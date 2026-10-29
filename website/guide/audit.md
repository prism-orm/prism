# Audit and behaviors

Business data needs to know when it changed, who changed it, and what it looked like before. Four class attributes cover this, with no extra package: `#[Prism\Timestampable]`, `#[Prism\Blameable]`, `#[Prism\SoftDeletable]` and `#[Prism\Auditable]`.

The examples run on a small example ERP, where `Order` is timestamped and blamed, `Customer` is soft-deletable and `Invoice` is audited.

## Timestamps and authors

```php
#[Prism\Entity]
#[Prism\Timestampable]
#[Prism\Blameable]
final class Ticket
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 120)]
    public string $subject;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;

    #[Prism\Column]
    public ?\DateTimeImmutable $updatedAt = null;

    #[Prism\Column(length: 180)]
    public ?string $createdBy = null;

    #[Prism\Column(length: 180)]
    public ?string $updatedBy = null;
}
```

- **At `INSERT`**, Prism sets `createdAt` and `createdBy`, unless your code already did: imported data keeps its original dates.
- **At `UPDATE`**, Prism sets `updatedAt` and `updatedBy`, only when another field changed. Loading and flushing an entity without changing it writes nothing.
- **The time** is UTC, from a PSR-20 clock: Symfony's clock in the bundle, which tests can freeze.
- **The author** comes from an `ActorProvider`: in Symfony, the logged-in user's identifier, or `cli:<command>` in a console command.

The fields are named `createdAt`, `updatedAt`, `createdBy` and `updatedBy` by default. Name your own with `#[Prism\Timestampable(createdAt: 'openedAt', updatedAt: null)]`; `null` disables the update.

## Soft delete

```php no-run
#[Prism\Entity]
#[Prism\SoftDeletable]
final class Customer
{
    #[Prism\Column]
    public ?\DateTimeImmutable $deletedAt = null;

    // ...
}
```

`remove()` on a soft-deletable entity does not delete the row: it writes the date into `deletedAt`. Deleted rows then disappear from every read.

```php
$customers = $em->getRepository(Customer::class);
$customer = $customers->findOneBy(['email' => 'customer3@acme.example.test']);

$em->remove($customer);
$em->flush();                                // UPDATE customer SET deleted_at = ...

var_dump($customers->findOneBy(['email' => 'customer3@acme.example.test'])); // NULL

$c = Customer_::as('c');
$deleted = $em->query($c)->withDeleted()->where($c->email->eq('customer3@acme.example.test'))->getOneOrNull();

$em->restore($deleted);
$em->flush();                                // deleted_at is NULL again
```

- **Excluded everywhere**: `find()`, `findBy()`, the query builder, collections and batch fetching. `withDeleted()` brings them back into one query.
- **Relations still work**: an order whose customer was deleted still loads its customer. A deleted row is history, not a hole.
- **Unique constraints** apply to the rows that are not deleted: the email of a deleted customer can be used again. Prism builds a partial index under PostgreSQL and SQLite, and an index on a generated column under MySQL and MariaDB.
- **To really delete** a row, pass `hardDelete: true`:

```php
$customer = new Customer();
$customer->name = 'Temporary';
$customer->email = 'temporary@acme.example.test';
$em->persist($customer);
$em->flush();

$em->remove($customer, hardDelete: true);
$em->flush();                                // DELETE FROM customer ...
```

## Audit log

```php no-run
#[Prism\Entity]
#[Prism\Auditable(exclude: ['updatedAt'], retention: 'P10Y')]
final class Invoice
{
    #[Prism\Column(length: 34, sensitive: true)]
    public string $iban = '';

    // ...
}
```

Each insert, update, delete, soft delete and restore of an `#[Prism\Auditable]` entity writes one row into the `prism_audit_log` table, **in the transaction of the flush**: a flush that fails leaves no audit row behind, and a change that was saved is always audited. A row holds:

- the entity class and identifier, and its tenant;
- the action: `insert`, `update`, `delete`, `soft-delete` or `restore`;
- the changeset: the old and new value of each changed field. Fields listed in `exclude` are left out; fields marked `#[Prism\Column(sensitive: true)]` are written as `***`;
- the author, the request identifier (the `X-Request-Id` header in Symfony), the UTC date, and whether the change was made in [system mode](/guide/multi-tenancy#setting-the-tenant).

`db:migrations:diff` creates the table with your first audited entity.

### Reading the history

```php
use Prism\Orm\Audit\AuditReader;

$invoice = $em->getRepository(Invoice::class)->findOneBy(['number' => 'INV-00003']);
$invoice->paid = !$invoice->paid;
$em->flush();

$audit = new AuditReader($em);
foreach ($audit->history(Invoice::class, $invoice->id) as $entry) {
    echo $entry->occurredAt->format('Y-m-d H:i'), ' ', $entry->action, ' ', json_encode($entry->changes), "\n";
}

$lastWeek = $audit->stateAt(Invoice::class, $invoice->id, new \DateTimeImmutable('-1 week'));
```

`history()` returns the entries from the oldest to the newest, as `AuditEntry` objects. `stateAt()` replays them up to a date and returns the fields with their values at that time, or `null` if the entity did not exist then. Both only see the current tenant's entries.

### Append only

No Prism API updates or deletes an audit row. Old rows are removed by one command, according to the `retention` of each entity, an ISO 8601 duration:

```console
$ bin/console db:audit:purge                 # shows what would be deleted
$ bin/console db:audit:purge --force
$ bin/console db:audit:purge --older-than=P1Y --force   # for entities without a retention
```

Each audit row also publishes an `AuditRecordedEvent`, to copy the log elsewhere. In Symfony, the **Audit** panel of the profiler lists the rows written during the request.

## Without Symfony

The clock, the author and the request identifier are arguments of the `EntityManager`:

```php no-run
return new EntityManager(
    $connection,
    $metadata,
    clock: $clock,                         // a PSR-20 ClockInterface; UTC system time by default
    actorProvider: $actorProvider,         // a Prism\Orm\Behavior\ActorProvider: getActor(): ?string
    requestIdProvider: $requestIdProvider, // a Prism\Orm\Audit\RequestIdProvider: getRequestId(): ?string
);
```

Without an `ActorProvider`, `createdBy`, `updatedBy` and the author of audit rows stay `null`.
