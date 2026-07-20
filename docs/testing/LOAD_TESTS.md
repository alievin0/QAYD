# Load & Performance Tests — QAYD Testing
Version: 1.0
Status: Design Specification
Module: Testing
Submodule: LOAD_TESTS
---

# Purpose

This document specifies how QAYD proves it stays fast and correct under load — not on an empty
demo tenant, but under realistic concurrent traffic across many companies, with the heavy endpoints
(report generation, ledger search, reconciliation, payroll runs, AI proposals) doing the work that
makes a financial platform slow. Where [`../api/API_TESTING.md`](../api/API_TESTING.md) names k6 as
the performance tier at the top of its pyramid, this document owns that tier in full: the service
level objectives (SLOs) and per-endpoint-class latency budgets, the throughput targets, the soak /
spike / stress profiles, per-tenant load isolation, how the database, queue, and Redis behave under
pressure, how the AI engine's load and cost are measured, the pass/fail thresholds that gate a
release, and how the runs are scheduled in CI.

A performance regression in QAYD is not a slow page; it is a CFO waiting on a report that used to be
instant, a payroll run that times out on release day, or — worst — a heavy report query on one
tenant starving every other tenant sharing the connection pool. The load suite exists to catch those
before a customer does, and to enforce that "fast" is a measured, budgeted, regression-gated property
of the API, not a hope.

Three platform facts shape every profile below, inherited from
[`../backend/SERVICE_ARCHITECTURE.md`](../backend/SERVICE_ARCHITECTURE.md) and
[`../ai/AI_FINANCE_OS.md`](../ai/AI_FINANCE_OS.md):

1. **Reads route to replicas; writes and their audit rows commit together on the primary.** A load
   profile that only reads and a profile that moves money stress genuinely different paths, and are
   budgeted separately — a report read has no business contending with a journal post's transaction.
2. **Every list is cursor-paginated and every tenant index leads with `company_id`.** Load tests are
   written knowing the sargable path exists; a profile that produces a full table scan is a *finding*
   (a missing composite index), not an accepted cost.
3. **The AI engine is a separate FastAPI service reached out-of-process, and its work costs model
   inference — measured in latency *and* money.** AI load is budgeted on both axes; a profile that
   ignores token cost is incomplete.

# Scope

## In scope

- SLOs and latency budgets per endpoint class (list, detail, write, report-gen, ledger-search,
  reconciliation, payroll-run, AI-proposal).
- Throughput targets (requests/sec sustained and peak) sized to the platform's target tenant count.
- The four load profiles: baseline, soak, spike, stress — and what each proves.
- The heavy endpoints, each with its own scenario, dataset scale, and threshold.
- Per-tenant load isolation: one heavy tenant must not degrade the p95 of others.
- Datastore behavior under load: PostgreSQL (primary + replica, PgBouncer), Redis (cache + queue +
  locks), the queue workers (`realtime`/`default`/`ai`/`reports` lanes).
- AI-engine load and cost: concurrency, queue depth, inference latency, and KWD-denominated token
  spend per 1,000 proposals.
- Pass/fail thresholds and the CI/scheduled cadence.

## Out of scope (owned elsewhere)

- Correctness of a single request (envelope, permission, double-entry math) — owned by
  [`../api/API_TESTING.md`](../api/API_TESTING.md)'s feature tests and
  [`./SECURITY_TESTS.md`](./SECURITY_TESTS.md).
- Browser rendering performance (Web Vitals, bundle size) — owned by the frontend performance budget
  in [`../frontend/README.md`](../frontend/README.md); this document loads the **API**, not the DOM.
- AI answer *quality* under load (does accuracy hold as concurrency rises) — owned by
  [`./AI_EVALUATION.md`](./AI_EVALUATION.md); this document measures AI *throughput, latency, and
  cost*, not correctness.
- Database index design and query plans in the abstract — owned by
  [`../database/DATABASE_PERFORMANCE.md`](../database/DATABASE_PERFORMANCE.md) and
  [`../database/DATABASE_INDEXING.md`](../database/DATABASE_INDEXING.md); load tests *exercise* those
  designs and report where they hold or fail.

# Tooling

| Concern | Tool | Detail |
|---|---|---|
| Load generator | k6 (Grafana k6, JS scenarios) | The platform standard, aligned with [`../api/API_TESTING.md`](../api/API_TESTING.md). Scenarios in `tests/load/`, one file per endpoint class + one composite. |
| Alternative considered | Gatling (Scala/Java) | Evaluated and kept as a fallback for teams already on the JVM; not the committed default. Any Gatling scenario must reproduce the same thresholds this document fixes — the SLOs are tool-independent. |
| Metrics store | Prometheus + Grafana | k6 pushes custom metrics via `remote-write`; the same Grafana that renders production Action/transaction metrics (per [`../backend/SERVICE_ARCHITECTURE.md`](../backend/SERVICE_ARCHITECTURE.md) `# Observability`) renders the load run, so a load p95 and a production p95 read on one dashboard. |
| Server-side truth | Prometheus counters/histograms already emitted per Action | Load tests do not trust only the generator's client-side timing; they correlate against the server's own `http_request_duration_seconds` histogram and per-Action metrics, so a number is confirmed from both ends. |
| Datastore telemetry | `pg_stat_statements`, PgBouncer stats, Redis `INFO`, queue depth gauges | Captured for the window of every run and attached to the report — a latency regression is diagnosed (a slow query, pool saturation, queue backlog), not merely observed. |
| AI cost telemetry | `ai_tasks.latency_ms` + token accounting from the FastAPI engine | Per-run token spend is summed from the engine's own accounting and rendered in KWD; see `# AI-engine load & cost`. |
| Seeding at scale | A dedicated load-seed command | Provisions N tenants at graduated data volumes (a "large" tenant with 500k `journal_lines`, a "typical" one with 40k) via the same API/factory path, never raw inserts that would skip the invariants under test. |
| CI | GitHub Actions (scheduled) + a release gate | Baseline runs nightly; the full profile suite runs pre-release; thresholds fail the job (`k6` exits non-zero when a threshold breaches). |

k6's `thresholds` are the pass/fail mechanism: a scenario declares its budget as a threshold, and k6
exits non-zero (failing CI) when the budget is breached. This is what makes the SLOs below
executable rather than aspirational.

# Conventions & Structure

## Folder layout

```text
tests/load/
├── lib/
│   ├── envelope.js         # unwraps the standard { success, data, meta, ... } response
│   ├── auth.js             # obtains a service/session token per virtual tenant, sets X-Company-Id
│   ├── tenants.js          # the seeded tenant roster (large / typical / small) + weighting
│   └── thresholds.js       # the single source of the per-class latency budgets (imported everywhere)
├── scenarios/
│   ├── list.js             # list endpoints (invoices, journal entries, ledger pages)
│   ├── detail.js           # single-record reads
│   ├── write.js            # journal post, invoice issue (primary, transactional, idempotency-keyed)
│   ├── report-gen.js       # financial statements, aged receivables (queued, poll-for-result)
│   ├── ledger-search.js    # GET /accounting/ledger-entries/search across dimensions
│   ├── reconciliation.js   # the matching sweep + confirm
│   ├── payroll-run.js      # calculate + release a payroll run
│   └── ai-proposal.js      # the AI proposal round-trip (engine + callback)
├── profiles/
│   ├── baseline.js         # steady expected load — the nightly gate
│   ├── soak.js             # 2h at expected load — leak/creep detection
│   ├── spike.js            # sudden 10x burst — recovery behavior
│   └── stress.js           # ramp past capacity — find the knee, prove graceful degradation
└── isolation/
    └── noisy-neighbor.js   # one heavy tenant + many typical tenants; assert others' p95 holds
```

## Naming & the shared threshold source

Every latency budget lives in exactly one file, `lib/thresholds.js`, imported by every scenario, so a
budget is defined once and can never drift between the scenario and the report.

```js
// tests/load/lib/thresholds.js — the single source of the SLOs
export const BUDGETS = {
  list:           { p95: 300,  p99: 600  },   // ms
  detail:         { p95: 250,  p99: 500  },
  write:          { p95: 500,  p99: 1000 },   // transactional; includes the audit-row commit
  ledgerSearch:   { p95: 800,  p99: 1500 },
  reportGen:      { p95: 3000, p99: 8000 },   // enqueue→result; a report is allowed to be slow, but bounded
  reconciliation: { p95: 1200, p99: 2500 },
  payrollRun:     { p95: 5000, p99: 12000 },  // calculate; release is a separate gated write
  aiProposal:     { p95: 4000, p99: 9000 },   // includes model inference; see AI-engine section
};

// Translated into k6 threshold expressions, tagged per scenario.
export const k6Thresholds = (cls) => ({
  [`http_req_duration{class:${cls}}`]: [
    `p(95)<${BUDGETS[cls].p95}`,
    `p(99)<${BUDGETS[cls].p99}`,
  ],
  http_req_failed: ['rate<0.01'],             // <1% error rate is a hard floor for every scenario
});
```

## Every request goes through the envelope + tenant helpers

A load VU (virtual user) authenticates as a specific seeded tenant and tags every request with its
endpoint class and its tenant tier, so the report can slice p95 by class *and* by tenant size — the
two dimensions a financial-platform performance question is always asked along.

```js
// tests/load/scenarios/list.js
import http from 'k6/http';
import { check } from 'k6';
import { pickTenant } from '../lib/tenants.js';
import { authHeaders } from '../lib/auth.js';
import { k6Thresholds } from '../lib/thresholds.js';

export const options = { thresholds: k6Thresholds('list') };

export default function () {
  const tenant = pickTenant();                       // weighted: mostly typical, some large
  const res = http.get(
    `${__ENV.API_BASE}/api/v1/accounting/journal-entries?per_page=25`,
    { headers: authHeaders(tenant), tags: { class: 'list', tier: tenant.tier } },
  );
  check(res, {
    'status 200': (r) => r.status === 200,
    'enveloped': (r) => r.json('success') === true,
    'cursor present': (r) => r.json('meta.pagination') !== null,
  });
}
```

# Patterns

## Budget by endpoint class, not by endpoint

QAYD has hundreds of endpoints but a handful of *classes*, and each class has one budget. A new list
endpoint inherits the `list` budget the day it ships; it does not need a new number negotiated. This
is what keeps the SLO table small enough to enforce and stable enough to trust.

## Correlate client timing with server timing

A k6 threshold is a client-side measurement (it includes network to the load generator). Every run
also queries the server's own `http_request_duration_seconds` histogram for the same window; a gap
between the two isolates the cost to the network vs. the application. A pass requires *both* the k6
threshold and the server-side p95 to be within budget — a fast server behind a saturated network is
still a failed run, and the correlation says which to fix.

## Realistic mix, realistic data

The composite `baseline` profile weights scenarios by production traffic shape (reads dominate,
writes are a minority, report-gen and payroll are bursty and periodic), and draws tenants weighted by
size (mostly typical, a few large). A load run against a uniform tenant of empty tables measures
nothing real; the seed provisions the large tenant with 500k `journal_lines` precisely so
`ledger-search` and `report-gen` hit the volumes that expose a missing composite index.

## Poll, don't block, for async work

Report generation and payroll calculation are queued (`GenerateReport` lands on the `reports` queue
per [`../backend/SERVICE_ARCHITECTURE.md`](../backend/SERVICE_ARCHITECTURE.md)). The scenario measures
*enqueue → result-ready* by polling the run's status endpoint, so the budget covers the real
user-perceived latency (the wait for the finished report), not just the 202 that accepted the job.

```js
// tests/load/scenarios/report-gen.js — enqueue then poll to completion
import http from 'k6/http';
import { check, sleep } from 'k6';
import { authHeaders } from '../lib/auth.js';
import { pickTenant } from '../lib/tenants.js';
import { k6Thresholds } from '../lib/thresholds.js';
import { Trend } from 'k6/metrics';

const enqueueToResult = new Trend('report_enqueue_to_result_ms', true);
export const options = { thresholds: { ...k6Thresholds('reportGen'), 'report_enqueue_to_result_ms': ['p(95)<3000'] } };

export default function () {
  const tenant = pickTenant();
  const start = Date.now();
  const enqueue = http.post(`${__ENV.API_BASE}/api/v1/financial-statements/balance-sheet/generate`,
    JSON.stringify({ period: '2026-06' }),
    { headers: authHeaders(tenant), tags: { class: 'reportGen', tier: tenant.tier } });
  const runId = enqueue.json('data.report_run_id');

  let done = false;
  for (let i = 0; i < 20 && !done; i++) {                 // bounded poll
    sleep(0.5);
    const status = http.get(`${__ENV.API_BASE}/api/v1/reports/runs/${runId}`, { headers: authHeaders(tenant) });
    done = status.json('data.status') === 'completed';
  }
  enqueueToResult.add(Date.now() - start);
  check(null, { 'report completed within budget': () => done });
}
```

# What to Test / Coverage

## SLOs / latency budgets per endpoint class

The canonical budget table (mirrored in `lib/thresholds.js`). All figures are server-observed p95/p99
in milliseconds, under the `baseline` profile, on a large tenant unless noted.

| Endpoint class | Example | p95 budget | p99 budget | Notes |
|---|---|---|---|---|
| List | `GET /accounting/journal-entries` | 300 ms | 600 ms | Cursor-paginated; must stay flat as `company_id` data grows (index leads with `company_id`). |
| Detail | `GET /approvals/{id}` | 250 ms | 500 ms | Single-record read, replica-served. |
| Write (transactional) | `POST /accounting/journal-entries` (post) | 500 ms | 1000 ms | Includes the in-transaction audit-row commit and idempotency-key insert. |
| Ledger search | `GET /accounting/ledger-entries/search` | 800 ms | 1500 ms | Multi-dimension (cost center, project, period); the classic full-scan risk. |
| Report generation | balance sheet / P&L / aged receivables | 3000 ms | 8000 ms | Enqueue→result; bounded, may be slow, must never be unbounded. |
| Reconciliation | matching sweep + confirm | 1200 ms | 2500 ms | Match-candidate query per unmatched line; scales with statement size. |
| Payroll run | calculate a run | 5000 ms | 12000 ms | Per-employee computation; *release* (disbursement) is a separate gated write, budgeted as `write`. |
| AI proposal | proposal round-trip incl. inference | 4000 ms | 9000 ms | Dominated by model latency; see `# AI-engine load & cost`. |

Two hard floors apply to **every** scenario regardless of class: `http_req_failed` rate `< 1%`, and
zero `5xx` responses attributable to the application (a `503 ai_engine_unavailable` from a
deliberately-loaded AI engine is counted separately, not as an application failure).

## Throughput targets

Sized to the platform's near-term target of low-thousands of active tenants, with a documented
headroom multiple so a growth spike does not immediately breach.

| Measure | Baseline target | Peak (spike) target |
|---|---|---|
| Sustained request rate (all classes) | 1,500 req/s | 6,000 req/s (10x baseline burst absorbed, recovering in <60 s) |
| Concurrent virtual users | 2,000 | 8,000 |
| Write rate (primary, transactional) | 120 writes/s | 400 writes/s |
| Report generations in flight | 50 concurrent | 150 concurrent (queue absorbs the rest; no drop) |
| AI proposals in flight | 30 concurrent | 100 concurrent (engine queue depth bounded; see below) |

## The heavy endpoints

Each heavy endpoint has a dedicated scenario, a dataset scale that provokes its worst case, and a
threshold that fails the run if breached.

- **Report generation** — run against the large tenant's full period; the risk is an aggregation that
  scans `journal_lines` without a period/company composite index. Threshold: `reportGen` budget, and
  a correlated check that the underlying query used an index (from `pg_stat_statements`, not a
  sequential scan).
- **Ledger search** — the multi-dimension filter is the single most likely full-scan path; the
  scenario sweeps combinations of account + cost center + project + date range on the 500k-line
  tenant. A p95 regression here is triaged directly against
  [`../database/DATABASE_INDEXING.md`](../database/DATABASE_INDEXING.md).
- **Reconciliation** — the matching-candidate query runs once per unmatched line; the scenario loads a
  1,000-line statement and measures the sweep. The budget must hold as statement size grows linearly,
  not quadratically — a quadratic curve is a finding.
- **Payroll run** — per-employee calculation on a large headcount tenant; the scenario calculates (not
  releases) so it is repeatable without moving money. Release is exercised once, single-shot, as a
  transactional `write`.

## Per-tenant load isolation (the noisy-neighbor test)

The single most important non-latency property: **one heavy tenant must not degrade another's p95.**
The `isolation/noisy-neighbor.js` profile runs one tenant hammering `report-gen` and `ledger-search`
at stress volume while a fleet of typical tenants runs the baseline mix, and asserts the *typical*
tenants' p95 stays within their normal budget.

```js
// tests/load/isolation/noisy-neighbor.js
export const options = {
  scenarios: {
    noisy: {                                   // one tenant, heavy
      executor: 'constant-vus', vus: 200, duration: '10m',
      exec: 'heavyTenant', tags: { role: 'noisy' },
    },
    neighbors: {                               // many tenants, normal
      executor: 'constant-arrival-rate', rate: 1000, timeUnit: '1s',
      duration: '10m', preAllocatedVUs: 1500, exec: 'typicalTenants', tags: { role: 'neighbor' },
    },
  },
  thresholds: {
    // The isolation guarantee: neighbors keep their budget even while the noisy tenant is at stress.
    'http_req_duration{role:neighbor,class:list}': ['p(95)<300'],
    'http_req_duration{role:neighbor,class:detail}': ['p(95)<250'],
  },
};
```

This proves in load what [`../backend/SERVICE_ARCHITECTURE.md`](../backend/SERVICE_ARCHITECTURE.md)
asserts in design: reporting reads route to replicas and the `reports` queue lane is separate, so a
tenant's report storm consumes replica + `reports`-worker capacity, not the primary write path or the
`realtime`/`default` lanes the neighbors depend on.

## Datastore, queue, and Redis under load

- **PostgreSQL** — primary write latency, replica read lag, and PgBouncer transaction-pool
  saturation are captured for every run. The load suite specifically stresses the pool under the
  `SET LOCAL app.current_company_id` transaction-scoped tenant GUC (per
  [`../database/MULTI_TENANCY.md`](../database/MULTI_TENANCY.md)) to prove RLS scoping holds — and
  stays fast — under transaction pooling, not just session pooling.
- **Redis** — cache hit ratio, idempotency-lock contention (the 30 s Redis lock from
  [`../backend/SERVICE_ARCHITECTURE.md`](../backend/SERVICE_ARCHITECTURE.md) `# Idempotency`), and
  queue depth per lane. A spike run asserts the cache absorbs the read burst rather than passing it
  to the primary.
- **Queues** — depth and drain rate per lane (`realtime`, `default`, `ai`, `reports`,
  `integrations`, `maintenance`). The spike profile asserts that a report/AI burst grows the
  `reports`/`ai` lanes (which is fine — they absorb it) without growing `realtime` (which must stay
  drained so the UI stays live). A lane that never drains within the run is a capacity finding.

## AI-engine load & cost

The AI engine is loaded on three axes at once — concurrency, latency, and **money** — because a
proposal that is fast but doubles token spend is still a regression.

| Metric | Budget | Source |
|---|---|---|
| Proposal round-trip p95 | 4,000 ms (`aiProposal`) | k6 + `ai_tasks.latency_ms` |
| Engine queue depth (`ai` lane) under peak | drains within the run; never unbounded | queue gauge |
| Concurrency ceiling | 100 in-flight proposals absorbed with graceful `503 ai_engine_unavailable` beyond, never a crash | k6 error taxonomy |
| Token spend per 1,000 proposals | tracked, alert on >15% run-over-run drift | engine token accounting → KWD |
| Backpressure correctness | when the engine is saturated, the platform returns `503` on AI-only paths and the human path stays fully available | correlated scenario |

The last row is the load-time expression of a safety property: under AI overload the platform sheds
AI load (a `503` the UI renders as `AiUnavailable`), it never blocks a human's journal post or
approval waiting on a saturated model. The `ai-proposal.js` scenario ramps past the concurrency
ceiling specifically to prove that shedding happens cleanly.

# CI Integration

## Profiles and cadence

| Profile | Shape | Cadence | Gate |
|---|---|---|---|
| `baseline` | Steady expected mix, 10 min | Nightly | **Gating** — a p95/p99 or error-rate threshold breach fails the nightly build and alerts. |
| `soak` | Expected load, 2 h | Weekly | Gating on leak signals — memory/connection creep, a slowly-growing p95, an ever-growing queue. |
| `spike` | 10x burst then recover | Pre-release | Gating — must absorb the burst and return to baseline p95 within 60 s. |
| `stress` | Ramp past capacity to the knee | Pre-release | **Informational + guardrail** — records the capacity ceiling and asserts *graceful* degradation (bounded latency growth, `429`/`503` not `5xx`), never a hard threshold that would flake. |
| `noisy-neighbor` | Isolation | Pre-release | Gating — neighbors keep their budget. |

## Invocation

```bash
# nightly gate
k6 run --out experimental-prometheus-rw tests/load/profiles/baseline.js \
  -e API_BASE="$STAGING_API" -e TENANT_SET=seeded

# pre-release sweep (each fails the job on threshold breach → k6 non-zero exit)
k6 run tests/load/profiles/spike.js       -e API_BASE="$STAGING_API"
k6 run tests/load/profiles/soak.js        -e API_BASE="$STAGING_API"
k6 run tests/load/isolation/noisy-neighbor.js -e API_BASE="$STAGING_API"
```

```yaml
# .github/workflows/load-nightly.yml (excerpt)
on:
  schedule: [{ cron: '0 2 * * *' }]        # nightly against staging
jobs:
  baseline:
    steps:
      - uses: actions/checkout@v4
      - name: Seed graduated tenants
        run: php artisan qayd:load-seed --tenants=large:5,typical:200,small:500
      - name: Run baseline profile
        run: k6 run tests/load/profiles/baseline.js -e API_BASE=${{ secrets.STAGING_API }}
        # k6 exits non-zero on a breached threshold → job fails → alert fires
      - name: Attach datastore telemetry
        if: always()
        run: ./scripts/collect-pg-redis-queue-stats.sh > load-report/telemetry.txt
      - uses: actions/upload-artifact@v4
        if: always()
        with: { name: load-report, path: load-report/ }
```

## Pass/fail thresholds

A run **passes** only when all of the following hold; any one failing fails the job:

1. Every scenario's k6 `http_req_duration{class:…}` p95 and p99 are within `BUDGETS`.
2. The server-side `http_request_duration_seconds` p95 for the same window is within budget (the
   client/server correlation check).
3. `http_req_failed` rate `< 1%` and zero application `5xx`.
4. For `soak`: no monotonic upward drift in p95, memory, DB connections, or queue depth over the 2 h.
5. For `spike`: recovery to baseline p95 within 60 s of the burst ending.
6. For `noisy-neighbor`: the neighbor tenants' p95 stays within their normal list/detail budgets.
7. AI token spend per 1,000 proposals within 15% of the prior release's figure.

A run against production is never a gate — load is generated against the seeded staging tenant set
only, so a load test can never move real money or contend with a real customer's traffic.

# Examples

**A composite baseline profile weighting scenarios by production shape:**

```js
// tests/load/profiles/baseline.js
import { k6Thresholds } from '../lib/thresholds.js';
export { default as list } from '../scenarios/list.js';
export { default as detail } from '../scenarios/detail.js';
export { default as write } from '../scenarios/write.js';
export { default as reportGen } from '../scenarios/report-gen.js';
export { default as aiProposal } from '../scenarios/ai-proposal.js';

export const options = {
  scenarios: {
    reads_list:   { executor: 'constant-arrival-rate', rate: 900, timeUnit: '1s', duration: '10m', preAllocatedVUs: 1200, exec: 'list' },
    reads_detail: { executor: 'constant-arrival-rate', rate: 450, timeUnit: '1s', duration: '10m', preAllocatedVUs: 600,  exec: 'detail' },
    writes:       { executor: 'constant-arrival-rate', rate: 120, timeUnit: '1s', duration: '10m', preAllocatedVUs: 300,  exec: 'write' },
    reports:      { executor: 'constant-vus',          vus: 50,  duration: '10m', exec: 'reportGen' },
    ai:           { executor: 'constant-vus',          vus: 30,  duration: '10m', exec: 'aiProposal' },
  },
  thresholds: {
    ...k6Thresholds('list'), ...k6Thresholds('detail'), ...k6Thresholds('write'),
    ...k6Thresholds('reportGen'), ...k6Thresholds('aiProposal'),
  },
};
```

**A transactional write scenario carrying an idempotency key (a retry must never double-post):**

```js
// tests/load/scenarios/write.js
import http from 'k6/http';
import { check } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';
import { authHeaders } from '../lib/auth.js';
import { pickTenant } from '../lib/tenants.js';
import { k6Thresholds } from '../lib/thresholds.js';

export const options = { thresholds: k6Thresholds('write') };

export default function () {
  const tenant = pickTenant();
  const res = http.post(`${__ENV.API_BASE}/api/v1/accounting/journal-entries`,
    JSON.stringify({ entry_date: '2026-07-14', lines: [
      { account_code: '5130', debit: '10.000', credit: '0.000' },
      { account_code: '2110', debit: '0.000',  credit: '10.000' },
    ]}),
    { headers: { ...authHeaders(tenant), 'Idempotency-Key': `load-${uuidv4()}` },
      tags: { class: 'write', tier: tenant.tier } });
  check(res, {
    'created 201': (r) => r.status === 201,
    'balanced (no 422)': (r) => r.status !== 422,
  });
}
```

# Edge Cases

| Edge case | Load-suite handling |
|---|---|
| Cold cache at run start | The baseline profile includes a 60 s warm-up ramp excluded from the threshold window, so a first-request cache miss does not fail a run that is otherwise healthy. |
| A large tenant's report vs. everyone else | Directly the `noisy-neighbor` profile — the neighbor budget is the pass criterion; a shared-primary regression here is the highest-priority finding. |
| PgBouncer transaction-pool exhaustion under a write spike | The `spike` write scenario asserts writes degrade to bounded queueing / `429`, never `500`; pool stats are captured to confirm the ceiling was the pool, not the primary CPU. |
| Idempotency-lock contention | A scenario replays the *same* idempotency key concurrently and asserts one `201` + the rest `409 IDEMPOTENCY_IN_PROGRESS`/replay — correctness under load, not just speed. |
| Queue lane starvation | The spike profile asserts a report/AI burst never grows the `realtime` lane; a `realtime` backlog under a `reports` storm is a lane-isolation defect. |
| AI engine saturated | `ai-proposal.js` ramps past the concurrency ceiling and asserts clean `503` shedding on AI-only paths with the human write path unaffected — an overload must degrade AI, never the ledger. |
| RLS under transaction pooling | The isolation profile runs enough concurrency to force PgBouncer to interleave transactions and asserts zero cross-tenant rows returned — a leak under pooling would be both a security and a correctness failure ([`./SECURITY_TESTS.md`](./SECURITY_TESTS.md) owns the functional isolation assertion; this proves it holds *at load*). |
| Token-cost regression | A run that meets latency but exceeds the per-1,000-proposal cost budget by >15% fails — a faster-but-pricier model change is caught before it reaches the bill. |
| Clock/timezone skew across generators | All k6 runners and the API pin `Asia/Kuwait`; period-boundary report scenarios use a fixed seeded period so a midnight-crossing run is deterministic. |
| Soak-only leaks invisible in baseline | The 2 h `soak` profile exists precisely for the creep a 10 min baseline cannot see — a slowly-leaking connection or an unbounded cache; its pass criterion is *flatness*, not just absolute p95. |
| Non-representative synthetic data | The load-seed uses graduated real-shaped volumes (500k / 40k / small `journal_lines`) via the API path, so index behavior under test matches production, not an artificially uniform table. |

# End of Document
