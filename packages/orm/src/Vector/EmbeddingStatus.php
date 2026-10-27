<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

/**
 * État des vecteurs d'un champ #[EmbeddedVector] (db:embeddings:status, ADR 0018) : lignes à
 * jour, lignes périmées, et toutes les lignes par modèle ; la clé '' compte celles sans modèle
 * (jamais calculées, ou vecteur fourni par l'application).
 *
 * @api
 */
final readonly class EmbeddingStatus
{
    /**
     * @param class-string       $className
     * @param array<string, int> $models    modèle → lignes
     */
    public function __construct(
        public string $className,
        public string $field,
        public int $fresh,
        public int $stale,
        public array $models,
    ) {
    }
}
