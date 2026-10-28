<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

use Prism\Dbal\LockMode;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Audit\AuditLog;
use Prism\Orm\Audit\AuditWriter;
use Prism\Orm\Behavior\ActorProvider;
use Prism\Orm\Behavior\SystemClock;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Collection\PersistentCollection;
use Prism\Orm\Event\AuditRecordedEvent;
use Prism\Orm\Event\BatchLoadedEvent;
use Prism\Orm\Event\DefaultEntityListenerResolver;
use Prism\Orm\Event\EntityListenerResolver;
use Prism\Orm\Event\InverseSideChangedEvent;
use Prism\Orm\Event\LifecycleDispatcher;
use Prism\Orm\Event\OnClearEvent;
use Prism\Orm\Event\OnFlushEvent;
use Prism\Orm\Event\PostFlushEvent;
use Prism\Orm\Event\PostLoadEvent;
use Prism\Orm\Event\PostPersistEvent;
use Prism\Orm\Event\PostRemoveEvent;
use Prism\Orm\Event\PostUpdateEvent;
use Prism\Orm\Event\PreFlushEvent;
use Prism\Orm\Event\PrePersistEvent;
use Prism\Orm\Event\PreRemoveEvent;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Exception\CrossTenantRelationException;
use Prism\Orm\Exception\CyclicDependencyException;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Exception\OptimisticLockException;
use Prism\Orm\Exception\OrphanReattachedException;
use Prism\Orm\Exception\ReentrantFlushException;
use Prism\Orm\Exception\TenantMismatchException;
use Prism\Orm\Exception\TenantSwitchException;
use Prism\Orm\Exception\TransactionRequiredException;
use Prism\Orm\Exception\UnpersistedRelationException;
use Prism\Orm\Exception\VectorDimensionMismatchException;
use Prism\Orm\Filter\FilterOperation;
use Prism\Orm\Hydrator\ObjectHydrator;
use Prism\Orm\Lazy\LazyFactory;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\Fetch;
use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Mapping\TrackingPolicy;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\IndexMapping;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Persister\BasicEntityPersister;
use Prism\Orm\Persister\PersisterProvider;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantContext;
use Prism\Orm\Tenancy\TenantScope;
use Prism\Orm\Tenancy\TenantSwitchListener;
use Prism\Orm\Vector\DeferredEmbeddingScheduler;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\EmbeddingScheduler;
use Prism\Orm\Vector\HybridOptions;
use Prism\Orm\Vector\HybridResult;
use Prism\Orm\Vector\ScoredResult;
use Prism\Orm\Vector\StaleEmbeddings;
use Prism\Orm\Vector\Vector;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Suit l'état des entités, détecte leurs changements et les écrit en une transaction.
 *
 * Comportements fixés par les scénarios de référence (tests/Unit/UnitOfWork) :
 * - une opération qui laisse l'entité dans son état est sans effet ; les autres transitions
 *   absentes de RG-03.2 lèvent InvalidEntityStateException ;
 * - flush : PreFlush, OnFlush, puis dans la transaction PrePersist (avant l'INSERT) et
 *   PreUpdate, puis après le commit Post* et PostFlush ; PreRemove est publié par remove() ;
 * - tout flush() pendant un flush, PostFlush compris, lève ReentrantFlushException ;
 * - pour une entité : callbacks, listeners d'entité, puis dispatcher PSR-14 ; PostLoad suit
 *   chaque hydratation ;
 * - une entité supprimée par le flush, ou persistée puis retirée avant tout flush, redevient NEW ;
 * - une ligne relue ne réécrit jamais une entité déjà gérée : seul refresh() la réécrit ;
 * - une référence (ghost) non chargée n'a pas de snapshot : le flush ne la compare pas ;
 * - relations ToOne : la FK est l'identifiant de la cible et fait partie du snapshot ; relue,
 *   elle devient une référence de l'identity map ; au flush, une cible NEW est persistée en
 *   cascade ou refusée (RG-04.2), puis les INSERT suivent l'ordre des FK et les DELETE l'ordre
 *   inverse (RG-04.3, RG-04.4) ;
 * - collections (ADR 0007) : jamais remplacées ; une collection relue est une
 *   PersistentCollection chargée au premier accès ; au flush, la différence d'une ManyToMany
 *   propriétaire est écrite dans sa table pivot, après les entités et avant leurs DELETE ;
 * - batch fetching (pilier 1) : une requête hydrate une cohorte ; une relation chargée pour un
 *   membre l'est pour toute la cohorte, par lot (BatchLoader, RG-04.9 à RG-04.14) ;
 * - cascades : remove() suit cascade: remove et orphanRemoval (une collection non chargée est
 *   chargée), detach() suit cascade: detach sans rien charger ; au flush, un orphelin est
 *   supprimé (RG-04.5) et un côté inverse modifié seul publie InverseSideChangedEvent (RG-04.1).
 *
 * Les entités sont indexées par spl_object_id() ; clear() libère toutes les références (worker).
 *
 * @internal
 */
final class UnitOfWork implements TenantSwitchListener, FlushContext
{
    /** @var array<int, EntityState> */
    private array $states = [];

    /** @var array<int, object> références gardées tant que l'entité est suivie */
    private array $entities = [];

    /** @var array<int, array<string, mixed>> valeurs de la base au dernier chargement ou flush */
    private array $snapshots = [];

    /** @var array<class-string, array<string, object>> identity map (RG-03.3) */
    private array $identityMap = [];

    /** @var array<int, string> clé d'identity map de chaque entité qui y figure */
    private array $keys = [];

    /** @var array<int, object> INSERT dans l'ordre de persist() */
    private array $insertions = [];

    /** @var array<int, object> DELETE, dans l'ordre de remove() */
    private array $deletions = [];

    /** @var array<int, true> entités passées à persist() depuis le dernier flush (RG-03.11) */
    private array $persisted = [];

    /** @var \WeakMap<object, true> distingue DETACHED de NEW sans retenir les objets */
    private \WeakMap $detached;

    /** @var array<class-string, array<string, FieldMapping>> colonne → champ */
    private array $columns = [];

    private bool $flushing = false;

    /** @var array<class-string, list<array{AssociationMapping, ClassMetadata<object>}>> relations ToOne possédées et leur cible */
    private array $toOne = [];

    /** @var array<class-string, array<string, \ReflectionProperty>> */
    private array $properties = [];

    /** @var array<class-string, list<AssociationMapping>> */
    private array $collectionAssociations = [];

    /** @var array<int, array<string, object|null>> côté inverse d'un OneToOne au chargement (orphanRemoval) */
    private array $inverseSnapshots = [];

    /** @var array<int, array<string, true>> OneToOne inverses qu'une jointure va charger, par spl_object_id */
    private array $fetchedInverse = [];

    /** @var array<class-string, bool> la classe a-t-elle des relations ? (évite leur traitement à chaque hydratation) */
    private array $hasRelations = [];

    /** @var array<int, array<string, list<object>>> éléments de chaque collection au chargement ou au dernier flush */
    private array $collectionSnapshots = [];

    /** @var array<int, array<string, PersistentCollection<array-key, object>>> collection posée à l'hydratation (pour reconnaître un remplacement) */
    private array $collectionInstances = [];

    /** FK vers une entité pas encore insérée : différente de toute valeur du snapshot, jamais écrite */
    private readonly object $pending;

    private readonly ObjectHydrator $hydrator;

    private readonly LazyFactory $lazy;

    private readonly LifecycleDispatcher $lifecycle;

    private readonly BatchLoader $batch;

    /** @var array<class-string, array<string, array{array<string, mixed>, Cohort}>> lignes chargées par lot, en attente de leur ghost */
    private array $prefetched = [];

    /** @var array<string, array<int, array{object, Vector}>> vecteurs paresseux à charger, par « classe::champ » puis entité (RG-09.6) */
    private array $lazyPending = [];

    /** @var array<string, array<int, array{bool, mixed, mixed}>> vecteurs chargés par lot, en attente de leur ghost : [trouvé, valeur PHP, valeur lue en base] */
    private array $lazyLoaded = [];

    private readonly TenantContext $tenants;

    private readonly ClockInterface $clock;

    /** @var array<class-string, FieldMapping> colonne du tenant de chaque entité #[Tenanted], typée */
    private array $tenantFields = [];

    /** @var array<int, mixed> tenant écrit à l'INSERT, que ne porte aucun champ, en attente du snapshot */
    private array $insertedTenants = [];

    /** @var array<int, true> entités supprimées logiquement depuis le dernier flush (audit : soft-delete) */
    private array $softDeleted = [];

    /** @var array<int, true> entités restaurées depuis le dernier flush (audit : restore) */
    private array $restored = [];

    /** @var list<array{class-string, string, ?string, string, array<string, array{mixed, mixed}>, bool}> lignes d'audit du flush en cours */
    private array $auditRecords = [];

    private readonly EmbeddingScheduler $embeddingScheduler;

    /** @var list<array{object, string}> vecteurs #[EmbeddedVector] devenus périmés dans le flush en cours : entité et champ */
    private array $flushStale = [];

    /** @var list<StaleEmbeddings> vecteurs périmés par des flushs validés, en attente de la fin de la transaction englobante */
    private array $pendingStale = [];

    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly PersisterProvider $persisters,
        private readonly ConversionContext $conversion,
        private readonly TypeRegistry $types,
        ?EventDispatcherInterface $events = null,
        ?EntityListenerResolver $listeners = null,
        int $batchSize = 100,
        ?TenantContext $tenants = null,
        ?ClockInterface $clock = null,
        private readonly ?ActorProvider $actors = null,
        private readonly ?AuditWriter $audit = null,
        ?EmbeddingScheduler $embeddingScheduler = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        // sans planificateur, db:embeddings:compute recalcule les vecteurs périmés (ADR 0018)
        $this->embeddingScheduler = $embeddingScheduler ?? new DeferredEmbeddingScheduler();
        // sans contexte, une entité #[Tenanted] n'a jamais de tenant (RG-08.4)
        $this->tenants = $tenants ?? new StackTenantContext();
        $this->tenants->addSwitchListener($this);
        $this->batch = new BatchLoader($batchSize);
        $this->detached = new \WeakMap();
        $this->hydrator = new ObjectHydrator($conversion, $types);
        $this->lazy = new LazyFactory();
        $this->pending = new \stdClass();
        $this->lifecycle = new LifecycleDispatcher($listeners ?? new DefaultEntityListenerResolver(), $events);
    }

    /**
     * NEW → MANAGED, REMOVED → MANAGED (RG-03.2) ; sans effet sur une entité MANAGED.
     *
     * @throws InvalidEntityStateException entité DETACHED
     */
    public function persist(object $entity): void
    {
        $oid = spl_object_id($entity);

        switch ($this->getEntityState($entity)) {
            case EntityState::New:
                $this->track($entity, EntityState::Managed);
                $this->insertions[$oid] = $entity;
                break;
            case EntityState::Removed:
                $this->states[$oid] = EntityState::Managed;
                unset($this->deletions[$oid]);
                break;
            case EntityState::Detached:
                throw InvalidEntityStateException::transition($entity, EntityState::Detached, 'persist', 'a detached entity is no longer tracked; load it again with find().');
            case EntityState::Managed:
                break;
        }

        $this->persisted[$oid] = true;
    }

    /**
     * MANAGED → REMOVED ; une entité persistée mais jamais écrite redevient NEW.
     *
     * @throws InvalidEntityStateException entité NEW ou DETACHED
     */
    public function remove(object $entity, bool $hardDelete = false): void
    {
        $oid = spl_object_id($entity);

        switch ($this->getEntityState($entity)) {
            case EntityState::New:
                throw InvalidEntityStateException::transition($entity, EntityState::New, 'remove', 'it is not managed; persist it first.');
            case EntityState::Detached:
                throw InvalidEntityStateException::transition($entity, EntityState::Detached, 'remove', 'a detached entity is no longer tracked; load it again with find().');
            case EntityState::Removed:
                return;
            case EntityState::Managed:
                if (isset($this->insertions[$oid])) {
                    // jamais écrite : rien à supprimer
                    $this->forget($entity);
                    $this->cascadeRemove($entity);

                    return;
                }
                // la version et les listeners lisent l'entité : une référence est chargée d'abord
                $this->lazy->initialize($entity);
                $metadata = $this->metadataOf($entity);
                if (!$hardDelete && null !== $field = $metadata->behaviors->deletedAt) {
                    $this->softRemove($metadata, $entity, $field);

                    return;
                }
                $this->lifecycle->lifecycle($metadata, LifecycleEvent::PreRemove, $entity, fn (): PreRemoveEvent => new PreRemoveEvent($entity, $this));
                $this->states[$oid] = EntityState::Removed;
                $this->deletions[$oid] = $entity;
                $this->cascadeRemove($entity);
        }
    }

    /**
     * RG-08.14 : la suppression d'une entité #[SoftDeletable] est l'UPDATE de son champ de
     * suppression au flush (PreRemove ici, PostUpdate après le flush) ; une entité déjà supprimée
     * reste telle quelle. Les cascades s'appliquent comme à une suppression (ADR 0015).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function softRemove(ClassMetadata $metadata, object $entity, string $field): void
    {
        if (null !== $this->read($entity, $metadata->className, $field)) {
            return;
        }
        $oid = spl_object_id($entity);
        $this->lifecycle->lifecycle($metadata, LifecycleEvent::PreRemove, $entity, fn (): PreRemoveEvent => new PreRemoveEvent($entity, $this));
        $this->property($metadata->className, $field)->setValue($entity, $this->now());
        $this->softDeleted[$oid] = true;
        unset($this->restored[$oid]);
        $this->cascadeRemove($entity);
    }

    /**
     * RG-08.14 : une entité supprimée logiquement redevient visible, par l'UPDATE de son champ de
     * suppression au flush ; sans effet sur une entité qui ne l'est pas (ADR 0015).
     *
     * @throws InvalidArgumentException    entité sans #[SoftDeletable]
     * @throws InvalidEntityStateException entité qui n'est pas MANAGED
     */
    public function restore(object $entity): void
    {
        $metadata = $this->metadataOf($entity);
        $field = $metadata->behaviors->deletedAt ?? throw InvalidArgumentException::notSoftDeletable($metadata->className);
        $state = $this->getEntityState($entity);
        if (EntityState::Managed !== $state) {
            throw InvalidEntityStateException::transition($entity, $state, 'restore', 'only a managed entity can be restored; load it with withDeleted().');
        }
        $this->lazy->initialize($entity);
        if (null === $this->read($entity, $metadata->className, $field)) {
            return;
        }
        $oid = spl_object_id($entity);
        $this->property($metadata->className, $field)->setValue($entity, null);
        $this->restored[$oid] = true;
        unset($this->softDeleted[$oid]);
    }

    /**
     * RG-04.6 : cascade: remove, et orphanRemoval qui l'implique ; une collection non chargée
     * est chargée. Seules les entités gérées sont suivies (cycles, entités NEW).
     */
    private function cascadeRemove(object $entity): void
    {
        $metadata = $this->metadataOf($entity);
        foreach ($metadata->getAssociations() as $association) {
            if ($association->orphanRemoval || \in_array(Cascade::Remove, $association->cascade, true)) {
                foreach ($this->related($entity, $metadata, $association, load: true) as $target) {
                    if (EntityState::Managed === $this->getEntityState($target)) {
                        $this->remove($target);
                    }
                }
            }
        }
    }

    /**
     * cascade: detach, sans rien charger.
     */
    private function cascadeDetach(object $entity): void
    {
        $metadata = $this->metadataOf($entity);
        foreach ($metadata->getAssociations() as $association) {
            if (\in_array(Cascade::Detach, $association->cascade, true)) {
                foreach ($this->related($entity, $metadata, $association, load: false) as $target) {
                    if (EntityState::Managed === $this->getEntityState($target)) {
                        $this->detach($target);
                    }
                }
            }
        }
    }

    /**
     * Entités atteintes par une relation : la cible ToOne, les éléments d'une collection
     * (chargée au besoin si $load).
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return list<object>
     */
    private function related(object $entity, ClassMetadata $metadata, AssociationMapping $association, bool $load): array
    {
        $value = $this->read($entity, $metadata->className, $association->fieldName);
        if ($association->isToOne()) {
            return \is_object($value) ? [$value] : [];
        }
        if (!$value instanceof Collection || (!$load && $value instanceof PersistentCollection && !$value->isInitialized())) {
            return [];
        }

        return array_values($value->toArray());
    }

    /**
     * MANAGED → DETACHED.
     *
     * @throws InvalidEntityStateException entité NEW ou REMOVED
     */
    public function detach(object $entity): void
    {
        switch ($this->getEntityState($entity)) {
            case EntityState::New:
                throw InvalidEntityStateException::transition($entity, EntityState::New, 'detach', 'only managed entities can be detached.');
            case EntityState::Removed:
                throw InvalidEntityStateException::transition($entity, EntityState::Removed, 'detach', 'persist it again to cancel the removal, or flush first.');
            case EntityState::Managed:
                $this->forget($entity, detach: true);
                // une référence non chargée n'a rien à suivre (et ne doit pas être chargée)
                if (!$this->lazy->isUninitialized($entity)) {
                    $this->cascadeDetach($entity);
                }
                break;
            case EntityState::Detached:
                break;
        }
    }

    /**
     * Toutes les entités suivies deviennent DETACHED ; libère toutes les références (mode worker).
     */
    public function clear(): void
    {
        foreach ($this->entities as $entity) {
            $this->detached[$entity] = true;
        }

        $this->states = [];
        $this->entities = [];
        $this->snapshots = [];
        $this->insertedTenants = [];
        $this->softDeleted = [];
        $this->restored = [];
        // les lignes restent périmées en base : db:embeddings:compute les reprendra
        $this->pendingStale = [];
        $this->identityMap = [];
        $this->keys = [];
        $this->collectionSnapshots = [];
        $this->collectionInstances = [];
        $this->inverseSnapshots = [];
        $this->fetchedInverse = [];
        $this->prefetched = [];
        $this->lazyPending = [];
        $this->lazyLoaded = [];
        $this->batch->clear();
        $this->insertions = [];
        $this->deletions = [];
        $this->persisted = [];

        $this->lifecycle->dispatch(new OnClearEvent($this));
    }

    /**
     * Vrai pour une entité MANAGED.
     */
    public function contains(object $entity): bool
    {
        return EntityState::Managed === $this->getEntityState($entity);
    }

    public function getEntityState(object $entity): EntityState
    {
        return $this->states[spl_object_id($entity)] ?? (isset($this->detached[$entity]) ? EntityState::Detached : EntityState::New);
    }

    /**
     * Entité gérée par son identifiant : la même instance tant que clear() n'est pas appelé
     * (RG-03.3) ; null si la ligne n'existe pas. L'entité rendue est chargée, y compris une
     * référence obtenue par getReference().
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function find(string $class, mixed $id): ?object
    {
        $key = self::key($id);
        if (isset($this->identityMap[$class][$key])) {
            /** @var T $entity */
            $entity = $this->identityMap[$class][$key];
            try {
                $this->lazy->initialize($entity);
            } catch (EntityNotFoundException) {
                $this->forget($entity);

                return null;
            }

            return $entity;
        }

        $metadata = $this->metadata->getMetadataFor($class);
        $row = $this->persisters->getPersister($metadata)->loadById($this->toDatabase($metadata->getIdField(), $id));
        if (null === $row) {
            return null;
        }
        $cohort = new Cohort();
        $entity = $this->register($metadata, $row, $cohort);
        $this->completeCohort($metadata, $cohort->members());

        return $entity;
    }

    /**
     * Entités qui satisfont les critères (champ → valeur PHP ; null : IS NULL ; liste : IN).
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     * @param array<string, Order> $orderBy  champ → sens
     *
     * @return list<T>
     *
     * @throws MappingException champ inconnu
     */
    public function findBy(string $class, array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        $metadata = $this->metadata->getMetadataFor($class);

        return $this->hydrate($metadata, $this->persisters->getPersister($metadata)->loadBy($this->criteria($metadata, $criteria), $orderBy, $limit, $offset));
    }

    /**
     * Entités gérées des lignes d'une requête : identity map, une cohorte (RG-04.9).
     *
     * @template T of object
     *
     * @param ClassMetadata<T>           $metadata
     * @param list<array<string, mixed>> $rows     lignes telles que la base les renvoie
     * @param bool                       $batch    false : pas de cohorte, chaque relation se
     *                                             charge seule (RG-04.13)
     * @param list<string>               $fetched  relations chargées par une jointure, que la
     *                                             fin de cohorte ne charge pas (RG-05.5)
     *
     * @return list<T>
     *
     * @internal le QueryBuilder typé y passe ses lignes
     */
    public function hydrate(ClassMetadata $metadata, array $rows, bool $batch = true, array $fetched = [], bool $vectors = false): array
    {
        $cohort = $batch ? new Cohort() : null;
        $fresh = [];
        $entities = [];
        foreach ($rows as $row) {
            $key = self::key($this->toPhp($metadata->getIdField(), $row[$metadata->getIdField()->columnName] ?? null));
            $known = isset($this->identityMap[$metadata->className][$key]);
            $entities[] = $entity = $this->register($metadata, $row, $cohort, $vectors, $key);
            if (!$known) {
                $fresh[] = $entity;
                foreach ($fetched as $field) {
                    if ($metadata->getAssociation($field)->isToOne()) {
                        $this->fetchedInverse[spl_object_id($entity)][$field] = true;
                    }
                }
            }
        }
        $this->completeCohort($metadata, null === $cohort ? $fresh : $cohort->members(), fetched: $fetched);

        return $entities;
    }

    /**
     * Côté inverse d'un OneToOne chargé par une jointure (RG-05.5), sans requête. Seule une
     * entité que la requête vient d'hydrater est remplie : une entité déjà connue a déjà le sien.
     *
     * @internal le QueryBuilder typé y passe la cible d'un fetchJoin()
     */
    public function initializeInverseToOne(object $owner, string $field, ?object $target): void
    {
        $oid = spl_object_id($owner);
        if (!isset($this->fetchedInverse[$oid][$field])) {
            return;
        }
        unset($this->fetchedInverse[$oid][$field]);
        $metadata = $this->metadataOf($owner);
        $this->property($metadata->className, $field)->setValue($owner, $target);
        if ($metadata->getAssociation($field)->orphanRemoval) {
            $this->inverseSnapshots[$oid][$field] = $target;
        }
    }

    /**
     * Collection chargée par une jointure (RG-05.5) : remplie et marquée chargée, sans requête.
     * Une collection déjà chargée, ou remplacée par l'application, n'est pas touchée.
     *
     * @param list<object> $elements
     *
     * @internal le QueryBuilder typé y passe les éléments d'un fetchJoin()
     */
    public function initializeCollection(object $owner, string $field, array $elements): void
    {
        $metadata = $this->metadataOf($owner);
        $collection = $this->read($owner, $metadata->className, $field);
        if (!$collection instanceof PersistentCollection || $collection->isInitialized()) {
            return;
        }
        $association = $metadata->getAssociation($field);
        $collection->initializeWith($this->indexed($elements, $association, $this->metadata->getMetadataFor($association->targetEntity)));
        $oid = spl_object_id($owner);
        if (($this->entities[$oid] ?? null) === $owner) {
            $this->collectionSnapshots[$oid][$field] = $elements;
        }
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $criteria
     *
     * @throws MappingException champ inconnu
     */
    public function count(string $class, array $criteria = []): int
    {
        $metadata = $this->metadata->getMetadataFor($class);

        return $this->persisters->getPersister($metadata)->count($this->criteria($metadata, $criteria));
    }

    /**
     * Les entités dont le vecteur est le plus proche (RG-09.4) : les filtres (tenant, suppression
     * logique) et les critères s'appliquent avant le classement, dans la base ; la distance est
     * celle du #[VectorIndex] du champ, sinon le cosinus. Les vecteurs sont lus avec les entités.
     * Un vecteur #[EmbeddedVector] périmé est exclu, sauf $includeStale (RG-09.8).
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param list<float>|Vector   $vector
     * @param array<string, mixed> $criteria
     *
     * @return list<ScoredResult<T>>
     *
     * @throws InvalidArgumentException         limite inférieure à 1
     * @throws MappingException                 champ inconnu, ou qui n'est pas un vecteur
     * @throws VectorDimensionMismatchException vecteur d'une autre dimension que le champ (RG-09.1)
     */
    public function nearest(string $class, string $field, array|Vector $vector, int $limit = 10, array $criteria = [], bool $includeStale = false): array
    {
        if ($limit < 1) {
            throw InvalidArgumentException::batchSize($limit);
        }
        $metadata = $this->metadata->getMetadataFor($class);
        [$mapping, $values, $distance] = $this->vectorSearch($metadata, $field, $vector, 'nearest()');

        $stale = $includeStale ? null : $metadata->getEmbedding($field)?->staleColumn;
        $rows = $this->persisters->getPersister($metadata)->loadNearest($mapping->columnName, $values, $distance->toDbal(), $limit, $this->criteria($metadata, $criteria), $stale);
        $distances = [];
        foreach ($rows as $i => $row) {
            $value = $row[BasicEntityPersister::DISTANCE_ALIAS] ?? null;
            $distances[$i] = is_numeric($value) ? (float) $value : \INF;
            unset($rows[$i][BasicEntityPersister::DISTANCE_ALIAS]);
        }
        $entities = $this->hydrate($metadata, $rows, vectors: true);
        // pgvector (parcours itératif) peut rendre un ordre légèrement relâché : rétabli ici
        asort($distances);
        $results = [];
        foreach ($distances as $i => $d) {
            $results[] = new ScoredResult($entities[$i], $d, $distance);
        }

        return $results;
    }

    /**
     * Recherche hybride (RG-09.5) : les candidats plein texte (au moins un mot cherché) et les
     * candidats vectoriels, filtrés de la même façon, fusionnés par Reciprocal Rank Fusion ; les
     * $limit meilleurs sont chargés en une requête, dans l'ordre fusionné. Le champ vecteur et
     * l'index plein texte sont ceux de l'entité, ou ceux nommés par les options. Un vecteur
     * périmé est exclu de la liste vectorielle, pas de la liste plein texte (RG-09.8).
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param list<float>|Vector   $vector
     * @param array<string, mixed> $criteria
     *
     * @return list<HybridResult<T>>
     *
     * @throws InvalidArgumentException         limite inférieure à 1
     * @throws MappingException                 entité sans champ vecteur ou sans #[FullTextIndex], ou plusieurs sans option
     * @throws VectorDimensionMismatchException vecteur d'une autre dimension que le champ (RG-09.1)
     */
    public function hybridSearch(string $class, string $query, array|Vector $vector, int $limit = 10, array $criteria = [], HybridOptions $options = new HybridOptions()): array
    {
        if ($limit < 1) {
            throw InvalidArgumentException::batchSize($limit);
        }
        $metadata = $this->metadata->getMetadataFor($class);
        $vectorFields = array_values(array_filter($metadata->getFields(), static fn (FieldMapping $f): bool => 'vector' === $f->type));
        $field = $options->field ?? (1 === \count($vectorFields) ? $vectorFields[0]->fieldName : null);
        if (null === $field) {
            throw MappingException::invalidMetadata($class, [] === $vectorFields ? 'hybridSearch() needs a field of type "vector".' : \sprintf('hybridSearch() needs HybridOptions::$field: the entity has several vector fields (%s).', implode(', ', array_map(static fn (FieldMapping $f): string => $f->fieldName, $vectorFields))));
        }
        [$mapping, $values, $distance] = $this->vectorSearch($metadata, $field, $vector, 'hybridSearch()');
        $fullText = array_values(array_filter($metadata->indexes, static fn (IndexMapping $i): bool => IndexKind::FullText === $i->kind && (null === $options->fullTextIndex || $i->name === $options->fullTextIndex)));
        if (1 !== \count($fullText)) {
            throw MappingException::invalidMetadata($class, match (true) {
                null !== $options->fullTextIndex => \sprintf('hybridSearch() found no #[FullTextIndex] named "%s".', $options->fullTextIndex), [] === $fullText => 'hybridSearch() needs a #[FullTextIndex].', default => 'hybridSearch() needs HybridOptions::$fullTextIndex: the entity has several #[FullTextIndex]; name the one to search.',
            });
        }
        $columns = array_map(static fn (string $f): string => $metadata->getField($f)->columnName, $fullText[0]->fields);

        $persister = $this->persisters->getPersister($metadata);
        $idField = $metadata->getIdField();
        $candidates = $options->candidatesFor($limit);
        $filters = $this->criteria($metadata, $criteria);
        $ranks = [];
        $stale = $options->includeStale ? null : $metadata->getEmbedding($field)?->staleColumn;
        foreach (['text' => $persister->loadFullTextIds($columns, $fullText[0]->language, $query, $candidates, $filters), 'vector' => $persister->loadNearestIds($mapping->columnName, $values, $distance->toDbal(), $candidates, $filters, $stale)] as $list => $ids) {
            foreach ($ids as $rank => $id) {
                $phpId = $this->toPhp($idField, $id);
                $ranks[self::key($phpId)][$list] = $rank + 1;
                $ranks[self::key($phpId)]['id'] = $phpId;
            }
        }
        $scores = [];
        foreach ($ranks as $key => $rank) {
            $scores[$key] = (isset($rank['text']) ? $options->textWeight / ($options->k + $rank['text']) : 0.0)
                + (isset($rank['vector']) ? $options->vectorWeight / ($options->k + $rank['vector']) : 0.0);
        }
        // à score égal, l'ordre des clés : un résultat stable d'un appel à l'autre
        uksort($scores, static fn (string $a, string $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);
        $best = \array_slice($scores, 0, $limit, true);

        $entities = [];
        $ids = array_map(static fn (string $key): mixed => $ranks[$key]['id'], array_keys($best));
        foreach ([] === $ids ? [] : $this->findBy($class, [$idField->fieldName => $ids]) as $entity) {
            $entities[self::key($this->property($class, $idField->fieldName)->getValue($entity))] = $entity;
        }
        $results = [];
        foreach ($best as $key => $score) {
            // une ligne supprimée entre les deux requêtes n'est pas rendue
            if (isset($entities[$key])) {
                $results[] = new HybridResult($entities[$key], $score, $ranks[$key]['text'] ?? null, $ranks[$key]['vector'] ?? null);
            }
        }

        return $results;
    }

    /**
     * Marque périmés tous les vecteurs d'un champ #[EmbeddedVector] que les filtres laissent
     * écrire (changement de modèle, RG-09.10) ; les entités déjà chargées ne changent pas.
     *
     * @param class-string $class
     *
     * @return int lignes marquées
     *
     * @throws MappingException champ sans #[EmbeddedVector]
     */
    public function markEmbeddingsStale(string $class, string $field): int
    {
        $metadata = $this->metadata->getMetadataFor($class);
        $embedding = $metadata->getEmbedding($field) ?? throw MappingException::invalidField($metadata->className, $field, 'the field has no #[EmbeddedVector].');

        return $this->persisters->getPersister($metadata)->updateAll([$embedding->staleColumn => $this->types->get('boolean')->toDatabase(true, $this->conversion)]);
    }

    /**
     * Le champ vecteur cherché, le vecteur vérifié (RG-09.1) et la distance de son index.
     *
     * @param ClassMetadata<object> $metadata
     * @param list<float>|Vector    $vector
     *
     * @return array{FieldMapping, list<float>, Distance}
     *
     * @throws MappingException                 champ inconnu, ou qui n'est pas un vecteur
     * @throws VectorDimensionMismatchException
     */
    private function vectorSearch(ClassMetadata $metadata, string $field, array|Vector $vector, string $method): array
    {
        if (!$metadata->hasField($field) || 'vector' !== $metadata->getField($field)->type) {
            throw MappingException::invalidField($metadata->className, $field, $method . ' needs a field of type "vector".');
        }
        $mapping = $metadata->getField($field);
        $values = ($vector instanceof Vector ? $vector : new Vector($vector))->toArray();
        if (null !== $mapping->length && \count($values) !== $mapping->length) {
            throw VectorDimensionMismatchException::forField($metadata->className, $field, $mapping->length, \count($values));
        }
        $distance = Distance::Cosine;
        foreach ($metadata->indexes as $index) {
            if (IndexKind::Vector === $index->kind && [$field] === $index->fields) {
                $distance = $index->distance ?? Distance::Cosine;
            }
        }

        return [$mapping, $values, $distance];
    }

    /**
     * Parcourt les entités par lots de $batchSize, en pagination sur l'identifiant : une requête
     * par lot, sans OFFSET. À la fin de chaque lot, les entités que l'itération a chargées sont
     * détachées (RG-03.10) ; une entité déjà gérée avant l'appel, ou supprimée pendant, reste
     * suivie. Une modification non flushée avant la fin de son lot est donc perdue.
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return \Generator<int, T, mixed, void>
     *
     * @throws InvalidArgumentException $batchSize inférieur à 1
     * @throws MappingException         champ inconnu
     */
    public function iterate(string $class, array $criteria = [], int $batchSize = 100): \Generator
    {
        if ($batchSize < 1) {
            throw InvalidArgumentException::batchSize($batchSize);
        }
        $metadata = $this->metadata->getMetadataFor($class);
        $persister = $this->persisters->getPersister($metadata);
        $idColumn = $metadata->getIdField()->columnName;
        $criteria = $this->criteria($metadata, $criteria);

        $after = null;
        do {
            $rows = $persister->loadAfter($criteria, $after, $batchSize);
            // RG-04.14 : une page, une cohorte
            $cohort = new Cohort();
            $page = [];
            $loaded = [];
            foreach ($rows as $row) {
                $known = isset($this->identityMap[$class][self::key($this->toPhp($metadata->getIdField(), $row[$idColumn]))]);
                $entity = $this->register($metadata, $row, $cohort);
                if (!$known) {
                    $loaded[] = $entity;
                }
                $page[] = $entity;
                $after = $row[$idColumn];
            }
            $this->completeCohort($metadata, $cohort->members());
            unset($cohort);

            foreach ($page as $entity) {
                yield $entity;
            }
            $page = [];
            foreach ($loaded as $entity) {
                if ($this->contains($entity)) {
                    $this->forget($entity, detach: true);
                }
            }
        } while (\count($rows) === $batchSize);
    }

    /**
     * Référence vers une entité sans la charger (décision B) : un ghost natif dont seul
     * l'identifiant est lisible sans requête ; tout autre accès charge la ligne, une fois, et
     * lève EntityNotFoundException si elle n'existe pas (RG-04.7, RG-04.8). Une entité déjà
     * gérée est rendue telle quelle.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function getReference(string $class, mixed $id): object
    {
        $metadata = $this->metadata->getMetadataFor($class);
        $idField = $metadata->getIdField();
        $id = $this->toPhp($idField, $this->toDatabase($idField, $id));
        $key = self::key($id);
        if (isset($this->identityMap[$class][$key])) {
            /** @var T */
            return $this->identityMap[$class][$key];
        }

        $ghost = $this->lazy->ghost($metadata, $id, function (object $ghost) use ($metadata, $key, $id): void {
            $this->loadGhost($metadata, $ghost, $key, $id);
        });
        $this->track($ghost, EntityState::Managed);
        $this->identityMap[$class][$key] = $ghost;
        $this->keys[spl_object_id($ghost)] = $key;

        return $ghost;
    }

    /**
     * Relit la ligne d'une entité gérée : ses modifications non flushées sont perdues, une
     * propriété readonly n'est jamais réécrite (RG-02.6).
     *
     * @throws InvalidEntityStateException entité qui n'est pas MANAGED, ou pas encore écrite
     * @throws EntityNotFoundException     la ligne n'existe plus
     */
    public function refresh(object $entity): void
    {
        $oid = $this->assertWritten($entity, 'refresh');
        if ($this->lazy->isUninitialized($entity)) {
            $this->lazy->initialize($entity);

            return;
        }

        $metadata = $this->metadataOf($entity);
        // une référence : la ligne supprimée logiquement se relit (ADR 0015)
        $row = $this->persisters->getPersister($metadata)->loadById($this->idOf($metadata, $oid), FilterOperation::Load);
        if (null === $row) {
            throw EntityNotFoundException::forId($metadata->className, $this->property($metadata->className, $metadata->id->fieldName)->getValue($entity));
        }
        $nulls = $this->takeLazy($metadata, $row);
        $this->hydrator->refresh($entity, $metadata, $row);
        $this->placeLazy($metadata, $entity, $nulls);
        $this->hydrateRelations($metadata, $entity, $row, refresh: true);
        $this->hydrateCollections($metadata, $entity, refresh: true);
        $this->completeCohort($metadata, [$entity], refresh: true);
        $this->snapshots[$oid] = $this->databaseRow($metadata, $entity) + $this->tenantColumn($metadata, $row);
        $this->postLoad($metadata, $entity);
    }

    /**
     * Verrou optimiste : vérifie que la version lue en base est $version (sans requête).
     * Verrou pessimiste : SELECT … FOR UPDATE / FOR SHARE dans la transaction en cours ; une
     * référence est verrouillée avant d'être chargée, pour être lue à jour.
     *
     * @throws InvalidEntityStateException  entité qui n'est pas MANAGED, ou pas encore écrite
     * @throws OptimisticLockException      entité sans #[Version], ou version différente
     * @throws TransactionRequiredException verrou pessimiste hors transaction
     * @throws EntityNotFoundException      la ligne n'existe plus
     */
    public function lock(object $entity, LockMode $mode, mixed $version = null): void
    {
        $oid = $this->assertWritten($entity, 'lock');
        $metadata = $this->metadataOf($entity);

        if (LockMode::Optimistic === $mode) {
            if (null === $metadata->versionField) {
                throw OptimisticLockException::notVersioned($entity);
            }
            $this->lazy->initialize($entity);
            $field = $metadata->getField($metadata->versionField);
            if (null !== $version && $this->toDatabase($field, $version) !== $this->snapshots[$oid][$field->columnName]) {
                throw OptimisticLockException::versionMismatch($entity, $version);
            }

            return;
        }

        if (!$this->persisters->isTransactionActive()) {
            throw TransactionRequiredException::forLock($entity, $mode);
        }
        // l'identifiant d'une référence se lit sans la charger
        $id = $this->property($metadata->className, $metadata->id->fieldName)->getValue($entity);
        if (!$this->persisters->getPersister($metadata)->lock($this->toDatabase($metadata->getIdField(), $id), $mode)) {
            throw EntityNotFoundException::forId($metadata->className, $id);
        }
    }

    /**
     * Écrit tout en une transaction (RG-03.6) : INSERT dans l'ordre des FK puis de persist(),
     * UPDATE des seules colonnes modifiées, DELETE en ordre inverse. En cas d'échec, rollback et
     * l'état d'avant le flush est rétabli, identifiants générés et versions compris.
     *
     * @throws ReentrantFlushException          appelé depuis un listener (RG-03.7)
     * @throws OptimisticLockException          version périmée (RG-03.9)
     * @throws UnpersistedRelationException     entité NEW atteinte sans cascade: persist (RG-04.2)
     * @throws CyclicDependencyException        cycle de FK obligatoires (RG-04.4)
     * @throws OrphanReattachedException        orphelin rattaché à un autre parent (RG-04.5)
     * @throws MissingTenantException           entité #[Tenanted] à écrire sans tenant (RG-08.4)
     * @throws TenantMismatchException          tenant d'un autre, ou changé (RG-08.3)
     * @throws CrossTenantRelationException     relation entre deux tenants (RG-08.6)
     * @throws VectorDimensionMismatchException vecteur d'une autre dimension que sa colonne (RG-09.1)
     */
    public function commit(): void
    {
        if ($this->flushing) {
            throw ReentrantFlushException::create();
        }

        $this->flushing = true;
        try {
            $this->lifecycle->dispatch(new PreFlushEvent($this));
            $this->persistRelations();
            $this->removeOrphans();
            $this->lifecycle->dispatch(new OnFlushEvent($this));
            $this->persistRelations();
            $this->removeOrphans();

            // calculés après OnFlush : ses modifications sont prises en compte
            $updates = $this->pendingUpdates();
            $collections = $this->pendingCollections();
            $this->reportInverseChanges($collections);
            $this->assertSameTenants($updates, $collections);
            if ([] !== $this->insertions || [] !== $this->deletions || [] !== $updates || [] !== $collections) {
                $this->executeFlush($updates, $collections, $this->insertionOrder(), $this->deletionOrder());
            }

            $this->lifecycle->dispatch(new PostFlushEvent($this));
        } finally {
            $this->flushing = false;
        }
        // RG-09.7 : après le commit et PostFlush, flush terminé : un scheduler peut vider l'UoW
        if (!$this->persisters->isTransactionActive()) {
            $this->releaseStaleEmbeddings();
        }
    }

    /**
     * RG-08.8 : aucune entité ne passe d'un tenant à l'autre. Sans changement en attente,
     * l'identity map est vidée ; avec, le changement est refusé à l'entrée, et les changements
     * abandonnés à la sortie d'un runAs() (ADR 0014).
     *
     * @throws TenantSwitchException
     */
    public function switching(TenantScope $from, TenantScope $to, bool $leaving): void
    {
        if ([] === $this->entities && [] === $this->insertions) {
            return;
        }
        if ($this->hasPendingChanges()) {
            if (!$leaving) {
                throw TenantSwitchException::pendingChanges($from, $to);
            }
            $this->clear();

            throw TenantSwitchException::discarded($from, $to);
        }
        $this->clear();
    }

    /**
     * Vrai si un flush écrirait quelque chose (calculé à l'appel, comme un flush).
     */
    public function hasPendingChanges(): bool
    {
        return [] !== $this->insertions || [] !== $this->deletions || [] !== $this->pendingUpdates() || [] !== $this->pendingCollections();
    }

    /**
     * Entités à insérer au prochain flush, dans l'ordre de persist().
     *
     * @return list<object>
     */
    public function getScheduledInsertions(): array
    {
        return array_values($this->insertions);
    }

    /**
     * Entités gérées dont les changements seront écrits au prochain flush (calculés à l'appel).
     *
     * @return list<object>
     */
    public function getScheduledUpdates(): array
    {
        return array_map(fn (int $oid): object => $this->entities[$oid], array_keys($this->pendingUpdates()));
    }

    /**
     * Entités à supprimer au prochain flush.
     *
     * @return list<object>
     */
    public function getScheduledDeletions(): array
    {
        return array_values($this->deletions);
    }

    /**
     * La transaction du flush, puis l'état de l'UnitOfWork et les événements Post*.
     *
     * @param array<int, array<string, mixed>>                                       $updates
     * @param list<array{int, AssociationMapping, list<object>, list<object>, bool}> $collections
     */
    private function executeFlush(array $updates, array $collections, CommitOrder $insertions, CommitOrder $deletions): void
    {
        /** @var list<array{object, \ReflectionProperty, mixed}> valeurs écrites sur les entités, à rétablir si rollback */
        $written = [];

        $this->auditRecords = [];
        $this->flushStale = [];
        try {
            $done = $this->persisters->transactional(function () use ($updates, $collections, $insertions, $deletions, &$written): array {
                $inserted = $this->executeInsertions($insertions, $written);
                $updated = $this->executeUpdates($updates, $written);
                $this->executeCollections($collections);
                $deleted = $this->executeDeletions($deletions);
                // RG-08.10 : dans la transaction du flush, après les écritures des entités
                $entries = null === $this->audit ? [] : $this->audit->write($this->auditRecords);

                return [$inserted, $updated, $deleted, $entries];
            });
        } catch (\Throwable $e) {
            foreach (array_reverse($written) as [$entity, $property, $value]) {
                // une propriété non initialisée et non nullable ne peut pas le redevenir
                if (null === $value && false === $property->getType()?->allowsNull()) {
                    continue;
                }
                $property->setValue($entity, $value);
            }

            throw $e;
        }

        [$inserted, $updated, $deleted, $entries] = $done;
        $this->afterCommit($inserted, $updated, $deleted);
        $this->collectStale();
        if ([] !== $entries) {
            $this->lifecycle->dispatch(new AuditRecordedEvent($entries));
        }
        foreach ($collections as [$oid, $association]) {
            $collection = $this->read($this->entities[$oid] ?? throw new \LogicException('Unknown owner.'), $this->metadataOf($this->entities[$oid])->className, $association->fieldName);
            if ($collection instanceof Collection) {
                $this->collectionSnapshots[$oid][$association->fieldName] = array_values($collection->toArray());
                if ($collection instanceof PersistentCollection) {
                    $collection->acknowledgeClear();
                }
            }
        }
    }

    /**
     * @param list<array{object, \ReflectionProperty, mixed}> $written
     *
     * @return list<object>
     */
    private function executeInsertions(CommitOrder $order, array &$written): array
    {
        /** @var array<int, list<string>> colonnes de jointure insérées à NULL, par entité */
        $nulled = [];
        foreach ($order->deferred as $dependency) {
            $nulled[$dependency->from][] = (string) $this->metadataOf($this->insertions[$dependency->from])->getAssociation($dependency->field)->joinColumn;
        }

        $inserted = [];
        foreach ($order->nodes as $oid) {
            $entity = $this->insertions[$oid];
            $metadata = $this->metadataOf($entity);
            $this->lifecycle->lifecycle($metadata, LifecycleEvent::PrePersist, $entity, fn (): PrePersistEvent => new PrePersistEvent($entity, $this));
            $this->stampInsert($metadata, $entity, $written);
            $idField = $metadata->getIdField();

            if (null !== $generated = $this->persisters->nextId($metadata)) {
                $this->write($entity, $idField->fieldName, $this->toPhp($idField, $generated), $written);
            }

            $row = array_filter($this->databaseRow($metadata, $entity), fn (string $column): bool => $this->field($metadata, $column)->insertable, \ARRAY_FILTER_USE_KEY);
            foreach ($nulled[$oid] ?? [] as $column) {
                $row[$column] = null;
            }
            $row = $this->withTenant($metadata, $entity, $row, $written);
            $identity = $this->persisters->getPersister($metadata)->insert($row + $this->embeddingState($metadata, $entity, $row, null));
            if (null !== $identity) {
                $this->write($entity, $idField->fieldName, $this->toPhp($idField, $identity), $written);
            }
            $inserted[] = $entity;
        }

        // RG-04.4 : les FK insérées à NULL, une fois leur cible insérée
        foreach ($order->deferred as $dependency) {
            $entity = $this->insertions[$dependency->from];
            $metadata = $this->metadataOf($entity);
            $column = (string) $metadata->getAssociation($dependency->field)->joinColumn;
            $this->persisters->getPersister($metadata)->update($this->currentId($metadata, $entity), [$column => $this->databaseRow($metadata, $entity)[$column]]);
        }

        foreach ($inserted as $entity) {
            $metadata = $this->metadataOf($entity);
            if ($metadata->behaviors->auditable) {
                $oid = spl_object_id($entity);
                $row = $this->databaseRow($metadata, $entity);
                $tenant = null === $metadata->tenancy ? null : ($row[$metadata->tenancy->column] ?? $this->insertedTenants[$oid] ?? null);
                $this->audit($metadata, AuditLog::INSERT, $this->currentId($metadata, $entity), $tenant, [], $row);
            }
        }

        return $inserted;
    }

    /**
     * @param array<int, array<string, mixed>>                $updates changements calculés avant la transaction
     * @param list<array{object, \ReflectionProperty, mixed}> $written
     *
     * @return list<array{object, array<string, mixed>}> entité et colonnes écrites
     */
    private function executeUpdates(array $updates, array &$written): array
    {
        $updated = [];
        foreach ($updates as $oid => $changes) {
            $entity = $this->entities[$oid];
            $metadata = $this->metadataOf($entity);

            // RG-03.8 : une modification faite dans PreUpdate est prise en compte ; sans personne pour
            // la recevoir, l'entité n'a pas changé depuis le calcul des changements
            if ($this->lifecycle->lifecycle($metadata, LifecycleEvent::PreUpdate, $entity, fn (): PreUpdateEvent => new PreUpdateEvent($entity, $this->changeSet($metadata, $oid, $changes), $this))) {
                $changes = $this->changes($metadata, $entity);
            }
            if ([] === $changes) {
                continue;
            }
            if ($this->stampUpdate($metadata, $entity, $changes, $written)) {
                $changes = $this->changes($metadata, $entity);
            }
            $this->assertTenantUnchanged($metadata, $oid, $changes);

            $version = null;
            if (null !== $metadata->versionField) {
                $field = $metadata->getField($metadata->versionField);
                $old = $this->snapshots[$oid][$field->columnName];
                $oldPhp = $this->toPhp($field, $old);
                $this->write($entity, $field->fieldName, \is_int($oldPhp) ? $oldPhp + 1 : new \DateTimeImmutable(), $written);
                $changes[$field->columnName] = $this->toDatabase($field, $this->property($metadata->className, $field->fieldName)->getValue($entity));
                $version = [$field->columnName, $old];
            }

            $unread = $metadata->behaviors->auditable ? $this->unreadOldValues($metadata, $oid, $changes) : [];
            $affected = $this->persisters->getPersister($metadata)->update($this->idOf($metadata, $oid), $changes + $this->embeddingState($metadata, $entity, null, $changes), $version);
            if (0 === $affected && null !== $version) {
                throw OptimisticLockException::versionMismatch($entity, $this->toPhp($metadata->getField($metadata->versionField), $version[1]));
            }
            if ($metadata->behaviors->auditable && 0 !== $affected) {
                $action = isset($this->softDeleted[$oid]) ? AuditLog::SOFT_DELETE : (isset($this->restored[$oid]) ? AuditLog::RESTORE : AuditLog::UPDATE);
                $this->audit($metadata, $action, $this->idOf($metadata, $oid), $this->snapshotTenant($metadata, $oid), array_intersect_key($this->snapshots[$oid] ?? [], $changes) + $unread, $changes);
            }
            $updated[] = [$entity, $changes];
        }

        return $updated;
    }

    /**
     * @return list<object>
     */
    private function executeDeletions(CommitOrder $order): array
    {
        // lignes pivot des entités supprimées, des deux côtés déclarés
        foreach ($this->deletions as $oid => $entity) {
            $metadata = $this->metadataOf($entity);
            foreach ($this->collectionsOf($metadata) as $association) {
                if (AssociationType::ManyToMany !== $association->type) {
                    continue;
                }
                [$table, $ownerColumn] = $this->joinTableOf($association);
                $this->persisters->getPersister($metadata)->deleteJoinRows($table, [$ownerColumn => $this->idOf($metadata, $oid)], [$ownerColumn => $metadata->getIdField()->type]);
            }
        }

        // RG-04.4 : dans un cycle, la FK nullable est remise à NULL avant les DELETE
        foreach ($order->deferred as $dependency) {
            $metadata = $this->metadataOf($this->deletions[$dependency->from]);
            $this->persisters->getPersister($metadata)->update($this->idOf($metadata, $dependency->from), [(string) $metadata->getAssociation($dependency->field)->joinColumn => null]);
        }

        $deleted = [];
        foreach (array_reverse($order->nodes) as $oid) {
            $entity = $this->deletions[$oid];
            $metadata = $this->metadataOf($entity);
            $version = null;
            if (null !== $metadata->versionField) {
                $column = $metadata->getField($metadata->versionField)->columnName;
                $version = [$column, $this->snapshots[$oid][$column]];
            }

            $affected = $this->persisters->getPersister($metadata)->delete($this->idOf($metadata, $oid), $version);
            if (0 === $affected && null !== $version) {
                throw OptimisticLockException::versionMismatch($entity, $this->toPhp($metadata->getField($metadata->versionField), $version[1]));
            }
            if ($metadata->behaviors->auditable && 0 !== $affected) {
                $this->audit($metadata, AuditLog::DELETE, $this->idOf($metadata, $oid), $this->snapshotTenant($metadata, $oid), $this->snapshots[$oid] ?? [], []);
            }
            $deleted[] = $entity;
        }

        return $deleted;
    }

    /**
     * Rien n'a changé dans l'UnitOfWork pendant la transaction : tout est appliqué ici, une fois
     * le commit acquis, puis les événements Post* sont publiés.
     *
     * @param list<object>                              $inserted
     * @param list<array{object, array<string, mixed>}> $updated
     * @param list<object>                              $deleted
     */
    private function afterCommit(array $inserted, array $updated, array $deleted): void
    {
        foreach ($inserted as $entity) {
            $metadata = $this->metadataOf($entity);
            $oid = spl_object_id($entity);
            $this->snapshots[$oid] = $this->databaseRow($metadata, $entity);
            if (\array_key_exists($oid, $this->insertedTenants) && null !== $metadata->tenancy) {
                $this->snapshots[$oid][$metadata->tenancy->column] = $this->insertedTenants[$oid];
            }
            unset($this->insertedTenants[$oid]);
            $key = self::key($this->property($metadata->className, $metadata->id->fieldName)->getValue($entity));
            $this->identityMap[$metadata->className][$key] = $entity;
            $this->keys[$oid] = $key;
        }
        foreach ($updated as [$entity, $changes]) {
            $oid = spl_object_id($entity);
            $this->snapshots[$oid] = $changes + $this->snapshots[$oid];
        }
        $this->softDeleted = [];
        $this->restored = [];
        foreach ($deleted as $entity) {
            $this->forget($entity);
        }
        $this->insertions = [];
        $this->deletions = [];
        $this->persisted = [];

        foreach ($inserted as $entity) {
            $this->lifecycle->lifecycle($this->metadataOf($entity), LifecycleEvent::PostPersist, $entity, fn (): PostPersistEvent => new PostPersistEvent($entity, $this));
        }
        foreach ($updated as [$entity]) {
            $this->lifecycle->lifecycle($this->metadataOf($entity), LifecycleEvent::PostUpdate, $entity, fn (): PostUpdateEvent => new PostUpdateEvent($entity, $this));
        }
        foreach ($deleted as $entity) {
            $this->lifecycle->lifecycle($this->metadataOf($entity), LifecycleEvent::PostRemove, $entity, fn (): PostRemoveEvent => new PostRemoveEvent($entity, $this));
        }
    }

    /**
     * Changements des entités gérées et déjà écrites (RG-03.4), selon leur politique de suivi
     * (RG-03.11) ; une entité readOnly n'est jamais comparée.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingUpdates(): array
    {
        $updates = [];
        foreach ($this->entities as $oid => $entity) {
            if (EntityState::Managed !== $this->states[$oid] || !isset($this->snapshots[$oid])) {
                continue;
            }
            $metadata = $this->metadataOf($entity);
            if ($metadata->readOnly || (TrackingPolicy::Explicit === $metadata->trackingPolicy && !isset($this->persisted[$oid]))) {
                continue;
            }
            if ([] !== $changes = $this->changes($metadata, $entity)) {
                $updates[$oid] = $changes;
            }
        }

        return $updates;
    }

    /**
     * Colonnes dont la valeur de la base diffère du snapshot (RG-03.5) : ni l'identifiant, ni la
     * version, ni une propriété readonly (RG-02.6), ni une colonne updatable: false.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, mixed>
     */
    private function changes(ClassMetadata $metadata, object $entity): array
    {
        $snapshot = $this->snapshots[spl_object_id($entity)] ?? [];
        $idColumn = $metadata->getIdField()->columnName;
        $versionColumn = null === $metadata->versionField ? null : $metadata->getField($metadata->versionField)->columnName;
        $topLevel = array_map(static fn (FieldMapping $f): string => $f->columnName, $metadata->getFields());

        $changes = [];
        foreach ($this->databaseRow($metadata, $entity, $snapshot) as $column => $value) {
            $field = $this->field($metadata, $column);
            $readonly = $field->readonly && \in_array($column, $topLevel, true);
            if ($column === $idColumn || $column === $versionColumn || $readonly || !$field->updatable) {
                continue;
            }
            if (!\array_key_exists($column, $snapshot) || $snapshot[$column] !== $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * Changeset de PreUpdate : champ → [ancienne valeur, nouvelle valeur], en valeurs PHP.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $changes
     *
     * @return array<string, array{mixed, mixed}>
     */
    private function changeSet(ClassMetadata $metadata, int $oid, array $changes): array
    {
        $set = [];
        $relations = [];
        foreach ($this->owningToOne($metadata) as [$association, $target]) {
            $relations[(string) $association->joinColumn] = [$association, $target];
        }
        foreach ($changes as $column => $value) {
            if (isset($relations[$column])) {
                // relation : les entités, l'ancienne par sa référence
                [$association, $target] = $relations[$column];
                $old = $this->snapshots[$oid][$column] ?? null;
                $set[$association->fieldName] = [
                    null === $old ? null : $this->getReference($target->className, $this->toPhp($target->getIdField(), $old)),
                    $this->read($this->entities[$oid], $metadata->className, $association->fieldName),
                ];
                continue;
            }
            $field = $this->field($metadata, $column);
            $set[$metadata->getFieldPathByColumn($column) ?? $column] = [
                $metadata->toFieldValue($field, $this->toPhp($field, $this->snapshots[$oid][$column] ?? null)),
                $metadata->toFieldValue($field, $this->toPhp($field, $value)),
            ];
        }

        return $set;
    }

    /**
     * Valeurs de la base des colonnes de l'entité ; un decimal est complété à l'échelle de sa
     * colonne, pour que '12.5' et '12.50' ne soient pas vus comme un changement. Avec le snapshot,
     * un vecteur égal à sa valeur relue reprend celle du snapshot, sans être réencodé.
     *
     * @param ClassMetadata<object>     $metadata
     * @param array<string, mixed>|null $snapshot
     *
     * @return array<string, mixed>
     */
    private function databaseRow(ClassMetadata $metadata, object $entity, ?array $snapshot = null): array
    {
        $row = [];
        foreach ($metadata->extractRow($entity) as $column => $value) {
            $field = $this->field($metadata, $column);
            if (null !== $snapshot && 'vector' === $field->type && \is_array($value) && \is_string($snapshot[$column] ?? null) && $this->isStoredVector($field, $snapshot[$column], $value)) {
                $row[$column] = $snapshot[$column];
                continue;
            }
            // RG-09.1 : avant l'envoi, avec la classe et le champ
            if ('vector' === $field->type && \is_array($value) && null !== $field->length && \count($value) !== $field->length) {
                throw VectorDimensionMismatchException::forField($metadata->className, $metadata->getFieldPathByColumn($column) ?? $field->fieldName, $field->length, \count($value));
            }
            $row[$column] = $this->toDatabase($field, $value);
        }

        return $this->hasRelations($metadata) ? $row + $this->foreignKeys($metadata, $entity) : $row;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function hasRelations(ClassMetadata $metadata): bool
    {
        return $this->hasRelations[$metadata->className] ??= [] !== $metadata->getAssociations();
    }

    /**
     * FK des relations ToOne possédées : identifiant de la cible (lisible sur un ghost sans le
     * charger), null sans cible, $this->pending pour une cible pas encore insérée.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, mixed>
     */
    private function foreignKeys(ClassMetadata $metadata, object $entity): array
    {
        $keys = [];
        foreach ($this->owningToOne($metadata) as [$association, $target]) {
            $value = $this->read($entity, $metadata->className, $association->fieldName);
            $id = \is_object($value) ? $this->property($target->className, $target->id->fieldName)->getValue($value) : null;
            $keys[(string) $association->joinColumn] = match (true) {
                !\is_object($value) => null,
                null === $id => $this->pending,
                default => $this->toDatabase($target->getIdField(), $id),
            };
        }

        return $keys;
    }

    /**
     * Le vecteur PHP a-t-il les composantes de la valeur lue en base ? Comparées en flottants : une
     * liste d'entiers égale n'est pas un changement.
     *
     * @param array<mixed> $value
     */
    private function isStoredVector(FieldMapping $field, string $stored, array $value): bool
    {
        return $this->toPhp($field, $stored) === array_map(static fn (mixed $x): mixed => \is_int($x) ? (float) $x : $x, $value);
    }

    private function toDatabase(FieldMapping $field, mixed $value): mixed
    {
        // les enums sont déjà ramenés à leur valeur par ClassMetadata::extractRow()
        $value = $this->types->get($field->type)->toDatabase($value, $this->conversion);

        return 'decimal' === $field->type && \is_string($value) ? self::normalizeDecimal($value, $field->scale ?? 0) : $value;
    }

    private function toPhp(FieldMapping $field, mixed $value): mixed
    {
        return $this->types->get($field->type)->toPhp($value, $this->conversion);
    }

    private static function normalizeDecimal(string $value, int $scale): string
    {
        if (1 !== preg_match('/^([+-]?\d+)(?:\.(\d*))?$/', $value, $m)) {
            return $value;
        }
        $fraction = $m[2] ?? '';

        return \strlen($fraction) >= $scale ? $value : $m[1] . '.' . str_pad($fraction, $scale, '0');
    }

    /**
     * RG-08.10, RG-08.11 : une ligne d'audit de ce flush ; un champ par colonne changée (la
     * relation ToOne par sa clé étrangère), sans les champs exclus, « *** » pour un champ
     * sensible ; la colonne du tenant que ne porte aucun champ donne le tenant, pas un changement.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $old      valeurs de la base avant, par colonne
     * @param array<string, mixed>  $new      valeurs de la base après, par colonne
     */
    private function audit(ClassMetadata $metadata, string $action, mixed $id, mixed $tenant, array $old, array $new): void
    {
        if (null === $this->audit) {
            return;
        }
        $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $column) {
            $field = $metadata->getFieldPathByColumn($column) ?? $this->joinFieldOf($metadata, $column);
            if (null === $field || \in_array($field, $metadata->behaviors->auditExclude, true)) {
                continue;
            }
            // un vecteur du snapshot est sous la forme lue (binaire de MariaDB) : journalisé en texte
            if ($metadata->hasField($field) && 'vector' === $metadata->getField($field)->type && \is_string($old[$column] ?? null)) {
                $vector = $metadata->getField($field);
                $old[$column] = $this->toDatabase($vector, $this->toPhp($vector, $old[$column]));
            }
            $before = \array_key_exists($column, $old) ? self::auditValue($old[$column]) : null;
            $after = \array_key_exists($column, $new) ? self::auditValue($new[$column]) : null;
            if ($metadata->hasField($field) && $metadata->getField($field)->sensitive) {
                [$before, $after] = [\array_key_exists($column, $old) ? AuditLog::MASK : null, \array_key_exists($column, $new) ? AuditLog::MASK : null];
            }
            $changes[$field] = [$before, $after];
        }
        // une mise à jour des seuls champs exclus n'a rien à journaliser
        if ([] === $changes && AuditLog::UPDATE === $action) {
            return;
        }
        $this->auditRecords[] = [$metadata->className, self::auditKey($id), null === $tenant ? null : self::auditKey($tenant), $action, $changes, $this->tenants->isSystem()];
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function joinFieldOf(ClassMetadata $metadata, string $column): ?string
    {
        foreach ($metadata->getAssociations() as $association) {
            if (null !== $association->joinColumn && 0 === strcasecmp($association->joinColumn, $column)) {
                return $association->fieldName;
            }
        }

        return null;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function snapshotTenant(ClassMetadata $metadata, int $oid): mixed
    {
        return null === $metadata->tenancy ? null : ($this->snapshots[$oid][$metadata->tenancy->column] ?? null);
    }

    // une valeur de la base, encodable en JSON : un binaire passe en base64
    private static function auditValue(mixed $value): mixed
    {
        return \is_string($value) && !mb_check_encoding($value, 'UTF-8') ? 'base64:' . base64_encode($value) : $value;
    }

    private static function auditKey(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    /**
     * L'ancienne valeur, lue en base avant l'UPDATE, des colonnes paresseuses remplacées sans avoir
     * été lues : le snapshot ne les connaît pas (RG-09.6), le journal d'audit en a besoin (RG-08.10).
     * Une requête par colonne, seulement pour une entité #[Auditable].
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $changes
     *
     * @return array<string, mixed>
     */
    private function unreadOldValues(ClassMetadata $metadata, int $oid, array $changes): array
    {
        $old = [];
        foreach ($metadata->getLazyFields() as $field) {
            if (!\array_key_exists($field->columnName, $changes) || \array_key_exists($field->columnName, $this->snapshots[$oid] ?? [])) {
                continue;
            }
            foreach ($this->persisters->getPersister($metadata)->loadColumn($field->columnName, [$this->idOf($metadata, $oid)]) as [, $value]) {
                $old[$field->columnName] = $value;
            }
        }

        return $old;
    }

    /**
     * Colonnes d'état des #[EmbeddedVector] à écrire (ADR 0018). À l'INSERT ($row), le vecteur est
     * périmé s'il est vide ; un vecteur fourni par l'application est gardé. À l'UPDATE ($changes),
     * le vecteur devient périmé si une source change (il n'est pas touché), et à jour si
     * l'application l'a remplacé seule. Les vecteurs devenus périmés sont notés pour le scheduler.
     *
     * @param ClassMetadata<object>     $metadata
     * @param array<string, mixed>|null $row
     * @param array<string, mixed>|null $changes
     *
     * @return array<string, mixed>
     */
    private function embeddingState(ClassMetadata $metadata, object $entity, ?array $row, ?array $changes): array
    {
        $state = [];
        foreach ($metadata->getEmbeddings() as $embedding) {
            $column = $metadata->getField($embedding->field)->columnName;
            if (null !== $row) {
                $stale = null === ($row[$column] ?? null);
            } elseif ([] !== array_intersect_key($changes ?? [], array_flip(array_map(static fn (string $f): string => $metadata->getField($f)->columnName, $embedding->source)))) {
                $stale = true;
            } elseif (\array_key_exists($column, $changes ?? [])) {
                $stale = false;
            } else {
                continue;
            }
            $state[$embedding->staleColumn] = $this->types->get('boolean')->toDatabase($stale, $this->conversion);
            if (!$stale) {
                // un vecteur de l'application : d'aucun modèle connu
                $state[$embedding->modelColumn] = null;
            } else {
                $this->flushStale[] = [$entity, $embedding->field];
            }
        }

        return $state;
    }

    /**
     * Après le commit du flush : les vecteurs devenus périmés, par classe, champ et tenant, mis de
     * côté pour le scheduler, à qui commit() les remet une fois le flush terminé ; dans une
     * transaction englobante, seulement à sa fin (RG-09.7).
     */
    private function collectStale(): void
    {
        $groups = [];
        foreach ($this->flushStale as [$entity, $field]) {
            $oid = spl_object_id($entity);
            $id = $this->idOf($metadata = $this->metadataOf($entity), $oid);
            if (!\is_int($id) && !\is_string($id)) {
                continue;
            }
            $tenant = self::tenantValue($this->snapshotTenant($metadata, $oid));
            $groups[$metadata->className . "\0" . $field . "\0" . $tenant][] = [$metadata->className, $field, $id, $tenant];
        }
        $this->flushStale = [];
        foreach ($groups as $rows) {
            [$class, $field, , $tenant] = $rows[0];
            $this->pendingStale[] = new StaleEmbeddings($class, $field, array_values(array_unique(array_column($rows, 2))), $tenant);
        }
    }

    /**
     * Remet au scheduler les vecteurs périmés par les flushs validés : à la fin d'un flush hors
     * transaction, ou de EntityManager::wrapInTransaction(). Un lot remis n'est plus en attente,
     * même si le scheduler échoue sur un suivant.
     *
     * @internal
     */
    public function releaseStaleEmbeddings(): void
    {
        while (null !== $stale = array_shift($this->pendingStale)) {
            $this->embeddingScheduler->schedule($stale);
        }
    }

    /**
     * RG-08.13, RG-08.16 : à l'INSERT, l'heure et l'auteur des champs encore vides ; une valeur
     * posée par l'application (reprise de données) est gardée (ADR 0015).
     *
     * @param ClassMetadata<object>                           $metadata
     * @param list<array{object, \ReflectionProperty, mixed}> $written
     */
    private function stampInsert(ClassMetadata $metadata, object $entity, array &$written): void
    {
        $behaviors = $metadata->behaviors;
        if (!$behaviors->isTimestampable() && !$behaviors->isBlameable()) {
            return;
        }
        $now = $this->now();
        $actor = $this->actors?->getActor();
        foreach ([[$behaviors->createdAt, $now], [$behaviors->updatedAt, $now], [$behaviors->createdBy, $actor], [$behaviors->updatedBy, $actor]] as [$field, $value]) {
            if (null !== $field && null === $this->read($entity, $metadata->className, $field)) {
                $this->write($entity, $field, $value, $written);
            }
        }
    }

    /**
     * RG-08.13 : updatedAt et updatedBy ne changent que si un autre champ a changé.
     *
     * @param ClassMetadata<object>                           $metadata
     * @param array<string, mixed>                            $changes
     * @param list<array{object, \ReflectionProperty, mixed}> $written
     *
     * @return bool vrai si l'entité a été modifiée : le changeset est à recalculer
     */
    private function stampUpdate(ClassMetadata $metadata, object $entity, array $changes, array &$written): bool
    {
        $fields = $metadata->behaviors->updateFields();
        if ([] === $fields) {
            return false;
        }
        $own = array_map(static fn (string $field): string => $metadata->getField($field)->columnName, $fields);
        if ([] === array_diff(array_keys($changes), $own)) {
            return false;
        }
        if (null !== $metadata->behaviors->updatedAt) {
            $this->write($entity, $metadata->behaviors->updatedAt, $this->now(), $written);
        }
        if (null !== $metadata->behaviors->updatedBy) {
            $this->write($entity, $metadata->behaviors->updatedBy, $this->actors?->getActor(), $written);
        }

        return true;
    }

    // RG-08.13 : en UTC, quelle que soit l'horloge fournie
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * RG-08.3, RG-08.4 : la colonne du tenant d'une ligne à insérer, depuis le TenantContext ;
     * une propriété du tenant est remplie. En mode système, seule une propriété remplie, ou une
     * entité global (ligne partagée, NULL), donne le tenant (ADR 0014).
     *
     * @param ClassMetadata<object>                           $metadata
     * @param array<string, mixed>                            $row
     * @param list<array{object, \ReflectionProperty, mixed}> $written
     *
     * @return array<string, mixed>
     *
     * @throws MissingTenantException
     * @throws TenantMismatchException
     */
    private function withTenant(ClassMetadata $metadata, object $entity, array $row, array &$written): array
    {
        $tenancy = $metadata->tenancy;
        if (null === $tenancy) {
            return $row;
        }
        $field = $this->tenantField($metadata);
        $given = $row[$tenancy->column] ?? null;

        if ($this->tenants->isSystem()) {
            if (null === $given && !$tenancy->global) {
                throw MissingTenantException::inSystemMode($metadata->className);
            }
            $value = $given;
        } else {
            $current = $this->tenants->getTenantId() ?? throw MissingTenantException::forEntity($metadata->className);
            $value = $this->toDatabase($field, $current);
            if (null !== $given && $given !== $value) {
                throw TenantMismatchException::forEntity($entity, (string) $tenancy->field, $current, $this->toPhp($field, $given));
            }
        }

        if (null !== $tenancy->field) {
            $this->write($entity, $tenancy->field, $this->toPhp($field, $value), $written);
        } else {
            $this->insertedTenants[spl_object_id($entity)] = $value;
        }
        $row[$tenancy->column] = $value;

        return $row;
    }

    /**
     * Le tenant d'une ligne ne change jamais par l'ORM, même en mode système (ADR 0014).
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $changes
     *
     * @throws TenantMismatchException
     */
    private function assertTenantUnchanged(ClassMetadata $metadata, int $oid, array $changes): void
    {
        $tenancy = $metadata->tenancy;
        if (null === $tenancy?->field || !\array_key_exists($tenancy->column, $changes)) {
            return;
        }
        $field = $this->tenantField($metadata);

        throw TenantMismatchException::changed($this->entities[$oid] ?? throw new \LogicException('Unknown entity.'), $tenancy->field, $this->toPhp($field, $this->snapshots[$oid][$tenancy->column] ?? null), $this->toPhp($field, $changes[$tenancy->column]));
    }

    /**
     * La colonne du tenant que ne porte aucun champ, telle que lue, pour le snapshot : l'UoW
     * connaît ainsi le tenant de chaque entité chargée, même en mode système (RG-08.6). Gardée
     * brute : elle n'est normalisée qu'à la comparaison (tenantOf), bien plus rare qu'une lecture.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row      ligne de la base
     *
     * @return array<string, mixed>
     */
    private function tenantColumn(ClassMetadata $metadata, array $row): array
    {
        $tenancy = $metadata->tenancy;
        if (null === $tenancy || null !== $tenancy->field || !\array_key_exists($tenancy->column, $row)) {
            return [];
        }

        return [$tenancy->column => $row[$tenancy->column]];
    }

    /**
     * Tenant d'une entité #[Tenanted], en valeur de la base : celui qu'elle aura à l'INSERT, ou
     * celui de son snapshot ; inconnu (false) sans tenant à poser, ou pour une entité non suivie.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array{bool, mixed}
     */
    private function tenantOf(ClassMetadata $metadata, object $entity): array
    {
        $tenancy = $metadata->tenancy ?? throw new \LogicException(\sprintf('%s is not #[Tenanted].', $metadata->className));
        $field = $this->tenantField($metadata);
        $oid = spl_object_id($entity);
        if (isset($this->insertions[$oid])) {
            $given = null === $tenancy->field ? null : $this->read($entity, $metadata->className, $tenancy->field);
            if (null !== $given) {
                return [true, $this->toDatabase($field, $given)];
            }
            if ($this->tenants->isSystem()) {
                return [$tenancy->global, null];
            }
            $current = $this->tenants->getTenantId();

            return null === $current ? [false, null] : [true, $this->toDatabase($field, $current)];
        }
        if (isset($this->snapshots[$oid]) && \array_key_exists($tenancy->column, $this->snapshots[$oid])) {
            $raw = $this->snapshots[$oid][$tenancy->column];

            // la colonne que ne porte aucun champ est gardée brute (tenantColumn)
            return [true, null === $raw || null !== $tenancy->field ? $raw : $this->toDatabase($field, $this->toPhp($field, $raw))];
        }

        return [false, null];
    }

    /**
     * RG-08.6 : une relation entre deux entités #[Tenanted] de tenants différents est refusée,
     * avant la transaction : relations ToOne et ManyToMany possédées des entités à insérer,
     * clés étrangères modifiées, éléments ajoutés à une collection (ADR 0014).
     *
     * @param array<int, array<string, mixed>>                                       $updates
     * @param list<array{int, AssociationMapping, list<object>, list<object>, bool}> $collections
     *
     * @throws CrossTenantRelationException
     * @throws EntityNotFoundException      cible référencée par getReference() hors du tenant
     */
    private function assertSameTenants(array $updates, array $collections): void
    {
        foreach ($this->insertions as $entity) {
            $metadata = $this->metadataOf($entity);
            if (null === $metadata->tenancy) {
                continue;
            }
            foreach ($metadata->getAssociations() as $association) {
                if (!$association->isOwningSide()) {
                    continue;
                }
                $value = $this->read($entity, $metadata->className, $association->fieldName);
                $targets = $association->isToOne() ? [$value] : ($value instanceof Collection ? $value->toArray() : []);
                foreach ($targets as $target) {
                    $this->assertSameTenant($metadata, $entity, $association, $target);
                }
            }
        }
        foreach ($updates as $oid => $changes) {
            $entity = $this->entities[$oid] ?? null;
            $metadata = null === $entity ? null : $this->metadataOf($entity);
            if (null === $entity || null === $metadata?->tenancy) {
                continue;
            }
            foreach ($metadata->getAssociations() as $association) {
                if ($association->isToOne() && $association->isOwningSide() && \array_key_exists((string) $association->joinColumn, $changes)) {
                    $this->assertSameTenant($metadata, $entity, $association, $this->read($entity, $metadata->className, $association->fieldName));
                }
            }
        }
        foreach ($collections as [$oid, $association, $added]) {
            $owner = $this->entities[$oid] ?? null;
            if (null === $owner || AssociationType::ManyToMany !== $association->type || !$association->isOwningSide() || isset($this->insertions[$oid])) {
                continue;
            }
            $metadata = $this->metadataOf($owner);
            if (null === $metadata->tenancy) {
                continue;
            }
            foreach ($added as $target) {
                $this->assertSameTenant($metadata, $owner, $association, $target);
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @throws CrossTenantRelationException
     * @throws EntityNotFoundException
     */
    private function assertSameTenant(ClassMetadata $metadata, object $entity, AssociationMapping $association, mixed $target): void
    {
        if (!\is_object($target)) {
            return;
        }
        $targetMetadata = $this->metadataOf($target);
        $targetTenancy = $targetMetadata->tenancy;
        if (null === $targetTenancy) {
            return;
        }
        // une référence jamais chargée : chargée ici, filtrée par le tenant courant (RG-04.8)
        if ($this->lazy->isUninitialized($target)) {
            $this->lazy->initialize($target);
        }
        [$known, $targetTenant] = $this->tenantOf($targetMetadata, $target);
        if (!$known || (null === $targetTenant && $targetTenancy->global)) {
            return;
        }
        [$known, $entityTenant] = $this->tenantOf($metadata, $entity);
        if ($known && $entityTenant !== $targetTenant) {
            throw CrossTenantRelationException::between($entity, $association->fieldName, $target, self::tenantValue($this->toPhp($this->tenantField($metadata), $entityTenant)), self::tenantValue($this->toPhp($this->tenantField($targetMetadata), $targetTenant)));
        }
    }

    private static function tenantValue(mixed $tenant): int|string|null
    {
        return \is_int($tenant) || \is_string($tenant) ? $tenant : null;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function tenantField(ClassMetadata $metadata): FieldMapping
    {
        $tenancy = $metadata->tenancy ?? throw new \LogicException(\sprintf('%s is not #[Tenanted].', $metadata->className));

        return $this->tenantFields[$metadata->className] ??= null !== $tenancy->field
            ? $metadata->getField($tenancy->field)
            : new FieldMapping('tenant', $tenancy->column, $tenancy->type, $tenancy->global);
    }

    /**
     * @param list<array{object, \ReflectionProperty, mixed}> $written
     */
    private function write(object $entity, string $field, mixed $value, array &$written): void
    {
        $property = $this->property($entity::class, $field);
        $written[] = [$entity, $property, $property->isInitialized($entity) ? $property->getValue($entity) : null];
        $property->setValue($entity, $value);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function idOf(ClassMetadata $metadata, int $oid): mixed
    {
        return $this->snapshots[$oid][$metadata->getIdField()->columnName] ?? null;
    }

    /**
     * Entité gérée d'une ligne lue : celle de l'identity map si elle y est (jamais réécrite),
     * sinon une entité hydratée, suivie avec son snapshot.
     *
     * @template T of object
     *
     * @param ClassMetadata<T>     $metadata
     * @param array<string, mixed> $row
     *
     * @return T
     */
    private function register(ClassMetadata $metadata, array $row, ?Cohort $cohort = null, bool $vectors = false, ?string $key = null): object
    {
        $idField = $metadata->getIdField();
        $key ??= self::key($this->toPhp($idField, $row[$idField->columnName] ?? null));
        if (isset($this->identityMap[$metadata->className][$key])) {
            /** @var T $known */
            $known = $this->identityMap[$metadata->className][$key];
            // une référence encore à charger : la ligne lue la charge, sans nouvelle requête ;
            // ses vecteurs restent paresseux, comme toute ligne en attente (RG-09.6)
            if ($this->lazy->isUninitialized($known)) {
                $this->prefetched[$metadata->className][$key] = [$vectors ? self::lazyFlags($metadata, $row) : $row, $cohort ?? new Cohort()];
                $this->lazy->initialize($known);
            }

            return $known;
        }

        $nulls = $vectors ? [] : $this->takeLazy($metadata, $row);
        $values = $this->hydrator->toPhpRow($metadata, $row);
        $entity = $this->hydrator->fromPhpRow($metadata, $values);
        $this->placeLazy($metadata, $entity, $nulls);
        $oid = spl_object_id($entity);
        $this->track($entity, EntityState::Managed);
        $this->identityMap[$metadata->className][$key] = $entity;
        $this->keys[$oid] = $key;
        if ($this->hasRelations($metadata)) {
            if (null !== $cohort) {
                $this->batch->join($cohort, $entity);
            }
            $this->hydrateRelations($metadata, $entity, $row, refresh: false);
            $this->hydrateCollections($metadata, $entity, refresh: false);
            $this->snapshots[$oid] = $this->snapshotOf($metadata, $entity, $values, $row) + $this->foreignKeys($metadata, $entity) + $this->tenantColumn($metadata, $row);
        } else {
            $this->snapshots[$oid] = $this->snapshotOf($metadata, $entity, $values, $row) + $this->tenantColumn($metadata, $row);
        }
        $this->postLoad($metadata, $entity);

        return $entity;
    }

    /**
     * Snapshot d'une entité qu'on vient d'hydrater : ses valeurs PHP par colonne sont celles que
     * databaseRow() relirait, sans relire les propriétés. Une ligne incomplète (colonne absente,
     * propriété laissée à sa valeur par défaut) passe par databaseRow().
     *
     * Un vecteur garde la valeur que la base a rendue (binaire de MariaDB, texte ailleurs) : la
     * réencoder coûterait un json_encode de chaque composante par entité hydratée.
     * databaseRow() la reprend tant que le vecteur PHP n'a pas changé.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $values   valeurs PHP, par colonne
     * @param array<string, mixed>  $row      la ligne lue
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(ClassMetadata $metadata, object $entity, array $values, array $row = []): array
    {
        if (\count($values) !== \count($metadata->getColumnTypes())) {
            return $this->databaseRow($metadata, $entity);
        }

        $snapshot = [];
        foreach ($values as $column => $value) {
            $field = $this->field($metadata, $column);
            $snapshot[$column] = 'vector' === $field->type && \is_string($row[$column] ?? null) ? $row[$column] : $this->toDatabase($field, $value);
        }

        return $snapshot;
    }

    /**
     * Retire d'une ligne lue les colonnes paresseuses, qui n'y portent que leur nullité (1 : NULL,
     * 0 : une valeur) : champ → NULL ou non (RG-09.6).
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     *
     * @return array<string, bool>
     */
    private function takeLazy(ClassMetadata $metadata, array &$row): array
    {
        $nulls = [];
        foreach ($metadata->getLazyFields() as $field) {
            if (\array_key_exists($field->columnName, $row)) {
                $flag = $row[$field->columnName];
                $nulls[$field->fieldName] = true === $flag || 1 === $flag || '1' === $flag;
                unset($row[$field->columnName]);
            }
        }

        return $nulls;
    }

    /**
     * Une ligne lue avec ses vecteurs, ramenée à la forme d'une ligne ordinaire (nullité seule).
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     *
     * @return array<string, mixed>
     */
    private static function lazyFlags(ClassMetadata $metadata, array $row): array
    {
        foreach ($metadata->getLazyFields() as $field) {
            if (\array_key_exists($field->columnName, $row)) {
                $row[$field->columnName] = null === $row[$field->columnName] ? 1 : 0;
            }
        }

        return $row;
    }

    /**
     * Chaque champ paresseux reçoit null ou un ghost de Vector, chargé au premier accès avec
     * ceux des autres entités en attente (RG-09.6). Une propriété readonly déjà écrite ne change
     * pas (RG-02.6).
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, bool>   $nulls    champ → NULL ou non
     */
    private function placeLazy(ClassMetadata $metadata, object $entity, array $nulls): void
    {
        foreach ($nulls as $fieldName => $isNull) {
            $property = $this->property($metadata->className, $fieldName);
            if ($property->isReadOnly() && $property->isInitialized($entity)) {
                continue;
            }
            $lazyKey = $metadata->className . '::' . $fieldName;
            $oid = spl_object_id($entity);
            unset($this->lazyPending[$lazyKey][$oid], $this->lazyLoaded[$lazyKey][$oid]);
            if ($isNull) {
                $property->setValue($entity, null);
                continue;
            }
            $ghost = new \ReflectionClass(Vector::class)->newLazyGhost(function (Vector $ghost) use ($metadata, $entity, $fieldName): void {
                $this->loadLazy($metadata, $entity, $fieldName, $ghost);
            });
            $property->setValue($entity, $ghost);
            $this->lazyPending[$lazyKey][$oid] = [$entity, $ghost];
        }
    }

    /**
     * Charge un vecteur paresseux, avec ceux des autres entités de la même classe encore en
     * attente, par lots de la taille du batch fetching (RG-04.11) : une requête IN. Le snapshot
     * reçoit la valeur lue, pour qu'un vecteur seulement lu ne soit pas vu comme changé.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @throws EntityNotFoundException     la ligne n'existe plus
     * @throws InvalidEntityStateException la colonne est devenue NULL depuis la lecture
     */
    private function loadLazy(ClassMetadata $metadata, object $entity, string $fieldName, Vector $ghost): void
    {
        $lazyKey = $metadata->className . '::' . $fieldName;
        $field = $metadata->getField($fieldName);
        $idField = $metadata->getIdField();
        $oid = spl_object_id($entity);
        $id = $this->property($metadata->className, $idField->fieldName)->getValue($entity);

        if (!isset($this->lazyLoaded[$lazyKey][$oid])) {
            $batch = [$oid => $entity];
            foreach ($this->lazyPending[$lazyKey] ?? [] as $other => [$member, $pending]) {
                if (\count($batch) >= $this->batch->batchSize) {
                    break;
                }
                // une entité dont le vecteur a été remplacé n'est plus en attente
                if ($other !== $oid && $this->read($member, $metadata->className, $fieldName) === $pending && new \ReflectionClass($pending)->isUninitializedLazyObject($pending)) {
                    $batch[$other] = $member;
                }
            }
            $ids = [];
            foreach ($batch as $member) {
                $ids[] = $this->toDatabase($idField, $this->property($metadata->className, $idField->fieldName)->getValue($member));
            }
            if (\count($ids) > 1) {
                $this->lifecycle->dispatch(new BatchLoadedEvent($metadata->className, $fieldName, \count($ids), $this));
            }
            $values = [];
            foreach ($this->persisters->getPersister($metadata)->loadColumn($field->columnName, $ids) as [$rowId, $value]) {
                $values[self::key($this->toPhp($idField, $rowId))] = [$this->toPhp($field, $value), $value];
            }
            foreach ($batch as $other => $member) {
                $memberKey = self::key($this->property($metadata->className, $idField->fieldName)->getValue($member));
                $this->lazyLoaded[$lazyKey][$other] = [\array_key_exists($memberKey, $values), ...$values[$memberKey] ?? [null, null]];
                unset($this->lazyPending[$lazyKey][$other]);
            }
        }

        [$found, $value, $stored] = $this->lazyLoaded[$lazyKey][$oid];
        unset($this->lazyLoaded[$lazyKey][$oid], $this->lazyPending[$lazyKey][$oid]);
        if (!$found) {
            throw EntityNotFoundException::forId($metadata->className, $id);
        }
        if (!\is_array($value)) {
            throw InvalidEntityStateException::lazyValueGone($metadata->className, $fieldName, $id);
        }
        // le constructeur sur le ghost : la forme d'initialisation des objets paresseux de PHP
        new \ReflectionMethod(Vector::class, '__construct')->invoke($ghost, $value);
        if (($this->entities[$oid] ?? null) === $entity && isset($this->snapshots[$oid])) {
            // la valeur lue, comme à l'hydratation (snapshotOf)
            $this->snapshots[$oid][$field->columnName] = \is_string($stored) ? $stored : $this->toDatabase($field, $value);
        }
    }

    /**
     * Relations ToOne d'une ligne lue : une FK devient une référence de l'identity map
     * (RG-04.7) ; le côté inverse d'un OneToOne est relu par la FK du côté propriétaire. Au
     * refresh, une relation readonly déjà écrite n'est pas touchée (RG-02.6).
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row      ligne telle que la base la renvoie
     */
    private function hydrateRelations(ClassMetadata $metadata, object $entity, array $row, bool $refresh): void
    {
        foreach ($this->owningToOne($metadata) as [$association, $target]) {
            $property = $this->property($metadata->className, $association->fieldName);
            if (!\array_key_exists((string) $association->joinColumn, $row) || ($refresh && $property->isReadOnly() && $property->isInitialized($entity))) {
                continue;
            }
            $id = $row[(string) $association->joinColumn];
            $reference = null === $id ? null : $this->getReference($target->className, $this->toPhp($target->getIdField(), $id));
            $property->setValue($entity, $reference);
            if (null !== $reference && $this->lazy->isUninitialized($reference)) {
                $this->batch->referenced($reference, $entity, $association);
            }
        }
    }

    /**
     * Charge un ghost (RG-04.7, RG-04.8). Sa ligne a pu être chargée avec celles de sa cohorte ;
     * sinon, les autres ghosts de la même relation dans la cohorte d'origine sont chargés avec
     * lui en une requête IN (RG-04.10), puis hydratés ensemble : ils forment une cohorte.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @throws EntityNotFoundException
     */
    private function loadGhost(ClassMetadata $metadata, object $ghost, string $key, mixed $id): void
    {
        [$row, $cohort] = $this->prefetched[$metadata->className][$key] ?? [null, null];
        unset($this->prefetched[$metadata->className][$key]);
        $leader = null === $cohort;
        $batch = [];

        if (null === $row) {
            $idField = $metadata->getIdField();
            $batch = \array_slice($this->batch->targetsWith(
                $ghost,
                fn (object $member, AssociationMapping $association): mixed => $this->read($member, $member::class, $association->fieldName),
                fn (object $other): bool => $other instanceof $metadata->className && $this->lazy->isUninitialized($other) && !isset($this->prefetched[$metadata->className][$this->keys[spl_object_id($other)] ?? '']),
            ), 1);
            $cohort = new Cohort();
            if ([] === $batch) {
                $row = $this->persisters->getPersister($metadata)->loadById($this->toDatabase($idField, $id), FilterOperation::Load);
            } else {
                $ids = [$id, ...array_map(fn (object $other): mixed => $this->property($metadata->className, $metadata->id->fieldName)->getValue($other), $batch)];
                $this->lifecycle->dispatch(new BatchLoadedEvent($metadata->className, null, \count($ids), $this));
                foreach ($this->persisters->getPersister($metadata)->loadBy([$idField->fieldName => $ids], operation: FilterOperation::Load) as $loaded) {
                    $this->prefetched[$metadata->className][self::key($this->toPhp($idField, $loaded[$idField->columnName] ?? null))] = [$loaded, $cohort];
                }
                [$row] = $this->prefetched[$metadata->className][$key] ?? [null];
                unset($this->prefetched[$metadata->className][$key]);
            }
        }
        if (null === $row) {
            throw EntityNotFoundException::forId($metadata->className, $id);
        }

        $nulls = $this->takeLazy($metadata, $row);
        $this->hydrator->refresh($ghost, $metadata, $row);
        $this->placeLazy($metadata, $ghost, $nulls);
        // une référence détachée se charge encore, mais n'est plus suivie
        $oid = spl_object_id($ghost);
        $tracked = ($this->entities[$oid] ?? null) === $ghost;
        if ($tracked && $this->hasRelations($metadata)) {
            $this->batch->join($cohort ?? new Cohort(), $ghost);
        }
        $this->hydrateRelations($metadata, $ghost, $row, refresh: true);
        $this->hydrateCollections($metadata, $ghost, refresh: true);

        if (!$leader) {
            // hydraté avec son lot : celui qui l'a chargé complète la cohorte
            $this->finishGhost($metadata, $ghost, $tracked, $row);

            return;
        }
        // les autres ghosts du lot, depuis leur ligne préchargée
        foreach ($batch as $other) {
            if ($this->lazy->isUninitialized($other) && isset($this->prefetched[$metadata->className][$this->keys[spl_object_id($other)] ?? ''])) {
                $this->lazy->initialize($other);
            }
        }
        $this->completeCohort($metadata, null === $cohort || [] === $batch ? [$ghost] : $cohort->members(), refresh: true);
        $this->finishGhost($metadata, $ghost, $tracked, $row);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row      ligne lue du ghost
     */
    private function finishGhost(ClassMetadata $metadata, object $ghost, bool $tracked, array $row): void
    {
        if ($tracked) {
            $this->snapshots[spl_object_id($ghost)] = $this->databaseRow($metadata, $ghost) + $this->tenantColumn($metadata, $row);
        }
        $this->postLoad($metadata, $ghost);
    }

    /**
     * Fin d'hydratation d'une cohorte : le côté inverse de ses OneToOne en une requête IN par
     * lot, puis ses relations Fetch::Eager, par lot. Les relations chargées par une jointure
     * sont laissées au QueryBuilder typé.
     *
     * @param ClassMetadata<object> $metadata
     * @param list<object>          $members
     * @param list<string>          $fetched
     */
    private function completeCohort(ClassMetadata $metadata, array $members, bool $refresh = false, array $fetched = []): void
    {
        if ([] === $members || !$this->hasRelations($metadata)) {
            return;
        }
        $associations = array_filter($metadata->getAssociations(), static fn (AssociationMapping $a): bool => !\in_array($a->fieldName, $fetched, true));
        foreach ($associations as $association) {
            if ($association->isToOne() && !$association->isOwningSide()) {
                $this->loadInverseToOne($metadata, $association, $members, $refresh);
            }
        }
        foreach ($associations as $association) {
            if (Fetch::Eager !== $association->fetch) {
                continue;
            }
            foreach ($members as $member) {
                $value = $this->read($member, $metadata->className, $association->fieldName);
                if ($value instanceof Collection) {
                    $value->toArray();
                } elseif (\is_object($value)) {
                    $this->lazy->initialize($value);
                }
            }
        }
    }

    /**
     * Côté inverse d'un OneToOne pour des entités hydratées ensemble : une requête sur la FK du
     * côté propriétaire, par lot. Au refresh, une relation readonly déjà écrite n'est pas touchée.
     *
     * @param ClassMetadata<object> $metadata
     * @param list<object>          $members
     */
    private function loadInverseToOne(ClassMetadata $metadata, AssociationMapping $association, array $members, bool $refresh): void
    {
        $property = $this->property($metadata->className, $association->fieldName);
        $members = array_values(array_filter($members, static fn (object $m): bool => !($refresh && $property->isReadOnly() && $property->isInitialized($m))));
        $mappedBy = (string) $association->mappedBy;

        $targets = [];
        foreach (array_chunk($members, $association->batch ? max(1, $association->batchSize ?? $this->batch->batchSize) : 1) as $chunk) {
            if (\count($chunk) > 1) {
                $this->lifecycle->dispatch(new BatchLoadedEvent($metadata->className, $association->fieldName, \count($chunk), $this));
            }
            foreach ($this->findBy($association->targetEntity, [$mappedBy => $chunk]) as $target) {
                $owner = $this->read($target, $target::class, $mappedBy);
                if (\is_object($owner)) {
                    $targets[spl_object_id($owner)] = $target;
                }
            }
        }
        foreach ($members as $member) {
            $value = $targets[spl_object_id($member)] ?? null;
            $property->setValue($member, $value);
            if ($association->orphanRemoval) {
                $this->inverseSnapshots[spl_object_id($member)][$association->fieldName] = $value;
            }
        }
    }

    /**
     * Critères sur une relation ToOne possédée : une entité est remplacée par son identifiant.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $criteria
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException entité sans identifiant
     */
    private function criteria(ClassMetadata $metadata, array $criteria): array
    {
        foreach ($this->owningToOne($metadata) as [$association, $target]) {
            $field = $association->fieldName;
            if (!\array_key_exists($field, $criteria)) {
                continue;
            }
            $toId = function (mixed $value) use ($metadata, $field, $target): mixed {
                if (!\is_object($value)) {
                    return $value;
                }

                return $this->property($target->className, $target->id->fieldName)->getValue($value)
                    ?? throw InvalidArgumentException::unpersistedCriteria($metadata->className, $field);
            };
            $criteria[$field] = \is_array($criteria[$field]) ? array_map($toId, $criteria[$field]) : $toId($criteria[$field]);
        }

        return $criteria;
    }

    /**
     * RG-04.2 : une entité NEW atteinte par une relation ToOne d'une entité gérée est persistée
     * si la relation a cascade: persist, récursivement ; sinon le flush est refusé.
     *
     * @throws UnpersistedRelationException
     * @throws InvalidEntityStateException  cible DETACHED
     */
    private function persistRelations(): void
    {
        foreach ($this->entities as $oid => $entity) {
            $metadata = $this->metadataOf($entity);
            if (EntityState::Managed === ($this->states[$oid] ?? null) && $this->hasRelations($metadata) && !$this->lazy->isUninitialized($entity)) {
                $this->cascadePersist($entity, '');
            }
        }
    }

    private function cascadePersist(object $entity, string $path): void
    {
        $metadata = $this->metadataOf($entity);
        $reached = [];
        // relations ToOne, des deux côtés
        foreach ($metadata->getAssociations() as $association) {
            if ($association->isToOne()) {
                $target = $this->read($entity, $metadata->className, $association->fieldName);
                if (\is_object($target)) {
                    $reached[] = [$association, $target];
                }
            }
        }
        foreach ($this->collectionsOf($metadata) as $association) {
            $collection = $this->read($entity, $metadata->className, $association->fieldName);
            // une collection non chargée n'a rien de nouveau
            if (!$collection instanceof Collection || ($collection instanceof PersistentCollection && !$collection->isInitialized())) {
                continue;
            }
            foreach ($collection->toArray() as $element) {
                $reached[] = [$association, $element];
            }
        }

        foreach ($reached as [$association, $target]) {
            $step = ('' === $path ? '' : $path . ' → ') . $metadata->className . '::$' . $association->fieldName;

            switch ($this->getEntityState($target)) {
                case EntityState::Detached:
                    throw InvalidEntityStateException::detachedReference($target, $metadata->className, $association->fieldName);
                case EntityState::New:
                    if (!\in_array(Cascade::Persist, $association->cascade, true)) {
                        throw UnpersistedRelationException::through($target, $step);
                    }
                    $this->persist($target);
                    $this->cascadePersist($target, $step);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Collections d'une entité lue : une PersistentCollection non chargée par relation. Au
     * refresh, une collection readonly n'est pas remplacée mais remise à l'état non chargé.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function hydrateCollections(ClassMetadata $metadata, object $entity, bool $refresh): void
    {
        $oid = spl_object_id($entity);
        foreach ($this->collectionsOf($metadata) as $association) {
            $field = $association->fieldName;
            $property = $this->property($metadata->className, $field);
            unset($this->collectionSnapshots[$oid][$field]);
            if ($refresh && $property->isReadOnly() && $property->isInitialized($entity)) {
                $current = $property->getValue($entity);
                if ($current instanceof PersistentCollection) {
                    $current->reset();
                }
                continue;
            }

            $collection = new PersistentCollection(
                fn (): array => $this->loadCollection($metadata, $entity, $association),
                Fetch::ExtraLazy === $association->fetch ? fn (): int => $this->countCollection($metadata, $entity, $association) : null,
            );
            $property->setValue($entity, $collection);
            $this->collectionInstances[$oid][$field] = $collection;
        }
    }

    /**
     * Éléments d'une collection : par la FK de la cible (OneToMany) ou par la table pivot
     * (ManyToMany), dans l'ordre orderBy, indexés par indexBy.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array<array-key, object>
     */
    private function loadCollection(ClassMetadata $metadata, object $owner, AssociationMapping $association): array
    {
        $target = $this->metadata->getMetadataFor($association->targetEntity);
        $order = $association->orderBy;
        $field = $association->fieldName;
        // RG-04.12 : la même collection des autres membres de la cohorte, en une requête
        $owners = $this->batch->ownersWith($owner, $association, function (object $member) use ($metadata, $field): bool {
            $collection = $this->read($member, $metadata->className, $field);

            return $collection instanceof PersistentCollection && !$collection->isInitialized();
        });

        if (\count($owners) > 1) {
            $this->lifecycle->dispatch(new BatchLoadedEvent($metadata->className, $field, \count($owners), $this));
        }

        /** @var array<int, list<object>> éléments par propriétaire, dans l'ordre orderBy */
        $groups = [];
        if (AssociationType::OneToMany === $association->type) {
            $mappedBy = (string) $association->mappedBy;
            foreach ($this->findBy($target->className, [$mappedBy => 1 === \count($owners) ? $owner : $owners], $order) as $element) {
                $parent = $this->read($element, $target->className, $mappedBy);
                if (\is_object($parent)) {
                    $groups[spl_object_id($parent)][] = $element;
                }
            }
        } else {
            [$table, $ownerColumn, $targetColumn] = $this->joinTableOf($association);
            $byId = [];
            foreach ($owners as $member) {
                $byId[self::key($this->toPhp($metadata->getIdField(), $this->currentId($metadata, $member)))] = spl_object_id($member);
            }
            $cohort = new Cohort();
            $rows = $this->persisters->getPersister($target)->loadByJoinTable($table, $ownerColumn, $targetColumn, array_map(fn (object $m): mixed => $this->currentId($metadata, $m), $owners), $metadata->getIdField()->type, $order);
            foreach ($rows as [$ownerId, $row]) {
                $groups[$byId[self::key($this->toPhp($metadata->getIdField(), $ownerId))] ?? 0][] = $this->register($target, $row, $cohort);
            }
            $this->completeCohort($target, $cohort->members());
        }

        foreach ($owners as $member) {
            $elements = $groups[spl_object_id($member)] ?? [];
            $oid = spl_object_id($member);
            if (($this->entities[$oid] ?? null) === $member) {
                $this->collectionSnapshots[$oid][$field] = $elements;
            }
            if ($member !== $owner) {
                $collection = $this->read($member, $metadata->className, $field);
                if ($collection instanceof PersistentCollection) {
                    $collection->initializeWith($this->indexed($elements, $association, $target));
                }
            }
        }

        return $this->indexed($groups[spl_object_id($owner)] ?? [], $association, $target);
    }

    /**
     * Clés d'une collection : indexBy, ou une liste.
     *
     * @param list<object>          $elements
     * @param ClassMetadata<object> $target
     *
     * @return array<array-key, object>
     */
    private function indexed(array $elements, AssociationMapping $association, ClassMetadata $target): array
    {
        if (null === $association->indexBy) {
            return $elements;
        }

        $indexed = [];
        foreach ($elements as $element) {
            $key = $this->read($element, $target->className, $association->indexBy);
            $key = $key instanceof \BackedEnum ? $key->value : $key;
            if (\is_int($key) || \is_string($key)) {
                $indexed[$key] = $element;
            } else {
                $indexed[] = $element;
            }
        }

        return $indexed;
    }

    /**
     * EXTRA_LAZY : nombre d'éléments sans charger la collection.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function countCollection(ClassMetadata $metadata, object $owner, AssociationMapping $association): int
    {
        if (AssociationType::OneToMany === $association->type) {
            return $this->count($association->targetEntity, [(string) $association->mappedBy => $owner]);
        }
        [$table, $ownerColumn, $targetColumn] = $this->joinTableOf($association);

        // compté par la cible : ses filtres s'appliquent (RG-08.2)
        return $this->persisters->getPersister($this->metadata->getMetadataFor($association->targetEntity))->countJoinTable($table, $ownerColumn, $targetColumn, $this->currentId($metadata, $owner), $metadata->getIdField()->type);
    }

    /**
     * Table pivot d'une ManyToMany vue de ce côté : table, colonne de cette entité, colonne de la cible.
     *
     * @return array{string, string, string}
     */
    private function joinTableOf(AssociationMapping $association): array
    {
        if ($association->isOwningSide()) {
            $join = $association->joinTable ?? throw new \LogicException('A ManyToMany owning side has a join table.');

            return [$join->name, $join->joinColumn, $join->inverseJoinColumn];
        }
        $owning = $this->metadata->getMetadataFor($association->targetEntity)->getAssociation((string) $association->mappedBy);
        $join = $owning->joinTable ?? throw new \LogicException('A ManyToMany owning side has a join table.');

        return [$join->name, $join->inverseJoinColumn, $join->joinColumn];
    }

    /**
     * Différences des collections des entités gérées, par identité (ADR 0007). Une collection
     * non chargée n'en a pas ; vidée avant chargement, ou remplacée sans avoir été chargée, elle
     * est réécrite entièrement.
     *
     * @return list<array{int, AssociationMapping, list<object>, list<object>, bool}> entité, relation, ajouts, retraits, réécriture
     */
    private function pendingCollections(): array
    {
        $diffs = [];
        foreach ($this->entities as $oid => $entity) {
            if (EntityState::Managed !== $this->states[$oid]) {
                continue;
            }
            $metadata = $this->metadataOf($entity);
            if ([] === $this->collectionsOf($metadata) || $this->lazy->isUninitialized($entity)) {
                continue;
            }
            foreach ($this->collectionsOf($metadata) as $association) {
                $field = $association->fieldName;
                $collection = $this->read($entity, $metadata->className, $field);
                if (!$collection instanceof Collection || ($collection instanceof PersistentCollection && !$collection->isInitialized())) {
                    continue;
                }

                $original = $this->collectionInstances[$oid][$field] ?? null;
                $rewrite = ($collection instanceof PersistentCollection && $collection->wasClearedUnloaded())
                    || (!isset($this->collectionSnapshots[$oid][$field]) && null !== $original && $original !== $collection);
                $before = $rewrite ? [] : ($this->collectionSnapshots[$oid][$field] ?? []);
                $current = array_values($collection->toArray());

                $added = self::without($current, $before);
                $removed = self::without($before, $current);
                if ($rewrite || [] !== $added || [] !== $removed) {
                    $diffs[] = [$oid, $association, $added, $removed, $rewrite];
                }
            }
        }

        return $diffs;
    }

    /**
     * Lignes pivot des ManyToMany propriétaires : réécriture, retraits puis ajouts. Les autres
     * collections n'écrivent rien (RG-04.1).
     *
     * @param list<array{int, AssociationMapping, list<object>, list<object>, bool}> $collections
     */
    private function executeCollections(array $collections): void
    {
        foreach ($collections as [$oid, $association, $added, $removed, $rewrite]) {
            if (AssociationType::ManyToMany !== $association->type || !$association->isOwningSide()) {
                continue;
            }
            $owner = $this->entities[$oid];
            $metadata = $this->metadataOf($owner);
            $target = $this->metadata->getMetadataFor($association->targetEntity);
            [$table, $ownerColumn, $targetColumn] = $this->joinTableOf($association);
            $types = [$ownerColumn => $metadata->getIdField()->type, $targetColumn => $target->getIdField()->type];
            $persister = $this->persisters->getPersister($metadata);
            $ownerId = $this->currentId($metadata, $owner);

            if ($rewrite) {
                $persister->deleteJoinRows($table, [$ownerColumn => $ownerId], $types);
            }
            foreach ($removed as $element) {
                $persister->deleteJoinRows($table, [$ownerColumn => $ownerId, $targetColumn => $this->currentId($target, $element)], $types);
            }
            foreach ($added as $element) {
                $persister->insertJoinRow($table, [$ownerColumn => $ownerId, $targetColumn => $this->currentId($target, $element)], $types);
            }
        }
    }

    /**
     * Éléments de $elements absents de $others, par identité.
     *
     * @param list<object> $elements
     * @param list<object> $others
     *
     * @return list<object>
     */
    private static function without(array $elements, array $others): array
    {
        $known = [];
        foreach ($others as $other) {
            $known[spl_object_id($other)] = true;
        }

        return array_values(array_filter($elements, static fn (object $e): bool => !isset($known[spl_object_id($e)])));
    }

    /**
     * Relations de collection de la classe (OneToMany, ManyToMany).
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return list<AssociationMapping>
     */
    private function collectionsOf(ClassMetadata $metadata): array
    {
        return $this->collectionAssociations[$metadata->className] ??= array_values(array_filter($metadata->getAssociations(), static fn (AssociationMapping $a): bool => $a->isCollection()));
    }

    /**
     * RG-04.5 : un élément retiré d'une collection orphanRemoval, ou l'ancienne cible d'un
     * OneToOne orphanRemoval, est supprimé par ce flush ; rattaché ailleurs, il est refusé.
     *
     * @throws OrphanReattachedException
     */
    private function removeOrphans(): void
    {
        foreach ($this->entities as $oid => $entity) {
            if (EntityState::Managed !== ($this->states[$oid] ?? null)) {
                continue;
            }
            $metadata = $this->metadataOf($entity);
            if (!$this->hasRelations($metadata) || $this->lazy->isUninitialized($entity)) {
                continue;
            }
            foreach ($metadata->getAssociations() as $association) {
                if (!$association->orphanRemoval) {
                    continue;
                }
                foreach ($this->orphansOf($oid, $entity, $metadata, $association) as $orphan) {
                    if (EntityState::Managed !== $this->getEntityState($orphan)) {
                        continue;
                    }
                    if ($this->isReattached($orphan, $entity, $association)) {
                        throw OrphanReattachedException::reattached($orphan, $metadata->className, $association->fieldName);
                    }
                    $this->remove($orphan);
                }
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<object>
     */
    private function orphansOf(int $oid, object $entity, ClassMetadata $metadata, AssociationMapping $association): array
    {
        $field = $association->fieldName;
        $current = $this->read($entity, $metadata->className, $field);

        if ($association->isCollection()) {
            if (!$current instanceof Collection || ($current instanceof PersistentCollection && !$current->isInitialized())) {
                return [];
            }
            $original = $this->collectionInstances[$oid][$field] ?? null;
            // vidée ou remplacée sans avoir été chargée : ses anciens éléments sont relus
            if (!isset($this->collectionSnapshots[$oid][$field]) && null !== $original && (($current instanceof PersistentCollection && $current->wasClearedUnloaded()) || ($original !== $current && !$original->isInitialized()))) {
                $this->loadCollection($metadata, $entity, $association);
            }

            return self::without($this->collectionSnapshots[$oid][$field] ?? [], array_values($current->toArray()));
        }

        if ($association->isOwningSide()) {
            $target = $this->metadata->getMetadataFor($association->targetEntity);
            $id = $this->snapshots[$oid][(string) $association->joinColumn] ?? null;
            $old = null === $id ? null : $this->getReference($target->className, $this->toPhp($target->getIdField(), $id));
        } else {
            $old = $this->inverseSnapshots[$oid][$field] ?? null;
            $this->inverseSnapshots[$oid][$field] = \is_object($current) ? $current : null;
        }

        return null !== $old && $old !== $current ? [$old] : [];
    }

    // l'orphelin pointe-t-il désormais vers un autre parent ?
    private function isReattached(object $orphan, object $parent, AssociationMapping $association): bool
    {
        if (null !== $association->mappedBy) {
            $now = $this->read($orphan, $orphan::class, $association->mappedBy);

            return \is_object($now) && $now !== $parent;
        }
        // OneToOne propriétaire : une autre entité de la même classe le référence
        foreach ($this->entities as $oid => $entity) {
            if ($entity !== $parent && $entity instanceof $parent && EntityState::Managed === $this->states[$oid] && !$this->lazy->isUninitialized($entity)
                && $this->read($entity, $entity::class, $association->fieldName) === $orphan) {
                return true;
            }
        }

        return false;
    }

    /**
     * RG-04.1 : collection inverse modifiée sans le côté propriétaire. Un côté propriétaire non
     * chargé n'est jamais chargé pour le vérifier.
     *
     * @param list<array{int, AssociationMapping, list<object>, list<object>, bool}> $collections
     */
    private function reportInverseChanges(array $collections): void
    {
        foreach ($collections as [$oid, $association, $added, $removed]) {
            if ($association->isOwningSide()) {
                continue;
            }
            $owner = $this->entities[$oid];
            foreach ($added as $element) {
                if (false === $this->pointsTo($element, $association, $owner)) {
                    $this->lifecycle->dispatch(new InverseSideChangedEvent($owner, $association->fieldName, $element, true, $this));
                }
            }
            if ($association->orphanRemoval) {
                continue;
            }
            foreach ($removed as $element) {
                if (EntityState::Managed === $this->getEntityState($element) && true === $this->pointsTo($element, $association, $owner)) {
                    $this->lifecycle->dispatch(new InverseSideChangedEvent($owner, $association->fieldName, $element, false, $this));
                }
            }
        }
    }

    // le côté propriétaire de $element pointe-t-il vers $owner ? null : inconnu (non chargé)
    private function pointsTo(object $element, AssociationMapping $association, object $owner): ?bool
    {
        $value = $this->read($element, $element::class, (string) $association->mappedBy);
        if (AssociationType::OneToMany === $association->type) {
            return $value === $owner;
        }
        if (!$value instanceof Collection) {
            return false;
        }

        return $value instanceof PersistentCollection && !$value->isInitialized() ? null : $value->contains($owner);
    }

    /**
     * Ordre des INSERT (RG-04.3) : la FK d'une entité vers une autre entité à insérer.
     *
     * @throws CyclicDependencyException
     */
    private function insertionOrder(): CommitOrder
    {
        $calculator = new CommitOrderCalculator();
        foreach ($this->insertions as $oid => $entity) {
            $calculator->addNode($oid, $entity::class);
        }
        foreach ($this->insertions as $oid => $entity) {
            $metadata = $this->metadataOf($entity);
            foreach ($this->owningToOne($metadata) as [$association]) {
                $target = $this->read($entity, $metadata->className, $association->fieldName);
                if (\is_object($target) && isset($this->insertions[spl_object_id($target)])) {
                    $calculator->addDependency($oid, spl_object_id($target), $association->fieldName, $association->nullable);
                }
            }
        }

        return $calculator->sort();
    }

    /**
     * Ordre des DELETE, inverse de celui des INSERT : d'après les FK en base (le snapshot).
     *
     * @throws CyclicDependencyException
     */
    private function deletionOrder(): CommitOrder
    {
        $calculator = new CommitOrderCalculator();
        foreach ($this->deletions as $oid => $entity) {
            $calculator->addNode($oid, $entity::class);
        }
        foreach ($this->deletions as $oid => $entity) {
            foreach ($this->owningToOne($this->metadataOf($entity)) as [$association, $target]) {
                $id = $this->snapshots[$oid][(string) $association->joinColumn] ?? null;
                $referenced = null === $id ? null : ($this->identityMap[$target->className][self::key($this->toPhp($target->getIdField(), $id))] ?? null);
                if (null !== $referenced && isset($this->deletions[spl_object_id($referenced)])) {
                    $calculator->addDependency($oid, spl_object_id($referenced), $association->fieldName, $association->nullable);
                }
            }
        }

        return $calculator->sort();
    }

    /**
     * Relations ToOne possédées de la classe, avec la metadata de leur cible.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return list<array{AssociationMapping, ClassMetadata<object>}>
     */
    private function owningToOne(ClassMetadata $metadata): array
    {
        if (!isset($this->toOne[$metadata->className])) {
            $toOne = [];
            foreach ($metadata->getAssociations() as $association) {
                if ($association->isToOne() && $association->isOwningSide()) {
                    $toOne[] = [$association, $this->metadata->getMetadataFor($association->targetEntity)];
                }
            }
            $this->toOne[$metadata->className] = $toOne;
        }

        return $this->toOne[$metadata->className];
    }

    /**
     * Identifiant d'une entité qu'on vient d'insérer (pas encore de snapshot), en valeur de la base.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function currentId(ClassMetadata $metadata, object $entity): mixed
    {
        return $this->toDatabase($metadata->getIdField(), $this->property($metadata->className, $metadata->id->fieldName)->getValue($entity));
    }

    /**
     * Une propriété non initialisée se lit comme null.
     *
     * @param class-string $class
     */
    private function read(object $entity, string $class, string $field): mixed
    {
        $property = $this->property($class, $field);

        return $property->isInitialized($entity) ? $property->getValue($entity) : null;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function postLoad(ClassMetadata $metadata, object $entity): void
    {
        $this->lifecycle->lifecycle($metadata, LifecycleEvent::PostLoad, $entity, fn (): PostLoadEvent => new PostLoadEvent($entity, $this));
    }

    /**
     * @throws InvalidEntityStateException entité qui n'est pas MANAGED, ou pas encore écrite
     */
    private function assertWritten(object $entity, string $operation): int
    {
        $oid = spl_object_id($entity);
        $state = $this->getEntityState($entity);
        if (EntityState::Managed !== $state) {
            throw InvalidEntityStateException::transition($entity, $state, $operation, 'only managed entities can be ' . ('lock' === $operation ? 'locked' : 'refreshed') . '.');
        }
        if (isset($this->insertions[$oid])) {
            throw InvalidEntityStateException::transition($entity, $state, $operation, 'it has not been written yet; flush it first.');
        }

        return $oid;
    }

    private function track(object $entity, EntityState $state): void
    {
        $oid = spl_object_id($entity);
        $this->states[$oid] = $state;
        $this->entities[$oid] = $entity;
        unset($this->detached[$entity]);
    }

    private function forget(object $entity, bool $detach = false): void
    {
        $oid = spl_object_id($entity);
        if (isset($this->keys[$oid])) {
            unset($this->identityMap[$entity::class][$this->keys[$oid]]);
        }
        unset($this->collectionSnapshots[$oid], $this->collectionInstances[$oid], $this->inverseSnapshots[$oid], $this->fetchedInverse[$oid], $this->prefetched[$entity::class][$this->keys[$oid] ?? '']);
        $this->batch->forget($entity);
        foreach (array_keys($this->lazyPending) as $lazyKey) {
            unset($this->lazyPending[$lazyKey][$oid], $this->lazyLoaded[$lazyKey][$oid]);
        }
        unset($this->keys[$oid], $this->states[$oid], $this->entities[$oid], $this->snapshots[$oid], $this->insertions[$oid], $this->deletions[$oid], $this->persisted[$oid], $this->softDeleted[$oid], $this->restored[$oid]);

        if ($detach) {
            $this->detached[$entity] = true;
        }
    }

    /**
     * @return ClassMetadata<object>
     */
    private function metadataOf(object $entity): ClassMetadata
    {
        return $this->metadata->getMetadataFor($entity::class);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function field(ClassMetadata $metadata, string $column): FieldMapping
    {
        if (!isset($this->columns[$metadata->className])) {
            $columns = [];
            foreach ($metadata->getFields() as $field) {
                $columns[$field->columnName] = $field;
            }
            foreach ($metadata->getEmbedded() as $embedded) {
                foreach ($embedded->getFields() as $field) {
                    $columns[$field->columnName] = $field;
                }
            }
            // colonne de jointure : typée comme l'identifiant de la cible
            foreach ($this->owningToOne($metadata) as [$association, $target]) {
                $columns[(string) $association->joinColumn] = new FieldMapping($association->fieldName, (string) $association->joinColumn, $target->getIdField()->type, $association->nullable);
            }
            $this->columns[$metadata->className] = $columns;
        }

        return $this->columns[$metadata->className][$column];
    }

    /**
     * @param class-string $class
     */
    private function property(string $class, string $field): \ReflectionProperty
    {
        return $this->properties[$class][$field] ??= new \ReflectionProperty($class, $field);
    }

    private static function key(mixed $id): string
    {
        return \is_int($id) || \is_string($id) ? (string) $id : '';
    }
}
