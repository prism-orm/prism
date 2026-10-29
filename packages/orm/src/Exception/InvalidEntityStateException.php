<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

use Prism\Orm\UnitOfWork\EntityState;

/**
 * Transition d'état absente de RG-03.2.
 *
 * @api
 */
final class InvalidEntityStateException extends \LogicException implements OrmException
{
    /**
     * @internal levée par l'UnitOfWork
     */
    public static function transition(object $entity, EntityState $state, string $operation, string $hint): self
    {
        return new self(\sprintf('Cannot %s a %s entity of class %s: %s', $operation, strtoupper($state->name), $entity::class, $hint));
    }

    /**
     * Un vecteur paresseux lu non NULL est devenu NULL avant d'être chargé (RG-09.6).
     *
     * @param class-string $class
     */
    public static function lazyValueGone(string $class, string $field, mixed $id): self
    {
        return new self(\sprintf('The lazy field %s::$%s of id %s became NULL in the database after the entity was read; refresh() the entity.', $class, $field, \is_scalar($id) ? (string) $id : get_debug_type($id)));
    }

    /**
     * @param class-string $source
     */
    public static function detachedReference(object $target, string $source, string $field): self
    {
        return new self(\sprintf('Cannot reference a DETACHED entity of class %s from %s::$%s: load it again with find() or getReference().', $target::class, $source, $field));
    }
}
