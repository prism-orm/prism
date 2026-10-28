<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;

/**
 * Index vectoriels et plein texte (ADR 0017) : créés, relus sans diff (RG-01.8), ajoutés et
 * supprimés par migration. MariaDB : VECTOR INDEX et FULLTEXT ; PostgreSQL : HNSW et GIN sur
 * to_tsvector() ; MySQL : FULLTEXT seul ; SQLite : aucun, la recherche lit toute la table.
 */
final class SearchIndexTest extends SchemaTestCase
{
    private function vectors(): bool
    {
        return $this->connection->getPlatform()->supportsVectors();
    }

    private function table(): Table
    {
        $table = new Table('search_doc', [
            new Column('id', 'integer'),
            new Column('title', 'string', length: 200),
            new Column('body', 'text', nullable: true),
        ], ['id']);
        $table = $table->withFullTextIndexOn(['title', 'body'], 'english');

        return $this->vectors()
            ? $table->withColumn(new Column('embedding', 'vector', length: 3))->withVectorIndexOn('embedding', VectorDistance::Cosine, m: 8, efConstruction: 32)
            : $table;
    }

    private function assertNoDiff(Table $expected): void
    {
        $actual = $this->connection->createSchemaManager()->introspectTable($expected->name);
        $diff = new Comparator($this->connection->getPlatform())->compare(new Schema([$actual]), new Schema([$expected]));

        self::assertTrue($diff->isEmpty(), implode(' | ', $this->connection->getPlatform()->getMigrationSql($diff)));
    }

    // RG-01.8
    public function testRoundTrip(): void
    {
        $this->create(new Schema([$this->table()]));

        $this->assertNoDiff($this->table());

        $indexes = $this->connection->createSchemaManager()->introspectTable('search_doc')->getIndexes();
        $kinds = array_map(static fn (Index $i): string => $i->kind->name, $indexes);
        sort($kinds);
        $expected = match ($this->connection->getPlatform()->getName()) {
            'SQLite' => [],
            'MySQL' => ['FullText'],
            default => ['FullText', 'Vector'],
        };
        self::assertSame($expected, $kinds);
    }

    public function testVectorIndexIsReadWithItsOptions(): void
    {
        if (!$this->vectors() || 'SQLite' === $this->connection->getPlatform()->getName()) {
            self::markTestSkipped('MariaDB and PostgreSQL only.');
        }
        $this->create(new Schema([$this->table()]));

        $vector = array_values(array_filter(
            $this->connection->createSchemaManager()->introspectTable('search_doc')->getIndexes(),
            static fn (Index $i): bool => IndexKind::Vector === $i->kind,
        ))[0];

        self::assertSame(['embedding'], $vector->columns);
        self::assertSame(VectorDistance::Cosine, $vector->distance);
        self::assertSame(8, $vector->m);
        // MariaDB ne garde pas ef_construction
        self::assertSame('MariaDB' === $this->connection->getPlatform()->getName() ? null : 32, $vector->efConstruction);
    }

    public function testMigrationAddsThenDropsTheIndexes(): void
    {
        $bare = new Table('search_doc', [
            new Column('id', 'integer'),
            new Column('title', 'string', length: 200),
            new Column('body', 'text', nullable: true),
        ], ['id']);
        if ($this->vectors()) {
            $bare = $bare->withColumn(new Column('embedding', 'vector', length: 3));
        }
        $this->create(new Schema([$bare]));
        $manager = $this->connection->createSchemaManager();
        $comparator = new Comparator($this->connection->getPlatform());

        $manager->migrate($comparator->compare(new Schema([$manager->introspectTable('search_doc')]), new Schema([$this->table()])));
        $this->assertNoDiff($this->table());

        $manager->migrate($comparator->compare(new Schema([$manager->introspectTable('search_doc')]), new Schema([$bare])));
        self::assertSame([], $manager->introspectTable('search_doc')->getIndexes());
    }

    // un index changé (distance, langue) est recréé
    public function testChangedOptionsRecreateTheIndex(): void
    {
        $this->create(new Schema([$this->table()]));
        $manager = $this->connection->createSchemaManager();
        $changed = $this->table()->withFullTextIndexOn(['title'], 'simple', name: 'ftx_title');
        if ($this->vectors()) {
            $vector = array_values(array_filter($this->table()->getIndexes(), static fn (Index $i): bool => IndexKind::Vector === $i->kind))[0];
            $changed = $changed->withIndex($vector->withDistance(VectorDistance::Euclidean));
        }

        $manager->migrate(new Comparator($this->connection->getPlatform())->compare(new Schema([$manager->introspectTable('search_doc')]), new Schema([$changed])));

        $this->assertNoDiff($changed);
    }
}
