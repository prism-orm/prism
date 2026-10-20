<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity(readOnly: true)]
#[Prism\Tenanted(global: true)]
final class Tag
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Sequence, sequenceName: 'tag_seq')]
    public ?int $id = null;

    /** @var Collection<int, Invoice> */
    #[Prism\ManyToMany(targetEntity: Invoice::class, mappedBy: 'tags')]
    public Collection $invoices;

    public function __construct()
    {
        $this->invoices = new ArrayCollection();
    }
}
