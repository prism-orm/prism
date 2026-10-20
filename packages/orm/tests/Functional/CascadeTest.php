<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Orm\EntityManager;
use Prism\Orm\Event\InverseSideChangedEvent;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Album;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Artist;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Booklet;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Cover;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Photo;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Track;

/**
 * Cascades remove, orphanRemoval et côté inverse sur base réelle : les contraintes de clé
 * étrangère vérifient l'ordre des DELETE (RG-04.5, RG-04.6), sur les 4 bases.
 */
final class CascadeTest extends MappingSchemaTestCase
{
    private EntityManager $em;

    private TestDispatcher $events;

    protected static function entities(): array
    {
        return [Album::class, Track::class, Photo::class, Cover::class, Booklet::class, Artist::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new TestDispatcher();
        $this->em = new EntityManager($this->connection, events: $this->events);
    }

    private function rowsIn(string $table): int
    {
        $count = $this->connection->createQueryBuilder()->select('COUNT(*)')->from($table)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    private function fullAlbum(): Album
    {
        $album = new Album('Blue');
        $album->tracks->add(new Track($album, 'Intro'));
        $album->tracks->add(new Track($album, 'Outro'));
        $album->photos->add(new Photo($album, 'front'));
        $album->cover = new Cover($album, 'cover.jpg');
        $album->booklet = new Booklet('Lyrics');
        $album->artist = new Artist('Nina');
        $this->em->persist($album->artist);
        $this->em->persist($album);
        $this->em->flush();
        $this->em->clear();

        return $this->em->find(Album::class, $album->id) ?? throw new \LogicException('Album not found.');
    }

    public function testRemovingAnAlbumRemovesWhatItCascadesTo(): void
    {
        $album = $this->fullAlbum();

        $this->em->remove($album);
        $this->em->flush();

        self::assertSame(
            ['album' => 0, 'track' => 0, 'photo' => 0, 'cover' => 0, 'booklet' => 0, 'artist' => 1],
            array_combine(['album', 'track', 'photo', 'cover', 'booklet', 'artist'], array_map($this->rowsIn(...), ['album', 'track', 'photo', 'cover', 'booklet', 'artist'])),
        );
    }

    public function testOrphansAreDeleted(): void
    {
        $album = $this->fullAlbum();
        $photo = $album->photos->first();
        self::assertNotNull($photo);

        $album->photos->removeElement($photo);
        $album->cover = null;
        $album->booklet = null;
        $this->em->flush();

        self::assertSame([0, 0, 0, 1], [$this->rowsIn('photo'), $this->rowsIn('cover'), $this->rowsIn('booklet'), $this->rowsIn('album')]);
    }

    public function testInverseSideChangedAloneIsReportedAndNotWritten(): void
    {
        $album = $this->fullAlbum();
        $track = $album->tracks->first();
        self::assertNotNull($track);

        $album->tracks->removeElement($track);
        $this->em->flush();

        self::assertCount(1, $this->events->ofType(InverseSideChangedEvent::class));
        self::assertSame(2, $this->rowsIn('track'), 'RG-04.1: the track still belongs to the album');
    }
}
