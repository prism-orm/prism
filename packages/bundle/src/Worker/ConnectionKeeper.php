<?php

declare(strict_types=1);

namespace Prism\Bundle\Worker;

use Prism\Dbal\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le mode worker (FrankenPHP, RoadRunner, Messenger ; RG-07.5) : à la fin d'une requête, une
 * transaction restée ouverte est annulée ; au début de la suivante, une connexion inactive depuis
 * plus de $idleTtl secondes est testée et, perdue, fermée : la prochaine requête SQL se
 * reconnecte. Jamais en pleine transaction.
 *
 * @internal
 */
final class ConnectionKeeper implements ResetInterface
{
    private float $idleSince;

    /** @var array<string, int> niveau de transaction que reset() n'annule pas, par connexion */
    private array $kept = [];

    /**
     * @param array<string, Connection> $connections par nom
     * @param int                       $idleTtl     secondes d'inactivité avant le test ; 0 : à chaque requête
     */
    public function __construct(
        private readonly array $connections,
        private readonly int $idleTtl = 60,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->idleSince = microtime(true);
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->check();
        }
    }

    /**
     * Une connexion ouverte, hors transaction, inactive depuis le délai : testée, et fermée si elle
     * ne répond plus.
     */
    public function check(): void
    {
        if (microtime(true) - $this->idleSince < $this->idleTtl) {
            return;
        }
        foreach ($this->connections as $name => $connection) {
            if ($connection->isConnected() && !$connection->isTransactionActive() && !$connection->ping()) {
                $connection->close();
                $this->logger->info('Prism connection "{connection}" was lost; it reconnects on its next query.', ['connection' => $name]);
            }
        }
    }

    /**
     * Les transactions ouvertes maintenant survivent aux reset() suivants : celle d'un test du
     * test-pack (ResetDatabase) enveloppe plusieurs requêtes HTTP. Jusqu'à release().
     */
    public function keepOpenTransactions(): void
    {
        foreach ($this->connections as $name => $connection) {
            $this->kept[$name] = $connection->getTransactionNestingLevel();
        }
    }

    public function release(): void
    {
        $this->kept = [];
    }

    /**
     * Fin de requête ou de message : aucune transaction ne survit, la suivante repart propre.
     */
    public function reset(): void
    {
        foreach ($this->connections as $name => $connection) {
            $kept = $this->kept[$name] ?? 0;
            if ($connection->getTransactionNestingLevel() <= $kept) {
                continue;
            }
            $this->logger->warning('Prism connection "{connection}" ended the request with an open transaction (level {level}); it is rolled back.', ['connection' => $name, 'level' => $connection->getTransactionNestingLevel()]);
            try {
                while ($connection->getTransactionNestingLevel() > $kept) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                // connexion perdue en route : la fermer annule la transaction côté serveur
                $connection->close();
            }
        }
        $this->idleSince = microtime(true);
    }
}
