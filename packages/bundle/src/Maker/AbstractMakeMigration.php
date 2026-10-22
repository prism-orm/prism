<?php

declare(strict_types=1);

namespace Prism\Bundle\Maker;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * make:migration pour Prism : db:migrations:diff de l'EntityManager choisi, renommages proposés
 * en mode interactif (RG-06.3).
 *
 * @internal
 */
abstract class AbstractMakeMigration extends AbstractMaker
{
    /**
     * @param ContainerInterface $diffCommands   db:migrations:diff, par EntityManager
     * @param list<string>       $entityManagers
     */
    public function __construct(
        private readonly ContainerInterface $diffCommands,
        private readonly array $entityManagers,
        private readonly string $default,
    ) {
    }

    public static function getCommandDescription(): string
    {
        return 'Create a Prism migration from the changes of the entities (db:migrations:diff)';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addOption('em', null, InputOption::VALUE_REQUIRED, \sprintf('The entity manager: %s', implode(', ', $this->entityManagers)), $this->default)
            ->addOption('complete', null, InputOption::VALUE_NONE, 'Also drop the tables and sequences that are not mapped');
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $name = $input->getOption('em');
        if (!\is_string($name) || !\in_array($name, $this->entityManagers, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown entity manager "%s"; declared: %s.', \is_string($name) ? $name : '', implode(', ', $this->entityManagers)));
        }
        $diff = $this->diffCommands->get($name);
        \assert($diff instanceof Command);
        $arguments = true === $input->getOption('complete') ? ['--complete' => true] : [];
        $inner = new ArrayInput($arguments);
        $inner->setInteractive($input->isInteractive());

        if (Command::SUCCESS !== $diff->run($inner, $io)) {
            throw new \RuntimeException('db:migrations:diff failed: see above.');
        }
        $io->text('Review the generated migration, if any, then run <info>db:migrations:migrate</info>.');
    }
}
