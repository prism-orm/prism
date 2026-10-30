<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\SoftDeletable]
final class Archive
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, ArchiveNote> */
    #[Prism\OneToMany(targetEntity: ArchiveNote::class, mappedBy: 'archive', cascade: [Prism\Cascade::Remove])]
    public Collection $notes;

    public function __construct()
    {
        $this->notes = new ArrayCollection();
    }
}
