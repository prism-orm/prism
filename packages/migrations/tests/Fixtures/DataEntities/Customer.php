<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\DataEntities;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('dt_customer')]
final class Customer
{
    #[Prism\Id]
    public int $id = 0;

    #[Prism\Column(length: 80)]
    public string $fullName = '';

    #[Prism\Column(length: 40)]
    public ?string $firstName = null;
}
