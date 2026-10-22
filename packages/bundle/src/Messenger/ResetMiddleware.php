<?php

declare(strict_types=1);

namespace Prism\Bundle\Messenger;

use Prism\Bundle\Worker\ConnectionKeeper;
use Prism\Orm\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Middleware Messenger « prism_reset » (RG-07.5) : avant le message, la connexion est testée
 * comme au début d'une requête ; après, même en cas d'échec, une transaction restée ouverte est
 * annulée et les EntityManagers sont vidés. Le message suivant ne voit rien du précédent.
 *
 * @internal
 */
final class ResetMiddleware implements MiddlewareInterface
{
    /**
     * @param iterable<EntityManagerInterface> $entityManagers
     */
    public function __construct(
        private readonly ConnectionKeeper $connections,
        private readonly iterable $entityManagers,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->connections->check();
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->connections->reset();
            foreach ($this->entityManagers as $em) {
                $em->clear();
            }
        }
    }
}
