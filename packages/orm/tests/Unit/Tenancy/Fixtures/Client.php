<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table(name: 'tenant_client')]
#[Prism\Tenanted]
final class Client
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<int, Invoice> */
    #[Prism\OneToMany(targetEntity: Invoice::class, mappedBy: 'client')]
    public Collection $invoices;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $name = '',
    ) {
        $this->invoices = new ArrayCollection();
    }
}
