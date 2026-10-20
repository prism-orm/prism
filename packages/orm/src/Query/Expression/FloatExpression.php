<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Expression;

use Prism\Orm\Query\Comparison\FloatComparisons;

/**
 * Expression de valeur float.
 *
 * @api
 */
final class FloatExpression extends TypedExpression
{
    use FloatComparisons;
}
