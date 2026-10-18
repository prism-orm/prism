<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;

final class PlatformTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [new MySqlPlatform('8.4.6')];
        yield 'MariaDB' => [new MariaDbPlatform('11.8.3-MariaDB-ubu2404')];
        yield 'PostgreSQL' => [new PostgreSqlPlatform('16.10 (Debian 16.10-1.pgdg120+1)')];
        yield 'SQLite' => [new SqlitePlatform('3.46.1')];
    }

    // RG-01.1 : les savepoints portent les niveaux imbriqués
    #[DataProvider('platforms')]
    public function testSavepointSql(AbstractPlatform $platform): void
    {
        self::assertSame('SAVEPOINT PRISM_SAVEPOINT_2', $platform->getCreateSavepointSql('PRISM_SAVEPOINT_2'));
        self::assertSame('RELEASE SAVEPOINT PRISM_SAVEPOINT_2', $platform->getReleaseSavepointSql('PRISM_SAVEPOINT_2'));
        self::assertSame('ROLLBACK TO SAVEPOINT PRISM_SAVEPOINT_2', $platform->getRollbackSavepointSql('PRISM_SAVEPOINT_2'));
    }

    #[DataProvider('platforms')]
    public function testSavepointNameIsNeverInterpolatedBlindly(AbstractPlatform $platform): void
    {
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('Invalid savepoint name "x; DROP TABLE t"');

        $platform->getCreateSavepointSql('x; DROP TABLE t');
    }

    public function testVersionIsNormalized(): void
    {
        self::assertSame('11.8.3', new MariaDbPlatform('11.8.3-MariaDB-ubu2404')->version);
        self::assertSame('16.10.0', new PostgreSqlPlatform('16.10 (Debian 16.10-1.pgdg120+1)')->version);
        self::assertSame('8.0.0', new MySqlPlatform('8')->version);
    }

    public function testMinimumVersionIsAccepted(): void
    {
        self::assertSame('8.0.0', new MySqlPlatform('8.0.0')->version);
        self::assertSame('11.8.0', new MariaDbPlatform('11.8.0')->version);
        self::assertSame('15.0.0', new PostgreSqlPlatform('15')->version);
        self::assertSame('3.40.0', new SqlitePlatform('3.40.0')->version);
    }

    public function testOnlyPostgreSqlAbortsTransactionOnFailure(): void
    {
        self::assertTrue(new PostgreSqlPlatform('16')->failedStatementAbortsTransaction());
        self::assertFalse(new MySqlPlatform('8.4')->failedStatementAbortsTransaction());
        self::assertFalse(new MariaDbPlatform('11.8')->failedStatementAbortsTransaction());
        self::assertFalse(new SqlitePlatform('3.46')->failedStatementAbortsTransaction());
    }

    public function testSqliteEnablesForeignKeys(): void
    {
        self::assertSame(['PRAGMA foreign_keys = ON'], new SqlitePlatform('3.46')->getSessionInitSql());
        self::assertSame([], new MySqlPlatform('8.4')->getSessionInitSql());
        self::assertSame([], new PostgreSqlPlatform('16')->getSessionInitSql());
    }

    public function testNames(): void
    {
        self::assertSame(['MySQL', 'MariaDB', 'PostgreSQL', 'SQLite'], array_map(
            static fn (array $p): string => $p[0]->getName(),
            array_values(iterator_to_array(self::platforms())),
        ));
    }
}
