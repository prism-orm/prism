<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Orm\Exception\QueryException;
use Prism\Orm\Query\Expression\DecimalExpression;
use Prism\Orm\Query\Expression\IntExpression;
use Prism\Orm\Query\Expression\StringExpression;
use Prism\Orm\Query\Metamodel\JsonField;

/**
 * Chemin dans un champ json (ADR 0010) : se lit en texte, en entier ou en décimal, puis se
 * compare, se trie, se groupe ou se sélectionne comme une expression de ce type. Un chemin
 * absent ou un null JSON vaut NULL sur les 4 bases.
 *
 * Le chemin est une structure, pas une valeur : validé, il est écrit en littéral par la
 * Platform, et le SQL est le même partout où il apparaît (SELECT, GROUP BY, ORDER BY).
 * Un nombre se lit par asInt() ou asDecimal() : son texte diffère selon la base (10.5, 10.50).
 *
 * @api
 */
final class JsonPath
{
    /**
     * @param list<string|int> $keys
     *
     * @throws QueryException chemin vide ou clé invalide
     *
     * @internal créé par JsonField::path()
     */
    public function __construct(
        public readonly JsonField $field,
        public readonly array $keys,
    ) {
        if ([] === $keys) {
            throw QueryException::invalidJsonPath($field->path, $keys);
        }
        foreach ($keys as $key) {
            if (\is_int($key) ? $key < 0 : 1 !== preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                throw QueryException::invalidJsonPath($field->path, $keys);
            }
        }
    }

    public function asString(): StringExpression
    {
        return new StringExpression($this->sql(null), static fn (): string => 'string');
    }

    public function asInt(): IntExpression
    {
        return new IntExpression($this->sql('integer'), static fn (): string => 'integer');
    }

    /**
     * Décimal, en numeric-string : son échelle dépend de la base (MySQL : 30 décimales).
     */
    public function asDecimal(): DecimalExpression
    {
        return new DecimalExpression($this->sql('decimal'), static fn (): string => 'decimal');
    }

    /**
     * @param 'integer'|'decimal'|null $cast
     *
     * @return \Closure(QueryContext): string
     */
    private function sql(?string $cast): \Closure
    {
        return function (QueryContext $c) use ($cast): string {
            $text = $c->platform()->getJsonExtractTextSql($this->field->toSql($c), $this->keys);

            return null === $cast ? $text : $c->platform()->getCastSql($text, $cast);
        };
    }
}
