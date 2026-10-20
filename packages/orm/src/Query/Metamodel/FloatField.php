<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Condition;

/**
 * Champ de type float : valeurs float.
 *
 * @api
 */
final class FloatField extends Field
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
