# API Architecture — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API_ARCHITECTURE

---

# Purpose

This document is the canonical engineering specification for the API layer of QAYD, the AI Financial Operating System. It defines the architecture that every request — from the Next.js 15 web app, the Flutter mobile app, the FastAPI/Python AI engine, or a third-party integration — passes through on its way to becoming a read or a write against the ledger. Where `DATABASE_ARCHITECTURE` defines how QAYD's data is stored, and `DATABASE_MULTI_TENANCY` and `DATABASE_ROW_LEVEL_SECURITY` define how tenant isolation is enforced at the storage layer, this document defines the single door through which every one of those reads and writes must pass: the REST API served by Laravel 12.

QAYD's founding architectural rule is that **Laravel owns the API, and the API owns the data**. No client — not the web app, not the mobile app, not the AI engine, not an internal admin script — is ever permitted to query PostgreSQL directly. Every business action in the platform, without exception, is an HTTP request against `/api/v1/...`, carrying a bearer token, scoped to a company, validated against a request schema, checked against a default-deny permission system, and executed inside a service layer that enforces QAYD's accounting and business invariants. This is not a convenience choice; it is what makes it possible for QAYD to state, truthfully, that every number a customer sees can be traced to a specific authenticated actor, a specific permission grant, and a specific validated request.

This document's scope is everything an engineer or an AI agent needs to build, extend, secure, or operate that layer without reverse-engineering intent from source code: the REST architectural style QAYD commits to; how resources are named, shaped, and versioned; the request lifecycle every call passes through; authentication and authorization; rate limiting and caching; idempotency; pagination, filtering, and sorting; the error model; observability (monitoring and logging); the versioning and deprecation policy; the webhook event system; the SDK strategy; the deliberate non-adoption of GraphQL today and the conditions under which that could change; performance and scalability targets; the security posture of the layer as a whole; the OpenAPI contract discipline; how the AI engine is granted API access without becoming a backdoor; and a set of fully worked request/response examples that tie every preceding section together into runnable artifacts.

Module-specific endpoint catalogs — the exact routes for Accounting, Sales, Purchasing, Banking, Inventory, Payroll, Tax, and Reports — are specified in their own module documents and must conform to every convention this document defines. Where a module document appears to conflict with this one, this document is authoritative for cross-cutting concerns (envelope shape, auth, error codes, pagination) and the module document is authoritative for domain-specific business rules.

# Vision

QAYD's API is not a convenience layer bolted onto a database; it is the platform's public promise of correctness, and it is the only artifact a bank, a tax authority, an auditor, an enterprise customer's internal IT team, or an independent security researcher will ever actually touch. A company's finance team never sees a SQL query. Its bank integration never sees a table. Its auditor never sees a migration file. What all of them see — the only surface QAYD exposes to the outside world — is this API. Its long-term vision is to be an API that a Fortune 500 CFO's engineering team would be comfortable integrating against with the same confidence they'd extend to Stripe for payments or Twilio for messaging: precise, boring in the best sense, and never a source of surprises.

Three multi-year commitments follow directly from that vision:

1. **The API is the only door, permanently.** As QAYD adds an AI engine, a mobile app, partner integrations, and eventually a public developer platform, the temptation to let a "trusted" internal service skip the API and talk to Postgres directly will recur at every scaling milestone. It is refused every time. The moment any internal system reads or writes the ledger outside the API, the platform loses its ability to say — truthfully, to an auditor or a regulator — that every financial mutation passed through authentication, authorization, and validation. The API is not one path among several; it is the only path, and that constraint is treated as non-negotiable infrastructure, not a style preference.

2. **The contract is more durable than any implementation behind it.** Laravel could be replaced. The database engine could change. The AI engine could be rewritten in a different language. The contract — the URL shapes, the envelope, the error codes, the permission keys, the webhook event names — is what every client, every integration, and every SDK is written against, and it must be capable of outliving several implementation generations without a breaking change forced onto customers who built against it. A versioned, deprecation-disciplined API is what makes it possible for QAYD to improve its internals aggressively while a customer's five-year-old integration keeps working unmodified.

3. **The API must be trustworthy enough to hand to an adversary.** QAYD assumes every request arrives from a hostile network, every bearer token can be intercepted or leaked, and every client library will eventually be reverse-engineered. The API's authentication, authorization, rate limiting, and audit trail are designed to remain correct under that assumption, not under the assumption of a cooperative, well-behaved caller. An API that only behaves correctly when used as documented is not production-grade for a financial system; it must behave *safely* even when misused, attacked, or called by a compromised AI agent.

The API layer's success is measured against four properties, in priority order: **correctness** (a client that follows the contract gets the right answer, every time), **security** (a client that violates the contract, or an attacker who has no valid contract at all, cannot extract or corrupt data), **stability** (a contract, once published, changes only through a disciplined, versioned, long-notice deprecation path), and **speed** (the fastest possible response that does not compromise the first three). Every architectural decision documented below is justified against that ordering.

# API Philosophy

QAYD's API design follows nine working principles. They are binding on every endpoint added to the platform, whether written by a human engineer or generated by an AI coding agent, and every code review checks new endpoints against this list explicitly.

**1. The API is the single source of truth for behavior, not just data.** Business logic — what happens when an invoice is posted, what happens when a payroll run is released, what a valid state transition looks like — lives in the Laravel service layer behind the API, never duplicated in a client. The Next.js web app, the Flutter mobile app, and the FastAPI AI engine are all "dumb" with respect to business rules: they render, they collect input, they call the API, and they display what the API returns. A validation rule implemented twice (once in a client, once in the API) is a bug waiting to happen the day the two implementations drift, so QAYD implements it exactly once, in the API, and clients treat its 422 responses as the authority.

**2. Explicit beats implicit, everywhere.** There is no endpoint that behaves differently based on undocumented header combinations, no field that silently changes meaning depending on the caller's role, and no "magic" default that a client must discover by trial and error. If a company's plan tier changes what an endpoint can do, that is documented in this specification and enforced with an explicit, named error code (`plan.upgrade_required`), never a silent no-op.

**3. Every unsafe write is idempotent by design.** Financial systems fail on retries more often than they fail on the original request — a mobile client on a bad network resends a payment; a webhook consumer replays a delivery; an AI agent's orchestration layer times out and retries. Every `POST` that creates a financial event supports an idempotency key as a first-class feature, not an afterthought, per the **Idempotency** section below.

**4. Authorization is default-deny, always.** A new endpoint with no permission assigned is unreachable, not open. A new role with no permissions assigned can do nothing beyond authenticate and view its own profile. This is enforced structurally: Laravel's `Gate::before` callback returns `false` for any ability that is not explicitly matched to a permission the caller holds, so a forgotten permission check fails closed, never open.

**5. The response envelope is the contract, not the HTTP status code alone.** Two clients receiving the same envelope must be able to make the same decision without inspecting undocumented fields. `success`, `data`, `errors`, `meta`, `request_id`, and `timestamp` appear on every single response, with no exceptions and no endpoint-specific envelope variants, because a client library that has to special-case even one endpoint's response shape is a client library with a bug in waiting.

**6. AI is a client of the API, never a bypass of it.** The FastAPI/Python AI engine authenticates like any other caller, is scoped to the same `company_id`, is checked against the same permission system (with its own AI-specific permission keys), and cannot write anything to the database that a human with the AI's assigned role could not also write through the same endpoint. Every AI-originated write carries `meta.ai_generated = true`, a confidence score, and a reasoning payload, per the **AI API Access** section.

**7. Backwards compatibility is a product feature, not an engineering courtesy.** A mobile app cannot force its installed base to upgrade the instant an API changes; a bank integration built by a customer's third-party vendor may not be touched for years. The API commits to its **Versioning** policy as a hard constraint on what "shipping a change" is allowed to mean.

**8. Every request is observable end to end.** A `request_id` is generated at the edge, appears in every log line the request touches (Laravel application logs, FastAPI AI engine logs if the request triggers an AI action, and the `audit_logs` table if the request mutates data), and is returned to the caller so that a support engineer can go from "the customer says this failed" to the exact server-side trace in seconds, per **Monitoring** and **Logging**.

**9. The API is documented by a machine-readable contract, not by prose that drifts from the code.** OpenAPI 3.1 is the source of truth for what every endpoint accepts and returns; the human-readable documentation, the SDKs, and the contract tests are all generated from — or validated against — that single specification, per the **OpenAPI** section.

# REST Architecture

QAYD's API is a resource-oriented REST API over HTTPS, exchanging JSON exclusively, versioned in the URL path. There is no unversioned route, no XML support, no SOAP legacy, and no plaintext HTTP listener anywhere in the request path — TLS termination happens at the load balancer and the origin refuses any connection that arrives without it having come through TLS (enforced via a signed header from the load balancer, not merely by convention).

## Base URL and versioning scheme

```
Production:   https://api.qayd.com/api/v1/
Sandbox:      https://sandbox-api.qayd.com/api/v1/
```

Every resource collection and every action lives under `/api/v1/`. The version is a path segment, not a header or a query parameter, because a path segment is impossible for a client to omit by accident, is trivially cacheable and routable at the CDN/load-balancer layer without inspecting the request body, and shows up unambiguously in every log line and every piece of documentation. The full versioning and deprecation discipline is defined in **Versioning** below; this section defines the architectural style within a given version.

## Statelessness

No request depends on server-side session state. Every request carries everything required to process it: a bearer token (**Authentication**), a tenant header (`X-Company-Id`), and whatever body/query parameters the endpoint defines. This is what allows the API tier to run as a fleet of interchangeable, horizontally scaled Laravel Octane workers behind a load balancer with no sticky sessions (see **Scalability**) — any worker can serve any request, because no worker holds request-specific state that another worker would need.

## Resources, not RPC

Endpoints name **nouns** (resources) and use HTTP methods as the verbs. QAYD explicitly rejects an RPC-style API (`POST /api/v1/createInvoice`, `POST /api/v1/doPayrollRun`) in favor of resource collections and standard verbs, because the resource style is what makes the API's shape predictable to a client that has only seen the OpenAPI spec for a handful of endpoints — the ninety-first endpoint behaves like the first ninety once a caller understands the pattern.

| HTTP Method | Semantics | Idempotent? | Safe? | Typical QAYD usage |
|---|---|---|---|---|
| `GET` | Retrieve a resource or collection | Yes | Yes | `GET /api/v1/accounting/journal-entries` |
| `POST` | Create a resource, or invoke a documented state-transition action | No (unless Idempotency-Key supplied) | No | `POST /api/v1/sales/invoices` |
| `PUT` | Replace a resource's full representation | Yes | No | Rarely used; QAYD prefers `PATCH` for partial updates |
| `PATCH` | Partially update a resource | No (but safe to make idempotent via key) | No | `PATCH /api/v1/customers/{id}` |
| `DELETE` | Soft-delete (never hard-delete a financial record) | Yes | No | `DELETE /api/v1/sales/quotations/{id}` (draft only) |

State transitions that are not naturally CRUD (posting a journal entry, releasing a payroll run, reconciling a bank statement) are still modeled as `POST` against a sub-resource that names the action as a noun, not a verb: `POST /api/v1/accounting/journal-entries/{id}/postings` rather than `POST /api/v1/accounting/journal-entries/{id}/post`. This keeps the verb space limited to the five methods above and keeps the action itself inspectable and listable like any other resource (a posting has its own `id`, its own `created_by`, and its own audit trail entry).

## The request pipeline

Every request, regardless of endpoint, passes through the same ordered pipeline before a controller method runs, and the same ordered pipeline (in reverse) before a response leaves the edge:

```
Client
  │
  ▼
[1] TLS termination + WAF / DDoS filtering        (edge / Cloudflare)
  │
  ▼
[2] CORS preflight handling                        (Laravel middleware)
  │
  ▼
[3] Rate limiting (Redis token bucket)              → 429 on violation
  │
  ▼
[4] Authentication (Sanctum / JWT bearer parse)      → 401 on failure
  │
  ▼
[5] Tenant context resolution (X-Company-Id)         → 403 if not a member
  │
  ▼
[6] Authorization (RBAC permission gate)              → 403 on denial
  │
  ▼
[7] Request validation (FormRequest rules)            → 422 on failure
  │
  ▼
[8] Idempotency check (Redis, unsafe methods only)    → cached response replay
  │
  ▼
[9] Controller → Service → Repository → Model         (business logic, DB transaction)
  │
  ▼
[10] Response envelope assembly + request_id/timestamp
  │
  ▼
[11] Audit log write (if mutation) + webhook dispatch (if domain event fired)
  │
  ▼
Client
```

This pipeline is expanded endpoint-by-stage in **Request Lifecycle** below; it is introduced here because it is the architectural backbone that every other section in this document attaches to. Stages 1–8 are pure infrastructure concerns that a controller method never has to reason about — by the time a controller runs, the request is authenticated, tenant-scoped, authorized, validated, and (for unsafe methods) idempotency-checked. A controller that receives a request has already been told, structurally, that it is allowed to proceed.

## Content negotiation

QAYD accepts and returns `application/json` exclusively. `Content-Type: application/json` is required on every request body; a request with a missing or incorrect `Content-Type` on a body-bearing method is rejected with `415 Unsupported Media Type` before validation runs. `Accept-Language` (values: `en`, `ar`; default `en`) controls the language of human-readable `message` and `errors[].message` strings without changing any machine-readable field (resource field names, enum values, and `errors[].code` are always English/ASCII, since they are meant to be branched on programmatically, not displayed).

## Standard headers

| Header | Direction | Required | Purpose |
|---|---|---|---|
| `Authorization: Bearer <token>` | Request | Yes (except `/auth/*` login) | Sanctum/JWT bearer authentication |
| `X-Company-Id` | Request | Conditionally (falls back to user's default company) | Active tenant for this request |
| `Content-Type: application/json` | Request | Yes on body-bearing methods | Content negotiation |
| `Accept-Language` | Request | No (defaults to `en`) | Localizes human-readable messages |
| `Idempotency-Key` | Request | Required on financial-mutation `POST`/`PATCH` | Safe retry semantics, see **Idempotency** |
| `X-Request-Id` | Response | Always | Echoes/creates the trace id also present in `body.request_id` |
| `X-RateLimit-Limit` / `-Remaining` / `-Reset` | Response | Always | Current rate-limit window state, see **Rate Limiting** |
| `ETag` | Response | On cacheable `GET`s | Conditional-request support, see **Caching** |
| `Sunset` | Response | On deprecated routes only | Deprecation timeline, see **Versioning** |

# Resource Design

QAYD's resource model mirrors the canonical table names defined in the platform's shared design context, mapped to plural, kebab-case URL segments and snake_case JSON fields. A resource path always resolves to a specific table (or a well-defined projection of one), so an engineer who knows the database schema can predict the API shape, and vice versa.

## Naming conventions

- **URL segments**: plural, kebab-case nouns — `journal-entries`, `purchase-orders`, `stock-movements`, `bank-accounts`, never `journalEntry` or `Purchase_Order`.
- **JSON fields**: snake_case, matching the underlying column name exactly where the column is exposed directly (`company_id`, `created_at`, `invoice_number`), so there is never a translation table between "the database name" and "the API name" that documentation has to maintain separately.
- **Money fields**: serialized as JSON strings, not native numbers (`"amount": "1250.5000"`, not `"amount": 1250.5`), because IEEE-754 floating point cannot represent `NUMERIC(19,4)` exactly and QAYD refuses to let a client-side floating-point rounding error silently corrupt a financial amount in transit. Every SDK deserializes these into a language-appropriate decimal type (`BigDecimal`-equivalent), never into a native float.
- **Timestamps**: ISO-8601 in UTC with an explicit `Z` suffix (`"2026-07-16T12:00:00Z"`), never a Unix epoch integer and never a naive local time. Any display-timezone conversion happens client-side.
- **Enums**: lowercase snake_case strings (`"status": "posted"`, `"status": "pending_approval"`), never integers, because an integer enum forces every client and every piece of documentation to maintain a decoder ring that a string enum makes unnecessary.
- **Booleans**: real JSON booleans, never `"1"`/`"0"` strings or integers.
- **Identifiers**: the primary `id` returned to clients is the `BIGINT` internal identity (`"id": 48213`), used for all internal foreign-key style references within a company's own data (since it is guaranteed only unique within QAYD, not guessable across tenants in a way that matters — access is authorization-gated, not obscurity-gated). A parallel `uuid` field is present on every resource for contexts (webhooks delivered to third parties, public-facing document links, cross-system correlation) where an external-safe, non-sequential identifier is preferable; QAYD never relies on `uuid` unpredictability as a substitute for a real authorization check.

## Canonical resource table

The following are the primary resource collections exposed under `/api/v1/`. Sub-resources (line items, allocations) nest one level under their parent; QAYD does not nest more than two levels deep (see **Nesting depth** below).

| Resource | Path | Backing table(s) |
|---|---|---|
| Companies | `/api/v1/companies` | `companies` |
| Branches | `/api/v1/branches` | `branches` |
| Users | `/api/v1/users` | `users`, `company_users` |
| Roles | `/api/v1/roles` | `roles`, `permissions` |
| Chart of Accounts | `/api/v1/accounting/accounts` | `accounts`, `account_types` |
| Fiscal Periods | `/api/v1/accounting/fiscal-periods` | `fiscal_years`, `fiscal_periods` |
| Journal Entries | `/api/v1/accounting/journal-entries` | `journal_entries`, `journal_lines` |
| General Ledger | `/api/v1/accounting/ledger-entries` | `ledger_entries` (read-only projection) |
| Cost Centers | `/api/v1/accounting/cost-centers` | `cost_centers` |
| Projects | `/api/v1/accounting/projects` | `projects` |
| Customers | `/api/v1/sales/customers` | `customers`, `customer_contacts` |
| Sales Quotations | `/api/v1/sales/quotations` | `sales_quotations` |
| Sales Orders | `/api/v1/sales/orders` | `sales_orders` |
| Invoices | `/api/v1/sales/invoices` | `invoices`, `invoice_items` |
| Credit Notes | `/api/v1/sales/credit-notes` | `credit_notes` |
| Receipts | `/api/v1/sales/receipts` | `receipts` |
| Vendors | `/api/v1/purchasing/vendors` | `vendors`, `vendor_contacts` |
| Purchase Orders | `/api/v1/purchasing/purchase-orders` | `purchase_orders` |
| Bills | `/api/v1/purchasing/bills` | `bills`, `bill_items` |
| Vendor Payments | `/api/v1/purchasing/vendor-payments` | `vendor_payments` |
| Bank Accounts | `/api/v1/banking/bank-accounts` | `bank_accounts` |
| Bank Transactions | `/api/v1/banking/bank-transactions` | `bank_transactions` |
| Bank Reconciliations | `/api/v1/banking/reconciliations` | `bank_reconciliations` |
| Transfers | `/api/v1/banking/transfers` | `transfers` |
| Products | `/api/v1/inventory/products` | `products`, `product_categories` |
| Inventory Items | `/api/v1/inventory/items` | `inventory_items` |
| Stock Movements | `/api/v1/inventory/stock-movements` | `stock_movements` |
| Stock Transfers | `/api/v1/inventory/stock-transfers` | `stock_transfers` |
| Employees | `/api/v1/payroll/employees` | `employees` |
| Payroll Runs | `/api/v1/payroll/runs` | `payroll_runs`, `payroll_items` |
| Payslips | `/api/v1/payroll/payslips` | `payslips` |
| Tax Codes | `/api/v1/tax/codes` | `tax_codes`, `tax_rates` |
| Tax Returns | `/api/v1/tax/returns` | `tax_returns` |
| Report Definitions | `/api/v1/reports/definitions` | `report_definitions` |
| Report Runs | `/api/v1/reports/runs` | `report_runs` |
| Notifications | `/api/v1/notifications` | `notifications` |
| Attachments | `/api/v1/attachments` | `attachments` (polymorphic) |
| AI Conversations | `/api/v1/ai/conversations` | `ai_conversations`, `ai_messages` |
| Webhook Subscriptions | `/api/v1/webhooks` | `webhook_subscriptions`, `webhook_deliveries` |

## Nesting depth

QAYD caps resource nesting at two levels: `/api/v1/{module}/{collection}/{id}/{sub-collection}`. A third level is never introduced; instead, the sub-resource is promoted to a top-level, filterable collection. For example, invoice line items are reachable both as `GET /api/v1/sales/invoices/{id}/items` (in the context of one invoice) and as `GET /api/v1/sales/invoice-items?filter[invoice_id]=48213` (as a first-class, independently filterable/sortable collection) — the two routes return the same underlying rows, and QAYD does not force a client to choose the nested form just because a resource happens to have a parent.

## Example resource representation

A single invoice, as returned by `GET /api/v1/sales/invoices/{id}`, illustrates every convention above:

```json
{
  "success": true,
  "data": {
    "id": 48213,
    "uuid": "6a8f2e2a-1b3d-4e9a-9c2f-7d5b6a1e9f21",
    "company_id": 102,
    "branch_id": 3,
    "invoice_number": "INV-2026-004213",
    "customer_id": 5541,
    "status": "posted",
    "currency_code": "KWD",
    "exchange_rate": "1.000000",
    "subtotal": "1250.0000",
    "tax_total": "62.5000",
    "total": "1312.5000",
    "amount_paid": "0.0000",
    "amount_due": "1312.5000",
    "issue_date": "2026-07-10",
    "due_date": "2026-08-09",
    "cost_center_id": 12,
    "project_id": null,
    "tags": ["recurring", "wholesale"],
    "custom_fields": {"po_reference": "PO-9981"},
    "created_by": 77,
    "updated_by": 77,
    "created_at": "2026-07-10T08:41:02Z",
    "updated_at": "2026-07-10T08:41:55Z",
    "deleted_at": null
  },
  "message": "Invoice retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": null
  },
  "request_id": "3f1c9e8a-77b0-4b0a-9a5e-2c1f0d7e6b1a",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

## Relationship expansion

By default, a resource response includes only its own scalar fields and foreign-key ids (`customer_id`, not an embedded `customer` object), to keep default payloads small and predictable in size (see **Performance**). A caller that needs related resources inlined uses `?expand=`, a whitelisted, per-resource parameter: `GET /api/v1/sales/invoices/{id}?expand=customer,items` returns the same envelope with `data.customer` and `data.items` populated as nested objects. `expand` targets are documented per resource in that resource's module doc and validated against a whitelist — an unrecognized expand target returns `422` naming the invalid value, never a silent no-op, per the **API Philosophy** principle that nothing fails silently.

## Soft-deleted resources

Because financial records are never hard-deleted (`DATABASE_ARCHITECTURE`, `DATABASE_SOFT_DELETE`), every list and detail endpoint excludes soft-deleted rows (`deleted_at IS NOT NULL`) by default. A caller with the relevant `*.read.deleted` permission may pass `?include_deleted=true` to see them; without that permission the parameter is silently ignored rather than erroring, because its absence has no security implication (it simply narrows what would otherwise be returned) — this is the one place QAYD prefers a permissive default over an explicit error, since under-fetching is safe and the alternative (erroring on every accidental use of the parameter by a caller without the permission) would be needlessly hostile to well-behaved clients.

# Request Lifecycle

Every request that reaches a QAYD controller has already passed through eight ordered stages. This section specifies each stage precisely: what it checks, what it attaches to the request for downstream stages, and exactly which error it produces on failure. The specificity here matters because "authenticate, authorize, validate" sounds simple until an engineer has to answer, precisely, what happens when a request is authenticated but the `X-Company-Id` header names a company the token holder does not belong to (answer: `403`, not `401` — the caller proved who they are; they simply may not act as that tenant) — this section is the reference for exactly that class of question.

## Stage 1 — Authentication

Handled by `Illuminate\Auth\Middleware\Authenticate` composed with Sanctum's guard. The bearer token is parsed from the `Authorization` header, verified (signature and expiry for JWT-style tokens; hash lookup for Sanctum personal access tokens), and resolved to a `User` model. Failure at this stage — missing header, malformed token, expired token, revoked token — always returns `401 Unauthorized` with `error.code = "authentication_required"` or `"token_expired"`, before any other stage runs. No information about the requested resource, tenant, or permission is ever leaked to an unauthenticated caller.

## Stage 2 — Tenant context resolution

Handled by `SetTenantContext` middleware, which runs immediately after authentication:

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
        app()->instance('tenant.branch_id', $request->header('X-Branch-Id'));

        // Propagates to PostgreSQL RLS via a session-local GUC — see DATABASE_ROW_LEVEL_SECURITY.
        DB::statement('SET LOCAL app.current_company_id = ?', [$companyId]);

        return $next($request);
    }
}
```

The resolved `company_id` is bound into the container so every Eloquent global scope, every service class, and every downstream authorization check reads it from one place, and it is additionally set as a PostgreSQL session variable so Row Level Security enforces the same scoping at the database layer independent of whether every query author remembered a `->where('company_id', ...)` clause. A token that is valid but for a user who is not a member of the requested company produces `403 Forbidden` with `error.code = "company_access_denied"` — never `404`, because revealing "this company doesn't exist" versus "you can't access this company" through a status-code difference would leak tenant existence to a token holder who has no relationship to that tenant, so QAYD always uses `403` for this case, whether the company genuinely doesn't exist or simply isn't one the caller belongs to.

## Stage 3 — Authorization

Handled by a `PermissionGate` middleware (route-level) backed by Laravel Policy classes (model-level, for object-specific checks like "can this user void *this specific* invoice, which belongs to a branch they're not assigned to"). Every route declares the permission key it requires as route metadata:

```php
Route::post('/accounting/journal-entries/{journalEntry}/postings', [JournalPostingController::class, 'store'])
    ->middleware('permission:accounting.journal.post');
```

`Gate::before` returns `false` for any ability the active role's permission set does not explicitly contain — default-deny, per **API Philosophy** principle 4. Failure here returns `403 Forbidden` with `error.code = "permission_denied"` and, in the `errors[]` array, the specific permission key that was missing (`accounting.journal.post`), which is safe to disclose (permission key names are not secrets) and dramatically speeds up integration debugging versus a bare "forbidden." Full detail in **Authorization** below.

## Stage 4 — Request validation

Handled by a Laravel `FormRequest` class per endpoint, which defines both the authorization pass (a redundant, defense-in-depth check specific to the payload — e.g., "the `account_id` in this journal line must belong to the active company's chart of accounts," which a route-level permission check cannot express) and the validation rules (types, required fields, format, referential existence). Failure returns `422 Unprocessable Entity` with every failing field populated in `errors[]` in a single response — QAYD never makes a client fix one field, resubmit, and discover a second field error it could have been told about the first time. Full detail in **Error Handling** below.

## Stage 5 — Idempotency check

For `POST`/`PATCH`/`DELETE` on endpoints tagged as financial-mutating, an `IdempotencyMiddleware` checks Redis for a prior response recorded under the caller's `Idempotency-Key`. A hit short-circuits the pipeline entirely and replays the original response (including its original HTTP status code) without re-executing Stage 6. A miss proceeds to Stage 6 and, after a successful response, records the response under that key with a 24-hour TTL. Full detail in **Idempotency** below.

## Stage 6 — Execution (Controller → Service → Repository → Model)

The controller method is now guaranteed to be running with an authenticated user, a resolved and authorized tenant, a validated request, and (where applicable) a fresh idempotency slot. The controller's only job is to translate the HTTP request into a call on a Service class; it contains no business logic itself. The Service class wraps its work in a database transaction (`DB::transaction(...)`), calls one or more Repository classes for persistence, and — for anything that produces a domain event — dispatches that event after the transaction commits (via `DB::afterCommit`, never before, so a webhook is never fired for a write that then rolls back).

```php
// app/Services/Accounting/JournalPostingService.php
public function post(JournalEntry $entry, User $actor): JournalEntry
{
    return DB::transaction(function () use ($entry, $actor) {
        $this->assertBalanced($entry);        // SUM(debits) === SUM(credits)
        $this->assertPeriodOpen($entry->fiscal_period_id);

        $entry->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $actor->id]);
        $this->ledgerRepository->materialize($entry);  // projects into ledger_entries
        $this->auditLogger->record($entry, $actor, 'journal.posted');

        DB::afterCommit(fn () => event(new JournalPosted($entry)));

        return $entry;
    });
}
```

## Stage 7 — Response assembly

A `ResponseFactory` (a thin, shared helper every controller calls rather than constructing arrays ad hoc) builds the standard envelope, attaching a freshly generated `request_id` (a UUIDv4, generated once at Stage 1 and threaded through the entire request via the container, so it is the *same* id in the response body, in the `X-Request-Id` header, and in every log line for this request) and the current UTC `timestamp`. This is the only place in the codebase permitted to construct a top-level API response, which is what guarantees the envelope never drifts per-endpoint.

## Stage 8 — Audit, webhook dispatch, and cache invalidation

If the request mutated data, an `audit_logs` row is written (actor, IP, device fingerprint, before/after diff) synchronously within the same transaction as the mutation (so an audit entry can never be "lost" relative to the write it describes), and any domain event queued in Stage 6 is now dispatched to the webhook delivery system (**Webhooks**) and to the cache invalidation listeners (**Caching**) asynchronously via a queued job, so the client's response is never held up waiting for webhook delivery to third parties.

# Authentication

QAYD authenticates every API caller — human users on web and mobile, and the FastAPI AI engine as an internal service actor — using **Laravel Sanctum** issuing **JWT-format bearer tokens**. There is no cookie-session authentication path for the API itself (Sanctum's SPA cookie mode is used only for Laravel-rendered first-party pages that exist outside `/api/v1/`, such as the billing portal); every `/api/v1/` request authenticates via `Authorization: Bearer <token>`.

## Token issuance flow

```
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "cfo@acme-holdings.com",
  "password": "••••••••••••"
}
```

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI3NyIsImNvbXBhbnlfaWQiOiIxMDIiLCJyb2xlcyI6WyJjZm8iXSwiaWF0IjoxNzUyNjQwODAwLCJleHAiOjE3NTI2NDQ0MDB9.signature",
    "refresh_token": "d41f1c2e9b7a4e6f8c0d2a1b3e5f7091.rft",
    "token_type": "Bearer",
    "expires_in": 3600,
    "user": {
      "id": 77,
      "name": "Fahad Al-Sabah",
      "email": "cfo@acme-holdings.com",
      "default_company_id": 102,
      "companies": [{"id": 102, "name": "Acme Holdings W.L.L.", "role": "cfo"}],
      "mfa_required": true,
      "mfa_verified": false
    }
  },
  "message": "Login successful. MFA verification required before sensitive operations.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "9c2e4b1a-7f3d-4e8a-b1c9-3d7e5f8a2b4c",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

The JWT's claims (`sub` = user id, `company_id` = default company at issuance time, `roles`, `iat`, `exp`) are informational and used for fast, stateless request routing (e.g., an API gateway can extract `company_id` without a database round-trip for coarse-grained rate limiting), but they are never trusted for actual authorization — every authorization decision (**Authorization** below) re-reads the user's live role/permission assignment and live company membership from the database on every request, since a JWT claim reflects the state at issuance and a permission revoked mid-session must take effect immediately, not after token expiry.

## Endpoint table

| Method | Path | Permission | Description |
|---|---|---|---|
| `POST` | `/api/v1/auth/login` | None (public) | Exchange email/password for an access + refresh token pair |
| `POST` | `/api/v1/auth/refresh` | Valid refresh token | Exchange a refresh token for a new access token |
| `POST` | `/api/v1/auth/logout` | Authenticated | Revoke the current access token (and optionally all tokens for the device) |
| `POST` | `/api/v1/auth/logout-all` | Authenticated | Revoke every active token for the user across all devices |
| `POST` | `/api/v1/auth/mfa/challenge` | Authenticated | Trigger an MFA one-time-code challenge (SMS/TOTP) |
| `POST` | `/api/v1/auth/mfa/verify` | Authenticated | Verify the MFA code and upgrade the session to `mfa_verified` |
| `GET` | `/api/v1/auth/me` | Authenticated | Return the current user, their companies, and active permissions |
| `POST` | `/api/v1/auth/password/forgot` | None (public) | Send a password-reset email |
| `POST` | `/api/v1/auth/password/reset` | Valid reset token | Set a new password |

## Access vs. refresh tokens

Access tokens are short-lived (**1 hour**, `expires_in: 3600`) and are the only token type ever attached to an outbound API request. Refresh tokens are long-lived (**30 days**, sliding — each use resets the window), stored hashed in the `personal_access_tokens` table, and are usable exactly once each (rotated on every refresh; the previous refresh token is immediately invalidated, so a leaked, already-used refresh token is worthless, and a refresh token used twice — a strong signal of token theft — immediately revokes the entire token family and forces re-authentication). The short access-token lifetime bounds the damage window of a leaked access token without forcing a user to re-enter a password every hour; the mobile Flutter app and the Next.js web app both silently refresh in the background before expiry.

## Multi-factor authentication

MFA is mandatory for the `owner`, `ceo`, `cfo`, and `finance_manager` roles, and mandatory for any user attempting a sensitive operation (bank transfer approval, payroll release, tax submission, permission changes) regardless of their base role. A session that has authenticated but not yet completed MFA (`mfa_verified: false`) can read non-sensitive resources but is rejected with `403` and `error.code = "mfa_required"` on any endpoint tagged sensitive, independent of whether the underlying permission check would otherwise pass — MFA is an additional gate stacked on top of, not instead of, RBAC.

## Service-to-service authentication (the AI engine)

The FastAPI/Python AI engine authenticates as a first-class, non-human `User` record of type `service_account`, issued a long-lived service token via a separate, operator-only endpoint (`POST /api/v1/auth/service-tokens`, requiring `owner`-level permission to mint) rather than the login flow above, and additionally presents a mutual-TLS client certificate at the network layer (verified by the load balancer before the request ever reaches Laravel) as a second factor appropriate to a machine caller that cannot complete an SMS/TOTP challenge. The AI service account is a member of every company it needs to act within (mirroring real company memberships, never a cross-tenant superuser), and its role is deliberately scoped — see **AI API Access** — so that authenticating successfully as "the AI" never implies broader access than a human with the AI's assigned role would have.

## Token storage guidance (client-side)

The Flutter mobile app stores tokens in platform secure storage (Keychain on iOS, EncryptedSharedPreferences/Keystore-backed on Android), never in plain SharedPreferences or plist files. The Next.js web app stores the access token in memory only (never `localStorage`, to limit XSS exfiltration blast radius) and relies on a same-site, `httpOnly` cookie holding only an opaque session-linking value for refresh continuity across page reloads — the refresh token itself is never placed in a location JavaScript can read. Full treatment of transport and storage security is in **Security**.

# Authorization

Authorization in QAYD is role-based access control (RBAC) with a **default-deny** posture: a permission not explicitly granted to a role is denied, full stop, with no fallback to "allow unless denied." This section defines the permission model, the default role catalog, and the elevated approval-chain mechanism required for financial actions with irreversible real-world consequences.

## Permission key format

Every permission is a dotted string `<area>.<action>` or, for entity-specific actions, `<area>.<entity>.<action>`:

```
accounting.read
accounting.journal.create
accounting.journal.post
accounting.journal.void
bank.read
bank.reconcile
bank.transfer
payroll.read
payroll.calculate
payroll.approve
payroll.release
inventory.read
inventory.adjust
tax.read
tax.submit
reports.read
reports.export
users.manage
roles.manage
company.settings.manage
```

Every route in the platform declares exactly the permission keys it requires (frequently more than one, when an action spans domains — e.g., posting a sales invoice's journal entry requires both `sales.invoice.post` and `accounting.journal.create`), and the full key catalog is generated automatically from route metadata into a machine-readable list consumed by the role-management UI, so the set of permission keys that exist in the system and the set of permission keys a route actually enforces can never silently drift apart.

## Default role catalog

QAYD ships default roles as a starting point every company can customize; a company may clone, rename, and re-scope any default role's permission set, but cannot remove the underlying permission-key catalog itself (a company can decide *who* holds `payroll.release`, never invent a new meaning for it).

| Role | Typical permission scope |
|---|---|
| `owner` | All permissions, all modules, including `roles.manage` and `company.settings.manage` |
| `ceo` | All read + approve permissions; execution delegated to functional roles |
| `cfo` | All accounting/banking/payroll/tax read + approve + release permissions |
| `finance_manager` | Accounting + banking read/write, journal posting, no payroll release |
| `senior_accountant` | Journal entry creation, period-close participation, no posting/void |
| `accountant` | Journal entry drafting, invoice/bill entry, no posting |
| `auditor` | Read-only across all modules, including historical/void audit trail |
| `hr_manager` | Employee records, payroll configuration, no payroll release |
| `payroll_officer` | Payroll calculation, payslip generation, no release |
| `inventory_manager` | Full inventory read/write/adjust |
| `warehouse_employee` | Stock movement entry (receive/issue), no adjustment/write-off |
| `sales_manager` | Full sales cycle, discount approval up to a configured ceiling |
| `sales_employee` | Quotation/order/invoice drafting, no discount override |
| `purchasing_manager` | Full purchasing cycle, vendor payment initiation |
| `purchasing_employee` | Purchase requisition/order drafting, no payment initiation |
| `read_only` | Read across modules the user is explicitly granted, no writes anywhere |
| `external_auditor` | Time-boxed, read-only, typically scoped to one fiscal year and no PII fields |

## Permission check flow

```php
// app/Providers/AuthServiceProvider.php
Gate::before(function (User $user, string $ability) {
    if (!$user->hasPermissionForActiveCompany($ability)) {
        return false;   // default-deny: absence of a grant is an explicit denial
    }
    return null;        // defer to the specific Policy method below, if one exists
});
```

Route-level checks (via the `permission:` middleware) cover "can this user perform this *class* of action at all." Policy-level checks (Laravel `Policy` classes, invoked with `$this->authorize('update', $invoice)`) cover "can this user perform this action on *this specific object*" — for example, a `sales_employee` may hold `sales.invoice.update` generally but a Policy still rejects updating an invoice belonging to a branch the user is not assigned to, or an invoice already in `posted` status (a business-rule-shaped denial that returns `409 Conflict`, not `403`, because the caller *is* authorized in principle — the object's current state is what makes the action invalid, see **Error Handling**).

## Sensitive operations and human approval chains

Certain actions are never permitted to execute in a single step, regardless of the caller's permission set, because their real-world consequences (money leaving the company, an employee's pay being finalized, a legal filing being submitted to a tax authority) are difficult or impossible to reverse:

| Sensitive action | Initiating permission | Approving permission | Endpoint pattern |
|---|---|---|---|
| Bank transfer | `bank.transfer.create` | `bank.transfer.approve` | `POST .../transfers` then `POST .../transfers/{id}/approvals` |
| Payroll release | `payroll.calculate` | `payroll.release` | `POST .../runs/{id}/calculations` then `POST .../runs/{id}/releases` |
| Tax submission | `tax.return.prepare` | `tax.submit` | `POST .../returns/{id}/preparations` then `POST .../returns/{id}/submissions` |
| Journal void | `accounting.journal.void.request` | `accounting.journal.void.approve` | `POST .../journal-entries/{id}/void-requests` then `POST .../void-requests/{id}/approvals` |
| Permission/role change | `roles.manage` | `owner`-only | `PATCH /api/v1/roles/{id}` |

The pattern is consistent: the initiating call creates a resource in a `pending_approval` state (never executes the effect immediately), and a **separate** call, by a **separate** permission key — which the initiator is structurally prevented from also holding for the same request via a same-actor check (`abort_if($approval->requested_by === $actor->id, 403, 'self_approval_forbidden')`) — transitions it to `approved` and only then triggers execution. This is enforced identically whether the initiator is a human or the AI engine; the AI engine is never granted an `*.approve`/`*.release`/`*.submit` permission for any action in this table, by policy, independent of what role it is otherwise assigned (see **AI API Access**).

## Example: permission-denied response

```
POST /api/v1/payroll/runs/8841/releases
Authorization: Bearer <token for a payroll_officer>
X-Company-Id: 102
```

```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to release payroll runs.",
  "errors": [
    {
      "code": "permission_denied",
      "field": null,
      "message": "Missing required permission: payroll.release",
      "meta": {"required_permission": "payroll.release", "role": "payroll_officer"}
    }
  ],
  "meta": {"pagination": null},
  "request_id": "1a2b3c4d-5e6f-4789-8a9b-0c1d2e3f4a5b",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

# Rate Limiting

Rate limiting protects the platform against three distinct failure modes — a misbehaving client stuck in a retry loop, a compromised credential being used to scrape data, and an AI agent's orchestration bug fanning out thousands of calls — and QAYD treats these as an infrastructure concern implemented once, centrally, rather than a per-endpoint afterthought.

## Mechanism

Rate limiting is enforced by a Redis-backed **sliding-window counter**, chosen over a fixed-window counter because a fixed window allows a client to burst up to 2x its nominal limit across a window boundary (e.g., all of its budget at 11:59:59 and all of its budget again at 12:00:00), which a sliding window does not permit. Each authenticated actor (`user_id` for humans, `service_account_id` for the AI engine) plus the active `company_id` forms the rate-limit key; unauthenticated endpoints (`/auth/login`, `/auth/password/forgot`) are limited by source IP instead, since there is no authenticated identity yet to key on.

```php
// app/Http/Middleware/ThrottleByTier.php — conceptual shape
$key = $request->user()
    ? "ratelimit:{$request->user()->id}:{$tenantCompanyId}"
    : "ratelimit:ip:{$request->ip()}";

$limiter = RateLimiter::for('api', function (Request $request) use ($key) {
    $tier = $request->user()?->activeCompany()?->plan_tier ?? 'unauthenticated';
    return Limit::perMinute(self::LIMITS[$tier])->by($key);
});
```

## Limits by plan tier and endpoint class

| Tier / class | Sustained limit | Burst limit | Window |
|---|---|---|---|
| Unauthenticated (`/auth/*`) | 10 req/min | 20 req/10s | Sliding, per IP |
| Starter plan | 120 req/min | 20 req/s | Sliding, per user+company |
| Growth plan | 300 req/min | 40 req/s | Sliding, per user+company |
| Enterprise plan | 1,000 req/min | 100 req/s | Sliding, per user+company |
| AI engine (per company) | 600 req/min | 60 req/s | Sliding, separate bucket from human traffic |
| Reporting endpoints (`/reports/*`) | 20 req/min | 5 req/s | Separate, lower bucket — reports are I/O-heavy |
| Webhook management (`/webhooks/*`) | 60 req/min | 10 req/s | Separate bucket |

The AI engine's traffic is deliberately isolated into its own bucket, keyed by `service_account_id` + `company_id` rather than sharing the human users' bucket for that company, so a burst of legitimate AI activity (e.g., the Document AI agent OCR-processing forty vendor bills uploaded at once) never exhausts the budget a human accountant needs to keep working in the same company at the same time, and vice versa — a runaway AI loop cannot lock a human out of their own account.

## Response headers and the 429 envelope

Every response, successful or not, carries the current window state:

```
X-RateLimit-Limit: 300
X-RateLimit-Remaining: 287
X-RateLimit-Reset: 1752641460
```

A request that exceeds its limit is rejected before Stage 4 (validation) even runs — rate limiting sits at Stage 3 of the pipeline in **Request Lifecycle**, ahead of authentication in the pure-IP-keyed unauthenticated case and immediately after it otherwise — and returns:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 42
X-RateLimit-Limit: 300
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1752641460
```

```json
{
  "success": false,
  "data": null,
  "message": "Rate limit exceeded. Please retry after the indicated delay.",
  "errors": [
    {"code": "rate_limit_exceeded", "field": null, "message": "Limit of 300 requests/minute exceeded for this account.", "meta": {"retry_after_seconds": 42}}
  ],
  "meta": {"pagination": null},
  "request_id": "7e2d1c0b-9a8f-4e3d-b2c1-5f6a7b8c9d0e",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

Rate-limit violations are logged (see **Logging**) and a sustained pattern of 429s from a single actor over a rolling 24-hour window raises an operational alert (see **Monitoring**) for the security team to review, since a legitimate integration hitting its ceiling repeatedly usually means either a bug in that integration's retry logic or a signal worth investigating.

# Caching

QAYD caches at the API layer to reduce database load for read-heavy, slow-changing resources, while guaranteeing that anything derived from the ledger is never served stale in a way that could mislead a financial decision.

## What is cached, and what is never cached

| Cacheable (Redis, TTL-bound) | Never cached (always live) |
|---|---|
| Chart of accounts tree | Account balances / trial balance figures |
| Price lists, tax rate tables | Invoice/bill `amount_due`, payment status |
| Product catalog, unit-of-measure conversions | Bank reconciliation status |
| Company settings, role/permission definitions | Payroll run status, approval-chain state |
| Report *definitions* (the template/config) | Report *run results* (see **Idempotency**/async pattern) |

The dividing line is precise: anything that represents a **balance, a status, or money owed or paid** is fetched live on every request, because a cache serving a stale `amount_due` after a payment was just recorded is not a performance optimization, it is a customer being told a wrong number about their own money. Anything that is **reference/configuration data** — changes rarely, and being a few seconds stale has no financial consequence — is a caching candidate.

## Cache key scheme and invalidation

```
cache:v1:{company_id}:chart-of-accounts:tree
cache:v1:{company_id}:products:catalog:page:{page}:filters:{filters_hash}
cache:v1:{company_id}:tax-rates:active
cache:v1:global:currency-rates:{date}
```

Every cache key is namespaced by `company_id` (or explicitly `global` for genuinely tenant-independent data like ISO currency exchange rates), so a cache flush or a key collision can never leak Company A's cached data into Company B's response — this mirrors the tenant-isolation discipline of the database layer at the caching layer. Invalidation is **event-driven, not merely TTL-based**: a `product.updated` domain event fires a cache-bust listener that deletes `cache:v1:{company_id}:products:catalog:*` immediately, so a product price change is visible on the very next request rather than waiting out a TTL. The TTL that also exists on every cached key (typically 5–60 minutes depending on resource) is a safety net against a missed invalidation event, not the primary invalidation mechanism.

## Conditional requests (ETag)

Cacheable `GET` endpoints return an `ETag` computed from the resource's `updated_at` plus a content hash; a client that sends `If-None-Match` with a matching value receives `304 Not Modified` with an empty body, saving bandwidth on polling clients (the Flutter app refreshing a product catalog on app foreground, for instance):

```
GET /api/v1/inventory/products?page=1
If-None-Match: "a1b2c3d4e5f6-102"

HTTP/1.1 304 Not Modified
ETag: "a1b2c3d4e5f6-102"
X-RateLimit-Remaining: 286
```

## Cache stampede protection

High-traffic cache keys (the product catalog for a large retail company, the tax rate table during month-end close when every report job reads it near-simultaneously) use a short-lived Redis lock plus jittered TTLs (`base_ttl + random(0, base_ttl * 0.1)`) so that when a cache key does expire, one request rebuilds it while concurrent requests either wait briefly on the lock or serve the just-expired value for a bounded grace period, rather than all of them hitting PostgreSQL simultaneously the instant a hot key expires.

# Idempotency

Financial APIs fail on retries far more often than they fail on the original call: a mobile client loses signal after sending a payment but before receiving the response, a webhook consumer's at-least-once delivery redelivers a request its receiver already processed, or an AI orchestration layer times out and retries a call that actually succeeded server-side. QAYD makes every financial-mutation endpoint safe to retry by contract, not by hope.

## The Idempotency-Key header

Every `POST` and `PATCH` that creates or mutates a financial event **requires** an `Idempotency-Key` header — its absence on a tagged endpoint is itself a `422` validation failure (`error.code = "idempotency_key_required"`), because an endpoint that moves money must never be callable in a way that silently permits an accidental duplicate. The key is a client-generated UUID (or any string up to 255 characters unique to that logical operation) that the client persists alongside its own record of "I attempted this action," so a retry after a timeout reuses the exact same key.

```
POST /api/v1/banking/transfers
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
Content-Type: application/json
Authorization: Bearer <token>
X-Company-Id: 102

{
  "from_bank_account_id": 44,
  "to_bank_account_id": 51,
  "amount": "5000.0000",
  "currency_code": "KWD",
  "memo": "Intercompany transfer — Q3 working capital"
}
```

## Server-side behavior

On first receipt of a given `Idempotency-Key` for a given `company_id`, QAYD executes the request normally, then stores the **full response** (status code + envelope body) in Redis keyed by `idempotency:{company_id}:{key}`, with a **24-hour TTL**. Any subsequent request bearing the same key within that window — regardless of how many times it arrives — short-circuits at Stage 5 of the pipeline (**Request Lifecycle**) and replays the stored response verbatim, without re-executing the underlying service logic, so a retried transfer is never executed twice.

```php
// app/Http/Middleware/IdempotencyMiddleware.php — conceptual shape
$cacheKey = "idempotency:{$companyId}:{$idempotencyKey}";

if ($cached = Redis::get($cacheKey)) {
    return response($cached['body'], $cached['status'])->withHeaders($cached['headers']);
}

// Acquire a short lock to serialize concurrent requests with the same key
$lock = Cache::lock("idempotency-lock:{$cacheKey}", 10);
if (!$lock->get()) {
    abort(409, 'A request with this idempotency key is already in progress.');
}

try {
    $response = $next($request);
    if ($response->isSuccessful()) {
        Redis::setex($cacheKey, 86400, ['status' => $response->status(), 'body' => $response->getContent(), 'headers' => $response->headers->all()]);
    }
    return $response;
} finally {
    $lock->release();
}
```

## Key reuse with a different payload

If a caller reuses an `Idempotency-Key` already recorded, but with a request body that differs from the original (a strong signal of a client bug — reusing a key for a *different* logical operation), QAYD rejects the request with `409 Conflict` and `error.code = "idempotency_key_conflict"` rather than either silently executing the new payload or silently replaying the old response for a request the caller may believe is different:

```json
{
  "success": false,
  "data": null,
  "message": "This idempotency key was already used with a different request body.",
  "errors": [{"code": "idempotency_key_conflict", "field": "Idempotency-Key", "message": "Key 550e8400-e29b-41d4-a716-446655440000 is bound to a prior request with a different payload hash.", "meta": null}],
  "meta": {"pagination": null},
  "request_id": "2b3c4d5e-6f70-4891-9a0b-1c2d3e4f5a6b",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

## Endpoints that require an idempotency key

| Endpoint | Reason |
|---|---|
| `POST /api/v1/banking/transfers` | Moves real money between accounts |
| `POST /api/v1/purchasing/vendor-payments` | Moves real money to a vendor |
| `POST /api/v1/payroll/runs/{id}/releases` | Finalizes employee pay, triggers bank disbursement |
| `POST /api/v1/accounting/journal-entries/{id}/postings` | Creates immutable ledger rows |
| `POST /api/v1/tax/returns/{id}/submissions` | Files with an external tax authority |
| `POST /api/v1/sales/receipts` | Records money received against a customer |
| `PATCH` on any resource in a terminal-state transition (void, cancel, reverse) | Prevents double-reversal |

Endpoints not in this table (drafting a quotation, updating a customer's phone number, listing invoices) do not require the header, since retrying them safely is already guaranteed by their nature (a `GET`) or their consequence of a duplicate is a harmless, correctable duplicate draft rather than a financial event.

# Pagination

Every collection endpoint (`GET` against a resource collection) is paginated; QAYD never returns an unbounded list, both for performance (**Performance**) and because "return every row" is not a coherent contract once a company has millions of `journal_lines`.

## Offset-style pagination (default)

Most collections default to page-number pagination, appropriate for UI-driven browsing where a user wants to jump to "page 4":

```
GET /api/v1/sales/invoices?page=2&per_page=25
```

```json
{
  "success": true,
  "data": [ /* 25 invoice objects */ ],
  "message": "Invoices retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": {"page": 2, "per_page": 25, "total": 812, "cursor": null}
  },
  "request_id": "4c5d6e7f-8091-4a2b-9c3d-4e5f6a7b8c9d",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

`per_page` defaults to **25** and is capped at **100**; a request for `per_page=500` is not an error but is silently clamped to 100 (documented, predictable clamping, not a rejection, since the caller's intent — "give me more per page" — is still honored as far as the platform allows). `page` and `per_page` are always integers; a non-numeric value is a `422`.

## Cursor-style pagination (high-volume, time-ordered resources)

Resources that grow unbounded and are typically consumed in one directional sweep — `journal_lines`, `ledger_entries`, `audit_logs`, `stock_movements`, `webhook_deliveries` — additionally support cursor pagination, which QAYD prefers for these because offset pagination on a fast-growing table causes rows to shift between pages mid-scroll (a row inserted on page 1 pushes what was page-2's first row back onto page 1, so a naive page-by-page sweep can skip or duplicate rows):

```
GET /api/v1/accounting/ledger-entries?cursor=eyJpZCI6NDQyMTB9&per_page=50
```

```json
{
  "success": true,
  "data": [ /* 50 ledger entry objects, ordered by id ascending */ ],
  "message": "Ledger entries retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": {"page": null, "per_page": 50, "total": null, "cursor": "eyJpZCI6NDQyNjB9"}
  },
  "request_id": "5d6e7f80-9142-4b3c-8d4e-5f6a7b8c9d0e",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

The returned `cursor` is an opaque, base64-encoded pointer (internally, the last row's `id` — QAYD uses `BIGINT` identity keys specifically so that "ascending id" and "insertion order" always agree, per `DATABASE_ARCHITECTURE`) that the client passes back verbatim to fetch the next page; QAYD does not document or guarantee the cursor's internal encoding, so a client must treat it as opaque and never attempt to construct or parse one itself. `total` is intentionally `null` on cursor-paginated responses, because computing an exact count on a table with hundreds of millions of rows on every page request is prohibitively expensive and is precisely the kind of query cursor pagination exists to avoid; a caller that needs an approximate total uses the resource's dedicated `/summary` or `/count` endpoint, which is cached (**Caching**) and explicitly documented as approximate where relevant.

## Endpoint-level defaults

| Resource class | Default style | Default `per_page` | Max `per_page` |
|---|---|---|---|
| Master data (customers, vendors, products) | Offset | 25 | 100 |
| Transactional documents (invoices, bills, orders) | Offset | 25 | 100 |
| High-volume ledger data (ledger-entries, journal-lines) | Cursor (offset also available, capped lower) | 50 | 200 |
| Audit/history (audit-logs, webhook-deliveries) | Cursor only | 50 | 200 |

# Filtering

Every collection endpoint supports a whitelisted set of filters via the `filter[field]` query parameter convention, chosen over a free-form query language (SQL-like or Mongo-like operators in the query string) because a whitelist is what makes it possible to guarantee that every filterable field is indexed (see `DATABASE_INDEXING`) and that no filter can be constructed that forces a full table scan across a large tenant's data.

## Syntax

```
GET /api/v1/sales/invoices?filter[status]=posted
GET /api/v1/sales/invoices?filter[customer_id]=5541
GET /api/v1/sales/invoices?filter[issue_date][gte]=2026-01-01&filter[issue_date][lte]=2026-03-31
GET /api/v1/sales/invoices?filter[total][gte]=1000
GET /api/v1/sales/invoices?filter[status][in]=posted,partially_paid
```

## Supported operators

| Operator | Meaning | Applicable field types |
|---|---|---|
| `filter[field]=value` | Equals (implicit) | string, enum, integer, boolean |
| `filter[field][in]=a,b,c` | One of a set | string, enum, integer |
| `filter[field][gte]` / `[lte]` / `[gt]` / `[lt]` | Range comparison | numeric, date, datetime |
| `filter[field][null]=true` | Field IS NULL | any nullable field |
| `filter[field][not]=value` | Not equal | string, enum, integer |

Each resource's module document lists the exact fields it exposes as filterable (never every column — join-only or internal columns are not filter targets), and an unrecognized filter key is rejected with `422` (`error.code = "invalid_filter_field"`) naming the offending key, rather than being silently ignored, because a silently ignored filter risks a caller believing a result set is narrowed when it is not — a especially dangerous failure mode for anything feeding a financial report.

## Full-text search

Resources with a meaningful "search by name/number" use case additionally support `q=`, backed by PostgreSQL's `pg_trgm`-indexed `ILIKE`/similarity search (`DATABASE_ARCHITECTURE`) rather than a naive unindexed `LIKE '%...%'`:

```
GET /api/v1/sales/customers?q=al-sabah
GET /api/v1/sales/invoices?q=INV-2026-004
```

`q` combines with `filter[...]` conjunctively (`AND`), never replacing structured filters — a caller narrowing by both `filter[status]=posted` and `q=acme` receives posted invoices whose searchable fields match "acme," not an OR of the two.

# Sorting

Collections support a single `sort` parameter accepting a comma-separated list of fields, each optionally prefixed with `-` for descending order:

```
GET /api/v1/sales/invoices?sort=-issue_date
GET /api/v1/sales/invoices?sort=customer_id,-total
```

## Rules

- Sortable fields are whitelisted per resource, exactly like filterable fields, and for the same reason (guaranteed index coverage — every sortable field either has a direct index or is covered by a composite index alongside the mandatory `company_id` scope). An unrecognized sort field returns `422` (`error.code = "invalid_sort_field"`).
- A stable secondary sort (`id ASC`) is always appended internally after whatever the caller requests, so that two rows with an identical value on the caller's chosen sort field still resolve to a deterministic order — this matters most for cursor pagination, where a non-deterministic sort would make "the next page" an ill-defined concept.
- Default sort, when a caller supplies none, is documented per resource and is chosen to match the most common use case (`-created_at` for transactional documents like invoices and bills, so the newest appears first; `code ASC` for the chart of accounts, since accountants expect it in account-number order).
- Sorting by a computed/aggregate field (e.g., an invoice's `amount_due`, which is derived rather than stored) is supported only where the module document explicitly names it as sortable and confirms it is backed by an indexed generated column or a materialized projection — QAYD does not sort by an on-the-fly computed expression across an unbounded result set, since that forces a full scan-and-compute regardless of any index.

# Error Handling

Every error QAYD's API returns is machine-actionable: a client can branch on `errors[].code` without parsing `message` text, and a human support engineer can read `message` without needing the code table memorized. This section is the authoritative reference for that dual contract.

## HTTP status code table

| Status | Meaning in QAYD | Example trigger |
|---|---|---|
| `400 Bad Request` | Malformed request the server cannot even parse/route | Invalid JSON body, unsupported `Content-Type` |
| `401 Unauthorized` | No valid authentication present | Missing/expired/malformed bearer token |
| `403 Forbidden` | Authenticated, but not permitted | Missing permission key; MFA required and not completed; wrong company |
| `404 Not Found` | No such resource (that the caller could distinguish from "no permission to know") | `GET /api/v1/sales/invoices/999999` where 999999 does not exist in the caller's company |
| `409 Conflict` | The request is well-formed and permitted, but the resource's current state forbids it | Voiding an already-voided journal entry; reusing an idempotency key with a different body |
| `422 Unprocessable Entity` | Validation failure on the request body/params | Missing required field, invalid enum value, unbalanced journal entry |
| `429 Too Many Requests` | Rate limit exceeded | See **Rate Limiting** |
| `500 Internal Server Error` | Unhandled server-side fault | A bug; always paged to on-call, never expected to reach a client under normal operation |

## The `errors[]` array

`422` and other multi-fault responses populate `errors[]` with **one entry per distinct problem**, never truncating to "the first error found," so a client can present every fix a user needs to make in one round trip:

```
POST /api/v1/sales/invoices
Content-Type: application/json
Authorization: Bearer <token>
X-Company-Id: 102

{
  "customer_id": 5541,
  "issue_date": "2026-07-10",
  "items": [
    {"product_id": 991, "quantity": -3, "unit_price": "125.0000"}
  ]
}
```

```json
{
  "success": false,
  "data": null,
  "message": "The invoice could not be created due to validation errors.",
  "errors": [
    {"code": "field_invalid", "field": "items.0.quantity", "message": "Quantity must be greater than zero.", "meta": {"min": 0.0001}},
    {"code": "field_required", "field": "due_date", "message": "The due date is required.", "meta": null},
    {"code": "field_required", "field": "currency_code", "message": "The currency code is required.", "meta": null}
  ],
  "meta": {"pagination": null},
  "request_id": "6e7f8091-a2b3-4c5d-9e0f-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

## Error code taxonomy

`errors[].code` values are a stable, documented, growing enumeration (never repurposed to mean something new once shipped); a representative slice:

| Code | HTTP status | Meaning |
|---|---|---|
| `field_required` | 422 | A required field was omitted |
| `field_invalid` | 422 | A field's value fails a format/range/enum rule |
| `entry_not_balanced` | 422 | A journal entry's debits and credits do not sum equal |
| `period_closed` | 409 | The fiscal period targeted is closed to new postings |
| `insufficient_funds` | 409 | A transfer/payment exceeds an account's available balance where enforced |
| `duplicate_idempotency_key` | 409 | See **Idempotency** |
| `permission_denied` | 403 | See **Authorization** |
| `mfa_required` | 403 | See **Authentication** |
| `company_access_denied` | 403 | See **Request Lifecycle** |
| `rate_limit_exceeded` | 429 | See **Rate Limiting** |
| `plan_upgrade_required` | 403 | The active plan tier does not include this capability |
| `resource_not_found` | 404 | No matching row in the caller's company |
| `invalid_filter_field` / `invalid_sort_field` | 422 | See **Filtering** / **Sorting** |
| `webhook_signature_invalid` | 401 | See **Webhooks** (inbound signature verification on relay endpoints) |

## Localization

`message` and `errors[].message` are localized per the request's `Accept-Language` header (`en` or `ar`), rendered through Laravel's standard localization files so a translator can review and update user-facing copy without touching application code. `errors[].code` and `errors[].field` are never localized — they are the stable, ASCII, programmatic surface a client branches on, and translating them would break every SDK's typed-exception mapping (see **SDK Strategy**) the moment a user switched their `Accept-Language`.

## Edge cases

- A request that fails at **multiple pipeline stages simultaneously in principle** (e.g., an expired token *and* a malformed body) always reports the **earliest** stage's failure only (`401`, not `422`), since later stages never execute against an unauthenticated request — this is a direct consequence of the pipeline's fail-fast design in **Request Lifecycle**, not a special case requiring extra logic.
- A `404` is returned, not a `403`, when a resource genuinely does not exist anywhere in the platform (a totally invalid id); a `403` (or a `404` used deliberately to avoid confirming existence, as in the company-membership case in **Request Lifecycle**) is returned when the resource exists but the caller's relationship to it is what's being withheld — module documents specify, per resource, which of the two applies, since the correct choice depends on whether the resource's mere existence is itself sensitive information.
- Partial failure within a single logical operation (e.g., a bulk endpoint accepting 50 line items where 3 fail validation) is never silently partially applied; the entire request is rejected atomically with all 3 failures enumerated in `errors[]`, consistent with every mutation running inside a single database transaction (**Request Lifecycle**, Stage 6).

# Monitoring

QAYD's API layer is monitored on the principle that a support engineer, an SRE, or an auditor should be able to answer "what happened to this specific request" and "is the platform healthy right now" without ever needing to attach a debugger to a production process.

## Request tracing

The `request_id` generated at Stage 1 of the pipeline (**Request Lifecycle**) is the spine of tracing: it appears in the response body, in the `X-Request-Id` header, in every Laravel log line the request touches, in the `audit_logs` row if the request mutated data, in the FastAPI AI engine's own logs if the request triggered an AI action (the AI engine receives and propagates the same id on its internal call back into the API, per **AI API Access**), and in any webhook delivery triggered by the request (as `meta.originating_request_id` in the webhook payload). A support engineer given only a `request_id` from a customer's bug report can reconstruct the full cross-service path of that single request.

QAYD instruments this with OpenTelemetry: every request opens a trace span at the edge, propagates a `traceparent` header (W3C Trace Context standard) across the Laravel-to-FastAPI boundary, and exports spans to the observability backend (Grafana Tempo), correlated with metrics (Prometheus, via `pg_stat_statements` exporters and Laravel's own request-duration histograms) and logs (see **Logging**) sharing the same `request_id`/`trace_id` label, so the three pillars of observability are cross-clickable in one dashboard rather than three disconnected tools.

## Service Level Indicators and Objectives

| SLI | Objective (SLO) | Measurement window |
|---|---|---|
| API availability (non-5xx responses / total) | 99.9% | Rolling 30 days |
| `GET` p95 latency (single-resource, cached-eligible) | < 150ms | Rolling 7 days |
| `GET` p95 latency (list/collection, uncached) | < 400ms | Rolling 7 days |
| `POST`/`PATCH` p95 latency (financial mutation) | < 600ms | Rolling 7 days |
| `POST` p99 latency (financial mutation) | < 1,500ms | Rolling 7 days |
| Webhook delivery success rate (first attempt) | > 98% | Rolling 7 days |
| Error budget burn (5xx rate) | < 0.1% of requests | Rolling 30 days |

An SLO breach — not a single slow request, but the rolling objective itself trending toward violation — pages the on-call engineer via the alerting pipeline described below; a single slow outlier request is expected noise and is visible on dashboards without waking anyone.

## Health and readiness endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/health` | None | Liveness: process is up and can respond at all |
| `GET` | `/api/v1/health/ready` | None | Readiness: database, Redis, and queue connections are all reachable |
| `GET` | `/api/v1/health/deep` | Internal network only | Extended check including replica lag and queue depth, used by the deploy pipeline before routing traffic to a new version |

These endpoints deliberately bypass the standard envelope (they return a minimal `{"status": "ok"}` for the fastest possible load-balancer health check) and are excluded from rate limiting and authentication, since a load balancer probing every few seconds must never be throttled or challenged for credentials.

## Alerting

Alert thresholds are tiered by severity, and every alert links directly to the runbook for its condition (no alert fires without a corresponding, dated, tested runbook — an untested runbook is treated the same as a missing one):

| Condition | Severity | Response |
|---|---|---|
| 5xx rate > 1% for 5 minutes | Page (immediate) | On-call investigates; rollback if tied to a recent deploy |
| p95 latency > 2x SLO for 10 minutes | Page (immediate) | On-call investigates DB/Redis/queue saturation |
| Webhook delivery failure rate > 5% for 15 minutes | Ticket (business hours) unless > 20% (page) | Investigate downstream consumer or delivery worker health |
| Rate-limit 429s from a single actor > 500 in 10 minutes | Ticket | Security review of the actor's traffic pattern |
| Queue depth (background jobs) > 10,000 | Page | Scale worker fleet, investigate stuck jobs |
| Replica lag > 30 seconds | Ticket unless > 2 minutes (page) | Investigate replication, consider routing reads back to primary |

# Logging

Every request produces one structured, machine-parseable log line, and every log line is designed around a single question: what does a person debugging an incident, or an auditor reviewing platform behavior, need to see — and what must they never be able to see.

## Structured request log

```json
{
  "timestamp": "2026-07-16T12:00:00.184Z",
  "level": "info",
  "request_id": "3f1c9e8a-77b0-4b0a-9a5e-2c1f0d7e6b1a",
  "trace_id": "80e1afed08e019fc1110464cfa66635c",
  "method": "POST",
  "path": "/api/v1/sales/invoices",
  "status": 201,
  "duration_ms": 184,
  "company_id": 102,
  "user_id": 77,
  "actor_type": "human",
  "ip": "82.178.xx.xx",
  "user_agent": "QAYD-Flutter/2.4.0 (iOS 18.1)",
  "idempotency_key_present": true,
  "rate_limit_remaining": 297
}
```

Every field above is safe to retain indefinitely in the application log store and safe to hand to a support engineer without any additional access control beyond "is on the support team," because none of it is a secret or a raw financial payload.

## What is never logged

- Passwords, in any form, at any log level, including failed-login attempts (the log records that authentication failed and why — wrong password, expired token, locked account — never the value submitted).
- Full bank account numbers, card numbers, or national ID numbers — these are masked to their last four characters wherever they must appear in a log for debugging context (`****1234`), with the full value retrievable only through the dedicated, permissioned, audited `attachments`/`vendor_bank_accounts` read path, never through the log store.
- Bearer tokens, refresh tokens, JWT secrets, API keys, or webhook signing secrets, in full or truncated form, anywhere in any log line, including error/exception logs (QAYD's exception handler explicitly redacts the `Authorization` header and any field named `*token*`, `*secret*`, `*password*`, `*key*` before an exception is ever serialized to a log sink).
- Full request/response bodies for endpoints touching payment credentials or bank routing details — these log a summary (field names present, not values) rather than the payload itself.

This redaction is enforced by a shared `LogSanitizer` applied uniformly to every log sink (application logs, exception tracker, APM traces), rather than left to each call site to remember, precisely because "remember not to log the password" is a rule that a shared filter enforces reliably and a thousand individual call sites do not.

## Application logs vs. the audit trail

QAYD deliberately maintains **two separate, non-interchangeable records** of "what happened": application logs (this section — operational, retained 90 days hot / 1 year cold, intended for debugging and performance analysis) and `audit_logs` (a business record — retained per the company's data-retention policy, typically 7+ years to match statutory bookkeeping requirements, intended for compliance and forensic review, and itself exposed through a permissioned `GET /api/v1/audit-logs` endpoint rather than a log-aggregation tool). A CFO or external auditor is given access to the audit trail API; they are never given access to the operational log store, which may contain infrastructure detail (worker hostnames, query plans) that has no business meaning and is not the auditor's concern.

## Log levels

| Level | Used for |
|---|---|
| `debug` | Verbose internal detail, enabled only in non-production or temporarily via a feature flag for a specific investigation |
| `info` | Every completed request (the structured line above), successful or a handled 4xx |
| `warning` | Handled-but-notable conditions: rate-limit near-exhaustion, a webhook retry, a cache-stampede lock wait |
| `error` | A 5xx, an unhandled exception, a failed webhook delivery after exhausting retries |
| `critical` | A condition implying data integrity risk: a journal entry that failed a balance check post-validation, a reconciliation mismatch — always paged, never left for daily review |

# Versioning

The API is versioned in the URL path (`/api/v1/`), and QAYD commits to a deprecation discipline strict enough that a customer's third-party bank integration, built once and never touched again, keeps working for years without the customer's engineering involvement.

## What constitutes a breaking vs. non-breaking change

| Change type | Breaking? | Requires a new version? |
|---|---|---|
| Adding a new optional request field | No | No |
| Adding a new field to a response | No | No — clients must ignore unknown fields |
| Adding a new endpoint | No | No |
| Adding a new enum value to an existing field | No, **provided** clients are contractually required to handle unrecognized enum values gracefully (documented requirement for every SDK) | No |
| Removing or renaming a response field | Yes | Yes |
| Removing or renaming a request field, or making an optional field required | Yes | Yes |
| Changing a field's type or semantic meaning | Yes | Yes |
| Changing default behavior (e.g., default `per_page`, default sort) | Yes | Yes |
| Changing an error's HTTP status code or `errors[].code` for an existing condition | Yes | Yes |

Every SDK (**SDK Strategy**) is contractually generated to deserialize unknown response fields into a catch-all bucket rather than failing, which is what makes "adding a response field is non-breaking" an enforceable claim rather than a hope.

## Deprecation policy

A breaking change is never made in place; `/api/v1/` is never mutated incompatibly. Instead, QAYD ships `/api/v2/...` for the affected resource (versioning can be scoped to individual resources under a shared major version umbrella when only one resource's contract changes, to avoid forcing a full-platform migration for a narrow change), and the deprecated version:

1. Continues operating, unchanged, for a **minimum 12-month notice period** from the announcement date — chosen specifically because mobile app store review and staged rollout can add months to a customer's ability to ship an update, and QAYD refuses to force a customer's hand faster than the slowest client platform can realistically move.
2. Returns a `Sunset` header (per RFC 8594) on every response, naming the exact retirement date, from the day the deprecation is announced:

```
Sunset: Sat, 16 Jul 2027 00:00:00 GMT
Deprecation: true
Link: <https://docs.qayd.com/migration/v1-to-v2-invoices>; rel="deprecation"
```

3. Is monitored specifically (a dashboard tracking "calls to deprecated endpoints, by customer") so QAYD's customer success team can proactively reach out to any account still integrated against a version approaching its sunset date, rather than letting the date arrive as a surprise.
4. Is only actually removed after the notice period elapses **and** call volume has fallen to zero or near-zero for that customer, whichever is later — the 12 months is a floor, not a target to hit exactly regardless of real usage.

## Version negotiation

There is no content-negotiation-based versioning (`Accept: application/vnd.qayd.v2+json`) — QAYD rejected this in favor of the URL path specifically because a header-based scheme is invisible in a browser address bar, in a support engineer's copy-pasted curl command, and in a log line's `path` field, all of which make the path-based version dramatically easier to reason about operationally, at the minor cost of a version segment appearing in every route.

# Webhooks

Webhooks let a customer's own systems (an internal ERP, a bank reconciliation tool, a business intelligence dashboard) react to domain events in near-real-time without polling the API. QAYD's webhook system is built around the same reliability guarantees — at-least-once delivery, verifiable authenticity, replay protection — that a financial integration requires.

## Event catalog

| Event | Fires when | Payload resource |
|---|---|---|
| `invoice.created` | A sales invoice is created (any status) | `invoices` |
| `invoice.paid` | An invoice's `amount_due` reaches zero | `invoices` |
| `payment.received` | A receipt is recorded against a customer | `receipts` |
| `payroll.completed` | A payroll run transitions to `released` | `payroll_runs` |
| `inventory.updated` | A stock movement changes an `inventory_items` on-hand quantity | `inventory_items` |
| `bank.synced` | A bank feed connection completes an import of new `bank_transactions` | `bank_transactions` |
| `ai.finished` | An AI agent completes an analysis/proposal and writes an `ai_messages` entry | `ai_messages` |
| `journal.posted` | A journal entry transitions from `draft` to `posted` | `journal_entries` |

This is the platform-level canonical catalog; individual module documents (Accounting, Sales, Payroll, and so on) may register additional, more granular events following the identical `<resource>.<event>` naming convention (for example `tax.return_filed` or `report.completed`) as those modules are specified in detail — every such event is delivered through the exact mechanism documented in this section, so a webhook consumer never needs a second integration pattern for a newly added event type.

## Subscription management

| Method | Path | Permission | Description |
|---|---|---|---|
| `POST` | `/api/v1/webhooks` | `webhooks.manage` | Register a new subscription (URL + event list + secret) |
| `GET` | `/api/v1/webhooks` | `webhooks.manage` | List a company's webhook subscriptions |
| `GET` | `/api/v1/webhooks/{id}` | `webhooks.manage` | Retrieve one subscription |
| `PATCH` | `/api/v1/webhooks/{id}` | `webhooks.manage` | Update URL, event list, or active status |
| `DELETE` | `/api/v1/webhooks/{id}` | `webhooks.manage` | Remove a subscription |
| `GET` | `/api/v1/webhooks/{id}/deliveries` | `webhooks.manage` | Delivery history with status and response codes, for debugging |
| `POST` | `/api/v1/webhooks/{id}/deliveries/{delivery_id}/redeliver` | `webhooks.manage` | Manually retry a specific failed delivery |

```
POST /api/v1/webhooks
Content-Type: application/json
Authorization: Bearer <token>
X-Company-Id: 102

{
  "url": "https://erp.acme-holdings.com/qayd-webhook",
  "events": ["invoice.paid", "journal.posted", "bank.synced"],
  "description": "Internal ERP sync"
}
```

```json
{
  "success": true,
  "data": {
    "id": 41,
    "uuid": "9f0e1d2c-3b4a-4958-8677-6a5b4c3d2e1f",
    "url": "https://erp.acme-holdings.com/qayd-webhook",
    "events": ["invoice.paid", "journal.posted", "bank.synced"],
    "signing_secret": "whsec_7f8e9d0c1b2a3948576a5b4c3d2e1f0a",
    "status": "active",
    "created_at": "2026-07-16T12:00:00Z"
  },
  "message": "Webhook subscription created. Store the signing secret securely — it will not be shown again.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "8091a2b3-c4d5-4e6f-af70-8192a3b4c5d6",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

The `signing_secret` is returned exactly once, at creation time, and stored server-side only as a salted hash — an operator who loses it must rotate to a new secret (`POST /api/v1/webhooks/{id}/secret-rotations`), the same discipline QAYD applies to any credential (**Security**).

## Delivery payload and signature verification

```
POST https://erp.acme-holdings.com/qayd-webhook
Content-Type: application/json
X-Qayd-Signature: t=1752660000,v1=5257a869e7bfa8c8b5b3f6b3b8e7f1a2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8
X-Qayd-Event: invoice.paid
X-Qayd-Delivery-Id: 991a2b3c-4d5e-4f60-8172-93a4b5c6d7e8

{
  "event": "invoice.paid",
  "data": {
    "id": 48213,
    "invoice_number": "INV-2026-004213",
    "company_id": 102,
    "amount_paid": "1312.5000",
    "paid_at": "2026-07-16T11:58:02Z"
  },
  "meta": {"originating_request_id": "3f1c9e8a-77b0-4b0a-9a5e-2c1f0d7e6b1a"},
  "created_at": "2026-07-16T11:58:03Z"
}
```

The signature (`X-Qayd-Signature`) is an HMAC-SHA256 of `"{timestamp}.{raw_body}"` using the subscription's signing secret, following the same `t=...,v1=...` convention popularized by mature webhook platforms specifically because it is well understood and easy to implement correctly across languages:

```php
// Verifying inbound signature (PHP example for a customer's receiving endpoint)
[$tPart, $vPart] = explode(',', $request->header('X-Qayd-Signature'));
$timestamp = substr($tPart, 2);
$expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $signingSecret);
abort_unless(hash_equals($expected, substr($vPart, 3)), 401, 'Invalid webhook signature.');
abort_if(abs(time() - (int) $timestamp) > 300, 401, 'Webhook timestamp outside tolerance — possible replay.');
```

```python
# Verifying inbound signature (Python example)
import hmac, hashlib, time

t_part, v_part = signature_header.split(",")
timestamp = t_part.split("=")[1]
expected = hmac.new(signing_secret.encode(), f"{timestamp}.{raw_body}".encode(), hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, v_part.split("=")[1]):
    raise ValueError("Invalid webhook signature")
if abs(time.time() - int(timestamp)) > 300:
    raise ValueError("Webhook timestamp outside tolerance")
```

The 300-second tolerance window plus the signature binding the timestamp into the HMAC input together prevent a captured, valid delivery from being replayed later by an attacker who intercepted it — an old, correctly-signed payload replayed outside the window is rejected regardless of how valid its signature was at the time of original delivery.

## Retry policy

| Attempt | Delay after previous attempt |
|---|---|
| 1 (initial) | Immediate |
| 2 | 30 seconds |
| 3 | 2 minutes |
| 4 | 10 minutes |
| 5 | 1 hour |
| 6 (final) | 6 hours |

A delivery is considered failed if the receiving endpoint does not respond with a `2xx` status within a 10-second timeout. After the sixth attempt fails, the delivery is marked `dead_lettered`, visible in `GET /api/v1/webhooks/{id}/deliveries`, and — for events tagged financially significant (`invoice.paid`, `journal.posted`, `payroll.completed`) specifically — triggers an in-app notification to the company's admin users, since a customer relying on a webhook to trigger their own downstream accounting sync must be told, not left to silently drift out of sync.

# SDK Strategy

QAYD publishes official, generated-not-handwritten SDKs so that no integrator has to hand-roll HTTP calls, envelope parsing, retry logic, or error typing against the raw REST contract.

## Supported languages and their primary consumers

| SDK | Primary consumer | Package |
|---|---|---|
| PHP | Server-side integrations, Laravel-based partner platforms | `qayd/qayd-php` (Composer) |
| JavaScript/TypeScript | The Next.js 15 web app itself, and browser/Node.js integrations | `@qayd/sdk` (npm) |
| Python | The FastAPI AI engine, data/analytics integrations | `qayd-python` (PyPI) |
| Flutter/Dart | The QAYD mobile app itself, and partner mobile apps | `qayd_sdk` (pub.dev) |

Every SDK, including the ones consumed by QAYD's own first-party clients, is generated from the same OpenAPI 3.1 specification (**OpenAPI**) using a shared code-generation pipeline, rather than hand-maintained per language — QAYD's own web and mobile teams are deliberately made to feel the same friction (or lack thereof) as an external integrator, which is precisely what keeps the generated SDKs good: if they were painful, QAYD's own engineers would demand they be fixed.

## What every SDK guarantees

- **Typed models**, generated from the OpenAPI schemas, with money fields deserialized into a language-appropriate arbitrary-precision decimal type (never a native float), per **Resource Design**.
- **Automatic envelope unwrapping** — a caller gets back a typed resource object or a typed exception, never a raw envelope dict to destructure manually.
- **Typed exceptions per error code** — `errors[].code` values map to a class hierarchy (`QaydPermissionDeniedException`, `QaydValidationException` carrying the full field-level `errors[]`, `QaydRateLimitException` carrying `retry_after_seconds`) so a caller can `catch` specifically rather than string-matching a message.
- **Automatic retry with backoff** for idempotent requests (`GET`, and any request bearing an `Idempotency-Key`) on transient failures (`429`, `503`, connection resets) — capped at 3 attempts with exponential backoff plus jitter, and never retrying a non-idempotent request that lacks an idempotency key, since the SDK cannot know it is safe to do so.
- **Automatic token refresh** — the SDK holds both the access and refresh token, transparently refreshes on a `401` with `token_expired` once, retries the original request once, and only then surfaces an authentication error to the calling application.
- **Pagination iterators** — `for invoice in client.invoices.list(filter={"status": "posted"}):` transparently walks pages (offset or cursor, per **Pagination**) without the caller managing page/cursor state manually.

## Example usage

```typescript
// @qayd/sdk — TypeScript
const qayd = new QaydClient({ apiKey: process.env.QAYD_TOKEN, companyId: 102 });

const invoice = await qayd.invoices.create({
  customerId: 5541,
  issueDate: "2026-07-10",
  dueDate: "2026-08-09",
  currencyCode: "KWD",
  items: [{ productId: 991, quantity: 3, unitPrice: "125.0000" }],
}, { idempotencyKey: crypto.randomUUID() });

console.log(invoice.total.toFixed(4)); // Decimal-safe, never a raw float
```

```python
# qayd-python — used internally by the FastAPI AI engine
from qayd import QaydClient

client = QaydClient(service_token=settings.AI_SERVICE_TOKEN, company_id=102)

proposal = client.journal_entries.propose(
    lines=[{"account_id": 4010, "debit": "500.0000"}, {"account_id": 2010, "credit": "500.0000"}],
    reasoning="Matched to bank transaction #88213 per vendor bill #4471 (confidence 0.94).",
    confidence=0.94,
)
```

# GraphQL Future

QAYD does not offer a GraphQL API today, and REST under `/api/v1/` remains the sole, canonical contract for the platform. This is a deliberate choice, revisited on a fixed schedule (annually, as part of the platform architecture review) rather than an oversight to be quietly fixed later.

## Why not now

- **Tenant-scoped query cost is unsolved for QAYD's data shape.** A naive GraphQL schema over QAYD's deeply relational accounting graph (an invoice to its customer to that customer's other invoices to their line items to their products to their category...) makes it trivial for a client to accidentally construct a query that fans out into thousands of database reads within a single HTTP request. Query cost analysis and depth/complexity limiting are solvable problems, but they are unsolved *for QAYD specifically* today, and QAYD will not expose a query surface it cannot yet bound.
- **Caching gets materially harder.** REST's per-resource, per-verb caching (**Caching**) maps cleanly onto CDN and Redis caching primitives keyed by URL. A single GraphQL endpoint receiving arbitrary query shapes via `POST /graphql` defeats URL-based caching entirely and requires a persisted-query or response-caching strategy of its own.
- **It would be a second contract to keep correct, not a replacement for the first.** Every guarantee this document defines — the envelope, idempotency, rate limiting, permission enforcement — would need an equivalent, independently correct implementation for GraphQL, doubling the attack surface and the audit burden for a financial system, for a benefit (avoiding over-fetching on complex nested reads) that QAYD's `?expand=` mechanism (**Resource Design**) already addresses for the cases that matter most today.
- **No current client is blocked by REST.** The Next.js web app, the Flutter mobile app, and third-party integrations are all well-served by REST plus `expand`; there is no concrete, named use case today that REST cannot satisfy, and QAYD does not add a second API paradigm speculatively.

## What would change the answer

A future **Backend-for-Frontend (BFF) GraphQL gateway**, sitting *in front of* the existing REST API (translating GraphQL queries into one or more internal REST calls, rather than reading the database directly) is the most likely eventual shape, should a concrete need emerge — most plausibly from a future complex mobile dashboard view that would otherwise require many round trips, or from an enterprise customer's own data team wanting flexible read access to their own company's data for BI purposes. In that future, REST remains the system of record and the only path with write access; a GraphQL gateway, if built, would be read-mostly, would enforce the identical authentication/authorization/rate-limiting stack by calling back into the REST API rather than bypassing it, and would be introduced as an additive capability, never a replacement for any REST consumer already depending on `/api/v1/`.

# Performance

Every performance target in this section is stated as a number, not an aspiration, because "the API should be fast" is not implementable and "p95 under 400ms for uncached collection reads" is.

## Latency budget by endpoint class

| Endpoint class | p50 target | p95 target | p99 target |
|---|---|---|---|
| Single-resource `GET`, cache-eligible, cache hit | < 30ms | < 80ms | < 150ms |
| Single-resource `GET`, cache miss / non-cacheable | < 60ms | < 150ms | < 300ms |
| Collection `GET`, offset-paginated | < 100ms | < 250ms | < 500ms |
| Collection `GET`, cursor-paginated (high-volume) | < 80ms | < 200ms | < 400ms |
| Financial mutation `POST`/`PATCH` (single-transaction) | < 150ms | < 400ms | < 900ms |
| Multi-step mutation (e.g., invoice post touching inventory + GL) | < 250ms | < 600ms | < 1,500ms |

These map directly onto the SLOs in **Monitoring**; the latency budget table is the engineering target teams design against, and the SLO table is what production is actually held accountable to (the budget is intentionally tighter than the SLO, so normal variance does not immediately threaten the customer-facing objective).

## N+1 prevention

Every collection endpoint that returns related identifiers eagerly loads the relations it is documented to expand (`?expand=`), using Eloquent's `with()` at the Repository layer, never lazy-loading inside a serialization loop. QAYD enforces this in CI: a test suite (`API_TESTING`) runs every endpoint with Laravel's strict-mode query logging enabled and fails the build if a single request issues more queries than a documented budget for that endpoint (typically 3–8 queries for a simple collection `GET`, scaling with the number of `expand` targets requested) — an N+1 regression is caught the moment it is introduced, not the first time a customer's dataset is large enough to make it visible.

## Query budget and read/write splitting

Read-only `GET` traffic on reporting-heavy resources (trial balance, aging reports, dashboard aggregates) is routed to a PostgreSQL read replica (`DATABASE_ARCHITECTURE`) by the Repository layer's connection selection, transparent to the controller; any request within an active database transaction (all mutations, and any read that must be transactionally consistent with a preceding write in the same request) stays pinned to the primary. Replica lag is monitored (**Monitoring**) specifically because a customer who posts an invoice and immediately re-fetches it must never see stale data — QAYD's Repository layer pins the primary connection for a configurable window (2 seconds) after any write **by that same user**, so a "read your own write" request never accidentally lands on a lagging replica, while unrelated read traffic continues to benefit from replica offload.

## Payload size discipline

Default `per_page` (25) and the enforced cap (100 for offset, 200 for cursor) exist as much for payload-size and serialization-time control as for database load; a collection response is capped, by design, at a size that serializes and transmits well within the latency budget above on a typical mobile connection. Responses are compressed (`gzip`, or `br` when the client's `Accept-Encoding` supports it) at the load balancer, transparent to the application, and this is verified to meaningfully shrink QAYD's JSON payloads (routinely 70–85% size reduction on list responses, given the repetitive key structure of a JSON array of similarly-shaped objects).

## Heavy operations run asynchronously

Any operation that cannot plausibly complete inside the mutation latency budget above — generating a multi-year financial statement, running payroll calculations for a thousand-employee company, bulk-importing a bank statement with ten thousand lines — is never executed synchronously inside the HTTP request. The endpoint instead creates a job resource (e.g., `POST /api/v1/reports/runs` returns `202 Accepted` immediately with a `report_runs` row in `status: queued`), the actual work runs on the Redis-backed queue worker fleet, and completion is signaled through the existing webhook/notification mechanism rather than the client holding an HTTP connection open indefinitely. This keeps the P99 tail of the *synchronous* API surface honest — a slow report never appears as a slow API endpoint in the latency dashboards, because it genuinely is not one.

# Scalability

QAYD's API tier is designed to scale horizontally without re-architecture from its first production deployment through multi-country enterprise scale, because the statelessness principle in **REST Architecture** was chosen specifically to make that possible.

## Horizontal scaling of the application tier

Laravel runs on **Laravel Octane** (Swoole/RoadRunner application server) behind a load balancer, as a fleet of stateless worker processes that can be scaled up or down purely by process count with zero coordination between workers — no sticky sessions, no in-memory state that a second request from the same user must land on the same worker to see, because all such state lives in Redis or PostgreSQL, reachable from every worker identically. Autoscaling triggers on CPU utilization and request queue depth, with a floor of instances sized for the rate-limit ceiling of QAYD's largest single enterprise customer, so a burst from one large customer never degrades service for every other tenant sharing the fleet.

## Database connection scaling

As the worker fleet scales, raw PostgreSQL connections scale with it unless bounded; QAYD runs **PgBouncer** in transaction-pooling mode between the application fleet and PostgreSQL specifically so that hundreds of Octane workers multiplex onto a much smaller number of actual database backend connections, since PostgreSQL's per-connection memory overhead makes "one backend connection per worker process" an early ceiling otherwise (detailed further in `DATABASE_ARCHITECTURE`/`DATABASE_PERFORMANCE`).

## Background work scaling

Webhook delivery, report generation, AI-triggered background analysis, and email/SMS notification dispatch all run on Redis-backed queue workers (Laravel Horizon supervising the queue fleet), scaled independently of the request-serving fleet — a surge in webhook redeliveries or report generation demand is handled by adding queue workers, never by competing with the synchronous request path for the same process pool.

## Rate limiting and caching at scale

Both **Rate Limiting** and **Caching** are backed by a Redis cluster (not a single Redis instance) once traffic exceeds a single node's safe throughput, using consistent hashing so that a given company's rate-limit and cache keys land predictably on the same shard, keeping per-company operations fast without a cross-shard fan-out for the common case.

## Multi-region posture

QAYD runs single-region today (primary region: closest to its Gulf customer base, with the read replica strategy in **Performance** operating within that region) because cross-region synchronous writes to a single ledger would trade away consistency guarantees the platform is unwilling to weaken for money data. The scalability roadmap's next step, when justified by customer geographic distribution, is **regional read replicas with a single global write region per company** (a company's writes always land in its home region; read-only reporting traffic can be served from a geographically closer replica) rather than a multi-master write topology, preserving the single-source-of-truth guarantee in **API Philosophy** even as the platform becomes geographically distributed.

## Growth path summary

| Scale milestone | What changes |
|---|---|
| Single Kuwait SME customers (today) | Single-region, single Octane fleet, single Redis, single PostgreSQL primary + 1 replica |
| Hundreds of SME/mid-market companies | Autoscaled Octane fleet, PgBouncer pooling, Redis cluster, 2+ read replicas |
| Enterprise/multi-country customers | Dedicated isolation tier option (`DATABASE_MULTI_TENANCY`), regional read replicas, dedicated AI engine capacity per large tenant |
| Public developer platform scale | Rate-limit tiers become customer-facing/self-service, GraphQL BFF gateway reconsidered (**GraphQL Future**), possible Citus-sharded PostgreSQL for the largest tenants |

# Security

The API is QAYD's entire external attack surface — there is no other network-reachable path into the system — so this section states the security posture of the layer as a whole, cross-referencing the specific mechanisms detailed earlier rather than repeating them.

## Transport security

TLS 1.2 is the minimum accepted protocol version, with TLS 1.3 preferred and negotiated wherever the client supports it; TLS 1.0/1.1 and all export/null/RC4 cipher suites are disabled at the load balancer. HSTS (`Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`) is sent on every response, and the domain is submitted to browser HSTS preload lists, so that even a client's very first request cannot be downgraded to plaintext by a network-level attacker.

## Input handling

Every request body is validated against an explicit `FormRequest` schema (**Request Lifecycle**, Stage 4) before it reaches business logic — there is no endpoint that passes raw, unvalidated input to a service class. Laravel's Eloquent query builder (parameter binding throughout; no raw string-concatenated SQL anywhere in the codebase, enforced by a static-analysis rule in CI) eliminates SQL injection as a class of vulnerability rather than relying on developer discipline per query. Mass-assignment is blocked by default (`$guarded = ['*']` on every model, with an explicit `$fillable` allow-list per model), so a client cannot set `company_id`, `id`, or any privileged field on a resource merely by including it in a request body the server did not ask for.

## Output handling

The API returns `application/json` exclusively and never renders user-supplied content as HTML, which eliminates reflected/stored XSS as a concern for the API responses themselves; the Next.js frontend, as the one layer that does render content, is responsible for its own output-encoding discipline (React's default JSX escaping, with `dangerouslySetInnerHTML` banned outside a short, code-reviewed allow-list) — documented fully in the frontend's own security specification, referenced here because the API's contract (never returning pre-rendered HTML fragments in any field) is what makes that frontend-side guarantee tractable in the first place.

## Secrets management

Database credentials, the JWT signing key, the `ANTHROPIC`-equivalent AI provider keys, webhook signing secrets, and third-party integration credentials (bank feed providers, tax-authority gateways) are never committed to source control and never present in a client-side bundle; they are injected at runtime from a managed secrets store (cloud provider secrets manager, referenced by the deployment configuration, not by a plaintext `.env` committed anywhere) and rotated on a schedule (90 days for service credentials, immediately on any suspected exposure) with the old credential revoked, not merely replaced, once rotation completes.

## Encryption at rest

Database-level encryption at rest (full-disk/volume encryption at the cloud provider layer) is the baseline for every table; a narrower set of especially sensitive columns (bank account numbers, national ID numbers, raw payment credentials where briefly held in transit to a processor) receive an additional, application-level `pgcrypto` column encryption layer, detailed in `DATABASE_ARCHITECTURE`/`DATABASE_ENCRYPTION` — the API layer's obligation is to never decrypt those columns into a response unless the requesting permission explicitly covers viewing the unmasked value, defaulting to the masked form (`****1234`) everywhere else, including in the `errors[].meta` debugging payloads discussed in **Error Handling**.

## Abuse and anomaly controls

**Rate Limiting** is the primary abuse control for volumetric attacks. Layered on top: failed-authentication attempts are counted per account and per IP, with progressive backoff and eventual temporary account lock after repeated failures (surfaced to the user via `error.code = "account_temporarily_locked"`, never a silent hang, per **API Philosophy**'s "nothing fails silently" principle); a Web Application Firewall at the edge blocks common injection/exploitation signatures before they reach the application; and an anomaly-detection job (part of the AI layer's **Fraud Detection** agent, itself calling back into the API like any other AI actor per **AI API Access**) reviews authentication and transfer patterns for signals — a login from a new country immediately followed by a large transfer attempt, for instance — that trigger a step-up MFA challenge or a manual hold, never a fully automated block for a large financial action.

## CORS

The API's CORS policy allow-lists exactly the origins that need browser access — `app.qayd.com`, `sandbox-app.qayd.com`, and any customer-specific white-labeled domain explicitly registered for that company — and rejects `Authorization`-header-bearing cross-origin requests from any other origin; there is no wildcard (`*`) origin permitted anywhere the request can carry credentials.

## Dependency and vulnerability management

Backend (Composer/PHP), frontend (npm), and AI engine (pip/Python) dependencies are scanned continuously (Dependabot-equivalent plus a software-composition-analysis tool) for known CVEs, with critical/high-severity findings in directly-exploitable paths patched within a committed SLA (72 hours for critical, 2 weeks for high). An external penetration test is commissioned at least annually and after any major architectural change to the API layer (a new auth flow, a new payment-adjacent integration), with findings tracked to closure the same way a production incident is tracked, per `API_SECURITY`'s more detailed treatment where that document exists.

## Security headers

| Header | Value |
|---|---|
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains; preload` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Content-Security-Policy` | Restrictive default-src for any HTML-serving route (the API itself serves none) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | Deny geolocation/camera/microphone by default at the API edge |

# OpenAPI

QAYD's API contract is authored once, as an OpenAPI 3.1 specification, and every other artifact — the human-readable developer docs, every generated SDK (**SDK Strategy**), and the contract tests that gate every deploy — is derived from or validated against that one specification, never hand-maintained in parallel.

## Specification as the source of truth

```
GET /api/v1/openapi.json     — machine-readable, versioned contract for v1
GET /api/v1/openapi.yaml     — same contract, YAML form
GET /docs                    — human-readable, rendered developer documentation (Redoc/Scalar), generated from the same file
```

The specification is generated from the Laravel codebase itself (PHP attributes on controllers and FormRequest classes describing each route's parameters, request schema, response schema, and possible error responses) rather than written as a hand-maintained YAML file that drifts from the actual routes — a route added without the corresponding OpenAPI attributes fails a CI check (`openapi:validate`) before it can merge, which is what keeps "the spec" and "the API" from ever silently diverging.

## Example specification fragment

```yaml
/api/v1/sales/invoices:
  get:
    summary: List invoices
    tags: [Sales]
    security: [{ bearerAuth: [] }]
    parameters:
      - { name: X-Company-Id, in: header, required: false, schema: { type: integer } }
      - { name: page, in: query, schema: { type: integer, default: 1 } }
      - { name: per_page, in: query, schema: { type: integer, default: 25, maximum: 100 } }
      - { name: filter[status], in: query, schema: { type: string, enum: [draft, posted, partially_paid, paid, void] } }
      - { name: sort, in: query, schema: { type: string, default: "-created_at" } }
    responses:
      '200':
        description: Paginated list of invoices
        content:
          application/json:
            schema: { $ref: '#/components/schemas/InvoiceListEnvelope' }
      '401': { $ref: '#/components/responses/Unauthorized' }
      '403': { $ref: '#/components/responses/Forbidden' }
      '429': { $ref: '#/components/responses/RateLimited' }
  post:
    summary: Create an invoice
    tags: [Sales]
    security: [{ bearerAuth: [] }]
    requestBody:
      required: true
      content:
        application/json:
          schema: { $ref: '#/components/schemas/InvoiceCreateRequest' }
    responses:
      '201': { description: Invoice created, content: { application/json: { schema: { $ref: '#/components/schemas/InvoiceEnvelope' } } } }
      '422': { $ref: '#/components/responses/ValidationError' }
```

## Contract testing

Every deploy pipeline run executes a contract-conformance suite (Spectator against Laravel's actual responses, Schemathesis running property-based fuzzing directly off the OpenAPI file) that fails the build if any live endpoint's actual response shape diverges from its documented schema — an extra field with the wrong type, a documented-required field genuinely missing, an undocumented status code — closing the loop between "the spec says this" and "the API actually does this" as an automated gate rather than a manual review.

## Versioned specifications

Each API version (**Versioning**) publishes its own immutable specification file (`/api/v1/openapi.json` never changes shape once `/api/v2/` exists for a given resource); a customer's tooling that generated code against the v1 spec a year ago can diff today's v1 spec against its saved copy and expect zero differences beyond strictly additive ones, per the breaking/non-breaking table in **Versioning**.

# AI API Access

QAYD's FastAPI/Python AI engine is architecturally a **client of the REST API**, not a privileged internal service with database access — this is the single most load-bearing security property in a platform that markets itself as an *AI* Financial Operating System, and it is enforced structurally rather than by policy alone.

## How the AI engine authenticates and is scoped

The AI engine authenticates via a service-account token (**Authentication**) bound to a specific set of companies, exactly like a human user is bound to the companies they belong to via `company_users`. It is assigned one or more of a small set of AI-specific roles (`ai_general_accountant`, `ai_auditor`, `ai_tax_advisor`, `ai_payroll_manager`, `ai_inventory_manager`, `ai_treasury_manager`, `ai_fraud_detection`, `ai_reporting_agent`, `ai_document_ocr`, `ai_forecast_agent`, `ai_compliance_agent`, `ai_approval_assistant`), each carrying a permission set that is a deliberately **restricted subset** of the corresponding human role's permissions — critically, **never including any `*.approve`, `*.release`, `*.submit`, or `*.void.approve` permission**, per the sensitive-operations table in **Authorization**, regardless of how the AI's role is otherwise configured for a given company. This is a hard-coded platform invariant, not a per-company setting: no company configuration can grant an AI service account an approval-tier permission, because the entire premise of QAYD's AI trust model depends on a human always being the one who pulls the trigger on an irreversible financial action.

## Autonomy levels

Every AI agent's action on every endpoint falls into exactly one of three autonomy levels, declared per agent per action in that agent's own specification document and enforced by which permission key the AI's role is actually granted:

| Autonomy level | What it means | Example |
|---|---|---|
| **Auto** | The AI calls the endpoint and the effect happens immediately, no human step | Categorizing an incoming bank transaction against a chart-of-accounts code the AI has high confidence in |
| **Suggest-only** | The AI writes a proposal/draft record; nothing changes until a human separately acts on it through the normal UI/API, with no obligation to act at all | Forecast Agent publishing a cash-flow projection; Document AI extracting line items from a scanned bill into a draft `bills` row |
| **Requires-approval** | The AI creates a `pending_approval` resource using the exact same two-step pattern as a human initiator in **Authorization**, and a human must call the separate `*.approve` endpoint | Fraud Detection flagging a transaction and proposing a hold; General Accountant agent proposing a journal entry for a transaction it matched with high confidence |

## Every AI write is tagged and explainable

```
POST /api/v1/accounting/journal-entries
Authorization: Bearer <AI service token>
X-Company-Id: 102
Idempotency-Key: ai-proposal-7f8e9d0c-1b2a-3948-5768-a5b4c3d2e1f0

{
  "status": "pending_approval",
  "lines": [
    {"account_id": 4010, "debit": "500.0000", "cost_center_id": 12},
    {"account_id": 2010, "credit": "500.0000"}
  ],
  "meta": {
    "ai_generated": true,
    "ai_agent": "general_accountant",
    "confidence": 0.94,
    "reasoning": "Matched bank_transactions#88213 (KWD 500.000, 2026-07-15, memo 'ACME SUPPLIES') to bills#4471 (ACME Supplies W.L.L., due 2026-07-20) on exact amount and vendor-name similarity (0.97).",
    "source_documents": [{"type": "bank_transaction", "id": 88213}, {"type": "bill", "id": 4471}]
  }
}
```

`meta.ai_generated`, `meta.confidence`, and `meta.reasoning` are not optional decoration — the `FormRequest` validating any endpoint reachable by an AI service-account token requires all three whenever the authenticated actor is a service account, and rejects the request with `422` (`error.code = "ai_reasoning_required"`) if they are absent, because an unexplained AI-originated financial record is precisely the failure mode this architecture exists to prevent. The resulting record's `created_by` is the AI service account's own `user_id` (never impersonating the human who configured it), so the audit trail (`DATABASE_ARCHITECTURE`) always shows, truthfully, whether a human or an AI agent authored a given row.

## Rate limits and blast-radius containment

The AI engine's traffic occupies its own rate-limit bucket per **Rate Limiting**, and additionally, any single AI agent run is capped on the number of `pending_approval` resources it may create per company per hour (a circuit breaker distinct from rate limiting — it protects against a correct-but-overzealous AI flooding a CFO's approval queue with hundreds of low-value proposals, not against raw request volume) — when that cap is hit, the agent's run is paused and the platform notifies the company's admins that AI activity has been throttled, rather than either silently dropping proposals or silently disabling the cap.

# Examples

This section ties every preceding section together into complete, runnable request/response pairs, using the real envelope, real `/api/v1/` paths, and the canonical resources and permissions defined throughout this document.

## Example 1 — Authenticate, then create a customer

```bash
curl -X POST https://api.qayd.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "accountant@acme-holdings.com", "password": "••••••••••••"}'
```

```bash
curl -X POST https://api.qayd.com/api/v1/sales/customers \
  -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..." \
  -H "X-Company-Id: 102" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 3b2a1c0d-9e8f-4a7b-b6c5-d4e3f2a1b0c9" \
  -d '{
    "name_en": "Al-Fahad Trading Co.",
    "name_ar": "شركة الفهد التجارية",
    "currency_code": "KWD",
    "credit_limit": "15000.0000",
    "customer_addresses": [{"line1": "Al-Soor Street, Building 12", "city": "Kuwait City", "country": "KW"}]
  }'
```

```json
{
  "success": true,
  "data": {
    "id": 5541,
    "uuid": "b1a2c3d4-5e6f-4708-9a1b-2c3d4e5f6a7b",
    "company_id": 102,
    "name_en": "Al-Fahad Trading Co.",
    "name_ar": "شركة الفهد التجارية",
    "currency_code": "KWD",
    "credit_limit": "15000.0000",
    "outstanding_balance": "0.0000",
    "status": "active",
    "created_at": "2026-07-16T12:00:00Z"
  },
  "message": "Customer created successfully.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "c4d5e6f7-8091-4a2b-9c3d-4e5f6a7b8c9d",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

## Example 2 — Create an invoice, then list invoices filtered and sorted

```bash
curl -X POST https://api.qayd.com/api/v1/sales/invoices \
  -H "Authorization: Bearer <token>" -H "X-Company-Id: 102" \
  -H "Content-Type: application/json" -H "Idempotency-Key: 6f7a8b9c-0d1e-4f2a-b3c4-d5e6f7a8b9c0" \
  -d '{
    "customer_id": 5541, "issue_date": "2026-07-16", "due_date": "2026-08-15",
    "currency_code": "KWD", "cost_center_id": 12,
    "items": [{"product_id": 991, "quantity": 10, "unit_price": "125.0000", "tax_code_id": 3}]
  }'
```

```bash
curl -G https://api.qayd.com/api/v1/sales/invoices \
  -H "Authorization: Bearer <token>" -H "X-Company-Id: 102" \
  --data-urlencode "filter[status][in]=posted,partially_paid" \
  --data-urlencode "filter[issue_date][gte]=2026-07-01" \
  --data-urlencode "sort=-total" \
  --data-urlencode "per_page=2"
```

```json
{
  "success": true,
  "data": [
    {"id": 48213, "invoice_number": "INV-2026-004213", "customer_id": 5541, "status": "posted", "total": "1362.5000", "amount_due": "1362.5000", "issue_date": "2026-07-16"},
    {"id": 48190, "invoice_number": "INV-2026-004190", "customer_id": 4402, "status": "partially_paid", "total": "980.0000", "amount_due": "430.0000", "issue_date": "2026-07-12"}
  ],
  "message": "Invoices retrieved successfully.",
  "errors": [],
  "meta": {"pagination": {"page": 1, "per_page": 2, "total": 47, "cursor": null}},
  "request_id": "d5e6f708-91a2-4b3c-8d4e-5f6a7b8c9d0e",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

## Example 3 — Validation failure (422)

```bash
curl -X POST https://api.qayd.com/api/v1/payroll/runs \
  -H "Authorization: Bearer <token>" -H "X-Company-Id: 102" -H "Content-Type: application/json" \
  -d '{"fiscal_period_id": 9, "employee_ids": []}'
```

```json
{
  "success": false,
  "data": null,
  "message": "The payroll run could not be created due to validation errors.",
  "errors": [
    {"code": "field_invalid", "field": "employee_ids", "message": "At least one employee must be included in a payroll run.", "meta": {"min_items": 1}}
  ],
  "meta": {"pagination": null},
  "request_id": "e6f70819-a2b3-4c5d-9e0f-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

## Example 4 — Sensitive action: initiate, then approve a bank transfer

```bash
curl -X POST https://api.qayd.com/api/v1/banking/transfers \
  -H "Authorization: Bearer <finance_manager token>" -H "X-Company-Id: 102" \
  -H "Content-Type: application/json" -H "Idempotency-Key: 91a2b3c4-d5e6-4708-9192-a3b4c5d6e7f8" \
  -d '{"from_bank_account_id": 44, "to_bank_account_id": 51, "amount": "5000.0000", "currency_code": "KWD", "memo": "Q3 working capital"}'
```

```json
{
  "success": true,
  "data": {"id": 771, "status": "pending_approval", "amount": "5000.0000", "requested_by": 77, "created_at": "2026-07-16T12:00:00Z"},
  "message": "Transfer created and awaiting approval.",
  "errors": [], "meta": {"pagination": null},
  "request_id": "f7081920-a3b4-4c5d-8e9f-2a3b4c5d6e7f", "timestamp": "2026-07-16T12:00:00Z"
}
```

```bash
curl -X POST https://api.qayd.com/api/v1/banking/transfers/771/approvals \
  -H "Authorization: Bearer <cfo token>" -H "X-Company-Id: 102" -H "Idempotency-Key: a2b3c4d5-e6f7-4809-9203-b4c5d6e7f8a9"
```

```json
{
  "success": true,
  "data": {"id": 771, "status": "approved", "approved_by": 12, "approved_at": "2026-07-16T12:05:00Z", "executed_at": "2026-07-16T12:05:01Z"},
  "message": "Transfer approved and executed.",
  "errors": [], "meta": {"pagination": null},
  "request_id": "0819202a-b4c5-4d6e-8f9a-3b4c5d6e7f8a", "timestamp": "2026-07-16T12:00:00Z"
}
```

## Example 5 — AI-proposed journal entry, human approval

```bash
curl -X POST https://api.qayd.com/api/v1/accounting/journal-entries \
  -H "Authorization: Bearer <AI service token>" -H "X-Company-Id: 102" \
  -H "Content-Type: application/json" -H "Idempotency-Key: ai-7f8e9d0c-1b2a-3948-5768-a5b4c3d2e1f0" \
  -d '{
    "status": "pending_approval",
    "lines": [{"account_id": 4010, "debit": "500.0000"}, {"account_id": 2010, "credit": "500.0000"}],
    "meta": {"ai_generated": true, "ai_agent": "general_accountant", "confidence": 0.94,
      "reasoning": "Matched bank_transactions#88213 to bills#4471 on exact amount and vendor-name similarity (0.97).",
      "source_documents": [{"type": "bank_transaction", "id": 88213}, {"type": "bill", "id": 4471}]}
  }'
```

```bash
curl -X POST https://api.qayd.com/api/v1/accounting/journal-entries/9021/postings \
  -H "Authorization: Bearer <senior_accountant token>" -H "X-Company-Id: 102" \
  -H "Idempotency-Key: b3c4d5e6-f708-4910-a2b3-c4d5e6f7a8b9"
```

```json
{
  "success": true,
  "data": {"id": 9021, "status": "posted", "posted_by": 88, "posted_at": "2026-07-16T12:10:00Z",
    "meta": {"ai_generated": true, "ai_agent": "general_accountant", "confidence": 0.94, "human_reviewed_by": 88}},
  "message": "Journal entry posted.",
  "errors": [], "meta": {"pagination": null},
  "request_id": "1920a2b3-c5d6-4e7f-9a0b-4c5d6e7f8a9b", "timestamp": "2026-07-16T12:00:00Z"
}
```

## Example 6 — Webhook subscription and a delivered event

```bash
curl -X POST https://api.qayd.com/api/v1/webhooks \
  -H "Authorization: Bearer <owner token>" -H "X-Company-Id: 102" -H "Content-Type: application/json" \
  -d '{"url": "https://erp.acme-holdings.com/qayd-webhook", "events": ["invoice.paid", "journal.posted"]}'
```

```json
{
  "success": true,
  "data": {"id": 41, "url": "https://erp.acme-holdings.com/qayd-webhook", "events": ["invoice.paid", "journal.posted"],
    "signing_secret": "whsec_7f8e9d0c1b2a3948576a5b4c3d2e1f0a", "status": "active"},
  "message": "Webhook subscription created. Store the signing secret securely — it will not be shown again.",
  "errors": [], "meta": {"pagination": null},
  "request_id": "2a3b4c5d-6e7f-4809-8a1b-5c6d7e8f9a0b", "timestamp": "2026-07-16T12:00:00Z"
}
```

Delivered payload (to `https://erp.acme-holdings.com/qayd-webhook`, signed per **Webhooks**):

```json
{
  "event": "journal.posted",
  "data": {"id": 9021, "status": "posted", "company_id": 102, "posted_at": "2026-07-16T12:10:00Z"},
  "meta": {"originating_request_id": "1920a2b3-c5d6-4e7f-9a0b-4c5d6e7f8a9b"},
  "created_at": "2026-07-16T12:10:01Z"
}
```

# End of Document
