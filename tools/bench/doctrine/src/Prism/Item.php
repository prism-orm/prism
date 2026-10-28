<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Prism;

use Prism\Orm\Mapping as Prism;

// une ligne d'ERP typique : texte, montant, quantité, booléen, date (scénarios hydratation et flush)
#[Prism\Entity]
#[Prism\Table('item')]
final class Item
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
