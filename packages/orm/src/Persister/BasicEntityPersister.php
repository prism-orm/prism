<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

use Prism\Dbal\Connection;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Id\GenerationStrategyResolver;
use Prism\Orm\Id\IdentityGenerator;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;

/**
 * Écrit et lit les lignes d'une entité par le QueryBuilder du DBAL : noms cités, valeurs liées,
 * aucun SQL brut. Les lignes reçues et rendues contiennent des valeurs de la base.
 *
 * @internal
 */
final class BasicEntityPersister implements EntityPersister
{
    private readonly string $idColumn;

    /** @var array<string, ParameterType> type de liaison par colonne */
    private readonly array $bindings;

    private readonly bool $identity;

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ClassMetadata $metadata,
    ) {
        $this->idColumn = $metadata->getIdField()->columnName;
        $bindings = [];
        foreach ($metadata->getColumnTypes() as $column => $type) {
            $bindings[$column] = $connection->getTypeRegistry()->get($type)->getBindingType();
        }
        $this->bindings = $bindings;
        $this->identity = GenerationStrategy::Identity === GenerationStrategyResolver::resolve($metadata->id->strategy, $connection->getPlatform());
    }

    public function insert(array $row): ?string
    {
        if ($this->identity && null === ($row[$this->idColumn] ?? null)) {
            // la base attribue l'identifiant : un NULL explicite serait refusé par PostgreSQL
            unset($row[$this->idColumn]);
        }

        $qb = $this->connection->createQueryBuilder()->insert($this->metadata->tableName);
        foreach ($row as $column => $value) {
            $qb = $qb->set($column, $value, $this->binding($column, $value));
        }
        $qb->executeStatement();

        return $this->identity && !\array_key_exists($this->idColumn, $row) ? new IdentityGenerator()->generate($this->connection) : null;
    }

    public function update(mixed $id, array $changes, ?array $version = null): int
    {
        if ([] === $changes) {
            return 0;
        }

        $qb = $this->connection->createQueryBuilder()->update($this->metadata->tableName);
        foreach ($changes as $column => $value) {
            $qb = $qb->set($column, $value, $this->binding($column, $value));
        }

        return $this->whereKey($qb, $id, $version)->executeStatement();
    }

    public function delete(mixed $id, ?array $version = null): int
    {
        return $this->whereKey($this->connection->createQueryBuilder()->delete($this->metadata->tableName), $id, $version)->executeStatement();
    }

    public function loadById(mixed $id): ?array
    {
        $row = $this->whereKey($this->select(), $id, null)->executeQuery()->fetchAssociative();

        return false === $row ? null : $row;
    }

    /**
     * Lignes qui satisfont les critères (champ → valeur PHP ; null : IS NULL ; liste : IN).
     *
     * @param array<string, mixed> $criteria
     * @param array<string, Order> $orderBy  champ → sens
     *
     * @return list<array<string, mixed>>
     *
     * @throws MappingException champ inconnu
     */
    public function loadBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->where($this->select(), $criteria);
        foreach ($orderBy as $field => $order) {
            $qb = $qb->addOrderBy($qb->column($this->field($field)->columnName), $order);
        }

        return $qb->limit($limit)->offset($offset)->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @throws MappingException champ inconnu
     */
    public function count(array $criteria = []): int
    {
        $qb = $this->connection->createQueryBuilder()->select('COUNT(*)')->from($this->metadata->tableName);
        $count = $this->where($qb, $criteria)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function select(): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select(...array_map($qb->column(...), array_keys($this->bindings)))->from($this->metadata->tableName);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function where(QueryBuilder $qb, array $criteria): QueryBuilder
    {
        foreach ($criteria as $name => $value) {
            $field = $this->field($name);
            $column = $qb->column($field->columnName);
            $binding = $this->bindings[$field->columnName];

            $condition = match (true) {
                null === $value => $qb->expr()->isNull($column),
                \is_array($value) => $qb->expr()->in($column, array_values(array_map(fn (mixed $v): mixed => $this->toDatabase($field, $v), $value)), $binding),
                default => $qb->expr()->eq($column, $this->toDatabase($field, $value), $binding),
            };
            $qb = $qb->andWhere($condition);
        }

        return $qb;
    }

    /**
     * @param array{string, mixed}|null $version
     */
    private function whereKey(QueryBuilder $qb, mixed $id, ?array $version): QueryBuilder
    {
        $qb = $qb->where($qb->expr()->eq($qb->column($this->idColumn), $id, $this->bindings[$this->idColumn]));
        if (null !== $version) {
            $qb = $qb->andWhere($qb->expr()->eq($qb->column($version[0]), $version[1], $this->bindings[$version[0]] ?? null));
        }

        return $qb;
    }

    private function field(string $name): FieldMapping
    {
        if (!$this->metadata->hasField($name)) {
            throw MappingException::unknownField($this->metadata->className, $name);
        }

        return $this->metadata->getField($name);
    }

    // valeur PHP d'un critère → valeur de la base (enum ramené à sa valeur, puis Type du DBAL)
    private function toDatabase(FieldMapping $field, mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return $this->connection->convertToDatabase($value, $field->type);
    }

    private function binding(string $column, mixed $value): ParameterType
    {
        return null === $value ? ParameterType::Null : ($this->bindings[$column] ?? ParameterType::String);
    }
}
