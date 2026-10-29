<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Après l'hydratation d'une entité (find, findBy, iterate, référence chargée, refresh) ; jamais pour une entité rendue par l'identity map.
 *
 * @api
 */
final class PostLoadEvent
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
