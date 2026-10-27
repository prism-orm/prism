# Avoiding N+1 queries

An N+1 is one query to load a list, then one more query per element to load a relation: 100 orders, then 100 queries for their customers. It is the most common performance problem of ORMs, and it hides in innocent loops and templates.

Prism prevents it by default, and reports the cases it cannot prevent.

## Batch fetching

Every entity loaded by the same query belongs to a **cohort**. When you initialize a lazy relation of one member, Prism loads that relation for every member of the cohort, in one `WHERE id IN (...)` query:

```php
$orders = $em->getRepository(Order::class)->findBy(['status' => 'open']);

foreach ($orders as $order) {
    // the first access loads the customers of all the orders at once
    echo $order->reference, ' ', $order->customer->name, "\n";
}
```

100 orders and their customers cost 2 queries, not 101. Nothing to declare and no join to write: it works for every relation, wherever the access happens, in a controller, a service or a template.

It applies to every kind of relation:

- **To-one relations** (`ManyToOne`, `OneToOne`): the targets of the whole cohort load together.
- **Collections** (`OneToMany`, `ManyToMany`): the collections of the whole cohort load together, each keeping its `#[Prism\OrderBy]` order.

```php
$orders = $em->getRepository(Order::class)->findBy(['status' => 'shipped']);

foreach ($orders as $order) {
    foreach ($order->lines as $line) {       // the lines of all the orders: one query
        echo $line->product->name, "\n";     // the products of all those lines: one query
    }
}
```

The entities loaded by a batch form a cohort of their own, so the next level loads in a batch too: three levels of relations cost three queries, plus the first one.

## Batch size

A batch holds 100 identifiers at most; a larger cohort is loaded in several `IN` queries. Change the size per relation, or globally:

```php no-run
#[Prism\ManyToOne(batchSize: 250)]
public Customer $customer;
```

```yaml
# config/packages/prism.yaml
prism:
    entity_managers:
        default:
            batch_size: 250
```

Without Symfony, the global size is the `batchSize` argument of the `EntityManager` constructor.

## Turning it off

Batch fetching loads relations that a loop may not use: an order list that shows the customer of the first order only would load all of them. Turn it off for one relation, or for the entities of one query:

```php no-run
#[Prism\ManyToOne(batch: false)]
public Customer $customer;
```

```php
$o = Order_::as('o');

$orders = $em->query($o)
    ->where($o->status->eq('open'))
    ->withoutBatchFetch()
    ->getResult();
```

## Loading in the same query

When you know a relation will be used, a fetch join loads it in the query itself, with no second query at all:

```php
$o = Order_::as('o');
$c = $o->customer->as('c');

$orders = $em->query($o)->fetchJoin($c)->getResult();

foreach ($orders as $order) {
    echo $order->customer->name, "\n"; // already loaded
}
```

Batch fetching is the safety net; a fetch join is the optimization for a query you know.

## Large tables

`iterate()` reads a table by pages and forms one cohort per page: relations load in batches within the page, and the cohort is released with the page.

```php
foreach ($em->getRepository(Order::class)->iterate(batchSize: 100) as $order) {
    echo $order->customer->name, "\n"; // one query per page of 100 orders
}
```

## The N+1 detector

Some loops still repeat a query: a `find()` in a loop, or a relation whose batching you turned off. In development, the N+1 detector counts the reads that have the same SQL once normalized (values and `IN` lists replaced by `?`). Beyond 5 identical queries in one HTTP request, it reports them with **the file and line of your code** that started the loop, the first frame outside `vendor/` and Prism.

In Symfony, the detections appear in the Prism panel of the profiler. The detector, like the query recording, does not exist in the production container: it costs nothing there.

Without Symfony, register it as a query observer of the connection, and listen to `NPlusOneDetected`:

```php no-run
use Prism\Dbal\Configuration;
use Prism\Dbal\DriverManager;
use Prism\Orm\Debug\N1Detector;

$detector = new N1Detector($eventDispatcher, threshold: 5);
$connection = DriverManager::fromUrl($url, new Configuration(observers: [$detector]));

// ... at the end of a request or a job:
foreach ($detector->getDetections() as $detection) {
    $logger->warning('N+1: {count} x {sql} at {origin}', ['count' => $detection->count, 'sql' => $detection->sql, 'origin' => $detection->origin]);
}
$detector->reset();
```

Every batch also publishes a `BatchLoadedEvent` (entity, relation, number of entities), which the profiler shows to explain what was loaded and why.

## In tests

The test pack asserts the number of queries of a block of code, so that a regression fails the build:

```php no-run
$this->assertQueryCount(2, function (): void {
    foreach ($this->em->getRepository(Order::class)->findAll() as $order) {
        $order->customer->name;
    }
});
```
