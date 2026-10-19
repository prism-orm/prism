<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Tenanted]
final class Client
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    /** @var Collection<int, Invoice> */
    #[Prism\OneToMany(targetEntity: Invoice::class, mappedBy: 'client', fetch: Prism\Fetch::ExtraLazy)]
    public Collection $invoices;

    public function __construct()
    {
        $this->invoices = new ArrayCollection();
    }
}
