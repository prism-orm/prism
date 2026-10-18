<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\ParameterType;

/**
 * Booléen : BOOLEAN natif sous PostgreSQL, 0/1 sous MySQL, MariaDB et SQLite.
 *
 * @api
 */
final class BooleanType extends Type
{
    // les clés '1' et '0' sont converties en entiers par PHP : 1 et 0 couvrent les deux formes
    private const array FROM_DATABASE = [1 => true, 0 => false, 't' => true, 'f' => false, 'true' => true, 'false' => false];

    public function getName(): string
    {
        return 'boolean';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?bool
    {
        if (null === $value || \is_bool($value)) {
            return $value;
        }

        throw ConversionException::invalidPhpValue($this->getName(), $value, 'a bool');
    }

    public function toPhp(mixed $value, ConversionContext $context): ?bool
    {
        if (null === $value || \is_bool($value)) {
            return $value;
        }
        if ((\is_int($value) || \is_string($value)) && \array_key_exists($value, self::FROM_DATABASE)) {
            return self::FROM_DATABASE[$value];
        }

        throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a boolean, 0 or 1');
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::Boolean;
    }
}
