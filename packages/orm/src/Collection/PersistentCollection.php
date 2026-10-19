<?php

declare(strict_types=1);

namespace Prism\Orm\Collection;

/**
 * Collection posée par l'ORM à l'hydratation (ADR 0007) : chargée au premier accès, une fois.
 * Avec un compteur (fetch: EXTRA_LAZY), count() ne la charge pas.
 *
 * clear() sur une collection non chargée ne la charge pas : l'UnitOfWork supprime alors toutes
 * les lignes de la relation au flush.
 *
 * @template TKey of array-key
 * @template T of object
 *
 * @implements Collection<TKey, T>
 *
 * @internal
 */
final class PersistentCollection implements Collection
{
    /** @use CollectionBehavior<TKey, T> */
    use CollectionBehavior;

    /** @var array<TKey, T>|null null tant qu'elle n'est pas chargée */
    private ?array $elements = null;

    private bool $cleared = false;

    /**
     * @param \Closure(): array<TKey, T> $loader
     * @param (\Closure(): int)|null     $counter
     */
    public function __construct(
        private readonly \Closure $loader,
        private readonly ?\Closure $counter = null,
    ) {
    }

    public function isInitialized(): bool
    {
        return null !== $this->elements;
    }

    /**
     * Vrai si clear() a été appelé avant tout chargement ; remis à faux par l'UnitOfWork une fois
     * les lignes supprimées.
     */
    public function wasClearedUnloaded(): bool
    {
        return $this->cleared;
    }

    public function acknowledgeClear(): void
    {
        $this->cleared = false;
    }

    /**
     * Revient à l'état non chargé (refresh d'une propriété readonly).
     */
    public function reset(): void
    {
        $this->elements = null;
        $this->cleared = false;
    }

    public function clear(): void
    {
        if (null === $this->elements) {
            $this->cleared = true;
        }
        $this->elements = [];
    }

    public function count(): int
    {
        if (null === $this->elements && null !== $this->counter) {
            return max(0, ($this->counter)());
        }

        return \count($this->elements());
    }

    /**
     * @return array<TKey, T>
     */
    private function &elements(): array
    {
        if (null === $this->elements) {
            $this->elements = ($this->loader)();
        }

        return $this->elements;
    }
}
