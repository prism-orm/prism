<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Politique de détection des changements (RG-03.11). Explicit ne compare que les entités passées à persist() depuis le dernier flush.
 *
 * @api
 */
enum TrackingPolicy
{
    case Implicit;
    case Explicit;
}
