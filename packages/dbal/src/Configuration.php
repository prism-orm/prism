<?php

declare(strict_types=1);

namespace Prism\Dbal;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Réglages d'une connexion. Immuable : chaque with*() retourne une copie.
 *
 * @api
 */
final class Configuration
{
    /**
     * @param bool $logParameters journalise les valeurs liées ; désactivé par défaut car elles
     *                            peuvent contenir des données sensibles (mots de passe, IBAN)
     */
    public function __construct(
        public readonly LoggerInterface $logger = new NullLogger(),
        public readonly bool $logParameters = false,
    ) {
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($logger, $this->logParameters);
    }

    public function withLogParameters(bool $logParameters): self
    {
        return new self($this->logger, $logParameters);
    }
}
