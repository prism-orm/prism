<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Main;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 120)]
    public string $name = '';
}
