<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Shop;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Category
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 40)]
    public string $name = '';
}
