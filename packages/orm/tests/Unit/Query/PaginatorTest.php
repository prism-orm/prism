<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Query\Paginator;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_;

/**
 * Pages et refus du paginateur, sans base (RG-05.9) : chaque refus est levé avant toute requête.
 */
final class PaginatorTest extends TestCase
{
    private int $counted = 0;

    /**
     * @param list<object> $items
     *
     * @return Paginator<object>
     */
    private function page(array $items, int $page, int $perPage, int $total): Paginator
    {
        return new Paginator($items, $page, $perPage, function () use ($total): int {
            ++$this->counted;

            return $total;
        });
    }

    /**
     * @return list<object>
     */
    private static function items(int $count): array
    {
        return array_map(static fn (): object => new \stdClass(), array_fill(0, $count, null));
    }

    public function testFullPageCountsOnceOnDemand(): void
    {
        $page = $this->page(self::items(10), 2, 10, 25);

        self::assertSame(0, $this->counted, 'counted on demand');
        self::assertSame([25, 3, true, true], [$page->total(), $page->pageCount(), $page->hasNext(), $page->hasPrevious()]);
        self::assertSame(1, $this->counted, 'once');
        self::assertCount(10, $page);
        self::assertSame($page->items, iterator_to_array($page));
    }

    public function testIncompletePageKnowsTheTotal(): void
    {
        $last = $this->page(self::items(5), 3, 10, 999);

        self::assertSame([25, 3, false, true], [$last->total(), $last->pageCount(), $last->hasNext(), $last->hasPrevious()]);
        self::assertSame(0, $this->counted);
        self::assertSame([0, 0, false, false], [($empty = $this->page([], 1, 10, 999))->total(), $empty->pageCount(), $empty->hasNext(), $empty->hasPrevious()]);
        self::assertSame(0, $this->counted, 'an empty first page has no entity at all');
    }

    public function testEmptyPageBeyondTheEndCounts(): void
    {
        $beyond = $this->page([], 9, 10, 25);

        self::assertSame([25, 3, false, true], [$beyond->total(), $beyond->pageCount(), $beyond->hasNext(), $beyond->hasPrevious()]);
        self::assertSame(1, $this->counted);
    }

    public function testFullLastPageHasNoNext(): void
    {
        self::assertFalse($this->page(self::items(10), 3, 10, 30)->hasNext());
    }

    // ---------------------------------------------------------------- refus

    /**
     * @param \Closure(EntityManager, Customer_): mixed $paginate
     */
    private static function assertRefused(string $message, \Closure $paginate): void
    {
        $em = new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));
        try {
            $paginate($em, Customer_::as('c'));
            self::fail('Expected QueryException.');
        } catch (QueryException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testInvalidPageIsRefused(): void
    {
        self::assertRefused('A page starts at 1 and holds at least 1 entity, got page 0 of 10.', static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->orderBy($c->name->asc())->paginate(0, 10));
        self::assertRefused('A page starts at 1 and holds at least 1 entity, got page 1 of 0.', static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->orderBy($c->name->asc())->paginate(1, 0));
    }

    // RG-05.9
    public function testQueryWithoutOrderIsRefused(): void
    {
        self::assertRefused('Pages of ' . Customer::class . ' need a deterministic order: add an orderBy(); its identifier is added as the last criterion.', static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->paginate(1));
    }

    public function testLimitedQueryIsRefused(): void
    {
        $message = 'paginate() sets the limit and the offset of the page: remove limit() and offset() from the query.';

        self::assertRefused($message, static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->orderBy($c->name->asc())->limit(5)->paginate(1));
        self::assertRefused($message, static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->orderBy($c->name->asc())->offset(5)->paginate(1));
    }

    public function testOrderingOnACollectionIsRefused(): void
    {
        $message = 'With a joined collection, a page is ordered by fields of the root or of a ToOne relation: an order on the collection, or on a computed expression, does not order the root entities.';

        self::assertRefused($message, static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->leftFetchJoin($o = $c->orders->as('o'))->orderBy($o->reference->asc())->paginate(1));
        self::assertRefused($message, static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->join($o = $c->orders->as('o'))->leftJoin($s = $o->seller->as('s'))->orderBy($s->name->asc())->paginate(1)); // jointe depuis la collection
        self::assertRefused($message, static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->join($c->orders->as('o'))->orderBy($c->name->max()->asc())->paginate(1));
    }

    public function testGroupedQueryIsRefused(): void
    {
        self::assertRefused('A query on ' . Customer::class . ' with groupBy(), having() or an ordering on an aggregate returns groups, not entities: project it with selectInto().', static fn (EntityManager $em, Customer_ $c): mixed => $em->query($c)->groupBy($c->name)->orderBy($c->name->asc())->paginate(1));
    }
}
