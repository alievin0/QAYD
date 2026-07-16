# Table Partitioning For Scale — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Table Partitioning For Scale
---

# Purpose

QAYD is a multi-tenant AI Financial Operating System. A small number of tables absorb the vast
majority of write volume and long-term storage: `journal_lines`, `ledger_entries`, `audit_logs`,
`stock_movements`, and `notifications`. In a single-company deployment these tables grow slowly, but
QAYD is multi-tenant — thousands of companies write to the same physical tables, every one of them
indexed on `company_id`, and every posted financial row is retained forever (soft-deleted, never
purged). Left unpartitioned, these tables will eventually exceed the point where a single B-tree index
can be maintained cheaply, where `VACUUM` completes in a reasonable window, and where hot data (the
current fiscal period) stays fully cached in shared buffers.

This document specifies how QAYD partitions its largest PostgreSQL 15+ tables: which tables qualify,
which partitioning strategy (RANGE, LIST, or HASH) applies to each, the exact DDL to create and
maintain partitions, how partitions are created automatically as time and tenants advance, how indexes
and constraints behave under partitioning, how old partitions are archived out of the hot dataset, how
an already-populated table is migrated to a partitioned layout without downtime, and how the Laravel
12 application layer (Eloquent, query builder, migrations) must be written to get partition pruning
instead of a full partition scan. It assumes the standard QAYD column set (`id`, `company_id`,
`branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) and the canonical
table names already defined for the Accounting, Inventory, and platform foundation modules
(`journal_entries`, `journal_lines`, `ledger_entries`, `audit_logs`, `stock_movements`,
`notifications`).

Partitioning in QAYD is an infrastructure decision, not a business-logic decision. It must never change
API responses, permission checks, or accounting semantics — a posted `journal_line` is exactly as
immutable and exactly as visible to the same company after partitioning as before. What changes is
physical storage: PostgreSQL routes each row to one of several child tables based on a partition key,
so that queries and maintenance operations that only need a slice of the data (one fiscal month, one
company, one calendar day) never have to touch the rest.

# When To Partition

Partitioning adds operational complexity (partition management, migration risk, more moving parts in
backups and `EXPLAIN` plans) and is not the default posture for every table. QAYD applies it selectively,
using these triggers:

| Signal | Threshold | Rationale |
|---|---|---|
| Table size | > 50 GB, or projected to cross it within 12 months | Autovacuum and index maintenance start to dominate I/O on tables this size. |
| Row count | > 100 million rows | B-tree index depth and bloat become expensive to rebuild (`REINDEX` on 100M+ rows is a multi-hour operation). |
| Write rate | > 500 inserts/sec sustained, or bursty batch writes (e.g. month-end journal posting, daily stock movement batches) | High insert rate on one large index causes lock contention and WAL bloat; smaller per-partition indexes absorb bursts better. |
| Query pattern | Most queries filter by a natural range or bucket (date, fiscal period, company) that maps to a small subset of the data | This is a precondition for partition pruning to pay off — if queries scan across all buckets, partitioning adds overhead with no pruning benefit. |
| Retention policy | Data has a defined lifecycle (archive after N years, roll off oldest month) | Dropping/detaching a partition is an O(1) metadata operation; `DELETE FROM ... WHERE created_at < ...` on a monolithic table is an O(n) operation that bloats the table and re-triggers autovacuum. |
| Multi-tenancy skew | A small number of very large companies dominate total rows | HASH or LIST partitioning by `company_id` isolates a noisy tenant's I/O pattern from the rest. |

QAYD does **not** partition tables that are small by nature (`accounts`, `customers`, `vendors`,
`products`, `bank_accounts`), tables whose access pattern is always full-table or company-scoped-small
(`fiscal_years`, `fiscal_periods`, `tax_codes`), or tables where foreign-key relationships to a
partitioned parent would force excessive join complexity without a clear win. The five tables that meet
the bar today are enumerated in **Candidate Tables** below; any future table that crosses the
thresholds above must go through the same evaluation before a partitioning migration is scheduled.

# PostgreSQL Declarative Partitioning (RANGE, LIST, HASH)

PostgreSQL 15 partitioning is **declarative**: the parent table is created with `PARTITION BY`, and
child partitions are created with `PARTITION OF ... FOR VALUES ...`. The parent table stores no rows of
its own — every row physically lives in exactly one child partition, chosen by the value of the
partition key at INSERT time. Three partitioning strategies apply to QAYD's tables:

**RANGE** — partitions hold a contiguous range of key values. This is the natural fit for anything
keyed by time (`created_at`, `posting_date`, `fiscal_period`), because queries filtering `WHERE
created_at >= X AND created_at < Y` prune to exactly the partitions that overlap `[X, Y)`, and old
partitions can be dropped/archived wholesale once they age out.

```sql
CREATE TABLE audit_logs (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id    BIGINT NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- ... remaining columns ...
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
```

**LIST** — partitions hold an explicit, enumerated set of key values. This fits `company_id` when QAYD
wants to dedicate one or more partitions to specific large tenants (isolating their I/O and allowing
per-tenant maintenance windows) while defaulting everyone else into a shared partition.

```sql
CREATE TABLE stock_movements (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id    BIGINT NOT NULL,
    -- ... remaining columns ...
    PRIMARY KEY (id, company_id)
) PARTITION BY LIST (company_id);
```

**HASH** — partitions hold an evenly distributed slice of key values, chosen by PostgreSQL's internal
hash of the key modulo the partition count. This fits `company_id` when QAYD wants to spread tenant
write load evenly across N partitions without maintaining an explicit per-tenant list — the tradeoff is
that a query for a single company still has to consult one partition (good), but a query that needs to
scan "all companies for date X" cannot prune by date alone if the table is HASH-partitioned only by
`company_id` (this is why several of QAYD's largest tables use **composite** RANGE-then-HASH
sub-partitioning, described in Candidate Tables).

```sql
CREATE TABLE ledger_entries (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id    BIGINT NOT NULL,
    -- ... remaining columns ...
    PRIMARY KEY (id, company_id)
) PARTITION BY HASH (company_id);
```

All three strategies share the same rules in PostgreSQL 15:

- The partition key **must** be part of every unique index and the primary key on the parent (hence the
  composite primary keys above — `id` alone cannot be the sole primary key on a partitioned table
  unless it is also the partition key).
- `CHECK` constraints, `NOT NULL`, and column defaults defined on the parent are inherited by every
  partition automatically.
- Partitions can themselves be partitioned (sub-partitioning), which QAYD uses for `journal_lines`,
  `ledger_entries`, and `audit_logs` — RANGE by date at the top level, then HASH by `company_id` inside
  each date range, so both date-scoped and company-scoped queries prune well.
- `INSERT ... ON CONFLICT` works normally, but the conflict target must include the partition key.
- Foreign keys **referencing** a partitioned table are supported in PostgreSQL 12+; QAYD avoids putting
  FKs *from* other tables directly onto partitioned children (see Edge Cases) and instead validates
  referential integrity at the Laravel Service layer plus a database trigger where strict enforcement is
  required.

# Candidate Tables

| Table | Strategy | Partition Key | Sub-Partition | Rationale |
|---|---|---|---|---|
| `journal_lines` | RANGE by month, sub-partitioned HASH by `company_id` (8-way) | `posting_date` (derived from parent `journal_entries.entry_date`) | HASH(`company_id`, 8) | Highest-volume table in the system; nearly all reporting queries (Trial Balance, GL, financial statements) are period-scoped. Retention is permanent (immutable ledger) so partitions accumulate but are never dropped — only detached to cold storage after the fiscal year closes. |
| `ledger_entries` | RANGE by month, sub-partitioned HASH by `company_id` (8-way) | `posted_at` | HASH(`company_id`, 8) | Same profile as `journal_lines` — it is the posted-line projection. Partition boundaries are kept identical to `journal_lines` so a month's worth of both tables can be archived together. |
| `audit_logs` | RANGE by month | `created_at` | none | Append-only, extremely high volume (every mutation across every module), queried almost exclusively by a recent time window or a specific `company_id` + time window. No sub-partitioning needed at current projected scale; revisit HASH sub-partitioning if a single company's audit volume dominates. |
| `stock_movements` | RANGE by month, sub-partitioned LIST by `company_id` for the top 5 tenants by volume, remainder in a `DEFAULT` list partition | `movement_date` | LIST(`company_id`) | Inventory-heavy tenants (large retail/warehouse operations) generate movement volume disproportionate to their peer companies. LIST isolates their partitions for targeted maintenance (reindex, vacuum) without affecting the shared tenant pool. |
| `notifications` | RANGE by month | `created_at` | none | High insert volume, short useful life (read/dismissed within days), aggressively pruned via `DROP PARTITION` after 90 days per the retention policy — RANGE-by-month is what makes that an O(1) operation. |
| *(general option)* | HASH by `company_id` | `company_id` | — | Reserved for any future very-large single-purpose table where access is always single-tenant and never needs a time-range scan (e.g. a future `ai_embeddings` or `document_ocr_pages` table). Not applied to any table in this document today. |

# Partition Key Selection

Choosing the wrong partition key is the most common partitioning mistake — it either fails to prune
(query costs stay high) or fragments maintenance work in a way that defeats the original goal. QAYD
applies these rules when selecting a key:

1. **The key must appear in the WHERE clause of the majority of production queries against that
   table.** `journal_lines` and `ledger_entries` are read almost exclusively through
   period-bounded reports (Trial Balance for fiscal period X, GL for date range Y) — hence RANGE by
   date. `stock_movements` is read through both date-bounded stock cards and company-bounded
   inventory valuation — hence the RANGE + LIST composite.
2. **The key must be immutable after insert.** QAYD never updates `posting_date`, `movement_date`, or
   `created_at` on a posted row; if a business process needed to change a partition key value it would
   require a `DELETE` + `INSERT` (PostgreSQL does not support moving a row across partitions via
   `UPDATE` unless the update is routed through the parent, and even then it is an expensive
   delete/insert internally). Immutability of the key is guaranteed by the immutable-ledger rule already
   in force for posted accounting rows.
3. **Avoid partitioning by `id`.** The surrogate identity key carries no business meaning and does not
   correlate with query filters; partitioning by it would only help extremely rare id-range scans.
4. **Prefer a key with a bounded, low-cardinality bucket count over the table's lifetime for RANGE.** A
   monthly bucket keeps individual partition sizes in the 1–10 GB range for QAYD's projected volumes —
   large enough to amortize partition-management overhead, small enough that a `VACUUM` or `REINDEX` on
   one partition finishes in minutes, not hours.
5. **For HASH, choose a partition count that is a power of two and matches expected parallel query
   workers** (`max_parallel_workers_per_gather`), so PostgreSQL's parallel sequential scan across
   partitions divides evenly. QAYD standardizes on 8-way HASH sub-partitions for `journal_lines` and
   `ledger_entries`.
6. **For LIST, keep an explicit `DEFAULT` partition** to catch any key value that has not yet been
   assigned its own partition (e.g. a newly onboarded very-large tenant before its dedicated LIST
   partition is provisioned) — otherwise inserts for unmapped values are rejected outright.

# Range Partitioning By Date (Monthly/Yearly)

`journal_lines`, `ledger_entries`, `audit_logs`, `stock_movements`, and `notifications` are all
top-level RANGE-partitioned by date, monthly. Monthly (rather than yearly) is chosen because QAYD's
per-tenant volume projections put a yearly partition for `journal_lines` at 40–120 GB by year three,
past the size where a single partition is easy to maintain; a monthly partition stays in the 3–10 GB
band. `audit_logs` and `notifications` follow the same monthly cadence for operational consistency, even
though their individual retention windows are shorter.

Full DDL for `audit_logs` (simplest case — RANGE only, no sub-partitioning):

```sql
CREATE TABLE audit_logs (
    id             BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    branch_id      BIGINT NULL REFERENCES branches(id),
    user_id        BIGINT NULL REFERENCES users(id),
    action         VARCHAR(64)  NOT NULL,          -- e.g. 'journal_entry.posted'
    auditable_type VARCHAR(128) NOT NULL,           -- polymorphic model class
    auditable_id   BIGINT       NOT NULL,
    old_values     JSONB NULL,
    new_values     JSONB NULL,
    reason         TEXT NULL,
    ip_address     INET NULL,
    user_agent     VARCHAR(512) NULL,
    device_id      VARCHAR(128) NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

CREATE INDEX idx_audit_logs_company_created  ON audit_logs (company_id, created_at);
CREATE INDEX idx_audit_logs_auditable        ON audit_logs (auditable_type, auditable_id);
CREATE INDEX idx_audit_logs_action           ON audit_logs (action);

-- Monthly child partitions (one CREATE TABLE per month; automated thereafter, see next section)
CREATE TABLE audit_logs_2026_07 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');

CREATE TABLE audit_logs_2026_08 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');

-- Catch-all so inserts never fail if the automated job falls behind
CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT;
```

Full DDL for `journal_lines` — RANGE by month at the top level, HASH by `company_id` at the second
level (this is the composite pattern used for both `journal_lines` and `ledger_entries`):

```sql
CREATE TABLE journal_lines (
    id               BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    branch_id        BIGINT NULL REFERENCES branches(id),
    journal_entry_id BIGINT NOT NULL,                     -- FK validated at application layer, see Edge Cases
    account_id       BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id   BIGINT NULL,
    project_id       BIGINT NULL,
    department_id    BIGINT NULL,
    debit            NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit           NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code    CHAR(3) NOT NULL,
    exchange_rate    NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_debit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_credit      NUMERIC(19,4) NOT NULL DEFAULT 0,
    memo             VARCHAR(512) NULL,
    posting_date     DATE NOT NULL,
    created_by       BIGINT NULL REFERENCES users(id),
    updated_by       BIGINT NULL REFERENCES users(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at       TIMESTAMPTZ NULL,
    CHECK (debit >= 0 AND credit >= 0),
    CHECK (NOT (debit > 0 AND credit > 0)),               -- a line is either a debit or a credit, never both
    PRIMARY KEY (id, posting_date, company_id)
) PARTITION BY RANGE (posting_date);

-- Second level: each monthly RANGE partition is itself HASH-partitioned 8 ways by company_id
CREATE TABLE journal_lines_2026_07 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01')
    PARTITION BY HASH (company_id);

CREATE TABLE journal_lines_2026_07_p0 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 0);
CREATE TABLE journal_lines_2026_07_p1 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 1);
CREATE TABLE journal_lines_2026_07_p2 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 2);
CREATE TABLE journal_lines_2026_07_p3 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 3);
CREATE TABLE journal_lines_2026_07_p4 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 4);
CREATE TABLE journal_lines_2026_07_p5 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 5);
CREATE TABLE journal_lines_2026_07_p6 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 6);
CREATE TABLE journal_lines_2026_07_p7 PARTITION OF journal_lines_2026_07
    FOR VALUES WITH (MODULUS 8, REMAINDER 7);
```

Yearly RANGE (used for archival tiers, not the live table — see Detaching & Archiving) follows the
identical pattern with `FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')`.

# List/Hash Partitioning By company_id

`stock_movements` uses LIST partitioning on `company_id` inside each monthly RANGE partition, dedicating
named partitions to the handful of tenants whose inventory volume dominates, with every other tenant
falling into a shared `DEFAULT` partition:

```sql
CREATE TABLE stock_movements (
    id              BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    warehouse_id    BIGINT NOT NULL,
    product_id      BIGINT NOT NULL,
    movement_type   VARCHAR(32) NOT NULL,   -- 'receipt' | 'issue' | 'transfer' | 'adjustment'
    quantity        NUMERIC(18,4) NOT NULL,
    unit_cost       NUMERIC(19,4) NULL,
    reference_type  VARCHAR(128) NULL,
    reference_id    BIGINT NULL,
    movement_date   DATE NOT NULL,
    created_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,
    PRIMARY KEY (id, movement_date, company_id)
) PARTITION BY RANGE (movement_date);

CREATE TABLE stock_movements_2026_07 PARTITION OF stock_movements
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01')
    PARTITION BY LIST (company_id);

-- Named partitions for the top-volume tenants (ids illustrative)
CREATE TABLE stock_movements_2026_07_c101 PARTITION OF stock_movements_2026_07
    FOR VALUES IN (101);
CREATE TABLE stock_movements_2026_07_c204 PARTITION OF stock_movements_2026_07
    FOR VALUES IN (204);

-- All remaining tenants
CREATE TABLE stock_movements_2026_07_default PARTITION OF stock_movements_2026_07 DEFAULT;
```

Pure HASH-by-`company_id` (single level, no date dimension) is reserved for a future table whose access
pattern is strictly single-tenant, illustrated here as the general-purpose pattern:

```sql
CREATE TABLE example_tenant_scoped_table (
    id          BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id  BIGINT NOT NULL REFERENCES companies(id),
    -- ...
    PRIMARY KEY (id, company_id)
) PARTITION BY HASH (company_id);

CREATE TABLE example_tenant_scoped_table_p0 PARTITION OF example_tenant_scoped_table
    FOR VALUES WITH (MODULUS 16, REMAINDER 0);
-- ... repeat REMAINDER 1 through 15 ...
```

Converting a `DEFAULT` LIST partition entry into its own named partition when a tenant grows large (a
routine QAYD operations task) requires detaching the row set first — PostgreSQL does not allow
`ATTACH PARTITION FOR VALUES IN (x)` if rows for `x` already exist in the `DEFAULT` partition:

```sql
BEGIN;
  -- 1. Create the new dedicated partition definition (attached to nothing yet)
  CREATE TABLE stock_movements_2026_07_c305 (LIKE stock_movements_2026_07 INCLUDING ALL);

  -- 2. Move matching rows out of the default partition
  WITH moved AS (
    DELETE FROM stock_movements_2026_07_default WHERE company_id = 305 RETURNING *
  )
  INSERT INTO stock_movements_2026_07_c305 SELECT * FROM moved;

  -- 3. Attach the populated table as a real partition
  ALTER TABLE stock_movements_2026_07
      ATTACH PARTITION stock_movements_2026_07_c305 FOR VALUES IN (305);
COMMIT;
```

# Automatic Partition Creation

Manually issuing `CREATE TABLE ... PARTITION OF` every month does not scale to QAYD's five partitioned
tables across a multi-year retention window. QAYD standardizes on **pg_partman** for RANGE-partitioned
tables, supplemented by a Laravel scheduled command that provisions LIST/HASH children for
`stock_movements` tenant promotions.

**pg_partman setup** (installed once per database):

```sql
CREATE EXTENSION IF NOT EXISTS pg_partman;

SELECT partman.create_parent(
    p_parent_table   := 'public.journal_lines',
    p_control        := 'posting_date',
    p_type           := 'range',
    p_interval       := '1 month',
    p_premake        := 3,              -- create 3 months ahead of time
    p_start_partition:= '2026-07-01'
);

UPDATE partman.part_config
   SET infinite_time_partitions = true,
       retention                = NULL,      -- journal_lines is never auto-dropped (immutable ledger)
       retention_keep_table     = true
 WHERE parent_table = 'public.journal_lines';
```

For `notifications`, which **is** subject to a hard retention window, pg_partman is configured to drop
old partitions automatically:

```sql
SELECT partman.create_parent(
    p_parent_table    := 'public.notifications',
    p_control         := 'created_at',
    p_type            := 'range',
    p_interval        := '1 month',
    p_premake         := 2,
    p_start_partition := '2026-07-01'
);

UPDATE partman.part_config
   SET retention              = '90 days',
       retention_keep_table   = false,     -- physically DROP, not just detach
       infinite_time_partitions = true
 WHERE parent_table = 'public.notifications';
```

pg_partman's own maintenance function must run on a schedule — QAYD invokes it hourly via `pg_cron`
(preferred, runs inside PostgreSQL) rather than an external cron job, so partition maintenance survives
independently of the application tier being up:

```sql
CREATE EXTENSION IF NOT EXISTS pg_cron;

SELECT cron.schedule(
    'partman-maintenance',
    '0 * * * *',                          -- top of every hour
    $$CALL partman.run_maintenance_proc()$$
);
```

Where `pg_cron` is unavailable (e.g. a managed PostgreSQL host that does not permit the extension), QAYD
falls back to a Laravel scheduled command that calls the same procedure over a database connection:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        DB::statement('CALL partman.run_maintenance_proc()');
    })->hourly()->name('partman-maintenance')->withoutOverlapping();
}
```

The 8-way HASH sub-partitions under each `journal_lines`/`ledger_entries` monthly RANGE partition are
static (HASH partition counts do not change month to month), so pg_partman's `template` mechanism is
used to create every new monthly RANGE partition already pre-populated with its 8 HASH children:

```sql
-- One-time: build a template table with the HASH sub-partition structure, then point pg_partman at it
UPDATE partman.part_config
   SET template_table = 'public.journal_lines_template'
 WHERE parent_table = 'public.journal_lines';
```

Provisioning a dedicated LIST partition for a newly large `stock_movements` tenant is not a time-based
event, so it stays outside pg_partman and is instead a Laravel Artisan command
(`php artisan partitions:promote-tenant {company_id}`) run manually by the platform team once a
tenant's volume crosses the threshold in **When To Partition**, executing the `BEGIN; ... ATTACH
PARTITION ...; COMMIT;` sequence shown in the previous section against every existing and future monthly
partition for that table.

# Indexing Partitioned Tables

Indexes in PostgreSQL are **not** automatically shared across partitions — each partition gets its own
physical index, and `CREATE INDEX` on the parent creates a matching index on every existing partition
(and pg_partman/the application must create it on every future one). QAYD's convention:

- Always index-create on the **parent**, never manually on individual children, so PostgreSQL manages
  the inheritance automatically for existing partitions:

```sql
CREATE INDEX idx_journal_lines_account   ON journal_lines (account_id);
CREATE INDEX idx_journal_lines_company   ON journal_lines (company_id, posting_date);
CREATE UNIQUE INDEX uq_journal_lines_id  ON journal_lines (id, posting_date, company_id);
```

- New partitions created by pg_partman **inherit indexes automatically** because pg_partman clones them
  from the parent's index definitions at partition-creation time — no extra step required as long as the
  index existed on the parent before the partition was created. Indexes added to the parent *after* some
  partitions already exist must be created with `CREATE INDEX ... ON ONLY <parent>` followed by
  attaching matching indexes on each child, or more simply with plain `CREATE INDEX` on the parent (which
  PostgreSQL 15 propagates to all existing children in one DDL statement, building them one partition at
  a time).
- Use `CREATE INDEX CONCURRENTLY` is **not** directly supported on a partitioned parent in one step;
  instead, build the index concurrently on each partition individually, then create it on the parent with
  `ONLY` and attach:

```sql
-- Build without parent-wide lock, one partition at a time
CREATE INDEX CONCURRENTLY idx_jl_2026_07_p0_memo ON journal_lines_2026_07_p0 (memo);
CREATE INDEX CONCURRENTLY idx_jl_2026_07_p1_memo ON journal_lines_2026_07_p1 (memo);
-- ... repeat for all existing partitions ...

-- Register the parent-level index definition (fast, metadata-only, once children match)
CREATE INDEX idx_journal_lines_memo ON ONLY journal_lines (memo);
ALTER INDEX idx_journal_lines_memo ATTACH PARTITION idx_jl_2026_07_p0_memo;
ALTER INDEX idx_journal_lines_memo ATTACH PARTITION idx_jl_2026_07_p1_memo;
-- ... attach remaining partitions until the parent index reports valid ...
```

- Partial indexes remain useful per-partition — e.g. an index that only covers non-deleted rows:

```sql
CREATE INDEX idx_journal_lines_active ON journal_lines (company_id, account_id)
    WHERE deleted_at IS NULL;
```

- `EXPLAIN (ANALYZE, BUFFERS)` is the standard verification step after any partitioning change; QAYD
  requires seeing `Index Scan` against a small named subset of partitions (not `Seq Scan` across the
  parent) for the module's canonical reporting queries before a partitioning migration is signed off.

# Constraint Exclusion / Partition Pruning

PostgreSQL 15 uses two related mechanisms to avoid touching irrelevant partitions:

- **Partition pruning** (the modern mechanism, enabled by default via `enable_partition_pruning = on`)
  happens both at planning time (when filter values are constants) and at execution time (when filter
  values come from parameters, a JOIN, or a subquery — critical for prepared statements executed
  repeatedly with different bind values, which is exactly how Laravel's query builder issues queries).
- **Constraint exclusion** (`constraint_exclusion = partition`, the legacy pre-declarative-partitioning
  mechanism based on CHECK constraints) is not required for declaratively partitioned tables and QAYD
  leaves it at its default; it only matters for old-style inheritance-based partitioning, which QAYD does
  not use.

For pruning to trigger, the WHERE clause must reference the partition key **directly and sargably**:

```sql
-- PRUNES: only the 2026-07 partitions (and their HASH children matching company 101) are scanned
EXPLAIN SELECT * FROM journal_lines
 WHERE posting_date >= '2026-07-01' AND posting_date < '2026-08-01'
   AND company_id = 101;

-- DOES NOT PRUNE: wrapping the key in a function defeats pruning unless the function is IMMUTABLE
--   and the planner can still derive a range from it — avoid this pattern
EXPLAIN SELECT * FROM journal_lines
 WHERE date_trunc('month', posting_date) = '2026-07-01';

-- FIX: rewrite as an explicit range (always prunes correctly)
EXPLAIN SELECT * FROM journal_lines
 WHERE posting_date >= '2026-07-01' AND posting_date < '2026-08-01';
```

Confirm pruning with `EXPLAIN` — a pruned plan shows only the touched partitions listed in the plan
tree; an unpruned plan shows every partition (`Append` node fanning out to dozens of children):

```
Append
  ->  Index Scan on journal_lines_2026_07_p0
  ->  Index Scan on journal_lines_2026_07_p1
  ...
```

versus the unpruned form, which lists every monthly and every HASH child across the table's entire
history — an immediate signal that a query needs to be rewritten or that the ORM generated a
non-sargable predicate (see Query & ORM Considerations).

# Detaching & Archiving Old Partitions

QAYD's retention policy distinguishes two classes of aged data:

- **`notifications`** — physically deleted after 90 days. pg_partman's `retention_keep_table = false`
  setting handles this: the maintenance procedure runs `DROP TABLE` on any partition whose upper bound
  has aged past the retention window. No manual action required.
- **`journal_lines`, `ledger_entries`, `audit_logs`, `stock_movements`** — never deleted (immutable
  ledger / compliance requirement), but old partitions are moved off the primary, frequently-backed-up,
  high-performance storage tier once their fiscal year is closed and audited. QAYD does this with
  `DETACH PARTITION`, which is an O(1) metadata operation (the data is not moved or rewritten):

```sql
-- 1. Detach the partition — it becomes an ordinary standalone table, no longer part of journal_lines
ALTER TABLE journal_lines_2025_01 DETACH PARTITION journal_lines_2025_01_p0 CONCURRENTLY;
-- (repeat for each HASH child _p0.._p7, then detach the empty monthly shell)
ALTER TABLE journal_lines DETACH PARTITION journal_lines_2025_01 CONCURRENTLY;
```

`CONCURRENTLY` (PostgreSQL 14+) avoids taking an `ACCESS EXCLUSIVE` lock on the parent for the whole
operation, so live inserts into other partitions are not blocked. After detaching:

```sql
-- 2. Move the now-standalone table to a cold tablespace on cheaper storage
ALTER TABLE journal_lines_2025_01 SET TABLESPACE cold_archive;

-- 3. Optionally export and drop, keeping only a Parquet/CSV export in Cloudflare R2 for compliance
--    (QAYD exports via `COPY ... TO PROGRAM` piped into the R2 upload CLI, then drops the local table
--     only after the export is verified and the fiscal year's external audit is complete)
COPY journal_lines_2025_01 TO PROGRAM 'aws s3 cp - s3://qayd-archive/journal_lines_2025_01.csv --endpoint-url=$R2_ENDPOINT' CSV HEADER;
```

If a reporting query later needs data from a detached-but-not-dropped archive table, QAYD re-attaches it
temporarily rather than querying it standalone (so the application's existing partition-aware queries
keep working unmodified):

```sql
ALTER TABLE journal_lines ATTACH PARTITION journal_lines_2025_01
    FOR VALUES FROM ('2025-01-01') TO ('2025-02-01');
```

Re-attaching validates that every row satisfies the partition bound; because the table was never
modified since detaching, this validation is fast (PostgreSQL can skip the scan if it can prove the
constraint from the table's own recorded CHECK, which QAYD always leaves in place on detached archive
tables specifically to make re-attachment cheap).

# Migration From Non-Partitioned To Partitioned (Zero-Downtime)

QAYD tables are already live with production data before this partitioning scheme is introduced.
PostgreSQL provides no in-place `ALTER TABLE ... PARTITION BY` — converting an existing table requires
building a new partitioned table and cutting over. The zero-downtime procedure QAYD uses (illustrated
for `journal_lines`) is the **logical-replication / dual-write cutover** pattern:

**Step 1 — create the new partitioned structure under a temporary name**, with the full column set,
constraints, and indexes matching the design above (`journal_lines_new`, partitioned exactly as
specified, with all current and near-future monthly/HASH children pre-created).

**Step 2 — backfill historical data in batches**, off-peak, throttled to avoid saturating I/O:

```sql
INSERT INTO journal_lines_new
SELECT * FROM journal_lines
 WHERE id > :last_copied_id
 ORDER BY id
 LIMIT 50000;
-- record MAX(id) copied, loop until caught up
```

Batching by primary key range (rather than one giant `INSERT INTO ... SELECT *`) keeps each transaction
short, avoids long-held locks, and allows the job to be paused/resumed safely.

**Step 3 — start dual-write.** Before the batch backfill finishes, add a Postgres trigger on the
*original* table that mirrors every new INSERT/UPDATE/DELETE into `journal_lines_new`, so writes that
land after the backfill snapshot are not lost:

```sql
CREATE OR REPLACE FUNCTION mirror_journal_lines() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    INSERT INTO journal_lines_new VALUES (NEW.*) ON CONFLICT (id, posting_date, company_id) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' THEN
    UPDATE journal_lines_new SET (debit, credit, deleted_at, updated_at, updated_by) =
      (NEW.debit, NEW.credit, NEW.deleted_at, NEW.updated_at, NEW.updated_by)
      WHERE id = NEW.id AND posting_date = NEW.posting_date AND company_id = NEW.company_id;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_mirror_journal_lines
AFTER INSERT OR UPDATE ON journal_lines
FOR EACH ROW EXECUTE FUNCTION mirror_journal_lines();
```

**Step 4 — reconcile.** Once the backfill's last batch overlaps with the point the trigger went live, run
a row-count and checksum comparison per partition (`SELECT count(*), sum(debit), sum(credit) FROM
journal_lines_new_2026_07 ...` vs. the equivalent range on the original table) to confirm parity.

**Step 5 — cut over inside a single short transaction**, swapping table names so the application's
existing table name (`journal_lines`) now refers to the partitioned structure:

```sql
BEGIN;
  ALTER TABLE journal_lines RENAME TO journal_lines_old;
  ALTER TABLE journal_lines_new RENAME TO journal_lines;
  -- re-point sequences, foreign keys from dependent tables, and views here if any exist
COMMIT;
```

This rename is a metadata-only operation and takes an `ACCESS EXCLUSIVE` lock for milliseconds, not the
duration of the whole migration — this is what makes the overall procedure zero-downtime: the expensive
part (the backfill) runs against a shadow table while production traffic is untouched, and only the
final swap requires a brief lock.

**Step 6 — drop the mirror trigger and, after a verification window (QAYD standard: 7 days), drop
`journal_lines_old`.**

For tables under lighter write load where a brief maintenance window is acceptable (`stock_movements` in
a smaller deployment, for instance), QAYD instead uses the simpler **`pg_partman` partition_data**
helper, which performs the same batched-copy-then-swap dance under a single function call, accepting a
short exclusive lock at the final rename:

```sql
SELECT partman.create_parent('public.stock_movements', 'movement_date', 'range', '1 month');
CALL partman.partition_data_proc('public.stock_movements', p_batch_count := 20, p_batch_interval := '1 month');
```

# Query & ORM Considerations (Laravel)

Laravel's Eloquent ORM has no native concept of PostgreSQL declarative partitioning — from Eloquent's
point of view, `journal_lines` is just a table name. This has two consequences QAYD's backend engineers
must design around:

1. **Every query against a partitioned table must include the partition key in its WHERE clause**, or
   PostgreSQL will scan every partition. QAYD enforces this at the Repository layer with a **global
   scope** that requires a date range (and, for the composite tables, that the query is already
   company-scoped by the standard multi-tenancy middleware):

```php
// app/Models/Scopes/RequiresPartitionRangeScope.php
class RequiresPartitionRangeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $wheres = collect($builder->getQuery()->wheres);
        $hasDateBound = $wheres->contains(fn ($w) => in_array($w['column'] ?? null, ['posting_date', 'created_at', 'movement_date']));

        if (! $hasDateBound && ! app()->runningInConsole()) {
            throw new UnboundedPartitionQueryException(
                "Query against {$model->getTable()} must filter by its partition date key."
            );
        }
    }
}
```

```php
// app/Models/JournalLine.php
class JournalLine extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new RequiresPartitionRangeScope);
    }
}
```

2. **Use explicit range predicates, never `whereMonth`/`whereYear`/`whereRaw("date_trunc(...)")`**,
   which wrap the column in a function and defeat pruning exactly as shown in Constraint
   Exclusion/Partition Pruning:

```php
// WRONG — defeats partition pruning (function-wrapped predicate)
JournalLine::whereYear('posting_date', 2026)->whereMonth('posting_date', 7)->get();

// RIGHT — sargable range, prunes to the 2026-07 partition set
JournalLine::whereBetween('posting_date', ['2026-07-01', '2026-07-31'])
    ->where('company_id', $companyId)
    ->get();

// Equivalently, for a half-open range that matches the partition boundary exactly:
JournalLine::where('posting_date', '>=', '2026-07-01')
    ->where('posting_date', '<', '2026-08-01')
    ->where('company_id', $companyId)
    ->get();
```

3. **Laravel migrations do not generate `PARTITION BY` syntax natively.** QAYD writes the initial
   partitioned-table migrations as raw SQL via `DB::unprepared()`, keeping the Schema Builder only for
   non-partitioned tables:

```php
// database/migrations/2026_07_01_000000_create_journal_lines_table.php
public function up(): void
{
    DB::unprepared(file_get_contents(database_path('sql/journal_lines_partitioned.sql')));
}

public function down(): void
{
    DB::statement('DROP TABLE IF EXISTS journal_lines CASCADE');
}
```

Keeping the raw DDL in a versioned `.sql` file (rather than inline in the migration) makes it easy to
diff partition-structure changes across releases and to run the same DDL manually against a
replica/staging cluster during rehearsal.

4. **Composite primary keys break Eloquent's default single-column `id` assumptions.** Every partitioned
   model must declare its composite key handling explicitly, since Eloquent does not support composite
   primary keys out of the box:

```php
class JournalLine extends Model
{
    protected $primaryKey = 'id';   // still used for route binding / relations
    public $incrementing = true;

    // Composite uniqueness (id, posting_date, company_id) is enforced at the DB level;
    // application code must always supply posting_date/company_id on update/delete,
    // never rely on `WHERE id = ?` alone once the table is partitioned.
    public function save(array $options = [])
    {
        if (! $this->posting_date || ! $this->company_id) {
            throw new \RuntimeException('posting_date and company_id are required to save a partitioned JournalLine.');
        }
        return parent::save($options);
    }
}
```

5. **Bulk inserts (month-end batch posting) should use `insert()`/`upsert()` with chunked arrays**, not a
   loop of individual `save()` calls, both for performance and because each chunk should be scoped to a
   single partition where possible (grouping by `posting_date` month before chunking further reduces
   cross-partition lock fan-out during the insert).

6. **Reporting queries that must span many partitions** (e.g. a 3-year Trial Balance comparison) are
   expected to touch many partitions by design — that is correct pruning behavior (touching exactly the
   36 relevant monthly partitions, not the whole table's history). QAYD's reporting Service layer
   composes these as explicit date-range queries per the pattern above; it never falls back to scanning
   the parent without bounds "because it's easier."

# Edge Cases

- **Foreign keys pointing INTO a partitioned table.** PostgreSQL allows a non-partitioned table to hold
  an FK referencing a partitioned table's primary key, but only if the referenced columns exactly match
  the partitioned table's full primary key (including the partition key column). Since QAYD's
  partitioned tables use composite keys like `(id, posting_date, company_id)`, any table that wants to
  FK into `journal_lines` would need to store `posting_date` and `company_id` redundantly. QAYD avoids
  this entirely: no table holds a DB-level FK into `journal_lines`, `ledger_entries`, `audit_logs`,
  `stock_movements`, or `notifications`. Referential integrity to these tables is enforced at the
  Laravel Service layer (validate the referenced row exists and belongs to the same company before
  insert) plus an `AFTER INSERT/UPDATE` trigger on the partitioned table itself that raises an exception
  if the referenced parent (e.g. `journal_entry_id` on `journal_lines`) does not exist — a
  "reverse-direction" integrity check that does not require the FK to be declared on the partitioned
  side.

- **Unique constraints that don't include the partition key.** A business rule like "no duplicate
  `idempotency_key` per company across all time" cannot be enforced as a single unique index on a
  partitioned table unless the partition key is part of the index. QAYD handles this by adding a small,
  separate, non-partitioned lookup table (`idempotency_keys(company_id, idempotency_key, created_at)`
  with a genuine cross-partition unique constraint) that the Service layer checks/inserts inside the same
  transaction as the partitioned insert, rather than trying to force global uniqueness onto the
  partitioned table's own index.

- **`ON DELETE CASCADE` from a parent row spanning partitions.** Voiding a `journal_entry` must remove or
  mark-void all of its `journal_lines`, which — because the entry's lines can only ever fall in one
  partition (all lines of one entry share the same `posting_date` and `company_id` by construction) — is
  a single-partition operation in practice. QAYD's Service layer still deletes by `journal_entry_id`
  scoped additionally by `posting_date` (denormalized onto the parent lookup) specifically so the
  DELETE itself prunes to one partition instead of scanning all of them for a rare multi-partition case
  that should not occur.

- **Sequence/identity gaps across partitions.** `GENERATED ALWAYS AS IDENTITY` on the parent produces one
  shared sequence for the whole partitioned table (not one sequence per partition), so ids remain
  globally unique and monotonic across partitions — this is expected PostgreSQL behavior and requires no
  special handling, but engineers should not assume a given partition's ids form a contiguous block.

- **`COPY` and bulk loaders targeting the parent vs. a specific partition.** `COPY journal_lines FROM
  ...` against the parent correctly routes each row to its partition (slower, because PostgreSQL must
  evaluate the partition key per row) versus `COPY journal_lines_2026_07_p3 FROM ...` against a known
  partition directly (faster, no routing overhead) when the loader already knows the target partition —
  QAYD's month-end batch import job pre-sorts input by `posting_date`/`company_id` hash and issues
  per-partition `COPY` commands for this reason.

- **Statistics and query planning after a large partition-structure change.** `ANALYZE` does not run
  automatically immediately after bulk-loading a freshly attached or backfilled partition; QAYD's
  migration runbook always ends with an explicit `ANALYZE <table>` (or `VACUUM ANALYZE` if the backfill
  also produced dead tuples) before declaring a partitioning migration complete, since a stale
  `pg_statistic` entry can cause the planner to choose a bad join order even when pruning itself works
  correctly.

- **`TRUNCATE` on a parent truncates every partition.** `TRUNCATE journal_lines` would destroy the
  entire multi-tenant ledger across all companies and all time — QAYD revokes `TRUNCATE` privilege on
  every partitioned parent and its children from all application database roles; only a break-glass
  superuser-equivalent role used solely for disaster-recovery restores holds it.

- **Time zone boundaries on `DATE`-typed partition keys.** `posting_date` and `movement_date` are `DATE`
  (not `TIMESTAMPTZ`) specifically so that a partition boundary of `'2026-07-01'` is unambiguous
  regardless of session time zone; using a `TIMESTAMPTZ` column as a RANGE partition key (as `audit_logs`
  and `notifications` do, deliberately, because they need sub-day precision) requires every partition
  boundary literal to be written in UTC and every application query to bind timestamps that have already
  been converted to UTC before comparison — QAYD's Laravel `Carbon` usage always calls `->utc()` before
  binding a timestamp into a query against these two tables.

# End of Document
