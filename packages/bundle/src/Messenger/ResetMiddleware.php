<?php

declare(strict_types=1);

namespace Prism\Bundle\Messenger;

use Prism\Bundle\Worker\ConnectionKeeper;
use Prism\Orm\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Middleware Messenger « prism_reset » (RG-07.5) : pour un message reçu d'un transport, la
 * connexion est testée comme au début d'une requête ; après, même en cas d'échec, une transaction
 * restée ouverte est annulée et les EntityManagers sont vidés. Un dispatch ordinaire ne réinitialise rien.
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
        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

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
