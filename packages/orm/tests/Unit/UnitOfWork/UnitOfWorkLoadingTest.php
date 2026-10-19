<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\LockMode;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Exception\OptimisticLockException;
use Prism\Orm\Exception\TransactionRequiredException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Article;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;
use Prism\Orm\UnitOfWork\EntityState;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence du chargement (étape 6), écrits avant le code : findBy, iterate,
 * refresh, getReference et lock.
 *
 * Règles fixées ici :
 * - une ligne relue ne réécrit jamais une entité déjà gérée (ses modifications non flushées
 *   sont gardées) ; seul refresh() la réécrit ;
 * - iterate() ne détache que les entités qu'il a lui-même chargées, à la fin de chaque lot ;
 * - un ghost non chargé n'a pas de snapshot : flush ne le compare pas, donc ne le charge pas ;
 * - find() rend une entité chargée : un ghost de la map est chargé, null si sa ligne n'existe pas.
 */
final class UnitOfWorkLoadingTest extends TestCase
{
    private InMemoryStorage $storage;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), new TestDispatcher());
    }

    private function seedDocuments(int $count, int $version = 1): void
    {
        for ($id = 1; $id <= $count; ++$id) {
            $this->storage->seed('document', 'id', ['id' => $id, 'title' => 'Doc ' . $id, 'version' => $version]);
        }
    }

    private function selects(): int
    {
        return \count($this->storage->operations('SELECT'));
    }

    // findBy, count

    public function testFindByHydratesManagedEntitiesInTheIdentityMap(): void
    {
        $this->seedDocuments(3);

        $documents = $this->uow->findBy(Document::class, [], ['id' => Order::Desc], limit: 2);

        self::assertSame([3, 2], array_map(static fn (Document $d): ?int => $d->id, $documents));
        self::assertSame(EntityState::Managed, $this->uow->getEntityState($documents[0]));
        self::assertSame($documents[1], $this->uow->find(Document::class, 2), 'RG-03.3');
    }

    public function testFindByKeepsUnflushedChangesOfAnAlreadyManagedEntity(): void
    {
        $this->seedDocuments(2);
        $first = $this->uow->find(Document::class, 1);
        self::assertNotNull($first);
        $first->title = 'Changed in memory';

        $documents = $this->uow->findBy(Document::class, ['title' => ['Doc 1', 'Doc 2']]);

        self::assertSame($first, $documents[0]);
        self::assertSame('Changed in memory', $first->title, 'the row read again never overwrites a managed entity');
        $this->uow->commit();
        self::assertSame('Changed in memory', $this->storage->row('document', 1)['title']);
    }

    public function testFindByCriteriaOffsetAndCount(): void
    {
        $this->seedDocuments(5);

        $documents = $this->uow->findBy(Document::class, ['version' => 1], ['id' => Order::Asc], limit: 2, offset: 3);

        self::assertSame([4, 5], array_map(static fn (Document $d): ?int => $d->id, $documents));
        self::assertSame([], $this->uow->findBy(Document::class, ['title' => 'none']));
        self::assertSame(5, $this->uow->count(Document::class));
        self::assertSame(1, $this->uow->count(Document::class, ['title' => 'Doc 3']));
    }

    // iterate (RG-03.10)

    public function testIterateReadsByKeysetPagesAndDetachesEachBatch(): void
    {
        $this->seedDocuments(250);
        $seen = [];
        $first = null;

        foreach ($this->uow->iterate(Document::class, batchSize: 100) as $i => $document) {
            $seen[] = $document->id;
            $first ??= $document;
            if (99 === $i) {
                self::assertSame(EntityState::Managed, $this->uow->getEntityState($first), 'the batch is still managed while it is read');
            }
            if (100 === $i) {
                self::assertSame(EntityState::Detached, $this->uow->getEntityState($first), 'the previous batch was detached');
            }
        }

        self::assertSame(range(1, 250), $seen);
        self::assertSame(3, $this->selects(), 'one query per batch: 100, 100, 50');
        self::assertSame(EntityState::Detached, $this->uow->getEntityState($first), 'the last batch is detached too');
    }

    public function testIterateStopsWhenTheLastPageIsFull(): void
    {
        $this->seedDocuments(4);

        self::assertCount(4, iterator_to_array($this->uow->iterate(Document::class, batchSize: 2)));
        // 2, 2 puis une page vide
        self::assertSame(3, $this->selects());
    }

    public function testIterateLeavesAlreadyManagedAndRemovedEntitiesAlone(): void
    {
        $this->seedDocuments(3);
        $managed = $this->uow->find(Document::class, 2);
        self::assertNotNull($managed);

        $removed = null;
        foreach ($this->uow->iterate(Document::class, ['version' => 1], batchSize: 10) as $document) {
            if (3 === $document->id) {
                $this->uow->remove($removed = $document);
            }
        }

        self::assertSame(EntityState::Managed, $this->uow->getEntityState($managed), 'managed before iterate(): not detached');
        self::assertNotNull($removed);
        self::assertSame(EntityState::Removed, $this->uow->getEntityState($removed), 'its removal is kept for the next flush');
        $this->uow->commit();
        self::assertArrayNotHasKey('3', $this->storage->tables['document']);
    }

    public function testIterateDefaultsToBatchesOfAHundred(): void
    {
        $this->seedDocuments(101);

        self::assertCount(101, iterator_to_array($this->uow->iterate(Document::class)));
        self::assertSame(2, $this->selects());
    }

    public function testIterateAcceptsBatchesOfOne(): void
    {
        $this->seedDocuments(2);

        self::assertCount(2, iterator_to_array($this->uow->iterate(Document::class, batchSize: 1)));
        self::assertSame(3, $this->selects());
    }

    public function testIterateRefusesAnEmptyBatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The batch size must be at least 1, got 0.');

        iterator_to_array($this->uow->iterate(Document::class, batchSize: 0));
    }

    // refresh

    public function testRefreshOverwritesUnflushedChangesWithTheDatabase(): void
    {
        $this->seedDocuments(1);
        $document = $this->uow->find(Document::class, 1);
        self::assertNotNull($document);
        $document->title = 'Unflushed';
        $this->storage->setColumn('document', 1, 'version', 2);

        $this->uow->refresh($document);

        self::assertSame(['Doc 1', 2], [$document->title, $document->version]);
        $this->uow->commit();
        self::assertSame([], $this->storage->operations('UPDATE'), 'the snapshot follows the refreshed values');
    }

    public function testRefreshNeverRewritesAReadonlyProperty(): void
    {
        $this->storage->seed('article', 'id', [
            'id' => 1, 'title' => 'Hello', 'price' => '12.50', 'published_at' => null, 'status' => 'draft',
            'place_street' => null, 'place_zip' => null, 'place_city' => null, 'created_by' => 'alice', 'computed' => null, 'slug' => 'hello',
        ]);
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);
        $this->storage->setColumn('article', 1, 'slug', 'changed-elsewhere');
        $this->storage->setColumn('article', 1, 'title', 'Changed elsewhere');

        $this->uow->refresh($article);

        self::assertSame(['Changed elsewhere', 'hello'], [$article->title, $article->slug]);
        $this->uow->commit();
        self::assertSame([], $this->storage->operations('UPDATE'), 'RG-02.6: a readonly property is never written');
    }

    public function testRefreshOfADeletedRowFails(): void
    {
        $this->seedDocuments(1);
        $document = $this->uow->find(Document::class, 1);
        self::assertNotNull($document);
        $this->storage->deleteRow('document', 1);

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage('No ' . Document::class . ' entity found for id 1.');

        $this->uow->refresh($document);
    }

    public function testRefreshNeedsAnEntityAlreadyWritten(): void
    {
        $document = new Document();
        $this->uow->persist($document);

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('Cannot refresh a MANAGED entity of class ' . Document::class . ': it has not been written yet; flush it first.');

        $this->uow->refresh($document);
    }

    public function testRefreshOfANewEntityFails(): void
    {
        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('Cannot refresh a NEW entity of class ' . Document::class . ': only managed entities can be refreshed.');

        $this->uow->refresh(new Document());
    }

    public function testRefreshOfAReferenceLoadsIt(): void
    {
        $this->seedDocuments(1);
        $reference = $this->uow->getReference(Document::class, 1);

        $this->uow->refresh($reference);

        self::assertSame(1, $this->selects());
        self::assertSame('Doc 1', $reference->title);
        self::assertSame(1, $this->selects());
    }

    // getReference (décision B, RG-04.7, RG-04.8)

    public function testReferenceExposesItsIdWithoutLoading(): void
    {
        $this->seedDocuments(1);

        $reference = $this->uow->getReference(Document::class, 1);

        self::assertSame(1, $reference->id);
        self::assertSame(0, $this->selects());
        self::assertSame(EntityState::Managed, $this->uow->getEntityState($reference));
        self::assertSame($reference, $this->uow->getReference(Document::class, 1));

        self::assertSame('Doc 1', $reference->title);
        self::assertSame(1, $this->selects(), 'loaded once, on the first access to another property');
        self::assertSame(1, $reference->version);
        self::assertSame(1, $this->selects());
    }

    public function testReferenceOfAManagedEntityIsThatEntity(): void
    {
        $this->seedDocuments(1);
        $document = $this->uow->find(Document::class, 1);

        self::assertSame($document, $this->uow->getReference(Document::class, 1));
    }

    public function testFindLoadsAReferenceAndReturnsTheSameInstance(): void
    {
        $this->seedDocuments(1);
        $reference = $this->uow->getReference(Document::class, 1);

        self::assertSame($reference, $this->uow->find(Document::class, 1));
        self::assertSame(1, $this->selects(), 'find() returns a loaded entity');
    }

    public function testReferenceToAMissingRowFailsOnEveryAccess(): void
    {
        $reference = $this->uow->getReference(Document::class, 404);

        foreach (['first access', 'second access'] as $attempt) {
            try {
                self::assertSame('', $reference->title);
                self::fail('Expected EntityNotFoundException on the ' . $attempt . '.');
            } catch (\RuntimeException $e) {
                self::assertInstanceOf(EntityNotFoundException::class, $e);
                self::assertSame('No ' . Document::class . ' entity found for id 404.', $e->getMessage());
            }
        }
        self::assertSame(404, $reference->id);
    }

    public function testFindOfAMissingReferenceReturnsNullAndForgetsIt(): void
    {
        $reference = $this->uow->getReference(Document::class, 404);

        self::assertNull($this->uow->find(Document::class, 404));
        self::assertSame(EntityState::New, $this->uow->getEntityState($reference));
    }

    public function testFlushNeverLoadsAnUnusedReference(): void
    {
        $this->seedDocuments(1);
        $this->uow->getReference(Document::class, 1);
        $this->uow->persist(new Document());

        $this->uow->commit();

        self::assertSame(0, $this->selects());
        self::assertSame([], $this->storage->operations('UPDATE'));
    }

    public function testLoadedReferenceIsTrackedForChanges(): void
    {
        $this->seedDocuments(1);
        $reference = $this->uow->getReference(Document::class, 1);

        $reference->title = 'Renamed';
        $this->uow->commit();

        self::assertSame([['document', ['title', 'version']]], $this->storage->operations('UPDATE'));
        self::assertSame(2, $reference->version, 'RG-03.9: the version read on load is the expected one');
    }

    public function testRemoveOfAReferenceDeletesItsRow(): void
    {
        $this->seedDocuments(1);
        $reference = $this->uow->getReference(Document::class, 1);

        $this->uow->remove($reference);
        $this->uow->commit();

        self::assertSame([], $this->storage->tables['document']);
        self::assertSame(EntityState::New, $this->uow->getEntityState($reference));
    }

    public function testDetachedReferenceIsForgottenByTheIdentityMap(): void
    {
        $this->seedDocuments(1);
        $reference = $this->uow->getReference(Document::class, 1);

        $this->uow->detach($reference);

        self::assertSame(EntityState::Detached, $this->uow->getEntityState($reference));
        self::assertNotSame($reference, $this->uow->find(Document::class, 1));
        self::assertSame('Doc 1', $reference->title, 'a detached reference can still be loaded');
        self::assertSame(EntityState::Detached, $this->uow->getEntityState($reference));
    }

    // lock

    public function testOptimisticLockChecksTheVersionReadFromTheDatabase(): void
    {
        $this->seedDocuments(1, version: 3);
        $document = $this->uow->find(Document::class, 1);
        self::assertNotNull($document);

        $this->uow->lock($document, LockMode::Optimistic);
        $this->uow->lock($document, LockMode::Optimistic, 3);

        try {
            $this->uow->lock($document, LockMode::Optimistic, 2);
            self::fail('Expected OptimisticLockException.');
        } catch (OptimisticLockException $e) {
            self::assertSame($document, $e->entity);
            self::assertSame(2, $e->expectedVersion);
        }
        self::assertSame(0, $this->storage->transactions, 'an optimistic lock never reaches the database');
    }

    public function testOptimisticLockOfAReferenceLoadsItsVersion(): void
    {
        $this->seedDocuments(1, version: 3);
        $reference = $this->uow->getReference(Document::class, 1);

        $this->expectException(OptimisticLockException::class);

        $this->uow->lock($reference, LockMode::Optimistic, 2);
    }

    public function testLockOfANewEntityFails(): void
    {
        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('Cannot lock a NEW entity of class ' . Document::class . ': only managed entities can be locked.');

        $this->uow->lock(new Document(), LockMode::PessimisticWrite);
    }

    public function testOptimisticLockNeedsAVersion(): void
    {
        $this->storage->seed('article', 'id', [
            'id' => 1, 'title' => 'Hello', 'price' => '12.50', 'published_at' => null, 'status' => 'draft',
            'place_street' => null, 'place_zip' => null, 'place_city' => null, 'created_by' => 'alice', 'computed' => null, 'slug' => 'hello',
        ]);
        $article = $this->uow->find(Article::class, 1);
        self::assertNotNull($article);

        $this->expectException(OptimisticLockException::class);
        $this->expectExceptionMessage('Cannot lock the ' . Article::class . ' entity optimistically: it has no #[Version] field.');

        $this->uow->lock($article, LockMode::Optimistic);
    }

    public function testPessimisticLockNeedsATransaction(): void
    {
        $this->seedDocuments(1);
        $document = $this->uow->find(Document::class, 1);
        self::assertNotNull($document);

        $this->expectException(TransactionRequiredException::class);
        $this->expectExceptionMessage('Locking the ' . Document::class . ' entity with PessimisticWrite requires an active transaction');

        $this->uow->lock($document, LockMode::PessimisticWrite);
    }

    public function testPessimisticLockLocksTheRowInsideATransaction(): void
    {
        $this->seedDocuments(1);
        $reference = $this->uow->getReference(Document::class, 1);

        $this->storage->transactional(fn () => $this->uow->lock($reference, LockMode::PessimisticRead));

        self::assertSame([['document', ['PessimisticRead']]], $this->storage->operations('LOCK'));
        self::assertSame(0, $this->selects(), 'a reference is locked before it is read, so that it is read up to date');
    }

    public function testPessimisticLockOfADeletedRowFails(): void
    {
        $this->seedDocuments(1);
        $document = $this->uow->find(Document::class, 1);
        self::assertNotNull($document);
        $this->storage->deleteRow('document', 1);

        $this->expectException(EntityNotFoundException::class);

        $this->storage->transactional(fn () => $this->uow->lock($document, LockMode::PessimisticWrite));
    }

    public function testOnlyWrittenEntitiesCanBeLocked(): void
    {
        $document = new Document();
        $this->uow->persist($document);

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('Cannot lock a MANAGED entity of class ' . Document::class . ': it has not been written yet; flush it first.');

        $this->uow->lock($document, LockMode::Optimistic);
    }
}
