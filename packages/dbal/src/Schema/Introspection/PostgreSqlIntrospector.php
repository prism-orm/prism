<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema\Introspection;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Sequence;

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
    ];

    public function listSequences(): array
    {
        \assert($this->platform instanceof PostgreSqlPlatform);

        return array_map(
            static fn (array $row): Sequence => new Sequence(self::string($row['name']), (int) self::nullableInt($row['start_value']), (int) self::nullableInt($row['increment'])),
            $this->connection->executeQuery($this->platform->getListSequencesSql())->fetchAllAssociative(),
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
            'string' === $type ? self::nullableInt($row['length']) : null,
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
