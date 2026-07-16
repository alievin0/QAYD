# API Versioning — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API Versioning
---

# Purpose

QAYD is an AI Financial Operating System. Its Laravel 12 (PHP 8.4+) backend is the single source of
truth for every accounting, sales, purchasing, banking, inventory, payroll, and tax fact in the
system. Three categories of clients depend on that backend never breaking under them without notice:

1. **First-party clients** — the Next.js 15 web app and the Flutter mobile app, both shipped and
   updated on QAYD's own release cadence.
2. **The AI engine** — FastAPI/Python services (General Accountant, Auditor, Tax Advisor, Payroll
   Manager, Inventory Manager, Treasury Manager, CFO, Fraud Detection, Reporting Agent, Document AI,
   OCR Agent, Forecast Agent, Compliance Agent, Approval Assistant) that call the API exactly like any
   other client — authenticated, authorized, validated — and never touch the database directly.
3. **Third-party integrations** — accountants' own tooling, bank connectors, government e-invoicing
   gateways, and partner systems that consume the QAYD API on their own upgrade schedule, sometimes
   for years without a code change.

This document defines how QAYD's REST API evolves over time without breaking any of the three. It
specifies the versioning scheme, what counts as a breaking versus non-breaking change, how versions
are deprecated and eventually retired, how multiple versions run in production simultaneously, how
changes are communicated, how SDKs stay aligned with server versions, and how the engineering
organization tests across versions. It is binding on every endpoint under `/api/v1/` and every future
`/api/v2/`, `/api/v3/`, etc. Any endpoint, controller, FormRequest, or OpenAPI contract that violates
this policy is a defect, not a design choice — reviewers must block it in code review.

The guiding principle, borrowed from Stripe and Twilio's public API practice: **a client that
integrated against QAYD once and never touched its code again should keep working for the
contractually stated minimum lifetime of that API version.** Financial software is held to a higher
bar than consumer software — an accountant's month-end close script, a bank reconciliation cron job,
or a government tax-filing integration cannot be allowed to fail because QAYD shipped a field rename.

# Versioning Strategy

QAYD versions its API at the **URI path level**, under the `/api/v1/` prefix, as fixed by the platform
convention (see `DESIGN_CONTEXT.md` §5). This is a deliberate choice over header-based or
content-negotiation versioning:

| Scheme | Chosen? | Why |
|---|---|---|
| URI path (`/api/v1/...`) | Yes | Visible in logs, curl history, browser network tabs, Postman collections, and error messages. Zero ambiguity about which contract a request used. Cacheable per-version at the CDN/reverse-proxy layer. Matches Stripe/Twilio/GitHub practice that QAYD's integration partners already expect. |
| Header (`Accept: application/vnd.qayd.v1+json`) | No | Invisible in most debugging tools; easy to omit by accident and silently fall back to a default version, which is unacceptable for financial data. |
| Query parameter (`?version=1`) | No | Trivially dropped by proxies/caches; not RESTful; encourages accidental version mixing within a single client session. |

**Version identifier format:** a bare major integer prefixed with `v` — `v1`, `v2`, `v3`. QAYD does
NOT expose minor/patch numbers in the URI (`v1.2` is not a thing). Non-breaking changes ship
continuously into the current major version without changing the path. Only a breaking change
justifies incrementing the major version.

**Full path shape:**

```
https://api.qayd.com/api/{version}/{module}/{resource}
```

Examples, using the canonical tables and modules already fixed by the platform:

```
GET  /api/v1/accounting/journal-entries
POST /api/v1/sales/invoices
POST /api/v1/bank/transfers
GET  /api/v1/inventory/stock-adjustments
POST /api/v1/payroll/runs/{id}/approve
```

**Where the version lives in code.** The version segment maps directly to a Laravel route group and
namespace, never to a query flag or a runtime `if` branch scattered through shared controllers:

```php
// routes/api.php
Route::prefix('v1')->name('v1.')->group(function () {
    require base_path('routes/v1/accounting.php');
    require base_path('routes/v1/sales.php');
    require base_path('routes/v1/purchasing.php');
    require base_path('routes/v1/bank.php');
    require base_path('routes/v1/inventory.php');
    require base_path('routes/v1/payroll.php');
    require base_path('routes/v1/tax.php');
    require base_path('routes/v1/reports.php');
});

Route::prefix('v2')->name('v2.')->group(function () {
    require base_path('routes/v2/accounting.php');
    // v2 only defines the routes/controllers that actually differ from v1;
    // unchanged v1 controllers are reused directly (see "Multiple Live Versions").
});
```

Controllers, FormRequests, and API Resources are namespaced per version:
`App\Http\Controllers\Api\V1\Accounting\JournalEntryController`,
`App\Http\Requests\Api\V1\Accounting\StoreJournalEntryRequest`,
`App\Http\Resources\Api\V1\Accounting\JournalEntryResource`. Services and Repositories are NOT
versioned — business logic lives once in `App\Services\Accounting\JournalEntryService` and is called
by every version's controller. Only the request/response *shape* is versioned; the underlying rules
(double-entry balancing, permission checks, multi-tenant isolation) never diverge between API
versions, because financial correctness cannot depend on which contract a client happened to call.

**Root discovery endpoint.** `GET /api/version` (unversioned, always available) reports which
versions are live and their lifecycle state, so tooling can self-check before use:

```json
{
  "success": true,
  "data": {
    "current": "v1",
    "versions": [
      { "version": "v1", "status": "current", "released_at": "2026-01-15", "sunset_at": null }
    ]
  },
  "message": "Available API versions",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b6b6a3b0-6e2e-4c39-9e2a-2f7b6a9d3a11",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

# Breaking vs Non-Breaking Changes

Every proposed API change is classified before it is coded. The classification decides whether the
change ships into the current version immediately (non-breaking) or requires a new major version
(breaking). This table is the canonical reference used in pull-request review templates.

| Change | Breaking? | Rationale |
|---|---|---|
| Add a new endpoint | No | No existing client path is affected. |
| Add a new optional request field | No | Old clients omit it; server applies a default. |
| Add a new field to a response object | No | Clients must ignore unknown fields per §"Additive Change Rules". |
| Add a new enum value to an existing enum field | **No, but treated as breaking for strict enum consumers** — see Edge Cases | Old clients that switch/case on enum values may hit a default branch; this is the single most common source of silent client breakage and is called out separately below. |
| Add a new optional query parameter | No | Ignored by clients that don't send it. |
| Add a new webhook event type | No | Clients subscribe to event types explicitly; new types are simply not delivered to old subscriptions. |
| Rename a field (request or response) | **Yes** | Old client code reading/writing the old key breaks immediately. |
| Remove a field (request or response) | **Yes** | Any client depending on it breaks. |
| Change a field's data type (e.g. string → object, int → string) | **Yes** | Deserialization breaks. |
| Change a field's semantic meaning without changing its name/type | **Yes** | Silent data corruption risk — the worst class of breaking change; must never ship without a version bump, even though it is not statically detectable. |
| Make a previously-optional request field required | **Yes** | Old clients omitting it now get `422`. |
| Change validation to reject previously-accepted values | **Yes** | Old clients relying on lenient validation start failing. |
| Change default sort order, default pagination size, or default filter | **Yes** | Clients relying on implicit ordering silently get wrong results — treated as breaking even though the HTTP contract shape is unchanged. |
| Change the URL path or HTTP method of an endpoint | **Yes** | Direct 404/405. |
| Change the meaning or contents of an existing error code for a given condition | **Yes** | Clients pattern-match on `errors[].code`; changing which code fires for a given failure is breaking. |
| Change the standard envelope shape itself | **Yes** | Affects every client, every endpoint; requires the highest-severity review and typically a full major version. |
| Tighten a rate limit | **No** (operational, not contractual) | Communicated via `Deprecation`-style operational notices, not a version bump, but must go through the change-management process in "Communicating Changes." |
| Fix a bug that a client was unintentionally depending on | **Yes, treated as breaking** | Per Stripe's own written policy, which QAYD adopts verbatim: if fixing a bug changes any documented or observable behavior a client could reasonably rely on, it ships as a breaking change behind a version, not as a silent patch. |
| Change internal implementation with no observable API difference | No | Not visible to any client by definition. |

**Decision rule of thumb:** if there exists any plausible client code (in any of PHP, JS/TS, Python,
Dart) that would throw, silently mis-parse, or silently mis-compute after the change without being
modified itself, the change is breaking. When in doubt, treat it as breaking — the cost of an
unnecessary version bump is far lower than the cost of silently corrupting a customer's ledger.

# Deprecation Policy

QAYD never removes a live API version without a formal deprecation period. Deprecation is
communicated two ways simultaneously: HTTP response headers (machine-readable, present on every
response from a deprecated version) and the published changelog/developer portal (human-readable).

**Headers on every response from a deprecated version:**

| Header | Example value | Meaning |
|---|---|---|
| `Deprecation` | `Deprecation: true` (per IETF draft `Deprecation` header) | This version is deprecated as of now; safe to keep using during the sunset window but must be migrated off. |
| `Sunset` | `Sunset: Wed, 15 Jul 2026 00:00:00 GMT` (RFC 8594 date, HTTP-date format) | Hard date after which this version stops serving requests. |
| `Link` | `Link: <https://docs.qayd.com/api/migration/v1-to-v2>; rel="deprecation"` | Points directly at the migration guide for this transition. |
| `X-Qayd-Api-Version` | `X-Qayd-Api-Version: v1` | Echoes back the version that actually served the request, for client-side logging/alerting. |

Example raw response demonstrating all four during the deprecation window:

```
HTTP/1.1 200 OK
Content-Type: application/json
Deprecation: true
Sunset: Wed, 15 Jul 2026 00:00:00 GMT
Link: <https://docs.qayd.com/api/migration/v1-to-v2>; rel="deprecation"
X-Qayd-Api-Version: v1
X-Request-Id: b6b6a3b0-6e2e-4c39-9e2a-2f7b6a9d3a11

{
  "success": true,
  "data": { "id": 88123, "invoice_number": "INV-2026-00881" },
  "message": "Invoice retrieved",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b6b6a3b0-6e2e-4c39-9e2a-2f7b6a9d3a11",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

Laravel middleware that attaches these headers automatically for any route registered under a
deprecated version group:

```php
// app/Http/Middleware/AnnounceApiDeprecation.php
class AnnounceApiDeprecation
{
    private const SUNSET = [
        'v1' => null, // not yet scheduled
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $version  = $request->route()->getPrefix(); // e.g. "api/v1"
        $short    = Str::afterLast($version, '/');   // "v1"

        if ($sunset = config("api.deprecations.{$short}")) {
            $response->headers->set('Deprecation', 'true');
            $response->headers->set('Sunset', $sunset->toRfc7231String());
            $response->headers->set(
                'Link',
                '<https://docs.qayd.com/api/migration/'.$short.'-to-'.config('api.current_version').'>; rel="deprecation"'
            );
        }

        $response->headers->set('X-Qayd-Api-Version', $short);
        return $response;
    }
}
```

**Deprecation timeline (fixed minimums, may be lengthened but never shortened without a documented
regulatory or security exception):**

| Milestone | Timing | What happens |
|---|---|---|
| Announcement | T-0 | New version publicly documented; old version marked `Deprecation: true` starts NOW, `Sunset` header set to T+12 months. Changelog entry + email/dashboard notice to every registered API consumer (identified by API key / OAuth client). |
| Grace period | T-0 to T+12 months | Deprecated version fully functional, receives security patches and critical bug fixes only — no new features. Both versions run in production. |
| Sunset warning escalation | T+9 months | If a client has made any request to the deprecated version in the trailing 30 days, QAYD sends a direct notice (email + in-dashboard banner + webhook `api.version.sunset_warning`) to that client's registered technical contact. |
| Final warning | T+11 months | Daily notice to any still-active deprecated-version client; responses gain an additional `Warning: 299 - "API v1 will stop serving requests on 2027-07-15"` header. |
| Sunset (hard removal) | T+12 months | Deprecated version returns `410 Gone` for every request, with a body explaining the sunset and linking the migration guide. Route group is physically removed from the router only after 410 responses have been observed running clean for 30 days (see Version Lifecycle "Retired" stage). |

Minimum deprecation window is **12 months** for any version that has at least one active third-party
integration; internal-only versions (never published beyond QAYD's own first-party clients) may use a
shorter, explicitly-approved window but never less than **90 days**, matching the minimum release
cadence of the Flutter mobile app through app-store review.

**410 Gone response after sunset:**

```json
{
  "success": false,
  "data": null,
  "message": "API version v1 was sunset on 2027-07-15 and no longer serves requests.",
  "errors": [
    { "code": "api_version_sunset", "detail": "Migrate to v2. See https://docs.qayd.com/api/migration/v1-to-v2" }
  ],
  "meta": { "pagination": null },
  "request_id": "3f9a1e2c-9b7a-4e35-96d2-88b9d9d7ef0c",
  "timestamp": "2027-07-16T09:12:44Z"
}
```

# Version Lifecycle

Every API version moves through exactly five states, tracked in a `api_versions` reference table
(system-level, not tenant-scoped) that both the `/api/version` endpoint and internal dashboards read
from:

```sql
CREATE TABLE api_versions (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    version       VARCHAR(8)  NOT NULL UNIQUE,           -- 'v1', 'v2'
    status        VARCHAR(16) NOT NULL,                  -- see enum below
    released_at   TIMESTAMPTZ NOT NULL,
    deprecated_at TIMESTAMPTZ NULL,
    sunset_at     TIMESTAMPTZ NULL,
    retired_at    TIMESTAMPTZ NULL,
    changelog_url VARCHAR(255) NULL,
    migration_url VARCHAR(255) NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_api_versions_status
        CHECK (status IN ('beta','current','deprecated','sunset','retired'))
);
```

```
   beta ──────► current ──────► deprecated ──────► sunset ──────► retired
    │              │                 │                 │              │
 preview,       fully           still serves,      returns 410,   routes/
 breaking       supported,      Deprecation+       kept for       controllers
 changes OK,    the version     Sunset headers      30-day        physically
 opt-in only    new clients     on every            observation   deleted from
                should target   response            window        the codebase
```

- **beta** — published for early integration partners under a `-beta` suffix
  (`/api/v1-beta/...`) or feature flag; breaking changes are allowed without a major bump and without
  the 12-month notice; not covered by the SLA. Used to validate a new module's contract (e.g. Tax
  e-invoicing) before it becomes `v1`-stable.
- **current** — the version new integrations are told to use. Exactly one version holds this status
  at a time in the "everyone should start here" sense, though multiple versions can be simultaneously
  live (see "Multiple Live Versions"). Full SLA, full support, receives non-breaking additive changes
  continuously.
- **deprecated** — a newer `current` version now exists; this version still fully works, carries the
  `Deprecation`/`Sunset` headers, and only receives security and critical-bug patches.
  Entered automatically the moment a new version is promoted to `current`.
- **sunset** — the `Sunset` date has passed; all endpoints under this version return `410 Gone`.
  Held for a minimum 30-day observation window before physical removal, in case of an emergency
  rollback need for a client that was somehow missed by all prior communication.
  This is a resiliency practice, not a client-facing scheduling change.
  the 30-day flag).
- **retired** — routes, controllers, FormRequests, and Resources for this version are deleted from
  the codebase. OpenAPI spec for the version is archived (not deleted) at
  `docs.qayd.com/api/archive/{version}` for audit/legal reference — financial-record-producing
  endpoints must keep a permanent historical contract record even after the version is gone.

# Client Migration Guides

Every version transition ships a migration guide before the deprecation announcement goes out — never
after. The guide lives at `https://docs.qayd.com/api/migration/{from}-to-{to}` and is linked from the
`Link` deprecation header, so any developer who is paged by the header lands directly on it.

**Required structure of every migration guide** (enforced by a documentation lint check in CI):

1. **Summary table** of every breaking change, one row each: field/endpoint, old behavior, new
   behavior, action required.
2. **Side-by-side request/response diff** for every changed endpoint (old JSON block, new JSON block).
3. **Per-SDK migration snippet** — PHP, JavaScript/TypeScript, Python, Flutter/Dart — showing the
   minimal code change (see "SDK Versioning Alignment").
4. **A compatibility checklist** the integrator can run against their own code (grep-able field
   names, enum values, error codes that changed).
5. **A sandbox toggle** — `X-Qayd-Api-Preview: v2` header usable against the production base URL
   while still on the old integration, so a client can test the new contract with real (sandboxed)
   company data before switching their default version segment in the URL.

Example diff block from the `v1`→`v2` accounting guide (illustrative — `v2` is not yet released; this
is the documented shape the guide will take):

```diff
 POST /api/v1/accounting/journal-entries
 {
   "entries": [
-    { "account_id": 411, "debit": 150.0000, "credit": 0.0000 }
+    { "account_id": 411, "debit_amount": "150.0000", "credit_amount": "0.0000" }
   ]
 }
```
```
Action required: rename `debit`→`debit_amount`, `credit`→`credit_amount`, and send amounts as
strings (not floats) to avoid floating-point rounding on NUMERIC(19,4) columns. v1 continues to
accept the old float shape until sunset on 2027-07-15.
```

# Additive Change Rules

To keep the surface of "breaking" as small as possible, every client SDK and every hand-rolled
integration is contractually required (documented in the API Terms and enforced by SDK design) to:

1. **Ignore unknown JSON fields** in any response object. QAYD SDKs deserialize into structures that
   silently drop unrecognized keys rather than throwing (e.g. PHP DTOs use `#[AllowUnknownProperties]`
   equivalents; TypeScript types use `interface` shapes consumed via safe-parsing, never `strict`
   exhaustive object literals from the wire).
2. **Treat unknown enum values as a generic "other/unknown" bucket**, never as a hard failure. Any
   enum QAYD returns over the API (`invoice.status`, `journal_entries.status`, `payroll_runs.status`,
   etc.) documents an explicit `unknown` fallback case for client `switch` statements.
3. **Never assume field ordering** in a JSON object, or object key enumeration order.
4. **Never assume an array's length or exact members** beyond what the endpoint's documented filters
   guarantee; new optional array items may appear over time (e.g. new webhook event types, new
   report line categories).
5. **Send only the fields you mean to change on PATCH-style updates** — omitted fields are left
   untouched server-side, never reset to null. QAYD's Laravel FormRequests use `sometimes` validation
   rules and Services apply `array_key_exists` (not `!empty`) checks, so a client that starts sending
   an additional optional field later never regresses older callers.

Given these rules, QAYD may ship the following into a `current` version at any time WITHOUT a
deprecation cycle, changelog "breaking" flag, or version bump — only a normal changelog "Added" entry:

- New endpoints, new optional filters/sorts, new optional request fields, new response fields, new
  webhook event types, new permission keys (additively — an existing role's total access never
  shrinks from an additive change), new enum members (with the `unknown` fallback contract from rule 2
  covering any client not yet updated to recognize the new member).

# Multiple Live Versions

QAYD runs more than one major version in production simultaneously for the entire deprecation
window — this is not an edge case, it is the normal steady state. The routing/controller strategy
avoids duplicating business logic:

```
Request → Router (path prefix v1|v2) → Version-specific Controller (thin)
              │
              ▼
      Shared FormRequest validation per version (shape only)
              │
              ▼
      Shared Service layer (ALL business rules — single implementation)
              │
              ▼
      Shared Repository → Eloquent Models → PostgreSQL
              │
              ▼
      Version-specific API Resource (response shape only) ← Response
```

Concretely: `App\Http\Controllers\Api\V2\Sales\InvoiceController` and
`App\Http\Controllers\Api\V1\Sales\InvoiceController` both call the exact same
`App\Services\Sales\InvoiceService::create()`. Only the FormRequest (input shape) and the API Resource
(output shape) differ between versions. This guarantees a debit/credit balance check, a permission
check, or a tax calculation can never silently diverge between what `v1` clients and `v2` clients
experience — the one place financial correctness must never fork.

```php
// app/Http/Controllers/Api/V2/Sales/InvoiceController.php
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function store(StoreInvoiceRequestV2 $request): JsonResponse
    {
        $invoice = $this->invoices->create(
            company: $request->activeCompany(),
            data: $request->toDomainArray()   // V2 FormRequest normalizes v2 shape → shared DTO
        );

        return ApiResponse::success(
            data: new InvoiceResourceV2($invoice),
            message: 'Invoice created'
        );
    }
}
```

**Database compatibility across versions.** Because the database schema is shared by all live
versions, any migration that would break an older version's Resource mapping (e.g. dropping a column
`v1`'s Resource still reads) is prohibited until that version reaches `retired`. Migrations that
rename or drop columns follow an "expand → migrate → contract" pattern: add the new column, backfill,
dual-write during the deprecation window, and only drop the old column after the version reading it
has been retired.

**Infrastructure:** both versions are served by the same Laravel application/deployment (not separate
services) — version is a routing concern, not a deployment concern — which keeps a single Redis
cache, a single queue, and a single Reverb websocket layer consistent for both. Rate limiting
(Redis-backed) is tracked per API key regardless of which version the key calls, so switching versions
never resets or doubles a client's quota.

# Communicating Changes

Every change to a live API version — additive or breaking — is recorded in a public, dated changelog
before it ships, and surfaced through three coordinated machine/human channels:

1. **Changelog** — `https://docs.qayd.com/api/changelog`, one dated entry per change, tagged `Added`,
   `Changed`, `Fixed`, `Deprecated`, or `Removed` (Keep-a-Changelog convention), each entry naming the
   exact endpoint(s)/field(s)/version(s) affected and linking the relevant migration guide when
   applicable. The changelog is generated from the same source that drives the OpenAPI spec diff, so
   it can never drift from the actual shipped contract.
2. **HTTP headers on affected responses** — `Deprecation`, `Sunset`, `Link`, `X-Qayd-Api-Version` as
   defined above, plus `X-Qayd-Changelog: https://docs.qayd.com/api/changelog#2026-11-03-invoice-tax-breakdown`
   attached for 30 days after any additive change to help integrators notice new fields without
   watching the changelog manually.
3. **Webhook + dashboard notice** — `api.version.announced`, `api.version.deprecated`,
   `api.version.sunset_warning`, `api.version.sunset` domain events are emitted to every registered
   webhook endpoint and mirrored as a dashboard banner in the QAYD web app for that company's admins,
   ensuring the message reaches a human even if their integration is unattended.

Example changelog entry:

```
## 2026-11-03
### Added
- `invoices.tax_breakdown` (array) added to invoice response objects in `v1` and `v2`. Non-breaking;
  omit to preserve existing behavior. See GET /api/v1/sales/invoices/{id}.

### Deprecated
- `v1` deprecated. `Sunset: 2027-11-03`. Migrate using
  https://docs.qayd.com/api/migration/v1-to-v2.
```

# SDK Versioning Alignment

QAYD ships and maintains official SDKs for PHP, JavaScript/TypeScript, Python, and Flutter/Dart. Each
SDK's own package version is decoupled from the API's major version number but follows Semantic
Versioning (`MAJOR.MINOR.PATCH`) mapped onto the API lifecycle as follows:

| SDK change | SDK version bump | Trigger |
|---|---|---|
| New optional field/endpoint support (additive API change) | MINOR | Any `Added` changelog entry |
| Bug fix in SDK code only, no API contract change | PATCH | Internal SDK fix |
| SDK begins supporting a new API major version alongside the old one (dual-version SDK) | MINOR | New API version enters `current` |
| SDK drops support for a sunset/retired API version | MAJOR | API version reaches `retired` |
| SDK's default target version changes (e.g. default constructor now targets `v2` instead of `v1`) | MAJOR | API version promoted to `current` and old one enters `deprecated` |

Each SDK exposes an explicit, required version selector at client construction — there is no silent
"use whatever is newest" default, because a silent default is exactly the failure mode this policy
exists to prevent:

```php
// PHP SDK
$qayd = new Qayd\Client(apiKey: $key, apiVersion: 'v1');
```
```typescript
// TypeScript SDK
const qayd = new QaydClient({ apiKey, apiVersion: "v1" });
```
```python
# Python SDK (used by the AI/FastAPI layer)
qayd = QaydClient(api_key=key, api_version="v1")
```
```dart
// Flutter/Dart SDK
final qayd = QaydClient(apiKey: key, apiVersion: ApiVersion.v1);
```

The AI engine's Python client pins its `api_version` in configuration per-environment and is upgraded
deliberately, in its own pull request, at the same cadence as any other integration — the AI layer
receives NO special fast-path or bypass around this policy; per the platform rule, every AI action is
an ordinary authenticated, authorized, validated API call, so it is versioned exactly like the web and
mobile clients.

Each SDK release note explicitly states which API version(s) it targets, e.g. "qayd-php 3.4.0 —
targets API v1 (current) and v2 (beta, opt-in via `apiVersion: 'v2-beta'`)."

# Testing Across Versions

Because multiple versions run in production concurrently, QAYD's Pest/PHPUnit backend test suite and
Vitest/Playwright frontend suite both execute a **version matrix**, not a single pinned target:

1. **Contract tests per version.** Every version's OpenAPI spec is the source of truth for a
   schema-validation test suite (Pest + a JSON-schema assertion helper) that runs on every CI build
   against every live (non-retired) version's routes, asserting the actual response matches the
   documented schema for that version exactly — including that fields removed in a newer version are
   still present in the older one, and vice versa.

   ```php
   // tests/Feature/Api/V1/Contract/InvoiceContractTest.php
   public function test_invoice_response_matches_v1_openapi_schema(): void
   {
       $invoice = Invoice::factory()->for($this->company)->create();

       $response = $this->actingAs($this->user)
           ->withHeader('X-Company-Id', $this->company->id)
           ->getJson("/api/v1/sales/invoices/{$invoice->id}");

       $response->assertJsonSchema('v1/invoice.schema.json'); // custom Pest macro
   }
   ```

2. **Cross-version regression suite.** A dedicated suite issues the SAME logical operation (e.g.
   "create an invoice with two line items") against every live version in the same test run and
   asserts the underlying database state (journal entries posted, stock decremented, AR balance
   increased) is byte-identical regardless of which version's shape was used to trigger it — this is
   the concrete proof that the shared-Service-layer architecture in "Multiple Live Versions" actually
   holds.

3. **Deprecated-version header tests.** Assert every route under a `deprecated`-status version emits
   `Deprecation`, `Sunset`, and `Link` headers with the exact values recorded in `api_versions`; assert
   a `sunset`-status version returns `410` with the documented error code for every route, including
   ones added to newer versions but intentionally absent from the old one (should 404, not 410,
   since it never existed under that version).

4. **SDK compatibility CI.** Each of the four official SDKs runs its own integration test suite
   nightly against a staging deployment that has both the `current` and `deprecated` versions live,
   using the SDK's pinned `apiVersion` — catching the case where a server-side additive change
   accidentally becomes non-additive in practice (e.g. a "new optional field" that a Resource actually
   marks as required by mistake).

5. **Sunset rehearsal.** Thirty days before any scheduled sunset, a staging environment flips that
   version's status to `sunset` early and QAYD runs its own first-party clients (web, mobile) and the
   AI engine's Python client against staging to confirm none of them are still silently calling the
   soon-to-be-retired version — a final internal check before the customer-facing sunset takes effect.

# Examples

**Example 1 — non-breaking additive change served identically old vs new client.**

Old client (never updated) request:
```
GET /api/v1/sales/invoices/8891
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 42
```
Response (after `tax_breakdown` was added on 2026-11-03 — old client simply never reads the new key):
```json
{
  "success": true,
  "data": {
    "id": 8891,
    "invoice_number": "INV-2026-08891",
    "customer_id": 310,
    "total_amount": "1050.0000",
    "currency_code": "KWD",
    "tax_breakdown": [
      { "tax_code": "VAT_STD", "rate": "0.0500", "amount": "50.0000" }
    ],
    "status": "sent"
  },
  "message": "Invoice retrieved",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9b2d9f0a-1e3a-4b7f-9a10-cf9d3b7f2211",
  "timestamp": "2026-11-05T08:20:00Z"
}
```

**Example 2 — curl against a deprecated version showing headers.**

```bash
curl -i https://api.qayd.com/api/v1/bank/transfers/551 \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 42"
```
```
HTTP/1.1 200 OK
Deprecation: true
Sunset: Wed, 15 Jul 2027 00:00:00 GMT
Link: <https://docs.qayd.com/api/migration/v1-to-v2>; rel="deprecation"
X-Qayd-Api-Version: v1
```

**Example 3 — sandboxing a beta version without changing the URL.**

```bash
curl https://api.qayd.com/api/v1/accounting/journal-entries \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 42" \
  -H "X-Qayd-Api-Preview: v2"
```
Response includes `X-Qayd-Api-Preview-Applied: v2` and returns the v2-shaped body, letting an
integrator validate v2 against the same base URL and credentials before switching their default path.

**Example 4 — breaking change rejected without a version bump (CI contract test failure).**

A pull request attempts to rename `invoices.total_amount` to `invoices.grand_total` directly on `v1`.
The contract test in "Testing Across Versions" fails immediately:

```
FAILED  tests/Feature/Api/V1/Contract/InvoiceContractTest.php
        Response is missing required property "total_amount" (v1/invoice.schema.json)
        This is a breaking change on a `current`/`deprecated` version. Introduce it under a new
        major version instead — see docs/api/API_VERSIONING.md § Breaking vs Non-Breaking Changes.
```

**Example 5 — full sunset error and the correct client remediation.**

```bash
curl -i https://api.qayd.com/api/v1/payroll/runs/9021/approve \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 42"
```
```
HTTP/1.1 410 Gone
Content-Type: application/json

{
  "success": false,
  "data": null,
  "message": "API version v1 was sunset on 2027-07-15 and no longer serves requests.",
  "errors": [ { "code": "api_version_sunset", "detail": "Migrate to v2." } ],
  "meta": { "pagination": null },
  "request_id": "1a9d4e77-6b2c-4a41-9e3d-42a9b7c9dd10",
  "timestamp": "2027-08-01T10:00:00Z"
}
```
Remediation: the client updates its base path from `/api/v1/` to `/api/v2/` and its SDK's
`apiVersion` constructor argument, following the published `v1-to-v2` migration guide's payroll
section.

# Edge Cases

- **New enum value reaches an old client's strict switch statement.** Even though adding an enum
  member is classified non-breaking under this policy, a poorly-written old client that does not
  implement the required `unknown` fallback (see "Additive Change Rules") may still throw. QAYD's
  mitigation: every enum-bearing endpoint's OpenAPI schema explicitly documents "this enum may gain
  members without a version bump" in its description field, and the changelog flags new enum members
  with elevated visibility (`Changed` tag, not buried in `Added`) precisely because this is the
  highest-risk "technically non-breaking" change class.
- **A client straddles two versions in one session** (e.g. reads via `v1`, writes via `v2` because it
  half-migrated). Both versions share the same underlying Service/Repository/Model layer and database,
  so data correctness is unaffected; however, the client may observe shape inconsistencies (a `v1` GET
  right after a `v2` POST shows the `v1`-shaped fields for data the client just wrote in `v2` shape).
  This is expected and documented — clients are told explicitly in migration guides to complete a
  migration atomically per logical workflow, not interleave versions within one user action.
- **A webhook fires with a payload shape from a version the receiving endpoint was built against a
  different version of.** Webhook payloads are versioned independently via a `webhook_version` field
  in the payload envelope and an `X-Qayd-Webhook-Version` header, decoupled from the URL-path API
  version the receiving company happens to call for its own reads/writes, since a webhook consumer
  and an API consumer for the same company may be different pieces of code on different upgrade
  schedules.
- **A version is deprecated, but a government-mandated tax integration (Tax module, `tax.submit`)
  cannot be updated before the sunset date due to the third party's own regulatory approval cycle.**
  Sensitive/regulated endpoints may be granted a documented sunset extension via an explicit
  exception request; the extension is tracked in `api_versions` as a per-endpoint override, not a
  silent blanket delay of the whole version, so the rest of that version's surface still sunsets on
  schedule.
- **Multiple concurrent breaking changes are needed at once** (e.g. both Sales and Payroll need
  incompatible reshaping). QAYD batches them into a single new major version rather than shipping
  `v1.1-breaking-sales` and `v1.2-breaking-payroll` separately — clients migrate once per major
  version, never twice for unrelated reasons close together.
- **A `beta` version never graduates and is abandoned.** Beta versions carry no SLA and no 12-month
  notice requirement; QAYD may retire a `beta` version with 30 days' notice to its opt-in
  participants only, since by definition no unaware third party depends on it.
- **Rate limits differ per version.** Because Redis-backed rate limiting keys on the API key, not the
  version path, a client cannot use multiple versions to multiply its quota; the limiter's key
  includes `company_id` + `api_key_id` only, deliberately excluding the version segment.
- **A retired version's routes are hit by a very old, forgotten integration years later.** Since
  routes are physically deleted at `retired`, the request 404s at the framework level (no route
  matches), not 410 — this is intentional: 410 is reserved for versions in the `sunset` observation
  window where QAYD still recognizes the path but is actively refusing it; 404 is what remains once
  QAYD's own memory of the version's existence is gone from the router. The audit log / archived
  OpenAPI spec at `docs.qayd.com/api/archive/{version}` remains the permanent record for any legal or
  compliance inquiry about what that version's contract used to be.

# End of Document
