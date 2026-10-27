<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Orm\Exception\InvalidArgumentException;

/**
 * Réglages de hybridSearch() (RG-09.5) : Reciprocal Rank Fusion, score = textWeight / (k + rang
 * plein texte) + vectorWeight / (k + rang vectoriel), chaque liste limitée à $candidates.
 *
 * - field : le champ vecteur, s'il y en a plusieurs ;
 * - fullTextIndex : le nom du #[FullTextIndex], s'il y en a plusieurs ;
 * - candidates : null, max(4 × limit, 40).
 *
 * @api
 */
final readonly class HybridOptions
{
    /**
     * @throws InvalidArgumentException réglage hors bornes
     */
    public function __construct(
        public int $k = 60,
        public float $textWeight = 1.0,
        public float $vectorWeight = 1.0,
        public ?int $candidates = null,
        public ?string $field = null,
        public ?string $fullTextIndex = null,
    ) {
        if ($k < 1 || $textWeight < 0.0 || $vectorWeight < 0.0 || 0.0 === $textWeight + $vectorWeight || (null !== $candidates && $candidates < 1)) {
            throw InvalidArgumentException::hybridOptions($k, $textWeight, $vectorWeight, $candidates);
        }
    }

    public function candidatesFor(int $limit): int
    {
        return $this->candidates ?? max(4 * $limit, 40);
    }
}
