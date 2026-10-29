<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Dbal\Query\Order;

/**
 * Tri appliqué au chargement d'une collection (OneToMany, ManyToMany),
 * conservé en batch fetching (RG-04.12).
 *
 * Exemple : #[OrderBy(['position' => Order::Asc, 'id' => Order::Asc])], avec
 * Prism\Dbal\Query\Order, le sens de tri de findBy() et du QueryBuilder.
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
