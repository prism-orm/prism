<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Marque l'identifiant de l'entité. Un seul par entité en 1.0 (RG-02.4).
 *
 * Forme recommandée : public private(set) ?int $id = null;
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Id implements MappingAttribute
{
}
