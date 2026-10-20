<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Condition;

/**
 * Champ de type boolean : valeurs bool : égalité seulement.
 *
 * @api
 */
final class BoolField extends Field
{
    public function eq(bool $value): Condition
    {
        return $this->compare('eq', $value);
    }

    public function neq(bool $value): Condition
    {
        return $this->compare('neq', $value);
    }
}
