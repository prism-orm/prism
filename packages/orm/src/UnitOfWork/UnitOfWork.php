<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

use Prism\Dbal\LockMode;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Event\PostPersistEvent;
use Prism\Orm\Event\PostRemoveEvent;
use Prism\Orm\Event\PostUpdateEvent;
use Prism\Orm\Event\PrePersistEvent;
use Prism\Orm\Event\PreRemoveEvent;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Exception\EntityNotFoundException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\OptimisticLockException;
use Prism\Orm\Exception\ReentrantFlushException;
use Prism\Orm\Exception\TransactionRequiredException;
use Prism\Orm\Hydrator\ObjectHydrator;
use Prism\Orm\Lazy\LazyFactory;
use Prism\Orm\Mapping\TrackingPolicy;
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
 * - PrePersist est publié pendant le flush, avant l'INSERT ; PreRemove par remove() ;
 * - Post* est publié après le commit, jamais pendant la transaction ;
 * - une entité supprimée par le flush, ou persistée puis retirée avant tout flush, redevient NEW ;
 * - une ligne relue ne réécrit jamais une entité déjà gérée : seul refresh() la réécrit ;
 * - une référence (ghost) non chargée n'a pas de snapshot : le flush ne la compare pas.
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

    private readonly ObjectHydrator $hydrator;

    private readonly LazyFactory $lazy;

    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly PersisterProvider $persisters,
        private readonly ConversionContext $conversion,
        private readonly TypeRegistry $types,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->detached = new \WeakMap();
        $this->hydrator = new ObjectHydrator($conversion, $types);
        $this->lazy = new LazyFactory();
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

                    return;
                }
                // la version et les listeners lisent l'entité : une référence est chargée d'abord
                $this->lazy->initialize($entity);
                $this->events?->dispatch(new PreRemoveEvent($entity, $this));
                $this->states[$oid] = EntityState::Removed;
                $this->deletions[$oid] = $entity;
        }
    }

    /**
     * MANAGED → DETACHED.
     *
     * @throws InvalidEntityStateException entité NEW ou REMOVED
     */
    public function detach(object $entity): void
    {
        match ($this->getEntityState($entity)) {
            EntityState::New => throw InvalidEntityStateException::transition($entity, EntityState::New, 'detach', 'only managed entities can be detached.'),
            EntityState::Removed => throw InvalidEntityStateException::transition($entity, EntityState::Removed, 'detach', 'persist it again to cancel the removal, or flush first.'),
            EntityState::Managed => $this->forget($entity, detach: true),
            EntityState::Detached => null,
        };
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
        $this->insertions = [];
        $this->deletions = [];
        $this->persisted = [];
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

        return null === $row ? null : $this->register($metadata, $row);
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
        $rows = $this->persisters->getPersister($metadata)->loadBy($criteria, $orderBy, $limit, $offset);

        return array_map(fn (array $row): object => $this->register($metadata, $row), $rows);
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $criteria
     *
     * @throws MappingException champ inconnu
     */
    public function count(string $class, array $criteria = []): int
    {
        return $this->persisters->getPersister($this->metadata->getMetadataFor($class))->count($criteria);
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

        $after = null;
        do {
            $rows = $persister->loadAfter($criteria, $after, $batchSize);
            $loaded = [];
            foreach ($rows as $row) {
                $known = isset($this->identityMap[$class][self::key($this->toPhp($metadata->getIdField(), $row[$idColumn]))]);
                $entity = $this->register($metadata, $row);
                if (!$known) {
                    $loaded[] = $entity;
                }
                $after = $row[$idColumn];

                yield $entity;
            }
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

        $ghost = $this->lazy->ghost($metadata, $id, function (object $ghost) use ($metadata, $idField, $id): void {
            $row = $this->persisters->getPersister($metadata)->loadById($this->toDatabase($idField, $id));
            if (null === $row) {
                throw EntityNotFoundException::forId($metadata->className, $id);
            }
            $this->hydrator->refresh($ghost, $metadata, $row);
            // une référence détachée se charge encore, mais n'est plus suivie
            $oid = spl_object_id($ghost);
            if (($this->entities[$oid] ?? null) === $ghost) {
                $this->snapshots[$oid] = $this->databaseRow($metadata, $ghost);
            }
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
        $this->snapshots[$oid] = $this->databaseRow($metadata, $entity);
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
     * Écrit tout en une transaction (RG-03.6) : INSERT dans l'ordre de persist(), UPDATE des
     * seules colonnes modifiées, DELETE en ordre inverse. En cas d'échec, rollback et l'état
     * d'avant le flush est rétabli, identifiants générés et versions compris.
     *
     * @throws ReentrantFlushException appelé depuis un listener (RG-03.7)
     * @throws OptimisticLockException version périmée (RG-03.9)
     */
    public function commit(): void
    {
        if ($this->flushing) {
            throw ReentrantFlushException::create();
        }

        $updates = $this->pendingUpdates();
        if ([] === $this->insertions && [] === $this->deletions && [] === $updates) {
            return;
        }

        $this->flushing = true;
        /** @var list<array{object, \ReflectionProperty, mixed}> valeurs écrites sur les entités, à rétablir si rollback */
        $written = [];

        try {
            $done = $this->persisters->transactional(function () use ($updates, &$written): array {
                return [$this->executeInsertions($written), $this->executeUpdates($updates, $written), $this->executeDeletions()];
            });
        } catch (\Throwable $e) {
            foreach (array_reverse($written) as [$entity, $property, $value]) {
                $property->setValue($entity, $value);
            }

            throw $e;
        } finally {
            $this->flushing = false;
        }

        $this->afterCommit(...$done);
    }

    /**
     * @param list<array{object, \ReflectionProperty, mixed}> $written
     *
     * @return list<object>
     */
    private function executeInsertions(array &$written): array
    {
        $inserted = [];
        foreach ($this->insertions as $entity) {
            $this->events?->dispatch(new PrePersistEvent($entity, $this));
            $metadata = $this->metadataOf($entity);
            $idField = $metadata->getIdField();

            if (null !== $generated = $this->persisters->nextId($metadata)) {
                $this->write($entity, $idField->fieldName, $this->toPhp($idField, $generated), $written);
            }

            $row = array_filter($this->databaseRow($metadata, $entity), fn (string $column): bool => $this->field($metadata, $column)->insertable, \ARRAY_FILTER_USE_KEY);
            $identity = $this->persisters->getPersister($metadata)->insert($row);
            if (null !== $identity) {
                $this->write($entity, $idField->fieldName, $this->toPhp($idField, $identity), $written);
            }
            $inserted[] = $entity;
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

            $this->events?->dispatch(new PreUpdateEvent($entity, $this->changeSet($metadata, $oid, $changes), $this));
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
    private function executeDeletions(): array
    {
        $deleted = [];
        foreach (array_reverse($this->deletions, true) as $oid => $entity) {
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
            $this->events?->dispatch(new PostPersistEvent($entity, $this));
        }
        foreach ($updated as [$entity]) {
            $this->events?->dispatch(new PostUpdateEvent($entity, $this));
        }
        foreach ($deleted as $entity) {
            $this->events?->dispatch(new PostRemoveEvent($entity, $this));
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
        foreach ($changes as $column => $value) {
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

        return $row;
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
    private function register(ClassMetadata $metadata, array $row): object
    {
        $idField = $metadata->getIdField();
        $key = self::key($this->toPhp($idField, $row[$idField->columnName] ?? null));
        if (isset($this->identityMap[$metadata->className][$key])) {
            /** @var T */
            return $this->identityMap[$metadata->className][$key];
        }

        $entity = $this->hydrator->hydrate($metadata, $row);
        $oid = spl_object_id($entity);
        $this->track($entity, EntityState::Managed);
        $this->snapshots[$oid] = $this->databaseRow($metadata, $entity);
        $this->identityMap[$metadata->className][$key] = $entity;
        $this->keys[$oid] = $key;

        return $entity;
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
            $this->columns[$metadata->className] = $columns;
        }

        return $this->columns[$metadata->className][$column];
    }

    /**
     * @param class-string $class
     */
    private function property(string $class, string $field): \ReflectionProperty
    {
        return new \ReflectionProperty($class, $field);
    }

    private static function key(mixed $id): string
    {
        return \is_int($id) || \is_string($id) ? (string) $id : '';
    }
}
