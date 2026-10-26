<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table(name: 'tenant_line')]
#[Prism\Tenanted]
final class Line
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\ManyToOne(inversedBy: 'lines')]
        public Invoice $invoice,
        #[Prism\Column(length: 40)]
        public string $label = '',
    ) {
    }
}
