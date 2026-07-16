# API Idempotency — QAYD API Layer

Version: 1.0
Status: Design Specification
Module: API
Submodule: Idempotency

---

# Purpose

This document specifies how the QAYD API layer guarantees **safe retries** for state-changing HTTP
requests. QAYD is an AI Financial Operating System: its Laravel 12 (PHP 8.4+) backend is the single
source of truth for every ledger, invoice, payment, payroll run, and bank transfer created by the
Next.js web app, the Flutter mobile app, third-party integrations, or the AI engine (FastAPI/Python)
acting on a user's behalf. In this domain, a duplicate `POST /api/v1/accounting/journal-entries` is not
a cosmetic bug — it is a second journal entry, a double-charged customer, or a duplicate bank transfer.
Idempotency is the mechanism that lets any client retry a request after a timeout, a dropped connection,
or a 5xx response, with a **guarantee that the underlying financial effect happens at most once**,
regardless of how many times the identical request is replayed.

This document defines the `Idempotency-Key` request header, the server-side idempotency store, the
request-fingerprinting and conflict-detection rules, TTL and cleanup policy, the scope of a key,
interaction with the platform's retry and webhook systems, the natural idempotency of GET/PUT/DELETE,
SDK guidance for automatic key generation, worked examples, and the edge cases every engineer
implementing a write endpoint on this platform must handle. It applies uniformly across every module —
Accounting, Sales, Purchasing, Banking, Inventory, Payroll, Tax, and Reports — because idempotency is
an API-layer concern, not a module concern: it is implemented once, in shared middleware, and every
money-moving controller inherits it for free.

# Why Idempotency

## The problem

HTTP is fundamentally unreliable at the network layer. A client can send a `POST` request, the server
can fully execute it (create the invoice, post the journal entry, debit the bank account), and the
response can still be lost — a mobile client loses signal mid-tunnel, a proxy times out, a load balancer
drops the connection during a deploy. From the client's point of view, the request **appears to have
failed**, and the only reasonable client behavior is to retry. Without idempotency, that retry creates a
second invoice, a second journal entry, or a second bank transfer for the same underlying business
event. In an accounting system this is not a UX nuisance — it corrupts the ledger, breaks
`SUM(debits) = SUM(credits)`, and can trigger duplicate real-world money movement.

## Where this specifically bites QAYD

- **`POST /api/v1/sales/invoices`** — a caregiver retries a slow request; the customer receives two
  invoices for the same order.
- **`POST /api/v1/banking/transfers`** — a mobile client loses connectivity right after submitting a
  wire transfer; the user taps "Send" again; without idempotency, the receiving account is credited
  twice.
- **`POST /api/v1/accounting/journal-entries`** — the AI engine's Treasury Manager agent submits a
  proposed journal entry on the user's behalf via the API; the FastAPI process retries on a 502 from an
  intermediate proxy; a duplicate unbalanced pair of entries would silently corrupt the General Ledger.
- **`POST /api/v1/purchasing/vendor-payments`** — a vendor payment is queued for approval; the approval
  webhook fires, the client retries the approval call because the webhook receipt timed out, and the
  vendor is paid twice.
- **`POST /api/v1/payroll/runs/{id}/release`** — payroll release is one of the platform's designated
  sensitive, human-approval-chain operations; a duplicate release would double-pay every employee in
  the run.

## The guarantee QAYD provides

Every non-idempotent-by-nature write endpoint (in practice: every `POST` that creates or mutates a
financial resource, and selected `PATCH` operations that transition state, e.g. `journal.post`,
`payroll.release`, `bank.transfer`) supports an `Idempotency-Key` header. When a client supplies the
same key for the same logical operation more than once, QAYD guarantees:

1. The underlying side effect (row creation, journal posting, money movement, webhook dispatch) executes
   **exactly once**.
2. Every retry with that key receives the **exact same response** (status code, body, `request_id`) that
   the original, successful execution produced.
3. A retry that reuses a key but changes the request body is rejected — the client is telling the server
   two different things while claiming they are the same operation, which is caught and reported rather
   than silently accepted or silently ignored.

Idempotency at QAYD is **client-driven** (the client decides retry boundaries and generates the key) and
**server-enforced** (the server is the arbiter of what counts as "the same request" and what response to
replay). It composes with — and does not replace — the platform's authentication, RBAC authorization,
and validation pipeline: an idempotency key never bypasses `auth → authorize → validate → execute`.

# Idempotency-Key Header

## Header contract

| Header | Direction | Required | Format | Description |
|---|---|---|---|---|
| `Idempotency-Key` | Request | Required on all money-moving `POST`/state-transition endpoints; optional elsewhere | String, 1–255 chars, `^[A-Za-z0-9_\-:.]+$` | Client-generated unique token identifying one logical attempt at one business operation |
| `Idempotency-Replayed` | Response | Server-set | `true` \| absent | Present and `true` when the response was served from the idempotency store rather than freshly executed |
| `Idempotency-Status` | Response | Server-set | `new` \| `replayed` \| `in-progress` \| `conflict` | Machine-readable outcome of idempotency handling for this call |
| `X-Company-Id` | Request | Required (platform-wide) | Integer | Active company; part of the idempotency scope (see **Scope**) |
| `X-Idempotency-Key-Expires-At` | Response | Server-set | ISO 8601 | The TTL boundary after which this key may be reused for a new attempt |

## Client contract

- The key MUST be generated by the client **before** the first network attempt and reused, unchanged,
  for every retry of that same logical attempt.
- A NEW user-initiated action (the user clicks "Save" again, deliberately, after seeing a successful
  confirmation) MUST use a NEW key. Idempotency keys represent "this is the same network attempt," not
  "this is the same kind of thing the user wants to do."
- Recommended format: a v4 UUID, or a v4 UUID prefixed with a client-meaningful namespace, e.g.
  `inv-create:5e2b3b8e-8f2a-4a1e-9c2d-1a2b3c4d5e6f`. QAYD does not parse the key's structure; it treats
  it as an opaque string scoped as described below.
- Idempotency keys are **not** a substitute for a resource's business primary key (e.g. an invoice
  number) and are never returned as part of the resource itself — they are a transport-layer replay
  guard, not domain data.

## Server-side enforcement middleware

```php
// app/Http/Middleware/EnforceIdempotency.php
namespace App\Http\Middleware;

use App\Services\Idempotency\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotency
{
    public function __construct(private IdempotencyService $idempotency) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            if ($this->idempotency->isRequired($request)) {
                return response()->json([
                    'success'    => false,
                    'data'       => null,
                    'message'    => 'Idempotency-Key header is required for this operation.',
                    'errors'     => [['field' => 'Idempotency-Key', 'code' => 'REQUIRED']],
                    'meta'       => new \stdClass(),
                    'request_id' => (string) \Illuminate\Support\Str::uuid(),
                    'timestamp'  => now()->toIso8601String(),
                ], 400);
            }
            return $next($request);
        }

        $companyId = $request->header('X-Company-Id');
        $fingerprint = $this->idempotency->fingerprint($request);

        $lock = $this->idempotency->acquireOrReplay($key, $companyId, $request->path(), $fingerprint);

        if ($lock->isConflict()) {
            return response()->json([
                'success'    => false,
                'data'       => null,
                'message'    => 'Idempotency-Key was already used with a different request payload.',
                'errors'     => [['field' => 'Idempotency-Key', 'code' => 'IDEMPOTENCY_KEY_CONFLICT']],
                'meta'       => new \stdClass(),
                'request_id' => (string) \Illuminate\Support\Str::uuid(),
                'timestamp'  => now()->toIso8601String(),
            ], 409)->header('Idempotency-Status', 'conflict');
        }

        if ($lock->isInProgress()) {
            return response()->json([
                'success'    => false,
                'data'       => null,
                'message'    => 'A request with this Idempotency-Key is still being processed.',
                'errors'     => [['field' => 'Idempotency-Key', 'code' => 'IDEMPOTENCY_IN_PROGRESS']],
                'meta'       => new \stdClass(),
                'request_id' => (string) \Illuminate\Support\Str::uuid(),
                'timestamp'  => now()->toIso8601String(),
            ], 409)->header('Idempotency-Status', 'in-progress');
        }

        if ($lock->isReplay()) {
            return $this->idempotency->replayResponse($lock)
                ->header('Idempotency-Replayed', 'true')
                ->header('Idempotency-Status', 'replayed');
        }

        // New key: execute the request, then persist the response under the lock.
        $response = $next($request);
        $this->idempotency->commit($lock, $response);

        return $response->header('Idempotency-Status', 'new');
    }
}
```

This middleware is registered on the route groups for every money-moving controller (`routes/api.php`,
group `middleware(['auth:sanctum', 'company.context', 'idempotent'])`) and runs **after** authentication
and company-context resolution but **before** the FormRequest validation and the Service layer, so that
a replay never re-runs validation or touches the Repository layer at all — it is a pure cache hit.

# Idempotency Store

## Storage engine

QAYD uses a **two-tier idempotency store**, consistent with the platform's existing Redis-for-cache,
PostgreSQL-for-durable-truth split:

- **Redis** holds the fast-path lock and short-lived response cache (sub-millisecond lookups, used on
  every retried request while it matters most — the first few seconds/minutes after the original call).
- **PostgreSQL** (`idempotency_keys` table) holds the durable, queryable, auditable record — required
  because financial idempotency keys must survive a Redis restart/eviction and must be inspectable by
  Auditors and support engineers investigating a duplicate-charge complaint.

Redis is the request path's source of truth for speed; Postgres is the system of record. On a Redis
miss, the middleware falls back to Postgres before concluding the key is genuinely new — this prevents
a Redis eviction from silently reopening a window for duplicate execution of a financial mutation.

## PostgreSQL DDL

```sql
CREATE TYPE idempotency_status AS ENUM ('in_progress', 'completed', 'failed');

CREATE TABLE idempotency_keys (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),          -- tenant scope, indexed
    idempotency_key VARCHAR(255) NOT NULL,                             -- client-supplied token
    route           VARCHAR(255) NOT NULL,                             -- e.g. POST /api/v1/sales/invoices
    method          VARCHAR(10)  NOT NULL,
    request_hash    VARCHAR(64)  NOT NULL,                             -- SHA-256 of the fingerprint (see below)
    status          idempotency_status NOT NULL DEFAULT 'in_progress',
    response_status SMALLINT     NULL,                                 -- HTTP status of the stored response
    response_body   JSONB        NULL,                                 -- the full envelope to replay verbatim
    response_headers JSONB       NULL,                                 -- selected headers to replay (e.g. Location)
    resource_type   VARCHAR(100) NULL,                                 -- e.g. 'invoice', 'journal_entry'
    resource_id     BIGINT       NULL,                                 -- id of the record the call created/affected
    request_id      UUID         NOT NULL,                             -- envelope request_id of the ORIGINAL call
    user_id         BIGINT       NULL REFERENCES users(id),
    locked_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),                -- when the in-flight lock was taken
    completed_at    TIMESTAMPTZ  NULL,
    expires_at      TIMESTAMPTZ  NOT NULL,                              -- TTL boundary; see TTL & Cleanup
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT uq_idempotency_scope UNIQUE (company_id, route, idempotency_key)
);

CREATE INDEX idx_idempotency_lookup   ON idempotency_keys (company_id, route, idempotency_key);
CREATE INDEX idx_idempotency_expiry   ON idempotency_keys (expires_at) WHERE status <> 'in_progress';
CREATE INDEX idx_idempotency_resource ON idempotency_keys (resource_type, resource_id);
CREATE INDEX idx_idempotency_stuck    ON idempotency_keys (locked_at) WHERE status = 'in_progress';
```

Notes on the schema:

- **Company-scoped uniqueness** (`uq_idempotency_scope`) is the enforcement point for the multi-tenant
  rule: the exact same key string used by two different companies is two different idempotency records,
  never a collision — Company A can never observe or replay Company B's cached response.
- `request_hash` stores a SHA-256 digest rather than the raw fingerprint for compact indexing and to
  avoid storing a second full copy of potentially sensitive request data outside of the audit log.
- `response_body` intentionally stores the **entire standard envelope** (`success`, `data`, `message`,
  `errors`, `meta`, `timestamp`) except `request_id`, which is replayed from the stored `request_id`
  column so a replayed call always returns the original call's `request_id` — critical for support
  and audit correlation ("which original request actually created this invoice?").
- This table does **not** replace `audit_logs`. Every committed idempotent write still produces its own
  audit log entry at the moment of original execution; `idempotency_keys` is a replay cache, not the
  audit trail, though `request_id` links the two.

## Redis key shape

```
idem:{company_id}:{route_hash}:{idempotency_key}   -> JSON { status, request_hash, response, request_id }
    TTL: matches idempotency_keys.expires_at (see TTL & Cleanup)

idem-lock:{company_id}:{route_hash}:{idempotency_key} -> "1"
    TTL: 30s, used only to detect/serialize concurrent in-flight duplicate calls (see Edge Cases)
```

`route_hash` is a short hash of the normalized route pattern (e.g. `sales/invoices`, not the resolved
URL with an id in it), keeping the key compact while preserving the "scoped per endpoint" rule below.

# Request Fingerprinting & Conflict

## Why fingerprint at all

An `Idempotency-Key` says "replay me if you've seen me before" — but the server must never replay a
cached invoice-creation response for a request whose **body has changed**. If it did, a client bug that
accidentally reused a key across two different invoices would silently create the first invoice and
return its receipt for the second, entirely different, invoice — the client believes it created
Invoice B but the server actually returns Invoice A's data. This is worse than a duplicate: it is silent
data corruption. QAYD prevents this with **request fingerprinting**: on every reuse of a key, the server
recomputes a canonical hash of the semantically relevant parts of the request and compares it to the
hash stored at the key's original creation.

## Fingerprint composition

```
fingerprint = SHA256(
    METHOD + "\n" +
    NORMALIZED_PATH + "\n" +               -- e.g. /api/v1/sales/invoices (no query string)
    X-Company-Id + "\n" +
    CANONICAL_JSON(request_body)           -- keys sorted, whitespace-normalized, floats as strings
)
```

- Query string parameters are excluded from the write-endpoint fingerprint (writes at QAYD carry their
  payload in the body; query parameters on a write endpoint, if any, are considered request metadata,
  not business data).
- `CANONICAL_JSON` sorts object keys recursively and serializes numbers as fixed-precision strings so
  that `{"amount": 10.50}` and `{"amount":10.5000}` fingerprint identically — critical because the
  `NUMERIC(19,4)` monetary type means trailing-zero variation is not a semantic difference.
- Headers other than `X-Company-Id` are excluded (e.g. a changed `User-Agent` on retry must not trigger
  a false conflict).
- File uploads (e.g. an attachment on a bill) are fingerprinted by their content hash, not by including
  raw bytes in the JSON fingerprint.

## Conflict resolution rule

| Stored fingerprint vs. incoming fingerprint | Server behavior | HTTP status |
|---|---|---|
| Match, stored status `completed` | Replay stored response verbatim | Original status (e.g. `201`) |
| Match, stored status `in_progress` | Reject as concurrent duplicate (see Edge Cases) | `409` |
| Match, stored status `failed` | Re-execute (a failed attempt does not lock the key) | Fresh execution |
| **Mismatch** | Reject — do NOT execute, do NOT replay | `409` |

A fingerprint mismatch is reported using the standard error envelope with a `422`-style errors array but
a `409 Conflict` status code, because the conflict is about the *key*, not the *field validation* of the
new payload:

```json
{
  "success": false,
  "data": null,
  "message": "Idempotency-Key 'inv-create:5e2b3b8e-...' was previously used with a different request body.",
  "errors": [
    {
      "field": "Idempotency-Key",
      "code": "IDEMPOTENCY_KEY_CONFLICT",
      "detail": "Reuse a fresh key for a new logical request, or resend the exact original payload to replay it."
    }
  ],
  "meta": {},
  "request_id": "7c1f2e2a-2222-4b3b-9a1a-8e2b1a2c3d4e",
  "timestamp": "2026-07-16T12:03:41Z"
}
```

This is intentionally an HTTP `409`, matching the platform's declared error-code palette, and is
distinguishable in client SDKs from the `409` returned for a genuinely in-progress duplicate via the
`errors[0].code` discriminator (`IDEMPOTENCY_KEY_CONFLICT` vs. `IDEMPOTENCY_IN_PROGRESS`).

# TTL & Cleanup

## TTL policy

| Property | Value | Rationale |
|---|---|---|
| Default TTL | 24 hours from `locked_at` | Covers realistic retry windows (offline mobile clients reconnecting) without holding financial replay caches indefinitely |
| Sensitive-op TTL (`bank.transfer`, `payroll.release`, `tax.submit`) | 72 hours | These flow through human approval chains that can legitimately take longer than 24h between the original attempt and a client's final retry |
| In-progress lock TTL (Redis `idem-lock:*`) | 30 seconds, auto-renewed every 10s while the request executes | Bounds how long a genuinely stuck request can block a legitimate retry (see Edge Cases) |
| Minimum enforced TTL | 1 hour | Clients may not request a shorter TTL; below this, retries after a normal mobile network blip would no longer be protected |
| Maximum TTL | 7 days | Configurable per-endpoint via `config('idempotency.ttl_overrides')`; nothing is cached indefinitely |

`expires_at` is computed once at key creation (`locked_at + ttl`) and never extended by a replay — a
replayed call does not "renew" the key's lifetime, preventing an aggressively-polling buggy client from
keeping a stale response alive forever.

## Cleanup

- **Redis**: keys expire natively via `EXPIRE`/`SETEX`; no cleanup job required for the Redis tier.
- **PostgreSQL**: a scheduled Laravel command runs the cleanup sweep:

```php
// app/Console/Commands/PruneIdempotencyKeys.php
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    public function handle(): void
    {
        $deleted = DB::table('idempotency_keys')
            ->where('expires_at', '<', now())
            ->where('status', '!=', 'in_progress')   // never prune a live lock, even if the TTL math looks expired
            ->limit(5000)                              // batched delete to avoid long locks on a hot table
            ->delete();

        Log::info('idempotency:prune removed rows', ['count' => $deleted]);
    }
}
```

Scheduled hourly in `routes/console.php` (`Schedule::command('idempotency:prune')->hourly()`), batched
to avoid a single multi-million-row `DELETE` from taking a heavy lock on a table that is also being
written to constantly by every live write endpoint. Rows with `status = 'in_progress'` past their TTL
are handled separately as **stuck locks** (see Edge Cases) rather than blindly deleted, because a stuck
`in_progress` row indicates a crashed worker mid-transaction, not a clean expiry.

- **Retention vs. audit**: pruning `idempotency_keys` never deletes the underlying business record or
  its `audit_logs` entry — those follow the platform's normal soft-delete/immutability rules
  independently. Idempotency-key pruning only removes the *replay cache*, not financial history.

# Scope

Every idempotency key at QAYD is scoped along three dimensions simultaneously — **company**, **endpoint
route**, and **key string** — matching the `uq_idempotency_scope UNIQUE (company_id, route, idempotency_key)`
constraint above.

| Dimension | Rule |
|---|---|
| **Per company** | The same key string used under two different `X-Company-Id` values is two unrelated keys. A key is never visible or replayable across companies — this is the same absolute isolation rule that governs every table in the schema. |
| **Per endpoint** | A key is scoped to the specific route pattern (`POST /api/v1/sales/invoices` ≠ `POST /api/v1/banking/transfers`), never globally. A client accidentally reusing a UUID across two different write endpoints gets two independent idempotency records, not a cross-endpoint collision. |
| **Per user (advisory, not primary key)** | `user_id` is recorded for audit/support purposes and to let SDKs default to per-session key namespacing, but is **not** part of the uniqueness constraint — a shared service account intentionally issuing the same key from two processes is exactly the "concurrent duplicate" case the lock is designed to catch (see Edge Cases), not a scope violation. |

Scope is deliberately **narrower than "global"** and **no broader than one company + one endpoint**.
This means:

- Rotating the same key across `invoices` and `bills` is safe and expected (they are different
  endpoints).
- An AI agent (e.g. Treasury Manager) generating draft journal entries for multiple companies the user
  has access to must generate a fresh key per company, even for what looks like "the same" logical
  batch operation, because the server will never treat cross-company reuse as a replay in the first
  place — it would be rejected upstream by RBAC/company-context resolution before idempotency is even
  evaluated.

# Interaction With Retries & Webhooks

## Client retry policy

QAYD's SDKs implement the following default retry policy for any request carrying an `Idempotency-Key`:

| Condition | Retry? | Backoff |
|---|---|---|
| Network error / timeout / no response received | Yes | Exponential, base 500ms, up to 5 attempts |
| `500`, `502`, `503`, `504` | Yes | Exponential, base 500ms, up to 5 attempts |
| `429` | Yes, honoring `Retry-After` | As specified by header |
| `409` with `errors[0].code = IDEMPOTENCY_IN_PROGRESS` | Yes | Short fixed delay (1–2s), a handful of attempts, since the original call is expected to finish quickly |
| `409` with `errors[0].code = IDEMPOTENCY_KEY_CONFLICT` | **No** | Client bug — surface to the caller, do not retry with the same key |
| `400`, `401`, `403`, `404`, `422` | **No** | Not retryable; these mean the request itself needs to change, and any retry MUST use a fresh key once the request is fixed |

A request is never retried indefinitely: SDKs cap total retry duration (default 30 seconds for
interactive endpoints, configurable higher for background/batch operations like payroll runs) and
surface a clear timeout error rather than silently giving up.

## Webhooks are a separate idempotency domain

QAYD's domain-event webhooks (`invoice.created`, `invoice.paid`, `payment.received`,
`payroll.completed`, `inventory.updated`, `bank.synced`, `ai.finished`, `journal.posted`, and others)
are delivered **at least once**, independent of whether the API call that triggered them used an
`Idempotency-Key`. This is intentional and symmetrical: the API is safe against duplicate *requests*,
and webhook consumers must independently be safe against duplicate *deliveries*, because network
unreliability exists on the outbound leg too.

- Every webhook delivery carries a stable `event_id` (a UUID, distinct from the triggering request's
  `request_id`, though the payload includes the original `request_id` for correlation).
- QAYD's own webhook dispatcher deduplicates *retries of the same delivery* using `event_id` as its own
  idempotency key against a `webhook_deliveries` log — so a webhook consumer returning a slow `200` does
  not cause QAYD to fire a second, distinct delivery attempt beyond the documented retry schedule
  (5 attempts, exponential backoff, dead-letter after final failure).
- A single idempotent API call that succeeds exactly once still fires its webhook exactly once at the
  point of commit (e.g. `invoice.created` fires once when the invoice transitions into existence,
  never once per retried HTTP request that happened to hit the *replay* path — replays never re-fire
  domain events, since a replay does not re-execute the Service layer at all).
- Consumers integrating with QAYD are documented (in the Webhooks doc) to treat `event_id` the same way
  QAYD's own clients treat `Idempotency-Key`: store it, and ignore a redelivery of an `event_id` already
  processed.

## AI-engine callers

The FastAPI AI engine calls the Laravel API exactly like any other client — it is not a special path and
does not bypass idempotency. When an AI agent (e.g. the Treasury Manager preparing a proposed bank
transfer, or the General Accountant proposing a journal entry) submits a `POST`, it generates its own
`Idempotency-Key` per proposed action and is subject to the identical fingerprinting, conflict, and
replay rules as the Next.js or Flutter clients. If an AI agent's HTTP client retries a timed-out call to
`POST /api/v1/accounting/journal-entries`, the platform guarantees the same "exactly once" semantics
whether the caller is a human-driven mobile tap or an autonomous agent loop — this is precisely why
idempotency is enforced centrally in middleware rather than per-controller: no caller, human or AI, gets
a weaker guarantee.

# GET/PUT/DELETE Natural Idempotency

Not every HTTP method needs the `Idempotency-Key` mechanism, because some methods are **naturally
idempotent by HTTP semantics** — applying them N times has the same effect as applying them once,
without any additional bookkeeping.

| Method | Natural idempotency at QAYD | Idempotency-Key required? | Notes |
|---|---|---|---|
| `GET` | Yes — read-only, no state change | No | Never accepts or needs the header; a cache-control/ETag strategy (separate doc) handles read freshness, not idempotency |
| `PUT` | Yes, when used as full-resource replace (e.g. `PUT /api/v1/sales/customers/{id}`) | No, but honored if supplied | Replacing a resource with the same payload twice yields the same end state; the platform still accepts an optional key for clients that want replay-with-identical-response semantics, e.g. to avoid re-running validation twice |
| `PATCH` | **Not assumed idempotent** — QAYD's `PATCH` endpoints are used for state transitions (`PATCH /api/v1/accounting/journal-entries/{id}/post`, `PATCH /api/v1/payroll/runs/{id}/release`), which are *not* naturally idempotent (posting an already-posted entry again is a business-rule violation, not a no-op) | **Yes, required** | Treated identically to `POST` by the middleware; these are exactly the "sensitive operation" transitions the platform singles out for human approval chains |
| `DELETE` | Yes, in the HTTP sense — deleting an already-deleted (or never-existing) resource converges to the same state | No | QAYD never hard-deletes financial records (`deleted_at` soft delete only); a repeated `DELETE` on an already-soft-deleted resource returns `404` or a `200` no-op (see table below) rather than an error, satisfying idempotency without a key |
| `POST` | **Never naturally idempotent** — creates a new resource by definition | **Yes, required for money-moving resources** | This is the entire subject of this document |

## DELETE convergence behavior

```
DELETE /api/v1/sales/invoices/{id}   (invoice already soft-deleted)
  -> 200 OK, envelope: { success: true, data: { id, deleted_at: "<original timestamp>" }, message: "Invoice already deleted." }

DELETE /api/v1/sales/invoices/{id}   (invoice never existed / different company)
  -> 404 Not Found
```

Because posted accounting records are immutable and never hard-deleted, `DELETE` on a posted
`journal_entries` row is itself rejected with `409` (business-rule conflict — must reverse, not delete);
that rejection is naturally idempotent too: it returns the same `409` on every repeat.

# Client/SDK Guidance

QAYD ships first-party SDKs for PHP, JavaScript/TypeScript, Python, and Flutter/Dart. All four bake in
automatic idempotency-key handling so application engineers do not hand-roll UUID generation at every
call site.

## Default behavior (all SDKs)

- Every SDK method that maps to a `POST` or sensitive `PATCH` **auto-generates a v4 UUID** as the
  `Idempotency-Key` if the caller does not supply one, scoped to that single method invocation.
- The generated key is attached **before** the first network attempt, and the SDK's internal retry
  logic reuses it across retries of that same call automatically — the application developer gets safe
  retries for free without thinking about idempotency at all.
- SDKs expose an explicit override so application code can supply its own key when it needs one that
  survives across process restarts (e.g. a mobile app that wants a form submission to be safely
  retryable even if the app is killed and relaunched before the retry completes).

## TypeScript SDK example

```ts
import { QaydClient } from "@qayd/sdk";

const qayd = new QaydClient({ companyId: 42, token: sanctumToken });

// Auto-generated key, safe against network retries within this call:
const invoice = await qayd.sales.invoices.create({
  customerId: 1001,
  currencyCode: "KWD",
  items: [{ productId: 55, quantity: 2, unitPrice: "12.5000" }],
});

// Explicit key, survives app restarts (e.g. persisted form draft):
const invoice2 = await qayd.sales.invoices.create(
  { customerId: 1001, currencyCode: "KWD", items: [...] },
  { idempotencyKey: `draft-${draftId}` }
);
```

## PHP SDK example

```php
use Qayd\Sdk\QaydClient;

$qayd = new QaydClient(companyId: 42, token: $token);

// The SDK generates and attaches Idempotency-Key transparently, retrying with the same
// key on network-level failure and 5xx up to the client's configured retry budget.
$response = $qayd->accounting()->journalEntries()->create([
    'entry_date' => '2026-07-16',
    'lines' => [
        ['account_id' => 1101, 'debit' => '150.0000', 'credit' => '0.0000'],
        ['account_id' => 4001, 'debit' => '0.0000',   'credit' => '150.0000'],
    ],
]);
```

## Guidance for non-SDK / raw HTTP integrators

- Generate the key with a cryptographically adequate UUID library, never with a timestamp or an
  incrementing counter (both risk collision or predictability).
- Persist the key locally **before** sending the request if the operation matters enough that the
  caller needs to survive a process crash between "sent" and "confirmed" (e.g. store it alongside a
  locally-queued invoice draft, clear it only after a `2xx` is durably observed).
- Never log the key alongside unrelated request bodies in a way that could let it be replayed by another
  process against a different payload — treat it as a single-use token for a single logical operation.
- On receiving `409 IDEMPOTENCY_KEY_CONFLICT`, do not retry with the same key; generate a new key and
  treat the situation as a client-side bug to fix (typically: a key was cached and reused across two
  distinct user actions).

# Examples

## Example 1: Successful create, then a network-timeout retry (the common case)

**Attempt 1** — client sends the request, but the response is lost in transit after the server has
already committed the invoice:

```
POST /api/v1/sales/invoices HTTP/1.1
Host: api.qayd.com
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 42
Idempotency-Key: inv-create:5e2b3b8e-8f2a-4a1e-9c2d-1a2b3c4d5e6f
Content-Type: application/json

{
  "customer_id": 1001,
  "currency_code": "KWD",
  "issue_date": "2026-07-16",
  "due_date": "2026-08-15",
  "items": [
    { "product_id": 55, "quantity": "2.0000", "unit_price": "12.5000" }
  ]
}
```

Server executes fully, commits, and *would have* returned:

```json
{
  "success": true,
  "data": {
    "id": 88231,
    "invoice_number": "INV-2026-004512",
    "customer_id": 1001,
    "status": "issued",
    "currency_code": "KWD",
    "exchange_rate": "1.000000",
    "subtotal": "25.0000",
    "tax_total": "1.2500",
    "total": "26.2500",
    "issue_date": "2026-07-16",
    "due_date": "2026-08-15"
  },
  "message": "Invoice created successfully.",
  "errors": [],
  "meta": {},
  "request_id": "a1b2c3d4-1111-4a2b-9c3d-4e5f6a7b8c9d",
  "timestamp": "2026-07-16T12:00:03Z"
}
```

...but the client's TCP connection drops before this response arrives. The client's SDK retry logic,
seeing a network-level failure, retries with the **identical key and identical body**:

**Attempt 2 (retry, identical key + body)**:

```
POST /api/v1/sales/invoices HTTP/1.1
Idempotency-Key: inv-create:5e2b3b8e-8f2a-4a1e-9c2d-1a2b3c4d5e6f
... (identical headers and body)
```

The middleware finds a `completed` record for this key with a matching fingerprint and replays the
stored response verbatim, without touching the Service or Repository layer:

```
HTTP/1.1 201 Created
Idempotency-Replayed: true
Idempotency-Status: replayed
Content-Type: application/json

{
  "success": true,
  "data": {
    "id": 88231,
    "invoice_number": "INV-2026-004512",
    ...
  },
  "message": "Invoice created successfully.",
  "errors": [],
  "meta": {},
  "request_id": "a1b2c3d4-1111-4a2b-9c3d-4e5f6a7b8c9d",
  "timestamp": "2026-07-16T12:00:03Z"
}
```

Note the `request_id` on the replay is the **original** call's id, not a new one — the client's logs
and QAYD's server-side audit trail agree on exactly one causal event, even though two HTTP requests were
observed on the wire. Exactly one invoice, one `INV-2026-004512`, one `invoice.created` webhook.

## Example 2: Same key, different body — rejected conflict

```
POST /api/v1/banking/transfers HTTP/1.1
X-Company-Id: 42
Idempotency-Key: transfer-2026-07-16-payroll-batch-9

{ "from_account_id": 5, "to_account_id": 19, "amount": "1000.0000", "currency_code": "KWD" }
```

...returns `201` and is recorded. A bug in the caller then reuses the same key for a *different*
transfer:

```
POST /api/v1/banking/transfers HTTP/1.1
X-Company-Id: 42
Idempotency-Key: transfer-2026-07-16-payroll-batch-9

{ "from_account_id": 5, "to_account_id": 24, "amount": "1500.0000", "currency_code": "KWD" }
```

```
HTTP/1.1 409 Conflict
Idempotency-Status: conflict

{
  "success": false,
  "data": null,
  "message": "Idempotency-Key 'transfer-2026-07-16-payroll-batch-9' was previously used with a different request body.",
  "errors": [
    { "field": "Idempotency-Key", "code": "IDEMPOTENCY_KEY_CONFLICT" }
  ],
  "meta": {},
  "request_id": "b7c8d9e0-2222-4a2b-9c3d-4e5f6a7b8c9d",
  "timestamp": "2026-07-16T12:05:11Z"
}
```

The second, genuinely different transfer of KWD 1,500 never executes under this key. The caller must
retry with a fresh key.

## Example 3: Concurrent duplicate (double-tap) on a slow request

```
Client fires two near-simultaneous requests (e.g. a double-tap on "Pay Now" in the Flutter app)
with the SAME key while the first is still executing:

Request A: POST /api/v1/purchasing/vendor-payments   Idempotency-Key: pay-vendor-778-attempt-1   (t=0ms)
Request B: POST /api/v1/purchasing/vendor-payments   Idempotency-Key: pay-vendor-778-attempt-1   (t=40ms)
```

Request A acquires the Redis `idem-lock`, begins executing. Request B, arriving 40ms later while A is
still `in_progress`, gets:

```
HTTP/1.1 409 Conflict
Idempotency-Status: in-progress

{
  "success": false,
  "data": null,
  "message": "A request with this Idempotency-Key is still being processed. Retry shortly.",
  "errors": [ { "field": "Idempotency-Key", "code": "IDEMPOTENCY_IN_PROGRESS" } ],
  "meta": {},
  "request_id": "c1d2e3f4-3333-4a2b-9c3d-4e5f6a7b8c9d",
  "timestamp": "2026-07-16T12:06:00Z"
}
```

The SDK's short-fixed-delay retry policy for this specific error code retries Request B after ~1.5s, by
which point Request A has completed and the key is `completed` — Request B now receives the replayed,
successful response instead of executing the vendor payment a second time.

## Example 4: Sensitive operation with extended TTL

```
PATCH /api/v1/payroll/runs/4471/release HTTP/1.1
X-Company-Id: 42
Idempotency-Key: payroll-release-4471
Authorization: Bearer eyJhbGciOi...

{ "approved_by": 17, "approval_reference": "APR-2026-0716-002" }
```

```json
{
  "success": true,
  "data": { "id": 4471, "status": "released", "released_at": "2026-07-16T13:00:00Z", "employees_paid": 84 },
  "message": "Payroll run released.",
  "errors": [],
  "meta": {},
  "request_id": "d4e5f6a7-4444-4a2b-9c3d-4e5f6a7b8c9d",
  "timestamp": "2026-07-16T13:00:00Z"
}
```

This key is stored with the **72-hour sensitive-op TTL**, since a finance manager's approval-chain
follow-up call (confirming release status after being pulled into an unrelated meeting) may legitimately
happen well over 24 hours later, and must still return the original, exactly-once result rather than
attempting to re-release payroll.

# Edge Cases

| # | Edge case | Resolution |
|---|---|---|
| 1 | **Concurrent identical requests (double-tap)** | First request takes the Redis `idem-lock`; concurrent duplicates receive `409 IDEMPOTENCY_IN_PROGRESS` and are expected to retry briefly (see Example 3). No two concurrent executions of the Service layer ever run for the same key. |
| 2 | **Worker/process crash mid-execution (stuck lock)** | If a request crashes after acquiring the lock but before committing (`status` stuck at `in_progress` past the 30s Redis lock TTL and past a configurable Postgres staleness threshold, default 5 minutes), a scheduled reconciliation job (`idempotency:reconcile-stuck`) marks the row `failed` so a subsequent retry with the same key is treated as genuinely new and re-executed — never left permanently blocked, never silently replayed as if it had succeeded. |
| 3 | **Server executes successfully but crashes before persisting the idempotency record** | The commit of the business record and the commit of the `idempotency_keys` row happen in the **same database transaction** (Service layer wraps both in one Postgres transaction). Either both commit or neither does — there is no state where the invoice exists but the idempotency record does not, which would otherwise allow a retry to create a second invoice. |
| 4 | **Client omits the header on a required endpoint** | Rejected upfront with `400` before any business logic runs (see middleware `isRequired()` check) — money-moving endpoints never silently proceed without idempotency protection. |
| 5 | **Client sends the header on a naturally-idempotent `GET`** | Header is accepted but ignored; `GET` responses are never cached under the idempotency store (that is a separate HTTP caching/ETag concern). |
| 6 | **Retry arrives after the key's TTL has expired** | Treated as a brand-new request under that key (the expired row, if not yet pruned, is overwritten rather than matched) — this is a deliberate design tradeoff: after 24h/72h, "retry" and "the user genuinely wants to try again" become indistinguishable, and re-execution is the safer default over an indefinite dedupe window that could mask a legitimate second attempt as a phantom replay. |
| 7 | **Key reused across companies by a multi-tenant integration bug** | Impossible to collide by construction — the uniqueness constraint includes `company_id`, and RBAC/company-context resolution runs before idempotency lookup, so a caller without access to Company B's context can never even reach Company B's idempotency record. |
| 8 | **Idempotent request whose *original* execution resulted in a validation error (`422`)** | `422` responses are stored as `status = 'failed'`, not `completed`; a retry with the same key and the same (still-invalid) body re-validates and returns a fresh `422` rather than being treated as a permanent conflict — this lets a client legitimately fix the payload and resend under the same key once the fingerprint check is updated to match the corrected body (a body change here is expected and allowed specifically because the prior attempt never "completed"). |
| 9 | **Webhook delivery races the API response** | A webhook (`invoice.created`) can be delivered and processed by a downstream consumer *before* the original HTTP response reaches the client (e.g. the consumer is faster than the client's own network path). This is expected and safe: the webhook consumer's own `event_id`-based dedupe (see **Interaction With Retries & Webhooks**) is independent of whether the original API caller has seen its response yet. |
| 10 | **Idempotency key reused for a `PATCH` state-transition that is itself business-rule-invalid the second time** (e.g. `journal.post` on an already-posted entry) | If the *first* call already transitioned and completed the state change, a retry with the same key/body replays that success. If a *different* client, without a matching idempotency key, separately calls `post` on an already-posted entry, that is rejected by ordinary business-rule validation (`409`, "entry already posted") — a distinct code path from idempotency conflict, applied regardless of idempotency-key usage. |
| 11 | **Very large request bodies (e.g. a bulk `stock_counts` import) hashed for fingerprinting** | Fingerprinting hashes the canonicalized JSON, not the raw multipart stream; bulk/file-attached endpoints fingerprint the file by content hash plus the JSON metadata, keeping fingerprint computation bounded and fast regardless of payload size. |
| 12 | **Clock skew between application servers affecting `expires_at`** | All TTL arithmetic is done against the database server's `now()` (Postgres) and Redis's own internal clock for its native `EXPIRE`, never against apphost wall-clock time, avoiding skew between horizontally-scaled Laravel workers. |

# End of Document
