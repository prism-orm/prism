<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Exception\InvalidQueryException;
use Prism\Dbal\Query\Expression;
use Prism\Dbal\Query\QueryBuilder;

final class ExpressionBuilderTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46')->createQueryBuilder()->from('t');
    }

    public function testComparisons(): void
    {
        $e = $this->qb->expr();

        self::assertSame('a = :prism_1', $this->where($e->eq('a', 1)));
        self::assertSame('a <> :prism_1', $this->where($e->neq('a', 1)));
        self::assertSame('a < :prism_1', $this->where($e->lt('a', 1)));
        self::assertSame('a <= :prism_1', $this->where($e->lte('a', 1)));
        self::assertSame('a > :prism_1', $this->where($e->gt('a', 1)));
        self::assertSame('a >= :prism_1', $this->where($e->gte('a', 1)));
        self::assertSame('a LIKE :prism_1', $this->where($e->like('a', 'F-%')));
        self::assertSame('a IS NULL', $this->where($e->isNull('a')));
        self::assertSame('a IS NOT NULL', $this->where($e->isNotNull('a')));
        self::assertSame('a NOT IN (:prism_1, :prism_2)', $this->where($e->notIn('a', [1, 2])));
    }

    // décision C : une liste vide ne produit jamais « IN () », invalide en SQL
    public function testEmptyListsProduceConstantConditions(): void
    {
        $e = $this->qb->expr();

        self::assertSame('1 = 0', $this->where($e->in('a', [])));
        self::assertSame('1 = 1', $this->where($e->notIn('a', [])));
        self::assertSame([], $this->qb->where($e->in('a', []))->getParameters());
    }

    public function testComparingWithNullIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('Cannot compare with NULL using "=": the result is never true in SQL. Use isNull() or isNotNull().');

        $this->qb->expr()->eq('a', null);
    }

    public function testSingleConditionCompositeIsNotWrapped(): void
    {
        self::assertSame('a = 1', $this->where($this->qb->expr()->and('a = 1')));
    }

    public function testEmptyCompositeIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('or() needs at least one condition.');

        $this->qb->expr()->or();
    }

    public function testTypedValuesKeepTheirType(): void
    {
        $qb = $this->qb->where($this->qb->expr()->in('d', ['1.50', '2.00'], 'decimal'));

        self::assertSame(['prism_1' => 'decimal', 'prism_2' => 'decimal'], $qb->getParameterTypes());
    }

    private function where(Expression $expression): string
    {
        return substr($this->qb->where($expression)->toSql(), \strlen('SELECT * FROM "t" WHERE '));
    }

    public function testBetweenBindsBothBounds(): void
    {
        $qb = $this->qb->where($this->qb->expr()->between('total', '10.00', '20.00', 'decimal'));

        self::assertSame('total BETWEEN :prism_1 AND :prism_2', $this->where($this->qb->expr()->between('total', '10.00', '20.00', 'decimal')));
        self::assertSame(['prism_1' => '10.00', 'prism_2' => '20.00'], $qb->getParameters());
        self::assertSame(['prism_1' => 'decimal', 'prism_2' => 'decimal'], $qb->getParameterTypes());
    }

    public function testBetweenRefusesANullBound(): void
    {
        $this->expectException(InvalidQueryException::class);

        $this->qb->expr()->between('total', null, 1);
    }

    public function testNotWrapsItsCondition(): void
    {
        $e = $this->qb->expr();

        self::assertSame('NOT ((a = :prism_1) OR (b IS NULL))', $this->where($e->not($e->or($e->eq('a', 1), 'b IS NULL'))));
    }

    public function testAggregates(): void
    {
        $e = $this->qb->expr();

        self::assertSame(['COUNT(*)', 'COUNT(o.id)', 'COUNT(DISTINCT o.client_id)'], [$e->count(), $e->count('o.id'), $e->count('o.client_id', distinct: true)]);
        self::assertSame(['SUM(o.total)', 'AVG(o.total)', 'MIN(o.total)', 'MAX(o.total)'], [$e->sum('o.total'), $e->avg('o.total'), $e->min('o.total'), $e->max('o.total')]);
    }
}
