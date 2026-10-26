<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Relation entre deux entités #[Tenanted] de tenants différents, refusée au flush (RG-08.6).
 *
 * @api
 */
final class CrossTenantRelationException extends \RuntimeException implements OrmException
{
    private function __construct(
        string $message,
        public readonly object $entity,
        public readonly string $field,
        public readonly object $target,
    ) {
        parent::__construct($message);
    }

    public static function between(object $entity, string $field, object $target, int|string|null $entityTenant, int|string|null $targetTenant): self
    {
        return new self(\sprintf('%s::$%s (tenant %s) refers to a %s entity of tenant %s; relations between tenants are refused.', $entity::class, $field, var_export($entityTenant, true), $target::class, var_export($targetTenant, true)), $entity, $field, $target);
    }
}
