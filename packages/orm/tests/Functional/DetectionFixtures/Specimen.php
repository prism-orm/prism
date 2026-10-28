<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\DetectionFixtures;

use Prism\Orm\Mapping as Prism;

// un champ de chaque type natif (hors vector et binary), pour la détection des changements (ADR 0022)
#[Prism\Entity]
final class Specimen
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 40)]
    public string $label = 'first';

    #[Prism\Column(type: 'text')]
    public string $notes = 'long text';

    #[Prism\Column]
    public int $quantity = 7;

    #[Prism\Column(type: 'bigint')]
    public int $big = 9_000_000_000;

    #[Prism\Column(type: 'smallint')]
    public int $small = 3;

    #[Prism\Column]
    public bool $active = true;

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '12.50';

    #[Prism\Column]
    public float $ratio = 0.25;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;

    #[Prism\Column(type: 'date_immutable')]
    public \DateTimeImmutable $day;

    #[Prism\Column(type: 'time_immutable')]
    public \DateTimeImmutable $hour;

    /** @var array<string, mixed> */
    #[Prism\Column(type: 'json')]
    public array $meta = ['size' => 'L', 'codes' => [1, 2]];

    #[Prism\Column(type: 'uuid')]
    public string $reference = '0192f5c4-6f3a-7b1c-9d2e-3f4a5b6c7d8e';

    #[Prism\Column]
    public Grade $grade = Grade::A;

    #[Prism\Column(length: 40)]
    public ?string $comment = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('2026-10-28 08:00:00');
        $this->day = new \DateTimeImmutable('2026-10-28');
        $this->hour = new \DateTimeImmutable('1970-01-01 08:30:00');
    }
}
