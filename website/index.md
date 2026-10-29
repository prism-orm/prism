---
layout: home

hero:
    name: Prism ORM
    text: The PHP ORM for business applications
    tagline: N+1 queries prevented by default, queries checked by PHPStan, multi-tenancy that fails closed. Built on PHP 8.4.
    image:
        src: /logo.svg
        alt: Prism
    actions:
        - theme: brand
          text: Get started
          link: /guide/getting-started
        - theme: alt
          text: Why Prism?
          link: /guide/introduction
        - theme: alt
          text: GitHub
          link: https://github.com/prism-orm/prism

features:
    - icon: ⚡
      title: No more N+1 queries
      details: Lazy relations load in batches. Looping over 100 orders and their customer costs 2 queries, not 101, without a single join written by hand.
    - icon: 🛡️
      title: Typed queries, checked by PHPStan
      details: A generated metamodel replaces field-name strings. A typo or a value of the wrong type is an error in your IDE, not in production.
    - icon: 🏢
      title: Multi-tenancy and audit, built in
      details: Every query is filtered by the current tenant and raw SQL is checked. No tenant, no query. Changes are audited in the same transaction.
    - icon: 🔎
      title: Vector and hybrid search
      details: Store embeddings next to your data. nearest() and hybridSearch() search by meaning and by words, after the tenant filter, and stale vectors are recomputed after the commit.
---

<div class="vp-doc home-examples">

## Loop over relations without thinking about it

Every entity loaded by one query belongs to a cohort. When you touch a lazy relation of one of them, Prism loads the same relation for the whole cohort in one `IN (...)` query.

```php
$orders = $em->getRepository(Order::class)->findAll();

foreach ($orders as $order) {
    echo $order->customer->name, "\n"; // every customer is loaded with the first one
}
```

## Queries without strings

```php
$o = Order_::as('o');

$orders = $em->query($o)
    ->where($o->status->eq('open'))
    ->orderBy($o->placedAt->desc())
    ->limit(20)
    ->getResult(); // list<Order>
```

`Order_` is generated from your entity. Rename a field and every query that uses it stops compiling in PHPStan.

## One database, many customers

```php
// the current tenant is Acme: only Acme's invoices, here and in every lazy load
$invoices = $em->getRepository(Invoice::class)->findAll();

// every tenant at once: only explicitly
$count = $tenants->runAsSystem(fn () => $em->getRepository(Invoice::class)->count());
```

Without a current tenant, a query on a tenanted entity throws. There is no implicit "all tenants" mode.

</div>
