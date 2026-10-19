<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

// table « group » : mot réservé (RG-01.3)
#[Prism\Entity]
final class Group
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<int, Customer> */
    #[Prism\ManyToMany(targetEntity: Customer::class, mappedBy: 'groups')]
    public Collection $customers;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $name = '',
    ) {
        $this->customers = new ArrayCollection();
    }
}
