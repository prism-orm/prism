<?php

declare(strict_types=1);

namespace Prism\Dbal;

use Prism\Dbal\Type\TypeRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Réglages d'une connexion. Immuable : chaque with*() retourne une copie.
 *
 * @api
 */
final class Configuration
{
    public readonly TypeRegistry $types;

    /**
     * @param bool                $logParameters journalise les valeurs liées ; désactivé par défaut car elles
     *                                           peuvent contenir des données sensibles (mots de passe, IBAN)
     * @param TypeRegistry|null   $types         types disponibles ; les 14 types natifs par défaut
     * @param \DateTimeZone|null  $timezone      fuseau de stockage des datetime (RG-01.6) ; null : aucune conversion
     * @param list<QueryObserver> $observers     appelés après chaque requête réussie (ADR 0008)
     */
    public function __construct(
        public readonly LoggerInterface $logger = new NullLogger(),
        public readonly bool $logParameters = false,
        ?TypeRegistry $types = null,
        public readonly ?\DateTimeZone $timezone = null,
        public readonly array $observers = [],
    ) {
        $this->types = $types ?? TypeRegistry::withDefaults();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($logger, $this->logParameters, $this->types, $this->timezone, $this->observers);
    }

    public function withLogParameters(bool $logParameters): self
    {
        return new self($this->logger, $logParameters, $this->types, $this->timezone, $this->observers);
    }

    public function withTypes(TypeRegistry $types): self
    {
        return new self($this->logger, $this->logParameters, $types, $this->timezone, $this->observers);
    }

    public function withTimezone(?\DateTimeZone $timezone): self
    {
        return new self($this->logger, $this->logParameters, $this->types, $timezone, $this->observers);
    }

    /**
     * @param list<QueryObserver> $observers
     */
    public function withObservers(array $observers): self
    {
        return new self($this->logger, $this->logParameters, $this->types, $this->timezone, $observers);
    }
}
