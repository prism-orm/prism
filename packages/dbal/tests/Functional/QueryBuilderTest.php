<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Tests\Support\TestDatabase;

final class QueryBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->connection = TestDatabase::connection();
        $this->dropTables();

        $q = $this->connection->getPlatform()->quoteIdentifier(...);
        $this->connection->executeStatement(\sprintf('CREATE TABLE %s (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50) NOT NULL)', $q('client')));
        // table et colonne aux noms réservés (RG-01.3)
        $this->connection->executeStatement(\sprintf(
            'CREATE TABLE %s (id INTEGER NOT NULL PRIMARY KEY, client_id INTEGER NOT NULL, %s VARCHAR(10) NOT NULL, total %s NOT NULL, issued_at %s NULL)',
            $q('order'),
            $q('group'),
            'DECIMAL(12,2)',
            'SQLite' === $this->connection->getPlatform()->getName() ? 'TEXT' : ('PostgreSQL' === $this->connection->getPlatform()->getName() ? 'TIMESTAMP(0)' : 'DATETIME'),
        ));

        foreach ([1 => 'ACME', 2 => 'Globex', 3 => 'Initech'] as $id => $name) {
            $this->connection->createQueryBuilder()->insert('client')->set('id', $id)->set('name', $name)->executeStatement();
        }
        foreach ([[1, 1, 'a', '10.00'], [2, 1, 'b', '20.50'], [3, 2, 'a', '5.25'], [4, 2, 'a', '7.75'], [5, 1, 'a', '100.00']] as [$id, $client, $group, $total]) {
            $this->connection->createQueryBuilder()->insert('order')
                ->set('id', $id)->set('client_id', $client)->set('group', $group)->set('total', $total, 'decimal')
                ->executeStatement();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropTables();
            $this->connection->close();
        }
    }

    public function testSelectWithJoinGroupByAndHaving(): void
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('c.name', 'COUNT(o.id) AS orders')
            ->from('client', 'c')
            ->join('order', 'o', 'o.client_id = c.id')
            ->where($qb->expr()->eq($qb->column('group', 'o'), 'a'))
            ->groupBy('c.name')
            ->having('COUNT(o.id) >= :min', ['min' => 2])
            ->orderBy('c.name')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame(['ACME', 'Globex'], array_column($rows, 'name'));
        self::assertEquals([2, 2], array_column($rows, 'orders'));
    }

    public function testLeftJoinKeepsClientsWithoutOrders(): void
    {
        $names = $this->connection->createQueryBuilder()
            ->select('c.name')
            ->from('client', 'c')
            ->leftJoin('order', 'o', 'o.client_id = c.id')
            ->where('o.id IS NULL')
            ->executeQuery()
            ->fetchFirstColumn();

        self::assertSame(['Initech'], $names);
    }

    public function testPaginationIncludingOffsetWithoutLimit(): void
    {
        $base = $this->connection->createQueryBuilder()->select('id')->from('order')->orderBy('id');

        self::assertEquals([2, 3], $base->limit(2)->offset(1)->executeQuery()->fetchFirstColumn());
        // MySQL et SQLite exigent un LIMIT : la plateforme le fournit
        self::assertEquals([4, 5], $base->offset(3)->executeQuery()->fetchFirstColumn());
        self::assertSame([], $base->limit(0)->executeQuery()->fetchFirstColumn());
    }

    public function testInWithListAndEmptyList(): void
    {
        $qb = $this->connection->createQueryBuilder();
        $base = $qb->select('id')->from('order')->orderBy('id', Order::Desc);

        self::assertEquals([5, 3, 1], $base->where($qb->expr()->in('id', [1, 3, 5]))->executeQuery()->fetchFirstColumn());
        self::assertSame([], $base->where($qb->expr()->in('id', []))->executeQuery()->fetchFirstColumn());
        self::assertCount(5, $base->where($qb->expr()->notIn('id', []))->executeQuery()->fetchFirstColumn());
    }

    // RG-01.5 : la valeur typée est convertie à l'écriture et comparée en decimal
    public function testTypedValues(): void
    {
        $qb = $this->connection->createQueryBuilder();
        $issued = new \DateTimeImmutable('2026-10-18 09:30:00');

        $updated = $qb->update('order')
            ->set('issued_at', $issued, 'datetime_immutable')
            ->where($qb->expr()->gte('total', '20.00', 'decimal'))
            ->executeStatement();
        self::assertSame(2, $updated);

        $raw = $qb->select('issued_at')->from('order')->where($qb->expr()->eq('id', 5))->executeQuery()->fetchOne();
        $read = $this->connection->convertToPhp($raw, 'datetime_immutable');
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('2026-10-18 09:30:00', $read->format('Y-m-d H:i:s'));
    }

    public function testUpdateWithExpressionAndDelete(): void
    {
        $qb = $this->connection->createQueryBuilder();

        self::assertSame(3, $qb->update('order')->set('group', 'z')->where($qb->expr()->eq('client_id', 1))->executeStatement());
        self::assertSame(1, $qb->update('client')->setExpression('id', 'id + 10')->where($qb->expr()->eq('name', 'Initech'))->executeStatement());
        self::assertSame(['ACME', 'Globex', 'Initech'], $qb->select('name')->from('client')->orderBy('id')->executeQuery()->fetchFirstColumn());

        self::assertSame(2, $qb->delete('order')->where($qb->expr()->eq('client_id', 2))->executeStatement());
        self::assertEquals(3, $qb->select('COUNT(*)')->from('order')->executeQuery()->fetchOne());
    }

    public function testInjectionAttemptStaysAValue(): void
    {
        $qb = $this->connection->createQueryBuilder();

        $count = $qb->select('COUNT(*)')->from('client')->where($qb->expr()->eq('name', "x' OR '1'='1"))->executeQuery()->fetchOne();

        self::assertEquals(0, $count);
    }

    private function dropTables(): void
    {
        $q = $this->connection->getPlatform()->quoteIdentifier(...);
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $q('order'));
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $q('client'));
    }
}
