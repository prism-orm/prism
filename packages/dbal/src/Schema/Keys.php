<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Validations communes aux listes de colonnes (index, clés).
 *
 * @internal
 */
final class Keys
{
    /**
     * @param array<mixed> $columns
     *
     * @return list<string>
     */
    public static function columns(string $object, string $name, array $columns): array
    {
        if ([] === $columns || !array_is_list($columns)) {
            throw InvalidSchemaException::invalidKey($object, $name, 'expected a non-empty list of column names.');
        }

        $seen = [];
        foreach ($columns as $column) {
            if (!\is_string($column) || '' === trim($column)) {
                throw InvalidSchemaException::invalidKey($object, $name, 'column names must be non-empty strings.');
            }
            if (isset($seen[strtolower($column)])) {
                throw InvalidSchemaException::invalidKey($object, $name, \sprintf('column "%s" is listed twice.', $column));
            }
            $seen[strtolower($column)] = true;
        }

        return $columns;
    }
}
