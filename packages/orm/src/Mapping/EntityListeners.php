<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Classes listeners dédiées à cette entité.
 *
 * Chaque listener expose des méthodes portant les attributs de cycle de vie
 * et recevant l'entité en premier argument. Dans Symfony, les listeners sont des services.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EntityListeners implements MappingAttribute
{
    /** @var list<class-string> */
    public readonly array $listeners;

    /**
     * @param list<class-string> $listeners
     */
    public function __construct(array $listeners)
    {
        $this->listeners = AttributeGuard::classList('EntityListeners', 'listeners', $listeners);
    }
}
