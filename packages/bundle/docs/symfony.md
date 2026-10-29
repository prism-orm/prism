# Prism in Symfony

The `prism-orm/bundle` package replaces Doctrine in a Symfony 7.4 or 8 application: configuration, services, controllers, console, profiler, Messenger, Security and tests. Every example comes from Prism's [demo application](https://github.com/prism-orm/demo), a small ERP written with Prism alone.

## Install

```bash
composer require prism-orm/bundle
composer require --dev symfony/maker-bundle prism-orm/test-pack
```

Flex registers the bundle in `config/bundles.php`; without Flex, add `Prism\Bundle\PrismBundle::class => ['all' => true]` there yourself. No configuration file is needed to start: Prism reads `DATABASE_URL` and maps `src/Entity/`.

The URL follows Symfony's format, and `serverVersion` is required:

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16"
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.4"
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=11.8.0-MariaDB"
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db?serverVersion=3.46.0"
```

## Configure

One database, the short form:

```yaml
# config/packages/prism.yaml
prism:
    url: '%env(resolve:DATABASE_URL)%'
    entity_dirs: ['%kernel.project_dir%/src/Entity']
    migrations:
        dir: '%kernel.project_dir%/migrations'
        namespace: 'App\Migrations'
```

Several databases: named connections and entity managers, the first declared being the default one. Each entity manager has its own migrations (`migrations/Billing` and `App\Migrations\Billing` by default):

```yaml
prism:
    connections:
        default: { url: '%env(resolve:DATABASE_URL)%' }
        billing: { url: '%env(resolve:BILLING_DATABASE_URL)%' }
    entity_managers:
        default: { entity_dirs: ['%kernel.project_dir%/src/Entity'] }
        billing: { entity_dirs: ['%kernel.project_dir%/src/Billing/Entity'], batch_size: 50 }
```

| Option | Default | Effect |
| --- | --- | --- |
| `entity_managers.*.connection` | the connection with the same name, or the only one | The connection of the entity manager |
| `entity_managers.*.batch_size` | `100` | The batch size of [batch fetching](/guide/n-plus-one#batch-size) |
| `default_entity_manager` | the first declared | The one behind `EntityManagerInterface` |
| `connection_idle_ttl` | `60` | Worker mode: seconds of inactivity before the connection is checked (`0`: on every request) |
| `metamodel.dir` | `%kernel.cache_dir%/prism/metamodel` | Where the metamodel is generated, one subdirectory per entity manager |
| `tenancy` | | [Multi-tenancy](/guide/multi-tenancy#in-symfony) |

An unknown option is refused when the container compiles, with a message that names it.

## Create entities

```bash
bin/console make:entity Order
```

`make:entity` creates the entity and its repository, then adds fields and relations one by one. For a relation, it offers the inverse side and writes both sides (`inversedBy`, `mappedBy`); a collection is initialized in the constructor. On an existing entity, it only adds the new members: your code and comments are kept. The metamodel is regenerated on the next request. Next to DoctrineBundle, during a migration from Doctrine, the commands are named `make:prism:entity` and `make:prism:migration`.

The mapping itself is described in [Mapping entities](/guide/mapping).

## Migrations

```bash
bin/console make:migration            # or db:migrations:diff
bin/console db:migrations:lint        # dangerous operations (22 rules)
bin/console db:migrations:migrate
```

With several entity managers, every `db:*` command takes `--em=billing`. See [Migrations](/guide/migrations).

## Inject

`EntityManagerInterface`, `Connection` and the repositories are autowired; a named entity manager is injected by the name of the argument:

```php
<?php

namespace App\Service;

use App\Repository\OrderRepository;
use Prism\Orm\EntityManagerInterface;

final class Billing
{
    public function __construct(
        private EntityManagerInterface $em,                    // the default one
        private EntityManagerInterface $billingEntityManager,  // the "billing" one
        private OrderRepository $orders,                       // the same instance as $em->getRepository(Order::class)
    ) {
    }
}
```

A repository is autowired when it extends `EntityRepository` and is named in `#[Prism\Entity(repositoryClass: ...)]`. It reaches its entity manager with `getEntityManager()`.

## Query

The metamodel (a class `Order_` per entity) is generated when the cache warms up, and regenerated in development when an entity changes:

```php
$o = Order_::as('o');
$orders = $em->query($o)->orderBy($o->placedAt->desc())->paginate($page, 20);

$i = Invoice_::as('i');
$o = $i->order->as('o');
$c = $o->customer->as('c');
$rows = $em->query($i)->join($o)->join($c)
    ->groupBy($c->id, $c->name)
    ->selectInto(CustomerRevenue::class, customer: $c->name, invoices: $i->id->count(), total: $i->total->sum(), lastInvoice: $i->issuedAt->max())
    ->getResult();
```

See [Typed queries](/guide/query-builder), and [PHPStan](/guide/phpstan) to check them in CI.

## Controllers

An argument typed with an entity is loaded from the route: from `{id}`, or from the route parameter named after the argument. A missing entity is a 404; a nullable argument receives `null` instead.

```php
<?php

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Order;
use Prism\Bundle\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController extends AbstractController
{
    #[Route('/orders/{id}')]
    public function order(Order $order): Response
    {
        return new Response($order->reference);
    }

    #[Route('/invoices/{number}')]
    public function invoice(#[MapEntity(mapping: ['number' => 'number'])] Invoice $invoice): Response
    {
        return new Response($invoice->number);
    }
}
```

`#[MapEntity]` also takes `id:`, `entityManager:` and `disabled: true`.

## Events and listeners

Each entity manager has its own event dispatcher: Prism's events (`PrePersistEvent`, `PostFlushEvent`...) are listened to with `#[AsPrismListener]`, not `#[AsEventListener]`:

```php
<?php

namespace App\EventListener;

use Prism\Bundle\Attribute\AsPrismListener;
use Prism\Orm\Event\PostFlushEvent;

#[AsPrismListener(entityManager: 'billing')]
final class InvoiceExport
{
    public function __invoke(PostFlushEvent $event): void
    {
    }
}
```

An entity listener, named in `#[Prism\EntityListeners]`, becomes a service with its dependencies through `#[AsEntityListener]`. A custom type, a class that extends `Prism\Dbal\Type\Type`, is registered automatically.

## Validation and security

The `#[UniqueEntity]` constraint checks uniqueness with the right entity manager, on fields and relations:

```php
#[Prism\Entity]
#[UniqueEntity(fields: ['number'])]
final class CreditNote
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20, unique: true)]
    public string $number;
}
```

The `prism` user provider loads users from an entity:

```yaml
# config/packages/security.yaml
security:
    providers:
        users:
            prism: { class: App\Entity\User, property: email }
```

Without `property`, the repository implements `Prism\Bundle\Security\UserLoaderInterface`. A password hash is upgraded by the repository if it implements `PasswordUpgraderInterface`.

## Profiler

In development, the Prism panel shows the queries and their duration, the **N+1 queries detected, with the line of your code that caused them**, the batch loads, and the changeset of each flush. The **Tenant** and **Audit** panels show the tenant of the request and the audit rows it wrote. Query recording stops at 1,000 queries per request.

In production, neither the panels, nor the query recording, nor the N+1 detector exist in the container.

## Worker mode and Messenger

Under FrankenPHP or RoadRunner, there is nothing to do: between two requests, the entity managers are cleared, a transaction left open is rolled back, the tenant is forgotten, and a lost connection is reconnected at the start of the next request, never in the middle of a transaction. The demo serves 10,000 requests from one worker without memory growth.

For Messenger, the `prism_reset` middleware does the same between two messages:

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware: [prism_reset]
```

## Embeddings

An `#[EmbeddedVector]` field is computed from its source fields by an embedding provider, named by the attribute (`model: 'default'`). The bundle registers the providers by name:

```yaml
prism:
    embeddings:
        scheduler: auto        # messenger | synchronous | deferred
        batch_size: 64         # texts per provider call, ids per message
        providers:
            default:
                openai: { base_url: 'http://ollama:11434/v1', model: 'nomic-embed-text:v1.5', dimensions: 768 }
            legacy:
                service: App\Embedding\LegacyProvider
```

The `openai` form needs `composer require prism-orm/embedding-openai symfony/http-client`. It speaks the `/v1/embeddings` API of OpenAI, Mistral, Ollama, vLLM, LocalAI and Text Embeddings Inference; give `api_key` for a hosted one. Any service that implements `EmbeddingProvider` is also registered: under the name of its `#[AsEmbeddingProvider('name')]` attribute, or under its service id.

When a source field changes, the flush marks the vector stale; once the transaction is committed, the scheduler takes over:

- `messenger`: dispatches one `ComputeEmbedding` message per batch of ids. Route it to an asynchronous transport. The handler processes the messages in batches, in the tenant of the rows, and a failure is retried by Messenger. `auto` picks it when a message bus exists.
- `synchronous`: computes the vectors at once, after the flush. For scripts and small applications.
- `deferred`: does nothing; run `db:embeddings:compute`, by hand or from a cron. `auto` picks it without Messenger.

```yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Prism\Bundle\Messenger\ComputeEmbedding: async
```

A stale vector is left out of `nearest()` until it is computed again. At `cache:warmup`, an unknown provider name, or a provider whose dimension is not the one of the column, is refused with the other mapping errors.

## Test

The `prism-orm/test-pack` package brings factories, a database reset, and query counting:

```php no-run
final class OrderPagesTest extends WebTestCase
{
    use ResetDatabase;          // the schema is created once; each test runs in a transaction rolled back at the end
    use QueryCountAssertions;

    public function testList(): void
    {
        $client = static::createClient();
        OrderFactory::new()->many(30);

        $this->assertQueryCount(5, fn () => $client->request('GET', '/orders'));
    }
}
```

A factory describes a typical entity; each test sets only what matters to it:

```php
use Prism\TestPack\Factory\Factory;

/** @extends Factory<Order> */
final class OrderFactory extends Factory
{
    protected static function class(): string
    {
        return Order::class;
    }

    protected function defaults(): array
    {
        return [
            'reference' => fn (int $n): string => sprintf('ORD-%05d', $n),
            'placedAt' => new \DateTimeImmutable('2026-03-01 10:00'),
            'status' => 'open',
            'customer' => CustomerFactory::new(),
        ];
    }
}
```

`many(30)` creates 30 orders in one flush. `#[WithRealTransactions]` disables the surrounding transaction for a test that needs real commits; under MySQL and MariaDB, a test that runs DDL needs it too. In a test of tenanted entities, set the tenant first: `static::getContainer()->get(StackTenantContext::class)->setTenantId(1)`.

## Known limitations

- A custom type converts its values, but cannot declare its column yet (`db:schema:create`).
- An entity manager accepts an entity of another entity manager and queries it in its own database. The controller argument resolver, `#[UniqueEntity]` and the test pack pick the right one.
