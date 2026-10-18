<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

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
}
