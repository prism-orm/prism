<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Dbal\Connection;
use Prism\Migrations\Console\MigrationFile;
use Prism\Migrations\Generator\DiffTranslator;
use Prism\Migrations\Generator\MigrationWriter;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Orm\Console\MappedSchema;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Génère la migration qui mène la base au schéma des entités (ADR 0012) : renommages confirmés
 * (RG-06.3), down() seulement s'il est sûr (RG-06.7), aucun fichier sans changement (RG-06.2).
 * Comme db:schema:update, les tables non mappées sont ignorées sans --complete ; celles que
 * Prism gère (prism_) le sont toujours.
 *
 * @internal
 */
#[AsCommand(name: 'db:migrations:diff', description: 'Generate a migration from the difference between the entities and the database')]
final class DiffCommand extends Command
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $now l'heure de la version ; UTC par défaut
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataFactory $metadata,
        private readonly MigrationsConfiguration $configuration,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('complete', null, InputOption::VALUE_NONE, 'Also drop the tables and sequences that are not mapped (except the prism_ tables)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the migration instead of writing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $schema = new MappedSchema($this->connection, $this->metadata);
        try {
            $target = $schema->target();
        } catch (MappingException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        // la base entière : une table renommée n'est plus mappée sous son ancien nom (RG-06.3)
        $actual = $schema->actual($target, complete: true);

        // RG-06.3 : une question par renommage possible ; sans interaction, des avertissements
        $confirm = $input->isInteractive() ? static fn (string $question): bool => $io->confirm($question, true) : null;
        $migration = new DiffTranslator($this->connection->getPlatform())->translate($actual, $target, $confirm, dropUnmapped: true === $input->getOption('complete'));
        if ($migration->isEmpty()) {
            $io->success('No changes: the database matches the entities, no migration generated.');

            return Command::SUCCESS;
        }

        $version = MigrationFile::version(($this->now)());
        $code = new MigrationWriter()->write($migration, trim($this->configuration->namespace, '\\'), $version);
        if (true === $input->getOption('dry-run')) {
            $io->writeln($code);

            return Command::SUCCESS;
        }
        $file = MigrationFile::write($io, $this->configuration, $version, $code);
        if (null === $file) {
            return Command::FAILURE;
        }
        foreach ($migration->warnings as $warning) {
            $io->warning($warning);
        }
        if (!$migration->isReversible()) {
            $io->note(\sprintf('Its down() is not generated: %s (RG-06.7).', implode('; ', $migration->irreversible)));
        }
        $io->success(\sprintf('Migration %s generated with %d operation(s): %s. Review it, then run db:migrations:migrate.', $version, \count($migration->up), $file));

        return Command::SUCCESS;
    }
}
