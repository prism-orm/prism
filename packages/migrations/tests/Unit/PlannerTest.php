<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\MigrationBuilder;
use Prism\Migrations\Planner;

/**
 * Les étapes d'une migration (ADR 0012) : les opérations de schéma qui se suivent en un diff,
 * renommages et SQL brut en instructions explicites, dans l'ordre écrit.
 */
final class PlannerTest extends TestCase
{
    private PostgreSqlPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new PostgreSqlPlatform('16');
    }

    private static function schema(): Schema
    {
        return new Schema([new Table('client', [new Column('id', 'integer'), new Column('mail', 'string', length: 120)], ['id'])]);
    }

    /**
     * @param \Closure(MigrationBuilder): void $migration
     */
    private function plan(\Closure $migration): \Prism\Migrations\Plan
    {
        $m = new MigrationBuilder($this->platform);
        $migration($m);

        return new Planner($this->platform)->plan(self::schema(), $m->operations());
    }

    public function testConsecutiveSchemaOperationsMakeOneDiff(): void
    {
        $plan = $this->plan(static function (MigrationBuilder $m): void {
            $m->addColumn('client', new Column('name', 'string', length: 80));
            $m->createIndex('client', ['name']);
        });

        self::assertCount(1, $plan->steps);
        self::assertInstanceOf(SchemaDiff::class, $plan->steps[0]);
        self::assertTrue($plan->ddl);
        self::assertSame(['ALTER TABLE "client" ADD COLUMN "name" VARCHAR(80) NOT NULL', 'CREATE INDEX "idx_client_b9e0995e" ON "client" ("name")'], $plan->sql($this->platform));
        self::assertTrue($plan->schema->getTable('client')->hasColumn('name'));
    }

    // RG-01.9 : un renommage n'est jamais un drop + add ; il coupe le diff en cours
    public function testRenameAndRawSqlAreExplicitStepsInWrittenOrder(): void
    {
        $plan = $this->plan(static function (MigrationBuilder $m): void {
            $m->addColumn('client', new Column('name', 'string', length: 80));
            $m->renameColumn('client', 'mail', 'email');
            $m->sql('UPDATE client SET name = :n', ['n' => 'x']);
            $m->renameTable('client', 'customer');
            $m->createIndex('customer', ['email'], unique: true);
        });

        self::assertSame([
            'ALTER TABLE "client" ADD COLUMN "name" VARCHAR(80) NOT NULL',
            'ALTER TABLE "client" RENAME COLUMN "mail" TO "email"',
            'UPDATE client SET name = :n',
            'ALTER TABLE "client" RENAME TO "customer"',
            'CREATE UNIQUE INDEX "uniq_customer_45b166be" ON "customer" ("email")',
        ], $plan->sql($this->platform));
        self::assertSame(['UPDATE client SET name = :n', ['n' => 'x']], $plan->steps[2]);
        self::assertSame(['id', 'email', 'name'], array_map(static fn (Column $c): string => $c->name, $plan->schema->getTable('customer')->getColumns()));
    }

    public function testRawSqlAloneIsNotDdl(): void
    {
        $plan = $this->plan(static fn (MigrationBuilder $m) => $m->sql('DELETE FROM client'));

        self::assertFalse($plan->ddl);
        self::assertSame([['DELETE FROM client', []]], $plan->steps);
    }

    public function testNothingToDo(): void
    {
        $plan = $this->plan(static function (MigrationBuilder $m): void {
            $m->addColumn('client', new Column('x', 'integer'));
            $m->dropColumn('client', 'x');
        });

        self::assertSame([], $plan->steps, 'an addition and its removal cancel out');
        self::assertFalse($plan->ddl);
    }

    public function testIndexAndForeignKeyAreDroppedByColumnsOrName(): void
    {
        $plan = $this->plan(static function (MigrationBuilder $m): void {
            $m->createIndex('client', ['mail'], name: 'by_mail');
            $m->createTable(new Table('invoice', [new Column('id', 'integer'), new Column('client_id', 'integer')], ['id']));
            $m->addForeignKey('invoice', ['client_id'], 'client', ['id']);
        });
        $m = new MigrationBuilder($this->platform);
        $m->dropIndex('client', ['mail']);
        $m->dropForeignKey('invoice', ['client_id']);
        $after = new Planner($this->platform)->plan($plan->schema, $m->operations())->schema;

        self::assertSame([], $after->getTable('client')->getIndexes());
        self::assertSame([], $after->getTable('invoice')->getForeignKeys());

        $m = new MigrationBuilder($this->platform);
        $m->dropIndex('client', 'BY_MAIL');
        self::assertSame([], new Planner($this->platform)->plan($plan->schema, $m->operations())->schema->getTable('client')->getIndexes(), 'by name, case-insensitively');
    }

    /**
     * @return iterable<string, array{\Closure(MigrationBuilder): void}>
     */
    public static function impossible(): iterable
    {
        yield 'existing column' => [static fn (MigrationBuilder $m) => $m->addColumn('client', new Column('mail', 'string'))];
        yield 'missing column change' => [static fn (MigrationBuilder $m) => $m->changeColumn('client', new Column('nope', 'string'))];
        yield 'missing column drop' => [static fn (MigrationBuilder $m) => $m->dropColumn('client', 'nope')];
        yield 'missing table' => [static fn (MigrationBuilder $m) => $m->addColumn('nope', new Column('a', 'string'))];
        yield 'existing table' => [static fn (MigrationBuilder $m) => $m->createTable(new Table('client', [new Column('id', 'integer')]))];
        yield 'missing index' => [static fn (MigrationBuilder $m) => $m->dropIndex('client', ['mail'])];
        yield 'missing foreign key' => [static fn (MigrationBuilder $m) => $m->dropForeignKey('client', 'fk_x')];
        yield 'rename onto an existing column' => [static fn (MigrationBuilder $m) => $m->renameColumn('client', 'mail', 'id')];
        yield 'dropped table' => [static fn (MigrationBuilder $m) => $m->dropTable('nope')];
    }

    /**
     * @param \Closure(MigrationBuilder): void $migration
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('impossible')]
    public function testImpossibleOperationIsRefused(\Closure $migration): void
    {
        $this->expectException(InvalidSchemaException::class);

        $this->plan($migration);
    }
}
