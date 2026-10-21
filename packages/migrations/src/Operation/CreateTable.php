<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;

/**
 * Crée une table, avec ses index et clés étrangères.
 *
 * @internal créée par MigrationBuilder
 */
final class CreateTable implements SchemaOperation
{
    public function __construct(
        public readonly Table $table,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'createTable', 'table' => Describe::table($this->table)];
    }

    public function apply(Schema $schema): Schema
    {
        if ($schema->hasTable($this->table->name)) {
            throw InvalidSchemaException::duplicateTable($this->table->name);
        }

        return $schema->withTable($this->table);
    }
}
