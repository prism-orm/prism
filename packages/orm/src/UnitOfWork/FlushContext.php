<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

use Prism\Orm\Exception\InvalidEntityStateException;

/**
 * Ce qu'un listener voit du flush en cours, par la propriété unitOfWork de chaque événement
 * (ADR 0020) : les entités à écrire, l'état d'une entité, et persist() ou remove() depuis
 * PreFlush ou OnFlush, pris en compte par ce même flush. Le reste de l'UnitOfWork est interne.
 *
 * @api
 */
interface FlushContext
{
    /**
     * NEW → MANAGED, REMOVED → MANAGED ; sans effet sur une entité MANAGED.
     *
     * @throws InvalidEntityStateException entité DETACHED
     */
    public function persist(object $entity): void;

    /**
     * MANAGED → REMOVED ; une entité #[SoftDeletable] est supprimée logiquement, sauf $hardDelete.
     *
     * @throws InvalidEntityStateException entité NEW ou DETACHED
     */
    public function remove(object $entity, bool $hardDelete = false): void;

    /**
     * Vrai pour une entité MANAGED.
     */
    public function contains(object $entity): bool;

    public function getEntityState(object $entity): EntityState;

    /**
     * Vrai si un flush écrirait quelque chose (calculé à l'appel, comme un flush).
     */
    public function hasPendingChanges(): bool;

    /**
     * Entités à insérer au prochain flush, dans l'ordre de persist().
     *
     * @return list<object>
     */
    public function getScheduledInsertions(): array;

    /**
     * Entités gérées dont les changements seront écrits au prochain flush (calculés à l'appel).
     *
     * @return list<object>
     */
    public function getScheduledUpdates(): array;

    /**
     * Entités à supprimer au prochain flush.
     *
     * @return list<object>
     */
    public function getScheduledDeletions(): array;
}
