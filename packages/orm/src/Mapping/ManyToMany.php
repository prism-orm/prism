<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Relation N vers N via une table pivot.
 *
 * - côté propriétaire (inversedBy ou unidirectionnel) : persiste la table pivot, personnalisable par #[JoinTable] ;
 * - côté inverse (mappedBy) : lecture seule pour la pivot ;
 * - targetEntity : obligatoire.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ManyToMany implements AssociationAttribute
{
    /** @var list<Cascade> */
    public readonly array $cascade;

    public readonly ?string $mappedBy;

    public readonly ?string $inversedBy;

    public readonly ?string $indexBy;

    public readonly ?int $batchSize;

    /**
     * @param class-string  $targetEntity
     * @param list<Cascade> $cascade
     */
    public function __construct(
        public readonly string $targetEntity,
        ?string $mappedBy = null,
        ?string $inversedBy = null,
        array $cascade = [],
        public readonly Fetch $fetch = Fetch::Lazy,
        ?string $indexBy = null,
        ?int $batchSize = null,
        public readonly bool $batch = true,
    ) {
        AttributeGuard::exclusive('ManyToMany', 'mappedBy', $mappedBy, 'inversedBy', $inversedBy);

        $this->mappedBy = AttributeGuard::nullableFieldName('ManyToMany', 'mappedBy', $mappedBy);
        $this->inversedBy = AttributeGuard::nullableFieldName('ManyToMany', 'inversedBy', $inversedBy);
        $this->cascade = AttributeGuard::cascade('ManyToMany', $cascade);
        $this->indexBy = AttributeGuard::nullableFieldName('ManyToMany', 'indexBy', $indexBy);
        $this->batchSize = AttributeGuard::positiveInt('ManyToMany', 'batchSize', $batchSize, 1, 1000);
    }

    public function isOwningSide(): bool
    {
        return null === $this->mappedBy;
    }

    /** @return class-string */
    public function getTargetEntity(): string
    {
        return $this->targetEntity;
    }

    public function getCascade(): array
    {
        return $this->cascade;
    }

    public function getFetch(): Fetch
    {
        return $this->fetch;
    }
}
