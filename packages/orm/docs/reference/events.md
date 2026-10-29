# Events

Prism publishes typed PSR-14 events through the event dispatcher of each entity manager. In Symfony, listen to them with [`#[AsPrismListener]`](/reference/attributes#asprismlistener); without Symfony, pass any PSR-14 dispatcher to the `EntityManager`:

```php no-run
$em = new EntityManager($connection, $metadata, events: $dispatcher);
```

For the events of one entity class, [lifecycle callbacks](/guide/mapping#lifecycle-callbacks) on the entity or [entity listeners](/reference/attributes#entitylisteners) are simpler: they receive the entity directly.

All events are in `Prism\Orm\Event`, except `NPlusOneDetected`, in `Prism\Orm\Debug`. Their properties are `readonly`.

The `unitOfWork` property of an event is a `Prism\Orm\UnitOfWork\FlushContext`: what a listener may see and do during a flush. It lists the entities to insert, update and delete (`getScheduledInsertions()`, `getScheduledUpdates()`, `getScheduledDeletions()`), tells whether anything would be written (`hasPendingChanges()`), gives the state of an entity (`contains()`, `getEntityState()`), and accepts `persist()` and `remove()` from `PreFlushEvent` or `OnFlushEvent`, which this same flush then writes. The rest of the unit of work is internal to Prism.

## Flush

A flush publishes `PreFlushEvent` and `OnFlushEvent` before its transaction, `PrePersistEvent` and `PreUpdateEvent` for each entity inside it, then after the commit `PostPersistEvent`, `PostUpdateEvent`, `PostRemoveEvent` and `AuditRecordedEvent`, and `PostFlushEvent` last. If the flush fails, nothing is published after the rollback. Calling `flush()` from a listener throws `ReentrantFlushException`.

### `PreFlushEvent`

At the start of `flush()`, before any change is computed: an entity persisted or modified here is written by this flush.

| Property | Type | |
| --- | --- | --- |
| `unitOfWork` | `FlushContext` | |

### `OnFlushEvent`

The changes are computed, before the transaction starts. `FlushContext::getScheduled*()` lists them; a change made here is included.

| Property | Type | |
| --- | --- | --- |
| `unitOfWork` | `FlushContext` | |

### `PrePersistEvent`

Before the `INSERT` of an entity, in the transaction of the flush; a change to the entity is inserted.

| Property | Type | |
| --- | --- | --- |
| `entity` | `object` | The entity |
| `unitOfWork` | `FlushContext` | |

### `PreUpdateEvent`

Before the `UPDATE` of an entity; a change to the entity is included, and only that entity's changeset is computed again.

| Property | Type | |
| --- | --- | --- |
| `entity` | `object` | The entity |
| `changeSet` | `array<string, array{mixed, mixed}>` | Field → [old value, new value] |
| `unitOfWork` | `FlushContext` | |

### `PostPersistEvent`

After the commit of the flush that inserted the entity, never inside its transaction.

| Property | Type | |
| --- | --- | --- |
| `entity` | `object` | The entity, with its generated identifier |
| `unitOfWork` | `FlushContext` | |

### `PostUpdateEvent`

After the commit of the flush that updated the entity.

| Property | Type | |
| --- | --- | --- |
| `entity` | `object` | The entity |
| `unitOfWork` | `FlushContext` | |

### `PostRemoveEvent`

After the commit of the flush that deleted the entity.

| Property | Type | |
| --- | --- | --- |
| `entity` | `object` | The entity |
| `unitOfWork` | `FlushContext` | |

### `AuditRecordedEvent`

The audit rows written by a flush, published after its commit. The Audit panel of the Symfony profiler listens to it; listen to it to copy the log elsewhere.

| Property | Type | |
| --- | --- | --- |
| `entries` | `list<AuditEntry>` | One entry per audited entity changed |

### `PostFlushEvent`

After the commit and the other `Post*` events; never after a failure.

| Property | Type | |
| --- | --- | --- |
| `unitOfWork` | `FlushContext` | |

### `InverseSideChangedEvent`

At flush, a collection on the inverse side of a relation changed without its owning side: nothing is written for that change. An added element does not point to the owner, or a removed one still does. The Symfony bundle logs it as a warning in development.

| Property | Type | |
| --- | --- | --- |
| `owner` | `object` | The entity whose collection changed |
| `field` | `string` | The collection property |
| `element` | `object` | The element added or removed |
| `added` | `bool` | `true` if added, `false` if removed |
| `unitOfWork` | `FlushContext` | |

## Entities

### `PreRemoveEvent`

When `remove()` is called, before the entity is scheduled for deletion.

| Property | Type | |
| --- | --- | --- |
| `entity` | `object` | The entity |
| `unitOfWork` | `FlushContext` | |

### `PostLoadEvent`

After an entity is loaded from the database: `find()`, `findBy()`, `iterate()`, a reference that loads, `refresh()`. Never for an entity that the identity map returns.

| Property | Type | |
| --- | --- | --- |
| `entity` | `object` | The entity |
| `unitOfWork` | `FlushContext` | |

### `OnClearEvent`

After `clear()`: every entity is detached.

| Property | Type | |
| --- | --- | --- |
| `unitOfWork` | `FlushContext` | |

## Diagnostics

### `BatchLoadedEvent`

A batch load: one query for a whole cohort. Published only when the batch holds at least two entities; the Symfony profiler shows them.

| Property | Type | |
| --- | --- | --- |
| `entityClass` | `class-string` | Without `association`: the class of the entities loaded together. With it: the class of the owners |
| `association` | `?string` | `null` for referenced entities loaded together; otherwise the relation loaded for the owners (a collection, or the inverse side of a `OneToOne`) |
| `count` | `int` | The number of entities, or of owners |
| `unitOfWork` | `FlushContext` | |

### `NPlusOneDetected`

`Prism\Orm\Debug\NPlusOneDetected`, published by the [N+1 detector](/guide/n-plus-one#the-n-1-detector) when the same read query ran more times than its threshold since its last `reset()`. Published once per query.

| Property | Type | |
| --- | --- | --- |
| `sql` | `string` | The normalized SQL: values and `IN` lists replaced by `?` |
| `count` | `int` | How many times it ran |
| `origin` | `string` | `file:line` of the first frame outside `vendor/` and Prism: the line of your code that started the loop |
