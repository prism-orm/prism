<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
use Prism\Orm\Console\Command\CacheClearMetadataCommand;
use Prism\Orm\Console\Command\MappingInfoCommand;
use Prism\Orm\Console\Command\QuerySqlCommand;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryCachePool;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * db:query:sql, db:mapping:info et db:cache:clear-metadata, sur SQLite en mémoire.
 */
final class ToolCommandsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0');
        $this->connection->executeStatement('CREATE TABLE item (id INTEGER PRIMARY KEY, name TEXT, note TEXT)');
        $this->connection->executeStatement("INSERT INTO item (id, name, note) VALUES (1, 'ada', NULL), (2, 'alan', 'x'), (3, 'grace', 'y')");
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testReadPrintsTheRows(): void
    {
        $tester = new CommandTester(new QuerySqlCommand($this->connection));

        self::assertSame(Command::SUCCESS, $tester->execute(['sql' => 'SELECT id, name, note FROM item WHERE id < :max ORDER BY id', '--param' => ['max=3']]));
        self::assertMatchesRegularExpression('/id\s+name\s+note/', $tester->getDisplay());
        self::assertStringContainsString('1 ada NULL', self::display($tester));
        self::assertStringContainsString('2 row(s).', self::display($tester));

        self::assertSame(Command::SUCCESS, $tester->execute(['sql' => 'SELECT * FROM item ORDER BY id', '--max-rows' => '1']));
        self::assertStringContainsString('3 row(s), 1 printed.', self::display($tester));
        self::assertSame(Command::SUCCESS, $tester->execute(['sql' => 'WITH n AS (SELECT 1 AS one) SELECT * FROM n WHERE one = 2']));
        self::assertStringContainsString('No rows.', self::display($tester));
    }

    public function testWriteNeedsForce(): void
    {
        $tester = new CommandTester(new QuerySqlCommand($this->connection));

        self::assertSame(Command::INVALID, $tester->execute(['sql' => 'DELETE FROM item WHERE id = :id', '--param' => ['id=1']]));
        self::assertStringContainsString('This query is not a read: nothing was executed.', self::display($tester));
        self::assertEquals(3, $this->connection->executeQuery('SELECT COUNT(*) FROM item')->fetchOne());

        self::assertSame(Command::SUCCESS, $tester->execute(['sql' => 'DELETE FROM item WHERE id = :id', '--param' => ['id=1'], '--force' => true]));
        self::assertStringContainsString('1 row(s) affected.', self::display($tester));
        self::assertSame(Command::INVALID, $tester->execute(['sql' => 'WITH gone AS (SELECT 1) DELETE FROM item']), 'a WITH that modifies is a write');
    }

    public function testInvalidParamAndFailingQuery(): void
    {
        $tester = new CommandTester(new QuerySqlCommand($this->connection));

        self::assertSame(Command::INVALID, $tester->execute(['sql' => 'SELECT 1', '--param' => ['nope']]));
        self::assertStringContainsString('Invalid --param "nope": expected name=value.', self::display($tester));
        self::assertSame(Command::FAILURE, $tester->execute(['sql' => 'SELECT * FROM missing']));
        self::assertStringContainsString('Query failed', self::display($tester));
    }

    public function testMappingInfo(): void
    {
        $tester = new CommandTester(new MappingInfoCommand(new MetadataFactory(new AttributeDriver([\dirname(__DIR__) . '/UnitOfWork/Fixtures/Batch']))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString(Customer::class . ' customer -', self::display($tester));
        self::assertStringContainsString('7 mapped entities.', self::display($tester));

        $empty = new CommandTester(new MappingInfoCommand(new MetadataFactory(new AttributeDriver([]))));
        self::assertSame(Command::SUCCESS, $empty->execute([]));
        self::assertStringContainsString('No mapped entity found', self::display($empty));
    }

    // RG-02.2 : un \DateTime mutable rend le mapping invalide
    public function testInvalidMappingFails(): void
    {
        $dir = sys_get_temp_dir() . '/prism-mapping-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $class = 'Broken' . bin2hex(random_bytes(4));
        $code = <<<'PHP'
            <?php
            namespace Prism\Orm\Tests\Generated;

            use Prism\Orm\Mapping as Prism;

            #[Prism\Entity]
            final class CLASS
            {
                #[Prism\Id]
                public int $id = 0;

                #[Prism\Column]
                public \DateTime $at;
            }
            PHP;
        file_put_contents($dir . '/' . $class . '.php', str_replace('CLASS', $class, $code));
        // le driver lit les classes chargeables ; celle-ci n'est dans aucun autoload
        require $dir . '/' . $class . '.php';

        try {
            $tester = new CommandTester(new MappingInfoCommand(new MetadataFactory(new AttributeDriver([$dir]))));
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('invalid', self::display($tester));
            self::assertStringContainsString('DateTimeImmutable', self::display($tester));
        } finally {
            unlink($dir . '/' . $class . '.php');
            rmdir($dir);
        }
    }

    public function testClearMetadataCache(): void
    {
        $cache = new InMemoryCachePool();
        $metadata = new MetadataFactory(new AttributeDriver([\dirname(__DIR__) . '/UnitOfWork/Fixtures/Batch']), $cache);
        $metadata->warmUp();
        self::assertGreaterThan(0, $cache->size());

        $tester = new CommandTester(new CacheClearMetadataCommand($metadata));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Metadata cache cleared.', self::display($tester));
        self::assertSame(0, $cache->size());

        $none = new CommandTester(new CacheClearMetadataCommand(new MetadataFactory(new AttributeDriver([]))));
        self::assertSame(Command::SUCCESS, $none->execute([]));
        self::assertStringContainsString('No metadata cache is configured', self::display($none));
    }
}
