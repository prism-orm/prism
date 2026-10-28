<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Contrainte d'unicité sur un ou plusieurs champs. Répétable.
 *
 * fields désigne des champs PHP (pas des colonnes) ; « embedded.champ » vise un champ d'embeddable.
 * where n'est pas encore pris en charge : une contrainte avec where est refusée à la construction
 * du schéma, car l'appliquer sans sa condition refuserait des lignes valides. Pour l'unicité des
 * seules lignes non supprimées, #[SoftDeletable] la pose seul (RG-08.15).
 * Si l'entité est #[Tenanted], tenant_id est ajouté en tête : UNIQUE (tenant_id, number).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class UniqueConstraint implements MappingAttribute
{
    /** @var list<string> */
    public readonly array $fields;

    public readonly ?string $name;

    public readonly ?string $where;

    /**
     * @param list<string> $fields
     */
    public function __construct(array $fields, ?string $name = null, ?string $where = null)
    {
        $this->fields = AttributeGuard::fieldList('UniqueConstraint', 'fields', $fields);
        $this->name = AttributeGuard::name('UniqueConstraint', 'name', $name);
        $this->where = AttributeGuard::name('UniqueConstraint', 'where', $where);
    }
}
