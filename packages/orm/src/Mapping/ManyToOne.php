<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Côté propriétaire d'une relation N vers 1 : porte la clé étrangère.
 *
 * - targetEntity : déduit du type de la propriété si null ;
 * - nullabilité de la FK : déduite du type (?Client) ;
 * - onDelete : action appliquée en base (CASCADE, SET NULL, RESTRICT) ;
 * - batch / batchSize : batch fetching anti-N+1 (pilier 1), actif par défaut.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ManyToOne implements AssociationAttribute
{
    /** @var list<Cascade> */
    public readonly array $cascade;

    public readonly ?string $inversedBy;

    public readonly ?string $joinColumn;

    public readonly ?int $batchSize;

    /**
     * @param class-string|null $targetEntity
     * @param list<Cascade>     $cascade
     */
    public function __construct(
        public readonly ?string $targetEntity = null,
        ?string $inversedBy = null,
        array $cascade = [],
        public readonly Fetch $fetch = Fetch::Lazy,
        ?string $joinColumn = null,
        public readonly OnDelete $onDelete = OnDelete::Restrict,
        ?int $batchSize = null,
        public readonly bool $batch = true,
    ) {
        $this->inversedBy = AttributeGuard::nullableFieldName('ManyToOne', 'inversedBy', $inversedBy);
        $this->cascade = AttributeGuard::cascade('ManyToOne', $cascade);
        $this->joinColumn = AttributeGuard::name('ManyToOne', 'joinColumn', $joinColumn);
        $this->batchSize = AttributeGuard::positiveInt('ManyToOne', 'batchSize', $batchSize, 1, 1000);
    }

    public function getTargetEntity(): ?string
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
