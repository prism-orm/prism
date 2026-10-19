<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

/**
 * Ordre des INSERT d'un flush, et FK différées : insérées à NULL, puis écrites par un UPDATE une
 * fois leur cible insérée (RG-04.4).
 *
 * @internal
 */
final class CommitOrder
{
    /**
     * @param list<int>        $nodes
     * @param list<Dependency> $deferred dans leur ordre de déclaration
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $deferred,
    ) {
    }
}
