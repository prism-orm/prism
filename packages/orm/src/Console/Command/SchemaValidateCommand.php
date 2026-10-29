<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Orm\Console\MappedSchema;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\MappingError;
use Prism\Orm\Metadata\MappingSeverity;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Metadata\MetadataValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vérifie le mapping (toutes les erreurs), puis que la base lui correspond.
 *
 * @internal
 */
#[AsCommand(name: 'db:schema:validate', description: 'Validate the mapping and check that the database is in sync with it')]
final class SchemaValidateCommand extends Command
{
    private readonly MappedSchema $schema;

    public function __construct(Connection $connection, MetadataFactory $metadata)
    {
        $this->schema = new MappedSchema($connection, $metadata);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-sync', null, InputOption::VALUE_NONE, 'Only validate the mapping, without connecting to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->section('Mapping');
        $metadata = [];

        try {
            $metadata = $this->schema->getMetadataFactory()->getAllMetadata();
            $found = new MetadataValidator($this->schema->getConnection()->getTypeRegistry(), $this->schema->getConnection()->getPlatform())->validate($metadata);
            $errors = array_values(array_map(static fn (MappingError $e): string => (string) $e, array_filter($found, static fn (MappingError $e): bool => MappingSeverity::Error === $e->severity)));
            $warnings = array_values(array_map(static fn (MappingError $e): string => (string) $e, array_filter($found, static fn (MappingError $e): bool => MappingSeverity::Warning === $e->severity)));
        } catch (MappingException $e) {
            // erreur du driver : une classe dont la metadata ne peut pas être construite
            $errors = [$e->getMessage()];
            $warnings = [];
        }
        if ([] !== $warnings) {
            $io->warning(\sprintf('%d mapping warning%s.', \count($warnings), 1 === \count($warnings) ? '' : 's'));
            $io->listing($warnings);
        }

        if ([] !== $errors) {
            $io->error(\sprintf('The mapping is invalid (%d error%s).', \count($errors), 1 === \count($errors) ? '' : 's'));
            $io->listing($errors);

            return Command::FAILURE;
        }
        $io->success(\sprintf('The mapping of %d entities is valid.', \count($metadata)));

        if (true === $input->getOption('skip-sync')) {
            return Command::SUCCESS;
        }

        $io->section('Database');
        $target = $this->schema->target();
        $pending = $this->schema->sql($this->schema->diff($this->schema->actual($target), $target));
        if ([] !== $pending) {
            $io->error(\sprintf('The database is not in sync with the mapping: %d queries pending. Run db:schema:update --dump-sql to see them.', \count($pending)));

            return Command::FAILURE;
        }
        $io->success('The database is in sync with the mapping.');

        return Command::SUCCESS;
    }
}
