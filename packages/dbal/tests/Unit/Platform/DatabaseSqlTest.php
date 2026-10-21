<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;

/**
 * db:database:create et drop : le SQL de chaque plateforme (RG-01.3 : un nom réservé, cité), et
 * la connexion au serveur sans la base.
 */
final class DatabaseSqlTest extends TestCase
{
    public function testCreateDropAndListDatabases(): void
    {
        foreach ([[new MySqlPlatform('8.4'), '`order`'], [new MariaDbPlatform('11.8'), '`order`'], [new PostgreSqlPlatform('16'), '"order"']] as [$platform, $quoted]) {
            self::assertSame('CREATE DATABASE ' . $quoted, $platform->getCreateDatabaseSql('order'));
            self::assertSame('DROP DATABASE ' . $quoted, $platform->getDropDatabaseSql('order'));
            self::assertStringStartsWith('SELECT ', $platform->getListDatabasesSql());
        }
    }

    public function testSqliteHasNoNamedDatabase(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('named databases (a SQLite database is a file)');

        new SqlitePlatform('3.46')->getCreateDatabaseSql('x');
    }

    public function testConnectionWithoutTheDatabase(): void
    {
        self::assertNull(DriverManager::fromUrl('mysql://u:p@db:3306/shop?serverVersion=8.4')->withoutDatabase()->getDatabase());
        self::assertSame('postgres', DriverManager::fromUrl('postgresql://u:p@db:5432/shop?serverVersion=16')->withoutDatabase()->getDatabase(), 'PostgreSQL always connects to a database');
        self::assertSame('/tmp/shop.db', DriverManager::fromUrl('sqlite:////tmp/shop.db?serverVersion=3.46.0')->withoutDatabase()->getDatabase(), 'a SQLite database is its file');
    }
}
