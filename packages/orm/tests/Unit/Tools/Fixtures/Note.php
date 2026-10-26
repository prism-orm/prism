<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools\Fixtures;

use Prism\Orm\Mapping as Prism;

// entité tenantée sans index déclaré : la colonne du tenant a son propre index (RG-08.1)
#[Prism\Entity]
#[Prism\Tenanted]
final class Note
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20, unique: true)]
    public string $code = '';
}
