<?php

declare(strict_types=1);

namespace Prism\Benchmarks\Entity;

use Prism\Orm\Mapping as Prism;

// ligne d'ERP typique : texte, montant, quantité, booléen, date
#[Prism\Entity]
final class BenchItem
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

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('2026-10-19 08:00:00');
    }
}
