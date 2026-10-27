<?php

declare(strict_types=1);

namespace Prism\Orm\Audit;

/**
 * Une ligne du journal d'audit (RG-08.10) : changes associe chaque champ à son ancienne et sa
 * nouvelle valeur, en valeurs de la base ; « *** » pour un champ sensible (RG-08.11).
 *
 * @api
 */
final class AuditEntry
{
    /**
     * @param class-string                       $entityClass
     * @param array<string, array{mixed, mixed}> $changes
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $entityClass,
        public readonly string $entityId,
        public readonly ?string $tenantId,
        public readonly string $action,
        public readonly array $changes,
        public readonly ?string $actor,
        public readonly ?string $requestId,
        public readonly bool $system,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
