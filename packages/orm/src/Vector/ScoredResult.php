<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

/**
 * Un résultat de nearest() (RG-09.4) : l'entité, sa distance au vecteur cherché telle que la
 * base l'a calculée (plus petite = plus proche), et un score de similarité (plus grand = plus
 * proche) : 1 − d / 2 pour le cosinus (dans [0, 1]), 1 / (1 + d) pour l'euclidienne, −d (le
 * produit scalaire) pour le produit scalaire.
 *
 * @template T of object
 *
 * @api
 */
final readonly class ScoredResult
{
    public float $score;

    /**
     * @param T $entity
     */
    public function __construct(
        public object $entity,
        public float $distance,
        Distance $metric,
    ) {
        $this->score = match ($metric) {
            Distance::Cosine => 1.0 - $distance / 2.0,
            Distance::Euclidean => 1.0 / (1.0 + $distance),
            Distance::InnerProduct => -$distance,
        };
    }
}
