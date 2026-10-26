<?php

declare(strict_types=1);

namespace Prism\Orm\Audit;

use Prism\Dbal\Connection;
use Prism\Orm\Behavior\ActorProvider;
use Psr\Clock\ClockInterface;

/**
 * Écrit les lignes d'audit d'un flush, dans sa transaction (RG-08.10) : auteur, identifiant de
 * requête et date UTC ajoutés ici. Ajout seul : aucune méthode ne modifie une ligne (RG-08.12).
 *
 * @internal
 */
final class AuditWriter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly ?ActorProvider $actors = null,
        private readonly ?RequestIdProvider $requestIds = null,
    ) {
    }

    /**
     * @param list<array{class-string, string, ?string, string, array<string, array{mixed, mixed}>, bool}> $records classe, id, tenant, action, changements, mode système
     *
     * @return list<AuditEntry>
     */
    public function write(array $records): array
    {
        if ([] === $records) {
            return [];
        }
        $actor = $this->actors?->getActor();
        $requestId = $this->requestIds?->getRequestId();
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $entries = [];
        foreach ($records as [$class, $id, $tenant, $action, $changes, $system]) {
            $this->connection->createQueryBuilder()->insert(AuditLog::TABLE)
                ->set('entity_class', $class)
                ->set('entity_id', $id)
                ->set('tenant_id', $tenant)
                ->set('action', $action)
                ->set('changes', $changes, 'json')
                ->set('actor', $actor)
                ->set('request_id', $requestId)
                ->set('system', $system, 'boolean')
                ->set('occurred_at', $now, 'datetime_immutable')
                ->executeStatement();
            $entries[] = new AuditEntry(null, $class, $id, $tenant, $action, $changes, $actor, $requestId, $system, $now);
        }

        return $entries;
    }
}
