<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Comparison;

use Prism\Orm\Query\Condition;

/**
 * Comparaisons d'un opérande de valeurs float, partagées par le champ et
 * l'expression du même type (RG-05.3, ADR 0010).
 *
 * @internal
 */
trait FloatComparisons
{
    public function eq(float $value): Condition
    {
        return $this->compare('eq', $value);
    }

    public function neq(float $value): Condition
    {
        return $this->compare('neq', $value);
    }

    public function lt(float $value): Condition
    {
        return $this->compare('lt', $value);
    }

    public function lte(float $value): Condition
    {
        return $this->compare('lte', $value);
    }

    public function gt(float $value): Condition
    {
        return $this->compare('gt', $value);
    }

    public function gte(float $value): Condition
    {
        return $this->compare('gte', $value);
    }

    /** @param list<float> $values */
    public function in(array $values): Condition
    {
        return $this->within($values);
    }

    /** @param list<float> $values */
    public function notIn(array $values): Condition
    {
        return $this->within($values, negated: true);
    }

    /**
     * Bornes comprises.
     */
    public function between(float $low, float $high): Condition
    {
        return $this->range($low, $high);
    }
}
