# Configuration

Every option of `config/packages/prism.yaml`. An unknown option is refused when the container compiles, with a message that names it. This page is checked against the configuration tree of the bundle.

The complete form, with the default values:

```yaml
prism:
    default_entity_manager: ~             # the first declared entity manager
    connections:
        default:
            url: '%env(resolve:DATABASE_URL)%'
    entity_managers:
        default:
            connection: ~                 # the connection with the same name, or the only one
            entity_dirs: ['%kernel.project_dir%/src/Entity']
            batch_size: 100
            migrations:
                dir: ~                    # migrations/, or migrations/<Name> for another entity manager
                namespace: ~              # App\Migrations, or App\Migrations\<Name>
    connection_idle_ttl: 60
    tenancy:
        type: integer
        guard: ~                          # exception in debug, log otherwise
        resolver: ~                       # one of header, subdomain, user
        loader: ~
    embeddings:
        scheduler: auto                   # messenger if a message bus exists, deferred otherwise
        batch_size: 64
        providers: {}                     # <name>: { service: … } or { openai: { … } }
    metamodel:
        dir: '%kernel.cache_dir%/prism/metamodel'
```

## Short form

With one database and one entity manager, `url`, `entity_dirs` and `migrations` can be written at the root. They declare the `default` connection and entity manager, and cannot be mixed with `connections` and `entity_managers`.

```yaml
prism:
    url: '%env(resolve:DATABASE_URL)%'
    entity_dirs: ['%kernel.project_dir%/src/Entity']
    migrations:
        dir: '%kernel.project_dir%/migrations'
        namespace: 'App\Migrations'
```

### `prism.url`

The URL of the `default` connection. Without `url` and `connections`, Prism uses `%env(resolve:DATABASE_URL)%`, read on first use only.

### `prism.entity_dirs`

The entity directories of the `default` entity manager.

### `prism.migrations.dir`

The migrations directory of the `default` entity manager.

### `prism.migrations.namespace`

The namespace of its migrations.

## Connections

### `prism.connections.<name>.url`

The URL of a named connection, in Symfony's format, with `serverVersion`: `mysql://user:pass@host:3306/db?serverVersion=8.4`. Required.

## Entity managers

### `prism.default_entity_manager`

The entity manager behind `EntityManagerInterface` and `bin/console db:*` without `--em`. Default: the first declared.

### `prism.entity_managers.<name>.connection`

The connection of the entity manager. Default: the connection with the same name, or the only connection if there is one.

### `prism.entity_managers.<name>.entity_dirs`

The directories of its entity classes, at least one. Default: `%kernel.project_dir%/src/Entity`.

### `prism.entity_managers.<name>.batch_size`

The largest number of entities loaded by one `IN` query of [batch fetching](/guide/n-plus-one#batch-size), at least 1. Default: `100`. A relation can set its own with `batchSize`.

### `prism.entity_managers.<name>.migrations.dir`

Its migrations directory. Default: `%kernel.project_dir%/migrations` for the default entity manager, `migrations/<Name>` for the others.

### `prism.entity_managers.<name>.migrations.namespace`

The namespace of its migrations. Default: `App\Migrations`, or `App\Migrations\<Name>`.

## Worker mode

### `prism.connection_idle_ttl`

Under FrankenPHP or RoadRunner: after this many seconds without a query, the connection is checked at the start of the next request, and reconnected if it was lost. `0` checks it on every request. Default: `60`.

## Multi-tenancy

See [Multi-tenancy](/guide/multi-tenancy#in-symfony).

### `prism.tenancy.type`

The type of the `tenant_id` column of every `#[Tenanted]` entity: `integer`, `bigint` or `uuid`. Default: `integer`.

### `prism.tenancy.guard`

What the guard does with raw SQL on a tenanted table without a tenant condition: `exception`, or `log` (a critical log entry). Default: `exception` in debug, `log` otherwise.

### `prism.tenancy.resolver.header`

Read the tenant from this request header, `X-Tenant-Id` for example. Before the firewall. **Only behind a gateway that sets the header itself**: otherwise, any client chooses its tenant.

### `prism.tenancy.resolver.subdomain`

Read the tenant from the subdomain under this domain: `acme` in `acme.example.com` under `example.com`. Before the firewall.

### `prism.tenancy.resolver.user`

`true`: the tenant of the logged-in user, which implements `TenantAwareUserInterface`. After the firewall; the user entity must not be tenanted.

Only one of `header`, `subdomain` and `user` can be set. Without any, the application sets the tenant on the `StackTenantContext` service.

### `prism.tenancy.loader`

The id of a `TenantLoader` service that turns the identifier read by the resolver (a header value, a subdomain) into the tenant identifier. Without it, the identifier read is the tenant, an integer for `integer` and `bigint`.

## Embeddings

The providers and the computation of the `#[EmbeddedVector]` fields. See [Embeddings](/bundle/symfony#embeddings).

### `prism.embeddings.scheduler`

What happens to the vectors a flush made stale, once its transaction is committed: `messenger` dispatches `ComputeEmbedding` messages, `synchronous` computes them at once, `deferred` leaves them to `db:embeddings:compute`. Default: `auto`, which is `messenger` when a message bus exists, `deferred` otherwise. `messenger` without a bus is refused.

### `prism.embeddings.batch_size`

The texts sent to a provider in one call, the ids in one `ComputeEmbedding` message, and the messages the handler processes together. Default: `64`.

### `prism.embeddings.providers.<name>.service`

The id of a service that implements `EmbeddingProvider`, registered under `<name>`: the name that `#[EmbeddedVector(model:)]` gives. A provider is either `service` or `openai`.

### `prism.embeddings.providers.<name>.openai.base_url`

The base URL of an API in the OpenAI format, without `/embeddings`: `https://api.openai.com/v1`, `http://ollama:11434/v1`. Needs `prism-orm/embedding-openai` and `symfony/http-client`.

### `prism.embeddings.providers.<name>.openai.model`

The model sent with each request, and stored with each vector it computes.

### `prism.embeddings.providers.<name>.openai.dimensions`

The dimension of its vectors. It must equal the `dimensions` of the column, checked at `cache:warmup`, and every response is checked against it.

### `prism.embeddings.providers.<name>.openai.api_key`

The API key, sent as a bearer token. `~` for a local server. Use an environment variable: `'%env(OPENAI_API_KEY)%'`.

### `prism.embeddings.providers.<name>.openai.send_dimensions`

`true` sends the dimension with each request, for the models that reduce it (`text-embedding-3-*`). Default: `false`.

### `prism.embeddings.providers.<name>.openai.prefix`

Put before each text. Some models expect a prefix that tells documents from questions: `'search_document: '` and `'search_query: '` for `nomic-embed-text`, as two providers of the same model. Default: `''`.

## Metamodel

### `prism.metamodel.dir`

The directory of the generated [metamodel](/guide/query-builder#the-metamodel); each entity manager generates into `<dir>/<name>`. Default: `%kernel.cache_dir%/prism/metamodel`.
