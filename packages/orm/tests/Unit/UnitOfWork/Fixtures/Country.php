<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;

// identifiant assigné : seule la WeakMap des entités détachées distingue DETACHED de NEW
#[Prism\Entity]
final class Country
{
    public function __construct(
        #[Prism\Id, Prism\Column(length: 2)]
        public string $code = '',
        #[Prism\Column(length: 80)]
        public string $name = '',
    ) {
    }
}
