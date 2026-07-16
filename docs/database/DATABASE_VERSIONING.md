# Database Versioning — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_VERSIONING
---

# Purpose

QAYD is an AI Financial Operating System built on PostgreSQL 15+, Laravel 12 (PHP 8.4+), Redis, and Cloudflare R2, exposed to every client — the Next.js 15 web application, the FastAPI AI layer, third-party integrators, and mobile clients — through a single versioned contract at `/api/v1/`. Because QAYD stores double-entry accounting data that is legally immutable once posted (`journal_entries`, `journal_lines`, `ledger_entries`), and because every business table is multi-tenant (`company_id NOT NULL`) with soft-deletes (`deleted_at`) rather than hard deletes, the database schema itself has a lifecycle that must be versioned, tracked, and reasoned about with the same rigor as the API and the application code.

This document defines how QAYD versions its database schema, how that schema version relates to Laravel's migration history, how schema changes propagate to (or are hidden from) `/api/v1` API consumers, how backward compatibility is preserved across releases, what qualifies as a breaking change, how releases are rolled out and rolled back, how Git branches and tags map onto schema state, how deployment pipelines sequence migrations against traffic cutover, how Semantic Versioning is applied uniformly across schema/API/application artifacts, and how individual rows are versioned for optimistic concurrency control and historical audit trails.

The governing principle across all of the above: **the database is the single source of truth for financial state, and schema evolution must never silently change the meaning of previously-posted data.** Every migration is additive-first, every breaking change is a deliberate, reviewed, communicated event, and every row that participates in financial reporting carries enough versioning metadata to reconstruct "what did this record look like at time T" without ambiguity.

This document assumes familiarity with `DATABASE_ARCHITECTURE.md` (physical schema), `DATABASE_STANDARDS.md` (column/table conventions), `MULTI_TENANCY.md` (company_id isolation), and `ROW_LEVEL_SECURITY.md` (RLS policies) in this same directory. It does not repeat those specifications; it defines the *temporal* dimension layered on top of them — how the schema, and the rows within it, change over time without breaking consumers.

## Why this is a distinct concern from "just running migrations"

Laravel ships a migration runner out of the box (`php artisan migrate`) and a `migrations` table that records which migration files have executed. That mechanism answers one question only: **"has this specific PHP migration class been applied to this specific database?"** It does not answer:

- "Which *logical* version of the schema is this database at, in a form that a human or an API client can reason about?" (e.g. "schema 2.4.0")
- "Is it safe to deploy application code version X against database schema version Y?"
- "If I roll back the application to the previous release, is the current schema still compatible?"
- "Did this migration change the *meaning* of a column that `/api/v1/accounting/journal-entries` returns, and do existing API consumers need to be notified?"
- "What did this specific `invoices` row look like three edits ago, and who changed it?"

QAYD therefore layers three additional mechanisms on top of Laravel's migration runner:

1. **A `schema_versions` table** — a logical, human-readable, SemVer-tagged checkpoint of schema state, updated once per release (not once per migration file).
2. **An API compatibility contract** (`/api/v1`, `/api/v2`, …) that is deliberately decoupled from the physical schema via Eloquent API Resources, so that most schema changes are invisible to API consumers.
3. **Row-level versioning** (`row_version` optimistic-lock columns plus `*_history` tables) so that individual financial records carry their own temporal audit trail independent of schema migrations.

The remainder of this document specifies each of these mechanisms precisely, with DDL, Laravel code, CI/CD pipeline steps, and worked examples.

# Schema Versions

## Concept

A **schema version** is a SemVer triple (`MAJOR.MINOR.PATCH`) that represents the cumulative state of the database schema at a specific point in the Git history and in the deployed environment. It is coarser-grained than a migration: a single release might ship fifteen migration files, but it bumps the schema version exactly once. Schema versions are:

- Recorded in a dedicated `schema_versions` table (distinct from Laravel's `migrations` table, which is an implementation detail of the migration runner and must never be treated as the authoritative version record).
- Tagged in Git as `db-vX.Y.Z` at the commit where the release's final migration merges to `main`.
- Referenced in deployment manifests, so that a given application release explicitly declares the minimum and maximum schema versions it is compatible with.
- Queryable at runtime, so the application can refuse to boot (or degrade gracefully) if the connected database's schema version falls outside the range the running code expects.

## DDL

```sql
CREATE TABLE schema_versions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    version         VARCHAR(20)  NOT NULL UNIQUE,          -- e.g. '2.4.0'
    major           INTEGER      NOT NULL,
    minor           INTEGER      NOT NULL,
    patch           INTEGER      NOT NULL,
    git_tag         VARCHAR(60)  NOT NULL,                 -- e.g. 'db-v2.4.0'
    git_commit_sha  VARCHAR(40)  NOT NULL,
    migration_batch_start BIGINT NOT NULL,                 -- first Laravel migration batch in this release
    migration_batch_end   BIGINT NOT NULL,                 -- last Laravel migration batch in this release
    api_min_version VARCHAR(10)  NOT NULL DEFAULT 'v1',     -- minimum API version this schema supports
    api_max_version VARCHAR(10)  NOT NULL DEFAULT 'v1',     -- maximum API version this schema supports
    is_breaking     BOOLEAN      NOT NULL DEFAULT false,
    released_by     BIGINT       NULL REFERENCES users(id),
    released_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    changelog       TEXT         NOT NULL,
    rollback_notes  TEXT         NULL,
    CONSTRAINT chk_semver_non_negative CHECK (major >= 0 AND minor >= 0 AND patch >= 0)
);

CREATE UNIQUE INDEX idx_schema_versions_semver ON schema_versions (major, minor, patch);
CREATE INDEX idx_schema_versions_released_at ON schema_versions (released_at DESC);
```

`schema_versions` is written to exactly once per release, by the deployment pipeline, immediately after the final migration in the release's batch completes successfully and before traffic is cut over to the new application code (see **Deployment Strategy**). It is never written to by developers manually, and never by an Eloquent model in normal request handling — it is a release-time artifact, populated by a dedicated Artisan command:

```php
// app/Console/Commands/RecordSchemaVersion.php
namespace App\Console\Commands;

use App\Models\SchemaVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecordSchemaVersion extends Command
{
    protected $signature = 'schema:record-version
        {version : SemVer string, e.g. 2.4.0}
        {--tag= : Git tag, defaults to db-v{version}}
        {--breaking : Mark this release as containing breaking changes}
        {--changelog= : Path to a changelog markdown file for this release}';

    protected $description = 'Record a schema version checkpoint after migrations complete';

    public function handle(): int
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', $this->argument('version')));
        $tag = $this->option('tag') ?: "db-v{$this->argument('version')}";
        $sha = trim(shell_exec('git rev-parse HEAD') ?? '');
        $batchRange = DB::table('migrations')->selectRaw('MIN(batch) as min, MAX(batch) as max')->first();
        $changelog = $this->option('changelog')
            ? file_get_contents($this->option('changelog'))
            : "Release {$this->argument('version')} recorded via CLI.";

        SchemaVersion::create([
            'version'                => $this->argument('version'),
            'major'                  => $major,
            'minor'                  => $minor,
            'patch'                  => $patch,
            'git_tag'                => $tag,
            'git_commit_sha'         => $sha,
            'migration_batch_start'  => $batchRange->min,
            'migration_batch_end'    => $batchRange->max,
            'is_breaking'            => (bool) $this->option('breaking'),
            'released_by'            => null, // set by CI service account elsewhere
            'changelog'              => $changelog,
        ]);

        $this->info("Recorded schema version {$this->argument('version')} (tag {$tag}).");
        return self::SUCCESS;
    }
}
```

## Compatibility matrix

QAYD maintains an explicit matrix mapping schema version ranges to API versions and minimum application release lines. This matrix is generated from `schema_versions.api_min_version` / `api_max_version` and is exposed at `GET /api/v1/system/compatibility` for operational tooling and for the FastAPI AI layer to self-check before making Laravel API calls:

| Schema Version | API Versions Supported | App Release Line | Notes |
|---|---|---|---|
| 1.0.0 – 1.9.x | `v1` | 1.x | Initial GA schema |
| 2.0.0 – 2.3.x | `v1` | 2.x | Additive only; `v1` clients unaffected |
| 2.4.0 – 2.x.x | `v1`, `v2` | 2.x, 3.x | `customers.name` split into `name_en`/`name_ar` (see Examples); `v1` reads a compatibility view |
| 3.0.0+ | `v2` only | 4.x+ | `v1` sunset; compatibility view dropped |

The application reads its own expected schema range from `config/database_versioning.php` and calls a boot-time check (see **API Compatibility**) so that a misconfigured deployment fails fast and loudly rather than corrupting data or serving malformed responses.

# Migration Versions

## Laravel's migration ledger

Laravel's own `migrations` table is the low-level, per-file ledger:

```sql
-- Created automatically by Laravel; documented here for completeness.
CREATE TABLE migrations (
    id        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch     INTEGER      NOT NULL
);
```

Every `php artisan migrate` invocation groups all pending migration files into a single **batch** number, incremented from the previous maximum. `php artisan migrate:rollback` undoes exactly one batch by default (`--step=N` undoes N batches). QAYD relies on batches as the atomic unit of a deploy's schema change: **one deployment = one migration batch**, enforced by never running `migrate` twice between two `schema:record-version` calls.

## File naming and organization

Migration files follow Laravel's standard timestamp-prefixed convention, but QAYD adds a mandatory module prefix in the class/description for discoverability, since a single release can touch Accounting, Sales, Inventory, and Payroll simultaneously:

```
database/migrations/
├── 2026_01_10_090000_create_companies_table.php
├── 2026_01_10_090100_create_branches_table.php
├── 2026_02_03_120000_create_accounting_accounts_table.php
├── 2026_02_03_120100_create_accounting_account_types_table.php
├── 2026_02_03_120200_create_accounting_journal_entries_table.php
├── 2026_02_03_120300_create_accounting_journal_lines_table.php
├── 2026_03_15_081500_create_sales_invoices_table.php
├── 2026_03_15_081600_create_sales_invoice_items_table.php
├── 2026_04_22_101000_add_row_version_to_journal_entries_table.php
├── 2026_05_01_070000_create_journal_entries_history_table.php
└── 2026_06_18_133000_split_customers_name_into_name_en_name_ar.php
```

Naming rule: `<module>_<verb>_<subject>_table.php` — e.g. `accounting_create_journal_entries_table` is preferred over a bare `create_journal_entries_table` once more than one module could plausibly own a `journal_entries`-shaped concept, even though in QAYD's canonical table list `journal_entries` is owned exclusively by Accounting. The migration **class name** always matches the file (Laravel 12 convention: anonymous class returned from the file, so the file name is authoritative).

## Anatomy of a QAYD migration

Every migration that touches a tenant-scoped table must, per `DATABASE_STANDARDS.md`, include the standard columns, and per this document, must be written to be reversible unless explicitly declared irreversible (see **Rollback**). A representative "create table" migration:

```php
<?php
// database/migrations/2026_02_03_120200_create_accounting_journal_entries_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('entry_number', 30);
            $table->date('entry_date');
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->string('status', 20)->default('draft'); // draft|posted|reversed|voided
            $table->string('source_type', 40)->nullable();  // e.g. 'invoice','manual','payroll_run'
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('memo')->nullable();
            $table->integer('row_version')->default(1);     // optimistic lock, see Row/Record Versioning
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'entry_number']);
            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT chk_journal_status
            CHECK (status IN ('draft','posted','reversed','voided'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
```

## Migration path organization for a modular monolith

Laravel supports registering additional migration paths per service provider, which QAYD uses so that each module (`Accounting`, `Sales`, `Purchasing`, `Inventory`, `Payroll`, `Tax`) can own its migrations directory under its own module folder while still running through the single unified `migrations` ledger table:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->loadMigrationsFrom([
        database_path('migrations'),                    // foundation
        base_path('modules/Accounting/database/migrations'),
        base_path('modules/Sales/database/migrations'),
        base_path('modules/Purchasing/database/migrations'),
        base_path('modules/Inventory/database/migrations'),
        base_path('modules/Payroll/database/migrations'),
        base_path('modules/Tax/database/migrations'),
    ]);
}
```

This keeps ownership boundaries clear (Accounting engineers do not need write access to Payroll's migration directory to review or merge) while migration *execution order* still respects the global timestamp prefix across all registered paths, which is why the timestamp — not directory — is the true ordering key. A convention QAYD enforces via CI linting: two migrations across different module directories must never share a timestamp collision; the CI check runs `php artisan migrate:status --pretend` against a fresh schema and fails the build on duplicate timestamps.

## Schema dumping and squashing

Over many releases, replaying hundreds of historical migration files against a fresh database becomes slow and is rarely what anyone actually wants (nobody needs to replay the 2026-Q1 `journal_entries` table shape before immediately altering it fourteen times). Laravel's schema dump mechanism is QAYD's squashing strategy:

```bash
php artisan schema:dump --prune
```

This writes `database/schema/pgsql-schema.sql` (a raw `pg_dump`-generated snapshot of the current schema) and deletes the migration files it consumed, *except* any migration file dated after the most recent tagged release remains untouched so in-flight release branches are unaffected. QAYD's policy: **squash only immediately after a MAJOR schema version release, never mid-release-cycle**, and always tag the pre-squash state in Git (`db-vX.Y.Z-pre-squash`) so historical migration text remains recoverable from Git history even after the working tree no longer contains those files. Fresh environments (new developer machines, ephemeral CI databases, disaster-recovery rebuilds) run:

```bash
php artisan migrate:fresh   # loads database/schema/pgsql-schema.sql first, then replays only post-squash migrations
```

## Zero-downtime migration pattern

Because QAYD runs multiple Laravel application instances behind a load balancer with rolling deploys, a migration that both changes shape and is applied atomically with a code deploy risks a window where old code runs against new schema (or vice versa). QAYD's standard pattern for any migration that is not a pure additive change (new nullable column, new table, new index) is the **three-phase expand/contract**:

1. **Expand** (release N): add the new column/table alongside the old one. Both old and new code paths work. New code starts *dual-writing* to both.
2. **Migrate** (release N, background job): backfill historical rows from old shape to new shape via a queued job, not inline in the migration (a migration must never run an unbounded `UPDATE` over a multi-million-row `journal_lines` table inside a transaction that holds a lock for the duration).
3. **Contract** (release N+2 or later, after confirming zero reads of the old shape via query logging): drop the old column/table in a follow-up migration.

```php
<?php
// Phase 1 (Expand) — 2026_06_18_133000_split_customers_name_into_name_en_name_ar.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name_en', 255)->nullable()->after('name');
            $table->string('name_ar', 255)->nullable()->after('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'name_ar']);
        });
    }
};
```

```php
<?php
// Phase 3 (Contract) — 2026_09_02_090000_drop_customers_legacy_name_column.php
// Only merged after release N+2's monitoring confirms zero reads of `customers.name`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->after('id');
        });
        // Note: down() cannot recover the original data once dropped; see Rollback for the
        // pre-contract backup requirement.
    }
};
```

# API Compatibility

## Decoupling the wire format from the schema

`/api/v1/` is a contract with external consumers (the Next.js frontend, third-party integrators, mobile apps, and the FastAPI AI layer). The physical database schema is an internal implementation detail. QAYD enforces the separation through **Laravel API Resources** on every response and **Form Requests** on every write, so that the large majority of schema changes — renamed internal columns, added nullable fields, normalized child tables, index changes, partitioning — never touch the API contract at all.

```php
// app/Http/Resources/V1/CustomerResource.php
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->display_name(), // computed accessor, schema-agnostic
            'email'       => $this->primary_email(),
            'status'      => $this->status,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}

// app/Models/Customer.php
public function display_name(): string
{
    // During the name_en/name_ar migration window, v1 continues to see a single `name`
    // field synthesized from the new bilingual columns — the schema changed, the contract did not.
    return match (app()->getLocale()) {
        'ar' => $this->name_ar ?? $this->name_en ?? $this->getRawOriginal('name'),
        default => $this->name_en ?? $this->getRawOriginal('name'),
    };
}
```

This is the mechanism by which `schema_versions.api_min_version`/`api_max_version` can legitimately show a schema bump (2.3.x → 2.4.0) with **no** API version bump: the resource layer absorbed the change.

## Versioned routes, controllers, and Form Requests

When a change *cannot* be absorbed by the resource layer — because the new shape is semantically different, not just differently stored — QAYD introduces a new API major version rather than mutating `v1`'s meaning:

```php
// routes/api.php
Route::prefix('v1')->middleware(['auth:sanctum', 'company.context'])->group(function () {
    Route::apiResource('accounting/journal-entries', \App\Http\Controllers\V1\JournalEntryController::class);
});

Route::prefix('v2')->middleware(['auth:sanctum', 'company.context'])->group(function () {
    Route::apiResource('accounting/journal-entries', \App\Http\Controllers\V2\JournalEntryController::class);
});
```

Both controllers can read from the *same* underlying tables; they differ only in their Resource classes and FormRequest validation rules. `V1\JournalEntryController` continues to accept and return the `v1` shape (e.g., a single flat `memo` string) while `V2\JournalEntryController` might expose a new `attachments[]` relation added in schema 3.0.0. Both controllers, both versions, one `journal_entries` table.

## Boot-time compatibility check

To prevent a deployment mistake — new application code accidentally pointed at an old, un-migrated database, or a rollback of application code left pointed at a database that has already migrated forward — QAYD runs a compatibility assertion during application boot in non-local environments:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
        return;
    }

    if (app()->environment('local')) {
        return;
    }

    $current = DB::table('schema_versions')->orderByDesc('id')->first();
    $expected = config('database_versioning.min_schema_version'); // e.g. '2.4.0'

    if (! $current || version_compare($current->version, $expected, '<')) {
        Log::critical('Schema version mismatch: app requires >= ' . $expected . ', found ' . ($current->version ?? 'none'));
        abort(503, 'Database schema out of date for this application version.');
    }
}
```

```php
// config/database_versioning.php
return [
    'min_schema_version' => env('DB_SCHEMA_MIN_VERSION', '2.4.0'),
    'max_schema_version' => env('DB_SCHEMA_MAX_VERSION', '2.99.99'),
    'current_api_version' => 'v1',
];
```

## Deprecation signaling

Endpoints scheduled for removal (e.g., `v1` after `v2` GA) return standard deprecation headers so integrators have machine-readable warning ahead of the sunset date, consistent with the standard response envelope's `meta` block:

```json
{
  "success": true,
  "data": { "id": 4821, "name": "Al-Rawda Trading Co." },
  "message": "OK",
  "errors": [],
  "meta": {
    "pagination": null,
    "deprecation": {
      "deprecated": true,
      "sunset": "2027-01-31T00:00:00Z",
      "successor_version": "v2",
      "docs_url": "https://docs.qayd.ai/api/migration/v1-to-v2"
    }
  },
  "request_id": "b3e2c1a0-6f2e-4b8a-9c1d-2a7e5f9d0c11",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

```php
// app/Http/Middleware/DeprecationHeaders.php
class DeprecationHeaders
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        if (str_starts_with($request->path(), 'api/v1/')) {
            $response->headers->set('Deprecation', 'true');
            $response->headers->set('Sunset', 'Sun, 31 Jan 2027 00:00:00 GMT');
            $response->headers->set('Link', '<https://docs.qayd.ai/api/migration/v1-to-v2>; rel="successor-version"');
        }
        return $response;
    }
}
```

# Backward Compatibility

## Additive-first rule

The default posture for every migration is **additive**: new tables, new nullable columns with sane defaults, new indexes, new enum values appended (never removed or renumbered). An additive migration, by construction, cannot break any query, Eloquent model, or API resource that predates it, because nothing that existed before has changed shape or meaning.

```sql
-- Backward-compatible: adding a nullable column with a default.
ALTER TABLE invoices ADD COLUMN due_date_reminder_sent BOOLEAN NOT NULL DEFAULT false;

-- Backward-compatible: adding an index does not change query results, only performance.
CREATE INDEX CONCURRENTLY idx_invoices_due_date_reminder
    ON invoices (company_id, due_date_reminder_sent)
    WHERE deleted_at IS NULL AND status = 'sent';
```

Note `CREATE INDEX CONCURRENTLY`: QAYD requires the `CONCURRENTLY` keyword for any index added to a table expected to exceed 100k rows in production (all core accounting/sales/inventory tables qualify), because a plain `CREATE INDEX` takes an `ACCESS EXCLUSIVE` lock that blocks writes for the duration of the build — unacceptable on a live financial ledger. `CONCURRENTLY` cannot run inside the transaction Laravel wraps each migration in by default, so such migrations must disable the implicit transaction:

```php
<?php
return new class extends Migration
{
    public $withinTransaction = false; // required for CREATE INDEX CONCURRENTLY

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_due_date_reminder
            ON invoices (company_id, due_date_reminder_sent)
            WHERE deleted_at IS NULL AND status = \'sent\'');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_invoices_due_date_reminder');
    }
};
```

## Renames are never renames

QAYD never issues `ALTER TABLE ... RENAME COLUMN` on a table an already-deployed application version reads from, because a rename is atomically both a removal (old name disappears) and an addition (new name appears) — it is backward-incompatible by definition for any code still referencing the old name, including in-flight requests on servers not yet rolled to the new code during a rolling deploy. Instead:

1. Add the new column (Expand).
2. Dual-write both columns from application code for one full release cycle.
3. Backfill historical rows via queued job.
4. Switch reads to the new column (still writing both).
5. Stop writing the old column (Migrate complete; monitor).
6. Drop the old column in a later release (Contract).

## Default values must never change retroactive meaning

A default value backfills *new* rows only, at the row's creation time. Changing a column's default in a migration does **not** retroactively alter existing rows — Postgres does not rewrite them — but QAYD's review checklist explicitly flags any `ALTER COLUMN ... SET DEFAULT` on a financial table for a second reviewer, because a downstream report or the AI layer's few-shot reasoning may implicitly assume the *old* default when interpreting historical `NULL`s versus explicit values.

## Backward-compatible enum growth

Status-like columns (`invoices.status`, `journal_entries.status`, `stock_movements.type`) are implemented as `VARCHAR` with a `CHECK` constraint rather than native Postgres `ENUM` types, specifically because native enums cannot have values removed and are awkward to reorder, while `CHECK` constraints can be dropped and recreated with an expanded value list in a simple additive migration:

```sql
-- Backward-compatible enum growth: existing rows and existing code paths are unaffected.
ALTER TABLE invoices DROP CONSTRAINT chk_invoices_status;
ALTER TABLE invoices ADD CONSTRAINT chk_invoices_status
    CHECK (status IN ('draft','sent','partially_paid','paid','overdue','void','disputed'));
    -- 'disputed' is new in schema 2.6.0; old code that never emits 'disputed' keeps working.
```

## Views as a compatibility shim

When a table's physical shape must change ahead of every consumer being updated, QAYD creates a compatibility view presenting the old shape, backed by the new tables, so that reporting queries, BI tools, or slow-to-migrate integrations keep functioning without code changes:

```sql
-- Physical table now stores bilingual names (schema 2.4.0+).
-- Legacy consumers of a flat "name" column read this view instead of the base table.
CREATE VIEW customers_v1_compat AS
SELECT
    id, company_id, branch_id,
    COALESCE(name_en, name_ar) AS name,   -- legacy single-name shape
    name_en, name_ar,
    created_at, updated_at, deleted_at
FROM customers;
```

Compatibility views are tracked in `schema_versions.changelog` with an explicit removal target release, and CI includes a periodic job that queries `pg_stat_statements`/application logs for any access to the view so the team knows when it is safe to drop.

# Breaking Changes

## Definition

A change is **breaking** if any currently-supported API version's documented contract, or any already-posted financial record's meaning, changes as a result. Concretely, QAYD classifies the following as breaking, requiring a MAJOR schema version bump and (if API-visible) a new API version:

| Category | Example | Why it breaks consumers |
|---|---|---|
| Column removal | Dropping `customers.name` before all readers migrated | Queries/ORM mappings referencing it fail |
| Column type change | `invoices.amount` from `NUMERIC(19,4)` to `NUMERIC(19,2)` | Silent precision loss in financial data |
| Column semantic change | Redefining `journal_lines.amount` sign convention (debit-positive vs. signed) | Every historical report and reconciliation is now wrong |
| Enum value removal | Removing `'draft'` from `journal_entries.status` allowed values | Existing draft rows become constraint-invalid on next write |
| NOT NULL tightening on existing column | Making `invoices.due_date` NOT NULL when historical NULL rows exist | Existing rows violate the new constraint; migration itself fails without a backfill |
| Uniqueness tightening | Adding a UNIQUE constraint where duplicate historical data exists | Migration fails or silently drops "duplicate" rows depending on strategy |
| Foreign key ON DELETE behavior change | Changing `ON DELETE SET NULL` to `ON DELETE CASCADE` on `journal_lines.account_id` | Deleting an account could now silently delete ledger history |
| API field removal/rename in a response | Removing `invoice.balance_due` from the `v1` JSON shape | Deployed frontends and integrators throw or misrender |
| API field meaning change | `invoice.status` gaining a new value that older client code doesn't branch on | Client-side state machines can enter an unhandled state |

## Non-breaking despite appearances

Some changes look risky but are not breaking under QAYD's definition, and are explicitly called out in code review guidance to avoid over-cautious MAJOR bumps:

- Adding a nullable column, even to a huge table.
- Adding a new table.
- Adding a new index (performance-only).
- Widening a numeric column's precision/scale (e.g. `NUMERIC(18,2)` → `NUMERIC(19,4)`) as long as no downstream rounding logic assumed the old scale.
- Adding a new enum/CHECK-allowed value, as long as no client-side switch/match statement is exhaustive over the old set in a way that throws on an unrecognized value (QAYD's front-end and FastAPI layers are required to have a default/`else` branch on every status field for exactly this reason).
- Adding a new required field to a *request* body when the field has a server-side default applied when absent (functionally optional even though the OpenAPI schema marks it required for new integrations).

## Process for a breaking change

1. **RFC.** The originating team writes a short RFC referencing the exact tables/columns/API fields affected, the two-tier consumer list (internal app, third-party integrators, AI layer), and the proposed migration path window. Reviewed by at least one Backend and one Product/API owner.
2. **Version decision.** MAJOR schema bump (e.g., `2.9.4` → `3.0.0`) is proposed; if API-visible, a MAJOR API version bump (`v1` → `v2`) accompanies it — schema MAJOR and API MAJOR are not required to move in lockstep (a schema MAJOR can be entirely internal), but an API MAJOR always corresponds to at least a schema MINOR that adds the new-shape support.
3. **Dual-support window.** Old and new shapes/endpoints coexist for a minimum of one full release train (typically 4-6 weeks) — see **Release Strategy**.
4. **Communication.** Changelog entry in `schema_versions.changelog`, developer-facing migration guide published at `docs.qayd.ai/api/migration/<old>-to-<new>`, deprecation headers activated (see **API Compatibility**), and a direct notice to any registered webhook/integration partners.
5. **Cutover.** After the dual-support window and confirmed zero traffic on the old shape (via access logging), the Contract-phase migration removes the old shape and the old API version is formally sunset (returns `410 Gone` with a pointer to the migration guide, retained for at least 90 days before route removal).

## Worked breaking-change example (summarized here; full version in Examples)

Splitting `customers.name` into `customers.name_en` / `customers.name_ar` was executed as **non-breaking** at the API layer (absorbed by `CustomerResource::display_name()`) but is recorded as a schema-level "soft-breaking" event because direct SQL/BI consumers reading the base table were affected; the compatibility view (`customers_v1_compat`) mitigated it without requiring an API version bump. Contrast this with a hypothetical redefinition of `journal_lines.amount`'s sign convention, which cannot be absorbed by any resource-layer shim because it would require re-deriving every historical trial balance — that class of change mandates a MAJOR schema version, a parallel `journal_lines_v2` table populated by a backfill job, dual-posting during the transition, and a formal reconciliation sign-off from Finance before the old table is ever dropped.

# Release Strategy

## Release train cadence

QAYD ships on a **train model**: PATCH releases (bug fixes, non-schema or additive-only schema changes) can ship any weekday via the standard CI/CD pipeline; MINOR releases (new additive schema, new non-breaking API fields, new modules) ship on a two-week cadence; MAJOR releases (any breaking change) ship on a quarterly cadence with a minimum four-week dual-support window baked into the schedule.

| Release type | Schema version bump | Cadence | Dual-support window required |
|---|---|---|---|
| Patch | PATCH (2.4.0 → 2.4.1) | Continuous (as needed, any weekday) | No |
| Minor | MINOR (2.4.1 → 2.5.0) | Every 2 weeks | No (additive) |
| Major | MAJOR (2.9.x → 3.0.0) | Quarterly | Yes, minimum 4 weeks |

## Feature flags for schema-adjacent behavior

New behavior that depends on a not-yet-fully-backfilled column is gated behind a feature flag stored in a dedicated table (not `.env`, so it can be toggled per-company without a deploy):

```sql
CREATE TABLE feature_flags (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key           VARCHAR(100) NOT NULL UNIQUE,
    description   TEXT NOT NULL,
    is_global     BOOLEAN NOT NULL DEFAULT false,
    global_value  BOOLEAN NOT NULL DEFAULT false,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE feature_flag_overrides (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    feature_flag_id  BIGINT NOT NULL REFERENCES feature_flags(id),
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    value            BOOLEAN NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (feature_flag_id, company_id)
);
```

```php
// Usage in application code guarding a not-yet-fully-migrated read path:
if (Feature::for($companyId)->active('customers_use_bilingual_name_v2')) {
    $name = $customer->name_en ?? $customer->name_ar;
} else {
    $name = $customer->getRawOriginal('name');
}
```

This allows the backfill job to complete company-by-company and the flag to be flipped on per-tenant, catching data issues on a small subset of companies before a global rollout — critical given QAYD's multi-tenant blast radius.

## Canary and blue-green sequencing

Application deploys use blue-green cutover at the load-balancer level; database migrations are decoupled from that cutover and always run to completion *before* any traffic reaches the new (green) application fleet, per the **Deployment Strategy** section. A release is only promoted to "current" for canary traffic (5% of companies, selected by a hash of `company_id`) after migrations, `schema:record-version`, and smoke tests pass; canary is held for 30-60 minutes before full rollout, monitored via the `get_advisors`-style database health checks and application error-rate dashboards.

# Rollback

## Migration-level rollback

Every migration must implement a correct `down()` unless it is explicitly declared irreversible (see below). Standard rollback of the most recent deployment's batch:

```bash
php artisan migrate:rollback --step=1
```

QAYD's CI pipeline verifies rollback correctness automatically: for every PR touching `database/migrations/`, a CI job runs `migrate` then `migrate:rollback` then `migrate` again against a fresh ephemeral database and asserts the final schema hash (via `pg_dump --schema-only | sha256sum`) equals the hash obtained by running `migrate` alone from a clean state — proving `down()` is a true inverse of `up()`, not merely present.

## Irreversible migrations

Some migrations are inherently destructive to roll back cleanly (e.g., a Contract-phase column drop, or a data transformation that collapses information, such as merging duplicate customer records). These are explicitly flagged:

```php
<?php
return new class extends Migration
{
    /**
     * IRREVERSIBLE: dropping `customers.name` discards data that was superseded by
     * name_en/name_ar in schema 2.4.0 and confirmed unused for 90+ days (see schema_versions
     * changelog for 2.4.0 and 3.0.0). A full pg_dump snapshot was taken and archived to
     * s3://qayd-db-backups/pre-contract/2026-09-02-customers-name-drop.sql.gz before this
     * migration ran in production. Restoring the column requires restoring from that snapshot,
     * not running this migration's down().
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'This migration is irreversible in-place. Restore from ' .
            's3://qayd-db-backups/pre-contract/2026-09-02-customers-name-drop.sql.gz'
        );
    }
};
```

Policy: **no irreversible migration may run in production without a verified, restorable backup taken immediately beforehand**, referenced by exact path/identifier in the migration's docblock and in the corresponding `schema_versions.rollback_notes` field. The deployment pipeline enforces this by requiring an `irreversible: true` flag in the release manifest to be accompanied by a `backup_snapshot_id` field before it will proceed.

## Application-level rollback compatibility

Because Expand/Migrate/Contract spans multiple releases, rolling back the *application code* to the previous release (a much more common operation than rolling back the *schema*) must always be safe against the *current* schema state — this is the entire point of the additive-first and dual-write disciplines. QAYD's rule: **you may always roll the app back one release without a schema rollback; rolling the app back further, or rolling the schema back at all, requires the on-call engineer to consult `schema_versions` for the target commit's compatible range and to explicitly run `migrate:rollback` to match.**

## Point-in-time recovery as the ultimate rollback

For catastrophic scenarios beyond migration rollback's scope (a Contract-phase migration ran with a bug, a backfill job corrupted data across millions of rows), QAYD's AWS RDS PostgreSQL instance has continuous WAL archiving enabled with a 35-day retention window, enabling point-in-time recovery to any second within that window:

```bash
aws rds restore-db-instance-to-point-in-time \
  --source-db-instance-identifier qayd-prod-primary \
  --target-db-instance-identifier qayd-prod-pitr-restore \
  --restore-time 2026-09-02T03:14:00Z \
  --db-instance-class db.r6g.2xlarge

# Restored instance is validated in isolation, then a decision is made:
# (a) promote it and re-point the application (major incident, requires maintenance window), or
# (b) extract only the affected rows/tables and reconcile them into the live primary
#     via a reviewed, audited one-off script — the preferred path for a narrow blast radius.
```

Nightly logical `pg_dump` snapshots (in addition to WAL-based PITR) are retained for 1 year in Cloudflare R2 (`qayd-db-backups` bucket) as a secondary, format-portable backup independent of the RDS/AWS toolchain, satisfying QAYD's cross-cloud disaster recovery requirement.

## Rollback runbook (abbreviated)

1. Declare incident; freeze deploys.
2. Identify last-known-good schema version from `schema_versions` and last-known-good app release tag.
3. If app-only rollback suffices (schema is backward-compatible with the prior release, per the compatibility matrix): redeploy previous app image; no migration action.
4. If schema rollback is required: run `php artisan migrate:rollback --step=N` for the affected batch(es) on a maintenance-mode replica first to validate `down()` correctness against production-shaped data volume, then execute against primary during a declared maintenance window.
5. If the migration was irreversible: initiate PITR restore procedure above; do not attempt manual data reconstruction under incident time pressure.
6. Record a new `schema_versions` row reflecting the rolled-back state with `rollback_notes` populated, and open a postmortem.

# Git Integration

## Branch and tag strategy

- `main` — always deployable; every merge to `main` that includes new migration files triggers the CI migration-safety checks described above.
- `release/X.Y` — cut from `main` at feature-freeze for a MINOR or MAJOR release; only cherry-picked fixes land here after cut.
- Git tags:
  - `db-vX.Y.Z` — schema version tag, created by the deployment pipeline at the exact commit whose migrations produced that schema state, matching the `schema_versions.git_tag` / `git_commit_sha` columns exactly. This tag is the canonical, queryable link between "a row in `schema_versions`" and "a point in source history."
  - `app-vX.Y.Z` — application release tag, may or may not coincide with a new `db-v` tag (a pure app-logic PATCH release ships no migrations and thus no new schema tag).
  - `db-vX.Y.Z-pre-squash` — snapshot tag created immediately before a `schema:dump --prune` squash operation, preserving the full migration file history in Git even after the working tree's `database/migrations/` directory is pruned.

## Pull request requirements for migration files

Any PR that adds or modifies a file under `database/migrations/` or any `modules/*/database/migrations/` path must, per QAYD's CI configuration and branch protection rules:

1. Include a `down()` method or an explicit `IRREVERSIBLE` docblock (enforced by a static-analysis check scanning the migration's AST — a bare `down(): void {}` with no body and no docblock fails the check).
2. Pass the `migrate → rollback → migrate` schema-hash-equality CI job described in **Rollback**.
3. Include an entry appended to `CHANGELOG_SCHEMA.md` at the repo root describing the change in one sentence, tagged `[additive]`, `[soft-breaking]`, or `[breaking]`.
4. If tagged `[breaking]`, link to the RFC document and carry an explicit sign-off label (`schema-breaking-approved`) applied by a Backend lead before merge is permitted — enforced via a required GitHub status check, not merely a convention.
5. If the migration touches a table with Row Level Security policies (per `ROW_LEVEL_SECURITY.md`), the PR must also show the corresponding `CREATE POLICY`/`ALTER POLICY` statement in the same migration or an immediately adjacent one in the same batch — RLS policy drift from schema shape is treated as a P0 security bug class.

## CI pipeline stages relevant to versioning

```yaml
# .github/workflows/db-migrations.yml (excerpt)
jobs:
  migration-safety:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:15
        env: { POSTGRES_PASSWORD: ci, POSTGRES_DB: qayd_ci }
    steps:
      - uses: actions/checkout@v4
      - name: Install dependencies
        run: composer install --no-interaction
      - name: Run migrations forward
        run: php artisan migrate --force
      - name: Snapshot schema hash (forward)
        run: pg_dump --schema-only -d qayd_ci | sha256sum > forward.hash
      - name: Roll back last batch
        run: php artisan migrate:rollback --step=1
      - name: Re-run migrations
        run: php artisan migrate --force
      - name: Snapshot schema hash (post-rollback-replay)
        run: pg_dump --schema-only -d qayd_ci | sha256sum > replay.hash
      - name: Compare hashes
        run: diff forward.hash replay.hash
      - name: Lint migration naming and timestamps
        run: php artisan schema:lint-migrations --fail-on-collision
```

## Post-merge and release tagging automation

```bash
# scripts/tag-schema-release.sh — run by CI immediately after a successful production
# migration step, before traffic cutover.
#!/usr/bin/env bash
set -euo pipefail
VERSION="$1"   # e.g. 2.4.0
SHA="$(git rev-parse HEAD)"

php artisan schema:record-version "$VERSION" --tag="db-v${VERSION}" \
  --changelog="CHANGELOG_SCHEMA.md"

git tag -a "db-v${VERSION}" -m "Schema version ${VERSION} at ${SHA}"
git push origin "db-v${VERSION}"
```

# Deployment Strategy

## Ordering: migrate before cutover, always

QAYD's non-negotiable deployment invariant: **schema migrations for a release always complete successfully, in production, before any application server running that release's new code receives live traffic.** This eliminates an entire class of incidents where new code assumes a column exists that migrations haven't yet created on the instance it happens to hit during a rolling deploy.

```
Pipeline stages (simplified):
1. Build application image (tag: app-vX.Y.Z)
2. Deploy image to a single "migrator" job (not a traffic-serving instance)
3. migrator job: php artisan migrate --force  (advisory-locked, see below)
4. migrator job: php artisan schema:record-version X.Y.Z --tag=db-vX.Y.Z
5. migrator job: run smoke test suite against migrated schema (read-only queries)
6. If 3-5 succeed: begin blue-green swap of traffic-serving fleet to app-vX.Y.Z
7. If 3-5 fail: abort deploy, page on-call, schema remains at previous state (no traffic
   was ever exposed to the new code, so no rollback of *application* state is needed —
   only the migration rollback procedure above applies, if the migration itself must be undone)
8. Canary 5% of companies for 30-60 minutes; monitor error rates and `get_advisors` checks
9. Full rollout to 100% of traffic-serving fleet
10. Retire blue fleet after a soak period (typically 2 hours)
```

## Preventing concurrent migration runs

Because QAYD's deployment pipeline can, in rare overlapping-release scenarios, trigger more than one `migrate --force` invocation against the same production database, a PostgreSQL advisory lock guards the migration step so a second concurrent invocation waits (or fails fast) rather than corrupting the `migrations` ledger via a race:

```php
// app/Console/Commands/SafeMigrate.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SafeMigrate extends Command
{
    protected $signature = 'migrate:safe {--force}';
    protected $description = 'Run migrations under a Postgres advisory lock to prevent concurrent runs';

    private const LOCK_KEY = 872364501; // arbitrary, stable bigint for this app's migration lock

    public function handle(): int
    {
        $acquired = DB::selectOne('SELECT pg_try_advisory_lock(?) AS locked', [self::LOCK_KEY])->locked;

        if (! $acquired) {
            $this->error('Another migration process holds the advisory lock. Aborting.');
            return self::FAILURE;
        }

        try {
            $this->call('migrate', ['--force' => true]);
            return self::SUCCESS;
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }
    }
}
```

## Migration timeout and lock-wait configuration

Long-running DDL (adding a `NOT NULL` column with a backfill, for example) can queue behind long-running transactions and, in turn, queue every other query behind itself once it acquires its lock. QAYD sets conservative statement and lock timeouts specifically for the migration session (not globally, which would break legitimate long-running reports) via a dedicated Postgres role:

```sql
-- Applied once to the migrator role, not the application's normal connection-pool role.
ALTER ROLE qayd_migrator SET lock_timeout = '5s';
ALTER ROLE qayd_migrator SET statement_timeout = '10min';
```

A migration whose DDL would block behind a long-running transaction fails fast (after 5 seconds waiting for the lock) rather than silently queuing every subsequent request against that table for the duration of the incompatible transaction — surfacing the conflict to the deploy pipeline immediately rather than as a customer-facing latency spike.

## Multi-region and read-replica considerations

QAYD's read replicas (used for reporting queries and the AI layer's read-only context fetches) apply migrations via standard PostgreSQL streaming replication — DDL replicates automatically, but application code on a replica-reading path must tolerate a brief window where the primary has completed a migration and the replica has not yet caught up (typically sub-second, but not zero). Read paths that are schema-sensitive (e.g., a raw query selecting a newly-added column) are guarded by the same feature-flag mechanism described in **Release Strategy**, checked against replication lag metrics rather than assumed instantaneous.

# Semantic Versioning

## Three independent SemVer tracks, one shared discipline

QAYD versions three artifacts independently, each using strict `MAJOR.MINOR.PATCH`, because they change at different cadences and a bump in one does not imply a bump in another:

| Artifact | Version source of truth | Bump triggers |
|---|---|---|
| Database schema | `schema_versions` table, `db-vX.Y.Z` Git tags | See table below |
| API contract | Route prefix `/api/v{N}` for MAJOR; changelog-tracked MINOR/PATCH within a major for additive/fix changes | See table below |
| Application release | `app-vX.Y.Z` Git tags, container image tags | Product/engineering release cadence |

### Schema SemVer rules

| Bump | Trigger |
|---|---|
| PATCH | Bug-fix migration correcting a constraint, index, or default with no shape change visible to any consumer (e.g., fixing an incorrect `CHECK` constraint that was too permissive due to a typo) |
| MINOR | Additive, backward-compatible schema change: new table, new nullable column, new index, new enum value, new compatibility view |
| MAJOR | Any breaking change per the **Breaking Changes** definition: column/table removal, type change, semantic redefinition, constraint tightening incompatible with existing data |

### API SemVer rules

Because the API is exposed via a coarse `/api/v1`/`/api/v2` URL prefix rather than a fine-grained per-endpoint version, QAYD tracks MINOR/PATCH-level API changes *within* a major version in a separate `API_CHANGELOG.md`, while the URL prefix itself only ever reflects the MAJOR:

| Bump | Trigger | Reflected in URL? |
|---|---|---|
| PATCH | Bug fix in response serialization, no field added/removed/renamed | No |
| MINOR | New optional request field, new response field, new endpoint | No |
| MAJOR | Any field removal/rename, required-field addition without a server default, endpoint removal, status code semantic change | Yes — new `/api/v{N+1}` prefix |

### Automated version derivation

QAYD uses Conventional Commits (`feat:`, `fix:`, `feat!:`/`BREAKING CHANGE:`) on migration and API-resource commits specifically, parsed by a `release-please`-style bot that proposes the next version bump as a PR comment for human confirmation before tagging — full automation without human sign-off is deliberately avoided given the MAJOR-bump implications for financial data:

```bash
# Example conventional commit messages that drive automated version proposals:
git commit -m "feat(schema): add customers.name_en/name_ar columns (additive, MINOR)"
git commit -m "fix(schema): correct chk_invoices_status to include 'disputed' (PATCH)"
git commit -m "feat(schema)!: redefine journal_lines.amount sign convention

BREAKING CHANGE: amount is now always positive; the sign is derived from journal_lines.side
(debit|credit) instead. All consumers reading amount directly for reconciliation math must
update to read (side = 'debit' ? amount : -amount). See migration guide at
docs.qayd.ai/api/migration/journal-lines-sign-convention."
```

## Pre-release and build metadata

Staging/canary builds append SemVer pre-release identifiers so they are unambiguously distinguishable from GA releases while remaining ordered correctly by SemVer-aware tooling: `3.0.0-rc.1`, `3.0.0-rc.2`, `3.0.0` (GA). `schema_versions.version` only ever stores GA values; `-rc.N` schema states exist only in the staging database's own `schema_versions` table, never in production, preventing any ambiguity about what "the" current production schema version is at a glance.

# Row/Record Versioning (optimistic locking + history tables)

Schema versioning governs the *shape* of tables over time. Row/record versioning governs the *content* of individual rows over time — essential for QAYD because (a) concurrent edits to the same financial record from multiple users/sessions must not silently clobber each other, and (b) posted accounting records must be auditable back to every prior state, not merely the current one, to satisfy financial audit and dispute-resolution requirements.

## Optimistic locking with `row_version`

Every mutable business table (not append-only logs like `ledger_entries`, which are never updated after insert) carries an integer `row_version` column, per the standard column set referenced in `DATABASE_STANDARDS.md`. Every `UPDATE` must both check the caller's expected version and increment it atomically, in a single statement, so that two concurrent updates based on the same starting version can never both succeed silently:

```sql
UPDATE journal_entries
SET memo = 'Adjusted per audit finding #4471',
    row_version = row_version + 1,
    updated_by = 8842,
    updated_at = now()
WHERE id = 391025
  AND row_version = 7          -- the version the caller last read
  AND deleted_at IS NULL;
-- If this UPDATE affects 0 rows, the caller's row_version was stale: someone else
-- modified the record since it was read. The application must reload and re-prompt,
-- never blindly retry with the new server-side version, since that would silently
-- discard the caller's intended change without their knowledge.
```

### Laravel Eloquent implementation

```php
<?php
// app/Models/Concerns/HasOptimisticLocking.php
namespace App\Models\Concerns;

use App\Exceptions\OptimisticLockException;
use Illuminate\Database\Eloquent\Model;

trait HasOptimisticLocking
{
    public static function bootHasOptimisticLocking(): void
    {
        static::updating(function (Model $model) {
            $expected = $model->getOriginal('row_version');

            $affected = static::withoutGlobalScopes()
                ->where($model->getKeyName(), $model->getKey())
                ->where('row_version', $expected)
                ->update([
                    ...$model->getDirty(),
                    'row_version' => $expected + 1,
                ]);

            if ($affected === 0) {
                throw new OptimisticLockException(
                    static::class,
                    $model->getKey(),
                    $expected
                );
            }

            $model->row_version = $expected + 1;

            // Prevent Eloquent's own default UPDATE from also running; we already
            // performed the guarded update above.
            return false;
        });
    }
}

// app/Models/JournalEntry.php
class JournalEntry extends Model
{
    use HasOptimisticLocking;
    // ...
}
```

```php
// app/Exceptions/OptimisticLockException.php
class OptimisticLockException extends \RuntimeException
{
    public function __construct(string $modelClass, int|string $id, int $expectedVersion)
    {
        parent::__construct(
            "Concurrent modification detected on {$modelClass}#{$id}: " .
            "expected row_version {$expectedVersion} but record has changed. Reload and retry."
        );
    }
}
```

```php
// Controller usage — the client must send back the row_version it read, and a 409 Conflict
// (per the standard error code table in DESIGN_CONTEXT) is returned on collision.
public function update(UpdateJournalEntryRequest $request, JournalEntry $entry)
{
    $entry->fill($request->validated());
    $entry->row_version = $request->validated('row_version'); // set expected version from client

    try {
        $entry->save();
    } catch (OptimisticLockException $e) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'This record was modified by another user. Please reload and try again.',
            'errors' => [['code' => 'OPTIMISTIC_LOCK_CONFLICT', 'detail' => $e->getMessage()]],
            'meta' => ['pagination' => null],
            'request_id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
        ], 409);
    }

    return new JournalEntryResource($entry);
}
```

## History tables

For financial tables where regulatory audit requires reconstructing "what did this record look like at any prior point," QAYD pairs each such table with a `<table>_history` table populated by a PostgreSQL trigger, so history capture is impossible to bypass by any application code path (including ad-hoc `psql` sessions, migrations, or future code that forgets to call an Eloquent event):

```sql
CREATE TABLE journal_entries_history (
    history_id      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    journal_entry_id BIGINT NOT NULL,               -- not a FK: history must survive hard-deletion edge cases
    row_version     INTEGER NOT NULL,
    company_id      BIGINT NOT NULL,
    branch_id       BIGINT NULL,
    entry_number    VARCHAR(30) NOT NULL,
    entry_date      DATE NOT NULL,
    fiscal_period_id BIGINT NOT NULL,
    status          VARCHAR(20) NOT NULL,
    source_type     VARCHAR(40) NULL,
    source_id       BIGINT NULL,
    memo            TEXT NULL,
    operation       VARCHAR(10) NOT NULL,            -- 'INSERT' | 'UPDATE' | 'DELETE'
    changed_by      BIGINT NULL,
    changed_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    valid_from      TIMESTAMPTZ NOT NULL,             -- when this version became current
    valid_to        TIMESTAMPTZ NULL                  -- NULL = still current at time of last capture
);

CREATE INDEX idx_journal_entries_history_entry ON journal_entries_history (journal_entry_id, row_version);
CREATE INDEX idx_journal_entries_history_range ON journal_entries_history (journal_entry_id, valid_from, valid_to);

CREATE OR REPLACE FUNCTION fn_journal_entries_history_capture() RETURNS TRIGGER AS $$
BEGIN
    IF (TG_OP = 'UPDATE' OR TG_OP = 'DELETE') THEN
        UPDATE journal_entries_history
        SET valid_to = now()
        WHERE journal_entry_id = OLD.id AND valid_to IS NULL;
    END IF;

    IF (TG_OP = 'INSERT' OR TG_OP = 'UPDATE') THEN
        INSERT INTO journal_entries_history (
            journal_entry_id, row_version, company_id, branch_id, entry_number, entry_date,
            fiscal_period_id, status, source_type, source_id, memo,
            operation, changed_by, changed_at, valid_from, valid_to
        ) VALUES (
            NEW.id, NEW.row_version, NEW.company_id, NEW.branch_id, NEW.entry_number, NEW.entry_date,
            NEW.fiscal_period_id, NEW.status, NEW.source_type, NEW.source_id, NEW.memo,
            TG_OP, NEW.updated_by, now(), now(), NULL
        );
    ELSIF (TG_OP = 'DELETE') THEN
        INSERT INTO journal_entries_history (
            journal_entry_id, row_version, company_id, branch_id, entry_number, entry_date,
            fiscal_period_id, status, source_type, source_id, memo,
            operation, changed_by, changed_at, valid_from, valid_to
        ) VALUES (
            OLD.id, OLD.row_version, OLD.company_id, OLD.branch_id, OLD.entry_number, OLD.entry_date,
            OLD.fiscal_period_id, OLD.status, OLD.source_type, OLD.source_id, OLD.memo,
            TG_OP, OLD.updated_by, now(), now(), NULL
        );
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_journal_entries_history
AFTER INSERT OR UPDATE OR DELETE ON journal_entries
FOR EACH ROW EXECUTE FUNCTION fn_journal_entries_history_capture();
```

Note that `journal_entries` uses soft deletes (`deleted_at`), so a true SQL `DELETE` should never occur in normal operation — the `DELETE` branch of the trigger exists as a defense-in-depth capture for the disallowed case, and any actual `DELETE` firing this trigger in production is itself alerted on as a policy violation via a database audit log watch.

### Migration creating the history infrastructure

```php
<?php
// database/migrations/2026_05_01_070000_create_journal_entries_history_table.php
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/journal_entries_history.sql')));
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_entries_history ON journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS fn_journal_entries_history_capture()');
        Schema::dropIfExists('journal_entries_history');
    }
};
```

### Querying history: point-in-time reconstruction

```sql
-- "What did journal entry 391025 look like as of 2026-06-01 09:00 UTC?"
SELECT *
FROM journal_entries_history
WHERE journal_entry_id = 391025
  AND valid_from <= '2026-06-01 09:00:00+00'
  AND (valid_to IS NULL OR valid_to > '2026-06-01 09:00:00+00')
ORDER BY valid_from DESC
LIMIT 1;

-- "Show the full edit history of this journal entry, most recent first."
SELECT row_version, operation, changed_by, changed_at, status, memo
FROM journal_entries_history
WHERE journal_entry_id = 391025
ORDER BY row_version DESC;
```

### Which tables get history tables

QAYD applies trigger-based history capture to every table that (a) participates in posted financial state and (b) is subject to any UPDATE after initial insert: `journal_entries`, `invoices`, `bills`, `receipts`, `vendor_payments`, `payroll_runs`, `tax_returns`, and `accounts` (the Chart of Accounts, since renaming or reclassifying an account after transactions have posted against it is a routine and auditable operation). Purely append-only tables (`journal_lines` once posted, `ledger_entries`, `stock_movements`, `audit_logs` itself) do not need history tables because the row, once written, is never updated — the row itself already is its own permanent history, enforced by a `BEFORE UPDATE` trigger that raises an exception on any attempted mutation of a posted line:

```sql
CREATE OR REPLACE FUNCTION fn_prevent_posted_line_mutation() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.posted_at IS NOT NULL THEN
        RAISE EXCEPTION 'journal_lines %: cannot modify a posted line (posted_at=%). Use a reversing entry.',
            OLD.id, OLD.posted_at;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prevent_posted_journal_line_mutation
BEFORE UPDATE ON journal_lines
FOR EACH ROW EXECUTE FUNCTION fn_prevent_posted_line_mutation();
```

# Examples

## Example 1 — Additive MINOR schema change end-to-end (non-breaking)

**Scenario:** Add a `due_date_reminder_sent` flag to `invoices` so the Reporting Agent can avoid re-sending reminder notifications, shipping in schema `2.5.0` / app `2.6.0`, no API version bump needed.

**Step 1 — Migration (Expand, and this is the entire change; no Contract phase needed since nothing old is removed):**

```php
<?php
// database/migrations/2026_07_20_090000_add_due_date_reminder_sent_to_invoices_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('due_date_reminder_sent')->default(false)->after('status');
        });
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_reminder_pending
            ON invoices (company_id, due_date_reminder_sent)
            WHERE deleted_at IS NULL AND status IN (\'sent\',\'overdue\')');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_invoices_reminder_pending');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('due_date_reminder_sent');
        });
    }
};
```

**Step 2 — Resource layer exposes it as a new, additive field (MINOR API change within `v1`, tracked in `API_CHANGELOG.md`, no URL prefix change):**

```php
// app/Http/Resources/V1/InvoiceResource.php — new field appended, nothing removed.
public function toArray(Request $request): array
{
    return [
        // ...existing fields unchanged...
        'due_date_reminder_sent' => (bool) $this->due_date_reminder_sent,
    ];
}
```

**Step 3 — CI runs the forward/rollback/replay hash-equality check; passes since `down()` is exact inverse of `up()`.**

**Step 4 — Deployment pipeline runs migration on the migrator job, then:**

```bash
php artisan schema:record-version 2.5.0 --tag=db-v2.5.0 --changelog=CHANGELOG_SCHEMA.md
git tag -a db-v2.5.0 -m "Add invoices.due_date_reminder_sent (additive)"
git push origin db-v2.5.0
```

**Step 5 — `schema_versions` row after release:**

```sql
INSERT INTO schema_versions (version, major, minor, patch, git_tag, git_commit_sha,
    migration_batch_start, migration_batch_end, api_min_version, api_max_version,
    is_breaking, changelog)
VALUES ('2.5.0', 2, 5, 0, 'db-v2.5.0', 'a1b2c3d4e5f6...',
    118, 118, 'v1', 'v1', false,
    'Added invoices.due_date_reminder_sent (boolean, default false) and a partial index for
     pending-reminder lookups. Non-breaking. Exposed as a new field on InvoiceResource (v1);
     no API version change required.');
```

**Step 6 — Sample API response showing the new field, same `v1` envelope:**

```json
{
  "success": true,
  "data": {
    "id": 55123,
    "invoice_number": "INV-2026-005512",
    "status": "overdue",
    "due_date_reminder_sent": false,
    "balance_due": "412.500",
    "currency_code": "KWD"
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "f0a1b2c3-d4e5-46f7-8899-aabbccddeeff",
  "timestamp": "2026-07-20T09:15:00Z"
}
```

No rollback of application code is needed for this class of change even if a defect is found post-release in unrelated logic, since the schema change itself is inert until the new field is actively used — the field simply defaults to `false` and is ignored by any code path that doesn't yet know about it.

## Example 2 — Breaking MAJOR schema change end-to-end, with dual-support window

**Scenario:** Redefine `journal_lines` to store `amount` as always-positive with a separate `side` (`debit`|`credit`) column, replacing the legacy signed-`amount` convention, because the signed convention was a recurring source of Reporting Agent errors when composing multi-currency consolidations. This is unambiguously breaking per the **Breaking Changes** table (semantic redefinition of a financial amount column). Ships schema `3.0.0`, API `v2`, four-week dual-support window.

**Step 1 — RFC approved; `schema-breaking-approved` label applied to the tracking issue.**

**Step 2 — Expand migration, batch 1 of release (adds new columns, backfills nothing yet):**

```php
<?php
// database/migrations/2026_10_01_080000_add_side_and_unsigned_amount_to_journal_lines_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->string('side', 6)->nullable()->after('account_id'); // 'debit' | 'credit'
            $table->decimal('amount_unsigned', 19, 4)->nullable()->after('side');
        });
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_side
            CHECK (side IS NULL OR side IN ('debit','credit'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS chk_journal_lines_side');
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn(['side', 'amount_unsigned']);
        });
    }
};
```

**Step 3 — Backfill job (queued, batched, not inline in a migration):**

```php
// app/Jobs/BackfillJournalLineSideAndUnsignedAmount.php
class BackfillJournalLineSideAndUnsignedAmount implements ShouldQueue
{
    public function handle(): void
    {
        JournalLine::query()
            ->whereNull('side')
            ->whereNotNull('amount') // legacy signed column
            ->chunkById(5000, function ($lines) {
                foreach ($lines as $line) {
                    $line->forceFill([
                        'side' => $line->amount >= 0 ? 'debit' : 'credit',
                        'amount_unsigned' => abs($line->amount),
                    ])->saveQuietly(); // saveQuietly: skip optimistic-lock version bump for a
                                        // system backfill of a not-yet-user-visible column
                }
            });
    }
}
```

**Step 4 — Dual-write phase: application code writes both `amount` (legacy signed) and `side`/`amount_unsigned` (new) for every new journal line for the duration of the dual-support window.**

**Step 5 — New API version exposes the new shape; old version continues serving the legacy shape unchanged, both reading from the same underlying rows:**

```php
// app/Http/Resources/V1/JournalLineResource.php — unchanged legacy shape.
public function toArray(Request $request): array
{
    return ['id' => $this->id, 'account_id' => $this->account_id, 'amount' => $this->amount];
}

// app/Http/Resources/V2/JournalLineResource.php — new shape.
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'account_id' => $this->account_id,
        'side' => $this->side,
        'amount' => $this->amount_unsigned, // note: field name "amount" now always non-negative
    ];
}
```

**Step 6 — Sample `v2` response:**

```json
{
  "success": true,
  "data": { "id": 998231, "account_id": 4021, "side": "debit", "amount": "1250.0000" },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "11122233-4455-6677-8899-aabbccddeeff",
  "timestamp": "2026-10-15T14:00:00Z"
}
```

**Step 7 — After the four-week window, confirmed via access logs that no client calls `/api/v1/accounting/journal-entries` for new writes (reads may continue longer, tracked separately), the Contract migration runs in schema `3.1.0`:**

```php
<?php
// database/migrations/2026_11_05_090000_drop_legacy_signed_amount_from_journal_lines.php
// IRREVERSIBLE-adjacent: pg_dump snapshot taken at s3://qayd-db-backups/pre-contract/2026-11-05-journal-lines-amount.sql.gz
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn('amount'); // legacy signed column
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->renameColumn('amount_unsigned', 'amount'); // now the sole, canonical "amount"
            $table->string('side', 6)->nullable(false)->change();
            $table->decimal('amount', 19, 4)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Irreversible: restore from s3://qayd-db-backups/pre-contract/2026-11-05-journal-lines-amount.sql.gz'
        );
    }
};
```

**Step 8 — `v1` formally sunset (`410 Gone`), and `schema_versions` reflects the two-stage rollout:**

```sql
-- Row 1: the Expand+dual-write release.
INSERT INTO schema_versions (version, major, minor, patch, git_tag, api_min_version, api_max_version,
    is_breaking, changelog)
VALUES ('3.0.0', 3, 0, 0, 'db-v3.0.0', 'v1', 'v2', true,
    'BREAKING (dual-support phase): added journal_lines.side + amount_unsigned; legacy signed
     amount retained and dual-written. v2 API introduced exposing the new shape. v1 continues
     unchanged. Dual-support window: 2026-10-01 to 2026-10-29.');

-- Row 2: the Contract release, four weeks later.
INSERT INTO schema_versions (version, major, minor, patch, git_tag, api_min_version, api_max_version,
    is_breaking, changelog, rollback_notes)
VALUES ('3.1.0', 3, 1, 0, 'db-v3.1.0', 'v2', 'v2', true,
    'Dropped legacy signed journal_lines.amount; amount_unsigned renamed to amount and made
     NOT NULL alongside side. v1 sunset (410 Gone). Pre-migration snapshot archived.',
    'Irreversible in-place; restore path is the archived pg_dump snapshot, not migrate:rollback.');
```

## Example 3 — Optimistic lock conflict in practice

**Scenario:** Two accountants, User A and User B, both open the same draft journal entry (`id=391025`, `row_version=7`) simultaneously. User A saves a memo edit first; User B, working from a stale copy, then attempts to save a different memo edit.

```
User A's client state: { id: 391025, row_version: 7, memo: "original" }
User B's client state: { id: 391025, row_version: 7, memo: "original" }  (same starting point)

t=0   User A PATCH /api/v1/accounting/journal-entries/391025
      body: { memo: "Adjusted per audit finding #4471", row_version: 7 }
      → server: UPDATE ... SET row_version = 8 WHERE id=391025 AND row_version=7  → 1 row affected
      → 200 OK, response includes row_version: 8

t=1   User B PATCH /api/v1/accounting/journal-entries/391025
      body: { memo: "Reclassified to account 4021", row_version: 7 }   (still thinks version is 7)
      → server: UPDATE ... SET row_version = 8 WHERE id=391025 AND row_version=7  → 0 rows affected
      → OptimisticLockException thrown → 409 Conflict returned to User B
```

**409 response body User B receives:**

```json
{
  "success": false,
  "data": null,
  "message": "This record was modified by another user. Please reload and try again.",
  "errors": [
    {
      "code": "OPTIMISTIC_LOCK_CONFLICT",
      "detail": "Concurrent modification detected on App\\Models\\JournalEntry#391025: expected row_version 7 but record has changed. Reload and retry."
    }
  ],
  "meta": { "pagination": null },
  "request_id": "9988ffee-7766-5544-3322-110011001100",
  "timestamp": "2026-07-16T12:03:41Z"
}
```

User B's client re-fetches the record (now at `row_version: 8`, memo "Adjusted per audit finding #4471"), surfaces both memos to the user for manual reconciliation, and only then resubmits with `row_version: 8`. The `journal_entries_history` table now contains two rows for this entry (`row_version` 7→8 for User A's change), and a subsequent reconciled edit by User B would create a third (7→8 already exists; the new edit becomes 8→9), giving Finance a complete, gap-free audit trail regardless of how many conflicting attempts occurred.

# End of Document
