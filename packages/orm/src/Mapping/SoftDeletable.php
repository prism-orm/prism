<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Transforme remove() en UPDATE du champ de suppression (DateTimeImmutable nullable).
 *
 * Les lignes supprimées sont exclues des lectures, jointures, batch fetching et recherches ;
 * ->withDeleted() les réintègre, EntityManager::restore() les restaure.
 * Les contraintes d'unicité tiennent compte de la suppression (RG-08.15).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SoftDeletable implements MappingAttribute
{
    public readonly string $field;

    public function __construct(string $field = 'deletedAt')
    {
        $this->field = AttributeGuard::fieldName('SoftDeletable', 'field', $field);
    }
}
