<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Tenancy;

use Prism\Bundle\Tenancy\TenantAwareUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class TenantUser implements UserInterface, TenantAwareUserInterface
{
    public function __construct(private readonly int $tenantId)
    {
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'user-' . $this->tenantId;
    }
}
