# API Monitoring & Observability — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: Monitoring & Observability
---

# Purpose

QAYD's API layer is the single ingress point for every client — the Next.js web app, the Flutter
mobile app, third-party integrations, and the internal FastAPI AI engine — into a multi-tenant
financial system of record. A defect, a slow query, or a silent failure in this layer does not
just degrade a screen; it can delay a payroll release, desynchronize a bank reconciliation, or
cause an AI agent to act on stale data. This document specifies how QAYD observes, measures, and
alerts on the health of the Laravel 12 API: what we instrument, where the metrics live, how traces
correlate a request across the Laravel monolith and the FastAPI AI engine, how errors are tracked
and triaged, what our Service Level Objectives are, how dashboards are laid out, what fires a page
versus a ticket, how liveness/readiness are exposed to orchestrators, how usage is metered per
tenant and per API key for billing and abuse detection, how synthetic checks catch regressions
before customers do, how we plan capacity ahead of tenant growth, and the runbooks on-call
engineers execute when a signal fires. Every metric, trace, log line, and alert described here is
implemented as Laravel middleware, listeners, and scheduled jobs — never as ad-hoc instrumentation
bolted onto individual controllers — so that observability coverage is uniform across all ~40
resource families (`accounts`, `journal-entries`, `invoices`, `bills`, `payroll-runs`,
`stock-movements`, etc.) without per-endpoint opt-in.

The audience for this document is the platform/SRE engineer building the observability stack and
the backend engineer who needs to know what instrumentation is expected on any new endpoint before
it ships to production.

# Golden Signals

QAYD's observability program is built on the four Golden Signals (Google SRE), applied uniformly
across every `/api/v1/*` route and extended with two QAYD-specific signals (tenant fairness and AI
confidence drift) that matter for a multi-tenant financial system with an embedded AI engine.

| Signal | Definition for QAYD | Primary Source |
|---|---|---|
| **Latency** | Time from request received to response envelope sent, split by success vs. error latency (a 422 should not silently count as "fast success") | `X-Response-Time` header + Prometheus histogram |
| **Traffic** | Requests per second (RPS), per route, per company, per API key | Prometheus counter on `api_requests_total` |
| **Errors** | Rate of 4xx/5xx responses, and *domain* errors (e.g., journal entry rejected for imbalance) even when HTTP status is 200-adjacent (422) | Prometheus counter `api_errors_total` + Sentry |
| **Saturation** | How "full" the system is: PHP-FPM/Octane worker pool usage, PostgreSQL connection pool usage, Redis memory, queue depth (`payroll`, `ai-jobs`, `webhooks` queues) | Prometheus gauges + Laravel Horizon metrics |
| **Tenant Fairness** (QAYD-specific) | Whether one company's traffic burst is degrading latency/error rate for other companies sharing the cluster | Per-company RPS vs. per-company p95 latency correlation |
| **AI Confidence Drift** (QAYD-specific) | Whether AI-proposed actions (journal drafts, OCR extraction, forecasts) are trending toward lower confidence scores, a leading indicator of a model or data-quality regression | `ai_confidence_score` histogram tagged by `agent_type` |

Golden Signals are the first thing rendered on the primary Grafana dashboard (see `# Dashboards`)
and the first four checks any on-call engineer walks through before opening a runbook.

# Metrics

## Instrumentation Point

All metrics are emitted from a single Laravel middleware, `App\Http\Middleware\MetricsMiddleware`,
registered globally in `bootstrap/app.php` after `EnsureCompanyContext` and
`Authenticate` so that `company_id`, `user_id`, and the matched route name are always available as
labels. Metrics are pushed to a local **Prometheus** exporter (`promphp/prometheus_client_php`
backed by the Redis storage adapter, `metrics` Redis logical database, distinct from the cache and
queue databases) and scraped every 15 seconds by the cluster's Prometheus server.

```php
// app/Http/Middleware/MetricsMiddleware.php
public function handle(Request $request, Closure $next): Response
{
    $start = microtime(true);
    $response = $next($request);
    $duration = microtime(true) - $start;

    $route = $request->route()?->getName() ?? 'unmatched';
    $labels = [$route, $request->method(), (string) $response->getStatusCode()];

    $this->registry->getOrRegisterHistogram(
        'qayd', 'api_request_duration_seconds', 'API request latency',
        ['route', 'method', 'status'],
        [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
    )->observe($duration, $labels);

    $this->registry->getOrRegisterCounter(
        'qayd', 'api_requests_total', 'Total API requests',
        ['route', 'method', 'status', 'company_id']
    )->inc([$route, $request->method(), (string) $response->getStatusCode(),
            (string) ($request->attributes->get('company_id') ?? 'unauthenticated')]);

    if ($response->getStatusCode() >= 400) {
        $this->registry->getOrRegisterCounter(
            'qayd', 'api_errors_total', 'Total API errors',
            ['route', 'status', 'error_code']
        )->inc([$route, (string) $response->getStatusCode(),
                $response->headers->get('X-Error-Code', 'unknown')]);
    }

    return $response->header('X-Response-Time', round($duration * 1000, 2) . 'ms')
                     ->header('X-Request-Id', $request->attributes->get('request_id'));
}
```

## Per-Endpoint Metric Catalog

Every endpoint automatically exposes these four Prometheus series without any additional code:

| Metric | Type | Labels | Purpose |
|---|---|---|---|
| `qayd_api_request_duration_seconds` | Histogram | `route`, `method`, `status` | Computes p50/p95/p99 latency |
| `qayd_api_requests_total` | Counter | `route`, `method`, `status`, `company_id` | Computes RPS and traffic share |
| `qayd_api_errors_total` | Counter | `route`, `status`, `error_code` | Computes error rate |
| `qayd_api_inflight_requests` | Gauge | `route` | Current concurrent requests (saturation) |

p50/p95/p99 latency, error rate, and RPS are derived at query time via PromQL, never precomputed
and stored, so that the aggregation window (1m, 5m, 1h) is chosen at dashboard/alert time:

```promql
# p95 latency for the invoices creation endpoint, 5-minute window
histogram_quantile(0.95,
  sum(rate(qayd_api_request_duration_seconds_bucket{route="invoices.store"}[5m])) by (le))

# Error rate (%) for the whole API, 5-minute window
100 * sum(rate(qayd_api_errors_total[5m])) / sum(rate(qayd_api_requests_total[5m]))

# RPS per company, top 10
topk(10, sum(rate(qayd_api_requests_total[1m])) by (company_id))
```

## Additional Domain Metrics

Beyond generic HTTP metrics, specific subsystems emit business-relevant gauges/counters consumed
by the same Prometheus registry:

| Metric | Type | Emitted By |
|---|---|---|
| `qayd_journal_imbalance_rejections_total` | Counter | `JournalEntryService::post()` when debits ≠ credits |
| `qayd_webhook_delivery_duration_seconds` | Histogram | Webhook dispatcher (per event type) |
| `qayd_webhook_delivery_failures_total` | Counter | Webhook dispatcher, labeled by `event`, `http_status` |
| `qayd_ai_confidence_score` | Histogram | Every AI agent response, labeled by `agent_type` |
| `qayd_rate_limit_rejections_total` | Counter | Rate-limit middleware, labeled by `company_id`, `route` |
| `qayd_queue_depth` | Gauge | Laravel Horizon metrics exporter, labeled by `queue` |
| `qayd_db_connections_in_use` | Gauge | PostgreSQL connection pool (PgBouncer `SHOW POOLS`) |

Retention: Prometheus retains 15 days of raw samples locally; a remote-write pipeline ships
downsampled (5m rollups) series to long-term storage (Thanos/Mimir-compatible) for 13 months, which
covers fiscal-year-over-fiscal-year comparisons.

# Distributed Tracing

## Correlation via `request_id`

Every response envelope carries a top-level `request_id` (UUID v4), generated once at the edge by
`App\Http\Middleware\AssignRequestId` and propagated on every downstream call — to PostgreSQL (as a
session comment via `SET LOCAL application_name`), to Redis (as a key prefix on structured log
context), to the FastAPI AI engine (as the `X-Request-Id` header), and to any webhook delivery (as
a header on the outbound POST). It is the single join key across Laravel logs, FastAPI logs,
Prometheus exemplars, and Sentry events for one logical user action.

```http
POST /api/v1/accounting/journal-entries HTTP/1.1
Host: api.qayd.com
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
X-Company-Id: 4821
Content-Type: application/json

{ "fiscal_period_id": 118, "lines": [ ... ] }
```

```json
{
  "success": true,
  "data": { "id": 90142, "status": "draft" },
  "message": "Journal entry created",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b7e6a2b0-4b1a-4a9a-9f2e-6a2c9d9e1f10",
  "timestamp": "2026-07-16T12:00:03Z"
}
```

## OpenTelemetry Instrumentation

QAYD uses **OpenTelemetry (OTel)** as the vendor-neutral tracing standard, exported via OTLP/gRPC
to a collector that fans out to the tracing backend (Grafana Tempo / Jaeger-compatible). The
`request_id` is set as the OTel `trace_id`'s baggage attribute (`qayd.request_id`) rather than
replacing the OTel-generated trace ID, so trace propagation stays W3C `traceparent`-compliant while
remaining human-searchable by our own UUID.

Instrumentation layers:

| Layer | Library | What It Wraps |
|---|---|---|
| Laravel HTTP kernel | `open-telemetry/opentelemetry-auto-laravel` | Every controller action, root span named `{method} {route}` |
| Query layer | `open-telemetry/opentelemetry-auto-pdo` | Every PostgreSQL query as a child span, tagged `db.statement` (redacted of literal values) |
| Redis | `open-telemetry/opentelemetry-auto-redis` | Cache/rate-limit/queue operations |
| Outbound HTTP (Guzzle) | `open-telemetry/opentelemetry-auto-guzzle` | Calls to the FastAPI AI engine, ElevenLabs-style third parties, webhook delivery |
| FastAPI AI engine | `opentelemetry-instrumentation-fastapi` (Python) | Continues the trace received via `traceparent` header, adds spans per agent (`OCRAgent.extract`, `ForecastAgent.project`) |

A single trace for "create a journal entry with an AI-assisted account suggestion" looks like:

```
Trace: b7e6a2b0-4b1a-4a9a-9f2e-6a2c9d9e1f10  (root span: 214ms)
├─ span: POST journal-entries.store                         (Laravel, 214ms)
│  ├─ span: middleware.authenticate                          (4ms)
│  ├─ span: middleware.authorize [accounting.journal.create] (2ms)
│  ├─ span: FormRequest.validate                              (6ms)
│  ├─ span: guzzle.POST /ai/suggest-account                  (Laravel → FastAPI, 118ms)
│  │  └─ span: AccountSuggestionAgent.run                    (FastAPI, 110ms)
│  │     ├─ span: pg.query SELECT accounts WHERE ...          (12ms)
│  │     └─ span: llm.completion [confidence=0.93]             (91ms)
│  ├─ span: JournalEntryService.create                        (48ms)
│  │  ├─ span: pdo.query INSERT INTO journal_entries          (6ms)
│  │  ├─ span: pdo.query INSERT INTO journal_lines (x4)       (14ms)
│  │  └─ span: event.dispatch journal.created                (3ms)
│  └─ span: redis.set cache:journal_entries:90142             (2ms)
```

Sampling: 100% of requests with an error (4xx/5xx) or latency above the route's p95 SLO target are
always sampled ("tail-based" sampling at the collector); routine successful traffic is head-sampled
at 10% to control storage cost. Sensitive financial values (amounts, account numbers, bank details)
are never included in span attributes — spans carry IDs and metadata only, never payload bodies.

# Error Tracking

**Sentry** is the error-tracking system of record for both the Laravel API and the FastAPI AI
engine (single Sentry organization, two projects: `qayd-api`, `qayd-ai-engine`), configured via
`sentry/sentry-laravel` and `sentry-sdk[fastapi]` respectively.

## What Gets Captured

| Event Class | Captured? | Sentry Level |
|---|---|---|
| Unhandled `Throwable` (5xx) | Yes, always | `error` |
| `ValidationException` (422) | No — expected, tracked via metrics not error tracker | — |
| `AuthorizationException` (403) | Aggregated as breadcrumb only, not a Sentry issue, unless rate spikes >5x baseline | `warning` |
| Domain exceptions (`ImbalancedJournalEntryException`, `InsufficientStockException`) | Captured as `warning`, grouped by exception class + route | `warning` |
| Queue job failures (webhook delivery, payroll calculation) | Yes, always, with job payload (PII-scrubbed) | `error` |
| AI agent low-confidence or failed inference | Yes, as `info`-level breadcrumb feeding the `ai_confidence_score` metric | `info` |

Every Sentry event is enriched with:
- `request_id` (searchable custom tag, matches the trace and log correlation key)
- `company_id`, `user_id` (tags — never full user objects; PII scrubbing via `sentry.laravel`
  `before_send` hook strips request body fields matching `/password|token|iban|card_number/i`)
- Laravel context: route name, controller action, matched middleware group
- Release version (git SHA) and deploy environment (`production`, `staging`)

```php
// config/sentry.php (excerpt)
'before_send' => function (Event $event, ?Hint $hint): ?Event {
    $event->setTag('request_id', request()->attributes->get('request_id'));
    $event->setTag('company_id', (string) request()->attributes->get('company_id'));
    return App\Support\SentryPiiScrubber::scrub($event);
},
'traces_sample_rate' => 0.1,
'profiles_sample_rate' => 0.1,
```

## Triage Workflow

1. Sentry issue created/regressed → posted to `#qayd-incidents` Slack channel via Sentry's native
   integration, tagged with `company_id` count affected (single-tenant vs. multi-tenant blast radius).
2. On-call engineer opens the issue, pivots to the trace via the embedded `request_id` link (Sentry
   → Tempo deep link), and to the Grafana dashboard filtered to that route.
3. Issues affecting >1% of requests on a route, or any issue touching `journal-entries`,
   `payroll-runs`, or `bank-transfers` posting logic, are auto-escalated to Sev-2 regardless of
   raw volume (financial-integrity routes get a lower escalation bar — see `# Alerting`).
4. Resolution requires a linked commit/PR; Sentry auto-resolves the issue on next deploy containing
   that commit and reopens automatically ("regression") if the same fingerprint reappears.

# SLOs & SLIs

SLIs are the PromQL-computed signals; SLOs are the target thresholds; error budgets are tracked in
Grafana and burn-rate alerts (see `# Alerting`) fire before the monthly budget is exhausted.

| SLI | SLO Target | Measurement Window | Notes |
|---|---|---|---|
| Dashboard load (`/api/v1/dashboard/summary`) p95 | **< 2s** | rolling 28 days | Aggregates across accounting/sales/inventory in one call |
| Search endpoints (`?search=`) p95 across all resources | **< 300ms** | rolling 28 days | Backed by PostgreSQL trigram/full-text indexes |
| Write endpoints (POST/PUT/PATCH) p95, non-AI | **< 500ms** | rolling 28 days | Excludes AI-assisted endpoints (separate SLO below) |
| Write endpoints (POST/PUT/PATCH) p99, non-AI | **< 1.5s** | rolling 28 days | |
| AI-assisted endpoints (`/ai/*` proxied through Laravel) p95 | **< 3s** | rolling 28 days | LLM latency dominates; tracked separately from core CRUD |
| Read endpoints (GET, non-search) p95 | **< 250ms** | rolling 28 days | |
| Availability (non-5xx rate) | **>= 99.9%** | rolling 28 days | ≈ 43 minutes of allowed downtime/month |
| Webhook delivery success (first attempt) | **>= 99%** | rolling 28 days | Retried deliveries excluded from this SLI; see retry policy |
| Auth endpoints (`/auth/login`, `/auth/refresh`) p95 | **< 400ms** | rolling 28 days | |

## Error Budget

Availability SLO of 99.9% over 28 days yields an error budget of **0.1% of requests**, tracked as:

```promql
# Error budget remaining (%) for the 28-day availability SLO
100 * (1 - (
  sum(increase(qayd_api_errors_total{status=~"5.."}[28d]))
  /
  sum(increase(qayd_api_requests_total[28d]))
) / 0.001)
```

When the burn rate implies the 28-day budget will be exhausted in **< 6 hours** at the current
rate, a fast-burn page fires (see `# Alerting`). When it implies exhaustion in **< 3 days**, a
slow-burn ticket is filed for the next sprint. Feature freezes on the affected module are triggered
automatically (via a CI gate checking a `budget_status` flag written by a scheduled job) if error
budget drops below 10% remaining mid-cycle.

# Dashboards

Grafana is the canonical dashboard tool, backed by Prometheus (metrics), Tempo (traces), and Loki
(logs) as a unified Grafana data-source trio, so any panel supports "view trace" / "view logs"
drill-down via exemplars and `request_id` correlation.

## Dashboard: "API Overview" (default landing page)

| Panel | Query Basis | Visualization |
|---|---|---|
| Golden Signals row | `api_request_duration_seconds`, `api_requests_total`, `api_errors_total`, `api_inflight_requests` | 4 stat panels + sparkline |
| RPS by route (top 15) | `sum(rate(qayd_api_requests_total[5m])) by (route)` | Stacked time series |
| p50/p95/p99 latency, whole API | `histogram_quantile` at 3 thresholds | Time series, 3 lines |
| Error rate by status class (4xx vs 5xx) | `sum(rate(qayd_api_errors_total[5m])) by (status)` | Time series, stacked area |
| Top 10 slowest routes (current p95) | `topk(10, histogram_quantile(0.95, ...))` | Table |
| Top 10 error-prone routes | `topk(10, sum(rate(qayd_api_errors_total[5m])) by (route))` | Table |
| Saturation: PHP workers, DB connections, Redis memory, queue depth | Respective gauges | 4 gauge panels with SLO-line thresholds |
| SLO burn-down (28-day) | Error budget PromQL above | Single-stat with color thresholds (green > 50%, amber 10-50%, red < 10%) |

## Dashboard: "Tenant Health"

Filterable by `company_id` (template variable), shows per-tenant RPS, latency, error rate, queue
usage, and AI confidence trend — used both for support triage ("is company 4821 having a bad time
right now?") and for the fairness signal ("is company 9902's burst degrading everyone else?"):

```promql
# Tenant fairness check: correlation candidate
sum(rate(qayd_api_requests_total{company_id="9902"}[5m]))
and
histogram_quantile(0.95, sum(rate(qayd_api_request_duration_seconds_bucket{company_id!="9902"}[5m])) by (le))
```

## Dashboard: "AI Engine Health"

Confidence score distribution per agent type, AI request latency (Laravel → FastAPI hop isolated
from LLM completion time), inference failure rate, and count of AI-proposed actions awaiting human
approval by module (journal drafts, payroll adjustments, tax filings) — a queue-depth style panel
that doubles as a compliance signal (approvals should not pile up).

## Dashboard: "Business/Domain Health"

Non-infra signals that matter to the business: journal imbalance rejection rate, webhook delivery
failure rate by event type, invoice-to-payment latency (time from `invoice.created` to
`payment.received` webhook), and bank reconciliation match rate — all derived from the same
Prometheus registry as domain counters, giving product/finance stakeholders a Grafana view without
needing database access.

# Alerting

Alerts route through **Alertmanager**, grouped by `route`/`company_id`/`severity`, to two channels:
PagerDuty for pages (Sev-1/Sev-2, wakes someone up) and Slack `#qayd-alerts` for tickets
(Sev-3/Sev-4, next-business-day). Every alert rule links to its corresponding runbook (see
`# Runbooks`) via the `runbook_url` annotation, which Alertmanager renders directly in the
notification.

## Alert Rule Table

| Alert | Condition (PromQL) | Severity | Action |
|---|---|---|---|
| `HighErrorRate` | `100 * sum(rate(qayd_api_errors_total{status=~"5.."}[5m])) / sum(rate(qayd_api_requests_total[5m])) > 1` for 5m | Sev-1 (page) | See Runbook R-01 |
| `CriticalRouteErrorRate` | error rate > 0.5% for 3m on `journal-entries.*`, `bank-transfers.*`, `payroll-runs.release` | Sev-1 (page) | See Runbook R-01 (lower threshold — financial integrity) |
| `LatencySLOBreach` | p95 latency > SLO target for 10m, per route class | Sev-2 (page during business hours, ticket after) | See Runbook R-02 |
| `ErrorBudgetFastBurn` | Burn rate implies exhaustion in < 6h | Sev-1 (page) | See Runbook R-05 |
| `ErrorBudgetSlowBurn` | Burn rate implies exhaustion in < 3d | Sev-3 (ticket) | Schedule remediation |
| `SaturationDBConnections` | `qayd_db_connections_in_use / qayd_db_connections_max > 0.85` for 5m | Sev-2 (page) | See Runbook R-03 |
| `SaturationQueueDepth` | `qayd_queue_depth{queue="payroll"} > 500` for 10m, or `queue="webhooks"` `> 2000` | Sev-2 (page) | See Runbook R-04 |
| `SaturationRedisMemory` | Redis `used_memory / maxmemory > 0.9` | Sev-2 (page) | Scale Redis / evict cold cache keys |
| `RateLimitSpike` | `sum(rate(qayd_rate_limit_rejections_total[5m])) by (company_id) > 50` | Sev-3 (ticket) | Possible abuse or misconfigured integration — see Runbook R-06 |
| `WebhookDeliveryFailureRate` | Delivery success < 95% over 15m for any `event` | Sev-3 (ticket) | Check receiving endpoint health, DLQ inspection |
| `AIConfidenceDrift` | `avg_over_time(qayd_ai_confidence_score[1h]) < avg_over_time(qayd_ai_confidence_score[7d]) * 0.85` | Sev-3 (ticket) | Notify AI/ML on-call — possible model or data drift |
| `HealthCheckFailing` | `/health/ready` returning non-200 from >1 pod for 2m | Sev-1 (page) | See Runbook R-07 |
| `SyntheticCheckFailure` | Synthetic probe (see `# Synthetic Monitoring`) fails 2 consecutive runs | Sev-1 (page) | See Runbook R-08 |

## Example Alertmanager Rule (Prometheus format)

```yaml
groups:
  - name: qayd-api-critical
    rules:
      - alert: CriticalRouteErrorRate
        expr: |
          100 * sum(rate(qayd_api_errors_total{status=~"5..", route=~"journal-entries.*|bank-transfers.*|payroll-runs.release"}[3m]))
          / sum(rate(qayd_api_requests_total{route=~"journal-entries.*|bank-transfers.*|payroll-runs.release"}[3m])) > 0.5
        for: 3m
        labels:
          severity: page
          team: platform
        annotations:
          summary: "Financial-integrity route error rate above 0.5% for 3m"
          runbook_url: "https://runbooks.qayd.internal/R-01-high-error-rate"
```

Every page includes: affected route(s), current vs. threshold value, top 3 companies by request
share on that route (blast radius), and a direct Grafana link pre-filtered to the alerting window.

# Health & Readiness Endpoints

Two unauthenticated, unversioned endpoints exist purely for orchestrator/load-balancer use — they
are excluded from the standard envelope (no auth, no rate limiting, no request_id requirement,
since Kubernetes/ALB health checks should never depend on downstream state):

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/health/live` | None (public) | Liveness — process is up and can serve HTTP. No dependency checks. |
| GET | `/health/ready` | None (public) | Readiness — process can serve real traffic (DB, Redis, queue reachable). |
| GET | `/api/v1/status` | None (public) | Versioned, envelope-wrapped status page for external/customer consumption. |

`/health/live` responds in under 5ms and never touches the database — it exists to distinguish "the
PHP-FPM/Octane process is deadlocked" from "a dependency is down," so Kubernetes doesn't restart
healthy pods just because Postgres is slow.

```http
GET /health/live HTTP/1.1
```
```json
{ "status": "ok" }
```

`/health/ready` checks PostgreSQL (`SELECT 1`), Redis (`PING`), and queue worker heartbeat
(Horizon's last-seen timestamp < 30s old), returning 503 if any fail — the load balancer removes
the pod from rotation on 503, and Kubernetes uses it as the readiness probe (not liveness, so a
transient DB blip drains traffic without killing the pod).

```http
GET /health/ready HTTP/1.1
```
```json
{
  "status": "ok",
  "checks": {
    "database": { "status": "ok", "latency_ms": 3 },
    "redis": { "status": "ok", "latency_ms": 1 },
    "queue_workers": { "status": "ok", "last_heartbeat_seconds_ago": 4 }
  }
}
```
5xx example (used by Runbook R-07):
```json
{
  "status": "degraded",
  "checks": {
    "database": { "status": "error", "error": "connection timeout after 2000ms" },
    "redis": { "status": "ok", "latency_ms": 2 },
    "queue_workers": { "status": "ok", "last_heartbeat_seconds_ago": 6 }
  }
}
```

The public `/api/v1/status` endpoint wraps a coarser rollup (per-subsystem operational/degraded/
outage, no infra internals) in the standard envelope, for use on QAYD's public status page and by
integration partners polling for outage awareness without needing credentials.

# Per-Tenant & Per-Key Usage Metrics

Because QAYD is multi-tenant and exposes API keys for integrations, usage must be measurable at two
additional granularities beyond the global Golden Signals: per `company_id` and per API key
(`personal_access_tokens.id` under Sanctum, extended with a `label` and `scopes` column for
integration keys).

## Data Model

Usage counters are written asynchronously (queued job `RecordApiUsage`, dispatched from
`MetricsMiddleware` onto the low-priority `metrics` queue so usage recording never adds latency to
the request path) into a dedicated append-only table, separate from `audit_logs` (which is
per-record change history, not aggregate traffic):

```sql
CREATE TABLE api_usage_events (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    api_key_id       BIGINT NULL REFERENCES personal_access_tokens(id),
    user_id         BIGINT NULL REFERENCES users(id),
    route           VARCHAR(150) NOT NULL,
    method          VARCHAR(10) NOT NULL,
    status_code     SMALLINT NOT NULL,
    duration_ms     INTEGER NOT NULL,
    request_id      UUID NOT NULL,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
) PARTITION BY RANGE (occurred_at);

CREATE INDEX idx_api_usage_company_time ON api_usage_events (company_id, occurred_at DESC);
CREATE INDEX idx_api_usage_key_time ON api_usage_events (api_key_id, occurred_at DESC)
    WHERE api_key_id IS NOT NULL;
```

The table is monthly-partitioned (`api_usage_events_2026_07`, etc.) with a 13-month retention
policy (older partitions dropped by a scheduled job), keeping high-cardinality per-request rows out
of Prometheus (which stays aggregate-only) while preserving raw per-tenant auditability for billing
disputes.

## Endpoints

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/usage/summary` | `usage.read` | Current company's usage rollup (requests, errors, avg latency) for a date range |
| GET | `/api/v1/usage/by-key` | `usage.read` | Breakdown per API key for the active company |
| GET | `/api/v1/usage/by-endpoint` | `usage.read` | Breakdown per route for the active company |
| GET | `/api/v1/admin/usage/companies` | `platform.usage.read` (internal/platform-admin only) | Cross-tenant usage rollup, for capacity planning and abuse detection |

```http
GET /api/v1/usage/summary?from=2026-07-01&to=2026-07-16 HTTP/1.1
X-Company-Id: 4821
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
```
```json
{
  "success": true,
  "data": {
    "total_requests": 184213,
    "total_errors": 312,
    "error_rate_pct": 0.17,
    "avg_latency_ms": 96,
    "p95_latency_ms": 340,
    "requests_by_day": [
      { "date": "2026-07-15", "requests": 12904, "errors": 21 },
      { "date": "2026-07-16", "requests": 6112, "errors": 9 }
    ],
    "plan_quota": { "monthly_requests": 500000, "used": 184213, "remaining": 315787 }
  },
  "message": "Usage summary retrieved",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9f0a1b22-3c4d-4e5f-8a9b-0c1d2e3f4a5b",
  "timestamp": "2026-07-16T12:00:03Z"
}
```

Per-key usage feeds both **billing** (plan tiers meter monthly request volume; overage triggers a
`usage.quota.exceeded` webhook and, if configured, a soft 429 throttle rather than a hard cutoff for
paying tenants) and **abuse detection** (a key suddenly issuing 50x its historical baseline RPS, or
hitting only `export`/`read` endpoints in a scraping pattern, raises `RateLimitSpike`, see
`# Alerting`, and can be auto-suspended pending review via `platform.usage.suspend-key`).

# Synthetic Monitoring

Real user traffic tells you when something is already broken for real customers; synthetic
monitoring catches the same failures from outside the cluster before a customer report arrives, and
verifies multi-step flows that a single-endpoint metric cannot capture.

## Probe Catalog

| Probe | Frequency | Flow Exercised | Pass Criteria |
|---|---|---|---|
| `synthetic-auth-login` | 1 min | `POST /auth/login` with a dedicated synthetic tenant/user | 200, token returned, < 400ms |
| `synthetic-health` | 30s | `GET /health/ready` from 3 external regions | 200 from all regions |
| `synthetic-crud-invoice` | 5 min | Create → fetch → void a test invoice in a sandboxed synthetic company | All 3 steps 2xx, total flow < 3s |
| `synthetic-journal-posting` | 5 min | Create a balanced journal entry, post it, verify ledger reflects it | Entry status `posted`, ledger delta matches |
| `synthetic-search` | 5 min | `GET /api/v1/customers?search=...` against a seeded dataset | 200, results returned, < 300ms |
| `synthetic-webhook-roundtrip` | 15 min | Trigger a domain event, verify webhook received by a controlled receiver within 10s | Webhook received, signature valid |
| `synthetic-ai-suggestion` | 15 min | Call `/ai/suggest-account` with a fixed input, verify response shape and confidence present | 200, `confidence` field present and > 0 |

All synthetic probes run from a dedicated **synthetic tenant** (`companies.is_synthetic = true`),
fully isolated from real tenant data, excluded from business-metric dashboards but included in
Golden Signal dashboards (its traffic is real HTTP traffic and should behave identically to a
customer's). Probes run from three geographic regions to distinguish regional network issues from
application issues, using an external runner (Grafana Synthetic Monitoring / a scheduled Laravel
command hitting the public endpoint from outside the VPC — never an in-cluster cron, since that
would not catch load-balancer or edge failures).

Two consecutive failures of the same probe fire `SyntheticCheckFailure` (Sev-1) — a single failure
is treated as noise (transient network blip) and only logged, to avoid alert fatigue.

# Capacity Planning

Capacity planning uses the same usage-metrics pipeline (`api_usage_events`, Prometheus long-term
storage) to forecast when infrastructure — PHP-FPM/Octane worker pools, PostgreSQL connections,
Redis memory, queue worker count — needs to scale ahead of tenant growth, rather than reactively
after a saturation alert.

## Process

1. **Weekly trend review**: a scheduled job computes week-over-week growth in total RPS, per-tenant
   RPS distribution (p50/p95/p99 across companies — a few large tenants can dominate), and queue
   throughput, publishing to the `#qayd-capacity` Slack channel.
2. **Quarterly headroom target**: infrastructure is provisioned to keep saturation metrics below
   **60%** of ceiling at projected peak (not average) load, with peak defined as the highest
   5-minute window observed in the trailing 90 days, scaled by the trend growth rate.
3. **Load testing before major launches**: before onboarding a large enterprise tenant or a major
   feature launch (e.g., payroll module GA), k6/Locust load tests replay production-shaped traffic
   at 3x the projected new peak against a staging environment sized identically to production.
4. **Autoscaling as the first line, capacity planning as the second**: Octane workers and queue
   consumers autoscale horizontally on CPU/queue-depth signals within pre-approved min/max bounds;
   capacity planning exists to raise those bounds and to plan stateful resources (PostgreSQL,
   Redis) that cannot autoscale as elastically.

## Capacity Signals Tracked

| Resource | Metric | Scale-Up Trigger |
|---|---|---|
| Octane/PHP-FPM workers | `qayd_api_inflight_requests` sustained > 70% of worker pool | Add workers / horizontal pods |
| PostgreSQL connections | `qayd_db_connections_in_use / max` sustained > 60% at peak | Increase PgBouncer pool size, then consider read replicas |
| Redis memory | `used_memory / maxmemory` trend | Scale Redis instance tier |
| Queue workers (`payroll`, `webhooks`, `ai-jobs`) | `qayd_queue_depth` trend at peak vs. drain rate | Add Horizon supervisors |
| Storage (attachments/R2) | Growth rate of attachment volume per tenant | Adjust R2 lifecycle/retention policy, not compute-bound |

Enterprise tenants that individually exceed 5% of platform-wide RPS are flagged for dedicated
capacity review before contract signature (their traffic pattern materially changes the p95 planning
baseline for the shared cluster) and are candidates for the per-tenant rate-limit tiers described in
the API layer's rate-limiting design.

# Runbooks

Each runbook is a standalone, copy-pasteable procedure linked from its alert's `runbook_url`
annotation. All runbooks share a structure: **Detect → Confirm → Mitigate → Root Cause → Follow-up**.

## R-01: High Error Rate

1. **Detect**: `HighErrorRate` or `CriticalRouteErrorRate` paged.
2. **Confirm**: Open "API Overview" dashboard, filter to the alerting route/time window. Check
   whether errors are concentrated on one `company_id` (tenant-specific bug/data issue) or spread
   across all tenants (platform-wide regression, likely a recent deploy).
3. **Mitigate**:
   - If tied to a deploy in the last 30 minutes (`git SHA` tag in Sentry release matches): roll back
     via the deploy pipeline immediately — do not wait for root cause.
   - If tenant-specific: check that tenant's recent request payloads for malformed data; consider
     temporary rate-limit tightening on that `company_id` if it's a retry storm.
   - If DB-related (confirm via trace: DB span durations spiking): check `SaturationDBConnections`;
     consider killing long-running queries (`pg_terminate_backend`) if a lock is held.
4. **Root Cause**: Pull the top 5 Sentry issues for the window, link the responsible commit/PR.
5. **Follow-up**: File a post-incident review if Sev-1/Sev-2; add a regression test.

## R-02: Latency SLO Breach

1. **Detect**: `LatencySLOBreach` paged/ticketed for a route class.
2. **Confirm**: Open the route in Grafana, check p50 vs p95 vs p99 — a p99-only spike suggests a
   long-tail cause (lock contention, GC pause, a slow downstream call) rather than systemic
   degradation.
3. **Mitigate**: Pivot to a slow trace via exemplar link; identify the dominant span (DB query,
   AI-engine call, external HTTP). For AI-hop latency: check FastAPI engine health independently.
   For DB: check `pg_stat_activity` for blocking queries, check if a missing index correlates with a
   recent schema change.
4. **Root Cause**: Query plan review (`EXPLAIN ANALYZE`) on the implicated query; check recent
   migrations.
5. **Follow-up**: Add/adjust an index, add a covering cache layer, or move a slow synchronous call
   to a queued job.

## R-03: Database Saturation

1. **Detect**: `SaturationDBConnections` paged.
2. **Confirm**: `SHOW POOLS` on PgBouncer; identify whether connections are held by long transactions
   (likely) or genuinely high concurrency (traffic-driven).
3. **Mitigate**: Kill/rollback the longest-held idle-in-transaction session if found; if
   traffic-driven, temporarily raise PgBouncer pool size within DB max_connections headroom.
4. **Root Cause**: Audit for a missing `DB::transaction()` boundary or a job holding a connection
   across an external HTTP call (anti-pattern — external calls must never happen inside an open
   transaction).
5. **Follow-up**: Add a connection-hold-time metric/alert if this class of incident recurs.

## R-04: Queue Saturation

1. **Detect**: `SaturationQueueDepth` paged for `payroll` or `webhooks`.
2. **Confirm**: Check Horizon dashboard for failed-job rate on that queue — a backlog is often a
   symptom of a downstream dependency failing repeatedly and jobs retrying, not just volume.
3. **Mitigate**: Scale up Horizon supervisors for the affected queue; if failures are the cause
   (e.g., webhook receiver down), pause retries for that specific webhook endpoint rather than
   burning worker capacity on guaranteed failures.
4. **Root Cause**: Inspect the dead-letter queue (jobs failed after max attempts) for the common
   exception.
5. **Follow-up**: Add a circuit breaker for webhook endpoints with a sustained failure rate.

## R-05: Error Budget Fast Burn

1. **Detect**: `ErrorBudgetFastBurn` paged.
2. **Confirm**: Determine which SLI (availability, latency) is burning — check the SLO dashboard.
3. **Mitigate**: Treat as a Sev-1 regardless of whether individual alerts (R-01/R-02) have
   independently fired — the burn-rate alert exists precisely to catch a slow accumulation of
   sub-threshold errors that individual alerts miss.
4. **Root Cause / Follow-up**: Same as the underlying SLI's runbook; additionally, trigger the
   feature-freeze gate on the affected module until the budget recovers above 50%.

## R-06: Rate Limit Spike / Suspected Abuse

1. **Detect**: `RateLimitSpike` for a `company_id`/API key.
2. **Confirm**: Check `/api/v1/usage/by-key` for that key's historical baseline vs. current burst;
   check the route pattern (is it a legitimate retry-with-backoff bug in the customer's integration,
   or a scraping/enumeration pattern hitting sequential IDs?).
3. **Mitigate**: If legitimate but misbehaving (bad retry logic), the existing 429 responses are
   sufficient — no manual action needed beyond a courtesy outreach. If malicious/scraping: suspend
   the key via `platform.usage.suspend-key` and notify the company's account owner.
4. **Follow-up**: If retry-storm from a legitimate integration, provide the customer's engineering
   team the `Retry-After` header contract and exponential-backoff guidance.

## R-07: Readiness Check Failing

1. **Detect**: `HealthCheckFailing` paged — one or more pods failing `/health/ready`.
2. **Confirm**: Inspect the failing check's `checks` object (database/redis/queue_workers) to
   identify which dependency is unreachable from that pod specifically (vs. cluster-wide).
3. **Mitigate**: If isolated to specific pods, the orchestrator has already removed them from
   rotation (that's the point of the readiness probe) — verify traffic is flowing to healthy pods.
   If cluster-wide, treat as R-01/R-03 depending on which dependency failed.
4. **Follow-up**: If a specific pod repeatedly fails readiness while siblings are healthy, treat as a
   node-level infra issue (network policy, DNS) rather than an application bug.

## R-08: Synthetic Check Failure

1. **Detect**: `SyntheticCheckFailure` paged — two consecutive failures of the same probe.
2. **Confirm**: Reproduce manually with the exact request the probe sends (probes log their full
   request for this purpose); check whether real user traffic on the same flow shows elevated errors
   (if real traffic is clean but the synthetic fails, suspect the synthetic tenant's fixture data or
   the probe runner's network path first).
3. **Mitigate**: Treat as a real incident if real traffic confirms the failure; escalate per R-01/R-02
   as applicable.
4. **Follow-up**: If the failure was probe-fixture-specific (e.g., synthetic tenant hit a real quota
   limit), fix the fixture and add a guard against synthetic-tenant quota exhaustion.

# Examples

## Example 1 — Reading the Golden Signals for a Single Route via PromQL

```promql
# Full Golden Signals snapshot for invoices.store over the last hour
sum(rate(qayd_api_requests_total{route="invoices.store"}[1h]))                                  # Traffic (RPS)
histogram_quantile(0.95, sum(rate(qayd_api_request_duration_seconds_bucket{route="invoices.store"}[1h])) by (le))  # Latency p95
100 * sum(rate(qayd_api_errors_total{route="invoices.store"}[1h])) / sum(rate(qayd_api_requests_total{route="invoices.store"}[1h]))  # Errors
avg(qayd_api_inflight_requests{route="invoices.store"})                                          # Saturation
```

## Example 2 — Correlating a Slow Request End-to-End

A customer reports "creating an invoice is slow." The support engineer asks for the `request_id`
from the response the customer's integration logged, `c3d4e5f6-...`. In Grafana:

1. Search Loki logs for `request_id="c3d4e5f6-..."` → find the log line with the matched route and
   `company_id`.
2. Click the log line's linked trace → Tempo shows the full span tree (see the tree in
   `# Distributed Tracing`) with per-span durations.
3. Identify the dominant span — say, `guzzle.POST /ai/suggest-account` taking 2.1s of a 2.4s total.
4. Pivot into the FastAPI engine's continuation of the same trace to see that the LLM completion
   span itself took 1.9s — an AI-engine latency issue, not a Laravel/DB issue.
5. Check `qayd_ai_confidence_score` and AI-engine-specific latency dashboards for a broader pattern
   (is this one slow call, or a fleet-wide AI latency regression?) before escalating to the AI/ML
   on-call.

## Example 3 — Webhook Delivery Failure Investigation

```http
GET /api/v1/webhooks/deliveries?event=invoice.paid&status=failed&from=2026-07-16 HTTP/1.1
X-Company-Id: 4821
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
```
```json
{
  "success": true,
  "data": [
    {
      "id": 553201,
      "event": "invoice.paid",
      "endpoint_url": "https://erp.customer.example/webhooks/qayd",
      "attempt": 3,
      "max_attempts": 5,
      "http_status": 504,
      "next_retry_at": "2026-07-16T12:15:00Z",
      "request_id": "a1b2c3d4-5e6f-7890-abcd-ef1234567890"
    }
  ],
  "message": "Webhook deliveries retrieved",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "d4e5f6a7-8b9c-0123-defa-456789012345",
  "timestamp": "2026-07-16T12:00:03Z"
}
```
The `qayd_webhook_delivery_failures_total{event="invoice.paid",http_status="504"}` counter
increments for this delivery; if the same receiving endpoint accumulates >5% failures over 15
minutes, `WebhookDeliveryFailureRate` fires and the on-call engineer contacts the customer's
integration owner with this exact delivery log as evidence, rather than a vague "webhooks are
failing" report.

## Example 4 — Diagnosing an AI Confidence Drift Alert

`AIConfidenceDrift` fires for `agent_type="OCRAgent"`. The AI/ML on-call queries:

```promql
avg_over_time(qayd_ai_confidence_score{agent_type="OCRAgent"}[1h])
avg_over_time(qayd_ai_confidence_score{agent_type="OCRAgent"}[7d])
```

A 1-hour average of 0.61 against a 7-day baseline of 0.89 indicates a real regression. Cross-check
against the "Business/Domain Health" dashboard's OCR-specific ingestion volume: if a single large
tenant just began uploading a new document format (e.g., scanned receipts vs. digital PDFs), this
is a **data distribution shift**, not a model bug — the runbook directs adding that tenant's
document type to the OCR training/eval set rather than rolling back a deploy.

## Example 5 — Curl Snippet for Manual Health Verification During an Incident

```bash
curl -s -o /dev/null -w "%{http_code} %{time_total}s\n" https://api.qayd.com/health/ready
curl -s https://api.qayd.com/api/v1/status | jq '.data'
```

# End of Document
