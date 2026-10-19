<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * flush() appelé pendant un flush, depuis un listener (RG-03.7).
 *
 * @api
 */
final class ReentrantFlushException extends \LogicException implements OrmException
{
    public static function create(): self
    {
        return new self('flush() cannot be called while a flush is in progress (from a lifecycle listener or callback). Changes made in a listener are included in the current flush.');
    }
}
