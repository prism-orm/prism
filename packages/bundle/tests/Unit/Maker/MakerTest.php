<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit\Maker;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Metadata\MetadataValidator;
use Prism\Orm\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\MakerBundle\MakerBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * make:entity et make:migration (RG-07.7), dans un projet temporaire : les fichiers générés sont
 * chargés puis validés par le MetadataValidator de l'ORM (les deux côtés de chaque relation,
 * RG-02.8), et le code écrit à la main est gardé.
 */
final class MakerTest extends TestCase
{
    private string $dir;

    private string $namespace;

    private TestKernel $kernel;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/prism-maker-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/src/Entity', 0o777, true);
        // un espace de noms par test : les classes générées sont chargées dans le processus
        $this->namespace = 'MakerApp' . bin2hex(random_bytes(4));
        self::classLoader()->addPsr4($this->namespace . '\\', $this->dir . '/src/');
        $this->kernel = $this->kernel();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        $this->kernel->removeDirectory();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    private static function classLoader(): ClassLoader
    {
        foreach (spl_autoload_functions() as $loader) {
            if (\is_array($loader) && $loader[0] instanceof ClassLoader) {
                return $loader[0];
            }
        }

        throw new \LogicException('No Composer class loader.');
    }

    /**
     * @param list<Bundle> $extraBundles
     */
    private function kernel(array $extraBundles = []): TestKernel
    {
        $namespace = $this->namespace;

        return new TestKernel(
            ['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'entity_dirs' => ['%kernel.project_dir%/src/Entity'], 'migrations' => ['dir' => '%kernel.project_dir%/migrations', 'namespace' => $namespace . '\Migrations']],
            services: static function (ContainerConfigurator $c) use ($namespace): void {
                $c->extension('maker', ['root_namespace' => $namespace]);
            },
            extraBundles: [new MakerBundle(), ...$extraBundles],
            projectDir: $this->dir,
        );
    }

    /**
     * @param list<string> $inputs les réponses aux questions, dans l'ordre
     */
    private function make(string $name, array $inputs, string $command = 'make:entity'): string
    {
        $tester = new CommandTester(new Application($this->kernel)->find($command));
        $tester->setInputs($inputs);
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => $name]), $tester->getDisplay());

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function file(string $relative): string
    {
        return (string) file_get_contents($this->dir . '/src/' . $relative);
    }

    /**
     * Les entités générées, chargées, puis validées comme le ferait db:schema:validate.
     *
     * @return array<string, \Prism\Orm\Metadata\ClassMetadata<object>>
     */
    private function validated(): array
    {
        foreach (array_filter((array) glob($this->dir . '/src/*/*.php'), is_string(...)) as $file) {
            require_once $file;
        }
        $metadata = new MetadataFactory(new AttributeDriver([$this->dir . '/src/Entity']))->getAllMetadata();
        new MetadataValidator()->assertValid($metadata);
        // le schéma s'en déduit aussi : chaque relation a sa clé étrangère ou sa table pivot
        new SchemaTool(new SqlitePlatform('3.46'), TypeRegistry::withDefaults())->fromMetadata($metadata);

        $byName = [];
        foreach ($metadata as $class) {
            $byName[substr($class->className, (int) strrpos($class->className, '\\') + 1)] = $class;
        }

        return $byName;
    }

    public function testNewEntityWithItsRepositoryAndFields(): void
    {
        $display = $this->make('Customer', [
            'name', 'string', '80', 'no',
            'email', 'string', '180', 'yes',
            'balance', 'decimal', '12', '2', 'no',
            'born', 'date_immutable', 'yes',
            '',
        ]);

        self::assertStringContainsString('Success!', $display);
        self::assertStringContainsString('make:migration', $display);
        $entity = $this->file('Entity/Customer.php');
        self::assertStringContainsString('#[Prism\Entity(repositoryClass: CustomerRepository::class)]', $entity);
        self::assertStringContainsString("#[Prism\\Column(length: 80)]\n    public string \$name;", $entity);
        self::assertStringContainsString("#[Prism\\Column(length: 180)]\n    public ?string \$email = null;", $entity);
        self::assertStringContainsString("#[Prism\\Column(type: 'decimal', precision: 12, scale: 2)]\n    public string \$balance;", $entity);
        self::assertStringContainsString("#[Prism\\Column(type: 'date_immutable')]\n    public ?\\DateTimeImmutable \$born = null;", $entity);
        self::assertStringContainsString('final class CustomerRepository extends EntityRepository', $this->file('Repository/CustomerRepository.php'));

        $customer = $this->validated()['Customer'];
        self::assertSame(['id', 'name', 'email', 'balance', 'born'], array_map(static fn ($f): string => $f->fieldName, $customer->getFields()));
        self::assertTrue($customer->getField('email')->nullable);
    }

    // RG-07.7 : les deux côtés de chaque relation, validés par l'ORM (RG-02.8)
    public function testRelationsWriteBothSides(): void
    {
        $this->make('Tag', ['label', 'string', '40', 'no', '']);
        $this->make('Customer', ['name', 'string', '80', 'no', '']);
        $this->make('Note', ['body', 'text', 'no', '']);
        $this->make('Order', [
            'customer', 'ManyToOne', 'Customer', 'no', 'yes', 'orders',
            'tags', 'ManyToMany', 'Tag', 'yes', 'orders',
            '',
        ]);
        $this->make('Profile', ['customer', 'OneToOne', 'Customer', 'no', 'yes', 'profile', '']);
        $this->make('Customer', ['notes', 'OneToMany', 'Note', 'author', 'yes', '']);

        $metadata = $this->validated();

        $order = $metadata['Order'];
        self::assertSame('orders', $order->getAssociation('customer')->inversedBy);
        self::assertSame('orders', $order->getAssociation('tags')->inversedBy);
        self::assertSame('customer', $metadata['Customer']->getAssociation('orders')->mappedBy);
        self::assertSame('tags', $metadata['Tag']->getAssociation('orders')->mappedBy);
        self::assertSame('customer', $metadata['Customer']->getAssociation('profile')->mappedBy);
        self::assertSame('notes', $metadata['Note']->getAssociation('author')->inversedBy);
        self::assertSame('author', $metadata['Customer']->getAssociation('notes')->mappedBy);

        // les collections sont initialisées dans le constructeur, créé pour l'occasion
        $customer = $this->file('Entity/Customer.php');
        self::assertStringContainsString("    public function __construct()\n    {\n        \$this->orders = new ArrayCollection();\n        \$this->notes = new ArrayCollection();\n    }", $customer);
        self::assertStringContainsString('use Prism\Orm\Collection\ArrayCollection;', $customer);
        $class = $this->namespace . '\Entity\Customer';
        $instance = new $class();
        $orders = new \ReflectionProperty($instance, 'orders')->getValue($instance);
        self::assertInstanceOf(\Countable::class, $orders);
        self::assertCount(0, $orders);
    }

    public function testHandWrittenCodeIsKept(): void
    {
        $namespace = $this->namespace;
        file_put_contents($this->dir . '/src/Entity/Customer.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Entity;

            use Prism\\Orm\\Mapping as Prism;

            // écrite à la main
            #[Prism\\Entity]
            final class Customer
            {
                #[Prism\\Id, Prism\\GeneratedValue]
                public ?int \$id = null;

                #[Prism\\Column(length: 80)]
                public string \$name = '';

                public function greet(): string
                {
                    return 'Hello ' .   \$this->name; // espaces gardés
                }
            }

            PHP);

        // un champ existant est refusé, la question est reposée
        $display = $this->make('Customer', ['name', 'nickname', 'string', '40', 'yes', '']);

        self::assertStringContainsString('The property "name" already exists.', $display);
        $code = $this->file('Entity/Customer.php');
        self::assertStringContainsString("// écrite à la main\n#[Prism\\Entity]", $code);
        self::assertStringContainsString("        return 'Hello ' .   \$this->name; // espaces gardés", $code);
        self::assertStringContainsString("    public string \$name = '';\n\n    #[Prism\\Column(length: 40)]\n    public ?string \$nickname = null;\n\n    public function greet(): string", $code);
        // la classe d'origine est déjà chargée (metamodel généré au démarrage) : une copie renommée est validée
        $copy = 'Customer' . bin2hex(random_bytes(4));
        file_put_contents($this->dir . '/' . $copy . '.php', str_replace('final class Customer', 'final class ' . $copy, $code));
        require $this->dir . '/' . $copy . '.php';
        /** @var class-string $class */
        $class = $this->namespace . '\Entity\\' . $copy;
        $metadata = new MetadataFactory(new AttributeDriver())->getMetadataFor($class);
        new MetadataValidator()->assertValid([$metadata]);
        self::assertSame(['id', 'name', 'nickname'], array_map(static fn ($f): string => $f->fieldName, $metadata->getFields()));
    }

    // RG-07.7 : le metamodel est marqué périmé, regénéré au démarrage suivant
    public function testMetamodelIsInvalidated(): void
    {
        $this->kernel->boot();
        $stamp = $this->kernel->getCacheDir() . '/prism/metamodel/default/.prism-generated';
        self::assertFileExists($stamp);

        $this->make('Customer', ['name', 'string', '80', 'no', '']);

        self::assertFileDoesNotExist($stamp);
    }

    public function testMakeMigrationRunsTheDiff(): void
    {
        $this->make('Customer', ['name', 'string', '80', 'no', '']);
        $this->validated();
        // une nouvelle commande, un nouveau processus : le kernel redémarre et voit la nouvelle entité
        $this->kernel->shutdown();

        $tester = new CommandTester(new Application($this->kernel)->find('make:migration'));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $migrations = array_values(array_filter((array) glob($this->dir . '/migrations/Version*.php'), is_string(...)));
        self::assertCount(1, $migrations);
        self::assertStringContainsString("\$m->createTable(new Table('customer'", (string) file_get_contents($migrations[0]));
    }

    // ADR 0013 : sans Doctrine, les commandes habituelles ; avec lui, make:prism:*
    public function testCommandNamesFollowDoctrine(): void
    {
        $application = new Application($this->kernel);
        self::assertSame('Create or update a Prism entity class, and its repository', $application->find('make:entity')->getDescription());
        self::assertStringContainsString('Prism migration', $application->find('make:migration')->getDescription());
        self::assertFalse($application->has('make:prism:entity'));

        $this->kernel->shutdown();
        $this->kernel->removeDirectory();
        $this->kernel = $this->kernel([new class extends Bundle {
            public function getContainerExtension(): Extension
            {
                // le seul alias suffit : Prism regarde si DoctrineBundle est là
                return new class extends Extension {
                    public function load(array $configs, ContainerBuilder $container): void
                    {
                    }

                    public function getAlias(): string
                    {
                        return 'doctrine';
                    }
                };
            }
        }]);
        $application = new Application($this->kernel);
        self::assertSame('Create or update a Prism entity class, and its repository', $application->find('make:prism:entity')->getDescription());
        self::assertTrue($application->has('make:prism:migration'));
        self::assertStringNotContainsString('Prism', $application->find('make:entity')->getDescription(), 'MakerBundle keeps its own');
    }
}
