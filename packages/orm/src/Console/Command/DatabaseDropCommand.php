<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\DbalException;
use Prism\Dbal\Platform\SqlitePlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime la base de la connexion et toutes ses données : rien sans --force (« toute commande
 * destructrice exige --force »), une confirmation en prod.
 *
 * @internal
 */
#[AsCommand(name: 'db:database:drop', description: 'Drop the database of the connection, with all its data')]
final class DatabaseDropCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $environment = 'dev',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Drop the database')
            ->addOption('if-exists', null, InputOption::VALUE_NONE, 'Do nothing if the database does not exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $database = $this->connection->getDatabase();
        if (null === $database || ':memory:' === $database) {
            $io->error(null === $database ? 'The connection URL names no database.' : 'The database is in memory: there is nothing to drop.');

            return Command::FAILURE;
        }
        if (true !== $input->getOption('force')) {
            $io->caution(\sprintf('This would drop the database "%s" and all its data; nothing was done. Run with --force to drop it.', $database));

            return Command::INVALID;
        }
        if ('prod' === $this->environment && $input->isInteractive() && !$io->confirm(\sprintf('Drop the prod database "%s" and all its data?', $database), false)) {
            $io->warning('Cancelled: nothing was dropped.');

            return Command::FAILURE;
        }
        $ifExists = true === $input->getOption('if-exists');

        try {
            // la base ne se supprime pas tant qu'une connexion y est ouverte
            $this->connection->close();
            if ($this->connection->getPlatform() instanceof SqlitePlatform) {
                if (!is_file($database)) {
                    return $this->missing($io, $database, $ifExists);
                }
                if (!@unlink($database)) {
                    $io->error(\sprintf('Cannot delete the SQLite file %s.', $database));

                    return Command::FAILURE;
                }
            } else {
                $server = $this->connection->withoutDatabase();
                try {
                    $platform = $server->getPlatform();
                    if (!\in_array($database, $server->executeQuery($platform->getListDatabasesSql())->fetchFirstColumn(), true)) {
                        return $this->missing($io, $database, $ifExists);
                    }
                    $server->executeStatement($platform->getDropDatabaseSql($database));
                } finally {
                    $server->close();
                }
            }
        } catch (DbalException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Database "%s" dropped.', $database));

        return Command::SUCCESS;
    }

    private function missing(SymfonyStyle $io, string $database, bool $ifExists): int
    {
        if ($ifExists) {
            $io->note(\sprintf('Database "%s" does not exist: nothing to do.', $database));

            return Command::SUCCESS;
        }
        $io->error(\sprintf('Database "%s" does not exist; pass --if-exists to accept it.', $database));

        return Command::FAILURE;
    }
}
