<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Audit;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table(name: 'audited_note')]
#[Prism\Auditable]
#[Prism\Timestampable]
#[Prism\Blameable]
final class Note
{
    #[Prism\Id, Prism\GeneratedValue]
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
