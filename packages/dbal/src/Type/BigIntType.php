<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\ParameterType;

/**
 * Entier 64 bits (BIGINT), rendu en int : PHP 64 bits couvre toute la plage signée.
 *
 * @api
 */
final class BigIntType extends Type
{
    public function getName(): string
    {
        return 'bigint';
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
