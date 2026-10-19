<?php

declare(strict_types=1);

namespace Prism\Orm\Collection;

/**
 * Collection créée par l'application, dans le constructeur de l'entité (ADR 0007) :
 * `$this->lines = new ArrayCollection();`.
 *
 * @template TKey of array-key
 * @template T of object
 *
 * @implements Collection<TKey, T>
 *
 * @api
 */
final class ArrayCollection implements Collection
{
    /** @use CollectionBehavior<TKey, T> */
    use CollectionBehavior;

    /**
     * @param array<TKey, T> $elements
     */
    public function __construct(private array $elements = [])
    {
    }

    public function clear(): void
    {
        $this->elements = [];
    }

    public function count(): int
    {
        return \count($this->elements);
    }

    /**
     * @return array<TKey, T>
     */
    private function &elements(): array
    {
        return $this->elements;
    }
}
