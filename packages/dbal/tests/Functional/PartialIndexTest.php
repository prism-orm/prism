<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;

/**
 * Index partiel « colonne IS NULL » (RG-08.15, ADR 0015) sur les 4 bases : index partiel sous
 * PostgreSQL et SQLite, colonne virtuelle masquée sous MySQL et MariaDB. Aller-retour sans diff
 * (RG-01.8), unicité limitée aux lignes non supprimées.
 */
final class PartialIndexTest extends SchemaTestCase
{
    private static function table(bool $partial = true): Table
    {
        $table = new Table('partial_note', [
            new Column('id', 'integer'),
            new Column('number', 'string', length: 20),
            new Column('deleted_at', 'datetime_immutable', nullable: true),
        ], ['id']);

        return $partial ? $table->withIndexOn(['number'], unique: true, whereNull: 'deleted_at') : $table->withIndexOn(['number'], unique: true);
    }

    private function insert(int $id, string $number, ?string $deletedAt = null): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->insert('partial_note')->set('id', $id)->set('number', $number)->set('deleted_at', $deletedAt)->executeStatement();
    }

    // RG-01.8 : relu tel quel, la colonne virtuelle de MySQL et MariaDB comprise
    public function testPartialIndexRoundTrip(): void
    {
        $this->create(new Schema([self::table()]));

        $actual = $this->connection->createSchemaManager()->introspectTable('partial_note');
        $index = $actual->getIndexes()[0] ?? null;

        self::assertNotNull($index);
        self::assertSame([['number'], true, 'deleted_at'], [$index->columns, $index->unique, $index->whereNull]);
        self::assertSame(['id', 'number', 'deleted_at'], array_map(static fn (Column $c): string => $c->name, $actual->getColumns()), 'no virtual column');
        $diff = new Comparator($this->connection->getPlatform())->compare(new Schema([$actual]), new Schema([self::table()]));
        self::assertTrue($diff->isEmpty(), implode(' | ', $this->connection->getPlatform()->getMigrationSql($diff)));
    }

    // RG-08.15 : un numéro se répète parmi les lignes supprimées, jamais parmi les autres
    public function testUniquenessIgnoresDeletedRows(): void
    {
        $this->create(new Schema([self::table()]));
        $this->insert(1, 'F-1', '2026-10-26 10:00:00');
        $this->insert(2, 'F-1', '2026-10-27 10:00:00');
        $this->insert(3, 'F-1');

        $this->expectException(QueryFailedException::class);
        $this->insert(4, 'F-1');
    }

    // un index complet devient partiel, puis redevient complet, sans reste
    public function testIndexBecomesPartialAndBack(): void
    {
        $this->create(new Schema([self::table(partial: false)]));
        $manager = $this->connection->createSchemaManager();
        $comparator = new Comparator($this->connection->getPlatform());

        foreach ([self::table(), self::table(partial: false)] as $target) {
            $diff = $comparator->compare(new Schema([$manager->introspectTable('partial_note')]), new Schema([$target]));
            self::assertFalse($diff->isEmpty());
            $manager->migrate($diff);
            self::assertTrue($comparator->compare(new Schema([$manager->introspectTable('partial_note')]), new Schema([$target]))->isEmpty());
        }
        self::assertSame(['id', 'number', 'deleted_at'], array_map(static fn (Column $c): string => $c->name, $manager->introspectTable('partial_note')->getColumns()), 'the virtual column is dropped with its index');
    }
}
