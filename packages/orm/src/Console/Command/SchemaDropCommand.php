<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Schema;
use Prism\Orm\Console\MappedSchema;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les tables et séquences du mapping (et seulement elles). Destructrice : --force.
 *
 * @api
 */
#[AsCommand(name: 'db:schema:drop', description: 'Drop the tables and sequences of the mapping')]
final class SchemaDropCommand extends Command
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
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the SQL instead of executing it')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Execute the SQL (destroys the data of the mapped tables)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $diff = $this->schema->diff($this->schema->actual($this->schema->target()), new Schema());

        if ($diff->isEmpty()) {
            $io->success('Nothing to drop: no mapped table exists.');

            return Command::SUCCESS;
        }
        if (true === $input->getOption('dump-sql')) {
            SqlOutput::write($io, $this->schema->sql($diff));

            return Command::SUCCESS;
        }
        if (true !== $input->getOption('force')) {
            $io->caution(\sprintf("This drops %d table(s) and all their data: %s.\nRun with --force to execute, or --dump-sql to see the SQL.", \count($diff->droppedTables), implode(', ', array_map(static fn ($t): string => $t->name, $diff->droppedTables))));

            return Command::INVALID;
        }

        $executed = $this->schema->migrate($diff);
        $io->success(\sprintf('Schema dropped: %d queries executed.', \count($executed)));

        return Command::SUCCESS;
    }
}
