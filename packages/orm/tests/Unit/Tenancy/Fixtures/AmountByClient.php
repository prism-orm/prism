<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

// projection groupée : total des factures par client (SUM est NULL sans ligne)
final class AmountByClient
{
    public function __construct(
        public readonly string $client,
        public readonly ?int $total,
    ) {
    }
}
