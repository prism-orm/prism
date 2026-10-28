<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\VectorFixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Vector;

/**
 * Trois formes de vecteur (RG-09.2, RG-09.6) : Vector paresseux, Vector paresseux nullable,
 * list<float> toujours chargé.
 */
#[Prism\Entity]
final class Doc
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @param list<float>|null $raw */
    public function __construct(
        #[Prism\Column(length: 40)]
        public string $title,
        #[Prism\Column(dimensions: 3)]
        public Vector $embedding,
        #[Prism\Column(dimensions: 3)]
        public ?Vector $draft = null,
        #[Prism\Column(type: 'vector', dimensions: 3)]
        public ?array $raw = null,
    ) {
    }
}
