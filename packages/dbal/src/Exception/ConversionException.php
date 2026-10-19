<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Valeur impossible à convertir entre PHP et la base. Le message nomme le type et la forme
 * attendue, jamais la valeur elle-même (elle peut être sensible).
 *
 * @api
 */
final class ConversionException extends \InvalidArgumentException implements DbalException
{
    public static function invalidPhpValue(string $type, mixed $value, string $expected): self
    {
        return new self(\sprintf('Cannot convert a PHP value of type %s to the database type "%s": expected %s.', get_debug_type($value), $type, $expected));
    }

    public static function invalidDatabaseValue(string $type, mixed $value, string $expected, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('Cannot convert a database value of type %s to the PHP value of type "%s": expected %s.', get_debug_type($value), $type, $expected), 0, $previous);
    }

    public static function mutableDateTime(string $type): self
    {
        return new self(\sprintf('Cannot convert a mutable \DateTime to the database type "%s": use \DateTimeImmutable instead.', $type));
    }

    // RG-01.5 : un montant ne transite jamais par un float
    public static function floatForDecimal(): self
    {
        return new self('Cannot convert a float to the database type "decimal": floats lose precision, pass the amount as a numeric string (for example "12.50").');
    }

    public static function tooManySignificantDigits(string $platform, int $digits, int $max): self
    {
        return new self(\sprintf('Cannot store a decimal with %d significant digits on %s: the platform keeps at most %d exactly and would silently round the value.', $digits, $platform, $max));
    }

    public static function invalidJson(\JsonException $previous): self
    {
        return new self(\sprintf('Cannot encode the value to the database type "json": %s.', $previous->getMessage()), 0, $previous);
    }
}
