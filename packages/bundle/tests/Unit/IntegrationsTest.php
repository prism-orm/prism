<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Category;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Product;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\User;
use Prism\Bundle\Tests\Fixtures\Messenger\Restock;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Bundle\Validator\UniqueEntity;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Étape 5 de la phase 7 : #[UniqueEntity], user provider « prism », middleware Messenger
 * « prism_reset » et fin de requête en mode worker (RG-07.5). SQLite en mémoire.
 */
final class IntegrationsTest extends TestCase
{
    private TestKernel $kernel;

    private ContainerInterface $container;

    private EntityManager $em;

    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel(
            ['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Shop']],
            services: static function (ContainerConfigurator $c): void {
                $c->extension('framework', ['validation' => ['enabled' => true], 'messenger' => ['default_bus' => 'bus', 'buses' => ['bus' => ['middleware' => ['prism_reset']]]]]);
                $c->extension('security', [
                    'providers' => [
                        'users' => ['prism' => ['class' => User::class, 'property' => 'email']],
                        'loader' => ['prism' => ['class' => User::class]],
                    ],
                    'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
                ]);
                $services = $c->services()->defaults()->autowire()->autoconfigure();
                $services->load('Prism\Bundle\Tests\Fixtures\\', \dirname(__DIR__) . '/Fixtures/{Repository,Messenger}');
                $services->set('logger', TestLogger::class)->public();
                foreach (['users', 'loader'] as $provider) {
                    $services->alias('test.provider.' . $provider, 'security.user.provider.concrete.' . $provider)->public();
                }
            },
            extraBundles: [new SecurityBundle()],
        );
        $this->kernel->boot();
        new CommandTester(new Application($this->kernel)->find('db:schema:create'))->execute([]);
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $this->container = $container;
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManager);
        $this->em = $em;
        $logger = $container->get('logger');
        \assert($logger instanceof TestLogger);
        $this->logger = $logger;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        $this->kernel->removeDirectory();
    }

    private function product(string $ref, ?Category $category = null, int $stock = 0): Product
    {
        $product = new Product();
        $product->ref = $ref;
        $product->category = $category;
        $product->stock = $stock;
        if (null !== $category) {
            $this->em->persist($category);
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /**
     * @return list<string> « chemin: message »
     */
    private function violations(object $entity): array
    {
        $validator = $this->container->get('validator');
        \assert($validator instanceof ValidatorInterface);
        $violations = [];
        foreach ($validator->validate($entity) as $violation) {
            self::assertSame(UniqueEntity::NOT_UNIQUE_ERROR, $violation->getCode());
            $violations[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }

        return $violations;
    }

    /**
     * @return UserProviderInterface<User>&PasswordUpgraderInterface
     */
    private function provider(string $name): UserProviderInterface&PasswordUpgraderInterface
    {
        $provider = $this->container->get('test.provider.' . $name);
        self::assertInstanceOf(UserProviderInterface::class, $provider);
        self::assertInstanceOf(PasswordUpgraderInterface::class, $provider);

        return $provider;
    }

    public function testUniqueEntity(): void
    {
        $existing = $this->product('A-1');
        $duplicate = new Product();
        $duplicate->ref = 'A-1';

        self::assertSame(['ref: This value is already used.'], $this->violations($duplicate));
        self::assertSame([], $this->violations($existing), 'the entity itself is not a duplicate');
    }

    public function testUniqueEntityWithARelation(): void
    {
        $tools = new Category();
        $tools->name = 'tools';
        $this->product('A-1', $tools, 5);
        $this->product('A-2', null, 5);

        $same = new Product();
        $same->ref = 'B-1';
        $same->category = $tools;
        $same->stock = 5;
        self::assertSame(['stock: One product per stock level in a category.'], $this->violations($same));

        $same->stock = 6;
        self::assertSame([], $this->violations($same));
        $orphan = new Product();
        $orphan->ref = 'B-2';
        $orphan->stock = 5;
        self::assertSame([], $this->violations($orphan), 'a null category does not count (ignoreNull)');
    }

    public function testUserProviderLoadsRefreshesAndUpgrades(): void
    {
        $user = new User();
        $user->email = 'ada@example.test';
        $user->password = 'old';
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->provider('users')->loadUserByIdentifier('ada@example.test');
        self::assertSame($user->id, $loaded->id);
        self::assertTrue($this->provider('users')->supportsClass(User::class));

        // le repository charge lui-même, sans property (UserLoaderInterface)
        self::assertSame($loaded, $this->provider('loader')->loadUserByIdentifier('ADA@example.test'));

        $this->em->clear();
        $refreshed = $this->provider('users')->refreshUser($loaded);
        self::assertNotSame($loaded, $refreshed, 'reloaded from the database');
        self::assertSame('ada@example.test', $refreshed->email);

        $this->provider('users')->upgradePassword($refreshed, 'new-hash');
        self::assertSame('new-hash', $refreshed->password, 'delegated to the repository');
    }

    public function testUnknownUserIsNotFound(): void
    {
        try {
            $this->provider('users')->loadUserByIdentifier('nobody@example.test');
            self::fail('Expected UserNotFoundException.');
        } catch (UserNotFoundException $e) {
            self::assertSame('nobody@example.test', $e->getUserIdentifier());
        }
    }

    // RG-07.5 : le message suivant ne voit rien du précédent, même si le handler échoue
    public function testMessengerMiddlewareResetsAfterEachMessage(): void
    {
        $bus = $this->container->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);

        $bus->dispatch(new Restock('R-1'));
        self::assertFalse($this->em->getConnection()->isTransactionActive(), 'the open transaction is rolled back');
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledInsertions(), 'the entity manager is cleared');

        try {
            $bus->dispatch(new Restock('R-2', fail: true));
            self::fail('Expected HandlerFailedException.');
        } catch (HandlerFailedException) {
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledInsertions());
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private static function warnings(TestLogger $logger): array
    {
        return array_values(array_filter($logger->records, static fn (array $r): bool => 'warning' === $r['level']));
    }

    // RG-07.5 : aucune transaction ne survit à la fin d'une requête ; c'est journalisé
    public function testOpenTransactionIsRolledBackAtTheEndOfTheRequest(): void
    {
        // une requête comme dans un worker : son kernel.request crée le gardien des connexions
        $request = \Symfony\Component\HttpFoundation\Request::create('/');
        $this->kernel->terminate($request, $this->kernel->handle($request));
        $this->logger->records = [];
        $this->em->getConnection()->beginTransaction();
        $this->em->getConnection()->beginTransaction();

        $resetter = $this->container->get('services_resetter');
        \assert($resetter instanceof ResetInterface);
        $resetter->reset();

        self::assertFalse($this->em->getConnection()->isTransactionActive());
        $warnings = self::warnings($this->logger);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('ended the request with an open transaction', $warnings[0]['message']);
        self::assertSame(['connection' => 'default', 'level' => 2], $warnings[0]['context']);
    }
}
