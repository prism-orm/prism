<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;

/**
 * Remplace la clé primaire d'une table ; null la retire.
 *
 * @internal créée par MigrationBuilder
 */
final class ChangePrimaryKey implements SchemaOperation
{
    /**
     * @param list<string>|null $columns
     */
    public function __construct(
        public readonly string $table,
        public readonly ?array $columns,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'changePrimaryKey', 'table' => $this->table, 'columns' => $this->columns];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withTable($schema->getTable($this->table)->withPrimaryKey($this->columns));
    }
}
