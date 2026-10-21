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
    // le défaut SQL de PostgreSQL et SQLite (contrôle en fin d'instruction) : pour reprendre une base
    // existante telle quelle (db:import) ; comme Restrict sous MySQL et MariaDB
    case NoAction;
}
