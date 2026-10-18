<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Lecture commune aux types chaîne.
 *
 * @internal
 */
final class StringValue
{
    public static function toPhp(string $type, mixed $value): ?string
    {
        return match (true) {
            null === $value, \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_resource($value) => (string) stream_get_contents($value),
            default => throw ConversionException::invalidDatabaseValue($type, $value, 'a string'),
        };
    }
}
