<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Tests\Support\ReferenceSchemas;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * Crée des schémas de référence et les supprime après le test, références croisées comprises.
 */
abstract class SchemaTestCase extends TestCase
{
    protected Connection $connection;

    /** @var list<string> tables créées, à supprimer dans l'ordre inverse */
    private array $created = [];

    /** @var list<string> */
    private array $sequences = [];

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->connection = TestDatabase::connection();
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }

        $platform = $this->connection->getPlatform();
        if ($platform->supportsSequences()) {
            foreach ($this->sequences as $sequence) {
                $this->connection->executeStatement($platform->getDropSequenceSql($sequence));
            }
        }
        $this->dropTables();
        $this->connection->close();
    }

    protected function create(Schema $schema): void
    {
        foreach ($schema->getTables() as $table) {
            $this->created[] = $table->name;
        }
        foreach ($schema->getSequences() as $sequence) {
            $this->sequences[] = $sequence->name;
        }
        $this->dropTables();

        foreach ($this->connection->getPlatform()->getCreateSchemaSql($schema) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    private function dropTables(): void
    {
        $platform = $this->connection->getPlatform();
        // MySQL / PostgreSQL : retirer les clés étrangères d'abord (références croisées)
        if ('SQLite' !== $platform->getName()) {
            foreach (array_reverse($this->created) as $table) {
                foreach ($this->foreignKeysOf($table) as $foreignKey) {
                    $this->connection->executeStatement($platform->getDropForeignKeySql($table, $foreignKey));
                }
            }
        }
        foreach (array_reverse($this->created) as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->q($table));
        }
    }

    /**
     * @return list<string>
     */
    private function foreignKeysOf(string $table): array
    {
        $names = [];
        foreach (ReferenceSchemas::all(true) as $schema) {
            if ($schema->hasTable($table) && $this->tableExists($table)) {
                foreach ($schema->getTable($table)->getForeignKeys() as $foreignKey) {
                    $names[] = $foreignKey->name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->rowsIn($table);

            return true;
        } catch (QueryFailedException) {
            return false;
        }
    }

    protected function rowsIn(string $table): mixed
    {
        return $this->connection->executeQuery('SELECT COUNT(*) FROM ' . $this->q($table))->fetchOne();
    }

    protected function q(string $name): string
    {
        return $this->connection->getPlatform()->quoteIdentifier($name);
    }
}
