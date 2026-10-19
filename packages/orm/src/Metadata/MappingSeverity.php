<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * Gravité d'un MappingError : une erreur rend le mapping invalide, un avertissement signale une
 * combinaison redondante ou contradictoire sans bloquer (RG-04.6).
 *
 * @api
 */
enum MappingSeverity
{
    case Error;
    case Warning;
}
