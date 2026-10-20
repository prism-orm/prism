<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Comparison\IntComparisons;
use Prism\Orm\Query\Expression\DecimalExpression;
use Prism\Orm\Query\Expression\IntExpression;

/**
 * Champ de type integer, smallint, bigint : valeurs int.
 *
 * @api
 */
final class IntField extends Field
{
    use IntComparisons;

    /**
     * SUM du champ ; null sans valeur.
     */
    public function sum(): IntExpression
    {
        return new IntExpression($this->aggregated('sum'), $this->ownType(), aggregate: true);
    }

    /**
     * AVG du champ, en décimal (échelle selon la base, ADR 0010) ; null sans valeur.
     */
    public function avg(): DecimalExpression
    {
        return new DecimalExpression($this->aggregated('avg'), static fn (): string => 'decimal', aggregate: true);
    }

    /**
     * MIN du champ ; null sans valeur.
     */
    public function min(): IntExpression
    {
        return new IntExpression($this->aggregated('min'), $this->ownType(), aggregate: true);
    }

    /**
     * MAX du champ ; null sans valeur.
     */
    public function max(): IntExpression
    {
        return new IntExpression($this->aggregated('max'), $this->ownType(), aggregate: true);
    }
}
