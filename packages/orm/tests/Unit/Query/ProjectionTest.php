<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Tests\Unit\Query\Fixtures\CustomerTotal;
use Prism\Orm\Tests\Unit\Query\Fixtures\Tally;
use Prism\Orm\Tests\Unit\Query\Fixtures\TypeSummary;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order_;

/**
 * Compilation des agrégats, du groupement et des projections, sans base (ADR 0010, RG-05.7).
 */
final class ProjectionTest extends TestCase
{
    private EntityManager $em;

    private AllTypes_ $t;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));
        $this->t = AllTypes_::as('t');
    }

    private function summary(): string
    {
        $t = $this->t;

        return $this->em->query($t)
            ->where($t->bool->eq(true))
            ->groupBy($t->priority)
            ->having($t->decimal->sum()->gt('100.00'))
            ->andHaving($t->id->count()->gte(2))
            ->orderBy($t->decimal->sum()->desc(), $t->priority->asc())
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
            ->toSql();
    }

    public function testAggregatesGroupingAndOrdering(): void
    {
        self::assertSame(
            'SELECT "t"."priority" AS "priority", COUNT("t"."id") AS "count", SUM("t"."decimal") AS "total", AVG("t"."smallint") AS "average",'
            . ' MAX("t"."float") AS "maxFloat", MIN("t"."bigint") AS "minBigint", MIN("t"."datetime") AS "first", MAX("t"."datetime") AS "last", MIN("t"."string") AS "firstName"'
            . ' FROM "all_types" "t" WHERE "t"."bool" = :prism_1 GROUP BY "t"."priority"'
            . ' HAVING (SUM("t"."decimal") > :prism_2) AND (COUNT("t"."id") >= :prism_3) ORDER BY SUM("t"."decimal") DESC, "t"."priority" ASC',
            $this->summary(),
        );
    }

    // RG-05.3 : une expression lie ses valeurs par le Type de son résultat
    public function testExpressionsBindTypedValues(): void
    {
        $t = $this->t;
        $projection = $this->em->query($t)->groupBy($t->bool)
            ->having(\Prism\Orm\Query\Condition::all($t->decimal->avg()->between('1.00', '9.00'), $t->datetime->max()->lt(new \DateTimeImmutable('2026-01-01')), $t->id->countDistinct()->in([1, 2]), $t->float->sum()->isNotNull()))
            ->selectInto(Tally::class, count: $t->id->count());

        self::assertStringEndsWith(
            'GROUP BY "t"."bool" HAVING (AVG("t"."decimal") BETWEEN :prism_1 AND :prism_2) AND (MAX("t"."datetime") < :prism_3) AND (COUNT(DISTINCT "t"."id") IN (:prism_4, :prism_5)) AND (SUM("t"."float") IS NOT NULL)',
            $projection->toSql(),
        );
        self::assertSame(['prism_1' => 'decimal', 'prism_2' => 'decimal', 'prism_3' => 'datetime_immutable', 'prism_4' => 'integer', 'prism_5' => 'integer'], $projection->getParameterTypes());
        self::assertSame('1.00', $projection->getParameters()['prism_1']);
    }

    public function testAggregateWithoutGroupingIsOneGroup(): void
    {
        $t = $this->t;

        self::assertSame('SELECT COUNT("t"."id") AS "count" FROM "all_types" "t"', $this->em->query($t)->selectInto(Tally::class, count: $t->id->count())->toSql());
    }

    public function testJoinedFieldsAndFetchJoinAsPlainJoin(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');

        $sql = $this->em->query($o)->fetchJoin($c)->groupBy($c->name)
            ->selectInto(CustomerTotal::class, customer: $c->name, orders: $o->id->count(), last: $o->reference->max())
            ->toSql();

        self::assertSame('SELECT "c"."name" AS "customer", COUNT("o"."id") AS "orders", MAX("o"."reference") AS "last" FROM "order" "o" INNER JOIN "customer" "c" ON "c"."id" = "o"."customer_id" GROUP BY "c"."name"', $sql);
    }

    public function testPlainSelectionWithoutAggregateNeedsNoGrouping(): void
    {
        $c = Customer_::as('c');

        self::assertSame(
            'SELECT "c"."name" AS "customer", "c"."id" AS "orders" FROM "customer" "c" ORDER BY "c"."name" ASC LIMIT 5',
            $this->em->query($c)->orderBy($c->name->asc())->limit(5)->selectInto(CustomerTotal::class, customer: $c->name, orders: $c->id)->toSql(),
        );
    }

    public function testLongSelectionNameStaysUnderTheIdentifierLimit(): void
    {
        $t = $this->t;
        $name = str_repeat('n', 64);

        self::assertSame('SELECT COUNT("t"."id") AS "count", "t"."id" AS "prism_1" FROM "all_types" "t" GROUP BY "t"."id"', $this->em->query($t)->groupBy($t->id)->selectInto(Tally::class, ...['count' => $t->id->count(), $name => $t->id])->toSql());
    }

    // ---------------------------------------------------------------- groupement refusé

    public function testUngroupedSelectionIsRefused(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The selection "customer" ("c"."name") is neither an aggregate nor in groupBy(): group by it, or aggregate it.');

        $this->em->query($o)->join($c)->groupBy($c->id)->selectInto(CustomerTotal::class, customer: $c->name, orders: $o->id->count())->toSql();
    }

    public function testUngroupedOrderingIsRefused(): void
    {
        $t = $this->t;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The ordering ("t"."string") is neither an aggregate nor in groupBy(): group by it, or aggregate it.');

        $this->em->query($t)->orderBy($t->string->asc())->selectInto(Tally::class, count: $t->id->count())->toSql();
    }

    public function testGroupedEntityQueryIsRefused(): void
    {
        $t = $this->t;
        $message = 'A query on ' . AllTypes::class . ' with groupBy(), having() or an ordering on an aggregate returns groups, not entities: project it with selectInto().';

        foreach ([
            $this->em->query($t)->groupBy($t->bool),
            $this->em->query($t)->having($t->id->count()->gt(1)),
            $this->em->query($t)->orderBy($t->id->count()->desc()),
        ] as $query) {
            try {
                $query->toSql();
                self::fail('Expected QueryException.');
            } catch (QueryException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------- DTO (RG-05.7)

    public function testUnnamedSelectionIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Each selection of selectInto(' . Tally::class . '::class, ...) is named after a parameter of its constructor, such as total: $o->total->sum().');

        $this->em->query($this->t)->selectInto(Tally::class, $this->t->id->count());
    }

    public function testSelectionWithoutParameterIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The selection "total" matches no parameter of ' . CustomerTotal::class . '::__construct() (customer, orders, last).');

        $this->em->query($this->t)->selectInto(CustomerTotal::class, customer: $this->t->string, orders: $this->t->id, total: $this->t->decimal);
    }

    public function testMissingRequiredParameterIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The parameter $orders of ' . CustomerTotal::class . '::__construct() is required but not selected: select it as orders: ...');

        $this->em->query($this->t)->selectInto(CustomerTotal::class, customer: $this->t->string);
    }

    public function testOptionalAndVariadicParameters(): void
    {
        $t = $this->t;

        self::assertStringStartsWith('SELECT "t"."string" AS "customer", "t"."id" AS "orders" FROM', $this->em->query($t)->selectInto(CustomerTotal::class, customer: $t->string, orders: $t->id)->toSql(), 'an optional parameter may be left out');
        self::assertStringStartsWith('SELECT COUNT("t"."id") AS "count", MAX("t"."string") AS "label" FROM', $this->em->query($t)->selectInto(Tally::class, count: $t->id->count(), label: $t->string->max())->toSql(), 'a variadic parameter takes any name');
    }
}
