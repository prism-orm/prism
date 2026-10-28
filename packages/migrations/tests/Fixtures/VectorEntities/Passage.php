<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\VectorEntities;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Vector\Vector;

// un passage d'un tenant, cherché par nearest() : L21 (RG-09.11)
#[Prism\Entity]
#[Prism\Table('passage')]
#[Prism\Tenanted]
final class Passage
{
    #[Prism\Id]
    public int $id = 0;

    #[Prism\Column(dimensions: 3)]
    public Vector $embedding;
}
