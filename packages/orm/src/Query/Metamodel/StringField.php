<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Comparison\StringComparisons;
use Prism\Orm\Query\Expression\StringExpression;

/**
 * Champ de type string, text, uuid : valeurs string.
 *
 * @api
 */
final class StringField extends Field
{
    use StringComparisons;

    /**
     * MIN du champ, dans l'ordre de la collation ; null sans valeur.
     */
    public function min(): StringExpression
    {
        return new StringExpression($this->aggregated('min'), $this->ownType(), aggregate: true);
    }

    /**
     * MAX du champ, dans l'ordre de la collation ; null sans valeur.
     */
    public function max(): StringExpression
    {
        return new StringExpression($this->aggregated('max'), $this->ownType(), aggregate: true);
    }
}
