<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\MetamodelException;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\MetamodelGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Génère le metamodel du QueryBuilder typé (ADR 0009, RG-05.1, RG-05.2), et l'aide aux IDE : son
 * stub hors de var/, et les repositories pour PhpStorm.
 *
 * @api
 */
#[AsCommand(name: 'db:metamodel:generate', description: 'Generate the metamodel classes of the typed query builder')]
final class MetamodelGenerateCommand extends Command
{
    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly string $directory = 'var/cache/prism/metamodel',
        private readonly string $ideStub = '.prism/metamodel.php',
        private readonly string $phpStormMeta = '.phpstorm.meta.php',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of the generated classes', $this->directory);
        $this->addOption('ide-stub', null, InputOption::VALUE_REQUIRED, 'Stub of the metamodel for IDEs, outside the directories they exclude (add it to .gitignore)', $this->ideStub);
        $this->addOption('phpstorm-meta', null, InputOption::VALUE_REQUIRED, 'PhpStorm metadata directory, where prism.php maps entities to their repository', $this->phpStormMeta);
        $this->addOption('no-ide', null, InputOption::VALUE_NONE, 'Generate the metamodel only, without the IDE helpers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $dir = \is_string($dir) ? $dir : $this->directory;

        $stub = $input->getOption('ide-stub');
        $meta = $input->getOption('phpstorm-meta');
        $ide = true !== $input->getOption('no-ide');

        try {
            $metadata = $this->metadata->getAllMetadata();
            $generator = new MetamodelGenerator();
            $result = $generator->generate($metadata, $dir);
            $helpers = $ide ? $generator->generateIdeHelpers($metadata, \is_string($stub) ? $stub : $this->ideStub, \is_string($meta) ? $meta : $this->phpStormMeta) : null;
        } catch (MappingException|MetamodelException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->written as $file) {
            $io->writeln(' <info>written</info>   ' . $file);
        }
        foreach ($result->deleted as $file) {
            $io->writeln(' <comment>deleted</comment>   ' . $file);
        }
        foreach ($helpers->written ?? [] as $file) {
            $io->writeln(' <info>written</info>   ' . $file);
        }
        $io->success(\sprintf('Metamodel in %s: %d written, %d unchanged, %d deleted.', $dir, \count($result->written), \count($result->unchanged), \count($result->deleted)));

        return Command::SUCCESS;
    }
}
