<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer')]
final class Customer
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 80)]
    public string $name = '';

    #[ORM\Column(length: 40)]
    public string $city = '';
}
