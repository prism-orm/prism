<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Hydrator;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Hydrator\ArrayHydrator;
use Prism\Orm\Hydrator\ObjectHydrator;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tests\Unit\Hydrator\Fixtures\Product;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;

final class HydratorTest extends TestCase
{
    private ObjectHydrator $objects;

    /** @var ClassMetadata<Product> */
    private ClassMetadata $metadata;

    protected function setUp(): void
    {
        $this->objects = new ObjectHydrator(new ConversionContext(new PostgreSqlPlatform('16')), TypeRegistry::withDefaults());
        $this->metadata = new AttributeDriver()->loadMetadata(Product::class);
        Product::$constructed = 0;
    }

    /**
     * Ligne telle que le driver la renvoie.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7, 'name' => 'Desk', 'price' => '149.90', 'status' => 'paid', 'released_at' => '2026-10-18 09:30:00',
            'thumbnail' => null, 'stock_street' => null, 'stock_zip' => null, 'stock_city' => null, 'sku' => 'DSK-1',
        ];
    }

    public function testRowIsConvertedToPhpValues(): void
    {
        $product = $this->objects->hydrate($this->metadata, self::row());

        self::assertSame(7, $product->id);
        // RG-01.5 : decimal en string
        self::assertSame('149.90', $product->price);
        self::assertSame(FixtureStatus::Paid, $product->status);
        self::assertEquals(new \DateTimeImmutable('2026-10-18 09:30:00'), $product->releasedAt);
        self::assertNull($product->thumbnail);
        self::assertNull($product->warehouse, 'RG-02.5');
        // RG-02.6 : la propriété readonly est écrite à l'hydratation
        self::assertSame('DSK-1', $product->sku);
    }

    // RG-03.12 ; #[PostLoad] est publié par l'UnitOfWork (UnitOfWorkEventsTest)
    public function testConstructorIsNeverCalledNorAnyCallback(): void
    {
        $product = $this->objects->hydrate($this->metadata, self::row());

        self::assertSame(0, Product::$constructed);
        self::assertSame(0, $product->postLoads);
    }

    public function testBinaryStreamFromTheDriverIsRead(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x89PNG\x00");
        rewind($stream);

        self::assertSame("\x89PNG\x00", $this->objects->hydrate($this->metadata, self::row(['thumbnail' => $stream]))->thumbnail);
    }

    public function testFilledEmbeddable(): void
    {
        $product = $this->objects->hydrate($this->metadata, self::row(['stock_street' => '1 rue du Port', 'stock_zip' => '13002', 'stock_city' => 'Marseille']));

        self::assertEquals(new Address('1 rue du Port', '13002', 'Marseille'), $product->warehouse);
    }

    public function testUnknownColumnsAreIgnoredAndMissingOnesLeaveTheProperty(): void
    {
        $row = self::row(['joined_total' => 3]);
        unset($row['name']);

        $product = $this->objects->hydrate($this->metadata, $row);

        self::assertSame('', $product->name, 'missing column: default value kept');
        self::assertArrayNotHasKey('joined_total', $this->objects->toPhpRow($this->metadata, $row));
    }

    // RG-02.6 : refresh() ne réécrit jamais une propriété readonly
    public function testRefreshKeepsReadonlyProperties(): void
    {
        $product = $this->objects->hydrate($this->metadata, self::row());
        $product->name = 'Edited in memory';

        $this->objects->refresh($product, $this->metadata, self::row(['name' => 'Desk XL', 'sku' => 'CHANGED', 'stock_street' => 'a', 'stock_zip' => 'b', 'stock_city' => 'c']));

        self::assertSame('Desk XL', $product->name);
        self::assertSame('DSK-1', $product->sku);
        self::assertSame('c', $product->warehouse?->city);
        self::assertSame(0, $product->postLoads);
    }

    public function testToPhpRowConvertsWithoutHydrating(): void
    {
        $values = $this->objects->toPhpRow($this->metadata, self::row());

        self::assertSame('paid', $values['status'], 'the enum is rebuilt by the entity, not by the Type');
        self::assertInstanceOf(\DateTimeImmutable::class, $values['released_at']);
        self::assertSame(0, Product::$constructed);
    }

    public function testArrayHydrator(): void
    {
        $arrays = new ArrayHydrator($this->objects);

        $result = $arrays->hydrate($this->metadata, self::row(['stock_street' => '1 rue du Port', 'stock_zip' => '13002', 'stock_city' => 'Marseille']));

        self::assertSame(['id', 'name', 'price', 'status', 'releasedAt', 'thumbnail', 'sku', 'warehouse'], array_keys($result), 'field names, not columns');
        self::assertSame('149.90', $result['price']);
        self::assertSame(FixtureStatus::Paid, $result['status']);
        self::assertSame(['street' => '1 rue du Port', 'zip' => '13002', 'city' => 'Marseille'], $result['warehouse']);
        self::assertSame(0, Product::$constructed, 'no entity is created');

        $list = $arrays->hydrateAll($this->metadata, [self::row(), self::row(['id' => 8, 'name' => 'Chair'])]);
        self::assertNull($list[0]['warehouse'], 'RG-02.5');
        self::assertSame(['Desk', 'Chair'], array_column($list, 'name'));
    }

    // phase 4 : une relation ToOne devient l'identifiant de sa cible
    public function testArrayHydratorGivesTheIdentifierOfARelation(): void
    {
        $factory = new \Prism\Orm\Metadata\MetadataFactory(new AttributeDriver());
        $order = $factory->getMetadataFor(\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\PurchaseOrder::class);
        $customer = $factory->getMetadataFor(\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Customer::class);

        self::assertSame(['id' => 10, 'reference' => 'PO-10', 'customer' => 3], new ArrayHydrator($this->objects, $factory)->hydrate($order, ['id' => '10', 'reference' => 'PO-10', 'customer_id' => '3']));
        self::assertSame(['id' => 3, 'name' => 'Ada', 'lastOrder' => null, 'referrer' => null], new ArrayHydrator($this->objects, $factory)->hydrate($customer, ['id' => 3, 'name' => 'Ada', 'last_order_id' => null, 'referrer_id' => null]));
        self::assertSame('3', new ArrayHydrator($this->objects)->hydrate($order, ['id' => 10, 'reference' => 'PO-10', 'customer_id' => '3'])['customer'], 'without metadata factory: the database value');
    }
}
