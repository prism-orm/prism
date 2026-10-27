<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\FrozenClock;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Link;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Memo;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Page;
use Prism\Orm\UnitOfWork\EntityState;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * #[SoftDeletable] dans l'UnitOfWork (RG-08.14, ADR 0015) : remove() devient l'UPDATE du champ de
 * suppression, hardDelete le DELETE, restore() le retour ; les cascades s'appliquent.
 */
final class UnitOfWorkSoftDeleteTest extends TestCase
{
    private InMemoryStorage $storage;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), clock: new FrozenClock(new \DateTimeImmutable('2026-10-26 12:00:00+00:00')));
    }

    private function page(string $slug = 'home'): Page
    {
        $page = new Page($slug);
        $this->uow->persist($page);
        $this->uow->commit();

        return $page;
    }

    public function testRemoveWritesTheDeletionDate(): void
    {
        $page = $this->page();

        $this->uow->remove($page);
        self::assertSame(EntityState::Managed, $this->uow->getEntityState($page), 'still managed');
        self::assertSame('2026-10-26 12:00:00', $page->deletedAt?->format('Y-m-d H:i:s'));
        $this->uow->commit();

        self::assertSame([], $this->storage->operations('DELETE'));
        self::assertNotNull($this->storage->row('page', (int) $page->id)['deleted_at']);
    }

    // RG-08.14 : sur une entité déjà supprimée, sans effet
    public function testRemovingADeletedEntityAgainDoesNothing(): void
    {
        $page = $this->page();
        $this->uow->remove($page);
        $this->uow->commit();
        $deletedAt = $page->deletedAt;
        $updates = \count($this->storage->operations('UPDATE'));

        $this->uow->remove($page);
        $this->uow->commit();

        self::assertSame($deletedAt, $page->deletedAt);
        self::assertCount($updates, $this->storage->operations('UPDATE'));
    }

    // RG-08.14 : hardDelete supprime la ligne, déjà supprimée logiquement ou non
    public function testHardDelete(): void
    {
        $page = $this->page();
        $this->uow->remove($page);
        $this->uow->commit();

        $this->uow->remove($page, hardDelete: true);
        $this->uow->commit();

        self::assertCount(1, $this->storage->operations('DELETE'));
        self::assertSame(EntityState::New, $this->uow->getEntityState($page), 'forgotten, as after any DELETE (RG-03.2)');
    }

    public function testRestore(): void
    {
        $page = $this->page();
        $this->uow->remove($page);
        $this->uow->commit();

        $this->uow->restore($page);
        $this->uow->commit();

        self::assertNull($page->deletedAt);
        self::assertNull($this->storage->row('page', (int) $page->id)['deleted_at']);
    }

    // restaurer avant le flush annule la suppression : rien n'est écrit
    public function testRestoreBeforeTheFlushCancelsTheRemoval(): void
    {
        $page = $this->page();
        $updates = \count($this->storage->operations('UPDATE'));

        $this->uow->remove($page);
        $this->uow->restore($page);
        $this->uow->commit();

        self::assertCount($updates, $this->storage->operations('UPDATE'));
    }

    // les cascades d'une suppression s'appliquent : l'enfant soft-deletable l'est aussi
    public function testCascadeRemove(): void
    {
        $page = new Page('home');
        $page->links->add($link = new Link($page, 'https://example.com'));
        $this->uow->persist($page);
        $this->uow->persist($link);
        $this->uow->commit();

        $this->uow->remove($page);
        $this->uow->commit();

        self::assertNotNull($link->deletedAt);
        self::assertSame([], $this->storage->operations('DELETE'));
    }

    public function testRestoreNeedsASoftDeletableManagedEntity(): void
    {
        try {
            $this->uow->restore(new Memo());
            self::fail('no exception');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Cannot restore a ' . Memo::class . ' entity: it is not #[SoftDeletable].', $e->getMessage());
        }

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('Cannot restore a NEW entity of class ' . Page::class . ': only a managed entity can be restored; load it with withDeleted().');
        $this->uow->restore(new Page());
    }
}
