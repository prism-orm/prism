<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

/**
 * Un résultat de hybridSearch() (RG-09.5) : l'entité, son score fusionné (Reciprocal Rank
 * Fusion, plus grand = meilleur) et son rang dans chaque liste, à partir de 1 ; null quand
 * elle n'y figure pas (un document sans les mots cherchés, ou hors des candidats vectoriels).
 *
 * @template T of object
 *
 * @api
 */
final readonly class HybridResult
{
    /**
     * @param T $entity
     */
    public function __construct(
        public object $entity,
        public float $score,
        public ?int $textRank,
        public ?int $vectorRank,
    ) {
    }
}
