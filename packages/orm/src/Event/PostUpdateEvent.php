<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Après le commit du flush qui a mis l'entité à jour.
 *
 * @api
 */
final class PostUpdateEvent
{
    public function __construct(
        public readonly object $entity,
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
