<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Orm\Audit\AuditLog;
use Prism\Orm\Behavior\SystemClock;
use Prism\Orm\Metadata\MetadataFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge du journal d'audit (RG-08.12) : les lignes plus anciennes que la rétention de leur
 * entité (#[Auditable(retention:)]), ou que --older-than pour celles qui n'en ont pas. Seule voie
 * de suppression d'une ligne d'audit ; rien sans --force.
 *
 * @internal
 */
#[AsCommand(name: 'db:audit:purge', description: 'Delete the audit log rows older than the retention of their entity')]
final class AuditPurgeCommand extends Command
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataFactory $metadata,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'ISO 8601 retention of the auditable entities without one, for example P1Y')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete the rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $default = $input->getOption('older-than');
        if (null !== $default && (!\is_string($default) || !self::isDuration($default))) {
            $io->error('--older-than must be an ISO 8601 duration, for example P1Y or P6M.');

            return Command::INVALID;
        }
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $plan = [];
        foreach ($this->metadata->getAllMetadata() as $class) {
            $retention = $class->behaviors->auditable ? ($class->behaviors->auditRetention ?? $default) : null;
            if (null === $retention) {
                continue;
            }
            $before = $now->sub(new \DateInterval($retention));
            $qb = $this->connection->createQueryBuilder();
            $count = $qb->select('COUNT(*)')->from(AuditLog::TABLE)
                ->where($qb->expr()->eq($qb->column('entity_class'), $class->className))
                ->andWhere($qb->expr()->lt($qb->column('occurred_at'), $before, 'datetime_immutable'))
                ->executeQuery()->fetchOne();
            $plan[] = [$class->className, $retention, $before, is_numeric($count) ? (int) $count : 0];
        }
        if ([] === $plan) {
            $io->note('No auditable entity has a retention: give one with #[Auditable(retention: ...)] or --older-than.');

            return Command::SUCCESS;
        }

        $io->table(['Entity', 'Retention', 'Rows before', 'Rows'], array_map(static fn (array $p): array => [$p[0], $p[1], $p[2]->format('Y-m-d H:i:s'), $p[3]], $plan));
        $total = array_sum(array_column($plan, 3));
        if (true !== $input->getOption('force')) {
            $io->caution(\sprintf('This would delete %d audit log row(s); nothing was done. Run with --force to delete them.', $total));

            return Command::SUCCESS;
        }
        foreach ($plan as [$class, , $before]) {
            $qb = $this->connection->createQueryBuilder();
            $qb->delete(AuditLog::TABLE)
                ->where($qb->expr()->eq($qb->column('entity_class'), $class))
                ->andWhere($qb->expr()->lt($qb->column('occurred_at'), $before, 'datetime_immutable'))
                ->executeStatement();
        }
        $io->success(\sprintf('%d audit log row(s) deleted.', $total));

        return Command::SUCCESS;
    }

    private static function isDuration(string $value): bool
    {
        try {
            new \DateInterval($value);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
