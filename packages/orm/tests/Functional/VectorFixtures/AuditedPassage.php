<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\VectorFixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Vector;

// un vecteur journalisé : l'ancienne valeur vient du snapshot, sous la forme lue en base
#[Prism\Entity]
#[Prism\Auditable]
final class AuditedPassage
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(dimensions: 3)]
        public Vector $embedding,
    ) {
    }
}
