# Console commands

Reference of Prism's `db:*` commands: what each does, its arguments and options, its exit codes and examples. For their use in Symfony, see [Prism in Symfony](symfony.md).

The examples are real outputs of `bin/prism` on SQLite, with two entities (`Customer`, `Invoice`); only absolute paths were shortened. The SQL shown depends on the platform of the connection.

## Running the commands

### `bin/prism` and `prism.php`

Without Symfony, the `vendor/bin/prism` executable (package `prism-orm/migrations`) loads the `prism.php` file of the current directory, or the file given by `--config=<file>`. This file returns either the `EntityManager`, or a `PrismConfig` to set the rest.

The short form is enough when the defaults suit you (migrations in `migrations/`, namespace `App\Migrations`, `dev` environment):

```php
<?php // prism.php

declare(strict_types=1);

use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;

// bin/prism has already loaded Composer's autoloader
return new EntityManager(
    DriverManager::fromUrl((string) getenv('DATABASE_URL')),
    new MetadataFactory(new AttributeDriver([__DIR__ . '/src/Entity'])),
);
```

The complete form:

```php
return new PrismConfig(
    $entityManager,
    migrations: new MigrationsConfiguration(__DIR__ . '/migrations', 'App\\Migrations', environment: 'prod'),
    metamodelDir: 'var/cache/prism/metamodel', // db:metamodel:generate
    largeTable: 1_000_000,                     // threshold of the L10 linter rule
);
```

| `PrismConfig` parameter | Default | Effect |
| --- | --- | --- |
| `entityManager` | — | The entity manager; its connection serves every command |
| `migrations` | `new MigrationsConfiguration()` | Directory and namespace of the migrations, environment (`dev`, `prod`...) |
| `metadata` | the `EntityManager`'s | To pass for another implementation of `EntityManagerInterface` |
| `metamodelDir` | `var/cache/prism/metamodel` | Default directory of `db:metamodel:generate` |
| `largeTable` | `1_000_000` | Number of rows beyond which the linter reports an `ALTER` under MySQL (L10) |
| `embeddingProviders` | `new EmbeddingProviderRegistry()` | The embedding providers by name, for `db:embeddings:compute` |

```console
$ vendor/bin/prism list db
$ vendor/bin/prism --config=config/prism.php db:migrations:status
```

In a Symfony application, the bundle registers the same commands in `bin/console`. They gain the `--em=<name>` option, which picks the entity manager (the default one otherwise): `bin/console db:migrations:migrate --em=billing`. Each entity manager has its own migrations.

### Common conventions

- **Exit codes**: `0` (`Command::SUCCESS`), `1` (`Command::FAILURE`: an error, a database out of sync, a failed lint), `2` (`Command::INVALID`: a missing or invalid option, or a destructive command run without `--force`).
- **Destructive commands**: nothing runs without `--force`; the command says what it would have done and returns `2`.
- **`prod` environment**: `db:migrations:migrate`, `db:migrations:execute` and `db:database:drop` ask for confirmation, unless `--no-interaction`.
- **Global options** of Symfony Console, valid everywhere: `--no-interaction` (`-n`), `--quiet` (`-q`), `--verbose` (`-v`), `--ansi` / `--no-ansi`, `--help` (`-h`).
- The tables and sequences prefixed with `prism_` belong to Prism (`prism_migration_versions`, `prism_audit_log`): no command drops them as "not mapped".

## Database

### `db:database:create`

Creates the database named in the URL of the connection. Under PostgreSQL, Prism connects to the `postgres` database to create it; under SQLite, it creates the file.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--if-not-exists` | | Do nothing if the database exists (exit code `0`) |

```console
$ vendor/bin/prism db:database:create

 [ERROR] Database "/srv/app/var/app.db" already exists; pass --if-not-exists to accept it.

$ vendor/bin/prism db:database:create --if-not-exists
```

### `db:database:drop`

Drops the database of the connection, with all its data. Requires `--force`; in `prod`, also asks for confirmation.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--force` | `-f` | Drop the database |
| `--if-exists` | | Do nothing if the database does not exist (exit code `0`) |

```console
$ vendor/bin/prism db:database:drop

 ! [CAUTION] This would drop the database "/srv/app/var/app.db" and all its data; nothing was done.
 !           Run with --force to drop it.

$ vendor/bin/prism db:database:drop --force --if-exists
```

## Schema without migrations

For development and tests: the database follows the mapping directly. In production, use migrations.

### `db:schema:create`

Creates the tables, indexes, foreign keys and sequences of the mapping. Refuses if one of the tables already exists.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--dump-sql` | | Show the SQL instead of running it |

```console
$ vendor/bin/prism db:schema:create --dump-sql

 [ERROR] These tables already exist: customer, invoice.
         Run db:schema:update to bring an existing database in line with the mapping.
```

### `db:schema:update`

Brings the schema of the database in line with the mapping. Without `--force`, nothing runs: the command counts the statements and returns `2`. Tables that are not mapped are dropped only with `--complete`.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--dump-sql` | | Show the SQL that would run |
| `--force` | `-f` | Run the SQL |
| `--complete` | | Also drop the tables and sequences that are not mapped (except `prism_*`) |

```console
$ vendor/bin/prism db:schema:update --dump-sql
$ vendor/bin/prism db:schema:update --force
$ vendor/bin/prism db:schema:update --dump-sql

 [OK] Nothing to update: the database is in sync with the mapping.
```

### `db:schema:drop`

Drops the tables and sequences of the mapping, and their data. Requires `--force`.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--dump-sql` | | Show the SQL instead of running it |
| `--force` | `-f` | Run the SQL (destroys the data of the mapped tables) |

```console
$ vendor/bin/prism db:schema:drop

 ! [CAUTION] This drops 2 table(s) and all their data: customer, invoice.
 !           Run with --force to execute, or --dump-sql to see the SQL.
```

### `db:schema:validate`

Validates the mapping, then checks that the database matches it. Returns `1` if the mapping is invalid or the database is out of sync; warnings do not fail.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--skip-sync` | | Validate the mapping only, without connecting to the database |

```console
$ vendor/bin/prism db:schema:validate

Mapping
-------

 [OK] The mapping of 2 entities is valid.

Database
--------

 [ERROR] The database is not in sync with the mapping: 4 queries pending. Run db:schema:update
         --dump-sql to see them.
```

## Migrations

A migration is a list of portable schema operations: the same migration runs on the four databases, and its SQL is produced by the platform when it runs. The files are named `VersionYYYYMMDDHHMMSS` and run in that order; their state is kept in the `prism_migration_versions` table. See the [migrations guide](/guide/migrations).

### `db:migrations:generate`

Creates an empty migration to fill in. With `--data`, a data migration (`VersionYYYYMMDDHHMMSSData`), which receives the entity manager and runs after the schema migration of the same version.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--data` | | Create a data migration |
| `--for` | | With `--data`: the schema migration it follows (`VersionYYYYMMDDHHMMSS`); a new version otherwise |

```console
$ vendor/bin/prism db:migrations:generate --data --for=Version20261021205005

 [OK] Migration Version20261021205005Data created:
      migrations/Version20261021205005Data.php
```

Two migrations generated within the same second would have the same name: the command then refuses to overwrite the file and asks you to wait a second.

### `db:migrations:diff`

Compares the entities with the database and writes the migration that brings them together.

- **Without a change**, no file is created and the command returns `0`.
- **Renames**: a dropped column or table and an added one, of the same type, make the command ask "rename?" in interactive mode. Confirmed, a `renameColumn()` or `renameTable()` keeps the data. In non-interactive mode, the migration keeps the drop and the add, with a warning in a comment.
- **`down()`** is written only when it is safe; a migration that destroys data throws `IrreversibleMigrationException` in its `down()`.
- Tables that are not mapped are dropped only with `--complete`; `prism_*` tables never.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--complete` | | Also drop the tables and sequences that are not mapped (except `prism_*`) |
| `--dry-run` | | Show the migration instead of writing it |

```console
$ vendor/bin/prism db:migrations:diff --no-interaction

 [WARNING] customer.name is dropped and customer.full_name added, both string: if it is a rename,
           replace the dropColumn() and addColumn() below with $m->renameColumn('customer', 'name',
           'full_name') to keep the data.

 ! [NOTE] Its down() is not generated: it drops customer.name, whose data cannot be restored
 !        (RG-06.7).

 [OK] Migration Version20261021205005 generated with 3 operation(s):
      migrations/Version20261021205005.php. Review it, then run db:migrations:migrate.
```

The generated file:

```php
final class Version20261021205005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(MigrationBuilder $m): void
    {
        // RG-06.3 : customer.name is dropped and customer.full_name added, both string: if it is a rename, replace the dropColumn() and addColumn() below with $m->renameColumn('customer', 'name', 'full_name') to keep the data.
        $m->addColumn('customer', new Column('full_name', 'string', length: 120));
        $m->dropColumn('customer', 'name');
        $m->addColumn('invoice', new Column('issued_at', 'datetime_immutable'));
    }

    public function down(MigrationBuilder $m): void
    {
        throw IrreversibleMigrationException::forVersion('Version20261021205005', 'it drops customer.name, whose data cannot be restored');
    }
}
```

### `db:migrations:migrate`

Runs the pending migrations, or migrates to a version: the pending migrations up to it, then the migrations executed after it, reverted from the newest to the oldest. `0` reverts everything.

- A migration modified after it ran blocks the command, unless `--allow-modified`: Prism keeps a checksum of the operations of each executed migration.
- A migration runs in a transaction when the platform can roll its DDL back (PostgreSQL). Otherwise, the command says so before it starts: a failure can leave the database half-migrated.
- In `prod`, the command asks for confirmation, unless `--no-interaction`.

| Argument / option | Shortcut | Effect |
| --- | --- | --- |
| `<version>` | | Target version (`VersionYYYYMMDDHHMMSS`), or `0` to revert everything; optional |
| `--dry-run` | | Show the SQL of each migration without running it |
| `--allow-modified` | | Run even if an executed migration was modified since |

```console
$ vendor/bin/prism db:migrations:migrate --dry-run
 up   Version20261021204946 (without transaction)
      CREATE TABLE "customer" ("id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, "name" VARCHAR(120) NOT NULL, "email" VARCHAR(180) NOT NULL);
      CREATE TABLE "invoice" ("id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, "total" NUMERIC(12, 2) NOT NULL, "customer_id" INTEGER NOT NULL, CONSTRAINT "fk_invoice_b335a48f" FOREIGN KEY ("customer_id") REFERENCES "customer" ("id") ON DELETE RESTRICT);
      CREATE UNIQUE INDEX "uniq_customer_45b166be" ON "customer" ("email");
      CREATE INDEX "idx_invoice_b335a48f" ON "invoice" ("customer_id");

 [WARNING] 1 migration(s) will run without a transaction: if one fails, the statements before the
           failure stay applied and the database is left half-migrated (RG-06.6).

 [OK] Dry run: 1 migration(s) planned, nothing executed.

$ vendor/bin/prism db:migrations:migrate --no-interaction
 up   Version20261021204946 (without transaction)
 ✔ up   Version20261021204946 (22 ms)

 [OK] 1 migration(s) executed in 22 ms.

$ vendor/bin/prism db:migrations:migrate 0 --dry-run
 down Version20261021204946 (without transaction)
      DROP TABLE "customer";
      DROP TABLE "invoice";
```

### `db:migrations:execute`

Runs one migration, in one direction: exactly one of `--up` and `--down` (exit code `2` otherwise). This is how a schema migration runs without its data migration. Asks for confirmation in `prod`.

| Argument / option | Shortcut | Effect |
| --- | --- | --- |
| `<version>` | | The version to run (`VersionYYYYMMDDHHMMSS`); required |
| `--up` | | Run its `up()` |
| `--down` | | Run its `down()` |
| `--dry-run` | | Show its SQL without running it |

```console
$ vendor/bin/prism db:migrations:execute Version20261021204946 --down --dry-run
 down Version20261021204946 (without transaction)
      DROP TABLE "customer";
      DROP TABLE "invoice";

 [OK] Dry run: nothing executed.
```

### `db:migrations:status`

Sums up the position of the database: current version, latest version, number of executed and pending migrations. Reports a migration modified after it ran (which `migrate` will refuse) or whose file has gone.

```console
$ vendor/bin/prism db:migrations:status
 ----------------- -----------------------
  Directory         migrations
  Namespace         App\Migrations
  Current version   0
  Latest version    Version20261021204946
  Executed          0
  Pending           1
 ----------------- -----------------------
```

### `db:migrations:list`

Lists each migration with its state (`pending`, `executed`, `modified`, `missing`), its execution date in UTC, its duration and its description.

```console
$ vendor/bin/prism db:migrations:list
 ----------------------- ---------- --------------------- ---------- -------------
  Version                 State      Executed at (UTC)     Duration   Description
 ----------------------- ---------- --------------------- ---------- -------------
  Version20261021204946   executed   2026-10-21 20:49:47   22 ms
 ----------------------- ---------- --------------------- ---------- -------------
```

### `db:migrations:lint`

Analyzes the pending migrations before they run: their operations, applied one by one to the schema, checked against the data of the database, the mapping and the platform. An error fails the command (exit code `1`); a warning too with `--strict`. `#[IgnoreLint('L12', reason: '...')]` on a migration silences a rule you accept.

| Argument / option | Shortcut | Effect |
| --- | --- | --- |
| `<version>` | | Lint the pending migrations up to this version; optional |
| `--strict` | | Also fail on warnings |

| Code | Severity | Case |
| --- | --- | --- |
| L00 | error | Operation impossible on the schema |
| L01 | error | `NOT NULL` column without a default added to a table with rows |
| L02 | error | Column made `NOT NULL` while it holds `NULL`s |
| L03 | error | Column dropped while still mapped |
| L04 | error | Table dropped while still mapped |
| L05 | error | Table or column renamed while its old name is still mapped |
| L06 | error | Table dropped while a foreign key still references it |
| L07 | error | Unique index on duplicates |
| L08 | error | Foreign key on orphan rows |
| L09 | warning | Index without `CONCURRENTLY` on a table with rows, under PostgreSQL |
| L10 | warning | DDL on a table of more than a million rows, under MySQL and MariaDB |
| L11 | warning | Table with rows dropped |
| L12 | warning | Column dropped from a table with rows |
| L13 | warning | Column shrunk on a table with rows |
| L14 | warning | Column type changed on a table with rows |
| L15 | warning | Primary key changed on a table with rows |
| L16 | warning | Raw SQL (`$m->sql()`), neither portable nor analyzable |
| L17 | warning | Several DDL statements without a transaction, under MySQL and MariaDB |
| L18 | warning | Table created without a primary key |
| L19 | warning | Irreversible migration |
| L20 | warning | Vector index created on a table with rows: the HNSW graph is built over them |
| L21 | warning | Vector index on a `#[Tenanted]` table where one tenant, among others, has more than 10,000 rows, on MariaDB: a `nearest()` filtered by that tenant often falls back to an exact search over its rows |

```console
$ vendor/bin/prism db:migrations:lint
 ----------------------- ------ ---------- ------------------------------------------------------
  Version                 Code   Severity   Message
 ----------------------- ------ ---------- ------------------------------------------------------
  Version20261021205005   L01    error      customer.full_name is added NOT NULL without a default
                                            to a table with rows: the existing rows have no value
                                            for it. Add it nullable, fill it, then make it NOT
                                            NULL; or give it a default.
  Version20261021205005   L12    warning    customer.name is dropped from a table with rows: its
                                            data is lost.
  Version20261021205005   L01    error      invoice.issued_at is added NOT NULL without a default
                                            to a table with rows: […]
 ----------------------- ------ ---------- ------------------------------------------------------

 [ERROR] 1 pending migration(s) linted: 2 error(s), 1 warning(s).
```

## Mapping and tools

### `db:mapping:info`

Lists the mapped entities, with their table and their repository.

```console
$ vendor/bin/prism db:mapping:info
 --------------------- ---------- ------------
  Entity                Table      Repository
 --------------------- ---------- ------------
  App\Entity\Customer   customer   -
  App\Entity\Invoice    invoice    -
 --------------------- ---------- ------------

 [OK] 2 mapped entities.
```

### `db:query:sql`

Runs a raw SQL query, with bound named parameters (`:name`), never concatenated. A query that is not a read requires `--force` (exit code `2` otherwise).

| Argument / option | Shortcut | Effect |
| --- | --- | --- |
| `<sql>` | | The query, with `:named` parameters; required |
| `--param` | `-p` | A bound parameter, `name=value`; repeatable |
| `--force` | `-f` | Run a query that is not a read |
| `--max-rows` | | Maximum number of rows shown (default: 100) |

```console
$ vendor/bin/prism db:query:sql "SELECT version, execution_time_ms FROM prism_migration_versions"
 ----------------------- -------------------
  version                 execution_time_ms
 ----------------------- -------------------
  Version20261021204946   22
 ----------------------- -------------------

 1 row(s).

$ vendor/bin/prism db:query:sql "DELETE FROM invoice"

 ! [CAUTION] This query is not a read: nothing was executed. Run with --force to execute it.

$ vendor/bin/prism db:query:sql --force "INSERT INTO customer (name, email) VALUES (:n, :e)" -p n=Ada -p e=ada@example.test

 [OK] 1 row(s) affected.
```

### `db:import`

Generates the entities of an existing database: one `final` class per table, strictly typed, with a `ManyToOne` (or a `OneToOne` if the column is unique) per foreign key. The mapping it writes is exact, so the first `db:migrations:diff` after the import is empty, apart from the reported approximations.

- **Never overwrites** an existing file without `--force`.
- **Reported, never silently approximated**:
  - tables without a primary key or with a composite one (not imported);
  - composite foreign keys, or foreign keys to a table that is not imported (kept as a column);
  - join columns without an index or with a default;
  - `ENUM` columns, read as a `string`.
- **Always excluded**: `prism_*` and `doctrine_migration_versions`.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--namespace` | | Namespace of the entities (default: `App\Entity`) |
| `--dir` | | Directory of the files (default: `src/Entity`) |
| `--strip-prefix` | | Table prefix removed from the class names |
| `--exclude` | | Regular expression of the tables to ignore; repeatable |
| `--dry-run` | | Show the entities instead of writing them |
| `--force` | `-f` | Replace existing files |

```console
$ vendor/bin/prism db:import --dry-run --namespace='App\Imported' --exclude=invoice

src/Entity/Customer.php
-----------------------

<?php

declare(strict_types=1);

namespace App\Imported;

use Prism\Orm\Mapping as Prism;

// Imported by db:import from the table customer: review it.
#[Prism\Entity]
#[Prism\Table('customer')]
#[Prism\UniqueConstraint(['email'], name: 'uniq_customer_45b166be')]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 120)]
    public string $name;

    #[Prism\Column(length: 180)]
    public string $email;
}

 [OK] 1 table(s) imported, 0 skipped. Review the entities, then run db:metamodel:generate.
```

### `db:metamodel:generate`

Generates the metamodel classes of the typed query builder (`Customer_`, `Invoice_`...), and the IDE helpers. Only the files that change are rewritten; those of removed entities are deleted. The generated files are not versioned.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--dir` | | Directory of the generated classes (default: `metamodelDir` of `PrismConfig`, `var/cache/prism/metamodel`) |
| `--ide-stub` | | Stub of the metamodel for IDEs, outside the directories they exclude (default: `.prism/metamodel.php`, to ignore in git) |
| `--phpstorm-meta` | | Directory of the PhpStorm metadata, where `prism.php` maps each entity to its repository (default: `.phpstorm.meta.php`) |
| `--no-ide` | | Generate the metamodel only, without the IDE helpers |

```console
$ vendor/bin/prism db:metamodel:generate --no-ide
 written   App/Entity/Customer_.php
 written   App/Entity/Invoice_.php

 [OK] Metamodel in var/cache/prism/metamodel: 2 written, 0 unchanged, 0 deleted.
```

### `db:cache:clear-metadata`

Clears the PSR-6 cache of the metadata, if there is one. Without a configured cache, the attributes are read on each run: there is nothing to clear.

```console
$ vendor/bin/prism db:cache:clear-metadata

 ! [NOTE] No metadata cache is configured: the attributes are read on each run, there is nothing to
 !        clear.
```

### `db:audit:purge`

Deletes the rows of the audit log (`prism_audit_log`) older than the retention of their entity, `#[Prism\Auditable(retention: 'P10Y')]`. It is the only way to delete an audit row (GDPR). An entity without a retention is purged only with `--older-than`. Without `--force`, the command shows what it would delete.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--older-than` | | ISO 8601 retention for the audited entities that declare none, for example `P1Y` |
| `--force` | `-f` | Delete the rows |

```console
$ vendor/bin/prism db:audit:purge --older-than=P1Y

 ------------------ ----------- --------------------- ------
  Entity             Retention   Rows before           Rows
 ------------------ ----------- --------------------- ------
  App\Entity\Order   P1Y         2025-10-26 09:00:00   1240
 ------------------ ----------- --------------------- ------

 ! [CAUTION] This would delete 1240 audit log row(s); nothing was done. Run with --force to delete them.

$ vendor/bin/prism db:audit:purge --older-than=P1Y --force
```

## Embeddings

An `#[EmbeddedVector]` field becomes stale when one of its source fields changes. A stale vector is left out of `nearest()` until it is computed again. Without a scheduler (the default), these commands compute the stale vectors. Like every `db:*` command, they see all the tenants.

### `db:embeddings:compute`

Computes the stale vectors of every `#[EmbeddedVector]` field, or of one entity. It reads the source texts, calls the provider named by the attribute (`model: 'default'`), then writes each vector in a short transaction. A row whose source changed in the meantime stays stale: its own computation is already scheduled. If the provider fails, the command stops with exit code `1` and the remaining rows stay stale.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--class` | | Only this entity, by its class name or its short name |
| `--batch-size` | | Texts sent to the provider per call (default: `64`) |
| `--all` | | Also compute the vectors that are up to date, for example after changing the provider without a migration |

```console
$ vendor/bin/prism db:embeddings:compute --class=Document
App\Entity\Document::$embedding: 1240 vector(s) computed.

 [OK] 1240 vector(s) computed.
```

### `db:embeddings:status`

Counts the vectors of each `#[EmbeddedVector]` field: up to date, stale, and rows by model. `(none)` counts the rows that were never computed, or whose vector the application set itself.

| Option | Shortcut | Effect |
| --- | --- | --- |
| `--class` | | Only this entity, by its class name or its short name |

```console
$ vendor/bin/prism db:embeddings:status

 ------------------------------- ------------ ------- ----------------------------------------------
  Field                           Up to date   Stale   Rows by model
 ------------------------------- ------------ ------- ----------------------------------------------
  App\Entity\Document::$embedding   1238         2       (none): 2, ollama/nomic-embed-text:v1.5: 1238
 ------------------------------- ------------ ------- ----------------------------------------------

 ! [NOTE] 2 stale vector(s): they are left out of nearest() until db:embeddings:compute (or the
 !        ComputeEmbedding messages) computes them.
```

To change the embedding model, a data migration marks every vector stale, then this command, or the messages, compute them again:

```php no-run
public function run(EntityManagerInterface $em): void
{
    $em->getRepository(Document::class)->markEmbeddingsStale('embedding');
}
```
