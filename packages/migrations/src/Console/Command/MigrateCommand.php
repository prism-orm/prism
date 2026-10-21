<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Dbal\Connection;
use Prism\Migrations\Console\MigrationOutput;
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
 * Migre jusqu'à une version, ou jusqu'à la dernière (RG-06.1). Annonce les migrations qui
 * tourneront sans transaction (RG-06.6) et demande confirmation en prod (RG-06.9).
 *
 * @api
 */
#[AsCommand(name: 'db:migrations:migrate', description: 'Run the pending migrations, or migrate to a version')]
final class MigrateCommand extends Command
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
            ->addArgument('version', InputArgument::OPTIONAL, 'The version to migrate to (VersionYYYYMMDDHHMMSS), or 0 to revert every migration')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the SQL of each migration without executing it')
            ->addOption('allow-modified', null, InputOption::VALUE_NONE, 'Run even if an executed migration was modified since (RG-06.4)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $version = $input->getArgument('version');
        $to = \is_string($version) ? $version : null;
        $allowModified = true === $input->getOption('allow-modified');
        try {
            $migrator = new Migrator($this->connection, $this->configuration->finder(), $this->entityManager);
        } catch (MigrationsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $preview = $migrator->migrate($to, dryRun: true, allowModified: $allowModified);
            foreach ($preview->warnings as $warning) {
                $io->warning($warning);
            }
            if ([] === $preview->migrations) {
                $io->success(null === $to ? 'Nothing to migrate: the database is at the latest version.' : \sprintf('Nothing to migrate: the database is already at %s.', $to));

                return Command::SUCCESS;
            }

            $dryRun = true === $input->getOption('dry-run');
            MigrationOutput::plan($io, $preview->migrations, $dryRun);
            MigrationOutput::warnNonTransactional($io, $preview->migrations);
            if ($dryRun) {
                $io->success(\sprintf('Dry run: %d migration(s) planned, nothing executed.', \count($preview->migrations)));

                return Command::SUCCESS;
            }
            if (!MigrationOutput::confirmed($io, $input, $this->configuration, \sprintf('Run %d migration(s) on the %s database?', \count($preview->migrations), $this->configuration->environment))) {
                $io->warning('Migration cancelled: nothing was executed.');

                return Command::FAILURE;
            }

            $result = $migrator->migrate($to, allowModified: $allowModified);
        } catch (MigrationsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        MigrationOutput::executed($io, $result->migrations);
        $io->success(\sprintf('%d migration(s) executed in %d ms.', \count($result->migrations), array_sum(array_map(static fn ($m): int => $m->executionTimeMs ?? 0, $result->migrations))));

        return Command::SUCCESS;
    }
}
