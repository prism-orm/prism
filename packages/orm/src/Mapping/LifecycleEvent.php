<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Event;

/**
 * Événements du cycle de vie d'une entité.
 *
 * @api
 */
enum LifecycleEvent: string
{
    case PrePersist = 'prePersist';
    case PostPersist = 'postPersist';
    case PreUpdate = 'preUpdate';
    case PostUpdate = 'postUpdate';
    case PreRemove = 'preRemove';
    case PostRemove = 'postRemove';
    case PostLoad = 'postLoad';

    /**
     * Classe de l'objet d'événement reçu par les callbacks et les listeners.
     *
     * @return class-string
     */
    public function eventClass(): string
    {
        return match ($this) {
            self::PrePersist => Event\PrePersistEvent::class,
            self::PostPersist => Event\PostPersistEvent::class,
            self::PreUpdate => Event\PreUpdateEvent::class,
            self::PostUpdate => Event\PostUpdateEvent::class,
            self::PreRemove => Event\PreRemoveEvent::class,
            self::PostRemove => Event\PostRemoveEvent::class,
            self::PostLoad => Event\PostLoadEvent::class,
        };
    }
}
