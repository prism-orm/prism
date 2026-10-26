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
     * @param list<QueryGuard>    $guards        appelées avant chaque requête (ADR 0014)
     */
    public function __construct(
        public readonly LoggerInterface $logger = new NullLogger(),
        public readonly bool $logParameters = false,
        ?TypeRegistry $types = null,
        public readonly ?\DateTimeZone $timezone = null,
        public readonly array $observers = [],
        public readonly array $guards = [],
    ) {
        $this->types = $types ?? TypeRegistry::withDefaults();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($logger, $this->logParameters, $this->types, $this->timezone, $this->observers, $this->guards);
    }

    public function withLogParameters(bool $logParameters): self
    {
        return new self($this->logger, $logParameters, $this->types, $this->timezone, $this->observers, $this->guards);
    }

    public function withTypes(TypeRegistry $types): self
    {
        return new self($this->logger, $this->logParameters, $types, $this->timezone, $this->observers, $this->guards);
    }

    public function withTimezone(?\DateTimeZone $timezone): self
    {
        return new self($this->logger, $this->logParameters, $this->types, $timezone, $this->observers, $this->guards);
    }

    /**
     * @param list<QueryObserver> $observers
     */
    public function withObservers(array $observers): self
    {
        return new self($this->logger, $this->logParameters, $this->types, $this->timezone, $observers, $this->guards);
    }

    /**
     * @param list<QueryGuard> $guards
     */
    public function withGuards(array $guards): self
    {
        return new self($this->logger, $this->logParameters, $this->types, $this->timezone, $this->observers, $guards);
    }
}
