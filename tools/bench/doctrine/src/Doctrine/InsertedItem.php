<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Doctrine;

use Doctrine\ORM\Mapping as ORM;

// la table des insertions mesurées, vidée avant chaque série : item garde ses 10 000 lignes
#[ORM\Entity]
#[ORM\Table(name: 'inserted_item')]
final class InsertedItem
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20)]
    public string $code = '';

    #[ORM\Column(length: 80)]
    public string $name = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '0.00';

    #[ORM\Column]
    public int $quantity = 0;

    #[ORM\Column]
    public bool $active = true;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
}
