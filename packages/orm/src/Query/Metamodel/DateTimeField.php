<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Condition;

/**
 * Champ de type datetime_immutable, date_immutable, time_immutable : valeurs \\DateTimeImmutable.
 *
 * @api
 */
final class DateTimeField extends Field
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
