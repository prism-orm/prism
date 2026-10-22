<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Functional;

use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\TestPack\Exception\TestPackException;
use Prism\TestPack\QueryCountAssertions;
use Prism\TestPack\ResetDatabase;
use Prism\TestPack\Tests\Fixtures\Controller\AuthorController;
use Prism\TestPack\Tests\Fixtures\Factory\AuthorFactory;
use Prism\TestPack\Tests\Support\TestPackTables;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * RG-07.6 dans un WebTestCase, sur les 4 bases : les requêtes du client voient les données du
 * test (même connexion, même transaction), la transaction survit au kernel.reset entre deux
 * requêtes (RG-07.5 ne l'annule pas), et le test suivant repart d'une base vide.
 */
final class WebResetDatabaseTest extends WebTestCase
{
    use QueryCountAssertions;
    use ResetDatabase;

    /** @var list<TestKernel> */
    private static array $kernels = [];

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $url = TestDatabase::url() ?? self::markTestSkipped('DATABASE_URL is not set.');
        $dir = \dirname(__DIR__) . '/Fixtures/Entity';
        $kernel = new TestKernel(
            [
                'connections' => ['default' => ['url' => $url], 'billing' => ['url' => $url]],
                'entity_managers' => ['default' => ['entity_dirs' => [$dir . '/Library']], 'billing' => ['entity_dirs' => [$dir . '/Billing']]],
            ],
            services: static function (ContainerConfigurator $c): void {
                $c->services()->set(AuthorController::class)->autowire()->tag('controller.service_arguments')->public();
            },
            routes: static function (RoutingConfigurator $routes): void {
                $routes->add('count', '/authors/count')->controller([AuthorController::class, 'count']);
                $routes->add('add', '/authors')->controller([AuthorController::class, 'add'])->methods(['POST']);
            },
        );
        self::$kernels[] = $kernel;

        return $kernel;
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        TestPackTables::drop();
        foreach (self::$kernels as $kernel) {
            $kernel->removeDirectory();
        }
        self::$kernels = [];
    }

    public function testRequestsSeeTheDataOfTheTestAcrossResets(): void
    {
        $client = static::createClient();
        AuthorFactory::new()->many(2);

        $client->request('GET', '/authors/count');
        self::assertSame('2', $client->getResponse()->getContent(), 'the same connection and transaction');

        $client->request('POST', '/authors');
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $client->request('GET', '/authors/count');
        self::assertSame('3', $client->getResponse()->getContent(), 'not rolled back by kernel.reset between two requests');
    }

    public function testNextTestStartsEmpty(): void
    {
        $client = static::createClient();

        $client->request('GET', '/authors/count');

        self::assertSame('0', $client->getResponse()->getContent());
    }

    // le recorder est vidé au début de chaque requête HTTP : le compte traverse les requêtes
    public function testQueriesAreCountedAcrossRequests(): void
    {
        $client = static::createClient();
        $client->request('GET', '/authors/count');

        $this->assertQueryCount(2, static function () use ($client): void {
            $client->request('GET', '/authors/count');
            $client->request('GET', '/authors/count');
        });
    }

    public function testKernelOptionsAreRefused(): void
    {
        $this->expectException(TestPackException::class);
        $this->expectExceptionMessage('With ResetDatabase, the kernel is booted before the test');

        static::createClient(['environment' => 'prod']);
    }
}
