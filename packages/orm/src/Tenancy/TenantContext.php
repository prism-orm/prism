<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

/**
 * Tenant courant des entités #[Tenanted] (pilier 3, ADR 0014). Sans tenant ni mode système,
 * toute opération sur une entité tenantée échoue (RG-08.4) : il n'existe pas de mode « tous
 * les tenants » implicite.
 *
 * @api
 */
interface TenantContext
{
    /**
     * Identifiant du tenant courant ; null : aucun tenant, ou mode système.
     */
    public function getTenantId(): int|string|null;

    /**
     * Mode multi-tenant global, ouvert par runAsSystem() seulement (RG-08.5).
     */
    public function isSystem(): bool;

    /**
     * Exécute $callback pour ce tenant, puis rétablit le contexte, même en cas d'exception.
     *
     * @template R
     *
     * @param \Closure(): R $callback
     *
     * @return R
     */
    public function runAs(int|string $tenantId, \Closure $callback): mixed;

    /**
     * Exécute $callback sans filtre de tenant (administration, batchs), puis rétablit le contexte.
     *
     * @template R
     *
     * @param \Closure(): R $callback
     *
     * @return R
     */
    public function runAsSystem(\Closure $callback): mixed;
}
