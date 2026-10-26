<?php

declare(strict_types=1);

namespace Prism\Orm;

use Prism\Dbal\Connection;
use Prism\Dbal\LockMode;
use Prism\Orm\Event\EntityListenerResolver;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Filter\FilterCollection;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Persister\DbalPersisterProvider;
use Prism\Orm\Query\EntityQueryBuilder;
use Prism\Orm\Query\Metamodel\Metamodel;
use Prism\Orm\Repository\EntityRepository;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantContext;
use Prism\Orm\Tenancy\TenantFilter;
use Prism\Orm\UnitOfWork\UnitOfWork;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * EntityManager d'une Connection : délègue le suivi à l'UnitOfWork et garde un repository par
 * entité. Sans MetadataFactory, les attributs sont relus sans cache ; sans dispatcher PSR-14,
 * seuls les callbacks et les listeners d'entité sont appelés (décision E) ; sans résolveur, un
 * listener d'entité est construit sans argument (DefaultEntityListenerResolver). $batchSize est
 * la taille de lot du batch fetching par défaut (RG-04.11), qu'une relation peut changer.
 * Sans TenantContext, les entités #[Tenanted] n'ont jamais de tenant : toute opération sur
 * elles échoue (RG-08.4, ADR 0014).
 *
 * @api
 */
final class EntityManager implements EntityManagerInterface
{
    private readonly MetadataFactory $metadata;

    private readonly UnitOfWork $unitOfWork;

    private readonly TenantContext $tenantContext;

    private readonly FilterCollection $filters;

    /** @var array<class-string, EntityRepository<object>> */
    private array $repositories = [];

    public function __construct(
        private readonly Connection $connection,
        ?MetadataFactory $metadata = null,
        ?EventDispatcherInterface $events = null,
        ?EntityListenerResolver $listeners = null,
        int $batchSize = 100,
        ?TenantContext $tenantContext = null,
        ?FilterCollection $filters = null,
    ) {
        $this->tenantContext = $tenantContext ?? new StackTenantContext();
        // les filtres de l'application d'abord ; celui du tenant, verrouillé, toujours présent
        $this->filters = $filters ?? new FilterCollection();
        $this->filters->add(TenantFilter::NAME, new TenantFilter($this->tenantContext), locked: true);
        $this->metadata = $metadata ?? new MetadataFactory(new AttributeDriver(), types: $connection->getTypeRegistry());
        $this->unitOfWork = new UnitOfWork($this->metadata, new DbalPersisterProvider($connection, $this->metadata, $this->filters), $connection->getConversionContext(), $connection->getTypeRegistry(), $events, $listeners, $batchSize, $this->tenantContext);
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

    /**
     * @template T of object
     *
     * @param Metamodel<T> $root
     *
     * @return EntityQueryBuilder<T>
     */
    public function query(Metamodel $root): EntityQueryBuilder
    {
        return new EntityQueryBuilder($this, $this->metadata, $root, $this->filters);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Le mapping des entités, pour les outils (bin/prism, commandes db:*).
     */
    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadata;
    }

    public function getTenantContext(): TenantContext
    {
        return $this->tenantContext;
    }

    /**
     * Filtres appliqués à chaque table d'entité lue ou écrite ; le TenantFilter est verrouillé.
     */
    public function getFilters(): FilterCollection
    {
        return $this->filters;
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }
}
