<?php

declare(strict_types=1);

namespace Prism\Migrations\Console;

use Prism\Migrations\ExecutedMigration;
use Prism\Migrations\MigrationsConfiguration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * L'affichage partagé de migrate et execute : la liste des migrations, leur SQL, l'annonce des
 * migrations sans transaction (RG-06.6), la confirmation en prod (RG-06.9).
 *
 * @internal
 */
final class MigrationOutput
{
    /**
     * @param list<ExecutedMigration> $migrations
     */
    public static function plan(SymfonyStyle $io, array $migrations, bool $withSql): void
    {
        foreach ($migrations as $migration) {
            $io->writeln(\sprintf(' <info>%-4s</info> %s%s', $migration->direction->value, $migration->version, $migration->transactional ? '' : ' <comment>(without transaction)</comment>'));
            if ($withSql) {
                foreach ($migration->sql as $sql) {
                    $io->writeln('      ' . $sql . ';');
                }
            }
        }
        $io->newLine();
    }

    /**
     * @param list<ExecutedMigration> $migrations
     */
    public static function warnNonTransactional(SymfonyStyle $io, array $migrations): void
    {
        $outside = array_filter($migrations, static fn (ExecutedMigration $m): bool => !$m->transactional);
        if ([] !== $outside) {
            $io->warning(\sprintf('%d migration(s) will run without a transaction: if one fails, the statements before the failure stay applied and the database is left half-migrated (RG-06.6).', \count($outside)));
        }
    }

    /**
     * RG-06.9 : en prod, une question ; sans interaction (--no-interaction), aucune.
     */
    public static function confirmed(SymfonyStyle $io, InputInterface $input, MigrationsConfiguration $configuration, string $question): bool
    {
        if (!$configuration->isProduction() || !$input->isInteractive()) {
            return true;
        }

        return $io->confirm($question, false);
    }

    /**
     * @param list<ExecutedMigration> $migrations
     */
    public static function executed(SymfonyStyle $io, array $migrations): void
    {
        foreach ($migrations as $migration) {
            $io->writeln(\sprintf(' <info>✔</info> %-4s %s <comment>(%d ms)</comment>', $migration->direction->value, $migration->version, $migration->executionTimeMs ?? 0));
            if ($io->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                foreach ($migration->sql as $sql) {
                    $io->writeln('      ' . $sql . ';');
                }
            }
        }
    }
}
