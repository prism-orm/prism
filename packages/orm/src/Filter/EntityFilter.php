<?php

declare(strict_types=1);

namespace Prism\Orm\Filter;

use Prism\Dbal\Query\Expression;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Condition ajoutée à chaque table d'entité que l'ORM lit ou écrit (ADR 0014) : find, findBy,
 * QueryBuilder typé, chargement paresseux, batch fetching, collections. Une entité jointe la
 * reçoit dans son ON.
 *
 * @api
 */
interface EntityFilter
{
    /**
     * Condition aux valeurs liées, jamais du SQL concaténé (RG-01.4) ; null : rien à filtrer.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function condition(ClassMetadata $metadata, FilterTarget $target): ?Expression;
}
