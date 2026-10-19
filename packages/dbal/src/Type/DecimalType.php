<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Nombre décimal exact (DECIMAL / NUMERIC), toujours rendu en string (RG-01.5, montants ERP).
 *
 * @api
 */
final class DecimalType extends Type
{
    private const string PATTERN = '/^[+-]?\d+(\.\d+)?$/';

    public function getName(): string
    {
        return 'decimal';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        $decimal = match (true) {
            null === $value => null,
            \is_int($value) => (string) $value,
            \is_float($value) => throw ConversionException::floatForDecimal(),
            \is_string($value) && 1 === preg_match(self::PATTERN, $value) => $value,
            default => throw ConversionException::invalidPhpValue($this->getName(), $value, 'a numeric string such as "12.50"'),
        };

        $max = $context->platform->getDecimalMaxSignificantDigits();
        if (null !== $decimal && null !== $max && ($digits = self::significantDigits($decimal)) > $max) {
            throw ConversionException::tooManySignificantDigits($context->platform->getName(), $digits, $max);
        }

        return $decimal;
    }

    // chiffres hors zéros de tête et de queue : ce qu'un flottant doit représenter exactement
    private static function significantDigits(string $decimal): int
    {
        return \strlen(trim(str_replace(['+', '-', '.'], '', $decimal), '0'));
    }

    public function toPhp(mixed $value, ConversionContext $context): ?string
    {
        return match (true) {
            null === $value => null,
            \is_string($value) && is_numeric($value) => $value,
            \is_int($value) => (string) $value,
            // SQLite (NUMERIC) rend un flottant : la valeur reste une string, l'échelle est perdue côté base
            \is_float($value) => var_export($value, true),
            default => throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a numeric string'),
        };
    }
}
