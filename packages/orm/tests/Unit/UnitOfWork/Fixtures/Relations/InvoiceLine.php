<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class InvoiceLine
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\ManyToOne(inversedBy: 'lines')]
        public Invoice $invoice,
        #[Prism\Column]
        public int $position = 0,
        #[Prism\Column(length: 40)]
        public string $label = '',
    ) {
    }
}
