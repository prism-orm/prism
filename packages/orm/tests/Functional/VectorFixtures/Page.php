<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\VectorFixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Vector;

/**
 * Une page dont le vecteur suit le titre et le contenu (RG-09.7, ADR 0018) ; sans index
 * vectoriel, MariaDB n'indexant pas une colonne nullable.
 */
#[Prism\Entity]
#[Prism\FullTextIndex(fields: ['title', 'content'], language: 'english')]
final class Page
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(dimensions: 16)]
    #[Prism\EmbeddedVector(source: ['title', 'content'])]
    public ?Vector $embedding = null;

    public function __construct(
        #[Prism\Column(length: 80)]
        public string $title,
        #[Prism\Column(type: 'text')]
        public string $content,
        #[Prism\Column(length: 20)]
        public string $section = 'guide',
    ) {
    }
}
