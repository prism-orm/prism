<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Exception\TenantMismatchException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Client;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Contract;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Rate;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Le tenant des lignes écrites par l'UnitOfWork (RG-08.3, RG-08.4, ADR 0014) : posé depuis le
 * TenantContext à l'INSERT, jamais changé ensuite, même en mode système.
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

        foreach ([fn () => $this->uow->commit(), fn () => $this->context->runAsSystem(fn () => $this->uow->commit())] as $flush) {
            try {
                $flush();
                self::fail('no exception');
            } catch (TenantMismatchException $e) {
                self::assertSame('The tenant of the ' . Contract::class . ' entity cannot change ($tenantId from 7 to 8); move the row with SQL in runAsSystem() if needed.', $e->getMessage());
            }
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
}
