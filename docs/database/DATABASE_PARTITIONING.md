# Database Partitioning — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_PARTITIONING
---

# Purpose

QAYD is a multi-tenant AI Financial Operating System: every posted invoice, journal entry, stock
movement, and audit event for every company on the platform lands in a shared PostgreSQL 15+
cluster. Four tables absorb the overwhelming majority of that volume — `journal_lines`,
`ledger_entries`, `audit_logs`, and `stock_movements` — because they are written on every financial
or inventory event and, by explicit business rule (see `DESIGN_CONTEXT.md` §2 and §4), are never
hard-deleted. Rows accumulate forever. Left as single monolithic tables, each of these will cross
the billion-row mark within a few years of normal growth, and monolithic tables at that size break
PostgreSQL's operational model in specific, predictable ways: `VACUUM` and `ANALYZE` cycles take
hours and compete with foreground I/O; a single stray `ALTER TABLE` takes an `ACCESS EXCLUSIVE` lock
across the *entire* history of the company instead of just the month being touched; a B-Tree index
on 2 billion rows no longer fits in shared memory and every lookup pays a random I/O tax; and
period-end financial reporting queries that only need the current fiscal quarter end up scanning
years of frozen, irrelevant data.

This document is the authoritative specification for how QAYD partitions its largest tables. It
defines the partitioning strategy for `journal_lines`, `ledger_entries`, `audit_logs`, and
`stock_movements` at the DDL level; the general decision framework other engineers must apply
before proposing a partitioning scheme for any *future* large table; and the operational lifecycle
— provisioning, indexing, vacuuming, archiving, and eventual multi-node scale-out — that keeps a
partitioned table healthy for the life of the platform. It assumes PostgreSQL's native declarative
partitioning (`PARTITION BY RANGE | LIST | HASH`, introduced in PostgreSQL 10 and materially
hardened through PostgreSQL 14–15: concurrent detach, partition-wise joins/aggregates, and identity
columns on partitioned parents). It does not assume any particular partitioning extension is
installed; where an extension (`pg_partman`, `postgres_fdw`, `pg_repack`) meaningfully simplifies an
operational task, it is named explicitly and treated as a recommended dependency, not a requirement
already met by the platform.

Every DDL example in this document uses the canonical table names and standard columns fixed in
`DESIGN_CONTEXT.md`: `id BIGINT GENERATED ALWAYS AS IDENTITY`, `company_id BIGINT NOT NULL`,
`branch_id BIGINT NULL`, `created_by` / `updated_by BIGINT NULL`, `created_at` / `updated_at
TIMESTAMPTZ NOT NULL DEFAULT now()`, `deleted_at TIMESTAMPTZ NULL`, `NUMERIC(19,4)` for money,
`NUMERIC(18,6)` for exchange rates, and `NUMERIC(18,4)` for quantities. Nothing in this document
introduces a new table-naming convention, a new money type, or a new multi-tenancy mechanism — it
only adds a physical storage dimension (partitioning) underneath tables whose logical shape is
already fixed elsewhere.

# Horizontal Partitioning

Horizontal partitioning splits a table by **row** — every partition has the identical column
definition as its parent, but each partition owns a disjoint subset of rows. This is the form of
partitioning this entire document is about, and it is the correct axis for all four target tables:
`journal_lines`, `ledger_entries`, `audit_logs`, and `stock_movements` are wide but not
*pathologically* wide (20–35 columns each), and the growth problem they have is row-count growth
over time and across tenants, not column-count bloat. Contrast this with vertical partitioning
(next section), which splits by **column** and is the correct fix for a different symptom.

In native PostgreSQL, a horizontally partitioned table is declared once as a **partitioned table**
(no storage of its own — it is a routing shell) and then populated with one or more **partitions**
(ordinary tables, each with real physical storage, each satisfying a bound expression the parent
enforces). Every `INSERT`, `UPDATE`, and `SELECT` addressed to the parent is transparently routed by
the planner/executor to the correct partition(s) using the partition key; application code and
Eloquent models continue to reference `journal_lines` exactly as before — partitioning is invisible
above the SQL layer, which is why Laravel's query builder and Eloquent require zero code changes to
benefit from it.

```sql
-- The parent is a partitioned TABLE, not a view. It has no heap storage of its own.
CREATE TABLE journal_lines (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    journal_entry_id    BIGINT NOT NULL,
    entry_date          DATE NOT NULL,
    -- ... remaining columns, see "Large Tables" ...
    PRIMARY KEY (id, entry_date)
) PARTITION BY RANGE (entry_date);
```

Three properties of horizontal partitioning matter operationally and recur throughout this
document:

1. **The partition key must appear in every unique constraint, including the primary key.**
   PostgreSQL cannot enforce global uniqueness of `id` alone across independently-stored
   partitions without scanning all of them on every insert, so it refuses to let you declare a
   `PRIMARY KEY (id)` on a partitioned table — the key must be `(id, entry_date)` or wider. This is
   the single most disruptive consequence of adopting partitioning and is covered in full in
   "Partition Keys" and "Large Tables" below, including how QAYD still gets a globally-unique `id`.

2. **A query without the partition key in its `WHERE` clause degrades, it does not fail.**
   `SELECT * FROM journal_lines WHERE journal_entry_id = 8821123` is perfectly legal SQL against a
   partitioned `journal_lines` — the planner just has to visit every partition to answer it (no
   pruning is possible), so it is exactly as expensive as before partitioning, never more. The
   danger with horizontal partitioning is never "this now breaks," it is "this silently stops
   getting faster," which is why "Query Optimization" below treats predicate shape as a first-class
   correctness concern, not just a performance one.

3. **DDL locks are scoped to the partition you touch, not the whole logical table**, provided you
   operate on the partition directly (or use the `CONCURRENTLY`/pre-validated-constraint patterns
   in "Maintenance"). `ALTER TABLE journal_lines ADD COLUMN memo TEXT;` still needs to visit every
   partition (metadata-only, fast on modern PostgreSQL since it just adds a catalog entry with a
   default), but a `VACUUM FULL`, `CLUSTER`, or index rebuild on one month's partition never blocks
   reads or writes against any other month.

QAYD's default horizontal split for all four target tables is `RANGE` on a date-like column, because
the natural, unbounded growth axis in a financial ledger is time, not any other dimension — a
company's `company_id` doesn't grow, but the calendar does. Where a single company's volume within
one time range is itself large enough to need spreading across I/O channels, QAYD composes `RANGE`
with a `HASH` sub-partition (composite partitioning), covered in "Hash Partitioning" and "Large
Tables." Horizontal partitioning is therefore the *outer* strategy platform-wide; `RANGE`, `HASH`,
and `LIST` (below) are the three *methods* PostgreSQL offers for implementing it, and QAYD mixes
them per table based on the query and retention shape of that specific table.

# Vertical Partitioning

Vertical partitioning splits a table by **column**: infrequently-read, large, or independently-
lifecycled attributes move out of the primary row into a companion table sharing the same key, so
the hot path — the columns actually touched by 95% of queries — stays small enough to live entirely
in PostgreSQL's shared buffers. PostgreSQL has no declarative syntax for vertical partitioning (no
`PARTITION BY COLUMN`); it is achieved purely by schema design: a 1:1 (or 1:0..1) companion table
joined on the shared primary key, or a narrow "hot" table paired with a wide "cold" table.

None of the four target tables in this document are vertically partitioned by default, and that is
a deliberate decision, not an oversight: `journal_lines`, `ledger_entries`, and `stock_movements`
are already narrow, fixed-width rows (mostly `BIGINT`, `NUMERIC`, and `DATE`/`TIMESTAMPTZ` — no
`JSONB`, no `TEXT` blobs beyond an optional short `description`), so there is no wide, rarely-read
payload to hive off. `audit_logs` is the one candidate, because it carries two `JSONB` columns
(`old_values`, `new_values`) that can be arbitrarily large (a `products` row with `custom_fields`
and `tags` serialized, for instance) and are read far less often than the audit metadata
(`company_id`, `user_id`, `action`, `created_at`) used to list and filter audit trails.

QAYD's rule: **apply vertical partitioning only when TOAST already isn't enough.** PostgreSQL
automatically vertically partitions any row wider than roughly 2 KB via TOAST (The Oversized
Attribute Storage Technique) — large `JSONB`/`TEXT` values are transparently moved to a side
`pg_toast` table and the main row keeps only a pointer, so a `SELECT company_id, action FROM
audit_logs WHERE ...` never pays the cost of detoasting `old_values`/`new_values` unless the
column is actually selected. This gives most of the benefit of manual vertical partitioning for
free. Manual vertical partitioning is reserved for cases TOAST cannot help with: columns that are
*individually small* but collectively numerous and rarely read together with the hot columns, or
cases where a different access-control/retention policy applies to the cold half (which is exactly
`audit_logs`'s situation once PII-minimization is layered on — see "Archiving").

```sql
-- Hot table: what every audit-trail LIST/FILTER query touches. Stays small, stays in cache.
CREATE TABLE audit_logs (
    id                BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT NULL REFERENCES branches(id),
    user_id           BIGINT NULL REFERENCES users(id),
    auditable_type    VARCHAR(100) NOT NULL,
    auditable_id      BIGINT NOT NULL,
    action            VARCHAR(30) NOT NULL,
    ip_address        INET NULL,
    request_id        UUID NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

-- Cold companion table: the actual diff payload, fetched only when a human opens one record's detail.
CREATE TABLE audit_log_payloads (
    audit_log_id       BIGINT NOT NULL,
    created_at         TIMESTAMPTZ NOT NULL,   -- mirrors parent's partition key; see "Large Tables"
    old_values         JSONB NULL,
    new_values         JSONB NULL,
    reason             TEXT NULL,
    user_agent         TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    PRIMARY KEY (audit_log_id, created_at)
) PARTITION BY RANGE (created_at);
```

This split is optional and QAYD ships `audit_logs` as a single wide partitioned table by default
(shown in full in "Large Tables") because the operational cost of maintaining two partition
hierarchies in lockstep is real and most companies never generate enough audit volume to need the
split. The threshold for promoting `audit_logs` to the two-table vertical split above is empirical,
not architectural: when `pg_stat_user_tables` shows the live `audit_logs` partitions' TOAST tables
exceeding roughly 3x the main relation's size *and* p95 latency on `GET /api/v1/audit-logs` (a
metadata-only list endpoint) is measurably dominated by heap fetches rather than index scans,
split it. Until then, rely on TOAST and on the covering/partial indexes described in "Query
Optimization" to keep the hot path fast without a second table to maintain.

The general vertical-partitioning checklist for any future QAYD table:

| Signal | Action |
|---|---|
| Table has one or more `JSONB`/`TEXT` columns individually > ~2 KB | No action needed — TOAST already vertically partitions this transparently. |
| Table has many small columns (5-15) that are only read in a "detail view," never in list/filter queries | Candidate for a 1:1 companion table keyed on the same PK. |
| A subset of columns has a materially different retention/erasure policy than the rest (e.g. PII vs. financial amount) | Split so the policy can be enforced (e.g. `UPDATE ... SET ip_address = NULL`) without touching the immutable financial columns. |
| A subset of columns is written by a different, less-trusted service (e.g. AI-generated `reasoning`/`confidence` fields) | Split so the core table's write path never needs the AI layer's schema changes to migrate in lockstep. |
| Table is already partitioned horizontally and rarely-read columns roughly double the per-partition heap size | Split to shrink the working set that has to fit in `shared_buffers` for the hot query path. |

# Range Partitioning

`RANGE` partitioning divides a table into partitions along a **contiguous, ordered** key range —
typically a date. It is PostgreSQL's oldest and most mature declarative partitioning method, and it
is QAYD's default for all four target tables because every one of them is fundamentally a
time-series of financial or operational events: entries are posted on a date, ledger lines are
posted on a date, audit events happen at an instant, and stock moves on a date. Time-range
partitioning aligns physical storage with three things simultaneously — the way the business asks
questions ("show me Q2"), the way retention/archiving policy is expressed ("keep 24 months hot"),
and the way data actually grows (monotonically, append-mostly, never backfilling the distant past).

QAYD partitions all four target tables by calendar **month**. Month was chosen over quarter, week,
or day after weighing:

- **Partition count stays bounded.** Twelve partitions/year/table keeps the *active* partition
  count in the tens even after several years (paired with the archiving tiers in "Archiving," which
  detach anything older than the hot/warm window), which matters because planning time grows with
  partition count — see "Performance."
- **Partition size stays bounded per tenant-month, not per tenant-day.** Daily partitions would
  produce 365+ tiny partitions/year with heavy per-partition overhead (each partition has its own
  files, its own `pg_class` row, its own autovacuum bookkeeping) for tables whose per-company daily
  volume is often just a few dozen rows. Quarterly partitions, at the other extreme, make the
  *current, actively-written* partition too large to `VACUUM`/reindex cheaply and delay the point
  at which a closed period can be frozen and archived.
- **Month matches the business's own reporting/closing cadence.** Fiscal periods in `fiscal_periods`
  are monthly by convention for the vast majority of QAYD companies; period-close, trial balance,
  and VAT-return workflows are already monthly-shaped, so pruning aligns with real query patterns
  instead of an arbitrary boundary the application then has to work around.

```sql
CREATE TABLE journal_lines (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    journal_entry_id     BIGINT NOT NULL,
    account_id           BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id       BIGINT NULL REFERENCES cost_centers(id),
    project_id           BIGINT NULL REFERENCES projects(id),
    department_id        BIGINT NULL REFERENCES departments(id),
    line_number          SMALLINT NOT NULL,
    description          TEXT NULL,
    debit_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code         CHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate         NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_debit_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_credit_amount    NUMERIC(19,4) NOT NULL DEFAULT 0,
    entry_date            DATE NOT NULL,
    created_by            BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    CONSTRAINT chk_journal_lines_one_sided CHECK (
        (debit_amount = 0 AND credit_amount > 0) OR (credit_amount = 0 AND debit_amount > 0)
    ),
    PRIMARY KEY (id, entry_date)
) PARTITION BY RANGE (entry_date);

-- One partition per calendar month. Bounds are half-open: [from, to).
CREATE TABLE journal_lines_y2026m06 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-06-01') TO ('2026-07-01');

CREATE TABLE journal_lines_y2026m07 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');

CREATE TABLE journal_lines_y2026m08 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');

-- Safety net: any row whose entry_date doesn't fall in a declared bound lands here instead of
-- erroring the INSERT. A non-empty default partition is always an application bug (see "Maintenance").
CREATE TABLE journal_lines_default PARTITION OF journal_lines DEFAULT;
```

`FOR VALUES FROM (...) TO (...)` bounds are always **half-open** (`>= FROM`, `< TO`) — this is the
detail engineers most often get wrong when hand-rolling partition DDL, and it is why the boundary is
always written as the first day of the *next* month, never the last day/instant of the current one.
A row with `entry_date = '2026-07-31'` belongs unambiguously to `journal_lines_y2026m07`; a row with
`entry_date = '2026-08-01 00:00:00'` (for a `TIMESTAMPTZ` partition key, e.g. `audit_logs.created_at`
or `ledger_entries.posting_date` when timestamped) belongs unambiguously to the August partition,
with zero ambiguity about which side of midnight owns the boundary instant.

QAYD standardizes the partition-naming convention `{table}_y{YYYY}m{MM}` (zero-padded month) across
every range-partitioned table, and a single `default` partition per table that is monitored (see
"Maintenance") rather than relied upon — the default partition exists purely so that a clock-skew
bug or bad backfill import fails loudly (rows visibly parked in `_default`) instead of raising a
constraint-violation error that could abort an otherwise-valid financial posting transaction.

Range partitioning's chief limitation is that it does nothing for **skew within** a range: if one
company legitimately posts ten million journal lines in a single month (a large ERP-migration
backfill, for instance) while every other company posts a few thousand, that one partition is a hot
spot no amount of date-based slicing fixes. QAYD's answer, used selectively, is to make the *current
month's* partition itself a `HASH`-partitioned table on `company_id` — a composite range-then-hash
scheme detailed in "Hash Partitioning" and applied concretely to `journal_lines` and
`ledger_entries` in "Large Tables."

# Hash Partitioning

`HASH` partitioning distributes rows across a fixed number of partitions by applying PostgreSQL's
internal hash function to the partition key and taking the result modulo the declared number of
buckets. Unlike `RANGE`, it has no notion of order and no notion of "current" or "old" — every
bucket is, on average, the same size forever, and there is no bucket you can ever archive and drop
the way you archive an old month. This makes `HASH` the wrong *primary* strategy for financial
fact tables (you can never retire a bucket, only grow it), but the right tool for a specific,
narrower problem QAYD does have: **spreading one time-window's write and storage load evenly across
many physical partitions when a handful of tenants dominate that window's volume.**

```sql
-- Example standalone use: an even, tenant-blind split into 8 buckets.
CREATE TABLE stock_movements_staging (
    id           BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id   BIGINT NOT NULL REFERENCES companies(id),
    payload      JSONB NOT NULL,
    received_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, company_id)
) PARTITION BY HASH (company_id);

CREATE TABLE stock_movements_staging_p0 PARTITION OF stock_movements_staging
    FOR VALUES WITH (MODULUS 8, REMAINDER 0);
CREATE TABLE stock_movements_staging_p1 PARTITION OF stock_movements_staging
    FOR VALUES WITH (MODULUS 8, REMAINDER 1);
-- ... p2 .. p7, REMAINDER 2..7
```

QAYD's production use of `HASH` is never standalone like the sketch above — it is always the
**second level of a composite RANGE-then-HASH scheme**, applied only to `journal_lines` and
`ledger_entries`, and only from the point in the platform's growth curve where a single month's
partition for those two tables exceeds roughly 50 GB or 200 million rows (see "Large Tables" for the
exact composite DDL). Below that threshold, a plain monthly `RANGE` partition is simpler to operate
and is preferred; `HASH` sub-partitioning is switched on for future months once the threshold is
crossed, never retrofitted onto already-closed historical months (which are about to be archived
anyway — see "Archiving" — so there is no benefit to rewriting them).

Three properties of `HASH` partitioning that shape how QAYD uses it:

1. **The bucket count is fixed at creation and expensive to change.** Growing from 8 buckets to 16
   is not an `ATTACH`/`DETACH` operation like `RANGE` — every existing bucket's `MODULUS` changes,
   which means every row's target bucket changes, which means a full rewrite of all data under that
   `HASH`-partitioned parent. QAYD therefore always over-provisions the bucket count for a hash
   layer relative to *current* need — e.g. 16 buckets sized for the largest handful of tenants
   expected over the *next 18 months*, not the tenants that exist today — because doubling later is
   a maintenance-window event, not a routine one.
2. **`HASH` gives even distribution, not tenant isolation.** Two different `company_id` values will
   almost always land in different buckets, but which company lands in which bucket is an
   implementation detail of PostgreSQL's hash function, not something the application controls or
   should rely on. If true physical per-tenant isolation is ever required (a dedicated-storage
   contractual commitment to one enterprise customer, for instance), that is `LIST` partitioning by
   `company_id` (or a fully separate database — see "Future Scaling"), not `HASH`.
3. **Hash bucket boundaries do not accept a `DEFAULT` partition escape hatch the way `RANGE` and
   `LIST` do for out-of-bound values** — because by construction every possible input hashes into
   exactly one of the declared buckets, there is no "value that doesn't fit anywhere." This removes
   one operational failure mode (`HASH` layers cannot silently accumulate rows in an unmonitored
   default partition) at the cost of the flexibility a default partition gives `RANGE`/`LIST`.

QAYD's composite pattern nests a `HASH` layer *inside* a `RANGE` month, rather than the reverse,
because the retention/archiving lifecycle is defined on the month, not on the tenant bucket — an
entire month (with all of its hash sub-partitions) is what gets frozen and eventually detached as a
unit in "Archiving." Nesting it the other way (hash buckets at the top, months underneath) would
mean an archival job has to touch all N hash buckets to retire one month, instead of detaching a
single subtree.

# List Partitioning

`LIST` partitioning routes rows to a partition based on membership in an explicit, enumerated set of
values for the partition key — the opposite of `RANGE`'s continuous ordering and `HASH`'s
value-blind distribution. It is the correct method whenever a column has a small number of discrete,
semantically distinct values that queries filter on disproportionately, and where the *operational
handling* of one value group differs meaningfully from another (different mutation frequency,
different retention, different index profile).

QAYD's primary production use of `LIST` is **not** on any of the four range-partitioned fact tables
themselves, but on `journal_entries` — the header row each `journal_lines` batch belongs to — split
by its own `status` lifecycle column (`draft -> posted -> reversed | voided`, per `DESIGN_CONTEXT.md`
§4). This is a deliberately different partitioning axis from `journal_lines`' `RANGE (entry_date)`,
and the two are independent design decisions, joined only by the ordinary (non-partitioned-target)
foreign key `journal_lines.journal_entry_id -> journal_entries.id`:

```sql
CREATE TABLE journal_entries (
    id             BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    branch_id      BIGINT NULL REFERENCES branches(id),
    fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
    entry_date     DATE NOT NULL,
    reference      VARCHAR(50) NOT NULL,
    memo           TEXT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'draft',
    posted_at      TIMESTAMPTZ NULL,
    reversed_entry_id BIGINT NULL REFERENCES journal_entries(id),
    created_by     BIGINT NULL REFERENCES users(id),
    updated_by     BIGINT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at     TIMESTAMPTZ NULL,
    CONSTRAINT chk_journal_entries_status
        CHECK (status IN ('draft', 'posted', 'reversed', 'voided')),
    PRIMARY KEY (id, status)
) PARTITION BY LIST (status);

-- Small, hot, frequently UPDATEd — line items still being edited before posting.
CREATE TABLE journal_entries_draft PARTITION OF journal_entries
    FOR VALUES IN ('draft');

-- Overwhelmingly the largest partition; append-only; rows here are never UPDATEd again
-- (corrections happen via a new reversing entry, per DESIGN_CONTEXT.md double-entry rules).
CREATE TABLE journal_entries_posted PARTITION OF journal_entries
    FOR VALUES IN ('posted');

CREATE TABLE journal_entries_reversed PARTITION OF journal_entries
    FOR VALUES IN ('reversed');

CREATE TABLE journal_entries_voided PARTITION OF journal_entries
    FOR VALUES IN ('voided');
```

The business value of this split is concrete: `draft` entries are the only ones ever subject to
`UPDATE`/`DELETE` (a user editing a journal entry before posting it), so isolating them keeps
`autovacuum`'s churn-heavy work confined to a small partition, while `journal_entries_posted` — by
far the largest partition and, per business rule, immutable once written — can run with
`autovacuum_vacuum_scale_factor` turned down to near zero and a `fillfactor` of 100, because
PostgreSQL never needs to leave update slack in pages that are never updated in place. The dominant
query "list my open drafts" (`WHERE status = 'draft'`) additionally prunes to a partition that is
orders of magnitude smaller than the full history, with no other filter required.

QAYD's second production use of `LIST` is on `stock_movements`' close cousin problem: distinguishing
`movement_type` values that are read by fundamentally different report families. Rather than
partition the fact table itself by type (which would break the time-range archiving story — see
"Large Tables"), QAYD reserves `LIST (movement_type)` for a narrower, optional case: **sub-listing
within a single month's `RANGE` partition** when one company's inventory pattern is dominated by one
movement type (e.g. a high-frequency `production_in`/`production_out` manufacturing client) and its
valuation job wants to scan only that type across the month without an index. This is shown as a
sub-partitioning example in "Large Tables" and, like `HASH`, is switched on selectively rather than
platform-wide, since most companies' movement-type distribution does not justify the added partition
count.

`LIST`'s general applicability checklist for future QAYD tables:

| Signal | Use `LIST`? |
|---|---|
| Column has a small (2-15), stable, enumerated set of values (a `status`, `type`, or `action` enum) | Yes — this is the textbook case. |
| One value group has a materially different write pattern (mutable vs. immutable) than the rest | Yes — isolating it changes autovacuum/fillfactor tuning for the bulk partition. |
| Queries overwhelmingly filter `WHERE column = 'single_value'` rather than ranges or IN-lists spanning many values | Yes — pruning collapses to exactly one partition. |
| The value set will grow unboundedly or is user-defined free text | No — use a plain indexed column; `LIST` requires a fixed, DDL-declared value set (a new value needs a new partition or falls into `DEFAULT`). |
| The natural retention/archiving axis is time, not this column | Use `LIST` only as a sub-partition beneath `RANGE`, never as the sole top-level strategy for an append-forever fact table. |

# Partition Keys

The partition key is the single most consequential decision in a partitioned table's design,
because — unlike an index, which can be added or dropped in minutes — changing a table's partition
key after the fact means rebuilding every partition and every constraint from scratch. QAYD applies
five criteria, in order, when choosing a partition key for any table, financial or otherwise:

1. **The key must be `NOT NULL` and present on every row at insert time.** PostgreSQL allows a
   `NULL` partition key value only under `LIST` (routed to a partition explicitly declared `FOR
   VALUES IN (NULL)`, or to `DEFAULT`); `RANGE` and `HASH` reject a `NULL` key outright unless a
   `DEFAULT` partition exists to catch it. Every candidate key in this document (`entry_date`,
   `posting_date`, `created_at`, `movement_date`, `company_id`, `status`) is already `NOT NULL` in
   the base schema, so this is a design constraint satisfied by construction, not a retrofit.
2. **The key must match the table's dominant filter predicate.** QAYD's product surfaces (financial
   statements, ledgers, stock cards, audit trails) are overwhelmingly period-scoped — "this fiscal
   quarter," "this month's movements," "audit events in the last 90 days" — which is why every
   fact table in this document keys on a date, not on `company_id`, despite `company_id` being the
   *other* column present on literally every query (multi-tenancy isolation, per `DESIGN_CONTEXT.md`
   §2, is enforced by an ordinary `WHERE company_id = :active_company` on an index, not by
   partitioning — see the callout below).
3. **The key should produce partitions of roughly comparable size**, so that no single partition
   becomes a bottleneck while others sit nearly empty. A monotonic date column naturally satisfies
   this for a platform with a steady onboarding rate; it stops being sufficient only when a handful
   of tenants dominate a given month's volume, which is precisely the trigger for composite
   `RANGE + HASH` (see "Hash Partitioning" and "Large Tables").
4. **The key must be part of every unique/primary key on the table** (a hard PostgreSQL rule for
   declarative partitioning, not a QAYD convention). This forces every partitioned table's primary
   key from a clean `id BIGINT PRIMARY KEY` to a composite `(id, <partition key>)`, discussed fully
   below.
5. **The key's value should not need to change after insert.** PostgreSQL 11+ permits `UPDATE`
   statements that move a row across a partition boundary (executed internally as a delete-then-
   insert, with full trigger support restored in PostgreSQL 13+), but QAYD treats this as an
   emergency escape hatch, not a supported application code path: none of `entry_date`,
   `posting_date`, `movement_date`, or `created_at` are ever mutated by application logic once a
   row exists (they describe *when a financial fact happened*, which — like the amounts themselves
   — is immutable once posted, per the platform's double-entry rules).

**Why `company_id` is not the partition key for these tables.** It is tempting to partition a
multi-tenant table by `company_id` so that "delete/archive one tenant" becomes "drop one partition."
QAYD deliberately does not do this for `journal_lines`, `ledger_entries`, `audit_logs`, or
`stock_movements`, for three reasons: financial rows are never deleted per-tenant in the way that
scheme optimizes for (a company leaving the platform still has a statutory retention obligation on
its historical books, so "drop the tenant's partition" is not actually a supported product
operation); a `LIST`- or `HASH`-partition-by-`company_id` scheme produces one partition per tenant
(or per hash bucket of tenants) whose *size* is entirely a function of that tenant's activity, so a
single large enterprise customer's partition grows forever with no archiving axis, defeating the
entire point of partitioning; and the query pattern that dominates — period-scoped financial
reporting — gets no pruning benefit from a `company_id`-keyed partition, since a trial balance for
one company in one quarter still has to filter by date *within* that company's partition. `company_id`
remains what it always was: the first column of nearly every composite index in this document, and
the row-level security boundary enforced elsewhere in the platform — a filtering and security
concern, not a physical storage-partitioning one, for these four tables specifically. (`company_id`
*is* the correct axis once QAYD needs to shard across multiple physical database nodes rather than
partition within one — that is a distinct problem, covered in "Future Scaling.")

**The composite primary key consequence.** Every partitioned table in this document declares
`PRIMARY KEY (id, <partition_key>)` instead of `PRIMARY KEY (id)`. This does not weaken uniqueness
of `id` in practice, because QAYD declares the `GENERATED ALWAYS AS IDENTITY` clause on the
top-level partitioned parent, not on each child partition individually — PostgreSQL associates
exactly one sequence with that identity column at the parent, and every `INSERT`, regardless of
which partition it is ultimately routed to, draws its `id` from that single shared sequence. `id`
therefore stays globally unique across every partition of the table without any extra plumbing,
even though the *enforced* uniqueness constraint PostgreSQL can actually check efficiently is the
wider `(id, entry_date)` pair. The practical implications engineers must design around:

- **Point lookups by `id` alone lose partition pruning.** `SELECT * FROM journal_lines WHERE id =
  91234` has to visit every partition, because `id` alone no longer identifies a partition. QAYD's
  API and internal services therefore always carry the natural date alongside a line-item id where
  one is available (e.g. `journal_lines` rows are always fetched through their parent
  `journal_entry_id`, which is a normal foreign key to the unpartitioned `journal_entries`, or
  through an explicit date-range filter), and any endpoint that must support a bare
  "fetch by id" lookup is expected to first resolve the record's date via a covering index (see
  "Query Optimization") rather than issue an unfiltered cross-partition scan.
- **Foreign keys *into* a partitioned table must reference the composite key, not just `id`.**
  PostgreSQL only allows a foreign key to reference a column set covered by a unique constraint on
  the target, and on a partitioned table that unique constraint is the composite one. Concretely,
  `ledger_entries.journal_line_id` cannot be declared as a plain `REFERENCES journal_lines(id)`; the
  referencing table must also carry the partition-key column and declare a composite foreign key:
  `FOREIGN KEY (journal_line_id, line_entry_date) REFERENCES journal_lines (id, entry_date)`. This
  is shown concretely in "Large Tables." QAYD's standing rule is therefore: **foreign keys point
  *into* a partitioned fact table only when unavoidable, and dimension/header tables
  (`journal_entries`, `accounts`, `products`, `warehouses`, `inventory_items`) stay unpartitioned
  specifically so that every other table in the schema can hold a normal, single-column foreign key
  into them** without inheriting the composite-key tax.

The partition-key assignment for every large table addressed by this document:

| Table | Partition method | Partition key | Rationale |
|---|---|---|---|
| `journal_lines` | RANGE, monthly (+ optional HASH sub-partition on `company_id`) | `entry_date` | Matches fiscal-period reporting; append-only after posting. |
| `ledger_entries` | RANGE, monthly (+ optional HASH sub-partition on `company_id`) | `posting_date` | Derived GL projection; queried almost exclusively by period. |
| `audit_logs` | RANGE, monthly | `created_at` | Compliance/audit queries are date-bounded; highest write volume on the platform. |
| `stock_movements` | RANGE, monthly (+ optional LIST sub-partition on `movement_type`) | `movement_date` | Inventory valuation and stock cards are period- and type-scoped. |
| `journal_entries` (header, unpartitioned by default) | LIST (when adopted) | `status` | Isolates the small mutable `draft` set from the large immutable `posted` set. |

# Large Tables (journal_lines, ledger_entries, audit_logs, stock_movements)

This section is the physical specification other engineers implement against. Each table below is
shown with its complete `CREATE TABLE` DDL, its indexing strategy, its foreign-key handling, its
composite-partitioning escalation path, and the specific volume assumptions that justify the design.
All four follow the same skeleton — `PARTITION BY RANGE` on a date column, monthly boundaries,
one `_default` catch-all — and differ only where their access pattern genuinely diverges.

## journal_lines

`journal_lines` is the highest-cardinality table in the double-entry accounting core: every posted
journal entry produces 2-N rows here (a simple entry is 2 lines; a multi-line payroll or purchase
allocation entry can be 20+). At QAYD's projected scale — a mid-market tenant posts on the order of
30,000-60,000 lines/month once fully onboarded (invoicing, bills, bank feeds, payroll, and inventory
COGS postings all write through here) — 5,000 active companies by year three implies roughly
150-300 million lines/month platform-wide, 1.8-3.6 billion/year. This is the table composite
partitioning exists for.

```sql
CREATE TABLE journal_lines (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    journal_entry_id     BIGINT NOT NULL REFERENCES journal_entries(id),
    account_id           BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id       BIGINT NULL REFERENCES cost_centers(id),
    project_id           BIGINT NULL REFERENCES projects(id),
    department_id        BIGINT NULL REFERENCES departments(id),
    line_number          SMALLINT NOT NULL,
    description          TEXT NULL,
    debit_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code        CHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate        NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_debit_amount    NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_credit_amount   NUMERIC(19,4) NOT NULL DEFAULT 0,
    entry_date           DATE NOT NULL,
    created_by           BIGINT NULL REFERENCES users(id),
    updated_by           BIGINT NULL REFERENCES users(id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at           TIMESTAMPTZ NULL,
    CONSTRAINT chk_journal_lines_one_sided CHECK (
        (debit_amount = 0 AND credit_amount > 0) OR (credit_amount = 0 AND debit_amount > 0)
    ),
    CONSTRAINT chk_journal_lines_nonnegative CHECK (debit_amount >= 0 AND credit_amount >= 0),
    PRIMARY KEY (id, entry_date)
) PARTITION BY RANGE (entry_date);

-- Below the composite-partitioning threshold: a plain monthly partition.
CREATE TABLE journal_lines_y2026m07 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');

-- Above the composite-partitioning threshold (>~200M rows or ~50GB projected for the month):
-- the month itself becomes a partitioned table, sub-partitioned by HASH(company_id).
CREATE TABLE journal_lines_y2026m08 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01')
    PARTITION BY HASH (company_id);

CREATE TABLE journal_lines_y2026m08_h0 PARTITION OF journal_lines_y2026m08
    FOR VALUES WITH (MODULUS 16, REMAINDER 0);
CREATE TABLE journal_lines_y2026m08_h1 PARTITION OF journal_lines_y2026m08
    FOR VALUES WITH (MODULUS 16, REMAINDER 1);
-- ... h2 .. h15 follow the same pattern (REMAINDER 2..15)

CREATE TABLE journal_lines_default PARTITION OF journal_lines DEFAULT;

-- Per-partition indexes (issued once per child; see "Maintenance" for the CONCURRENTLY-safe
-- rollout pattern used once a partition is already live and taking writes).
CREATE INDEX idx_jl_y2026m07_company ON journal_lines_y2026m07 (company_id, account_id);
CREATE INDEX idx_jl_y2026m07_entry ON journal_lines_y2026m07 (journal_entry_id);
CREATE INDEX idx_jl_y2026m07_account_date ON journal_lines_y2026m07 (account_id, entry_date)
    WHERE deleted_at IS NULL;
```

`journal_entry_id` references the **unpartitioned** `journal_entries` header table (see "Partition
Keys" above for why headers stay unpartitioned) — a plain, single-column foreign key, no composite
tax. `account_id`, `cost_center_id`, `project_id`, and `department_id` similarly reference small,
unpartitioned dimension tables. The only place `journal_lines`' composite key propagates outward is
into `ledger_entries`, below.

## ledger_entries

`ledger_entries` is the General Ledger read-model: a posted, denormalized projection of
`journal_lines` optimized for the query pattern financial statements actually need (one row per
posted debit/credit, already resolved to its fiscal period, with a running balance materialized at
write time so trial-balance and account-statement endpoints never compute a window function over
the full account history at request time, per `DESIGN_CONTEXT.md` §4: "General Ledger... derived
from posted journal_lines... a projection/materialized view for speed"). It is written exactly once
per `journal_lines` row, at the moment that row's parent entry transitions to `posted`, and is never
updated afterward — corrections post a new reversing entry, which produces new `ledger_entries` rows
rather than mutating existing ones.

```sql
CREATE TABLE ledger_entries (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    journal_entry_id    BIGINT NOT NULL REFERENCES journal_entries(id),
    journal_line_id     BIGINT NOT NULL,
    line_entry_date     DATE NOT NULL,   -- mirrors journal_lines.entry_date; required for the FK below
    account_id          BIGINT NOT NULL REFERENCES accounts(id),
    fiscal_year_id      BIGINT NOT NULL REFERENCES fiscal_years(id),
    fiscal_period_id    BIGINT NOT NULL REFERENCES fiscal_periods(id),
    cost_center_id      BIGINT NULL REFERENCES cost_centers(id),
    project_id          BIGINT NULL REFERENCES projects(id),
    posting_date        DATE NOT NULL,
    debit_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
    running_balance     NUMERIC(19,4) NOT NULL,
    currency_code       CHAR(3) NOT NULL DEFAULT 'KWD',
    base_debit_amount   NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_credit_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Composite FK into the partitioned journal_lines table: must include the partition key.
    PRIMARY KEY (id, posting_date),
    CONSTRAINT fk_ledger_entries_journal_line
        FOREIGN KEY (journal_line_id, line_entry_date)
        REFERENCES journal_lines (id, entry_date)
) PARTITION BY RANGE (posting_date);

CREATE TABLE ledger_entries_y2026m07 PARTITION OF ledger_entries
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE ledger_entries_default PARTITION OF ledger_entries DEFAULT;

CREATE INDEX idx_le_y2026m07_account_period
    ON ledger_entries_y2026m07 (company_id, account_id, fiscal_period_id);
CREATE INDEX idx_le_y2026m07_entry ON ledger_entries_y2026m07 (journal_entry_id);
-- BRIN over the insertion-correlated timestamp: cheap, high-value for range scans across a quarter.
CREATE INDEX idx_le_y2026m07_created_brin ON ledger_entries_y2026m07
    USING BRIN (created_at) WITH (pages_per_range = 32);
```

`ledger_entries` follows `journal_lines`' partition boundary one-for-one — the same
composite-`HASH`-on-`company_id` escalation applies the moment a single month crosses the same
~200M-row/~50GB threshold, using the identical `MODULUS`/`REMAINDER` scheme so the two tables'
partitions for the same month can be reasoned about (and, if ever needed, joined with
partition-wise join enabled) symmetrically. Note the `line_entry_date` mirror column: because
`journal_lines`' primary key is `(id, entry_date)`, any table that wants a real, enforced foreign
key into a specific `journal_lines` row must carry both columns and declare the composite FK — this
is the concrete instance of the rule stated in "Partition Keys."

## audit_logs

`audit_logs` is the single highest-write-volume table on the entire platform, because — per
`DESIGN_CONTEXT.md` §2, "every mutation writes an audit log" — it receives a row for every `INSERT`,
`UPDATE`, and soft-`DELETE` across every tenant-scoped table in the system, not just the accounting
module. Its write rate is therefore a multiple of the busiest business table's write rate, and its
retention requirement is simultaneously the longest (compliance and forensic value grows with age,
not shrinks) and the least frequently *read* (the overwhelming majority of rows are never viewed by
a human after the week they were written, until an audit or dispute reopens interest in them years
later).

```sql
CREATE TABLE audit_logs (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    user_id            BIGINT NULL REFERENCES users(id),
    auditable_type     VARCHAR(100) NOT NULL,
    auditable_id       BIGINT NOT NULL,
    action             VARCHAR(30) NOT NULL,
    old_values         JSONB NULL,
    new_values         JSONB NULL,
    reason             TEXT NULL,
    ip_address         INET NULL,
    user_agent         TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    request_id         UUID NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_audit_logs_action CHECK (action IN (
        'created', 'updated', 'deleted', 'restored', 'posted', 'reversed',
        'voided', 'approved', 'rejected', 'login', 'export', 'permission_changed'
    )),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

CREATE TABLE audit_logs_y2026m07 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-07-01 00:00:00+00') TO ('2026-08-01 00:00:00+00');
CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT;

CREATE INDEX idx_al_y2026m07_auditable ON audit_logs_y2026m07 (auditable_type, auditable_id);
CREATE INDEX idx_al_y2026m07_company_user ON audit_logs_y2026m07 (company_id, user_id);
CREATE INDEX idx_al_y2026m07_created_brin ON audit_logs_y2026m07
    USING BRIN (created_at) WITH (pages_per_range = 32);
-- GIN index only on partitions where diff-payload search is actually a supported product feature;
-- otherwise omitted to keep write amplification down (see "Performance").
CREATE INDEX idx_al_y2026m07_new_values_gin ON audit_logs_y2026m07
    USING GIN (new_values jsonb_path_ops);
```

`audit_logs` does not get the `HASH`-on-`company_id` composite escalation that `journal_lines` and
`ledger_entries` get, because its skew profile is different: no single company's *audit* volume
dominates a month the way a backfill can dominate a ledger month, since audit rows are a roughly
constant multiplier on top of every other table's own write volume, which is already smoothed across
tenants. If a future workload changes that assumption (a bulk-import tool that fires one audit row
per imported record, for instance, at a scale that creates real skew), the same `RANGE`-then-`HASH`
pattern applies unchanged — the schema does not need to anticipate it in advance. `audit_logs` is,
however, the table most likely to be promoted to the vertical split shown in "Vertical Partitioning"
(`audit_logs` / `audit_log_payloads`) once JSONB payload volume dominates partition size, and it is
the primary subject of the PII-minimization archiving policy in "Archiving" (`ip_address`,
`user_agent`, and `device_fingerprint` are the columns anonymized after a shorter window than the
financial-relevance of `action`/`auditable_id` requires them to be kept).

## stock_movements

`stock_movements` is the perpetual-inventory ledger: one row per unit-of-measure movement in or out
of a warehouse, driving `inventory_items.quantity_on_hand`, COGS recognition, and FIFO/weighted-
average valuation. Its volume is driven by transaction count, not by line-item count the way
`journal_lines` is, but distribution-heavy tenants (a company moving thousands of SKUs across
multiple warehouses) can rival accounting-line volume, and — like the other three tables — its
history must remain queryable indefinitely for valuation audits and inventory reconciliation.

```sql
CREATE TABLE stock_movements (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id               BIGINT NOT NULL REFERENCES companies(id),
    branch_id                BIGINT NULL REFERENCES branches(id),
    warehouse_id             BIGINT NOT NULL REFERENCES warehouses(id),
    product_id               BIGINT NOT NULL REFERENCES products(id),
    product_batch_id         BIGINT NULL REFERENCES product_batches(id),
    product_serial_id        BIGINT NULL REFERENCES product_serials(id),
    movement_type            VARCHAR(20) NOT NULL,
    reference_type           VARCHAR(50) NULL,
    reference_id             BIGINT NULL,
    quantity                 NUMERIC(18,4) NOT NULL,
    unit_cost                NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_cost                NUMERIC(19,4) NOT NULL DEFAULT 0,
    balance_quantity_after     NUMERIC(18,4) NOT NULL,
    balance_value_after         NUMERIC(19,4) NOT NULL,
    movement_date                DATE NOT NULL,
    created_by                    BIGINT NULL REFERENCES users(id),
    created_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_stock_movements_type CHECK (movement_type IN (
        'purchase_in', 'sale_out', 'transfer_in', 'transfer_out', 'adjustment_in',
        'adjustment_out', 'count_correction', 'return_in', 'return_out',
        'production_in', 'production_out'
    )),
    CONSTRAINT chk_stock_movements_quantity_nonzero CHECK (quantity <> 0),
    PRIMARY KEY (id, movement_date)
) PARTITION BY RANGE (movement_date);

CREATE TABLE stock_movements_y2026m07 PARTITION OF stock_movements
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE stock_movements_default PARTITION OF stock_movements DEFAULT;

CREATE INDEX idx_sm_y2026m07_product_wh ON stock_movements_y2026m07
    (product_id, warehouse_id, movement_date);
CREATE INDEX idx_sm_y2026m07_reference ON stock_movements_y2026m07 (reference_type, reference_id);

-- Optional LIST sub-partition, used only for tenants whose single movement_type dominates the
-- month's volume enough to justify it (e.g. a manufacturing client's production_in/production_out
-- pair). Declared per-month, not platform-wide.
CREATE TABLE stock_movements_y2026m08 PARTITION OF stock_movements
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01')
    PARTITION BY LIST (movement_type);

CREATE TABLE stock_movements_y2026m08_production PARTITION OF stock_movements_y2026m08
    FOR VALUES IN ('production_in', 'production_out');
CREATE TABLE stock_movements_y2026m08_other PARTITION OF stock_movements_y2026m08
    FOR VALUES IN (
        'purchase_in', 'sale_out', 'transfer_in', 'transfer_out', 'adjustment_in',
        'adjustment_out', 'count_correction', 'return_in', 'return_out'
    );
```

`inventory_items.quantity_on_hand` and `inventory_valuations` are maintained by the application
service layer at write time (per `DESIGN_CONTEXT.md`'s Clean Architecture — Service/Repository — and
event-driven module communication rules), reading the most recent `stock_movements` row for a given
`product_id`/`warehouse_id` to seed `balance_quantity_after`/`balance_value_after`; this lookup is
always scoped to the current and, at most, the immediately preceding month's partition, so it never
degrades as historical partitions accumulate.

## Cross-cutting rules for all four tables

- **Never partition on a nullable column.** All four keys (`entry_date`, `posting_date`,
  `created_at`, `movement_date`) are `NOT NULL` at the database level, not just enforced by
  application validation, so a partition-routing failure can never occur from a missing value.
- **Every partition gets its indexes at creation time, before it takes write traffic**, following
  the exact index set shown for the seed month above; "Maintenance" specifies the automation that
  provisions new months (and their indexes) ahead of the calendar reaching them.
- **`deleted_at IS NULL` partial indexes are used only where soft-deleted rows are a meaningful
  fraction of a partition** (mainly `journal_lines`, since `stock_movements` and `ledger_entries`
  are not soft-deletable once posted, and `audit_logs` is never deleted at all). This keeps the
  partial index small and keeps `deleted_at IS NOT NULL` scans — an intentionally rare
  administrative path — off the fast path entirely.
- **No table in this section stores `name_en`/`name_ar`/`tags`/`custom_fields`.** The bilingual and
  enterprise-extensibility field conventions in `DESIGN_CONTEXT.md` §9 apply to master-data
  (dimension) tables, not to these transactional fact tables — there is nothing to translate or
  tag on a posted debit/credit line, and adding such columns here would only inflate row width
  across billions of rows for no product benefit.

# Performance

**Partition pruning** is the entire performance case for this document, and it happens at two
points in query execution. *Plan-time (static) pruning* happens when the filter is a literal
constant the planner can evaluate before execution — `WHERE entry_date >= '2026-07-01' AND
entry_date < '2026-08-01'` is resolved against every partition's bounds during planning, and
non-matching partitions never appear in the plan at all. *Execution-time (runtime) pruning* handles
the more common application case, a bind parameter — `WHERE entry_date >= $1 AND entry_date < $2` —
where PostgreSQL (since v11) defers the exclusion decision until the actual parameter values are
known at the start of execution, still avoiding a scan of non-matching partitions even though the
planner could not know their values in advance. Both are controlled by `enable_partition_pruning`
(on by default; QAYD never disables it) and both are visible directly in `EXPLAIN` output as a
`Subplans Removed: N` annotation — the concrete verification technique used in "Examples" and
required in code review for any new query against these tables.

**Partition-wise joins and aggregates.** When two partitioned tables share the same partition
bounds on the join/group key (e.g. `journal_lines` and `ledger_entries` for the same month, both
partitioned by a date column with identical boundaries), PostgreSQL can join or aggregate
partition-to-partition instead of first collecting all partitions into one stream — this is gated
by `enable_partitionwise_join` and `enable_partitionwise_agg` (both off by default in stock
PostgreSQL because they add planning time to every query, not just partitioned ones). QAYD enables
both at the database level for the primary connection role used by reporting/analytics queries,
and leaves them off for the high-frequency OLTP write-path role, because the planning-time cost is
worth paying only for the larger, less frequent aggregate queries (trial balance, financial
statements) that actually benefit:

```sql
ALTER ROLE qayd_reporting SET enable_partitionwise_join = on;
ALTER ROLE qayd_reporting SET enable_partitionwise_aggregate = on;
```

**Lock budget.** A statement or `ATTACH`/`DETACH` operation that touches many partitions acquires a
lock on each one; the default `max_locks_per_transaction = 64` (times `max_connections`, the actual
shared lock table size) is comfortably sufficient for QAYD's bounded, tens-of-partitions active set,
but any maintenance script that loops over *all* partitions of a table in one transaction (a
platform-wide reindex, for instance) must be sized against this limit or run partition-by-partition
in separate transactions rather than one. QAYD's maintenance jobs (see "Maintenance") always operate
one partition per transaction for exactly this reason, in addition to keeping individual lock
durations short.

**Index scope.** Native declarative partitioning indexes are local to each partition by default —
`CREATE INDEX idx_x ON parent_table (col)` issued against the parent creates a lightweight
"partitioned index" catalog entry and recursively creates (and locks) a real index on every existing
partition, then automatically extends to any partition created afterward. This is the correct way to
declare an index once and have it consistently present everywhere, and it is what the seed DDL in
"Large Tables" assumes; the safe, non-blocking way to *add* such an index to a table that is already
large and live is covered in "Maintenance" (`CONCURRENTLY` per-partition, then `ATTACH`).

**Parallelism.** A sequential or index scan that still has to visit multiple partitions (a
cross-quarter report, for instance) is a natural candidate for PostgreSQL's parallel query executor
— each matching partition can be assigned to a separate worker. QAYD sizes
`max_parallel_workers_per_gather` (default 2, raised to 4 on the primary writer for the reporting
role) and `max_parallel_workers` (platform total pool) to allow a single expensive trial-balance
query to fan out across a quarter's three partitions without starving other concurrent queries of
worker slots.

**Statistics.** `ANALYZE` runs per partition (autovacuum's analyze phase already does this — see
"Maintenance"), which is a genuine advantage over a monolithic table: statistics for July's data
are computed from July's data, not diluted by three-year-old rows with a completely different
company mix. QAYD additionally builds extended statistics on the (`company_id`, `account_id`) pair
for `journal_lines` and `ledger_entries`, because these two columns are correlated in practice (a
retail company's transactions cluster on Revenue and COGS accounts; a services company's cluster
elsewhere), and the default per-column statistics understate the selectivity of a combined filter
without this hint:

```sql
CREATE STATISTICS stx_journal_lines_company_account (dependencies)
    ON company_id, account_id FROM journal_lines;
ANALYZE journal_lines;
```

**Storage parameters differ between the live partition and closed ones.** The current month's
partition for any of the four tables is still receiving `UPDATE`s in edge cases (a draft entry
edited before posting still touches its not-yet-posted `journal_lines` rows) and benefits from the
default `fillfactor` (100 minus update slack); a closed, immutable historical partition benefits
from `fillfactor = 100` (no slack needed — nothing will ever update in place) and from
`autovacuum_vacuum_scale_factor`/`autovacuum_analyze_scale_factor` set near zero once frozen, since
a table that never changes has nothing left for autovacuum to usefully do and should not be
rescanned on the default schedule:

```sql
ALTER TABLE journal_lines_y2025m01 SET (
    fillfactor = 100,
    autovacuum_vacuum_scale_factor = 0.0,
    autovacuum_vacuum_threshold = 100000,
    autovacuum_analyze_scale_factor = 0.0,
    autovacuum_analyze_threshold = 100000
);
```

# Maintenance

Partition maintenance is a recurring operational duty, not a one-time DDL exercise, and QAYD treats
it the same way it treats database migrations: automated, idempotent, and observable, with a human
runbook for the failure path. Two tools carry the load: the **pg_partman** extension for routine
partition lifecycle (create-ahead, retention, default-partition monitoring) and a **Laravel
scheduled Artisan command** as the application-visible orchestration and alerting layer on top of it.

**Provisioning partitions ahead of the calendar.** Every partitioned table always has the current
month plus the next three months already created, so that a clock running slightly fast, a delayed
deploy, or an unexpected end-of-month traffic spike never routes live inserts into the `_default`
partition. With `pg_partman` installed, this is a declarative, one-time-per-table configuration:

```sql
SELECT partman.create_parent(
    p_parent_table  => 'public.journal_lines',
    p_control       => 'entry_date',
    p_type          => 'range',
    p_interval      => 'monthly',
    p_premake       => 3,
    p_default_table => true
);

UPDATE partman.part_config
   SET retention        = '84 months',   -- see "Archiving" for the tiering this feeds
       retention_keep_table = true,      -- detach, do not DROP; archiving job takes it from there
       infinite_time_partitions = true
 WHERE parent_table = 'public.journal_lines';

SELECT cron.schedule('partman-maintenance', '*/30 * * * *', 'CALL partman.run_maintenance_proc()');
```

Where `pg_partman` is not available (e.g. a managed PostgreSQL offering that restricts extensions),
QAYD runs the equivalent logic as an idempotent Laravel Artisan command
(`php artisan db:partitions:ensure-ahead --months=3`) invoked from the platform's own scheduler,
which computes the next N month boundaries per table, checks `pg_inherits`/`pg_partition_tree()` for
existing coverage, and issues any missing `CREATE TABLE ... PARTITION OF ... FOR VALUES FROM ... TO
...` plus the fixed index set for that table. Both paths converge on the same invariant, checked
daily by an alerting query:

```sql
-- Alert condition: this must always return zero rows. A non-empty default partition means an
-- application bug (bad date input, timezone mismatch, or a missed provisioning run) let a row
-- fall outside every declared bound.
SELECT relname, n_live_tup
  FROM pg_stat_user_tables
 WHERE relname LIKE '%_default'
   AND n_live_tup > 0;
```

**Adding an index to an already-large, already-live partitioned table without a long lock.** Once a
partition has real production traffic, a plain `CREATE INDEX` against the parent takes a
`SHARE` lock on every partition for the duration of the build, which is unacceptable on a
multi-hundred-GB table. The safe sequence builds each partition's index concurrently and standalone,
then attaches them into a single logical index definition on the parent:

```sql
-- 1. Build the real index on each existing partition, one at a time, without blocking writes.
CREATE INDEX CONCURRENTLY idx_jl_y2026m06_memo ON journal_lines_y2026m06 (description);
CREATE INDEX CONCURRENTLY idx_jl_y2026m07_memo ON journal_lines_y2026m07 (description);

-- 2. Declare the logical parent index, but ONLY ON the parent shell (no partitions yet) —
--    this creates an "invalid" index that expects children to be attached.
CREATE INDEX idx_jl_memo ON ONLY journal_lines (description);

-- 3. Attach each already-built child index. Once every partition's index is attached, the
--    parent index automatically flips from invalid to valid — no scan, no long lock, at any point.
ALTER INDEX idx_jl_memo ATTACH PARTITION idx_jl_y2026m06_memo;
ALTER INDEX idx_jl_memo ATTACH PARTITION idx_jl_y2026m07_memo;
```

**Adding a new partition boundary retroactively without a full-table validation scan.** Attaching a
partition that already contains data (a backfill import, or converting an existing unpartitioned
table) normally forces PostgreSQL to scan the incoming data to prove it satisfies the new bound. If
a matching `CHECK` constraint already exists and is already validated, PostgreSQL detects the proof
is already available and skips the scan entirely:

```sql
ALTER TABLE journal_lines_y2026m09_staging
    ADD CONSTRAINT chk_bound CHECK (entry_date >= '2026-09-01' AND entry_date < '2026-10-01');
-- (validated instantly on a freshly-loaded staging table)

ALTER TABLE journal_lines ATTACH PARTITION journal_lines_y2026m09_staging
    FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
-- ATTACH is near-instant: the existing, already-validated CHECK constraint is proof enough.

ALTER TABLE journal_lines_y2026m09_staging DROP CONSTRAINT chk_bound; -- now redundant with the partition bound itself
```

**Vacuum and freeze policy.** The current month's partition is vacuumed on the normal autovacuum
schedule (it has real update/delete churn from draft corrections and soft-deletes). The moment a
month closes (its fiscal period locks), QAYD's monthly close job issues one explicit
`VACUUM (FREEZE, ANALYZE) journal_lines_yYYYYmMM;` per affected table — freezing early, right after
the data stops changing, is cheaper than waiting for `autovacuum_freeze_max_age` to force it later
under memory pressure, and it lets the visibility map mark the entire partition all-visible/all-
frozen, after which autovacuum's cost-based scan skips it on every subsequent cycle (the storage
parameters shown in "Performance" make this permanent). **Bloat removal on a partition that still
needs to stay online** (rare, since these tables are append-mostly, but possible after a large
batch `UPDATE`/soft-delete) uses `pg_repack` rather than `VACUUM FULL`, because `pg_repack`
rebuilds the table into a new file and swaps it in with only a brief lock at the very end, whereas
`VACUUM FULL` holds an `ACCESS EXCLUSIVE` lock for its entire, potentially hours-long, run:

```bash
pg_repack --table=journal_lines_y2025m03 --no-order --jobs=4 qayd_production
```

**Monitoring partition health** is a standing dashboard, not an ad hoc query, built on three
catalog sources: `pg_partition_tree('journal_lines')` (the live partition topology, including
sub-partitions), `pg_stat_user_tables` (live/dead tuple counts, last vacuum/analyze time per
partition), and `pg_relation_size`/`pg_total_relation_size` (physical size, to catch a partition
growing unexpectedly faster than its siblings — the earliest signal that a tenant has outgrown plain
`RANGE` and needs the `HASH` escalation described in "Large Tables").

# Archiving

QAYD's archiving model is a three-tier temperature gradient, not a single hot/cold switch, because
the query patterns against financial history are themselves tiered: the current and prior fiscal
quarter are queried constantly (dashboards, open-period reporting); the current fiscal year plus the
prior one or two are queried occasionally (year-over-year comparisons, annual audits); and anything
older is queried rarely, but — because posted financial records are never deleted — must remain
producible on demand indefinitely.

| Tier | Age | Storage | Access | Mechanism |
|---|---|---|---|---|
| Hot | Current + previous 2 partitions | Primary PostgreSQL cluster, NVMe-backed tablespace | Sub-10ms, full index support | Live partition, default storage parameters |
| Warm | 3-24 months | Primary cluster, standard tablespace | Sub-100ms, full index support, but frozen/read-only | `VACUUM FREEZE` + reduced-autovacuum storage parameters (see "Maintenance") |
| Cold | 24+ months | Detached from the live parent; exported to Cloudflare R2 as compressed Parquet, or kept as a plain detached table in an `_archive` schema | Seconds (FDW query) to minutes (restore-on-demand) | `DETACH PARTITION CONCURRENTLY`, then export/move (below) |

**Detaching a partition without blocking concurrent traffic.** PostgreSQL 14+ supports
`CONCURRENTLY` on `DETACH PARTITION`, which avoids the `ACCESS EXCLUSIVE` lock a plain detach would
otherwise take on the parent (and therefore on every other partition's traffic, briefly, at the
moment of detach):

```sql
ALTER TABLE journal_lines DETACH PARTITION journal_lines_y2024m06 CONCURRENTLY;
```

The detached table is now an ordinary, freestanding PostgreSQL table — still fully queryable by its
old name, just no longer part of the `journal_lines` routing tree (a query against the parent will
no longer find it; a query against `journal_lines_y2024m06` directly still works). QAYD's archiving
job then does one of two things with it, depending on the cold-tier policy chosen for that
table/company:

```sql
-- Option A: keep it in PostgreSQL, just move it out of the hot tablespace and rename it out of
-- the active naming convention so it's unambiguous it is archived.
ALTER TABLE journal_lines_y2024m06 SET TABLESPACE archive_ts;
ALTER TABLE journal_lines_y2024m06 RENAME TO journal_lines_archive_y2024m06;
```

```bash
# Option B: export to Cloudflare R2 (S3-compatible) as compressed Parquet, then drop the
# PostgreSQL copy once the export's row count and checksum are verified against the source.
psql qayd_production -c "\copy (SELECT * FROM journal_lines_archive_y2024m06) TO STDOUT WITH CSV" \
  | gzip \
  | aws s3 cp - s3://qayd-cold-archive/journal_lines/y2024m06.csv.gz --endpoint-url "$R2_ENDPOINT"

# Verify before dropping:
psql qayd_production -c "SELECT count(*) FROM journal_lines_archive_y2024m06;"
# ... compare to a row count read back from the uploaded object before DROP TABLE.
```

**Querying cold data without a manual restore.** For the common "we need one archived month back
for an audit, read-only, today" case, QAYD prefers `postgres_fdw` over a full restore: point a
foreign table at the archive tablespace/schema (Option A) or at a small dedicated "cold" PostgreSQL
instance the exported Parquet files are loaded into for the duration of the audit, and query it
through a `UNION ALL` view that overlays the live partitioned table with its archived history —
transparent to the reporting layer, at the cost of FDW-scan latency instead of local-partition
latency:

```sql
CREATE SERVER qayd_cold_archive FOREIGN DATA WRAPPER postgres_fdw
    OPTIONS (host 'cold-archive.internal', dbname 'qayd_archive');
CREATE USER MAPPING FOR qayd_app SERVER qayd_cold_archive
    OPTIONS (user 'qayd_readonly', password 'REDACTED_SET_VIA_SECRETS_MANAGER');
IMPORT FOREIGN SCHEMA archive LIMIT TO (journal_lines_archive_y2024m06)
    FROM SERVER qayd_cold_archive INTO archive_fdw;

CREATE VIEW journal_lines_with_archive AS
    SELECT * FROM journal_lines
    UNION ALL
    SELECT * FROM archive_fdw.journal_lines_archive_y2024m06;
```

**Retention duration is a configurable policy, not a hardcoded constant.** QAYD's platform default
keeps posted financial data in at least the warm tier for 10 years — a duration chosen to comfortably
exceed common Gulf-region statutory retention practice for commercial accounting books, which varies
by jurisdiction and by record type — but the exact figure is stored per-company in a
`data_retention_policies` table (`company_id`, `record_type`, `retention_years`, `jurisdiction`,
`legal_basis`) and enforced by the archiving job reading that table rather than a constant baked
into DDL, so Compliance can tighten or extend any single tenant's policy without an engineering
change, and so the platform never asserts a specific legal figure on an engineering team's behalf —
that confirmation is Compliance/Legal's per-jurisdiction responsibility, not this document's.

**PII minimization is layered independently of financial retention.** The financial substance of an
`audit_logs` row (`action`, `auditable_type`, `auditable_id`, the JSONB diff) is kept for the full
retention window above, but the peripheral request-metadata columns — `ip_address`, `user_agent`,
`device_fingerprint` — carry weaker justification for indefinite retention once the security-forensics
window that motivated capturing them has passed. QAYD nulls these three columns on a rolling
window (default 13 months) independently of when/whether the row's partition itself is archived,
via a narrow, indexed, low-lock `UPDATE`:

```sql
UPDATE audit_logs_y2025m06
   SET ip_address = NULL, user_agent = NULL, device_fingerprint = NULL
 WHERE created_at < now() - interval '13 months'
   AND ip_address IS NOT NULL;
```

This is deliberately scheduled to run once, shortly after a partition transitions from hot to warm
(while it is still cheap to touch), rather than repeatedly — it is a one-time redaction pass per
partition, not an ongoing job.

# Query Optimization

Every query against a partitioned table either prunes or it doesn't, and the difference between the
two is almost always the literal shape of the `WHERE` clause, not the logical intent behind it.
QAYD's code-review checklist for any new query against `journal_lines`, `ledger_entries`,
`audit_logs`, or `stock_movements` enforces the following, in priority order:

**Filter on the raw partition key, never on a function of it.** `WHERE entry_date >=
'2026-07-01' AND entry_date < '2026-08-01'` prunes to exactly one partition. `WHERE
date_trunc('month', entry_date) = '2026-07-01'` does not — wrapping the column in a function
defeats the planner's ability to compare it against partition bounds before execution, so every
partition gets scanned and the function is evaluated row-by-row inside each one. The fix is always
to push the transformation onto the *bind values*, not the column:

```sql
-- BAD: forces a full scan of every partition, function-wrapped column can't be pruned against.
SELECT sum(debit_amount) FROM journal_lines
 WHERE date_trunc('month', entry_date) = '2026-07-01' AND company_id = 4471;

-- GOOD: identical result, prunes to journal_lines_y2026m07 only.
SELECT sum(debit_amount) FROM journal_lines
 WHERE entry_date >= '2026-07-01' AND entry_date < '2026-08-01' AND company_id = 4471;
```

**Verify pruning actually happened — don't assume it.** `EXPLAIN (ANALYZE, BUFFERS)` on any new
report query is mandatory before merge; the plan must show the query touching only the partitions
the date filter implies, surfaced either as a small, named list of `->  Seq Scan on
journal_lines_y2026m07` nodes (good) or, when PostgreSQL can prove even more partitions are
irrelevant than the ones literally scanned, an explicit `Subplans Removed: N` line. A plan that
shows an `Append` node fanning out across a full year of monthly partitions for a query that only
needed one month is a code-review-blocking finding, not a "nice to have" optimization — see
"Examples" for a full worked trace.

**Project only the columns the caller needs.** `SELECT *` against these tables carries real cost
beyond bandwidth: `journal_lines` and `stock_movements` rows are narrow enough that this rarely
forces detoasting, but `audit_logs`' `old_values`/`new_values` JSONB columns are exactly the case
described in "Vertical Partitioning" where an unqualified `SELECT *` on a list endpoint pays for
every diff payload it will never render. List/filter endpoints select the hot columns explicitly;
only a single-record "detail" endpoint selects the JSONB payloads.

**Prefer covering, index-only scans for hot aggregate paths.** The account-statement endpoint
(`GET /api/v1/accounting/accounts/{id}/ledger`) is dominated by `WHERE company_id = ? AND account_id
= ? AND posting_date BETWEEN ? AND ?` against `ledger_entries`; the index declared in "Large Tables"
(`company_id, account_id, fiscal_period_id`) is extended with the amount columns as `INCLUDE`
payload so PostgreSQL can answer the query straight from the index without a heap fetch at all,
provided the partition's visibility map marks the relevant pages all-visible (true for any frozen,
closed-period partition per "Maintenance"):

```sql
CREATE INDEX idx_le_y2026m07_account_covering ON ledger_entries_y2026m07
    (company_id, account_id, posting_date)
    INCLUDE (debit_amount, credit_amount, running_balance);
```

**Materialize genuinely expensive cross-partition aggregates instead of re-scanning on every
request.** A full trial balance for a fiscal year touches twelve partitions no matter how well
they are indexed; QAYD does not ask the OLTP path to answer this live on every page load. A
materialized view, refreshed once at period close and once on demand, absorbs the cross-partition
aggregation cost exactly once per period rather than once per request:

```sql
CREATE MATERIALIZED VIEW mv_trial_balance_2026 AS
    SELECT company_id, account_id, fiscal_period_id,
           sum(debit_amount) AS total_debit, sum(credit_amount) AS total_credit
      FROM ledger_entries
     WHERE posting_date >= '2026-01-01' AND posting_date < '2027-01-01'
     GROUP BY company_id, account_id, fiscal_period_id;

CREATE UNIQUE INDEX ON mv_trial_balance_2026 (company_id, account_id, fiscal_period_id);
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_trial_balance_2026;
```

**Batch writes; never insert fact-table rows one at a time from a loop.** Bulk ingestion paths (bank
feed reconciliation import, OCR-driven bill capture, an ERP migration backfill) use multi-row
`INSERT ... VALUES (...), (...), ...` or `COPY`, both of which amortize per-statement planning and
WAL-flush overhead across many rows instead of paying it per row; this matters more, not less, on a
partitioned table, because each individual `INSERT` still has to resolve which partition it targets,
and doing that resolution once for a 5,000-row `COPY` is materially cheaper than doing it 5,000
times.

**Be deliberate with prepared statements across partition boundaries.** PgBouncer transaction-mode
pooling (QAYD's default) and PostgreSQL's own generic-vs-custom plan choice (`plan_cache_mode`)
interact with partitioned tables in a way worth naming explicitly: a generic plan prepared once for
`WHERE entry_date >= $1 AND entry_date < $2` cannot bake in partition pruning the way a plan built
fresh for known literal bounds can, because a generic plan has to remain valid for *any* future
values of `$1`/`$2`. PostgreSQL's planner already defaults to trying a handful of custom plans
before falling back to generic, and QAYD's date-range application queries are typically re-prepared
per request rather than long-lived, so this is stated here as an awareness item for anyone
introducing a long-lived prepared statement or a connection-pooled `PREPARE`, not as a currently
active problem.

# Future Scaling

Partitioning within a single PostgreSQL primary buys QAYD years of runway, not indefinite runway —
it defers the point at which a single node's disk throughput, WAL generation rate, or replication
lag becomes the binding constraint, but it does not remove that ceiling. This section names the
concrete next steps, in the order QAYD expects to reach for them, without pretending any of them is
needed today.

**Read replicas for reporting/analytics isolation.** The first scale-out step, and the one QAYD
expects to need earliest, is not partitioning-related at all: streaming physical replicas taking
100% of the reporting/analytics role's read traffic (trial balance, financial statements, dashboard
aggregates) off the primary, which then serves only OLTP writes and the low-latency read paths that
require zero replication lag. This is orthogonal to and fully compatible with everything in this
document — partitioned tables replicate exactly like any other table.

**Distributed PostgreSQL (Citus) once vertical headroom on the primary is exhausted.** Citus
extends PostgreSQL with a *distribution column* — conceptually a `HASH` partition key applied
across physical nodes instead of within one — and QAYD's natural distribution column for that move
is `company_id`, for the same reason "Partition Keys" gives for *not* choosing it as the
single-node partition key today: it is the one column present on every row of every one of these
tables that is also the platform's tenancy and billing boundary, so co-locating all of one
company's `journal_lines`, `ledger_entries`, `audit_logs`, and `stock_movements` rows on the same
Citus worker node keeps every one of this platform's actual queries (which are always scoped to one
active company) node-local, with cross-node traffic needed only for genuinely platform-wide
aggregates (which are rare and already off the OLTP path per "Query Optimization"). Adopting Citus
would not discard the work in this document — a Citus "distributed table" is layered *on top of*
local declarative partitioning per node, so QAYD's existing monthly `RANGE` partitions become the
local partitioning scheme *within* each worker's shard of the distributed table.

**Tiered analytical storage for the cold tier.** As the cold tier in "Archiving" grows into the
tens of terabytes, querying it via `postgres_fdw` round-trips to row-oriented PostgreSQL becomes the
wrong tool for the aggregate, full-history questions that tier actually gets asked ("total revenue
by account, every year since inception"). The expected evolution is to land the Parquet exports
already being produced for R2 into a columnar query engine (DuckDB or ClickHouse reading directly
off R2's S3-compatible API, or an Apache Iceberg/Parquet table catalog) rather than a second
PostgreSQL instance — this is a pure win for that specific access pattern because the export format
(Parquet) is already columnar and already the artifact "Archiving" produces; no new export pipeline
is needed, only a new query engine pointed at files that already exist.

**Dedicated "silo" databases for whale tenants.** QAYD's default multi-tenancy model is a shared
"pool" (every company in the same schema, isolated by `company_id` plus row-level security)
specifically because it is the cheapest model to operate at low-to-mid tenant scale and it is what
every DDL example in this document assumes. A small number of very large enterprise customers may
eventually justify a physically dedicated database (their own PostgreSQL instance or Citus
coordinator, potentially a contractual requirement for regulatory data-residency reasons some Gulf
public-sector or banking customers impose) — QAYD's schema is deliberately compatible with this
"pool-then-silo" evolution because every table already carries `company_id` as a real column rather
than relying on schema-per-tenant, so migrating one company's history into a dedicated instance is a
`COPY`/logical-replication exercise filtered by `company_id`, not a schema redesign.

**Event-sourced/CQRS evolution.** `journal_lines` and `ledger_entries` are already, by business
rule, append-only and immutable once posted — which is precisely the shape event sourcing wants.
Nothing in this document requires that evolution, but nothing in it forecloses it either: a future
architecture where posting a journal entry publishes an immutable event that both writes the
OLTP row *and* streams (via logical decoding / Debezium-style CDC) into a separate analytical store
would slot in underneath the existing table shape without changing the API or the accounting
business rules a single bit — partitioning is the mechanism that keeps the OLTP side of that split
healthy for as long as it remains the system of record.

**TimescaleDB as an alternative, not a given.** For a purpose-built time-series table with heavier
continuous-aggregate and automatic-compression needs than QAYD's four fact tables currently have,
TimescaleDB's hypertables offer a more automated version of the same `RANGE`-partitioning idea (with
native columnar compression for old chunks). QAYD does not adopt it today — the hand-rolled
`pg_partman` + native declarative partitioning approach in this document has no operational
dependency on a forked/extended PostgreSQL distribution and every managed PostgreSQL provider
supports it — but it remains a named, evaluated alternative if a specific table's compression or
continuous-aggregate needs someday outgrow what is specified here.

# Examples

**Example 1 — provisioning a full year of partitions ahead of a product launch.** Before a
publicized launch date, an engineer wants to confirm every partition a new fiscal year needs exists
in advance rather than trusting the rolling 3-month-ahead job to keep pace with a traffic spike:

```sql
DO $$
DECLARE
    month_start DATE := '2027-01-01';
    month_end   DATE;
    part_name   TEXT;
BEGIN
    FOR i IN 0..11 LOOP
        month_end := month_start + INTERVAL '1 month';
        part_name := format('journal_lines_y%sm%s',
                             to_char(month_start, 'YYYY'), to_char(month_start, 'MM'));
        EXECUTE format(
            'CREATE TABLE IF NOT EXISTS %I PARTITION OF journal_lines
                 FOR VALUES FROM (%L) TO (%L)', part_name, month_start, month_end);
        EXECUTE format(
            'CREATE INDEX IF NOT EXISTS idx_%s_company ON %I (company_id, account_id)',
            part_name, part_name);
        month_start := month_end;
    END LOOP;
END $$;
```

**Example 2 — verifying partition pruning on the trial-balance query.** Confirming the query shape
recommended in "Query Optimization" actually prunes as expected, using the standard verification
technique required in code review:

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT account_id, sum(debit_amount) AS total_debit, sum(credit_amount) AS total_credit
  FROM ledger_entries
 WHERE company_id = 4471
   AND posting_date >= '2026-07-01' AND posting_date < '2026-08-01'
 GROUP BY account_id;

--                                    QUERY PLAN
-- ------------------------------------------------------------------------------
--  HashAggregate  (actual rows=42 loops=1)
--    Group Key: account_id
--    ->  Append  (actual rows=38104 loops=1)
--          ->  Index Scan using idx_le_y2026m07_account_period on ledger_entries_y2026m07
--                Index Cond: (company_id = 4471)
--    Subplans Removed: 11
-- (11 sibling monthly partitions eliminated at planning time; only July's partition is touched.)
```

**Example 3 — the composite escalation trigger in practice.** A monitoring alert fires because
`journal_lines_y2026m11` has crossed 180 million live rows three weeks before month-end, projecting
past the 200-million-row composite-partitioning threshold from "Large Tables." The response is not
to retrofit November (already partially populated, and about to close) but to pre-empt December:

```sql
-- December is provisioned directly as a RANGE-then-HASH composite from the start, sized for
-- roughly double November's per-tenant peak observed in monitoring.
CREATE TABLE journal_lines_y2026m12 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-12-01') TO ('2027-01-01')
    PARTITION BY HASH (company_id);

DO $$
BEGIN
    FOR i IN 0..15 LOOP
        EXECUTE format(
            'CREATE TABLE journal_lines_y2026m12_h%s PARTITION OF journal_lines_y2026m12
                 FOR VALUES WITH (MODULUS 16, REMAINDER %s)', i, i);
        EXECUTE format(
            'CREATE INDEX idx_jl_y2026m12_h%s_company ON journal_lines_y2026m12_h%s (company_id, account_id)',
            i, i);
    END LOOP;
END $$;
```

**Example 4 — end-to-end archive-and-restore for an external audit request.** A company under a
statutory audit needs its 2023 books re-examined; the 2023 partitions were detached and exported to
R2 eighteen months ago per "Archiving." Restoring read access:

```bash
# 1. Pull the archived object back from R2.
aws s3 cp s3://qayd-cold-archive/journal_lines/y2023m01.csv.gz - --endpoint-url "$R2_ENDPOINT" \
  | gunzip > /tmp/journal_lines_y2023m01.csv

# 2. Load it into a scratch table on the (already-provisioned) cold-archive PostgreSQL instance.
psql qayd_archive -c "CREATE TABLE journal_lines_y2023m01 (LIKE journal_lines_template);"
psql qayd_archive -c "\copy journal_lines_y2023m01 FROM '/tmp/journal_lines_y2023m01.csv' WITH CSV"
```
```sql
-- 3. Expose it to the reporting role via the standing FDW server (see "Archiving"), scoped to
--    read-only, for the duration of the audit engagement only.
IMPORT FOREIGN SCHEMA archive LIMIT TO (journal_lines_y2023m01)
    FROM SERVER qayd_cold_archive INTO archive_fdw;

SELECT * FROM archive_fdw.journal_lines_y2023m01
 WHERE company_id = 4471 AND entry_date >= '2023-01-01' AND entry_date < '2023-02-01';
```

**Example 5 — Laravel migration wrapping the DDL for CI/CD.** QAYD's Laravel migrations issue raw
SQL for anything partition-related (Eloquent's schema builder has no native partitioning support),
kept idempotent so the same migration can safely run against an environment that already has some
months provisioned:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS journal_lines (
                id                BIGINT GENERATED ALWAYS AS IDENTITY,
                company_id        BIGINT NOT NULL REFERENCES companies(id),
                branch_id         BIGINT NULL REFERENCES branches(id),
                journal_entry_id  BIGINT NOT NULL REFERENCES journal_entries(id),
                account_id        BIGINT NOT NULL REFERENCES accounts(id),
                debit_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
                credit_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
                entry_date        DATE NOT NULL,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at        TIMESTAMPTZ NULL,
                PRIMARY KEY (id, entry_date)
            ) PARTITION BY RANGE (entry_date);
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS journal_lines_default
                PARTITION OF journal_lines DEFAULT;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS journal_lines CASCADE');
    }
};
```

# End of Document

