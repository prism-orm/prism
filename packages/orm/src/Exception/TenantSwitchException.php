<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

use Prism\Orm\Tenancy\TenantScope;

/**
 * Changement de tenant alors que l'UnitOfWork a des changements non écrits (RG-08.8, ADR 0014) :
 * à l'entrée, le changement est refusé et rien n'est perdu ; à la sortie d'un runAs(), les
 * changements sont abandonnés, jamais écrits sous un autre tenant.
 *
 * @api
 */
final class TenantSwitchException extends \LogicException implements OrmException
{
    public static function pendingChanges(TenantScope $from, TenantScope $to): self
    {
        return new self(\sprintf('Cannot switch from %s to %s: the EntityManager has unflushed changes; flush() or clear() it first.', $from->describe(), $to->describe()));
    }

    public static function discarded(TenantScope $from, TenantScope $to): self
    {
        return new self(\sprintf('Unflushed changes made in %s were discarded when switching back to %s: flush() before leaving runAs() or runAsSystem().', $from->describe(), $to->describe()));
    }
}
