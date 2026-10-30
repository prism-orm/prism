<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Persister\EntityPersister;
use Prism\Orm\Persister\PersisterProvider;

/**
 * Stockage en mémoire pour les scénarios de l'UnitOfWork : journal des écritures, transaction
 * avec rollback réel, échec provoqué au N-ième INSERT.
 *
 * @internal
 */
final class InMemoryStorage implements PersisterProvider
{
    /** @var array<string, array<string, array<string, mixed>>> table → id → ligne */
    public array $tables = [];

    /** @var list<array{string, string, list<string>}> opération, table, colonnes */
    public array $log = [];

    /** @var array<string, list<array<string, mixed>>> table pivot → lignes */
    public array $pivots = [];

    public bool $inTransaction = false;

    public int $transactions = 0;

    /** @var array<string, int> */
    private array $sequences = [];

    private ?int $failOnInsert = null;

    private int $inserts = 0;

    public function getPersister(ClassMetadata $metadata): EntityPersister
    {
        return new InMemoryPersister($this, $metadata);
    }

    public function withoutFilter(string $name, \Closure $operation): mixed
    {
        return $operation();
    }

    public function isTransactionActive(): bool
    {
        return $this->inTransaction;
    }

    // SEQUENCE : identifiant connu avant l'INSERT, compteur rétabli par le rollback
    public function nextId(ClassMetadata $metadata): ?int
    {
        if (GenerationStrategy::Sequence !== $metadata->id->strategy) {
            return null;
        }
        $name = $metadata->tableName . '_seq';

        return $this->sequences[$name] = ($this->sequences[$name] ?? 100) + 1;
    }

    public function transactional(\Closure $operations): mixed
    {
        $tables = $this->tables;
        $pivots = $this->pivots;
        $sequences = $this->sequences;
        $outer = $this->inTransaction;
        $this->inTransaction = true;
        ++$this->transactions;

        try {
            $result = $operations();
        } catch (\Throwable $e) {
            // rollback : la base revient à son état d'avant la transaction
            $this->tables = $tables;
            $this->pivots = $pivots;
            $this->sequences = $sequences;
            throw $e;
        } finally {
            $this->inTransaction = $outer;
        }

        return $result;
    }

    /**
     * Le N-ième INSERT à venir échoue (compté à partir de maintenant).
     */
    public function failOnInsert(?int $n): void
    {
        $this->failOnInsert = $n;
        $this->inserts = 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function seed(string $table, string $idColumn, array $row): void
    {
        $this->tables[$table][(string) self::key($row[$idColumn] ?? null)] = $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(string $table, int|string $id): array
    {
        return $this->tables[$table][(string) $id] ?? throw new \LogicException(\sprintf('No row %s in "%s".', $id, $table));
    }

    /**
     * @param array<string, mixed> $row
     */
    public function seedPivot(string $table, array $row): void
    {
        $this->pivots[$table][] = $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pivotRows(string $table): array
    {
        return $this->pivots[$table] ?? [];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @internal appelé par InMemoryPersister
     */
    public function insertPivot(string $table, array $row): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException('INSERT outside of a transaction.');
        }
        self::assertScalar($table, $row);
        $this->pivots[$table][] = $row;
        $this->log[] = ['INSERT', $table, array_keys($row)];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @internal appelé par InMemoryPersister
     */
    public function deletePivot(string $table, array $criteria): int
    {
        if (!$this->inTransaction) {
            throw new \LogicException('DELETE outside of a transaction.');
        }
        $this->log[] = ['DELETE', $table, array_keys($criteria)];
        $kept = array_values(array_filter($this->pivots[$table] ?? [], static function (array $row) use ($criteria): bool {
            foreach ($criteria as $column => $value) {
                if ((string) self::key($row[$column] ?? null) !== (string) self::key($value)) {
                    return true;
                }
            }

            return false;
        }));
        $deleted = \count($this->pivots[$table] ?? []) - \count($kept);
        $this->pivots[$table] = $kept;

        return $deleted;
    }

    // ligne supprimée hors de l'UnitOfWork (autre processus)
    public function deleteRow(string $table, int|string $id): void
    {
        unset($this->tables[$table][(string) $id]);
    }

    public function setColumn(string $table, int|string $id, string $column, mixed $value): void
    {
        $row = $this->row($table, $id);
        $row[$column] = $value;
        $this->tables[$table][(string) $id] = $row;
    }

    /**
     * @return list<array{string, list<string>}> table et colonnes de chaque opération du type donné
     */
    public function operations(string $type): array
    {
        return array_values(array_map(static fn (array $o): array => [$o[1], $o[2]], array_filter($this->log, static fn (array $o): bool => $o[0] === $type)));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @internal appelé par InMemoryPersister
     */
    public function insert(string $table, string $idColumn, bool $identity, array $row): ?int
    {
        if (!$this->inTransaction) {
            throw new \LogicException('INSERT outside of a transaction.');
        }
        self::assertScalar($table, $row);
        if (null !== $this->failOnInsert && ++$this->inserts === $this->failOnInsert) {
            throw new \RuntimeException(\sprintf('Simulated failure on INSERT #%d into "%s".', $this->failOnInsert, $table));
        }

        $generated = null;
        if ($identity && null === ($row[$idColumn] ?? null)) {
            $generated = $this->sequences[$table] = ($this->sequences[$table] ?? 0) + 1;
            $row[$idColumn] = $generated;
        }
        $this->tables[$table][(string) self::key($row[$idColumn] ?? null)] = $row;
        $this->log[] = ['INSERT', $table, array_keys($row)];

        return $generated;
    }

    /**
     * @param array<string, mixed>      $changes
     * @param array{string, mixed}|null $version
     *
     * @internal
     */
    public function update(string $table, mixed $id, array $changes, ?array $version): int
    {
        if (!$this->inTransaction) {
            throw new \LogicException('UPDATE outside of a transaction.');
        }
        self::assertScalar($table, $changes);
        $key = (string) self::key($id);
        $this->log[] = ['UPDATE', $table, array_keys($changes)];
        if (!isset($this->tables[$table][$key]) || (null !== $version && $this->tables[$table][$key][$version[0]] !== $version[1])) {
            return 0;
        }
        $this->tables[$table][$key] = $changes + $this->tables[$table][$key];

        return 1;
    }

    /**
     * @param array{string, mixed}|null $version
     *
     * @internal
     */
    public function delete(string $table, mixed $id, ?array $version): int
    {
        if (!$this->inTransaction) {
            throw new \LogicException('DELETE outside of a transaction.');
        }
        $key = (string) self::key($id);
        $this->log[] = ['DELETE', $table, []];
        if (!isset($this->tables[$table][$key]) || (null !== $version && $this->tables[$table][$key][$version[0]] !== $version[1])) {
            return 0;
        }
        unset($this->tables[$table][$key]);

        return 1;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @internal
     */
    public function load(string $table, mixed $id): ?array
    {
        $this->log[] = ['SELECT', $table, []];

        return $this->tables[$table][(string) self::key($id)] ?? null;
    }

    /**
     * Comme une vraie base : seules des valeurs scalaires (ou NULL) sont écrites.
     *
     * @param array<string, mixed> $values
     */
    private static function assertScalar(string $table, array $values): void
    {
        foreach ($values as $column => $value) {
            if (null !== $value && !\is_scalar($value)) {
                throw new \LogicException(\sprintf('Cannot write a %s into "%s"."%s".', get_debug_type($value), $table, $column));
            }
        }
    }

    private static function key(mixed $id): string|int
    {
        return \is_int($id) || \is_string($id) ? $id : '';
    }
}
