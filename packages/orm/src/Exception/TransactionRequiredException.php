<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

use Prism\Dbal\LockMode;

/**
 * Opération qui n'a de sens que dans une transaction ouverte (verrou pessimiste).
 *
 * @api
 */
final class TransactionRequiredException extends \LogicException implements OrmException
{
    public static function forLock(object $entity, LockMode $mode): self
    {
        return new self(\sprintf('Locking the %s entity with %s requires an active transaction: the lock is released at its end. Call lock() inside wrapInTransaction().', $entity::class, $mode->name));
    }
}
