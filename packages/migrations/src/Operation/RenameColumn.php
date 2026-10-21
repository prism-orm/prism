<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;

/**
 * Renomme une colonne : ses données, index et clés étrangères la suivent. Écrite par le SQL
 * de renommage de la Platform, jamais par un diff (RG-01.9).
 *
 * @internal créée par MigrationBuilder
 */
final class RenameColumn implements SchemaOperation
{
    public function __construct(
        public readonly string $table,
        public readonly string $from,
        public readonly string $to,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'renameColumn', 'table' => $this->table, 'from' => $this->from, 'to' => $this->to];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withRenamedColumn($this->table, $this->from, $this->to);
    }
}
