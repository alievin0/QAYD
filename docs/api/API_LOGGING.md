# API Logging & Observability — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API_LOGGING
---

# Purpose

QAYD is an AI Financial Operating System: every screen a customer touches in the Next.js web app or
Flutter mobile app is a thin client over one authority — the Laravel 12 API. Every automated decision
made by the FastAPI AI layer (the General Accountant, Auditor, Tax Advisor, Payroll Manager, Treasury
Manager, and the other agents) is only real once it has been validated, authorized, and persisted through
that same API. Because the API is the single choke point for money movement, payroll release, tax
submission, and the general ledger, the logging layer wrapped around it is not an operational nicety — it
is the primary forensic record that lets QAYD, its customers, and their external auditors answer, with
certainty, "who did this, when, from where, on whose authority, and was the AI involved."

This document specifies that logging layer end-to-end: the structured JSON log format written by every
service (Laravel, FastAPI, Redis-backed queue workers, Reverb websocket broadcasters, webhook dispatch
workers); how a single inbound request is stamped with a `request_id` and how a whole business workflow —
which may fan out across several requests, a queued job, an AI engine call, and a webhook delivery — is
stitched together with a `correlation_id`; how salary figures, IBAN/bank account numbers, card data,
tokens, and other regulated fields are masked before a single byte reaches a log store; how long each log
category is retained and where it lives; how logs are searched, both by internal engineers (Loki/Grafana,
Elasticsearch/Kibana) and by tenants themselves through a first-class `/api/v1` endpoint backed by the
immutable `audit_logs` table; which compliance frameworks the design satisfies and how; how log volume is
kept sane through level thresholds and deterministic sampling; and how logs are shipped off the API boxes
to durable, queryable storage.

QAYD maintains two deliberately distinct logging planes, and confusing them is the single most common
design mistake this document exists to prevent:

1. **Structured operational logs** — high-volume, technical, written for every request (reads and writes
   alike), shipped to Loki and/or Elasticsearch, retained on a cost/utility curve (days to months), used
   for debugging, SRE dashboards, alerting, and security monitoring. These logs are ephemeral by design.
2. **The `audit_logs` table** — low-volume relative to total traffic (mutations only), written inside the
   same database transaction as the business change it describes, stored in PostgreSQL next to the data
   it audits, retained for years to satisfy bookkeeping law, queryable by tenants through the API, and
   treated as tamper-evident evidence, not telemetry.

Both planes share the same `request_id` and `correlation_id` values for any given event, which is what
lets an engineer pivot from a Grafana panel showing an error spike straight to the exact `audit_logs` row
a customer is disputing, and back again. The rest of this document defines exactly how that works.

# Structured Logging

## Format: JSON, always

Every process that is part of the QAYD request path — the Laravel `octane`/`fpm` workers, the Laravel
queue workers (`horizon`), the FastAPI AI engine (Uvicorn workers), and the webhook-dispatch worker — emits
one JSON object per line to `stdout`. Nothing is ever logged as free-form text in any environment above
`local`. JSON-per-line (NDJSON) is required because the shipping pipeline (see `# Shipping (Loki/ELK)`)
parses structure at the edge rather than trying to regex it back out downstream, and because every field
below must be independently indexable/searchable.

## Canonical field schema

Every structured log line carries this baseline envelope. Service-specific fields are added under
`context`; the top-level fields never change shape across services so that a single Kibana/Grafana view
can mix Laravel, FastAPI, and worker logs in one timeline.

| Field | Type | Description |
|---|---|---|
| `timestamp` | string (ISO-8601, UTC, ms) | Emission time, e.g. `2026-07-16T12:00:00.482Z` |
| `level` | string | One of `debug, info, notice, warning, error, critical, alert, emergency` (PSR-3) |
| `message` | string | Human-readable summary, stable per event type (used for de-duplication/alert grouping) |
| `event` | string | Machine-stable event key, e.g. `api.request.completed`, `ai.agent.invoked`, `webhook.delivery.failed` |
| `service` | string | `laravel-api`, `ai-engine`, `queue-worker`, `webhook-dispatcher`, `reverb` |
| `environment` | string | `local`, `staging`, `production` |
| `company_id` | integer\|null | Active tenant; `null` only for pre-auth/platform-level events |
| `branch_id` | integer\|null | Active branch, if scoped |
| `user_id` | integer\|null | Authenticated actor; `null` for AI-originated or system events |
| `actor_type` | string | `user`, `ai_agent`, `system`, `webhook_consumer` |
| `request_id` | string (UUIDv7) | See `# Request IDs` |
| `correlation_id` | string (UUIDv7) | See `# Correlation IDs` |
| `method` | string\|null | HTTP method, when applicable |
| `path` | string\|null | Route path, e.g. `/api/v1/accounting/journal-entries` |
| `permission` | string\|null | Permission key checked/enforced for this event, e.g. `accounting.journal.post` |
| `status_code` | integer\|null | HTTP status, when applicable |
| `duration_ms` | number\|null | Wall-clock duration of the unit of work |
| `ip_hash` | string\|null | SHA-256 of client IP + daily salt (see `# Sensitive Data`) |
| `context` | object | Event-specific structured payload (already masked — see `# Sensitive Data`) |
| `tags` | array\<string\> | Free-form facets, e.g. `["security.event"]`, `["sampled"]` |

## Laravel logging channel configuration

`config/logging.php` defines a dedicated `structured` channel used by the whole application in every
non-local environment; the default `stack` channel routes to it.

```php
// config/logging.php
'channels' => [
    'structured' => [
        'driver' => 'monolog',
        'level' => env('LOG_LEVEL', 'info'),
        'handler' => Monolog\Handler\StreamHandler::class,
        'with' => ['stream' => 'php://stdout'],
        'formatter' => Monolog\Formatter\JsonFormatter::class,
        'formatter_with' => ['appendNewline' => true],
        'processors' => [
            App\Logging\Processors\RequestContextProcessor::class,
            App\Logging\Processors\SensitiveDataProcessor::class,
            App\Logging\Processors\SamplingProcessor::class,
        ],
    ],
],
```

`RequestContextProcessor` reads the values `AssignRequestId` and `AssignCorrelationId` middleware placed on
the request (see next section) and merges `request_id`, `correlation_id`, `company_id`, `branch_id`,
`user_id`, `method`, `path` into every record automatically, so application code never has to remember to
pass them:

```php
final class RequestContextProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $ctx = app(RequestContext::class); // request-scoped singleton

        return $record->with(extra: array_merge($record->extra, [
            'request_id'     => $ctx->requestId,
            'correlation_id' => $ctx->correlationId,
            'company_id'     => $ctx->companyId,
            'branch_id'      => $ctx->branchId,
            'user_id'        => $ctx->userId,
            'actor_type'     => $ctx->actorType,
            'environment'    => app()->environment(),
            'service'        => 'laravel-api',
        ]));
    }
}
```

## Middleware that emits the request lifecycle logs

```php
final class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        Log::channel('structured')->info('api.request.started', [
            'event'  => 'api.request.started',
            'method' => $request->method(),
            'path'   => $request->path(),
        ]);

        $response = $next($request);

        Log::channel('structured')->log(
            $response->isSuccessful() ? 'info' : ($response->isServerError() ? 'error' : 'warning'),
            'api.request.completed',
            [
                'event'       => 'api.request.completed',
                'method'      => $request->method(),
                'path'        => $request->path(),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]
        );

        return $response;
    }
}
```

## Example log lines

A read request completing normally:

```json
{"timestamp":"2026-07-16T12:00:00.482Z","level":"info","message":"api.request.completed","event":"api.request.completed","service":"laravel-api","environment":"production","company_id":4821,"branch_id":1,"user_id":9931,"actor_type":"user","request_id":"01912e4a-6f2a-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e4a-6f2a-7c9e-8b7a-1a2b3c4d5e6f","method":"GET","path":"/api/v1/accounting/invoices","permission":"accounting.invoice.read","status_code":200,"duration_ms":38.42,"ip_hash":"9f3a...c21","context":{"result_count":25,"cursor":"eyJpZCI6MTIzfQ"},"tags":[]}
```

A permission-denied write attempt, tagged as a security event:

```json
{"timestamp":"2026-07-16T12:03:11.902Z","level":"warning","message":"authorization.denied","event":"authorization.denied","service":"laravel-api","environment":"production","company_id":4821,"branch_id":1,"user_id":9931,"actor_type":"user","request_id":"01912e4b-1a10-7d3e-9c11-2b3c4d5e6f70","correlation_id":"01912e4b-1a10-7d3e-9c11-2b3c4d5e6f70","method":"POST","path":"/api/v1/banking/transfers","permission":"bank.transfer","status_code":403,"duration_ms":4.11,"ip_hash":"9f3a...c21","context":{"required_permission":"bank.transfer","user_roles":["accountant"]},"tags":["security.event"]}
```

An unhandled server error:

```json
{"timestamp":"2026-07-16T12:07:45.113Z","level":"error","message":"unhandled_exception","event":"api.exception","service":"laravel-api","environment":"production","company_id":4821,"branch_id":null,"user_id":9931,"actor_type":"user","request_id":"01912e4c-88b0-7a11-9e33-3c4d5e6f7081","correlation_id":"01912e4c-88b0-7a11-9e33-3c4d5e6f7081","method":"POST","path":"/api/v1/accounting/journal-entries","status_code":500,"duration_ms":211.7,"context":{"exception":"Illuminate\\Database\\QueryException","file":"app/Repositories/JournalRepository.php","line":142,"trace_id":"abbreviated-for-log"},"tags":["incident_candidate"]}
```

## Relationship to `audit_logs`

`audit_logs` is the permanent, business-facing ledger of mutations. It is populated by an `AuditLogger`
domain service called from every Repository write method — never from a Monolog handler, and never on the
hot read path — so it stays small, precise, and free of read-traffic noise. Its DDL, owned jointly by the
Accounting foundation and extended here with the observability columns this document introduces
(`request_id`, `correlation_id`, `prev_hash`, `row_hash`, `legal_hold`):

```sql
CREATE TABLE audit_logs (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    branch_id      BIGINT NULL REFERENCES branches(id),
    user_id        BIGINT NULL REFERENCES users(id),
    actor_type     TEXT NOT NULL DEFAULT 'user' CHECK (actor_type IN ('user','ai_agent','system')),
    ai_agent_key   TEXT NULL,                      -- e.g. 'auditor_agent' when actor_type = 'ai_agent'
    action         TEXT NOT NULL,                  -- e.g. 'journal_entry.posted', 'payroll_run.approved'
    auditable_type TEXT NOT NULL,                  -- e.g. 'JournalEntry'
    auditable_id   BIGINT NOT NULL,
    old_values     JSONB NULL,                     -- masked at write time, see # Sensitive Data
    new_values     JSONB NULL,                     -- masked at write time, see # Sensitive Data
    reason         TEXT NULL,
    ip_address     TEXT NULL,                      -- stored hashed, see # Sensitive Data
    user_agent     TEXT NULL,
    request_id     UUID NOT NULL,
    correlation_id UUID NOT NULL,
    prev_hash      TEXT NULL,                      -- hash chain, see # Compliance
    row_hash       TEXT NOT NULL,
    legal_hold     BOOLEAN NOT NULL DEFAULT FALSE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
) PARTITION BY RANGE (created_at);

CREATE INDEX idx_audit_logs_company_created   ON audit_logs (company_id, created_at DESC);
CREATE INDEX idx_audit_logs_auditable         ON audit_logs (auditable_type, auditable_id);
CREATE INDEX idx_audit_logs_request_id        ON audit_logs (request_id);
CREATE INDEX idx_audit_logs_correlation_id    ON audit_logs (correlation_id);
CREATE INDEX idx_audit_logs_reason_fts        ON audit_logs USING GIN (to_tsvector('simple', coalesce(reason, '')));
```

The rule of thumb engineers are given: **if it changes data, it goes in `audit_logs`, in the same
transaction as the change, full stop — the structured log line for that same request is a courtesy copy
for dashboards, not the source of truth.** If it only reads data, it is structured-log-only.

# Request IDs

## Definition

A `request_id` identifies exactly one unit of work: one inbound HTTP request to Laravel, one inbound HTTP
request to the FastAPI AI engine, one queue job execution (a job re-run after failure gets a *new*
`request_id`, not a reused one, so retries are distinguishable), or one scheduled command run. It is
minted fresh at the start of that unit of work and never reused across two different units of work, even
if they are part of the same business transaction — that broader relationship is what `correlation_id`
(next section) is for.

## Generation

Laravel mints `request_id` as a UUIDv7 (`Str::orderedUuid()`), not UUIDv4. UUIDv7 embeds a millisecond
timestamp in its high bits, which keeps it monotonically sortable and gives good B-tree locality on the
`audit_logs.request_id` and structured-log index fields — a meaningful operational win at QAYD's insert
volume compared with the random scatter of UUIDv4. The FastAPI AI engine and the queue workers use the
Python `uuid6` package's `uuid7()` for the same reason, so `request_id` values are format-compatible and
sortable across every service.

## Middleware

`AssignRequestId` is the first middleware in the global stack — ahead of `TrustProxies`, rate limiting,
and authentication — so that every response, including a 401, a 429, or a request that never reaches a
controller, still carries a `request_id`. This is deliberate: an unauthenticated brute-force attempt is
exactly the kind of event that must be traceable.

```php
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');

        $requestId = ($incoming && $this->isTrustedInternalCaller($request) && Str::isUuid($incoming))
            ? $incoming
            : (string) Str::orderedUuid();

        app(RequestContext::class)->requestId = $requestId;
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function isTrustedInternalCaller(Request $request): bool
    {
        // Only the AI engine, the queue-worker HTTP callbacks, and the internal
        // service mesh carry a pre-shared internal service token; public/browser
        // clients' X-Request-Id is always ignored and regenerated to prevent
        // log-injection or cross-tenant correlation spoofing.
        return $request->attributes->get('internal_service_auth') === true;
    }
}
```

Public clients (the Next.js web app, Flutter mobile app, third-party integrations) MAY send `X-Request-Id`
for their own client-side correlation, but the server never trusts it as the log-of-record `request_id`;
it is captured instead into `context.client_request_id` for support purposes ("the app showed me error ID
abc123") without letting an external party inject an ID that collides with or spoofs an internal one.

## Where `request_id` shows up

| Surface | Field / Header |
|---|---|
| Every API response envelope | `request_id` (top-level key, see below) |
| Every API response | `X-Request-Id` header |
| Every structured log line for that request | `request_id` |
| The `audit_logs` row written during that request | `audit_logs.request_id` |
| A queue job payload dispatched from that request | `payload._meta.request_id` (job's own request gets a fresh id; this is the *parent* id) |
| Outbound call from Laravel to the FastAPI AI engine | `X-Request-Id` header on the outbound HTTP call |
| Outbound webhook delivery | `X-Qayd-Request-Id` header + `request_id` field in the payload envelope |
| Reverb websocket broadcast | `request_id` field in the event envelope |

Standard response envelope, reproduced here because `request_id` is a mandatory top-level field on every
single response, success or error, with no exceptions:

```json
{
  "success": true,
  "data": { "id": 88231, "invoice_number": "INV-2026-004821" },
  "message": "Invoice created successfully.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "01912e4a-6f2a-7c9e-8b7a-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T12:00:00.482Z"
}
```

```bash
curl -sS https://api.qayd.app/api/v1/accounting/invoices/88231 \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 4821" \
  -i | grep -i x-request-id
# X-Request-Id: 01912e4a-6f2a-7c9e-8b7a-1a2b3c4d5e6f
```

## `request_id` vs `Idempotency-Key`

These are frequently confused and serve opposite purposes, so QAYD documents both wherever a write
endpoint is described:

| | `request_id` | `Idempotency-Key` |
|---|---|---|
| Origin | Always server-generated | Always client-supplied |
| Purpose | Observability / tracing of one attempt | Retry-safety: dedupes two attempts of the *same* logical write |
| Lifetime | One HTTP round trip | Must be reusable across retries of the same operation (24h window, cached in Redis) |
| Present on reads | Yes | No — only meaningful on POST/PUT/PATCH |
| Where enforced | `AssignRequestId` middleware | `EnsureIdempotency` middleware, keyed on `(company_id, route, Idempotency-Key)` in Redis |

A client retrying a failed `POST /api/v1/accounting/invoices` after a timeout sends the *same*
`Idempotency-Key` both times (so QAYD returns the original result instead of creating a duplicate invoice)
but each of the two HTTP attempts still gets its *own* distinct `request_id`, because each attempt is a
distinct, individually loggable unit of work.

# Correlation IDs

## Definition

A `correlation_id` identifies one end-to-end business workflow — everything that happens as a consequence
of a single triggering action, however many requests, queue jobs, AI engine calls, and webhook deliveries
it fans out into. Where `request_id` answers "what happened in this one call," `correlation_id` answers
"show me the whole story."

## Minting rule

A `correlation_id` is minted exactly once, at the root of a workflow, by `AssignCorrelationId` middleware
running immediately after `AssignRequestId`:

- If the inbound request carries `X-Correlation-Id` **and** the caller is a trusted internal service (the
  AI engine calling back into Laravel, a queue worker's HTTP callback, an internal admin tool), that value
  is inherited — the workflow continues rather than restarts.
- Otherwise (a fresh client-initiated action, e.g. a user clicking "Post Journal Entry" or a scheduled
  command kicking off `payroll_runs`), `correlation_id` defaults to that call's own `request_id`. This
  guarantees every request has a valid, non-null `correlation_id` even if it never fans out further — a
  single self-contained request is trivially "a workflow of one."

Every subsequent hop of that same workflow — a dispatched queue job, an outbound call to the AI engine, an
outbound webhook — receives the *same* `correlation_id` but mints its *own new* `request_id`. This is the
single rule that makes the whole model work: `correlation_id` is inherited, `request_id` is always fresh.

## Propagation table

| Hop | Mechanism |
|---|---|
| Client → Laravel | Optional inbound `X-Correlation-Id` (only honored from trusted internal callers; otherwise ignored, see above) |
| Laravel → FastAPI AI engine | Outbound `X-Correlation-Id` header on the internal HTTP call |
| Laravel → Redis queue | `correlation_id` field written into the job payload (`ShouldQueue` jobs implement `WithCorrelation` which serializes it alongside the job's own arguments) |
| Queue worker → Laravel (callback/service call) | `X-Correlation-Id` header, read back off the job payload |
| FastAPI → Laravel (AI result callback) | `X-Correlation-Id` header on the callback POST |
| Laravel → Webhook consumer | `X-Qayd-Correlation-Id` header + `correlation_id` field inside the webhook JSON payload |
| Laravel → Reverb (websocket) | `correlation_id` field in the broadcast event envelope |

## Worked example: a payroll run

```
Client                Laravel API              Redis Queue         FastAPI AI Engine        Webhook
  |  POST /payroll-runs/{id}/calculate           |                       |                    |
  |------------------------------------------->  |                       |                    |
  |   C = c1 (root), R = r1                      |                       |                    |
  |                    dispatch CalculatePayrollJob(C=c1)                |                    |
  |                    |----------------------->  |                       |                    |
  |                    |                          |  job runs, R = r2     |                    |
  |                    |                          |  calls AI engine, C=c1|                    |
  |                    |                          |---------------------> |                    |
  |                    |                          |                       | Payroll Manager    |
  |                    |                          |                       | agent runs, R = r3 |
  |                    |                          |                       | returns draft +    |
  |                    |                          |                       | confidence, C=c1   |
  |                    |                          |  <--------------------|                    |
  |                    |  callback posts journal, R = r4, C = c1          |                    |
  |                    |<-----------------------  |                       |                    |
  |                    |  fires payroll.completed webhook, R = r5, C = c1 |                    |
  |                    |-------------------------------------------------------------------->  |
  |  200 (poll or websocket push), R = r1, C = c1 |                       |                    |
  |<-------------------------------------------   |                       |                    |
```

Five distinct `request_id`s (`r1`..`r5`), one `correlation_id` (`c1`) threading the entire run. An engineer
or auditor querying `correlation_id = c1` sees the complete, ordered story: the human trigger, the queued
calculation, the AI agent's proposal and confidence score, the resulting journal posting, and the outbound
notification — across four different services, five different log sources, and one `audit_logs` entry for
the journal post itself.

## Interop with distributed tracing

QAYD also emits the W3C `traceparent` header alongside its native `X-Request-Id` / `X-Correlation-Id`
headers, mapping `correlation_id → trace_id` and `request_id → span_id`, so that generic APM tooling
(Grafana Tempo, Jaeger) can render the same workflow as a waterfall trace without QAYD needing to abandon
its own human-readable, support-friendly header names. The mapping is deterministic and one-directional
(QAYD IDs are generated first; the trace headers are derived from them), so there is exactly one source of
truth per workflow, never two competing identifiers.

## Query patterns

Internal engineers reconstruct a full workflow with a single filter in Grafana/Kibana:
`{correlation_id="01912e4a-6f2a-7c9e-8b7a-1a2b3c4d5e6f"}`. Tenants and support agents get the equivalent
through the API (see `# Search`): `GET /api/v1/admin/logs/search?correlation_id=...`, which returns the
`audit_logs` rows for that workflow in chronological order, already permission-filtered to the caller's
own company.

# Sensitive Data (mask salary/tokens/IBAN/card)

## Classification

Every field QAYD stores is classified once, in code, at the DTO/Resource layer, into one of four
treatments. This classification is the input to the masking pipeline described below and to the `Log
Levels & Sampling` and `Retention` decisions elsewhere in this document.

| Category | Examples (columns) | Log treatment | Storage treatment |
|---|---|---|---|
| **Secrets — never logged** | `users.password`, Sanctum plaintext tokens, JWT bearer tokens, OTP codes, `ELEVENLABS_API_KEY`-style provider secrets, webhook signing secrets | Full redaction: `"[REDACTED]"`, key name itself is retained so shape is visible, value never is | Hashed (`password`) or encrypted-at-rest, never plaintext |
| **Payment card data — never logged, never stored** | PAN, CVV, expiry date | Full redaction; a PAN-shaped value anywhere in a payload trips a `security.event` and is redacted before the log line is even assembled | QAYD does not store PANs at all — card capture happens inside the PCI-compliant processor's hosted fields/SDK; QAYD only ever sees a processor token/reference |
| **Regulated financial PII — masked** | `bank_accounts.iban`, `vendor_bank_accounts.iban`, `bank_transactions.account_number`, `salary_components.amount`, `payslips.net_pay`, `employees.basic_salary`, `employees.national_id` | Partial mask (see patterns below), always, regardless of the caller's own read permission — logs are for operations, not for reproducing the payroll UI | `NUMERIC`/`TEXT` columns encrypted-at-rest at the storage layer (Postgres TDE / column-level `pgcrypto` for IBAN and national ID); read access gated by permission |
| **General PII — lightly masked** | `users.email`, `customers.phone`, `employees.phone` | Partial mask (`a***@domain.com`, `+965 ****1234`) | Stored plaintext (needed operationally), access logged |

## Masking architecture: allow-list primary, deny-list safety net

QAYD implements masking in two independent layers, deliberately redundant:

1. **Allow-list (primary control).** Every API response and every log context is built from an explicit,
   per-resource "loggable" DTO (e.g. `InvoiceLogContext`, `PayslipLogContext`) that whitelists exactly
   which fields are allowed into a log line. Fields not on the allow-list are never even *offered* to the
   logger; this is the control that actually matters.
2. **Deny-list processor (defense-in-depth).** A Monolog processor, `SensitiveDataProcessor`, recursively
   walks every log record's `context` (and, on the FastAPI side, an equivalent `structlog` processor) and
   masks anything matching a key-name pattern or a value pattern, in case a future feature accidentally
   passes an unfiltered model attribute array into a log call. This is the safety net, not the primary
   control — relying on regexes alone is explicitly rejected as the sole defense because it is trivially
   defeated by a differently-named field or a nested structure the regex author didn't anticipate.

```php
final class SensitiveDataProcessor implements Monolog\Processor\ProcessorInterface
{
    private const KEY_PATTERN = '/salary|basic_pay|net_pay|iban|account_number|acct_no|token|password|secret|cvv|card_number|pan|national_id|civil_id|passport/i';

    // IBAN: 2 letters + 2 digits + up to 30 alphanumerics. PAN: 13-19 digits, optionally spaced/dashed.
    private const IBAN_VALUE_PATTERN = '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/';
    private const PAN_VALUE_PATTERN  = '/\b(?:\d[ -]?){13,19}\b/';
    private const JWT_VALUE_PATTERN  = '/\b[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->maskRecursive($record->context));
    }

    private function maskRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskRecursive($value);
                continue;
            }
            if (is_string($key) && preg_match(self::KEY_PATTERN, $key)) {
                $data[$key] = $this->mask((string) $value, $key);
                continue;
            }
            if (is_string($value)) {
                if (preg_match(self::JWT_VALUE_PATTERN, $value)) { $data[$key] = '[REDACTED:token]'; continue; }
                if (preg_match(self::PAN_VALUE_PATTERN, $value))  { $data[$key] = '[REDACTED:card]'; continue; }
                if (preg_match(self::IBAN_VALUE_PATTERN, $value)) { $data[$key] = $this->maskTail($value); continue; }
            }
        }
        return $data;
    }

    private function mask(string $value, string $key): string
    {
        return str_contains($key, 'token') || str_contains($key, 'password') || str_contains($key, 'secret')
            ? '[REDACTED]'
            : $this->maskTail($value);
    }

    private function maskTail(string $value): string
    {
        $clean = preg_replace('/\s+/', '', $value);
        return strlen($clean) <= 4 ? '****' : str_repeat('*', strlen($clean) - 4) . substr($clean, -4);
    }
}
```

## Before / after example

```json
// Raw payload passed to the payroll service (never logged as-is):
{
  "employee_id": 771,
  "basic_salary": 1850.0000,
  "bank_account": { "iban": "KW81CBKU0000000000001234560101", "bank_name": "NBK" },
  "auth_token": "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI5OTMxIn0.sflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c",
  "note": "processed by ops"
}
```

```json
// Structured log line context after SensitiveDataProcessor:
{
  "employee_id": 771,
  "basic_salary": "***********0000",
  "bank_account": { "iban": "KW81CBKU****0101", "bank_name": "NBK" },
  "auth_token": "[REDACTED]",
  "note": "processed by ops"
}
```

## Where masking is enforced

Masking is not only a logging concern — the same `SensitiveDataProcessor` logic (as a shared package,
`qayd/log-safety`, vendored into both the Laravel app and, as a Python port, the FastAPI engine) is applied
at every point sensitive data could otherwise leak into a durable, searchable store that is not the
primary encrypted database column:

- **`audit_logs.old_values` / `new_values`** — masked at write time by the same `AuditLogger` service that
  writes the row. If a genuine reversal ever needs the true prior value (e.g., correcting a mis-keyed
  IBAN), the unmasked value is recoverable only from the primary encrypted `bank_accounts` table's own
  history (Laravel model events + `Illuminate\Database\Eloquent\Casts\AsEncryptedCollection`), gated by a
  distinct high-privilege permission `payroll.amounts.unmask` / `banking.iban.unmask`, and *that* unmask
  action is itself written to `audit_logs` (`action = 'sensitive_value.unmasked'`).
- **FastAPI AI engine logs** — the AI agents routinely reason over payslips, bank statements, and invoices;
  the Python-side processor runs before any `structlog` `bind()` call is flushed, and additionally, the
  prompt/response pairs persisted to `ai_messages` for audit purposes are masked before storage, not only
  before logging, because `ai_messages.content` is itself a quasi-log surface.
- **Webhook payloads** — outbound webhooks (`payroll.completed`, `bank.synced`, etc.) never include
  unmasked salary or IBAN values; they carry resource IDs and non-sensitive summary fields only. A webhook
  consumer that needs the detail must call back through the authenticated `/api/v1` API, which re-applies
  the caller's own permission checks — logging discipline does not get to be laxer than the API itself.
- **Error trackers / crash reports** — the same processor runs as an exception-context scrubber before any
  payload is sent to the error-tracking sink, so a stack trace that happens to capture a request array in
  its context does not become the leak.

# Retention

## Retention table

| Category | Store | Hot | Cold / Archive | Legal basis |
|---|---|---|---|---|
| Application/debug logs | Loki | 14 days | none (deleted) | Operational only, no compliance value |
| Structured access/API logs | Loki + derived fields in Elasticsearch | 30 days | 1 year (compressed NDJSON in R2) | Incident investigation window; SOC 2 monitoring evidence |
| Security logs (`security.event` tag: auth failures, permission denials, rate-limit trips, PAN/IBAN pattern hits) | Elasticsearch | 90 days | 3 years (R2) | SOC 2 CC7, ISO 27001 A.8.16, breach-investigation window |
| AI engine reasoning logs (`ai_conversations`, `ai_messages`, FastAPI decision logs) | PostgreSQL (`ai_conversations`/`ai_messages`) + Elasticsearch mirror | 180 days full content | 3 years, content summarized/hashed after day 180 | AI-decision dispute resolution vs PII minimization balance |
| Webhook delivery logs | Elasticsearch | 90 days | Summarized (counts, not payloads) thereafter | Integration debugging window |
| **`audit_logs`** (PostgreSQL, permanent business record) | PostgreSQL, partitioned by quarter | Indefinite while account active | Never deleted while active; +1 year post-offboarding, then exported to the company and purged | Kuwait Commercial Companies Law bookkeeping retention (≥5 years); QAYD standardizes to **7 years** to also satisfy stricter GCC neighbor requirements for multi-country tenants |

## Deletion mechanics

A nightly Artisan scheduled command enforces the table above per category:

```php
// app/Console/Kernel.php
$schedule->command('logs:prune --category=debug --older-than=14d')->daily();
$schedule->command('logs:prune --category=access --older-than=30d --to=cold')->daily();
$schedule->command('logs:prune --category=security --older-than=90d --to=cold')->daily();
$schedule->command('audit:archive-partition --quarter-older-than=28')->monthly(); // 7 years = 28 quarters
```

`logs:prune` never touches `audit_logs`; it only operates on the Loki/Elasticsearch structured-log planes.
`audit_logs` partitions are archived (moved to a read-only, cheaper-storage tablespace) after 7 years, not
deleted, unless a company has been fully offboarded and its data-processing agreement's purge date has
passed. R2 lifecycle rules on the cold-archive bucket implement age-based transition/expiration directly in
object storage configuration rather than in application code, so a bug in a cron job cannot silently retain
data past its policy window or, worse, delete it early.

## Legal hold

`audit_logs.legal_hold` (boolean, added to the DDL in `# Structured Logging`) pins a row against any
pruning or archival regardless of its age. It is set only via permission `compliance.legal_hold.set`, which
requires a mandatory `reason` string, and the act of setting it is itself written to `audit_logs` as
`action = 'legal_hold.applied'` — a hold on the audit log is, itself, audited.

## Right-to-erasure vs immutability

A company or individual's request to delete personal data collides, by design, with the immutability
`audit_logs` requires for bookkeeping law. QAYD resolves this by **pseudonymizing, never deleting**, the
audit trail: `user_id` foreign keys are preserved for referential and debit/credit integrity, but the
*display* layer resolves a pseudonymized user's name to `"Former User #9931"` once the underlying `users`
row has been anonymized through the standard account-deletion flow. The monetary facts of what was posted,
by whom (by durable ID, if no longer by name), and when, are never erased — only the human-identifying
overlay is.

# Search

## Two search surfaces, two audiences

QAYD deliberately exposes two different search paths over two different stores, because the audiences and
trust boundaries are different:

1. **Internal engineering/SRE search** over the structured-log planes (Loki + Elasticsearch), used by
   engineers and on-call responders through Grafana Explore and Kibana Discover, behind SSO + VPN. This
   surface can see cross-tenant technical telemetry (already masked per `# Sensitive Data`) because it is
   necessary for debugging shared infrastructure.
2. **A first-class authenticated API endpoint**, `/api/v1/admin/logs/search`, backed by `audit_logs`, used
   by (a) in-app "Activity" / "Audit Trail" screens so a company's own Owner/Auditor role can see their own
   history, and (b) QAYD internal support tooling for cross-company investigation under a distinct,
   more tightly held permission.

## Example queries

LogQL (Loki) — all `403`s for company 4821 in the banking area over the last hour:

```logql
{service="laravel-api", environment="production"}
  | json
  | company_id="4821"
  | status_code="403"
  | path=~"/api/v1/banking.*"
```

Kibana Query Language / Lucene (Elasticsearch) — the equivalent, plus full-text over the `context.reason`
field:

```
service:"laravel-api" AND environment:"production" AND company_id:4821
  AND status_code:403 AND path:/api\/v1\/banking.*/
```

## Cardinality note

`company_id` is deliberately **not** used as a raw Loki *label* at QAYD's tenant scale, because Loki's
index grows with label cardinality and a label with tens of thousands of distinct values degrades query
performance badly. `company_id` is kept inside the parsed JSON body (queried via the `| json` pipeline
stage above) rather than as a stream label. In Elasticsearch this concern does not apply the same way —
`company_id` is a standard indexed `keyword` field there, which is why cross-tenant security-log search
(where filtering precision matters more than raw ingestion cost) lives in Elasticsearch, not Loki.

## The `/api/v1/admin/logs/search` endpoint

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/admin/logs/search` | `logs.search` | Search the caller's own company's `audit_logs` |
| GET | `/api/v1/admin/logs/search?cross_company=true` | `logs.search.cross_company` | Platform-admin only: search across companies |
| GET | `/api/v1/admin/logs/{id}` | `logs.search` | Fetch one `audit_logs` row by id, scoped to caller's company |

Request:

```bash
curl -sS "https://api.qayd.app/api/v1/admin/logs/search?correlation_id=01912e4a-6f2a-7c9e-8b7a-1a2b3c4d5e6f&per_page=25" \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 4821"
```

Response:

```json
{
  "success": true,
  "data": [
    {
      "id": 5501233,
      "action": "payroll_run.calculate.requested",
      "auditable_type": "PayrollRun",
      "auditable_id": 991,
      "user_id": 9931,
      "actor_type": "user",
      "request_id": "01912e4a-...-r1",
      "correlation_id": "01912e4a-...-c1",
      "created_at": "2026-07-16T12:00:00.482Z"
    },
    {
      "id": 5501240,
      "action": "journal_entry.posted",
      "auditable_type": "JournalEntry",
      "auditable_id": 88452,
      "user_id": null,
      "actor_type": "ai_agent",
      "ai_agent_key": "payroll_manager_agent",
      "request_id": "01912e4a-...-r4",
      "correlation_id": "01912e4a-...-c1",
      "created_at": "2026-07-16T12:00:04.113Z"
    }
  ],
  "message": "12 audit events found.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 12, "cursor": null } },
  "request_id": "01912e50-aa11-7b22-8c33-4d5e6f708192",
  "timestamp": "2026-07-16T12:15:02.001Z"
}
```

Every call to this endpoint is itself written to `audit_logs` as `action = 'logs.searched'`, capturing the
query parameters (masked) and the requesting user — deliberately closing the "who watches the watchers"
gap, since the ability to read audit history is itself a privileged action worth a trail of its own. Ad
hoc lighter-weight lookups that don't warrant the full search endpoint (e.g., "what changed on this one
invoice") also work directly against `audit_logs.reason`'s GIN/`tsvector` index for simple full-text
matches without needing Kibana access at all.

# Compliance

## Framework mapping

| Framework | Control area | How QAYD logging satisfies it |
|---|---|---|
| PCI-DSS | Req. 3 — protect stored cardholder data | QAYD never stores or logs full PAN/CVV; card capture is delegated to a PCI-compliant processor's tokenized fields; the `SensitiveDataProcessor` PAN-pattern check exists purely as a tripwire, not a primary control |
| PCI-DSS | Req. 10 — track and monitor all access to network resources and cardholder data | Every request touching `bank_*`/payment-adjacent resources is 100%-logged (never sampled, see `# Log Levels & Sampling`) with actor, timestamp, and outcome |
| PCI-DSS | Req. 10.5 — secure audit trails so they cannot be altered | `audit_logs` is insert-only at the database-role level (the application's Postgres role has no `UPDATE`/`DELETE` grant on `audit_logs`); hash-chained (below) |
| SOC 2 Type II | CC6 — logical access controls | Every `authorization.denied` event is a first-class, always-sampled, tagged `security.event` log entry feeding the access-control monitoring dashboard |
| SOC 2 Type II | CC7 — system operations / monitoring | Alerting rules in `# Shipping` on error-rate, permission-denial spikes, and AI-confidence anomalies are the CC7 evidence artifact |
| SOC 2 Type II | CC8 — change management | Schema/config/permission changes are themselves `audit_logs` entries (`action = 'role.permission.changed'`, `'company.settings.updated'`) |
| ISO/IEC 27001 | A.8.15 Logging, A.8.16 Monitoring activities | This document is the design record satisfying both controls; the nightly hash-chain verification (below) is the monitoring evidence for A.8.16 |
| ISO/IEC 27001 | A.5.33 Protection of records | 7-year `audit_logs` retention + insert-only DB role + R2 archive immutability (Object Lock) |
| Kuwait Commercial Companies Law No. 1/2016 | Bookkeeping retention | `audit_logs` 7-year retention (see `# Retention`) exceeds the statutory minimum |
| Kuwait CITRA Data Privacy Protection Regulation | PII handling in AI logs | `ai_messages` content minimization after 180 days; masking pipeline applies to AI logs identically to API logs |
| Saudi PDPL / UAE PDPL (per-tenant, config-driven) | Cross-border data handling | `companies.country` drives a per-tenant compliance policy flag that can tighten retention/masking further than the GCC baseline without a code change |
| GDPR-style principles (adopted voluntarily) | Purpose limitation, minimization, storage limitation | Applied to AI conversation logs even though QAYD's primary markets are GCC-based, because AI reasoning logs are the highest-PII-density log category QAYD has |

## Tamper-evidence: hash chaining

Every `audit_logs` row's `row_hash` is computed as `SHA-256(prev_hash || canonical_json(row_without_hash))`,
where `prev_hash` is the `row_hash` of the immediately preceding row **within the same `company_id`**
(chains are per-tenant, not global, so one tenant's write volume doesn't gate another's and a chain break
is unambiguous about which tenant is affected):

```php
final class AuditChainService
{
    public function nextHash(int $companyId, array $row): string
    {
        $prevHash = AuditLog::where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('row_hash') ?? str_repeat('0', 64); // genesis row

        $canonical = json_encode($this->canonicalize($row), JSON_UNESCAPED_SLASHES);

        return hash('sha256', $prevHash . $canonical);
    }
}
```

A nightly `audit:verify-chain` command re-walks every company's chain and recomputes each `row_hash` from
its stored fields; any mismatch means either row tampering or an out-of-band write that bypassed
`AuditLogger` (both are incidents). A break raises a `critical`-level structured log and pages the on-call
engineer — it is treated with the same severity as a production outage, because for a financial system an
undetected silent edit to the audit trail is arguably worse than downtime.

## Access-to-logs is itself audited

Reading `audit_logs` — whether through the `/api/v1/admin/logs/search` endpoint or through direct
Kibana/Grafana access to the masked structured-log copies — is a permissioned, audited action in its own
right (`logs.search`, logged as `logs.searched`, see `# Search`). This closes the common gap where "the
people who can read the audit trail" become an unaudited class of super-user; at QAYD, reading the trail
leaves a trail.

## Segregation-of-duties evidence

External Auditor exports (role: `External Auditor`, permission `reports.export` + read-only
`accounting.*.read`) include a standard report, generated from `audit_logs` joins, proving no single user
both created and approved/posted the same `journal_entries` row — the classic SoD control for financial
systems — plus an equivalent check for `payroll_runs` (calculated-by ≠ approved-by) and `vendor_payments`
(bill entry ≠ payment release). Any exception surfaces as a flagged row in the export rather than being
silently allowed, and Auditor-role users can run this report themselves without needing an engineer to
query the database directly.

# Log Levels & Sampling

## PSR-3 levels in QAYD terms

| Level | QAYD example |
|---|---|
| `debug` | Raw SQL query timing; only enabled in `local` |
| `info` | `api.request.completed` (2xx), `journal_entry.posted`, `ai.agent.invoked` |
| `notice` | Cache miss falling back to source-of-truth read; non-fatal config fallback |
| `warning` | AI agent confidence below the auto-action threshold, downgraded to suggest-only; `authorization.denied` (403) |
| `error` | Webhook delivery failed after all retries; external bank-sync API timeout; validation-adjacent 5xx |
| `critical` | Audit hash-chain verification failure; double-entry imbalance detected (`SUM(debits) != SUM(credits)`) at post time |
| `alert` | Payroll run partially posted then the process crashed mid-batch |
| `emergency` | Primary database unreachable; queue backlog exceeds failover threshold |

## Per-environment thresholds

| Channel | local | staging | production |
|---|---|---|---|
| `app` (general application logs) | `debug` | `info` | `warning` |
| `audit`-adjacent / `security` (anything tagged `security.event`, all mutations) | `debug` | `info` | `info` — **never raised above `info`; nothing in this channel is ever filtered out by level** |
| `ai` (AI engine decision logs) | `debug` | `info` | `info` |

The financial/security signal is deliberately exempt from level-based filtering in production: a `warning`
like `authorization.denied` must never be silently dropped just because the general `app` channel's
threshold is tuned up to control noise/cost.

## Sampling policy

Sampling is a cost/volume control applied **only** to high-frequency, low-forensic-value log categories —
never to anything that could matter to a dispute, an audit, or a security investigation.

| Category | Sampled? | Rate | Rationale |
|---|---|---|---|
| Health checks (`/up`, load-balancer probes) | Yes | 1% at `info`, 100% at `error` | Pure noise when healthy |
| Successful idempotent `GET` reads | Yes | 10% at `info` in production, 100% at `error` | High volume, low individual forensic value; errors always kept |
| All mutating requests (`POST`/`PUT`/`PATCH`/`DELETE`) | **No** | 100% | Every financial mutation must be reconstructable |
| `401`/`403` responses | **No** | 100%, tagged `security.event` | Security-relevant by definition |
| AI agent invocations (`ai.agent.invoked`, `ai.agent.completed`) | **No** | 100% | Confidence + reasoning is itself a compliance artifact, not telemetry |
| Webhook deliveries | Partial | 100% on failure, 10% on 2xx success | Failures are the operationally interesting case |
| Rate-limit trips (`429`) | **No** | 100% | Abuse-detection signal |

## Deterministic sampling by `request_id`

Sampling decisions are made once, deterministically, from a hash of the `request_id` — not by an
independent coin-flip per log line — so that *all* log lines belonging to one sampled-in request are kept
together, and all lines of a sampled-out request are dropped together. An engineer never sees a
half-complete request in the sample.

```php
final class SamplingProcessor implements Monolog\Processor\ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->isNeverSampled($record)) {
            return $record; // mutations, security events, AI events, errors: always kept
        }

        $rate = (float) Redis::get('log:sampling:' . $record->channel) ?: $this->defaultRate($record);
        $keep = (hexdec(substr(md5($record->extra['request_id'] ?? ''), 0, 8)) / 0xFFFFFFFF) < $rate;

        return $keep ? $record->with(extra: array_merge($record->extra, ['tags' => ['sampled']]))
                     : $record->with(context: []); // dropped downstream by the shipping agent's tag filter
    }
}
```

## Dynamic / break-glass sampling

`log:sampling:{channel}` is a Redis key, adjustable at runtime without a deploy: an on-call engineer
investigating an incident sets it to `1.0` (100%) for the affected channel, with a TTL (default 4 hours) so
it automatically reverts to the standard rate rather than silently staying wide open after the incident is
resolved. Changing this key is itself an audited action (`action = 'observability.sampling.changed'`)
because "temporarily log everything" is a meaningful operational and privacy-relevant decision, not a
no-op.

# Shipping (Loki/ELK)

## Architecture

```
┌────────────┐  ┌────────────┐  ┌──────────────┐  ┌──────────────────┐
│ Laravel API│  │ FastAPI AI │  │ Queue workers│  │ Webhook dispatch │
│ (Monolog   │  │ engine     │  │ (Horizon)    │  │ worker           │
│  JSON→stdout)│ │(structlog  │  │ (JSON→stdout)│  │ (JSON→stdout)    │
└─────┬──────┘  │ JSON→stdout)│  └──────┬───────┘  └────────┬─────────┘
      │         └─────┬──────┘         │                   │
      └───────┬────────┴────────┬───────┴───────────────────┘
              │      container stdout (all services)        │
              ▼                                              ▼
      ┌───────────────────────────────────────────────────────────┐
      │   Vector / Fluent Bit  (node daemonset / sidecar)          │
      │   - tails docker/k8s container logs                        │
      │   - parses NDJSON                                           │
      │   - enriches: pod, namespace, node, cluster                 │
      │   - last-resort mask sweep (defense-in-depth #2)            │
      │   - routes by tag                                           │
      └───────────────┬───────────────────────┬────────────────────┘
                       │                       │
        high-volume operational stream   security/compliance-tagged stream
                       │                       │
                       ▼                       ▼
                ┌─────────────┐        ┌──────────────────────┐
                │    Loki     │        │ Logstash / ingest     │
                │ (label-idx, │        │ pipeline → Elasticsearch│
                │  cheap, 30d)│        │ (full-text, RBAC, 90d+)│
                └──────┬──────┘        └──────────┬────────────┘
                       │                            │
                       ▼                            ▼
                  Grafana                        Kibana
             (dashboards, Alerting)      (Discover, compliance review, ILM)
```

The same event class can be routed to both sinks (e.g. every `security.event`-tagged line goes to both
Loki, for the unified engineering timeline, and Elasticsearch, for longer retention and Kibana's
document-level security when a compliance reviewer needs access without also getting engineering-wide log
visibility).

## Vector configuration (representative)

```toml
[sources.container_logs]
type = "docker_logs"
include_containers = ["laravel-api", "ai-engine", "queue-worker", "webhook-dispatcher"]

[transforms.parse_json]
type = "remap"
inputs = ["container_logs"]
source = '''
. = parse_json!(.message)
.k8s_pod = get_env_var!("POD_NAME")
.k8s_namespace = get_env_var!("POD_NAMESPACE")
# Last-resort mask sweep: defense-in-depth #2 (agent-level), independent of the
# application-level SensitiveDataProcessor. Anything matching still gets caught.
if exists(.context.iban) { .context.iban = "[REDACTED-BY-AGENT]" }
if match(to_string!(.), r'\b(?:\d[ -]?){13,19}\b') {
  .tags = push(.tags, "shipping_redaction_triggered")
}
'''

[transforms.route_by_tag]
type = "route"
inputs = ["parse_json"]
route.security = '.tags != null && contains(.tags, "security.event")'
route.default  = "true"

[sinks.loki]
type = "loki"
inputs = ["parse_json"]
endpoint = "http://loki.qayd.internal:3100"
encoding.codec = "json"
labels.service = "{{ service }}"
labels.environment = "{{ environment }}"
labels.level = "{{ level }}"
buffer.type = "disk"
buffer.max_size = 268435488   # ~256MB local buffer, outage tolerance

[sinks.elasticsearch]
type = "elasticsearch"
inputs = ["route_by_tag.security"]
endpoint = "https://es.qayd.internal:9200"
bulk.index = "qayd-security-%Y.%m.%d"
buffer.type = "disk"
buffer.max_size = 268435488

[sinks.dead_letter]
type = "aws_s3"   # R2 via S3-compatible endpoint
inputs = ["parse_json", "route_by_tag.security"]
bucket = "qayd-log-shipping-dlq"
condition = "sink_unhealthy"
```

## Reliability & backpressure

- Each Vector/Fluent Bit instance buffers to local disk (sized for several hours of outage tolerance) if
  Loki or Elasticsearch is unreachable, and retries with backoff rather than dropping.
- If a sink is down long enough to exceed the disk buffer, events overflow to a dead-letter path in R2
  (`qayd-log-shipping-dlq/`) rather than being discarded, and a `critical` alert fires on buffer-depth
  exceeding 80%.
- Container log drivers (`json-file` under Docker, the equivalent CRI driver under Kubernetes) are
  configured with their own small rotation/size caps (`max-size: 50m, max-file: 3`) purely to protect node
  disk pressure — the shipping agent, not the container runtime, owns actual retention.

## Grafana / Loki

Dashboards built on the Loki datasource: request rate & error rate by `path`, `authorization.denied` rate
by `company_id` (top-N, security-relevant), AI agent confidence-score distribution over time, webhook
delivery success rate by `event` type. Example Grafana Alerting rule:

```yaml
apiVersion: 1
groups:
  - name: qayd-api-slo
    rules:
      - alert: HighServerErrorRate
        expr: |
          sum(rate({service="laravel-api"} | json | status_code>=500 [5m]))
          / sum(rate({service="laravel-api"} [5m])) > 0.02
        for: 5m
        labels: { severity: page }
        annotations: { summary: "5xx rate > 2% over 5m" }
      - alert: PermissionDeniedSpike
        expr: sum(rate({service="laravel-api"} | json | tags=~".*security.event.*" [5m])) > 20
        for: 2m
        labels: { severity: warn }
        annotations: { summary: "Unusual spike in authorization.denied events" }
```

## Elasticsearch ILM (Index Lifecycle Management)

The Elasticsearch side mirrors the `# Retention` table via an ILM policy per index pattern:

```json
{
  "policy": {
    "phases": {
      "hot":   { "actions": { "rollover": { "max_age": "1d", "max_size": "20gb" } } },
      "warm":  { "min_age": "30d", "actions": { "shrink": { "number_of_shards": 1 } } },
      "cold":  { "min_age": "90d", "actions": { "searchable_snapshot": { "snapshot_repository": "r2-cold" } } },
      "delete": { "min_age": "1095d", "actions": { "delete": {} } }
    }
  }
}
```

# Examples

## 1. Successful invoice creation

```bash
curl -sS -X POST https://api.qayd.app/api/v1/sales/invoices \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Company-Id: 4821" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6b1f6e9a-...-idem" \
  -d '{"customer_id": 331, "currency_code": "KWD", "items": [{"product_id": 88, "qty": 2, "unit_price": 125.5}]}'
```

```json
{
  "success": true,
  "data": { "id": 90112, "invoice_number": "INV-2026-004900", "status": "draft", "total": 251.0000 },
  "message": "Invoice created successfully.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "01912e60-1111-7c9e-8b7a-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T13:00:00.001Z"
}
```

```json
{"timestamp":"2026-07-16T13:00:00.001Z","level":"info","message":"api.request.completed","event":"api.request.completed","service":"laravel-api","environment":"production","company_id":4821,"user_id":9931,"actor_type":"user","request_id":"01912e60-1111-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e60-1111-7c9e-8b7a-1a2b3c4d5e6f","method":"POST","path":"/api/v1/sales/invoices","permission":"sales.invoice.create","status_code":201,"duration_ms":52.9,"context":{"invoice_id":90112,"total":251.0},"tags":[]}
```

```json
{"id":7788231,"company_id":4821,"user_id":9931,"actor_type":"user","action":"invoice.created","auditable_type":"Invoice","auditable_id":90112,"old_values":null,"new_values":{"status":"draft","total":251.0,"customer_id":331},"reason":null,"request_id":"01912e60-1111-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e60-1111-7c9e-8b7a-1a2b3c4d5e6f","row_hash":"a1c9...ff2","created_at":"2026-07-16T13:00:00.001Z"}
```

## 2. Validation error — unbalanced journal entry (422)

```json
{
  "success": false,
  "data": null,
  "message": "The given data was invalid.",
  "errors": [
    { "field": "lines", "code": "journal.unbalanced", "message": "Sum of debits (500.0000) must equal sum of credits (480.0000)." }
  ],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 0, "cursor": null } },
  "request_id": "01912e61-2222-7c9e-8b7a-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T13:01:14.223Z"
}
```

```json
{"timestamp":"2026-07-16T13:01:14.223Z","level":"warning","message":"validation.failed","event":"api.validation.failed","service":"laravel-api","environment":"production","company_id":4821,"user_id":9931,"request_id":"01912e61-2222-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e61-2222-7c9e-8b7a-1a2b3c4d5e6f","method":"POST","path":"/api/v1/accounting/journal-entries","permission":"accounting.journal.create","status_code":422,"duration_ms":9.8,"context":{"errors":[{"field":"lines","code":"journal.unbalanced"}]},"tags":[]}
```

## 3. Permission denied — attempted bank transfer (403)

```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to perform this action.",
  "errors": [ { "field": null, "code": "authorization.denied", "message": "Missing permission: bank.transfer" } ],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 0, "cursor": null } },
  "request_id": "01912e62-3333-7c9e-8b7a-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T13:02:40.555Z"
}
```

```json
{"timestamp":"2026-07-16T13:02:40.555Z","level":"warning","message":"authorization.denied","event":"authorization.denied","service":"laravel-api","environment":"production","company_id":4821,"user_id":9931,"request_id":"01912e62-3333-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e62-3333-7c9e-8b7a-1a2b3c4d5e6f","method":"POST","path":"/api/v1/banking/transfers","permission":"bank.transfer","status_code":403,"duration_ms":3.4,"context":{"required_permission":"bank.transfer","user_roles":["accountant"],"amount":"[REDACTED:masked-financial-amount]"},"tags":["security.event"]}
```

## 4. AI agent flags an anomaly (Auditor agent)

```json
{"timestamp":"2026-07-16T13:05:00.010Z","level":"info","message":"ai.agent.completed","event":"ai.agent.completed","service":"ai-engine","environment":"production","company_id":4821,"user_id":null,"actor_type":"ai_agent","request_id":"01912e63-4444-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e63-4444-7c9e-8b7a-1a2b3c4d5e6f","context":{"agent":"auditor_agent","input_ref":{"type":"JournalEntry","id":88452},"finding":"duplicate_vendor_payment_suspected","confidence":0.86,"reasoning":"Vendor 331 invoice #INV-4471 amount 1250.00 KWD matches a payment posted 3 days prior with the same reference number.","autonomy":"suggest_only","supporting_documents":["bills#4471","vendor_payments#9013"]},"tags":["ai.flag"]}
```

```json
{"id":7788299,"company_id":4821,"user_id":null,"actor_type":"ai_agent","ai_agent_key":"auditor_agent","action":"anomaly.flagged","auditable_type":"JournalEntry","auditable_id":88452,"new_values":{"finding":"duplicate_vendor_payment_suspected","confidence":0.86},"reason":"Possible duplicate vendor payment detected by Auditor agent; human review requested.","request_id":"01912e63-4444-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e63-4444-7c9e-8b7a-1a2b3c4d5e6f","row_hash":"77bd...1e0","created_at":"2026-07-16T13:05:00.010Z"}
```

## 5. Webhook delivery with one retry

```json
{"timestamp":"2026-07-16T13:10:00.900Z","level":"error","message":"webhook.delivery.failed","event":"webhook.delivery.failed","service":"webhook-dispatcher","environment":"production","company_id":4821,"request_id":"01912e64-5555-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e64-c1c1-7c9e-8b7a-1a2b3c4d5e6f","context":{"webhook_endpoint_id":221,"event":"payroll.completed","attempt":1,"response_code":503,"next_retry_in_s":30},"tags":[]}
```

```json
{"timestamp":"2026-07-16T13:10:31.120Z","level":"info","message":"webhook.delivery.succeeded","event":"webhook.delivery.succeeded","service":"webhook-dispatcher","environment":"production","company_id":4821,"request_id":"01912e65-6666-7c9e-8b7a-1a2b3c4d5e6f","correlation_id":"01912e64-c1c1-7c9e-8b7a-1a2b3c4d5e6f","context":{"webhook_endpoint_id":221,"event":"payroll.completed","attempt":2,"response_code":200},"tags":["sampled"]}
```

## 6. Sensitive-data masking in practice (combined example)

```json
// Before (raw internal payload, never persisted to any log store in this form):
{
  "employee_id": 771,
  "basic_salary": 1850.0,
  "iban": "KW81CBKU0000000000001234560101",
  "card_on_file": "4111 1111 1111 1111",
  "auth_token": "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI5OTMxIn0.sflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c"
}
```

```json
// After SensitiveDataProcessor (this is what ever reaches Loki/Elasticsearch):
{
  "employee_id": 771,
  "basic_salary": "***********0000",
  "iban": "KW81CBKU****0101",
  "card_on_file": "[REDACTED:card]",
  "auth_token": "[REDACTED]"
}
```

## 7. Full correlation trace across services (payroll run, condensed)

```json
{"event":"api.request.completed","request_id":"r1","correlation_id":"c1","path":"/api/v1/payroll/payroll-runs/991/calculate","status_code":202,"user_id":9931}
{"event":"queue.job.started","request_id":"r2","correlation_id":"c1","context":{"job":"CalculatePayrollJob","payroll_run_id":991}}
{"event":"ai.agent.invoked","request_id":"r3","correlation_id":"c1","context":{"agent":"payroll_manager_agent","confidence_pending":true}}
{"event":"ai.agent.completed","request_id":"r3","correlation_id":"c1","context":{"agent":"payroll_manager_agent","confidence":0.97,"autonomy":"auto_with_notify"}}
{"event":"journal_entry.posted","request_id":"r4","correlation_id":"c1","context":{"journal_entry_id":88452,"source":"payroll_run:991"}}
{"event":"webhook.delivery.succeeded","request_id":"r5","correlation_id":"c1","context":{"event":"payroll.completed","webhook_endpoint_id":221}}
```

A single `GET /api/v1/admin/logs/search?correlation_id=c1` call returns the `audit_logs` subset of this
same story (rows 1, 4, and 5 above are audit-worthy mutations; rows 2 and 3 are structured-log-only
process telemetry), in chronological order, scoped to company 4821.

## 8. Search API — cross-company platform-admin investigation

```bash
curl -sS "https://api.qayd.app/api/v1/admin/logs/search?cross_company=true&action=authorization.denied&from=2026-07-16T00:00:00Z&to=2026-07-16T23:59:59Z" \
  -H "Authorization: Bearer eyJhbGciOi..." \
  -H "X-Platform-Admin: true"
```

```json
{
  "success": true,
  "data": [
    { "id": 7788240, "company_id": 4821, "action": "authorization.denied", "permission": "bank.transfer", "created_at": "2026-07-16T13:02:40.555Z" },
    { "id": 7788511, "company_id": 6603, "action": "authorization.denied", "permission": "payroll.approve", "created_at": "2026-07-16T14:11:02.302Z" }
  ],
  "message": "2 audit events found across companies.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 2, "cursor": null } },
  "request_id": "01912e70-7777-7c9e-8b7a-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T15:00:00.000Z"
}
```

This call itself produces an `audit_logs` row (`action: 'logs.searched'`, `context.cross_company: true`),
because cross-tenant visibility is the single most sensitive capability this document describes, and it is
never granted, nor exercised, without a trail.

# End of Document
