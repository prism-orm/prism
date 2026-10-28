# Coming from Doctrine

Prism keeps the concepts of Doctrine ORM: entities mapped with attributes, an `EntityManager` with `persist()` and `flush()`, a unit of work, repositories, lifecycle callbacks, migrations. Most of an application's code keeps its shape. What changes is the mapping syntax, the way queries are written, and a few rules that Prism enforces where Doctrine lets them go.

## An entity, side by side

::: code-group

```php no-run [Doctrine]
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[ORM\Index(columns: ['issued_at'])]
class Invoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $total;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\ManyToOne(inversedBy: 'invoices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}
```

```php no-run [Prism]
use Prism\Dbal\Query\Order;
use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity(repositoryClass: InvoiceRepository::class)]
#[Prism\Table(name: 'invoices')]
#[Prism\Index(fields: ['issuedAt'])]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue]
    private ?int $id = null;

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $total;

    #[Prism\Column]
    private \DateTimeImmutable $issuedAt;

    #[Prism\ManyToOne(inversedBy: 'invoices', onDelete: Prism\OnDelete::Cascade)]
    private Customer $customer;

    /** @var Collection<int, InvoiceLine> */
    #[Prism\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: [Prism\Cascade::Persist], orphanRemoval: true)]
    #[Prism\OrderBy(['position' => Order::Asc])]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}
```

:::

The differences:

- **No `#[ORM\JoinColumn]`.** The foreign key options are parameters of the relation: `joinColumn`, `onDelete`, `foreignKey`. The nullability comes from the PHP type: `Customer` is `NOT NULL`, `?Customer` is nullable.
- **Indexes name fields**, not columns: `fields: ['issuedAt']`, and they are repeatable attributes instead of a `Table` option.
- **Enums instead of strings**: `Prism\Cascade::Persist`, `Prism\OnDelete::Cascade`, and `Order::Asc`: `Prism\Dbal\Query\Order`, the same sort order as `findBy()`.
- **`#[Column]` deduces more**: `\DateTimeImmutable` needs no type, an identifier needs no `#[Column]`.
- **Collections** come from `Prism\Orm\Collection`, with the same `add()`, `removeElement()`, `contains()`, `toArray()`...
- **The class can be `final`**: Prism generates no proxy class.

## Attributes

| Doctrine | Prism |
| --- | --- |
| `#[ORM\Entity]`, `#[ORM\Table]` | `#[Prism\Entity]`, `#[Prism\Table]` |
| `#[ORM\Id]`, `#[ORM\GeneratedValue]` | the same; `strategy: GenerationStrategy::UuidV7` for UUIDs |
| `#[ORM\Column(type: Types::...)]` | `#[Prism\Column(type: '...')]`, often deduced |
| `#[ORM\JoinColumn]`, `#[ORM\JoinColumns]` | `joinColumn:`, `onDelete:`, `foreignKey:` of the relation |
| `#[ORM\JoinTable]` | `#[Prism\JoinTable]` |
| `#[ORM\ManyToOne]`, `#[ORM\OneToMany]`, `#[ORM\OneToOne]`, `#[ORM\ManyToMany]` | the same names and parameters, `cascade` as enums |
| `#[ORM\OrderBy]` | `#[Prism\OrderBy]`, with `Prism\Dbal\Query\Order` cases |
| `#[ORM\Index]`, `#[ORM\UniqueConstraint]` | the same, on fields |
| `#[ORM\Embeddable]`, `#[ORM\Embedded]` | the same; a nullable embeddable is `null` when its columns are |
| `#[ORM\Version]` | `#[Prism\Version]` |
| `#[ORM\HasLifecycleCallbacks]`, `#[ORM\PrePersist]`... | the same names |
| `#[ORM\EntityListeners]` | `#[Prism\EntityListeners]` |
| `#[ORM\InheritanceType]`, `#[ORM\DiscriminatorMap]` | not supported in 1.0 |
| `#[ORM\Cache]` | not supported in 1.0 |

The [attribute reference](/reference/attributes) lists every parameter.

## Types

| Doctrine | Prism |
| --- | --- |
| `string`, `text`, `integer`, `bigint`, `smallint`, `boolean`, `float`, `json`, `binary` | the same |
| `decimal` | `decimal`, a `string` in PHP, as in Doctrine |
| `datetime_immutable`, `date_immutable`, `time_immutable` | the same |
| `datetime`, `date`, `time` (mutable) | refused: use `\DateTimeImmutable` |
| `guid` | `uuid` |
| `simple_array`, `array`, `object` | no equivalent: use `json` |
| `datetimetz_immutable` | no equivalent: store UTC dates |

Custom types extend `Prism\Dbal\Type\Type`.

## Repositories

::: code-group

```php no-run [Doctrine]
/** @extends ServiceEntityRepository<Invoice> */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }
}
```

```php [Prism]
/** @extends EntityRepository<Invoice> */
final class InvoiceRepository extends EntityRepository
{
}
```

:::

No constructor: a repository named in `repositoryClass` is autowired in Symfony, and `$em->getRepository()` returns the same instance. `find()`, `findBy()`, `findOneBy()`, `findAll()` and `count()` work as in Doctrine; the order argument takes `Prism\Dbal\Query\Order` cases instead of `'ASC'` strings, and PHPStan checks the field names.

## Queries

DQL and its query builder are replaced by the typed query builder:

::: code-group

```php no-run [Doctrine]
$invoices = $em->createQueryBuilder()
    ->select('i')
    ->from(Invoice::class, 'i')
    ->join('i.order', 'o')
    ->where('i.paid = :paid')
    ->andWhere('o.status = :status')
    ->setParameter('paid', false)
    ->setParameter('status', 'shipped')
    ->orderBy('i.issuedAt', 'DESC')
    ->getQuery()
    ->getResult();
```

```php [Prism]
$i = Invoice_::as('i');
$o = $i->order->as('o');

$invoices = $em->query($i)
    ->join($o)
    ->where($i->paid->eq(false))
    ->andWhere($o->status->eq('shipped'))
    ->orderBy($i->issuedAt->desc())
    ->getResult();
```

:::

The structure is the same; the strings are gone. `Invoice_` is generated from the entity: a renamed field breaks the queries that use it in PHPStan, not in production. `addSelect()` with a join becomes `fetchJoin()`; `NEW Dto(...)` becomes `selectInto(Dto::class, ...)`; Doctrine's `Paginator` becomes `paginate()`. See [Typed queries](/guide/query-builder).

There is no textual query language. For SQL that the builder does not cover, use the connection: `$em->getConnection()->executeQuery($sql, $params)`.

## Behaviors that change

- **N+1 queries.** Doctrine loads each lazy relation separately unless you join it; Prism loads it for every entity of the query at once. Loops that relied on `fetch: 'EAGER'` or on joins added for performance can be simplified. See [Avoiding N+1 queries](/guide/n-plus-one).
- **No proxies.** References are PHP 8.4 lazy ghosts: objects of the real class. `instanceof`, `::class` and `get_class()` give the entity class, never a proxy class.
- **The constructor is never called** when an entity is loaded, as in Doctrine. Derived state goes in `#[PostLoad]`.
- **Change detection compares database values**: an entity whose `\DateTimeImmutable` was replaced by an equal one is not updated.
- **A failed flush leaves the unit of work as it was before.** Doctrine closes the entity manager after a failure; Prism rolls back and lets you fix the cause and flush again.
- **Mutable dates are refused**, where Doctrine accepts them.

## Extensions

| Doctrine | Prism |
| --- | --- |
| Gedmo / StofDoctrineExtensions Timestampable, Blameable | `#[Prism\Timestampable]`, `#[Prism\Blameable]` |
| Gedmo SoftDeleteable | `#[Prism\SoftDeletable]` |
| Gedmo Loggable, auditing bundles | `#[Prism\Auditable]` |
| SQL filters (`SQLFilter`) | `EntityFilter`, tag `prism.filter` |
| `#[AsDoctrineListener]`, `#[AsEntityListener]` | `#[AsPrismListener]`, `#[AsEntityListener]` of the Prism bundle |
| `#[MapEntity]` | `#[MapEntity]` of the Prism bundle |
| `#[UniqueEntity]` | `Prism\Bundle\Validator\UniqueEntity` |
| Security `entity` user provider | `prism` user provider |
| DoctrineFixturesBundle, Foundry | the test pack's factories and `ResetDatabase` |

## Commands

| Doctrine | Prism |
| --- | --- |
| `doctrine:database:create`, `drop` | `db:database:create`, `drop` |
| `doctrine:schema:create`, `update`, `drop`, `validate` | `db:schema:create`, `update`, `drop`, `validate` |
| `doctrine:migrations:diff`, `migrate`, `execute`, `status`, `list`, `generate` | `db:migrations:diff`, `migrate`, `execute`, `status`, `list`, `generate` |
| `make:entity`, `make:migration` | the same with Prism, `make:prism:*` next to DoctrineBundle |
| `doctrine:mapping:info` | `db:mapping:info` |
| `dbal:run-sql` | `db:query:sql` |
| `doctrine:mapping:import` | `db:import` |

Prism adds `db:migrations:lint`, which checks pending migrations against the data before they run.

## Migrating an application

1. **Install Prism next to Doctrine.** `composer require prism-orm/bundle`. Its services and commands have their own names (`Prism\Orm\EntityManagerInterface`, `db:*`, `make:prism:*`), so both bundles work side by side while you migrate. Point Prism at the same `DATABASE_URL`.
2. **Get Prism entities.** Either convert your entities' attributes with the tables above, or generate them from the database: `bin/console db:import --namespace='App\Entity' --dry-run`. `db:import` writes `final`, strictly typed entities with their relations, and reports what it approximates; it never overwrites a file without `--force`.
3. **Check the mapping against the database.** `bin/console db:migrations:diff --dry-run` must generate nothing: the entities describe the existing tables exactly. Prism keeps its own migration table, `prism_migration_versions`, and ignores `doctrine_migration_versions`; the existing Doctrine migrations stay in Doctrine's history.
4. **Port the repositories and queries**, one module at a time. PHPStan and the [extension](/guide/phpstan) find the wrong field names.
5. **Port the listeners and extensions** with the table above.
6. **Port the tests** to the test pack: factories, `ResetDatabase`, and `assertQueryCount()` to keep the query counts you gained.
7. **Remove Doctrine.** `composer remove doctrine/doctrine-bundle doctrine/orm`, and generate the next migrations with `db:migrations:diff`.

## Not supported

Some Doctrine features have no equivalent in Prism 1.0, by design or by lack of time: entity inheritance (single table and class table), composite primary keys, DQL strings, XML and YAML mapping, the second-level cache, and `datetimetz` columns. Check that your model does not depend on them before you start.
