<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * La propriété du tenant d'une entité persistée désigne un autre tenant que le tenant courant
 * (RG-08.3).
 *
 * @api
 */
final class TenantMismatchException extends \RuntimeException implements OrmException
{
    private function __construct(
        string $message,
        public readonly object $entity,
    ) {
        parent::__construct($message);
    }

    public static function forEntity(object $entity, string $field, int|string $expected, mixed $given): self
    {
        return new self(\sprintf('The %s entity belongs to tenant %s ($%s), but the current tenant is %s.', $entity::class, \is_scalar($given) ? var_export($given, true) : get_debug_type($given), $field, var_export($expected, true)), $entity);
    }
}
