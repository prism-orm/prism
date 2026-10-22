<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;

final class ConnectionTest extends TestCase
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
        $this->connection->executeStatement('DROP TABLE IF EXISTS prism_connection_test');
        $this->connection->executeStatement('CREATE TABLE prism_connection_test (id INTEGER NOT NULL PRIMARY KEY, label VARCHAR(50) NOT NULL, qty INTEGER NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS prism_connection_test');
            $this->connection->close();
        }
    }

    // RG-01.4 : valeurs liées, jamais concaténées
    public function testPositionalParameters(): void
    {
        $inserted = $this->connection->executeStatement('INSERT INTO prism_connection_test (id, label, qty) VALUES (?, ?, ?)', [1, "O'Reilly; DROP TABLE x", null]);

        self::assertSame(1, $inserted);
        self::assertSame(
            ['id' => 1, 'label' => "O'Reilly; DROP TABLE x", 'qty' => null],
            $this->normalize($this->connection->executeQuery('SELECT id, label, qty FROM prism_connection_test WHERE id = ?', [1])->fetchAssociative()),
        );
    }

    public function testNamedParametersWithAndWithoutColon(): void
    {
        $this->connection->executeStatement('INSERT INTO prism_connection_test (id, label, qty) VALUES (:id, :label, :qty)', ['id' => 1, ':label' => 'a', 'qty' => 5]);

        self::assertSame('a', $this->connection->executeQuery('SELECT label FROM prism_connection_test WHERE id = :id AND qty = :qty', ['id' => 1, 'qty' => 5])->fetchOne());
    }

    public function testExplicitTypes(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO prism_connection_test (id, label, qty) VALUES (?, ?, ?)',
            ['7', 'typed', '12'],
            [0 => ParameterType::Integer, 2 => ParameterType::Integer],
        );

        self::assertEquals(12, $this->connection->executeQuery('SELECT qty FROM prism_connection_test WHERE id = ?', [7])->fetchOne());
    }

    public function testFetchMethods(): void
    {
        $this->seed();

        $result = $this->connection->executeQuery('SELECT id, label FROM prism_connection_test ORDER BY id');
        self::assertSame(2, $result->columnCount());
        self::assertSame(['a', 'b', 'c'], array_column($result->fetchAllAssociative(), 'label'));

        self::assertEquals([1, 2, 3], $this->connection->executeQuery('SELECT id FROM prism_connection_test ORDER BY id')->fetchFirstColumn());

        $labels = [];
        foreach ($this->connection->executeQuery('SELECT label FROM prism_connection_test ORDER BY id')->iterateAssociative() as $row) {
            $labels[] = $row['label'];
        }
        self::assertSame(['a', 'b', 'c'], $labels);

        $numeric = $this->connection->executeQuery('SELECT label, id FROM prism_connection_test WHERE id = 2')->fetchNumeric();
        self::assertIsArray($numeric);
        self::assertSame('b', $numeric[0]);
    }

    public function testFetchOneDistinguishesNoRowFromNullColumn(): void
    {
        $this->connection->executeStatement('INSERT INTO prism_connection_test (id, label, qty) VALUES (1, ?, NULL)', ['a']);

        self::assertNull($this->connection->executeQuery('SELECT qty FROM prism_connection_test WHERE id = 1')->fetchOne());
        self::assertFalse($this->connection->executeQuery('SELECT qty FROM prism_connection_test WHERE id = 99')->fetchOne());
        self::assertFalse($this->connection->executeQuery('SELECT qty FROM prism_connection_test WHERE id = 99')->fetchAssociative());
        self::assertSame([], $this->connection->executeQuery('SELECT qty FROM prism_connection_test WHERE id = 99')->fetchAllAssociative());
    }

    // MySQL compte par défaut les lignes modifiées ; Prism aligne sur les lignes trouvées (base de RG-03.9)
    public function testRowCountCountsMatchedRowsOnEveryPlatform(): void
    {
        $this->seed();

        self::assertSame(1, $this->connection->executeStatement('UPDATE prism_connection_test SET label = ? WHERE id = ?', ['a', 1]));
        self::assertSame(3, $this->connection->executeStatement('UPDATE prism_connection_test SET qty = qty'));
        self::assertSame(0, $this->connection->executeStatement('DELETE FROM prism_connection_test WHERE id = ?', [99]));
    }

    public function testFailedQueryIsTypedAndKeepsSql(): void
    {
        try {
            $this->connection->executeQuery('SELECT missing_column FROM prism_connection_test WHERE id = ?', [1]);
            self::fail('Expected QueryFailedException.');
        } catch (QueryFailedException $e) {
            self::assertSame('SELECT missing_column FROM prism_connection_test WHERE id = ?', $e->sql);
            self::assertNotNull($e->sqlState);
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        $errors = array_values(array_filter($this->logger->records, static fn (array $r): bool => 'error' === $r['level']));
        self::assertCount(1, $errors);
        self::assertSame('SELECT missing_column FROM prism_connection_test WHERE id = ?', $errors[0]['context']['sql']);
    }

    public function testUnsupportedParametersAreRejectedBeforeReachingTheServer(): void
    {
        foreach ([[[1, 'x' => 2], 'Cannot mix positional and named parameters'], [[new \stdClass()], 'values of type stdClass are not supported']] as [$params, $message]) {
            try {
                $this->connection->executeQuery('SELECT 1', $params);
                self::fail('Expected InvalidParameterException.');
            } catch (InvalidParameterException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testQueriesAreLoggedWithDurationAndTypesButNotValues(): void
    {
        $this->connection->executeQuery('SELECT label FROM prism_connection_test WHERE id = ? AND label = ?', [1, 'Sup3rS3cret']);

        $records = $this->logger->withSql('SELECT label FROM prism_connection_test WHERE id = ? AND label = ?');
        self::assertCount(1, $records);
        self::assertSame('debug', $records[0]['level']);
        self::assertIsFloat($records[0]['context']['duration_ms']);
        self::assertGreaterThanOrEqual(0, $records[0]['context']['duration_ms']);
        self::assertSame(['Integer', 'String'], $records[0]['context']['types']);
        self::assertArrayNotHasKey('params', $records[0]['context']);
        self::assertStringNotContainsString('Sup3rS3cret', print_r($this->logger->records, true));
    }

    public function testParameterValuesAreLoggedOnlyWhenEnabled(): void
    {
        $logger = new TestLogger();
        $connection = TestDatabase::connection(new Configuration($logger)->withLogParameters(true));

        $connection->executeQuery('SELECT ?', ['visible']);

        self::assertSame(['visible'], $logger->withSql('SELECT ?')[0]['context']['params']);
    }

    public function testConnectionIsOpenedOnFirstQueryAndCanBeReopened(): void
    {
        $connection = TestDatabase::connection();
        self::assertFalse($connection->isConnected());

        self::assertEquals(1, $connection->executeQuery('SELECT 1')->fetchOne());
        self::assertTrue($connection->isConnected());

        $connection->close();
        self::assertFalse($connection->isConnected());
        self::assertEquals(2, $connection->executeQuery('SELECT 2')->fetchOne());
    }

    // RG-07.5 : le bundle teste la connexion d'un worker avant de la réutiliser
    public function testPingAnswersOnlyForAnOpenConnection(): void
    {
        $connection = TestDatabase::connection();

        self::assertFalse($connection->ping(), 'closed: nothing to test, and ping() does not open it');
        self::assertFalse($connection->isConnected());
        $connection->connect();
        self::assertTrue($connection->ping());
        $connection->beginTransaction();
        self::assertTrue($connection->ping(), 'it does not disturb the transaction');
        $connection->rollBack();
    }

    // RG-07.5 : une connexion tuée par le serveur (redémarrage, wait_timeout) se reconnecte après close()
    public function testPingDetectsAConnectionLostOnTheServer(): void
    {
        $platform = $this->connection->getPlatform()->getName();
        if ('SQLite' === $platform) {
            self::markTestSkipped('An SQLite connection cannot be lost.');
        }
        $victim = TestDatabase::connection();
        $id = $victim->executeQuery('PostgreSQL' === $platform ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchOne();
        self::assertTrue(is_numeric($id));
        $id = (int) $id;

        'PostgreSQL' === $platform
            ? $this->connection->executeQuery('SELECT pg_terminate_backend(:pid)', ['pid' => $id])
            : $this->connection->executeStatement(\sprintf('KILL %d', $id));
        usleep(100_000);

        self::assertFalse($victim->ping());
        $victim->close();
        self::assertEquals(3, $victim->executeQuery('SELECT 3')->fetchOne(), 'reconnected on the next query');
        self::assertTrue($victim->ping());
        $victim->close();
    }

    private function seed(): void
    {
        foreach ([1 => 'a', 2 => 'b', 3 => 'c'] as $id => $label) {
            $this->connection->executeStatement('INSERT INTO prism_connection_test (id, label, qty) VALUES (?, ?, ?)', [$id, $label, $id * 10]);
        }
    }

    /**
     * Les entiers reviennent en int sur les 4 bases ; le cast protège seulement des drivers anciens.
     *
     * @param array<string, mixed>|false $row
     *
     * @return array<string, mixed>|false
     */
    private function normalize(array|false $row): array|false
    {
        if (false === $row) {
            return false;
        }

        return ['id' => is_numeric($row['id']) ? (int) $row['id'] : $row['id']] + $row;
    }
}
