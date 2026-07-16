# SDK Guidelines — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: SDK Guidelines
---

# Purpose

QAYD is an AI Financial Operating System. Laravel 12 (PHP 8.4+) is the single source of truth for
every business and financial rule; the FastAPI/Python AI engine and all first- and third-party
clients — the Next.js 15 web app, the Flutter mobile app, partner integrations, and scripts run by
customers' own developers — talk to that Laravel API over REST/JSON/HTTPS and never touch the
PostgreSQL database directly. This document specifies how QAYD builds, ships, and maintains the
**official client SDKs** that wrap `/api/v1/...` for external and internal consumers: a PHP SDK, a
JavaScript/TypeScript SDK, a Python SDK, and a Flutter/Dart SDK.

An SDK's job is to make the fixed platform contract — the response envelope, Sanctum/JWT bearer
auth, `X-Company-Id` multi-tenancy, cursor pagination, the `errors[]` array, and the webhook
event catalogue — disappear behind idiomatic, typed, well-documented calls in each target
language. A developer integrating QAYD should never need to read the raw HTTP spec to issue an
invoice, reconcile a bank transaction, or verify a webhook signature; the SDK encodes every rule
in this document once so client code cannot violate it by accident. This document is the contract
that all four SDK teams (and any code-generation tooling that produces a first draft of them) must
follow, so that a `Client.create_invoice(...)` call in Python and a `client->invoices()->create(...)`
call in PHP behave identically at the wire level and return the same shape of typed result.

The SDKs are not a reimplementation of business logic. No SDK computes tax, posts a journal entry,
or decides whether a transfer needs approval — all of that lives in Laravel, per the platform
architecture. The SDK's scope is transport, auth, typing, pagination, retries, idempotency,
error mapping, and webhook verification: the "plumbing" every API consumer would otherwise
reimplement badly and inconsistently.

# Target SDKs

QAYD maintains four first-party SDKs. Each wraps the same `/api/v1/` surface; none diverges on
behavior, only on language idiom.

| SDK | Language / Runtime | Package name | Min. version | Primary consumers |
|---|---|---|---|---|
| PHP SDK | PHP 8.2+ (built/tested against 8.4) | `qayd/sdk` (Packagist) | PHP 8.2 | Server-side integrations, Laravel/WordPress plugins, partner backends |
| JS/TS SDK | Node.js 18+, browsers (ESM+CJS) | `@qayd/sdk` (npm) | Node 18 | The QAYD Next.js 15 web app itself, partner Node backends, browser widgets |
| Python SDK | Python 3.9+ | `qayd-sdk` (PyPI) | Python 3.9 | The FastAPI AI engine's outbound calls to Laravel, data/finance scripting, notebooks |
| Flutter/Dart SDK | Dart 3 / Flutter 3.19+ | `qayd_sdk` (pub.dev) | Dart 3.0 | The QAYD Flutter mobile app, third-party Flutter integrations |

Each SDK repository lives under `qayd/sdk-<lang>` (e.g. `qayd/sdk-php`, `qayd/sdk-js`,
`qayd/sdk-python`, `qayd/sdk-dart`) and is generated/maintained from the same canonical OpenAPI
document, `openapi/qayd-v1.yaml`, described in **Code Generation From OpenAPI** below. A change to
a resource, field, error code, or webhook event is made once in the OpenAPI spec (plus any
hand-written extension notes) and propagates to all four SDKs through the same release pipeline,
so the SDKs cannot silently drift from each other or from the API itself.

Internal consumption is a first-class use case, not an afterthought: the AI engine (Python) and
the Next.js web app (TS) use the *public* SDK — the same package an external developer would
install — rather than a private internal client. This guarantees the SDK is dogfooded on every
QAYD deploy and that any papercut is felt (and fixed) before an external integrator hits it.

# Design Principles

Three words govern every SDK decision: **idiomatic**, **typed**, **consistent**.

**Idiomatic.** Each SDK reads like a native library for its ecosystem, not a mechanical
transliteration of the HTTP spec:
- PHP: PSR-4 autoloading, PSR-12 style, PSR-18 HTTP client abstraction (bring-your-own Guzzle/
  Symfony HTTP client via `psr/http-client-implementation`), immutable value objects,
  `readonly` properties, named constructors (`Invoice::fromArray()`), fluent builders for
  multi-field requests (`Invoices::create()->forCustomer($id)->withLine(...)`).
- JavaScript/TypeScript: a single `QaydClient` class, promise-based (`async`/`await`), native
  `fetch` under the hood with a pluggable `fetch` implementation for non-browser/non-Node
  runtimes (Cloudflare Workers, Deno, React Native), tree-shakeable ESM build plus a CJS build
  for legacy `require()` consumers, discriminated-union types for polymorphic resources.
- Python: PEP 8, full type hints (`from __future__ import annotations`), `dataclasses`/`pydantic`
  models for every resource, both a synchronous `QaydClient` and an async `AsyncQaydClient`
  sharing the same generated model layer, context-manager support (`with QaydClient(...) as c:`)
  for connection pooling.
- Flutter/Dart: null-safety throughout, `Future<T>`/`Stream<T>` idioms, `freezed`-style immutable
  models with `copyWith`, a `QaydClient` that plugs into `dio` or `package:http`, first-class
  support for Flutter's `Isolate`-friendly serialization (no reliance on `dart:mirrors`).

**Typed.** Every request parameter and every response field has a real type in the target
language — no SDK method accepts or returns a bare `array`/`dict`/`Map<String, dynamic>` for a
known resource shape. Enums in the API (invoice `status`, journal_entry `state`, permission keys)
become real enums (`InvoiceStatus::Posted`, `TypeScript` string-literal unions, Python `Enum`
subclasses, Dart `enum`s) generated from the OpenAPI `enum` values, not hand-maintained lists that
can fall out of sync. Money fields are never floats: PHP/TS/Dart SDKs represent money as a
minor-unit integer plus currency code (mirroring the API's `NUMERIC(19,4)` semantics, transported
as a fixed-precision decimal string, never a binary float) wrapped in a `Money` value type with
`.add()`, `.multiply()`, and locale-aware `.format()`; the Python SDK uses `decimal.Decimal`
end-to-end and never casts amounts through `float`.

**Consistent.** The same operation looks the same way across SDKs, modulo language idiom. A
resource client is always named after its plural REST resource (`invoices`, `journalEntries`,
`bankTransactions`); CRUD methods are always `list`, `get`, `create`, `update`, `delete` (or the
closest idiomatic verb — Python favors `retrieve`/`list`/`create`/`update`/`delete` to match the
naming convention used by comparable financial SDKs); every list method returns a **paginator**,
never a bare array (see **Pagination Helpers**); every mutating call accepts an optional
idempotency key parameter in the same position; every SDK raises the same *hierarchy* of typed
exceptions, mapped 1:1 from HTTP status + `errors[]` codes (see **Error Handling**); every SDK
verifies webhook signatures with a function literally named `verifyWebhookSignature` (or the
per-language casing convention of that name) taking the same three arguments: payload, signature
header value, and signing secret.

A cross-cutting rule: **no SDK ever guesses at business logic**. If the API returns
`422 Unprocessable Entity` because a journal entry is unbalanced, the SDK surfaces a typed
`ValidationException` with the exact `errors[]` payload — it does not attempt client-side
double-entry validation, tax computation, or approval-chain logic. That logic is Laravel's alone.

# Authentication Handling

QAYD auth is Laravel Sanctum + JWT bearer tokens. Every SDK supports the two credential flows the
platform exposes and hides the token lifecycle from the caller:

1. **API key / personal access token** — a long-lived Sanctum token issued from the QAYD
   dashboard (Settings → Developer → API Keys), scoped to a company and a permission set. Typical
   for server-to-server integrations (the AI engine, partner backends, scripts).
2. **User session JWT** — issued via `POST /api/v1/auth/login` (or SSO exchange), short-lived
   (default 15 minutes) with a longer-lived refresh token (default 30 days), used by the Next.js
   web app and the Flutter mobile app on behalf of a logged-in human.

## Token storage

Storage is delegated to a `TokenStorage` interface per SDK so the SDK never dictates a storage
mechanism that is wrong for its runtime:

| SDK | Default storage | Interface to override |
|---|---|---|
| PHP | In-memory only (caller must persist) | `Qayd\Auth\TokenStorageInterface` (`get`, `set`, `clear`) |
| JS/TS | `localStorage` in browsers, in-memory in Node | `TokenStorage` (`getToken`, `setToken`, `clearToken`), swappable for `AsyncStorage`, cookies, KV |
| Python | In-memory only | `TokenStorage` ABC (`load`, `save`, `delete`) — apps supply Redis/DB-backed implementations |
| Flutter/Dart | `flutter_secure_storage` (Keychain/Keystore-backed) | `TokenStorage` abstract class |

The PHP and Python SDKs default to in-memory-only storage deliberately: server processes are
typically short-lived or stateless per request, and silently writing tokens to disk in a generic
server SDK is a security foot-gun. The JS and Dart SDKs, which run in end-user app contexts, ship
a safe default (secure storage on mobile, `localStorage` on web with an explicit opt-out for
SSR/edge contexts) because most consumers of those SDKs are apps, not backend services.

Access tokens and refresh tokens are **never written to logs**. Every SDK's default HTTP logger
redacts the `Authorization` header and any `refresh_token` field before emitting a debug log line.

## Refresh flow

For the JWT flow, every SDK implements the same refresh contract:

- Each outgoing request carries `Authorization: Bearer <access_token>`.
- On a `401 Unauthorized` response whose `errors[].code` is `token_expired` (not
  `invalid_credentials` or `token_revoked`, which are not retried), the SDK automatically calls
  `POST /api/v1/auth/refresh` with the stored refresh token, updates storage, and **transparently
  retries the original request once**. If refresh itself fails, the SDK raises
  `AuthenticationException` and does not loop.
- Concurrent requests hitting the same expired token trigger only **one** refresh call; the SDK
  coalesces concurrent refreshes behind a mutex/lock (an `asyncio.Lock` in Python, a shared
  in-flight `Promise` in JS, a `synchronized` guard in PHP via a static token-refresh guard object,
  a `Completer`-backed lock in Dart) so a burst of parallel calls does not hammer
  `/api/v1/auth/refresh`.
- API-key auth never triggers refresh; a `401` on an API-key request is always terminal and maps
  straight to `AuthenticationException`.

```ts
// JS/TS — construction
import { QaydClient } from "@qayd/sdk";

const client = new QaydClient({
  auth: { type: "apiKey", apiKey: process.env.QAYD_API_KEY! },
  companyId: "cmp_9f2a1c",
});

// or, for a logged-in user session:
const userClient = new QaydClient({
  auth: {
    type: "session",
    tokenStorage: new BrowserTokenStorage(), // implements TokenStorage
  },
  companyId: "cmp_9f2a1c",
});
```

```python
# Python — construction
from qayd import QaydClient

client = QaydClient(
    api_key="sk_live_...",
    company_id="cmp_9f2a1c",
)

# Refresh-token flow with a custom store:
client = QaydClient(
    auth=SessionAuth(token_storage=RedisTokenStorage(redis_conn)),
    company_id="cmp_9f2a1c",
)
```

# Company Context

Every authenticated request is scoped to exactly one active company via the `X-Company-Id`
header — QAYD is strictly multi-tenant and company isolation is absolute at the API layer. Every
SDK therefore requires `companyId` at client construction time and injects it into every request
automatically; there is no per-call parameter for it, so a developer cannot accidentally omit it
or interpolate the wrong value on one call out of many.

For consumers who legitimately operate across multiple companies in one process (an accounting
firm's script iterating its client companies, or the AI engine serving multiple tenants from one
worker), every SDK exposes a cheap **scoped-client** factory that clones the underlying HTTP
transport and auth state but swaps the header, so switching companies never means constructing a
brand-new client (and re-authenticating):

```php
// PHP
$client = new Qayd\Client(apiKey: $apiKey, companyId: 'cmp_9f2a1c');
$otherCompany = $client->forCompany('cmp_44b7de'); // same transport/auth, different X-Company-Id
$invoice = $otherCompany->invoices()->create(/* ... */);
```

```dart
// Dart
final client = QaydClient(apiKey: apiKey, companyId: 'cmp_9f2a1c');
final otherCompany = client.forCompany('cmp_44b7de');
final invoice = await otherCompany.invoices.create(/* ... */);
```

If `companyId` is missing at construction and no default company can be resolved (e.g. no single
company associated with an API key), the SDK raises a typed `ConfigurationException` at
construction time rather than deferring to a confusing `403 Forbidden` on the first real call.
Every response model that includes `company_id` is cross-checked against the client's active
company by the SDK in debug/strict mode; a mismatch (which should never happen server-side, but
guards against SDK bugs) raises an assertion in non-production builds and is silently logged in
production builds.

Branch scoping (`branch_id`) is optional and passed per-call, not per-client, since a single
company-scoped client legitimately operates across branches within one session:

```python
client.invoices.list(branch_id="brn_02")
client.journal_entries.create(branch_id="brn_02", lines=[...])
```

# Pagination Helpers

The API paginates every list endpoint with the standard envelope's
`meta.pagination = { page, per_page, total, cursor }`, default `per_page=25`, and supports both
page-based and cursor-based iteration, plus filtering/sorting/search query parameters. SDKs never
expose raw page-fetching as the primary interface — every `list()` call returns an **auto-paginating
iterable/paginator** that lazily fetches subsequent pages as the caller consumes results, so a
developer can `for` loop over "all invoices" without hand-rolling a `while (cursor)` loop or
accidentally forgetting to check `meta.pagination.cursor`.

Concretely:

- **PHP**: `list()` returns a `Qayd\Pagination\AutoPagingIterator` implementing `IteratorAggregate`,
  usable directly in a `foreach`. `->getIterator()` fetches page N+1 only once page N is exhausted.
  An explicit `->pages()` generator is available for callers who want page-level control (e.g. to
  show a "Next" button with `has_more`).
- **JS/TS**: `list()` returns an object that is both a `Promise<Page<T>>` (for callers who just
  want page 1) and an `AsyncIterable<T>` (for `for await (const inv of client.invoices.list())`).
  This dual shape is achieved via a `ListPromise<T>` class that extends `Promise` and implements
  `Symbol.asyncIterator`.
- **Python**: `list()` returns an `Iterator[T]` (or `AsyncIterator[T]` on `AsyncQaydClient`) that
  pages transparently; `.pages()` yields `Page[T]` objects when page boundaries matter to the
  caller.
- **Dart**: `list()` returns a `Paginator<T>` implementing `Stream<T>`, consumable with
  `await for (final inv in client.invoices.list())`, plus `.nextPage()` for manual control.

All four use **cursor** pagination internally when iterating (following `meta.pagination.cursor`)
rather than incrementing `page`, because cursor pagination is stable under concurrent
inserts/deletes server-side; `page`-based access remains available for UI "jump to page" use cases
via an explicit `{ page: N }` option, which the SDK passes through as the `page` query parameter
instead of `cursor`.

Every paginator enforces a **max-pages safety cap** (default 10,000 pages, configurable) that
raises a `PaginationLimitExceeded` warning/exception rather than looping forever if the API ever
returns a non-advancing cursor — a defensive guard, not an expected code path.

```js
// JS/TS — auto-iterate all unpaid invoices
for await (const invoice of client.invoices.list({ status: "unpaid", sort: "-due_date" })) {
  console.log(invoice.id, invoice.totalDue.format());
}

// or just the first page:
const firstPage = await client.invoices.list({ per_page: 50 });
console.log(firstPage.data.length, firstPage.hasMore);
```

```python
# Python — auto-iterate, filtering + search
for bill in client.bills.list(vendor_id="vnd_1a", search="AWS"):
    print(bill.id, bill.total)
```

# Error Handling & Typed Exceptions

The API's error contract is fixed: non-2xx responses use HTTP status 400/401/403/404/409/422/
429/500, and on `422` the envelope's `errors[]` array is populated with field-level details. Every
SDK maps this to a single exception hierarchy, so callers can catch broadly or narrowly as needed,
and the hierarchy is identical in shape across languages:

```
QaydException (base)
├── AuthenticationException        (401)
├── AuthorizationException         (403 — permission denied; carries the missing permission key)
├── NotFoundException              (404 — carries resource type + id)
├── ConflictException              (409 — e.g. duplicate idempotency key with different payload)
├── ValidationException            (422 — carries a structured `errors` list: field, code, message)
├── RateLimitException             (429 — carries `retry_after` seconds, parsed from Retry-After)
├── ApiException                   (400 / generic 4xx not covered above)
├── InternalServerException        (500 — carries `request_id` for support correlation)
├── NetworkException               (transport-level failure: timeout, DNS, connection reset)
└── SdkException                   (client-side misuse: bad config, serialization failure)
```

Every exception carries: the HTTP status, the parsed `errors[]` array verbatim, the
`request_id` from the envelope (critical for support tickets — QAYD support always asks for it),
and the raw response body for debugging. `ValidationException` additionally exposes a
`fieldErrors(): Map<string, string[]>`-style accessor (or per-language equivalent) so callers can
route specific messages to specific form fields without parsing `errors[]` by hand.

```php
// PHP
use Qayd\Exceptions\ValidationException;

try {
    $client->journalEntries()->post($entryId);
} catch (ValidationException $e) {
    foreach ($e->fieldErrors() as $field => $messages) {
        // e.g. "lines" => ["Debits (1200.00) do not equal credits (1150.00)"]
    }
} catch (\Qayd\Exceptions\AuthorizationException $e) {
    // $e->getMissingPermission() === "accounting.journal.post"
}
```

```python
# Python
from qayd.exceptions import ValidationError, RateLimitError

try:
    client.journal_entries.post(entry_id)
except ValidationError as e:
    for field, messages in e.field_errors.items():
        ...
except RateLimitError as e:
    time.sleep(e.retry_after)
```

No SDK ever swallows an error silently or returns `None`/`null`/`nil` in place of raising — a
failed call always either returns a fully-typed success object or raises. This is a deliberate
departure from "soft failure" styles seen in some REST SDKs, because in a financial system a
silently-`None` result (e.g. "invoice not found, return null") is far more dangerous than a loud
exception a caller must handle.

# Idempotency Helpers

Every mutating (`POST`/`PATCH`/`PUT`/`DELETE`) call in an SDK accepts an optional idempotency key
in the same call-signature position (a trailing options object/kwarg — never a positional
parameter, so it cannot be confused with business fields). If the caller does not supply one, the
SDK **generates a UUIDv4 automatically** for every mutating call and sends it as the
`Idempotency-Key` header, because network retries (see **Retries & Backoff**) must never risk a
duplicate invoice, payment, or journal entry — automatic retries are only safe because every retried
request carries the same idempotency key as the original attempt.

- The API guarantees: replaying the same `Idempotency-Key` with an identical request body within
  24 hours returns the original response (same resource, same status code) without re-executing
  the side effect; replaying the same key with a **different** body returns `409 Conflict`
  (`ConflictException` in every SDK).
  - Explicit keys matter when a caller wants idempotency across process restarts (e.g. a job
  queue retrying a "create invoice" job after a crash) — the caller supplies a stable key derived
  from their own job id, not the SDK's ephemeral UUID:

```ts
// JS/TS — explicit idempotency key tied to an internal job id
await client.invoices.create(
  { customerId: "cus_5f", lines: [...] },
  { idempotencyKey: `invoice-job-${job.id}` }
);
```

```python
# Python — same pattern
client.invoices.create(
    customer_id="cus_5f",
    lines=[...],
    idempotency_key=f"invoice-job-{job.id}",
)
```

The SDK never reuses one auto-generated key across two logically different calls, and never mutates
a caller-supplied key. `GET`/list calls never carry an idempotency key (they are naturally
idempotent). Bulk/batch endpoints accept one idempotency key for the whole batch, covering the
batch as a single logical operation.

# Retries & Backoff

Network blips and `429`/`5xx` responses are retried automatically by every SDK using the same
policy, configurable but sane by default:

| Setting | Default | Notes |
|---|---|---|
| Max retries | 2 (3 attempts total) | Configurable 0–5 |
| Backoff | Exponential + full jitter | `base=0.5s, factor=2, jitter=random(0, computed)` |
| Retried statuses | `429`, `500`, `502`, `503`, `504` | Never `400`, `401`, `403`, `404`, `409`, `422` |
| Retried transport errors | Connect timeout, read timeout, connection reset, DNS failure | |
| Respects `Retry-After` | Yes, on `429` | Overrides computed backoff if larger |
| Per-call override | Yes | `{ maxRetries: 0 }` to disable for a specific call |

Only requests that are safe to retry are retried automatically: `GET` always; mutating requests
only because every mutating request carries an idempotency key (see above), which is what makes
automatic retry of a `POST` safe in the first place. A `500` on a request with no idempotency key
(which should not happen given the SDK auto-generates one, but is defensively checked) is **not**
retried.

Backoff computation (identical formula, every SDK):

```
attempt 0 fails -> wait = min(cap, base * 2^0) * jitter   where jitter ~ U(0, 1), cap = 8s
attempt 1 fails -> wait = min(cap, base * 2^1) * jitter
attempt 2 fails -> give up, raise the mapped exception
```

The SDK's HTTP layer is instrumented with hooks (`onRetry(attempt, error, delay)`) so integrators
can log or emit metrics on retries without re-implementing the retry loop themselves. Retries are
capped by a total-time budget as well as an attempt count (default 30s wall-clock across all
attempts for one logical call) so a slow chain of retries cannot silently balloon a request's
latency past a caller's own timeout expectations.

```php
// PHP — per-call retry override, and a retry hook set globally
$client = new Qayd\Client(apiKey: $apiKey, companyId: $companyId, options: [
    'maxRetries' => 3,
    'onRetry' => fn(int $attempt, \Throwable $e, float $delay) => Log::warning("qayd retry", [
        'attempt' => $attempt, 'delay' => $delay, 'error' => $e->getMessage(),
    ]),
]);

$client->bankTransactions()->list(options: ['maxRetries' => 0]); // fail fast, no retry
```

# Webhooks Verification Helpers

QAYD fires webhooks on domain events — `invoice.created`, `invoice.paid`, `payment.received`,
`payroll.completed`, `inventory.updated`, `bank.synced`, `ai.finished`, `journal.posted`, and
others as modules add events. Every webhook delivery is signed with an HMAC-SHA256 signature over
the raw request body, using the company's webhook signing secret (issued per webhook endpoint in
the QAYD dashboard), delivered in a `Qayd-Signature` header formatted as
`t=<unix_timestamp>,v1=<hex_hmac>`.

Every SDK ships a **framework-agnostic verification function** — it takes the raw (unparsed)
request body, the `Qayd-Signature` header value, and the signing secret, and returns the
verified, typed, deserialized event, or raises `SignatureVerificationException`. It does not
depend on any particular web framework, so it works identically inside Laravel, Express, FastAPI,
or Django:

```python
# Python — verifying inside a FastAPI endpoint
from fastapi import Request, HTTPException
from qayd.webhooks import verify_webhook_signature, SignatureVerificationError

@app.post("/webhooks/qayd")
async def qayd_webhook(request: Request):
    payload = await request.body()
    sig_header = request.headers.get("Qayd-Signature", "")
    try:
        event = verify_webhook_signature(
            payload=payload,
            signature_header=sig_header,
            secret=settings.QAYD_WEBHOOK_SECRET,
            tolerance_seconds=300,
        )
    except SignatureVerificationError:
        raise HTTPException(status_code=400, detail="invalid signature")

    if event.type == "invoice.paid":
        handle_invoice_paid(event.data)
    return {"received": True}
```

```ts
// JS/TS — verifying inside an Express route
import { verifyWebhookSignature, SignatureVerificationError } from "@qayd/sdk/webhooks";

app.post("/webhooks/qayd", express.raw({ type: "application/json" }), (req, res) => {
  try {
    const event = verifyWebhookSignature({
      payload: req.body, // Buffer — raw body, not re-parsed JSON
      signatureHeader: req.header("Qayd-Signature") ?? "",
      secret: process.env.QAYD_WEBHOOK_SECRET!,
    });
    if (event.type === "bank.synced") handleBankSynced(event.data);
    res.sendStatus(200);
  } catch (e) {
    if (e instanceof SignatureVerificationError) return res.sendStatus(400);
    throw e;
  }
});
```

Verification rules, identical across SDKs:
- The comparison of the computed HMAC to the header's `v1` value is **constant-time**
  (`hash_equals` in PHP, `hmac.compare_digest` in Python, `crypto.timingSafeEqual` in Node,
  a constant-time byte compare in Dart) to prevent timing side-channels.
- A `tolerance_seconds` window (default 300s) rejects signatures whose `t=` timestamp is too old,
  mitigating replay of a captured payload; callers may widen this for systems with clock drift,
  never disable it outright without an explicit `allowClockSkewBypass` flag that logs a warning
  when used.
- The event payload returned is a **typed** event object matching the OpenAPI webhook schemas
  (`InvoicePaidEvent`, `BankSyncedEvent`, etc.), not a raw dict — callers get autocomplete and type
  errors on `event.data.invoiceId` just as they would on a normal API response.
- SDKs never attempt to fetch a "latest" version of the resource on the caller's behalf inside the
  webhook handler; if the handler needs fresher data than the event payload carries, it calls the
  normal resource API explicitly. This keeps webhook handling side-effect-transparent.

# Versioning & Compatibility

The API is versioned at the URL level (`/api/v1/`); a future breaking change ships as `/api/v2/`
and both remain live during a deprecation window (minimum 12 months' notice via the
`Qayd-Deprecation` response header and dashboard/email notice, per QAYD's platform policy). SDK
versioning is **decoupled but coordinated**:

- Each SDK follows **Semantic Versioning** (`MAJOR.MINOR.PATCH`) independent of the API's own
  `v1`/`v2` path version. A `MAJOR` SDK bump happens when the SDK's own public interface changes
  incompatibly (e.g. a method rename, a required-parameter addition) — not merely because the API
  gained new optional fields.
  - Adding a new optional field to a response model, a new enum value, or a new endpoint is a
  **non-breaking**, `MINOR` SDK release in every language: TS types add the field as optional;
  Python/PHP typed models tolerate unknown/absent fields; new enum values are added such that
  switch/match statements in generated code default to an `Unknown`/catch-all variant instead of
  throwing, so an SDK on an older `MINOR` version does not crash when the API adds a value it does
  not yet know about.
- Each SDK client can pin an explicit API version via `apiVersion: 'v1'` at construction (default:
  the latest version the installed SDK version supports.) When QAYD ships `/api/v2/`, an SDK
  `MAJOR` release adds `v2` support while a `v1`-pinned client continues to target `/api/v1/`
  until the caller opts in — SDKs never silently switch a running integration to a new API major
  version.
- A compatibility matrix is published and kept current in each SDK's README:

| SDK version range | Supported API versions | PHP/Node/Python/Dart minimum |
|---|---|---|
| 1.x | v1 | per **Target SDKs** table above |
| 2.x (planned, opens with API v2 GA) | v1, v2 | to be set at v2 GA |

- Every SDK response model deserializer is **forward-compatible by construction**: unknown JSON
  fields are ignored rather than raising (PHP: constructor collects unknown keys into an
  `extra: array` bag rather than erroring; TS: interfaces are not `exact`; Python: pydantic models
  use `model_config = ConfigDict(extra="ignore")`; Dart: generated `fromJson` ignores unrecognized
  keys). This is what allows the API team to ship additive changes without a synchronized SDK
  release on the same day.
- Deprecated SDK methods are annotated with the language's native deprecation mechanism
  (`@deprecated` docblock + PHPStan `@deprecated` tag in PHP, `@deprecated` JSDoc + TS
  `@deprecated` decorator, Python `warnings.deprecated`/`DeprecationWarning`, Dart `@Deprecated
  ('message')`) at least one `MINOR` version before removal in the next `MAJOR`.

# Code Generation From OpenAPI

OpenAPI/Swagger is the canonical contract (per platform convention); QAYD maintains
`openapi/qayd-v1.yaml` as the single hand-curated source of truth for every path, schema, enum,
and webhook event, and **generates the bulk of every SDK's model and low-level request layer from
it**, so the four SDKs cannot drift from the actual API or from each other on basic shape.

Generation pipeline (run in CI on every merge to the OpenAPI spec, and on every scheduled nightly
build against the live spec):

1. **Lint & validate** — `spectral lint openapi/qayd-v1.yaml` against a QAYD-specific ruleset
   enforcing: every operation has an `operationId` in `resource_action` form
   (`invoices_create`, `journalEntries_post`), every schema has `description`s, every enum lists
   all values with no free-text fallback, and every endpoint documents its required permission via
   an `x-qayd-permission` vendor extension consumed by the generators to produce permission-aware
   docstrings.
2. **Generate models + low-level clients** per language using **OpenAPI Generator** with
   QAYD-maintained custom templates (Mustache) per target:
   - PHP: `openapi-generator generate -g php -c config/php.yaml` → generates
     `src/Generated/Models/*.php` and `src/Generated/Api/*.php`.
   - TS: `openapi-generator generate -g typescript-fetch -c config/ts.yaml` → generated types +
     a thin fetch layer.
   - Python: `openapi-generator generate -g python -c config/python.yaml`, post-processed to
     retarget models onto `pydantic` v2 rather than the generator's default plain classes.
   - Dart: `openapi-generator generate -g dart-dio -c config/dart.yaml` → models + a `dio`-based
     API layer.
3. **Hand-written layer on top** — the generated code is never shipped directly as the public
   API of the SDK. Each SDK has a hand-written `src/` (or `lib/`) layer that wraps the generated
   `Generated/` code and adds everything this document specifies and that generation cannot
   produce automatically: auto-pagination, the exception hierarchy, retry/backoff, idempotency
   key injection, webhook verification, and the ergonomic method names/fluent builders described
   in **Design Principles**. This split means regenerating models never requires re-writing the
   idiomatic layer, and the idiomatic layer's tests catch any accidental generated-shape change.
4. **Diff & review gate** — CI produces a human-readable diff of the generated layer
   (`openapi-diff` against the previous spec version) and fails the build if a change is
   *breaking* (removed field, removed enum value, changed type) unless the PR is labeled
   `breaking-change` and targets a planned `MAJOR` SDK release branch.
5. **Golden fixture tests** — each SDK repo keeps a set of recorded request/response fixtures
   (JSON) shared across all four SDK repos via a `qayd/sdk-fixtures` submodule, so "does the PHP
   SDK parse the same `invoice.created` webhook payload as the Python SDK" is a real, automated,
   cross-language test rather than an assumption.

Nothing about business rules (double-entry balancing, approval chains, tax computation) is or can
be generated from the OpenAPI spec — those rules live only in Laravel, and the spec documents
their *symptoms* (a 422 shape, a `state` enum) not their implementation.

# Publishing & Distribution

Each SDK is published to the canonical registry for its ecosystem, from the same CI pipeline,
gated on the full test suite (unit tests against fixtures, plus a contract-test run against a
sandbox QAYD environment) passing on the release commit:

| SDK | Registry | Package | Release trigger |
|---|---|---|---|
| PHP | Packagist | `qayd/sdk` | Git tag `php-vX.Y.Z` on `sdk-php` → `composer.json` version bump + `packagist.org` auto-update via the configured GitHub webhook |
| JS/TS | npm | `@qayd/sdk` | Git tag `js-vX.Y.Z` on `sdk-js` → `npm publish --provenance --access public` from CI with npm's OIDC trusted publishing (no long-lived npm token in CI secrets) |
| Python | PyPI | `qayd-sdk` | Git tag `py-vX.Y.Z` on `sdk-python` → build sdist+wheel, `twine upload` via PyPI's trusted publisher (GitHub Actions OIDC, no stored PyPI token) |
| Flutter/Dart | pub.dev | `qayd_sdk` | Git tag `dart-vX.Y.Z` on `sdk-dart` → `dart pub publish` (requires the pub.dev publisher's Google account credential stored as a CI secret; `--dry-run` gate first) |

Distribution requirements common to all four:
- **Signed/attested releases**: npm publishes with provenance attestation; PyPI and Packagist
  releases are built from a pinned CI image and the build log is linked in the release notes;
  pub.dev releases include a `CHANGELOG.md` entry enforced by CI (`pubspec.yaml` version bump
  must have a matching changelog heading, or the publish job fails).
- **README parity**: every package's README on its registry page contains the same "Quick start"
  section (install, construct client, first call, first list, first webhook verification) so a
  developer moving between languages recognizes the same five-minute path each time.
- **License**: MIT, matching across all four repos and packages.
- **Minimum supported runtime is a compatibility promise, not a suggestion**: dropping support
  for a language runtime version (e.g. PHP 8.2) is itself a `MAJOR` SDK bump with its own
  deprecation notice, because integrators' own runtime upgrade cycles are outside QAYD's control.
- **Pre-release channels**: `-beta.N`/`-rc.N` tags publish to the same registries under the
  registry's pre-release mechanism (npm `dist-tag beta`, PyPI pre-release versioning, Packagist
  branch-alias, pub.dev's own pre-release flag) so integrators can opt into testing an upcoming
  `MAJOR` version (e.g. `v2` API support) without affecting default-install consumers.

# Examples

The following four snippets perform the same operation — create an invoice for a customer with
one line item, then retrieve it — showing the same logical call in each SDK's idiom. All four
produce and consume the same request/response shape:

```
POST /api/v1/invoices
X-Company-Id: cmp_9f2a1c
Authorization: Bearer <token>
Content-Type: application/json
Idempotency-Key: <auto-generated-or-caller-supplied-uuid>

{
  "customer_id": "cus_5f2a",
  "currency_code": "KWD",
  "lines": [
    { "product_id": "prd_88a1", "quantity": "2.0000", "unit_price": "45.0000" }
  ]
}
```

```json
{
  "success": true,
  "data": {
    "id": "inv_7c3e91",
    "customer_id": "cus_5f2a",
    "status": "draft",
    "currency_code": "KWD",
    "total": "90.0000",
    "total_due": "90.0000",
    "lines": [
      { "id": "invl_1", "product_id": "prd_88a1", "quantity": "2.0000", "unit_price": "45.0000", "line_total": "90.0000" }
    ],
    "created_at": "2026-07-16T09:12:03Z"
  },
  "message": "Invoice created.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "6c1e2b0a-7a3e-4b1a-9c31-2f6d0a9e6d21",
  "timestamp": "2026-07-16T09:12:03Z"
}
```

**PHP**

```php
use Qayd\Client;

$client = new Client(apiKey: getenv('QAYD_API_KEY'), companyId: 'cmp_9f2a1c');

$invoice = $client->invoices()->create([
    'customerId'  => 'cus_5f2a',
    'currencyCode' => 'KWD',
    'lines' => [
        ['productId' => 'prd_88a1', 'quantity' => '2.0000', 'unitPrice' => '45.0000'],
    ],
]);

echo $invoice->id;                 // "inv_7c3e91"
echo $invoice->totalDue->format(); // "KWD 90.000"

$fetched = $client->invoices()->get($invoice->id);
```

**JavaScript/TypeScript**

```ts
import { QaydClient } from "@qayd/sdk";

const client = new QaydClient({
  auth: { type: "apiKey", apiKey: process.env.QAYD_API_KEY! },
  companyId: "cmp_9f2a1c",
});

const invoice = await client.invoices.create({
  customerId: "cus_5f2a",
  currencyCode: "KWD",
  lines: [{ productId: "prd_88a1", quantity: "2.0000", unitPrice: "45.0000" }],
});

console.log(invoice.id, invoice.totalDue.format());

const fetched = await client.invoices.get(invoice.id);
```

**Python**

```python
from qayd import QaydClient

client = QaydClient(api_key=os.environ["QAYD_API_KEY"], company_id="cmp_9f2a1c")

invoice = client.invoices.create(
    customer_id="cus_5f2a",
    currency_code="KWD",
    lines=[{"product_id": "prd_88a1", "quantity": "2.0000", "unit_price": "45.0000"}],
)

print(invoice.id, invoice.total_due.format())

fetched = client.invoices.retrieve(invoice.id)
```

**Flutter/Dart**

```dart
import 'package:qayd_sdk/qayd_sdk.dart';

final client = QaydClient(apiKey: apiKey, companyId: 'cmp_9f2a1c');

final invoice = await client.invoices.create(
  CreateInvoiceRequest(
    customerId: 'cus_5f2a',
    currencyCode: 'KWD',
    lines: [
      InvoiceLineInput(productId: 'prd_88a1', quantity: '2.0000', unitPrice: '45.0000'),
    ],
  ),
);

print('${invoice.id} ${invoice.totalDue.format()}');

final fetched = await client.invoices.get(invoice.id);
```

A second example demonstrates error handling and idempotent retry-safety together — attempting to
post an unbalanced journal entry, then correcting it:

```python
from qayd.exceptions import ValidationError

try:
    client.journal_entries.post("je_44a1")
except ValidationError as e:
    # e.field_errors == {"lines": ["Debits (1200.0000) do not equal credits (1150.0000)."]}
    fix_lines(e.field_errors)
    client.journal_entries.update("je_44a1", lines=corrected_lines)
    client.journal_entries.post("je_44a1")  # retried safely; same idempotency key if the
                                             # caller supplied one, otherwise a fresh call
```

A third example shows the auto-paginating list plus a webhook consumer working together in one
service — reconciling a page of unpaid invoices, then reacting to a `payment.received` webhook:

```ts
// Nightly reconciliation job
for await (const invoice of client.invoices.list({ status: "unpaid" })) {
  await flagIfOverdue(invoice);
}

// Webhook consumer, same process's HTTP server
app.post("/webhooks/qayd", raw(), (req, res) => {
  const event = verifyWebhookSignature({
    payload: req.body,
    signatureHeader: req.header("Qayd-Signature") ?? "",
    secret: process.env.QAYD_WEBHOOK_SECRET!,
  });
  if (event.type === "payment.received") markInvoicePaidLocally(event.data.invoiceId);
  res.sendStatus(200);
});
```

# Edge Cases

- **Clock skew on webhook verification.** A receiving server with a clock more than
  `tolerance_seconds` off from real time rejects valid webhooks as expired. SDKs log a specific,
  actionable warning (`"signature timestamp is N seconds outside tolerance — check server clock
  (NTP)"`) rather than a generic "invalid signature" message, because the two failure modes
  (actual forgery vs. clock drift) need different fixes.
- **Idempotency key collision across unrelated calls.** If a caller mistakenly reuses a
  hand-supplied idempotency key for two different logical operations (e.g. two different
  invoices), the second call returns `409 Conflict` with the *first* call's resource referenced in
  `errors[].meta.original_request_id`. The SDK's `ConflictException` surfaces that reference so
  the caller can locate and fix the bug, rather than silently returning invoice #1's data as if it
  were invoice #2's — the SDK never masks this as success.
- **Partial multi-currency rounding.** When a caller's SDK-side `Money` type performs
  `.multiply()`/`.allocate()` operations (e.g. splitting a total across lines), rounding uses
  banker's rounding to the currency's minor-unit precision and is required to be display-only —
  the SDK never sends a client-computed derived amount to the API in place of a source amount the
  API itself must (re)compute (e.g. tax); it only uses `Money` math for values the SDK itself
  displays or that are genuinely caller-supplied inputs (unit price × quantity for a *proposed*
  line, before server-side validation).
- **Cursor pagination and concurrent mutation.** Auto-paginating a `list()` while records are
  being created/deleted server-side can, per cursor-pagination semantics, skip a record inserted
  after iteration started or (rarely, depending on the cursor's ordering key) repeat one near a
  deletion boundary. This is documented per-SDK as an explicit caveat rather than presented as
  strongly consistent; callers needing a stable snapshot pass an explicit `as_of`/`snapshot_at`
  filter (where the endpoint supports it) instead of relying on iteration order alone.
- **Rate limiting during bulk export.** A caller iterating a very large `list()` (e.g. a full
  ledger export) can exhaust rate limits mid-iteration. The auto-paginator's built-in retry/backoff
  (see **Retries & Backoff**) already respects `Retry-After` transparently, but SDKs additionally
  expose a `throttle: { requestsPerSecond }` client option so long-running bulk jobs can
  self-limit proactively rather than reactively, which is friendlier to shared rate-limit buckets
  on multi-process integrations.
- **Offline/queued writes on mobile.** The Flutter SDK is used from a mobile app that can go
  offline mid-write. The SDK does not implement an offline write queue itself (that is an
  app-level concern, since only the app knows what UI state depends on the pending write) but
  every mutating call's required idempotency key means an app-level retry queue built on top of
  the SDK is automatically safe to replay after reconnecting, without the SDK needing special
  "offline mode."
- **Enum forward-compatibility at runtime.** An older SDK talking to a newer API can receive an
  enum value it does not recognize (e.g. a new `invoice.status` value added server-side). Rather
  than throwing, every SDK's enum deserialization maps unrecognized values to an explicit
  `Unknown("raw_value")` variant (not silently to an existing value, and not to `null`), so calling
  code that pattern-matches exhaustively is forced by the type system to handle the unknown case,
  and code inspecting `.rawValue` can still see and log the actual server-sent string.
- **Multi-tenant client reuse across requests in a web server.** In a Node/PHP/Python web server
  handling many companies' requests concurrently on shared worker processes, constructing a new
  `forCompany()` scoped client per request (see **Company Context**) — never a single
  mutable-`companyId` client shared across requests — is mandatory; SDKs document this explicitly
  because a shared mutable client with a settable `companyId` property is exactly the kind of API
  that invites a request-isolation bug, which is why `companyId` is immutable post-construction and
  `forCompany()` returns a new instance rather than mutating in place.
- **Large decimal precision beyond a language's native float.** Python's SDK uses `Decimal`
  natively so this is a non-issue; the JS SDK, where `number` cannot safely represent
  `NUMERIC(19,4)` at the extremes QAYD's larger enterprise customers can reach, represents money
  amounts as strings internally and only exposes numeric access via an explicit
  `.toNumber({ acknowledgePrecisionLoss: true })` escape hatch that requires an opt-in flag, so a
  developer cannot accidentally lose precision by calling `Number(invoice.total)` on a value the
  SDK deliberately kept as a string.

# End of Document
