# Migrations

A migration is a versioned PHP class that changes the schema. Prism generates it from the difference between your entities and the database, checks it before it runs, and refuses to run a migration that changed after it was applied.

The commands below are the Symfony ones (`bin/console`); without Symfony, they are the same with `vendor/bin/prism`. Every option is listed in the [command reference](/bundle/commands).

## The workflow

1. Change your entities.
2. Generate the migration: `bin/console db:migrations:diff`.
3. Read it. Fix it if needed.
4. Lint it against the database: `bin/console db:migrations:lint`.
5. Run it: `bin/console db:migrations:migrate`.

`diff` writes nothing when the database already matches the entities.

## A migration

```php
<?php

namespace App\Migrations;

use Prism\Dbal\Schema\Column;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

final class Version20261027093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment date of invoices';
    }

    public function up(MigrationBuilder $m): void
    {
        $m->addColumn('invoice', new Column('paid_at', 'datetime_immutable', nullable: true));
        $m->createIndex('invoice', ['paid_at']);
    }

    public function down(MigrationBuilder $m): void
    {
        $m->dropIndex('invoice', ['paid_at']);
        $m->dropColumn('invoice', 'paid_at');
    }
}
```

A migration is named `VersionYYYYMMDDHHMMSS`, and migrations run in the order of that timestamp. `up()` and `down()` do not write SQL: they record portable operations, which the platform of the connection translates. The same migration runs on MySQL, MariaDB, PostgreSQL and SQLite, where a change that SQLite cannot `ALTER` rebuilds the table.

| Operation | |
| --- | --- |
| `createTable()`, `dropTable()`, `renameTable()` | Tables |
| `addColumn()`, `changeColumn()`, `dropColumn()`, `renameColumn()` | Columns |
| `changePrimaryKey()` | Primary key |
| `createIndex()`, `dropIndex()` | Indexes, unique or not; `concurrently: true` builds a PostgreSQL index without blocking writes |
| `addForeignKey()`, `dropForeignKey()` | Foreign keys |
| `createSequence()`, `changeSequence()`, `renameSequence()`, `dropSequence()` | Sequences |
| `sql($sql, $params)` | Raw SQL, when nothing else fits: neither portable nor checked by the linter |

## Renames

When a column disappears and another one of the same type appears in the same table, `diff` asks whether it is a rename. Confirmed, the migration uses `renameColumn()` and keeps the data; tables are handled the same way. Without a terminal (`--no-interaction`), the migration keeps the drop and the add, with a comment that tells how to turn them into a rename:

```php no-run
public function up(MigrationBuilder $m): void
{
    // customer.name is dropped and customer.full_name added, both string: if it is a rename,
    // replace the dropColumn() and addColumn() below with $m->renameColumn('customer', 'name', 'full_name') to keep the data.
    $m->addColumn('customer', new Column('full_name', 'string', length: 120));
    $m->dropColumn('customer', 'name');
}
```

## Going back

`diff` writes a `down()` only when it is safe. When the migration destroys data, a dropped column for example, its `down()` throws `IrreversibleMigrationException`: there is no way to bring the data back.

```console
$ bin/console db:migrations:migrate Version20261020120000   # back to that version
$ bin/console db:migrations:migrate 0                       # revert everything
$ bin/console db:migrations:execute Version20261027093000 --down
```

## Running migrations

```console
$ bin/console db:migrations:status
$ bin/console db:migrations:migrate --dry-run
$ bin/console db:migrations:migrate
```

- **Transactions.** Under PostgreSQL, each migration runs in a transaction and a failure leaves nothing behind. MySQL and MariaDB commit each DDL statement implicitly: a migration that fails halfway stays half applied. The command says which migrations run without a transaction before it starts.
- **Checksums.** Prism stores a checksum of each applied migration in `prism_migration_versions`. A migration modified after it ran blocks `migrate`, until you restore it or pass `--allow-modified`.
- **Production.** In the `prod` environment, `migrate` asks for confirmation, unless `--no-interaction`.

## The linter

`db:migrations:lint` replays the pending migrations on the current schema and data, before they run. Errors make the command fail; warnings too with `--strict`.

```console
$ bin/console db:migrations:lint
 ----------------------- ------ ---------- ------------------------------------------------------
  Version                 Code   Severity   Message
 ----------------------- ------ ---------- ------------------------------------------------------
  Version20261021205005   L01    error      customer.full_name is added NOT NULL without a default
                                            to a table with rows: the existing rows have no value
                                            for it. Add it nullable, fill it, then make it NOT
                                            NULL; or give it a default.
  Version20261021205005   L12    warning    customer.name is dropped from a table with rows: its
                                            data is lost.
 ----------------------- ------ ---------- ------------------------------------------------------
```

It catches, among others:

- a `NOT NULL` column without a default added to a table with rows (`L01`), or made `NOT NULL` while it holds `NULL`s (`L02`);
- a column or table dropped or renamed while the mapping still uses it (`L03` to `L05`);
- a unique index on duplicates (`L07`), a foreign key on orphan rows (`L08`);
- an index built without `CONCURRENTLY` on a non-empty PostgreSQL table (`L09`), DDL on a MySQL table of more than a million rows (`L10`);
- data loss: a non-empty table or column dropped, a column shrunk or retyped (`L11` to `L14`).

The command reference lists [all 19 rules](/bundle/commands). A rule that you accept for one migration is silenced with an attribute and a reason:

```php
<?php

namespace App\Migrations;

use Prism\Migrations\AbstractMigration;
use Prism\Migrations\Lint\IgnoreLint;
use Prism\Migrations\MigrationBuilder;

#[IgnoreLint('L12', reason: 'the legacy fax numbers are no longer needed')]
final class Version20261027094500 extends AbstractMigration
{
    public function up(MigrationBuilder $m): void
    {
        $m->dropColumn('customer', 'fax');
    }
}
```

## Data migrations

A data migration changes rows rather than the schema, with the entity manager. Name it after the schema migration of the same version, with a `Data` suffix; it runs right after it, in a transaction, and its changes are flushed at the end:

```php
<?php

namespace App\Migrations;

use App\Entity\Customer;
use Prism\Migrations\DataMigration;
use Prism\Orm\EntityManagerInterface;

final class Version20261027093000Data extends DataMigration
{
    public function run(EntityManagerInterface $em): void
    {
        foreach ($em->getRepository(Customer::class)->findBy(['city' => null]) as $customer) {
            $customer->city = 'Paris';
        }
    }
}
```

`bin/console db:migrations:generate --data` creates one. A data migration uses the entities as they are today, not as they were when it was written: replayed on an empty database years later, it fails if a field it reads has gone. For a migration meant to last, write SQL through `$em->getConnection()`.

## Without migrations

In development, `db:schema:update` brings the database in line with the entities without a migration: `--dump-sql` shows the SQL, `--force` runs it. It never drops a table that is not mapped unless you add `--complete`.
