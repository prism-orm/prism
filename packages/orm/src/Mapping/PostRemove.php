<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Appelée après le DELETE.
 *
 * Active uniquement si la classe porte #[HasLifecycleCallbacks].
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PostRemove implements LifecycleCallbackAttribute
{
    public function getEvent(): LifecycleEvent
    {
        return LifecycleEvent::PostRemove;
    }
}
