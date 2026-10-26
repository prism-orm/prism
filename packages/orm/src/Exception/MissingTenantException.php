<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Opération sur une entité #[Tenanted] sans tenant courant (RG-08.4) : Prism n'a pas de mode
 * « tous les tenants » implicite.
 *
 * @api
 */
final class MissingTenantException extends \RuntimeException implements OrmException
{
    /**
     * @param class-string $class
     */
    public static function forEntity(string $class): self
    {
        return new self(\sprintf('No current tenant for the #[Tenanted] entity %s: set one in the TenantContext, or use runAs() or runAsSystem() explicitly.', $class));
    }

    /**
     * @param class-string $class
     */
    public static function inSystemMode(string $class): self
    {
        return new self(\sprintf('Cannot write the #[Tenanted] entity %s in system mode without a tenant: wrap the write in runAs(), or set its tenant property.', $class));
    }
}
