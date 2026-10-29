<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Dbal\Connection;
use Prism\Migrations\Console\MigrationOutput;
use Prism\Migrations\Direction;
use Prism\Migrations\Exception\MigrationsException;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Migrations\Migrator;
use Prism\Orm\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exécute une seule migration, dans un sens ; confirmation en prod (RG-06.9).
 *
 * @internal
 */
#[AsCommand(name: 'db:migrations:execute', description: 'Run a single migration up or down')]
final class ExecuteCommand extends Command
{
    /**
     * @param EntityManagerInterface|null $entityManager pour les migrations de données (RG-06.8)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationsConfiguration $configuration,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'The version to run (VersionYYYYMMDDHHMMSS)')
            ->addOption('up', null, InputOption::VALUE_NONE, 'Run its up()')
            ->addOption('down', null, InputOption::VALUE_NONE, 'Run its down()')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print its SQL without executing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $up = true === $input->getOption('up');
        if ($up === (true === $input->getOption('down'))) {
            $io->error('Pass exactly one of --up and --down.');

            return Command::INVALID;
        }
        $version = $input->getArgument('version');
        $version = \is_string($version) ? $version : '';
        $direction = $up ? Direction::Up : Direction::Down;
        try {
            $migrator = new Migrator($this->connection, $this->configuration->finder(), $this->entityManager);
        } catch (MigrationsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $preview = $migrator->execute($version, $direction, dryRun: true);
            $dryRun = true === $input->getOption('dry-run');
            MigrationOutput::plan($io, $preview->migrations, $dryRun);
            MigrationOutput::warnNonTransactional($io, $preview->migrations);
            if ($dryRun) {
                $io->success('Dry run: nothing executed.');

                return Command::SUCCESS;
            }
            if (!MigrationOutput::confirmed($io, $input, $this->configuration, \sprintf('Run %s %s on the %s database?', $version, $direction->value, $this->configuration->environment))) {
                $io->warning('Migration cancelled: nothing was executed.');

                return Command::FAILURE;
            }
            $result = $migrator->execute($version, $direction);
        } catch (MigrationsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        MigrationOutput::executed($io, $result->migrations);
        $io->success(\sprintf('Migration %s executed %s.', $version, $direction->value));

        return Command::SUCCESS;
    }
}
