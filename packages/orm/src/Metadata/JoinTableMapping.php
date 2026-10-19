<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Orm\Mapping\OnDelete;

/**
 * Table pivot d'un ManyToMany, portée par le côté propriétaire.
 *
 * @api
 */
final class JoinTableMapping
{
    public function __construct(
        public readonly string $name,
        public readonly string $joinColumn,
        public readonly string $inverseJoinColumn,
        public readonly OnDelete $onDelete = OnDelete::Cascade,
    ) {
    }
}
