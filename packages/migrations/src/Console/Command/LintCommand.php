<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Dbal\Connection;
use Prism\Migrations\Exception\MigrationsException;
use Prism\Migrations\Lint\Finding;
use Prism\Migrations\Lint\MigrationLinter;
use Prism\Migrations\Lint\Severity;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Analyse les migrations en attente avant leur exécution (RG-06.5). Échoue sur une erreur ; avec
 * --strict, aussi sur un avertissement (pour la CI). #[IgnoreLint] sur une migration ignore une
 * règle assumée, sa raison affichée.
 *
 * @internal
 */
#[AsCommand(name: 'db:migrations:lint', description: 'Find the dangerous operations of the pending migrations')]
final class LintCommand extends Command
{
    /**
     * @param MetadataFactory|null $mapping    pour les règles L03 à L05 (colonnes et tables encore mappées)
     * @param int                  $largeTable seuil, en lignes, d'une grande table (L10)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationsConfiguration $configuration,
        private readonly ?MetadataFactory $mapping = null,
        private readonly int $largeTable = 1_000_000,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Lint the pending migrations up to this version')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on warnings too');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $version = $input->getArgument('version');
        try {
            $result = new MigrationLinter($this->connection, $this->configuration->finder(), $this->mapping, $this->largeTable)->lint(\is_string($version) ? $version : null);
        } catch (MigrationsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ([] === $result->versions) {
            $io->success('No pending migration to lint.');

            return Command::SUCCESS;
        }
        if (!$result->mappingChecked) {
            $io->note('No mapping given: L03 to L05 (dropped or renamed columns and tables still mapped by an entity) were not checked.');
        }
        if ([] !== $result->findings) {
            $io->table(['Version', 'Code', 'Severity', 'Message'], array_map(static fn (Finding $f): array => [
                $f->version,
                $f->code,
                null === $f->ignoredBecause ? $f->severity->value : 'ignored',
                null === $f->ignoredBecause ? $f->message : $f->message . ' (ignored: ' . $f->ignoredBecause . ')',
            ], $result->findings));
        }

        $errors = \count($result->active(Severity::Error));
        $warnings = \count($result->active(Severity::Warning));
        $summary = \sprintf('%d pending migration(s) linted: %d error(s), %d warning(s).', \count($result->versions), $errors, $warnings);
        if ($errors > 0 || ($warnings > 0 && true === $input->getOption('strict'))) {
            $io->error($summary);

            return Command::FAILURE;
        }
        $warnings > 0 ? $io->warning($summary) : $io->success($summary);

        return Command::SUCCESS;
    }
}
