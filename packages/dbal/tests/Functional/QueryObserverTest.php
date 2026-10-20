<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\QueryObserver;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * ADR 0008 : les observateurs voient les requêtes paramétrées réussies, sur les 4 bases.
 */
final class QueryObserverTest extends TestCase
{
    /** @var \ArrayObject<int, array{string, array<int|string, mixed>, float}> */
    private \ArrayObject $seen;

    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->seen = new \ArrayObject();
        $observer = new class($this->seen) implements QueryObserver {
            /**
             * @param \ArrayObject<int, array{string, array<int|string, mixed>, float}> $seen
             */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function queryExecuted(string $sql, array $params, float $durationMs): void
            {
                $this->seen->append([$sql, $params, $durationMs]);
            }
        };
        $this->connection = TestDatabase::connection(new Configuration()->withObservers([$observer]));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function testQueriesAndStatementsAreObservedWithTheirParameters(): void
    {
        $this->connection->executeQuery('SELECT :a AS a, :b AS b', ['a' => 1, 'b' => 'x']);
        $this->connection->executeStatement('SELECT ?', [7]);

        [$query, $statement] = $this->seen->getArrayCopy() + [null, null];
        self::assertCount(2, $this->seen);
        self::assertNotNull($query);
        self::assertNotNull($statement);
        self::assertSame(['SELECT :a AS a, :b AS b', [':a' => 1, ':b' => 'x']], [$query[0], $query[1]]);
        self::assertSame(['SELECT ?', [1 => 7]], [$statement[0], $statement[1]]);
        self::assertGreaterThanOrEqual(0.0, $query[2]);
    }

    public function testTransactionsAndFailedQueriesAreNotObserved(): void
    {
        $this->connection->transactional(static fn (Connection $c): mixed => $c->executeQuery('SELECT 1'));
        try {
            $this->connection->executeQuery('SELECT * FROM prism_missing_table');
            self::fail('Expected QueryFailedException.');
        } catch (QueryFailedException) {
        }

        self::assertSame(['SELECT 1'], array_column($this->seen->getArrayCopy(), 0));
    }

    public function testConfigurationKeepsItsObservers(): void
    {
        $configuration = new Configuration()->withObservers($observers = [self::createStub(QueryObserver::class)]);

        self::assertSame($observers, $configuration->withLogParameters(true)->withTimezone(null)->observers);
        self::assertSame([], new Configuration()->observers);
    }
}
