<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Orm\Collection\PersistentCollection;
use Prism\Orm\EntityManager;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Invoice;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\InvoiceLine;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\StockItem;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Tag;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Warehouse;

/**
 * Collections sur base réelle (ADR 0007), sur les 4 bases : chargement par FK et par table
 * pivot, différence écrite, suppression avec ses lignes pivot.
 */
final class CollectionsTest extends MappingSchemaTestCase
{
    private EntityManager $em;

    protected static function entities(): array
    {
        return [Invoice::class, InvoiceLine::class, Tag::class, Warehouse::class, StockItem::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = new EntityManager($this->connection);
    }

    private function rowsIn(string $table): int
    {
        $count = $this->connection->createQueryBuilder()->select('COUNT(*)')->from($table)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @param iterable<Tag> $tags
     *
     * @return list<string>
     */
    private static function names(iterable $tags): array
    {
        $names = [];
        foreach ($tags as $tag) {
            $names[] = $tag->name;
        }
        sort($names);

        return $names;
    }

    private function invoiceWithTags(string ...$tags): Invoice
    {
        $invoice = new Invoice('INV-1');
        $invoice->lines->add(new InvoiceLine($invoice, 2, 'Second'));
        $invoice->lines->add(new InvoiceLine($invoice, 1, 'First'));
        foreach ($tags as $tag) {
            $invoice->tags->add(new Tag($tag));
        }
        $this->em->persist($invoice);
        $this->em->flush();
        $this->em->clear();

        return $this->em->find(Invoice::class, $invoice->id) ?? throw new \LogicException('Invoice not found.');
    }

    // critère de sortie : entité final, collection readonly
    public function testFinalEntityWithAReadonlyCollectionIsPersistedAndReadBack(): void
    {
        $invoice = $this->invoiceWithTags('urgent', 'paid');

        self::assertInstanceOf(PersistentCollection::class, $invoice->lines);
        self::assertSame(['First', 'Second'], array_map(static fn (InvoiceLine $l): string => $l->label, array_values($invoice->lines->toArray())), 'orderBy position');
        self::assertSame(['paid', 'urgent'], self::names($invoice->tags));
        self::assertSame($invoice, $invoice->lines->first()?->invoice);
    }

    public function testInverseManyToManyReadsTheSameJoinTable(): void
    {
        $invoice = $this->invoiceWithTags('urgent');
        $tag = $invoice->tags->first();
        self::assertNotNull($tag);

        self::assertSame([$invoice], array_values($tag->invoices->toArray()));
    }

    public function testDifferenceIsWritten(): void
    {
        $invoice = $this->invoiceWithTags('urgent', 'paid');
        $paid = null;
        foreach ($invoice->tags as $tag) {
            if ('paid' === $tag->name) {
                $paid = $tag;
            }
        }
        self::assertNotNull($paid);

        $invoice->tags->removeElement($paid);
        $invoice->tags->add(new Tag('late'));
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['late', 'urgent'], self::names($this->em->find(Invoice::class, $invoice->id)->tags ?? []));
        self::assertSame(2, $this->rowsIn('invoice_tag'));
        self::assertSame(3, $this->rowsIn('tag'), 'removing from the collection never deletes the tag');
    }

    public function testClearWithoutLoadingDeletesTheJoinRows(): void
    {
        $invoice = $this->invoiceWithTags('urgent', 'paid');

        $invoice->tags->clear();
        $this->em->flush();

        self::assertSame(0, $this->rowsIn('invoice_tag'));
    }

    public function testRemovingTheOwnerDeletesItsLinesAndJoinRows(): void
    {
        $invoice = $this->invoiceWithTags('urgent');
        foreach ($invoice->lines as $line) {
            $this->em->remove($line);
        }
        $this->em->remove($invoice);
        $this->em->flush();

        self::assertSame([0, 0, 0, 1], [$this->rowsIn('invoice'), $this->rowsIn('invoice_line'), $this->rowsIn('invoice_tag'), $this->rowsIn('tag')]);
    }

    public function testRemovingATagDeletesItsJoinRows(): void
    {
        $invoice = $this->invoiceWithTags('urgent', 'paid');
        $tag = $invoice->tags->first();
        self::assertNotNull($tag);

        $this->em->remove($tag);
        $this->em->flush();

        self::assertSame([1, 1], [$this->rowsIn('tag'), $this->rowsIn('invoice_tag')]);
    }

    public function testExtraLazyCountAndIndexBy(): void
    {
        $warehouse = new Warehouse();
        $this->em->persist($warehouse);
        $this->em->persist(new StockItem($warehouse, 'B-2'));
        $this->em->persist(new StockItem($warehouse, 'A-1'));
        $this->em->flush();
        $this->em->clear();

        $items = $this->em->find(Warehouse::class, $warehouse->id)?->items;
        self::assertNotNull($items);
        self::assertCount(2, $items);
        self::assertInstanceOf(PersistentCollection::class, $items);
        self::assertFalse($items->isInitialized(), 'EXTRA_LAZY: counted without loading');
        self::assertSame('A-1', $items->get('A-1')?->sku);
    }
}
