# BEST_PRACTICES — analytics practices worth adopting, Postgres first

**Phase 3 engineering research · analytics domain.** Version 1.0 · 2026-07-28 · Status: **research, not binding**

What to actually do. Ordered by when QAYD needs it, not by how interesting it is. Sections 1–7 are
PostgreSQL-native and are **the longest part of this document on purpose**: they are the only part QAYD will
act on for years. Sections 8–11 cover the columnar/lakehouse practices that become relevant at the archive
boundary and for internal analytics.

**Extends, does not repeat:** `05_FUTURE_ARCHITECTURE.md` §B (partition scheme and its hazards), §C (the
rollup and the never-cache rules), §G (the reporting ladder). Where a practice below refines one of those,
it says so.

---

## 1. Measure before anything else — `pg_stat_statements` is the precondition for `05`

**The finding that should be uncomfortable:** `05`'s scale-trigger table specifies thresholds like
*"p95 trial-balance latency > 200 ms → add the covering index"* and *"> 500 ms → serve from
`account_period_balances`."* **QAYD cannot currently measure either.** Every threshold in that table is
decorative until query timing is collected.

> **You cannot fire a trigger you cannot measure.** This is the single highest-ROI item in the entire
> analytics domain, and it costs one line of `shared_preload_libraries`.

### What to enable

| Extension / setting | What it gives | Effort |
|---|---|---|
| `pg_stat_statements` | Per-normalised-query: calls, `total_exec_time`, `mean_exec_time`, `stddev_exec_time`, `rows`, `shared_blks_hit/read/dirtied`, `temp_blks_*`, WAL bytes | 1 |
| `auto_explain` (`log_min_duration`, `log_analyze`) | The plan for the slow tail, without reproducing it by hand | 1 |
| `track_io_timing = on` | Splits execution time into CPU vs I/O — the difference between "add an index" and "add RAM" | 0 |
| `log_lock_waits = on` | Catches the lock-contention class that looks like slowness | 0 |

`[DOCS]` https://www.postgresql.org/docs/current/pgstatstatements.html

### The multi-tenant trap in `pg_stat_statements`, and the fix

`pg_stat_statements` **normalises by query text with parameters stripped**. QAYD's trial balance is one
query text for every tenant. So a whale tenant's 8-second scan and 9,999 small tenants' 15 ms scans collapse
into a single row with a mean of ~16 ms — and `05`'s p95 threshold never fires while a customer is timing
out. `[DOCS]` (normalisation is documented behaviour) `[INFERENCE]` (the consequence for QAYD).

Three fixes, use all three:

1. **Tag reporting queries with a SQL comment** — `/* qayd:report=trial_balance */` — and either enable
   `pg_stat_statements.track_utility`-adjacent comment retention or, more reliably, record the timing in the
   application layer per report type.
2. **Record p95 per report type in application telemetry**, keyed by `(report_type, company_id)`. This is the
   metric `05` actually specifies; the database cannot produce it.
3. **Track the shape metric directly.** `05`'s real alarm is
   `max(ledger rows per company_id, fiscal_year_id)`. That is one cheap scheduled query:

   ```
   SELECT max(c) FROM (
     SELECT count(*) c FROM ledger_entries GROUP BY company_id, fiscal_year_id
   ) t;
   ```

   Run it nightly, alert at 200,000 and 1,000,000 — the two thresholds `05` already names. On a partitioned
   table this becomes a partition-wise aggregate and stays cheap.

**Do this at Tier 1.** It costs nothing and it is what makes every later decision in this document evidence
based instead of vibes based.

---

## 2. Reach for the index before the architecture

`05` §C establishes the escalation ladder and observes that step 2 (the covering index) "is often mistaken
for 'not a real fix' and is in fact the highest value-per-point item in the list." The mechanism, which `05`
states but does not decompose:

```
covering index on (company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)
   ├─ index tuple ≈ 22 B  vs  heap tuple ≈ 250 B          → ~11× less I/O
   ├─ index is already sorted by account_id                → GROUP BY needs no sort
   └─ index-only scan: no heap access at all               → if the visibility map cooperates
```

That last conditional is the practice most often missed.

### Practice: verify the index-only scan is actually index-only

```
EXPLAIN (ANALYZE, BUFFERS) SELECT account_id, SUM(signed_base_amount) …
```

Look for `Index Only Scan` **and** `Heap Fetches: 0` (or near it). A non-zero `Heap Fetches` means the
visibility map says those pages are not all-visible, and the "covering" index is doing a heap access per row
anyway.

On an append-only table this is the *normal* state for recent pages, because VACUUM is what sets
all-visible and autovacuum is triggered primarily by dead tuples — of which an insert-only table produces
none.

**Fix:** `autovacuum_vacuum_insert_threshold` / `autovacuum_vacuum_insert_scale_factor` exist for exactly
this case. `[DOCS]` https://www.postgresql.org/docs/current/runtime-config-autovacuum.html Set them per-table
on `ledger_entries` so the visibility map keeps up with insert volume:

```
ALTER TABLE ledger_entries SET (
  autovacuum_vacuum_insert_threshold = 10000,
  autovacuum_vacuum_insert_scale_factor = 0.05
);
```

Numbers are a starting point, not a recommendation — measure `Heap Fetches` before and after.

**This is not in `05` and it is the difference between the covering index working and appearing not to.**

---

## 3. BRIN — use it, but for the right query

`[DOCS]` https://www.postgresql.org/docs/current/brin.html · default `pages_per_range = 128`.

### The honest assessment

BRIN summarises min/max per block range. Its value is entirely a function of **physical correlation** —
how well a column's values track physical row order.

On QAYD's `ledger_entries` `[CODE]`:

| Column | Correlation with physical order | BRIN verdict |
|---|---|---|
| `id` (`GENERATED ALWAYS AS IDENTITY`) | Perfect | Useful, but the PK already covers it |
| `posted_at` (set at insert) | Near-perfect | **Useful** |
| `created_at` (`DEFAULT now()`) | Near-perfect | Useful (redundant with `posted_at`) |
| `entry_date` | Approximate — **back-dating breaks it** | Partly useful; degrades at period-end |
| `company_id` | **None** — tenants interleave | **Useless** |
| `account_id`, `fiscal_year_id` | None to weak | Useless |

### The point that overturns the folklore

Every tenant-facing query in QAYD filters `company_id` first. **BRIN cannot prune by `company_id`**, because
every 128-page range contains rows from nearly every active tenant, so `[min,max]` spans the id space and
nothing is skipped. Therefore:

> **BRIN is not a reporting optimisation for QAYD. It is a maintenance-scan optimisation.**

That is still worth having, because the *unindexed* queries in QAYD are exactly the cross-tenant ones:

| Query with no good index today | BRIN column that helps |
|---|---|
| Hash-chain verification over a time window | `posted_at` |
| `VerifyLedgerProjectionAction` drift scan (`05` §B option c) | `posted_at` / `id` |
| Selecting a date range for archival / `DETACH` | `entry_date` |
| Retention sweeps | `posted_at` |
| Backfill and migration jobs | `id` |

### The practice

```
CREATE INDEX idx_ledger_brin_posted ON ledger_entries USING BRIN (posted_at);
```

- Size on a 400 GB table: a few MB, versus ~9 GB for the btree equivalent. `[INFERENCE]` from the documented
  index-entries = pages / `pages_per_range` relationship.
- Write cost: negligible — BRIN maintenance on append is a min/max update on the current range.
- **Do not drop `idx_ledger_account_date` for it.** BRIN complements the btrees; it never replaces them.
- **Do not expect it to speed up a trial balance.** If someone proposes BRIN as the fix for slow reporting,
  that is the signal they have not looked at the query's predicate.
- Consider `pages_per_range = 32` if you measure that the default is too coarse; smaller ranges mean a bigger
  but more precise index.
- On a partitioned table, BRIN is per-partition and its value drops (the partition already prunes the date
  range) — so **add BRIN before partitioning, and re-evaluate after.**

Effort **1**. Confidence **high** on the mechanism, **medium** on the size of the win, which depends entirely
on how often the maintenance scans run.

---

## 4. Aggregation: incremental projection beats every alternative — and why materialised views are the wrong tool

`05` §C already chose `account_period_balances` maintained by an `AFTER INSERT` trigger, and rejected
materialised views on performance grounds ("`REFRESH` is a full recompute; `CONCURRENTLY` needs a unique
index and still rebuilds everything"). Both true `[DOCS]`
https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html — `CONCURRENTLY` requires at least
one `UNIQUE` index using only column names with no `WHERE` clause, requires the view to be already populated,
and only one refresh may run at a time.

**A stronger objection exists, and it is about correctness, not performance:**

> A materialised view over an RLS-protected table is populated by whoever runs the `REFRESH`, in that
> session's GUC context. `app_current_company_id()` reads `app.current_company_id` `[CODE]`. A maintenance
> job refreshing the view has no tenant set — so the view is either **empty** (fail-closed, if the policy
> denies on NULL, which QAYD's does) or, under a role that bypasses RLS, **cross-tenant**. Neither is a
> usable financial artefact. `[INFERENCE]` — verify against the deployed PostgreSQL version before relying
> on the exact behaviour, but design as if it is true.

And the second-order problem: RLS is a **table** feature. A materialised view is not a table for policy
purposes, so a per-tenant filter must be reapplied at query time over a pre-aggregated blob whose contents
were computed in someone else's tenancy context. That is the "authoritative number from outside the tenant
boundary" failure, wearing a performance hat.

**Practice: in a multi-tenant RLS system, materialised views are for tenant-agnostic data only** —
reference tables, currency lists, platform-wide operational metrics. Never for money, never for anything
`company_id`-scoped.

### What to use instead — the four-step ladder, restated with the tenancy reason attached

| Step | Mechanism | Tenancy-safe? | From |
|---|---|---|---|
| 1 | Live `SUM` via `idx_ledger_account_date` | Yes — RLS applies to the base table | `05` §C |
| 2 | Covering index → index-only scan | Yes — same table | `05` §C |
| 3 | `account_period_balances`, `company_id` column, own RLS policies, in-transaction trigger | **Yes — it is a table** | `05` §C |
| 4 | `report_snapshots`, immutable, tied to `ledger_head_hash` | Yes | `05` §G |
| ✗ | Materialised view | **No** | rejected here |

Step 3's decisive property is that it is **an ordinary table with ordinary RLS**, maintained inside the
posting transaction. It gets the tenant boundary from the same mechanism as everything else, which is
precisely why it is safe and a materialised view is not.

### Incremental-aggregate patterns worth borrowing from the analytics world

- **ClickHouse's `AggregatingMergeTree` + insert-triggered MV** `[DOCS]`
  https://clickhouse.com/docs/materialized-view/incremental-materialized-view — same architecture as QAYD's
  trigger, but not transactional with the base insert. QAYD's version is strictly stronger. Borrow the
  confidence, not the system.
- **Delta Lake checkpoints** `[DOCS]` https://github.com/delta-io/delta/blob/master/PROTOCOL.md — a
  materialised fold over an append-only log that is always recomputable. **Use "checkpoint" as the review
  vocabulary for `account_period_balances`**: it makes the rebuild requirement self-evident in a way that
  "rollup" or "cache" does not.
- **Pinot's star-tree budget** `[DOCS]` https://docs.pinot.apache.org/basics/indexing/star-tree-index —
  when analytic dimensions land, **declare which dimension combinations get a rollup**; never materialise
  the full cube. 5 dimensions is 32 rollups if you are careless and 3 if you are deliberate.
- **TimescaleDB continuous aggregates** `[DOCS]`
  https://docs.timescale.com/use-timescale/latest/continuous-aggregates/ — incrementally-refreshed
  aggregates with invalidation tracking. Conceptually the closest off-the-shelf match to
  `account_period_balances`, and **still a downgrade**, because the refresh is driven by a background policy
  (eventual) rather than by the posting transaction (exact). Take the idea of invalidation *ranges*, not the
  extension.

---

## 5. Query optimisation practices that matter here

### 5.1 Keep the RLS functions `PARALLEL SAFE` — and guard it in CI

`[DOCS]` https://www.postgresql.org/docs/current/sql-createfunction.html — a function is **`PARALLEL UNSAFE`
by default**. `[DOCS]` https://www.postgresql.org/docs/current/parallel-query.html — the planner will not
generate a parallel plan if the query uses a parallel-unsafe function.

RLS policy expressions are part of every query on every tenant table. So:

> If `app_current_company_id()` were `PARALLEL UNSAFE`, **parallel query would be silently disabled on every
> table in QAYD**, and no error would ever be raised. Large reports would simply run single-threaded forever.

**QAYD already got this right.** `[CODE]`
`apps/api/database/migrations/2026_07_27_000009_enable_row_level_security.php`:

```
CREATE OR REPLACE FUNCTION app_current_company_id()
RETURNS BIGINT LANGUAGE sql STABLE PARALLEL SAFE AS $$ … $$;
```

`STABLE` and `PARALLEL SAFE`, correctly. `app_current_user_id()` and `app_is_platform_admin()` likewise.

**The practice is therefore a guardrail, not a fix:** the definitions use `CREATE OR REPLACE`, and a future
migration that re-creates one of them without repeating the markers reverts it to the default and disables
parallelism system-wide, silently. Add a CI catalog assertion in the same family as the existing RLS checks:

```
SELECT proname, provolatile, proparallel
FROM pg_proc
WHERE proname IN ('app_current_company_id','app_current_user_id','app_is_platform_admin');
-- require provolatile = 's' AND proparallel = 's'
```

Effort **1**. Confidence **high**. This belongs in `05`'s "Correctness and tenancy (do these regardless of
tier)" table with threshold **1**.

### 5.2 `work_mem` is per node per worker — set it per role, never globally

`[DOCS]` https://www.postgresql.org/docs/current/runtime-config-resource.html

The classic footgun: `work_mem` is not a per-query budget. It applies to **each** sort, hash join, hash
aggregate or bitmap heap scan node, **and each parallel worker gets its own**. A report with three
memory-hungry nodes and four workers can consume 12 × `work_mem`.

```
   work_mem = 256MB
   × 3 hash/sort nodes
   × 4 parallel workers
   = 3 GB   for ONE query
   × 50 concurrent reporting sessions = an OOM, not a slow query
```

**Practice:** leave the global `work_mem` conservative, and raise it only for the reporting role —

```
ALTER ROLE qayd_reporting SET work_mem = '128MB';
ALTER ROLE qayd_reporting SET max_parallel_workers_per_gather = 4;
```

This dovetails with `05` §G's recommendation to make the split **structurally hard to get wrong** by using
separate connection names (`reporting` vs `analytics`). Separate *roles* is the stronger version of the same
idea: the guarantees and the resource budget then travel together, and neither can be applied to the wrong
connection by accident.

### 5.3 Parallel query — know what disables it

Worth knowing because a report that "should" parallelise and does not is a common and confusing incident:

- any `PARALLEL UNSAFE` function anywhere in the query (see 5.1)
- queries that write, and queries with `FOR UPDATE`/`FOR SHARE`
- cursors and some PL/pgSQL contexts
- `max_parallel_workers_per_gather = 0`
- tables below `min_parallel_table_scan_size` (default 8 MB) — most tenant slices!

That last one is the honest note: **most QAYD tenant-scoped scans are too small to parallelise, and that is
fine.** Parallelism matters for the whale tenant and the maintenance scan, which is the same conclusion BRIN
reached from a different direction.

### 5.4 Plan-stability practices

- `EXPLAIN (ANALYZE, BUFFERS, SETTINGS)` — `SETTINGS` reveals a non-default GUC that is changing the plan,
  which is the usual cause of "it's fast on my machine."
- Keep `effective_cache_size` honest (roughly 50–75% of RAM). It does not allocate anything; it tells the
  planner how likely a page is already cached, and a wrong value pushes it toward seq scans.
- Lower `random_page_cost` on SSD/NVMe (1.1 is the common practitioner value `[COMMUNITY]`). The default of
  4.0 encodes rotational media and systematically discourages index scans — exactly the plans QAYD wants.
- **Never** use `SET enable_seqscan = off` in application code. It is a debugging tool; in production it is
  a way to convert a slow query into a slower one at an unpredictable future date.

---

## 6. Partitioning practices — additions to `05` §B

`05` §B decides the scheme (`entry_date` RANGE, monthly, fiscal-period-aligned), the weakened UNIQUE and its
mitigation, the per-partition RLS hazard and the per-partition trigger hazard. Not repeated. Four operational
additions:

1. **Pre-create partitions well ahead, and alert on the gap, not on the failure.** A partition-creation job
   that fails silently is an outage on the first business day of a month. Alert on *"fewer than 3 future
   partitions exist"* — that fires days before the incident instead of during it.
2. **Constraint-exclusion needs the predicate to name the partition key.** `WHERE fiscal_year_id = 7` does
   **not** prune a table partitioned by `entry_date`. Every report query must carry an `entry_date` range
   predicate even when the fiscal-year id feels sufficient. This is a query-writing rule, not a config
   setting, and it is the most common way partitioning silently fails to help `[COMMUNITY]`.
3. **Closed partitions are physically different** — read-only, fully vacuumed, all-visible, compressible.
   Treat them as a distinct class: index-only scans are perfect there, and they are the only rows worth
   compressing or exporting. `ARCHITECTURE.md` §5.4 draws this.
4. **Partition-wise aggregate must be enabled to get the drift-check speedup `05` §B assumes.**
   `enable_partitionwise_aggregate` and `enable_partitionwise_join` are **off by default**
   `[DOCS]` https://www.postgresql.org/docs/current/runtime-config-query.html — they cost planning time, so
   enable them per-role on the maintenance/reporting connection rather than globally.

---

## 7. Compression practices inside PostgreSQL

Honest summary: **PostgreSQL's compression story is weak, and that is fine, because the data that wants
compressing is the data that should have left the database.**

- TOAST compresses only oversized varlena values (`description TEXT`, `[CODE]`), not whole rows or columns.
  `lz4` is available as an alternative to `pglz` and is faster `[DOCS]`
  https://www.postgresql.org/docs/current/storage-toast.html
- There is no native columnar compression. Extensions provide it (TimescaleDB's compressed chunks; Citus's
  historical `cstore_fdw` lineage), always with restrictions on mutability.
- The right practice is therefore: **do not compress in the database. Export closed partitions to Parquet
  and delete them.** That achieves 5–15× `[INFERENCE]` on ledger-shaped data, removes the rows from vacuum,
  index and restore scope, and produces a queryable artefact — three wins from one operation that `05` §B
  already schedules.

**Measure the ratio on a real export before quoting a number.** The estimate above is directional.

---

## 8. Archive practices — Parquet, done properly

This is the one place QAYD should adopt a columnar technology, and the practices are specific.

| Practice | Why |
|---|---|
| **Row groups of 128 MB–1 GB** | Smaller wastes the footer/statistics overhead and defeats pruning granularity; larger hurts memory and parallelism `[COMMUNITY]` |
| **Sort within the file by `(company_id, account_id, entry_date)`** | Sorting is what makes RLE and dictionary encoding effective (`OVERVIEW.md` §2b) and tightens the min/max statistics that drive pruning. This is free at export time and expensive to fix later |
| **Hive-style directory layout** (`year=2026/month=03/`) | DuckDB auto-detects it and prunes whole directories before opening a file `[DOCS]` https://duckdb.org/docs/current/data/parquet/overview.html |
| **`NUMERIC(19,4)` → Parquet `DECIMAL(19,4)`**, never `DOUBLE` | A float in the archive is a float in the audit. `01` P14 does not stop applying at the storage boundary |
| **Write a `_manifest.json` beside the data** | Rows, min/max `entry_date`, SHA-256 per file, and the `ledger_head_hash` — this is what makes the archive *provable*, not just readable. See `LESSONS_FOR_QAYD.md` L-03 |
| **Verify the export before dropping the partition** | Row count and `SUM(signed_base_amount)` must match the source exactly. An export that silently truncated is discovered years later, by an auditor |
| **Never partition the archive by `company_id` directory** | 100,000 directories is the same mistake as 100,000 table partitions (`05` §B). Bucket if needed: `company_bucket = company_id % 64` |

---

## 9. DuckDB practices — for internal analytics only

| Practice | Why |
|---|---|
| **Run it in a job, never in the API request path** | It is a library with a single-writer model; it is not a concurrent query service |
| **Never install it inside PostgreSQL** (`pg_duckdb`, `pg_analytics`) | See `ANTI_PATTERNS.md` A-09 — an alternative scan path over a tenant table is a candidate for bypassing RLS, and QAYD's tenancy model is "the storage engine enforces it" |
| **Query the Parquet directly**; do not import into a `.duckdb` file | A `.duckdb` file is a second copy with its own staleness. `read_parquet('s3://…/*.parquet')` has none |
| **Pin the version and record it with the output** | A number in a board deck should be reproducible |
| **Give it read-only credentials to the archive bucket** | It is analysis; it never writes |
| **Set a memory limit** (`SET memory_limit`) | An unbounded analytical query on a shared job runner is an outage for everything else on that runner |

---

## 10. Streaming practices — the answer is "you already have one"

QAYD has an **outbox** with after-commit domain events, a drain, and idempotency (`05` §D). That is a durable,
ordered, exactly-once-effective event log backed by the same PostgreSQL transaction as the fact it describes.

**Practices:**

- **Feed internal analytics from the outbox, not from the ledger.** The ledger is money and must not acquire
  a second reader with its own availability expectations. Events are the seam (`03_DESIGN_PATTERNS` P-11).
- **The analytics event schema is append-only and versioned.** Renaming a field breaks every historical
  comparison. Add fields; never repurpose them.
- **No tenant PII in the analytics dataset.** Company id (or a hashed surrogate), plan, cohort, counts.
  Never names, never amounts attributable to a named customer. This keeps the advisory tier outside the
  data-residency and RLS questions entirely, which is worth more than the analysis it forgoes.
- **Every advisory number is displayed with an as-of timestamp.** `05` §C's rule for dashboard trend charts,
  applied universally in the advisory tier.
- **Do not add Kafka.** See `ANTI_PATTERNS.md` A-03 for the arithmetic. The short version: at Tier 3 QAYD
  emits ~13 events/s average and ~100/s peak; Kafka's reason to exist begins three to four orders of
  magnitude higher.

---

## 11. Practices for the boundary between the two tiers

The line drawn in `ARCHITECTURE.md` §7 needs enforcement mechanisms, not just goodwill:

| Practice | Mechanism |
|---|---|
| Statements never served from the advisory tier | Separate roles and connection names; the reporting resolver has no route to the analytics store |
| Advisory numbers always carry an as-of | Make it a required field in the dashboard component's props, so omitting it fails the build |
| No money reconstructed by ETL | Code review rule: any job that computes a monetary total outside the accounting module is rejected by default (`01` P19) |
| The archive is not a read replica | The archive is for auditors, retention and internal analysis. If a *tenant-facing* feature wants archived data, the correct answer is to restore the partition, not to query the lake |
| The analytics dataset can be deleted and rebuilt | If it cannot, it has become a system of record. That is the test |

---

## Sources

- [PostgreSQL — pg_stat_statements](https://www.postgresql.org/docs/current/pgstatstatements.html)
- [PostgreSQL — BRIN Indexes](https://www.postgresql.org/docs/current/brin.html)
- [PostgreSQL — REFRESH MATERIALIZED VIEW](https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html)
- [PostgreSQL — CREATE FUNCTION (parallel safety defaults)](https://www.postgresql.org/docs/current/sql-createfunction.html)
- [PostgreSQL — Parallel Query](https://www.postgresql.org/docs/current/parallel-query.html)
- [PostgreSQL — Resource consumption (work_mem)](https://www.postgresql.org/docs/current/runtime-config-resource.html)
- [PostgreSQL — Query planning GUCs (partitionwise aggregate)](https://www.postgresql.org/docs/current/runtime-config-query.html)
- [PostgreSQL — Autovacuum configuration](https://www.postgresql.org/docs/current/runtime-config-autovacuum.html)
- [PostgreSQL — TOAST](https://www.postgresql.org/docs/current/storage-toast.html)
- [DuckDB — Reading Parquet](https://duckdb.org/docs/current/data/parquet/overview.html)
- [ClickHouse — Incremental materialized views](https://clickhouse.com/docs/materialized-view/incremental-materialized-view)
- [Apache Pinot — Star-tree index](https://docs.pinot.apache.org/basics/indexing/star-tree-index)
- [TimescaleDB — Continuous aggregates](https://docs.timescale.com/use-timescale/latest/continuous-aggregates/)
- [Delta Lake — PROTOCOL.md](https://github.com/delta-io/delta/blob/master/PROTOCOL.md)
