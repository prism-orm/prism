<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\QueryGuard;
use Prism\Dbal\QueryObserver;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * ADR 0014 : les gardes voient chaque requête paramétrée avant son exécution, sur les 4 bases ;
 * une garde qui lève une exception l'empêche.
 */
final class QueryGuardTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $seen;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $executed;

    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->seen = new \ArrayObject();
        $this->executed = new \ArrayObject();
        $this->connection = TestDatabase::connection(new Configuration()->withGuards([self::guard($this->seen, 'first'), self::guard($this->seen, 'second')])->withObservers([self::observer($this->executed)]));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    /**
     * @param \ArrayObject<int, string> $seen
     */
    private static function guard(\ArrayObject $seen, string $name): QueryGuard
    {
        return new class($seen, $name) implements QueryGuard {
            /**
             * @param \ArrayObject<int, string> $seen
             */
            public function __construct(private readonly \ArrayObject $seen, private readonly string $name)
            {
            }

            public function guard(string $sql): void
            {
                $this->seen->append($this->name . ': ' . $sql);
                if (str_contains($sql, 'refused')) {
                    throw new \RuntimeException('Refused by ' . $this->name);
                }
            }
        };
    }

    /**
     * @param \ArrayObject<int, string> $executed
     */
    private static function observer(\ArrayObject $executed): QueryObserver
    {
        return new class($executed) implements QueryObserver {
            /**
             * @param \ArrayObject<int, string> $executed
             */
            public function __construct(private readonly \ArrayObject $executed)
            {
            }

            public function queryExecuted(string $sql, array $params, float $durationMs): void
            {
                $this->executed->append($sql);
            }
        };
    }

    public function testGuardsSeeEachQueryInOrderBeforeItRuns(): void
    {
        $this->connection->executeQuery('SELECT 1');
        $this->connection->executeStatement('SELECT ?', [2]);

        self::assertSame(['first: SELECT 1', 'second: SELECT 1', 'first: SELECT ?', 'second: SELECT ?'], $this->seen->getArrayCopy());
        self::assertSame(['SELECT 1', 'SELECT ?'], $this->executed->getArrayCopy());
    }

    // la première garde qui refuse arrête tout : ni les suivantes ni la base ne voient la requête
    public function testRefusedQueryIsNeverSent(): void
    {
        try {
            $this->connection->executeQuery("SELECT 'refused'");
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Refused by first', $e->getMessage());
        }

        self::assertSame(["first: SELECT 'refused'"], $this->seen->getArrayCopy());
        self::assertSame([], $this->executed->getArrayCopy());
    }

    // comme pour les observateurs (ADR 0008), les requêtes de transaction ne sont pas gardées
    public function testTransactionsAreNotGuarded(): void
    {
        $this->connection->transactional(static fn (Connection $c): mixed => $c->executeQuery('SELECT 1'));

        self::assertSame(['first: SELECT 1', 'second: SELECT 1'], $this->seen->getArrayCopy());
    }

    public function testConfigurationKeepsItsGuards(): void
    {
        $configuration = new Configuration()->withGuards($guards = [self::createStub(QueryGuard::class)]);

        self::assertSame($guards, $configuration->withLogParameters(true)->withObservers([])->withTimezone(null)->guards);
        self::assertSame([], new Configuration()->guards);
    }
}
