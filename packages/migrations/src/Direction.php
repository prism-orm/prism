<?php

declare(strict_types=1);

namespace Prism\Migrations;

/**
 * Sens d'exécution d'une migration.
 *
 * @api
 */
enum Direction: string
{
    case Up = 'up';
    case Down = 'down';
}
