<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table(name: 'tenant_tag')]
#[Prism\Tenanted]
final class Tag
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 20)]
        public string $name = '',
    ) {
    }
}
