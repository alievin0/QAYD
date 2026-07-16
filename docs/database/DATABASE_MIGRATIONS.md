# Database Migrations — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_MIGRATIONS
---

# Purpose

This document is the binding engineering specification for how schema and data change reaches the
QAYD PostgreSQL database in production, without downtime, without data loss, and without ever
putting the platform in a state where `SUM(debits) != SUM(credits)` for a posted journal entry.
QAYD is an AI Financial Operating System serving many tenant companies from one shared PostgreSQL
15+ cluster (Laravel 12 / PHP 8.4+ backend, Redis cache/queue, Cloudflare R2 object storage, AWS +
Cloudflare hosting). The accounting engine, sales, purchasing, banking, inventory, payroll, and tax
modules all read and write the same database under continuous, 24/7 traffic from browser sessions,
mobile clients, the FastAPI AI layer (via the Laravel API — the AI layer never touches the database
directly), webhooks, and Laravel Reverb real-time channels. There is no maintenance window in which
the platform can be safely taken offline: Kuwait, Saudi, UAE, and other Gulf tenants operate across
different working weeks and time zones, and financial postings (invoices, payroll runs, bank
reconciliations) happen continuously. Every schema change described in this document is therefore
designed to be applied to a live, writing, multi-tenant database with zero downtime as the default
requirement, not the exception.

A "migration" in this document means any versioned, code-reviewed, automatically-applied change to
the shape or content of the database: a Laravel migration class under
`database/migrations/`, a raw SQL script executed by the deployment pipeline, or a queued background
data-migration job that backfills or transforms rows outside of the request/response cycle. This
document does not cover ad-hoc `psql` sessions against production — those are prohibited outside of
a declared incident response, and even then must be captured as a migration afterward so the schema
history remains the single source of truth for what the database actually looks like.

The audience is every backend engineer, human or AI, who writes a Laravel migration, and every
reviewer who approves one. AI agents (the Reporting Agent, Compliance Agent, and any other agent
with `schema.propose` permission) may draft migration files, but no AI-authored migration runs
against a shared environment — local sandbox, staging, or production — without a human engineer
approving the pull request. This is a hard rule, not a suggestion: schema changes are the one class
of change in QAYD that can silently corrupt financial history for every tenant on the platform
simultaneously, and they are consequently held to a higher bar than ordinary application code.

This document assumes the platform facts fixed in the shared design context: every business table
carries `company_id BIGINT NOT NULL` (indexed) for tenant isolation, `branch_id` for branch
scoping, `created_by`/`updated_by`/`created_at`/`updated_at`/`deleted_at` as standard columns,
`NUMERIC(19,4)` for money, `NUMERIC(18,6)` for exchange rates, `NUMERIC(18,4)` for quantities, and
soft deletes on every financial row (posted accounting rows are corrected via reversing entries,
never edited or hard-deleted). It also assumes the canonical table set: `accounts`,
`journal_entries`, `journal_lines`, `ledger_entries`, `customers`, `vendors`, `invoices`, `bills`,
`bank_accounts`, `inventory_items`, `stock_movements`, `payroll_runs`, `tax_transactions`,
`audit_logs`, and the rest of the tables named in the shared design context and in
`DATABASE_STANDARDS.md`. Everything below builds on those facts; it does not restate or contradict
them.

# Migration Philosophy

QAYD treats the database schema the same way it treats a posted journal entry: as an append-only,
versioned history that is never rewritten, only extended. Every change to the schema is a new,
forward-only migration file, checked into version control alongside the application code it
supports, reviewed with the same rigor as a change to `JournalEntryService`, and applied through the
same CI/CD pipeline that deploys the application. There is exactly one authoritative description of
what the production schema looks like: the ordered sequence of migration files that have run. Nobody
edits a past migration file after it has been merged to `main` and applied to any shared environment
(local developer sandboxes are the only exception, and only before the first shared deploy). If a
past migration turns out to be wrong, the fix is a new migration that corrects it — exactly as a
wrong journal entry is fixed by a reversing entry, not by editing history.

Four principles govern every migration written against QAYD:

**1. Expand before contract.** A change that could break a currently-running version of the
application — dropping a column, renaming a column, tightening a constraint, changing a column's
type — is never done in one step. It is split into an *expand* phase (add the new thing alongside
the old thing, dual-write or dual-read as needed) and a *contract* phase (remove the old thing) that
ships only after every application instance and every in-flight deploy is running code that no
longer needs the old thing. See "Zero Downtime Deployments" for the full pattern; it is the spine of
this entire document.

**2. Migrations are code, and code is reviewed.** A migration file is a pull request like any other:
it is diffed, reviewed by at least one other engineer, and — for any migration touching a table over
10 million rows, adding a `NOT NULL` constraint, adding a foreign key, or touching `journal_entries`,
`journal_lines`, `ledger_entries`, `accounts`, or any payroll/tax table — reviewed by a second,
senior engineer with explicit sign-off in the PR description. The review checklist in "Production
Safety" is mandatory, not advisory.

**3. Schema changes and data changes are separate migrations.** A migration that adds a column and a
migration that backfills that column for 50 million existing rows are two different files, deployed
as two different steps, because they have different risk profiles, different lock behavior, and
different rollback stories. Conflating them is the single most common cause of production incidents
in this class of system — see "Data Migrations" and "Background Migrations."

**4. Every migration is idempotent and safe to re-run.** Deployment pipelines retry. Laravel's
migration runner records what has run in the `migrations` table and will not re-run a completed
migration, but a migration that is interrupted mid-execution (deploy timeout, connection drop, pod
eviction) must leave the database in a state where re-running the same migration — or running the
"up" logic a second time by hand — does not error or double-apply. `IF NOT EXISTS`, `IF EXISTS`,
`ON CONFLICT DO NOTHING`, and explicit existence checks before DDL are used throughout this document
for exactly this reason.

The philosophy in one sentence: **a migration is a small, reviewed, forward-only, idempotent unit of
change that never assumes it has an exclusive lock on the database's attention, because in
production it never does.**

# Migration Lifecycle

Every migration in QAYD moves through the same seven stages, regardless of whether it originates from
a human engineer or an AI-drafted proposal that a human has approved.

```
 [1] DESIGN           [2] AUTHOR            [3] REVIEW
  ideate the         write migration       PR opened, CI runs
  expand/contract  →  file(s) + tests   →   migration against a     ┐
  plan; identify       locally against       throwaway DB snapshot; │
  lock/perf risk        a seeded local db     lint + dry-run EXPLAIN│
                                                                     │
                                                                     ▼
 [7] CONTRACT   ←   [6] MONITOR    ←    [5] APPLY (PROD)   ←   [4] APPLY (STAGING)
  follow-up PR to     dashboards +        deploy pipeline runs      identical pipeline
  remove old column/   alerts watched      migration step against   step runs against
  code path, once      for lock wait,      production during        staging; smoke
  100% of traffic is   replication lag,    deploy window; app       tests + a soak
  on the new shape      error rate for      version deploys after   period before
                        N minutes            migration succeeds      promoting to prod
```

**1. Design.** Before a line of migration code is written, the engineer states in the PR description
(or an accompanying design note for large changes): what is changing, why, which tables/columns are
touched, the estimated row count and current table size (`SELECT pg_size_pretty(pg_total_relation_size('journal_lines'))`),
whether the change needs expand/contract, and the rollback plan. For any change to a table
exceeding 5 million rows, this design step is mandatory and reviewed before code is written, not
after.

**2. Author.** The migration is written as one or more Laravel migration classes (see "Forward
Migrations"), each with a working `down()` method (see "Rollback"), plus any accompanying background
job for data backfill (see "Data Migrations" / "Background Migrations"), plus a Pest test that
asserts the migration produces the expected schema and, for data migrations, the expected data shape
on a representative fixture (see "Testing"). The author runs the migration locally against a
database seeded with a production-realistic fixture set, not an empty database — an empty database
never surfaces lock contention, default-value backfill cost, or constraint-violation edge cases.

**3. Review.** CI runs the migration against a fresh, throwaway Postgres instance seeded from the
current staging schema snapshot (see "CI/CD Integration"). The review checklist from "Production
Safety" is attached to the PR as a template; the reviewer confirms each item explicitly rather than
rubber-stamping. Any migration that acquires `ACCESS EXCLUSIVE` on a table larger than 1 million rows,
or that is missing a `lock_timeout`/`statement_timeout`, is blocked from merge by an automated CI
check (see "CI/CD Integration") in addition to human review.

**4. Apply to staging.** The same deploy pipeline that will run against production runs first
against staging, which is a schema-identical, data-realistic (anonymized production snapshot,
refreshed weekly) environment. The migration runs, the application deploys on top of it, and an
automated smoke-test suite plus a minimum 30-minute soak period (for any migration flagged high-risk
in review) must pass before the same change is eligible to promote to production.

**5. Apply to production.** The pipeline applies the migration as a distinct deploy step that
completes and is verified *before* the new application code that depends on it is rolled out (see
"Zero Downtime Deployments" for why this ordering is non-negotiable). Migrations run with a bounded
`lock_timeout` and `statement_timeout` (see "Production Safety") so that if the migration cannot
acquire its lock quickly or runs long, it fails loudly and rolls back cleanly rather than blocking
production traffic indefinitely.

**6. Monitor.** For a bounded watch window after apply — 15 minutes for a routine migration, up to 24
hours for a high-risk one — the on-call engineer watches the dashboards named in "Monitoring": lock
wait time, replication lag, error rate, and query latency on the affected tables. Any anomaly
triggers the rollback strategy in "Rollback Strategy" before it triggers a "let's see if it settles"
wait-and-hope.

**7. Contract.** For expand/contract changes, a follow-up migration (and follow-up PR) removes the
now-unused old column, index, or constraint once every application instance has deployed the new
code path and the "expand" artifact is confirmed unused (verified via `pg_stat_user_tables` access
counts or an application-level usage metric, never assumed). The contract step is tracked as an
explicit, scheduled follow-up item — it is the step most commonly forgotten, and QAYD's engineering
process treats an un-contracted expand as unfinished work, not acceptable technical debt, because it
leaves two sources of truth for the same fact.

# Versioning

QAYD uses Laravel's native migration versioning: every migration file is named with a
`YYYY_MM_DD_HHMMSS_description.php` timestamp prefix, generated by `php artisan make:migration`, and
Laravel's `migrations` table (itself a QAYD-managed table, tracked like any other) records which
files have run, in filename order, per environment. This gives QAYD three properties for free that a
hand-rolled numbering scheme would have to reinvent: (1) migrations from parallel feature branches
merge without filename collisions, because the timestamp is assigned at file-creation time and two
engineers working the same day get different seconds; (2) the apply order is unambiguous and
identical across every environment, because it is a lexical sort on the filename; (3) `php artisan
migrate:status` gives an authoritative, environment-specific view of "ran" vs. "pending" without any
custom tooling.

```
database/migrations/
├── 2026_01_10_090000_create_companies_table.php
├── 2026_01_10_090100_create_branches_table.php
├── 2026_02_03_141500_create_accounts_table.php
├── 2026_02_03_141600_create_account_types_table.php
├── 2026_03_18_101230_create_journal_entries_table.php
├── 2026_03_18_101245_create_journal_lines_table.php
├── 2026_06_02_083000_add_cost_center_id_to_journal_lines.php        (expand)
├── 2026_06_02_083015_backfill_cost_center_id_on_journal_lines.php    (data migration, queued)
├── 2026_06_16_090000_add_not_null_cost_center_fk_journal_lines.php   (contract: validate + enforce)
└── 2026_07_01_120000_drop_legacy_cost_center_code_column.php         (contract: remove old column)
```

On top of Laravel's file-based versioning, QAYD adds a lightweight **schema version tag**: every
migration that changes a table structure (not a pure data migration) must update a
`schema_migrations_log` table via its `up()`/`down()` methods, recording the migration class name,
a human description, the git commit SHA (injected by CI as an environment variable at migration
time), and a `risk_level` enum (`low`, `medium`, `high`) matching the review classification from
"Production Safety." This is intentionally *not* a replacement for Laravel's `migrations` table — it
is an audit trail that answers "what changed, when, at what risk, and who approved it" without
needing to reconstruct that from git history and Slack threads during an incident review or an
external audit (QAYD is an accounting platform; auditors ask this question routinely).

```sql
CREATE TABLE schema_migrations_log (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    migration_name   TEXT NOT NULL,
    description      TEXT NOT NULL,
    git_commit_sha    TEXT NOT NULL,
    risk_level       TEXT NOT NULL CHECK (risk_level IN ('low','medium','high')),
    applied_by       TEXT NOT NULL,          -- CI actor / deploy pipeline identity
    applied_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    duration_ms      INTEGER,
    rolled_back      BOOLEAN NOT NULL DEFAULT FALSE,
    rolled_back_at   TIMESTAMPTZ
);
CREATE INDEX schema_migrations_log_applied_at_idx ON schema_migrations_log (applied_at DESC);
```

Semantic versioning (`major.minor.patch`) is deliberately **not** used for the schema itself — a
schema does not have a single version number that a client negotiates, the way an API does. QAYD's
public and internal REST API is versioned under `/api/v1/`, and that API version is what client
compatibility is actually pinned to; the database schema can and does change under a stable API
version, because the Repository layer is the only thing that translates between API contracts and
table shape. A schema change is a breaking change to *the database*, not necessarily to `/api/v1/` —
which is precisely why expand/contract exists: to let the schema evolve without forcing an API
version bump on every column rename.

# Forward Migrations

A forward ("up") migration is a Laravel migration class whose `up()` method contains the DDL or, for
data migrations, the dispatch of a queued backfill job. QAYD migrations follow a strict template:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the (nullable) cost_center_id column to journal_lines.
     * Part 1 of an expand/contract sequence — see backfill + NOT NULL
     * migrations that follow this one for the full change.
     */
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')
                ->nullable()
                ->after('project_id');
            // No FK constraint yet — added NOT VALID in a later migration,
            // see "Constraints".
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn('cost_center_id');
        });
    }
};
```

Every forward migration in QAYD obeys these rules, enforced by the CI migration linter described in
"CI/CD Integration":

- **One structural intent per file.** A single migration file adds one column, or one index, or one
  constraint, or creates one table (plus its immediate indexes) — never "add three columns and drop
  two others and add an index," because that conflates independent risk profiles and independent
  rollback stories into one unit that cannot be partially reverted.
- **Explicit `Blueprint` types, never `$table->text()` for money or dates.** Money is always
  `$table->decimal('amount', 19, 4)`, rates are `$table->decimal('exchange_rate', 18, 6)`, quantities
  are `$table->decimal('quantity', 18, 4)`, timestamps are `$table->timestampTz(...)` (QAYD explicitly
  uses `timestampTz`, not `timestamp`, everywhere — see `DATABASE_STANDARDS.md`).
- **New tables always include the standard columns** (`company_id`, `branch_id`, `created_by`,
  `updated_by`, `created_at`, `updated_at`, `deleted_at`) via a shared trait/macro
  (`Blueprint::tenantColumns()`, a QAYD-specific macro registered in a service provider) so that no
  new table can be created without them — the macro is the enforcement mechanism, not a code-review
  reminder:

```php
// app/Providers/AppServiceProvider.php (excerpt)
Blueprint::macro('tenantColumns', function () {
    /** @var Blueprint $this */
    $this->id();
    $this->foreignId('company_id')->references('id')->on('companies');
    $this->foreignId('branch_id')->nullable()->references('id')->on('branches');
    $this->foreignId('created_by')->nullable()->references('id')->on('users');
    $this->foreignId('updated_by')->nullable()->references('id')->on('users');
    $this->timestampTz('created_at')->useCurrent();
    $this->timestampTz('updated_at')->useCurrent();
    $this->timestampTz('deleted_at')->nullable();
});
```

```php
Schema::create('cost_centers', function (Blueprint $table) {
    $table->tenantColumns();
    $table->string('code', 32);
    $table->string('name_en');
    $table->string('name_ar');
    $table->foreignId('parent_id')->nullable()->references('id')->on('cost_centers');
    $table->string('status', 16)->default('active');
    $table->jsonb('custom_fields')->nullable();
    $table->jsonb('tags')->nullable();

    $table->index(['company_id', 'status']);
    $table->unique(['company_id', 'code'], 'cost_centers_company_code_unique');
});
```

- **Raw SQL is used, deliberately, whenever Laravel's Schema builder cannot express the safe form of
  an operation** — most importantly, `CREATE INDEX CONCURRENTLY` and `ALTER TABLE ... ADD CONSTRAINT
  ... NOT VALID`, neither of which Laravel's fluent builder can emit correctly for QAYD's needs.
  QAYD's convention is `DB::statement(<<<SQL ... SQL);` with the raw SQL formatted and commented as
  carefully as any other code:

```php
public function up(): void
{
    DB::statement(<<<'SQL'
        CREATE INDEX CONCURRENTLY IF NOT EXISTS journal_lines_cost_center_id_idx
            ON journal_lines (cost_center_id)
            WHERE deleted_at IS NULL
    SQL);
}
```

  `CREATE INDEX CONCURRENTLY` cannot run inside a transaction block, and Laravel wraps each migration
  in a transaction by default for most drivers. QAYD's `pgsql` migrations disable that wrapping
  per-migration via the `$withinTransaction = false` property (Laravel 12 exposes this on the
  migration class) whenever a `CONCURRENTLY` statement is used — forgetting this is the single most
  common review-flagged mistake in QAYD migration PRs, and the CI linter (see "CI/CD Integration")
  greps for `CONCURRENTLY` without a matching `$withinTransaction = false` and fails the build if it
  finds one.

```php
return new class extends Migration
{
    public bool $withinTransaction = false; // required: CONCURRENTLY cannot run in a transaction

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS invoices_company_invoice_number_unique
                ON invoices (company_id, invoice_number)
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS invoices_company_invoice_number_unique');
    }
};
```

# Rollback

Every migration in QAYD ships a `down()` method that is tested, not decorative. "Tested" means the
migration's Pest test suite includes a case that runs `up()`, asserts the expected state, runs
`down()`, and asserts the schema is back to its pre-migration shape — this is mechanical to write and
catches the extremely common bug of a `down()` that references a column name that was itself renamed
in `up()`, or that assumes an index name Laravel auto-generated differently than expected.

Not every migration is safely reversible, and QAYD is explicit about the three categories rather than
pretending all migrations roll back cleanly:

**Category 1 — Fully reversible.** Adding a nullable column, adding an index, creating a new table,
adding a `NOT VALID` constraint (before it is validated). The `down()` simply undoes the `up()`:

```php
public function down(): void
{
    Schema::table('bills', function (Blueprint $table) {
        $table->dropColumn('vendor_reference_number');
    });
}
```

**Category 2 — Reversible with data loss risk.** Dropping a column, dropping a table, narrowing a
column's type (`VARCHAR(255)` to `VARCHAR(50)`), changing a default value. The `down()` can restore
the *structure* but cannot restore *data* that was dropped in `up()` — QAYD's rule is that any
migration in this category must be preceded, in a separate deploy, by confirmation that the column/
table is genuinely unused (see "Migration Lifecycle," Contract stage) and, for anything touching
financial tables, by an export of the data being dropped to cold storage (a dated Parquet/CSV snapshot
in Cloudflare R2) before the drop runs, referenced by path in the migration's PR description. The
`down()` recreates the column but leaves it empty/null — this is documented in the migration's PHPDoc
so nobody mistakes a structural rollback for a data restore:

```php
/**
 * Drops the legacy `cost_center_code` text column from journal_lines, now fully
 * superseded by `cost_center_id` (see 2026_06_02 expand migration).
 *
 * ROLLBACK NOTE: down() recreates the column as NULL for all rows. It does NOT
 * restore prior values. A full pre-drop export lives at:
 * r2://qayd-cold-storage/migrations/2026_07_01_journal_lines_cost_center_code_backup.parquet
 */
public function up(): void
{
    Schema::table('journal_lines', function (Blueprint $table) {
        $table->dropColumn('cost_center_code');
    });
}

public function down(): void
{
    Schema::table('journal_lines', function (Blueprint $table) {
        $table->string('cost_center_code', 32)->nullable();
    });
}
```

**Category 3 — Not reversible in place; rollback is forward.** A data migration that has already
transformed millions of rows (e.g., recomputing every `inventory_valuations` row under a new costing
method) cannot be "undone" by a `down()` that re-runs the old formula in reverse, because the old
inputs to that formula may no longer exist in recoverable form once time has passed and downstream
postings have been made against the new values. For this category, QAYD does not write a `down()`
that lies by pretending to restore the previous state. Instead, the migration's PR explicitly states
"rollback strategy: forward-fix only" and names the compensating migration pattern that would be used
if the change needs reversing — typically a new migration that re-applies the previous formula as a
new forward change, going through the same review process, not a rollback in the traditional sense.
This is identical in spirit to how a posted journal entry is never edited — it is reversed by another
entry. See "Rollback Strategy" for how this plays out at the deployment level, not just the migration
level.

QAYD never runs `php artisan migrate:rollback` against production as a first response to an incident.
The default incident response is: stop the deploy pipeline, assess via the dashboards in
"Monitoring," and choose between (a) rolling the *application* back to the previous release while
leaving the expand-phase schema change in place (safe, because expand-phase changes are additive and
backward-compatible by construction), or (b) running a specific, reviewed `down()` migration if the
schema change itself — not the application code — is the source of the incident. Rolling back schema
under load is treated as riskier than the forward change was, and is never done reflexively.

# Zero Downtime Deployments

Zero-downtime schema evolution is the organizing constraint of this entire document, and it rests on
one pattern applied consistently: **expand, migrate, contract**. The core insight is that at any
point during a rolling deploy, two versions of the application code — old and new — are running
simultaneously against the same database, for anywhere from a few seconds to several minutes
(canary/staged rollouts intentionally extend this window). Every schema change must be a state that
*both* the old and the new application code can operate against correctly, for the entire duration
that both are live. A change that only the new code understands, applied before the new code is
fully rolled out, breaks the old code's requests; a change the old code no longer understands, if the
old code is still receiving traffic during a rollback, breaks the rollback path too.

**Expand phase.** Add the new schema element without touching or depending on the old one. The old
code path continues to work unmodified because nothing it relies on changed.

- Adding a nullable column, or a column with a `DEFAULT` that Postgres can apply as a fast metadata-
  only operation (see "Schema Changes" for exactly which defaults qualify).
- Adding a new table.
- Adding an index `CONCURRENTLY` (never blocks writers; see "Indexes").
- Adding a constraint as `NOT VALID` (enforced for new/updated rows immediately, not yet checked
  against existing rows; see "Constraints").
- Adding a new nullable foreign key column, deploying application code that dual-writes it alongside
  the old reference.

**Migrate phase.** Backfill existing data into the new shape (see "Data Migrations" /
"Background Migrations"), and deploy application code that reads from the new column/table while
still writing to both old and new (dual-write) until the backfill is confirmed complete and the read
path has been fully cut over and verified.

**Contract phase.** Once 100% of application instances are running code that no longer reads or
writes the old shape — confirmed, not assumed, via deploy completion tracking and a metric on old-
path usage — remove the old column/table/index/constraint in its own migration and PR.

Worked example: renaming `journal_lines.memo` to `journal_lines.description` without downtime.

```
Step 1 (expand)   — add `description`, nullable, no default.
Step 2 (migrate)  — deploy app v2: writes to BOTH memo and description on every write;
                     reads from `description`, falling back to `memo` if description is null.
Step 3 (backfill) — queued job copies memo -> description for all existing rows in batches.
Step 4 (verify)   — confirm 0 rows where description IS NULL AND memo IS NOT NULL.
Step 5 (migrate)  — deploy app v3: stops writing to `memo`, reads only from `description`.
Step 6 (contract) — migration drops the `memo` column.
```

```php
// Step 1
Schema::table('journal_lines', function (Blueprint $table) {
    $table->text('description')->nullable()->after('memo');
});

// Step 6, weeks later, after v3 has been at 100% for a full deploy cycle
Schema::table('journal_lines', function (Blueprint $table) {
    $table->dropColumn('memo');
});
```

Never rename a live column directly with `ALTER TABLE ... RENAME COLUMN` in a single migration in
QAYD, even though PostgreSQL executes it as a fast, non-blocking metadata change — the reason has
nothing to do with lock cost and everything to do with the *old application code still running
during rollout referencing the old name*. A `RENAME COLUMN` breaks every in-flight old-version pod
instantly and atomically, with no expand window at all. The expand/contract rename above costs more
calendar time but never has a moment where both app versions cannot function.

The two PostgreSQL primitives that make expand-phase changes non-blocking for concurrent writers are
covered in depth in their own sections, but stated here because they are the mechanical foundation of
this entire pattern:

- **`CREATE INDEX CONCURRENTLY`** builds an index without holding a lock that blocks concurrent
  `INSERT`/`UPDATE`/`DELETE` on the table (it still blocks other DDL, and takes roughly 2-3x longer
  than a normal `CREATE INDEX`, and — critically — does not run inside a transaction block). See
  "Indexes."
- **`ALTER TABLE ... ADD CONSTRAINT ... NOT VALID`** adds a `CHECK` or foreign-key constraint that is
  enforced immediately for all new writes but is *not* retroactively checked against existing rows at
  add-time — that full-table check is deferred to a separate `ALTER TABLE ... VALIDATE CONSTRAINT`
  statement, which takes only a `SHARE UPDATE EXCLUSIVE` lock (compatible with reads and writes,
  incompatible only with other schema changes) rather than the `ACCESS EXCLUSIVE` lock that a
  same-transaction `ADD CONSTRAINT ... CHECK (...)` would hold for the full duration of the
  validation scan. See "Constraints."

A statement that acquires `ACCESS EXCLUSIVE` — a plain `ADD COLUMN ... NOT NULL` with a
volatile/non-constant default on older Postgres semantics, a column type change, a plain `ADD
CONSTRAINT` without `NOT VALID`, `ALTER TABLE ... SET NOT NULL` without a preceding `NOT VALID` CHECK
— blocks every reader and writer of that table for the full duration of the statement. On a table
like `journal_lines` with hundreds of millions of rows, that duration is not "a few seconds" — it can
be minutes, during which every invoice post, every payroll calculation, every dashboard load that
touches the ledger queues behind the lock and then, in the worst case, times out and errors for the
end user. QAYD's rule: **no migration is allowed to hold `ACCESS EXCLUSIVE` on a table with more than
100,000 live rows for longer than the `lock_timeout` configured for migrations (2 seconds — see
"Production Safety")** — if a migration would violate this, it is not written that way; it is
decomposed into the expand/contract steps above.

# Schema Changes

This section catalogs the common schema-change shapes QAYD's migrations perform and the safe pattern
for each, in PostgreSQL 15+ under concurrent production load.

**Add a nullable column.** Always safe, always a fast metadata-only change regardless of table size,
because PostgreSQL does not rewrite existing rows to add a nullable column with no default (or with a
constant default, since Postgres 11 — see below).

```php
Schema::table('vendors', function (Blueprint $table) {
    $table->string('preferred_payment_method', 32)->nullable();
});
```

**Add a column with a default value.** Since PostgreSQL 11, adding a column with a *constant*
default (`DEFAULT 'active'`, `DEFAULT 0`, `DEFAULT now()`... note: `now()` is *not* constant per
call but Postgres treats a column default's evaluation specially — see caveat below) is a fast
metadata-only operation: Postgres stores the default in the catalog and returns it for existing rows
without rewriting the table. This does **not** apply to a default that is a *volatile* expression
evaluated per-row (e.g., `gen_random_uuid()`, or any function without the `IMMUTABLE`/`STABLE`
marker) — those still force a full table rewrite under `ACCESS EXCLUSIVE`. QAYD's rule: a `DEFAULT`
on `ADD COLUMN` is allowed directly in a migration only if the default is a simple literal or a
marked-`IMMUTABLE` expression; anything else is added nullable-with-no-default and backfilled via a
background migration (see "Data Migrations"), then a `SET DEFAULT` is applied afterward if needed for
future rows.

```php
Schema::table('sales_orders', function (Blueprint $table) {
    $table->string('status', 16)->default('draft'); // literal default: fast, metadata-only
});
```

**Add a `NOT NULL` column.** Never done directly on a table with existing rows — a bare `ADD COLUMN
x NOT NULL` with no default fails outright against existing rows (they have no value for `x`), and
even with a default it forces every existing row to be considered against the constraint under an
`ACCESS EXCLUSIVE` lock in older semantics. QAYD's pattern is always: (1) add nullable, (2) backfill,
(3) add a `NOT VALID` CHECK constraint enforcing non-null, (4) `VALIDATE CONSTRAINT`, (5) once
validated, `SET NOT NULL` (which, as of Postgres 12+, can use an existing validated CHECK constraint
to skip its own full-table scan — see "Constraints" for the exact sequence).

**Rename a column or table.** Never a single-step `RENAME`; always expand/contract as shown in "Zero
Downtime Deployments." For a table rename specifically, QAYD additionally creates a compatibility VIEW
under the old name during the migrate phase if any reporting or ad-hoc tooling might still reference
it directly, dropped in the contract phase.

**Change a column's type.** The riskiest common schema change. `ALTER TABLE ... ALTER COLUMN ... TYPE
...` rewrites the entire table under `ACCESS EXCLUSIVE` unless the change is provably a no-op cast
(e.g., `VARCHAR(50)` to `VARCHAR(100)` — widening a `varchar` length, or `VARCHAR` to `TEXT`, are
metadata-only in modern Postgres because they cannot invalidate existing data). A genuine type change
(e.g., `INTEGER` to `BIGINT` for an `id` column expected to exceed 2^31, or `TEXT` to an `ENUM`) is
done via expand/contract: add a new column of the target type, dual-write, backfill, cut over reads,
drop the old column, and — if the name must stay the same — rename the new column into the old name
only after the old column has been fully dropped and the table is otherwise quiescent for that name
collision, in a single fast metadata-only `RENAME COLUMN` step that, at that point, is safe because
only one app version's worth of code exists that references either name (the drop of the truly-old
column already happened in a prior deploy).

```php
// Expand: journal_lines.id is BIGINT already (QAYD always starts BIGINT identity — see
// DATABASE_STANDARDS.md), but this pattern generalizes to any provider_reference_id growing
// past its original INTEGER bound on an older/acquired-company subsystem table:
Schema::table('legacy_pos_transactions', function (Blueprint $table) {
    $table->bigInteger('pos_transaction_id_v2')->nullable();
});
// Migrate: dual-write in app code, backfill via background job (see Data Migrations),
// cut reads over, then in a later migration:
Schema::table('legacy_pos_transactions', function (Blueprint $table) {
    $table->dropColumn('pos_transaction_id');
});
Schema::table('legacy_pos_transactions', function (Blueprint $table) {
    $table->renameColumn('pos_transaction_id_v2', 'pos_transaction_id');
});
```

**Widen or add a `JSONB` column.** Always safe and additive; QAYD's `custom_fields`/`tags` pattern
means schema flexibility for master-data attributes rarely needs a true column-level migration at
all — see "Normalization" in `DATABASE_STANDARDS.md` for when a JSONB key must be promoted to a real
column, which then follows the "add column" pattern above plus a background migration to project the
JSONB value out.

**Drop a column or table.** Contract-phase only, after confirming zero reads (see "Migration
Lifecycle"), always preceded by a cold-storage export for anything under the financial or
audit-relevant table set, per "Rollback" Category 2.

# Data Migrations

A data migration changes the *content* of existing rows, not the shape of the table — backfilling a
new column, recomputing a derived value under a new formula, correcting historical data after a bug
fix ships. QAYD treats data migrations as strictly separate PRs and deploy steps from the schema
migration that adds the column they populate, for three concrete reasons: a schema change is fast and
metadata-only (per "Schema Changes"), while a data migration touching millions of rows takes minutes
to hours and must never hold a long transaction or a table-wide lock; a schema change is reversible in
the traditional sense, while a data migration on a large table often is not (per "Rollback" Category
3); and separating them means a slow-running backfill failing halfway never blocks the (already-
applied, fast) schema change from having succeeded — the two failure domains are decoupled.

**Never do this** — a single UPDATE across a large table inside a migration, run synchronously as
part of `php artisan migrate` during a deploy:

```php
// ANTI-PATTERN — do not do this against any table over ~10k rows.
public function up(): void
{
    DB::table('journal_lines')->update(['cost_center_id' => DB::raw(
        '(SELECT cost_center_id FROM projects WHERE projects.id = journal_lines.project_id)'
    )]);
}
```

This holds row-level locks on every touched row for the duration of the statement, runs inside the
migration's transaction (if not disabled) so the WAL for the entire operation is held until commit,
blocks vacuum, risks lock escalation against concurrent writers touching the same rows, and — if the
deploy times out or the connection drops at row 40 million of 60 million — leaves the migration state
ambiguous (Laravel will not know it "half-ran"; it will either be marked as run, having partially
committed if `$withinTransaction = false`, or fully rolled back, having wasted the time). It also, on
a table the size of `journal_lines`, simply will not finish inside a deploy pipeline's timeout.

**Always do this instead** — batched, resumable, rate-limited updates, run as a background job
outside the deploy's critical path, dispatched *by* a lightweight migration but *executed* by the
queue worker fleet (see "Background Migrations" for the job implementation pattern):

```php
// database/migrations/2026_06_02_083015_backfill_cost_center_id_on_journal_lines.php
use Illuminate\Database\Migrations\Migration;
use App\Jobs\Migrations\BackfillJournalLinesCostCenterId;

return new class extends Migration
{
    public function up(): void
    {
        // Dispatch only — the migration itself completes instantly. The actual
        // backfill runs asynchronously via the queue and is monitored separately
        // (see Monitoring). This migration's "done" state means "backfill started,"
        // not "backfill complete" — completion is tracked via the
        // migration_backfill_progress table, not via php artisan migrate:status.
        BackfillJournalLinesCostCenterId::dispatch();
    }

    public function down(): void
    {
        // Data migrations do not reverse data; see Rollback, Category 3.
        // No-op: the schema-level column removal (if any) is a separate migration.
    }
};
```

Every batched backfill in QAYD follows this shape: select a bounded batch by primary key range (never
`OFFSET`-based pagination on a large, actively-written table — `OFFSET` re-scans and skips/duplicates
rows under concurrent writes as the table changes underneath it), update that batch in its own short
transaction, sleep briefly to let replication and vacuum keep up and to bound the impact on
concurrent query latency, and record progress so the job is resumable from exactly where it stopped
if interrupted:

```sql
-- One batch of a keyset-paginated backfill. Application code loops this with the
-- previous batch's max id as the next batch's lower bound, until no rows remain.
UPDATE journal_lines jl
SET cost_center_id = p.cost_center_id,
    updated_at = now()
FROM projects p
WHERE jl.project_id = p.id
  AND jl.id > :last_processed_id
  AND jl.id <= :last_processed_id + :batch_size
  AND jl.cost_center_id IS NULL
  AND jl.deleted_at IS NULL;
```

Batch size in QAYD is tuned per table, starting from a conservative default of 5,000 rows per batch
and adjusted based on observed lock-wait and replication-lag impact (see "Monitoring"); it is a
configuration value on the job, not a magic number buried in code, so an on-call engineer can lower
it live (via a config-cache-safe settings row, not a code deploy) if a running backfill is causing
contention.

For a data migration that corrects historical values after a bug fix — as opposed to backfilling a
newly-added column — QAYD additionally requires: (1) the correction is itself logged to `audit_logs`
per affected row, with `reason` set to the migration/ticket reference, exactly as a user-initiated
correction would be, because from an audit perspective a system-initiated bulk correction is not
different in kind from a human one; (2) if the corrected rows include any *posted* journal lines, the
correction is implemented as a set of reversing + replacement journal entries generated by the
`JournalEntryService`, never as a direct `UPDATE` against `journal_lines`, because posted lines are
immutable by platform invariant regardless of who or what is issuing the change (see
`DATABASE_STANDARDS.md`, Double-Entry Accounting Rules).

# Indexes

Every index QAYD creates against a table with any meaningful row count — in practice, "meaningful"
means "don't bother measuring, just always do this" — uses `CREATE INDEX CONCURRENTLY`, never a plain
`CREATE INDEX`, because a plain `CREATE INDEX` takes a `SHARE` lock that blocks concurrent writes
(though not reads) for the full build duration, which on a large table is unacceptable during
business hours and QAYD has no maintenance window to hide it in.

```php
return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS invoices_company_customer_status_idx
                ON invoices (company_id, customer_id, status)
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS invoices_company_customer_status_idx');
    }
};
```

`CREATE INDEX CONCURRENTLY` has three operational costs that must be planned for, not discovered in
production: it takes roughly 2-3x longer than a blocking `CREATE INDEX` because it performs two
passes over the table (plus a wait for concurrent transactions to finish) to avoid needing the
exclusive lock; it **cannot** run inside a transaction block, which is why every migration using it
sets `public bool $withinTransaction = false;`; and it can fail and leave behind an **invalid**
index (visible in `\d tablename` as `INVALID`, and in `pg_index.indisvalid = false`) if it is
interrupted or if it hits a constraint violation partway through (relevant for unique indexes) — an
invalid index consumes disk space and is skipped by the planner but is not automatically cleaned up,
so QAYD's migration for a `CONCURRENTLY` index always checks for and drops a prior invalid attempt
before retrying:

```sql
-- Defensive cleanup at the top of a CONCURRENTLY index migration, in case a prior
-- deploy attempt was interrupted and left an invalid index behind.
DROP INDEX CONCURRENTLY IF EXISTS invoices_company_customer_status_idx;
```

Every index QAYD adds is deliberate, tied to a known query pattern (a specific dashboard query, a
specific `WHERE`/`ORDER BY`/`JOIN` in a Repository method), and reviewed against `EXPLAIN (ANALYZE,
BUFFERS)` output attached to the PR — QAYD does not add speculative indexes "in case it helps," both
because every index has a real write-amplification cost (every `INSERT`/`UPDATE`/`DELETE` on the
table must also update every index on it) and because index bloat on a table the size of
`journal_lines` or `ledger_entries` has meaningfully different storage and vacuum cost than on a
small master-data table.

QAYD's standard index shapes:

- **Tenant-scoped composite indexes** always lead with `company_id` (the highest-cardinality filter
  present on effectively every query) followed by the next most selective predicate:
  `(company_id, status)`, `(company_id, customer_id, invoice_date DESC)`.
- **Partial indexes** exclude soft-deleted rows (`WHERE deleted_at IS NULL`) on every index that
  backs an application query, because the application never queries deleted rows in the hot path,
  and excluding them keeps the index smaller and faster to maintain — this is the default, not the
  exception, and any index missing this predicate on a soft-deletable table needs a comment in the PR
  explaining why (e.g., an index specifically supporting an admin "restore deleted records" screen).
- **Foreign key columns are always indexed**, even though PostgreSQL does not create an index on a
  foreign key automatically (unlike the referenced side, which needs a unique index/PK for the FK to
  even be creatable). An unindexed FK column means every `ON DELETE`/`ON UPDATE CASCADE` check and
  every join against the parent does a sequential scan of the child table — QAYD's migration linter
  (see "CI/CD Integration") flags any `foreignId()` column added without an accompanying index.
- **Unique indexes enforcing business uniqueness** (e.g., one invoice number per company) are always
  partial and tenant-scoped, built `CONCURRENTLY`:

```sql
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS invoices_company_invoice_number_unique
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;
```

- **`GIN` indexes on JSONB** back any `custom_fields`/`tags` column that the application actually
  filters on (most do not need this — see "Normalization" — but for the ones that do, e.g. `tags` on
  `products` used by a saved-search feature):

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS products_tags_gin_idx
    ON products USING GIN (tags)
    WHERE deleted_at IS NULL;
```

Dropping an index follows the same `CONCURRENTLY` discipline in reverse — `DROP INDEX CONCURRENTLY`
avoids the brief `ACCESS EXCLUSIVE` a plain drop would take, which matters less for correctness (a
drop is fast either way) but is still QAYD's default for consistency and because a plain `DROP INDEX`
on an index still being used by a long-running query will block behind that query, whereas
`CONCURRENTLY` does not.

# Constraints

Constraints are how QAYD enforces the invariants that make an accounting system trustworthy at the
database layer, not just in application code — application-level validation can be bypassed by a
bug, a raw query, a script run in an incident, or a future engineer who does not know a rule exists;
a database constraint cannot be bypassed by any of those. The challenge is adding a constraint to a
large, live table without an `ACCESS EXCLUSIVE` table scan blocking production traffic for the scan's
duration — solved, for `CHECK` and foreign-key constraints, by the `NOT VALID` → `VALIDATE CONSTRAINT`
split.

**The pattern.** `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...) NOT VALID` adds the constraint and
enforces it against every new `INSERT`/`UPDATE` immediately, but takes only a brief lock to update the
catalog — it does **not** scan existing rows, so it completes in milliseconds regardless of table
size. A separate `ALTER TABLE ... VALIDATE CONSTRAINT ...` statement then scans existing rows to
confirm they all satisfy the constraint, but this scan takes only a `SHARE UPDATE EXCLUSIVE` lock,
which is compatible with concurrent `SELECT`, `INSERT`, `UPDATE`, and `DELETE` — it blocks only other
schema-changing DDL on the same table, not application traffic.

```php
// Migration 1 (expand): add the constraint NOT VALID — instant, no table scan.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_cost_center_id_fkey
                FOREIGN KEY (cost_center_id) REFERENCES cost_centers (id)
                NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_cost_center_id_fkey');
    }
};
```

```php
// Migration 2 (validate): scans existing rows under a non-blocking lock. Run only
// after the backfill migration (Data Migrations) has completed and confirmed clean.
return new class extends Migration
{
    public bool $withinTransaction = false; // VALIDATE CONSTRAINT is safest run standalone

    public function up(): void
    {
        DB::statement('ALTER TABLE journal_lines VALIDATE CONSTRAINT journal_lines_cost_center_id_fkey');
    }

    public function down(): void
    {
        // Validating a constraint is not meaningfully reversible/necessary to reverse;
        // dropping the constraint entirely (migration 1's down()) is the real rollback.
    }
};
```

The same two-step pattern applies to `NOT NULL`. As of PostgreSQL 12+, `ALTER TABLE ... ALTER COLUMN
... SET NOT NULL` can use an existing, already-validated `CHECK (col IS NOT NULL)` constraint to skip
its own redundant full-table scan — so QAYD's "add a NOT NULL column safely" sequence is:

```sql
-- Step 1: add nullable column (Schema Changes) + backfill (Data Migrations). Then:

-- Step 2: add the check constraint NOT VALID (instant)
ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_cost_center_id_not_null
    CHECK (cost_center_id IS NOT NULL) NOT VALID;

-- Step 3: validate it (SHARE UPDATE EXCLUSIVE lock, non-blocking for DML)
ALTER TABLE journal_lines
    VALIDATE CONSTRAINT journal_lines_cost_center_id_not_null;

-- Step 4: NOW SET NOT NULL is fast, because Postgres finds the validated CHECK
-- and trusts it instead of re-scanning:
ALTER TABLE journal_lines
    ALTER COLUMN cost_center_id SET NOT NULL;

-- Step 5 (cleanup, optional): the explicit CHECK is now redundant with the column's
-- own NOT NULL attribute and can be dropped to declutter \d output:
ALTER TABLE journal_lines
    DROP CONSTRAINT journal_lines_cost_center_id_not_null;
```

**Unique constraints** follow a related but distinct path: `ADD CONSTRAINT ... UNIQUE` has no `NOT
VALID` form (uniqueness cannot be partially trusted), but the standard workaround is to build the
backing unique index `CONCURRENTLY` first (per "Indexes"), then attach the constraint to the
already-built index, which is a fast catalog-only operation:

```sql
-- Step 1: build the unique index without blocking writers (see Indexes)
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_company_tax_id_unique
    ON customers (company_id, tax_registration_number)
    WHERE deleted_at IS NULL AND tax_registration_number IS NOT NULL;

-- Step 2: attach the constraint using the existing index — instant, catalog-only
ALTER TABLE customers
    ADD CONSTRAINT customers_company_tax_id_unique
    UNIQUE USING INDEX customers_company_tax_id_unique;
```

**`CHECK` constraints enforcing business rules** are used liberally on QAYD's financial tables beyond
simple not-null checks — for example, enforcing that a journal line is either a debit or a credit,
never both or neither, at the database layer as a backstop to the application-layer
`JournalEntryService` validation:

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_debit_xor_credit
    CHECK (
        (debit_amount > 0 AND credit_amount = 0) OR
        (credit_amount > 0 AND debit_amount = 0)
    ) NOT VALID;
-- ...then VALIDATE CONSTRAINT as above once confident no existing row violates it.
```

QAYD never adds a foreign key or check constraint against a table over roughly 100,000 rows without
the `NOT VALID` → `VALIDATE CONSTRAINT` split — this is enforced by the CI migration linter (see
"CI/CD Integration"), which flags a bare `ADD CONSTRAINT` (without `NOT VALID`) on any migration
touching a table present in a maintained list of large tables (`journal_lines`, `ledger_entries`,
`stock_movements`, `audit_logs`, `invoice_items`, `bill_items`, and any table the linter's row-count
probe against the staging snapshot reports over the threshold).

# Production Safety

Every migration that runs against QAYD's production database does so under two session-level guards,
set at the top of the migration or in the deploy pipeline's connection configuration for the migration
step specifically (not for ordinary application connections, which have their own, different
timeout profile):

```php
// Set for the migration's DB connection before any DDL executes.
DB::statement('SET lock_timeout = ' . "'2s'");
DB::statement('SET statement_timeout = ' . "'30s'");
```

`lock_timeout` bounds how long a migration will wait to *acquire* a lock before giving up and erroring
out, rather than queuing silently behind, say, a long-running analytics query or another engineer's
concurrent migration — a migration that cannot get its lock in 2 seconds during business hours almost
always indicates contention that will only get worse, and QAYD would rather fail the deploy loudly and
let an engineer investigate than have the migration sit blocked for minutes while it itself blocks
every subsequent query queued behind it (a blocked `ACCESS EXCLUSIVE` request blocks even `SELECT`s
that arrive after it, because Postgres's lock queue is FIFO per-table). `statement_timeout` bounds how
long the migration's own statement is allowed to run once it has its lock, protecting against a
migration that turns out to be far more expensive than estimated (e.g., an unexpectedly-not-yet-`ANALYZE`d
table causing a bad plan) from running unbounded.

**The mandatory PR review checklist**, attached as a template to every migration PR and explicitly
checked off (not just present) before merge:

| # | Check | Why |
|---|---|---|
| 1 | Does this migration acquire `ACCESS EXCLUSIVE` on a table with >100k rows? If yes, is it decomposed into expand/contract? | Avoids blocking production traffic |
| 2 | Does every `CREATE INDEX` / `DROP INDEX` use `CONCURRENTLY`, with `$withinTransaction = false`? | Avoids blocking writers during index builds |
| 3 | Does every new `CHECK`/FK constraint on a >100k row table use `NOT VALID` + a separate `VALIDATE CONSTRAINT`? | Avoids a blocking full-table scan |
| 4 | Is a schema change and its data backfill split into separate migrations/PRs? | Decouples fast/safe from slow/risky |
| 5 | Does the migration have a tested, working `down()`, or an explicit "forward-fix only" note? | Guarantees a rollback story exists |
| 6 | Is `lock_timeout`/`statement_timeout` set for anything beyond a trivial nullable-column add? | Bounds blast radius of the unexpected |
| 7 | Does the estimated row count and current table size appear in the PR description? | Forces sizing before, not during, the incident |
| 8 | For a drop of a column/table with financial data, is there a cold-storage export referenced? | Preserves auditability |
| 9 | Is a second, senior reviewer required (per the risk-level rules) and have they signed off? | Raises the bar for high-blast-radius changes |
| 10 | Does the migration touch `journal_entries`/`journal_lines`/`ledger_entries`/`accounts`/payroll/tax tables? If yes, has Finance/Compliance been notified per the change-management policy? | Financial-integrity and audit notification |

Beyond the PR checklist, QAYD enforces production safety operationally: migrations never run
automatically on a schedule outside a deploy (no cron-triggered `migrate`), never run against
production without having first run cleanly against the staging snapshot in the same pipeline
execution (see "CI/CD Integration"), and never run concurrently with another migration deploy — the
pipeline holds a deploy-lock (a Redis-backed mutex, TTL slightly longer than the pipeline's own
timeout) so two engineers cannot accidentally trigger overlapping migration runs against the same
database.

# Large Tables

QAYD's largest tables by row count and growth rate are, in order, `journal_lines` (every debit/credit
line of every posted and draft entry, across every tenant, forever — soft-deleted, never purged),
`ledger_entries` (the GL projection derived from `journal_lines`), `audit_logs` (every mutation on
every table, platform-wide), `stock_movements`, `invoice_items`/`bill_items`, and `tax_transactions`.
These tables receive dedicated migration handling beyond the general rules above, because at scale
(QAYD's growth trajectory implies `journal_lines` crossing hundreds of millions of rows within a few
years across the full tenant base) even a `CONCURRENTLY` index build or a `VALIDATE CONSTRAINT` scan
takes long enough — potentially hours — that it needs explicit scheduling and monitoring, not just
correctness.

**Partitioning.** `journal_lines`, `ledger_entries`, and `audit_logs` are declared as PostgreSQL
native range-partitioned tables, partitioned by `created_at` (month-range partitions), from table
creation — QAYD does not wait until a table is already large and painful to partition retroactively,
because converting an existing large unpartitioned table into a partitioned one is itself a
significant migration (effectively a full data migration copying every row into the new partitioned
structure) that is far cheaper to avoid than to perform later.

```sql
CREATE TABLE journal_lines (
    id             BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    journal_entry_id BIGINT NOT NULL,
    account_id     BIGINT NOT NULL REFERENCES accounts(id),
    debit_amount   NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code  CHAR(3) NOT NULL,
    exchange_rate  NUMERIC(18,6) NOT NULL DEFAULT 1,
    cost_center_id BIGINT REFERENCES cost_centers(id),
    project_id     BIGINT REFERENCES projects(id),
    description    TEXT,
    created_by     BIGINT REFERENCES users(id),
    updated_by     BIGINT REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at     TIMESTAMPTZ,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

CREATE TABLE journal_lines_2026_07 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE journal_lines_2026_08 PARTITION OF journal_lines
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
```

New monthly partitions are created ahead of need by a scheduled Laravel command (`php artisan
db:create-next-partition`), run via Laravel's scheduler at least one full month before the partition
is needed, itself implemented as an idempotent `CREATE TABLE ... PARTITION OF ... IF NOT EXISTS`-style
check (Postgres does not support `IF NOT EXISTS` directly on `PARTITION OF` in all versions QAYD
targets, so the command checks `pg_class`/`pg_inherits` first). Indexes on a partitioned table are
created once on the parent (`CREATE INDEX ... ON journal_lines (...)`) and Postgres propagates them to
every partition automatically as a templated index — but building that propagated index on an
*existing* large partitioned table still requires `CONCURRENTLY` semantics, achieved via
`CREATE INDEX CONCURRENTLY` on each partition individually followed by `CREATE INDEX ... ON ONLY
journal_lines (...)` plus `ALTER INDEX ... ATTACH PARTITION` to assemble them into one logical parent
index without ever taking a blocking lock on the full logical table at once.

**Retention and cold storage.** `audit_logs` partitions older than the platform's active retention
window (36 months, per QAYD's compliance policy) are not deleted — financial audit trails are kept
indefinitely per Gulf regulatory expectations — but are detached from the live partitioned table
(`ALTER TABLE audit_logs DETACH PARTITION audit_logs_2023_01 CONCURRENTLY;`, a Postgres 14+ feature
that avoids blocking concurrent queries on the parent during detach) and archived to a separate,
infrequently-queried tablespace or exported to Cloudflare R2 as Parquet for compliance/audit query via
an external tool, keeping the live table's working set — and therefore every index scan against it —
bounded regardless of how many years of history the platform accumulates.

**Vacuum and autovacuum tuning.** Large, high-churn tables (`stock_movements`, `journal_lines` before
posting settles) have table-specific autovacuum tuning applied via `ALTER TABLE ... SET (autovacuum_vacuum_scale_factor
= 0.02, autovacuum_analyze_scale_factor = 0.01)` — tighter than Postgres's defaults — because on a
huge table, the default scale-factor thresholds (a percentage of table size) mean autovacuum runs far
less often than the actual dead-tuple accumulation from QAYD's write pattern warrants, and letting bloat
accumulate on the ledger's core table is not an acceptable trade against slightly more frequent vacuum
I/O. This tuning is itself deployed as a migration (`DB::statement("ALTER TABLE journal_lines SET
(autovacuum_vacuum_scale_factor = 0.02)")`) and is reviewed and adjusted based on the bloat and vacuum
metrics in "Monitoring."

**Building anything on a large table takes a schedule, not just a `CONCURRENTLY` flag.** For any
`CONCURRENTLY` index build or `VALIDATE CONSTRAINT` estimated (via a dry run against the staging
snapshot, timed) to exceed 15 minutes on `journal_lines`, `ledger_entries`, or `audit_logs`, the
migration is scheduled for a specific, communicated low-traffic window (QAYD's lowest sustained load
is currently Friday 02:00–05:00 Kuwait time, reviewed periodically as the tenant base's geographic mix
shifts) even though it is technically non-blocking for DML — not because it would break anything, but
because a multi-hour background operation competing for I/O and CPU with foreground queries degrades
p99 latency in a way that is better to have off-peak, and because it keeps the on-call monitoring
window (per "Migration Lifecycle," stage 6) inside reasonable working hours for the engineer watching
it.

# Multi Tenant

Every migration in QAYD is written and reviewed with the fact that a single schema serves every
tenant company simultaneously — there are no per-tenant schemas, databases, or connection strings;
isolation is entirely row-level via `company_id` plus PostgreSQL Row-Level Security policies (see
`ROW_LEVEL_SECURITY.md`). This has direct migration consequences beyond the general safety rules
above.

**A migration's blast radius is the entire platform, always.** There is no such thing as "a migration
that only affects one tenant" — a schema change to `invoices` affects every company's invoices in the
same statement, at the same time. This is precisely why the row-count and risk-level thresholds
throughout this document are pinned to the *shared* table's size, not to any individual tenant's data
volume, and why QAYD does not offer, and will not build, a "migrate just my company" escape hatch —
it would fragment the schema history the entire safety model depends on.

**RLS policies must be considered part of the migration for any new tenant-scoped table.** Creating a
new table without also creating its `company_id`-scoping RLS policy in the *same* migration (or a
tightly-coupled immediately-following one, never left as a follow-up "TODO") is a data-isolation
defect, not a style issue — QAYD's CI migration linter checks that any `CREATE TABLE` including a
`company_id` column is paired with an `ENABLE ROW LEVEL SECURITY` + `CREATE POLICY` statement before
allowing the PR to merge:

```sql
ALTER TABLE cost_centers ENABLE ROW LEVEL SECURITY;

CREATE POLICY cost_centers_tenant_isolation ON cost_centers
    USING (company_id = current_setting('app.current_company_id')::BIGINT);
```

**Backfills must never leak across `company_id`.** A batched data migration (per "Data Migrations")
that joins across tables to compute a backfilled value must include `company_id` in its join
predicate explicitly, even when the foreign key relationship alone would produce the same result —
this is defense in depth against a future schema change accidentally weakening a constraint, and it
is the same principle RLS applies at the query layer, applied at the migration-authoring layer:

```sql
UPDATE invoices i
SET default_payment_terms_days = c.payment_terms_days
FROM customers c
WHERE i.customer_id = c.id
  AND i.company_id = c.company_id      -- explicit, even though customer_id -> customers.id
  AND i.default_payment_terms_days IS NULL;
```

**A migration that changes behavior per-tenant (e.g., rolling out a new costing method company-by-
company) is not a schema migration at all** — it is a feature flag / configuration change, stored in
a `company_settings` table and read by application logic, never expressed as conditional DDL. QAYD
never writes a migration containing per-`company_id` branching logic in its `up()` — schema shape is
identical for every tenant, always; only data and application behavior vary by tenant, and only
through ordinary rows and feature flags, never through migration-time conditionals that would make
the schema's actual state depend on when a given company was created relative to a migration's
deployment.

**Tenant onboarding is itself a (very small, very frequent) data migration.** Provisioning a new
company — inserting its `companies` row, its default `account_types`/chart-of-accounts template
rows, its default roles/permissions rows — runs through the same Repository/Service layer as any
other write, not through a bespoke migration-time script, precisely so that it is covered by the same
tests, the same audit logging, and the same RLS policies as every other multi-row insert in the
system.

# Background Migrations

A background migration is any data migration whose execution is dispatched by a lightweight Laravel
migration file but actually performed by Laravel queue workers, over minutes to days, outside the
deploy pipeline's own timeout and outside any single database transaction. This is QAYD's default
mechanism for any backfill exceeding roughly 50,000 rows, and is mandatory — not merely
recommended — for any table on the "Large Tables" list.

**Job structure.** Every background migration job is a `ShouldQueue` job dispatched onto a dedicated
`migrations` queue (isolated from the `default`, `notifications`, and `ai` queues so a large backfill
never starves user-facing background work like email sending or AI-agent processing), implemented as
a self-re-dispatching batch processor rather than one job that loops internally for hours — this keeps
each individual job execution short (bounded by the queue worker's own timeout), makes progress
visible and resumable between batches, and lets Horizon (QAYD's queue dashboard) show real, granular
progress rather than one opaque long-running job.

```php
<?php

namespace App\Jobs\Migrations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillJournalLinesCostCenterId implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 30;
    public string $queue = 'migrations';

    public function __construct(private int $lastProcessedId = 0, private int $batchSize = 5000)
    {
    }

    public function handle(): void
    {
        $maxId = (int) DB::table('journal_lines')->max('id');

        if ($this->lastProcessedId >= $maxId) {
            DB::table('migration_backfill_progress')
                ->updateOrInsert(
                    ['migration_name' => self::class],
                    ['status' => 'completed', 'completed_at' => now(), 'last_processed_id' => $this->lastProcessedId]
                );
            Log::info('Backfill complete', ['job' => self::class, 'last_id' => $this->lastProcessedId]);
            return;
        }

        $updated = DB::update(<<<'SQL'
            UPDATE journal_lines jl
            SET cost_center_id = p.cost_center_id, updated_at = now()
            FROM projects p
            WHERE jl.project_id = p.id
              AND jl.company_id = p.company_id
              AND jl.id > ? AND jl.id <= ?
              AND jl.cost_center_id IS NULL
              AND jl.deleted_at IS NULL
        SQL, [$this->lastProcessedId, $this->lastProcessedId + $this->batchSize]);

        $nextId = $this->lastProcessedId + $this->batchSize;

        DB::table('migration_backfill_progress')->updateOrInsert(
            ['migration_name' => self::class],
            ['status' => 'running', 'last_processed_id' => $nextId, 'rows_updated_last_batch' => $updated, 'updated_at' => now()]
        );

        // Re-dispatch the next batch with a small delay to bound sustained load.
        self::dispatch($nextId, $this->batchSize)->delay(now()->addSeconds(2));
    }
}
```

```sql
CREATE TABLE migration_backfill_progress (
    migration_name       TEXT PRIMARY KEY,
    status                TEXT NOT NULL CHECK (status IN ('running','completed','failed')),
    last_processed_id     BIGINT NOT NULL DEFAULT 0,
    rows_updated_last_batch INTEGER,
    started_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at          TIMESTAMPTZ,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

**Idempotency and resumability are the two non-negotiable properties.** Every batch's `WHERE` clause
includes the condition that makes re-running it a no-op if it already succeeded (`cost_center_id IS
NULL` above) — this means a job retried by Laravel's queue after a transient failure, or manually
re-dispatched by an on-call engineer after an incident, never double-applies or corrupts data, and it
means the `migration_backfill_progress` row is a genuinely trustworthy resume point rather than an
approximation.

**Backpressure and self-throttling.** The `delay(now()->addSeconds(2))` between batches, and the
configurable `$batchSize`, are QAYD's primary levers for keeping a background migration from degrading
foreground query latency — an on-call engineer watching the "Monitoring" dashboards can update the
`batchSize`/delay for a *running* migration (read from `migration_backfill_progress` or a dedicated
config row rather than hardcoded, so no code deploy is needed to slow a hot migration down mid-flight)
and, in the worst case, can flip a `status = 'paused'` flag the job checks at the top of `handle()`
before doing any work, stopping the backfill within one batch's worth of time without needing to purge
the queue.

**Completion is a monitored event, not an assumption.** A background migration is not "done" when the
last job in the initial dispatch chain returns — it is done when `migration_backfill_progress.status =
'completed'`, and the contract-phase migration that depends on the backfill (e.g., the `VALIDATE
CONSTRAINT` in "Constraints," or the column drop in "Zero Downtime Deployments") includes a
pre-flight check that queries this table and refuses to proceed (failing the deploy step explicitly)
if the corresponding backfill is not marked complete — this is enforced in code, not left to an
engineer's memory of whether last week's backfill actually finished.

# Testing

Every migration in QAYD ships with a Pest test, run in CI against a real (throwaway, containerized)
PostgreSQL instance — never SQLite or an in-memory substitute, because the entire point of this
document is behavior (locking, constraint validation timing, index build semantics) that only a real
Postgres engine exhibits.

**Schema migration tests** assert the resulting structure directly against `information_schema` /
`pg_catalog`, not just "the migration ran without throwing":

```php
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

it('adds a nullable cost_center_id column to journal_lines', function () {
    expect(Schema::hasColumn('journal_lines', 'cost_center_id'))->toBeTrue();

    $column = DB::selectOne(<<<'SQL'
        SELECT is_nullable, data_type
        FROM information_schema.columns
        WHERE table_name = 'journal_lines' AND column_name = 'cost_center_id'
    SQL);

    expect($column->is_nullable)->toBe('YES');
    expect($column->data_type)->toBe('bigint');
});

it('rolls back cleanly, removing the column', function () {
    $this->artisan('migrate:rollback', ['--step' => 1]);
    expect(Schema::hasColumn('journal_lines', 'cost_center_id'))->toBeFalse();
});
```

**Constraint tests** assert both that the constraint rejects bad data after validation, and — for the
`NOT VALID` expand step specifically — that it does *not* yet reject pre-existing bad data until
`VALIDATE CONSTRAINT` runs, since that asymmetry is the entire point of the pattern and is exactly the
kind of subtlety a future refactor could silently break:

```php
it('rejects new rows violating debit-xor-credit after the check is added', function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/2026_..._add_debit_xor_credit_check.php']);

    expect(fn () => DB::table('journal_lines')->insert([
        'company_id' => 1, 'journal_entry_id' => 1, 'account_id' => 1,
        'debit_amount' => 100, 'credit_amount' => 100, // both set: invalid
        'currency_code' => 'KWD', 'exchange_rate' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

**Data migration / backfill tests** seed a representative fixture — including edge cases: rows with
NULL foreign keys, soft-deleted rows that must be excluded, rows already correctly backfilled that
must not be double-processed — then run the job's `handle()` synchronously (Laravel's `Bus::fake()`
is deliberately *not* used for these tests; the job's actual SQL is what is being verified) and assert
the resulting data state batch-by-batch, including that a second full run is a no-op (idempotency):

```php
it('backfills cost_center_id from the related project, batch by batch, idempotently', function () {
    $company = Company::factory()->create();
    $project = Project::factory()->for($company)->create(['cost_center_id' => $cc = CostCenter::factory()->for($company)->create()->id]);
    $line = JournalLine::factory()->for($company)->create(['project_id' => $project->id, 'cost_center_id' => null]);
    $alreadyDone = JournalLine::factory()->for($company)->create(['project_id' => $project->id, 'cost_center_id' => $cc]);
    $softDeleted = JournalLine::factory()->for($company)->create(['project_id' => $project->id, 'cost_center_id' => null, 'deleted_at' => now()]);

    (new BackfillJournalLinesCostCenterId(0, 100))->handle();

    expect($line->refresh()->cost_center_id)->toBe($cc);
    expect($softDeleted->refresh()->cost_center_id)->toBeNull(); // excluded by design

    $rowsBefore = DB::table('journal_lines')->whereNotNull('cost_center_id')->count();
    (new BackfillJournalLinesCostCenterId(0, 100))->handle(); // run again
    $rowsAfter = DB::table('journal_lines')->whereNotNull('cost_center_id')->count();

    expect($rowsAfter)->toBe($rowsBefore); // idempotent: no change on re-run
});
```

**Lock and performance regression tests** run outside the normal Pest suite (they are slow and
environment-sensitive) as a separate, scheduled CI job against a sized fixture (a seeded database with
a representative row count for the target table, not production scale, but large enough — typically
1-5 million rows — to surface `EXPLAIN` plan shape and lock-duration issues that an empty table would
hide) and assert, via `pg_stat_activity` sampling during the migration's execution, that no lock is
held longer than the migration's declared `lock_timeout`/`statement_timeout` budget.

# CI/CD Integration

QAYD's deployment pipeline treats database migration as a distinct, gated stage that runs strictly
before the application code depending on it is rolled out, never bundled into the same deploy step as
application deployment, and never run without first succeeding against staging.

```
 PR opened
   │
   ▼
 CI: lint migrations (see linter rules below)
   │
   ▼
 CI: spin up throwaway Postgres 15 container, seeded from latest staging schema
   │  dump; run `php artisan migrate`; run full Pest suite (schema + data migration
   │  tests); run `php artisan migrate:rollback` + re-migrate to prove reversibility
   │  for every migration flagged reversible
   ▼
 PR reviewed + approved (+ second reviewer if risk_level = high)
   │
   ▼
 Merge to main → pipeline stage: DEPLOY TO STAGING
   │  1. Run `php artisan migrate --force` against staging DB
   │  2. Deploy application containers to staging
   │  3. Run smoke test suite (Playwright + API contract tests)
   │  4. Soak period (30 min routine / up to 24h for risk_level = high)
   ▼
 Manual promote (or auto-promote for risk_level = low after soak) → DEPLOY TO PRODUCTION
   │  1. Acquire deploy-lock (Redis mutex)
   │  2. Run `php artisan migrate --force` against production, with
   │     lock_timeout / statement_timeout set per Production Safety
   │  3. Verify migration success + schema_migrations_log entry written
   │  4. Only THEN: roll out new application containers (canary → full)
   │  5. Release deploy-lock
   ▼
 Monitor window (Migration Lifecycle, stage 6)
```

**Migration linter rules**, run as a CI step against the diff of every PR touching
`database/migrations/`, implemented as a small static-analysis script over the migration files (a
custom Composer-installed tool, `qayd/migration-linter`, run via `php artisan migrations:lint`):

- Fails if a migration file contains more than one of: `CREATE TABLE`, structural `ALTER TABLE ADD/DROP
  COLUMN`, index creation, constraint addition — enforcing "one structural intent per file" from
  "Forward Migrations."
- Fails if `CREATE INDEX`/`DROP INDEX` appears without `CONCURRENTLY`.
- Fails if `CONCURRENTLY` appears without `public bool $withinTransaction = false;` in the same class.
- Fails if `ADD CONSTRAINT` (CHECK or FOREIGN KEY) appears without `NOT VALID`, when the target table
  is on the maintained large-tables list (refreshed weekly from an actual `SELECT reltuples FROM
  pg_class` probe against the staging snapshot).
- Fails if a new `foreignId()`/foreign-key column is added without an accompanying index in the same
  or a paired migration.
- Fails if a `down()` method is empty/missing without an explicit `// forward-fix only:` comment
  explaining why (per "Rollback," Category 3).
- Fails if a `CREATE TABLE` with a `company_id` column has no paired `ENABLE ROW LEVEL SECURITY` +
  `CREATE POLICY` statement in the same PR.
- Warns (does not fail, but requires reviewer acknowledgment) if a migration's estimated affected row
  count, computed by running the migration's `WHERE` clause as a `SELECT COUNT(*)` dry run against the
  staging snapshot, exceeds 100,000.

**The staging snapshot** used for CI dry-runs and the staging environment itself is a nightly,
anonymized (PII/financial-value-scrambled per QAYD's data-handling policy — customer names, tax IDs,
and monetary amounts are deterministically hashed/randomized, never real tenant data) `pg_dump` of
production, refreshed automatically so that CI's row-count probes and `EXPLAIN` plans reflect
production's actual data distribution and index usage patterns, not a stale or synthetic fixture that
would give false confidence about a migration's real-world lock/performance behavior.

**Deploy pipeline safety nets:** the pipeline aborts the entire deploy (application rollout does not
proceed) if the migration step exits non-zero for any reason, including a `lock_timeout` expiry; the
pipeline captures and attaches the full `EXPLAIN (ANALYZE, BUFFERS)` output (where applicable) and the
`schema_migrations_log` row for the just-applied migration to the deploy's audit trail automatically;
and a second, independent "migration health check" step runs immediately after migration and before
application rollout, executing a small set of read-only sanity queries against the just-migrated
tables (row count sanity, a spot-check `SELECT` against the new column/constraint) to catch a
migration that "succeeded" per Laravel's runner but left data in an unexpected state.

# Monitoring

QAYD watches four categories of signal specifically around migration execution, in addition to the
platform's always-on database and application monitoring (query latency, error rates, saturation).

**Lock activity**, sampled continuously via a scheduled query against `pg_locks` joined to
`pg_stat_activity`, surfaced on a dashboard the on-call engineer watches during and immediately after
every migration deploy:

```sql
SELECT
    pg_stat_activity.pid,
    pg_stat_activity.query,
    pg_stat_activity.state,
    age(now(), pg_stat_activity.query_start) AS query_age,
    pg_locks.mode,
    pg_locks.granted,
    pg_class.relname
FROM pg_locks
JOIN pg_stat_activity ON pg_locks.pid = pg_stat_activity.pid
JOIN pg_class ON pg_locks.relation = pg_class.oid
WHERE pg_locks.mode IN ('AccessExclusiveLock', 'ShareUpdateExclusiveLock', 'ShareLock')
ORDER BY query_age DESC;
```

An alert fires if any lock in `AccessExclusiveLock` mode is held (`granted = true`) for longer than 5
seconds, or if any query is waiting (`granted = false`) for a lock for longer than the configured
`lock_timeout` minus a small margin — the latter is a leading indicator that a migration is about to
fail its `lock_timeout` and lets on-call intervene (or simply expect and accept the controlled
failure) before it happens blind.

**Replication lag**, because QAYD's read replicas (serving reporting queries and read-heavy dashboard
traffic) must not fall meaningfully behind during a large backfill's write volume:

```sql
SELECT client_addr, state, sent_lsn, replay_lsn,
       pg_wal_lsn_diff(sent_lsn, replay_lsn) AS lag_bytes,
       extract(epoch from replay_lag) AS lag_seconds
FROM pg_stat_replication;
```

An alert fires above 30 seconds of `lag_seconds` sustained for more than 2 minutes during a background
migration's execution window, at which point the migration job's self-throttling (per "Background
Migrations") is expected to be tuned down — a batch delay increase or a batch size decrease — via the
live-adjustable config, without needing a code deploy.

**Migration duration and outcome**, tracked directly in `schema_migrations_log` (per "Versioning") and
in the queue-level metrics Horizon exposes for background migration jobs (`migration_backfill_progress`
rows plus Horizon's own job-duration histograms), graphed over time so a migration that is taking
unexpectedly long relative to its CI dry-run estimate is visible immediately rather than discovered
when a deploy pipeline eventually times out.

**Data-quality regression signals** specific to financial correctness, run as a scheduled (every 15
minutes) background check independent of any specific migration, because a migration's local tests
passing does not guarantee the platform-wide invariant holds after rollout at scale:

```sql
-- Alert if any posted journal entry is out of balance, at all, ever.
SELECT je.id, je.company_id,
       SUM(jl.debit_amount) AS total_debits,
       SUM(jl.credit_amount) AS total_credits
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
WHERE je.status = 'posted' AND jl.deleted_at IS NULL
GROUP BY je.id, je.company_id
HAVING SUM(jl.debit_amount) != SUM(jl.credit_amount);
```

This query returning any row at all, ever, is treated as a Severity-1 incident regardless of whether
a migration ran recently — it is included here because a botched data migration touching
`journal_lines` is the most plausible root cause QAYD has identified for this invariant ever breaking,
and the response runbook for this alert starts by checking `schema_migrations_log` and
`migration_backfill_progress` for anything that ran in the preceding 24 hours before looking anywhere
else.

# Rollback Strategy

"Rollback" (earlier in this document) covers how an individual migration's `down()` is written and
when it is trustworthy. This section covers the deployment-level decision of *what to actually do*
when a migration or the deploy built on top of it causes a production problem — a distinct, higher-
stakes decision that on-call engineers make under time pressure, and QAYD gives them a fixed decision
tree rather than leaving it to improvisation.

```
 Incident detected during/after a migration deploy
   │
   ▼
 Is the application (not the schema) the source of the problem?
   │ YES → roll back the APPLICATION deploy to the previous release.
   │        Safe by construction IF the migration was expand-phase (additive,
   │        backward-compatible) — which is the default requirement per
   │        "Zero Downtime Deployments." Schema stays as-is; no migration
   │        rollback needed or attempted.
   │
   │ NO / UNSURE → continue below
   ▼
 Is the migration still executing (long VALIDATE CONSTRAINT / CONCURRENTLY build /
 background backfill in progress)?
   │ YES → the operation is safe to simply CANCEL (pg_cancel_backend for a
   │        blocking foreground statement; flip the `status = 'paused'` flag
   │        for a background job). CONCURRENTLY builds and VALIDATE CONSTRAINT
   │        do not corrupt data if interrupted — they may leave an INVALID
   │        index or an un-validated constraint, both harmless no-ops until
   │        retried. Retry later after investigating.
   │
   │ NO (migration already completed) → continue below
   ▼
 Is the completed migration's `down()` tested and safe (Category 1 or a
 confidently-assessed Category 2 with a fresh cold-storage export)?
   │ YES → run the specific `down()` migration for JUST that migration
   │        (`php artisan migrate:rollback --step=1` targeting the exact
   │        file, never a blanket multi-step rollback), under the deploy-lock,
   │        with the same lock_timeout/statement_timeout discipline as any
   │        other migration.
   │
   │ NO (Category 3, forward-fix only, or the down() would lose data
   │      unacceptably) → do NOT roll back. Write and fast-track a new
   │      forward migration that corrects the problem (a compensating
   │      change), through an expedited but still-reviewed path (minimum:
   │      one senior reviewer, no skipped CI stages, but skip the staging
   │      soak period for a Sev-1 with reviewer + incident-commander sign-off).
```

The overriding principle: **rolling back a schema change is not inherently safer than fixing forward,
and QAYD does not treat "rollback" as a reflexive first move.** For expand-phase changes specifically,
the schema change was designed from the start to coexist safely with the previous application version
— which means the correct rollback target, in the overwhelming majority of real incidents, is the
*application* release, not the migration. This is precisely why expand/contract discipline exists:
it converts "roll back a risky schema change under time pressure" into "roll back a container image,"
which is fast, well-tested by the platform's ordinary deploy tooling, and carries none of the data-loss
risk that reversing a schema or data change can carry.

For the rare case where the schema change itself must be reversed, QAYD requires the incident
commander (a named on-call role, rotated weekly) to explicitly approve the specific `down()` migration
being run, logged in the incident channel and in `schema_migrations_log.rolled_back = true`, before it
executes against production — this is a deliberate manual gate, not automated, because a mistaken
automatic rollback during an active incident has, industry-wide, caused more damage than the original
incident in comparable systems, and QAYD treats that risk as worth the extra 60 seconds of human
confirmation every time.

# Best Practices

- **Default to expand/contract for anything that could break a currently-running old application
  version.** Treat "can I skip the expand phase this once" as a question that is always answered no,
  because the cost of expand/contract is calendar time, and the cost of skipping it under load is a
  production incident.
- **Separate schema migrations from data migrations, always**, even when it feels like overhead for a
  small table today — consistency in this discipline is what makes the CI linter, the review
  checklist, and the on-call runbooks reliable, and none of those tools work if some migrations
  quietly conflate the two and others don't.
- **Never trust an estimate you have not measured against the staging snapshot.** "This table is
  small, it'll be fine" is the most common last words before an avoidable incident; the CI pipeline's
  row-count probe and `EXPLAIN` dry-run exist specifically to replace guesses with numbers.
- **Prefer `NOT VALID` + `VALIDATE CONSTRAINT` and `CONCURRENTLY` by default, not as an optimization
  reached for only on "big" tables.** A table that is small today grows, migrations written against it
  outlive the assumption that it will stay small, and the marginal cost of the safe pattern on a small
  table is negligible.
- **Write the `down()` (or the explicit "forward-fix only" note) at the same time as the `up()`, not
  as an afterthought before merge.** A `down()` written immediately after the `up()`, while the
  engineer still holds the full context of what changed, is dramatically more likely to be correct
  than one bolted on days later to satisfy a checklist item.
- **Treat `company_id`/RLS coverage as part of the schema, not a follow-up.** A tenant-scoped table
  without its isolation policy is not "80% done" — it is an active data-isolation defect the moment it
  can be written to.
- **Size every backfill's batch and delay based on measured impact, not intuition**, and make both
  live-adjustable so an on-call engineer can react to real-time lock/replication signals without
  needing a code deploy.
- **Keep the contract phase on a tracked, scheduled follow-up, never an implicit "someday."** An
  un-contracted expand is unfinished work with a real cost: two sources of truth, extra storage, extra
  write amplification, and cognitive overhead for every engineer who encounters the deprecated shape
  later without context.
- **Financial-table migrations get a second reviewer, without exception**, because the cost of a
  false negative (an accounting-integrity defect reaching production) is categorically higher than the
  cost of the extra review cycle.
- **Prefer fixing forward over rolling back schema, and make that the documented default**, so the
  decision under incident pressure is "follow the runbook," not "improvise while the pager is going
  off."
- **Every migration's PR description states the estimated row count, the risk level, and the rollback
  plan explicitly** — not because the template demands prose, but because writing those three things
  down is what forces the sizing and safety analysis to actually happen before the code is written,
  not after something breaks.

# Examples

This section works five complete, realistic QAYD migrations end to end, each showing every file
involved, to serve as the reference implementation new engineers (and AI agents drafting migrations
for human review) copy from directly.

**Example 1 — Add a new nullable, indexed foreign key to a large table (fully expand-phase, no
contract needed because nothing old is being removed).**

Adding `payment_terms_days` sourced from a new `payment_terms` master-data table to `sales_orders` (a
large, actively-written table).

```php
// 2026_07_10_090000_create_payment_terms_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->tenantColumns();
            $table->string('code', 16);
            $table->string('name_en');
            $table->string('name_ar');
            $table->smallInteger('days');
            $table->boolean('is_default')->default(false);

            $table->unique(['company_id', 'code'], 'payment_terms_company_code_unique');
            $table->index(['company_id', 'is_default']);
        });

        DB::statement('ALTER TABLE payment_terms ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY payment_terms_tenant_isolation ON payment_terms
                USING (company_id = current_setting('app.current_company_id')::BIGINT)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
```

```php
// 2026_07_10_090100_add_payment_terms_id_to_sales_orders.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('payment_terms_id')->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('payment_terms_id');
        });
    }
};
```

```php
// 2026_07_10_090200_add_index_payment_terms_id_sales_orders.php
return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS sales_orders_payment_terms_id_idx
                ON sales_orders (payment_terms_id)
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sales_orders_payment_terms_id_idx');
    }
};
```

No backfill is needed here because `payment_terms_id` defaults to null (meaning "use customer's
default") and application logic already falls back correctly — this migration is complete after the
expand phase, with no contract phase required, because nothing old is deprecated.

**Example 2 — Add a `NOT NULL` foreign key to a 200-million-row table (full expand → migrate →
contract sequence).**

Enforcing that every `journal_lines` row has a non-null `cost_center_id`, across four separate,
sequentially-deployed migrations.

```php
// 2026_07_11_080000_add_cost_center_id_to_journal_lines.php  (expand: schema)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn('cost_center_id');
        });
    }
};
```

```php
// 2026_07_11_080100_index_cost_center_id_journal_lines.php (expand: index, CONCURRENTLY)
return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS journal_lines_cost_center_id_idx
                ON journal_lines (cost_center_id) WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS journal_lines_cost_center_id_idx');
    }
};
```

```php
// 2026_07_11_080200_dispatch_backfill_cost_center_id.php (migrate: data, background job)
return new class extends Migration
{
    public function up(): void
    {
        \App\Jobs\Migrations\BackfillJournalLinesCostCenterId::dispatch();
    }

    public function down(): void
    {
        // forward-fix only: backfilled data is not reversed; see Rollback, Category 3.
    }
};
```

Deploy, in this order, with a manual gate between each: (1) ship application code that dual-writes
`cost_center_id` on every new journal line going forward, alongside the legacy `project_id`-derived
lookup, so no new rows are created without it while the backfill catches up on history; (2) confirm
`migration_backfill_progress.status = 'completed'` for the backfill job; (3) only then proceed to the
constraint migrations below.

```php
// 2026_07_18_090000_add_not_valid_check_cost_center_id_journal_lines.php (contract: constraint, not valid)
return new class extends Migration
{
    public function up(): void
    {
        $incomplete = DB::table('migration_backfill_progress')
            ->where('migration_name', \App\Jobs\Migrations\BackfillJournalLinesCostCenterId::class)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($incomplete) {
            throw new \RuntimeException('Backfill not yet complete; refusing to add NOT NULL constraint.');
        }

        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_cost_center_id_not_null
                CHECK (cost_center_id IS NOT NULL) NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_cost_center_id_not_null');
    }
};
```

```php
// 2026_07_18_090100_validate_and_enforce_not_null_cost_center_id.php (contract: validate + enforce)
return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE journal_lines VALIDATE CONSTRAINT journal_lines_cost_center_id_not_null');
        DB::statement('ALTER TABLE journal_lines ALTER COLUMN cost_center_id SET NOT NULL');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_cost_center_id_not_null');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_lines ALTER COLUMN cost_center_id DROP NOT NULL');
    }
};
```

**Example 3 — Add a unique constraint to prevent duplicate invoice numbers per tenant, on a live,
already-populated table, with a pre-check for existing violations.**

```php
// 2026_07_12_070000_check_duplicate_invoice_numbers.php (pre-flight, fails loud if data is dirty)
return new class extends Migration
{
    public function up(): void
    {
        $dupes = DB::select(<<<'SQL'
            SELECT company_id, invoice_number, COUNT(*) c
            FROM invoices
            WHERE deleted_at IS NULL
            GROUP BY company_id, invoice_number
            HAVING COUNT(*) > 1
            LIMIT 10
        SQL);

        if (count($dupes) > 0) {
            throw new \RuntimeException(
                'Duplicate invoice numbers exist; resolve before adding unique constraint: ' .
                json_encode($dupes)
            );
        }
    }

    public function down(): void
    {
        // No-op: this migration only checks, it does not change schema.
    }
};
```

```php
// 2026_07_12_070100_add_unique_index_invoice_number.php
return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS invoices_company_invoice_number_unique'); // defensive cleanup
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY invoices_company_invoice_number_unique
                ON invoices (company_id, invoice_number)
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS invoices_company_invoice_number_unique');
    }
};
```

```php
// 2026_07_12_070200_attach_unique_constraint_invoice_number.php
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE invoices
                ADD CONSTRAINT invoices_company_invoice_number_unique
                UNIQUE USING INDEX invoices_company_invoice_number_unique
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_company_invoice_number_unique');
    }
};
```

**Example 4 — Partitioning a fast-growing table retroactively (rare, expensive, but occasionally
necessary — shown for `stock_movements` after it was created unpartitioned and later needed to be).**

Because converting an existing unpartitioned table to partitioned in place is not directly supported
by `ALTER TABLE`, QAYD's pattern is: create the new partitioned table under a temporary name, copy data
across in batches (a background migration, per that section), then atomically swap names inside a
brief transaction once the copy is verified caught up via a final small delta copy.

```sql
-- Step 1: create the new partitioned structure.
CREATE TABLE stock_movements_partitioned (LIKE stock_movements INCLUDING ALL)
    PARTITION BY RANGE (created_at);
CREATE TABLE stock_movements_p2026_07 PARTITION OF stock_movements_partitioned
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
-- ... create partitions covering all historical months present in stock_movements ...

-- Step 2 (background job, batched, per Data Migrations): copy all existing rows
-- INSERT INTO stock_movements_partitioned SELECT * FROM stock_movements
--   WHERE id > :last_id AND id <= :last_id + :batch_size;

-- Step 3: application dual-writes to BOTH tables during the copy window (app-code change).

-- Step 4: once copy is confirmed caught up (row counts match, spot-checked), swap:
BEGIN;
  ALTER TABLE stock_movements RENAME TO stock_movements_old;
  ALTER TABLE stock_movements_partitioned RENAME TO stock_movements;
COMMIT;
-- stock_movements_old is retained for a cooling-off period (per Rollback,
-- Category 2), then dropped in a later contract migration.
```

This swap transaction holds a brief `ACCESS EXCLUSIVE` on both table names for the rename itself —
fast, catalog-only, no data movement — but the application must be deployed with retry-on-serialization-
error logic active for the handful of requests that may hit the rename mid-flight, and the swap is
scheduled during the low-traffic window described in "Large Tables" out of caution, even though the
rename itself is expected to complete in milliseconds.

**Example 5 — A five-worked-request API sanity check confirming a migration's effect is visible
end-to-end (used as the migration health check step in CI/CD, per that section).**

```json
// POST /api/v1/accounting/journal-entries — request, after cost_center_id is live
{
  "branch_id": 4,
  "entry_date": "2026-07-16",
  "memo": "Monthly rent accrual",
  "lines": [
    { "account_id": 5102, "debit_amount": "1250.0000", "credit_amount": "0.0000", "cost_center_id": 9, "currency_code": "KWD", "exchange_rate": "1.000000" },
    { "account_id": 2004, "debit_amount": "0.0000", "credit_amount": "1250.0000", "cost_center_id": 9, "currency_code": "KWD", "exchange_rate": "1.000000" }
  ]
}
```

```json
// 201 Created — response
{
  "success": true,
  "data": {
    "id": 88231,
    "status": "draft",
    "lines": [
      { "id": 501144, "account_id": 5102, "debit_amount": "1250.0000", "credit_amount": "0.0000", "cost_center_id": 9 },
      { "id": 501145, "account_id": 2004, "debit_amount": "0.0000", "credit_amount": "1250.0000", "cost_center_id": 9 }
    ]
  },
  "message": "Journal entry created.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "5b1e2f2a-9e4a-4a7b-9a1a-8e2f2b3c4d5e",
  "timestamp": "2026-07-16T12:03:11Z"
}
```

```json
// Attempting the same request with cost_center_id omitted, AFTER the contract-phase
// NOT NULL constraint has been enforced — confirms the migration's effect end-to-end.
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    { "field": "lines.0.cost_center_id", "code": "required", "message": "cost_center_id is required." },
    { "field": "lines.1.cost_center_id", "code": "required", "message": "cost_center_id is required." }
  ],
  "meta": { "pagination": null },
  "request_id": "9f3a1c4d-2b5e-4f6a-8c7d-1e2f3a4b5c6d",
  "timestamp": "2026-07-19T09:15:44Z"
}
```

The 422 response above is generated by the FormRequest validation layer, not by the database
constraint bubbling up as a raw SQL error — this is deliberate and worth restating: QAYD's database
constraints (per "Constraints") are a backstop against bugs and bypasses, not the primary UX-facing
validation mechanism. The FormRequest layer is expected to reject this request before it ever reaches
the database, and the database constraint existing underneath it is what QAYD's health-check step
verifies by also testing a raw, validation-bypassing insert against a non-production sandbox
connection to confirm the constraint itself, independent of the application layer, is truly in force
after the contract-phase migration completes — because a passing application-level test alone would
not prove the database-level invariant holds for every possible write path, including the ones the
FormRequest layer does not guard (a raw script, a future endpoint, an AI-agent-issued query that
somehow bypasses expected validation).

# End of Document
