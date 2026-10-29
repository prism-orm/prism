<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

use Prism\Dbal\Query\Expression;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Filter\EntityFilter;
use Prism\Orm\Filter\FilterOperation;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * tenant_id = :tenant sur chaque table #[Tenanted] (RG-08.2) ; les lignes partagées d'un
 * #[Tenanted(global: true)] sont lues, jamais écrites, par un tenant (ADR 0014). Sans tenant,
 * MissingTenantException (RG-08.4) ; en mode système, aucun filtre (RG-08.5). Verrouillé dans
 * la FilterCollection : il ne se désactive pas.
 *
 * @internal
 */
final class TenantFilter implements EntityFilter
{
    public const string NAME = 'tenant';

    public function __construct(
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @throws MissingTenantException entité tenantée sans tenant courant hors mode système
     */
    public function condition(ClassMetadata $metadata, FilterTarget $target): ?Expression
    {
        $tenancy = $metadata->tenancy;
        if (null === $tenancy || $this->context->isSystem()) {
            return null;
        }
        $tenantId = $this->context->getTenantId() ?? throw MissingTenantException::forEntity($metadata->className);
        $column = $target->column($tenancy->column);
        $condition = $target->expr()->eq($column, $tenantId, $tenancy->type);

        return $tenancy->global && FilterOperation::Write !== $target->operation
            ? $target->expr()->or($condition, $target->expr()->isNull($column))
            : $condition;
    }
}
