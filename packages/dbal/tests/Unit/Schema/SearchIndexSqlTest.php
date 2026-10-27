<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;

/**
 * Index vectoriels et plein texte (ADR 0017) : règles de l'objet, noms déterministes (RG-01.7),
 * DDL par plateforme, forme canonique comparée par le Comparator.
 */
final class SearchIndexSqlTest extends TestCase
{
    private static function table(): Table
    {
        return new Table('doc', [
            new Column('id', 'integer'),
            new Column('title', 'string', length: 200),
            new Column('body', 'text', nullable: true),
            new Column('embedding', 'vector', length: 3),
        ], ['id']);
    }

    /**
     * @return iterable<string, array{\Closure(): Index, string}>
     */
    public static function invalidIndexes(): iterable
    {
        yield 'unique vector' => [static fn (): Index => new Index('i', ['e'], unique: true, kind: IndexKind::Vector, distance: VectorDistance::Cosine), 'can be neither unique nor partial'];
        yield 'partial full-text' => [static fn (): Index => new Index('i', ['t'], whereNull: 'd', kind: IndexKind::FullText), 'can be neither unique nor partial'];
        yield 'vector on two columns' => [static fn (): Index => new Index('i', ['a', 'b'], kind: IndexKind::Vector, distance: VectorDistance::Cosine), 'covers one column and needs a distance'];
        yield 'vector without distance' => [static fn (): Index => new Index('i', ['e'], kind: IndexKind::Vector), 'covers one column and needs a distance'];
        yield 'distance on a B-tree' => [static fn (): Index => new Index('i', ['e'], distance: VectorDistance::Cosine), 'belong to a vector index'];
        yield 'm out of range' => [static fn (): Index => new Index('i', ['e'], kind: IndexKind::Vector, distance: VectorDistance::Cosine, m: 1), 'm must be between 2 and 100'];
        yield 'efConstruction below 2 × m' => [static fn (): Index => new Index('i', ['e'], kind: IndexKind::Vector, distance: VectorDistance::Cosine, m: 16, efConstruction: 20), 'at least 2 × m'];
        yield 'language on a vector' => [static fn (): Index => new Index('i', ['e'], kind: IndexKind::Vector, distance: VectorDistance::Cosine, language: 'english'), 'belongs to a full-text index'];
        yield 'language not a name' => [static fn (): Index => new Index('i', ['t'], kind: IndexKind::FullText, language: "english'; --"), 'configuration name'];
    }

    /**
     * @param \Closure(): Index $index
     */
    #[DataProvider('invalidIndexes')]
    public function testInvalidIndexIsRefused(\Closure $index, string $message): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage($message);

        $index();
    }

    // RG-01.7 : un nom propre à la sorte, jamais celui d'un index B-tree sur la même colonne
    public function testNamesAreDeterministicAndDistinctPerKind(): void
    {
        $table = self::table()->withIndexOn(['embedding'])->withVectorIndexOn('embedding', VectorDistance::Cosine)->withFullTextIndexOn(['title']);
        $names = array_map(static fn (Index $i): string => $i->name, $table->getIndexes());

        self::assertCount(3, array_unique($names));
        self::assertMatchesRegularExpression('/^vec_doc_[0-9a-f]{8}$/', $names[1]);
        self::assertMatchesRegularExpression('/^ftx_doc_[0-9a-f]{8}$/', $names[2]);
        self::assertSame($names, array_map(static fn (Index $i): string => $i->name, self::table()->withIndexOn(['embedding'])->withVectorIndexOn('embedding', VectorDistance::Euclidean)->withFullTextIndexOn(['title'])->getIndexes()));
    }

    public function testMariaDb(): void
    {
        $platform = new MariaDbPlatform('11.8');

        self::assertSame('CREATE VECTOR INDEX `v` ON `doc` (`embedding`) M=8 DISTANCE=cosine', $platform->getCreateIndexSql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Cosine, m: 8, efConstruction: 32)));
        self::assertSame('CREATE VECTOR INDEX `v` ON `doc` (`embedding`) DISTANCE=euclidean', $platform->getCreateIndexSql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Euclidean)));
        self::assertSame('CREATE FULLTEXT INDEX `f` ON `doc` (`title`, `body`)', $platform->getCreateIndexSql('doc', new Index('f', ['title', 'body'], kind: IndexKind::FullText, language: 'english')));
        self::assertSame('DROP INDEX `v` ON `doc`', $platform->getDropIndexDefinitionSql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Cosine)));

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('MariaDB does not support the inner product distance');
        $platform->getCreateIndexSql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::InnerProduct));
    }

    public function testMySqlHasFullTextButNoVectorIndex(): void
    {
        $platform = new MySqlPlatform('8.4');

        self::assertSame('CREATE FULLTEXT INDEX `f` ON `doc` (`title`)', $platform->getCreateIndexSql('doc', new Index('f', ['title'], kind: IndexKind::FullText)));

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('MySQL does not support the vector type');
        $platform->getCreateIndexSql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Cosine));
    }

    public function testPostgreSql(): void
    {
        $platform = new PostgreSqlPlatform('16');

        self::assertSame(
            'CREATE INDEX "v" ON "doc" USING hnsw ("embedding" vector_cosine_ops) WITH (m = 16, ef_construction = 64)',
            $platform->getCreateIndexSql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Cosine)),
        );
        // ef_construction d'au moins 2 × m quand il n'est pas donné
        self::assertSame(
            'CREATE INDEX "v" ON "doc" USING hnsw ("embedding" vector_ip_ops) WITH (m = 48, ef_construction = 96)',
            $platform->getCreateIndexSql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::InnerProduct, m: 48)),
        );
        self::assertSame(
            'CREATE INDEX CONCURRENTLY "v" ON "doc" USING hnsw ("embedding" vector_l2_ops) WITH (m = 8, ef_construction = 32)',
            $platform->getCreateIndexConcurrentlySql('doc', new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Euclidean, m: 8, efConstruction: 32)),
        );
        self::assertSame(
            "CREATE INDEX \"f\" ON \"doc\" USING gin (to_tsvector('english', coalesce(\"title\", '') || ' ' || coalesce(\"body\", '')))",
            $platform->getCreateIndexSql('doc', new Index('f', ['title', 'body'], kind: IndexKind::FullText, language: 'english')),
        );
        self::assertSame("to_tsvector('simple', coalesce(d.title, ''))", $platform->getFullTextDocumentSql(['d.title'], null));
    }

    // SQLite : ni l'un ni l'autre, et le schéma se crée quand même
    public function testSqliteSkipsBoth(): void
    {
        $platform = new SqlitePlatform('3.46');
        $table = self::table()->withVectorIndexOn('embedding', VectorDistance::Cosine)->withFullTextIndexOn(['title'])->withIndexOn(['title']);

        self::assertCount(1, $platform->getCanonicalTable($table)->getIndexes());
        $sql = $platform->getCreateSchemaSql(new Schema([$table]));
        self::assertCount(2, $sql, implode(' | ', $sql));
        self::assertStringStartsWith('CREATE INDEX "idx_doc_', $sql[1]);
    }

    // ce que la base ne garde pas n'est pas comparé ; une option changée recrée l'index
    public function testComparatorUsesTheCanonicalForm(): void
    {
        $declared = self::table()->withVectorIndexOn('embedding', VectorDistance::Cosine, m: 8, efConstruction: 32, name: 'v')->withFullTextIndexOn(['title'], 'english', name: 'f');
        $readByMariaDb = self::table()
            ->withIndex(new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Cosine, m: 8))
            ->withIndex(new Index('f', ['title'], kind: IndexKind::FullText));
        $mariaDb = new Comparator(new MariaDbPlatform('11.8'));

        self::assertTrue($mariaDb->compare(new Schema([$readByMariaDb]), new Schema([$declared]))->isEmpty());

        $changed = $declared->withIndex(new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Euclidean, m: 8));
        $diff = $mariaDb->compare(new Schema([$readByMariaDb]), new Schema([$changed]));
        self::assertSame([['v'], ['v']], [array_map(static fn (Index $i): string => $i->name, $diff->alteredTables[0]->droppedIndexes), array_map(static fn (Index $i): string => $i->name, $diff->alteredTables[0]->addedIndexes)]);

        // PostgreSQL : les défauts de pgvector et la configuration « simple » valent l'absence d'option
        $postgreSql = new Comparator(new PostgreSqlPlatform('16'));
        $readByPostgreSql = self::table()
            ->withIndex(new Index('v', ['embedding'], kind: IndexKind::Vector, distance: VectorDistance::Cosine, m: 16, efConstruction: 64))
            ->withIndex(new Index('f', ['title'], kind: IndexKind::FullText, language: 'simple'));
        $withDefaults = self::table()->withVectorIndexOn('embedding', VectorDistance::Cosine, name: 'v')->withFullTextIndexOn(['title'], name: 'f');
        self::assertTrue($postgreSql->compare(new Schema([$readByPostgreSql]), new Schema([$withDefaults]))->isEmpty());
    }
}
