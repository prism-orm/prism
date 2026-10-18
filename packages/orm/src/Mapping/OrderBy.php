<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Tri appliqué au chargement d'une collection (OneToMany, ManyToMany),
 * conservé en batch fetching (RG-04.12).
 *
 * Exemple : #[OrderBy(['position' => Order::Asc, 'id' => Order::Asc])]
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OrderBy implements MappingAttribute
{
    /** @var array<string, Order> */
    public readonly array $fields;

    /**
     * @param array<string, Order> $fields
     */
    public function __construct(array $fields)
    {
        $this->fields = AttributeGuard::orderMap('OrderBy', 'fields', $fields);
    }
}
