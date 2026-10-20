<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Expression;

use Prism\Orm\Query\Comparison\DateTimeComparisons;

/**
 * Expression de valeur \DateTimeImmutable.
 *
 * @api
 */
final class DateTimeExpression extends TypedExpression
{
    use DateTimeComparisons;
}
