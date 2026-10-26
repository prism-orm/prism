<?php

declare(strict_types=1);

namespace Prism\Orm;

use Prism\Dbal\Connection;
use Prism\Dbal\LockMode;
use Prism\Orm\Exception\CrossTenantRelationException;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Exception\OptimisticLockException;
use Prism\Orm\Exception\ReentrantFlushException;
use Prism\Orm\Exception\TenantMismatchException;
use Prism\Orm\Exception\TransactionRequiredException;
use Prism\Orm\Query\EntityQueryBuilder;
use Prism\Orm\Query\Metamodel\Metamodel;
use Prism\Orm\Repository\EntityRepository;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Point d'entrée de l'ORM : suivi des entités, écriture en une transaction, lecture par
 * identifiant ou par repository.
 *
 * @api
 */
interface EntityManagerInterface
{
    /**
     * NEW → MANAGED, REMOVED → MANAGED (RG-03.2) ; l'INSERT a lieu au flush.
     *
     * @throws InvalidEntityStateException entité DETACHED
     */
    public function persist(object $entity): void;

    /**
     * MANAGED → REMOVED ; le DELETE a lieu au flush.
     *
     * @throws InvalidEntityStateException entité NEW ou DETACHED
     */
    public function remove(object $entity): void;

    /**
     * Écrit toutes les modifications en une transaction (RG-03.6).
     *
     * @throws ReentrantFlushException      appelé depuis un listener (RG-03.7)
     * @throws OptimisticLockException      version périmée (RG-03.9)
     * @throws MissingTenantException       entité #[Tenanted] à écrire sans tenant (RG-08.4)
     * @throws TenantMismatchException      tenant d'un autre, ou changé (RG-08.3)
     * @throws CrossTenantRelationException relation entre deux tenants (RG-08.6)
     */
    public function flush(): void;

    /**
     * La même instance pour un même identifiant jusqu'au clear() (RG-03.3) ; null si la ligne
     * n'existe pas.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function find(string $class, mixed $id): ?object;

    /**
     * Référence non chargée : seul l'identifiant se lit sans requête (RG-04.7).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function getReference(string $class, mixed $id): object;

    /**
     * Relit l'entité depuis la base ; ses modifications non flushées sont perdues.
     *
     * @throws InvalidEntityStateException entité qui n'est pas MANAGED, ou pas encore écrite
     * @throws EntityNotFoundException     la ligne n'existe plus
     */
    public function refresh(object $entity): void;

    /**
     * MANAGED → DETACHED : l'entité n'est plus suivie.
     *
     * @throws InvalidEntityStateException entité NEW ou REMOVED
     */
    public function detach(object $entity): void;

    /**
     * Détache toutes les entités et libère leurs références (mode worker).
     */
    public function clear(): void;

    public function contains(object $entity): bool;

    /**
     * Exécute $operations puis flush() dans une transaction (un savepoint si une transaction est
     * déjà ouverte). En cas d'échec, rollback et clear() : les entités suivies ne reflètent plus
     * la base, l'appelant les relit.
     *
     * @template T
     *
     * @param \Closure(self): T $operations
     *
     * @return T
     */
    public function wrapInTransaction(\Closure $operations): mixed;

    /**
     * @param mixed $version version attendue pour un verrou optimiste (null : vérifie seulement
     *                       que l'entité est versionnée)
     *
     * @throws InvalidEntityStateException  entité qui n'est pas MANAGED, ou pas encore écrite
     * @throws OptimisticLockException      entité sans #[Version], ou version différente
     * @throws TransactionRequiredException verrou pessimiste hors transaction
     * @throws EntityNotFoundException      la ligne n'existe plus
     */
    public function lock(object $entity, LockMode $mode, mixed $version = null): void;

    /**
     * Le repository de l'entité : sa repositoryClass, sinon EntityRepository (ADR 0006).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return EntityRepository<T>
     *
     * @throws MappingException classe non mappée, ou repositoryClass qui n'étend pas EntityRepository
     */
    public function getRepository(string $class): EntityRepository;

    /**
     * Requête typée sur l'entité de $root (pilier 2, ADR 0009).
     *
     * @template T of object
     *
     * @param Metamodel<T> $root
     *
     * @return EntityQueryBuilder<T>
     */
    public function query(Metamodel $root): EntityQueryBuilder;

    public function getConnection(): Connection;

    public function getUnitOfWork(): UnitOfWork;
}
