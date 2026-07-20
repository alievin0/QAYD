# Authorization Security — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: AUTHORIZATION
---

# Purpose

This document specifies, in security depth, how QAYD decides *what an authenticated caller may do*.
It is the L4 (authorization) specialization of the layered model in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md), the security-hardening companion to the
foundational grammar in [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md) and
the API-surface mechanics in [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md). Where those
describe *how the permission model is shaped and served*, this document describes the adversarial
view: a user probing for a capability their role never granted, an accountant trying to approve their
own bank transfer, an over-privileged manager attempting to escalate their own role, a compromised AI
agent trying to drive a payroll release to completion, an API key reaching for a scope its creator
never held.

Authorization answers "may this identity perform this action, on this resource, in this company,
right now?" — and nothing else. It never answers "who is this?" (that is
[AUTHENTICATION.md](AUTHENTICATION.md)) and it never answers "which company is this request acting
on?" (that is [TENANT_ISOLATION.md](TENANT_ISOLATION.md)). The three are kept rigorously separate
because conflating them is how a system accidentally grants access as a side effect of proving
identity or of resolving a tenant. A caller who has authenticated perfectly and resolved a legitimate
company still holds **zero** permissions until authorization grants them — per request, freshly,
server-side.

The platform facts this document builds on are fixed: QAYD runs a Laravel 12 (PHP 8.4+) backend that
is the single writer to PostgreSQL; permissions are dotted keys in the grammar `<area>.<action>` /
`<area>.<entity>.<action>`; the model is deny-by-default with `Gate::before` establishing the floor;
enforcement is a fixed pipeline of `EnsurePermission` middleware → FormRequest `authorize()` + Policy
(ABAC) → approval-chain gate; and no single credential — human or AI — can drive a sensitive financial
or identity mutation to completion alone. Everything below hardens those facts against the threat
model in [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md).

---

# Threat Model / Principles

## The adversary at the authorization boundary

Authorization defends boundary **B2** (client ↔ API) against a caller who *is* who they say they are
and *is* acting on a company they legitimately belong to, but who is trying to do something they are
not entitled to do. Concretely:

- **Vertical escalation** — a user with a low-privilege role attempting a high-privilege action: an
  Accountant trying to `post` a journal entry, a Sales Employee trying to override a discount beyond
  policy, a Payroll Officer trying to `release` payroll.
- **Self-grant escalation** — a user attempting to widen their own permissions: editing their own
  role, assigning themselves a more powerful role, or tuning an ABAC `policy_rules` row to unlock a
  capability their role withholds.
- **Self-approval** — the maker of a sensitive action attempting to also be its checker: initiating a
  bank transfer and then approving it, so a single compromised credential moves money end-to-end.
- **Horizontal probing** — a valid user of the company reaching for a *resource* their scope excludes
  (a Warehouse Employee touching another warehouse's stock, an HR Manager reading another
  department's payslips).
- **Scope inflation** — an API key, OAuth token, or the AI service credential attempting an action
  broader than the grant it was issued, or broader than its grantor's own permission set.
- **AI over-reach** — the FastAPI AI engine attempting to execute a sensitive action directly, above
  its configured autonomy level, or outside the acting user's permission envelope.
- **UX-gating bypass** — a caller crafting a request the UI would never have sent (a hidden button, a
  disabled field), assuming the missing button was the only control.

## Principles

- **Server-authoritative, always.** Every authorization decision is made on the Laravel backend,
  freshly, on every request. Client-side gating — a hidden button in Next.js, a disabled control in
  Flutter — exists only to make the interface pleasant; it is never a security control and is assumed
  absent or hostile. A request the UI would never have sent is authorized exactly like one it would.
- **Deny by default.** The absence of an explicit grant is a denial. `Gate::before` does not
  short-circuit to allow for anyone except the single Owner sentinel; every other principal reaches
  the explicit permission check, and a key that is not present is a `403`.
- **RBAC adds, ABAC subtracts.** The coarse role gate can only *grant* capability; the fine-grained
  attribute layer can only *remove* it. A resource-level deny can never be outvoted by a broad role
  grant. Explicit deny always wins.
- **Two-key control on money and identity.** No single credential can drive a bank transfer, payroll
  release, tax submission, or permission change to completion. These are two-actor state machines, so
  one compromised credential is contained.
- **The maker is never the checker.** The initiator of a sensitive action can never be an approver of
  that same action, structurally, regardless of how many approval roles they hold.
- **The AI is on-behalf-of, never instead-of.** The AI engine acts strictly within the calling user's
  permission envelope, never holds an approval permission, and its origin flag can only make an
  outcome *more* restrictive, never less.
- **Every decision is auditable.** Every grant and every denial is logged with actor, company, key,
  resource, and reason, because a control you cannot prove fired is a control an attacker can later
  deny.

---

# Controls

## The permission grammar and why it is shaped this way

Every permission is a dotted, lowercase key in one of two shapes, defined once and centrally in the
`permissions` catalog (see [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) § Permissions):

```
<area>.<action>            e.g. accounting.read, tax.submit, bank.transfer
<area>.<entity>.<action>   e.g. accounting.journal.post, inventory.count.approve
```

The security-relevant property of the grammar is the deliberate split between *mutating a draft* and
*making it irreversible or externally visible*. `accounting.journal.create` (edit a draft) is a
different key from `accounting.journal.post` (commit to the ledger); `payroll.calculate` is different
from `payroll.release`; `tax.calculate` is different from `tax.submit`. The second key of each pair is
the one that moves money, files with an authority, or changes the ledger — and is exactly the set
carrying the `is_sensitive` / `requires_approval` flags that route it into an approval chain. This
split is what lets a company grant broad draft-editing capability without ever granting the power to
make it real.

The catalog is **closed**: a custom role can only compose keys that already exist in `permissions`. A
role can never introduce a new permission key, so every key that can ever appear on a token or in an
audit log is defined once by QAYD engineering and is enumerable by an auditor.

## Permission resolution — union of active roles, cached, versioned

A caller's effective permission set in a given company is the **union** of the permissions attached to
every active role they hold in that company, filtered to roles whose membership is not expired
(`company_users.access_expires_at`). Resolution is cached for 15 minutes, keyed
`perm:{user_id}:{company_id}`, and never hits PostgreSQL on the steady-state hot path — the security
requirement is that the cache is *tight and self-invalidating*, so a revoked permission cannot linger.

```php
// app/Services/Auth/PermissionResolver.php — the union-of-active-roles resolution, hardened.
// perms_ver is a per-(user,company) monotonic counter baked into the cache key so that ANY
// permission-affecting change invalidates in O(1), without enumerating keys to delete.
final class PermissionResolver
{
    public function effectivePermissions(User $user, int $companyId): PermissionSet
    {
        $ver = PermsVersion::for($user->id, $companyId);        // Redis counter, see below
        $cacheKey = "perm:{$user->id}:{$companyId}:v{$ver}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($user, $companyId) {
            $roleIds = CompanyUser::query()
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
                ->pluck('role_id');

            $keys = Permission::query()
                ->join('role_permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->whereIn('role_permissions.role_id', $roleIds)
                ->pluck('permissions.key')
                ->all();

            return new PermissionSet($keys);   // supports wildcard expansion, e.g. 'accounting.*'
        });
    }
}
```

**`perms_ver` invalidation.** Rather than deleting keys, QAYD bumps a per-`(user, company)` version
counter on any security-relevant change; the next resolution computes a fresh key and misses the old
cache entirely. The counter is bumped on `role.updated`, `role_permissions.updated`,
`company_users.updated` (including role reassignment, suspension, and expiry), and on any emergency
lock. Because expiry is time-based, resolution also re-filters `access_expires_at` on every cache
*miss*, and the 15-minute TTL bounds the worst-case window in which an expired-but-not-yet-invalidated
grant could survive. The security posture: a permission removed in the UI is unusable within one cache
generation, and a hard revocation (lock, suspension) is effective on the next request.

```php
// A permission-affecting domain event bumps the version; no key enumeration required.
public function bumpPermsVersion(int $userId, int $companyId): void
{
    Redis::incr("perms_ver:{$userId}:{$companyId}");
    AuditLog::security('authz.perms.invalidated', compact('userId', 'companyId'));
}
```

## Roles and role_permissions — flat, explicit, auditable

QAYD's RBAC is deliberately **flat**: `owner` is the only role with implicit "all permissions"
behavior; every other role is an explicit, auditable set of `role_permissions` rows with no hidden
inheritance. Role hierarchies are convenient but make it materially harder for an auditor — human or
the Auditor AI agent — to answer "exactly what can this user do", which is precisely the question a
financial system must answer without ambiguity. The full default role catalog and the
`roles` / `role_permissions` / `company_users` schema are owned by
[../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) § RBAC and are binding here by reference;
this document adds only the security invariants on top of them:

- **System roles are immutable.** `is_system = true` roles (Owner, CFO, Auditor, …) cannot be edited
  or deleted; a company customizes access by creating custom roles, never by mutating a shipped one.
- **Exactly one Owner per company**, and the Owner role's grant set cannot be narrowed to lock the
  company out of its own administration.
- **A custom role's grants are a subset of the catalog**, enforced at write time — an attempt to
  attach a non-existent permission key is rejected, closing the catalog.
- **Wildcards are aggregation, not definition.** `accounting.*` in a grant list expands at resolution
  time to every currently-defined `accounting.` key; it is sugar over an explicit list, never a new
  kind of key, and is reserved for Owner/CEO/CFO-level roles.

## Deny-by-default: `Gate::before`

The floor of the entire authorization model is a single `Gate::before` hook that establishes
default-deny and the one sanctioned exception (the company Owner sentinel). Nothing is allowed because
it was "not explicitly denied"; things are denied because they were "not explicitly allowed."

```php
// app/Providers/AuthServiceProvider.php
public function boot(): void
{
    // Runs BEFORE every Gate/Policy check. Returning null = "no opinion, fall through to the
    // explicit check" (the deny-by-default path). Returning true/false short-circuits.
    Gate::before(function (User $user, string $ability, array $args = []) {
        $companyId = TenantContext::companyId();          // set by ResolveTenantCompany

        // The ONLY implicit allow in the platform: the company Owner sentinel.
        if ($user->isOwnerOf($companyId)) {
            return true;
        }

        // Fail closed: no active company context means no authorization is possible.
        if (! $companyId) {
            return false;
        }

        return null;   // defer to the explicit permission/Policy check — deny unless it grants
    });

    // No Gate::after hook exists. There is deliberately no "allow if nothing denied" backstop.
}
```

There is intentionally **no `Gate::after`** — QAYD never rescues an un-granted action at the end of
the pipeline. If no explicit grant fired, the answer is no.

## `policy_rules` ABAC — explicit deny wins

RBAC answers "does this role hold `bank.transfer` at all?" ABAC answers the narrower question RBAC
cannot: "does *this* principal hold it for *this* resource, in *this* state, *this* amount, *right
now*?" It is implemented as Laravel Policy classes plus data-driven `policy_rules` rows, and it runs
**after** the RBAC gate has already passed — never instead of it. The `policy_rules` schema and the
JSON-logic interpreter are owned by [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) § ABAC;
the security invariants are:

- **Rules evaluate in ascending `priority`.** The first matching rule of the highest precedence wins.
- **Explicit deny always wins** over any allow, including the RBAC grant that let the request past the
  coarse gate. RBAC can only add capability; ABAC can only subtract it. A resource-level deny can
  never be outvoted by a broader role grant.
- **A deny is the safe default on ambiguity.** If a rule cannot be evaluated (a referenced attribute
  is missing, the JSON-logic is malformed), the layer denies and raises a configuration alarm — it
  never falls through to allow.

```php
// app/Policies/JournalEntryPolicy.php — ABAC after RBAC. Note: cross-tenant is 404, not 403.
final class JournalEntryPolicy
{
    public function post(User $user, JournalEntry $entry, PolicyContext $ctx): Response
    {
        // Belt-and-suspenders tenant re-check; a foreign-tenant row 404s (enumeration safety).
        if ($entry->company_id !== $ctx->companyId) {
            return Response::denyAsNotFound();                 // -> 404, never 403
        }
        if ($entry->status !== 'draft') {
            return Response::deny('Only draft entries can be posted.', 'policy_denied');
        }
        // Data-driven policy_rules (thresholds, scoping) evaluated here; explicit deny wins.
        return PolicyRules::evaluate('accounting.journal.post', $ctx, $entry)
            ?? Response::allow();                              // no rule matched -> RBAC outcome (allow)
    }
}
```

## Escalation prevention

Two classes of privilege escalation are structurally blocked, not merely discouraged.

**Vertical (role) escalation.** A user can never grant themselves a capability. The permissions that
mutate access — `auth.roles.manage`, `users.role.assign`, `auth.policies.manage`,
`auth.api_keys.manage`, `ai.autonomy.manage` — are themselves `is_sensitive` and gated. Beyond the
permission check, QAYD enforces a **no-self-elevation** rule in the Policy layer: a user editing role
assignments may not add to *their own* `company_users` row a role carrying a permission they do not
already hold, and may not grant a custom role a permission that exceeds the granting user's own
effective set. This closes the "I hold `users.role.assign`, so I'll assign myself CFO" path.

```php
// app/Policies/CompanyUserPolicy.php — you cannot grant what you do not hold, least of all to yourself.
public function assignRole(User $actor, CompanyUser $target, Role $role, PolicyContext $ctx): Response
{
    if (! $actor->canInCompany('users.role.assign', $ctx->companyId)) {
        return Response::deny('Missing users.role.assign.', 'permission_denied');
    }
    $actorPerms  = app(PermissionResolver::class)->effectivePermissions($actor, $ctx->companyId);
    $rolePerms   = $role->permissionKeys();

    // The role being granted may not exceed the granter's own permission envelope.
    if (! $actorPerms->covers($rolePerms)) {
        return Response::deny('Cannot grant permissions you do not hold.', 'policy_denied');
    }
    // Self-assignment of an elevated role is additionally routed through the approval chain
    // for users.role.assign (Owner single-step) — no user silently elevates themselves.
    return Response::allow();
}
```

**Scope inflation.** An API key, OAuth token, or AI credential can only carry a scope that subsets its
grantor's own effective permissions; an attempt to request a broader scope is rejected
(`422 scope_exceeds_grantor`), and a write scope on an API key additionally routes through an approval
chain. A scope can subset a user's permissions; it can never exceed them. Full scope mechanics are in
[../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) § Scopes.

## Self-approval prohibition (maker ≠ checker)

Every sensitive action that requires approval enforces that the **initiator can never be an approver**
of that same action, regardless of how many approval-eligible roles they hold. This is the core of
segregation-of-duties and is enforced at the approval-decision endpoint, not just in the UI.

```php
// app/Services/Auth/ApprovalService.php — the maker-checker guard.
public function decide(ApprovalRequest $req, User $approver, string $decision, PolicyContext $ctx): void
{
    // 1. The approver must hold the approval permission AND match the current step's role.
    abort_unless($approver->canInCompany('auth.approvals.approve', $ctx->companyId), 403, 'permission_denied');
    abort_unless($req->currentStepRoleMatches($approver), 403, 'policy_denied');

    // 2. The maker can NEVER be the checker — the single most important SoD invariant.
    if ($req->initiated_by_id === $approver->id) {
        throw new MakerCheckerViolationException();          // -> 403 maker_checker_violation
    }

    // 3. Nor can the same human approve two distinct steps of the same chain (collusion-of-one).
    if ($req->actions()->where('actor_user_id', $approver->id)->exists()) {
        throw new MakerCheckerViolationException();
    }

    $req->recordDecision($approver, $decision, $ctx);
}
```

The third guard blocks a user who holds both step roles (e.g. Finance Manager *and* CFO in a small
company) from single-handedly clearing a two-step chain: one human may satisfy at most one step of any
given chain.

## Delegation and two-key approvals

The "always-sensitive" operations require an approval chain in every company, unconditionally — a
company may customize *who* the approvers are, but cannot disable approval for this set. The chain
definitions and the `approval_chains` / `approval_chain_steps` / `approval_requests` schema are owned
by [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) § Policies. The security-critical members
of the set:

| Operation | Permission key | Default two-key chain |
|---|---|---|
| Bank transfer at/over threshold | `bank.transfer` | Finance Manager → CFO |
| Bank payment (final release) | `bank.payment.approve` → `bank.payment.final` | Finance Manager → CFO (distinct approve vs. final-release keys) |
| Payroll release | `payroll.release` | HR Manager → CFO |
| Tax submission | `tax.submit` | Senior Accountant → CFO |
| AI-proposed sensitive action | `ai.approve` (+ the underlying key) | Human approver, always — AI never approves |
| Void/delete a posted record | `accounting.journal.void`, `sales.invoice.void` | CFO (single step) |
| Permission / role change | `auth.roles.manage`, `users.role.assign` | Owner (single step) |

**Two-key money release** is modeled as two *distinct* permissions, not one permission approved twice:
`bank.payment.approve` authorizes queuing a payment for release; `bank.payment.final` authorizes the
irreversible release itself. They are held by different roles, so moving money end-to-end requires two
distinct humans holding two distinct keys — the maker-checker guard above additionally forbidding the
same person from holding both slots of one request.

**Delegation.** An approver may delegate their approval authority for a bounded window (vacation
cover) by granting a time-boxed `company_users` row carrying the approval role, with
`access_expires_at` set. Delegation never widens the delegate's own permissions beyond the delegated
role, is itself an audited, `auth.roles.manage`-gated action, and the delegate is still bound by the
maker-checker prohibition. There is no "approve on behalf of" that hides the acting human — the
`approval_request_actions.actor_user_id` is always the real approver.

**`ai.approve`.** The AI engine holds no approval permission and cannot be assigned one. When the AI
proposes a sensitive action, it creates a *more heavily documented* approval request
(`initiated_by_type='ai_agent'`, `ai_confidence`, `ai_reasoning`, `ai_source_documents`) that a human
holding the relevant approval permission must clear. `ai.approve` is the permission a human needs to
approve an AI-originated proposal; it is never held by the AI itself.

## Server-authoritative enforcement vs. UX gating

The Next.js and Flutter clients call `GET /api/v1/auth/me/permissions` to *render* the UI — disabling
buttons, hiding menu items, showing "request access" affordances. This is **UX gating**: it improves
the interface and is not, and must never be treated as, a security control. Every decision the UI
makes visually is re-made authoritatively on the server when the request actually arrives. The
security contract is explicit:

- A request the UI would never have produced (a hidden endpoint, a disabled action, a forged body) is
  authorized identically to one the UI would have produced.
- The client permission list is advisory and may be stale; the server never trusts a client-asserted
  permission, role, or company.
- Removing a button is never a substitute for a server check; every gated action has a server-side
  `EnsurePermission` + Policy pair, verified by test (see § Testing & Verification).

## The 403 taxonomy

Every authorization denial returns HTTP `403` (or `404` for cross-tenant, see below) with the standard
`/api/v1` envelope and a machine-readable `code`. Frontends key their UX off `code`, never off the
human-readable message. The full contract lives in
[../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) § "The 403 contract"; the security-relevant
taxonomy is:

| `code` | Meaning | Typical trigger |
|---|---|---|
| `permission_denied` | RBAC gate failed — role holds no matching key | Accountant attempts `accounting.journal.post` |
| `policy_denied` | ABAC gate failed — attributes don't satisfy a Policy / `policy_rules` | Warehouse Employee outside their warehouse scope |
| `insufficient_scope` | API key / OAuth / AI token scope too narrow | Integration key holds only `accounting:read` |
| `tenant_mismatch` | `X-Company-Id` not in the caller's active memberships | Stale company header after session change |
| `approval_pending` | Resource is locked behind an unresolved approval chain | Editing a transfer awaiting CFO sign-off |
| `mfa_required` | Sensitive action needs step-up auth this session | First bank transfer of the session (see [AUTHENTICATION.md](AUTHENTICATION.md)) |
| `ai_autonomy_blocked` | AI attempted an action above its configured autonomy level | AI tried to auto-release payroll |
| `maker_checker_violation` | Initiator attempted to approve their own sensitive action | Transfer maker calls the approve endpoint |

`401` is reserved strictly for "who are you" failures; `403` for "I know who you are, and the answer is
no"; `404` for a resource that exists but belongs to another tenant (enumeration safety, owned by
[TENANT_ISOLATION.md](TENANT_ISOLATION.md)). A `403 permission_denied` / `policy_denied` should not be
retried by the client; a `403 tenant_mismatch` should trigger a company re-switch; an
`mfa_required` should trigger a step-up handshake.

---

# Implementation

The authorization pipeline is a fixed, ordered sequence. No step is skippable, none is reorderable,
and no caller — human, API key, or AI — is exempt.

```
authenticate → resolve tenant → EnsurePermission (RBAC) → FormRequest authorize()+Policy (ABAC)
             → sensitive-op / approval gate → execute (scoped) → audit
```

**Step 1 — `EnsurePermission:<key>` (RBAC, coarse gate).** Middleware on every authenticated route.
Resolves the effective permission set for the caller in the active company and checks for the key (or
a covering wildcard). Miss → `403 permission_denied`, request never reaches the controller.

```php
// app/Http/Middleware/EnsurePermission.php
public function handle(Request $request, Closure $next, string $permissionKey): Response
{
    $companyId = TenantContext::companyId();                 // set upstream by ResolveTenantCompany
    $set = app(PermissionResolver::class)->effectivePermissions($request->user(), $companyId);

    if (! $set->has($permissionKey)) {
        AuditLog::security('authz.permission_denied', $request, ['key' => $permissionKey]);
        return ApiResponse::error('permission_denied', "Missing permission: {$permissionKey}", 403);
    }
    return $next($request);
}
```

**Step 2 — FormRequest `authorize()` + Policy (ABAC, fine gate).** The FormRequest's `authorize()`
invokes the resource Policy, which compares the `PolicyContext` (subject attributes: role, branch,
department, warehouse scope, approval limit, origin) against the resource (status, amount, owner,
company) and the `policy_rules`. Deny → `403 policy_denied`, or `404` if the denial reason is
cross-tenant.

**Step 3 — sensitive-op / approval-chain gate.** If the permission key is `requires_approval` (or the
AI autonomy level for this key is `requires_approval`), the write is intercepted rather than executed:
an `approval_requests` row is created (`status='pending'`), the response is `202 Accepted`, and the
underlying action executes only when the final approver clears the chain — re-validated against the
*original* initiator's permission context at execution time. The maker-checker and one-human-one-step
guards from § Controls apply here.

**Step 4 — execute + audit.** Only after all gates pass does the Service/Repository execute the write
(under the tenant scope enforced by [TENANT_ISOLATION.md](TENANT_ISOLATION.md)) and emit the audit
record inside the same transaction (see [SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md)).

**AI-originated requests take the identical path.** The AI engine calls `/api/v1` like any client,
carrying `X-Acting-As-User-Id` and `X-Agent-Id`; the pipeline resolves permissions for the *acting
user*, exactly as if that user had called directly. The AI service token itself carries only
`ai:invoke`, which authorizes nothing on any business resource. The origin flag
(`PolicyContext::$origin === 'ai_agent'`) additionally unlocks the autonomy check, which can only make
the outcome more restrictive. There is no separate, more-permissive code path for AI writes — this is
the structural guarantee behind "AI obeys the same permissions as the human."

---

# Enforcement Points

| Enforcement point | Mechanism | Defends | Detail in |
|---|---|---|---|
| `Gate::before` | Default-deny floor; Owner sentinel; fail-closed on no tenant | Implicit-allow bugs | This document |
| `EnsurePermission:<key>` middleware | RBAC coarse gate on the route | Vertical escalation | [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) |
| FormRequest `authorize()` + Policy | ABAC fine gate, `policy_rules`, explicit-deny-wins | Horizontal probing, state/amount abuse | [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) |
| `EnsureScope` middleware (API-key/OAuth/AI guard) | Scope subset check | Scope inflation | [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) |
| `ApprovalService::decide` | Maker-checker + one-human-one-step + step-role match | Self-approval, collusion-of-one | This document |
| `CompanyUserPolicy::assignRole` | No-self-elevation, grant ⊆ granter | Self-grant escalation | This document |
| AI autonomy check (`ai_autonomy_settings`) | `auto` / `suggest_only` / `requires_approval` per key | AI over-reach | [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md) |
| Audit observers | Append-only record of every grant/denial | Deniability of decisions | [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) |

The authorization step is the third in the fixed pipeline
(`authenticate → resolve tenant → authorize → validate → execute → audit`); it never runs before tenant
resolution and never proves identity or resolves a tenant itself.

---

# Failure Modes

The invariant is **fail closed**: any authorization component that cannot make a confident positive
allow decision denies.

| Event | Fail-closed behavior |
|---|---|
| No active company context at `Gate::before` | `403` — no authorization is possible without a tenant |
| Permission key absent from the resolved set | `403 permission_denied`; controller never runs |
| `policy_rules` row malformed / attribute missing | `403 policy_denied` + config alarm; never falls through to allow |
| Permission cache stale after a revoke | `perms_ver` bump invalidates on next request; TTL bounds worst case to 15 min |
| Requested API-key/AI scope exceeds grantor | `422 scope_exceeds_grantor`; key not issued |
| Maker attempts to approve their own request | `403 maker_checker_violation`; nothing executes |
| Same human attempts two steps of one chain | `403 maker_checker_violation`; second step refused |
| AI attempts a `requires_approval` action | `approval_requests` row created; `202`; no direct execution ever |
| AI attempts an action above its autonomy level | `403 ai_autonomy_blocked` |
| Permission resolver / cache backend (Redis) unreachable | Fail closed: deny rather than allow an unverifiable request |
| Cross-tenant resource reached past the scope | `404` (never `403`) — enumeration safety, per [TENANT_ISOLATION.md](TENANT_ISOLATION.md) |

Two failure modes deserve emphasis:

- **A sensitive action that cannot be approved does not happen.** An approval chain that cannot be
  resolved (no eligible approver, expired request) leaves the action in `pending`/`expired` and
  requires resubmission; it never "times out into execution."
- **A permission change that cannot be audited does not commit.** Role/permission mutations write
  their audit record in the same transaction as the change; if the audit write fails, the change rolls
  back. There is no state in which access changed and no one can prove who changed it.

---

# Compliance

| Framework expectation | Control in this document |
|---|---|
| SOC 2 — logical access, least privilege | Deny-by-default (`Gate::before`), flat auditable RBAC, closed permission catalog |
| SOC 2 — segregation of duties | Maker ≠ checker, one-human-one-step, two-key money release (`bank.payment.approve`/`.final`) |
| SOC 2 — change management | `auth.roles.manage` / `users.role.assign` gated + approval-chained + audited |
| ISO 27001 — access control lifecycle | Time-boxed grants (`access_expires_at`), delegation as audited grant, `perms_ver` revocation |
| GDPR / GCC — need-to-know | ABAC department/branch/self scoping restricts PII (payslips) to entitled roles |
| Kuwait / GCC e-invoicing & tax | `tax.submit` two-key control; submission authorization is auditable |

QAYD does not claim certification here; it claims that the controls a certification would audit —
default-deny, segregation of duties, no self-elevation, no self-approval, AI-within-envelope — are
specified, enforced server-side, and testable. The `is_sensitive` / `requires_approval` flags on the
`permissions` catalog are the spine connecting a framework's "segregation of duties" requirement to
the exact keys and chains that implement it.

---

# Testing & Verification

The model is only real if it is continuously proven. Every control has an automated gate.

**Static gates (CI, block merge):**

- A route-coverage check asserts every state-changing `/api/v1` route declares an `EnsurePermission`
  middleware and a Policy; a route without both fails CI (no un-gated write can ship).
- A grammar linter asserts every referenced permission key exists in the `permissions` catalog seed
  (no orphan keys, no typo'd checks that would silently never match — and, being deny-by-default, a
  typo'd key is a *denial* bug, not a leak).
- A Larastan rule bans `Gate::after` and bans returning `true` from `Gate::before` for any principal
  other than the Owner sentinel.

**Dynamic / behavioral tests (Pest / PHPUnit, must be green):**

- **Escalation negative tests:** an Accountant receives `403` on `accounting.journal.post`; a user
  holding `users.role.assign` cannot assign themselves a role carrying a permission they lack; a
  custom-role create rejects a permission exceeding the creator's set.
- **Self-approval tests:** the initiator of every two-key flow (bank transfer, payroll release, tax
  submit) receives `403 maker_checker_violation` when calling the approve endpoint; a user holding
  both step roles cannot clear both steps.
- **Two-key tests:** a payment released without a distinct `bank.payment.final` approver never
  executes; the underlying action runs only after the final step and is re-validated against the
  original initiator's context.
- **AI-envelope tests:** an AI request on behalf of a user lacking the underlying permission is denied
  identically to the human; an AI attempt at a `requires_approval` key produces an `approval_requests`
  row and `202`, never a direct write; an AI attempt above its autonomy level returns
  `403 ai_autonomy_blocked`.
- **Revocation timing:** removing a permission bumps `perms_ver` and the next request is denied;
  an expired `access_expires_at` membership contributes no permissions after expiry.
- **UX-gating bypass:** a request crafted without the client (raw HTTP, forged body) is authorized
  identically to a UI-produced one — proving the button was never the control.

**Periodic verification:** quarterly access review of role assignments and approval-chain
configurations; a documented tabletop for the self-elevation and self-approval paths; penetration
testing scoped explicitly to vertical/horizontal escalation and two-key bypass before major releases.

The acceptance bar: for each threat in the threat model, a named test proves the corresponding control
fires and fails closed. Where it cannot be shown, the gap is a defect, not a documentation nicety.

---

# End of Document
