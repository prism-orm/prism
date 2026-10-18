<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Marqueur commun à tous les attributs de mapping Prism.
 *
 * Permet à l'AttributeDriver de filtrer en une passe :
 * $reflector->getAttributes(MappingAttribute::class, \ReflectionAttribute::IS_INSTANCEOF).
 *
 * @api
 */
interface MappingAttribute
{
}
