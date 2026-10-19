<?php

declare(strict_types=1);

namespace Prism\Dbal;

/**
 * Mode de verrouillage d'une lecture : optimiste (vérification de version, aucun verrou en base)
 * ou pessimiste (verrou de ligne jusqu'à la fin de la transaction).
 *
 * @api
 */
enum LockMode
{
    case Optimistic;
    case PessimisticRead;
    case PessimisticWrite;
}
