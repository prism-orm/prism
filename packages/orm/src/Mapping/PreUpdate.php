<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Appelée avant l'UPDATE ; le changeset de l'entité est recalculé ensuite (RG-03.8).
 *
 * Active uniquement si la classe porte #[HasLifecycleCallbacks].
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PreUpdate implements LifecycleCallbackAttribute
{
    /**
     * @internal lu par l'AttributeDriver
     */
    public function getEvent(): LifecycleEvent
    {
        return LifecycleEvent::PreUpdate;
    }
}
