<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Avant l'UPDATE : une modification de l'entité est prise en compte (RG-03.8).
 *
 * @api
 */
final class PreUpdateEvent
{
    /**
     * @param array<string, array{mixed, mixed}> $changeSet champ → [ancienne valeur, nouvelle valeur]
     *
     * @internal construit par Prism ; un listener le reçoit
     */
    public function __construct(
        public readonly object $entity,
        public readonly array $changeSet,
        public readonly FlushContext $unitOfWork,
    ) {
    }
}
