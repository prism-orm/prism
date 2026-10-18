<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Stratégie de chargement d'une relation.
 *
 * @api
 */
enum Fetch
{
    case Lazy;
    case Eager;
    case ExtraLazy;
}
