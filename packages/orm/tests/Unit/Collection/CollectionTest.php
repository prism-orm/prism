<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Collection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Collection\PersistentCollection;

final class CollectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'ArrayCollection' => ['array'];
        yield 'PersistentCollection' => ['persistent'];
    }

    /**
     * @param list<\stdClass> $elements
     *
     * @return Collection<array-key, \stdClass>
     */
    private static function make(string $kind, array $elements): Collection
    {
        return 'array' === $kind ? new ArrayCollection($elements) : new PersistentCollection(static fn (): array => $elements);
    }

    #[DataProvider('implementations')]
    public function testSameBehaviourForBothImplementations(string $kind): void
    {
        [$a, $b, $c] = [new \stdClass(), new \stdClass(), new \stdClass()];
        $collection = self::make($kind, [$a, $b]);

        self::assertCount(2, $collection);
        self::assertTrue($collection->contains($a));
        self::assertFalse($collection->contains($c));
        self::assertSame($a, $collection->first());
        self::assertSame($b, $collection->get(1));
        self::assertNull($collection->get(9));

        $collection->add($c);
        $c2 = new \stdClass();
        $collection[] = $c2;
        $keyed = new \stdClass();
        $collection['key'] = $keyed;
        self::assertSame([$a, $b, $c, $c2, 'key' => $keyed], $collection->toArray());
        self::assertTrue(isset($collection['key']));
        self::assertSame($keyed, $collection->offsetGet('key'));

        self::assertTrue($collection->removeElement($b));
        self::assertFalse($collection->removeElement($b));
        self::assertSame($c, $collection->remove(2));
        self::assertNull($collection->remove(2));
        unset($collection['key']);
        self::assertSame([0 => $a, 3 => $c2], iterator_to_array($collection));

        $collection->set(5, $b);
        self::assertSame($b, $collection->get(5));
        self::assertFalse($collection->isEmpty());
        $collection->clear();
        self::assertTrue($collection->isEmpty());
        self::assertNull($collection->first());
        self::assertCount(0, $collection);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function nonObjects(): iterable
    {
        yield 'string in an ArrayCollection' => ['array', 'text', 'string'];
        yield 'int in a PersistentCollection' => ['persistent', 42, 'int'];
    }

    #[DataProvider('nonObjects')]
    public function testOnlyObjectsAreAccepted(string $kind, mixed $value, string $type): void
    {
        $collection = self::make($kind, []);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('A collection of entities only holds objects, got ' . $type . '.');

        $collection[] = $value;
    }

    public function testPersistentCollectionLoadsOnceOnFirstAccess(): void
    {
        $loads = new \ArrayObject();
        $collection = new PersistentCollection(static function () use ($loads): array {
            $loads->append(true);

            return [new \stdClass()];
        });

        self::assertFalse($collection->isInitialized());
        self::assertCount(0, $loads);
        self::assertCount(1, $collection);
        self::assertTrue($collection->isInitialized());
        iterator_to_array($collection);
        self::assertCount(1, $loads);

        $collection->reset();
        self::assertFalse($collection->isInitialized());
        $collection->toArray();
        self::assertCount(2, $loads);
    }

    // EXTRA_LAZY
    public function testCounterAvoidsLoading(): void
    {
        $collection = new PersistentCollection(static fn (): array => throw new \LogicException('Must not load.'), static fn (): int => 42);

        self::assertCount(42, $collection);
        self::assertFalse($collection->isInitialized());
    }

    public function testClearBeforeLoadingDoesNotLoad(): void
    {
        $collection = new PersistentCollection(static fn (): array => throw new \LogicException('Must not load.'));

        $collection->clear();

        self::assertTrue($collection->wasClearedUnloaded());
        self::assertSame([], $collection->toArray());
        $collection->acknowledgeClear();
        self::assertFalse($collection->wasClearedUnloaded());
    }

    public function testClearAfterLoadingIsAnOrdinaryClear(): void
    {
        $collection = new PersistentCollection(static fn (): array => [new \stdClass()]);
        $collection->toArray();

        $collection->clear();

        self::assertFalse($collection->wasClearedUnloaded());
        self::assertTrue($collection->isEmpty());
    }
}
