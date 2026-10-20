<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Expression;

use Prism\Orm\Query\Comparison\StringComparisons;

/**
 * Expression de valeur string.
 *
 * @api
 */
final class StringExpression extends TypedExpression
{
    use StringComparisons;
}
