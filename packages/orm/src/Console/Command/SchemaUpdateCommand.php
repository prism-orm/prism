<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Orm\Console\MappedSchema;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synchronise la base avec le mapping, sans migrations (dev). RG-02.9 : sans --force, rien
 * n'est exécuté ; --complete est nécessaire pour supprimer les tables non mappées.
 *
 * @api
 */
#[AsCommand(name: 'db:schema:update', description: 'Bring the database schema in line with the mapping')]
final class SchemaUpdateCommand extends Command
{
    private readonly MappedSchema $schema;

    public function __construct(Connection $connection, MetadataFactory $metadata)
    {
        $this->schema = new MappedSchema($connection, $metadata);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the SQL that would be executed')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Execute the SQL')
            ->addOption('complete', null, InputOption::VALUE_NONE, 'Also drop the tables and sequences that are not mapped');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $this->schema->target();
        $complete = true === $input->getOption('complete');
        $diff = $this->schema->diff($this->schema->actual($target, $complete), $target);

        if ($diff->isEmpty()) {
            $io->success('Nothing to update: the database is in sync with the mapping.');

            return Command::SUCCESS;
        }

        $sql = $this->schema->sql($diff);
        $dump = true === $input->getOption('dump-sql');
        $force = true === $input->getOption('force');

        if ($dump) {
            SqlOutput::write($io, $sql);
        }
        if ($complete && [] !== $diff->droppedTables) {
            $io->warning(\sprintf('--complete drops %d unmapped table(s) and their data: %s.', \count($diff->droppedTables), implode(', ', array_map(static fn ($t): string => $t->name, $diff->droppedTables))));
        }
        if ($force) {
            $executed = $this->schema->migrate($diff);
            $io->success(\sprintf('Database schema updated: %d queries executed.', \count($executed)));

            return Command::SUCCESS;
        }
        if ($dump) {
            return Command::SUCCESS;
        }

        // des changements en attente sans option : un script ne doit pas croire le schéma à jour
        $io->caution(\sprintf("%d queries are needed to update the schema; nothing was executed.\nRun with --dump-sql to see them, --force to execute them.", \count($sql)));

        return Command::INVALID;
    }
}
