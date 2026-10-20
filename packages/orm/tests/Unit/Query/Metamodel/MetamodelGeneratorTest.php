<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query\Metamodel;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Console\Command\MetamodelGenerateCommand;
use Prism\Orm\Exception\MetamodelException;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\IdMapping;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\BinaryField;
use Prism\Orm\Query\Metamodel\BoolField;
use Prism\Orm\Query\Metamodel\CollectionRelation;
use Prism\Orm\Query\Metamodel\DateTimeField;
use Prism\Orm\Query\Metamodel\DecimalField;
use Prism\Orm\Query\Metamodel\EmbeddableMetamodel;
use Prism\Orm\Query\Metamodel\EnumField;
use Prism\Orm\Query\Metamodel\FloatField;
use Prism\Orm\Query\Metamodel\IntField;
use Prism\Orm\Query\Metamodel\JsonField;
use Prism\Orm\Query\Metamodel\Metamodel;
use Prism\Orm\Query\Metamodel\MetamodelAutoloader;
use Prism\Orm\Query\Metamodel\MetamodelGenerator;
use Prism\Orm\Query\Metamodel\MixedField;
use Prism\Orm\Query\Metamodel\Relation;
use Prism\Orm\Query\Metamodel\StringField;
use Prism\Orm\Tests\Functional\PersisterFixtures;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Client;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\Priority;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR 0009 : génération stable (RG-05.2), fichiers non réécrits, classes chargeables.
 */
final class MetamodelGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/prism-metamodel-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                \assert($file instanceof \SplFileInfo);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<ClassMetadata<object>>
     */
    private static function metadata(array $classes): array
    {
        $driver = new AttributeDriver();

        return array_map($driver->loadMetadata(...), $classes);
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private static function invoiceAndClient(): array
    {
        return self::metadata([Invoice::class, Client::class]);
    }

    // ---------------------------------------------------------------- rendu

    public function testGeneratedCodeIsTheReference(): void
    {
        $files = new MetamodelGenerator()->render(self::invoiceAndClient());

        self::assertSame(['Prism/Orm/Tests/Unit/Mapping/Fixtures/Address_.php', 'Prism/Orm/Tests/Unit/Mapping/Fixtures/Client_.php', 'Prism/Orm/Tests/Unit/Mapping/Fixtures/Invoice_.php'], array_keys($files));
        foreach ($files as $path => $content) {
            self::assertStringEqualsFile(__DIR__ . '/Expected/' . basename($path, '.php') . '.txt', $content, $path);
        }
    }

    public function testRenderingIsDeterministic(): void
    {
        $generator = new MetamodelGenerator();

        self::assertSame($generator->render(self::invoiceAndClient()), $generator->render(array_reverse(self::invoiceAndClient())));
    }

    public function testEachTypeHasItsFieldClass(): void
    {
        $this->generateAndLoad([AllTypes::class]);
        $metamodel = self::make(AllTypes::class, 't');

        $expected = [
            'id' => IntField::class, 'string' => StringField::class, 'text' => StringField::class, 'uuid' => StringField::class,
            'smallint' => IntField::class, 'bigint' => IntField::class, 'decimal' => DecimalField::class, 'float' => FloatField::class,
            'bool' => BoolField::class, 'datetime' => DateTimeField::class, 'date' => DateTimeField::class, 'time' => DateTimeField::class,
            'json' => JsonField::class, 'binary' => BinaryField::class, 'priority' => EnumField::class,
        ];
        foreach ($expected as $field => $class) {
            self::assertInstanceOf($class, self::property($metamodel, $field), $field);
        }
        $priority = self::property($metamodel, 'priority');
        self::assertInstanceOf(EnumField::class, $priority);
        self::assertSame(Priority::class, $priority->enum);
    }

    public function testCustomTypeIsAMixedField(): void
    {
        $this->generateAndLoad([Invoice::class, Client::class]);

        self::assertInstanceOf(MixedField::class, self::property(self::make(Invoice::class, 'i'), 'embedding'));
    }

    // ---------------------------------------------------------------- fichiers

    public function testUnchangedFilesAreNotRewritten(): void
    {
        $generator = new MetamodelGenerator();
        $first = $generator->generate(self::invoiceAndClient(), $this->dir);
        $file = $this->dir . '/Prism/Orm/Tests/Unit/Mapping/Fixtures/Invoice_.php';
        touch($file, 1_000_000_000);
        clearstatcache();

        $second = $generator->generate(self::invoiceAndClient(), $this->dir . '/');

        self::assertCount(3, $first->written);
        self::assertSame([], $second->written);
        self::assertCount(3, $second->unchanged);
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($file), 'the mtime is kept');
    }

    public function testChangedFileIsRewrittenAndStaleOnesDeleted(): void
    {
        $generator = new MetamodelGenerator();
        $generator->generate(self::invoiceAndClient(), $this->dir);
        file_put_contents($this->dir . '/Prism/Orm/Tests/Unit/Mapping/Fixtures/Client_.php', '<?php // edited');
        // un fichier qui n'est pas de Prism, même en _.php, n'est jamais supprimé
        file_put_contents($foreign = $this->dir . '/Foreign_.php', '<?php // not generated');

        $result = $generator->generate(self::metadata([Client::class, Invoice::class, AllTypes::class]), $this->dir);
        self::assertSame(['Prism/Orm/Tests/Unit/Mapping/Fixtures/Client_.php', 'Prism/Orm/Tests/Unit/Query/Metamodel/Fixtures/AllTypes_.php'], $result->written);

        $result = $generator->generate(self::metadata([Client::class]), $this->dir);
        self::assertSame(['Prism/Orm/Tests/Unit/Mapping/Fixtures/Address_.php', 'Prism/Orm/Tests/Unit/Mapping/Fixtures/Invoice_.php', 'Prism/Orm/Tests/Unit/Query/Metamodel/Fixtures/AllTypes_.php'], $result->deleted);
        self::assertFileExists($foreign);
    }

    // RG-05.1
    public function testFreshness(): void
    {
        $generator = new MetamodelGenerator();
        self::assertFalse($generator->isFresh(self::invoiceAndClient(), $this->dir), 'nothing generated');

        $generator->generate(self::invoiceAndClient(), $this->dir);
        self::assertTrue($generator->isFresh(self::invoiceAndClient(), $this->dir));
        self::assertFalse($generator->isFresh(self::metadata([Invoice::class, Client::class, AllTypes::class]), $this->dir), 'an entity was added');
        self::assertFalse($generator->isFresh(self::metadata([Client::class]), $this->dir), 'an entity was removed');

        file_put_contents($this->dir . '/Prism/Orm/Tests/Unit/Mapping/Fixtures/Client_.php', '<?php // edited');
        self::assertFalse($generator->isFresh(self::invoiceAndClient(), $this->dir), 'a file was changed');
    }

    public function testUnwritableDirectoryFails(): void
    {
        file_put_contents($this->dir, 'a file, not a directory');

        try {
            $this->expectException(MetamodelException::class);
            $this->expectExceptionMessage('Cannot write the metamodel file "' . $this->dir . '/Prism/');

            new MetamodelGenerator()->generate(self::invoiceAndClient(), $this->dir);
        } finally {
            unlink($this->dir);
        }
    }

    // critère de sortie : génération du metamodel < 1 s pour 200 entités
    public function testTwoHundredEntitiesInLessThanOneSecond(): void
    {
        $metadata = [];
        for ($i = 0; $i < 200; ++$i) {
            $fields = [new FieldMapping('id', 'id', 'integer')];
            for ($f = 0; $f < 20; ++$f) {
                $fields[] = new FieldMapping('field' . $f, 'field_' . $f, 0 === $f % 2 ? 'string' : 'decimal');
            }
            /** @var class-string $class */
            $class = 'App\Entity\Generated' . $i;
            $metadata[] = new ClassMetadata($class, 'generated_' . $i, new IdMapping('id'), $fields);
        }

        $start = hrtime(true);
        $result = new MetamodelGenerator()->generate($metadata, $this->dir);

        self::assertCount(200, $result->written);
        self::assertLessThan(1000, (hrtime(true) - $start) / 1e6, 'milliseconds');
    }

    // ---------------------------------------------------------------- classes chargées

    public function testGeneratedClassesExposeFieldsRelationsAndEmbeddables(): void
    {
        $this->generateAndLoad([Invoice::class, Client::class]);
        $invoice = self::make(Invoice::class, 'i');

        self::assertSame([Invoice::class, 'i', null], [$invoice->entity, $invoice->alias, $invoice->via()]);
        $status = self::property($invoice, 'status');
        self::assertInstanceOf(EnumField::class, $status);
        self::assertSame([$invoice, 'status', FixtureStatus::class], [$status->owner, $status->path, $status->enum]);

        $address = self::property($invoice, 'billingAddress');
        self::assertInstanceOf(EmbeddableMetamodel::class, $address);
        self::assertSame([$invoice, 'billingAddress'], [$address->owner, $address->path]);
        self::assertSame(Address::class . '_', $address::class);
        $city = self::property($address, 'city');
        self::assertInstanceOf(StringField::class, $city);
        self::assertSame([$invoice, 'billingAddress.city'], [$city->owner, $city->path], 'the field belongs to the entity alias');

        $relation = self::property($invoice, 'client');
        self::assertInstanceOf(Relation::class, $relation);
        $client = $relation->as('c');
        self::assertSame([Client::class, 'c', $relation], [$client->entity, $client->alias, $client->via()]);
        self::assertInstanceOf(CollectionRelation::class, self::property($client, 'invoices'));
        self::assertSame('invoice', self::make(Invoice::class, null)->alias, 'default alias');
    }

    public function testAutoloaderRegistersEachDirectoryOnce(): void
    {
        $count = \count(spl_autoload_functions());

        MetamodelAutoloader::register($this->dir);
        MetamodelAutoloader::register($this->dir . '/');

        self::assertCount($count + 1, spl_autoload_functions());
        self::assertFalse(class_exists('App\Missing\Entity_'), 'unknown class: nothing loaded');
    }

    public function testCommand(): void
    {
        $tester = new CommandTester(new MetamodelGenerateCommand(new MetadataFactory(new AttributeDriver([\dirname(__DIR__, 2) . '/Mapping/Fixtures']))));
        $options = ['--dir' => $this->dir . '/var', '--ide-stub' => $this->dir . '/.prism/metamodel.php', '--phpstorm-meta' => $this->dir . '/.phpstorm.meta.php'];

        self::assertSame(0, $tester->execute($options));
        self::assertStringContainsString('written', $tester->getDisplay());
        self::assertFileExists($this->dir . '/var/Prism/Orm/Tests/Unit/Mapping/Fixtures/Invoice_.php');
        self::assertFileExists($this->dir . '/.prism/metamodel.php');
        self::assertFileExists($this->dir . '/.phpstorm.meta.php/prism.php');
        self::assertSame(0, $tester->execute($options));
        self::assertStringContainsString('0 written', $tester->getDisplay());
        self::assertStringNotContainsString('.prism', $tester->getDisplay(), 'unchanged helpers are not listed');
    }

    public function testCommandWithoutIdeHelpers(): void
    {
        $tester = new CommandTester(new MetamodelGenerateCommand(new MetadataFactory(new AttributeDriver([\dirname(__DIR__, 2) . '/Mapping/Fixtures']))));

        self::assertSame(0, $tester->execute(['--dir' => $this->dir . '/var', '--ide-stub' => $this->dir . '/stub.php', '--phpstorm-meta' => $this->dir . '/meta', '--no-ide' => true]));
        self::assertFileDoesNotExist($this->dir . '/stub.php');
        self::assertDirectoryDoesNotExist($this->dir . '/meta');
    }

    // ---------------------------------------------------------------- aide aux IDE (RG-05.2)

    public function testStubIsTheReference(): void
    {
        $stub = new MetamodelGenerator()->renderStub(self::invoiceAndClient());

        self::assertStringEqualsFile(__DIR__ . '/Expected/stub.txt', $stub);
    }

    public function testStubIsValidPhpAndHoldsEachClassOnce(): void
    {
        $file = $this->dir . '/stub.php';
        new MetamodelGenerator()->generateIdeHelpers(self::metadata([Invoice::class, Client::class, AllTypes::class]), $file, null);

        exec(\sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($file)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $stub = (string) file_get_contents($file);
        self::assertSame(1, substr_count($stub, 'final class Address_ '), 'an embeddable shared by entities is declared once');
        self::assertSame(4, substr_count($stub, 'final class '));
        self::assertStringContainsString("namespace Prism\\Orm\\Tests\\Unit\\Query\\Metamodel\\Fixtures {\n", $stub);
    }

    public function testPhpStormMetaMapsEntitiesToTheirRepository(): void
    {
        $meta = new MetamodelGenerator()->renderPhpStormMeta(self::metadata([Invoice::class, PersisterFixtures\Customer::class]));

        self::assertStringEqualsFile(__DIR__ . '/Expected/phpstorm-meta.txt', $meta);
        self::assertStringNotContainsString('override(', new MetamodelGenerator()->renderPhpStormMeta(self::invoiceAndClient()), 'no repository declared: nothing to override');
    }

    public function testIdeHelpersAreNotRewrittenWhenUnchanged(): void
    {
        $generator = new MetamodelGenerator();
        $stub = $this->dir . '/.prism/metamodel.php';
        $meta = $this->dir . '/.phpstorm.meta.php';

        $first = $generator->generateIdeHelpers(self::invoiceAndClient(), $stub, $meta);
        self::assertSame([$stub, $meta . '/prism.php'], $first->written);
        touch($stub, 1_000_000);
        $second = $generator->generateIdeHelpers(self::invoiceAndClient(), $stub, $meta . '/');

        self::assertSame([], $second->written);
        self::assertSame([$stub, $meta . '/prism.php'], $second->unchanged);
        clearstatcache();
        self::assertSame(1_000_000, filemtime($stub), 'mtime kept');
        self::assertSame([], $generator->generateIdeHelpers(self::invoiceAndClient(), null, null)->written);
    }

    public function testUnwritableIdeHelperFails(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/.phpstorm.meta.php', '<?php // a file, not a directory');

        $this->expectException(MetamodelException::class);

        new MetamodelGenerator()->generateIdeHelpers(self::invoiceAndClient(), null, $this->dir . '/.phpstorm.meta.php');
    }

    // ---------------------------------------------------------------- outils

    /**
     * @param list<class-string> $classes
     */
    private function generateAndLoad(array $classes): void
    {
        // un dossier par processus : les classes chargées le restent pour toute la suite
        $dir = sys_get_temp_dir() . '/prism-metamodel-loaded-' . getmypid();
        new MetamodelGenerator()->generate(self::metadata($classes), $dir);
        MetamodelAutoloader::register($dir);
    }

    /**
     * @param class-string $entity
     *
     * @return Metamodel<object>
     */
    private static function make(string $entity, ?string $alias): Metamodel
    {
        $class = $entity . '_';
        $metamodel = null === $alias ? new $class() : new $class($alias);
        \assert($metamodel instanceof Metamodel);

        return $metamodel;
    }

    private static function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }
}
