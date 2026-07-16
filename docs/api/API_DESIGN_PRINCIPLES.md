# API Design Principles — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API Design Principles
---

# Purpose

This document defines the binding design principles for every HTTP endpoint exposed by the QAYD
API layer. QAYD is an AI Financial Operating System: Laravel 12 (PHP 8.4+) is the single source
of truth and owns the API; the AI engine (FastAPI/Python), the Next.js web client, the Flutter
mobile client, and all third-party integrations are consumers. Nothing — including the AI engine —
bypasses the API to read or write the database directly.

These principles exist so that:

1. Every endpoint, across every module (Accounting, Sales, Purchasing, Banking, Inventory,
   Payroll, Tax, Reports), looks and behaves as if a single engineer wrote it.
2. A human developer or an AI agent can predict the shape of an endpoint it has never called
   before, from its name and HTTP verb alone.
3. Financial correctness (double-entry integrity, immutability of posted records, multi-tenant
   isolation) is enforced at the API boundary, not left to client discipline.
4. Backward compatibility is a first-class design constraint, not an afterthought bolted on
   after a breaking change ships.

This is not a style guide for prose. It is a contract. Every other QAYD API document (endpoint
references, module specs, SDK generation rules) inherits from this one. Where a module doc
appears to conflict with this document, this document wins.

# Core Principles

## API-first

The API is designed and versioned before any client code is written against it. Concretely:

- The OpenAPI 3.1 contract for a new endpoint is authored and reviewed *before* the Laravel
  controller is implemented. The contract is the deliverable; the code satisfies the contract.
- No endpoint ships that exists only to serve one client's current screen. `GET
  /api/v1/sales/invoices/{invoice}` returns the full invoice resource for web, mobile, AI, and
  third-party integrations alike — none of them gets a bespoke shape.
- The FastAPI AI engine is a client like any other. It has no private, undocumented endpoints,
  no shared database credentials, and no shortcut around Sanctum/JWT auth or RBAC. If the AI
  engine needs a capability the API doesn't expose, that capability is added to the public
  contract, not smuggled in as a side channel.

## Consistency

One envelope, one pagination shape, one error format, one naming convention, one versioning
scheme — applied without exception across all ~14 modules. Consistency is what makes the API
learnable in one sitting and machine-generatable (SDKs, AI tool schemas) without per-endpoint
special-casing. A client that has correctly parsed the response of `GET
/api/v1/accounting/accounts` already knows how to parse the response of `GET
/api/v1/inventory/stock-adjustments` — same envelope, same pagination meta, same error shape.

## Least Surprise

An endpoint does exactly what its method and path imply, nothing more:

- `GET` never mutates state (not even a "last viewed" timestamp with side effects visible to
  other users).
- `DELETE /api/v1/accounting/journal-entries/{id}` never hard-deletes a posted journal entry —
  posted financial records are immutable by platform rule (see Section 4 of the platform design
  context). The verb still means "remove this resource from the caller's view"; for a posted
  entry that means soft-delete/void with an audit trail, never physical deletion, and the
  behavior is documented on the endpoint itself, not left for the caller to discover by trial.
- A `201 Created` always includes a `Location` header and the full created resource — a caller
  never has to make a second `GET` to know what was created.
- Field names never lie: `total_amount` is always the grand total including tax; a field that
  excludes tax is named `subtotal_amount`, never a differently-scoped `total_amount`.

## Statelessness

Every request carries everything the server needs to authorize and execute it: the bearer token
(identity), the `X-Company-Id` header (tenant context), and the request body/query (intent). The
server holds no per-client session state between requests.

- No server-side "current company" or "current fiscal year" stored against a session — every
  request declares `X-Company-Id` explicitly, and multi-step workflows (e.g., a purchase
  approval chain) are modeled as resource state transitions (`POST
  .../purchase-orders/{id}/approve`), not as an implicit wizard session.
- Idempotency keys (see "Idempotency & Safety") let a client safely retry a request without the
  server needing to remember "did I already see this" outside of a keyed, expiring record — the
  key travels with the request, not with a session.
- Rate limiting and caching (Redis-backed) key off the bearer token + company + route, never off
  a sticky session, so any API node can serve any request.

# Resource Modeling

Every endpoint models a **resource**, not a procedure. A resource is a noun with a well-defined
lifecycle: `invoice`, `journal-entry`, `bank-transaction`, `payroll-run`. Actions on a resource
that don't fit CRUD are modeled as **sub-resources or state-transition endpoints on the noun**,
never as verbs bolted onto the URI.

| Bad (RPC-style)                               | Good (resource-style)                                          |
|------------------------------------------------|-----------------------------------------------------------------|
| `POST /api/v1/postJournalEntry`                | `POST /api/v1/accounting/journal-entries/{id}/post`             |
| `POST /api/v1/sendInvoiceReminder`             | `POST /api/v1/sales/invoices/{id}/reminders`                     |
| `GET /api/v1/getCustomerBalance?id=42`         | `GET /api/v1/sales/customers/42/balance`                         |
| `POST /api/v1/approvePayroll`                  | `POST /api/v1/payroll/payroll-runs/{id}/approve`                 |

Rules for resource modeling:

1. **Every resource maps to exactly one canonical table** from the platform's canonical table
   list (e.g., `invoices`, `journal_entries`, `bank_accounts`). No endpoint invents a shape that
   doesn't correspond to a real, owned table or a documented projection of one.
2. **State transitions are sub-resources of the noun**, expressed as `POST
   /{resource}/{id}/{transition}` where `{transition}` is a verb naming the transition, not the
   action performed on data (`post`, `approve`, `void`, `reconcile`, `reverse`) — this keeps the
   base resource RESTful while still giving transitions a stable, guessable location.
3. **Read-only derived data is modeled as a sub-resource**, not a query flag on the parent:
   `GET /api/v1/accounting/accounts/{id}/ledger` (the account's ledger entries), not `GET
   /api/v1/accounting/accounts/{id}?include=ledger` for anything that constitutes a distinct,
   independently paginated collection.
4. **Composite documents keep their line items nested under the parent** in the URI
   (`/invoices/{invoice}/items`) but are still separately addressable resources with their own
   `id`, because line items are edited, priced, and taxed individually.
5. **Cross-cutting concerns (attachments, comments, audit history) are generic, polymorphic
   sub-resources** reused across every parent: `GET /api/v1/attachments?attachable_type=invoice
   &attachable_id=1042`, never a bespoke `invoice_attachments` table or endpoint per module.

# URI Design

- All paths are versioned: `/api/v1/...`. A breaking change never lands inside `v1`; it ships as
  `/api/v2/...` (see Backward Compatibility).
- Resource collections are **plural, kebab-case, lowercase**: `journal-entries`, `bank-accounts`,
  `sales-orders`, `stock-adjustments`. Never `journalEntry`, `Journal_Entries`, or singular
  `journal-entry` for a collection.
- Paths are namespaced by module: `/api/v1/{module}/{resource}`. Module segments are fixed:
  `accounting`, `sales`, `purchasing`, `banking`, `inventory`, `payroll`, `tax`, `reports`,
  `ai`, `admin`. Example: `/api/v1/banking/bank-accounts`, `/api/v1/payroll/payroll-runs`.
- Resource identifiers are path parameters, never query parameters, for single-resource
  operations: `GET /api/v1/sales/invoices/{invoice}` — not `GET
  /api/v1/sales/invoices?id={invoice}`.
- Nesting is at most two levels deep. `GET
  /api/v1/sales/invoices/{invoice}/items/{item}` is the maximum nesting allowed; a third level
  (e.g., item-level tax breakdown) becomes its own top-level, filterable resource instead:
  `GET /api/v1/sales/invoice-items/{item}/tax-lines`.
- Query strings are reserved for **filtering, sorting, search, pagination, and field
  selection** — never for identifying "which resource" or "which action."
- No trailing slashes. No file extensions (`.json`). No verbs in collection names
  (`get-invoices`).
- Company scoping is **never** in the URI (`/companies/{id}/invoices`) — it is always the
  `X-Company-Id` header, because every authenticated route is already company-scoped and
  putting it in the path would let a client attempt cross-tenant paths that must then be
  rejected, instead of the header simply not existing.

Canonical URI examples:

```
GET    /api/v1/accounting/accounts
GET    /api/v1/accounting/accounts/{account}
GET    /api/v1/accounting/accounts/{account}/ledger
POST   /api/v1/accounting/journal-entries
POST   /api/v1/accounting/journal-entries/{journalEntry}/post
POST   /api/v1/accounting/journal-entries/{journalEntry}/reverse
GET    /api/v1/sales/invoices?status=overdue&sort=-due_date
POST   /api/v1/sales/invoices/{invoice}/receipts
GET    /api/v1/banking/bank-accounts/{bankAccount}/bank-transactions
POST   /api/v1/banking/transfers
POST   /api/v1/payroll/payroll-runs/{payrollRun}/calculate
POST   /api/v1/payroll/payroll-runs/{payrollRun}/approve
GET    /api/v1/inventory/inventory-items?warehouse_id=3&low_stock=true
```

# HTTP Method Semantics

| Method | Semantics | Idempotent | Safe | Success codes | Body |
|--------|-----------|------------|------|----------------|------|
| GET    | Retrieve a resource or collection | Yes | Yes | 200 | None |
| POST   | Create a resource, or trigger a non-idempotent state transition | No (unless `Idempotency-Key` used) | No | 201 (create), 200 (transition) | Required |
| PUT    | Full replacement of a resource | Yes | No | 200 | Required (full resource) |
| PATCH  | Partial update of a resource | Yes (same payload → same end state) | No | 200 | Required (partial resource) |
| DELETE | Remove/void/soft-delete a resource | Yes | No | 200 or 204 | Optional (e.g., void reason) |

Rules:

- **GET is always safe and cacheable.** No GET endpoint has a documented side effect. Read
  receipts, "viewed at" timestamps, and analytics counters are written asynchronously via a
  domain event, never synchronously inside a GET handler's response path.
- **POST is for creation and for transitions that are not naturally idempotent** (e.g.,
  `POST .../journal-entries/{id}/post` — posting the same entry twice must be rejected with `409
  Conflict`, not silently repeated). Where a POST transition needs safe client retries, it
  supports the `Idempotency-Key` header (see next section).
- **PUT replaces the entire resource.** Fields omitted from a PUT body are reset to their
  defaults, not left untouched. QAYD prefers PATCH for almost all updates; PUT is reserved for
  resources where a full-replacement semantic is genuinely useful (e.g., replacing an entire
  `price-lists/{id}` pricing table in one call).
- **PATCH updates only the fields present in the body**, following RFC 7396 (JSON Merge Patch)
  semantics: a field set to `null` clears it; a field absent from the body is untouched.
- **DELETE never physically removes a financial record.** For posted, immutable records (see
  Idempotency & Safety and platform rule "posted accounting records are immutable"), `DELETE`
  is disabled and returns `409 Conflict` with a message directing the caller to the reversing-
  entry workflow (`POST /{id}/reverse`). For non-financial, non-posted resources, `DELETE` sets
  `deleted_at` (soft delete) and is idempotent — calling it twice returns `200`/`204` both times.
- **HEAD and OPTIONS** are supported on every collection endpoint for existence checks and CORS
  preflight respectively; neither is documented per-endpoint since behavior is uniform.

# Request & Response Consistency

Every response — success or error — uses one envelope, with no exceptions:

```json
{
  "success": true,
  "data": {},
  "message": "Human readable message",
  "errors": [],
  "meta": {
    "pagination": { "page": 1, "per_page": 25, "total": 0, "cursor": null }
  },
  "request_id": "8f14e45f-ceea-4a94-8c1a-4a5f3ed6a2b3",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

Binding rules for the envelope:

- `success` is a boolean and is **never** absent. A `2xx` HTTP status always pairs with
  `success: true`; any `4xx`/`5xx` always pairs with `success: false`. Clients may safely branch
  on `success` alone without inspecting the HTTP status.
- `data` holds the resource(s). For a single-resource response it is a JSON object; for a
  collection it is a JSON array. It is `null` (not omitted) when there is genuinely no payload
  (e.g., a `204`-adjacent `DELETE` acknowledgement returned as `200`).
- `message` is always a non-empty, human-readable string — safe to show directly in a toast/UI.
  It is localizable server-side based on the caller's `Accept-Language` header.
- `errors` is always an array. It is empty (`[]`) on success. On failure it is populated per the
  Error Philosophy section below. It is never `null` and never an object.
- `meta.pagination` is present on every collection response; it is omitted (not merely empty)
  on single-resource responses.
- `request_id` is a UUID v4, generated per request by middleware, echoed in logs, and returned
  to the caller so a support ticket or AI-agent retry can reference the exact request.
- `timestamp` is ISO 8601, UTC, with `Z` suffix, generated at response-serialization time —
  never client-supplied.
- Every request that carries a body sends `Content-Type: application/json` and every response
  sends the same. QAYD does not support XML, form-encoded bodies, or content negotiation beyond
  JSON.
- Every mutating request is authenticated (Sanctum/JWT bearer token), tenant-scoped
  (`X-Company-Id`), authorized (RBAC), validated (Laravel FormRequest), and only then executed —
  in that fixed order, enforced by middleware, not by controller code that could forget a step.

Standard request headers:

| Header | Required | Description |
|---|---|---|
| `Authorization` | Yes | `Bearer <token>` — Sanctum/JWT access token |
| `X-Company-Id` | Yes (all authenticated routes) | Active tenant company |
| `Content-Type` | Yes on bodies | `application/json` |
| `Accept-Language` | No | `en` or `ar`; defaults to `en` |
| `Idempotency-Key` | Required on non-idempotent POSTs that create financial side effects | Client-generated UUID |
| `X-Request-Id` | No | Client-supplied correlation id, echoed back alongside the server `request_id` |

# Naming Conventions

- **JSON keys**: `snake_case`, always. `invoice_number`, `due_date`, `total_amount`,
  `exchange_rate` — never `invoiceNumber` or `InvoiceNumber`. This matches PostgreSQL column
  naming exactly, so serialization is a direct passthrough with no case-mapping layer to
  maintain or get wrong.
- **URI segments**: plural `kebab-case` nouns (`bank-accounts`, `stock-adjustments`).
- **Query parameters**: `snake_case` (`per_page`, `sort`, `created_at_from`), matching JSON body
  conventions so a field name is spelled identically whether it appears in a filter, a body, or
  a response.
- **Permission keys**: dotted `<area>.<action>` or `<area>.<entity>.<action>`
  (`accounting.journal.create`, `bank.transfer`, `payroll.approve`, `tax.submit`). Permission
  keys are lowercase, use `.` as the only separator, and never abbreviate the area beyond its
  module name.
- **Enums**: `snake_case` string values, never integers, so a raw JSON payload is
  self-explanatory without a lookup table: `"status": "posted"`, not `"status": 2`.
- **Booleans**: prefixed `is_`/`has_`/`can_` (`is_posted`, `has_attachments`,
  `can_approve`), always `true`/`false`, never `1`/`0` or `"yes"`.
- **Money fields**: suffixed `_amount` and always paired with `currency_code`:
  `subtotal_amount`, `tax_amount`, `total_amount`, `currency_code: "KWD"`. A bare `amount` field
  never appears without this pairing.
- **Foreign keys in JSON**: `<singular_resource>_id` (`customer_id`, `account_id`,
  `warehouse_id`), matching the FK column name in Postgres exactly.
- **Timestamps**: suffixed `_at` (`created_at`, `posted_at`, `approved_at`), always ISO 8601 UTC.
- **Dates without time**: suffixed `_date` (`due_date`, `invoice_date`, `fiscal_year_start_date`).

# Idempotency & Safety

Financial operations must never be double-executed because of a network retry, a client bug, or
an AI agent re-issuing a call after an ambiguous timeout. QAYD's idempotency model:

1. **All GET, PUT, PATCH, DELETE requests are naturally idempotent** by HTTP semantics and
   require no additional key. Calling `PATCH /api/v1/sales/customers/42` with the same body
   twice leaves the customer in the same end state both times.
2. **Non-idempotent POST requests that create a financial side effect require an
   `Idempotency-Key` header** — a client-generated UUID v4. This is mandatory (not merely
   supported) on: journal entry creation/posting, invoice creation, receipt/payment recording,
   bank transfers, payroll run approval, and any other endpoint tagged `idempotent_required:
   true` in its OpenAPI operation.
3. **Server-side idempotency ledger**: the API persists `(company_id, route, idempotency_key) ->
   response` in Redis for 24 hours. A repeated request with the same key and route returns the
   **original stored response** verbatim (same status code, same body) without re-executing the
   side effect. A repeated request with the same key but a **different body** returns `409
   Conflict` with `errors: [{"code": "idempotency_key_reused", ...}]` — reusing a key for a
   different payload is treated as a client bug, never silently accepted.
4. **Double-posting protection independent of idempotency keys**: `journal_entries.status`
   transitions `draft -> posted` exactly once, enforced by a database-level check (optimistic
   locking on a `version` column, or a `posted_at IS NULL` guard in the `UPDATE ... WHERE`
   clause) so that even a client that forgot the `Idempotency-Key` cannot post the same entry
   twice under concurrent requests. A second `POST .../post` on an already-posted entry returns
   `409 Conflict`, never a silent no-op `200`.
5. **Safe methods never require idempotency keys.** Read endpoints have no side effects to
   deduplicate.

Example: creating an invoice safely under retry.

```
POST /api/v1/sales/invoices
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1042
Idempotency-Key: 6f9619ff-8b86-d011-b42d-00cf4fc964ff
Content-Type: application/json

{
  "customer_id": 88,
  "invoice_date": "2026-07-16",
  "due_date": "2026-08-15",
  "currency_code": "KWD",
  "items": [
    { "product_id": 501, "quantity": "10.0000", "unit_price": "12.5000", "tax_code_id": 3 }
  ]
}
```

If the client's connection drops after the server has committed the invoice but before the
response arrives, the client retries with the **identical** `Idempotency-Key`. The server finds
the stored response in the idempotency ledger and returns it unchanged — no second invoice is
created, no second journal entry is posted.

# Pagination / Filtering / Sorting Defaults

**Pagination.** Every collection endpoint paginates by default; there is no "return everything"
mode. Two pagination styles are supported:

- **Page-based** (default, simplest): `?page=2&per_page=25`. Default `per_page` is `25`;
  maximum is `100`. Requesting `per_page` above `100` is clamped to `100`, not rejected.
- **Cursor-based** (for high-churn or very large tables, e.g., `ledger_entries`,
  `stock_movements`, `audit_logs`): `?cursor=eyJpZCI6MTIzfQ&per_page=25`. The cursor is an
  opaque, base64-encoded pointer; clients never construct or decode it themselves.

Both styles return the same `meta.pagination` shape:

```json
"meta": {
  "pagination": {
    "page": 2,
    "per_page": 25,
    "total": 483,
    "cursor": "eyJpZCI6MTIzfQ"
  }
}
```

`total` is always present for page-based pagination. For cursor-based pagination on very large
tables, `total` may be `null` when computing an exact count would be prohibitively expensive;
this is documented per-endpoint and never silently substituted with an estimate presented as
exact.

**Filtering.** Query parameters filter by exact match on a field name (`?status=posted`), or by
a documented suffix for ranges and operators:

| Suffix | Meaning | Example |
|---|---|---|
| `_from` / `_to` | Inclusive range | `?invoice_date_from=2026-07-01&invoice_date_to=2026-07-31` |
| `_in` | Comma-separated set membership | `?status_in=posted,draft` |
| `_like` | Case-insensitive partial match | `?name_like=acme` |
| `q` | Free-text search across the resource's indexed search fields | `?q=acme+invoice` |

Filters are combined with implicit AND. There is no OR syntax in the query string; complex
boolean filters use `POST /api/v1/{resource}/search` with a JSON filter body instead of
overloading GET query strings.

**Sorting.** `?sort=field` ascending, `?sort=-field` descending, comma-separated for multi-key
sort: `?sort=-invoice_date,customer_id`. Every collection endpoint documents its allow-listed
sortable fields; requesting a non-allow-listed field returns `422` with
`errors: [{"code": "invalid_sort_field", "field": "sort"}]`, never a silent fallback to default
order.

**Field selection.** `?fields=id,invoice_number,total_amount` restricts the `data` payload to
the named fields, always still wrapped in the standard envelope. Nested relations are requested
via `?include=customer,items` — never auto-included, to keep default payload size predictable.

# Error Philosophy

Errors are **specific, actionable, and machine-parseable first, human-readable second.** A
caller — human or AI agent — must be able to branch on `errors[].code` without parsing
`message`.

Envelope on failure:

```json
{
  "success": false,
  "data": null,
  "message": "The given data was invalid.",
  "errors": [
    {
      "code": "validation.required",
      "field": "items.0.quantity",
      "message": "The quantity field is required."
    },
    {
      "code": "validation.numeric",
      "field": "items.0.unit_price",
      "message": "The unit price must be a number."
    }
  ],
  "meta": {},
  "request_id": "a1b2c3d4-e5f6-4789-a012-3456789abcde",
  "timestamp": "2026-07-16T12:03:41Z"
}
```

Status code semantics:

| Code | Meaning | Body `errors[].code` examples |
|---|---|---|
| 400 | Malformed request (bad JSON, missing required header) | `malformed_json`, `missing_header` |
| 401 | Missing/invalid/expired bearer token | `unauthenticated`, `token_expired` |
| 403 | Authenticated but not permitted (RBAC denial, wrong `X-Company-Id`) | `forbidden`, `permission_denied` |
| 404 | Resource does not exist, or exists in a company the caller cannot see | `not_found` |
| 409 | State conflict (double-post, idempotency key reuse with different body, edit of an immutable record) | `already_posted`, `idempotency_key_reused`, `immutable_record` |
| 422 | Semantically invalid payload (validation failures, unbalanced journal entry) | `validation.required`, `journal_unbalanced` |
| 429 | Rate limit exceeded (Redis-backed) | `rate_limited` |
| 500 | Unhandled server error | `internal_error` |

Rules:

- **404 is used for cross-tenant access, not 403**, when the caller has no permission to know
  the resource exists at all (e.g., requesting another company's invoice by ID). 403 is reserved
  for cases where the caller can see the resource exists but lacks the specific permission to
  act on it (e.g., viewing an invoice but lacking `sales.invoice.void`).
- **Every `errors[]` entry has a stable `code`** drawn from a documented, versioned enum. `code`
  values never change meaning between minor releases; new codes are added, existing ones are
  never repurposed.
- **`field` uses dot-and-index notation** matching the request body path exactly
  (`items.0.quantity`), so a client can map an error straight back to a form field or an array
  row without string parsing heuristics.
- **429 responses include `Retry-After`** (seconds) as a standard HTTP header, in addition to
  the JSON body, so both HTTP-aware and JSON-only clients can back off correctly.
- **500 responses never leak stack traces, SQL, or internal file paths** in `message` or
  `errors[]`. Full diagnostic detail goes to server-side logs keyed by `request_id`; the client
  gets `request_id` to hand to support.

# Backward Compatibility

- **The URI version (`/api/v1/`) is the compatibility boundary.** Anything that would break an
  existing, conformant client is a breaking change and ships in `/api/v2/`, never as a mutation
  of `v1` behavior.
- **Non-breaking (allowed within `v1` without a version bump):**
  - Adding a new optional field to a response.
  - Adding a new optional request parameter with a safe default.
  - Adding a new endpoint.
  - Adding a new enum value to a field, **provided** the field's documentation already states
    "additional values may be added" (all QAYD enum fields carry this note by default).
  - Loosening a validation rule (accepting more than before).
- **Breaking (requires a new version):**
  - Removing or renaming a response field.
  - Changing a field's type or unit (e.g., `total_amount` from string-decimal to float).
  - Removing an enum value that a client may depend on.
  - Changing default pagination size, sort order, or default filter behavior.
  - Tightening validation (rejecting requests previously accepted).
  - Changing the meaning of an existing status code on an existing endpoint.
- **Deprecation policy**: a deprecated endpoint or field is marked in the OpenAPI spec with
  `deprecated: true` and a `Sunset` HTTP header (RFC 8594) carrying the removal date, minimum 6
  months out. Deprecated endpoints continue to function identically until the sunset date;
  QAYD never silently changes behavior under a deprecated path.
- **Two major versions are supported concurrently at most.** When `v2` ships, `v1` remains fully
  supported for the deprecation window; `v3` cannot ship while both `v1` and `v2` are still
  active.
- **SDKs (PHP, TypeScript, Python, Dart) are generated from the OpenAPI contract per version**,
  so a breaking API change always corresponds to a new generated SDK major version — a
  consuming application controls its own upgrade timing by pinning an SDK version.

# HATEOAS Stance

QAYD deliberately does **not** implement full HATEOAS (hypermedia-driven, self-navigating
`_links` graphs). Rationale: the API's consumers are (a) generated, strongly-typed SDKs and (b)
an AI agent operating from a fixed OpenAPI tool schema — both navigate by **known, versioned
paths**, not by discovering links at runtime. Full hypermedia adds payload weight and client
complexity without a corresponding consumer that benefits from it.

QAYD takes a **pragmatic partial stance**:

- Every response includes a minimal, predictable set of **action affordances** as plain fields,
  not a `_links` hypermedia graph: `can_edit`, `can_post`, `can_void`, `can_approve` (booleans
  computed server-side from the caller's actual RBAC permissions and the resource's current
  state). A client checks `data.can_post` before rendering a "Post" button, rather than
  attempting the call and parsing a 403.
- Collection responses include `meta.pagination` with enough information (`page`, `per_page`,
  `total`, `cursor`) for a client to construct the next page's URI itself — the API does not
  additionally return a `next_page_url` string, because the URI is deterministically
  constructible from documented query parameters.
- Cross-resource references are always plain `_id` foreign keys (`customer_id: 88`), not
  embedded hypermedia links (`{"href": "/api/v1/sales/customers/88"}`) — the URI pattern for any
  resource is documented and stable, so a client that knows the resource type and id already
  knows the URI.

This is a considered omission, not an oversight: full HATEOAS is revisited only if a consumer
class emerges (e.g., a generic no-code integration builder) that genuinely needs runtime link
discovery.

# Bulk Operations

Single-record endpoints do not silently accept arrays. Bulk operations are explicit, separate
endpoints with bulk-specific semantics:

| Method | Path | Description |
|---|---|---|
| POST | `/api/v1/accounting/journal-entries/bulk` | Create up to 100 journal entries in one call |
| PATCH | `/api/v1/sales/invoices/bulk` | Partially update up to 100 invoices in one call |
| DELETE | `/api/v1/inventory/stock-adjustments/bulk` | Void up to 100 stock adjustments in one call |
| POST | `/api/v1/sales/invoices/bulk-export` | Async export (see Async Operations) for >100 records |

Rules:

- Bulk endpoints have a **hard item-count limit** (100 by default, documented per-endpoint);
  exceeding it returns `422` with `errors: [{"code": "bulk_limit_exceeded", "field": "items"}]`
  rather than silently truncating.
- **Bulk requests are partially-atomic per item, not all-or-nothing across the batch**: each
  item is validated and executed independently, and the response reports per-item outcomes.
  This matches financial reality — 97 valid journal entries in a batch of 100 should post; the
  3 invalid ones should not block the other 97, but must be individually reported.

```json
{
  "success": true,
  "data": {
    "succeeded": [
      { "index": 0, "id": 9001 },
      { "index": 1, "id": 9002 }
    ],
    "failed": [
      {
        "index": 2,
        "errors": [{ "code": "journal_unbalanced", "field": "lines", "message": "Debits (150.0000) do not equal credits (140.0000)." }]
      }
    ]
  },
  "message": "2 of 3 journal entries created.",
  "errors": [],
  "meta": {},
  "request_id": "b7e6a5d4-c3b2-4a19-8e0f-1d2c3b4a5968",
  "timestamp": "2026-07-16T12:05:02Z"
}
```

- A bulk request still requires a single `Idempotency-Key` covering the whole batch when any
  item would create a financial side effect, so retrying the whole batch never double-executes
  the items that already succeeded — the idempotency ledger stores the full per-item outcome
  array, not just a single status.
- Bulk `GET` does not exist as a separate concept — `GET` collections already return many
  records per the standard pagination model; "bulk read" is simply a paginated `GET`.

# Async Operations

Some operations exceed what a synchronous request/response cycle should hold: report generation
across a full fiscal year, bulk export of ledger history, AI-driven bank-statement reconciliation
across thousands of lines, payroll calculation for a large headcount. These are modeled as
**asynchronous jobs with a polling or webhook completion signal**, never as a long-held HTTP
connection.

Pattern:

1. Client issues `POST /api/v1/reports/report-runs` (or any async-tagged endpoint). The server
   validates the request synchronously, enqueues a Redis-backed job, and returns immediately.

```
POST /api/v1/reports/report-runs
{ "report_definition_id": 12, "fiscal_year_id": 2026, "format": "pdf" }
```

```json
{
  "success": true,
  "data": {
    "id": 4471,
    "status": "queued",
    "report_definition_id": 12,
    "created_at": "2026-07-16T12:06:00Z"
  },
  "message": "Report run queued.",
  "errors": [],
  "meta": {},
  "request_id": "c9d8e7f6-a5b4-4c3d-9e8f-7a6b5c4d3e2f",
  "timestamp": "2026-07-16T12:06:00Z"
}
```

Response status is `202 Accepted`, not `201` — the resource (the report run record) exists, but
the work it represents has not completed.

2. Client polls `GET /api/v1/reports/report-runs/{id}` — `status` progresses `queued ->
   running -> completed | failed`. Long-running-job resources always expose `status`,
   `progress_percent` (nullable), `started_at`, `completed_at`, and, on completion, either a
   `result` payload or an `errors[]` explaining the failure.

3. Alternatively (preferred for server-to-server and AI-engine consumers), the client subscribes
   to the domain event via webhook (`ai.finished`, or a report-specific event) or Reverb
   WebSocket channel `private-company.{company_id}.report-runs.{id}`, avoiding polling entirely.

Rules:

- **An endpoint is async if and only if it is documented as such** in its OpenAPI operation
  (`x-async: true`) and returns `202` — a client never has to guess synchronous-vs-async from
  behavior.
- **Async jobs are themselves resources**, addressable, cancelable (`POST
  .../report-runs/{id}/cancel` where cancellation is meaningful), and listable (`GET
  /api/v1/reports/report-runs?status=running`) — never fire-and-forget with no way to check on
  them.
- **AI engine jobs follow the identical pattern.** `POST /api/v1/ai/analyses` enqueues an AI
  task (e.g., invoice OCR extraction, anomaly detection sweep); the AI engine posts its result
  back through the same authenticated API (`PATCH /api/v1/ai/analyses/{id}`) it would use for
  any other write, carrying `confidence_score` and `reasoning` fields, and the `ai.finished`
  webhook fires on completion for any subscriber.

# AI-Consumable Design

QAYD's API is consumed directly by AI agents (the FastAPI AI engine's General Accountant,
Auditor, Tax Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO, Fraud
Detection, Reporting, Document AI/OCR, Forecast, Compliance, and Approval Assistant agents) as
tool-callable functions, not just by human-facing UIs. This constrains the design further:

- **The OpenAPI 3.1 contract is the single source of AI tool definitions.** Endpoint
  `operationId`, parameter descriptions, and response schemas are written precisely enough to
  be converted mechanically into an LLM tool schema with no hand-editing. `operationId` values
  are stable, descriptive, and unique (`postJournalEntry`, `createSalesInvoice`,
  `approvePayrollRun`) — never `endpoint1` or auto-generated hashes.
- **Every field the AI must reason about has a `description` in the schema**, including units
  and edge-case meaning (`"tax_amount": "Tax portion of total_amount, in the invoice's
  currency_code, NUMERIC(19,4)"`), because an AI agent has no tribal knowledge to fall back on.
- **Responses are self-describing enough to act on without a second lookup.** An invoice
  response includes `customer_id` and the denormalized `customer_name` together — an AI agent
  drafting a collections message doesn't need a second round-trip just to get a display name it
  will need regardless.
- **Every write endpoint an AI agent can call declares its required permission** in the OpenAPI
  `x-permission` extension field, and the AI engine is issued tokens scoped to exactly the
  permissions its acting user holds — the AI never operates with elevated privilege the human
  session lacks. An AI agent attempting `bank.transfer` without that permission on the acting
  user's role gets the identical `403 permission_denied` a human client would.
- **Sensitive AI-proposed actions are always drafts, never direct writes.** An AI agent never
  calls `POST .../payroll-runs/{id}/approve` directly; it calls `POST
  .../payroll-runs/{id}/approval-recommendations` with `confidence_score`, `reasoning`, and
  supporting `attachment_ids`, and a human calls the actual `approve` endpoint. The API enforces
  this separation structurally — recommendation endpoints and action endpoints are distinct
  routes with distinct permissions (`payroll.recommend` vs `payroll.approve`), so an AI-scoped
  token physically cannot hold the approval permission for these gated actions.
- **Errors are structured for programmatic recovery, not just human display** (see Error
  Philosophy) — an AI agent retrying after `422 validation.required` on `items.0.quantity` can
  correct exactly that field and retry, rather than re-deriving the problem from a prose
  message.
- **Idempotency keys are mandatory, not optional, on every endpoint an AI agent can call that
  creates a financial side effect** — an AI agent that times out mid-call and re-issues the
  request must never double-post a journal entry or double-send a payment.
- **Confidence and reasoning are first-class response/request fields, not free-text
  afterthoughts**, on every AI-originated resource: `ai_conversations`, `ai_messages`, and any
  `*-recommendations` sub-resource carry `confidence_score` (NUMERIC 0–1), `reasoning` (text),
  and `source_document_ids` (array), with a documented, fixed schema across every module rather
  than a module-specific ad hoc shape.

Example: an AI-proposed action versus a human-executed action, same resource.

```
POST /api/v1/payroll/payroll-runs/781/approval-recommendations
Authorization: Bearer <ai-engine-scoped-token>
X-Company-Id: 1042
{
  "recommendation": "approve",
  "confidence_score": 0.94,
  "reasoning": "All 42 payslips match prior period within 3% variance; no flagged anomalies.",
  "source_document_ids": [55231, 55232]
}
```

```
POST /api/v1/payroll/payroll-runs/781/approve
Authorization: Bearer <human-cfo-token>
X-Company-Id: 1042
{ "approval_note": "Reviewed AI recommendation and payslip summary." }
```

Both hit the same underlying `payroll_runs` resource; only the second, human-permissioned call
changes `status` to `approved`.

# Anti-Patterns

The following are explicitly prohibited across every QAYD endpoint, present or future.

| Anti-pattern | Why it's banned | Correct alternative |
|---|---|---|
| Verbs in URIs (`/getInvoices`, `/createUser`) | Breaks resource modeling; not guessable | `GET /invoices`, `POST /users` |
| Returning `200` on a validation failure with an `"error"` string buried in `data` | Forces every client to inspect the body just to know if the call worked | `422` + `success: false` + `errors[]` |
| A single endpoint that changes response shape based on a query parameter (`?verbose=true`) | Two undocumented response schemas for one operationId; breaks AI tool schemas and SDK typing | Separate sub-resource (`/invoices/{id}/details`) or `?fields=` selection with one fixed schema |
| Encoding actions as HTTP methods that don't match semantics (`GET` that deletes, `POST` used for reads) | Violates safety/idempotency guarantees caches and retries depend on | Use the method matching actual semantics |
| Auto-including every relation on every response "to be helpful" | Unbounded, unpredictable payload size; N+1 queries; breaks pagination cost budgets | Explicit `?include=` |
| Silent partial success on a single-record write (accepting some fields, dropping others, still returning `200`) | Client believes the full write succeeded | Reject with `422` naming every invalid field, or fully succeed |
| Returning raw database error text or stack traces in `message` | Leaks schema/internals; unsafe, unhelpful | Generic `message` + logged `request_id` |
| Pagination that returns "all rows" when `per_page` is omitted | Unbounded response size; DoS risk on large tables (`ledger_entries`, `audit_logs`) | Always default and cap `per_page` |
| Different envelope shape for one module vs another (e.g., Payroll omitting `meta`) | Breaks the one-envelope guarantee this whole document exists to protect | Same envelope, every module, no exceptions |
| Booleans encoded as `"Y"/"N"` or `1/0` strings | Ambiguous typing, breaks strongly-typed SDKs | JSON `true`/`false` |
| Allowing `DELETE` to hard-delete a posted journal entry because "the client asked for it" | Violates immutability of posted financial records — the platform's core financial-integrity guarantee | `409 Conflict`; direct to `/reverse` |
| An AI-engine token with the same permission scope as its acting human's session including approval-only actions | Breaks the human-approval-chain guarantee for sensitive ops | AI tokens restricted to `*.recommend`/read scopes; approval endpoints require a human-session token |
| New optional field silently changing an existing field's meaning ("total_amount now excludes tax") | Backward-incompatible in practice even if technically additive | New, separately named field; old field's meaning frozen |

# Examples

## Example 1 — List with filtering, sorting, and pagination

```
GET /api/v1/sales/invoices?status_in=posted,overdue&invoice_date_from=2026-06-01&sort=-due_date&page=1&per_page=25
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1042
Accept-Language: en
```

```json
{
  "success": true,
  "data": [
    {
      "id": 9187,
      "invoice_number": "INV-2026-09187",
      "customer_id": 88,
      "customer_name": "Al Salam Trading Co.",
      "invoice_date": "2026-07-01",
      "due_date": "2026-07-31",
      "currency_code": "KWD",
      "subtotal_amount": "500.0000",
      "tax_amount": "25.0000",
      "total_amount": "525.0000",
      "status": "overdue",
      "is_posted": true,
      "can_edit": false,
      "can_void": true,
      "created_at": "2026-07-01T08:12:00Z",
      "updated_at": "2026-07-01T08:12:00Z"
    }
  ],
  "message": "12 invoices found.",
  "errors": [],
  "meta": {
    "pagination": { "page": 1, "per_page": 25, "total": 12, "cursor": null }
  },
  "request_id": "d3c2b1a0-9f8e-4d7c-9b6a-5e4f3d2c1b0a",
  "timestamp": "2026-07-16T12:10:11Z"
}
```

## Example 2 — Curl for a state transition (idempotent posting)

```bash
curl -X POST https://api.qayd.io/api/v1/accounting/journal-entries/5521/post \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 1042" \
  -H "Idempotency-Key: 3fa85f64-5717-4562-b3fc-2c963f66afa6" \
  -H "Content-Type: application/json" \
  -d '{}'
```

Success:

```json
{
  "success": true,
  "data": {
    "id": 5521,
    "status": "posted",
    "posted_at": "2026-07-16T12:11:03Z",
    "debit_total": "1500.0000",
    "credit_total": "1500.0000"
  },
  "message": "Journal entry posted.",
  "errors": [],
  "meta": {},
  "request_id": "e4d5c6b7-a8f9-4021-8e3d-2c1b0a9f8e7d",
  "timestamp": "2026-07-16T12:11:03Z"
}
```

Retry with the same key after the entry is already posted (server returns the stored response,
does not re-post):

```json
{
  "success": true,
  "data": {
    "id": 5521,
    "status": "posted",
    "posted_at": "2026-07-16T12:11:03Z",
    "debit_total": "1500.0000",
    "credit_total": "1500.0000"
  },
  "message": "Journal entry posted.",
  "errors": [],
  "meta": {},
  "request_id": "e4d5c6b7-a8f9-4021-8e3d-2c1b0a9f8e7d",
  "timestamp": "2026-07-16T12:11:03Z"
}
```

## Example 3 — Validation failure (422) on an unbalanced journal entry

```
POST /api/v1/accounting/journal-entries
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 1042
Idempotency-Key: 7c9e6679-7425-40de-944b-e07fc1f90ae7
Content-Type: application/json

{
  "entry_date": "2026-07-16",
  "description": "Office supplies purchase",
  "lines": [
    { "account_id": 610, "debit_amount": "150.0000", "credit_amount": "0.0000" },
    { "account_id": 101, "debit_amount": "0.0000", "credit_amount": "140.0000" }
  ]
}
```

```json
{
  "success": false,
  "data": null,
  "message": "The given data was invalid.",
  "errors": [
    {
      "code": "journal_unbalanced",
      "field": "lines",
      "message": "Total debits (150.0000) must equal total credits (140.0000)."
    }
  ],
  "meta": {},
  "request_id": "f5e6d7c8-b9a0-4132-9f4e-3d2c1b0a9f8e",
  "timestamp": "2026-07-16T12:12:45Z"
}
```

## Example 4 — Cross-tenant access attempt (404, not 403)

```
GET /api/v1/sales/invoices/9187
Authorization: Bearer <token scoped to company 2001>
X-Company-Id: 2001
```

```json
{
  "success": false,
  "data": null,
  "message": "The requested resource was not found.",
  "errors": [
    { "code": "not_found", "field": "id", "message": "No invoice matches the given id for this company." }
  ],
  "meta": {},
  "request_id": "0a1b2c3d-4e5f-4678-9a0b-1c2d3e4f5a6b",
  "timestamp": "2026-07-16T12:13:20Z"
}
```

Invoice `9187` belongs to company `1042`; the token is scoped to company `2001`. The API returns
`404`, not `403`, because a caller outside a tenant must not be able to distinguish "exists in a
company I can't touch" from "doesn't exist" — that distinction is itself information leakage
across tenant boundaries.

## Example 5 — Laravel middleware ordering that enforces the four-stage pipeline

```php
// routes/api.php
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::middleware(['company.context', 'permission:accounting.journal.create'])
        ->post('accounting/journal-entries', [JournalEntryController::class, 'store']);

    Route::middleware(['company.context', 'permission:accounting.journal.post', 'idempotent'])
        ->post('accounting/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post']);
});
```

```php
// app/Http/Middleware/EnsureCompanyContext.php
public function handle(Request $request, Closure $next): Response
{
    $companyId = $request->header('X-Company-Id');

    abort_unless($companyId, 400, 'X-Company-Id header is required.');
    abort_unless(
        $request->user()->companies()->where('companies.id', $companyId)->exists(),
        403,
        'You do not have access to this company.'
    );

    app()->instance('currentCompanyId', (int) $companyId);

    return $next($request);
}
```

This is the concrete implementation of "authenticated, then authorized, then validated, then
executed": `auth:sanctum` authenticates, `company.context` resolves and authorizes tenant
scope, `permission:*` authorizes the specific action, the FormRequest injected into the
controller action validates the body, and only the controller/service executes the write.

# End of Document
