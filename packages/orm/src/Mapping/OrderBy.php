<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Exception\MappingException;

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
        if ([] === $fields) {
            throw MappingException::invalidAttributeArgument('OrderBy', 'fields', 'expected at least one field.');
        }

        foreach ($fields as $field => $order) {
            AttributeGuard::fieldName('OrderBy', 'fields', $field);
            if (!$order instanceof Order) {
                throw MappingException::invalidAttributeArgument('OrderBy', 'fields', \sprintf('direction for "%s" must be Order::Asc or Order::Desc.', $field));
            }
        }

        $this->fields = $fields;
    }
}
