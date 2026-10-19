<?php

declare(strict_types=1);

namespace Prism\Orm\Collection;

/**
 * Collection d'entités d'une relation OneToMany ou ManyToMany (ADR 0007). L'ORM ne la remplace
 * jamais : il compare ses éléments à ceux du dernier chargement ou du dernier flush.
 *
 * @template TKey of array-key
 * @template T of object
 *
 * @extends \IteratorAggregate<TKey, T>
 * @extends \ArrayAccess<TKey|null, T>
 *
 * @api
 */
interface Collection extends \IteratorAggregate, \Countable, \ArrayAccess
{
    /**
     * @param T $element
     */
    public function add(object $element): void;

    /**
     * @param T $element
     *
     * @return bool faux si l'élément n'y était pas
     */
    public function removeElement(object $element): bool;

    /**
     * @param T $element
     */
    public function contains(object $element): bool;

    /**
     * @param TKey $key
     *
     * @return T|null
     */
    public function get(int|string $key): ?object;

    /**
     * @param TKey $key
     * @param T    $element
     */
    public function set(int|string $key, object $element): void;

    /**
     * @param TKey $key
     *
     * @return T|null l'élément retiré
     */
    public function remove(int|string $key): ?object;

    public function clear(): void;

    public function isEmpty(): bool;

    /**
     * @return T|null
     */
    public function first(): ?object;

    /**
     * @return array<TKey, T>
     */
    public function toArray(): array;
}
