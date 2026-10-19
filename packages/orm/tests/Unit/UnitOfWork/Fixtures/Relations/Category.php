<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Mapping as Prism;

// auto-référence
#[Prism\Entity]
final class Category
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $name = '',
        #[Prism\ManyToOne(cascade: [Prism\Cascade::Persist])]
        public ?self $parent = null,
    ) {
    }
}
