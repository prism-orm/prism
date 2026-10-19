<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Changements calculés, avant la transaction : UnitOfWork::getScheduled*() les expose, et une modification faite ici est prise en compte.
 *
 * @api
 */
final class OnFlushEvent
{
    public function __construct(
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
