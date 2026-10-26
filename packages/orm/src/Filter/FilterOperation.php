<?php

declare(strict_types=1);

namespace Prism\Orm\Filter;

/**
 * Nature de la requête filtrée : une lecture peut voir plus de lignes qu'une écriture n'en
 * touche (lignes partagées d'un #[Tenanted(global: true)], ADR 0014).
 *
 * @api
 */
enum FilterOperation
{
    case Read;
    case Write;
}
