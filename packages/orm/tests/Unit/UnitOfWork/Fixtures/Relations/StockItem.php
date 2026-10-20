<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class StockItem
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\ManyToOne(inversedBy: 'items')]
        public Warehouse $warehouse,
        #[Prism\Column(length: 20)]
        public string $sku = '',
    ) {
    }
}
