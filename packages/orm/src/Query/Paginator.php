<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

/**
 * Une page d'une requête typée (RG-05.9), créée par EntityQueryBuilder::paginate() : ses
 * entités sont déjà chargées, le total vient d'une requête COUNT séparée, lancée au premier
 * besoin et une seule fois. Une page incomplète donne le total sans requête.
 *
 * @template T of object
 *
 * @implements \IteratorAggregate<int, T>
 *
 * @api
 */
final class Paginator implements \IteratorAggregate, \Countable
{
    private ?int $total = null;

    /**
     * @param list<T>         $items
     * @param \Closure(): int $count compte les entités de toutes les pages
     *
     * @internal créé par EntityQueryBuilder::paginate()
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        private readonly \Closure $count,
    ) {
    }

    /**
     * Le nombre d'entités de toutes les pages.
     */
    public function total(): int
    {
        if (null === $this->total) {
            $known = \count($this->items) < $this->perPage && ([] !== $this->items || 1 === $this->page);
            $this->total = $known ? ($this->page - 1) * $this->perPage + \count($this->items) : ($this->count)();
        }

        return $this->total;
    }

    /**
     * Le nombre de pages ; 0 sans aucune entité.
     */
    public function pageCount(): int
    {
        return intdiv($this->total() + $this->perPage - 1, $this->perPage);
    }

    public function hasNext(): bool
    {
        return \count($this->items) === $this->perPage && $this->page < $this->pageCount();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Le nombre d'entités de cette page ; total() pour toutes les pages.
     */
    public function count(): int
    {
        return \count($this->items);
    }
}
