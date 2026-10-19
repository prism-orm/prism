<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Avant l'INSERT, dans la transaction du flush : une modification de l'entité est incluse.
 *
 * @api
 */
final class PrePersistEvent
{
    public function __construct(
        public readonly object $entity,
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
