<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\Fetch;
use Prism\Orm\Mapping\OnDelete;
use Prism\Orm\Mapping\Order;

/**
 * Relation vers une autre entité. Seul le côté propriétaire est persisté (RG-04.1) : celui qui
 * porte la colonne de jointure, ou la table pivot d'un ManyToMany.
 *
 * @api
 */
final class AssociationMapping
{
    /**
     * @param class-string         $sourceEntity
     * @param class-string         $targetEntity
     * @param list<Cascade>        $cascade
     * @param array<string, Order> $orderBy
     */
    public function __construct(
        public readonly string $sourceEntity,
        public readonly string $fieldName,
        public readonly AssociationType $type,
        public readonly string $targetEntity,
        public readonly ?string $mappedBy = null,
        public readonly ?string $inversedBy = null,
        public readonly ?string $joinColumn = null,
        public readonly bool $nullable = true,
        public readonly OnDelete $onDelete = OnDelete::Restrict,
        public readonly ?JoinTableMapping $joinTable = null,
        public readonly array $cascade = [],
        public readonly Fetch $fetch = Fetch::Lazy,
        public readonly bool $orphanRemoval = false,
        public readonly ?string $indexBy = null,
        public readonly array $orderBy = [],
        public readonly ?int $batchSize = null,
        public readonly bool $batch = true,
    ) {
        $fail = static fn (string $reason): MappingException => MappingException::invalidField($sourceEntity, $fieldName, $reason);

        if (null !== $mappedBy && null !== $inversedBy) {
            throw $fail('mappedBy and inversedBy cannot be combined.');
        }
        if (AssociationType::OneToMany === $type && null === $mappedBy) {
            throw $fail('a OneToMany is always the inverse side and needs mappedBy.');
        }
        if (AssociationType::ManyToOne === $type && null !== $mappedBy) {
            throw $fail('a ManyToOne is always the owning side and cannot use mappedBy.');
        }
        if ($this->isOwningSide() && $type->isToOne() && null === $joinColumn) {
            throw $fail('the owning side of a to-one relation needs a join column.');
        }
        if ($this->isOwningSide() && AssociationType::ManyToMany === $type && null === $joinTable) {
            throw $fail('the owning side of a ManyToMany needs a join table.');
        }
        if (!$this->isOwningSide() && (null !== $joinColumn || null !== $joinTable)) {
            throw $fail('the inverse side (mappedBy) cannot declare a join column or a join table.');
        }
    }

    public function isOwningSide(): bool
    {
        return null === $this->mappedBy && AssociationType::OneToMany !== $this->type;
    }

    public function isToOne(): bool
    {
        return $this->type->isToOne();
    }

    public function isCollection(): bool
    {
        return $this->type->isCollection();
    }
}
