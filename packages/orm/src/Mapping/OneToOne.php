<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Exception\MappingException;

/**
 * Relation 1 vers 1.
 *
 * - côté propriétaire (inversedBy ou unidirectionnel) : porte une FK UNIQUE ;
 * - côté inverse (mappedBy) : aucune colonne ; joinColumn et onDelete y sont interdits.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OneToOne implements AssociationAttribute
{
    /** @var list<Cascade> */
    public readonly array $cascade;

    public readonly ?string $mappedBy;

    public readonly ?string $inversedBy;

    public readonly ?string $joinColumn;

    public readonly ?int $batchSize;

    /**
     * @param class-string|null $targetEntity
     * @param list<Cascade>     $cascade
     */
    public function __construct(
        public readonly ?string $targetEntity = null,
        ?string $mappedBy = null,
        ?string $inversedBy = null,
        array $cascade = [],
        public readonly Fetch $fetch = Fetch::Lazy,
        ?string $joinColumn = null,
        public readonly OnDelete $onDelete = OnDelete::Restrict,
        public readonly bool $orphanRemoval = false,
        ?int $batchSize = null,
        public readonly bool $batch = true,
    ) {
        AttributeGuard::exclusive('OneToOne', 'mappedBy', $mappedBy, 'inversedBy', $inversedBy);

        if (null !== $mappedBy && (null !== $joinColumn || OnDelete::Restrict !== $onDelete)) {
            throw MappingException::invalidAttributeArgument('OneToOne', 'mappedBy', 'the inverse side cannot declare joinColumn or onDelete; set them on the owning side.');
        }

        $this->mappedBy = AttributeGuard::nullableFieldName('OneToOne', 'mappedBy', $mappedBy);
        $this->inversedBy = AttributeGuard::nullableFieldName('OneToOne', 'inversedBy', $inversedBy);
        $this->cascade = AttributeGuard::cascade('OneToOne', $cascade);
        $this->joinColumn = AttributeGuard::name('OneToOne', 'joinColumn', $joinColumn);
        $this->batchSize = AttributeGuard::positiveInt('OneToOne', 'batchSize', $batchSize, 1, 1000);
    }

    public function isOwningSide(): bool
    {
        return null === $this->mappedBy;
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
