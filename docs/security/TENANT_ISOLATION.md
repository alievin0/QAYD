# Tenant Isolation Security — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: TENANT_ISOLATION
---

# Purpose

This document specifies, in security depth, how QAYD guarantees that **no company ever sees another
company's data**. It is the L3/L6/L7 (tenant-resolution, data-access-scoping, and database-RLS)
specialization of the layered model in [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md), and the
security-hardening companion to the database-enforcement mechanics owned by
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) and
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md). Where those describe *how the tenancy
model is shaped and enforced at the database*, this document describes the adversarial view and the
end-to-end control chain: a curious tenant incrementing an id, a hostile tenant smuggling a second
`company_id` into a request body, a forgotten `WHERE company_id = ?` in a future engineer's query, a
pooled database connection leaking one tenant's context onto the next request, a cache key or search
index or storage object bleeding across the boundary.

The cross-tenant boundary is the single assumption the entire multi-tenant business rests on. Company
isolation is not one control — it is **four independent enforcements of the same predicate**: the
HTTP `X-Company-Id` boundary, the Eloquent `CompanyScope` global scope, the Policy re-check, and
PostgreSQL Row-Level Security. An attacker's read must slip past all four; a defender needs only one to
hold. The architecture's stated posture is deliberate and load-bearing: **RLS is the boundary of last
resort; the application-layer scopes are convenience and clarity, not the security guarantee.** Losing
an application scope is a bug the database catches; losing the database enforcement would be the
breach — so the redundancy is asymmetric and intentional.

The platform facts this document builds on are fixed: QAYD runs a single shared-schema PostgreSQL
cluster behind PgBouncer in transaction-pooling mode; every tenant table carries a `company_id`;
tenant context flows from an `X-Company-Id` header validated against `company_users`, into a per-
request `SET LOCAL app.company_id` GUC that RLS reads; the application database roles
(`qayd_app`/`qayd_worker`/`qayd_ai`/`qayd_support`) are all `NOBYPASSRLS`; and a cross-tenant resource
resolves to `404`, never `403`. Everything below hardens those facts against the threat model in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md).

---

# Threat Model / Principles

## The adversary at the tenant boundary

Tenant isolation defends against the **malicious or curious tenant** (adversary class 2 in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md)) — a legitimately authenticated, legitimately
authorized user of Company A who tries to read, write, guess, or infer the existence of Company B's
data. It also defends against the **infrastructure adversary** who reaches a pooled connection, a
cache, a search index, or a backup. Concretely:

- **ID enumeration** — incrementing or guessing a resource id (`/invoices/90441`) hoping the platform
  returns a foreign tenant's row, or leaks its existence via a `403`-vs-`404` distinction.
- **Parameter smuggling** — placing a second `company_id` in a request body, query string, or a
  nested relation, hoping some layer trusts the payload over the resolved tenant context.
- **Header forgery** — presenting an `X-Company-Id` for a company the user does not belong to, hoping
  membership is assumed rather than checked.
- **Unscoped-query bug** — a future engineer writing `DB::table('invoices')->find($id)`, a raw join,
  or an eager-load that bypasses the Eloquent global scope.
- **Pool-leak** — a PgBouncer-reused physical connection carrying a prior tenant's session state onto
  an unrelated request, so RLS faithfully enforces the *wrong* company.
- **Sidecar leak** — a Redis cache key, a search index document, or an R2 object stored without a
  tenant namespace, so one tenant's write is served to another.
- **Cross-tenant write** — an `UPDATE` that rewrites a row's `company_id` to move it into another
  tenant, or an `INSERT` tagged with a foreign `company_id`.

## Principles

- **The boundary is enforced four times, independently.** L3 (middleware), L6 (Eloquent scope), L7
  (RLS) plus the Policy re-check are written by different code and each fails closed. A single missed
  layer is caught by the next.
- **RLS is the boundary; app scopes are convenience.** The database is the authority. Application-layer
  scoping exists for readable, intention-revealing code and query-planner clarity — never as the sole
  guarantee.
- **Fail closed on missing tenant context.** No `app.company_id` GUC means RLS returns **zero rows**,
  never all rows. No resolved company means the request is refused. Absence of context is a denial,
  never an open door.
- **The resolved tenant is authoritative; the payload is not.** `company_id` is derived from the
  validated `X-Company-Id` and never read from a request body. A `company_id` in a payload is ignored
  (or rejected), never trusted.
- **Cross-tenant is invisible.** A foreign-tenant resource returns `404`, never `403`, because a `403`
  confirms the row exists — itself a leak across the boundary.
- **Isolation extends to every store.** PostgreSQL, Redis cache, the search index, R2 storage, and the
  job queue each carry the tenant boundary; a boundary that holds only in the database is not a
  boundary.

---

# Controls

## The `X-Company-Id` boundary and the membership check (L3)

Every authenticated request declares the company it acts on via the `X-Company-Id` header. This is the
*only* source of tenant identity for a request; `company_id` is never read from a request body, query
string, or route parameter. The `ResolveTenantCompany` middleware validates the header against an
**active, non-expired** `company_users` membership before it is trusted for anything — and it is the
bridge that sets the database GUC that L7 depends on.

```php
// app/Http/Middleware/ResolveTenantCompany.php — L3: validate membership, then set the GUC (L7 bridge).
public function handle(Request $request, Closure $next): Response
{
    $user      = $request->user();                       // resolved by Sanctum/JWT upstream
    $companyId = (int) $request->header('X-Company-Id');

    abort_unless($companyId, 403, 'tenant_mismatch');    // no header -> refuse; never default a company

    // Membership check in the application layer BEFORE the GUC is set; RLS re-enforces it at the DB.
    $membership = CompanyUser::query()
        ->where('user_id', $user->id)
        ->where('company_id', $companyId)
        ->where('status', 'active')
        ->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
        ->first();

    if (! $membership) {
        AuditLog::security('tenant.mismatch', $request, ['company_id' => $companyId]);
        return ApiResponse::error('tenant_mismatch', 'You do not have access to this company.', 403);
    }

    // Wrap the whole request in one transaction so SET LOCAL survives every query and rolls back cleanly.
    return DB::transaction(function () use ($request, $next, $user, $companyId, $membership) {
        DB::statement('SET LOCAL app.company_id = ?', [(string) $companyId]);
        DB::statement('SET LOCAL app.user_id = ?', [(string) $user->id]);
        DB::statement('SET LOCAL app.acting_as_support = ?', [$user->is_support_impersonating ? 'true' : 'false']);

        TenantContext::set($companyId, $membership->branch_id);   // consumed by CompanyScope + Policies
        return $next($request);
    });
}
```

Note the two distinct failures: a **missing or malformed** header, and a header for a company the user
does **not** belong to, both return `403 tenant_mismatch` — the header (not a resource) is what is
rejected, so there is no resource whose existence could leak. Resolving the *active* company is tenant
isolation's job; authenticating *who* the user is belongs to [AUTHENTICATION.md](AUTHENTICATION.md),
and deciding *what* they may do belongs to [AUTHORIZATION.md](AUTHORIZATION.md).

## PostgreSQL Row-Level Security — the boundary of last resort (L7)

RLS is the enforcement QAYD trusts even when every application layer above has failed. It is enabled
**and forced** on every tenant table, so no role — including the table owner — silently ignores a
policy. The full policy catalog, GUC contract, helper functions, and role model are owned by
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md); this section records the
security-load-bearing facts.

**The company boundary is `RESTRICTIVE`; everything else is `PERMISSIVE`.** PostgreSQL combines
multiple `PERMISSIVE` policies for the same command with `OR` (broadening) and `RESTRICTIVE` policies
with `AND` (narrowing). QAYD declares the company predicate as a single `RESTRICTIVE` policy applied
to every role, so no combination of additional `PERMISSIVE` visibility rules can ever `OR` its way
across the company boundary. Per-command `PERMISSIVE` policies then grant the *intra*-company
visibility (all-company-rows, branch-scoped, self-only, role-elevated).

```sql
-- The un-bypassable floor: a RESTRICTIVE policy ANDs against every PERMISSIVE policy, for every role.
ALTER TABLE journal_lines ENABLE ROW LEVEL SECURITY;
ALTER TABLE journal_lines FORCE  ROW LEVEL SECURITY;

CREATE POLICY journal_lines_company_boundary ON journal_lines
  AS RESTRICTIVE FOR ALL
  USING (company_id = app_current_company_id());

-- Per-command PERMISSIVE policies grant visibility WITHIN the already-bounded company.
CREATE POLICY journal_lines_tenant_select ON journal_lines
  FOR SELECT USING (company_id = app_current_company_id());

CREATE POLICY journal_lines_tenant_update ON journal_lines
  FOR UPDATE
  USING      (company_id = app_current_company_id())   -- which existing rows may be targeted
  WITH CHECK (company_id = app_current_company_id());   -- what the row may look like AFTER the update
```

Effective visibility for any command is `(RESTRICTIVE company boundary) AND (OR of matching PERMISSIVE
policies)`. Two facts make this more than a checkbox:

- **`WITH CHECK` on `UPDATE`/`INSERT` blocks the cross-tenant write.** Without it, a session could load
  a row it owns and rewrite its `company_id` into another tenant, or insert a row tagged with a foreign
  `company_id`. Both clauses reference `app_current_company_id()`, so a row can never be moved or
  created across the boundary.
- **RLS fails to *empty*, not to *all*.** The helper `app_current_company_id()` returns `NULL` when the
  GUC is unset (`NULLIF(current_setting('app.company_id', true), '')::BIGINT`); `company_id = NULL` is
  never true, so a connection with no tenant context returns **zero rows**. A forgotten `SET LOCAL` is
  a visible, self-announcing bug (a user sees an empty list) rather than a silent catastrophic leak.

**`SET LOCAL` GUC, not `SET`.** The GUC is set transaction-scoped (`SET LOCAL`) so it cannot outlive
the request on a pooled connection. The helper functions are `STABLE PARALLEL SAFE` so the planner may
constant-fold them within a statement (a performance requirement detailed in
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) § Performance), never
`VOLATILE`.

**PG roles are `NOBYPASSRLS`.** The runtime roles `qayd_app`, `qayd_worker`, `qayd_ai`, and
`qayd_support` are created `NOBYPASSRLS` and are subject to every policy; the GUCs are what scope them.
Only two roles carry `BYPASSRLS` — `qayd_migrator` (CI/CD schema only, no network path from the app
tier) and `qayd_etl` (audited, read-only at the `GRANT` level despite the bypass). No application
request path can reach a `BYPASSRLS` role.

## Eloquent `CompanyScope` — fail-closed convenience (L6)

Every tenant model uses a shared trait that registers a global scope, so **every** Eloquent query
against that model — including one written by an engineer who never thought about tenancy — is
automatically constrained to the active company. Critically, the scope is **fail-closed**: with no
resolved tenant context, it injects an impossible predicate (`1 = 0`) rather than returning
unscoped rows, matching the database's fail-to-empty posture.

```php
// app/Models/Concerns/BelongsToCompany.php
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $companyId = TenantContext::companyId();
            $table = $builder->getModel()->getTable();

            if ($companyId) {
                $builder->where("{$table}.company_id", $companyId);
            } else {
                // Fail closed: no tenant context => match nothing, never match everything.
                $builder->whereRaw('1 = 0');
            }
        });

        // Auto-stamp company_id on create; a payload can never set it to a foreign tenant.
        static::creating(function (Model $model) {
            $model->company_id = TenantContext::companyId()
                ?? throw new MissingTenantContextException();      // -> 500, never an untagged insert
        });
    }
}
```

**Raw queries use the `forCompany` macro.** Where a query legitimately bypasses Eloquent
(`DB::table(...)`, a reporting query, a raw join), it must go through the sanctioned `forCompany`
query macro that injects `where company_id = ?` from `TenantContext`; `withoutCompanyScope()` /
`forCompany()`-any exist only for the platform-analytics path guarded by `RequirePlatformAdmin` and
are banned in module code by a Larastan rule. RLS (L7) remains the backstop even for a raw query that
forgets the macro.

## Jobs, events, and workers bind tenant context

A queue worker, scheduled job, or broadcast listener runs with **no ambient request context**, so it
must re-establish the tenant boundary before touching any tenant table. The base `TenantAwareJob`
centralizes this so a subclass cannot forget it: every job carries `companyId`, re-binds
`TenantContext`, and issues `SET LOCAL app.company_id` inside its own transaction before the first
query. This closes the gap where an async path could otherwise run unscoped (see
[SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) § Jobs and Queues).

```php
// A job re-establishes tenant context; without this, a worker query would fail closed (1=0 / zero rows).
final class GenerateStatement extends TenantAwareJob
{
    public function __construct(public readonly int $companyId, public readonly int $customerId) {}

    public function handle(): void
    {
        $this->bindTenant($this->companyId);   // TenantContext::set + SET LOCAL app.company_id, in a txn
        // ... every model query below is now scoped by CompanyScope AND RLS to $this->companyId
    }
}
```

## Composite-unique constraints carry `company_id`

Every uniqueness rule on a tenant table leads with `company_id`, so a "unique" human-readable value
(invoice number, product SKU, account code) is unique *per company*, never globally. This is both a
correctness rule and an isolation rule: a globally-unique constraint would let one tenant probe for
another's existence by collision, and would let one tenant's namespace collide with another's.

```sql
-- Invoice numbers are unique PER COMPANY, never globally. Every index leads with company_id.
CREATE UNIQUE INDEX invoices_company_number_uk
  ON invoices (company_id, invoice_number)
  WHERE deleted_at IS NULL;

-- Foreign keys within a tenant are validated against the same company; a child row can never
-- reference a parent in another tenant (enforced by the FK plus the RESTRICTIVE RLS boundary).
```

The universal "every index leads with `company_id`" rule is owned by
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) § Indexes; its security consequence is
that tenant pruning happens early in the query plan *and* that no uniqueness namespace is shared across
tenants.

## Storage / file isolation

Object storage (Cloudflare R2 / S3) is private — no bucket, prefix, or object is publicly readable and
no long-lived public link is ever minted. Every object lives under a **company-scoped prefix**, and the
object key is an opaque UUID, never a guessable path, so a signature can never be edited onto a
neighboring object. Access is always via a short-TTL signed URL issued only after the
authenticate → resolve-tenant → authorize pipeline confirms the caller may touch that specific object,
and a cross-tenant object request returns `404`. The signed-URL and encryption mechanics are owned by
[ENCRYPTION.md](ENCRYPTION.md) § Signed URLs; the isolation contract is:

```php
// Object keys are company-prefixed opaque UUIDs; access is tenant-checked, then short-TTL signed.
$objectKey = "company/{$companyId}/documents/" . Str::uuid();   // opaque, prefix-isolated

public function downloadUrl(Attachment $att): string
{
    $this->authorize('documents.read', $att);                   // RBAC + ownership
    abort_unless($att->company_id === TenantContext::companyId(), 404);   // cross-tenant -> 404
    return Storage::disk('r2')->temporaryUrl($att->object_key, now()->addMinutes(5));
}
```

## Cache-key isolation

Every Redis cache key is tenant-namespaced (`qayd:company:{company_id}:...`), so a cache read can never
return another tenant's value and a cache invalidation is scoped to one tenant's namespace — never a
global `FLUSHDB`. This is both an isolation control (no cross-tenant read) and a resilience property
(invalidating one tenant's cache never evicts another's warm cache). The namespacing scheme is owned by
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) § Per-tenant hot-data caching.

```
qayd:company:{company_id}:accounts:tree        (invalidated on any accounts write for that company)
qayd:company:{company_id}:settings
qayd:company:{company_id}:price_list:{list_id}
```

The security rule: **no cache key may omit the `company_id` segment.** A helper (`TenantCache::key()`)
constructs every key from `TenantContext`, and a CI grep flags any raw `Cache::` call on a
tenant-derived value that does not route through it. Session and rate-limit keys are likewise scoped so
one tenant cannot evict or read another's.

## Search-index isolation

The search service indexes tenant documents (customers, vendors, invoices, chart of accounts) for
type-ahead and full-text queries. Every indexed document carries its `company_id`, and **every search
query is filtered by the active company as a mandatory, server-injected term** — never a
client-supplied filter that could be omitted or overridden. The filter is applied by the backend from
`TenantContext`, so a query string can never widen the scope past the caller's company. Detailed
mechanics are owned by [../backend/SEARCH_SERVICE.md](../backend/SEARCH_SERVICE.md); the isolation
contract is:

```php
// Every search is company-filtered by the server, from TenantContext — not from any client parameter.
$results = $search->query($userText)
    ->mustFilter('company_id', TenantContext::companyId())   // injected server-side, non-negotiable
    ->limit(20)
    ->run();
```

Where the index is a shared cluster, the per-company filter is a `RESTRICTIVE`-equivalent term applied
to *every* query at the client-library boundary, so an engineer writing a new search feature cannot
forget it. Per-tenant index partitioning is used where the corpus size or the isolation posture of a
larger customer warrants a physically separate index.

## Cross-tenant resolves to 404, never 403

| Situation | Response |
|---|---|
| Resource exists in the active company, caller lacks the permission/policy | `403` (`permission_denied` / `policy_denied`) |
| Resource belongs to a **different** company entirely | `404` — never `403` |
| `X-Company-Id` itself is not a company the caller belongs to | `403 tenant_mismatch` (the header, not a resource, is rejected) |

Returning `404` for a foreign-tenant resource is deliberate: a `403` confirms the record exists, which
is itself a leak across the boundary (ID enumeration). `403` is used only when the caller has already
proven they may know the resource exists. In practice this is automatic — a cross-tenant id is
filtered out by `CompanyScope` (and RLS), so route-model binding raises `ModelNotFoundException` →
`404` before any Policy runs. The Policy re-check (L3-of-the-four) additionally returns `404` for a
foreign-tenant object reached via a raw/joined query.

---

# Implementation

The four layers compose into a single request path. A cross-tenant read must defeat **all four**;
each was written by different code and each fails closed.

```
Request with X-Company-Id: B  (attacker is a user of Company A)
  │
  ▼  L3  ResolveTenantCompany: membership(A-user, B)? NO  -> 403 tenant_mismatch  ── stops here
  │       (if the header were A but the target row is B's, L3 passes; the id is caught below)
  ▼  L6  CompanyScope: WHERE company_id = A  -> B's row not in result -> ModelNotFound -> 404
  │       Policy re-check: entry.company_id (B) != ctx.companyId (A) -> 404 (raw-query path)
  ▼  L7  PostgreSQL RLS: RESTRICTIVE company_boundary (company_id = app.company_id = A)
          -> B's row returns zero rows even to a raw DB::table() query or a stray console
```

Two composition facts are the crux of the design:

- **The layers are ANDed, and the innermost is the strongest.** Even if a future refactor deletes the
  `CompanyScope` trait from a model (L6), and a Policy forgets its re-check (Policy layer), and the
  route uses a raw query, the `RESTRICTIVE` RLS boundary (L7) still returns zero rows — because the app
  database role is `NOBYPASSRLS` and the GUC scopes it to Company A. This is why the security posture
  names RLS the boundary and the app scopes convenience.
- **Every async and sidecar path re-derives the same boundary.** Jobs re-bind the GUC; cache keys carry
  the `company_id` segment; search queries inject the company filter; storage keys are company-
  prefixed. A boundary that held only on the synchronous DB path would be no boundary at all.

The single most important test in the codebase proves the composition end to end (see § Testing).

---

# Enforcement Points

| Enforcement point | Layer | Mechanism | Detail in |
|---|---|---|---|
| `ResolveTenantCompany` middleware | L3 | `X-Company-Id` → live `company_users` check; `SET LOCAL app.company_id`/`app.user_id` | [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) |
| `BelongsToCompany` / `CompanyScope` | L6 | Auto `WHERE company_id = ?`; fail-closed `1=0`; auto-stamp on create | [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) |
| `forCompany` query macro | L6 | Tenant filter for sanctioned raw/reporting queries | [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) |
| Policy re-check (`resource.company_id`) | L6 | Belt-and-suspenders for raw/joined/eager-loaded rows → `404` | [AUTHORIZATION.md](AUTHORIZATION.md) |
| `RESTRICTIVE company_boundary` policy | L7 | Un-bypassable `AND` on every command, every role | [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) |
| Per-command `PERMISSIVE` policies | L7 | Intra-company visibility (all/branch/self/elevated) | [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) |
| PgBouncer `DISCARD ALL` reset | L7 | Clears leaked session state on connection return-to-pool | [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) |
| `TenantAwareJob::bindTenant` | L6/L7 | Re-establishes GUC + `TenantContext` in workers | [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) |
| `TenantCache::key` / company-prefixed R2 keys / search filter | sidecar | Cache/storage/search tenant namespacing | [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [ENCRYPTION.md](ENCRYPTION.md), [../backend/SEARCH_SERVICE.md](../backend/SEARCH_SERVICE.md) |

The tenant-resolution step is the second in the fixed pipeline
(`authenticate → resolve tenant → authorize → validate → execute → audit`); scoping (L6) and RLS (L7)
then apply to every query the later steps issue.

---

# Failure Modes

The invariant is **fail to empty**: when tenant context is absent or unverifiable, the boundary returns
zero rows / refuses the request — never all rows.

| Failing layer | Failure event | Fail-closed behavior | Caught by |
|---|---|---|---|
| L3 middleware | `X-Company-Id` missing/malformed | `403 tenant_mismatch`; request halts | L6, L7 (would also deny) |
| L3 middleware | Header for a company the user does not belong to | `403 tenant_mismatch` (header rejected, no resource leaked) | L6, L7 |
| L6 CompanyScope | No tenant context resolved | `1=0` predicate → zero rows | L7 RLS |
| L6 CompanyScope | Scope omitted on a model / raw query | Foreign row *would* return… | Policy re-check → `404`; RLS → zero rows |
| L7 RLS | GUC unset on the connection | `USING` evaluates false → **zero rows**, never all | — (last line; fails to empty by design) |
| L7 RLS | `UPDATE` rewrites `company_id` to a foreign tenant | `WITH CHECK` rejects the write | — |
| Pool | Prior tenant's `SET` leaked onto a reused connection | `SET LOCAL` scoping + `DISCARD ALL` on return clears it | — |
| Sidecar | Cache/search/storage key missing the tenant segment | CI grep blocks pre-merge; helper forces the segment at runtime | — |
| Async | Worker runs without binding tenant context | `CompanyScope` `1=0` + RLS zero rows until `bindTenant` runs | — |

Two failure modes deserve emphasis:

- **A forgotten `SET LOCAL` is a self-announcing bug, not a leak.** Because the GUC-unset case returns
  zero rows, the symptom is a user seeing an empty list — noisy and immediately reported — rather than
  a silent catastrophic disclosure of every tenant's data. This is the deliberate inversion that makes
  the last line safe.
- **A pool leak cannot outlive a transaction.** `SET LOCAL` is transaction-scoped and PgBouncer runs
  `server_reset_query = DISCARD ALL`; even a hypothetical code bug using session-level `SET` is cleared
  before the physical connection is handed to the next tenant. `DISCARD ALL` is the last-resort net,
  not an excuse to skip `SET LOCAL`.

---

# Compliance

| Framework expectation | Control in this document |
|---|---|
| SOC 2 (Security, Confidentiality) | Four-layer company isolation; `RESTRICTIVE` un-bypassable boundary; `NOBYPASSRLS` runtime roles |
| ISO 27001 — segregation of tenant data | RLS-forced tables, per-tenant cache/search/storage namespacing |
| GDPR / GCC data-protection | Tenant data separation; cross-tenant read structurally impossible; per-company export/erasure paths |
| GDPR — data minimization / need-to-know | Branch/department/self `PERMISSIVE` scoping within an already-isolated company |
| PCI-adjacent posture | Bank/account identifiers isolated per tenant; foreign-tenant objects return `404`, never confirmable |

QAYD does not claim certification here; it claims that the single control an auditor most wants to see
in a multi-tenant financial platform — that Company A cannot, under any bug, reach Company B — is
enforced four independent times and proven by the mandatory cross-tenant test suite below. Data
classification and the crypto-shredding path for per-tenant erasure are owned by
[ENCRYPTION.md](ENCRYPTION.md).

---

# Testing & Verification

The guarantee is only real if it is continuously and adversarially proven. The cross-tenant negative
suite is the single most important test in the codebase.

**Static gates (CI, block merge):**

- A schema linter fails any migration that adds a tenant table without both `ENABLE` and
  `FORCE ROW LEVEL SECURITY`, the `RESTRICTIVE company_boundary` policy, and a `company_id`-leading
  unique index.
- A Larastan rule flags any Eloquent model with a `company_id` column that does not use
  `BelongsToCompany`, and bans `withoutCompanyScope()` / bare `DB::table()` on tenant tables outside
  the `RequirePlatformAdmin` path.
- A CI grep flags any `Cache::`/search/storage key on tenant-derived data that does not route through
  the tenant-namespacing helper, and any GUC set with `SET` rather than `SET LOCAL`.

**Dynamic / behavioral tests (Pest / PHPUnit, must be green):**

- **The mandatory cross-tenant suite** — for *every* tenant-scoped resource: authenticate as Company A
  (with full permissions), attempt to read / update / delete a Company B object by id, assert `404`
  (never `403`, never `200`). This runs for the read path, the raw-query path, and the eager-load path.

```php
// tests/Feature/TenantIsolationTest.php — the single most important test in the codebase.
it('returns 404, not 403, for a resource belonging to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $entry    = JournalEntry::factory()->for($companyB)->create();
    actingAsUserOf($companyA, role: 'cfo');              // full permissions, WRONG tenant

    getJson("/api/v1/accounting/journal-entries/{$entry->id}", ['X-Company-Id' => $companyA->id])
        ->assertStatus(404);
});

it('returns zero rows from RLS when the tenant GUC is unset', function () {
    JournalEntry::factory()->count(3)->create();         // seeded across tenants
    DB::statement("RESET app.company_id");               // simulate a forgotten SET LOCAL
    expect(DB::select('SELECT count(*) c FROM journal_entries')[0]->c)->toBe(0);   // fails to EMPTY
});

it('refuses an UPDATE that moves a row to another company', function () {
    $a = actingAsUserOf($companyA = Company::factory()->create(), role: 'cfo');
    $inv = Invoice::factory()->for($companyA)->create();
    // Attempt to rewrite company_id via a raw update under company A's context -> WITH CHECK rejects.
    expect(fn () => DB::update('UPDATE invoices SET company_id = ? WHERE id = ?', [$companyB->id ?? 9999, $inv->id]))
        ->toThrow(QueryException::class);
});
```

- **Sidecar isolation tests:** a cache read under Company A never returns a value written under Company
  B; a search query under Company A never returns Company B documents; a signed URL for Company B's
  object requested under Company A returns `404`.
- **Async isolation tests:** a job dispatched for Company A that omits `bindTenant` reads zero rows
  (proving fail-closed), and with `bindTenant` reads only Company A's rows.
- **Pool-leak test:** interleaved requests for two companies over a pooled connection never observe
  each other's `app.company_id` (proving `SET LOCAL` + `DISCARD ALL`).

**Periodic verification:** a per-release RLS audit confirming every tenant table is `FORCE`d and
carries the `RESTRICTIVE` boundary; a `pg_dump`/replica-read drill confirming a raw read outside the
app path is still scoped; and external penetration testing scoped explicitly to cross-tenant isolation
(ID enumeration, parameter smuggling, header forgery) as a required test case before major releases.

The acceptance bar: for every tenant-scoped resource and every store (DB, cache, search, storage,
queue), a named test proves a Company A principal cannot reach Company B data, and proves the boundary
fails to empty rather than to all. Where it cannot be shown, the gap is a data-breach-class defect.

---

# End of Document
