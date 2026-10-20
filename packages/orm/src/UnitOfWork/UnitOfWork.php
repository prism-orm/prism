<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

use Prism\Dbal\LockMode;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Collection\PersistentCollection;
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
use Prism\Orm\Exception\CyclicDependencyException;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\OptimisticLockException;
use Prism\Orm\Exception\OrphanReattachedException;
use Prism\Orm\Exception\ReentrantFlushException;
use Prism\Orm\Exception\TransactionRequiredException;
use Prism\Orm\Exception\UnpersistedRelationException;
use Prism\Orm\Hydrator\ObjectHydrator;
use Prism\Orm\Lazy\LazyFactory;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\Fetch;
use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Mapping\Order as MappingOrder;
use Prism\Orm\Mapping\TrackingPolicy;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Persister\PersisterProvider;
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
 * @api
 */
final class UnitOfWork
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

    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly PersisterProvider $persisters,
        private readonly ConversionContext $conversion,
        private readonly TypeRegistry $types,
        ?EventDispatcherInterface $events = null,
        ?EntityListenerResolver $listeners = null,
        int $batchSize = 100,
    ) {
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
    public function remove(object $entity): void
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
                $this->lifecycle->lifecycle($this->metadataOf($entity), LifecycleEvent::PreRemove, $entity, fn (): PreRemoveEvent => new PreRemoveEvent($entity, $this));
                $this->states[$oid] = EntityState::Removed;
                $this->deletions[$oid] = $entity;
                $this->cascadeRemove($entity);
        }
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
        $this->identityMap = [];
        $this->keys = [];
        $this->collectionSnapshots = [];
        $this->collectionInstances = [];
        $this->inverseSnapshots = [];
        $this->fetchedInverse = [];
        $this->prefetched = [];
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
    public function hydrate(ClassMetadata $metadata, array $rows, bool $batch = true, array $fetched = []): array
    {
        $cohort = $batch ? new Cohort() : null;
        $fresh = [];
        $entities = [];
        foreach ($rows as $row) {
            $known = isset($this->identityMap[$metadata->className][self::key($this->toPhp($metadata->getIdField(), $row[$metadata->getIdField()->columnName] ?? null))]);
            $entities[] = $entity = $this->register($metadata, $row, $cohort);
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
        $row = $this->persisters->getPersister($metadata)->loadById($this->idOf($metadata, $oid));
        if (null === $row) {
            throw EntityNotFoundException::forId($metadata->className, $this->property($metadata->className, $metadata->id->fieldName)->getValue($entity));
        }
        $this->hydrator->refresh($entity, $metadata, $row);
        $this->hydrateRelations($metadata, $entity, $row, refresh: true);
        $this->hydrateCollections($metadata, $entity, refresh: true);
        $this->completeCohort($metadata, [$entity], refresh: true);
        $this->snapshots[$oid] = $this->databaseRow($metadata, $entity);
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
     * @throws ReentrantFlushException      appelé depuis un listener (RG-03.7)
     * @throws OptimisticLockException      version périmée (RG-03.9)
     * @throws UnpersistedRelationException entité NEW atteinte sans cascade: persist (RG-04.2)
     * @throws CyclicDependencyException    cycle de FK obligatoires (RG-04.4)
     * @throws OrphanReattachedException    orphelin rattaché à un autre parent (RG-04.5)
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
            if ([] !== $this->insertions || [] !== $this->deletions || [] !== $updates || [] !== $collections) {
                $this->executeFlush($updates, $collections, $this->insertionOrder(), $this->deletionOrder());
            }

            $this->lifecycle->dispatch(new PostFlushEvent($this));
        } finally {
            $this->flushing = false;
        }
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

        try {
            $done = $this->persisters->transactional(function () use ($updates, $collections, $insertions, $deletions, &$written): array {
                $inserted = $this->executeInsertions($insertions, $written);
                $updated = $this->executeUpdates($updates, $written);
                $this->executeCollections($collections);

                return [$inserted, $updated, $this->executeDeletions($deletions)];
            });
        } catch (\Throwable $e) {
            foreach (array_reverse($written) as [$entity, $property, $value]) {
                $property->setValue($entity, $value);
            }

            throw $e;
        }

        $this->afterCommit(...$done);
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
            $idField = $metadata->getIdField();

            if (null !== $generated = $this->persisters->nextId($metadata)) {
                $this->write($entity, $idField->fieldName, $this->toPhp($idField, $generated), $written);
            }

            $row = array_filter($this->databaseRow($metadata, $entity), fn (string $column): bool => $this->field($metadata, $column)->insertable, \ARRAY_FILTER_USE_KEY);
            foreach ($nulled[$oid] ?? [] as $column) {
                $row[$column] = null;
            }
            $identity = $this->persisters->getPersister($metadata)->insert($row);
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

            $this->lifecycle->lifecycle($metadata, LifecycleEvent::PreUpdate, $entity, fn (): PreUpdateEvent => new PreUpdateEvent($entity, $this->changeSet($metadata, $oid, $changes), $this));
            // RG-03.8 : une modification faite dans PreUpdate est prise en compte
            $changes = $this->changes($metadata, $entity);
            if ([] === $changes) {
                continue;
            }

            $version = null;
            if (null !== $metadata->versionField) {
                $field = $metadata->getField($metadata->versionField);
                $old = $this->snapshots[$oid][$field->columnName];
                $oldPhp = $this->toPhp($field, $old);
                $this->write($entity, $field->fieldName, \is_int($oldPhp) ? $oldPhp + 1 : new \DateTimeImmutable(), $written);
                $changes[$field->columnName] = $this->toDatabase($field, $this->property($metadata->className, $field->fieldName)->getValue($entity));
                $version = [$field->columnName, $old];
            }

            $affected = $this->persisters->getPersister($metadata)->update($this->idOf($metadata, $oid), $changes, $version);
            if (0 === $affected && null !== $version) {
                throw OptimisticLockException::versionMismatch($entity, $this->toPhp($metadata->getField($metadata->versionField), $version[1]));
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
            $key = self::key($this->property($metadata->className, $metadata->id->fieldName)->getValue($entity));
            $this->identityMap[$metadata->className][$key] = $entity;
            $this->keys[$oid] = $key;
        }
        foreach ($updated as [$entity, $changes]) {
            $oid = spl_object_id($entity);
            $this->snapshots[$oid] = $changes + $this->snapshots[$oid];
        }
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
        foreach ($this->databaseRow($metadata, $entity) as $column => $value) {
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
     * colonne, pour que '12.5' et '12.50' ne soient pas vus comme un changement.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, mixed>
     */
    private function databaseRow(ClassMetadata $metadata, object $entity): array
    {
        $row = [];
        foreach ($metadata->extractRow($entity) as $column => $value) {
            $row[$column] = $this->toDatabase($this->field($metadata, $column), $value);
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
    private function register(ClassMetadata $metadata, array $row, ?Cohort $cohort = null): object
    {
        $idField = $metadata->getIdField();
        $key = self::key($this->toPhp($idField, $row[$idField->columnName] ?? null));
        if (isset($this->identityMap[$metadata->className][$key])) {
            /** @var T $known */
            $known = $this->identityMap[$metadata->className][$key];
            // une référence encore à charger : la ligne lue la charge, sans nouvelle requête
            if ($this->lazy->isUninitialized($known)) {
                $this->prefetched[$metadata->className][$key] = [$row, $cohort ?? new Cohort()];
                $this->lazy->initialize($known);
            }

            return $known;
        }

        $values = $this->hydrator->toPhpRow($metadata, $row);
        $entity = $this->hydrator->fromPhpRow($metadata, $values);
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
            $this->snapshots[$oid] = $this->snapshotOf($metadata, $entity, $values) + $this->foreignKeys($metadata, $entity);
        } else {
            $this->snapshots[$oid] = $this->snapshotOf($metadata, $entity, $values);
        }
        $this->postLoad($metadata, $entity);

        return $entity;
    }

    /**
     * Snapshot d'une entité qu'on vient d'hydrater : ses valeurs PHP par colonne sont celles que
     * databaseRow() relirait, sans relire les propriétés. Une ligne incomplète (colonne absente,
     * propriété laissée à sa valeur par défaut) passe par databaseRow().
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $values   valeurs PHP, par colonne
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(ClassMetadata $metadata, object $entity, array $values): array
    {
        if (\count($values) !== \count($metadata->getColumnTypes())) {
            return $this->databaseRow($metadata, $entity);
        }

        $snapshot = [];
        foreach ($values as $column => $value) {
            $snapshot[$column] = $this->toDatabase($this->field($metadata, $column), $value);
        }

        return $snapshot;
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
                $row = $this->persisters->getPersister($metadata)->loadById($this->toDatabase($idField, $id));
            } else {
                $ids = [$id, ...array_map(fn (object $other): mixed => $this->property($metadata->className, $metadata->id->fieldName)->getValue($other), $batch)];
                foreach ($this->persisters->getPersister($metadata)->loadBy([$idField->fieldName => $ids]) as $loaded) {
                    $this->prefetched[$metadata->className][self::key($this->toPhp($idField, $loaded[$idField->columnName] ?? null))] = [$loaded, $cohort];
                }
                [$row] = $this->prefetched[$metadata->className][$key] ?? [null];
                unset($this->prefetched[$metadata->className][$key]);
            }
        }
        if (null === $row) {
            throw EntityNotFoundException::forId($metadata->className, $id);
        }

        $this->hydrator->refresh($ghost, $metadata, $row);
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
            $this->finishGhost($metadata, $ghost, $tracked);

            return;
        }
        // les autres ghosts du lot, depuis leur ligne préchargée
        foreach ($batch as $other) {
            if ($this->lazy->isUninitialized($other) && isset($this->prefetched[$metadata->className][$this->keys[spl_object_id($other)] ?? ''])) {
                $this->lazy->initialize($other);
            }
        }
        $this->completeCohort($metadata, null === $cohort || [] === $batch ? [$ghost] : $cohort->members(), refresh: true);
        $this->finishGhost($metadata, $ghost, $tracked);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function finishGhost(ClassMetadata $metadata, object $ghost, bool $tracked): void
    {
        if ($tracked) {
            $this->snapshots[spl_object_id($ghost)] = $this->databaseRow($metadata, $ghost);
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
        $order = array_map(static fn (MappingOrder $o): Order => MappingOrder::Desc === $o ? Order::Desc : Order::Asc, $association->orderBy);
        $field = $association->fieldName;
        // RG-04.12 : la même collection des autres membres de la cohorte, en une requête
        $owners = $this->batch->ownersWith($owner, $association, function (object $member) use ($metadata, $field): bool {
            $collection = $this->read($member, $metadata->className, $field);

            return $collection instanceof PersistentCollection && !$collection->isInitialized();
        });

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
        [$table, $ownerColumn] = $this->joinTableOf($association);

        return $this->persisters->getPersister($metadata)->countJoinTable($table, $ownerColumn, $this->currentId($metadata, $owner), $metadata->getIdField()->type);
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
        unset($this->keys[$oid], $this->states[$oid], $this->entities[$oid], $this->snapshots[$oid], $this->insertions[$oid], $this->deletions[$oid], $this->persisted[$oid]);

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
