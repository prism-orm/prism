<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\BatchFixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Bill
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    public function __construct(
        #[Prism\ManyToOne]
        public Client $client,
        #[Prism\Column(length: 20)]
        public string $number = '',
    ) {
    }
}
