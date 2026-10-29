<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Event\PostPersistEvent;
use Prism\Orm\Event\PostRemoveEvent;
use Prism\Orm\Event\PostUpdateEvent;
use Prism\Orm\Event\PrePersistEvent;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Exception\OptimisticLockException;
use Prism\Orm\Exception\ReentrantFlushException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Article;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\AuditLine;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Country;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Note;
use Prism\Orm\UnitOfWork\EntityState;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence de l'UnitOfWork, écrits avant son implémentation.
 *
 * Règles de comportement fixées ici, en complément de la roadmap :
 * - une opération qui laisse l'entité dans son état est sans effet ; seules les transitions
 *   absentes de RG-03.2 lèvent InvalidEntityStateException ;
 * - les événements Post* sont publiés après le commit, jamais pendant la transaction ;
 * - une entité supprimée par le flush redevient NEW ;
 * - une entité persistée puis retirée avant tout flush redevient NEW (rien à supprimer).
 */
final class UnitOfWorkTest extends TestCase
{
    private InMemoryStorage $storage;

    private TestDispatcher $events;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->events = new TestDispatcher();
        $this->uow = $this->newUnitOfWork();
    }

    private function newUnitOfWork(): UnitOfWork
    {
        return new UnitOfWork(
            new MetadataFactory(new AttributeDriver()),
            $this->storage,
            new ConversionContext(new SqlitePlatform('3.46')),
            TypeRegistry::withDefaults(),
            $this->events,
        );
    }

    private static function article(string $title = 'Hello'): Article
    {
        $article = new Article(strtolower($title));
        $article->title = $title;

        return $article;
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function flushed(object $entity): object
    {
        $this->uow->persist($entity);
        $this->uow->commit();
        $this->storage->log = [];
        $this->events->dispatched = [];

        return $entity;
    }

    private function seedArticle(int $id = 1, string $price = '12.50'): void
    {
        $this->storage->seed('article', 'id', [
            'id' => $id, 'title' => 'Hello', 'price' => $price, 'published_at' => '2026-10-18 09:00:00', 'status' => 'draft',
            'place_street' => null, 'place_zip' => null, 'place_city' => null, 'created_by' => 'alice', 'computed' => 'x', 'slug' => 'hello',
        ]);
    }

    /**
     * @return list<string>
     */
    private function updatedColumns(): array
    {
        return array_merge(...array_map(static fn (array $o): array => $o[1], $this->storage->operations('UPDATE')));
    }

    // ---------------------------------------------------------------- états (RG-03.1, RG-03.2)

    public function testNewEntityIsUnknown(): void
    {
        self::assertSame(EntityState::New, $this->uow->getEntityState(self::article()));
        self::assertFalse($this->uow->contains(self::article()));
    }

    public function testPersistMakesANewEntityManaged(): void
    {
        $article = self::article();
        $this->uow->persist($article);

        self::assertSame(EntityState::Managed, $this->uow->getEntityState($article));
        self::assertTrue($this->uow->contains($article));
        self::assertSame([], $this->storage->log, 'persist() writes nothing before flush');
    }

    public function testPersistIsANoOpOnAManagedEntity(): void
    {
        $article = $this->flushed(self::article());
        $this->uow->persist($article);
        $this->uow->commit();

        self::assertSame(EntityState::Managed, $this->uow->getEntityState($article));
        self::assertSame([], $this->storage->operations('INSERT'));
    }

    public function testRemoveMakesAManagedEntityRemoved(): void
    {
        $article = $this->flushed(self::article());
        $this->uow->remove($article);

        self::assertSame(EntityState::Removed, $this->uow->getEntityState($article));
        self::assertFalse($this->uow->contains($article));
    }

    public function testRemovedEntityIsDeletedByFlushAndBecomesNew(): void
    {
        $article = $this->flushed(self::article());
        $this->uow->remove($article);
        $this->uow->commit();

        self::assertSame([['article', []]], $this->storage->operations('DELETE'));
        self::assertSame([], $this->storage->tables['article']);
        self::assertSame(EntityState::New, $this->uow->getEntityState($article));
    }

    public function testPersistBeforeFlushCancelsTheRemoval(): void
    {
        $article = $this->flushed(self::article());
        $this->uow->remove($article);
        $this->uow->persist($article);
        $this->uow->commit();

        self::assertSame(EntityState::Managed, $this->uow->getEntityState($article));
        self::assertSame([], $this->storage->operations('DELETE'));
    }

    public function testRemoveIsANoOpOnARemovedEntity(): void
    {
        $article = $this->flushed(self::article());
        $this->uow->remove($article);
        $this->uow->remove($article);
        $this->uow->commit();

        self::assertCount(1, $this->storage->operations('DELETE'));
    }

    public function testRemovingAPersistedButUnflushedEntityCancelsItsInsertion(): void
    {
        $article = self::article();
        $this->uow->persist($article);
        $this->uow->remove($article);
        $this->uow->commit();

        self::assertSame(EntityState::New, $this->uow->getEntityState($article));
        self::assertSame([], $this->storage->log);
    }

    public function testDetachAndClearMakeManagedEntitiesDetached(): void
    {
        $detached = $this->flushed(self::article('A'));
        $cleared = $this->flushed(self::article('B'));
        $removed = $this->flushed(self::article('C'));
        $this->uow->remove($removed);

        $this->uow->detach($detached);
        self::assertSame(EntityState::Detached, $this->uow->getEntityState($detached));

        $this->uow->clear();
        self::assertSame(EntityState::Detached, $this->uow->getEntityState($cleared));
        self::assertSame(EntityState::Detached, $this->uow->getEntityState($removed), 'clear() also forgets scheduled removals');
        $this->uow->commit();
        self::assertSame([], $this->storage->log);
    }

    public function testDetachedEntityWithAnAssignedIdIsStillRecognised(): void
    {
        $country = $this->flushed(new Country('FR', 'France'));
        $this->uow->detach($country);

        self::assertSame(EntityState::Detached, $this->uow->getEntityState($country));
        self::assertSame(EntityState::New, $this->uow->getEntityState(new Country('FR', 'France')));
    }

    public function testDetachAndClearAreNoOpsOnADetachedEntity(): void
    {
        $article = $this->flushed(self::article());
        $this->uow->detach($article);
        $this->uow->detach($article);
        $this->uow->clear();

        self::assertSame(EntityState::Detached, $this->uow->getEntityState($article));
    }

    public function testClearIsANoOpOnANewEntity(): void
    {
        $article = self::article();
        $this->uow->clear();

        self::assertSame(EntityState::New, $this->uow->getEntityState($article));
    }

    public function testFlushWritesNothingForNewOrDetachedEntities(): void
    {
        $new = self::article('New');
        $detached = $this->flushed(self::article('Detached'));
        $this->uow->detach($detached);
        $detached->title = 'Changed';
        $new->title = 'Changed';

        $this->uow->commit();

        self::assertSame([], $this->storage->log);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'remove a NEW entity' => ['new', 'remove', 'Cannot remove a NEW entity'];
        yield 'detach a NEW entity' => ['new', 'detach', 'Cannot detach a NEW entity'];
        yield 'detach a REMOVED entity' => ['removed', 'detach', 'Cannot detach a REMOVED entity'];
        yield 'persist a DETACHED entity' => ['detached', 'persist', 'Cannot persist a DETACHED entity'];
        yield 'remove a DETACHED entity' => ['detached', 'remove', 'Cannot remove a DETACHED entity'];
    }

    // RG-03.1 : toute transition non listée lève InvalidEntityStateException
    #[DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitions(string $state, string $operation, string $message): void
    {
        $article = self::article();
        if ('new' !== $state) {
            $this->flushed($article);
        }
        if ('removed' === $state) {
            $this->uow->remove($article);
        }
        if ('detached' === $state) {
            $this->uow->detach($article);
        }

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage($message . ' of class ' . Article::class);

        match ($operation) {
            'persist' => $this->uow->persist($article),
            'remove' => $this->uow->remove($article),
            'detach' => $this->uow->detach($article),
            default => throw new \LogicException($operation),
        };
    }

    // ---------------------------------------------------------------- identity map (RG-03.3)

    public function testFindReturnsTheSameInstanceUntilClear(): void
    {
        $this->seedArticle();

        $first = $this->uow->find(Article::class, 1);
        self::assertInstanceOf(Article::class, $first);
        self::assertSame($first, $this->uow->find(Article::class, 1));
        self::assertCount(1, $this->storage->operations('SELECT'), 'the second find() is served by the identity map');
        self::assertSame(EntityState::Managed, $this->uow->getEntityState($first));

        $this->uow->clear();
        $second = $this->uow->find(Article::class, 1);
        self::assertNotSame($first, $second);
    }

    public function testFlushedEntityIsFoundWithoutQuery(): void
    {
        $article = $this->flushed(self::article());

        self::assertSame($article, $this->uow->find(Article::class, $article->id));
        self::assertSame([], $this->storage->operations('SELECT'));
    }

    public function testFindReturnsNullForAMissingRow(): void
    {
        self::assertNull($this->uow->find(Article::class, 404));
    }

    public function testSameIdInTwoClassesGivesTwoEntities(): void
    {
        $this->seedArticle();
        $this->storage->seed('document', 'id', ['id' => 1, 'title' => 'Doc', 'version' => 1]);

        self::assertInstanceOf(Article::class, $this->uow->find(Article::class, 1));
        self::assertInstanceOf(Document::class, $this->uow->find(Document::class, 1));
    }

    // RG-03.12 et conversion des types : l'entité relue porte des valeurs PHP
    public function testFoundEntityIsHydratedWithPhpValues(): void
    {
        $this->seedArticle();

        $article = $this->uow->find(Article::class, 1);

        self::assertNotNull($article);
        self::assertSame(['Hello', '12.50', FixtureStatus::Draft, 'hello', null], [$article->title, $article->price, $article->status, $article->slug, $article->place]);
        self::assertEquals(new \DateTimeImmutable('2026-10-18 09:00:00'), $article->publishedAt);
    }

    // ---------------------------------------------------------------- changements (RG-03.4, RG-03.5)

    public function testUnmodifiedEntityProducesNoUpdate(): void
    {
        $this->seedArticle();
        $this->uow->find(Article::class, 1);

        $this->uow->commit();

        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    public function testUpdateContainsOnlyTheChangedColumn(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->title = 'Changed';
        $this->uow->commit();

        self::assertSame([['article', ['title']]], $this->storage->operations('UPDATE'));
        self::assertSame('Changed', $this->storage->row('article', 1)['title']);
    }

    public function testValueChangedBackProducesNoUpdate(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->title = 'Changed';
        $article->title = 'Hello';
        $this->uow->commit();

        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    // RG-03.4 : comparaison des valeurs converties en base, pas des objets PHP
    public function testNewDateInstanceWithTheSameValueProducesNoUpdate(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->publishedAt = new \DateTimeImmutable('2026-10-18 09:00:00');
        $this->uow->commit();

        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    // décision A : un decimal est comparé à l'échelle de sa colonne
    public function testDecimalWithoutTrailingZerosProducesNoUpdate(): void
    {
        $this->seedArticle(price: '12.50');
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->price = '12.5';
        $this->uow->commit();

        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    public function testDecimalChangeIsDetected(): void
    {
        $this->seedArticle(price: '12.50');
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->price = '12.51';
        $this->uow->commit();

        self::assertSame(['price'], $this->updatedColumns());
    }

    public function testEmbeddableChangeUpdatesOnlyItsColumns(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->place = new Address('1 rue de la Paix', '75002', 'Paris');
        $this->uow->commit();

        self::assertSame(['place_street', 'place_zip', 'place_city'], $this->updatedColumns());
    }

    public function testEnumChangeIsDetected(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->status = FixtureStatus::Paid;
        $this->uow->commit();

        self::assertSame(['status'], $this->updatedColumns());
        self::assertSame('paid', $this->storage->row('article', 1)['status']);
    }

    // RG-02.6 et options de colonne
    public function testNonUpdatableColumnsAreNeverUpdated(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $article->createdBy = 'mallory';
        $article->computed = 'changed';
        $this->uow->commit();

        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    public function testNonInsertableColumnIsNotInserted(): void
    {
        $this->flushedWithoutReset(self::article());

        self::assertNotContains('computed', $this->storage->operations('INSERT')[0][1]);
        self::assertContains('slug', $this->storage->operations('INSERT')[0][1], 'a readonly property is written once, at insertion');
    }

    public function testReadOnlyEntityIsNeverUpdated(): void
    {
        $line = $this->flushed(new AuditLine());
        $line->message = 'tampered';

        $this->uow->commit();

        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    public function testInsertedValuesBecomeTheSnapshot(): void
    {
        $article = $this->flushed(self::article());

        $this->uow->commit();

        self::assertSame([], $this->storage->operations('UPDATE'), 'nothing changed since the INSERT');
        self::assertSame(1, $article->id, 'the IDENTITY value is written back to the entity');
    }

    // ---------------------------------------------------------------- flush atomique (RG-03.6)

    // critère de sortie : exception au 3e INSERT, aucune ligne en base, UnitOfWork inchangée
    public function testFailureOnTheThirdInsertRollsEverythingBack(): void
    {
        $articles = [self::article('A'), self::article('B'), self::article('C')];
        foreach ($articles as $article) {
            $this->uow->persist($article);
        }
        $this->storage->failOnInsert(3);

        try {
            $this->uow->commit();
            self::fail('Expected the simulated failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated failure on INSERT #3 into "article".', $e->getMessage());
        }

        self::assertSame([], $this->storage->tables['article'] ?? [], 'no row in the database');
        foreach ($articles as $article) {
            self::assertSame(EntityState::Managed, $this->uow->getEntityState($article), 'still scheduled for insertion');
            self::assertNull($article->id, 'generated identifiers are reset');
        }
        self::assertSame([], $this->events->ofType(PostPersistEvent::class), 'no Post* event for a rolled back flush');

        $this->storage->failOnInsert(null);
        $this->storage->log = [];
        $this->uow->commit();
        self::assertCount(3, $this->storage->operations('INSERT'), 'the second flush inserts each entity once');
        self::assertCount(3, $this->storage->tables['article']);
    }

    public function testFailedFlushKeepsPendingChangesAndSnapshots(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);
        $article->title = 'Changed';
        $this->uow->persist(self::article('New'));
        $this->storage->failOnInsert(1);

        try {
            $this->uow->commit();
            self::fail('Expected the simulated failure.');
        } catch (\RuntimeException) {
        }

        self::assertSame('Hello', $this->storage->row('article', 1)['title']);
        $this->storage->failOnInsert(null);
        $this->storage->log = [];
        $this->uow->commit();
        self::assertSame(['title'], $this->updatedColumns(), 'the change is still pending, compared to the original snapshot');
    }

    public function testEverythingRunsInOneTransaction(): void
    {
        $this->uow->persist(self::article('A'));
        $this->uow->persist(self::article('B'));

        $this->uow->commit();

        self::assertSame(1, $this->storage->transactions);
    }

    public function testEmptyFlushOpensNoTransaction(): void
    {
        $this->uow->commit();

        self::assertSame(0, $this->storage->transactions);
    }

    // ---------------------------------------------------------------- événements

    // RG-03.7
    public function testFlushFromAListenerIsRefused(): void
    {
        $this->events->listen(PrePersistEvent::class, function (): void {
            $this->uow->commit();
        });
        $this->uow->persist(self::article());

        $this->expectException(ReentrantFlushException::class);

        $this->uow->commit();
    }

    // RG-03.8
    public function testChangeMadeInPreUpdateIsIncluded(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);
        $this->events->listen(PreUpdateEvent::class, static function (PreUpdateEvent $event): void {
            if ($event->entity instanceof Article) {
                $event->entity->price = '99.00';
            }
        });

        $article->title = 'Changed';
        $this->uow->commit();

        self::assertSame(['title', 'price'], $this->updatedColumns());
        self::assertSame('99.00', $this->storage->row('article', 1)['price']);
    }

    public function testPreUpdateReceivesTheChangeSet(): void
    {
        $this->seedArticle();
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);
        $article->title = 'Changed';

        $this->uow->commit();

        $events = $this->events->ofType(PreUpdateEvent::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(PreUpdateEvent::class, $events[0]);
        self::assertSame(['title' => ['Hello', 'Changed']], $events[0]->changeSet);
    }

    public function testChangeMadeInPrePersistIsInserted(): void
    {
        $this->events->listen(PrePersistEvent::class, static function (PrePersistEvent $event): void {
            if ($event->entity instanceof Article) {
                $event->entity->createdBy = 'listener';
            }
        });

        $this->flushedWithoutReset(self::article());

        self::assertSame('listener', $this->storage->row('article', 1)['created_by']);
    }

    // décision : Post* après le commit, jamais pendant la transaction
    public function testPostEventsAreDispatchedAfterTheCommit(): void
    {
        $inTransaction = [];
        foreach ([PostPersistEvent::class, PostUpdateEvent::class, PostRemoveEvent::class] as $event) {
            $this->events->listen($event, function () use (&$inTransaction): void {
                $inTransaction[] = $this->storage->inTransaction;
            });
        }

        $article = $this->flushedWithoutReset(self::article());
        $article->title = 'Changed';
        $this->uow->commit();
        $this->uow->remove($article);
        $this->uow->commit();

        self::assertSame([false, false, false], $inTransaction);
        self::assertCount(1, $this->events->ofType(PostPersistEvent::class));
        self::assertCount(1, $this->events->ofType(PostUpdateEvent::class));
        self::assertCount(1, $this->events->ofType(PostRemoveEvent::class));
    }

    // ---------------------------------------------------------------- verrouillage optimiste (RG-03.9)

    public function testVersionIsCheckedAndIncremented(): void
    {
        $document = new Document();
        $document->title = 'v1';
        $this->flushed($document);

        $document->title = 'v2';
        $this->uow->commit();

        self::assertSame(['title', 'version'], $this->updatedColumns());
        self::assertSame(2, $document->version);
        self::assertSame(2, $this->storage->row('document', 1)['version']);
    }

    public function testStaleVersionThrowsAndRollsBack(): void
    {
        $document = new Document();
        $document->title = 'v1';
        $this->flushed($document);
        // un autre processus a modifié la ligne
        $this->storage->setColumn('document', 1, 'version', 5);
        $other = self::article('Same flush');
        $this->uow->persist($other);

        $document->title = 'v2';
        try {
            $this->uow->commit();
            self::fail('Expected OptimisticLockException.');
        } catch (OptimisticLockException $e) {
            self::assertSame($document, $e->entity);
            self::assertSame(1, $e->expectedVersion);
            self::assertStringContainsString('version 1 is no longer current', $e->getMessage());
        }

        self::assertSame(1, $document->version, 'the version is not incremented by a failed flush');
        self::assertNull($other->id, 'the INSERT of the same flush is rolled back');
        self::assertSame('v1', $this->storage->row('document', 1)['title']);
    }

    public function testRemovalChecksTheVersionToo(): void
    {
        $document = $this->flushed(new Document());
        $this->storage->setColumn('document', 1, 'version', 9);
        $this->uow->remove($document);

        $this->expectException(OptimisticLockException::class);

        $this->uow->commit();
    }

    // ---------------------------------------------------------------- politique de suivi (RG-03.11)

    public function testImplicitPolicyUpdatesEveryManagedEntity(): void
    {
        $article = $this->flushed(self::article());
        $article->title = 'Changed';

        $this->uow->commit();

        self::assertSame(['title'], $this->updatedColumns());
    }

    public function testExplicitPolicyUpdatesOnlyPersistedEntities(): void
    {
        $note = new Note();
        $note->body = 'first';
        $this->flushed($note);

        $note->body = 'changed without persist';
        $this->uow->commit();
        self::assertSame([], $this->storage->operations('UPDATE'));

        $this->uow->persist($note);
        $this->uow->commit();
        self::assertSame(['body'], $this->updatedColumns());

        // persist() ne vaut que jusqu'au flush suivant
        $this->storage->log = [];
        $note->body = 'again';
        $this->uow->commit();
        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    // ---------------------------------------------------------------- ordre des opérations

    public function testOperationOrder(): void
    {
        $updated = $this->flushed(self::article('Updated'));
        $firstRemoved = $this->flushed(self::article('Removed first'));
        $secondRemoved = $this->flushed(self::article('Removed second'));

        $this->uow->remove($firstRemoved);
        $this->uow->remove($secondRemoved);
        $updated->title = 'Changed';
        $this->uow->persist(self::article('Inserted A'));
        $this->uow->persist(self::article('Inserted B'));
        $this->uow->commit();

        self::assertSame(['INSERT', 'INSERT', 'UPDATE', 'DELETE', 'DELETE'], array_column($this->storage->log, 0));
        self::assertSame(['inserted a', 'inserted b'], array_map(static fn (array $row): mixed => $row['slug'], \array_slice(array_values($this->storage->tables['article']), 1)));
        // DELETE en ordre inverse de remove()
        self::assertSame([$secondRemoved->id, $firstRemoved->id], $this->deletedIdsInOrder([$firstRemoved, $secondRemoved]));
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function flushedWithoutReset(object $entity): object
    {
        $this->uow->persist($entity);
        $this->uow->commit();

        return $entity;
    }

    /**
     * @param list<Article> $removed
     *
     * @return list<int|null>
     */
    private function deletedIdsInOrder(array $removed): array
    {
        // les DELETE sont journalisés sans id : l'ordre se lit dans les événements PostRemove
        $ids = [];
        foreach ($this->events->ofType(PostRemoveEvent::class) as $event) {
            if ($event instanceof PostRemoveEvent && $event->entity instanceof Article && \in_array($event->entity, $removed, true)) {
                $ids[] = $event->entity->id;
            }
        }

        return $ids;
    }
}
