<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\NameGenerator;
use Prism\Dbal\Schema\Schema;

/**
 * Crée un index ; nommé de façon déterministe (RG-01.7) sans nom explicite. Concurrently :
 * sans verrou des écritures sous PostgreSQL, hors transaction (RG-06.5) ; sans effet ailleurs.
 *
 * @internal créée par MigrationBuilder
 */
final class CreateIndex implements SchemaOperation
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly bool $unique = false,
        public readonly ?string $name = null,
        public readonly bool $concurrently = false,
    ) {
    }

    public function describe(): array
    {
        // concurrently seulement s'il est demandé : les checksums d'avant restent valables
        return ['operation' => 'createIndex', 'table' => $this->table, 'columns' => $this->columns, 'unique' => $this->unique, 'name' => $this->name] + ($this->concurrently ? ['concurrently' => true] : []);
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withTable($schema->getTable($this->table)->withIndexOn($this->columns, $this->unique, $this->name));
    }

    /**
     * L'index tel qu'il sera créé, nommé de façon déterministe (RG-01.7).
     */
    public function index(): Index
    {
        return new Index($this->name ?? NameGenerator::generate($this->unique ? 'uniq' : 'idx', $this->table, $this->columns), $this->columns, $this->unique);
    }
}
