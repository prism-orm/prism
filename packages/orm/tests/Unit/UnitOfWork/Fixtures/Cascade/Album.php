<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Album
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    /** @var Collection<int, Track> cascade: remove */
    #[Prism\OneToMany(targetEntity: Track::class, mappedBy: 'album', cascade: [Prism\Cascade::Persist, Prism\Cascade::Remove])]
    public Collection $tracks;

    /** @var Collection<int, Photo> orphanRemoval, cascade: detach */
    #[Prism\OneToMany(targetEntity: Photo::class, mappedBy: 'album', cascade: [Prism\Cascade::Persist, Prism\Cascade::Detach], orphanRemoval: true)]
    public Collection $photos;

    // côté inverse, orphanRemoval
    #[Prism\OneToOne(mappedBy: 'album', cascade: [Prism\Cascade::Persist], orphanRemoval: true)]
    public ?Cover $cover = null;

    // côté propriétaire, orphanRemoval et cascade: remove
    #[Prism\OneToOne(cascade: [Prism\Cascade::Persist, Prism\Cascade::Remove], orphanRemoval: true)]
    public ?Booklet $booklet = null;

    // cascade: detach seulement
    #[Prism\ManyToOne(cascade: [Prism\Cascade::Detach])]
    public ?Artist $artist = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $title = '',
    ) {
        $this->tracks = new ArrayCollection();
        $this->photos = new ArrayCollection();
    }
}
