<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Dbal\Schema\VectorDistance;

/**
 * Fonction de distance d'une recherche vectorielle (RG-09.3).
 *
 * @api
 */
enum Distance
{
    case Cosine;
    case Euclidean;
    case InnerProduct;

    public function toDbal(): VectorDistance
    {
        return match ($this) {
            self::Cosine => VectorDistance::Cosine,
            self::Euclidean => VectorDistance::Euclidean,
            self::InnerProduct => VectorDistance::InnerProduct,
        };
    }
}
