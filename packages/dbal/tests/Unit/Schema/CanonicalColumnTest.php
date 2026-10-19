<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;

// ce que chaque plateforme conserve d'une colonne : base de la comparaison RG-01.8
final class CanonicalColumnTest extends TestCase
{
    public function testStringWithoutLengthBecomes255Everywhere(): void
    {
        foreach ([new MySqlPlatform('8.4'), new MariaDbPlatform('11.8'), new PostgreSqlPlatform('16'), new SqlitePlatform('3.46')] as $platform) {
            self::assertSame(255, $platform->getCanonicalColumn(new Column('a', 'string'))->length, $platform->getName());
        }
    }

    public function testBinaryLengthIsKeptOnlyWhereTheTypeHasOne(): void
    {
        $binary = new Column('b', 'binary', length: 64);

        self::assertSame(64, new MySqlPlatform('8.4')->getCanonicalColumn($binary)->length);
        self::assertNull(new PostgreSqlPlatform('16')->getCanonicalColumn($binary)->length);
        self::assertNull(new SqlitePlatform('3.46')->getCanonicalColumn($binary)->length);
    }

    public function testSqliteAutoincrementIsAlwaysInteger(): void
    {
        $id = new Column('id', 'bigint', autoincrement: true);

        self::assertSame('integer', new SqlitePlatform('3.46')->getCanonicalColumn($id)->type);
        self::assertSame('bigint', new PostgreSqlPlatform('16')->getCanonicalColumn($id)->type);
        self::assertSame('bigint', new SqlitePlatform('3.46')->getCanonicalColumn(new Column('n', 'bigint'))->type);
    }

    public function testCanonicalTableKeepsEverythingElse(): void
    {
        $table = new Table('t', [new Column('id', 'bigint', autoincrement: true), new Column('label', 'string')], ['id'])->withIndexOn(['label']);

        $canonical = new SqlitePlatform('3.46')->getCanonicalTable($table);

        self::assertSame(['integer', 'string'], array_map(static fn (Column $c): string => $c->type, $canonical->getColumns()));
        self::assertSame(255, $canonical->getColumn('label')->length);
        self::assertSame(['id'], $canonical->primaryKey);
        self::assertCount(1, $canonical->getIndexes());
    }
}
