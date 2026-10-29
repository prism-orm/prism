# Prism

A modern PHP ORM for business applications and AI.

Prism keeps the ideas of Doctrine ORM (attribute mapping, an entity manager, a unit of work) and adds what business applications need every day:

- **No more N+1 queries.** Lazy relations load in batches, without a join written by hand: 100 orders, their customer and their lines cost 3 queries, where Doctrine's lazy loading sends 168 ([benchmarks](https://prism-orm.github.io/prism/benchmarks)). A detector names the `file:line` of the loop that would have caused one.
- **Typed queries.** A metamodel generated from your entities and a PHPStan extension: a misspelled field or a wrong type fails in your editor, and results are `list<Order>`, not `array`.
- **Multi-tenancy and audit, built in.** Every query is filtered by the current tenant, and Prism refuses to run without one. Changes are journaled with their author, in the same transaction.
- **Vectors and hybrid search.** Store embeddings next to your business data, index them (MariaDB `VECTOR`, pgvector), and search by meaning and words together with `hybridSearch()`.

## Requirements

- PHP 8.4 or later: lazy relations use the native lazy objects of PHP 8.4, no proxy is generated.
- MySQL 8.0+, MariaDB 11.8+, PostgreSQL 15+, or SQLite 3.40+ for tests and development.
- Symfony 7.4 or 8, for the bundle. The ORM works without Symfony.

## Install

> **Trying the release candidate.** Until 1.0.0 is released, Composer installs the last stable version, 0.9. To get `1.0.0-RC1`, run `composer config minimum-stability RC` and `composer config prefer-stable true` first, then require `prism-orm/bundle:^1.0` (or `prism-orm/orm:^1.0`). A `@RC` flag on the package alone does not reach the packages it depends on.

In a Symfony application:

```bash
composer require prism-orm/bundle
```

Without Symfony:

```bash
composer require prism-orm/orm prism-orm/migrations
```

## A first look

```php
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Product
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 120)]
    public string $name;

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price; // a decimal is always a string, never a float
}
```

```php
$em->persist($product);
$em->flush();

$p = Product_::as('p'); // generated from the entity

$desks = $em->query($p)
    ->where($p->name->like('%desk%'))
    ->andWhere($p->price->lt('1000.00'))
    ->orderBy($p->price->asc())
    ->getResult(); // list<Product>
```

## Documentation

The documentation is at **[prism-orm.github.io/prism](https://prism-orm.github.io/prism/)**. Its sources are in [`website/`](website) and `packages/*/docs/`, and every PHP example in it runs in CI on the four databases.

- [Installation](https://prism-orm.github.io/prism/guide/installation) and [your first entity](https://prism-orm.github.io/prism/guide/getting-started)
- [Coming from Doctrine](https://prism-orm.github.io/prism/guide/from-doctrine)
- The pillars: [avoiding N+1 queries](https://prism-orm.github.io/prism/guide/n-plus-one), [typed queries](https://prism-orm.github.io/prism/guide/query-builder), [multi-tenancy](https://prism-orm.github.io/prism/guide/multi-tenancy), [audit](https://prism-orm.github.io/prism/guide/audit), [vector search](https://prism-orm.github.io/prism/guide/vector-search)
- [Prism in Symfony](https://prism-orm.github.io/prism/bundle/symfony) and [console commands](https://prism-orm.github.io/prism/bundle/commands)
- [Benchmarks against Doctrine ORM 3](https://prism-orm.github.io/prism/benchmarks): method, hardware, code and every figure, including those where Doctrine is faster

The [demo application](https://github.com/prism-orm/demo) is a small ERP built with Prism: clone it and run it in three commands.

## Packages

| Package | Role |
| --- | --- |
| [`prism-orm/dbal`](packages/dbal) | Connection, platforms, types, schema |
| [`prism-orm/orm`](packages/orm) | Mapping, unit of work, repositories, typed queries |
| [`prism-orm/migrations`](packages/migrations) | Versioned migrations and their linter |
| [`prism-orm/bundle`](packages/bundle) | Symfony integration: configuration, profiler, Messenger, security |
| [`prism-orm/phpstan`](packages/phpstan) | PHPStan extension for typed queries and repositories |
| [`prism-orm/test-pack`](packages/test-pack) | Testing tools: factories, `ResetDatabase`, `assertQueryCount()` |
| [`prism-orm/embedding-openai`](packages/embedding-openai) | Embedding provider for OpenAI-compatible `/v1/embeddings` APIs (OpenAI, Mistral, Ollama…) |

This repository holds them all; each one is published read-only to its own repository for Composer.

## Status

Prism is heading to 1.0. The public API is frozen: what is marked `@api` will not break in a 1.x version. The first release candidate is `1.0.0-RC1`; 1.0.0 follows once a release candidate has run in real applications for three weeks without a critical bug. Changes from 0.9 are listed in [UPGRADE-1.0.md](UPGRADE-1.0.md), and every version in the [changelog](CHANGELOG.md).

Not planned for 1.x: a textual query language (DQL), entity inheritance, composite primary keys, Oracle and SQL Server, a second-level cache.

## Contributing

Bug reports, documentation fixes and pull requests are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). To report a security problem, follow [SECURITY.md](SECURITY.md).

To work on Prism, you need Docker and `make`:

```bash
make build && make up && make install
make ci         # code style, PHPStan and tests on MySQL, MariaDB, PostgreSQL and SQLite
```

## License

Prism is released under the [MIT License](LICENSE).
