<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;

/**
 * Crée un index ; nommé de façon déterministe (RG-01.7) sans nom explicite.
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
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'createIndex', 'table' => $this->table, 'columns' => $this->columns, 'unique' => $this->unique, 'name' => $this->name];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withTable($schema->getTable($this->table)->withIndexOn($this->columns, $this->unique, $this->name));
    }
}
