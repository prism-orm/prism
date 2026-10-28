<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Table;
use Prism\Orm\Console\MappedSchema;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(name: 'db:schema:create', description: 'Create the tables, indexes, foreign keys and sequences of the mapping')]
final class SchemaCreateCommand extends Command
{
    private readonly MappedSchema $schema;

    public function __construct(Connection $connection, MetadataFactory $metadata)
    {
        $this->schema = new MappedSchema($connection, $metadata);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the SQL instead of executing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $this->schema->target();
        $actual = $this->schema->actual($target);

        // pas de création à moitié : update sait compléter une base existante
        if ([] !== $existing = array_map(static fn (Table $t): string => $t->name, $actual->getTables())) {
            $io->error(\sprintf("These tables already exist: %s.\nRun db:schema:update to bring an existing database in line with the mapping.", implode(', ', $existing)));

            return Command::FAILURE;
        }

        $diff = $this->schema->diff($actual, $target);
        if (true === $input->getOption('dump-sql')) {
            SqlOutput::write($io, $this->schema->sql($diff));

            return Command::SUCCESS;
        }

        $executed = $this->schema->migrate($diff);
        $io->success(\sprintf('Schema created: %d tables, %d queries executed.', \count($target->getTables()), \count($executed)));

        return Command::SUCCESS;
    }
}
