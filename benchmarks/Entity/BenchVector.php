<?php

declare(strict_types=1);

namespace Prism\Benchmarks\Entity;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\Vector;

// un document de make bench-vector : un vecteur de 768 dimensions (nomic-embed-text), une catégorie pour filtrer
// la catégorie est indexée, comme le serait un tenant_id : un filtre sélectif s'appuie dessus
#[Prism\Entity]
#[Prism\Index(fields: ['category'])]
final class BenchVector
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public int $category = 0;

    #[Prism\Column(dimensions: 768)]
    #[Prism\VectorIndex(distance: Distance::Cosine)]
    public Vector $embedding;
}
