<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Comparison;

use Prism\Orm\Query\Condition;

/**
 * Comparaisons d'un opérande de valeurs numeric-string, jamais float (RG-01.5), partagées par le champ et
 * l'expression du même type (RG-05.3, ADR 0010).
 *
 * @internal
 */
trait DecimalComparisons
{
    /** @param numeric-string $value */
    public function eq(string $value): Condition
    {
        return $this->compare('eq', $value);
    }

    /** @param numeric-string $value */
    public function neq(string $value): Condition
    {
        return $this->compare('neq', $value);
    }

    /** @param numeric-string $value */
    public function lt(string $value): Condition
    {
        return $this->compare('lt', $value);
    }

    /** @param numeric-string $value */
    public function lte(string $value): Condition
    {
        return $this->compare('lte', $value);
    }

    /** @param numeric-string $value */
    public function gt(string $value): Condition
    {
        return $this->compare('gt', $value);
    }

    /** @param numeric-string $value */
    public function gte(string $value): Condition
    {
        return $this->compare('gte', $value);
    }

    /** @param list<numeric-string> $values */
    public function in(array $values): Condition
    {
        return $this->within($values);
    }

    /** @param list<numeric-string> $values */
    public function notIn(array $values): Condition
    {
        return $this->within($values, negated: true);
    }

    /**
     * Bornes comprises.
     *
     * @param numeric-string $low
     * @param numeric-string $high
     */
    public function between(string $low, string $high): Condition
    {
        return $this->range($low, $high);
    }
}
