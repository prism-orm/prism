<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Mapping as Prism;

// non tenantée : partagée par tous les tenants, jamais filtrée
#[Prism\Entity]
#[Prism\Table(name: 'tenant_currency')]
final class Currency
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 3)]
        public string $code = '',
    ) {
    }
}
