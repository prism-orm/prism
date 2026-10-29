<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Orm\Exception\EmbeddingException;
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
 * Recalcule les vecteurs #[EmbeddedVector] périmés (ADR 0018), de toutes les entités ou d'une
 * seule ; --all recalcule aussi les vecteurs à jour (changement de fournisseur sans migration).
 * Lancée par bin/prism ou le bundle, elle voit tous les tenants (mode système).
 *
 * @internal
 */
#[AsCommand(name: 'db:embeddings:compute', description: 'Compute the stale #[EmbeddedVector] vectors')]
final class EmbeddingsComputeCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataFactory $metadata,
        private readonly EmbeddingProviderRegistry $providers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Only this entity (class name, or its short name)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Texts sent to the provider per call', '64')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also compute the vectors that are up to date');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = $input->getOption('batch-size');
        if (!\is_string($batchSize) || !ctype_digit($batchSize) || (int) $batchSize < 1) {
            $io->error('--batch-size must be a positive integer.');

            return Command::INVALID;
        }
        $only = $input->getOption('class');
        $only = \is_string($only) ? $only : null;
        $targets = EmbeddingTargets::find($this->metadata, $only);
        if ([] === $targets) {
            $io->note(null === $only ? 'No entity has an #[EmbeddedVector] field.' : \sprintf('No #[EmbeddedVector] field on "%s".', $only));

            return null === $only ? Command::SUCCESS : Command::INVALID;
        }

        $updater = new EmbeddingUpdater($this->connection, $this->metadata, $this->providers, batchSize: (int) $batchSize);
        $all = true === $input->getOption('all');
        $total = 0;
        foreach ($targets as [$class, $field]) {
            $progress = $io->createProgressBar();
            $progress->setMessage(\sprintf('%s::$%s', $class, $field));
            try {
                $written = $updater->computeAll($class, $field, $all, static function (int $rows) use ($progress): void {
                    $progress->advance($rows);
                });
            } catch (EmbeddingException $e) {
                $progress->clear();
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $progress->finish();
            $io->newLine();
            $io->writeln(\sprintf('%s::$%s: %d vector(s) computed.', $class, $field, $written));
            $total += $written;
        }
        $io->success(\sprintf('%d vector(s) computed.', $total));

        return Command::SUCCESS;
    }
}
