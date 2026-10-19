<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Requête du QueryBuilder incohérente : clause interdite pour ce type de requête, table absente….
 *
 * @api
 */
final class InvalidQueryException extends \LogicException implements DbalException
{
    public static function clauseNotAllowed(string $clause, string $queryType): self
    {
        return new self(\sprintf('%s is not supported in %s queries.', $clause, $queryType));
    }

    public static function missingTable(string $queryType): self
    {
        return new self(\sprintf('A %s query needs a table: call %s() with the table name.', $queryType, strtolower($queryType)));
    }

    public static function emptySelect(): self
    {
        return new self('A SELECT query needs at least one expression or a FROM clause.');
    }

    public static function noValues(string $queryType): self
    {
        return new self(\sprintf('A %s query needs at least one column: call set().', $queryType));
    }

    public static function negative(string $clause, int $value): self
    {
        return new self(\sprintf('%s must be zero or positive, got %d.', $clause, $value));
    }

    public static function invalidParameterName(string $name): self
    {
        return new self(\sprintf('Invalid parameter name "%s": expected letters, digits and underscores, not starting with a digit or with the reserved "prism_" prefix.', $name));
    }

    public static function nullComparison(string $operator): self
    {
        return new self(\sprintf('Cannot compare with NULL using "%s": the result is never true in SQL. Use isNull() or isNotNull().', $operator));
    }

    public static function emptyComposite(string $operator): self
    {
        return new self(\sprintf('%s() needs at least one condition.', $operator));
    }
}
