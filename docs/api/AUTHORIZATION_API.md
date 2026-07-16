# Authorization & Access Control API — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: AUTHORIZATION_API
---

# Purpose

This document specifies the Authorization & Access Control layer of the QAYD API — the cross-cutting
system that decides, on every single request, whether the calling principal (a human user, a service
integration holding an API key, or the QAYD AI engine acting on a user's behalf) is allowed to perform
the requested action on the requested resource, inside the requested company.

QAYD is a multi-tenant AI Financial Operating System. A single Laravel 12 backend is the sole owner of
business and financial logic and the sole writer to PostgreSQL. The Next.js web client, the Flutter
mobile client, the FastAPI AI engine, and every external integration reach the database exclusively
through this API. That makes the Authorization layer the single choke point that guarantees three
non-negotiable properties across the entire platform:

1. **Default deny.** No principal has any permission unless it has been explicitly granted, directly or
   through a role. A brand-new user with no role assignment can authenticate successfully (their bearer
   token is valid) but cannot read or write a single business record until a role is attached.
2. **Absolute tenant isolation.** A user, API key, or AI agent scoped to Company A can never read, write,
   enumerate, or infer the existence of Company B's data, regardless of bugs elsewhere in the stack.
   Authorization is checked at four independent layers (Section "Tenant Isolation") so that a defect in
   any single layer cannot leak data across a tenant boundary.
3. **The AI is not a backdoor.** The FastAPI AI engine never writes to PostgreSQL directly and never
   evaluates its own authorization. Every AI-initiated action is re-authenticated and re-authorized by
   this same Laravel layer, on behalf of a specific human user, under that user's own permission set,
   and is additionally subject to autonomy policy (auto / suggest-only / requires-approval) and, for
   sensitive operations, a mandatory human approval chain.

This document defines:

- **RBAC** — the coarse-grained Role-Based Access Control model: roles, the permission catalog, how
  roles are assigned per company/branch, and how the Laravel Gate resolves a permission check.
- **ABAC** — the fine-grained Attribute-Based Access Control layer that sits on top of RBAC to answer
  questions RBAC cannot: "this Accountant, but only for draft invoices", "this Warehouse Employee, but
  only for their assigned warehouse", "this Finance Manager, but only up to 5,000 KWD".
- **Permissions** — the full dotted permission-key catalog (`<area>.<action>`), how it is stored,
  discovered, cached, and enforced, and the exact contract for a `403` denial.
- **Scopes** — how API keys, OAuth clients, mobile refresh tokens, and the AI engine's service
  credential each carry a narrower, explicit scope grant that can never exceed the issuing user's own
  permissions.
- **Policies** — the approval-chain engine that intercepts sensitive, irreversible, or high-value
  operations and requires one or more humans to approve before the action executes — including when
  the action was proposed by the AI.
- **Tenant Isolation** — the defense-in-depth mechanism (middleware, Eloquent global scope, Policy
  re-check, PostgreSQL Row-Level Security) that makes company/branch/department isolation structurally
  guaranteed rather than merely conventional.
- **Examples** — fully worked HTTP request/response pairs covering the success path, RBAC denial, ABAC
  denial, scope denial, tenant-isolation denial, and the full lifecycle of an approval chain, including
  one initiated by the AI engine.

This document assumes a valid, non-expired Sanctum/JWT bearer token has already been presented and
parsed (see the separate Authentication API document for login, token issuance, refresh, and MFA). Every
example below therefore starts from "the caller is a known, authenticated principal" and focuses purely
on the authorization decision that follows.

# RBAC

## 1. Model

QAYD's Role-Based Access Control model is deliberately flat: **Owner** is the only role with implicit
"all permissions" behavior; every other role is an explicit, auditable set of permission grants with no
hidden inheritance. Flat RBAC is a conscious trade-off — role hierarchies are convenient but make it
materially harder for an auditor (human or the Auditor AI agent) to answer "exactly what can this user
do", which is precisely the question a financial system must answer without ambiguity.

```
User ──< company_users >── Company
          │
          └── role_id ──> Role ──< role_permissions >── Permission
                             │
                             └── (branch_id / department_id scope, nullable)
```

A user's effective permission set in a given company is the union of the permissions attached to every
role that user holds in that company. A user may hold different roles in different companies (a
consultant who is `senior_accountant` at Company A and `external_auditor` at Company B), and, within one
company, may hold more than one role (e.g., `sales_manager` + `purchasing_employee` in a small company
that has not yet split those functions).

## 2. Default role catalog

These are the system roles shipped with every company (`is_system = true`); companies may additionally
define custom roles built from the same permission catalog (see §5).

| Role key | Name (EN) | Typical scope | Notes |
|---|---|---|---|
| `owner` | Owner | Whole company | Implicit `*` — every permission, cannot be edited or removed, exactly one per company |
| `ceo` | CEO | Whole company | Near-`*`; excluded from destructive settings like deleting the company itself |
| `cfo` | CFO | Whole company (finance) | Full Accounting/Banking/Tax/Payroll/Reports; top of most approval chains |
| `finance_manager` | Finance Manager | Company or branch | Journal posting, bank transfers up to configured limit, AR/AP oversight |
| `senior_accountant` | Senior Accountant | Company or branch | Posts/voids journal entries, closes periods, cannot release payroll or submit tax |
| `accountant` | Accountant | Company or branch | Creates/edits draft journal entries, invoices, bills; cannot post or void |
| `auditor` | Auditor | Whole company, read-only | Read access to every ledger, journal, and audit log; zero write permissions |
| `hr_manager` | HR Manager | Department-scoped | Manages employee records and payroll runs; release still requires CFO approval |
| `payroll_officer` | Payroll Officer | Department-scoped | Prepares payroll runs and payslips; cannot approve or release |
| `inventory_manager` | Inventory Manager | Branch/warehouse-scoped | Full inventory CRUD, stock counts, adjustments within assigned warehouses |
| `warehouse_employee` | Warehouse Employee | Single warehouse | Stock movements and counts only for their assigned warehouse |
| `sales_manager` | Sales Manager | Branch-scoped | Full sales cycle incl. discounts/price overrides within policy limits |
| `sales_employee` | Sales Employee | Branch-scoped | Creates quotations/orders/invoices; cannot apply discounts beyond policy default |
| `purchasing_manager` | Purchasing Manager | Branch-scoped | Full purchasing cycle incl. vendor payments up to configured limit |
| `purchasing_employee` | Purchasing Employee | Branch-scoped | Creates purchase requests/orders; cannot approve or pay |
| `read_only` | Read Only | Whole company or scoped | Read access to whatever modules are explicitly granted; no writes anywhere |
| `external_auditor` | External Auditor | Whole company, time-boxed | Read-only, additionally time-boxed via `company_users.access_expires_at` |

## 3. Database design

```sql
-- Global permission catalog (system-wide, NOT company-scoped — see "Permissions" section for the key format)
CREATE TABLE permissions (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key              VARCHAR(160) NOT NULL UNIQUE,        -- e.g. 'accounting.journal.post'
    area             VARCHAR(64)  NOT NULL,                -- e.g. 'accounting'
    entity           VARCHAR(64)  NULL,                    -- e.g. 'journal'
    action           VARCHAR(64)  NOT NULL,                -- e.g. 'post'
    label_en         VARCHAR(160) NOT NULL,
    label_ar         VARCHAR(160) NOT NULL,
    category         VARCHAR(64)  NOT NULL,                -- grouping for UI, e.g. 'Accounting'
    is_sensitive     BOOLEAN NOT NULL DEFAULT false,        -- true => eligible for approval-chain policy
    requires_approval BOOLEAN NOT NULL DEFAULT false,       -- true => ALWAYS creates an approval_request
    deprecated_alias_of BIGINT NULL REFERENCES permissions(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_permissions_area ON permissions(area);

-- Roles: is_system roles have company_id NULL and are shared read-only templates;
-- custom roles are company-owned.
CREATE TABLE roles (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NULL REFERENCES companies(id),
    key           VARCHAR(64)  NOT NULL,
    name_en       VARCHAR(120) NOT NULL,
    name_ar       VARCHAR(120) NOT NULL,
    description   TEXT NULL,
    is_system     BOOLEAN NOT NULL DEFAULT false,
    is_default    BOOLEAN NOT NULL DEFAULT false,
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL,
    CONSTRAINT uq_roles_company_key UNIQUE (company_id, key)
);
CREATE INDEX idx_roles_company_id ON roles(company_id);

CREATE TABLE role_permissions (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    role_id       BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    granted_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_role_permission UNIQUE (role_id, permission_id)
);
CREATE INDEX idx_role_permissions_role ON role_permissions(role_id);

-- Company membership + role assignment, with OPTIONAL branch/department scope narrowing.
CREATE TABLE company_users (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    user_id        BIGINT NOT NULL REFERENCES users(id),
    role_id        BIGINT NOT NULL REFERENCES roles(id),
    branch_id      BIGINT NULL REFERENCES branches(id),      -- NULL = whole company
    department_id  BIGINT NULL REFERENCES departments(id),   -- NULL = whole branch/company
    status         VARCHAR(16) NOT NULL DEFAULT 'active',    -- active | suspended | invited
    access_expires_at TIMESTAMPTZ NULL,                       -- e.g. external_auditor engagements
    created_by     BIGINT NULL REFERENCES users(id),
    updated_by     BIGINT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at     TIMESTAMPTZ NULL,
    CONSTRAINT uq_company_user_role UNIQUE (company_id, user_id, role_id, branch_id, department_id)
);
CREATE INDEX idx_company_users_company ON company_users(company_id);
CREATE INDEX idx_company_users_user ON company_users(user_id);
CREATE INDEX idx_company_users_branch ON company_users(branch_id);
```

## 4. Resolution algorithm

```php
// app/Services/Auth/PermissionResolver.php
final class PermissionResolver
{
    public function effectivePermissions(User $user, int $companyId): PermissionSet
    {
        $cacheKey = "perm:{$user->id}:{$companyId}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($user, $companyId) {
            $roleIds = CompanyUser::query()
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now());
                })
                ->pluck('role_id');

            $keys = Permission::query()
                ->join('role_permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->whereIn('role_permissions.role_id', $roleIds)
                ->pluck('permissions.key')
                ->toArray();

            return new PermissionSet($keys); // supports wildcard expansion, e.g. 'accounting.*'
        });
    }
}
```

Cache invalidation fires on `role.updated`, `role_permissions.updated`, and `company_users.updated`
domain events, which flush `perm:{user_id}:{company_id}` for every affected user. Permission checks
never hit PostgreSQL on the hot path in steady state — only on cache miss.

## 5. Custom roles

A company's `owner` or `ceo` may create custom roles from Settings (`POST /api/v1/auth/roles`), composed
strictly from the existing permission catalog — a custom role can never introduce a permission key that
does not already exist in `permissions`. This keeps the catalog closed and auditable: every permission
key that can ever appear on a token or in an audit log is defined once, centrally, by QAYD engineering.

## 6. Middleware pipeline

Every authenticated route passes through, in order: `auth:sanctum` → `ResolveActiveCompany` →
`EnsurePermission:<key>` → the controller → the FormRequest's `authorize()` (ABAC, see next section) →
the Service/Repository. `ResolveActiveCompany` reads `X-Company-Id`, validates it against the user's
`company_users` rows, and binds it into the request context consumed by every later layer, including the
Eloquent global scope described in "Tenant Isolation".

```php
// app/Http/Middleware/EnsurePermission.php
public function handle(Request $request, Closure $next, string $permissionKey): Response
{
    $companyId = $request->attributes->get('active_company_id');
    $set = app(PermissionResolver::class)->effectivePermissions($request->user(), $companyId);

    if (! $set->has($permissionKey)) {
        AuditLog::record($request, 'permission_denied', ['key' => $permissionKey]);
        return ApiResponse::error(
            code: 'permission_denied',
            message: "Missing permission: {$permissionKey}",
            status: 403
        );
    }

    return $next($request);
}
```

# ABAC

RBAC answers "does this role have `accounting.journal.post` at all?" — a yes/no independent of which
specific journal entry, in which state, for how much money. Financial systems need a second, narrower
question answered for the same request: "does *this* principal have it for *this* resource, *right
now*?" That is Attribute-Based Access Control, and in QAYD it is implemented as Laravel Policy classes
that run **after** the RBAC gate passes, never instead of it. RBAC is the coarse default-deny gate; ABAC
is the fine-grained business-rule layer bolted on top.

## 1. Attribute categories

| Category | Examples | Source |
|---|---|---|
| Subject attributes | role, `branch_id`/`department_id` scope, `approval_limit`, employment status, MFA level | `company_users`, `user_branch_scopes`, `approval_limits` |
| Resource attributes | `status` (draft/posted/void), `amount`, `owner_id`/`created_by`, `company_id`, `branch_id`, `cost_center_id` | The domain row itself (e.g. `journal_entries`, `invoices`) |
| Environment attributes | request origin (web/mobile/ai-agent), IP/device trust, time-of-day (kiosk mode), request `Idempotency-Key` | Request context, `PolicyContext` |

## 2. Worked scoping rules

- **Branch/warehouse scoping.** A `warehouse_employee` may hold `inventory.adjust`, but the ABAC policy
  additionally requires `resource.warehouse_id IN subject.warehouse_scope_ids`. Scope assignment lives
  in `user_warehouse_scopes` (many-to-many, a user can be scoped to more than one warehouse).
- **Department scoping.** An `hr_manager` may hold `payroll.read`, but the policy restricts visible
  `payroll_items`/`payslips` to `resource.department_id = subject.department_id` unless the role also
  carries the `hr.cross_department.read` override permission.
- **Lifecycle/state scoping.** An `accountant` may hold `accounting.journal.edit`, but the policy denies
  the edit once `resource.status != 'draft'` — posted lines are immutable platform-wide (see the
  Accounting Engine document); only `accounting.journal.reverse` (a distinct, more sensitive permission)
  can act on a posted entry, and only by creating a new reversing entry.
- **Amount-threshold scoping.** A `finance_manager` may hold `bank.transfer`, but the policy compares
  `resource.amount` against `subject.approval_limit` (from `approval_limits`, per-role/per-user, per
  company, per currency). Under the limit: allowed outright. At or over the limit: the request is
  accepted but routed into an approval chain instead of executed immediately (see "Policies").
- **Ownership scoping.** A `sales_employee` may hold `sales.quotation.edit`, but only where
  `resource.created_by = subject.user_id`, unless the role also carries `sales.quotation.edit.any`.

## 3. Policy context object

```php
final class PolicyContext
{
    public function __construct(
        public readonly int $userId,
        public readonly int $companyId,
        public readonly ?int $branchId,
        public readonly ?int $departmentId,
        public readonly array $warehouseScopeIds,
        public readonly ?float $approvalLimit,
        public readonly string $origin,       // 'web' | 'mobile' | 'ai_agent' | 'api_key'
        public readonly ?string $agentId,     // set when origin === 'ai_agent'
        public readonly ?int $actingAsUserId, // set when an AI agent acts on behalf of a user
    ) {}
}
```

`PolicyContext` is built once per request by `ResolveActiveCompany` + `ResolvePolicyContext` middleware
and injected into every Policy method, so a Policy never re-derives subject attributes itself — it only
compares `PolicyContext` against the resource it was handed.

## 4. Policy class shape

```php
// app/Policies/JournalEntryPolicy.php
final class JournalEntryPolicy
{
    public function post(User $user, JournalEntry $entry, PolicyContext $ctx): Response
    {
        if ($entry->company_id !== $ctx->companyId) {
            return Response::deny('Resource does not belong to the active company.'); // → 404, not 403
        }
        if ($entry->status !== 'draft') {
            return Response::deny('Only draft entries can be posted.');
        }
        if (! bcAreEqual($entry->totalDebits(), $entry->totalCredits())) {
            return Response::deny('Unbalanced entry: debits must equal credits.');
        }
        return Response::allow();
    }
}
```

## 5. Rule engine for company-configurable policies

Not every ABAC rule is hard-coded PHP. Rules that companies may legitimately want to tune (approval
thresholds, cross-department visibility, discount caps) are stored as data in `policy_rules` and
evaluated through a small JSON-logic interpreter, so the Compliance AI agent and non-engineer admins can
read and, within bounds, adjust them without a deployment.

```sql
CREATE TABLE policy_rules (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    permission_key  VARCHAR(160) NOT NULL REFERENCES permissions(key),
    effect          VARCHAR(8) NOT NULL CHECK (effect IN ('allow','deny')),
    condition       JSONB NOT NULL,               -- JsonLogic expression
    priority        INT NOT NULL DEFAULT 100,      -- lower evaluates first
    is_active       BOOLEAN NOT NULL DEFAULT true,
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL
);
CREATE INDEX idx_policy_rules_company_perm ON policy_rules(company_id, permission_key) WHERE is_active;
```

Example row (rendered as JSON) restricting `inventory.adjust` to the employee's own warehouse:

```json
{
  "permission_key": "inventory.adjust",
  "effect": "deny",
  "condition": {
    "!": { "in": [{ "var": "resource.warehouse_id" }, { "var": "subject.warehouse_scope_ids" }] }
  },
  "priority": 10
}
```

## 6. Conflict resolution

Rules evaluate in ascending `priority` order. **Explicit deny always wins** over any allow, including
the RBAC grant that let the request past the coarse gate — this is intentional: RBAC can only add
capability, ABAC can only subtract it. A resource-level deny can never be "outvoted" by a broader role
grant. If no rule matches, the ABAC layer defaults to the RBAC outcome (allow, since RBAC already
passed).

# Permissions

## 1. Key format

Every permission is a dotted string, lowercase, snake-free, in one of two shapes:

```
<area>.<action>                 e.g. accounting.read, tax.submit
<area>.<entity>.<action>        e.g. accounting.journal.post, inventory.count.approve
```

`<area>` groups permissions by module/domain (`accounting`, `bank`, `sales`, `purchasing`, `inventory`,
`payroll`, `tax`, `reports`, `hr`, `settings`, `ai`, `users`, `auth`). `<entity>` names the specific
resource inside that area when the area has more than one write surface. `<action>` is a verb:
`read`, `create`, `update`, `delete`, `post`, `void`, `reverse`, `approve`, `release`, `submit`,
`export`, `manage`, `adjust`, `reconcile`, `transfer`, `calculate`. Create/update/delete on a draft
resource is deliberately a *different* permission from post/void/release/submit on the same resource,
because the latter group is irreversible or externally visible (bank ledger, tax authority, payroll bank
file) and is exactly the set eligible for the `is_sensitive`/`requires_approval` flags described above.

## 2. Catalog (representative — full catalog is served by the discovery endpoint in §4)

| Permission key | Category | Sensitive | Typical roles |
|---|---|---|---|
| `auth.roles.manage` | Auth | yes | Owner, CEO |
| `auth.permissions.read` | Auth | no | Owner, CEO, Auditor |
| `auth.api_keys.manage` | Auth | yes | Owner, CFO |
| `auth.policies.manage` | Auth | yes | Owner, CEO |
| `auth.approvals.approve` | Auth | yes | Per approval-chain step config |
| `auth.impersonate` | Auth | yes | QAYD internal support role only |
| `accounting.read` | Accounting | no | Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, Read Only |
| `accounting.journal.create` | Accounting | no | Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant |
| `accounting.journal.post` | Accounting | yes | Owner, CEO, CFO, Finance Manager, Senior Accountant |
| `accounting.journal.void` | Accounting | yes | Owner, CEO, CFO |
| `accounting.journal.reverse` | Accounting | yes | Owner, CEO, CFO, Finance Manager |
| `accounting.period.close` | Accounting | yes | Owner, CEO, CFO, Senior Accountant |
| `accounting.coa.manage` | Accounting | yes | Owner, CEO, CFO |
| `bank.read` | Banking | no | Owner, CEO, CFO, Finance Manager, Auditor |
| `bank.transfer` | Banking | yes | Owner, CEO, CFO, Finance Manager (limit-scoped) |
| `bank.reconcile` | Banking | no | Owner, CEO, CFO, Finance Manager, Senior Accountant |
| `bank.statement.import` | Banking | no | Owner, CEO, CFO, Finance Manager |
| `sales.read` | Sales | no | Owner, CEO, Sales Manager, Sales Employee, Auditor |
| `sales.quotation.create` | Sales | no | Sales Manager, Sales Employee |
| `sales.invoice.create` | Sales | no | Sales Manager, Sales Employee |
| `sales.invoice.void` | Sales | yes | Owner, CEO, CFO, Sales Manager |
| `sales.discount.override` | Sales | yes | Owner, CEO, Sales Manager (limit-scoped) |
| `purchasing.read` | Purchasing | no | Owner, CEO, Purchasing Manager, Purchasing Employee, Auditor |
| `purchasing.order.create` | Purchasing | no | Purchasing Manager, Purchasing Employee |
| `purchasing.payment.create` | Purchasing | yes | Owner, CEO, CFO, Purchasing Manager (limit-scoped) |
| `inventory.read` | Inventory | no | Owner, CEO, Inventory Manager, Warehouse Employee, Auditor |
| `inventory.adjust` | Inventory | yes | Inventory Manager, Warehouse Employee (warehouse-scoped) |
| `inventory.count.approve` | Inventory | yes | Owner, CEO, Inventory Manager |
| `payroll.read` | Payroll | no | Owner, CEO, CFO, HR Manager, Payroll Officer (dept-scoped) |
| `payroll.calculate` | Payroll | no | HR Manager, Payroll Officer |
| `payroll.approve` | Payroll | yes | Owner, CEO, CFO, HR Manager |
| `payroll.release` | Payroll | yes | Owner, CEO, CFO |
| `tax.read` | Tax | no | Owner, CEO, CFO, Auditor |
| `tax.calculate` | Tax | no | Owner, CEO, CFO, Senior Accountant |
| `tax.submit` | Tax | yes | Owner, CEO, CFO |
| `reports.read` | Reports | no | most roles (module-scoped) |
| `reports.export` | Reports | no | most roles (module-scoped) |
| `hr.employee.manage` | HR | yes | Owner, CEO, HR Manager |
| `settings.company.manage` | Settings | yes | Owner |
| `settings.branch.manage` | Settings | yes | Owner, CEO |
| `ai.invoke` | AI | no | Any role holding the underlying business permission |
| `ai.autonomy.manage` | AI | yes | Owner, CEO, CFO |
| `users.invite` | Users | no | Owner, CEO |
| `users.role.assign` | Users | yes | Owner, CEO |

Wildcards are supported for aggregation only, never for definition: `accounting.*` in a role's grant list
expands, at resolution time, to every currently-defined `accounting.` key — it is sugar over an explicit
list, not a new kind of key, and new permissions added later under `accounting.` are automatically
included for any role holding the wildcard (used for `owner`/`ceo`/`cfo`-level roles).

## 3. Enforcement algorithm (recap, precise contract)

1. `EnsurePermission:<key>` (RBAC gate) — coarse: does the resolved `PermissionSet` contain `<key>` or a
   covering wildcard? No → `403 permission_denied` immediately, request never reaches the controller.
2. FormRequest `authorize()` + Policy method (ABAC) — fine: do the resource/subject/environment
   attributes satisfy every applicable `policy_rules` row and hard-coded Policy method? No →
   `403 policy_denied` (or `404` if the denial reason is cross-tenant, see "Tenant Isolation").
3. `requires_approval` flag / sensitive-op policy — if true and no active, satisfied approval chain
   covers this exact action, the write is intercepted: the record is created with `status = 'pending_approval'`
   (or, for actions with no natural draft state, an `approval_requests` row is created) and the response
   is `202 Accepted`, not `200`/`201`. See "Policies".
4. Only after all three gates pass does the Service/Repository execute the write and emit the domain
   event / audit log entry.

## 4. The 403 contract

Every denial at gates 1–2 returns HTTP `403` with the standard envelope and a machine-readable `code`,
never a bare boolean or an empty body. Frontends key their UI (disable vs. hide vs. "request access")
off `code`, not off the human-readable `message`, and the human-readable `message` is always translated
via `td()`-equivalent i18n on the client, not parsed.

| `code` | Meaning | Typical trigger |
|---|---|---|
| `permission_denied` | RBAC gate failed — role has no matching key | User's role lacks `bank.transfer` |
| `policy_denied` | ABAC gate failed — attributes don't satisfy policy | Warehouse Employee outside their scope |
| `insufficient_scope` | API key / OAuth token scope too narrow | Integration key has `accounting:read` only |
| `tenant_mismatch` | `X-Company-Id` not in caller's memberships | Stale header from a previous session |
| `approval_pending` | Resource is locked behind an unresolved approval | Editing a transfer awaiting CFO sign-off |
| `mfa_required` | Sensitive action needs step-up auth this session | First bank transfer of the session |
| `ai_autonomy_blocked` | AI attempted an action above its autonomy level | AI tried to auto-release payroll |

Standard shape (all fields always present; `errors[]` carries structured detail for `403`/`422`):

```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to post journal entries.",
  "errors": [
    {
      "code": "permission_denied",
      "permission": "accounting.journal.post",
      "field": null
    }
  ],
  "meta": null,
  "request_id": "6f1a1e2c-2b3f-4a41-9a4f-9d6a0e5b2b10",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

`401` is reserved strictly for "who are you" failures (missing/expired/invalid bearer token). `403` is
reserved strictly for "I know who you are, and the answer is no." `404` is used, deliberately, for a
resource that exists but belongs to a different tenant — see "Tenant Isolation" for the enumeration-safety
rationale. A client that receives `403 tenant_mismatch` should re-run the company-switch flow before
retrying; a client that receives `403 permission_denied`/`policy_denied` should not retry and should
surface a "request access" affordance instead.

## 5. Discovery endpoints

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/auth/permissions` | `auth.permissions.read` | Full system permission catalog |
| GET | `/api/v1/auth/me/permissions` | (none — always available to an authenticated user) | Effective permission set for the caller in the active company |
| GET | `/api/v1/auth/roles` | `auth.roles.manage` or `auth.permissions.read` | List roles (system + company custom) |
| POST | `/api/v1/auth/roles` | `auth.roles.manage` | Create a custom role |
| PATCH | `/api/v1/auth/roles/{role}` | `auth.roles.manage` | Edit a custom role's permission grants |
| DELETE | `/api/v1/auth/roles/{role}` | `auth.roles.manage` | Soft-delete a custom role (system roles are immutable) |

```
GET /api/v1/auth/me/permissions
Headers: Authorization: Bearer <token>, X-Company-Id: 1002
```

```json
{
  "success": true,
  "data": {
    "user_id": 233,
    "company_id": 1002,
    "branch_id": null,
    "roles": ["finance_manager"],
    "permissions": [
      "accounting.read", "accounting.journal.create", "accounting.journal.post",
      "accounting.journal.reverse", "bank.read", "bank.transfer", "bank.reconcile",
      "reports.read", "reports.export"
    ],
    "approval_limit": { "currency": "KWD", "amount": "5000.0000" }
  },
  "message": "OK",
  "errors": [],
  "meta": null,
  "request_id": "6a2e2b8e-9c1d-4e2a-8b1a-3d0e6f2c1a44",
  "timestamp": "2026-07-16T09:15:40Z"
}
```

# Scopes

RBAC/ABAC above governs a **human session** (web or mobile, bearer token tied to a `users` row). Three
other principal types call the same API and need a narrower, explicitly-granted contract instead of "the
full permission set of whoever created the credential": server-to-server **API keys**, **OAuth clients**
(future partner marketplace), and the **AI engine**'s own service credential. In every case the rule is
identical: *a scope can subset a user's permissions, it can never exceed them.*

## 1. API keys

```sql
CREATE TABLE api_keys (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    name            VARCHAR(120) NOT NULL,
    prefix          VARCHAR(16)  NOT NULL,            -- shown in UI, e.g. 'qak_live_8f2a'
    key_hash        VARCHAR(255) NOT NULL,             -- Argon2id hash, never store the raw key
    scopes          JSONB NOT NULL DEFAULT '[]',        -- e.g. ["accounting:read","webhooks:manage"]
    ip_allowlist    JSONB NULL,                         -- CIDR list, NULL = unrestricted
    rate_limit_tier VARCHAR(16) NOT NULL DEFAULT 'standard',
    created_by      BIGINT NOT NULL REFERENCES users(id),
    last_used_at    TIMESTAMPTZ NULL,
    expires_at      TIMESTAMPTZ NULL,
    revoked_at      TIMESTAMPTZ NULL,
    revoked_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_api_keys_company ON api_keys(company_id) WHERE revoked_at IS NULL;
CREATE UNIQUE INDEX uq_api_keys_prefix ON api_keys(prefix);
```

A key is created by a user holding `auth.api_keys.manage`; the API rejects any requested scope not
already present in that user's own effective permission set (`422 scope_exceeds_grantor` if attempted).
Keys are always company-scoped — there is no such thing as a cross-company API key, matching the
platform's absolute tenant-isolation rule.

| Scope | Underlying permission keys granted | Typical use |
|---|---|---|
| `accounting:read` | `accounting.read`, `reports.read` (accounting reports) | BI/reporting integration |
| `accounting:write` | `accounting.journal.create` (never `.post`/`.void`) | External import tool staging entries as drafts |
| `bank:sync` | `bank.statement.import`, `bank.read` | Bank-feed aggregator webhook consumer |
| `inventory:sync` | `inventory.read`, `inventory.adjust` (warehouse-scoped by key config) | E-commerce/POS stock sync |
| `webhooks:manage` | management of the key's own webhook subscriptions only | Any integration needing event delivery |
| `ai:invoke` | proxies to the acting user's own permission set (see §3) | The FastAPI AI engine's service credential |

Scope enforcement is a middleware parallel to `EnsurePermission`, applied only on the API-key auth guard:

```php
// app/Http/Middleware/EnsureScope.php
public function handle(Request $request, Closure $next, string $scope): Response
{
    $apiKey = $request->attributes->get('api_key');
    if (! in_array($scope, $apiKey->scopes, true)) {
        return ApiResponse::error('insufficient_scope', "API key missing scope: {$scope}", 403);
    }
    return $next($request);
}
```

## 2. OAuth2 clients (partner marketplace, forward-looking)

Third-party partner apps authorize via standard OAuth2 Authorization Code + PKCE. The consent screen
renders each requested scope in plain language ("Read your Chart of Accounts", "Create draft invoices on
your behalf") sourced from the same scope→permission mapping table as API keys, so the two credential
types share one mental model and one enforcement middleware.

```
POST /api/v1/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&client_id=...&client_secret=...&code=...&redirect_uri=...
```

```json
{
  "success": true,
  "data": {
    "access_token": "qat_...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "refresh_token": "qrt_...",
    "scope": "accounting:read reports:read"
  },
  "message": "OK",
  "errors": [],
  "meta": null,
  "request_id": "b7e2f1a0-1c2d-4e3f-9a5b-0c1d2e3f4a5b",
  "timestamp": "2026-07-16T09:20:11Z"
}
```

## 3. The AI agent's scope — "on-behalf-of", never "instead-of"

The FastAPI AI engine holds one long-lived internal service credential scoped only to `ai:invoke` and
nothing else — that scope, by itself, authorizes *nothing* on any business resource. Every business
action the AI engine wants to take is expressed as a call back into this same Laravel API carrying two
additional headers identifying the human on whose behalf it is acting:

```
POST /api/v1/accounting/journal-entries
Authorization: Bearer <ai-service-token>
X-Company-Id: 1002
X-Acting-As-User-Id: 233
X-Agent-Id: general-accountant-v3
Idempotency-Key: 8f14e45f-ceea-4f7a-b1a9-2c3d4e5f6a7b
```

The Laravel middleware pipeline resolves `PermissionResolver::effectivePermissions()` for user `233`
exactly as if user `233` had called the endpoint directly from the web app — the AI service token itself
carries no business permissions to fall back on. If user `233` does not hold `accounting.journal.create`,
the AI's request is denied with the same `403 permission_denied` a human would get. This is the structural
guarantee behind "AI obeys the same permissions as the user": there is no separate, more-permissive code
path for AI-originated writes — it is the identical Gate → Policy → sensitive-op pipeline, with the origin
flag (`PolicyContext::$origin === 'ai_agent'`) additionally unlocking the autonomy check in the next
section, which can only make the outcome *more* restrictive, never less.

## 4. Token/scope introspection

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/api/v1/auth/token/introspect` | (self-authenticating) | RFC 7662-style introspection for any bearer credential |
| GET | `/api/v1/auth/api-keys` | `auth.api_keys.manage` | List a company's API keys (hash never returned) |
| POST | `/api/v1/auth/api-keys` | `auth.api_keys.manage` | Create a key; raw key returned exactly once |
| DELETE | `/api/v1/auth/api-keys/{key}` | `auth.api_keys.manage` | Revoke a key immediately |

```bash
curl -X POST https://api.qayd.app/api/v1/auth/token/introspect \
  -H "Authorization: Bearer qak_live_8f2a...redacted" \
  -H "Content-Type: application/json" \
  -d '{"token_type_hint":"api_key"}'
```

```json
{
  "success": true,
  "data": {
    "active": true,
    "company_id": 1002,
    "scopes": ["accounting:read", "webhooks:manage"],
    "expires_at": null,
    "created_by": 55
  },
  "message": "OK",
  "errors": [],
  "meta": null,
  "request_id": "1e2d3c4b-5a6f-4e7d-8c9b-0a1b2c3d4e5f",
  "timestamp": "2026-07-16T09:22:03Z"
}
```

# Policies

"Policies" here means the approval-chain engine: the mechanism that turns a subset of permission-gated,
ABAC-cleared actions into a **proposal** rather than an immediate write, and routes that proposal through
one or more human approvers before the underlying business action actually executes. This is the
platform's answer to "some actions must never be a single click (or a single AI decision) away from
happening."

## 1. Always-sensitive operations

These operations require an approval chain in every company, unconditionally — a company cannot disable
approval for this list, only customize *who* the approvers are:

| Operation | `permission_key` | Default chain |
|---|---|---|
| Bank transfer ≥ company threshold | `bank.transfer` | Finance Manager → CFO |
| Payroll release | `payroll.release` | HR Manager → CFO |
| Tax submission | `tax.submit` | Senior Accountant → CFO |
| Void/delete a posted financial record | `accounting.journal.void`, `sales.invoice.void` | CFO (single step) |
| Permission/role changes | `auth.roles.manage`, `users.role.assign` | Owner (single step) |
| Company settings changes | `settings.company.manage` | Owner (single step) |
| API key creation with a write scope | `auth.api_keys.manage` | CFO (single step) |

## 2. Database design

```sql
CREATE TABLE approval_chains (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    permission_key VARCHAR(160) NOT NULL REFERENCES permissions(key),
    name          VARCHAR(120) NOT NULL,
    threshold_amount NUMERIC(19,4) NULL,     -- NULL = applies unconditionally
    threshold_currency VARCHAR(3) NULL,
    is_system_minimum BOOLEAN NOT NULL DEFAULT false,  -- true => company cannot delete, only add steps
    is_active     BOOLEAN NOT NULL DEFAULT true,
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL
);

CREATE TABLE approval_chain_steps (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chain_id        BIGINT NOT NULL REFERENCES approval_chains(id) ON DELETE CASCADE,
    step_order      INT NOT NULL,
    approver_role_id BIGINT NOT NULL REFERENCES roles(id),
    is_parallel     BOOLEAN NOT NULL DEFAULT false,   -- true => this step can run alongside step_order siblings
    min_approvals   INT NOT NULL DEFAULT 1,           -- for parallel steps requiring N-of-M
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_chain_step_order UNIQUE (chain_id, step_order, approver_role_id)
);

CREATE TABLE approval_requests (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    chain_id          BIGINT NOT NULL REFERENCES approval_chains(id),
    permission_key    VARCHAR(160) NOT NULL,
    resource_type     VARCHAR(64) NOT NULL,             -- e.g. 'transfers', 'payroll_runs'
    resource_id       BIGINT NULL,                       -- NULL until the underlying draft is materialized
    payload           JSONB NOT NULL,                    -- the pending action's full intended body
    amount            NUMERIC(19,4) NULL,
    currency_code     VARCHAR(3) NULL,
    status            VARCHAR(16) NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','approved','rejected','expired','cancelled')),
    current_step      INT NOT NULL DEFAULT 1,
    initiated_by_type VARCHAR(16) NOT NULL DEFAULT 'user' CHECK (initiated_by_type IN ('user','ai_agent')),
    initiated_by_id   BIGINT NOT NULL,                   -- users.id, always the human of record even for AI
    agent_id          VARCHAR(64) NULL,                  -- set when initiated_by_type = 'ai_agent'
    ai_confidence     NUMERIC(5,4) NULL,                 -- 0.0000–1.0000
    ai_reasoning      TEXT NULL,
    ai_source_documents JSONB NULL,                       -- attachment ids the AI grounded its proposal in
    expires_at        TIMESTAMPTZ NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_approval_requests_company_status ON approval_requests(company_id, status);

CREATE TABLE approval_request_actions (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    approval_request_id BIGINT NOT NULL REFERENCES approval_requests(id) ON DELETE CASCADE,
    step_order        INT NOT NULL,
    actor_user_id     BIGINT NOT NULL REFERENCES users(id),
    decision          VARCHAR(8) NOT NULL CHECK (decision IN ('approved','rejected')),
    reason            TEXT NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

## 3. Workflow

```
 initiator (user or AI-on-behalf-of-user)
        │  action carries permission_key that is is_sensitive/requires_approval
        ▼
 RBAC + ABAC gates pass ──────────────────────────────────────────────► normal path if NOT sensitive
        │
        ▼ (sensitive)
 resolve approval_chains for (company_id, permission_key, amount) ── none active? allow immediately
        │
        ▼ (active chain found)
 create approval_requests row, status='pending', current_step=1 ── API responds 202 Accepted
        │
        ▼
 notify step-1 approver role (in-app + email, Reverb realtime push)
        │
   approver calls PATCH /api/v1/auth/approvals/{id} {"decision":"approved"}
        │
        ├─ rejected ─► status='rejected' ─► webhook approval.rejected ─► initiator notified, nothing executes
        │
        ▼ approved
 more steps remain? ── yes ─► current_step += 1 ─► notify next approver role
        │
        ▼ no (final step approved)
 status='approved' ─► queued job executes the ORIGINAL payload against the real endpoint/service
        │             (using the ORIGINAL initiator's permission context, re-validated)
        ▼
 underlying resource created/mutated ─► webhook approval.approved + the domain event (e.g. transfer.completed)
```

Expiry: every `approval_requests` row carries `expires_at` (default 72 hours, company-configurable down
to a system minimum of 24 hours); a scheduled job flips unresolved rows to `status='expired'` and fires
`approval.expired`, requiring the initiator to resubmit.

## 4. Endpoints

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/auth/approvals` | `auth.approvals.approve` or ownership of the request | List pending/decided approval requests |
| GET | `/api/v1/auth/approvals/{id}` | same | Full detail incl. AI reasoning if applicable |
| PATCH | `/api/v1/auth/approvals/{id}` | `auth.approvals.approve` (role must match current step) | Approve or reject |
| DELETE | `/api/v1/auth/approvals/{id}` | initiator only | Cancel a still-pending request |
| GET | `/api/v1/auth/approval-chains` | `auth.policies.manage` | List a company's configured chains |
| PATCH | `/api/v1/auth/approval-chains/{id}` | `auth.policies.manage` | Adjust threshold/approver roles (bounded by `is_system_minimum`) |

## 5. AI-initiated approval requests

When the AI engine proposes a sensitive action, it does not get a lighter-weight path — it gets a
**more heavily documented** one. The `approval_requests` row it creates always populates
`initiated_by_type='ai_agent'`, `agent_id`, `ai_confidence`, `ai_reasoning`, and `ai_source_documents`,
so the human approver reviews not just "approve this payroll release" but the AI's stated evidence and
certainty:

```json
{
  "id": "apr_01J2Y6Z8QF3K",
  "company_id": 1002,
  "chain_id": 4,
  "permission_key": "payroll.release",
  "resource_type": "payroll_runs",
  "resource_id": 771,
  "amount": "48250.0000",
  "currency_code": "KWD",
  "status": "pending",
  "current_step": 1,
  "initiated_by_type": "ai_agent",
  "initiated_by_id": 233,
  "agent_id": "payroll-manager-v2",
  "ai_confidence": 0.9700,
  "ai_reasoning": "All 42 employee salary components recalculated against July fiscal_period; 0 variances >2% vs June; attendance sync complete; no pending leave-without-pay adjustments.",
  "ai_source_documents": ["att_88f1", "att_88f2"],
  "expires_at": "2026-07-19T09:00:00Z",
  "created_at": "2026-07-16T09:00:00Z"
}
```

## 6. Autonomy levels

Every AI agent operates at exactly one of three autonomy levels **per permission key**, configured in
`ai_autonomy_settings` (company-scoped, editable only via `ai.autonomy.manage`):

| Level | Behavior |
|---|---|
| `auto` | AI executes directly (still fully RBAC/ABAC-checked as the acting user) — reserved for non-sensitive, easily-reversible actions, e.g. drafting a journal entry |
| `suggest_only` | AI writes a suggestion/draft the user must explicitly submit themselves — the AI never calls the write endpoint on its own initiative |
| `requires_approval` | AI may call the write endpoint, but doing so always produces an `approval_requests` row per this section, regardless of the `is_sensitive` flag |

Every operation in the "always-sensitive" table in §1 is hard-pinned to `requires_approval` for AI
principals and cannot be reconfigured to `auto`, even by an Owner — this is a system minimum, not a
company setting.

# Tenant Isolation

Company isolation is the platform's hardest guarantee: it must hold even if a future engineer forgets a
`WHERE company_id = ?` clause. QAYD achieves this with four independent layers so that any single missed
layer is caught by the next one, rather than relying on developer discipline alone.

## 1. Layer 1 — Middleware resolution and validation

`ResolveActiveCompany` reads the `X-Company-Id` header on every request, and confirms it against an
**active, non-expired** row in `company_users` for the authenticated user:

```php
public function handle(Request $request, Closure $next): Response
{
    $companyId = (int) $request->header('X-Company-Id');

    $membership = CompanyUser::query()
        ->where('user_id', $request->user()->id)
        ->where('company_id', $companyId)
        ->where('status', 'active')
        ->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
        ->first();

    if (! $membership) {
        return ApiResponse::error('tenant_mismatch', 'You do not have access to this company.', 403);
    }

    $request->attributes->set('active_company_id', $companyId);
    $request->attributes->set('active_branch_id', $membership->branch_id);
    DB::statement('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $companyId]);

    return $next($request);
}
```

That last line is the bridge to Layer 4 (PostgreSQL RLS) — it sets a session-local Postgres variable for
every request, inside the same connection, before any query runs.

## 2. Layer 2 — Eloquent global scope

Every tenant-scoped model uses a shared trait that registers a global scope, so **every** Eloquent query
against that model — including ones written by an engineer who forgot to think about tenancy at all — is
automatically constrained:

```php
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if ($companyId = request()->attributes->get('active_company_id')) {
                $builder->where($builder->getModel()->getTable() . '.company_id', $companyId);
            }
        });

        static::creating(function (Model $model) {
            $model->company_id ??= request()->attributes->get('active_company_id');
        });
    }
}
```

## 3. Layer 3 — Policy re-check (belt-and-suspenders against raw/joined queries)

Every Policy method that receives a specific model re-checks `resource.company_id` explicitly (shown
already in the `JournalEntryPolicy::post()` example under "ABAC") — this catches the case where a query
used `DB::table()` / a raw join / an eager-loaded relation that bypassed the Eloquent global scope.

## 4. Layer 4 — PostgreSQL Row-Level Security (last line of defense)

RLS is enabled on every tenant table so that even a compromised application-layer bug, a stray migration
console, or a future direct-DB debugging session cannot cross a tenant boundary:

```sql
ALTER TABLE journal_entries ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_journal_entries ON journal_entries
    USING (company_id = current_setting('app.current_company_id', true)::bigint);
-- Repeated for every tenant table: invoices, bills, bank_transactions, payroll_runs, ...
```

The application's database role is never granted `BYPASSRLS`; only a narrowly-scoped, break-glass
operations role has that privilege, and its use is itself logged to `audit_logs` and alerted.

## 5. Branch and department scoping layer on top, they do not replace it

Company isolation is absolute and structural (four layers above). Branch (`branch_id`) and department
(`department_id`) scoping is an **additional** ABAC narrowing within an already-isolated company — a
`warehouse_employee` scoped to Branch 2 still has their queries first constrained to `company_id = 1002`
by Layers 1–4, and only then further narrowed to `branch_id = 2` by the Policy/`policy_rules` layer
described under "ABAC". Losing branch scoping is a business-rule bug; losing company scoping is a data
breach — the architecture treats them with correspondingly different levels of redundancy.

## 6. 403 vs. 404 — enumeration safety

| Situation | Response |
|---|---|
| Resource exists in the active company, caller lacks the permission/policy | `403` (`permission_denied`/`policy_denied`) |
| Resource belongs to a **different** company entirely | `404` — never `403` |
| `X-Company-Id` itself is not a company the caller belongs to | `403 tenant_mismatch` (the header, not a resource, is what's rejected) |

Returning `404` for a foreign-tenant resource (instead of `403`) is deliberate: a `403` confirms the
record exists, which is itself a leak across the tenant boundary (ID enumeration). `403` is only ever
used when the caller has already proven they're allowed to know the resource exists.

## 7. Company switching

```
POST /api/v1/auth/switch-company
Authorization: Bearer <token>
{ "company_id": 1002 }
```

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOi...",
    "company_id": 1002,
    "branch_id": null,
    "roles": ["finance_manager"]
  },
  "message": "Company switched.",
  "errors": [],
  "meta": null,
  "request_id": "9c0b1a2d-3e4f-4a5b-8c9d-0e1f2a3b4c5d",
  "timestamp": "2026-07-16T09:25:47Z"
}
```

## 8. Support impersonation

QAYD staff support access is a distinct, tightly-scoped principal type (`auth.impersonate`, internal
role only), requiring: the company `owner` to have an active, time-boxed consent flag set from Settings;
a mandatory reason string; automatic expiry at 4 hours; and every single action taken while impersonating
written to `audit_logs` with `impersonated_by` populated, plus a persistent, undismissable banner in every
client surface for the duration.

## 9. Testing the guarantee

```php
// tests/Feature/TenantIsolationTest.php
it('returns 404, not 403, for a journal entry belonging to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $entry = JournalEntry::factory()->for($companyB)->create();
    $user = actingAsUserOf($companyA, role: 'cfo'); // full permissions, wrong tenant

    $response = getJson("/api/v1/accounting/journal-entries/{$entry->id}", [
        'X-Company-Id' => $companyA->id,
    ]);

    $response->assertStatus(404);
});
```

# Examples

Eight fully worked request/response pairs, ordered to walk through every gate described above: a clean
success, an RBAC denial, an ABAC denial, a scope denial, a tenant-isolation denial, a full approval-chain
lifecycle, the same chain triggered by the AI engine, and a company switch.

## Example 1 — Success: Accountant creates a draft journal entry

```bash
curl -X POST https://api.qayd.app/api/v1/accounting/journal-entries \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 1002" \
  -H "Content-Type: application/json" \
  -d '{
        "fiscal_period_id": 44,
        "description": "July office rent accrual",
        "lines": [
          {"account_id": 5310, "debit": "850.0000", "credit": "0.0000", "cost_center_id": 12},
          {"account_id": 2110, "debit": "0.0000",   "credit": "850.0000"}
        ]
      }'
```

Caller holds `accounting.journal.create` (Accountant role). Response `201`:

```json
{
  "success": true,
  "data": {
    "id": 90441,
    "company_id": 1002,
    "status": "draft",
    "description": "July office rent accrual",
    "total_debit": "850.0000",
    "total_credit": "850.0000",
    "created_by": 512,
    "created_at": "2026-07-16T09:30:02Z"
  },
  "message": "Journal entry created.",
  "errors": [],
  "meta": null,
  "request_id": "3f4e5d6c-7b8a-4192-9c1d-2e3f4a5b6c7d",
  "timestamp": "2026-07-16T09:30:02Z"
}
```

## Example 2 — RBAC denial: same Accountant tries to post it

```
POST /api/v1/accounting/journal-entries/90441/post
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1002
```

The Accountant role does not carry `accounting.journal.post` (only Senior Accountant and above do).
Response `403`:

```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to post journal entries.",
  "errors": [{ "code": "permission_denied", "permission": "accounting.journal.post", "field": null }],
  "meta": null,
  "request_id": "4a5b6c7d-8e9f-4a1b-8c2d-3e4f5a6b7c8d",
  "timestamp": "2026-07-16T09:31:10Z"
}
```

## Example 3 — ABAC denial: Warehouse Employee outside their scope

Warehouse Employee #881 is scoped to warehouse `WH-02` only, and holds `inventory.adjust`. They attempt
to adjust stock in `WH-05`:

```
POST /api/v1/inventory/stock-adjustments
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1002
{ "warehouse_id": 5, "product_id": 8821, "quantity_delta": "-3.0000", "reason": "damaged" }
```

RBAC passes (`inventory.adjust` is present); the `policy_rules` row from the ABAC section denies:

```json
{
  "success": false,
  "data": null,
  "message": "You are not authorized to adjust stock for this warehouse.",
  "errors": [{ "code": "policy_denied", "permission": "inventory.adjust", "field": "warehouse_id" }],
  "meta": null,
  "request_id": "5b6c7d8e-9f0a-4b2c-8d3e-4f5a6b7c8d9e",
  "timestamp": "2026-07-16T09:32:44Z"
}
```

## Example 4 — Scope denial: read-only integration key attempts a write

```bash
curl -X POST https://api.qayd.app/api/v1/accounting/journal-entries \
  -H "Authorization: Bearer qak_live_8f2a...redacted" \
  -H "X-Company-Id: 1002" \
  -H "Content-Type: application/json" \
  -d '{"fiscal_period_id":44,"description":"test","lines":[]}'
```

The key's `scopes` array contains only `accounting:read`:

```json
{
  "success": false,
  "data": null,
  "message": "This API key does not have the required scope.",
  "errors": [{ "code": "insufficient_scope", "permission": "accounting:write", "field": null }],
  "meta": null,
  "request_id": "6c7d8e9f-0a1b-4c3d-8e4f-5a6b7c8d9e0f",
  "timestamp": "2026-07-16T09:33:05Z"
}
```

## Example 5 — Tenant-isolation denial: cross-company fetch returns 404

CFO of Company 1002 has full accounting permissions, but requests journal entry `77120`, which belongs
to Company 2231:

```
GET /api/v1/accounting/journal-entries/77120
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1002
```

```json
{
  "success": false,
  "data": null,
  "message": "Resource not found.",
  "errors": [{ "code": "not_found", "field": null }],
  "meta": null,
  "request_id": "7d8e9f0a-1b2c-4d4e-8f5a-6b7c8d9e0f1a",
  "timestamp": "2026-07-16T09:34:18Z"
}
```

Note: no `403` and no mention that a record `77120` exists anywhere — this is the enumeration-safety
behavior described under "Tenant Isolation".

## Example 6 — Full approval chain: a bank transfer over threshold

Finance Manager (approval limit 5,000 KWD) initiates a transfer of 15,000 KWD:

```
POST /api/v1/bank/transfers
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1002
{ "from_account_id": 41, "to_account_id": 902, "amount": "15000.0000", "currency_code": "KWD",
  "memo": "Q3 supplier settlement — Al Rawabi Trading" }
```

RBAC (`bank.transfer`) and ABAC pass, but amount ≥ `approval_limit` routes into the chain. Response `202`:

```json
{
  "success": true,
  "data": {
    "approval_request_id": "apr_01J2Y7A1BZQK",
    "status": "pending",
    "current_step": 1,
    "chain": "Finance Manager → CFO",
    "expires_at": "2026-07-19T09:36:00Z"
  },
  "message": "This transfer requires approval before it is executed.",
  "errors": [],
  "meta": null,
  "request_id": "8e9f0a1b-2c3d-4e5f-8a6b-7c8d9e0f1a2b",
  "timestamp": "2026-07-16T09:36:00Z"
}
```

CFO approves:

```
PATCH /api/v1/auth/approvals/apr_01J2Y7A1BZQK
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1002
{ "decision": "approved" }
```

```json
{
  "success": true,
  "data": {
    "approval_request_id": "apr_01J2Y7A1BZQK",
    "status": "approved",
    "resource": { "type": "transfers", "id": 8891, "status": "completed" }
  },
  "message": "Transfer approved and executed.",
  "errors": [],
  "meta": null,
  "request_id": "9f0a1b2c-3d4e-4f6a-8b7c-8d9e0f1a2b3c",
  "timestamp": "2026-07-16T10:02:11Z"
}
```

A `transfer.completed` webhook fires to any subscribed endpoint immediately after.

## Example 7 — Same chain, initiated by the AI engine

The Treasury Manager AI agent, acting on behalf of Finance Manager user `233`, proposes the identical
transfer after detecting an overdue supplier invoice:

```
POST /api/v1/bank/transfers
Authorization: Bearer <ai-service-token>
X-Company-Id: 1002
X-Acting-As-User-Id: 233
X-Agent-Id: treasury-manager-v1
Idempotency-Key: c1d2e3f4-5a6b-4c7d-8e9f-0a1b2c3d4e5f
{ "from_account_id": 41, "to_account_id": 902, "amount": "15000.0000", "currency_code": "KWD",
  "memo": "Q3 supplier settlement — Al Rawabi Trading", "ai_reasoning": "Invoice bil_4471 is 12 days overdue; account 41 has sufficient cleared balance; no open dispute on file." }
```

Because `bank.transfer` is hard-pinned to `requires_approval` for AI principals (regardless of amount),
this creates an `approval_requests` row with `initiated_by_type="ai_agent"` and the same `202` shape as
Example 6, plus the AI fields shown in the "Policies" section. The CFO reviews `ai_reasoning` and
`ai_confidence` alongside the transfer details before approving — the approval endpoint and payload are
otherwise identical to the human-initiated case, by design.

## Example 8 — Company switch

```
POST /api/v1/auth/switch-company
Authorization: Bearer eyJhbGciOi...
{ "company_id": 2231 }
```

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOi.new.token",
    "company_id": 2231,
    "branch_id": null,
    "roles": ["external_auditor"]
  },
  "message": "Company switched.",
  "errors": [],
  "meta": null,
  "request_id": "d2e3f4a5-6b7c-4d8e-8f9a-0b1c2d3e4f5a",
  "timestamp": "2026-07-16T10:05:00Z"
}
```

The new token's claims bind `company_id=2231`; any subsequent request with the old `X-Company-Id: 1002`
now fails Layer 1 (`ResolveActiveCompany`) with `403 tenant_mismatch`, even though the bearer token itself
is still otherwise valid — company context is re-validated per request, not cached in the token beyond
the company it was issued for.

# End of Document

