<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Flottant double précision. Pour un montant, utiliser decimal (RG-01.5).
 *
 * @api
 */
final class FloatType extends Type
{
    public function getName(): string
    {
        return 'float';
    }

    /**
     * Envoyé sous forme de chaîne : un cast (string) passerait par l'ini « precision » (14 chiffres)
     * et perdrait des décimales ; var_export() donne la représentation exacte la plus courte.
     */
    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value) || (\is_float($value) && is_finite($value))) {
            return var_export((float) $value, true);
        }

        throw ConversionException::invalidPhpValue($this->getName(), $value, 'a finite float or an int');
    }

    public function toPhp(mixed $value, ConversionContext $context): ?float
    {
        return match (true) {
            null === $value => null,
            \is_float($value), \is_int($value) => (float) $value,
            \is_string($value) && is_numeric($value) => (float) $value,
            default => throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a numeric value'),
        };
    }
}
