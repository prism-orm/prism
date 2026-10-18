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
