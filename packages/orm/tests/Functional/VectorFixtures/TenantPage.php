<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\VectorFixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Vector;

/**
 * Une page d'un tenant : le lot de vecteurs périmés porte le tenant, l'updater ne lit que
 * celles du tenant courant (RG-09.11, ADR 0018).
 */
#[Prism\Entity]
#[Prism\Tenanted]
final class TenantPage
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(dimensions: 16)]
    #[Prism\EmbeddedVector(source: 'content')]
    public ?Vector $embedding = null;

    public function __construct(
        #[Prism\Column(type: 'text')]
        public string $content,
    ) {
    }
}
