# ANTI_PATTERNS — analytics mistakes, and the mechanism by which each one hurts

**Phase 3 engineering research · analytics domain.** Version 1.0 · 2026-07-28 · Status: **research, not binding**

Modelled on `04_REJECTED_PATTERNS.md`: each entry names the pattern, the **mechanism of harm** (not just the
symptom), who it is right for, the condition that would overturn the rejection, and a symptom→rejection
lookup at the end.

Numbering: **AA-01 … AA-14**, to avoid colliding with `04`'s `R-01…R-34`.

---

## Symptom → rejection lookup

| Symptom you are hearing | Rejection |
|---|---|
| "We should get a data warehouse set up early so we're ready" | **AA-01** |
| "The trial balance is slow, let's put it in ClickHouse" | **AA-02** |
| "We need Kafka for the event pipeline" | **AA-03** |
| "Let's stream the ledger into the analytics store in real time" | **AA-04** |
| "Just use a materialised view for the balances" | **AA-05** |
| "Cache the balance for 60 seconds, nobody will notice" | **AA-06** |
| "The dashboard number is a bit stale but it's close enough" | **AA-07** |
| "We'll ETL the ledger into the warehouse and report from there" | **AA-08** |
| "There's a DuckDB extension for Postgres, we can just use that" | **AA-09** |
| "BRIN is perfect for append-only tables, add it and reporting gets fast" | **AA-10** |
| "We rebuilt the balances for the affected period" | **AA-11** ⚠️ |
| "Let's pre-aggregate every dimension combination while we're at it" | **AA-12** |
| "Partition by company_id, it's the tenant key" | **AA-13** |
| "The archive is cheap, let's point the product at it" | **AA-14** |

---

## AA-01 — Premature warehousing

**The pattern.** Standing up Snowflake / BigQuery / Redshift / a lakehouse "so we're ready when we need it,"
before there are customers, before there is a question anyone is asking, and before anyone has measured a
slow query.

**Mechanism of harm.** Four, compounding:

1. **It creates a second system of record for money.** The moment a monetary figure exists in two places
   maintained by two mechanisms, reconciliation between them becomes a permanent engineering obligation.
   `01_ENGINEERING_PRINCIPLES` P19 exists to prevent exactly this. The warehouse copy sits **outside RLS,
   outside the append-only trigger, outside PITR alignment** — `05` §G states this and it is the decisive
   objection.
2. **It fixes the schema before the questions are known.** A warehouse built pre-launch models a business
   nobody has run yet. Every later question requires either a backfill or an apology.
3. **It is a permanent tax.** Pipelines break silently; a broken pipeline produces *plausible* numbers, which
   is worse than an error.
4. **It costs money continuously to answer questions nobody asked.** Consumption billing punishes scheduled
   jobs whose output nobody reads `[COMMUNITY]`, and pre-launch that is all of them.

**Who it is right for.** An organisation with a data team, non-engineers who need self-service SQL, and a
dataset too large for one machine. QAYD has none of the three.

**The overturning condition.** All of: (a) the internal analytics dataset exceeds single-node DuckDB comfort;
(b) more than ~10 non-engineers query it daily; (c) the company can staff someone to own its correctness.
These are **organisational** triggers. Notice that none of them is "the trial balance got slow."

**Instead:** `IMPLEMENTATION_RECOMMENDATIONS.md` steps 1–6. Postgres, then Parquet, then DuckDB.

---

## AA-02 — Solving an OLTP problem with an OLAP system

**The pattern.** A tenant's trial balance is slow, so the proposal is a columnar engine.

**Mechanism of harm.** It optimises a term that is not the bottleneck, and pays for it with a system of
record. QAYD's trial balance is a **tenant-scoped, index-supported, ~60-row aggregate over 25k–400k rows**
(`05` §C's own table). Columnar storage attacks bytes-read on scans with weak predicates; QAYD's predicate is
`company_id`, the most selective column in the database. The measured fix is one of: a covering index
(effort 2), the rollup (effort 8), or a replica for buffer-pool relief (effort 5) — all inside Postgres, all
transactional, all already planned in `05` §C/§G.

Adopting an OLAP engine here also *adds* latency for the actual failure mode: a whale tenant's report is slow
because of buffer-pool contention and row count, and an ETL hop makes the number stale as well as no faster
to produce.

**Who it is right for.** Cross-tenant scans with no selective predicate. That is QAYD's *internal* analytics,
and only that.

**The overturning condition.** A report whose predicate genuinely cannot eliminate most rows, that is
tenant-facing, and that a rollup cannot serve. No such report has been identified. If one appears, the first
question is why an accounting report needs to scan a tenant's entire history rather than reading period
balances.

---

## AA-03 — The "we need Kafka" reflex

**The pattern.** Reaching for Kafka (or Kinesis, or Pulsar) as the default backbone for events, because that
is what event-driven architectures look like in conference talks.

**Mechanism of harm.**

1. **It duplicates a mechanism QAYD already has.** The outbox provides durability tied to the same
   transaction as the fact, ordering by `id`, at-least-once delivery, and idempotent consumption
   (`05` §D). Kafka provides the same properties in a *different durability domain* — which means a
   two-phase problem appears: the DB commit succeeded and the Kafka publish did not, or vice versa. The
   outbox pattern exists **specifically to avoid** that, so adding Kafka behind an outbox adds a hop, and
   adding Kafka *instead of* an outbox reintroduces the bug the outbox solved.
2. **The volume argument does not survive arithmetic.** From `05`'s capacity table:

   | Tier | Ledger rows/month | Avg events/s | Peak events/s |
   |---|---|---|---|
   | T1 (100) | 200 K | ~0.3 | ~3 |
   | T2 (1,000) | 3.36 M | ~5 | ~53 |
   | T3 (10,000) | 33.6 M | ~13 (business hours) | ~100 |
   | T4 (100,000) | 336 M | ~130 | ~760 |

   Kafka's design point begins in the 10⁵–10⁶ events/s range. **At Tier 3, QAYD is three to four orders of
   magnitude below it.** PostgreSQL handles 100 inserts/s without noticing.
3. **An accounting system's event volume is bounded by human business activity, not by machine telemetry.**
   It cannot spike the way clickstream or IoT can, because a human has to author a journal entry. This is a
   structural ceiling, not a current measurement.
4. **Operational surface.** Brokers, KRaft/ZooKeeper, consumer groups, rebalance storms, partition-count
   decisions that are hard to change, schema registry, and a second on-call domain — for a system whose
   ledger is the crown jewel and whose team is small.

**Who it is right for.** Many independent consumer systems, cross-datacentre replication, replay of a
long-retained log by consumers that must not touch the primary, and volumes where a database table genuinely
cannot keep up.

**The overturning condition.** A **third** independent consumer system, at Tier 4+, that must not share the
primary's connection budget, *and* a measured outbox-drain depth persistently above `05`'s 10,000 threshold
after the drain has already been sharded by `company_id` bucket. Until both, Kafka is strictly negative.

---

## AA-04 — Unnecessary streaming into the analytical tier

**The pattern.** Continuously streaming ledger changes into an analytical store so dashboards are "real
time."

**Mechanism of harm.**

- **It buys freshness nobody needs at the cost of correctness nobody can verify.** Internal analytics
  questions — cohort retention, feature adoption, cost-to-serve — are answered on daily or weekly cadence.
  Sub-second freshness on a cohort chart has no consumer.
- **It makes the analytical store a continuous-availability dependency.** A batch export that fails is
  retried tomorrow. A stream that fails is an incident, at 3 a.m., about a dashboard.
- **It creates a real-time path along which someone will eventually route a financial number**, because the
  path exists and it is fast. This is the failure that matters. The architectural defence against
  authoritative-numbers-from-the-advisory-tier is *not having a fresh advisory tier*.
- **CDC on the ledger adds a replication-slot risk to the primary.** An abandoned logical slot pins WAL
  and can fill the primary's disk — a documented operational hazard `[COMMUNITY]` and a spectacularly bad
  way to lose an accounting database.

**Who it is right for.** Fraud detection, live operational monitoring, anything with a sub-minute decision
loop.

**The overturning condition.** A product feature with a genuine sub-minute analytical SLA. Note that a
*tenant-facing* such feature would be served from the primary, not from the analytical store, so this
condition is narrower than it sounds.

**Instead:** scheduled batch export from the outbox, at the cadence the questions actually have.

---

## AA-05 — Materialised views for tenant-scoped balances

**The pattern.** `CREATE MATERIALIZED VIEW trial_balance AS SELECT company_id, account_id, SUM(...)`.

**Mechanism of harm.** `05` §C already rejects it on performance grounds — `REFRESH` is a full recompute;
`CONCURRENTLY` requires a `UNIQUE` index using only column names with no `WHERE` clause, requires the view to
be populated, and still rebuilds everything `[DOCS]`
https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html

**The stronger, correctness-based mechanism** (the addition this document makes):

> The view is populated by whoever runs the `REFRESH`, in **that session's** GUC context.
> `app_current_company_id()` reads `app.current_company_id` `[CODE]`. A maintenance job has no tenant set, so
> the refresh either produces an **empty** view (QAYD's policies fail closed on NULL) or, under an
> RLS-bypassing role, a **cross-tenant** one. Then RLS cannot protect the result, because row-level security
> is a *table* feature and the view's rows were computed outside any tenant boundary. `[INFERENCE]` — verify
> against the deployed version, but design as if true.

A materialised view of money in a multi-tenant RLS system is therefore not a slow correct thing. It is a fast
wrong thing.

**Who it is right for.** Tenant-agnostic data: reference tables, platform-wide operational metrics, currency
lists.

**The overturning condition.** None for `company_id`-scoped monetary data.

**Instead:** `account_period_balances` — an ordinary table, with ordinary RLS policies, maintained by an
in-transaction trigger (`05` §C).

---

## AA-06 — Caching an authoritative number

**The pattern.** A short TTL on a balance, "because it barely changes."

**Mechanism of harm.** `05` §C's rule already states it: *a number a user could mistake for authoritative
must not be served from a cache*, because a user initiates a payment against a bank balance, runs a payment
batch against an AR aging, and files a return against a tax liability. Not repeated here.

**The addition:** the harm is **asymmetric and delayed**. A stale balance does not fail; it succeeds, with a
wrong number, and the failure surfaces at reconciliation, weeks later, with no trace of which render was
stale. A cache miss is visible; a cache hit that was wrong is not. That asymmetry is why the rule has to be
absolute rather than risk-weighted.

**Instead:** the projection ladder (`05` §C), and for genuinely cacheable things, invalidation driven by the
outbox with the realtime channel telling the client to **re-fetch**, never carrying the value.

---

## AA-07 — Displaying a stale number without its as-of

**The pattern.** A dashboard tile rendering a figure that came from a replica, a cache or the analytical
tier, styled identically to an authoritative figure.

**Mechanism of harm.** The provenance is invisible at exactly the moment it matters. `05` §C: *"a cached
number must carry its as-of and its provenance, or it must not be shown. A stale balance with a visible 'as
of 14:32' is a product decision. A stale balance rendered as current is a defect."*

**The addition — a mechanism, not a policy:** make the as-of a **required prop** on the advisory-number
component so omitting it fails the build. A rule enforced by a code-review checklist decays; a rule enforced
by a type does not. This is the same reasoning `03_DESIGN_PATTERNS` applies elsewhere.

---

## AA-08 — ETL that reconstructs money

**The pattern.** A pipeline that reads `journal_lines` (or worse, source documents) and *recomputes* balances
in the analytical store.

**Mechanism of harm.** It creates a **second implementation of the posting rules** — sign conventions, base
currency conversion, period assignment, reversal handling. Two implementations of the same accounting logic
diverge; they always diverge; and the divergence is discovered by a customer comparing two screens.

`01` P19 and `03_DESIGN_PATTERNS` P-01 (one authorized write path into the ledger) are the standing defence.
An ETL that recomputes a balance is a second posting engine wearing a Python hat.

**Who it is right for.** Nobody, in a system whose value proposition is that the books are right.

**The overturning condition.** None.

**Instead:** export the **already-computed** facts. `signed_base_amount` exists precisely so that no consumer
ever has to re-derive a sign (`05` §C). The archive carries ledger rows and rollup rows as they were, and any
analytical aggregate is a `SUM` over facts, never a re-derivation of them.

---

## AA-09 — Embedding an analytical engine inside the accounting database

**The pattern.** Installing `pg_duckdb`, `pg_analytics`, or a columnar-storage extension into the production
PostgreSQL instance to "get columnar without another system."

**Mechanism of harm.** `[INFERENCE]` — reasoned from the architecture of in-process extensions; verify
specifics before adopting, and treat this rejection as the default:

1. **It introduces an alternative scan path over tenant tables.** QAYD's entire tenancy model is *"the
   storage engine enforces the boundary"* (`03` P-09, `05` §A). Any component that can read heap or column
   data by a route other than the normal executor is a component that must independently honour row-level
   security. That is a large, subtle, security-critical surface to audit — and the failure mode is a silent
   cross-tenant read, the highest-severity class in this system.
2. **Blast radius.** An embedded analytical engine allocates and executes inside a backend process. An
   analytical query that exhausts memory does not fail a job; it takes down a backend of the database that
   holds the ledger.
3. **Maturity mismatch.** These extensions are young relative to the thing they would be installed into.
   `05` §L's whole framing is that the ledger's survival properties are the product.
4. **The benefit is available for free out of process.** DuckDB reading exported Parquet gives the same
   engine, the same speed, and none of the above.

**Who it is right for.** A single-tenant analytics-heavy application where the database is not a book of
record.

**The overturning condition.** The extension demonstrably honours RLS on every scan path, runs with a hard
resource limit, and has a track record in financial systems — **and** the out-of-process route has been
measured as insufficient. Both halves required.

---

## AA-10 — BRIN as a reporting fix

**The pattern.** "The ledger is append-only and time-ordered, so BRIN is the natural index — add it and
reporting gets fast."

**Mechanism of harm.** BRIN prunes by **physical block range**, using per-range min/max
`[DOCS]` https://www.postgresql.org/docs/current/brin.html. QAYD's tenant-facing queries filter `company_id`
first, and **tenants interleave in insertion order**, so every block range's `company_id` min/max spans
essentially the whole id space and **nothing is pruned**. The index is added, the query does not improve, and
— worse — the team concludes that "we tried indexing and it didn't help," which sends them toward AA-01 or
AA-02.

The secondary trap: BRIN on `entry_date` looks correlated and mostly is, but **back-dating within an open
period is a routine accounting operation**, so the ranges widen exactly during period-end catch-up posting,
which is when reports are run hardest.

**Who it is right for — and it *is* right for something.** Cross-tenant scans over a time window: hash-chain
verification, the ledger-projection drift check, archival range selection, retention sweeps, backfills. Those
are QAYD's genuinely unindexed queries, and BRIN on `posted_at` is a few MB against a ~9 GB btree.

**The correct statement:** *BRIN is a maintenance-scan optimisation, not a reporting optimisation.* Adopt it
(`BEST_PRACTICES.md` §3), and never propose it as the answer to a slow trial balance.

---

## AA-11 — Rebuilding one period's balances without cascading forward ⚠️

**The most dangerous entry in this document**, because the design that permits it is already planned and the
existing safeguard does not catch it.

**The pattern.** A back-dated posting (or a drift repair) lands in period 3, so the rebuilder recomputes
`account_period_balances` for period 3 and stops.

**Mechanism of harm.** `account_period_balances` carries `opening, debit, credit, closing` with
`CHECK (closing = opening + debit - credit)` (`05` §C). That CHECK is **per row**. It says nothing about the
relationship *between* periods.

But accounting's continuity identity is exactly that relationship:

```
   period 3   opening=1000  debit=500  credit=200  closing=1300   ✔ CHECK passes
   period 4   opening=1000  debit=300  credit=100  closing=1200   ✔ CHECK passes
                     ^^^^
                     STALE — should be 1300 after the period-3 rebuild

   Every row passes its own CHECK. The chain is broken. The balance sheet
   for period 4 onward is wrong, silently, and the database is content.
```

The failure is invisible to the constraint, invisible to the drift check if that check compares each period's
rollup to a `SUM` of *that period's* ledger rows only, and invisible to the user because every screen is
internally consistent.

**Who it is right for.** Nobody. This is a defect class, not a tradeoff.

**Instead — three mechanisms, all cheap:**

1. **Cascade by construction.** A rebuild of period N rebuilds N…end-of-fiscal-year for the affected
   `(company_id, account_id)` pairs. Never a single period.
2. **Assert continuity in the drift checker.** For every `(company, account)` and consecutive periods:
   `period(N+1).opening = period(N).closing`. This is a cheap window query and it catches every instance of
   this bug, including ones introduced by future code.
3. **Derive the invalidation key from `entry_date`, not from `now()`.** A posting made today with
   `entry_date` in March invalidates March **and everything after it**. An invalidation keyed on the current
   period is wrong for every back-dated entry — which, in accounting, is a large fraction of them at
   period-end.

**Effort 3. Confidence high.** This should be recorded against `05` §C as a required companion to the rollup,
not as an optional hardening.

---

## AA-12 — Materialising the full dimensional cube

**The pattern.** When analytic dimensions land (`AD-11` — dimensions as rows), pre-aggregating balances for
every combination of account × cost centre × project × department × branch × period.

**Mechanism of harm.** Combinatorial explosion: n dimensions yield 2ⁿ rollup tables, each needing its own
trigger, its own rebuilder and its own drift check. Six dimensions is 64 aggregates and 64 ways to be
subtly wrong. Write amplification on the hottest path in the system multiplies accordingly, and `05` §C
already flags the single trigger as a potential Tier-4 write bottleneck.

**Who it is right for.** Systems where the query set is unknown and storage is free — classic warehouse
territory, not an OLTP write path.

**Instead — borrow Pinot's budget.** `[DOCS]` https://docs.pinot.apache.org/basics/indexing/star-tree-index
Declare the two or three dimension combinations that real reports use, materialise those, and let everything
else be a live query with a `maxLeafRecords`-style bound on how much it may scan. *Declare the combinations,
bound the leaf.*

---

## AA-13 — Partitioning by tenant

**The pattern.** `PARTITION BY LIST (company_id)` or `HASH (company_id)`, because the tenant key is the
obvious key.

**Mechanism of harm.** Fully argued in `05` §B — 100,000 partitions degrades planning, bloats `pg_class`,
gives no pruning for date-scoped reports (which is every financial report), and makes closed-year archival
impossible. Not repeated.

**The addition that belongs here:** the same mistake reappears **in the archive**, where it looks harmless.
`s3://qayd-archive/ledger/company_id=4471/…` produces 100,000 prefixes, defeats DuckDB's directory pruning,
makes listing expensive, and turns every cross-tenant analysis into a 100,000-way glob. Bucket instead:
`company_bucket = company_id % 64`. Same lesson, second venue.

---

## AA-14 — Pointing a tenant-facing feature at the archive

**The pattern.** The archive is queryable and cheap, so a product feature ("view any historical year") is
built on top of it.

**Mechanism of harm.**

1. **The archive is outside RLS.** Object storage has bucket policies, not row policies. A tenant-facing
   query over Parquet must implement the tenant boundary in application code — abandoning the
   storage-engine-enforced guarantee that `03` P-09 exists to provide, for the one dataset where an error is
   least likely to be noticed.
2. **It makes an availability promise about object storage** that nobody agreed to.
3. **It creates a second read path for money** with different latency, different consistency and different
   failure modes, and it will be compared against the primary by a customer.
4. **It quietly converts an archive into a system of record**, which means it can no longer be deleted,
   re-exported or reformatted — the properties that made it cheap.

**Who it is right for.** Internal analysis and auditor extracts, produced by a human or a job, delivered as a
file.

**Instead:** if a tenant needs a closed year back, **re-attach the partition** (`05` §B's archival flow runs
in both directions) or generate the extract as an asynchronous job that produces a document. Both preserve
the boundary.

---

## The one-line test that catches most of these

> **Does this proposal create a second place where a monetary figure is produced, stored or trusted?**
> If yes, it is rejected until `01` P19 is satisfied — which, for money, means it must be a *rebuildable
> projection of the ledger*, not a copy of it.

AA-01, AA-02, AA-05, AA-08, AA-09 and AA-14 are all instances of that single test failing.

---

## Sources

- [PostgreSQL — REFRESH MATERIALIZED VIEW](https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html)
- [PostgreSQL — BRIN Indexes](https://www.postgresql.org/docs/current/brin.html)
- [PostgreSQL — Row Security Policies](https://www.postgresql.org/docs/current/ddl-rowsecurity.html)
- [Apache Pinot — Star-tree index](https://docs.pinot.apache.org/basics/indexing/star-tree-index)
- [Apache Druid — Rollup](https://druid.apache.org/docs/latest/ingestion/rollup/)
- [Questioning the Lambda Architecture — Jay Kreps](https://www.oreilly.com/radar/questioning-the-lambda-architecture/)
