<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Migrations\Console\Command\ExecuteCommand;
use Prism\Migrations\Console\Command\ListCommand;
use Prism\Migrations\Console\Command\MigrateCommand;
use Prism\Migrations\Console\Command\StatusCommand;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Migrations\VersionStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les commandes d'exécution sur les 4 bases : migrate, execute, status, list ; avertissement
 * des migrations sans transaction (RG-06.6), confirmation en prod (RG-06.9).
 */
final class CommandsTest extends TestCase
{
    private const array TABLES = ['mg_bill', 'mg_invoice', 'mg_client', 'mg_partial', VersionStorage::TABLE];

    private Connection $connection;

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
    }

    private function drop(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->getPlatform()->quoteIdentifier($table));
        }
    }

    private function configuration(string $environment = 'dev', string $fixtures = 'Basic'): MigrationsConfiguration
    {
        return new MigrationsConfiguration(\dirname(__DIR__) . '/Fixtures/' . $fixtures, 'Prism\\Migrations\\Tests\\Fixtures\\' . $fixtures, $environment);
    }

    /**
     * @param class-string<MigrateCommand|ExecuteCommand|StatusCommand|ListCommand> $command
     */
    private function tester(string $command, string $environment = 'dev', string $fixtures = 'Basic'): CommandTester
    {
        return new CommandTester(new $command($this->connection, $this->configuration($environment, $fixtures)));
    }

    // SymfonyStyle coupe les lignes à la largeur du terminal : les messages sont comparés sans
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testMigrateListAndStatus(): void
    {
        $status = $this->tester(StatusCommand::class);
        self::assertSame(Command::SUCCESS, $status->execute([]));
        self::assertMatchesRegularExpression('/Current version\s+0/', $status->getDisplay());
        self::assertMatchesRegularExpression('/Pending\s+3/', $status->getDisplay());

        $migrate = $this->tester(MigrateCommand::class);
        self::assertSame(Command::SUCCESS, $migrate->execute(['version' => 'Version20261021110000'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        $display = self::display($migrate);
        self::assertStringContainsString('up Version20261021100000', $display);
        self::assertStringContainsString('2 migration(s) executed', $display);
        self::assertStringContainsString('RENAME COLUMN', $display, 'the SQL, in verbose mode');
        self::assertSame(!$this->connection->getPlatform()->supportsTransactionalDdl(), str_contains($display, 'without a transaction'), 'RG-06.6, announced before running');

        $list = $this->tester(ListCommand::class);
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertMatchesRegularExpression('/Version20261021110000\s+executed\s+\d{4}-\d{2}-\d{2} [\d:]+\s+\d+ ms\s+Payment date, client email/', $list->getDisplay());
        self::assertMatchesRegularExpression('/Version20261021120000\s+pending/', $list->getDisplay());

        self::assertSame(Command::SUCCESS, $status->execute([]));
        self::assertMatchesRegularExpression('/Current version\s+Version20261021110000/', $status->getDisplay());
        self::assertMatchesRegularExpression('/Latest version\s+Version20261021120000/', $status->getDisplay());

        self::assertSame(Command::SUCCESS, $migrate->execute([]));
        self::assertSame(Command::SUCCESS, $migrate->execute([]));
        self::assertStringContainsString('Nothing to migrate: the database is at the latest version.', self::display($migrate));
    }

    public function testDryRunPrintsTheSqlAndExecutesNothing(): void
    {
        $migrate = $this->tester(MigrateCommand::class);

        self::assertSame(Command::SUCCESS, $migrate->execute(['--dry-run' => true]));
        self::assertStringContainsString('mg_client', self::display($migrate));
        self::assertStringContainsString('Dry run: 3 migration(s) planned, nothing executed.', self::display($migrate));
        $list = $this->tester(ListCommand::class);
        $list->execute([]);
        self::assertSame(3, substr_count($list->getDisplay(), 'pending'));
    }

    // RG-06.9
    public function testProductionAsksForConfirmation(): void
    {
        $refused = $this->tester(MigrateCommand::class, 'prod');
        $refused->setInputs(['no']);
        self::assertSame(Command::FAILURE, $refused->execute([]));
        self::assertStringContainsString('Run 3 migration(s) on the prod database?', self::display($refused));
        self::assertStringContainsString('Migration cancelled: nothing was executed.', self::display($refused));

        $accepted = $this->tester(MigrateCommand::class, 'prod');
        $accepted->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $accepted->execute(['version' => 'Version20261021100000']));
        self::assertStringContainsString('1 migration(s) executed', self::display($accepted));

        $script = $this->tester(MigrateCommand::class, 'prod');
        self::assertSame(Command::SUCCESS, $script->execute(['version' => 'Version20261021110000'], ['interactive' => false]), '--no-interaction: no question');
        self::assertStringNotContainsString('prod database?', self::display($script));

        $dev = $this->tester(ExecuteCommand::class);
        self::assertSame(Command::SUCCESS, $dev->execute(['version' => 'Version20261021110000', '--down' => true]), 'no question outside prod');
    }

    public function testExecuteOneMigration(): void
    {
        $execute = $this->tester(ExecuteCommand::class);

        self::assertSame(Command::SUCCESS, $execute->execute(['version' => 'Version20261021100000', '--up' => true]));
        self::assertStringContainsString('Migration Version20261021100000 executed up.', self::display($execute));
        self::assertSame(Command::SUCCESS, $execute->execute(['version' => 'Version20261021100000', '--down' => true, '--dry-run' => true]));
        self::assertStringContainsString('DROP TABLE', self::display($execute));
        self::assertSame(Command::FAILURE, $execute->execute(['version' => 'Version20261021100000', '--up' => true]));
        self::assertStringContainsString('is already executed', self::display($execute));
        self::assertSame(Command::INVALID, $execute->execute(['version' => 'Version20261021100000']));
        self::assertSame(Command::INVALID, $execute->execute(['version' => 'Version20261021100000', '--up' => true, '--down' => true]));
        self::assertStringContainsString('Pass exactly one of --up and --down.', self::display($execute));
    }

    public function testFailuresAreReported(): void
    {
        $migrate = $this->tester(MigrateCommand::class);
        self::assertSame(Command::FAILURE, $migrate->execute(['version' => 'Version20990101000000']));
        self::assertStringContainsString('Unknown migration version', self::display($migrate));

        // RG-06.7 : revertir une migration sans down()
        self::assertSame(Command::SUCCESS, $migrate->execute([]));
        self::assertSame(Command::FAILURE, $migrate->execute(['version' => '0']));
        self::assertStringContainsString('cannot be reverted: it has no down()', self::display($migrate));

        // même nom de version que Basic : repartir d'une base vierge
        $this->drop();
        $failing = $this->tester(MigrateCommand::class, fixtures: 'Failing');
        self::assertSame(Command::FAILURE, $failing->execute([]));
        self::assertStringContainsString('The migration Version20261021100000 failed (up)', self::display($failing));
    }

    // RG-06.4
    public function testStatusWarnsAboutModifiedAndMissingMigrations(): void
    {
        $this->tester(MigrateCommand::class)->execute(['version' => 'Version20261021100000']);
        $qb = $this->connection->createQueryBuilder();
        $qb->update(VersionStorage::TABLE)->set('checksum', str_repeat('0', 64))->executeStatement();
        $this->connection->createQueryBuilder()->insert(VersionStorage::TABLE)->set('version', 'Version20200101000000')->set('executed_at', new \DateTimeImmutable('2020-01-01'), 'datetime_immutable')->set('execution_time_ms', 1)->set('checksum', 'x')->executeStatement();

        $status = $this->tester(StatusCommand::class);
        self::assertSame(Command::SUCCESS, $status->execute([]));
        self::assertStringContainsString('Version20261021100000 was modified after it was executed', self::display($status));
        self::assertStringContainsString('Version20200101000000 is recorded as executed, but its file is missing.', self::display($status));

        $migrate = $this->tester(MigrateCommand::class);
        self::assertSame(Command::FAILURE, $migrate->execute([]));
        self::assertSame(Command::SUCCESS, $migrate->execute(['--allow-modified' => true]));
        self::assertStringContainsString('its file is missing', self::display($migrate));
    }
}
