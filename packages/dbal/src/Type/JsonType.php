<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Valeur JSON, décodée en tableaux associatifs. Un null PHP devient un NULL SQL (pas le JSON « null »).
 *
 * @api
 */
final class JsonType extends Type
{
    private const int ENCODE = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION;

    public function getName(): string
    {
        return 'json';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value) {
            return null;
        }

        try {
            return json_encode($value, self::ENCODE);
        } catch (\JsonException $e) {
            throw ConversionException::invalidJson($e);
        }
    }

    public function toPhp(mixed $value, ConversionContext $context): mixed
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a JSON string');
        }

        try {
            return json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'valid JSON', $e);
        }
    }
}
