<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Orm\Exception\EmbeddingException;

/**
 * Calcule les embeddings d'un champ #[EmbeddedVector] (RG-09.9, ADR 0018). Le cœur n'en fournit
 * aucun qui appelle le réseau : les adaptateurs vivent dans des paquets prism-orm/embedding-*.
 * Un fournisseur est désigné par son nom dans un EmbeddingProviderRegistry, pas par son modèle.
 *
 * @api
 */
interface EmbeddingProvider
{
    /**
     * Identifiant du modèle, stocké par ligne dans la colonne <champ>_model (RG-09.10), par
     * exemple « ollama/nomic-embed-text:v1.5 ».
     */
    public function model(): string;

    public function dimensions(): int;

    /**
     * @param non-empty-list<string> $texts
     *
     * @return non-empty-list<list<float>> un vecteur par texte, dans l'ordre
     *
     * @throws EmbeddingException le fournisseur a échoué : les lignes restent périmées
     */
    public function embed(array $texts): array;
}
