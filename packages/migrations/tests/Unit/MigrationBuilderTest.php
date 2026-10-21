<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\Exception\IrreversibleMigrationException;
use Prism\Migrations\MigrationBuilder;
use Prism\Migrations\Operation;
use Prism\Migrations\Operation\Describe;
use Prism\Migrations\Tests\Fixtures\Basic\Version20261021120000;

/**
 * Opérations enregistrées par une migration (ADR 0012) et leur checksum (RG-06.4).
 */
final class MigrationBuilderTest extends TestCase
{
    private static function builder(): MigrationBuilder
    {
        return new MigrationBuilder(new SqlitePlatform('3.46'));
    }

    public function testOperationsAreRecordedInOrder(): void
    {
        $m = self::builder();
        $m->createTable(new Table('t', [new Column('id', 'integer')], ['id']));
        $m->addColumn('t', new Column('a', 'string', length: 10));
        $m->changeColumn('t', new Column('a', 'string', nullable: true, length: 20));
        $m->renameColumn('t', 'a', 'b');
        $m->createIndex('t', ['b'], unique: true);
        $m->addForeignKey('t', ['b'], 'u', ['id'], ForeignKeyAction::SetNull, 'fk_b');
        $m->dropForeignKey('t', 'fk_b');
        $m->dropIndex('t', ['b']);
        $m->dropColumn('t', 'b');
        $m->renameTable('t', 'v');
        $m->createSequence(new Sequence('v_seq'));
        $m->dropSequence('v_seq');
        $m->sql('DELETE FROM v WHERE id > :id', ['id' => 3]);
        $m->dropTable('v');

        self::assertSame(
            [Operation\CreateTable::class, Operation\AddColumn::class, Operation\ChangeColumn::class, Operation\RenameColumn::class, Operation\CreateIndex::class, Operation\AddForeignKey::class, Operation\DropForeignKey::class, Operation\DropIndex::class, Operation\DropColumn::class, Operation\RenameTable::class, Operation\CreateSequence::class, Operation\DropSequence::class, Operation\Sql::class, Operation\DropTable::class],
            array_map(static fn (Operation\Operation $o): string => $o::class, $m->operations()),
        );
        self::assertSame(['operation' => 'addForeignKey', 'table' => 't', 'localColumns' => ['b'], 'foreignTable' => 'u', 'foreignColumns' => ['id'], 'onDelete' => 'SetNull', 'name' => 'fk_b'], $m->operations()[5]->describe());
        self::assertSame('SQLite', $m->platform()->getName());
    }

    // RG-06.4 : le checksum suit les opérations, pas le fichier
    public function testChecksumFollowsTheOperations(): void
    {
        $a = self::builder();
        $a->addColumn('t', new Column('a', 'string', length: 10));
        $same = self::builder();
        $same->addColumn('t', new Column('a', 'string', length: 10));
        $longer = self::builder();
        $longer->addColumn('t', new Column('a', 'string', length: 11));
        $reordered = self::builder();
        $reordered->addColumn('t', new Column('b', 'integer'));
        $reordered->addColumn('t', new Column('a', 'string', length: 10));

        self::assertSame($a->checksum(), $same->checksum());
        self::assertSame(64, \strlen($a->checksum()));
        self::assertNotSame($a->checksum(), $longer->checksum());
        self::assertNotSame($a->checksum(), $reordered->checksum());
        self::assertNotSame($a->checksum(), self::builder()->checksum());
    }

    // RG-06.4 : fixed n'entre dans le checksum que s'il est demandé ; les migrations déjà
    // exécutées gardent leur checksum
    public function testFixedLengthLeavesExistingChecksumsUnchanged(): void
    {
        $before = ['name' => 'a', 'type' => 'string', 'nullable' => false, 'default' => null, 'length' => 10, 'precision' => null, 'scale' => null, 'autoincrement' => false];

        self::assertSame($before, Describe::column(new Column('a', 'string', length: 10)));
        self::assertSame([...$before, 'fixed' => true], Describe::column(new Column('a', 'string', length: 10, fixed: true)));
        $fixed = self::builder();
        $fixed->addColumn('t', new Column('a', 'string', length: 10, fixed: true));
        $variable = self::builder();
        $variable->addColumn('t', new Column('a', 'string', length: 10));
        self::assertNotSame($variable->checksum(), $fixed->checksum());
    }

    // RG-06.7
    public function testMigrationWithoutDownIsIrreversible(): void
    {
        $migration = new Version20261021120000();
        self::assertSame('', $migration->getDescription());
        self::assertTrue($migration->isTransactional());

        $this->expectException(IrreversibleMigrationException::class);
        $this->expectExceptionMessage('The migration Version20261021120000 cannot be reverted: it has no down().');

        $migration->down(self::builder());
    }

    public function testVersionIsTheShortClassName(): void
    {
        self::assertSame('Version20261021120000', AbstractMigration::versionOf(Version20261021120000::class));
        self::assertSame('Version1', AbstractMigration::versionOf('Version1'));
    }
}
