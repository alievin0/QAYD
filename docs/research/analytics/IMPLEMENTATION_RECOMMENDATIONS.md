# IMPLEMENTATION_RECOMMENDATIONS — sequenced, with explicit trigger metrics

**Phase 3 engineering research · analytics domain.** Version 1.0 · 2026-07-28 · Status: **research, not binding**

Ten steps, in dependency order. Each carries **the trigger that justifies starting it**, effort (Fibonacci),
confidence, and the specific thing that must be true before it ships.

**Every trigger below reuses a metric already defined in `05_FUTURE_ARCHITECTURE.md`.** No parallel set of
thresholds is invented here — where a new metric appears (steps 1 and 5), it is an *instrument for* an
existing `05` threshold, not a replacement for it.

---

## The rule this sequence is written under

> **Do the work when the metric fires — not before (waste) and not after (incident).**
> `05_FUTURE_ARCHITECTURE.md`, "Scale trigger → action decision table"

Steps 1–3 are the exception: they are **effort 1 each, they are preconditions for measuring anything**, and
they should be done now, at zero customers, precisely because they are what make the rest of this sequence
trigger-driven rather than guesswork.

---

## Summary table

| # | Step | Trigger | Effort | Conf | Tier |
|---|---|---|---|---|---|
| 1 | Enable `pg_stat_statements` + `auto_explain` + `track_io_timing` | **None — do now.** Precondition for every `05` DB trigger | 1 | High | Now |
| 2 | CI assertion: `app_*()` stay `STABLE` + `PARALLEL SAFE` | **None — do now.** Regression guard on an existing correct property | 1 | High | Now |
| 3 | `BRIN (posted_at)` on `ledger_entries` | **None — do now.** A few MB; speeds maintenance scans | 1 | High | Now |
| 4 | Per-report and per-tenant reporting metrics in app telemetry | Step 1 done | 2 | High | Now / T1 |
| 5 | Nightly `max(rows per company_id, fiscal_year_id)` alarm at 200 K / 1 M | Step 1 done | 1 | High | T1 |
| 6 | Covering index; autovacuum insert-threshold tuning | `05`: **p95 TB > 200 ms** or **max rows/tenant-FY > 200 K** | 2 (+2) | High | T1→T2 |
| 7 | Rollup hardening: cross-period continuity + `entry_date`-keyed invalidation | Ships **with** `account_period_balances`, not after | 3 | High | With rollup |
| 8 | Parquet partition export + `_manifest.json` with `ledger_head_hash` | `05`: **`ledger_entries` > 300 GB** (design) / **> 600 GB** (execute) | 5 + 3 | High | T2→T3 |
| 9 | `analytics_events` from the outbox + DuckDB job over the archive | First unanswerable business question, or T3 | 5 + 3 | Med-High | T3 |
| 10 | Single-node ClickHouse for cross-shard internal analytics | `05`: **primary > 2 TB / shard count > 1** *and* DuckDB measured insufficient | 21 | Low | T4 |
| — | Managed warehouse (Snowflake/BigQuery) | **Organisational**, see §11 | 34+ | Low | Not foreseen |

**Total to the end of step 9: 26 points, no new service to operate.**

---

## Step 1 — Enable query measurement (do now)

**Trigger: none. This is a precondition, not a response.**

`05`'s decision table specifies actions at *"p95 trial-balance latency > 200 ms"* and *"> 500 ms."* Neither is
currently measurable `[INFERENCE]` — no reference to `pg_stat_statements` exists in the knowledge base or
migrations. **Until this ships, every database threshold in `05` is decorative.**

**What to do**

- `shared_preload_libraries = 'pg_stat_statements'` (requires a restart) + `CREATE EXTENSION`
- `auto_explain` with `log_min_duration_statement` set to the slow tail only, `log_analyze = on`
- `track_io_timing = on` — splits time into CPU vs I/O, which is the difference between "add an index" and
  "add RAM"
- `log_lock_waits = on`

**Ship criteria.** A dashboard showing top queries by `total_exec_time` and by `mean_exec_time`, and
`shared_blks_read` per call for the trial-balance query.

**Known limitation to design around:** `pg_stat_statements` normalises by query text with parameters
stripped, so all tenants collapse into one row and a whale's slow report is averaged away. Step 4 is the fix;
do not treat step 1 as sufficient on its own.

| Effort | **1** | Confidence | **High** | Risk | Log volume — `05` already warns observability must not out-cost the product |
|---|---|---|---|---|---|

---

## Step 2 — Guard the parallel-safety markers in CI (do now)

**Trigger: none. This protects a property QAYD already has.**

`[DOCS]` https://www.postgresql.org/docs/current/sql-createfunction.html — functions are **`PARALLEL UNSAFE`
by default**. `[DOCS]` https://www.postgresql.org/docs/current/parallel-query.html — the planner will not
produce a parallel plan if any function in the query is parallel-unsafe. RLS policy expressions are in
*every* query on *every* tenant table.

QAYD got this right `[CODE]`
(`apps/api/database/migrations/2026_07_27_000009_enable_row_level_security.php`): `app_current_company_id()`,
`app_current_user_id()` and `app_is_platform_admin()` are all `STABLE PARALLEL SAFE`.

**The hazard is regression.** They are defined with `CREATE OR REPLACE`; a future migration that re-creates
one without repeating the markers silently reverts it to the default and **disables parallel query on every
table in the system**, with no error, forever.

**What to do** — add to the existing CI catalog checks:

```
SELECT proname, provolatile, proparallel
FROM pg_proc
WHERE proname IN ('app_current_company_id','app_current_user_id','app_is_platform_admin');
-- require provolatile = 's' AND proparallel = 's'  → else fail the build
```

**Where it belongs:** `05`'s *"Correctness and tenancy (do these regardless of tier)"* table, threshold **1**,
alongside the `SET LOCAL` and RLS catalog checks.

| Effort | **1** | Confidence | **High** | Risk | None |
|---|---|---|---|---|---|

---

## Step 3 — `BRIN (posted_at)` (do now)

**Trigger: none. It costs a few MB.**

```
CREATE INDEX idx_ledger_brin_posted ON ledger_entries USING BRIN (posted_at);
```

**Scope it in the migration comment**, because the scoping is the whole point:

> *Maintenance-scan index. `posted_at` is near-perfectly correlated with physical order because the table is
> append-only. This index does NOT and CANNOT help tenant-scoped reporting — `company_id` values interleave
> across every block range, so BRIN prunes nothing for a query filtered by company. It exists for
> cross-tenant scans: hash-chain verification, the ledger-projection drift check, archival range selection,
> retention sweeps. Do not drop `idx_ledger_account_date` for it.*

Full reasoning: `BEST_PRACTICES.md` §3, `ANTI_PATTERNS.md` AA-10, `LESSONS_FOR_QAYD.md` L-05.

**Re-evaluate after partitioning** — a date-range partition already prunes the range BRIN was pruning.

| Effort | **1** | Confidence | **High** on the mechanism, **medium** on the size of the win |
|---|---|---|---|

---

## Step 4 — Per-report and per-tenant reporting telemetry

**Trigger: step 1 complete.**

The metric `05` actually specifies — *p95 trial-balance latency* — cannot come from the database, for the
normalisation reason above. It has to come from the application.

**What to do**

- Time every report execution in the reporting service; emit `(report_type, company_id, duration_ms, rows)`.
- Alert on **p95 per `report_type`**, not on the global mean.
- Tag the SQL with `/* qayd:report=trial_balance */` so slow-query logs are attributable without a stack
  trace.

**Ship criteria.** `05`'s two thresholds (200 ms → covering index; 500 ms → serve from rollup) exist as live
alerts, per report type.

| Effort | **2** | Confidence | **High** | Business impact | Turns `05`'s decision table from a plan into a control |
|---|---|---|---|---|---|

---

## Step 5 — The tenant-shape alarm

**Trigger: step 1 complete.**

`05`'s sharpest framing is that *"total row count is the wrong alarm"* — the metric that matters is
`max(rows per (company_id, fiscal_year_id))`, with thresholds at **200,000** (covering index) and
**1,000,000** (rollup as the serving path).

**What to do** — a nightly scheduled query:

```
SELECT max(c) FROM (
  SELECT count(*) AS c FROM ledger_entries GROUP BY company_id, fiscal_year_id
) t;
```

Alert at 200,000 and at 1,000,000. Also emit the **top 10 tenants by row count** — `05` states that "the
first real scaling crisis will be a single whale customer, not customer count," and this is the query that
sees the whale coming.

After partitioning, enable `enable_partitionwise_aggregate` on the maintenance role so this stays cheap
`[DOCS]` https://www.postgresql.org/docs/current/runtime-config-query.html (it is **off by default**).

| Effort | **1** | Confidence | **High** |
|---|---|---|---|

---

## Step 6 — Covering index, and make the index-only scan real

**Trigger (`05`, verbatim): p95 trial-balance latency > 200 ms, or `max(ledger rows per company_id,
fiscal_year_id)` > 200,000.**

The index is `05` §C step 2 and is not re-argued:

```
(company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)
```

**The addition this research makes — verify it is actually index-only.**

```
EXPLAIN (ANALYZE, BUFFERS) …    →  require "Index Only Scan" AND "Heap Fetches" ≈ 0
```

An index-only scan consults the **visibility map**, which is maintained by VACUUM — and an insert-only table
produces no dead tuples, so ordinary autovacuum thresholds may never fire. The result is a covering index
that silently degrades into an index scan with a heap fetch per row, and a team concluding the index "didn't
work."

**Fix** `[DOCS]` https://www.postgresql.org/docs/current/runtime-config-autovacuum.html:

```
ALTER TABLE ledger_entries SET (
  autovacuum_vacuum_insert_threshold    = 10000,
  autovacuum_vacuum_insert_scale_factor = 0.05
);
```

Numbers are a starting point. **Measure `Heap Fetches` before and after** — that is the acceptance test.

**Also at this step:** create a `qayd_reporting` role with its own `work_mem` and
`max_parallel_workers_per_gather`, rather than raising them globally. `work_mem` applies **per node per
worker** `[DOCS]` https://www.postgresql.org/docs/current/runtime-config-resource.html, so a global
`256MB` with 3 hash nodes × 4 workers × 50 sessions is an OOM, not a fast report. This also gives `05` §G's
"separate connection names" recommendation a stronger form: guarantees and resource budgets travel together
on the role.

| Effort | **2** (index) + **2** (autovacuum + role) | Confidence | **High** |
|---|---|---|---|

---

## Step 7 — Harden the rollup *when it is built*, not afterwards

**Trigger: ships with `account_period_balances` (`05` §C, effort 8). This is a companion, not a follow-up.**

`05` §C specifies `CHECK (closing = opening + debit - credit)`. That is a **per-row** constraint, and the
identity that holds accounting together is **between** rows.

```
   period 3   opening=1000  debit=500  credit=200  closing=1300   ✔ CHECK passes
   period 4   opening=1000  debit=300  credit=100  closing=1200   ✔ CHECK passes
                     ^^^^ stale after a period-3 rebuild

   Both rows valid. Chain broken. Balance sheet wrong from period 4 on.
   Nothing detects it.
```

**Three mechanisms, all required:**

1. **Cascade by construction** — `RebuildPeriodBalancesAction` rebuilds period N **through end of fiscal
   year** for the affected `(company_id, account_id)` pairs. There is no single-period rebuild API.
2. **Continuity assertion in the drift checker** — for consecutive periods per `(company, account)`:
   `period(N+1).opening = period(N).closing`. Cheap (it reads the rollup, not the ledger); keep it
   partition-wise so it stays fast and therefore stays enabled — `05` §C already warns that a slow drift
   check gets disabled.
3. **Invalidation keyed on `entry_date`, never `now()`** — a posting made today with a March `entry_date`
   invalidates March **and every period after it**. Keying invalidation on the current period is wrong for
   exactly the entries that are most common at period-end.

**Ship criteria.** A test that back-dates a posting into a closed-but-not-locked earlier period and asserts
that (a) every subsequent period's `opening` moved, and (b) the continuity check fails if the cascade is
skipped.

Full argument: `ANTI_PATTERNS.md` AA-11, `LESSONS_FOR_QAYD.md` L-06.

| Effort | **3** | Confidence | **High** | Business impact | Prevents a silently wrong balance sheet — the worst outcome available to this product |
|---|---|---|---|---|---|

---

## Step 8 — Export detached partitions as Parquet, with a manifest

**Trigger (`05`, verbatim): `ledger_entries` size > 300 GB → design the partition scheme; > 600 GB → execute
the partition migration.** The export format decision is part of the design, so it is made at the **300 GB**
trigger and implemented with the archival flow.

`05` §B already mandates *"export to object storage (compressed, hash-verified)."* The only open question is
the format, and Parquet costs nothing extra.

**Layout**

```
s3://qayd-archive/ledger/year=2026/month=03/
    part-000.parquet          ← sorted by (company_id, account_id, entry_date)
    part-001.parquet
    _manifest.json
```

**`_manifest.json`** — the Iceberg idea at effort 3 instead of 21:

```
{ "fiscal_year": 2026, "period": 3,
  "row_count": 1234567,
  "entry_date_min": "2026-03-01", "entry_date_max": "2026-03-31",
  "sum_signed_base_amount": "…",              ← exact reconciliation value
  "ledger_head_id": 998877, "ledger_head_hash": "…",
  "files": [ {"name": "part-000.parquet", "rows": …, "sha256": "…"}, … ],
  "exported_at": "…", "exporter_version": "…" }
```

**Non-negotiable practices** (`BEST_PRACTICES.md` §8):

- Row groups **128 MB – 1 GB**.
- **Sort by `(company_id, account_id, entry_date)` inside the file** — this is what makes RLE and dictionary
  encoding work and tightens the statistics that drive pruning. Free at export, expensive to fix later.
- `NUMERIC(19,4)` → Parquet **`DECIMAL(19,4)`**, never `DOUBLE`. `01` P14 does not stop at the storage
  boundary.
- **Hive-style directories** (`year=`/`month=`) so DuckDB prunes before opening a file `[DOCS]`
  https://duckdb.org/docs/current/data/parquet/overview.html
- **Never one directory per company** — that is `05` §B's partition-count mistake in a second venue
  (`ANTI_PATTERNS.md` AA-13). Bucket: `company_bucket = company_id % 64` if a tenant axis is needed.
- **Write the manifest last, after checksum verification**, so its presence *is* the success signal.

**Ship criteria — the acceptance test that matters.** Before the partition is dropped: exported row count and
`SUM(signed_base_amount)` must match the source **exactly**. An export that silently truncated is discovered
years later, by an auditor.

| Effort | **5** (export) + **3** (manifest) | Confidence | **High** on the decision; **medium** on the 5–15× compression estimate — measure it |
|---|---|---|---|

---

## Step 9 — `analytics_events` from the outbox, and a DuckDB job over the archive

**Trigger: the first business question that cannot be answered with a SQL query someone types by hand — or
Tier 3, whichever comes first.**

`05` §G names this workload and says it "should never be in the tenant database" and "is fed by the outbox."
This step is what it should be.

**9a — start the dataset early (Tier 2, effort 5).** An `analytics_events` append-only table fed by the
outbox drain. Start it before the questions arrive, because **history cannot be backfilled**.

Rules:
- Append-only, versioned schema. **Add fields; never repurpose them** — renaming breaks every historical
  comparison.
- **No tenant PII.** Company id or a hashed surrogate, plan, cohort, counts. No names, no
  customer-attributable amounts. This keeps the advisory tier outside the residency and RLS questions
  entirely, which is worth more than the analysis it forgoes.
- It must be **deletable and rebuildable**. If it stops being either, it has become a system of record —
  that is the test.

**9b — the DuckDB job (Tier 3, effort 3).** Export `analytics_events` to Parquet on a schedule; query with
DuckDB in a job. Documented capabilities that make this work with no infrastructure `[DOCS]`
https://duckdb.org/docs/current/data/parquet/overview.html: direct Parquet reads, globs, auto-detected Hive
partitioning, S3 via `httpfs`, projection pushdown and filter pushdown with built-in zone maps.

**Hard boundaries:**

| Rule | Enforcement |
|---|---|
| Out of process — never `pg_duckdb` in the accounting DB | `ANTI_PATTERNS.md` AA-09; reject at review |
| Read-only credentials, advisory bucket only | IAM, not convention |
| No tenant-facing feature reads the archive | AA-14; if a tenant needs a closed year, re-attach the partition or produce a document asynchronously |
| Every advisory number carries an as-of | Required prop on the component, so omission fails the build |
| Memory limit set on the DuckDB session | An unbounded analytical query on a shared runner is an outage for everything else on it |

| Effort | **5** + **3** | Confidence | **High** on suitability; **medium** on how long single-node suffices (`[UNKNOWN]` — event volume unmeasured) |
|---|---|---|---|

---

## Step 10 — Single-node ClickHouse, only if measured

**Trigger — all three, together:**

1. `05` Tier-4 sharding has happened (**primary > 2 TB**, or **peak row-writes/s > 8,000**), so cross-shard
   internal analytics can no longer be one SQL query; **and**
2. the DuckDB job has been **measured** as insufficient — runtime or memory, with numbers; **and**
3. more than a handful of people query the dataset concurrently.

Cross-shard internal analytics is **the first genuine technical justification for a separate analytical store
in QAYD's entire roadmap**, and it arrives at ~100,000 customers and ~$10M MRR (`05` capacity table).

**Why ClickHouse and not Druid/Pinot/Snowflake:** it runs usefully on **one node**, which Druid and Pinot do
not `[COMMUNITY]`, and it does not require a data team or consumption billing. It is also the closest
architectural cousin to what QAYD already does — insert-triggered incremental aggregates `[DOCS]`
https://clickhouse.com/docs/materialized-view/incremental-materialized-view.

**What stays true even then:** advisory tier only. No ledger data. No tenant-facing figure. Deletable and
rebuildable from the outbox. `ARCHITECTURE.md` §7's one-way arrow does not bend at Tier 4.

| Effort | **21** | Confidence | **Low** (genuinely unevaluated, and correctly so — do not evaluate before the trigger) |
|---|---|---|---|

---

## §11 — The managed-warehouse trigger is organisational, not technical

Snowflake / BigQuery / Databricks become defensible when **all three** hold:

1. Non-engineers need **self-service SQL** over the internal dataset — this is genuinely Snowflake's
   strongest practical advantage, and it is a people problem, not a performance one.
2. The dataset exceeds what one machine handles comfortably **and** more than ~10 people query it daily.
3. The company can **staff someone to own its correctness** — a broken pipeline produces plausible numbers,
   which is worse than an error.

Notice that **none of these is "the trial balance got slow."** If a warehouse is ever proposed as the fix for
a reporting latency problem, that proposal has misdiagnosed the workload (`ANTI_PATTERNS.md` AA-02) and the
correct response is `05` §C's ladder.

**And the standing prohibition, at every tier and every headcount:**

> No tenant-facing financial figure is ever computed, stored or served from an analytical store. The ledger
> has one system of record. `01_ENGINEERING_PRINCIPLES` P19.

---

## What to add to `05`'s decision table

These belong in `05_FUTURE_ARCHITECTURE.md`'s "Scale trigger → action decision table" when it is next
revised, so that the analytics domain is operationally wired in rather than living only in this folder:

**Correctness and tenancy (do regardless of tier)**

| Metric | Threshold | Action | Why |
|---|---|---|---|
| `app_*()` functions not `STABLE` + `PARALLEL SAFE` | **1** | Fail the build | Silently disables parallel query on every table in the system |
| A materialised view over a `company_id`-scoped table | **1** | Reject at review | Populated outside a tenant session ⇒ empty or cross-tenant (`AA-05`) |
| A monetary aggregate computed outside the accounting module | **1** | Reject at review | Second posting engine (`AA-08`, `01` P19) |
| `account_period_balances` rebuild that does not cascade forward | **1** | Fail the build | Per-row CHECK passes while the period chain is broken (`AA-11`) |

**Database performance**

| Metric | Threshold | Action |
|---|---|---|
| `pg_stat_statements` not installed | **1** | Install it — every threshold below is unmeasurable without it |
| `Heap Fetches` on the trial-balance index-only scan | > 10% of rows | Tune `autovacuum_vacuum_insert_threshold` on `ledger_entries` |
| Parquet export row count or `SUM(signed_base_amount)` ≠ source | **1** | **Do not drop the partition.** Incident |
| Advisory number rendered without an as-of | **1** | Defect (`AA-07`) |

---

## Confidence register for this sequence

**Confident.**
- Steps 1–7 are correct, cheap and should be done in that order. Nothing in them adds a system.
- Parquet is the right archive format and the decision is nearly free at the point it must be made anyway.
- No tenant-facing financial figure should ever come from an analytical store, at any tier.

**Guessing — argue with these first.**
- That internal analytics stays inside single-node DuckDB through Tier 4. Depends on event volume per tenant,
  which is unmeasured. `[UNKNOWN]`
- The 5–15× Parquet compression estimate. Directionally safe; **measure a real export before quoting it.**
- That ClickHouse rather than a bigger DuckDB machine is the right step-10 answer. Untested by design.

**Deliberately not decided here.**
- The `analytics_events` schema — a product question, owned by whoever owns growth metrics.
- Whether the archive manifest eventually becomes real Iceberg. Decide when the archive passes ~1 TB or a
  second engine needs to read it. Not before.
- Citus as the Tier-4 shard mechanism (`05` §A's open question). Its RLS/`SET LOCAL` behaviour across
  coordinator and workers is a real `[UNKNOWN]`; when the trigger fires, **write the isolation test before
  the migration**, exactly as `05` requires for PgBouncer.

---

## Sources

- [PostgreSQL — pg_stat_statements](https://www.postgresql.org/docs/current/pgstatstatements.html)
- [PostgreSQL — CREATE FUNCTION (parallel safety defaults)](https://www.postgresql.org/docs/current/sql-createfunction.html)
- [PostgreSQL — Parallel Query](https://www.postgresql.org/docs/current/parallel-query.html)
- [PostgreSQL — Autovacuum configuration](https://www.postgresql.org/docs/current/runtime-config-autovacuum.html)
- [PostgreSQL — Resource consumption (work_mem)](https://www.postgresql.org/docs/current/runtime-config-resource.html)
- [PostgreSQL — Query planning GUCs](https://www.postgresql.org/docs/current/runtime-config-query.html)
- [PostgreSQL — BRIN Indexes](https://www.postgresql.org/docs/current/brin.html)
- [DuckDB — Reading Parquet](https://duckdb.org/docs/current/data/parquet/overview.html)
- [Apache Parquet — File Format](https://parquet.apache.org/docs/file-format/)
- [Apache Iceberg — Table Spec](https://iceberg.apache.org/spec/)
- [ClickHouse — Incremental materialized views](https://clickhouse.com/docs/materialized-view/incremental-materialized-view)
