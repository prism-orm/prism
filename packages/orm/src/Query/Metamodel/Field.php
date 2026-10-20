<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Expression\IntExpression;
use Prism\Orm\Query\Operand;
use Prism\Orm\Query\QueryContext;

/**
 * Un champ d'une entité dans une requête : son metamodel (donc son alias) et son chemin
 * (« total », « billingAddress.city »). La colonne est résolue par la ClassMetadata à la
 * compilation, jamais générée (ADR 0009).
 *
 * Tout champ se compte ; les champs numériques, de date et de texte ont aussi leurs agrégats
 * typés (ADR 0010).
 *
 * @api
 */
abstract class Field extends Operand
{
    /**
     * @param Metamodel<object> $owner
     */
    public function __construct(
        public readonly Metamodel $owner,
        public readonly string $path,
    ) {
    }

    /**
     * @internal
     */
    public function toSql(QueryContext $context): string
    {
        return $context->column($this);
    }

    /**
     * @internal
     */
    public function typeOf(QueryContext $context): string
    {
        return $context->fieldType($this);
    }

    /**
     * @internal
     */
    public function toPhp(QueryContext $context, mixed $value): mixed
    {
        return $context->fieldValue($this, $value);
    }

    /**
     * COUNT du champ : les valeurs non nulles.
     */
    public function count(): IntExpression
    {
        return new IntExpression(fn (QueryContext $c): string => $c->expr()->count($this->toSql($c)), static fn (): string => 'integer', aggregate: true);
    }

    /**
     * COUNT(DISTINCT) du champ : les valeurs distinctes non nulles.
     */
    public function countDistinct(): IntExpression
    {
        return new IntExpression(fn (QueryContext $c): string => $c->expr()->count($this->toSql($c), distinct: true), static fn (): string => 'integer', aggregate: true);
    }

    /**
     * SQL d'un agrégat du champ.
     *
     * @param 'sum'|'avg'|'min'|'max' $function
     *
     * @return \Closure(QueryContext): string
     */
    protected function aggregated(string $function): \Closure
    {
        return fn (QueryContext $c): string => match ($function) {
            'sum' => $c->expr()->sum($this->toSql($c)),
            'avg' => $c->expr()->avg($this->toSql($c)),
            'min' => $c->expr()->min($this->toSql($c)),
            'max' => $c->expr()->max($this->toSql($c)),
        };
    }

    /**
     * Le Type du champ, pour un agrégat qui le garde.
     *
     * @return \Closure(QueryContext): string
     */
    protected function ownType(): \Closure
    {
        return fn (QueryContext $c): string => $this->typeOf($c);
    }
}
