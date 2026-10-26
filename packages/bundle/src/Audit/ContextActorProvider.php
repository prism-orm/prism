<?php

declare(strict_types=1);

namespace Prism\Bundle\Audit;

use Prism\Orm\Behavior\ActorProvider;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * L'auteur des modifications (RG-08.16, ADR 0015) : l'identifiant de l'utilisateur connecté,
 * sinon « cli:<commande> » en console, sinon personne.
 *
 * @internal
 */
final class ContextActorProvider implements ActorProvider
{
    private ?string $command = null;

    public function __construct(
        private readonly ?TokenStorageInterface $tokens = null,
    ) {
    }

    public function getActor(): ?string
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (null !== $user) {
            return $user->getUserIdentifier();
        }

        return null === $this->command ? null : 'cli:' . $this->command;
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->command = $event->getCommand()?->getName();
    }

    public function reset(): void
    {
        $this->command = null;
    }
}
