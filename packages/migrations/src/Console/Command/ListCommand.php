<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Dbal\Connection;
use Prism\Migrations\Exception\MigrationsException;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Migrations\MigrationStatus;
use Prism\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Toutes les migrations, avec leur état, leur date et leur durée d'exécution.
 *
 * @internal
 */
#[AsCommand(name: 'db:migrations:list', description: 'List the migrations and their state')]
final class ListCommand extends Command
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
        if ([] === $status) {
            $io->note(\sprintf('No migration in %s yet: create one with db:migrations:generate.', $this->configuration->directory));

            return Command::SUCCESS;
        }

        $io->table(['Version', 'State', 'Executed at (UTC)', 'Duration', 'Description'], array_map(static fn (MigrationStatus $s): array => [
            $s->version,
            $s->state->value,
            $s->executedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? '',
            null === $s->executionTimeMs ? '' : $s->executionTimeMs . ' ms',
            $s->description,
        ], $status));

        return Command::SUCCESS;
    }
}
