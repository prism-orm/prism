<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Objet de valeur dont les champs sont aplatis dans la table de l'entité hôte.
 *
 * Un embeddable n'a ni identifiant, ni relation, ni callbacks de cycle de vie.
 * Mappé via #[Embedded] ; une propriété nullable (?Address) donne un embeddable nullable (RG-02.5).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Embeddable implements MappingAttribute
{
}
