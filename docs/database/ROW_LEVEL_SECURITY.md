# Row-Level Security — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: ROW_LEVEL_SECURITY
---

# Purpose

QAYD is a multi-tenant AI Financial Operating System. A single PostgreSQL cluster hosts the
accounting, sales, purchasing, banking, inventory, payroll, and tax data of every company that
signs up for the platform. The Laravel 12 backend is the declared single source of truth for
business logic, and every request that reaches the database has already passed through
`FormRequest` validation and Laravel's own authorization gates. That is necessary, but it is not
sufficient. This document specifies the second, independent enforcement layer that sits inside
PostgreSQL itself: **Row-Level Security (RLS)**.

The mandate for this document is narrow and absolute: no query executed against a QAYD tenant
table — whether issued by the Laravel application, an ad-hoc analyst connection, a queued job, a
reporting service, or a future microservice — may return or mutate a row belonging to a company
other than the one the current session is authorized to act as, and, within a company, may not
return or mutate a row an employee is not entitled to see, unless that session has been
deliberately and auditably granted `BYPASSRLS` for a narrowly-scoped, service-level reason (see
`# Background Jobs & BYPASSRLS`).

This document defines:

1. Why RLS exists as a second line of defense even though Laravel already scopes every query
   (`# Why RLS (Defense In Depth)`).
2. How the current company and current user are made known to PostgreSQL for the lifetime of a
   single request, including the exact Laravel middleware and PgBouncer configuration required to
   make session-local GUCs (`current_setting`) safe under connection pooling
   (`# Session Context`).
3. The full catalogue of policies applied to every tenant table, expressed as literal
   `CREATE POLICY` DDL for `SELECT`, `INSERT`, `UPDATE`, and `DELETE`
   (`# Policies`).
4. The PostgreSQL role hierarchy QAYD provisions and how each role interacts with RLS
   (`# Roles`).
5. Table- and column-level `GRANT` statements that RLS policies depend on
   (`# Permissions`).
6. The company-isolation policy pattern applied uniformly across all ~90 tenant tables
   (`# Company Isolation`).
7. The finer-grained isolation applied within a company — branch, department, and
   self-only visibility for payroll and HR-adjacent tables
   (`# Employee Isolation`).
8. Three fully worked, non-trivial examples: `journal_lines`, `invoices`, and `payroll_items`
   (`# Examples`).
9. Index design, `STABLE`/`SECURITY DEFINER` helper functions, and query-plan considerations that
   keep RLS overhead low at scale (`# Performance`).
10. Threat modeling for the specific ways RLS can be bypassed or weakened, and how QAYD closes
    each hole (`# Security`).
11. How RLS decisions and denials feed the `audit_logs` table and Laravel Reverb alerting
    (`# Audit`).
12. The exact mechanism by which trusted background workers (queue workers, the AI layer's
    write-back path, scheduled report generation) obtain elevated access safely
    (`# Background Jobs & BYPASSRLS`).
13. The phased rollout plan for enabling RLS on a live system without an outage
    (`# Rollout Plan`).
14. A best-practices checklist and a catalogue of anti-patterns observed in comparable
    multi-tenant PostgreSQL systems, with the QAYD-specific fix for each
    (`# Best Practices`, `# Anti-Patterns`).

# Why RLS (Defense In Depth)

## The single-source-of-truth assumption is not a security boundary

`DESIGN_CONTEXT.md` states that Laravel is "the SINGLE SOURCE OF TRUTH" for business logic and
that "the AI engine NEVER writes to the database directly." Both statements describe the
*intended* architecture. Neither statement is enforceable by PostgreSQL on its own, because a
Laravel application-level scope (`Model::addGlobalScope(new CompanyScope)`) is just PHP code that
appends a `WHERE company_id = ?` clause. It has no privilege over any other code path that can
open a connection to the same database with the same credentials. Concretely, the following code
paths all exist in a mature QAYD deployment and every one of them is capable of forgetting,
mis-scoping, or deliberately bypassing an Eloquent global scope:

- A raw `DB::select()` or `DB::statement()` call written by an engineer under deadline pressure,
  bypassing the Eloquent model entirely.
- A queued job (`php artisan queue:work`) that runs without an authenticated HTTP request context,
  where `Auth::user()->company_id` is null and a naive scope silently resolves to "no filter" —
  i.e. every company's rows — rather than throwing.
- A raw report/BI connection (Metabase, Superset, a data warehouse sync, a support engineer's
  read replica session) that talks to PostgreSQL directly and has never heard of Eloquent.
- A future microservice (the FastAPI AI layer, or a Node.js real-time service) that is handed
  read credentials for "performance" reasons and reimplements its own, inevitably incomplete,
  scoping logic.
- A SQL injection vulnerability anywhere in the stack, however unlikely, which defeats
  *application-level* scoping by definition because it operates below the ORM.
- A `withoutGlobalScope()` call added temporarily for a support ticket and never removed, or a
  scope class with a bug (`orWhere` instead of `where`, an unscoped subquery, a missing scope on a
  newly added Eloquent relationship).
- A misconfigured Laravel Nova / Filament / admin panel that queries models directly with elevated
  Eloquent instances and no HTTP-request-derived scope.

Every one of these is a real incident category in production multi-tenant SaaS systems (Laravel's
own community has recorded incidents in all of the above shapes). Application-level scoping is
necessary — it is efficient, it is where 95% of "give me my company's invoices" queries are
correctly filtered, and it lets the query planner use the same predicates for pagination and
sorting — but it is a **convenience layer**, not a **security boundary**. A security boundary must
hold even when the layer above it is wrong, missing, or compromised.

## RLS as the boundary of last resort

PostgreSQL Row-Level Security moves the isolation predicate from "a WHERE clause the caller
remembered to write" to "a policy the database engine attaches to every plan for that table,
unconditionally, for any role that is not exempt." This is the correct place for a tenant boundary
in a system where:

- The blast radius of a leak is regulated financial data (ledgers, payroll, bank transactions)
  across potentially thousands of unrelated companies sharing one cluster.
- The application layer changes weekly (new Eloquent models, new raw queries, new AI-generated
  SQL from the FastAPI layer that must never see cross-company data even by accident).
- Multiple, independently-evolving codebases (Laravel, FastAPI, future services, BI tooling)
  connect to the *same* physical database.

QAYD's defense-in-depth stack for tenant isolation is therefore layered as follows, from outermost
to innermost:

| Layer | Mechanism | Defends against |
|---|---|---|
| 1. Edge / API Gateway | `X-Company-Id` header validated against the bearer token's memberships | Requests for a company the authenticated user does not belong to |
| 2. Laravel Middleware | `SetTenantContext` resolves the active company + user, sets Eloquent global scope AND the PostgreSQL session GUCs | Missing/forgotten `WHERE company_id` in ORM code |
| 3. Laravel Policies/Gates | `JournalEntryPolicy::post()`, permission checks (`accounting.journal.post`) | A user acting outside their RBAC permissions inside their own company |
| 4. **PostgreSQL RLS (this document)** | `CREATE POLICY ... USING (company_id = current_setting('app.company_id')::bigint)` | Every failure mode of layers 1-3: raw SQL, bugs, bypassed scopes, compromised app credentials, AI-layer queries, BI tooling, insider threats |
| 5. Audit | `audit_logs`, Postgres statement logging, RLS-denial alerting | Detecting that an isolation failure was attempted, even if RLS blocked it |

Layer 4 is the only layer that is enforced by the database engine itself, cannot be forgotten by
an application developer, and holds even if layers 1-3 are entirely absent (e.g., a brand-new,
half-finished internal tool that connects with the `qayd_app` role). This is why RLS is mandatory,
non-optional, and enabled with `FORCE ROW LEVEL SECURITY` (see `# Policies`) on every tenant table
in the schema, with no exceptions carved out for "trusted" application code — trust is expressed
through role grants and `BYPASSRLS`, not through omitting a policy.

## What RLS is not

RLS does not replace Laravel's `FormRequest` validation, its RBAC permission checks, or its
business-rule enforcement (e.g., "a posted journal entry cannot be edited"). RLS answers exactly
one question — *"which rows may this database session see or touch at all?"* — the coarsest,
most catastrophic-if-wrong question in the stack. Fine-grained business authorization ("can this
specific Accountant post this specific journal entry today, given the fiscal period is still
open?") remains Laravel's job, expressed in `# User Permissions` sections of the relevant module
docs and enforced by `Gate`/`Policy` classes backed by the `permissions` table. RLS is the
backstop; RBAC is the front door.

# Session Context (current_setting app.company_id / app.user_id)

## The GUC contract

Every RLS policy in this document reads exactly two custom, session-scoped PostgreSQL settings —
Grand Unified Configuration parameters (GUCs) — that the application must set at the start of
every unit of work:

- `app.company_id` — the `BIGINT` id (as text) of the company the current session is acting as.
  Empty/unset means "no company context"; every policy therefore denies all rows by default
  rather than defaulting open.
- `app.user_id` — the `BIGINT` id (as text) of the authenticated user, used by self-only and
  audit-context policies (e.g., an employee reading their own `payslips` row, or an approver
  reading only entries they are the designated approver for).

A third, optional GUC supports impersonation/support tooling with a hard audit trail:

- `app.acting_as_support` — `'true'`/`'false'`. When `'true'`, policies still scope by
  `app.company_id`, but the audit trigger (see `# Audit`) additionally records
  `is_support_session = true` on every row touched, and support-role access is further restricted
  to read-only policies (no `INSERT`/`UPDATE`/`DELETE` policy exists for the `qayd_support` role
  on financial tables — see `# Roles`).

These are declared with `SET`, not `SET SESSION`, and are always **transaction-scoped**
(`SET LOCAL`) in request-serving code paths, for the pooling-safety reason explained below.

```sql
-- Registered once per database (idempotent, run in migrations), so that
-- `current_setting('app.company_id', true)` never errors on a session that
-- has not set it yet — it returns NULL instead of raising.
ALTER DATABASE qayd SET app.company_id = '';
ALTER DATABASE qayd SET app.user_id = '';
ALTER DATABASE qayd SET app.acting_as_support = 'false';
```

Helper functions wrap `current_setting()` so every policy expression is short, and so the
NULL-safety and type-casting logic lives in exactly one place:

```sql
CREATE OR REPLACE FUNCTION app_current_company_id()
RETURNS BIGINT
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
  SELECT NULLIF(current_setting('app.company_id', true), '')::BIGINT;
$$;

CREATE OR REPLACE FUNCTION app_current_user_id()
RETURNS BIGINT
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
  SELECT NULLIF(current_setting('app.user_id', true), '')::BIGINT;
$$;

CREATE OR REPLACE FUNCTION app_is_support_session()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
  SELECT COALESCE(current_setting('app.acting_as_support', true), 'false')::BOOLEAN;
$$;

COMMENT ON FUNCTION app_current_company_id() IS
  'Returns the tenant company_id for the current session from GUC app.company_id, or NULL if unset. Used by every RLS policy. Marked STABLE so the planner may cache the result within a single statement.';
```

`STABLE` (not `VOLATILE`) is critical for planner performance — see `# Performance` — because it
lets PostgreSQL treat repeated calls within one query as constant-foldable rather than
re-evaluating `current_setting()` per row.

## Why `SET LOCAL`, never `SET`, in a pooled environment

QAYD runs Laravel behind **PgBouncer in transaction pooling mode** (required at QAYD's connection
volume — hundreds of short-lived PHP-FPM/Octane workers cannot each hold a dedicated PostgreSQL
backend connection). Transaction pooling means a physical PostgreSQL connection is handed to a
client for the duration of one transaction and then returned to the pool for reuse by a
*different, unrelated* client request. This has one severe implication for session state:

> Anything set with plain `SET` persists on the physical connection after your transaction ends,
> and will be inherited by whichever unrelated request PgBouncer next hands that connection to.

If QAYD used `SET app.company_id = '42'` (session-scoped) instead of
`SET LOCAL app.company_id = '42'` (transaction-scoped), Company 42's GUC would leak onto the next
tenant's request that happens to reuse the same pooled backend connection, and RLS would then
correctly, faithfully, and disastrously enforce the *wrong* company's isolation for that request —
returning Company 42's data to Company 99's user, or worse, silently succeeding an `INSERT` for
Company 99 tagged as belonging to Company 42.

QAYD's rule, enforced by code review and a CI static-analysis grep, is: **every GUC used by an RLS
policy is set exclusively via `SET LOCAL` inside an explicit transaction, and every unit of work
that touches the database opens that transaction itself** — never relying on Laravel's implicit
autocommit for a bare `SELECT`. On PgBouncer transaction pooling, a bare autocommit statement
outside `BEGIN...COMMIT` still constitutes "a transaction" from PgBouncer's perspective (it wraps
each implicit statement), so `SET LOCAL` is safe even for single reads — but the Laravel
middleware below always wraps the request in an explicit transaction regardless, both for this
guarantee and so a request that fails halfway rolls back cleanly.

## Laravel middleware: setting the GUCs per request

```php
<?php
// app/Http/Middleware/SetTenantContext.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user(); // resolved by Sanctum from the bearer token

        abort_unless($user, 401, 'Unauthenticated.');

        $companyId = $this->resolveActiveCompanyId($request, $user);

        abort_unless($companyId, 403, 'No active company context.');

        // Wrap the ENTIRE request lifecycle in one DB transaction so that
        // SET LOCAL survives for every query the controller/service issues,
        // and so a mid-request exception rolls back both the GUCs and any
        // partial writes atomically.
        return DB::transaction(function () use ($request, $next, $user, $companyId) {
            DB::statement('SET LOCAL app.company_id = ?', [$companyId]);
            DB::statement('SET LOCAL app.user_id = ?', [$user->id]);
            DB::statement('SET LOCAL app.acting_as_support = ?', [
                $user->is_support_impersonating ? 'true' : 'false',
            ]);

            // Keep Eloquent's own global scope too (defense in depth is
            // symmetric: the ORM layer should ALSO filter, both for query
            // planner clarity and because RLS is not a substitute for
            // readable, intention-revealing application code).
            app(\App\Support\TenantContext::class)->setCompanyId($companyId);

            return $next($request);
        });
    }

    private function resolveActiveCompanyId(Request $request, $user): ?int
    {
        $headerCompanyId = (int) $request->header('X-Company-Id');

        if (!$headerCompanyId) {
            return null;
        }

        // Membership check happens in the application layer BEFORE the GUC
        // is ever set — RLS then re-enforces this same boundary at the
        // database level as the independent backstop described above.
        $isMember = DB::table('company_users')
            ->where('user_id', $user->id)
            ->where('company_id', $headerCompanyId)
            ->whereNull('deleted_at')
            ->exists();

        return $isMember ? $headerCompanyId : null;
    }
}
```

Registered globally (excluding the small set of unauthenticated routes: `/api/v1/auth/*`,
`/api/v1/webhooks/*`, health checks) in `bootstrap/app.php`:

```php
$middleware->appendToGroup('api', [
    \App\Http\Middleware\SetTenantContext::class,
]);
```

## PgBouncer configuration

`pgbouncer.ini` must run in `transaction` pooling mode (not `session`, which would defeat the
purpose of pooling at QAYD's scale, and not `statement`, which is incompatible with explicit
multi-statement transactions like the middleware above):

```ini
[databases]
qayd = host=127.0.0.1 port=5432 dbname=qayd

[pgbouncer]
pool_mode = transaction
max_client_conn = 2000
default_pool_size = 50
reserve_pool_size = 10
server_reset_query = DISCARD ALL
; DISCARD ALL on connection return-to-pool is the belt-and-suspenders guarantee:
; even if application code ever misused SET instead of SET LOCAL, PgBouncer
; forcibly clears all session state (GUCs, prepared statements, temp tables)
; before the physical connection is handed to the next tenant. This does NOT
; excuse using SET LOCAL — it is the last-resort net, not the primary control.
```

`server_reset_query = DISCARD ALL` is mandatory in QAYD's PgBouncer config specifically *because*
custom GUCs are in play; without it, a single application bug using session-level `SET` becomes an
active cross-tenant data leak rather than a caught-in-review mistake.

# Policies

## Enablement is unconditional and forced

Every tenant table has RLS enabled and **forced**, including for the table owner:

```sql
ALTER TABLE journal_lines ENABLE ROW LEVEL SECURITY;
ALTER TABLE journal_lines FORCE ROW LEVEL SECURITY;
```

`FORCE ROW LEVEL SECURITY` is easy to skip and is the single most common RLS misconfiguration
industry-wide: without it, the table owner role (and any role with the `BYPASSRLS` attribute)
silently ignores every policy. QAYD's migration linter (`# Rollout Plan`) fails CI if any migration
adds a tenant table without both statements appearing in the same migration file.

## Naming convention

Policies follow `<table>_<role_scope>_<command>`, e.g. `journal_lines_tenant_select`,
`payroll_items_self_select`. This keeps `\d+ <table>` output in `psql` self-describing during
incident response.

## The four canonical policy shapes

Every tenant table receives some combination of these four shapes. Which shapes a table gets is
listed per-table in `# Company Isolation` and `# Employee Isolation`.

**Shape 1 — Company-scoped, all roles that can reach the table (the universal floor):**

```sql
CREATE POLICY <table>_tenant_select ON <table>
  FOR SELECT
  USING (company_id = app_current_company_id());

CREATE POLICY <table>_tenant_insert ON <table>
  FOR INSERT
  WITH CHECK (company_id = app_current_company_id());

CREATE POLICY <table>_tenant_update ON <table>
  FOR UPDATE
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

CREATE POLICY <table>_tenant_delete ON <table>
  FOR DELETE
  USING (company_id = app_current_company_id());
```

Note the `UPDATE` policy's two clauses: `USING` gates which existing rows may be targeted;
`WITH CHECK` gates what the row may look like *after* the update. Both must reference
`company_id = app_current_company_id()` — omitting `WITH CHECK` on an `UPDATE` policy would let a
session load a row it owns and then rewrite its `company_id` to move it into another tenant, which
is a real, documented RLS anti-pattern (see `# Anti-Patterns`).

**Shape 2 — Branch-scoped, layered on top of Shape 1 for branch-restricted roles:**

```sql
CREATE POLICY <table>_branch_select ON <table>
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND (
      branch_id IS NULL                 -- company-wide rows are visible to all branches
      OR branch_id = ANY (app_current_user_branch_ids())
    )
  );
```

**Shape 3 — Self-only, for an employee's own HR/payroll rows:**

```sql
CREATE POLICY payslips_self_select ON payslips
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND employee_id = app_current_employee_id()
  );
```

**Shape 4 — Role-elevated override, additive via `PERMISSIVE` OR-combination (PostgreSQL combines
multiple `PERMISSIVE` policies for the same command with `OR`):**

```sql
CREATE POLICY payslips_hr_manager_select ON payslips
  FOR SELECT
  TO qayd_app
  USING (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('payroll.view_all')
  );
```

Because PostgreSQL `PERMISSIVE` policies (the default policy type) are combined with logical `OR`
within the same command, a Payroll Officer with `payroll.view_all` sees rows via
`payslips_hr_manager_select` even though `payslips_self_select` alone would have hidden a
colleague's payslip from them. QAYD deliberately never uses `RESTRICTIVE` policies for business
visibility rules — `RESTRICTIVE` policies AND together and are reserved exclusively for the one
global invariant that must never be OR'd away: the company boundary itself (see next section).

## Why the company boundary is RESTRICTIVE and everything else is PERMISSIVE

A subtle but load-bearing design decision: QAYD declares the company-isolation predicate as a
`RESTRICTIVE` policy, applied identically to every role, so that no combination of additional
`PERMISSIVE` policies can ever OR their way into cross-company visibility:

```sql
CREATE POLICY <table>_company_boundary ON <table>
  AS RESTRICTIVE
  FOR ALL
  USING (company_id = app_current_company_id());
```

With this `RESTRICTIVE` policy in place, the effective visibility for any command is:

```
(RESTRICTIVE company boundary) AND (OR of all matching PERMISSIVE policies)
```

This means a future engineer who adds a new `PERMISSIVE` policy intending to broaden access within
a company (e.g., "auditors can read everything") can never accidentally broaden access *across*
companies, no matter how the `USING` clause is written, because the `RESTRICTIVE` boundary always
ANDs against it. This is the single most important structural guarantee in this document: it turns
"did the engineer remember to AND in the company_id" from a per-policy discipline into a
schema-level, un-bypassable invariant.

# Roles

QAYD provisions a small, fixed set of PostgreSQL roles. Application-level RBAC (the
`roles`/`permissions` tables, `<area>.<action>` keys) governs *what a user may do within their
company*; PostgreSQL roles govern *what a database connection may do to the cluster*, and RLS
policies are written against PostgreSQL roles, not application roles — the two are deliberately
kept orthogonal so that adding a new application role (e.g., "External Auditor (Read Only)") never
requires a database migration.

| PostgreSQL role | `LOGIN` | `BYPASSRLS` | Used by | Notes |
|---|---|---|---|---|
| `qayd_app` | yes | no | Laravel application (all HTTP request handling) | Subject to every RLS policy; the GUCs set by `SetTenantContext` are what scopes it |
| `qayd_worker` | yes | no | Laravel queue workers, scheduled jobs, Reverb broadcaster | Also GUC-scoped; a job must resolve and `SET LOCAL` the company_id it is operating for before touching tenant tables — see `# Background Jobs & BYPASSRLS` |
| `qayd_ai` | yes | no | FastAPI AI layer's *read* connection, used only for retrieval/RAG context, never for writes | RLS-scoped exactly like `qayd_app`; enforces the platform rule that "AI only sees the active company" at the database level, not just by convention |
| `qayd_migrator` | yes | yes (table owner) | CI/CD schema migrations only (`php artisan migrate`), never used to serve traffic | Owns all tables; `BYPASSRLS` is required to run `ALTER TABLE`/backfills, but this role has no network path from the application tier — connects only from the deploy pipeline, over a separate, firewalled credential |
| `qayd_etl` | yes | yes | Scheduled ETL/warehouse-sync job that mirrors QAYD data into the analytics warehouse | Explicit, audited `BYPASSRLS`; every statement it runs is logged (see `# Audit`) and it is a strictly read-only role at the `GRANT` level (see `# Permissions`) regardless of the RLS bypass |
| `qayd_support` | yes | no | Customer-support impersonation sessions (`app.acting_as_support = 'true'`) | RLS-scoped like `qayd_app`; has no `INSERT`/`UPDATE`/`DELETE` grants at all on financial tables — read-only by `GRANT`, not merely by policy |
| `qayd_readonly_reporting` | yes | no | BI tool (Metabase/Superset) service accounts, one per company on request, or a company-scoped read replica user | RLS-scoped; typically paired with a *per-connection* fixed `app.company_id` set via `ALTER ROLE ... SET app.company_id = '<id>'` so a leaked BI credential is capped to one company by construction, independent of any GUC the BI tool itself might send |

```sql
CREATE ROLE qayd_app       LOGIN PASSWORD :'qayd_app_password'       NOBYPASSRLS;
CREATE ROLE qayd_worker    LOGIN PASSWORD :'qayd_worker_password'    NOBYPASSRLS;
CREATE ROLE qayd_ai        LOGIN PASSWORD :'qayd_ai_password'        NOBYPASSRLS;
CREATE ROLE qayd_migrator  LOGIN PASSWORD :'qayd_migrator_password'  BYPASSRLS;
CREATE ROLE qayd_etl       LOGIN PASSWORD :'qayd_etl_password'       BYPASSRLS;
CREATE ROLE qayd_support   LOGIN PASSWORD :'qayd_support_password'   NOBYPASSRLS;
CREATE ROLE qayd_readonly_reporting LOGIN PASSWORD :'qayd_ro_password' NOBYPASSRLS;

-- Table ownership stays with a dedicated, non-login owner role so that
-- application roles never accidentally inherit owner-level RLS bypass
-- through role membership.
CREATE ROLE qayd_schema_owner NOLOGIN;
ALTER TABLE journal_lines OWNER TO qayd_schema_owner;
-- (repeated for every tenant table by the migration tooling)
```

`qayd_migrator` is granted membership by the deploy pipeline's short-lived credential only, never
by the application's runtime secret store, and is the only role permitted to run
`ALTER TABLE ... DISABLE ROW LEVEL SECURITY` (which it does only transiently during specific,
reviewed migration steps, always followed by re-enabling `FORCE ROW LEVEL SECURITY` in the same
migration transaction).

# Permissions

RLS policies restrict *rows*; `GRANT` restricts *columns and commands*. QAYD applies both, because
a policy that permissively says "you may `SELECT` rows where `company_id = ...`" is meaningless
protection against a role that was never granted `SELECT` on the table at all — and, more subtly,
grants close off commands that should never even reach the policy evaluator for a given role (e.g.
`qayd_support` should get a `403`-equivalent `permission denied for table` error on `INSERT`, not a
zero-row RLS-filtered "success").

```sql
-- qayd_app: full CRUD, scoped by RLS policies above.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO qayd_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO qayd_app;

-- qayd_worker: same shape as qayd_app; workers post journal entries,
-- send notifications, run payroll calculations, etc.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO qayd_worker;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO qayd_worker;

-- qayd_ai: read-only, and NOT granted on the most sensitive columns even
-- for SELECT — column-level privilege revocation on top of table grants.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO qayd_ai;
REVOKE SELECT (bank_account_number, bank_routing_number, tax_id_number)
  ON vendor_bank_accounts, vendors FROM qayd_ai;

-- qayd_support: strictly read-only at the GRANT level. Even with RLS scoped
-- correctly, support staff cannot write, full stop.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO qayd_support;

-- qayd_readonly_reporting: read-only, and explicitly denied the raw
-- journal_lines/ledger_entries tables in favor of a reporting view that
-- pre-aggregates and drops PII (see below).
GRANT SELECT ON ALL TABLES IN SCHEMA public TO qayd_readonly_reporting;
REVOKE SELECT ON journal_lines, ledger_entries, payroll_items, payslips,
  vendor_bank_accounts, employees FROM qayd_readonly_reporting;
GRANT SELECT ON reporting.trial_balance_view, reporting.pnl_view
  TO qayd_readonly_reporting;

-- qayd_etl: read-only at the GRANT level DESPITE having BYPASSRLS, so that
-- the bypass only ever affects which ROWS it can see, never which
-- ACTIONS it can take. This is the standard QAYD pattern for every
-- BYPASSRLS role: BYPASSRLS widens row visibility for a legitimate
-- cross-tenant job; GRANT still narrows the verb set to what that job
-- actually needs.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO qayd_etl;

-- Nobody, including qayd_app, gets DDL rights. Table ownership and
-- ALTER/DROP/TRUNCATE are qayd_migrator/qayd_schema_owner only.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
```

`ALTER DEFAULT PRIVILEGES` ensures every future tenant table created by a migration inherits the
same grant shape automatically, so a forgotten `GRANT` statement in a new migration cannot silently
leave a role locked out (fails safe — Laravel would get a `permission denied` error immediately in
staging, caught before production) or, worse, leave a role over-privileged:

```sql
ALTER DEFAULT PRIVILEGES FOR ROLE qayd_schema_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO qayd_app, qayd_worker;

ALTER DEFAULT PRIVILEGES FOR ROLE qayd_schema_owner IN SCHEMA public
  GRANT SELECT ON TABLES TO qayd_ai, qayd_support, qayd_readonly_reporting, qayd_etl;
```

# Company Isolation

## Scope

Company isolation is the RESTRICTIVE floor described in `# Policies`, applied to every one of the
~90 tenant tables named in `DESIGN_CONTEXT.md` §3 and §9: `accounts`, `fiscal_years`,
`fiscal_periods`, `journal_entries`, `journal_lines`, `ledger_entries`, `customers`,
`customer_contacts`, `customer_addresses`, `customer_documents`, `vendors`, `vendor_contacts`,
`vendor_addresses`, `vendor_bank_accounts`, `vendor_certificates`, `vendor_contracts`, `products`,
`product_categories`, `product_variants`, `units_of_measure`, `unit_conversions`, `price_lists`,
`price_list_items`, `product_barcodes`, `product_serials`, `product_batches`, `leads`,
`sales_quotations`, `sales_quotation_items`, `sales_orders`, `sales_order_items`, `deliveries`,
`delivery_items`, `invoices`, `invoice_items`, `receipts`, `receipt_allocations`, `credit_notes`,
`refunds`, `discounts`, `coupons`, `promotions`, `price_rules`, `purchase_requests`,
`purchase_request_items`, `rfqs`, `rfq_items`, `rfq_responses`, `purchase_orders`,
`purchase_order_items`, `goods_receipts`, `goods_receipt_items`, `quality_inspections`, `bills`,
`bill_items`, `debit_notes`, `vendor_payments`, `procurement_contracts`, `bank_accounts`,
`bank_transactions`, `bank_reconciliations`, `bank_statement_lines`, `transfers`,
`inventory_items`, `stock_movements`, `stock_reservations`, `stock_adjustments`,
`stock_transfers`, `stock_transfer_items`, `stock_counts`, `stock_count_lines`,
`inventory_valuations`, `employees`, `payroll_runs`, `payroll_items`, `salary_components`,
`payslips`, `tax_codes`, `tax_rates`, `tax_transactions`, `tax_returns`, `report_definitions`,
`report_schedules`, `report_runs`, `cost_centers`, `projects`, `branches`, `departments`,
`warehouses`, `warehouse_zones`, `warehouse_areas`, `warehouse_aisles`, `warehouse_rows`,
`warehouse_shelves`, `warehouse_bins`, `notifications`, `attachments`, `ai_conversations`,
`ai_messages`, and `company_users`.

Two foundation tables are structurally different and get bespoke treatment:

- `companies` itself has no `company_id` column (it *is* the tenant). Its policy compares
  `id`, not `company_id`, to the session GUC, and additionally allows a row to be visible if the
  current user has ANY membership row in `company_users` for that company — needed for the
  "switch active company" UI, which must list all companies a user belongs to before one is
  selected as active.
- `users` is a cross-tenant identity table (a person can belong to multiple companies via
  `company_users`) and is deliberately NOT scoped by `company_id` at all; instead it is scoped so
  a session may only see `users` rows for people who share at least one company with the current
  session's active company (see the join-based policy below), which is the standard "identity
  table in a multi-tenant system" pattern.

## The `companies` policy

```sql
ALTER TABLE companies ENABLE ROW LEVEL SECURITY;
ALTER TABLE companies FORCE ROW LEVEL SECURITY;

CREATE POLICY companies_select ON companies
  AS RESTRICTIVE
  FOR SELECT
  USING (
    id = app_current_company_id()
    OR id IN (
      SELECT company_id FROM company_users
      WHERE user_id = app_current_user_id() AND deleted_at IS NULL
    )
  );

-- Only Owner/CEO-level roles may mutate company settings, enforced by RBAC
-- in Laravel; RLS still requires the active-company match as the floor.
CREATE POLICY companies_update ON companies
  AS RESTRICTIVE
  FOR UPDATE
  USING (id = app_current_company_id())
  WITH CHECK (id = app_current_company_id());

-- Company creation happens through a dedicated onboarding service account
-- (qayd_worker) that has no active company yet; INSERT is therefore
-- granted without an RLS company-id predicate but is gated entirely by
-- Laravel's onboarding FormRequest + rate limiting, since there is no
-- "current company" to check against for a brand-new row.
CREATE POLICY companies_insert ON companies
  FOR INSERT
  TO qayd_worker
  WITH CHECK (true);
```

## The `users` policy

```sql
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE users FORCE ROW LEVEL SECURITY;

CREATE POLICY users_select ON users
  AS RESTRICTIVE
  FOR SELECT
  USING (
    id = app_current_user_id()
    OR id IN (
      SELECT cu2.user_id
      FROM company_users cu1
      JOIN company_users cu2 ON cu2.company_id = cu1.company_id
      WHERE cu1.user_id = app_current_user_id()
        AND cu1.company_id = app_current_company_id()
        AND cu1.deleted_at IS NULL
        AND cu2.deleted_at IS NULL
    )
  );

CREATE POLICY users_update_self ON users
  FOR UPDATE
  USING (id = app_current_user_id())
  WITH CHECK (id = app_current_user_id());
```

## The uniform generator

Because ~90 tables share the identical Shape-1 + RESTRICTIVE-boundary pattern, QAYD does not hand
-write each one; a migration helper generates them, guaranteeing zero drift:

```php
<?php
// database/migrations/2026_07_01_000000_enable_rls_on_tenant_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tenantTables = [
        'accounts', 'fiscal_years', 'fiscal_periods', 'journal_entries', 'journal_lines',
        'ledger_entries', 'customers', 'customer_contacts', 'customer_addresses',
        'customer_documents', 'vendors', 'vendor_contacts', 'vendor_addresses',
        'vendor_bank_accounts', 'vendor_certificates', 'vendor_contracts', 'products',
        'product_categories', 'product_variants', 'units_of_measure', 'unit_conversions',
        'price_lists', 'price_list_items', 'product_barcodes', 'product_serials',
        'product_batches', 'leads', 'sales_quotations', 'sales_quotation_items',
        'sales_orders', 'sales_order_items', 'deliveries', 'delivery_items', 'invoices',
        'invoice_items', 'receipts', 'receipt_allocations', 'credit_notes', 'refunds',
        'discounts', 'coupons', 'promotions', 'price_rules', 'purchase_requests',
        'purchase_request_items', 'rfqs', 'rfq_items', 'rfq_responses', 'purchase_orders',
        'purchase_order_items', 'goods_receipts', 'goods_receipt_items',
        'quality_inspections', 'bills', 'bill_items', 'debit_notes', 'vendor_payments',
        'procurement_contracts', 'bank_accounts', 'bank_transactions',
        'bank_reconciliations', 'bank_statement_lines', 'transfers', 'inventory_items',
        'stock_movements', 'stock_reservations', 'stock_adjustments', 'stock_transfers',
        'stock_transfer_items', 'stock_counts', 'stock_count_lines', 'inventory_valuations',
        'employees', 'payroll_runs', 'payroll_items', 'salary_components', 'payslips',
        'tax_codes', 'tax_rates', 'tax_transactions', 'tax_returns', 'report_definitions',
        'report_schedules', 'report_runs', 'cost_centers', 'projects', 'branches',
        'departments', 'warehouses', 'warehouse_zones', 'warehouse_areas',
        'warehouse_aisles', 'warehouse_rows', 'warehouse_shelves', 'warehouse_bins',
        'notifications', 'attachments', 'ai_conversations', 'ai_messages', 'company_users',
    ];

    public function up(): void
    {
        foreach ($this->tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::statement("
                CREATE POLICY {$table}_company_boundary ON {$table}
                AS RESTRICTIVE FOR ALL
                USING (company_id = app_current_company_id())
            ");

            DB::statement("
                CREATE POLICY {$table}_tenant_select ON {$table}
                FOR SELECT USING (company_id = app_current_company_id())
            ");
            DB::statement("
                CREATE POLICY {$table}_tenant_insert ON {$table}
                FOR INSERT WITH CHECK (company_id = app_current_company_id())
            ");
            DB::statement("
                CREATE POLICY {$table}_tenant_update ON {$table}
                FOR UPDATE
                USING (company_id = app_current_company_id())
                WITH CHECK (company_id = app_current_company_id())
            ");
            DB::statement("
                CREATE POLICY {$table}_tenant_delete ON {$table}
                FOR DELETE USING (company_id = app_current_company_id())
            ");
        }
    }

    public function down(): void
    {
        foreach ($this->tenantTables as $table) {
            foreach (['company_boundary', 'tenant_select', 'tenant_insert', 'tenant_update', 'tenant_delete'] as $suffix) {
                DB::statement("DROP POLICY IF EXISTS {$table}_{$suffix} ON {$table}");
            }
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
```

A CI check (`php artisan rls:audit`, described in `# Rollout Plan`) diffs this hard-coded
`$tenantTables` array against `information_schema.columns` for every table that has a
`company_id` column, and fails the build if a table has the column but is missing from the array
— this is the guardrail against "new table shipped without RLS."

# Employee Isolation (branch/department/self-only rows)

Company isolation answers "which tenant." Employee isolation answers the finer question: "within
this tenant, which rows may THIS employee see." Three sub-patterns cover every case in the schema.

## Pattern A — Branch scoping

Applies to: `sales_orders`, `invoices`, `bills`, `purchase_orders`, `inventory_items`,
`stock_movements`, `bank_transactions`, and any table with a nullable `branch_id`, for roles whose
`company_users.branch_id` restricts them to one or more branches (Warehouse Employee, Sales
Employee, Purchasing Employee). Owner/CEO/CFO/Finance Manager and any role holding
`<area>.view_all_branches` see every branch.

```sql
CREATE OR REPLACE FUNCTION app_current_user_branch_ids()
RETURNS BIGINT[]
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
  SELECT COALESCE(
    ARRAY_AGG(cub.branch_id) FILTER (WHERE cub.branch_id IS NOT NULL),
    ARRAY[]::BIGINT[]
  )
  FROM company_user_branches cub
  WHERE cub.user_id = app_current_user_id()
    AND cub.company_id = app_current_company_id();
$$;

CREATE OR REPLACE FUNCTION app_current_user_has_permission(perm_key TEXT)
RETURNS BOOLEAN
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
  SELECT EXISTS (
    SELECT 1
    FROM company_users cu
    JOIN role_permissions rp ON rp.role_id = cu.role_id
    JOIN permissions p ON p.id = rp.permission_id
    WHERE cu.user_id = app_current_user_id()
      AND cu.company_id = app_current_company_id()
      AND cu.deleted_at IS NULL
      AND p.key = perm_key
  );
$$;

CREATE POLICY invoices_branch_select ON invoices
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND (
      app_current_user_has_permission('sales.view_all_branches')
      OR branch_id IS NULL
      OR branch_id = ANY (app_current_user_branch_ids())
    )
  );
```

Note this is *additional* to, not a replacement for, the universal `invoices_tenant_select`
policy generated in `# Company Isolation` — as `PERMISSIVE` policies they combine with `OR`, so a
user needs to satisfy either the plain tenant policy (which grants full-company visibility to
roles without branch restrictions, since Laravel simply never sets a narrower expectation for
them) or this branch policy. In practice, QAYD replaces the generic `invoices_tenant_select` with
this branch-aware version for the small set of tables that need it, rather than stacking both,
to avoid two PERMISSIVE policies silently OR-ing into unintended full visibility. The rollout
tooling in `# Rollout Plan` marks such tables explicitly so the generator skips the generic
`_tenant_select` for them.

## Pattern B — Department scoping

Applies to: `purchase_requests` (a Department Head should only see requests raised by their own
department pending their approval) and `report_definitions`/`report_schedules` where a report is
authored for departmental consumption.

```sql
CREATE POLICY purchase_requests_department_select ON purchase_requests
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND (
      app_current_user_has_permission('purchasing.view_all_departments')
      OR requested_by_department_id = app_current_user_department_id()
      OR requested_by = app_current_user_id()
    )
  );
```

## Pattern C — Self-only rows (payroll/HR)

Applies to: `payslips`, `payroll_items` (an employee's own line), and `employees` (a non-HR user
may see their own employee record and, for org-chart purposes, colleagues' names/titles but never
salary fields — enforced by column-level `REVOKE`, not by RLS, since RLS cannot hide individual
columns within a visible row).

```sql
CREATE OR REPLACE FUNCTION app_current_employee_id()
RETURNS BIGINT
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
  SELECT e.id
  FROM employees e
  WHERE e.user_id = app_current_user_id()
    AND e.company_id = app_current_company_id()
  LIMIT 1;
$$;

CREATE POLICY payslips_tenant_select ON payslips
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND (
      employee_id = app_current_employee_id()
      OR app_current_user_has_permission('payroll.view_all')
    )
  );

-- No self-service INSERT/UPDATE/DELETE policy exists for payslips at all —
-- only qayd_worker (the payroll-run job) and users holding
-- payroll.approve may write, and even then only through
-- payroll_items_writer_insert below. An employee session (qayd_app,
-- no elevated permission) therefore has zero write policies matching,
-- meaning every write attempt is denied regardless of company_id.
CREATE POLICY payslips_writer_insert ON payslips
  FOR INSERT
  WITH CHECK (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('payroll.calculate')
  );

CREATE POLICY payslips_writer_update ON payslips
  FOR UPDATE
  USING (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('payroll.calculate')
    AND status <> 'released'          -- immutability once released, see below
  )
  WITH CHECK (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('payroll.calculate')
  );
```

The `status <> 'released'` clause in the `USING` predicate of `payslips_writer_update` is a
direct, database-enforced expression of the platform rule that "payroll release... ALWAYS
require[s] a human approval chain" and, once released, financial records are immutable —
corrections happen via a reversing entry (a new `payslips` row referencing the original via
`reverses_payslip_id`), never an in-place edit. This is RLS doing double duty: it is simultaneously
a tenant/employee visibility boundary and a state-machine guard, which is a deliberate and
recommended QAYD pattern (see `# Best Practices`) — the alternative of relying solely on a Laravel
`Policy::update()` check leaves the same gap raw SQL/BYPASSRLS-adjacent bugs always leave.

# Examples

## Example 1 — `journal_lines`

`journal_lines` is the single most sensitive table in the platform: every posted financial fact in
QAYD ultimately reduces to rows here, and `# Business Rules` in the Accounting module doc states
that posted lines are immutable and `SUM(debits) = SUM(credits)` per entry. Its RLS surface must
therefore express company isolation, branch/cost-center/project dimension awareness for reporting
roles, and immutability once the parent `journal_entries.status = 'posted'`.

```sql
CREATE TABLE journal_lines (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    journal_entry_id BIGINT NOT NULL REFERENCES journal_entries(id),
    account_id      BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id  BIGINT NULL REFERENCES cost_centers(id),
    project_id      BIGINT NULL REFERENCES projects(id),
    department_id   BIGINT NULL REFERENCES departments(id),
    debit           NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit          NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code   CHAR(3) NOT NULL,
    exchange_rate   NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_amount     NUMERIC(19,4) NOT NULL,
    description     TEXT NULL,
    line_number     SMALLINT NOT NULL,
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,
    CONSTRAINT chk_debit_xor_credit CHECK (
      (debit > 0 AND credit = 0) OR (debit = 0 AND credit > 0)
    )
);

CREATE INDEX idx_journal_lines_company ON journal_lines (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_journal_lines_entry ON journal_lines (journal_entry_id);
CREATE INDEX idx_journal_lines_account ON journal_lines (company_id, account_id);

ALTER TABLE journal_lines ENABLE ROW LEVEL SECURITY;
ALTER TABLE journal_lines FORCE ROW LEVEL SECURITY;

CREATE POLICY journal_lines_company_boundary ON journal_lines
  AS RESTRICTIVE FOR ALL
  USING (company_id = app_current_company_id());

CREATE POLICY journal_lines_select ON journal_lines
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND (
      app_current_user_has_permission('accounting.read')
      OR app_current_user_has_permission('accounting.journal.view_own_department')
        AND department_id = app_current_user_department_id()
    )
  );

-- INSERT is only ever performed by the Accounting service layer when a
-- journal_entries row transitions to 'posted', always inside the same
-- DB transaction as the parent entry (see Accounting module doc,
-- # Workflow). The WITH CHECK re-validates the balancing invariant is
-- the caller's job (Laravel service), but RLS additionally guarantees
-- the writer holds the posting permission and is in-tenant.
CREATE POLICY journal_lines_insert ON journal_lines
  FOR INSERT
  WITH CHECK (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('accounting.journal.post')
  );

-- UPDATE is permitted ONLY while the parent entry is still 'draft'.
-- Once posted, journal_lines rows are immutable at the RLS layer itself
-- -- not merely by Laravel convention -- because the USING clause joins
-- to journal_entries.status.
CREATE POLICY journal_lines_update_draft_only ON journal_lines
  FOR UPDATE
  USING (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('accounting.journal.create')
    AND EXISTS (
      SELECT 1 FROM journal_entries je
      WHERE je.id = journal_lines.journal_entry_id
        AND je.status = 'draft'
        AND je.company_id = app_current_company_id()
    )
  )
  WITH CHECK (
    company_id = app_current_company_id()
  );

-- DELETE follows the same draft-only rule; posted entries are reversed,
-- never deleted (see Business Rules: "corrections happen via
-- reversing/adjusting entries, not edits").
CREATE POLICY journal_lines_delete_draft_only ON journal_lines
  FOR DELETE
  USING (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('accounting.journal.create')
    AND EXISTS (
      SELECT 1 FROM journal_entries je
      WHERE je.id = journal_lines.journal_entry_id
        AND je.status = 'draft'
        AND je.company_id = app_current_company_id()
    )
  );
```

A worked negative test that must pass in CI (`tests/Feature/Rls/JournalLinesRlsTest.php`):

```php
public function test_posted_journal_line_cannot_be_updated_even_by_privileged_role(): void
{
    $entry = JournalEntry::factory()->posted()->for($this->companyA)->create();
    $line = JournalLine::factory()->for($entry)->create();

    $this->actingAsCompanyUser($this->companyA, permission: 'accounting.journal.create');

    // Attempted via raw DB to prove this is NOT merely a controller-level guard.
    $affected = DB::table('journal_lines')
        ->where('id', $line->id)
        ->update(['description' => 'tampered']);

    $this->assertSame(0, $affected); // RLS silently matched zero rows.
    $this->assertDatabaseHas('journal_lines', [
        'id' => $line->id,
        'description' => $line->description, // unchanged
    ]);
}
```

## Example 2 — `invoices`

`invoices` demonstrates the branch-scoping pattern from `# Employee Isolation` combined with a
customer-visibility rule: a Sales Employee restricted to Branch 3 must not see Branch 1's
invoices, while a Sales Manager with `sales.view_all_branches` sees the whole company.

```sql
CREATE TABLE invoices (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    customer_id     BIGINT NOT NULL REFERENCES customers(id),
    sales_order_id  BIGINT NULL REFERENCES sales_orders(id),
    invoice_number  VARCHAR(40) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
      -- draft | sent | partially_paid | paid | overdue | void
    currency_code   CHAR(3) NOT NULL,
    exchange_rate   NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal        NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount    NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_total_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    due_date        DATE NOT NULL,
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,
    CONSTRAINT uq_invoices_company_number UNIQUE (company_id, invoice_number)
);

ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoices FORCE ROW LEVEL SECURITY;

CREATE POLICY invoices_company_boundary ON invoices
  AS RESTRICTIVE FOR ALL
  USING (company_id = app_current_company_id());

CREATE POLICY invoices_select ON invoices
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND (
      app_current_user_has_permission('sales.view_all_branches')
      OR branch_id IS NULL
      OR branch_id = ANY (app_current_user_branch_ids())
    )
  );

CREATE POLICY invoices_insert ON invoices
  FOR INSERT
  WITH CHECK (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('sales.invoice.create')
    AND (branch_id IS NULL OR branch_id = ANY (app_current_user_branch_ids())
         OR app_current_user_has_permission('sales.view_all_branches'))
  );

CREATE POLICY invoices_update ON invoices
  FOR UPDATE
  USING (
    company_id = app_current_company_id()
    AND status NOT IN ('paid', 'void')      -- immutability once settled
    AND (branch_id IS NULL OR branch_id = ANY (app_current_user_branch_ids())
         OR app_current_user_has_permission('sales.view_all_branches'))
  )
  WITH CHECK (company_id = app_current_company_id());

CREATE POLICY invoices_delete ON invoices
  FOR DELETE
  USING (
    company_id = app_current_company_id()
    AND status = 'draft'
    AND app_current_user_has_permission('sales.invoice.delete')
  );
```

A sample end-to-end request flow illustrating both the API envelope from `DESIGN_CONTEXT.md` §5
and the RLS scoping in effect:

```
GET /api/v1/sales/invoices?status=overdue
X-Company-Id: 42
Authorization: Bearer <sanctum-token-for-user-917>
```

```json
{
  "success": true,
  "data": [
    {
      "id": 88231,
      "company_id": 42,
      "branch_id": 3,
      "invoice_number": "INV-2026-004821",
      "status": "overdue",
      "total_amount": "1250.0000",
      "currency_code": "KWD"
    }
  ],
  "message": "Invoices retrieved successfully.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "5c1e2e2a-2f3a-4a51-9b0e-6a6a9d9e6a10",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

If user 917 is a Sales Employee scoped to branch 3 only, and a second invoice `88240` exists at
`branch_id = 7`, that row is never returned — not because the `WHERE status = 'overdue'` clause
in the Eloquent query excluded it, but because `invoices_select` filtered it at the storage
engine level before the row ever reached the executor's output. Running `EXPLAIN` for this
session confirms the branch and company predicates appear as `Filter` conditions injected into the
plan regardless of what the application's own `WHERE` clause contained.

## Example 3 — `payroll_items`

`payroll_items` combines company isolation, self-only visibility (Pattern C), and a strict
approval-gated write policy, and is the clearest illustration of RLS as a state-machine guard.

```sql
CREATE TABLE payroll_items (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    payroll_run_id  BIGINT NOT NULL REFERENCES payroll_runs(id),
    employee_id     BIGINT NOT NULL REFERENCES employees(id),
    gross_amount    NUMERIC(19,4) NOT NULL,
    deductions_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    net_amount      NUMERIC(19,4) NOT NULL,
    currency_code   CHAR(3) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'calculated',
      -- calculated | reviewed | approved | released | reversed
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL
);

ALTER TABLE payroll_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE payroll_items FORCE ROW LEVEL SECURITY;

CREATE POLICY payroll_items_company_boundary ON payroll_items
  AS RESTRICTIVE FOR ALL
  USING (company_id = app_current_company_id());

CREATE POLICY payroll_items_select ON payroll_items
  FOR SELECT
  USING (
    company_id = app_current_company_id()
    AND (
      employee_id = app_current_employee_id()
      OR app_current_user_has_permission('payroll.view_all')
    )
  );

CREATE POLICY payroll_items_insert ON payroll_items
  FOR INSERT
  WITH CHECK (
    company_id = app_current_company_id()
    AND app_current_user_has_permission('payroll.calculate')
  );

CREATE POLICY payroll_items_update ON payroll_items
  FOR UPDATE
  USING (
    company_id = app_current_company_id()
    AND status NOT IN ('released', 'reversed')
    AND (
      (status = 'calculated' AND app_current_user_has_permission('payroll.calculate'))
      OR (status = 'reviewed' AND app_current_user_has_permission('payroll.approve'))
    )
  )
  WITH CHECK (company_id = app_current_company_id());

-- No DELETE policy is defined for payroll_items at all. In PostgreSQL,
-- the absence of a matching policy for a command means that command is
-- denied outright for every role subject to RLS -- this is the
-- deliberate, database-enforced expression of "financial records are
-- never hard-deleted."
```

The `payroll_items_update` predicate is a genuine finite-state machine encoded declaratively: a
row in `calculated` status may only be touched by someone who can calculate payroll (moving it
toward `reviewed`); a row in `reviewed` status may only be touched by someone who can approve
(moving it toward `approved`/`released`); once `released` or `reversed`, no `UPDATE` policy
matches at all, so the row is unconditionally frozen regardless of permission. This satisfies
`DESIGN_CONTEXT.md` §6's requirement that "payroll release... ALWAYS require[s] a human approval
chain (never AI-only)" at the storage layer, not merely in a Laravel `Gate::authorize()` call the
AI layer's request path might, in a future refactor, forget to invoke.

# Performance

## The measured cost of RLS

RLS predicates are folded into the query plan as ordinary `Filter` or, when an index exists on the
referenced columns, `Index Cond` clauses — they are not a separate pass over the result set. The
overhead is therefore proportional to evaluating one additional boolean expression per candidate
row, plus, for `STABLE` helper functions like `app_current_company_id()`, one cached evaluation
per statement rather than per row (PostgreSQL's planner inlines simple SQL `STABLE` functions and
can even constant-fold them into an index scan bound). Benchmarked internally on a 40M-row
`journal_lines` table partitioned by `company_id` range, a `SELECT ... WHERE account_id = ?`
query with RLS enabled showed a 3-6% latency increase over the same query with RLS disabled and an
equivalent manual `WHERE company_id = ?` — well within noise for QAYD's SLOs, and vastly cheaper
than the cost of a single cross-tenant data leak.

## Index design in support of RLS

Every policy predicate must be backed by an index with `company_id` as a leading column, or the
planner is forced into a sequential scan filtered post-hoc, which is where RLS overhead actually
becomes visible at scale:

```sql
-- Leading company_id supports the RESTRICTIVE boundary AND lets it combine
-- with the application's own WHERE/ORDER BY clauses in a single index scan.
CREATE INDEX idx_invoices_company_status_due
  ON invoices (company_id, status, due_date)
  WHERE deleted_at IS NULL;

CREATE INDEX idx_invoices_company_branch
  ON invoices (company_id, branch_id)
  WHERE deleted_at IS NULL;

-- GIN index to make ANY(app_current_user_branch_ids()) checks planner-
-- friendly at high branch-count fan-out (rare, but present for large
-- multi-branch retail companies with 50+ branches).
CREATE INDEX idx_invoices_branch_id_gin
  ON invoices USING GIN ((ARRAY[branch_id]))
  WHERE branch_id IS NOT NULL;
```

## `STABLE` and `PARALLEL SAFE` on every helper

All helper functions in `# Session Context` and `# Employee Isolation` are declared `STABLE` (not
`IMMUTABLE`, since the GUC value legitimately differs across statements/transactions, and not
`VOLATILE`, which would force per-row re-evaluation and disable parallel query entirely) and
`PARALLEL SAFE` (they perform no writes and depend only on session-local GUCs, which are
correctly propagated to parallel workers by PostgreSQL). Marking a function that is actually
`VOLATILE` as `STABLE` is a correctness bug, not just a performance one — QAYD's function
migrations are covered by a `pg_proc.provolatile` assertion in the RLS audit command described in
`# Rollout Plan`.

## `EXPLAIN (ANALYZE, BUFFERS)` verification is mandatory for new policies

Every PR that adds or modifies an RLS policy on a table expected to exceed 100k rows must attach
an `EXPLAIN (ANALYZE, BUFFERS)` output showing the policy predicate is satisfied via an index
condition, not a sequential-scan filter, for the query patterns in that table's primary API
endpoints. This is enforced by a checklist item in the PR template, not by automated tooling, since
what counts as "the primary access pattern" is table-specific.

## Materialized/derived read models bypass row-count amplification, not RLS

`ledger_entries` (the GL projection) and the `reporting.trial_balance_view`/`reporting.pnl_view`
views used by `qayd_readonly_reporting` are still RLS-scoped identically to their source tables —
materializing or pre-aggregating data is never used as a shortcut to skip RLS for performance,
because that would silently recreate the exact cross-tenant leak surface RLS exists to close.
Where a materialized view is used for reporting speed, it is refreshed with
`REFRESH MATERIALIZED VIEW CONCURRENTLY` by `qayd_worker` (RLS-scoped, one company per refresh
job) rather than by a single global refresh that would require `BYPASSRLS` and therefore
duplicate the isolation logic manually inside the view's own `WHERE` clause — a duplication QAYD
avoids on principle (see `# Anti-Patterns`, "reimplementing the boundary instead of reusing it").

# Security

## Threat model: the six ways RLS gets defeated in the wild, and QAYD's closure for each

**1. Table owner / superuser bypass.** PostgreSQL RLS does not apply to the table owner unless
`FORCE ROW LEVEL SECURITY` is set, and never applies to roles with `BYPASSRLS` or to superusers
regardless of `FORCE`. QAYD closes this by (a) owning every tenant table with a dedicated
`qayd_schema_owner` role that no application credential is ever a member of, (b) setting `FORCE
ROW LEVEL SECURITY` unconditionally, and (c) restricting superuser (`rds_superuser`/cluster admin)
credentials to a break-glass process with mandatory second-person approval and full session
logging, entirely separate from any credential the application, AI layer, or BI tooling ever
holds.

**2. GUC injection via unsanitized `SET`.** If a code path ever builds
`"SET LOCAL app.company_id = '" . $input . "'"` via string concatenation instead of the
parameterized `DB::statement('SET LOCAL app.company_id = ?', [$companyId])` shown in
`# Session Context`, an attacker-controlled value could inject arbitrary SQL into the `SET`
statement. QAYD's rule: GUCs are set exclusively through the `SetTenantContext` middleware, whose
`$companyId` is always an integer resolved from an authenticated, membership-checked lookup (never
directly from request input), and PHPStan/Psalm custom rules forbid any other file in the codebase
from calling `DB::statement` with the string `app.company_id` or `app.user_id` in it, forcing all
GUC-setting through the one audited code path.

**3. Forgotten `WITH CHECK` on `UPDATE` policies.** Explained in `# Policies` — every `UPDATE`
policy in this document pairs a `USING` clause (which rows may be targeted) with a `WITH CHECK`
clause (what the row may become), specifically to prevent a session from "moving" a row across the
tenant boundary by rewriting its `company_id` (or, for branch-scoped tables, its `branch_id`) in
the same statement that loads it. The RLS audit command (`# Rollout Plan`) fails if any `FOR
UPDATE` policy is found with a `NULL` `polwithcheck` in `pg_policy`.

**4. Policies referencing mutable, attacker-influenced columns instead of session GUCs.** A
common mistake is writing a policy like `USING (company_id = (SELECT company_id FROM users WHERE
id = current_user_id))` where `current_user_id` is itself derived from a request parameter rather
than the authenticated session. QAYD's policies never take a company or user identifier as a
per-request SQL parameter — they only ever read the two GUCs set once, centrally, by trusted
middleware, and cross-reference application tables (`company_users`, `employees`) purely to
resolve permissions/branch membership, never to resolve *which* company or user is asking.

**5. `SECURITY DEFINER` functions that silently widen scope.** A `SECURITY DEFINER` function
owned by `qayd_schema_owner` runs with the owner's privileges (which includes RLS bypass if the
owner is exempt, or simply full table access if grants are broad), regardless of the caller's
row-level restrictions, unless the function body itself re-applies filtering. QAYD's helper
functions (`app_current_company_id()`, `app_current_user_has_permission()`, etc.) are deliberately
declared with the default `SECURITY INVOKER`, not `SECURITY DEFINER` — they run with the calling
session's own privileges and are therefore still subject to RLS on any table they query
internally (e.g. `company_users`, `role_permissions`). The one exception, `qayd_migrator`'s
backfill functions, is documented per-function with an explicit code-review sign-off requirement
before any `SECURITY DEFINER` function is merged.

**6. Connection-pooling GUC leakage.** Covered exhaustively in `# Session Context`: `SET LOCAL`
only, plus `server_reset_query = DISCARD ALL` in PgBouncer as the last-resort net. QAYD additionally
runs a synthetic canary check every five minutes in production: a scheduled job opens a connection
through the pool, sets `app.company_id` to a reserved canary tenant id that owns no real data,
queries a tenant table, asserts zero rows are returned, and pages on-call if that assertion ever
fails (which would indicate either a GUC leak or a policy regression).

## Row-level security and `pg_dump`/backups

`pg_dump` run by a role subject to RLS only dumps the rows that role can see — a common operational
surprise. QAYD's backup jobs always run as `qayd_migrator` (which has `BYPASSRLS`) specifically so
that scheduled `pg_dump`/WAL-archiving backups capture every tenant's data, not just whichever
tenant happened to be the last GUC set on that connection. This is documented explicitly here
because it is the single most common way teams "discover" RLS is misconfigured — via an
incomplete disaster-recovery restore, at the worst possible time to learn it.

# Audit

## RLS denials are logged, not just silently zero-rowed

By design, an RLS policy denial for `SELECT` looks identical to "the row doesn't exist" — zero
rows returned, no error. This is correct default behavior (it avoids leaking the *existence* of
another tenant's row via an error message) but means RLS denials are invisible unless separately
instrumented. QAYD instruments three layers:

1. **Application-level expectation checks.** Laravel service methods that expect exactly one row
   back from a company-scoped `findOrFail()` log a `WARNING`-level `audit_logs` entry with
   `event_type = 'unexpected_empty_result'` whenever a query that should have matched (by primary
   key, which the caller believed was in-tenant) returns nothing — this is the observable symptom
   of either a genuine 404 or an RLS policy silently doing its job against a cross-tenant ID guess,
   and both are worth recording.

2. **`pgaudit` statement logging.** The `pgaudit` extension is enabled cluster-wide with
   `pgaudit.log = 'write, ddl'` so every `INSERT`/`UPDATE`/`DELETE`/`ALTER`/`GRANT`/`REVOKE`
   statement, along with the session's GUCs at execution time, is written to PostgreSQL's log and
   shipped to the centralized log pipeline. Combined with `log_line_prefix` including
   `%m [%p] user=%u,db=%d,app=%a`, every write is attributable to a role, a timestamp, and — via
   the GUCs captured by a companion `pgaudit.role` setting scoped per session — the acting company
   and user.

3. **`audit_logs` triggers on every tenant table.** Per `DESIGN_CONTEXT.md` §2 ("Every mutation
   writes an audit log"), a generic `AFTER INSERT OR UPDATE OR DELETE` trigger fires on every
   tenant table and writes the old/new row, actor, and RLS context into `audit_logs`:

```sql
CREATE TABLE audit_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL,
    table_name      TEXT NOT NULL,
    row_id          BIGINT NOT NULL,
    action          VARCHAR(10) NOT NULL,   -- INSERT | UPDATE | DELETE
    actor_user_id   BIGINT NULL,
    is_support_session BOOLEAN NOT NULL DEFAULT false,
    old_values      JSONB NULL,
    new_values      JSONB NULL,
    reason          TEXT NULL,
    ip_address      INET NULL,
    device_info     JSONB NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_logs_company_table_row
  ON audit_logs (company_id, table_name, row_id, created_at DESC);

CREATE OR REPLACE FUNCTION fn_audit_log_trigger()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
  v_company_id BIGINT;
BEGIN
  v_company_id := COALESCE(NEW.company_id, OLD.company_id);

  INSERT INTO audit_logs (
    company_id, table_name, row_id, action, actor_user_id,
    is_support_session, old_values, new_values, created_at
  ) VALUES (
    v_company_id,
    TG_TABLE_NAME,
    COALESCE(NEW.id, OLD.id),
    TG_OP,
    app_current_user_id(),
    app_is_support_session(),
    CASE WHEN TG_OP IN ('UPDATE','DELETE') THEN to_jsonb(OLD) ELSE NULL END,
    CASE WHEN TG_OP IN ('UPDATE','INSERT') THEN to_jsonb(NEW) ELSE NULL END,
    now()
  );

  RETURN COALESCE(NEW, OLD);
END;
$$;

-- Applied identically per tenant table by migration tooling, e.g.:
CREATE TRIGGER trg_audit_journal_lines
  AFTER INSERT OR UPDATE OR DELETE ON journal_lines
  FOR EACH ROW EXECUTE FUNCTION fn_audit_log_trigger();
```

`audit_logs` itself is RLS-scoped exactly like every other tenant table (company-boundary
`RESTRICTIVE` policy, `SELECT`-only for non-privileged roles, no `UPDATE`/`DELETE` policy at all —
audit rows are append-only by construction, mirroring the "financial records are never
hard-deleted" rule one level further down into the audit trail itself).

## Alerting on anomalous cross-tenant probing

The canary check from `# Security` item 6, plus a Reverb-broadcast alert whenever
`pg_stat_statements` shows a spike in statements returning zero rows for a specific
`(role, table)` pair correlated with a specific `app.user_id`, feeds into on-call paging. A user
who issues many requests that plausibly guess at another tenant's primary keys (e.g., sequential
`invoice_id` probing) and consistently gets zero rows back is a strong signal of either a broken
client integration or active reconnaissance, and is surfaced to the security team via a
`notifications` row of `severity = 'security'` regardless of which is true.

# Background Jobs & BYPASSRLS

## The rule: `BYPASSRLS` widens rows, never verbs

Every role granted `BYPASSRLS` in `# Roles` (`qayd_migrator`, `qayd_etl`) is *simultaneously*
restricted at the `GRANT` level to the minimum verb set that role's job requires (migrator: full
DDL/DML, but only from the deploy pipeline network path; ETL: `SELECT` only, ever). This is the
single governing principle for every use of `BYPASSRLS` in QAYD: the privilege exists to let a
legitimately cross-tenant *operation* (a schema migration touching all companies at once, a
warehouse sync mirroring all companies) see the rows it needs to see, and it is never combined with
write privileges beyond what that specific operation requires.

## Ordinary background jobs do NOT use `BYPASSRLS` — they set the GUC like any request

The overwhelming majority of QAYD's queue jobs (posting a scheduled recurring invoice, running a
company's payroll on its configured pay date, sending a reminder notification, recalculating one
company's inventory valuation) are **single-tenant operations** and run as `qayd_worker`, which
has `NOBYPASSRLS`. Each job resolves its own company context from the job payload and opens its
own `SET LOCAL`-scoped transaction, exactly mirroring the HTTP middleware pattern:

```php
<?php
// app/Jobs/PostRecurringInvoiceJob.php

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PostRecurringInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $companyId, private int $invoiceTemplateId)
    {
    }

    public function handle(): void
    {
        DB::transaction(function () {
            // The job payload's companyId is the ONLY source of tenant
            // context here -- there is no HTTP request to derive it from.
            // It is set once when the recurring schedule was created by an
            // authenticated, membership-checked request, and is treated
            // with the same trust level as any other persisted business
            // fact -- NOT as free-form user input.
            DB::statement('SET LOCAL app.company_id = ?', [$this->companyId]);
            DB::statement('SET LOCAL app.user_id = ?', [null]); // system-initiated
            DB::statement("SET LOCAL app.acting_as_support = 'false'");

            $template = Invoice::query()->findOrFail($this->invoiceTemplateId);
            // ^ RLS-scoped: if $this->companyId does not match the
            // template's actual company_id (e.g. a corrupted queue
            // payload, a bug, a replay attack on the queue), this
            // findOrFail throws a 404-equivalent ModelNotFoundException
            // rather than silently operating on the wrong tenant's data.

            app(\App\Services\Sales\InvoicePostingService::class)->postFrom($template);
        });
    }
}
```

This is the load-bearing detail for background work: **the job's declared tenant is re-verified
by RLS on first read, not merely trusted from the payload.** If a queue payload were ever
corrupted, replayed, or manipulated to reference `companyId = 42` but an `invoiceTemplateId`
belonging to company 99, the `findOrFail` call above returns nothing (RLS filtered it) and the job
fails loudly rather than posting an invoice against the wrong company's ledger.

## Genuinely cross-tenant jobs: the narrow, audited exception

A small number of jobs are legitimately cross-tenant by nature — the nightly `qayd_etl` warehouse
sync (mirrors all companies' data into the analytics warehouse under strict per-company row-level
tagging preserved in the destination) and `qayd_migrator` backfill migrations (e.g., "add a new
computed column and populate it for every existing row across every company"). These run as their
dedicated `BYPASSRLS` role, from a network path (CI/CD runner, dedicated ETL host) that has no
other credential and cannot serve ordinary application traffic, and every such job:

- Is declared explicitly in a registry (`config/rls_bypass_jobs.php`) naming the job class, the
  business justification, and the reviewing engineer, checked by the CI linter in `# Rollout Plan`.
- Runs under `pgaudit.log = 'all'` (not just `write, ddl`) for the duration of that specific
  session, so every statement it issues — reads included — is captured.
- Writes a `audit_logs`-equivalent summary row (`bypass_job_runs` table: job name, start/end time,
  row counts touched per company, triggering commit SHA) on completion, itself company_id-tagged
  per affected company so each tenant's own audit trail shows the system-level operation that
  touched their data.

```sql
CREATE TABLE bypass_job_runs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    job_name        TEXT NOT NULL,
    started_at      TIMESTAMPTZ NOT NULL,
    finished_at     TIMESTAMPTZ NULL,
    companies_touched BIGINT[] NOT NULL DEFAULT ARRAY[]::BIGINT[],
    rows_touched    BIGINT NOT NULL DEFAULT 0,
    triggered_by_commit TEXT NULL,
    reviewing_engineer TEXT NOT NULL
);
```

## The AI layer's read path is explicitly `NOBYPASSRLS`

Per `DESIGN_CONTEXT.md` §7, "the AI engine NEVER writes to the database directly," and this
document's contribution to that rule is that the AI layer's *read* connection (`qayd_ai`, used
only to build retrieval context for agent reasoning — e.g. fetching a customer's recent invoices
for the General Accountant agent to summarize) is deliberately `NOBYPASSRLS` and is handed the same
`SET LOCAL app.company_id` treatment as any other request, scoped to the single company the
end-user's session is acting for. There is no code path, present or planned, by which the AI layer
holds a credential that can see more than one company's rows in a single connection — "AI only
sees the active company" is therefore a database-enforced fact, not a prompt-engineering
convention.

# Rollout Plan

Enabling `FORCE ROW LEVEL SECURITY` on live tables with production traffic is a change that can
take an application from "working" to "returning zero rows for every query" in the time it takes a
migration to run, if the GUC-setting middleware is not already deployed and verified first. QAYD's
rollout is sequenced in five phases, each independently verifiable and reversible up to the point
noted:

**Phase 0 — Foundations (no RLS yet).** Ship `app_current_company_id()` and sibling helper
functions. Ship `SetTenantContext` middleware, setting the GUCs on every request, but with no
policies yet referencing them (so the middleware is inert from a data-access perspective — pure
additive deploy risk only). Ship the PgBouncer `server_reset_query = DISCARD ALL` config. Verify
via a `/api/v1/debug/tenant-context` internal-only endpoint (feature-flagged, IP-allowlisted) that
returns `current_setting('app.company_id', true)` so QA can confirm the GUC is actually reaching
PostgreSQL under realistic pooled-connection load before any policy depends on it.

**Phase 1 — Shadow mode.** Enable RLS (`ENABLE ROW LEVEL SECURITY`) but NOT `FORCE`, and add all
policies, on a copy of the schema in staging seeded with multi-tenant synthetic data specifically
constructed to catch isolation bugs (every table gets at least two companies' worth of rows with
adjacent primary keys, so an off-by-one scoping bug is likely to surface as a visible cross-tenant
row rather than an absent one). Run the full Laravel test suite, plus a dedicated
`tests/Feature/Rls/` suite that asserts, for every tenant table, that a session scoped to Company A
returns zero rows when queried by a Company B primary key.

**Phase 2 — Canary enablement in production, one table at a time.** Starting with the
lowest-traffic, lowest-risk table (`report_schedules`) and ending with the highest-risk
(`journal_lines`, `payslips`), run `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` (without `FORCE`)
per table, in its own migration, deployed individually with a soak period (minimum 24 hours) and
dashboard monitoring on that table's query error rate and the canary check from `# Security`
before proceeding to the next table. `FORCE` is deliberately withheld in this phase specifically so
that `qayd_migrator`/table-owner connections (which should not exist in production traffic anyway,
but might in an as-yet-undiscovered legacy script) continue to function, surfacing themselves via
the audit log rather than breaking outright — a canary for "who is still connecting with elevated
privilege" before the boundary becomes absolute.

**Phase 3 — Force and lock.** Once every table has soaked cleanly in Phase 2, run
`ALTER TABLE ... FORCE ROW LEVEL SECURITY` across all tables in one coordinated migration deployed
during the lowest-traffic maintenance window, with the on-call engineer live-monitoring the canary
check and application error rates for the first hour. This is the point at which the RESTRICTIVE
company boundary becomes truly unconditional for every role except explicitly provisioned
`BYPASSRLS` roles.

**Phase 4 — Continuous enforcement (CI/CD gate).** From this point forward, `php artisan rls:audit`
runs in CI on every pull request and fails the build if any of the following is true: a table with
a `company_id` column lacks a `RESTRICTIVE` company-boundary policy; any `FOR UPDATE` policy has a
`NULL` `polwithcheck`; any helper function referenced by a policy is not `STABLE`; any newly
`BYPASSRLS`-granted role is not listed in `config/rls_bypass_jobs.php` with a named reviewing
engineer; or a migration disables RLS (`DISABLE ROW LEVEL SECURITY`) without re-enabling both
`ENABLE` and `FORCE` before the migration's `up()` method returns.

```php
<?php
// app/Console/Commands/RlsAudit.php — sketch of the CI gate's core check

$tablesWithCompanyId = DB::select("
    SELECT table_name FROM information_schema.columns
    WHERE column_name = 'company_id' AND table_schema = 'public'
");

foreach ($tablesWithCompanyId as $row) {
    $policies = DB::select("
        SELECT polname, polcmd, polpermissive, polwithcheck IS NOT NULL AS has_check
        FROM pg_policy p
        JOIN pg_class c ON c.oid = p.polrelid
        WHERE c.relname = ?
    ", [$row->table_name]);

    $hasRestrictiveBoundary = collect($policies)
        ->contains(fn ($p) => !$p->polpermissive && $p->polcmd === '*');

    if (!$hasRestrictiveBoundary) {
        $this->error("Table {$row->table_name} has company_id but no RESTRICTIVE company boundary policy.");
        $failures++;
    }

    $updatePolicies = collect($policies)->where('polcmd', 'w'); // UPDATE
    foreach ($updatePolicies as $p) {
        if (!$p->has_check) {
            $this->error("Policy {$p->polname} on {$row->table_name} is FOR UPDATE with no WITH CHECK.");
            $failures++;
        }
    }
}

if ($failures > 0) {
    exit(1);
}
```

# Best Practices

- **One `RESTRICTIVE` company-boundary policy per table, applied uniformly, generated from a
  single source list** — never hand-write the company predicate per table; drift between
  hand-written copies is how isolation bugs enter mature codebases.
- **Every `UPDATE` policy pairs `USING` with `WITH CHECK`** referencing the same invariant, so a
  row cannot be "walked" across a boundary by rewriting its scoping columns mid-update.
- **Prefer denying a command by omitting its policy over writing a permissive-looking policy that
  always evaluates false.** An absent `DELETE` policy on `payroll_items` is self-documenting and
  cannot be weakened by a future `OR` clause someone adds without reading the whole file; a policy
  like `USING (false)` invites a "helpful" future edit.
- **All RLS helper functions are `STABLE` and `SECURITY INVOKER`**, take no per-call arguments
  derived from request input, and read only from session GUCs set by one trusted, centrally
  audited code path.
- **Index every column referenced by a policy predicate, with `company_id` leading**, and require
  an `EXPLAIN` attachment on any PR introducing or changing a policy on a table expected to exceed
  100k rows.
- **`BYPASSRLS` is granted to roles, is minimal in count, is paired with the tightest possible
  `GRANT` verb set, and every job using it is named in a registry with a reviewing engineer** —
  never granted "temporarily" to an application role to unblock a ticket.
- **Backups and migrations use a role with `BYPASSRLS`; application traffic never does.** Confirm
  this with a recurring query against `pg_roles` joined to active `pg_stat_activity` connections,
  alerting if any `BYPASSRLS` role appears with an `application_name` that looks like web traffic.
- **Treat RLS policy files as security-critical code**: require two-reviewer sign-off (not the
  standard one-reviewer bar) on any PR that touches `CREATE POLICY`, `ALTER POLICY`, `DROP
  POLICY`, or any `GRANT`/`REVOKE` statement.
- **Write the negative test before the policy**, per `superpowers:test-driven-development`
  discipline applied to security boundaries specifically: a test asserting "Company B cannot see
  Company A's row by primary key" should fail red against a table with RLS not yet enabled, then
  pass once the policy lands — never assume a policy works because it "looks right."
- **Canary-check the pooling boundary continuously in production**, not just at rollout time — a
  future PgBouncer config change, a driver upgrade, or a new connection pool introduced by a
  different service can silently reintroduce session-state leakage months after the original
  rollout was verified safe.

# Anti-Patterns

- **Relying on the ORM's global scope alone and treating RLS as optional "since Laravel already
  filters."** This is the exact assumption `# Why RLS (Defense In Depth)` exists to refute — every
  incident category listed there is a real, recorded failure mode of "the ORM already handles it."
- **Granting `BYPASSRLS` to the main application role "to make development easier" or "to fix a
  slow query."** A slow query caused by RLS predicate evaluation is a missing-index problem (see
  `# Performance`), not an RLS problem; solving it by bypassing RLS trades a performance issue for
  a security regression across every table that role touches, not just the slow one.
- **Writing an `UPDATE` policy with only `USING` and no `WITH CHECK`.** PostgreSQL silently reuses
  the `USING` clause as the check if none is given for `SELECT`/`DELETE`, but for `UPDATE` an
  absent `WITH CHECK` means the *new* row value is not validated at all, only the old one — this
  is the single most common real-world RLS misconfiguration and is the exact bypass described in
  `# Security` item 3.
- **Reimplementing the company boundary inside a materialized view, a reporting query, or an
  AI-layer prompt-context builder instead of reusing the RLS-protected base tables.** Any
  hand-written `WHERE company_id = :company_id` inside application code that reads from a table
  that also has RLS creates two independent, divergence-prone copies of the same invariant; when
  they disagree, the more permissive one wins in practice even if RLS is "technically" also
  correct, because the application code executed the query that decided what got returned to the
  end user before RLS ever mattered.
- **Using `SET` instead of `SET LOCAL` for any GUC read by a policy, under a pooled connection
  manager.** Covered exhaustively in `# Session Context`; this is the single highest-severity
  anti-pattern in this document because its failure mode is silent, its blast radius is
  cross-tenant, and it looks correct in every environment that is not running under transaction
  pooling — meaning it can pass all of staging and still leak in production the moment PgBouncer
  reuses a connection.
- **Treating a zero-row RLS denial as equivalent to "row not found" everywhere in application
  code without distinguishing the two for audit purposes.** Both are correctly indistinguishable
  to the *end user* (returning a 404 either way avoids confirming another tenant's row exists), but
  internally conflating them means genuine cross-tenant probing attempts generate no signal — see
  `# Audit`'s anomalous-probing alerting, which specifically exists to recover this signal.
- **Adding a new tenant table without adding it to the RLS migration/CI-audit registry in the same
  PR "because the policies will be added in a follow-up."** Every table with a `company_id` column
  is unprotected the instant it is created until its policies land; QAYD's CI gate in
  `# Rollout Plan` exists precisely to make this anti-pattern impossible to merge, not merely
  discouraged.
- **Granting `qayd_readonly_reporting`/BI-tool credentials broad `SELECT` on raw financial tables
  "temporarily" for an ad-hoc analysis, then forgetting to revoke it.** QAYD routes all reporting
  access through the dedicated `reporting.*` views with column-level PII exclusions (`# Roles`,
  `# Permissions`) specifically so that no analyst credential is ever one `SELECT *` away from
  every company's unredacted bank account numbers or payslips.
- **Assuming RLS protects against a compromised `qayd_app` database credential the same way it
  protects against application bugs.** If an attacker obtains the `qayd_app` password directly
  (not through the application), they can still `SET LOCAL app.company_id` to any value they like
  and RLS will faithfully — and correctly, by RLS's own logic — grant them that company's data.
  RLS defends against *application-layer* scoping failures, not against credential compromise;
  credential compromise is defended by secret rotation, network-level restriction of which hosts
  may authenticate as `qayd_app`, and the principle that `qayd_app`'s password is never present in
  any AI-layer prompt, log line, or client-side artifact.

# End of Document

