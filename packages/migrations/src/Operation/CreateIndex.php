<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\NameGenerator;
use Prism\Dbal\Schema\Schema;

/**
 * Crée un index ; nommé de façon déterministe (RG-01.7) sans nom explicite. Concurrently :
 * sans verrou des écritures sous PostgreSQL, hors transaction (RG-06.5) ; sans effet ailleurs.
 * whereNull : index partiel, sur les lignes où cette colonne est NULL (RG-08.15, ADR 0015).
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
        public readonly ?string $whereNull = null,
    ) {
    }

    public function describe(): array
    {
        // concurrently seulement s'il est demandé : les checksums d'avant restent valables
        return ['operation' => 'createIndex', 'table' => $this->table, 'columns' => $this->columns, 'unique' => $this->unique, 'name' => $this->name]
            + ($this->concurrently ? ['concurrently' => true] : [])
            + (null === $this->whereNull ? [] : ['whereNull' => $this->whereNull]);
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withTable($schema->getTable($this->table)->withIndexOn($this->columns, $this->unique, $this->name, $this->whereNull));
    }

    /**
     * L'index tel qu'il sera créé, nommé de façon déterministe (RG-01.7).
     */
    public function index(): Index
    {
        return new Index($this->name ?? NameGenerator::index($this->table, $this->columns, $this->unique, $this->whereNull), $this->columns, $this->unique, $this->whereNull);
    }
}
