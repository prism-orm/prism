<?php

declare(strict_types=1);

namespace Prism\Orm\Filter;

use Prism\Dbal\Query\ExpressionBuilder;
use Prism\Dbal\Query\QueryBuilder;

/**
 * La table d'entité à filtrer dans une requête en construction : son alias, et de quoi écrire
 * une condition aux valeurs liées.
 *
 * @api
 */
final class FilterTarget
{
    /**
     * @internal construit par le persister et la compilation du QueryBuilder typé
     */
    public function __construct(
        private readonly QueryBuilder $qb,
        public readonly ?string $alias,
        public readonly FilterOperation $operation = FilterOperation::Read,
    ) {
    }

    /**
     * Colonne citée, préfixée par l'alias de la table.
     */
    public function column(string $name): string
    {
        return $this->qb->column($name, $this->alias);
    }

    public function expr(): ExpressionBuilder
    {
        return $this->qb->expr();
    }
}
