<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Schema;

/**
 * Ajoute une colonne.
 *
 * @internal créée par MigrationBuilder
 */
final class AddColumn implements SchemaOperation
{
    public function __construct(
        public readonly string $table,
        public readonly Column $column,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'addColumn', 'table' => $this->table, 'column' => Describe::column($this->column)];
    }

    public function apply(Schema $schema): Schema
    {
        $table = $schema->getTable($this->table);
        if ($table->hasColumn($this->column->name)) {
            throw InvalidSchemaException::duplicate('column', $this->column->name, $table->name);
        }

        return $schema->withTable($table->withColumn($this->column));
    }
}
