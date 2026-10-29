<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Appelée après l'INSERT, l'identifiant est connu.
 *
 * Active uniquement si la classe porte #[HasLifecycleCallbacks].
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PostPersist implements LifecycleCallbackAttribute
{
    /**
     * @internal lu par l'AttributeDriver
     */
    public function getEvent(): LifecycleEvent
    {
        return LifecycleEvent::PostPersist;
    }
}
