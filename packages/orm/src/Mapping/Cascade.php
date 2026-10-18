<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Opérations propagées d'une entité vers ses relations.
 *
 * @api
 */
enum Cascade
{
    case Persist;
    case Remove;
    case Detach;
}
