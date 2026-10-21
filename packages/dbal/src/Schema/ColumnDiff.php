<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

/**
 * Colonne présente des deux côtés, dont au moins une propriété a changé.
 *
 * @api
 */
final class ColumnDiff
{
    /**
     * @param list<'type'|'nullable'|'default'|'length'|'precision'|'scale'|'autoincrement'|'fixed'> $changedProperties
     */
    public function __construct(
        public readonly Column $from,
        public readonly Column $to,
        public readonly array $changedProperties,
    ) {
    }

    public function hasChanged(string $property): bool
    {
        return \in_array($property, $this->changedProperties, true);
    }
}
