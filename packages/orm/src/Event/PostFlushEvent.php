<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Après le commit et les événements Post* ; jamais après un échec. Un flush() ici lève ReentrantFlushException.
 *
 * @api
 */
final class PostFlushEvent
{
    public function __construct(
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
