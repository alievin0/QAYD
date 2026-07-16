# REST & HTTP Standards — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: REST_STANDARDS
---

# Purpose

This document is the binding contract for how every HTTP endpoint in the QAYD platform is designed,
named, requested, and answered. QAYD is an AI Financial Operating System with Laravel 12 (PHP 8.4+) as
the single source of truth for all business and financial logic. The FastAPI/Python AI engine, the
Next.js 15 web client, the Flutter mobile client, and every third-party integration are, without
exception, callers of this API — none of them touch the PostgreSQL database directly, and none of them
may invent their own request/response shape. This document defines that shape once, so that every
engineer, every AI agent generating a controller, and every SDK generator produces byte-for-byte
consistent output.

The standards below apply uniformly across all modules — Accounting, Sales, Purchasing, Banking,
Inventory, Payroll, Tax, Reports, and the AI layer. A request to `/api/v1/accounting/journal-entries`
and a request to `/api/v1/payroll/payroll-runs` are indistinguishable in shape: same envelope, same
header contract, same pagination rules, same error format. This consistency is what allows a single
generated OpenAPI/Swagger contract to drive PHP, JavaScript/TypeScript, Python, and Flutter/Dart SDKs
without per-module exceptions.

Every request that reaches a controller has already passed through three non-negotiable gates, in this
order: **authenticate** (Sanctum/JWT bearer token is valid and not expired), **authorize** (RBAC
permission check against the caller's role in the active company, default-deny), and **validate**
(FormRequest schema validation against the declared JSON schema for that endpoint). Only after all three
gates pass does a request reach a Service class for execution. This document describes the wire format
those gates operate on — not the business rules behind them, which live in each module's own
specification document.

# HTTP Methods

QAYD uses the standard HTTP verbs with strict, uniform semantics. No endpoint may repurpose a verb
outside the meaning defined here — for example, `GET` never mutates state, and `DELETE` never performs a
hard delete of financial data (see Edge Cases).

| Method | Semantics | Idempotent | Safe | Request Body | Typical Use |
|---|---|---|---|---|---|
| `GET` | Retrieve a resource or collection. Never mutates state, never has side effects visible to the caller. | Yes | Yes | None | `GET /api/v1/accounting/journal-entries/482` |
| `POST` | Create a new resource, OR trigger a non-CRUD action/state-transition (see Sub-Resources & Actions). | No (creates a new resource each call unless an `Idempotency-Key` is supplied) | No | Required | `POST /api/v1/sales/invoices` |
| `PUT` | Replace a resource in full. The body must contain every writable field; omitted fields are reset to their default/null, not left untouched. | Yes | No | Required (full representation) | `PUT /api/v1/sales/customers/91` |
| `PATCH` | Partially update a resource. Only the fields present in the body are changed; all other fields are untouched. | Yes | No | Required (partial representation) | `PATCH /api/v1/sales/customers/91` |
| `DELETE` | Soft-delete a resource (sets `deleted_at`). For posted financial records this is disallowed outright — see Business Rules in the owning module doc; reversal/voiding is used instead. | Yes | No | None (query params only) | `DELETE /api/v1/inventory/products/220` |

Rules governing verb choice:

- **`PATCH` is preferred over `PUT`** for QAYD resources because most updates are partial (e.g. changing
  an invoice's `due_date` without resending every line item). `PUT` is reserved for resources whose
  entire representation is naturally replaced in one operation, such as replacing the full set of
  `price_list_items` under a price list.
- **`POST` for actions**: any operation that is not a pure create — posting a journal entry, approving a
  payroll run, reversing a bill — is a `POST` to a sub-resource path ending in a verb (see Sub-Resources
  & Actions). This keeps the noun-based resource paths clean and keeps `POST` semantics ("do something,
  possibly non-idempotent") consistent.
- **`GET` never accepts a body.** Filtering, sorting, search, and pagination are expressed exclusively as
  query parameters (see Collections vs Single Resource). This keeps `GET` requests cacheable and
  bookmarkable, and keeps behavior identical across every HTTP client and CDN.
- **`HEAD` and `OPTIONS`** are supported automatically by the Laravel router for CORS pre-flight and
  existence checks; they are not separately documented per endpoint.

# Status Codes

Every response uses a single HTTP status code that reflects the outcome of the *entire* request. The
envelope's `success` boolean must always agree with the status code (2xx implies `true`; 4xx/5xx implies
`false`). Mixed states — for example, "created, but one side effect failed" — are represented as a 2xx
response whose `data.warnings` array explains the partial issue; they are never represented by picking a
code that doesn't match `success`.

| Code | Name | When to Use |
|---|---|---|
| `200` | OK | Successful `GET`, `PATCH`, `PUT`, or a `POST` action that does not create a new resource (e.g. `POST /journal-entries/482/post`, `POST /bills/77/approve`). |
| `201` | Created | Successful `POST` that creates a new resource. Response includes the created resource in `data` and a `Location` header pointing at its canonical URI. |
| `202` | Accepted | Request is valid and queued for asynchronous processing (e.g. AI document extraction, large report generation, bulk import). `data` contains a job/task reference; the client polls `GET /api/v1/reports/report-runs/{id}` or listens for the `ai.finished` webhook. |
| `204` | No Content | Successful `DELETE`, or any action with no meaningful response body. Response has an empty body; no envelope is emitted (there is nothing to envelope). |
| `400` | Bad Request | Malformed request that never reached routing/validation logic — invalid JSON syntax, wrong `Content-Type`, missing required header (`X-Company-Id` absent). Distinct from `422`, which is well-formed but semantically invalid. |
| `401` | Unauthorized | Missing, malformed, expired, or revoked bearer token. The caller is not authenticated at all. |
| `403` | Forbidden | Caller is authenticated but lacks the RBAC permission for this action on the active company (default-deny), or is attempting to act on a company/branch outside their assignment. |
| `404` | Not Found | The resource ID does not exist, OR it exists but belongs to a different company (multi-tenant isolation returns 404, never 403, to avoid leaking existence across tenants). |
| `409` | Conflict | The request is valid but conflicts with the current state of the resource — posting an already-posted journal entry, reusing a duplicate `Idempotency-Key` with a different body, closing a fiscal period that has open transactions, optimistic-lock version mismatch (`If-Match` header stale). |
| `422` | Unprocessable Entity | Well-formed request that fails FormRequest validation — required field missing, wrong type, business-rule validation (e.g. debit/credit totals don't balance, negative quantity). `errors[]` is always populated with field-level detail. |
| `429` | Too Many Requests | Redis-backed rate limiter has been exceeded for this token/company/IP. `Retry-After` header is set. |
| `500` | Internal Server Error | Unhandled server-side fault. The envelope's `message` is a generic, non-leaking string; the `request_id` is the correlation key for support/log lookup. Never exposes stack traces or SQL to the client. |
| `503` | Service Unavailable | A dependency the request needs is down (e.g. the FastAPI AI engine is unreachable for an AI-only endpoint, or the system is in scheduled maintenance). `Retry-After` is set when known. |

`400` vs `422` is the distinction engineers get wrong most often: `400` means the HTTP layer or JSON
parser rejected the request before it became a typed object; `422` means it became a typed object and
then failed business/schema validation. A missing `X-Company-Id` header is `400`. A present but
non-existent `company_id` value is `422` (fails a `exists:companies,id` rule) or `404` depending on where
the check lives; QAYD standard is `422` when the value is caller-supplied and fails validation, `404`
when it is a path parameter that doesn't resolve.

# Resource URIs & Nesting

All URIs are versioned, lower-case, kebab-case-for-multi-word-resources, and use plural nouns for
collections. The general shape is:

```
/api/v1/{module}/{resource}[/{id}][/{sub-resource}[/{sub-id}]][/{action}]
```

| Pattern | Example | Notes |
|---|---|---|
| Module + collection | `/api/v1/accounting/journal-entries` | Module prefix groups routes by domain and matches the module's route file. |
| Module + resource + id | `/api/v1/sales/invoices/4821` | `id` is the numeric primary key. |
| Nested sub-resource (collection) | `/api/v1/sales/invoices/4821/invoice-items` | Used when the sub-resource cannot exist without its parent (an invoice line has no meaning outside its invoice). |
| Nested sub-resource (single) | `/api/v1/sales/invoices/4821/invoice-items/9` | Full nesting for a specific line. |
| Action on a resource | `/api/v1/accounting/journal-entries/482/post` | Non-CRUD state transition; always a `POST`. |
| Flat reference, filtered | `/api/v1/sales/invoice-items?invoice_id=4821` | Used when the sub-resource is ALSO addressable independently (e.g. reporting queries across all invoice items). Both nested and flat forms may coexist and return identical objects; nested is preferred for writes, flat is preferred for cross-cutting reads. |

Rules:

- **Nesting depth is capped at two levels.** `/invoices/4821/invoice-items/9` is allowed;
  `/invoices/4821/invoice-items/9/tax-lines/2` is not — `tax-lines` becomes a flat, filterable resource
  (`/api/v1/sales/tax-lines?invoice_item_id=9`) instead. Deep nesting produces brittle clients and
  unreadable OpenAPI paths.
- **IDs are always the resource's own primary key**, never a composite or a slug, except where a
  `code` is explicitly documented as an alternate lookup (e.g. `GET
  /api/v1/accounting/accounts/by-code/{code}` as a secondary, clearly-named route — never overloading the
  primary `{id}` segment to mean two different things).
- **Module prefixes match the owning module** listed in the platform's canonical table registry:
  `accounting`, `sales`, `purchasing`, `banking`, `inventory`, `payroll`, `tax`, `reports`, `ai`,
  `settings`, `identity` (users/roles/permissions), `company` (companies/branches/departments).
- **Never verbs in the resource noun itself.** `/invoices/create` is wrong; `POST /invoices` is correct.
  Verbs only appear as an action suffix on an existing resource, per Sub-Resources & Actions.
- **Query parameters never carry secrets or tenant-scoping.** Company scoping travels exclusively via the
  `X-Company-Id` header (see Standard Headers), never as `?company_id=`, so that the same URL cannot be
  replayed against a different tenant by editing the query string.

# Request Format

Every request body is JSON (`application/json; charset=utf-8`), UTF-8 encoded, with no trailing commas
and no comments. Field names are `snake_case`, matching the underlying PostgreSQL columns, so that
request bodies map onto Eloquent models with zero renaming in the FormRequest layer.

Required headers on every authenticated request:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
X-Company-Id: 118
Content-Type: application/json
Accept: application/json
X-Request-Id: 6f2b9e2a-2f0a-4b7a-9a3d-6b1c2e9f0a11
```

Conditionally required:

```
Idempotency-Key: 3f7c9b0e-8a2d-4e21-9f10-7c4a5b6d8e90   # required on every non-idempotent POST that creates a resource
Accept-Language: ar-KW                                    # optional; defaults to en if absent
If-Match: "a1b2c3d4"                                       # required on PATCH/PUT of concurrency-sensitive resources (see Edge Cases)
```

Header contract:

- **`X-Company-Id`** (required, integer): the active company for this request. The authenticated user
  must have an entry in `company_users` for this company, or the request fails `403`. This header is the
  single source of tenant scoping — controllers never trust a `company_id` field inside the JSON body for
  scoping purposes (a `company_id` field inside a body, if present at all, is ignored or must match the
  header, else `422`).
- **`X-Request-Id`** (optional on request, always present on response): a client-generated UUIDv4 used to
  correlate a request across logs, the AI engine, and webhook deliveries. If the client omits it, the
  server generates one and still returns it in the response envelope's `request_id` field. If supplied by
  the client, the server echoes the same value.
- **`Idempotency-Key`** (required on financial-mutating `POST`, optional elsewhere): a client-generated
  UUIDv4. The server stores `(company_id, endpoint, idempotency_key) -> response` in Redis for 24 hours.
  A repeated request with the same key and an identical body returns the original `201`/`200` response
  unchanged (no duplicate side effect). A repeated request with the same key and a *different* body
  returns `409 Conflict`. This is mandatory for `invoices`, `receipts`, `bills`, `vendor-payments`,
  `bank-transactions`, `journal-entries` create/post endpoints, and any AI-triggered write, because
  network retries must never double-post money.
- **`Content-Type`**: must be `application/json`; anything else on a body-bearing request is `400`.
  File uploads (attachments) use `multipart/form-data` on the dedicated `/api/v1/attachments` endpoint
  only, never mixed into a JSON body as base64 (base64 is permitted only for small inline AI/OCR payloads
  under 200KB, explicitly documented per endpoint).

Example request body (creating a sales invoice):

```json
POST /api/v1/sales/invoices
Host: api.qayd.app
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
X-Company-Id: 118
Idempotency-Key: 3f7c9b0e-8a2d-4e21-9f10-7c4a5b6d8e90
Content-Type: application/json
Accept: application/json
Accept-Language: en

{
  "customer_id": 4021,
  "branch_id": 3,
  "currency_code": "KWD",
  "issue_date": "2026-07-16",
  "due_date": "2026-08-15",
  "cost_center_id": 12,
  "invoice_items": [
    {
      "product_id": 552,
      "description": "Consulting services — July",
      "quantity": "10.0000",
      "unit_price": "45.0000",
      "tax_code_id": 2
    }
  ],
  "notes": "Milestone 2 delivery",
  "tags": ["retainer", "priority"]
}
```

# Response Envelope Schema

Every JSON response (except `204 No Content`, which has no body) uses exactly this envelope shape, with
no exceptions and no module-specific variants:

```json
{
  "success": true,
  "data": {},
  "message": "Human readable message",
  "errors": [],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 0,
      "cursor": null
    }
  },
  "request_id": "uuid",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

Field contract:

| Field | Type | Always Present | Notes |
|---|---|---|---|
| `success` | boolean | Yes | Mirrors the HTTP status class (2xx → `true`, 4xx/5xx → `false`). |
| `data` | object \| array \| null | Yes | The payload. A single resource is an object; a collection is an array; actions with no payload return `null` or `{}`. |
| `message` | string | Yes | Human-readable, localized per `Accept-Language`. Safe to show directly in a UI toast. Never contains a stack trace or raw exception text. |
| `errors` | array | Yes | Empty `[]` on success. Populated on `422` (see error object shape below) and may carry a single generic entry on `500`. |
| `meta.pagination` | object | On collection endpoints | Absent (or `null`) on single-resource responses. Shape is fixed regardless of offset or cursor mode (see Collections vs Single Resource). |
| `meta.*` | object | Endpoint-specific | Additional non-pagination metadata (e.g. `meta.ai_confidence`, `meta.exchange_rate_used`) is documented per endpoint but always lives under `meta`, never at the envelope's top level. |
| `request_id` | string (UUIDv4) | Yes | Echo of `X-Request-Id`, or server-generated if absent. |
| `timestamp` | string (ISO 8601, UTC) | Yes | Server time at response generation, always UTC with a `Z` suffix, regardless of the caller's locale or company timezone. |

Validation error shape inside `errors[]` (used on `422`):

```json
{
  "errors": [
    {
      "field": "invoice_items.0.quantity",
      "code": "required",
      "message": "The quantity field is required."
    },
    {
      "field": "invoice_items.0.tax_code_id",
      "code": "exists",
      "message": "The selected tax code does not exist for this company."
    }
  ]
}
```

- `field` uses dot-notation matching the request body path, including array indices, so a client can map
  an error directly onto a form field or a table row.
  - `code` is a stable, machine-readable identifier for the failure type (`required`, `exists`, `unique`,
  `numeric`, `after:issue_date`, `balance_mismatch`, `insufficient_stock`, `permission_denied`) that a
  client can switch on without parsing the human message.
- `message` is the localized, human-readable explanation.

# Content Negotiation

QAYD is JSON-only over HTTPS. There is no XML, no HTML-rendered API response, and no protocol
negotiation beyond language and (rarely) file export format:

- **`Accept: application/json`** is the only supported media type for the core API. A request with
  `Accept: application/xml` or `Accept: text/html` receives `406 Not Acceptable` with a standard envelope
  explaining that only `application/json` is supported.
- **Exports** (PDF invoices, XLSX reports, CSV bank statements) are the sole exception and are served
  from dedicated endpoints such as `GET /api/v1/reports/report-runs/{id}/export?format=pdf`, which return
  the raw file with the matching `Content-Type` (`application/pdf`, `application/vnd.openxmlformats-...`,
  `text/csv`) and a `Content-Disposition: attachment; filename="..."` header — never wrapped in the JSON
  envelope, because the payload is binary. The `format` query parameter enumerates the supported values
  per endpoint; an unsupported value is `422`.
- **Language negotiation** uses `Accept-Language`, not `Accept` (see Localization).
- **API versioning** is in the URL path (`/api/v1/`), not content negotiation, so that a version bump
  cannot be silently missed by a client that doesn't send a header. A future `/api/v2/` is a fully
  separate, parallel-run path; QAYD does not do in-place breaking changes to `v1`.

# Standard Headers

Request headers:

| Header | Required | Purpose |
|---|---|---|
| `Authorization` | Yes (except public/webhook-signature endpoints) | `Bearer <token>` — Sanctum personal access token or JWT. |
| `X-Company-Id` | Yes (except identity/company-list endpoints) | Active tenant scope. |
| `Content-Type` | Yes on body-bearing requests | `application/json` (or `multipart/form-data` for `/attachments`). |
| `Accept` | Yes | `application/json`. |
| `Accept-Language` | No | `en` or `ar` (optionally region-tagged, e.g. `ar-KW`). Defaults to `en`. |
| `X-Request-Id` | No | Client-supplied correlation UUID; server generates one if absent. |
| `Idempotency-Key` | Conditional | Required on financial-mutating creates (see Request Format). |
| `If-Match` | Conditional | Required on updates to concurrency-sensitive resources; carries the resource's current `etag`/version. |
| `X-Client-Platform` | No | `web` \| `mobile-ios` \| `mobile-android` \| `integration`. Used for analytics and platform-specific rate-limit tiers, never for authorization decisions. |

Response headers:

| Header | Always Present | Purpose |
|---|---|---|
| `Content-Type` | Yes | `application/json; charset=utf-8` (or the export MIME type for file endpoints). |
| `X-Request-Id` | Yes | Same value as the envelope's `request_id`, duplicated at the transport layer for proxies/log shippers that don't parse bodies. |
| `X-RateLimit-Limit` | Yes | Requests allowed in the current window for this token/company. |
| `X-RateLimit-Remaining` | Yes | Requests left in the current window. |
| `X-RateLimit-Reset` | Yes | Unix timestamp when the window resets. |
| `Retry-After` | On `429`/`503` | Seconds to wait before retrying. |
| `Location` | On `201` | Canonical URI of the newly created resource. |
| `ETag` | On resources supporting optimistic locking | Opaque version token; echo via `If-Match` on the next write. |
| `Cache-Control` | On cacheable `GET` responses | e.g. `private, max-age=30` for reference data like `account_types`; `no-store` for anything containing balances or PII. |

# Collections vs Single Resource

A collection endpoint (`GET /api/v1/accounting/journal-entries`) always returns `data` as a JSON array
and always populates `meta.pagination`. A single-resource endpoint (`GET
/api/v1/accounting/journal-entries/482`) always returns `data` as a JSON object and either omits
`meta.pagination` or sets it to `null` — a single resource is never wrapped in a one-element array.

Collections support these query parameters uniformly:

| Parameter | Example | Behavior |
|---|---|---|
| `page` | `?page=2` | Offset-style pagination, 1-indexed. Mutually exclusive with `cursor`. |
| `per_page` | `?per_page=50` | Default `25`, max `100`. Values above the max are clamped, not rejected. |
| `cursor` | `?cursor=eyJpZCI6NDgyfQ` | Opaque, base64url-encoded cursor for cursor-style pagination on large or frequently-mutated tables (e.g. `ledger_entries`, `audit_logs`). Mutually exclusive with `page`. |
| `sort` | `?sort=-issue_date,customer_id` | Comma-separated fields; `-` prefix means descending. Unknown fields are `422`. |
| `filter[field]` | `?filter[status]=posted&filter[branch_id]=3` | Exact-match filtering per field. Range filters use suffixes: `filter[issue_date][gte]=2026-07-01`. |
| `search` | `?search=acme+corp` | Free-text search across the resource's documented searchable fields (e.g. customer name, invoice number). Uses PostgreSQL full-text search. |
| `include` | `?include=customer,invoice_items` | Eager-loads named relationships into `data[].customer` / `data[].invoice_items` to avoid N+1 client round-trips. Unlisted relationships are never auto-included. |
| `fields` | `?fields=id,invoice_number,total_amount` | Sparse fieldset — restricts the returned object to the listed keys, always including `id`. |

Pagination response shape (offset mode):

```json
"meta": {
  "pagination": { "page": 2, "per_page": 25, "total": 138, "cursor": null }
}
```

Pagination response shape (cursor mode — `total` is omitted/`null` because counting a live cursor set is
expensive; `cursor` carries the next page's token, `null` when exhausted):

```json
"meta": {
  "pagination": { "page": null, "per_page": 25, "total": null, "cursor": "eyJpZCI6NTA3fQ" }
}
```

# Sub-Resources & Actions

Non-CRUD operations — anything that is a state transition or a business action rather than a plain
create/read/update/delete — are modeled as a `POST` to a verb-suffixed path on the resource, never as a
special value written into a `status` field via `PATCH`. This keeps state transitions auditable,
individually permissioned, and individually rate-limited, and it lets each action carry its own request
body shape (e.g. `post` needs a `posting_date`; `approve` needs a `note`) without overloading a generic
update endpoint.

| Method | Path | Permission | Description |
|---|---|---|---|
| `POST` | `/api/v1/accounting/journal-entries/{id}/post` | `accounting.journal.post` | Transitions a `draft` journal entry to `posted`. Validates SUM(debits) = SUM(credits). Immutable afterward. |
| `POST` | `/api/v1/accounting/journal-entries/{id}/reverse` | `accounting.journal.post` | Creates a new reversing entry mirroring the original with debits/credits swapped; original stays `posted`. |
| `POST` | `/api/v1/sales/invoices/{id}/void` | `sales.invoice.void` | Marks an unpaid invoice `voided`; requires approval chain if already sent to the customer. |
| `POST` | `/api/v1/banking/transfers/{id}/approve` | `bank.transfer` | Second-approver sign-off on a pending bank transfer; human-approval-chain gated (see Permissions). |
| `POST` | `/api/v1/payroll/payroll-runs/{id}/calculate` | `payroll.calculate` | Runs payroll computation (draft payslips); does not release funds. |
| `POST` | `/api/v1/payroll/payroll-runs/{id}/release` | `payroll.approve` | Releases a calculated payroll run for payment; requires human approval chain. |
| `POST` | `/api/v1/tax/tax-returns/{id}/submit` | `tax.submit` | Submits a prepared tax return to the authority; requires human approval chain. |
| `POST` | `/api/v1/inventory/stock-adjustments/{id}/approve` | `inventory.adjust` | Approves a pending stock adjustment, posting the corresponding journal entry via a domain event. |
| `POST` | `/api/v1/purchasing/bills/{id}/match` | `purchasing.bill.match` | Runs three-way match (PO / goods receipt / bill) and returns a match result with a discrepancy list. |
| `POST` | `/api/v1/reports/report-runs` | `reports.export` | Triggers an async report generation job; returns `202` with a job reference. |

Rules for action endpoints:

- The action verb is always an English infinitive (`post`, `approve`, `reverse`, `void`, `submit`,
  `release`, `calculate`, `match`), never a past tense or a status noun (`/posted` is wrong).
- An action endpoint's success response returns the **affected resource in its new state** under `data`,
  not just a bare acknowledgment — the client should never need a follow-up `GET` to see the effect.
- An action that is not currently valid for the resource's state returns `409 Conflict` with a `code` of
  `invalid_state_transition` inside `errors[]`, naming the current state and the allowed next states in
  `message`.
- Actions gated by a human-approval chain return `202 Accepted` (not `200`) when the action only *enters*
  the approval workflow rather than completing it immediately; the resource's `data.approval_status`
  reflects `pending_approval`.

# Date/Time & Money Formatting

- **Timestamps**: always ISO 8601, always UTC, always with an explicit `Z` suffix —
  `"2026-07-16T12:00:00Z"`. Never a naive datetime, never a Unix epoch integer in a JSON body (epoch
  integers are permitted only inside opaque cursor tokens, which clients must not parse). Clients convert
  to the company's configured timezone/fiscal calendar for display; the API never returns pre-localized
  timestamps.
- **Dates without time** (e.g. `issue_date`, `due_date`, fiscal period boundaries): `"2026-07-16"`,
  `YYYY-MM-DD`, no time component, no timezone — these are calendar dates in the company's fiscal
  calendar, not instants.
- **Money**: every monetary field is a **JSON string**, never a JSON number — `"unit_price": "45.0000"`,
  not `45.0`. This avoids floating-point precision loss across every language's JSON parser. The string
  always carries the full `NUMERIC(19,4)` scale (4 decimal places), even when trailing zeros. Every
  monetary object also carries its currency explicitly; there is no implicit "assume base currency."
- **Multi-currency amount object** — used consistently wherever an amount is transaction-currency-aware
  (invoices, bills, payments, bank transactions):

```json
{
  "amount": "450.0000",
  "currency_code": "KWD",
  "exchange_rate": "1.000000",
  "base_amount": "450.0000",
  "base_currency_code": "KWD"
}
```

  When the transaction currency differs from the company's base currency, `exchange_rate` is the rate
  applied at the transaction's effective date (`NUMERIC(18,6)`), and `base_amount` is the pre-computed
  converted value — clients never recompute this themselves.
- **Quantities**: JSON strings at `NUMERIC(18,4)` scale, same rationale as money — `"quantity": "10.0000"`.
- **Percentages** (tax rates, discount rates): JSON strings expressing the rate as a decimal fraction with
  4-decimal precision, e.g. `"rate": "0.0500"` for 5%, never `"5%"` or the integer `5`.

# Localization

QAYD supports English and Arabic end-to-end, with Arabic rendered RTL in every client. The API's role in
localization is narrow but strict:

- **`Accept-Language` header** drives which language the server returns in every human-readable string
  field: the envelope's `message`, error `message` values, and any `_display`-suffixed convenience field
  (e.g. `status_display: "مرحّل"` alongside the language-neutral `status: "posted"`). Supported values are
  `en` and `ar` (region subtags like `ar-KW` or `en-US` are accepted and normalized to the base language).
  An unsupported or missing value falls back to `en` — the API never guesses from IP or company default
  silently in a way that changes behavior between identical requests.
- **Bilingual master data** (per the shared design context) stores both `name_en` and `name_ar`
  simultaneously on every master-data table (customers, vendors, products, accounts, cost centers,
  etc.). The API returns **both fields always**, regardless of `Accept-Language` — localization of stored
  data is a client-rendering concern (pick `name_ar` when the UI is RTL), while localization of
  *server-generated* text (`message`, validation errors, `status_display`) is a server concern driven by
  the header. This split avoids the API silently hiding data the client might need (e.g. an audit screen
  that must show both languages side by side).
- **Numerals and RTL punctuation** are never applied server-side to numeric JSON string fields (money,
  quantities, dates always use Western Arabic numerals and ISO formats regardless of language, per
  Date/Time & Money Formatting) — RTL digit shaping and locale-specific separators are exclusively a
  client rendering responsibility, keeping every numeric value machine-parseable identically in both
  languages.
- **Validation error messages** are fully localized via `Accept-Language`, but the `code` field
  (`required`, `exists`, `balance_mismatch`) is always the same stable, language-neutral token so client
  logic never branches on translated text.

# Examples

**1. `GET` — list a collection, filtered and paginated**

Request:

```
GET /api/v1/sales/invoices?filter[status]=posted&filter[branch_id]=3&sort=-issue_date&page=1&per_page=25&include=customer
X-Company-Id: 118
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
Accept: application/json
Accept-Language: en
```

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": 4821,
      "invoice_number": "INV-2026-004821",
      "customer_id": 4021,
      "customer": { "id": 4021, "name_en": "Acme Trading Co.", "name_ar": "شركة أكمي التجارية" },
      "branch_id": 3,
      "status": "posted",
      "currency_code": "KWD",
      "total_amount": "450.0000",
      "issue_date": "2026-07-16",
      "due_date": "2026-08-15",
      "created_at": "2026-07-16T09:12:03Z",
      "updated_at": "2026-07-16T09:14:51Z"
    }
  ],
  "message": "Invoices retrieved successfully.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "6f2b9e2a-2f0a-4b7a-9a3d-6b1c2e9f0a11",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

**2. `POST` — create a resource**

Request: as shown in Request Format (`POST /api/v1/sales/invoices`).

Response `201`:

```json
{
  "success": true,
  "data": {
    "id": 4821,
    "invoice_number": "INV-2026-004821",
    "customer_id": 4021,
    "branch_id": 3,
    "status": "draft",
    "currency_code": "KWD",
    "total_amount": "450.0000",
    "issue_date": "2026-07-16",
    "due_date": "2026-08-15",
    "invoice_items": [
      {
        "id": 9911,
        "product_id": 552,
        "description": "Consulting services — July",
        "quantity": "10.0000",
        "unit_price": "45.0000",
        "line_total": "450.0000",
        "tax_code_id": 2
      }
    ],
    "created_at": "2026-07-16T12:00:00Z",
    "updated_at": "2026-07-16T12:00:00Z"
  },
  "message": "Invoice created successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3f7c9b0e-8a2d-4e21-9f10-7c4a5b6d8e90",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

Headers include `Location: /api/v1/sales/invoices/4821`.

**3. `PATCH` — partial update**

Request:

```
PATCH /api/v1/sales/invoices/4821
X-Company-Id: 118
If-Match: "a1b2c3d4"
Content-Type: application/json

{ "due_date": "2026-08-30", "notes": "Extended per customer request" }
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": 4821,
    "due_date": "2026-08-30",
    "notes": "Extended per customer request",
    "updated_at": "2026-07-16T12:05:11Z"
  },
  "message": "Invoice updated successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b2d4e6f8-1a3c-4e5a-8b7d-9f0e1c2b3a4d",
  "timestamp": "2026-07-16T12:05:11Z"
}
```

**4. `DELETE` — soft delete**

Request: `DELETE /api/v1/inventory/products/220` with `X-Company-Id: 118`.

Response `204` — empty body, headers only:

```
HTTP/1.1 204 No Content
X-Request-Id: 9a8b7c6d-5e4f-3a2b-1c0d-8e7f6a5b4c3d
```

**5. Action — non-CRUD state transition**

Request:

```
POST /api/v1/accounting/journal-entries/482/post
X-Company-Id: 118
Content-Type: application/json

{ "posting_date": "2026-07-16" }
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": 482,
    "status": "posted",
    "posting_date": "2026-07-16",
    "posted_by": 17,
    "posted_at": "2026-07-16T12:07:44Z",
    "total_debit": "450.0000",
    "total_credit": "450.0000"
  },
  "message": "Journal entry posted successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "c3d5e7f9-2b4d-4f6a-9c8e-1d2f3a4b5c6d",
  "timestamp": "2026-07-16T12:07:44Z"
}
```

**6. Validation failure**

Request: `POST /api/v1/accounting/journal-entries` with an unbalanced entry.

Response `422`:

```json
{
  "success": false,
  "data": null,
  "message": "The journal entry could not be created due to validation errors.",
  "errors": [
    {
      "field": "journal_lines",
      "code": "balance_mismatch",
      "message": "Total debits (450.0000) do not equal total credits (400.0000)."
    }
  ],
  "meta": { "pagination": null },
  "request_id": "d4e6f8a0-3c5e-4a7b-8d9f-2e3a4b5c6d7e",
  "timestamp": "2026-07-16T12:10:02Z"
}
```

**7. Async action — `202 Accepted`**

Request: `POST /api/v1/reports/report-runs` with `{"report_definition_id": 14, "period": "2026-06"}`.

Response `202`:

```json
{
  "success": true,
  "data": { "id": 771, "status": "queued", "report_definition_id": 14, "period": "2026-06" },
  "message": "Report generation has been queued.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "e5f7a9b1-4d6f-4b8c-9e0f-3a4b5c6d7e8f",
  "timestamp": "2026-07-16T12:11:19Z"
}
```

# Edge Cases

- **Idempotent retry with identical key, identical body**: server returns the original response verbatim
  (same `request_id` as originally generated, same `201`), and performs no new side effect. This is the
  mechanism that makes network timeouts and client retries safe for money-moving endpoints.
- **Idempotent retry with identical key, different body**: `409 Conflict`, `code: "idempotency_key_reused"`,
  explaining the key is already bound to a different payload. The client must generate a new key for a
  genuinely different request.
- **Optimistic lock conflict**: `PATCH`/`PUT` with a stale `If-Match` value returns `409 Conflict`,
  `code: "version_conflict"`, and `data` contains the current server-side representation so the client can
  re-diff and retry without a second round-trip.
- **Cross-tenant resource ID**: requesting `/api/v1/sales/invoices/4821` under a `X-Company-Id` that does
  not own invoice 4821 returns `404 Not Found`, not `403` — QAYD never confirms the existence of another
  tenant's record, even to say access is forbidden.
- **Posted/immutable financial records**: `PATCH`/`PUT`/`DELETE` on a `posted` journal entry, a sent
  invoice past its grace window, or a released payroll run returns `409 Conflict`,
  `code: "immutable_record"`. The response `message` names the reversing/voiding action endpoint the
  client should use instead.
- **Pagination beyond the last page**: `page` beyond `total_pages` returns `200` with `data: []`, not
  `404` — an empty result set is not an error.
- **Sort/filter on an unindexed or non-existent field**: `422`, `code: "invalid_sort_field"` /
  `"invalid_filter_field"`, listing the allowed fields for that endpoint in `message`.
- **Rate limit exceeded mid-batch**: if a client is bulk-creating resources one `POST` at a time and hits
  `429` partway through, already-created resources are **not** rolled back — each request is its own
  atomic unit. The client resumes from `Retry-After` using the same `Idempotency-Key`s it already sent for
  any request it is unsure whether succeeded.
- **AI-engine unavailable**: any endpoint whose only purpose is AI-driven (e.g. document OCR extraction)
  returns `503 Service Unavailable` with `Retry-After` when the FastAPI engine health check fails, rather
  than hanging until a gateway timeout; endpoints that merely *use* AI as an optional enhancement (e.g. a
  categorization suggestion) degrade gracefully and return `200` with `meta.ai_suggestion: null` and a note
  in `message`.
- **Currency mismatch on a payment allocation**: allocating a receipt in `USD` against an invoice issued
  in `KWD` is not silently converted — the request is `422`, `code: "currency_mismatch"`, unless the
  request explicitly supplies an `exchange_rate` and `allow_cross_currency: true`, in which case the
  conversion and both amounts are echoed back in `data` for confirmation before any posting occurs.
- **Empty `PATCH` body**: a `PATCH` with `{}` is `422`, `code: "empty_patch"` — a no-op update is treated
  as a client error rather than silently returning `200` with the unchanged resource, to catch client bugs
  early.
- **Webhook delivery failure**: webhook events (`invoice.created`, `invoice.paid`, `payment.received`,
  `payroll.completed`, `inventory.updated`, `bank.synced`, `ai.finished`, `journal.posted`) are retried
  with exponential backoff up to 24 hours; the triggering API response itself is never blocked on webhook
  delivery — the API call that raised the event always returns based on its own success, independent of
  whether subscribers receive the webhook.
- **Large collection export via `GET`**: a `GET` with `per_page` at the maximum (`100`) against a
  reporting-scale table like `ledger_entries` still completes synchronously within the request, but the
  API discourages full-table scraping by capping filterable date ranges to 12 months per request on
  high-volume endpoints (documented per endpoint); larger extracts use the async `report-runs` pattern
  instead of paginated `GET` looping.

# End of Document
