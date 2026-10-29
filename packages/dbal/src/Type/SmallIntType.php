<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\ParameterType;

/**
 * Entier 16 bits (SMALLINT).
 *
 * @internal
 */
final class SmallIntType extends Type
{
    public function getName(): string
    {
        return 'smallint';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?int
    {
        return IntegerValue::toDatabase($this->getName(), $value);
    }

    public function toPhp(mixed $value, ConversionContext $context): ?int
    {
        return IntegerValue::toPhp($this->getName(), $value);
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::Integer;
    }
}
