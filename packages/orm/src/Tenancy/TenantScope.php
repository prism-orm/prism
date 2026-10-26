<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

/**
 * État d'un TenantContext : un tenant, aucun, ou le mode système (RG-08.5).
 *
 * @api
 */
final class TenantScope
{
    public function __construct(
        public readonly int|string|null $tenantId,
        public readonly bool $system = false,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->tenantId === $other->tenantId && $this->system === $other->system;
    }

    public function describe(): string
    {
        return match (true) {
            $this->system => 'system mode',
            null === $this->tenantId => 'no tenant',
            default => 'tenant ' . var_export($this->tenantId, true),
        };
    }
}
