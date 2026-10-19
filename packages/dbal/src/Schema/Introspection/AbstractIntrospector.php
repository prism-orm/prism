<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema\Introspection;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Table;

/**
 * Lecture commune des lignes du catalogue ; chaque plateforme décode ses colonnes.
 *
 * Colonnes attendues des requêtes de la Platform :
 * - colonnes : name, data_type, default_value, primary_position, plus celles de la plateforme ;
 * - index : name, column_name, non_unique ;
 * - clés étrangères : name, local_column, foreign_table, foreign_column, on_delete.
 *
 * @internal
 */
abstract class AbstractIntrospector implements Introspector
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly AbstractPlatform $platform,
    ) {
    }

    public function listTableNames(): array
    {
        return array_map(self::string(...), $this->connection->executeQuery($this->platform->getListTablesSql())->fetchFirstColumn());
    }

    public function listSequences(): array
    {
        return [];
    }

    public function introspectTable(string $name): Table
    {
        $rows = $this->connection->executeQuery($this->platform->getListColumnsSql(), [$name])->fetchAllAssociative();
        if ([] === $rows) {
            throw InvalidSchemaException::unknownTable($name);
        }
        $this->prepare($name);

        $columns = [];
        $primaryKey = [];
        foreach ($rows as $row) {
            $column = $this->column($name, $row);
            $columns[] = $column;
            $position = self::nullableInt($row['primary_position'] ?? null);
            if (null !== $position && $position > 0) {
                $primaryKey[$position] = $column->name;
            }
        }
        ksort($primaryKey);

        return $this->finish(new Table($name, $columns, [] === $primaryKey ? null : array_values($primaryKey), $this->indexes($name), $this->foreignKeys($name)));
    }

    /**
     * Point d'accroche avant la lecture des colonnes (contraintes à charger par table).
     */
    protected function prepare(string $table): void
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    abstract protected function column(string $table, array $row): Column;

    /**
     * Retouche finale (index implicites à retirer…).
     */
    protected function finish(Table $table): Table
    {
        return $table;
    }

    /**
     * @return list<Index>
     */
    protected function indexes(string $table): array
    {
        $grouped = [];
        foreach ($this->connection->executeQuery($this->platform->getListIndexesSql(), [$table])->fetchAllAssociative() as $row) {
            $name = self::string($row['name']);
            $grouped[$name]['columns'][] = self::string($row['column_name']);
            $grouped[$name]['unique'] = 0 === self::nullableInt($row['non_unique']);
        }

        return array_map(static fn (array $index, string $name): Index => new Index($name, $index['columns'], $index['unique']), $grouped, array_keys($grouped));
    }

    /**
     * @return list<ForeignKey>
     */
    protected function foreignKeys(string $table): array
    {
        $grouped = [];
        foreach ($this->connection->executeQuery($this->platform->getListForeignKeysSql(), [$table])->fetchAllAssociative() as $row) {
            $name = self::string($row['name']);
            $grouped[$name]['local'][] = self::string($row['local_column']);
            $grouped[$name]['foreign'][] = self::string($row['foreign_column']);
            $grouped[$name]['table'] = self::string($row['foreign_table']);
            $grouped[$name]['action'] = self::string($row['on_delete']);
        }

        $foreignKeys = [];
        foreach ($grouped as $name => $fk) {
            $foreignKeys[] = new ForeignKey($name, $fk['local'], $fk['table'], $fk['foreign'], self::action($table, $name, $fk['action']));
        }

        return $foreignKeys;
    }

    /**
     * Valeur par défaut, déjà débarrassée de la syntaxe propre à la plateforme, typée selon la colonne.
     */
    protected static function typedDefault(string $type, ?string $value): string|int|float|bool|null
    {
        if (null === $value) {
            return null;
        }

        return match (true) {
            \in_array($type, ['integer', 'smallint', 'bigint'], true) && false !== filter_var($value, \FILTER_VALIDATE_INT) => (int) $value,
            'boolean' === $type && \in_array(strtolower($value), ['1', 'true', 't'], true) => true,
            'boolean' === $type && \in_array(strtolower($value), ['0', 'false', 'f'], true) => false,
            'float' === $type && is_numeric($value) => (float) $value,
            default => $value,
        };
    }

    /**
     * Retire les apostrophes d'un littéral SQL standard ('O''Reilly' → O'Reilly).
     */
    protected static function unquote(string $literal): string
    {
        return str_replace("''", "'", substr($literal, 1, -1));
    }

    protected static function isQuoted(string $value): bool
    {
        return \strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'");
    }

    protected static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    protected static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }

    protected static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected static function action(string $table, string $name, string $rule): ForeignKeyAction
    {
        return ForeignKeyAction::tryFrom(strtoupper($rule))
            ?? throw InvalidSchemaException::invalidKey('foreign key', $table . '.' . $name, \sprintf('ON DELETE %s is not supported by Prism.', $rule));
    }
}
