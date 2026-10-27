<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Le calcul d'un embedding a échoué (ADR 0018) : fournisseur inconnu, ou réponse inutilisable.
 * Les lignes concernées restent périmées ; un message ComputeEmbedding sera réessayé.
 *
 * @api
 */
final class EmbeddingException extends \RuntimeException implements OrmException
{
    /**
     * @param list<string> $known
     */
    public static function unknownProvider(string $name, array $known): self
    {
        return new self(\sprintf('Unknown embedding provider "%s"; registered providers: %s. Register it in the EmbeddingProviderRegistry (prism.embeddings.providers in Symfony).', $name, [] === $known ? 'none' : implode(', ', $known)));
    }

    public static function invalidResponse(string $model, string $reason): self
    {
        return new self(\sprintf('The embedding provider "%s" returned an unusable response: %s.', $model, $reason));
    }

    public static function failed(string $model, \Throwable $previous): self
    {
        return new self(\sprintf('The embedding provider "%s" failed: %s', $model, $previous->getMessage()), 0, $previous);
    }
}
