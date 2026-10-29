<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\FlushContext;

/**
 * Au début de flush(), avant tout calcul : une entité modifiée ou persistée ici est écrite par ce flush.
 *
 * @api
 */
final class PreFlushEvent
{
    /**
     * @internal construit par Prism ; un listener le reçoit
     */
    public function __construct(
        public readonly FlushContext $unitOfWork,
    ) {
    }
}
