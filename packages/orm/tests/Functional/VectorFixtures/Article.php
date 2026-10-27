<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\VectorFixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\Vector;

/**
 * Un article cherché par hybridSearch() (RG-09.5) : titre et corps en plein texte, un vecteur.
 */
#[Prism\Entity]
#[Prism\FullTextIndex(fields: ['title', 'body'], language: 'english')]
final class Article
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $topic,
        #[Prism\Column(length: 80)]
        public string $title,
        #[Prism\Column(type: 'text')]
        public string $body,
        #[Prism\Column(dimensions: 3)]
        #[Prism\VectorIndex(distance: Distance::Cosine)]
        public Vector $embedding,
    ) {
    }
}
