<?php

declare(strict_types=1);

namespace Prism\Dbal;

/**
 * Type de liaison d'un paramètre. Les types métier (decimal, dates, json…) arrivent avec TypeRegistry.
 *
 * @api
 */
enum ParameterType
{
    case String;
    case Integer;
    case Boolean;
    case Null;
    case Binary;

    /**
     * @internal
     */
    public function toPdo(): int
    {
        return match ($this) {
            self::String => \PDO::PARAM_STR,
            self::Integer => \PDO::PARAM_INT,
            self::Boolean => \PDO::PARAM_BOOL,
            self::Null => \PDO::PARAM_NULL,
            self::Binary => \PDO::PARAM_LOB,
        };
    }

    /**
     * @internal
     */
    public static function infer(mixed $value): ?self
    {
        return match (true) {
            null === $value => self::Null,
            \is_int($value) => self::Integer,
            \is_bool($value) => self::Boolean,
            \is_string($value), \is_float($value) => self::String,
            default => null,
        };
    }
}
