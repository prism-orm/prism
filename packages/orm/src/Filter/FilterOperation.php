<?php

declare(strict_types=1);

namespace Prism\Orm\Filter;

/**
 * Nature de la requête filtrée : une lecture peut voir plus de lignes qu'une écriture n'en
 * touche (lignes partagées d'un #[Tenanted(global: true)], ADR 0014). Load : le chargement d'une
 * entité par son identifiant parce qu'une autre la référence (ghost, ToOne, refresh), qui atteint
 * une ligne supprimée logiquement (ADR 0015).
 *
 * @api
 */
enum FilterOperation
{
    case Read;
    case Write;
    case Load;
}
