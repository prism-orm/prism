# Contributing to Prism

Thank you for your interest in Prism. This guide explains how to set up the project, what a pull request needs, and how decisions are made.

## Before you start

- **A bug**: open an issue with the bug report template, ideally with a failing test or a minimal script.
- **A feature or a change of behaviour**: open an issue first and describe the need. Prism has a narrow scope on purpose, and a structural change is agreed in the issue before any code (see below).
- **A security problem**: do not open an issue; follow [SECURITY.md](SECURITY.md).

Out of scope for 1.x, so not accepted as pull requests: a textual query language (DQL), entity inheritance, composite primary keys, Oracle and SQL Server, a second-level cache, mapping in XML or YAML.

## Set up

You need Docker and `make`. Everything runs in containers: PHP 8.4, MySQL 8.4 and 9.7, MariaDB 11.8, PostgreSQL 16 with pgvector.

```bash
git clone https://github.com/prism-orm/prism && cd prism
make build      # PHP image
make up         # databases
make install    # composer install
make ci         # style, PHPStan and tests on the 4 databases
```

`make help` lists every command. The most useful ones:

```bash
make test-unit                  # unit tests, no database
make test-db DB=pgsql           # functional tests on one database: mysql, mysql97, mariadb, pgsql or sqlite
make stan                       # PHPStan, level max
make cs                         # fix the code style
make infection FILTER=packages/orm/src/UnitOfWork/UnitOfWork.php   # mutation testing of one class
make docs-install && make docs-dev   # the documentation site on http://localhost:5173
```

## The repository

| Path | Content |
| --- | --- |
| `packages/dbal` | Connection, platforms, types, schema |
| `packages/orm` | Mapping, unit of work, persisters, queries |
| `packages/migrations` | Migrations and their linter |
| `packages/bundle` | Symfony integration |
| `packages/phpstan` | PHPStan extension |
| `packages/test-pack` | Testing tools for applications |
| `packages/embedding-openai` | Embedding provider for `/v1/embeddings` APIs |
| `website` | Documentation site and its tests |

Each package is published read-only to its own repository (`prism-orm/<package>`). Contributions go to this repository only.

Dependencies between packages are strict: `dbal` depends on PDO and PSR interfaces only, never on Symfony; `orm` uses `symfony/console` only in `src/Console/`; only `bundle` depends on `symfony/framework-bundle`. A new Composer dependency is agreed in an issue first.

## What a pull request needs

1. **Tests.** Every new behaviour comes with tests; every bug fix with a regression test. Unit tests without a database go in `tests/Unit/`, tests on a real database in `tests/Functional/`. A functional test passes on the 4 databases; when a behaviour differs on purpose, the test says so for each platform.
2. **`make ci` passes.** Code style, PHPStan at level max with strict rules (no baseline and no ignored error in `dbal` and `orm`), and the tests on the 4 databases.
3. **Documentation.** A user-visible change updates its page in `website/` or `packages/*/docs/`. Every PHP block of the documentation is run by the tests; mark a fragment that cannot run with `no-run`.
4. **A changelog entry** in `CHANGELOG.md`, under the unreleased version.
5. **Conventional Commits**, with the package as scope: `feat(orm): batch fetching for ManyToOne`, `fix(dbal): sqlite alter table rebuild`.

The unit of work, the schema comparator, the commit order calculator and the batch loader are the most critical classes: write the reference scenarios before changing them, and keep their mutation score (Infection) at 70 % or more.

## Code rules

- `declare(strict_types=1);` in every file, PSR-12 through PHP-CS-Fixer.
- Classes are `final` by default; extension goes through published interfaces.
- Value objects are immutable: `readonly` or `private(set)` properties, `with*()` methods that return a copy.
- No magic methods (`__get`, `__call`, `__set`) in the public API.
- Exceptions are typed per package and implement `Prism\Exception\PrismException`; their messages are in English and give context (class, field, id, file).
- No value is ever concatenated into SQL: bound parameters only, and every identifier goes through `Platform::quoteIdentifier()`. SQL specific to a database lives in its `Platform` class only.
- `decimal` is always a `string`, never a `float`; dates are `\DateTimeImmutable`, never `\DateTime`.
- Generics in PHPDoc (`@template T of object`, `@return list<T>`).

## Public API and compatibility

Every class is marked `@api` or `@internal`. Only `@api` is covered by the backward compatibility promise of [semantic versioning](https://semver.org): changing the signature of an `@api` method needs a deprecation in a minor version first, and the removal in the next major. A test checks that every class is marked and that no `@api` signature exposes an `@internal` class.

## Decisions

A structural choice (a new dependency, a change to an `@api` interface, a new convention) is discussed in an issue and agreed before the code: describe the options you considered and why you prefer one. The maintainers record the decision; a pull request that makes such a choice without that discussion may be closed.

## License

By contributing, you agree that your contribution is released under the [MIT License](LICENSE).
