<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Dbal\Query\Expression;
use Prism\Dbal\Query\Order;
use Prism\Orm\Query\Condition;
use Prism\Orm\Query\Ordering;
use Prism\Orm\Query\QueryContext;

/**
 * Un champ d'une entité dans une requête : son metamodel (donc son alias) et son chemin
 * (« total », « billingAddress.city »). La colonne est résolue par la ClassMetadata à la
 * compilation, jamais générée (ADR 0009).
 *
 * Chaque classe de champ n'expose que les comparaisons de son type, avec des valeurs de ce type
 * (RG-05.3) ; toutes ont isNull(), isNotNull(), asc() et desc().
 *
 * @api
 */
abstract class Field
{
    /**
     * @param Metamodel<object> $owner
     */
    public function __construct(
        public readonly Metamodel $owner,
        public readonly string $path,
    ) {
    }

    public function isNull(): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->expr()->isNull($c->column($this)));
    }

    public function isNotNull(): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->expr()->isNotNull($c->column($this)));
    }

    public function asc(): Ordering
    {
        return new Ordering($this, Order::Asc);
    }

    public function desc(): Ordering
    {
        return new Ordering($this, Order::Desc);
    }

    /**
     * @param 'eq'|'neq'|'lt'|'lte'|'gt'|'gte' $operator
     */
    protected function compare(string $operator, mixed $value): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->compare($this, $operator, $value));
    }

    /**
     * @param list<mixed> $values
     */
    protected function within(array $values, bool $negated = false): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->within($this, $values, $negated));
    }

    protected function range(mixed $low, mixed $high): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->between($this, $low, $high));
    }
}
