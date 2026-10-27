<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema\Introspection;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\VectorDistance;

/**
 * PostgreSQL (information_schema et pg_catalog), schéma courant uniquement.
 *
 * @internal
 */
final class PostgreSqlIntrospector extends AbstractIntrospector
{
    private const array TYPES = [
        'varchar' => 'string',
        'bpchar' => 'string',
        'text' => 'text',
        'int4' => 'integer',
        'int2' => 'smallint',
        'int8' => 'bigint',
        'bool' => 'boolean',
        'numeric' => 'decimal',
        'float8' => 'float',
        'timestamp' => 'datetime_immutable',
        'date' => 'date_immutable',
        'time' => 'time_immutable',
        'jsonb' => 'json',
        'uuid' => 'uuid',
        'bytea' => 'binary',
        'vector' => 'vector',
    ];

    public function listSequences(): array
    {
        \assert($this->platform instanceof PostgreSqlPlatform);

        return array_map(
            static fn (array $row): Sequence => new Sequence(self::string($row['name']), (int) self::nullableInt($row['start_value']), (int) self::nullableInt($row['increment'])),
            $this->connection->executeQuery($this->platform->getListSequencesSql())->fetchAllAssociative(),
        );
    }

    /**
     * HNSW : distance relue dans l'opclass, m et ef_construction dans les options ; GIN sur le
     * document to_tsvector() de Prism : plein texte. Un autre index sur expression n'est pas géré
     * par Prism et reste ignoré, comme avant.
     */
    protected function indexes(string $table): array
    {
        $regular = [];
        $special = [];
        foreach ($this->connection->executeQuery($this->platform->getListIndexesSql(), [$table])->fetchAllAssociative() as $row) {
            $method = self::string($row['method']);
            if ('hnsw' === $method || null === $row['column_name']) {
                $special[self::string($row['name'])] ??= $row;
            } else {
                $regular[] = $row;
            }
        }

        $indexes = $this->indexesFromRows($table, $regular);
        foreach ($special as $name => $row) {
            $index = 'hnsw' === self::string($row['method']) ? self::vectorIndex($name, $row) : self::fullTextIndex($name, $row);
            if (null !== $index) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function vectorIndex(string $name, array $row): ?Index
    {
        $distance = array_search(self::nullableString($row['operator_class']), PostgreSqlPlatform::OPERATOR_CLASSES, true);
        if (!\is_string($distance) || null === $row['column_name']) {
            return null;
        }
        parse_str(str_replace(',', '&', self::nullableString($row['options']) ?? ''), $options);

        return new Index(
            $name,
            [self::string($row['column_name'])],
            kind: IndexKind::Vector,
            distance: \constant(VectorDistance::class . '::' . $distance),
            m: is_numeric($options['m'] ?? null) ? (int) $options['m'] : 16,
            efConstruction: is_numeric($options['ef_construction'] ?? null) ? (int) $options['ef_construction'] : 64,
        );
    }

    /**
     * « USING gin (to_tsvector('english'::regconfig, … COALESCE(title, …) … COALESCE(body, …))) ».
     *
     * @param array<string, mixed> $row
     */
    private static function fullTextIndex(string $name, array $row): ?Index
    {
        $definition = self::string($row['definition']);
        if ('gin' !== self::string($row['method']) || 1 !== preg_match("/USING gin \\(to_tsvector\\('([a-z_]+)'::regconfig, (.*)\\)\\)$/s", $definition, $m)) {
            return null;
        }
        preg_match_all('/COALESCE\\(("(?:[^"]|"")+"|[a-z_][a-z0-9_$]*)\\s*,/', $m[2], $columns);
        if ([] === $columns[1]) {
            return null;
        }

        return new Index(
            $name,
            array_map(static fn (string $c): string => str_starts_with($c, '"') ? str_replace('""', '"', substr($c, 1, -1)) : $c, $columns[1]),
            kind: IndexKind::FullText,
            language: $m[1],
        );
    }

    protected function column(string $table, array $row): Column
    {
        $name = self::string($row['name']);
        $udt = self::string($row['data_type']);
        $type = self::TYPES[$udt] ?? throw InvalidSchemaException::unsupportedDatabaseType($this->platform->getName(), $table, $name, $udt);
        $identity = 'YES' === self::string($row['is_identity']);

        return new Column(
            $name,
            $type,
            'YES' === self::string($row['nullable']),
            $identity ? null : self::typedDefault($type, self::defaultValue(self::nullableString($row['default_value']))),
            match ($type) {
                'string' => self::nullableInt($row['length']),
                'vector' => self::nullableInt($row['type_modifier']),
                default => null,
            },
            'decimal' === $type ? self::nullableInt($row['precision']) : null,
            'decimal' === $type ? self::nullableInt($row['scale']) : null,
            $identity,
            // CHAR(n) : bpchar, toujours d'une longueur donnée
            'bpchar' === $udt && null !== self::nullableInt($row['length']),
        );
    }

    // « 'draft'::character varying », « '-5'::integer », « 9.90 », « true »
    private static function defaultValue(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $value = (string) preg_replace('/::[a-z][a-z0-9_ ]*(\(\d+(,\s*\d+)?\))?$/i', '', $raw);

        return self::isQuoted($value) ? self::unquote($value) : $value;
    }
}
