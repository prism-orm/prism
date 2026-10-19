<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\Console\Command\SchemaCreateCommand;
use Prism\Orm\Console\Command\SchemaDropCommand;
use Prism\Orm\Console\Command\SchemaUpdateCommand;
use Prism\Orm\Console\Command\SchemaValidateCommand;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaCommandsTest extends TestCase
{
    private const array MAPPED = ['author', 'book', 'book_label', 'label'];

    private Connection $connection;

    private MetadataFactory $metadata;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->connection = TestDatabase::connection();
        $this->metadata = new MetadataFactory(new AttributeDriver([__DIR__ . '/Fixtures']), null, $this->connection->getTypeRegistry());
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->cleanUp();
            $this->connection->close();
        }
    }

    // critère de sortie : --dump-sql, puis --force, puis un second --dump-sql vide
    public function testUpdateDumpForceThenNothingToUpdate(): void
    {
        [$code, $display] = $this->runCommand(SchemaUpdateCommand::class, ['--dump-sql' => true]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('CREATE TABLE ' . $this->q('book'), $display);
        self::assertSame([], $this->tables(), '--dump-sql executes nothing');

        [$code, $display] = $this->runCommand(SchemaUpdateCommand::class, ['--force' => true]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Database schema updated', $display);
        self::assertSame(self::MAPPED, $this->tables());

        [$code, $display] = $this->runCommand(SchemaUpdateCommand::class, ['--dump-sql' => true]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Nothing to update', $display);
        self::assertStringNotContainsString(';', $display);
    }

    // RG-02.9 : sans --force, rien n'est exécuté
    public function testUpdateWithoutForceExecutesNothing(): void
    {
        [$code, $display] = $this->runCommand(SchemaUpdateCommand::class);

        self::assertSame(Command::INVALID, $code);
        self::assertStringContainsString('nothing was executed', $display);
        self::assertSame([], $this->tables());
    }

    // RG-02.9 : une table non mappée n'est supprimée qu'avec --complete
    public function testUnmappedTablesAreDroppedOnlyWithComplete(): void
    {
        $this->connection->executeStatement(\sprintf('CREATE TABLE %s (id INTEGER NOT NULL PRIMARY KEY)', $this->q('legacy_unmapped')));

        $this->runCommand(SchemaUpdateCommand::class, ['--force' => true]);
        self::assertTrue($this->exists('legacy_unmapped'));

        [, $display] = $this->runCommand(SchemaUpdateCommand::class, ['--force' => true, '--complete' => true]);
        self::assertStringContainsString('--complete drops 1 unmapped table(s)', $display);
        self::assertFalse($this->exists('legacy_unmapped'));
        self::assertSame(self::MAPPED, $this->tables());
    }

    public function testCreateRefusesAnExistingSchema(): void
    {
        [$code] = $this->runCommand(SchemaCreateCommand::class);
        self::assertSame(Command::SUCCESS, $code);
        self::assertSame(self::MAPPED, $this->tables());

        [$code, $display] = $this->runCommand(SchemaCreateCommand::class);
        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('These tables already exist: author, book, book_label, label', $display);
    }

    public function testCreateDumpSql(): void
    {
        [$code, $display] = $this->runCommand(SchemaCreateCommand::class, ['--dump-sql' => true]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('CREATE TABLE ' . $this->q('label'), $display);
        self::assertSame([], $this->tables());
    }

    public function testDropRequiresForceAndOnlyDropsMappedTables(): void
    {
        $this->runCommand(SchemaCreateCommand::class);
        $this->connection->executeStatement(\sprintf('CREATE TABLE %s (id INTEGER NOT NULL PRIMARY KEY)', $this->q('legacy_unmapped')));

        [$code, $display] = $this->runCommand(SchemaDropCommand::class);
        self::assertSame(Command::INVALID, $code);
        self::assertStringContainsString('This drops 4 table(s) and all their data', $display);
        self::assertSame(self::MAPPED, $this->tables());

        [$code, $display] = $this->runCommand(SchemaDropCommand::class, ['--dump-sql' => true]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('DROP TABLE ' . $this->q('book'), $display);

        [$code] = $this->runCommand(SchemaDropCommand::class, ['--force' => true]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertSame([], $this->tables());
        self::assertTrue($this->exists('legacy_unmapped'));

        [, $display] = $this->runCommand(SchemaDropCommand::class, ['--force' => true]);
        self::assertStringContainsString('Nothing to drop', $display);
    }

    public function testValidate(): void
    {
        [$code, $display] = $this->runCommand(SchemaValidateCommand::class);
        self::assertSame(Command::FAILURE, $code, 'mapping valid, database not created yet');
        self::assertStringContainsString('The mapping of 3 entities is valid.', $display);
        self::assertStringContainsString('The database is not in sync with the mapping', $display);

        $this->runCommand(SchemaUpdateCommand::class, ['--force' => true]);
        [$code, $display] = $this->runCommand(SchemaValidateCommand::class);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('The database is in sync with the mapping.', $display);

        [$code] = $this->runCommand(SchemaValidateCommand::class, ['--skip-sync' => true]);
        self::assertSame(Command::SUCCESS, $code);
    }

    public function testValidateReportsEveryMappingError(): void
    {
        // la fixture Invoice utilise le type vector, inconnu avant la phase 9
        $invalid = new MetadataFactory(new AttributeDriver([__DIR__ . '/../Unit/Mapping/Fixtures']), null, $this->connection->getTypeRegistry());
        $tester = new CommandTester(new SchemaValidateCommand($this->connection, $invalid));

        $code = $tester->execute(['--skip-sync' => true]);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('The mapping is invalid (1 error).', $tester->getDisplay());
        self::assertStringContainsString('unknown type "vector"', $tester->getDisplay());
    }

    /**
     * @param class-string<SchemaCreateCommand|SchemaUpdateCommand|SchemaDropCommand|SchemaValidateCommand> $command
     * @param array<string, bool>                                                                           $options
     *
     * @return array{int, string}
     */
    private function runCommand(string $command, array $options = []): array
    {
        $tester = new CommandTester(new $command($this->connection, $this->metadata));
        $code = $tester->execute($options);

        return [$code, $tester->getDisplay()];
    }

    /**
     * @return list<string> tables du mapping présentes en base, triées
     */
    private function tables(): array
    {
        $tables = array_values(array_intersect($this->connection->createSchemaManager()->listTableNames(), self::MAPPED));
        sort($tables);

        return $tables;
    }

    private function exists(string $table): bool
    {
        return \in_array($table, $this->connection->createSchemaManager()->listTableNames(), true);
    }

    private function q(string $name): string
    {
        return $this->connection->getPlatform()->quoteIdentifier($name);
    }

    private function cleanUp(): void
    {
        new CommandTester(new SchemaDropCommand($this->connection, $this->metadata))->execute(['--force' => true]);
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->q('legacy_unmapped'));
    }
}
