<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tools\SchemaTool;

/**
 * Applique le schéma déduit d'un jeu d'entités, puis le supprime ; seules les tables et
 * séquences de ce schéma sont touchées.
 */
abstract class MappingSchemaTestCase extends TestCase
{
    protected Connection $connection;

    private Schema $target;

    /**
     * @return list<class-string>
     */
    abstract protected static function entities(): array;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->connection = TestDatabase::connection();
        $this->target = new SchemaTool($this->connection->getPlatform())->fromMetadata($this->metadata());
        $this->migrateTo(new Schema());
        $this->migrateTo($this->target);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->migrateTo(new Schema());
            $this->connection->close();
        }
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    protected function metadata(): array
    {
        $driver = new AttributeDriver();

        return array_map($driver->loadMetadata(...), static::entities());
    }

    protected function target(): Schema
    {
        return $this->target;
    }

    // la base réelle, restreinte aux tables et séquences du schéma cible
    protected function actual(): Schema
    {
        $tables = array_map(static fn (Table $t): string => strtolower($t->name), $this->target->getTables());
        $sequences = array_map(static fn (Sequence $s): string => strtolower($s->name), $this->target->getSequences());
        $actual = $this->connection->createSchemaManager()->introspect();

        return new Schema(
            array_values(array_filter($actual->getTables(), static fn (Table $t): bool => \in_array(strtolower($t->name), $tables, true))),
            array_values(array_filter($actual->getSequences(), static fn (Sequence $s): bool => \in_array(strtolower($s->name), $sequences, true))),
        );
    }

    protected function migrateTo(Schema $schema): void
    {
        $this->connection->createSchemaManager()->migrate(new Comparator($this->connection->getPlatform())->compare($this->actual(), $schema));
    }
}
