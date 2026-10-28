<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Appelée après l'UPDATE.
 *
 * Active uniquement si la classe porte #[HasLifecycleCallbacks].
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PostUpdate implements LifecycleCallbackAttribute
{
    /**
     * @internal lu par l'AttributeDriver
     */
    public function getEvent(): LifecycleEvent
    {
        return LifecycleEvent::PostUpdate;
    }
}
