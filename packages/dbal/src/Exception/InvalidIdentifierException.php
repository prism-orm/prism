<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Identifiant (table, colonne, index…) impossible à citer de façon sûre sur la plateforme.
 *
 * @api
 */
final class InvalidIdentifierException extends \InvalidArgumentException implements DbalException
{
    public static function empty(string $platform): self
    {
        return new self(\sprintf('Invalid %s identifier: the name is empty.', $platform));
    }

    public static function invalidCharacters(string $platform, string $name): self
    {
        return new self(\sprintf('Invalid %s identifier "%s": the name must be valid UTF-8 without NUL bytes.', $platform, addcslashes($name, "\0..\37\177..\377")));
    }

    public static function tooLong(string $platform, string $name, int $length, int $max, string $unit): self
    {
        return new self(\sprintf('Invalid %s identifier "%s": %d %s, the maximum is %d (the server would truncate or reject it).', $platform, $name, $length, $unit, $max));
    }
}
