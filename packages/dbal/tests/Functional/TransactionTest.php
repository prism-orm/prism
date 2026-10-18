<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\Driver;
use Prism\Dbal\Exception\AbortedTransactionException;
use Prism\Dbal\Exception\NoActiveTransactionException;
use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;

final class TransactionTest extends TestCase
{
    private Connection $connection;

    private TestLogger $logger;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->logger = new TestLogger();
        $this->connection = TestDatabase::connection(new Configuration($this->logger));
        $this->connection->executeStatement('DROP TABLE IF EXISTS prism_tx_test');
        $this->connection->executeStatement('CREATE TABLE prism_tx_test (id INTEGER NOT NULL PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->executeStatement('DROP TABLE IF EXISTS prism_tx_test');
        $this->connection->executeStatement('DROP TABLE IF EXISTS prism_tx_ddl');
        $this->connection->close();
    }

    // RG-01.1 : critère de sortie « transactions imbriquées sur 3 niveaux avec rollback intermédiaire »
    public function testThreeLevelsWithIntermediateRollback(): void
    {
        $this->connection->beginTransaction();
        $this->insert(1);

        $this->connection->beginTransaction();
        $this->insert(2);

        $this->connection->beginTransaction();
        $this->insert(3);
        self::assertSame(3, $this->connection->getTransactionNestingLevel());
        $this->connection->commit();

        // annule le niveau 2, donc aussi le niveau 3 qu'il contenait
        $this->connection->rollBack();
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame([1], $this->ids());

        $this->insert(4);
        $this->connection->commit();

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame([1, 4], $this->committedIds());
        self::assertSame(
            ['SAVEPOINT PRISM_SAVEPOINT_2', 'SAVEPOINT PRISM_SAVEPOINT_3', 'RELEASE SAVEPOINT PRISM_SAVEPOINT_3', 'ROLLBACK TO SAVEPOINT PRISM_SAVEPOINT_2'],
            array_values(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_contains($sql, 'SAVEPOINT'))),
        );
    }

    public function testRollbackOfOuterTransactionDiscardsEverything(): void
    {
        $this->connection->beginTransaction();
        $this->insert(1);
        $this->connection->beginTransaction();
        $this->insert(2);
        $this->connection->commit();
        $this->connection->rollBack();

        self::assertSame([], $this->ids());
    }

    // RG-01.2
    public function testCommitAndRollbackWithoutTransactionAreRefused(): void
    {
        $this->connection->beginTransaction();
        $this->connection->commit();

        $this->expectException(NoActiveTransactionException::class);
        $this->connection->commit();
    }

    public function testTransactionalCommitsAndReturnsTheCallbackResult(): void
    {
        $result = $this->connection->transactional(function (Connection $c): int {
            $this->insert(1, $c);

            return $c->getTransactionNestingLevel();
        });

        self::assertSame(1, $result);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame([1], $this->committedIds());
    }

    public function testTransactionalRollsBackAndRethrows(): void
    {
        try {
            $this->connection->transactional(function (Connection $c): void {
                $this->insert(1, $c);
                $this->failBusinessRule();
            });
            self::fail('Expected DomainException.');
        } catch (\DomainException $e) {
            self::assertSame('business rule', $e->getMessage());
        }

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame([], $this->ids());
    }

    // RG-01.1 : l'appelant décide de propager ; ici il absorbe l'échec du niveau interne
    public function testCallerMayKeepOuterTransactionAfterInnerFailure(): void
    {
        $this->connection->transactional(function (Connection $c): void {
            $this->insert(1, $c);

            try {
                $c->transactional(function (Connection $inner): void {
                    $this->insert(2, $inner);

                    throw new \DomainException('inner');
                });
            } catch (\DomainException) {
            }

            $this->insert(3, $c);
        });

        self::assertSame([1, 3], $this->committedIds());
    }

    // une requête en échec dans un savepoint se répare par ROLLBACK TO, y compris sous PostgreSQL
    public function testFailedStatementInSavepointIsRecoverable(): void
    {
        $this->connection->beginTransaction();
        $this->insert(1);

        $this->connection->beginTransaction();
        try {
            $this->insert(1);
            self::fail('Expected a duplicate key error.');
        } catch (QueryFailedException) {
        }
        $this->connection->rollBack();

        $this->insert(2);
        $this->connection->commit();

        self::assertSame([1, 2], $this->committedIds());
    }

    // Divergence documentée : une erreur au niveau 1 invalide la transaction sous PostgreSQL seulement
    public function testFailedStatementAtTopLevel(): void
    {
        $this->connection->beginTransaction();
        $this->insert(1);
        try {
            $this->insert(1);
            self::fail('Expected a duplicate key error.');
        } catch (QueryFailedException) {
        }

        if (Driver::PostgreSql === TestDatabase::driver()) {
            // le serveur ferait un ROLLBACK silencieux à la place du COMMIT : Prism le signale
            try {
                $this->connection->commit();
                self::fail('Expected AbortedTransactionException.');
            } catch (AbortedTransactionException) {
            }
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
            self::assertSame([], $this->ids());

            return;
        }

        $this->connection->commit();
        self::assertSame([1], $this->committedIds());
    }

    // Divergence documentée : sous MySQL et MariaDB, un DDL valide la transaction en cours
    public function testDdlInsideTransaction(): void
    {
        $this->connection->beginTransaction();
        $this->insert(1);
        $this->connection->executeStatement('CREATE TABLE prism_tx_ddl (id INTEGER NOT NULL PRIMARY KEY)');

        if (Driver::MySql === TestDatabase::driver()) {
            try {
                $this->connection->rollBack();
                self::fail('Expected NoActiveTransactionException.');
            } catch (NoActiveTransactionException $e) {
                self::assertStringContainsString('committed the transaction implicitly', $e->getMessage());
            }
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
            self::assertSame([1], $this->ids());

            return;
        }

        // PostgreSQL et SQLite : le DDL est transactionnel
        $this->connection->rollBack();
        self::assertSame([], $this->ids());
        $this->expectException(QueryFailedException::class);
        $this->connection->executeQuery('SELECT id FROM prism_tx_ddl');
    }

    public function testCommitAfterImplicitDdlCommitWarnsInsteadOfFailing(): void
    {
        if (Driver::MySql !== TestDatabase::driver()) {
            self::markTestSkipped('Implicit DDL commit only exists on MySQL and MariaDB.');
        }

        $this->connection->beginTransaction();
        $this->insert(1);
        $this->connection->executeStatement('CREATE TABLE prism_tx_ddl (id INTEGER NOT NULL PRIMARY KEY)');
        $this->connection->commit();

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame([1], $this->committedIds());
        self::assertNotEmpty(array_filter($this->logger->records, static fn (array $r): bool => 'warning' === $r['level']));
    }

    public function testTransactionsAreLogged(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->connection->rollBack();
        $this->connection->commit();

        $messages = array_column($this->logger->records, 'message');
        self::assertContains('Beginning transaction', $messages);
        self::assertContains('Committing transaction', $messages);
        self::assertSame(2, $this->logger->withSql('ROLLBACK TO SAVEPOINT PRISM_SAVEPOINT_2')[0]['context']['level']);
    }

    /**
     * Lecture depuis une autre connexion : seul ce qui est réellement commité est visible.
     * Une base SQLite en mémoire n'existe que dans sa connexion : on relit hors transaction.
     *
     * @return list<int>
     */
    private function committedIds(): array
    {
        if (':memory:' === $this->connection->getDatabase()) {
            self::assertFalse($this->connection->isTransactionActive());

            return $this->ids();
        }

        return $this->ids(TestDatabase::connection());
    }

    // hors du callback pour que l'échec ne soit pas connu statiquement
    private function failBusinessRule(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \DomainException('business rule');
        }
    }

    private function insert(int $id, ?Connection $connection = null): void
    {
        ($connection ?? $this->connection)->executeStatement('INSERT INTO prism_tx_test (id) VALUES (?)', [$id]);
    }

    /**
     * @return list<int>
     */
    private function ids(?Connection $connection = null): array
    {
        $ids = ($connection ?? $this->connection)->executeQuery('SELECT id FROM prism_tx_test ORDER BY id')->fetchFirstColumn();

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : -1, $ids);
    }
}
