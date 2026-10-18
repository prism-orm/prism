<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Déclare une entité persistée.
 *
 * - repositoryClass : classe étendant EntityRepository (sinon repository générique) ;
 * - trackingPolicy : politique de détection des changements (RG-03.11) ;
 * - readOnly : aucun INSERT, UPDATE ni DELETE (vues, tables de référence).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity implements MappingAttribute
{
    /**
     * @param class-string|null $repositoryClass
     */
    public function __construct(
        public readonly ?string $repositoryClass = null,
        public readonly TrackingPolicy $trackingPolicy = TrackingPolicy::Implicit,
        public readonly bool $readOnly = false,
    ) {
    }
}
