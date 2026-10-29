<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Vector\EmbeddingProviderRegistry;
use Prism\Orm\Vector\EmbeddingUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Par champ #[EmbeddedVector] : lignes à jour, lignes périmées, et lignes par modèle (ADR 0018).
 *
 * @internal
 */
#[AsCommand(name: 'db:embeddings:status', description: 'Count the up-to-date and stale #[EmbeddedVector] vectors')]
final class EmbeddingsStatusCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataFactory $metadata,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('class', null, InputOption::VALUE_REQUIRED, 'Only this entity (class name, or its short name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('class');
        $only = \is_string($only) ? $only : null;
        $targets = EmbeddingTargets::find($this->metadata, $only);
        if ([] === $targets) {
            $io->note(null === $only ? 'No entity has an #[EmbeddedVector] field.' : \sprintf('No #[EmbeddedVector] field on "%s".', $only));

            return null === $only ? Command::SUCCESS : Command::INVALID;
        }

        // aucun calcul : le registre des fournisseurs n'est pas lu
        $updater = new EmbeddingUpdater($this->connection, $this->metadata, new EmbeddingProviderRegistry());
        $rows = [];
        $stale = 0;
        foreach ($targets as [$class, $field]) {
            $status = $updater->status($class, $field);
            $models = [];
            foreach ($status->models as $model => $count) {
                $models[] = \sprintf('%s: %d', '' === $model ? '(none)' : $model, $count);
            }
            $rows[] = [$class . '::$' . $field, $status->fresh, $status->stale, implode(', ', $models)];
            $stale += $status->stale;
        }
        $io->table(['Field', 'Up to date', 'Stale', 'Rows by model'], $rows);
        if ($stale > 0) {
            $io->note(\sprintf('%d stale vector(s): they are left out of nearest() until db:embeddings:compute (or the ComputeEmbedding messages) computes them.', $stale));
        }

        return Command::SUCCESS;
    }
}
