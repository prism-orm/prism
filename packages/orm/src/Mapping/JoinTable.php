<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Personnalise la table pivot d'un ManyToMany, côté propriétaire uniquement.
 *
 * Par défaut : nom par la NamingStrategy (article_tag), colonnes <source>_id et <cible>_id,
 * ON DELETE CASCADE des deux côtés (supprimer un article supprime ses liens, pas ses tags).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class JoinTable implements MappingAttribute
{
    public readonly ?string $name;

    public readonly ?string $joinColumn;

    public readonly ?string $inverseJoinColumn;

    public function __construct(
        ?string $name = null,
        ?string $joinColumn = null,
        ?string $inverseJoinColumn = null,
        public readonly OnDelete $onDelete = OnDelete::Cascade,
    ) {
        $this->name = AttributeGuard::name('JoinTable', 'name', $name);
        $this->joinColumn = AttributeGuard::name('JoinTable', 'joinColumn', $joinColumn);
        $this->inverseJoinColumn = AttributeGuard::name('JoinTable', 'inverseJoinColumn', $inverseJoinColumn);
    }
}
