<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Orm\Exception\EmbeddingException;

/**
 * Les fournisseurs par nom : #[EmbeddedVector(model: 'default')] désigne un nom de ce registre
 * (ADR 0018).
 *
 * @api
 */
final readonly class EmbeddingProviderRegistry
{
    /**
     * @param array<string, EmbeddingProvider> $providers nom → fournisseur
     */
    public function __construct(private array $providers = [])
    {
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * @throws EmbeddingException nom inconnu
     */
    public function get(string $name): EmbeddingProvider
    {
        return $this->providers[$name] ?? throw EmbeddingException::unknownProvider($name, array_keys($this->providers));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
