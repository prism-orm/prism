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
        $rows = $this->matching($criteria);
        $sort = [];
        foreach ($orderBy as $field => $order) {
            $sort[$this->metadata->getField($field)->columnName] = $order;
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
                $stored = $row[$this->metadata->getField($field)->columnName] ?? null;
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

    private static function scalar(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return \is_scalar($value) ? (string) $value : null;
    }
}
