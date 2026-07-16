# API Error Handling — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API Error Handling
---

# Purpose

QAYD is an AI Financial Operating System. Its Laravel 12 (PHP 8.4+) backend is the single source of
truth for every business and financial fact in the platform; the FastAPI/Python AI engine, the Next.js
web client, the Flutter mobile client, and all third-party integrations reach that truth exclusively
through the REST API under `/api/v1/`. Nothing — not the AI engine, not a partner integration, not an
internal batch job — is permitted to read or write the database directly.

Because every write in QAYD can represent money moving, a ledger being posted, a payroll being
released, or a tax return being submitted, the error-handling contract is not a cosmetic layer. It is
the mechanism by which the API refuses to let inconsistent, unauthorized, or ambiguous financial state
ever exist. An error response in QAYD must always let the caller answer three questions without
inspecting server logs: *what exactly went wrong*, *is it safe to retry*, and *what does the caller do
next*. This document defines the single error contract every endpoint, every middleware layer, and
every SDK in the QAYD ecosystem must implement, in the same register as Stripe, Twilio, SAP, and
Oracle API error documentation: precise, machine-parseable, and example-driven.

This document governs: the shape of every error response (the envelope), the HTTP status codes QAYD
uses and what each means in financial-domain terms, the canonical catalog of machine-readable error
codes, the field-level shape of validation failures, how business-rule violations (unbalanced entries,
locked periods, credit limits, RBAC denials) are represented, how error messages are localized into
Arabic and English, how `request_id` powers support and tracing, which errors are safe to retry
automatically, how official SDKs should surface errors to calling code, what QAYD will never leak in
an error body, worked JSON examples for every error class, and a set of edge cases every client
implementation must handle correctly.

# Error Envelope

Every QAYD API response — success or failure — uses the same top-level envelope. Error responses do
not get a special shape; they populate the same `errors` array that is always present (and empty) on
success, and they set `"success": false`.

```json
{
  "success": false,
  "data": null,
  "message": "The request could not be completed. See errors for details.",
  "errors": [
    {
      "code": "VALIDATION_ERROR",
      "field": "amount",
      "message": "The amount must be greater than zero.",
      "meta": {}
    }
  ],
  "meta": {
    "pagination": null
  },
  "request_id": "3f1e9b2a-6c3d-4a9a-8f7e-1b2c3d4e5f6a",
  "timestamp": "2026-07-16T12:03:41Z"
}
```

Envelope field contract:

| Field | Type | Present on error | Description |
|---|---|---|---|
| `success` | boolean | always `false` | Single boolean a client can branch on before touching anything else. |
| `data` | object \| null | always `null` on error | Never partially populated. An error response never returns a half-written resource. |
| `message` | string | always | Human-readable, localized summary of the *overall* failure. Safe to show in a toast. |
| `errors` | array | always (non-empty on error) | One entry per discrete problem. See Error Code Catalog and Validation Errors below for the shape of each entry. |
| `meta` | object | always | Present but typically `{ "pagination": null }` on error responses; may carry error-class-specific metadata (see per-class sections). |
| `request_id` | string (UUID) | always | Correlates this exact response to server-side logs, traces, and support tickets. Also echoed as the `X-Request-Id` response header. |
| `timestamp` | string (ISO 8601, UTC) | always | Server time the response was generated, independent of any timestamp inside `errors[].meta`. |

Two invariants every client and every Laravel controller must honor:

1. `success: false` if and only if `errors` is non-empty. A response is never `success: true` with
   populated `errors`, and never `success: false` with an empty `errors` array.
2. `data` is `null` on every error response. QAYD never returns a partially-applied resource (e.g. a
   journal entry that was created but failed to post) as `data` alongside an error; the operation is
   either fully committed (success) or fully rolled back (error). This is enforced at the Service layer
   via database transactions wrapping every multi-step financial operation.

# HTTP Status Codes

QAYD uses eight HTTP status codes across the entire API. No endpoint may return a status outside this
table; if a new failure mode does not fit one of these, it is mapped to the closest one below rather
than inventing a new HTTP code.

| Status | Name | When QAYD returns it | Retry semantics |
|---|---|---|---|
| 400 | Bad Request | Malformed request the framework itself rejects before it reaches validation: invalid JSON body, wrong `Content-Type`, missing required header format (e.g. `X-Company-Id` present but not a valid integer). | Retryable only after the client fixes the request. |
| 401 | Unauthorized | Missing, expired, malformed, or revoked Sanctum/JWT bearer token. No authenticated identity could be established. | Retryable after re-authentication (refresh token / re-login). |
| 403 | Forbidden | Identity is known but lacks the permission for the action (RBAC default-deny), or the authenticated user does not belong to the company in `X-Company-Id`, or a sensitive operation requires an approval chain that has not been satisfied. | Not retryable without a permission/role change or an approval. |
| 404 | Not Found | The resource does not exist, OR it exists but belongs to a different company (QAYD returns 404, never 403, for cross-tenant existence — see Security). | Not retryable with the same identifier. |
| 409 | Conflict | The request is well-formed and authorized, but conflicts with the current state of the resource: posting an already-posted journal entry, voiding a reconciled bank transaction, editing a closed fiscal period, a concurrent edit lost an optimistic-lock check. | Retryable only after the client re-fetches current state. |
| 422 | Unprocessable Entity | Request is syntactically valid JSON but fails field-level validation (`VALIDATION_ERROR`) or a business rule tied to the payload's content rather than a state conflict (e.g. `UNBALANCED_ENTRY`, `CREDIT_LIMIT_EXCEEDED`). | Retryable only after the client corrects the payload. |
| 429 | Too Many Requests | Redis-backed rate limiter rejected the request. `Retry-After` header is always present in seconds. | Retryable after the `Retry-After` window. |
| 500 | Internal Server Error | Unhandled exception inside Laravel, an AI engine timeout that the API could not gracefully degrade, or an infrastructure fault (DB connection loss, queue failure). | Retryable with backoff; QAYD guarantees these are logged with full `request_id` correlation. |

Status-to-domain mapping notes specific to QAYD:

- **403 vs 404 for tenancy**: a request for `GET /api/v1/accounting/journal-entries/{id}` where `{id}`
  belongs to another company returns `404 Not Found`, not `403 Forbidden`. QAYD never confirms the
  *existence* of another company's record to a caller who cannot access it (see Security).
- **409 is state, 422 is content**: if the same underlying rule can be violated either by "the record
  changed underneath you" or by "the payload you sent is invalid on its face," QAYD always prefers 409
  for the former and 422 for the latter. Example: posting a journal entry into a period that was open
  when you loaded the form but got closed by another user before you submitted → 409
  `PERIOD_LOCKED`. Submitting a journal entry that references a `fiscal_period_id` that was already
  closed at submission time and the client should have known → 422 `PERIOD_LOCKED` with the same code
  but different status, distinguished via the `meta.state_changed` flag described in Business-Rule
  Errors.
- **401 never carries a body message about *why***: to avoid leaking whether a token is malformed vs.
  expired vs. revoked vs. belongs to a disabled user, 401 responses always use the single code
  `AUTHENTICATION_FAILED` with a generic message. Distinguishing detail is only in server-side logs
  keyed by `request_id`.

# Error Code Catalog

Every entry in `errors[]` carries a `code` from this catalog. Codes are stable, upper-snake-case,
English-only identifiers intended for client `switch` statements — never localize the code itself,
only `message`. New codes may be added over time (additive, non-breaking); existing codes are never
renamed or repurposed.

| Code | HTTP Status | Category | Meaning |
|---|---|---|---|
| `VALIDATION_ERROR` | 422 | Validation | One or more fields failed FormRequest validation rules. |
| `MISSING_REQUIRED_FIELD` | 422 | Validation | A specific required field was absent (subset of `VALIDATION_ERROR`, used when the client wants field-presence granularity). |
| `INVALID_FORMAT` | 422 | Validation | Field present but does not match expected format (date, currency code, IBAN, email). |
| `DUPLICATE_ENTRY` | 409 | Business Rule | Unique-constraint violation surfaced as a domain error (e.g. duplicate invoice number within a company). |
| `AUTHENTICATION_FAILED` | 401 | Auth | Bearer token missing, invalid, expired, or revoked. |
| `TOKEN_EXPIRED` | 401 | Auth | Refresh-eligible variant of `AUTHENTICATION_FAILED`, returned only from token-refresh-aware endpoints so SDKs know a silent refresh may succeed. |
| `INSUFFICIENT_PERMISSION` | 403 | Authorization | Authenticated user's role does not grant the permission key required for this action. |
| `COMPANY_MISMATCH` | 403 | Authorization | `X-Company-Id` does not match a company the authenticated user belongs to. |
| `APPROVAL_REQUIRED` | 403 | Authorization | Action is sensitive (bank transfer, payroll release, tax submission, permission change) and needs a pending or completed approval chain. |
| `RESOURCE_NOT_FOUND` | 404 | Not Found | Generic "no such resource," including cross-tenant lookups (see Security). |
| `PERIOD_LOCKED` | 409 / 422 | Business Rule | Target `fiscal_period_id` is closed or locked; no posting, edit, or reversal permitted. |
| `UNBALANCED_ENTRY` | 422 | Business Rule | `SUM(journal_lines.debit) != SUM(journal_lines.credit)` for a journal entry submission. |
| `ALREADY_POSTED` | 409 | Business Rule | Attempt to edit or re-post a `journal_entries` row already in `posted` state; posted lines are immutable. |
| `CREDIT_LIMIT_EXCEEDED` | 422 | Business Rule | A sales order or invoice would push a customer's outstanding balance past `customers.credit_limit`. |
| `INSUFFICIENT_STOCK` | 422 | Business Rule | A stock-out operation (delivery, adjustment, transfer) would drive `inventory_items.quantity_on_hand` negative without a negative-stock override permission. |
| `RECONCILED_LOCK` | 409 | Business Rule | Attempt to modify a `bank_transactions` row already matched in a completed `bank_reconciliations` record. |
| `CURRENCY_MISMATCH` | 422 | Business Rule | Multi-currency document mixes `currency_code` values that are not reconcilable via a stored `exchange_rate`. |
| `RATE_LIMITED` | 429 | Throttling | Redis rate limiter tripped for this token/IP/endpoint combination. |
| `CONCURRENT_MODIFICATION` | 409 | Concurrency | Optimistic-lock (`updated_at`/version) mismatch: the resource changed between read and write. |
| `WEBHOOK_DELIVERY_FAILED` | 500 (async, not HTTP) | Integration | Reported via the webhook's own retry/status record, not a live HTTP response; listed here because it shares the code catalog. |
| `AI_CONFIDENCE_TOO_LOW` | 422 | AI | An AI-proposed action was submitted for execution but its confidence score fell below the action's configured auto-execute threshold; requires human approval instead. |
| `INTERNAL_ERROR` | 500 | System | Unhandled server fault. Body never contains stack traces or internals (see Security). |

# Validation Errors

Validation errors always return HTTP `422` with the code `VALIDATION_ERROR` (or, when the client
specifically needs field-presence detail, `MISSING_REQUIRED_FIELD` / `INVALID_FORMAT`) and populate one
entry in `errors[]` per invalid field — never one aggregated string. This mirrors how Laravel
`FormRequest::rules()` failures are already structured internally, so the Service layer does no
translation work beyond localization.

Shape of a single validation error entry:

```json
{
  "code": "VALIDATION_ERROR",
  "field": "invoice_items.1.quantity",
  "message": "The quantity must be at least 1.",
  "meta": {
    "rule": "min",
    "constraint": 1,
    "given": 0
  }
}
```

Field contract:

| Field | Type | Description |
|---|---|---|
| `code` | string | Always a catalog code; `VALIDATION_ERROR` for general cases. |
| `field` | string | Dot-path into the request body, including array indices, e.g. `invoice_items.1.quantity`. `null` only for whole-payload errors (e.g. "body must not be empty"). |
| `message` | string | Localized (see Localization). Specific to this field, not the whole request. |
| `meta.rule` | string | The Laravel validation rule that failed (`required`, `min`, `max`, `date`, `in`, `exists`, `unique`, `regex`, …), for programmatic client handling (e.g. highlighting a specific form-control style). |
| `meta.constraint` | mixed | The rule's parameter, when applicable (e.g. `1` for `min:1`, `["USD","KWD","SAR","AED"]` for `in:USD,KWD,SAR,AED`). |
| `meta.given` | mixed | Echo of the offending value, EXCEPT for fields flagged sensitive (passwords, national ID, bank account numbers) — those omit `given` entirely rather than echo it back. |

Full example — creating an invoice with two field errors:

```
POST /api/v1/sales/invoices
X-Company-Id: 104
Authorization: Bearer eyJhbGciOi...
Content-Type: application/json

{
  "customer_id": 5501,
  "currency_code": "US",
  "invoice_items": [
    { "product_id": 771, "quantity": 0, "unit_price": 12.5 }
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
      "code": "INVALID_FORMAT",
      "field": "currency_code",
      "message": "The currency code must be a valid ISO 4217 code.",
      "meta": { "rule": "size", "constraint": 3, "given": "US" }
    },
    {
      "code": "VALIDATION_ERROR",
      "field": "invoice_items.0.quantity",
      "message": "The quantity must be at least 1.",
      "meta": { "rule": "min", "constraint": 1, "given": 0 }
    }
  ],
  "meta": { "pagination": null },
  "request_id": "9a2d7e10-4b3f-4c9a-9e11-2f6a8b0c4d55",
  "timestamp": "2026-07-16T12:05:02Z"
}
```

Validation always runs to completion and returns every failing field in one response — QAYD never
makes a client fix one field, resubmit, and discover the next error. This is enforced by using a single
`FormRequest::rules()` pass per endpoint rather than sequential ad-hoc checks in the controller.

# Business-Rule Errors

Business-rule errors represent a request that is syntactically valid and individually authorized, but
which the domain forbids given the current state of the ledger, the period, the customer, or the
inventory. QAYD returns `409` when the violation is about *state* (something changed, or something is
locked) and `422` when the violation is about the *content* of the payload judged against rules that
don't depend on a race condition. Both share the same `errors[].code` catalog entries; the HTTP status
is what tells the client which recovery path applies.

```json
{
  "success": false,
  "data": null,
  "message": "This journal entry cannot be posted.",
  "errors": [
    {
      "code": "UNBALANCED_ENTRY",
      "field": "journal_lines",
      "message": "Total debits (1,250.0000) do not equal total credits (1,200.0000).",
      "meta": {
        "total_debit": "1250.0000",
        "total_credit": "1200.0000",
        "difference": "50.0000",
        "currency_code": "KWD"
      }
    }
  ],
  "meta": { "pagination": null },
  "request_id": "6c1a9e77-2d4b-4f88-9a10-5e3c7b2a1f90",
  "timestamp": "2026-07-16T12:06:44Z"
}
```

Canonical business-rule scenarios and their resolution:

| Scenario | Code | Status | `meta` fields | Client recovery |
|---|---|---|---|---|
| Debits ≠ credits on submit | `UNBALANCED_ENTRY` | 422 | `total_debit`, `total_credit`, `difference`, `currency_code` | Adjust lines client-side; the UI should already be computing this live, this is the server-side backstop. |
| Posting into a closed fiscal period | `PERIOD_LOCKED` | 409 if the period closed *after* the client loaded the form (`meta.state_changed: true`); 422 if the client submitted a `fiscal_period_id` that was already closed | `fiscal_period_id`, `period_status`, `closed_at`, `state_changed` | Re-fetch open periods, resubmit against a valid one, or request a reopening (requires `accounting.period.reopen` permission). |
| Editing/reposting an already-posted entry | `ALREADY_POSTED` | 409 | `journal_entry_id`, `posted_at`, `posted_by` | Create a reversing entry instead of editing (see Related workflow in the Journal Entries module doc). |
| Sales order pushes customer over credit limit | `CREDIT_LIMIT_EXCEEDED` | 422 | `customer_id`, `credit_limit`, `current_balance`, `order_amount`, `resulting_balance` | Reduce order amount, collect a receipt first, or a Finance Manager can override with `sales.credit_limit.override`. |
| Delivery would drive stock negative | `INSUFFICIENT_STOCK` | 422 | `product_id`, `warehouse_id`, `quantity_on_hand`, `quantity_requested` | Reduce quantity, transfer stock in first, or a Warehouse Manager can override with `inventory.negative_stock.allow`. |
| Editing a bank transaction already in a completed reconciliation | `RECONCILED_LOCK` | 409 | `bank_transaction_id`, `bank_reconciliation_id`, `reconciled_at` | Unreconcile first (requires `bank.reconcile`), or post an adjusting entry. |
| Mixed currencies without a resolvable rate | `CURRENCY_MISMATCH` | 422 | `expected_currency`, `given_currency`, `exchange_rate_available` | Supply `exchange_rate` explicitly or use the company's base currency. |
| Two users edit the same record concurrently | `CONCURRENT_MODIFICATION` | 409 | `expected_version` (or `updated_at`), `current_version` | Re-fetch the record, reapply the change on top of the current version. |
| AI-proposed action below auto-execute confidence | `AI_CONFIDENCE_TOO_LOW` | 422 | `confidence_score`, `threshold`, `agent`, `proposal_id` | Route to human approval queue; do not resubmit as-is. |

Business-rule violations always include enough `meta` for a client to render the exact numbers without
a follow-up request — QAYD never makes a client re-fetch the customer or the journal entry just to
learn *why* a 422/409 happened.

# Localization Of Messages

QAYD serves Kuwait-first, Gulf-wide, English + Arabic (full RTL) audiences. Every string a human reads
in an error — `message` at the envelope level and `message` inside each `errors[]` entry — is
localized; `code`, `field`, and all `meta` keys and values are never localized, since clients branch on
them programmatically.

Locale resolution order (first match wins):

1. `Accept-Language` request header (`ar`, `ar-KW`, `en`, `en-US` are all recognized; region subtags are
   normalized to the base language).
2. The authenticated user's `users.locale` preference, if `Accept-Language` is absent.
3. The company's default locale (`companies.default_locale`), if the user has none set.
4. `en` as the final fallback.

The resolved locale is echoed back on every response via the `Content-Language` header so clients never
have to guess which language a message arrived in.

Example — the same `CREDIT_LIMIT_EXCEEDED` error in both supported languages:

```json
// Content-Language: en
{
  "code": "CREDIT_LIMIT_EXCEEDED",
  "field": "customer_id",
  "message": "This order exceeds the customer's approved credit limit of 5,000.0000 KWD.",
  "meta": { "customer_id": 5501, "credit_limit": "5000.0000", "current_balance": "4200.0000", "order_amount": "1300.0000", "resulting_balance": "5500.0000" }
}
```

```json
// Content-Language: ar
{
  "code": "CREDIT_LIMIT_EXCEEDED",
  "field": "customer_id",
  "message": "يتجاوز هذا الطلب حد الائتمان المعتمد للعميل والبالغ 5,000.0000 د.ك.",
  "meta": { "customer_id": 5501, "credit_limit": "5000.0000", "current_balance": "4200.0000", "order_amount": "1300.0000", "resulting_balance": "5500.0000" }
}
```

Implementation notes:

- Messages live in Laravel's standard `lang/en/errors.php` and `lang/ar/errors.php` translation files,
  keyed by the same catalog `code` (plus a variant suffix for codes with multiple phrasings, e.g.
  `errors.PERIOD_LOCKED.state_changed` vs `errors.PERIOD_LOCKED.default`). No message string is ever
  hand-built by concatenation outside the translation layer — every message is `__('errors.CODE', $params)`
  with named placeholders (`:amount`, `:currency`, `:customer_name`) so numeral and currency formatting
  respects the resolved locale (Arabic numerals rendered per company `numeral_system` setting, currency
  formatted per ISO 4217 with the correct minor-unit precision).
- Numbers, dates, and currency amounts embedded in messages follow the same locale as the message text
  itself; `meta` values remain locale-neutral machine format (`NUMERIC` string, ISO 8601 date) regardless
  of `message` language, so clients doing their own formatting are unaffected by which language the
  human-readable string happens to be in.
- If a translation key is missing for the resolved non-English locale, QAYD falls back to English for
  that single string rather than failing the request — a missing Arabic string is a documentation bug,
  never a 500.

# request_id & Tracing

Every request, success or failure, is assigned a UUIDv4 `request_id` at the earliest point in the
Laravel middleware stack (before routing, authentication, or validation), so even a `400` on a
malformed body still carries one. The same identifier is:

- Returned in the JSON envelope as `request_id`.
- Echoed as the `X-Request-Id` response header (so it's visible even for non-JSON responses like
  malformed-body 400s that fail before the envelope is built).
- Accepted as an *inbound* `X-Request-Id` header from the client; if supplied and it is a valid UUIDv4,
  QAYD uses the client-supplied value instead of minting a new one, allowing a client to correlate a
  request across its own retry logic and QAYD's logs with one identifier end-to-end.
- Attached to every log line, queued job, database audit-log row (`audit_logs.request_id`), webhook
  delivery attempt, and AI-engine call made while servicing that request, via a request-scoped context
  (Laravel's `Context` facade) that survives across service/repository boundaries and into any FastAPI
  call the AI engine makes back into the same request.
- Propagated to the AI engine as the `X-Request-Id` header on every Laravel → FastAPI call, and back
  again on the FastAPI → Laravel response, so an AI-engine timeout or fault is traceable to the exact
  originating API request.

When a caller contacts support about a failure, `request_id` is the only piece of information support
needs to pull the full trace: originating request, every downstream service call it made (database
queries, AI engine calls, webhook fan-out), and the exact exception if one occurred — all without the
caller ever needing to paste a stack trace or reproduce the issue.

```
curl -i https://api.qayd.app/api/v1/accounting/journal-entries/88231/post \
  -X POST \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 104" \
  -H "X-Request-Id: 11111111-2222-3333-4444-555555555555"

HTTP/1.1 409 Conflict
Content-Type: application/json
Content-Language: en
X-Request-Id: 11111111-2222-3333-4444-555555555555
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 598
```

# Retryable vs Non-Retryable

Clients and SDKs must not blindly retry every non-2xx response. QAYD classifies every error code as
retryable or not, and SDKs are required to honor this classification in their built-in retry logic
rather than retrying on status code alone.

| Class | Codes | Retry policy |
|---|---|---|
| Always retryable (network/infra) | `INTERNAL_ERROR` (500), connection resets, timeouts | Exponential backoff with jitter, starting at 500ms, capped at 8s, max 5 attempts. Safe because these carry no partial state (see Error Envelope invariant on `data: null`). |
| Retryable after a fixed wait | `RATE_LIMITED` (429) | Honor the `Retry-After` header exactly; do not use exponential backoff — the server has already told you the wait time. |
| Retryable only after client-side correction | `VALIDATION_ERROR`, `INVALID_FORMAT`, `MISSING_REQUIRED_FIELD`, `UNBALANCED_ENTRY`, `CREDIT_LIMIT_EXCEEDED`, `INSUFFICIENT_STOCK`, `CURRENCY_MISMATCH` | Never auto-retry unmodified; the same payload will fail identically forever. |
| Retryable only after re-fetching state | `PERIOD_LOCKED`, `ALREADY_POSTED`, `RECONCILED_LOCK`, `CONCURRENT_MODIFICATION`, `DUPLICATE_ENTRY` | Client must GET the current resource, reconcile the difference, and resubmit a new request — never resubmit the identical body. |
| Not retryable without human/administrative action | `AUTHENTICATION_FAILED`, `INSUFFICIENT_PERMISSION`, `COMPANY_MISMATCH`, `APPROVAL_REQUIRED`, `AI_CONFIDENCE_TOO_LOW` | Requires a token refresh, a role/permission grant, or a human approval — no automatic client action resolves these. |
| Never retryable | `RESOURCE_NOT_FOUND` | The identifier is wrong or the resource is genuinely gone (or cross-tenant, see Security); retrying will not change that. |

Idempotency: all financial-mutation POST endpoints (invoice creation, journal posting, payment
capture, payroll release) accept an optional `Idempotency-Key` header. When present, QAYD stores the
first response for that key for 24 hours and returns the identical stored response — same
`request_id`, same body — for any repeat request with the same key, rather than executing the
operation twice. This is the mechanism that makes it safe for a client to retry a 500/timeout on a
mutating endpoint without risking a duplicate journal entry or a double-charged payment: the client
should always generate the `Idempotency-Key` once per logical operation and reuse it across retries.

# Error Handling In Clients/SDKs

QAYD ships official SDKs for PHP, JavaScript/TypeScript, Python, and Flutter/Dart. All four expose the
same error model so an engineer moving between the Next.js web app and the Flutter mobile app
recognizes the same shapes.

Common contract across all SDKs:

- A single base exception/error type, `QaydApiError` (`QaydApiException` in PHP/Dart), constructed from
  the envelope's top-level `message`, `request_id`, and the full `errors[]` array — never just the first
  error.
- A `.code` convenience accessor that returns the *first* `errors[].code`, for the common case of a
  single-error response, plus `.errors` for the full list when a client needs every field-level failure
  (e.g. rendering multiple invalid form fields at once).
- HTTP-status-derived subclasses (`ValidationError` for 422, `AuthenticationError` for 401,
  `PermissionError` for 403, `NotFoundError` for 404, `ConflictError` for 409, `RateLimitError` for 429
  carrying a parsed `.retryAfter`, `ApiError` as the generic 500 fallback) so calling code can `catch`
  the specific class it cares about instead of string-matching `code`.
- Built-in retry only for the "always retryable" and "retryable after a fixed wait" classes from the
  table above; every other class raises immediately with no silent retry, because silently retrying a
  `VALIDATION_ERROR` or an `ALREADY_POSTED` conflict would hide a real bug in the caller.
- `Idempotency-Key` auto-generation: SDK methods that map to a financial mutation generate a UUIDv4
  idempotency key once per logical call and attach it to every retry of that call automatically, unless
  the caller supplies their own key.

TypeScript SDK example:

```ts
try {
  const invoice = await qayd.sales.invoices.create({
    customerId: 5501,
    currencyCode: "KWD",
    items: [{ productId: 771, quantity: 3, unitPrice: 12.5 }],
  });
} catch (err) {
  if (err instanceof QaydValidationError) {
    for (const fieldError of err.errors) {
      form.setFieldError(fieldError.field, fieldError.message);
    }
  } else if (err instanceof QaydRateLimitError) {
    await sleep(err.retryAfterMs);
  } else {
    throw err; // surface QaydApiError.requestId to support tooling / Sentry breadcrumb
  }
}
```

Python (AI engine) SDK example, showing the mandatory confidence-gated pattern the AI engine follows
for every proposed write:

```python
try:
    result = qayd_client.accounting.journal_entries.post(
        journal_entry_id=entry_id,
        idempotency_key=proposal.idempotency_key,
    )
except QaydConflictError as e:
    if e.code == "PERIOD_LOCKED":
        escalate_to_human_review(entry_id, reason=e.meta["period_status"])
    else:
        raise
except QaydApiError as e:
    logger.error("journal post failed", request_id=e.request_id, code=e.code)
    raise
```

Every SDK logs `request_id` on every raised error via its standard logging integration
(Monolog channel in PHP, `console.error`/Sentry breadcrumb in TS, `structlog` in Python) so that a
crash report or an application log line is always sufficient to look up the exact server-side trace
without additional round-trips.

# Security

Error responses are a data-exposure surface, and QAYD treats them with the same seriousness as any
other API surface in a multi-tenant financial system:

- **No stack traces, no file paths, no SQL, no internal exception class names** ever appear in a
  response body, in any environment, including staging. Laravel's `APP_DEBUG` is forced `false` at the
  HTTP-response layer regardless of environment config; verbose debug output, if enabled at all, is
  routed only to the internal log sink keyed by `request_id`, never to the client.
- **Generic 500 body**: every unhandled exception is caught by a single global exception handler and
  rendered as `INTERNAL_ERROR` with the fixed message "An unexpected error occurred. Our team has been
  notified." — the specific exception message, class, and trace are logged server-side against
  `request_id` and never serialized into the response.
- **No tenant-existence leakage**: as established in HTTP Status Codes, a lookup for a resource that
  belongs to a different company returns `404 RESOURCE_NOT_FOUND`, identical in shape to a lookup for an
  ID that does not exist at all. A caller can never distinguish "this ID belongs to another company"
  from "this ID was never issued," which prevents company-existence and record-existence enumeration
  across tenants.
- **No permission-set leakage**: a `403 INSUFFICIENT_PERMISSION` never lists which permission key was
  missing in a way that reveals the shape of the RBAC system beyond the single key relevant to the
  attempted action (`meta.required_permission` is included, since the caller already knows what they
  attempted) — but it never enumerates the caller's *other* granted or denied permissions.
- **No credential/secret echo**: validation error `meta.given` (see Validation Errors) is suppressed for
  any field tagged sensitive in its FormRequest rule set — passwords, API keys, bank account numbers,
  national ID numbers, OTP codes — even though it is populated for ordinary business fields like
  `quantity` or `currency_code`.
- **Uniform timing**: authentication failure paths (`AUTHENTICATION_FAILED` for a wrong token vs. a
  disabled user vs. an expired token) are implemented to take a comparable amount of processing time
  regardless of which specific condition failed, to avoid timing side-channels revealing account state.
- **Rate-limit responses reveal only the limit that was hit**, never the caller's remaining quota
  across unrelated endpoints, and never another tenant's usage.
- **Webhook error payloads follow the same rules**: a failed webhook delivery notification (via the
  dashboard or the `webhook.delivery_failed` internal event) includes the QAYD-side `error code` and
  `request_id` but never re-serializes internal exception detail from the receiving endpoint's response
  body beyond the first 500 bytes, truncated and stored only for the account owner's own dashboard.

# Examples

**1. Authentication failure (401)** — expired bearer token:

```
GET /api/v1/accounting/accounts
Authorization: Bearer <expired-token>
X-Company-Id: 104
```

```json
{
  "success": false,
  "data": null,
  "message": "Authentication failed.",
  "errors": [
    { "code": "AUTHENTICATION_FAILED", "field": null, "message": "Your session has expired. Please sign in again.", "meta": {} }
  ],
  "meta": { "pagination": null },
  "request_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
  "timestamp": "2026-07-16T12:10:00Z"
}
```

**2. Authorization failure (403)** — Accountant role attempting to release payroll:

```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to perform this action.",
  "errors": [
    {
      "code": "INSUFFICIENT_PERMISSION",
      "field": null,
      "message": "This action requires the payroll.release permission.",
      "meta": { "required_permission": "payroll.release", "current_role": "Accountant" }
    }
  ],
  "meta": { "pagination": null },
  "request_id": "b2c3d4e5-f6a7-4b6c-9d0e-1f2a3b4c5d6e",
  "timestamp": "2026-07-16T12:11:15Z"
}
```

**3. Sensitive operation requiring approval (403)**:

```json
{
  "success": false,
  "data": null,
  "message": "This bank transfer requires approval before it can be executed.",
  "errors": [
    {
      "code": "APPROVAL_REQUIRED",
      "field": null,
      "message": "Transfers over 1,000.0000 KWD require CFO approval.",
      "meta": { "approval_chain_id": 9012, "required_approver_role": "CFO", "status": "pending" }
    }
  ],
  "meta": { "pagination": null },
  "request_id": "c3d4e5f6-a7b8-4c7d-0e1f-2a3b4c5d6e7f",
  "timestamp": "2026-07-16T12:12:30Z"
}
```

**4. Not found (404)** — cross-tenant or genuinely missing, indistinguishable by design:

```json
{
  "success": false,
  "data": null,
  "message": "The requested resource was not found.",
  "errors": [
    { "code": "RESOURCE_NOT_FOUND", "field": "id", "message": "No invoice was found with the given ID.", "meta": { "resource": "invoices", "id": 88231 } }
  ],
  "meta": { "pagination": null },
  "request_id": "d4e5f6a7-b8c9-4d8e-1f2a-3b4c5d6e7f8a",
  "timestamp": "2026-07-16T12:13:45Z"
}
```

**5. Rate limited (429)**:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 12
```

```json
{
  "success": false,
  "data": null,
  "message": "Too many requests. Please slow down.",
  "errors": [
    { "code": "RATE_LIMITED", "field": null, "message": "You have exceeded 600 requests per minute for this endpoint.", "meta": { "limit": 600, "window_seconds": 60, "retry_after_seconds": 12 } }
  ],
  "meta": { "pagination": null },
  "request_id": "e5f6a7b8-c9d0-4e9f-2a3b-4c5d6e7f8a9b",
  "timestamp": "2026-07-16T12:14:50Z"
}
```

**6. AI confidence too low (422)**:

```json
{
  "success": false,
  "data": null,
  "message": "The AI-proposed categorization could not be auto-applied.",
  "errors": [
    {
      "code": "AI_CONFIDENCE_TOO_LOW",
      "field": "proposal_id",
      "message": "The AI's confidence (0.62) is below the auto-execute threshold (0.85) for General Accountant categorization.",
      "meta": { "proposal_id": "prop_7a1c9e", "confidence_score": 0.62, "threshold": 0.85, "agent": "General Accountant" }
    }
  ],
  "meta": { "pagination": null },
  "request_id": "f6a7b8c9-d0e1-4f0a-3b4c-5d6e7f8a9b0c",
  "timestamp": "2026-07-16T12:16:05Z"
}
```

**7. Internal server error (500)**:

```json
{
  "success": false,
  "data": null,
  "message": "An unexpected error occurred. Our team has been notified.",
  "errors": [
    { "code": "INTERNAL_ERROR", "field": null, "message": "An unexpected error occurred. Our team has been notified.", "meta": {} }
  ],
  "meta": { "pagination": null },
  "request_id": "a7b8c9d0-e1f2-4a1b-4c5d-6e7f8a9b0c1d",
  "timestamp": "2026-07-16T12:17:20Z"
}
```

# Edge Cases

- **Multiple simultaneous business-rule violations**: a single request can trigger more than one
  business-rule code at once (e.g. a sales order that is both over the customer's credit limit and
  requesting more stock than is on hand). QAYD returns both entries in `errors[]` in a single 422 rather
  than surfacing only the first-detected violation, so the client does not have to fix-and-resubmit
  twice to discover the second problem.
- **Validation vs. business-rule ordering**: field-level validation always runs and fails first; QAYD
  never evaluates a business rule (credit limit, stock, period lock) against a payload that has not yet
  passed basic validation, to avoid business-rule error messages referencing malformed data.
- **Partial success is not a supported concept.** Endpoints that operate on a batch (e.g. bulk invoice
  import) do not return "207 Multi-Status"-style partial results. Each item in a bulk endpoint is
  validated up front; if any item fails, the entire batch is rejected with `422` and one `errors[]` entry
  per failing item (`field` prefixed with its array index), and nothing in the batch is persisted. This
  preserves the "no half-committed financial state" invariant from the Error Envelope section.
  Bulk endpoints that intentionally support best-effort partial processing must say so explicitly in
  their own module documentation and use a distinct, clearly-labeled response shape — never silently
  reuse the standard error envelope for a mixed result.
- **Idempotency key reused with a different body**: if a client sends the same `Idempotency-Key` with a
  materially different request body than the first use, QAYD returns `409 CONCURRENT_MODIFICATION`
  with `meta.reason: "idempotency_key_reused_with_different_payload"` rather than silently executing
  the new body or silently returning the old cached response — both would be financially unsafe.
- **Optimistic-lock races on soft-deleted records**: attempting to modify a record that was concurrently
  soft-deleted (`deleted_at` set) by another user returns `409 CONCURRENT_MODIFICATION`, not `404`,
  because the record still exists (soft delete) and the client's mental model ("it changed under me")
  is more accurate than "it never existed."
- **Locale negotiation failure**: if `Accept-Language` requests a locale QAYD does not support (e.g.
  `fr`), QAYD falls back to the user's stored `locale` preference, then company default, then English —
  it never returns a 406 Not Acceptable for message localization; localization degrades gracefully,
  it never fails a request.
- **Error during error serialization**: if the localization layer itself throws while resolving a
  message string (a missing translation key with a malformed placeholder), the global exception handler
  catches that failure specifically and falls back to the English string for that one entry rather than
  letting the whole response degrade into a raw 500 with no envelope — the envelope contract is
  considered a stronger invariant than perfect localization.
- **Webhook signature/verification failures are not API errors**: they are rejected at the receiving
  client's own endpoint and never surface through this envelope at all; QAYD's outbound webhook retries
  (with exponential backoff, capped at 24 hours) are documented in the Webhooks module, not here — this
  document governs only synchronous request/response errors from `/api/v1/`.
- **Clock skew on `timestamp`**: clients must never use the envelope's `timestamp` for idempotency or
  ordering decisions across requests — it reflects server render time only, not a monotonic sequence,
  and successive error responses for the same logical retry may show a `timestamp` that is earlier than
  a prior successful response's if requests raced across load-balanced instances. `request_id` and
  server-side audit log ordering are the only correct source of truth for sequencing.
- **Errors that span a domain-event chain**: if a synchronous request triggers a queued domain event
  (e.g. `invoice.created` firing an async webhook, or an AI engine call spawning a background job) and
  that downstream step later fails, the *original* HTTP response is unaffected — it already returned
  `success: true` for the request that was itself valid. The downstream failure is reported through its
  own channel (webhook delivery status, `ai_conversations` record, notification), tagged with the
  original `request_id` for correlation, never retroactively as an error on a request that already
  completed successfully.

# End of Document
