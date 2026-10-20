<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Connection;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\NonUniqueResultException;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Mapping\Order as MappingOrder;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\Metamodel;

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
        if ((null !== $limit || 0 !== $offset) && null !== ($collection = $this->fetchedCollection())) {
            throw QueryException::limitWithCollectionFetchJoin($collection);
        }
        $qb = $this->em->getConnection()->createQueryBuilder();
        $context = new QueryContext($qb, $this->metadata);
        $metadata = $context->add($this->root);
        $nodes = [$this->root->alias => new Node($metadata, null, null)];
        $orderBy = [];

        $qb = $qb->from($metadata->tableName, $this->root->alias);
        foreach ($this->joins as [$target, $left, $fetch]) {
            [$association, $joins] = $context->join($target);
            foreach ($joins as [$table, $alias, $on]) {
                $qb = $left ? $qb->leftJoin($table, $alias, $on) : $qb->join($table, $alias, $on);
            }
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
            $qb = $qb->addOrderBy($context->column($ordering->field), $ordering->order);
        }
        foreach ($orderBy as [$column, $order]) {
            $qb = $qb->addOrderBy($column, $order);
        }

        return [$qb->limit($limit)->offset($offset), $nodes];
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
