<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Changement de tenant alors que l'UnitOfWork a des changements non écrits : la vider les
 * perdrait (RG-08.8, ADR 0014). Flusher ou vider l'EntityManager avant de changer de tenant.
 *
 * @api
 */
final class TenantSwitchException extends \LogicException implements OrmException
{
    public static function pendingChanges(int|string|null $from, int|string|null $to): self
    {
        return new self(\sprintf('Cannot switch from tenant %s to tenant %s: the EntityManager has unflushed changes; flush() or clear() it first.', var_export($from, true), var_export($to, true)));
    }
}
