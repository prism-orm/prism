<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Event\DefaultEntityListenerResolver;
use Prism\Orm\Event\OnClearEvent;
use Prism\Orm\Event\OnFlushEvent;
use Prism\Orm\Event\PostFlushEvent;
use Prism\Orm\Event\PostLoadEvent;
use Prism\Orm\Event\PostPersistEvent;
use Prism\Orm\Event\PostRemoveEvent;
use Prism\Orm\Event\PostUpdateEvent;
use Prism\Orm\Event\PreFlushEvent;
use Prism\Orm\Event\PrePersistEvent;
use Prism\Orm\Event\PreRemoveEvent;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\ReentrantFlushException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\Hydrator\Fixtures\Product;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Journal;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\JournalListener;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Ledger;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\LedgerListener;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence des événements (étape 7), écrits avant le code.
 *
 * Règles fixées ici :
 * - flush : PreFlush, OnFlush, puis dans la transaction PrePersist / PreUpdate, puis après le
 *   commit Post* et PostFlush ; PreFlush, OnFlush et PostFlush sont publiés même sans écriture ;
 * - tout flush() appelé pendant un flush, PostFlush compris, lève ReentrantFlushException ;
 * - pour une entité : callbacks, puis listeners d'entité, puis dispatcher PSR-14 ;
 * - PostLoad suit chaque hydratation, jamais une entité rendue par l'identity map.
 */
final class UnitOfWorkEventsTest extends TestCase
{
    private InMemoryStorage $storage;

    private TestDispatcher $events;

    private DefaultEntityListenerResolver $listeners;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        Journal::$log = [];
        JournalListener::$instances = 0;
        $this->storage = new InMemoryStorage();
        $this->events = new TestDispatcher();
        $this->listeners = new DefaultEntityListenerResolver();
        $this->uow = $this->unitOfWork($this->events);
    }

    private function unitOfWork(?TestDispatcher $events): UnitOfWork
    {
        return new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), $events, $this->listeners);
    }

    /**
     * @return list<string> événements reçus par le dispatcher, en nom court
     */
    private function dispatched(): array
    {
        return array_map(Journal::short(...), $this->events->dispatched);
    }

    private function journal(string $title = 'Day 1'): Journal
    {
        $journal = new Journal();
        $journal->title = $title;

        return $journal;
    }

    private function seedJournal(int $id = 1): void
    {
        $this->storage->seed('journal', 'id', ['id' => $id, 'title' => 'Day ' . $id]);
    }

    // ---------------------------------------------------------------- flush

    public function testFlushEventsOrder(): void
    {
        $this->uow->persist(new Document());
        $this->uow->commit();

        self::assertSame(['PreFlush', 'OnFlush', 'PrePersist', 'PostPersist', 'PostFlush'], $this->dispatched());
    }

    public function testFlushEventsArePublishedEvenWithNothingToWrite(): void
    {
        $this->uow->commit();

        self::assertSame(['PreFlush', 'OnFlush', 'PostFlush'], $this->dispatched());
        self::assertSame(0, $this->storage->transactions, 'still no transaction');
    }

    public function testEntityPersistedInPreFlushIsInserted(): void
    {
        $this->events->listen(PreFlushEvent::class, static function (PreFlushEvent $event): void {
            $event->unitOfWork->persist(new Document());
        });

        $this->uow->commit();

        self::assertCount(1, $this->storage->operations('INSERT'));
    }

    public function testOnFlushSeesScheduledEntitiesAndItsChangesAreIncluded(): void
    {
        $this->storage->seed('document', 'id', ['id' => 1, 'title' => 'Changed', 'version' => 1]);
        $this->storage->seed('document', 'id', ['id' => 2, 'title' => 'Removed', 'version' => 1]);
        $this->storage->seed('document', 'id', ['id' => 3, 'title' => 'Touched in OnFlush', 'version' => 1]);
        [$changed, $removed, $touched] = [$this->uow->find(Document::class, 1), $this->uow->find(Document::class, 2), $this->uow->find(Document::class, 3)];
        self::assertNotNull($changed);
        self::assertNotNull($removed);
        self::assertNotNull($touched);
        $this->uow->persist($inserted = new Document());
        $changed->title = 'Changed!';
        $this->uow->remove($removed);

        $seen = [];
        $this->events->listen(OnFlushEvent::class, static function (OnFlushEvent $event) use (&$seen, $touched): void {
            $seen = [$event->unitOfWork->getScheduledInsertions(), $event->unitOfWork->getScheduledUpdates(), $event->unitOfWork->getScheduledDeletions()];
            $touched->title = 'Changed in OnFlush';
        });
        $this->uow->commit();

        self::assertSame([[$inserted], [$changed], [$removed]], $seen);
        self::assertSame('Changed in OnFlush', $this->storage->row('document', 3)['title']);
    }

    public function testNoPostFlushAfterAFailure(): void
    {
        $this->uow->persist(new Document());
        $this->storage->failOnInsert(1);

        try {
            $this->uow->commit();
            self::fail('Expected the simulated failure.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['PreFlush', 'OnFlush', 'PrePersist'], $this->dispatched());
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function flushEvents(): iterable
    {
        yield 'PreFlush' => [PreFlushEvent::class];
        yield 'OnFlush' => [OnFlushEvent::class];
        yield 'PostPersist' => [PostPersistEvent::class];
        yield 'PostFlush' => [PostFlushEvent::class];
    }

    /**
     * @param class-string $event
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('flushEvents')]
    public function testFlushFromAnyFlushEventIsRefused(string $event): void
    {
        $this->events->listen($event, function (): void {
            $this->uow->commit();
        });
        $this->uow->persist(new Document());

        $this->expectException(ReentrantFlushException::class);

        $this->uow->commit();
    }

    public function testFlushWorksAgainAfterARefusedReentrantFlush(): void
    {
        $reenter = true;
        $this->events->listen(PostFlushEvent::class, function () use (&$reenter): void {
            if ($reenter) {
                $reenter = false;
                $this->uow->commit();
            }
        });
        try {
            $this->uow->commit();
            self::fail('Expected ReentrantFlushException.');
        } catch (ReentrantFlushException) {
        }

        $this->uow->persist(new Document());
        $this->uow->commit();
        self::assertCount(1, $this->storage->operations('INSERT'), 'the flushing flag was reset');
    }

    // ---------------------------------------------------------------- PostLoad, OnClear

    public function testPostLoadFollowsEveryHydration(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->seedJournal($id);
        }

        $this->uow->find(Journal::class, 1);
        $this->uow->find(Journal::class, 1);
        $this->uow->findBy(Journal::class, ['id' => [1, 2]]);
        $reference = $this->uow->getReference(Journal::class, 3);
        self::assertSame('Day 3', $reference->title);
        $journal = $this->uow->find(Journal::class, 1);
        self::assertNotNull($journal);
        $this->uow->refresh($journal);
        iterator_to_array($this->uow->iterate(Journal::class, ['id' => 4]));

        // 1 (find), 2 (findBy, 1 vient de la map), 3 (référence), 1 (refresh), 4 (iterate)
        self::assertCount(5, $this->events->ofType(PostLoadEvent::class));
        self::assertSame([1, 2, 3, 1, 4], array_map(static fn (object $e): ?int => $e instanceof PostLoadEvent && $e->entity instanceof Journal ? $e->entity->id : null, $this->events->ofType(PostLoadEvent::class)));
    }

    // RG-03.12 : #[PostLoad] initialise l'état dérivé
    public function testPostLoadCallbackSeesTheHydratedValues(): void
    {
        $this->storage->seed('product', 'id', [
            'id' => 7, 'name' => 'Desk', 'price' => '10.00', 'status' => 'draft', 'released_at' => null, 'thumbnail' => null,
            'stock_street' => null, 'stock_zip' => null, 'stock_city' => null, 'sku' => 'DSK-1',
        ]);
        Product::$constructed = 0;

        $product = $this->uow->find(Product::class, 7);
        self::assertNotNull($product);
        self::assertSame(['Desk (DSK-1)', 1, 0], [$product->label, $product->postLoads, Product::$constructed]);

        $this->storage->setColumn('product', 7, 'name', 'Desk XL');
        $this->uow->refresh($product);
        self::assertSame(['Desk XL (DSK-1)', 2], [$product->label, $product->postLoads], 'refresh() runs #[PostLoad] again');
    }

    public function testClearPublishesOnClear(): void
    {
        $this->uow->clear();

        self::assertSame(['OnClear'], $this->dispatched());
        self::assertInstanceOf(OnClearEvent::class, $this->events->dispatched[0]);
    }

    // ---------------------------------------------------------------- callbacks et listeners

    public function testCallbacksThenEntityListenersThenDispatcher(): void
    {
        $this->events->listen(PrePersistEvent::class, static function (): void {
            Journal::$log[] = 'dispatcher:PrePersist';
        });

        $this->uow->persist($this->journal());
        $this->uow->commit();

        self::assertSame(['callback:PrePersist', 'callback:touch', 'listener:PrePersist', 'dispatcher:PrePersist', 'callback:PostPersist', 'listener:PostPersist'], Journal::$log);
    }

    public function testEveryLifecycleEventReachesCallbacksAndListeners(): void
    {
        $journal = $this->journal();
        $this->uow->persist($journal);
        $this->uow->commit();
        $journal->title = 'Day 2';
        $this->uow->commit();
        $this->uow->remove($journal);
        $this->uow->commit();
        $this->seedJournal(9);
        $this->uow->find(Journal::class, 9);

        self::assertSame([
            'callback:PrePersist', 'callback:touch', 'listener:PrePersist', 'callback:PostPersist', 'listener:PostPersist',
            'callback:PreUpdate(title)', 'listener:PreUpdate', 'callback:PostUpdate', 'listener:PostUpdate',
            'callback:PreRemove', 'listener:PreRemove', 'callback:PostRemove', 'listener:PostRemove',
            'callback:PostLoad', 'listener:PostLoad',
        ], Journal::$log);
    }

    public function testCallbacksAndListenersRunWithoutDispatcher(): void
    {
        $uow = $this->unitOfWork(null);

        $uow->persist($this->journal());
        $uow->commit();

        self::assertSame(['callback:PrePersist', 'callback:touch', 'listener:PrePersist', 'callback:PostPersist', 'listener:PostPersist'], Journal::$log);
    }

    // RG-03.8 depuis un callback
    public function testChangeMadeInAPreUpdateCallbackIsIncluded(): void
    {
        $this->seedJournal();
        $journal = $this->uow->find(Journal::class, 1);
        self::assertNotNull($journal);
        $this->events->listen(PreUpdateEvent::class, static function (PreUpdateEvent $event): void {
            if ($event->entity instanceof Journal) {
                $event->entity->title .= ' (edited)';
            }
        });

        $journal->title = 'Day one';
        $this->uow->commit();

        self::assertSame('Day one (edited)', $this->storage->row('journal', 1)['title']);
    }

    public function testListenerIsResolvedOncePerClass(): void
    {
        $this->uow->persist($this->journal('A'));
        $this->uow->persist($this->journal('B'));
        $this->uow->commit();

        self::assertSame(1, JournalListener::$instances);
    }

    public function testRegisteredListenerInstanceIsUsed(): void
    {
        $this->listeners->register($listener = new LedgerListener('audit'));

        $this->uow->persist(new Ledger());
        $this->uow->commit();

        self::assertSame(['audit'], $listener->seen);
    }

    public function testListenerWithConstructorArgumentsNeedsARegisteredInstance(): void
    {
        $this->uow->persist(new Ledger());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(LedgerListener::class . ': the entity listener has required constructor arguments; register an instance with DefaultEntityListenerResolver::register(), or use an EntityListenerResolver that builds it.');

        $this->uow->commit();
    }

    public function testPreRemoveIsPublishedByRemove(): void
    {
        $this->seedJournal();
        $journal = $this->uow->find(Journal::class, 1);
        self::assertNotNull($journal);
        $this->events->dispatched = [];

        $this->uow->remove($journal);

        self::assertSame(['PreRemove'], $this->dispatched());
        self::assertCount(1, $this->events->ofType(PreRemoveEvent::class));
        $this->uow->commit();
        self::assertCount(1, $this->events->ofType(PostRemoveEvent::class));
        self::assertSame([], $this->events->ofType(PostUpdateEvent::class));
    }
}
