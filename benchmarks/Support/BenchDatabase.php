<?php

declare(strict_types=1);

namespace Prism\Benchmarks\Support;

use Prism\Benchmarks\Entity\BenchItem;
use Prism\Dbal\Connection;
use Prism\Dbal\ConnectionParameters;
use Prism\Dbal\Driver;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tools\SchemaTool;

/**
 * Base dédiée à un benchmark : créée par sa méthode BeforeClassMethods, relue par chaque
 * itération (processus séparés), supprimée par AfterClassMethods.
 *
 * @internal
 */
final class BenchDatabase
{
    public static function connection(string $name): Connection
    {
        return DriverManager::fromUrl(self::url($name));
    }

    /**
     * Recrée la base, puis la table de BenchItem et $rows lignes (une transaction).
     */
    public static function create(string $name, int $rows): void
    {
        $admin = DriverManager::fromUrl(self::serverUrl());
        $quoted = $admin->getPlatform()->quoteIdentifier($name);
        $admin->executeStatement('DROP DATABASE IF EXISTS ' . $quoted . self::force());
        $admin->executeStatement('CREATE DATABASE ' . $quoted);
        $admin->close();

        $connection = self::connection($name);
        $target = new SchemaTool($connection->getPlatform())->fromMetadata([new AttributeDriver()->loadMetadata(BenchItem::class)]);
        $connection->createSchemaManager()->migrate(new Comparator($connection->getPlatform())->compare(new Schema(), $target));

        $connection->transactional(static function (Connection $connection) use ($rows): void {
            for ($i = 1; $i <= $rows; ++$i) {
                $connection->createQueryBuilder()->insert('bench_item')
                    ->set('code', \sprintf('IT-%06d', $i))
                    ->set('name', 'Item ' . $i)
                    ->set('price', \sprintf('%d.%02d', $i % 1000, $i % 100), 'decimal')
                    ->set('quantity', $i % 50)
                    ->set('active', 0 !== $i % 3, 'boolean')
                    ->set('created_at', new \DateTimeImmutable('2026-10-19 08:00:00'), 'datetime_immutable')
                    ->executeStatement();
            }
        });
        $connection->close();
    }

    public static function drop(string $name): void
    {
        $admin = DriverManager::fromUrl(self::serverUrl());
        $admin->executeStatement('DROP DATABASE IF EXISTS ' . $admin->getPlatform()->quoteIdentifier($name) . self::force());
        $admin->close();
    }

    // DATABASE_URL du Makefile, la base remplacée par celle du benchmark
    private static function url(string $name): string
    {
        return (string) preg_replace('#/[^/?]*(\?.*)?$#', '/' . $name . '$1', self::serverUrl(), 1);
    }

    private static function serverUrl(): string
    {
        $url = getenv('DATABASE_URL');
        if (!\is_string($url) || '' === $url) {
            throw new \LogicException('DATABASE_URL is not set: run the benchmarks with make bench.');
        }
        if (Driver::Sqlite === ConnectionParameters::fromUrl($url)->driver) {
            throw new \LogicException('The benchmarks need a database server (MySQL, MariaDB or PostgreSQL): every iteration runs in its own process.');
        }

        return $url;
    }

    private static function force(): string
    {
        return Driver::PostgreSql === ConnectionParameters::fromUrl(self::serverUrl())->driver ? ' WITH (FORCE)' : '';
    }
}
