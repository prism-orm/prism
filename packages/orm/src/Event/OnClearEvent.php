<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Après clear() : toutes les entités sont détachées.
 *
 * @api
 */
final class OnClearEvent
{
    public function __construct(
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
