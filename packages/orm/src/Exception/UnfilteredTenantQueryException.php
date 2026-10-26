<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Requête SQL brute sur une table #[Tenanted] sans condition sur sa colonne du tenant, ou que
 * le TenantGuard ne sait pas analyser (RG-08.7, ADR 0014).
 *
 * @api
 */
final class UnfilteredTenantQueryException extends \RuntimeException implements OrmException
{
    public static function forTable(string $table, string $column, string $sql): self
    {
        return new self(\sprintf('The query reads or writes the tenanted table "%s" without a condition on "%s": %s', $table, $column, $sql));
    }

    public static function unparsable(string $table, string $sql): self
    {
        return new self(\sprintf('The query uses the tenanted table "%s" in a form the TenantGuard cannot check (subquery, WITH…); filter it explicitly or run it in runAsSystem(): %s', $table, $sql));
    }
}
