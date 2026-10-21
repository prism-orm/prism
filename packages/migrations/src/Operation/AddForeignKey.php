<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Schema;

/**
 * Ajoute une clé étrangère ; nommée de façon déterministe (RG-01.7) sans nom explicite.
 *
 * @internal créée par MigrationBuilder
 */
final class AddForeignKey implements SchemaOperation
{
    /**
     * @param list<string> $localColumns
     * @param list<string> $foreignColumns
     */
    public function __construct(
        public readonly string $table,
        public readonly array $localColumns,
        public readonly string $foreignTable,
        public readonly array $foreignColumns,
        public readonly ForeignKeyAction $onDelete = ForeignKeyAction::NoAction,
        public readonly ?string $name = null,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'addForeignKey', 'table' => $this->table, 'localColumns' => $this->localColumns, 'foreignTable' => $this->foreignTable, 'foreignColumns' => $this->foreignColumns, 'onDelete' => $this->onDelete->name, 'name' => $this->name];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withTable($schema->getTable($this->table)->withForeignKeyTo($this->localColumns, $this->foreignTable, $this->foreignColumns, $this->onDelete, $this->name));
    }
}
