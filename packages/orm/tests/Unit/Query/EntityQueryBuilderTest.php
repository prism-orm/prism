<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Query\Condition;
use Prism\Orm\Query\Metamodel\StringField;
use Prism\Orm\Tests\Support\MetamodelFixtures;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes_;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\Priority;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order_;

/**
 * Compilation du QueryBuilder typé, sans base (la connexion SQLite n'est jamais ouverte) :
 * colonnes résolues par la metadata, valeurs liées et typées (RG-05.3, RG-05.4, RG-05.10).
 */
final class EntityQueryBuilderTest extends TestCase
{
    private EntityManager $em;

    private AllTypes_ $t;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));
        $this->t = AllTypes_::as('t');
    }

    private function where(Condition $condition): string
    {
        $sql = $this->em->query($this->t)->where($condition)->toSql();

        return substr($sql, (int) strpos($sql, ' WHERE ') + 7);
    }

    // le metamodel versionné des fixtures suit leur mapping
    public function testFixtureMetamodelIsFresh(): void
    {
        self::assertTrue(new \Prism\Orm\Query\Metamodel\MetamodelGenerator()->isFresh(MetamodelFixtures::metadata(), MetamodelFixtures::directory(), MetamodelFixtures::PREFIX), 'regenerate it: docker compose run --rm php php packages/orm/tests/Support/MetamodelFixtures.php');
    }

    public function testSelectsEveryColumnOfTheRootUnderItsAlias(): void
    {
        self::assertSame(
            'SELECT "t"."id", "t"."string", "t"."text", "t"."uuid", "t"."smallint", "t"."bigint", "t"."decimal", "t"."float", "t"."bool", "t"."datetime", "t"."date", "t"."time", "t"."json", "t"."binary", "t"."priority" FROM "all_types" "t"',
            $this->em->query($this->t)->toSql(),
        );
    }

    public function testComparisonsBindTypedValues(): void
    {
        $t = $this->t;
        $date = new \DateTimeImmutable('2026-10-20 08:00:00');

        self::assertSame('"t"."string" = :prism_1', $this->where($t->string->eq('a')));
        self::assertSame('"t"."bigint" <> :prism_1', $this->where($t->bigint->neq(3)));
        self::assertSame('"t"."decimal" < :prism_1', $this->where($t->decimal->lt('9.99')));
        self::assertSame('"t"."float" <= :prism_1', $this->where($t->float->lte(1.5)));
        self::assertSame('"t"."datetime" > :prism_1', $this->where($t->datetime->gt($date)));
        self::assertSame('"t"."smallint" >= :prism_1', $this->where($t->smallint->gte(2)));
        self::assertSame('"t"."bool" = :prism_1', $this->where($t->bool->eq(true)));
        self::assertSame('"t"."binary" <> :prism_1', $this->where($t->binary->neq("\x00")));

        $qb = $this->em->query($t)->where(Condition::all($t->decimal->gte('10.00'), $t->datetime->lt($date)));
        self::assertSame(['prism_1' => '10.00', 'prism_2' => $date], $qb->getParameters());
        self::assertSame(['prism_1' => 'decimal', 'prism_2' => 'datetime_immutable'], $qb->getParameterTypes(), 'converted by the Type of the field');
    }

    public function testListsRangesPatternsAndNulls(): void
    {
        $t = $this->t;

        self::assertSame('"t"."id" IN (:prism_1, :prism_2)', $this->where($t->id->in([1, 2])));
        self::assertSame('"t"."string" NOT IN (:prism_1)', $this->where($t->string->notIn(['x'])));
        self::assertSame('1 = 0', $this->where($t->id->in([])), 'an empty list matches nothing');
        self::assertSame('"t"."decimal" BETWEEN :prism_1 AND :prism_2', $this->where($t->decimal->between('1.00', '2.00')));
        self::assertSame('"t"."text" LIKE :prism_1', $this->where($t->text->like('%urgent%')));
        self::assertSame('"t"."uuid" IS NULL', $this->where($t->uuid->isNull()));
        self::assertSame('"t"."json" IS NOT NULL', $this->where($t->json->isNotNull()));
    }

    // RG-05.3 : un enum se compare à ses cas, liés par leur valeur
    public function testEnumIsBoundByItsValue(): void
    {
        $qb = $this->em->query($this->t)->where($this->t->priority->in([Priority::High, Priority::Low]));

        self::assertSame(['prism_1' => 2, 'prism_2' => 1], $qb->getParameters());
        self::assertSame('"t"."priority" <> :prism_1', $this->where($this->t->priority->neq(Priority::High)));
    }

    public function testCombinations(): void
    {
        $t = $this->t;

        self::assertSame('("t"."id" = :prism_1) AND (("t"."string" = :prism_2) OR ("t"."string" IS NULL))', $this->where(Condition::all($t->id->eq(1), Condition::any($t->string->eq('a'), $t->string->isNull()))));
        self::assertSame('NOT ("t"."bool" = :prism_1)', $this->where($t->bool->eq(true)->not()));
        self::assertSame('1 = 1', $this->where(Condition::all()));
        self::assertSame('1 = 0', $this->where(Condition::any()));
    }

    public function testWhereAndWhereOrWhere(): void
    {
        $t = $this->t;
        $qb = $this->em->query($t)->andWhere($t->id->gt(1))->andWhere($t->id->lt(9))->orWhere($t->bool->eq(true));

        self::assertStringEndsWith('WHERE (("t"."id" > :prism_1) AND ("t"."id" < :prism_2)) OR ("t"."bool" = :prism_3)', $qb->toSql());
        self::assertStringEndsWith('WHERE "t"."bool" = :prism_1', $qb->where($t->bool->eq(true))->toSql(), 'where() replaces');
    }

    public function testOrderLimitOffsetAndImmutability(): void
    {
        $t = $this->t;
        $base = $this->em->query($t);

        $page = $base->orderBy($t->datetime->desc())->addOrderBy($t->id->asc())->limit(20)->offset(40);

        self::assertStringEndsWith('ORDER BY "t"."datetime" DESC, "t"."id" ASC LIMIT 20 OFFSET 40', $page->toSql());
        self::assertStringEndsWith('ORDER BY "t"."id" ASC', $page->orderBy($t->id->asc())->limit(null)->offset(0)->toSql());
        self::assertStringEndsWith('FROM "all_types" "t"', $base->toSql(), 'the base query is untouched');
        self::assertSame($this->em->getConnection(), $base->getConnection());
    }

    public function testNegativeLimitOrOffsetIsRefused(): void
    {
        try {
            $this->em->query($this->t)->limit(-1);
            self::fail('Expected QueryException.');
        } catch (QueryException $e) {
            self::assertSame('LIMIT must be zero or positive, got -1.', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('OFFSET must be zero or positive, got -2.');

        $this->em->query($this->t)->offset(-2);
    }

    // ---------------------------------------------------------------- relations ToOne

    public function testRelationComparesTheForeignKey(): void
    {
        $o = Order_::as('o');
        $customer = new Customer('Ada');
        $customer->id = 7;

        $qb = $this->em->query($o)->where(Condition::all($o->customer->eq($customer), $o->seller->isNull(), $o->depot->in([1, 2])));

        self::assertStringEndsWith('WHERE ("o"."customer_id" = :prism_1) AND ("o"."seller_id" IS NULL) AND ("o"."depot_id" IN (:prism_2, :prism_3))', $qb->toSql());
        self::assertSame(['prism_1' => 7, 'prism_2' => 1, 'prism_3' => 2], $qb->getParameters());
        self::assertSame('integer', $qb->getParameterTypes()['prism_1'], 'typed as the identifier of the target');
        self::assertStringEndsWith('WHERE "o"."depot_id" IS NOT NULL', $this->em->query($o)->where($o->depot->isNotNull())->toSql());
    }

    public function testInverseSideHasNoForeignKey(): void
    {
        $c = Customer_::as('c');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(Customer::class . '::$account is not an owning ToOne relation: only a foreign key can be compared; join the relation instead.');

        $this->em->query($c)->where($c->account->isNull())->toSql();
    }

    public function testUnpersistedTargetIsRefused(): void
    {
        $o = Order_::as('o');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The condition on $customer uses a ' . Customer::class . ' that has no identifier yet; flush it first.');

        $this->em->query($o)->where($o->customer->eq(new Customer('Unsaved')))->toSql();
    }

    // ---------------------------------------------------------------- jointures

    public function testJoinOnEachKindOfRelation(): void
    {
        $c = Customer_::as('c');
        $from = 'SELECT "c"."id", "c"."name", "c"."country_id" FROM "customer" "c" ';

        // ToOne possédée : la FK du propriétaire
        self::assertSame($from . 'INNER JOIN "country" "n" ON "n"."id" = "c"."country_id"', $this->em->query($c)->join($c->country->as('n'))->toSql());
        // OneToMany : la FK de la cible
        self::assertSame($from . 'LEFT JOIN "order" "o" ON "o"."customer_id" = "c"."id"', $this->em->query($c)->leftJoin($c->orders->as('o'))->toSql());
        // OneToOne inverse : la FK de la cible
        self::assertSame($from . 'INNER JOIN "account" "a" ON "a"."customer_id" = "c"."id"', $this->em->query($c)->join($c->account->as('a'))->toSql());
        // ManyToMany : par la table pivot, sous l'alias propriétaire__champ
        self::assertSame(
            $from . 'INNER JOIN "customer_group" "c__groups" ON "c__groups"."customer_id" = "c"."id" INNER JOIN "group" "g" ON "g"."id" = "c__groups"."group_id"',
            $this->em->query($c)->join($c->groups->as('g'))->toSql(),
        );
        $g = Group_::as('g');
        self::assertSame(
            'SELECT "g"."id", "g"."name" FROM "group" "g" INNER JOIN "customer_group" "g__customers" ON "g__customers"."group_id" = "g"."id" INNER JOIN "customer" "c" ON "c"."id" = "g__customers"."customer_id"',
            $this->em->query($g)->join($g->customers->as('c'))->toSql(),
            'inverse side of a ManyToMany',
        );
    }

    public function testConditionsAndOrderingOnAJoinedAlias(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');
        $n = $c->country->as('n');

        $qb = $this->em->query($o)->join($c)->leftJoin($n)->where($n->name->eq('France'))->orderBy($c->name->asc());

        self::assertStringEndsWith('FROM "order" "o" INNER JOIN "customer" "c" ON "c"."id" = "o"."customer_id" LEFT JOIN "country" "n" ON "n"."id" = "c"."country_id" WHERE "n"."name" = :prism_1 ORDER BY "c"."name" ASC', $qb->toSql());
        self::assertStringStartsWith('SELECT "o"."id", "o"."reference", ', $qb->toSql(), 'a plain join selects only the root');
    }

    // RG-05.5 : chaque colonne nommée alias__colonne, l'ordre de la collection chargée ajouté (RG-04.12)
    public function testFetchJoinSelectsTheJoinedColumnsUnderTheirAlias(): void
    {
        $c = Customer_::as('c');
        $o = $c->orders->as('o');

        self::assertSame(
            'SELECT "c"."id" AS "c__id", "c"."name" AS "c__name", "c"."country_id" AS "c__country_id", "o"."id" AS "o__id", "o"."reference" AS "o__reference", "o"."customer_id" AS "o__customer_id", "o"."seller_id" AS "o__seller_id", "o"."depot_id" AS "o__depot_id" FROM "customer" "c" LEFT JOIN "order" "o" ON "o"."customer_id" = "c"."id" ORDER BY "c"."name" ASC, "o"."reference" DESC',
            $this->em->query($c)->leftFetchJoin($o)->orderBy($c->name->asc())->toSql(),
        );
        self::assertStringContainsString('INNER JOIN "order" "o"', $this->em->query($c)->fetchJoin($o)->toSql());
    }

    public function testLongColumnAliasStaysUnderTheIdentifierLimit(): void
    {
        $c = Customer_::as('c');
        $n = $c->country->as(str_repeat('n', 60));

        $sql = $this->em->query($c)->fetchJoin($n)->toSql();

        self::assertStringContainsString('"' . str_repeat('n', 60) . '"."id" AS "prism_3"', $sql, 'PostgreSQL truncates identifiers beyond 63 bytes');
    }

    public function testJoinsAreImmutable(): void
    {
        $c = Customer_::as('c');
        $base = $this->em->query($c);
        $base->join($c->country->as('n'))->withoutBatchFetch();

        self::assertStringEndsWith('FROM "customer" "c"', $base->toSql());
    }

    public function testJoiningTheRootIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The alias "c" (' . Customer::class . ') is not a relation: join a metamodel obtained from one, such as $o->client->as(\'c\').');

        $this->em->query(Customer_::as('o'))->join(Customer_::as('c'));
    }

    public function testDuplicateAliasIsRefused(): void
    {
        $c = Customer_::as('c');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The alias "c" is already used in this query: give each join its own alias.');

        $this->em->query($c)->join($c->country->as('c'));
    }

    public function testJoinFromAnAliasOutsideTheQueryIsRefused(): void
    {
        $c = Customer_::as('c');
        $n = Order_::as('o')->customer->as('x')->country->as('n');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The alias "n" is joined from "x", which is not part of this query yet: join "x" first.');

        $this->em->query($c)->join($n);
    }

    public function testFetchJoinFromAPlainJoinIsRefused(): void
    {
        $o = Order_::as('o');
        $c = $o->customer->as('c');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The alias "n" is fetch-joined from "c", which is not fetched: fetch-join "c" too, or join "n" without fetching.');

        $this->em->query($o)->join($c)->fetchJoin($c->country->as('n'));
    }

    public function testLimitWithACollectionFetchJoinIsRefused(): void
    {
        $c = Customer_::as('c');
        $o = Order_::as('o');
        self::assertStringEndsWith('LIMIT 5', $this->em->query($o)->fetchJoin($o->customer->as('x'))->limit(5)->toSql(), 'a ToOne does not multiply the rows');
        self::assertStringEndsWith('LIMIT 5', $this->em->query($c)->join($c->orders->as('j'))->limit(5)->toSql(), 'a plain join does not load the collection');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('A limit or an offset would cut the collection fetch-joined as "o" and leave it partly loaded; use the Paginator, or join it without fetching.');

        $this->em->query($c)->leftFetchJoin($c->orders->as('o'))->offset(10)->toSql();
    }

    // ---------------------------------------------------------------- erreurs

    public function testFieldOfAnotherAliasIsRefused(): void
    {
        $other = AllTypes_::as('x');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The alias "x" (' . AllTypes::class . ') is not part of this query: start the query from it, or join it.');

        $this->em->query($this->t)->where($other->id->eq(1))->toSql();
    }

    public function testSameAliasNameFromAnotherInstanceIsRefused(): void
    {
        $this->expectException(QueryException::class);

        $this->em->query($this->t)->where(AllTypes_::as('t')->id->eq(1))->toSql();
    }

    public function testStaleMetamodelIsReported(): void
    {
        $stale = new StringField($this->t, 'removedField');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(AllTypes::class . '::$removedField is not mapped anymore: the metamodel is stale, regenerate it (db:metamodel:generate).');

        $this->em->query($this->t)->where($stale->eq('x'))->toSql();
    }

    public function testRootIsAMetamodel(): void
    {
        self::assertSame([AllTypes::class, 't', null], [$this->t->entity, $this->t->alias, $this->t->via()]);
    }
}
