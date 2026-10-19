<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\BatchFixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Client
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $name = '',
    ) {
    }
}
