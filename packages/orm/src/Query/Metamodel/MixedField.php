<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Condition;

/**
 * Champ de type personnalisé : valeurs mixed : égalité seulement.
 *
 * @api
 */
final class MixedField extends Field
{
    public function eq(mixed $value): Condition
    {
        return $this->compare('eq', $value);
    }

    public function neq(mixed $value): Condition
    {
        return $this->compare('neq', $value);
    }
}
