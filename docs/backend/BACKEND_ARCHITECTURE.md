# Backend Architecture — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: BACKEND_ARCHITECTURE
---

# Purpose

This document is the definitive overview of the QAYD backend: the Laravel 12 application that
is the single source of truth for every piece of business and financial state in the platform.
It defines how the backend is structured, how a request travels from the edge to the database
and back, where module boundaries sit, and how the backend relates to the other five systems in
the platform — the Next.js web client, the Flutter mobile client, third-party integrations, the
FastAPI AI engine, and the PostgreSQL/Redis/R2 data tier. Every per-service specification in this
folder (see [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md),
[WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md), [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md), and
the module `*_SERVICE.md` documents) specializes the patterns established here; where a per-service
doc is silent, this document governs.

The backend exists to enforce three properties that no client may be trusted to enforce on its own:
**correctness** (double-entry accounting always balances, money never double-posts, records are
immutable once posted), **isolation** (company A can never observe or mutate company B's data), and
**authority** (every action is authenticated, authorized against RBAC, validated, and audited). The
authoritative stack is fixed by [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) and is
not open to substitution here: Laravel 12 on PHP 8.4+, PostgreSQL as the primary database, Redis for
cache/session/queue, Cloudflare R2 for object storage, Laravel Reverb for realtime WebSockets, and
Laravel Sanctum (bearer token / JWT) for authentication. The AI engine is a **separate FastAPI
service** the backend calls over HTTP and queues — never an in-process library, and never a
component with direct database access.

This document does not restate business rules that belong to individual accounting modules (those
live in [../accounting/](../accounting/GENERAL_LEDGER.md)), nor the wire contract of the REST API
(that is [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md)). It describes the *shape of the
Laravel application* that implements all of them.

# Architectural Principles

The backend is built on a small set of non-negotiable principles, each traceable to a foundation
document.

1. **Backend is the single source of truth.** Per
   [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md), no other component
   touches PostgreSQL directly. The web client, mobile client, integrations, and the AI engine are
   all callers of `/api/v1`. This is what allows one OpenAPI contract to drive four SDKs and one set
   of business invariants to hold regardless of who initiated a request.
2. **Layered within each module.** Every module follows Presentation → Application → Domain →
   Infrastructure, exactly as mandated by
   [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md). Controllers are
   thin; business logic lives in Actions and Services; persistence lives behind models and
   repositories.
3. **Modules are independent and event-connected.** A module never reaches into another module's
   tables or classes. Cross-module effects travel through domain events, queued listeners, and the
   internal API — never through a direct database write.
4. **Default-deny everywhere.** Every request passes authenticate → authorize (RBAC, company-scoped)
   → validate before any Service runs. The permission grammar is `<area>.<action>` and
   `<area>.<entity>.<action>` per [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md).
5. **Tenant scope is ambient and enforced, not passed.** The active company arrives as the
   `X-Company-Id` header, is bound once per request by the `ResolveTenantCompany` middleware, and is
   applied automatically to every query by the `CompanyScope` global scope (added via the
   `BelongsToCompany` trait) plus PostgreSQL row-level security (`SET LOCAL app.current_company_id`).
   Controllers never hand-filter by `company_id` for isolation. See
   [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md).
6. **Async by default for heavy work.** OCR, report generation, bulk import, AI analysis, webhook
   delivery, and notifications run on queues, never inline in the request that triggered them.
7. **Everything is auditable.** Every state transition writes an immutable audit record and, where
   relevant, broadcasts a realtime event.

# System Context — Where the Backend Sits

```
        Web (Next.js 15)     Mobile (Flutter)     Integrations / Partner API
                 \                  |                        /
                  \                 |                       /
                   ▼                ▼                      ▼
        ┌───────────────────────────────────────────────────────────┐
        │                 QAYD Backend  (Laravel 12)                 │
        │   /api/v1  —  the ONLY write path to business state        │
        │                                                            │
        │   Middleware → Controller → FormRequest → Action/Service   │
        │        → Model/Repository → Resource → Envelope            │
        │                                                            │
        │   Events ─▶ Queued Listeners ─▶ Jobs                       │
        │   Broadcasting ─▶ Reverb        Scheduler ─▶ Jobs          │
        └───────┬───────────────┬───────────────┬───────────┬───────┘
                │               │               │           │
                ▼               ▼               ▼           ▼
          PostgreSQL         Redis          Cloudflare    FastAPI
        (source of truth)  (cache/queue/     R2         AI Engine
                            session/locks) (objects)   (HTTP + queue,
                                                        NO DB access)
```

The FastAPI AI engine is drawn as a peer, not a layer: the backend calls it, and it calls the
backend back through the internal API for any read or write it needs. The AI engine holds no
authoritative state. Realtime updates flow one way — backend → Reverb → clients — over
company-scoped private channels.

# Layered Structure Inside the Application

The Laravel `app/` tree follows [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md).
Each namespace maps onto one of the four architectural layers.

| Layer | Directories | Responsibility |
|---|---|---|
| Presentation | `Http/Controllers`, `Http/Requests`, `Http/Middleware`, `Http/Resources` | HTTP entry, header/permission gates, validation, envelope shaping. No business rules. |
| Application | `Actions`, `Services`, `Jobs`, `Listeners`, `Console` | Orchestrates a use case: transactions, cross-model coordination, event emission, queue dispatch. |
| Domain | `Models`, `Enums`, `Domain/*` value objects, `Policies` | Business entities, invariants, state machines, authorization rules. |
| Infrastructure | `Repositories`, `Support/AiEngine`, `Notifications`, `Mail`, `Observers`, `Providers` | Persistence, external services (AI engine client, R2, mail/SMS gateways), framework wiring. |

Modules are physical groupings that cut across these namespaces. A module such as `Sales` owns its
controllers, requests, actions, services, models, policies, events, listeners, jobs, and resources.
The canonical module prefixes — matching the API route files — are `accounting`, `sales`,
`purchasing`, `banking`, `inventory`, `payroll`, `tax`, `reports`, `ai`, `settings`, `identity`
(users/roles/permissions), and `company` (companies/branches/departments).

```
app/
  Http/
    Controllers/Api/V1/{Module}/...Controller.php
    Requests/{Module}/...Request.php
    Middleware/ResolveTenantCompany.php
    Middleware/EnforceIdempotency.php
    Middleware/CheckPermission.php
    Resources/{Module}/...Resource.php
  Actions/{Module}/...Action.php
  Services/{Module}/...Service.php
  Repositories/{Module}/...Repository.php
  Models/{Module}/....php
  Policies/{Module}/...Policy.php
  Events/{Module}/....php
  Listeners/{Module}/....php
  Jobs/{Module}/....php
  Enums/....php
  Support/AiEngine/AiEngineClient.php
  Providers/...ServiceProvider.php
routes/
  api.php              # loads modular route files, all under /api/v1
  channels.php         # Reverb private channel authorization
  console.php          # scheduler + artisan commands
  health.php           # liveness/readiness probes
config/
  ai.php accounting.php banking.php permissions.php ...
```

# The Request Lifecycle

Every authenticated `/api/v1` request follows one fixed pipeline. The steps are the same whether the
caller is the web app, the mobile app, an integration, or the AI engine acting on a user's behalf.

```
HTTP request
   │
   ▼
[1] Global middleware        TrustProxies, HandleCors, TrimStrings, JSON force-accept
   │
   ▼
[2] Auth (Sanctum)           auth:sanctum — verify bearer token; resolve $request->user()
   │
   ▼
[3] ResolveTenantCompany     read X-Company-Id (UUID); assert company_users membership;
   │                          bind tenant.company_id; SET LOCAL app.current_company_id (RLS)
   ▼
[4] Rate limiting            Redis throttle keyed by token+company+route tier
   │
   ▼
[5] Controller method        resolves via route-model binding (already tenant-scoped)
   │
   ▼
[6] Authorization            $this->authorize('sales.invoice.create', ...) → Policy → Gate
   │
   ▼
[7] FormRequest validation   typed rules; returns 422 envelope on failure
   │
   ▼
[8] Action / Service         DB::transaction { domain work; post ledger; emit events }
   │
   ▼
[9] Model / Repository       Eloquent under BelongsToCompany global scope
   │
   ▼
[10] API Resource            maps model → JSON; money as strings, bilingual name_en/name_ar
   │
   ▼
[11] Envelope + headers      {success,data,message,errors,meta,request_id,timestamp}
   │                          + X-Request-Id, X-RateLimit-*, Location/ETag as applicable
   ▼
HTTP response
```

Steps 2, 3, 6, and 7 are the four gates. A request that fails any gate never reaches a Service, so
business logic can assume it always runs for an authenticated, authorized, validated, tenant-scoped
caller. The envelope and status-code semantics are defined authoritatively in
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md); the backend's job is to produce that shape
identically for every module.

## Controllers Are Thin

A controller does four things and nothing else: authorize, hand the validated request to an
Action/Service, wrap the result in a Resource, and return. It contains no queries, no calculations,
no ledger logic.

```php
namespace App\Http\Controllers\Api\V1\Sales;

use App\Actions\Sales\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreInvoiceRequest;
use App\Http\Resources\Sales\InvoiceResource;

final class InvoiceController extends Controller
{
    public function store(
        StoreInvoiceRequest $request,
        CreateInvoiceAction $action,
    ): InvoiceResource {
        // Authorization already asserted in the FormRequest::authorize(); do the work.
        $invoice = $action->execute(
            InvoiceData::fromRequest($request),   // DTO — see SERVICE_ARCHITECTURE.md
            $request->user(),
        );

        return (new InvoiceResource($invoice->load('items')))
            ->additional(['message' => __('sales.invoice.created')]);
    }
}
```

The `->additional(['message' => ...])` and the global response macro cooperate so that the
Resource's array output is wrapped in the platform envelope; the controller never assembles
`success`/`errors`/`meta` by hand.

## FormRequests Own Validation and Endpoint-Level Authorization

```php
namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canInCompany('sales.invoice.create');
    }

    public function rules(): array
    {
        return [
            'customer_id'              => ['required', 'integer', 'exists:customers,id'],
            'branch_id'                => ['required', 'integer', 'exists:branches,id'],
            'currency_code'            => ['required', 'string', 'size:3'],
            'issue_date'               => ['required', 'date'],
            'due_date'                 => ['required', 'date', 'after_or_equal:issue_date'],
            'invoice_items'            => ['required', 'array', 'min:1'],
            'invoice_items.*.product_id'  => ['required', 'integer', 'exists:products,id'],
            'invoice_items.*.quantity'    => ['required', 'decimal:0,4', 'gt:0'],
            'invoice_items.*.unit_price'  => ['required', 'decimal:0,4', 'gte:0'],
            'invoice_items.*.tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id'],
        ];
    }
}
```

Because the `ResolveTenantCompany` middleware has already bound the tenant, `exists:customers,id`
resolves only against the active company's rows (the global scope applies to the validator's query
too, via a company-aware `exists` rule). A `customer_id` from another tenant fails validation as
"does not exist," never leaking that the row exists elsewhere.

# Module Boundary Map

Modules are the unit of ownership. The map below is the canonical division; it mirrors the module
prefixes in the API and the future `modules/` layout in
[../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md). Each module owns its tables
and exposes its capabilities exclusively through its API endpoints and its domain events.

| Module | Owns (representative tables) | Emits (representative events) | Consumes |
|---|---|---|---|
| `accounting` | `accounts`, `journal_entries`, `journal_lines`, `ledger_entries`, `fiscal_periods` | `journal.posted`, `journal.reversed`, `period.closed` | events from every module that posts to the ledger |
| `sales` | `customers`, `invoices`, `invoice_items`, `receipts` | `invoice.created`, `invoice.posted`, `invoice.paid` | `payment.received` (banking) |
| `purchasing` | `vendors`, `purchase_orders`, `bills`, `vendor_payments` | `bill.approved`, `bill.paid`, `po.matched` | `goods.received` (inventory) |
| `banking` | `bank_accounts`, `bank_transactions`, `transfers`, `reconciliations` | `payment.received`, `bank.synced`, `transfer.completed` | `invoice.posted`, `bill.approved` |
| `inventory` | `products`, `warehouses`, `stock_movements`, `stock_adjustments` | `stock.adjusted`, `goods.received`, `stock.low` | `invoice.posted`, `po.received` |
| `payroll` | `employees`, `payroll_runs`, `payslips` | `payroll.calculated`, `payroll.released` | approval decisions |
| `tax` | `tax_codes`, `tax_returns`, `tax_lines` | `tax.return.submitted` | `invoice.posted`, `bill.approved` |
| `reports` | `report_definitions`, `report_runs` | `report.generated` | reads across modules (query-only) |
| `ai` | `ai_conversations`, `ai_messages`, `ai_proposals`, `ai_runs` | `ai.proposal.created`, `ai.finished` | every module (as tools) |
| `identity` | `users`, `roles`, `permissions`, `company_users` | `user.invited`, `role.assigned` | — |
| `company` | `companies`, `branches`, `departments` | `company.created`, `branch.created` | — |
| `workflow` (cross-cutting) | `approvals`, `approval_steps`, `approval_decisions` | `approval.requested`, `approval.decided`, `approval.escalated` | actions from any module that needs sign-off |
| `automation` (cross-cutting) | `automation_rules`, `automation_runs` | `automation.executed`, `automation.failed` | any triggering event |

The `workflow` and `automation` capabilities are cross-cutting engines rather than accounting
domains; they are specified in [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md) and
[AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md). They observe module events and drive module actions,
but they still respect module boundaries: they invoke a module's own Action to commit an effect,
never write another module's tables directly.

## Inter-Module Communication

The only three sanctioned channels between modules are **events**, **queues**, and the **internal
API**. A concrete example — an invoice being posted — shows all three staying inside their lanes:

```
POST /api/v1/sales/invoices/{id}/post
   │  Sales\PostInvoiceAction (DB::transaction)
   │    - transitions invoice draft → posted
   │    - dispatches InvoicePosted event
   ▼
event(new InvoicePosted($invoice))
   ├─▶ Accounting\CreateJournalForInvoice (queued listener)
   │       calls Accounting\PostJournalEntryAction — accounting owns the ledger write
   ├─▶ Inventory\ReduceStockForInvoice (queued listener)
   │       calls Inventory\AdjustStockAction — inventory owns the stock write
   ├─▶ Reports\InvalidateReportCache (queued listener)
   └─▶ Broadcasting: InvoicePosted implements ShouldBroadcast
           → Reverb → private-company.{id} channel → clients refresh
```

Sales never inserts a `journal_line`; it emits an event and Accounting reacts. This is what keeps
modules replaceable: swapping the inventory implementation changes only the listener body, not the
Sales action. The pattern is elaborated in [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md).

# One API, Many Surfaces

The web, mobile, and partner surfaces all hit the same `/api/v1` contract. There is no separate
"mobile API" or "web API" with divergent shapes — divergence would fork the business rules. The
surfaces differ only in three controlled dimensions, none of which changes what the endpoint does:

| Surface | Auth token | `X-Client-Platform` | Rate-limit tier | Notes |
|---|---|---|---|---|
| Web (Next.js) | Sanctum token (httpOnly cookie-issued) | `web` | standard | SSR/BFF may proxy, but calls the same paths. |
| Mobile (Flutter) | Sanctum personal access token | `mobile-ios` / `mobile-android` | standard | Same envelope; offline queue replays with `Idempotency-Key`. |
| Partner / Integration | API key → scoped token | `integration` | partner tier | Key carries a restricted permission set (e.g. invoices + customers, no payroll). See [../api/PARTNER_API.md](../api/PARTNER_API.md). |
| AI engine (on behalf of user) | short-lived internal service token bound to the user+company | `integration` | internal tier | Calls the internal API; still permission-checked as the acting user. See [../api/INTERNAL_API.md](../api/INTERNAL_API.md). |

`X-Client-Platform` is used only for analytics and rate-limit tiering — never for an authorization
decision. The permission the caller holds, not the surface they came from, decides what they can do.

# The AI-Engine Boundary

The FastAPI AI engine is a separate service, and the boundary between it and the backend is one of
the most important invariants in the platform: **the AI engine never edits the database directly;
every write passes through the backend API, and every read the AI engine performs is permission-
checked as the acting user** (per SYSTEM_ARCHITECTURE and PERMISSION_SYSTEM).

The backend talks to the AI engine in two directions:

1. **Backend → AI engine** for reasoning: OCR/document extraction, chat, financial analysis,
   forecasting, report narration, and automation suggestions. Synchronous, latency-bounded calls
   (a chat turn) go over HTTP; heavy work (bulk OCR, long analyses) is dispatched as a queued job
   that calls the engine and stores the result, returning `202 Accepted` to the client per REST
   standards.
2. **AI engine → backend** for data and effects: when an agent needs to read a customer's balance or
   propose a journal entry, it calls the backend's internal API with a short-lived service token
   scoped to the acting user and company. A proposed write does not commit — it lands as an
   `ai_proposal` that a human approves through the workflow engine.

```php
namespace App\Support\AiEngine;

final class AiEngineClient
{
    public function __construct(
        private readonly \Illuminate\Http\Client\Factory $http,
    ) {}

    /** Synchronous reasoning call, latency-bounded, correlation-preserving. */
    public function analyze(string $capability, array $payload, RequestContext $ctx): AiResult
    {
        $response = $this->http
            ->baseUrl(config('ai.engine_base_url'))          // AI_ENGINE_BASE_URL
            ->withToken($this->mintServiceToken($ctx))        // scoped to user+company
            ->withHeaders([
                'X-Company-Id' => (string) $ctx->companyId,
                'X-Request-Id' => $ctx->requestId,            // same correlation id end-to-end
            ])
            ->timeout(config('ai.timeout_seconds', 20))
            ->retry(2, 250, throw: false)
            ->post("/v1/{$capability}", $payload);

        if ($response->failed()) {
            // AI-only endpoints surface 503; AI-optional paths degrade to null. See REST_STANDARDS.
            throw new AiEngineUnavailableException($capability, $response->status());
        }

        return AiResult::fromResponse($response->json());
    }
}
```

The full request/response contract, service-token minting, and the proposal lifecycle are the
subject of [../api/INTERNAL_API.md](../api/INTERNAL_API.md), [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md),
and [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md). The architectural point stands: the AI engine is a
consultant, not an owner.

# Realtime — Laravel Reverb

Realtime is one-directional and company-scoped. The backend broadcasts domain events over Laravel
Reverb (WebSockets); clients subscribe and re-render. Reverb is used for notifications, AI status,
live accounting figures, and the live dashboard, per TECH_STACK.

Channels are always private and always tenant-scoped so a socket for company A can never receive
company B's traffic:

| Channel | Purpose | Authorized when |
|---|---|---|
| `private-company.{companyId}` | General company stream: posted documents, dashboard deltas, `bank.synced`. | user ∈ `company_users` for `{companyId}` |
| `private-company.{companyId}.ai` | AI run status, streaming tokens, proposal-created events. | user holds `ai.chat` in `{companyId}` |
| `private-company.{companyId}.approvals` | Approval requested/decided/escalated for approvers. | user is an eligible approver in `{companyId}` |
| `private-user.{userId}` | Personal notifications and DMs. | authenticated user == `{userId}` |

Channel authorization lives in `routes/channels.php` and re-runs the same RBAC checks as the HTTP
layer — the socket handshake is not a bypass:

```php
Broadcast::channel('company.{companyId}.approvals', function ($user, int $companyId) {
    return $user->belongsToCompany($companyId)
        && $user->canInCompany('approvals.review', $companyId);
});
```

An event opts into broadcasting by implementing `ShouldBroadcast` and declaring its channel and
payload; the payload is a compact projection (ids + status), never a full record, so the client
fetches details through the permission-checked API. The workflow and AI channels are used heavily by
[WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md).

# Queues, Jobs, and the Scheduler

Redis backs the queue. Heavy or fan-out work never runs in the request thread. The backend uses
named queues so latency-sensitive work is never starved by bulk work.

| Queue | Workload | Priority |
|---|---|---|
| `realtime` | broadcasts, notification dispatch | highest |
| `default` | queued domain-event listeners (ledger posting, stock updates) | high |
| `ai` | AI-engine calls, OCR, document extraction, proposal generation | medium |
| `reports` | report generation, exports, large aggregations | medium |
| `integrations` | bank sync, webhook delivery, ERP/POS push | low |
| `maintenance` | archival, cache warming, scheduled recomputation | lowest |

Jobs are idempotent and safe to retry. Money-affecting jobs carry a natural idempotency key (e.g. the
source document id + operation) and check-then-act inside a transaction so a retried job never
double-posts. Failed jobs land on `failed_jobs` with the correlation `request_id` for replay.

The scheduler (`routes/console.php`, run by a single `schedule:run` cron) drives recurring work:
period-close reminders, tax-deadline checks, recurring-invoice generation, automation time-triggers
(see [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md)), SLA/escalation sweeps for pending approvals
(see [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md)), and nightly reconciliation of AI run costs.

```php
// routes/console.php
Schedule::job(new SweepApprovalSlas)->everyFiveMinutes()->onQueue('default');
Schedule::job(new RunTimeTriggeredAutomations)->everyMinute()->onQueue('default');
Schedule::job(new GenerateRecurringInvoices)->dailyAt('01:00')->onQueue('default');
Schedule::job(new ReconcileAiRunCosts)->dailyAt('02:30')->onQueue('maintenance');
```

# Caching

Redis is the cache tier. The backend caches only data that is safe to cache and always keys the cache
by company so no cross-tenant bleed is possible. Per REST standards, responses containing balances or
PII are `no-store`; reference data (account types, tax codes, currencies) is cacheable.

- **Company-keyed tags.** Cache keys are prefixed `company:{id}:...`; invalidation is by tag so
  posting a journal entry can flush `company:{id}:trial-balance` without touching other tenants.
- **Read-through for reference data.** Chart-of-accounts metadata, permission matrices, and currency
  tables are cached read-through with event-driven invalidation (a `role.updated` event flushes the
  permission cache for affected users).
- **Never cache authorization outcomes across requests** in a way that outlives a permission change;
  the permission cache is invalidated on any role/permission mutation.
- **Locks.** Redis atomic locks (`Cache::lock`) serialize risk-sensitive sections such as invoice-
  number allocation and reconciliation, complementing database transactions.

# Configuration and Environment

Configuration lives in `config/*.php` (see PROJECT_STRUCTURE): `ai.php`, `accounting.php`,
`banking.php`, `inventory.php`, `payroll.php`, `security.php`, `permissions.php`,
`notifications.php`, `storage.php`, `integrations.php`. Secrets never live in config files or the
repo; they come from environment variables injected at deploy time.

Key environment variables the backend depends on:

| Variable | Purpose |
|---|---|
| `APP_KEY`, `APP_URL` | Laravel app key and canonical URL. |
| `DB_*` | PostgreSQL connection (primary + read replica DSNs). |
| `REDIS_*` | Cache/session/queue/lock connection. |
| `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis` | Drivers. |
| `REVERB_*` | Reverb app id/key/secret and host for broadcasting. |
| `AI_ENGINE_BASE_URL` | Base URL of the FastAPI AI engine. |
| `AI_ENGINE_SERVICE_KEY` | Signing key for minting scoped internal service tokens. |
| `R2_*` / `AWS_*` | Cloudflare R2 (or S3) bucket, keys, region for object storage. |
| `MAILGUN_*` / `SES_*`, `TWILIO_*` | Email and SMS gateways. |
| `SENTRY_DSN` | Error tracking. |

Config is cached in production (`php artisan config:cache`); no code reads `env()` outside config
files, so cached config is authoritative.

# Deployment Topology

Per TECH_STACK, development runs on Hetzner and production on AWS behind Cloudflare (CDN, DNS, WAF,
DDoS). The backend deploys as several roles from one codebase, scaled independently:

```
                Cloudflare (CDN / WAF / DDoS / TLS)
                              │
                     ┌────────┴────────┐
                     ▼                 ▼
                Load Balancer     Reverb LB (WebSocket)
                     │                 │
        ┌────────────┼───────────┐     │
        ▼            ▼           ▼     ▼
   web dyno     web dyno    web dyno   reverb node(s)   ← stateless HTTP + WS
   (php-fpm)    (php-fpm)   (php-fpm)
        │            │           │
        └────────────┼───────────┘
                     ▼
        ┌───────────────────────────────┐
        │  queue workers (per queue)     │  ← horizontally scaled by queue
        │  realtime | default | ai |     │
        │  reports | integrations | maint│
        └───────────────────────────────┘
                     │
         scheduler node (single schedule:run cron)
                     │
     ┌───────────────┼──────────────┬──────────────┐
     ▼               ▼              ▼              ▼
 PostgreSQL      Redis          R2/S3          FastAPI AI
 primary +     (cluster)       (objects)       engine (own
 read replica                                   service + scale)
```

Roles and scaling rules:

- **Web dynos** (php-fpm behind nginx) are stateless; sessions/cache/locks live in Redis, so any dyno
  can serve any request. Scale on request latency and CPU.
- **Queue workers** are scaled per queue; the `ai` and `reports` queues scale on backlog depth
  independently of `realtime`, which must stay near-empty.
- **Reverb nodes** hold WebSocket connections and are scaled on concurrent-connection count; they
  read broadcast payloads from Redis.
- **Scheduler** runs on exactly one node to avoid duplicate cron firing; scheduled work is itself
  dispatched to queues.
- **PostgreSQL** runs primary + read replica; reporting reads route to the replica, all writes to the
  primary. Multi-region is a future step (TECH_STACK: "Multi-region architecture").
- **The AI engine** is deployed and scaled as its own service; the backend depends on it only through
  the HTTP client and the `ai` queue, so an AI outage degrades AI features (`503`/graceful null) but
  never blocks core accounting writes.

CI/CD is GitHub Actions: PHPUnit/Pest test suites, PHPStan static analysis, and Laravel Pint style
gate on every PR; migrations run as a gated release step against the primary before new code takes
traffic.

# Observability

The backend is instrumented so any request can be traced end-to-end by its `request_id`:

- **Correlation.** The `X-Request-Id` (echoed as envelope `request_id`) is attached to every log
  line, every queued job, every AI-engine call, and every webhook delivery, so one id follows a
  request across the backend, the queues, and the FastAPI engine.
- **Errors.** Unhandled exceptions go to Sentry with the `request_id`, company id (never PII payload),
  and route; the client receives a generic `500` message per REST standards.
- **Metrics.** Prometheus scrapes request latency, queue depth per queue, job runtime, DB pool usage,
  AI-engine call latency/error rate, and broadcast throughput; Grafana dashboards visualize them.
- **Health.** `routes/health.php` exposes liveness (process up) and readiness (DB, Redis, R2, and AI-
  engine reachability) probes for the load balancer and orchestrator.
- **Audit vs logs.** Operational logs are for engineers and are ephemeral; the audit trail (see
  [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)) is durable, tamper-evident
  business history and is written inside the same transaction as the state change it records.

# Error Handling

A single exception handler maps typed exceptions to the platform envelope and the correct status
code, so no controller assembles error responses by hand:

| Exception | Status | Envelope `errors[].code` |
|---|---|---|
| `AuthenticationException` | 401 | `unauthenticated` |
| `AuthorizationException` / policy denial | 403 | `permission_denied` |
| `ModelNotFoundException` (incl. cross-tenant) | 404 | `not_found` |
| `ValidationException` (FormRequest) | 422 | field-level codes (`required`, `balance_mismatch`, …) |
| `InvalidStateTransitionException` | 409 | `invalid_state_transition` |
| `IdempotencyKeyReusedException` | 409 | `idempotency_key_reused` |
| `OptimisticLockException` | 409 | `version_conflict` |
| `AiEngineUnavailableException` | 503 | `ai_engine_unavailable` |
| unhandled `Throwable` | 500 | `internal_error` (generic, non-leaking) |

Cross-tenant access resolves to `404`, never `403`, so existence never leaks across companies (REST
standards). Domain invariants (double-entry balance, immutability of posted records) throw typed
domain exceptions that the handler renders as `422`/`409` — the invariant is enforced in the Domain
layer, not the controller.

# Testing

Testing follows TECH_STACK (PHPUnit, Pest) and the layering above:

- **Unit tests** cover Domain-layer invariants and Services in isolation (a journal entry rejects an
  unbalanced set of lines; a state machine refuses an illegal transition).
- **Feature tests** drive real `/api/v1` requests through the full middleware pipeline, asserting the
  envelope shape, status codes, and — critically — **tenant isolation**: every module has a test that
  a token for company A receives `404` for company B's resource.
- **Permission tests** assert default-deny: an endpoint refuses a caller lacking the exact
  `<area>.<entity>.<action>` permission.
- **Contract tests** validate responses against the generated OpenAPI schema so the four SDKs never
  drift.
- **Queue/event tests** assert that posting an invoice dispatches the expected events and that
  listeners are idempotent under retry.

Every feature is considered incomplete until it ships with database migration, API, permissions,
audit logging, AI support, documentation, and tests — the release checklist from MODULE_ARCHITECTURE.

# Related Documents

- [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) — the Action/Service pattern every module instantiates.
- [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md) — approval chains, two-key sign-off, AI-proposal commit.
- [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md) — trigger→condition→action rules and guardrails.
- [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md), [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md), [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md), [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)
- [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md), [../api/INTERNAL_API.md](../api/INTERNAL_API.md)
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)
- [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md)

# End of Document
