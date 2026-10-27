<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Mapping as Prism;

// colonne du tenant lisible par une propriété (ADR 0014)
#[Prism\Entity]
#[Prism\Table(name: 'tenant_contract')]
#[Prism\Tenanted]
final class Contract
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public int $tenantId;

    // une ligne partagée (tenant NULL) est une cible permise (RG-08.6)
    #[Prism\ManyToOne]
    public ?Rate $rate = null;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $reference = '',
    ) {
    }
}
