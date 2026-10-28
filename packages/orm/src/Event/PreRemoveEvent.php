<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Au moment de remove(), avant que l'entité ne soit planifiée pour suppression.
 *
 * @api
 */
final class PreRemoveEvent
{
    /**
     * @internal construit par Prism ; un listener le reçoit
     */
    public function __construct(
        public readonly object $entity,
        public readonly FlushContext $unitOfWork,
    ) {
    }
}
