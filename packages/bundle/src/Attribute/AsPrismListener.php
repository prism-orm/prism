<?php

declare(strict_types=1);

namespace Prism\Bundle\Attribute;

/**
 * Listener d'un événement Prism (PrePersistEvent, PostFlushEvent…), sur une méthode ou sur la
 * classe (__invoke). Chaque EntityManager a son dispatcher : sans entityManager, le listener les
 * écoute tous (ADR 0013).
 *
 * - event : déduit du type du premier paramètre de la méthode s'il est absent ;
 * - method : sur la classe, __invoke par défaut ;
 * - priority : comme pour Symfony, le plus grand d'abord.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsPrismListener
{
    /**
     * @param class-string|null $event
     */
    public function __construct(
        public readonly ?string $event = null,
        public readonly ?string $method = null,
        public readonly ?string $entityManager = null,
        public readonly int $priority = 0,
    ) {
    }
}
