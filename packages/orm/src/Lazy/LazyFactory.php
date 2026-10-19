<?php

declare(strict_types=1);

namespace Prism\Orm\Lazy;

use Prism\Orm\Metadata\ClassMetadata;

/**
 * Ghosts natifs de PHP 8.4 (ADR 0001) : l'entité garde sa classe réelle, son identifiant est posé
 * sans initialisation (RG-04.7) et tout autre accès appelle l'initializer, une seule fois.
 *
 * Un initializer qui lève une exception laisse le ghost non initialisé : l'accès suivant le
 * rappelle (RG-04.8, sans état d'erreur à garder).
 *
 * @internal
 */
final class LazyFactory
{
    /**
     * @template T of object
     *
     * @param ClassMetadata<T>  $metadata
     * @param \Closure(T): void $initializer remplit le ghost ; ne réécrit jamais l'identifiant
     *
     * @return T
     */
    public function ghost(ClassMetadata $metadata, mixed $id, \Closure $initializer): object
    {
        $reflection = new \ReflectionClass($metadata->className);
        $ghost = $reflection->newLazyGhost($initializer);
        $reflection->getProperty($metadata->id->fieldName)->setRawValueWithoutLazyInitialization($ghost, $id);

        return $ghost;
    }

    public function isUninitialized(object $entity): bool
    {
        return new \ReflectionClass($entity)->isUninitializedLazyObject($entity);
    }

    /**
     * Charge un ghost non initialisé ; sans effet sur une entité ordinaire.
     */
    public function initialize(object $entity): void
    {
        $reflection = new \ReflectionClass($entity);
        if ($reflection->isUninitializedLazyObject($entity)) {
            $reflection->initializeLazyObject($entity);
        }
    }
}
