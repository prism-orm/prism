<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Comparison\DecimalComparisons;
use Prism\Orm\Query\Expression\DecimalExpression;

/**
 * Champ de type decimal : valeurs numeric-string, jamais float (RG-01.5).
 *
 * @api
 */
final class DecimalField extends Field
{
    use DecimalComparisons;

    /**
     * SUM du champ ; null sans valeur.
     */
    public function sum(): DecimalExpression
    {
        return new DecimalExpression($this->aggregated('sum'), $this->ownType(), aggregate: true);
    }

    /**
     * AVG du champ (échelle selon la base, ADR 0010) ; null sans valeur.
     */
    public function avg(): DecimalExpression
    {
        return new DecimalExpression($this->aggregated('avg'), $this->ownType(), aggregate: true);
    }

    /**
     * MIN du champ ; null sans valeur.
     */
    public function min(): DecimalExpression
    {
        return new DecimalExpression($this->aggregated('min'), $this->ownType(), aggregate: true);
    }

    /**
     * MAX du champ ; null sans valeur.
     */
    public function max(): DecimalExpression
    {
        return new DecimalExpression($this->aggregated('max'), $this->ownType(), aggregate: true);
    }
}
