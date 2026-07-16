# Database Architecture — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_ARCHITECTURE

---

# Purpose

This document is the canonical engineering specification for the database layer that underlies every module of QAYD, the AI Financial Operating System. It defines the physical and logical architecture of the PostgreSQL cluster that stores all tenant data — chart of accounts, journal entries, sales and purchase documents, inventory, payroll, tax, and the audit trail that binds them together — and it defines the operational disciplines (replication, backup, monitoring, encryption, partitioning) that keep that data correct, available, and recoverable under production load.

QAYD is not a reporting tool bolted onto a spreadsheet. It is a system of record for money movement inside a company, consumed simultaneously by human accountants, autonomous AI agents, and downstream integrations (banks, tax authorities, payroll processors). A system of record for money has a different bar than a typical SaaS CRUD application: every write must be traceable to a person or a process, every balance must reconcile to the cent, every deletion must be reversible in substance even when it is irreversible in form, and every failure mode — a crashed pod, a bad migration, a compromised credential, a full disk, a split-brain replica — must have a pre-written, tested response.

This document exists so that any backend engineer, SRE, or AI agent operating on QAYD's infrastructure can implement, extend, or operate the database layer without having to reverse-engineer intent from code. It is intentionally prescriptive. Where a decision has trade-offs (for example, BIGINT identity keys versus UUID primary keys, or shared-schema multi-tenancy versus schema-per-tenant), the trade-off is stated explicitly and a default is chosen, because an AI financial operating system cannot afford architectural ambiguity in the layer that holds the ledger.

The scope of this document is the **database layer** specifically: schema design conventions that apply across all modules, tenant isolation mechanics, transactional and consistency guarantees, the audit/history/versioning stack, indexing and partitioning strategy, and the full operational lifecycle — replication topology, backup and disaster recovery, read-replica routing, high availability, encryption, performance tuning, scalability roadmap, and monitoring. Module-specific schemas (accounting, sales, inventory, payroll, tax) are specified in their own documents and must conform to every convention defined here.

# Vision

QAYD's long-term vision is to be the system of record that a company's finance department, its auditors, its bank, its tax authority, and its AI copilot can all trust simultaneously — without any of them needing to trust each other's tooling. The database is where that trust is manufactured. Every other layer (Laravel services, the FastAPI AI engine, the Next.js frontend) is replaceable; the database is not. A company's five years of posted journal entries, reconciled bank statements, and payroll runs must still be queryable, auditable, and restorable a decade from now, even if QAYD rewrites its frontend three times in that period.

Three multi-year commitments follow from that vision:

1. **The database is the single source of financial truth.** No cache, no read model, no AI-generated summary is ever authoritative. Ledger balances, tax liabilities, and payroll totals are always derived, on demand or via materialized projection, from the posted rows in PostgreSQL. If a number cannot be traced back to a row (or a set of rows) in the database, QAYD does not display it as a fact — it displays it as an AI estimate, clearly labeled.

2. **Nothing that represents money is ever silently lost.** Hard deletes on financial tables are architecturally impossible (there is no `DELETE` grant on posted-record tables for the application role). Corrections happen through reversing entries, adjusting entries, and versioned history rows. A CFO auditing what happened to an invoice in March must be able to answer "who changed what, when, and why" without needing an application-level audit feature — the guarantee lives in the schema itself.

3. **Scale is planned for before it is needed.** QAYD's roadmap runs from a single Kuwait-based SME to multi-country enterprise groups with tens of millions of ledger lines per company per year. The schema decisions made on day one — BIGINT identity keys, UUID-based external identifiers, RANGE partitioning on high-volume tables, a multi-tenant model that can be split into schema-per-tenant or Citus-sharded without an application rewrite — exist so that the tenth-largest customer does not force a rearchitecture that risks the first thousand.

The database layer's success is measured by four properties, in priority order: **correctness** (the numbers are always right), **durability** (the data cannot be lost), **availability** (the system is reachable when a company needs to close its books), and **speed** (reports and AI queries return fast enough to feel real-time). Every design decision in this document is justified against that ordering — a decision that trades a small amount of speed for a large amount of correctness is always taken.

# Database Philosophy

QAYD's database philosophy can be summarized in nine working principles that every migration, every query, and every AI-generated write must satisfy.

**1. The schema is a contract, not an implementation detail.** Column names, types, and constraints are part of the platform's public API surface in spirit, even though the database itself is never exposed directly to clients. Renaming a column is a breaking change that requires the same discipline as renaming a REST field: a deprecation window, a compatibility view if needed, and a migration plan communicated across the Laravel, FastAPI, and Next.js teams.

**2. Business logic that protects money lives in the database, not just in the application.** Laravel's Service layer is the primary enforcement point for business rules, but a determined bug, a raw SQL migration, or a future service written in a different language must not be able to corrupt the ledger. `CHECK` constraints, triggers, and exclusion constraints are the last line of defense: a journal entry that does not balance, a fiscal period that overlaps another, or a negative on-hand quantity where negatives are disallowed must be rejected by PostgreSQL itself, independent of which application wrote it.

**3. Immutability is the default for anything that represents a completed financial event.** A posted journal line, a sent invoice, a processed payslip — once posted, these rows do not receive `UPDATE` in the ordinary sense. Corrections are new rows (reversing entries, credit notes, adjustment runs) that reference what they correct. This is not bureaucratic caution; it is what makes an audit possible at all. An auditor who sees a row that changed silently cannot certify anything.

**4. Every table answers "who, when, and from where."** The standard columns (`created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) are non-negotiable on every business table, and every mutation additionally writes a row to `audit_logs` capturing the actor, the IP address, the device fingerprint, and a diff of old versus new values. This is enforced at the ORM layer (a Laravel trait, `HasAuditLog`) and backstopped by database triggers on the tables that matter most (accounting, payroll, tax).

**5. Tenant isolation is a database-enforced property, not an application-trusted one.** `company_id` scoping in a Laravel global scope is necessary but not sufficient — a forgotten `->where('company_id', ...)` in one query must not leak Company B's payroll into Company A's report. PostgreSQL Row Level Security is the enforcement backstop that makes cross-tenant leakage a database-level impossibility rather than an application-level discipline.

**6. Reads and writes have different consistency needs, and the schema should not force them to share one.** Journal posting is a strict, serializable, single-writer-per-account-period operation. A trial balance report, an AI-generated cash-flow forecast, or a dashboard tile can tolerate a few seconds of staleness in exchange for not competing with OLTP writes for I/O. `ledger_entries` as a materialized projection, and read replicas for reporting traffic, exist because of this principle — never because "it's the pattern".

**7. JSONB is for genuinely schemaless data, not for avoiding a migration.** `custom_fields`, `tags`, AI reasoning payloads, and third-party webhook bodies are legitimately shaped differently per tenant or per event and belong in JSONB. A field that every tenant has, that has a fixed type, and that participates in a `WHERE`, `JOIN`, or aggregate belongs in a real column with a real index. Using JSONB to skip a migration is treated as technical debt from day one and is flagged in code review.

**8. Every table that will ever be large is partitioned before it is large.** Waiting until `journal_lines` has 200 million rows to add partitioning means performing that migration under production load, against a table that cannot be locked. High-volume, time-ordered tables are partitioned by `RANGE` on a timestamp from the migration that creates them, even on day one when the table has ten rows.

**9. Operational recoverability is designed, drilled, and dated — not assumed.** A backup that has never been restored is a hypothesis, not a backup. Disaster recovery runbooks are tested on a schedule, RPO/RTO numbers are re-validated after every infrastructure change, and the date of the last successful restore drill is tracked as an operational metric, not folklore.

# Why PostgreSQL

QAYD standardizes on **PostgreSQL 15+** as the single relational database for all tenant and platform data. This section states the justification precisely, including the alternatives considered and rejected, because "why Postgres" is a question every new engineer and every audit will ask.

## Requirements that shaped the decision

- **Strict ACID transactions across multi-table writes.** Posting a single sales invoice touches `invoices`, `invoice_items`, `journal_entries`, `journal_lines`, `inventory_items`, `stock_movements`, and `audit_logs` in one atomic unit. A database that offers only eventual consistency, or that only guarantees atomicity within a single document, is disqualified outright for a ledger.
- **Rich constraint and trigger support.** `CHECK`, `EXCLUDE`, deferred `FOREIGN KEY`, and procedural triggers in `PLPGSQL` let QAYD enforce accounting invariants (balanced entries, non-overlapping fiscal periods, non-negative stock where configured) inside the database.
- **Native Row Level Security.** Multi-tenant isolation needs a mechanism that cannot be bypassed by a missing `WHERE` clause. PostgreSQL RLS, tied to a session variable set per request, gives QAYD that guarantee natively.
- **JSONB with indexing.** AI reasoning payloads, webhook bodies, and per-tenant custom fields need a first-class semi-structured type that is still indexable and queryable with real operators (`@>`, `?`, `jsonb_path_query`) — not a bolt-on text blob.
- **Mature logical and physical replication.** Read replicas for reporting, and change-data-capture for the event-driven architecture (`pgoutput`, Debezium-compatible logical replication slots), must be first-party features, not third-party add-ons bolted onto the wire protocol.
- **Extensibility for the AI workload.** QAYD's AI layer needs vector similarity search over embeddings (document matching, fraud-pattern similarity, natural-language-to-SQL grounding). `pgvector` running inside the same transactional database as the ledger means AI retrieval never needs a second data store to keep in sync.
- **Operational maturity and talent availability.** PostgreSQL has 25+ years of production hardening, a large Gulf-region and global talent pool familiar with it, and first-class support from every cloud QAYD might run on (AWS RDS/Aurora, GCP Cloud SQL/AlloyDB, self-managed EC2 with Patroni).

## Alternatives considered and rejected

| Alternative | Why it was rejected for QAYD |
|---|---|
| **MySQL / MariaDB** | Weaker `CHECK` constraint enforcement historically (MySQL only fully enforces `CHECK` from 8.0.16+), no native Row Level Security, `JSON` type without the indexing depth of JSONB's GIN operator classes, weaker native support for exclusion constraints needed for non-overlapping fiscal periods and reservation windows. |
| **MongoDB / document stores** | No multi-document ACID transactions across the breadth QAYD needs (cross-collection, cross-shard), no native double-entry balance enforcement, denormalization pressure conflicts directly with "single source of truth" for the ledger. Acceptable for logs or analytics, not for money. |
| **DynamoDB / other key-value stores** | No ad-hoc relational queries (a CFO's month-end report is inherently relational — joins across accounts, periods, cost centers, projects), no transactions beyond a small item-count limit, forces application-side joins that reintroduce the consistency problems a database is supposed to solve. |
| **Oracle Database** | Technically capable and the traditional ERP choice (SAP, Oracle Financials run on it), but licensing cost is prohibitive for QAYD's SME-to-enterprise pricing model, and it offers no meaningful capability advantage over PostgreSQL 15+ for QAYD's workload. |
| **CockroachDB / distributed SQL** | Attractive for the eventual multi-region, horizontally-sharded future, but its consistency model (Serializable via distributed consensus) adds write latency that is unjustified at QAYD's current and near-term scale. Revisited in **Future Architecture** below as a possible target if/when Citus-based sharding is insufficient. |

## Required extensions

Every QAYD database instance ships with the following extensions enabled by default, installed via a Laravel migration run once per database (not per tenant, since QAYD uses shared-schema multi-tenancy — see next section):

```sql
CREATE EXTENSION IF NOT EXISTS pgcrypto;      -- gen_random_uuid(), pgp_sym_encrypt/decrypt
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;  -- query performance monitoring
CREATE EXTENSION IF NOT EXISTS pg_trgm;       -- trigram indexes for fuzzy/ILIKE search
CREATE EXTENSION IF NOT EXISTS btree_gin;     -- composite GIN indexes mixing scalar + jsonb
CREATE EXTENSION IF NOT EXISTS pg_partman;    -- automated partition management
CREATE EXTENSION IF NOT EXISTS vector;        -- pgvector, AI embedding similarity search
CREATE EXTENSION IF NOT EXISTS postgres_fdw;  -- foreign data wrapper, cross-database reporting
```

Each extension is justified by a concrete, named QAYD use case: `pgcrypto` for external UUID generation and column-level encryption of bank account numbers (see **Encryption**); `pg_stat_statements` for the query-level metrics that drive the **Monitoring** and **Performance** sections; `pg_trgm` for the AI Document/OCR agent's fuzzy vendor-name matching against `vendors.name_en`/`name_ar`; `btree_gin` for composite indexes over `(company_id, custom_fields)`; `pg_partman` for the partition maintenance described in **Partitioning**; `vector` for embedding storage on `ai_messages` and future semantic search over `attachments`; `postgres_fdw` for the reporting warehouse cross-database queries described in **Future Architecture**.

# Multi Tenant Architecture

QAYD uses a **shared database, shared schema, row-discriminated** multi-tenancy model: all companies live in the same PostgreSQL database and the same set of tables, isolated by a mandatory `company_id` column present on every business table, enforced by both the application layer and PostgreSQL Row Level Security.

## Why shared-schema over schema-per-tenant or database-per-tenant

| Model | Isolation strength | Operational cost | Cross-tenant reporting | Migration cost | Chosen for QAYD? |
|---|---|---|---|---|---|
| Shared schema, row-discriminated | Strong (with RLS) | One schema to migrate, one connection pool | Trivial (platform-level aggregate queries) | One migration run, applies to all tenants atomically | **Yes — default for all tenants** |
| Schema-per-tenant | Very strong (native Postgres namespace isolation) | N schemas to migrate on every deploy; connection pool exhaustion risk past a few hundred tenants | Requires fan-out queries across schemas or an FDW | N migration runs per deploy, harder to guarantee atomicity | No — reserved as an **opt-in isolation tier** for regulated enterprise tenants (see below) |
| Database-per-tenant | Strongest | Highest — N databases to back up, replicate, monitor, patch | Requires cross-database FDW or ETL | N times the migration and connection overhead | No — cost does not match QAYD's SME-heavy customer base |

Shared-schema is the correct default because QAYD's customer base is overwhelmingly SMEs and mid-market companies where the operational simplicity of one schema — one migration path, one connection pool, one set of indexes to tune — outweighs the marginal isolation benefit of per-tenant schemas, provided isolation is enforced at the row level with a mechanism (RLS) that does not depend on every query author remembering a `WHERE` clause.

QAYD reserves the right to offer **schema-per-tenant as a paid isolation tier** for large enterprise or regulated customers (banks, government-adjacent entities) who require physical namespace separation for compliance reasons. That tier is described in **Future Architecture**; it does not change the default architecture documented here, and no code should assume it exists until a company record has `isolation_tier = 'dedicated_schema'`.

## Standard tenant columns

Every business table — every table listed in the canonical table list of the platform's shared design context — carries these columns, in this order, immediately after any table-specific columns:

```sql
id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
uuid          UUID NOT NULL DEFAULT gen_random_uuid(),
company_id    BIGINT NOT NULL REFERENCES companies(id),
branch_id     BIGINT NULL REFERENCES branches(id),
created_by    BIGINT NULL REFERENCES users(id),
updated_by    BIGINT NULL REFERENCES users(id),
created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
deleted_at    TIMESTAMPTZ NULL
```

`company_id` is `NOT NULL` on every business table without exception; there is no "global" business record. Platform-level tables that genuinely have no tenant owner (`companies` itself, `roles` where system-defined, `account_types`) are the only tables without `company_id`, and they are explicitly enumerated in the schema review checklist so a reviewer never has to guess whether an omission was intentional.

## Tenant context propagation

The active tenant is established once per request and propagated through three layers:

**1. HTTP layer.** Every authenticated request carries `X-Company-Id` (validated against the user's `company_users` membership) or derives the active company from the user's default company if the header is absent. A `SetTenantContext` middleware resolves this before any controller runs.

```php
// app/Http/Middleware/SetTenantContext.php
class SetTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = $request->header('X-Company-Id')
            ?? $request->user()->default_company_id;

        abort_unless(
            $request->user()->companies()->where('companies.id', $companyId)->exists(),
            403,
            'User is not a member of the requested company.'
        );

        app()->instance('tenant.company_id', $companyId);

        // Propagate to Postgres session for Row Level Security.
        DB::statement('SELECT set_config(?, ?, false)', ['app.current_company_id', (string) $companyId]);
        DB::statement('SELECT set_config(?, ?, false)', ['app.current_user_id', (string) $request->user()->id]);

        return $next($request);
    }
}
```

**2. Eloquent layer.** A `BelongsToCompany` global scope adds `WHERE company_id = ?` to every query on every tenant-scoped model, as defense-in-depth alongside RLS — never as a substitute for it.

```php
// app/Models/Concerns/BelongsToCompany.php
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = app('tenant.company_id');
            }
        });
    }
}
```

**3. Database layer.** `set_config('app.current_company_id', ...)` sets a session-local (non-transaction-local, `is_local = false` so it survives across statements in the same connection lease, but is reset per PgBouncer session — see **High Availability**) GUC that every RLS policy reads. This is the enforcement layer that cannot be bypassed by an application bug.

## Connection pooling under multi-tenancy

Because tenant context is carried in a session-level GUC rather than in the connection string, QAYD uses **PgBouncer in `transaction` pooling mode** with an explicit reset of the tenant GUCs at the start of every transaction rather than relying on session-level state surviving a pooled connection handoff:

```ini
; pgbouncer.ini (relevant excerpt)
[databases]
qayd_production = host=pg-primary.internal port=5432 dbname=qayd

[pgbouncer]
pool_mode = transaction
max_client_conn = 4000
default_pool_size = 50
reserve_pool_size = 10
server_reset_query = DISCARD ALL
```

`server_reset_query = DISCARD ALL` guarantees that a connection handed back to the pool never leaks one tenant's session GUCs to the next transaction that happens to reuse the same physical connection — the middleware's `set_config` call re-establishes tenant context at the start of every request's first query, every time, without exception.

# Row Level Security

Row Level Security (RLS) is the database-enforced backstop for tenant isolation described above. Every tenant-scoped table has RLS **enabled and forced**, meaning even the table owner role is subject to the policies unless explicitly using a `BYPASSRLS` role (reserved for the migration runner and background queue workers that operate across tenants for platform-level jobs).

## Enabling RLS on a tenant table

```sql
ALTER TABLE journal_entries ENABLE ROW LEVEL SECURITY;
ALTER TABLE journal_entries FORCE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation_select ON journal_entries
    FOR SELECT
    USING (company_id = current_setting('app.current_company_id', true)::bigint);

CREATE POLICY tenant_isolation_insert ON journal_entries
    FOR INSERT
    WITH CHECK (company_id = current_setting('app.current_company_id', true)::bigint);

CREATE POLICY tenant_isolation_update ON journal_entries
    FOR UPDATE
    USING (company_id = current_setting('app.current_company_id', true)::bigint)
    WITH CHECK (company_id = current_setting('app.current_company_id', true)::bigint);

CREATE POLICY tenant_isolation_delete ON journal_entries
    FOR DELETE
    USING (company_id = current_setting('app.current_company_id', true)::bigint);
```

`current_setting(..., true)` uses the `missing_ok` flag so that a connection with no tenant context set returns `NULL` rather than raising an error — and `NULL = company_id` evaluates to `NULL` (not true), so the policy correctly denies access by default rather than throwing an unhandled exception mid-request. This "fail closed" behavior is deliberate and tested.

## Generating RLS policies for every tenant table in one migration

Rather than hand-writing four `CREATE POLICY` statements per table across dozens of tables, QAYD ships a Laravel Artisan command that applies the standard policy set to any table listed in a config array, guaranteeing consistency and making it impossible to add a new tenant table and forget RLS:

```php
// app/Console/Commands/ApplyTenantRls.php
class ApplyTenantRls extends Command
{
    protected $signature = 'db:apply-tenant-rls';

    public function handle(): void
    {
        foreach (config('tenancy.rls_tables') as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            foreach (['select', 'insert', 'update', 'delete'] as $op) {
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$op} ON {$table}");
            }

            DB::statement("CREATE POLICY tenant_isolation_select ON {$table}
                FOR SELECT USING (company_id = current_setting('app.current_company_id', true)::bigint)");
            DB::statement("CREATE POLICY tenant_isolation_insert ON {$table}
                FOR INSERT WITH CHECK (company_id = current_setting('app.current_company_id', true)::bigint)");
            DB::statement("CREATE POLICY tenant_isolation_update ON {$table}
                FOR UPDATE USING (company_id = current_setting('app.current_company_id', true)::bigint)
                WITH CHECK (company_id = current_setting('app.current_company_id', true)::bigint)");
            DB::statement("CREATE POLICY tenant_isolation_delete ON {$table}
                FOR DELETE USING (company_id = current_setting('app.current_company_id', true)::bigint)");

            $this->info("RLS applied to {$table}");
        }
    }
}
```

Every migration that creates a new tenant table must add that table's name to `config/tenancy.php`'s `rls_tables` array in the same pull request; CI runs `php artisan db:apply-tenant-rls --dry-run` (a `--dry-run` flag that diffs policies without applying) against the migrated schema and fails the build if a table with `company_id` is missing from the list.

## Branch-level RLS for branch-scoped roles

Some roles (a Warehouse Employee, for instance) are scoped to a single branch even within a company. Branch-level policies compose with tenant-level policies using a second session GUC:

```sql
CREATE POLICY branch_scoping_select ON stock_movements
    FOR SELECT
    USING (
        company_id = current_setting('app.current_company_id', true)::bigint
        AND (
            current_setting('app.current_branch_id', true) IS NULL
            OR branch_id = current_setting('app.current_branch_id', true)::bigint
        )
    );
```

`app.current_branch_id` is only set by the middleware when the authenticated user's role carries a `branch_restricted` flag; for company-wide roles it is left unset, and the `IS NULL` clause on the left of the `OR` grants unrestricted branch visibility within the tenant.

## Bypassing RLS safely for platform jobs

Background jobs that legitimately operate across tenants — nightly report aggregation, platform billing reconciliation, the scheduled task that expires unconfirmed invitations — run under a dedicated database role, `qayd_platform_worker`, granted `BYPASSRLS`, which is **never** the role the Laravel application uses for request-scoped queries:

```sql
CREATE ROLE qayd_platform_worker WITH LOGIN PASSWORD :'worker_password' BYPASSRLS;
GRANT qayd_app_role TO qayd_platform_worker; -- inherits table grants, not RLS exemption for app role
```

Any query run as `qayd_platform_worker` must set `company_id` explicitly in its `WHERE` clause by hand, and every such job is code-reviewed with the assumption that a missing filter here is a full cross-tenant data breach — `BYPASSRLS` removes the database's safety net entirely for that connection.

## Testing RLS

RLS policies are tested with a dedicated Pest test suite that asserts cross-tenant leakage is impossible even when a query intentionally omits a `company_id` filter:

```php
// tests/Feature/Security/RowLevelSecurityTest.php
it('never returns another companys journal entries even without an explicit filter', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    JournalEntry::factory()->for($companyB)->create(['reference' => 'B-SECRET']);

    setTenantContext($companyA->id);

    // Deliberately bypass Eloquent's global scope to prove RLS alone holds the line.
    $rows = DB::table('journal_entries')->withoutGlobalScopes()->get();

    expect($rows->pluck('reference'))->not->toContain('B-SECRET');
});
```

This test class runs against a real PostgreSQL test database (never SQLite, which has no RLS) as a required CI gate on every pull request that touches a migration.

# UUID Strategy

QAYD uses a **dual-key strategy** on every business table: an internal `BIGINT` identity column as the primary key used for all joins, foreign keys, and index maintenance, plus an external `UUID` column exposed as the record's public identifier through the API, in webhooks, and in any URL. Neither key alone satisfies every requirement QAYD has; together they do.

## The dual-key schema

```sql
CREATE TABLE invoices (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid          UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    -- ... table-specific columns ...
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX invoices_uuid_unique ON invoices (uuid);
```

`id` is the row's identity for every internal purpose: it is what every foreign key column (`invoice_id BIGINT REFERENCES invoices(id)`) references, what every join uses, what every index is built on, and what Laravel's Eloquent `$model->id` returns for internal application logic. `uuid` is what leaves the system: it is the value serialized in every API response's `"id"` field (the API never emits the internal BIGINT — see below), the value embedded in a `GET /api/v1/accounting/invoices/{uuid}` route, the value referenced in a webhook payload, and the value a customer's integration stores.

## Why not a UUID primary key

A natural simplification would be to make `uuid` itself the primary key and drop the BIGINT entirely. QAYD rejects this for concrete, measurable reasons:

- **B-tree insert locality.** A `BIGINT IDENTITY` primary key is monotonically increasing, so every insert appends to the right edge of the primary key's B-tree index — the hot page is almost always already in the buffer cache. A random `UUIDv4` primary key inserts at a effectively random location in the index on every write, causing constant page splits, larger index bloat, and worse cache-hit ratios under high insert volume (`journal_lines` and `stock_movements` are exactly this kind of high-insert-volume table).
- **Index and row size.** A `UUID` is 16 bytes versus 8 bytes for `BIGINT`. Every foreign key column, and every index that includes it, pays that cost multiplied across every referencing table. On a table like `journal_lines` with tens of millions of rows and five to eight indexes, the aggregate size difference between BIGINT and UUID foreign keys is measured in tens of gigabytes at scale — a direct cost in storage, backup size, replication bandwidth, and cache pressure.
- **Join performance.** Integer equality comparisons are marginally but consistently faster than UUID (128-bit) comparisons at the volume of joins QAYD's reporting queries perform (a trial balance report joins `journal_lines` to `accounts`, `cost_centers`, `projects`, and `fiscal_periods` for potentially millions of rows).
- **Human-referenceable internal debugging.** An engineer or support agent reading a Laravel log, a `psql` session, or a Horizon queue dashboard benefits from small sequential integers when correlating rows during an incident; UUIDs are illegible for this purpose.

## Why not a BIGINT-only strategy (no UUID at all)

The opposite simplification — exposing the internal BIGINT `id` directly in the API — is rejected for equally concrete reasons:

- **Enumeration and information leakage.** A sequential `id` in a public-facing URL (`/invoices/48213`) leaks business intelligence: a competitor or a curious customer can infer approximately how many invoices, customers, or companies QAYD has created in total and how fast that number is growing, simply by watching the ID sequence.
- **Cross-environment portability.** UUIDs generated client-side or by an integration before the record is persisted (idempotency keys, offline-first mobile capture) can be assigned deterministically; sequential integers cannot be known before an `INSERT` completes, which complicates idempotent retry logic for the AI layer and third-party integrations.
- **Merge and migration safety.** When QAYD migrates a company between environments (staging to production, or a future schema-per-tenant migration for an enterprise customer), UUIDs guarantee no collision between two databases' primary keys; BIGINT sequences from two different databases collide constantly and require re-keying every foreign key on merge.
- **External identifier stability across internal refactors.** If QAYD ever needs to renumber or resequence internal IDs (extremely rare, but not impossible during a partitioning migration that recreates a table), the UUID a customer has stored in their own systems never changes, because it was never derived from the internal key's physical storage.

## UUID generation: UUIDv4 today, UUIDv7 evaluation for the future

QAYD generates UUIDs with PostgreSQL's native `gen_random_uuid()` (from `pgcrypto`), which produces **UUIDv4** (fully random). This is the default in every `CREATE TABLE` and every Laravel migration's `uuid()->default(DB::raw('gen_random_uuid()'))` column definition.

QAYD is evaluating a move to **UUIDv7** (time-ordered UUIDs, draft-standardized in RFC 9562) for new tables as PostgreSQL-native generation support matures beyond extension packages, because UUIDv7's leading timestamp bits restore much of the B-tree insert locality that a full BIGINT/UUID dual-key exists to work around — at which point the dual-key pattern could, for greenfield tables only, collapse into "UUIDv7 as the sole primary key." This is **not** the current standard: every existing and new table today uses the BIGINT + UUIDv4 dual-key pattern described above, and any change to UUIDv7 will be a deliberate, documented decision applied to new tables only, never retrofitted onto existing primary keys.

## API-layer enforcement: the BIGINT never leaves the backend

Laravel API Resources are the single enforcement point that guarantees the internal `id` is never serialized to a client:

```php
// app/Http/Resources/InvoiceResource.php
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->uuid,          // external identifier
            'number'      => $this->invoice_number,
            'customer_id' => $this->customer->uuid, // relations also resolve to UUID
            'total'       => $this->total_amount,
            'status'      => $this->status,
            'created_at'  => $this->created_at->toIso8601String(),
        ];
        // Note: $this->id (BIGINT) is intentionally absent from every resource.
    }
}
```

Route model binding resolves the reverse direction — an incoming `{invoice}` route parameter is a UUID string, and Laravel's `Route::bind('invoice', ...)` (or a resolveRouteBinding override on the model) looks it up by `uuid` and yields the full model, so controllers work with the Eloquent model and its internal `id` for every subsequent query, while only ever having accepted a UUID from the outside world.

```php
// app/Models/Invoice.php
public function resolveRouteBinding($value, $field = null)
{
    return $this->where('uuid', $value)->firstOrFail();
}
```

## Indexing the UUID column

Because every inbound API request resolves a UUID to a row before any further processing, `uuid` carries its own unique B-tree index on every table (shown in the DDL above), separate from the primary key index on `id`. This index is smaller in row count impact than it would be if `uuid` were the primary key (it is a secondary index, not the clustering key each foreign key must reference), but it is still sized for the full row count and is monitored under the same **Index Strategy** discipline as every other index — including `pg_stat_user_indexes` checks that it is actually being used and not a redundant duplicate of another constraint.

# Primary Keys

Every table's primary key is a `BIGINT` populated by PostgreSQL's native identity column mechanism, `GENERATED ALWAYS AS IDENTITY`, which QAYD standardizes on over the legacy `serial`/`bigserial` pseudo-type for three concrete reasons: identity columns are SQL-standard syntax (portable in spirit even though QAYD is Postgres-only by design), they cannot be accidentally overwritten by an explicit `INSERT ... (id) VALUES (...)` without an explicit `OVERRIDING SYSTEM VALUE` clause (whereas a `serial` column's underlying sequence can be silently bypassed), and their ownership and sequence lifecycle is managed automatically by the table's own DDL rather than a separately-named sequence object that migrations must track.

```sql
CREATE TABLE accounts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ...
);
```

## Laravel migration convention

```php
Schema::create('accounts', function (Blueprint $table) {
    $table->id();                 // BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY
    $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'));
    $table->unique('uuid');
    $table->foreignId('company_id')->constrained('companies');
    $table->foreignId('branch_id')->nullable()->constrained('branches');
    // table-specific columns...
    $table->foreignId('created_by')->nullable()->constrained('users');
    $table->foreignId('updated_by')->nullable()->constrained('users');
    $table->timestampsTz();
    $table->softDeletesTz();
});
```

## Composite and natural keys

QAYD does not use natural keys (tax IDs, invoice numbers, IBANs) as primary keys anywhere, because natural keys can change (a tax authority reissues an ID, a customer corrects a typo) and a primary key must never need to change once referenced by a foreign key. Natural keys are instead enforced as **unique constraints** scoped to the tenant:

```sql
ALTER TABLE customers
    ADD CONSTRAINT customers_tax_id_unique_per_company
    UNIQUE (company_id, tax_registration_number);
```

Pure join/association tables (many-to-many, such as `company_users` linking `companies` and `users`) still receive their own surrogate `BIGINT IDENTITY` primary key rather than a composite `(company_id, user_id)` primary key, because QAYD's audit and soft-delete conventions (`deleted_at`, `updated_by`) require a single-column identity that other tables (e.g., `role_assignments` referencing a specific membership row) can cleanly reference. A composite natural key is enforced instead as a unique constraint:

```sql
CREATE TABLE company_users (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid         UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id   BIGINT NOT NULL REFERENCES companies(id),
    user_id      BIGINT NOT NULL REFERENCES users(id),
    role_id      BIGINT NOT NULL REFERENCES roles(id),
    status       VARCHAR(20) NOT NULL DEFAULT 'active'
                 CHECK (status IN ('active', 'invited', 'suspended', 'removed')),
    invited_by   BIGINT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at   TIMESTAMPTZ NULL,
    CONSTRAINT company_users_membership_unique UNIQUE (company_id, user_id)
);
```

# Foreign Keys

Every relationship between tables is enforced by a real `FOREIGN KEY` constraint — QAYD does not rely on application-level referential integrity anywhere, because an orphaned `journal_lines.account_id` pointing at a deleted account is a corrupted ledger, not a cosmetic bug.

## Standard foreign key pattern

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_journal_entry_id_fkey
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE;

ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_account_id_fkey
    FOREIGN KEY (account_id) REFERENCES accounts(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE;
```

## ON DELETE policy by relationship class

QAYD does not use a single default `ON DELETE` action across the schema; the action is chosen per relationship based on what deleting the parent should mean for the child, and is documented per foreign key in the migration:

| Relationship class | `ON DELETE` action | Rationale | Example |
|---|---|---|---|
| Financial parent-to-line (posted records) | `RESTRICT` | A posted journal entry can never be deleted while its lines exist; deletion must go through the reversing-entry workflow instead, never through a cascading `DELETE`. | `journal_entries` → `journal_lines` |
| Master data referenced by transactions | `RESTRICT` | An account, customer, or vendor referenced by even one historical transaction can never be hard-deleted (and in practice never is — it is soft-deleted, see **Soft Delete**). `RESTRICT` is the backstop against a bug that attempts a hard `DELETE` anyway. | `accounts` → `journal_lines`, `customers` → `invoices` |
| Strictly compositional child (exists only for its parent) | `CASCADE` | An invoice's line items have no independent existence; deleting a draft (never-posted) invoice should remove its lines. This only applies to `draft`-status rows — posted rows are protected by the state machine well before the foreign key is reached. | `invoices` (draft) → `invoice_items` |
| Optional cross-reference / dimension | `SET NULL` | A journal line's optional `cost_center_id` or `project_id` should not block deleting a cost center; the historical line simply loses that dimension tag. Only used on nullable FK columns. | `journal_lines.cost_center_id` → `cost_centers` |
| Polymorphic attachment | `CASCADE` via application-managed cleanup | `attachments` uses `attachable_type`/`attachable_id` (no real FK possible on a polymorphic pair); cleanup is handled by a model observer, not the database, and is noted here as the one deliberate exception to "every relationship is a real FK." | `attachments` → any attachable model |

## Indexing every foreign key

PostgreSQL does **not** automatically create an index on a foreign key column (unlike the primary key it references). Every foreign key column in QAYD's schema has an explicit index, because an unindexed FK causes a full table scan on the child table every time the parent is deleted or updated (Postgres must verify no child rows reference the parent), and because FK columns are overwhelmingly the columns QAYD's queries join and filter on anyway:

```sql
CREATE INDEX journal_lines_journal_entry_id_idx ON journal_lines (journal_entry_id);
CREATE INDEX journal_lines_account_id_idx ON journal_lines (account_id);
```

In Laravel, `$table->foreignId('account_id')->constrained('accounts')` creates the index automatically as part of `foreignId()`'s underlying `unsignedBigInteger` + index convention; migrations that instead hand-write `ALTER TABLE ... ADD CONSTRAINT` (common when adding a FK to an existing large table without a table rewrite) must add the index explicitly in the same migration.

## Deferred constraints for circular and batch-insert scenarios

A small number of relationships are legitimately circular at the schema level (a `companies` row references a `default_branch_id` on `branches`, while every `branches` row references its `company_id` back). QAYD resolves this with `DEFERRABLE INITIALLY DEFERRED` foreign keys on the column that closes the cycle, so that both rows can be inserted in one transaction regardless of insert order:

```sql
ALTER TABLE companies
    ADD CONSTRAINT companies_default_branch_id_fkey
    FOREIGN KEY (default_branch_id) REFERENCES branches(id)
    DEFERRABLE INITIALLY DEFERRED;
```

Deferred constraints are the exception, not the pattern — they are used only where a genuine circular dependency exists, and every use is commented in the migration explaining why immediate enforcement is impossible.

## Foreign keys across module boundaries

Per the platform's event-driven module communication rule, one module's tables may still hold a direct foreign key into another module's canonical tables when the relationship is a stable reference (e.g., `invoices.customer_id REFERENCES customers(id)`), because a foreign key is a data-integrity guarantee, not a code coupling — Sales does not call Accounting's PHP classes, but an `invoices` row absolutely must not be allowed to reference a `customer_id` that does not exist. The rule against cross-module coupling governs *service and business-logic* calls (event-driven, via domain events on the shared bus), not the underlying relational integrity of foreign keys, which the database always enforces directly regardless of which module "owns" which table.

# Constraints

Beyond foreign keys, QAYD leans on PostgreSQL's constraint system as the enforcement layer of last resort for every invariant that, if violated, would corrupt financial data. The guiding rule: **if violating a rule would produce an incorrect balance sheet, the rule is a database constraint, not just a Laravel validation rule.**

## NOT NULL

Every column that the business logic requires to always have a value is `NOT NULL` at the schema level, not just validated as `required` in a Laravel `FormRequest`. `company_id`, `account_id` on a journal line, `total_amount` on an invoice — none of these are ever legitimately absent, and a `NOT NULL` constraint means a bug in a raw SQL script, a data migration, or a future non-Laravel service cannot silently insert an incomplete row.

## CHECK constraints

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_debit_or_credit_not_both
    CHECK (
        (debit_amount > 0 AND credit_amount = 0) OR
        (credit_amount > 0 AND debit_amount = 0)
    );

ALTER TABLE invoices
    ADD CONSTRAINT invoices_total_non_negative
    CHECK (total_amount >= 0);

ALTER TABLE fiscal_periods
    ADD CONSTRAINT fiscal_periods_end_after_start
    CHECK (end_date > start_date);

ALTER TABLE accounts
    ADD CONSTRAINT accounts_type_valid
    CHECK (account_type IN ('asset', 'liability', 'equity', 'revenue', 'expense'));

ALTER TABLE stock_movements
    ADD CONSTRAINT stock_movements_quantity_nonzero
    CHECK (quantity <> 0);
```

## Balanced-journal-entry enforcement: constraint versus trigger

A `CHECK` constraint cannot enforce "the sum of debits across all lines of this journal entry equals the sum of credits," because a `CHECK` constraint evaluates a single row, and balance is an aggregate property across the set of `journal_lines` sharing a `journal_entry_id`. QAYD enforces this with an `AFTER INSERT OR UPDATE OR DELETE` constraint trigger, which is deferred to statement end (or transaction commit) so that a multi-line `INSERT` batch is only checked once all lines are in place:

```sql
CREATE OR REPLACE FUNCTION enforce_journal_entry_balance()
RETURNS TRIGGER AS $$
DECLARE
    v_journal_entry_id BIGINT;
    v_debit_total NUMERIC(19,4);
    v_credit_total NUMERIC(19,4);
BEGIN
    v_journal_entry_id := COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);

    SELECT COALESCE(SUM(debit_amount), 0), COALESCE(SUM(credit_amount), 0)
    INTO v_debit_total, v_credit_total
    FROM journal_lines
    WHERE journal_entry_id = v_journal_entry_id
      AND deleted_at IS NULL;

    IF v_debit_total <> v_credit_total THEN
        RAISE EXCEPTION 'Journal entry % is unbalanced: debits % <> credits %',
            v_journal_entry_id, v_debit_total, v_credit_total
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER trg_journal_entry_balance
    AFTER INSERT OR UPDATE OR DELETE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION enforce_journal_entry_balance();
```

Because the trigger is `DEFERRABLE INITIALLY DEFERRED`, Laravel's posting service can insert all lines of a multi-line journal entry within one `DB::transaction()` and the balance check only fires once, at `COMMIT`, against the final state — an intermediate unbalanced state mid-transaction never raises a false failure.

## EXCLUDE constraints for non-overlapping ranges

Fiscal periods within a fiscal year must never overlap, and QAYD enforces this with a range-based `EXCLUDE` constraint using the `btree_gist` extension rather than an application-level "check for overlap" query, which is inherently racy under concurrent inserts:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE fiscal_periods
    ADD CONSTRAINT fiscal_periods_no_overlap
    EXCLUDE USING gist (
        company_id WITH =,
        fiscal_year_id WITH =,
        daterange(start_date, end_date, '[]') WITH &&
    );
```

The same pattern secures `stock_reservations` (a reserved quantity range for a serialized/batched item must not double-book) and any future booking-style resource where two rows must never claim overlapping time or quantity ranges concurrently.

## UNIQUE constraints scoped to tenant

Every "must be unique" business rule is scoped by `company_id`, never global, because uniqueness in QAYD is always a per-tenant concept (two different companies can both have an invoice numbered `INV-0001`):

```sql
ALTER TABLE invoices
    ADD CONSTRAINT invoices_number_unique_per_company
    UNIQUE (company_id, invoice_number);

ALTER TABLE accounts
    ADD CONSTRAINT accounts_code_unique_per_company
    UNIQUE (company_id, code);
```

## Anti-pattern: validating only in Laravel

A `FormRequest::rules()` check for `'total' => 'numeric|min:0'` is necessary for a fast, friendly HTTP 422 response, but it is never treated as sufficient on its own. Every rule that protects financial correctness is duplicated as a database constraint specifically so that (a) the AI layer's writes, which go through the same Laravel API but through a different code path (a `Service` class rather than a `FormRequest`-bound controller), (b) a future non-PHP service, and (c) a manual data-fix script run by an engineer under incident pressure, cannot bypass it.

# Transactions

Every write that touches more than one row across more than one logical concern is wrapped in an explicit PostgreSQL transaction. Laravel's `DB::transaction()` is the standard entry point, and QAYD's convention is that **Service classes, not Controllers or Models, own transaction boundaries**, because a transaction boundary is a business decision (what must succeed or fail together) rather than an HTTP or persistence concern.

## Standard transaction pattern

```php
// app/Services/Accounting/JournalPostingService.php
class JournalPostingService
{
    public function post(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry->lines()->each(fn ($line) => $line->validateAgainstAccount());

            $entry->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => auth()->id()]);

            $entry->lines->each(function (JournalLine $line) {
                LedgerEntry::create([
                    'company_id'  => $line->company_id,
                    'account_id'  => $line->account_id,
                    'journal_line_id' => $line->id,
                    'debit_amount'  => $line->debit_amount,
                    'credit_amount' => $line->credit_amount,
                    'posted_at' => now(),
                ]);
            });

            AuditLog::record($entry, 'posted', auth()->user());

            event(new JournalEntryPosted($entry));

            return $entry;
        }, attempts: 3);
    }
}
```

`DB::transaction()`'s `attempts` parameter (Laravel 12) automatically retries the closure on a detected deadlock (Postgres error code `40P01`) or serialization failure (`40001`), which matters specifically because QAYD's posting workflow can have two accountants posting entries against overlapping accounts concurrently.

## Isolation levels

PostgreSQL's default isolation level is `READ COMMITTED`, which is sufficient for the majority of QAYD's CRUD operations (creating a customer, updating a product price) where no cross-row invariant is at risk from a concurrent transaction. QAYD explicitly elevates isolation for operations where a concurrent transaction could otherwise produce a financially incorrect result:

```php
DB::transaction(function () {
    DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

    // e.g., posting a journal entry that must see a fully consistent
    // snapshot of account balances and fiscal period status, with no
    // possibility of a concurrent post producing a non-serializable anomaly.
    ...
}, attempts: 5);
```

| Operation class | Isolation level | Why |
|---|---|---|
| Ordinary CRUD (customer, product, contact updates) | `READ COMMITTED` (default) | No cross-row invariant at risk; default MVCC snapshot-per-statement is sufficient. |
| Journal posting, fiscal period close | `SERIALIZABLE` | Must prevent write skew — e.g., two concurrent postings that each individually check "period is still open" and both proceed, producing two postings into a period that should have been serially closed after the first. |
| Inventory stock deduction on sale | `REPEATABLE READ` minimum, `SERIALIZABLE` for high-contention SKUs | Must prevent two concurrent sales from both reading the same on-hand quantity and both deducting, resulting in oversold stock. |
| Bank reconciliation matching | `SERIALIZABLE` | Must prevent the same bank statement line from being matched to two different ledger entries by two concurrent reconciliation attempts. |
| Payroll run calculation | `SERIALIZABLE`, single-writer enforced via advisory lock (see below) | Payroll totals must never be computed from a partially-updated employee/salary-component snapshot. |

`SERIALIZABLE` in PostgreSQL is implemented via Serializable Snapshot Isolation (SSI), which detects would-be anomalies and aborts one of the conflicting transactions with a `40001` serialization_failure — this is precisely why every `SERIALIZABLE` transaction in QAYD's Service layer is wrapped with Laravel's automatic-retry `attempts` parameter; an abort here is an expected, handled control-flow event, not an error to surface to the end user.

## Advisory locks for single-writer workflows

Some workflows (payroll run calculation, month-end close) must not just be serializable in the database-theoretic sense but must be single-writer in practice, because re-running the entire calculation concurrently twice — even if each individually is serializable — is wasted work and a confusing user experience (two "Calculating..." spinners racing). QAYD uses PostgreSQL advisory locks, scoped by company and resource, for this:

```php
public function runPayroll(PayrollRun $run): void
{
    $lockKey = crc32("payroll_run:{$run->company_id}:{$run->id}");

    DB::transaction(function () use ($run, $lockKey) {
        $acquired = DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS locked', [$lockKey])->locked;

        abort_if(! $acquired, 409, 'This payroll run is already being processed.');

        // ... calculation logic, safe from concurrent re-entry ...
    });
}
```

`pg_try_advisory_xact_lock` is transaction-scoped (automatically released at commit or rollback, never leaked by a crashed process holding a session-scoped lock indefinitely), and `pg_try_` (non-blocking) is preferred over the blocking `pg_advisory_xact_lock` so a second concurrent attempt fails fast with a clear `409 Conflict` rather than queuing invisibly.

## Savepoints for partial rollback within a larger transaction

Laravel's nested `DB::transaction()` calls automatically use `SAVEPOINT` when already inside an outer transaction, which QAYD relies on when a Service composes smaller Services — for example, `InvoicePostingService::post()` calls `JournalPostingService::post()` internally; if the inner journal posting throws, only the inner savepoint rolls back if the outer service chooses to catch and handle it (e.g., falling back to a draft state with a user-facing error) rather than propagating the failure to abort the entire outer transaction.

# ACID

Every one of the four ACID properties is a concrete, testable guarantee in QAYD's schema and Service layer, not an abstract database-theory footnote. This section states what each property means specifically for QAYD's ledger.

## Atomicity

A financial event either fully happens or does not happen at all — there is no partial state where an invoice is marked `sent` but its corresponding journal entry was never created. This is what `DB::transaction()` around `JournalPostingService::post()` guarantees: if the `LedgerEntry::create()` call in the middle of that closure throws (a constraint violation, a deadlock, an application exception), PostgreSQL rolls back every statement in the transaction, including the earlier `$entry->update(['status' => 'posted', ...])` — the invoice is left exactly as it was before the attempt, not "half posted."

## Consistency

Every transaction, on commit, leaves the database satisfying every constraint described in the **Constraints** section: no unbalanced journal entry, no overlapping fiscal period, no negative stock where negatives are disallowed, no orphaned foreign key. PostgreSQL enforces this at the statement and constraint-trigger level regardless of what the application intended — a Service bug that tries to post an unbalanced entry is rejected by the database's `enforce_journal_entry_balance()` trigger even if the Laravel-level validation that should have caught it earlier had a bug of its own.

## Isolation

Concurrent transactions do not see each other's uncommitted intermediate states, and — for the operations QAYD elevates to `SERIALIZABLE` — concurrent transactions cannot produce a result that could not have arisen from *some* serial (one-at-a-time) execution order of those same transactions. The isolation-level table in the **Transactions** section above is this guarantee applied per operation class.

## Durability

Once `DB::transaction()`'s closure returns without exception and PostgreSQL reports `COMMIT`, the data survives a crash of the database process, a crash of the underlying host, and (per the **Replication** and **Backup** sections) the loss of the entire primary server. QAYD's `postgresql.conf` uses `synchronous_commit = on` (the default) for the primary connection pool used by financial-write endpoints specifically because durability for money movement is worth the small latency cost of waiting for the WAL record to be flushed to disk (and, per the **High Availability** section, replicated to at least one synchronous standby) before returning success to the client:

```conf
# postgresql.conf — durability-critical settings
synchronous_commit = on
fsync = on
wal_sync_method = fdatasync
full_page_writes = on
```

Reporting-only or AI-analytics connections that write to non-financial tables (e.g., caching an AI-generated forecast) may use `synchronous_commit = local` or `off` at the session level for latency, but this is an explicit, narrow opt-out set per-session (`SET LOCAL synchronous_commit = off`) and is never the database-wide default, and is never used on a connection that writes to any table listed in the platform's canonical Accounting, Sales, Purchasing, Banking, Inventory, or Payroll table sets.

# Event Driven Database

QAYD's modules communicate exclusively through domain events, never through direct cross-module database writes — Sales does not `INSERT` into Accounting's `journal_entries` table from Sales' own Service class; it emits an event, and Accounting's own listener performs the write within Accounting's own transaction and its own business rules. The database layer supports this with an **outbox pattern** that guarantees an event is never lost even if the process that raised it crashes immediately after committing the primary write.

## The domain events / outbox table

```sql
CREATE TABLE domain_events (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    event_name      VARCHAR(150) NOT NULL,          -- e.g. 'invoice.created', 'invoice.paid'
    aggregate_type  VARCHAR(100) NOT NULL,           -- e.g. 'Invoice'
    aggregate_id    BIGINT NOT NULL,                 -- internal id of the source row
    payload         JSONB NOT NULL,
    metadata        JSONB NOT NULL DEFAULT '{}',     -- actor, ip, request_id, correlation_id
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'processing', 'dispatched', 'failed')),
    attempts        SMALLINT NOT NULL DEFAULT 0,
    last_error      TEXT NULL,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    dispatched_at   TIMESTAMPTZ NULL
) PARTITION BY RANGE (occurred_at);

CREATE INDEX domain_events_status_idx ON domain_events (status) WHERE status IN ('pending', 'processing');
CREATE INDEX domain_events_company_event_idx ON domain_events (company_id, event_name, occurred_at DESC);
CREATE INDEX domain_events_aggregate_idx ON domain_events (aggregate_type, aggregate_id);
```

The outbox row is written **inside the same transaction** as the business write it describes:

```php
DB::transaction(function () use ($invoice) {
    $invoice->update(['status' => 'sent', 'sent_at' => now()]);

    DomainEvent::create([
        'company_id'     => $invoice->company_id,
        'event_name'     => 'invoice.sent',
        'aggregate_type' => 'Invoice',
        'aggregate_id'   => $invoice->id,
        'payload'        => $invoice->toEventPayload(),
        'metadata'       => ['actor_id' => auth()->id(), 'request_id' => request()->header('X-Request-Id')],
    ]);
});
```

Because both writes are in one transaction, there is no window in which the invoice is marked `sent` but the event is lost (a crash between two separate, non-transactional writes), and no window in which the event exists but the invoice update rolled back. A separate relay process — a Laravel queued job (`RelayDomainEvents`) running on a short interval, or, for lower latency, a `LISTEN`/`NOTIFY` triggered dispatch — reads `pending` rows and publishes them to Laravel's event bus (which in turn drives queued listeners in other modules, and Reverb WebSocket broadcasts, and outbound webhooks).

## LISTEN/NOTIFY for low-latency relay

To avoid polling-interval latency for time-sensitive events (a payment received webhook that should update a customer's UI within roughly a second), the same transaction that inserts the outbox row also issues a lightweight `NOTIFY`:

```sql
CREATE OR REPLACE FUNCTION notify_domain_event() RETURNS TRIGGER AS $$
BEGIN
    PERFORM pg_notify('domain_events_channel', NEW.id::text);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_notify_domain_event
    AFTER INSERT ON domain_events
    FOR EACH ROW EXECUTE FUNCTION notify_domain_event();
```

A long-running relay worker (a Laravel Artisan command using `pg_listen` via `pdo_pgsql`, run under Supervisor) blocks on the channel and dispatches the referenced row's event the moment it commits, falling back to the polling job as a correctness backstop (in case the listener process itself was down when the `NOTIFY` fired — Postgres `NOTIFY` is fire-and-forget with no persistence for missed listeners, which is exactly why the durable `domain_events` table, not the notification itself, is the source of truth).

## Idempotent event handling

Every event listener is idempotent against redelivery — the relay's at-least-once delivery guarantee (a worker crash after dispatch but before marking `dispatched_at` will redeliver) means a listener must tolerate seeing `invoice.sent` for the same invoice twice without double-processing:

```php
class SendInvoiceNotificationListener
{
    public function handle(DomainEventDispatched $event): void
    {
        $cacheKey = "event_processed:{$event->domainEvent->uuid}:send_invoice_notification";

        if (Cache::has($cacheKey)) {
            return; // already processed this exact event+listener combination
        }

        // ... send the notification ...

        Cache::put($cacheKey, true, now()->addDays(7));
    }
}
```

## Retention and partitioning of the event log

`domain_events` is partitioned by `RANGE (occurred_at)` (monthly partitions, managed by `pg_partman` per the **Partitioning** section below) because it is one of the highest-insert-volume tables in the system — every meaningful state change across every module produces one row. Dispatched events older than 90 days are archived to Cloudflare R2 as compressed JSON and their partition is detached and dropped, rather than being deleted row-by-row, which would be prohibitively slow at this table's volume and would fragment the table unnecessarily.

# Audit Tables

Every mutation to a business record — create, update, soft-delete, restore, and every state transition (`draft` → `posted`, `sent` → `paid`) — writes an immutable row to `audit_logs`. This is separate from and complementary to the domain events described above: `domain_events` exists for module-to-module communication and can be pruned after processing; `audit_logs` exists for compliance, forensics, and the CFO/auditor-facing "who changed what" feature, and is retained indefinitely (subject to the retention policy in **History Tables** below).

## Schema

```sql
CREATE TABLE audit_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    user_id         BIGINT NULL REFERENCES users(id),      -- NULL for system/AI-driven changes
    actor_type      VARCHAR(20) NOT NULL DEFAULT 'user'
                    CHECK (actor_type IN ('user', 'ai_agent', 'system', 'api_integration')),
    ai_agent_name   VARCHAR(100) NULL,                      -- populated when actor_type = 'ai_agent'
    action          VARCHAR(50) NOT NULL,                    -- created, updated, deleted, restored, posted, voided...
    auditable_type  VARCHAR(100) NOT NULL,                   -- 'Invoice', 'JournalEntry', ...
    auditable_id    BIGINT NOT NULL,
    old_values      JSONB NULL,
    new_values      JSONB NULL,
    reason          TEXT NULL,                               -- required for voids/reversals/permission changes
    ip_address      INET NULL,
    user_agent      TEXT NULL,
    device_id       VARCHAR(100) NULL,
    request_id      UUID NULL,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
) PARTITION BY RANGE (occurred_at);

CREATE INDEX audit_logs_auditable_idx ON audit_logs (auditable_type, auditable_id, occurred_at DESC);
CREATE INDEX audit_logs_company_occurred_idx ON audit_logs (company_id, occurred_at DESC);
CREATE INDEX audit_logs_user_idx ON audit_logs (user_id, occurred_at DESC) WHERE user_id IS NOT NULL;
CREATE INDEX audit_logs_old_values_gin ON audit_logs USING GIN (old_values);
CREATE INDEX audit_logs_new_values_gin ON audit_logs USING GIN (new_values);
```

`audit_logs` has no `updated_at` or `deleted_at` column and no `UPDATE`/`DELETE` grant for the application role at all — an audit log entry that could itself be edited is worthless as an audit trail. The only permitted operation from the application role is `INSERT`; even the platform's own support tooling can only read, filter, and export this table, never mutate it.

## Application-layer capture: the HasAuditLog trait

```php
// app/Models/Concerns/HasAuditLog.php
trait HasAuditLog
{
    protected static function bootHasAuditLog(): void
    {
        static::created(fn ($model) => $model->writeAudit('created', null, $model->getAttributes()));

        static::updated(function ($model) {
            $model->writeAudit('updated', $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function ($model) {
            $action = $model->isForceDeleting() ? 'force_deleted' : 'deleted';
            $model->writeAudit($action, $model->getOriginal(), null);
        });
    }

    protected function writeAudit(string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'company_id'     => $this->company_id ?? app('tenant.company_id'),
            'user_id'        => auth()->id(),
            'actor_type'     => app()->bound('ai.active_agent') ? 'ai_agent' : 'user',
            'ai_agent_name'  => app()->bound('ai.active_agent') ? app('ai.active_agent') : null,
            'action'         => $action,
            'auditable_type' => class_basename($this),
            'auditable_id'   => $this->id,
            'old_values'     => $old,
            'new_values'     => $new,
            'reason'         => request()->input('reason'),
            'ip_address'     => request()->ip(),
            'user_agent'     => request()->userAgent(),
            'request_id'     => request()->header('X-Request-Id'),
        ]);
    }
}
```

## Database-layer backstop: generic audit trigger

For the highest-sensitivity tables (`journal_entries`, `journal_lines`, `payroll_items`, `tax_transactions`, `permissions`, `company_users`), QAYD adds a database-level trigger as a backstop independent of the Laravel trait, so that even a raw SQL migration or a direct `psql` fix under incident response is captured:

```sql
CREATE OR REPLACE FUNCTION audit_trigger_fn() RETURNS TRIGGER AS $$
DECLARE
    v_old JSONB;
    v_new JSONB;
BEGIN
    IF TG_OP = 'INSERT' THEN
        v_new := to_jsonb(NEW);
    ELSIF TG_OP = 'UPDATE' THEN
        v_old := to_jsonb(OLD);
        v_new := to_jsonb(NEW);
    ELSIF TG_OP = 'DELETE' THEN
        v_old := to_jsonb(OLD);
    END IF;

    INSERT INTO audit_logs (
        company_id, user_id, actor_type, action, auditable_type, auditable_id,
        old_values, new_values, occurred_at
    ) VALUES (
        COALESCE(NEW.company_id, OLD.company_id),
        NULLIF(current_setting('app.current_user_id', true), '')::bigint,
        'user',
        lower(TG_OP),
        TG_TABLE_NAME,
        COALESCE(NEW.id, OLD.id),
        v_old,
        v_new,
        now()
    );

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_audit_journal_entries
    AFTER INSERT OR UPDATE OR DELETE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION audit_trigger_fn();
```

This trigger-based audit intentionally duplicates the application-level trait's coverage for these specific tables — redundancy here is a deliberate defense-in-depth choice, not an oversight, given what is at stake if a change to a posted journal entry ever went unrecorded.

## Querying the audit trail

A CFO viewing "who changed this invoice" is a straightforward, well-indexed query:

```sql
SELECT al.occurred_at, u.name AS actor_name, al.actor_type, al.action,
       al.old_values, al.new_values, al.reason
FROM audit_logs al
LEFT JOIN users u ON u.id = al.user_id
WHERE al.company_id = $1
  AND al.auditable_type = 'Invoice'
  AND al.auditable_id = $2
ORDER BY al.occurred_at DESC;
```

# History Tables

`audit_logs` answers "what changed and who changed it" as a chronological log of diffs. For a smaller set of the most sensitive master-data tables, QAYD additionally maintains **history tables** — full-row snapshots of every prior version, structured so that "what did this row look like as of any point in time" is a single indexed query rather than a JSONB diff replay. This is QAYD's practical emulation of SQL:2011 system-versioned temporal tables, which PostgreSQL does not natively support as of version 15/16 (unlike SQL Server or MariaDB).

## Which tables get a full history table

History tables are reserved for master data whose historical state is queried directly and often, not just audited occasionally: `accounts` (chart of accounts structure changes over years), `exchange_rates` (a rate used in a historical transaction must be reproducible exactly), `tax_rates` (a tax return for a prior period must recompute using the rate in effect at that time, not today's rate), and `salary_components` (a historical payslip must be explainable using the salary structure in effect when it was run). Transactional tables (`invoices`, `journal_entries`) do **not** get history tables — they are already immutable once posted, so "history" for them is simply the audit log of the pre-posting draft edits plus the permanent posted row itself.

## History table schema and trigger

```sql
CREATE TABLE accounts_history (
    history_id      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id              BIGINT NOT NULL,               -- the accounts.id this version belonged to
    uuid            UUID NOT NULL,
    company_id      BIGINT NOT NULL,
    code            VARCHAR(30) NOT NULL,
    name_en         VARCHAR(200) NOT NULL,
    name_ar         VARCHAR(200) NOT NULL,
    account_type    VARCHAR(20) NOT NULL,
    parent_id       BIGINT NULL,
    status          VARCHAR(20) NOT NULL,
    valid_from      TIMESTAMPTZ NOT NULL,
    valid_to        TIMESTAMPTZ NOT NULL,
    changed_by      BIGINT NULL,
    change_action   VARCHAR(20) NOT NULL            -- 'updated' | 'deleted'
);

CREATE INDEX accounts_history_id_valid_idx ON accounts_history (id, valid_from, valid_to);
CREATE INDEX accounts_history_company_idx ON accounts_history (company_id);

CREATE OR REPLACE FUNCTION accounts_history_capture() RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO accounts_history (
        id, uuid, company_id, code, name_en, name_ar, account_type, parent_id, status,
        valid_from, valid_to, changed_by, change_action
    ) VALUES (
        OLD.id, OLD.uuid, OLD.company_id, OLD.code, OLD.name_en, OLD.name_ar,
        OLD.account_type, OLD.parent_id, OLD.status,
        OLD.updated_at, now(),
        NULLIF(current_setting('app.current_user_id', true), '')::bigint,
        lower(TG_OP)
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_accounts_history
    BEFORE UPDATE OR DELETE ON accounts
    FOR EACH ROW EXECUTE FUNCTION accounts_history_capture();
```

Each history row's `valid_from`/`valid_to` bounds a period during which the *previous* version of the row was live; the current live row's implicit "valid from" is its own `updated_at` with no upper bound until it is next changed. Querying "what was account 4001 called on 2025-03-15" is:

```sql
SELECT name_en, name_ar, account_type
FROM accounts_history
WHERE id = 4001 AND valid_from <= '2025-03-15' AND valid_to > '2025-03-15'
UNION ALL
SELECT name_en, name_ar, account_type
FROM accounts
WHERE id = 4001 AND updated_at <= '2025-03-15'   -- current row covers if it existed by then and never changed since
LIMIT 1;
```

In practice this point-in-time lookup is wrapped in a `SECURITY DEFINER` PL/pgSQL function (`fn_account_as_of(account_id, as_of_timestamp)`) so application code never has to hand-write the `UNION ALL` and always gets RLS-consistent, correct results.

## Retention of history tables

History rows are never purged on a rolling window the way `domain_events` is — a tax audit can reach back seven years or more depending on jurisdiction, and reconstructing "what exchange rate applied to this 2019 invoice" must remain possible for as long as the source transaction itself is retained. History tables are partitioned by `valid_to` (RANGE, yearly) purely for query and vacuum performance, not for expiry; partitions are never dropped, only compressed and, after a configurable number of years (default 10, configurable per company's jurisdiction), moved to a cheaper storage tablespace.

# Soft Delete

No business table permits a hard `DELETE` from the application role. Every table's `deleted_at TIMESTAMPTZ NULL` column is the only mechanism by which a row is "removed," and QAYD relies on Laravel's native `SoftDeletes` trait paired with PostgreSQL partial indexes and constraints to make soft-delete correct rather than merely convenient.

## Enforcing soft-delete-only at the database level

The application database role is granted `INSERT, SELECT, UPDATE` on every business table but explicitly **not** `DELETE`:

```sql
REVOKE DELETE ON ALL TABLES IN SCHEMA public FROM qayd_app_role;
GRANT DELETE ON audit_logs TO NOBODY; -- audit_logs permits no delete for any role, including platform_worker
```

A `DELETE` attempted by the application (a bug, a forgotten `->forceDelete()`, a raw query) fails with a permissions error at the database level rather than silently succeeding — this is the same "belt and suspenders" philosophy as RLS: application discipline (Eloquent's `SoftDeletes` trait intercepting `->delete()` calls and issuing an `UPDATE ... SET deleted_at = now()` instead of a real `DELETE`) is necessary but the database grant is what makes it actually impossible to bypass.

```php
// app/Models/Invoice.php
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes, BelongsToCompany, HasAuditLog;

    protected $dates = ['deleted_at'];
}
```

## Partial indexes that exclude soft-deleted rows

Every index that supports an application query on "active" records is a **partial index** filtering `WHERE deleted_at IS NULL`, both because the vast majority of queries only ever want live rows, and because a smaller index (excluding the accumulated dead rows over years of soft-deletes) stays faster and cheaper to maintain:

```sql
CREATE INDEX invoices_company_status_idx ON invoices (company_id, status)
    WHERE deleted_at IS NULL;

CREATE UNIQUE INDEX customers_tax_id_unique_active ON customers (company_id, tax_registration_number)
    WHERE deleted_at IS NULL;
```

The second example above is the standard pattern for a "must be unique among active rows" business rule — a customer's tax ID must be unique among customers that are not soft-deleted, but a *new* customer is permitted to reuse a tax ID that belonged to a customer who was soft-deleted (an intentional business allowance: correcting a mistakenly duplicated customer record should not permanently burn that tax ID).

## Restore

Restoring a soft-deleted row is `UPDATE ... SET deleted_at = NULL`, exposed as a dedicated, permissioned API action (`POST /api/v1/{module}/{resource}/{uuid}/restore`) rather than implicit in a generic `PATCH`, because restoring a record is a meaningful business event in its own right (it re-audits, it re-fires relevant domain events such as `customer.restored` for any downstream system that had reacted to the deletion) and must go through the same permission check (`<area>.<entity>.restore`) as the delete itself.

```php
public function restore(Invoice $invoice): JsonResponse
{
    $this->authorize('accounting.invoice.restore', $invoice);

    $invoice->restore(); // Eloquent SoftDeletes: sets deleted_at = null

    AuditLog::record($invoice, 'restored', auth()->user());
    event(new InvoiceRestored($invoice));

    return response()->json(['success' => true, 'data' => new InvoiceResource($invoice)]);
}
```

## Cascading soft delete

Deleting a parent row soft-deletes dependent child rows that have no independent existence (draft invoice → its line items), implemented via a model observer rather than a database trigger, because cascading soft-delete is a business rule with exceptions (a *posted* invoice's line items are never touched by a delete on the invoice — deleting a posted invoice is itself disallowed by the state machine long before reaching this logic) that are easier to express and test in PHP than in PL/pgSQL:

```php
static::deleting(function (Invoice $invoice) {
    if ($invoice->status !== 'draft') {
        throw new IllegalStateTransitionException('Only draft invoices can be deleted.');
    }
    $invoice->items()->each(fn ($item) => $item->delete());
});
```

## Anti-pattern: filtering soft-deleted rows manually everywhere

Because Eloquent's global `SoftDeletingScope` already appends `WHERE deleted_at IS NULL` to every query by default, engineers must never hand-write that filter in raw queries or Query Builder calls that bypass Eloquent — doing so is redundant at best and, if the filter is later forgotten in one of many hand-written places while the actual soft-delete convention evolves (for example, adding a `permanently_deleted_at` for GDPR-style hard purges — see **Future Architecture**), becomes a silent correctness bug. Any query that must see soft-deleted rows uses Eloquent's explicit `withTrashed()` or `onlyTrashed()`, never a hand-rolled `WHERE deleted_at IS NOT NULL`.

# Versioning

QAYD distinguishes three unrelated things that all get called "versioning": **optimistic concurrency versioning** (has this row changed since I last read it), **row history versioning** (covered above under History Tables), and **schema/migration versioning** (has the database structure itself changed). This section covers the first and third.

## Optimistic concurrency control

Every table that can be edited by more than one actor concurrently (which, given the AI layer plus human users, is effectively every table) carries a `version` column, an integer incremented on every update, used to detect and reject a write based on stale data — the classic "someone else already saved changes to this record" scenario, common when a human accountant and an AI Auditor agent might both have a stale copy of the same draft invoice open:

```sql
ALTER TABLE invoices ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
```

```php
public function update(Invoice $invoice, UpdateInvoiceRequest $request): JsonResponse
{
    $expectedVersion = $request->input('version');

    $updated = DB::table('invoices')
        ->where('id', $invoice->id)
        ->where('version', $expectedVersion)
        ->update([
            ...$request->validated(),
            'version'    => $expectedVersion + 1,
            'updated_at' => now(),
            'updated_by' => auth()->id(),
        ]);

    abort_if($updated === 0, 409, 'This invoice was modified by someone else. Reload and try again.');

    return response()->json(['success' => true, 'data' => new InvoiceResource($invoice->refresh())]);
}
```

The API exposes `version` in every resource response, and every `PUT`/`PATCH` request must echo back the version it read, enforced by a `FormRequest` rule (`'version' => 'required|integer'`). This is used specifically for records still in a mutable (`draft`) state; once a record is posted, immutability (see **Database Philosophy**) makes optimistic locking moot — there is nothing left to concurrently edit.

## Schema (migration) versioning

Every schema change is a Laravel migration file, checked into version control, applied in a strict, monotonic order tracked by Laravel's own `migrations` table (`id`, `migration`, `batch`). QAYD's convention layers three additional disciplines on top of Laravel's default:

- **One logical change per migration file.** A migration that adds a column and a migration that backfills that column's data are two separate files, so a failed backfill on a huge table never blocks the (already-succeeded) structural change from being recorded, and so a backfill can be safely re-run idempotently if it fails partway.
- **Every migration is reversible.** `down()` is implemented for real, not left as a no-op, and CI runs `php artisan migrate && php artisan migrate:rollback && php artisan migrate` against a fresh database on every pull request to prove the round-trip is clean.
- **Large-table migrations avoid full-table locks.** Adding a `NOT NULL` column with a default to a table with hundreds of millions of rows (`journal_lines`) is never a single `ALTER TABLE ... ADD COLUMN ... NOT NULL DEFAULT ...` (which historically required a full table rewrite pre-Postgres-11, and even post-11 with volatile defaults still can); it is split into `ADD COLUMN nullable`, a batched backfill job, then `ALTER TABLE ... ADD CONSTRAINT ... CHECK (col IS NOT NULL) NOT VALID` followed by `VALIDATE CONSTRAINT` run separately (which takes only a brief lock to check, not to rewrite):

```sql
-- Migration 1
ALTER TABLE journal_lines ADD COLUMN cost_center_id BIGINT NULL;

-- Migration 2 (batched backfill job, not a single UPDATE on 200M rows)
-- run in application code, 5,000 rows per batch, with a short sleep between batches

-- Migration 3
ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_cost_center_fk
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) NOT VALID;
ALTER TABLE journal_lines VALIDATE CONSTRAINT journal_lines_cost_center_fk;
```

- **Migrations never contain conditional environment logic.** A migration that behaves differently in staging versus production is a migration that has not actually been tested before reaching production; QAYD's CI runs every migration against a database seeded to resemble the largest production tenant's row counts before it is allowed to merge.

# JSON Columns

JSONB is used deliberately and narrowly: for data that is genuinely schemaless per-tenant or per-event, never as a substitute for a real column when the data has a fixed shape and participates in filtering, joining, or aggregation.

## Where JSONB is used

| Column | Table(s) | Why JSONB is correct here |
|---|---|---|
| `custom_fields` | most master-data tables (`customers`, `vendors`, `products`) | Different tenants configure entirely different custom fields; no fixed schema exists across tenants. |
| `tags` | most master-data tables | An open-ended, per-tenant array of labels with no fixed cardinality or shape. |
| `payload` | `domain_events`, `webhooks_log` | The shape of an event payload varies by `event_name`; a single generic outbox table cannot have one column per possible event shape. |
| `reasoning`, `source_documents` | AI-related tables (`ai_messages`, any `*_ai_review` table) | AI output structure varies by agent and task; capturing it as structured-but-flexible JSON preserves detail without a combinatorial explosion of nullable columns. |
| `metadata` | `audit_logs`, `domain_events` | Contextual, variable-shape request metadata (device info, correlation IDs) that is useful for forensics but never filtered on as a primary query path. |
| `settings` | `companies`, `users` | A genuinely evolving bag of preferences (locale, notification channels, feature flags) that changes shape release over release faster than a migration cadence should have to track. |

## Where JSONB is explicitly rejected

A fixed, cross-tenant field with a real type — `invoices.total_amount`, `customers.email`, `journal_lines.debit_amount` — is never stored inside a JSONB blob (e.g., a generic `attributes JSONB` catch-all column), even though it would technically "work," because it would forfeit `NOT NULL`, `CHECK`, foreign key, and B-tree index support, and because `WHERE (attributes->>'total_amount')::numeric > 1000` is both slower and less safe (no type enforcement on write) than `WHERE total_amount > 1000` against a real `NUMERIC(19,4)` column.

## Structural validation on JSONB

Even schemaless-by-design JSONB columns carry a minimal `CHECK` constraint guaranteeing they are at least the right *kind* of JSON value, catching an application bug that accidentally serializes a JSON string instead of a JSON object:

```sql
ALTER TABLE customers
    ADD CONSTRAINT customers_custom_fields_is_object
    CHECK (jsonb_typeof(custom_fields) = 'object');

ALTER TABLE customers
    ADD CONSTRAINT customers_tags_is_array
    CHECK (jsonb_typeof(tags) = 'array');
```

For payloads with a known, versioned inner shape (`domain_events.payload` for a specific `event_name`), QAYD additionally validates against a JSON Schema at the application boundary (a `Spatie\LaravelData` DTO or a dedicated `EventPayloadValidator`) before the row is ever written — the database-level `jsonb_typeof` check is the last-resort backstop, not the primary validation.

## Indexing JSONB

Two index strategies cover QAYD's JSONB query patterns:

```sql
-- Containment queries: WHERE custom_fields @> '{"industry": "retail"}'
CREATE INDEX customers_custom_fields_gin ON customers USING GIN (custom_fields);

-- Targeted key lookups on a specific, known-hot key: WHERE tags ? 'vip'
CREATE INDEX customers_tags_gin ON customers USING GIN (tags jsonb_path_ops);
```

`jsonb_path_ops` produces a smaller, faster index than the default `jsonb_ops` operator class when the query pattern is exclusively `@>`/containment (as with `tags`), at the cost of not supporting the `?`/`?|`/`?&` existence operators — QAYD picks the operator class per column based on the actual query pattern used against it, documented as a comment directly above the `CREATE INDEX` statement in the migration.

For composite queries mixing a scalar column and a JSONB containment check (`WHERE company_id = ? AND custom_fields @> ?`), a `btree_gin` composite index avoids two separate index scans:

```sql
CREATE INDEX customers_company_custom_fields_idx
    ON customers USING GIN (company_id, custom_fields);
```

## Extracting JSONB into a real column when it stabilizes

QAYD treats "a custom field that most tenants end up configuring the same way" as a signal to promote that field out of JSONB into a first-class column in a future migration, generated initially as a `GENERATED ALWAYS AS (...) STORED` column for a transition period so existing queries against the JSONB path keep working while new queries adopt the real column:

```sql
ALTER TABLE customers ADD COLUMN industry VARCHAR(100)
    GENERATED ALWAYS AS (custom_fields->>'industry') STORED;

CREATE INDEX customers_industry_idx ON customers (industry);
```

# Index Strategy

Every index in QAYD's schema exists to serve a named, real query pattern — either a query the application issues today or one explicitly planned in a module's specification — and every index is reviewed periodically against `pg_stat_user_indexes` to confirm it is actually used, because an unused index is pure write-amplification cost (every `INSERT`/`UPDATE` maintains it) with no read benefit.

## Default: B-tree

B-tree is the default and correct choice for the overwhelming majority of QAYD's indexes: primary keys, foreign keys, equality and range filters, and `ORDER BY` support.

```sql
CREATE INDEX invoices_company_created_idx ON invoices (company_id, created_at DESC);
```

Column order in a composite B-tree index matters and follows the rule "equality columns first, in the order they are most selectively filtered, range/sort columns last": a query that filters `WHERE company_id = ? AND status = 'sent' ORDER BY created_at DESC` is served efficiently by an index on `(company_id, status, created_at DESC)`, not `(created_at, company_id, status)`.

## Partial indexes

Beyond the soft-delete partial indexes already described, partial indexes serve any query pattern that only ever targets a meaningful subset of rows:

```sql
-- Only pending/processing domain events are ever looked up by status
CREATE INDEX domain_events_pending_idx ON domain_events (occurred_at)
    WHERE status IN ('pending', 'processing');

-- Only overdue, unpaid invoices are queried for the collections dashboard
CREATE INDEX invoices_overdue_idx ON invoices (company_id, due_date)
    WHERE status = 'sent' AND due_date < CURRENT_DATE AND deleted_at IS NULL;
```

## Covering indexes with INCLUDE

Where a hot query selects a small number of additional columns beyond its filter/sort columns, an `INCLUDE` clause lets PostgreSQL answer the query entirely from the index without a heap fetch (an "index-only scan"), provided the visibility map cooperates (recently vacuumed table):

```sql
CREATE INDEX invoices_company_status_covering_idx
    ON invoices (company_id, status)
    INCLUDE (invoice_number, total_amount, due_date)
    WHERE deleted_at IS NULL;
```

## GIN for JSONB and full-text

Covered in **JSON Columns** above; the same `GIN` mechanism also backs full-text search on bilingual product/customer names via `to_tsvector('simple', name_en || ' ' || name_ar)` for the AI Document/OCR agent's fuzzy matching, combined with `pg_trgm` for typo-tolerant `ILIKE`/similarity search:

```sql
CREATE INDEX products_name_trgm_idx ON products USING GIN (name_en gin_trgm_ops, name_ar gin_trgm_ops);
```

## BRIN for very large, naturally-ordered tables

For extremely large, insert-ordered tables where a full B-tree's size is disproportionate to the selectivity benefit it provides — `audit_logs` and `domain_events` at hundreds of millions of rows, queried predominantly by coarse time ranges — a `BRIN` (Block Range Index) index on the timestamp column is a small fraction of a B-tree's size and is sufficient because the physical row order already correlates strongly with insertion time:

```sql
CREATE INDEX audit_logs_occurred_brin_idx ON audit_logs USING BRIN (occurred_at)
    WITH (pages_per_range = 32);
```

BRIN is deliberately not used as a replacement for the B-tree indexes that support equality lookups (`auditable_type, auditable_id`) — it is additive, targeting only the coarse time-range scan pattern that a partition-pruning query still benefits from within a single partition.

## Index maintenance

`REINDEX CONCURRENTLY` (available since PostgreSQL 12) is the standard tool for rebuilding a bloated index without taking a table lock that would block production writes:

```sql
REINDEX INDEX CONCURRENTLY invoices_company_status_idx;
```

A weekly scheduled job inspects `pg_stat_user_indexes.idx_scan` and `pgstattuple`'s bloat estimate across the schema, flags indexes with high bloat (>30%) for a `REINDEX CONCURRENTLY`, and flags indexes with zero scans over a rolling 30-day window as drop candidates for manual engineering review (never auto-dropped — an index with zero recent scans might still be a safety net for an infrequent but important admin report).

## Anti-patterns

- **Indexing every column "just in case."** Each additional index is real, measurable write-path cost; QAYD requires a named query pattern (a controller method, a report, an AI agent's known lookup) cited in the migration's comment before an index is added.
- **Redundant indexes.** An index on `(company_id)` alone is redundant once an index on `(company_id, status)` exists, because the leading column of a composite B-tree already serves single-column equality queries on that column — QAYD's CI includes a `pg_stat_user_indexes`-based redundancy linter that flags this pattern in review.
- **Over-wide composite indexes.** An index with six columns to serve one specific report query is rarely worth its write-amplification and storage cost versus letting that one infrequent report do a bounded sequential scan or use a narrower two-column index plus a filter; QAYD's rule of thumb is no more than three key columns in a B-tree index outside of deliberate covering-index (`INCLUDE`) cases.

# Partitioning

Database Philosophy's eighth principle — every table that will ever be large is partitioned before it is large — is not aspirational in this section; it is the literal, load-bearing design of QAYD's highest-volume tables. `audit_logs` and `domain_events` are already partitioned by `RANGE (occurred_at)`, shown in their respective DDL above. This section extends the same discipline to the remaining high-volume tables — `journal_lines`, `ledger_entries`, `stock_movements`, `bank_transactions`, `ai_messages` — and states the mechanics, the trade-offs, and the maintenance automation that make partitioning operationally sustainable rather than a one-time migration that bit-rots.

## Which tables are partitioned, and why header tables are not

QAYD partitions the **line and event tables**, never the **header tables** that own them. A `journal_entries` row is created once per posting event; its `journal_lines` children are created five, ten, or fifty times as often, and accumulate at the rate of the business's actual transaction volume rather than the rate of "postings" as a human-scale event. The same asymmetry holds for `invoices` versus nothing further downstream in that direction (invoices are already a leaf relative to accounting, but feed `journal_lines` on the accounting side) and for `payroll_runs` versus `payroll_items`. Partitioning the low-volume header table would add operational complexity (every partitioned table needs partition-aware unique constraints, as shown below) for a table that will still be comfortably sized at ten million rows; partitioning the high-volume line table is what actually bounds index size, vacuum duration, and query planning time. The rule QAYD applies when a new table is designed: if the table is a child of a 1:N relationship where N is unbounded and driven by transaction volume rather than by a human decision to create a record, it is partitioned from its first migration.

## Standard scheme: monthly RANGE partitioning on the table's primary timestamp

Every partitioned table in QAYD uses `RANGE` partitioning, monthly intervals, on the single timestamp column that represents when the row is financially or operationally "of" — `posted_at` for `journal_lines` and `ledger_entries` (the accounting date, not the row's `created_at`, because a correction posted today for a prior period must partition-prune to the period it affects, not the day it was typed), and `created_at` for tables with no separate posting concept (`stock_movements`, `bank_transactions`, `ai_messages`). QAYD deliberately does not use `HASH` or `LIST` partitioning on these tables: the dominant query shape across accounting, reporting, and the AI layer is "this company, this period," and a `RANGE` scheme on the date column is what lets the planner prune to a small, bounded number of partitions regardless of how many companies or how many rows exist in total. `company_id` is left as an ordinary indexed column within each date partition rather than a partitioning dimension, because splitting the low tens-of-thousands of companies across partitions would create a maintenance dimension (rebalancing as tenants grow at wildly different rates) that RANGE-by-date does not have.

```sql
CREATE TABLE journal_lines (
    id                BIGINT GENERATED ALWAYS AS IDENTITY,
    uuid              UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    journal_entry_id  BIGINT NOT NULL REFERENCES journal_entries(id),
    account_id        BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id    BIGINT NULL REFERENCES cost_centers(id),
    project_id        BIGINT NULL REFERENCES projects(id),
    debit_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code     CHAR(3) NOT NULL,
    exchange_rate     NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_amount       NUMERIC(19,4) NOT NULL,
    posted_at         TIMESTAMPTZ NOT NULL,
    created_by        BIGINT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ NULL,
    PRIMARY KEY (id, posted_at)
) PARTITION BY RANGE (posted_at);

CREATE TABLE journal_lines_2026_07 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE journal_lines_2026_08 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
CREATE TABLE journal_lines_default PARTITION OF journal_lines DEFAULT;

CREATE INDEX ON journal_lines (company_id, account_id, posted_at);
CREATE INDEX ON journal_lines (journal_entry_id);
CREATE UNIQUE INDEX journal_lines_uuid_unique ON journal_lines (uuid, posted_at);
```

`ledger_entries` is partitioned identically, on its own `posted_at` column shown in the **Transactions** section's `LedgerEntry::create()` example, because it is queried with the same "this company, this period" shape and grows at the same rate as `journal_lines` (it is, by construction, a row-for-row projection of posted journal lines).

## The composite-key trade-off, stated plainly

PostgreSQL requires every unique index on a partitioned table — including its primary key — to include the partitioning column, because a unique index is physically implemented per-partition and the engine cannot enforce global uniqueness across partitions with a single index. This is the one documented exception to the **Primary Keys** section's rule that every table's primary key is a bare `BIGINT id`: on a partitioned table, the primary key is the composite `(id, posted_at)`, and the `uuid` unique index becomes `(uuid, posted_at)` rather than `uuid` alone.

QAYD accepts this deliberately rather than treating it as a gap. Global uniqueness of `id` is still true in practice — it is generated from one shared identity sequence across all partitions of the table, and a sequence never repeats a value — PostgreSQL simply cannot *prove* that global property with a single index the way it can on an unpartitioned table. Global uniqueness of `uuid` relies on the collision probability of `gen_random_uuid()` (a 122-bit random space), which is negligible at any volume QAYD will reach before a longer-term key strategy revision. Both trade-offs are recorded here rather than left as an implicit surprise for the next engineer who runs `\d journal_lines` and finds a two-column primary key. The practical consequence that matters day-to-day: nothing in QAYD's schema holds a foreign key that points *into* `journal_lines`, `ledger_entries`, `audit_logs`, `stock_movements`, or `domain_events` — every partitioned table in this document is a leaf in the referential graph — which sidesteps the much harder problem of a foreign key needing to reference a composite, partition-spanning key. This is a design constraint the schema review checklist enforces explicitly: a proposal to add a foreign key referencing a partitioned table is rejected in favor of denormalizing the referenced value or reversing the relationship's direction.

## Automated partition maintenance with pg_partman

Partitions are never created or dropped by a manually-run `CREATE TABLE ... PARTITION OF` in production; `pg_partman` (declared as a required extension in **Why PostgreSQL**) owns partition lifecycle for every partitioned table from the migration that creates it:

```sql
SELECT partman.create_parent(
    p_parent_table   => 'public.journal_lines',
    p_control        => 'posted_at',
    p_type           => 'range',
    p_interval       => 'monthly',
    p_premake        => 3          -- always keep 3 months of future partitions pre-created
);

UPDATE partman.part_config
SET retention               = '84 months',    -- 7 years, matching statutory financial-record retention
    retention_keep_table    = true,            -- DETACH, never DROP outright — see archiving below
    infinite_time_partitions = true
WHERE parent_table = 'public.journal_lines';
```

The same `create_parent` call, with `p_control` and retention adjusted per table, registers `ledger_entries`, `stock_movements`, `bank_transactions`, and `ai_messages`. A scheduled job invokes maintenance on a ten-minute cycle via `pg_cron`, so a partition for next month always exists well before it is needed and expired partitions are detached promptly rather than accumulating:

```sql
SELECT cron.schedule('partman-run-maintenance', '*/10 * * * *',
    $$CALL partman.run_maintenance_proc()$$);
```

## Partition pruning and why the partition key must appear in every query

The entire benefit of partitioning collapses if a query cannot be pruned to a small subset of partitions — a report that scans `journal_lines` without a `posted_at` predicate degrades to scanning all 84+ monthly partitions, which is strictly worse than an unpartitioned table for that one query. Every controller, report, and AI-agent query against a partitioned table is required, by schema review checklist, to include a bounded predicate on the partition column:

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT account_id, SUM(debit_amount), SUM(credit_amount)
FROM journal_lines
WHERE company_id = 42
  AND posted_at >= '2026-07-01' AND posted_at < '2026-08-01'
GROUP BY account_id;
-- Plan shows "Partitions removed: 83" (or similar, version-dependent wording) — a single
-- child partition (journal_lines_2026_07) is scanned, not the parent's full 84-partition set.
```

CI includes a lint that flags any new query in a `Service` class touching a partitioned table's Eloquent model without a corresponding `whereBetween` (or equivalent) on that table's registered partition column, sourced from a `config('partitioning.tables')` map that names each partitioned table's key column.

## Archiving instead of dropping

Expired partitions are **detached, exported, then dropped** — never dropped directly — because a `DROP` destroys the data outright while a detach-then-export preserves it in cold storage for the remainder of the statutory retention window at a fraction of hot-cluster storage cost:

```sql
ALTER TABLE journal_lines DETACH PARTITION journal_lines_2019_01 CONCURRENTLY;
-- CONCURRENTLY (PG14+) avoids taking a lock on the parent that would block concurrent writers
-- to the still-live partitions while the detach of an old, cold partition proceeds.
```

The detached table is exported to Cloudflare R2 as compressed Parquet (via a one-off `COPY (SELECT * FROM journal_lines_2019_01) TO PROGRAM '... | zstd | aws s3 cp - s3://qayd-cold/...' WITH (FORMAT csv)` pipeline, or an equivalent Parquet writer for analytics-friendly columnar storage) and only then is the now-empty local partition table dropped. This connects directly to the data-lake export item in **Future Architecture**.

## Partition summary

| Table | Partition key | Interval | Retention before archive |
|---|---|---|---|
| `journal_lines` | `posted_at` | monthly | 84 months (7 years) |
| `ledger_entries` | `posted_at` | monthly | 84 months |
| `audit_logs` | `occurred_at` | monthly | indefinite — tiered to cheaper storage after 24 months, never dropped (see **History Tables**) |
| `domain_events` | `occurred_at` | monthly | 90 days, then archived and dropped |
| `stock_movements` | `created_at` | monthly | 60 months |
| `bank_transactions` | `created_at` | monthly | 84 months |
| `ai_messages` | `created_at` | monthly | 24 months hot, then cold storage |

# Replication

QAYD's replication topology exists to satisfy two distinct needs that must not be conflated: **durability and availability** (a second and third copy of every committed transaction, ready to take over within seconds of a primary failure) and **read scaling** (offloading reporting and AI query load from the primary so it is never competing with a month-end close for I/O). The topology below serves both, and the **High Availability** and **Read Replicas** sections describe how the application actually uses it.

## Topology

```
                        ┌───────────────────────────┐
                        │   pg-primary (AZ-a)       │◄── all writes, via PgBouncer (transaction pooling)
                        └─────────────┬─────────────┘
                synchronous_standby_names = 'ANY 1 (replica_a, replica_b)'
             ┌────────────────────────┼────────────────────────┐
             ▼                                                 ▼
   ┌───────────────────────┐                         ┌───────────────────────┐
   │ pg-replica-a (AZ-b)    │                         │ pg-replica-b (AZ-c)    │
   │ hot_standby = on       │                         │ hot_standby = on       │
   │ serves reporting reads │                         │ serves reporting reads │
   └───────────┬───────────┘                         └───────────────────────┘
               │ cascading async physical replication
               ▼
   ┌───────────────────────────────┐
   │ pg-dr-standby (second region) │  async — RPO bounded by replication lag; promoted only in a
   └───────────────┬───────────────┘  declared DR event (see Disaster Recovery)
                    │ logical replication — CDC, explicit table allow-list only
                    ▼
   ┌───────────────────────────────┐
   │ analytics / AI read model DB  │
   └───────────────────────────────┘
```

Two in-region replicas exist rather than one so that the loss of a single availability zone never leaves the cluster with zero standby capacity for `synchronous_standby_names = 'ANY 1 (...)'` to satisfy; the primary always has at least one other synchronous-eligible target even while one replica is down for maintenance.

## Primary-side configuration

```conf
# postgresql.conf — primary, replication-relevant settings
wal_level = replica
max_wal_senders = 10
max_replication_slots = 10
wal_keep_size = 2GB
hot_standby = on
synchronous_commit = on
synchronous_standby_names = 'ANY 1 (replica_a, replica_b)'
```

`ANY 1 (replica_a, replica_b)` means a commit on the primary waits for acknowledgment from at least one of the two named in-region replicas before returning success to the client — this is the mechanism, referenced in **ACID**'s Durability discussion, that makes a committed transaction survive the outright loss of the primary node, not just a crash-and-restart of the same node. `wal_keep_size` is a bounded safety margin on top of replication slots (below); it is not the primary retention mechanism, because a size-based limit alone can still let WAL be recycled out from under a replica that has fallen far enough behind, whereas a slot cannot.

## Replication slots and roles

```sql
CREATE ROLE replicator WITH REPLICATION LOGIN PASSWORD :'replicator_password';

SELECT pg_create_physical_replication_slot('replica_a_slot');
SELECT pg_create_physical_replication_slot('replica_b_slot');
SELECT pg_create_physical_replication_slot('dr_standby_slot');
```

```
# pg_hba.conf — primary
hostssl   replication   replicator   10.20.1.0/24   scram-sha-256
hostssl   replication   replicator   10.20.2.0/24   scram-sha-256
hostssl   replication   replicator   10.30.0.0/16   scram-sha-256   # cross-region DR standby
```

A physical replication slot reserves WAL on the primary until the specific replica named by that slot has confirmed receipt, which is what prevents the primary from recycling a WAL segment a lagging replica still needs — the failure mode a slot exists to prevent is a replica that falls silent for hours (a network partition, a stuck disk) discovering, upon reconnecting, that the WAL it needs to catch up has already been removed and it must be rebuilt from a fresh base backup instead of simply resuming streaming.

## Standby configuration

```conf
# postgresql.conf on replica_a
primary_conninfo = 'host=pg-primary.internal port=5432 user=replicator password=... sslmode=verify-full application_name=replica_a'
primary_slot_name = 'replica_a_slot'
hot_standby = on
```

An empty `standby.signal` file in the replica's data directory is what marks it as a standby under PostgreSQL 12+'s configuration model (the pre-12 `recovery.conf` file is not used anywhere in QAYD's infrastructure). `application_name` in `primary_conninfo` is what `synchronous_standby_names` on the primary matches against — the two names must agree exactly, and QAYD's infrastructure-as-code (Terraform + Ansible) generates both from the same source variable to prevent drift between them.

## Logical replication for change-data-capture

The analytics and AI read-model pipeline never connects directly to the OLTP primary or its physical standbys; it consumes a **logical replication publication that names an explicit table allow-list**, never `FOR ALL TABLES`, so that a schema addition to a sensitive table does not silently start flowing into a downstream store with weaker access controls than the RLS-protected primary:

```sql
-- on the primary
CREATE PUBLICATION qayd_reporting_pub FOR TABLE
    accounts, journal_entries, journal_lines, ledger_entries,
    customers, vendors, invoices, invoice_items, bills;
    -- Deliberately excludes bank_accounts, employees, payroll_items, tax_transactions, and
    -- any column protected under Encryption's column-level scheme — the analytics/AI pipeline
    -- never receives raw bank account numbers, national IDs, or payroll amounts per employee.

-- on the analytics database
CREATE SUBSCRIPTION qayd_reporting_sub
    CONNECTION 'host=pg-primary.internal dbname=qayd user=replicator sslmode=verify-full'
    PUBLICATION qayd_reporting_pub
    WITH (copy_data = true, create_slot = true, slot_name = 'qayd_reporting_slot');
```

Adding a table to `qayd_reporting_pub` is a reviewed, deliberate change — the default for any new table is exclusion, and inclusion requires a named justification in the migration (which report or AI agent needs it) exactly as **Index Strategy** requires a named justification for a new index.

# Backup

Backups exist to answer one question under pressure: "can we get this exact company's ledger back, as of a specific second, without guessing." QAYD's backup strategy is built around **pgBackRest** as the primary tool for the OLTP cluster (full, differential, and incremental physical backups plus continuous WAL archiving), because it supports block-level incremental backups, parallel compression, and fan-out to multiple storage repositories in a single invocation without requiring a separate scripting layer on top.

## Cadence and retention

| Backup type | Command | Cadence | Rolling retention |
|---|---|---|---|
| Full | `pgbackrest --stanza=qayd-prod backup --type=full` | Weekly, Sunday 01:00 Asia/Kuwait | 5 fulls (~5 weeks) kept hot; one full per month retained for 84 months |
| Differential | `pgbackrest --stanza=qayd-prod backup --type=diff` | Daily, 01:00 | 14 days |
| Incremental | `pgbackrest --stanza=qayd-prod backup --type=incr` | Every 6 hours | 3 days |
| Continuous WAL | `archive_command` → `pgbackrest archive-push` | Continuous, per 16MB segment or `archive_timeout=60s` | 14 days beyond the oldest retained full |

```conf
# postgresql.conf — WAL archiving
archive_mode = on
archive_command = 'pgbackrest --stanza=qayd-prod archive-push %p'
archive_timeout = 60
```

`archive_timeout = 60` bounds the worst case: even a quiet period with no natural WAL segment fill still forces a segment (and therefore an archive push) at least once a minute, which is what makes the backup-derived **RPO** a concrete, defensible number (well under two minutes) rather than "whenever the next 16MB of WAL happens to accumulate."

## Storage, encryption, and immutability

Backup repositories live in Cloudflare R2 (the platform's standard object storage, per the platform stack), encrypted at the repository level (`repo1-cipher-type=aes-256-cbc`, key sourced from the platform KMS, never checked into infrastructure code) and written with object-lock-style immutability so that a compromised operator credential or a ransomware event cannot delete or overwrite backup history inside the retention window — this is the specific defense that makes "an attacker got write access to production" not automatically mean "and also deleted the backups that would undo the damage."

## Restore verification

A backup that has never been restored is a hypothesis, not a backup, per **Database Philosophy**'s ninth principle. QAYD runs an automated monthly restore drill: `pgbackrest restore` into a scratch instance from the latest full backup, followed by a scripted checksum comparison (row counts per table, and a `pg_checksums` verification pass) against expectations captured at backup time, with the date and outcome of each drill logged as an operational metric reviewed at the same cadence as uptime SLOs.

## Per-tenant logical export

Independent of the physical backup system, a company requesting a full export of its own data (contractual data portability, or a GDPR-style access request from a jurisdiction QAYD operates in) is served by a scoped logical export rather than a restore of the physical backup: a background job authenticates as `qayd_platform_worker`, explicitly sets `app.current_company_id` to the requesting company's ID (never omits it — this job runs under `BYPASSRLS` and is exactly the kind of query the **Row Level Security** section warns must filter by hand), and streams every RLS-scoped table via `COPY (SELECT * FROM <table>) TO STDOUT WITH CSV HEADER` into a per-table CSV bundle, zipped and delivered through a signed, time-limited R2 URL rather than an email attachment.

# Disaster Recovery

Disaster recovery is distinct from ordinary high availability: **High Availability** (next section) covers the loss of a single node inside one region, handled automatically within seconds. This section covers the loss of an entire region, or a corruption/security event that a fast automatic failover would faithfully replicate rather than fix — cases that require a human-declared incident and a runbook, not an automated controller.

## Tiers

| Tier | Trigger | Mechanism | RPO | RTO | Automation |
|---|---|---|---|---|---|
| 0 — Node loss | Single primary or replica host fails | Patroni-managed automatic failover to an in-region synchronous replica | ≈0 (synchronous commit) | < 2 minutes | Fully automatic |
| 1 — Region loss | Entire primary region unreachable | Manual promotion of the cross-region async DR standby | Bounded by replication lag at the moment of loss, typically well under 60 seconds | < 30 minutes | Runbook-triggered, human-declared |
| 2 — Data corruption / backup-only recovery | Logical corruption, a bad migration, or a security event where replaying the primary's recent state would replicate the problem | `pgBackRest` full restore plus WAL replay to a chosen point in time, into a fresh instance | Bounded by `archive_timeout` (≈60s) up to the chosen recovery point | Hours, scaling with data volume | Runbook-triggered |

## Tier 1 runbook (region loss)

1. **Confirm the primary region is genuinely unreachable**, not merely experiencing elevated latency — the Incident Commander checks at least two independent signals (cloud provider status page and a direct connectivity probe from a third region) before declaring a DR event, specifically to avoid promoting a standby while the "failed" primary is still writable, which would produce two primaries (split-brain) the moment connectivity returns.
2. **Fence the old primary** wherever any residual connectivity might exist (revoke its security-group/network path to the application tier) before promotion, not after.
3. **Promote the DR standby**: `pg_ctl promote` (or, if Patroni already manages the cross-region node as a Patroni-aware standby cluster, `patronictl failover` targeting it) ends recovery mode and makes it a writable primary.
4. **Re-point traffic**: update the PgBouncer/HAProxy target (or the Route53 failover DNS record health-checked against the old primary) to the newly-promoted node; application pods pick up the change on their next connection attempt without a redeploy.
5. **Verify**: run the same restore-drill checksum script used in monthly backup testing against the newly-promoted node's current state before declaring the incident resolved, not just "the database accepted a connection."
6. **Re-establish redundancy**: provision a fresh in-region replica pair in the (now primary) region as soon as traffic is stable, so the cluster is not running on a single node for longer than necessary.

## Drills and the accepted data-loss window

QAYD runs a full Tier 1 DR drill quarterly against a non-production environment sized to resemble production's largest tenant, measuring actual RTO against the 30-minute target and logging the result — per **Database Philosophy**, an unrehearsed runbook is assumed broken until proven otherwise. The Tier 1 data-loss window (up to the async replication lag at the moment of regional loss) is a deliberate, documented trade-off: making the cross-region standby synchronous would add the cross-region network round-trip to every commit on the primary, which is an unacceptable latency cost for a system whose OLTP write path serves interactive accounting workflows, for a residual risk (simultaneous total regional loss plus a non-zero lag window at that exact moment) that Tier 0's in-region synchronous replication already makes rare.

# Read Replicas

The two in-region streaming replicas described in **Replication** serve a second purpose beyond availability: absorbing read traffic that would otherwise compete with OLTP writes for the primary's I/O and buffer cache. Reporting queries (trial balance, aged receivables, a year-long cash-flow chart), the AI layer's grounding queries (an agent reading five years of a customer's invoice history before drafting a collection email), and dashboard tiles are the workloads routed here.

## Laravel read/write connection split

```php
// config/database.php
'pgsql' => [
    'read' => [
        'host' => explode(',', env('DB_READ_HOSTS', 'pg-replica-a.internal,pg-replica-b.internal')),
    ],
    'write' => [
        'host' => [env('DB_WRITE_HOST', 'pg-primary.internal')],
    ],
    'sticky'   => true,   // reuse the write connection for reads later in the same request, after a write
    'driver'   => 'pgsql',
    'port'     => env('DB_PORT', 5432),
    'database' => env('DB_DATABASE', 'qayd'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'sslmode'  => 'verify-full',
],
```

Laravel selects a read host at random per query from the `read.host` list (spreading load across both replicas) for any query not explicitly forced onto the write connection, and `sticky => true` is what prevents the classic "I just saved my changes and the page that reloaded them doesn't show them yet" bug — once a request has performed a write, every subsequent read in that same request goes to the primary rather than a replica that may not have caught up yet.

## Replication lag as a routing input, not just a metric

A dashboard tile is not read-consistency-critical the way "did my save take effect" is; QAYD accepts a few seconds of replica staleness for reporting traffic as an explicit trade-off (**Database Philosophy**'s sixth principle). What is not accepted is silent, unbounded staleness — the same `pg_stat_replication`-derived lag metric that feeds **Monitoring**'s alerting is also checked by a lightweight middleware before routing a request's reads to a replica at all: if measured lag for both replicas exceeds a threshold (30 seconds), reporting queries for that request fall back to the primary rather than serving a report that is stale enough to be actively misleading to a CFO closing the books.

## The AI layer's read access is RLS-bound, not bypassed

The AI/FastAPI layer's database credentials are a dedicated role, `qayd_ai_readonly`, granted `CONNECT` and `SELECT` only, on the replicas only, with Row Level Security **enforced** — deliberately not `BYPASSRLS` the way the platform's background-job role is. This is the database-layer enforcement of the platform rule that AI "obeys the same permissions as the user; it never bypasses authorization": an AI agent's session sets `app.current_company_id` exactly as the human-facing API does before issuing any query, and a bug or a manipulated prompt that tries to get the AI layer to read across companies is stopped by the identical RLS policies described in **Row Level Security**, not by trusting the AI layer's own code to have scoped its query correctly.

```sql
CREATE ROLE qayd_ai_readonly WITH LOGIN PASSWORD :'ai_readonly_password';
GRANT CONNECT ON DATABASE qayd TO qayd_ai_readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO qayd_ai_readonly;
-- No BYPASSRLS. No INSERT/UPDATE/DELETE grant, anywhere, ever, for this role.
```

# High Availability

QAYD's availability target for the primary OLTP cluster is **99.95%** (a budget of roughly 4.4 hours of unplanned downtime per year), achieved through automatic leader election and failover rather than manual intervention for the common node-loss case (Tier 0 in **Disaster Recovery**'s tiering).

## Patroni and the distributed configuration store

**Patroni**, backed by a three-node **etcd** cluster as the distributed configuration store (DCS), manages leader election, health checking, and automatic failover across the primary and its two in-region replicas:

```yaml
# patroni.yml (excerpt) — every cluster node runs this
scope: qayd-pg-cluster
restapi:
  listen: 0.0.0.0:8008
etcd3:
  hosts: etcd-1.internal:2379,etcd-2.internal:2379,etcd-3.internal:2379
bootstrap:
  dcs:
    ttl: 30
    loop_wait: 10
    retry_timeout: 10
    maximum_lag_on_failover: 1048576   # 1 MB — a replica lagging beyond this is not failover-eligible
    synchronous_mode: true             # Patroni manages synchronous_standby_names dynamically
  postgresql:
    use_pg_rewind: true
postgresql:
  listen: 0.0.0.0:5432
  authentication:
    replication:
      username: replicator
      password: "{{ REPLICATOR_PASSWORD }}"
```

`synchronous_mode: true` hands control of `synchronous_standby_names` to Patroni itself, which is what lets the synchronous replica set adjust automatically as nodes join, leave, or fail — the static configuration shown in **Replication** is the steady-state result Patroni maintains, not a value an operator edits by hand during an incident.

## Failover sequence and timing budget

| Phase | Typical duration | What happens |
|---|---|---|
| Detection | ~10s | Patroni's DCS-based leader lock (TTL 30s, refreshed every `loop_wait`) expires without renewal from the failed primary |
| Election | ~5–10s | Remaining nodes agree, via the DCS, on the most-caught-up eligible replica |
| Promotion | ~2–5s | The elected replica runs `pg_ctl promote`, begins accepting writes |
| Client reconnect | < 5s | PgBouncer/HAProxy, health-checking Patroni's REST API `/primary` endpoint, redirects new connections; in-flight queries against the old primary fail fast and are retried by Laravel's `DB::transaction(..., attempts: N)` |

Total observed failover windows are typically in the 20–30 second range — well inside the availability budget for a single such event, though the SLA is a rolling annual figure, not a per-incident guarantee, and is tracked accordingly.

## Split-brain prevention

The DCS-based leader lock is what prevents two nodes from both believing themselves primary: a node can only accept writes while it continuously holds the lock, the lock has a bounded TTL, and losing contact with the DCS for longer than that TTL causes Patroni to demote its own local node defensively (a "fencing" behavior) rather than risk continuing to serve writes with stale leadership. Network-level fencing (revoking the old primary's route to the application tier during a Tier 1 regional event) is a manual runbook step precisely because a true regional partition can leave a node unable to reach the DCS to demote itself automatically, which is why **Disaster Recovery**'s Tier 1 runbook explicitly fences before promoting.

## Zero-downtime maintenance

Minor version upgrades and OS patching never touch the current primary first: replicas are patched and restarted one at a time (Patroni continues serving reads from the remaining healthy replica throughout), then `patronictl switchover` promotes a freshly-patched replica to primary in a controlled, sub-second-interruption handoff, and only then is the now-demoted former primary patched. This ordering means every maintenance window's only user-visible effect is the brief connection interruption of a planned switchover — an event indistinguishable, from the application's retry logic's point of view, from the automatic failover path already exercised and tested above.

# Encryption

Encryption in QAYD is layered deliberately rather than treated as a single control: transport encryption, storage encryption, and column-level encryption each defend against a different threat, and none is a substitute for the others — nor, critically, for Row Level Security, which remains the first line of defense simply because a query that RLS never allows to return a row never reaches the point where decrypting that row's contents would even matter.

## Encryption in transit

Every connection to every PostgreSQL node — application to primary, application to replica, replica to primary for streaming replication, and the analytics database's logical subscription — is `sslmode=verify-full`, the strictest libpq mode, which validates both that the certificate chains to a trusted CA and that the server's certificate hostname matches the host actually being connected to (preventing a network-level machine-in-the-middle from presenting *any* valid-looking certificate for a different host). `pg_hba.conf` on every node contains only `hostssl` entries; there are no `host` (plaintext-permitted) entries anywhere in QAYD's configuration, including for connections that originate from within the same VPC, because "the network is trusted" is exactly the assumption that turns a single compromised internal host into a full plaintext credential and data interception point.

```
# pg_hba.conf — no plaintext entries exist anywhere in this file
hostssl   all   all   10.20.0.0/16   scram-sha-256
hostssl   all   all   10.30.0.0/16   scram-sha-256
hostnossl all   all   0.0.0.0/0      reject
```

TLS certificates are issued by the platform's internal CA (or ACM for public-facing endpoints) with a 90-day validity and automated rotation via `cert-manager`; a certificate that fails to rotate raises a monitoring alert 14 days before expiry, well ahead of any risk of an expired-certificate outage.

## Encryption at rest

The underlying block storage for every cluster node (primary, replicas, and the DR standby) is encrypted using cloud-provider volume encryption backed by the platform's own KMS-managed keys, not the cloud provider's default keys, so that key access itself is auditable and revocable independently of the storage provider's own access controls. `pgBackRest` repositories carry a second, independent layer of encryption (`repo1-cipher-type=aes-256-cbc`) so that backup data is protected even in the (already unlikely, given R2's own encryption) scenario where the storage layer's encryption were somehow bypassed.

## Column-level encryption for the highest-sensitivity fields

A small, explicitly enumerated set of columns — `bank_accounts.account_number`, `employees.national_id`, and equivalents — carry an additional, application-independent layer: PostgreSQL's `pgcrypto` extension (already required per **Why PostgreSQL**) encrypts these specific values with a data key that is never stored in the database itself.

```sql
ALTER TABLE bank_accounts ADD COLUMN account_number_encrypted BYTEA;

CREATE OR REPLACE FUNCTION encrypt_bank_field(p_plaintext TEXT) RETURNS BYTEA AS $$
    SELECT pgp_sym_encrypt(p_plaintext, current_setting('app.bank_data_key', false));
$$ LANGUAGE sql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION decrypt_bank_field(p_ciphertext BYTEA) RETURNS TEXT AS $$
    SELECT pgp_sym_decrypt(p_ciphertext, current_setting('app.bank_data_key', false));
$$ LANGUAGE sql SECURITY DEFINER;

REVOKE EXECUTE ON FUNCTION decrypt_bank_field(BYTEA) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION decrypt_bank_field(BYTEA) TO qayd_app_role;
```

`app.bank_data_key` is set once per session by Laravel immediately after establishing tenant context, fetched at request time from the platform KMS rather than ever appearing in `postgresql.conf`, a migration, or version control. Because these columns are deliberately excluded from the logical replication publication described in **Replication**, the plaintext data key never needs to exist on the analytics database at all — the AI and reporting layer simply never receives the ciphertext to begin with, which is a stronger guarantee than trusting that layer to also enforce decryption restrictions correctly. Fields like `customers.tax_registration_number`, which must remain queryable and unique-constrained (see **Constraints**), are deliberately *not* column-encrypted — encryption and equality-searchability are in tension, and QAYD resolves that tension per-column by choosing which property a given field needs, rather than encrypting everything uniformly and then working around the resulting query limitations.

## Key rotation

The KMS-managed data key referenced by `app.bank_data_key` rotates annually. Rotation does not trigger a blocking mass re-encryption migration across every historical row; instead, a `key_version` tag travels alongside each ciphertext (embedded in a sibling column, `account_number_key_version SMALLINT`), and a lazy rotation strategy re-encrypts a row with the current key version the next time that specific row is written for an unrelated reason, with a background job sweeping any rows that have not naturally rotated within 18 months to bound how long an old key version must remain available for decryption.

# Performance

Performance in QAYD is a budget, not an aspiration: the OLTP write path (posting a journal entry, recording a payment) targets a p95 under 100ms end-to-end including the round trip through PgBouncer, and read endpoints backing interactive UI target a p95 under 50ms server-side. Every item below exists to protect that budget as data volume grows, not as generic "best practice" advice disconnected from a number.

## Connection pooling

PgBouncer in `transaction` pooling mode (configuration shown in **Multi Tenant Architecture**) is the single most consequential performance decision in the stack: without it, Laravel's per-request connection model against a Postgres instance whose `max_connections` is measured in the hundreds would exhaust available backends under any meaningful concurrent load, since each Postgres backend process is comparatively expensive (its own OS process, ~5–10MB of memory) versus a pooled logical connection.

## Query observability discipline

`pg_stat_statements` (loaded via `shared_preload_libraries`, required per **Why PostgreSQL**) is queried weekly for the top twenty statements by total execution time, not merely by per-call latency — a query that runs in 2ms but fires 50,000 times an hour often deserves attention before one that runs in 200ms but fires twice a day. Any new query in a `Service` class that touches a table over one million rows, or any partitioned table, must include an `EXPLAIN (ANALYZE, BUFFERS)` capture in the pull request description showing the chosen plan and buffer usage — this is a required review-checklist item, not a suggestion, precisely because a query that looks correct and reasonably fast against a development database seeded with a few hundred rows can be a sequential scan waiting to happen against a production partition with ten million.

```sql
SELECT query, calls, total_exec_time, mean_exec_time, rows
FROM pg_stat_statements
ORDER BY total_exec_time DESC
LIMIT 20;
```

## N+1 prevention

Eloquent's lazy-loading default makes an N+1 query pattern (loading a list of invoices, then triggering a separate query per invoice to fetch its customer) trivially easy to introduce by accident. QAYD's test suite runs with `spatie/laravel-query-detector`-equivalent tooling active in the testing environment, configured to fail any feature test whose request issues more queries than a per-route budget declared in that route's test — a controller must explicitly `with('customer', 'items')` eager-load its relations, and a regression that removes an eager-load call fails CI rather than silently degrading production latency.

## Materialized views for expensive aggregates

A trial balance — the sum of debits and credits per account per fiscal period, potentially across millions of `ledger_entries` rows — is exactly the kind of aggregate the **Database Philosophy** explicitly calls out as tolerant of a few seconds of staleness. QAYD serves it from a materialized view rather than a live aggregate query on every page load:

```sql
CREATE MATERIALIZED VIEW trial_balance_mv AS
SELECT company_id, account_id, fiscal_period_id,
       SUM(debit_amount)  AS total_debits,
       SUM(credit_amount) AS total_credits
FROM ledger_entries
GROUP BY company_id, account_id, fiscal_period_id;

CREATE UNIQUE INDEX trial_balance_mv_uidx
    ON trial_balance_mv (company_id, account_id, fiscal_period_id);

REFRESH MATERIALIZED VIEW CONCURRENTLY trial_balance_mv;
```

The unique index shown is a hard requirement for `CONCURRENTLY`, which is itself a hard requirement for QAYD's use case — a non-concurrent refresh holds an `ACCESS EXCLUSIVE` lock that would block every read against the view for the refresh's full duration, unacceptable for a view read constantly by dashboard tiles. The refresh runs both on a five-minute schedule and is triggered directly by the `JournalEntryPosted` domain event's listener for the specific `(company_id, fiscal_period_id)` pairs a posting affects, so routine dashboard staleness is bounded in the single-digit minutes and a user who just posted an entry and immediately checks their own trial balance is not left wondering why their own action has not yet appeared.

## Autovacuum tuning for high-churn tables

The default autovacuum thresholds (tuned for a generic OLTP table) are too conservative for tables like `stock_movements` that see continuous, high-frequency inserts and occasional updates — waiting for the default 20% dead-tuple threshold on a table already numbering in the tens of millions of rows means autovacuum runs far less often than the table's actual churn warrants:

```sql
ALTER TABLE stock_movements SET (
    autovacuum_vacuum_scale_factor  = 0.02,
    autovacuum_analyze_scale_factor = 0.01,
    autovacuum_vacuum_cost_delay    = 2
);
```

## Instance-level tuning

```conf
# postgresql.conf — primary, reference sizing for a 64GB-RAM instance
shared_buffers          = 16GB    # ~25% of RAM
effective_cache_size    = 48GB    # ~75% of RAM — informs the planner's cache-residency assumptions
maintenance_work_mem    = 2GB
work_mem                = 64MB    # sized against expected concurrent sort/hash operations under PgBouncer pooling
max_connections         = 400     # sized to PgBouncer's pooled backend count, not raw application concurrency
random_page_cost        = 1.1     # SSD-backed storage; default 4.0 assumes spinning disks
effective_io_concurrency = 200
```

`work_mem` in particular is deliberately conservative relative to what a single query could use, because it is a per-sort/per-hash-operation allowance that multiplies by the number of concurrent operations across all active backends — an overly generous `work_mem` is one of the most common causes of an out-of-memory event on a busy Postgres primary, and QAYD's default favors more temp-file spills (visible and tunable via `log_temp_files`, see **Monitoring**) over a memory exhaustion incident.

# Scalability

QAYD's current single-primary architecture is not the end state; it is the correct starting point for the company's actual current and near-term scale, with an explicit, named condition for when it stops being correct.

## Current ceiling

A single well-tuned primary on the reference 64GB-RAM instance class comfortably serves several thousand active companies and a sustained write rate in the low thousands of transactions per second before `synchronous_commit`-induced write latency or connection saturation becomes the binding constraint — comfortably ahead of QAYD's current SME-to-mid-market customer base, with headroom the roadmap tracks explicitly rather than discovering under load.

## Connection math

The practical concurrency limit is rarely Postgres's own `max_connections`; it is PgBouncer's pool sizing. With `pool_mode = transaction` and `default_pool_size = 50` against a single database, PgBouncer can serve far more than 50 concurrent client requests because a pooled backend connection is only checked out for the duration of a single transaction, not a whole request — the real capacity planning question QAYD tracks is PgBouncer's own connection-wait time (`SHOW POOLS` and its exported metrics), not the raw `max_connections` ceiling, which exists primarily as a safety backstop against a PgBouncer misconfiguration rather than the day-to-day operating constraint.

## Caching for read-heavy, slow-changing data

Redis (the platform's designated cache/queue layer) caches reference data that is read far more often than it changes — a company's chart-of-accounts tree, active price lists, the permission matrix for a role — under a `company:{id}:accounts:tree`-style key convention, invalidated explicitly by a listener on the relevant domain event (`account.created`, `account.updated`) rather than a bare TTL, because a stale chart of accounts is a correctness bug (an accountant posting against a renamed or deactivated account), not merely a minor staleness the way a dashboard tile's few seconds of lag is.

## The sharding trigger condition

QAYD evaluates Citus-based sharding (detailed in **Future Architecture**) when either of two named conditions is met: sustained primary write throughput reaching 70% of the measured ceiling above, or a single enterprise tenant's data volume alone (rather than the aggregate of all tenants) growing large enough to noticeably affect query planning or vacuum duration for other tenants sharing the same physical tables. Neither condition is close to being met today; naming them explicitly here is what prevents "we might need to shard eventually" from turning into either premature complexity today or an unplanned scramble later.

# Monitoring

A failure mode nobody is watching for is a failure mode that becomes an incident instead of a graph. QAYD's database monitoring stack is `postgres_exporter` feeding Prometheus, visualized in Grafana, with `pg_stat_statements` as the query-level layer and `pgbackrest_exporter` covering backup-specific health (archive lag, last successful backup age) that the generic exporter does not surface.

## Logging configuration

```conf
# postgresql.conf — logging
log_min_duration_statement = 200     # ms; log any statement slower than this
log_checkpoints = on
log_lock_waits = on
log_temp_files = 0                   # log every temp file spill — signals work_mem is too small for that query
track_io_timing = on
shared_preload_libraries = 'pg_stat_statements'
```

## SLO metrics and alert thresholds

| Metric | Source | Warning | Page |
|---|---|---|---|
| Replication lag (bytes / time) | `pg_stat_replication` | > 5s | > 30s |
| Cache hit ratio | `pg_stat_database` (`blks_hit` / (`blks_hit`+`blks_read`)) | < 99% | < 95% |
| Connection pool saturation | PgBouncer `SHOW POOLS` wait time | > 50ms | > 250ms |
| Deadlocks | `pg_stat_database.deadlocks` (rate) | > 0/hour sustained | > 5/hour |
| Lock wait time (p95) | `pg_locks` sampling | > 100ms | > 1s |
| Transaction ID (xid) age | `age(datfrozenxid)` | > 500M (of a 2.1B wraparound limit) | > 1.5B |
| WAL archiving lag | `pgbackrest_exporter` | > 2 min | > 10 min |
| Disk headroom | node/volume exporter | < 25% free | < 10% free |

The transaction ID age metric protects against a specific, easy-to-overlook failure: if autovacuum is ever unable to keep up with freezing old transaction IDs (a long-running transaction blocking it, for instance), a table can in the extreme approach transaction ID wraparound, at which point Postgres refuses new writes entirely to protect data integrity — an alert at 500 million gives operators weeks of lead time to diagnose the blocking cause long before the 1.5 billion page threshold, let alone the hard 2.1 billion limit.

## Dashboards and on-call

Three standing Grafana dashboards back the metrics above: a cluster-health dashboard (replication lag, connection saturation, cache hit ratio) reviewed continuously by the on-call rotation; a query-performance dashboard (`pg_stat_statements` top-N, slow query log volume) reviewed weekly; and a backup/DR-readiness dashboard (last successful full backup age, last restore-drill date, WAL archiving lag) reviewed at the same cadence as the monthly restore drill it reports on. Every page-severity alert links directly to a named runbook section (this document, or the module-specific doc it points to) rather than leaving an on-call engineer to reconstruct context at 3am.

# Future Architecture

The decisions in this document are the correct architecture for QAYD's current scale and its near-term roadmap, not a permanent commitment. This section names the specific evolutions already anticipated, the condition that would trigger each, and — critically — states that none of them are implemented today, so that no other module's document may assume one exists.

- **Citus-based sharding**, keyed on `company_id`, if the **Scalability** section's named write-throughput or single-tenant-volume conditions are met. This would shard the largest, highest-volume tables (`journal_lines`, `ledger_entries`, `stock_movements`) across multiple physical nodes while leaving smaller reference tables (`accounts`, `companies`, `users`) as Citus "reference tables" replicated to every shard — a migration path chosen specifically because it requires no application-level rewrite of the RLS-plus-`company_id`-scoping model already in place, only a change in physical data placement beneath it.
- **Debezium/Kafka-based CDC**, replacing the current `domain_events` outbox-plus-logical-replication relay for the analytics and AI pipeline specifically, once event volume or downstream consumer count grows past what a single logical replication subscription comfortably serves — the outbox table itself does not go away (it remains the source of truth and the guarantee against lost events described in **Event Driven Database**), only its relay mechanism gains a more horizontally scalable consumer-group model.
- **Schema-per-tenant as a paid, opt-in isolation tier**, referenced but not implemented in **Multi Tenant Architecture**, for enterprise or regulated customers requiring physical namespace separation — gated behind a `companies.isolation_tier` flag that, when set, would trigger a one-time migration of that tenant's rows into a dedicated schema rather than changing the default architecture for every tenant.
- **UUIDv7 for new tables**, once PostgreSQL-native (non-extension) generation support matures, per the evaluation already flagged in **UUID Strategy** — applied only to greenfield tables, never retrofitted onto an existing primary key.
- **Regional tenant pinning** rather than true multi-region active-active writes: a company domiciled in a jurisdiction with data-residency requirements would have its primary hosted in that region by policy, with cross-region replication serving DR only (as today), not concurrent active writes from multiple regions — a deliberate choice to avoid the write-latency and conflict-resolution costs of active-active replication (which CAP-theorem trade-offs make expensive to do correctly for a strongly-consistent ledger) in favor of a simpler per-tenant regional assignment.
- **Cold-partition data lake export** to Parquet/Iceberg tables on Cloudflare R2, extending the detach-and-archive mechanism already described in **Partitioning**, fully decoupling long-horizon historical analytics from the OLTP cluster's own storage and query capacity.
- **Crypto-shredding for right-to-erasure requests**: because hard deletes on financial tables are architecturally disallowed (**Soft Delete**) and an immutable ledger is a core guarantee (**Database Philosophy**), a future data-subject-erasure feature would encrypt specific PII fields (beyond the bank/national-ID fields already covered in **Encryption**) with a per-subject key from first write, such that "erasure" becomes destroying that one subject's key — rendering the field permanently unreadable — rather than deleting or rewriting the row itself, preserving every downstream ledger reference and audit trail while still satisfying a genuine right-to-erasure obligation.
- **pgvector scaling evaluation**: as AI-embedding volume on `ai_messages` and future semantic-search-indexed `attachments` grows, QAYD will evaluate HNSW index tuning within the existing cluster against migrating embedding storage to a dedicated vector database, specifically to ensure embedding-workload query patterns never compete with the ledger's own I/O and cache budget on the primary OLTP instance.

# End of Document

