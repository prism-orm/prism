<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

/**
 * Distance entre deux vecteurs, telle que la plateforme la calcule (ADR 0017). Toujours une
 * distance : plus petite, plus proche ; le produit scalaire est donc rendu négatif.
 *
 * @api
 */
enum VectorDistance
{
    case Cosine;
    case Euclidean;
    case InnerProduct;
}
