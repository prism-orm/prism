<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Tag
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<int, Invoice> côté inverse, sans cascade */
    #[Prism\ManyToMany(targetEntity: Invoice::class, mappedBy: 'tags')]
    public Collection $invoices;

    public function __construct(
        #[Prism\Column(length: 30)]
        public string $name = '',
    ) {
        $this->invoices = new ArrayCollection();
    }
}
