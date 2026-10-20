<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

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
}
