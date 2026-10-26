<?php

declare(strict_types=1);

namespace Prism\Bundle\Debug;

use Prism\Orm\Event\AuditRecordedEvent;

/**
 * Les lignes d'audit écrites pendant une requête, pour le panneau Audit (dev seulement).
 *
 * @internal
 */
final class AuditRecorder
{
    /** @var list<array{entityManager: string, class: string, id: string, tenant: ?string, action: string, changes: array<string, array{string, string}>, actor: ?string, system: bool}> */
    private array $entries = [];

    public function __construct(
        private readonly string $entityManager,
    ) {
    }

    public function onAuditRecorded(AuditRecordedEvent $event): void
    {
        foreach ($event->entries as $entry) {
            $this->entries[] = [
                'entityManager' => $this->entityManager,
                'class' => $entry->entityClass,
                'id' => $entry->entityId,
                'tenant' => $entry->tenantId,
                'action' => $entry->action,
                'changes' => array_map(static fn (array $change): array => [self::text($change[0]), self::text($change[1])], $entry->changes),
                'actor' => $entry->actor,
                'system' => $entry->system,
            ];
        }
    }

    /**
     * @return list<array{entityManager: string, class: string, id: string, tenant: ?string, action: string, changes: array<string, array{string, string}>, actor: ?string, system: bool}>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            null === $value => 'NULL',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
