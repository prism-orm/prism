<?php

declare(strict_types=1);

namespace App\Entity;

use App\Money;
use App\Repository\OrderLineRepository;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity(repositoryClass: OrderLineRepository::class)]
#[Prism\Tenanted]
final class OrderLine
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\ManyToOne(inversedBy: 'lines')]
    public Order $order;

    #[Prism\ManyToOne]
    public Product $product;

    #[Prism\Column]
    public int $quantity;

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $unitPrice;

    // montant de la ligne, en chaîne comme tout decimal (RG-01.5)
    public function total(): string
    {
        return Money::fromCents(Money::toCents($this->unitPrice) * $this->quantity);
    }
}
