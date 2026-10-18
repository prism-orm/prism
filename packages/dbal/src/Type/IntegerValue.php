<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Conversions communes aux types entiers.
 *
 * @internal
 */
final class IntegerValue
{
    public static function toDatabase(string $type, mixed $value): ?int
    {
        if (null === $value || \is_int($value)) {
            return $value;
        }

        throw ConversionException::invalidPhpValue($type, $value, 'an int');
    }

    public static function toPhp(string $type, mixed $value): ?int
    {
        if (null === $value || \is_int($value)) {
            return $value;
        }

        // FILTER_VALIDATE_INT refuse aussi un entier hors de la plage de PHP
        if (\is_string($value) && false !== $int = filter_var($value, \FILTER_VALIDATE_INT)) {
            return $int;
        }

        throw ConversionException::invalidDatabaseValue($type, $value, 'an integer within the PHP int range');
    }
}
