<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\Console\Command\ImportCommand;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\ErpSchemaAnonymizer;
use Prism\Orm\Tests\Unit\Tools\Import\EntityImporterTest;
use Prism\Orm\Tools\Import\EntityImporter;
use Prism\Orm\Tools\Import\ImportResult;
use Prism\Orm\Tools\SchemaTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * db:import sur base réelle (RG-06.10) : les entités générées redonnent le schéma de la base,
 * aux seuls écarts que l'importeur signale lui-même. Sur les 4 bases avec un schéma de
 * synthèse ; sous MariaDB, avec le schéma ERP réel anonymisé (critère de sortie de la phase 6).
 */
final class ImportTest extends TestCase
{
    private const array TABLES = ['shop_list', 'shop_profile', 'shop_customer', 'shop_country', 'shop_log', 'shop_pair'];

    private Connection $connection;

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->connection = TestDatabase::connection();
        $this->drop();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->drop();
            $this->connection->close();
        }
        foreach ($this->dirs as $dir) {
            $files = glob($dir . '/*');
            foreach (false === $files ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    private function drop(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->getPlatform()->quoteIdentifier($table));
        }
    }

    private function dir(): string
    {
        $this->dirs[] = $dir = sys_get_temp_dir() . '/prism-import-' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    /**
     * Les entités écrites, chargées, puis leur schéma (le mapping est validé au passage).
     */
    private function entitySchema(Connection $connection, ImportResult $result): Schema
    {
        $dir = $this->dir();
        foreach ($result->entities as $file => $code) {
            file_put_contents($dir . '/' . $file, $code);
        }
        foreach (array_keys($result->entities) as $file) {
            require $dir . '/' . $file;
        }
        $metadata = new MetadataFactory(new AttributeDriver([$dir]), types: $connection->getTypeRegistry());

        return new SchemaTool($connection->getPlatform(), $connection->getTypeRegistry())->fromMetadata($metadata->getAllMetadata());
    }

    /**
     * Le diff entre la base et les entités ne contient que ce que l'importeur a signalé : index
     * ajouté sur une colonne de jointure, défaut d'une colonne de jointure, clé étrangère gardée
     * en colonne.
     */
    private static function assertOnlyReported(SchemaDiff $diff, ImportResult $result): void
    {
        self::assertSame([[], [], [], [], []], [$diff->createdTables, $diff->droppedTables, $diff->createdSequences, $diff->droppedSequences, $diff->alteredSequences]);
        $unexpected = [];
        foreach ($diff->alteredTables as $t) {
            foreach ($t->addedIndexes as $index) {
                if (!str_contains($result->approximations[$t->name . '.' . $index->columns[0]] ?? '', 'indexes every join column')) {
                    $unexpected[] = 'index ' . $t->name . '.' . $index->name;
                }
            }
            foreach ($t->changedColumns as $change) {
                if (['default'] !== $change->changedProperties || !str_contains($result->approximations[$t->name . '.' . $change->from->name] ?? '', 'The default')) {
                    $unexpected[] = 'column ' . $t->name . '.' . $change->from->name . ' (' . implode(', ', $change->changedProperties) . ')';
                }
            }
            foreach ($t->droppedForeignKeys as $fk) {
                if (!isset($result->foreignKeys[$t->name . '.' . $fk->name])) {
                    $unexpected[] = 'foreign key ' . $t->name . '.' . $fk->name;
                }
            }
            foreach ([...$t->addedColumns, ...$t->droppedColumns, ...$t->droppedIndexes, ...$t->addedForeignKeys] as $other) {
                $unexpected[] = $t->name . ': ' . $other->name;
            }
            if ($t->fromPrimaryKey !== $t->toPrimaryKey) {
                $unexpected[] = $t->name . ': primary key';
            }
        }

        self::assertSame([], $unexpected, 'the entities give back the schema of the database, except what the importer reported');
    }

    /**
     * @param list<string> $tables
     */
    private static function only(Schema $schema, array $tables): Schema
    {
        return new Schema(array_values(array_filter($schema->getTables(), static fn (Table $t): bool => \in_array(strtolower($t->name), array_map(strtolower(...), $tables), true))));
    }

    public function testEntitiesGiveBackTheSchema(): void
    {
        foreach ($this->connection->getPlatform()->getCreateSchemaSql(EntityImporterTest::schema()) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $actual = self::only($this->connection->createSchemaManager()->introspect(), self::TABLES);

        $result = new EntityImporter()->import($actual, 'Prism\\Orm\\Tests\\Generated\\Import' . bin2hex(random_bytes(4)), 'shop_');

        self::assertSame(['Country.php', 'Customer.php', 'ListEntity.php', 'Profile.php'], array_keys($result->entities));
        self::assertSame(['shop_log', 'shop_pair'], array_keys($result->skipped));
        $target = $this->entitySchema($this->connection, $result);
        $imported = array_map(static fn (Table $t): string => $t->name, $target->getTables());
        self::assertOnlyReported(new Comparator($this->connection->getPlatform())->compare(self::only($actual, $imported), $target), $result);
        // la colonne de jointure n'a pas d'index déclaré (l'index implicite de MySQL n'est pas introspecté)
        self::assertStringContainsString('indexes every join column', $result->approximations['shop_customer.country_id'] ?? '');
    }

    // critère de sortie de la phase 6 : schéma ERP réel anonymisé, 165 tables
    public function testRealErpSchemaUnderMariaDb(): void
    {
        if ('MariaDB' !== $this->connection->getPlatform()->getName()) {
            self::markTestSkipped('The ERP schema is a MariaDB dump.');
        }
        $database = 'prism_erp_' . bin2hex(random_bytes(4));
        $server = $this->connection->withoutDatabase();
        $server->executeStatement('CREATE DATABASE ' . $database);
        try {
            $erp = DriverManager::fromUrl((string) preg_replace('#^([^?]+/)[^/?]+#', '$1' . $database, (string) TestDatabase::url()));
            $sql = (string) preg_replace('/^--[^\n]*\n/m', '', (string) file_get_contents(ErpSchemaAnonymizer::fixture()));
            foreach (array_filter(array_map(trim(...), explode(";\n", $sql)), static fn (string $s): bool => '' !== $s) as $statement) {
                $erp->executeStatement($statement);
            }
            $actual = $erp->createSchemaManager()->introspect();
            self::assertCount(165, $actual->getTables());

            $result = new EntityImporter()->import($actual, 'Prism\\Orm\\Tests\\Generated\\Erp' . bin2hex(random_bytes(4)), 'erp_');

            self::assertCount(119, $result->entities);
            self::assertCount(46, $result->skipped, 'without primary key');
            self::assertSame([], $result->foreignKeys, 'every foreign key becomes a relation');
            self::assertSame(['erp_t029.c70'], array_keys($result->approximations), 'a join column with a default, and nothing else');
            $target = $this->entitySchema($erp, $result);
            self::assertCount(119, $target->getTables());
            $imported = array_map(static fn (Table $t): string => $t->name, $target->getTables());
            self::assertOnlyReported(new Comparator($erp->getPlatform())->compare(self::only($actual, $imported), $target), $result);
            $erp->close();
        } finally {
            $server->executeStatement('DROP DATABASE ' . $database);
            $server->close();
        }
    }

    public function testCommand(): void
    {
        foreach ($this->connection->getPlatform()->getCreateSchemaSql(EntityImporterTest::schema()) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $dir = $this->dir();
        $tester = new CommandTester(new ImportCommand($this->connection));
        $options = ['--dir' => $dir, '--namespace' => 'App\\Imported', '--strip-prefix' => 'shop_', '--exclude' => ['^(?!shop_)']];
        $display = static fn (): string => (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute($options));
        self::assertStringContainsString('4 entities written to', $display());
        self::assertStringContainsString('skipped shop_log: it has no primary key', $display());
        self::assertStringContainsString('note shop_list.FK_LIST_PAIR', $display());
        self::assertStringContainsString('namespace App\\Imported;', (string) file_get_contents($dir . '/Customer.php'));

        // RG-06.10 : jamais remplacé sans --force
        file_put_contents($dir . '/Customer.php', '<?php // edited');
        self::assertSame(Command::SUCCESS, $tester->execute($options));
        self::assertStringContainsString('already exist and were kept (RG-06.10)', $display());
        self::assertSame('<?php // edited', file_get_contents($dir . '/Customer.php'));
        self::assertSame(Command::SUCCESS, $tester->execute([...$options, '--force' => true]));
        self::assertStringContainsString('final class Customer', (string) file_get_contents($dir . '/Customer.php'));

        self::assertSame(Command::SUCCESS, $tester->execute([...$options, '--dry-run' => true, '--exclude' => ['^(?!shop_)', 'shop_list']]));
        self::assertStringContainsString('final class Country', $display());
        self::assertStringNotContainsString('final class ListEntity', $display(), '--exclude');
        self::assertSame(Command::INVALID, $tester->execute([...$options, '--exclude' => ['(']]));
    }

    public function testIndexOfAnImportedJoinColumnIsKept(): void
    {
        // une base où la colonne de jointure a déjà son index : aucune approximation
        $schema = EntityImporterTest::schema();
        $customer = $schema->getTable('shop_customer')->withIndex(new Index('IDX_CUSTOMER_COUNTRY', ['country_id']));
        $result = new EntityImporter()->import($schema->withTable($customer), 'App\\Entity', 'shop_');

        self::assertArrayNotHasKey('shop_customer.country_id', $result->approximations);
        self::assertStringContainsString("#[Prism\\Index(['country'], name: 'IDX_CUSTOMER_COUNTRY')]", $result->entities['Customer.php']);
    }
}
