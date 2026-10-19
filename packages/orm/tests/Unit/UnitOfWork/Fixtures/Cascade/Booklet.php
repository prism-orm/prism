<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Booklet
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $name = '',
    ) {
    }
}
