<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Erreur de mapping détectée à la lecture d'un attribut ou à la validation de la metadata.
 *
 * @api
 */
final class MappingException extends \LogicException implements OrmException
{
    public static function invalidAttributeArgument(string $attribute, string $argument, string $reason): self
    {
        return new self(\sprintf('Invalid argument "%s" for #[%s]: %s', $argument, $attribute, $reason));
    }

    public static function invalidMetadata(string $class, string $reason): self
    {
        return new self(\sprintf('Invalid mapping for %s: %s', $class, $reason));
    }

    public static function invalidField(string $class, string $field, string $reason): self
    {
        return new self(\sprintf('Invalid mapping for %s::$%s: %s', $class, $field, $reason));
    }

    public static function notAnEntity(string $class): self
    {
        return new self(\sprintf('%s is not an entity: add #[Entity] to the class.', $class));
    }

    public static function unknownClass(string $class): self
    {
        return new self(\sprintf('Class %s does not exist or cannot be autoloaded.', $class));
    }

    public static function mutableDateTime(string $class, string $field): self
    {
        return new self(\sprintf('Invalid mapping for %s::$%s: \\DateTime is mutable and not supported, use \\DateTimeImmutable instead.', $class, $field));
    }

    public static function unknownField(string $class, string $field): self
    {
        return new self(\sprintf('%s has no mapped field "%s".', $class, $field));
    }
}
