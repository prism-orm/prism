<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Tenancy;

use Prism\Bundle\Tenancy\TenantLoader;

final class SlugLoader implements TenantLoader
{
    public function load(string $identifier): ?int
    {
        return ['acme' => 1, 'globex' => 2][$identifier] ?? null;
    }
}
