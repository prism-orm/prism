<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

/**
 * Scénarios de référence du Comparator, écrits avant son implémentation (CLAUDE.md).
 * Chaque scénario vérifie aussi ce qui ne doit PAS apparaître dans le diff.
 */
final class ComparatorTest extends TestCase
{
    private static function compare(Schema $from, Schema $to, ?AbstractPlatform $platform = null): SchemaDiff
    {
        return new Comparator($platform ?? new PostgreSqlPlatform('16'))->compare($from, $to);
    }

    private static function client(): Table
    {
        return new Table('client', [new Column('id', 'bigint', autoincrement: true), new Column('name', 'string', length: 100)], ['id']);
    }

    private static function invoice(): Table
    {
        return new Table('invoice', [
            new Column('id', 'bigint', autoincrement: true),
            new Column('client_id', 'bigint'),
            new Column('number', 'string', length: 20),
            new Column('total', 'decimal', default: '0.00', precision: 12, scale: 2),
        ], ['id'])
            ->withIndex(new Index('idx_number', ['number'], unique: true))
            ->withForeignKey(new ForeignKey('fk_client', ['client_id'], 'client', ['id'], ForeignKeyAction::Cascade));
    }

    private static function base(): Schema
    {
        return new Schema([self::client(), self::invoice()], [new Sequence('invoice_seq', 1, 1)]);
    }

    /**
     * @param list<object> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_map(static fn (object $o): string => property_exists($o, 'name') && \is_string($o->name) ? $o->name : '?', $items);
    }

    // 1
    public function testIdenticalSchemasProduceAnEmptyDiff(): void
    {
        $diff = self::compare(self::base(), self::base());

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $diff->createdTables);
        self::assertSame([], $diff->droppedTables);
        self::assertSame([], $diff->alteredTables);
    }

    // 1
    public function testDeclarationOrderOfTablesIndexesAndForeignKeysIsIgnored(): void
    {
        $invoice = self::invoice()->withIndex(new Index('idx_client', ['client_id']));
        $reordered = new Table('invoice', $invoice->getColumns(), $invoice->primaryKey, array_reverse($invoice->getIndexes()), $invoice->getForeignKeys());

        self::assertTrue(self::compare(new Schema([self::client(), $invoice]), new Schema([$reordered, self::client()]))->isEmpty());
    }

    // 2
    public function testNamesAreComparedCaseInsensitively(): void
    {
        $upper = new Table('CLIENT', [new Column('ID', 'bigint', autoincrement: true), new Column('Name', 'string', length: 100)], ['ID']);

        self::assertTrue(self::compare(new Schema([self::client()]), new Schema([$upper]))->isEmpty());
    }

    // 3
    public function testCreatedAndDroppedTables(): void
    {
        $diff = self::compare(new Schema([self::client()]), new Schema([self::client(), self::invoice()]));
        self::assertSame(['invoice'], self::names($diff->createdTables));
        self::assertSame([], $diff->droppedTables);
        self::assertSame([], $diff->alteredTables);

        $reverse = self::compare(new Schema([self::client(), self::invoice()]), new Schema([self::client()]));
        self::assertSame(['invoice'], self::names($reverse->droppedTables));
        self::assertSame([], $reverse->createdTables);
    }

    // 4
    public function testAddedAndDroppedColumns(): void
    {
        $to = self::base()->withTable(self::client()->withColumn(new Column('email', 'string', nullable: true, length: 180)));

        $diff = self::compare(self::base(), $to);
        self::assertCount(1, $diff->alteredTables);
        $table = $diff->alteredTables[0];
        self::assertSame('client', $table->name);
        self::assertSame(['email'], self::names($table->addedColumns));
        self::assertSame([], $table->droppedColumns);
        self::assertSame([], $table->changedColumns);

        self::assertSame(['email'], self::names(self::compare($to, self::base())->alteredTables[0]->droppedColumns));
    }

    // 5 : RG-01.9
    public function testRenamedColumnIsNeverGuessed(): void
    {
        $renamed = new Table('client', [new Column('id', 'bigint', autoincrement: true), new Column('label', 'string', length: 100)], ['id']);

        $table = self::compare(new Schema([self::client()]), new Schema([$renamed]))->alteredTables[0];

        self::assertSame(['label'], self::names($table->addedColumns));
        self::assertSame(['name'], self::names($table->droppedColumns));
        self::assertSame([], $table->changedColumns);
    }

    /**
     * @return iterable<string, array{Column, Column, list<string>}>
     */
    public static function columnChanges(): iterable
    {
        $total = new Column('total', 'decimal', default: '0.00', precision: 12, scale: 2);

        yield 'type' => [new Column('qty', 'integer'), new Column('qty', 'bigint'), ['type']];
        yield 'nullable' => [$total, $total->withNullable(true), ['nullable']];
        yield 'default value' => [$total, $total->withDefault('1.00'), ['default']];
        yield 'default removed' => [$total, $total->withDefault(null), ['default']];
        yield 'default type (int → string)' => [new Column('n', 'string', default: '1'), new Column('n', 'string', default: 1), ['default']];
        yield 'length' => [new Column('name', 'string', length: 100), new Column('name', 'string', length: 200), ['length']];
        yield 'precision' => [$total, $total->withType('decimal', null, 14, 2), ['precision']];
        yield 'scale' => [$total, $total->withType('decimal', null, 12, 4), ['scale']];
        yield 'fixed' => [new Column('code', 'string', length: 3), new Column('code', 'string', length: 3, fixed: true), ['fixed']];
        yield 'type and length' => [new Column('code', 'string', length: 10), new Column('code', 'text'), ['type', 'length']];
    }

    /**
     * 6 : une seule propriété signalée par changement.
     *
     * @param list<string> $expected
     */
    #[DataProvider('columnChanges')]
    public function testChangedColumnReportsExactlyTheChangedProperties(Column $from, Column $to, array $expected): void
    {
        $diff = self::compare(new Schema([new Table('t', [$from])]), new Schema([new Table('t', [$to])]));

        $table = $diff->alteredTables[0];
        self::assertSame([], $table->addedColumns);
        self::assertSame([], $table->droppedColumns);
        self::assertCount(1, $table->changedColumns);
        self::assertSame($expected, $table->changedColumns[0]->changedProperties);
        self::assertTrue($table->changedColumns[0]->hasChanged($expected[0]));
        self::assertFalse($table->changedColumns[0]->hasChanged('autoincrement'));
        self::assertSame($from->name, $table->changedColumns[0]->from->name);
        self::assertSame($to->type, $table->changedColumns[0]->to->type);
    }

    // 6
    public function testAutoincrementChange(): void
    {
        $from = new Table('t', [new Column('id', 'bigint')], ['id']);
        $to = new Table('t', [new Column('id', 'bigint', autoincrement: true)], ['id']);

        self::assertSame(['autoincrement'], self::compare(new Schema([$from]), new Schema([$to]))->alteredTables[0]->changedColumns[0]->changedProperties);
    }

    // 7
    public function testEquivalentDefaultsProduceNoChange(): void
    {
        $from = new Table('t', [new Column('a', 'float', default: 1.5), new Column('b', 'string', nullable: true)]);
        $to = new Table('t', [new Column('a', 'float', default: 1.5), new Column('b', 'string', nullable: true, default: null)]);

        self::assertTrue(self::compare(new Schema([$from]), new Schema([$to]))->isEmpty());
    }

    /**
     * @return iterable<string, array{AbstractPlatform, Column, Column}>
     */
    public static function platformEquivalences(): iterable
    {
        yield 'string without length is VARCHAR(255)' => [new MySqlPlatform('8.4'), new Column('a', 'string'), new Column('a', 'string', length: 255)];
        yield 'PostgreSQL BYTEA has no length' => [new PostgreSqlPlatform('16'), new Column('a', 'binary', length: 64), new Column('a', 'binary')];
        yield 'SQLite BLOB has no length' => [new SqlitePlatform('3.46'), new Column('a', 'binary', length: 64), new Column('a', 'binary')];
    }

    // 8
    #[DataProvider('platformEquivalences')]
    public function testPlatformOnlyDifferencesProduceNoDiff(AbstractPlatform $platform, Column $declared, Column $introspected): void
    {
        self::assertTrue(self::compare(new Schema([new Table('t', [$declared])]), new Schema([new Table('t', [$introspected])]), $platform)->isEmpty());
    }

    // 8
    public function testSqliteAutoincrementBigintEqualsInteger(): void
    {
        $declared = new Table('t', [new Column('id', 'bigint', autoincrement: true)], ['id']);
        $introspected = new Table('t', [new Column('id', 'integer', autoincrement: true)], ['id']);

        self::assertTrue(self::compare(new Schema([$declared]), new Schema([$introspected]), new SqlitePlatform('3.46'))->isEmpty());
        self::assertFalse(self::compare(new Schema([$declared]), new Schema([$introspected]), new PostgreSqlPlatform('16'))->isEmpty());
    }

    // 9
    public function testColumnOrderIsIgnored(): void
    {
        $reordered = new Table('client', array_reverse(self::client()->getColumns()), ['id']);

        self::assertTrue(self::compare(new Schema([self::client()]), new Schema([$reordered]))->isEmpty());
    }

    // 10
    public function testPrimaryKeyChanges(): void
    {
        $columns = [new Column('a', 'integer'), new Column('b', 'integer')];
        $none = new Table('t', $columns);
        $single = new Table('t', $columns, ['a']);
        $composite = new Table('t', $columns, ['a', 'b']);

        $added = self::compare(new Schema([$none]), new Schema([$single]))->alteredTables[0];
        self::assertTrue($added->primaryKeyChanged());
        self::assertNull($added->fromPrimaryKey);
        self::assertSame(['a'], $added->toPrimaryKey);

        $widened = self::compare(new Schema([$single]), new Schema([$composite]))->alteredTables[0];
        self::assertSame(['a'], $widened->fromPrimaryKey);
        self::assertSame(['a', 'b'], $widened->toPrimaryKey);

        $removed = self::compare(new Schema([$composite]), new Schema([$none]))->alteredTables[0];
        self::assertSame(['a', 'b'], $removed->fromPrimaryKey);
        self::assertNull($removed->toPrimaryKey);

        self::assertFalse([] !== self::compare(new Schema([$single]), new Schema([new Table('t', $columns, ['A'])]))->alteredTables);
    }

    /**
     * @return iterable<string, array{Index, list<string>, list<string>}>
     */
    public static function indexChanges(): iterable
    {
        yield 'columns changed' => [new Index('idx_number', ['number', 'client_id'], unique: true), ['idx_number'], ['idx_number']];
        yield 'uniqueness changed' => [new Index('idx_number', ['number']), ['idx_number'], ['idx_number']];
        yield 'same columns, other name' => [new Index('uniq_number', ['number'], unique: true), ['idx_number'], ['uniq_number']];
    }

    /**
     * 11 : un index modifié devient suppression + ajout.
     *
     * @param list<string> $dropped
     * @param list<string> $added
     */
    #[DataProvider('indexChanges')]
    public function testChangedIndexIsDroppedAndRecreated(Index $index, array $dropped, array $added): void
    {
        $to = self::base()->withTable(self::invoice()->withoutIndex('idx_number')->withIndex($index));

        $table = self::compare(self::base(), $to)->alteredTables[0];

        self::assertSame($dropped, self::names($table->droppedIndexes));
        self::assertSame($added, self::names($table->addedIndexes));
        self::assertSame([], $table->addedForeignKeys);
        self::assertSame([], $table->changedColumns);
    }

    // 11
    public function testAddedAndDroppedIndexes(): void
    {
        $to = self::base()->withTable(self::invoice()->withIndex(new Index('idx_client', ['client_id'])));

        self::assertSame(['idx_client'], self::names(self::compare(self::base(), $to)->alteredTables[0]->addedIndexes));
        self::assertSame(['idx_client'], self::names(self::compare($to, self::base())->alteredTables[0]->droppedIndexes));
    }

    /**
     * @return iterable<string, array{ForeignKey}>
     */
    public static function foreignKeyChanges(): iterable
    {
        yield 'on delete' => [new ForeignKey('fk_client', ['client_id'], 'client', ['id'], ForeignKeyAction::Restrict)];
        yield 'foreign table' => [new ForeignKey('fk_client', ['client_id'], 'customer', ['id'], ForeignKeyAction::Cascade)];
        yield 'foreign column' => [new ForeignKey('fk_client', ['client_id'], 'client', ['code'], ForeignKeyAction::Cascade)];
    }

    // 12 : une clé étrangère modifiée devient suppression + ajout
    #[DataProvider('foreignKeyChanges')]
    public function testChangedForeignKeyIsDroppedAndRecreated(ForeignKey $foreignKey): void
    {
        $to = new Schema([
            self::client()->withColumn(new Column('code', 'string', length: 10)),
            self::invoice()->withoutForeignKey('fk_client')->withForeignKey($foreignKey),
            new Table('customer', [new Column('id', 'bigint')], ['id']),
        ], self::base()->getSequences());

        $diff = self::compare(self::base(), $to);
        $invoice = array_values(array_filter($diff->alteredTables, static fn ($t): bool => 'invoice' === $t->name))[0];

        self::assertSame(['fk_client'], self::names($invoice->droppedForeignKeys));
        self::assertSame(['fk_client'], self::names($invoice->addedForeignKeys));
        self::assertSame($foreignKey, $invoice->addedForeignKeys[0]);
    }

    // 12
    public function testForeignTableNameIsComparedCaseInsensitively(): void
    {
        $to = self::base()->withTable(self::invoice()->withoutForeignKey('fk_client')->withForeignKey(new ForeignKey('fk_client', ['client_id'], 'CLIENT', ['id'], ForeignKeyAction::Cascade)));

        self::assertTrue(self::compare(self::base(), $to)->isEmpty());
    }

    // 13
    public function testForeignKeyToADroppedTableIsRefused(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('Foreign key "fk_client" of table "invoice" references table "client", which is not in the target schema.');

        self::compare(self::base(), new Schema([self::invoice()]));
    }

    // 14
    public function testSequences(): void
    {
        $created = self::compare(new Schema([self::client(), self::invoice()]), self::base());
        self::assertSame(['invoice_seq'], self::names($created->createdSequences));
        self::assertFalse($created->isEmpty());

        self::assertSame(['invoice_seq'], self::names(self::compare(self::base(), new Schema([self::client(), self::invoice()]))->droppedSequences));

        $altered = self::compare(self::base(), self::base()->withSequence(new Sequence('invoice_seq', 1, 5)));
        self::assertSame(['invoice_seq'], self::names($altered->alteredSequences));
        self::assertSame(5, $altered->alteredSequences[0]->increment);

        // le point de départ n'a plus de sens une fois la séquence créée
        self::assertTrue(self::compare(self::base(), self::base()->withSequence(new Sequence('invoice_seq', 500, 1)))->isEmpty());
    }

    // 15
    public function testReverseDiffIsSymmetric(): void
    {
        $to = new Schema([
            self::client()->withColumn(new Column('email', 'string', nullable: true, length: 180))->withoutColumn('name'),
            self::invoice()->withIndex(new Index('idx_client', ['client_id']))->withoutIndex('idx_number'),
            new Table('tag', [new Column('id', 'integer')], ['id']),
        ]);

        $forward = self::compare(self::base(), $to);
        $backward = self::compare($to, self::base());

        self::assertSame(self::names($forward->createdTables), self::names($backward->droppedTables));
        self::assertSame(self::names($forward->createdSequences), self::names($backward->droppedSequences));
        self::assertSame(self::names($forward->droppedSequences), self::names($backward->createdSequences));

        $byName = static function (SchemaDiff $d): array {
            $tables = [];
            foreach ($d->alteredTables as $t) {
                $tables[$t->name] = $t;
            }
            ksort($tables);

            return $tables;
        };
        foreach ($byName($forward) as $name => $table) {
            $other = $byName($backward)[$name];
            self::assertSame(self::names($table->addedColumns), self::names($other->droppedColumns), $name);
            self::assertSame(self::names($table->droppedColumns), self::names($other->addedColumns), $name);
            self::assertSame(self::names($table->addedIndexes), self::names($other->droppedIndexes), $name);
            self::assertSame(self::names($table->droppedIndexes), self::names($other->addedIndexes), $name);
        }
    }

    // 3 et 4 : un ajout en tête n'interrompt pas la comparaison des éléments suivants
    public function testAdditionsDoNotStopTheComparisonOfWhatFollows(): void
    {
        $to = new Schema([
            new Table('aaa_new', [new Column('id', 'integer')], ['id']),
            new Table('client', [new Column('aaa_new', 'integer', nullable: true), new Column('id', 'bigint', autoincrement: true), new Column('name', 'string', length: 150)], ['id']),
            self::invoice(),
        ], self::base()->getSequences());

        $diff = self::compare(self::base(), $to);

        self::assertSame(['aaa_new'], self::names($diff->createdTables));
        self::assertCount(1, $diff->alteredTables);
        self::assertSame(['aaa_new'], self::names($diff->alteredTables[0]->addedColumns));
        self::assertSame(['name'], array_map(static fn ($c): string => $c->to->name, $diff->alteredTables[0]->changedColumns));
    }

    // 2 et 11 : index, clés et séquences sont comparés sans tenir compte de la casse
    public function testIndexForeignKeyAndSequenceNamesIgnoreCase(): void
    {
        $upper = new Schema([
            self::client(),
            self::invoice()->withoutIndex('idx_number')->withIndex(new Index('IDX_NUMBER', ['number'], unique: true))
                ->withoutForeignKey('fk_client')->withForeignKey(new ForeignKey('FK_CLIENT', ['client_id'], 'client', ['id'], ForeignKeyAction::Cascade)),
        ], [new Sequence('INVOICE_SEQ', 1, 1)]);

        self::assertTrue(self::compare(self::base(), $upper)->isEmpty());
        self::assertTrue(self::compare($upper, self::base())->isEmpty());
    }

    public function testCompareTablesCanBeUsedDirectly(): void
    {
        $diff = new Comparator(new PostgreSqlPlatform('16'))->compareTables(self::client(), self::client()->withColumn(new Column('email', 'string', nullable: true)));

        self::assertSame('client', $diff->name);
        self::assertSame(['email'], self::names($diff->addedColumns));
        self::assertSame(255, $diff->addedColumns[0]->length);
    }

    public function testTableDiffExposesBothVersions(): void
    {
        $to = self::base()->withTable(self::client()->withColumn(new Column('email', 'string', nullable: true)));

        $table = self::compare(self::base(), $to)->alteredTables[0];

        self::assertFalse($table->from->hasColumn('email'));
        self::assertTrue($table->to->hasColumn('email'));
        self::assertFalse($table->isEmpty());
        self::assertFalse($table->primaryKeyChanged());
    }
}
