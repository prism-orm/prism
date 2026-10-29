<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Prism;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('order_line')]
final class OrderLine
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\ManyToOne(inversedBy: 'lines')]
    public PurchaseOrder $order;

    #[Prism\Column(length: 40)]
    public string $product = '';

    #[Prism\Column]
    public int $quantity = 0;

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '0.00';
}
