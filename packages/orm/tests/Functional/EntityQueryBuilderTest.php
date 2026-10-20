<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\NonUniqueResultException;
use Prism\Orm\Query\Condition;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes_;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\Priority;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller;

/**
 * QueryBuilder typé sur base réelle (pilier 2), sur les 4 bases : chaque opérateur sur les vrais
 * types de colonne, identity map et batch fetching sur le résultat (RG-05.3, RG-05.4, RG-05.6).
 */
final class EntityQueryBuilderTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private EntityManager $em;

    protected static function entities(): array
    {
        return [AllTypes::class, Order::class, Customer::class, Country::class, Account::class, Group::class, Seller::class, Depot::class];
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
            foreach ([
                [1, 'alpha', 'urgent call', '10.00', 1.5, true, '2026-10-01 08:00:00', Priority::Low],
                [2, 'beta', 'nothing', '99.99', 2.5, false, '2026-10-10 08:00:00', Priority::High],
                [3, 'gamma', 'urgent mail', '100.00', 3.5, true, '2026-10-20 08:00:00', Priority::High],
            ] as [$id, $string, $text, $decimal, $float, $bool, $datetime, $priority]) {
                $row = new AllTypes();
                $row->id = $id;
                $row->string = $string;
                $row->text = $text;
                $row->uuid = \sprintf('0192f0a0-0000-7000-8000-00000000000%d', $id);
                $row->smallint = $id;
                $row->bigint = $id * 1_000_000_000;
                $row->decimal = $decimal;
                $row->float = $float;
                $row->bool = $bool;
                $row->datetime = new \DateTimeImmutable($datetime);
                $row->json = ['n' => $id];
                $row->binary = str_repeat(\chr($id), 16);
                $row->priority = $priority;
                $em->persist($row);
            }
        });
        $this->em->clear();
    }

    /**
     * @return list<int>
     */
    private function ids(AllTypes_ $t, Condition $condition): array
    {
        return array_map(static fn (AllTypes $r): int => $r->id, $this->em->query($t)->where($condition)->orderBy($t->id->asc())->getResult());
    }

    public function testEveryOperatorOnRealColumns(): void
    {
        $t = AllTypes_::as('t');

        self::assertSame([2], $this->ids($t, $t->string->eq('beta')));
        self::assertSame([1, 3], $this->ids($t, $t->string->neq('beta')));
        self::assertSame([1, 2], $this->ids($t, $t->decimal->lt('100.00')), 'decimal compared as a number');
        self::assertSame([3], $this->ids($t, $t->decimal->gte('100.00')));
        self::assertSame([2, 3], $this->ids($t, $t->float->gt(2.0)));
        self::assertSame([1, 2], $this->ids($t, $t->bigint->lte(2_000_000_000)));
        self::assertSame([2, 3], $this->ids($t, $t->datetime->gt(new \DateTimeImmutable('2026-10-05 00:00:00'))));
        self::assertSame([2], $this->ids($t, $t->datetime->between(new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable('2026-10-15'))));
        self::assertSame([1, 3], $this->ids($t, $t->bool->eq(true)));
        self::assertSame([2, 3], $this->ids($t, $t->priority->eq(Priority::High)));
        self::assertSame([1], $this->ids($t, $t->priority->notIn([Priority::High])));
        self::assertSame([1, 3], $this->ids($t, $t->text->like('urgent%')));
        self::assertSame([1, 3], $this->ids($t, $t->smallint->in([1, 3, 9])));
        self::assertSame([], $this->ids($t, $t->smallint->in([])));
        self::assertSame([2], $this->ids($t, $t->binary->eq(str_repeat(\chr(2), 16))));
        self::assertSame([3], $this->ids($t, $t->uuid->eq('0192f0a0-0000-7000-8000-000000000003')));
        self::assertSame([1, 2, 3], $this->ids($t, $t->json->isNotNull()));
    }

    public function testCombinationsOrderAndPage(): void
    {
        $t = AllTypes_::as('t');
        $query = $this->em->query($t)
            ->where(Condition::any($t->bool->eq(false), $t->text->like('%mail')))
            ->andWhere($t->priority->eq(Priority::High)->not()->not())
            ->orderBy($t->decimal->desc());

        self::assertSame([3, 2], array_map(static fn (AllTypes $r): int => $r->id, $query->getResult()));
        self::assertSame([2], array_map(static fn (AllTypes $r): int => $r->id, $query->limit(1)->offset(1)->getResult()));
    }

    // RG-05.6
    public function testOneOrNull(): void
    {
        $t = AllTypes_::as('t');

        self::assertSame('gamma', $this->em->query($t)->where($t->id->eq(3))->getOneOrNull()?->string);
        self::assertNull($this->em->query($t)->where($t->id->eq(9))->getOneOrNull());

        $this->expectException(NonUniqueResultException::class);
        $this->em->query($t)->where($t->bool->eq(true))->getOneOrNull();
    }

    public function testResultGoesThroughTheIdentityMapAndBatchFetching(): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            for ($i = 1; $i <= 10; ++$i) {
                $em->persist($customer = new Customer('C' . $i));
                $em->persist(new Order($customer, 'R' . $i));
            }
        });
        $this->em->clear();
        $known = $this->em->getRepository(Order::class)->findOneBy(['reference' => 'R1']);
        $this->logger->records = [];

        $o = Order_::as('o');
        $orders = $this->em->query($o)->where($o->reference->like('R%'))->orderBy($o->id->asc())->getResult();
        $names = array_map(static fn (Order $order): string => $order->customer->name, $orders);

        self::assertCount(10, $names);
        self::assertSame($known, $orders[0], 'RG-03.3');
        $selects = array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT'));
        // les commandes ; les 9 nouveaux clients en un lot, et leurs comptes (côté inverse) ; puis le
        // client de la commande connue avant, qui appartient à sa propre cohorte, et son compte
        self::assertCount(5, $selects);
    }
}
