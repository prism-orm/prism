<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Dbal\Query\Expression;
use Prism\Orm\Query\Condition;
use Prism\Orm\Query\QueryContext;

/**
 * Champ de type string, text, uuid : valeurs string.
 *
 * @api
 */
final class StringField extends Field
{
    public function eq(string $value): Condition
    {
        return $this->compare('eq', $value);
    }

    public function neq(string $value): Condition
    {
        return $this->compare('neq', $value);
    }

    public function lt(string $value): Condition
    {
        return $this->compare('lt', $value);
    }

    public function lte(string $value): Condition
    {
        return $this->compare('lte', $value);
    }

    public function gt(string $value): Condition
    {
        return $this->compare('gt', $value);
    }

    public function gte(string $value): Condition
    {
        return $this->compare('gte', $value);
    }

    /** @param list<string> $values */
    public function in(array $values): Condition
    {
        return $this->within($values);
    }

    /** @param list<string> $values */
    public function notIn(array $values): Condition
    {
        return $this->within($values, negated: true);
    }

    /**
     * Bornes comprises.
     */
    public function between(string $low, string $high): Condition
    {
        return $this->range($low, $high);
    }

    /**
     * LIKE, avec les jokers % et _ ; le motif est lié en paramètre.
     */
    public function like(string $pattern): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->expr()->like($c->column($this), $pattern));
    }
}
