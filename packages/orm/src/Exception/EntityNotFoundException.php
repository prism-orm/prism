<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * La ligne d'une entité attendue n'existe pas (ou plus) : référence chargée, refresh(), lock()
 * (RG-04.8).
 *
 * @api
 */
final class EntityNotFoundException extends \RuntimeException implements OrmException
{
    /**
     * @param class-string $class
     */
    public static function forId(string $class, mixed $id): self
    {
        return new self(\sprintf('No %s entity found for id %s.', $class, \is_scalar($id) ? (string) $id : get_debug_type($id)));
    }
}
