<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Prism;

use Prism\Orm\Mapping as Prism;

// la table des insertions mesurées, vidée avant chaque série : item garde ses 10 000 lignes
#[Prism\Entity]
#[Prism\Table('inserted_item')]
final class InsertedItem
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 20)]
    public string $code = '';

    #[Prism\Column(length: 80)]
    public string $name = '';

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '0.00';

    #[Prism\Column]
    public int $quantity = 0;

    #[Prism\Column]
    public bool $active = true;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;
}
