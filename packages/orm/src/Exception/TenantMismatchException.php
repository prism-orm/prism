<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * La propriété du tenant d'une entité persistée désigne un autre tenant que le tenant courant
 * (RG-08.3), ou a changé : le tenant d'une ligne ne change jamais par l'ORM (ADR 0014).
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

    public static function changed(object $entity, string $field, mixed $from, mixed $to): self
    {
        return new self(\sprintf('The tenant of the %s entity cannot change ($%s from %s to %s); move the row with SQL in runAsSystem() if needed.', $entity::class, $field, \is_scalar($from) ? var_export($from, true) : get_debug_type($from), \is_scalar($to) ? var_export($to, true) : get_debug_type($to)), $entity);
    }
}
