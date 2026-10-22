<?php

declare(strict_types=1);

namespace Prism\Bundle\Attribute;

/**
 * Règle la conversion d'un paramètre de route en entité pour un argument de contrôleur
 * (RG-07.3). Sans cet attribut, l'identifiant est le paramètre de route qui porte le nom de
 * l'argument, sinon {id}.
 *
 * - id : le paramètre de route qui porte l'identifiant ;
 * - mapping : paramètre de route => champ, cherché par findOneBy() ;
 * - entityManager : l'EntityManager ; par défaut, le premier qui mappe la classe ;
 * - disabled : l'argument est laissé aux autres resolvers.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapEntity
{
    /**
     * @param array<string, string>|null $mapping
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?array $mapping = null,
        public readonly ?string $entityManager = null,
        public readonly bool $disabled = false,
    ) {
    }
}
