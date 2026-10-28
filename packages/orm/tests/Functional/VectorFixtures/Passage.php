<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\VectorFixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\Vector;

/**
 * Un passage cherché par nearest() (RG-09.4) : un sujet pour les critères, la suppression
 * logique, un vecteur indexé (cosinus).
 */
#[Prism\Entity]
#[Prism\SoftDeletable]
final class Passage
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $topic,
        #[Prism\Column(length: 40)]
        public string $title,
        #[Prism\Column(dimensions: 3)]
        #[Prism\VectorIndex(distance: Distance::Cosine, m: 8, efConstruction: 32)]
        public Vector $embedding,
    ) {
    }
}
