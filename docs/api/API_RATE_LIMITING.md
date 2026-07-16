# API Rate Limiting — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API_RATE_LIMITING
---

# Purpose

QAYD is a multi-tenant AI Financial Operating System: a single Laravel 12 (PHP 8.4+) API cluster
serves every company on the platform, from a solo bookkeeper on the Free plan to an Enterprise
group running consolidated multi-branch accounting, payroll, and inventory across a shared pool of
application servers, PostgreSQL connections, and Redis memory. Nothing in QAYD bypasses this API —
not the Next.js web client, not the Flutter mobile app, not the FastAPI AI engine, not third-party
integrations holding an API key. Every one of those callers converges on the same finite compute,
database connection pool, and Redis cluster, which means a single company (or a single
misconfigured integration, or a single AI agent stuck in a retry loop) can, without a rate limiter,
degrade latency and error rate for every other tenant sharing the cluster at that moment.

This document specifies the rate-limiting subsystem that prevents that outcome: the algorithm
(Redis-backed GCRA, a token-bucket variant chosen for its O(1) memory footprint and atomic
single-round-trip evaluation), the four independent dimensions a request is evaluated against (IP,
authenticated user, tenant/company, API key), how those dimensions compose with the plan a company
is subscribed to (`company_subscriptions.plan_code`: `free`, `starter`, `growth`, `enterprise`),
how a temporary burst of legitimate traffic is distinguished from sustained overuse, how a monthly
plan quota differs from a per-minute throttle and what happens when a paying tenant exceeds it, the
exact `X-RateLimit-*` response headers and `429 Too Many Requests` payload every client integrates
against, and the Prometheus metrics, alerts, and dashboards that let QAYD's platform team see a
rate-limiting problem — abuse, misconfiguration, or simple undersizing of a tenant's plan — before
it becomes a support ticket.

This document assumes and reuses, without modification, the platform facts established in the
shared design context and in sibling API-layer documents: the standard response envelope, the
`/api/v1/` versioning scheme, the `Authorization: Bearer` + `X-Company-Id` request shape, the
`RATE_LIMITED` error code and its `429`/`Retry-After` contract already defined in
`API_ERROR_HANDLING.md`, the `api_keys` table and its `rate_limit_tier` column already defined in
`AUTHENTICATION_API.md`, and the `qayd_rate_limit_rejections_total` metric and `RateLimitSpike`
alert already defined in `API_MONITORING.md`. Where this document introduces a number, a table, a
metric, or an error code that does not exist elsewhere, it is called out explicitly as an addition
owned by this document; where it reuses something defined elsewhere, it does not redefine it, only
cross-references it. This document is the single authoritative source for: the exact numeric
limits per plan and per scope, the Redis key schema and algorithm used to enforce them, and the
semantics of monthly quota overage. General error taxonomy beyond the two throttling-specific codes
remains owned by `API_ERROR_HANDLING.md`; general observability infrastructure (Golden Signals,
tracing, SLOs, dashionards other than the ones this document adds) remains owned by
`API_MONITORING.md`.

# Rate Limits

## Algorithm: GCRA over Redis, not Laravel's default fixed-window limiter

Laravel ships a serviceable rate limiter out of the box (`Illuminate\Cache\RateLimiter`, driving
the `throttle:` middleware) built on a fixed-window or sliding-window counter in whatever cache
store is configured. QAYD does not use that default implementation for tenant, user, or API-key
throttling, for two concrete reasons that matter at QAYD's scale:

1. **Fixed windows allow a 2x burst at window boundaries.** A `perMinute(300)` limiter implemented
   as "reset the counter every wall-clock minute" allows 300 requests in the last second of minute
   N and another 300 in the first second of minute N+1 — 600 requests in two seconds, twice the
   intended rate. For a financial system sizing its Postgres connection pool and PHP-FPM/Octane
   worker count against a *guaranteed* ceiling, this is not an acceptable approximation.
2. **A single Laravel cache `increment()` + `expire()` pair is two round trips and not atomic**
   under concurrent requests from the same key without an additional lock, which itself adds
   latency to every single API call — unacceptable on the hot path of every request in the system.

QAYD instead implements the **Generic Cell Rate Algorithm (GCRA)**, the same class of algorithm
used by Stripe's and Twilio's public rate limiters, executed as a single Redis Lua script per
request (one round trip, fully atomic, no separate lock). GCRA models each bucket not as a counter
but as a **Theoretical Arrival Time (TAT)**: the Redis value stored per key is the time at which the
bucket would be "full" again assuming no further requests arrive. Every request compares "now" to
the TAT, decides allow/deny, and — only on allow — advances the TAT by one emission interval. This
gives an exact, smooth sustained rate with a precisely bounded burst allowance (see `# Burst
Limits`), in a single `EVAL` call, with the key's own TTL cleaning up idle buckets automatically (a
tenant that stops calling the API for a day leaves no Redis footprint from this subsystem).

## The four dimensions

Every authenticated request is evaluated against up to four independent buckets. **The most
restrictive applicable bucket wins** — a request is only allowed through if it clears every bucket
that applies to it:

| Scope | Applies to | Key pattern | Owner of the limit |
|---|---|---|---|
| `ip` | Every request, but only enforced meaningfully on public (pre-auth) endpoints — see `# User Limits` for why authenticated routes de-prioritize this scope | `ratelimit:ip:{ip_address}:{route_name\|'global'}` | This document (`# Rate Limits` / `# Burst Limits`) |
| `user` | Every request carrying a human-issued access token (Sanctum/JWT), scoped per active company so a user's activity in Company A never consumes their budget in Company B | `ratelimit:user:{user_id}:{company_id}` | `# User Limits` |
| `company` | Every request scoped to a company via `X-Company-Id`/`ResolveCompanyContext`, aggregating **all** users and API keys belonging to that company — the plan-driven ceiling | `ratelimit:company:{company_id}:{route_class}` | `# Tenant Limits`, plan numbers in `# Plan Quotas` |
| `api_key` | Every request authenticated via an `api_keys` row (machine/integration client) instead of a human Sanctum session | `ratelimit:key:{api_keys.id}:{route_class}` | `# API Keys` |

`{route_class}` is not the literal route name for every scope — see `## Route Weighting` below for
how routes are grouped into a small number of classes so that the number of Redis keys stays
bounded and predictable regardless of how many distinct endpoints QAYD ships.

## Redis topology

Rate-limit state is **not** stored in the `cache` Redis logical database described in
`DATABASE_CACHING.md` (`REDIS_CACHE_DB=1`, `allkeys-lru`). Mixing high-churn, short-TTL counters
into an LRU-evictable cache pool would mean a cache-memory-pressure eviction could silently reset a
tenant's throttle state (letting a burst through un-metered) or, in the other direction, a
rate-limit key storm could evict genuinely useful cached Trial Balances under memory pressure.
`DATABASE_CACHING.md` allocates logical databases 1–4 (`cache`, `session`, `queue`,
`broadcasting`); this document additively claims the next unallocated one:

```
# .env addition (production) — owned by this document
REDIS_RATELIMIT_DB=5
```

```php
// config/database.php — 'redis' block, new 'ratelimit' connection alongside cache/session/queue
'ratelimit' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_RATELIMIT_DB', '5'),
    'read_timeout' => 0.5,      // fail fast — see "Fail-Open Policy" below
    'persistent' => true,
],
```

Memory policy for logical DB 5 is `volatile-ttl`: every key this subsystem writes carries a TTL by
construction (the GCRA script always sets one — see below), so `volatile-ttl` and `allkeys-lru`
would behave identically in practice, but `volatile-ttl` is the explicit, self-documenting choice
that also makes a programming error (a key written without a TTL) immediately visible in
`redis-cli --bigkeys` output rather than silently surviving forever under an LRU policy.

## The GCRA Lua script

```lua
-- rate_limit_gcra.lua
-- KEYS[1]        = bucket key, e.g. "ratelimit:company:412:global"
-- ARGV[1]        = emission_interval (seconds/token, float) = period_seconds / sustained_rate
-- ARGV[2]        = burst_offset (seconds, float) = burst_capacity * emission_interval
-- ARGV[3]        = now (seconds since epoch, float, high precision)
-- ARGV[4]        = cost (integer token weight of this request; see Route Weighting)
-- returns        = { allowed (0|1), retry_after_seconds (float), remaining_tokens (int) }

local key              = KEYS[1]
local emission_interval = tonumber(ARGV[1])
local burst_offset      = tonumber(ARGV[2])
local now               = tonumber(ARGV[3])
local cost              = tonumber(ARGV[4])

local tat = tonumber(redis.call('GET', key))
if tat == nil or tat < now then
  tat = now
end

local increment = emission_interval * cost
local new_tat    = tat + increment
local allow_at   = new_tat - burst_offset

if allow_at > now then
  -- Over limit: do not advance the TAT, caller is charged nothing for a rejected request.
  local retry_after = allow_at - now
  local remaining   = math.floor((burst_offset - (tat - now)) / emission_interval)
  return { 0, retry_after, math.max(remaining, 0) }
else
  local ttl = math.ceil(new_tat - now)
  redis.call('SET', key, new_tat, 'EX', math.max(ttl, 1))
  local remaining = math.floor((burst_offset - (new_tat - now)) / emission_interval)
  return { 1, 0, math.max(remaining, 0) }
end
```

This script is loaded once via `SCRIPT LOAD` at deploy time and invoked with `EVALSHA` (with a
`NOSCRIPT`-triggered fallback to `EVAL` on cache miss after a Redis restart), so the hot path never
pays the cost of re-parsing the script body.

## The PHP service

```php
namespace App\Services\RateLimit;

final class GcraLimiter
{
    public function __construct(
        private readonly \Illuminate\Redis\Connections\Connection $redis, // 'ratelimit' connection
        private readonly string $scriptSha,
    ) {}

    public function hit(string $key, float $sustainedRatePerSecond, int $burstCapacity, int $cost = 1): RateLimitResult
    {
        $emissionInterval = 1 / $sustainedRatePerSecond;
        $burstOffset      = $burstCapacity * $emissionInterval;
        $now              = microtime(true);

        try {
            [$allowed, $retryAfter, $remaining] = $this->redis->evalSha(
                $this->scriptSha, 1, $key, $emissionInterval, $burstOffset, $now, $cost,
            );
        } catch (\RedisException $e) {
            return $this->failOpen($key, $e);
        }

        return new RateLimitResult(
            allowed: (bool) $allowed,
            retryAfterSeconds: (float) $retryAfter,
            remaining: (int) $remaining,
            limit: (int) round($sustainedRatePerSecond * 60),
            resetAt: (int) ceil($now + $retryAfter),
        );
    }
}
```

## Bridging into Laravel's native `throttle:` idiom

Every engineer who has worked in Laravel expects `RateLimiter::for()` and a `throttle:<name>` route
middleware. QAYD keeps that exact developer-facing surface — the GCRA/Redis machinery above is an
implementation detail behind it, registered once in `AppServiceProvider::boot()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('api-tenant', function (Request $request) {
    $company = $request->attributes->get('company');           // hydrated by ResolveCompanyContext
    $plan    = config("ratelimit.plans.{$company->plan_code}");

    return Limit::perMinute($plan['sustained_rpm'])
        ->by("company:{$company->id}")
        ->response(fn ($request, array $headers) =>
            app(RateLimitResponder::class)->reject($request, $headers));
});

RateLimiter::for('auth-public', function (Request $request) {
    $routeName = $request->route()?->getName() ?? 'global';
    $cfg = config("ratelimit.ip_defaults.{$routeName}", config('ratelimit.ip_defaults.global'));

    return Limit::perMinute($cfg['sustained_rpm'])->by('ip:' . $request->ip());
});
```

`RateLimiter::for()` is Laravel's configuration-time API; QAYD supplies a custom
`RedisGcraStore` that the named limiters resolve against instead of the framework's default
fixed-window cache driver, so `throttle:api-tenant` and `throttle:auth-public` read exactly like
stock Laravel to anyone joining the team, while every evaluation underneath is the atomic GCRA Lua
script above. Route registration:

```php
// routes/api.php
Route::middleware(['throttle:auth-public'])->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/auth/mfa/challenge', [AuthController::class, 'mfaChallenge'])->name('auth.mfa.challenge');
    Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->name('auth.password.forgot');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
});

Route::middleware(['auth:sanctum', 'company.context', 'throttle:api-tenant', 'throttle.user', 'throttle.key'])
    ->group(function () {
        Route::apiResource('accounting/journal-entries', JournalEntryController::class);
        // ...every authenticated /api/v1 route
    });
```

`throttle.user` and `throttle.key` are additional custom middleware aliases (not
`Illuminate\Routing\Middleware\ThrottleRequests`, because those two scopes need the
per-user-per-company and per-api-key-tier lookups described in `# User Limits` and `# API Keys`
respectively, which a single named `RateLimiter::for()` closure cannot cleanly express alongside
the company-scoped one on the same middleware call). All three throttle middleware share the same
underlying `GcraLimiter` service and Lua script; they differ only in which Redis key, sustained
rate, and burst capacity they pass in.

## Placement in the request pipeline

`AUTHENTICATION_API.md` defines the six-step middleware pipeline for every protected endpoint. This
document inserts two additional steps without renumbering the original six, so existing
cross-references to steps `[1]`–`[6]` in other documents remain valid:

```
Incoming request
   │
   ▼
[1]   ForceJsonResponse
   │
   ▼
[1.5] throttle:auth-public   — IP-scoped GCRA check, PUBLIC endpoints only (this document)
   │
   ▼
[2]   auth:sanctum           — verifies JWT signature, exp, jti not revoked (Redis)
   │
   ▼
[3]   ResolveCompanyContext  — reads X-Company-Id, hydrates $request->company()
   │
   ▼
[3.5] throttle:api-tenant, throttle.user, throttle.key   — company/user/key GCRA checks (this document)
   │
   ▼
[4]   Authorize (permission middleware)
   │
   ▼
[5]   FormRequest validation
   │
   ▼
[6]   Controller → Service → Repository
```

Rate limiting deliberately runs **before** permission checks (`[4]`) but **after** authentication
(`[2]`/`[3]`): an invalid or expired token is rejected with `401` before it ever touches a Redis
bucket (so an attacker spraying garbage tokens cannot use QAYD's own rate limiter as an amplifier
against a specific `user_id`'s bucket), while a validly authenticated caller who simply lacks
permission for an action is still charged for the call — a `403` still costs a token, because the
cost of evaluating and rejecting the request was still paid by the cluster.

## Fail-Open Policy

Every `GcraLimiter::hit()` call is wrapped in a hard 500ms read timeout against the dedicated
`ratelimit` Redis connection (`config/database.php` → `'read_timeout' => 0.5` shown above). If that
connection is unreachable or times out, QAYD **fails open** — the request is allowed through
un-throttled — rather than returning `503` for every API consumer because one subsystem's Redis
shard is unavailable. This is a deliberate, time-boxed decision, not an oversight:

- A total Redis outage already breaks session revocation checks and Horizon queues, both far more
  severe than a temporarily un-throttled API; the platform already has `HealthCheckFailing` (Sev-1,
  pages immediately, see `API_MONITORING.md` Runbook R-07) covering that scenario.
- Failing open is logged at `warning` level on every occurrence and increments
  `qayd_rate_limit_fail_open_total{scope}` (see `# Monitoring`); a sustained run of fail-opens for
  more than 30 seconds is itself paged via `RateLimiterDegraded` (Sev-2), because an attacker who
  can trigger real Redis unavailability on demand should not be able to convert that into a
  guaranteed unlimited-request window without anyone noticing.
- Fail-open never applies to the monthly **quota** check (`# Plan Quotas`), which is re-derived
  from the durable `api_usage_events` Postgres table (already defined in `API_MONITORING.md`) on a
  short cache refresh, not from the same Redis path — a Redis outage cannot be used to bypass
  billing-relevant quota enforcement, only the short-window throttle.

# Burst Limits

## Burst is a first-class GCRA parameter, not a separate mechanism

A common rate-limiter design mistake is bolting a "burst allowance" onto a counter as an
afterthought (a second counter, a second Redis key, a second round trip). GCRA does not need that:
burst capacity is the `burst_offset` term already present in the Lua script in `# Rate Limits`
(`ARGV[2]`), computed once as `burst_capacity * emission_interval`. Raising or lowering burst
capacity for a route class or a plan is a pure configuration change — no code path changes, no
second bucket to keep consistent with the first.

Concretely, `burst_offset` is the number of seconds of "banked" capacity the bucket tolerates ahead
of the steady-state pace before it starts rejecting. A bucket with a 1,200 req/min sustained rate
(`emission_interval = 0.05s`) and a burst capacity of 400 tokens has `burst_offset = 400 * 0.05 =
20s` — meaning up to 400 requests may arrive effectively instantaneously (limited only by network
and PHP-FPM/Octane concurrency, not by the limiter) before the bucket starts pacing every
subsequent request at one every 50ms.

## Why burst matters for QAYD specifically

`DATABASE_CACHING.md` already establishes that a single dashboard page load fires eight to twelve
parallel aggregate queries (Trial Balance summary, AR aging, cash position, top overdue invoices,
etc.), each of which is a distinct authenticated API call from the Next.js client. Without burst
tolerance, a sustained-rate-only limiter sized for realistic steady-state usage would reject half
of every dashboard's own page load, because twelve near-simultaneous requests look, in a naive
per-second view, like a 720 req/min instantaneous rate even though the user only loaded one page.
Burst capacity is what makes "one page load = allowed" and "one script hammering an endpoint in a
tight loop for ten minutes = throttled" both true under the same limiter.

## Worked timeline (Growth plan, company-scoped `global` bucket: 1,200 rpm sustained, burst 400)

```
t=0.00s   Dashboard page load fires 10 parallel requests.
          Bucket had been idle (TAT = now). All 10 consume burst_offset headroom.
          allow_at = new_TAT - burst_offset = (now + 10*0.05s) - 20s  =>  well below now => ALLOWED x10
          Remaining burst headroom after: 400 - 10 = 390 tokens.

t=0.00–2.00s   A misconfigured integration retries a failed webhook receiver acknowledgment
          in a tight loop with no backoff: ~800 requests/second for 2 seconds (1,600 requests).
          First ~390 requests (remaining burst) are ALLOWED.
          Requests 391 onward: allow_at > now => REJECTED (429 RATE_LIMITED), Retry-After
          shrinking from ~19.99s toward 0 as the bucket's TAT is not advanced by rejections.

t=20.00s  Burst headroom has fully regenerated (no requests arrived during the throttled
          window in this simplified example): bucket is back to 400/400 tokens available.
```

The key property visible above: **rejected requests never advance the TAT**, so a client that
backs off immediately (rather than continuing to hammer) recovers its full burst allowance in
exactly `burst_offset` seconds of quiet, never longer — there is no additional "penalty box" beyond
the mechanical consequence of the algorithm itself.

## Per-route-class burst table

Burst capacity is set independently per route class, because the acceptable "instantaneous batch
size" differs by what the endpoint does, not only by plan:

| Route class | Rationale for its burst size | Free | Starter | Growth | Enterprise |
|---|---|---|---|---|---|
| `global` (default reads/writes) | Sized for one dashboard page load (~10 parallel calls) plus headroom | 20 | 100 | 400 | 2,000 |
| `reports.export` | Exports are expensive; even a legitimate user rarely fires more than 2–3 at once | 2 | 5 | 15 | 50 |
| `ai.agents.run` | AI agent invocations are already rate-limited upstream by the FastAPI engine's own concurrency cap; API-layer burst only needs to absorb a multi-agent parallel dispatch (e.g. Auditor + Fraud Detection running together) | 3 | 8 | 25 | 100 |
| `ai.documents.extract` | Bulk OCR upload of a batch of scanned invoices/receipts is a real, legitimate burst pattern | 5 | 15 | 50 | 200 |
| `webhooks.test` | A developer manually re-testing an endpoint in the dashboard; no legitimate reason for a large burst | 2 | 5 | 10 | 25 |

## Configuration

```php
// config/ratelimit.php (excerpt — burst column of the plan table introduced fully in # Plan Quotas)
'route_classes' => [
    'global'                => ['weight' => 1],
    'write'                 => ['weight' => 2],
    'reports.export'        => ['weight' => 10, 'burst' => ['free' => 2, 'starter' => 5, 'growth' => 15, 'enterprise' => 50]],
    'ai.agents.run'         => ['weight' => 5,  'burst' => ['free' => 3, 'starter' => 8, 'growth' => 25, 'enterprise' => 100]],
    'ai.documents.extract'  => ['weight' => 8,  'burst' => ['free' => 5, 'starter' => 15, 'growth' => 50, 'enterprise' => 200]],
    'webhooks.test'         => ['weight' => 3,  'burst' => ['free' => 2, 'starter' => 5, 'growth' => 10, 'enterprise' => 25]],
],
```

Burst and weight are orthogonal: a route class's `weight` determines how many tokens **one call**
consumes from whichever bucket it hits (see `## Route Weighting` in `# Tenant Limits`); its `burst`
entry determines how many tokens that bucket can hold above steady-state before throttling begins.
A `reports.export` call on the Growth plan costs 10 tokens against the `company:{id}:global`
bucket (so roughly 120 exports/minute could theoretically saturate the entire 1,200 rpm budget) but
is additionally capped by its own `reports.export` sub-bucket at a burst of 15 and the per-plan
sustained sub-rate defined in `# Tenant Limits`, so a script exporting reports in a tight loop trips
the narrower, purpose-specific ceiling long before it could starve every other endpoint in the
company of its shared budget.

# Tenant Limits

## The company bucket is the primary, plan-driven ceiling

Of the four scopes in `# Rate Limits`, the **company** scope is the one QAYD actually sells and
supports: it is what a plan's rate limit "is," in the sense a customer reads on a pricing page or a
sales engineer negotiates in an enterprise contract. It aggregates every human user and every API
key belonging to `company_id`, on the theory that from the platform's perspective a burst of load
from Company 412 is a burst of load from Company 412 regardless of whether it came from three
employees clicking around the UI at once or one integration key running a nightly sync — the
company is the billing entity and the noisy-neighbor unit that must be kept fair against every
other company sharing the cluster.

| `plan_code` | Sustained (company-wide, `global` class) | Burst (`global`) | Notes |
|---|---|---|---|
| `free` | 60 req/min | 20 | Hard-capped; see `# Plan Quotas` for the monthly quota that usually binds first |
| `starter` | 300 req/min | 100 | |
| `growth` | 1,200 req/min | 400 | Additionally, list/read endpoints are flat-capped at 600 req/min per company regardless of weighted cost — see `# Examples` for the worked `RATE_LIMITED` response this produces, reusing the exact envelope shown in `API_ERROR_HANDLING.md` |
| `enterprise` | 6,000 req/min (contractual baseline) | 2,000 (contractual baseline) | Negotiable upward via `rate_limit_overrides` (below); never negotiated downward below the `growth` numbers |

## Route Weighting

Not every request costs the cluster the same amount of work, so not every request should cost the
same number of tokens. QAYD assigns each request a **weight** (default `1`) looked up from
`config('ratelimit.route_classes')` by the matched route's class, and passes it as `ARGV[4]`
(`cost`) to the GCRA script, so an expensive call can legitimately consume several seconds of
"banked" bucket capacity in one shot rather than being (incorrectly) treated as equivalent to a
cheap, indexed, single-row `GET`:

| Route class | Weight (tokens per call) | Representative endpoint |
|---|---|---|
| Standard read | 1 | `GET /api/v1/accounting/journal-entries` |
| Standard write | 2 | `POST /api/v1/sales/invoices` |
| Bulk/list export | 10 | `GET /api/v1/reports/{id}/export` |
| AI agent invocation | 5 | `POST /api/v1/ai/agents/{type}/run` |
| OCR/document AI upload | 8 | `POST /api/v1/ai/documents/extract` |
| Webhook manual test | 3 | `POST /api/v1/integrations/webhooks/{id}/test` |

## Sub-bucketing by route class

The `company` scope is not a single Redis key per company; it is one key per `(company_id,
route_class)` pair actually exercised, e.g. `ratelimit:company:412:global`,
`ratelimit:company:412:reports.export`, `ratelimit:company:412:ai.agents.run`. A request is
evaluated against **both** the `global` bucket (charged its weight) **and**, if it belongs to a
named class other than the default, that class's own narrower sub-bucket (charged `1` against the
sub-bucket, since the sub-bucket's own sustained rate already encodes how expensive that class is
allowed to be in aggregate). This is why a `reports.export` burst is stopped by the 15-token
`reports.export` burst ceiling on the Growth plan long before the 400-token `global` burst would
otherwise have allowed it through — the narrower bucket is deliberately the tighter constraint for
expensive classes.

## Negotiated overrides for Enterprise contracts

Enterprise sales frequently negotiates limits above the contractual baseline (a large group company
running overnight batch reconciliation across twenty branches legitimately needs a higher sustained
rate for a bounded window). QAYD models this as a new, standalone table rather than a special case
in application code, so a negotiated limit is data, auditable and time-bounded, not a code change:

```sql
CREATE TABLE rate_limit_overrides (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    scope           VARCHAR(20) NOT NULL,               -- 'company' | 'api_key'
    api_key_id      BIGINT NULL REFERENCES api_keys(id),  -- set only when scope = 'api_key'
    route_class     VARCHAR(60) NOT NULL DEFAULT 'global',
    sustained_rpm   INTEGER NOT NULL,
    burst_capacity  INTEGER NOT NULL,
    monthly_quota   BIGINT NULL,                        -- NULL = do not change the plan's quota
    reason          VARCHAR(255) NOT NULL,
    approved_by     BIGINT NOT NULL REFERENCES users(id), -- platform-admin user, never a tenant user
    branch_id       BIGINT NULL REFERENCES branches(id),
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,
    expires_at      TIMESTAMPTZ NULL,                   -- NULL = indefinite (standard for signed contracts)
    CONSTRAINT rate_limit_overrides_scope_chk CHECK (scope IN ('company','api_key')),
    CONSTRAINT rate_limit_overrides_key_scope_chk CHECK (
        (scope = 'api_key' AND api_key_id IS NOT NULL) OR
        (scope = 'company' AND api_key_id IS NULL)
    )
);
CREATE INDEX rate_limit_overrides_company_idx ON rate_limit_overrides (company_id, deleted_at, expires_at);
CREATE UNIQUE INDEX rate_limit_overrides_active_uk ON rate_limit_overrides (company_id, scope, route_class, COALESCE(api_key_id, 0))
    WHERE deleted_at IS NULL;
```

`GcraLimiter` resolves effective `(sustained_rpm, burst_capacity)` for a company/route-class pair by
checking `rate_limit_overrides` (non-expired, non-deleted) first and falling back to
`config('ratelimit.plans.{plan_code}')` — an override is always additive relief, never a silent
downgrade, and reverts automatically and safely to the plan default the instant it expires or is
soft-deleted, with no application restart required since the check happens per-request.

Administrative endpoints for managing overrides:

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/admin/rate-limit-overrides` | `platform.ratelimit.override` | List active overrides across all tenants (platform-admin only, not tenant-visible) |
| POST | `/api/v1/admin/rate-limit-overrides` | `platform.ratelimit.override` | Grant a company or a specific API key a custom sustained/burst/quota, with a mandatory `reason` and `approved_by` |
| DELETE | `/api/v1/admin/rate-limit-overrides/{id}` | `platform.ratelimit.override` | Revoke early (soft-delete); the company reverts to its plan default on the next request |

Worked example in `# Examples`. `platform.ratelimit.override` follows the same dotted
`<area>.<action>` permission-key convention established in the shared design context and is never
granted to a tenant-side role (Owner, CFO, etc.) — it exists only on QAYD's own internal
platform-admin role, consistent with `platform.usage.suspend-key` and `platform.usage.read` already
defined in `API_MONITORING.md`.

# User Limits

## Purpose: protecting a company's pool from any single seat

The `company` bucket in `# Tenant Limits` is a shared pool: a Growth-plan company with fifteen
users has one 1,200 req/min budget for all fifteen of them combined. Without a secondary per-user
ceiling, a single runaway browser tab (a stuck polling loop in a buggy frontend build, a user
double-clicking "Export" forty times because the first click did not visibly respond) could consume
the entire company's budget alone, starving the other fourteen users — a single seat degrading
service for the whole tenant is exactly the "noisy neighbor" problem the company bucket exists to
prevent one company from inflicting on *another* company, just recurring one level down.

## Key scoping: per user, per active company

The `user` bucket is keyed `ratelimit:user:{user_id}:{company_id}`, deliberately including the
company id even though `user_id` alone is already unique platform-wide. `AUTHENTICATION_API.md`
establishes that one user can belong to multiple companies (`GET /auth/companies`) and that an
access token carries a single active company as a JWT claim (`active_company_id`, set at
login/`switch-company` time). Scoping the bucket per `(user, company)` pair means a user acting as
Company 12 in one browser tab and as Company 19 in another (two distinct access tokens, two
distinct sessions per `auth_sessions`) draws from two entirely independent user-level buckets — a
heavy session against Company 19 never throttles that same person's legitimate work in Company 12.

## Default limits

| Scope | Sustained | Burst | Rationale |
|---|---|---|---|
| `user` (default, all plans) | 300 req/min | 100 | Set comfortably above any single human's realistic click-driven traffic (even power-user dashboard-refresh behavior) while well below every plan's company-wide ceiling except `free`, so on `free` and low-usage `starter` companies the company bucket (`# Tenant Limits`) binds first and the user limit is rarely the visible cause of a `429` |

The user limit does **not** scale with plan tier — it is a flat safety net, not a sold feature, so
it is defined once in `config('ratelimit.user_default')` rather than in the per-plan table in `#
Plan Quotas`. Raising it for a specific user (e.g., a data-migration specialist temporarily running
a bulk import from their own session rather than via a proper API key) is possible only through the
same `rate_limit_overrides` mechanism generalized to a `scope = 'user'` row — QAYD's default
configuration does not expose this in the dashboard because the correct fix for sustained bulk
operations is always "use an API key with an appropriate tier" (`# API Keys`), not "raise a human's
personal ceiling."

## MFA and step-up endpoints are exempt from the standard user bucket

`POST /api/v1/auth/step-up` (re-verifying MFA mid-session to authorize a sensitive action, per
`AUTHENTICATION_API.md`) is deliberately **not** charged against the general `user` bucket. A
legitimate user re-entering a TOTP code because they are approving a bank transfer, a payroll
release, and a tax submission in the same working session should never be blocked by their own
unrelated API traffic elsewhere in the app. `step-up` has its own narrow bucket
(`ratelimit:user:{user_id}:step-up`, sustained 6/min, burst 2) sized only to prevent brute-forcing
the MFA code itself, which is a distinct security control, not a traffic-shaping one — the six
attempts per minute figure is deliberately close to the account-lockout threshold already implied
by `AUTHENTICATION_API.md`'s brute-force defenses, not a load-management number.

## Interaction with sessions and device revocation

Because the `user` bucket key includes only `user_id` and `company_id` — not `session_id` — logging
out one device (`DELETE /api/v1/auth/sessions/{id}`) or revoking all sessions
(`POST /api/v1/auth/logout-all`) has no effect on that bucket's accumulated state; a user who logs
back in immediately on a new device resumes against the same partially-consumed bucket rather than
receiving a fresh one, which is the correct behavior — the limit exists to bound how much load
*this person* can generate, not how much load *this specific browser tab* can generate, and logging
out and back in is not a legitimate way to reset a throttle.

# API Keys

## The `api_keys` table is authoritative and defined in `AUTHENTICATION_API.md`

This document does not redefine the `api_keys` table; it consumes one column from it —
`rate_limit_tier VARCHAR(20) NOT NULL DEFAULT 'standard'` — and defines what that column's values
mean for throttling. For reference, the columns relevant here (full DDL, including `key_prefix`,
`key_hash`, `scopes`, `environment`, is in `AUTHENTICATION_API.md`):

```sql
-- (excerpt of the existing api_keys table — see AUTHENTICATION_API.md for the complete definition)
--   key_prefix       VARCHAR(16)   e.g. 'qk_live_9f2a', shown in the dashboard, never the full secret
--   environment      VARCHAR(10)   'live' | 'test'
--   rate_limit_tier  VARCHAR(20)   DEFAULT 'standard'  ← this document defines its meaning
```

## Tier table

`rate_limit_tier` is a multiplier applied on top of the owning company's plan-driven sustained rate
and burst (`# Tenant Limits`), not a replacement for it — an API key's effective ceiling is always
`company_plan_sustained_rpm × tier_multiplier`, evaluated against its **own** `key` bucket
(`ratelimit:key:{api_keys.id}:{route_class}`), independent of and in addition to the `company`
bucket the same request also has to clear. A single API key can never, by tier alone, exceed its
company's aggregate ceiling — tier only controls how large a *share* of the company bucket that one
key may claim before its own narrower key-level bucket throttles it first, protecting the rest of
the company's keys/users from one integration key's burst.

| `rate_limit_tier` | Multiplier vs. company sustained rpm | Assigned to | Bypasses monthly quota? |
|---|---|---|---|
| `standard` | 1× | Default for every newly created key, on every plan | No |
| `elevated` | 2× | Granted automatically to keys created under `growth`/`enterprise` plans; grantable manually on `starter` after a usage review | No |
| `partner` | 5×, plus a custom burst set via `rate_limit_overrides` | Enterprise integration partners named in a signed data-processing agreement | No — quota is still billed, just at contracted volume (`# Plan Quotas`) |
| `internal` | 10× (soft-capped; effectively unbounded in practice) | Platform-internal service credentials only: `company_id IS NULL` rows, e.g. the FastAPI AI engine's own service token, synthetic-monitoring probes (`API_MONITORING.md` `# Synthetic Monitoring`) | Yes — internal keys are excluded from `api_usage_events` billing aggregation entirely |

## Live vs. test environment: fully separate buckets

`api_keys.environment` (`'live'` | `'test'`) already exists to distinguish production traffic from
a sandbox integration under development. This document extends that distinction into rate limiting:
a `test`-environment key's Redis bucket key includes the environment
(`ratelimit:key:{id}:test:{route_class}`) and always uses a small, fixed, plan-independent ceiling
— 60 req/min sustained, burst 20, regardless of the company's actual plan — so that:

- A developer iterating against the sandbox cannot accidentally consume or inflate a production
  company's paid quota.
- A `live` key's tier and plan-driven ceiling are never diluted by test traffic sharing the same
  bucket.
- Test-environment requests are excluded from `api_usage_events` monthly quota aggregation
  entirely (they still emit the standard Prometheus metrics, tagged `environment="test"`, for the
  integration developer's own debugging, just never billed or quota-counted).

## Key lifecycle and bucket continuity

| Event | Effect on the key's rate-limit bucket |
|---|---|
| Key created (`POST /api/v1/auth/api-keys`) | A fresh bucket is implicitly created on first use — no explicit provisioning step, the Redis key simply does not exist until the first `hit()` |
| `scopes` (permissions) narrowed or widened | No effect. The bucket is keyed by `api_keys.id`, which does not change when `scopes` is updated, so accumulated throttle state persists across a scope change exactly as it should — a permission change is an authorization event, not a traffic event |
| `rate_limit_tier` changed by an admin | Takes effect on the **next** request; the bucket's stored TAT is a timestamp, not a counter, so a tier change (which alters `emission_interval`/`burst_offset`) is applied cleanly to future evaluations without needing to reset or migrate the existing value |
| Key revoked (`DELETE /api/v1/auth/api-keys/{id}`) | The key fails authentication (`401`) before request `[3.5]` in the pipeline is ever reached — the bucket is simply never touched again and expires naturally via its own TTL (see `# Rate Limits`), needing no explicit cleanup |
| Key rotated (revoke + create new) | A new `api_keys.id` means a **new**, empty bucket — this is the only way to deliberately reset a key's accumulated throttle state, and is called out explicitly to integration partners who ask "how do I reset my rate limit" (the answer is never "wait," for a key-level bucket that has fully drained is already back at full burst within one `burst_offset` window — see `# Burst Limits` — so rotation is only relevant if the *tier* itself needs to change and a tier change alone, per the row above, already takes effect without rotation) |

## AI engine attribution

The FastAPI AI engine never authenticates end users itself (`AUTHENTICATION_API.md`); when an AI
agent (General Accountant, Auditor, Forecast Agent, etc.) performs a multi-step tool-calling loop
that calls back into the Laravel API on a user's behalf, those calls are attributed to that
**user's** own bearer token forwarded by the engine, not to a separate "AI" identity — they consume
the same `company` and `user` buckets the human's own direct traffic would. Only the AI engine's
own housekeeping calls (health checks, its own background job dispatch back to Laravel) use the
dedicated `internal`-tier service credential described above. This means an AI agent that needs to
read fifty invoices to reconcile a bank statement is genuinely subject to the same company/user
ceilings a human clicking through fifty invoices one-by-one would be — the AI does not get a free
pass, consistent with the platform rule that AI "obeys the same permissions as the user; it never
bypasses authorization" extended here to also never bypass throttling.

# 429 Strategy

## Response headers on every request, not only rejections

Every response — success or error — carries four headers describing the **most-constrained**
scope evaluated for that request (the scope with the smallest `remaining ÷ burst_capacity` ratio
among the up-to-four buckets checked), so a client always knows how close it is to being throttled
without needing to wait for an actual `429`:

| Header | Present on | Meaning |
|---|---|---|
| `X-RateLimit-Limit` | Every response | Sustained limit (requests/minute) for the reported scope |
| `X-RateLimit-Remaining` | Every response | Tokens currently available in that scope's bucket |
| `X-RateLimit-Reset` | Every response | Unix timestamp when the bucket will next have at least one token available (not necessarily full capacity — full recovery takes up to `burst_offset` seconds longer) |
| `X-RateLimit-Scope` | Every response | Which dimension the three headers above describe: `ip`, `user`, `company`, or `api_key` |
| `Retry-After` | `429` responses only | Seconds until the request would succeed if retried; identical value to `X-RateLimit-Reset - now`, included because it is the HTTP-standard header most off-the-shelf HTTP clients and proxies already understand |

Reporting the most-constrained scope (rather than always e.g. the company scope) is a deliberate
usability choice: a caller hitting their **own** `user` or `api_key` ceiling can self-remediate
(slow down that specific session or key); a caller hitting the **company** ceiling cannot fix it
alone if other users or keys in the same company are the ones consuming the shared budget, and
`X-RateLimit-Scope: company` is precisely the signal that tells them to talk to whoever manages
their company's other integrations, or to their QAYD account manager about a plan upgrade, rather
than debugging their own single client.

## Two distinct 429 error codes

`API_ERROR_HANDLING.md` already defines `RATE_LIMITED` (429, category Throttling) for a short-window
throttle rejection. This document adds one sibling code, owned here, for the conceptually distinct
case of a **monthly plan quota** being exhausted:

| Code | HTTP | Category | Meaning | Retry guidance |
|---|---|---|---|---|
| `RATE_LIMITED` | 429 | Throttling (defined in `API_ERROR_HANDLING.md`) | A GCRA bucket (`ip`/`user`/`company`/`api_key`, any route class) rejected this request; the caller is going too fast right now | Honor `Retry-After` exactly — seconds, not minutes |
| `QUOTA_EXCEEDED` | 429 | Throttling (new, owned by this document) | The company has used its entire `monthly_requests` allotment for the current billing period (`# Plan Quotas`); this is unrelated to how fast requests are arriving | `Retry-After` reflects seconds until `company_subscriptions.current_period_end`, which for a monthly quota is potentially days away — SDKs must **not** auto-retry this the way they auto-retry `RATE_LIMITED`, see `## SDK Behavior` below |

Both remain retryable-class errors in the sense that they are not the caller's fault in the way a
`422 VALIDATION_ERROR` is, but they require categorically different client behavior, which is why
they are surfaced as different `errors[].code` values under the same `429` HTTP status rather than
overloaded onto one code.

## Soft throttle vs. hard cutoff

Not every plan responds to quota exhaustion the same way (full behavior and numbers in `# Plan
Quotas`); this section defines the two mechanisms referenced there:

- **Hard cutoff**: every request beyond the quota returns `429 QUOTA_EXCEEDED` for the remainder of
  the billing period. Used only for `free`.
- **Soft throttle**: requests beyond the quota are still served (`200`/normal status codes
  continue), but the company's `global` sustained rate is temporarily reduced to a fraction of its
  normal plan ceiling for the remainder of the period, `X-RateLimit-*` headers reflect the reduced
  ceiling, and a `usage.quota.exceeded` webhook fires once per billing period at the moment of
  crossing 100%. Used for `starter`, `growth`, and `enterprise`, at different reduction fractions
  and with different escalation (`# Plan Quotas`).

## SDK behavior

`API_SDK_GUIDELINES.md` already specifies that every SDK auto-retries `429` by honoring
`Retry-After` exactly (no exponential backoff, "the server has already told you the wait time") and
raises a `RateLimitError`/`RateLimitException` carrying a parsed `retry_after`. This document
refines that behavior to distinguish the two codes above: SDKs inspect `errors[0].code` before
deciding whether to auto-retry —

```php
// PHP SDK — internal retry decision, extending the existing RateLimitException handling
if ($exception instanceof RateLimitException) {
    if ($exception->code() === 'RATE_LIMITED') {
        // Existing behavior per API_SDK_GUIDELINES.md: honor Retry-After, retry automatically
        // (bounded by the SDK's total-time retry budget).
        usleep($exception->retryAfter() * 1_000_000);
        return $this->retry();
    }
    if ($exception->code() === 'QUOTA_EXCEEDED') {
        // Never auto-retried: Retry-After can be days. Raise immediately so the caller's own
        // application logic decides (queue for later, alert an admin, prompt a plan upgrade).
        throw $exception;
    }
}
```

Client-side proactive pacing (recommended in official SDKs, not enforced server-side): an SDK may
read `X-RateLimit-Remaining` and `X-RateLimit-Reset` on **every** successful response and, when
remaining capacity drops under a low-water mark (default 10%), voluntarily insert small delays
between subsequent calls in a batch operation — smoothing its own request pacing before ever
receiving a `429`, which produces a better experience for bulk operations (e.g., an accountant's
script pulling twelve months of reports) than bursting until rejected and then waiting.

## Idempotency interplay

`API_ERROR_HANDLING.md` and `API_SDK_GUIDELINES.md` define `Idempotency-Key` for financial-mutation
`POST` endpoints: a repeated request with the same key returns the stored first response without
re-executing the operation. Rate-limit evaluation happens **before** the idempotency-store lookup
in the request pipeline (`[3.5]` is earlier than the Service-layer idempotency check inside `[6]`),
but a request that is about to be served from the idempotency cache is charged at **zero
additional cost** against the weight table in `# Tenant Limits` (a configuration flag on the GCRA
call, `cost: 0`, resolved by the controller after it has looked up — but before executing — a
matching `Idempotency-Key`) — a client safely retrying a timed-out mutation must never be double-
throttled for doing the correct, recommended thing.

## Fail-open does not apply to this section's guarantees selectively

To avoid ambiguity given `# Rate Limits`' fail-open policy: if the Redis `ratelimit` connection is
unavailable, requests are allowed through with `X-RateLimit-Limit`/`Remaining`/`Reset` headers
**omitted entirely** (not set to placeholder values, which a naive client might misinterpret as
"unlimited" and rely upon) and `X-RateLimit-Scope: unavailable` is set instead, so client-side
observability can distinguish "you have lots of headroom" from "the limiter could not be
evaluated" — the two must never look identical on the wire.

# Plan Quotas

## Quota is a monthly ceiling, independent of the per-minute throttle

`# Tenant Limits` bounds how fast a company may call the API; this section bounds how *much* a
company may call it **in total** across a billing period, mirroring
`company_subscriptions.current_period_start`/`current_period_end` (already defined in the shared
ERD) rather than a rolling window. A company can be well within its per-minute sustained rate all
month and still exhaust its monthly quota through steady, moderate, entirely legitimate usage —
this is a capacity-planning and monetization control, not an abuse control (abuse is what `#
Tenant Limits` and `RateLimitSpike`, `API_MONITORING.md`, are for).

| `plan_code` | Monthly request quota | Overage behavior | Notes |
|---|---|---|---|
| `free` | 10,000 | **Hard cutoff** — `429 QUOTA_EXCEEDED` for every request until `current_period_end` or upgrade | No overage billing exists for a plan with no payment method on file |
| `starter` | 100,000 | **Soft throttle to 10%** of the plan's sustained rate (30 req/min effective) for the remainder of the period, plus an email at 80% and 100% usage | Overage itself is not separately billed on `starter`; the soft throttle is the only consequence, incentivizing an upgrade rather than metering fractional usage |
| `growth` | 500,000 | **Soft throttle to 20%** of sustained rate (240 req/min effective), `usage.quota.exceeded` webhook fires at 100%, and a persistent dashboard banner appears | Matches the `500,000`/`184,213 used` example already shown in `API_MONITORING.md`'s `GET /api/v1/usage/summary` sample response |
| `enterprise` | 5,000,000 (contractual baseline, adjustable via `rate_limit_overrides.monthly_quota`) | **Soft, billed overage** — no throttle reduction at all; usage beyond the baseline is metered and invoiced at the contracted per-1,000-request overage rate, and the assigned account manager is notified | Never a hard cutoff — an Enterprise contract's commercial terms, not a live API response, are what govern overage cost |

## Data source: `api_usage_events`, not the Redis GCRA buckets

Quota tracking reads the durable `api_usage_events` table already defined in
`API_MONITORING.md` (monthly-partitioned, 13-month retention) — specifically
`SUM(1) WHERE company_id = ? AND occurred_at >= current_period_start` — rather than a Redis
counter, because a monthly quota must survive a Redis restart or `FLUSHDB` without silently
resetting a company's billing-relevant usage to zero. To avoid a full-table aggregate query on
every single request, the current period's running count is cached (Redis `cache` store, `DB 1`,
key `quota:{company_id}:{period_start}`, TTL until `current_period_end`) and refreshed
incrementally by the same `RecordApiUsage` queued job that already writes each `api_usage_events`
row, rather than recomputed from scratch — the cache is a read-through accelerator over the
Postgres source of truth, never itself the source of truth, consistent with the caching
philosophy established in `DATABASE_CACHING.md`.

## `usage.quota.exceeded` webhook

Named already (without a payload shape) in `API_MONITORING.md`'s list of domain events; this
document defines its payload, following the same signed-webhook mechanism
(`Qayd-Signature: t=<unix_timestamp>,v1=<hex_hmac>`) specified in `API_SDK_GUIDELINES.md`:

```json
{
  "event": "usage.quota.exceeded",
  "created_at": "2026-07-16T14:32:07Z",
  "data": {
    "company_id": 412,
    "plan_code": "growth",
    "period_start": "2026-07-01T00:00:00Z",
    "period_end": "2026-08-01T00:00:00Z",
    "monthly_quota": 500000,
    "used_at_trigger": 500001,
    "overage_behavior": "soft_throttle_20pct",
    "effective_sustained_rpm": 240,
    "dashboard_url": "https://app.qayd.com/settings/billing/usage"
  }
}
```

This fires exactly once per billing period (guarded by a unique constraint on
`(company_id, period_start)` in a small `webhook_dedupe` marker, not re-listed here as it reuses the
existing webhook delivery infrastructure) — a company sitting at 100.4% of quota for three weeks
does not receive the same webhook three thousand times.

## Tenant-facing self-service visibility

Rather than requiring a company to discover its quota state by eventually receiving a `429`, QAYD
exposes it proactively:

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/usage/rate-limit-status` | `usage.read` | Current bucket state for all scopes relevant to the caller (their `user` bucket, their company's `company` bucket, and — if authenticated via an API key — that key's bucket), plus the current monthly quota position, in one call, without needing to trigger an actual throttle to observe it |

```http
GET /api/v1/usage/rate-limit-status HTTP/1.1
X-Company-Id: 412
Authorization: Bearer eyJhbGciOiJSUzI1NiIs...
```

```json
{
  "success": true,
  "data": {
    "company": { "plan_code": "growth", "sustained_rpm": 1200, "remaining": 1147, "reset_at": "2026-07-16T14:33:01Z" },
    "user": { "sustained_rpm": 300, "remaining": 300, "reset_at": null },
    "monthly_quota": { "limit": 500000, "used": 214883, "remaining": 285117, "percent_used": 42.98, "period_end": "2026-08-01T00:00:00Z" }
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7c1d2e3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f",
  "timestamp": "2026-07-16T14:32:11Z"
}
```

This endpoint is itself charged the standard `1`-token `global` weight — it is a normal read
endpoint, not a privileged bypass, and is intentionally cheap and cacheable (60-second Redis cache
of the monthly-quota portion, since that value changes at most once per `RecordApiUsage` job tick)
so that dashboard widgets can poll it on every page load without becoming a load problem in their
own right.

# Monitoring

## Reused metrics

`API_MONITORING.md` already defines `qayd_rate_limit_rejections_total` (Counter, labels `company_id`,
`route`) as part of its per-endpoint catalog, and the `RateLimitSpike` alert
(`sum(rate(qayd_rate_limit_rejections_total[5m])) by (company_id) > 50`, Sev-3, Runbook R-06) built
on top of it. This document does not redefine either — every rejection this subsystem produces
increments that exact counter with those exact labels, so the existing alert and runbook apply
unchanged to everything specified here.

## New metrics owned by this document

The existing counter answers "how many rejections, for which company, on which route" but cannot
answer *why* (short-window throttle vs. monthly quota) or show a tenant approaching its ceiling
*before* it is actually rejected. This document adds four metrics that extend, rather than
duplicate, the existing catalog:

| Metric | Type | Labels | Purpose |
|---|---|---|---|
| `qayd_rate_limit_rejections_by_scope_total` | Counter | `scope` (`ip`\|`user`\|`company`\|`api_key`), `plan_code`, `reason` (`rate_limited`\|`quota_exceeded`) | Breaks down *why* rejections happen, which the route/company-only view cannot |
| `qayd_rate_limit_bucket_fill_ratio` | Gauge | `scope`, `scope_id`, `route_class` | Sampled on every allowed hit: `remaining ÷ burst_capacity`, `0.0`–`1.0`. Lets a dashboard show tenants trending toward exhaustion before their first `429` |
| `qayd_quota_usage_ratio` | Gauge | `company_id`, `plan_code` | Current billing period's `used ÷ monthly_quota`, refreshed on the same cadence as the `quota:{company_id}:{period_start}` cache entry in `# Plan Quotas` |
| `qayd_rate_limit_fail_open_total` | Counter | `scope` | Incremented every time the Redis `ratelimit` connection was unreachable and a request was allowed through un-evaluated (`# Rate Limits` → Fail-Open Policy) |

```php
// GcraLimiter::hit() — emitting qayd_rate_limit_bucket_fill_ratio alongside every evaluation
$this->registry->getOrRegisterGauge(
    'qayd', 'rate_limit_bucket_fill_ratio', 'Fraction of burst capacity remaining',
    ['scope', 'scope_id', 'route_class']
)->set($result->remaining / $burstCapacity, [$scope, (string) $scopeId, $routeClass]);
```

## Dashboard: extending "Tenant Health"

`API_MONITORING.md` already names a `Tenant Health` dashboard. This document adds one panel row to
it rather than introducing a new dashboard:

- **Top 10 companies by `qayd_rate_limit_bucket_fill_ratio` proximity to zero** (i.e., closest to
  their next throttle) — proactive, catches a company about to start seeing `429`s before their
  first support ticket.
- **Rejections by scope, stacked** — `qayd_rate_limit_rejections_by_scope_total` grouped by
  `reason`, so an on-call engineer immediately sees whether a spike is short-window abuse
  (`rate_limited`) or a wave of companies simply hitting month-end (`quota_exceeded`, which is not
  an incident, merely a monetization signal).
- **Quota usage heatmap** — every active company's `qayd_quota_usage_ratio`, colored by proximity
  to 1.0, filterable by `plan_code`, the same view Customer Success uses to spot upgrade
  candidates.
- **Fail-open occurrences** — `qayd_rate_limit_fail_open_total`, expected to be flat zero in
  steady state; any nonzero rate is itself worth a glance even below the alert threshold below.

## Alerting additions

Both new alerts route through the same Alertmanager pipeline described in `API_MONITORING.md`
(grouped by `route`/`company_id`/`severity`, PagerDuty for pages, Slack `#qayd-alerts` for
tickets), with one addition: `QuotaExhaustionImminent` routes to `#qayd-customer-success`, not
`#qayd-alerts`, because it is a business event, not an operational one.

| Alert | Condition (PromQL) | Severity | Action |
|---|---|---|---|
| `RateLimiterDegraded` | `sum(rate(qayd_rate_limit_fail_open_total[1m])) > 0` sustained for 30s | Sev-2 (page) | Check Redis `ratelimit` connection (logical DB 5) health directly; if the broader Redis cluster is already down, this is subsumed by the existing `HealthCheckFailing`/R-07 response — do not double-page the same root cause, Alertmanager's `RateLimiterDegraded` inhibition rule silences this alert while `HealthCheckFailing` is already firing |
| `QuotaExhaustionImminent` | `qayd_quota_usage_ratio{plan_code=~"starter\|growth\|enterprise"} > 0.8` | Sev-4 (Slack `#qayd-customer-success`, no page) | Proactive outreach: offer an upgrade or a temporary `rate_limit_overrides` quota bump before the tenant hits the soft-throttle behavior in `# Plan Quotas` |

```yaml
# Alertmanager rule — owned by this document, same file/format convention as API_MONITORING.md
groups:
  - name: qayd-api-ratelimit
    rules:
      - alert: RateLimiterDegraded
        expr: sum(rate(qayd_rate_limit_fail_open_total[1m])) > 0
        for: 30s
        labels:
          severity: page
          team: platform
        annotations:
          summary: "Rate limiter is failing open — Redis 'ratelimit' connection (logical DB 5) may be unreachable"
          runbook_url: "https://runbooks.qayd.internal/R-09-ratelimiter-degraded"
      - alert: QuotaExhaustionImminent
        expr: qayd_quota_usage_ratio{plan_code=~"starter|growth|enterprise"} > 0.8
        for: 5m
        labels:
          severity: ticket
          team: customer-success
        annotations:
          summary: "Company approaching {{ $labels.plan_code }} plan's monthly quota"
          runbook_url: "https://runbooks.qayd.internal/R-10-quota-exhaustion-imminent"
```

## Runbook R-09: Rate Limiter Degraded (new, complements R-06)

`API_MONITORING.md`'s Runbook R-06 already covers *suspected abuse* (a tenant or key legitimately
tripping `RateLimitSpike`). This document adds the counterpart runbook for the limiter's own
infrastructure failing, since the two are easily confused at a glance (both involve
rate-limit-named alerts) but require opposite responses — R-06 is "throttle harder / investigate
the caller," R-09 is "the throttle itself is unhealthy":

1. **Detect**: `RateLimiterDegraded` paged.
2. **Confirm**: `redis-cli -n 5 PING` against the `ratelimit` logical database directly; check
   whether the broader Redis cluster is otherwise healthy (if `HealthCheckFailing` is also firing,
   this is one incident, not two — follow R-07 instead).
3. **Mitigate**: If DB 5 specifically is saturated (`SaturationRedisMemory`-style pressure isolated
   to this logical database), the `volatile-ttl` policy should already be self-correcting; if the
   connection itself is refusing connections, this is infrastructure-layer (network/cluster
   topology), escalate to the on-call Redis owner. The API continues serving traffic un-throttled
   for the duration (`# Rate Limits` Fail-Open Policy) — this is expected, not a second incident.
4. **Follow-up**: Because fail-open means genuine abuse could pass through unmetered during the
   outage window, cross-check `qayd_api_requests_total` for any per-company spike coinciding with
   the outage window once Redis recovers, and retroactively evaluate whether that company's
   `api_usage_events` should be adjusted for billing purposes.

# Examples

## 1. A normal, successful request showing all rate-limit headers

```http
GET /api/v1/accounting/journal-entries?page=1&per_page=25 HTTP/1.1
Host: api.qayd.com
Authorization: Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...
X-Company-Id: 412
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-RateLimit-Limit: 1200
X-RateLimit-Remaining: 1147
X-RateLimit-Reset: 1752676381
X-RateLimit-Scope: company
X-Request-Id: 3f9a2b1c-4d5e-4f6a-8b7c-9d0e1f2a3b4c
X-Response-Time: 42.15ms
```

```json
{
  "success": true,
  "data": {
    "items": [
      { "id": 88231, "entry_number": "JE-2026-04471", "status": "posted", "total_debit": "1250.0000" }
    ]
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 3902, "cursor": null } },
  "request_id": "3f9a2b1c-4d5e-4f6a-8b7c-9d0e1f2a3b4c",
  "timestamp": "2026-07-16T14:32:41Z"
}
```

## 2. Short-window throttle rejection (`RATE_LIMITED`)

This reuses the exact envelope shape and figures already introduced as the canonical example in
`API_ERROR_HANDLING.md` — the 600 req/min ceiling is the Growth-plan flat per-route cap on list/read
endpoints noted in `# Tenant Limits`:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 12
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1752676393
X-RateLimit-Scope: company
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
  "timestamp": "2026-07-16T14:32:50Z"
}
```

## 3. Monthly quota exhausted on the Free plan (`QUOTA_EXCEEDED`, hard cutoff)

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 1314500
X-RateLimit-Scope: company
```

```json
{
  "success": false,
  "data": null,
  "message": "Monthly request quota exceeded for your plan.",
  "errors": [
    {
      "code": "QUOTA_EXCEEDED",
      "field": null,
      "message": "Your Free plan includes 10,000 API requests per month. This limit resets on 2026-08-01.",
      "meta": {
        "plan_code": "free",
        "monthly_quota": 10000,
        "used": 10000,
        "period_end": "2026-08-01T00:00:00Z",
        "upgrade_url": "https://app.qayd.com/settings/billing/plans"
      }
    }
  ],
  "meta": { "pagination": null },
  "request_id": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "timestamp": "2026-07-16T09:15:03Z"
}
```

## 4. Soft-throttled overage on the Growth plan (still `200`, reduced headroom)

```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 240
X-RateLimit-Remaining: 3
X-RateLimit-Reset: 1752676420
X-RateLimit-Scope: company
X-Qayd-Quota-Status: soft-throttled
```

The request still succeeds — the Growth plan never hard-cuts — but `X-RateLimit-Limit` itself has
dropped from the plan's normal `1200` to the `20%` soft-throttle figure (`240`) for the rest of the
billing period, and the non-standard `X-Qayd-Quota-Status` header (informational only, not part of
the standard envelope contract) flags why a client that logged its own historical headers would
otherwise be confused by the sudden ceiling drop. The corresponding webhook fired once, at the
moment 100% was first crossed:

```json
{
  "event": "usage.quota.exceeded",
  "created_at": "2026-07-14T11:02:19Z",
  "data": {
    "company_id": 412,
    "plan_code": "growth",
    "period_start": "2026-07-01T00:00:00Z",
    "period_end": "2026-08-01T00:00:00Z",
    "monthly_quota": 500000,
    "used_at_trigger": 500001,
    "overage_behavior": "soft_throttle_20pct",
    "effective_sustained_rpm": 240,
    "dashboard_url": "https://app.qayd.com/settings/billing/usage"
  }
}
```

## 5. Enterprise partner API key under a negotiated override

Request to grant the override (platform-admin only):

```http
POST /api/v1/admin/rate-limit-overrides HTTP/1.1
Authorization: Bearer <platform-admin token>

{
  "company_id": 88,
  "scope": "api_key",
  "api_key_id": 5521,
  "route_class": "global",
  "sustained_rpm": 12000,
  "burst_capacity": 4000,
  "reason": "Nightly consolidated reconciliation batch across 20 branches, per Enterprise contract addendum #EA-2026-014",
  "approved_by": 7
}
```

```json
{
  "success": true,
  "data": {
    "id": 341,
    "company_id": 88,
    "scope": "api_key",
    "api_key_id": 5521,
    "route_class": "global",
    "sustained_rpm": 12000,
    "burst_capacity": 4000,
    "expires_at": null,
    "created_at": "2026-07-16T10:00:00Z"
  },
  "message": "Rate limit override created.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9b8c7d6e-5f4a-4b3c-2d1e-0f9a8b7c6d5e",
  "timestamp": "2026-07-16T10:00:00Z"
}
```

Subsequent requests from API key `5521` are now evaluated at `12000` sustained / `4000` burst
against its `ratelimit:key:5521:global` bucket instead of the `partner` tier's default `5× 6000 =
30000`-derived figure — note this override is deliberately *below* what the raw tier multiplier
would otherwise allow, illustrating that overrides can tighten as well as loosen a specific key,
e.g. to satisfy a contractual ceiling negotiated for cost-predictability reasons rather than
technical ones.

## 6. curl snippet — inspecting headroom without triggering a throttle

```bash
curl -s -D - -o /dev/null \
  -H "Authorization: Bearer $QAYD_TOKEN" \
  -H "X-Company-Id: 412" \
  https://api.qayd.com/api/v1/usage/rate-limit-status \
  | grep -i "^x-ratelimit\|^http"
```

```
HTTP/1.1 200 OK
X-RateLimit-Limit: 1200
X-RateLimit-Remaining: 1147
X-RateLimit-Reset: 1752676381
X-RateLimit-Scope: company
```

## 7. TypeScript SDK — proactive client-side pacing before a 429 ever occurs

```ts
qayd.on("response", ({ headers }) => {
  const remaining = Number(headers["x-ratelimit-remaining"]);
  const limit = Number(headers["x-ratelimit-limit"]);
  if (limit > 0 && remaining / limit < 0.1) {
    // Under 10% headroom: voluntarily pace the next call in this batch rather than
    // bursting until the server issues a 429. Purely a client-side courtesy — the
    // server-side contract (Retry-After honored exactly on an actual 429) is unchanged.
    qayd.pacing.insertDelay(250);
  }
});

try {
  await qayd.reports.export({ reportId: "rpt_9f2a" });
} catch (err) {
  if (err instanceof RateLimitError) {
    if (err.code === "QUOTA_EXCEEDED") {
      // Days-long wait — never auto-retried by the SDK. Surface to the caller.
      throw err;
    }
    // err.code === "RATE_LIMITED" — SDK has already retried per API_SDK_GUIDELINES.md
    // by the time a caller sees this rethrown, meaning the retry budget itself was exhausted.
    throw err;
  }
}
```

# End of Document
