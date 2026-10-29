<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

use Prism\Dbal\Connection;
use Prism\Dbal\LockMode;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Dbal\Schema\VectorDistance;
use Prism\Dbal\Type\Type;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Filter\FilterCollection;
use Prism\Orm\Filter\FilterOperation;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Id\GenerationStrategyResolver;
use Prism\Orm\Id\IdentityGenerator;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;

/**
 * Écrit et lit les lignes d'une entité par le QueryBuilder du DBAL : noms cités, valeurs liées,
 * aucun SQL brut. Les lignes reçues et rendues contiennent des valeurs de la base.
 *
 * Chaque lecture et chaque UPDATE ou DELETE porte la condition des filtres de l'EntityManager,
 * dont celle du tenant (RG-08.2, ADR 0014) ; une table pivot n'en a pas, sa cible si.
 *
 * @internal
 */
final class BasicEntityPersister implements EntityPersister
{
    // colonne du propriétaire dans le résultat d'une jointure sur une table pivot
    private const string OWNER_ALIAS = 'prism_owner';

    // alias de la table de l'entité jointe à une table pivot
    private const string TARGET_ALIAS = 't';

    /** Colonne de la distance dans les lignes de loadNearest(). */
    public const string DISTANCE_ALIAS = 'prism_distance';

    private const string VECTOR_PARAMETER = 'nearest_vector';

    private const string SCORE_ALIAS = 'prism_score';

    /** Colonne du nombre de lignes dans countGroupedBy(). */
    public const string COUNT_ALIAS = 'prism_count';

    private readonly string $idColumn;

    /** @var array<string, ParameterType> type de liaison par colonne */
    private readonly array $bindings;

    private readonly bool $identity;

    /** @var array<string, ParameterType> type de liaison de la colonne du tenant que ne porte aucun champ */
    private readonly array $tenantBinding;

    /** @var array<string, ParameterType> colonnes d'état des #[EmbeddedVector], écrites jamais lues (ADR 0018) */
    private readonly array $embeddingBindings;

    /** @var array<string, FieldMapping> colonne de jointure de chaque relation possédée, typée comme l'identifiant visé */
    private readonly array $joins;

    /** @var array<string, true> colonnes des champs paresseux : seule leur nullité est lue (RG-09.6) */
    private readonly array $lazy;

    /** @var array<string, Type> colonnes dont le paramètre est enveloppé par la plateforme (vector, ADR 0017) */
    private readonly array $wrapped;

    /**
     * @param ClassMetadata<object>                $metadata
     * @param array<string, array{string, string}> $joinColumns relation ToOne possédée → colonne et type
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ClassMetadata $metadata,
        array $joinColumns = [],
        private readonly FilterCollection $filters = new FilterCollection(),
    ) {
        $this->idColumn = $metadata->getIdField()->columnName;
        $bindings = [];
        foreach ($metadata->getColumnTypes() as $column => $type) {
            $bindings[$column] = $connection->getTypeRegistry()->get($type)->getBindingType();
        }
        $joins = [];
        foreach ($joinColumns as $field => [$column, $type]) {
            $joins[$field] = new FieldMapping($field, $column, $type, true);
            $bindings[$column] = $connection->getTypeRegistry()->get($type)->getBindingType();
        }
        $this->joins = $joins;
        $this->bindings = $bindings;
        $lazy = [];
        foreach ($metadata->getLazyFields() as $field) {
            $lazy[$field->columnName] = true;
        }
        $this->lazy = $lazy;
        $wrapped = [];
        foreach ($metadata->getColumnTypes() as $column => $type) {
            $converter = $connection->getTypeRegistry()->get($type);
            if (':p' !== $converter->getParameterSql(':p', $connection->getPlatform())) {
                $wrapped[$column] = $converter;
            }
        }
        $this->wrapped = $wrapped;
        // colonne du tenant que ne porte aucun champ : écrite à l'INSERT, lue pour l'UoW (RG-08.6)
        $tenancy = $metadata->tenancy;
        $this->tenantBinding = null === $tenancy || null !== $tenancy->field ? [] : [$tenancy->column => $connection->getTypeRegistry()->get($tenancy->type)->getBindingType()];
        $embeddingBindings = [];
        foreach ($metadata->getEmbeddings() as $embedding) {
            $embeddingBindings[$embedding->staleColumn] = $connection->getTypeRegistry()->get('boolean')->getBindingType();
            $embeddingBindings[$embedding->modelColumn] = $connection->getTypeRegistry()->get('string')->getBindingType();
        }
        $this->embeddingBindings = $embeddingBindings;
        $this->identity = GenerationStrategy::Identity === GenerationStrategyResolver::resolve($metadata->id->strategy, $connection->getPlatform());
    }

    public function insert(array $row): ?string
    {
        if ($this->identity && null === ($row[$this->idColumn] ?? null)) {
            // la base attribue l'identifiant : un NULL explicite serait refusé par PostgreSQL
            unset($row[$this->idColumn]);
        }

        if ([] === $row) {
            // seul l'identifiant, attribué par la base
            $this->connection->executeStatement($this->connection->getPlatform()->getInsertDefaultValuesSql($this->metadata->tableName));
        } else {
            $qb = $this->connection->createQueryBuilder()->insert($this->metadata->tableName);
            foreach ($row as $column => $value) {
                $qb = $this->setValue($qb, $column, $value);
            }
            $qb->executeStatement();
        }

        return $this->identity && !\array_key_exists($this->idColumn, $row) ? new IdentityGenerator()->generate($this->connection) : null;
    }

    public function update(mixed $id, array $changes, ?array $version = null): int
    {
        if ([] === $changes) {
            return 0;
        }

        $qb = $this->connection->createQueryBuilder()->update($this->metadata->tableName);
        foreach ($changes as $column => $value) {
            $qb = $this->setValue($qb, $column, $value);
        }

        return $this->filtered($this->whereKey($qb, $id, $version), FilterOperation::Write)->executeStatement();
    }

    public function delete(mixed $id, ?array $version = null): int
    {
        return $this->filtered($this->whereKey($this->connection->createQueryBuilder()->delete($this->metadata->tableName), $id, $version), FilterOperation::Write)->executeStatement();
    }

    public function loadById(mixed $id, FilterOperation $operation = FilterOperation::Read): ?array
    {
        $row = $this->filtered($this->whereKey($this->select(), $id, null), $operation)->executeQuery()->fetchAssociative();

        return false === $row ? null : $row;
    }

    /**
     * Lignes qui satisfont les critères (champ → valeur PHP ; null : IS NULL ; liste : IN).
     *
     * @param array<string, mixed> $criteria
     * @param array<string, Order> $orderBy  champ → sens
     *
     * @return iterable<int, array<string, mixed>> lues une à une : les lignes brutes ne s'accumulent
     *                                             pas pendant l'hydratation
     *
     * @throws MappingException champ inconnu
     */
    public function loadBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0, FilterOperation $operation = FilterOperation::Read): iterable
    {
        $qb = $this->filtered($this->where($this->select(), $criteria), $operation);
        foreach ($orderBy as $field => $order) {
            $qb = $qb->addOrderBy($qb->column($this->field($field)->columnName), $order);
        }

        return $qb->limit($limit)->offset($offset)->executeQuery()->iterateAssociative();
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @throws MappingException champ inconnu
     */
    public function count(array $criteria = []): int
    {
        $qb = $this->connection->createQueryBuilder()->select('COUNT(*)')->from($this->metadata->tableName);
        $count = $this->filtered($this->where($qb, $criteria))->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function loadAfter(array $criteria, mixed $afterId, int $limit): array
    {
        $qb = $this->filtered($this->where($this->select(), $criteria));
        $id = $qb->column($this->idColumn);
        if (null !== $afterId) {
            $qb = $qb->andWhere($qb->expr()->gt($id, $afterId, $this->bindings[$this->idColumn]));
        }

        return $qb->orderBy($id)->limit($limit)->executeQuery()->fetchAllAssociative();
    }

    public function loadColumn(string $column, array $ids): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb = $qb->select($qb->column($this->idColumn), $qb->column($column))->from($this->metadata->tableName);
        $qb = $this->filtered($qb->where($qb->expr()->in($qb->column($this->idColumn), $ids, $this->bindings[$this->idColumn])), FilterOperation::Load);

        return array_map(
            fn (array $row): array => [$row[$this->idColumn], $row[$column]],
            $qb->executeQuery()->fetchAllAssociative(),
        );
    }

    public function loadColumns(array $columns, array $ids, bool $lock = false): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb = $qb->select($qb->column($this->idColumn), ...array_map(static fn (string $column): string => $qb->column($column), $columns))->from($this->metadata->tableName);
        $qb = $this->filtered($qb->where($qb->expr()->in($qb->column($this->idColumn), $ids, $this->bindings[$this->idColumn])));

        return $qb->lock($lock ? LockMode::PessimisticWrite : null)->executeQuery()->fetchAllAssociative();
    }

    public function loadIdsAfter(?string $flag, mixed $afterId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $id = $qb->column($this->idColumn);
        $qb = $this->filtered($qb->select($id)->from($this->metadata->tableName));
        if (null !== $flag) {
            $qb = $qb->andWhere($qb->expr()->eq($qb->column($flag), $this->connection->convertToDatabase(true, 'boolean'), $this->binding($flag, true)));
        }
        if (null !== $afterId) {
            $qb = $qb->andWhere($qb->expr()->gt($id, $afterId, $this->bindings[$this->idColumn]));
        }

        return array_map(fn (array $row): mixed => $row[$this->idColumn], $qb->orderBy($id)->limit($limit)->executeQuery()->fetchAllAssociative());
    }

    public function updateAll(array $changes): int
    {
        $qb = $this->connection->createQueryBuilder()->update($this->metadata->tableName);
        foreach ($changes as $column => $value) {
            $qb = $this->setValue($qb, $column, $value);
        }

        return $this->filtered($qb, FilterOperation::Write)->executeStatement();
    }

    public function countGroupedBy(array $columns): array
    {
        $qb = $this->connection->createQueryBuilder();
        $quoted = array_map(static fn (string $column): string => $qb->column($column), $columns);
        $qb = $qb->select(...$quoted, ...['COUNT(*) AS ' . $this->connection->getPlatform()->quoteIdentifier(self::COUNT_ALIAS)])->from($this->metadata->tableName);

        $qb = $this->filtered($qb)->groupBy(...$quoted);
        foreach ($quoted as $column) {
            $qb = $qb->addOrderBy($column);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function loadNearest(string $column, array $vector, VectorDistance $distance, int $limit, array $criteria = [], ?string $staleColumn = null): array
    {
        return $this->nearestOrExact($column, $vector, $distance, $limit, $criteria, idsOnly: false, staleColumn: $staleColumn);
    }

    public function loadNearestIds(string $column, array $vector, VectorDistance $distance, int $limit, array $criteria = [], ?string $staleColumn = null): array
    {
        return array_map(fn (array $row): mixed => $row[$this->idColumn], $this->nearestOrExact($column, $vector, $distance, $limit, $criteria, idsOnly: true, staleColumn: $staleColumn));
    }

    public function loadFullTextIds(array $columns, ?string $language, string $query, int $limit, array $criteria = []): array
    {
        $platform = $this->connection->getPlatform();
        $qb = $this->connection->createQueryBuilder();
        $quoted = array_map(static fn (string $column): string => $qb->column($column), $columns);
        // deux noms : un paramètre nommé ne se répète pas sous les requêtes préparées natives de MySQL
        $qb = $qb->select($qb->column($this->idColumn), $platform->getFullTextScoreSql($quoted, $language, ':fulltext_score') . ' AS ' . $platform->quoteIdentifier(self::SCORE_ALIAS))
            ->from($this->metadata->tableName)
            ->where($platform->getFullTextConditionSql($quoted, $language, ':fulltext_match'))
            ->setParameter('fulltext_score', $query)
            ->setParameter('fulltext_match', $query);
        $qb = $this->filtered($this->where($qb, $criteria));
        $qb = $qb->orderBy($platform->quoteIdentifier(self::SCORE_ALIAS), Order::Desc)->addOrderBy($qb->column($this->idColumn));

        return array_map(fn (array $row): mixed => $row[$this->idColumn], $qb->limit($limit)->executeQuery()->fetchAllAssociative());
    }

    /**
     * @param list<float>          $vector
     * @param array<string, mixed> $criteria
     *
     * @return list<array<string, mixed>>
     */
    private function nearestOrExact(string $column, array $vector, VectorDistance $distance, int $limit, array $criteria, bool $idsOnly, ?string $staleColumn): array
    {
        $rows = $this->nearest($column, $vector, $distance, $limit, $criteria, exact: false, idsOnly: $idsOnly, staleColumn: $staleColumn);
        // ADR 0017 : sous un filtre sélectif, l'index de MariaDB peut rendre moins que la limite
        if (\count($rows) < $limit && $this->connection->getPlatform()->vectorIndexMayMissRows()) {
            $rows = $this->nearest($column, $vector, $distance, $limit, $criteria, exact: true, idsOnly: $idsOnly, staleColumn: $staleColumn);
        }

        return $rows;
    }

    /**
     * @param list<float>          $vector
     * @param array<string, mixed> $criteria
     *
     * @return list<array<string, mixed>>
     */
    private function nearest(string $column, array $vector, VectorDistance $distance, int $limit, array $criteria, bool $exact, bool $idsOnly, ?string $staleColumn): array
    {
        $platform = $this->connection->getPlatform();
        $qb = $this->connection->createQueryBuilder();
        $parameter = $platform->getVectorParameterSql(':' . self::VECTOR_PARAMETER);
        $sql = $exact
            ? $platform->getExactVectorDistanceSql($distance, $qb->column($column), $parameter)
            : $platform->getVectorDistanceSql($distance, $qb->column($column), $parameter);
        // la distance nue, triée par son alias : la seule forme qui garde l'index sous MariaDB
        $selection = $idsOnly ? [$qb->column($this->idColumn)] : $this->selection($qb, null, lazy: false);
        $qb = $qb->select(...$selection, ...[$sql . ' AS ' . $platform->quoteIdentifier(self::DISTANCE_ALIAS)])
            ->from($this->metadata->tableName)
            ->setParameter(self::VECTOR_PARAMETER, $vector, 'vector');
        $qb = $this->filtered($this->where($qb, $criteria));
        if (null !== $staleColumn) {
            // RG-09.8 : un vecteur périmé ne correspond plus à son texte
            $qb = $qb->andWhere($qb->expr()->eq($qb->column($staleColumn), $this->connection->convertToDatabase(false, 'boolean'), $this->binding($staleColumn, false)));
        }

        return $qb->orderBy($platform->quoteIdentifier(self::DISTANCE_ALIAS))->limit($limit)->executeQuery()->fetchAllAssociative();
    }

    public function lock(mixed $id, LockMode $mode): bool
    {
        $qb = $this->connection->createQueryBuilder();
        $qb = $qb->select($qb->column($this->idColumn))->from($this->metadata->tableName);

        return false !== $this->filtered($this->whereKey($qb, $id, null))->lock($mode)->executeQuery()->fetchOne();
    }

    public function loadByJoinTable(string $table, string $ownerColumn, string $targetColumn, array $ownerIds, string $ownerType, array $orderBy = []): array
    {
        $qb = $this->connection->createQueryBuilder();
        $owner = $this->connection->getPlatform()->quoteIdentifier(self::OWNER_ALIAS);
        $t = self::TARGET_ALIAS;
        $qb = $qb->select($qb->column($ownerColumn, 'p') . ' AS ' . $owner, ...$this->selection($qb, $t))
            ->from($this->metadata->tableName, $t)
            ->join($table, 'p', $qb->column($targetColumn, 'p') . ' = ' . $qb->column($this->idColumn, $t));
        $qb = $this->filtered($qb->where($qb->expr()->in($qb->column($ownerColumn, 'p'), $ownerIds, $this->bindingOf($ownerType))), alias: $t);
        foreach ($orderBy as $field => $order) {
            $qb = $qb->addOrderBy($qb->column($this->field($field)->columnName, $t), $order);
        }

        $rows = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $ownerId = $row[self::OWNER_ALIAS];
            unset($row[self::OWNER_ALIAS]);
            $rows[] = [$ownerId, $row];
        }

        return $rows;
    }

    public function countJoinTable(string $table, string $ownerColumn, string $targetColumn, mixed $ownerId, string $ownerType): int
    {
        $qb = $this->connection->createQueryBuilder();
        $condition = $this->filters->condition($this->metadata, new FilterTarget($qb, self::TARGET_ALIAS));
        $qb = $qb->select('COUNT(*)')->from($table, 'p')->where($qb->expr()->eq($qb->column($ownerColumn, 'p'), $ownerId, $this->bindingOf($ownerType)));
        if (null !== $condition) {
            // une cible filtrée : seules ses lignes visibles comptent
            $qb = $qb->join($this->metadata->tableName, self::TARGET_ALIAS, $qb->column($this->idColumn, self::TARGET_ALIAS) . ' = ' . $qb->column($targetColumn, 'p'))->andWhere($condition);
        }
        $count = $qb->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function insertJoinRow(string $table, array $row, array $types): void
    {
        $qb = $this->connection->createQueryBuilder()->insert($table);
        foreach ($row as $column => $value) {
            $qb = $qb->set($column, $value, $this->bindingOf($types[$column]));
        }
        $qb->executeStatement();
    }

    public function deleteJoinRows(string $table, array $criteria, array $types): int
    {
        $qb = $this->connection->createQueryBuilder()->delete($table);
        foreach ($criteria as $column => $value) {
            $qb = $qb->andWhere($qb->expr()->eq($qb->column($column), $value, $this->bindingOf($types[$column])));
        }

        return $qb->executeStatement();
    }

    /**
     * Conditions des filtres sur la table de l'entité, sans alias sauf jointure.
     */
    private function filtered(QueryBuilder $qb, FilterOperation $operation = FilterOperation::Read, ?string $alias = null): QueryBuilder
    {
        $condition = $this->filters->condition($this->metadata, new FilterTarget($qb, $alias, $operation));

        return null === $condition ? $qb : $qb->andWhere($condition);
    }

    private function bindingOf(string $type): ParameterType
    {
        return $this->connection->getTypeRegistry()->get($type)->getBindingType();
    }

    private function select(): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select(...$this->selection($qb, null))->from($this->metadata->tableName);
    }

    /**
     * Les colonnes lues ; d'une colonne paresseuse, seulement si elle est NULL (1) ou non (0),
     * sous son propre nom : l'UnitOfWork y place null ou un ghost (RG-09.6).
     *
     * @return list<string>
     */
    private function selection(QueryBuilder $qb, ?string $alias, bool $lazy = true): array
    {
        $platform = $this->connection->getPlatform();

        return array_map(
            fn (string $column): string => $lazy && isset($this->lazy[$column])
                ? \sprintf('CASE WHEN %s IS NULL THEN 1 ELSE 0 END AS %s', $qb->column($column, $alias), $platform->quoteIdentifier($column))
                : $qb->column($column, $alias),
            $this->columns(),
        );
    }

    /**
     * Une valeur d'INSERT ou d'UPDATE ; un vecteur passe par le SQL de paramètre de sa
     * plateforme (VEC_FromText(), CAST), lié sous un nom propre à sa colonne.
     */
    private function setValue(QueryBuilder $qb, string $column, mixed $value): QueryBuilder
    {
        if (null === $value || !isset($this->wrapped[$column])) {
            return $qb->set($column, $value, $this->binding($column, $value));
        }
        // un nom par colonne : une même requête n'écrit une colonne qu'une fois
        $name = 'vector_' . substr(hash('xxh3', $column), 0, 12);

        return $qb->setExpression($column, $this->wrapped[$column]->getParameterSql(':' . $name, $this->connection->getPlatform()))
            ->setParameter($name, $value, $this->binding($column, $value));
    }

    /**
     * Colonnes lues : celles des champs et des jointures, puis celle du tenant que ne porte aucun champ.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        return [...array_keys($this->bindings), ...array_keys($this->tenantBinding)];
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

    // un champ, ou une relation ToOne possédée par sa colonne de jointure (critère : identifiant visé)
    private function field(string $name): FieldMapping
    {
        if (isset($this->joins[$name])) {
            return $this->joins[$name];
        }
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
        return null === $value ? ParameterType::Null : ($this->bindings[$column] ?? $this->tenantBinding[$column] ?? $this->embeddingBindings[$column] ?? ParameterType::String);
    }
}
