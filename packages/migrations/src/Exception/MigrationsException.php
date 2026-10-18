<?php

declare(strict_types=1);

namespace Prism\Migrations\Exception;

use Prism\Exception\PrismException;

/**
 * Interface commune aux exceptions du paquet prism-orm/migrations.
 *
 * @api
 */
interface MigrationsException extends PrismException
{
}
