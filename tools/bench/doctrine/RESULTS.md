# Prism against Doctrine ORM 3: results

Measured on 2026-10-29, Prism 1.0 in progress (branch `bench/doctrine`, after commit `66336cb`, with row-by-row hydration). The method is decided in ADR 0021; the code is this folder. Every figure is published, including those where Doctrine is faster.

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
| Prism | 1.0 in progress, native lazy ghosts, metadata cached in PSR-6 |
| Databases | MySQL 8.4, PostgreSQL 16 (`pgvector/pgvector:pg16` image) |
| Bench | PHPBench 1.7.0 |

Both ORMs map the same tables with attributes, without SQL logger, profiler or second-level cache. The machine is not dedicated: from one run to another, a figure can move by 10 to 20 %, on both sides. The relative deviation of a series (`rstdev`) is below 3.5 % for every subject.

## Results

Median of 10 iterations, in milliseconds. Lower is better.

### N+1: 100 orders, then their customer (ManyToOne) and their lines (OneToMany)

| | MySQL | PostgreSQL | Queries |
| --- | --- | --- | --- |
| Prism, default (batch fetching) | 21.9 | 18.3 | 3 |
| Doctrine, default (lazy loading) | 97.0 | 103.1 | 168 |
| Doctrine, hand-written join (`addSelect`) | 21.9 | 18.6 | 1 |

Without any change to the code, Prism is 4.4 times faster than Doctrine under MySQL and 5.6 times under PostgreSQL, and on par with the join written by hand.

### Flush

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| INSERT of 1,000 entities, one `flush()` | 156.7 | **136.3** | 186.4 | 193.0 |
| UPDATE of one field on 1,000 managed entities | **127.2** | 133.4 | **104.6** | 125.9 |

### Hydration: `findAll()` of 10,000 entities without relation

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Time | 168.7 | **152.0** | 122.1 | **115.0** |
| Peak memory | 22.5 MB | **19.6 MB** | 22.1 MB | **19.1 MB** |

### Worker: 10,000 requests in one process

Each request reads 20 orders (sorted by id) and their customer, changes the status of the first one, then `flush()` and `clear()`: one real UPDATE per request.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Throughput | 184 req/s | **345 req/s** | **381 req/s** | 264 req/s |
| Memory after 1,000 requests | 1.95 MB | 1.88 MB | 1.91 MB | 1.84 MB |
| Growth from 1,000 to 10,000 | 0 KB | 0 KB | 0 KB | 0 KB |

## Where Doctrine is ahead

- **Worker under MySQL**: with one write per request, Doctrine serves 1.9 times more requests per second; under PostgreSQL, Prism serves 1.4 times more. The cause is not found yet. What is ruled out: the SQL sent (the same `BEGIN`, `UPDATE` and `COMMIT` for both ORMs, counted by the server), Prism's DBAL (15 to 20 µs above raw PDO per query), native prepared statements (emulating them makes Prism slower), and the commit order calculation. The investigation continues with a profiler before 1.0.
- **Hydration**: Doctrine is 6 to 10 % faster and uses about 15 % less memory. Prism reads the rows one at a time, like Doctrine; for each entity, its unit of work keeps the values read and a smaller snapshot of the identifier, the version and the foreign keys, plus two index entries.
- **INSERT under MySQL**: Doctrine is 15 % faster; both are equal under PostgreSQL. Prism spends about 25 % more PHP time per INSERT, and waits less for the database because it reuses its prepared statements.

These figures will be measured again before 1.0, then at each minor version.
