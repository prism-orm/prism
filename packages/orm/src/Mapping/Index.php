<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Index sur un ou plusieurs champs. Répétable.
 *
 * fields désigne des champs PHP (pas des colonnes) ; « embedded.champ » vise un champ d'embeddable.
 * where crée un index partiel sous PostgreSQL et est ignoré ailleurs, avec un avertissement.
 * Si l'entité est #[Tenanted], tenant_id est ajouté en tête (RG-08.1).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Index implements MappingAttribute
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
        $this->fields = AttributeGuard::fieldList('Index', 'fields', $fields);
        $this->name = AttributeGuard::name('Index', 'name', $name);
        $this->where = AttributeGuard::name('Index', 'where', $where);
    }
}
