<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\Driver;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * Aller-retour PHP → base → PHP de chaque type natif, à travers une vraie colonne.
 *
 * Le DDL est écrit à la main par plateforme en attendant les types de colonnes de l'étape 5 ;
 * il fixe les déclarations que le DDL généré devra reproduire.
 */
final class TypeRoundTripTest extends TestCase
{
    /**
     * Déclaration par plateforme : mysql (MySQL et MariaDB, sauf surcharge), mariadb, pgsql, sqlite.
     */
    private const array COLUMNS = [
        'string' => ['mysql' => 'VARCHAR(255)', 'pgsql' => 'VARCHAR(255)', 'sqlite' => 'VARCHAR(255)'],
        'text' => ['mysql' => 'TEXT', 'pgsql' => 'TEXT', 'sqlite' => 'TEXT'],
        'integer' => ['mysql' => 'INTEGER', 'pgsql' => 'INTEGER', 'sqlite' => 'INTEGER'],
        'smallint' => ['mysql' => 'SMALLINT', 'pgsql' => 'SMALLINT', 'sqlite' => 'SMALLINT'],
        'bigint' => ['mysql' => 'BIGINT', 'pgsql' => 'BIGINT', 'sqlite' => 'BIGINT'],
        'boolean' => ['mysql' => 'TINYINT(1)', 'pgsql' => 'BOOLEAN', 'sqlite' => 'INTEGER'],
        // SQLite : NUMERIC convertirait '12.50' en REAL 12.5 et perdrait l'échelle, d'où TEXT
        'decimal' => ['mysql' => 'DECIMAL(20,2)', 'pgsql' => 'NUMERIC(20,2)', 'sqlite' => 'TEXT'],
        'float' => ['mysql' => 'DOUBLE', 'pgsql' => 'DOUBLE PRECISION', 'sqlite' => 'REAL'],
        'datetime_immutable' => ['mysql' => 'DATETIME', 'pgsql' => 'TIMESTAMP(0) WITHOUT TIME ZONE', 'sqlite' => 'TEXT'],
        'date_immutable' => ['mysql' => 'DATE', 'pgsql' => 'DATE', 'sqlite' => 'TEXT'],
        'time_immutable' => ['mysql' => 'TIME', 'pgsql' => 'TIME(0) WITHOUT TIME ZONE', 'sqlite' => 'TEXT'],
        'json' => ['mysql' => 'JSON', 'pgsql' => 'JSONB', 'sqlite' => 'TEXT'],
        'uuid' => ['mysql' => 'CHAR(36)', 'mariadb' => 'UUID', 'pgsql' => 'UUID', 'sqlite' => 'TEXT'],
        'binary' => ['mysql' => 'VARBINARY(255)', 'pgsql' => 'BYTEA', 'sqlite' => 'BLOB'],
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->connection = TestDatabase::connection();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS prism_types_test');
            $this->connection->close();
        }
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['string', 'Société générale 💶'];
        yield 'text' => ['text', str_repeat('Lorem ipsum ', 1000)];
        yield 'integer' => ['integer', -2147483648];
        yield 'smallint' => ['smallint', 32767];
        yield 'bigint' => ['bigint', \PHP_INT_MAX];
        yield 'boolean true' => ['boolean', true];
        yield 'boolean false' => ['boolean', false];
        // RG-01.5
        yield 'decimal beyond float precision' => ['decimal', '123456789012345678.90'];
        yield 'decimal negative' => ['decimal', '-0.05'];
        yield 'float' => ['float', 0.1 + 0.2];
        yield 'datetime' => ['datetime_immutable', new \DateTimeImmutable('2026-10-18 14:30:15')];
        yield 'date' => ['date_immutable', new \DateTimeImmutable('2026-02-28')];
        yield 'time' => ['time_immutable', new \DateTimeImmutable('1970-01-01 23:59:59')];
        yield 'json' => ['json', ['client' => 'Société', 'lines' => [['qty' => 2, 'price' => '9.90']], 'paid' => false]];
        yield 'uuid' => ['uuid', '0192f1e4-7b5c-7d3a-9f00-1a2b3c4d5e6f'];
        yield 'binary' => ['binary', "\x00\x01\xFE\xFFbytes\x00"];
    }

    #[DataProvider('values')]
    public function testRoundTrip(string $type, mixed $value): void
    {
        $this->insert($type, $value);

        $read = $this->connection->convertToPhp($this->read($type), $type);

        if ($value instanceof \DateTimeImmutable) {
            self::assertInstanceOf(\DateTimeImmutable::class, $read);
            self::assertSame($value->format('Y-m-d H:i:s'), $read->format('Y-m-d H:i:s'));

            return;
        }

        // assertEquals pour json : PostgreSQL JSONB réordonne les clés
        'json' === $type ? self::assertEquals($value, $read) : self::assertSame($value, $read);
    }

    #[DataProvider('values')]
    public function testNullRoundTrip(string $type): void
    {
        $this->insert($type, null);

        self::assertNull($this->connection->convertToPhp($this->read($type), $type));
    }

    // RG-01.6 : avec un fuseau de stockage UTC, la base contient l'heure UTC
    public function testDateTimeIsStoredInUtcWhenConfigured(): void
    {
        // nouvelle connexion configurée en UTC ; la table y est recréée (une base SQLite en mémoire
        // n'existe que dans sa connexion)
        $this->connection->close();
        $this->connection = $connection = TestDatabase::connection(new Configuration(timezone: new \DateTimeZone('UTC')));
        $this->createTable();
        $paris = new \DateTimeImmutable('2026-10-18 14:30:15', new \DateTimeZone('Europe/Paris'));

        $connection->executeStatement('INSERT INTO prism_types_test (id, c_datetime_immutable) VALUES (1, ?)', [$paris], ['datetime_immutable']);

        $raw = $connection->executeQuery('SELECT c_datetime_immutable FROM prism_types_test WHERE id = 1')->fetchOne();
        self::assertSame('2026-10-18 12:30:15', $raw);

        $read = $connection->convertToPhp($raw, 'datetime_immutable');
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('UTC', $read->getTimezone()->getName());
        self::assertSame($paris->getTimestamp(), $read->getTimestamp());
    }

    // RG-01.5 : un decimal relu n'est jamais un float, quelle que soit la plateforme
    public function testDecimalIsReadAsStringByTheDriver(): void
    {
        $this->insert('decimal', '12.50');

        self::assertSame('12.50', $this->read('decimal'));
    }

    private function insert(string $type, mixed $value): void
    {
        $this->connection->executeStatement(
            \sprintf('INSERT INTO prism_types_test (id, %s) VALUES (?, ?)', $this->column($type)),
            [1, $value],
            [1 => $type],
        );
    }

    private function read(string $type): mixed
    {
        return $this->connection->executeQuery(\sprintf('SELECT %s FROM prism_types_test WHERE id = 1', $this->column($type)))->fetchOne();
    }

    private function column(string $type): string
    {
        return $this->connection->getPlatform()->quoteIdentifier('c_' . $type);
    }

    private function createTable(): void
    {
        $platform = $this->connection->getPlatform();
        $key = match (TestDatabase::driver()) {
            Driver::MySql => $platform instanceof MariaDbPlatform ? 'mariadb' : 'mysql',
            Driver::PostgreSql => 'pgsql',
            Driver::Sqlite => 'sqlite',
        };

        $columns = [];
        foreach (self::COLUMNS as $type => $declarations) {
            $columns[] = $this->column($type) . ' ' . ($declarations[$key] ?? $declarations['mysql']) . ' NULL';
        }

        $this->connection->executeStatement('DROP TABLE IF EXISTS prism_types_test');
        $this->connection->executeStatement(\sprintf('CREATE TABLE prism_types_test (id INTEGER NOT NULL PRIMARY KEY, %s)', implode(', ', $columns)));
    }
}
