<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Configuration;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Exception\ConnectionFailedException;
use Prism\Dbal\Exception\NoActiveTransactionException;
use Prism\Dbal\Exception\UnsupportedPlatformException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Dbal\Type\TypeRegistry;

final class DriverManagerTest extends TestCase
{
    // port 1 : connexion refusée immédiatement, aucun serveur nécessaire
    private const string UNREACHABLE = 'mysql://app:Sup3rS3cret@127.0.0.1:1/erp';

    /**
     * @return iterable<string, array{string, class-string<AbstractPlatform>, string}>
     */
    public static function platforms(): iterable
    {
        yield 'mysql' => ['mysql://u@db/erp?serverVersion=8.4', MySqlPlatform::class, '8.4.0'];
        yield 'mariadb from serverVersion' => ['mysql://u@db/erp?serverVersion=11.8.0-MariaDB', MariaDbPlatform::class, '11.8.0'];
        yield 'mariadb from scheme' => ['mariadb://u@db/erp?serverVersion=11.8', MariaDbPlatform::class, '11.8.0'];
        yield 'mariadb with replication prefix' => ['mysql://u@db/erp?serverVersion=5.5.5-11.8.3-MariaDB-ubu2404', MariaDbPlatform::class, '11.8.3'];
        yield 'postgresql' => ['postgresql://u@db/erp?serverVersion=16', PostgreSqlPlatform::class, '16.0.0'];
        yield 'sqlite' => ['sqlite:///:memory:?serverVersion=3.45.1', SqlitePlatform::class, '3.45.1'];
    }

    /**
     * @param class-string<AbstractPlatform> $class
     */
    #[DataProvider('platforms')]
    public function testServerVersionSelectsPlatformWithoutConnecting(string $url, string $class, string $version): void
    {
        $connection = DriverManager::fromUrl($url);

        self::assertInstanceOf($class, $connection->getPlatform());
        self::assertSame($version, $connection->getPlatform()->version);
        self::assertFalse($connection->isConnected());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedVersions(): iterable
    {
        yield 'mysql 5.7' => ['mysql://u@db/erp?serverVersion=5.7.44', 'MySQL 5.7.44 is not supported: Prism requires MySQL 8.0.0'];
        yield 'mariadb 11.4 (ADR 0003)' => ['mariadb://u@db/erp?serverVersion=11.4.2', 'MariaDB 11.4.2 is not supported: Prism requires MariaDB 11.8.0'];
        yield 'postgresql 14' => ['postgresql://u@db/erp?serverVersion=14', 'PostgreSQL 14.0.0 is not supported: Prism requires PostgreSQL 15.0.0'];
        yield 'sqlite 3.39' => ['sqlite:///:memory:?serverVersion=3.39.0', 'SQLite 3.39.0 is not supported: Prism requires SQLite 3.40.0'];
        yield 'unparsable' => ['mysql://u@db/erp?serverVersion=latest', 'Cannot read the MySQL server version from "latest"'];
    }

    #[DataProvider('unsupportedVersions')]
    public function testUnsupportedVersionIsRefusedBeforeConnecting(string $url, string $message): void
    {
        $this->expectException(UnsupportedPlatformException::class);
        $this->expectExceptionMessage($message);

        DriverManager::fromUrl($url);
    }

    public function testConnectionIsLazy(): void
    {
        $connection = DriverManager::fromUrl(self::UNREACHABLE . '?serverVersion=8.4');

        self::assertFalse($connection->isConnected());
        self::assertSame('erp', $connection->getDatabase());
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testConnectionFailureIsTypedAndHidesPassword(): void
    {
        $logger = new TestLogger();
        $connection = DriverManager::fromUrl(self::UNREACHABLE, new Configuration($logger));

        try {
            $connection->executeQuery('SELECT 1');
            self::fail('Expected ConnectionFailedException.');
        } catch (ConnectionFailedException $e) {
            self::assertStringStartsWith('Could not connect to MySql server 127.0.0.1:1 (database "erp")', $e->getMessage());
            self::assertStringNotContainsString('Sup3rS3cret', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        self::assertFalse($connection->isConnected());
        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringNotContainsString('Sup3rS3cret', print_r($logger->records, true));
    }

    // RG-01.2 : vérifié avant toute connexion
    public function testCommitAndRollbackWithoutTransactionFailBeforeConnecting(): void
    {
        $connection = DriverManager::fromUrl(self::UNREACHABLE . '?serverVersion=8.4');

        foreach (['commit' => $connection->commit(...), 'roll back' => $connection->rollBack(...)] as $operation => $call) {
            try {
                $call();
                self::fail('Expected NoActiveTransactionException.');
            } catch (NoActiveTransactionException $e) {
                self::assertSame(\sprintf('Cannot %s: no transaction is active. Call beginTransaction() first.', $operation), $e->getMessage());
            }
        }

        self::assertFalse($connection->isConnected());
    }

    public function testConfigurationIsImmutable(): void
    {
        $logger = new TestLogger();
        $base = new Configuration();
        $withLogger = $base->withLogger($logger);
        $withParams = $withLogger->withLogParameters(true);

        self::assertNotSame($base, $withLogger);
        self::assertFalse($base->logParameters);
        self::assertSame($logger, $withParams->logger);
        self::assertTrue($withParams->logParameters);
        self::assertFalse($withLogger->logParameters);

        $types = TypeRegistry::withDefaults();
        $utc = new \DateTimeZone('UTC');
        $full = $withParams->withTypes($types)->withTimezone($utc);
        self::assertSame($types, $full->types);
        self::assertSame($utc, $full->timezone);
        self::assertSame($logger, $full->logger);
        self::assertTrue($full->logParameters);
        self::assertNull($withParams->timezone);
        self::assertSame($utc, $full->withLogger(new TestLogger())->withLogParameters(false)->timezone);
        self::assertSame(TypeRegistry::withDefaults()->getNames(), $base->types->getNames());
    }
}
