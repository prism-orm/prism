<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Connection;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Query\Expression;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\NonUniqueResultException;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Mapping\Order as MappingOrder;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\Field;
use Prism\Orm\Query\Metamodel\Metamodel;
use Prism\Orm\Query\Metamodel\MixedField;

/**
 * Requête typée (pilier 2) sur une entité racine, sans chaîne de nom de champ :
 *
 *     $o = Order_::as('o');
 *     $em->query($o)->where($o->status->eq(Status::Paid))->orderBy($o->total->desc())->getResult();
 *
 * Immuable : chaque méthode rend une copie, une requête de base se décline sans effet de bord.
 * Compilée vers le QueryBuilder du DBAL (valeurs liées, RG-05.4) ; ses entités passent par
 * l'identity map et forment une cohorte, comme celles de findBy().
 *
 * Une jointure part d'une relation d'un alias déjà présent :
 *
 *     $c = $o->customer->as('c');
 *     $em->query($o)->fetchJoin($c)->where($c->name->like('A%'))->getResult();
 *
 * fetchJoin() hydrate en plus la relation jointe dans la même requête (RG-05.5) ; une condition
 * sur une collection chargée ainsi la charge filtrée, comme le SQL le dit.
 *
 * selectInto() projette la requête dans un DTO, sans passer par l'UoW ; c'est la seule forme
 * d'une requête groupée (ADR 0010) :
 *
 *     $em->query($o)->groupBy($o->status)->selectInto(StatusTotal::class, status: $o->status, total: $o->total->sum());
 *
 * @template T of object
 *
 * @api
 */
final class EntityQueryBuilder
{
    private ?Condition $where = null;

    /** @var list<Ordering> */
    private array $orderBy = [];

    private ?int $limit = null;

    private int $offset = 0;

    /** @var list<array{Metamodel<object>, bool, bool}> metamodel joint, jointure externe, chargé */
    private array $joins = [];

    private bool $batch = true;

    /** @var list<Operand> */
    private array $groupBy = [];

    private ?Condition $having = null;

    /**
     * @param Metamodel<T> $root
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MetadataFactory $metadata,
        private readonly Metamodel $root,
    ) {
    }

    /**
     * Jointure interne sur une relation : $o->customer->as('c'), $o->lines->as('l').
     *
     * @param Metamodel<object> $target
     *
     * @return static<T>
     *
     * @throws QueryException metamodel non joignable, alias déjà pris, propriétaire absent
     */
    public function join(Metamodel $target): static
    {
        return $this->joined($target, left: false, fetch: false);
    }

    /**
     * @param Metamodel<object> $target
     *
     * @return static<T>
     *
     * @throws QueryException
     */
    public function leftJoin(Metamodel $target): static
    {
        return $this->joined($target, left: true, fetch: false);
    }

    /**
     * Jointure interne qui hydrate la relation : aucune requête ensuite pour elle (RG-05.5).
     *
     * @param Metamodel<object> $target
     *
     * @return static<T>
     *
     * @throws QueryException
     */
    public function fetchJoin(Metamodel $target): static
    {
        return $this->joined($target, left: false, fetch: true);
    }

    /**
     * Jointure externe qui hydrate la relation : null ou collection vide sans ligne jointe.
     *
     * @param Metamodel<object> $target
     *
     * @return static<T>
     *
     * @throws QueryException
     */
    public function leftFetchJoin(Metamodel $target): static
    {
        return $this->joined($target, left: true, fetch: true);
    }

    /**
     * Sans batch fetching : chaque relation paresseuse du résultat se charge seule (RG-04.13).
     *
     * @return static<T>
     */
    public function withoutBatchFetch(): static
    {
        return $this->with(static function (self $qb): void {
            $qb->batch = false;
        });
    }

    /**
     * @return static<T>
     */
    public function where(Condition $condition): static
    {
        return $this->with(static function (self $qb) use ($condition): void {
            $qb->where = $condition;
        });
    }

    /**
     * @return static<T>
     */
    public function andWhere(Condition $condition): static
    {
        return $this->where(null === $this->where ? $condition : Condition::all($this->where, $condition));
    }

    /**
     * @return static<T>
     */
    public function orWhere(Condition $condition): static
    {
        return $this->where(null === $this->where ? $condition : Condition::any($this->where, $condition));
    }

    /**
     * @return static<T>
     */
    public function orderBy(Ordering ...$orderings): static
    {
        return $this->with(static function (self $qb) use ($orderings): void {
            $qb->orderBy = array_values($orderings);
        });
    }

    /**
     * @return static<T>
     */
    public function addOrderBy(Ordering ...$orderings): static
    {
        return $this->orderBy(...$this->orderBy, ...array_values($orderings));
    }

    /**
     * Remplace le groupement : seule une projection selectInto() lit une requête groupée.
     *
     * @return static<T>
     */
    public function groupBy(Operand ...$operands): static
    {
        return $this->with(static function (self $qb) use ($operands): void {
            $qb->groupBy = array_values($operands);
        });
    }

    /**
     * Remplace la condition sur les groupes : $o->total->sum()->gt('100.00').
     *
     * @return static<T>
     */
    public function having(Condition $condition): static
    {
        return $this->with(static function (self $qb) use ($condition): void {
            $qb->having = $condition;
        });
    }

    /**
     * @return static<T>
     */
    public function andHaving(Condition $condition): static
    {
        return $this->having(null === $this->having ? $condition : Condition::all($this->having, $condition));
    }

    /**
     * Projection dans un DTO (RG-05.6, RG-05.7) : chaque sélection est nommée comme le paramètre
     * du constructeur qui la reçoit.
     *
     *     ->selectInto(CustomerTotal::class, customer: $c->name, total: $o->total->sum())
     *
     * @template D of object
     *
     * @param class-string<D> $dto
     *
     * @return Projection<D>
     *
     * @throws QueryException sélection sans nom, nom ou paramètre sans correspondance
     */
    public function selectInto(string $dto, Operand ...$selections): Projection
    {
        return new Projection($this, $dto, $selections);
    }

    /**
     * La requête d'une projection : ses sélections nommées, et le nom de colonne de chacune.
     * Une jointure chargée y est une jointure simple.
     *
     * @param array<string, Operand> $selections
     *
     * @return array{QueryBuilder, QueryContext, array<string, string>}
     *
     * @throws QueryException sélection ou tri ni agrégé ni groupé
     *
     * @internal la Projection l'exécute
     */
    public function compileProjection(array $selections, bool $one): array
    {
        $qb = $this->em->getConnection()->createQueryBuilder();
        $context = new QueryContext($qb, $this->metadata);
        [$qb] = $this->from($context, $qb);

        // un champ à côté d'un agrégat est groupé : PostgreSQL et MySQL le refuseraient, SQLite non
        $grouped = array_map(static fn (Operand $operand): string => $operand->toSql($context), $this->groupBy);
        $aggregated = [] !== $grouped || null !== $this->having || [] !== array_filter($selections, static fn (Operand $operand): bool => $operand->isAggregate());
        $check = static function (string $what, Operand $operand, string $sql) use ($aggregated, $grouped): void {
            if ($aggregated && !$operand->isAggregate() && !\in_array($sql, $grouped, true)) {
                throw QueryException::notGrouped($what, $sql);
            }
        };

        $aliases = [];
        $select = [];
        foreach ($selections as $name => $operand) {
            $check(\sprintf('The selection "%s"', $name), $operand, $sql = $operand->toSql($context));
            // PostgreSQL tronque un identifiant au-delà de 63 octets
            $aliases[$name] = \strlen($name) <= 63 ? $name : 'prism_' . \count($aliases);
            $select[] = $sql . ' AS ' . $qb->column($aliases[$name]);
        }
        $qb = $qb->select(...$select);
        if (null !== $this->where) {
            $qb = $qb->where($this->where->compile($context));
        }
        if ([] !== $grouped) {
            $qb = $qb->groupBy(...$grouped);
        }
        if (null !== $this->having) {
            $qb = $qb->having($this->having->compile($context));
        }
        foreach ($this->orderBy as $ordering) {
            $check('The ordering', $ordering->operand, $sql = $ordering->operand->toSql($context));
            $qb = $qb->addOrderBy($sql, $ordering->order);
        }

        return [$qb->limit($one ? min(2, $this->limit ?? 2) : $this->limit)->offset($this->offset), $context, $aliases];
    }

    /**
     * @return static<T>
     *
     * @throws QueryException limite négative
     */
    public function limit(?int $limit): static
    {
        if (null !== $limit && $limit < 0) {
            throw QueryException::negative('LIMIT', $limit);
        }

        return $this->with(static function (self $qb) use ($limit): void {
            $qb->limit = $limit;
        });
    }

    /**
     * @return static<T>
     *
     * @throws QueryException offset négatif
     */
    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw QueryException::negative('OFFSET', $offset);
        }

        return $this->with(static function (self $qb) use ($offset): void {
            $qb->offset = $offset;
        });
    }

    /**
     * Les entités de la requête (RG-05.6).
     *
     * @return list<T>
     *
     * @throws QueryException
     */
    public function getResult(): array
    {
        return $this->hydrate(...$this->build($this->limit, $this->offset));
    }

    /**
     * L'entité de la requête, ou null.
     *
     * @return T|null
     *
     * @throws NonUniqueResultException plusieurs entités
     * @throws QueryException
     */
    public function getOneOrNull(): ?object
    {
        // LIMIT 2 suffit à savoir s'il y en a plus d'une, sauf si une collection multiplie les lignes
        [$qb, $nodes] = $this->build(null === $this->limit && null !== $this->fetchedCollection() ? null : min(2, $this->limit ?? 2), $this->offset);
        $result = $this->hydrate($qb, $nodes);
        if (\count($result) > 1) {
            throw NonUniqueResultException::forQuery($this->root->entity, $qb->toSql());
        }

        return $result[0] ?? null;
    }

    /**
     * Le SQL compilé, pour les cas non couverts (RG-05.10).
     *
     * @throws QueryException
     */
    public function toSql(): string
    {
        return $this->build($this->limit, $this->offset)[0]->toSql();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws QueryException
     */
    public function getParameters(): array
    {
        return $this->build($this->limit, $this->offset)[0]->getParameters();
    }

    /**
     * @return array<string, ParameterType|string>
     *
     * @throws QueryException
     */
    public function getParameterTypes(): array
    {
        return $this->build($this->limit, $this->offset)[0]->getParameterTypes();
    }

    /**
     * Une page de la requête (RG-05.9). Le tri doit être déterministe : l'identifiant de la
     * racine y est ajouté en dernier critère. Avec une jointure de collection, les identifiants
     * de la page sont lus d'abord, puis leurs entités, collections complètes.
     *
     * @return Paginator<T>
     *
     * @throws QueryException page invalide, requête sans tri, déjà limitée ou groupée, tri sur
     *                        une collection
     */
    public function paginate(int $page, int $perPage = 20): Paginator
    {
        if ($page < 1 || $perPage < 1) {
            throw QueryException::invalidPage($page, $perPage);
        }
        if (null !== $this->limit || 0 !== $this->offset) {
            throw QueryException::paginatedWithLimit();
        }
        if ([] === $this->orderBy) {
            throw QueryException::paginationWithoutOrder($this->root->entity);
        }
        $metadata = $this->metadata->getMetadataFor($this->root->entity);
        $id = new MixedField($this->root, $metadata->id->fieldName);
        $ordered = $this;
        if ([] === array_filter($this->orderBy, static fn (Ordering $o): bool => $o->operand instanceof Field && $o->operand->owner === $ordered->root && $o->operand->path === $id->path)) {
            $ordered = $this->addOrderBy($id->asc());
        }
        $offset = ($page - 1) * $perPage;
        $count = $ordered->countQuery(...);

        $collections = $this->collectionAliases();
        if ([] === $collections) {
            return new Paginator($ordered->limit($perPage)->offset($offset)->getResult(), $page, $perPage, $count);
        }
        foreach ($ordered->orderBy as $ordering) {
            if (!$ordering->operand instanceof Field || \in_array($ordering->operand->owner->alias, $collections, true)) {
                throw QueryException::paginatedOrderingOnCollection();
            }
        }

        // les identifiants de la page, une fois chacun, dans l'ordre ; puis leurs entités
        $qb = $this->em->getConnection()->createQueryBuilder();
        $context = new QueryContext($qb, $this->metadata);
        [$qb] = $this->from($context, $qb);
        $idColumn = $id->toSql($context);
        $orderings = array_map(static fn (Ordering $o): string => $o->operand->toSql($context), $ordered->orderBy);
        $qb = $qb->select($idColumn)->groupBy(...array_values(array_unique([$idColumn, ...$orderings])));
        if (null !== $this->where) {
            $qb = $qb->where($this->where->compile($context));
        }
        foreach ($ordered->orderBy as $i => $ordering) {
            $qb = $qb->addOrderBy($orderings[$i], $ordering->order);
        }
        $ids = $qb->limit($perPage)->offset($offset)->executeQuery()->fetchFirstColumn();
        if ([] === $ids) {
            return new Paginator([], $page, $perPage, $count);
        }
        $items = $ordered->andWhere(Condition::of(static fn (QueryContext $c): Expression => $c->expr()->in($id->toSql($c), $ids)))->getResult();

        return new Paginator($items, $page, $perPage, $count);
    }

    public function getConnection(): Connection
    {
        return $this->em->getConnection();
    }

    /**
     * @param Metamodel<object> $target
     *
     * @return static<T>
     *
     * @throws QueryException
     */
    private function joined(Metamodel $target, bool $left, bool $fetch): static
    {
        $relation = $target->via() ?? throw QueryException::notJoinable($target->alias, $target->entity);
        $known = [$this->root, ...array_column($this->joins, 0)];
        foreach ($known as $metamodel) {
            if ($metamodel->alias === $target->alias) {
                throw QueryException::duplicateAlias($target->alias);
            }
        }
        if (!\in_array($relation->owner, $known, true)) {
            throw QueryException::joinedFromUnknownAlias($target->alias, $relation->owner->alias);
        }
        if ($fetch && $relation->owner !== $this->root && !\in_array([$relation->owner, true], array_map(static fn (array $join): array => [$join[0], $join[2]], $this->joins), true)) {
            throw QueryException::fetchJoinOfUnfetchedAlias($target->alias, $relation->owner->alias);
        }

        return $this->with(static function (self $qb) use ($target, $left, $fetch): void {
            $qb->joins[] = [$target, $left, $fetch];
        });
    }

    /**
     * Les entités de toutes les pages : une fois chacune, même si une jointure multiplie les lignes.
     *
     * @throws QueryException
     */
    private function countQuery(): int
    {
        $qb = $this->em->getConnection()->createQueryBuilder();
        $context = new QueryContext($qb, $this->metadata);
        [$qb, $metadata] = $this->from($context, $qb);
        $qb = $qb->select([] === $this->joins ? $qb->expr()->count() : $qb->expr()->count($qb->column($metadata->getIdField()->columnName, $this->root->alias), distinct: true));
        if (null !== $this->where) {
            $qb = $qb->where($this->where->compile($context));
        }
        $count = $qb->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Les alias joints par une collection, et ceux joints depuis eux : chacun multiplie les lignes.
     *
     * @return list<string>
     */
    private function collectionAliases(): array
    {
        $aliases = [];
        foreach ($this->joins as [$target]) {
            $relation = $target->via();
            if (null === $relation) {
                continue;
            }
            $owner = $this->metadata->getMetadataFor($relation->owner->entity);
            if (\in_array($relation->owner->alias, $aliases, true) || ($owner->hasAssociation($relation->path) && $owner->getAssociation($relation->path)->isCollection())) {
                $aliases[] = $target->alias;
            }
        }

        return $aliases;
    }

    /**
     * L'alias d'une collection chargée par jointure, s'il y en a une.
     */
    private function fetchedCollection(): ?string
    {
        foreach ($this->joins as [$target, , $fetch]) {
            $relation = $target->via();
            if (!$fetch || null === $relation) {
                continue;
            }
            $owner = $this->metadata->getMetadataFor($relation->owner->entity);
            if ($owner->hasAssociation($relation->path) && $owner->getAssociation($relation->path)->isCollection()) {
                return $target->alias;
            }
        }

        return null;
    }

    /**
     * La requête, et les alias à hydrater : la racine, puis chaque relation chargée.
     *
     * @return array{QueryBuilder, array<string, Node>}
     *
     * @throws QueryException
     */
    private function build(?int $limit, int $offset): array
    {
        if ([] !== $this->groupBy || null !== $this->having || [] !== array_filter($this->orderBy, static fn (Ordering $o): bool => $o->operand->isAggregate())) {
            throw QueryException::groupedEntityQuery($this->root->entity);
        }
        if ((null !== $limit || 0 !== $offset) && null !== ($collection = $this->fetchedCollection())) {
            throw QueryException::limitWithCollectionFetchJoin($collection);
        }
        $qb = $this->em->getConnection()->createQueryBuilder();
        $context = new QueryContext($qb, $this->metadata);
        [$qb, $metadata, $joined] = $this->from($context, $qb);
        $nodes = [$this->root->alias => new Node($metadata, null, null)];
        $orderBy = [];

        foreach ($joined as [$target, $fetch, $association]) {
            if (!$fetch) {
                continue;
            }
            $targetMetadata = $context->metadataOf($target);
            $nodes[$target->alias] = new Node($targetMetadata, $association, $target->via()?->owner->alias);
            // une collection chargée garde son ordre (RG-04.12)
            foreach ($association->isCollection() ? $association->orderBy : [] as $field => $order) {
                $orderBy[] = [$qb->column($targetMetadata->getField($field)->columnName, $target->alias), MappingOrder::Desc === $order ? Order::Desc : Order::Asc];
            }
        }

        // avec une relation chargée, chaque colonne est nommée « alias__colonne » pour séparer les entités
        $select = [];
        foreach ($nodes as $alias => $node) {
            foreach ($node->metadata->getColumnNames() as $column) {
                if (1 === \count($nodes)) {
                    $select[] = $qb->column($column, $alias);
                    continue;
                }
                // PostgreSQL tronque un identifiant au-delà de 63 octets
                $name = \strlen($alias . '__' . $column) <= 63 ? $alias . '__' . $column : 'prism_' . \count($select);
                $node->columns[$name] = $column;
                $select[] = $qb->column($column, $alias) . ' AS ' . $qb->column($name);
            }
        }
        $qb = $qb->select(...$select);

        if (null !== $this->where) {
            $qb = $qb->where($this->where->compile($context));
        }
        foreach ($this->orderBy as $ordering) {
            $qb = $qb->addOrderBy($ordering->operand->toSql($context), $ordering->order);
        }
        foreach ($orderBy as [$column, $order]) {
            $qb = $qb->addOrderBy($column, $order);
        }

        return [$qb->limit($limit)->offset($offset), $nodes];
    }

    /**
     * FROM la racine et ses jointures.
     *
     * @return array{QueryBuilder, ClassMetadata<object>, list<array{Metamodel<object>, bool, AssociationMapping}>} requête, metadata de la racine, puis metamodel joint, chargé, relation
     *
     * @throws QueryException
     */
    private function from(QueryContext $context, QueryBuilder $qb): array
    {
        $metadata = $context->add($this->root);
        $qb = $qb->from($metadata->tableName, $this->root->alias);
        $joined = [];
        foreach ($this->joins as [$target, $left, $fetch]) {
            [$association, $joins] = $context->join($target);
            foreach ($joins as [$table, $alias, $on]) {
                $qb = $left ? $qb->leftJoin($table, $alias, $on) : $qb->join($table, $alias, $on);
            }
            $joined[] = [$target, $fetch, $association];
        }

        return [$qb, $metadata, $joined];
    }

    /**
     * Les entités racines, sans doublon, dans l'ordre de leur première ligne.
     *
     * @param array<string, Node> $nodes
     *
     * @return list<T>
     */
    private function hydrate(QueryBuilder $qb, array $nodes): array
    {
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $uow = $this->em->getUnitOfWork();
        $root = $nodes[$this->root->alias];
        if (1 === \count($nodes)) {
            $unique = [];
            foreach ($uow->hydrate($root->metadata, $rows, $this->batch) as $entity) {
                $unique[spl_object_id($entity)] ??= $entity;
            }

            /** @var list<T> */
            return array_values($unique);
        }

        // la ligne de chaque entité, une seule fois, et l'identifiant de chaque alias par ligne
        $ids = [];
        foreach ($rows as $i => $row) {
            foreach ($nodes as $alias => $node) {
                $ids[$i][$alias] = $node->read($row);
            }
        }
        $this->hydrateNode($this->root->alias, $nodes, $ids);

        /** @var list<T> */
        return array_values($root->entities);
    }

    /**
     * Hydrate un alias : ses cibles ToOne possédées d'abord, pour que sa FK les retrouve dans
     * l'identity map ; puis lui ; puis ses collections et ses OneToOne inverses, par parent.
     *
     * @param array<string, Node>                    $nodes
     * @param array<int, array<string, string|null>> $ids   identifiant de chaque alias, par ligne
     */
    private function hydrateNode(string $alias, array $nodes, array $ids): void
    {
        $uow = $this->em->getUnitOfWork();
        $node = $nodes[$alias];
        $children = array_filter($nodes, static fn (Node $child): bool => $alias === $child->owner);
        $after = [];
        foreach ($children as $childAlias => $child) {
            if (null !== $child->association && $child->association->isOwningSide() && $child->association->isToOne()) {
                $this->hydrateNode($childAlias, $nodes, $ids);
            } else {
                $after[$childAlias] = $child;
            }
        }

        $fetched = array_values(array_map(static fn (Node $child): string => (string) $child->association?->fieldName, $after));
        $entities = $uow->hydrate($node->metadata, array_values($node->rows), $this->batch, $fetched);
        $node->entities = array_combine(array_keys($node->rows), $entities);

        foreach ($after as $childAlias => $child) {
            $this->hydrateNode($childAlias, $nodes, $ids);
            /** @var array<string, array<string, true>> $groups éléments de chaque parent, dans l'ordre des lignes */
            $groups = array_fill_keys(array_map(strval(...), array_keys($node->entities)), []);
            foreach ($ids as $row) {
                if (null !== $row[$alias] && null !== $row[$childAlias]) {
                    $groups[$row[$alias]][$row[$childAlias]] = true;
                }
            }
            $association = $child->association ?? throw new \LogicException('A joined alias has a relation.');
            foreach ($groups as $parent => $elements) {
                $elements = array_map(static fn (string $key): object => $child->entities[$key], array_map(strval(...), array_keys($elements)));
                if ($association->isCollection()) {
                    $uow->initializeCollection($node->entities[$parent], $association->fieldName, $elements);
                } else {
                    $uow->initializeInverseToOne($node->entities[$parent], $association->fieldName, $elements[0] ?? null);
                }
            }
        }
    }

    /**
     * @param \Closure(self<T>): void $change
     *
     * @return static<T>
     */
    private function with(\Closure $change): static
    {
        $copy = clone $this;
        $change($copy);

        return $copy;
    }
}
