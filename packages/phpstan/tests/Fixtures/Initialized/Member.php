<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures\Initialized;

use Prism\Orm\Mapping as Prism;

// mappés sans constructeur : hydratés (RG-03.12) ; $nickname n'est pas mappé (RG-02.7)
#[Prism\Entity]
final class Member
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public int $id;

    #[Prism\Column(length: 80)]
    public string $name;

    public string $nickname;
}
