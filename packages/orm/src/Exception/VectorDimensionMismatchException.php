<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Un vecteur n'a pas la dimension de sa colonne : refusé avant l'envoi en base (RG-09.1).
 *
 * @api
 */
final class VectorDimensionMismatchException extends \InvalidArgumentException implements OrmException
{
    /**
     * @param class-string $class
     */
    public static function forField(string $class, string $field, int $expected, int $actual): self
    {
        return new self(\sprintf('%s::$%s expects a vector of %d dimensions, got %d: check the embedding model of this field.', $class, $field, $expected, $actual));
    }
}
