<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * Index ou contrainte d'unicité déclarés sur des champs (« total », « billingAddress.city ») ;
 * les colonnes sont résolues au moment de produire le schéma.
 *
 * @api
 */
final class IndexMapping
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly bool $unique = false,
        public readonly ?string $name = null,
        public readonly ?string $where = null,
    ) {
    }
}
