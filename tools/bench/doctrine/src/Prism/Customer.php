<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Prism;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('customer')]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 80)]
    public string $name = '';

    #[Prism\Column(length: 40)]
    public string $city = '';
}
