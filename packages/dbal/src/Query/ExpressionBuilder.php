<?php

declare(strict_types=1);

namespace Prism\Dbal\Query;

use Prism\Dbal\Exception\InvalidQueryException;
use Prism\Dbal\ParameterType;

/**
 * Conditions dont le premier argument est toujours une colonne (SQL brut) et le second toujours
 * une valeur, liée en paramètre (RG-01.4). Pour comparer deux colonnes, écrire le SQL brut :
 * 'c.id = i.client_id'.
 *
 * @api
 */
final class ExpressionBuilder
{
    /**
     * @param ParameterType|string|null $type ParameterType ou nom d'un Type (valeur convertie)
     */
    public function eq(string $column, mixed $value, ParameterType|string|null $type = null): Expression
    {
        return $this->compare($column, '=', $value, $type);
    }

    public function neq(string $column, mixed $value, ParameterType|string|null $type = null): Expression
    {
        return $this->compare($column, '<>', $value, $type);
    }

    public function lt(string $column, mixed $value, ParameterType|string|null $type = null): Expression
    {
        return $this->compare($column, '<', $value, $type);
    }

    public function lte(string $column, mixed $value, ParameterType|string|null $type = null): Expression
    {
        return $this->compare($column, '<=', $value, $type);
    }

    public function gt(string $column, mixed $value, ParameterType|string|null $type = null): Expression
    {
        return $this->compare($column, '>', $value, $type);
    }

    public function gte(string $column, mixed $value, ParameterType|string|null $type = null): Expression
    {
        return $this->compare($column, '>=', $value, $type);
    }

    public function like(string $column, string $pattern): Expression
    {
        return Expression::of($column . ' LIKE ', new BoundValue($pattern, ParameterType::String));
    }

    public function isNull(string $column): Expression
    {
        return Expression::of($column . ' IS NULL');
    }

    public function isNotNull(string $column): Expression
    {
        return Expression::of($column . ' IS NOT NULL');
    }

    /**
     * Une liste vide donne une condition toujours fausse (1 = 0) plutôt qu'un « IN () » invalide.
     *
     * @param list<mixed> $values
     */
    public function in(string $column, array $values, ParameterType|string|null $type = null): Expression
    {
        return [] === $values ? Expression::of('1 = 0') : $this->list($column, 'IN', $values, $type);
    }

    /**
     * Une liste vide donne une condition toujours vraie (1 = 1).
     *
     * @param list<mixed> $values
     */
    public function notIn(string $column, array $values, ParameterType|string|null $type = null): Expression
    {
        return [] === $values ? Expression::of('1 = 1') : $this->list($column, 'NOT IN', $values, $type);
    }

    public function and(Expression|string ...$conditions): Expression
    {
        return $this->composite('AND', $conditions);
    }

    public function or(Expression|string ...$conditions): Expression
    {
        return $this->composite('OR', $conditions);
    }

    private function compare(string $column, string $operator, mixed $value, ParameterType|string|null $type): Expression
    {
        if (null === $value) {
            throw InvalidQueryException::nullComparison($operator);
        }

        return Expression::of($column . ' ' . $operator . ' ', new BoundValue($value, $type));
    }

    /**
     * @param list<mixed> $values
     */
    private function list(string $column, string $operator, array $values, ParameterType|string|null $type): Expression
    {
        $parts = [$column . ' ' . $operator . ' ('];
        foreach ($values as $i => $value) {
            if ($i > 0) {
                $parts[] = ', ';
            }
            $parts[] = new BoundValue($value, $type);
        }
        $parts[] = ')';

        return Expression::of(...$parts);
    }

    /**
     * @param array<Expression|string> $conditions
     */
    private function composite(string $operator, array $conditions): Expression
    {
        if ([] === $conditions) {
            throw InvalidQueryException::emptyComposite(strtolower($operator));
        }
        if (1 === \count($conditions)) {
            return self::expression(array_values($conditions)[0]);
        }

        return Expression::join(' ' . $operator . ' ', ...array_map(static fn (Expression|string $c): Expression => self::expression($c)->wrapped(), array_values($conditions)));
    }

    /**
     * @internal
     */
    public static function expression(Expression|string $condition): Expression
    {
        return \is_string($condition) ? Expression::of($condition) : $condition;
    }
}
