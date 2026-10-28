<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Prism;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

// scénarios N+1 et mode worker : un client (ManyToOne) et des lignes (OneToMany), paresseux
#[Prism\Entity]
#[Prism\Table('purchase_order')]
final class PurchaseOrder
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 20)]
    public string $reference = '';

    #[Prism\Column(length: 20)]
    public string $status = 'open';

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total = '0.00';

    #[Prism\ManyToOne]
    public Customer $customer;

    /** @var Collection<int, OrderLine> */
    #[Prism\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order')]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}
