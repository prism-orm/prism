# Prism against Doctrine ORM 3: results

Measured on 2026-10-28, Prism at commit `5d7ace2` (1.0 in progress). The method is decided in ADR 0021; the code is this folder. Every figure is published, including those where Doctrine is faster.

## Reproduce

```bash
make up
make bench-doctrine                 # MySQL 8.4, then PostgreSQL 16
make bench-doctrine DB=pgsql        # one database
make bench-doctrine REQUESTS=1000   # shorter worker scenario
```

The command installs this project (`composer install`, Doctrine from Packagist, Prism from `packages/` by a `path` repository), creates the tables and a fixed data set, runs the PHPBench suites, counts the queries, runs the worker scenario for each ORM, then drops the tables.

## Hardware and versions

| | |
| --- | --- |
| CPU | Intel Xeon Gold 5218, 8 cores available, 2.30 GHz |
| Memory | 7.7 GB |
| Containers | Docker 29.9, databases and PHP on the same host |
| PHP | 8.4.27 CLI, opcache enabled, JIT off, `memory_limit` 1 GB, pcov off |
| Doctrine | ORM 3.7.5, DBAL 4.5.0, native lazy objects, metadata cached in `symfony/cache` 8.1.9 |
| Prism | `5d7ace2`, native lazy ghosts, metadata cached in PSR-6 |
| Databases | MySQL 8.4, PostgreSQL 16 (`pgvector/pgvector:pg16` image) |
| Bench | PHPBench 1.7.0 |

Both ORMs map the same tables with attributes, without SQL logger, profiler or second-level cache. The machine is not dedicated: from one run to another, a figure can move by 10 to 20 %, on both sides. The relative deviation of a series (`rstdev`) is below 3.2 % for every subject.

## Results

Median of 10 iterations, in milliseconds. Lower is better.

### N+1: 100 orders, then their customer (ManyToOne) and their lines (OneToMany)

| | MySQL | PostgreSQL | Queries |
| --- | --- | --- | --- |
| Prism, default (batch fetching) | 21.9 | 18.6 | 3 |
| Doctrine, default (lazy loading) | 100.5 | 114.1 | 168 |
| Doctrine, hand-written join (`addSelect`) | 21.3 | 20.0 | 1 |

Without any change to the code, Prism is 4.6 times faster than Doctrine under MySQL and 6.1 times under PostgreSQL, and on par with the join written by hand.

### Flush

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| INSERT of 1,000 entities, one `flush()` | 156.8 | **133.5** | **177.1** | 201.1 |
| UPDATE of one field on 1,000 managed entities | 127.4 | 127.9 | **105.4** | 133.5 |

### Hydration: `findAll()` of 10,000 entities without relation

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Time | 166.1 | **150.7** | 124.1 | **108.6** |
| Peak memory | 26.4 MB | **19.6 MB** | 26.7 MB | **19.1 MB** |

### Worker memory: 10,000 requests in one process

Each request reads 20 orders and their customer, changes one, then `flush()` and `clear()`.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Throughput | 294 req/s | **365 req/s** | 312 req/s | 312 req/s |
| Memory after 1,000 requests | 1.95 MB | 1.88 MB | 1.90 MB | 1.84 MB |
| Growth from 1,000 to 10,000 | 0 KB | 0 KB | 0 KB | 0 KB |

## Where Doctrine is ahead

- **Hydration**: Doctrine is 10 % faster under MySQL, 14 % under PostgreSQL, and uses about 37 % less memory. For each entity, Prism's unit of work keeps the values read and a second, smaller snapshot of the identifier, the version and the foreign keys; Doctrine keeps one array. ADR 0022 already removed the conversion of every value back to its database form (from 132–135 ms to 104–110 ms with a dedicated probe); the memory is the next point to work on before 1.0.
- **INSERT under MySQL**: Doctrine is 17 % faster, while Prism is 12 % faster under PostgreSQL. Not explained yet.
- **Worker under MySQL**: Doctrine serves 24 % more requests per second; both are equal under PostgreSQL. Not explained yet. Neither ORM leaks memory.

These figures will be measured again before 1.0, then at each minor version.
