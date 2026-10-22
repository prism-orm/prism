<?php

declare(strict_types=1);

namespace Prism\Bundle\Attribute;

/**
 * Fait d'un listener d'entité (cité dans #[EntityListeners] sur l'entité) un service, avec ses
 * dépendances. Sans cet attribut, la classe est instanciée sans argument (ADR 0013).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsEntityListener
{
}
