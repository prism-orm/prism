<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Migrations\Console\MigrationFile;
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
        $version = MigrationFile::version(($this->now)());
        $file = MigrationFile::write($io, $this->configuration, $version, self::template(trim($this->configuration->namespace, '\\'), $version));
        if (null === $file) {
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
