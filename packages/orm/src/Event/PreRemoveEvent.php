<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Au moment de remove(), avant que l'entité ne soit planifiée pour suppression.
 *
 * @api
 */
final class PreRemoveEvent
{
    public function __construct(
        public readonly object $entity,
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
