<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Platform\SqliteVectorFunctions;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;

/**
 * Le SQL des vecteurs par plateforme (ADR 0017) : déclaration, distances, extension pgvector,
 * refus sous MySQL ; distances de SQLite calculées en PHP.
 */
final class VectorSqlTest extends TestCase
{
    private const string PGVECTOR_CHECK = "DO $$ BEGIN IF string_to_array((SELECT extversion FROM pg_extension WHERE extname = 'vector'), '.')::int[] < ARRAY[0, 8] THEN RAISE EXCEPTION 'Prism needs pgvector 0.8 or later, found %', (SELECT extversion FROM pg_extension WHERE extname = 'vector'); END IF; END $$";

    private static function table(bool $nullable = false): Table
    {
        return new Table('doc', [new Column('id', 'integer'), new Column('embedding', 'vector', nullable: $nullable, length: 768)], ['id']);
    }

    /**
     * @return iterable<string, array{AbstractPlatform, non-empty-string}>
     */
    public static function declarations(): iterable
    {
        yield 'MariaDB' => [new MariaDbPlatform('11.8'), 'CREATE TABLE `doc` (`id` INT NOT NULL, `embedding` VECTOR(768) NOT NULL, PRIMARY KEY (`id`))'];
        yield 'PostgreSQL' => [new PostgreSqlPlatform('16'), 'CREATE TABLE "doc" ("id" INTEGER NOT NULL, "embedding" vector(768) NOT NULL, PRIMARY KEY ("id"))'];
        yield 'SQLite' => [new SqlitePlatform('3.46'), 'CREATE TABLE "doc" ("id" INTEGER NOT NULL, "embedding" VECTOR_TEXT(768) NOT NULL, PRIMARY KEY ("id"))'];
    }

    /**
     * @param non-empty-string $expected
     */
    #[DataProvider('declarations')]
    public function testColumnDeclaration(AbstractPlatform $platform, string $expected): void
    {
        self::assertTrue($platform->supportsVectors());
        self::assertStringStartsWith($expected, $platform->getCreateTableSql(self::table()));
    }

    public function testMySqlRefusesTheColumn(): void
    {
        $platform = new MySqlPlatform('8.4');
        self::assertFalse($platform->supportsVectors());

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('MySQL does not support the vector type');

        $platform->getCreateTableSql(self::table());
    }

    public function testAVectorNeedsItsDimensionsAndHasNoDefault(): void
    {
        try {
            new Column('embedding', 'vector');
            self::fail('A vector without dimensions was accepted.');
        } catch (InvalidSchemaException $e) {
            self::assertStringContainsString('a vector column needs its dimensions as length', $e->getMessage());
        }

        self::assertFalse(Column::acceptsDefault('vector'));
    }

    /**
     * @return iterable<string, array{AbstractPlatform, VectorDistance, string}>
     */
    public static function distances(): iterable
    {
        $mariaDb = new MariaDbPlatform('11.8');
        $postgreSql = new PostgreSqlPlatform('16');
        $sqlite = new SqlitePlatform('3.46');

        yield 'MariaDB cosine' => [$mariaDb, VectorDistance::Cosine, 'VEC_DISTANCE_COSINE(d.embedding, VEC_FromText(:v))'];
        yield 'MariaDB euclidean' => [$mariaDb, VectorDistance::Euclidean, 'VEC_DISTANCE_EUCLIDEAN(d.embedding, VEC_FromText(:v))'];
        yield 'PostgreSQL cosine' => [$postgreSql, VectorDistance::Cosine, '(d.embedding <=> CAST(:v AS vector))'];
        yield 'PostgreSQL euclidean' => [$postgreSql, VectorDistance::Euclidean, '(d.embedding <-> CAST(:v AS vector))'];
        yield 'PostgreSQL inner product' => [$postgreSql, VectorDistance::InnerProduct, '(d.embedding <#> CAST(:v AS vector))'];
        yield 'SQLite cosine' => [$sqlite, VectorDistance::Cosine, 'prism_vec_cosine(d.embedding, :v)'];
        yield 'SQLite euclidean' => [$sqlite, VectorDistance::Euclidean, 'prism_vec_euclidean(d.embedding, :v)'];
        yield 'SQLite inner product' => [$sqlite, VectorDistance::InnerProduct, 'prism_vec_inner(d.embedding, :v)'];
    }

    #[DataProvider('distances')]
    public function testDistanceSql(AbstractPlatform $platform, VectorDistance $distance, string $expected): void
    {
        self::assertSame($expected, $platform->getVectorDistanceSql($distance, 'd.embedding', $platform->getVectorParameterSql(':v')));
    }

    // relance exacte (ADR 0017) : MariaDB n'utilise pas son index sur une distance enveloppée
    public function testExactDistance(): void
    {
        $mariaDb = new MariaDbPlatform('11.8');
        $postgreSql = new PostgreSqlPlatform('16');

        self::assertTrue($mariaDb->vectorIndexMayMissRows());
        self::assertSame('(VEC_DISTANCE_COSINE(e, VEC_FromText(:v)) + 0)', $mariaDb->getExactVectorDistanceSql(VectorDistance::Cosine, 'e', 'VEC_FromText(:v)'));
        self::assertFalse($postgreSql->vectorIndexMayMissRows());
        self::assertSame('(e <=> :v)', $postgreSql->getExactVectorDistanceSql(VectorDistance::Cosine, 'e', ':v'));
    }

    // RG-09.3 : refusé par la plateforme ; le validateur du mapping le refusera plus tôt (étape 3)
    public function testMariaDbHasNoInnerProduct(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('MariaDB does not support the inner product distance');

        new MariaDbPlatform('11.8')->getVectorDistanceSql(VectorDistance::InnerProduct, 'e', ':v');
    }

    public function testMySqlHasNoDistance(): void
    {
        $this->expectException(InvalidSchemaException::class);

        new MySqlPlatform('8.4')->getVectorDistanceSql(VectorDistance::Cosine, 'e', ':v');
    }

    public function testPostgreSqlCreatesTheExtensionBeforeAVectorTable(): void
    {
        $platform = new PostgreSqlPlatform('16');

        $sql = $platform->getCreateSchemaSql(new Schema([self::table()]));
        self::assertSame(['CREATE EXTENSION IF NOT EXISTS vector', self::PGVECTOR_CHECK], \array_slice($sql, 0, 2));

        $without = $platform->getCreateSchemaSql(new Schema([new Table('note', [new Column('id', 'integer')], ['id'])]));
        self::assertNotContains('CREATE EXTENSION IF NOT EXISTS vector', $without);
    }

    public function testPostgreSqlMigrationCreatesTheExtensionOnlyForANewVector(): void
    {
        $platform = new PostgreSqlPlatform('16');
        $comparator = new Comparator($platform);
        $before = new Table('doc', [new Column('id', 'integer'), new Column('title', 'string', length: 100)], ['id']);
        $after = $before->withColumn(new Column('embedding', 'vector', nullable: true, length: 3));

        $sql = $platform->getMigrationSql($comparator->compare(new Schema([$before]), new Schema([$after])));
        self::assertSame(['CREATE EXTENSION IF NOT EXISTS vector', self::PGVECTOR_CHECK, 'ALTER TABLE "doc" ADD COLUMN "embedding" vector(3) NULL'], $sql);

        // une table qui a déjà son vecteur : rien à préparer
        $renamed = $after->withColumn(new Column('title', 'string', length: 200));
        $sql = $platform->getMigrationSql($comparator->compare(new Schema([$after]), new Schema([$renamed])));
        self::assertNotContains('CREATE EXTENSION IF NOT EXISTS vector', $sql);
    }

    public function testSqliteDistancesAreComputedInPhp(): void
    {
        $functions = SqliteVectorFunctions::all();

        self::assertSame(['prism_vec_cosine', 'prism_vec_euclidean', 'prism_vec_inner', 'prism_fts_score'], array_keys($functions));
        self::assertEqualsWithDelta(0.0, SqliteVectorFunctions::cosine('[1.0,2.0]', '[2.0,4.0]'), 1e-12);
        self::assertEqualsWithDelta(1.0, SqliteVectorFunctions::cosine('[1.0,0.0]', '[0.0,1.0]'), 1e-12);
        self::assertEqualsWithDelta(2.0, SqliteVectorFunctions::cosine('[1.0,0.0]', '[-1.0,0.0]'), 1e-12);
        self::assertEqualsWithDelta(5.0, SqliteVectorFunctions::euclidean('[0.0,0.0]', '[3.0,4.0]'), 1e-12);
        self::assertEqualsWithDelta(-11.0, SqliteVectorFunctions::inner('[1.0,2.0]', '[3.0,4.0]'), 1e-12);
    }

    public function testSqliteDistanceOfNullOrZeroVectorIsNull(): void
    {
        self::assertNull(SqliteVectorFunctions::cosine(null, '[1.0]'));
        self::assertNull(SqliteVectorFunctions::euclidean('[1.0]', null));
        self::assertNull(SqliteVectorFunctions::cosine('[0.0,0.0]', '[1.0,2.0]'));
    }

    public function testSqliteRefusesVectorsOfDifferentDimensions(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('two vectors of the same dimension');

        SqliteVectorFunctions::euclidean('[1.0,2.0]', '[1.0,2.0,3.0]');
    }
}
