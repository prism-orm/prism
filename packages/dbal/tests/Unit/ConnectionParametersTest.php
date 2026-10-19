<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\ConnectionParameters;
use Prism\Dbal\Driver;
use Prism\Dbal\Exception\InvalidUrlException;

// RG-00.2 : URL au format Symfony
final class ConnectionParametersTest extends TestCase
{
    public function testMysqlUrl(): void
    {
        $p = ConnectionParameters::fromUrl('mysql://app:secret@db.local:3307/erp?serverVersion=8.4');

        self::assertSame(Driver::MySql, $p->driver);
        self::assertFalse($p->mariaDb);
        self::assertSame('db.local', $p->host);
        self::assertSame(3307, $p->port);
        self::assertSame('app', $p->user);
        self::assertSame('secret', $p->getPassword());
        self::assertSame('erp', $p->database);
        self::assertSame('8.4', $p->serverVersion);
        self::assertSame('mysql:host=db.local;port=3307;dbname=erp;charset=utf8mb4', $p->getDsn());
    }

    public function testMariaDbScheme(): void
    {
        $p = ConnectionParameters::fromUrl('mariadb://app@db/erp');

        self::assertSame(Driver::MySql, $p->driver);
        self::assertTrue($p->mariaDb);
        self::assertSame(3306, $p->port);
        self::assertNull($p->getPassword());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function postgresSchemes(): iterable
    {
        yield 'postgresql' => ['postgresql'];
        yield 'postgres' => ['postgres'];
        yield 'pgsql' => ['pgsql'];
        yield 'uppercase' => ['PostgreSQL'];
    }

    #[DataProvider('postgresSchemes')]
    public function testPostgresSchemes(string $scheme): void
    {
        $p = ConnectionParameters::fromUrl($scheme . '://app:pw@pg/erp?serverVersion=16');

        self::assertSame(Driver::PostgreSql, $p->driver);
        self::assertSame('pgsql:host=pg;port=5432;dbname=erp', $p->getDsn());
    }

    public function testUrlEncodedCredentialsAreDecoded(): void
    {
        $p = ConnectionParameters::fromUrl('mysql://us%40er:p%40ss%3Aw%2Fd@db/erp');

        self::assertSame('us@er', $p->user);
        self::assertSame('p@ss:w/d', $p->getPassword());
    }

    public function testDatabaseIsOptionalOnServers(): void
    {
        $p = ConnectionParameters::fromUrl('mysql://root:root@db');

        self::assertNull($p->database);
        self::assertSame('mysql:host=db;port=3306;charset=utf8mb4', $p->getDsn());
    }

    public function testCharsetOption(): void
    {
        self::assertStringEndsWith(';charset=latin1', ConnectionParameters::fromUrl('mysql://u@db/erp?charset=latin1')->getDsn());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sqliteUrls(): iterable
    {
        yield 'memory' => ['sqlite:///:memory:', ':memory:'];
        yield 'relative' => ['sqlite:///var/app.db', 'var/app.db'];
        yield 'absolute' => ['sqlite:////srv/app.db', '/srv/app.db'];
        yield 'with version' => ['sqlite:///:memory:?serverVersion=3.45', ':memory:'];
    }

    #[DataProvider('sqliteUrls')]
    public function testSqliteUrls(string $url, string $path): void
    {
        $p = ConnectionParameters::fromUrl($url);

        self::assertSame(Driver::Sqlite, $p->driver);
        self::assertSame($path, $p->database);
        self::assertSame('sqlite:' . $path, $p->getDsn());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'no scheme' => ['db.local/erp', 'unsupported scheme ""'];
        yield 'unknown scheme' => ['oracle://u@db/erp', 'unsupported scheme "oracle"'];
        yield 'missing host' => ['mysql:///erp', 'missing host'];
        yield 'dsn injection in database' => ['mysql://u@db/erp;unix_socket=x', 'must not contain'];
        yield 'unknown option' => ['mysql://u@db/erp?sslmode=require', 'unknown option "sslmode"'];
        yield 'empty option' => ['mysql://u@db/erp?serverVersion=', 'must be a non-empty string'];
        yield 'sqlite without path' => ['sqlite://', 'expected sqlite:///:memory:'];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejected(string $url, string $reason): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage($reason);

        ConnectionParameters::fromUrl($url);
    }

    public function testPasswordNeverAppearsInMessagesOrDumps(): void
    {
        try {
            ConnectionParameters::fromUrl('mysql://app:Sup3rS3cret@db/erp?sslmode=x');
            self::fail('Expected InvalidUrlException.');
        } catch (InvalidUrlException $e) {
            self::assertStringNotContainsString('Sup3rS3cret', $e->getMessage());
            self::assertStringContainsString('mysql://app:***@db/erp', $e->getMessage());
        }

        $p = ConnectionParameters::fromUrl('mysql://app:Sup3rS3cret@db/erp');
        self::assertStringNotContainsString('Sup3rS3cret', print_r($p, true));
        self::assertStringNotContainsString('Sup3rS3cret', $p->describe());
        self::assertStringNotContainsString('Sup3rS3cret', $p->getDsn());
    }

    public function testDescribe(): void
    {
        self::assertSame('MySql server db:3306 (database "erp")', ConnectionParameters::fromUrl('mysql://u:p@db/erp')->describe());
        self::assertSame('MariaDB server db:3306', ConnectionParameters::fromUrl('mariadb://u:p@db')->describe());
        self::assertSame('SQLite database ":memory:"', ConnectionParameters::fromUrl('sqlite:///:memory:')->describe());
    }
}
