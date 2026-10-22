<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Exception\InvalidConfigurationException;
use Prism\Bundle\Metamodel\MetamodelRefresher;
use Prism\Bundle\Tests\Fixtures\Entity\Billing\Invoice;
use Prism\Bundle\Tests\Fixtures\Entity\Main\Customer;
use Prism\Bundle\Tests\Fixtures\Invalid\UnknownEntityManagerListener;
use Prism\Bundle\Tests\Fixtures\Invalid\UntypedListener;
use Prism\Bundle\Tests\Fixtures\Listener\Journal;
use Prism\Bundle\Tests\Fixtures\Repository\CustomerRepository;
use Prism\Bundle\Tests\Fixtures\Type\MoneyType;
use Prism\Bundle\Tests\Support\AutowiredServices;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Étape 2 de la phase 7 (ADR 0013) : repositories autowirés (RG-07.2), types et listeners par
 * tags, dispatcher par EntityManager, metamodel chargé, généré au warmup et suivi en dev
 * (RG-05.1). SQLite en mémoire.
 */
final class WiringTest extends TestCase
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
     * Les services d'une application : autowire et autoconfigure sur ses dossiers, comme le
     * services.yaml de Symfony (le repository compris).
     *
     * @param list<class-string> $extra
     */
    private function kernel(string $environment = 'test', bool $debug = true, array $extra = []): TestKernel
    {
        $kernel = new TestKernel([
            'connections' => ['default' => ['url' => self::MEMORY], 'billing' => ['url' => self::MEMORY]],
            'entity_managers' => [
                'default' => ['entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Main']],
                'billing' => ['entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Billing']],
            ],
        ], $environment, $debug, static function (ContainerConfigurator $c) use ($extra): void {
            $services = $c->services()->defaults()->autowire()->autoconfigure();
            $services->load('Prism\Bundle\Tests\Fixtures\\', \dirname(__DIR__) . '/Fixtures/{Repository,Type,Listener}');
            foreach ($extra as $class) {
                $services->set($class);
            }
            $services->set(AutowiredServices::class)->public();
            $services->alias('test.customer_repository', CustomerRepository::class)->public();
            $services->alias('test.journal', Journal::class)->public();
        });
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

    private static function entityManager(ContainerInterface $container, string $id = EntityManagerInterface::class): EntityManager
    {
        $em = $container->get($id);
        self::assertInstanceOf(EntityManager::class, $em);

        return $em;
    }

    private static function journal(ContainerInterface $container): Journal
    {
        $journal = $container->get('test.journal');
        self::assertInstanceOf(Journal::class, $journal);

        return $journal;
    }

    private static function createSchemas(TestKernel $kernel): void
    {
        $tester = new CommandTester(new Application($kernel)->find('db:schema:create'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(Command::SUCCESS, $tester->execute(['--em' => 'billing']));
    }

    // RG-07.2 : même instance que getRepository(), y compris quand l'application l'autowire
    public function testRepositoryIsAutowiredAsTheEntityManagerOne(): void
    {
        $container = self::container($this->kernel());

        $repository = $container->get('test.customer_repository');

        self::assertInstanceOf(CustomerRepository::class, $repository);
        self::assertSame(self::entityManager($container)->getRepository(Customer::class), $repository);
    }

    public function testCustomTypesAreRegisteredOnEveryConnection(): void
    {
        $services = self::container($this->kernel())->get(AutowiredServices::class);
        self::assertInstanceOf(AutowiredServices::class, $services);

        foreach ([$services->connection, $services->billingConnection] as $connection) {
            self::assertInstanceOf(MoneyType::class, $connection->getTypeRegistry()->get('money'));
            self::assertTrue($connection->getTypeRegistry()->has('decimal'), 'the native types stay');
        }
    }

    public function testEntityListenerIsAServiceWithItsDependencies(): void
    {
        $kernel = $this->kernel();
        $container = self::container($kernel);
        self::createSchemas($kernel);
        $customer = new Customer();
        $customer->name = 'Ada';

        $em = self::entityManager($container);
        $em->persist($customer);
        $em->flush();

        self::assertContains('pre-persist Ada', self::journal($container)->lines);
    }

    // ADR 0013 : un dispatcher par EntityManager ; entityManager: limite le listener
    public function testPrismListenersFollowTheirEntityManager(): void
    {
        $kernel = $this->kernel();
        $container = self::container($kernel);
        self::createSchemas($kernel);
        $journal = self::journal($container);

        $default = self::entityManager($container);
        $default->persist(new Customer());
        $default->flush();
        self::assertSame(['pre-persist ', 'pre-persist event'], $journal->lines, 'no billing flush on the default entity manager');

        $journal->lines = [];
        $billing = self::entityManager($container, 'prism.entity_manager.billing');
        $billing->persist(new Invoice());
        $billing->flush();
        self::assertSame(['pre-persist event', 'billing flush'], $journal->lines);
    }

    public function testListenerOnAnUnknownEntityManagerIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('listens to the entity manager "archive", which is not declared; declared: default, billing.');

        $this->kernel(extra: [UnknownEntityManagerListener::class])->boot();
    }

    public function testListenerWithoutEventIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('UntypedListener::__invoke()" needs an event');

        $this->kernel(extra: [UntypedListener::class])->boot();
    }

    // RG-05.1 : en dev, généré au démarrage, puis seulement quand une entité est plus récente
    public function testMetamodelFollowsTheEntitiesInDev(): void
    {
        $kernel = $this->kernel('dev', true);
        $kernel->boot();
        $dir = $kernel->getCacheDir() . '/prism/metamodel';
        $customer = $dir . '/default/Prism/Bundle/Tests/Fixtures/Entity/Main/Customer_.php';

        self::assertFileExists($customer);
        self::assertFileExists($dir . '/billing/Prism/Bundle/Tests/Fixtures/Entity/Billing/Invoice_.php', 'one directory per entity manager');
        self::assertTrue(class_exists('Prism\Bundle\Tests\Fixtures\Entity\Main\Customer_'), 'the autoloader is registered');

        // à jour : rien n'est regénéré au démarrage suivant
        unlink($customer);
        $kernel->shutdown();
        $kernel->boot();
        self::assertFileDoesNotExist($customer);

        // une entité plus récente que la dernière génération : regénéré
        touch($dir . '/default/' . MetamodelRefresher::STAMP, time() - 3600, time() - 3600);
        touch(\dirname(__DIR__) . '/Fixtures/Entity/Main/Customer.php');
        $kernel->shutdown();
        $kernel->boot();
        self::assertFileExists($customer);
    }

    // phase 2 et RG-05.1 : en prod, le warmup valide et met en cache les metadata, génère le metamodel
    public function testWarmupFillsTheMetadataCacheAndTheMetamodelInProd(): void
    {
        $kernel = $this->kernel('prod', false);
        $container = self::container($kernel);

        $pool = $container->get('prism.metadata_cache.default');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        // MetadataFactory::CLASS_LIST_KEY
        self::assertTrue($pool->hasItem('prism.metadata.__entities'));
        self::assertFileExists($kernel->getCacheDir() . '/prism/metamodel/billing/Prism/Bundle/Tests/Fixtures/Entity/Billing/Invoice_.php');
    }
}
