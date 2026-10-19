<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\PersistentCollection;
use Prism\Orm\Event\PostLoadEvent;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence du batch fetching (pilier 1, RG-04.9 à RG-04.14), écrits avant le code.
 *
 * Règles fixées ici :
 * - une requête hydrate une cohorte ; une relation chargée pour un membre l'est pour tous les
 *   membres qui ne l'ont pas encore, en une requête IN par lot (100, ou batchSize) ;
 * - les entités d'un lot sont hydratées ensemble : elles forment à leur tour une cohorte ;
 * - une collection chargée par lot garde son ordre orderBy ; le côté inverse d'un OneToOne est
 *   chargé pour toute la cohorte ; Fetch::Eager charge la relation par lot dès l'hydratation ;
 * - batch: false revient à une requête par accès ; une page d'iterate() est une cohorte, libérée
 *   à son détachement.
 */
final class UnitOfWorkBatchFetchTest extends TestCase
{
    private InMemoryStorage $storage;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->uow = $this->unitOfWork();
    }

    private function unitOfWork(int $batchSize = 100, ?TestDispatcher $events = null): UnitOfWork
    {
        return new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), $events, batchSize: $batchSize);
    }

    private function selectsOn(string $table): int
    {
        return \count(array_filter($this->storage->operations('SELECT'), static fn (array $o): bool => $o[0] === $table));
    }

    private function seedCustomers(int $count, ?int $countries = null): void
    {
        for ($id = 1; $id <= $count; ++$id) {
            $this->storage->seed('customer', 'id', ['id' => $id, 'name' => 'Customer ' . $id, 'country_id' => null === $countries ? null : 1 + ($id - 1) % $countries]);
        }
        for ($id = 1; $id <= ($countries ?? 0); ++$id) {
            $this->storage->seed('country', 'id', ['id' => $id, 'name' => 'C' . $id]);
        }
    }

    /**
     * Une commande par client, ou $perCustomer.
     */
    private function seedOrders(int $customers, int $perCustomer = 1, ?int $sellers = null): void
    {
        $id = 0;
        for ($customer = 1; $customer <= $customers; ++$customer) {
            for ($n = 1; $n <= $perCustomer; ++$n) {
                ++$id;
                $this->storage->seed('order', 'id', ['id' => $id, 'reference' => \sprintf('R%03d-%d', $customer, $n), 'customer_id' => $customer, 'seller_id' => null === $sellers ? null : 1 + ($id - 1) % $sellers, 'depot_id' => null]);
            }
        }
        for ($seller = 1; $seller <= ($sellers ?? 0); ++$seller) {
            $this->storage->seed('seller', 'id', ['id' => $seller, 'name' => 'S' . $seller]);
        }
    }

    // lit une propriété : charge l'entité qui la porte
    private static function touch(mixed $value): void
    {
    }

    /**
     * @return list<Order>
     */
    private function orders(): array
    {
        return $this->uow->findBy(Order::class, []);
    }

    /**
     * @return list<Customer>
     */
    private function customers(): array
    {
        return $this->uow->findBy(Customer::class, []);
    }

    // ---------------------------------------------------------------- ToOne (RG-04.10, RG-04.11)

    // critère de sortie : 100 commandes et leur client → 2 requêtes au lieu de 101
    public function testHundredOrdersAndTheirCustomersInTwoQueries(): void
    {
        $this->seedCustomers(100);
        $this->seedOrders(100);

        foreach ($this->orders() as $order) {
            self::assertSame('Customer ' . $order->customer->id, $order->customer->name);
        }

        self::assertSame([1, 1], [$this->selectsOn('order'), $this->selectsOn('customer')]);
    }

    public function testBeyondTheBatchSizeSeveralInQueries(): void
    {
        $this->seedCustomers(250);
        $this->seedOrders(250);

        foreach ($this->orders() as $order) {
            self::touch($order->customer->name);
        }

        self::assertSame(3, $this->selectsOn('customer'), '100, 100, 50');
    }

    public function testGlobalBatchSize(): void
    {
        $this->uow = $this->unitOfWork(batchSize: 10);
        $this->seedCustomers(25);
        $this->seedOrders(25);

        foreach ($this->orders() as $order) {
            self::touch($order->customer->name);
        }

        self::assertSame(3, $this->selectsOn('customer'));
    }

    public function testBatchSizeOfTheRelationAndASecondLevel(): void
    {
        // 100 clients, 10 pays, lots de 2 sur Customer::$country
        $this->seedCustomers(100, countries: 10);
        $this->seedOrders(100);

        foreach ($this->orders() as $order) {
            self::touch($order->customer->country?->name);
        }

        self::assertSame([1, 5], [$this->selectsOn('customer'), $this->selectsOn('country')], 'the customers of a batch form a cohort');
    }

    // RG-04.13
    public function testBatchFalseLoadsOneByOne(): void
    {
        $this->seedCustomers(10);
        $this->seedOrders(10, sellers: 4);

        foreach ($this->orders() as $order) {
            self::touch($order->seller?->name);
        }

        self::assertSame(4, $this->selectsOn('seller'), 'one query per distinct seller');
    }

    public function testEntitiesOfTheBatchAreLoadedNotJustFetched(): void
    {
        $this->seedCustomers(3);
        $this->seedOrders(3);
        [$first, $second] = $this->orders();

        self::touch($first->customer->name);
        $this->storage->deleteRow('customer', 2);

        self::assertSame('Customer 2', $second->customer->name, 'hydrated with the batch');
        self::assertSame(1, $this->selectsOn('customer'));
    }

    public function testMissingRowOfABatchFailsOnlyForItsEntity(): void
    {
        $this->seedCustomers(3);
        $this->seedOrders(3);
        $this->storage->deleteRow('customer', 2);
        [$first, $second, $third] = $this->orders();

        self::assertSame('Customer 1', $first->customer->name);
        self::assertSame('Customer 3', $third->customer->name);
        $this->expectException(EntityNotFoundException::class);
        self::assertSame('', $second->customer->name);
    }

    public function testEntityAlreadyManagedIsNotPartOfTheCohort(): void
    {
        $this->seedCustomers(3);
        $this->seedOrders(3);
        $known = $this->uow->find(Order::class, 1);
        self::assertNotNull($known);

        $orders = $this->orders();
        self::touch($orders[1]->customer->name);

        self::assertSame(1, $this->selectsOn('customer'));
        self::assertSame(['Customer 2', 'Customer 3'], [$orders[1]->customer->name, $orders[2]->customer->name]);
        self::touch($known->customer->name);
        self::assertSame(2, $this->selectsOn('customer'), 'the order known before belongs to its own cohort');
    }

    // ---------------------------------------------------------------- collections (RG-04.12)

    public function testOneToManyLoadedForTheWholeCohortInOrder(): void
    {
        $this->seedCustomers(50);
        $this->seedOrders(50, perCustomer: 3);

        $customers = $this->customers();
        foreach ($customers as $customer) {
            self::assertCount(3, $customer->orders);
        }

        self::assertSame(1, $this->selectsOn('order'));
        self::assertSame(['R007-3', 'R007-2', 'R007-1'], array_map(static fn (Order $o): string => $o->reference, array_values($customers[6]->orders->toArray())), 'orderBy reference DESC');
    }

    public function testManyToManyLoadedForTheWholeCohort(): void
    {
        $this->seedCustomers(20);
        foreach ([1, 2, 3] as $group) {
            $this->storage->seed('group', 'id', ['id' => $group, 'name' => 'G' . $group]);
        }
        for ($customer = 1; $customer <= 20; ++$customer) {
            $this->storage->seedPivot('customer_group', ['customer_id' => $customer, 'group_id' => 1 + $customer % 3]);
        }

        $customers = $this->customers();
        foreach ($customers as $customer) {
            self::assertCount(1, $customer->groups);
        }

        self::assertSame(1, $this->selectsOn('group'));
        self::assertSame('G3', $customers[1]->groups->first()?->name);
    }

    public function testInverseOneToOneLoadedForTheWholeCohort(): void
    {
        $this->seedCustomers(30);
        for ($id = 1; $id <= 30; $id += 2) {
            $this->storage->seed('account', 'id', ['id' => $id, 'customer_id' => $id, 'balance' => $id * 10]);
        }

        $customers = $this->customers();

        self::assertSame(1, $this->selectsOn('account'), 'one query at hydration, not one per customer');
        self::assertSame(10, $customers[0]->account?->balance);
        self::assertNull($customers[1]->account);
        self::assertSame($customers[2], $customers[2]->account?->customer);
    }

    // ---------------------------------------------------------------- Fetch::Eager, iterate (RG-04.14)

    public function testEagerRelationIsLoadedByBatchAtHydration(): void
    {
        $this->seedCustomers(5);
        $this->seedOrders(5);
        foreach ([1, 2] as $depot) {
            $this->storage->seed('depot', 'id', ['id' => $depot, 'name' => 'D' . $depot]);
        }
        for ($id = 1; $id <= 5; ++$id) {
            $this->storage->setColumn('order', $id, 'depot_id', 1 + $id % 2);
        }

        $orders = $this->orders();

        self::assertSame(1, $this->selectsOn('depot'));
        self::assertSame('D2', $orders[0]->depot?->name);
        self::assertSame(1, $this->selectsOn('depot'), 'already loaded');
    }

    public function testEachPageOfIterateIsACohort(): void
    {
        $this->seedCustomers(30);
        $this->seedOrders(30);

        foreach ($this->uow->iterate(Order::class, batchSize: 10) as $order) {
            self::touch($order->customer->name);
        }

        self::assertSame(3, $this->selectsOn('customer'), 'one batch per page');
    }

    public function testDetachedPageReleasesItsCohort(): void
    {
        $this->seedCustomers(3);
        $this->seedOrders(3);
        $first = null;

        foreach ($this->uow->iterate(Order::class, batchSize: 10) as $order) {
            $first ??= \WeakReference::create($order);
        }
        unset($order);
        gc_collect_cycles();

        self::assertNotNull($first);
        self::assertNull($first->get(), 'no reference left to the detached page');
    }

    public function testSingleFindIsACohortOfOne(): void
    {
        $this->seedCustomers(2);
        $this->seedOrders(2);

        $this->uow->find(Order::class, 1)?->customer->name;

        self::assertSame(1, $this->selectsOn('customer'), 'nothing to batch with');
    }

    // ---------------------------------------------------------------- compléments (mutants)

    public function testEntitiesLoadedWithABatchAreTrackedAndPublishPostLoad(): void
    {
        $events = new TestDispatcher();
        $this->uow = $this->unitOfWork(events: $events);
        $this->seedCustomers(3);
        $this->seedOrders(3);
        $orders = $this->orders();

        self::touch($orders[0]->customer->name);
        $orders[2]->customer->name = 'Renamed';
        $this->uow->commit();

        self::assertCount(3, array_filter($events->ofType(PostLoadEvent::class), static fn (object $e): bool => $e instanceof PostLoadEvent && $e->entity instanceof Customer));
        self::assertSame([['customer', ['name']]], $this->storage->operations('UPDATE'));
    }

    public function testCohortOfABatchLoadsItsInverseSideOnce(): void
    {
        $this->seedCustomers(10);
        $this->seedOrders(10);
        for ($id = 1; $id <= 10; ++$id) {
            $this->storage->seed('account', 'id', ['id' => $id, 'customer_id' => $id, 'balance' => $id]);
        }

        foreach ($this->orders() as $order) {
            self::assertSame($order->customer->id, $order->customer->account?->balance);
        }

        self::assertSame(1, $this->selectsOn('account'));
    }

    public function testSingleReferenceLoadsItsInverseSide(): void
    {
        $this->seedCustomers(1);
        $this->storage->seed('account', 'id', ['id' => 1, 'customer_id' => 1, 'balance' => 7]);

        self::assertSame(7, $this->uow->getReference(Customer::class, 1)->account?->balance);
    }

    public function testEagerCollectionIsLoadedByBatchAtHydration(): void
    {
        $this->seedCustomers(4);
        $this->seedOrders(4, sellers: 2);

        $sellers = $this->uow->findBy(Seller::class, []);

        self::assertSame(1, $this->selectsOn('order'));
        self::assertInstanceOf(PersistentCollection::class, $sellers[1]->orders);
        self::assertTrue($sellers[1]->orders->isInitialized());
        self::assertSame([2, 4], array_map(static fn (Order $o): ?int => $o->id, array_values($sellers[1]->orders->toArray())));
    }

    public function testCollectionReplacedByTheApplicationIsLeftOutOfTheBatch(): void
    {
        $this->seedCustomers(3);
        $this->seedOrders(3);
        $customers = $this->customers();
        $customers[1]->orders = $replaced = new ArrayCollection();

        self::assertCount(1, $customers[0]->orders);

        self::assertSame($replaced, $customers[1]->orders);
        self::assertCount(0, $replaced);
        self::assertCount(1, $customers[2]->orders);
        self::assertSame(1, $this->selectsOn('order'));
    }

    public function testBatchesNeverLoadEntitiesOutsideTheCohort(): void
    {
        $this->seedCustomers(4);
        $this->seedOrders(4);
        for ($id = 1; $id <= 4; ++$id) {
            $this->storage->seed('account', 'id', ['id' => $id, 'customer_id' => $id, 'balance' => $id]);
        }

        $customers = $this->uow->findBy(Customer::class, ['id' => [1, 2]]);
        self::assertCount(1, $customers[0]->orders);
        [$orders, $accounts] = [$this->selectsOn('order'), $this->selectsOn('account')];

        self::assertNotNull($this->uow->find(Order::class, 4));
        self::assertNotNull($this->uow->find(Account::class, 4));
        self::assertSame([$orders + 1, $accounts + 1], [$this->selectsOn('order'), $this->selectsOn('account')], 'the order and the account of customer 4 were never loaded');
    }
}
