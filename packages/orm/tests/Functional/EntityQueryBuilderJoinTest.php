<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\Collection\PersistentCollection;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\NonUniqueResultException;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller;

/**
 * Jointures du QueryBuilder typé sur les 4 bases : filtre sur une relation, fetchJoin() des
 * quatre sortes de relation sans requête ensuite (RG-05.5), ordre des collections chargées
 * (RG-04.12), racines sans doublon, withoutBatchFetch() (RG-04.13).
 *
 * Données : Ada (France, compte, commandes A1 et A2, groupe VIP), Bob (Italie, commande B1,
 * groupes VIP et Pro), Cy (sans pays, sans compte, sans commande ni groupe).
 */
final class EntityQueryBuilderJoinTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private EntityManager $em;

    protected static function entities(): array
    {
        return [Order::class, Customer::class, Country::class, Account::class, Group::class, Seller::class, Depot::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        $this->connection->close();
        $this->connection = TestDatabase::connection(new Configuration($this->logger));
        if (':memory:' === $this->connection->getDatabase()) {
            $this->migrateTo($this->target());
        }
        $this->em = new EntityManager($this->connection);

        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $vip = new Group('VIP');
            $pro = new Group('Pro');
            $ada = new Customer('Ada');
            $ada->country = new Country('France');
            $bob = new Customer('Bob');
            $bob->country = new Country('Italy');
            $cy = new Customer('Cy');
            $ada->groups->add($vip);
            $bob->groups->add($vip);
            $bob->groups->add($pro);
            foreach ([$vip, $pro, $ada->country, $bob->country, $ada, $bob, $cy] as $entity) {
                $em->persist($entity);
            }
            $em->persist(new Account($ada, 100));
            // A1 avant A2 en base : la collection, triée par référence décroissante, les inverse
            $em->persist(new Order($ada, 'A1'));
            $em->persist(new Order($ada, 'A2'));
            $em->persist(new Order($bob, 'B1'));
        });
        $this->em->clear();
        $this->logger->records = [];
    }

    private function selects(): int
    {
        return \count(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    /**
     * @param array<object> $entities
     *
     * @return list<string>
     */
    private static function names(array $entities): array
    {
        return array_map(static fn (object $e): string => $e instanceof Order ? $e->reference : ($e instanceof Customer || $e instanceof Group || $e instanceof Country ? $e->name : '?'), array_values($entities));
    }

    public function testFilterAndOrderOnAJoinedRelation(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');
        $n = $c->country->as('n');

        $orders = $this->em->query($o)->join($c)->join($n)->where($n->name->eq('France'))->orderBy($o->reference->desc())->getResult();

        self::assertSame(['A2', 'A1'], self::names($orders));
        self::assertSame(1, $this->selects(), 'a plain join loads nothing more');
    }

    // RG-05.5 : la chaîne commande → client → pays, et le compte du client, en une requête
    public function testNestedToOneFetchJoinsNeedNoOtherQuery(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');

        $orders = $this->em->query($o)
            ->fetchJoin($c)->leftFetchJoin($n = $c->country->as('n'))->leftFetchJoin($c->account->as('a'))
            ->orderBy($o->reference->asc())
            ->getResult();

        self::assertSame(['A1', 'A2', 'B1'], self::names($orders));
        self::assertSame($orders[0]->customer, $orders[1]->customer, 'RG-03.3');
        self::assertSame(['France', 'France', 'Italy'], array_map(static fn (Order $order): string => $order->customer->country->name ?? '-', $orders));
        self::assertSame([100, 100, null], array_map(static fn (Order $order): ?int => $order->customer->account?->balance, $orders));
        self::assertSame(1, $this->selects());
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledUpdates(), 'nothing looks modified');
        self::assertSame('Italy', $this->em->query($o)->fetchJoin($c)->fetchJoin($n)->where($n->name->eq('Italy'))->getOneOrNull()?->customer->country?->name);
    }

    // RG-05.5, RG-04.12 : collection chargée dans son ordre, vide sans ligne jointe
    public function testCollectionFetchJoinKeepsItsOrder(): void
    {
        $c = Customer_::as('c');

        $customers = $this->em->query($c)->leftFetchJoin($c->orders->as('o'))->leftFetchJoin($c->account->as('a'))->orderBy($c->name->asc())->getResult();

        self::assertSame(['Ada', 'Bob', 'Cy'], self::names($customers), 'each root once, in the order of its first row');
        foreach ($customers as $customer) {
            self::assertTrue($customer->orders instanceof PersistentCollection && $customer->orders->isInitialized(), $customer->name);
        }
        self::assertSame(['A2', 'A1'], self::names($customers[0]->orders->toArray()), 'ordered by reference DESC');
        self::assertSame(['B1'], self::names($customers[1]->orders->toArray()));
        self::assertSame([], $customers[2]->orders->toArray());
        self::assertSame($customers[0], $customers[0]->orders->first()?->customer);
        self::assertSame(1, $this->selects());

        $this->em->flush();
        self::assertSame(1, $this->selects(), 'a fetched collection has its snapshot: nothing to write');
    }

    public function testManyToManyFetchJoinFromBothSides(): void
    {
        $c = Customer_::as('c');
        $customers = $this->em->query($c)->fetchJoin($c->groups->as('g'))->leftFetchJoin($c->account->as('a'))->orderBy($c->name->asc())->getResult();

        self::assertSame(['Ada', 'Bob'], self::names($customers), 'an inner join drops Cy, who has no group');
        self::assertEqualsCanonicalizing(['VIP', 'Pro'], self::names($customers[1]->groups->toArray()));
        self::assertContains($customers[0]->groups->first(), $customers[1]->groups->toArray(), 'RG-03.3: the same VIP instance');
        self::assertSame(1, $this->selects());

        $this->em->clear();
        $g = Group_::as('g');
        $c = $g->customers->as('c');
        $groups = $this->em->query($g)->fetchJoin($c)->leftFetchJoin($c->account->as('a'))->where($g->name->eq('VIP'))->getResult();
        self::assertEqualsCanonicalizing(['Ada', 'Bob'], self::names($groups[0]->customers->toArray()));
    }

    public function testPlainJoinOnACollectionReturnsEachRootOnce(): void
    {
        $c = Customer_::as('c');
        $o = $c->orders->as('o');

        $customers = $this->em->query($c)->join($o)->where($o->reference->like('%'))->orderBy($c->name->asc())->getResult();

        self::assertSame(['Ada', 'Bob'], self::names($customers));
        self::assertFalse($customers[0]->orders instanceof PersistentCollection && $customers[0]->orders->isInitialized(), 'a plain join does not load the collection');
    }

    // une entité déjà connue garde sa collection : la jointure ne la remplace pas
    public function testKnownEntityKeepsItsLoadedCollection(): void
    {
        $ada = $this->em->getRepository(Customer::class)->findOneBy(['name' => 'Ada']);
        self::assertNotNull($ada);
        self::assertCount(2, $ada->orders->toArray());
        $c = Customer_::as('c');
        $o = $c->orders->as('o');

        $customers = $this->em->query($c)->fetchJoin($o)->where($o->reference->eq('A1'))->getResult();

        self::assertSame([$ada], $customers);
        self::assertCount(2, $ada->orders, 'loaded before: not cut by the join condition');
    }

    public function testOneOrNullCountsRootsNotRows(): void
    {
        $c = Customer_::as('c');
        $query = $this->em->query($c)->fetchJoin($c->orders->as('o'));

        self::assertSame('Ada', $query->where($c->name->eq('Ada'))->getOneOrNull()?->name, 'two rows, one customer');

        $this->expectException(NonUniqueResultException::class);
        $query->getOneOrNull();
    }

    // RG-04.13 : sans batch fetching, chaque client se charge seul
    public function testWithoutBatchFetch(): void
    {
        $o = Order_::as('o');
        $count = function (bool $batch) use ($o): int {
            $this->em->clear();
            $this->logger->records = [];
            $query = $this->em->query($o);
            $names = array_map(static fn (Order $order): string => $order->customer->name, ($batch ? $query : $query->withoutBatchFetch())->getResult());
            self::assertCount(3, $names);

            return $this->selects();
        };

        // les commandes, les 2 clients en un lot, leurs comptes en un lot
        self::assertSame(3, $count(true));
        // les commandes, puis chaque client et son compte
        self::assertSame(5, $count(false));
    }
}
