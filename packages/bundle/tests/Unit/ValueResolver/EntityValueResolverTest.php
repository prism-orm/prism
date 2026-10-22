<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit\ValueResolver;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Attribute\MapEntity;
use Prism\Bundle\Exception\MapEntityException;
use Prism\Bundle\Tests\Fixtures\Entity\Billing\Invoice;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Coupon;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Product;
use Prism\Bundle\ValueResolver\EntityValueResolver;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RG-07.3 : un paramètre de route converti en entité ; 404 si elle n'existe pas, null pour un
 * argument nullable ; identifiant par nom d'argument, {id}, id: ou mapping. SQLite en mémoire.
 */
final class EntityValueResolverTest extends TestCase
{
    private EntityManager $shop;

    private EntityManager $billing;

    private EntityValueResolver $resolver;

    protected function setUp(): void
    {
        $this->shop = self::entityManager('Shop');
        $this->billing = self::entityManager('Billing');
        $this->resolver = new EntityValueResolver(new ServiceLocator(['shop' => fn (): EntityManager => $this->shop, 'billing' => fn (): EntityManager => $this->billing]), ['shop', 'billing']);
    }

    private static function entityManager(string $dir): EntityManager
    {
        $connection = DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0');
        $metadata = new MetadataFactory(new AttributeDriver([\dirname(__DIR__, 2) . '/Fixtures/Entity/' . $dir]), types: $connection->getTypeRegistry());
        $schema = new SchemaTool($connection->getPlatform(), $connection->getTypeRegistry())->fromMetadata($metadata->getAllMetadata());
        foreach ($connection->getPlatform()->getCreateSchemaSql($schema) as $sql) {
            $connection->executeStatement($sql);
        }

        return new EntityManager($connection, $metadata);
    }

    private function product(string $ref): Product
    {
        $product = new Product();
        $product->ref = $ref;
        $this->shop->persist($product);
        $this->shop->flush();

        return $product;
    }

    /**
     * @param array<string, mixed> $attributes paramètres de route
     *
     * @return list<mixed>
     */
    private function resolve(array $attributes, string $name, ?string $type, bool $nullable = false, ?MapEntity $options = null): array
    {
        $argument = new ArgumentMetadata($name, $type, false, false, null, $nullable, null === $options ? [] : [$options]);

        return array_values([...$this->resolver->resolve(new Request(attributes: $attributes), $argument)]);
    }

    public function testRouteParameterNamedLikeTheArgument(): void
    {
        $product = $this->product('A-1');

        self::assertSame([$product], $this->resolve(['product' => (string) $product->id], 'product', Product::class));
    }

    public function testIdRouteParameter(): void
    {
        $product = $this->product('A-1');

        self::assertSame([$product], $this->resolve(['id' => (string) $product->id], 'product', Product::class));
    }

    public function testExplicitIdParameter(): void
    {
        $product = $this->product('A-1');

        self::assertSame([$product], $this->resolve(['productId' => (string) $product->id, 'id' => '999'], 'product', Product::class, options: new MapEntity(id: 'productId')));
    }

    public function testMappingSearchesByFields(): void
    {
        $product = $this->product('A-1');

        self::assertSame([$product], $this->resolve(['ref' => 'A-1'], 'product', Product::class, options: new MapEntity(mapping: ['ref' => 'ref'])));
    }

    // RG-07.3
    public function testMissingEntityIsANotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage(Product::class . ' not found (id = 42).');

        $this->resolve(['id' => '42'], 'product', Product::class);
    }

    // RG-07.3
    public function testMissingEntityIsNullForANullableArgument(): void
    {
        self::assertSame([null], $this->resolve(['id' => '42'], 'product', Product::class, nullable: true));
        self::assertSame([null], $this->resolve(['ref' => 'nope'], 'product', Product::class, nullable: true, options: new MapEntity(mapping: ['ref' => 'ref'])));
    }

    // un identifiant qui ne peut pas en être un : 404, jamais une erreur 500
    public function testIdThatCannotBeOneIsANotFound(): void
    {
        foreach (['abc', '1.5', '', '99999999999999999999'] as $id) {
            try {
                $this->resolve(['id' => $id], 'product', Product::class);
                self::fail('Expected a 404 for ' . $id);
            } catch (NotFoundHttpException) {
            }
        }
        self::assertSame([null], $this->resolve(['id' => 'not-a-uuid'], 'coupon', Coupon::class, nullable: true), 'the uuid Type refuses it');
    }

    public function testEntityManagerIsTheOneMappingTheClass(): void
    {
        $invoice = new Invoice();
        $this->billing->persist($invoice);
        $this->billing->flush();

        self::assertSame([$invoice], $this->resolve(['id' => (string) $invoice->id], 'invoice', Invoice::class));
        self::assertSame([$invoice], $this->resolve(['id' => (string) $invoice->id], 'invoice', Invoice::class, options: new MapEntity(entityManager: 'billing')));
    }

    public function testWhatIsNotAnEntityArgumentIsLeftToOtherResolvers(): void
    {
        self::assertSame([], $this->resolve(['id' => '1'], 'request', Request::class));
        self::assertSame([], $this->resolve(['id' => '1'], 'id', 'int'));
        self::assertSame([], $this->resolve(['id' => '1'], 'product', null));
        self::assertSame([], $this->resolve(['other' => '1'], 'product', Product::class), 'no identifier in the route');
        self::assertSame([], $this->resolve(['id' => '1'], 'product', Product::class, options: new MapEntity(disabled: true)));
    }

    public function testMisuseIsAProgrammingError(): void
    {
        $cases = [
            [new MapEntity(entityManager: 'archive'), 'names an entity manager that is not declared; declared: shop, billing.'],
            [new MapEntity(entityManager: 'billing'), 'names an entity manager that does not map ' . Product::class . '.'],
            [new MapEntity(id: 'productId'), '#[MapEntity(id: "productId")] of the argument $product names a route parameter that the route does not have.'],
            [new MapEntity(mapping: ['code' => 'ref']), 'maps the route parameter "code", which the route does not have.'],
            [new MapEntity(mapping: ['id' => 'price']), 'maps "id" to ' . Product::class . '::$price, which is not a mapped field.'],
        ];
        foreach ($cases as [$options, $message]) {
            try {
                $this->resolve(['id' => '1'], 'product', Product::class, options: $options);
                self::fail('Expected MapEntityException: ' . $message);
            } catch (MapEntityException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
