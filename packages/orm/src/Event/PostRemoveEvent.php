<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Après le commit du flush qui a supprimé l'entité.
 *
 * @api
 */
final class PostRemoveEvent
{
    public function __construct(
        public readonly object $entity,
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
