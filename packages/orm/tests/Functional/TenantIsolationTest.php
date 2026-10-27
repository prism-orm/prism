<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\CrossTenantRelationException;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Exception\TenantMismatchException;
use Prism\Orm\Exception\TenantSwitchException;
use Prism\Orm\Exception\UnfilteredTenantQueryException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantGuard;
use Prism\Orm\Tenancy\TenantGuardMode;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\AmountByClient;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Client;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Contract;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice_;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Line;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Rate;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Tag;

/**
 * Isolation entre deux tenants sur les 4 bases : les 36 scénarios de référence de la phase 8
 * (critère de sortie, minimum 30), écrits avant le code (étape 2). Aucune ligne du tenant B
 * n'est lue, comptée ni modifiée depuis le tenant A (RG-08.2 à RG-08.8, ADR 0014).
 *
 * Données, pour A et pour B : un client, les factures F-1 (10, deux lignes « a » et « b », un
 * tag), F-2 (20) et F-{A,B}-only (5), un taux propre R-{A,B}. Partagées : la devise EUR (non
 * tenantée) et le taux VAT (tenant NULL). Anomalies écrites en mode système : la facture F-9 de
 * A vise le client de B, une ligne de B est rattachée à F-1 de A, le tag de B est dans le pivot
 * de F-1 de A.
 */
final class TenantIsolationTest extends MappingSchemaTestCase
{
    private const int A = 1;

    private const int B = 2;

    private TestLogger $logger;

    private StackTenantContext $context;

    private MetadataFactory $metadata;

    private EntityManager $em;

    /** @var array<string, int> identifiants par nom (« A.f1 », « B.client »…) */
    private array $ids = [];

    protected static function entities(): array
    {
        return [Client::class, Invoice::class, Line::class, Tag::class, Rate::class, Currency::class, Contract::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        $this->context = new StackTenantContext();
        // la garde connaît les tables tenantées par les entités découvertes
        $this->metadata = new MetadataFactory(new AttributeDriver([\dirname(__DIR__) . '/Unit/Tenancy/Fixtures']));
        $this->connection->close();
        $this->connection = $this->guardedConnection(TenantGuardMode::Exception, $this->logger);
        $this->em = new EntityManager($this->connection, $this->metadata, tenantContext: $this->context);
        $this->seed();
        $this->context->setTenantId(self::A);
        $this->em->clear();
        $this->logger->records = [];
    }

    private function guardedConnection(TenantGuardMode $mode, TestLogger $logger): Connection
    {
        $connection = TestDatabase::connection(new Configuration($logger)->withGuards([new TenantGuard($this->metadata, $this->context, $mode, $logger)]));
        if (':memory:' === $connection->getDatabase()) {
            // SQLite en mémoire : une base neuve par connexion
            $previous = $this->connection;
            $this->connection = $connection;
            $this->context->runAsSystem(fn () => $this->migrateTo($this->target()));
            $this->connection = $previous;
        }

        return $connection;
    }

    private function seed(): void
    {
        $this->context->runAsSystem(function (): void {
            $eur = new Currency('EUR');
            $vat = new Rate('VAT', 20);
            $this->em->persist($eur);
            $this->em->persist($vat);
            $this->em->flush();
            $this->ids['eur'] = (int) $eur->id;
            $this->ids['vat'] = (int) $vat->id;
        });

        foreach (['A' => self::A, 'B' => self::B] as $name => $tenant) {
            $this->context->runAs($tenant, function () use ($name): void {
                $client = new Client('Client ' . $name);
                $f1 = new Invoice('F-1', 10, $client, $this->em->getReference(Currency::class, $this->ids['eur']));
                $f2 = new Invoice('F-2', 20, $client);
                $only = new Invoice('F-' . $name . '-only', 5, $client);
                $f9 = new Invoice('F-9', 0);
                $tag = new Tag('x-' . $name);
                $f1->tags->add($tag);
                $lines = [new Line($f1, 'a'), new Line($f1, 'b')];
                foreach ([$client, $f1, $f2, $only, $f9, $tag, new Rate('R-' . $name, 5), ...$lines] as $entity) {
                    $this->em->persist($entity);
                }
                $this->em->flush();
                $this->ids += [$name . '.client' => (int) $client->id, $name . '.f1' => (int) $f1->id, $name . '.f2' => (int) $f2->id, $name . '.only' => (int) $only->id, $name . '.f9' => (int) $f9->id, $name . '.tag' => (int) $tag->id, $name . '.line' => (int) $lines[0]->id];
            });
        }

        $this->context->runAsSystem(function (): void {
            // F-9 de A vise le client de B
            $this->update('tenant_invoice', $this->ids['A.f9'], 'client_id', $this->ids['B.client']);
            // la ligne « a » de B rattachée à F-1 de A
            $this->update('tenant_line', $this->ids['B.line'], 'invoice_id', $this->ids['A.f1']);
            // le tag de B dans le pivot de F-1 de A
            $this->connection->createQueryBuilder()->insert('tenant_invoice_tag')->set('invoice_id', $this->ids['A.f1'])->set('tag_id', $this->ids['B.tag'])->executeStatement();
        });
    }

    private function update(string $table, int $id, string $column, mixed $value): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->update($table)->set($column, $value)->where($qb->expr()->eq($qb->column('id'), $id))->executeStatement();
    }

    /**
     * Passe une ligne de A chez B sans quitter le tenant A, qui viderait l'identity map (RG-08.8) :
     * la condition sur tenant_id satisfait la garde du SQL brut (RG-08.7).
     */
    private function moveToTenantB(string $table, int $id): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->update($table)->set('tenant_id', self::B)->where($qb->expr()->eq($qb->column('id'), $id))->andWhere($qb->expr()->eq($qb->column('tenant_id'), self::A))->executeStatement();
    }

    /**
     * Une colonne entière d'une ligne, lue en mode système.
     */
    private function raw(string $table, int $id, string $column): int
    {
        $value = $this->context->runAsSystem(function () use ($table, $id, $column): mixed {
            $qb = $this->connection->createQueryBuilder();

            return $qb->select($qb->column($column))->from($table)->where($qb->expr()->eq($qb->column('id'), $id))->executeQuery()->fetchOne();
        });

        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException(\sprintf('%s.%s of row %d is not an integer.', $table, $column, $id));
    }

    /**
     * @param list<Invoice> $invoices
     *
     * @return list<int>
     */
    private static function idsOf(array $invoices): array
    {
        $ids = array_map(static fn (Invoice $i): int => (int) $i->id, $invoices);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function idsNamed(string ...$names): array
    {
        $ids = array_map(fn (string $name): int => $this->ids[$name], array_values($names));
        sort($ids);

        return $ids;
    }

    private function lastSelect(): string
    {
        $selects = array_values(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));

        return $selects[\count($selects) - 1] ?? '';
    }

    private function selects(): int
    {
        return \count(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    // ----- lecture par le persister (RG-08.2) -----

    // scénario 1
    public function testFindIgnoresAnotherTenantsEntity(): void
    {
        self::assertNull($this->em->find(Invoice::class, $this->ids['B.f1']));
        self::assertNotNull($this->em->find(Invoice::class, $this->ids['A.f1']));
    }

    // scénario 2 : RG-04.8, sans révéler que la ligne existe ailleurs
    public function testReferenceToAnotherTenantsEntityIsNotFound(): void
    {
        $ghost = $this->em->getReference(Invoice::class, $this->ids['B.f1']);

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('No %s entity found for id %d.', Invoice::class, $this->ids['B.f1']));

        self::assertSame('F-1', $ghost->number);
    }

    // scénario 3
    public function testFindByReturnsOnlyTheCurrentTenant(): void
    {
        self::assertSame($this->idsNamed('A.f1', 'A.f2', 'A.only', 'A.f9'), self::idsOf($this->em->getRepository(Invoice::class)->findBy([])));
    }

    // scénario 4 : F-1 existe chez A et chez B
    public function testFindOneByAValueSharedByBothTenants(): void
    {
        self::assertSame($this->ids['A.f1'], $this->em->getRepository(Invoice::class)->findOneBy(['number' => 'F-1'])?->id);
    }

    // scénario 5
    public function testFindByIdentifiersOfBothTenants(): void
    {
        self::assertSame([$this->ids['A.f1']], self::idsOf($this->em->getRepository(Invoice::class)->findBy(['id' => [$this->ids['A.f1'], $this->ids['B.f1']]])));
    }

    // scénario 6
    public function testCountOnlyCountsTheCurrentTenant(): void
    {
        self::assertSame(4, $this->em->getRepository(Invoice::class)->count());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count(['number' => 'F-1']));
    }

    // scénario 7 : chaque lot est filtré
    public function testIterateOnlyYieldsTheCurrentTenant(): void
    {
        $ids = [];
        foreach ($this->em->getRepository(Invoice::class)->iterate(batchSize: 1) as $invoice) {
            $ids[] = (int) $invoice->id;
        }
        sort($ids);

        self::assertSame($this->idsNamed('A.f1', 'A.f2', 'A.only', 'A.f9'), $ids);
    }

    // scénario 8 : la ligne est passée chez B entre la lecture et le refresh (F-A-only : sans homonyme chez B)
    public function testRefreshOfARowMovedToAnotherTenant(): void
    {
        $only = $this->em->find(Invoice::class, $this->ids['A.only']);
        self::assertNotNull($only);
        $this->moveToTenantB('tenant_invoice', $this->ids['A.only']);

        $this->expectException(EntityNotFoundException::class);

        $this->em->refresh($only);
    }

    // ----- relations et chargement paresseux -----

    // scénario 9 : la clé étrangère de F-9 vise le client de B
    public function testLazyToOneOnAnotherTenantsEntityIsNotFound(): void
    {
        $f9 = $this->em->find(Invoice::class, $this->ids['A.f9']);
        self::assertNotNull($f9);
        self::assertNotNull($f9->client, 'a ghost: its identifier is read without loading (RG-04.7)');

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('No %s entity found for id %d.', Client::class, $this->ids['B.client']));

        self::assertSame('Client B', $f9->client->name);
    }

    // scénario 10 : RG-04.10, le lot porte la condition du tenant
    public function testBatchFetchedToOneIsFiltered(): void
    {
        $invoices = $this->em->getRepository(Invoice::class)->findBy(['id' => [$this->ids['A.f1'], $this->ids['A.f2']]]);
        foreach ($invoices as $invoice) {
            self::assertSame('Client A', $invoice->client?->name);
        }

        self::assertSame(2, $this->selects(), 'the invoices, then their clients in one query');
        self::assertStringContainsString('tenant_id', $this->lastSelect());
    }

    // scénario 11
    public function testOneToManyIgnoresAnotherTenantsRows(): void
    {
        $f1 = $this->em->find(Invoice::class, $this->ids['A.f1']);

        self::assertSame(['a', 'b'], array_map(static fn (Line $l): string => $l->label, $f1?->lines->toArray() ?? []));
    }

    // scénario 12 : le pivot ne porte pas de tenant, sa cible est filtrée
    public function testManyToManyIgnoresAnotherTenantsTarget(): void
    {
        $f1 = $this->em->find(Invoice::class, $this->ids['A.f1']);

        self::assertSame(['x-A'], array_map(static fn (Tag $t): string => $t->name, $f1?->tags->toArray() ?? []));
    }

    // scénario 13 : EXTRA_LAZY, COUNT(*) joint à la cible
    public function testExtraLazyCountIgnoresAnotherTenantsTarget(): void
    {
        $f1 = $this->em->find(Invoice::class, $this->ids['A.f1']);

        self::assertNotNull($f1);
        self::assertCount(1, $f1->tags);
        self::assertStringContainsString('tenant_id', $this->lastSelect());
    }

    // scénario 14 : RG-04.12, les collections d'une cohorte en une requête filtrée
    public function testBatchFetchedCollectionsAreFiltered(): void
    {
        $invoices = $this->em->getRepository(Invoice::class)->findBy(['id' => [$this->ids['A.f1'], $this->ids['A.f2']]], ['number' => Order::Asc]);

        self::assertSame([['a', 'b'], []], array_map(static fn (Invoice $i): array => array_map(static fn (Line $l): string => $l->label, $i->lines->toArray()), $invoices));
        self::assertSame(2, $this->selects());
        self::assertStringContainsString('tenant_id', $this->lastSelect());
    }

    // ----- QueryBuilder typé -----

    // scénario 15
    public function testQueryOnlyReturnsTheCurrentTenant(): void
    {
        self::assertSame($this->idsNamed('A.f1', 'A.f2', 'A.only', 'A.f9'), self::idsOf($this->em->query(Invoice_::as('i'))->getResult()));
    }

    // scénario 16
    public function testQueryConditionTrueForBothTenants(): void
    {
        $i = Invoice_::as('i');

        self::assertSame([$this->ids['A.f1']], self::idsOf($this->em->query($i)->where($i->number->eq('F-1'))->getResult()));
    }

    // scénario 17 : la jointure interne écarte F-9, dont le client est chez B
    public function testInnerJoinIsFilteredInItsOn(): void
    {
        $i = Invoice_::as('i');

        self::assertSame($this->idsNamed('A.f1', 'A.f2', 'A.only'), self::idsOf($this->em->query($i)->join($i->client->as('c'))->getResult()));
    }

    // scénario 18 : la jointure externe garde F-9, sans le client de B
    public function testLeftJoinKeepsTheRootWithoutAnotherTenantsTarget(): void
    {
        $i = Invoice_::as('i');
        $c = $i->client->as('c');

        self::assertSame([$this->ids['A.f9']], self::idsOf($this->em->query($i)->leftJoin($c)->where($c->id->isNull())->getResult()));
    }

    // scénario 19 : RG-05.5, la collection chargée par jointure, sans requête ensuite
    public function testFetchJoinedCollectionIsFiltered(): void
    {
        $i = Invoice_::as('i');
        $f1 = $this->em->query($i)->leftFetchJoin($i->lines->as('l'))->where($i->number->eq('F-1'))->getOneOrNull();

        self::assertSame(['a', 'b'], array_map(static fn (Line $l): string => $l->label, $f1?->lines->toArray() ?? []));
        self::assertSame(1, $this->selects());
    }

    // scénario 20 : RG-05.9, page et total
    public function testPaginatorCountsOnlyTheCurrentTenant(): void
    {
        $i = Invoice_::as('i');
        $page = $this->em->query($i)->orderBy($i->number->asc())->paginate(1, 2);

        self::assertSame(4, $page->total());
        self::assertSame(['F-1', 'F-2'], array_map(static fn (Invoice $invoice): string => $invoice->number, $page->items));
    }

    // scénario 21 : agrégat groupé
    public function testProjectionAggregatesOnlyTheCurrentTenant(): void
    {
        $i = Invoice_::as('i');
        $c = $i->client->as('c');

        self::assertEquals([new AmountByClient('Client A', 35)], $this->em->query($i)->join($c)->groupBy($c->name)->selectInto(AmountByClient::class, client: $c->name, total: $i->amount->sum())->getResult());
    }

    // scénario 22
    public function testOneOrNullOnAnotherTenantsRow(): void
    {
        $i = Invoice_::as('i');

        self::assertNull($this->em->query($i)->where($i->number->eq('F-B-only'))->getOneOrNull());
    }

    // ----- écritures (RG-08.3, RG-08.6) -----

    // scénario 23
    public function testInsertTakesTheCurrentTenant(): void
    {
        $invoice = new Invoice('F-new', 1);
        $this->em->persist($invoice);
        $this->em->flush();

        self::assertSame(self::A, $this->raw('tenant_invoice', (int) $invoice->id, 'tenant_id'));
    }

    // scénario 24 : RG-08.3
    public function testTenantPropertyOfAnotherTenantIsRefused(): void
    {
        $contract = new Contract('C-1');
        $contract->tenantId = self::B;

        $this->expectException(TenantMismatchException::class);
        $this->expectExceptionMessage(\sprintf('The %s entity belongs to tenant 2 ($tenantId), but the current tenant is 1.', Contract::class));

        $this->em->persist($contract);
        $this->em->flush();
    }

    // scénario 24 bis : la propriété du tenant est remplie et relue
    public function testTenantPropertyIsFilled(): void
    {
        $contract = new Contract('C-2');
        $this->em->persist($contract);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(self::A, $this->em->find(Contract::class, $contract->id)?->tenantId);
    }

    // scénario 25 : la ligne passée chez B n'est ni modifiée ni supprimée (sans #[Version] : sans exception) ;
    // F-A-only : sans homonyme chez B, l'unicité par tenant (RG-08.1) laisse la déplacer
    public function testWritesNeverTouchAnotherTenantsRow(): void
    {
        $only = $this->em->find(Invoice::class, $this->ids['A.only']);
        self::assertNotNull($only);
        $this->moveToTenantB('tenant_invoice', $this->ids['A.only']);

        $only->amount = 999;
        $this->em->flush();
        $this->em->remove($only);
        $this->em->flush();

        // lu en mode système, qui vide l'identity map (RG-08.8) : après les deux écritures
        self::assertSame(5, $this->raw('tenant_invoice', $this->ids['A.only'], 'amount'), 'not updated');
        self::assertSame(self::B, $this->raw('tenant_invoice', $this->ids['A.only'], 'tenant_id'), 'not deleted');
    }

    // scénario 26 : RG-08.6, refusé même en mode système ; rien n'est écrit
    public function testCrossTenantRelationIsRefusedAtFlush(): void
    {
        $this->context->runAsSystem(function (): void {
            $invoice = $this->em->find(Invoice::class, $this->ids['A.f2']);
            $client = $this->em->find(Client::class, $this->ids['B.client']);
            self::assertNotNull($invoice);
            $invoice->client = $client;

            try {
                $this->em->flush();
                self::fail('no exception');
            } catch (\RuntimeException $e) {
                self::assertInstanceOf(CrossTenantRelationException::class, $e);
                self::assertSame(\sprintf('%s::$client (tenant 1) refers to a %s entity of tenant 2; relations between tenants are refused.', Invoice::class, Client::class), $e->getMessage());
            }
            // RG-08.8 : la modification refusée est abandonnée avant de quitter le mode système
            $this->em->clear();
        });

        self::assertSame($this->ids['A.client'], $this->raw('tenant_invoice', $this->ids['A.f2'], 'client_id'));
    }

    // ----- contexte (RG-08.4, RG-08.5, RG-08.8) -----

    /**
     * @return iterable<string, array{\Closure(EntityManager): mixed}>
     */
    public static function operationsWithoutTenant(): iterable
    {
        yield 'find' => [static fn (EntityManager $em): mixed => $em->find(Invoice::class, 1)];
        yield 'findBy' => [static fn (EntityManager $em): mixed => $em->getRepository(Invoice::class)->findBy([])];
        yield 'count' => [static fn (EntityManager $em): mixed => $em->getRepository(Invoice::class)->count()];
        yield 'query' => [static fn (EntityManager $em): mixed => $em->query(Invoice_::as('i'))->getResult()];
        yield 'reference' => [static fn (EntityManager $em): mixed => $em->getReference(Invoice::class, 1)->number];
        yield 'persist' => [static function (EntityManager $em): void {
            $em->persist(new Invoice('F-x'));
            $em->flush();
        }];
    }

    // scénario 27 : fail-closed
    #[DataProvider('operationsWithoutTenant')]
    public function testEveryOperationFailsWithoutTenant(\Closure $operation): void
    {
        $this->context->setTenantId(null);

        $this->expectException(MissingTenantException::class);
        $this->expectExceptionMessage('No current tenant for the #[Tenanted] entity ' . Invoice::class);

        $operation($this->em);
    }

    // scénario 28 : runAs() voit B puis revient à A, l'identity map vidée à chaque changement
    public function testRunAsSwitchesTenantAndClearsTheIdentityMap(): void
    {
        $f1 = $this->em->find(Invoice::class, $this->ids['A.f1']);
        self::assertNotNull($f1);

        $seen = $this->context->runAs(self::B, fn (): array => [$this->em->find(Invoice::class, $this->ids['B.f1'])?->number, $this->em->contains($f1)]);

        self::assertSame(['F-1', false], $seen, 'B is visible, A was cleared');
        self::assertNull($this->em->find(Invoice::class, $this->ids['B.f1']), 'back to A');
        self::assertNotSame($f1, $this->em->find(Invoice::class, $this->ids['A.f1']), 'cleared on the way back');
    }

    // scénario 29 : écart à RG-08.8 (ADR 0014), rien n'est perdu
    public function testSwitchingTenantWithPendingChangesIsRefused(): void
    {
        $f1 = $this->em->find(Invoice::class, $this->ids['A.f1']);
        self::assertNotNull($f1);
        $f1->amount = 11;

        try {
            $this->context->runAs(self::B, static fn (): null => null);
            self::fail('no exception');
        } catch (TenantSwitchException $e) {
            self::assertSame('Cannot switch from tenant 1 to tenant 2: the EntityManager has unflushed changes; flush() or clear() it first.', $e->getMessage());
        }

        $this->em->flush();
        self::assertSame(11, $this->raw('tenant_invoice', $this->ids['A.f1'], 'amount'));
    }

    // scénario 30 : RG-08.5
    public function testSystemModeReadsEveryTenantButNeedsOneToWrite(): void
    {
        self::assertSame(8, $this->context->runAsSystem(fn (): int => $this->em->getRepository(Invoice::class)->count()));

        $this->expectException(MissingTenantException::class);
        $this->expectExceptionMessage(\sprintf('Cannot write the #[Tenanted] entity %s in system mode without a tenant: wrap the write in runAs(), or set its tenant property.', Invoice::class));

        $this->context->runAsSystem(function (): void {
            $this->em->persist(new Invoice('F-sys'));
            $this->em->flush();
        });
    }

    // ----- lignes partagées -----

    // scénario 31 : ADR 0014, lues par chaque tenant, écrites en mode système seulement
    public function testGlobalRowsAreSharedButReadOnlyForATenant(): void
    {
        $codes = array_map(static fn (Rate $r): string => $r->code, $this->em->getRepository(Rate::class)->findBy([], ['code' => Order::Asc]));
        self::assertSame(['R-A', 'VAT'], $codes);

        $vat = $this->em->find(Rate::class, $this->ids['vat']);
        self::assertNotNull($vat);
        $vat->percent = 0;
        $this->em->flush();

        self::assertSame(20, $this->raw('tenant_rate', $this->ids['vat'], 'percent'));
    }

    // scénario 32
    public function testEntityWithoutTenantIsNeverFiltered(): void
    {
        self::assertSame('EUR', $this->em->find(Currency::class, $this->ids['eur'])?->code);
        self::assertStringNotContainsString('tenant_id', $this->lastSelect());
        $this->em->clear();
        self::assertSame('EUR', $this->context->runAs(self::B, fn (): ?string => $this->em->find(Currency::class, $this->ids['eur'])?->code));
    }

    // ----- SQL brut : TenantGuard (RG-08.7) -----

    // scénario 33
    public function testRawQueryWithoutTenantConditionIsRefused(): void
    {
        $this->expectException(UnfilteredTenantQueryException::class);
        $this->expectExceptionMessage('The query reads or writes the tenanted table "tenant_invoice" without a condition on "tenant_id"');

        $this->connection->executeQuery('SELECT id FROM tenant_invoice');
    }

    // scénario 34 : condition qualifiée par l'alias ; une table jointe en a besoin aussi
    public function testRawQueryWithAliasesAndJoins(): void
    {
        self::assertCount(4, $this->connection->executeQuery('SELECT i.id FROM tenant_invoice i WHERE i.tenant_id = ?', [self::A])->fetchFirstColumn());
        self::assertSame(1, $this->connection->executeStatement('UPDATE tenant_invoice SET amount = 12 WHERE id = ? AND tenant_id = ?', [$this->ids['A.f1'], self::A]));
        self::assertSame(['EUR'], $this->connection->executeQuery('SELECT code FROM tenant_currency')->fetchFirstColumn(), 'a table without tenant is free');

        $this->expectException(UnfilteredTenantQueryException::class);
        $this->expectExceptionMessage('The query reads or writes the tenanted table "tenant_client" without a condition on "tenant_id"');

        $this->connection->executeQuery('SELECT i.id FROM tenant_invoice i JOIN tenant_client c ON c.id = i.client_id WHERE i.tenant_id = ?', [self::A]);
    }

    // scénario 35 : le QueryBuilder du DBAL ne contourne pas la garde (RG-05.10) ; en mode log, la requête passe
    public function testDbalQueryBuilderIsGuardedAndLogModeOnlyLogs(): void
    {
        $qb = $this->connection->createQueryBuilder();
        try {
            $qb->select($qb->column('id'))->from('tenant_invoice')->executeQuery();
            self::fail('no exception');
        } catch (UnfilteredTenantQueryException) {
        }

        $logger = new TestLogger();
        $connection = $this->guardedConnection(TenantGuardMode::Log, $logger);
        $count = $connection->executeQuery('SELECT COUNT(*) FROM tenant_invoice')->fetchOne();
        $connection->close();

        // exécutée ; sous SQLite en mémoire, la seconde connexion est une base neuve, vide
        self::assertTrue(is_numeric($count), 'executed');
        self::assertNotSame([], array_filter($logger->records, static fn (array $r): bool => 'critical' === $r['level'] && str_contains($r['message'], 'tenant_invoice')));
    }

    // scénario 36 : ce que la garde ne sait pas analyser est refusé
    public function testSubqueriesAndCommonTableExpressionsAreRefused(): void
    {
        foreach ([
            'SELECT code FROM tenant_currency WHERE id IN (SELECT currency_id FROM tenant_invoice)',
            'WITH t AS (SELECT id FROM tenant_invoice) SELECT id FROM t',
        ] as $sql) {
            try {
                $this->connection->executeQuery($sql);
                self::fail('no exception: ' . $sql);
            } catch (UnfilteredTenantQueryException $e) {
                self::assertStringContainsString('tenant_invoice', $e->getMessage());
            }
        }

        // en mode système, la garde laisse passer
        self::assertNotFalse($this->context->runAsSystem(fn (): mixed => $this->connection->executeQuery('SELECT id FROM tenant_invoice')->fetchOne()));
    }
}
