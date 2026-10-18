<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * @api
 */
final class DuplicateTypeException extends \LogicException implements DbalException
{
    public static function named(string $name): self
    {
        return new self(\sprintf('Type "%s" is already registered; type names must be unique.', $name));
    }
}
