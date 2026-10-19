<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Au début de flush(), avant tout calcul : une entité modifiée ou persistée ici est écrite par ce flush.
 *
 * @api
 */
final class PreFlushEvent
{
    public function __construct(
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
