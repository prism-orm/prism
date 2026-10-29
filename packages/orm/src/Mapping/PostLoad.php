<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Appelée après l'hydratation ; le constructeur n'est jamais appelé par l'ORM (RG-03.12).
 *
 * Active uniquement si la classe porte #[HasLifecycleCallbacks].
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PostLoad implements LifecycleCallbackAttribute
{
    /**
     * @internal lu par l'AttributeDriver
     */
    public function getEvent(): LifecycleEvent
    {
        return LifecycleEvent::PostLoad;
    }
}
