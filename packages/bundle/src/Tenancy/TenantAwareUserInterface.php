<?php

declare(strict_types=1);

namespace Prism\Bundle\Tenancy;

/**
 * L'utilisateur connecté porte son tenant : stratégie « user » du TenantResolver.
 *
 * @api
 */
interface TenantAwareUserInterface
{
    public function getTenantId(): int|string|null;
}
