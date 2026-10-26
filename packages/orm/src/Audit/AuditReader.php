<?php

declare(strict_types=1);

namespace Prism\Orm\Audit;

use Prism\Orm\EntityManager;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\MissingTenantException;

/**
 * Le journal d'audit d'une entité (RG-08.10, ADR 0015) : son historique, et son état à une date,
 * rejoué depuis les changesets. Une entité #[Tenanted] n'a d'historique que dans le tenant courant
 * (tous en mode système) ; sans tenant, la lecture échoue (RG-08.4).
 *
 * @api
 */
final class AuditReader
{
    public function __construct(
        private readonly EntityManager $em,
    ) {
    }

    /**
     * Les lignes de l'entité, de la plus ancienne à la plus récente.
     *
     * @param class-string $class
     *
     * @return list<AuditEntry>
     *
     * @throws MappingException
     * @throws MissingTenantException entité tenantée sans tenant courant
     */
    public function history(string $class, mixed $id): array
    {
        $metadata = $this->em->getMetadataFactory()->getMetadataFor($class);
        $connection = $this->em->getConnection();
        $qb = $connection->createQueryBuilder();
        $columns = ['id', 'entity_class', 'entity_id', 'tenant_id', 'action', 'changes', 'actor', 'request_id', 'system', 'occurred_at'];
        $qb = $qb->select(...array_map($qb->column(...), $columns))->from(AuditLog::TABLE)
            ->where($qb->expr()->eq($qb->column('entity_class'), $metadata->className))
            ->andWhere($qb->expr()->eq($qb->column('entity_id'), self::key($connection->convertToDatabase($id, $metadata->getIdField()->type))));

        $tenancy = $metadata->tenancy;
        $context = $this->em->getTenantContext();
        if (null !== $tenancy && !$context->isSystem()) {
            $tenant = $context->getTenantId() ?? throw MissingTenantException::forEntity($metadata->className);
            $own = $qb->expr()->eq($qb->column('tenant_id'), self::key($connection->convertToDatabase($tenant, $tenancy->type)));
            $qb = $qb->andWhere($tenancy->global ? $qb->expr()->or($own, $qb->expr()->isNull($qb->column('tenant_id'))) : $own);
        }

        $entries = [];
        foreach ($qb->orderBy($qb->column('id'))->executeQuery()->fetchAllAssociative() as $row) {
            /** @var array<string, array{mixed, mixed}> $changes */
            $changes = $connection->convertToPhp($row['changes'], 'json') ?? [];
            $occurredAt = $connection->convertToPhp($row['occurred_at'], 'datetime_immutable');
            \assert($occurredAt instanceof \DateTimeImmutable);
            $entries[] = new AuditEntry(
                is_numeric($row['id']) ? (int) $row['id'] : null,
                $metadata->className,
                self::key($row['entity_id']),
                null === $row['tenant_id'] ? null : self::key($row['tenant_id']),
                self::key($row['action']),
                $changes,
                null === $row['actor'] ? null : self::key($row['actor']),
                null === $row['request_id'] ? null : self::key($row['request_id']),
                true === $connection->convertToPhp($row['system'], 'boolean'),
                $occurredAt,
            );
        }

        return $entries;
    }

    /**
     * L'état de l'entité à une date, champ → valeur de la base (« *** » pour un champ sensible) ;
     * null si elle n'existait pas encore, ou plus.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>|null
     *
     * @throws MappingException
     * @throws MissingTenantException
     */
    public function stateAt(string $class, mixed $id, \DateTimeImmutable $at): ?array
    {
        $state = null;
        foreach ($this->history($class, $id) as $entry) {
            if ($entry->occurredAt > $at) {
                break;
            }
            if (AuditLog::DELETE === $entry->action) {
                $state = null;
                continue;
            }
            $state ??= [];
            foreach ($entry->changes as $field => [, $value]) {
                $state[$field] = $value;
            }
        }

        return $state;
    }

    private static function key(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
