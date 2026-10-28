<?php

declare(strict_types=1);

namespace Prism\Migrations\Lint;

use Prism\Dbal\Connection;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Query\QueryBuilder;

/**
 * Les questions du linter, posées à la base par le QueryBuilder du DBAL : identifiants cités,
 * aucune valeur concaténée.
 *
 * @internal
 */
final class DatabaseInspector implements DataInspector
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function isEmpty(string $table): bool
    {
        return !$this->hasMoreRowsThan($table, 0);
    }

    // une ligne après les $rows premières : sans COUNT(*) sur toute la table
    public function hasMoreRowsThan(string $table, int $rows): bool
    {
        $qb = $this->connection->createQueryBuilder();

        return $this->exists($qb->select('1')->from($table)->limit(1)->offset($rows));
    }

    public function hasNulls(string $table, string $column): bool
    {
        $qb = $this->connection->createQueryBuilder();

        return $this->exists($qb->select('1')->from($table)->where($qb->expr()->isNull($qb->column($column)))->limit(1));
    }

    public function largestGroup(string $table, string $column): int
    {
        $qb = $this->connection->createQueryBuilder();
        $count = $qb->select($qb->expr()->count())->from($table)->groupBy($qb->column($column))
            ->orderBy($qb->expr()->count(), Order::Desc)->limit(1)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function hasDuplicates(string $table, array $columns): bool
    {
        $qb = $this->connection->createQueryBuilder();
        $quoted = array_map(static fn (string $c): string => $qb->column($c), $columns);
        $qb = $qb->select(...$quoted)->from($table)->groupBy(...$quoted)->having($qb->expr()->count() . ' > 1');
        // une valeur nulle n'entre pas en conflit dans un index unique
        foreach ($quoted as $column) {
            $qb = $qb->andWhere($qb->expr()->isNotNull($column));
        }

        return $this->exists($qb->limit(1));
    }

    public function hasOrphans(string $table, array $localColumns, string $foreignTable, array $foreignColumns): bool
    {
        $qb = $this->connection->createQueryBuilder();
        $on = [];
        foreach ($localColumns as $i => $local) {
            $on[] = $qb->column($foreignColumns[$i], 'p') . ' = ' . $qb->column($local, 'c');
        }
        $qb = $qb->select('1')->from($table, 'c')->leftJoin($foreignTable, 'p', implode(' AND ', $on))
            ->where($qb->expr()->isNotNull($qb->column($localColumns[0], 'c')))
            ->andWhere($qb->expr()->isNull($qb->column($foreignColumns[0], 'p')));

        return $this->exists($qb->limit(1));
    }

    private function exists(QueryBuilder $qb): bool
    {
        return false !== $qb->executeQuery()->fetchOne();
    }
}
