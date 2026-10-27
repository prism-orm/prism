---
examples: sequence
---

# Your first entity

This page walks through the life of one entity: map it, create its table, save it, load it, query it, change it and delete it. It assumes Prism is [installed](/guide/installation).

## Map a class

An entity is a plain PHP class with attributes. Create `src/Entity/Product.php`:

```php
<?php

namespace App\Entity;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Product
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 40)]
    public string $sku;

    #[Prism\Column(length: 120)]
    public string $name;

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price;
}
```

Prism deduces most of the mapping from the PHP types:

- The table is `product`, and each column is named after its property in snake case.
- `string` becomes a `VARCHAR`, `int` an `INTEGER`, `bool` a `BOOLEAN`, `\DateTimeImmutable` a date and time.
- A nullable type (`?string`) makes a nullable column.
- `#[Prism\GeneratedValue]` lets the database generate the identifier: auto-increment under MySQL and MariaDB, a sequence under PostgreSQL.

The price is a `decimal` stored as a PHP `string`: Prism never converts an amount to a `float`, so it is never rounded.

The class is `final` and its properties are public: Prism does not need setters or proxies. Private properties with methods work just as well.

## Create the table

Generate a migration from your entities, then run it:

::: code-group

```console [Symfony]
$ bin/console db:migrations:diff
$ bin/console db:migrations:migrate
```

```console [Without Symfony]
$ vendor/bin/prism db:migrations:diff
$ vendor/bin/prism db:migrations:migrate
```

:::

The migration is a PHP file in `migrations/`. Read it before running it: when a column disappears and another one appears, Prism asks whether it is a rename, so that no data is dropped by mistake.

## Get the entity manager

The `EntityManager` is the entry point: it saves, loads and queries your entities.

::: code-group

```php no-run [Symfony]
// autowired in your controllers and services
public function __construct(private EntityManagerInterface $em) {}
```

```php no-run [Without Symfony]
// the same EntityManager as the console
$em = require __DIR__ . '/prism.php';
```

:::

The examples below use it as `$em`.

## Save

```php
$product = new Product();
$product->sku = 'DESK-01';
$product->name = 'Standing desk';
$product->price = '499.00';

$em->persist($product);
$em->flush();

echo $product->id; // set by the database
```

`persist()` tells Prism to manage the object; `flush()` writes every pending change in one transaction. Call `flush()` once for many changes: Prism orders the `INSERT`, `UPDATE` and `DELETE` statements itself.

## Load

```php
use Prism\Dbal\Query\Order;

$products = $em->getRepository(Product::class);

$desk = $products->findOneBy(['sku' => 'DESK-01']);
$same = $products->find($desk->id);           // the same object: $same === $desk
$cheapest = $products->findBy([], ['price' => Order::Asc], limit: 10);
$count = $products->count();
```

Within one entity manager, an entity is loaded once: `find()` returns the instance you already have instead of a copy.

## Query

For anything beyond equality, use the typed query builder. `Product_` is generated from your entity:

```php
$p = Product_::as('p');

$desks = $em->query($p)
    ->where($p->name->like('%desk%'))
    ->andWhere($p->price->lt('1000.00'))
    ->orderBy($p->price->asc())
    ->getResult(); // list<Product>
```

Each field knows its type: `$p->price->lt(1000)` is refused by PHPStan, because a decimal compares with a string. In Symfony, the metamodel is regenerated when an entity changes; without Symfony, run `vendor/bin/prism db:metamodel:generate`.

## Update

```php
$desk = $em->getRepository(Product::class)->findOneBy(['sku' => 'DESK-01']);
$desk->price = '449.00';

$em->flush();
```

There is no `update()` call: Prism compares each managed entity with its state when it was loaded, and the `UPDATE` contains only the columns that changed. An entity that did not change costs no query.

## Delete

```php
$em->remove($desk);
$em->flush();
```

## Next

- [Prism in Symfony](/bundle/symfony): configuration, controllers, profiler and tests.
- [Console commands](/bundle/commands): every `db:*` command.
