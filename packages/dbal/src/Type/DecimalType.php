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
        return match (true) {
            null === $value => null,
            \is_int($value) => (string) $value,
            \is_float($value) => throw ConversionException::floatForDecimal(),
            \is_string($value) && 1 === preg_match(self::PATTERN, $value) => $value,
            default => throw ConversionException::invalidPhpValue($this->getName(), $value, 'a numeric string such as "12.50"'),
        };
    }

    public function toPhp(mixed $value, ConversionContext $context): ?string
    {
        return match (true) {
            null === $value => null,
            \is_string($value) && is_numeric($value) => $value,
            \is_int($value) => (string) $value,
            // colonne mal déclarée (NUMERIC sous SQLite) : l'échelle est déjà perdue côté base
            \is_float($value) => var_export($value, true),
            default => throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a numeric string'),
        };
    }
}
