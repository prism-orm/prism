<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Query\Expression;
use Prism\Dbal\Query\Order;

/**
 * Ce qu'une requête typée compare, trie, groupe ou sélectionne : un champ du metamodel, ou une
 * expression calculée comme un agrégat (ADR 0010). Ses sous-classes n'exposent que les
 * comparaisons de leur type, avec des valeurs de ce type (RG-05.3) ; toutes ont isNull(),
 * isNotNull(), asc() et desc().
 *
 * @api
 */
abstract class Operand
{
    /**
     * Son SQL dans la requête.
     *
     * @internal
     */
    abstract public function toSql(QueryContext $context): string;

    /**
     * Le nom du Type DBAL de sa valeur, qui lie ses paramètres et convertit ce qu'on en lit.
     *
     * @internal
     */
    abstract public function typeOf(QueryContext $context): string;

    /**
     * Vrai pour une fonction d'agrégat.
     *
     * @internal
     */
    public function isAggregate(): bool
    {
        return false;
    }

    /**
     * Valeur PHP d'une valeur lue en base (projection).
     *
     * @internal
     */
    public function toPhp(QueryContext $context, mixed $value): mixed
    {
        return $context->convert($this->typeOf($context), $value);
    }

    public function isNull(): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->expr()->isNull($this->toSql($c)));
    }

    public function isNotNull(): Condition
    {
        return Condition::of(fn (QueryContext $c): Expression => $c->expr()->isNotNull($this->toSql($c)));
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
