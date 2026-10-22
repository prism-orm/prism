<?php

declare(strict_types=1);

namespace Prism\Bundle\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Une commande db:* de bin/console (ADR 0013) : la définition de la commande d'origine, plus
 * --em ; l'exécution est déléguée à l'instance câblée sur l'EntityManager demandé. Les commandes
 * des paquets orm et migrations ne changent pas.
 *
 * @internal
 */
final class EntityManagerCommand extends Command
{
    private readonly Command $default;

    /**
     * @param ContainerInterface $commands       la commande d'origine, par nom d'EntityManager
     * @param list<string>       $entityManagers
     */
    public function __construct(
        private readonly ContainerInterface $commands,
        private readonly string $defaultEntityManager,
        private readonly array $entityManagers,
    ) {
        $default = $commands->get($defaultEntityManager);
        \assert($default instanceof Command);
        $this->default = $default;

        parent::__construct($default->getName());
    }

    protected function configure(): void
    {
        $this->setDescription($this->default->getDescription())
            ->setHelp($this->default->getHelp())
            ->setAliases(array_values(array_filter($this->default->getAliases(), is_string(...))))
            ->setDefinition(clone $this->default->getDefinition())
            ->addOption('em', null, InputOption::VALUE_REQUIRED, \sprintf('The entity manager: %s', implode(', ', $this->entityManagers)), $this->defaultEntityManager);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('em');
        if (!\is_string($name) || !\in_array($name, $this->entityManagers, true)) {
            new SymfonyStyle($input, $output)->error(\sprintf('Unknown entity manager "%s"; declared: %s.', \is_string($name) ? $name : '', implode(', ', $this->entityManagers)));

            return Command::INVALID;
        }
        $command = $this->commands->get($name);
        \assert($command instanceof Command);

        // les arguments et options de la commande d'origine seulement, sans --em ; sans
        // application, sa définition n'est pas fusionnée avec celle de bin/console
        $definition = $command->getDefinition();
        $parameters = [];
        foreach ($input->getArguments() as $argument => $value) {
            if ($definition->hasArgument($argument) && null !== $value) {
                $parameters[$argument] = $value;
            }
        }
        foreach ($input->getOptions() as $option => $value) {
            if ($definition->hasOption($option) && null !== $value && false !== $value && [] !== $value) {
                $parameters['--' . $option] = $value;
            }
        }
        $inner = new ArrayInput($parameters);
        $inner->setInteractive($input->isInteractive());

        return $command->run($inner, $output);
    }
}
