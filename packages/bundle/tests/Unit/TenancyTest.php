<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Debug\TenantDataCollector;
use Prism\Bundle\Exception\InvalidConfigurationException;
use Prism\Bundle\Tenancy\TenantResolver;
use Prism\Bundle\Tests\Fixtures\Tenancy\ArchivedFilter;
use Prism\Bundle\Tests\Fixtures\Tenancy\Note;
use Prism\Bundle\Tests\Fixtures\Tenancy\NoteController;
use Prism\Bundle\Tests\Fixtures\Tenancy\SlugLoader;
use Prism\Bundle\Tests\Fixtures\Tenancy\TenantUser;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Dbal\Connection;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\UnfilteredTenantQueryException;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Multi-tenancy dans le bundle (phase 8, étape 7, ADR 0014) : configuration prism.tenancy,
 * contexte partagé et oublié à kernel.reset, résolution par requête (RG-08.5), garde du SQL brut
 * selon l'environnement (RG-08.7), filtres tagués prism.filter, commandes db:* en mode système,
 * panneau Tenant du profiler. SQLite en mémoire.
 */
final class TenancyTest extends TestCase
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

    /**
     * @param array<string, mixed> $tenancy
     */
    private function kernel(array $tenancy = [], bool $debug = true, bool $profiler = false, string $environment = 'test'): TestKernel
    {
        $kernel = new TestKernel(
            ['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Tenancy'], 'tenancy' => $tenancy],
            $environment,
            $debug,
            static function (ContainerConfigurator $c): void {
                $services = $c->services()->defaults()->autowire()->autoconfigure();
                $services->set(NoteController::class)->tag('controller.service_arguments')->public();
                $services->set(ArchivedFilter::class);
                $services->set(SlugLoader::class);
                $services->set('logger', TestLogger::class)->public();
            },
            static function (RoutingConfigurator $routes): void {
                $routes->add('notes', '/notes')->controller([NoteController::class, 'list']);
            },
            profiler: $profiler,
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

    private static function context(TestKernel $kernel): StackTenantContext
    {
        $context = self::container($kernel)->get(TenantContext::class);
        self::assertInstanceOf(StackTenantContext::class, $context);

        return $context;
    }

    // notes « a », « b » et « archived » du tenant 1, « c » du tenant 2 ; schéma créé par la commande, en mode système
    private static function seed(TestKernel $kernel): void
    {
        self::assertSame(Command::SUCCESS, new CommandTester(new Application($kernel)->find('db:schema:create'))->execute([]));
        $em = self::container($kernel)->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManager::class, $em);
        $context = self::context($kernel);
        foreach ([1 => ['a', 'b', 'archived'], 2 => ['c']] as $tenant => $titles) {
            $context->runAs($tenant, static function () use ($em, $titles): void {
                foreach ($titles as $title) {
                    $em->persist(new Note($title));
                }
                $em->flush();
            });
        }
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(TestKernel $kernel, string $uri, array $server = []): Response
    {
        $request = Request::create($uri, server: $server);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    // RG-08.5 : l'en-tête de chaque requête ; sans lui, aucun tenant (RG-08.4) ; kernel.reset l'oublie
    public function testHeaderResolvesTheTenantOfEachRequest(): void
    {
        $kernel = $this->kernel(['resolver' => ['header' => 'X-Tenant-Id']]);
        self::seed($kernel);

        self::assertSame('a,b / 3', self::request($kernel, '/notes', ['HTTP_X_TENANT_ID' => '1'])->getContent(), 'the filter hides "archived"; system mode sees every tenant');
        self::assertSame('c / 3', self::request($kernel, '/notes', ['HTTP_X_TENANT_ID' => '2'])->getContent());
        self::assertSame('no tenant', self::request($kernel, '/notes')->getContent(), 'the tenant of the previous request is forgotten');
        self::assertSame('no tenant', self::request($kernel, '/notes', ['HTTP_X_TENANT_ID' => 'acme'])->getContent(), 'an integer tenant id');
    }

    // le sous-domaine, traduit par le TenantLoader
    public function testSubdomainThroughALoader(): void
    {
        $kernel = $this->kernel(['resolver' => ['subdomain' => 'example.com'], 'loader' => SlugLoader::class]);
        self::seed($kernel);

        self::assertSame('a,b / 3', self::request($kernel, 'http://acme.example.com/notes')->getContent());
        self::assertSame('c / 3', self::request($kernel, 'http://www.globex.example.com/notes')->getContent());
        self::assertSame('no tenant', self::request($kernel, 'http://initech.example.com/notes')->getContent());
        self::assertSame('no tenant', self::request($kernel, 'http://example.com/notes')->getContent());
    }

    // l'utilisateur connecté, après le firewall
    public function testUserResolver(): void
    {
        $context = new StackTenantContext();
        $tokens = new TokenStorage();
        $resolver = new TenantResolver($context, TenantResolver::USER, '', 'integer', tokens: $tokens);
        $kernel = self::createStub(HttpKernelInterface::class);

        $resolver->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($context->getTenantId(), 'anonymous');

        $tokens->setToken(new UsernamePasswordToken(new TenantUser(2), 'main'));
        $resolver->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        self::assertNull($context->getTenantId(), 'a sub-request keeps the tenant of the main one');
        $resolver->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(2, $context->getTenantId());
        self::assertSame('2', $resolver->getIdentifier());
    }

    // avant le firewall pour l'en-tête et le sous-domaine, après lui pour l'utilisateur
    public function testResolverPriorities(): void
    {
        $header = new TestKernel(['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'tenancy' => ['resolver' => ['header' => 'X-Tenant']]]);
        $user = new TestKernel(['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'tenancy' => ['resolver' => ['user' => true]]], services: static function (ContainerConfigurator $c): void {
            $c->extension('security', ['firewalls' => ['main' => ['security' => false]]]);
        }, extraBundles: [new SecurityBundle()]);
        $this->kernels = [...$this->kernels, $header, $user];

        foreach ([[$header, 64], [$user, 7]] as [$kernel, $priority]) {
            $dispatcher = self::container($kernel)->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $listener = array_values(array_filter($dispatcher->getListeners('kernel.request'), static fn (mixed $l): bool => \is_array($l) && $l[0] instanceof TenantResolver))[0] ?? null;
            self::assertNotNull($listener);
            self::assertSame($priority, $dispatcher->getListenerPriority('kernel.request', $listener));
        }
    }

    public function testUserResolverNeedsSecurityBundle(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The "user" tenant resolver reads the logged-in user: it needs SecurityBundle.');

        self::container($this->kernel(['resolver' => ['user' => true]]));
    }

    public function testOneResolverOnly(): void
    {
        $this->expectExceptionMessage('Choose one tenant resolver: header, subdomain or user.');

        self::container($this->kernel(['resolver' => ['header' => 'X-Tenant', 'subdomain' => 'example.com']]));
    }

    // RG-08.1 : le type de la colonne du tenant, pour toutes les entités
    public function testTenantType(): void
    {
        $em = self::container($this->kernel(['type' => 'uuid']))->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManager::class, $em);

        self::assertSame('uuid', $em->getMetadataFactory()->getMetadataFor(Note::class)->tenancy?->type);
    }

    // RG-08.7 : exception en debug, log critique sinon, sauf choix explicite
    public function testGuardModeFollowsDebug(): void
    {
        $guarded = static function (TestKernel $kernel): ?string {
            self::seed($kernel);
            $connection = self::container($kernel)->get(Connection::class);
            self::assertInstanceOf(Connection::class, $connection);
            try {
                $connection->executeQuery('SELECT title FROM tenant_note')->fetchAllAssociative();
            } catch (UnfilteredTenantQueryException) {
                return 'exception';
            }
            $logger = self::container($kernel)->get('logger');
            self::assertInstanceOf(TestLogger::class, $logger);

            return \in_array('critical', array_column($logger->records, 'level'), true) ? 'log' : null;
        };

        self::assertSame('exception', $guarded($this->kernel()));
        self::assertSame('log', $guarded($this->kernel(debug: false, environment: 'prod')));
        self::assertSame('exception', $guarded($this->kernel(['guard' => 'exception'], debug: false, environment: 'prod')));
    }

    // ADR 0014 : les commandes db:* administrent toute la base, en mode système
    public function testCommandsRunInSystemMode(): void
    {
        $kernel = $this->kernel();
        self::seed($kernel);
        self::context($kernel)->setTenantId(null);

        $tester = new CommandTester(new Application($kernel)->find('db:query:sql'));
        self::assertSame(Command::SUCCESS, $tester->execute(['sql' => 'SELECT COUNT(*) AS notes FROM tenant_note']));
        self::assertStringContainsString('4', $tester->getDisplay(), 'every tenant, without the guard');
    }

    // kernel.reset oublie le tenant : rien ne passe d'une requête à la suivante en mode worker
    public function testKernelResetForgetsTheTenant(): void
    {
        $kernel = $this->kernel();
        $context = self::context($kernel);
        $context->setTenantId(1);

        $resetter = self::container($kernel)->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertNull($context->getTenantId());
    }

    // le panneau Tenant : tenant, résolution et passages en mode système
    public function testTenantPanel(): void
    {
        $kernel = $this->kernel(['resolver' => ['header' => 'X-Tenant-Id']], profiler: true, environment: 'dev');
        self::seed($kernel);
        $resetter = self::container($kernel)->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $response = self::request($kernel, '/notes', ['HTTP_X_TENANT_ID' => '1']);
        $profiler = self::container($kernel)->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $collector = $profiler->loadProfileFromResponse($response)?->getCollector('prism_tenant');
        self::assertInstanceOf(TenantDataCollector::class, $collector);

        self::assertSame(['tenant 1', 'header', '1'], [$collector->getTenant(), $collector->getStrategy(), $collector->getIdentifier()]);
        self::assertSame([
            ['from' => 'no tenant', 'to' => 'tenant 1', 'leaving' => false],
            ['from' => 'tenant 1', 'to' => 'system mode', 'leaving' => false],
            ['from' => 'system mode', 'to' => 'tenant 1', 'leaving' => true],
        ], $collector->getSwitches());

        $panel = self::request($kernel, '/_profiler/' . $response->headers->get('X-Debug-Token') . '?panel=prism_tenant');
        self::assertSame(200, $panel->getStatusCode());
        self::assertStringContainsString('system mode', (string) $panel->getContent());
    }

    // en prod, ni enregistreur ni panneau
    public function testNoTenantPanelInProd(): void
    {
        $container = self::container($this->kernel(debug: false, environment: 'prod'));

        self::assertFalse($container->has('prism.debug.tenant'));
        self::assertFalse($container->has('prism.tenant_data_collector'));
    }
}
