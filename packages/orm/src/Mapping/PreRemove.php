<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Appelée avant le DELETE ou le soft delete.
 *
 * Active uniquement si la classe porte #[HasLifecycleCallbacks].
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PreRemove implements LifecycleCallbackAttribute
{
    /**
     * @internal lu par l'AttributeDriver
     */
    public function getEvent(): LifecycleEvent
    {
        return LifecycleEvent::PreRemove;
    }
}
