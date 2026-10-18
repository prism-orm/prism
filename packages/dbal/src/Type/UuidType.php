<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * UUID sous forme canonique en minuscules (8-4-4-4-12), sans dépendance externe.
 *
 * @api
 */
final class UuidType extends Type
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function getName(): string
    {
        return 'uuid';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value) {
            return null;
        }
        if (\is_string($value) && 1 === preg_match(self::PATTERN, $value)) {
            return strtolower($value);
        }

        throw ConversionException::invalidPhpValue($this->getName(), $value, 'a UUID string such as "0192f1e4-7b5c-7d3a-9f00-1a2b3c4d5e6f"');
    }

    public function toPhp(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value) {
            return null;
        }
        if (\is_string($value) && 1 === preg_match(self::PATTERN, $value)) {
            return strtolower($value);
        }

        throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a UUID string');
    }
}
