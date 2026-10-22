<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Shop;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Product
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20, unique: true)]
    public string $ref = '';

    #[Prism\Column]
    public int $stock = 0;

    #[Prism\ManyToOne]
    public ?Category $category = null;
}
