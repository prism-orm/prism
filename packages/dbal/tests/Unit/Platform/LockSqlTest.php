<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\LockMode;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;

final class LockSqlTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string, string}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [new MySqlPlatform('8.4'), ' FOR SHARE', ' FOR UPDATE'];
        // FOR SHARE n'existe pas sous MariaDB
        yield 'MariaDB' => [new MariaDbPlatform('11.8'), ' LOCK IN SHARE MODE', ' FOR UPDATE'];
        yield 'PostgreSQL' => [new PostgreSqlPlatform('16'), ' FOR SHARE', ' FOR UPDATE'];
        // décision C : SQLite verrouille toute la base pendant l'écriture, pas de verrou de ligne
        yield 'SQLite' => [new SqlitePlatform('3.46'), '', ''];
    }

    #[DataProvider('platforms')]
    public function testLockClauseByPlatform(AbstractPlatform $platform, string $read, string $write): void
    {
        self::assertSame($read, $platform->getLockSql(LockMode::PessimisticRead));
        self::assertSame($write, $platform->getLockSql(LockMode::PessimisticWrite));
        self::assertSame('', $platform->getLockSql(LockMode::Optimistic), 'an optimistic lock never reaches the database');
    }
}
