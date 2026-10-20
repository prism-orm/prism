<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Comparison;

use Prism\Orm\Query\Condition;

/**
 * Comparaisons d'un opérande de valeurs \DateTimeImmutable, partagées par le champ et
 * l'expression du même type (RG-05.3, ADR 0010).
 *
 * @internal
 */
trait DateTimeComparisons
{
    public function eq(\DateTimeImmutable $value): Condition
    {
        return $this->compare('eq', $value);
    }

    public function neq(\DateTimeImmutable $value): Condition
    {
        return $this->compare('neq', $value);
    }

    public function lt(\DateTimeImmutable $value): Condition
    {
        return $this->compare('lt', $value);
    }

    public function lte(\DateTimeImmutable $value): Condition
    {
        return $this->compare('lte', $value);
    }

    public function gt(\DateTimeImmutable $value): Condition
    {
        return $this->compare('gt', $value);
    }

    public function gte(\DateTimeImmutable $value): Condition
    {
        return $this->compare('gte', $value);
    }

    /** @param list<\DateTimeImmutable> $values */
    public function in(array $values): Condition
    {
        return $this->within($values);
    }

    /** @param list<\DateTimeImmutable> $values */
    public function notIn(array $values): Condition
    {
        return $this->within($values, negated: true);
    }

    /**
     * Bornes comprises.
     */
    public function between(\DateTimeImmutable $low, \DateTimeImmutable $high): Condition
    {
        return $this->range($low, $high);
    }
}
