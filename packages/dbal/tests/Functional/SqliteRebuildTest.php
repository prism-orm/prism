<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * RG-01.11 : la reconstruction d'une table SQLite ne perd aucune donnée, ni dans la table,
 * ni dans les tables qui la référencent avec ON DELETE CASCADE.
 */
final class SqliteRebuildTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url() || !str_starts_with(TestDatabase::url(), 'sqlite:')) {
            self::markTestSkipped('SQLite only: the other platforms alter tables in place.');
        }

        $this->connection = TestDatabase::connection();
        $this->migrate(new Schema(), $this->schema(false));
        $q = $this->connection->getPlatform()->quoteIdentifier(...);
        $this->connection->executeStatement(\sprintf('INSERT INTO %s (%s, %s) VALUES (1, ?), (2, ?)', $q('parent'), $q('id'), $q('label')), ['A', 'B']);
        $this->connection->executeStatement(\sprintf('INSERT INTO %s (%s, %s) VALUES (10, 1), (11, 1), (12, 2)', $q('child'), $q('id'), $q('parent_id')));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    // la parade : sans elle, le DROP TABLE du parent supprimerait les 3 enfants par CASCADE
    public function testRebuildingAParentKeepsItsChildren(): void
    {
        $this->migrate($this->schema(false), $this->schema(true));

        self::assertEquals(3, $this->scalar('SELECT COUNT(*) FROM "child"'));
        self::assertSame(['A', 'B'], $this->connection->executeQuery('SELECT "label" FROM "parent" ORDER BY "id"')->fetchFirstColumn());
        self::assertEquals(1, $this->scalar('PRAGMA foreign_keys'), 'Foreign keys must be enabled again.');
        // la clé étrangère de l'enfant vise toujours la table reconstruite
        $this->connection->executeStatement('DELETE FROM "parent" WHERE "id" = 1');
        self::assertEquals(1, $this->scalar('SELECT COUNT(*) FROM "child"'));
    }

    public function testMigrationInsideATransactionIsRefused(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->migrate($this->schema(false), $this->schema(true));
            self::fail('Expected InvalidSchemaException.');
        } catch (InvalidSchemaException $e) {
            self::assertStringContainsString('inside an open transaction', $e->getMessage());
        } finally {
            $this->connection->rollBack();
        }

        self::assertEquals(3, $this->scalar('SELECT COUNT(*) FROM "child"'));
    }

    // une clé ajoutée que les données violent : tout est annulé
    public function testForeignKeyViolationRollsTheMigrationBack(): void
    {
        $from = new Schema([$this->parent(false), new Table('child', [new Column('id', 'integer'), new Column('parent_id', 'integer')], ['id'])]);
        $this->migrate($this->schema(false), $from);
        $this->connection->executeStatement('INSERT INTO "child" ("id", "parent_id") VALUES (13, 99)');

        try {
            $this->migrate($from, $this->schema(false));
            self::fail('Expected InvalidSchemaException.');
        } catch (InvalidSchemaException $e) {
            self::assertStringContainsString('rows of table "child" reference missing rows of table "parent"', $e->getMessage());
        }

        self::assertSame([], $this->connection->createSchemaManager()->introspectTable('child')->getForeignKeys());
        self::assertEquals(4, $this->scalar('SELECT COUNT(*) FROM "child"'));
        self::assertEquals(1, $this->scalar('PRAGMA foreign_keys'));
    }

    private function schema(bool $rebuilt): Schema
    {
        return new Schema([
            $this->parent($rebuilt),
            new Table('child', [new Column('id', 'integer'), new Column('parent_id', 'integer')], ['id'])
                ->withForeignKeyTo(['parent_id'], 'parent', ['id'], ForeignKeyAction::Cascade),
        ]);
    }

    private function parent(bool $rebuilt): Table
    {
        // label passe de VARCHAR(20) à VARCHAR(80) : modification de colonne, donc reconstruction
        return new Table('parent', [new Column('id', 'integer'), new Column('label', 'string', length: $rebuilt ? 80 : 20)], ['id']);
    }

    private function migrate(Schema $from, Schema $to): void
    {
        $this->connection->createSchemaManager()->migrate(new Comparator($this->connection->getPlatform())->compare($from, $to));
    }

    private function scalar(string $sql): mixed
    {
        return $this->connection->executeQuery($sql)->fetchOne();
    }
}
