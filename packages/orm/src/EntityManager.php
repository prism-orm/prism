<?php

declare(strict_types=1);

namespace Prism\Orm;

use Prism\Dbal\Connection;
use Prism\Dbal\LockMode;
use Prism\Orm\Event\EntityListenerResolver;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Persister\DbalPersisterProvider;
use Prism\Orm\Repository\EntityRepository;
use Prism\Orm\UnitOfWork\UnitOfWork;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * EntityManager d'une Connection : délègue le suivi à l'UnitOfWork et garde un repository par
 * entité. Sans MetadataFactory, les attributs sont relus sans cache ; sans dispatcher PSR-14,
 * seuls les callbacks et les listeners d'entité sont appelés (décision E) ; sans résolveur, un
 * listener d'entité est construit sans argument (DefaultEntityListenerResolver).
 *
 * @api
 */
final class EntityManager implements EntityManagerInterface
{
    private readonly MetadataFactory $metadata;

    private readonly UnitOfWork $unitOfWork;

    /** @var array<class-string, EntityRepository<object>> */
    private array $repositories = [];

    public function __construct(
        private readonly Connection $connection,
        ?MetadataFactory $metadata = null,
        ?EventDispatcherInterface $events = null,
        ?EntityListenerResolver $listeners = null,
    ) {
        $this->metadata = $metadata ?? new MetadataFactory(new AttributeDriver(), types: $connection->getTypeRegistry());
        $this->unitOfWork = new UnitOfWork($this->metadata, new DbalPersisterProvider($connection), $connection->getConversionContext(), $connection->getTypeRegistry(), $events, $listeners);
    }

    public function persist(object $entity): void
    {
        $this->unitOfWork->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->unitOfWork->remove($entity);
    }

    public function flush(): void
    {
        $this->unitOfWork->commit();
    }

    public function find(string $class, mixed $id): ?object
    {
        return $this->unitOfWork->find($class, $id);
    }

    public function getReference(string $class, mixed $id): object
    {
        return $this->unitOfWork->getReference($class, $id);
    }

    public function refresh(object $entity): void
    {
        $this->unitOfWork->refresh($entity);
    }

    public function detach(object $entity): void
    {
        $this->unitOfWork->detach($entity);
    }

    public function clear(): void
    {
        $this->unitOfWork->clear();
    }

    public function contains(object $entity): bool
    {
        return $this->unitOfWork->contains($entity);
    }

    public function wrapInTransaction(\Closure $operations): mixed
    {
        try {
            return $this->connection->transactional(function () use ($operations): mixed {
                $result = $operations($this);
                $this->flush();

                return $result;
            });
        } catch (\Throwable $e) {
            // un flush réussi dans la transaction annulée a marqué des entités comme écrites
            $this->clear();

            throw $e;
        }
    }

    public function lock(object $entity, LockMode $mode, mixed $version = null): void
    {
        $this->unitOfWork->lock($entity, $mode, $version);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return EntityRepository<T>
     */
    public function getRepository(string $class): EntityRepository
    {
        if (!isset($this->repositories[$class])) {
            $metadata = $this->metadata->getMetadataFor($class);
            $repositoryClass = $metadata->repositoryClass ?? EntityRepository::class;
            if (!is_a($repositoryClass, EntityRepository::class, true)) {
                throw MappingException::invalidMetadata($class, \sprintf('its repository class %s must extend %s (ADR 0006).', $repositoryClass, EntityRepository::class));
            }
            $this->repositories[$class] = new $repositoryClass($this, $metadata);
        }

        /** @var EntityRepository<T> */
        return $this->repositories[$class];
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }
}
