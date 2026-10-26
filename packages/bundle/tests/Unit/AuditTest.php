<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Audit\ContextActorProvider;
use Prism\Bundle\Audit\RequestStackRequestIdProvider;
use Prism\Bundle\Debug\AuditDataCollector;
use Prism\Bundle\Tests\Fixtures\Audit\Note;
use Prism\Bundle\Tests\Fixtures\Audit\NoteController;
use Prism\Bundle\Tests\Fixtures\Tenancy\TenantUser;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Orm\Audit\AuditReader;
use Prism\Orm\Behavior\ActorProvider;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Audit et comportements dans le bundle (phase 8, étape 10, ADR 0015) : l'auteur (utilisateur,
 * sinon commande), l'identifiant de requête, db:audit:purge, le panneau Audit du profiler.
 */
final class AuditTest extends TestCase
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

    private function kernel(): TestKernel
    {
        $kernel = new TestKernel(
            ['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Audit']],
            'dev',
            true,
            static function (ContainerConfigurator $c): void {
                $c->services()->set(NoteController::class)->autowire()->tag('controller.service_arguments')->public();
            },
            static function (RoutingConfigurator $routes): void {
                $routes->add('note', '/note')->controller([NoteController::class, 'create']);
            },
            profiler: true,
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

    // RG-08.16 : l'utilisateur connecté, sinon la commande, sinon personne
    public function testActorProvider(): void
    {
        $tokens = new TokenStorage();
        $actors = new ContextActorProvider($tokens);
        self::assertNull($actors->getActor());

        $actors->onConsoleCommand(new ConsoleCommandEvent(new Command('app:import'), new ArrayInput([]), new NullOutput()));
        self::assertSame('cli:app:import', $actors->getActor());

        $tokens->setToken(new UsernamePasswordToken(new TenantUser(1), 'main'));
        self::assertSame('user-1', $actors->getActor(), 'the logged-in user first');

        $tokens->setToken(null);
        $actors->reset();
        self::assertNull($actors->getActor());
    }

    // RG-08.10 : l'en-tête du proxy, sinon un identifiant tiré une fois par requête
    public function testRequestIdProvider(): void
    {
        $stack = new RequestStack();
        $requests = new RequestStackRequestIdProvider($stack);
        $outside = $requests->getRequestId();
        self::assertNull($outside, 'outside a request');

        $stack->push(Request::create('/', server: ['HTTP_X_REQUEST_ID' => 'abc']));
        $header = $requests->getRequestId();
        self::assertSame('abc', $header);

        $stack->pop();
        $stack->push(Request::create('/'));
        $generated = $requests->getRequestId();
        $again = $requests->getRequestId();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $generated);
        self::assertSame($generated, $again, 'stable within the request');
    }

    // le journal d'une requête : identifiant de requête et panneau Audit ; la commande de purge est là
    public function testAuditOfARequest(): void
    {
        $kernel = $this->kernel();
        self::assertSame(Command::SUCCESS, new CommandTester(new Application($kernel)->find('db:schema:create'))->execute([]));
        self::assertTrue(new Application($kernel)->has('db:audit:purge'));
        $resetter = self::container($kernel)->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $request = Request::create('/note', server: ['HTTP_X_REQUEST_ID' => 'req-42']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode());

        $em = self::container($kernel)->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManager::class, $em);
        $entries = new AuditReader($em)->history(Note::class, (int) $response->getContent());
        self::assertCount(1, $entries);
        self::assertSame(['insert', 'req-42', null], [$entries[0]->action, $entries[0]->requestId, $entries[0]->actor]);

        $profiler = self::container($kernel)->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $collector = $profiler->loadProfileFromResponse($response)?->getCollector('prism_audit');
        self::assertInstanceOf(AuditDataCollector::class, $collector);
        self::assertSame([['insert', Note::class]], array_map(static fn (array $e): array => [$e['action'], $e['class']], $collector->getEntries()));

        $panel = $kernel->handle(Request::create('/_profiler/' . $response->headers->get('X-Debug-Token') . '?panel=prism_audit'));
        self::assertSame(200, $panel->getStatusCode());
        self::assertStringContainsString('<code>title</code>: NULL → hello', (string) $panel->getContent());
    }

    // en console, l'auteur est la commande : le fournisseur écoute console.command
    public function testCommandIsTheActor(): void
    {
        $container = self::container($this->kernel());
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(new ConsoleCommandEvent(new Command('db:schema:create'), new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);

        $actors = $container->get(ActorProvider::class);
        self::assertInstanceOf(ContextActorProvider::class, $actors);
        self::assertSame('cli:db:schema:create', $actors->getActor());
    }
}
