<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\SoftDeletable]
final class Link
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        #[Prism\ManyToOne(inversedBy: 'links')]
        public Page $page,
        #[Prism\Column(length: 80)]
        public string $url = '',
    ) {
    }
}
