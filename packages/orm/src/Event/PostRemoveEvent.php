<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Après le commit du flush qui a supprimé l'entité.
 *
 * @api
 */
final class PostRemoveEvent
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
