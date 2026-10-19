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
    public static function transition(object $entity, EntityState $state, string $operation, string $hint): self
    {
        return new self(\sprintf('Cannot %s a %s entity of class %s: %s', $operation, strtoupper($state->name), $entity::class, $hint));
    }
}
