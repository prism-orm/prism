<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit\ValueResolver;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Tests\Fixtures\Controller\ProductController;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Product;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Orm\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * RG-07.3 de bout en bout : une route, un contrôleur, une vraie réponse 404.
 */
final class ControllerTest extends TestCase
{
    private TestKernel $kernel;

    private int $id;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel(
            ['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Shop']],
            services: static function (ContainerConfigurator $c): void {
                $c->services()->set(ProductController::class)->autowire()->tag('controller.service_arguments')->public();
            },
            routes: static function (RoutingConfigurator $routes): void {
                $routes->add('show', '/products/{id}')->controller([ProductController::class, 'show']);
                $routes->add('maybe', '/maybe/{product}')->controller([ProductController::class, 'maybe']);
                $routes->add('by_ref', '/by-ref/{ref}')->controller([ProductController::class, 'byRef']);
            },
        );
        $this->kernel->boot();
        new CommandTester(new Application($this->kernel)->find('db:schema:create'))->execute([]);

        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $product = new Product();
        $product->ref = 'A-1';
        $em->persist($product);
        $em->flush();
        $this->id = (int) $product->id;
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        $this->kernel->removeDirectory();
    }

    /**
     * @return array{int, string}
     */
    private function get(string $path): array
    {
        $response = $this->kernel->handle(Request::create($path));

        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    public function testEntityIsGivenToTheController(): void
    {
        self::assertSame([200, 'A-1'], $this->get('/products/' . $this->id));
        self::assertSame([200, (string) $this->id], $this->get('/by-ref/A-1'));
    }

    // RG-07.3
    public function testMissingEntityIsA404(): void
    {
        self::assertSame(404, $this->get('/products/999')[0]);
        self::assertSame(404, $this->get('/products/abc')[0], 'an id that cannot be one');
        self::assertSame(404, $this->get('/by-ref/B-2')[0]);
    }

    // RG-07.3
    public function testNullableArgumentGetsNull(): void
    {
        self::assertSame([200, 'A-1'], $this->get('/maybe/' . $this->id));
        self::assertSame([200, 'none'], $this->get('/maybe/999'));
    }
}
