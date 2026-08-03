# ARCHITECTURE — how analytical systems are built, and what QAYD's should look like

**Phase 3 engineering research · analytics domain.** Version 1.0 · 2026-07-28 · Status: **research, not binding**

Deep technical companion to `OVERVIEW.md`. This document explains the *mechanics* — storage layouts, commit
protocols, execution models — with diagrams, and then draws QAYD's own target architecture at each of the
five tiers defined in `docs/architecture/knowledge/05_FUTURE_ARCHITECTURE.md`.

**Does not repeat `05`:** the deployment diagrams there show the *system* topology (app containers, DB
instances, shards, regions). The diagrams here show the *data* topology — where a byte of ledger lives, in
what layout, and which process reads it.

---

## 1. Row storage vs column storage, drawn

### 1.1 PostgreSQL's heap — what a `ledger_entries` page actually contains

`[CODE]` — 21 columns, from `apps/api/database/migrations/2026_07_28_000007_create_ledger_entries_table.php`.
`[DOCS]` page layout: https://www.postgresql.org/docs/current/storage-page-layout.html

```
                       ONE 8 KB HEAP PAGE  (~30 ledger rows)
 ┌────────────────────────────────────────────────────────────────────────────┐
 │ PageHeader (24 B) │ ItemId array →→→→→→                                    │
 ├───────────────────┴────────────────────────────────────────────────────────┤
 │                              free space                                    │
 ├────────────────────────────────────────────────────────────────────────────┤
 │ ←← tuples grow upward from the end                                         │
 │ ┌──────────────────────────────────────────────────────────────────────┐   │
 │ │ hdr │ id │ company │ je_id │ jl_id │ acct │ fy │ fp │ date │ posted │ │   │  row N
 │ │ type│ ccy│ debit │ credit │ b_deb │ b_cr │ SIGNED │ src │ desc │ ref │ │   │
 │ └──────────────────────────────────────────────────────────────────────┘   │
 │ ┌──────────────────────────────────────────────────────────────────────┐   │
 │ │ ... identical shape ...                                    │ SIGNED │ │   │  row N-1
 │ └──────────────────────────────────────────────────────────────────────┘   │
 └────────────────────────────────────────────────────────────────────────────┘

 SUM(signed_base_amount) over this page:
   wanted:      30 rows × ~10 B  =   300 B
   transferred: 1 page           = 8,192 B
   amplification                 ≈ 27×
```

That 27× is the entire motivation for columnar storage, stated honestly. It is also why the covering index
in `05` §C step 2 matters so much: an index-only scan over
`(company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)` transfers ~22 B/row instead of
~273 B/row, which is a hand-rolled 4-column column store for the one query that pays for it.

### 1.2 A Parquet file — the same rows, rotated

`[DOCS]` https://parquet.apache.org/docs/file-format/

```
 ledger_2027_03.parquet
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ PAR1  (4-byte magic)                                                         │
 ├──────────────────────────────────────────────────────────────────────────────┤
 │ ROW GROUP 0   (~128 MB of rows — say 500,000 ledger rows)                     │
 │  ┌────────────────────────────────────────────────────────────────────────┐  │
 │  │ column chunk: company_id     [RLE + bitpack]   min=1001  max=8842       │  │
 │  │   pages: [hdr|stats|data] [hdr|stats|data] …                            │  │
 │  ├────────────────────────────────────────────────────────────────────────┤  │
 │  │ column chunk: entry_date     [DELTA]           min=2027-03-01 max=03-31 │  │
 │  ├────────────────────────────────────────────────────────────────────────┤  │
 │  │ column chunk: account_id     [DICT + bitpack]  ndv=7,412                │  │
 │  ├────────────────────────────────────────────────────────────────────────┤  │
 │  │ column chunk: currency_code  [DICT]            ndv=6      ← KWD × 480k  │  │
 │  ├────────────────────────────────────────────────────────────────────────┤  │
 │  │ column chunk: signed_base_amount [DECIMAL/byte-split]                   │  │
 │  ├────────────────────────────────────────────────────────────────────────┤  │
 │  │ … 16 more column chunks …                                              │  │
 │  └────────────────────────────────────────────────────────────────────────┘  │
 ├──────────────────────────────────────────────────────────────────────────────┤
 │ ROW GROUP 1 … ROW GROUP n                                                    │
 ├──────────────────────────────────────────────────────────────────────────────┤
 │ FOOTER: schema · row-group offsets · per-chunk statistics (min/max/nulls)     │
 │ footer length (4 B) │ PAR1                                                   │
 └──────────────────────────────────────────────────────────────────────────────┘

 SUM(signed_base_amount) WHERE company_id = 4471:
   1. read footer                                     (~KB)
   2. row-group stats: skip groups where 4471 ∉ [min,max]
   3. read ONLY the company_id and signed_base_amount chunks of surviving groups
   4. decode into Arrow buffers, run vectorised SUM
```

Note what steps 2–4 are: **zone-map pruning, projection pushdown, vectorised aggregation** — three of the
four effects from `OVERVIEW.md` §2, obtained by layout alone with no index to maintain.

Note also what is *missing*: there is no way to find one row by primary key without scanning a chunk. A
Parquet file is a magnificent archive and a hopeless transactional store. It has exactly the shape of
QAYD's cold data and exactly the wrong shape for its hot data.

### 1.3 Arrow — the in-memory form, and why it is a separate thing

`[DOCS]` https://arrow.apache.org/docs/format/Columnar.html

```
 Arrow array for signed_base_amount (Decimal128), 8 values, one null:

   validity bitmap :  1 1 1 0 1 1 1 1        (1 bit/value, packed)
   values buffer   : [ v0 ][ v1 ][ v2 ][ xx ][ v4 ][ v5 ][ v6 ][ v7 ]
                       ^ contiguous, fixed-width, 64-byte aligned

   SUM  →  a single loop over one contiguous buffer.
           No pointer chasing. No per-value branch (mask the bitmap).
           Auto-vectorises to SIMD. Cache lines are 100% useful.
```

Parquet is the compressed on-disk form; Arrow is the uncompressed in-memory form; decoding Parquet →
Arrow is the boundary. Systems that speak Arrow (DuckDB, Polars, Pandas 2+, Spark via the Arrow bridge) can
hand each other data with a pointer instead of a serialisation round trip. That is the whole value
proposition and it is orthogonal to storage.

---

## 2. Execution models

### 2.1 Tuple-at-a-time (PostgreSQL's general executor)

```
   Aggregate ──next()──► IndexScan ──next()──► heap page
        ▲                     │
        └──── one tuple ──────┘        per tuple: virtual call, deform,
                                       expression eval dispatch, slot copy
```

Per-tuple overhead is constant, so total cost ≈ rows × (real work + overhead). When `real work` is one
`NUMERIC` addition and `overhead` is ~10× that, the executor is the bottleneck. This is fine at 40,000 rows
(the median tenant-year, `05` §C) and ruinous at 500 million.

PostgreSQL mitigates rather than eliminates: JIT compilation of expressions removes some dispatch, and
parallel query divides the row count across workers.

### 2.2 Vectorised, push-based (DuckDB, ClickHouse)

```
   Scan  ──push 2048 values──►  Filter  ──push──►  HashAggregate
     │                            │                     │
     └ decode one column chunk    └ SIMD compare,        └ tight loop over
       into an Arrow buffer         produce a selection    a contiguous array
                                    vector
```

One operator call per 2,048 values instead of per value. The overhead term is divided by the batch size, and
the inner loops become SIMD-friendly. `[DOCS]` The canonical measurement of this effect is MonetDB/X100,
CIDR 2005: https://www.cidrdb.org/cidr2005/papers/P19.pdf

**The honest conclusion for QAYD:** vectorisation is worth an order of magnitude on scans of hundreds of
millions of rows. QAYD's tenant-facing scans are tens of thousands of rows. The gain there would be
measured in microseconds and paid for with a second system of record. That trade is obviously bad, and it
stays obviously bad until the internal-analytics dataset (which has no tenant predicate) grows large.

---

## 3. Table formats — immutable files plus a pointer

### 3.1 Iceberg's metadata tree

`[DOCS]` https://iceberg.apache.org/spec/ · https://iceberg.apache.org/docs/latest/reliability/

```
                    ┌──────────────────┐
   catalog ────────►│ metadata pointer │  ← the ONLY mutable thing.
   (Glue/REST/JDBC) └────────┬─────────┘    A commit = atomic swap of this.
                             │
                    ┌────────▼──────────────────────┐
                    │ table metadata (v37.json)     │
                    │  schema (field IDs)           │
                    │  partition spec               │
                    │  snapshot log: s1 s2 … s37    │
                    │  current-snapshot-id = 37     │
                    └────────┬──────────────────────┘
                             │
                ┌────────────▼─────────────┐
                │ manifest list (snap-37)  │  seq-no 37
                └───┬──────────────────┬───┘
                    │                  │
            ┌───────▼──────┐   ┌───────▼──────┐
            │ manifest A   │   │ manifest B   │   per-file: partition values,
            │  (stats)     │   │  (stats)     │   row count, column min/max
            └───┬──────┬───┘   └──────┬───────┘
                │      │              │
             ┌──▼──┐┌──▼──┐        ┌──▼──┐
             │ .pq ││ .pq │        │ .pq │        ← IMMUTABLE data files
             └─────┘└─────┘        └─────┘

   Concurrency (documented): writers optimistically write new metadata,
   then atomically swap the pointer; if the base snapshot is no longer
   current the writer RETRIES against the new current version. The atomic
   swap "provides the basis for serializable isolation."

   Time travel: read metadata v12 instead of v37. That is the entire
   implementation. It is free because nothing was ever overwritten.
```

### 3.2 Delta Lake — the same idea as an ordered log

`[DOCS]` https://github.com/delta-io/delta/blob/master/PROTOCOL.md

```
   table/
     part-0000.parquet   part-0001.parquet   …        ← immutable data
     _delta_log/
       00000000000000000000.json    ← commit 0: {add: part-0000}
       00000000000000000001.json    ← commit 1: {add: part-0001, remove: …}
       …
       00000000000000000010.checkpoint.parquet   ← fold of commits 0..10
       00000000000000000011.json

   Reader state = latest checkpoint + replay of the JSON commits after it.
```

**The construct to notice:** the checkpoint is a *materialised fold over an append-only log, always
recomputable from the log*. That is `account_period_balances`, exactly. `05` §C insists on the
projection-vs-cache distinction; Delta is independent confirmation that the distinction is the load-bearing
one — a checkpoint you cannot rebuild is a liability, and a checkpoint you can rebuild is an optimisation.

### 3.3 The QAYD correspondence, drawn

```
   ICEBERG                                QAYD LEDGER
   ───────                                ───────────
   data files (immutable Parquet)   ≡     ledger_entries rows
                                          (BEFORE UPDATE OR DELETE trigger
                                           rejects mutation even for the owner)
   snapshot                         ≡     ledger head at a point in time
   snapshot id / sequence number    ≡     ledger_head_id
   manifest statistics              ≡     partition bounds + idx_ledger_account_date
   atomic metadata-pointer swap     ≡     COMMIT of the posting transaction
   time travel to snapshot N        ≡     report_snapshots.ledger_head_hash  (05 §G)
   schema evolution by field ID     ≡     additive migration on a projection
   ─────────────────────────────────────────────────────────────────────────
   (nothing)                        <     HASH CHAIN

   Iceberg can prove WHICH version you read.
   QAYD can prove the version was NOT ALTERED.
```

The asymmetry in the last two lines is the interesting part, and it has a practical consequence: when a
partition is detached and exported (`05` §B), the exported files inherit *addressability* automatically from
the file layout but inherit *provability* only if the export writes the head hash alongside. See
`LESSONS_FOR_QAYD.md` L-03.

---

## 4. Real-time OLAP — three pre-aggregation models

All three trade freshness or fidelity for latency. Drawn side by side because the differences are what
decide QAYD's answer.

### 4.1 ClickHouse — insert-time materialised view

`[DOCS]` https://clickhouse.com/docs/materialized-view/incremental-materialized-view

```
   INSERT block (≥ ~1000 rows)
        │
        ├──────────────► base MergeTree table          (durable)
        │
        └── MV trigger ─► GROUP BY ─► AggregatingMergeTree target   (durable)

   ── the two writes are NOT one transaction ──
   If the target insert fails, the base row survives and the aggregate is short.
   Background merges later collapse partial aggregate states.
```

### 4.2 Druid — ingestion-time rollup

`[DOCS]` https://druid.apache.org/docs/latest/ingestion/rollup/

```
   raw events ──► rollup at declared granularity (e.g. 1 minute) ──► segment
                                  │
                                  └── RAW ROWS ARE DISCARDED
```

Irreversible. Perfect for telemetry. **Disqualifying for a book of record** — there is no accounting system
in which "we aggregated the journal lines and dropped them" is an acceptable sentence.

### 4.3 Pinot — star-tree, a budgeted cube

`[DOCS]` https://docs.pinot.apache.org/basics/indexing/star-tree-index

```
                       ROOT (all rows)
                     /        |        \
           dim1=A         dim1=B      dim1=★  ← ★ = "aggregated over dim1"
            /   \           / \          |
      dim2=X  dim2=★   dim2=X  …     dim2=X …
        │        │
     leaf     PRE-AGGREGATED node

   Split only while leaf size > maxLeafRecords.
   → storage grows with the dimension combinations you DECLARE,
     not with 2ⁿ. Worst-case scan is bounded by maxLeafRecords.
```

The generalisable idea — *declare the combinations, bound the leaf* — is the right answer to a problem QAYD
will have when analytic dimensions land: balances by (account × cost centre × project × department ×
period) is a combinatorial rollup, and the naive response is to materialise all of it.

### 4.4 QAYD's model, for contrast

```
   POSTING TRANSACTION
   ┌──────────────────────────────────────────────────────────┐
   │ INSERT journal_entries / journal_lines                    │
   │ INSERT ledger_entries      (1 row per posted line)        │
   │   └─ AFTER INSERT trigger ─► UPSERT account_period_balances│
   │ INSERT outbox_events                                      │
   └──────────────────────────── COMMIT ──────────────────────┘
        atomic. exact. monotonic (append-only ⇒ increment-only).
        CHECK (closing = opening + debit − credit) enforced by the DB.
```

**This is strictly stronger than all three.** It is only possible because the source is append-only — the
trigger never has to reconcile against a mutation, never sees UPDATE or DELETE, and therefore cannot drift
for the usual reason aggregates drift. `05` §C states this; the diagram is here because the contrast with
ClickHouse's *non-transactional* MV is the clearest available proof that QAYD's design is not merely
adequate but better for this workload.

---

## 5. PostgreSQL's own analytical machinery

The part that matters most, because it is the only part QAYD will use.

### 5.1 Where the levers are

```
  QUERY
    │
    ├─ PLANNER
    │    ├─ partition pruning        (declarative RANGE partitions — 05 §B)
    │    ├─ index selection          (btree / covering / BRIN)
    │    ├─ parallel plan?           (blocked by any PARALLEL UNSAFE function)
    │    └─ cost model               (random_page_cost, effective_cache_size)
    │
    ├─ EXECUTOR
    │    ├─ index-only scan          (needs visibility map ⇒ needs VACUUM)
    │    ├─ parallel workers         (max_parallel_workers_per_gather)
    │    ├─ hash/sort memory         (work_mem — PER NODE PER WORKER)
    │    └─ JIT                      (jit_above_cost)
    │
    └─ STORAGE
         ├─ shared_buffers           (hot pages)
         ├─ OS page cache            (effective_cache_size tells the planner)
         ├─ TOAST                    (description TEXT — off-page if large)
         └─ visibility map / FSM     (maintained by VACUUM)
```

### 5.2 Index-only scan — the mechanism that must not be taken for granted

```
   covering index: (company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)

   index tuple: [ 1001 | 7 | 4402 ][ +1250.0000 ]  ≈ 22 B
                 └─────── key ─────┘└── payload ─┘

   Index-only scan ─► for each index tuple:
                        consult VISIBILITY MAP for the tuple's heap page
                          ├ all-visible?  → return payload, NO heap read
                          └ not visible?  → heap fetch (the fallback)
```

The trap: **the visibility map is maintained by VACUUM.** On a table with heavy recent inserts and no recent
vacuum, index-only scans silently degrade into index scans with heap fetches, and the covering index appears
not to work. On an append-only table this is the *normal* state for the newest pages.

Mitigations, in order: (1) confirm with `EXPLAIN (ANALYZE, BUFFERS)` that `Heap Fetches` is low; (2) tune
autovacuum on `ledger_entries` to run on insert volume, not just dead tuples —
`autovacuum_vacuum_insert_threshold` exists precisely for insert-only tables `[DOCS]`
https://www.postgresql.org/docs/current/runtime-config-autovacuum.html ; (3) accept that the newest partition
will always have some heap fetches and that closed periods will not.

**This is a genuinely important operational detail for an append-only design and it is not in `05`.**

### 5.3 BRIN — the zone map, drawn

`[DOCS]` https://www.postgresql.org/docs/current/brin.html · default `pages_per_range` = 128
(`BRIN_DEFAULT_PAGES_PER_RANGE`)

```
   HEAP (append-only ⇒ physical order = insertion order)
   ┌─────────┬─────────┬─────────┬─────────┬─────────┐
   │ pages   │ pages   │ pages   │ pages   │ pages   │
   │ 0-127   │128-255  │256-383  │384-511  │512-639  │
   └────┬────┴────┬────┴────┬────┴────┬────┴────┬────┘
        │         │         │         │         │
   BRIN summary per 128-page range:
   posted_at  [Mar 1–Mar 3] [Mar 3–Mar 6] [Mar 6–Mar 9] …   ← TIGHT   ✔ prunes
   entry_date [Feb 1–Mar 3] [Feb 8–Mar 6] [Jan 2–Mar 9] …   ← LOOSE   ~ back-dating widens it
   company_id [1001–9998]   [1002–9999]   [1001–9997]  …    ← USELESS ✘ prunes nothing

   Size on a 400 GB table: a few MB.  Equivalent btree: ~9 GB.
```

Three conclusions, and the third is the one that matters:

1. **`posted_at` is perfectly correlated** with physical order, because the table is append-only and
   `posted_at` is set at insert. `[CODE]` — `posted_at TIMESTAMPTZ NOT NULL`, `id BIGINT GENERATED ALWAYS AS
   IDENTITY`.
2. **`entry_date` is only approximately correlated**, because back-dating within an open period is a routine
   accounting operation. BRIN on `entry_date` degrades exactly when the books are busiest (period-end
   catch-up posting). It still works — it just skips less.
3. **`company_id` cannot be BRIN-indexed usefully at all.** Tenants interleave in insertion order, so every
   block range's `[min,max]` spans nearly the whole tenant id space. Since *every tenant-facing query filters
   by company first*, **BRIN is close to worthless for QAYD's reporting** and valuable only for cross-tenant
   maintenance scans.

That third point contradicts the common framing that BRIN is "a natural fit for append-only ledgers." It is
a natural fit for the *table's physical shape* and a poor fit for the *product's query shape*. Both are true;
only the second decides. See `BEST_PRACTICES.md` §3 for the resulting (still worthwhile) recommendation.

### 5.4 Partitioning, in the layout view

`05` §B decides the scheme (`entry_date` RANGE, monthly) and its consequences (weakened UNIQUE, per-partition
RLS, per-partition triggers). Not repeated. The layout consequence worth adding:

```
   ledger_entries (partitioned parent — no storage)
     ├── ledger_entries_2027_01   ← CLOSED. immutable. fully vacuumed.
     │        all-visible ⇒ index-only scans are perfect here
     ├── ledger_entries_2027_02   ← CLOSED
     ├── ledger_entries_2027_03   ← OPEN. hot. inserts land here.
     │        newest pages not all-visible ⇒ heap fetches
     └── ledger_entries_2027_04   ← future (pre-created)

   Detached + exported:  s3://qayd-archive/ledger/company_bucket=…/year=2026/*.parquet
```

The bimodality is the useful insight: **closed partitions are a different physical animal from the open
one.** They are read-only, fully visible, perfectly vacuumed, compressible and exportable. Every technique in
this document that involves compression or columnar layout applies to them and to nothing else.

---

## 6. QAYD's data architecture, by tier

Tier definitions and load figures from `05`. These diagrams show *data*, not deployment.

### 6.1 Tier 1 (100 customers) — one database, no analytics

```
   ┌──────────┐    SQL     ┌──────────────────────────────────┐
   │ Next.js  │───────────►│ PostgreSQL (single instance)      │
   └──────────┘  Laravel   │  journal_entries / journal_lines  │
                           │  ledger_entries  ← RLS, append-only│
                           │  audit_logs                       │
                           │  idx_ledger_account_date           │
                           └──────────────────────────────────┘

   Trial balance: live SUM. ~15 ms at the median tenant (05 §C).
   Analytics: none. Business questions are answered with a SQL query
              typed by a human. This is CORRECT at this tier.
```

**Anything more than this before ~400 customers is waste.** The only additions worth making at Tier 1 are
*measurement* (`pg_stat_statements`) and *guardrails* (the parallel-safety CI check), because both are
effort 1 and both are preconditions for knowing when the next tier arrived.

### 6.2 Tier 2 (1,000) — covering index, and the archive decision

```
   ┌──────────────────────────────────────────┐
   │ PostgreSQL primary                        │
   │  ledger_entries                           │
   │   ├ idx_ledger_account_date               │
   │   ├ COVERING idx (company, fy, account)   │  ← 05 §C step 2, effort 2
   │   │        INCLUDE (signed_base_amount)   │
   │   └ BRIN (posted_at)                      │  ← maintenance scans only
   │  account_period_balances (built, verified)│  ← projection, not yet serving
   └───────────────────┬──────────────────────┘
                       │ outbox drain
                       ▼
              ┌──────────────────┐
              │ analytics_events │  append-only, NO company PII
              └──────────────────┘   ← the internal dataset starts here, in Postgres
```

The Tier-2 decision that pays off later is not an index. It is choosing **Parquet** as the format for the
eventual partition export, and starting the `analytics_events` table so that the internal dataset has
history when someone finally asks a cohort question. Both are cheap now and impossible to backfill later.

### 6.3 Tier 3 (10,000) — rollup serving, replica, and DuckDB over the archive

```
   ┌──────────────────────────────────────────┐
   │ PostgreSQL PRIMARY                        │        ┌───────────────────┐
   │  ledger_entries (RANGE partitioned)       │───WAL─►│ REPLICA           │
   │  account_period_balances ◄── SERVING PATH │        │  exploration only │
   │  report_snapshots (head hash)             │        │  NEVER statements │
   └──────────┬───────────────────────────────┘        └───────────────────┘
              │ DETACH PARTITION CONCURRENTLY (05 §B)
              ▼
   ┌────────────────────────────────────────────────────────────┐
   │ OBJECT STORAGE — s3://qayd-archive/                        │
   │   ledger/year=2026/month=01/part-000.parquet               │
   │   ledger/year=2026/month=01/_manifest.json                 │
   │        { rows, min/max entry_date, sha256, ledger_head_hash}│
   └───────────────┬────────────────────────────────────────────┘
                   │  read_parquet('s3://…/year=2026/*.parquet')
                   ▼
        ┌────────────────────────────┐
        │ DuckDB — IN A JOB          │   internal analytics + auditor extracts
        │ no server, no cluster      │   projection + filter pushdown, zonemaps
        └────────────────────────────┘

   Tenant-facing statements: PRIMARY → account_period_balances. Always.
```

**This is the architecture the whole research program points at.** Note what it is not: there is no
warehouse, no ETL that reconstructs money, no second system of record, and no service to operate. The
"analytics platform" is a naming convention for files plus a library.

### 6.4 Tier 4 (100,000) — sharded, and the first defensible warehouse question

```
   shard 1 ┐        shard 2 ┐        …        shard N ┐
   ┌───────▼──┐   ┌───────▼──┐              ┌───────▼──┐
   │ Postgres │   │ Postgres │              │ Postgres │   tenant-facing:
   │ + rollup │   │ + rollup │              │ + rollup │   unchanged, per shard
   └────┬─────┘   └────┬─────┘              └────┬─────┘
        │ outbox       │ outbox                  │ outbox
        └──────────────┴──────────┬──────────────┘
                                  ▼
                    ┌───────────────────────────┐
                    │ analytics_events → Parquet │
                    │ s3://qayd-analytics/       │
                    └─────────────┬─────────────┘
                                  │
                     ┌────────────▼────────────┐
                     │ DuckDB job  ──or──      │  ← cross-shard questions are
                     │ single-node ClickHouse  │    now impossible in one SQL
                     └─────────────────────────┘    query. THIS is the trigger.
```

The honest statement: **cross-shard internal analytics is the first genuine technical justification for a
separate analytical store in QAYD's entire roadmap**, and it arrives at Tier 4 — 100,000 customers, ~$10M
MRR (`05` capacity table). Even there the answer is one node, not a platform.

### 6.5 Tier 5 — out of scope

`05` §Tier 5 already carries the reality check: at a million customers the interesting questions are
commercial. Nothing in this domain changes that. If QAYD reaches Tier 5, it will have a data team, and that
team should re-derive this document rather than inherit it.

---

## 7. The correctness boundary, drawn once

Everything in this research reduces to one line that must never be crossed:

```
 ┌──────────────────────────────── AUTHORITATIVE ──────────────────────────────┐
 │  ledger_entries  →  account_period_balances  →  report_snapshots            │
 │  transactional · exact · RLS-enforced · append-only · hash-chained          │
 │                                                                             │
 │  Serves: trial balance, balance sheet, P&L, VAT return, bank balance,       │
 │          AR/AP aging, anything a user signs, files, sends, or pays against  │
 └────────────────────────────────────┬────────────────────────────────────────┘
                                      │  outbox events only — never a money copy
                                      ▼
 ┌──────────────────────────────── ADVISORY ───────────────────────────────────┐
 │  analytics_events → Parquet → DuckDB (→ ClickHouse if it ever outgrows)     │
 │  eventual · aggregated · no tenant PII · always shown with an as-of         │
 │                                                                             │
 │  Serves: cohort retention, feature adoption, AI cost-to-serve, unit         │
 │          economics, internal dashboards                                     │
 └─────────────────────────────────────────────────────────────────────────────┘

 The arrow points ONE WAY. No advisory number is ever displayed as a financial
 figure, and no financial figure is ever computed in the advisory tier.
```

If a future design proposes an arrow pointing back up, that is the moment to invoke
`01_ENGINEERING_PRINCIPLES` P19 and stop.

---

## 8. Effort and confidence for the architectural moves in this document

| Move | Effort | Confidence | Note |
|---|---|---|---|
| `pg_stat_statements` + `auto_explain` enabled and dashboarded | **1** | High | Precondition for every `05` trigger metric |
| CI assertion that `app_*()` stay `PARALLEL SAFE` / `STABLE` | **1** | High | Already correct `[CODE]`; guard against regression |
| `BRIN (posted_at)` on `ledger_entries` | **1** | High | Maintenance scans only; not a reporting fix |
| Autovacuum insert-threshold tuning for index-only scans | **2** | Medium-high | Measure `Heap Fetches` first |
| Parquet as the partition-export format (+ manifest with head hash) | **5** | High | Replaces an export that must happen anyway |
| `analytics_events` table fed by the outbox | **5** | Medium | Schema is a product question |
| DuckDB job over the archive | **3** | High | Library, not a service |
| Cross-period continuity check on `account_period_balances` | **3** | High | Closes a real gap — see `ANTI_PATTERNS.md` A-11 |
| Single-node ClickHouse for cross-shard analytics | **21** | Low | Tier 4 only, and only if measured |
| Managed warehouse (Snowflake/BigQuery) | **34+** | Low | Organisational trigger, not technical |

---

## Sources

- [PostgreSQL — Page layout](https://www.postgresql.org/docs/current/storage-page-layout.html)
- [PostgreSQL — BRIN Indexes](https://www.postgresql.org/docs/current/brin.html)
- [PostgreSQL — Autovacuum configuration](https://www.postgresql.org/docs/current/runtime-config-autovacuum.html)
- [PostgreSQL — Parallel Query](https://www.postgresql.org/docs/current/parallel-query.html)
- [Apache Parquet — File Format](https://parquet.apache.org/docs/file-format/)
- [Apache Arrow — Columnar Format](https://arrow.apache.org/docs/format/Columnar.html)
- [Apache Iceberg — Table Spec](https://iceberg.apache.org/spec/)
- [Apache Iceberg — Reliability](https://iceberg.apache.org/docs/latest/reliability/)
- [Delta Lake — PROTOCOL.md](https://github.com/delta-io/delta/blob/master/PROTOCOL.md)
- [ClickHouse — Incremental materialized views](https://clickhouse.com/docs/materialized-view/incremental-materialized-view)
- [Apache Druid — Rollup](https://druid.apache.org/docs/latest/ingestion/rollup/)
- [Apache Pinot — Star-tree index](https://docs.pinot.apache.org/basics/indexing/star-tree-index)
- [MonetDB/X100 — CIDR 2005](https://www.cidrdb.org/cidr2005/papers/P19.pdf)
- [DuckDB — SIGMOD 2019](https://duckdb.org/pdf/SIGMOD2019-demo-duckdb.pdf)
