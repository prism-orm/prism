<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

/**
 * Les quatre seuls états d'une entité (RG-03.1).
 *
 * @api
 */
enum EntityState
{
    /** Inconnue de l'UnitOfWork : jamais persistée, ou supprimée par un flush. */
    case New;

    /** Suivie : écrite au prochain flush (INSERT si planifiée, UPDATE si modifiée). */
    case Managed;

    /** Planifiée pour un DELETE au prochain flush. */
    case Removed;

    /** Détachée par detach() ou clear() : plus suivie, jamais écrite. */
    case Detached;
}
