<?php

declare(strict_types=1);

namespace Prism\Bundle\Tenancy;

/**
 * Traduit l'identifiant résolu d'une requête (en-tête, sous-domaine) en identifiant du tenant :
 * « acme » → 42. null : aucun tenant, et l'accès aux entités #[Tenanted] échoue (RG-08.4).
 *
 * @api
 */
interface TenantLoader
{
    public function load(string $identifier): int|string|null;
}
