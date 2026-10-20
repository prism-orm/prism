<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Query\Expression;

/**
 * Condition d'une requête typée, immuable : créée par les champs du metamodel
 * ($o->total->gte('100.00')), combinée par all(), any() et not(). Ses valeurs sont toujours
 * liées en paramètres (RG-05.4).
 *
 * @api
 */
final class Condition
{
    /**
     * @param \Closure(QueryContext): Expression $compile
     */
    private function __construct(private readonly \Closure $compile)
    {
    }

    /**
     * @param \Closure(QueryContext): Expression $compile
     *
     * @internal
     */
    public static function of(\Closure $compile): self
    {
        return new self($compile);
    }

    /**
     * Toutes les conditions (AND) ; sans condition, toujours vraie.
     */
    public static function all(self ...$conditions): self
    {
        return new self(static fn (QueryContext $context): Expression => [] === $conditions
            ? Expression::of('1 = 1')
            : $context->expr()->and(...array_map(static fn (self $c): Expression => $c->compile($context), $conditions)));
    }

    /**
     * Au moins une des conditions (OR) ; sans condition, toujours fausse.
     */
    public static function any(self ...$conditions): self
    {
        return new self(static fn (QueryContext $context): Expression => [] === $conditions
            ? Expression::of('1 = 0')
            : $context->expr()->or(...array_map(static fn (self $c): Expression => $c->compile($context), $conditions)));
    }

    public function not(): self
    {
        return new self(fn (QueryContext $context): Expression => $context->expr()->not($this->compile($context)));
    }

    /**
     * @internal
     */
    public function compile(QueryContext $context): Expression
    {
        return ($this->compile)($context);
    }
}
