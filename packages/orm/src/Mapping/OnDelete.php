<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Comportement de la clé étrangère à la suppression de la ligne référencée (appliqué en base).
 *
 * @api
 */
enum OnDelete
{
    case Restrict;
    case Cascade;
    case SetNull;
}
