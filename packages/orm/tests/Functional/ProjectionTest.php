<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\NonUniqueResultException;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Tests\Unit\Query\Fixtures\CustomerTotal;
use Prism\Orm\Tests\Unit\Query\Fixtures\Tally;
use Prism\Orm\Tests\Unit\Query\Fixtures\TypeSummary;
use Prism\Orm\Tests\Unit\Query\Fixtures\WrongType;
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
 * Agrégats, groupement et projections sur les 4 bases (ADR 0010, RG-05.6, RG-05.7) : valeurs
 * converties par le Type de chaque agrégat, groupe vide à null, rien dans l'identity map.
 *
 * Différence documentée : l'échelle de AVG() dépend de la base (MySQL : 4 décimales de plus,
 * PostgreSQL : jusqu'à 16 et plus, SQLite : un flottant) ; elle est comparée en valeur.
 */
final class ProjectionTest extends MappingSchemaTestCase
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
                [1, 'alpha', '10.00', 1.5, true, '2026-10-01 08:00:00', Priority::Low],
                [2, 'beta', '99.99', 2.5, false, '2026-10-10 08:00:00', Priority::High],
                [3, 'gamma', '100.00', 3.5, true, '2026-10-20 08:00:00', Priority::High],
                [4, 'delta', '0.01', 4.5, true, '2026-10-15 08:00:00', Priority::High],
            ] as [$id, $string, $decimal, $float, $bool, $datetime, $priority]) {
                $row = new AllTypes();
                $row->id = $id;
                $row->string = $string;
                $row->text = $string;
                $row->uuid = \sprintf('0192f0a0-0000-7000-8000-00000000000%d', $id);
                $row->json = ['n' => $id];
                $row->binary = str_repeat(\chr($id), 16);
                $row->smallint = $id;
                $row->bigint = $id * 1_000_000_000;
                $row->decimal = $decimal;
                $row->float = $float;
                $row->bool = $bool;
                $row->datetime = new \DateTimeImmutable($datetime);
                $row->priority = $priority;
                $em->persist($row);
            }
            $ada = new Customer('Ada');
            $bob = new Customer('Bob');
            $em->persist($ada);
            $em->persist($bob);
            $em->persist(new Customer('Cy'));
            $em->persist(new Order($ada, 'A1'));
            $em->persist(new Order($ada, 'A2'));
            $em->persist(new Order($bob, 'B1'));
        });
        $this->em->clear();
    }

    public function testEachAggregateWithItsType(): void
    {
        $t = AllTypes_::as('t');

        $summaries = $this->em->query($t)
            ->where($t->bool->eq(true))
            ->groupBy($t->priority)
            ->orderBy($t->priority->asc())
            ->selectInto(
                TypeSummary::class,
                priority: $t->priority,
                count: $t->id->count(),
                total: $t->decimal->sum(),
                average: $t->smallint->avg(),
                maxFloat: $t->float->max(),
                minBigint: $t->bigint->min(),
                first: $t->datetime->min(),
                last: $t->datetime->max(),
                firstName: $t->string->min(),
            )
            ->getResult();

        self::assertCount(2, $summaries);
        [$low, $high] = $summaries;
        self::assertSame([Priority::Low, 1, Priority::High, 2], [$low->priority, $low->count, $high->priority, $high->count]);
        self::assertEqualsWithDelta(100.01, (float) $high->total, 0.0001, 'decimal sum, as a numeric string');
        self::assertIsNumeric($high->total);
        self::assertEqualsWithDelta(3.5, (float) $high->average, 0.0001, 'AVG(3, 4): its scale depends on the platform');
        self::assertSame([4.5, 3_000_000_000], [$high->maxFloat, $high->minBigint]);
        self::assertSame(['2026-10-15 08:00:00', '2026-10-20 08:00:00'], [$high->first?->format('Y-m-d H:i:s'), $high->last?->format('Y-m-d H:i:s')]);
        self::assertSame('delta', $high->firstName);
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledUpdates());
    }

    public function testHavingAndOrderingOnAnAggregate(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');

        $totals = $this->em->query($o)->join($c)->groupBy($c->name)
            ->orderBy($o->id->count()->desc(), $c->name->asc())
            ->selectInto(CustomerTotal::class, customer: $c->name, orders: $o->id->count(), last: $o->reference->max())
            ->getResult();

        self::assertEquals([new CustomerTotal('Ada', 2, 'A2'), new CustomerTotal('Bob', 1, 'B1')], $totals);

        $busy = $this->em->query($o)->join($c)->groupBy($c->name)->having($o->id->count()->gt(1))
            ->selectInto(CustomerTotal::class, customer: $c->name, orders: $o->id->count())
            ->getResult();
        self::assertEquals([new CustomerTotal('Ada', 2)], $busy);
    }

    // sans groupement, une seule ligne ; un agrégat d'aucune valeur vaut null, COUNT vaut 0
    public function testEmptySetGivesNullAggregates(): void
    {
        $t = AllTypes_::as('t');

        $tally = $this->em->query($t)->where($t->id->gt(100))
            ->selectInto(Tally::class, count: $t->id->count(), total: $t->decimal->sum(), average: $t->decimal->avg(), max: $t->float->max(), last: $t->datetime->max(), name: $t->string->min())
            ->getOneOrNull();

        self::assertNotNull($tally);
        self::assertSame(0, $tally->count);
        self::assertSame(['total' => null, 'average' => null, 'max' => null, 'last' => null, 'name' => null], $tally->extra);
    }

    public function testCountOfAnEmptySetIsZero(): void
    {
        $t = AllTypes_::as('t');

        self::assertSame(0, $this->em->query($t)->where($t->id->gt(100))->selectInto(Tally::class, count: $t->id->count())->getOneOrNull()?->count);
        self::assertSame(4, $this->em->query($t)->selectInto(Tally::class, count: $t->id->count())->getOneOrNull()?->count);
    }

    public function testOneOrNull(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');
        $query = $this->em->query($o)->join($c)->groupBy($c->name);

        self::assertSame(1, $query->where($c->name->eq('Bob'))->selectInto(CustomerTotal::class, customer: $c->name, orders: $o->id->count())->getOneOrNull()?->orders);
        self::assertNull($query->where($c->name->eq('Cy'))->selectInto(CustomerTotal::class, customer: $c->name, orders: $o->id->count())->getOneOrNull());

        $this->expectException(NonUniqueResultException::class);
        $query->selectInto(CustomerTotal::class, customer: $c->name, orders: $o->id->count())->getOneOrNull();
    }

    // RG-05.7 : une valeur d'un autre type que le paramètre est refusée à la construction
    public function testTypeMismatchIsReported(): void
    {
        $t = AllTypes_::as('t');

        try {
            $this->em->query($t)->selectInto(WrongType::class, name: $t->string)->getResult();
            self::fail('Expected QueryException.');
        } catch (QueryException $e) {
            self::assertStringStartsWith('A row cannot build ' . WrongType::class . ': ', $e->getMessage());
            self::assertStringContainsString('$name', $e->getMessage());
            self::assertInstanceOf(\TypeError::class, $e->getPrevious());
        }
    }
}
