<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

/**
 * Réaction du TenantGuard à une requête brute non filtrée (RG-08.7) : exception en dev et en
 * test, log critique en prod par défaut.
 *
 * @api
 */
enum TenantGuardMode
{
    case Exception;
    case Log;
}
