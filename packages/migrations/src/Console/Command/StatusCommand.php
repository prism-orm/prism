<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Dbal\Connection;
use Prism\Migrations\Exception\MigrationsException;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Migrations\MigrationState;
use Prism\Migrations\MigrationStatus;
use Prism\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Où en est la base : version courante, dernière disponible, migrations en attente, et celles
 * modifiées après exécution (RG-06.4) ou sans fichier.
 *
 * @internal
 */
#[AsCommand(name: 'db:migrations:status', description: 'Show where the database stands in the migrations')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationsConfiguration $configuration,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $status = new Migrator($this->connection, $this->configuration->finder())->status();
        } catch (MigrationsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $in = static fn (MigrationState ...$states): array => array_values(array_filter($status, static fn (MigrationStatus $s): bool => \in_array($s->state, $states, true)));
        $executed = $in(MigrationState::Executed, MigrationState::Modified, MigrationState::Missing);
        $available = $in(MigrationState::Pending, MigrationState::Executed, MigrationState::Modified);

        $io->definitionList(
            ['Directory' => $this->configuration->directory],
            ['Namespace' => $this->configuration->namespace],
            ['Current version' => [] === $executed ? '0' : $executed[\count($executed) - 1]->version],
            ['Latest version' => [] === $available ? '0' : $available[\count($available) - 1]->version],
            ['Executed' => (string) \count($executed)],
            ['Pending' => (string) \count($in(MigrationState::Pending))],
        );
        foreach ($in(MigrationState::Modified) as $migration) {
            $io->warning(\sprintf('%s was modified after it was executed: migrate will refuse to run (RG-06.4).', $migration->version));
        }
        foreach ($in(MigrationState::Missing) as $migration) {
            $io->warning(\sprintf('%s is recorded as executed, but its file is missing.', $migration->version));
        }

        return Command::SUCCESS;
    }
}
