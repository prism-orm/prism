<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Expression;

use Prism\Orm\Query\Comparison\DecimalComparisons;

/**
 * Expression de valeur numeric-string, jamais float (RG-01.5).
 *
 * @api
 */
final class DecimalExpression extends TypedExpression
{
    use DecimalComparisons;
}
