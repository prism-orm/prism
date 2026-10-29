<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\DbalException;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Orm\Tools\Import\EntityImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Génère les entités d'une base existante (RG-06.10) : final, typées, relations déduites des
 * clés étrangères ; un fichier existant n'est jamais remplacé sans --force. Les tables que Prism
 * gère (prism_) et celles des migrations Doctrine sont écartées par défaut.
 *
 * @internal
 */
#[AsCommand(name: 'db:import', description: 'Generate the entities of an existing database')]
final class ImportCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace of the entities', 'App\\Entity')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of the entity files', 'src/Entity')
            ->addOption('strip-prefix', null, InputOption::VALUE_REQUIRED, 'Table prefix left out of the class names', '')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Regex of tables to leave out (added to prism_ and doctrine_migration_versions)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the entities instead of writing them')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace the existing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $namespace = trim(self::string($input->getOption('namespace'), 'App\\Entity'), '\\');
        $dir = rtrim(self::string($input->getOption('dir'), 'src/Entity'), '/\\');
        $excludes = ['/^prism_/i', '/^doctrine_migration_versions$/i'];
        foreach ((array) $input->getOption('exclude') as $exclude) {
            $pattern = '/' . str_replace('/', '\/', self::string($exclude, '')) . '/i';
            if (false === @preg_match($pattern, '')) {
                $io->error(\sprintf('Invalid --exclude pattern "%s".', self::string($exclude, '')));

                return Command::INVALID;
            }
            $excludes[] = $pattern;
        }

        $schemaManager = $this->connection->createSchemaManager();
        try {
            $all = $schemaManager->introspect();
        } catch (DbalException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $kept = array_values(array_filter($all->getTables(), static fn (Table $t): bool => [] === array_filter($excludes, static fn (string $p): bool => 1 === preg_match($p, $t->name))));
        $result = new EntityImporter()->import(new Schema($kept), $namespace, self::string($input->getOption('strip-prefix'), ''), $schemaManager->getIntrospectionNotes());

        if (true === $input->getOption('dry-run')) {
            foreach ($result->entities as $file => $code) {
                $io->section($dir . '/' . $file);
                $io->writeln($code);
            }
        } else {
            $force = true === $input->getOption('force');
            $written = 0;
            $existing = [];
            foreach ($result->entities as $file => $code) {
                $path = $dir . '/' . $file;
                if (is_file($path) && !$force) {
                    $existing[] = $path;
                    continue;
                }
                if ((!is_dir($dir) && !@mkdir($dir, 0o777, true)) || false === @file_put_contents($path, $code)) {
                    $io->error(\sprintf('Cannot write %s: check the permissions of %s.', $path, $dir));

                    return Command::FAILURE;
                }
                ++$written;
            }
            if ([] !== $existing) {
                $io->warning(\sprintf("%d file(s) already exist and were kept (RG-06.10); pass --force to replace them:\n%s", \count($existing), implode("\n", $existing)));
            }
            $io->writeln(\sprintf(' %d entit%s written to %s.', $written, 1 === $written ? 'y' : 'ies', $dir));
        }

        foreach ($result->skipped as $table => $reason) {
            $io->writeln(\sprintf(' <comment>skipped</comment> %s: %s', $table, $reason));
        }
        foreach ([...$result->foreignKeys, ...$result->approximations] as $where => $reason) {
            $io->writeln(\sprintf(' <comment>note</comment>    %s: %s', $where, $reason));
        }
        $io->success(\sprintf('%d table(s) imported, %d skipped. Review the entities, then run db:metamodel:generate.', \count($result->entities), \count($result->skipped)));

        return Command::SUCCESS;
    }

    private static function string(mixed $value, string $default): string
    {
        return \is_string($value) ? $value : $default;
    }
}
