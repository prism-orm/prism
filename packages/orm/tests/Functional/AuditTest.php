<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Orm\Audit\AuditEntry;
use Prism\Orm\Audit\AuditReader;
use Prism\Orm\Audit\RequestIdProvider;
use Prism\Orm\Console\Command\AuditPurgeCommand;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tests\Support\FixedActor;
use Prism\Orm\Tests\Support\FrozenClock;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Link;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Page;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #[Auditable] sur les 4 bases (RG-08.10 à RG-08.12, ADR 0015) : une ligne par entité modifiée,
 * dans la transaction du flush, avec son changeset, son tenant, son auteur, sa requête et sa date
 * UTC ; lue par l'AuditReader dans le tenant courant ; purgée seulement par db:audit:purge.
 */
final class AuditTest extends MappingSchemaTestCase
{
    private FrozenClock $clock;

    private FixedActor $actor;

    private StackTenantContext $context;

    private EntityManager $em;

    protected static function entities(): array
    {
        return [Account::class, Page::class, Link::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-26 08:00:00', new \DateTimeZone('UTC')));
        $this->actor = new FixedActor('ada');
        $this->context = new StackTenantContext(1);
        $requests = new class implements RequestIdProvider {
            public function getRequestId(): string
            {
                return 'req-1';
            }
        };
        // les entités découvertes : db:audit:purge parcourt les entités auditées
        $metadata = new MetadataFactory(new AttributeDriver([\dirname(__DIR__) . '/Unit/UnitOfWork/Fixtures/Behavior']));
        $this->em = new EntityManager($this->connection, $metadata, tenantContext: $this->context, clock: $this->clock, actorProvider: $this->actor, requestIdProvider: $requests);
    }

    /**
     * @return list<AuditEntry>
     */
    private function history(int $id): array
    {
        return new AuditReader($this->em)->history(Account::class, $id);
    }

    private function account(): Account
    {
        $page = new Page('home');
        $account = new Account('Ada');
        $account->iban = 'FR7630006000011234567890189';
        $account->note = 'vip';
        $account->page = $page;
        $this->em->persist($page);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    // RG-08.10, RG-08.11 : l'INSERT, sans le champ exclu, le champ sensible masqué
    public function testInsertIsAudited(): void
    {
        $account = $this->account();

        $entries = $this->history((int) $account->id);
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(['insert', (string) $account->id, '1', 'ada', 'req-1', false], [$entry->action, $entry->entityId, $entry->tenantId, $entry->actor, $entry->requestId, $entry->system]);
        self::assertSame('2026-10-26 08:00:00', $entry->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame([null, 'Ada'], $entry->changes['owner']);
        self::assertSame([null, '***'], $entry->changes['iban']);
        self::assertNull($entry->changes['page'][0]);
        self::assertEquals($account->page?->id, $entry->changes['page'][1], 'a relation by its foreign key');
        self::assertArrayNotHasKey('note', $entry->changes);
    }

    // insert, update, soft-delete, restore, delete : un changeset correct chacun
    public function testEveryActionHasItsChangeset(): void
    {
        $account = $this->account();
        $id = (int) $account->id;
        $this->clock->advance('+1 hour');
        $account->owner = 'Ada L.';
        $account->iban = 'FR7600000000000000000000000';
        $this->em->flush();
        $account->note = 'only an excluded field';
        $this->em->flush();
        $this->em->remove($account);
        $this->em->flush();
        $this->em->restore($account);
        $this->em->flush();
        $this->em->remove($account, hardDelete: true);
        $this->em->flush();

        $entries = $this->history($id);
        self::assertSame(['insert', 'update', 'soft-delete', 'restore', 'delete'], array_map(static fn (AuditEntry $e): string => $e->action, $entries));
        self::assertEqualsCanonicalizing(['owner' => ['Ada', 'Ada L.'], 'iban' => ['***', '***']], $entries[1]->changes);
        self::assertSame(['deletedAt'], array_keys($entries[2]->changes));
        self::assertNull($entries[2]->changes['deletedAt'][0]);
        self::assertNull($entries[3]->changes['deletedAt'][1]);
        self::assertSame(['Ada L.', null], $entries[4]->changes['owner']);
        self::assertSame(['***', null], $entries[4]->changes['iban']);
    }

    // RG-08.10 : dans la transaction du flush ; un flush annulé ne laisse aucune ligne
    public function testRolledBackFlushLeavesNoAuditRow(): void
    {
        $this->account();
        $this->em->persist(new Account('Bob'));
        // le slug est unique parmi les pages non supprimées : le second INSERT échoue
        $this->em->persist(new Page('home'));

        try {
            $this->em->flush();
            self::fail('no exception');
        } catch (\Throwable) {
        }
        // les changements refusés restent en attente : les abandonner avant de changer de tenant (RG-08.8)
        $this->em->clear();

        $count = $this->context->runAsSystem(fn (): mixed => $this->connection->executeQuery('SELECT COUNT(*) FROM prism_audit_log')->fetchOne());
        self::assertSame(1, is_numeric($count) ? (int) $count : null, 'only the insert of the first flush');
    }

    // l'état à une date, rejoué depuis les changesets
    public function testStateAtADate(): void
    {
        $account = $this->account();
        $this->clock->advance('+1 day');
        $account->owner = 'Ada L.';
        $this->em->flush();

        $reader = new AuditReader($this->em);
        self::assertNull($reader->stateAt(Account::class, $account->id, new \DateTimeImmutable('2026-10-25 00:00:00+00:00')));
        self::assertSame('Ada', $reader->stateAt(Account::class, $account->id, new \DateTimeImmutable('2026-10-26 12:00:00+00:00'))['owner'] ?? null);
        self::assertSame('Ada L.', $reader->stateAt(Account::class, $account->id, new \DateTimeImmutable('2026-10-28 00:00:00+00:00'))['owner'] ?? null);
    }

    // ADR 0014 : l'historique d'un tenant ne se lit pas depuis un autre ; le mode système est marqué (RG-08.5)
    public function testHistoryFollowsTheTenant(): void
    {
        $account = $this->account();
        $id = (int) $account->id;
        $this->context->runAsSystem(function () use ($id): void {
            $loaded = $this->em->find(Account::class, $id);
            self::assertNotNull($loaded);
            $loaded->owner = 'Ada (admin)';
            $this->em->flush();
        });

        self::assertSame([false, true], array_map(static fn (AuditEntry $e): bool => $e->system, $this->history($id)));
        self::assertSame([], $this->context->runAs(2, fn (): array => $this->history($id)));
        self::assertCount(2, $this->context->runAsSystem(fn (): array => $this->history($id)));

        $this->context->setTenantId(null);
        $this->expectException(MissingTenantException::class);
        $this->history($id);
    }

    // RG-08.12 : la purge, seule voie de suppression, et rien sans --force
    public function testPurge(): void
    {
        $account = $this->account();
        $this->clock->advance('+2 days');
        $account->owner = 'Ada L.';
        $this->em->flush();
        $tester = new CommandTester(new AuditPurgeCommand($this->connection, $this->em->getMetadataFactory(), $this->clock));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('This would delete 1 audit log row(s)', $tester->getDisplay());
        self::assertCount(2, $this->history((int) $account->id));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertSame(['update'], array_map(static fn (AuditEntry $e): string => $e->action, $this->history((int) $account->id)), 'the row older than one day is gone');
        self::assertSame(Command::INVALID, $tester->execute(['--older-than' => 'one year']));
    }
}
