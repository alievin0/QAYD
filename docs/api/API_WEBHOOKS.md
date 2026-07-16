# Webhooks — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: Webhooks
---

# Purpose

Webhooks are the asynchronous, push-based counterpart to the QAYD REST API. Where a client calling
`GET /api/v1/accounting/journal-entries` pulls state on demand, a webhook lets a client register once
and receive an HTTP callback the instant a domain event occurs inside QAYD — a customer invoice is
created, a payment is received, a payroll run completes, a bank feed finishes syncing, or the AI engine
finishes a background job. This is the mechanism by which QAYD notifies external systems — a client's
ERP, a Zapier/Make automation, a custom integration built by a partner, or QAYD's own Next.js web client
maintaining a secondary real-time channel — without those systems having to poll the API.

Every domain event inside QAYD's Laravel 12 backend (the single source of truth for all business and
financial logic) is dispatched as a Laravel event after the triggering database transaction commits. A
dedicated `WebhookDispatcher` listener fans that event out to every `webhook_endpoints` row in the
originating company that has subscribed to the event type, and queues one `webhook_deliveries` job per
matching endpoint onto a Redis-backed queue. Delivery, retry, signing, and dead-lettering are handled
entirely by this subsystem; nothing about webhook plumbing lives in domain controllers or services —
they only need to call `event(new InvoiceCreated($invoice))` and the rest is infrastructure.

This document specifies the complete contract for that subsystem: the catalog of events QAYD emits, how
a company registers and manages webhook endpoints through the `/api/v1/webhooks/*` REST surface, the
exact HTTP shape of a delivery (envelope, headers, signature), the retry/backoff and dead-letter policy,
idempotency and ordering guarantees (or the explicit lack thereof), the security posture required of
both QAYD and the receiving endpoint, how to test and replay deliveries, worked payload examples for
every cataloged event, and the edge cases an implementer must handle. It is written to the same
precision bar as the rest of the QAYD API layer: every endpoint uses the standard response envelope,
every path is versioned under `/api/v1/`, every write requires a dotted RBAC permission, and every
example uses real QAYD resources — no placeholders.

Webhooks are strictly a notification layer. They never carry authorization to act — a receiver that
wants to know the current state of a resource after being notified of a change must call back into the
authenticated REST API (`GET /api/v1/sales/invoices/{id}`) rather than trust the webhook payload as the
system of record for anything beyond triggering a re-sync. This matters because delivery is at-least-once
and payloads can arrive late, duplicated, or (rarely) out of order — the webhook tells a receiver
*something happened*; the API tells it *what is true right now*.

# Event Catalog

QAYD emits webhook events for the same domain events that other modules already document as inter-module
signals (see `# Related Modules` in each module spec). The catalog below is authoritative for the
webhook-facing subset. `Resource` is the primary entity in `data.object`; `Trigger` is the exact backend
condition that fires the event.

| Event               | Trigger                                                              | Resource   | Emitting Module |
|---------------------|-----------------------------------------------------------------------|------------|-----------------|
| `invoice.created`   | A sales invoice is created (`invoices.status = draft` or `posted`)   | `invoice`  | Sales           |
| `invoice.paid`      | An invoice's `balance_due` reaches `0.0000` via receipt allocation    | `invoice`  | Sales           |
| `payment.received`  | A `receipts` row is created and allocated to one or more invoices     | `receipt`  | Sales           |
| `journal.posted`    | A `journal_entries` row transitions `draft -> posted`                | `journal_entry` | Accounting |
| `payroll.completed` | A `payroll_runs` row transitions to `completed` (all payslips final)  | `payroll_run` | Payroll      |
| `inventory.updated` | Any `stock_movements` row is committed, changing `inventory_items.qty_on_hand` | `inventory_item` | Inventory |
| `bank.synced`       | A bank feed import finishes and `bank_transactions` rows are inserted | `bank_account` | Banking     |
| `ai.finished`       | An `ai_conversations`/background AI job reaches a terminal state (`completed`/`failed`) | `ai_job` | AI Layer |

Each event's `data.object` schema:

**`invoice.created` / `invoice.paid`**
```json
{
  "id": "uuid",
  "invoice_number": "string",
  "customer_id": "uuid",
  "status": "draft | posted | paid | partially_paid | void",
  "currency_code": "ISO 4217",
  "exchange_rate": "numeric(18,6)",
  "total_amount": "numeric(19,4)",
  "balance_due": "numeric(19,4)",
  "due_date": "date",
  "company_id": "uuid",
  "created_at": "ISO 8601",
  "updated_at": "ISO 8601"
}
```

**`payment.received`**
```json
{
  "id": "uuid",
  "receipt_number": "string",
  "customer_id": "uuid",
  "amount": "numeric(19,4)",
  "currency_code": "ISO 4217",
  "method": "cash | bank_transfer | card | knet | cheque",
  "allocations": [
    { "invoice_id": "uuid", "amount_allocated": "numeric(19,4)" }
  ],
  "received_at": "ISO 8601"
}
```

**`journal.posted`**
```json
{
  "id": "uuid",
  "journal_number": "string",
  "fiscal_period_id": "uuid",
  "status": "posted",
  "total_debit": "numeric(19,4)",
  "total_credit": "numeric(19,4)",
  "lines_count": "integer",
  "posted_by": "uuid",
  "posted_at": "ISO 8601"
}
```

**`payroll.completed`**
```json
{
  "id": "uuid",
  "run_number": "string",
  "period_start": "date",
  "period_end": "date",
  "employee_count": "integer",
  "total_gross": "numeric(19,4)",
  "total_net": "numeric(19,4)",
  "status": "completed",
  "completed_at": "ISO 8601"
}
```

**`inventory.updated`**
```json
{
  "id": "uuid",
  "product_id": "uuid",
  "warehouse_id": "uuid",
  "qty_on_hand": "numeric(18,4)",
  "qty_reserved": "numeric(18,4)",
  "movement_type": "receipt | issue | transfer | adjustment | count",
  "movement_id": "uuid",
  "updated_at": "ISO 8601"
}
```

**`bank.synced`**
```json
{
  "id": "uuid",
  "bank_account_id": "uuid",
  "statement_lines_imported": "integer",
  "date_range": { "from": "date", "to": "date" },
  "unmatched_count": "integer",
  "synced_at": "ISO 8601"
}
```

**`ai.finished`**
```json
{
  "id": "uuid",
  "agent": "General Accountant | Auditor | Tax Advisor | Payroll Manager | Inventory Manager | Treasury Manager | CFO | Fraud Detection | Reporting Agent | Document AI | OCR Agent | Forecast Agent | Compliance Agent | Approval Assistant",
  "job_type": "string",
  "status": "completed | failed",
  "confidence_score": "numeric(5,4)",
  "reasoning_summary": "string",
  "output_ref": { "type": "string", "id": "uuid" },
  "finished_at": "ISO 8601"
}
```

# Subscription Management

Webhook endpoints are managed exclusively through the authenticated REST API — there is no
unauthenticated registration path. All routes below sit under `/api/v1/webhooks/` and require
`X-Company-Id` and a valid Sanctum/JWT bearer token, scoped by RBAC permission.

| Method | Path                                     | Permission               | Description                                   |
|--------|-------------------------------------------|---------------------------|------------------------------------------------|
| GET    | `/api/v1/webhooks/events`                 | `webhooks.read`          | List all subscribable event types + schemas   |
| POST   | `/api/v1/webhooks/endpoints`              | `webhooks.create`        | Register a new webhook endpoint                |
| GET    | `/api/v1/webhooks/endpoints`               | `webhooks.read`          | List the company's webhook endpoints           |
| GET    | `/api/v1/webhooks/endpoints/{id}`          | `webhooks.read`          | Get one endpoint (secret is write-once, masked)|
| PATCH  | `/api/v1/webhooks/endpoints/{id}`          | `webhooks.update`        | Update URL, description, subscribed events     |
| DELETE | `/api/v1/webhooks/endpoints/{id}`          | `webhooks.delete`        | Delete (soft) an endpoint                       |
| POST   | `/api/v1/webhooks/endpoints/{id}/disable`  | `webhooks.update`        | Pause deliveries without deleting               |
| POST   | `/api/v1/webhooks/endpoints/{id}/enable`   | `webhooks.update`        | Resume a paused endpoint                        |
| POST   | `/api/v1/webhooks/endpoints/{id}/rotate-secret` | `webhooks.manage`   | Issue a new signing secret                      |
| POST   | `/api/v1/webhooks/endpoints/{id}/test`     | `webhooks.update`        | Send a synthetic test event                     |
| GET    | `/api/v1/webhooks/deliveries`              | `webhooks.read`          | List deliveries (filter by endpoint/event/status)|
| GET    | `/api/v1/webhooks/deliveries/{id}`         | `webhooks.read`          | Get one delivery, including attempts and response|
| POST   | `/api/v1/webhooks/deliveries/{id}/replay`  | `webhooks.manage`        | Re-send a specific delivery                     |

Underlying storage:

```sql
CREATE TYPE webhook_endpoint_status AS ENUM ('active', 'disabled', 'revoked');

CREATE TABLE webhook_endpoints (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    url           TEXT NOT NULL,
    description   TEXT NULL,
    events        JSONB NOT NULL DEFAULT '[]',   -- e.g. ["invoice.created","payment.received"]
    secret_hash   TEXT NOT NULL,                  -- bcrypt/sha256 hash; plaintext shown once on create
    status        webhook_endpoint_status NOT NULL DEFAULT 'active',
    api_version   VARCHAR(10) NOT NULL DEFAULT 'v1',
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL,
    CONSTRAINT chk_webhook_url_https CHECK (url LIKE 'https://%')
);
CREATE INDEX idx_webhook_endpoints_company ON webhook_endpoints (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_webhook_endpoints_events ON webhook_endpoints USING GIN (events);
```

Register an endpoint:

```
POST /api/v1/webhooks/endpoints
X-Company-Id: 4471
Authorization: Bearer eyJhbGciOi...
Content-Type: application/json

{
  "url": "https://erp.acme-kw.com/hooks/qayd",
  "description": "Acme ERP sync",
  "events": ["invoice.created", "invoice.paid", "payment.received"]
}
```

Response (`201 Created`) — the plaintext `secret` is returned exactly once and is never retrievable
again; only a masked reference (`whsec_****last4`) is shown on subsequent `GET` calls:

```json
{
  "success": true,
  "data": {
    "id": "wh_01J9Z3K7Q2R8",
    "url": "https://erp.acme-kw.com/hooks/qayd",
    "description": "Acme ERP sync",
    "events": ["invoice.created", "invoice.paid", "payment.received"],
    "status": "active",
    "secret": "whsec_8f3b6a1c9e7d4a2b5c8f1e6d3a9b7c4e",
    "api_version": "v1",
    "created_at": "2026-07-16T09:12:03Z"
  },
  "message": "Webhook endpoint created. Store the secret now — it will not be shown again.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b3f2c1a0-4d5e-4f6a-8b7c-9d0e1f2a3b4c",
  "timestamp": "2026-07-16T09:12:03Z"
}
```

`PATCH` to change subscribed events only requires sending the new `events` array; `url` and
`description` are optional partial updates. Removing all events from `events` is rejected with `422`
(`errors: [{ "field": "events", "code": "min_events", "message": "At least one event is required." }]`)
— disable the endpoint instead if the intent is to stop all delivery.

# Delivery

Every delivery is a single `POST` request from a QAYD-owned egress worker to the endpoint's `url`. The
request body is the **delivery envelope** — distinct from the API's response envelope, since this is a
push notification, not a pull response:

```json
{
  "id": "evt_01J9ZA6XQK3M8P",
  "type": "invoice.created",
  "api_version": "v1",
  "company_id": "4471",
  "created_at": "2026-07-16T09:12:04Z",
  "data": {
    "object": { "...": "the resource payload from the Event Catalog" }
  }
}
```

| Field         | Type   | Description                                                              |
|---------------|--------|----------------------------------------------------------------------------|
| `id`          | string | Globally unique event id (`evt_` prefix, ULID). Use for idempotency.       |
| `type`        | string | One of the cataloged event names.                                          |
| `api_version` | string | Contract version the payload shape conforms to (`v1`).                     |
| `company_id`  | string | The company the event belongs to (matches the receiving endpoint's owner). |
| `created_at`  | string | ISO 8601 UTC timestamp of when the domain event occurred (not send time).  |
| `data.object` | object | The resource, per the Event Catalog schema for `type`.                     |

Request headers:

| Header                 | Example                                              | Purpose                                  |
|-------------------------|-------------------------------------------------------|-------------------------------------------|
| `Content-Type`          | `application/json`                                   | Body encoding                             |
| `User-Agent`            | `QAYD-Webhooks/1.0`                                  | Identifies QAYD as sender                 |
| `X-Qayd-Event`          | `invoice.created`                                     | Mirrors `type`, for header-based routing  |
| `X-Qayd-Delivery-Id`    | `del_01J9ZA6Y1N4R7T`                                  | Unique per delivery attempt (not per event)|
| `X-Qayd-Event-Id`       | `evt_01J9ZA6XQK3M8P`                                  | Mirrors `id`, stable across retry attempts|
| `X-Qayd-Timestamp`      | `1752656  ` *(unix seconds)*                          | Used in signature computation             |
| `X-Qayd-Signature`      | `t=1752656 ,v1=5257a869...`                            | HMAC-SHA256 signature — see next section  |

Delivery sequence:

```
Domain action commits (e.g. InvoiceController::store)
        |
        v
event(new InvoiceCreated($invoice))              [Laravel event, in-transaction]
        |
        v
WebhookDispatcher::handle()                       [queued listener, after commit]
        |
        v
Match webhook_endpoints WHERE company_id = X
      AND status = 'active'
      AND events @> '["invoice.created"]'
        |
        v
For each match: enqueue WebhookDeliveryJob        [Redis queue "webhooks", per-endpoint FIFO]
        |
        v
Worker: sign payload, POST with 10s connect / 15s total timeout
        |
   2xx  |  non-2xx / timeout / DNS / TLS failure
        v                    v
  mark delivered      schedule retry (see Retries & Backoff)
```

A receiving endpoint must respond with any `2xx` status within 15 seconds to be considered successful.
The response body is ignored; only the status code and latency are recorded on the delivery record. A
receiver should acknowledge immediately and process asynchronously if handling takes longer than a few
seconds — synchronous heavy processing inside the webhook handler is the single most common cause of
receiver-side timeouts.

# Signing & Verification

Every delivery is signed with HMAC-SHA256 using the endpoint's unique secret (`whsec_...`, returned once
at creation, rotatable via `POST /api/v1/webhooks/endpoints/{id}/rotate-secret`). The signed string is
the timestamp and raw request body joined by a period, matching Stripe's proven scheme to keep
implementation familiar to integrators:

```
signed_payload = "{timestamp}.{raw_request_body}"
signature      = hex(HMAC_SHA256(key = endpoint_secret, message = signed_payload))
header         = "t={timestamp},v1={signature}"
```

`X-Qayd-Signature` carries the header value. Verification must:
1. Extract `t` and `v1` from the header.
2. Reject if `abs(now() - t) > 300` seconds (5-minute replay tolerance).
3. Recompute the HMAC over `"{t}.{raw_body}"` using the stored secret, on the **raw, unparsed** body
   bytes (re-serializing JSON before verifying is a common bug that breaks signatures on whitespace or
   key-order differences).
4. Compare using a constant-time comparison, never `==`/`string ===`.
5. Reject the request (`401`, and let QAYD retry) if any check fails.

**PHP (Laravel middleware) verification:**
```php
final class VerifyQaydWebhookSignature
{
    public function handle(Request $request, Closure $next): mixed
    {
        $header = $request->header('X-Qayd-Signature', '');
        $secret = config('services.qayd.webhook_secret');

        [$t, $v1] = $this->parseHeader($header);

        if (abs(time() - (int) $t) > 300) {
            abort(401, 'Webhook timestamp outside tolerance.');
        }

        $expected = hash_hmac('sha256', "{$t}.{$request->getContent()}", $secret);

        if (! hash_equals($expected, $v1)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }

    private function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $parts[$k] = $v;
        }
        return [$parts['t'] ?? '0', $parts['v1'] ?? ''];
    }
}
```

**JavaScript / Node verification:**
```javascript
const crypto = require('crypto');

function verifyQaydSignature(rawBody, signatureHeader, secret, toleranceSeconds = 300) {
  const parts = Object.fromEntries(
    signatureHeader.split(',').map(p => p.split('='))
  );
  const t = parseInt(parts.t, 10);
  const v1 = parts.v1;

  if (Math.abs(Math.floor(Date.now() / 1000) - t) > toleranceSeconds) {
    throw new Error('Webhook timestamp outside tolerance');
  }

  const expected = crypto
    .createHmac('sha256', secret)
    .update(`${t}.${rawBody}`, 'utf8')
    .digest('hex');

  const isValid = crypto.timingSafeEqual(
    Buffer.from(expected, 'utf8'),
    Buffer.from(v1, 'utf8')
  );
  if (!isValid) throw new Error('Invalid webhook signature');
  return true;
}
```

Receivers on a framework that pre-parses JSON (Express `body-parser`, for example) must capture the raw
body via a `verify` callback or a raw-body middleware mounted before JSON parsing — verifying against a
re-stringified object will intermittently fail and is explicitly unsupported.

# Retries & Backoff

Delivery failure is any of: connection refused, DNS failure, TLS handshake failure, timeout (no response
within 15s total), or a non-2xx HTTP status. QAYD retries with exponential backoff and jitter, per
endpoint per event, up to 16 attempts over roughly 24 hours:

| Attempt | Delay before this attempt | Cumulative elapsed |
|---------|----------------------------|----------------------|
| 1       | 0s (immediate)             | 0s                   |
| 2       | 30s                        | 30s                  |
| 3       | 2 min                      | ~2.5 min             |
| 4       | 10 min                     | ~13 min              |
| 5       | 30 min                     | ~43 min              |
| 6       | 1 hr                       | ~1.7 hr              |
| 7       | 2 hr                       | ~3.7 hr              |
| 8       | 4 hr                       | ~7.7 hr              |
| 9–16    | 6 hr (fixed) each           | up to ~24 hr total   |

±10% random jitter is applied to every delay to avoid thundering-herd retries against a recovering
receiver. `429` responses from the receiver are honored specially: if a `Retry-After` header is present,
QAYD waits at least that long before the next attempt regardless of the schedule above. After the final
(16th) attempt fails, the delivery is marked `dead_lettered` (see next section) and no further attempts
are made for that specific event/endpoint pair. Retrying one event does not block delivery of other
events to the same endpoint — each event has its own retry timeline, dispatched onto the endpoint's FIFO
queue independently.

# Dead Letter / Failure Handling

```sql
CREATE TYPE webhook_delivery_status AS ENUM
    ('pending', 'delivered', 'failed', 'retrying', 'dead_lettered');

CREATE TABLE webhook_deliveries (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    webhook_endpoint_id BIGINT NOT NULL REFERENCES webhook_endpoints(id),
    event_id         VARCHAR(40) NOT NULL,          -- evt_... , stable across retries
    event_type       VARCHAR(60) NOT NULL,
    payload          JSONB NOT NULL,
    status           webhook_delivery_status NOT NULL DEFAULT 'pending',
    attempt_count    SMALLINT NOT NULL DEFAULT 0,
    last_attempt_at  TIMESTAMPTZ NULL,
    next_attempt_at  TIMESTAMPTZ NULL,
    response_status  SMALLINT NULL,
    response_latency_ms INTEGER NULL,
    response_snippet TEXT NULL,                     -- first 500 bytes of receiver response, for debugging
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_webhook_deliveries_endpoint ON webhook_deliveries (webhook_endpoint_id, created_at DESC);
CREATE INDEX idx_webhook_deliveries_event ON webhook_deliveries (event_id);
CREATE INDEX idx_webhook_deliveries_status ON webhook_deliveries (status) WHERE status IN ('pending','retrying');
```

When a delivery is `dead_lettered`:
1. A `notifications` row is created for the company's Owner/CFO/Finance Manager roles: "Webhook to
   `{url}` has failed 16 times for event `{event_type}` and will not retry automatically."
2. If an endpoint accumulates 3 consecutive fully-dead-lettered events (i.e. its last 3 distinct event
   deliveries each exhausted all 16 attempts), the endpoint's `status` is automatically flipped to
   `disabled` and the company admin is notified separately — this protects QAYD's egress workers from
   burning capacity on an endpoint that is clearly gone (domain expired, server decommissioned).
3. Dead-lettered deliveries remain queryable via `GET /api/v1/webhooks/deliveries?status=dead_lettered`
   indefinitely (subject to the platform's general data-retention policy) and are individually replayable
   via `POST /api/v1/webhooks/deliveries/{id}/replay`, which resets `attempt_count` to 0 and re-enters the
   full retry schedule from attempt 1 — it does not bypass signing or count against the original 16.

# Idempotency

Delivery is **at-least-once**, never exactly-once. A receiver will observe duplicates under normal
operation — e.g. QAYD's worker receives a slow `2xx` after already having timed out and scheduled a
retry, or a receiver's load balancer returns a `200` from a duplicate upstream after the original request
already succeeded server-side. The delivery envelope's `id` (`evt_...`) is stable across every retry
attempt of the same underlying event — it is the idempotency key, not `X-Qayd-Delivery-Id` (which
changes per attempt and identifies the HTTP request, not the event).

Recommended receiver-side pattern:

```sql
-- receiver-owned table, not part of QAYD's schema
CREATE TABLE processed_webhook_events (
    event_id    VARCHAR(40) PRIMARY KEY,
    processed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

```php
if (ProcessedWebhookEvent::where('event_id', $payload['id'])->exists()) {
    return response()->json(['status' => 'duplicate_ignored'], 200); // still ack with 2xx
}
DB::transaction(function () use ($payload) {
    ProcessedWebhookEvent::create(['event_id' => $payload['id']]);
    // handle event...
});
```

Insert the dedupe record and the business effect in the same transaction so a crash between them cannot
silently drop an event. QAYD retains delivered event ids for 30 days server-side for its own duplicate
suppression within a single retry chain, but that window does not substitute for receiver-side dedupe,
since a receiver may reasonably retain state far longer than 30 days.

# Ordering

QAYD does **not** guarantee cross-event ordering at the receiver. Two events for the same resource can
arrive out of order because: they are dispatched onto independent retry timers once a failure occurs, the
receiver's own infrastructure (load balancers, serverless cold starts) can reorder concurrent inbound
requests, and network-level retransmission can duplicate/delay individual HTTP requests independently.

Within a single endpoint, QAYD makes a best-effort ordering guarantee only for the **first attempt** of
events belonging to the same `event_type` and resource id: they are enqueued onto a per-endpoint FIFO
Redis list and dispatched by a single consumer per endpoint, so first attempts leave QAYD in creation
order. That guarantee evaporates the moment any event in the sequence needs a retry, since the retried
event is re-queued behind newer, unrelated first-attempts.

Because of this, a payload's `updated_at` (or, for `journal.posted`, `posted_at`) must be used by the
receiver to resolve conflicts — always keep the event with the newest timestamp per resource id, and
never assume "the second webhook I received is the newer state." Receivers requiring strict ordering
guarantees should treat each webhook purely as a "something changed, go re-fetch" signal and call
`GET /api/v1/{resource}/{id}` to read current, authoritative state rather than trusting payload ordering.

# Security

- **Transport**: `https://` is enforced at registration time (`chk_webhook_url_https`); QAYD refuses to
  create or update an endpoint with a plaintext `http://` URL, and TLS 1.2+ is required — handshakes to
  endpoints offering only TLS 1.0/1.1 or self-signed/expired certificates fail closed (marked as a
  delivery failure, entering the standard retry schedule, not a security bypass).
- **Secrets**: the signing secret is generated with a CSPRNG (32 bytes, hex-encoded, `whsec_` prefixed),
  shown once at creation, and stored server-side only as a salted hash reference sufficient to re-derive
  signing material — never in reversible plaintext at rest. `POST /endpoints/{id}/rotate-secret` issues a
  new secret and returns it once; the old secret remains valid for 24 hours to allow a zero-downtime
  rollover on the receiver's side, then is permanently invalidated.
- **Permission gating**: all `/api/v1/webhooks/*` mutations require `webhooks.create` / `webhooks.update`
  / `webhooks.delete` / `webhooks.manage`, which by default are granted only to Owner, CEO, CFO, and
  Finance Manager — webhook endpoints are a data-exfiltration vector (any financial event payload leaves
  the tenant boundary to a URL of the configurer's choosing) and are treated with the same sensitivity as
  bank/payroll permissions, not general `accounting.read`-level access.
- **Replay protection**: the 5-minute timestamp tolerance in `X-Qayd-Signature` (see Signing &
  Verification) prevents a captured request from being replayed indefinitely even if TLS is somehow
  compromised in transit.
- **IP allowlisting**: QAYD publishes a stable, versioned set of egress CIDR blocks at
  `GET /api/v1/webhooks/egress-ips` (also mirrored in the developer portal) so receivers behind a
  restrictive firewall or WAF can allowlist QAYD's senders rather than accepting webhook traffic from
  arbitrary IPs; this list changes rarely and any change is announced 30 days in advance via the
  `platform.changelog` events feed.
- **No inbound secrets in the payload**: delivery payloads never contain bearer tokens, passwords, full
  bank account numbers, or card PANs — sensitive numeric identifiers (bank account, national ID) are
  omitted or masked in every event schema in the Event Catalog. A receiver needing full sensitive detail
  must call back into the authenticated REST API, which applies the normal RBAC/field-level redaction
  rules for the calling user.
- **Company isolation**: `webhook_deliveries.company_id` is enforced identically to every other tenant
  table — an endpoint can only ever receive events for the company that registered it; there is no
  cross-tenant subscription mechanism, by design.

# Testing

- **Test events**: `POST /api/v1/webhooks/endpoints/{id}/test` sends a single synthetic delivery for a
  caller-chosen event type from the endpoint's subscribed list, using realistic but clearly-synthetic
  data (`invoice_number: "TEST-0001"`, a valid signature computed with the real endpoint secret so
  signature-verification code can be exercised end-to-end). Test deliveries are recorded in
  `webhook_deliveries` with `event_id` prefixed `evt_test_` so receivers and QAYD's own dashboards can
  filter them out of production event handling if desired.
  ```
  POST /api/v1/webhooks/endpoints/wh_01J9Z3K7Q2R8/test
  X-Company-Id: 4471
  Authorization: Bearer eyJhbGciOi...
  Content-Type: application/json

  { "event": "invoice.created" }
  ```
  ```json
  {
    "success": true,
    "data": { "delivery_id": "del_test_9Q2R8K7Z3J1", "status": "pending" },
    "message": "Test event queued for delivery.",
    "errors": [],
    "meta": { "pagination": null },
    "request_id": "1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
    "timestamp": "2026-07-16T09:20:11Z"
  }
  ```
- **Replay**: `POST /api/v1/webhooks/deliveries/{id}/replay` re-sends the exact original payload (not a
  regenerated one) with a freshly computed signature/timestamp, useful after fixing a receiver bug so a
  real historical event (e.g. an actual `invoice.paid` from last week) can be safely reprocessed once the
  receiver's idempotency table confirms it was never successfully processed the first time.
- **Sandbox company**: every QAYD account includes one company flagged `is_sandbox = true` in which all
  writes are fully functional but ledger-isolated from production reporting; webhook endpoints registered
  under a sandbox company deliver real-shaped events triggered by real sandbox actions (create a sandbox
  invoice, receive a real `invoice.created` webhook) without touching production financial data —
  the recommended integration-testing path over synthetic `/test` calls alone.
- **Delivery inspector**: `GET /api/v1/webhooks/deliveries/{id}` returns the full attempt history
  (`attempt_count`, each `response_status`, `response_latency_ms`, `response_snippet`) so a developer can
  diagnose exactly why a receiver rejected a delivery without needing server-side log access on their own
  end.

# Examples

**`invoice.created` delivery:**
```
POST /hooks/qayd HTTP/1.1
Host: erp.acme-kw.com
Content-Type: application/json
User-Agent: QAYD-Webhooks/1.0
X-Qayd-Event: invoice.created
X-Qayd-Event-Id: evt_01J9ZA6XQK3M8P
X-Qayd-Delivery-Id: del_01J9ZA6Y1N4R7T
X-Qayd-Timestamp: 1752656 24
X-Qayd-Signature: t=1752656 24,v1=5257a869e7c8e1f0a3b6d9c4e2f18a7b5c3d9e6f1a2b4c8d7e9f0a1b2c3d4e5f

{
  "id": "evt_01J9ZA6XQK3M8P",
  "type": "invoice.created",
  "api_version": "v1",
  "company_id": "4471",
  "created_at": "2026-07-16T09:12:04Z",
  "data": {
    "object": {
      "id": "inv_8Q1J9Z3K7Q2R",
      "invoice_number": "INV-2026-00841",
      "customer_id": "cus_4M7N2P9Q1R3S",
      "status": "posted",
      "currency_code": "KWD",
      "exchange_rate": "1.000000",
      "total_amount": "1250.0000",
      "balance_due": "1250.0000",
      "due_date": "2026-08-15",
      "company_id": "4471",
      "created_at": "2026-07-16T09:12:03Z",
      "updated_at": "2026-07-16T09:12:03Z"
    }
  }
}
```

**`payment.received` delivery body:**
```json
{
  "id": "evt_01J9ZB2H4T6W9X",
  "type": "payment.received",
  "api_version": "v1",
  "company_id": "4471",
  "created_at": "2026-07-16T11:05:30Z",
  "data": {
    "object": {
      "id": "rcpt_3K7Q2R8N4M9P",
      "receipt_number": "RCPT-2026-00512",
      "customer_id": "cus_4M7N2P9Q1R3S",
      "amount": "1250.0000",
      "currency_code": "KWD",
      "method": "knet",
      "allocations": [
        { "invoice_id": "inv_8Q1J9Z3K7Q2R", "amount_allocated": "1250.0000" }
      ],
      "received_at": "2026-07-16T11:05:29Z"
    }
  }
}
```

**`ai.finished` delivery body (Fraud Detection agent):**
```json
{
  "id": "evt_01J9ZC5Y8B3D6E",
  "type": "ai.finished",
  "api_version": "v1",
  "company_id": "4471",
  "created_at": "2026-07-16T12:30:00Z",
  "data": {
    "object": {
      "id": "aijob_9X2Y7Z4W1V8U",
      "agent": "Fraud Detection",
      "job_type": "vendor_payment_anomaly_scan",
      "status": "completed",
      "confidence_score": "0.9120",
      "reasoning_summary": "Vendor bank account changed 2 days before a KWD 42,000 payment; no prior payments to this account.",
      "output_ref": { "type": "approval_request", "id": "appr_5T8U1V4W7X2Y" },
      "finished_at": "2026-07-16T12:29:58Z"
    }
  }
}
```

**`bank.synced` delivery body:**
```json
{
  "id": "evt_01J9ZD1F3G5H7J",
  "type": "bank.synced",
  "api_version": "v1",
  "company_id": "4471",
  "created_at": "2026-07-16T06:00:12Z",
  "data": {
    "object": {
      "id": "bank_7H4J1K8L2M5N",
      "bank_account_id": "bank_7H4J1K8L2M5N",
      "statement_lines_imported": 37,
      "date_range": { "from": "2026-07-14", "to": "2026-07-16" },
      "unmatched_count": 4,
      "synced_at": "2026-07-16T06:00:10Z"
    }
  }
}
```

**cURL to register + test an endpoint end-to-end:**
```bash
curl -X POST https://api.qayd.app/api/v1/webhooks/endpoints \
  -H "Authorization: Bearer $QAYD_TOKEN" \
  -H "X-Company-Id: 4471" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://erp.acme-kw.com/hooks/qayd","events":["invoice.created","payment.received"]}'

curl -X POST https://api.qayd.app/api/v1/webhooks/endpoints/wh_01J9Z3K7Q2R8/test \
  -H "Authorization: Bearer $QAYD_TOKEN" \
  -H "X-Company-Id: 4471" \
  -H "Content-Type: application/json" \
  -d '{"event":"invoice.created"}'
```

# Edge Cases

- **Endpoint URL becomes unreachable mid-schedule**: the retry schedule continues unmodified through DNS
  failures, connection refusals, and timeouts identically — QAYD does not distinguish "endpoint is down"
  from "endpoint is slow" until the schedule exhausts and the delivery dead-letters.
- **Secret rotated while retries are in flight**: attempts already scheduled before rotation continue
  to sign with the previous secret for the 24-hour dual-validity window described in Security; any new
  event created after rotation signs with the new secret immediately. A receiver mid-rotation must accept
  either secret during that window.
- **Receiver returns `200` but processing later fails on their side**: this is invisible to QAYD by
  design — a `2xx` response is a delivery acknowledgment, not a processing guarantee. Receivers should not
  send `2xx` until the event is durably queued or persisted on their side; sending `200` speculatively
  and failing to process afterward is a receiver-side bug that QAYD cannot detect or retry for.
- **Duplicate `2xx` after a timeout**: if the receiver actually processed a request that QAYD's worker
  timed out waiting on, the retry-triggered redelivery is a legitimate duplicate — this is exactly what
  the Idempotency section's `event_id` dedupe key exists to absorb; it is expected behavior, not a bug.
- **Payload exceeds size limits**: no cataloged event's `data.object` is expected to exceed a few KB;
  QAYD nonetheless caps delivery payload size at 256 KB. Should a future event type risk exceeding it
  (e.g. a bulk `inventory.updated` batch), the payload is truncated to a reference (`{"id":..., "truncated":
  true, "fetch_url": "/api/v1/inventory/items/{id}"}`) rather than silently growing past the cap.
- **Company is deleted or churns while endpoints are still active**: on company deletion, all
  `webhook_endpoints` for that `company_id` are immediately set to `status = 'revoked'` in the same
  transaction; no further events are ever enqueued for a revoked endpoint, and any deliveries already
  queued in Redis for that company at the moment of deletion are drained without dispatch.
- **Sandbox vs. production event bleed**: an endpoint registered under a sandbox company never receives
  production events and vice versa — `company_id` scoping on `webhook_endpoints` makes this structurally
  impossible, not merely policy-enforced.
- **Clock skew between QAYD and receiver**: the 5-minute signature tolerance assumes both sides are
  within a few minutes of true UTC (NTP-synced). A receiver whose clock has drifted by more than ~4
  minutes will begin rejecting otherwise-valid signatures; this is a receiver-side operational issue, not
  a QAYD signing bug, and is the first thing to check when "valid" webhooks start failing verification.
- **Self-referential loops**: a receiver's webhook handler that itself triggers a QAYD API write (e.g.
  auto-creating a `receipts` row on `invoice.paid`) must guard against re-triggering the same event chain
  indefinitely; QAYD does not detect or break such loops server-side, since from QAYD's perspective each
  resulting write is simply another authenticated API call with no knowledge of its origin.
- **Endpoint disabled mid-retry-schedule**: disabling an endpoint (`POST .../disable`) immediately halts
  all *future* scheduled attempts for every in-flight delivery to that endpoint, but does not retroactively
  mark already-completed attempts as anything other than what actually happened; disabled deliveries that
  had not yet exhausted their schedule are marked `failed` (not `dead_lettered`) so re-enabling the
  endpoint can be followed by a deliberate bulk replay if desired.
- **Event schema evolution**: `api_version` in the envelope lets QAYD add new optional fields to an
  existing event's `data.object` without a breaking change; receivers must ignore unknown fields rather
  than fail-closed on them. A genuinely breaking change (field removal, type change) ships as a new
  `api_version` and a new/renamed event, with the old version continuing to deliver unchanged for a
  published deprecation window — never a silent in-place breaking mutation of a live event contract.

# End of Document
