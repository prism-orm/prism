<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * Comportements d'une entité (ADR 0015) : horodatage et auteur (RG-08.13, RG-08.16), suppression
 * logique (RG-08.14), journal d'audit (RG-08.10). Les noms désignent des champs mappés ; null :
 * comportement absent, ou mise à jour désactivée pour updatedAt et updatedBy.
 *
 * @api
 */
final class BehaviorMapping
{
    /**
     * @param list<string> $auditExclude   champs omis du journal
     * @param string|null  $auditRetention durée ISO 8601 de conservation du journal
     */
    public function __construct(
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $createdBy = null,
        public readonly ?string $updatedBy = null,
        public readonly ?string $deletedAt = null,
        public readonly bool $auditable = false,
        public readonly array $auditExclude = [],
        public readonly ?string $auditRetention = null,
    ) {
    }

    public function isTimestampable(): bool
    {
        return null !== $this->createdAt;
    }

    public function isBlameable(): bool
    {
        return null !== $this->createdBy;
    }

    public function isSoftDeletable(): bool
    {
        return null !== $this->deletedAt;
    }

    /**
     * Champs écrits par l'ORM à l'UPDATE : ils ne comptent pas comme un changement (RG-08.13).
     *
     * @return list<string>
     */
    public function updateFields(): array
    {
        return array_values(array_filter([$this->updatedAt, $this->updatedBy], is_string(...)));
    }
}
