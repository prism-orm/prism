<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaManager;
use Prism\Dbal\Schema\Table;

/**
 * La table prism_migration_versions : les migrations exécutées, leur durée et leur checksum
 * (RG-06.4). Créée au premier migrate.
 *
 * @internal
 */
final class VersionStorage
{
    public const string TABLE = 'prism_migration_versions';

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function table(): Table
    {
        return new Table(self::TABLE, [
            new Column('version', 'string', length: 191),
            new Column('executed_at', 'datetime_immutable'),
            new Column('execution_time_ms', 'integer'),
            new Column('checksum', 'string', length: 64),
        ], ['version']);
    }

    public function ensure(): void
    {
        if ($this->exists()) {
            return;
        }
        foreach ($this->connection->getPlatform()->getCreateSchemaSql(new Schema([self::table()])) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * @return array<string, array{executedAt: \DateTimeImmutable, executionTimeMs: int, checksum: string}> par version, chronologique
     */
    public function executed(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select($qb->column('version'), $qb->column('executed_at'), $qb->column('execution_time_ms'), $qb->column('checksum'))
            ->from(self::TABLE)
            ->executeQuery()->fetchAllAssociative();
        $types = $this->connection->getTypeRegistry();
        $context = $this->connection->getConversionContext();

        $executed = [];
        foreach ($rows as $row) {
            $at = $types->get('datetime_immutable')->toPhp($row['executed_at'] ?? null, $context);
            $executed[self::scalar($row['version'] ?? '')] = [
                'executedAt' => $at instanceof \DateTimeImmutable ? $at : new \DateTimeImmutable('@0'),
                'executionTimeMs' => (int) self::scalar($row['execution_time_ms'] ?? 0),
                'checksum' => self::scalar($row['checksum'] ?? ''),
            ];
        }
        ksort($executed, \SORT_STRING);

        return $executed;
    }

    public function record(string $version, \DateTimeImmutable $executedAt, int $executionTimeMs, string $checksum): void
    {
        $this->connection->createQueryBuilder()->insert(self::TABLE)
            ->set('version', $version)
            ->set('executed_at', $executedAt, 'datetime_immutable')
            ->set('execution_time_ms', $executionTimeMs)
            ->set('checksum', $checksum)
            ->executeStatement();
    }

    public function remove(string $version): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->delete(self::TABLE)->where($qb->expr()->eq($qb->column('version'), $version))->executeStatement();
    }

    private function exists(): bool
    {
        return \in_array(strtolower(self::TABLE), array_map(strtolower(...), new SchemaManager($this->connection)->listTableNames()), true);
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
