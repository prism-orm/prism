<?php

declare(strict_types=1);

namespace Prism\Migrations\Console\Command;

use Prism\Migrations\Console\MigrationFile;
use Prism\Migrations\MigrationsConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée une migration vide, nommée d'après l'heure UTC (RG-06.1) ; --data, une migration de
 * données (RG-06.8).
 *
 * @internal
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

    protected function configure(): void
    {
        $this
            ->addOption('data', null, InputOption::VALUE_NONE, 'Create a data migration (VersionYYYYMMDDHHMMSSData), run with the EntityManager after the schema (RG-06.8)')
            ->addOption('for', null, InputOption::VALUE_REQUIRED, 'With --data: the schema migration it follows (VersionYYYYMMDDHHMMSS); a new version otherwise');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $data = true === $input->getOption('data');
        $for = $input->getOption('for');
        if (null !== $for && (!$data || !\is_string($for) || 1 !== preg_match('/^Version\d{14}$/', $for))) {
            $io->error('--for needs --data and a schema version such as Version20261021143000.');

            return Command::INVALID;
        }
        $version = (\is_string($for) ? $for : MigrationFile::version(($this->now)())) . ($data ? 'Data' : '');
        $namespace = trim($this->configuration->namespace, '\\');
        $file = MigrationFile::write($io, $this->configuration, $version, $data ? self::dataTemplate($namespace, $version) : self::template($namespace, $version));
        if (null === $file) {
            return Command::FAILURE;
        }
        $io->success(\sprintf('Migration %s created: %s', $version, $file));

        return Command::SUCCESS;
    }

    private static function dataTemplate(string $namespace, string $version): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Prism\\Migrations\\DataMigration;
            use Prism\\Orm\\EntityManagerInterface;

            // après la migration de schéma de la même version, dans une transaction (RG-06.8) ; les
            // entités sont celles d'aujourd'hui : pour une migration qui doit durer, préférer le SQL
            // de \$em->getConnection()
            final class {$version} extends DataMigration
            {
                public function getDescription(): string
                {
                    return '';
                }

                public function run(EntityManagerInterface \$em): void
                {
                }

                // sans revert(), la migration est irréversible : l'écrire pour pouvoir la revertir (RG-06.7)
            }

            PHP;
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
