<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\CrossTenantRelationException;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Exception\TenantMismatchException;
use Prism\Orm\Exception\TenantSwitchException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Client;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Contract;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Rate;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Tag;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Le tenant des lignes écrites par l'UnitOfWork (RG-08.3, RG-08.4, ADR 0014) : posé depuis le
 * TenantContext à l'INSERT, jamais changé ensuite, même en mode système. Une relation entre
 * deux tenants est refusée au flush (RG-08.6) ; le stockage en mémoire ne filtre pas : une ligne
 * d'un autre tenant s'y lit comme en mode système.
 */
final class UnitOfWorkTenancyTest extends TestCase
{
    private InMemoryStorage $storage;

    private StackTenantContext $context;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->context = new StackTenantContext(7);
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), tenants: $this->context);
    }

    private function insert(object $entity): void
    {
        $this->uow->persist($entity);
        $this->uow->commit();
    }

    // RG-08.3 : colonne que ne porte aucune propriété
    public function testInsertWritesTheCurrentTenant(): void
    {
        $this->insert($client = new Client('Ada'));

        self::assertSame(7, $this->storage->row('tenant_client', (int) $client->id)['tenant_id']);
    }

    // ADR 0014 : une propriété du tenant est remplie
    public function testTenantPropertyIsFilled(): void
    {
        $this->insert($contract = new Contract('C-1'));

        self::assertSame(7, $contract->tenantId);
        self::assertSame(7, $this->storage->row('tenant_contract', (int) $contract->id)['tenant_id']);
    }

    public function testTenantPropertyOfTheCurrentTenantIsAccepted(): void
    {
        $contract = new Contract('C-1');
        $contract->tenantId = 7;
        $this->insert($contract);

        self::assertSame(7, $this->storage->row('tenant_contract', (int) $contract->id)['tenant_id']);
    }

    // RG-08.3 : rien n'est écrit
    public function testTenantPropertyOfAnotherTenantIsRefused(): void
    {
        $contract = new Contract('C-1');
        $contract->tenantId = 8;

        try {
            $this->insert($contract);
            self::fail('no exception');
        } catch (TenantMismatchException $e) {
            self::assertSame('The ' . Contract::class . ' entity belongs to tenant 8 ($tenantId), but the current tenant is 7.', $e->getMessage());
            self::assertSame($contract, $e->entity);
        }
        self::assertSame([], $this->storage->tables['tenant_contract'] ?? []);
    }

    // RG-08.4
    public function testInsertWithoutTenantIsRefused(): void
    {
        $this->context->setTenantId(null);

        $this->expectException(MissingTenantException::class);
        $this->expectExceptionMessage('No current tenant for the #[Tenanted] entity ' . Client::class);

        $this->insert(new Client('Ada'));
    }

    public function testEntityWithoutTenantNeedsNoTenant(): void
    {
        $this->context->setTenantId(null);
        $this->insert($currency = new Currency('EUR'));

        self::assertArrayNotHasKey('tenant_id', $this->storage->row('tenant_currency', (int) $currency->id));
    }

    // RG-08.5 : le mode système ne donne pas de tenant implicite
    public function testSystemModeNeedsAnExplicitTenant(): void
    {
        $this->expectException(MissingTenantException::class);
        $this->expectExceptionMessage('Cannot write the #[Tenanted] entity ' . Client::class . ' in system mode without a tenant: wrap the write in runAs(), or set its tenant property.');

        $this->context->runAsSystem(fn () => $this->insert(new Client('Ada')));
    }

    public function testSystemModeWritesTheTenantOfTheProperty(): void
    {
        $contract = new Contract('C-1');
        $contract->tenantId = 8;
        $this->context->runAsSystem(fn () => $this->insert($contract));

        self::assertSame(8, $this->storage->row('tenant_contract', (int) $contract->id)['tenant_id']);
    }

    // ADR 0014 : une ligne partagée n'est écrite qu'en mode système
    public function testSystemModeWritesSharedRows(): void
    {
        $shared = new Rate('VAT', 20);
        $this->context->runAsSystem(fn () => $this->insert($shared));
        $this->insert($own = new Rate('R-7', 5));

        self::assertNull($this->storage->row('tenant_rate', (int) $shared->id)['tenant_id']);
        self::assertSame(7, $this->storage->row('tenant_rate', (int) $own->id)['tenant_id']);
    }

    // ADR 0014 : le tenant d'une ligne ne change jamais par l'ORM, même en mode système
    public function testTenantIsImmutable(): void
    {
        $this->insert($contract = new Contract('C-1'));
        $contract->tenantId = 8;
        $message = 'The tenant of the ' . Contract::class . ' entity cannot change ($tenantId from 7 to 8); move the row with SQL in runAsSystem() if needed.';

        try {
            $this->uow->commit();
            self::fail('no exception');
        } catch (TenantMismatchException $e) {
            self::assertSame($message, $e->getMessage());
        }

        $this->uow->clear();
        try {
            $this->context->runAsSystem(function () use ($contract): void {
                $loaded = $this->uow->find(Contract::class, $contract->id);
                self::assertNotNull($loaded);
                $loaded->tenantId = 8;
                $this->uow->commit();
            });
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(TenantMismatchException::class, $e);
            self::assertSame($message, $e->getMessage(), 'even in system mode');
        }
        self::assertSame(7, $this->storage->row('tenant_contract', (int) $contract->id)['tenant_id']);
    }

    // RG-03.6 : limite assumée du rollback, l'identifiant est bien rétabli
    public function testRollbackCannotUninitializeTheTenantProperty(): void
    {
        $this->storage->failOnInsert(2);
        $contract = new Contract('C-1');
        $this->uow->persist($contract);
        $this->uow->persist(new Client('Ada'));

        try {
            $this->uow->commit();
            self::fail('no exception');
        } catch (\RuntimeException) {
        }

        // une propriété int non initialisée ne peut pas le redevenir : elle garde le tenant posé
        self::assertSame(7, $contract->tenantId);
        self::assertNull($contract->id);
    }

    // ----- RG-08.6 : relations entre tenants -----

    private function seedClient(int $id, ?int $tenant): void
    {
        $this->storage->seed('tenant_client', 'id', ['id' => $id, 'name' => 'Client ' . $id, 'tenant_id' => $tenant]);
    }

    public function testRelationToTheSameTenantIsAccepted(): void
    {
        $this->seedClient(1, 7);
        $this->insert($invoice = new Invoice('F-1', 10, $this->uow->find(Client::class, 1)));

        self::assertSame(1, $this->storage->row('tenant_invoice', (int) $invoice->id)['client_id']);
    }

    // une entité à insérer, vers une entité chargée d'un autre tenant : rien n'est écrit
    public function testNewEntityTowardsAnotherTenantIsRefused(): void
    {
        $this->seedClient(1, 8);
        $client = $this->uow->find(Client::class, 1);
        $invoice = new Invoice('F-1', 10, $client);

        try {
            $this->insert($invoice);
            self::fail('no exception');
        } catch (CrossTenantRelationException $e) {
            self::assertSame(Invoice::class . '::$client (tenant 7) refers to a ' . Client::class . ' entity of tenant 8; relations between tenants are refused.', $e->getMessage());
            self::assertSame([$invoice, 'client', $client], [$e->entity, $e->field, $e->target]);
        }
        self::assertSame([], $this->storage->tables['tenant_invoice'] ?? []);
    }

    // une clé étrangère modifiée vers un autre tenant
    public function testChangedRelationTowardsAnotherTenantIsRefused(): void
    {
        $this->seedClient(1, 7);
        $this->seedClient(2, 8);
        $this->insert($invoice = new Invoice('F-1', 10, $this->uow->find(Client::class, 1)));
        $invoice->client = $this->uow->find(Client::class, 2);

        $this->expectException(CrossTenantRelationException::class);
        $this->expectExceptionMessage('(tenant 7) refers to a ' . Client::class . ' entity of tenant 8');

        $this->uow->commit();
    }

    // ManyToMany : un élément ajouté d'un autre tenant
    public function testManyToManyElementOfAnotherTenantIsRefused(): void
    {
        $this->storage->seed('tenant_tag', 'id', ['id' => 1, 'name' => 'x', 'tenant_id' => 8]);
        $this->insert($invoice = new Invoice('F-1'));
        $tag = $this->uow->find(Tag::class, 1);
        self::assertNotNull($tag);
        $invoice->tags->add($tag);

        $this->expectException(CrossTenantRelationException::class);
        $this->expectExceptionMessage(Invoice::class . '::$tags (tenant 7) refers to a ' . Tag::class . ' entity of tenant 8');

        $this->uow->commit();
    }

    // une référence jamais chargée est chargée pour connaître son tenant
    public function testReferenceIsLoadedToCheckItsTenant(): void
    {
        $this->seedClient(1, 8);

        $this->expectException(CrossTenantRelationException::class);

        $this->insert(new Invoice('F-1', 10, $this->uow->getReference(Client::class, 1)));
    }

    // le mode système ne permet pas plus de relier deux tenants
    public function testSystemModeIsRefusedToo(): void
    {
        $this->seedClient(1, 7);
        $this->seedClient(2, 8);
        $this->storage->seed('tenant_invoice', 'id', ['id' => 1, 'number' => 'F-1', 'amount' => 10, 'client_id' => 1, 'currency_id' => null, 'tenant_id' => 7]);

        $this->expectException(CrossTenantRelationException::class);
        $this->expectExceptionMessage('(tenant 7) refers to a ' . Client::class . ' entity of tenant 8');

        $this->context->runAsSystem(function (): void {
            $invoice = $this->uow->find(Invoice::class, 1);
            self::assertNotNull($invoice);
            $invoice->client = $this->uow->find(Client::class, 2);
            $this->uow->commit();
        });
    }

    // une ligne partagée est une cible permise ; la ligne propre d'un autre tenant non
    public function testSharedRowIsAValidTarget(): void
    {
        $this->storage->seed('tenant_rate', 'id', ['id' => 1, 'code' => 'VAT', 'percent' => 20, 'tenant_id' => null]);
        $this->storage->seed('tenant_rate', 'id', ['id' => 2, 'code' => 'R-8', 'percent' => 5, 'tenant_id' => 8]);
        $contract = new Contract('C-1');
        $contract->rate = $this->uow->find(Rate::class, 1);
        $this->insert($contract);
        self::assertSame(1, $this->storage->row('tenant_contract', (int) $contract->id)['rate_id']);

        $contract->rate = $this->uow->find(Rate::class, 2);
        $this->expectException(CrossTenantRelationException::class);
        $this->expectExceptionMessage(Contract::class . '::$rate (tenant 7) refers to a ' . Rate::class . ' entity of tenant 8');

        $this->uow->commit();
    }

    // une entité non tenantée est partagée : toujours une cible permise
    public function testTargetWithoutTenantIsAccepted(): void
    {
        $this->storage->seed('tenant_currency', 'id', ['id' => 1, 'code' => 'EUR']);
        $this->insert($invoice = new Invoice('F-1', currency: $this->uow->find(Currency::class, 1)));

        self::assertSame(1, $this->storage->row('tenant_invoice', (int) $invoice->id)['currency_id']);
    }

    // ----- RG-08.8 : changement de tenant -----

    // sans changement en attente, l'identity map est vidée à chaque changement effectif
    public function testSwitchClearsTheIdentityMap(): void
    {
        $this->insert($client = new Client('Ada'));

        $inside = $this->context->runAs(8, fn (): bool => $this->uow->contains($client));

        self::assertFalse($inside);
        self::assertFalse($this->uow->contains($client), 'cleared again on the way back');
        self::assertNotSame($client, $this->uow->find(Client::class, $client->id));
    }

    public function testSameTenantKeepsTheIdentityMap(): void
    {
        $this->insert($client = new Client('Ada'));

        self::assertTrue($this->context->runAs(7, fn (): bool => $this->uow->contains($client)));
    }

    // à l'entrée : refusé, rien n'est perdu
    public function testSwitchWithPendingChangesIsRefused(): void
    {
        $this->insert($client = new Client('Ada'));
        $client->name = 'Ada L.';

        foreach ([fn () => $this->context->runAs(8, static fn (): null => null), fn () => $this->context->runAsSystem(static fn (): null => null), fn () => $this->context->setTenantId(8)] as $switch) {
            try {
                $switch();
                self::fail('no exception');
            } catch (TenantSwitchException $e) {
                self::assertStringStartsWith('Cannot switch from tenant 7 to ', $e->getMessage());
            }
        }

        self::assertSame(7, $this->context->getTenantId());
        $this->uow->commit();
        self::assertSame('Ada L.', $this->storage->row('tenant_client', (int) $client->id)['name']);
    }

    // à la sortie : abandonnés, jamais écrits sous le tenant retrouvé
    public function testPendingChangesAreDiscardedOnLeave(): void
    {
        try {
            $this->context->runAs(8, fn () => $this->uow->persist(new Client('Bob')));
            self::fail('no exception');
        } catch (TenantSwitchException $e) {
            self::assertSame('Unflushed changes made in tenant 8 were discarded when switching back to tenant 7: flush() before leaving runAs() or runAsSystem().', $e->getMessage());
        }

        self::assertSame([], $this->uow->getScheduledInsertions());
        $this->uow->commit();
        self::assertSame([], $this->storage->tables['tenant_client'] ?? []);
    }

    public function testEmptyUnitOfWorkIgnoresSwitches(): void
    {
        $this->context->runAs(8, static fn (): null => null);
        $this->context->setTenantId(9);

        self::assertFalse($this->uow->hasPendingChanges());
    }
}
