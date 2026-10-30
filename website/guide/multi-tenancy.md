# Multi-tenancy

A B2B application often serves many customer companies, the *tenants*, from one database. Every query must then be limited to the current tenant, and forgetting it once leaks one customer's data to another. Prism makes this filter automatic and impossible to forget: it fails closed.

The examples run on a small example ERP, whose tenants are two companies, Acme and Globex. Acme is the current tenant.

## Tenanted entities

```php
#[Prism\Entity]
#[Prism\Tenanted]
final class Contract
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 40)]
    public string $number;

    #[Prism\ManyToOne]
    public Customer $customer;
}
```

A `#[Prism\Tenanted]` entity gets a `tenant_id` column, `NOT NULL` and indexed, placed first in each of its indexes and unique constraints: a contract number is unique per tenant, not across tenants. The column is not a property of the entity: Prism writes it, filters on it and checks it. To read it, map a property on it, `public private(set) int $tenantId` for example; Prism fills it.

The column type is the same for every entity: `integer` by default, `bigint` or `uuid` if your tenant identifiers are.

## What Prism does

For a tenanted entity, **every** query of Prism has a `tenant_id = :current` condition:

- `find()`, `findBy()`, `count()`, `iterate()`, `refresh()`, `lock()`;
- the typed query builder, joins and fetch joins included, pagination and projections;
- lazy references, collections and batch fetching.

```php
// Acme's invoices only
$invoices = $em->getRepository(Invoice::class)->findAll();

// even by identifier: another tenant's invoice is "not found"
$o = Order_::as('o');
$order = $em->query($o)->where($o->reference->eq('ORD-00001'))->getOneOrNull();
```

Writes are checked too:

- at `INSERT`, Prism writes the current tenant; a different value set by your code throws `TenantMismatchException`;
- `UPDATE` and `DELETE` statements carry the condition as well, and a row never changes tenant;
- a relation between entities of two different tenants is refused at flush with `CrossTenantRelationException`.

## No tenant, no query

Without a current tenant, any operation on a tenanted entity throws `MissingTenantException`. There is no implicit "all tenants" mode:

```php
use Prism\Orm\Exception\MissingTenantException;

$tenants->setTenantId(null);

try {
    $em->getRepository(Invoice::class)->findAll();
} catch (MissingTenantException $e) {
    echo $e->getMessage(), "\n";
}
```

## Setting the tenant

The current tenant lives in a `TenantContext`, the `StackTenantContext` service:

```php
$globex = $em->getRepository(Company::class)->findOneBy(['slug' => 'globex']);

// one tenant, for the duration of a callback
$globexOrders = $tenants->runAs($globex->id, fn () => $em->getRepository(Order::class)->count());

// every tenant, explicitly: administration, reports, batch jobs
$allOrders = $tenants->runAsSystem(fn () => $em->getRepository(Order::class)->count());
```

| Method | Effect |
| --- | --- |
| `setTenantId($id)` | Sets the tenant of the request or the job |
| `runAs($id, $callback)` | Runs the callback as another tenant, then comes back |
| `runAsSystem($callback)` | Runs the callback without the tenant filter |
| `reset()` | Forgets the tenant; called between two requests in a worker |

In system mode, reads see every tenant, but a row still never changes tenant, and a relation between two tenants is still refused. Writes made in system mode are flagged in the [audit log](/guide/audit).

**Each change of tenant detaches tenant-owned entities**, so that no entity crosses from one tenant to another. Non-tenant and shared (`global: true`) entities remain managed. If changes are waiting to be flushed, the switch is refused with `TenantSwitchException` on entry; unflushed changes are discarded when leaving a tenant scope.

## Shared rows

A reference table can mix rows shared by every tenant with rows owned by one, a product catalog for example:

```php no-run
#[Prism\Entity]
#[Prism\Tenanted(global: true)]
final class Product
{
    // ...
}
```

A row whose `tenant_id` is `NULL` is shared: every tenant reads it, and only the system mode writes it. Rows with a tenant follow the usual rules. In the example ERP, the 30 products of the catalog are shared, and each company has two of its own.

## Raw SQL

SQL that you write yourself goes through the tenant guard of the connection. A query on a tenanted table must have a condition on its tenant column:

```php
use Prism\Orm\Exception\UnfilteredTenantQueryException;

$sql = 'SELECT id FROM invoice WHERE tenant_id = ? AND total > ?';
$big = $connection->executeQuery($sql, [$tenants->getTenantId(), '1000.00'])->fetchAllAssociative();

try {
    $connection->executeQuery('SELECT id FROM invoice WHERE total > ?', ['1000.00']);
} catch (UnfilteredTenantQueryException $e) {
    echo $e->getMessage(), "\n";
}
```

The guard reads the SQL. It accepts a condition on the tenant column in each part of the `WHERE` or `ON` joined by `AND`, or in each branch of an `OR`, and the tenant column of an `INSERT`. When it cannot be sure, it refuses: subqueries, `WITH`, `UNION`, `INSERT ... SELECT` and `TRUNCATE` on a tenanted table. It checks that the condition exists, not the value you bind. It lets everything through in system mode.

By default, the guard throws in development and in tests, and logs a critical error in production.

## Entity filters

The tenant filter is one entity filter among others, the only one that cannot be disabled. A filter adds its condition to every query on the tables it targets:

```php
use Prism\Dbal\Query\Expression;
use Prism\Orm\Filter\EntityFilter;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Metadata\ClassMetadata;

final class ParisCustomersFilter implements EntityFilter
{
    public function condition(ClassMetadata $metadata, FilterTarget $target): ?Expression
    {
        return Customer::class === $metadata->className ? $target->expr()->eq($target->column('city'), 'Paris') : null;
    }
}
```

In Symfony, a service that implements `EntityFilter` is registered automatically. Without Symfony, add it to the `FilterCollection` passed to the `EntityManager`.

## In Symfony

```yaml
# config/packages/prism.yaml
prism:
    tenancy:
        type: integer           # integer, bigint or uuid
        resolver:
            subdomain: example.com
        loader: App\Tenancy\CompanyLoader
        # guard: log            # default: exception in debug, log otherwise
```

The bundle sets the tenant of each request from **one** source:

| `resolver` | Source | Runs |
| --- | --- | --- |
| `header: X-Tenant-Id` | A request header; **only behind a gateway that sets it itself** | Before the firewall |
| `subdomain: example.com` | `acme` in `acme.example.com` | Before the firewall |
| `user: true` | The logged-in user, a `TenantAwareUserInterface` | After the firewall |

A subdomain is a name, not an identifier: a `TenantLoader` service translates it, like the demo's `CompanyLoader`. With the `user` strategy, the user entity must not be tenanted, since the firewall loads it before the tenant is known. Without a `resolver`, set the tenant yourself, through the `StackTenantContext` service.

Between two requests of a worker, the context is reset. The `db:*` commands run in system mode: they administer the whole database. In development, the **Tenant** panel of the profiler shows the tenant of the request, how it was resolved, and the `runAs()` and `runAsSystem()` calls.

In a test, set the tenant before using the entities:

```php no-run
static::getContainer()->get(StackTenantContext::class)->setTenantId(1);
```

## Without Symfony

Share one `TenantContext` between the guard of the connection and the entity manager:

```php no-run
use Prism\Dbal\Configuration;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantGuard;

$tenants = new StackTenantContext();
$metadata = new MetadataFactory(new AttributeDriver([__DIR__ . '/src/Entity']));
$connection = DriverManager::fromUrl((string) getenv('DATABASE_URL'), new Configuration(guards: [new TenantGuard($metadata, $tenants)]));

return new EntityManager($connection, $metadata, tenantContext: $tenants);
```

The tenant type of the mapping is the `tenantType` argument of the `AttributeDriver`: `'integer'` by default.
