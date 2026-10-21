<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;

/**
 * Renommages explicites des migrations (ADR 0012) : la colonne ou la table renommée partout où
 * le schéma la cite ; le nom des index et des clés est gardé, comme la base le garde.
 */
final class RenameTest extends TestCase
{
    private static function schema(): Schema
    {
        $client = new Table('client', [new Column('id', 'integer'), new Column('mail', 'string', length: 120)], ['id']);
        $client = $client->withIndexOn(['mail'], unique: true, name: 'uniq_mail');
        $invoice = new Table('invoice', [new Column('id', 'integer'), new Column('client_id', 'integer'), new Column('ref', 'string', length: 20)], ['id']);
        $invoice = $invoice->withIndexOn(['client_id', 'ref'], name: 'idx_client_ref')->withForeignKeyTo(['client_id'], 'client', ['id'], ForeignKeyAction::Cascade, 'fk_client');

        return new Schema([$client, $invoice]);
    }

    public function testRenamedColumnIsRenamedWhereverTheTableCitesIt(): void
    {
        $schema = self::schema()->withRenamedColumn('invoice', 'client_id', 'customer_id');
        $invoice = $schema->getTable('invoice');

        self::assertSame(['id', 'customer_id', 'ref'], array_map(static fn (Column $c): string => $c->name, $invoice->getColumns()), 'at its place');
        self::assertSame(['customer_id', 'ref'], $invoice->getIndexes()[0]->columns);
        self::assertSame('idx_client_ref', $invoice->getIndexes()[0]->name, 'the index keeps its name');
        self::assertSame(['customer_id'], $invoice->getForeignKeys()[0]->localColumns);
        self::assertSame('integer', $invoice->getColumn('customer_id')->type);
    }

    public function testRenamedReferencedColumnIsRenamedInOtherForeignKeys(): void
    {
        $schema = self::schema()->withRenamedColumn('client', 'id', 'client_id');

        self::assertSame(['client_id'], $schema->getTable('client')->primaryKey);
        self::assertSame(['client_id'], $schema->getTable('invoice')->getForeignKeys()[0]->foreignColumns);
        self::assertSame(['client_id'], $schema->getTable('invoice')->getForeignKeys()[0]->localColumns, 'the local column is another one');
    }

    public function testRenamedTableIsFollowedByForeignKeys(): void
    {
        $schema = self::schema()->withRenamedTable('client', 'customer');

        self::assertFalse($schema->hasTable('client'));
        self::assertSame(['id', 'mail'], array_map(static fn (Column $c): string => $c->name, $schema->getTable('customer')->getColumns()));
        self::assertSame('uniq_mail', $schema->getTable('customer')->getIndexes()[0]->name);
        self::assertSame('customer', $schema->getTable('invoice')->getForeignKeys()[0]->foreignTable);
    }

    public function testRenamingKeepsTheSchemaImmutable(): void
    {
        $schema = self::schema();
        $schema->withRenamedTable('client', 'customer')->withRenamedColumn('invoice', 'ref', 'reference');

        self::assertTrue($schema->hasTable('client'));
        self::assertTrue($schema->getTable('invoice')->hasColumn('ref'));
    }

    public function testCaseOnlyRenameIsAllowed(): void
    {
        self::assertTrue(self::schema()->withRenamedColumn('invoice', 'ref', 'REF')->getTable('invoice')->hasColumn('REF'));
        self::assertTrue(self::schema()->withRenamedTable('invoice', 'Invoice')->hasTable('Invoice'));
    }

    public function testRenamingOntoAnExistingNameIsRefused(): void
    {
        try {
            self::schema()->withRenamedColumn('invoice', 'ref', 'id');
            self::fail('Expected InvalidSchemaException.');
        } catch (InvalidSchemaException) {
        }

        $this->expectException(InvalidSchemaException::class);
        self::schema()->withRenamedTable('client', 'invoice');
    }

    public function testUnknownTableOrColumnIsRefused(): void
    {
        try {
            self::schema()->withRenamedColumn('invoice', 'missing', 'x');
            self::fail('Expected InvalidSchemaException.');
        } catch (InvalidSchemaException) {
        }

        $this->expectException(InvalidSchemaException::class);
        self::schema()->withRenamedTable('missing', 'x');
    }

    /**
     * @return iterable<string, array{AbstractPlatform, string, string, bool}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [new MySqlPlatform('8.4'), 'ALTER TABLE `order` RENAME TO `purchase`', 'ALTER TABLE `order` RENAME COLUMN `group` TO `team`', false];
        yield 'MariaDB' => [new MariaDbPlatform('11.8'), 'ALTER TABLE `order` RENAME TO `purchase`', 'ALTER TABLE `order` RENAME COLUMN `group` TO `team`', false];
        yield 'PostgreSQL' => [new PostgreSqlPlatform('16'), 'ALTER TABLE "order" RENAME TO "purchase"', 'ALTER TABLE "order" RENAME COLUMN "group" TO "team"', true];
        yield 'SQLite' => [new SqlitePlatform('3.46'), 'ALTER TABLE "order" RENAME TO "purchase"', 'ALTER TABLE "order" RENAME COLUMN "group" TO "team"', false];
    }

    // RG-01.3 : des mots réservés, cités
    #[DataProvider('platforms')]
    public function testRenameSqlAndTransactionalDdl(AbstractPlatform $platform, string $table, string $column, bool $transactionalDdl): void
    {
        self::assertSame($table, $platform->getRenameTableSql('order', 'purchase'));
        self::assertSame($column, $platform->getRenameColumnSql('order', 'group', 'team'));
        self::assertSame($transactionalDdl, $platform->supportsTransactionalDdl());
    }
}
