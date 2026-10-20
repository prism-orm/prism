<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Seller
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<int, Order> chargée par lot dès l'hydratation */
    #[Prism\OneToMany(targetEntity: Order::class, mappedBy: 'seller', fetch: Prism\Fetch::Eager)]
    public Collection $orders;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $name = '',
    ) {
        $this->orders = new ArrayCollection();
    }
}
