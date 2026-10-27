# Typed queries

The typed query builder writes queries without a single field-name string. For each entity, Prism generates a metamodel class, `Order_` for `Order`, whose properties are typed fields; a query is built from them and returns typed results.

```php
$o = Order_::as('o');

$orders = $em->query($o)
    ->where($o->status->eq('open'))
    ->andWhere($o->placedAt->gte(new \DateTimeImmutable('2026-03-01')))
    ->orderBy($o->placedAt->desc())
    ->limit(10)
    ->getResult(); // list<Order>
```

`Order_::as('o')` names the entity in the query; `$em->query($o)` starts a query whose results are `Order` entities.

## The metamodel

The metamodel classes are generated from your entities, and never written by hand:

- **In Symfony**, they are generated in `var/cache/<env>/prism/metamodel` when the cache warms up, and regenerated in development when an entity changes.
- **Without Symfony**, run `vendor/bin/prism db:metamodel:generate` after changing an entity, and register the autoloader once, in `prism.php` for example:

```php no-run
use Prism\Orm\Query\Metamodel\MetamodelAutoloader;

MetamodelAutoloader::register(__DIR__ . '/var/cache/prism/metamodel');
```

The generated files are not versioned. For your IDE, the command also writes a stub (`.prism/metamodel.php`) and PhpStorm metadata, so `Order_` and its fields autocomplete.

Each field has the methods that make sense for its type, and only accepts values of that type:

| Field | Comparisons |
| --- | --- |
| string | `eq`, `neq`, `lt`, `lte`, `gt`, `gte`, `in`, `notIn`, `between`, `like` |
| int, float, decimal | `eq`, `neq`, `lt`, `lte`, `gt`, `gte`, `in`, `notIn`, `between` |
| date and time | the same, with `\DateTimeImmutable` values |
| bool | `eq`, `neq` |
| enum | `eq`, `neq`, `in`, `notIn`, with cases of the enum |
| relation | `eq`, `in` (entities or identifiers), `isNull`, `isNotNull` |

Every field also has `isNull()`, `isNotNull()`, `asc()` and `desc()`. A decimal compares with a string (`$i->total->gt('100.00')`), never a float. Passing a value of the wrong type is a PHP type error, and PHPStan reports it before the code runs.

## Conditions

```php
use Prism\Orm\Query\Condition;

$c = Customer_::as('c');

$customers = $em->query($c)
    ->where(Condition::any(
        $c->city->in(['Lyon', 'Nantes']),
        $c->email->like('%@globex.example.test'),
    ))
    ->andWhere($c->name->eq('Customer 1')->not())
    ->orderBy($c->name->asc())
    ->getResult();
```

`where()` sets the condition, `andWhere()` and `orWhere()` add to it. `Condition::all()` and `Condition::any()` group conditions with `AND` and `OR`, and `not()` negates one. Every value is bound as a parameter: no value ever reaches the SQL text.

## Joins

A relation of the metamodel becomes a joined entity with `as()`:

```php
$o = Order_::as('o');
$c = $o->customer->as('c');
$l = $o->lines->as('l');

$orders = $em->query($o)
    ->join($c)
    ->join($l)
    ->where($c->city->eq('Lyon'))
    ->andWhere($l->quantity->gte(5))
    ->getResult();
```

`join()` and `leftJoin()` filter or sort on the joined entity; the result is still a list of distinct `Order` entities, even when several lines of an order match. Every kind of relation can be joined, `ManyToMany` included.

To load the joined entities in the same query, fetch-join them:

```php
$o = Order_::as('o');
$c = $o->customer->as('c');
$l = $o->lines->as('l');

$orders = $em->query($o)
    ->fetchJoin($c)
    ->leftFetchJoin($l)
    ->where($o->status->eq('shipped'))
    ->getResult();

foreach ($orders as $order) {
    echo $order->customer->name, ': ', count($order->lines), "\n"; // no query
}
```

A fetch-joined relation is marked as loaded. Without fetch joins, the relations load in batches when you first use them; `withoutBatchFetch()` turns that off for the entities of one query.

## Aggregates and projections

To read computed values instead of entities, select them into a DTO. Each named argument of `selectInto()` is a constructor parameter of the DTO:

```php
final readonly class CityRevenue
{
    public function __construct(
        public ?string $city,
        public int $invoices,
        public ?string $total,
        public ?\DateTimeImmutable $lastInvoice,
    ) {
    }
}

$i = Invoice_::as('i');
$o = $i->order->as('o');
$c = $o->customer->as('c');

$rows = $em->query($i)
    ->join($o)
    ->join($c)
    ->groupBy($c->city)
    ->having($i->id->count()->gte(2))
    ->orderBy($i->total->sum()->desc())
    ->selectInto(CityRevenue::class, city: $c->city, invoices: $i->id->count(), total: $i->total->sum(), lastInvoice: $i->issuedAt->max())
    ->getResult(); // list<CityRevenue>
```

| Aggregate | On | Result |
| --- | --- | --- |
| `count()`, `countDistinct()` | any field | int |
| `sum()` | int, decimal, float | the type of the field |
| `avg()` | int, decimal | decimal, as a string |
| `avg()` | float | float |
| `min()`, `max()` | numbers, strings, dates | the type of the field |

An aggregate has the same comparisons as a field of its type, for `having()` and `orderBy()`. `sum`, `avg`, `min` and `max` of an empty group are `null`, so their DTO parameters are nullable.

The DTO is checked before the query runs: a selection without a matching parameter, or a required parameter without a selection, throws `QueryException`; PHPStan reports both, and type mismatches, during analysis. A field selected next to an aggregate must be grouped, on every database. Projections bypass the unit of work: nothing enters the identity map.

## JSON fields

A path inside a `json` column is read with `path()`, then typed explicitly:

```php no-run
$p = Product_::as('p');

$red = $em->query($p)
    ->where($p->attributes->path('color')->asString()->eq('red'))
    ->andWhere($p->attributes->path('sizes', 0)->asInt()->gte(38))
    ->getResult();
```

`asString()`, `asInt()` and `asDecimal()` give an expression with the comparisons of that type. A missing key or a JSON `null` is `NULL` on all four databases.

## Pagination

```php
$o = Order_::as('o');

$page = $em->query($o)
    ->where($o->status->eq('open'))
    ->orderBy($o->placedAt->desc())
    ->paginate(page: 1, perPage: 10);

echo $page->total(), ' orders, ', $page->pageCount(), " pages\n";
foreach ($page as $order) {
    echo $order->reference, "\n";
}
```

The page holds `items`, `page` and `perPage`, and has `total()`, `pageCount()`, `hasNext()` and `hasPrevious()`. The total runs a `COUNT` query only when you ask for it.

Pagination needs a sort: the identifier is appended as the last sort key, so that every row appears on exactly one page. With a fetch-joined collection, Prism reads the identifiers of the page first, then their entities with complete collections: a page of 10 orders has 10 orders, whatever their number of lines.

## Results

| Method | Returns |
| --- | --- |
| `getResult()` | `list<T>` |
| `getOneOrNull()` | `?T`; throws `NonUniqueResultException` if there are several |
| `paginate($page, $perPage)` | `Paginator<T>` |
| `selectInto(Dto::class, ...)->getResult()` | `list<Dto>` |

Soft-deleted entities are left out; `withDeleted()` includes them. When a query needs SQL that the builder does not cover, `toSql()` and `getParameters()` show what it generates, and `getConnection()` gives the DBAL connection to run your own. Raw SQL on a tenanted table still has to filter on the tenant: see [multi-tenancy](/guide/multi-tenancy#raw-sql).
