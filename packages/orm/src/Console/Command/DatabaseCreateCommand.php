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
 * Crée la base nommée dans l'URL de connexion, par une connexion au serveur sans elle. Sous
 * SQLite, la base est un fichier : il est créé.
 *
 * @api
 */
#[AsCommand(name: 'db:database:create', description: 'Create the database of the connection')]
final class DatabaseCreateCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('if-not-exists', null, InputOption::VALUE_NONE, 'Do nothing if the database already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $database = $this->connection->getDatabase();
        $ifNotExists = true === $input->getOption('if-not-exists');
        if (null === $database) {
            $io->error('The connection URL names no database.');

            return Command::FAILURE;
        }

        try {
            if ($this->connection->getPlatform() instanceof SqlitePlatform) {
                return $this->createFile($io, $database, $ifNotExists);
            }
            $server = $this->connection->withoutDatabase();
            try {
                $platform = $server->getPlatform();
                if (\in_array($database, $server->executeQuery($platform->getListDatabasesSql())->fetchFirstColumn(), true)) {
                    return $this->exists($io, $database, $ifNotExists);
                }
                $server->executeStatement($platform->getCreateDatabaseSql($database));
            } finally {
                $server->close();
            }
        } catch (DbalException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Database "%s" created.', $database));

        return Command::SUCCESS;
    }

    private function createFile(SymfonyStyle $io, string $file, bool $ifNotExists): int
    {
        if (':memory:' === $file) {
            $io->note('The database is in memory: there is nothing to create.');

            return Command::SUCCESS;
        }
        if (is_file($file)) {
            return $this->exists($io, $file, $ifNotExists);
        }
        if ((!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0o777, true)) || !@touch($file)) {
            $io->error(\sprintf('Cannot create the SQLite file %s: check the permissions of its directory.', $file));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Database file "%s" created.', $file));

        return Command::SUCCESS;
    }

    private function exists(SymfonyStyle $io, string $database, bool $ifNotExists): int
    {
        if ($ifNotExists) {
            $io->note(\sprintf('Database "%s" already exists: nothing to do.', $database));

            return Command::SUCCESS;
        }
        $io->error(\sprintf('Database "%s" already exists; pass --if-not-exists to accept it.', $database));

        return Command::FAILURE;
    }
}
