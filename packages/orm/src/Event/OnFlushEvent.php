<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Changements calculés, avant la transaction : FlushContext::getScheduled*() les expose, et une modification faite ici est prise en compte.
 *
 * @api
 */
final class OnFlushEvent
{
    /**
     * @internal construit par Prism ; un listener le reçoit
     */
    public function __construct(
        public readonly FlushContext $unitOfWork,
    ) {
    }
}
