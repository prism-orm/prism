<?php

declare(strict_types=1);

namespace Prism\Dbal\Query;

use Prism\Dbal\ParameterType;

/**
 * Valeur liée en paramètre ; son nom (:prism_N) n'est attribué qu'à la génération du SQL,
 * dans l'ordre d'apparition, pour que le SQL reste déterministe.
 *
 * @internal
 */
final class BoundValue
{
    public function __construct(
        public readonly mixed $value,
        public readonly ParameterType|string|null $type = null,
    ) {
    }
}
