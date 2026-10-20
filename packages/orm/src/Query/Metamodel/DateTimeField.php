<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Comparison\DateTimeComparisons;
use Prism\Orm\Query\Expression\DateTimeExpression;

/**
 * Champ de type datetime_immutable, date_immutable, time_immutable : valeurs \\DateTimeImmutable.
 *
 * @api
 */
final class DateTimeField extends Field
{
    use DateTimeComparisons;

    /**
     * MIN du champ : la plus ancienne ; null sans valeur.
     */
    public function min(): DateTimeExpression
    {
        return new DateTimeExpression($this->aggregated('min'), $this->ownType(), aggregate: true);
    }

    /**
     * MAX du champ : la plus récente ; null sans valeur.
     */
    public function max(): DateTimeExpression
    {
        return new DateTimeExpression($this->aggregated('max'), $this->ownType(), aggregate: true);
    }
}
