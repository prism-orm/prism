<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Event\InverseSideChangedEvent;
use Prism\Orm\Event\PostRemoveEvent;
use Prism\Orm\Event\PreRemoveEvent;
use Prism\Orm\Exception\OrphanReattachedException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Album;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Artist;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Cover;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Photo;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Cascade\Track;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Invoice;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Tag;
use Prism\Orm\UnitOfWork\EntityState;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence des cascades remove et detach, d'orphanRemoval et du côté inverse
 * (phase 4, étape 4), écrits avant le code.
 *
 * Règles fixées ici :
 * - remove() suit cascade: remove (une collection non chargée est chargée), chaque entité avec
 *   ses événements ; orphanRemoval implique cascade: remove ; une entité NEW atteinte est ignorée ;
 * - detach() suit cascade: detach sans rien charger ;
 * - un élément retiré d'une collection orphanRemoval, ou l'ancienne cible d'un OneToOne
 *   orphanRemoval, est supprimé au flush ; rattaché ailleurs dans le même flush, il est refusé ;
 * - un côté inverse modifié sans le côté propriétaire publie InverseSideChangedEvent (RG-04.1).
 */
final class UnitOfWorkCascadeTest extends TestCase
{
    private InMemoryStorage $storage;

    private TestDispatcher $events;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->events = new TestDispatcher();
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), $this->events);
    }

    /**
     * @return list<string>
     */
    private function writes(): array
    {
        return array_values(array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], array_filter($this->storage->log, static fn (array $o): bool => \in_array($o[0], ['INSERT', 'UPDATE', 'DELETE'], true))));
    }

    private function seedAlbum(int $id, ?int $booklet = null, ?int $artist = null): void
    {
        $this->storage->seed('album', 'id', ['id' => $id, 'title' => 'Album ' . $id, 'booklet_id' => $booklet, 'artist_id' => $artist]);
    }

    /**
     * @param list<int> $ids
     */
    private function seedChildren(string $table, int $album, array $ids): void
    {
        foreach ($ids as $id) {
            $this->storage->seed($table, 'id', ['id' => $id, 'album_id' => $album, 'name' => $table . '-' . $id]);
        }
    }

    private function album(int $id): Album
    {
        return $this->uow->find(Album::class, $id) ?? throw new \LogicException('No album ' . $id);
    }

    // ---------------------------------------------------------------- cascade: remove (RG-04.6)

    public function testRemoveCascadesToTheElementsOfACollection(): void
    {
        $this->seedAlbum(1);
        $this->seedChildren('track', 1, [10, 11]);
        $album = $this->album(1);

        $this->uow->remove($album);

        $tracks = array_values($album->tracks->toArray());
        self::assertSame([EntityState::Removed, EntityState::Removed], array_map($this->uow->getEntityState(...), $tracks), 'the unloaded collection was loaded for the cascade');
        self::assertCount(3, $this->events->ofType(PreRemoveEvent::class));

        $this->uow->commit();
        self::assertSame(['DELETE track', 'DELETE track', 'DELETE album'], $this->writes(), 'children before their parent');
        self::assertCount(3, $this->events->ofType(PostRemoveEvent::class));
    }

    public function testRemoveCascadesToAToOneTarget(): void
    {
        $this->storage->seed('booklet', 'id', ['id' => 5, 'name' => 'Lyrics']);
        $this->seedAlbum(1, booklet: 5);

        $this->uow->remove($this->album(1));
        $this->uow->commit();

        self::assertSame(['DELETE album', 'DELETE booklet'], $this->writes(), 'the album holds the foreign key');
    }

    public function testRelationsWithoutCascadeRemoveAreLeftAlone(): void
    {
        $this->storage->seed('artist', 'id', ['id' => 3, 'name' => 'Nina']);
        $this->seedAlbum(1, artist: 3);
        $album = $this->album(1);

        $this->uow->remove($album);

        self::assertSame(EntityState::Managed, $this->uow->getEntityState($album->artist ?? throw new \LogicException()));
    }

    public function testRemovingAGraphThatWasNeverWrittenWritesNothing(): void
    {
        $album = new Album('Draft');
        $album->tracks->add($track = new Track($album, 'Intro'));
        $this->uow->persist($album);
        $this->uow->persist($track);

        $this->uow->remove($album);
        $this->uow->commit();

        self::assertSame([], $this->writes());
        self::assertSame(EntityState::New, $this->uow->getEntityState($track));
    }

    // ---------------------------------------------------------------- orphanRemoval (RG-04.5)

    public function testElementRemovedFromAnOrphanRemovalCollectionIsDeleted(): void
    {
        $this->seedAlbum(1);
        $this->seedChildren('photo', 1, [20, 21]);
        $album = $this->album(1);
        $photo = $album->photos->first();
        self::assertNotNull($photo);

        $album->photos->removeElement($photo);
        $this->uow->commit();

        self::assertSame(['DELETE photo'], $this->writes());
        self::assertSame(EntityState::New, $this->uow->getEntityState($photo));
        self::assertCount(1, $this->events->ofType(PostRemoveEvent::class));
    }

    public function testClearOfAnUnloadedOrphanRemovalCollectionDeletesItsElements(): void
    {
        $this->seedAlbum(1);
        $this->seedChildren('photo', 1, [20, 21]);

        $this->album(1)->photos->clear();
        $this->uow->commit();

        self::assertSame(['DELETE photo', 'DELETE photo'], $this->writes());
    }

    public function testOrphanRemovalImpliesCascadeRemove(): void
    {
        $this->seedAlbum(1);
        $this->seedChildren('photo', 1, [20]);

        $this->uow->remove($this->album(1));
        $this->uow->commit();

        self::assertSame(['DELETE photo', 'DELETE album'], $this->writes());
    }

    public function testOrphanReattachedToAnotherParentIsRefused(): void
    {
        $this->seedAlbum(1);
        $this->seedAlbum(2);
        $this->seedChildren('photo', 1, [20]);
        $first = $this->album(1);
        $second = $this->album(2);
        $photo = $first->photos->first();
        self::assertNotNull($photo);

        $first->photos->removeElement($photo);
        $photo->album = $second;
        $second->photos->add($photo);

        try {
            $this->uow->commit();
            self::fail('Expected OrphanReattachedException.');
        } catch (\LogicException $e) {
            self::assertInstanceOf(OrphanReattachedException::class, $e);
            self::assertSame(\sprintf('The %s entity removed from %s::$photos (orphanRemoval) is deleted by this flush; it cannot be attached to another %s in the same flush. Flush the removal first, or move it without orphanRemoval.', Photo::class, Album::class, Album::class), $e->getMessage());
            self::assertSame($photo, $e->entity);
        }
        self::assertSame([], $this->writes());
    }

    public function testReassignedOwningOneToOneOrphanIsDeleted(): void
    {
        $this->storage->seed('booklet', 'id', ['id' => 5, 'name' => 'Lyrics']);
        $this->seedAlbum(1, booklet: 5);
        $album = $this->album(1);

        $album->booklet = null;
        $this->uow->commit();

        self::assertSame(['UPDATE album', 'DELETE booklet'], $this->writes(), 'the foreign key is cleared before the delete');
    }

    public function testInverseOneToOneOrphanIsDeleted(): void
    {
        $this->seedAlbum(1);
        $this->storage->seed('cover', 'id', ['id' => 7, 'album_id' => 1, 'file' => 'front.jpg']);
        $album = $this->album(1);
        self::assertNotNull($album->cover);

        $album->cover = null;
        $this->uow->commit();

        self::assertSame(['DELETE cover'], $this->writes());

        $this->storage->log = [];
        $this->uow->commit();
        self::assertSame([], $this->writes(), 'done once');
    }

    // ---------------------------------------------------------------- cascade: detach

    public function testDetachCascadesWithoutLoading(): void
    {
        $this->storage->seed('artist', 'id', ['id' => 3, 'name' => 'Nina']);
        $this->seedAlbum(1, artist: 3);
        $this->seedChildren('track', 1, [10]);
        $album = $this->album(1);
        $artist = $album->artist;
        self::assertNotNull($artist);

        $this->uow->detach($album);

        self::assertSame(EntityState::Detached, $this->uow->getEntityState($artist));
        self::assertCount(0, array_filter($this->storage->operations('SELECT'), static fn (array $o): bool => 'artist' === $o[0] || 'track' === $o[0]), 'the reference and the collection stay unloaded');
    }

    public function testDetachDoesNotFollowOtherRelations(): void
    {
        $this->seedAlbum(1);
        $this->seedChildren('track', 1, [10]);
        $album = $this->album(1);
        $track = $album->tracks->first();
        self::assertNotNull($track);

        $this->uow->detach($album);

        self::assertSame(EntityState::Managed, $this->uow->getEntityState($track));
    }

    // ---------------------------------------------------------------- côté inverse (RG-04.1)

    public function testInverseSideChangedAloneIsReported(): void
    {
        $this->seedAlbum(1);
        $this->seedAlbum(2);
        $this->seedChildren('track', 1, [10]);
        $this->seedChildren('track', 2, [11]);
        $first = $this->album(1);
        $second = $this->album(2);
        $own = $first->tracks->first();
        $other = $second->tracks->first();
        self::assertNotNull($own);
        self::assertNotNull($other);

        $first->tracks->removeElement($own);
        $first->tracks->add($other);
        $this->uow->commit();

        $events = $this->events->ofType(InverseSideChangedEvent::class);
        self::assertCount(2, $events);
        [$added, $removed] = $events[0] instanceof InverseSideChangedEvent && $events[0]->added ? $events : array_reverse($events);
        self::assertInstanceOf(InverseSideChangedEvent::class, $added);
        self::assertInstanceOf(InverseSideChangedEvent::class, $removed);
        self::assertSame([$first, 'tracks', $other, true], [$added->owner, $added->field, $added->element, $added->added]);
        self::assertSame([$first, 'tracks', $own, false], [$removed->owner, $removed->field, $removed->element, $removed->added]);
        self::assertSame([], $this->writes(), 'nothing is written');
    }

    public function testConsistentChangeOfBothSidesIsNotReported(): void
    {
        $this->seedAlbum(1);
        $this->seedAlbum(2);
        $this->seedChildren('track', 2, [11]);
        $first = $this->album(1);
        $second = $this->album(2);
        $track = $second->tracks->first();
        self::assertNotNull($track);

        $second->tracks->removeElement($track);
        $track->album = $first;
        $first->tracks->add($track);
        $this->uow->commit();

        self::assertSame([], $this->events->ofType(InverseSideChangedEvent::class));
        self::assertSame(['UPDATE track'], $this->writes());
    }

    public function testInverseManyToManyChangedAloneIsReported(): void
    {
        $this->storage->seed('tag', 'id', ['id' => 1, 'name' => 'urgent']);
        $this->storage->seed('invoice', 'id', ['id' => 1, 'number' => 'INV-1']);
        $tag = $this->uow->find(Tag::class, 1);
        $invoice = $this->uow->find(Invoice::class, 1);
        self::assertNotNull($tag);
        self::assertNotNull($invoice);
        self::assertCount(0, $invoice->tags, 'the owning side is loaded and does not hold the tag');

        $tag->invoices->add($invoice);
        $this->uow->commit();

        self::assertCount(1, $this->events->ofType(InverseSideChangedEvent::class));
        self::assertSame([], $this->writes());
    }

    public function testUnloadedOwningSideIsNeverLoadedForTheCheck(): void
    {
        $this->storage->seed('tag', 'id', ['id' => 1, 'name' => 'urgent']);
        $this->storage->seed('invoice', 'id', ['id' => 1, 'number' => 'INV-1']);
        $tag = $this->uow->find(Tag::class, 1);
        $invoice = $this->uow->find(Invoice::class, 1);
        self::assertNotNull($tag);
        self::assertNotNull($invoice);

        $tag->invoices->add($invoice);
        $this->uow->commit();

        self::assertSame([], $this->events->ofType(InverseSideChangedEvent::class));
        self::assertCount(1, array_filter($this->storage->operations('SELECT'), static fn (array $o): bool => 'tag' === $o[0]), 'Invoice::$tags stays unloaded: only find() read the tag table');
    }

    // ---------------------------------------------------------------- compléments (mutants)

    public function testDetachFollowsALoadedCollectionAndNeverLoadsOne(): void
    {
        $this->seedAlbum(1);
        $this->seedAlbum(2);
        $this->seedChildren('photo', 1, [20]);
        $this->seedChildren('photo', 2, [21]);
        $loaded = $this->album(1);
        $photo = $loaded->photos->first();
        self::assertNotNull($photo);
        $unloaded = $this->album(2);
        $this->storage->log = [];

        $this->uow->detach($loaded);
        $this->uow->detach($unloaded);

        self::assertSame(EntityState::Detached, $this->uow->getEntityState($photo));
        self::assertSame([], $this->storage->operations('SELECT'));
    }

    public function testReplacedUnloadedOrphanRemovalCollectionDeletesItsElements(): void
    {
        $this->seedAlbum(1);
        $this->seedChildren('photo', 1, [20, 21]);
        $album = $this->album(1);

        $album->photos = new ArrayCollection();
        $this->uow->commit();

        self::assertSame(['DELETE photo', 'DELETE photo'], $this->writes());
    }

    public function testOrphansOfSeveralEntitiesInOneFlush(): void
    {
        $this->storage->seed('artist', 'id', ['id' => 3, 'name' => 'Nina']);
        $this->seedAlbum(1);
        $this->seedAlbum(2);
        $this->seedChildren('photo', 2, [20, 21]);
        // devant : une entité sans relation, une entité supprimée
        $this->uow->find(Artist::class, 3);
        $this->uow->remove($this->album(1));
        $album = $this->album(2);
        [$first, $second] = array_values($album->photos->toArray());
        $this->uow->remove($first);

        $album->photos->removeElement($first);
        $album->photos->removeElement($second);
        $this->uow->commit();

        self::assertSame(['DELETE photo', 'DELETE photo', 'DELETE album'], $this->writes());
    }

    public function testReplacedThenClearedInverseOneToOneDeletesEachOrphan(): void
    {
        $this->seedAlbum(1);
        $this->storage->seed('cover', 'id', ['id' => 7, 'album_id' => 1, 'file' => 'old.jpg']);
        $album = $this->album(1);

        $album->cover = new Cover($album, 'new.jpg');
        $this->uow->commit();
        self::assertSame(['INSERT cover', 'DELETE cover'], $this->writes());

        $this->storage->log = [];
        $album->cover = null;
        $this->uow->commit();
        self::assertSame(['DELETE cover'], $this->writes(), 'the new cover became the one to watch');
    }

    public function testInverseOneToOneSetWhereThereWasNoneIsNoOrphan(): void
    {
        $this->seedAlbum(1);
        $album = $this->album(1);

        $album->cover = new Cover($album, 'first.jpg');
        $this->uow->commit();

        self::assertSame(['INSERT cover'], $this->writes());
    }

    public function testOwningOneToOneOrphanReattachedIsRefused(): void
    {
        $this->storage->seed('booklet', 'id', ['id' => 5, 'name' => 'Lyrics']);
        $this->seedAlbum(1, booklet: 5);
        $this->seedAlbum(2);
        $first = $this->album(1);
        $second = $this->album(2);

        $second->booklet = $first->booklet;
        $first->booklet = null;

        $this->expectException(OrphanReattachedException::class);
        $this->expectExceptionMessage('removed from ' . Album::class . '::$booklet (orphanRemoval)');

        $this->uow->commit();
    }

    public function testInverseChangesAreCheckedAfterOtherCollections(): void
    {
        $this->seedAlbum(1);
        $this->seedAlbum(2);
        $this->seedChildren('photo', 1, [20]);
        $this->seedChildren('track', 2, [10]);
        $this->storage->seed('tag', 'id', ['id' => 1, 'name' => 'urgent']);
        $this->storage->seed('invoice', 'id', ['id' => 1, 'number' => 'INV-1']);
        // dans l'ordre : une ManyToMany propriétaire, une collection orphanRemoval, puis l'incohérence
        $invoice = $this->uow->find(Invoice::class, 1);
        $tag = $this->uow->find(Tag::class, 1);
        self::assertNotNull($invoice);
        self::assertNotNull($tag);
        $invoice->tags->add($tag);
        $first = $this->album(1);
        $photo = $first->photos->first();
        self::assertNotNull($photo);
        $first->photos->removeElement($photo);
        $second = $this->album(2);
        $track = $second->tracks->first();
        self::assertNotNull($track);

        $second->tracks->removeElement($track);
        $this->uow->commit();

        $events = $this->events->ofType(InverseSideChangedEvent::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(InverseSideChangedEvent::class, $events[0]);
        self::assertSame($track, $events[0]->element);
    }

    public function testOwningSideStillInAnArrayCollectionIsChecked(): void
    {
        $this->storage->seed('tag', 'id', ['id' => 1, 'name' => 'urgent']);
        $tag = $this->uow->find(Tag::class, 1);
        self::assertNotNull($tag);
        $invoice = new Invoice('INV-NEW');
        $this->uow->persist($invoice);

        $tag->invoices->add($invoice);
        $this->uow->commit();

        self::assertCount(1, $this->events->ofType(InverseSideChangedEvent::class));
    }
}
