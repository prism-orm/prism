<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Chaîne courte (VARCHAR) ; la longueur est portée par la colonne.
 *
 * @api
 */
final class StringType extends Type
{
    public function getName(): string
    {
        return 'string';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw ConversionException::invalidPhpValue($this->getName(), $value, 'a string');
    }

    public function toPhp(mixed $value, ConversionContext $context): ?string
    {
        return StringValue::toPhp($this->getName(), $value);
    }
}
