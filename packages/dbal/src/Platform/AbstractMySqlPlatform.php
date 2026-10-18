<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

/**
 * Socle commun MySQL / MariaDB ; les divergences (VECTOR, JSON, index) vivent dans les sous-classes.
 *
 * @api
 */
abstract class AbstractMySqlPlatform extends AbstractPlatform
{
}
