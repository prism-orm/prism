<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Detection\Bag;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Detection\Tags;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Detection\TagsType;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence de la détection des changements (RG-03.5, ADR 0022), écrits avant le
 * snapshot en valeurs PHP : ce qui doit rester un changement, et ce qui ne doit pas en être un.
 */
final class UnitOfWorkChangeDetectionTest extends TestCase
{
    private InMemoryStorage $storage;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $types = TypeRegistry::withDefaults()->register(new TagsType());
        $this->storage = new InMemoryStorage();
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver(), types: $types), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), $types);
        $this->storage->seed('bag', 'id', ['id' => 1, 'tags' => 'red,blue', 'packed_at' => '2026-10-28 08:00:00', 'weight' => '12.50', 'meta' => '{"size":"L","codes":[1,2]}']);
    }

    private function bag(): Bag
    {
        $bag = $this->uow->find(Bag::class, 1);
        self::assertNotNull($bag);
        $this->storage->log = [];

        return $bag;
    }

    /**
     * @return list<mixed> les colonnes écrites par chaque UPDATE du flush
     */
    private function flushedUpdates(): array
    {
        $this->storage->log = [];
        $this->uow->commit();

        return array_map(static fn (array $operation): mixed => $operation[1], $this->storage->operations('UPDATE'));
    }

    private function stored(string $column): mixed
    {
        return ($this->storage->load('bag', 1) ?? [])[$column] ?? null;
    }

    public function testNothingChangedWritesNothing(): void
    {
        $this->bag();

        self::assertSame([], $this->flushedUpdates());
    }

    // RG-03.5 : un objet mutable modifié sur place change sa valeur en base, pas son identité
    public function testMutableObjectChangedInPlaceIsDetected(): void
    {
        $bag = $this->bag();
        $bag->tags->items[] = 'green';

        self::assertSame([['tags']], $this->flushedUpdates());
        self::assertSame('red,blue,green', $this->stored('tags'));
        self::assertSame([], $this->flushedUpdates(), 'the snapshot follows the written value');

        // ADR 0022 : après le flush, la colonne reste comparée en valeurs de la base
        $bag->tags->items[] = 'black';
        self::assertSame([['tags']], $this->flushedUpdates());
        self::assertSame('red,blue,green,black', $this->stored('tags'));
    }

    // ADR 0022 : un objet mutable affecté avant un flush, puis modifié sur place
    public function testMutableObjectAssignedThenChangedInPlaceIsDetected(): void
    {
        $bag = $this->bag();
        $tags = new Tags(['white']);
        $bag->tags = $tags;
        self::assertSame([['tags']], $this->flushedUpdates());

        $tags->items[] = 'grey';
        self::assertSame([['tags']], $this->flushedUpdates());
        self::assertSame('white,grey', $this->stored('tags'));
    }

    // ADR 0022 : un tableau json modifié après un flush reste détecté
    public function testJsonChangedAfterAFlushIsDetected(): void
    {
        $bag = $this->bag();
        $bag->meta = ['size' => 'M'];
        self::assertSame([['meta']], $this->flushedUpdates());

        $bag->meta['size'] = 'S';
        self::assertSame([['meta']], $this->flushedUpdates());
        self::assertSame('{"size":"S"}', $this->stored('meta'));
    }

    // les mêmes valeurs en base, écrites autrement : pas un changement
    public function testEqualValuesAreNotChanges(): void
    {
        $bag = $this->bag();
        $bag->packedAt = new \DateTimeImmutable('2026-10-28 08:00:00');
        $bag->weight = '12.5';
        $bag->tags = new Tags(['red', 'blue']);
        $bag->meta = ['size' => 'L', 'codes' => [1, 2]];

        self::assertSame([], $this->flushedUpdates());
    }

    public function testChangedValuesAreWritten(): void
    {
        $bag = $this->bag();
        $bag->packedAt = $bag->packedAt->modify('+1 day');
        $codes = (array) $bag->meta['codes'];
        $codes[] = 3;
        $bag->meta['codes'] = $codes;

        self::assertSame([['packed_at', 'meta']], $this->flushedUpdates());
        self::assertSame(['2026-10-29 08:00:00', '{"size":"L","codes":[1,2,3]}'], [$this->stored('packed_at'), $this->stored('meta')]);
        self::assertSame([], $this->flushedUpdates());
    }
}
