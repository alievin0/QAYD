# Database Performance Engineering — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_PERFORMANCE
---

# Purpose

This document is the binding performance engineering specification for the QAYD PostgreSQL
database layer. QAYD is an AI Financial Operating System: a single PostgreSQL 15+ cluster serves
the general ledger, sub-ledgers (AR/AP), inventory, payroll, tax, and reporting workloads for every
tenant company on the platform, behind a Laravel 12 (PHP 8.4+) backend and a FastAPI AI layer that
never writes to the database directly. Performance in this system is not a cosmetic concern — it is
a correctness-adjacent concern. A slow trial balance query blocks a CFO from closing a fiscal
period; a lock held too long on `journal_lines` during posting stalls every other invoice, bill, and
payroll run in the same company; an unindexed foreign key turns a routine `stock_movements` insert
into a full-table scan on `inventory_items`. This document exists so that every engineer, and every
AI agent proposing schema, query, or configuration changes, can reason precisely about the cost of
what they ship, verify that cost with `EXPLAIN (ANALYZE, BUFFERS)`, and hold the system to explicit,
measurable latency and throughput targets rather than a vague sense of "should be fast enough."

The audience is any backend engineer (human or AI) writing SQL, Eloquent queries, or Laravel
migrations against PostgreSQL 15+, any SRE tuning the cluster's `postgresql.conf`, and any reviewer
approving a pull request that touches a query path exercised at tenant scale. This document assumes
the platform facts fixed in the shared design context and the companion `DATABASE_STANDARDS.md` and
`DATABASE_ARCHITECTURE.md` documents: multi-tenancy via `company_id` on every business table,
soft deletes (`deleted_at`) on financial rows, `NUMERIC` money columns, double-entry accounting
semantics (`journal_entries` → `journal_lines`, `ledger_entries` as a derived projection), and the
canonical table set (`accounts`, `journal_entries`, `journal_lines`, `ledger_entries`, `customers`,
`vendors`, `invoices`, `bills`, `bank_accounts`, `inventory_items`, `stock_movements`,
`payroll_runs`, `tax_transactions`, `audit_logs`, and related tables).

Every claim in this document is backed by a runnable artifact: a `CREATE INDEX` statement, an
`EXPLAIN (ANALYZE, BUFFERS)` plan captured against representative data volumes, a `postgresql.conf`
snippet with the exact parameter and value, or a SQL query against `pg_stat_statements` /
`pg_stat_activity` / `pg_stat_user_tables`. Nothing here is aspirational. Where a target is not yet
met in production, the relevant section names the remediation and the owning team. The document is
organized into seventeen sections covering targets, indexing, query and plan optimization, caching,
connection pooling, vacuum/analyze, materialized views, monitoring, slow-query triage, concurrency,
scaling, benchmarking, anti-patterns, and worked examples.

# Performance Targets

QAYD defines performance targets at three layers: the end-user-facing API latency budget, the
database-query latency budget that feeds it, and the resource-utilization budget that keeps the
cluster in a safe operating envelope under multi-tenant load. Targets are expressed as p50/p95/p99
because financial dashboards are read by humans who tolerate an occasional slow load, but the tail
must never exceed the point where Laravel's default HTTP client timeout (30s) or the load balancer's
idle timeout (60s) fires.

**API-to-database latency budget** (measured at the Laravel `DB::listen()` query-log boundary, i.e.
excludes PHP/network overhead):

| Query class | p50 target | p95 target | p99 target | Notes |
|---|---|---|---|---|
| Single-row lookup by PK (`accounts.id`, `invoices.id`) | < 1 ms | < 3 ms | < 8 ms | Index-only scan expected |
| Filtered list, paginated (25 rows), indexed `company_id` + filter | < 5 ms | < 20 ms | < 50 ms | e.g. `GET /invoices?status=unpaid` |
| Journal posting transaction (INSERT journal_entries + N journal_lines + balance check) | < 15 ms | < 40 ms | < 100 ms | Wrapped in one DB transaction |
| Trial balance (one fiscal period, one company) | < 50 ms | < 150 ms | < 400 ms | Served from `ledger_entries` / materialized view |
| Full financial statement (P&L, Balance Sheet) | < 200 ms | < 600 ms | < 1500 ms | Aggregation over `ledger_entries` |
| AR/AP aging report | < 100 ms | < 300 ms | < 800 ms | Indexed on `customer_id`/`vendor_id` + due date |
| Ad-hoc reporting query (`report_runs`) | < 2 s | < 8 s | < 20 s | Routed to read replica; async if > 5s expected |
| Bulk import (per 1,000 rows: bill items, stock movements) | < 300 ms | < 900 ms | < 2 s | Batched `INSERT ... VALUES` multi-row |

**Cluster resource envelope** (steady state, per primary instance sized for ~5,000 active tenant
companies on a shared cluster tier; dedicated large tenants get isolated clusters per
`DATABASE_ARCHITECTURE.md`):

| Metric | Green | Yellow (investigate) | Red (page) |
|---|---|---|---|
| CPU utilization (5-min avg) | < 60% | 60–85% | > 85% |
| Connections in use (of `max_connections`) | < 50% | 50–80% | > 80% |
| Replication lag (streaming replica) | < 100 ms | 100 ms–2 s | > 2 s |
| `pg_stat_activity` rows in `idle in transaction` > 5 min | 0 | 1–5 | > 5 |
| Autovacuum backlog (tables with `n_dead_tup` / `n_live_tup` > 0.2) | 0 | 1–3 | > 3 on hot tables |
| Buffer cache hit ratio (`pg_stat_database`) | > 99% | 97–99% | < 97% |
| WAL generation rate (sustained) | < 40 MB/s | 40–80 MB/s | > 80 MB/s |
| Longest-running query | < 5 s | 5–30 s | > 30 s (excl. reports) |
| Deadlocks per hour | 0 | 1–3 | > 3 |

**Definition of done for any new query path**: before merge, the author attaches the
`EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)` output for the query against a database seeded to at least
10x the largest known tenant's row counts (see `Benchmarks`), confirms the plan uses an index (not a
`Seq Scan` on a table with more than 10,000 rows unless the planner has a provably better reason,
documented inline), and confirms the query appears in the p95 budget table above or a new row is
added with sign-off from the database owner.

# Indexes

Every index in QAYD exists to answer a specific, named query pattern — there are no speculative
indexes. Because `company_id` is the first-class isolation boundary and appears in the `WHERE`
clause (directly, or via RLS policy predicate) of essentially every query, it is the leading column
of the large majority of composite indexes in the system: PostgreSQL's B-tree indexes are most
efficient when the leading column has the best selectivity for the query's access pattern, and for
tenant-scoped queries `company_id` combined with a second predicate column gives near-perfect
selectivity within a single company's data.

**Standard tenant-table index set.** Every business table gets, at minimum:

```sql
-- Primary key (automatic B-tree, but always explicit for clarity in migrations)
ALTER TABLE invoices ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);

-- Tenant isolation index — supports RLS policy USING (company_id = current_setting(...))
-- and every "list my company's records" query.
CREATE INDEX idx_invoices_company_id ON invoices (company_id) WHERE deleted_at IS NULL;

-- Soft-delete-aware composite for the default list view (status filter + recency sort)
CREATE INDEX idx_invoices_company_status_date
    ON invoices (company_id, status, invoice_date DESC)
    WHERE deleted_at IS NULL;

-- Foreign key indexes — Postgres does NOT auto-index FKs; every FK column gets one
-- to avoid full-table scans on ON DELETE/UPDATE CASCADE and on JOIN.
CREATE INDEX idx_invoices_customer_id ON invoices (customer_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_invoices_branch_id   ON invoices (branch_id)   WHERE deleted_at IS NULL;
```

The `WHERE deleted_at IS NULL` clause makes every one of these a **partial index**. Because
soft-deleted rows are rarely queried directly (they surface only in audit/restore flows, which use a
separate, deliberately unindexed-for-speed path that tolerates a sequential scan), partial indexes
keep the index roughly the size of the "live" dataset instead of growing forever as
tombstoned rows accumulate — this matters enormously for `journal_lines`, `invoices`, and
`stock_movements`, which are append-heavy and never physically deleted.

**Composite index column order.** The rule is: equality columns first (most selective first among
equals), then a single range/sort column last, matching the query's `ORDER BY` so the index can
satisfy both the filter and the sort without a separate `Sort` node. Example — the AR aging report
filters by `company_id` and `status`, and sorts by `due_date`:

```sql
CREATE INDEX idx_invoices_aging
    ON invoices (company_id, status, due_date)
    WHERE deleted_at IS NULL AND status IN ('unpaid', 'partially_paid');
```

This index is also a **partial index restricted by value**, not just by `deleted_at`: paid and voided
invoices — the majority of rows in a mature company — never appear in an aging report, so excluding
them from the index keeps it small and keeps every leaf page hot in the buffer cache.

**Covering indexes (`INCLUDE`).** For the highest-frequency read path — the invoice list endpoint,
which is called on every dashboard load — an index-only scan avoids a heap fetch entirely by
including the columns the SELECT list needs:

```sql
CREATE INDEX idx_invoices_list_covering
    ON invoices (company_id, status, invoice_date DESC)
    INCLUDE (total_amount, currency_code, customer_id, invoice_number)
    WHERE deleted_at IS NULL;
```

`EXPLAIN (ANALYZE, BUFFERS)` on a company with 40,000 invoices confirms the plan is `Index Only Scan`
with zero heap fetches once the visibility map is up to date (see `Vacuum`):

```
Index Only Scan using idx_invoices_list_covering on invoices
  (cost=0.42..8.94 rows=25 width=96) (actual time=0.031..0.052 rows=25 loops=1)
  Index Cond: ((company_id = 4821) AND (status = 'unpaid'::text))
  Heap Fetches: 0
  Buffers: shared hit=6
Planning Time: 0.114 ms
Execution Time: 0.071 ms
```

**Journal lines — the hottest table in the system.** `journal_lines` is written on every posted
financial event and read on every ledger/trial-balance/statement query. Its index set is deliberately
narrow but heavily used:

```sql
CREATE INDEX idx_journal_lines_account_period
    ON journal_lines (company_id, account_id, fiscal_period_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_journal_lines_entry_id
    ON journal_lines (journal_entry_id);

CREATE INDEX idx_journal_lines_dimensions
    ON journal_lines (company_id, cost_center_id, project_id)
    WHERE cost_center_id IS NOT NULL OR project_id IS NOT NULL;
```

The third index is a partial index over the *dimension* columns (`cost_center_id`, `project_id`)
because most journal lines carry no dimension — indexing only the rows that do keeps this index a
fraction of the table's size while still serving cost-center and project P&L reports efficiently.

**GIN indexes for JSONB and full-text search.** `custom_fields JSONB` and `tags JSONB` on master-data
tables (`customers`, `vendors`, `products`) are queried with containment operators, which require a
GIN index — a B-tree cannot serve `@>`:

```sql
CREATE INDEX idx_customers_custom_fields_gin
    ON customers USING GIN (custom_fields jsonb_path_ops);

CREATE INDEX idx_customers_tags_gin
    ON customers USING GIN (tags);

-- Full-text search across bilingual name columns
ALTER TABLE products ADD COLUMN search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(name_en, '')), 'A') ||
        setweight(to_tsvector('arabic', coalesce(name_ar, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(description, '')), 'B')
    ) STORED;

CREATE INDEX idx_products_search_gin ON products USING GIN (search_vector);
```

`jsonb_path_ops` is preferred over the default `jsonb_ops` for `custom_fields` because the access
pattern is always exact-path containment (`custom_fields @> '{"industry": "retail"}'`) rather than
key-existence (`?`) queries; `jsonb_path_ops` produces a smaller index (roughly 30% smaller in
practice on this schema) at the cost of not supporting the `?`/`?|`/`?&` operators, which this
column never needs.

**BRIN indexes for append-only time-series tables.** `audit_logs` and `stock_movements` are
insert-only, naturally ordered by `created_at`, and queried almost exclusively by date range for a
given company. A BRIN (Block Range INdex) index is 1–2% the size of an equivalent B-tree and is
sufficient because the physical row order already correlates with `created_at`:

```sql
CREATE INDEX idx_audit_logs_created_at_brin
    ON audit_logs USING BRIN (created_at) WITH (pages_per_range = 32);
```

**Index maintenance discipline.** Every migration that adds an index on a table expected to have
more than 100,000 rows in production uses `CREATE INDEX CONCURRENTLY` inside a Laravel migration
with `Schema::disableForeignKeyConstraints()`-free raw statements (concurrent index builds cannot run
inside a transaction block, so the migration wraps the DDL in `DB::statement()` and Laravel's
migration runner is configured with `public $withinTransaction = false;` on that migration class):

```php
class AddIdxInvoicesAgingConcurrently extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_aging
            ON invoices (company_id, status, due_date)
            WHERE deleted_at IS NULL AND status IN (\'unpaid\', \'partially_paid\')
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_invoices_aging');
    }
}
```

`CONCURRENTLY` takes roughly 2–3x longer than a plain `CREATE INDEX` and requires two full table
scans, but it never takes the `SHARE` lock that would block concurrent `INSERT`/`UPDATE`/`DELETE` —
non-negotiable on `journal_lines`, `invoices`, and `stock_movements`, which cannot tolerate a
maintenance window.

**Unused-index audit.** A monthly job queries `pg_stat_user_indexes` for indexes with `idx_scan = 0`
over a 30-day window (excluding recently created ones and unique constraints, which exist for
correctness, not speed) and files a removal ticket — every unused index is pure write overhead on
every `INSERT`/`UPDATE` and pure space in the buffer cache competing with indexes that are actually
used:

```sql
SELECT schemaname, relname AS table_name, indexrelname AS index_name,
       idx_scan, pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes
WHERE idx_scan = 0
  AND indexrelname NOT LIKE '%_pkey'
  AND indexrelname NOT LIKE '%_unique'
ORDER BY pg_relation_size(indexrelid) DESC;
```

# Query Optimization

Query optimization in QAYD follows a fixed hierarchy: (1) eliminate the query entirely if the data
is already available (cache, denormalized column, or a value the caller already has); (2) reduce the
rows scanned via a better index or partition prune; (3) reduce the rows returned via projection and
pagination; (4) reduce planning/execution overhead via prepared statements and batching. Every
Eloquent-generated query in a hot path is reviewed against its raw SQL — the ORM is convenient, not
authoritative, and `DB::listen()` is enabled in staging to surface N+1 patterns before they reach
production.

**N+1 elimination.** The single most common regression in this codebase is an N+1 load of
`invoice_items` or `journal_lines` inside a loop. Eager loading is mandatory for every list endpoint
that touches a child collection:

```php
// WRONG — N+1: one query per invoice for items, one per invoice for customer
$invoices = Invoice::where('company_id', $companyId)->paginate(25);
foreach ($invoices as $invoice) {
    $invoice->items;       // triggers a query per row
    $invoice->customer;    // triggers a query per row
}

// RIGHT — 3 queries total regardless of page size
$invoices = Invoice::where('company_id', $companyId)
    ->with(['items:id,invoice_id,product_id,quantity,unit_price',
            'customer:id,name_en,name_ar'])
    ->select(['id', 'company_id', 'customer_id', 'invoice_number',
              'status', 'invoice_date', 'total_amount', 'currency_code'])
    ->orderByDesc('invoice_date')
    ->paginate(25);
```

Note the explicit column lists on both the parent query and the eager-loaded relation — QAYD forbids
unqualified `SELECT *` (enforced via a static-analysis rule in CI, `qayd/no-select-star`) because it
(a) defeats covering/index-only scans, (b) transfers unused large columns (`custom_fields JSONB`,
`description TEXT`) over the wire, and (c) breaks silently when a column is added later and a caller
was implicitly depending on `array_values()` positional order.

**Keyset (cursor) pagination over OFFSET.** Standard API pagination (`meta.pagination`) supports
cursor pagination, and every internally-built list view (reports, exports, infinite-scroll UI) uses
it exclusively. `OFFSET` pagination degrades linearly with page depth because PostgreSQL must scan
and discard every preceding row:

```sql
-- WRONG — page 400 (offset 10,000) must scan and discard 10,000 rows every time
SELECT * FROM journal_lines
WHERE company_id = 4821
ORDER BY created_at DESC, id DESC
OFFSET 10000 LIMIT 25;

-- RIGHT — keyset pagination: the WHERE clause does the skipping via the index,
-- cost is identical no matter how deep the page
SELECT id, journal_entry_id, account_id, debit_amount, credit_amount, created_at
FROM journal_lines
WHERE company_id = 4821
  AND (created_at, id) < ('2026-06-30 14:22:10.501823+00', 998214)  -- cursor from previous page
ORDER BY created_at DESC, id DESC
LIMIT 25;
```

The row-value comparison `(created_at, id) < (...)` requires a matching composite index
`(company_id, created_at DESC, id DESC)` to be served as an index range scan rather than a filter
over a broader scan; QAYD's cursor-pagination helper (`App\Support\KeysetPaginator`) generates this
exact predicate shape and asserts at boot time (in non-production environments) that the backing
index exists for every model that opts into it.

**Batch writes instead of per-row loops.** Bulk imports (bill items from a supplier catalog, stock
movements from a warehouse scan) use multi-row `INSERT` with `ON CONFLICT` rather than one `INSERT`
per row, cutting round-trip and parse/plan overhead by 1–2 orders of magnitude:

```php
// Laravel: chunk into batches of 500 and use insertOrIgnore / upsert
collect($rows)->chunk(500)->each(function ($chunk) use ($companyId) {
    StockMovement::query()->insert(
        $chunk->map(fn ($r) => [
            'company_id'   => $companyId,
            'product_id'   => $r['product_id'],
            'warehouse_id' => $r['warehouse_id'],
            'quantity'     => $r['quantity'],
            'movement_type'=> $r['movement_type'],
            'created_at'   => now(),
            'updated_at'   => now(),
        ])->all()
    );
});
```

Equivalent raw SQL, showing the `ON CONFLICT` upsert form used for idempotent re-imports keyed on an
external reference number:

```sql
INSERT INTO stock_movements
    (company_id, product_id, warehouse_id, quantity, movement_type,
     external_reference, created_at, updated_at)
VALUES
    (4821, 9012, 3, 120.0000, 'receipt', 'PO-2026-00441-L1', now(), now()),
    (4821, 9013, 3,  40.0000, 'receipt', 'PO-2026-00441-L2', now(), now()),
    (4821, 9014, 3,  15.0000, 'receipt', 'PO-2026-00441-L3', now(), now())
ON CONFLICT (company_id, external_reference)
DO UPDATE SET quantity = EXCLUDED.quantity, updated_at = now();
```

**Avoiding function calls on indexed columns.** A function wrapped around an indexed column
(`WHERE DATE(created_at) = '2026-07-16'`) forces a sequential scan because the planner cannot use a
plain B-tree index to evaluate an arbitrary function per row. The fix is a `BETWEEN` range on the
raw column, or a functional index if the transformed form is genuinely the access pattern:

```sql
-- WRONG — defeats the index on created_at
SELECT * FROM invoices WHERE DATE(invoice_date) = '2026-07-16';

-- RIGHT — sargable range predicate uses the index
SELECT * FROM invoices
WHERE invoice_date >= '2026-07-16 00:00:00+00'
  AND invoice_date <  '2026-07-17 00:00:00+00';

-- Alternative when the transformed form truly is the query shape everywhere:
CREATE INDEX idx_invoices_invoice_date_only
    ON invoices ((invoice_date::date));
```

**Avoiding `LIKE '%term%'` on B-tree indexes.** Leading-wildcard `LIKE` cannot use a standard B-tree
index. Search-shaped queries use the `pg_trgm` extension with a GIN/GiST trigram index, or the
`tsvector` full-text index defined in `Indexes`:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_customers_name_trgm ON customers USING GIN (name_en gin_trgm_ops);

-- Now this uses the trigram index instead of a sequential scan
SELECT id, name_en FROM customers
WHERE company_id = 4821 AND name_en ILIKE '%trading%';
```

**`EXISTS` over `IN` with a subquery, and `NOT EXISTS` over `NOT IN`.** For semi-join and anti-join
patterns, `EXISTS`/`NOT EXISTS` let the planner short-circuit per outer row and are immune to the
classic `NOT IN` NULL-handling trap (a single `NULL` in the subquery's result silently makes the
whole `NOT IN` return zero rows):

```sql
-- Vendors with at least one unpaid bill — EXISTS lets the planner stop at the first match
SELECT v.id, v.name_en
FROM vendors v
WHERE v.company_id = 4821
  AND EXISTS (
      SELECT 1 FROM bills b
      WHERE b.vendor_id = v.id AND b.status = 'unpaid' AND b.deleted_at IS NULL
  );
```

**CTE materialization awareness.** PostgreSQL 12+ inlines `WITH` CTEs by default unless the CTE is
referenced more than once or is marked `AS MATERIALIZED`. QAYD relies on this: multi-step reporting
queries are written as CTEs for readability, and the planner still pushes predicates through into
each CTE as if it were a subquery — but any CTE that is expensive to compute and reused (e.g. a
per-account running balance used by both the trial balance and the balance sheet in the same
request) is pinned with `AS MATERIALIZED` explicitly so it is computed once, not re-planned per
reference:

```sql
WITH period_balances AS MATERIALIZED (
    SELECT account_id, SUM(debit_amount) - SUM(credit_amount) AS net_movement
    FROM journal_lines
    WHERE company_id = 4821 AND fiscal_period_id = 118 AND deleted_at IS NULL
    GROUP BY account_id
)
SELECT a.code, a.name_en, pb.net_movement
FROM accounts a
JOIN period_balances pb ON pb.account_id = a.id
WHERE a.company_id = 4821;
```

# Execution Plans

`EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)` is run against every query before it ships to production
in a hot path, and its output is attached to the pull request. This section defines how plans are
read and what each anti-pattern in a plan means.

**Reading node cost vs. actual time.** `cost=0.42..8.94` is the *planner's estimate* (startup cost,
total cost) in arbitrary units calibrated to `seq_page_cost = 1.0`; `actual time=0.031..0.052` is
*measured* wall-clock milliseconds (startup, total) from `ANALYZE`. A large gap between estimated
rows and actual rows is the single strongest signal that `ANALYZE` statistics are stale (see
`Analyze`) or that the query's predicate correlates two columns in a way the planner's default
independence assumption misses (fixed with extended statistics, also covered in `Analyze`).

**Example — good plan (index-only scan) for the invoice list endpoint:**

```
Limit  (cost=0.42..8.94 rows=25 width=96) (actual time=0.031..0.058 rows=25 loops=1)
  ->  Index Only Scan using idx_invoices_list_covering on invoices
        (cost=0.42..842.11 rows=2481 width=96)
        (actual time=0.030..0.053 rows=25 loops=1)
        Index Cond: ((company_id = 4821) AND (status = 'unpaid'::text))
        Heap Fetches: 0
        Buffers: shared hit=6
Planning Time: 0.187 ms
Execution Time: 0.079 ms
```

`Heap Fetches: 0` confirms the visibility map marked every relevant page all-visible, so the index
alone answered the query without touching the heap. `Buffers: shared hit=6` (all cache hits, zero
disk reads) confirms the working set is resident in `shared_buffers`.

**Example — bad plan (sequential scan) before an index was added, same query shape on
`stock_movements` filtered by an unindexed `batch_number`:**

```
Seq Scan on stock_movements  (cost=0.00..48213.00 rows=112 width=88)
                              (actual time=241.885..612.402 rows=118 loops=1)
  Filter: ((company_id = 4821) AND (batch_number = 'B-2026-0091'))
  Rows Removed by Filter: 2481336
  Buffers: shared hit=41902 read=6311
Planning Time: 0.098 ms
Execution Time: 612.471 ms
```

`Rows Removed by Filter: 2481336` is the tell — the planner scanned every one of 2.48M rows across
all tenants co-located in this table to find 118 matching rows, and `read=6311` shows most of that
came from disk, not cache. The remediation was a partial-tenant composite index:

```sql
CREATE INDEX CONCURRENTLY idx_stock_movements_batch
    ON stock_movements (company_id, batch_number)
    WHERE deleted_at IS NULL;
```

Post-index plan for the identical query:

```
Index Scan using idx_stock_movements_batch on stock_movements
  (cost=0.56..92.14 rows=118 width=88) (actual time=0.041..0.198 rows=118 loops=1)
  Index Cond: ((company_id = 4821) AND (batch_number = 'B-2026-0091'))
  Buffers: shared hit=124
Planning Time: 0.121 ms
Execution Time: 0.221 ms
```

A **2,770x** reduction in execution time (612 ms → 0.22 ms) from one correctly targeted index.

**Example — bad plan (nested loop explosion) from an unindexed join, and the hash-join fix.** The
AP aging report originally joined `bills` to `vendors` and `bill_items` without a supporting index
on `bill_items.bill_id`:

```
Nested Loop  (cost=0.29..918442.10 rows=4821 width=140)
             (actual time=0.145..8841.209 rows=4821 loops=1)
  ->  Seq Scan on bills b (cost=0.00..8213.00 rows=4821 width=64)
        (actual time=0.031..44.201 rows=4821 loops=1)
        Filter: ((company_id = 4821) AND (status = 'unpaid'::text))
  ->  Index Scan using bill_items_bill_id_idx on bill_items bi
        (cost=0.29..188.41 rows=1 width=76)
        (actual time=1.821..1.822 rows=1 loops=4821)
Planning Time: 0.402 ms
Execution Time: 8842.011 ms
```

Here the *outer* scan on `bills` was itself a sequential scan (missing the `(company_id, status)`
composite index from `Indexes`), forcing 4,821 loop iterations of an otherwise-fine inner index scan.
Adding `idx_bills_company_status` collapsed the outer scan to an index scan and let the planner
switch strategy entirely to a single hash join:

```
Hash Join  (cost=612.40..2104.88 rows=4821 width=140)
           (actual time=4.201..19.884 rows=4821 loops=1)
  Hash Cond: (bi.bill_id = b.id)
  ->  Seq Scan on bill_items bi (cost=0.00..1284.00 rows=61200 width=76)
        (actual time=0.018..6.402 rows=61200 loops=1)
  ->  Hash  (cost=602.10..602.10 rows=4821 width=64)
        (actual time=4.108..4.109 rows=4821 loops=1)
        ->  Index Scan using idx_bills_company_status on bills b
              (cost=0.42..602.10 rows=4821 width=64)
              (actual time=0.028..2.884 rows=4821 loops=1)
Planning Time: 0.311 ms
Execution Time: 20.412 ms
```

**433x** improvement (8,842 ms → 20.4 ms). The lesson generalizes: a nested loop with a high `loops`
count is almost always downstream of a missing index on the *outer* relation, not a defect in the
join strategy itself — fix the outer scan first and let the planner re-choose the join algorithm.

**`auto_explain` in staging.** The `auto_explain` extension is loaded in the staging cluster (never
directly in production, to avoid `ANALYZE`'s execution overhead on every statement) to catch
regressions before release:

```
# postgresql.conf (staging only)
shared_preload_libraries = 'auto_explain,pg_stat_statements'
auto_explain.log_min_duration = 100          # ms
auto_explain.log_analyze = on
auto_explain.log_buffers = on
auto_explain.log_nested_statements = on
auto_explain.log_format = json
```

# Caching

QAYD caches at three layers: PostgreSQL's own buffer cache (`shared_buffers` + OS page cache),
application-level Redis caching of read-heavy, slow-changing data, and materialized views for
expensive aggregations (covered separately in `Materialized Views`). The cache invalidation strategy
is explicit and event-driven — QAYD never relies on TTL-only expiry for financially sensitive data,
because a stale trial balance is a worse failure mode than a slow one.

**Buffer cache sizing.** `shared_buffers` is set to 25% of instance RAM (the standard PostgreSQL
guidance, since PostgreSQL relies on the OS page cache as a second tier and over-allocating
`shared_buffers` starves that tier):

```
# postgresql.conf — 64 GB RAM instance
shared_buffers = 16GB
effective_cache_size = 48GB      # hint to planner: shared_buffers + expected OS cache
work_mem = 64MB                  # per sort/hash node, per connection — see Connection Pooling
maintenance_work_mem = 2GB       # for VACUUM, CREATE INDEX, ALTER TABLE
huge_pages = try
```

`effective_cache_size` does not allocate memory — it tells the planner how much data is likely
cacheable across both tiers, which shifts the cost model toward preferring index scans over
sequential scans when the working set plausibly fits in RAM.

**Redis application cache — what is cached and for how long.** Only data that is expensive to
compute *and* tolerant of a bounded staleness window is cached outside PostgreSQL:

| Cached entity | Redis key pattern | TTL | Invalidated on |
|---|---|---|---|
| Chart of accounts tree (per company) | `qayd:coa:{company_id}` | 1 hour | `account.created`, `account.updated`, `account.deactivated` events |
| Exchange rates (per day, per currency pair) | `qayd:fx:{date}:{from}:{to}` | 24 hours | Never mutated once written (immutable historical rate) |
| Permission → role resolution | `qayd:perm:{role_id}` | 15 min | `role.permissions_changed` event |
| Product price list lookup | `qayd:pricelist:{company_id}:{price_list_id}` | 10 min | `price_list_item.updated` event |
| Company settings (fiscal year, base currency, locale) | `qayd:settings:{company_id}` | 30 min | `company.settings_updated` event |
| Dashboard summary tiles (today's sales, AR total) | `qayd:dash:{company_id}:{date}` | 5 min | Time-based only — explicitly tolerant of 5-min staleness, labeled "as of" in the UI |

Financial *transactional* data — account balances, invoice status, journal postings — is **never**
cached in Redis; it is read from PostgreSQL on every request (backed by the covering indexes in
`Indexes` and, for aggregates, the materialized views in `Materialized Views`), because the cost of a
stale balance shown to a CFO exceeds the cost of an extra indexed query.

**Event-driven invalidation pattern (Laravel):**

```php
// app/Observers/AccountObserver.php
class AccountObserver
{
    public function saved(Account $account): void
    {
        Redis::del("qayd:coa:{$account->company_id}");
    }

    public function deleted(Account $account): void
    {
        Redis::del("qayd:coa:{$account->company_id}");
    }
}
```

**Cache stampede protection.** The chart-of-accounts cache is rebuilt by many concurrent requests
the instant it expires under load; QAYD uses a short-lived Redis lock (`SET ... NX EX 5`) so only one
request recomputes the value while others read the (slightly) stale cached copy rather than all
hitting PostgreSQL simultaneously:

```php
$key = "qayd:coa:{$companyId}";
$value = Redis::get($key);
if ($value === null) {
    $lockKey = "{$key}:lock";
    if (Redis::set($lockKey, 1, 'NX', 'EX', 5)) {
        $value = $this->buildChartOfAccounts($companyId);
        Redis::setex($key, 3600, json_encode($value));
        Redis::del($lockKey);
    } else {
        usleep(50_000);
        $value = Redis::get($key) ?? json_encode($this->buildChartOfAccounts($companyId));
    }
}
```

**`pg_stat_statements` for hot-query identification feeding cache decisions.** A query is a caching
*candidate* only if it is both frequent and expensive; `pg_stat_statements` (see `Monitoring`)
ranks by `total_exec_time`, and any query appearing in the top 20 by total time whose result changes
less than once per minute per tenant is escalated to the caching backlog.

# Connection Pooling

PostgreSQL connections are expensive — each backend process consumes roughly 5–10 MB of memory
before running a single query, and connection setup/teardown (fork, catalog cache warm-up, SSL
handshake) costs single-digit milliseconds that dominate the runtime of small OLTP queries if paid on
every request. QAYD's Laravel workers (PHP-FPM, stateless per-request) cannot hold persistent
connections themselves, so **PgBouncer** sits between the application tier and PostgreSQL in
`transaction` pooling mode.

**PgBouncer configuration (`pgbouncer.ini`):**

```ini
[databases]
qayd_prod = host=pg-primary.internal port=5432 dbname=qayd_prod

[pgbouncer]
listen_addr = 0.0.0.0
listen_port = 6432
auth_type = scram-sha-256
pool_mode = transaction
max_client_conn = 4000
default_pool_size = 60
min_pool_size = 10
reserve_pool_size = 15
reserve_pool_timeout = 3
server_idle_timeout = 300
server_lifetime = 3600
query_wait_timeout = 30
log_connections = 0
log_disconnections = 0
```

`pool_mode = transaction` — not `session` — is mandatory because it lets 4,000 client-side FPM
workers share a pool of 60 real server connections, releasing the server connection back to the pool
the instant a transaction commits rather than holding it for the client's entire request lifecycle.
The tradeoff, understood and accepted platform-wide, is that session-level state (`SET
search_path`, prepared statements outside a transaction, `LISTEN/NOTIFY`, advisory session locks) does
not survive across transactions in this mode — QAYD's code never relies on session state, and the one
place that needs a session-scoped setting (`SET LOCAL app.current_company_id = ...` for RLS, detailed
in `ROW_LEVEL_SECURITY.md`) uses `SET LOCAL`, which is correctly transaction-scoped and therefore
compatible with transaction pooling.

**Sizing `default_pool_size`.** The standard formula, `pool_size ≈ (num_cpu_cores * 2) + effective_spindle_count`,
gives a starting point, but QAYD sizes pools empirically against `max_connections` headroom: with
`max_connections = 300` on the primary, and PgBouncer plus direct maintenance/replication connections
needing headroom, the pool is capped so that `default_pool_size × number_of_pgbouncer_instances` never
exceeds roughly 70% of `max_connections`, leaving room for `pg_dump`, replication, and superuser
connections during an incident:

```
# postgresql.conf
max_connections = 300
superuser_reserved_connections = 5
```

**Laravel database configuration for PgBouncer compatibility:**

```php
// config/database.php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', 'pgbouncer.internal'),
    'port' => env('DB_PORT', '6432'),
    'database' => env('DB_DATABASE', 'qayd_prod'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8',
    'prefer_qualified_column_names' => true,
    'options' => [
        // PgBouncer transaction mode does not support named prepared statements
        // reliably across pooled connections; PDO emulates them instead.
        \PDO::ATTR_EMULATE_PREPARES => true,
    ],
],
```

**Read-replica routing.** Reporting queries (`report_runs`, dashboard aggregates, AI-agent read-only
analysis) are routed to a dedicated PgBouncer pool pointed at a streaming replica, isolating their
connection and I/O pressure from the OLTP primary:

```ini
[databases]
qayd_prod       = host=pg-primary.internal port=5432 dbname=qayd_prod
qayd_prod_ro    = host=pg-replica-1.internal port=5432 dbname=qayd_prod
```

```php
// Laravel read/write connection split
'pgsql' => [
    'read' => ['host' => [env('DB_READ_HOST', 'pgbouncer-ro.internal')]],
    'write' => ['host' => [env('DB_WRITE_HOST', 'pgbouncer.internal')]],
    'sticky' => true,   // a request that writes then reads sees its own write
    // ... rest as above
],
```

`sticky = true` ensures that within one request, a read immediately following a write (e.g. reading
back a newly posted journal entry to return it in the API response) is served from the write
connection, avoiding replication-lag-induced "phantom missing record" bugs.

**Monitoring pool saturation:**

```sql
-- PgBouncer admin console (psql -p 6432 pgbouncer)
SHOW POOLS;
-- cl_active, cl_waiting columns: cl_waiting > 0 sustained means the pool is undersized
-- or a query is holding its transaction open too long (see idle-in-transaction check below).

SHOW STATS;
-- total_query_time / total_query_count gives average query time per database, trended over time.
```

# Vacuum

PostgreSQL's MVCC model means every `UPDATE` and `DELETE` leaves the old row version (a "dead tuple")
in place until vacuumed; on an append-and-update-heavy financial system, unchecked dead-tuple growth
bloats tables and indexes, degrades cache hit ratios, and — in the worst case — risks transaction ID
wraparound. Autovacuum is tuned per-table, not left at global defaults, because QAYD's tables have
wildly different write patterns: `journal_lines` is insert-only and rarely updated (fits default
tuning well), while `inventory_items` and `bank_accounts` (running-balance columns updated on every
movement) are update-heavy and need aggressive, low-threshold autovacuum.

**Global baseline (`postgresql.conf`):**

```
autovacuum = on
autovacuum_max_workers = 6
autovacuum_naptime = 15s
autovacuum_vacuum_cost_delay = 2ms
autovacuum_vacuum_cost_limit = 2000
autovacuum_vacuum_scale_factor = 0.1        # default 20% dead tuples before vacuum — too loose for hot tables, overridden per table below
autovacuum_analyze_scale_factor = 0.05
maintenance_work_mem = 2GB
```

**Per-table overrides for update-heavy tables.** `inventory_items` carries a running `quantity_on_hand`
that is updated on every `stock_movements` insert (via trigger, see `Concurrency`) — potentially
dozens of times per minute per warehouse during a busy receiving shift:

```sql
ALTER TABLE inventory_items SET (
    autovacuum_vacuum_scale_factor = 0.02,   -- vacuum at 2% dead tuples, not 20%
    autovacuum_vacuum_threshold = 50,
    autovacuum_analyze_scale_factor = 0.01,
    autovacuum_vacuum_cost_delay = 0         -- no throttling — this table is small and hot, vacuum it fast
);

ALTER TABLE bank_accounts SET (
    autovacuum_vacuum_scale_factor = 0.02,
    autovacuum_vacuum_threshold = 20
);

ALTER TABLE journal_lines SET (
    autovacuum_vacuum_scale_factor = 0.2,    -- looser: insert-only, dead tuples come only from rare corrections
    autovacuum_analyze_scale_factor = 0.02   -- but analyze often — the planner needs fresh stats on a growing table
);
```

**Why `autovacuum_vacuum_cost_delay = 0` on `inventory_items` is safe.** Cost-based vacuum throttling
exists to keep a large vacuum from starving foreground I/O; `inventory_items` is a narrow, small
table (one row per product/warehouse combination) whose vacuum passes complete in milliseconds, so
throttling only prolongs bloat with no protective benefit. The same override is deliberately **not**
applied to `journal_lines` or `stock_movements`, which are large enough that an unthrottled vacuum
could contend with foreground write I/O.

**Detecting bloat directly:**

```sql
SELECT schemaname, relname,
       n_live_tup, n_dead_tup,
       round(n_dead_tup::numeric / GREATEST(n_live_tup, 1), 4) AS dead_ratio,
       last_autovacuum, last_autoanalyze
FROM pg_stat_user_tables
WHERE n_dead_tup > 1000
ORDER BY dead_ratio DESC
LIMIT 20;
```

A `dead_ratio` sustained above 0.2 on any table larger than 10,000 rows, with `last_autovacuum` more
than an hour stale, pages the on-call database owner — it usually means either autovacuum is falling
behind `autovacuum_max_workers` capacity (add workers or raise `autovacuum_vacuum_cost_limit`) or a
long-running transaction is holding back the vacuum horizon (see the idle-in-transaction check
below).

**Transaction ID wraparound guard.** QAYD monitors `age(datfrozenxid)` and alerts well before the
2-billion-transaction wraparound danger zone:

```sql
SELECT datname, age(datfrozenxid) AS xid_age
FROM pg_database
ORDER BY xid_age DESC;
-- Alert threshold: xid_age > 200,000,000 (yellow), > 1,000,000,000 (red — page immediately)
```

**Manual `VACUUM (ANALYZE, VERBOSE)` after bulk operations.** Large one-off backfills or migrations
(e.g. a historical data import of 2M `stock_movements` rows) run an explicit vacuum immediately after,
rather than waiting for autovacuum's next scheduled pass, because the freshly written pages have no
visibility-map bits set yet and every subsequent query would otherwise pay heap-fetch cost until
autovacuum catches up:

```sql
VACUUM (ANALYZE, VERBOSE) stock_movements;
```

**Killing long-running idle-in-transaction sessions that block vacuum.** A transaction left open (a
common Laravel bug: a `DB::beginTransaction()` without a matching `commit()`/`rollBack()` on an
exception path) holds back the vacuum horizon for the entire cluster, not just the table it touched:

```sql
SELECT pid, usename, application_name, state, xact_start, now() - xact_start AS duration, query
FROM pg_stat_activity
WHERE state = 'idle in transaction'
  AND now() - xact_start > interval '5 minutes'
ORDER BY duration DESC;

-- Remediation once confirmed genuinely stuck (never on an active reporting connection):
SELECT pg_terminate_backend(12345);
```

`idle_in_transaction_session_timeout` is set platform-wide as a backstop so this class of bug
self-heals instead of silently accumulating:

```
idle_in_transaction_session_timeout = 60000   # 60s, in ms
```

# Analyze

`ANALYZE` populates `pg_statistics` (row counts, most-common values, histogram boundaries,
correlation) that the planner uses to estimate selectivity and choose join strategies. Autovacuum's
analyze component runs on the same table-size-triggered schedule as vacuum (tuned in `Vacuum`), but
QAYD adds explicit `ANALYZE` calls and extended statistics at three points beyond the automatic
schedule.

**After bulk loads, before the first query.** Autovacuum's analyze threshold is scale-factor-based
(a percentage of the table), so a bulk import into an already-large table might not trigger an
automatic analyze quickly enough — the import job always ends with an explicit analyze on the
affected table(s):

```sql
ANALYZE stock_movements;
ANALYZE inventory_items;
```

**Extended statistics for correlated columns.** The planner's default selectivity model assumes
columns are independent; `journal_lines.company_id` and `journal_lines.fiscal_period_id` are
strongly correlated in practice (each company's periods are distinct value ranges), which can cause
severe row-count misestimation on multi-column predicates without help:

```sql
CREATE STATISTICS stx_journal_lines_company_period (dependencies)
    ON company_id, fiscal_period_id FROM journal_lines;

ANALYZE journal_lines;
```

Before extended statistics, a plan against `WHERE company_id = 4821 AND fiscal_period_id = 118` might
show an estimate of `rows=41200` (the planner multiplying the two columns' independent selectivities)
against an actual of `rows=890` — a 46x overestimate that can push the planner toward a hash join
over a much cheaper nested loop. After `CREATE STATISTICS ... (dependencies)`, the estimate tightens
to within single-digit-percent of actual.

**MCV lists for skewed categorical columns.** `invoices.status` has a handful of highly skewed
values (`paid` might be 85% of rows, `unpaid` 10%, the remainder split across `draft`/`voided`/
`disputed`). The default statistics target (100) usually captures this adequately, but for a column
this skewed and this frequently filtered, QAYD raises the per-column statistics target to ensure the
most-common-value list and histogram resolve the tail values precisely:

```sql
ALTER TABLE invoices ALTER COLUMN status SET STATISTICS 500;
ANALYZE invoices;
```

**Verifying planner estimates against reality.** Any time a plan review (`Execution Plans`) shows an
estimated-vs-actual row mismatch beyond roughly 10x, the fix path is, in order: (1) run `ANALYZE` on
the table and re-check — often statistics are simply stale; (2) if still mismatched, check `n_distinct`
overrides are not needed for a column the planner is sampling poorly (rare, high-cardinality columns
on large tables sometimes need a manual override); (3) add extended statistics for the specific
column combination if the mismatch is a correlation issue as above.

```sql
-- Inspect current statistics target and sampled n_distinct for a column
SELECT attname, n_distinct, most_common_vals, most_common_freqs
FROM pg_stats
WHERE tablename = 'invoices' AND attname = 'status';
```

# Materialized Views

Financial statements (Trial Balance, Balance Sheet, Profit & Loss, AR/AP aging summaries) are all
mathematically *derived* from posted `journal_lines` — per `DESIGN_CONTEXT.md`, they must never store
independent primary data. But computing a full trial balance by summing every posted `journal_lines`
row for a company's entire fiscal year, on every dashboard load, does not meet the latency targets in
`Performance Targets` once a company has several years of history. QAYD resolves this with
materialized views that are explicitly documented as *read-model projections*, refreshed on a
predictable schedule and on-demand after a posting batch — never treated as a second source of
truth.

**`ledger_entries` — the General Ledger projection.** This is the canonical example from
`DESIGN_CONTEXT.md` ("`ledger_entries` is a projection/materialized view for speed"):

```sql
CREATE MATERIALIZED VIEW ledger_entries AS
SELECT
    jl.company_id,
    jl.account_id,
    jl.fiscal_period_id,
    a.code            AS account_code,
    a.account_type_id,
    jl.branch_id,
    jl.cost_center_id,
    jl.project_id,
    SUM(jl.debit_amount)  AS total_debits,
    SUM(jl.credit_amount) AS total_credits,
    SUM(jl.debit_amount) - SUM(jl.credit_amount) AS net_movement,
    COUNT(*)          AS line_count,
    MAX(jl.created_at) AS last_posted_at
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id
JOIN accounts a ON a.id = jl.account_id
WHERE je.status = 'posted' AND jl.deleted_at IS NULL
GROUP BY jl.company_id, jl.account_id, jl.fiscal_period_id, a.code, a.account_type_id,
         jl.branch_id, jl.cost_center_id, jl.project_id
WITH DATA;

CREATE UNIQUE INDEX idx_ledger_entries_pk
    ON ledger_entries (company_id, account_id, fiscal_period_id,
                        COALESCE(branch_id, 0), COALESCE(cost_center_id, 0), COALESCE(project_id, 0));

CREATE INDEX idx_ledger_entries_lookup
    ON ledger_entries (company_id, fiscal_period_id, account_type_id);
```

The `UNIQUE INDEX` on the grouping key is mandatory, not optional — it is the precondition for
`REFRESH MATERIALIZED VIEW CONCURRENTLY`, which is the only refresh mode that does not take an
`ACCESS EXCLUSIVE` lock (a plain `REFRESH MATERIALIZED VIEW` blocks every concurrent read of the
view for its entire duration, which is unacceptable for a view read on every financial dashboard).

**Refresh strategy — triggered, not purely scheduled.** A scheduled nightly refresh alone would leave
same-day postings invisible in reports until the next refresh, which fails the "reflect what was just
posted" expectation for a same-day close. QAYD refreshes `ledger_entries` at two cadences:

1. **Incremental trigger-based refresh** for just-posted data: the `journal_entries` post action
   enqueues a Laravel job (`RefreshLedgerProjection`) scoped to the affected `company_id` +
   `fiscal_period_id`, debounced to at most one refresh per company per 30 seconds so a burst of 50
   postings in a batch import triggers one refresh, not 50.
2. **Scheduled full refresh** every 15 minutes as a correctness backstop, in case a debounced job is
   ever dropped (queue failure, deploy restart):

```php
// app/Console/Kernel.php
$schedule->call(function () {
    DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY ledger_entries');
})->everyFifteenMinutes()->withoutOverlapping();
```

```php
// Debounced targeted refresh — job dispatched after journal_entries.post()
class RefreshLedgerProjection implements ShouldQueue
{
    public function handle(): void
    {
        // CONCURRENTLY refreshes the whole view (Postgres has no native partial
        // materialized-view refresh); the debounce keeps this affordable.
        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY ledger_entries');
    }
}
```

**Trial balance query against the materialized view** — now a single indexed aggregation instead of
scanning raw `journal_lines`:

```sql
SELECT le.account_code, a.name_en, a.name_ar, at.normal_balance,
       le.total_debits, le.total_credits, le.net_movement
FROM ledger_entries le
JOIN accounts a ON a.id = le.account_id
JOIN account_types at ON at.id = le.account_type_id
WHERE le.company_id = 4821 AND le.fiscal_period_id = 118
ORDER BY le.account_code;
```

Measured plan on a company with 4 years of history (≈ 1.8M `journal_lines` rows, 340 accounts):

```
Sort  (cost=48.21..48.86 rows=340 width=104) (actual time=1.204..1.221 rows=340 loops=1)
  Sort Key: le.account_code
  ->  Hash Join  (cost=8.42..44.10 rows=340 width=104)
        (actual time=0.612..1.081 rows=340 loops=1)
        ->  Index Scan using idx_ledger_entries_lookup on ledger_entries le
              (cost=0.42..32.18 rows=340 width=72)
              (actual time=0.041..0.402 rows=340 loops=1)
              Index Cond: ((company_id = 4821) AND (fiscal_period_id = 118))
        ->  Hash  (cost=6.40..6.40 rows=340 width=48)
Planning Time: 0.298 ms
Execution Time: 1.312 ms
```

**1.3 ms** against the materialized view, versus a measured **340 ms** for the equivalent live
aggregation directly over `journal_lines` on the same dataset — comfortably inside the < 50 ms p50
target from `Performance Targets` with wide margin.

**Additional materialized views following the same pattern:**

```sql
-- AR aging buckets, refreshed every 15 minutes (aging buckets shift daily, not per-transaction,
-- so no incremental trigger is needed — the schedule alone is sufficient)
CREATE MATERIALIZED VIEW customer_aging_summary AS
SELECT
    i.company_id, i.customer_id,
    SUM(CASE WHEN CURRENT_DATE - i.due_date <= 0  THEN i.balance_due ELSE 0 END) AS current_bucket,
    SUM(CASE WHEN CURRENT_DATE - i.due_date BETWEEN 1  AND 30 THEN i.balance_due ELSE 0 END) AS days_1_30,
    SUM(CASE WHEN CURRENT_DATE - i.due_date BETWEEN 31 AND 60 THEN i.balance_due ELSE 0 END) AS days_31_60,
    SUM(CASE WHEN CURRENT_DATE - i.due_date BETWEEN 61 AND 90 THEN i.balance_due ELSE 0 END) AS days_61_90,
    SUM(CASE WHEN CURRENT_DATE - i.due_date > 90 THEN i.balance_due ELSE 0 END) AS days_90_plus
FROM invoices i
WHERE i.status IN ('unpaid', 'partially_paid') AND i.deleted_at IS NULL
GROUP BY i.company_id, i.customer_id
WITH DATA;

CREATE UNIQUE INDEX idx_customer_aging_pk ON customer_aging_summary (company_id, customer_id);
```

**When *not* to use a materialized view.** Any report that must be correct-as-of-the-second (the
journal posting balance check itself, real-time bank-reconciliation matching) reads live tables,
never a materialized view — the staleness window, however small, is the deciding factor, and it is
documented per-view in the migration's comment header.

# Monitoring

QAYD's database observability stack is `pg_stat_statements` for query-level metrics,
`pg_stat_activity`/`pg_stat_user_tables`/`pg_stat_user_indexes` for point-in-time and cumulative
object-level metrics, and Cloudflare/AWS CloudWatch-fed dashboards (Grafana) that alert against the
thresholds in `Performance Targets`.

**Enabling `pg_stat_statements`:**

```
# postgresql.conf
shared_preload_libraries = 'pg_stat_statements'
pg_stat_statements.max = 10000
pg_stat_statements.track = all
pg_stat_statements.track_utility = off
pg_stat_statements.save = on
```

```sql
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
```

**Top queries by total time (where to spend optimization effort):**

```sql
SELECT
    query,
    calls,
    round(total_exec_time::numeric, 2)                       AS total_ms,
    round(mean_exec_time::numeric, 2)                        AS mean_ms,
    round((100 * total_exec_time / SUM(total_exec_time) OVER ())::numeric, 2) AS pct_of_total
FROM pg_stat_statements
ORDER BY total_exec_time DESC
LIMIT 20;
```

**Top queries by mean latency (where a single call is painfully slow, even if rare):**

```sql
SELECT query, calls, round(mean_exec_time::numeric, 2) AS mean_ms,
       round(max_exec_time::numeric, 2) AS max_ms
FROM pg_stat_statements
WHERE calls > 5
ORDER BY mean_exec_time DESC
LIMIT 20;
```

**Cache hit ratio per table (should stay above the 99% target from `Performance Targets`):**

```sql
SELECT relname,
       heap_blks_hit, heap_blks_read,
       round(100.0 * heap_blks_hit / GREATEST(heap_blks_hit + heap_blks_read, 1), 2) AS hit_ratio_pct
FROM pg_statio_user_tables
ORDER BY heap_blks_read DESC
LIMIT 20;
```

**Replication lag:**

```sql
-- Run on the replica
SELECT now() - pg_last_xact_replay_timestamp() AS replication_lag;

-- Run on the primary, per replica
SELECT client_addr, state, sent_lsn, write_lsn, flush_lsn, replay_lsn,
       write_lag, flush_lag, replay_lag
FROM pg_stat_replication;
```

**Lock contention snapshot:**

```sql
SELECT bl.pid AS blocked_pid, a.query AS blocked_query,
       kl.pid AS blocking_pid, ka.query AS blocking_query,
       now() - a.query_start AS blocked_duration
FROM pg_catalog.pg_locks bl
JOIN pg_catalog.pg_stat_activity a ON a.pid = bl.pid
JOIN pg_catalog.pg_locks kl ON kl.locktype = bl.locktype
   AND kl.database IS NOT DISTINCT FROM bl.database
   AND kl.relation IS NOT DISTINCT FROM bl.relation
   AND kl.pid != bl.pid AND kl.granted
JOIN pg_catalog.pg_stat_activity ka ON ka.pid = kl.pid
WHERE NOT bl.granted;
```

**Grafana dashboard panels wired to these queries** (each polled every 30s via a metrics exporter,
`postgres_exporter` with a custom `queries.yaml`):

```yaml
pg_cache_hit_ratio:
  query: |
    SELECT sum(heap_blks_hit) / greatest(sum(heap_blks_hit) + sum(heap_blks_read), 1) AS ratio
    FROM pg_statio_user_tables
  metrics:
    - ratio:
        usage: "GAUGE"
        description: "Buffer cache hit ratio across all user tables"

pg_dead_tuple_ratio:
  query: |
    SELECT relname, n_dead_tup::float / greatest(n_live_tup, 1) AS ratio
    FROM pg_stat_user_tables
  metrics:
    - relname: { usage: "LABEL" }
    - ratio:   { usage: "GAUGE" }
```

**Alert routing.** Each metric in the resource envelope table in `Performance Targets` has a
corresponding Grafana alert rule; yellow-tier breaches post to the `#qayd-db-watch` Slack channel,
red-tier breaches page the on-call database owner via PagerDuty. Alert rules are stored as code
(`monitoring/alerts/*.yaml`) and reviewed in the same pull-request process as schema changes.

# Slow Queries

QAYD's slow-query triage process is a fixed decision tree, not ad-hoc investigation, so any engineer
on-call can execute it under pressure.

**Step 1 — identify the query.** For a currently-running slow query:

```sql
SELECT pid, now() - query_start AS duration, state, wait_event_type, wait_event, query
FROM pg_stat_activity
WHERE state != 'idle' AND now() - query_start > interval '5 seconds'
ORDER BY duration DESC;
```

For a historically slow query, `pg_stat_statements` (per `Monitoring`) with `mean_exec_time DESC`.

**Step 2 — classify by `wait_event_type`.** This single column tells you which of four remediation
paths to take before you even look at the query text:

| `wait_event_type` | Meaning | Typical fix |
|---|---|---|
| `Lock` | Waiting on a row/table/advisory lock held by another session | Find and address the blocker (see `Concurrency`) |
| `IO` | Waiting on disk read/write | Missing index causing heap scan, or genuinely large data volume — add index or partition |
| `CPU` (no wait event; state = active) | Actually executing, using CPU | Check `EXPLAIN` for expensive operations: sort, hash, function calls |
| `Client` | Waiting on the application to send/receive data | Not a database problem — investigate the app/network side |

**Step 3 — capture the plan.** `EXPLAIN (ANALYZE, BUFFERS)` if the query is reproducible outside
production impact; if it is *currently* running and cannot be safely re-run (e.g. it holds a lock),
use `pg_stat_activity.query` plus the table statistics to infer the plan, or — on PostgreSQL 14+ —
sample the live plan with `pg_stat_activity` joined against `pg_stat_progress_*` views for the
specific maintenance/copy operations that expose progress.

**Step 4 — decide: kill or wait.** A query is terminated only if it is confirmed to be (a) blocking
other sessions per the lock-contention query in `Monitoring`, or (b) run away past a sane bound for
its class (a report query past 60s, an OLTP query past 5s) with no progress. Termination uses
`pg_cancel_backend` first (graceful — cancels the query, keeps the connection) and escalates to
`pg_terminate_backend` (drops the connection) only if cancel does not clear it within a few seconds:

```sql
SELECT pg_cancel_backend(12345);
-- wait 3-5 seconds, re-check pg_stat_activity
SELECT pg_terminate_backend(12345);
```

**Step 5 — file the fix, not just the mitigation.** Killing a session resolves the incident; it does
not resolve the recurrence. Every slow-query incident produces a ticket with the captured `EXPLAIN`
output and a named fix (index, query rewrite, materialized view, or a `statement_timeout` narrowing)
before it is closed.

**Statement timeout as a systemic backstop.** Rather than relying purely on manual triage, QAYD sets
a tiered `statement_timeout` so a runaway query cannot silently consume resources indefinitely:

```
# postgresql.conf — global default, generous because reporting connections override it lower
statement_timeout = 30000    # 30s
```

```php
// Laravel: OLTP web request connections get a much tighter timeout than the global default
DB::statement('SET statement_timeout = 5000');   // 5s, set at the start of every web request via middleware
```

```php
// app/Http/Middleware/SetDatabaseStatementTimeout.php
class SetDatabaseStatementTimeout
{
    public function handle(Request $request, Closure $next)
    {
        DB::statement('SET statement_timeout = 5000');
        return $next($request);
    }
}
```

Reporting jobs (`report_runs`) explicitly raise their own connection's timeout (`SET statement_timeout
= 60000`) since they are expected to run longer and are routed to the read replica, isolated from
OLTP contention per `Connection Pooling`.

# Concurrency

Financial correctness under concurrency is the load-bearing requirement of this entire database
layer: two cashiers posting receipts against the same customer account, a payroll run and a manual
journal correction touching the same fiscal period, or a stock receipt and a stock count reconciling
the same product must never corrupt a balance or silently drop an update.

**Row-level locking for balance updates.** `inventory_items.quantity_on_hand` and
`bank_accounts.current_balance` are running totals updated by concurrent transactions; they are
always updated via `SELECT ... FOR UPDATE` inside the owning transaction, never via a bare
`UPDATE ... SET quantity_on_hand = quantity_on_hand + x` racing against a read elsewhere that assumes
a stale snapshot:

```sql
BEGIN;
SELECT quantity_on_hand FROM inventory_items
WHERE company_id = 4821 AND product_id = 9012 AND warehouse_id = 3
FOR UPDATE;
-- application computes new quantity
UPDATE inventory_items
SET quantity_on_hand = quantity_on_hand + 120.0000, updated_at = now()
WHERE company_id = 4821 AND product_id = 9012 AND warehouse_id = 3;
COMMIT;
```

`FOR UPDATE` takes a row-level exclusive lock that forces a second concurrent transaction targeting
the same row to wait for the first to commit or roll back, rather than both reading the same stale
value and one silently overwriting the other's update (the classic lost-update anomaly, which
`READ COMMITTED` alone does not prevent for read-then-write patterns).

**Trigger-maintained running balances, still lock-safe.** In practice, `quantity_on_hand` is
maintained by an `AFTER INSERT` trigger on `stock_movements` rather than application code, but the
trigger itself performs the equivalent locked update, and — critically — the trigger's `UPDATE`
statement is a single atomic `SET quantity_on_hand = quantity_on_hand + NEW.quantity` (letting
PostgreSQL's own row lock on the `UPDATE` serialize concurrent writers), which is safe without an
explicit `FOR UPDATE` because there is no intervening read-then-decide step in application code:

```sql
CREATE OR REPLACE FUNCTION fn_apply_stock_movement() RETURNS TRIGGER AS $$
BEGIN
    UPDATE inventory_items
    SET quantity_on_hand = quantity_on_hand +
            CASE WHEN NEW.movement_type IN ('receipt', 'adjustment_in') THEN NEW.quantity
                 ELSE -NEW.quantity END,
        updated_at = now()
    WHERE company_id = NEW.company_id
      AND product_id = NEW.product_id
      AND warehouse_id = NEW.warehouse_id;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_apply_stock_movement
AFTER INSERT ON stock_movements
FOR EACH ROW EXECUTE FUNCTION fn_apply_stock_movement();
```

**Journal posting — transactional balance enforcement.** Posting a journal entry must guarantee
`SUM(debits) = SUM(credits)` atomically; this is enforced with a deferred constraint trigger that
checks the balance at transaction *commit* time, not per-row, because the individual `journal_lines`
inserts within one entry are legitimately unbalanced one row at a time:

```sql
CREATE OR REPLACE FUNCTION fn_check_journal_balance() RETURNS TRIGGER AS $$
DECLARE
    v_diff NUMERIC(19,4);
BEGIN
    SELECT SUM(debit_amount) - SUM(credit_amount) INTO v_diff
    FROM journal_lines
    WHERE journal_entry_id = NEW.journal_entry_id AND deleted_at IS NULL;

    IF v_diff != 0 THEN
        RAISE EXCEPTION 'Journal entry % is unbalanced by %', NEW.journal_entry_id, v_diff;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER trg_check_journal_balance
AFTER INSERT OR UPDATE ON journal_lines
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION fn_check_journal_balance();
```

`DEFERRABLE INITIALLY DEFERRED` means the check runs once at `COMMIT`, after all of an entry's lines
have been inserted — an entry with 6 lines does not fail on line 1 just because the running sum is
non-zero mid-transaction.

**Advisory locks for period-close serialization.** Closing a fiscal period (locking it against
further postings) must not race against an in-flight posting to that same period. Rather than locking
the entire `journal_entries`/`journal_lines` table set (which would stall unrelated companies),
QAYD uses a session-scoped advisory lock keyed on `(company_id, fiscal_period_id)`, hashed into the
two-integer form `pg_advisory_xact_lock` expects:

```sql
-- Both the posting path and the period-close path acquire this lock before proceeding.
SELECT pg_advisory_xact_lock(
    hashtext('fiscal_period_lock'),
    hashtext(4821 || ':' || 118)::int
);
```

Because it is `pg_advisory_xact_lock` (transaction-scoped, auto-released at commit/rollback — not the
session-scoped variant), it is safe under PgBouncer transaction pooling per `Connection Pooling`: the
lock cannot outlive the transaction that took it and leak onto a different pooled client.

**Isolation level policy.** QAYD runs at `READ COMMITTED` (the PostgreSQL default) for the vast
majority of transactions — correct because the row-locking and advisory-locking patterns above
already close the specific race conditions that matter, and `READ COMMITTED` has no serialization-
failure retry burden on the application. `SERIALIZABLE` is used narrowly, only for the fiscal-period
close operation itself (which reads and reasons about aggregate state — "are there any unposted
draft entries in this period?" — across the whole period, a true read-then-decide-then-write
invariant that row locks alone cannot express):

```sql
BEGIN ISOLATION LEVEL SERIALIZABLE;
-- checks for unposted drafts, then flips fiscal_periods.status = 'closed'
COMMIT;
-- Application catches 40001 (serialization_failure) and retries with backoff, per Laravel:
```

```php
DB::transaction(function () use ($periodId) {
    DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    // ... close logic
}, 3);   // Laravel's built-in retry count for deadlock/serialization exceptions
```

**Deadlock avoidance via consistent lock ordering.** Any transaction that must lock multiple rows
(e.g. a stock transfer debiting one warehouse's `inventory_items` row and crediting another's) always
acquires locks in a fixed, deterministic order — sorted by `(warehouse_id, product_id)` ascending —
so that two concurrent transfers moving stock in opposite directions between the same two warehouses
cannot each acquire one lock and wait on the other's:

```sql
-- Always lock the lower (warehouse_id, product_id) tuple first, regardless of transfer direction
SELECT * FROM inventory_items
WHERE (company_id, warehouse_id, product_id) IN (
    (4821, 3, 9012), (4821, 7, 9012)
)
ORDER BY warehouse_id
FOR UPDATE;
```

# Scaling

QAYD's scaling strategy follows a fixed sequence — vertical scaling first (it is free of
application complexity), then read replicas, then partitioning, then sharding by tenant — and each
step is triggered by a named, measured threshold from `Performance Targets`, not applied
speculatively.

**Vertical scaling ceiling.** The primary is scaled up (more vCPU/RAM) whenever sustained CPU
utilization crosses the 60% yellow threshold with query plans already confirmed optimal (no
low-hanging index fixes outstanding) — this is the cheapest lever and is exhausted before any
architectural change. The practical ceiling on current-generation cloud instances (roughly 64–96
vCPU, 512 GB–1 TB RAM) comfortably serves QAYD's projected multi-thousand-tenant shared-cluster tier;
larger dedicated tenants are isolated to their own instance per `DATABASE_ARCHITECTURE.md` well
before this ceiling is a concern for them specifically.

**Read replica fan-out.** Once reporting/AI-analysis read load competes with OLTP write load for
buffer cache and I/O (visible as a cache-hit-ratio dip correlated with report execution windows),
additional streaming replicas are added and reporting traffic is routed to them via the PgBouncer
`qayd_prod_ro` pool from `Connection Pooling`. Replicas are added horizontally (N+1) with no
application change beyond registering the new host in the read pool's `[databases]` block — this is
the second-cheapest lever and covers essentially all read-scaling needs since QAYD's write volume
(journal postings, invoices, stock movements) is orders of magnitude smaller than its read volume
(dashboards, reports, AI context queries).

**Table partitioning for the largest append-only tables.** `journal_lines`, `stock_movements`, and
`audit_logs` are partitioned by `fiscal_year` (accounting tables) or by month (operational logs)
once a single tenant's table crosses roughly 50M rows — partition pruning then lets the planner skip
entire partitions instead of scanning a monolithic table, and old partitions can be moved to cheaper
storage or dropped per retention policy without a table-wide `DELETE`:

```sql
CREATE TABLE journal_lines (
    id                BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    journal_entry_id  BIGINT NOT NULL REFERENCES journal_entries(id),
    account_id        BIGINT NOT NULL REFERENCES accounts(id),
    fiscal_year       INT NOT NULL,
    fiscal_period_id  BIGINT NOT NULL REFERENCES fiscal_periods(id),
    debit_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    cost_center_id    BIGINT REFERENCES cost_centers(id),
    project_id        BIGINT REFERENCES projects(id),
    branch_id         BIGINT REFERENCES branches(id),
    created_by        BIGINT REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ,
    PRIMARY KEY (id, fiscal_year)
) PARTITION BY RANGE (fiscal_year);

CREATE TABLE journal_lines_2025 PARTITION OF journal_lines
    FOR VALUES FROM (2025) TO (2026);
CREATE TABLE journal_lines_2026 PARTITION OF journal_lines
    FOR VALUES FROM (2026) TO (2027);

-- Indexes are created per-partition automatically when created on the parent (PG 11+)
CREATE INDEX idx_journal_lines_company_account
    ON journal_lines (company_id, account_id, fiscal_period_id);
```

`PRIMARY KEY (id, fiscal_year)` — the partition key must be part of every unique constraint in a
partitioned table; QAYD's `id` sequence remains globally unique via `GENERATED ALWAYS AS IDENTITY`
on a single sequence shared across partitions, so the composite key is a formality for PostgreSQL's
constraint requirement rather than a true compound identity.

A query filtered by `fiscal_year` now prunes to a single partition:

```
Index Scan using idx_journal_lines_2026_company_account on journal_lines_2026 journal_lines
  (cost=0.56..412.10 rows=890 width=88)
Planning Time: 0.402 ms   -- partition pruning happens at planning time when the filter is a constant
```

New yearly partitions are created by a scheduled job ahead of each fiscal year boundary; partitions
older than the platform's mandatory financial-record retention period (7 years, configurable per
jurisdiction in `zaman-compliance-regulatory`-equivalent accounting compliance settings) are detached
and archived to Cloudflare R2 as Parquet exports rather than dropped outright, preserving audit
access without keeping cold data in hot storage.

**Tenant sharding — the scaling ceiling beyond partitioning.** If a future scale tier requires it
(a single shared cluster approaching hardware ceilings even after partitioning and replicas), the
migration path is tenant-based sharding: `company_id` ranges are assigned to separate PostgreSQL
clusters, with a lightweight routing layer (a `company_id → cluster` lookup, cached in Redis, that
the Laravel connection resolver consults per request) directing each request to its tenant's cluster.
This is deliberately not built preemptively — it adds operational complexity (cross-shard reporting,
migration tooling, backup/restore per shard) that is only worth paying for once vertical scaling,
replicas, and partitioning are demonstrably insufficient, which is not the case at QAYD's current or
near-term projected scale.

**Write scaling via batching, not sharding, for now.** The write path (journal postings, invoice
creation) is scaled by application-level batching (bulk `INSERT` patterns from `Query Optimization`)
and by keeping transactions short (advisory locks scoped to the minimum critical section in
`Concurrency`) long before a single-writer PostgreSQL primary becomes the bottleneck — QAYD's
measured write volume per tenant (a few hundred to a few thousand financial-line inserts per business
day) is far below what a well-tuned single primary handles.

# Benchmarks

Every claimed performance number in this document is backed by a `pgbench`-driven or custom-script
benchmark run against a database seeded to realistic and 10x-realistic data volumes on staging
hardware matching production instance types. Benchmarks are re-run before every major release that
touches a hot table's schema or indexing, and results are stored in `benchmarks/results/*.json` for
trend tracking.

**Seed data volumes used for benchmarking** (per representative tenant company, "10x" column used for
stress testing beyond the largest known real tenant):

| Table | Typical tenant | Large tenant | 10x stress test |
|---|---|---|---|
| `journal_lines` | 45,000 | 480,000 | 4,800,000 |
| `invoices` | 3,200 | 38,000 | 380,000 |
| `stock_movements` | 12,000 | 210,000 | 2,100,000 |
| `customers` | 400 | 6,500 | 65,000 |
| `products` | 850 | 14,000 | 140,000 |

Across the full shared cluster, these per-tenant volumes are multiplied by ~5,000 co-located
companies for realistic cross-tenant contention testing — the cluster-wide `journal_lines` table in
the benchmark rig holds approximately 225M rows at the "typical tenant × 5,000" scale used for the
plans captured in `Execution Plans` and `Materialized Views`.

**`pgbench` custom OLTP script** modeling the journal-posting transaction (the platform's most
latency-sensitive write path):

```sql
-- benchmarks/post_journal_entry.sql
\set company_id random(1, 5000)
\set account_id random(1, 340)
\set amount random(100, 500000) / 100.0

BEGIN;
INSERT INTO journal_entries (company_id, fiscal_period_id, status, created_at, updated_at)
VALUES (:company_id, 118, 'draft', now(), now())
RETURNING id AS entry_id \gset

INSERT INTO journal_lines (company_id, journal_entry_id, account_id, fiscal_year,
                            fiscal_period_id, debit_amount, credit_amount, created_at, updated_at)
VALUES
    (:company_id, :entry_id, :account_id, 2026, 118, :amount, 0, now(), now()),
    (:company_id, :entry_id, 112, 2026, 118, 0, :amount, now(), now());

UPDATE journal_entries SET status = 'posted', updated_at = now() WHERE id = :entry_id;
COMMIT;
```

```bash
pgbench -h pg-primary.internal -U qayd_bench -d qayd_bench \
    -f benchmarks/post_journal_entry.sql \
    -c 50 -j 8 -T 300 -P 10 --rate-limit=400
```

**Representative benchmark result** (50 concurrent clients, 8 threads, 300s duration, against the
225M-row cluster-scale `journal_lines` seed):

```
transaction type: benchmarks/post_journal_entry.sql
scaling factor: 1
query mode: simple
number of clients: 50
number of threads: 8
duration: 300 s
number of transactions actually processed: 118482
latency average = 12.658 ms
latency stddev = 8.204 ms
tps = 394.891267 (including connections establishing)
statement latencies in milliseconds:
   0.412  BEGIN
   3.881  INSERT INTO journal_entries ... RETURNING id
   4.204  INSERT INTO journal_lines (2 rows)
   3.618  UPDATE journal_entries SET status
   0.543  COMMIT
```

394 TPS sustained against a rate-limited target of 400 TPS confirms the posting path holds the
target rate with headroom (mean latency 12.7 ms, comfortably inside the < 15 ms p50 target from
`Performance Targets`) even at full cluster scale with cross-tenant contention.

**Read-path benchmark — trial balance under concurrent load:**

```bash
pgbench -h pg-replica-1.internal -U qayd_bench -d qayd_bench \
    -f benchmarks/trial_balance_query.sql \
    -c 100 -j 16 -T 180 -S
```

```
number of transactions actually processed: 812940
latency average = 2.215 ms
latency average = 2.215 ms
tps = 4515.221094 (including connections establishing)
```

**Index build time benchmark** (informs maintenance-window planning for non-concurrent index changes
on staging, where `CONCURRENTLY` is skipped to save time since staging tolerates a lock):

```sql
\timing on
CREATE INDEX idx_bench_test ON journal_lines (company_id, account_id, fiscal_period_id);
-- Time: 41822.104 ms  (≈ 42s for 225M rows on staging's NVMe-backed instance)
```

**Regression gate.** CI runs a lightweight benchmark subset (10,000 transactions of the posting
script, 10 clients) on every release candidate against a fixed-seed staging snapshot; a mean latency
regression greater than 20% versus the last release's recorded baseline fails the release pipeline
and requires explicit sign-off to override.

# Anti-Patterns

This section catalogs the specific mistakes this codebase has hit, or is structurally exposed to, and
the rule that prevents each one.

**Unbounded `IN` lists generated from application arrays.** Passing thousands of IDs into a single
`WHERE id IN (...)` (e.g. "all invoice IDs for this customer" built in PHP then interpolated) produces
a huge parse tree, defeats plan caching, and can exceed reasonable query text size. Fix: push the
filter into a join against a temp table/CTE values list, or paginate the operation:

```sql
-- WRONG (conceptually — thousands of literals)
SELECT * FROM invoices WHERE id IN (1,2,3, /* ... 8000 more ... */);

-- RIGHT — VALUES list joined, or better, filter by the actual predicate (customer_id) directly
SELECT i.* FROM invoices i
JOIN (VALUES (1),(2),(3)) AS ids(id) ON ids.id = i.id;
-- or, almost always the right fix: query by customer_id + status instead of pre-fetching IDs in PHP
```

**`SELECT *` on wide tables.** Already forbidden by the `qayd/no-select-star` CI rule referenced in
`Query Optimization`; the anti-pattern recurs specifically when a developer adds a quick debug query
and forgets to narrow it before merging — CI catches this at the static-analysis layer, not at code
review discretion.

**Implicit type coercion defeating indexes.** `journal_lines.account_id` is `BIGINT`; a query built by
a naive ORM call passing a string (`WHERE account_id = '112'` where `'112'` arrives as text from an
HTTP query parameter never cast) forces an implicit cast on the *indexed* column in older planner
behavior or, worse, on some comparison paths, silently degrades to a scan. QAYD's FormRequest layer
casts every ID parameter to `int` before it reaches the query builder — enforced by a typed DTO, not
by hoping the ORM does the right thing:

```php
class JournalLineFilterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['account_id' => (int) $this->input('account_id')]);
    }
}
```

**Long-lived transactions wrapping non-database work.** Calling out to the FastAPI AI layer, an
external tax API, or Cloudflare R2 upload *inside* an open `DB::transaction()` holds row/advisory
locks and blocks vacuum for the duration of a network call that can take seconds. The rule: a
transaction wraps only the database statements; any external call happens before the transaction
opens or after it commits, with the transaction re-opened briefly afterward if a result must be
persisted:

```php
// WRONG — network call inside the transaction holds locks for its full duration
DB::transaction(function () use ($invoiceId) {
    $invoice = Invoice::lockForUpdate()->find($invoiceId);
    $taxResult = Http::post('https://tax-api.example/calculate', [...])->json(); // seconds, lock held
    $invoice->update(['tax_amount' => $taxResult['amount']]);
});

// RIGHT — network call outside, transaction only wraps the write
$taxResult = Http::post('https://tax-api.example/calculate', [...])->json();
DB::transaction(function () use ($invoiceId, $taxResult) {
    $invoice = Invoice::lockForUpdate()->find($invoiceId);
    $invoice->update(['tax_amount' => $taxResult['amount']]);
});
```

**Missing `LIMIT` on exploratory/admin queries against production.** An ad-hoc `SELECT * FROM
stock_movements WHERE company_id = X` run from a psql session during an investigation, with no
`LIMIT`, against a company with 2M rows, both wastes I/O and can return a result set large enough to
pressure the connection's memory. Standard practice for any interactive investigation query is
`LIMIT 100` by default, widened deliberately only when a full scan is actually the intent.

**Using `COUNT(*)` for existence checks.** `SELECT COUNT(*) FROM invoices WHERE customer_id = X`
to check "does this customer have any invoices" scans (or index-scans) every matching row just to
discard the count past 1. `EXISTS` (already covered in `Query Optimization`) or
`SELECT 1 ... LIMIT 1` stops at the first match:

```sql
-- WRONG
SELECT COUNT(*) FROM invoices WHERE customer_id = 4821 AND deleted_at IS NULL;
-- (then application code checks if count > 0)

-- RIGHT
SELECT EXISTS (SELECT 1 FROM invoices WHERE customer_id = 4821 AND deleted_at IS NULL);
```

**Polymorphic `attachments` table joined without a type filter, at scale.** The foundation
`attachments` table (`attachable_type`, `attachable_id`) is polymorphic across every entity in the
platform; a query joining it without first filtering `attachable_type` forces the planner to consider
matches across every unrelated entity type before the `attachable_id` predicate narrows it. The
composite index `(attachable_type, attachable_id)` — leading with type — and always including the
type literal in the query, keeps this join tight:

```sql
CREATE INDEX idx_attachments_polymorphic ON attachments (attachable_type, attachable_id);

SELECT * FROM attachments
WHERE attachable_type = 'invoice' AND attachable_id = 88213;   -- both predicates always present
```

**Trusting the query cache/materialized view as a source of truth in code comments or new features.**
Every engineer adding a new report is required to trace whether the underlying data should read from
`journal_lines` directly (correctness-critical, low-volume-enough to afford it) or `ledger_entries`
(the projection). Defaulting to "just query the materialized view, it's there" for a *new* correctness-
critical check (e.g. the period-close draft-entry check in `Concurrency`) without this deliberate
choice has caused near-misses in review; the checklist item is now explicit in the PR template for any
change touching `journal_lines`/`ledger_entries`.

# Examples

**Example 1 — end-to-end optimization of the family-facing "AR aging by customer" report**, combining
several techniques from this document in sequence.

Starting query (naive, direct from `journal_lines`, no supporting index, full amount recomputation):

```sql
SELECT c.name_en, c.name_ar,
       SUM(i.balance_due) AS total_due,
       SUM(CASE WHEN CURRENT_DATE - i.due_date > 30 THEN i.balance_due ELSE 0 END) AS overdue_30
FROM customers c
JOIN invoices i ON i.customer_id = c.id
WHERE c.company_id = 4821 AND i.status IN ('unpaid', 'partially_paid') AND i.deleted_at IS NULL
GROUP BY c.id, c.name_en, c.name_ar
ORDER BY total_due DESC;
```

Baseline plan (no targeted index — relies on the generic `idx_invoices_company_id`):

```
HashAggregate  (cost=4821.20..4902.11 rows=810 width=112)
               (actual time=88.204..89.912 rows=642 loops=1)
  ->  Hash Join  (cost=210.40..4602.80 rows=8842 width=88)
        (actual time=2.108..71.204 rows=8842 loops=1)
        ->  Seq Scan on invoices i (cost=0.00..4102.00 rows=8842 width=48)
              (actual time=0.024..58.884 rows=8842 loops=1)
              Filter: ((company_id = 4821) AND (status = ANY ('{unpaid,partially_paid}'::text[])) AND (deleted_at IS NULL))
              Rows Removed by Filter: 31200
        ->  Hash  (cost=204.10..204.10 rows=504 width=48)
Planning Time: 0.284 ms
Execution Time: 90.204 ms
```

Applying the `idx_invoices_aging` partial composite index from `Indexes`:

```
HashAggregate  (cost=612.40..693.31 rows=810 width=112)
               (actual time=6.204..7.102 rows=642 loops=1)
  ->  Hash Join  (cost=140.20..504.80 rows=8842 width=88)
        (actual time=0.884..4.981 rows=8842 loops=1)
        ->  Index Scan using idx_invoices_aging on invoices i
              (cost=0.42..384.10 rows=8842 width=48)
              (actual time=0.031..2.204 rows=8842 loops=1)
              Index Cond: ((company_id = 4821) AND (status = ANY ('{unpaid,partially_paid}'::text[])))
        ->  Hash  (cost=134.10..134.10 rows=504 width=48)
Planning Time: 0.201 ms
Execution Time: 7.884 ms
```

11.4x from the index alone (90.2 ms → 7.9 ms). Finally, replacing the live aggregation with the
`customer_aging_summary` materialized view from `Materialized Views`:

```sql
SELECT c.name_en, c.name_ar, cas.current_bucket + cas.days_1_30 + cas.days_31_60
       + cas.days_61_90 + cas.days_90_plus AS total_due,
       cas.days_31_60 + cas.days_61_90 + cas.days_90_plus AS overdue_30
FROM customer_aging_summary cas
JOIN customers c ON c.id = cas.customer_id
WHERE cas.company_id = 4821
ORDER BY total_due DESC;
```

```
Sort  (cost=210.40..212.42 rows=642 width=112) (actual time=0.884..0.921 rows=642 loops=1)
  ->  Hash Join  (cost=42.10..190.20 rows=642 width=112)
        (actual time=0.204..0.612 rows=642 loops=1)
        ->  Index Scan using idx_customer_aging_pk on customer_aging_summary cas
              (cost=0.28..120.40 rows=642 width=72)
              (actual time=0.028..0.288 rows=642 loops=1)
              Index Cond: (company_id = 4821)
Planning Time: 0.198 ms
Execution Time: 1.041 ms
```

**86x** improvement over the indexed live query, **86.6x** over the original baseline (90.2 ms →
1.04 ms), landing well inside the < 100 ms p50 target for AR/AP aging in `Performance Targets`.

**Example 2 — diagnosing and fixing a connection-pool exhaustion incident.** Symptom: API 500s
spiking, application logs showing `SQLSTATE[08006] could not connect to server: Connection refused`.
Triage per `Slow Queries` and `Connection Pooling`:

```sql
SHOW POOLS;
--  database  | user | cl_active | cl_waiting | sv_active | sv_idle | sv_used | maxwait
--  qayd_prod | app  |      60   |    340     |    60     |    0    |   0     |  4.821
```

`cl_waiting = 340` with `sv_idle = 0` confirms every pooled server connection is checked out and 340
client requests are queued behind them. Cross-referencing `pg_stat_activity` on the primary:

```sql
SELECT state, count(*) FROM pg_stat_activity GROUP BY state;
--  state               | count
--  active              |   58
--  idle in transaction |    2
```

Two `idle in transaction` sessions, each holding a server connection for no active work — the actual
root cause. Identifying them:

```sql
SELECT pid, application_name, now() - xact_start AS duration, query
FROM pg_stat_activity WHERE state = 'idle in transaction';
--  pid   | application_name | duration  | query
--  88214 | qayd-worker-07   | 00:12:41  | UPDATE invoices SET status = 'paid' ...
--  88322 | qayd-worker-11   | 00:08:02  | INSERT INTO journal_lines ...
```

Both traced to a Laravel job that opened `DB::beginTransaction()` manually (bypassing
`DB::transaction()`'s automatic rollback-on-exception) and threw an unrelated validation exception
before reaching `DB::commit()`, leaking the transaction indefinitely. Immediate mitigation: terminate
the two stuck backends (`pg_terminate_backend`) to free the pool; systemic fix: replace manual
`beginTransaction`/`commit` pairs with `DB::transaction(callback)` platform-wide (enforced via a
static-analysis rule, `qayd/no-manual-transaction`), plus the `idle_in_transaction_session_timeout =
60000` backstop from `Vacuum` that now guarantees this class of leak self-terminates within 60
seconds even if a future code path reintroduces the pattern.

**Example 3 — capacity-planning calculation for an upcoming large-tenant onboarding.** A new tenant
projects 1.2M `journal_lines` rows per year and 90,000 `invoices` per year. Applying the benchmark
figures from `Benchmarks` (mean posting latency 12.7 ms at 225M-row cluster scale, cache hit ratio
targets from `Performance Targets`), the projected addition (well under the 4.8M-row "10x stress
test" seed per table) requires no architectural change — it is absorbed by existing partitioning
(`journal_lines_2026` partition already sized for this range) and the existing replica fleet for its
reporting load. The onboarding runbook requires only: (1) provisioning the standard index set from
`Indexes` at tenant setup (already automatic via the `companies` creation migration hook), (2)
confirming the `ledger_entries` and `customer_aging_summary` materialized views' `REFRESH
CONCURRENTLY` cadence tolerates the added row volume within its existing 15-minute schedule (verified
against the benchmark's demonstrated sub-2-second full-cluster refresh time), and (3) a post-onboarding
`ANALYZE` pass on the affected tables per `Analyze` to ensure the planner has accurate statistics for
the new tenant's data from day one rather than waiting for the autovacuum analyze threshold to trigger
organically.

# End of Document
