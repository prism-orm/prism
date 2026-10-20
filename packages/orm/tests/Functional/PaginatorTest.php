<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\Collection\PersistentCollection;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller;

/**
 * Pagination sur les 4 bases (RG-05.9, critère de sortie de la phase 5) : pages complètes et
 * sans doublon, avec ou sans collection chargée par jointure, égalités départagées par
 * l'identifiant, total compté une fois.
 *
 * Données : 25 clients ; les 10 premiers s'appellent tous « Abc » (égalités), les autres
 * « C11 » à « C25 ». Le client n a n mod 4 commandes « Rn-1 » à « Rn-k ».
 */
final class PaginatorTest extends MappingSchemaTestCase
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
            for ($n = 1; $n <= 25; ++$n) {
                $em->persist($customer = new Customer($n <= 10 ? 'Abc' : \sprintf('C%02d', $n)));
                for ($k = 1; $k <= $n % 4; ++$k) {
                    $em->persist(new Order($customer, \sprintf('R%02d-%d', $n, $k)));
                }
            }
        });
        $this->em->clear();
        $this->logger->records = [];
    }

    private function selects(): int
    {
        return \count(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    /**
     * @param iterable<Customer> $customers
     *
     * @return list<int>
     */
    private static function ids(iterable $customers): array
    {
        $ids = [];
        foreach ($customers as $customer) {
            $ids[] = (int) $customer->id;
        }

        return $ids;
    }

    // RG-05.9 : « Abc » × 10, départagés par l'identifiant
    public function testPagesWithoutCollectionAreStableOnTies(): void
    {
        $c = Customer_::as('c');
        $query = $this->em->query($c)->leftFetchJoin($c->account->as('a'))->orderBy($c->name->asc());

        $first = $query->paginate(1, 4);
        $second = $query->paginate(2, 4);
        $third = $query->paginate(3, 4);

        self::assertSame([1, 2, 3, 4], self::ids($first));
        self::assertSame([5, 6, 7, 8], self::ids($second));
        self::assertSame([9, 10, 11, 12], self::ids($third));
        self::assertSame(3, $this->selects(), 'one query a page, the inverse OneToOne fetched');

        self::assertSame([25, 7, true, false], [$first->total(), $first->pageCount(), $first->hasNext(), $first->hasPrevious()]);
        self::assertSame(4, $this->selects(), 'one COUNT');
    }

    public function testLastPageAndBeyond(): void
    {
        $c = Customer_::as('c');
        $query = $this->em->query($c)->leftFetchJoin($c->account->as('a'))->orderBy($c->name->desc());

        $last = $query->paginate(3, 10);
        // C25 … C11, puis les « Abc » par identifiant : la page 3 est 6 … 10
        self::assertSame([[6, 7, 8, 9, 10], 25, 3, false, true], [self::ids($last), $last->total(), $last->pageCount(), $last->hasNext(), $last->hasPrevious()]);
        self::assertSame(1, $this->selects(), 'an incomplete page knows the total');

        $beyond = $query->paginate(4, 10);
        self::assertSame([[], 25], [$beyond->items, $beyond->total()]);
    }

    // critère de sortie : pagination avec fetch join de collection
    public function testCollectionFetchJoinGivesWholeRootsAndWholeCollections(): void
    {
        $c = Customer_::as('c');
        $query = $this->em->query($c)->leftFetchJoin($c->orders->as('o'))->leftFetchJoin($c->account->as('a'))->orderBy($c->name->desc());

        $seen = [];
        for ($page = 1; $page <= 3; ++$page) {
            $customers = $query->paginate($page, 10);
            foreach ($customers as $customer) {
                $seen[] = (int) $customer->id;
                self::assertTrue($customer->orders instanceof PersistentCollection && $customer->orders->isInitialized());
                $n = (int) $customer->id;
                $expected = 0 === $n % 4 ? [] : array_map(static fn (int $k): string => \sprintf('R%02d-%d', $n, $k), range($n % 4, 1, -1));
                self::assertSame($expected, array_map(static fn (Order $o): string => $o->reference, $customer->orders->toArray()), 'whole collection, ordered by reference DESC');
            }
            self::assertCount(1 === $page || 2 === $page ? 10 : 5, $customers, 'a page counts roots, not rows');
        }

        // C25 … C11, puis les « Abc » par identifiant
        self::assertSame([...range(25, 11), ...range(1, 10)], $seen);
        self::assertSame(6, $this->selects(), 'the identifiers of the page, then its entities');
        self::assertSame(25, $customers->total());
    }

    public function testFilterOnTheCollectionSelectsRootsAndFiltersTheirElements(): void
    {
        $c = Customer_::as('c');
        $o = $c->orders->as('o');

        $customers = $this->em->query($c)->fetchJoin($o)->leftFetchJoin($c->account->as('a'))
            ->where($o->reference->like('%-3'))->orderBy($c->name->asc())
            ->paginate(1, 2);

        self::assertSame([3, 7], self::ids($customers), 'customers with a third order');
        self::assertSame(['R03-3'], array_map(static fn (Order $order): string => $order->reference, $customers->items[0]->orders->toArray()), 'filtered as fetchJoin() does');
        self::assertSame(6, $customers->total(), 'COUNT(DISTINCT) despite the join: 3, 7, 11, 15, 19, 23');
    }

    public function testToOneFetchJoinUsesASingleQuery(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');

        $orders = $this->em->query($o)->fetchJoin($c)->leftFetchJoin($c->account->as('a'))->orderBy($c->name->desc(), $o->reference->asc())->paginate(1, 3);

        // C25 a 1 commande, C24 aucune, C23 en a 3
        self::assertSame(['R25-1', 'R23-1', 'R23-2'], array_map(static fn (Order $order): string => $order->reference, $orders->items));
        self::assertSame(['C25', 'C23'], [$orders->items[0]->customer->name, $orders->items[1]->customer->name]);
        self::assertSame(1, $this->selects());
    }
}
