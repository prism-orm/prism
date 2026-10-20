<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Comparison;

use Prism\Orm\Query\Condition;

/**
 * Comparaisons d'un opérande de valeurs int, partagées par le champ et
 * l'expression du même type (RG-05.3, ADR 0010).
 *
 * @internal
 */
trait IntComparisons
{
    public function eq(int $value): Condition
    {
        return $this->compare('eq', $value);
    }

    public function neq(int $value): Condition
    {
        return $this->compare('neq', $value);
    }

    public function lt(int $value): Condition
    {
        return $this->compare('lt', $value);
    }

    public function lte(int $value): Condition
    {
        return $this->compare('lte', $value);
    }

    public function gt(int $value): Condition
    {
        return $this->compare('gt', $value);
    }

    public function gte(int $value): Condition
    {
        return $this->compare('gte', $value);
    }

    /** @param list<int> $values */
    public function in(array $values): Condition
    {
        return $this->within($values);
    }

    /** @param list<int> $values */
    public function notIn(array $values): Condition
    {
        return $this->within($values, negated: true);
    }

    /**
     * Bornes comprises.
     */
    public function between(int $low, int $high): Condition
    {
        return $this->range($low, $high);
    }
}
