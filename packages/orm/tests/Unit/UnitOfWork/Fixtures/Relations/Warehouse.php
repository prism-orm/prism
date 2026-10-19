<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Warehouse
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<string, StockItem> EXTRA_LAZY, indexée par sku */
    #[Prism\OneToMany(targetEntity: StockItem::class, mappedBy: 'warehouse', fetch: Prism\Fetch::ExtraLazy, indexBy: 'sku')]
    public Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }
}
