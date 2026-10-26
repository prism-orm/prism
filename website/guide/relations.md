# Relations

Prism supports the four kinds of relations: `ManyToOne`, `OneToMany`, `OneToOne` and `ManyToMany`. A relation is a property typed with the target entity, or with `Collection` for the "many" side.

## Many to one

The side that holds the foreign key is a `ManyToOne`:

```php
#[Prism\Entity]
final class Project
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 80)]
    public string $name;

    /** @var Collection<int, Task> */
    #[Prism\OneToMany(targetEntity: Task::class, mappedBy: 'project')]
    public Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
    }
}

#[Prism\Entity]
final class Task
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 120)]
    public string $title;

    #[Prism\ManyToOne(inversedBy: 'tasks', onDelete: Prism\OnDelete::Cascade)]
    public Project $project;

    #[Prism\ManyToOne]
    public ?Customer $requestedBy = null;
}
```

The target is read from the property type. The foreign key column is named after the property: `project_id`, `requested_by_id`. It is nullable when the type is: `?Customer`. Its options:

| Option | Default | Effect |
| --- | --- | --- |
| `inversedBy` | none | The property of the target that holds the other side |
| `onDelete` | `OnDelete::Restrict` | What the database does when the target row is deleted: `Restrict`, `Cascade`, `SetNull` or `NoAction` |
| `joinColumn` | `<property>_id` | The name of the foreign key column |
| `foreignKey` | generated | The name of the foreign key constraint |
| `cascade`, `fetch`, `batchSize`, `batch` | | See below |

## One to many

The other side, `Project::$tasks` above, is a `OneToMany`. It has no column: it is the list of the tasks whose `project` is this project. `targetEntity` and `mappedBy` are required, because a `Collection` type does not say what it contains.

A collection property is typed `Collection`, and initialized with an `ArrayCollection` in the constructor. When Prism loads the entity, it puts its own collection in the property, which loads the elements on first access.

```php
$order = $em->getRepository(Order::class)->findOneBy(['reference' => 'ORD-00001']);

echo count($order->lines);       // loads the lines
foreach ($order->lines as $line) {
    echo $line->quantity, "\n";
}
$first = $order->lines->first();
```

A `Collection` is iterable, countable and array-accessible, and has `add()`, `removeElement()`, `contains()`, `get()`, `set()`, `remove()`, `clear()`, `isEmpty()`, `first()` and `toArray()`.

### The owning side

Only the owning side is saved: the `ManyToOne`, which holds the foreign key. Adding a task to `$project->tasks` writes nothing; setting `$task->project` does. Keep both sides in step in your code:

```php
$order = $em->getRepository(Order::class)->findOneBy(['reference' => 'ORD-00002']);

$line = new OrderLine();
$line->order = $order;           // the owning side: this is what is saved
$line->product = $em->getRepository(Product::class)->findOneBy(['sku' => 'SKU-002']);
$line->quantity = 1;
$line->unitPrice = $line->product->price;
$order->lines->add($line);       // the inverse side: keeps the object graph consistent

$em->persist($line);
$em->flush();
```

When only the inverse side changed, Prism publishes an `InverseSideChangedEvent` at flush; the Symfony bundle logs it as a warning in development.

### Sorting and indexing

```php no-run
/** @var Collection<string, Product> */
#[Prism\ManyToMany(targetEntity: Product::class, indexBy: 'sku')]
#[Prism\OrderBy(['name' => Prism\Order::Asc])]
public Collection $products;
```

`#[Prism\OrderBy]` sorts a `OneToMany` or `ManyToMany` collection in SQL when it loads, and the order is kept when the collection is loaded in a batch. `indexBy` keys the collection by a field of the target: `$priceList->products['SKU-042']`.

## One to one

A `OneToOne` is a `ManyToOne` with a unique foreign key. The owning side holds the column and `inversedBy`; the inverse side has `mappedBy` and no column:

```php
#[Prism\Entity]
final class Employee
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\OneToOne(mappedBy: 'employee')]
    public ?Badge $badge = null;
}

#[Prism\Entity]
final class Badge
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\OneToOne(inversedBy: 'badge')]
    public Employee $employee;
}
```

The inverse side cannot be a lazy reference: Prism would have to query to know whether a badge exists. It is loaded right after the entities, for all of them in one query.

## Many to many

A `ManyToMany` goes through a join table. The owning side, which declares `inversedBy` or no other side at all, writes it:

```php
#[Prism\Entity]
final class PriceList
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    /** @var Collection<int, Product> */
    #[Prism\ManyToMany(targetEntity: Product::class)]
    #[Prism\JoinTable(name: 'price_list_products')]
    public Collection $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
    }
}
```

The join table is named `<owner>_<target>` by default (`price_list_product`), with the columns `price_list_id` and `product_id`, and `ON DELETE CASCADE` on both: deleting a price list deletes its links, not its products. `#[Prism\JoinTable]` renames them. The inverse side, with `mappedBy`, only reads.

## Cascades and orphans

A new entity reachable from a managed one must be persisted. If it is not, the flush throws `UnpersistedRelationException`, which names the entity, the field and the path. To persist it with its parent:

```php no-run
/** @var Collection<int, OrderLine> */
#[Prism\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: [Prism\Cascade::Persist], orphanRemoval: true)]
public Collection $lines;
```

| Option | Effect |
| --- | --- |
| `cascade: [Prism\Cascade::Persist]` | `persist()` on the parent persists the new entities it reaches |
| `cascade: [Prism\Cascade::Remove]` | `remove()` on the parent removes the related entities, with their events |
| `cascade: [Prism\Cascade::Detach]` | `detach()` on the parent detaches them |
| `orphanRemoval: true` | An entity removed from the collection is deleted at the flush |

`cascade: Remove` is applied by Prism, entity by entity, with lifecycle events; `onDelete: Cascade` is applied by the database, without them. The mapping validator warns when both are set on the same relation. An orphan cannot be attached to another parent in the same flush.

## Lazy loading

A relation is loaded when you first use it, never before:

- a `ManyToOne` or owning `OneToOne` is a reference: an object of the target class whose identifier is readable without a query;
- a collection loads its elements on first access.

```php
$order = $em->getRepository(Order::class)->findOneBy(['reference' => 'ORD-00003']);

echo $order->customer->id;   // no query: the identifier is known
echo $order->customer->name; // loads the customer
```

There is no proxy class: references are PHP 8.4 lazy ghosts, so a `final` entity works. The `fetch` option changes when a relation loads:

| `fetch` | Effect |
| --- | --- |
| `Fetch::Lazy` (default) | On first access |
| `Fetch::Eager` | Right after the query that loads the owner, in one query for all of them |
| `Fetch::ExtraLazy` | For collections: `count()` runs a `SELECT COUNT(*)` without loading the elements |

When a relation is loaded for one entity, Prism loads it for every entity of the same query at once, in batches of 100. This is what keeps loops free of N+1 queries; `batchSize` changes the batch size of a relation, and `batch: false` turns batching off for it.
