<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

// numéro unique par tenant : deux tenants peuvent avoir la facture « F-1 » (RG-08.1)
#[Prism\Entity]
#[Prism\Table(name: 'tenant_invoice')]
#[Prism\Tenanted]
#[Prism\UniqueConstraint(fields: ['number'])]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<int, Line> */
    #[Prism\OneToMany(targetEntity: Line::class, mappedBy: 'invoice')]
    #[Prism\OrderBy(['label' => Prism\Order::Asc])]
    public Collection $lines;

    /** @var Collection<int, Tag> */
    #[Prism\ManyToMany(targetEntity: Tag::class, fetch: Prism\Fetch::ExtraLazy)]
    #[Prism\JoinTable(name: 'tenant_invoice_tag')]
    public Collection $tags;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $number = '',
        #[Prism\Column]
        public int $amount = 0,
        #[Prism\ManyToOne(inversedBy: 'invoices')]
        public ?Client $client = null,
        // entité non tenantée : toujours visible
        #[Prism\ManyToOne]
        public ?Currency $currency = null,
    ) {
        $this->lines = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}
