<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Dbal\Query\Order as Sort;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    // lots de 2 (RG-04.11)
    #[Prism\ManyToOne(batchSize: 2)]
    public ?Country $country = null;

    /** @var Collection<int, Order> */
    #[Prism\OneToMany(targetEntity: Order::class, mappedBy: 'customer')]
    #[Prism\OrderBy(['reference' => Sort::Desc])]
    public Collection $orders;

    /** @var Collection<int, Group> */
    #[Prism\ManyToMany(targetEntity: Group::class, inversedBy: 'customers')]
    public Collection $groups;

    #[Prism\OneToOne(mappedBy: 'customer')]
    public ?Account $account = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $name = '',
    ) {
        $this->orders = new ArrayCollection();
        $this->groups = new ArrayCollection();
    }
}
