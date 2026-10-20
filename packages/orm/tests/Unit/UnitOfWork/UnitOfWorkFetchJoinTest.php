<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\PersistentCollection;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Ce que l'UoW offre au fetchJoin() du QueryBuilder typé (RG-05.5) : une relation chargée par
 * la jointure n'est pas rechargée en fin de cohorte, ses entités remplissent les relations sans
 * requête, et une entité déjà connue garde son état.
 */
final class UnitOfWorkFetchJoinTest extends TestCase
{
    private InMemoryStorage $storage;

    private UnitOfWork $uow;

    private MetadataFactory $metadata;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->metadata = new MetadataFactory(new AttributeDriver());
        $this->uow = new UnitOfWork($this->metadata, $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults());
    }

    private function selectsOn(string $table): int
    {
        return \count(array_filter($this->storage->operations('SELECT'), static fn (array $o): bool => $o[0] === $table));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ClassMetadata<T>
     */
    private function meta(string $class): ClassMetadata
    {
        return $this->metadata->getMetadataFor($class);
    }

    /**
     * @param list<string> $fetched
     *
     * @return list<Customer>
     */
    private function hydrateCustomers(int $count, array $fetched = []): array
    {
        $rows = [];
        for ($id = 1; $id <= $count; ++$id) {
            $rows[] = ['id' => $id, 'name' => 'C' . $id, 'country_id' => null];
        }

        return $this->uow->hydrate($this->meta(Customer::class), $rows, fetched: $fetched);
    }

    public function testFetchedInverseOneToOneIsNotQueried(): void
    {
        $this->storage->seed('account', 'id', ['id' => 1, 'customer_id' => 1, 'balance' => 5]);

        [$first, $second] = $this->hydrateCustomers(2, ['account']);
        $account = $this->uow->hydrate($this->meta(Account::class), [['id' => 1, 'customer_id' => 1, 'balance' => 5]])[0];
        $this->uow->initializeInverseToOne($first, 'account', $account);
        $this->uow->initializeInverseToOne($second, 'account', null);

        self::assertSame(0, $this->selectsOn('account'));
        self::assertSame($account, $first->account);
        self::assertSame($first, $account->customer);
        self::assertNull($second->account);

        // une seule fois : un second appel n'écrase rien
        $this->uow->initializeInverseToOne($first, 'account', null);
        self::assertSame($account, $first->account);
    }

    public function testInverseOneToOneIsStillLoadedWhenNotFetched(): void
    {
        $this->storage->seed('account', 'id', ['id' => 1, 'customer_id' => 1, 'balance' => 5]);

        [$customer] = $this->hydrateCustomers(1, ['orders']);

        self::assertSame(1, $this->selectsOn('account'));
        self::assertSame(5, $customer->account?->balance);
        $this->uow->initializeInverseToOne($customer, 'account', null);
        self::assertNotNull($customer->account, 'not awaited by a join: untouched');
    }

    public function testKnownEntityKeepsItsInverseOneToOne(): void
    {
        $this->storage->seed('account', 'id', ['id' => 1, 'customer_id' => 1, 'balance' => 5]);
        [$customer] = $this->hydrateCustomers(1);
        self::assertSame(1, $this->selectsOn('account'));

        self::assertSame([$customer], $this->hydrateCustomers(1, ['account']));
        $this->uow->initializeInverseToOne($customer, 'account', null);

        self::assertSame(5, $customer->account?->balance);
    }

    // RG-04.13 : sans cohorte, chaque référence se charge seule ; la fin d'hydratation porte sur les nouvelles entités
    public function testWithoutBatchEachReferenceLoadsAlone(): void
    {
        foreach ([1, 2] as $id) {
            $this->storage->seed('customer', 'id', ['id' => $id, 'name' => 'C' . $id, 'country_id' => null]);
        }
        $rows = array_map(static fn (int $id): array => ['id' => $id, 'reference' => 'R' . $id, 'customer_id' => $id, 'seller_id' => null, 'depot_id' => null], [1, 2]);

        $orders = $this->uow->hydrate($this->meta(Order::class), $rows, batch: false);

        self::assertSame(['C1', 'C2'], array_map(static fn (Order $order): string => $order->customer->name, $orders));
        self::assertSame(2, $this->selectsOn('customer'));
        self::assertSame(2, $this->selectsOn('account'), 'the account of each customer, alone');
    }

    public function testFetchedEagerRelationIsNotLoaded(): void
    {
        $this->storage->seed('depot', 'id', ['id' => 1, 'name' => 'D1']);
        $row = ['id' => 1, 'reference' => 'R1', 'customer_id' => 1, 'seller_id' => null, 'depot_id' => 1];

        $order = $this->uow->hydrate($this->meta(Order::class), [$row], fetched: ['depot'])[0];

        self::assertSame(0, $this->selectsOn('depot'), 'left to the join');
        self::assertInstanceOf(Depot::class, $order->depot);
        self::assertTrue(new \ReflectionClass(Depot::class)->isUninitializedLazyObject($order->depot));
    }

    // une référence connue est chargée par la ligne que la requête vient de lire
    public function testKnownGhostIsLoadedFromTheRowRead(): void
    {
        $ghost = $this->uow->getReference(Customer::class, 1);

        self::assertSame([$ghost], $this->hydrateCustomers(1, ['account']));
        self::assertFalse(new \ReflectionClass(Customer::class)->isUninitializedLazyObject($ghost));
        self::assertSame('C1', $ghost->name);
        self::assertSame(0, $this->selectsOn('customer'));
    }

    public function testFetchedCollectionIsFilledWithoutQuery(): void
    {
        [$customer] = $this->hydrateCustomers(1, ['orders', 'account']);
        $orders = $this->uow->hydrate($this->meta(Order::class), [
            ['id' => 2, 'reference' => 'B', 'customer_id' => 1, 'seller_id' => null, 'depot_id' => null],
            ['id' => 1, 'reference' => 'A', 'customer_id' => 1, 'seller_id' => null, 'depot_id' => null],
        ]);

        $this->uow->initializeCollection($customer, 'orders', $orders);

        self::assertInstanceOf(PersistentCollection::class, $customer->orders);
        self::assertTrue($customer->orders->isInitialized());
        self::assertSame($orders, $customer->orders->toArray(), 'in the order of the rows');
        self::assertSame(0, $this->selectsOn('order'));

        $this->uow->commit();
        self::assertSame([], $this->storage->operations('UPDATE'), 'its snapshot is the fetched elements');

        $this->uow->initializeCollection($customer, 'orders', []);
        self::assertCount(2, $customer->orders, 'a loaded collection is not replaced');
    }

    public function testReplacedCollectionIsLeftAlone(): void
    {
        [$customer] = $this->hydrateCustomers(1, ['orders', 'account']);
        $customer->orders = $replaced = new ArrayCollection();

        $this->uow->initializeCollection($customer, 'orders', [new Order($customer, 'X')]);

        self::assertSame($replaced, $customer->orders);
        self::assertTrue($replaced->isEmpty());
    }

    public function testDetachedOwnerIsFilledButNotTracked(): void
    {
        [$customer] = $this->hydrateCustomers(1, ['orders', 'account']);
        $collection = $customer->orders;
        $this->uow->detach($customer);

        $this->uow->initializeCollection($customer, 'orders', []);

        self::assertInstanceOf(PersistentCollection::class, $collection);
        self::assertTrue($collection->isInitialized());
        self::assertFalse($this->uow->contains($customer));
    }
}
