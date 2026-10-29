# What is Prism?

Prism is an object-relational mapper for PHP 8.4 and later. You describe your entities with PHP attributes; Prism creates the schema, loads and saves your objects, tracks what changed and writes it in one transaction.

It is built for business applications: ERPs, B2B software, back offices. These applications have many relations, many customers sharing one database, and amounts that must never be rounded. Prism handles each of these by default instead of leaving it to every developer.

## Four pillars

**1. No N+1 queries.** Entities loaded by the same query form a *cohort*. When you initialize a lazy relation of one entity, Prism loads that relation for the whole cohort in one `WHERE id IN (...)` query. In development, a detector reports repeated queries with the file and line that triggered them, in the Symfony profiler.

**2. Typed queries.** Prism generates a metamodel class per entity (`Order_` for `Order`). Queries use its typed fields instead of strings, return `list<Order>`, and a PHPStan extension checks field names, value types and DTO projections before the code runs.

**3. Multi-tenancy and audit.** An entity marked `#[Tenanted]` gets a `tenant_id` column that Prism fills, filters and checks in every query, including lazy loading, batch fetching and raw SQL. `#[Auditable]`, `#[Timestampable]`, `#[Blameable]` and `#[SoftDeletable]` cover the rest of what business data needs.

**4. Vector and hybrid search.** A `vector` type for MariaDB and PostgreSQL (pgvector), HNSW and full-text indexes, `nearest()` and `hybridSearch()` on every repository, with the usual filters applied before the ranking. `#[EmbeddedVector]` keeps a vector in step with its text: stale at flush, recomputed after the commit by a provider you choose. See [Vector search](/guide/vector-search).

## Design choices

- **Attributes only.** No XML, no YAML: the mapping lives next to the properties it describes.
- **No proxy classes.** Lazy relations use PHP 8.4 native lazy ghosts. Nothing is generated for your entities, which can be `final`, and `readonly` properties are supported.
- **Your constructor stays yours.** Prism never calls it when loading an entity; use `#[PostLoad]` to compute derived state.
- **Exact values.** A `decimal` column is always a PHP `string`, never a `float`. Dates are `\DateTimeImmutable`; a mutable `\DateTime` property is refused when the mapping is validated.
- **Atomic flush.** `flush()` runs in one transaction. If it fails, the transaction is rolled back and the unit of work is left exactly as it was before the flush.
- **Fail closed.** Without a current tenant, any operation on a tenanted entity throws. There is no implicit "all tenants" mode.
- **Safe migrations.** Migrations are generated from your entities, renames are detected and confirmed, a linter flags dangerous operations, and a migration modified after it ran blocks the next run.

## Requirements

| | Supported | Tested in CI |
| --- | --- | --- |
| PHP | 8.4 and later | 8.4, 8.5 |
| MySQL | 8.0 and later | 8.4 |
| MariaDB | 11.8 and later | 11.8 |
| PostgreSQL | 15 and later | 16 |
| SQLite | 3.40 and later, for development and tests | |
| Symfony (optional) | 7.4 and 8 | 7.4, 8 |

Prism refuses to connect to an older server version than the ones above.

## Not in 1.0

Some features are deliberately out of scope for the first stable version: a textual query language (the typed query builder replaces DQL), entity inheritance, composite primary keys, Oracle and SQL Server, and a second-level cache.

## Packages

| Package | Contents |
| --- | --- |
| `prism-orm/dbal` | Connection, platforms, types, schema and schema diff |
| `prism-orm/orm` | Mapping, `EntityManager`, unit of work, typed query builder |
| `prism-orm/migrations` | Versioned migrations, linter, the `vendor/bin/prism` console |
| `prism-orm/bundle` | Symfony integration |
| `prism-orm/phpstan` | PHPStan extension |
| `prism-orm/test-pack` | Testing tools for applications: factories, `ResetDatabase`, `assertQueryCount()` |

## Project status

Prism is in its `0.x` series, one minor version per milestone, toward a 1.0 release. Until then, the public API may still change between minor versions; a change is announced by a deprecation one minor version before the removal. What changed in each version is listed in the [changelog](https://github.com/prism-orm/prism/blob/main/CHANGELOG.md).

Every example on this site is executed in CI against the four supported databases, on the data of a small example ERP, the same domain as the [demo application](https://github.com/prism-orm/demo): two companies (Acme and Globex), their customers, orders, invoices, and a shared product catalog.
