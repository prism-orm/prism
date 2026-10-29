<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Prism\Orm\Mapping as Prism;

// le catalogue commun (tenant NULL) et les produits propres à une société
#[Prism\Entity(repositoryClass: ProductRepository::class)]
#[Prism\Tenanted(global: true)]
final class Product
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 40)]
    public string $sku;

    #[Prism\Column(length: 120)]
    public string $name;

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price;
}
