<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Expression;

use Prism\Orm\Query\Operand;
use Prism\Orm\Query\QueryContext;

/**
 * Valeur calculée d'une requête typée (ADR 0010) : $o->total->sum(), $o->id->count(). Se compare
 * comme un champ du même type, se trie, se sélectionne dans un DTO.
 *
 * @api
 */
abstract class TypedExpression extends Operand
{
    /**
     * @param \Closure(QueryContext): string $sql
     * @param \Closure(QueryContext): string $type nom du Type DBAL de sa valeur
     *
     * @internal créée par les champs
     */
    final public function __construct(
        private readonly \Closure $sql,
        private readonly \Closure $type,
        private readonly bool $aggregate = false,
    ) {
    }

    /**
     * @internal
     */
    public function toSql(QueryContext $context): string
    {
        return ($this->sql)($context);
    }

    /**
     * @internal
     */
    public function typeOf(QueryContext $context): string
    {
        return ($this->type)($context);
    }

    /**
     * @internal
     */
    public function isAggregate(): bool
    {
        return $this->aggregate;
    }
}
