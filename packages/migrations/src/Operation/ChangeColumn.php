<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Schema;

/**
 * Remplace la définition d'une colonne existante (type, nullabilité, défaut).
 *
 * @internal créée par MigrationBuilder
 */
final class ChangeColumn implements SchemaOperation
{
    public function __construct(
        public readonly string $table,
        public readonly Column $column,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'changeColumn', 'table' => $this->table, 'column' => Describe::column($this->column)];
    }

    public function apply(Schema $schema): Schema
    {
        $table = $schema->getTable($this->table);
        $table->getColumn($this->column->name);

        return $schema->withTable($table->withColumn($this->column));
    }
}
