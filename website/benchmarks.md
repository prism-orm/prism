# Benchmarks

Prism is measured against Doctrine ORM 3 on four scenarios: hydration, flush, the N+1 pattern and memory in a long-running worker. Every figure is published, including those where Doctrine is faster. The code, the method and the raw results are in [`tools/bench/doctrine`](https://github.com/IMAMx39/prism/tree/main/tools/bench/doctrine) ([results](https://github.com/IMAMx39/prism/blob/main/tools/bench/doctrine/RESULTS.md)).

## Summary

| Scenario | Result |
| --- | --- |
| N+1: 100 orders, their customer and their lines | Prism 4.6 to 6.1 times faster than Doctrine by default, 3 queries instead of 168; on par with a join written by hand |
| Flush of 1,000 UPDATE | Equal under MySQL, Prism 21 % faster under PostgreSQL |
| Flush of 1,000 INSERT | Doctrine 17 % faster under MySQL, Prism 12 % faster under PostgreSQL |
| Hydration of 10,000 entities | Doctrine 10 to 14 % faster, with about 37 % less memory |
| Worker, 10,000 requests | No memory growth on either side; Doctrine 24 % faster under MySQL, equal under PostgreSQL |

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
| Prism, default | 21.9 | 18.6 | 3 |
| Doctrine, default | 100.5 | 114.1 | 168 |
| Doctrine, hand-written join | 21.3 | 20.0 | 1 |

Prism loads the lazy relations of all the orders in one query per relation: see [Avoiding N+1 queries](/guide/n-plus-one). Doctrine reaches the same time once the join is written by hand.

## Flush

One `flush()` of 1,000 new entities, then one `flush()` after changing a field on 1,000 managed entities. Times in milliseconds.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| 1,000 INSERT | 156.8 | 133.5 | 177.1 | 201.1 |
| 1,000 UPDATE | 127.4 | 127.9 | 105.4 | 133.5 |

## Hydration

`findAll()` of 10,000 entities of a typical ERP row (text, amount, quantity, boolean, date), without relation.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Time | 166.1 ms | 150.7 ms | 124.1 ms | 108.6 ms |
| Peak memory | 26.4 MB | 19.6 MB | 26.7 MB | 19.1 MB |

Doctrine is ahead here. For each entity, Prism's unit of work keeps the values read and a smaller snapshot of the identifier, the version and the foreign keys, where Doctrine keeps one array. Reducing this memory is planned before 1.0.

## Worker memory

10,000 requests in one process, as in a FrankenPHP or Messenger worker: each one reads 20 orders and their customer, changes one, then calls `flush()` and `clear()`.

| | MySQL Prism | MySQL Doctrine | PostgreSQL Prism | PostgreSQL Doctrine |
| --- | --- | --- | --- | --- |
| Throughput | 294 req/s | 365 req/s | 312 req/s | 312 req/s |
| Memory after 1,000 requests | 1.95 MB | 1.88 MB | 1.90 MB | 1.84 MB |
| Growth up to 10,000 | 0 KB | 0 KB | 0 KB | 0 KB |

Neither ORM keeps anything from one request to the next.

These figures date from 2026-10-28. They are measured again before 1.0, then at each minor version.
