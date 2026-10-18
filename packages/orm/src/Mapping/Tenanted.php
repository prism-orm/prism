<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Isole l'entité par tenant (pilier 3).
 *
 * - colonne tenant_id NOT NULL indexée, remplie à l'INSERT depuis le TenantContext ;
 * - filtre appliqué à toutes les lectures, jointures, chargements paresseux et batch fetching ;
 * - global: true marque une entité partagée entre tenants (référentiel) pouvant être cible de relation.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Tenanted implements MappingAttribute
{
    public readonly ?string $column;

    public function __construct(
        public readonly bool $global = false,
        ?string $column = null,
        public readonly bool $strictForeignKeys = false,
    ) {
        $this->column = AttributeGuard::name('Tenanted', 'column', $column);
    }
}
