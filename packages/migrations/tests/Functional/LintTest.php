<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaManager;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Migrations\Console\Command\LintCommand;
use Prism\Migrations\Lint\DatabaseInspector;
use Prism\Migrations\MigrationFinder;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Migrations\Migrator;
use Prism\Migrations\VersionStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le linter sur les 4 bases (RG-06.5) : les questions de l'inspecteur posées aux vraies
 * données, la commande db:migrations:lint, et CREATE INDEX CONCURRENTLY.
 */
final class LintTest extends TestCase
{
    private const array TABLES = ['lt_invoice', 'lt_customer', 'lt_concurrent', VersionStorage::TABLE];

    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->connection = TestDatabase::connection();
        $this->drop();
        $customer = new Table('lt_customer', [new Column('id', 'integer'), new Column('name', 'string', length: 40), new Column('nickname', 'string', nullable: true, length: 40), new Column('legacy', 'string', nullable: true, length: 10)], ['id']);
        $invoice = new Table('lt_invoice', [new Column('id', 'integer'), new Column('customer_id', 'integer', nullable: true)], ['id']);
        foreach ($this->connection->getPlatform()->getCreateSchemaSql(new Schema([$customer, $invoice])) as $sql) {
            $this->connection->executeStatement($sql);
        }
        // deux « Ada » (doublon), un surnom NULL, une facture sans client existant (orpheline)
        // MySQL refuse un même paramètre nommé deux fois dans une requête
        $this->connection->executeStatement('INSERT INTO lt_customer (id, name, nickname) VALUES (1, :a1, :n1), (2, :a2, NULL), (3, :b, :n3)', ['a1' => 'Ada', 'a2' => 'Ada', 'b' => 'Alan', 'n1' => 'x', 'n3' => 'x']);
        $this->connection->executeStatement('INSERT INTO lt_invoice (id, customer_id) VALUES (10, 1), (11, 99), (12, NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->drop();
            $this->connection->close();
        }
    }

    private function drop(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->getPlatform()->quoteIdentifier($table));
        }
    }

    public function testInspectorAsksTheRealData(): void
    {
        $data = new DatabaseInspector($this->connection);

        self::assertFalse($data->isEmpty('lt_customer'));
        self::assertTrue($data->hasMoreRowsThan('lt_customer', 2));
        self::assertFalse($data->hasMoreRowsThan('lt_customer', 3), 'three rows: none after the third');
        self::assertTrue($data->hasNulls('lt_customer', 'nickname'));
        self::assertFalse($data->hasNulls('lt_customer', 'name'));
        self::assertTrue($data->hasDuplicates('lt_customer', ['name']));
        self::assertFalse($data->hasDuplicates('lt_customer', ['id']));
        self::assertFalse($data->hasDuplicates('lt_customer', ['legacy']), 'NULLs do not collide in a unique index');
        self::assertTrue($data->hasOrphans('lt_invoice', ['customer_id'], 'lt_customer', ['id']), 'invoice 11 references customer 99');
        // L21 : la valeur la plus fréquente, deux « Ada »
        self::assertSame(2, $data->largestGroup('lt_customer', 'name'));
        self::assertSame(1, $data->largestGroup('lt_customer', 'id'));

        $this->connection->executeStatement('DELETE FROM lt_invoice WHERE id = 11');
        self::assertFalse($data->hasOrphans('lt_invoice', ['customer_id'], 'lt_customer', ['id']), 'a NULL reference is not an orphan');
    }

    public function testLintCommand(): void
    {
        $configuration = new MigrationsConfiguration(\dirname(__DIR__) . '/Fixtures/Lint', 'Prism\\Migrations\\Tests\\Fixtures\\Lint');
        $tester = new CommandTester(new LintCommand($this->connection, $configuration));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        foreach (['L01', 'L07', 'L08'] as $code) {
            self::assertStringContainsString('Version20261021100000 ' . $code . ' error', $display);
        }
        self::assertStringContainsString('L12 ignored', $display);
        self::assertStringContainsString('(ignored: legacy is never read)', $display);
        self::assertStringContainsString('No mapping given: L03 to L05', $display);
        self::assertMatchesRegularExpression('/1 pending migration\(s\) linted: 3 error\(s\), \d warning\(s\)\./', $display);
        self::assertSame('PostgreSQL' === $this->connection->getPlatform()->getName(), str_contains($display, 'L09'), 'CONCURRENTLY, under PostgreSQL only');
    }

    public function testNothingToLint(): void
    {
        $configuration = new MigrationsConfiguration(\dirname(__DIR__) . '/Fixtures/Basic', 'Prism\\Migrations\\Tests\\Fixtures\\Basic');
        $tester = new CommandTester(new LintCommand($this->connection, $configuration));

        self::assertSame(Command::SUCCESS, $tester->execute(['version' => 'Version20261021100000']));
        // RG-06.6 : 4 opérations de schéma sans transaction, sous MySQL et MariaDB (L17)
        $mysql = \in_array($this->connection->getPlatform()->getName(), ['MySQL', 'MariaDB'], true);
        self::assertStringContainsString(\sprintf('1 pending migration(s) linted: 0 error(s), %d warning(s).', $mysql ? 1 : 0), (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]), '--strict: the warnings of the later migrations fail too');
    }

    // RG-06.5 : sous PostgreSQL, l'index se crée sans verrou, hors transaction
    public function testConcurrentIndex(): void
    {
        $migrator = new Migrator($this->connection, new MigrationFinder(\dirname(__DIR__) . '/Fixtures/Concurrent', 'Prism\\Migrations\\Tests\\Fixtures\\Concurrent'));
        $postgres = 'PostgreSQL' === $this->connection->getPlatform()->getName();

        $result = $migrator->migrate();

        self::assertSame($postgres, str_contains(implode("\n", $result->migrations[0]->sql), 'CREATE UNIQUE INDEX CONCURRENTLY'));
        self::assertFalse($result->migrations[0]->transactional && $postgres, 'outside a transaction under PostgreSQL');
        $indexes = new SchemaManager($this->connection)->introspectTable('lt_concurrent')->getIndexes();
        self::assertCount(1, $indexes);
        self::assertTrue($indexes[0]->unique);
        $migrator->migrate('0');
    }
}
