# Working with entities

The `EntityManager` saves, loads and tracks your entities. Its unit of work remembers every entity it manages and, at `flush()`, writes what changed in one transaction.

The examples run on a small example ERP: `Customer`, `Order`, `OrderLine`, `Product` and `Invoice` entities, with Acme as the current tenant.

## Entity states

An entity is in one of four states:

| State | Meaning | Reached by |
| --- | --- | --- |
| `NEW` | Unknown to Prism | `new Customer()` |
| `MANAGED` | Tracked: its changes are written at the next flush | `persist()`, or loaded by Prism |
| `REMOVED` | Deleted at the next flush | `remove()` on a managed entity |
| `DETACHED` | No longer tracked | `detach()`, `clear()` |

Any other transition throws `InvalidEntityStateException`: for example, `remove()` on a new entity, or `persist()` on a detached one. A removed entity becomes managed again if you `persist()` it before the flush.

## Saving

```php
$customer = new Customer();
$customer->name = 'Fabrikam';
$customer->email = 'buyer@fabrikam.example';

$em->persist($customer);
$em->flush();
```

`flush()` writes every pending change of every managed entity:

- **One transaction.** If a statement fails, the transaction is rolled back and the unit of work goes back exactly to its state before the flush: nothing is marked as saved, you can fix the cause and flush again.
- **Ordered statements.** `INSERT` statements follow the foreign keys, then come the `UPDATE` statements, the join table rows, and the `DELETE` statements in reverse order. A cycle between two entities is broken on a nullable foreign key, inserted as `NULL` and updated afterwards.
- **Only what changed.** An entity is compared with its state when it was loaded or last flushed. An unchanged entity costs nothing, and an `UPDATE` contains only the changed columns.

Calling `flush()` from inside a flush, in an event listener, throws `ReentrantFlushException`.

## Loading

```php
$customers = $em->getRepository(Customer::class);

$customer = $customers->findOneBy(['email' => 'customer1@acme.example.test']);
$same = $em->find(Customer::class, $customer->id);

var_dump($same === $customer); // bool(true)
```

The unit of work keeps an identity map: within one entity manager, one row is one object. `find()` returns the instance already loaded instead of querying again, until `clear()`.

The repository methods:

| Method | Returns |
| --- | --- |
| `find($id)` | The entity, or `null` |
| `findOneBy($criteria, $orderBy)` | The first match, or `null` |
| `findBy($criteria, $orderBy, $limit, $offset)` | `list<T>` |
| `findAll()` | `list<T>` |
| `count($criteria)` | `int` |
| `iterate($criteria, $batchSize)` | A generator, for large result sets |

A criterion is an equality on a field; a list of values means `IN`, `null` means `IS NULL`. For anything else, use the [typed query builder](/guide/getting-started#query).

## References

When you only need an entity to point at it, a reference avoids a query:

```php
$customerId = $em->getRepository(Customer::class)->findOneBy(['email' => 'customer2@acme.example.test'])->id;
$em->clear();

$customer = $em->getReference(Customer::class, $customerId); // no query
echo $customer->id;                                         // still no query
echo $customer->name;                                       // loads the row
```

The reference is a PHP 8.4 lazy ghost: an object of the real class, whose identifier is readable without loading. Any other property access loads the row once. If the row does not exist anymore, that access throws `EntityNotFoundException`.

## Transactions

`flush()` opens its own transaction. To group several flushes, or your own queries, with them, use `wrapInTransaction()`:

```php
$order = $em->wrapInTransaction(function () use ($em): Order {
    $order = new Order();
    $order->reference = 'ORD-90001';
    $order->placedAt = new \DateTimeImmutable();
    $order->status = 'open';
    $order->customer = $em->getRepository(Customer::class)->findOneBy(['email' => 'customer1@acme.example.test']);
    $em->persist($order);

    $line = new OrderLine();
    $line->order = $order;
    $line->product = $em->getRepository(Product::class)->findOneBy(['sku' => 'SKU-001']);
    $line->quantity = 3;
    $line->unitPrice = $line->product->price;
    $order->lines->add($line);
    $em->persist($line);

    return $order; // flushed and committed when the callback returns
});
```

The callback's changes are flushed and committed together. Inside an open transaction, it uses a savepoint, so it can be nested. If anything throws, the transaction is rolled back and the entity manager is cleared: the entities you hold no longer reflect the database and must be loaded again.

## Locking

Two processes can load the same row and both save it; the last one wins. To prevent it:

- **Optimistic locking**: a [`#[Prism\Version]`](/guide/mapping#optimistic-locking) field. The second flush fails with `OptimisticLockException`, and nothing waits.
- **Pessimistic locking**: lock the row in the database until the transaction ends.

```php
use Prism\Dbal\LockMode;

$em->wrapInTransaction(function () use ($em): void {
    $order = $em->getRepository(Order::class)->findOneBy(['reference' => 'ORD-00001']);
    $em->lock($order, LockMode::PessimisticWrite); // SELECT ... FOR UPDATE
    $order->status = 'shipped';
});
```

`LockMode::PessimisticRead` takes a shared lock instead (`FOR SHARE`). SQLite has no row locks: the clause is left out there, and its write transactions lock the whole database. A pessimistic lock outside a transaction throws `TransactionRequiredException`.

## Change tracking

By default, every managed entity is compared at each flush. On an entity with many instances loaded and few changed, you can compare only the entities you pass to `persist()`:

```php no-run
#[Prism\Entity(trackingPolicy: Prism\TrackingPolicy::Explicit)]
final class StockMovement
{
    // ...
}
```

With `Explicit`, a loaded entity that you modify is saved only if you call `persist()` on it before the flush.

## Large result sets

`findAll()` keeps every entity in memory. To process a whole table, iterate:

```php
foreach ($em->getRepository(Order::class)->iterate(batchSize: 100) as $order) {
    // ...
}
```

`iterate()` reads the table by pages on the identifier and detaches the entities every 100 rows: the memory stays flat, a million rows included.

## Refresh, detach, clear

| Method | Effect |
| --- | --- |
| `refresh($entity)` | Reads the row again; unsaved changes to the entity are lost |
| `detach($entity)` | Stops tracking the entity; its changes will not be saved |
| `clear()` | Detaches every entity and releases them: call it between two jobs in a long-running process |
| `contains($entity)` | Whether the entity is managed |
