<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools\Fixtures;

use Prism\Orm\Mapping as Prism;

// identifiant CHAR(3) : les colonnes qui le visent sont CHAR(3) aussi
#[Prism\Entity]
final class Currency
{
    #[Prism\Id]
    #[Prism\Column(length: 3, fixed: true)]
    public string $code;

    #[Prism\Column(type: 'binary', length: 16, fixed: true)]
    public ?string $digest = null;
}
