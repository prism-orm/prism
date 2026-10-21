<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Migrations\MigrationsConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée une migration vide, nommée d'après l'heure UTC (RG-06.1).
 *
 * @api
 */
#[AsCommand(name: 'db:migrations:generate', description: 'Create an empty migration')]
final class GenerateCommand extends Command
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $now l'heure de la version ; UTC par défaut
     */
    public function __construct(
        private readonly MigrationsConfiguration $configuration,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $version = 'Version' . ($this->now)()->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis');
        $directory = rtrim($this->configuration->directory, '/\\');
        $file = $directory . '/' . $version . '.php';

        if (is_file($file)) {
            $io->error(\sprintf('%s already exists: wait a second and run the command again.', $file));

            return Command::FAILURE;
        }
        if ((!is_dir($directory) && !@mkdir($directory, 0o777, true)) || false === @file_put_contents($file, self::template(trim($this->configuration->namespace, '\\'), $version))) {
            $io->error(\sprintf('Cannot write %s: check the permissions of %s.', $file, $directory));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Migration %s created: %s', $version, $file));

        return Command::SUCCESS;
    }

    private static function template(string $namespace, string $version): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Prism\\Migrations\\AbstractMigration;
            use Prism\\Migrations\\MigrationBuilder;

            final class {$version} extends AbstractMigration
            {
                public function getDescription(): string
                {
                    return '';
                }

                public function up(MigrationBuilder \$m): void
                {
                }

                // sans down(), la migration est irréversible : l'écrire pour pouvoir la revertir (RG-06.7)
            }

            PHP;
    }
}
