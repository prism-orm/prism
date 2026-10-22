<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Dbal\Connection;
use Prism\Dbal\Tests\Support\TestDatabase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * RG-07.5 sur base réelle : dans un worker, une connexion tuée par le serveur entre deux
 * requêtes est fermée au début de la suivante, puis se reconnecte à sa première requête SQL.
 */
final class WorkerTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $url = TestDatabase::url();
        if (null === $url) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        if (str_starts_with($url, 'sqlite')) {
            self::markTestSkipped('An SQLite connection cannot be lost.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            $kernel->removeDirectory();
        }
    }

    /**
     * Un worker : un kernel démarré une fois, qui sert des requêtes successives.
     *
     * @return array{TestKernel, Connection}
     */
    private function worker(int $idleTtl): array
    {
        $kernel = new TestKernel(['url' => (string) TestDatabase::url(), 'connection_idle_ttl' => $idleTtl, 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Shop']]);
        $this->kernels[] = $kernel;
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);

        return [$kernel, $connection];
    }

    private static function request(TestKernel $kernel): void
    {
        $request = Request::create('/');
        $kernel->terminate($request, $kernel->handle($request));
    }

    // la connexion est tuée côté serveur (redémarrage, wait_timeout dépassé)
    private static function kill(Connection $victim): void
    {
        $postgres = 'PostgreSQL' === $victim->getPlatform()->getName();
        $id = $victim->executeQuery($postgres ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchOne();
        self::assertTrue(is_numeric($id));
        $killer = TestDatabase::connection();
        $postgres
            ? $killer->executeQuery('SELECT pg_terminate_backend(:pid)', ['pid' => (int) $id])
            : $killer->executeStatement(\sprintf('KILL %d', (int) $id));
        $killer->close();
        usleep(100_000);
    }

    public function testLostConnectionIsReopenedAtTheNextRequest(): void
    {
        [$kernel, $connection] = $this->worker(0);
        self::request($kernel);
        self::kill($connection);
        self::assertTrue($connection->isConnected(), 'PHP does not know yet');

        self::request($kernel);

        self::assertFalse($connection->isConnected(), 'closed at kernel.request');
        self::assertEquals(1, $connection->executeQuery('SELECT 1')->fetchOne(), 'reconnected by the first query');
    }

    public function testNoCheckBeforeTheIdleDelay(): void
    {
        [$kernel, $connection] = $this->worker(3600);
        self::request($kernel);
        $connection->connect();

        self::request($kernel);

        self::assertTrue($connection->isConnected(), 'not idle long enough: no ping, no extra query');
    }
}
