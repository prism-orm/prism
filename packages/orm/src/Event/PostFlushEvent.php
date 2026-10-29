<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Après le commit et les événements Post* ; jamais après un échec. Un flush() ici lève ReentrantFlushException.
 *
 * @api
 */
final class PostFlushEvent
{
    /**
     * @internal construit par Prism ; un listener le reçoit
     */
    public function __construct(
        public readonly FlushContext $unitOfWork,
    ) {
    }
}
