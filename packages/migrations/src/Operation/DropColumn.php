<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;

/**
 * Supprime une colonne et ses données.
 *
 * @internal créée par MigrationBuilder
 */
final class DropColumn implements SchemaOperation
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'dropColumn', 'table' => $this->table, 'column' => $this->column];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withTable($schema->getTable($this->table)->withoutColumn($this->column));
    }
}
