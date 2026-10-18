<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Remplit l'auteur depuis l'ActorProvider (utilisateur connecté, ou cli:<commande>).
 *
 * Les noms désignent des champs mappés de l'entité ; passer null au second désactive sa mise à jour.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Blameable implements MappingAttribute
{
    public readonly string $createdBy;

    public readonly ?string $updatedBy;

    public function __construct(string $createdBy = 'createdBy', ?string $updatedBy = 'updatedBy')
    {
        $this->createdBy = AttributeGuard::fieldName('Blameable', 'createdBy', $createdBy);
        $this->updatedBy = AttributeGuard::nullableFieldName('Blameable', 'updatedBy', $updatedBy);
    }
}
