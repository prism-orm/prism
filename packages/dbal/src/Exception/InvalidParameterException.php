<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Paramètre non liable (type non scalaire, clés positionnelles et nommées mélangées) ou nom de savepoint invalide.
 *
 * @api
 */
final class InvalidParameterException extends \InvalidArgumentException implements DbalException
{
    public static function unsupportedValue(int|string $key, mixed $value): self
    {
        return new self(\sprintf('Cannot bind parameter "%s": values of type %s are not supported; pass a scalar or null.', $key, get_debug_type($value)));
    }

    public static function invalidSavepointName(string $name): self
    {
        return new self(\sprintf('Invalid savepoint name "%s": expected uppercase letters, digits and underscores.', $name));
    }

    public static function mixedKeys(): self
    {
        return new self('Cannot mix positional and named parameters in the same query.');
    }
}
