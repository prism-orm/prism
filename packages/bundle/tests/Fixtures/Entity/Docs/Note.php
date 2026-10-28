<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Docs;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Vector;

// une note d'un tenant, dont le vecteur suit le texte (ADR 0018)
#[Prism\Entity]
#[Prism\Tenanted]
final class Note
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(dimensions: 8)]
    #[Prism\EmbeddedVector(source: 'text')]
    public ?Vector $embedding = null;

    public function __construct(
        #[Prism\Column(type: 'text')]
        public string $text,
    ) {
    }
}
