<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Connection;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\NonUniqueResultException;
use Prism\Orm\Exception\QueryException;
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
        return $this->hydrate($this->build($this->limit));
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
        // LIMIT 2 suffit à savoir s'il y en a plus d'une
        $qb = $this->build(null === $this->limit ? 2 : min(2, $this->limit));
        $result = $this->hydrate($qb);
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
        return $this->build($this->limit)->toSql();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws QueryException
     */
    public function getParameters(): array
    {
        return $this->build($this->limit)->getParameters();
    }

    /**
     * @return array<string, ParameterType|string>
     *
     * @throws QueryException
     */
    public function getParameterTypes(): array
    {
        return $this->build($this->limit)->getParameterTypes();
    }

    public function getConnection(): Connection
    {
        return $this->em->getConnection();
    }

    /**
     * @throws QueryException
     */
    private function build(?int $limit): QueryBuilder
    {
        $qb = $this->em->getConnection()->createQueryBuilder();
        $context = new QueryContext($qb, $this->metadata);
        $metadata = $context->add($this->root);
        $alias = $this->root->alias;

        $qb = $qb->select(...array_map(static fn (string $column): string => $qb->column($column, $alias), $metadata->getColumnNames()))
            ->from($metadata->tableName, $alias);
        if (null !== $this->where) {
            $qb = $qb->where($this->where->compile($context));
        }
        foreach ($this->orderBy as $ordering) {
            $qb = $qb->addOrderBy($context->column($ordering->field), $ordering->order);
        }

        return $qb->limit($limit)->offset($this->offset);
    }

    /**
     * @return list<T>
     */
    private function hydrate(QueryBuilder $qb): array
    {
        return $this->em->getUnitOfWork()->hydrate($this->metadata->getMetadataFor($this->root->entity), $qb->executeQuery()->fetchAllAssociative());
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
