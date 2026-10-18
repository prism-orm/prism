<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Remplit createdAt et updatedAt en DateTimeImmutable UTC. updatedAt ne change que si un autre champ change.
 *
 * Les noms désignent des champs mappés de l'entité ; passer null au second désactive sa mise à jour.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Timestampable implements MappingAttribute
{
    public readonly string $createdAt;

    public readonly ?string $updatedAt;

    public function __construct(string $createdAt = 'createdAt', ?string $updatedAt = 'updatedAt')
    {
        $this->createdAt = AttributeGuard::fieldName('Timestampable', 'createdAt', $createdAt);
        $this->updatedAt = AttributeGuard::nullableFieldName('Timestampable', 'updatedAt', $updatedAt);
    }
}
