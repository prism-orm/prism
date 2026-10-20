<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;

// régression : une entité dont seul l'identifiant IDENTITY est une colonne
final class InsertDefaultValuesTest extends TestCase
{
    public function testStandardSyntax(): void
    {
        self::assertSame('INSERT INTO "warehouse" DEFAULT VALUES', new PostgreSqlPlatform('16')->getInsertDefaultValuesSql('warehouse'));
        self::assertSame('INSERT INTO "order" DEFAULT VALUES', new SqlitePlatform('3.46')->getInsertDefaultValuesSql('order'));
    }

    // MySQL et MariaDB n'ont pas DEFAULT VALUES
    public function testMySqlAndMariaDbSyntax(): void
    {
        self::assertSame('INSERT INTO `warehouse` () VALUES ()', new MySqlPlatform('8.4')->getInsertDefaultValuesSql('warehouse'));
        self::assertSame('INSERT INTO `order` () VALUES ()', new MariaDbPlatform('11.8')->getInsertDefaultValuesSql('order'));
    }
}
