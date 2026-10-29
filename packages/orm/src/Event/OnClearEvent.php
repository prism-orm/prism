<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Après clear() : toutes les entités sont détachées.
 *
 * @api
 */
final class OnClearEvent
{
    /**
     * @internal construit par Prism ; un listener le reçoit
     */
    public function __construct(
        public readonly FlushContext $unitOfWork,
    ) {
    }
}
