<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Dbal\Query\Expression;
use Prism\Orm\Query\Condition;
use Prism\Orm\Query\QueryContext;

/**
 * Relation ToOne (ManyToOne, OneToOne). Sur le côté propriétaire, les conditions portent sur la
 * FK : une entité ou son identifiant.
 *
 * @template M of Metamodel<object>
 *
 * @extends Association<M>
 *
 * @api
 */
final class Relation extends Association
{
    public function eq(object|int|string $target): Condition
    {
        return Condition::of(function (QueryContext $c) use ($target): Expression {
            [$column, $type] = $c->joinColumn($this);

            return $c->expr()->eq($column, $c->targetId($this, $target), $type);
        });
    }

    /**
     * @param list<object|int|string> $targets
     */
    public function in(array $targets): Condition
    {
        return Condition::of(function (QueryContext $c) use ($targets): Expression {
            [$column, $type] = $c->joinColumn($this);

            return $c->expr()->in($column, array_map(fn (object|int|string $t): mixed => $c->targetId($this, $t), $targets), $type);
        });
    }

    public function isNull(): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->expr()->isNull($c->joinColumn($this)[0]));
    }

    public function isNotNull(): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->expr()->isNotNull($c->joinColumn($this)[0]));
    }
}
