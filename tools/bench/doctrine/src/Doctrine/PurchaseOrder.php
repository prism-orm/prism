<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

// scénarios N+1 et mode worker : un client (ManyToOne) et des lignes (OneToMany), paresseux
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order')]
final class PurchaseOrder
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20)]
    public string $reference = '';

    #[ORM\Column(length: 20)]
    public string $status = 'open';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total = '0.00';

    #[ORM\ManyToOne(fetch: 'LAZY')]
    #[ORM\JoinColumn(nullable: false)]
    public Customer $customer;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order')]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}
