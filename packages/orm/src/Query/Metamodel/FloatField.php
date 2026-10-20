<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Comparison\FloatComparisons;
use Prism\Orm\Query\Expression\FloatExpression;

/**
 * Champ de type float : valeurs float.
 *
 * @api
 */
final class FloatField extends Field
{
    use FloatComparisons;

    /**
     * SUM du champ ; null sans valeur.
     */
    public function sum(): FloatExpression
    {
        return new FloatExpression($this->aggregated('sum'), $this->ownType(), aggregate: true);
    }

    /**
     * AVG du champ ; null sans valeur.
     */
    public function avg(): FloatExpression
    {
        return new FloatExpression($this->aggregated('avg'), $this->ownType(), aggregate: true);
    }

    /**
     * MIN du champ ; null sans valeur.
     */
    public function min(): FloatExpression
    {
        return new FloatExpression($this->aggregated('min'), $this->ownType(), aggregate: true);
    }

    /**
     * MAX du champ ; null sans valeur.
     */
    public function max(): FloatExpression
    {
        return new FloatExpression($this->aggregated('max'), $this->ownType(), aggregate: true);
    }
}
