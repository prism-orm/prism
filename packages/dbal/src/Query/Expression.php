<?php

declare(strict_types=1);

namespace Prism\Dbal\Query;

/**
 * Fragment de SQL avec ses valeurs liées. Immuable ; construit par ExpressionBuilder
 * (ou depuis une chaîne de SQL brut passée à where(), having()…).
 *
 * @api
 */
final class Expression
{
    /**
     * @param list<string|BoundValue> $parts
     */
    private function __construct(private readonly array $parts)
    {
    }

    /**
     * @internal
     */
    public static function of(string|BoundValue ...$parts): self
    {
        return new self(array_values($parts));
    }

    /**
     * @internal entoure l'expression de parenthèses (pour AND / OR)
     */
    public function wrapped(): self
    {
        return new self(['(', ...$this->parts, ')']);
    }

    /**
     * @internal
     */
    public static function join(string $glue, self ...$expressions): self
    {
        $parts = [];
        foreach ($expressions as $i => $expression) {
            if ($i > 0) {
                $parts[] = $glue;
            }
            array_push($parts, ...$expression->parts);
        }

        return new self($parts);
    }

    /**
     * @internal
     *
     * @return list<string|BoundValue>
     */
    public function getParts(): array
    {
        return $this->parts;
    }
}
