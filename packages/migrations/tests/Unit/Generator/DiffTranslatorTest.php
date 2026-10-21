<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\Generator\DiffTranslator;
use Prism\Migrations\Generator\GeneratedMigration;
use Prism\Migrations\Operation\Operation;
use Prism\Migrations\Planner;

/**
 * De l'écart entre deux schémas aux opérations d'une migration : ordre accepté par chaque
 * opération, down() seulement s'il est sûr (RG-06.7), renommages confirmés (RG-06.3).
 */
final class DiffTranslatorTest extends TestCase
{
    private PostgreSqlPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new PostgreSqlPlatform('16');
    }

    private static function client(Column ...$extra): Table
    {
        return new Table('client', [new Column('id', 'integer'), new Column('mail', 'string', length: 120), ...array_values($extra)], ['id']);
    }

    /**
     * @param list<string> $answers
     */
    private function translate(Schema $from, Schema $to, ?array $answers = null, bool $dropUnmapped = true): GeneratedMigration
    {
        $confirm = null === $answers ? null : static function () use (&$answers): bool {
            return 'yes' === array_shift($answers);
        };

        return new DiffTranslator($this->platform)->translate($from, $to, $confirm, $dropUnmapped);
    }

    /**
     * @param list<Operation> $operations
     *
     * @return list<string>
     */
    private static function names(array $operations): array
    {
        return array_map(static fn (Operation $o): string => \is_string($name = $o->describe()['operation'] ?? null) ? $name : '?', $operations);
    }

    public function testEachChangeHasItsInverse(): void
    {
        $from = new Schema([self::client(new Column('name', 'string', length: 80))->withIndexOn(['mail'])], [new Sequence('client_id_seq')]);
        $to = new Schema([
            self::client(new Column('name', 'string', default: 'x', length: 40), new Column('phone', 'string', nullable: true, length: 20))->withIndexOn(['name']),
            new Table('note', [new Column('id', 'integer')], ['id']),
        ], [new Sequence('client_id_seq', increment: 10)]);

        $migration = $this->translate($from, $to);

        self::assertSame(['changeSequence', 'createTable', 'dropIndex', 'addColumn', 'changeColumn', 'createIndex'], self::names($migration->up));
        self::assertSame(['dropIndex', 'changeColumn', 'dropColumn', 'createIndex', 'dropTable', 'changeSequence'], self::names($migration->down), 'inverses, in reverse order');
        self::assertTrue($migration->isReversible(), 'up() narrows the column; down() widens it back without risk');

        // l'aller et le retour mènent bien d'un schéma à l'autre
        $planner = new Planner($this->platform);
        $after = $planner->plan($from, $migration->up)->schema;
        self::assertTrue(new \Prism\Dbal\Schema\Comparator($this->platform)->compare($after, $to)->isEmpty());
        self::assertTrue(new \Prism\Dbal\Schema\Comparator($this->platform)->compare($planner->plan($after, $migration->down)->schema, $from)->isEmpty());
    }

    // RG-06.7
    public function testDestroyingDataMakesTheMigrationIrreversible(): void
    {
        $from = new Schema([self::client(new Column('code', 'string', length: 20), new Column('total', 'decimal', precision: 12, scale: 2)), new Table('legacy', [new Column('id', 'integer')], ['id'])], [new Sequence('legacy_id_seq')]);
        $to = new Schema([self::client(new Column('code', 'integer'), new Column('total', 'decimal', precision: 10, scale: 2))]);

        $migration = $this->translate(new Schema($from->getTables(), $from->getSequences()), $to);

        self::assertFalse($migration->isReversible());
        self::assertSame([], $migration->down);
        self::assertSame([
            'it changes client.code, and changing it back could truncate or reject its data',
            'it drops the table legacy and its data',
            'it drops the sequence legacy_id_seq and its counter',
        ], array_values(array_filter($migration->irreversible, static fn (string $r): bool => !str_contains($r, 'client.total'))));
        self::assertNotContains('it changes client.total, and changing it back could truncate or reject its data', $migration->irreversible, 'a narrower decimal widens back safely');
    }

    // RG-06.7 : élargie par up(), une colonne serait rétrécie par down() : irréversible
    public function testWidenedColumnCannotBeNarrowedBack(): void
    {
        $from = new Schema([self::client(new Column('name', 'string', length: 40))]);
        $to = new Schema([self::client(new Column('name', 'string', nullable: true, length: 80))]);

        $migration = $this->translate($from, $to);

        self::assertSame(['it changes client.name, and changing it back could truncate or reject its data'], $migration->irreversible);
    }

    public function testKeysAndIndexesLeaveBeforeTheirColumnsAndComeAfter(): void
    {
        $from = new Schema([self::client(new Column('old', 'integer'))->withIndexOn(['old'])->withForeignKeyTo(['old'], 'client', ['id'])]);
        $to = new Schema([self::client(new Column('new', 'string', length: 10))->withIndexOn(['new'])]);

        $migration = $this->translate($from, $to);

        self::assertSame(['dropForeignKey', 'dropIndex', 'addColumn', 'dropColumn', 'createIndex'], self::names($migration->up));
        new Planner($this->platform)->plan($from, $migration->up);
    }

    // RG-06.3
    public function testConfirmedRenamesKeepTheDataAndTheSequenceCounter(): void
    {
        $from = new Schema([new Table('invoice', [new Column('id', 'integer'), new Column('ref', 'string', length: 20)], ['id']), self::client()], [new Sequence('invoice_id_seq')]);
        $to = new Schema([new Table('bill', [new Column('id', 'integer'), new Column('ref', 'string', length: 20)], ['id']), new Table('client', [new Column('id', 'integer'), new Column('email', 'string', length: 120)], ['id'])], [new Sequence('bill_id_seq')]);

        $migration = $this->translate($from, $to, ['yes', 'yes']);

        self::assertSame(['renameTable', 'renameSequence', 'renameColumn'], self::names($migration->up));
        self::assertSame(['renameColumn', 'renameSequence', 'renameTable'], self::names($migration->down));
        self::assertSame([], $migration->warnings);

        $refused = $this->translate($from, $to, ['no', 'no']);
        self::assertSame(['createSequence', 'createTable', 'addColumn', 'dropColumn', 'dropTable', 'dropSequence'], self::names($refused->up));
        self::assertSame([], $refused->warnings, 'a refused rename is a decision, not a warning');
    }

    public function testNonInteractiveRenamesBecomeWarnings(): void
    {
        $from = new Schema([self::client()]);
        $to = new Schema([new Table('client', [new Column('id', 'integer'), new Column('email', 'string', length: 120), new Column('other', 'string', length: 5)], ['id'])]);

        $migration = $this->translate($from, $to);

        self::assertSame(["client.mail is dropped and client.email added, both string: if it is a rename, replace the dropColumn() and addColumn() below with \$m->renameColumn('client', 'mail', 'email') to keep the data."], $migration->warnings, 'one candidate per dropped column');
        self::assertSame(['addColumn', 'addColumn', 'dropColumn'], self::names($migration->up));
    }

    public function testUnmappedTablesAreKeptWithoutComplete(): void
    {
        $from = new Schema([self::client(), new Table('legacy', [new Column('id', 'integer')], ['id'])]);

        self::assertTrue($this->translate($from, new Schema([self::client()]), dropUnmapped: false)->isEmpty());
        self::assertSame(['dropTable'], self::names($this->translate($from, new Schema([self::client()]))->up));
    }
}
