<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<int, InvoiceLine> collection readonly (ADR 0007) */
    #[Prism\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: [Prism\Cascade::Persist])]
    #[Prism\OrderBy(['position' => Prism\Order::Asc])]
    public readonly Collection $lines;

    /** @var Collection<int, Tag> */
    #[Prism\ManyToMany(targetEntity: Tag::class, inversedBy: 'invoices', cascade: [Prism\Cascade::Persist])]
    public Collection $tags;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $number = '',
    ) {
        $this->lines = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}
