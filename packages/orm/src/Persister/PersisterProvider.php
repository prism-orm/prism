<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

use Prism\Orm\Metadata\ClassMetadata;

/**
 * Accès de l'UnitOfWork au stockage : un persister par entité et la transaction du flush.
 *
 * @internal
 */
interface PersisterProvider
{
    /**
     * @param ClassMetadata<object> $metadata
     */
    public function getPersister(ClassMetadata $metadata): EntityPersister;

    /**
     * Identifiant généré avant l'INSERT (séquence, UUID v7), en valeur de la base ; null si la
     * base l'attribue (IDENTITY) ou si l'application l'assigne.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function nextId(ClassMetadata $metadata): int|string|null;

    public function isTransactionActive(): bool;

    /**
     * Exécute $operations dans une transaction : commit si elles se terminent, rollback et
     * exception relancée sinon (RG-03.6).
     *
     * @template T
     *
     * @param \Closure(): T $operations
     *
     * @return T
     */
    public function transactional(\Closure $operations): mixed;
}
