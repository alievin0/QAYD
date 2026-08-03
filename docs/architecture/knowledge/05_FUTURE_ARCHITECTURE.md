# 05 — Future Architecture

**How QAYD's architecture evolves with scale.** Version 1.0 · 2026-07-28 · Status: **planning reference, not binding**

Companion to `01_ENGINEERING_PRINCIPLES.md` (which is binding). Where this document and `01` disagree, `01` wins:
scaling is an optimisation, correctness is not.

---

## What this document is

QAYD today has **zero production customers**. Everything below is a plan for problems it does not yet have.
That makes the document dangerous in a specific way: an architecture written for imagined load is an
architecture optimised for nothing, and every premature mechanism is a permanent tax on the team that
maintains it.

So this document is organised the opposite way from most scaling plans. It does not say "build for a
million users." It says, for each tier: *here is the arithmetic, here is the specific thing that breaks
first, here is the smallest change that fixes it, here is what must deliberately NOT change, and here is
the observable metric that tells you the tier has arrived.*

### The rule this document is written under

> **Never plan an architecture because Odoo, SAP, or anyone else does it.**
> Every decision below is justified by QAYD's own estimated rows, bytes, queries per second, and dollars.
> Where a number is a guess, it is marked as a guess.

Odoo appears in exactly one role here: as a **worked example of the cost of not doing this**. Odoo stores
no aggregate balances anywhere — every trial balance is a full scan of the largest table in the database
(`_compute_current_balance` has no date bound at all), and its ledger cannot be partitioned because it
doubles as the mutable invoice-line table. That is not a criticism of Odoo's engineers; it is the
predictable end state of twenty years of deferring the aggregate question. QAYD can answer it cheaply
**only because it already chose an append-only ledger**, and the whole of §"Aggregates and caching" below
is the dividend of that one decision.

### The single most important framing in this document

**QAYD's scaling triggers are per-tenant, not aggregate.**

A trial balance is scoped to one company. A reconciliation is scoped to one company. An AI drafting call
loads one company's chart of accounts. Almost nothing QAYD does reads across tenants. This has three
consequences that recur in every section:

1. **Total row count is the wrong alarm.** 400 million ledger rows spread over 10,000 tenants is 40,000
   rows per tenant per year — a trivial index scan. 400 million rows in *one* tenant is a several-second
   query. The metric that matters is `max(rows per (company_id, fiscal_year_id))`, not `count(*)`.
2. **Vector and full-text search never face the "billion-vector problem."** Retrieval is always filtered
   to one company first. That is why pgvector and Postgres FTS stay viable far longer here than the
   general advice suggests.
3. **The first real scaling crisis will be a single whale customer, not customer count.** Plan for it.

### Assumptions that hold across all five tiers

| Assumption | Value | Confidence |
|---|---|---|
| Business hours (the only hours that matter) | 22 working days × 8 h = **633,600 s/month** | High — GCC business rhythm |
| Timezone spread | **None.** Kuwait, Saudi, UAE, Qatar, Bahrain, Oman are UTC+3/+4. All tenants peak in the same 8 hours. | High — this is a real disadvantage vs. a global SaaS |
| Revenue per customer (ARPU) | **$100/month** (≈ 30 KWD) | **Guess.** Every cost-as-%-of-revenue figure scales inversely with this. |
| Money column | `NUMERIC(19,4)`, ~10 B stored per value | High |
| `ledger_entries` row, all-in (heap + PK + UNIQUE + 4 indexes) | **~410 B** — derived below | Medium-high |
| `journal_lines` row, all-in | ~500 B (wider; grows with tax/dimension work) | Medium |
| `journal_entries` row, all-in | ~300 B | Medium |
| `audit_logs` rows per posted entry | 2, at ~600 B each | **Guess** — depends on how chatty the audit becomes |

**Derivation of the ~410 B ledger row** (from `2026_07_28_000007_create_ledger_entries_table.php`):

```
heap tuple:
  header + null bitmap, 8-byte aligned                       32 B
  7 × BIGINT (id, company, entry, line, account, fy, period) 56 B
  entry_date DATE 4 + posted_at TIMESTAMPTZ 8 + created_at 8 20 B
  entry_type (enum, 4) + currency_code CHAR(3) (varlena, 4)   8 B
  5 × NUMERIC(19,4) @ ~10 B                                  50 B
  source_type ~11 + source_id 8 + description ~41 + ref ~13  73 B
  alignment padding + item pointer                          ~11 B
                                                        ---------
                                                            250 B
indexes (btree entry = 8 B IndexTuple + key, +4 B line pointer, 90% fill):
  PK (id)                                                    ~22 B
  uq_ledger_entries_journal_line                             ~22 B
  idx_ledger_account_date (company, account, entry_date)     ~40 B
  idx_ledger_entry (journal_entry_id)                        ~22 B
  idx_ledger_year (fiscal_year_id)                           ~22 B
  idx_ledger_source (source_type, source_id)                 ~34 B
                                                        ---------
                                                            162 B
TOTAL                                                       ~412 B
```

Rounded to **410 B**. Rolling up the four accounting-core tables gives a convenient composite:

```
per ledger row-equivalent, per year:
  ledger_entries      410 B
  journal_lines       500 B
  journal_entries     300 B / 4.2 lines  =  71 B
  audit_logs      2 × 600 B / 4.2 lines  = 286 B
                                      -----------
                                        ~1,267 B  ≈ 1.27 KB
```

So: **one posted journal line costs QAYD about 1.27 KB of primary database, forever.** Add ~30% for
reference tables, bloat, and free-space-map slack and the planning number is **~1.65 KB per posted line**.
That single constant drives every DB-size figure below.

---

# Tier 1 — 100 customers

**Design target: today through roughly the first year of selling.**

## 1. Stated assumptions

| Input | Value | Why |
|---|---|---|
| Customers | 100 | First-year target |
| Users per customer | 3 | Owner + accountant + one clerk. GCC SMB norm. |
| Journal entries / customer / month | 500 | ~200 sales invoices, ~100 purchases, ~80 payments, ~60 bank lines, ~60 payroll/adjustments |
| Lines per entry | 4.0 | Debit AR / credit revenue / credit VAT-output = 3; purchases similar; payments 2. 4.0 is slightly generous. |
| Documents / customer / month | 300, avg 250 KB | Invoice PDFs, scanned receipts, bank statements |
| AI calls / customer / month | 800 | 300 extraction, 400 drafting/matching, 40 escalation, 60 chat |

**These are the arguable numbers.** If a real Tier-1 customer posts 2,000 entries a month rather than 500,
every row figure below is 4× — but the *conclusions* barely change, because Tier 1's bottleneck is not the
database.

## 2. Derived load

**Rows.**
```
lines/month   = 100 customers × 500 entries × 4.0 lines  =    200,000
lines/year    = 200,000 × 12                             =  2,400,000
ledger rows/year (1:1 with posted lines)                 =  2,400,000
journal_entries/year = 100 × 500 × 12                    =    600,000
audit_logs/year      = 600,000 × 2                       =  1,200,000
```

**Database size.**
```
2,400,000 posted lines × 1.65 KB = 3.96 GB
→ ~4 GB after year one, ~12 GB after year three
```

**Peak write QPS.**
```
mean lines/s = 200,000 / 633,600 s        = 0.32 lines/s
peak factor  = 10×  (month-end close, morning batch import, single timezone)
peak         = 3.2 lines/s ÷ 4.0          = 0.8 posted entries/s

row-writes per posted entry:
  1 journal_entries + 4.2 journal_lines + 4.2 ledger_entries
  + 1 audit_logs + 1 outbox_events + 4.2 account_period_balances upserts
                                            ≈ 16 row-writes

peak row-writes = 0.8 × 16                = ~13 row-writes/s
```

**Peak read QPS.**
```
users              = 100 × 3              = 300
concurrent at peak = 20%                  = 60
actions/s per user = 0.2 (one action / 5s)= 12 requests/s
queries/request    = 8                    = ~96 read queries/s
```

**AI tokens/month.**
```
per customer/month:
  300 extraction × 6,600 tok  = 1,980,000
  400 drafting   × 8,300 tok  = 3,320,000
   40 escalation × 16,000 tok =   640,000
   60 chat       × 9,300 tok  =   558,000
                                ----------
                                6,498,000 ≈ 6.5M tokens/customer/month

100 customers → ~650M tokens/month
```

## 3. What breaks first

**Nothing in the database.** A 4 GB Postgres serving 13 writes/s and 96 reads/s is idle. A Raspberry Pi
would do it.

The first thing that breaks at Tier 1 is **operational maturity**, and specifically these three, in order:

1. **A missing backup restore test.** At 100 customers you have 100 businesses' books. A backup you have
   never restored is a hypothesis, not a backup. This is the single highest-consequence gap at this tier
   and it costs one afternoon to close.
2. **The `SET LOCAL` / connection-lifecycle discipline.** *Corrected 2026-07-28 after verification —
   the earlier wording here overstated this as a live leak. It is not.* The verified facts:
   - The **HTTP path is correct today**: `ResolveTenantCompany` uses `set_config(name, value, true)` —
     the transaction-local form — inside a transaction it opens itself (`ResolveTenantCompany.php:85-90`).
     That is already PgBouncer-transaction-pooling safe by construction.
   - There are **no queue jobs at all** right now (no `app/Jobs` directory), and the only console command
     is `GenerateJwtKeys`, which needs no tenant context. **Nothing leaks today.**
   - The risk is **prospective and structural**: there is no tenant-context helper for *non-HTTP* entry
     points, so the first engineer to write a queue job has no correct pattern to copy, and the obvious
     naive implementation — a session-scoped `SET` on a long-lived worker connection — would leak tenant
     context between jobs, failing *open* with plausible-looking data rather than failing closed.
   - The action is therefore **preventive, and must land before the first background job**, not after:
     a `RunsInTenantContext` helper that opens a transaction and issues the same `set_config(..., true)`
     calls, plus a test asserting two sequential jobs for different companies cannot see each other's
     rows. Cheap now; a silent breach later. See §"Horizontal scaling and connection pooling".
3. **The CI catalog-introspection RLS check.** Every table added between now and Tier 2 is a table that
   could ship cross-tenant-readable. One `pg_class`/`pg_policy` query that fails the build converts the
   `BelongsToCompany` convention into a mechanism.

## 4. What changes

**Build:**
- `account_period_balances` rollup — **not because it is needed for performance** (it isn't, at 40k rows
  per tenant per year), but because it must be in place *before* the ledger is large enough that
  backfilling it is a migration event. Building it now costs 8 points; building it at Tier 3 costs 8
  points plus a several-hour backfill on the largest table under load.
- Transactional outbox for domain events.
- Weekly automated restore-and-verify into a scratch environment.
- The CI RLS catalog check.

**What deliberately does NOT change — and this list is longer than the build list:**

| Not doing | Why not |
|---|---|
| Partitioning | 2.4M rows. Partitioning a table this size adds planning overhead and buys nothing. |
| Read replicas | 96 read q/s. A replica adds a second failure mode and a lag question for zero benefit. |
| PgBouncer | ~10 connections needed. Adding transaction pooling before the GUC discipline is proven is how a cross-tenant leak ships. |
| Sharding, multi-region, OLAP, dedicated search, dedicated vector store | All of these are Tier 3+ answers to Tier 3+ problems. |
| Microservices | The modular monolith is the correct shape until the *team* — not the load — makes it wrong. |
| Caching layer for reports | A cached financial number a user might act on is a correctness problem, not a performance win. See §"Aggregates and caching". |

## 5. Migration path

From nothing to Tier 1: greenfield. The only migration discipline that matters here is the one that makes
every later migration possible — **every projection ships with a rebuilder** (`RebuildLedgerAction`,
`RebuildPeriodBalancesAction`) and a drift check that runs in CI. If those exist at Tier 1, every later
tier's migration is "build the new thing, rebuild it from source, compare, cut over." If they don't, every
later migration is an outage.

## 6. Cost envelope

| Line | Monthly |
|---|---|
| Managed Postgres, 4 vCPU / 16 GB / 200 GB | $300 |
| 2 app containers (API + worker) | $120 |
| Redis (single instance, cache + queue) | $50 |
| Object storage (90 GB/yr) + egress | $30 |
| Monitoring, logs, CI, misc | $100 |
| **Non-AI subtotal** | **~$600** |
| **AI** (100 × ~$14 — see §"AI workload") | **~$1,400** |
| **Total** | **~$2,000/month** |

On $10,000 MRR: **20% of revenue, of which AI is 70%.** The AI line is already the dominant infrastructure
cost at 100 customers, and it stays dominant at every subsequent tier. That is the thesis of this document's
AI section.

## 7. Trigger metrics — you are approaching Tier 2 when

| Metric | Threshold | Start |
|---|---|---|
| Total customers | > 400 | Reading Tier 2 seriously |
| Postgres `numbackends` / `max_connections` | > 40% | The `SET LOCAL` audit + `PgBouncerSafetyTest` (**before** PgBouncer) |
| p95 trial-balance latency | > 200 ms | Add the covering index (see Tier 2) |
| `max(ledger rows per company_id, fiscal_year_id)` | > 200,000 | Same |
| DB size | > 30 GB | Plan a bigger instance; review index bloat |
| Peak row-writes/s | > 200 | Review the posting hot path; confirm H3 (fiscal-year lock removal) landed |

---

# Tier 2 — 1,000 customers

## 1. Stated assumptions

| Input | Value | Change from Tier 1 | Why |
|---|---|---|---|
| Customers | 1,000 | ×10 | |
| Users per customer | 4 | +1 | Broader mix; some 10–30 person firms |
| Journal entries / customer / month | 800 | +60% | Customer mix shifts upward as you move past the smallest businesses |
| Lines per entry | 4.2 | +5% | Tax lines and analytic dimensions land in this period |
| Documents / customer / month | 300 | — | |
| AI calls / customer / month | 800 | — | Per-customer AI usage assumed flat. **This is the assumption most likely to be wrong** — see §"AI workload". |

## 2. Derived load

```
lines/month = 1,000 × 800 × 4.2           =  3,360,000
lines/year                                = 40,320,000
DB (40.3M × 1.65 KB)                      = ~66 GB/year → ~130 GB by end of year 2

mean lines/s = 3,360,000 / 633,600        = 5.3 lines/s
peak ×10                                  = 53 lines/s = 12.6 entries/s
peak row-writes = 12.6 × 16               = ~202 row-writes/s

users = 4,000; concurrent 20% = 800; × 0.2 rps = 160 req/s
peak read queries = 160 × 8               = ~1,280 read q/s

AI = 1,000 × 6.5M tokens                  = ~6.5B tokens/month
```

## 3. What breaks first

**Connection exhaustion — and the fix for it is a correctness hazard.**

At 1,280 read q/s the API tier needs ~6 containers. PHP-FPM at `pm.max_children = 32` gives
6 × 32 = **192 potential concurrent connections**, plus queue workers, plus the scheduler, plus a human
with `psql`. A Postgres tuned with 64 GB RAM realistically holds `max_connections` in the 200–400 range
(each backend costs ~5–10 MB of process memory before it does any work). You are at the wall.

The obvious answer is PgBouncer in transaction mode. **PgBouncer in transaction mode is where QAYD's
tenancy boundary breaks silently.** RLS reads `app.current_company_id` from a Postgres GUC; GUCs are
per-*connection*; transaction pooling hands a backend connection to a different request after every
`COMMIT`. A session-scoped `SET` therefore leaks one tenant's company id into another tenant's query, and
RLS then filters *correctly* — to the wrong company. It fails **open**, silently, returning plausible data.

Two independent Odoo-study workstreams reached this finding from different subsystems. It is treated in
this document as a data-leak risk, not a performance topic. Full treatment in §"Horizontal scaling and
connection pooling".

**Second (and only second): trial-balance latency for the largest tenants.** At 800 entries/month a median
tenant accumulates 800 × 4.2 × 12 = 40,320 ledger rows per fiscal year — a ~20 ms index scan. But the
largest tenant at this tier might post 8,000 entries/month = 403,000 rows/year, which is a
several-hundred-millisecond scan and climbing. This is the first appearance of the whale-tenant pattern.

## 4. What changes

**Build, in this order:**

1. **The `SET LOCAL` audit + `PgBouncerSafetyTest` against a real pooler.** Before PgBouncer, not after.
2. **PgBouncer (or Supavisor, given the Supabase-managed Postgres)** in transaction mode.
3. **Covering index for trial balance** — the cheap step before the rollup:
   `CREATE INDEX ... ON ledger_entries (company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)`
   turns the TB into an index-only scan. Effort 2. Do this before reaching for anything bigger.
4. **One streaming read replica**, used only for reporting and analytics — with the read-your-writes rule
   in §"Reporting" enforced.
5. **Split Redis into two instances**: cache (`allkeys-lru`) and queue (`noeviction`). Sharing one is a
   classic production incident — an eviction policy tuned for cache silently deletes queued jobs.
6. **Queue lanes**: `interactive` / `default` / `bulk` / `outbox`, with the outbox drain on its own worker
   so a backed-up AI queue cannot delay a domain event.
7. **Per-tenant `statement_timeout`** via a role setting, so one runaway report cannot hold a connection
   for minutes.

**What deliberately does NOT change:**

| Not doing | Why not |
|---|---|
| Partitioning | 40M rows in one heap is fine; vacuum still completes comfortably. Partitioning now costs the `UNIQUE (journal_line_id)` guarantee (see §"Partitioning") for no benefit. |
| `account_period_balances` as a *performance* mechanism | It exists from Tier 1, but the covering index is what actually fixes the median tenant. The rollup earns its keep against whales. |
| Sharding | One 8-vCPU box at 202 writes/s is at maybe 5% of its write capacity. |
| A dedicated search engine | See §"Search" — tenant-scoped search over ~500k rows per tenant is a Postgres GIN index. |
| A separate vector database | See §"AI workload" — retrieval is tenant-filtered; per-tenant corpora are ~50k vectors. |
| Multi-region | Every tenant is within ~30 ms of a single GCC region. |

## 5. Migration path (Tier 1 → Tier 2, no downtime)

| Step | Method | Downtime |
|---|---|---|
| Covering index | `CREATE INDEX CONCURRENTLY` (note: Laravel migrations wrap in a transaction by default — must opt out) | None |
| Read replica | Provision streaming standby, then route reporting reads behind a feature flag, tenant by tenant | None |
| PgBouncer | Deploy alongside; move one app container's `DATABASE_URL` to the pooler; watch `PgBouncerSafetyTest` metrics for 48 h; then move the rest | None |
| Redis split | Deploy the second instance; drain the queue on the old one; repoint workers | Seconds of queue pause |
| Instance resize | Managed-Postgres failover to a larger replica | ~30 s connection blip; retry logic absorbs it |

## 6. Cost envelope

| Line | Monthly |
|---|---|
| Postgres 8 vCPU / 64 GB / 1 TB + 1 replica | $1,800 |
| PgBouncer + 6 app + 3 worker containers | $700 |
| Redis × 2 | $250 |
| Object storage (~1 TB) + CDN | $150 |
| Observability (metrics, logs, traces) | $400 |
| **Non-AI subtotal** | **~$3,300** |
| **AI** (1,000 × ~$13) | **~$13,000** |
| **Total** | **~$16,000/month** |

On $100,000 MRR: **16% of revenue. AI is 80% of infrastructure.**

## 7. Trigger metrics — you are approaching Tier 3 when

| Metric | Threshold | Start |
|---|---|---|
| Customers | > 4,000 | Reading Tier 3 |
| `max(ledger rows per company_id, fiscal_year_id)` | **> 1,000,000** | Build/verify `account_period_balances` as the *serving* path for TB, not just a projection |
| p95 trial-balance latency | > 500 ms | Same |
| `ledger_entries` table size | > 300 GB | Design the partition scheme (§"Partitioning") |
| Peak row-writes/s | > 2,000 | Move WAL to a dedicated volume; review `checkpoint_timeout`/`max_wal_size` |
| Replica lag p99 | > 5 s | Investigate long transactions; consider a second replica |
| Autovacuum: `n_dead_tup` on `journal_lines` | > 20% of live tuples | Partition, or tune autovacuum per-table |
| `ai_cost_per_customer_month / ARPU` | > 15% | Model-tiering and cache-hit audit |

---

# Tier 3 — 10,000 customers

## 1. Stated assumptions

| Input | Value | Why |
|---|---|---|
| Customers | 10,000 | Roughly 1% of the GCC addressable base (see the Tier 5 reality check) |
| Users per customer | 4 | |
| Journal entries / customer / month | 800 | Mix assumed stable |
| Lines per entry | 4.2 | |
| Documents / customer / month | 300 | |
| AI calls / customer / month | 800 | |
| Largest single tenant | **50× the median** (40,000 entries/month) | **Guess**, but the shape (power-law tenant sizes) is near-certain |

## 2. Derived load

```
lines/month = 10,000 × 800 × 4.2          =   33,600,000
lines/year                                =  403,200,000
DB (403M × 1.65 KB)                       = ~665 GB/year → ~1.4 TB by end of year 2

mean lines/s = 33,600,000 / 633,600       = 53 lines/s
peak ×8 (some averaging; month-end close is still correlated)
                                          = 424 lines/s = 101 entries/s
peak row-writes = 101 × 16                = ~1,616 row-writes/s

WAL estimate: 424 ledger rows/s × ~1 KB WAL/row (heap + 6 index inserts,
full-page writes amortised)               = ~424 KB/s = ~36 GB/day

users = 40,000; concurrent 15% = 6,000; × 0.2 = 1,200 req/s
peak read queries = 1,200 × 8             = ~9,600 read q/s

whale tenant: 40,000 entries/mo × 4.2 × 12 = 2,016,000 ledger rows/fiscal year
AI = 10,000 × 6.5M                        = ~65B tokens/month
Documents: 10,000 × 300 × 250 KB × 12     = ~9 TB/year
```

## 3. What breaks first

**Reporting reads and vacuum competing with the write path on the same primary.**

At 9,600 read q/s a single primary is saturated on CPU before it is saturated on writes. Worse, the two
workloads interfere: a whale tenant's year-to-date trial balance scanning 2 million rows evicts the hot
posting-path pages from `shared_buffers`, and the posting path then goes to disk. This is the classic
"one big report made everything slow" incident, and it appears here for the first time.

Simultaneously, `ledger_entries` crosses ~400 GB. At that size:
- Autovacuum on the table takes hours, and index-only scans degrade because the visibility map goes stale.
- `REINDEX` becomes an operation you have to schedule rather than run.
- Restoring a single logical table is no longer a thing you can do in a maintenance window.

**Named bottleneck: the primary's shared buffer pool, contended between OLTP posting and per-tenant
reporting scans — with table-level maintenance cost as the close second.**

## 4. What changes

1. **`account_period_balances` becomes the serving path for every balance report**, not merely a
   projection. A trial balance becomes a ~60-row index scan (one row per *active* account per period),
   independent of tenant size. This is the change that decouples report latency from ledger volume, and
   it is the single largest scalability win available to QAYD. See §"Aggregates and caching".
2. **Partition `ledger_entries` by fiscal period** (RANGE on `entry_date`, or LIST on `fiscal_period_id`).
   This does *not* raise write throughput — every partition still lives on one primary — but it makes
   index size, vacuum time, and closed-year archival tractable, and gives partition pruning to every
   date-scoped report. See §"Partitioning" for the `UNIQUE` and RLS consequences, which are real.
3. **Dedicated reporting replica(s)** — at least two: one for tenant-facing reports (with the LSN rule),
   one for internal analytics and long queries.
4. **Build the tenant-routing indirection now, while it is cheap.** A `tenant_directory` table mapping
   `company_id → shard_key`, consulted by a connection resolver, with every shard being the same schema.
   At Tier 3 there is exactly one shard and the indirection is a no-op. Adding it at Tier 4 under load is
   a rewrite of every connection path. **This is the highest-leverage cheap item at Tier 3.**
5. **Whale isolation policy.** Define "whale" as *any tenant exceeding 5% of a shard's rows or 20% of its
   IOPS*, and give the whale its own shard. This partially reverses the DB-per-tenant rejection — but
   **per tenant, by evidence, not globally by policy**. See §"Tenancy evolution".
6. **Per-tenant resource guards**: queue rate limits per company, `statement_timeout` per role, and a hard
   per-tenant AI budget enforced in the database.
7. **AI: introduce batching and model tiering as a cost discipline, not an experiment.** At $100k/month of
   AI spend, a 40% saving is $40,000/month — more than the entire non-AI infrastructure bill.

**What deliberately does NOT change:**

| Not doing | Why not |
|---|---|
| Sharding by tenant | One primary at 1,616 row-writes/s is well within a modern NVMe Postgres (which does 5–10k simple writes/s). Sharding now buys nothing and costs cross-shard reporting, cross-shard migrations, and a permanently more complex ops story. |
| A separate OLAP store | The queries are `SUM(signed_base_amount) GROUP BY account, period` over a tenant-scoped, indexed, pre-rolled table. That is not an OLAP workload. See §"Reporting". |
| A dedicated search engine | Still tenant-scoped. See §"Search". |
| A separate vector store | Still tenant-scoped. See §"AI workload". |
| Multi-region write | Ledger + gapless numbering + hash chain all require a single writer per tenant. See §"Multi-region". |
| Rewriting the modular monolith into services | The load argument for services is absent; the team argument may or may not have arrived. Do not conflate them. |

## 5. Migration path (Tier 2 → Tier 3, no downtime)

**Partitioning an existing 400 GB `ledger_entries` without downtime** is the hard one. The append-only
property makes it tractable:

```
1.  Create ledger_entries_new PARTITION BY RANGE (entry_date),
    with partitions for every existing period + 12 future periods.
    Apply RLS + FORCE + policies + append-only trigger to EACH partition.
2.  Dual-write: PostingService writes to both tables in the same transaction.
    (Cheap and safe precisely because the ledger has exactly one writer — P7.)
3.  Backfill historical rows period by period, oldest first,
    INSERT ... SELECT with a bounded batch size, off-peak.
    Append-only means backfilled rows can never go stale mid-copy.
4.  After each period, verify: SUM(signed_base_amount) and COUNT(*) per
    (company_id, account_id, period) must match exactly between old and new.
5.  When backfill completes and verification is clean for 24 h, flip reads
    behind a feature flag, one tenant cohort at a time.
6.  Stop dual-write. Keep the old table read-only for 30 days. Then drop.
```

Total downtime: **zero**. Total risk: the verification query in step 4 is the whole safety argument, and it
is only possible because the source is append-only. Odoo could not do this migration at all.

`account_period_balances` cutover is simpler: it is already being maintained by trigger, so cutting the
report over is a query change plus a `RebuildPeriodBalancesAction` drift check that must return zero rows.

## 6. Cost envelope

| Line | Monthly |
|---|---|
| Postgres primary 32 vCPU / 256 GB / 8 TB + 2 replicas | $12,000 |
| ~25 app + ~10 worker containers | $3,500 |
| Redis cluster (cache + queue + rate limits) | $900 |
| Object storage ~30 TB cumulative + CDN egress | $1,200 |
| Observability (this becomes a real line item) | $3,000 |
| **Non-AI subtotal** | **~$21,000** |
| **AI** (10,000 × ~$10 with tiering, caching, batching) | **~$100,000** |
| **Total** | **~$120,000/month** |

On $1,000,000 MRR: **12% of revenue. AI is 83% of infrastructure.**

## 7. Trigger metrics — you are approaching Tier 4 when

| Metric | Threshold | Start |
|---|---|---|
| Customers | > 40,000 | Reading Tier 4 |
| Peak row-writes/s sustained | > 8,000 | Shard planning becomes urgent |
| Primary DB size | **> 2 TB** | Same — restore time is now the binding constraint, not throughput |
| Full restore wall-clock (measured, not estimated) | > stated RTO | **Stop and fix this before shipping features** |
| Any single tenant | > 5% of shard rows or > 20% of shard IOPS | Move that tenant to its own shard |
| Replica lag p99 during month-end | > 30 s | Reporting replica is undersized or a long transaction is pinning WAL |
| `outbox_events` undispatched depth | > 10,000 | Shard the outbox drain by `company_id` bucket |
| Regulatory: any customer requires physical data separation | any | Evaluate dedicated-shard tenancy (§"Tenancy evolution") |

---

# Tier 4 — 100,000 customers

## 1. Stated assumptions

| Input | Value | Why |
|---|---|---|
| Customers | 100,000 | Roughly 10% of the GCC addressable base — an aggressive but not absurd regional outcome |
| Users per customer | 5 | Larger average customer |
| Journal entries / customer / month | 800 | |
| Lines per entry | 4.2 | |
| AI calls / customer / month | 800 | Held flat *by policy* — see the spend cap discussion |
| Peak factor | 6× | More averaging, but month-end close is still correlated across all tenants |

## 2. Derived load

```
lines/month = 100,000 × 800 × 4.2         =   336,000,000
lines/year                                = 4,032,000,000  (4.0B)
DB (4.03B × 1.65 KB)                      = ~6.6 TB/year → ~15–20 TB cumulative

mean lines/s = 336,000,000 / 633,600      = 530 lines/s
peak ×6                                   = 3,183 lines/s = 758 entries/s
peak row-writes = 758 × 16                = ~12,100 row-writes/s

WAL: 3,183 rows/s × ~1 KB                 = ~3.2 MB/s = ~275 GB/day

users = 500,000; concurrent 12% = 60,000; × 0.2 = 12,000 req/s
peak read queries = 12,000 × 8            = ~96,000 read q/s

AI = 100,000 × 6.5M                       = ~650B tokens/month
Documents: 100,000 × 300 × 250 KB × 12    = ~90 TB/year
```

## 3. What breaks first

**Two things, and the second is the real one.**

1. **Single-primary write ceiling.** 12,100 row-writes/s with 3.2 MB/s of WAL is at the edge of what one
   very large Postgres does comfortably while also serving reads, running autovacuum, and maintaining a
   synchronous standby. It is achievable on a 64-core machine with fast NVMe — but there is no headroom
   for a bad day.

2. **Blast radius — and this is the decisive one.** A single 20 TB Postgres is:
   - one restore (measured in *hours*, possibly a day),
   - one major-version upgrade,
   - one runaway vacuum,
   - one bad query plan,
   - one corrupt page,

   affecting **100,000 businesses simultaneously**. For a system that keeps other people's books, the
   argument for sharding at this tier is a *risk* argument, not a throughput argument. Throughput could be
   bought with a bigger machine; blast radius cannot.

   A useful framing: at Tier 3, an incident is "our service is degraded." At Tier 4 on one database, an
   incident is "100,000 companies cannot close their month." Those are different businesses.

## 4. What changes

1. **Shard by tenant.** Route on `tenant_directory.shard_key` (built at Tier 3, now finally used). Target
   ~8–16 shards, each carrying a Tier-3-class load (roughly 6,000–12,000 tenants). Each shard is
   **byte-identical in schema** and keeps full RLS — sharding replaces *nothing* about the tenancy model,
   it only bounds the blast radius. See §"Tenancy evolution".
2. **Shard-local everything.** Every query, every job, every report is shard-local. The only cross-shard
   reads are platform operations (billing, usage, fleet health), which run against a **separate
   aggregation store fed by the outbox** — never by fanning out reads across shards.
3. **Data residency becomes a routing key.** `companies.data_region` (added at Tier 3 as a column even
   with one region) now participates in shard selection. See §"Multi-region".
4. **Object storage lifecycle + legal hold** become real operational systems, not settings: 90 TB/year of
   documents at standard-class pricing is a line item worth managing.
5. **Dedicated AI cost engineering function.** At $800k/month, a 10% improvement in token economics is
   $80,000/month — comparable to an entire engineering team's fully-loaded cost. This is where AI cost
   stops being an infrastructure concern and becomes a product-economics concern.
6. **Per-shard DR with independent RPO/RTO.** The advantage of shards is that recovery is per-shard;
   measure and report RTO per shard, not for "the database".

**What deliberately does NOT change:**

| Not doing | Why not |
|---|---|
| RLS | Sharding bounds blast radius; it does not isolate tenants *within* a shard. RLS is still the boundary, on every shard, forever. |
| The single `PostingService` | One writer into the ledger, per shard. Sharding does not license a second write path. |
| Append-only ledger, NUMERIC money, DB-enforced invariants | These are `01_ENGINEERING_PRINCIPLES` items. They do not scale-negotiate. |
| Active-active writes | See §"Multi-region". A ledger with gapless numbering and a hash chain has no merge function. |
| Cross-shard joins in application code | If a feature needs one, the feature is wrong or the sharding key is wrong. |

## 5. Migration path (Tier 3 → Tier 4, no downtime)

Sharding a live 15 TB database is the largest migration in this document. It is tractable **only** because
tenants are independent.

```
1.  Provision shard-2 with identical schema (same migrations, same RLS, same roles).
2.  Select a cohort: the smallest ~500 tenants, chosen for low blast radius.
3.  Per tenant, one at a time:
      a. Mark the tenant read-only in tenant_directory (a flag the API honours;
         a short, announced, per-tenant maintenance window of seconds-to-minutes).
      b. Logical-replicate / dump-restore that tenant's rows to shard-2.
      c. Verify: per (company, account, fiscal_period), COUNT(*) and
         SUM(signed_base_amount) must match; hash-chain head must match;
         audit_logs row count must match.
      d. Flip tenant_directory.shard_key. Clear read-only.
      e. Leave source rows in place, read-only, for 30 days.
4.  Repeat, widening the cohort as confidence grows. Whales go last and alone.
5.  Reclaim source space only after the 30-day window.
```

Per-tenant downtime: **seconds to minutes, announced, one tenant at a time.**
System-wide downtime: **zero.**
The verification in 3(c) is the entire safety argument — and step (c)'s hash-chain check is something most
systems cannot perform at all. See §"Disaster recovery".

## 6. Cost envelope

| Line | Monthly |
|---|---|
| 8–16 Postgres shards (each ~Tier-3 class, with replicas) | $110,000 |
| ~120 app + ~40 worker containers | $18,000 |
| Redis fleet (per-shard cache; central queue) | $4,000 |
| Object storage ~300 TB + CDN egress | $10,000 |
| Observability across a fleet | $15,000 |
| **Non-AI subtotal** | **~$160,000** |
| **AI** (100,000 × ~$8) | **~$800,000** |
| **Total** | **~$960,000/month** |

On $10,000,000 MRR: **~10% of revenue. AI is 83% of infrastructure.**

## 7. Trigger metrics — you are approaching Tier 5 when

| Metric | Threshold | Start |
|---|---|---|
| Customers | > 400,000 | Reading Tier 5 — **and re-reading the market-reality note below** |
| Shard count | > 24 | Automate shard provisioning and rebalancing; manual shard ops stops scaling around here |
| Any customer outside the GCC | any | Region-per-jurisdiction planning (§"Multi-region") |
| AI spend | > 40% of gross margin | Product-level intervention, not an infrastructure one |
| Shard rebalance frequency | > 1/month manual | Build automated tenant migration as a product feature |

---

# Tier 5 — 1,000,000 customers

## 0. The reality check that has to come first

**1,000,000 paying accounting customers is approximately the entire GCC addressable market.**

Rough public figures (**approximate; verify before using commercially**): Saudi Arabia has on the order of
1.2–1.4M commercial registrations, the UAE several hundred thousand active licences, and Kuwait, Qatar,
Bahrain and Oman together a few hundred thousand more — call it **2.5–3.5M registered entities**, of which
perhaps **30–40%** are plausibly addressable (formal enough to need software, solvent enough to pay). That
puts the GCC ceiling around **1 million businesses**.

So Tier 5 means one of two things:

- **100% GCC market share**, which does not happen; or
- **QAYD has left the region** — in which case the multi-region and data-residency architecture is no
  longer a nice-to-have but the primary architectural constraint, and the assumptions below (single
  timezone, one regulatory regime, one currency family) all break.

Tier 5 is therefore included as a **stress test of the architecture's shape**, not as a plan. Its most
useful output is: *does anything in the Tier 4 design have a hard ceiling that would require a rewrite?*
The answer, on the numbers below, is **no** — the design scales by adding shards and regions, which is the
property you actually want from a scaling plan.

## 1. Stated assumptions

| Input | Value | Change | Why |
|---|---|---|---|
| Customers | 1,000,000 | ×10 | Global, not GCC-only |
| Users per customer | 5 | — | |
| Journal entries / customer / month | 800 | — | |
| Lines per entry | 4.2 | — | |
| Peak factor | **4×** | ↓ from 6× | Genuine timezone spread finally provides averaging |
| Regions | **3–5** | new | Residency-driven, not latency-driven |
| AI calls / customer / month | 800 | — | Assumes the spend cap held |

## 2. Derived load

```
lines/month = 1,000,000 × 800 × 4.2       =   3,360,000,000
lines/year                                =  40,320,000,000  (40B)
DB (40.3B × 1.65 KB)                      = ~66 TB/year → ~200 TB cumulative

mean lines/s = 3.36e9 / 633,600           = 5,303 lines/s
peak ×4                                   = 21,213 lines/s = 5,051 entries/s
peak row-writes = 5,051 × 16              = ~81,000 row-writes/s

users = 5,000,000; concurrent 12% = 600,000; × 0.2 = 120,000 req/s
peak read queries                         = ~960,000 read q/s

AI = 1,000,000 × 6.5M                     = ~6.5 trillion tokens/month
Documents: ~900 TB/year → multi-petabyte cumulative
```

## 3. What breaks first

**Shard operations, and specifically shard *rebalancing*.**

At 60–100 shards, the technical load per shard is Tier-3-comfortable. Nothing about Postgres is the
problem. What breaks is that **tenants grow at wildly different rates**, so shards drift out of balance,
and rebalancing means moving live tenants between shards — the Tier-4 migration procedure, executed
continuously rather than once. If that procedure is manual, it becomes a full-time team and then a
bottleneck on growth.

Second: **cross-region regulatory divergence.** With 3–5 regions under different data-protection and
record-retention regimes, "what is the retention period for this document" becomes a per-region policy
lookup rather than a constant, and "can this tenant's data be read from region X" becomes a routing
decision with legal consequences.

Third, and least interesting: **observability cost**. At a million tenants, naively logging every request
produces more data than the ledger does. Sampling and cardinality discipline become architecture.

## 4. What changes

1. **Automated shard rebalancing as a product feature** — the Tier-4 tenant-migration procedure, wrapped
   in an operator-driven Action with the same verification steps, executed by a scheduler. The
   verification (row counts, sums, hash-chain head) does not change; only the trigger does.
2. **Region as a first-class tenancy dimension.** `companies.data_region` becomes immutable after
   provisioning (moving a tenant across regions is a legal event, not a technical one), and every shard
   belongs to exactly one region.
3. **Per-region control planes.** Billing, usage aggregation, and platform admin run per-region, with a
   global roll-up that carries only aggregates — never tenant records.
4. **Tiered object storage with per-region lifecycle policy**, and a legal-hold service that can freeze
   deletion for a named tenant or period across every region.
5. **Everything else is more of Tier 4.** That is the point of the exercise.

**What deliberately does NOT change** — and this list is the actual conclusion of the whole document:

- RLS as the tenancy boundary, on every shard, in every region.
- One `PostingService`, one way into the ledger, per shard.
- Append-only `ledger_entries` with a hash chain and external anchors.
- `NUMERIC(19,4)` money; no floats, anywhere, ever.
- Every invariant in PostgreSQL, not in application code.
- Single-writer-per-tenant. No active-active ledger writes.

## 5. Migration path (Tier 4 → Tier 5)

There is no new mechanism. Tier 5 is Tier 4 executed more times and more automatically:

- New shards: provision from the same migrations; register in `tenant_directory`.
- New regions: provision a control plane, then route new tenants there by `data_region`.
- Rebalancing: the Tier-4 per-tenant procedure, scheduled.

**The only genuinely new work is making the Tier-4 procedure unattended.** If Tier 4's migration was built
as a documented runbook rather than as an Action with automated verification, Tier 5 is a rewrite. That is
the argument for building it as an Action at Tier 4.

## 6. Cost envelope

| Line | Monthly |
|---|---|
| 60–100 Postgres shards across 3–5 regions, with replicas | $900,000 |
| App + worker fleet (~1,000 containers) | $150,000 |
| Object storage ~3 PB + CDN | $70,000 |
| Observability (sampled) | $80,000 |
| **Non-AI subtotal** | **~$1.2M** |
| **AI** (1,000,000 × ~$7) | **~$7,000,000** |
| **Total** | **~$8.2M/month** |

On $100,000,000 MRR: **~8% of revenue. AI is 85% of infrastructure.**

Note the trend across all five tiers: infrastructure falls from 20% → 16% → 12% → 10% → 8% of revenue,
while AI rises from 70% → 80% → 83% → 83% → 85% *of that infrastructure*. Scale improves QAYD's
non-AI unit economics substantially and its AI unit economics only modestly. **AI cost is the structural
margin risk of this business, at every tier.**

## 7. Trigger metrics — beyond Tier 5

At this point the metrics that matter are commercial, not technical: gross margin per tenant, AI cost as a
share of gross margin, and cost-to-serve by tenant cohort. The database stopped being the interesting
question two tiers ago.

---

# Deployment diagrams

## Tier 1 — 100 customers

One of everything. The diagram is small on purpose; anything more is a cost with no benefit.

```
                          ┌────────────────────┐
        browsers  ───────▶│  CDN / TLS edge    │
      (Next.js 15)        └─────────┬──────────┘
                                    │
                          ┌─────────▼──────────┐
                          │  apps/web (SSR)    │  Next.js static export + SSR
                          └─────────┬──────────┘
                                    │  /api/v1  (typed SDK)
                          ┌─────────▼──────────┐
                          │  apps/api          │  Laravel 12 / PHP 8.4
                          │  container × 2     │  ← THE domain layer
                          │  (API + scheduler) │     PostingService lives here
                          └──┬───────┬──────┬──┘
                             │       │      │
              ┌──────────────┘       │      └────────────┐
              │                      │                   │
    ┌─────────▼────────┐   ┌─────────▼────────┐  ┌───────▼────────┐
    │  PostgreSQL      │   │  Redis           │  │  apps/ai       │
    │  4 vCPU / 16 GB  │   │  cache + queue   │  │  FastAPI       │
    │  ~4 GB data      │   │  (single inst.)  │  │  proposes only │
    │                  │   └─────────┬────────┘  │  NO db creds   │
    │  RLS FORCE       │             │           └───────┬────────┘
    │  NOBYPASSRLS app │   ┌─────────▼────────┐          │
    │  role            │   │  queue worker    │          ▼
    │  daily PITR      │   │  container × 1   │    Anthropic API
    └─────────┬────────┘   └──────────────────┘    (Haiku/Sonnet/Opus)
              │
    ┌─────────▼────────┐   ┌──────────────────┐
    │  WAL archive     │   │  Object storage  │  documents, exports
    │  + weekly        │   │  ~90 GB/yr       │  signed URLs only
    │  RESTORE TEST    │   └──────────────────┘
    └──────────────────┘

RPO 5 min · RTO 4 h · one region (GCC) · no pooler, no replica, no shards
```

## Tier 3 — 10,000 customers

The pooler, the replicas, the partitioned ledger, and the shard indirection that is still a no-op.

```
                          ┌────────────────────┐
        browsers  ───────▶│  CDN / WAF / TLS   │
                          └─────────┬──────────┘
                          ┌─────────▼──────────┐
                          │  apps/web × N      │
                          └─────────┬──────────┘
                          ┌─────────▼──────────────────────────┐
                          │  apps/api × ~25 (stateless)        │
                          │  ┌──────────────────────────────┐  │
                          │  │ TenantConnectionResolver     │  │  ← built now,
                          │  │  company_id → shard_key      │  │    one shard,
                          │  │  (tenant_directory)          │  │    a no-op today
                          │  └──────────────────────────────┘  │
                          └──┬──────────────┬────────────┬─────┘
                             │              │            │
                  ┌──────────▼────┐  ┌──────▼─────┐  ┌───▼──────────┐
                  │  PgBouncer    │  │  Redis     │  │  apps/ai × M │
                  │  TXN MODE     │  │  cluster   │  │  batching +  │
                  │  ⚠ SET LOCAL  │  │  ┌───────┐ │  │  model tier  │
                  │    ONLY       │  │  │cache  │ │  └───┬──────────┘
                  └───┬───────┬───┘  │  │lru    │ │      │
                      │       │      │  ├───────┤ │      ▼
        writes ───────┘       └───┐  │  │queue  │ │  Anthropic API
                                  │  │  │noevict│ │  (Batches API for
     ┌────────────────────────────▼──┴──┴───────┴─┐  non-interactive)
     │  PostgreSQL PRIMARY  32 vCPU / 256 GB      │
     │  ~1.4 TB                                   │
     │                                            │
     │  ledger_entries  PARTITION BY RANGE(date)  │
     │   ├ p2026_01 … p2026_12  (RLS on EACH)     │
     │   └ append-only trigger on EACH            │
     │  account_period_balances  ← serves every   │
     │                             balance report │
     │  outbox_events  (SKIP LOCKED drain)        │
     └───────┬─────────────────────┬──────────────┘
             │ streaming           │ streaming
   ┌─────────▼────────┐  ┌─────────▼────────┐   ┌──────────────────┐
   │ REPLICA A        │  │ REPLICA B        │   │ Object storage   │
   │ tenant reports   │  │ internal analytics│  │ ~30 TB + lifecycle│
   │ (LSN-pinned —    │  │ long queries OK  │   │ + legal hold      │
   │  read-your-writes)│ └──────────────────┘   └──────────────────┘
   └──────────────────┘
             │
   ┌─────────▼──────────────────────────────────┐
   │ WAL archive + PITR + weekly restore test   │
   │ + daily KMS-signed hash-chain anchor       │  ← makes a restore
   └────────────────────────────────────────────┘     PROVABLY complete

RPO 30 s · RTO 30 min · one GCC region · one shard · whale-isolation policy armed
```

## Tier 5 — 1,000,000 customers

Nothing new — Tier 3, replicated N times, grouped by region. That is the point.

```
                     ┌──────────────────────────────────┐
                     │  Global edge (CDN / WAF / DNS)   │
                     │  routes by tenant's data_region  │
                     └───┬──────────────┬───────────┬───┘
              ┌──────────┘              │           └──────────┐
    ══════════▼═══════════   ═══════════▼════════   ═══════════▼════════
     REGION: GCC              REGION: EU             REGION: (other)
     (residency: KW/SA/AE)    (residency: EU)        …
    ┌─────────────────────┐  ┌────────────────────┐ ┌───────────────────┐
    │ control plane       │  │ control plane      │ │ control plane     │
    │  billing · usage    │  │  (region-local)    │ │                   │
    │  platform admin     │  └────────────────────┘ └───────────────────┘
    ├─────────────────────┤
    │ apps/api fleet      │   Each region is a full, independent
    │  TenantConnResolver │   Tier-3 stack. No cross-region reads
    ├─────────────────────┤   of tenant data — ever.
    │ shard 01 ┐          │
    │ shard 02 ├ each =   │   ┌──────────────────────────────────────┐
    │ shard .. │ a Tier-3 │   │ Cross-region aggregation             │
    │ shard 20 ┘ database │──▶│  AGGREGATES ONLY (counts, $, health) │
    ├─────────────────────┤   │  fed by the outbox                   │
    │ whale shards        │   │  never tenant rows                   │
    │  (1 tenant each)    │   └──────────────────────────────────────┘
    ├─────────────────────┤
    │ object storage      │   ┌──────────────────────────────────────┐
    │  (region-local)     │   │ Automated shard rebalancer           │
    ├─────────────────────┤   │  = the Tier-4 tenant-migration       │
    │ WAL + PITR +        │   │    Action, on a schedule             │
    │ signed anchors      │   └──────────────────────────────────────┘
    └─────────────────────┘

RPO 10 s · RTO 15 min PER SHARD (blast radius = 1 shard, not 1 company base)
Single writer per tenant. No active-active. Ever.
```

---

# Capacity planning table

All figures per the assumptions in each tier section. **Bold = the binding constraint at that tier.**

| | **T1** 100 | **T2** 1,000 | **T3** 10,000 | **T4** 100,000 | **T5** 1,000,000 |
|---|---|---|---|---|---|
| Users | 300 | 4,000 | 40,000 | 500,000 | 5,000,000 |
| JE / customer / month | 500 | 800 | 800 | 800 | 800 |
| Lines / entry | 4.0 | 4.2 | 4.2 | 4.2 | 4.2 |
| **Ledger rows / month** | 200 K | 3.36 M | 33.6 M | 336 M | 3.36 B |
| **Ledger rows / year** | 2.4 M | 40.3 M | 403 M | 4.03 B | 40.3 B |
| Rows/yr for the **largest tenant** | ~120 K | ~400 K | **~2 M** | ~20 M | ~50 M |
| **DB size / year** | 4 GB | 66 GB | 665 GB | **6.6 TB** | 66 TB |
| DB size cumulative (yr 3) | ~12 GB | ~200 GB | ~2 TB | **~20 TB** | ~200 TB |
| Peak factor | 10× | 10× | 8× | 6× | 4× |
| Peak lines/s | 3.2 | 53 | 424 | 3,183 | 21,213 |
| Peak posted entries/s | 0.8 | 12.6 | 101 | 758 | 5,051 |
| **Peak row-writes/s** | 13 | 202 | 1,616 | **12,100** | 81,000 |
| WAL/day | ~0.4 GB | ~7 GB | ~36 GB | ~275 GB | ~2.7 TB |
| **Peak read queries/s** | 96 | 1,280 | **9,600** | 96,000 | 960,000 |
| App containers | 2 | 6 | 25 | 120 | ~1,000 |
| Postgres instances | 1 | 2 | 3 | 24–48 | 180–300 |
| Shards | 1 | 1 | 1 | 8–16 | 60–100 |
| Regions | 1 | 1 | 1 | 1 | 3–5 |
| Documents / year | 90 GB | 900 GB | 9 TB | 90 TB | 900 TB |
| **AI tokens / month** | 650 M | 6.5 B | 65 B | 650 B | **6.5 T** |
| AI $/customer/month | ~$14 | ~$13 | ~$10 | ~$8 | ~$7 |
| **AI $/month** | $1.4 K | $13 K | $100 K | **$800 K** | **$7.0 M** |
| Non-AI infra $/month | $0.6 K | $3.3 K | $21 K | $160 K | $1.2 M |
| **Total infra $/month** | ~$2 K | ~$16 K | ~$120 K | ~$960 K | ~$8.2 M |
| MRR at $100 ARPU | $10 K | $100 K | $1 M | $10 M | $100 M |
| **Infra as % of revenue** | 20% | 16% | 12% | 10% | 8% |
| AI as % of infra | 70% | 80% | 83% | 83% | 85% |
| RPO / RTO | 5 min / 4 h | 1 min / 2 h | 30 s / 30 min | 10 s / 15 min per shard | 10 s / 15 min per shard |

---

# Scale trigger → action decision table

**This is the operationally useful part of this document.** Wire every metric below into monitoring with
the stated threshold as an alert. Do the work when the alert fires — not before (waste) and not after
(incident).

Metrics are grouped by what they protect.

### Correctness and tenancy (do these regardless of tier)

| Metric | Threshold | Action | Why this threshold |
|---|---|---|---|
| Any `SET app.current_*` that is not `SET LOCAL` | **1** | Stop. Fix immediately. | A single occurrence is a live cross-tenant leak in queue workers, before any pooler exists |
| Tables with `company_id` lacking `NOT NULL` + FORCE RLS + the restrictive policy | **1** | Fail the build | A NULL `company_id` is invisible to an isolation predicate — the row leaks *out* of the boundary |
| Partitions of an RLS table without their own RLS + policies | **1** | Fail the build | Queries against a partition directly use that partition's policies, not the parent's |
| `PgBouncerSafetyTest` failures | **1** | Block the PgBouncer rollout | Fails open, silently, with plausible data |

### Database performance

| Metric | Threshold | Action |
|---|---|---|
| p95 trial-balance latency | > 200 ms | Add the covering index `(company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)` |
| p95 trial-balance latency | > 500 ms | Serve balances from `account_period_balances` |
| `max(ledger rows per company_id, fiscal_year_id)` | > 200 K | Covering index |
| same | > 1 M | Rollup as the serving path |
| `ledger_entries` size | > 300 GB | Design the partition scheme |
| `ledger_entries` size | > 600 GB | Execute the partition migration |
| Autovacuum duration on the hottest table | > 4 h | Partition, or tune per-table autovacuum |
| `n_dead_tup` / `n_live_tup` on `journal_lines` | > 20% | Same |
| Peak row-writes/s sustained | > 2,000 | Dedicated WAL volume; tune `max_wal_size` |
| Peak row-writes/s sustained | > 8,000 | Shard planning becomes urgent |
| `numbackends` / `max_connections` | > 40% | Run the `SET LOCAL` audit — **before** the pooler |
| same | > 60% | Deploy the pooler (audit already green) |
| Replica lag p99 | > 5 s | Second replica; hunt long transactions |
| Replica lag p99 during month-end | > 30 s | Reporting replica undersized |
| Primary DB size | > 2 TB | Shard. This is a **restore-time** threshold, not a throughput one |
| Any single tenant | > 5% of shard rows **or** > 20% of shard IOPS | Move to a dedicated shard |

### Queue and events

| Metric | Threshold | Action |
|---|---|---|
| Oldest job age, `interactive` lane, p99 | > 60 s | Add workers; check for a poison tenant |
| Oldest job age, `bulk` lane, p99 | > 30 min | Add workers, or the lane is mis-sized |
| `outbox_events` undispatched depth | > 10,000 | Shard the drain by `company_id` bucket |
| Job failure rate for a **single tenant** | > 20% of that tenant's jobs | Circuit-break that tenant; one bad payload must not burn fleet capacity |
| Redis memory used | > 70% | Split instances, or the queue and cache are sharing (they must not) |

### AI economics — the ones that decide whether this is a good business

| Metric | Threshold | Action |
|---|---|---|
| `ai_cost_per_customer_month / ARPU` | > 15% | Audit model tiering and cache hit rate |
| same | > 25% | Enforce hard per-tenant AI budgets; product-level throttle |
| same | > 40% of gross margin | Product intervention, not infrastructure |
| `cache_read_input_tokens / total input tokens` | < 50% | Prefix audit — almost certainly a silent invalidator or a prefix below the model's cacheable minimum |
| `cache_creation_input_tokens` consistently 0 despite a `cache_control` marker | any | The prefix is under the model's minimum (4,096 tokens on Opus 4.8 and Haiku 4.5) — pad it or drop the marker |
| Share of calls served by the cheapest capable model | < 60% | Escalation policy is mis-tuned |
| Share of non-interactive calls using the Batches API | < 50% at Tier 3+ | 50% discount left on the table |

### Storage, DR, and operations

| Metric | Threshold | Action |
|---|---|---|
| **Measured** full restore wall-clock | > stated RTO | **Stop feature work.** A restore slower than the promise is a broken promise |
| Days since last verified restore | > 7 | Automate it |
| Hash-chain verification failures | **1** | Incident. Treat as tamper until proven otherwise |
| Missing daily signed anchor | **1** | Incident — the chain's external tamper-evidence is gone |
| Object storage monthly egress | > 10% of stored bytes | Put a CDN in front; egress is the sleeper cost |
| Documents past `retention_until` with no legal hold | any backlog | Lifecycle policy is not running |
| Shard rebalances performed manually | > 1/month | Automate the tenant-migration Action |
| Log volume | > ledger write volume | Sample. Observability must not out-cost the product |

---

# Cross-cutting deep dives

Each dive follows the same shape: **rationale · advantages · disadvantages · alternatives · risks ·
effort (Fibonacci) · confidence.**

---

## A. Tenancy evolution — single DB + RLS, and when (if ever) to shard

**Rationale.** QAYD's tenancy boundary is a PostgreSQL RESTRICTIVE policy on `company_id = app_current_company_id()`,
with `FORCE ROW LEVEL SECURITY` and a runtime role that is `NOSUPERUSER NOBYPASSRLS`. This survives raw
SQL, queue jobs, console commands, BI connectors, replicas, and endpoints nobody remembers writing —
which is the entire argument, and it is not a scaling argument at all. It is a correctness argument that
happens to also scale well.

The scaling question is narrower: **when does one database stop being the right container for all
tenants?** The answer from the arithmetic above is **Tier 4 (~100,000 customers, ~15–20 TB, ~12,000
row-writes/s)** — and the binding reason is **blast radius**, not throughput. Throughput at Tier 4 is
achievable on one very large machine. A restore of one very large machine is not achievable inside any
RTO worth promising.

**Sharding does not replace RLS.** A shard bounds the blast radius of an *operational* failure. RLS bounds
the blast radius of a *code* failure. They defend different things, and a shard containing 6,000 tenants
without RLS is 6,000 tenants one bug away from each other. Every shard keeps the full boundary.

### Noisy neighbour

Postgres has **no per-tenant resource governor**. That is the honest limitation and the strongest
structural argument for eventual sharding. Before sharding, the mitigations are:

| Symptom | Mitigation | Available from |
|---|---|---|
| One tenant's bulk import saturates the write path | Per-tenant queue rate limit; `bulk` lane with bounded concurrency | Tier 2 |
| One tenant's report scan evicts hot pages from `shared_buffers` | Route reporting to a replica; serve balances from the rollup | Tier 2/3 |
| One tenant's runaway query holds a connection | `statement_timeout` set per role, and a lower one on the reporting path | Tier 2 |
| One tenant's AI usage burns the budget | Hard per-tenant spend cap, enforced in the database | Tier 1 |
| One tenant's malformed payload burns worker capacity | Per-tenant failure-rate circuit breaker | Tier 2 |

These cover the common cases. What they do **not** cover is sustained legitimate load from a large tenant
— which is the whale problem, not the noisy-neighbour problem.

### The whale tenant

Define it numerically so it is an alert, not a judgement call:

> **A tenant is a whale when it exceeds 5% of its shard's rows or 20% of its shard's peak IOPS.**

At Tier 3 with 10,000 tenants on one shard, 5% of rows means a tenant 500× the median. That happens: one
franchise group, one holding company consolidating twenty subsidiaries, one high-volume retailer.

The response is a **dedicated shard for that tenant** — which is DB-per-tenant, arrived at by evidence for
one customer rather than by policy for all. That is the important distinction: the architecture supports
1-tenant shards and 10,000-tenant shards with the same code, and **the application must never know which
kind it is talking to.**

### Why schema-per-tenant was rejected

| Problem | At 10,000 tenants |
|---|---|
| Migrations become O(tenants) DDL | One `ALTER TABLE` = 10,000 statements, hours of lock churn, and a partial-failure state where tenant 4,312 is on v87 and everyone else is on v88 |
| Catalog bloat | 10,000 schemas × ~40 tables × ~20 columns ≈ **8 million `pg_attribute` rows**. Query planning slows for *everything*, including tenants doing nothing wrong |
| `search_path` juggling | Has the *same* pooler hazard as GUCs, with worse failure semantics: a stale `search_path` returns the wrong tenant's data with no error and no RLS to catch it |
| Platform queries | Billing and usage become a 10,000-way `UNION` |
| It does not remove `company_id` | A Gulf group commonly runs one company per jurisdiction or licence. The multi-company relationship exists inside a customer, so the discriminator column is needed anyway |

### Why DB-per-tenant was rejected (for the general case)

| Problem | Consequence |
|---|---|
| Connection cost | Each database needs its own pool. N tenants × pool size is unbounded; a pooler in front of 10,000 databases is itself a scaling problem |
| Migration fan-out | Same partial-failure state as schema-per-tenant, plus per-database backup, monitoring, and alerting |
| Cost floor | A $100/month customer cannot carry a $50/month dedicated instance. This alone disqualifies it for SMB |

### When the rejection reverses

Three specific, per-customer conditions — none of which is "we got big":

1. **A regulator or a contract requires physical separation** for a named customer (a bank, a government
   entity, a listed company). Plausible in the GCC.
2. **An enterprise contract pays for it** and the price covers the cost floor.
3. **A whale exceeds a shard** (the 5%/20% rule).

In all three, the answer is a dedicated shard for that tenant, not a change of architecture. **Build the
`tenant_directory` indirection at Tier 3** so all three are a row update rather than a rewrite.

| | |
|---|---|
| **Advantages** | One schema, one migration, one backup story, one monitoring story, until 100,000 customers. RLS survives every access path. Sharding, when it comes, is additive and per-tenant. |
| **Disadvantages** | No per-tenant resource isolation until shards exist. Cross-tenant platform queries need an aggregation store rather than a simple join. Every table pays the `company_id` column and index. |
| **Alternatives** | Schema-per-tenant (rejected: catalog bloat, O(N) migrations, worse pooler failure mode). DB-per-tenant globally (rejected: cost floor, connection fan-out). Citus/distributed Postgres (deferred: adds a distributed-transaction failure mode to a system whose core invariant is a single-transaction balanced posting — revisit only if single-shard write ceiling, not blast radius, becomes the binding constraint). |
| **Risks** | Building `tenant_directory` too late (Tier 4 rewrite). Building sharding too early (permanent ops tax for nothing). Letting a "shard is the boundary" mental model erode RLS discipline — the most dangerous of the three. |
| **Effort** | `tenant_directory` + connection resolver: **8**. Tenant-migration Action with verification: **21**. Automated rebalancer: **13** on top. |
| **Confidence** | **High** on RLS-until-Tier-4 (the arithmetic is not close). **Medium** on the exact Tier-4 threshold — it depends on hardware and on how large the largest tenants actually get. **High** on "blast radius, not throughput, is the trigger." |

---

## B. Partitioning `ledger_entries`

**Rationale.** QAYD *can* partition its ledger, and Odoo structurally cannot — because Odoo's ledger is its
mutable invoice-line table, carrying `amount_residual`, `reconciled`, and `full_reconcile_id` that change
after the fact. QAYD's `ledger_entries` is append-only, enforced by a trigger that rejects UPDATE and
DELETE even for the owning role. An append-only table is the ideal partitioning candidate: rows never move
between partitions, partitions become immutable once their period closes, and closed periods can be
detached and archived without touching live data.

**Partition key: `entry_date` RANGE, monthly, aligned to fiscal periods.**

Not `company_id`, and the reasons are concrete:
- **LIST/HASH by company** at 100,000 tenants means 100,000 partitions. Postgres planning degrades badly
  past a few thousand; `pg_class` bloats; `DETACH` becomes meaningless.
- **Hash by company** gives no pruning for date-scoped reports (which is every financial report) and makes
  closed-year archival impossible.
- **Date range** prunes every trial balance, every P&L, every general-ledger listing, and makes "archive
  fiscal year 2026" a `DETACH PARTITION CONCURRENTLY`.

### The cost that must be stated: the UNIQUE constraint weakens

PostgreSQL requires a unique constraint on a partitioned table to **include every partition-key column**.
So today's

```sql
CONSTRAINT uq_ledger_entries_journal_line UNIQUE (journal_line_id)
```

is illegal on a table partitioned by `entry_date`. The only legal form is

```sql
UNIQUE (entry_date, journal_line_id)
```

which does **not** enforce the same invariant: the same `journal_line_id` could be projected twice at two
different dates. That UNIQUE is QAYD's database backstop for idempotent posting. Weakening it is a real
cost and must be a deliberate decision, not a discovery.

**Three options, and the recommendation:**

| Option | How | Verdict |
|---|---|---|
| **(a) Composite unique + a date-consistency guarantee** | `UNIQUE (entry_date, journal_line_id)` plus a trigger asserting `NEW.entry_date` equals the source `journal_lines.entry_date`. Since `PostingService` derives `entry_date` from the line, a double-projection at a *different* date requires a bug that also corrupts the date. | **Recommended** |
| (b) Global uniqueness registry | A small non-partitioned `ledger_projection_index (journal_line_id PK, company_id, ledger_entry_id)` written in the same transaction | **Rejected.** At Tier 4 that is 4B rows × ~50 B = 200 GB in a single unpartitioned globally-hot index — reintroducing exactly the problem partitioning solved |
| (c) Accept and detect | Nightly `VerifyLedgerProjectionAction`: `SELECT journal_line_id FROM ledger_entries GROUP BY journal_line_id HAVING count(*) > 1` — a partition-wise aggregate | **Adopt alongside (a)**, not instead of it |

Ship **(a) + (c)**. Record it as an ADR, because it is a genuine (small) weakening of a database-enforced
invariant, and `01_ENGINEERING_PRINCIPLES` P1 requires that to be explicit.

### RLS and partitions — the non-obvious failure

Row-level security policies defined on a **partitioned parent** apply to queries routed **through the
parent**. A query issued **directly against a partition** uses only that partition's own RLS state. So:

> **Every partition must independently get `ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY`, and
> the same restrictive + permissive policies.**

Automate this in the partition-creation function, and **extend the CI catalog check to enumerate partitions**
(`pg_class.relispartition`, `pg_inherits`) rather than only base tables. A partition created by a
maintenance job without RLS is a cross-tenant hole that no test currently looks for.

### Triggers and partitions

The append-only trigger must exist on every partition. PostgreSQL 11+ clones `AFTER ... FOR EACH ROW`
triggers from a partitioned parent to its partitions; `BEFORE ... FOR EACH ROW` triggers on partitioned
tables arrived in PostgreSQL 13. QAYD's append-only trigger is `BEFORE UPDATE OR DELETE ... FOR EACH ROW`.

> **Verify against the deployed PostgreSQL major version before relying on inheritance**, and if there is
> any doubt, create the trigger explicitly per partition in the same function that creates the partition.
> A partition without the trigger is a mutable slice of an "immutable" ledger.

### Retention and archival

```
period closes → partition becomes logically immutable
              → after the statutory retention window, DETACH PARTITION CONCURRENTLY
              → export to object storage (compressed, hash-verified against the chain)
              → drop, only if no legal hold covers any row in the range
```

The hash chain makes the exported partition **self-verifying**: its head hash ties into the chain, so an
archived year can be proven unaltered years later without the database.

### What partitioning does NOT buy

Stated explicitly because it is commonly assumed: **partitioning does not increase write throughput.** All
partitions live on the same primary and share the same WAL. It buys index size, vacuum time,
partition-pruned reads, and archivability. Throughput comes from sharding, one tier later.

| | |
|---|---|
| **Advantages** | Vacuum and reindex become per-partition and bounded. Every date-scoped report prunes. Closed years become detachable and archivable. Partition-wise aggregates speed up the drift checks. Only possible because the ledger is append-only. |
| **Disadvantages** | The `UNIQUE (journal_line_id)` guarantee weakens (mitigated above). RLS and triggers must be applied per-partition — a new class of thing to get wrong. Planning overhead on queries that cannot prune. A partition-creation job that fails silently is an outage waiting for the first day of a month. |
| **Alternatives** | No partitioning + bigger machine (works to ~1 TB, then vacuum and restore times dominate). Partition by `fiscal_period_id` LIST instead of `entry_date` RANGE — equivalent, marginally cleaner pruning once `fiscal_periods` lands, and it makes "close a period" and "freeze a partition" the same concept. Worth choosing when S2-07 lands. |
| **Risks** | The RLS-per-partition gap is the highest-severity risk in this section: it is silent, it is a cross-tenant leak, and no existing test covers it. Fix the CI check *before* the first partition exists. |
| **Effort** | Partition scheme + creation automation + RLS/trigger propagation + CI check extension: **13**. The zero-downtime migration of an existing 400 GB table: **21**. |
| **Confidence** | **High** that date-range partitioning is right and company partitioning is wrong. **High** on the UNIQUE consequence (it is a documented PostgreSQL rule). **Medium** on the trigger-inheritance detail — verify against the deployed major version rather than trusting this document. |

---

## C. Aggregates and caching

**Rationale.** Odoo stores no aggregate balances anywhere, at any granularity; every trial balance is a full
scan of the largest table. QAYD already has half the fix — `ledger_entries.signed_base_amount` makes any
balance a single `SUM()`. The other half is `account_period_balances`:

```sql
account_period_balances (
  company_id, account_id, period_id,
  opening, debit, credit, closing,
  CHECK (closing = opening + debit - credit)
)
```

maintained by an **AFTER INSERT trigger on `ledger_entries`**.

**Why QAYD can do this safely and Odoo cannot:** a cached aggregate is trustworthy only if its source is
append-only. QAYD's is. The trigger is therefore **monotonic** — it can only ever increment, never
reconcile against a mutation, never handle UPDATE or DELETE. That is what makes it correct by construction
rather than correct by vigilance.

### The quantitative case — and when it actually pays

This is where the per-tenant framing matters most. Run the crossover:

```
A trial balance without the rollup scans one tenant's ledger rows for one fiscal year.
Index scan + heap fetch: ~200k–1M rows/s warm, ~50k rows/s cold.
Target: p95 < 500 ms.
→ crossover at roughly 100k–200k rows scanned.
→ at 4.2 lines/entry, 200k rows ≈ 48,000 posted entries per fiscal year
→ ≈ 4,000 posted entries per month.

A tenant posting more than ~4,000 entries/month crosses the threshold within one fiscal year.
```

So:

| Tenant size | Rows/fiscal year | TB without rollup | Verdict |
|---|---|---|---|
| Median at Tier 1 (500 JE/mo) | 25,000 | ~15 ms | Rollup unnecessary |
| Median at Tier 3 (800 JE/mo) | 40,000 | ~25 ms | Rollup unnecessary |
| Large at Tier 3 (8,000 JE/mo) | 403,000 | ~400 ms – 2 s | **Rollup needed** |
| Whale at Tier 3 (40,000 JE/mo) | 2,016,000 | **2–10 s** | Rollup essential |

**Conclusion: `account_period_balances` is justified by whale tenants, not by customer count.** That is
why the trigger metric in the decision table is
`max(ledger rows per company_id, fiscal_year_id) > 1,000,000` and not "10,000 customers."

**The escalation ladder, cheapest first:**

| Step | Mechanism | Effort | Buys |
|---|---|---|---|
| 1 | Existing `idx_ledger_account_date` | 0 | Fine to ~100k rows/tenant/year |
| 2 | Covering index `(company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)` — index-only scan | **2** | 3–5× on the same scan; buys another tier |
| 3 | `account_period_balances` rollup | **8** | Constant-time TB regardless of tenant size: ~60 rows, one per active account per period |
| 4 | Report snapshots (immutable, as-of a ledger head hash) | **8** | Published/filed statements, which must be immutable anyway |

Do them in that order. Step 2 is often mistaken for "not a real fix" and is in fact the highest
value-per-point item in the list.

**Sizing the rollup.** Rows = companies × *active* accounts × periods. Not all accounts see activity:

```
Tier 3: 10,000 companies × ~60 active accounts × 12 periods = 7.2M rows/year
        7.2M × ~120 B = ~900 MB/year
Tier 4: 72M rows/year ≈ 9 GB/year
```

Trivial next to a 4-billion-row ledger, and a trial balance becomes a ~60-row index scan.

### The distinction that matters: projection vs cache

`account_period_balances` is **not a cache**. It is a projection with:
- a rebuilder (`RebuildPeriodBalancesAction`),
- a drift check that runs in CI and on a schedule,
- and a DB CHECK enforcing its own internal identity.

Per `01_ENGINEERING_PRINCIPLES` P19, derived data is rebuildable from its source. A cache has none of
those properties, which is why the two must not be confused in review.

### Cache invalidation driven by domain events

For things that genuinely are caches:

```
posting transaction commits
   → after-commit domain event (accounting.journal.posted)
   → outbox row (never lost, never fires on rollback)
   → drain publishes
   → cache invalidator marks (company_id, period_id) dirty
   → Reverb push tells the client to refresh authoritative state
```

Key rule already in the stack: the realtime channel **tells the client to re-fetch**; it never carries the
new value. A push that carries a number is a second write path with no invariants.

### What must NEVER be cached

> **A number a user could mistake for authoritative must not be served from a cache.**

| Never cached | Because |
|---|---|
| Current bank / cash balance | A user initiates a payment against it |
| AR / AP aging shown before a payment run | Same |
| Tax liability on a filing screen | Carries legal liability |
| Any figure on a document the user will sign, file, or send | Same |
| Remaining credit limit, available stock | Decisions are made against it |

| Safe to cache | With what |
|---|---|
| Chart-of-accounts lists, currency lists, tax-code lists | Standard TTL; invalidate on the config-changed event |
| Resolved permissions | Already handled by `perms_ver` cache-busting |
| Rendered *historical* report output for a **closed** period | Immutable by definition once the period is locked |
| Dashboard trend charts | **Only with a visible as-of timestamp** |

**The rule to enforce in review:** *a cached number must carry its as-of and its provenance, or it must not
be shown.* A stale balance with a visible "as of 14:32" is a product decision. A stale balance rendered as
current is a defect.

| | |
|---|---|
| **Advantages** | Trial balance latency decoupled from ledger size. Monotonic trigger, so the aggregate cannot silently drift. A DB CHECK on the rollup's own identity. Enables balance sheet and P&L without a per-report scan. |
| **Disadvantages** | An AFTER INSERT trigger on the hottest write path — every posted line pays an upsert (~4.2 upserts per entry, already counted in the 16 row-writes/s figure). A second place account balances live, requiring a drift check to stay honest. |
| **Alternatives** | Materialised views (rejected: `REFRESH` is a full recompute; `CONCURRENTLY` needs a unique index and still rebuilds everything). Application-maintained aggregates (rejected: P1 — an invariant maintained by application code is maintained only on the paths that remember). Compute-on-read with an aggressive cache (rejected: this is exactly the "authoritative number from a cache" failure). |
| **Risks** | The trigger becoming a write-path bottleneck at Tier 4 — mitigate by batching upserts per posting transaction rather than per row. Drift going undetected because the rebuild check is slow and gets disabled — keep it partition-wise so it stays fast. |
| **Effort** | Rollup + trigger + rebuilder + drift check: **8**. Covering index: **2**. Snapshots: **8**. |
| **Confidence** | **High** on correctness (monotonicity is a property of append-only, not a hope). **Medium** on the exact crossover row count — it depends on hardware, cache warmth, and the eventual row width; measure rather than trust the 200k figure. |

---

## D. Queue architecture

**Rationale.** Laravel queues on Redis are already available and lightly used. The evolution is not
technology, it is **isolation** — one tenant's bulk import, one slow AI call, or one poison payload must
not delay a domain event or a user-blocking export.

### The lanes

| Lane | Contents | SLA | Workers (T3) |
|---|---|---|---|
| `outbox` | Domain-event drain, nothing else | seconds | 2, dedicated |
| `interactive` | User-blocking: report generation, export, PDF | < 10 s p95 | scaled to demand |
| `default` | Notifications, webhooks, indexing | < 60 s | pooled |
| `bulk` | Imports, backfills, AI batch, revaluation runs | minutes–hours | bounded concurrency, per-tenant rate-limited |

**The outbox drain gets its own worker.** If it shares a worker pool with AI relay jobs, a rate-limited AI
provider stalls domain events — which stalls cache invalidation, which serves stale numbers. Cheap to
prevent, expensive to diagnose.

### Idempotency

**Laravel's `ShouldBeUnique` is a lock, not idempotency.** It prevents concurrent duplicates; it does not
prevent a job that already committed its side effect from running again after a worker crash between the
side effect and the ack.

Real idempotency:

```sql
job_executions (
  event_id     BIGINT NOT NULL,
  handler      TEXT   NOT NULL,
  company_id   BIGINT NOT NULL,
  executed_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (event_id, handler)
)
```

Every handler upserts its `(event_id, handler)` row **in the same transaction as its side effect**. A
replay hits the primary key and no-ops. This is the same shape as the ledger's
`UNIQUE (journal_line_id)` idempotency backstop, applied one layer out.

### Poison messages

```
attempt 1 → fail → backoff 10 s
attempt 2 → fail → backoff 60 s
attempt 3 → fail → failed_jobs + alert + QUARANTINE
```

Quarantine matters at Tier 3+: a per-tenant failure-rate circuit breaker. If > 20% of one tenant's jobs
fail, pause that tenant's lane and alert. Without it, one tenant's malformed import consumes retry capacity
for the whole fleet — a real and common outage shape.

### The outbox drain

```sql
SELECT id, payload
  FROM outbox_events
 WHERE dispatched_at IS NULL
 ORDER BY id
   FOR UPDATE SKIP LOCKED
 LIMIT 100;
```

Throughput math:

```
T3: 101 entries/s × ~3 events/entry     = ~300 events/s
    one drainer at 100/batch, 10 batches/s = 1,000/s capacity → 1 drainer suffices
T4: 758 entries/s × 3                   = ~2,300 events/s
    → 3–4 drainers, and now they contend on the same rows
```

At Tier 4, shard the drain: claim by `WHERE company_id % :n = :worker_index`, or a dedicated
`bucket SMALLINT` column indexed alongside `dispatched_at`.

**Ordering caveat that must be stated:** `SKIP LOCKED` gives up global ordering. If per-aggregate ordering
matters (it does for "invoice posted" then "invoice paid"), the bucket must be `company_id`-derived so one
company's events stay on one drainer and therefore stay ordered. Hashing on `id` would break that
silently.

### Redis

- **Two instances, always.** Cache (`maxmemory-policy allkeys-lru`) and queue (`noeviction`). Sharing one
  means an eviction policy tuned for the cache silently deletes queued jobs under memory pressure. This is
  a well-known incident shape and costs nothing to avoid.
- At Tier 4, per-shard cache instances (locality) but a central queue (simpler routing).
- Redis is **not** a source of truth for anything. The outbox is in Postgres precisely so a Redis loss is
  a delay, not a data loss.

| | |
|---|---|
| **Advantages** | Isolation between user-blocking and bulk work. Domain events cannot be delayed by AI backpressure. Idempotency is a database constraint, not a convention. One tenant cannot burn fleet retry capacity. |
| **Disadvantages** | More worker pools to size and monitor. `job_executions` is another growing table (needs its own retention — 90 days is ample). Bucketed draining adds a routing concept. |
| **Alternatives** | Kafka / a real event log (rejected: adds a second durable store, a second ops story, and a second consistency model, to solve a problem an outbox table already solves at these volumes). SQS/managed queue (viable at Tier 4+; the outbox stays regardless — it is what makes the queue replaceable). Postgres-only queueing with `SKIP LOCKED` and no Redis (genuinely attractive at Tier 1–2; loses to Redis on latency at Tier 3+). |
| **Risks** | The lane split existing in config but not in worker deployment — i.e. all lanes served by one pool, which is the same as no lanes. Verify with a metric per lane, not with a config file. |
| **Effort** | Lanes + dedicated outbox worker: **5**. `job_executions` idempotency: **5**. Circuit breaker: **5**. Sharded drain: **5**. |
| **Confidence** | **High.** These are well-understood patterns and the volumes are not exotic. |

---

## E. AI workload — the differentiating cost centre

**This is the most important section in the document.** At every tier, AI is 70–85% of infrastructure cost,
and it is the only line item that scales linearly with *usage* rather than with *customers*.

### Token economics, per call

QAYD's AI shapes, with a token budget for each. The **cached prefix** is the company's chart of accounts,
the instruction block, and few-shot examples; the **fresh input** is the document or the specific question;
the **output** is structured JSON or prose.

| Shape | Model | Cached prefix | Fresh in | Out | Calls/customer/mo |
|---|---|---|---|---|---|
| Document extraction (OCR → structured fields) | `claude-haiku-4-5` | 4,000 | 2,000 | 600 | 300 |
| Journal drafting / bank-match proposal | `claude-sonnet-5` | 6,000 | 1,500 | 800 | 400 |
| Escalation (ambiguous, multi-document) | `claude-opus-4-8` | 10,000 | 4,000 | 2,000 | 40 |
| Natural-language query / chat | `claude-sonnet-5` | 8,000 | 600 | 700 | 60 |

Published pricing per million tokens (verify before committing to a price): Haiku 4.5 **$1 / $5**,
Sonnet 5 **$3 / $15** (introductory $2/$10 through 2026-08-31), Opus 4.8 **$5 / $25**. Cache **reads** are
~0.1× the input rate; cache **writes** are 1.25× (5-minute TTL) or 2× (1-hour TTL).

**Cost per call:**

```
Extraction   (Haiku)  = 4,000×$0.10/M + 2,000×$1/M + 600×$5/M     = $0.0054
Drafting     (Sonnet) = 6,000×$0.30/M + 1,500×$3/M + 800×$15/M    = $0.0183
Escalation   (Opus)   = 10,000×$0.50/M + 4,000×$5/M + 2,000×$25/M = $0.0750
Chat         (Sonnet) = 8,000×$0.30/M + 600×$3/M + 700×$15/M      = $0.0147
```

**Cost per customer per month:**

```
300 × $0.0054 = $1.62
400 × $0.0183 = $7.32
 40 × $0.0750 = $3.00
 60 × $0.0147 = $0.88
                -----
                $12.82  + ~8% cache-write amortisation ≈ $13.85

→ ~$14 per customer per month = 14% of a $100 ARPU
```

**Fourteen points of gross margin, spent on tokens.** That is the number to carry around.

### The counterfactual — what the naive build costs

Same workload, all on `claude-opus-4-8`, with no prompt caching:

```
Extraction:  (4,000+2,000)×$5/M + 600×$25/M    = $0.0450 × 300 = $13.50
Drafting:    (6,000+1,500)×$5/M + 800×$25/M    = $0.0575 × 400 = $23.00
Escalation:  (10,000+4,000)×$5/M + 2,000×$25/M = $0.1200 ×  40 =  $4.80
Chat:        (8,000+600)×$5/M + 700×$25/M      = $0.0605 ×  60 =  $3.63
                                                                 ------
                                                                 $44.93
→ 45% of revenue
```

**$14 vs $45 — a 3.2× difference, and the difference between an 80%-gross-margin business and a
45%-gross-margin one.** Not from a different product; from three engineering decisions.

### Where the saving actually comes from — and the surprise

Decomposing the $31 gap:

| Lever | Saving | Share of the gap |
|---|---|---|
| **Prompt caching** (stable per-company prefix) | ~$16 | **52%** |
| **Model tiering** (cheapest capable model first) | ~$13 | 42% |
| Cache-write amortisation via batching within a window | ~$2 | 6% |

**Prompt caching is worth more than model tiering for QAYD specifically**, and that is not the usual
answer. The reason is structural: QAYD's prompts are dominated by a large, stable, per-company prefix (the
chart of accounts, the posting rules, the few-shot examples). That is an unusually cache-friendly shape,
and it should be treated as an architectural asset, not a micro-optimisation.

### Three caching facts that will silently cost money if missed

1. **The minimum cacheable prefix is 4,096 tokens on Opus 4.8 and Haiku 4.5** (2,048 on some
   Sonnet-family models). A 200-account chart of accounts is roughly 2,400 tokens — **below the Haiku
   minimum**. A `cache_control` marker on a prefix under the minimum does nothing, silently:
   `cache_creation_input_tokens` returns 0 and no error is raised. **Deliberately construct the prefix
   (instructions + few-shot + COA) to clear 4,096 tokens**, and assert on `cache_read_input_tokens` in
   integration tests.

2. **The cached prefix is per-company**, because the chart of accounts is. So cache entries are
   per-tenant, and with the default 5-minute TTL, only tenants with *sustained* activity get hits. This
   means **AI unit cost improves with scale** — at Tier 1 with 100 sparsely-active tenants, hit rates will
   be poor; at Tier 3, most active tenants have continuous activity inside a 5-minute window during
   business hours. Budget pessimistically at Tier 1 and expect improvement, not the reverse.

3. **Anything that varies per request must sit after the last breakpoint.** A timestamp, a request id, or
   a non-deterministically-serialised JSON blob anywhere in the prefix invalidates everything after it.
   Since QAYD's prefix is assembled from database rows, **order the chart-of-accounts serialisation
   deterministically** (`ORDER BY code`) or the cache silently never hits.

For bursty tenants, the 1-hour TTL (2× write cost) breaks even at ≥3 reads and keeps the prefix alive
across gaps. Choose TTL per tenant activity profile, not globally.

### Batching

The Batches API is **50% off**, up to 100,000 requests per batch, most complete within an hour (24 h
maximum). QAYD workloads that tolerate that latency:

| Batchable | Not batchable |
|---|---|
| Nightly document extraction from an email/upload drop | Interactive drafting while the user waits |
| Month-end anomaly sweeps | Chat |
| Bulk historical-import classification | Bank-match proposals presented in a live session |
| Periodic re-embedding after a chart-of-accounts change | Anything with a user watching |

Realistically **~40% of calls** are batchable at Tier 3+ → **~20% off the total bill** → **$20,000/month at
Tier 3, $160,000/month at Tier 4.** This is the single largest remaining lever after caching and tiering,
and it requires no model change.

### Model tiering and escalation

```
                    ┌────────────────────────────┐
   input ──────────▶│  deterministic rules first │  no tokens at all
                    │  (exact ref/amount match,  │  ~40% of matching resolved
                    │   known vendor template)   │  here — and the AI only ever
                    └───────────┬────────────────┘  sees what rules couldn't settle
                        unresolved
                    ┌───────────▼────────────────┐
                    │  Haiku 4.5                 │  extraction, classification,
                    │  structured output +       │  duplicate detection
                    │  confidence score          │
                    └───────────┬────────────────┘
                  confidence < threshold
                    ┌───────────▼────────────────┐
                    │  Sonnet 5                  │  drafting, matching,
                    │                            │  NL query
                    └───────────┬────────────────┘
                  still ambiguous / high value
                    ┌───────────▼────────────────┐
                    │  Opus 4.8                  │  multi-document reasoning,
                    │                            │  close checklists, anomaly
                    └────────────────────────────┘  explanation
```

**Deterministic-first is not only cheaper — it keeps the AI honest.** The model only ever sees the cases
rules could not settle, which is also the highest-signal training and evaluation set.

Use **structured outputs** (`output_config.format` with a JSON schema) on every extraction and proposal
call. It removes an entire class of parse-failure retries, which are pure wasted spend.

### The boundary that does not scale-negotiate

> **The AI proposes. It never writes to the ledger.**

Enforced at the database level: the FastAPI engine holds no tenant DB credential, and the AI's role has no
INSERT on `ledger_entries` or `journal_*`. Proposals land in `match_proposals` / `dimension_suggestions`
with a confidence and a rationale; only a human-confirmed Laravel Action promotes one. This is
`01_ENGINEERING_PRINCIPLES` P15 and it is not a tier-dependent decision.

### Embedding storage — pgvector in the same DB, or a separate vector store?

**Argue it from QAYD's retrieval shape, not from vector-database marketing.**

Volume:
```
300 docs/customer/month × ~4 chunks/doc = 1,200 embeddings/customer/month
Tier 3: 10,000 × 1,200 × 12 = 144,000,000 embeddings/year
        1,536 dims × 4 B = 6 KB each → ~864 GB/year raw
        halfvec (2 B)    → ~432 GB;  768-dim halfvec → ~216 GB
```

That number sounds decisive — and it is, but for the *wrong* index design.

**The decisive fact: QAYD never does a global vector search.** Retrieval is always
`WHERE company_id = :current` first. The relevant corpus is not 144 million vectors; it is **one tenant's**
— roughly `1,200 × 12 = 14,400/year`, call it **~50,000 vectors for a mature tenant**. At 768-dim halfvec
that is ~75 MB. An HNSW index over 50,000 vectors fits in memory trivially and answers in single-digit
milliseconds.

**So the recommendation is: pgvector, in the same PostgreSQL, partitioned by company.**

| Why it wins | |
|---|---|
| RLS applies | The vector table gets the same `company_id` boundary as everything else. An external vector store is **a copy of tenant data outside the RLS boundary** — a new tenancy surface with its own auth, its own leak modes, and no `FORCE ROW LEVEL SECURITY` |
| One backup, one restore, one PITR | An external store means embeddings can be restored to a different point in time than the documents they describe |
| Transactional consistency | An embedding written in the same transaction as the document row cannot be orphaned |
| The billion-vector problem does not exist here | Because there is no global index |

**When this reverses.** Three specific conditions, none of which is "we got big":
1. The HNSW index build/rebuild time starts competing with autovacuum on the same instance (visible as
   maintenance windows lengthening) — **first mitigation is a separate PostgreSQL+pgvector instance**, not
   a different technology. The operational toolchain and the RLS model carry over.
2. A product feature genuinely requires cross-tenant retrieval — which would be a tenancy violation and
   should be rejected on those grounds first.
3. Per-tenant corpora exceed ~1M vectors with sub-50 ms recall requirements. On the arithmetic above, that
   is not reachable for QAYD's document volumes.

**Practical guidance regardless:** store vectors as `halfvec` (2 bytes/dim) rather than `vector` (4
bytes/dim) from the start — it halves the storage and the index memory for a recall difference that is
usually immeasurable, and converting later is a rewrite of the largest vector table.

### How AI cost scales relative to revenue

| Tier | AI $/customer | AI as % of ARPU | AI $/month | Why it improves |
|---|---|---|---|---|
| T1 | $14 | 14% | $1.4 K | Poor cache hit rates (sparse tenant activity), no batching |
| T2 | $13 | 13% | $13 K | Better hit rates; tiering matured |
| T3 | $10 | 10% | $100 K | Batching (~40% of calls), deterministic-first matching mature |
| T4 | $8 | 8% | $800 K | Fine-tuned/distilled paths for the highest-volume shapes |
| T5 | $7 | 7% | $7.0 M | Diminishing returns; the floor is real token cost |

**The risk this table hides:** it assumes **per-customer usage stays flat at ~800 calls/month**. If the
agentic product succeeds — "ask anything about my books", autonomous month-end close, always-on anomaly
monitoring — usage could easily triple, and $14 becomes $42, which is **42% of revenue**. The trend line
in the table is a *policy outcome*, not a natural law.

Therefore:

> **Enforce a hard per-tenant monthly AI budget in the database**, checked before the call, not after.
> A spend cap that lives in a dashboard is not a spend cap.

And instrument `ai_cost_per_customer_month / ARPU` as a first-class product metric with the thresholds in
the decision table (15% audit, 25% enforce, 40%-of-gross-margin product intervention).

| | |
|---|---|
| **Advantages** | 3.2× cost reduction from three decisions that cost roughly 21 points to build. Deterministic-first keeps the AI honest and generates the best evaluation set. pgvector-in-Postgres keeps the tenancy boundary intact for the one dataset most likely to leak. The AI-never-writes boundary is DB-enforced, so no amount of scaling pressure can erode it. |
| **Disadvantages** | Model tiering means multiple prompts, multiple eval suites, and a confidence-threshold surface to tune. Prompt caching couples prompt structure to cost, so a "harmless" prompt refactor can double the bill silently. Batching adds a latency contract to explain to users. |
| **Alternatives** | Single-model simplicity (rejected: 3.2× the cost). Self-hosted open-weight models (deferred: the arithmetic only works above roughly Tier 4 volumes, and the accuracy bar for accounting extraction is high; revisit at Tier 4 with a measured accuracy comparison, not a cost one). Dedicated vector database (rejected above; the RLS argument is decisive). |
| **Risks** | **A silent caching regression is the highest-frequency risk in this document** — a reordered chart-of-accounts serialisation or an injected timestamp doubles the bill with no error and no alert. Mitigate with a `cache_read_input_tokens` ratio alert, not with review discipline. Second: usage growth outrunning the spend cap. Third: pricing changes — every dollar figure here should be re-derived, not trusted, before any commercial commitment. |
| **Effort** | Model tiering + escalation policy: **13**. Prompt-cache architecture + prefix construction + hit-rate assertions: **8**. Batch pipeline: **8**. Per-tenant budget enforcement: **5**. pgvector schema + per-company partitioning: **8**. |
| **Confidence** | **High** on the shape (caching > tiering > batching, in that order of value, for QAYD's prompt structure). **High** on pgvector-stays, because the tenant-scoped retrieval argument is structural. **Medium** on the absolute dollar figures — token counts per call are estimates and provider pricing moves. **Low** on the flat-usage assumption; treat it as the thing most likely to be wrong. |

---

## F. Search

**Rationale.** QAYD's searches are things like *"invoice 4471"*, *"anything from Al-Ghanim"*, *"the entry
where I wrote 'reversal of Q2 accrual'"*. Every one of them is scoped to one company. That single fact
determines the whole answer.

**Volume per tenant, at Tier 3:**
```
searchable rows for a mature tenant (entries + lines + documents + partners)
  ≈ 800 entries/mo × 12 × 5 years × (1 header + 4.2 lines)  ≈ 250,000 rows
  + documents 300/mo × 12 × 5                                ≈  18,000
                                                            ≈ 270,000 searchable rows
```

A GIN trigram index over 270,000 rows, with the `company_id` predicate already applied by RLS, is a
sub-10 ms query. **Postgres full-text search and `pg_trgm` are sufficient for QAYD's tenant-facing search
essentially forever.**

**Index design notes that matter:**
- `pg_trgm` GIN on the free-text columns that users actually search: `journal_entries.reference`,
  `journal_entries.description`, `ledger_entries.description`, `ledger_entries.reference`, partner names,
  `accounts.name`. That is a handful of columns, not "everything".
- Because RLS injects `company_id = app_current_company_id()`, the plan is a bitmap AND of the trigram GIN
  and the `company_id` btree. That works. For whale tenants, consider `btree_gin` to build a genuinely
  composite `(company_id, trigram)` index rather than relying on the bitmap AND.
- If `unaccent` is used in an index expression it **must be wrapped in an `IMMUTABLE` function**, or the
  index is invalid. Odoo warns about exactly this at boot; QAYD should make it a migration-time assertion,
  not a log line.

**The cautionary example.** Odoo's own audit-log search issues a four-way `OR` of `LIKE` predicates against
`old_value_char` / `old_value_text` / `new_value_char` / `new_value_text` with **no trigram or GIN index
declared** — a sequential scan of a very large table on every search. The lesson is not "use Elasticsearch";
it is "index the columns you search."

### When a dedicated engine is justified — and the honest answer

**Almost never, and here is the decisive reason:**

> **An external search index is a copy of tenant data outside the RLS boundary.**

Every leak mode QAYD has designed away — a forgotten endpoint, a raw SQL path, a queue job with the wrong
tenant context, a BI connector — reappears in the external index, where there is no `FORCE ROW LEVEL
SECURITY`, no `NOBYPASSRLS` role, and no CI catalog check. It also introduces a second consistency problem
(the index lags the ledger) for data users make decisions on.

The three conditions that would justify it:

| Condition | QAYD's exposure |
|---|---|
| Cross-tenant search is a product requirement | It is a tenancy violation. Reject on those grounds, not on performance grounds. |
| Per-tenant corpora > 10M documents with sub-50 ms typo-tolerant faceted search | Not reachable at these document volumes (a mature tenant has ~18,000 documents after five years) |
| Ranking sophistication beyond `ts_rank` / trigram similarity is a differentiator | Possible in a far-future "search your whole financial history" product; would be a new product decision, not a scaling one |

The one legitimate future use is **internal cross-tenant product analytics** — churn signals, feature
usage, support search. That is a **different dataset**, it should never live in the tenant database
anyway, and it is fed by the outbox. Do not conflate it with tenant-facing search.

| | |
|---|---|
| **Advantages** | Zero new infrastructure. Search results are transactionally consistent with the ledger. RLS covers search for free. One backup, one restore. |
| **Disadvantages** | No typo tolerance beyond trigram similarity, no faceting, no learned ranking. GIN indexes are write-amplifying — measure the posting-path cost before indexing a hot column. |
| **Alternatives** | OpenSearch / Meilisearch / Typesense (rejected: the RLS-boundary argument, plus a second consistency model, plus a second ops story, to solve a problem that does not exist at these volumes). Postgres FTS `tsvector` columns with a GIN index alongside trigram — worth adding for long-form description search where phrase matching matters. |
| **Risks** | Adding GIN indexes to hot write-path tables without measuring the write amplification. Discovering the `unaccent` immutability problem in production rather than in a migration. |
| **Effort** | Trigram + FTS indexes on the right columns, with write-path measurement: **5**. |
| **Confidence** | **High.** The tenant-scoped argument is structural, not a bet on volumes staying small. |

---

## G. Reporting

**Rationale.** A tenant-facing financial statement must be **correct**, not *fast-and-eventually-correct*.
That constraint, not the query volume, is what shapes this section.

### The ladder

| Stage | Mechanism | When | Effort |
|---|---|---|---|
| 1 | Live query over `ledger_entries` via `idx_ledger_account_date` | Now | 0 |
| 2 | Covering index → index-only scan | p95 TB > 200 ms | 2 |
| 3 | `account_period_balances` rollup as the serving path | p95 TB > 500 ms, or max tenant-year rows > 1M | 8 |
| 4 | **Report snapshots** — immutable, as-of a ledger head hash | When statements are published or filed (needed for compliance regardless) | 8 |
| 5 | Read replica for reporting | Reporting reads evicting hot pages on the primary (~Tier 3) | 5 |
| 6 | Separate OLAP store | **Almost certainly never** — see below | 34+ |

### The correctness problem with reporting replicas

A read replica introduces **replication lag into a number a user may act on**. A user posts an invoice,
navigates to the trial balance, and sees a figure that does not include it. In a social app that is a
refresh. In an accounting system it is a support ticket at best and a wrong decision at worst.

Three acceptable resolutions, in order of preference:

1. **Serve statements from the primary; serve exploration and analytics from the replica.** Simplest, and
   the statement path is low-QPS precisely because it is served from a rollup.
2. **Read-your-writes pinning.** Record the LSN of the user's last write in their session; the replica
   read waits for that LSN before answering. Correct and invisible to the user; costs a small latency tail.
3. **Display the replica's as-of timestamp** on the report. Honest, but pushes the problem onto the user.

**What is not acceptable:** serving a statement from a replica with no lag guarantee and no as-of display.
That is the "authoritative number from a cache" failure wearing a different hat.

### Snapshots

A **published** or **filed** statement must be immutable — a VAT return, a signed balance sheet, a
statement given to a bank. Store it as a snapshot carrying:

```
report_snapshots (
  company_id, report_type, period_id,
  generated_at, generated_by,
  ledger_head_id, ledger_head_hash,   ← ties the statement to a ledger state
  payload JSONB,                       ← the rendered figures
  ...
)
```

`ledger_head_hash` is the killer feature: **"this P&L was generated against ledger head X"** is verifiable
years later, and if the chain still verifies to X, the statement provably reflects the books as they stood.
This also protects against the M11 hazard — reclassifying an account between cash-flow buckets silently
reshapes an already-published statement — because the snapshot is frozen and the reclassification is
event-sourced and blocked for covered periods.

### Why a separate OLAP store is almost certainly wrong

| Reason | Detail |
|---|---|
| It is not an OLAP workload | The query is `SUM(signed_base_amount) GROUP BY account, period` over a tenant-scoped, indexed, **already pre-aggregated** table returning ~60 rows. Columnar storage solves a problem QAYD does not have. |
| A second system of record | A columnar copy of the ledger sits outside RLS, outside the append-only trigger, outside PITR alignment |
| Statements must tie to the fils | An ETL'd copy introduces a reconciliation burden between two representations of the same money — the exact class of problem `01` P19 exists to prevent |
| It does not fix the actual bottleneck | Which at Tier 3 is buffer-pool contention, fixed by a replica plus a rollup |

The legitimate case is **internal, cross-tenant product analytics** — cohort retention, feature adoption,
cost-to-serve. That dataset is different, should never be in the tenant database, and is fed by the outbox.

| | |
|---|---|
| **Advantages** | Report latency independent of tenant size from stage 3 onward. Published statements are immutable and cryptographically tied to a ledger state — a compliance property, obtained as a side effect of the hash chain. |
| **Disadvantages** | The LSN-pinning path adds a latency tail and a session concept. Snapshots are another growing store with their own retention question. |
| **Alternatives** | Materialised views (rejected in §C). Serving everything from the primary forever (works to Tier 3; fails on buffer-pool contention). |
| **Risks** | Someone routing statement reads to the replica for latency without the LSN rule. Make it structurally hard: separate connection names (`reporting` vs `analytics`) with different guarantees documented at the resolver. |
| **Effort** | Replica + routing + LSN pinning: **8**. Snapshots: **8**. |
| **Confidence** | **High** on the ladder and on rejecting OLAP. **Medium** on where exactly stage 5 becomes necessary — it depends on report mix more than on customer count. |

---

## H. Storage — documents, retention, legal hold

**Rationale.** Documents are the one dataset that grows independently of ledger volume and that carries a
statutory retention obligation. They belong in object storage, never in the database.

**Volumes:**
```
docs/customer/month × avg size × 12 × customers
T1:    300 × 250 KB × 12 ×     100 =    90 GB/year
T2:                          1,000 =   900 GB/year
T3:                         10,000 =     9 TB/year
T4:                        100,000 =    90 TB/year
T5:                      1,000,000 =   900 TB/year  (multi-PB cumulative)
```

**Architecture (unchanged at every tier):**
- Bytes in object storage (Supabase Storage / S3-compatible). The database stores metadata only: path,
  content hash, size, mimetype, `company_id`, `retention_until`, and the linkage to the journal entry.
- Access exclusively by **short-lived signed URL** issued by the Laravel File Service after a permission
  check. No public buckets, ever.
- **Content-hash deduplication.** The same invoice PDF emailed to three people is one object. Estimated
  saving 15–30% at Tier 3+ (**a guess** — measure it before budgeting on it).
- **Direct-to-storage uploads** with a pre-signed URL, so the API tier never buffers file bytes. This is
  also what keeps the API stateless (§I).

### Retention

Accounting records in GCC jurisdictions carry statutory retention obligations — commonly in the range of
**five to ten years** depending on the jurisdiction, the entity type, and whether tax records are treated
separately from commercial books.

> ⚠️ **Do not encode a specific number from this document.** Retention periods must be confirmed with
> counsel per jurisdiction (Kuwait, Saudi Arabia, UAE, Qatar, Bahrain, Oman each differ, and free-zone
> regimes such as DIFC/ADGM differ again). The architectural requirement is that the number is **data**,
> not a constant.

Model it as:

```
jurisdiction_retention_policies (jurisdiction, record_class, retention_years, source_reference)
documents.retention_until  = computed at write time from the fiscal year + the tenant's jurisdiction
```

Lifecycle, driven by `retention_until` and age:

```
0–12 months   standard class      ~$0.023/GB/mo
12–36 months  infrequent access   ~$0.0125/GB/mo
36 months+    archive             ~$0.004/GB/mo
past retention_until AND no legal hold  → eligible for deletion
```

At Tier 3 (~30 TB cumulative): $690/month all-standard, roughly **$300/month with lifecycle** applied.
At Tier 4 (~300 TB): **$3–7 K/month** depending on the mix.

**The sleeper cost is egress, not storage.** Signed-URL downloads at ~$0.09/GB: 300 TB stored with 5%
downloaded monthly is 15 TB × $0.09 = **$1,350/month** — comparable to the storage bill itself. Put a CDN
in front of document delivery, and alert when monthly egress exceeds 10% of stored bytes.

### Legal hold

```sql
legal_holds (
  id, company_id, scope,          -- 'company' | 'period' | 'entry' | 'document'
  scope_ref, reason NOT NULL,
  placed_by, placed_at, released_at,
  ...
)
```

Rules:
- Any deletion path — user-initiated, lifecycle-expiry, or tenant offboarding — **must** check for an
  active hold covering the target. Enforce in the database (a trigger on the delete path), not only in the
  lifecycle job, because the lifecycle job is exactly the code path that will forget.
- A hold has a `reason NOT NULL` and an actor. An unattributed hold is indistinguishable from a bug.
- **Object Lock / WORM is applied specifically to the hash-chain anchor objects** (§J), not to the whole
  bucket. Anchors must be immutable; ordinary documents must be deletable when retention expires, or
  QAYD becomes non-compliant in the other direction.

| | |
|---|---|
| **Advantages** | Storage cost stays a rounding error next to AI at every tier. The database stays small and fast. Retention is data, so a new jurisdiction is an INSERT. Legal hold is enforced where deletion happens. |
| **Disadvantages** | Signed-URL issuance is a permission-check hot path. Lifecycle transitions are asynchronous and eventually consistent — a document may briefly be in the "wrong" class. Dedup complicates deletion (reference counting). |
| **Alternatives** | Bytes in Postgres `bytea` / large objects (rejected: destroys backup and restore times for zero benefit). Per-tenant buckets (rejected below Tier 4: bucket-count limits and per-bucket policy management; reconsider for dedicated-shard enterprise tenants where it aligns with their isolation requirement). |
| **Risks** | Egress cost surprise (alert on it). A lifecycle policy that deletes past-retention documents that a hold should have protected — this is a legal event, not an incident. Test the hold path with the same rigour as the tenancy path. |
| **Effort** | Retention policy table + computed `retention_until` + lifecycle: **8**. Legal hold + DB-enforced deletion guard: **8**. CDN + egress alerting: **3**. |
| **Confidence** | **High** on architecture. **Low** on specific retention periods — flagged above; that is a legal input, not an engineering one. **Medium** on the dedup saving estimate. |

---

## I. Background workers, scheduling, and leader election

**Rationale.** Scheduled work (FX revaluation, period-close checks, anchor generation, lifecycle
transitions, drift checks) must run exactly once per period per tenant, must make progress independently
per tenant, and must never leak tenant context between jobs.

### Scheduling

| Tier | Mechanism |
|---|---|
| T1–T2 | Laravel scheduler with `onOneServer()` (a cache lock). Adequate and free. |
| T3+ | **Fan out per tenant.** A scheduled job enqueues one work item per company; workers claim items with `SKIP LOCKED`. One tenant's failure stalls one tenant, not the run. |

The fan-out claiming pattern:

```sql
UPDATE scheduled_tenant_runs
   SET claimed_by = :worker, claimed_at = now()
 WHERE id IN (
   SELECT id FROM scheduled_tenant_runs
    WHERE run_key = :run_key AND completed_at IS NULL AND claimed_at IS NULL
    ORDER BY id
      FOR UPDATE SKIP LOCKED
    LIMIT 20
 )
RETURNING id, company_id;
```

Plus a reclaim path for items whose `claimed_at` is older than a lease window (a worker died mid-job) —
which is why the job body must be idempotent (§D).

### Leader election

**Do not build Raft. Do not add etcd or ZooKeeper.** The rationale is availability arithmetic: PostgreSQL
is already the floor of QAYD's availability — if it is down, nothing works. Adding a second consensus
system means adding a component whose *own* failure modes can take the system down while the database is
healthy. That is strictly worse than deriving leadership from the thing you already depend on.

Two acceptable mechanisms:

```sql
-- (1) Lease row — preferred; visible, debuggable, has an owner and an expiry
UPDATE leader_leases
   SET holder = :instance, expires_at = now() + interval '30 seconds'
 WHERE name = :name AND (holder = :instance OR expires_at < now())
RETURNING holder;

-- (2) Transaction-scoped advisory lock — for work that fits in one transaction
SELECT pg_try_advisory_xact_lock(:key);
```

> ⚠️ **Never use session-scoped `pg_advisory_lock`.** It is released on session end, and under transaction
> pooling the "session" is not what you think it is — the connection returns to the pool while your code
> believes it still holds the lock. `pg_advisory_xact_lock` is transaction-scoped and therefore
> pooler-safe. This is the same root cause as the GUC hazard in §J.

### The GUC hazard applies to workers too — and applies *first*

A queue worker holds a long-lived connection and processes jobs for many different tenants on it. A
session-scoped `SET app.current_company_id` in a worker would leak tenant context **between jobs**, with
no pooler anywhere in the picture.

*Verified 2026-07-28:* this is **not** a live defect — QAYD has **no queue jobs yet** (no `app/Jobs`
directory), and the HTTP middleware already uses the correct transaction-local `set_config(..., true)`
form (`ResolveTenantCompany.php:85-90`). It is a **prospective** risk, and precisely because the first
job does not exist yet, the helper below should land *before* it does. The failure mode if it does not:
a silent cross-tenant leak that fails open with plausible data, invisible to single-connection tests.

**Every job must:**
1. open an explicit transaction,
2. `SET LOCAL` the tenant GUCs as the first statement in it,
3. do its work,
4. commit or roll back — which discards the GUCs by definition.

Same rule for scheduled commands and console commands. The connection wrapper in §J must make the
non-transactional path unreachable, in workers as much as in HTTP.

| | |
|---|---|
| **Advantages** | Per-tenant progress isolation. Leadership derived from the database QAYD already depends on, adding no new failure mode. `SKIP LOCKED` claiming is simple, correct, and needs no coordinator. |
| **Disadvantages** | Lease-based leadership means a brief leaderless window on failover (bounded by the lease duration). Fan-out creates one row per tenant per run — at Tier 4 that is 100,000 rows per scheduled run, needing its own retention. |
| **Alternatives** | Odoo's `ir.cron` jobs-as-data with `SKIP LOCKED` (a sound pattern; adopt the claiming mechanism, skip the jobs-as-data model until tenant-configurable schedules become a product requirement). Kubernetes leader election via lease objects (viable at Tier 4+ when the fleet is already on Kubernetes; still not worth a new dependency before that). |
| **Risks** | A job that reads tenant tables outside a transaction — silent cross-tenant read. This is the single highest-severity risk in the worker layer and it must be closed by a mechanism, not a convention. |
| **Effort** | Per-tenant fan-out + claiming + reclaim: **8**. Lease-based leadership: **3**. |
| **Confidence** | **High.** |

---

## J. Horizontal scaling and connection pooling

> **This section is a data-leak analysis that happens to be filed under performance.**
> PgBouncer transaction mode is not a throughput topic for QAYD. It is the mechanism most likely to
> produce a silent cross-tenant breach, and it must be treated accordingly.

### Stateless API — the preconditions for scaling out at all

| Requirement | Why | Status |
|---|---|---|
| No session state in process memory | Any container can serve any request | Sanctum cookies → DB/Redis session store |
| No local filesystem for uploads | Containers are ephemeral | Direct-to-object-storage pre-signed uploads (§H) |
| No in-process cache of **tenant** data | A cached tenant row in a container is a tenancy boundary with no RLS | Only immutable global catalogues may be cached in-process |
| No sticky sessions | Load balancing must be free to move a user | Bearer/cookie auth, no server affinity |
| Graceful shutdown draining in-flight jobs | Rolling deploys must not lose work | Worker `SIGTERM` handling |

### Connection arithmetic

```
T2:  6 app containers × 32 PHP-FPM children  = 192
   + 3 worker containers × 4                 =  12
   + scheduler, console, humans              =  ~8
                                             = ~212 potential connections
Postgres at 64 GB, ~5–10 MB per backend      → max_connections realistically 200–400
```

At Tier 2 you are at the wall. A pooler is required. Choices: **PgBouncer**, **Supavisor** (the natural
fit given Supabase-managed Postgres), **pgcat**, or a cloud-provider proxy. All have the same hazard.

### The hazard, precisely

```
Request A (tenant 7)                    Request B (tenant 12)
─────────────────────                   ─────────────────────
BEGIN
SET app.current_company_id = 7   ← plain SET, session-scoped
SELECT ... (RLS filters to 7)  ✓
COMMIT
                                        ← PgBouncer returns the SAME backend
                                          connection to the pool, then hands
                                          it to Request B
                                        BEGIN
                                        SELECT ... FROM ledger_entries
                                          → app.current_company_id is STILL 7
                                          → RLS filters correctly... to tenant 7
                                        ✓ HTTP 200. Tenant 12 sees tenant 7's books.
```

Three properties make this the worst kind of bug:

1. **It fails open.** QAYD's design fails *closed* on a **missing** GUC (zero rows). A **stale** GUC fails
   **open**, with plausible, correct-looking data.
2. **It is invisible in testing.** A direct (unpooled) connection passes every test. Only a real pooler in
   transaction mode with an undersized pool and concurrent tenants reproduces it.
3. **`DISCARD ALL` / `server_reset_query` does not save you.** In transaction mode the reset query runs on
   *session-pool release*, not after every transaction — it is not the unit of reuse.

### Mandatory mechanisms

1. **`SET LOCAL`, always, inside an explicit transaction.** Never a bare `SET`. `SET LOCAL` is discarded at
   `COMMIT`/`ROLLBACK` — which is exactly the pooler's unit of reuse in transaction mode.
2. **A connection wrapper that makes the non-transactional path unreachable.** Every query on the tenant
   connection runs inside a transaction that has already issued the `SET LOCAL`s. Not a convention — a
   type. If a code path can reach the tenant connection outside such a transaction, the mechanism is not
   in place. `SET LOCAL` outside a transaction is a **no-op with a warning**, which would fail closed but
   silently break every query — so the tests must catch that too.
3. **Extend the discipline to every entry point**, not just HTTP: queue jobs, scheduled commands, console
   commands, data-fix scripts, the AI relay.
4. **`PgBouncerSafetyTest`, run in CI against a real PgBouncer in transaction mode with a deliberately
   undersized pool:**
   - assert `current_setting('app.current_company_id', true) IS NULL` immediately after a commit on the
     same connection;
   - assert a second request on a reused connection sees no leftover context;
   - assert a tenant-table `SELECT` with no context returns **zero rows**;
   - run concurrent requests for different tenants against a pool of size 1 or 2 and assert no
     cross-contamination.
5. **A cheap defensive assertion** once per transaction: the resolved company id from the request must
   equal `current_setting('app.current_company_id')`. Costs one round trip's worth of nothing; catches the
   whole class.
6. **Order of operations is not negotiable:** audit → wrapper → test green → *then* enable the pooler. If
   the audit finds a session-scoped `SET`, that is a **live bug today**, without PgBouncer, because queue
   workers have the same exposure.

### Secondary pooler consequences worth knowing before the rollout

| Thing | Under transaction pooling |
|---|---|
| Session-scoped advisory locks (`pg_advisory_lock`) | **Broken.** Use `pg_advisory_xact_lock` or a lease row (§I) |
| `LISTEN` / `NOTIFY` | **Broken.** Do not build on it — the outbox already covers the need |
| Server-side prepared statements | Historically broken; PgBouncer ≥ 1.21 supports them via `max_prepared_statements`. Check PDO's `ATTR_EMULATE_PREPARES` setting against the pooler's configuration before rollout |
| `SET SESSION` of any kind (timezone, `search_path`, roles) | Leaks across tenants exactly like the GUC. Audit for all of them, not just `app.current_*` |
| Temporary tables | Do not survive the transaction; anything relying on them breaks |
| `FOR UPDATE` row locks | **Safe** — transaction-scoped by definition. QAYD's `JournalNumberAllocator` is unaffected |

| | |
|---|---|
| **Advantages** | Removes the connection ceiling, which is the actual Tier-2 wall. Forces transaction discipline that is correct independently of pooling. The wrapper closes the worker-context leak that exists today. |
| **Disadvantages** | A whole category of session-scoped Postgres features becomes unavailable. One more component in the request path with its own failure and monitoring story. |
| **Alternatives** | Session-mode pooling (no GUC hazard, but far less connection multiplexing — defeats the purpose). Fewer, fatter app containers (delays the wall by maybe one tier). Supavisor (same hazard, better integration with the managed Postgres — evaluate on operations, not on safety, because the safety work is identical). |
| **Risks** | Enabling the pooler before the audit. Treating this as a performance ticket and giving it performance-level review. **The correct framing for planning: this is a security item with a performance side effect.** |
| **Effort** | Audit + wrapper + `PgBouncerSafetyTest` + entry-point sweep: **8**. Pooler deployment: **3**. |
| **Confidence** | **Very high** on the hazard — this is the standard way RLS multi-tenancy is defeated in production, and it was reached independently by two workstreams in the Odoo study. **High** on the mitigations. |

---

## K. Multi-region

### Data residency — the GCC regulatory reality

> ⚠️ **This subsection describes the *shape* of the requirement, not the requirement itself.**
> Every GCC jurisdiction has its own data-protection instrument, and financial-sector rules frequently
> add localisation obligations beyond the general regime. Free zones (DIFC, ADGM) operate distinct
> frameworks. **Confirm with counsel per jurisdiction and per customer segment before making any
> commercial commitment or architectural bet.**

The shape that is safe to design against:

- Some customers — particularly regulated financial entities and government-adjacent bodies — will require
  their data to remain in a named jurisdiction.
- Cross-border transfer of personal data is restricted in a way that varies by jurisdiction, and the
  restriction applies to *processing*, not only to storage — so a read replica in another region can be as
  much of a problem as a primary there.
- Retention obligations differ by jurisdiction (§H).

**The architectural conclusion is simple and cheap:**

> **Data residency is a tenant attribute, not a deployment attribute.**

Add `companies.data_region` at **Tier 3**, while there is exactly one region and the column is a constant.
Retrofitting a region attribute onto a live tenant base later means a migration on every table plus a
re-derivation of every routing decision. Adding it now is one column and one default.

### Latency — one GCC region is enough through Tier 4

Approximate round-trip times from Kuwait (**verify with real measurements; these are order-of-magnitude**):

| To | RTT |
|---|---|
| Bahrain (`me-south-1`) | ~10–20 ms |
| UAE (`me-central-1`) | ~15–25 ms |
| Frankfurt / Ireland | ~90–110 ms |
| US East | ~180–220 ms |

A single GCC region serves **every GCC tenant under ~30 ms**. Since the entire Tier-4 addressable market is
GCC (§Tier 5, "the reality check"), **multi-region is a residency and DR requirement, never a latency
requirement, until QAYD leaves the region.** That is a useful thing to know, because it means multi-region
can be deferred until there is a specific customer or jurisdiction demanding it.

### Why active-active is almost certainly wrong for a ledger

Four independent arguments, any one of which is sufficient:

1. **Gapless numbering requires a single serialisation point.** QAYD allocates journal numbers gaplessly
   per `(company, fiscal_year, entry_type)` via an atomic upsert-increment on a sequence row. Multi-master
   gives you two options, both bad: distributed consensus per allocation (worse latency than a single
   region, plus a new failure mode on the hottest write path), or per-region number ranges (gaps — which
   the GCC audit posture is specifically designed to avoid).

2. **The hash chain requires a total order per company.** Two regions accepting writes produce two chains.
   There is no merge. The tamper-evidence property — the thing that makes a restore provable (§L) — is
   destroyed by the first concurrent write.

3. **Double-entry has no merge function.** Last-write-wins is undefined for a ledger. If two regions each
   post a different entry as number 4471, neither "wins" — both are real financial events, and the
   reconciliation is a human accounting exercise, not a conflict-resolution policy.

4. **The invariants are transactional.** Balance-in-both-currencies, the period lock, the balanced-entry
   CHECK, and the reconciliation `SUM(matched) ≤ original` constraint are all enforced inside one
   PostgreSQL transaction. Multi-master means either global transactions (unacceptable latency on the
   posting path) or accepting that an invariant can be violated and repaired after the fact — which is
   exactly the class of design `01_ENGINEERING_PRINCIPLES` P1 and P2 exist to forbid.

### What is right instead

```
Per tenant:  ONE writer, in ONE region, always.
             ├── read replicas in-region for load
             ├── read replicas cross-region ONLY where residency permits,
             │   and only for latency — never as a failover target that
             │   could accept writes
             └── async cross-region standby for DR, with:
                   • an explicit, human-triggered failover
                   • a stated, measured RPO
                   • a documented split-brain prevention procedure
                     (fence the old primary before promoting)
```

Cross-region failover for a ledger is a **decision**, not an automation. An automated failover that
promotes a lagging standby while the old primary is still reachable produces two divergent ledgers — the
one failure mode from which an accounting system genuinely may not recover.

| | |
|---|---|
| **Advantages** | Residency becomes a routing decision on a column. Single-writer-per-tenant preserves every ledger invariant unchanged. Regions are independent blast radii on top of shards. |
| **Disadvantages** | Cross-region DR means a real RPO gap (async replication). A tenant cannot be served from a region other than its own, so a badly-chosen region at signup is a migration. Per-region control planes duplicate operational surface. |
| **Alternatives** | Active-active (rejected, four arguments above). Single global region (correct through Tier 4; fails the first residency requirement). Region-per-jurisdiction from day one (rejected: pays the full multi-region cost years before any customer requires it). |
| **Risks** | Making `data_region` mutable, then discovering that moving a tenant between regions is a legal event with a technical component rather than the reverse. Automated cross-region failover (see above). |
| **Effort** | `companies.data_region` + routing at Tier 3: **3**. Full second region with control plane: **34**. |
| **Confidence** | **Very high** on rejecting active-active for a ledger. **High** on residency-as-tenant-attribute. **Low** on the specific regulatory requirements — flagged; that is a legal input. |

---

## L. Disaster recovery — and the ledger's unique advantage

### RPO / RTO targets by tier

| Tier | RPO | RTO | Mechanism |
|---|---|---|---|
| T1 | 5 min | 4 h | Continuous WAL archiving + PITR; manual restore |
| T2 | 1 min | 2 h | WAL archiving + a warm standby |
| T3 | 30 s | 30 min | Streaming standby + automated promotion runbook |
| T4 | 10 s | **15 min per shard** | Per-shard standby; blast radius = one shard |
| T5 | 10 s | 15 min per shard | Same, plus cross-region standby per region |

The Tier-4 line is the important one: **RTO is stated per shard, not for "the database."** That is the
whole point of sharding at that tier — an incident degrades ~6,000 tenants for 15 minutes rather than
100,000 tenants for a day.

### Backup verification

> **A backup you have not restored is a hypothesis.**

Weekly, automatically, into a scratch environment:

```
1. Restore the most recent full backup + WAL to a random point in the last 7 days
2. Run VerifyLedgerIntegrityAction    → hash chain verifies from genesis to head
3. Run RebuildPeriodBalancesAction    → rebuild into a shadow table
4. Compare shadow vs restored account_period_balances → must be identical
5. Run VerifyNumberSequenceAction     → no gaps, no duplicates, per scope
6. Record the WALL-CLOCK TIME of steps 1–5
7. Publish that time as the measured RTO
```

Step 6 is the one people skip and the one that matters. It converts *"we have backups"* into *"our RTO is
47 minutes, measured last Tuesday"* — which is a number you can put in a contract and an alert you can
set. When the measured time exceeds the stated RTO, **that is a stop-feature-work event**, per the
decision table.

### The ledger's unique advantage — reconstructible *and* provably intact

Most systems can restore data. Very few can **prove the restored data is complete and unaltered.** QAYD
can, because of two decisions already made.

**1. The ledger is a projection, so most of the database is derived.**

```
IRREPLACEABLE (must be protected at the highest tier):
  journal_entries          the financial events themselves
  journal_lines            their detail
  audit_logs               who did what, when — append-only, hash-chained
  companies / accounts / fiscal_years / config   the tenant's world
  documents (object storage)  the evidence

DERIVED — rebuildable from the above by a named Action:
  ledger_entries               ← RebuildLedgerAction
  account_period_balances      ← RebuildPeriodBalancesAction
  reconciliation groups        ← RebuildReconciliationGroupsAction
  report snapshots (regenerable, though the originals are evidence)
  search indexes, embeddings   ← re-derivable at a token cost
```

At Tier 4 that means the irreplaceable set is roughly **journal tables + audit + documents** — a fraction
of the ~20 TB. The derived tables can be excluded from the fastest-recovery path entirely and rebuilt
after service is restored. **That materially shortens the critical restore, and most systems cannot make
this split because they cannot distinguish source from projection.**

**2. The hash chain plus external anchors make completeness verifiable.**

```
ledger_entries: append-only, hash-chained, every amount column covered
                (not Odoo's allowlist, which omits amount_currency,
                 currency_id, date_maturity, and analytic distribution)

daily:  chain_anchors (company_id, as_of, head_seq, head_hash,
                       signature, key_id)
        signature = Sign_KMS(head_hash ‖ as_of ‖ company_id)
        asymmetric key in KMS/HSM — NEVER in the database
        anchor object written to storage with Object Lock (WORM)
```

Three properties fall out, and the third is the one nobody else has:

| Property | How |
|---|---|
| **Tamper-evidence** | Any mutation of a historical row breaks the chain from that row forward |
| **Independent verification** | The chain itself is unkeyed SHA-256, so any auditor can verify it without QAYD's cooperation |
| **Provable restore completeness** | Restore → verify chain from genesis → compare the restored head hash against the last externally-signed anchor. **If they match, the restored ledger is provably identical to the ledger that existed at anchor time.** |

That third property closes a failure mode most systems cannot even detect: **a restore that silently lost
the tail.** Restore to a point before the last anchor and the restored head is *behind* the anchor head —
detected immediately, mechanically, without anyone noticing that "the last few days look thin." A system
without an external anchor has no way to know that its restore is short.

The anchor must be **externally signed** for this to hold. Odoo's chain is unkeyed with an empty-string
genesis, so a full-chain rewrite is undetectable — the chain proves internal consistency, not authenticity.
QAYD keeps the chain unkeyed (for independent verification) and signs the *anchor* (for authenticity). Both
properties, no conflict.

### Recovery runbook shape

```
1.  Fence the failed primary (prevent split brain) — before anything else
2.  Promote the standby for the affected shard(s) only
3.  Verify the hash chain to head; compare against the last signed anchor
        ├─ match      → the ledger is provably intact; proceed
        └─ head short → data loss between anchor and failure, QUANTIFIED
                        (which entries, which companies) — notify affected
                        tenants with specifics, not with an apology
4.  Rebuild derived tables (ledger projection if needed, period balances,
    reconciliation groups) and run drift checks
5.  Restore service, read-only first, then writes
6.  Reconcile the gap: replay from the outbox and from source documents
7.  Post-incident: publish the measured RTO and RPO actuals against targets
```

Step 3's branch is the payoff. **"We lost 47 entries across 3 companies between 14:02 and 14:09, here they
are"** is a recoverable customer conversation. *"We had an incident and are not sure what was affected"*
is not.

| | |
|---|---|
| **Advantages** | Restore is provable, not hopeful. The critical restore set is a fraction of the database because most of it is derived. Data loss is *quantified* rather than estimated. The chain is independently auditable — a genuine commercial differentiator in a regulated market. Per-shard RTO at Tier 4+. |
| **Disadvantages** | Anchoring is a daily job that must not fail silently (a missing anchor is an incident). Rebuilding derived tables lengthens full recovery even as it shortens the critical path. KMS key custody and rotation become a real operational responsibility. |
| **Alternatives** | Backups without verification (rejected: an unverified backup is a hypothesis). Chain without external anchors (rejected: proves consistency, not authenticity — a full rewrite is undetectable). Synchronous replication for RPO 0 (viable at Tier 3+ for the write path; costs commit latency on the posting path — evaluate against the actual RPO requirement, which for an accounting system may well justify it). |
| **Risks** | Anchor generation failing silently for weeks — alert on *absence*, not on error. Treating the derived/irreplaceable split as documentation rather than as an actual backup policy. Rebuilders that have never been run at production scale, which are then run for the first time during an incident — **run them monthly**. |
| **Effort** | Chain activation + anchors + verification Action: **21** (TD-06 / H5). Restore-test automation: **8**. Per-shard DR runbook: **8**. |
| **Confidence** | **Very high** on the reconstructible-and-provable argument — it follows from append-only + hash chain, both already decided. **Medium** on the RPO/RTO numbers, which depend on infrastructure choices not yet made. **High** that the measured-RTO discipline is the highest-value item in this section. |

---

# Confidence register

Explicit separation of what this document is confident in from what it is guessing.

## Confident

| Claim | Basis |
|---|---|
| One PostgreSQL with RLS is correct through ~100,000 customers | Arithmetic is not close: 12,100 row-writes/s and 96,000 read q/s are within one large machine's reach |
| The Tier-4 sharding trigger is **blast radius**, not throughput | A 20 TB restore has no acceptable RTO; throughput could be bought with hardware |
| `account_period_balances` is safe **because** the ledger is append-only | Monotonic trigger — a structural property, not a hope |
| Date-range partitioning is right; company partitioning is wrong | 100,000 partitions breaks the planner; date pruning serves every financial report |
| Partitioning weakens `UNIQUE (journal_line_id)` | Documented PostgreSQL rule: unique constraints must include the partition key |
| Partitions need their own RLS + FORCE + policies | Documented PostgreSQL behaviour; direct-partition access bypasses parent policies |
| PgBouncer transaction mode + session-scoped `SET` = silent cross-tenant breach | The standard way RLS multi-tenancy fails; reached independently by two Odoo-study workstreams |
| Active-active is wrong for this ledger | Four independent arguments (gapless numbering, hash chain, no merge function, transactional invariants) |
| pgvector in-database is right for QAYD | Retrieval is always tenant-filtered; per-tenant corpora are ~50k vectors |
| A dedicated search engine is not justified | An external index is a copy of tenant data outside RLS |
| AI is 70–85% of infrastructure cost at every tier | Falls directly out of the per-call token arithmetic |
| Caching + tiering + batching is worth ~3.2× on AI cost | Computed above from published per-token pricing |
| Prompt caching is worth *more* than model tiering here | QAYD's prompts are dominated by a large stable per-company prefix — an unusual and valuable shape |
| An append-only hash-chained ledger with external anchors makes a restore **provable** | Follows from properties already decided |

## Guessing — argue with these first

| Guess | Why it matters | How to replace it |
|---|---|---|
| **ARPU = $100/month** | Every cost-as-%-of-revenue figure scales inversely | Actual pricing decision |
| **800 journal entries / customer / month** | Drives every row, byte, and QPS figure | Measure the first 20 real customers |
| **300 documents / customer / month at 250 KB** | Drives storage and AI extraction cost | Same |
| **800 AI calls / customer / month, flat across tiers** | **The single most load-bearing guess in the document.** 3× usage → AI is 42% of revenue | Instrument from day one; enforce a cap |
| Per-call token budgets (4,000/2,000/600 etc.) | Drive every AI dollar figure | `count_tokens` against real prompts |
| Model pricing | Providers change pricing | Re-derive before any commercial commitment |
| Peak factors (10× → 4×) | Drive every peak-QPS figure | Measure; month-end close will dominate |
| 2 audit rows per posted entry | ~23% of the per-line byte budget | Measure once the audit path is complete |
| Row-size estimates (410 B / 500 B / 300 B / 600 B) | Drive every DB-size figure | `pg_total_relation_size` / row count at Tier 1 |
| Whale = 50× median | Drives the rollup and shard-isolation triggers | Observe the actual tenant-size distribution |
| GCC addressable market ≈ 1M businesses | Determines that Tier 5 requires leaving the region | Commercial research |
| Retention periods (5–10 years) | Legal exposure in both directions | **Counsel, per jurisdiction** |
| RTT figures | Support the "one GCC region is enough" conclusion | Measure |
| 15–30% document dedup saving | Storage budget only — low consequence | Measure at Tier 2 |
| Infrastructure prices | Order-of-magnitude only | Quote at the time |

## Deliberately not decided here

- Whether to run Postgres managed (Supabase) or self-hosted at Tier 3+. Both work; the decision is
  operational (team size, on-call capability, cost) and should be an ADR when the question is live.
- Whether the modular monolith becomes services. That is a **team** decision, not a load decision, and
  this document has nothing useful to say about it.
- Self-hosted open-weight models for the highest-volume AI shapes. Revisit at Tier 4 with a measured
  accuracy comparison, not a cost one — the accuracy bar for accounting extraction is high and a
  cost-driven downgrade is exactly the kind of decision `01` P1 exists to resist.

---

# The four things to do before any of this matters

Everything above is conditional. These are not.

| # | Item | Effort | Why now |
|---|---|---|---|
| 1 | **`SET LOCAL` audit + connection wrapper + `PgBouncerSafetyTest`** | 8 | A live cross-tenant leak exists in queue workers **today**, before any pooler. Not a Tier-2 item. |
| 2 | **CI catalog-introspection RLS check, extended to partitions** | 5 | Converts a convention into a mechanism, and closes the partition gap before the first partition exists |
| 3 | **`account_period_balances` + rebuilder + drift check** | 8 | Costs 8 points now; costs 8 points plus a backfill on the largest table under load later |
| 4 | **Weekly automated restore-and-verify, with the wall-clock published as the measured RTO** | 8 | An unverified backup is a hypothesis, and at 100 customers you already hold 100 businesses' books |

**Total: 29 points.** Everything else in this document can wait for its trigger metric to fire.

---

*Every figure in this document is derived from QAYD's own stated assumptions and shown with its
arithmetic. Nothing here is justified by what another system does. Where a number is a guess it is marked
as one, and the confidence register above is the list of things to argue with first.*


