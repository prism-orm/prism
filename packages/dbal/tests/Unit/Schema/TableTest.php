<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

final class TableTest extends TestCase
{
    private static function invoice(): Table
    {
        return new Table('invoice', [
            new Column('id', 'bigint', autoincrement: true),
            new Column('number', 'string', length: 20),
            new Column('client_id', 'bigint'),
            new Column('total', 'decimal', default: '0.00', precision: 12, scale: 2),
        ], ['id']);
    }

    public function testColumnsKeepTheirOrderAndAreFoundCaseInsensitively(): void
    {
        $table = self::invoice();

        self::assertSame(['id', 'number', 'client_id', 'total'], array_map(static fn (Column $c): string => $c->name, $table->getColumns()));
        self::assertTrue($table->hasColumn('NUMBER'));
        self::assertSame('total', $table->getColumn('Total')->name);
    }

    // RT-07 : chaque with*() retourne une copie
    public function testTableIsImmutable(): void
    {
        $table = self::invoice();
        $changed = $table->withColumn(new Column('paid', 'boolean', default: false))->withIndexOn(['number'], unique: true);

        self::assertFalse($table->hasColumn('paid'));
        self::assertSame([], $table->getIndexes());
        self::assertTrue($changed->hasColumn('paid'));
        self::assertCount(1, $changed->getIndexes());
    }

    public function testWithColumnReplacesInPlace(): void
    {
        $table = self::invoice()->withColumn(new Column('number', 'string', length: 40));

        self::assertSame(['id', 'number', 'client_id', 'total'], array_map(static fn (Column $c): string => $c->name, $table->getColumns()));
        self::assertSame(40, $table->getColumn('number')->length);
    }

    // RG-01.7 : nom déterministe, stable d'un appel à l'autre
    public function testGeneratedNamesAreDeterministic(): void
    {
        $a = self::invoice()->withIndexOn(['client_id', 'number'])->withForeignKeyTo(['client_id'], 'client', ['id'], ForeignKeyAction::Cascade);
        $b = self::invoice()->withIndexOn(['client_id', 'number'])->withForeignKeyTo(['client_id'], 'client', ['id'], ForeignKeyAction::Cascade);

        self::assertSame($a->getIndexes()[0]->name, $b->getIndexes()[0]->name);
        self::assertMatchesRegularExpression('/^idx_invoice_[0-9a-f]{8}$/', $a->getIndexes()[0]->name);
        self::assertMatchesRegularExpression('/^fk_invoice_[0-9a-f]{8}$/', $a->getForeignKeys()[0]->name);
        self::assertMatchesRegularExpression('/^uniq_invoice_[0-9a-f]{8}$/', self::invoice()->withIndexOn(['number'], true)->getIndexes()[0]->name);
        // l'ordre des colonnes compte : (a, b) et (b, a) sont deux index différents
        self::assertNotSame(
            self::invoice()->withIndexOn(['client_id', 'number'])->getIndexes()[0]->name,
            self::invoice()->withIndexOn(['number', 'client_id'])->getIndexes()[0]->name,
        );
    }

    public function testGeneratedNamesFitTheShortestIdentifierLimit(): void
    {
        $long = str_repeat('very_long_table_name_', 6);
        $table = new Table($long, [new Column('a', 'integer')]);

        $name = $table->withIndexOn(['a'])->getIndexes()[0]->name;

        self::assertSame(63, \strlen($name));
        self::assertStringStartsWith('idx_very_long_table_name_', $name);
    }

    public function testWithoutColumnRefusesAColumnInUse(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('Cannot remove column "number" from table "invoice": it is used by index "idx_number"');

        self::invoice()->withIndex(new Index('idx_number', ['number']))->withoutColumn('number');
    }

    public function testWithoutAndRemovals(): void
    {
        $table = self::invoice()->withIndexOn(['number'], name: 'idx_n')->withForeignKeyTo(['client_id'], 'client', ['id'], name: 'fk_c');

        $stripped = $table->withoutIndex('idx_n')->withoutForeignKey('FK_C')->withoutColumn('number')->withColumn(new Column('id', 'bigint'))->withPrimaryKey(null);

        self::assertSame([], $stripped->getIndexes());
        self::assertSame([], $stripped->getForeignKeys());
        self::assertNull($stripped->primaryKey);
        self::assertFalse($stripped->hasColumn('number'));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidTables(): iterable
    {
        yield 'duplicate column, other case' => [static fn (): Table => new Table('t', [new Column('Name', 'string'), new Column('name', 'string')]), 'Duplicate column "name" in table "t"'];
        yield 'unknown index column' => [static fn (): Table => new Table('t', [new Column('a', 'integer')], null, [new Index('i', ['b'])]), 'Table "t" has no column "b" (used by index "i")'];
        yield 'unknown primary key column' => [static fn (): Table => new Table('t', [new Column('a', 'integer')], ['id']), 'has no column "id" (used by the primary key)'];
        yield 'unknown foreign key column' => [static fn (): Table => new Table('t', [new Column('a', 'integer')], null, [], [new ForeignKey('f', ['b'], 'u', ['id'])]), 'has no column "b" (used by foreign key "f")'];
        yield 'nullable primary key' => [static fn (): Table => new Table('t', [new Column('a', 'integer', nullable: true)], ['a']), 'is part of the primary key and cannot be nullable'];
        yield 'autoincrement outside primary key' => [static fn (): Table => new Table('t', [new Column('a', 'integer', autoincrement: true)]), 'it must be the only primary key column'];
        yield 'autoincrement in composite key' => [static fn (): Table => new Table('t', [new Column('a', 'integer', autoincrement: true), new Column('b', 'integer')], ['a', 'b']), 'it must be the only primary key column'];
        yield 'duplicate index' => [static fn (): Table => new Table('t', [new Column('a', 'integer')], null, [new Index('i', ['a']), new Index('I', ['a'])]), 'Duplicate index "I"'];
        yield 'empty table name' => [static fn (): Table => new Table(' '), 'The table name must not be empty'];
        yield 'duplicate table' => [static fn (): Schema => new Schema([new Table('t'), new Table('T')]), 'Table "T" already exists in the schema'];
        yield 'unknown table' => [static fn (): Table => new Schema()->getTable('t'), 'The schema has no table "t"'];
        yield 'unknown column' => [static fn (): Column => new Table('t')->getColumn('a'), 'Table "t" has no column "a"'];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[DataProvider('invalidTables')]
    public function testInvalidTablesAreRefused(\Closure $build, string $message): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    public function testSchema(): void
    {
        $schema = new Schema([self::invoice()])->withTable(new Table('client', [new Column('id', 'bigint')], ['id']))->withSequence(new Sequence('invoice_seq', 1000, 10));

        self::assertSame(['invoice', 'client'], array_map(static fn (Table $t): string => $t->name, $schema->getTables()));
        self::assertTrue($schema->hasTable('CLIENT'));
        self::assertSame(1000, $schema->getSequences()[0]->start);
        self::assertFalse($schema->withoutTable('client')->hasTable('client'));
        self::assertTrue($schema->hasTable('client'));
    }
}
