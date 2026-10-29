<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_line')]
final class OrderLine
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    public PurchaseOrder $order;

    #[ORM\Column(length: 40)]
    public string $product = '';

    #[ORM\Column]
    public int $quantity = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '0.00';
}
