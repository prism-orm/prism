<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vide le cache PSR-6 de la metadata : les attributs sont relus à la prochaine utilisation.
 *
 * @api
 */
#[AsCommand(name: 'db:cache:clear-metadata', description: 'Clear the metadata cache')]
final class CacheClearMetadataCommand extends Command
{
    public function __construct(private readonly MetadataFactory $metadata)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->metadata->hasCache()) {
            $io->note('No metadata cache is configured: the attributes are read on each run, there is nothing to clear.');

            return Command::SUCCESS;
        }
        $this->metadata->clear();
        $io->success('Metadata cache cleared.');

        return Command::SUCCESS;
    }
}
