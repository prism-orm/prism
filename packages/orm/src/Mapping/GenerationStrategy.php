<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Stratégie de génération de l'identifiant. Auto choisit Identity (MySQL, MariaDB) ou Sequence (PostgreSQL).
 *
 * @api
 */
enum GenerationStrategy
{
    case Auto;
    case Identity;
    case Sequence;
    case UuidV7;
}
