<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Driver;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * Vérifie que l'environnement de test (Docker, CI) atteint bien chaque base.
 */
final class DatabaseConnectionSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
    }

    public function testDatabaseAnswersSelectOne(): void
    {
        self::assertEquals(1, TestDatabase::connection()->executeQuery('SELECT 1')->fetchOne());
    }

    // RG-00.1 : la suite tourne sur une base créée pour elle, jamais sur la base partagée
    public function testSuiteRunsOnItsOwnDatabase(): void
    {
        $connection = TestDatabase::connection();

        if (Driver::Sqlite === TestDatabase::driver()) {
            self::assertSame(':memory:', $connection->getDatabase());

            return;
        }

        $sql = Driver::PostgreSql === TestDatabase::driver() ? 'SELECT current_database()' : 'SELECT DATABASE()';
        $current = $connection->executeQuery($sql)->fetchOne();

        self::assertIsString($current);
        self::assertMatchesRegularExpression('/^prism_test_[0-9a-f]{8}$/', $current);
        self::assertSame($connection->getDatabase(), $current);
    }

    public function testExpectedServerIsReached(): void
    {
        $url = (string) TestDatabase::url();
        $platform = TestDatabase::connection()->getPlatform();

        match (true) {
            str_contains($url, 'MariaDB') => self::assertInstanceOf(MariaDbPlatform::class, $platform),
            str_starts_with($url, 'mysql:') => self::assertInstanceOf(MySqlPlatform::class, $platform),
            default => self::assertNotSame('', $platform->version),
        };
    }
}
