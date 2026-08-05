# LESSONS_FOR_QAYD — what the analytics domain actually means here

**Phase 3 engineering research · analytics domain.** Version 1.0 · 2026-07-28 · Status: **research, not binding**

Nine lessons, each with: **why · benefits · tradeoffs · risks · scalability · performance · maintainability ·
complexity · effort (Fibonacci) · business impact · confidence · evidence.**

Numbering **L-01 … L-09**. Sequencing and trigger metrics are in `IMPLEMENTATION_RECOMMENDATIONS.md`.

**Relationship to `05_FUTURE_ARCHITECTURE.md`:** every lesson below either (a) supplies a mechanism `05`
asserts without deriving, (b) fills a gap `05` left open, or (c) corrects something. None of them re-argue
`05`'s tier model, rollup decision or OLAP rejection — those are taken as settled.

---

## L-01 — The trigger metrics in `05` are unmeasurable today. Fix that first.

**Why.** `05`'s decision table is the operationally useful part of that document, and it specifies thresholds
in units — p95 trial-balance latency, `max(ledger rows per company_id, fiscal_year_id)` — that QAYD does not
currently collect. An alert that cannot fire is a plan, not a control.

**The specific gap.** `pg_stat_statements` is not enabled `[INFERENCE]` — no reference to it exists anywhere
in the knowledge base or migrations. And even enabled, it **normalises by query text**, so one query text
serves all tenants and a whale's 8-second report is averaged into 9,999 fast ones. `05`'s p95 threshold would
never fire while a customer times out.

| | |
|---|---|
| **Benefits** | Every later decision in this domain becomes evidence-based. Cheapest item in the entire research program by an order of magnitude. |
| **Tradeoffs** | `pg_stat_statements` adds a small, well-understood overhead and requires a restart to load. `auto_explain` with `log_analyze` costs more; use it on the slow tail only. |
| **Risks** | Query text can contain data if literals are inlined — use bound parameters (Laravel does) so normalisation is clean. Log volume: `05` already warns that observability must not out-cost the product. |
| **Scalability** | Unaffected by tier; the per-tenant metric needs the partition-wise aggregate at Tier 3+, which is cheap. |
| **Performance** | Overhead is a few percent at most `[COMMUNITY]`; the information is worth orders of magnitude more. |
| **Maintainability** | High. It is configuration plus one scheduled query. |
| **Complexity** | Minimal. |
| **Effort** | **1** (extensions + settings) + **2** (per-report-type timing in the app, the per-tenant row-count query, dashboards) |
| **Business impact** | Indirect but decisive: it is the difference between "we scale when the metric fires" and "we scale when a customer complains." |
| **Confidence** | **High** |
| **Evidence** | `[DOCS]` https://www.postgresql.org/docs/current/pgstatstatements.html · `[INFERENCE]` on QAYD's current absence |

---

## L-02 — Export detached partitions as **Parquet**. This is the single highest-leverage decision in the domain.

**Why.** `05` §B already mandates the flow: *period closes → partition becomes immutable → `DETACH PARTITION
CONCURRENTLY` → export to object storage (compressed, hash-verified) → drop*. The export **has to happen
anyway**. The only open question is the file format, and that question has a free answer.

Choosing Parquet over `COPY`-to-gzip-CSV or `pg_dump` costs nothing extra at export time and buys:

1. **5–15× compression** `[INFERENCE]` on ledger-shaped data, because the columns are exactly the kind
   columnar encodings crush — `currency_code` (≈1 distinct value per GCC tenant), `entry_type` (17 enum
   values `[CODE]`), `company_id` and `fiscal_year_id` (RLE), `id`/`posted_at`/`entry_date` (delta),
   `account_id` (dictionary + bit-packing). See `OVERVIEW.md` §2b.
2. **Queryability without restore.** "Give me FY2026 for company 4471" becomes a `SELECT` over object storage
   via DuckDB `[DOCS]` https://duckdb.org/docs/current/data/parquet/overview.html — with projection pushdown,
   filter pushdown and zone-map skipping — instead of restoring a partition into a database.
3. **Universality.** Every analytical tool made in the last decade reads Parquet. The archive stays useful
   regardless of what QAYD's stack becomes.
4. **A free internal-analytics substrate** (L-04) that requires no additional infrastructure at all.

**The practices that make it correct** are in `BEST_PRACTICES.md` §8; the two that matter most:
**sort within the file by `(company_id, account_id, entry_date)`** — sorting is what makes the encodings work
and tightens the statistics, it is free at export time and expensive to fix later — and **map
`NUMERIC(19,4)` to Parquet `DECIMAL(19,4)`, never `DOUBLE`** (`01` P14 does not stop applying at the storage
boundary).

| | |
|---|---|
| **Benefits** | Smaller archive, queryable archive, universal format, zero marginal cost over an export that must happen regardless. |
| **Tradeoffs** | A Parquet writer in the export path (Python/Arrow, or `COPY` → converter) instead of a plain `COPY`. Row-group sizing and sort order must be chosen deliberately. |
| **Risks** | A silently truncated export discovered years later by an auditor — **mitigate by verifying row count and `SUM(signed_base_amount)` against the source before dropping the partition, and never dropping without that verification passing**. Decimal-type mapping errors. |
| **Scalability** | Excellent — this is what the format is for. |
| **Performance** | Export is slower than raw `COPY`; irrelevant, it is a scheduled maintenance job. |
| **Maintainability** | Good, provided the manifest (L-03) makes the archive self-describing. |
| **Complexity** | Moderate, and entirely contained in one job. |
| **Effort** | **5** |
| **Business impact** | Storage cost down; auditor-extract turnaround from "restore a partition" to "run a query"; a whole analytics capability obtained for free. |
| **Confidence** | **High** on the decision; **medium** on the 5–15× figure — measure on a real export before quoting it. |
| **Evidence** | `[DOCS]` Parquet encodings https://parquet.apache.org/docs/file-format/data-pages/encodings/ · `[DOCS]` DuckDB pushdown, above · `[CODE]` column shapes from the ledger migration |

---

## L-03 — QAYD already built Iceberg's useful half. Finish the parallel with a manifest.

**Why.** Iceberg, Delta Lake, Snowflake and QAYD's ledger are the **same design**: immutable data plus a
small mutable pointer to metadata; a commit is an atomic pointer move; **time travel is nearly free because
nothing was ever overwritten**. `[DOCS]` Iceberg's spec states the commit protocol explicitly — writers
optimistically write new metadata and commit by *atomically swapping the metadata pointer*, and that swap
"provides the basis for serializable isolation" (https://iceberg.apache.org/spec/,
https://iceberg.apache.org/docs/latest/reliability/).

The correspondence table is in `OVERVIEW.md` §3.4 and drawn in `ARCHITECTURE.md` §3.3. The row that matters:

> Iceberg lets you prove **which** version you read. QAYD's hash chain lets you prove the version **was not
> altered**. QAYD is ahead, not behind.

**The gap this lesson closes.** When a partition is detached and exported, the Parquet files inherit
*addressability* from the layout automatically. They do **not** inherit *provability* unless the export
writes the chain state alongside. Without it, the archive is a pile of files someone has to trust.

**The lesson is not "adopt Iceberg."** Adopting Iceberg means adopting a catalog service (Glue/Nessie/REST/JDBC)
and a compaction story, for a dataset that is cold, append-only and already partitioned by date — all the
problems Iceberg solves, QAYD does not have. The useful 5% is a **manifest**:

```
s3://qayd-archive/ledger/year=2026/month=03/
    part-000.parquet
    part-001.parquet
    _manifest.json
      { "fiscal_year": 2026, "period": 3,
        "row_count": 1234567,
        "entry_date_min": "2026-03-01", "entry_date_max": "2026-03-31",
        "sum_signed_base_amount": "…",          ← exact reconciliation value
        "ledger_head_id": 998877, "ledger_head_hash": "…",
        "files": [ {"name":"part-000.parquet","rows":…,"sha256":"…"}, … ],
        "exported_at": "…", "exporter_version": "…" }
```

That is Iceberg's manifest concept at effort 3 instead of 21, and it makes the archive **self-verifying**:
the head hash ties it into the chain, so an archived year can be proven unaltered years later without the
database — which is precisely the property `05` §L identifies as the ledger's unique DR advantage, extended
to cold storage.

| | |
|---|---|
| **Benefits** | Archive becomes provable, not merely readable. Self-describing without a catalog service. Migrating to real Iceberg later is a well-trodden path from Hive-partitioned Parquet `[COMMUNITY]`. |
| **Tradeoffs** | A hand-rolled manifest is not a standard; a second engine would need to be taught it. Acceptable while there is one consumer. |
| **Risks** | Manifest and data drifting apart — mitigate by writing the manifest **last**, after checksum verification, so its presence *is* the success signal. |
| **Scalability** | Fine to thousands of partitions. Beyond that, reconsider Iceberg. |
| **Performance** | N/A (metadata). |
| **Maintainability** | High — one JSON schema, versioned. |
| **Complexity** | Low. |
| **Effort** | **3** (on top of L-02) |
| **Business impact** | Directly serves the GCC audit posture that is QAYD's differentiator: *"this archived fiscal year is provably the one the books contained."* |
| **Confidence** | **High** |
| **Evidence** | `[DOCS]` Iceberg spec + reliability · `[DOCS]` Delta protocol https://github.com/delta-io/delta/blob/master/PROTOCOL.md · `[DOCS]` Snowflake time travel https://docs.snowflake.com/en/user-guide/data-time-travel |

---

## L-04 — DuckDB over Parquet is the entire internal-analytics answer through Tier 4

**Why.** `05` §G names the legitimate analytical workload — *"internal, cross-tenant product analytics —
cohort retention, feature adoption, cost-to-serve… fed by the outbox"* — and correctly says it should never
be in the tenant database. It does not say what it *should* be. This is the answer.

That workload is genuinely OLAP-shaped: no tenant predicate, wide scans, aggregate-heavy, ad hoc,
schema-unstable — and, decisively, **nobody makes a payment against it**. It is also small: it is business
events, not ledger rows, and it has no legal fidelity requirement.

DuckDB serves it with **zero infrastructure**. Documented capabilities `[DOCS]`
https://duckdb.org/docs/current/data/parquet/overview.html: query Parquet directly (`SELECT * FROM
'x.parquet'`), glob patterns, auto-detected Hive-partitioned directories, HTTPS/S3 via `httpfs`, projection
pushdown ("only the columns required for the query are read") and filter pushdown ("can even be used to skip
parts of the file using the built-in zonemaps"). A directory of Parquet files **is** the warehouse; there is
no ingestion step, no schema registration, no cluster and no bill.

**Two hard boundaries:**

- **Out of process, always.** Never `pg_duckdb` inside the accounting database — `ANTI_PATTERNS.md` AA-09.
  The out-of-process form gives the same engine with none of the RLS-bypass surface or blast radius.
- **Advisory tier only.** No tenant-facing figure is ever computed here (`ARCHITECTURE.md` §7).

| | |
|---|---|
| **Benefits** | Real analytical SQL at zero operational cost. Works in CI and on a laptop identically. Reads the L-02 ledger archive too, so auditor extracts use the same tool. |
| **Tradeoffs** | Single-writer, single-node, not a concurrent query service. Fine for jobs and analysts; wrong for a hundred dashboard users. |
| **Risks** | Success breeds misuse: someone points a product feature at it (AA-14) or lets it become a system of record. Enforce with credentials — read-only, advisory bucket only. |
| **Scalability** | Comfortable to a few hundred GB on one machine `[COMMUNITY]`. Beyond that, single-node ClickHouse — still one box. |
| **Performance** | Vectorised execution; excellent for this shape. |
| **Maintainability** | Excellent — a pinned library version, no service. |
| **Complexity** | Very low. |
| **Effort** | **3** for the job harness (+ **5** for the `analytics_events` table and outbox feed) |
| **Business impact** | Unit economics, cohort retention and AI cost-to-serve become answerable — which `05` §E argues are the numbers that decide whether QAYD is a good business. |
| **Confidence** | **High** on suitability; **medium** on how long single-node suffices (`[UNKNOWN]` — depends on unmeasured event volume). |
| **Evidence** | `[DOCS]` DuckDB Parquet docs · `[DOCS]` DuckDB SIGMOD 2019 https://duckdb.org/pdf/SIGMOD2019-demo-duckdb.pdf |

---

## L-05 — BRIN is a maintenance-scan tool here, not a reporting tool. Adopt it; scope it correctly.

**Why.** The received wisdom — "append-only, time-ordered table, therefore BRIN" — is half right in a way
that produces a wrong conclusion.

BRIN prunes by physical block range using per-range min/max `[DOCS]`
https://www.postgresql.org/docs/current/brin.html (default `pages_per_range` = 128). Its value is entirely a
function of physical correlation. On `ledger_entries` `[CODE]`:

- `posted_at`, `created_at`, `id` — **near-perfect** correlation (append-only insert order).
- `entry_date` — **approximate**; back-dating within an open period widens ranges, and it does so hardest at
  period-end when reports run hardest.
- `company_id` — **no correlation**. Tenants interleave, so every range spans the id space.

And **every tenant-facing query filters `company_id` first** (`05`'s per-tenant framing). Therefore BRIN
prunes nothing for reporting. What it *does* serve is the set of queries QAYD has no good index for at all,
all of them cross-tenant: hash-chain verification, the `VerifyLedgerProjectionAction` drift scan (`05` §B
option c), archival range selection, retention sweeps, backfills.

`CREATE INDEX … USING BRIN (posted_at)` — a few MB against ~9 GB for the btree equivalent `[INFERENCE]`, and
negligible write cost on append.

| | |
|---|---|
| **Benefits** | Near-free index; speeds the exact maintenance jobs the append-only design depends on. |
| **Tradeoffs** | Zero benefit to the queries people will expect it to help — which is a communication risk more than a technical one. |
| **Risks** | Being cited as "we tried indexing and it didn't help," pushing the team toward AA-01/AA-02. Document the scope where the index is created. |
| **Scalability** | Improves with table size — that is BRIN's whole shape. |
| **Performance** | Large win on unindexed cross-tenant scans; **zero** on tenant-scoped reports. |
| **Maintainability** | Trivial. Re-evaluate after partitioning, since partitions already prune the date range. |
| **Complexity** | Minimal. |
| **Effort** | **1** |
| **Business impact** | Small and operational: maintenance jobs and audit verification stay fast as the ledger grows. |
| **Confidence** | **High** on the mechanism and the scoping; **medium** on the size of the win (depends on job frequency). |
| **Evidence** | `[DOCS]` PostgreSQL BRIN · `[CODE]` ledger column definitions |

---

## L-06 — The planned `account_period_balances` CHECK does not catch the worst bug it will have

**Why.** `05` §C specifies `CHECK (closing = opening + debit - credit)`. That constraint is **per row**. The
identity that actually holds accounting together is **between** rows:
`period(N+1).opening = period(N).closing`, per `(company_id, account_id)`.

A back-dated posting into period 3, or a repair that rebuilds only period 3, leaves periods 4–12 with stale
`opening` values. Every one of those rows still satisfies its own CHECK. The database is content, every
screen is internally consistent, and the balance sheet is wrong from period 4 onward. `ANTI_PATTERNS.md`
AA-11 draws it.

This is a **defect class in a design that has not shipped yet** — the cheapest possible moment to fix it.

**Three mechanisms, all cheap:**

1. **Cascade by construction** — rebuilding period N rebuilds N…end-of-fiscal-year for the affected
   `(company, account)` pairs. Never a single period.
2. **Continuity assertion in the drift checker** — a window comparison of consecutive periods per
   `(company, account)`. Catches every instance, including ones future code introduces.
3. **Invalidation keyed on `entry_date`, not `now()`** — a posting made today with a March `entry_date`
   invalidates March *and everything after*. Keying on the current period is wrong for exactly the entries
   that are most common at period-end.

| | |
|---|---|
| **Benefits** | Closes a silent, high-severity correctness gap before it can ship. Makes the rollup trustworthy enough to be the *serving* path, which is what `05` §G stage 3 requires. |
| **Tradeoffs** | A rebuild becomes O(remaining periods) rather than O(1) — negligible at ~12 periods. The drift check gains a second query. |
| **Risks** | The continuity check being slow and therefore disabled — `05` §C already warns about this failure mode. Keep it partition-wise (`enable_partitionwise_aggregate`) so it stays fast. |
| **Scalability** | Fine — the check is over the rollup (~60 rows/account-year), not the ledger. |
| **Performance** | Negligible cost. |
| **Maintainability** | Improves it: the invariant becomes executable instead of tribal. |
| **Complexity** | Low. |
| **Effort** | **3** |
| **Business impact** | Prevents the single worst outcome available to this product — a wrong balance sheet that nothing detects. |
| **Confidence** | **High** |
| **Evidence** | `[INFERENCE]` from the `05` §C schema; the gap is structural and does not require a test to confirm |

---

## L-07 — Materialised views are rejected here for a **correctness** reason, not a performance one

**Why.** `05` §C rejects materialised views because `REFRESH` is a full recompute and `CONCURRENTLY` needs a
unique index and still rebuilds everything — both documented `[DOCS]`
https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html (`CONCURRENTLY` requires a `UNIQUE`
index on column names only with no `WHERE`, requires the view to be populated, and permits only one refresh
at a time).

The stronger objection is tenancy: **the view is populated in the refresher's GUC context.**
`app_current_company_id()` reads `app.current_company_id` `[CODE]`; a maintenance job has none, so the
refresh yields an **empty** view (QAYD's policies fail closed on NULL) or, under an RLS-bypassing role, a
**cross-tenant** one. And RLS is a *table* feature, so the result cannot be re-protected after the fact.

This upgrade matters because performance objections get overturned by clever engineering and correctness
objections do not. Someone will eventually propose "a materialised view refreshed incrementally by X"; the
performance argument would not survive that proposal, and the tenancy argument does.

| | |
|---|---|
| **Benefits** | A rejection that stays rejected. Generalises: *no tenant-scoped monetary data in any object that is populated outside a tenant session.* |
| **Tradeoffs** | None — the alternative (`account_period_balances`, an ordinary table with ordinary RLS) is already chosen. |
| **Risks** | The `[INFERENCE]` grade. Verify the exact behaviour against the deployed PostgreSQL version before citing it as fact; design as if true regardless. |
| **Scalability / Performance / Maintainability / Complexity** | N/A — this is a rejection, not a build. |
| **Effort** | **1** (record it against `05` §C and `04_REJECTED_PATTERNS`) |
| **Business impact** | Prevents a plausible-looking cross-tenant data exposure — the highest-severity class in this system. |
| **Confidence** | **High** on the conclusion; **medium** on the precise mechanism until verified. |
| **Evidence** | `[DOCS]` REFRESH MATERIALIZED VIEW · `[DOCS]` RLS is a table feature https://www.postgresql.org/docs/current/ddl-rowsecurity.html · `[CODE]` the RLS function definitions · `[INFERENCE]` for the combination |

---

## L-08 — QAYD already has its event log. Kafka would be a downgrade for three to four orders of magnitude.

**Why.** The outbox gives durability tied to the same transaction as the fact, ordering by `id`,
at-least-once delivery and idempotent consumption (`05` §D). Kafka provides similar properties in a
**different durability domain**, which reintroduces the two-phase problem the outbox exists to solve.

The arithmetic, from `05`'s capacity table:

| Tier | Ledger rows/month | Avg events/s | Peak events/s | Kafka's design point |
|---|---|---|---|---|
| T2 (1,000) | 3.36 M | ~5 | ~53 | 10⁵–10⁶/s |
| T3 (10,000) | 33.6 M | ~13 | ~100 | 10⁵–10⁶/s |
| T4 (100,000) | 336 M | ~130 | ~760 | 10⁵–10⁶/s |

And the structural point: **an accounting system's event volume is bounded by human business activity.** It
cannot spike the way clickstream or telemetry can, because a human authors a journal entry. That ceiling is
architectural, not a current measurement.

| | |
|---|---|
| **Benefits** | Avoids a second durability domain, a second on-call surface, and a class of dual-write bugs — for a team whose ledger is the crown jewel. |
| **Tradeoffs** | The outbox drain is QAYD's to operate and shard (`05` §D already plans bucketing by `company_id`). |
| **Risks** | The reflex resurfacing whenever "event-driven" is said aloud. Keep the arithmetic where reviewers can find it (AA-03). |
| **Scalability** | The outbox is sufficient through Tier 4 on these numbers. |
| **Performance** | Not a bottleneck at any modelled tier. |
| **Maintainability** | Strongly favourable — one fewer distributed system. |
| **Complexity** | Avoided rather than added. |
| **Effort** | **0** (a decision to keep not doing something) |
| **Business impact** | Avoided infrastructure and headcount that would otherwise be spent on a solved problem. |
| **Confidence** | **High** through Tier 4. |
| **Evidence** | `05` capacity table · `[COMMUNITY]` on Kafka's design point · `[INFERENCE]` on the human-activity ceiling |

---

## L-09 — Extensions (Citus, TimescaleDB, pg_duckdb) — take the ideas, not the dependencies

**Why.** Three PostgreSQL extensions look relevant. Assessed honestly, two are ideas and one is a hazard.

### Citus — a candidate *implementation* of `05`'s Tier-4 shard plan, not an analytics tool

Distributed PostgreSQL sharded on a distribution column. QAYD's natural distribution column is `company_id` —
exactly the sharding `05` §A describes at Tier 4. So Citus is not a warehouse decision; it is a
shard-mechanism decision, deferred to the Tier-4 trigger (`> 2 TB primary`, `> 8,000 writes/s`).

The cost to investigate **before** adopting: how RLS policies and `SET LOCAL` GUCs behave across coordinator
and worker nodes. That is the same hazard class `05` already flags for PgBouncer, and it deserves the same
treatment — **write the isolation test first**, the `PgBouncerSafetyTest` analogue, and let it gate the
rollout. `[UNKNOWN]` — QAYD has not evaluated this and should not until the trigger fires.

### TimescaleDB — the compression idea is right; the aggregate idea is a downgrade

Hypertables (automatic time partitioning), continuous aggregates (incrementally refreshed, with invalidation
tracking `[DOCS]` https://docs.timescale.com/use-timescale/latest/continuous-aggregates/) and columnar
compression of old chunks.

- **Continuous aggregates** are the closest off-the-shelf match to `account_period_balances` — and still
  worse, because refresh is driven by a **background policy** (eventual) rather than by the posting
  transaction (exact). For a trial balance, eventual is not a weaker guarantee, it is a wrong number.
- **Columnar compression of closed chunks** is genuinely attractive at Tier 3+, and it maps perfectly onto
  the closed/open partition bimodality (`ARCHITECTURE.md` §5.4). But **Parquet export achieves the same
  compression and removes the rows from vacuum, index and restore scope entirely** — a strictly better
  outcome, with no extension in the database that holds the ledger.

Take the **invalidation-range** idea (which is exactly L-06's third mechanism). Leave the extension.

### pg_duckdb / pg_analytics — reject by default

An analytical engine inside the accounting database introduces an alternative scan path over tenant tables
(an RLS surface to audit) and puts an unbounded analytical query inside a database backend (blast radius).
Full argument at `ANTI_PATTERNS.md` AA-09. The benefit is available for free out of process (L-04).

| | |
|---|---|
| **Benefits** | Keeps the accounting database boring, which is the correct aesthetic for a book of record. |
| **Tradeoffs** | Some hand-building where an extension would have done it — the rollup, the export, the drift checks. All already planned in `05`. |
| **Risks** | Citus's RLS/GUC interaction is a real `[UNKNOWN]` that will need real investigation at Tier 4. Do not let it be discovered during a migration. |
| **Scalability** | Citus remains the most likely Tier-4 shard mechanism; nothing here forecloses it. |
| **Performance** | No loss — the Parquet route beats in-database compression on every axis that matters here. |
| **Maintainability** | Fewer extensions in the ledger's database is a maintainability win in itself. |
| **Complexity** | Lower. |
| **Effort** | **0** now; **8** for the Citus RLS/GUC isolation test when the Tier-4 trigger fires |
| **Business impact** | Avoids an extension-driven upgrade treadmill on the one database that must never be down. |
| **Confidence** | **High** on pg_duckdb and TimescaleDB; **medium** on Citus (genuinely unevaluated, and correctly so). |
| **Evidence** | `[DOCS]` Timescale continuous aggregates · `[DOCS]` Citus https://docs.citusdata.com/ · `[INFERENCE]` on the RLS surface of in-process engines |

---

## The lessons in one table

| # | Lesson | Effort | Confidence | Tier |
|---|---|---|---|---|
| L-01 | `pg_stat_statements` + per-report/per-tenant metrics — `05`'s triggers are unmeasurable today | 1 + 2 | High | **Now** |
| L-05 | `BRIN (posted_at)` for maintenance scans; never sold as a reporting fix | 1 | High | **Now** |
| L-07 | Materialised views rejected on tenancy grounds, not performance | 1 | High | **Now** |
| L-06 | Cross-period continuity check + `entry_date`-keyed invalidation for the rollup | 3 | High | With the rollup |
| L-02 | Parquet as the partition-export format | 5 | High | T2–T3 |
| L-03 | Manifest with `ledger_head_hash` — make the archive provable | 3 | High | With L-02 |
| L-04 | DuckDB over Parquet for internal analytics, out of process | 3 (+5) | High | T3 |
| L-08 | No Kafka — the outbox is the log | 0 | High | Standing |
| L-09 | Take ideas from Citus/Timescale; reject in-database DuckDB | 0 (+8 at T4) | Med–High | Standing |

**Total effort to act on everything actionable before Tier 3: 1 + 2 + 1 + 1 + 3 + 5 + 3 + 3 = 19 points.**
That is the entire analytics programme, and it contains no new system to operate.

---

## Sources

- [PostgreSQL — pg_stat_statements](https://www.postgresql.org/docs/current/pgstatstatements.html)
- [PostgreSQL — BRIN Indexes](https://www.postgresql.org/docs/current/brin.html)
- [PostgreSQL — REFRESH MATERIALIZED VIEW](https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html)
- [PostgreSQL — Row Security Policies](https://www.postgresql.org/docs/current/ddl-rowsecurity.html)
- [Apache Parquet — encodings](https://parquet.apache.org/docs/file-format/data-pages/encodings/)
- [Apache Iceberg — Table Spec](https://iceberg.apache.org/spec/)
- [Apache Iceberg — Reliability](https://iceberg.apache.org/docs/latest/reliability/)
- [Delta Lake — PROTOCOL.md](https://github.com/delta-io/delta/blob/master/PROTOCOL.md)
- [Snowflake — Time Travel](https://docs.snowflake.com/en/user-guide/data-time-travel)
- [DuckDB — Reading Parquet](https://duckdb.org/docs/current/data/parquet/overview.html)
- [DuckDB — SIGMOD 2019](https://duckdb.org/pdf/SIGMOD2019-demo-duckdb.pdf)
- [TimescaleDB — Continuous aggregates](https://docs.timescale.com/use-timescale/latest/continuous-aggregates/)
- [Citus documentation](https://docs.citusdata.com/)
