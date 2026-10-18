<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

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
}
