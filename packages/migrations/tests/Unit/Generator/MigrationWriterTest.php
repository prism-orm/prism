<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\Generator\GeneratedMigration;
use Prism\Migrations\Generator\MigrationWriter;
use Prism\Migrations\Operation;

/**
 * Le fichier d'une migration générée : déterministe, PHP valide, chaque opération écrite avec
 * les seuls arguments qui diffèrent de leur valeur par défaut.
 */
final class MigrationWriterTest extends TestCase
{
    private static function migration(bool $reversible = true): GeneratedMigration
    {
        $table = new Table('invoice', [new Column('id', 'integer', autoincrement: true), new Column('client_id', 'integer'), new Column('ref', 'string', length: 20), new Column('total', 'decimal', default: '0.00', precision: 12, scale: 2), new Column('note', 'text', nullable: true)], ['id'], [new Index('uniq_ref', ['ref'], unique: true)], [new ForeignKey('fk_client', ['client_id'], 'client', ['id'], ForeignKeyAction::Cascade)]);
        $up = [
            new Operation\RenameTable('bill', 'invoice_old'),
            new Operation\RenameSequence('bill_id_seq', 'invoice_old_id_seq'),
            new Operation\CreateSequence(new Sequence('invoice_id_seq', start: 10, increment: 5)),
            new Operation\CreateTable($table),
            new Operation\DropForeignKey('client', 'fk_old'),
            new Operation\DropIndex('client', 'idx_old'),
            new Operation\AddColumn('client', new Column('vip', 'boolean', default: false)),
            new Operation\ChangeColumn('client', new Column('name', 'string', nullable: true, length: 80)),
            new Operation\RenameColumn('client', 'mail', 'email'),
            new Operation\ChangePrimaryKey('client', ['id']),
            new Operation\DropColumn('client', 'fax'),
            new Operation\CreateIndex('client', ['email'], unique: true, name: 'uniq_email'),
            new Operation\AddForeignKey('client', ['parent_id'], 'client', ['id'], ForeignKeyAction::SetNull, 'fk_parent'),
            new Operation\ChangeSequence(new Sequence('client_id_seq', increment: 2)),
            new Operation\DropSequence('old_seq'),
            new Operation\DropTable('legacy'),
        ];

        return $reversible
            ? new GeneratedMigration($up, [new Operation\DropTable('invoice')], [], ['client.mail is dropped and client.email added, both string: if it is a rename, use renameColumn().'])
            : new GeneratedMigration($up, [], ['it drops client.fax, whose data cannot be restored', 'it drops the table legacy and its data'], []);
    }

    public function testGeneratedFileIsTheReference(): void
    {
        $code = new MigrationWriter()->write(self::migration(), 'App\\Migrations', 'Version20261021143000');

        self::assertStringEqualsFile(__DIR__ . '/Expected/Version20261021143000.txt', $code);
        self::assertSame($code, new MigrationWriter()->write(self::migration(), 'App\\Migrations', 'Version20261021143000'), 'deterministic');
    }

    public function testIrreversibleMigrationThrowsInItsDown(): void
    {
        $code = new MigrationWriter()->write(self::migration(reversible: false), 'App\\Migrations', 'Version20261021143000');

        self::assertStringContainsString("throw IrreversibleMigrationException::forVersion('Version20261021143000', 'it drops client.fax, whose data cannot be restored; it drops the table legacy and its data');", $code);
        self::assertStringContainsString('use Prism\\Migrations\\Exception\\IrreversibleMigrationException;', $code);
    }

    public function testGeneratedFileIsValidPhpAndLoadsAsAMigration(): void
    {
        $dir = sys_get_temp_dir() . '/prism-writer-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/Version20261021143000.php';
        file_put_contents($file, new MigrationWriter()->write(self::migration(), 'Prism\\Migrations\\Tests\\Generated\\Writer', 'Version20261021143000'));

        try {
            exec(\sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($file)), $output, $status);
            self::assertSame(0, $status, implode("\n", $output));
            $migrations = new \Prism\Migrations\MigrationFinder($dir, 'Prism\\Migrations\\Tests\\Generated\\Writer')->find();
            $builder = new \Prism\Migrations\MigrationBuilder(new \Prism\Dbal\Platform\PostgreSqlPlatform('16'));
            $migrations['Version20261021143000']->up($builder);
            self::assertSame(array_map(static fn (Operation\Operation $o): array => $o->describe(), self::migration()->up), array_map(static fn (Operation\Operation $o): array => $o->describe(), $builder->operations()), 'the file records exactly the operations');
        } finally {
            unlink($file);
            rmdir($dir);
        }
    }
}
