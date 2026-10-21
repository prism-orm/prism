<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\ReferenceSchemas;

/**
 * Amorce de RG-01.8 : introspect(create(S)) == S, sous la forme canonique de la plateforme.
 */
final class IntrospectionTest extends SchemaTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function schemaNames(): iterable
    {
        foreach (array_keys(ReferenceSchemas::all(true)) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('schemaNames')]
    public function testIntrospectedSchemaEqualsTheCreatedOne(string $name): void
    {
        $platform = $this->connection->getPlatform();
        $schemas = ReferenceSchemas::all($platform->supportsSequences());
        if (!isset($schemas[$name])) {
            self::markTestSkipped(\sprintf('%s does not support sequences.', $platform->getName()));
        }
        $expected = $schemas[$name];

        $this->create($expected);
        $actual = $this->connection->createSchemaManager()->introspect();

        foreach ($expected->getTables() as $table) {
            self::assertTrue($actual->hasTable($table->name), \sprintf('Table "%s" was not introspected.', $table->name));
            self::assertTableEquals($platform->getCanonicalTable($table), $actual->getTable($table->name));
        }

        foreach ($expected->getSequences() as $sequence) {
            $found = array_values(array_filter($actual->getSequences(), static fn ($s): bool => $s->name === $sequence->name));
            self::assertEquals([$sequence], $found);
        }
    }

    // les séquences des colonnes d'identité appartiennent à leur colonne, pas au schéma
    public function testIdentitySequencesAreNotListed(): void
    {
        $this->create(ReferenceSchemas::all(false)['autoincrement primary key']);

        self::assertSame([], array_filter(
            $this->connection->createSchemaManager()->introspect()->getSequences(),
            static fn ($s): bool => str_starts_with($s->name, 'ref_auto'),
        ));
    }

    public function testUnknownTable(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('The schema has no table "ref_missing"');

        $this->connection->createSchemaManager()->introspectTable('ref_missing');
    }

    public function testUnsupportedColumnTypeIsReportedWithItsLocation(): void
    {
        $this->create(ReferenceSchemas::all(false)['autoincrement primary key']);
        // un type sans équivalent Prism sur chaque plateforme
        $legacy = 'PostgreSQL' === $this->connection->getPlatform()->getName() ? 'POINT' : 'GEOMETRY';
        $this->connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN %s %s', $this->q('ref_auto'), $this->q('legacy'), $legacy));

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('Cannot introspect column "ref_auto.legacy"');

        $this->connection->createSchemaManager()->introspectTable('ref_auto');
    }

    // comparaison champ par champ : un message d'échec lisible vaut mieux qu'un dump d'objets
    private static function assertTableEquals(Table $expected, Table $actual): void
    {
        $describe = static fn (Column $c): array => [$c->name, $c->type, $c->nullable, $c->default, $c->length, $c->precision, $c->scale, $c->autoincrement, $c->fixed];
        $indexes = static function (Table $t): array {
            $all = array_map(static fn (Index $i): array => [$i->name, $i->columns, $i->unique], $t->getIndexes());
            sort($all);

            return $all;
        };
        $foreignKeys = static function (Table $t): array {
            $all = array_map(static fn ($f): array => [$f->name, $f->localColumns, $f->foreignTable, $f->foreignColumns, $f->onDelete], $t->getForeignKeys());
            sort($all);

            return $all;
        };

        self::assertSame(array_map($describe, $expected->getColumns()), array_map($describe, $actual->getColumns()), $expected->name . ': columns');
        self::assertSame($expected->primaryKey, $actual->primaryKey, $expected->name . ': primary key');
        self::assertSame($indexes($expected), $indexes($actual), $expected->name . ': indexes');
        self::assertEquals($foreignKeys($expected), $foreignKeys($actual), $expected->name . ': foreign keys');
    }
}
