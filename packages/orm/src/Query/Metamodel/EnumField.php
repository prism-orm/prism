<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Query\Condition;

/**
 * Champ enum backed : n'accepte que des cas de son enum (RG-05.3).
 *
 * @template E of \BackedEnum
 *
 * @api
 */
final class EnumField extends Field
{
    /**
     * @param Metamodel<object> $owner
     * @param class-string<E>   $enum
     */
    public function __construct(
        Metamodel $owner,
        string $path,
        public readonly string $enum,
    ) {
        parent::__construct($owner, $path);
    }

    /** @param E $value */
    public function eq(\BackedEnum $value): Condition
    {
        return $this->compare('eq', $value);
    }

    /** @param E $value */
    public function neq(\BackedEnum $value): Condition
    {
        return $this->compare('neq', $value);
    }

    /** @param list<E> $values */
    public function in(array $values): Condition
    {
        return $this->within($values);
    }

    /** @param list<E> $values */
    public function notIn(array $values): Condition
    {
        return $this->within($values, negated: true);
    }
}
