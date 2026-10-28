<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\ParameterType;

/**
 * Octets bruts (VARBINARY, BYTEA, BLOB) dans une string PHP.
 *
 * @internal
 */
final class BinaryType extends Type
{
    public function getName(): string
    {
        return 'binary';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw ConversionException::invalidPhpValue($this->getName(), $value, 'a string of bytes');
    }

    public function toPhp(mixed $value, ConversionContext $context): ?string
    {
        return match (true) {
            null === $value, \is_string($value) => $value,
            // PostgreSQL renvoie un BYTEA sous forme de flux
            \is_resource($value) => (string) stream_get_contents($value),
            default => throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a string of bytes'),
        };
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::Binary;
    }
}
