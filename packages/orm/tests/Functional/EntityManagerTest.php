<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\LockMode;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Exception\OptimisticLockException;
use Prism\Orm\Exception\TransactionRequiredException;
use Prism\Orm\Tests\Functional\PersisterFixtures\Label;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Journal;

/**
 * CRUD complet par l'EntityManager, sur les 4 bases.
 */
final class EntityManagerTest extends MappingSchemaTestCase
{
    private EntityManager $em;

    protected static function entities(): array
    {
        return [Document::class, Label::class, Journal::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = new EntityManager($this->connection);
    }

    private static function document(string $title): Document
    {
        $document = new Document();
        $document->title = $title;

        return $document;
    }

    private function rowsIn(string $table): int
    {
        $count = $this->connection->createQueryBuilder()->select('COUNT(*)')->from($table)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    public function testCreateReadUpdateDelete(): void
    {
        $document = self::document('Draft');
        $this->em->persist($document);
        $this->em->flush();
        self::assertIsInt($id = $document->id);

        $this->em->clear();
        $read = $this->em->find(Document::class, $id);
        self::assertNotNull($read);
        self::assertNotSame($document, $read);
        self::assertSame(['Draft', 1], [$read->title, $read->version]);

        $read->title = 'Final';
        $this->em->flush();
        $this->em->clear();
        $updated = $this->em->find(Document::class, $id);
        self::assertNotNull($updated);
        self::assertSame(['Final', 2], [$updated->title, $updated->version]);

        $this->em->remove($updated);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(Document::class, $id));
        self::assertSame(0, $this->rowsIn('document'));
    }

    // RG-03.3
    public function testFindReturnsTheSameInstanceUntilClear(): void
    {
        $this->em->persist($document = self::document('Same'));
        $this->em->flush();
        self::assertIsInt($id = $document->id);

        self::assertSame($document, $this->em->find(Document::class, $id));
        self::assertTrue($this->em->contains($document));

        $this->em->clear();
        self::assertFalse($this->em->contains($document));
        self::assertNotSame($document, $this->em->find(Document::class, $id));
    }

    public function testReferenceLoadsOnFirstAccess(): void
    {
        $this->em->persist($document = self::document('Referenced'));
        $this->em->flush();
        self::assertIsInt($id = $document->id);
        $this->em->clear();

        $reference = $this->em->getReference(Document::class, $id);
        self::assertSame($id, $reference->id);
        self::assertSame('Referenced', $reference->title);

        $missing = $this->em->getReference(Document::class, $id + 1000);
        $this->expectException(EntityNotFoundException::class);
        self::assertSame('', $missing->title);
    }

    public function testRefreshReadsAChangeMadeElsewhere(): void
    {
        $this->em->persist($document = self::document('Before'));
        $this->em->flush();
        $qb = $this->connection->createQueryBuilder();
        $qb->update('document')->set('title', 'After')->where($qb->expr()->eq('id', $document->id))->executeStatement();

        $this->em->refresh($document);

        self::assertSame('After', $document->title);
    }

    // RG-03.9 sur base réelle
    public function testConcurrentUpdateIsDetectedByTheVersion(): void
    {
        $this->em->persist($document = self::document('Mine'));
        $this->em->flush();
        $qb = $this->connection->createQueryBuilder();
        $qb->update('document')->set('version', 5)->where($qb->expr()->eq('id', $document->id))->executeStatement();

        $document->title = 'Lost update';
        try {
            $this->em->flush();
            self::fail('Expected OptimisticLockException.');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(OptimisticLockException::class, $e);
        }
        self::assertSame(1, $document->version, 'the version is restored by the rollback');
    }

    public function testWrapInTransactionFlushesAndReturnsTheResult(): void
    {
        $id = $this->em->wrapInTransaction(static function (EntityManagerInterface $em): ?int {
            $em->persist($label = new Label('red'));
            $em->flush();

            return $label->id;
        });

        self::assertIsInt($id);
        self::assertSame(1, $this->rowsIn('label'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testWrapInTransactionFlushesAtTheEnd(): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $em->persist(new Label('green'));
        });

        self::assertSame(1, $this->rowsIn('label'));
    }

    public function testFailedTransactionRollsBackAndClears(): void
    {
        $label = new Label('blue');
        try {
            $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($label): void {
                $em->persist($label);
                $em->flush();
                throw new \DomainException('Business rule failed after the flush.');
            });
        } catch (\DomainException) {
            // relancée par wrapInTransaction() après le rollback
        }

        self::assertSame(0, $this->rowsIn('label'));
        self::assertFalse($this->em->contains($label), 'the flushed entity no longer matches the database');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testPessimisticLockInsideATransaction(): void
    {
        $this->em->persist($document = self::document('Locked'));
        $this->em->flush();

        $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($document): void {
            // SQLite : sans verrou de ligne (décision C), la requête est exécutée quand même
            $em->lock($document, LockMode::PessimisticWrite);
            $em->lock($document, LockMode::PessimisticRead);
            $document->title = 'Locked and changed';
        });
        $this->em->clear();

        self::assertSame('Locked and changed', $this->em->find(Document::class, $document->id)?->title);
        $this->expectException(TransactionRequiredException::class);
        $this->em->lock($this->em->find(Document::class, $document->id) ?? $document, LockMode::PessimisticWrite);
    }

    public function testOptimisticLock(): void
    {
        $this->em->persist($document = self::document('Versioned'));
        $this->em->flush();

        $this->em->lock($document, LockMode::Optimistic, 1);
        $this->expectException(OptimisticLockException::class);
        $this->em->lock($document, LockMode::Optimistic, 2);
    }

    // étape 7 : dispatcher, callbacks et listener d'entité branchés par l'EntityManager
    public function testEventsCallbacksAndEntityListenersOnARealDatabase(): void
    {
        Journal::$log = [];
        $events = new TestDispatcher();
        $em = new EntityManager($this->connection, events: $events);
        $journal = new Journal();
        $journal->title = 'Real';

        $em->persist($journal);
        $em->flush();
        $em->clear();
        self::assertSame('Real', $em->find(Journal::class, $journal->id)?->title);

        self::assertSame(['callback:PrePersist', 'callback:touch', 'listener:PrePersist', 'callback:PostPersist', 'listener:PostPersist', 'callback:PostLoad', 'listener:PostLoad'], Journal::$log);
        self::assertSame(['PreFlush', 'OnFlush', 'PrePersist', 'PostPersist', 'PostFlush', 'OnClear', 'PostLoad'], array_map(Journal::short(...), $events->dispatched));
    }
}
