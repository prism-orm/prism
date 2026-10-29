<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Appelée avant l'INSERT, au flush.
 *
 * Active uniquement si la classe porte #[HasLifecycleCallbacks].
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PrePersist implements LifecycleCallbackAttribute
{
    /**
     * @internal lu par l'AttributeDriver
     */
    public function getEvent(): LifecycleEvent
    {
        return LifecycleEvent::PrePersist;
    }
}
