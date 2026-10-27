<?php

declare(strict_types=1);

namespace Prism\Orm\Repository;

use Prism\Dbal\Query\Order;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\VectorDimensionMismatchException;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Vector\ScoredResult;
use Prism\Orm\Vector\Vector;

/**
 * Lecture des entités d'une classe. Seule classe du cœur ouverte à l'héritage (ADR 0006) : un
 * repository métier l'étend, ses méthodes publiques restent final ; il atteint l'EntityManager et
 * la metadata par getEntityManager() et getClassMetadata().
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
     * Pour les méthodes d'un repository métier : requêtes typées ($em->query()), flush.
     */
    final protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /**
     * @return ClassMetadata<T>
     */
    final protected function getClassMetadata(): ClassMetadata
    {
        return $this->metadata;
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
     * Les entités dont le vecteur $field est le plus proche de $vector, de la plus proche à la
     * plus lointaine, chacune avec sa distance et son score (RG-09.4). Les critères, le tenant et
     * la suppression logique filtrent avant le classement.
     *
     *     foreach ($docs->nearest('embedding', $question, limit: 5, criteria: ['published' => true]) as $hit) {
     *         echo $hit->entity->title, ' ', $hit->score;
     *     }
     *
     * @param list<float>|Vector   $vector
     * @param array<string, mixed> $criteria
     *
     * @return list<ScoredResult<T>>
     *
     * @throws MappingException                 champ inconnu, ou qui n'est pas un vecteur
     * @throws VectorDimensionMismatchException vecteur d'une autre dimension que le champ (RG-09.1)
     */
    public function nearest(string $field, array|Vector $vector, int $limit = 10, array $criteria = []): array
    {
        return $this->entityManager->getUnitOfWork()->nearest($this->metadata->className, $field, $vector, $limit, $criteria);
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
