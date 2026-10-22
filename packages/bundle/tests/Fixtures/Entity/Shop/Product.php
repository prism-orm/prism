<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Shop;

use Prism\Bundle\Validator\UniqueEntity;
use Prism\Orm\Mapping as Prism;

// la relation comptée parmi les champs ; une catégorie nulle ne compte pas (ignoreNull)
#[UniqueEntity(fields: ['ref'])]
#[UniqueEntity(fields: ['category', 'stock'], message: 'One product per stock level in a category.', errorPath: 'stock')]
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
