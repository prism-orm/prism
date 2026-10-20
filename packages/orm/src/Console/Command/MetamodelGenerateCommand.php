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
 * Génère le metamodel du QueryBuilder typé (ADR 0009, RG-05.1, RG-05.2).
 *
 * @api
 */
#[AsCommand(name: 'db:metamodel:generate', description: 'Generate the metamodel classes of the typed query builder')]
final class MetamodelGenerateCommand extends Command
{
    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly string $directory = 'var/cache/prism/metamodel',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of the generated classes', $this->directory);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $dir = \is_string($dir) ? $dir : $this->directory;

        try {
            $result = new MetamodelGenerator()->generate($this->metadata->getAllMetadata(), $dir);
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
        $io->success(\sprintf('Metamodel in %s: %d written, %d unchanged, %d deleted.', $dir, \count($result->written), \count($result->unchanged), \count($result->deleted)));

        return Command::SUCCESS;
    }
}
