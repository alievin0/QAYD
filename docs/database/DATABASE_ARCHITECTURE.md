# Database Layer Architecture — QAYD Database Layer

Version: 1.0
Status: Design Specification
Module: Database
Submodule: Database Architecture
---

# Purpose

This document specifies the database layer that underlies every module of QAYD: the PostgreSQL
topology, schema organization, tenancy model, data-type standards, extensibility mechanism, scaling
path, high-availability posture, and the contract between PostgreSQL and the Laravel 12 backend
that is QAYD's single source of truth for business logic. It does not re-specify any individual
module's tables (those live in their own module documents — `CHART_OF_ACCOUNTS.md`,
`JOURNAL_ENTRIES.md`, `INVENTORY.md`, etc.); it specifies the platform-wide rules those tables must
obey, the infrastructure they run on, and the operational guarantees the database layer provides to
every module: durability, isolation between tenants, immutability of posted financial records, and
predictable performance as a company's transaction volume grows from a single-branch trading shop
to a multi-branch, multi-currency enterprise.

PostgreSQL is the sole system of record for QAYD. Redis accelerates reads and queues background
work but never holds authoritative financial state. Cloudflare R2 stores binary attachments;
PostgreSQL stores their metadata. Every other document in this repository assumes the conventions
defined here — standard columns, money types, `company_id` scoping, soft deletes — without
repeating them.

# Design Principles

1. **PostgreSQL is authoritative.** No financial fact exists unless it is committed to PostgreSQL.
   Redis, search indexes, and materialized report caches are derived and disposable; they can be
   rebuilt from PostgreSQL at any time with zero data loss.
2. **Multi-tenancy is a column, not a database.** All companies share one PostgreSQL cluster and one
   schema (`public`). Isolation is enforced by `company_id` on every business table, indexed,
   filtered by the application layer on every query, and defended in depth by row-level security
   policies (see `# Security Overview`). This keeps operational complexity (backups, migrations,
   connection pooling, monitoring) linear in the number of servers, not in the number of tenants.
3. **Immutability of posted financial state.** Once a `journal_entries` row transitions to `posted`,
   its `journal_lines` are never updated or hard-deleted. Corrections are new reversing/adjusting
   entries. This makes the ledger a durable, append-mostly log — the property every audit, every
   trial balance, and every AI reconciliation agent relies on.
4. **Schema mirrors business domains, not UI screens.** Tables are grouped into logical domains
   (identity, accounting, sales, purchasing, inventory, payroll, tax, ai, system) so that ownership,
   migration order, and read-replica routing can reason about a domain as a unit.
5. **Every write is attributable and auditable.** `created_by`/`updated_by` plus a row in
   `audit_logs` for every mutation is a hard requirement, not an optional feature — it is what lets
   QAYD promise "who changed this, when, and why" to an auditor with zero extra instrumentation.
6. **Design for horizontal read scale before horizontal write scale.** QAYD's read:write ratio is
   dominated by reporting, dashboards, and AI agents reading historical data; the topology below
   optimizes for cheap read replicas first, and only introduces sharding-by-company as a later,
   optional escape valve (see `# Scaling Strategy`).
7. **The database enforces what the application must never get wrong.** Financial correctness
   (balanced journals, non-negative stock where required, referential integrity, immutability of
   posted rows) is enforced with `CHECK` constraints, triggers, and foreign keys wherever
   practical — not left purely to Laravel service-layer discipline, because a second write path
   (a migration script, a support console, a future service) will eventually bypass the Eloquent
   layer.

# PostgreSQL Topology

## Cluster shape

QAYD runs PostgreSQL 15+ on AWS RDS (Multi-AZ) as the baseline topology:

```
                        ┌─────────────────────┐
                        │   Primary (writer)  │
                        │  db.r6g.xlarge       │
                        │  AWS RDS Multi-AZ    │
                        └──────────┬───────────┘
                                   │ streaming replication (async)
                 ┌─────────────────┼─────────────────┐
                 ▼                 ▼                 ▼
        ┌────────────────┐ ┌────────────────┐ ┌────────────────┐
        │ Read Replica 1 │ │ Read Replica 2 │ │ Read Replica N │
        │ Reports / BI   │ │ AI Agents      │ │ Ad-hoc / Admin │
        └────────────────┘ └────────────────┘ └────────────────┘
```

- **Primary** takes all writes and any read that must see its own most-recent write
  (read-your-writes inside a single request lifecycle).
- **Read Replica 1** is dedicated to `report_runs`, dashboards, and exports — workloads that can
  tolerate the default replica lag (typically < 1s, alerted above 30s) and that would otherwise
  compete with OLTP traffic for buffer cache.
- **Read Replica 2** is dedicated to the AI Layer (FastAPI): every AI agent (`General Accountant`,
  `Auditor`, `Forecast Agent`, etc.) reads context exclusively from this replica so that large,
  irregular AI read patterns (full-table scans for anomaly detection, embeddings backfills) never
  degrade transactional latency for end users.
- **Read Replica N** is scaled horizontally on demand for ad-hoc analyst/admin queries and is the
  first target of `pg_terminate_backend` if a runaway query threatens replica health.
- Multi-AZ standby is the automatic failover target (RPO ≈ 0, RTO ≈ 60–120s) and is never used for
  application reads — it exists purely for availability, not scale.

## Connection pooling — PgBouncer

Laravel's PHP-FPM workers and FastAPI's async workers both open short-lived connections at a rate
Postgres's own connection model (one OS process per connection) cannot absorb directly. PgBouncer
sits in front of every Postgres endpoint in `transaction` pooling mode:

```ini
; /etc/pgbouncer/pgbouncer.ini
[databases]
qayd_primary   = host=qayd-primary.rds.amazonaws.com   port=5432 dbname=qayd
qayd_replica_1 = host=qayd-replica-1.rds.amazonaws.com port=5432 dbname=qayd
qayd_replica_2 = host=qayd-replica-2.rds.amazonaws.com port=5432 dbname=qayd

[pgbouncer]
listen_addr          = 0.0.0.0
listen_port           = 6432
auth_type             = scram-sha-256
auth_file             = /etc/pgbouncer/userlist.txt
pool_mode             = transaction
max_client_conn       = 4000
default_pool_size     = 60
reserve_pool_size     = 10
reserve_pool_timeout  = 3
server_idle_timeout   = 600
query_wait_timeout    = 30
log_connections       = 1
log_disconnections    = 1
```

Rules for using `transaction` pooling correctly:

- No session-level state (`SET`, prepared statements outside the driver's per-transaction cache,
  advisory locks held across statements) may leak between Laravel requests. Laravel's PDO driver is
  configured with `PDO::ATTR_PERSISTENT => false` and `options => ['sslmode' => 'require']`.
- Laravel's `config/database.php` connections point at PgBouncer's port (6432), never at Postgres's
  port (5432), for all application traffic. Only migrations and `apply_migration`-style DDL connect
  directly to the primary on 5432, because `CREATE INDEX CONCURRENTLY` and other DDL are incompatible
  with pooled transactions holding implicit transactions open.

```php
// config/database.php
'pgsql' => [
    'driver'   => 'pgsql',
    'host'     => env('DB_HOST', 'pgbouncer.internal'),
    'port'     => env('DB_PORT', '6432'),
    'database' => env('DB_DATABASE', 'qayd'),
    'sslmode'  => 'require',
    'options'  => ['persistent' => false],
],
'pgsql_replica' => [
    'driver'   => 'pgsql',
    'host'     => env('DB_REPLICA_HOST', 'pgbouncer-replica.internal'),
    'port'     => 6432,
    'database' => 'qayd',
    'sslmode'  => 'require',
],
```

## `postgresql.conf` baseline

```conf
# Memory
shared_buffers                  = '8GB'          # ~25% of instance RAM
effective_cache_size            = '24GB'         # ~75% of instance RAM
work_mem                        = '32MB'
maintenance_work_mem            = '1GB'

# WAL / durability
wal_level                       = replica
max_wal_senders                 = 10
max_replication_slots           = 10
synchronous_commit              = on             # off is NEVER used for financial writes
checkpoint_timeout              = '15min'
checkpoint_completion_target    = 0.9

# Query planning
random_page_cost                = 1.1            # SSD-backed storage (gp3/io2)
effective_io_concurrency        = 200
default_statistics_target       = 200

# Observability
shared_preload_libraries        = 'pg_stat_statements'
pg_stat_statements.track        = all
pg_stat_statements.max          = 10000
log_min_duration_statement      = 250            # ms; flag slow queries
log_lock_waits                  = on
```

## Required extensions

```sql
CREATE EXTENSION IF NOT EXISTS pgcrypto;          -- gen_random_uuid(), digest(), pgp_sym_encrypt/decrypt
CREATE EXTENSION IF NOT EXISTS pg_trgm;           -- trigram GIN indexes for fuzzy name/description search
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";       -- uuid_generate_v4() for public-facing identifiers
CREATE EXTENSION IF NOT EXISTS pg_stat_statements; -- query-level performance telemetry (see below)
```

| Extension | Used for |
|---|---|
| `pgcrypto` | `gen_random_uuid()` for `request_id`/idempotency keys; `digest()` for webhook signature verification; `pgp_sym_encrypt` as a defense-in-depth option for select PII columns beyond application-layer encryption. |
| `pg_trgm` | GIN trigram indexes on `customers.name_en`, `vendors.name_en`, `products.name_en`, etc. for typo-tolerant search that Laravel's search endpoints call via `ILIKE '%term%'` or `similarity()`. |
| `uuid-ossp` | Public API-facing UUIDs (e.g. `invoices.public_uuid`) that must never leak the sequential `BIGINT` primary key (which would reveal row-count/velocity to a customer looking at consecutive invoice links). |
| `pg_stat_statements` | Feeds the slow-query dashboard and the `EXPLAIN (ANALYZE, BUFFERS)` triage workflow described in `# Best Practices`. |

# Schema Organization

All tables live in the single `public` schema (multi-tenancy is row-based, not schema-based — see
`# Multi-Tenant Foundation`), but are organized into logical domains via naming prefixes, a
`pg_catalog.obj_description()` comment tag, and physical migration-file grouping. This lets tooling
(ERD generators, the `search_docs`-style schema browser, migration ordering) reason about a domain
without needing PostgreSQL schemas, which would complicate cross-domain foreign keys and PgBouncer's
`transaction` pooling (search_path juggling per tenant is a well-known anti-pattern this design
deliberately avoids).

| Domain | Representative tables | Owning module doc |
|---|---|---|
| `identity` | `users`, `companies`, `company_users`, `branches`, `departments`, `roles`, `permissions` | Foundation |
| `accounting` | `accounts`, `account_types`, `fiscal_years`, `fiscal_periods`, `journal_entries`, `journal_lines`, `ledger_entries` | Accounting |
| `sales` | `customers`, `sales_quotations`, `sales_orders`, `invoices`, `invoice_items`, `credit_notes`, `receipts` | Sales |
| `purchasing` | `vendors`, `purchase_orders`, `bills`, `bill_items`, `goods_receipts`, `debit_notes`, `vendor_payments` | Purchasing |
| `inventory` | `products`, `warehouses`, `inventory_items`, `stock_movements`, `stock_adjustments`, `stock_transfers` | Inventory |
| `payroll` | `employees`, `payroll_runs`, `payroll_items`, `salary_components`, `payslips` | Payroll |
| `tax` | `tax_codes`, `tax_rates`, `tax_transactions`, `tax_returns` | Tax |
| `ai` | `ai_conversations`, `ai_messages`, `ai_agents`, `ai_tasks`, `ai_decisions` | AI Architecture |
| `system` | `audit_logs`, `attachments`, `notifications`, `report_definitions`, `report_runs` | Foundation |

A lightweight convention tags each table with its domain for tooling that introspects the catalog:

```sql
COMMENT ON TABLE invoices IS 'domain:sales; owner:sales-module; pii:customer_name,customer_email';
```

Migration files are grouped on disk by the same domain (`database/migrations/2026_.._accounting_*`,
`..._sales_*`, ...), and Laravel's migration runner executes them in dependency order: `identity` →
`accounting` (chart of accounts must exist before anything can post to it) → `sales`/`purchasing`/
`inventory`/`payroll`/`tax` (in parallel, since they only depend on `identity` + `accounting`) →
`ai`/`system` (depend on everything, since `audit_logs` and `attachments` are polymorphic over all
of the above).

# Core Entity Groups & How They Relate

```
companies ──┬──> branches ──> departments ──> employees
            ├──> users (via company_users, many-to-many)
            ├──> accounts ──> journal_entries ──> journal_lines ──> ledger_entries
            │                                          ▲
            ├──> customers ──> invoices ──> invoice_items    │ (posts via domain event)
            │              └─> receipts ─────────────────────┘
            ├──> vendors ──> bills ──> bill_items ─────────────┘
            ├──> products ──> inventory_items ──> stock_movements ──> (posts to Inventory GL) ─┘
            ├──> bank_accounts ──> bank_transactions ──> bank_reconciliations
            ├──> employees ──> payroll_runs ──> payroll_items ──> payslips ──> (posts to Payroll GL)
            └──> tax_codes ──> tax_transactions ──> tax_returns
```

Every arrow above is a `company_id`-scoped foreign key. Cross-domain edges (invoice → journal entry,
stock movement → journal entry) are **not** direct foreign keys from the source table into
`journal_entries`; instead the source row carries `posted_journal_entry_id BIGINT NULL` set after
the domain event (`invoice.posted`, `stock_movement.posted`) is processed by the Accounting module's
listener. This keeps each module's writer path decoupled from Accounting's internals — a purchasing
developer never needs to know how the Accounting service structures a journal entry, only that
calling `AccountingService::postFromSource($sourceModel)` will populate `posted_journal_entry_id`.

`ledger_entries` is the one table in this diagram that is not primary data: it is a
denormalized projection of `journal_lines` (joined to `accounts`, `cost_centers`, `projects`) kept in
sync by an `AFTER INSERT/UPDATE` trigger on `journal_lines`, purely to make Trial Balance and GL
drill-down queries index-only-scan fast without repeatedly joining five tables at read time. It can
be `TRUNCATE`d and rebuilt from `journal_lines` at any time with a single backfill job — it holds no
fact that is not derivable from posted journal lines.

# Multi-Tenant Foundation

Every business table — with no exception — carries:

```sql
company_id BIGINT NOT NULL REFERENCES companies(id)
```

and every business table has, at minimum, this index:

```sql
CREATE INDEX idx_<table>_company ON <table> (company_id) WHERE deleted_at IS NULL;
```

Most tables extend this to a composite leading-`company_id` index matching their dominant query
shape, e.g.:

```sql
CREATE INDEX idx_invoices_company_status_date
    ON invoices (company_id, status, invoice_date DESC)
    WHERE deleted_at IS NULL;
```

`company_id` is always the **first** column in any composite index on a tenant table. Because every
query from the application layer is company-scoped (enforced by the `EnsureCompanyContext`
middleware described in `# Integration With Backend`), this guarantees the planner can always start
from a tight index range scan on the tenant's own rows, regardless of how large other tenants'
partitions of the same table have grown — this is the single most important performance property of
row-based multi-tenancy at QAYD's scale.

`branch_id BIGINT NULL REFERENCES branches(id)` is present wherever branch-level scoping is
meaningful (invoices, stock movements, payroll runs) and `NULL` means "company-wide, not
branch-specific" (e.g. a shared chart-of-accounts row). `branch_id`, when present, is always the
second column after `company_id` in that table's primary access-pattern index.

Tenant isolation is defended at three independent layers, so that a bug at any single layer cannot
leak data across companies:

1. **Application layer (primary):** every Eloquent model uses a global scope that injects
   `WHERE company_id = ?` from the authenticated request's active-company context. This is the layer
   developers interact with day to day.
2. **Database layer (defense in depth):** PostgreSQL Row-Level Security policies (below) reject any
   query that does not set the tenant session variable, so a raw SQL bug, a forgotten scope, or a
   compromised query builder call fails closed instead of leaking rows.
3. **Audit layer (detective control):** `audit_logs` records `company_id` on every write; a nightly
   job flags any `audit_logs` row whose `company_id` does not match the acting user's authorized
   companies (`company_users`), which would indicate a tenancy breach anywhere upstream.

```sql
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation_invoices ON invoices
    USING (company_id = current_setting('qayd.current_company_id')::bigint);

CREATE POLICY tenant_isolation_invoices_write ON invoices
    FOR ALL
    USING (company_id = current_setting('qayd.current_company_id')::bigint)
    WITH CHECK (company_id = current_setting('qayd.current_company_id')::bigint);
```

Laravel sets the session variable at the start of every request, inside the same DB transaction that
serves the request, via a `DB::statement("SET qayd.current_company_id = ?", [$companyId])` call in
`EnsureCompanyContext` middleware, immediately after resolving the active company from
`X-Company-Id` and verifying the authenticated user's `company_users` membership. Because PgBouncer
runs in `transaction` pooling mode, this `SET` is transaction-scoped (`SET LOCAL` semantics achieved
by wrapping the request's DB work in an explicit transaction) and never leaks to the next pooled
client.

# Data Types & Money Handling

| Concept | Type | Rationale |
|---|---|---|
| Primary key | `BIGINT GENERATED ALWAYS AS IDENTITY` | Sequential, index-friendly, never reused; `IDENTITY` (not `SERIAL`) is the SQL-standard, Postgres-recommended form and plays correctly with `pg_dump`/logical replication ownership. |
| Public-facing id | `UUID DEFAULT gen_random_uuid()` on a `public_uuid` column, unique-indexed | Used in any URL, webhook payload, or externally shared reference so sequential IDs never leak business volume. |
| Money (any amount) | `NUMERIC(19,4)` | Exact decimal arithmetic — floating point is never used for money anywhere in QAYD, including in Laravel PHP (`bcmath`/`brick/money`, never native float) and in FastAPI (Python `Decimal`, never `float`). Precision 19,4 supports up to ~999 trillion with 4 decimal places, comfortably covering KWD's 3-decimal minor unit and any larger-denomination currency. |
| Exchange rate | `NUMERIC(18,6)` | 6 decimal places avoids compounding rounding error across multi-step conversions (transaction currency → base currency → reporting currency). |
| Quantity | `NUMERIC(18,4)` | Supports fractional units (weight, volume) without float drift; `unit_conversions` handles UoM-to-UoM math using the same type. |
| Percentage/rate (tax, discount) | `NUMERIC(7,4)` | e.g. `12.5000` — four decimal places for compounded/prorated tax calculations. |
| Timestamps | `TIMESTAMPTZ` | Always timezone-aware; stored in UTC, rendered per company/branch timezone at the API/UI layer. `DATE` (no time) is used only for calendar concepts with no time component (`invoice_date`, `fiscal_periods.start_date`). |
| Enumerated state | native `ENUM` type (e.g. `journal_entry_status`) | Preferred over free-text `VARCHAR` + `CHECK` for a closed, rarely-changing state set — self-documenting in `\d` and in generated TypeScript types; changed only via `ALTER TYPE ... ADD VALUE` in a migration, never in application code. |
| Free-text status with app-level growth | `VARCHAR(30) CHECK (status IN (...))` | Used instead of `ENUM` where the value set is expected to grow via configuration rather than migration (e.g. `custom_fields`-driven pipeline stages). |
| Identifiers/codes | `VARCHAR(n)`, never `TEXT`, with an explicit, reviewed length cap | Keeps index sizes predictable; QAYD never uses unbounded `TEXT` for anything that is looked up by equality or indexed. |
| Bilingual text | `name_en VARCHAR`, `name_ar VARCHAR` as sibling columns | Never a single `jsonb` "translations" blob for primary display names — sibling columns are indexable, sortable, and NOT NULL-enforceable per language independently. |

Multi-currency amounts always appear as a quadruple, never as a single ambiguous "amount" column:

```sql
amount               NUMERIC(19,4) NOT NULL,          -- in transaction currency
currency_code        CHAR(3)       NOT NULL,          -- ISO 4217, e.g. 'KWD', 'USD', 'SAR', 'AED'
exchange_rate        NUMERIC(18,6) NOT NULL DEFAULT 1, -- transaction currency -> company base currency
base_amount          NUMERIC(19,4) NOT NULL,          -- amount * exchange_rate, stored (not computed
                                                        -- at read time) so historical rates are frozen
CONSTRAINT chk_base_amount_consistent
    CHECK (base_amount = ROUND(amount * exchange_rate, 4))
```

`base_amount` is stored, not a generated/virtual column, specifically so that a later change to a
company's base currency or to how rates are sourced never silently rewrites historical financial
statements — the frozen `base_amount` at the time of the transaction is the permanent accounting
fact; only the `CHECK` constraint validates internal consistency at write time.

# Extensibility

QAYD ships a fixed, reviewed schema for every well-known business concept, but every company has
unmodeled fields (an industry-specific attribute on a product, a custom approval note on a bill). Two
mechanisms cover this without ad-hoc `ALTER TABLE` per customer:

```sql
custom_fields JSONB NOT NULL DEFAULT '{}'::jsonb,
tags          JSONB NOT NULL DEFAULT '[]'::jsonb
```

present on every master-data and document table (`products`, `customers`, `vendors`, `invoices`,
`bills`, ...). Rules of use:

- `custom_fields` values are always primitives or shallow objects — never another financial fact
  that participates in double-entry math. If a "custom field" starts being summed into a total, it
  is promoted to a real column via a normal migration; `custom_fields` is for descriptive metadata,
  not shadow business logic.
- Every `custom_fields` schema per (`company_id`, table) is declared in a `custom_field_definitions`
  table (name, data type, validation rule, required flag) so the FormRequest layer can validate
  incoming values before they ever reach `custom_fields`, and so the frontend can render the right
  input control without hardcoding customer-specific knowledge.
- Indexing: a targeted expression/GIN index is added only for `custom_fields` keys a company actually
  filters or sorts on, discovered from real query logs — not preemptively for every possible key.

```sql
CREATE TABLE custom_field_definitions (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    table_name    VARCHAR(60)  NOT NULL,
    field_key     VARCHAR(60)  NOT NULL,
    label_en      VARCHAR(120) NOT NULL,
    label_ar      VARCHAR(120) NOT NULL,
    data_type     VARCHAR(20)  NOT NULL CHECK (data_type IN
                       ('text','number','date','boolean','select','multiselect')),
    options       JSONB NULL,                     -- for select/multiselect
    is_required   BOOLEAN NOT NULL DEFAULT false,
    display_order SMALLINT NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_custom_field UNIQUE (company_id, table_name, field_key)
);

-- Example: index a specific hot custom_fields key once usage justifies it
CREATE INDEX idx_products_custom_batch_grade
    ON products ((custom_fields->>'batch_grade'))
    WHERE custom_fields ? 'batch_grade';
```

`tags` (a JSONB array of short strings) backs free-form categorization/filtering
(`WHERE tags @> '["urgent"]'`) and is indexed company-wide with a GIN index using the `jsonb_path_ops`
operator class when tag-filtering becomes a dominant query pattern for a table:

```sql
CREATE INDEX idx_invoices_tags ON invoices USING GIN (tags jsonb_path_ops);
```

# Scaling Strategy

## Partitioning (near-term, active today for high-volume tables)

`audit_logs`, `stock_movements`, `bank_transactions`, and `ai_messages` are the tables expected to
grow fastest and least usefully accessed beyond a recent window in the OLTP path. They are declared
as **range-partitioned by month** on `created_at` from day one, so partition maintenance is routine
rather than a future migration crisis:

```sql
CREATE TABLE audit_logs (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    user_id       BIGINT NULL REFERENCES users(id),
    action        VARCHAR(60)  NOT NULL,
    auditable_type VARCHAR(80) NOT NULL,
    auditable_id  BIGINT NOT NULL,
    old_values    JSONB NULL,
    new_values    JSONB NULL,
    reason        TEXT NULL,
    ip_address    INET NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

CREATE TABLE audit_logs_2026_07 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE audit_logs_2026_08 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');

CREATE INDEX idx_audit_logs_company ON audit_logs (company_id, created_at DESC);
```

A monthly cron job (Laravel scheduled command, run via `php artisan db:create-next-partition`)
creates next month's partition ahead of time and, for `audit_logs` only, detaches (never drops)
partitions older than the company's configured retention policy (regulatory default: 10 years),
moving detached partitions to cold storage (exported to R2 as Parquet) rather than deleting them —
consistent with the platform's never-hard-delete-financial-data rule extended to its audit trail.

`journal_lines` and `ledger_entries` are **not** partitioned by default: they are the tables every
report depends on, cross-period reporting (year-over-year) is a first-class query pattern, and their
row size is small enough that a well-indexed single table comfortably serves companies with millions
of lines. They become partition candidates (by `fiscal_year_id`) only past a measured threshold —
see `# Future Improvements`.

## Sharding by company (future, not implemented today)

The row-based, single-cluster model above scales to the overwhelming majority of QAYD's expected
customer distribution. The explicitly reserved future escape valve, should a small number of
extremely high-volume enterprise tenants outgrow a single primary's write capacity, is
**sharding by `company_id` range or hash across multiple PostgreSQL clusters**, coordinated by a
thin routing layer:

```
company_id → shard_map lookup (Redis-cached, DB-backed) → target cluster DSN
```

This is deliberately **not** built today because: (a) it multiplies operational surface area
(migrations, backups, cross-shard reporting) for a scale QAYD has not reached; (b) the row-based
design above imposes zero migration cost to adopt sharding later — every table already carries
`company_id` as the natural shard key, and no cross-company foreign key exists anywhere in the
schema (the one thing that would make sharding retroactively hard). When the trigger threshold is
hit (see `# Future Improvements`), the migration path is: pick the largest tenants first, dual-write
during a backfill window, cut over per company with zero cross-shard joins required by design.

# High Availability & Failover

- **Multi-AZ synchronous standby** (AWS RDS Multi-AZ) provides automatic failover with RPO ≈ 0
  (synchronous physical replication to the standby) and observed RTO of 60–120 seconds; Laravel's
  DB connection layer retries with exponential backoff (`retry_after` in `config/database.php`'s
  `sticky` + `queue` connection settings) so an in-flight request fails over transparently.
- **Cross-region read replica** (separate AWS region) exists purely for disaster recovery, not
  latency: promoted only in a full-region-outage runbook, never used for routine traffic.
- **Point-in-time recovery (PITR):** continuous WAL archiving with a 35-day retention window; any
  point in that window can be restored to a new instance in under 30 minutes for forensic recovery
  or "undo a bad migration" scenarios, independent of the application-level soft-delete guarantee.
- **Backup verification:** a weekly automated job restores the latest snapshot into an isolated
  instance and runs a schema+row-count smoke test, so "the backup exists" is never confused with
  "the backup is restorable."
- **Connection draining on deploys:** PgBouncer's `PAUSE`/`RESUME` commands are used during planned
  maintenance (major version upgrades, large `ALTER TABLE`) to drain in-flight transactions cleanly
  instead of hard-killing connections.
- **Replica lag alerting:** CloudWatch alarms fire when any read replica's `ReplicaLag` exceeds 10
  seconds (warning) or 30 seconds (page), because Reports/AI traffic silently reading stale data past
  that threshold would produce financial statements a customer could act on incorrectly.

# Integration With Backend

QAYD's backend uses Laravel Eloquent as the ORM, but no controller and no queued job talks to
Eloquent models directly for business-significant reads or writes — the Repository + Service pattern
mandated by the platform's Clean Architecture rule is what actually touches the database layer.

```php
// app/Repositories/Contracts/InvoiceRepositoryInterface.php
interface InvoiceRepositoryInterface
{
    public function findForCompany(int $companyId, int $invoiceId): ?Invoice;
    public function paginateByStatus(int $companyId, string $status, int $perPage = 25): LengthAwarePaginator;
    public function create(array $attributes): Invoice;
    public function markPosted(Invoice $invoice, int $journalEntryId): Invoice;
}

// app/Repositories/Eloquent/InvoiceRepository.php
final class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function findForCompany(int $companyId, int $invoiceId): ?Invoice
    {
        return Invoice::query()
            ->where('company_id', $companyId)   // defense-in-depth; global scope also applies this
            ->whereNull('deleted_at')
            ->find($invoiceId);
    }

    public function markPosted(Invoice $invoice, int $journalEntryId): Invoice
    {
        return DB::transaction(function () use ($invoice, $journalEntryId) {
            $invoice->update([
                'status'                  => 'posted',
                'posted_journal_entry_id' => $journalEntryId,
                'updated_by'              => auth()->id(),
            ]);
            return $invoice->refresh();
        });
    }
}

// app/Services/InvoiceService.php
final class InvoiceService
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private AccountingService $accounting,
        private AuditLogger $auditLogger,
    ) {}

    public function post(Invoice $invoice): Invoice
    {
        $journalEntry = $this->accounting->postFromSource($invoice); // event-driven posting
        $posted = $this->invoices->markPosted($invoice, $journalEntry->id);
        $this->auditLogger->log('invoice.posted', $invoice, ['status' => 'posted']);
        return $posted;
    }
}
```

Controllers depend only on Services; Services depend only on Repository interfaces (bound to
Eloquent implementations in a `RepositoryServiceProvider`), never on `DB::` or `Model::` calls
directly, except inside the repository implementation itself. This is what lets the AI layer
(FastAPI) and any future secondary API surface reuse the exact same Service layer without
duplicating validation, permission checks, or posting logic — the one and only path to a database
write is Controller → FormRequest → Service → Repository → Model → PostgreSQL.

Every tenant-scoped Eloquent model uses a shared global scope, applied automatically:

```php
// app/Models/Concerns/BelongsToCompany.php
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if ($companyId = app(CurrentCompany::class)->id()) {
                $builder->where($builder->getModel()->getTable() . '.company_id', $companyId);
            }
        });

        static::creating(function (Model $model) {
            $model->company_id ??= app(CurrentCompany::class)->id();
            $model->created_by ??= auth()->id();
        });

        static::updating(fn (Model $model) => $model->updated_by = auth()->id());
    }
}
```

Read/write connection routing is explicit, not relying on Laravel's automatic sticky-read heuristic
alone, for any query the AI layer or Reports module issues:

```php
DB::connection('pgsql_replica')->table('journal_lines')
    ->select(...)
    ->where('company_id', $companyId)
    ->get();
```

Migrations are ordinary Laravel migrations (`database/migrations/*.php`), run via
`php artisan migrate` against the primary's direct port (bypassing PgBouncer, per `# PostgreSQL
Topology`), with `Schema::table()` used for `ADD COLUMN`/`ADD INDEX` and raw `DB::statement()` for
anything Laravel's schema builder does not express natively (partitioned tables, `ENUM` types, `CHECK`
constraints with subqueries, RLS policies).

# Security Overview

- **Encryption in transit:** `sslmode=require` (upgraded to `verify-full` in production) on every
  connection, including PgBouncer→Postgres and Laravel→PgBouncer hops.
- **Encryption at rest:** AWS RDS storage encryption (AES-256) on the primary, all replicas, and all
  automated snapshots; KMS-managed keys, rotated per AWS's managed rotation schedule.
- **Column-level protection for high-sensitivity PII:** national ID numbers, bank account numbers on
  `vendor_bank_accounts`, and payroll bank details are additionally encrypted at the application
  layer (Laravel's `encrypted` Eloquent cast, AES-256-GCM) before storage, so a raw database dump or
  a misconfigured replica read permission does not expose them in plaintext — encryption at rest
  covers the disk; this covers the row.
- **Row-Level Security** as defense-in-depth tenant isolation, detailed in `# Multi-Tenant
  Foundation`, enabled on every business table via a migration-generated policy, verified by a CI
  check that fails the build if a new business table is added without RLS enabled.
- **Least-privilege database roles:** the Laravel application connects as `qayd_app` (DML only, no
  `DROP`/`TRUNCATE`/`ALTER` grants); migrations run as `qayd_migrator` (DDL, used only from CI/CD, not
  reachable from application servers); the AI layer connects as `qayd_ai_readonly` (SELECT-only,
  granted on the read-replica connection exclusively — the AI layer has no credential capable of
  writing to PostgreSQL at all, structurally enforcing the "AI never writes directly" platform rule).

```sql
CREATE ROLE qayd_app        LOGIN PASSWORD '***' NOSUPERUSER;
CREATE ROLE qayd_migrator   LOGIN PASSWORD '***' NOSUPERUSER;
CREATE ROLE qayd_ai_readonly LOGIN PASSWORD '***' NOSUPERUSER;

GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA public TO qayd_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO qayd_app;
REVOKE DELETE ON audit_logs, journal_lines, ledger_entries FROM qayd_app; -- immutability at the grant level

GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO qayd_migrator;

GRANT SELECT ON ALL TABLES IN SCHEMA public TO qayd_ai_readonly;
```

- **Secrets management:** database credentials are never in `.env` files committed to a repository;
  they are pulled at boot from AWS Secrets Manager, rotated quarterly, with old/new credential
  overlap during rotation handled by PgBouncer's `userlist.txt` reload without a restart.
- **SQL injection surface:** eliminated by construction — Eloquent/Query Builder parameter binding
  everywhere; any raw SQL (`DB::statement`, partition maintenance scripts) is reviewed in PR with a
  standing checklist item "no string-concatenated user input in raw SQL."

# Edge Cases

- **Clock skew across the fleet:** all `TIMESTAMPTZ` columns default to `now()` evaluated inside
  PostgreSQL, never inside PHP/Python application code, so a misconfigured application-server clock
  can never produce an inconsistent `created_at` relative to the database's own ordering.
  Auto-increment `id` order remains the authoritative causal ordering even if two rows share a
  millisecond-identical timestamp.
- **PgBouncer transaction pooling + advisory locks:** any code path that needs a session-scoped
  Postgres advisory lock (e.g. serializing per-company sequence-number generation for
  human-readable invoice numbers) must use `pg_try_advisory_xact_lock()` (transaction-scoped, safe
  under `transaction` pooling), never `pg_advisory_lock()` (session-scoped, which would silently
  leak the lock to the next unrelated pooled client).
- **Exchange-rate change after a transaction but before posting:** `base_amount` is frozen at the
  rate captured when the source document was created, not recalculated at posting time; the
  `journal_lines` row for a foreign-currency transaction inherits that frozen `base_amount` even if
  `exchange_rate` tables have since been updated for future transactions.
- **Partition boundary transactions:** a transaction started just before midnight on a partitioned
  table (`audit_logs`) and committed just after midnight is placed in the partition matching its
  actual `created_at` (evaluated at `now()` at row-write time), which may differ from the partition
  the application "expected" when the request began — reporting queries must always filter by range,
  never assume a request maps to exactly one partition.
- **Soft-deleted rows and unique constraints:** unique constraints that must allow re-use of a code
  after deletion (e.g. a customer code) are declared as **partial unique indexes** scoped to
  non-deleted rows, not a plain `UNIQUE` constraint, so a soft-deleted customer's code can be reissued:

```sql
CREATE UNIQUE INDEX uq_customers_company_code_active
    ON customers (company_id, customer_code)
    WHERE deleted_at IS NULL;
```

- **Restoring a soft-deleted row whose unique code was reissued:** blocked at the application layer
  with a explicit 409 conflict — restoring must first re-validate uniqueness, since the database
  partial index alone will correctly reject the restore but the error must be surfaced as a clear,
  actionable API error rather than a raw constraint-violation stack trace.
- **Long-running analytical query on a replica during failover:** if a Multi-AZ failover promotes a
  standby while a read replica's own upstream (the old primary) disappears momentarily, the replica
  pauses replication rather than corrupting state; QAYD's read-replica health check
  (`pg_is_in_recovery()` + lag check) removes an unhealthy replica from PgBouncer's replica pool
  automatically via a sidecar that rewrites `pgbouncer.ini` and issues `RELOAD`.
- **Numeric overflow at extreme scale:** `NUMERIC(19,4)` bounds the largest representable amount at
  999,999,999,999,999.9999; any input exceeding that (essentially unreachable for a single business
  transaction) is rejected by the column's own precision limit at the driver level before it reaches
  a `CHECK` constraint, surfaced as a 422 validation error, never a silent truncation.

# Future Improvements

- Introduce **logical replication** of the `accounting` and `tax` domains into a dedicated
  compliance-reporting Postgres instance, isolating heavy regulatory export jobs from the OLTP
  primary entirely (beyond what read replicas already provide) once tax-authority integrations
  require sustained large exports on a fixed schedule.
- Evaluate **`pg_partman`** to automate partition creation/retention for `audit_logs`,
  `stock_movements`, and `bank_transactions` instead of the current scheduled-command approach, once
  the number of partitioned tables grows past what a single cron command comfortably manages.
- Define and monitor the concrete **partitioning trigger threshold** for `journal_lines` /
  `ledger_entries` (proposed: partition by `fiscal_year_id` once any single company's `journal_lines`
  exceeds ~50M rows or its Trial Balance query p95 exceeds 500ms on the primary index path) so the
  decision is made from measured data, not guessed in advance.
- Build the **company-shard router** described in `# Scaling Strategy` as a thin, testable service
  ahead of actually needing it, so the first enterprise tenant that requires it is a configuration
  change (add a shard, backfill, cut over) rather than a from-scratch build under time pressure.
- Add **automated schema-drift detection** that diffs the live `information_schema` against the
  Laravel migration history in CI, catching any manual/emergency `ALTER TABLE` run directly against
  production that was not captured as a versioned migration.
- Extend **row-level security policies** from tenant isolation alone to branch-level isolation for
  companies that need to restrict a branch manager's database-layer visibility to their own branch,
  once a module's permission model requires it (most modules currently enforce branch scoping at the
  application layer only).
- Pilot **columnar/analytical acceleration** (e.g. a read-replica-side extension or a periodic export
  to a columnar store) for `report_runs` once cross-fiscal-year, cross-branch consolidated reporting
  queries on the row-store replica stop meeting target latency for the largest tenants.

# End of Document
