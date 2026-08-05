# Analytics research — OLAP, columnar storage, and when (if ever) QAYD needs more than PostgreSQL

**Phase 3 engineering research · analytics domain.** Version 1.0 · 2026-07-28 · Status: **research, not binding**

Systems studied: **Snowflake · ClickHouse · DuckDB · Apache Pinot · Apache Druid · Apache Iceberg ·
Delta Lake · Apache Arrow · Parquet**, plus PostgreSQL's own analytical machinery and the extensions
around it (Citus, TimescaleDB, pg_duckdb/pg_analytics).

---

## The answer, before the documents

> **QAYD does not need an analytics platform. It needs PostgreSQL configured competently, one rollup table
> it has already designed, and — eventually, for internal business analytics only — a directory of Parquet
> files it is going to write anyway.**

A pre-launch product with zero customers adopting a data warehouse would not be a premature optimisation; it
would be a **second system of record for money**, which `01_ENGINEERING_PRINCIPLES` P19 exists to prevent.
The correct number of analytical databases in QAYD's architecture today is **zero**, and the correct number
for tenant-facing financial statements is **zero forever**.

The reason is mechanical, not ideological. QAYD's trial balance is a **tenant-scoped, index-supported,
~60-row aggregate**. Columnar storage optimises the bytes-read term of a cost function whose dominant term a
`WHERE company_id = ?` has already eliminated. That is why the answer stays "PostgreSQL" for years, and it is
derived rather than asserted in `OVERVIEW.md` §1–2.

**But the research is not a dead end.** Three ideas from this field are worth taking — and QAYD already has
two of them without having noticed:

1. **Parquet as the format for the partition archive `05` already schedules.** Free at the point of a
   decision that must be made anyway; converts cold storage into a queryable dataset.
2. **QAYD's append-only ledger is structurally an Iceberg table** — immutable data plus a pointer, where time
   travel is nearly free because nothing is ever mutated. QAYD is *ahead*: its hash chain proves history was
   not altered, which Iceberg cannot do. The gap is that the archive does not yet inherit that proof.
3. **DuckDB over Parquet is a complete internal-analytics stack with zero infrastructure.**

---

## The documents

| # | Document | Lines | What it is |
|---|---|---|---|
| 1 | [`OVERVIEW.md`](OVERVIEW.md) | 638 | OLTP vs OLAP decomposed mechanically; why columnar wins, as four separable effects; profiles of all nine systems with good/bad/verdict; the comparison table; the freshness–speed trade |
| 2 | [`ARCHITECTURE.md`](ARCHITECTURE.md) | 615 | Deep technical, with ASCII diagrams — heap page vs Parquet file vs Arrow buffer; execution models; Iceberg's metadata tree and Delta's log; three pre-aggregation models; PostgreSQL's levers; **QAYD's own data architecture at each of `05`'s five tiers**; the correctness boundary |
| 3 | [`BEST_PRACTICES.md`](BEST_PRACTICES.md) | 451 | What to do. Sections 1–7 are PostgreSQL-native and deliberately the longest — measurement, index-only scans, BRIN, aggregation, query optimisation, partitioning, compression. Then archive, DuckDB, streaming and boundary practices |
| 4 | [`ANTI_PATTERNS.md`](ANTI_PATTERNS.md) | 428 | 14 rejections (**AA-01…AA-14**) with the *mechanism* of harm and the condition that would overturn each — premature warehousing, the "we need Kafka" reflex, unnecessary streaming, cache-invalidation hazards, and the cross-period rebuild bug |
| 5 | [`LESSONS_FOR_QAYD.md`](LESSONS_FOR_QAYD.md) | 407 | 9 lessons (**L-01…L-09**), each with why · benefits · tradeoffs · risks · scalability · performance · maintainability · complexity · effort · business impact · confidence · evidence |
| 6 | [`IMPLEMENTATION_RECOMMENDATIONS.md`](IMPLEMENTATION_RECOMMENDATIONS.md) | 464 | 10 sequenced steps, each with **an explicit trigger metric taken from `05`**, effort and confidence — plus the rows to add to `05`'s decision table |
| 7 | `README.md` | this file | Index, verdict, evidence policy |

**Total: ~3,100 lines.**

---

## Relationship to the knowledge base — extends, never repeats

`docs/architecture/knowledge/05_FUTURE_ARCHITECTURE.md` (2,452 lines) **already contains** the five-tier
scaling analysis, the `account_period_balances` rollup recommendation, `ledger_entries` partitioning, the
caching rules and the reporting-evolution ladder — including the rejection of a separate OLAP store. All of
that is taken as **settled** here and is not re-argued.

What this folder adds:

| Contribution | Where |
|---|---|
| The *mechanical* reason columnar wins, split into four separable effects — and which of them QAYD already gets from a covering index | `OVERVIEW.md` §2 |
| **Parquet as the archive format** for `05` §B's detached partitions — a free analytics capability | `LESSONS_FOR_QAYD.md` L-02 |
| **The Iceberg parallel**, and the manifest that makes the archive *provable* rather than merely readable | `OVERVIEW.md` §3.4 · L-03 |
| **BRIN, honestly assessed** — why it cannot prune by tenant, and the maintenance-scan workload where it is the cheapest win available | `BEST_PRACTICES.md` §3 · L-05 |
| **The cross-period continuity defect** in the planned rollup — every row passes its own CHECK while the chain between periods is broken | `ANTI_PATTERNS.md` AA-11 · L-06 |
| **`pg_stat_statements` as the precondition for every trigger metric in `05`** — those thresholds are unmeasurable today | `BEST_PRACTICES.md` §1 · L-01 |
| **The RLS objection to materialised views**, which is stronger than `05`'s performance objection | AA-05 · L-07 |
| **The parallel-safety guardrail** — QAYD already got this right; a future `CREATE OR REPLACE` could undo it system-wide, silently | `BEST_PRACTICES.md` §5.1 |
| Index-only scans depend on VACUUM, and an append-only table produces no dead tuples to trigger it | `BEST_PRACTICES.md` §2 |
| DuckDB-over-Parquet as the entire internal-analytics answer through Tier 4 | L-04 |
| The Kafka arithmetic — QAYD's event rate is 3–4 orders of magnitude below Kafka's reason to exist | AA-03 · L-08 |

---

## Where to start

| If you are asking… | Read |
|---|---|
| *Do we need a data warehouse?* | This page, then `OVERVIEW.md` §1 and §5 |
| *Why is columnar faster, really?* | `OVERVIEW.md` §2, then `ARCHITECTURE.md` §1–2 |
| *What should I do on Monday?* | `IMPLEMENTATION_RECOMMENDATIONS.md` steps 1–3 (effort 1 each) |
| *Someone proposed X and it feels wrong* | `ANTI_PATTERNS.md` — start at the symptom→rejection lookup |
| *How does our reporting evolve?* | `05_FUTURE_ARCHITECTURE.md` §C/§G first, then `ARCHITECTURE.md` §6 |
| *What are we archiving to, and how?* | `BEST_PRACTICES.md` §8 and `IMPLEMENTATION_RECOMMENDATIONS.md` step 8 |
| *Is Snowflake ever right for us?* | `IMPLEMENTATION_RECOMMENDATIONS.md` §11 — the trigger is organisational, not technical |

---

## The sequence, at a glance

| # | Step | Trigger | Effort |
|---|---|---|---|
| 1 | `pg_stat_statements` + `auto_explain` + `track_io_timing` | **Do now** — precondition for every `05` DB trigger | 1 |
| 2 | CI assertion that `app_*()` stay `STABLE` + `PARALLEL SAFE` | **Do now** — regression guard | 1 |
| 3 | `BRIN (posted_at)` for maintenance scans | **Do now** — costs a few MB | 1 |
| 4 | Per-report / per-tenant reporting telemetry | After step 1 | 2 |
| 5 | Nightly `max(rows per company, fiscal year)` alarm | After step 1 | 1 |
| 6 | Covering index + autovacuum insert-threshold + reporting role | `05`: p95 TB > 200 ms, or > 200 K rows/tenant-FY | 2+2 |
| 7 | Rollup hardening: cross-period cascade + `entry_date` invalidation | **With** `account_period_balances` | 3 |
| 8 | Parquet export + manifest carrying `ledger_head_hash` | `05`: `ledger_entries` > 300 GB | 5+3 |
| 9 | `analytics_events` from the outbox + DuckDB job | First unanswerable business question, or Tier 3 | 5+3 |
| 10 | Single-node ClickHouse for cross-shard analytics | Tier 4 sharding **and** DuckDB measured insufficient | 21 |

**26 points gets you through step 9, and adds no new system to operate.**

---

## The one-line test

> **Does this proposal create a second place where a monetary figure is produced, stored or trusted?**

If yes, it is rejected until `01` P19 is satisfied — which, for money, means a *rebuildable projection of the
ledger*, never a copy of it. Six of the fourteen anti-patterns are instances of that single test failing.

---

## Evidence policy

| Tag | Meaning |
|---|---|
| `[DOCS]` | Vendor/project documentation or published paper, URL cited |
| `[CODE]` | Read from QAYD's own source, path cited |
| `[COMMUNITY]` | Widely-reported practitioner experience, not vendor-documented |
| `[INFERENCE]` | Reasoned from documented mechanics; not directly stated by a source |
| `[UNKNOWN]` | Could not be determined — said so rather than guessed |

Most systems in this domain are open source or publish real papers, so the evidence base is strong.
`[UNKNOWN]` appears three times and in every case it is about **QAYD's own unmeasured future numbers**
(internal event volume, Parquet compression ratio on real data, Citus's RLS/GUC behaviour), never about the
systems studied. **No code from any studied system is used or reproduced — principles only.**

Every claim about a system is traceable to its documentation. Every claim about QAYD is traceable to a
migration file or to `05`. Where the two are combined into a conclusion, the conclusion is graded
`[INFERENCE]` and says what would falsify it.

---

## Standing prohibition

> **No tenant-facing financial figure is ever computed, stored or served from an analytical store — at any
> tier, at any headcount, for any latency.** The ledger has one system of record.

Everything else in this folder is an optimisation. That line is not.
