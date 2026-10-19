<?php

declare(strict_types=1);

namespace Prism\Orm\Repository;

use Prism\Dbal\Query\Order;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Lecture des entités d'une classe. Seule classe du cœur ouverte à l'héritage (ADR 0006) : un
 * repository métier l'étend, ses méthodes publiques restent final.
 *
 * Critères : champ → valeur PHP ; null : IS NULL ; liste : IN. Les entités rendues sont gérées et
 * passent par l'identity map (RG-03.3).
 *
 * @template T of object
 *
 * @api
 *
 * @extensible
 */
class EntityRepository
{
    /**
     * @param ClassMetadata<T> $metadata
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassMetadata $metadata,
    ) {
    }

    /**
     * @return T|null
     */
    public function find(mixed $id): ?object
    {
        return $this->entityManager->find($this->metadata->className, $id);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, Order> $orderBy  champ → sens
     *
     * @return T|null
     *
     * @throws MappingException champ inconnu
     */
    public function findOneBy(array $criteria, array $orderBy = []): ?object
    {
        return $this->findBy($criteria, $orderBy, 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, Order> $orderBy  champ → sens
     *
     * @return list<T>
     *
     * @throws MappingException champ inconnu
     */
    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        return $this->entityManager->getUnitOfWork()->findBy($this->metadata->className, $criteria, $orderBy, $limit, $offset);
    }

    /**
     * @return list<T>
     */
    public function findAll(): array
    {
        return $this->findBy([]);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @throws MappingException champ inconnu
     */
    public function count(array $criteria = []): int
    {
        return $this->entityManager->getUnitOfWork()->count($this->metadata->className, $criteria);
    }

    /**
     * Parcours par lots en pagination sur l'identifiant ; chaque lot est détaché à sa fin
     * (RG-03.10) : flush() avant la fin du lot pour garder une modification.
     *
     * @param array<string, mixed> $criteria
     *
     * @return \Generator<int, T, mixed, void>
     *
     * @throws InvalidArgumentException $batchSize inférieur à 1
     * @throws MappingException         champ inconnu
     */
    public function iterate(array $criteria = [], int $batchSize = 100): \Generator
    {
        return $this->entityManager->getUnitOfWork()->iterate($this->metadata->className, $criteria, $batchSize);
    }

    /**
     * @return class-string<T>
     */
    public function getClassName(): string
    {
        return $this->metadata->className;
    }
}
