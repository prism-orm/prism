<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Sens de tri d'une collection.
 *
 * @api
 */
enum Order
{
    case Asc;
    case Desc;
}
