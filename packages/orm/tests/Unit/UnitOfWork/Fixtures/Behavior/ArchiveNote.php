<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class ArchiveNote
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\ManyToOne(inversedBy: 'notes')]
        public Archive $archive,
        #[Prism\Column(length: 80)]
        public string $text = '',
    ) {
    }
}
