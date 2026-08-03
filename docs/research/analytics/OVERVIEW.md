# OVERVIEW — Analytics, OLAP and columnar systems, judged against QAYD

**Phase 3 engineering research · analytics domain.** Version 1.0 · 2026-07-28 · Status: **research, not binding**

Companion to `ARCHITECTURE.md` (mechanics), `BEST_PRACTICES.md` (what to do),
`ANTI_PATTERNS.md` (what not to do), `LESSONS_FOR_QAYD.md` (what it means here) and
`IMPLEMENTATION_RECOMMENDATIONS.md` (sequenced, with triggers).

**Extends, does not repeat:** `docs/architecture/knowledge/05_FUTURE_ARCHITECTURE.md` §B (partitioning),
§C (aggregates and caching), §G (reporting). Those sections already established the five-tier model, the
`account_period_balances` rollup, the reporting ladder and the rejection of a separate OLAP store. This
document does not re-argue any of them. It supplies the layer underneath: *why* columnar systems win where
they win, *what* the nine studied systems actually do, and *where exactly* the boundary sits for QAYD.

---

## Evidence legend

| Tag | Meaning |
|---|---|
| `[DOCS]` | Vendor or project documentation / published paper, URL cited |
| `[CODE]` | Read from QAYD's own source, path cited |
| `[COMMUNITY]` | Widely-reported practitioner experience, not vendor-documented |
| `[INFERENCE]` | Reasoned from documented mechanics; not directly stated by a source |
| `[UNKNOWN]` | Could not be determined — say so rather than guess |

This domain is unusually well documented — Snowflake, Druid and Pinot published SIGMOD papers, and
ClickHouse, DuckDB, Iceberg, Delta, Arrow, Parquet and PostgreSQL are open source. `[UNKNOWN]` is
therefore rare here, and where it appears it is about *QAYD's* future numbers, not about the systems.

---

## The verdict, stated first

> **QAYD does not need an analytics platform. It needs PostgreSQL configured competently, one rollup
> table it has already designed, and — eventually, for internal business analytics only — a directory of
> Parquet files it is going to write anyway.**

A pre-launch product with zero customers adopting a data warehouse would not be a premature optimisation;
it would be a **second system of record for money**, which `01_ENGINEERING_PRINCIPLES` P19 exists to
prevent. The correct number of analytical databases in QAYD's architecture today is **zero**, and the
correct number for tenant-facing financial statements is **zero forever**.

That is not a dismissal of the field. The mechanics below are worth understanding precisely, because three
of them — columnar layout, immutable-file-plus-metadata table formats, and pre-aggregation — are ideas
QAYD should **borrow without adopting the systems that popularised them**. The most valuable finding in
this research is not a tool to install. It is that QAYD's append-only ledger is already, structurally, an
Iceberg-style table, and its planned partition archive is already, structurally, a Parquet data lake. The
work is to *notice* that and finish it cheaply, not to buy it.

---

## 1. OLTP vs OLAP — the distinction, mechanically

The folklore version ("OLTP is writes, OLAP is reads") is wrong and leads directly to the mistake this
document exists to prevent. The real distinction is about **selectivity and column breadth**.

| | OLTP query | OLAP query |
|---|---|---|
| Rows touched | Few, found by a selective predicate | Many — often most of the table |
| Columns touched | Most or all of the row | Few, out of many |
| Predicate | Highly selective (`WHERE id = ?`) | Weak or absent; often only a time range |
| Result size | Small | Small — but computed *from* a huge scan |
| Access pattern | Point lookups, index descents, random I/O | Sequential scan, sequential I/O |
| What dominates cost | Latency per lookup | Bytes read per second, and CPU per row |
| Concurrency | Thousands of short transactions | Few long queries |
| Mutation | Frequent update-in-place | Append, rarely mutate |

The decisive question is not "am I reading or writing." It is:

> **Does my predicate eliminate most of the data before the aggregate runs?**

If yes, you have an OLTP-shaped aggregate and a B-tree solves it. If no, you are scan-bound, and the only
remaining levers are: read fewer bytes (columnar + compression), read them faster (sequential + parallel),
process them with fewer CPU cycles per row (vectorised execution), or **do not read them at all because
the answer was computed earlier** (pre-aggregation).

### Where QAYD's reporting query lands

QAYD's trial balance is:

```sql
SELECT account_id, SUM(signed_base_amount)
FROM ledger_entries
WHERE company_id = ? AND fiscal_year_id = ?
GROUP BY account_id;
```

`[CODE]` — column names from `apps/api/database/migrations/2026_07_28_000007_create_ledger_entries_table.php`.

Run it through the test above:

- **Predicate selectivity: extreme.** `company_id` is the most selective column in the database. At Tier 3
  (10,000 tenants) one tenant is ~0.01% of rows. `05_FUTURE_ARCHITECTURE` §"single most important framing"
  already establishes that *nothing* QAYD does reads across tenants.
- **Column breadth: 3 of 21 columns**, which sounds columnar-friendly — until you notice that the row count
  after the predicate is 25k–400k, not 4 billion.
- **Result size: ~60 rows** (one per active account).

So the trial balance is an **OLTP-shaped aggregate**: a narrow, index-supported scan of a small slice.
Columnar storage optimises the bytes-read term of a cost function whose dominant term QAYD has already
eliminated with a `WHERE` clause. This is the mechanical restatement of what `05` §G asserts, and it is
why that assertion holds rather than being a preference.

### The one QAYD workload that *is* OLAP-shaped

Internal, cross-tenant business analytics: cohort retention, feature adoption, AI cost-to-serve, gross
margin by tenant cohort, funnel conversion. Named but not developed in `05` §G ("fed by the outbox").

Run it through the test: no tenant predicate (that is the point), wide scans, aggregate-heavy, ad-hoc,
schema-unstable, and — critically — **nobody makes a payment against it**. It is genuinely OLAP-shaped, and
it is also the workload where a wrong number costs a mis-drawn chart rather than a mis-filed VAT return.

**Everything analytical that QAYD should ever build belongs to this second workload.** Keeping the two
apart is the whole design.

---

## 2. Why columnar beats row storage for aggregates — the mechanism

Four independent effects, commonly collapsed into "columnar is faster." They are not the same effect and
they do not all apply to QAYD.

### (a) I/O elimination — you do not read what you do not select

A row store reads whole tuples. PostgreSQL's unit of I/O is the 8 KB page; a page holds ~30 `ledger_entries`
rows at ~250 B of heap each `[CODE]`/`[INFERENCE]`. Summing one `NUMERIC(19,4)` column therefore drags
~240 B of unwanted columns into the buffer pool for every 10 B of wanted data — a **~25× read
amplification** for that access pattern.

A column store writes each column to its own contiguous run. `SUM(signed_base_amount)` reads only that
run. Nothing else moves.

*Applies to QAYD?* Partially — and PostgreSQL already offers most of it without a column store, via
**index-only scans on covering indexes**. `05` §C step 2 (`(company_id, fiscal_year_id, account_id) INCLUDE
(signed_base_amount)`) is precisely a hand-built, query-specific column store for the one query that
matters. That is why `05` rates it "the highest value-per-point item in the list."

### (b) Compression — like values sit next to like values

This is the effect that is genuinely hard to replicate in a row store, and the reason Parquet files are
5–15× smaller than the equivalent CSV.

A column of `company_id`, sorted or merely clustered by insertion order, is a run of near-identical
integers. A column of `currency_code` over a GCC tenant base is `KWD` repeated ten thousand times. A column
of `entry_type` is one of 17 enum values `[CODE]`. Real encodings exploit exactly this:

| Encoding | What it does | QAYD column it would crush |
|---|---|---|
| **Run-length (RLE)** | Store (value, count) | `currency_code`, `entry_type`, `fiscal_year_id`, `company_id` within a partition |
| **Dictionary** | Store distinct values once; store small codes per row | `source_type` (VARCHAR(60), low cardinality), `account_id` |
| **Delta / frame-of-reference** | Store differences from a base | `id` (monotonic), `entry_date`, `posted_at`, `journal_entry_id` |
| **Bit-packing** | Use ⌈log₂(range)⌉ bits, not 64 | `account_id` (~60 distinct per tenant → 6 bits) |

In a row store none of these work well, because the next byte after a `company_id` is an unrelated
timestamp. Compression ratio is a function of **neighbour similarity**, and column layout is simply the
arrangement that maximises it.

`[DOCS]` Parquet defines RLE/bit-packing hybrid, dictionary, delta and byte-stream-split encodings:
https://parquet.apache.org/docs/file-format/data-pages/encodings/

*Applies to QAYD?* **Yes, and this is the actionable one** — not for live queries, but for the partition
archive that `05` §B already requires ("export to object storage, compressed, hash-verified"). The only
open question there is the file format, and the answer should be Parquet. See `LESSONS_FOR_QAYD.md` L-02.

### (c) Vectorised execution — amortising the interpreter

A classic row-at-a-time executor (PostgreSQL's, in the general case) walks a tree of operator nodes and
calls `ExecProcNode` once per tuple. The per-tuple overhead — virtual call, tuple deform, expression
evaluation dispatch — can exceed the actual arithmetic by an order of magnitude.

Vectorised engines process **batches** (typically 1,024–65,536 values) per operator call. One call, one
tight loop over a contiguous array of `int64`, auto-vectorisable to SIMD, branch-predictable, cache-resident.
The interpretive overhead is divided by the batch size.

`[DOCS]` The canonical result is the MonetDB/X100 paper (Boncz, Zukowski, Nes, CIDR 2005), which measured
order-of-magnitude gains from vectorised-in-cache execution over tuple-at-a-time:
https://www.cidrdb.org/cidr2005/papers/P19.pdf

`[DOCS]` DuckDB is a direct descendant of that line — "an embeddable analytical database" with a vectorised
push-based executor: https://duckdb.org/pdf/SIGMOD2019-demo-duckdb.pdf

*Applies to QAYD?* Only to the internal-analytics workload. On a 60-row result computed from a 40,000-row
scan, execution overhead is not the bottleneck; on a 500-million-row cross-tenant cohort scan, it is
everything.

### (d) Zone maps / min-max pruning — skipping without an index

Every serious columnar format stores per-chunk summary statistics (min, max, null count, sometimes distinct
count) and consults them before decompressing. A predicate outside `[min, max]` skips the chunk entirely.

- **Parquet:** row-group and page-level statistics `[DOCS]` https://parquet.apache.org/docs/file-format/
- **Snowflake:** per-micro-partition "range of values for each of the columns" and distinct counts; 50–500 MB
  uncompressed per micro-partition `[DOCS]` https://docs.snowflake.com/en/user-guide/tables-clustering-micropartitions
- **DuckDB:** "filter will be pushed down into the scan, and can even be used to skip parts of the file using
  the built-in zonemaps" `[DOCS]` https://duckdb.org/docs/current/data/parquet/overview.html
- **PostgreSQL:** this is exactly what a **BRIN index** is — a zone map bolted onto a heap
  `[DOCS]` https://www.postgresql.org/docs/current/brin.html

The last line is the important one. **PostgreSQL already ships the zone-map idea**, and on an append-only
table it is the cheapest index in the system. It is also, for QAYD, far less useful than it first appears —
see `BEST_PRACTICES.md` §3 for the honest assessment, which is that BRIN cannot prune by tenant and is
therefore a *maintenance-scan* tool, not a reporting tool.

### Summary of applicability

| Columnar effect | Helps QAYD's tenant reporting? | Helps QAYD's archive? | Helps QAYD's internal analytics? |
|---|---|---|---|
| (a) I/O elimination | Already obtained via covering index | Yes | Yes |
| (b) Compression | No (data is hot and small per tenant) | **Yes — strongly** | Yes |
| (c) Vectorised execution | No | N/A | **Yes — strongly** |
| (d) Zone-map pruning | Marginal (predicate is already selective) | Yes | Yes |

---

## 3. The nine systems

Each profile: **what it is · the one idea worth stealing · what it is genuinely good at and why · where it
is bad and why · verdict for QAYD.**

---

### 3.1 Apache Parquet — the file format

**What it is.** An open, columnar, self-describing on-disk format: a file is a sequence of **row groups**;
each row group holds one **column chunk** per column; each chunk is a sequence of **pages**; a footer
carries the schema and per-chunk/per-page statistics. `[DOCS]` https://parquet.apache.org/docs/file-format/

**The idea worth stealing.** *Self-describing immutable files with embedded statistics.* An archived
Parquet file needs no external catalog to be read five years later, and its footer answers "could this file
contain company 4471 in March 2027?" without reading a byte of data.

**Good at.** Long-lived analytical storage. Compression (see §2b). Being readable by literally everything —
DuckDB, Spark, Pandas, ClickHouse, Snowflake, BigQuery, Athena, Trino. It is the closest thing the data
world has to a lingua franca.

**Bad at.** Point lookups (no index, no key). Mutation (a "change" means rewriting a file). Small writes
(row groups want to be 128 MB–1 GB; a stream of single rows produces thousands of tiny files, the
well-known "small files problem" `[COMMUNITY]`). Transactions (it has none — that is what Iceberg/Delta add).

**Verdict for QAYD: adopt, but only as the archive format.** `05` §B already mandates exporting detached
partitions to object storage. Choosing Parquet instead of a `COPY`-to-gzip-CSV costs nothing extra at export
time and converts dead cold storage into a queryable dataset. **This is the highest-leverage single decision
in this research.**

---

### 3.2 Apache Arrow — the memory format

**What it is.** A standardised **in-memory** columnar layout: contiguous validity bitmaps + value buffers,
with defined layouts for nested and variable-length types. `[DOCS]`
https://arrow.apache.org/docs/format/Columnar.html Its purpose is to eliminate serialisation between
processes and languages: two systems that both speak Arrow exchange data by passing a pointer, not by
encoding and re-decoding.

**The idea worth stealing.** *A shared representation removes a whole class of cost that nobody budgets
for.* The unglamorous truth of most data pipelines is that they spend more CPU converting between
representations than computing anything.

**Good at.** Zero-copy interchange (Arrow Flight, Arrow IPC). Being the substrate for vectorised execution —
its layout is what makes SIMD loops possible. Language interop (C++, Rust, Java, Python, Go, R).

**Bad at.** Storage — it is uncompressed by design (that is Parquet's job). Being a database — it is a
memory layout, not a system.

**Verdict for QAYD: not applicable directly; understand it as the reason DuckDB is fast and as a possible
future interchange with the FastAPI AI engine.** `[INFERENCE]` If the AI engine ever needs bulk numeric
context (e.g. 12 months of ledger aggregates for anomaly scoring), Arrow/Parquet over a shared object store
is materially cheaper than JSON over HTTP. Worth remembering; not worth building now.

---

### 3.3 DuckDB — in-process analytics

**What it is.** An embeddable OLAP database — "SQLite for analytics." Runs inside the host process, no
server, no daemon, no cluster. Vectorised, columnar, with a full SQL engine including window functions and
CTEs. `[DOCS]` https://duckdb.org/pdf/SIGMOD2019-demo-duckdb.pdf

**The idea worth stealing — and the reason to take DuckDB seriously.** *You can have a real analytical
engine with zero infrastructure.* Documented capabilities that matter here `[DOCS]`
https://duckdb.org/docs/current/data/parquet/overview.html:

- `SELECT * FROM 'test.parquet'` — query Parquet directly, no load step
- glob patterns (`'ledger/*.parquet'`) and Hive-partitioned directory layouts, auto-detected
- HTTPS/S3 access via the `httpfs` extension
- **projection pushdown** — "only the columns required for the query are read"
- **filter pushdown** — "can even be used to skip parts of the file using the built-in zonemaps"

Which means: a directory of Parquet files on object storage *is already a data warehouse* to DuckDB, with
no ingestion, no schema registration, no cluster and no monthly bill.

**Good at.** Ad-hoc analysis on datasets from megabytes to a few hundred gigabytes on one machine. CI and
local development (no service to run). Being embedded in a job, a notebook or a CLI. Reading other people's
files.

**Bad at.** Concurrency — it is single-writer and designed for one analyst, not a hundred dashboard users.
High availability (it is a library). Being a system of record. Very large datasets that exceed one node.

**Verdict for QAYD: adopt at Tier 3, for internal analytics only, and never inside the accounting
database.** The out-of-process form (a job that reads exported Parquet) is excellent. The in-process form
(`pg_duckdb`) is a different and much worse proposition — see `ANTI_PATTERNS.md` A-09.

---

### 3.4 Apache Iceberg — the table format, and QAYD's structural twin

**What it is.** An open table format that puts ACID semantics over immutable files in object storage. The
structure is a tree: a **catalog pointer** → **table metadata file** → **manifest list** (one per snapshot)
→ **manifest files** → **data files**. `[DOCS]` https://iceberg.apache.org/spec/

The commit protocol, documented `[DOCS]` (https://iceberg.apache.org/spec/, https://iceberg.apache.org/docs/latest/reliability/):

- Writers use **optimistic concurrency**: each writer assumes no other writer is active and writes new table
  metadata for its operation.
- A writer commits by **atomically swapping the table's metadata file pointer** from the base version to
  the new version.
- "An atomic swap of one table metadata file for another provides the basis for **serializable isolation**."
- Readers "use the snapshot that was current when they load the table metadata and are not affected by
  changes until they refresh."
- If the base snapshot is no longer current, the writer **retries** against the new current version.
- Manifests, data files and delete files inherit the snapshot's **sequence number**.
- Schema evolution is by **field ID**, not by column position or name — so renames and reorderings are
  metadata-only and never rewrite data.

**The idea worth stealing — and the parallel that matters most in this research.**

> **Iceberg and QAYD's `ledger_entries` are the same design.** Immutable data + a small mutable pointer to
> a metadata structure. A "transaction" is an atomic pointer move. History is never mutated, so **time
> travel is nearly free**: keeping an old pointer *is* the feature.

The correspondence is close enough to be worth tabulating:

| Iceberg | QAYD |
|---|---|
| Immutable data files | `ledger_entries` rows (append-only trigger, `[CODE]` `fn_ledger_entries_append_only`) |
| Snapshot | Ledger head at a point in time |
| Snapshot id / sequence number | `ledger_head_id` + `ledger_head_hash` (`05` §G `report_snapshots`) |
| Atomic metadata-pointer swap | Commit of the posting transaction |
| Time travel to snapshot N | "This P&L was generated against ledger head X" |
| Schema evolution by field ID | Additive migrations on an append-only projection |
| Manifest statistics for pruning | Partition boundaries + `idx_ledger_account_date` |
| — *(no equivalent)* | **Hash chain — tamper-evidence, which Iceberg does not have** |

The last row is the point. Iceberg gives you *addressable* history. QAYD's hash chain gives you
**provable** history. `05` §L already identifies this as the ledger's unique DR advantage; the lesson from
Iceberg is that the *archive* should carry the same property, which means the exported Parquet needs a
manifest carrying the head hash — otherwise the archive is addressable but not provable. See
`LESSONS_FOR_QAYD.md` L-03.

**Good at.** Multi-engine access to one dataset. Petabyte-scale tables where rewriting is impossible. Schema
evolution without rewrites. Time travel and rollback. Partition evolution (changing the partition scheme
without rewriting history) — a genuinely hard problem it solves elegantly.

**Bad at.** Small data (the metadata tree is pure overhead below ~TB). Latency (a commit is several object
store round-trips). Point lookups. Operating without a catalog service (Glue, Nessie, JDBC, REST) — which is
a real component with real availability requirements.

**Verdict for QAYD: do not adopt. Recognise the parallel and copy the *layout convention*.** Adopting
Iceberg means adopting a catalog service and a compaction story for a dataset that is cold, append-only and
already partitioned by date. The correct move is a **manifest file next to the Parquet files** — a JSON
listing of (file, row range, min/max `entry_date`, row count, ledger head hash, SHA-256 of the file) — which
is Iceberg's useful 5% at effort 3 instead of 21. If the archive ever outgrows that, migrating a
Hive-partitioned Parquet directory into Iceberg is a well-trodden path `[COMMUNITY]`.

---

### 3.5 Delta Lake — the other table format

**What it is.** Databricks' table format, structurally similar to Iceberg: immutable Parquet data files plus
a transaction log directory (`_delta_log/`) of ordered JSON commits, periodically compacted into Parquet
checkpoints. Readers reconstruct table state by replaying the log from the latest checkpoint.
`[DOCS]` https://github.com/delta-io/delta/blob/master/PROTOCOL.md

**The idea worth stealing.** *A checkpoint is just a fold over an append-only log.* Delta's checkpoints and
QAYD's `account_period_balances` are the same construct: a materialised fold that exists so readers do not
replay history, and that can always be **recomputed from the log** if it is lost or doubted. That is exactly
what `05` §C means by "projection, not cache," and Delta is independent evidence that the distinction is the
load-bearing one.

**Good at.** The same things as Iceberg. Tight Spark/Databricks integration. A simpler mental model (an
ordered log) than Iceberg's metadata tree.

**Bad at.** Historically more Databricks-centric than Iceberg, though the protocol is open and multi-engine
support has broadened `[COMMUNITY]`. Log replay cost grows between checkpoints. Same small-data overhead.

**Verdict for QAYD: do not adopt.** Same reasoning as Iceberg. But steal the vocabulary: calling
`account_period_balances` a **checkpoint over the ledger log** is a more accurate and more defensible
description than "cache" or even "rollup", and it makes the rebuild requirement obvious to reviewers.

---

### 3.6 ClickHouse — the fast one

**What it is.** An open-source columnar OLAP DBMS built around the **MergeTree** family of engines: data is
written as immutable sorted parts, merged in the background (an LSM-flavoured design), with a sparse primary
index over the sort key. Extremely fast at scan-and-aggregate.

**The idea worth stealing — and the sharpest contrast in this research.** ClickHouse materialised views are
**insert-time triggers, not periodic refreshes**: "Materialized views are, in effect, a trigger that runs
when a block is inserted into a table. They transform the data e.g. through a `GROUP BY`, before inserting
the result into a different table." `[DOCS]`
https://clickhouse.com/docs/materialized-view/incremental-materialized-view
Combined with `AggregatingMergeTree`, this gives incrementally-maintained aggregates. `[DOCS]`
https://clickhouse.com/docs/engines/table-engines/mergetree-family/aggregatingmergetree
(A separate, later **refreshable** materialised view feature provides the periodic-recompute model
instead. `[DOCS]` https://clickhouse.com/blog/clickhouse-release-23-12)

This is **the same architecture QAYD already chose** for `account_period_balances` (an `AFTER INSERT`
trigger maintaining an aggregate, `05` §C) — arrived at independently, which is a good sign for the design.

But the difference is decisive and is the reason QAYD keeps its own version:

> A ClickHouse MV insert is **not in the same transaction** as the base insert. If the MV target insert
> fails, the base row is still there and the aggregate is silently short. QAYD's trigger runs inside the
> posting transaction: either both happen or neither does. **For a trial balance, that is not a performance
> difference, it is a correctness difference.** `[INFERENCE]` from the documented trigger-on-block-insert
> model.

**Good at.** Cross-tenant scan-and-aggregate at enormous rates. Time-series and event analytics. Cost per
query — it is genuinely one of the most efficient engines available. Single-node deployment is viable
(unlike Druid/Pinot).

**Bad at.** Updates and deletes (`ALTER TABLE ... UPDATE` is an asynchronous mutation that rewrites parts —
not a transactional update). Joins, relative to a good relational planner. Transactions across tables.
Constraint enforcement — there is no equivalent of QAYD's `CHECK (signed_base_amount = base_debit - base_credit)`
protecting money at the storage layer. Eventual consistency in the merge model (duplicates can be visible
until a merge completes, which is why `FINAL` exists).

**Verdict for QAYD: not for tenant-facing data, ever. The strongest single candidate *if* internal
analytics ever outgrows DuckDB** — one node, one binary, no cluster required. Trigger for reconsidering:
`ANTI_PATTERNS.md` A-01 and `IMPLEMENTATION_RECOMMENDATIONS.md` step 8.

---

### 3.7 Apache Druid — real-time OLAP with ingestion-time rollup

**What it is.** A distributed real-time analytics store: data is ingested into immutable, time-partitioned
**segments**, and a cluster of specialised processes (historical, middle-manager, broker, coordinator,
overlord) serves queries. `[DOCS]` https://druid.apache.org/docs/latest/design/segments/

**The idea worth stealing — stated as a warning, not a compliment.** Druid's headline feature is
**ingestion-time rollup**: rows are aggregated as they arrive, at a declared granularity, and the raw rows
are **discarded**. `[DOCS]` https://druid.apache.org/docs/latest/ingestion/rollup/ That is a magnificent
trade for telemetry (nobody needs one specific ad impression) and a **disqualifying** one for accounting.

> **An accounting system may never discard a row to make a report faster.** Rollup-at-ingest is
> irreversible aggregation; QAYD's `account_period_balances` is *reversible* aggregation, because the
> ledger rows survive and the rollup is rebuildable. The difference between those two is the difference
> between a metrics store and a book of record.

**Good at.** Sub-second aggregation over event streams with high ingest rates. Time-sliced dashboards.
Approximate distinct counts at scale.

**Bad at.** Operational simplicity — the component count is the standard complaint `[COMMUNITY]`. Joins.
Updates. Exact answers when approximation is configured. Small deployments (the minimum viable cluster is
not small).

**Verdict for QAYD: reject, at every tier.** The operational cost is unjustifiable below very large scale,
and the core feature is incompatible with a book of record.

---

### 3.8 Apache Pinot — real-time OLAP with pre-computed cubes

**What it is.** A distributed real-time OLAP store aimed at user-facing analytics at high QPS (its origin is
LinkedIn's "who viewed your profile"). Segment-based like Druid, with a richer index toolbox: inverted,
sorted, range, text, JSON, and the distinctive **star-tree index**. `[DOCS]`
https://docs.pinot.apache.org/basics/indexing/star-tree-index

**The idea worth stealing.** The star-tree is a **configurable, partially-materialised data cube**: it
pre-aggregates along chosen dimension combinations up to a declared `maxLeafRecords` threshold, trading
storage for a bounded worst-case scan. The generalisable insight is *pre-aggregate the dimension
combinations you actually query, not all of them* — a cube is exponential, a star-tree is a budget.

QAYD's forward-looking version of this: when analytic dimensions land (`AD-11`, dimension rows), the
temptation will be to materialise balances by every combination of (account × cost centre × project ×
department × period). That is 2ⁿ rollups. Pinot's answer — declare the combinations, bound the leaf size —
is the right shape. `[INFERENCE]`

**Good at.** Very high QPS on user-facing analytical queries with tight latency SLAs. Ingestion from Kafka
with seconds-level freshness.

**Bad at.** The same operational weight as Druid. Joins (historically weak; improving via the multi-stage
engine). Being useful below very large scale.

**Verdict for QAYD: reject as a system; keep the star-tree budget idea for the dimensional-rollup design.**

---

### 3.9 Snowflake — the managed warehouse

**What it is.** A cloud data warehouse with the defining architectural move of **separating storage from
compute**: data lives in object storage as immutable **micro-partitions** (50–500 MB uncompressed,
columnar, with per-column min/max ranges and distinct counts used for pruning) `[DOCS]`
https://docs.snowflake.com/en/user-guide/tables-clustering-micropartitions ; compute is elastic "virtual
warehouses" that can be sized and suspended independently. `[DOCS]` The SIGMOD 2016 paper, *The Snowflake
Elastic Data Warehouse*: https://dl.acm.org/doi/10.1145/2882903.2903741

**The idea worth stealing.** Two:
1. **Storage/compute separation** — the archive should not be sized by the query engine, and the query
   engine should not run when nobody is asking. A directory of Parquet files + an on-demand DuckDB job is
   the same idea at 1/1000th the price.
2. **Time travel as a consequence of immutability** — Snowflake's time travel works because
   micro-partitions are never overwritten. `[DOCS]` https://docs.snowflake.com/en/user-guide/data-time-travel
   Exactly like Iceberg, exactly like QAYD's ledger. **Three independent systems arrive at "history is free
   if you never mutate."** QAYD already paid that price and should collect the dividend everywhere.

**Good at.** Elastic scale with no operations. Concurrency isolation (one team's query cannot slow another's
warehouse). Self-service SQL for non-engineers — genuinely its strongest practical advantage. Data sharing.

**Bad at.** Cost, in a specific and predictable way: consumption billing punishes exploratory workloads and
scheduled jobs that nobody reads `[COMMUNITY]`. Latency for small queries (there is a floor). Lock-in — the
data is in Snowflake's format unless deliberately kept external. And, decisively for QAYD: **it is a second
copy of the money, outside RLS, outside the append-only trigger, outside PITR alignment** — the exact
objection `05` §G already raises.

**Verdict for QAYD: reject for the foreseeable future.** Snowflake's justifying condition is *organisational*,
not technical: it becomes right when non-engineers need self-service SQL over a large internal dataset and
the company can staff the data team to keep it honest. QAYD has neither condition and will not for years.
See `IMPLEMENTATION_RECOMMENDATIONS.md` step 9 for the falsifiable trigger.

---

## 4. The comparison table

Scored **for QAYD's situation**, not in the abstract.

| System | Class | Solves a QAYD problem? | Op cost | Freshness model | Verdict |
|---|---|---|---|---|---|
| **PostgreSQL** (+ covering index, rollup, partitions) | OLTP + adequate OLAP | **Yes — all tenant-facing reporting** | Already paid | Transactional, exact | **The whole answer for years** |
| **Parquet** | File format | Yes — the archive format `05` §B already needs | ~0 | Immutable snapshots | **Adopt (Tier 2–3)** |
| **DuckDB** | In-process OLAP | Yes — internal analytics with zero infra | ~0 | As fresh as the export | **Adopt (Tier 3, internal only)** |
| **Arrow** | Memory format | Indirect (why DuckDB is fast) | ~0 | N/A | Understand; do not adopt |
| **Iceberg** | Table format | No — but the *pattern* is already QAYD's | Medium (catalog) | Snapshot isolation | Steal the manifest idea only |
| **Delta Lake** | Table format | No | Medium | Log + checkpoints | Steal the vocabulary only |
| **ClickHouse** | Real-time OLAP | Only if internal analytics outgrows DuckDB | Medium | Eventual (async MV) | Defer; best fallback |
| **Druid** | Real-time OLAP | No | High | Eventual + lossy rollup | **Reject** |
| **Pinot** | Real-time OLAP | No | High | Eventual | **Reject** (keep star-tree idea) |
| **Snowflake** | Managed warehouse | No — organisational trigger, not technical | $$$ | Snapshot | **Reject for years** |

---

## 5. The freshness–speed trade, and why QAYD sits at one extreme

Every system in the right half of that table buys speed by **computing the answer before the question is
asked**. They differ only in when and how lossily:

```
                        exact & current                              fast & stale
  |------------------------------------------------------------------------------|
  ^                    ^                  ^              ^          ^            ^
  |                    |                  |              |          |            |
 live SUM over      in-transaction     ClickHouse     Pinot       Druid       nightly
 ledger_entries     trigger rollup     async MV     star-tree   ingest rollup   batch
 (QAYD today)       (QAYD planned)                              (row discarded)
  \_________________________________/  \______________________________________/
        QAYD's entire viable range          disqualified for a book of record
```

QAYD's product constraint pins it to the far left, and this is not a preference:

- **A trial balance is acted upon.** `05` §C's "never cached" table lists the cases — a bank balance a user
  pays against, an AR aging before a payment run, a tax liability on a filing screen.
- **A wrong number is a legal artefact**, not a stale chart.
- **The in-transaction trigger is what makes the rollup safe**, and it is only possible because the source is
  append-only and the trigger is therefore monotonic (`05` §C).

Everything to the right of "in-transaction trigger rollup" is available to QAYD **only for internal
analytics**, where the worst outcome of staleness is a mis-drawn cohort chart.

---

## 6. What this domain contributes that `05` did not already have

Stated explicitly so this document earns its place:

| Contribution | Where |
|---|---|
| The *mechanical* reason columnar wins, decomposed into four separable effects — and which of the four QAYD already gets from a covering index | §2, here |
| **Parquet as the archive format** for `05` §B's detached partitions — a free analytics capability from a decision that has to be made anyway | `LESSONS_FOR_QAYD.md` L-02 |
| **The Iceberg parallel**, and the manifest convention that makes the archive *provable* and not merely addressable | §3.4, L-03 |
| **BRIN, honestly assessed** — why it cannot prune by tenant, and the maintenance-scan workload where it is nonetheless the cheapest win available | `BEST_PRACTICES.md` §3 |
| **The cross-period continuity defect** in the planned `account_period_balances` CHECK — each row can pass its own CHECK while the chain across periods is broken | `ANTI_PATTERNS.md` A-11, L-06 |
| **`pg_stat_statements` as the precondition for every trigger metric in `05`** — the thresholds are currently unmeasurable | `BEST_PRACTICES.md` §1 |
| **The RLS/materialised-view correctness objection**, which is stronger than `05` §C's performance objection | `ANTI_PATTERNS.md` A-05 |
| **The parallel-safety guardrail** — QAYD already got this right; a future `CREATE OR REPLACE` could silently undo it system-wide | `BEST_PRACTICES.md` §5 |
| DuckDB-over-Parquet as the entire internal-analytics answer through Tier 4 | `LESSONS_FOR_QAYD.md` L-04 |
| The "we need Kafka" arithmetic — QAYD's event rate is 3–4 orders of magnitude below Kafka's reason to exist | `ANTI_PATTERNS.md` A-03 |

---

## 7. Confidence register

**Confident.**
- Columnar storage solves a problem QAYD's tenant-facing reporting does not have. The predicate is already
  maximally selective. (`[INFERENCE]` from documented mechanics + `05`'s per-tenant framing.)
- Parquet is the right archive format. Cost of choosing it ≈ 0; option value high. `[DOCS]`
- Iceberg/Delta/Snowflake/QAYD-ledger are the same immutable-plus-pointer design and time travel is
  near-free in all four. `[DOCS]` for all three externals.
- Druid's ingestion-time rollup disqualifies it for a book of record. `[DOCS]`
- DuckDB can query Parquet in object storage with projection and filter pushdown and no server. `[DOCS]`

**Guessing — argue with these first.**
- That internal analytics stays under DuckDB's single-node comfort zone through Tier 4. It depends on event
  volume per tenant, which is unmeasured. `[UNKNOWN]`
- The 5–15× Parquet compression estimate for ledger data. Directionally safe; the exact ratio depends on
  cardinality and row-group sizing. **Measure on a real export before quoting it.** `[INFERENCE]`
- That ClickHouse is the right fallback rather than a bigger DuckDB box. Untested; revisit with real data.

**Deliberately not decided here.**
- The internal-analytics schema (event model, cohort definitions). That is a product question and belongs
  with whoever owns growth metrics.
- Whether the archive manifest should eventually become real Iceberg. Decide when the archive exceeds ~1 TB
  or a second engine needs to read it — not before.

---

## Sources

- [PostgreSQL — BRIN Indexes](https://www.postgresql.org/docs/current/brin.html)
- [PostgreSQL — REFRESH MATERIALIZED VIEW](https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html)
- [Apache Parquet — File Format](https://parquet.apache.org/docs/file-format/)
- [Apache Arrow — Columnar Format](https://arrow.apache.org/docs/format/Columnar.html)
- [Apache Iceberg — Table Spec](https://iceberg.apache.org/spec/)
- [Apache Iceberg — Reliability](https://iceberg.apache.org/docs/latest/reliability/)
- [Delta Lake — PROTOCOL.md](https://github.com/delta-io/delta/blob/master/PROTOCOL.md)
- [DuckDB — Reading Parquet](https://duckdb.org/docs/current/data/parquet/overview.html)
- [DuckDB — SIGMOD 2019 paper](https://duckdb.org/pdf/SIGMOD2019-demo-duckdb.pdf)
- [ClickHouse — Incremental materialized views](https://clickhouse.com/docs/materialized-view/incremental-materialized-view)
- [ClickHouse — AggregatingMergeTree](https://clickhouse.com/docs/engines/table-engines/mergetree-family/aggregatingmergetree)
- [ClickHouse — Release 23.12 (refreshable MVs)](https://clickhouse.com/blog/clickhouse-release-23-12)
- [Apache Pinot — Star-tree index](https://docs.pinot.apache.org/basics/indexing/star-tree-index)
- [Apache Druid — Segments](https://druid.apache.org/docs/latest/design/segments/)
- [Apache Druid — Rollup](https://druid.apache.org/docs/latest/ingestion/rollup/)
- [Snowflake — Micro-partitions and clustering](https://docs.snowflake.com/en/user-guide/tables-clustering-micropartitions)
- [Snowflake — Time Travel](https://docs.snowflake.com/en/user-guide/data-time-travel)
- [Snowflake — SIGMOD 2016 paper](https://dl.acm.org/doi/10.1145/2882903.2903741)
- [MonetDB/X100 — CIDR 2005](https://www.cidrdb.org/cidr2005/papers/P19.pdf)
