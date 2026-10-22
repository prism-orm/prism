<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Debug\PrismDataCollector;
use Prism\Bundle\Tests\Fixtures\Controller\ProductController;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Category;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Product;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Orm\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Le panneau Prism du profiler (RG-07.4) : requêtes, N+1 avec leur origine (RG-04.15),
 * chargements par lot, flushs et changesets ; rien de tout cela en prod (RG-04.16).
 */
final class ProfilerTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            $kernel->removeDirectory();
        }
    }

    private function kernel(string $environment = 'dev', bool $debug = true): TestKernel
    {
        $kernel = new TestKernel(
            ['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Shop']],
            $environment,
            $debug,
            static function (ContainerConfigurator $c): void {
                $c->services()->set(ProductController::class)->autowire()->tag('controller.service_arguments')->public();
            },
            static function (RoutingConfigurator $routes): void {
                $routes->add('report', '/report')->controller([ProductController::class, 'report']);
            },
            profiler: $debug,
        );
        $this->kernels[] = $kernel;

        return $kernel;
    }

    private static function container(TestKernel $kernel): ContainerInterface
    {
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    // six produits dans deux catégories
    private static function seed(TestKernel $kernel): void
    {
        new CommandTester(new Application($kernel)->find('db:schema:create'))->execute([]);
        $em = self::container($kernel)->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $categories = [new Category(), new Category()];
        $categories[0]->name = 'tools';
        $categories[1]->name = 'toys';
        for ($i = 1; $i <= 6; ++$i) {
            $product = new Product();
            $product->ref = 'P' . $i;
            $product->category = $categories[$i % 2];
            $em->persist($categories[$i % 2]);
            $em->persist($product);
        }
        $em->flush();
        // comme entre deux requêtes : l'enregistrement repart de zéro (kernel.reset)
        $resetter = self::container($kernel)->get('services_resetter');
        \assert($resetter instanceof \Symfony\Contracts\Service\ResetInterface);
        $resetter->reset();
    }

    // comme un front controller : le profil n'est enregistré qu'au kernel.terminate
    private static function request(TestKernel $kernel, string $path): Response
    {
        $request = Request::create($path);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private static function collector(TestKernel $kernel, Response $response): PrismDataCollector
    {
        $profiler = self::container($kernel)->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $collector = $profiler->loadProfileFromResponse($response)?->getCollector('prism');
        self::assertInstanceOf(PrismDataCollector::class, $collector);

        return $collector;
    }

    public function testPanelReportsQueriesNPlusOneBatchesAndFlushes(): void
    {
        $kernel = $this->kernel();
        self::seed($kernel);

        $response = self::request($kernel, '/report');
        self::assertSame(200, $response->getStatusCode());
        $collector = self::collector($kernel, $response);

        $connection = $collector->getConnections()['default'];
        // 6 find(), la liste, les catégories en un lot, puis la mise à jour du flush
        self::assertSame(9, $connection['count']);
        self::assertCount(9, $connection['queries']);
        self::assertGreaterThan(0.0, $collector->getDurationMs());

        // RG-04.15 : le N+1 et son origine, dans le code de l'application
        self::assertCount(1, $connection['nPlusOne']);
        self::assertSame(6, $connection['nPlusOne'][0]['count']);
        self::assertStringContainsString('ProductController.php:', $connection['nPlusOne'][0]['origin']);

        $em = $collector->getEntityManagers()['default'];
        self::assertSame([['class' => Category::class, 'association' => null, 'count' => 2]], $em['batches']);
        self::assertCount(1, $em['flushes']);
        self::assertSame('update', $em['flushes'][0][0]['action']);
        self::assertSame([Product::class, '1', ['stock' => ['0', '7']]], [$em['flushes'][0][0]['class'], $em['flushes'][0][0]['id'], $em['flushes'][0][0]['changes']]);
    }

    public function testPanelIsRendered(): void
    {
        $kernel = $this->kernel();
        self::seed($kernel);
        $token = self::request($kernel, '/report')->headers->get('X-Debug-Token');
        self::assertNotNull($token);

        $panel = self::request($kernel, '/_profiler/' . $token . '?panel=prism');

        self::assertSame(200, $panel->getStatusCode());
        $html = (string) $panel->getContent();
        self::assertStringContainsString('N+1 detected', $html);
        self::assertStringContainsString('ProductController.php:', $html);
        self::assertStringContainsString(Category::class, $html);
        self::assertStringContainsString('1 instead of 2', $html);
        self::assertStringContainsString('<code>stock</code>: 0 → 7', $html);
    }

    // RG-07.5 : chaque requête repart d'un enregistrement vide
    public function testEachRequestStartsAnEmptyRecording(): void
    {
        $kernel = $this->kernel();
        self::seed($kernel);
        self::request($kernel, '/report');

        $collector = self::collector($kernel, self::request($kernel, '/report'));

        self::assertSame(9, $collector->getConnections()['default']['count'], 'the queries of the first request are gone');
        self::assertCount(1, $collector->getEntityManagers()['default']['flushes']);
    }

    // RG-04.16 et RG-07.4 : en prod, ni collector, ni enregistreur, ni détecteur N+1
    public function testNothingOfItInProd(): void
    {
        $dev = self::container($this->kernel());
        self::assertTrue($dev->has('prism.debug.n1_detector.default'), 'the probe sees it in dev');

        $kernel = $this->kernel('prod', false);
        $prod = self::container($kernel);
        foreach (['prism.debug.n1_detector.default', 'prism.debug.queries.default', 'prism.debug.flushes.default', 'prism.data_collector'] as $id) {
            self::assertFalse($prod->has($id), $id);
        }
        $source = implode('', array_map(static fn (string $f): string => (string) file_get_contents($f), array_filter((array) glob($kernel->getCacheDir() . '/Container*/*.php'), is_string(...))));
        self::assertNotSame('', $source);
        self::assertStringNotContainsString('N1Detector', $source, 'absent from the compiled container, not only disabled');
        self::assertStringNotContainsString('QueryRecorder', $source);
    }
}
