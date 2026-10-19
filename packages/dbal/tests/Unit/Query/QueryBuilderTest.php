<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Exception\InvalidQueryException;
use Prism\Dbal\LockMode;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Query\QueryBuilder;

final class QueryBuilderTest extends TestCase
{
    // serverVersion : la plateforme est connue sans se connecter
    private static function qb(string $url = 'postgresql://u@db/erp?serverVersion=16'): QueryBuilder
    {
        return DriverManager::fromUrl($url)->createQueryBuilder();
    }

    public function testSelectWithEveryClause(): void
    {
        $qb = self::qb();
        $qb = $qb->select('i.id', 'c.name')
            ->addSelect('COUNT(l.id) AS lines')
            ->distinct()
            ->from('invoice', 'i')
            ->join('client', 'c', 'c.id = i.client_id')
            ->leftJoin('invoice_line', 'l', 'l.invoice_id = i.id')
            ->where($qb->expr()->eq('i.status', 'paid'))
            ->andWhere($qb->expr()->gte('i.total', '100.00', 'decimal'))
            ->groupBy('i.id', 'c.name')
            ->having('COUNT(l.id) > :min', ['min' => 2])
            ->orderBy('c.name')
            ->addOrderBy('i.id', Order::Desc)
            ->limit(20)
            ->offset(40);

        self::assertSame(
            'SELECT DISTINCT i.id, c.name, COUNT(l.id) AS lines FROM "invoice" "i"'
            . ' INNER JOIN "client" "c" ON c.id = i.client_id'
            . ' LEFT JOIN "invoice_line" "l" ON l.invoice_id = i.id'
            . ' WHERE (i.status = :prism_1) AND (i.total >= :prism_2)'
            . ' GROUP BY i.id, c.name HAVING COUNT(l.id) > :min'
            . ' ORDER BY c.name ASC, i.id DESC LIMIT 20 OFFSET 40',
            $qb->toSql(),
        );
        self::assertSame(['prism_1' => 'paid', 'prism_2' => '100.00', 'min' => 2], $qb->getParameters());
        self::assertSame(['prism_2' => 'decimal'], $qb->getParameterTypes());
    }

    public function testSelectDefaultsToStarAndWorksWithoutFrom(): void
    {
        self::assertSame('SELECT * FROM "invoice"', self::qb()->from('invoice')->toSql());
        self::assertSame('SELECT 1', self::qb()->select('1')->toSql());
    }

    // décision A : chaque méthode retourne une copie, la requête de base n'est jamais modifiée
    public function testBuilderIsImmutable(): void
    {
        $base = self::qb()->select('i.id')->from('invoice', 'i')->where('i.tenant_id = :t', ['t' => 1]);

        $count = $base->select('COUNT(*)');
        $page = $base->orderBy('i.id')->limit(10);

        self::assertSame('SELECT i.id FROM "invoice" "i" WHERE i.tenant_id = :t', $base->toSql());
        self::assertSame('SELECT COUNT(*) FROM "invoice" "i" WHERE i.tenant_id = :t', $count->toSql());
        self::assertSame('SELECT i.id FROM "invoice" "i" WHERE i.tenant_id = :t ORDER BY i.id ASC LIMIT 10', $page->toSql());
        self::assertNotSame($base, $base->distinct(false));
    }

    // RG-01.3 : les noms de structure sont cités, y compris les mots réservés
    public function testIdentifiersAreQuotedPerPlatform(): void
    {
        self::assertSame('SELECT * FROM "order" "group"', self::qb()->from('order', 'group')->toSql());
        self::assertSame('SELECT * FROM `order` `group`', self::qb('mysql://u@db/erp?serverVersion=8.4')->from('order', 'group')->toSql());
        self::assertSame('"i"."order"', self::qb()->column('order', 'i'));
        self::assertSame('`user`', self::qb('mariadb://u@db/erp?serverVersion=11.8')->column('user'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function offsetWithoutLimit(): iterable
    {
        yield 'PostgreSQL' => ['postgresql://u@db/erp?serverVersion=16', 'SELECT * FROM "t" OFFSET 5'];
        yield 'MySQL' => ['mysql://u@db/erp?serverVersion=8.4', 'SELECT * FROM `t` LIMIT 18446744073709551615 OFFSET 5'];
        yield 'MariaDB' => ['mariadb://u@db/erp?serverVersion=11.8', 'SELECT * FROM `t` LIMIT 18446744073709551615 OFFSET 5'];
        yield 'SQLite' => ['sqlite:///:memory:?serverVersion=3.46', 'SELECT * FROM "t" LIMIT -1 OFFSET 5'];
    }

    #[DataProvider('offsetWithoutLimit')]
    public function testOffsetWithoutLimitPerPlatform(string $url, string $sql): void
    {
        self::assertSame($sql, self::qb($url)->from('t')->offset(5)->toSql());
    }

    public function testLimitZeroAndNoOffset(): void
    {
        self::assertSame('SELECT * FROM "t" LIMIT 0', self::qb()->from('t')->limit(0)->toSql());
        self::assertSame('SELECT * FROM "t"', self::qb()->from('t')->limit(10)->limit(null)->offset(0)->toSql());
    }

    // RG-01.4 : les valeurs d'un INSERT sont liées, les colonnes citées
    public function testInsert(): void
    {
        $qb = self::qb()->insert('order')
            ->set('number', 'F-2026-001')
            ->set('total', '12.50', 'decimal')
            ->set('paid', false, ParameterType::Boolean);

        self::assertSame('INSERT INTO "order" ("number", "total", "paid") VALUES (:prism_1, :prism_2, :prism_3)', $qb->toSql());
        self::assertSame(['prism_1' => 'F-2026-001', 'prism_2' => '12.50', 'prism_3' => false], $qb->getParameters());
        self::assertSame(['prism_2' => 'decimal', 'prism_3' => ParameterType::Boolean], $qb->getParameterTypes());
    }

    public function testUpdateWithValueAndExpression(): void
    {
        $qb = self::qb();
        $qb = $qb->update('stock')
            ->set('label', "O'Reilly")
            ->setExpression('qty', 'qty - 1')
            ->where($qb->expr()->eq('id', 7));

        self::assertSame('UPDATE "stock" SET "label" = :prism_1, "qty" = qty - 1 WHERE id = :prism_2', $qb->toSql());
        self::assertSame(['prism_1' => "O'Reilly", 'prism_2' => 7], $qb->getParameters());
    }

    public function testDelete(): void
    {
        $qb = self::qb();

        self::assertSame('DELETE FROM "invoice"', $qb->delete('invoice')->toSql());
        self::assertSame('DELETE FROM "invoice" WHERE id IN (:prism_1, :prism_2)', $qb->delete('invoice')->where($qb->expr()->in('id', [1, 2]))->toSql());
    }

    public function testOrWhereAndNestedComposites(): void
    {
        $qb = self::qb();
        $e = $qb->expr();
        $qb = $qb->from('t')
            ->where($e->eq('a', 1))
            ->orWhere($e->and($e->isNull('b'), $e->or('c = d', $e->lt('e', 5))));

        self::assertSame('SELECT * FROM "t" WHERE (a = :prism_1) OR ((b IS NULL) AND ((c = d) OR (e < :prism_2)))', $qb->toSql());
        self::assertSame(['prism_1' => 1, 'prism_2' => 5], $qb->getParameters());
    }

    // les noms automatiques suivent l'ordre d'apparition : le SQL est stable d'une génération à l'autre
    public function testGeneratedSqlIsDeterministic(): void
    {
        $build = static function (): QueryBuilder {
            $qb = self::qb();

            return $qb->from('t')->where($qb->expr()->eq('a', 1))->andWhere($qb->expr()->in('b', ['x', 'y']));
        };

        self::assertSame($build()->toSql(), $build()->toSql());
        self::assertSame('SELECT * FROM "t" WHERE (a = :prism_1) AND (b IN (:prism_2, :prism_3))', $build()->toSql());
    }

    public function testWhereReplacesThePreviousCondition(): void
    {
        self::assertSame('SELECT * FROM "t" WHERE b = 2', self::qb()->from('t')->where('a = 1')->where('b = 2')->toSql());
        self::assertSame('SELECT * FROM "t" WHERE a = 1', self::qb()->from('t')->andWhere('a = 1')->toSql());
    }

    public function testJoinParametersAndSetParameter(): void
    {
        $qb = self::qb()->from('a', 'a')
            ->join('b', 'b', 'b.a_id = a.id AND b.kind = :kind', ['kind' => 'x'])
            ->where('a.created_at > :since')
            ->setParameter('since', new \DateTimeImmutable('2026-01-01'), 'datetime_immutable');

        self::assertSame('SELECT * FROM "a" "a" INNER JOIN "b" "b" ON b.a_id = a.id AND b.kind = :kind WHERE a.created_at > :since', $qb->toSql());
        self::assertSame(['kind', 'since'], array_keys($qb->getParameters()));
        self::assertSame(['since' => 'datetime_immutable'], $qb->getParameterTypes());
    }

    /**
     * @return iterable<string, array{\Closure(QueryBuilder): mixed, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'empty select' => [static fn (QueryBuilder $qb): string => $qb->toSql(), 'needs at least one expression or a FROM clause'];
        yield 'set on select' => [static fn (QueryBuilder $qb): QueryBuilder => $qb->from('t')->set('a', 1), 'set() is not supported in SELECT queries'];
        yield 'insert without values' => [static fn (QueryBuilder $qb): string => $qb->insert('t')->toSql(), 'INSERT query needs at least one column'];
        yield 'update without values' => [static fn (QueryBuilder $qb): string => $qb->update('t')->toSql(), 'UPDATE query needs at least one column'];
        yield 'insert with where' => [static fn (QueryBuilder $qb): string => $qb->insert('t')->set('a', 1)->where('a = 1')->toSql(), 'WHERE is not supported in INSERT'];
        yield 'update with limit' => [static fn (QueryBuilder $qb): string => $qb->update('t')->set('a', 1)->limit(1)->toSql(), 'LIMIT is not supported in UPDATE'];
        yield 'delete with join' => [static fn (QueryBuilder $qb): string => $qb->delete('t')->join('u', 'u', 'u.id = t.u')->toSql(), 'JOIN is not supported in DELETE'];
        yield 'delete with order' => [static fn (QueryBuilder $qb): string => $qb->delete('t')->orderBy('id')->toSql(), 'ORDER BY is not supported in DELETE'];
        yield 'negative limit' => [static fn (QueryBuilder $qb): QueryBuilder => $qb->limit(-1), 'LIMIT must be zero or positive, got -1'];
        yield 'negative offset' => [static fn (QueryBuilder $qb): QueryBuilder => $qb->offset(-5), 'OFFSET must be zero or positive, got -5'];
        yield 'reserved parameter name' => [static fn (QueryBuilder $qb): QueryBuilder => $qb->setParameter('prism_1', 1), 'Invalid parameter name "prism_1"'];
        yield 'invalid parameter name' => [static fn (QueryBuilder $qb): QueryBuilder => $qb->where('a = :1a', ['1a' => 1]), 'Invalid parameter name "1a"'];
    }

    /**
     * @param \Closure(QueryBuilder): mixed $build
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRefused(\Closure $build, string $message): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage($message);

        $build(self::qb());
    }

    public function testLockIsAppendedAfterTheLimit(): void
    {
        $qb = self::qb()->from('invoice')->where('id = :id', ['id' => 1])->limit(1);

        self::assertSame('SELECT * FROM "invoice" WHERE id = :id LIMIT 1 FOR UPDATE', $qb->lock(LockMode::PessimisticWrite)->toSql());
        self::assertSame('SELECT * FROM `invoice` WHERE id = :id LOCK IN SHARE MODE', self::qb('mariadb://u@db/erp?serverVersion=11.8')->from('invoice')->where('id = :id', ['id' => 1])->lock(LockMode::PessimisticRead)->toSql());
        self::assertSame('SELECT * FROM "invoice" WHERE id = :id LIMIT 1', $qb->lock(LockMode::PessimisticWrite)->lock(null)->toSql(), 'null removes the lock');
        self::assertSame('SELECT * FROM "invoice" WHERE id = :id LIMIT 1', $qb->toSql(), 'the base query is left untouched');
    }

    public function testLockIsRefusedOnWrites(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('LOCK is not supported in UPDATE queries.');

        self::qb()->update('invoice')->set('total', 1)->lock(LockMode::PessimisticWrite)->toSql();
    }
}
