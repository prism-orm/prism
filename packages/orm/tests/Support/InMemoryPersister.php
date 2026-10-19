<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Prism\Dbal\LockMode;
use Prism\Dbal\Query\Order;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Persister\EntityPersister;

/**
 * @internal
 */
final class InMemoryPersister implements EntityPersister
{
    private readonly string $table;

    private readonly string $idColumn;

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function __construct(
        private readonly InMemoryStorage $storage,
        private readonly ClassMetadata $metadata,
    ) {
        $this->table = $metadata->tableName;
        $this->idColumn = $metadata->getIdField()->columnName;
    }

    public function insert(array $row): ?int
    {
        return $this->storage->insert($this->table, $this->idColumn, GenerationStrategy::Identity === $this->metadata->id->strategy, $row);
    }

    public function update(mixed $id, array $changes, ?array $version = null): int
    {
        return $this->storage->update($this->table, $id, $changes, $version);
    }

    public function delete(mixed $id, ?array $version = null): int
    {
        return $this->storage->delete($this->table, $id, $version);
    }

    public function loadById(mixed $id): ?array
    {
        return $this->storage->load($this->table, $id);
    }

    public function loadBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        self::assertOrder($orderBy);
        $rows = $this->matching($criteria);
        $sort = [];
        foreach ($orderBy as $field => $order) {
            $sort[$this->column($field)] = $order;
        }
        usort($rows, static function (array $a, array $b) use ($sort): int {
            foreach ($sort as $column => $order) {
                $result = ($a[$column] ?? null) <=> ($b[$column] ?? null);
                if (0 !== $result) {
                    return Order::Desc === $order ? -$result : $result;
                }
            }

            return 0;
        });
        $this->storage->log[] = ['SELECT', $this->table, []];

        return \array_slice($rows, $offset, $limit);
    }

    public function loadAfter(array $criteria, mixed $afterId, int $limit): array
    {
        $rows = array_values(array_filter($this->matching($criteria), fn (array $row): bool => null === $afterId || $row[$this->idColumn] > $afterId));
        usort($rows, fn (array $a, array $b): int => $a[$this->idColumn] <=> $b[$this->idColumn]);
        $this->storage->log[] = ['SELECT', $this->table, []];

        return \array_slice($rows, 0, $limit);
    }

    public function count(array $criteria = []): int
    {
        $this->storage->log[] = ['COUNT', $this->table, []];

        return \count($this->matching($criteria));
    }

    public function lock(mixed $id, LockMode $mode): bool
    {
        if (!$this->storage->inTransaction) {
            throw new \LogicException('LOCK outside of a transaction.');
        }
        $this->storage->log[] = ['LOCK', $this->table, [$mode->name]];

        return isset($this->storage->tables[$this->table][(string) (\is_int($id) || \is_string($id) ? $id : '')]);
    }

    public function loadByJoinTable(string $table, string $ownerColumn, string $targetColumn, mixed $ownerId, string $ownerType, array $orderBy = []): array
    {
        self::assertOrder($orderBy);
        $ids = [];
        foreach ($this->storage->pivots[$table] ?? [] as $row) {
            if (self::scalar($row[$ownerColumn]) === self::scalar($ownerId)) {
                $ids[] = self::scalar($row[$targetColumn]);
            }
        }
        $rows = array_values(array_filter($this->storage->tables[$this->table] ?? [], fn (array $row): bool => \in_array(self::scalar($row[$this->idColumn]), $ids, true)));
        $sort = [] === $orderBy ? [$this->idColumn => Order::Asc] : [];
        foreach ($orderBy as $field => $order) {
            $sort[$this->column($field)] = $order;
        }
        usort($rows, static function (array $a, array $b) use ($sort): int {
            foreach ($sort as $column => $order) {
                $result = ($a[$column] ?? null) <=> ($b[$column] ?? null);
                if (0 !== $result) {
                    return Order::Desc === $order ? -$result : $result;
                }
            }

            return 0;
        });
        $this->storage->log[] = ['SELECT', $this->table, []];

        return $rows;
    }

    public function countJoinTable(string $table, string $ownerColumn, mixed $ownerId, string $ownerType): int
    {
        $this->storage->log[] = ['COUNT', $table, []];

        return \count(array_filter($this->storage->pivots[$table] ?? [], static fn (array $row): bool => self::scalar($row[$ownerColumn]) === self::scalar($ownerId)));
    }

    public function insertJoinRow(string $table, array $row, array $types): void
    {
        self::assertTyped($row, $types);
        $this->storage->insertPivot($table, $row);
    }

    public function deleteJoinRows(string $table, array $criteria, array $types): int
    {
        self::assertTyped($criteria, $types);

        return $this->storage->deletePivot($table, $criteria);
    }

    /**
     * Comme le persister du DBAL : chaque colonne écrite a son type.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $types
     */
    private static function assertTyped(array $values, array $types): void
    {
        foreach (array_keys($values) as $column) {
            if (!isset($types[$column])) {
                throw new \LogicException(\sprintf('No type for the column "%s".', $column));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $orderBy
     */
    private static function assertOrder(array $orderBy): void
    {
        foreach ($orderBy as $order) {
            if (!$order instanceof Order) {
                throw new \LogicException(\sprintf('Expected a %s, got %s.', Order::class, get_debug_type($order)));
            }
        }
    }

    /**
     * @param array<string, mixed> $criteria champ → valeur PHP
     *
     * @return list<array<string, mixed>>
     */
    private function matching(array $criteria): array
    {
        $rows = [];
        foreach ($this->storage->tables[$this->table] ?? [] as $row) {
            foreach ($criteria as $field => $wanted) {
                $stored = $row[$this->column($field)] ?? null;
                $match = match (true) {
                    null === $wanted => null === $stored,
                    \is_array($wanted) => \in_array(self::scalar($stored), array_map(self::scalar(...), $wanted), true),
                    default => self::scalar($stored) === self::scalar($wanted),
                };
                if (!$match) {
                    continue 2;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    // un champ, ou la colonne de jointure d'une relation ToOne possédée
    private function column(string $field): string
    {
        if ($this->metadata->hasAssociation($field)) {
            return (string) $this->metadata->getAssociation($field)->joinColumn;
        }

        return $this->metadata->getField($field)->columnName;
    }

    private static function scalar(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return \is_scalar($value) ? (string) $value : null;
    }
}
