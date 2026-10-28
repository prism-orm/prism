<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Les entités mappées : classe, table, repository ; celles dont le mapping est invalide, avec
 * l'erreur.
 *
 * @internal
 */
#[AsCommand(name: 'db:mapping:info', description: 'List the mapped entities')]
final class MappingInfoCommand extends Command
{
    public function __construct(private readonly MetadataFactory $metadata)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $classes = $this->metadata->getAllClassNames();
        } catch (MappingException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ([] === $classes) {
            $io->note('No mapped entity found: check the entity directories of the metadata driver.');

            return Command::SUCCESS;
        }

        $rows = [];
        $invalid = [];
        foreach ($classes as $class) {
            try {
                $metadata = $this->metadata->getMetadataFor($class);
                $rows[] = [$class, $metadata->tableName, $metadata->repositoryClass ?? '-'];
            } catch (MappingException $e) {
                $rows[] = [$class, '<error>invalid</error>', ''];
                $invalid[] = $e->getMessage();
            }
        }
        $io->table(['Entity', 'Table', 'Repository'], $rows);
        foreach ($invalid as $message) {
            $io->error($message);
        }
        if ([] !== $invalid) {
            return Command::FAILURE;
        }
        $io->success(\sprintf('%d mapped entit%s.', \count($classes), 1 === \count($classes) ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
