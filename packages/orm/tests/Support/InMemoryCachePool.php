<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache PSR-6 de test qui sérialise réellement les valeurs, comme un cache sur disque ou Redis :
 * un pool qui garderait les objets tels quels ne prouverait rien sur la sérialisation.
 *
 * @internal
 */
final class InMemoryCachePool implements CacheItemPoolInterface
{
    /** @var array<string, string> valeurs sérialisées */
    private array $items = [];

    public int $writes = 0;

    public function getItem(string $key): CacheItemInterface
    {
        self::assertKey($key);
        if (!isset($this->items[$key])) {
            return new InMemoryCacheItem($key, null, false);
        }

        return new InMemoryCacheItem($key, unserialize($this->items[$key]), true);
    }

    /**
     * @param string[] $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function size(): int
    {
        return \count($this->items);
    }

    public function hasItem(string $key): bool
    {
        self::assertKey($key);

        return isset($this->items[$key]);
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        self::assertKey($key);
        unset($this->items[$key]);

        return true;
    }

    /**
     * @param string[] $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->items[$item->getKey()] = serialize($item->get());
        ++$this->writes;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }

    /**
     * Simule une entrée écrite par une autre version de Prism.
     */
    public function corrupt(string $key, string $serialized): void
    {
        $this->items[$key] = $serialized;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->items);
    }

    private static function assertKey(string $key): void
    {
        if ('' === $key || 1 === preg_match('#[{}()/\\\\@:]#', $key)) {
            throw new InvalidCacheKey(\sprintf('Invalid PSR-6 key "%s".', $key));
        }
    }
}
