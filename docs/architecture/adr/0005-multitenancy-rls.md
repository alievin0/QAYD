# ADR-0005: Isolate tenants in one database with Postgres Row-Level Security keyed on company_id

Status: Accepted

Date: 2026-07

## Context

QAYD is multi-tenant: many companies' financial data live in one platform, and a cross-tenant leak is the
single worst failure the system can have — a regulatory and existential event, not a bug ticket. The design
specification ([../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md),
[../../database/ROW_LEVEL_SECURITY.md](../../database/ROW_LEVEL_SECURITY.md), and
[../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) ADR-002) fixes the model
as **shared-database, shared-schema, discriminator column** defended in depth by Row-Level Security. QAYD's
backend is Laravel over managed Postgres ([ADR-0003](./0003-supabase-as-backend.md)): Laravel is the primary
path every business query passes through, and it pins the active company per request. A database-enforced
isolation floor beneath that application path is not merely desirable but essential, so that a raw query, a
background job, a future service, or a misconfigured client can never cross the tenant boundary even if the
application layer forgets to.

## Decision

Every tenant-owned table carries **`company_id BIGINT NOT NULL REFERENCES companies(id)`**, and isolation is
enforced by **Postgres Row-Level Security** as the layer that cannot be forgotten. Each such table runs
`ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY`, with a policy that admits a row only when its
`company_id` matches the active company pinned for the request:
`USING (company_id = current_setting('app.current_company_id', true)::bigint)`. The Laravel backend
authenticates the caller (Sanctum session or RS256 JWT, [ADR-0003](./0003-supabase-as-backend.md)), validates
the requested company against a live **`company_users`** membership row, and pins the active company by issuing
`SET LOCAL app.current_company_id` inside the request's transaction — so the tenant context is derived from a
verified membership, never from client input. When the GUC is unset the policy evaluates false and returns
**zero rows**. Genuinely global reference tables (`account_types`, the permission catalog, system default
roles) carry no `company_id` and are shared read-only. Application code (the `BelongsToCompany` trait and
`CompanyScope` global scope) still filters by `company_id` for correctness and performance, but RLS is the
floor beneath it: a forgotten filter returns zero rows, never another company's ledger.

## Consequences

Positive:
- Isolation survives an application bug. Because RLS runs in the storage engine, a missing `WHERE` clause, a raw query, or a misused client key cannot leak another tenant's data.
- One migration, one backup, one connection pool, one monitoring surface for all tenants — operations scale sublinearly with tenant count.
- The `membership → pinned company → policy` mapping supports users belonging to more than one company cleanly, and the active company is a single pinned scalar (`app.current_company_id`) the backend sets per request from a verified membership.

Negative / trade-offs:
- Every new tenant table must remember to enable and force RLS and ship a correct policy; a table shipped without it is an isolation hole, so this is checked in review and by an automated isolation test suite.
- RLS policies add per-query planning cost and must be indexed (`company_id` leading) to stay fast; a poorly written policy can be a performance cliff.
- Platform-level maintenance that must cross tenants requires an explicit, audited service-role path — the one place isolation is intentionally lifted must stay narrow and logged.

## Alternatives considered

- **Database-per-tenant:** strongest physical isolation, but O(N) operational cost — thousands of databases to migrate, back up, and pool — and cross-tenant platform analytics become an ETL problem.
- **Schema-per-tenant:** strong isolation, but `search_path` juggling defeats the plan cache, migrations run N times, and the catalog bloats past a few thousand schemas.
- **Application-only `WHERE company_id = ?`:** scales well but is only as strong as the discipline of every query ever written; one forgotten clause is a breach — unacceptable as the sole control for financial data.

## Related

- [ADR-0003](./0003-supabase-as-backend.md), [ADR-0004](./0004-postgresql.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-002; [../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md); [../../database/ROW_LEVEL_SECURITY.md](../../database/ROW_LEVEL_SECURITY.md); [../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md)
