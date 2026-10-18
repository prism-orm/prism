<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Tenanted]
final class InvoiceLine
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\ManyToOne(inversedBy: 'lines', onDelete: Prism\OnDelete::Cascade)]
    public Invoice $invoice;

    #[Prism\Column]
    public int $position = 0;

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $amount = '0.00';

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }
}
