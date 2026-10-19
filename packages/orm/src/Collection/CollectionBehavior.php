<?php

declare(strict_types=1);

namespace Prism\Orm\Collection;

/**
 * Comportement commun d'ArrayCollection et de PersistentCollection, sur le tableau que rend
 * &elements() : PersistentCollection s'y charge au premier accès.
 *
 * @template TKey of array-key
 * @template T of object
 *
 * @internal
 */
trait CollectionBehavior
{
    /**
     * @return array<TKey, T>
     */
    abstract private function &elements(): array;

    public function add(object $element): void
    {
        $elements = &$this->elements();
        $elements[] = $element;
    }

    public function removeElement(object $element): bool
    {
        $elements = &$this->elements();
        $key = array_search($element, $elements, true);
        if (false === $key) {
            return false;
        }
        unset($elements[$key]);

        return true;
    }

    public function contains(object $element): bool
    {
        return \in_array($element, $this->elements(), true);
    }

    public function get(int|string $key): ?object
    {
        return $this->elements()[$key] ?? null;
    }

    public function set(int|string $key, object $element): void
    {
        $elements = &$this->elements();
        $elements[$key] = $element;
    }

    public function remove(int|string $key): ?object
    {
        $elements = &$this->elements();
        $removed = $elements[$key] ?? null;
        unset($elements[$key]);

        return $removed;
    }

    public function isEmpty(): bool
    {
        return [] === $this->elements();
    }

    public function first(): ?object
    {
        $elements = $this->elements();

        return [] === $elements ? null : $elements[array_key_first($elements)];
    }

    public function toArray(): array
    {
        return $this->elements();
    }

    /**
     * @return \ArrayIterator<TKey, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->elements());
    }

    public function offsetExists(mixed $offset): bool
    {
        return (\is_int($offset) || \is_string($offset)) && isset($this->elements()[$offset]);
    }

    public function offsetGet(mixed $offset): ?object
    {
        return \is_int($offset) || \is_string($offset) ? $this->get($offset) : null;
    }

    /**
     * @param mixed $value vérifié : ArrayAccess ne contraint pas le type à l'exécution
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!\is_object($value)) {
            throw new \TypeError(\sprintf('A collection of entities only holds objects, got %s.', get_debug_type($value)));
        }
        /** @var T $value */
        if (\is_int($offset) || \is_string($offset)) {
            $this->set($offset, $value);

            return;
        }
        $this->add($value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (\is_int($offset) || \is_string($offset)) {
            $elements = &$this->elements();
            unset($elements[$offset]);
        }
    }
}
