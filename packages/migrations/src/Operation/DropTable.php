<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;

/**
 * Supprime une table et ses données.
 *
 * @internal créée par MigrationBuilder
 */
final class DropTable implements SchemaOperation
{
    public function __construct(
        public readonly string $table,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'dropTable', 'table' => $this->table];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withoutTable($schema->getTable($this->table)->name);
    }
}
