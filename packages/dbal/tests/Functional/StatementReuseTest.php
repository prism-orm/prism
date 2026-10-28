<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Driver;
use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * Une écriture répétée (INSERT, UPDATE, DELETE au même SQL) est préparée une fois, puis
 * réexécutée avec ses nouvelles valeurs : un flush de 1 000 UPDATE ne fait plus 1 000
 * préparations (ADR 0021, benchmark contre Doctrine). Observé par la base sous MySQL, MariaDB
 * et PostgreSQL ; SQLite n'expose rien, seul le résultat y est vérifié.
 */
final class StatementReuseTest extends TestCase
{
    private const string INSERT = 'INSERT INTO prism_reuse_test (id, label) VALUES (:id, :label)';

    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->connection = TestDatabase::connection();
        $this->connection->executeStatement('DROP TABLE IF EXISTS prism_reuse_test');
        $this->connection->executeStatement('CREATE TABLE prism_reuse_test (id INTEGER NOT NULL PRIMARY KEY, label VARCHAR(20) NOT NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS prism_reuse_test');
            $this->connection->close();
        }
    }

    private function rows(): int
    {
        $count = $this->connection->executeQuery('SELECT COUNT(*) FROM prism_reuse_test')->fetchOne();
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * Les préparations de la session, quand la base les expose : MySQL et MariaDB comptent les
     * préparations (la requête de lecture comprise), PostgreSQL liste celles qui vivent encore.
     */
    private function prepared(): ?int
    {
        $count = match (TestDatabase::driver()) {
            Driver::MySql => $this->connection->executeQuery("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetchAssociative()['Value'] ?? null,
            Driver::PostgreSql => $this->connection->executeQuery("SELECT COUNT(*) FROM pg_prepared_statements WHERE statement LIKE 'INSERT INTO prism_reuse_test%'")->fetchOne(),
            default => null,
        };

        return is_numeric($count) ? (int) $count : null;
    }

    public function testARepeatedWriteIsPreparedOnce(): void
    {
        $before = $this->prepared();
        for ($i = 1; $i <= 200; ++$i) {
            self::assertSame(1, $this->connection->executeStatement(self::INSERT, ['id' => $i, 'label' => 'row ' . $i]));
        }
        $after = $this->prepared();

        self::assertSame(200, $this->rows());
        self::assertSame('row 137', $this->connection->executeQuery('SELECT label FROM prism_reuse_test WHERE id = :id', ['id' => 137])->fetchOne(), 'each execution binds its own values');
        match (TestDatabase::driver()) {
            // l'INSERT une fois, plus la lecture du compteur elle-même
            Driver::MySql => self::assertSame(2, (int) $after - (int) $before),
            // l'INSERT reste préparé, prêt pour la prochaine écriture
            Driver::PostgreSql => self::assertSame(1, $after),
            default => self::assertNull($after),
        };
    }

    // une écriture refusée ne laisse pas une requête inutilisable : la même réussit ensuite
    public function testAFailedWriteDoesNotBreakTheNextOne(): void
    {
        $this->connection->executeStatement(self::INSERT, ['id' => 1, 'label' => 'first']);
        try {
            $this->connection->executeStatement(self::INSERT, ['id' => 1, 'label' => 'duplicate']);
            self::fail('A duplicate key was accepted.');
        } catch (QueryFailedException) {
        }

        self::assertSame(1, $this->connection->executeStatement(self::INSERT, ['id' => 2, 'label' => 'second']));
        self::assertSame(2, $this->rows());
    }

    // fermée puis rouverte (mode worker), la connexion prépare à nouveau
    public function testClosedConnectionPreparesAgain(): void
    {
        if (':memory:' === $this->connection->getDatabase()) {
            self::markTestSkipped('An in-memory SQLite database dies with its connection.');
        }
        $this->connection->executeStatement(self::INSERT, ['id' => 1, 'label' => 'before']);
        $this->connection->close();

        self::assertSame(1, $this->connection->executeStatement(self::INSERT, ['id' => 2, 'label' => 'after']));
        self::assertSame(2, $this->rows());
    }

    // au-delà de la limite du cache, les plus anciennes sont libérées ; toutes restent justes
    public function testManyDistinctWritesStayCorrect(): void
    {
        for ($i = 1; $i <= 100; ++$i) {
            $this->connection->executeStatement(\sprintf('INSERT INTO prism_reuse_test (id, label) VALUES (:id, \'%s\')', 'l' . $i), ['id' => $i]);
        }
        $this->connection->executeStatement(\sprintf('INSERT INTO prism_reuse_test (id, label) VALUES (:id, \'%s\')', 'l1'), ['id' => 101]);

        self::assertSame(101, $this->rows());
        self::assertSame(1, $this->connection->executeStatement('UPDATE prism_reuse_test SET label = :label WHERE id = :id', ['label' => 'x', 'id' => 101]));
    }
}
