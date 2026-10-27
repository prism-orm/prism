<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

/**
 * Sorte d'index (ADR 0017) : B-tree, vectoriel (HNSW) ou plein texte.
 *
 * @api
 */
enum IndexKind
{
    case Regular;
    case Vector;
    case FullText;
}
