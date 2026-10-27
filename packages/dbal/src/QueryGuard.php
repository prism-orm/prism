<?php

declare(strict_types=1);

namespace Prism\Dbal;

/**
 * Appelée avant chaque requête paramétrée de la Connection (ADR 0014), symétrique de
 * QueryObserver (ADR 0008) : elle lève une exception pour empêcher la requête. Le DBAL n'en
 * fournit aucune ; l'ORM y branche sa garde du multi-tenancy (TenantGuard).
 *
 * @api
 */
interface QueryGuard
{
    public function guard(string $sql): void;
}
