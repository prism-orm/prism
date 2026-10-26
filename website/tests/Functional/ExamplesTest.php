<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Functional;

use App\Command\SeedCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Driver;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\MetamodelGenerator;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tools\SchemaTool;
use Prism\Website\Tests\Support\CodeBlock;
use Prism\Website\Tests\Support\DocPages;
use Prism\Website\Tests\Support\ExampleRuntime;
use Prism\Website\Tests\Support\ExampleScript;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RG-10.3 : chaque bloc PHP des pages du site s'exécute sur la base de test, sur les 4 bases,
 * dans son propre processus, sur les données de la démo ERP (app:seed, 2 sociétés). Un bloc
 * marqué no-run n'est que vérifié en syntaxe (CodeBlocksTest).
 */
final class ExamplesTest extends TestCase
{
    private static string $url;

    private static string $workDir;

    private static Schema $target;

    public static function setUpBeforeClass(): void
    {
        $url = TestDatabase::url();
        if (null === $url) {
            return;
        }
        self::$workDir = DocPages::root() . '/var/docs-examples';
        if (!is_dir(self::$workDir . '/metamodel')) {
            mkdir(self::$workDir . '/metamodel', 0o777, true);
        }
        // une base SQLite en mémoire meurt avec sa connexion : les exemples, dans d'autres processus, lisent un fichier
        if (Driver::Sqlite === TestDatabase::driver()) {
            $file = self::$workDir . '/examples.sqlite';
            if (is_file($file)) {
                unlink($file);
            }
            $url = 'sqlite:///' . $file;
        }
        self::$url = $url;

        $connection = DriverManager::fromUrl($url);
        $driver = new AttributeDriver([ExampleRuntime::entityDir()]);
        $metadata = array_map($driver->loadMetadata(...), $driver->getAllClassNames());
        self::$target = new SchemaTool($connection->getPlatform())->fromMetadata($metadata);
        self::migrate($connection, new Schema());
        self::migrate($connection, self::$target);
        new MetamodelGenerator()->generate($metadata, self::$workDir . '/metamodel');

        $tenants = new StackTenantContext();
        $em = new EntityManager($connection, new MetadataFactory($driver, types: $connection->getTypeRegistry()), tenantContext: $tenants);
        new SeedCommand($em, $tenants)(new SymfonyStyle(new ArrayInput([]), new NullOutput()), customers: 5, orders: 30);
        $connection->close();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$url)) {
            $connection = DriverManager::fromUrl(self::$url);
            self::migrate($connection, new Schema());
            $connection->close();
        }
    }

    /**
     * @return iterable<string, array{CodeBlock}>
     */
    public static function examples(): iterable
    {
        foreach (DocPages::codeBlocks('php') as $block) {
            if ($block->runs()) {
                yield $block->name() => [$block];
            }
        }
    }

    #[DataProvider('examples')]
    public function testExampleRuns(CodeBlock $block): void
    {
        if (!isset(self::$url)) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $script = self::$workDir . '/example.php';
        file_put_contents($script, ExampleScript::render($block));

        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=' . \dirname(__DIR__) . '/Examples/prelude.php', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'DATABASE_URL' => self::$url, ExampleRuntime::METAMODEL_ENV => self::$workDir . '/metamodel'],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, \sprintf(
            "The example at %s failed (exit code %d). Fix it, or mark it ```php no-run if it cannot run alone.\n\n%s\n--- output ---\n%s%s",
            $block->name(),
            $status,
            ExampleScript::render($block),
            $stdout,
            $stderr,
        ));
    }

    // les tables de la démo seulement, comme MappingSchemaTestCase
    private static function migrate(Connection $connection, Schema $schema): void
    {
        $tables = array_map(static fn (Table $t): string => strtolower($t->name), self::$target->getTables());
        $sequences = array_map(static fn (Sequence $s): string => strtolower($s->name), self::$target->getSequences());
        $introspected = $connection->createSchemaManager()->introspect();
        $actual = new Schema(
            array_values(array_filter($introspected->getTables(), static fn (Table $t): bool => \in_array(strtolower($t->name), $tables, true))),
            array_values(array_filter($introspected->getSequences(), static fn (Sequence $s): bool => \in_array(strtolower($s->name), $sequences, true))),
        );
        $connection->createSchemaManager()->migrate(new Comparator($connection->getPlatform())->compare($actual, $schema));
    }
}
