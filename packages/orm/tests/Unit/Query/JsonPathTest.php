<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Query\Condition;
use Prism\Orm\Tests\Unit\Query\Fixtures\CustomerTotal;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes_;

/**
 * Chemins JSON du QueryBuilder typé, compilés pour SQLite (ADR 0010) : le chemin est écrit en
 * littéral validé, les valeurs comparées restent liées (RG-05.4).
 */
final class JsonPathTest extends TestCase
{
    private EntityManager $em;

    private AllTypes_ $t;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));
        $this->t = AllTypes_::as('t');
    }

    public function testComparisonsBindTheValueNotThePath(): void
    {
        $t = $this->t;
        $qb = $this->em->query($t)
            ->where(Condition::all(
                $t->json->path('customer', 'city')->asString()->eq('Lyon'),
                $t->json->path('lines', 0, 'qty')->asInt()->gt(2),
                $t->json->path('amount')->asDecimal()->between('1.00', '20.00'),
                $t->json->path('note')->asString()->isNull(),
            ));

        self::assertStringEndsWith(
            "WHERE (CAST(json_extract(\"t\".\"json\", '$.\"customer\".\"city\"') AS TEXT) = :prism_1)"
            . " AND (CAST(CAST(json_extract(\"t\".\"json\", '$.\"lines\"[0].\"qty\"') AS TEXT) AS INTEGER) > :prism_2)"
            . " AND (CAST(CAST(json_extract(\"t\".\"json\", '$.\"amount\"') AS TEXT) AS NUMERIC) BETWEEN :prism_3 AND :prism_4)"
            . " AND (CAST(json_extract(\"t\".\"json\", '$.\"note\"') AS TEXT) IS NULL)",
            $qb->toSql(),
        );
        self::assertSame(['prism_1' => 'Lyon', 'prism_2' => 2, 'prism_3' => '1.00', 'prism_4' => '20.00'], $qb->getParameters());
        self::assertSame(['prism_1' => 'string', 'prism_2' => 'integer', 'prism_3' => 'decimal', 'prism_4' => 'decimal'], $qb->getParameterTypes());
    }

    // le même chemin donne le même SQL : groupé, il peut être sélectionné et trié
    public function testPathCanBeGroupedSelectedAndOrdered(): void
    {
        $t = $this->t;
        $city = $t->json->path('customer', 'city');

        $sql = $this->em->query($t)->groupBy($city->asString())->orderBy($t->json->path('customer', 'city')->asString()->asc())
            ->selectInto(CustomerTotal::class, customer: $city->asString(), orders: $t->id->count())
            ->toSql();

        $extract = "CAST(json_extract(\"t\".\"json\", '$.\"customer\".\"city\"') AS TEXT)";
        self::assertSame(\sprintf('SELECT %1$s AS "customer", COUNT("t"."id") AS "orders" FROM "all_types" "t" GROUP BY %1$s ORDER BY %1$s ASC', $extract), $sql);
    }

    public function testEntityQueryCanOrderByAPath(): void
    {
        $t = $this->t;

        self::assertStringEndsWith("ORDER BY CAST(CAST(json_extract(\"t\".\"json\", '$.\"rank\"') AS TEXT) AS INTEGER) DESC", $this->em->query($t)->orderBy($t->json->path('rank')->asInt()->desc())->toSql());
    }

    public function testPathKeepsItsKeys(): void
    {
        $path = $this->t->json->path('lines', 0);

        self::assertSame([$this->t->json, ['lines', 0]], [$path->field, $path->keys]);
    }

    public function testInvalidPathIsRefusedAtOnce(): void
    {
        foreach ([[], ["city'; DROP TABLE x; --"], ['a.b'], ['lines', -1]] as $keys) {
            try {
                $this->t->json->path(...$keys);
                self::fail('Expected QueryException for ' . json_encode($keys));
            } catch (QueryException $e) {
                self::assertSame(\sprintf('Invalid JSON path %s on $json: give one or more keys of letters, digits and underscores, or array indexes from 0.', json_encode($keys)), $e->getMessage());
            }
        }
    }
}
