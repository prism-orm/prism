<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\PersistentCollection;
use Prism\Orm\Exception\UnpersistedRelationException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Invoice;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\InvoiceLine;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\StockItem;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Tag;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Warehouse;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence des collections (phase 4, étape 3, ADR 0007), écrits avant le code.
 *
 * Règles fixées ici :
 * - une collection relue est une PersistentCollection chargée au premier accès, jamais par le
 *   flush ; orderBy et indexBy s'appliquent au chargement ; EXTRA_LAZY compte sans charger ;
 * - seule la différence d'une ManyToMany propriétaire est écrite : lignes pivot ajoutées puis
 *   supprimées, après les entités ; le côté inverse et les OneToMany n'écrivent rien (RG-04.1) ;
 * - clear() sur une collection non chargée supprime toutes ses lignes pivot sans la charger ;
 * - supprimer une entité supprime d'abord ses lignes pivot, des deux côtés déclarés ;
 * - un élément NEW suit RG-04.2 (cascade: persist ou UnpersistedRelationException).
 */
final class UnitOfWorkCollectionsTest extends TestCase
{
    private InMemoryStorage $storage;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults());
    }

    /**
     * @return list<string> écritures « TYPE table » dans l'ordre
     */
    private function writes(): array
    {
        return array_values(array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], array_filter($this->storage->log, static fn (array $o): bool => \in_array($o[0], ['INSERT', 'UPDATE', 'DELETE'], true))));
    }

    private function selectsOn(string $table): int
    {
        return \count(array_filter($this->storage->operations('SELECT'), static fn (array $o): bool => $o[0] === $table));
    }

    /**
     * @param list<int> $tags
     */
    private function seedInvoice(int $id, array $tags = []): void
    {
        $this->storage->seed('invoice', 'id', ['id' => $id, 'number' => 'INV-' . $id]);
        foreach ($tags as $tag) {
            $this->storage->seedPivot('invoice_tag', ['invoice_id' => $id, 'tag_id' => $tag]);
        }
    }

    private function seedTag(int $id): void
    {
        $this->storage->seed('tag', 'id', ['id' => $id, 'name' => 'tag-' . $id]);
    }

    private function invoice(int $id): Invoice
    {
        return $this->uow->find(Invoice::class, $id) ?? throw new \LogicException('No invoice ' . $id);
    }

    /**
     * @param iterable<object> $entities
     *
     * @return list<int|null>
     */
    private static function ids(iterable $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = $entity instanceof Tag || $entity instanceof Invoice || $entity instanceof InvoiceLine || $entity instanceof StockItem ? $entity->id : null;
        }

        return $ids;
    }

    // ---------------------------------------------------------------- chargement

    public function testCollectionIsLoadedOnFirstAccessInItsOrder(): void
    {
        $this->seedInvoice(1);
        $this->storage->seed('invoice_line', 'id', ['id' => 10, 'invoice_id' => 1, 'position' => 2, 'label' => 'Second']);
        $this->storage->seed('invoice_line', 'id', ['id' => 11, 'invoice_id' => 1, 'position' => 1, 'label' => 'First']);
        $invoice = $this->invoice(1);

        self::assertInstanceOf(PersistentCollection::class, $invoice->lines);
        self::assertFalse($invoice->lines->isInitialized());
        self::assertSame(0, $this->selectsOn('invoice_line'));

        self::assertSame([11, 10], self::ids($invoice->lines), 'orderBy position');
        self::assertSame($invoice, $invoice->lines->first()?->invoice, 'the owner is the managed instance');
        self::assertSame(1, $this->selectsOn('invoice_line'));
        self::assertCount(2, $invoice->lines);
        self::assertSame(1, $this->selectsOn('invoice_line'), 'loaded once');
    }

    public function testFlushNeverLoadsACollection(): void
    {
        $this->seedInvoice(1, [1]);
        $this->invoice(1);

        $this->uow->commit();

        self::assertSame(0, $this->selectsOn('invoice_line') + $this->selectsOn('tag'));
        self::assertSame([], $this->writes());
    }

    public function testManyToManyIsLoadedThroughTheJoinTable(): void
    {
        $this->seedTag(1);
        $this->seedTag(2);
        $this->seedTag(3);
        $this->seedInvoice(1, [3, 1]);

        self::assertSame([1, 3], self::ids($this->invoice(1)->tags));
    }

    public function testInverseManyToManyIsLoadedThroughTheSameJoinTable(): void
    {
        $this->seedTag(1);
        $this->seedInvoice(1, [1]);
        $this->seedInvoice(2, [1]);

        $tag = $this->uow->find(Tag::class, 1);

        self::assertNotNull($tag);
        self::assertSame([1, 2], self::ids($tag->invoices));
    }

    public function testIndexBy(): void
    {
        $this->storage->seed('warehouse', 'id', ['id' => 1]);
        $this->storage->seed('stock_item', 'id', ['id' => 5, 'warehouse_id' => 1, 'sku' => 'B-2']);
        $this->storage->seed('stock_item', 'id', ['id' => 6, 'warehouse_id' => 1, 'sku' => 'A-1']);

        $items = $this->uow->find(Warehouse::class, 1)?->items;

        self::assertNotNull($items);
        self::assertSame(['B-2', 'A-1'], array_keys($items->toArray()));
        self::assertSame(6, $items->get('A-1')?->id);
    }

    public function testExtraLazyCountDoesNotLoad(): void
    {
        $this->storage->seed('warehouse', 'id', ['id' => 1]);
        $this->storage->seed('stock_item', 'id', ['id' => 5, 'warehouse_id' => 1, 'sku' => 'B-2']);

        $items = $this->uow->find(Warehouse::class, 1)?->items;

        self::assertNotNull($items);
        self::assertCount(1, $items);
        self::assertSame(0, $this->selectsOn('stock_item'));
        self::assertInstanceOf(PersistentCollection::class, $items);
        self::assertFalse($items->isInitialized());
    }

    // ---------------------------------------------------------------- écriture

    public function testNewOwnerAndItsCollectionsArePersistedInCascade(): void
    {
        $invoice = new Invoice('INV-1');
        $invoice->lines->add(new InvoiceLine($invoice, 1, 'First'));
        $invoice->lines->add(new InvoiceLine($invoice, 2, 'Second'));
        $invoice->tags->add($urgent = new Tag('urgent'));
        $this->uow->persist($invoice);
        $this->uow->commit();

        self::assertSame(['INSERT invoice', 'INSERT invoice_line', 'INSERT invoice_line', 'INSERT tag', 'INSERT invoice_tag'], $this->writes());
        self::assertSame([['invoice_id' => $invoice->id, 'tag_id' => $urgent->id]], $this->storage->pivotRows('invoice_tag'));

        $this->storage->log = [];
        $this->uow->commit();
        self::assertSame([], $this->writes(), 'the collection snapshot follows the flush');
    }

    public function testOnlyTheDifferenceIsWritten(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->seedTag($id);
        }
        $this->seedInvoice(1, [1, 2]);
        $invoice = $this->invoice(1);
        $first = $invoice->tags->first();
        self::assertNotNull($first);

        $invoice->tags->removeElement($first);
        $invoice->tags->add($this->uow->find(Tag::class, 3) ?? throw new \LogicException());
        $this->uow->commit();

        self::assertSame(['DELETE invoice_tag', 'INSERT invoice_tag'], $this->writes());
        self::assertSame([['invoice_id' => 1, 'tag_id' => 2], ['invoice_id' => 1, 'tag_id' => 3]], $this->storage->pivotRows('invoice_tag'));
    }

    public function testUnchangedLoadedCollectionWritesNothing(): void
    {
        $this->seedTag(1);
        $this->seedInvoice(1, [1]);
        self::assertCount(1, $this->invoice(1)->tags);

        $this->uow->commit();

        self::assertSame([], $this->writes());
    }

    public function testClearOfAnUnloadedCollectionDeletesAllItsRowsWithoutLoading(): void
    {
        $this->seedTag(1);
        $this->seedTag(2);
        $this->seedInvoice(1, [1, 2]);
        $this->seedInvoice(2, [1]);
        $invoice = $this->invoice(1);

        $invoice->tags->clear();
        $this->uow->commit();

        self::assertSame(['DELETE invoice_tag'], $this->writes());
        self::assertSame(0, $this->selectsOn('tag'));
        self::assertSame([['invoice_id' => 2, 'tag_id' => 1]], $this->storage->pivotRows('invoice_tag'));

        $this->storage->log = [];
        $this->uow->commit();
        self::assertSame([], $this->writes(), 'done once');
    }

    public function testReplacedUnloadedCollectionIsRewrittenEntirely(): void
    {
        $this->seedTag(1);
        $this->seedTag(2);
        $this->seedInvoice(1, [1]);
        $invoice = $this->invoice(1);

        $invoice->tags = new ArrayCollection([$this->uow->find(Tag::class, 2) ?? throw new \LogicException()]);
        $this->uow->commit();

        self::assertSame(['DELETE invoice_tag', 'INSERT invoice_tag'], $this->writes());
        self::assertSame([['invoice_id' => 1, 'tag_id' => 2]], $this->storage->pivotRows('invoice_tag'));
    }

    // RG-04.1
    public function testInverseSideChangesAreNotWritten(): void
    {
        $this->seedTag(1);
        $this->seedInvoice(1, [1]);
        $this->seedInvoice(2);
        $tag = $this->uow->find(Tag::class, 1);
        self::assertNotNull($tag);

        $tag->invoices->removeElement($this->invoice(1));
        $tag->invoices->add($this->invoice(2));
        $this->uow->commit();

        self::assertSame([], $this->writes());
        self::assertSame([['invoice_id' => 1, 'tag_id' => 1]], $this->storage->pivotRows('invoice_tag'));
    }

    // RG-04.1 : la FK de la ligne compte, pas la collection
    public function testOneToManyChangesAreNotWritten(): void
    {
        $this->seedInvoice(1);
        $this->storage->seed('invoice_line', 'id', ['id' => 10, 'invoice_id' => 1, 'position' => 1, 'label' => 'Line']);
        $invoice = $this->invoice(1);
        $line = $invoice->lines->first();
        self::assertNotNull($line);

        $invoice->lines->removeElement($line);
        $this->uow->commit();

        self::assertSame([], $this->writes());
    }

    // ---------------------------------------------------------------- suppression

    public function testRemovingTheOwnerDeletesItsJoinRowsFirst(): void
    {
        $this->seedTag(1);
        $this->seedInvoice(1, [1]);
        $this->seedInvoice(2, [1]);

        $this->uow->remove($this->invoice(1));
        $this->uow->commit();

        self::assertSame(['DELETE invoice_tag', 'DELETE invoice'], $this->writes());
        self::assertSame([['invoice_id' => 2, 'tag_id' => 1]], $this->storage->pivotRows('invoice_tag'));
    }

    public function testRemovingATargetDeletesItsJoinRowsThroughTheInverseSide(): void
    {
        $this->seedTag(1);
        $this->seedTag(2);
        $this->seedInvoice(1, [1, 2]);

        $this->uow->remove($this->uow->find(Tag::class, 1) ?? throw new \LogicException());
        $this->uow->commit();

        self::assertSame(['DELETE invoice_tag', 'DELETE tag'], $this->writes());
        self::assertSame([['invoice_id' => 1, 'tag_id' => 2]], $this->storage->pivotRows('invoice_tag'));
    }

    // ---------------------------------------------------------------- RG-04.2, readonly, refresh, rollback

    public function testNewElementWithoutCascadeIsRefused(): void
    {
        $this->seedTag(1);
        $tag = $this->uow->find(Tag::class, 1);
        self::assertNotNull($tag);
        $tag->invoices->add(new Invoice('Unsaved'));

        $this->expectException(UnpersistedRelationException::class);
        $this->expectExceptionMessage('was found through ' . Tag::class . '::$invoices, which does not cascade persist.');

        $this->uow->commit();
    }

    public function testReadonlyCollectionIsHydratedOnceAndResetByRefresh(): void
    {
        $invoice = new Invoice('INV-1');
        $invoice->lines->add(new InvoiceLine($invoice, 1, 'First'));
        $this->uow->persist($invoice);
        $this->uow->commit();
        $this->uow->clear();

        $read = $this->invoice((int) $invoice->id);
        self::assertSame(['First'], array_map(static fn (InvoiceLine $l): string => $l->label, array_values($read->lines->toArray())));

        $this->storage->seed('invoice_line', 'id', ['id' => 99, 'invoice_id' => $invoice->id, 'position' => 2, 'label' => 'Added elsewhere']);
        $this->uow->refresh($read);
        self::assertSame(['First', 'Added elsewhere'], array_map(static fn (InvoiceLine $l): string => $l->label, array_values($read->lines->toArray())), 'the same collection, loaded again');
    }

    public function testRefreshReloadsAManyToMany(): void
    {
        $this->seedTag(1);
        $this->seedTag(2);
        $this->seedInvoice(1, [1]);
        $invoice = $this->invoice(1);
        self::assertSame([1], self::ids($invoice->tags));

        $this->storage->seedPivot('invoice_tag', ['invoice_id' => 1, 'tag_id' => 2]);
        $this->uow->refresh($invoice);

        self::assertSame([1, 2], self::ids($invoice->tags));
    }

    // RG-03.6 : un flush annulé n'avance pas le snapshot des collections
    public function testFailedFlushKeepsTheCollectionDifference(): void
    {
        $invoice = new Invoice('INV-1');
        $invoice->tags->add(new Tag('urgent'));
        $this->uow->persist($invoice);
        $this->storage->failOnInsert(2);

        try {
            $this->uow->commit();
            self::fail('Expected the simulated failure.');
        } catch (\RuntimeException) {
        }
        self::assertSame([], $this->storage->pivotRows('invoice_tag'));

        $this->storage->failOnInsert(null);
        $this->uow->commit();
        self::assertCount(1, $this->storage->pivotRows('invoice_tag'));
    }

    // ---------------------------------------------------------------- compléments (mutants)

    public function testReferenceLoadedLaterHasItsCollections(): void
    {
        $this->seedTag(1);
        $this->seedInvoice(1, [1]);

        $reference = $this->uow->getReference(Invoice::class, 1);

        self::assertSame([1], self::ids($reference->tags));
    }

    public function testFlushNeverLoadsAnUnusedReferenceWithCollections(): void
    {
        $this->seedInvoice(1);
        $this->uow->getReference(Invoice::class, 1);

        $this->uow->commit();

        self::assertSame(0, $this->selectsOn('invoice'));
    }

    public function testRefreshKeepsAReadonlyCollectionCreatedByTheApplication(): void
    {
        $invoice = new Invoice('INV-1');
        $lines = $invoice->lines;
        $this->uow->persist($invoice);
        $this->uow->commit();

        $this->uow->refresh($invoice);

        self::assertSame($lines, $invoice->lines, 'a readonly property is never written again');
    }

    public function testOnlyARemovalIsWritten(): void
    {
        $this->seedTag(1);
        $this->seedTag(2);
        $this->seedInvoice(1, [1, 2]);
        $this->seedInvoice(2, [1]);
        $invoice = $this->invoice(1);
        $first = $invoice->tags->first();
        self::assertNotNull($first);

        $invoice->tags->removeElement($first);
        $this->uow->commit();

        self::assertSame(['DELETE invoice_tag'], $this->writes());
        self::assertSame([['invoice_id' => 1, 'tag_id' => 2], ['invoice_id' => 2, 'tag_id' => 1]], $this->storage->pivotRows('invoice_tag'), 'the other invoice keeps the tag');
    }

    public function testCollectionsOfSeveralEntitiesInOneFlush(): void
    {
        $this->seedTag(1);
        $this->seedTag(2);
        $this->seedInvoice(1);
        $this->seedInvoice(2);
        $this->seedInvoice(3);
        $this->storage->seed('invoice_line', 'id', ['id' => 10, 'invoice_id' => 3, 'position' => 1, 'label' => 'Line']);
        // devant les factures : une entité sans collection, une entité supprimée
        $this->uow->find(InvoiceLine::class, 10);
        $this->uow->remove($this->invoice(3));
        $first = $this->invoice(1);
        $second = $this->invoice(2);

        $first->tags->add($this->uow->find(Tag::class, 1) ?? throw new \LogicException());
        $second->tags->add($this->uow->find(Tag::class, 2) ?? throw new \LogicException());
        $this->uow->commit();

        self::assertSame([['invoice_id' => 1, 'tag_id' => 1], ['invoice_id' => 2, 'tag_id' => 2]], $this->storage->pivotRows('invoice_tag'));
    }

    public function testNewElementOfTheSecondCollectionIsPersisted(): void
    {
        $this->seedInvoice(1);
        $invoice = $this->invoice(1);

        // lines, la première collection, n'est pas chargée
        $invoice->tags->add(new Tag('new'));
        $this->uow->commit();

        self::assertSame(['INSERT tag', 'INSERT invoice_tag'], $this->writes());
    }
}
