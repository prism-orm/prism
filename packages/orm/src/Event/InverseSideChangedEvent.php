<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Au flush, une collection du côté inverse a changé sans le côté propriétaire (RG-04.1) : rien
 * n'est écrit pour ce changement. Un élément ajouté ne pointe pas vers $owner, ou un élément
 * retiré y pointe encore. Le bundle l'écrit en warning en dev.
 *
 * @api
 */
final class InverseSideChangedEvent
{
    public function __construct(
        public readonly object $owner,
        public readonly string $field,
        public readonly object $element,
        public readonly bool $added,
        public readonly UnitOfWork $unitOfWork,
    ) {
    }
}
