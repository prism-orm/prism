<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Un chargement par lot (pilier 1, RG-04.10) : une seule requête pour toute une cohorte. Publié
 * seulement quand le lot compte au moins deux entités ; le profiler du bundle l'affiche.
 *
 * - association null : $count entités $entityClass référencées (ToOne) chargées ensemble ;
 * - sinon : la relation $entityClass::$association (collection, côté inverse d'un OneToOne)
 *   chargée pour $count propriétaires.
 *
 * @api
 */
final class BatchLoadedEvent
{
    /**
     * @param class-string $entityClass
     *
     * @internal construit par Prism ; un listener le reçoit
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly ?string $association,
        public readonly int $count,
        public readonly FlushContext $unitOfWork,
    ) {
    }
}
