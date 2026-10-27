# Exceptions

Every exception that Prism throws implements `Prism\Exception\PrismException`, and an interface per package: `DbalException`, `OrmException`, `MigrationsException`, `BundleException`. Catch one of them to handle all the errors of a package.

The messages are in English and name what is concerned: the entity, the field, the identifier, the table. They never contain a bound value or a password, which may be sensitive.

## ORM

`Prism\Orm\Exception`, package `prism-orm/orm`.

### `MappingException`

A mapping attribute has an invalid argument, or the mapping of an entity is invalid: no identifier, a `\DateTime` property, a contradiction between `nullable` and the PHP type, a relation whose other side does not match... The message lists every error with its class and field.

**Fix:** follow the message; `db:schema:validate` lists all the errors of all the entities at once.

### `InvalidEntityStateException`

An operation that the state of the entity does not allow: `remove()` on a new entity, `persist()` on a detached one, for example.

**Fix:** see the [entity states](/guide/entity-manager#entity-states). A detached entity is loaded again with `find()`.

### `UnpersistedRelationException`

At flush, a new entity is reachable through a relation of a managed one, but was never persisted. The message gives the entity, the field and the path. Nothing was written.

**Fix:** `persist()` it, or add `cascade: [Prism\Cascade::Persist]` to the relation.

### `CyclicDependencyException`

The `INSERT` statements of a flush cannot be ordered: the entities reference each other in a cycle in which no foreign key is nullable.

**Fix:** make one foreign key of the cycle nullable. Prism then inserts it as `NULL` and sets it with an `UPDATE` afterwards.

### `ReentrantFlushException`

`flush()` was called during a flush, from an event listener or a lifecycle callback.

**Fix:** change entities in `PreFlushEvent`, `OnFlushEvent` or `PreUpdateEvent`, which the running flush writes; or flush after it returns.

### `OrphanReattachedException`

An entity removed from a collection with `orphanRemoval` was attached to another parent in the same flush, while it is going to be deleted.

**Fix:** flush the removal first, or move the entity without `orphanRemoval`.

### `EntityNotFoundException`

The row of an entity does not exist, or not anymore: a reference that loads, `refresh()`, `lock()`. The message gives the class and the identifier.

**Fix:** use `find()`, which returns `null`, when the row may be missing.

### `OptimisticLockException`

The row changed since it was loaded: the `UPDATE` or `DELETE` checked against the [`#[Version]`](/reference/attributes#version) field matched no row. Also thrown by an optimistic `lock()` on an outdated version, or on an entity without a version.

**Fix:** load the entity again and reapply the change, or tell the user that someone else changed it.

### `TransactionRequiredException`

A pessimistic `lock()` outside a transaction.

**Fix:** call it inside `wrapInTransaction()`.

### `NonUniqueResultException`

`getOneOrNull()` on a query that returns several entities.

**Fix:** add a condition, or use `getResult()` or `limit(1)`.

### `QueryException`

A typed query is invalid: an alias that is not part of the query, a stale metamodel, a fetch join of an alias that is not fetched, a `limit()` with a fetch-joined collection, an entity query with a `groupBy()`, a selected field that is not grouped, a `selectInto()` that does not match its DTO, an invalid JSON path, a pagination without order or with a `limit()`.

**Fix:** follow the message. PHPStan reports the `selectInto()` cases during analysis; in development, a stale metamodel is regenerated when the kernel boots.

### `InvalidArgumentException`

An argument refused by an ORM API: a batch size out of range, a criterion that holds an unsaved entity, a filter name that already exists or does not, the tenant filter disabled, `restore()` on an entity that is not soft-deletable.

**Fix:** follow the message.

### `MetamodelException`

The metamodel cannot be written in its directory.

**Fix:** make the directory writable, or choose another one (`metamodel.dir`, `--dir`).

### `MissingTenantException`

An operation on a `#[Tenanted]` entity without a current tenant. Prism has no implicit "all tenants" mode.

**Fix:** set the tenant (`setTenantId()`, `runAs()`), or use `runAsSystem()` for an operation on every tenant. See [Multi-tenancy](/guide/multi-tenancy#no-tenant-no-query).

### `TenantMismatchException`

The tenant property of an entity holds another tenant than the current one, or was changed: the tenant of a row never changes.

**Fix:** let Prism fill the tenant property; to create an entity for another tenant, do it inside `runAs()`.

### `CrossTenantRelationException`

At flush, a relation links two tenanted entities of different tenants. Refused even in system mode.

**Fix:** link entities of the same tenant; a shared reference row belongs to an entity marked `#[Tenanted(global: true)]`.

### `TenantSwitchException`

The tenant changed while the unit of work had unsaved changes. When entering `runAs()` or `runAsSystem()`, the switch is refused and nothing is lost; when leaving, the changes made inside are discarded, never written under another tenant.

**Fix:** flush before entering, and before leaving.

### `UnfilteredTenantQueryException`

Raw SQL on a tenanted table without a condition on its tenant column, a `TRUNCATE`, or SQL that the guard cannot analyze. Thrown by the tenant guard in `exception` mode.

**Fix:** add `tenant_id = ?` to the `WHERE` or `ON` of each tenanted table, or run the query in `runAsSystem()` if it really concerns every tenant. See [Raw SQL](/guide/multi-tenancy#raw-sql).

## DBAL

`Prism\Dbal\Exception`, package `prism-orm/dbal`.

### `ConnectionFailedException`

The database connection could not be opened.

**Fix:** check the host, the credentials and that the server runs; the message is the driver's.

### `InvalidUrlException`

The database URL cannot be read or is incomplete. The password never appears in the message.

**Fix:** use Symfony's format, `driver://user:pass@host:port/db?serverVersion=x`.

### `UnsupportedPlatformException`

The `serverVersion` cannot be read, or is older than what Prism supports: MySQL 8.0, MariaDB 11.8, PostgreSQL 15, SQLite 3.40.

**Fix:** set `serverVersion` to the real version of the server, which must be supported.

### `QueryFailedException`

The server refused a query. The exception carries the SQL and the SQLSTATE, never the bound values.

**Fix:** read the SQLSTATE: a constraint violation, a missing table (run the migrations), a syntax error in your own SQL.

### `AbortedTransactionException`

Under PostgreSQL, a failed query invalidated the transaction: a `COMMIT` would silently become a `ROLLBACK`. Prism rolls back and says so.

**Fix:** handle the first failure; to continue a transaction after an expected failure, run the risky query inside a nested transaction (a savepoint).

### `NoActiveTransactionException`

`commit()` or `rollBack()` without an open transaction, or after MySQL or MariaDB committed it implicitly with a DDL statement.

**Fix:** pair each `beginTransaction()` with one `commit()` or `rollBack()`, or use `transactional()`.

### `ConversionException`

A value cannot be converted between PHP and the database: a `float` for a `decimal`, a mutable `\DateTime`, a number with more significant digits than the column holds, invalid JSON... The message names the type and the expected form, never the value.

**Fix:** pass the PHP type of the column: a `string` for a `decimal`, a `\DateTimeImmutable` for a date.

### `InvalidParameterException`

A value that cannot be bound (not a scalar), positional and named parameters mixed, an invalid savepoint name or JSON path.

**Fix:** follow the message.

### `InvalidQueryException`

A query of the DBAL query builder is inconsistent: a clause that this kind of query does not allow, no table, an empty `SELECT` or `IN` list, a comparison with `null` (use `isNull()`).

**Fix:** follow the message.

### `InvalidIdentifierException`

A table, column or index name that cannot be quoted safely on the platform: empty, with invalid characters, or too long.

**Fix:** rename it.

### `InvalidSchemaException`

A schema object is inconsistent, or cannot be translated into DDL on the platform: a duplicate or unknown column, a nullable primary key, a foreign key to an unknown table, a type or feature the platform does not support.

**Fix:** follow the message; it names the table and the column.

### `UnknownTypeException`

A column type that is not registered in the `TypeRegistry`.

**Fix:** check the name, or register the custom type (in Symfony, tag it `prism.type`).

### `DuplicateTypeException`

A type registered twice under the same name.

**Fix:** choose another name for the custom type.

## Migrations

`Prism\Migrations\Exception`, package `prism-orm/migrations`.

### `MigrationException`

A migration cannot run: an invalid file name, a missing class, a class that is not a migration, an unknown version, a migration already executed or not executed, a migration modified after it ran, an invalid operation, or a failure while it ran.

**Fix:** follow the message. For a migration modified after it ran, restore it, or run `migrate --allow-modified` if the change is deliberate.

### `IrreversibleMigrationException`

The `down()` of a migration that cannot be reverted: it has none, or reverting it would need data that it destroyed.

**Fix:** none in the general case; restore a backup, or write a `down()` if the data can be rebuilt.

## Symfony bundle

`Prism\Bundle\Exception`, package `prism-orm/bundle`.

### `InvalidConfigurationException`

`prism.yaml` is inconsistent: short and complete forms mixed, an entity manager whose connection is not declared, an unknown default entity manager.

**Fix:** follow the message; see the [configuration reference](/reference/configuration).

### `MapEntityException`

`#[MapEntity]` is misused: an unknown entity manager, a missing route parameter, a field that does not exist. A programming error, never a 404.

**Fix:** follow the message.

### `MakerException`

`make:entity` or `make:migration` cannot complete: a file it cannot read, a missing class.

**Fix:** follow the message.

## Test pack

### `TestPackException`

`Prism\TestPack\Exception\TestPackException`: the test pack is misused, for example a factory used before the kernel boots, an unknown attribute, or queries counted while they are not recorded. A test error, never an application one.

**Fix:** follow the message.
