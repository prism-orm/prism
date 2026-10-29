<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;

/**
 * Type vector sur les 4 bases (ADR 0017) : écriture par le QueryBuilder (SQL du paramètre propre
 * à la plateforme), lecture, introspection sans diff (RG-01.8), ajout par migration, distances ;
 * refus sous MySQL.
 */
final class VectorTest extends SchemaTestCase
{
    private static function table(): Table
    {
        return new Table('vector_doc', [
            new Column('id', 'integer'),
            new Column('embedding', 'vector', length: 3),
            new Column('draft', 'vector', nullable: true, length: 3),
        ], ['id']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->connection->getPlatform()->supportsVectors()) {
            self::assertSame('MySQL', $this->connection->getPlatform()->getName());
        }
    }

    /**
     * @param list<float>      $embedding
     * @param list<float>|null $draft
     */
    private function insert(int $id, array $embedding, ?array $draft = null): void
    {
        $this->connection->createQueryBuilder()->insert('vector_doc')
            ->set('id', $id)
            ->set('embedding', $embedding, 'vector')
            ->set('draft', $draft, 'vector')
            ->executeStatement();
    }

    public function testMySqlRefusesTheType(): void
    {
        if ($this->connection->getPlatform()->supportsVectors()) {
            self::markTestSkipped('MySQL only.');
        }

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('MySQL does not support the vector type');

        $this->create(new Schema([self::table()]));
    }

    public function testRoundTrip(): void
    {
        $this->skipWithoutVectors();
        $this->create(new Schema([self::table()]));
        $this->insert(1, [0.5, -1.25, 3.0], null);
        $this->insert(2, [0.1, 0.2, 0.3], [1.0, 0.0, 0.0]);

        $rows = $this->connection->createQueryBuilder()->select('id', 'embedding', 'draft')->from('vector_doc')->orderBy('id')->executeQuery()->fetchAllAssociative();

        self::assertSame([0.5, -1.25, 3.0], $this->connection->convertToPhp($rows[0]['embedding'], 'vector'));
        self::assertNull($this->connection->convertToPhp($rows[0]['draft'], 'vector'));
        // 0.1 n'existe pas en float32 : relu à la précision de la base (MariaDB stocke en float32)
        $read = $this->connection->convertToPhp($rows[1]['embedding'], 'vector');
        self::assertIsArray($read);
        self::assertEqualsWithDelta([0.1, 0.2, 0.3], $read, 1e-6);
        self::assertSame([1.0, 0.0, 0.0], $this->connection->convertToPhp($rows[1]['draft'], 'vector'));
    }

    // une mise à jour passe aussi par le SQL du paramètre
    public function testUpdate(): void
    {
        $this->skipWithoutVectors();
        $this->create(new Schema([self::table()]));
        $this->insert(1, [1.0, 1.0, 1.0]);

        $qb = $this->connection->createQueryBuilder();
        $qb->update('vector_doc')->set('embedding', [2.0, 4.0, 8.0], 'vector')->where($qb->expr()->eq('id', 1))->executeStatement();

        $raw = $this->connection->createQueryBuilder()->select('embedding')->from('vector_doc')->executeQuery()->fetchOne();
        self::assertSame([2.0, 4.0, 8.0], $this->connection->convertToPhp($raw, 'vector'));
    }

    // RG-01.8 : relu avec sa dimension, aucun diff
    public function testIntrospectionRoundTrip(): void
    {
        $this->skipWithoutVectors();
        $this->create(new Schema([self::table()]));

        $actual = $this->connection->createSchemaManager()->introspectTable('vector_doc');

        self::assertSame(['vector', 3, false], [$actual->getColumn('embedding')->type, $actual->getColumn('embedding')->length, $actual->getColumn('embedding')->nullable]);
        $diff = new Comparator($this->connection->getPlatform())->compare(new Schema([$actual]), new Schema([self::table()]));
        self::assertTrue($diff->isEmpty(), implode(' | ', $this->connection->getPlatform()->getMigrationSql($diff)));
    }

    // une colonne ajoutée par migration (pgvector : extension préparée), puis une dimension changée
    public function testMigrationAddsAndResizesAVector(): void
    {
        $this->skipWithoutVectors();
        $before = new Table('vector_doc', [new Column('id', 'integer')], ['id']);
        $this->create(new Schema([$before]));
        $manager = $this->connection->createSchemaManager();

        $added = $before->withColumn(new Column('embedding', 'vector', nullable: true, length: 3));
        $manager->migrate(new Comparator($this->connection->getPlatform())->compare(new Schema([$manager->introspectTable('vector_doc')]), new Schema([$added])));
        self::assertSame(3, $manager->introspectTable('vector_doc')->getColumn('embedding')->length);

        $resized = $before->withColumn(new Column('embedding', 'vector', nullable: true, length: 5));
        $manager->migrate(new Comparator($this->connection->getPlatform())->compare(new Schema([$manager->introspectTable('vector_doc')]), new Schema([$resized])));
        $actual = $manager->introspectTable('vector_doc');
        self::assertSame(5, $actual->getColumn('embedding')->length);
        self::assertTrue(new Comparator($this->connection->getPlatform())->compare(new Schema([$actual]), new Schema([$resized]))->isEmpty());
    }

    /**
     * @return iterable<string, array{VectorDistance, list<int>}>
     */
    public static function distances(): iterable
    {
        // requête [1, 0, 0] contre 1 = [3, 0, 0], 2 = [0, 2, 0], 3 = [-0.5, 0, 0]
        // cosinus : même direction, orthogonal, opposé ; euclidienne : 1,5 (3), 2 (1), 2,24 (2)
        yield 'cosine' => [VectorDistance::Cosine, [1, 2, 3]];
        yield 'euclidean' => [VectorDistance::Euclidean, [3, 1, 2]];
        yield 'inner product' => [VectorDistance::InnerProduct, [1, 2, 3]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('distances')]
    public function testDistanceOrdersTheRows(VectorDistance $distance, array $expected): void
    {
        $this->skipWithoutVectors();
        $platform = $this->connection->getPlatform();
        if (VectorDistance::InnerProduct === $distance && 'MariaDB' === $platform->getName()) {
            self::markTestSkipped('MariaDB has no inner product distance.');
        }
        $this->create(new Schema([self::table()]));
        $this->insert(1, [3.0, 0.0, 0.0]);
        $this->insert(2, [0.0, 2.0, 0.0]);
        $this->insert(3, [-0.5, 0.0, 0.0]);

        $sql = $platform->getVectorDistanceSql($distance, $platform->quoteIdentifier('embedding'), $platform->getVectorParameterSql(':q'));
        $rows = $this->connection->executeQuery(
            \sprintf('SELECT id, %s AS d FROM %s ORDER BY d LIMIT 3', $sql, $platform->quoteIdentifier('vector_doc')),
            ['q' => [1.0, 0.0, 0.0]],
            ['q' => 'vector'],
        )->fetchAllAssociative();

        self::assertSame($expected, array_map(static fn (array $row): int => is_numeric($row['id']) ? (int) $row['id'] : -1, $rows));
        if (VectorDistance::Cosine === $distance) {
            self::assertEqualsWithDelta([0.0, 1.0, 2.0], array_map(static fn (array $row): float => is_numeric($row['d']) ? (float) $row['d'] : \NAN, $rows), 1e-6);
        }
    }

    private function skipWithoutVectors(): void
    {
        if (!$this->connection->getPlatform()->supportsVectors()) {
            self::markTestSkipped('MySQL has no vector type.');
        }
    }
}
