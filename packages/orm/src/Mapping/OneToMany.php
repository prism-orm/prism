<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Côté inverse d'un ManyToOne, exposé sous forme de Collection.
 *
 * - targetEntity et mappedBy : obligatoires (le type Collection ne porte pas la cible) ;
 * - orphanRemoval : supprime au flush l'élément retiré de la collection (RG-04.5) ;
 * - indexBy : indexe la collection par un champ de la cible ($lines['SKU-42']) ;
 * - Fetch::ExtraLazy : count() par une requête COUNT, sans charger la collection ; les autres
 *   méthodes la chargent.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OneToMany implements AssociationAttribute
{
    /** @var list<Cascade> */
    public readonly array $cascade;

    public readonly string $mappedBy;

    public readonly ?string $indexBy;

    public readonly ?int $batchSize;

    /**
     * @param class-string  $targetEntity
     * @param list<Cascade> $cascade
     */
    public function __construct(
        public readonly string $targetEntity,
        string $mappedBy,
        array $cascade = [],
        public readonly Fetch $fetch = Fetch::Lazy,
        public readonly bool $orphanRemoval = false,
        ?string $indexBy = null,
        ?int $batchSize = null,
        public readonly bool $batch = true,
    ) {
        $this->mappedBy = AttributeGuard::fieldName('OneToMany', 'mappedBy', $mappedBy);
        $this->cascade = AttributeGuard::cascade('OneToMany', $cascade);
        $this->indexBy = AttributeGuard::nullableFieldName('OneToMany', 'indexBy', $indexBy);
        $this->batchSize = AttributeGuard::positiveInt('OneToMany', 'batchSize', $batchSize, 1, 1000);
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
