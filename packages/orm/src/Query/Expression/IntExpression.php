<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Expression;

use Prism\Orm\Query\Comparison\IntComparisons;

/**
 * Expression de valeur int.
 *
 * @api
 */
final class IntExpression extends TypedExpression
{
    use IntComparisons;
}
