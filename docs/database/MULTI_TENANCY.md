# Multi-Tenancy Architecture — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: MULTI_TENANCY
---

# Purpose

QAYD is a single deployment of Laravel 12 (PHP 8.4+) and PostgreSQL 15+ that serves every
customer company — from a two-person accounting shop to a multi-branch enterprise group — out
of one physical database, one set of application servers, and one Redis cluster. This document
is the authoritative engineering specification for how that single deployment guarantees that
Company A can never read, write, enumerate, infer the existence of, or exhaust resources
belonging to Company B, while still giving every company full CRUD access to its own accounting,
sales, purchasing, banking, inventory, payroll, and tax data at production scale.

The design is a **shared-database, shared-schema, discriminator-column** model: every business
table carries a `company_id BIGINT NOT NULL` column, and isolation is enforced by three
independent, stacked layers — Laravel HTTP middleware, a global Eloquent scope, and PostgreSQL
Row-Level Security (RLS) — so that a defect in any single layer does not result in a cross-tenant
data leak. This document specifies, at the level of runnable SQL and Laravel code, exactly how
those layers are built, how they interact, how they are indexed and tuned for performance, how
they behave when a user belongs to more than one company, how branch/department sub-isolation
composes with company isolation, how the model evolves toward geographic sharding as QAYD scales
past a single region, how backups and disaster recovery respect tenant boundaries, and how the
whole system is proven correct with an automated isolation test suite that runs on every pull
request. It is written in the register of Stripe Connect's platform/connected-account
architecture documentation: every claim is backed by a concrete artifact (DDL, migration,
policy, middleware class, or test), not by prose assertions alone.

Everything in this document is consistent with the platform facts fixed in
`DESIGN_CONTEXT.md`: PostgreSQL as the system of record, Laravel 12 as the single source of
business-logic truth, Redis for cache/queue, Cloudflare R2 for objects, Sanctum/JWT for auth, and
the standard column set (`id`, `company_id`, `branch_id`, `created_by`, `updated_by`,
`created_at`, `updated_at`, `deleted_at`) present on every tenant-owned table.

# Tenancy Model & Rationale

## The three canonical multi-tenancy models

There are exactly three architectural options for isolating tenant data in a relational system,
and QAYD's choice among them is deliberate, not a default:

| Model | Isolation unit | Example | Isolation strength | Ops cost at 10,000 tenants | Cross-tenant reporting |
|---|---|---|---|---|---|
| Database-per-tenant | One PostgreSQL database (or cluster) per company | Each company gets its own `qayd_company_<id>` database | Strongest (OS/process-level) | Very high — 10,000 databases to migrate, monitor, back up, connection-pool | Impossible without ETL |
| Schema-per-tenant | One PostgreSQL schema per company inside one database | `company_42.invoices`, `company_43.invoices` | Strong | High — `search_path` juggling, migrations run N times, `pg_class` catalog bloat past ~5,000 schemas | Requires `UNION ALL` across schemas or a separate warehouse |
| Shared-schema with discriminator column | One set of tables, `company_id` column, one connection pool | `invoices.company_id = 42` | Enforced entirely in software (app + DB policy) | Lowest — one migration run, one connection pool, one backup job | Trivial — a single `GROUP BY company_id` |

QAYD adopts **shared-schema with a `company_id` discriminator column**, defended in depth by
PostgreSQL Row-Level Security. The rationale, in order of weight:

1. **Operational cost dominates at QAYD's target scale.** QAYD's business model is a
   self-serve/sales-assisted SaaS with an expected tenant count in the thousands within 24
   months and tens of thousands within 5 years. Database-per-tenant and schema-per-tenant both
   have O(N) operational cost in tenant count: N migration runs on every deploy, N connection
   pool slots (or N schema `search_path` switches, which defeats PostgreSQL's plan cache),
   N backup jobs to monitor. Shared-schema has O(1) operational cost — one migration run, one
   connection pool, one backup job — regardless of whether QAYD has 10 tenants or 100,000.
2. **The AI layer needs cheap, safe context switching.** Section 7 of `DESIGN_CONTEXT.md`
   requires the FastAPI AI layer to "only see the active company" and to always go through the
   Laravel API. With shared-schema, "the active company" is a single scalar (`company_id`)
   threaded through one connection; with schema-per-tenant it would require reconnecting or
   re-issuing `SET search_path`, which is both slower and a much larger attack surface for a
   component whose entire job is to be sandboxed.
3. **Cross-tenant analytics and platform-level reporting are a real, recurring requirement.**
   QAYD's own billing, usage metering, and fraud/anomaly detection (Section: AI Responsibilities
   in sibling module docs) need to query across all companies (e.g., "total posted journal
   entries this month across the platform" for capacity planning). Shared-schema makes this a
   plain aggregate query; database-per-tenant makes it an ETL pipeline.
4. **PostgreSQL Row-Level Security closes the "isolation strength" gap.** The traditional
   objection to shared-schema — "a single missing `WHERE company_id = ?` leaks data" — is
   answered directly by RLS (see `# Security` below): the database itself refuses to return or
   accept rows outside the session's authorized company, independent of what the application
   code does or forgets to do. This gives shared-schema isolation guarantees that are
   competitive with schema-per-tenant while keeping O(1) operational cost.
5. **Migration and schema evolution are dramatically simpler.** A single `php artisan migrate`
   run alters one table once. Multiplying that by thousands of schemas or databases turns every
   feature release into a fleet-management problem, and a failed migration on schema #6,214
   becomes a partial-rollout incident.

## Chosen model in one sentence

**One PostgreSQL database, one schema (`public`), every tenant-owned table carries
`company_id BIGINT NOT NULL REFERENCES companies(id)`, and every read/write path is constrained
to the caller's authorized company by three independent layers: Laravel middleware resolving and
pinning the active company per-request, a global Eloquent scope auto-injecting the `company_id`
predicate into every query built through the ORM, and a PostgreSQL RLS policy that enforces the
same predicate at the storage engine regardless of how the row was reached.**

## Rationale for defense-in-depth specifically

A single-layer isolation strategy — "the ORM always adds `WHERE company_id = ?`" — has one
well-known failure mode: a raw query, a `DB::table()` call that bypasses the Eloquent model, a
report-generation job that forgets the scope, or a future engineer who writes
`Invoice::withoutGlobalScope(CompanyScope::class)` for a legitimate reason (e.g., a platform
admin dashboard) and ships it without realizing the scope removal is now unguarded. Because a
financial ERP's blast radius for a cross-tenant leak is total (customer lists, invoice amounts,
bank balances, payroll), QAYD treats company isolation the same way it treats double-entry
balancing: as an invariant enforced by more than one independent mechanism, so that a bug in one
layer degrades security rather than eliminating it. This mirrors how Stripe Connect enforces
connected-account boundaries at both the API-key/OAuth-scope layer and the internal ledger layer,
never relying on a single check.

## What is NOT multi-tenant

`account_types` (the system-level asset/liability/equity/revenue/expense classification table)
and any other genuinely global reference table are explicitly **not** tenant-scoped — they carry
no `company_id` and are shared read-only reference data across all companies. Mixing tenant and
global tables in the same schema is intentional and requires every new table's author to declare,
in its own module doc, whether it is tenant-scoped (default: yes) or global (rare, and must be
justified). The full list of global, non-tenant tables as of this writing: `account_types`,
`tax_codes` (jurisdiction-level defaults, cloned into tenant-scoped `tax_rates` on company
setup), `permissions` (the permission catalog, not per-company grants), and `roles` when
`roles.company_id IS NULL` (system default roles — see `# Users In Multiple Companies` for how
company-specific role customization is layered on top).

# Company Isolation

## The `companies` table

```sql
CREATE TABLE companies (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
    legal_name          VARCHAR(255) NOT NULL,
    trade_name          VARCHAR(255),
    name_en             VARCHAR(255) NOT NULL,
    name_ar             VARCHAR(255),
    tax_registration_no VARCHAR(64),
    commercial_reg_no   VARCHAR(64),
    base_currency       CHAR(3) NOT NULL DEFAULT 'KWD',
    fiscal_year_start_month SMALLINT NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    region              VARCHAR(32) NOT NULL DEFAULT 'me-central-1',
    timezone            VARCHAR(64) NOT NULL DEFAULT 'Asia/Kuwait',
    locale_default      VARCHAR(8) NOT NULL DEFAULT 'ar',
    plan                VARCHAR(32) NOT NULL DEFAULT 'trial',
    status              VARCHAR(16) NOT NULL DEFAULT 'active'
                          CHECK (status IN ('active', 'suspended', 'trial', 'archived', 'closed')),
    max_users           INTEGER NOT NULL DEFAULT 5,
    max_branches        INTEGER NOT NULL DEFAULT 1,
    settings            JSONB NOT NULL DEFAULT '{}'::jsonb,
    trial_ends_at       TIMESTAMPTZ,
    suspended_at        TIMESTAMPTZ,
    suspended_reason    TEXT,
    created_by          BIGINT NULL,
    updated_by          BIGINT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX companies_uuid_uk ON companies (uuid);
CREATE INDEX companies_status_idx ON companies (status) WHERE deleted_at IS NULL;
CREATE INDEX companies_region_idx ON companies (region) WHERE deleted_at IS NULL;
```

`uuid` exists specifically so that company identifiers that ever appear in a public-facing URL,
a webhook payload, or a support ticket never leak the sequential `id` (which would allow
tenant-count enumeration — "there are currently 4,812 companies" — a minor but real information
leak, again the same discipline Stripe applies by never exposing raw auto-increment IDs in its
public API). Internal joins and foreign keys use the cheap, indexable `BIGINT id`; anything
serialized to a client uses `uuid`.

## Resolving "the active company" per request

Every authenticated HTTP request must resolve to exactly one active company before it reaches a
controller. The resolution order is:

1. **Header `X-Company-Id`** (the UUID form) — required for every API call per
   `DESIGN_CONTEXT.md` §5. This is how a user who belongs to multiple companies tells the API
   which one they are acting as (see `# Users In Multiple Companies`).
2. Verify the authenticated user (`auth()->user()`) has an active, non-revoked row in
   `company_users` for that company.
3. Pin the resolved `company_id` (the internal `BIGINT`, resolved once from the UUID) into:
   - The Laravel request-scoped container binding `app('tenant.company_id')`.
   - The PostgreSQL session variable used by RLS: `SET LOCAL app.current_company_id = ?`.
   - The current user context object used by the global Eloquent scope.

```php
<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyUuid = $request->header('X-Company-Id');

        if (! $companyUuid) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'X-Company-Id header is required.',
                'errors' => ['X-Company-Id header is required.'],
                'meta' => [],
                'request_id' => (string) request()->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ], 400);
        }

        $company = Company::query()
            ->where('uuid', $companyUuid)
            ->where('status', '!=', 'archived')
            ->whereNull('deleted_at')
            ->first();

        if (! $company) {
            abort(404, 'Company not found.');
        }

        $membership = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        if (! $membership && ! $request->user()->is_platform_admin) {
            abort(403, 'You are not a member of this company.');
        }

        // Pin for the remainder of the request lifecycle.
        app()->instance('tenant.company_id', $company->id);
        app()->instance('tenant.company', $company);
        app()->instance('tenant.branch_id', $this->resolveBranch($request, $company));
        app()->instance('tenant.membership', $membership);

        // Defense-in-depth layer 3: tell PostgreSQL RLS who is asking, inside the same
        // transaction/connection this request will use. SET LOCAL scopes to the current
        // transaction only, so it can never leak into a pooled connection reused by another
        // request (see `# Security` for the pooling caveat with PgBouncer transaction mode).
        DB::statement('SET LOCAL app.current_company_id = ?', [$company->id]);
        DB::statement('SET LOCAL app.current_user_id = ?', [$request->user()->id]);
        DB::statement(
            'SET LOCAL app.is_platform_admin = ?',
            [$request->user()->is_platform_admin ? 'true' : 'false']
        );

        return $next($request);
    }

    private function resolveBranch(Request $request, Company $company): ?int
    {
        $branchUuid = $request->header('X-Branch-Id');
        if (! $branchUuid) {
            return null;
        }

        return \App\Models\Branch::query()
            ->where('company_id', $company->id)
            ->where('uuid', $branchUuid)
            ->value('id');
    }
}
```

Registered globally (excluding only `auth/login`, `auth/register`, and platform-level routes)
in `bootstrap/app.php`:

```php
$middleware->appendToGroup('api', [
    \App\Http\Middleware\ResolveTenantCompany::class,
]);
```

Because `SET LOCAL` is transaction-scoped, QAYD wraps every request that touches tenant data in
an explicit transaction opened *after* the middleware sets the session variable, using a second,
thin middleware (`TransactionalRequest`) that calls `DB::beginTransaction()` after
`ResolveTenantCompany` runs and commits/rolls back after the response is built. This guarantees
the RLS session variable is visible for the entirety of the request's database work even under
PgBouncer transaction-pooling mode (see `# Performance`).

## The global Eloquent scope

```php
<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound('tenant.company_id')) {
            // No tenant context resolved (e.g. a console command, a queued job run
            // outside a request). Fail closed: return zero rows rather than all rows.
            $builder->whereRaw('1 = 0');
            return;
        }

        $builder->where($model->getTable() . '.company_id', app('tenant.company_id'));
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutCompanyScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(CompanyScope::class);
        });

        $builder->macro('forCompany', function (Builder $builder, int $companyId) {
            return $builder->withoutGlobalScope(CompanyScope::class)
                ->where($builder->getModel()->getTable() . '.company_id', $companyId);
        });
    }
}
```

A base model, `App\Models\Concerns\BelongsToCompany`, applies the scope and also
auto-fills `company_id`, `branch_id`, `created_by`, and `updated_by` on creation:

```php
<?php

namespace App\Models\Concerns;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function (Model $model) {
            if (! $model->company_id && app()->bound('tenant.company_id')) {
                $model->company_id = app('tenant.company_id');
            }
            if (! $model->branch_id && app()->bound('tenant.branch_id')) {
                $model->branch_id = app('tenant.branch_id');
            }
            if (auth()->check()) {
                $model->created_by = auth()->id();
                $model->updated_by = auth()->id();
            }
        });

        static::updating(function (Model $model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }
}
```

Every tenant-owned model (`Invoice`, `JournalEntry`, `Customer`, `Product`, …) uses
`use BelongsToCompany;`. Because the scope is bound at the base model level via a shared trait
rather than copy-pasted per model, there is exactly one place to audit, and CI includes a static
check (`# Testing Isolation`) that fails the build if any model under `app/Models/*` that has a
`company_id` column does not use the trait.

# Tenant IDs

## Identity strategy

QAYD uses **`BIGINT GENERATED ALWAYS AS IDENTITY`** (PostgreSQL identity columns, the modern
replacement for `SERIAL`) for every table's internal primary key, including `companies.id`. This
is the fastest, smallest, and most index-friendly primary key type for a relational OLTP system
and is what every foreign key (`company_id`, `branch_id`, `customer_id`, …) references. It is
**never** exposed to clients.

Every tenant-facing entity that is referenced in a URL, an API response `data.id` field, a QR
code, a webhook payload, or any other externally-visible surface additionally carries a
`uuid UUID NOT NULL DEFAULT gen_random_uuid()` column with a unique index. Clients address
resources by UUID; the API translates UUID → internal `BIGINT` at the repository layer and never
the other way around. This split exists for three concrete reasons:

1. **No sequential enumeration.** A client cannot infer `GET /api/v1/invoices/1047` implies
   `.../1046` exists and belongs to someone.
2. **No cross-environment collisions.** UUIDs generated in staging, a customer's on-prem import
   tool, or an offline mobile draft can be merged into production without a primary-key clash.
3. **BIGINT stays optimal for the actual hot path** — foreign keys and joins, which dominate
   query volume, use an 8-byte integer instead of a 16-byte UUID, keeping indexes small and
   B-tree traversal cache-friendly. (QAYD deliberately rejected UUID-as-primary-key, which is a
   common anti-pattern — see `# Anti-Patterns` in `# Edge Cases` — because UUIDv4's randomness
   causes B-tree index write amplification and page splits at scale; QAYD uses UUIDv4 only for
   the externally-facing secondary identifier, never as the join key.)

```sql
-- Convention repeated on every tenant table that is ever addressed externally:
id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
uuid        UUID NOT NULL DEFAULT gen_random_uuid(),
company_id  BIGINT NOT NULL REFERENCES companies(id),
...
CONSTRAINT <table>_uuid_uk UNIQUE (uuid)
```

## `company_id` typing and referential integrity

`company_id` is **always** `BIGINT NOT NULL REFERENCES companies(id)`. It is never nullable on a
tenant-owned table — a row with no company is a data-integrity defect, not a valid state (compare
`branch_id`, which is legitimately nullable for company-level records that are not
branch-specific; see `# Branch & Department Sub-Isolation`). The foreign key uses
`ON DELETE RESTRICT` by default (a company cannot be hard-deleted while it owns rows — companies
are only ever soft-deleted, per `DESIGN_CONTEXT.md` §2), except on purely operational/log tables
(`audit_logs`, `ai_messages`) which use `ON DELETE CASCADE` since they have no independent
business meaning once the owning company is gone.

```sql
ALTER TABLE invoices
    ADD CONSTRAINT invoices_company_id_fk
    FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE;
```

## Laravel migration convention for tenant tables

A shared migration helper enforces the standard columns and FK on every tenant table so no
individual migration can "forget" `company_id`:

```php
<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

trait TenantTableColumns
{
    protected function tenantColumns(Blueprint $table, bool $withBranch = true): void
    {
        $table->id();
        $table->uuid('uuid')->default(new \Illuminate\Database\Query\Expression('gen_random_uuid()'));
        $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

        if ($withBranch) {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        }

        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestampsTz();
        $table->softDeletesTz();

        $table->unique('uuid');
    }
}
```

Usage in a concrete migration:

```php
<?php

use App\Support\Database\TenantTableColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use TenantTableColumns;

    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $this->tenantColumns($table);
            $table->string('code', 32);
            $table->string('name_en', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('tax_registration_no', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->jsonb('tags')->default('[]');
            $table->jsonb('custom_fields')->default('{}');

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        // RLS enabled as part of the same migration — see `# Security`.
        DB::statement('ALTER TABLE customers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customers FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY customers_tenant_isolation ON customers
            USING (company_id = current_setting('app.current_company_id', true)::bigint
                   OR current_setting('app.is_platform_admin', true)::boolean IS TRUE)
            WITH CHECK (company_id = current_setting('app.current_company_id', true)::bigint)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
```

Note the `code` column pattern: tenant-scoped uniqueness (`customer code "C-001" is unique
*within* a company, not globally`) is expressed as a **composite unique index that always leads
with `company_id`** — `UNIQUE (company_id, code)`, never `UNIQUE (code)`. This single rule is the
most common multi-tenancy defect in naive implementations and is enforced by CI schema linting
(`# Testing Isolation`) that scans `information_schema` for any unique index on a tenant table
that does not include `company_id` as its first column.

# Queries

## The three ways a query can reach PostgreSQL, and how each is scoped

1. **Eloquent ORM queries** (the overwhelming majority of application code) — scoped
   automatically by `CompanyScope` (see `# Company Isolation`). No developer action required for
   the common case.
2. **Raw/query-builder queries** (`DB::table(...)`, `DB::select(...)`, reporting jobs, bulk
   operations) — **not** covered by the Eloquent global scope, because `DB::table()` never
   touches the Eloquent model layer. These MUST explicitly filter by `company_id` in application
   code. RLS (`# Security`) is the safety net for this case, but application code is still
   required to filter explicitly for correctness and for query-planner efficiency (RLS
   predicates are applied by PostgreSQL but an explicit `WHERE` lets the planner pick the
   `(company_id, ...)` composite index directly rather than relying on policy-injected filters,
   which are usually — but not always, depending on PostgreSQL version and policy complexity —
   pushed down equally well).
3. **Queued jobs and console commands** — run outside an HTTP request, so `app('tenant.company_id')`
   is never auto-bound by middleware. Every job that touches tenant data must explicitly carry
   `company_id` as a constructor argument and explicitly bind the tenant context at the start of
   `handle()`. See the `WithTenantContext` job trait below.

## Query builder macro for explicit scoping

```php
<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class QueryBuilderTenantMacros extends ServiceProvider
{
    public function boot(): void
    {
        Builder::macro('forCompany', function (int $companyId) {
            /** @var Builder $this */
            return $this->where($this->from . '.company_id', $companyId);
        });

        Builder::macro('currentCompany', function () {
            /** @var Builder $this */
            if (! app()->bound('tenant.company_id')) {
                throw new \RuntimeException(
                    'No tenant company resolved for this connection; refuse to run an unscoped query.'
                );
            }
            return $this->where($this->from . '.company_id', app('tenant.company_id'));
        });
    }
}
```

Usage — every hand-written query-builder call in the codebase must read like this (enforced by a
custom PHPStan rule described in `# Testing Isolation`):

```php
$totals = DB::table('invoices')
    ->currentCompany()
    ->where('status', 'posted')
    ->whereBetween('invoice_date', [$from, $to])
    ->selectRaw('currency_code, SUM(total_base_amount) AS total')
    ->groupBy('currency_code')
    ->get();
```

## Jobs and console commands: explicit tenant context

```php
<?php

namespace App\Jobs;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RecalculateInventoryValuation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $companyId, private int $warehouseId)
    {
    }

    public function handle(): void
    {
        // Bind tenant context exactly as the HTTP middleware would, so every
        // Eloquent call inside this job is correctly scoped by CompanyScope,
        // and RLS is satisfied for the duration of the DB transaction.
        app()->instance('tenant.company_id', $this->companyId);

        DB::transaction(function () {
            DB::statement('SET LOCAL app.current_company_id = ?', [$this->companyId]);
            DB::statement('SET LOCAL app.is_platform_admin = ?', ['false']);

            $this->recalculate();
        });
    }

    private function recalculate(): void
    {
        // ... business logic using standard Eloquent models, safely scoped.
    }
}
```

A base class `TenantAwareJob` wraps this boilerplate so individual jobs only implement
`handleForTenant()`. Any job class that touches a tenant-owned model and does NOT extend
`TenantAwareJob` fails a static-analysis CI check.

## Cross-tenant queries: the ONLY legitimate path

Some platform operations are inherently cross-tenant: billing usage aggregation, the "total
platform ARR" internal dashboard, or a support engineer looking up which company owns a given
UUID (support diagnostics). These are never allowed to run through ordinary Eloquent
`withoutCompanyScope()` calls scattered through business code. Instead they are centralized into
a single, narrowly-scoped repository:

```php
<?php

namespace App\Repositories\Platform;

use Illuminate\Support\Facades\DB;

/**
 * The ONLY class in the codebase permitted to run cross-tenant aggregate queries.
 * Every method here is audited, requires the `platform.admin` permission at the
 * controller layer, and every call is written to audit_logs with action
 * 'cross_tenant_query' including the SQL fingerprint and requesting admin id.
 */
class PlatformAnalyticsRepository
{
    public function monthlyPostedJournalVolume(string $month): array
    {
        return DB::table('journal_entries')
            ->selectRaw('company_id, COUNT(*) AS entry_count, SUM(total_debit) AS volume')
            ->where('status', 'posted')
            ->whereRaw("date_trunc('month', posted_at) = ?", [$month . '-01'])
            ->groupBy('company_id')
            ->get()
            ->toArray();
    }
}
```

Every method in this repository runs with `is_platform_admin = true` in the RLS session
variable (set only by the platform-admin-only middleware `RequirePlatformAdmin`, never by
`ResolveTenantCompany`), and every call site is required to pass through
`AuditLogger::logCrossTenantQuery()` before the query executes.

## Reporting and analytical queries

Financial reports (Trial Balance, P&L, Balance Sheet — owned by the Reports module doc) are
tenant-scoped aggregate queries over `journal_lines`/`ledger_entries`. They follow the same
`currentCompany()` discipline but additionally always specify the composite index columns in
the same order they are defined (`company_id, fiscal_period_id, account_id`) so PostgreSQL's
planner uses an index-only scan wherever possible:

```sql
SELECT
    a.code,
    a.name_en,
    SUM(jl.debit_amount)  AS total_debit,
    SUM(jl.credit_amount) AS total_credit
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.company_id = jl.company_id
JOIN accounts a ON a.id = jl.account_id AND a.company_id = jl.company_id
WHERE jl.company_id = $1
  AND je.status = 'posted'
  AND je.fiscal_period_id = $2
GROUP BY a.id, a.code, a.name_en
ORDER BY a.code;
```

Note every join condition repeats `company_id` equality (`a.company_id = jl.company_id`) in
addition to the primary key join — this is not redundant defensiveness for its own sake, it lets
the planner prune partitions when `journal_lines` is partitioned by `company_id` range in the
future (`# Scaling`), and it is a second, query-level assertion that the join can never silently
cross a tenant boundary even if a FK constraint were ever missing during a migration window.

# Indexes

## The universal rule

**Every index on a tenant table leads with `company_id`**, whether it is a unique constraint, a
lookup index, or a composite index supporting a specific query pattern. This is not a style
preference — it is what allows PostgreSQL to use the index to (a) prune to a single tenant's
rows early in the plan and (b) still satisfy the secondary predicate efficiently, without a
company-wide sequential scan followed by a filter.

```sql
-- WRONG — usable only for global lookups, useless for the 99% case of "this company's
-- customers with this status", and allows a non-scoped query to accidentally scan fine:
CREATE INDEX customers_status_idx ON customers (status);

-- RIGHT — company_id leads, status is the secondary predicate:
CREATE INDEX customers_company_status_idx ON customers (company_id, status)
    WHERE deleted_at IS NULL;
```

## Index catalog for representative high-traffic tables

```sql
-- invoices: list view (company + status + date range, the single most common query)
CREATE INDEX invoices_company_status_date_idx
    ON invoices (company_id, status, invoice_date DESC)
    WHERE deleted_at IS NULL;

-- invoices: customer statement lookup
CREATE INDEX invoices_company_customer_idx
    ON invoices (company_id, customer_id, invoice_date DESC)
    WHERE deleted_at IS NULL;

-- invoices: uniqueness of human-readable invoice number per company (never globally unique)
CREATE UNIQUE INDEX invoices_company_number_uk
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;

-- journal_lines: general ledger drill-down, the hottest table in the accounting engine
CREATE INDEX journal_lines_company_account_period_idx
    ON journal_lines (company_id, account_id, fiscal_period_id);

CREATE INDEX journal_lines_company_costcenter_idx
    ON journal_lines (company_id, cost_center_id)
    WHERE cost_center_id IS NOT NULL;

-- customers / vendors: text search scoped per company (trigram, for "search as you type")
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX customers_company_name_trgm_idx
    ON customers USING gin (company_id, name_en gin_trgm_ops);

-- audit_logs: append-only, queried by company + entity + time window
CREATE INDEX audit_logs_company_entity_idx
    ON audit_logs (company_id, auditable_type, auditable_id, created_at DESC);
```

## Partial indexes to keep hot indexes small

Because `deleted_at IS NULL` is present in nearly every list-view query (soft-deleted rows are
excluded by default via Eloquent's `SoftDeletes` trait, which itself adds
`WHERE deleted_at IS NULL` automatically), partial indexes with that predicate keep the index
small and entirely resident in the PostgreSQL shared buffer cache even for tenants with millions
of historical (soft-deleted or archived) rows:

```sql
CREATE INDEX products_company_active_idx
    ON products (company_id, status)
    WHERE deleted_at IS NULL AND status = 'active';
```

## Covering indexes for list endpoints

The single highest-frequency query shape in QAYD is "paginated list of X for this company,
optionally filtered by status, sorted by date." A covering index (via `INCLUDE`) lets PostgreSQL
answer these directly from the index without a heap fetch:

```sql
CREATE INDEX invoices_company_list_covering_idx
    ON invoices (company_id, status, invoice_date DESC)
    INCLUDE (customer_id, total_amount, currency_code, invoice_number)
    WHERE deleted_at IS NULL;
```

## Index maintenance discipline

- Every new migration that adds a tenant table MUST add at minimum: (1) the composite unique
  index backing any "code"/"number" uniqueness, and (2) one composite index supporting the
  primary list-view query pattern. This is enforced by PR review checklist and by the CI schema
  linter described in `# Testing Isolation`.
- `pg_stat_user_indexes` is scraped nightly into a monitoring dashboard; any index with
  `idx_scan = 0` after 30 days on a table with more than 100k rows is flagged for removal —
  unused indexes cost write throughput on every INSERT/UPDATE for no read benefit.
- `REINDEX CONCURRENTLY` is used for any index rebuild in production (never plain `REINDEX`,
  which takes an exclusive lock) — see `# Performance`.

# Security

## Layer 1: HTTP middleware (`ResolveTenantCompany`)

Documented fully in `# Company Isolation`. This layer's job is to authenticate *membership* (is
this user allowed to act as this company at all) and to establish the session-level RLS
variables. It is the only layer aware of HTTP concepts (headers, the authenticated user, JWT
claims) — layers 2 and 3 below operate purely on the already-resolved `company_id`.

## Layer 2: Global Eloquent scope (`CompanyScope`)

Documented fully in `# Company Isolation`. This layer's job is developer ergonomics and
defense-in-depth at the ORM level: 95% of the codebase never has to think about tenancy because
every `Model::query()` call is pre-filtered.

## Layer 3: PostgreSQL Row-Level Security — the layer that cannot be forgotten

RLS is enabled on every tenant table and is the layer that holds even when layers 1 and 2 are
bypassed by a bug, a raw SQL migration script run by hand, a future contractor's one-off query,
or a compromised application-layer credential that still uses the same low-privilege database
role.

```sql
-- Enabled once per table, as part of every tenant-table migration (see the
-- TenantTableColumns trait in `# Tenant IDs`, which every migration uses).
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;

-- FORCE is essential: without it, the table OWNER (and any role with BYPASSRLS)
-- is exempt from RLS by default. Application connections NEVER use the table
-- owner role — see the role separation below — but FORCE is a second guarantee.
ALTER TABLE invoices FORCE ROW LEVEL SECURITY;

CREATE POLICY invoices_tenant_isolation ON invoices
    USING (
        company_id = current_setting('app.current_company_id', true)::bigint
        OR current_setting('app.is_platform_admin', true)::boolean IS TRUE
    )
    WITH CHECK (
        company_id = current_setting('app.current_company_id', true)::bigint
    );
```

Two distinct clauses matter:

- **`USING`** governs which existing rows are visible to `SELECT`/`UPDATE`/`DELETE`. The
  `OR is_platform_admin` escape hatch is intentionally narrow — it grants *read visibility* for
  legitimate platform operations (support diagnostics, the `PlatformAnalyticsRepository`), but
  never appears in the `WITH CHECK` clause.
- **`WITH CHECK`** governs which rows may be *written*. It deliberately has **no** platform-admin
  escape hatch: even a platform admin session can never `INSERT`/`UPDATE` a row into a company it
  is not impersonating, because the health of the accounting ledger depends on `company_id`
  always matching context, and "helpful" cross-tenant writes are exactly the failure mode RLS
  exists to prevent. If a platform operation genuinely needs to write on behalf of a company
  (e.g., a data-migration script), it must explicitly set
  `app.current_company_id` to that company first, going through the exact same code path a
  normal tenant request would.

## Database role separation

Three distinct PostgreSQL roles exist, enforcing least privilege independent of RLS:

```sql
-- 1. The application role — what Laravel's DB connection actually authenticates as.
--    No BYPASSRLS. No superuser. Only DML + sequence usage on tenant tables.
CREATE ROLE qayd_app LOGIN PASSWORD '...' NOBYPASSRLS NOSUPERUSER NOCREATEDB NOCREATEROLE;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO qayd_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO qayd_app;

-- 2. The migration role — used only by CI/CD deploy pipelines, never by the running app.
--    Owns the tables (needed for DDL) but the app never connects as this role.
CREATE ROLE qayd_migrator LOGIN PASSWORD '...' NOBYPASSRLS;
ALTER DATABASE qayd OWNER TO qayd_migrator;

-- 3. The read-only analytics role — used by the platform BI tool, scoped to a
--    materialized view layer, never granted direct table access, so it structurally
--    cannot run an unscoped write even if credentials leak.
CREATE ROLE qayd_analytics_ro LOGIN PASSWORD '...' NOBYPASSRLS;
GRANT SELECT ON ALL TABLES IN SCHEMA analytics TO qayd_analytics_ro;
```

`qayd_app` — the role the running Laravel application uses for 100% of production traffic — has
`NOBYPASSRLS` explicitly set (it is the default for new roles, but QAYD sets it explicitly so a
future `ALTER ROLE` audit finds it declared, not merely defaulted). This means even a full SQL
injection vulnerability in application code (which QAYD additionally defends against via
Eloquent's parameterized query builder and a ban on string-interpolated raw SQL, enforced by
static analysis) could not read across tenants without also defeating RLS, because the injected
query still executes as `qayd_app`, still subject to `USING`/`WITH CHECK`.

## Session-variable integrity under connection pooling

QAYD uses PgBouncer in **transaction pooling** mode for connection efficiency at scale. Session
variables set with `SET` (not `SET LOCAL`) persist on the underlying physical connection across
pooled transactions and can leak into an unrelated tenant's transaction if not reset — this is a
well-documented PgBouncer + session-variable hazard. QAYD's mitigation, non-negotiable:

1. **Always `SET LOCAL`, never plain `SET`**, for `app.current_company_id`,
   `app.current_user_id`, and `app.is_platform_admin`. `SET LOCAL` is scoped to the current
   transaction and is automatically discarded at `COMMIT`/`ROLLBACK`, so it cannot leak to the
   next transaction that reuses the same pooled physical connection.
2. Every tenant-scoped unit of work is wrapped in an explicit transaction (see
   `TransactionalRequest` middleware in `# Company Isolation`) so `SET LOCAL` is issued inside
   the same transaction as the queries it protects, never before `BEGIN`.
3. A defense-in-depth CI test (`# Testing Isolation`) opens two overlapping "requests" against a
   pooled connection pair and asserts that company A's `SET LOCAL` value is never observable in
   company B's subsequent transaction on the same physical connection.
4. `default_transaction_isolation` is not relied upon for tenant isolation — RLS is independent
   of isolation level — but QAYD runs `READ COMMITTED` (the PostgreSQL default) for OLTP traffic
   and `REPEATABLE READ` for report-generation transactions that must see a consistent snapshot
   across several queries in the same company.

## Secrets and credentials

Database credentials for `qayd_app` are stored in AWS Secrets Manager, injected into the Laravel
container as environment variables at boot (never committed, never logged). `ANTHROPIC_API_KEY`,
`ELEVENLABS_API_KEY`-equivalents for QAYD's own AI layer, and the Sanctum/JWT signing keys follow
the same pattern. No tenant-specific secret exists at the database-credential level — tenancy is
enforced entirely by the RLS session variables above, not by per-tenant database credentials,
which is a deliberate simplification versus, e.g., issuing a unique database role per company
(rejected: it reintroduces the O(N) operational cost this model was chosen to avoid, for no
additional isolation benefit given RLS already provides row-level enforcement).

## Preventing tenant ID guessing/enumeration at the API layer

Because externally-facing identifiers are UUIDs (`# Tenant IDs`), and because `WHERE uuid = ?`
against a non-existent or cross-tenant UUID returns an empty RLS-filtered result set
indistinguishable from "does not exist," QAYD's API deliberately returns **404, never 403**, for
a request that names a real UUID belonging to another company. Returning 403 would confirm the
resource exists (an information leak); 404 gives no signal about whether the record exists at
all. This is implemented by never separating "not found" from "found but not authorized" in the
repository layer — both collapse to a `ModelNotFoundException` because the RLS-and-scope-filtered
query simply never returns the row.

# Performance

## Composite indexes are the primary performance lever

As covered in `# Indexes`, the `(company_id, ...)` composite-index-first discipline is what keeps
per-tenant query cost roughly constant regardless of total platform size: a company with 50,000
invoices pays the query cost of "scan this company's 50,000-row index range," not "scan the
platform's 50 million rows and filter." `EXPLAIN (ANALYZE, BUFFERS)` is run against every new
list/report query in code review; the acceptance bar is an index scan (or index-only scan) with
`rows removed by filter` near zero, never a sequential scan on any table over 10,000 rows.

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT * FROM invoices
WHERE company_id = 42 AND status = 'posted'
ORDER BY invoice_date DESC
LIMIT 25;
-- Expected plan shape:
-- Limit
--   -> Index Scan using invoices_company_status_date_idx on invoices
--        Index Cond: (company_id = 42 AND status = 'posted')
```

## Connection pooling architecture

```
Laravel (N app containers, each with its own small internal PDO pool)
        │
        ▼
   PgBouncer (transaction pooling mode, ~500 client-facing slots)
        │
        ▼
  PostgreSQL primary (max_connections tuned to PgBouncer's pool_size, typically 60-100)
        │
        ├──▶ streaming replica #1 (read-heavy reports, `# Scaling`)
        └──▶ streaming replica #2 (analytics role, `# Scaling`)
```

`pgbouncer.ini` (relevant excerpt):

```ini
[databases]
qayd = host=pg-primary.internal port=5432 dbname=qayd

[pgbouncer]
pool_mode = transaction
max_client_conn = 2000
default_pool_size = 60
reserve_pool_size = 10
server_reset_query = DISCARD ALL
```

`server_reset_query = DISCARD ALL` is set specifically so that any session-level state
(including any stray `SET` that should have been `SET LOCAL`) is wiped when a server connection
is returned to the pool — an additional guard on top of the `SET LOCAL` discipline in
`# Security`.

## Per-tenant hot-data caching in Redis

Frequently-read, rarely-written per-company reference data — the chart of accounts, active
price lists, tax rates, company settings — is cached in Redis with tenant-namespaced keys so a
cache flush or eviction never crosses tenant boundaries and so cache invalidation can be scoped:

```
qayd:company:{company_id}:accounts:tree          (TTL 1h, invalidated on any accounts write)
qayd:company:{company_id}:settings                (TTL 1h, invalidated on settings update)
qayd:company:{company_id}:price_list:{list_id}     (TTL 15m)
qayd:company:{company_id}:fx_rate:{ccy}:{date}     (TTL 24h, immutable once the date has passed)
```

```php
$tree = Cache::remember(
    "qayd:company:{$companyId}:accounts:tree",
    now()->addHour(),
    fn () => Account::query()->orderBy('code')->get()->toTree()
);
```

Cache invalidation is company-scoped via a Redis key-pattern flush restricted to that company's
namespace (`qayd:company:{company_id}:*`), never a global `FLUSHDB`, so invalidating one
tenant's cache after a write never evicts every other tenant's warm cache — an important
performance property once the platform has thousands of active tenants sharing one Redis
cluster.

## Avoiding the "noisy neighbor" problem

A single large tenant issuing a heavy report query must not degrade latency for every other
tenant on the shared database. Mitigations:

1. **`statement_timeout` per role**: `qayd_app` has `statement_timeout = '30s'` set at the role
   level; any query exceeding it is killed rather than holding a connection and locks
   indefinitely.
2. **Report queries are routed to read replicas** (`# Scaling`), isolating their I/O and CPU
   footprint from the OLTP primary that every tenant's checkout/posting flow depends on.
3. **`work_mem` is tuned conservatively** (`work_mem = '16MB'` platform-wide) so that a single
   tenant's ad-hoc aggregate query cannot request an unbounded amount of sort/hash memory and
   starve concurrent connections; report-specific connections that legitimately need more use a
   dedicated PgBouncer pool with a higher per-session `work_mem` override, not a global increase.
4. **Per-company rate limiting** at the API gateway (Section 5 of `DESIGN_CONTEXT.md` — 429 on
   excess) throttles abusive or misconfigured integrations before they generate enough query
   volume to matter at the database.

# Scaling

## Vertical and read-replica scaling (current, 0–50,000 tenants)

The shared-schema model scales vertically on PostgreSQL far beyond QAYD's near-term needs — a
well-indexed PostgreSQL 15+ instance on modern hardware comfortably handles hundreds of millions
of rows per table with the `(company_id, ...)` composite-index discipline described above,
because every query's working set is bounded by one tenant's data, not the platform total.
QAYD's scaling path is staged:

1. **Stage 0 (current):** single primary, two streaming read replicas. All writes and
   time-sensitive reads (dashboard, posting, reconciliation) hit the primary; report generation,
   the analytics role, and the AI layer's read-only context queries are routed to replicas via a
   Laravel read/write connection split:

```php
// config/database.php
'pgsql' => [
    'read' => [
        'host' => explode(',', env('DB_READ_HOSTS', 'pg-replica-1,pg-replica-2')),
    ],
    'write' => [
        'host' => [env('DB_WRITE_HOST', 'pg-primary')],
    ],
    'sticky' => true, // a write followed by a read in the same request reads its own write
    'driver' => 'pgsql',
    'database' => env('DB_DATABASE', 'qayd'),
    'username' => env('DB_USERNAME', 'qayd_app'),
    // ...
],
```

2. **Stage 1 (table partitioning, triggered by table size, not tenant count):** once a single
   table — almost always `journal_lines`, `audit_logs`, or `stock_movements` — exceeds roughly
   200-300 GB or its hot index no longer fits comfortably in shared buffers, it is converted to a
   PostgreSQL **declarative range partition by `company_id` hash-bucket** (not by date, because
   date-based partitioning does not help tenant-scoped query pruning the way it helps time-series
   pruning):

```sql
-- Repartitioning journal_lines into 16 hash buckets keyed on company_id.
-- Every partition still enforces its own RLS policy (RLS is inherited from the
-- parent in PG 15+ when ENABLE/FORCE ROW LEVEL SECURITY is set on the parent).
CREATE TABLE journal_lines_p (LIKE journal_lines INCLUDING ALL)
    PARTITION BY HASH (company_id);

DO $$
BEGIN
    FOR i IN 0..15 LOOP
        EXECUTE format(
            'CREATE TABLE journal_lines_p_%s PARTITION OF journal_lines_p
             FOR VALUES WITH (MODULUS 16, REMAINDER %s)', i, i
        );
    END LOOP;
END $$;
```

Hash-partitioning by `company_id` guarantees every tenant's rows live entirely inside one
partition, so a tenant-scoped query touches exactly one partition (`partition pruning`), and a
single very large tenant's data volume never degrades another tenant's partition scan cost.

3. **Stage 2 (Citus / distributed PostgreSQL, triggered by primary write-throughput ceiling):**
   when a single-writer primary can no longer absorb platform-wide write volume (typically in the
   tens-of-thousands-of-tenants range with heavy accounting-posting concurrency), QAYD migrates
   to Citus (distributed PostgreSQL, available as a managed extension on several cloud providers)
   using `company_id` as the **distribution column** — the exact discriminator column this
   document already mandates on every table, which is precisely why this migration path is
   available without a data model rewrite:

```sql
SELECT create_distributed_table('journal_lines', 'company_id');
SELECT create_distributed_table('invoices', 'company_id');
-- Reference (small, shared) tables replicate to every node instead of sharding:
SELECT create_reference_table('account_types');
SELECT create_reference_table('tax_codes');
```

Because every foreign key between tenant tables already includes `company_id` in the join
predicate (`# Queries`), Citus can co-locate all of one tenant's rows across related tables on
the same physical shard/node, making cross-table joins within a tenant a local, single-node
operation even in a sharded topology — the single biggest reason QAYD standardized on
"`company_id` always in the join," not merely on the primary key, from day one.

## Horizontal scale-out is a data-model consequence, not a redesign

Because `company_id` is already present, indexed, and RLS-enforced on every table, none of the
three scaling stages above require changing the application's query shape, the Eloquent models,
or the API contract. This is the direct payoff of the architectural choice made in
`# Tenancy Model & Rationale`: the discriminator-column model defers the database-per-tenant
decision until it is actually justified by measured load, while keeping every future path
(partitioning, Citus, or — as a last resort for a single pathologically large tenant — physical
extraction to a dedicated database) available without a rewrite.

## Tenant-tier isolation for large/enterprise customers

For a small number of very large enterprise customers (or any customer whose contract requires
physical data isolation for regulatory reasons — see `# Future Multi Region Support`), QAYD
supports "pinning" a company to a dedicated database at the connection-resolution layer, without
changing a single line of business logic:

```php
// ResolveTenantCompany, extended:
$connectionName = $company->dedicated_connection_name ?? 'pgsql'; // default shared pool
app()->instance('tenant.connection', $connectionName);
config(['database.default' => $connectionName]);
```

`companies.dedicated_connection_name` is null for 99%+ of tenants (shared pool) and set to a
named connection in `config/database.php` for the handful that are physically isolated. The
schema, migrations, RLS policies, and application code are byte-for-byte identical on a dedicated
connection — only the physical destination changes — so this is a deployment-time decision, not
an application rewrite, and a tenant can be migrated from shared to dedicated (or back) via the
standard backup/restore tooling in `# Backups`.

# Backups

## Baseline: platform-wide continuous backup

The primary PostgreSQL cluster runs continuous WAL archiving to Cloudflare R2 / S3-compatible
storage via `pgBackRest`, giving point-in-time recovery (PITR) for the entire database:

```ini
# pgbackrest.conf
[qayd]
pg1-path=/var/lib/postgresql/15/main
repo1-type=s3
repo1-s3-bucket=qayd-pgbackups
repo1-s3-endpoint=https://<account>.r2.cloudflarestorage.com
repo1-retention-full=14
repo1-retention-diff=4
```

- Full backup: nightly.
- Differential: every 4 hours.
- WAL archiving: continuous, giving PITR granularity in seconds.
- Restore drills: automated monthly restore-to-scratch-instance + row-count/checksum
  verification against a snapshot manifest, alerting if drift exceeds a threshold.

This platform-wide backup restores the *entire* database (all tenants) to a point in time — the
correct tool for a full-cluster disaster (hardware failure, region outage) but the *wrong* tool
for a single tenant's "please restore my company to how it looked yesterday" request, because
restoring the whole cluster would roll back every other tenant's data too.

## Per-tenant logical export and restore

For the single-tenant recovery case (a customer accidentally bulk-deletes their chart of
accounts, or requests an export for legal/portability reasons — a right explicitly protected by
most data-protection regimes), QAYD provides a **logical, `company_id`-filtered export** using
`pg_dump`'s row-level filtering combined with a script that iterates every tenant table:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportCompanyData extends Command
{
    protected $signature = 'tenant:export {company_id} {--format=jsonl}';

    protected $tenantTables = [
        'accounts', 'journal_entries', 'journal_lines', 'customers', 'vendors',
        'products', 'invoices', 'invoice_items', 'bills', 'bill_items',
        'bank_accounts', 'bank_transactions', 'inventory_items', 'stock_movements',
        // ... every tenant table, generated from information_schema at build time
        // rather than hand-maintained, to guarantee completeness (see `# Testing
        // Isolation` for the CI check that keeps this list honest).
    ];

    public function handle(): int
    {
        $companyId = (int) $this->argument('company_id');
        $path = storage_path("exports/company_{$companyId}_" . now()->format('Ymd_His'));
        mkdir($path, 0700, true);

        foreach ($this->tenantTables as $table) {
            $rows = DB::table($table)->forCompany($companyId)->orderBy('id')->cursor();
            $file = fopen("{$path}/{$table}.jsonl", 'w');
            foreach ($rows as $row) {
                fwrite($file, json_encode($row) . "\n");
            }
            fclose($file);
        }

        $this->info("Exported company {$companyId} to {$path}");
        return self::SUCCESS;
    }
}
```

The generated export is uploaded to a private, company-scoped R2 prefix
(`exports/company/{uuid}/...`) with a signed URL delivered to the requesting admin, following the
same private-storage discipline used for dose-proof-equivalent sensitive media in sibling
products. Restoring a single tenant from such an export runs the inverse operation inside a
transaction, re-validating every foreign key resolves within the same `company_id` before commit,
and is only ever run by the migration role (`qayd_migrator`), never by the application role.

## Point-in-time recovery scoped to one tenant

True per-tenant PITR (restore company 42 to exactly 14:32 UTC yesterday, leaving every other
company at the current moment) is **not possible on a shared physical cluster** — PITR restores
the entire physical cluster to a WAL position, which would roll back all tenants. QAYD's answer
for this specific requirement (offered only on enterprise-tier contracts) is:

1. Restore the desired WAL position to a **scratch instance** (isolated, non-production).
2. Run `ExportCompanyData` (above) against the scratch instance for the affected company only.
3. Run the corresponding `ImportCompanyData` against production, inside a transaction, replacing
   only that company's rows.
4. Tear down the scratch instance.

This procedure is fully scripted (`php artisan tenant:pitr-restore {company_id} {timestamp}`) and
takes on the order of minutes for a typical company's data volume, because step 1 only needs to
restore up to the point where the affected tables are consistent, not the full multi-terabyte
cluster to a live, query-serving state.

## Backup encryption and access control

All backups (WAL archives, full/differential base backups, and per-tenant logical exports) are
encrypted at rest with a platform-managed KMS key (AWS KMS or Cloudflare's equivalent), and
access to the backup bucket is restricted to the `qayd_migrator`/ops IAM role — the application
role `qayd_app` has no permission to read or write the backup bucket, so a compromised
application credential cannot exfiltrate a full-platform backup.

# Cross Tenant Protection

## Enumeration of every mechanism, stacked

Cross-tenant protection is the *outcome* this entire document produces; this section names each
contributing control in one place as a checklist, because a security review needs a single
enumeration rather than having to reconstruct it from every other section:

| # | Control | Where enforced | Failure mode if this control alone existed |
|---|---|---|---|
| 1 | `X-Company-Id` header + membership check | `ResolveTenantCompany` middleware | Bypassed by any code path that skips the middleware |
| 2 | Global Eloquent scope | ORM query builder | Bypassed by raw SQL, `withoutGlobalScope`, or console/queue context |
| 3 | PostgreSQL RLS `USING`/`WITH CHECK` | Database engine | Bypassed only by a role with `BYPASSRLS` (application role never has it) |
| 4 | Composite FK + UNIQUE constraints leading with `company_id` | Schema DDL | Prevents orphaned/duplicate rows across tenants, not query leaks |
| 5 | `qayd_app` role has no `BYPASSRLS`, no superuser | Database role grants | Would be moot if RLS itself were disabled |
| 6 | UUID (non-sequential) external identifiers | API/model layer | Reduces enumeration risk, not a data-access control |
| 7 | 404-not-403 response discipline | API controllers/resources | Prevents existence-confirmation side channel |
| 8 | `SET LOCAL` (never `SET`) + explicit transactions | Middleware + PgBouncer config | Prevents session-variable bleed across pooled connections |
| 9 | CI isolation test suite | `# Testing Isolation` | Catches regressions in any of the above before merge |
| 10 | Audit logging of every mutation + every cross-tenant platform query | `audit_logs`, `PlatformAnalyticsRepository` | Provides forensic trail if a leak occurs despite 1-9 |

No single row in this table is sufficient alone; the design goal is that a defect in any one row
degrades to the protection offered by the remaining rows, never to zero protection.

## Automated leak-detection at write time

Beyond preventing leaks, QAYD actively detects attempted cross-tenant writes and treats them as
security incidents, not silent no-ops. The RLS `WITH CHECK` clause causes PostgreSQL to raise
`ERROR: new row violates row-level security policy` rather than silently rejecting or, worse,
silently redirecting the write. Laravel's exception handler specifically pattern-matches this
PostgreSQL error code (`42501`) and, instead of surfacing a generic 500, logs a
`security.cross_tenant_write_attempt` event with the authenticated user, both the intended and
attempted `company_id`, the SQL fingerprint, and the request ID, then returns a 403 to the
client:

```php
<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class CrossTenantViolationHandler
{
    public function handle(QueryException $e): ?\Illuminate\Http\JsonResponse
    {
        if (($e->errorInfo[0] ?? null) !== '42501') {
            return null; // not an RLS violation, let normal handling proceed
        }

        Log::channel('security')->critical('security.cross_tenant_write_attempt', [
            'user_id' => auth()->id(),
            'attempted_company_context' => app('tenant.company_id') ?? null,
            'request_id' => request()->attributes->get('request_id'),
            'sql_fingerprint' => $e->getSql(),
        ]);

        app(\App\Services\SecurityAlertDispatcher::class)->pageOnCall(
            'RLS policy violation — possible cross-tenant write attempt',
        );

        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Forbidden.',
            'errors' => ['Forbidden.'],
            'meta' => [],
            'request_id' => (string) request()->attributes->get('request_id'),
            'timestamp' => now()->toIso8601String(),
        ], 403);
    }
}
```

This event pages the on-call security engineer in real time (`SecurityAlertDispatcher`), because
in a correctly functioning system this error should never occur in production — it is a strong
signal of either an application bug that must be fixed immediately or an active exploitation
attempt.

## Foreign-key cross-tenant guard

RLS protects `SELECT`/`INSERT`/`UPDATE`/`DELETE` against direct cross-tenant access, but it does
not, by itself, prevent an application bug from creating an `invoice_items` row whose
`invoice_id` correctly belongs to company 42 while the row's own `company_id` is (incorrectly)
set to company 43 — both writes could individually satisfy their own table's RLS policy if the
session context happened to be wrong at each step. QAYD closes this with a `CHECK`-equivalent
enforced via a trigger on every child table that denormalizes and re-validates the parent's
`company_id`:

```sql
CREATE OR REPLACE FUNCTION assert_same_company_as_parent()
RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM invoices
        WHERE id = NEW.invoice_id AND company_id = NEW.company_id
    ) THEN
        RAISE EXCEPTION 'invoice_items.company_id (%) does not match parent invoice''s company_id',
            NEW.company_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER invoice_items_company_consistency
    BEFORE INSERT OR UPDATE ON invoice_items
    FOR EACH ROW EXECUTE FUNCTION assert_same_company_as_parent();
```

This pattern (a generated, per-parent-table trigger function) is applied to every child/detail
table in the schema (`journal_lines` → `journal_entries`, `bill_items` → `bills`,
`stock_movements` → `inventory_items`, and so on) via a code-generation step in the migration
tooling, so no individual migration author has to remember to write it by hand.

# Users In Multiple Companies

## Data model: `company_users`

```sql
CREATE TABLE company_users (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    user_id        BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    role_id        BIGINT NOT NULL REFERENCES roles(id),
    status         VARCHAR(16) NOT NULL DEFAULT 'active'
                     CHECK (status IN ('invited', 'active', 'suspended', 'revoked')),
    is_default     BOOLEAN NOT NULL DEFAULT false,
    invited_by     BIGINT NULL REFERENCES users(id),
    invited_at     TIMESTAMPTZ NULL,
    accepted_at    TIMESTAMPTZ NULL,
    revoked_at     TIMESTAMPTZ NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT company_users_company_user_uk UNIQUE (company_id, user_id)
);

CREATE INDEX company_users_user_idx ON company_users (user_id) WHERE status = 'active';
CREATE INDEX company_users_company_idx ON company_users (company_id) WHERE status = 'active';

-- Exactly one default company per user, enforced at the database level:
CREATE UNIQUE INDEX company_users_one_default_per_user_uk
    ON company_users (user_id) WHERE is_default = true;
```

A `user` is a global identity (one row in `users`, one email, one password/passkey) that can hold
independent memberships — with independent roles — in any number of companies. This directly
mirrors how a single human can be an accountant at one company and a business owner at another,
without QAYD forcing duplicate accounts.

## Company switching

The client (web/mobile) calls `GET /api/v1/me/companies` on login to list every company the
authenticated user belongs to, then sends `X-Company-Id` on every subsequent request for the
company the user is currently "acting as" (`# Company Isolation`). Switching companies is a
client-side header change, not a re-login — the JWT identifies the *user*, never the company;
the company context is per-request, resolved fresh by `ResolveTenantCompany` every time,
so a user cannot retain stale authorization to a company they were removed from mid-session (the
membership check in step 2 of that middleware re-runs on every request).

```json
GET /api/v1/me/companies
X-Request-Id: 7c1b...

{
  "success": true,
  "data": [
    {
      "company_id": "c0ffee12-...-uuid",
      "name_en": "Al-Salam Trading Co.",
      "role": "Finance Manager",
      "is_default": true,
      "status": "active"
    },
    {
      "company_id": "d34db33f-...-uuid",
      "name_en": "Nour Consulting",
      "role": "Read Only",
      "is_default": false,
      "status": "active"
    }
  ],
  "message": "OK",
  "errors": [],
  "meta": {},
  "request_id": "7c1b...",
  "timestamp": "2026-07-16T09:12:00Z"
}
```

## Per-company role independence

`company_users.role_id` means the *same* user can be `Finance Manager` (full accounting write
access) at company A and `Read Only` at company B — permission evaluation (owned in detail by
each module's `# User Permissions` section, but resolved here for company context) always joins
through the current `company_users` row for the *active* company, never a global "user's role"
column:

```php
<?php

namespace App\Services;

class PermissionResolver
{
    public function can(\App\Models\User $user, string $permission): bool
    {
        if ($user->is_platform_admin) {
            return true; // platform admins bypass tenant permission checks entirely,
                          // but NOT RLS's WITH CHECK — see `# Security`.
        }

        $membership = app('tenant.membership'); // resolved once per request by the
                                                  // ResolveTenantCompany middleware
        if (! $membership || $membership->status !== 'active') {
            return false;
        }

        return $membership->role->permissions->contains('key', $permission);
    }
}
```

## Cross-company data references are structurally impossible

Because `PermissionResolver` and every Eloquent query resolve against exactly one
`app('tenant.company_id')` per request, and because `company_users` itself is the only table
that legitimately references two different tenants' concepts from a single row (a user and a
company), there is no code path in which a request "acting as Company A" can attach, reference,
or read a Company B record via the user's *other* membership — switching companies always
requires a fresh request with a different `X-Company-Id`, re-validated from scratch.

## Invitation flow

```sql
-- New membership starts life as 'invited', with no role permissions active until accepted.
INSERT INTO company_users (company_id, user_id, role_id, status, invited_by, invited_at)
VALUES ($1, $2, $3, 'invited', $4, now());
```

```php
Route::post('/companies/{company}/invitations', [CompanyInvitationController::class, 'store'])
    ->middleware(['auth:sanctum', 'permission:company.users.invite']);
```

An invited membership grants zero access until `accepted_at` is set (via an emailed, single-use,
time-limited acceptance token — following the same signed-URL discipline QAYD uses for
document/media access) — this prevents a company administrator from unilaterally granting a
still-uninvolved external user's account read access to sensitive financial data before that
user has affirmatively accepted.

# Branch & Department Sub-Isolation

## `branch_id` composes with, never replaces, `company_id`

Branch-level scoping is a **narrowing** of company-level scoping, never an alternative to it.
Every branch-scoped query still carries `company_id` as the leading, mandatory predicate, with
`branch_id` as an optional secondary predicate — this ordering matters both for index efficiency
(`# Indexes`) and for the RLS policy, which never checks `branch_id` on its own:

```sql
CREATE TABLE branches (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid          UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id    BIGINT NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    code          VARCHAR(32) NOT NULL,
    name_en       VARCHAR(255) NOT NULL,
    name_ar       VARCHAR(255),
    warehouse_id  BIGINT NULL REFERENCES warehouses(id),
    status        VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL,

    CONSTRAINT branches_company_code_uk UNIQUE (company_id, code)
);

CREATE TABLE departments (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid          UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id    BIGINT NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    branch_id     BIGINT NULL REFERENCES branches(id) ON DELETE SET NULL,
    code          VARCHAR(32) NOT NULL,
    name_en       VARCHAR(255) NOT NULL,
    name_ar       VARCHAR(255),
    cost_center_id BIGINT NULL REFERENCES cost_centers(id),
    status        VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL,

    CONSTRAINT departments_company_code_uk UNIQUE (company_id, code)
);
```

## RLS extension for branch-restricted roles

Most roles (Finance Manager, CFO, Owner) see every branch within their company — `branch_id` is
a filter the UI/report layer applies, not a hard security boundary, for those roles. But some
roles (`Warehouse Employee`, `Branch Cashier`) are legitimately restricted to one or more named
branches, and QAYD enforces that restriction at the same RLS layer, not only in application code,
using a second, branch-aware policy that composes with the company policy via `AND`:

```sql
-- A membership can optionally be restricted to specific branches:
CREATE TABLE company_user_branches (
    company_user_id BIGINT NOT NULL REFERENCES company_users(id) ON DELETE CASCADE,
    branch_id       BIGINT NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    PRIMARY KEY (company_user_id, branch_id)
);

-- session variable set by ResolveTenantCompany, alongside app.current_company_id:
--   app.current_user_branch_restricted (boolean)
--   app.current_user_id (already set for other purposes)

CREATE POLICY stock_movements_branch_isolation ON stock_movements
    USING (
        company_id = current_setting('app.current_company_id', true)::bigint
        AND (
            current_setting('app.current_user_branch_restricted', true)::boolean IS NOT TRUE
            OR branch_id IN (
                SELECT cub.branch_id FROM company_user_branches cub
                JOIN company_users cu ON cu.id = cub.company_user_id
                WHERE cu.user_id = current_setting('app.current_user_id', true)::bigint
                  AND cu.company_id = current_setting('app.current_company_id', true)::bigint
            )
        )
    );
```

This policy is applied only to tables where branch restriction is a genuine security requirement
(inventory/warehouse tables, point-of-sale-style cash registers) — applying it universally to
every table (e.g., `journal_entries`, which a Branch Cashier never touches directly) would add
unnecessary planner overhead for no security benefit, so branch-level RLS is opt-in per table,
declared explicitly in that table's migration, unlike company-level RLS which is mandatory on
every tenant table without exception.

## Department scoping is informational, not a security boundary, by default

Unlike `branch_id`, `department_id` (present on `journal_lines`, employees, and budget lines as a
dimension per `DESIGN_CONTEXT.md` §9) is treated as a **reporting dimension**, not a hard
isolation boundary, in the base product — a Finance Manager can always see spend across every
department in their company. Enterprise-tier customers can opt into department-level RLS using
the exact same `company_user_branches`-style join-table pattern
(`company_user_departments`), applied per-table on request, following the identical mechanism
already built for branches — this is a configuration toggle on the company's `settings JSONB`
column, not a schema change, keeping the base product's query plans simpler for the 95% of
tenants who never need it.

## Middleware extension for branch context

```php
// Extending ResolveTenantCompany's resolveBranch() from `# Company Isolation`:
DB::statement('SET LOCAL app.current_user_branch_restricted = ?', [
    $membership->branches()->exists() ? 'true' : 'false',
]);
```

If a membership has zero rows in `company_user_branches`, it is treated as unrestricted (sees all
branches) — restriction is opt-in per membership, so adding branch-level access control to an
existing role does not silently lock out every existing user of that role; an administrator must
explicitly assign branch restrictions.

# Future Multi Region Support

## Why region matters beyond latency

QAYD's initial deployment is single-region (`me-central-1`, close to Kuwait/GCC customers).
Multi-region support is a near-certain future requirement for three independent reasons, and the
data model above is deliberately designed so none of them require a `company_id` redesign:

1. **Latency** — a Saudi or European enterprise customer expects sub-100ms API latency, which a
   single Gulf-region database cannot guarantee globally.
2. **Data residency / regulatory requirements** — some jurisdictions (and some enterprise
   contracts) require that a company's financial data physically reside within a named country
   or region and never transit outside it. `companies.region` (already present, `# Company
   Isolation`) exists specifically to make this a per-tenant, enforceable attribute today, even
   before physical multi-region infrastructure exists.
3. **Disaster-recovery blast-radius reduction** — a single-region outage today takes down 100%
   of QAYD's tenants; region-sharding limits an outage's blast radius to the tenants hosted in
   that region.

## The routing model: region is resolved before company

Multi-region support is implemented as an additional resolution step **before**
`ResolveTenantCompany` runs, not as a change to the tenancy model itself:

```
Client request
     │
     ▼
Global Anycast / Cloudflare edge (terminates TLS, geo-routes by default)
     │
     ▼
Region Router (looks up companies.region by the company UUID in the X-Company-Id
                header via a lightweight global lookup table — NOT the full tenant
                schema, just company_id -> region -> connection string, replicated
                to every region's edge)
     │
     ▼
Regional Laravel cluster (me-central-1 / eu-west-1 / ap-south-1 / ...)
     │
     ▼
Regional PostgreSQL primary (contains ONLY companies whose `region` matches this
     cluster; the shared-schema, RLS, and CompanyScope model is otherwise
     byte-for-byte identical per region)
```

```sql
-- The global (cross-region) lookup table — deliberately tiny, containing no
-- business data, replicated to every region via a low-latency global KV
-- (Cloudflare KV or DynamoDB Global Tables), never PostgreSQL streaming
-- replication, since it must be readable even if a region is down.
-- Schema (conceptually — this table itself lives outside any single region's
-- PostgreSQL primary):
--   company_uuid UUID PRIMARY KEY, region VARCHAR(32), connection_string_secret_ref TEXT
```

```php
<?php

namespace App\Http\Middleware;

class ResolveRegionalConnection
{
    public function handle($request, \Closure $next)
    {
        $companyUuid = $request->header('X-Company-Id');
        $region = app(\App\Services\GlobalRegionLookup::class)->regionFor($companyUuid);

        if ($region !== config('app.region')) {
            // Misrouted request — redirect the client to the correct region's
            // edge endpoint rather than silently proxying (proxying would
            // re-introduce a cross-region hop on every request).
            return response()->json([
                'success' => false,
                'message' => 'Wrong region.',
                'meta' => ['correct_region_endpoint' => config("regions.{$region}.api_base")],
            ], 421); // 421 Misdirected Request
        }

        return $next($request);
    }
}
```

## Each region is a fully independent shared-schema deployment

Critically, region sharding does **not** mean "one giant global database with region-aware
queries" — that would reintroduce a single global point of failure and cross-region latency on
every query. Instead, each region runs its own **complete, independent** instance of the entire
schema described in this document — its own `companies`, `users`, `invoices`, `journal_lines`,
its own RLS policies, its own read replicas. A company's data lives entirely within its assigned
region; there is no row anywhere that spans regions. `companies.region`, once assigned at company
creation, becomes immutable in the base product (migrating a live tenant across regions is a
supported but explicitly manual, scheduled operation — see below — not a runtime toggle) because
allowing it to change casually would require an online cross-region data migration mid-flight
with in-progress transactions, which is out of scope for routine operation.

## Global identity vs. regional data

`users` — specifically authentication identity, email, password hash, passkeys — is the one
concern that legitimately needs to be global, because the same human may need to log into
companies hosted in different regions (a consultant working with both a Kuwaiti and a UAE-hosted
client, for instance, in a future where those are different regions). QAYD's answer is a small,
global **identity service** (a minimal, replicated table containing only
`id, uuid, email, password_hash, mfa_secret_ref`, no business data) that every region's
`company_users` foreign-keys to by the global `user_uuid`, resolved at login before the
region-specific JWT is minted:

```sql
-- Global identity store (replicated globally, contains no financial data):
CREATE TABLE global_users (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid          UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    email         CITEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    mfa_secret_ref TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Regional `users` table mirrors the identity by UUID, not by cross-region FK:
CREATE TABLE users (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    global_user_uuid UUID NOT NULL, -- resolved from global_users at login/sync time
    display_name   VARCHAR(255),
    -- regional profile fields only; no password/secret duplicated here
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

This split (global thin identity, regional thick profile-plus-tenancy) is the same pattern used
by every major multi-region SaaS platform and lets QAYD add regions without ever migrating
financial data across a region boundary as a side effect of a login-flow change.

## Cross-region tenant migration (planned, manual, scheduled)

When a company must move regions (contract renegotiation, regulatory change, or QAYD opening a
closer region), the procedure reuses the exact per-tenant export/import tooling from
`# Backups`:

1. Freeze writes for the company (return 503 with `Retry-After` for that `company_id` only, via
   a feature flag keyed on `company_id` — every other tenant in both regions is unaffected).
2. `php artisan tenant:export {company_id}` in the source region.
3. Transfer the export bundle to the destination region's private storage.
4. `php artisan tenant:import {export_bundle}` in the destination region, which allocates a new
   regional `company_id` (the internal BIGINT is never portable across independent regional
   databases — only the external `uuid` is preserved) and re-links every foreign key.
5. Update the global region-lookup table's `region` value for the company's UUID.
6. Unfreeze writes; DNS/edge routing now sends the company to the new region.

Because every table, index, and RLS policy in the destination region is schema-identical to the
source region (same migrations, same version, enforced by CI running the identical migration
suite in every region), the imported data is immediately subject to the same isolation
guarantees with zero manual reconfiguration.

# Examples

## Example 1: Creating an invoice, end-to-end through all three isolation layers

Request:

```http
POST /api/v1/sales/invoices HTTP/1.1
Host: api.qayd.com
Authorization: Bearer eyJhbGciOi...
X-Company-Id: c0ffee12-3456-7890-abcd-ef1234567890
Content-Type: application/json

{
  "customer_id": "9a1b2c3d-...-uuid",
  "invoice_date": "2026-07-16",
  "currency_code": "KWD",
  "items": [
    { "product_id": "5f6e7d8c-...-uuid", "quantity": 10, "unit_price": "12.5000" }
  ]
}
```

What happens, in order:

1. `ResolveTenantCompany` resolves `X-Company-Id` → internal `company_id = 42`, confirms the
   authenticated user has an active `company_users` row for company 42, binds
   `app('tenant.company_id') = 42`, and issues `SET LOCAL app.current_company_id = 42`.
2. `InvoiceController::store()` resolves `customer_id`/`product_id` UUIDs to internal BIGINTs —
   this resolution itself runs through `Customer::query()` and `Product::query()`, both
   automatically scoped by `CompanyScope`, so a UUID belonging to another company's customer
   silently resolves to "not found" (404, per the enumeration-prevention discipline in
   `# Security`), never to a different company's row.
3. `InvoiceService::create()` (Service layer, per `DESIGN_CONTEXT.md`'s Clean Architecture) opens
   a transaction, creates the `Invoice` and `InvoiceItem` rows — `BelongsToCompany`'s `creating`
   hook auto-fills `company_id = 42` on both, with no explicit assignment needed in the service
   code.
4. PostgreSQL's `WITH CHECK` policy on `invoices` and `invoice_items` validates
   `company_id = current_setting('app.current_company_id')::bigint` for both INSERTs — since the
   session variable is 42 and the auto-filled column value is 42, both succeed silently. If any
   bug had caused a mismatch, the transaction would abort with error `42501` and the incident
   pipeline in `# Cross Tenant Protection` would fire.
5. The `assert_same_company_as_parent()` trigger on `invoice_items` verifies its `company_id`
   matches its parent `invoices.company_id` — also 42, so it passes.
6. Response:

```json
{
  "success": true,
  "data": {
    "id": "a1b2c3d4-5678-90ab-cdef-1234567890ab",
    "invoice_number": "INV-2026-000482",
    "customer_id": "9a1b2c3d-...-uuid",
    "status": "draft",
    "currency_code": "KWD",
    "total_amount": "125.0000",
    "invoice_date": "2026-07-16"
  },
  "message": "Invoice created.",
  "errors": [],
  "meta": {},
  "request_id": "8f3e2a10-...",
  "timestamp": "2026-07-16T10:04:12Z"
}
```

## Example 2: Attempted cross-tenant read returns 404, not the record

```http
GET /api/v1/sales/invoices/b9c8d7e6-...-uuid-belonging-to-company-99 HTTP/1.1
X-Company-Id: c0ffee12-...-company-42-uuid
Authorization: Bearer eyJhbGciOi...
```

```json
{
  "success": false,
  "data": null,
  "message": "Invoice not found.",
  "errors": ["Invoice not found."],
  "meta": {},
  "request_id": "1c2d3e4f-...",
  "timestamp": "2026-07-16T10:05:00Z"
}
```

HTTP status: `404`. The underlying query
(`Invoice::where('uuid', $uuid)->firstOrFail()`) is scoped by `CompanyScope` to
`company_id = 42`; the row exists but belongs to `company_id = 99`, so the scoped query returns
zero rows, and even if the scope were somehow bypassed, RLS's `USING` clause would independently
filter it out before it ever reached the application.

## Example 3: Attempted cross-tenant write via a crafted payload

```http
PATCH /api/v1/sales/invoices/{invoice_of_company_42} HTTP/1.1
X-Company-Id: c0ffee12-...-company-42-uuid
Authorization: Bearer eyJhbGciOi...
Content-Type: application/json

{ "company_id": 99 }
```

`company_id` is not in any invoice `UpdateInvoiceRequest`'s validated field list — Laravel
`FormRequest::validated()` strips unlisted keys, so this field is silently discarded before it
ever reaches the service layer, regardless of what the client sends. Even in the hypothetical
where a developer error allowed `company_id` through validation, the RLS `WITH CHECK` policy on
`UPDATE` would reject the resulting row (its new `company_id` of 99 would not match
`current_setting('app.current_company_id')`, which remains 42 for this session), aborting the
transaction with error `42501` and triggering the security-incident pipeline.

## Example 4: Platform admin cross-tenant support lookup

```http
GET /api/v1/platform/companies/lookup?uuid=b9c8d7e6-...-uuid HTTP/1.1
Authorization: Bearer <platform-admin-token>
```

(No `X-Company-Id` header — this route is exempted from `ResolveTenantCompany` and instead
requires `RequirePlatformAdmin`, which sets `app.is_platform_admin = true` without pinning any
`company_id`.)

```json
{
  "success": true,
  "data": {
    "company_id": "b9c8d7e6-...-uuid",
    "name_en": "Gulf Fresh Foods WLL",
    "status": "active",
    "plan": "enterprise",
    "region": "me-central-1"
  },
  "message": "OK",
  "errors": [],
  "meta": {},
  "request_id": "...",
  "timestamp": "2026-07-16T10:07:00Z"
}
```

This endpoint runs through `PlatformAnalyticsRepository`-equivalent read-only, audited access
(`# Cross Tenant Protection`) and is logged to `audit_logs` with
`action = 'platform_admin_lookup'`, `actor_id`, and the looked-up `company_id`.

## Example 5: A user switching between two company contexts in the same session

```http
GET /api/v1/accounting/accounts?per_page=25 HTTP/1.1
X-Company-Id: c0ffee12-...-company-A
Authorization: Bearer eyJhbGciOi...
```

returns Company A's chart of accounts. The very next request from the same browser tab, same
bearer token, different header:

```http
GET /api/v1/accounting/accounts?per_page=25 HTTP/1.1
X-Company-Id: d34db33f-...-company-B
Authorization: Bearer eyJhbGciOi...
```

returns Company B's chart of accounts — a completely disjoint result set — because
`ResolveTenantCompany` re-resolves membership and re-binds `app('tenant.company_id')` fresh on
every single request; there is no session-level "current company" state held server-side between
requests that could go stale or be tampered with.

# Testing Isolation

## The isolation test suite is a release gate, not an optional test class

Every pull request that touches a migration, a model, or a controller runs a dedicated Pest test
suite, `tests/Isolation/`, and CI is configured to block merge if any test in that directory
fails — it is treated with the same severity as a failing double-entry balance check.

## Structural/static tests (schema linting)

```php
<?php

use Illuminate\Support\Facades\DB;

test('every tenant table has a company_id column with a NOT NULL constraint', function () {
    $tenantTables = DB::table('information_schema.tables')
        ->where('table_schema', 'public')
        ->whereNotIn('table_name', config('tenancy.global_tables')) // account_types, tax_codes, etc.
        ->pluck('table_name');

    foreach ($tenantTables as $table) {
        $column = DB::table('information_schema.columns')
            ->where('table_name', $table)
            ->where('column_name', 'company_id')
            ->first();

        expect($column)->not->toBeNull("Table {$table} is missing company_id");
        expect($column->is_nullable)->toBe('NO', "Table {$table}.company_id must be NOT NULL");
    }
});

test('every unique index on a tenant table leads with company_id', function () {
    $violations = DB::select(<<<'SQL'
        SELECT t.relname AS table_name, i.relname AS index_name
        FROM pg_index ix
        JOIN pg_class i ON i.oid = ix.indexrelid
        JOIN pg_class t ON t.oid = ix.indrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        WHERE ix.indisunique AND n.nspname = 'public'
          AND t.relname NOT IN (SELECT unnest(?::text[]))
          AND NOT EXISTS (
              SELECT 1 FROM pg_attribute a
              WHERE a.attrelid = t.oid
                AND a.attnum = ix.indkey[0]
                AND a.attname = 'company_id'
          )
    SQL, [config('tenancy.global_tables')]);

    expect($violations)->toBeEmpty(
        'Found unique indexes not leading with company_id: ' . json_encode($violations)
    );
});

test('every tenant table has RLS enabled and forced', function () {
    $unprotected = DB::select(<<<'SQL'
        SELECT relname FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind = 'r'
          AND relname NOT IN (SELECT unnest(?::text[]))
          AND (NOT relrowsecurity OR NOT relforcerowsecurity)
    SQL, [config('tenancy.global_tables')]);

    expect($unprotected)->toBeEmpty('Tables without forced RLS: ' . json_encode($unprotected));
});

test('every model with a company_id column uses BelongsToCompany', function () {
    $modelFiles = glob(app_path('Models/*.php'));
    foreach ($modelFiles as $file) {
        $class = 'App\\Models\\' . basename($file, '.php');
        if (! class_exists($class)) continue;
        $model = new $class();
        if (! \Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'company_id')) {
            continue;
        }
        $traits = class_uses_recursive($class);
        expect($traits)->toHaveKey(
            \App\Models\Concerns\BelongsToCompany::class,
            "{$class} has company_id but does not use BelongsToCompany"
        );
    }
});
```

## Behavioral/integration tests (the actual leak proof)

```php
<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;

test('a user cannot read another companys invoice by guessing/knowing its uuid', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userA = User::factory()->for($companyA, 'primaryCompany')->create();
    $invoiceB = Invoice::factory()->for($companyB)->create();

    $response = $this->actingAs($userA)
        ->withHeader('X-Company-Id', $companyA->uuid)
        ->getJson("/api/v1/sales/invoices/{$invoiceB->uuid}");

    $response->assertStatus(404);
});

test('a raw query builder call without forCompany() still cannot leak rows, thanks to RLS', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Invoice::factory()->for($companyB)->count(3)->create();

    app()->instance('tenant.company_id', $companyA->id);
    DB::statement('SET LOCAL app.current_company_id = ?', [$companyA->id]);

    // Deliberately buggy: forgot .forCompany($companyA->id)
    $rows = DB::table('invoices')->get();

    expect($rows)->toBeEmpty(); // RLS caught the missing application-level filter
});

test('an attempted cross-tenant write is rejected by RLS WITH CHECK, not silently succeeds', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app()->instance('tenant.company_id', $companyA->id);

    expect(fn () => DB::transaction(function () use ($companyB) {
        DB::statement('SET LOCAL app.current_company_id = ?', [request()->attributes->get('company_id') ?? $companyB->id]);
        DB::table('invoices')->insert([
            'company_id' => $companyB->id, // mismatched on purpose
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'invoice_number' => 'TEST-1',
            'invoice_date' => now(),
            'currency_code' => 'KWD',
            'total_amount' => 0,
        ]);
    }))->toThrow(\Illuminate\Database\QueryException::class);
});

test('SET LOCAL does not leak across pooled connections between two simulated tenants', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    DB::transaction(function () use ($companyA) {
        DB::statement('SET LOCAL app.current_company_id = ?', [$companyA->id]);
        expect(DB::selectOne("SELECT current_setting('app.current_company_id', true) AS v")->v)
            ->toBe((string) $companyA->id);
    });

    // New transaction on the (potentially reused, pooled) connection — SET LOCAL
    // from the previous transaction must not be visible here.
    DB::transaction(function () {
        $value = DB::selectOne("SELECT current_setting('app.current_company_id', true) AS v")->v;
        expect($value)->toBeNull();
    });
});

test('a membership revoked mid-session immediately loses access on the next request', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $membership = \App\Models\CompanyUser::factory()->for($company)->for($user)->create(['status' => 'active']);

    $this->actingAs($user)->withHeader('X-Company-Id', $company->uuid)
        ->getJson('/api/v1/accounting/accounts')->assertStatus(200);

    $membership->update(['status' => 'revoked']);

    $this->actingAs($user)->withHeader('X-Company-Id', $company->uuid)
        ->getJson('/api/v1/accounting/accounts')->assertStatus(403);
});

test('exporting a company only ever includes that companys rows across every tenant table', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Invoice::factory()->for($companyA)->count(2)->create();
    Invoice::factory()->for($companyB)->count(5)->create();

    $this->artisan('tenant:export', ['company_id' => $companyA->id])->assertSuccessful();

    $exported = collect(glob(storage_path('exports/company_' . $companyA->id . '_*')))->first();
    $invoices = collect(file(($exported ?? '') . '/invoices.jsonl'))->map(fn ($l) => json_decode($l, true));

    expect($invoices)->toHaveCount(2);
    expect($invoices->pluck('company_id')->unique()->all())->toBe([$companyA->id]);
});
```

## Fuzz/property-based isolation testing

Beyond fixed scenario tests, a nightly CI job runs a property-based fuzzer that generates N
random companies, M random users with random multi-company memberships, and K random records
per tenant table, then asserts an invariant across every possible `(acting_user, X-Company-Id,
endpoint)` triple: **the response never contains a `company_id`/`uuid` that does not equal the
resolved active company.** This is implemented as a response-body scanner that recursively walks
every JSON response and flags any embedded `company_id` field (denormalized onto some response
DTOs for display) that mismatches the request's `X-Company-Id`.

## Load-test-time isolation verification

QAYD's load-testing harness (k6) intentionally interleaves requests for many different companies
against the same connection pool concurrently and asserts response bodies never cross — this
specifically exercises the PgBouncer `SET LOCAL` leak scenario (`# Security`) under realistic
concurrency, which unit tests running serially cannot reproduce.

# Edge Cases

## Company creation race: two simultaneous signups with the same commercial registration number

`companies.commercial_reg_no` is not globally unique by schema default (some jurisdictions'
registration numbers are not always available at signup time), but QAYD's signup service applies
an application-level advisory lock (`pg_advisory_xact_lock(hashtext(commercial_reg_no))`) around
the creation transaction to prevent two concurrent signups from racing to create duplicate
company records for the same legal entity, then surfaces a 409 Conflict to the second requester
with a "this company may already be registered — request access instead" flow that routes into
the invitation system (`# Users In Multiple Companies`) rather than creating a duplicate tenant.

## Deleting the last active user of a company

A company can never be left with zero active memberships through the API — `DELETE
/company-users/{id}` and the "leave company" self-service action both check
`company_users->where('status', 'active')->count() > 1` before allowing the last active
Owner-role membership to be removed, returning 409 otherwise. This prevents an orphaned company
that no human can administer (a platform admin can still intervene via the dedicated support
tooling, which is explicitly logged).

## Soft-deleting a company

`companies.deleted_at` is set (never a hard delete) when a company churns. All RLS policies,
`ResolveTenantCompany`, and `CompanyScope` continue to function against a soft-deleted company's
data during the retention window (contractually 90 days by default) — a soft-deleted company
simply fails the `whereNull('deleted_at')` check in `ResolveTenantCompany`'s company lookup, so no
user, including a former active member, can resolve an `X-Company-Id` for it anymore, while the
data itself remains intact, indexed, and RLS-protected for potential reactivation, legal hold, or
final export before permanent purge at the end of the retention window.

## Permanent purge after retention window

An automated job (`PurgeExpiredCompanies`, scheduled nightly) hard-deletes companies whose
`deleted_at` is older than the retention window, in dependency order (children before parents,
computed from the FK graph, since `ON DELETE RESTRICT` is the default) — this is one of the very
few code paths permitted to run genuinely destructive, non-soft, cross-table deletes, and it is
gated behind a mandatory legal/compliance sign-off flag on the company record
(`purge_approved_at`) that a human must set before the job will act on that row, in addition to
the retention-window date check.

## A company changes its `base_currency` after transactions exist

`companies.base_currency` is immutable once any posted `journal_entries` row exists for that
company (enforced by a `BEFORE UPDATE` trigger checking for posted entries) — changing a base
currency after the ledger has accumulated posted-in-that-currency history would silently corrupt
every historical base-currency amount. A company that must change currency (e.g., a national
currency redenomination) goes through a dedicated, explicitly modeled "currency migration" event
(owned by the Accounting module doc) rather than a plain column update.

## A user's membership spans a region migration

If Company A migrates regions (`# Future Multi Region Support`) while User X — who also belongs
to Company B in a different, unmigrated region — is mid-session, User X's next request for
Company A correctly 421-redirects to the new region per `ResolveRegionalConnection`, while their
request for Company B is entirely unaffected, because region resolution happens per-request,
keyed off the specific `X-Company-Id` presented, never off any cached "user's region."

## Orphaned child rows from a partially-failed bulk import

A bulk CSV import (e.g., opening-balance customer import) that fails partway through must not
leave `company_id`-mismatched or parent-less child rows behind. Every bulk-import service method
wraps the entire batch in one transaction (`DB::transaction`, not per-row commits), so a
mid-batch failure rolls back the whole import atomically; combined with the
`assert_same_company_as_parent()` trigger (`# Cross Tenant Protection`), a partially-applied
import can never leave a child row whose `company_id` disagrees with its parent's.

## Anti-Patterns explicitly rejected

- **`WHERE company_id = ? OR company_id IS NULL`** — a common workaround pattern developers add
  "temporarily" while backfilling data, and a guaranteed cross-tenant leak vector if it survives
  into production; `company_id` is `NOT NULL` specifically so this pattern is not even
  syntactically tempting for genuine tenant tables.
- **UUID as the primary/foreign key** for internal joins (as opposed to the external-facing
  secondary `uuid` column this document mandates) — causes B-tree write amplification and larger
  indexes for no isolation benefit, since isolation is already provided by `company_id` +
  RLS, not by key unguessability.
- **A single global admin role that implicitly bypasses `CompanyScope`** — QAYD instead requires
  every cross-tenant capability to route through the audited, narrowly-scoped
  `PlatformAnalyticsRepository`/platform-admin middleware pattern in `# Security`, never a
  blanket `withoutGlobalScope()` sprinkled through ordinary controllers.
- **Caching a resolved `company_id` in a long-lived session/cookie instead of re-resolving via
  `X-Company-Id` on every request** — reintroduces exactly the stale-authorization risk the
  per-request resolution model in `# Company Isolation` is designed to eliminate.
- **Schema-per-tenant "just for the biggest customer"** as an ad-hoc exception — instead, large
  or regulated customers use the `dedicated_connection_name` mechanism (`# Scaling`), which keeps
  the schema, RLS, and application code identical to every other tenant, differing only in
  physical placement.

# End of Document
