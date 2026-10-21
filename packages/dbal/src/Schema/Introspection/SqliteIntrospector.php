<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema\Introspection;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\NameGenerator;

/**
 * SQLite (pragmas) : le type déclaré est conservé tel quel, ce qui permet de retrouver le type Prism.
 *
 * @internal
 */
final class SqliteIntrospector extends AbstractIntrospector
{
    private const array TYPES = [
        'VARCHAR' => 'string',
        'TEXT' => 'text',
        'INTEGER' => 'integer',
        'SMALLINT' => 'smallint',
        'BIGINT' => 'bigint',
        'BOOLEAN' => 'boolean',
        'NUMERIC' => 'decimal',
        'DOUBLE PRECISION' => 'float',
        'DATETIME' => 'datetime_immutable',
        'DATE' => 'date_immutable',
        'TIME' => 'time_immutable',
        'JSON_TEXT' => 'json',
        'CHAR' => 'uuid',
        'CHARACTER' => 'string',
        'BLOB' => 'binary',
    ];

    private string $definition = '';

    protected function prepare(string $table): void
    {
        \assert($this->platform instanceof SqlitePlatform);
        $this->definition = self::string($this->connection->executeQuery($this->platform->getTableDefinitionSql(), [$table])->fetchOne());
    }

    protected function column(string $table, array $row): Column
    {
        $name = self::string($row['name']);
        $declaration = strtoupper(trim(self::string($row['data_type'])));

        if (1 !== preg_match('/^([A-Z_ ]+?)\s*(?:\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\))?$/', $declaration, $m) || !isset(self::TYPES[$m[1]])) {
            throw InvalidSchemaException::unsupportedDatabaseType($this->platform->getName(), $table, $name, $declaration);
        }
        $type = self::TYPES[$m[1]];
        $size = isset($m[2]) ? (int) $m[2] : null;
        // CHAR(n) d'un autre outil : une chaîne fixe, sauf CHAR(36), le uuid de Prism
        $fixed = 'CHARACTER' === $m[1] || ('CHAR' === $m[1] && null !== $size && 36 !== $size);
        if ($fixed) {
            $type = 'string';
        }
        $isPrimary = (self::nullableInt($row['primary_position']) ?? 0) > 0;

        return new Column(
            $name,
            $type,
            0 === self::nullableInt($row['not_null']),
            self::typedDefault($type, self::defaultValue(self::nullableString($row['default_value']))),
            'string' === $type ? $size : null,
            'decimal' === $type ? $size : null,
            'decimal' === $type ? (isset($m[3]) ? (int) $m[3] : null) : null,
            // seule une colonne INTEGER PRIMARY KEY peut porter AUTOINCREMENT
            $isPrimary && 'integer' === $type && 1 === preg_match('/\bAUTOINCREMENT\b/i', $this->definition),
            $fixed && null !== $size,
        );
    }

    /**
     * Le pragma ne donne pas le nom des contraintes : il est relu dans le CREATE TABLE.
     */
    protected function foreignKeys(string $table): array
    {
        $names = [];
        preg_match_all('/CONSTRAINT\s+("(?:[^"]|"")+"|\S+)\s+FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+("(?:[^"]|"")+"|\S+)/i', $this->definition, $matches, \PREG_SET_ORDER);
        foreach ($matches as $m) {
            $names[self::key(self::identifier($m[3]), array_map(self::identifier(...), explode(',', $m[2])))] = self::identifier($m[1]);
        }

        $grouped = [];
        foreach ($this->connection->executeQuery($this->platform->getListForeignKeysSql(), [$table])->fetchAllAssociative() as $row) {
            $id = self::string($row['id']);
            $grouped[$id]['local'][] = self::string($row['local_column']);
            $grouped[$id]['foreign'][] = self::string($row['foreign_column']);
            $grouped[$id]['table'] = self::string($row['foreign_table']);
            $grouped[$id]['action'] = self::string($row['on_delete']);
        }

        $foreignKeys = [];
        foreach (array_reverse($grouped, true) as $fk) {
            $name = $names[self::key($fk['table'], $fk['local'])] ?? NameGenerator::generate('fk', $table, $fk['local']);
            $foreignKeys[] = new ForeignKey($name, $fk['local'], $fk['table'], $fk['foreign'], self::action($table, $name, $fk['action']));
        }

        return $foreignKeys;
    }

    private static function defaultValue(?string $raw): ?string
    {
        return null === $raw || !self::isQuoted($raw) ? $raw : self::unquote($raw);
    }

    private static function identifier(string $quoted): string
    {
        $quoted = trim($quoted);

        return str_starts_with($quoted, '"') ? str_replace('""', '"', substr($quoted, 1, -1)) : $quoted;
    }

    /**
     * @param list<string> $columns
     */
    private static function key(string $table, array $columns): string
    {
        return strtolower($table) . '|' . strtolower(implode(',', $columns));
    }
}
