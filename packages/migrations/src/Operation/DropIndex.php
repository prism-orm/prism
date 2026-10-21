<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Schema;

/**
 * Supprime un index, désigné par son nom ou par ses colonnes.
 *
 * @internal créée par MigrationBuilder
 */
final class DropIndex implements SchemaOperation
{
    /**
     * @param list<string>|string $index colonnes, ou nom
     */
    public function __construct(
        public readonly string $table,
        public readonly array|string $index,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'dropIndex', 'table' => $this->table, 'index' => $this->index];
    }

    public function apply(Schema $schema): Schema
    {
        $table = $schema->getTable($this->table);
        foreach ($table->getIndexes() as $index) {
            if (\is_string($this->index) ? 0 === strcasecmp($index->name, $this->index) : self::sameColumns($index, $this->index)) {
                return $schema->withTable($table->withoutIndex($index->name));
            }
        }

        throw InvalidSchemaException::invalidKey('index', \is_string($this->index) ? $this->index : implode(', ', $this->index), \sprintf('no such index on table "%s".', $table->name));
    }

    /**
     * @param list<string> $columns
     */
    private static function sameColumns(Index $index, array $columns): bool
    {
        return array_map(strtolower(...), $index->columns) === array_map(strtolower(...), $columns);
    }
}
