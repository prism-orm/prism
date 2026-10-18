<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
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
        $statement = TestDatabase::connect()->query('SELECT 1');

        self::assertNotFalse($statement);
        self::assertEquals(1, $statement->fetchColumn());
    }

    // RG-00.1 : la suite tourne sur une base créée pour elle, jamais sur la base partagée
    public function testSuiteRunsOnItsOwnDatabase(): void
    {
        if ('sqlite' === TestDatabase::platform()) {
            self::assertStringEndsWith(':memory:', (string) TestDatabase::url());

            return;
        }

        $sql = 'pgsql' === TestDatabase::platform() ? 'SELECT current_database()' : 'SELECT DATABASE()';
        $statement = TestDatabase::connect()->query($sql);
        self::assertNotFalse($statement);

        $current = $statement->fetchColumn();
        self::assertIsString($current);
        self::assertMatchesRegularExpression('/^prism_test_[0-9a-f]{8}$/', $current);
        self::assertSame(TestDatabase::databaseName(), $current);
    }

    public function testExpectedServerIsReached(): void
    {
        $version = TestDatabase::connect()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        self::assertIsString($version);

        match (TestDatabase::platform()) {
            'mariadb' => self::assertStringContainsString('MariaDB', $version),
            'mysql' => self::assertStringNotContainsString('MariaDB', $version),
            default => self::assertNotSame('', $version),
        };
    }
}
