# Performance and scaling

## Where the time goes

Issuing a B2C invoice in `queue` mode does: store the JSON, validate, lock the device counter, build and
sign the XML, store it, commit, push a queue job. **ZATCA is not called in the request.**

Measured on a developer laptop (Windows, SQLite, PHP 8.4, one process, warm): signing about 4 ms,
complete issuance p50 about 89 ms, most of it database commits. On a server with MySQL/PostgreSQL on fast
disks and Redis queues, expect a lower figure; measure on your own hardware before committing to a
target.

## The one rule: one device = one document at a time

Each document contains the hash of the device's previous document, so documents of one device are
created **strictly one after another**. Concurrent requests for the same device wait for the counter row
lock (up to `counter_lock_wait_ms`, default 2000 ms).

Throughput therefore scales with the **number of devices**:

```
max invoices/second ≈ number of devices × (1000 ms ÷ issuance time per document in ms)
```

| Setup | Recommendation |
|---|---|
| POS terminals | One device per terminal (natural fit) |
| Web shop / API | A pool of devices; spread requests over them (for example by order ID modulo pool size) |
| Nightly ERP batch | `b2b()->submit()` (no signing in the request); workers sign per device in parallel |

::: tip Very high volume
Workloads of many thousands of invoices per second need many devices, several app servers behind a load
balancer, a database sized for the write rate, Redis for queue and cache, and enough queue workers to
keep up with ZATCA's own limits. The load-shedding middleware answers `429`/`503` with `Retry-After`
instead of letting requests pile up into gateway errors (502/504). Benchmark your full stack first;
the package alone cannot guarantee a given rate.
:::

## Tuning knobs

| Setting | Default | Effect |
|---|---|---|
| `performance.signing_key_memory_ttl` | 300 s | Keep decrypted signing keys in worker memory (Octane/long-running workers) |
| `performance.max_in_flight_issuance` | 200 | HTTP API: concurrent issuance requests before `429` |
| `performance.counter_lock_wait_ms` | 2000 | How long a request waits for a busy device |
| `performance.advisory_chain_lock` | false | Extra cache lock around issuance (rarely needed) |
| `queues.high_water_mark` | 5000 | Pending submissions before the API answers `503` |
| `http.max_rps` | 10 | Requests per second to ZATCA |
| `http.max_in_flight_per_device` | 2 | Parallel ZATCA calls per device |

## Infrastructure checklist

- Database: InnoDB (MySQL/MariaDB) or PostgreSQL; enough connections for web + workers.
- Queue and cache: Redis. Not the `sync` driver, not the `file` cache with several servers.
- Artifact disk: shared (S3) when several servers issue invoices.
- PHP: OPcache on; Octane or long-running workers benefit from the in-memory key cache.
- Workers: start with 4 per server; add more if `zatca_outbox` lag grows.
