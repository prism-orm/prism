<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exécute une requête SQL brute. Une lecture affiche ses lignes ; toute autre requête exige
 * --force. Les valeurs passent par --param, liées, jamais dans le texte de la requête.
 *
 *     db:query:sql "SELECT * FROM invoice WHERE status = :status" --param status=paid
 *
 * @internal
 */
#[AsCommand(name: 'db:query:sql', description: 'Run a raw SQL query')]
final class QuerySqlCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sql', InputArgument::REQUIRED, 'The SQL query, with :named parameters')
            ->addOption('param', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A bound parameter, name=value')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run a query that is not a read')
            ->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'The most rows to print', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sql = $input->getArgument('sql');
        $sql = \is_string($sql) ? trim($sql) : '';
        $params = [];
        foreach ((array) $input->getOption('param') as $param) {
            if (!\is_string($param) || !str_contains($param, '=')) {
                $io->error(\sprintf('Invalid --param "%s": expected name=value.', \is_string($param) ? $param : ''));

                return Command::INVALID;
            }
            [$name, $value] = explode('=', $param, 2);
            $params[$name] = $value;
        }
        $maxRows = $input->getOption('max-rows');
        $maxRows = is_numeric($maxRows) ? max(1, (int) $maxRows) : 100;

        try {
            if (self::isRead($sql)) {
                $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
                if ([] === $rows) {
                    $io->note('No rows.');

                    return Command::SUCCESS;
                }
                $io->table(array_map(strval(...), array_keys($rows[0])), array_map(
                    static fn (array $row): array => array_map(static fn (mixed $v): string => null === $v ? 'NULL' : (\is_scalar($v) ? (string) $v : get_debug_type($v)), array_values($row)),
                    \array_slice($rows, 0, $maxRows),
                ));
                $io->writeln(\sprintf(' %d row(s)%s.', \count($rows), \count($rows) > $maxRows ? \sprintf(', %d printed', $maxRows) : ''));

                return Command::SUCCESS;
            }
            if (true !== $input->getOption('force')) {
                $io->caution('This query is not a read: nothing was executed. Run with --force to execute it.');

                return Command::INVALID;
            }
            $affected = $this->connection->executeStatement($sql, $params);
        } catch (DbalException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d row(s) affected.', $affected));

        return Command::SUCCESS;
    }

    // prudent : une lecture commence par l'un de ces mots, et un WITH ne modifie rien
    private static function isRead(string $sql): bool
    {
        if (1 === preg_match('/^\(*\s*(SELECT|SHOW|EXPLAIN|VALUES|DESCRIBE)\b/i', $sql)) {
            return true;
        }

        return 1 === preg_match('/^WITH\b/i', $sql) && 0 === preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|DROP|ALTER|CREATE)\b/i', $sql);
    }
}
