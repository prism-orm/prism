<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Texte long (TEXT, CLOB).
 *
 * @internal
 */
final class TextType extends Type
{
    public function getName(): string
    {
        return 'text';
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
