<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Mapping as Prism;

// référentiel mixte : les lignes à tenant_id NULL sont lues par tous, écrites en mode système
#[Prism\Entity]
#[Prism\Table(name: 'tenant_rate')]
#[Prism\Tenanted(global: true)]
final class Rate
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 10)]
        public string $code = '',
        #[Prism\Column]
        public int $percent = 0,
    ) {
    }
}
