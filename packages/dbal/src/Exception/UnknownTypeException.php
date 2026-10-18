<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * @api
 */
final class UnknownTypeException extends \InvalidArgumentException implements DbalException
{
    /**
     * @param list<string> $known
     */
    public static function named(string $name, array $known): self
    {
        return new self(\sprintf('Unknown type "%s". Registered types: %s. Register custom types with TypeRegistry::register().', $name, implode(', ', $known)));
    }
}
