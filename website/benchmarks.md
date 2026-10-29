# Benchmarks

Prism is measured against Doctrine ORM 3 on four scenarios: hydration, flush, the N+1 pattern and a long-running worker. Every figure is published, including those where Doctrine is faster. The code, the method and the raw results are in [`tools/bench/doctrine`](https://github.com/prism-orm/prism/tree/main/tools/bench/doctrine) ([results](https://github.com/prism-orm/prism/blob/main/tools/bench/doctrine/RESULTS.md)).

## Summary

| Scenario | Result |
| --- | --- |
| N+1: 100 orders, their customer and their lines | Prism 4.4 to 5.6 times faster than Doctrine by default, 3 queries instead of 168; on par with a join written by hand |
| Flush of 1,000 UPDATE | Prism 5 % faster under MySQL, 17 % under PostgreSQL |
| Flush of 1,000 INSERT | Doctrine 15 % faster under MySQL, equal under PostgreSQL |
| Hydration of 10,000 entities | Doctrine 6 to 10 % faster, with about 15 % less memory |
| Worker, one write per request | Doctrine 1.9 times faster under MySQL, Prism 1.4 times faster under PostgreSQL; no memory growth on either side |

## Method

- **Same conditions**: same PHP process settings, same database, same tables and same data, created from a fixed seed. Both ORMs map the entities with attributes, cache their metadata, use PHP 8.4 native lazy objects, and run without SQL logger, profiler or second-level cache.
- **Same entities**: each entity is written twice, once per ORM, with the same fields and types; a `decimal` is a string on both sides.
- **Durations** are measured by PHPBench: median of 10 iterations, after a warm-up. **Memory** is read with `memory_get_usage()` inside the scenario process.
- **Machine**: Intel Xeon Gold 5218 (8 cores available), 7.7 GB, PHP 8.4.27 CLI with opcache and without JIT, MySQL 8.4 and PostgreSQL 16 in Docker on the same host. Doctrine ORM 3.7.5 with DBAL 4.5.0. The host is not dedicated: a figure can move by 10 to 20 % from one run to another.

To run them again, from a clone of the repository:

```bash
make up
make bench-doctrine
```

## N+1

100 orders are read, then the customer (ManyToOne) and the lines (OneToMany) of each one. Times in milliseconds.

| | MySQL | PostgreSQL | Queries |
| --- | --- | --- | --- |
| Prism, default | 21.9 | 18.3 | 3 |
| Doctrine, default | 97.0 | 103.1 | 168 |
| Doctrine, hand-written join | 21.9 | 18.6 | 1 |

Prism loads the lazy relations of all the orders in one query per relation: see [Avoiding N+1 queries](/guide/n-plus-one). Doctrine reaches the same time once the join is written by hand.

## Flush

One `flush()` of 1,000 new entities, then one `flush()` after changing a field on 1,000 managed entities. Times in milliseconds.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| 1,000 INSERT | 156.7 | 136.3 | 186.4 | 193.0 |
| 1,000 UPDATE | 127.2 | 133.4 | 104.6 | 125.9 |

## Hydration

`findAll()` of 10,000 entities of a typical ERP row (text, amount, quantity, boolean, date), without relation.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Time | 168.7 ms | 152.0 ms | 122.1 ms | 115.0 ms |
| Peak memory | 22.5 MB | 19.6 MB | 22.1 MB | 19.1 MB |

Both ORMs read the rows one at a time. For each entity, Prism's unit of work keeps the values read and a smaller snapshot of the identifier, the version and the foreign keys, where Doctrine keeps one array.

## Worker

10,000 requests in one process, as in a FrankenPHP or Messenger worker: each one reads 20 orders and their customer, changes the status of one, then calls `flush()` and `clear()`.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Throughput | 184 req/s | 345 req/s | 381 req/s | 264 req/s |
| Memory after 1,000 requests | 1.95 MB | 1.88 MB | 1.91 MB | 1.84 MB |
| Growth up to 10,000 | 0 KB | 0 KB | 0 KB | 0 KB |

Neither ORM keeps anything from one request to the next. Under MySQL, Doctrine is ahead in this scenario; both ORMs send the same statements to the server, and the cause is being investigated before 1.0.

These figures date from 2026-10-29. They are measured again before 1.0, then at each minor version.
