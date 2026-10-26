<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Timestampable]
#[Prism\Blameable]
final class Memo
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;

    #[Prism\Column]
    public ?\DateTimeImmutable $updatedAt = null;

    #[Prism\Column(length: 80)]
    public ?string $createdBy = null;

    #[Prism\Column(length: 80)]
    public ?string $updatedBy = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $title = '',
    ) {
    }
}
