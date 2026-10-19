<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Country
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $name = '',
    ) {
    }
}
