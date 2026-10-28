<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Tests\Fixtures\Entity\Billing\Invoice;
use Prism\Bundle\Tests\Fixtures\Entity\Main\Customer;
use Prism\Bundle\Tests\Support\AutowiredServices;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Dbal\Connection;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Le bundle dans une application (ADR 0013) : services, autowiring par défaut et nommé
 * (RG-07.1), cache des metadata en prod seulement, kernel.reset (RG-07.5), commandes db:* et --em.
 * SQLite en mémoire : aucune base de test n'est nécessaire.
 */
final class PrismBundleTest extends TestCase
{
    private const string MEMORY = 'sqlite:///:memory:?serverVersion=3.46.0';

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
     * @param array<mixed> $prism
     */
    private function boot(array $prism, string $environment = 'test', bool $debug = true, bool $autowired = false): ContainerInterface
    {
        $kernel = new TestKernel($prism, $environment, $debug, $autowired ? static function (ContainerConfigurator $c): void {
            $c->services()->set(AutowiredServices::class)->autowire()->public();
        } : null);
        $this->kernels[] = $kernel;
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @return array<mixed>
     */
    private static function twoEntityManagers(): array
    {
        return [
            'connections' => ['default' => ['url' => self::MEMORY], 'billing' => ['url' => self::MEMORY]],
            'entity_managers' => [
                'default' => ['entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Main']],
                'billing' => ['entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Billing']],
            ],
        ];
    }

    private static function entityManager(ContainerInterface $container, string $id = EntityManagerInterface::class): EntityManager
    {
        $em = $container->get($id);
        self::assertInstanceOf(EntityManager::class, $em);

        return $em;
    }

    /**
     * @return list<class-string>
     */
    private static function mapped(EntityManager $em): array
    {
        return array_map(static fn ($m): string => $m->className, $em->getMetadataFactory()->getAllMetadata());
    }

    public function testShortFormGivesTheDefaultServices(): void
    {
        $container = $this->boot(['url' => self::MEMORY, 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Main']]);

        $em = self::entityManager($container);
        self::assertSame($container->get(Connection::class), $em->getConnection());
        self::assertSame($em, $container->get('prism.entity_manager.default'));
        self::assertSame([Customer::class], self::mapped($em));
    }

    // RG-07.1
    public function testNamedEntityManagersAreAutowiredByArgumentName(): void
    {
        $container = $this->boot(self::twoEntityManagers(), autowired: true);

        $services = $container->get(AutowiredServices::class);
        self::assertInstanceOf(AutowiredServices::class, $services);
        self::assertSame($services->entityManager, $services->defaultEntityManager, 'the first declared one is the default');
        self::assertNotSame($services->entityManager, $services->billingEntityManager);
        self::assertSame($services->connection, $services->entityManager->getConnection());
        self::assertSame($services->billingConnection, $services->billingEntityManager->getConnection());
        self::assertNotSame($services->connection, $services->billingConnection);
        self::assertInstanceOf(EntityManager::class, $services->billingEntityManager);
        self::assertSame([Invoice::class], self::mapped($services->billingEntityManager));
    }

    public function testServicesArePrivate(): void
    {
        $kernel = new TestKernel(['url' => self::MEMORY]);
        $this->kernels[] = $kernel;
        $kernel->boot();

        self::assertFalse($kernel->getContainer()->has('prism.entity_manager.default'));
        self::assertFalse($kernel->getContainer()->has(EntityManagerInterface::class));
    }

    // étape 8 : composer require puis cache:clear, avant que DATABASE_URL soit définie (sans recette)
    public function testKernelBootsBeforeTheUrlIsDefined(): void
    {
        $container = $this->boot(['url' => '%env(resolve:PRISM_UNDEFINED_URL)%', 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Main']], 'dev', true);
        $metadata = $container->get('prism.metadata_factory.default');
        self::assertInstanceOf(\Prism\Orm\Metadata\MetadataFactory::class, $metadata);
        self::assertSame([Customer::class], $metadata->getAllClassNames(), 'the metadata (and the metamodel at boot) need no connection');

        // la variable n'est lue qu'au premier usage de la connexion
        $this->expectException(\Symfony\Component\DependencyInjection\Exception\EnvNotFoundException::class);
        $container->get(EntityManagerInterface::class);
    }

    // phase 2 : relecture des attributs en dev, cache PSR-6 en prod
    public function testMetadataCacheInProdOnly(): void
    {
        $dev = self::entityManager($this->boot(['url' => self::MEMORY], 'dev', true));
        $prod = self::entityManager($this->boot(['url' => self::MEMORY], 'prod', false));

        self::assertFalse($dev->getMetadataFactory()->hasCache());
        self::assertTrue($prod->getMetadataFactory()->hasCache());
    }

    // RG-07.5 : en mode worker, kernel.reset vide l'EntityManager entre deux requêtes
    public function testKernelResetClearsEveryEntityManager(): void
    {
        $container = $this->boot(self::twoEntityManagers());
        $default = self::entityManager($container);
        $billing = self::entityManager($container, 'prism.entity_manager.billing');
        $customer = new Customer();
        $invoice = new Invoice();
        $default->persist($customer);
        $billing->persist($invoice);

        $resetter = $container->get('services_resetter');
        \assert($resetter instanceof \Symfony\Contracts\Service\ResetInterface);
        $resetter->reset();

        self::assertFalse($default->contains($customer));
        self::assertFalse($billing->contains($invoice));
    }

    public function testDbCommandsAreInTheConsoleWithEm(): void
    {
        $kernel = new TestKernel(self::twoEntityManagers());
        $this->kernels[] = $kernel;
        $application = new Application($kernel);

        $names = array_keys($application->all('db'));
        sort($names);
        self::assertCount(21, $names);
        self::assertContains('db:migrations:migrate', $names);

        $command = $application->find('db:mapping:info');
        self::assertTrue($command->getDefinition()->hasOption('em'));
        self::assertSame('default', $command->getDefinition()->getOption('em')->getDefault());
        self::assertSame('List the mapped entities', $command->getDescription());

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString(Customer::class, $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--em' => 'billing']));
        self::assertStringContainsString(Invoice::class, $tester->getDisplay());
        self::assertStringNotContainsString(Customer::class, $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['--em' => 'archive']));
        self::assertStringContainsString('Unknown entity manager "archive"; declared: default, billing.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testArgumentsAndOptionsReachTheCommand(): void
    {
        $kernel = new TestKernel(self::twoEntityManagers());
        $this->kernels[] = $kernel;
        $tester = new CommandTester(new Application($kernel)->find('db:query:sql'));

        self::assertSame(Command::SUCCESS, $tester->execute(['sql' => 'SELECT :a AS answer', '--param' => ['a=42'], '--em' => 'billing']));
        self::assertStringContainsString('42', $tester->getDisplay());
        // une écriture sans --force est refusée par la commande d'origine
        self::assertSame(Command::INVALID, $tester->execute(['sql' => 'DELETE FROM x']));
    }

    // ADR 0013 : chaque base a ses migrations, jamais un répertoire partagé
    public function testEachEntityManagerHasItsMigrations(): void
    {
        $kernel = new TestKernel(self::twoEntityManagers());
        $this->kernels[] = $kernel;
        $tester = new CommandTester(new Application($kernel)->find('db:migrations:status'));
        $display = static fn (): string => (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Directory ' . \dirname(__DIR__) . '/migrations ', $display());
        self::assertStringContainsString('Namespace App\Migrations ', $display());
        self::assertSame(Command::SUCCESS, $tester->execute(['--em' => 'billing']));
        self::assertStringContainsString('Directory ' . \dirname(__DIR__) . '/migrations/Billing ', $display());
        self::assertStringContainsString('Namespace App\Migrations\Billing ', $display());
    }
}
