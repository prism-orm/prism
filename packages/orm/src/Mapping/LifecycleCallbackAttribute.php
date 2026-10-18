<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Attribut posé sur une méthode appelée à un moment du cycle de vie.
 *
 * @api
 */
interface LifecycleCallbackAttribute extends MappingAttribute
{
    public function getEvent(): LifecycleEvent;
}
