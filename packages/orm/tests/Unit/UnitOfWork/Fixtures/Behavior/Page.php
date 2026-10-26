<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

// RG-08.15 : le slug est unique parmi les pages non supprimées
#[Prism\Entity]
#[Prism\SoftDeletable]
final class Page
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, Link> */
    #[Prism\OneToMany(targetEntity: Link::class, mappedBy: 'page', cascade: [Prism\Cascade::Remove])]
    public Collection $links;

    public function __construct(
        #[Prism\Column(length: 40, unique: true)]
        public string $slug = '',
    ) {
        $this->links = new ArrayCollection();
    }
}
