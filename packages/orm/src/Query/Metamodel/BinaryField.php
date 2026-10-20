<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Condition;

/**
 * Champ de type binary : valeurs string : égalité seulement.
 *
 * @api
 */
final class BinaryField extends Field
{
    public function eq(string $value): Condition
    {
        return $this->compare('eq', $value);
    }

    public function neq(string $value): Condition
    {
        return $this->compare('neq', $value);
    }
}
