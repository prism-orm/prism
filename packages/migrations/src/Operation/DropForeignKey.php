<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Schema;

/**
 * Supprime une clé étrangère, désignée par son nom ou par ses colonnes locales.
 *
 * @internal créée par MigrationBuilder
 */
final class DropForeignKey implements SchemaOperation
{
    /**
     * @param list<string>|string $foreignKey colonnes locales, ou nom
     */
    public function __construct(
        public readonly string $table,
        public readonly array|string $foreignKey,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'dropForeignKey', 'table' => $this->table, 'foreignKey' => $this->foreignKey];
    }

    public function apply(Schema $schema): Schema
    {
        $table = $schema->getTable($this->table);
        foreach ($table->getForeignKeys() as $foreignKey) {
            $matches = \is_string($this->foreignKey)
                ? 0 === strcasecmp($foreignKey->name, $this->foreignKey)
                : array_map(strtolower(...), $foreignKey->localColumns) === array_map(strtolower(...), $this->foreignKey);
            if ($matches) {
                return $schema->withTable($table->withoutForeignKey($foreignKey->name));
            }
        }

        throw InvalidSchemaException::invalidKey('foreign key', \is_string($this->foreignKey) ? $this->foreignKey : implode(', ', $this->foreignKey), \sprintf('no such foreign key on table "%s".', $table->name));
    }
}
