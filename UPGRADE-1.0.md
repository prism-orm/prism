# Upgrading to Prism 1.0

Prism 1.0 freezes its public API: from 1.0 on, a minor or patch version does not break what is marked `@api` (see [ADR 0020](docs/adr/0020-api-policy-1-0.md)). Before that freeze, 1.0 trims the API to what an application writes, calls, implements or configures. This page lists every change that can break code written for 0.9.

## One sort order: `Prism\Dbal\Query\Order`

`Prism\Orm\Mapping\Order` is removed. `#[OrderBy]` now takes `Prism\Dbal\Query\Order`, the enum `findBy()` and the query builder already took.

```php
// before
#[Prism\OrderBy(['position' => Prism\Order::Asc])]

// after
use Prism\Dbal\Query\Order;

#[Prism\OrderBy(['position' => Order::Asc])]
```

Clear the metadata cache after upgrading (`bin/console cache:clear`, or `db:cache:clear-metadata`): a cached mapping still refers to the removed enum.

## Events: `unitOfWork` is a `FlushContext`

The `unitOfWork` property of every event is now typed `Prism\Orm\UnitOfWork\FlushContext`, an interface with what a listener needs during a flush: `persist()`, `remove()`, `contains()`, `getEntityState()`, `hasPendingChanges()` and `getScheduledInsertions()`, `getScheduledUpdates()`, `getScheduledDeletions()`. A listener that only calls these methods needs no change.

A listener that called another method of the unit of work relied on an internal class: there is no supported replacement.

## Classes that are no longer part of the API

These classes are now `@internal`: they still exist, but they may change in any version. An application uses them through the attributes, the entity manager, the commands or the configuration, which stay covered.

| Package | Internal classes | Use instead |
| --- | --- | --- |
| `dbal` | `MySqlPlatform`, `MariaDbPlatform`, `PostgreSqlPlatform`, `SqlitePlatform`, `AbstractMySqlPlatform` | `AbstractPlatform`, through `Connection::getPlatform()`: its methods are API, but a platform is not to extend |
| `dbal` | `Schema\Comparator`, `SchemaDiff`, `TableDiff`, `ColumnDiff`, `SchemaManager`, and `Connection::createSchemaManager()` | migrations: `MigrationBuilder`, `db:migrations:diff` |
| `dbal` | the built-in types, `StringType` to `VectorType` | their names, `'string'` to `'vector'`, in `#[Column(type:)]`; `Type` for a custom type |
| `orm` | `UnitOfWork\UnitOfWork`, and `EntityManager::getUnitOfWork()` | the entity manager; `FlushContext` in a listener |
| `orm` | the classes of the `db:*` commands | the commands themselves, by name and option |
| `orm` | `Tools\SchemaTool`, `Tools\Import\EntityImporter`, `ImportResult`, `Metadata\MetadataValidator`, `Query\Metamodel\MetamodelGenerator`, `MetamodelAutoloader`, `GenerationResult` | `db:schema:*`, `db:import`, `db:schema:validate`, `db:metamodel:generate` |
| `orm` | `Behavior\SoftDeleteFilter`, `Tenancy\TenantFilter`, `Event\DefaultEntityListenerResolver` | the filter names, `'soft_delete'` and `'tenant'`; `EntityListenerResolver` |
| `orm` | `Mapping\MappingAttribute`, `AssociationAttribute`, `LifecycleCallbackAttribute`, `LifecycleEvent` | the attributes themselves |
| `orm` | the constructors of `ClassMetadata`, `FieldMapping`, `AssociationMapping` and the other mappings | read them, from `MetadataFactory::getMetadataFor()` or in an `EntityFilter`; Prism builds them |
| `migrations` | `Migrator`, `MigrationFinder`, `MigrationResult`, `MigrationState`, `MigrationStatus`, `ExecutedMigration`, `Direction`, and the classes of the `db:migrations:*` commands | the commands |
| `migrations` | `Lint\MigrationLinter`, `LintResult`, `Finding`, `Severity` | `db:migrations:lint`, and its codes L01 to L21 |

A few members that only Prism calls are also `@internal` now: the constructors of the events, the factory methods of the exceptions that take an internal type, `getEvent()` of the lifecycle attributes, `ClassMetadata::getLifecycleCallbacks()` and `getEntityListenerCallbacks()`, `AbstractPlatform::getMigrationSql()` and `MigrationsConfiguration::finder()`.

## Generated files

`db:import` and `db:migrations:diff` now write their header comment in English. Files generated before keep theirs; nothing needs to change.
