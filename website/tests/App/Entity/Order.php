<?php

declare(strict_types=1);

namespace App\Entity;

use App\Money;
use App\Repository\OrderRepository;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity(repositoryClass: OrderRepository::class)]
#[Prism\Tenanted]
#[Prism\Timestampable]
#[Prism\Blameable]
final class Order
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20)]
    public string $reference;

    #[Prism\Column]
    public \DateTimeImmutable $placedAt;

    #[Prism\Column(length: 20)]
    public string $status;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;

    #[Prism\Column]
    public ?\DateTimeImmutable $updatedAt = null;

    #[Prism\Column(length: 180)]
    public ?string $createdBy = null;

    #[Prism\Column(length: 180)]
    public ?string $updatedBy = null;

    #[Prism\ManyToOne(inversedBy: 'orders')]
    public Customer $customer;

    /** @var Collection<int, OrderLine> */
    #[Prism\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order')]
    public Collection $lines;

    #[Prism\OneToOne(mappedBy: 'order')]
    public ?Invoice $invoice = null;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function total(): string
    {
        $cents = 0;
        foreach ($this->lines as $line) {
            $cents += Money::toCents($line->total());
        }

        return Money::fromCents($cents);
    }
}
