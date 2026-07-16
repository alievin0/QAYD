# Caching Layer — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_CACHING
---

# Purpose

QAYD is a multi-tenant AI Financial Operating System built on Laravel 12 (PHP 8.4+) over PostgreSQL 15+,
with a FastAPI/Python AI layer that never writes to the database directly. Every financial screen in the
product — the General Ledger, Trial Balance, Balance Sheet, Profit & Loss, AR/AP aging, inventory
valuation, payroll summaries, dashboard KPIs — is derived, at read time, from potentially millions of
`journal_lines`, `invoices`, `stock_movements`, and `payroll_items` rows scoped to a single `company_id`.
Recomputing these aggregates from raw rows on every HTTP request is not viable once a tenant accumulates
more than a few fiscal periods of activity: a Trial Balance for a company with 500,000 posted journal
lines can take several hundred milliseconds to several seconds to aggregate correctly, and a dashboard
that renders eight to twelve such aggregates on page load cannot afford to pay that cost synchronously,
per request, per user, on every page view.

This document specifies the caching layer that sits between the Laravel application and PostgreSQL: what
gets cached, where it is cached (Redis, PostgreSQL materialized views, or both), how cache keys are
namespaced per tenant so that Company A can never observe a byte of Company B's data through a cache
collision, how long each category of cached value lives before it is considered stale, how invalidation
is triggered the instant the underlying financial event that would change it occurs, and how the system
protects itself against cache stampedes, thundering herds, and the subtle correctness bugs that caching
introduces into a ledger that must always foot to the cent.

The caching layer is deliberately **not** a source of truth. Every cached value in this specification is
reconstructible, byte-for-byte, from the posted rows in PostgreSQL. If Redis is flushed entirely, no
financial data is lost — every dashboard, report, and balance simply recomputes on the next read and
repopulates the cache. This is the single invariant that governs every decision in this document: caching
in QAYD is a performance optimization on top of an ACID system of record, never a replacement for it.

This document assumes and reuses, without modification, the platform facts and canonical table names
established in the shared design context: PostgreSQL as primary store, Redis for cache/queue, Laravel 12
Clean Architecture (Controller → FormRequest → Service → Repository → Model), `company_id` tenant
isolation on every business table, immutable posted journal lines, and the standard response envelope for
every API response.

# Redis

## 1.1 Role in the Stack

Redis serves four distinct roles in QAYD, each configured as a **separate logical Redis database or
separate Redis connection** in `config/database.php`, so that an operational issue in one (e.g. a queue
backlog filling memory) cannot evict cache entries needed by another (e.g. session data, which would log
every user out):

| Redis connection name | Purpose | Laravel config key | Persistence |
|---|---|---|---|
| `cache` | Application data cache (this document's scope) | `CACHE_STORE=redis`, `REDIS_CACHE_DB=1` | RDB snapshot only, no AOF required (rebuildable) |
| `session` | User session storage | `SESSION_DRIVER=redis`, `REDIS_SESSION_DB=2` | AOF `appendfsync everysec` (session loss = forced re-login, acceptable but avoid) |
| `queue` | Laravel queue driver (Horizon) for jobs: report generation, cache warming, AI dispatch | `QUEUE_CONNECTION=redis`, `REDIS_QUEUE_DB=3` | AOF `appendfsync everysec` (job loss is unacceptable — payroll runs, tax submissions) |
| `broadcasting` | Laravel Reverb presence/broadcast auth cache | `REDIS_BROADCAST_DB=4` | RDB only |

All four connections point at the same Redis Cluster (or Sentinel-managed primary/replica set in
non-cluster deployments) but use distinct logical databases (`SELECT 1..4`) so that `FLUSHDB` on one
during an incident does not require flushing the others, and so that per-database `maxmemory-policy` can
differ (see below).

## 1.2 Deployment Topology

Production topology is **Redis Cluster**, minimum 3 primary shards + 3 replicas (6 nodes), running on
AWS ElastiCache for Redis (cluster mode enabled) behind the application's VPC. Non-production
(staging/dev) uses a single Redis 7.x instance with no clustering, matching config keys but
`REDIS_CLUSTER=false`.

```
# .env (production)
REDIS_CLUSTER=true
REDIS_CLIENT=phpredis
REDIS_HOST=qayd-prod.xxxxxx.clustercfg.euc1.cache.amazonaws.com
REDIS_PORT=6379
REDIS_PASSWORD=null            # AUTH disabled at transport layer; TLS + VPC security group is the boundary
REDIS_TLS=true
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2
REDIS_QUEUE_DB=3
REDIS_BROADCAST_DB=4
REDIS_PREFIX=qayd_prod_
```

`config/database.php` `redis` block:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),

    'cluster' => env('REDIS_CLUSTER', false),

    'options' => [
        'cluster' => env('REDIS_CLUSTER_MODE', 'redis'), // native redis-cluster protocol
        'prefix'  => env('REDIS_PREFIX', 'qayd_'),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],

    'cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
        'read_timeout' => 2.0,
        'persistent' => true,
    ],

    'session' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_SESSION_DB', '2'),
    ],

    'queue' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_QUEUE_DB', '3'),
    ],
],
```

`config/cache.php` store definition used throughout this document:

```php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'cache',
    ],
],
'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'qayd'), '_').'_cache'),
```

## 1.3 Memory Policy Per Logical Database

| DB | maxmemory-policy | Rationale |
|---|---|---|
| `cache` (1) | `allkeys-lru` | Pure cache; any key can be evicted under pressure, application recomputes from Postgres |
| `session` (2) | `volatile-lru` (all session keys carry TTL) | Evicting a session forces re-login; acceptable degradation, never silent data loss |
| `queue` (3) | `noeviction` | Job payloads must never be silently dropped; `noeviction` returns `OOM` error instead, which pages on-call |
| `broadcasting` (4) | `allkeys-lru` | Purely ephemeral presence data |

## 1.4 Data Structures Used

QAYD uses five Redis primitives deliberately, never using Redis as a generic blob store beyond what
Laravel's cache abstraction provides:

- **STRING** (`SET`/`GET` with `EX`) — the default for `Cache::remember()` serialized JSON payloads:
  single entities, computed reports, dashboard widgets.
- **HASH** — per-company "hot fields" that are read far more often than written, e.g. a company's live
  cash position across all bank accounts, avoiding N separate string keys.
- **SORTED SET (ZSET)** — leaderboard-style and time-ordered data: recent audit log tail per company for
  the activity feed widget, rate-limiting windows.
- **SET** — tag-emulation for grouped invalidation (see `# Invalidation`), and idempotency key tracking
  for webhook delivery.
- **Redis Lua scripts (EVAL)** — atomic check-and-set operations that must not race: stampede locks,
  distributed rate limiters, and the "invalidate-tag" fan-out described below.

# Cache Strategy

## 2.1 Three-Tier Read Path

Every read of a financial aggregate in QAYD flows through up to three tiers, each faster and narrower in
scope than the last:

```
┌─────────────────────────────────────────────────────────────────┐
│ Tier 0 — PHP-FPM OPcache / in-request static memoization         │
│   Scope: single HTTP request. Repeated calls to the same         │
│   repository method within one request hit an in-memory array.   │
├─────────────────────────────────────────────────────────────────┤
│ Tier 1 — Redis (this document)                                   │
│   Scope: cross-request, cross-user, per-tenant. TTL-bound.        │
│   Latency target: p99 < 5ms.                                     │
├─────────────────────────────────────────────────────────────────┤
│ Tier 2 — PostgreSQL materialized views (ledger_entries, mv_*)     │
│   Scope: durable, transactionally consistent with posted rows.    │
│   Refreshed on posting events or on a schedule. Survives a        │
│   total Redis flush with zero data loss, only a latency hit.      │
├─────────────────────────────────────────────────────────────────┤
│ Tier 3 — Raw aggregation over journal_lines / source tables       │
│   Scope: fallback of last resort; also the authoritative          │
│   recomputation path used to populate Tier 1 and Tier 2.          │
└─────────────────────────────────────────────────────────────────┘
```

A request for the Trial Balance, for example, first checks Tier 0 (has this request already computed it
— e.g. because both the page header widget and the main report body need it), then Tier 1 (Redis key
`company:{id}:report:trial-balance:{period_id}:{hash}`), then Tier 2 (`SELECT * FROM
mv_account_balances WHERE company_id = ? AND fiscal_period_id = ?`), and only falls through to Tier 3 —
a live `SUM(debit_amount), SUM(credit_amount) ... GROUP BY account_id` over `journal_lines` — if the
materialized view itself is stale beyond its own refresh contract (e.g. mid-period, before the nightly
refresh, for the *current* open period only; closed periods are always served from Tier 2 because closed
periods cannot change).

## 2.2 Strategy Selection Matrix

| Data category | Strategy | Backing tier(s) | Owner decision |
|---|---|---|---|
| Single entity by PK (account, customer, vendor, product) | Cache-aside, read-through | Redis only | Repository |
| List/index views (paginated invoice list, customer list) | Cache-aside, short TTL, keyed by filter hash | Redis only | Repository |
| Computed aggregate for **closed** period (Trial Balance, P&L, Balance Sheet on a locked fiscal period) | Write-through on period close, effectively infinite TTL until manually invalidated | Redis + Postgres materialized view | Service + scheduled job |
| Computed aggregate for **open** period (same reports, current month) | Cache-aside, short TTL (5 min), invalidated on every posting event touching that period | Redis, backed by materialized view refreshed async | Repository + Observer |
| Running balances (customer AR balance, vendor AP balance, account running balance, bank account balance) | Write-through, updated synchronously in the same DB transaction that posts the affecting journal entry | Redis HASH, mirrored by a Postgres trigger-maintained `running_balance` column | Service (`afterCommit`) |
| Session / auth / permission cache | Write-through on login, mutation, or role change; read-through otherwise | Redis (session DB) + Redis (cache DB for permission sets) | Middleware |
| Dashboard KPI tiles | Cache-aside with background warm job pre-populating before expiry (avoids any user ever paying the cold-cache cost) | Redis, warmed by scheduled job | Scheduled Job |
| AI conversation context / retrieved documents | Cache-aside, medium TTL, keyed by conversation id | Redis | AI Gateway Service |
| Static/reference data (account_types, tax_rates, units_of_measure) | Cache-aside, long TTL (24h), invalidated on any admin write | Redis | Repository |

## 2.3 Cache-Aside vs Write-Through Decision Rule

The rule QAYD engineers apply when adding a new cached value: **if the value participates in a financial
control total that another cached value must agree with (e.g. a customer's AR balance must always equal
the sum of their open invoice balances, which must always equal the GL control account balance), use
write-through inside the same database transaction that posts the change.** Otherwise, for anything that
is a convenience read-optimization only (list pages, search results, static reference tables, dashboard
tiles that are individually refreshed), use cache-aside with an event-driven invalidation hook plus a TTL
safety net. Never use write-through for high-cardinality list/paginated data — it multiplies write cost
for no correctness benefit, since list pages are inherently approximate/racy from the user's perspective
(a list can always be one refresh behind).

# Cache Keys

## 3.1 Global Naming Convention

Every cache key in QAYD is tenant-scoped and namespaced with the pattern:

```
company:{company_id}:{module}:{entity_or_report}:{qualifier...}
```

`company_id` is **always** the first segment after the Laravel cache prefix (`qayd_prod_cache:` from
`config/cache.php`, applied automatically by the Redis client, not written explicitly in application
code). This guarantees that:

1. A single wildcard scan (`SCAN ... MATCH qayd_prod_cache:company:{id}:*`) can enumerate — and, in an
   incident, purge — every cached value belonging to one tenant, which is required both for the "delete
   my company data" compliance flow and for cache-correctness debugging.
2. It is structurally impossible for a key collision to leak Company B's balance into Company A's
   response, because the tenant id is baked into the key, not passed as a parameter that a bug could drop.
3. Redis Cluster key-based sharding (via hash tags) can co-locate all of one company's cache keys on the
   same shard when desired, using the hash-tag form `company:{{company_id}}:...` (curly braces around the
   *value* being hashed) for multi-key Lua/transaction operations — see § 3.5.

Global (non-tenant) keys — account types, tax jurisdiction reference data, system feature flags — use the
prefix `global:{module}:{entity}` instead and are never prefixed with a company id.

## 3.2 Key Scheme By Category

| Category | Key pattern | Example | Value type |
|---|---|---|---|
| Single entity | `company:{id}:{module}:{table}:{pk}` | `company:42:accounting:accounts:1005` | STRING (JSON) |
| Entity list (filtered/paginated) | `company:{id}:{module}:{table}:list:{filters_hash}` | `company:42:sales:invoices:list:a1b2c3d4` | STRING (JSON) |
| Running balance | `company:{id}:{module}:balance:{entity}:{pk}` | `company:42:accounting:balance:account:1005` | HASH (`{amount, currency_code, as_of, version}`) |
| Report (period-scoped) | `company:{id}:report:{report_key}:{period_id}:{params_hash}` | `company:42:report:trial-balance:2026-06:default` | STRING (JSON, gzip if > 32KB) |
| Report (date-range-scoped) | `company:{id}:report:{report_key}:{date_from}_{date_to}:{params_hash}` | `company:42:report:ar-aging:2026-07-01_2026-07-16:default` | STRING (JSON) |
| Dashboard tile | `company:{id}:dashboard:{tile_key}` | `company:42:dashboard:cash-position` | STRING (JSON) |
| Permission set for a user in a company | `company:{id}:auth:permissions:{user_id}` | `company:42:auth:permissions:88` | SET (permission keys) |
| Session | `session:{session_id}` (no company prefix — a session may switch active company) | `session:9f8c...` | Laravel session serialization |
| Rate limit window | `ratelimit:{scope}:{identifier}:{window}` | `ratelimit:api:user:88:2026071618` | ZSET or STRING+INCR |
| Cache tag registry (see Invalidation) | `company:{id}:tag:{tag_name}` | `company:42:tag:invoices` | SET of member keys |
| Stampede lock | `lock:{original_key}` | `lock:company:42:report:trial-balance:2026-06:default` | STRING with `NX EX` |
| AI conversation context | `company:{id}:ai:context:{conversation_id}` | `company:42:ai:context:c-77123` | STRING (JSON, msgpack) |
| Static/reference data | `global:{module}:{table}` | `global:accounting:account_types` | STRING (JSON) |
| Idempotency (webhook/API) | `company:{id}:idempotency:{key}` | `company:42:idempotency:8f3e...` | STRING (response snapshot) |

## 3.3 Filter/Parameter Hashing

List and report keys that vary by arbitrary query parameters use a deterministic hash of the
**normalized** parameter set, never the raw query string (raw query strings vary in key order and
whitespace and would fragment the cache):

```php
final class CacheKeyHasher
{
    public static function forParams(array $params): string
    {
        ksort($params); // deterministic order regardless of caller's array order
        // Drop null/empty values so ?status=&page=1 hashes identically to ?page=1
        $normalized = array_filter($params, fn ($v) => $v !== null && $v !== '');
        return substr(hash('xxh3', json_encode($normalized, JSON_THROW_ON_ERROR)), 0, 16);
    }
}

// Usage in a repository:
$filters = $request->only(['status', 'customer_id', 'date_from', 'date_to', 'sort', 'page', 'per_page']);
$hash = CacheKeyHasher::forParams($filters);
$key = "company:{$companyId}:sales:invoices:list:{$hash}";
```

`xxh3` (via `hash()` in PHP 8.1+) is used over `md5`/`sha1` for speed on high-QPS list endpoints; it is
not used anywhere security-sensitive (it is a cache key, not a token), so collision resistance
requirements are minimal — 16 hex characters of a 64/128-bit hash is more than sufficient collision
resistance for the finite space of realistic filter combinations per tenant.

## 3.4 Enum Reference: Report Keys

| `report_key` | Report | Scope |
|---|---|---|
| `trial-balance` | Trial Balance | fiscal period |
| `balance-sheet` | Balance Sheet | as-of date |
| `profit-loss` | Income Statement | date range |
| `general-ledger` | GL detail by account | date range + account filter |
| `ar-aging` | Accounts Receivable aging | as-of date |
| `ap-aging` | Accounts Payable aging | as-of date |
| `cash-flow` | Statement of Cash Flows | date range |
| `inventory-valuation` | Inventory valuation summary | as-of date + warehouse filter |
| `payroll-summary` | Payroll run summary | payroll_run_id |
| `tax-liability` | Tax liability summary | tax period |

## 3.5 Hash Tags for Multi-Key Atomicity in Cluster Mode

When a single logical operation must touch multiple Redis keys atomically (e.g. invalidating a report
key and its tag-registry membership in one `MULTI`/Lua call), Redis Cluster requires all keys in the
operation to hash to the same slot. QAYD achieves this by wrapping only the `company_id` segment in hash
tag braces in any key that participates in a multi-key transaction:

```
company:{{42}}:report:trial-balance:2026-06:default
company:{{42}}:tag:trial-balance
```

Both keys hash on `42` (the substring inside `{{...}}`... rendered literally as `{42}` in the actual key,
double-braces here are Markdown escaping) and are therefore guaranteed co-located on the same cluster
shard, making `EVAL` scripts touching both keys legal in cluster mode. Keys that never participate in a
multi-key atomic operation (single entity caches, list caches) do **not** need hash tags and are left as
plain `company:42:...` — omitting unnecessary hash tags gives Redis Cluster more freedom to
load-balance keys evenly across shards.

# TTL

## 4.1 TTL Matrix

| Category | TTL | Rationale |
|---|---|---|
| Single entity (account, customer, vendor, product) | 3600s (1h) | Master data changes infrequently; event-driven invalidation handles the exceptions immediately, TTL is a safety net only |
| Entity list/paginated | 300s (5m) | List views tolerate slight staleness; keeps memory footprint bounded for high-cardinality filter combinations that would otherwise never expire |
| Running balance (HASH) | 86400s (24h), but always superseded by write-through on every posting | TTL almost never fires in practice; it exists purely so an orphaned key from a bug cannot live forever |
| Report — closed fiscal period | 2592000s (30 days), refreshed to full TTL on each read (sliding) | Closed periods are immutable; effectively permanent within a billing cycle, still bounded to allow eventual garbage collection of reports for periods nobody looks at anymore |
| Report — open/current fiscal period | 300s (5m) | Must reflect near-real-time posting activity |
| Dashboard tile | 60s (1m), warmed proactively at 45s (see Stampede Protection) | Dashboards are viewed constantly during business hours; short TTL + warm job keeps them always-fresh without ever making a user wait |
| Permission set | 900s (15m) | Balances "role change should take effect soon" against "don't hit the DB on every request" |
| Session | 7200s (2h) sliding, `SESSION_LIFETIME=120` minutes in `config/session.php` | Standard web session expectations |
| Rate limit window | Matches the window itself (60s, 3600s, etc.) | TTL *is* the window |
| Static/reference data | 86400s (24h) | Changes only via admin action, which explicitly busts the key |
| AI conversation context | 1800s (30m) of inactivity | Bounds memory for abandoned conversations; explicit user action extends it |
| Idempotency key | 86400s (24h) | Matches the window during which a client might legitimately retry a request with the same idempotency key |
| Stampede lock | 10s–30s (operation-dependent, always shorter than the expected recomputation time × 2) | Long enough to cover recomputation, short enough that a crashed holder self-heals quickly |

## 4.2 TTL Constants in Code

TTLs are never hard-coded inline at call sites; they live in a single config file so that operational
tuning does not require a code deploy:

```php
// config/caching.php
return [
    'ttl' => [
        'entity_single'        => (int) env('CACHE_TTL_ENTITY_SINGLE', 3600),
        'entity_list'          => (int) env('CACHE_TTL_ENTITY_LIST', 300),
        'running_balance'      => (int) env('CACHE_TTL_RUNNING_BALANCE', 86400),
        'report_closed_period' => (int) env('CACHE_TTL_REPORT_CLOSED', 2_592_000),
        'report_open_period'   => (int) env('CACHE_TTL_REPORT_OPEN', 300),
        'dashboard_tile'       => (int) env('CACHE_TTL_DASHBOARD', 60),
        'permission_set'       => (int) env('CACHE_TTL_PERMISSIONS', 900),
        'static_reference'     => (int) env('CACHE_TTL_STATIC', 86400),
        'ai_context'           => (int) env('CACHE_TTL_AI_CONTEXT', 1800),
        'idempotency'          => (int) env('CACHE_TTL_IDEMPOTENCY', 86400),
    ],
    'warm_before_expiry' => [
        'dashboard_tile' => 15, // seconds before TTL expiry that the warm job refreshes
    ],
];
```

```php
// Usage
Cache::remember($key, config('caching.ttl.entity_single'), fn () => $this->loadFromDb($id));
```

# Invalidation

## 5.1 Principle: Invalidate on the Domain Event, Not on a Timer

TTL is a safety net, never the primary invalidation mechanism for anything that participates in a
financial control total. The primary mechanism is **event-driven invalidation fired synchronously,
within the same request/transaction that mutates data**, via Laravel Model Observers and domain Events
dispatched by Services after a successful `DB::transaction()` commit.

## 5.2 Observer-Based Invalidation

```php
// app/Observers/JournalEntryObserver.php
final class JournalEntryObserver
{
    public function __construct(private readonly ReportCacheInvalidator $invalidator) {}

    public function updated(JournalEntry $entry): void
    {
        if ($entry->wasChanged('status') && $entry->status === JournalEntryStatus::Posted) {
            // Posting a journal entry can affect: account balances, the open-period trial
            // balance, the balance sheet, the P&L, and any sub-ledger control account touched
            // by its lines. Fan out via tag invalidation rather than enumerating every report key.
            $this->invalidator->invalidateForPosting($entry);
        }
    }
}

// app/Providers/AppServiceProvider.php (boot)
JournalEntry::observe(JournalEntryObserver::class);
```

```php
// app/Services/Cache/ReportCacheInvalidator.php
final class ReportCacheInvalidator
{
    public function invalidateForPosting(JournalEntry $entry): void
    {
        $companyId = $entry->company_id;
        $periodId  = $entry->fiscal_period_id;

        // 1. Running balances for every distinct account touched by this entry's lines.
        foreach ($entry->lines()->pluck('account_id')->unique() as $accountId) {
            Cache::forget("company:{$companyId}:accounting:balance:account:{$accountId}");
        }

        // 2. Open-period reports: trial balance, balance sheet, P&L, GL detail — invalidated via
        //    tag, since dozens of parameter-hash variants of each report may be cached.
        $this->invalidateTag($companyId, 'trial-balance');
        $this->invalidateTag($companyId, 'balance-sheet');
        $this->invalidateTag($companyId, 'profit-loss');
        $this->invalidateTag($companyId, "general-ledger:period:{$periodId}");

        // 3. Sub-ledger control accounts: if any line hit the AR or AP control account, bust
        //    the relevant customer/vendor balance too.
        foreach ($entry->lines as $line) {
            if ($line->customer_id) {
                Cache::forget("company:{$companyId}:sales:balance:customer:{$line->customer_id}");
                $this->invalidateTag($companyId, 'ar-aging');
            }
            if ($line->vendor_id) {
                Cache::forget("company:{$companyId}:purchasing:balance:vendor:{$line->vendor_id}");
                $this->invalidateTag($companyId, 'ap-aging');
            }
        }

        // 4. Dashboard tiles that surface any of the above.
        Cache::forget("company:{$companyId}:dashboard:cash-position");
        Cache::forget("company:{$companyId}:dashboard:profit-loss-summary");
        Cache::forget("company:{$companyId}:dashboard:ar-ap-summary");
    }

    private function invalidateTag(int $companyId, string $tag): void
    {
        $registryKey = "company:{$companyId}:tag:{$tag}";
        $members = Redis::connection('cache')->smembers($registryKey);
        if (empty($members)) {
            return;
        }
        Redis::connection('cache')->pipeline(function ($pipe) use ($members, $registryKey) {
            foreach ($members as $memberKey) {
                $pipe->del($memberKey);
            }
            $pipe->del($registryKey);
        });
    }
}
```

## 5.3 Tag-Emulation Registry (Laravel Redis Cache Has No Native Cross-Cluster Tags)

Laravel's native cache "tags" feature is unsupported on the `redis` driver in cluster mode with
predictable performance (tag lookups require `SMEMBERS` across a potentially unbounded set and do not
respect per-key TTL cleanly). QAYD instead maintains an explicit **tag registry SET** per company per
logical tag, populated whenever a tagged key is written, and consumed only during invalidation (never
during reads):

```php
// app/Services/Cache/TaggedCache.php
final class TaggedCache
{
    public function put(string $key, mixed $value, int $ttl, array $tags, int $companyId): void
    {
        Cache::put($key, $value, $ttl);
        $conn = Redis::connection('cache');
        foreach ($tags as $tag) {
            $registryKey = "company:{$companyId}:tag:{$tag}";
            $conn->sadd($registryKey, $key);
            // Registry itself gets a TTL slightly longer than the longest-lived tagged key,
            // so an abandoned tag registry doesn't grow forever if invalidation is ever skipped.
            $conn->expire($registryKey, $ttl + 3600);
        }
    }
}

// Usage when caching a trial balance variant:
$this->taggedCache->put(
    key: "company:{$companyId}:report:trial-balance:{$periodId}:{$hash}",
    value: $reportData,
    ttl: config('caching.ttl.report_open_period'),
    tags: ['trial-balance'],
    companyId: $companyId,
);
```

This gives O(1) writes and O(members) invalidation — bounded in practice because a tag registry only
ever contains the handful of parameter-hash variants of one report that have actually been requested,
not an unbounded set.

## 5.4 Cascading Invalidation Table

| Domain event | Directly invalidated keys | Tags busted | Cascades to |
|---|---|---|---|
| `journal_entries.posted` | account balance HASH(es) | `trial-balance`, `balance-sheet`, `profit-loss`, `general-ledger:period:{id}` | AR/AP balances if control accounts touched; dashboard tiles |
| `invoices.created` \| `invoices.posted` | customer balance HASH | `ar-aging`, `invoice-list:customer:{id}` | Sales dashboard tile; `journal_entries.posted` (invoice posting always creates a JE) |
| `invoices.paid` (via `receipts.allocated`) | customer balance HASH, invoice entity key | `ar-aging` | Cash position dashboard tile |
| `bills.posted` | vendor balance HASH | `ap-aging`, `general-ledger:period:{id}` | Purchasing dashboard tile |
| `vendor_payments.completed` | vendor balance HASH, bank account balance HASH | `ap-aging` | Cash position dashboard tile |
| `stock_movements.created` | `inventory_items` entity key for (product, warehouse) | `inventory-valuation` | Inventory dashboard tile |
| `payroll_runs.completed` | none directly (payroll is period-scoped, not "current") | `payroll-summary:run:{id}` | Payroll dashboard tile |
| `fiscal_periods.closed` | ALL open-period report keys for that period | every report tag for that period | Re-tags the just-closed period's reports with the long "closed period" TTL, see § 5.5 |
| `accounts.updated` (COA edit) | `company:{id}:accounting:accounts:{id}` | `chart-of-accounts` | Every report tag (COA changes invalidate everything derived from account structure) |
| `roles.updated` \| `permissions.updated` | `company:{id}:auth:permissions:{user_id}` for every affected user | n/a | none (permission cache only) |
| `company_users.role_changed` | `company:{id}:auth:permissions:{user_id}` | n/a | Session (force re-fetch of ability list on next request) |

## 5.5 Period-Close Re-Caching, Not Just Invalidation

When a fiscal period closes (`fiscal_periods.closed` event), the reports for that period do not merely
get invalidated — they are **immediately recomputed and re-cached with the long closed-period TTL**, so
that the very next read of "June's Trial Balance" (which will now be requested constantly for
comparison purposes, audit, and month-over-month dashboards) is warm rather than triggering a synchronous
Tier 3 fallback for the first user unlucky enough to ask:

```php
// app/Listeners/RecachePeriodReportsOnClose.php
final class RecachePeriodReportsOnClose implements ShouldQueue
{
    public function handle(FiscalPeriodClosed $event): void
    {
        foreach (['trial-balance', 'balance-sheet', 'profit-loss', 'general-ledger'] as $reportKey) {
            ReportCacheWarmJob::dispatch($event->companyId, $reportKey, $event->periodId)
                ->onQueue('cache-warm');
        }
    }
}
```

# Read Cache

## 6.1 Repository Cache-Aside Pattern

All read caching is implemented at the **Repository** layer per the platform's Clean Architecture
(Controller → FormRequest → Service → Repository → Model). Services and Controllers never call
`Cache::` directly — this keeps cache-aside logic in exactly one layer, testable in isolation, and
trivially bypassable in tests via a repository fake.

```php
// app/Repositories/AccountRepository.php
final class AccountRepository implements AccountRepositoryInterface
{
    public function __construct(
        private readonly int $ttl = 0, // resolved from config in constructor binding
    ) {}

    public function find(int $companyId, int $accountId): ?Account
    {
        $key = "company:{$companyId}:accounting:accounts:{$accountId}";

        return Cache::remember($key, config('caching.ttl.entity_single'), function () use ($companyId, $accountId) {
            return Account::query()
                ->where('company_id', $companyId)
                ->where('id', $accountId)
                ->first();
        });
    }

    public function paginateForCompany(int $companyId, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $hash = CacheKeyHasher::forParams([...$filters, 'page' => $page, 'per_page' => $perPage]);
        $key = "company:{$companyId}:accounting:accounts:list:{$hash}";

        return Cache::remember($key, config('caching.ttl.entity_list'), function () use ($companyId, $filters, $page, $perPage) {
            return Account::query()
                ->where('company_id', $companyId)
                ->when($filters['type_id'] ?? null, fn ($q, $v) => $q->where('account_type_id', $v))
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereRaw(
                    'name_en ILIKE ? OR name_ar ILIKE ? OR code ILIKE ?',
                    ["%{$v}%", "%{$v}%", "%{$v}%"]
                ))
                ->orderBy('code')
                ->paginate($perPage, ['*'], 'page', $page);
        });
    }
}
```

## 6.2 Report Repository Cache-Aside With Tier Fallback

```php
// app/Repositories/Reports/TrialBalanceRepository.php
final class TrialBalanceRepository
{
    public function get(int $companyId, int $fiscalPeriodId, array $params = []): array
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);
        $hash   = CacheKeyHasher::forParams($params);
        $key    = "company:{$companyId}:report:trial-balance:{$fiscalPeriodId}:{$hash}";

        return Cache::remember($key, $this->ttlFor($period), function () use ($companyId, $fiscalPeriodId, $params) {
            return $this->loadFromMaterializedViewOrRaw($companyId, $fiscalPeriodId, $params);
        });
    }

    private function ttlFor(FiscalPeriod $period): int
    {
        return $period->status === FiscalPeriodStatus::Closed
            ? config('caching.ttl.report_closed_period')
            : config('caching.ttl.report_open_period');
    }

    private function loadFromMaterializedViewOrRaw(int $companyId, int $fiscalPeriodId, array $params): array
    {
        // Tier 2: try the materialized view first — it is refreshed on every posting event
        // for the open period (async) and on period close (sync), so it is "fresh enough".
        $rows = DB::table('mv_account_balances')
            ->where('company_id', $companyId)
            ->where('fiscal_period_id', $fiscalPeriodId)
            ->when($params['account_type_id'] ?? null, fn ($q, $v) => $q->where('account_type_id', $v))
            ->orderBy('account_code')
            ->get();

        if ($rows->isEmpty() && $this->periodHasActivity($companyId, $fiscalPeriodId)) {
            // Tier 3 fallback: materialized view refresh job hasn't run yet (race on a brand
            // new period). Compute directly from journal_lines and trigger an async refresh.
            RefreshAccountBalancesView::dispatch($companyId, $fiscalPeriodId)->onQueue('cache-warm');
            return $this->computeRawFromJournalLines($companyId, $fiscalPeriodId, $params);
        }

        return $this->formatTrialBalance($rows);
    }
}
```

# Write Cache

## 7.1 Write-Through for Running Balances

Running balances (account balance, customer AR balance, vendor AP balance, bank account balance) are
updated **synchronously, in the same database transaction** as the posting that changes them, and the
cache is updated in the transaction's `afterCommit()` callback — never before commit, so that a rolled
-back transaction never leaves a cache entry reflecting data that was never actually persisted.

```php
// app/Services/Accounting/JournalPostingService.php
final class JournalPostingService
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly BalanceCacheWriter $balanceCache,
    ) {}

    public function post(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry) {
            $this->assertBalanced($entry);           // SUM(debits) = SUM(credits)
            $entry->status = JournalEntryStatus::Posted;
            $entry->posted_at = now();
            $entry->save();

            foreach ($entry->lines as $line) {
                $this->applyToAccountBalance($line);   // updates accounts.running_balance via SQL
            }

            DB::afterCommit(function () use ($entry) {
                foreach ($entry->lines()->pluck('account_id')->unique() as $accountId) {
                    $this->balanceCache->refresh($entry->company_id, $accountId);
                }
                event(new JournalEntryPosted($entry));  // triggers ReportCacheInvalidator (§5)
            });

            return $entry;
        });
    }
}
```

```php
// app/Services/Cache/BalanceCacheWriter.php
final class BalanceCacheWriter
{
    public function refresh(int $companyId, int $accountId): void
    {
        // Read the just-committed value straight from Postgres (source of truth) and push it
        // into the write-through cache. Never trust an in-memory delta — always re-read.
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('id', $accountId)
            ->select(['id', 'running_balance', 'currency_code', 'updated_at'])
            ->first();

        $key = "company:{$companyId}:accounting:balance:account:{$accountId}";
        Redis::connection('cache')->hmset($key, [
            'amount'      => $account->running_balance,
            'currency'    => $account->currency_code,
            'as_of'       => $account->updated_at->toIso8601String(),
            'version'     => (string) Str::uuid(), // see Consistency §11
        ]);
        Redis::connection('cache')->expire($key, config('caching.ttl.running_balance'));
    }
}
```

## 7.2 Postgres-Side Balance Maintenance (Trigger, Not Application Code)

The `running_balance` column itself is maintained by a database trigger, not solely by application code,
so that any write path (including data fixes run directly against Postgres by an authorized operator with
an audit-logged session) keeps balances correct without relying on the cache-writer being invoked:

```sql
CREATE OR REPLACE FUNCTION fn_update_account_running_balance()
RETURNS TRIGGER AS $$
DECLARE
    v_normal_balance TEXT;
    v_delta NUMERIC(19,4);
BEGIN
    SELECT at.normal_balance INTO v_normal_balance
    FROM accounts a
    JOIN account_types at ON at.id = a.account_type_id
    WHERE a.id = NEW.account_id;

    v_delta := CASE
        WHEN v_normal_balance = 'debit'  THEN NEW.debit_amount  - NEW.credit_amount
        WHEN v_normal_balance = 'credit' THEN NEW.credit_amount - NEW.debit_amount
    END;

    UPDATE accounts
       SET running_balance = running_balance + v_delta,
           updated_at = now()
     WHERE id = NEW.account_id
       AND company_id = NEW.company_id;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_journal_lines_balance_after_insert
AFTER INSERT ON journal_lines
FOR EACH ROW
WHEN (NEW.deleted_at IS NULL)
EXECUTE FUNCTION fn_update_account_running_balance();
```

The Laravel `BalanceCacheWriter` above re-reads this trigger-maintained column after commit; it never
computes the delta itself, eliminating an entire class of cache-drift bugs where application-side balance
math diverges from database-side balance math.

# Session Cache

## 8.1 Configuration

```php
// config/session.php
'driver' => env('SESSION_DRIVER', 'redis'),
'connection' => 'session',          // maps to the dedicated `session` Redis logical DB (§1.2)
'lifetime' => (int) env('SESSION_LIFETIME', 120), // minutes
'expire_on_close' => false,
'encrypt' => true,                  // session payload encrypted at rest via APP_KEY
'cookie' => env('SESSION_COOKIE', 'qayd_session'),
'same_site' => 'lax',
'secure' => true,
'http_only' => true,
```

## 8.2 Multi-Company Session Scoping

A single authenticated user session may have access to multiple companies (via `company_users`). The
**active company** for a request is not stored redundantly inside the session payload as the source of
truth — it is read from the `X-Company-Id` header per request (per the platform API convention) and
validated against the user's `company_users` membership on every request via middleware, with that
membership check itself cached:

```php
// app/Http/Middleware/ResolveActiveCompany.php
final class ResolveActiveCompany
{
    public function handle(Request $request, Closure $next): mixed
    {
        $companyId = (int) $request->header('X-Company-Id');
        $userId = $request->user()->id;

        $key = "company:{$companyId}:auth:membership:{$userId}";
        $isMember = Cache::remember($key, config('caching.ttl.permission_set'), function () use ($companyId, $userId) {
            return CompanyUser::query()
                ->where('company_id', $companyId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();
        });

        abort_unless($isMember, 403, 'Not a member of the requested company.');

        app()->instance('active_company_id', $companyId);
        return $next($request);
    }
}
```

Session state itself stores only: user id, MFA-verified flag, last-activity timestamp, and a device
fingerprint hash — deliberately minimal, so that a role or company-membership change takes effect on the
**next request** (bounded by the 15-minute permission cache TTL below) rather than requiring the session
itself to be invalidated and the user logged out.

## 8.3 Permission Set Caching

```php
// app/Services/Auth/PermissionResolver.php
final class PermissionResolver
{
    public function abilitiesFor(int $userId, int $companyId): array
    {
        $key = "company:{$companyId}:auth:permissions:{$userId}";

        return Cache::remember($key, config('caching.ttl.permission_set'), function () use ($userId, $companyId) {
            return DB::table('company_users')
                ->join('roles', 'roles.id', '=', 'company_users.role_id')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('company_users.company_id', $companyId)
                ->where('company_users.user_id', $userId)
                ->pluck('permissions.key')
                ->all();
        });
    }
}
```

`roles.updated`, `permissions.updated`, and `company_users.role_changed` events each invalidate every
affected `company:{id}:auth:permissions:{userId}` key immediately (§5.4), so the 15-minute TTL is, in
practice, never the mechanism that surfaces a permission change — it exists only as a bound on the worst
case if an invalidation hook is ever missed.

## 8.4 Session Revocation ("Log Out Everywhere")

```php
// app/Services/Auth/SessionRevocationService.php
final class SessionRevocationService
{
    public function revokeAllForUser(int $userId): void
    {
        // Laravel's redis session driver stores sessions as opaque session-id keys with no
        // secondary index by user, so QAYD maintains an explicit reverse index.
        $conn = Redis::connection('session');
        $sessionIds = $conn->smembers("user:{$userId}:sessions");
        $conn->pipeline(function ($pipe) use ($sessionIds, $userId) {
            foreach ($sessionIds as $sid) {
                $pipe->del("qayd_prod_cache:{$sid}");
            }
            $pipe->del("user:{$userId}:sessions");
        });
        Sanctum::actingAs(null); // clears any Sanctum token guard state for the current request
    }
}
```

The reverse index `user:{id}:sessions` (a SET) is populated at login time in the same request that
creates the session, and pruned lazily on each login by discarding member session ids that no longer
exist in Redis (a natural consequence of TTL expiry).

# Report Cache

## 9.1 Why Reports Are Cached Separately From Entities

Financial reports differ from entity caching in three ways that justify their own materialized-view-
backed tier: (1) they aggregate over potentially millions of source rows, so recomputation cost is high;
(2) they are requested with wide parameter variance (date ranges, account filters, cost-center filters,
currency views) that would explode a naive per-parameter-combination cache into an unbounded key space;
and (3) closed-period reports are **provably immutable** — once a fiscal period is closed and its posted
journal lines cannot change, the Trial Balance for that period is a pure function of immutable input and
can be cached indefinitely (bounded only by the housekeeping TTL in §4.1), which is a much stronger
guarantee than any other cached value in the system enjoys.

## 9.2 Materialized Views Backing Reports

```sql
-- Account balances per fiscal period, the Tier-2 backing store for Trial Balance, Balance
-- Sheet, and P&L. Refreshed incrementally per open period; permanently valid once a period
-- closes because no new journal_lines can post into a closed period (enforced at the
-- application layer and by a Postgres CHECK-via-trigger on journal_entries.status).
CREATE MATERIALIZED VIEW mv_account_balances AS
SELECT
    jl.company_id,
    je.fiscal_period_id,
    jl.account_id,
    a.code            AS account_code,
    a.name_en         AS account_name_en,
    a.name_ar         AS account_name_ar,
    a.account_type_id,
    at.classification AS account_classification,      -- asset/liability/equity/revenue/expense
    at.normal_balance,
    SUM(jl.debit_amount)  AS total_debit,
    SUM(jl.credit_amount) AS total_credit,
    CASE
        WHEN at.normal_balance = 'debit'
            THEN SUM(jl.debit_amount) - SUM(jl.credit_amount)
        ELSE SUM(jl.credit_amount) - SUM(jl.debit_amount)
    END AS net_balance,
    COUNT(*) AS line_count,
    MAX(jl.updated_at) AS last_line_updated_at
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
JOIN accounts a ON a.id = jl.account_id
JOIN account_types at ON at.id = a.account_type_id
WHERE jl.deleted_at IS NULL
GROUP BY jl.company_id, je.fiscal_period_id, jl.account_id, a.code, a.name_en, a.name_ar,
         a.account_type_id, at.classification, at.normal_balance;

CREATE UNIQUE INDEX ux_mv_account_balances
    ON mv_account_balances (company_id, fiscal_period_id, account_id);
CREATE INDEX ix_mv_account_balances_company_period
    ON mv_account_balances (company_id, fiscal_period_id);
```

```sql
-- Customer AR sub-ledger balances, backing AR aging.
CREATE MATERIALIZED VIEW mv_customer_balances AS
SELECT
    i.company_id,
    i.customer_id,
    c.name_en, c.name_ar,
    SUM(i.total_amount - i.paid_amount)                                      AS outstanding_balance,
    SUM(CASE WHEN i.due_date >= CURRENT_DATE THEN i.total_amount - i.paid_amount ELSE 0 END) AS current_due,
    SUM(CASE WHEN i.due_date < CURRENT_DATE AND i.due_date >= CURRENT_DATE - 30
             THEN i.total_amount - i.paid_amount ELSE 0 END)                 AS overdue_1_30,
    SUM(CASE WHEN i.due_date < CURRENT_DATE - 30 AND i.due_date >= CURRENT_DATE - 60
             THEN i.total_amount - i.paid_amount ELSE 0 END)                 AS overdue_31_60,
    SUM(CASE WHEN i.due_date < CURRENT_DATE - 60 AND i.due_date >= CURRENT_DATE - 90
             THEN i.total_amount - i.paid_amount ELSE 0 END)                 AS overdue_61_90,
    SUM(CASE WHEN i.due_date < CURRENT_DATE - 90
             THEN i.total_amount - i.paid_amount ELSE 0 END)                 AS overdue_90_plus
FROM invoices i
JOIN customers c ON c.id = i.customer_id
WHERE i.status IN ('posted', 'partially_paid')
  AND i.deleted_at IS NULL
GROUP BY i.company_id, i.customer_id, c.name_en, c.name_ar;

CREATE UNIQUE INDEX ux_mv_customer_balances ON mv_customer_balances (company_id, customer_id);
```

## 9.3 Refresh Strategy: `CONCURRENTLY` + Event-Triggered, Not Cron-Only

```php
// app/Jobs/RefreshAccountBalancesView.php
final class RefreshAccountBalancesView implements ShouldQueue
{
    public function __construct(
        private readonly int $companyId,
        private readonly int $fiscalPeriodId,
    ) {}

    public function handle(): void
    {
        // REFRESH MATERIALIZED VIEW CONCURRENTLY requires a unique index (present above) and
        // allows reads against the view to continue uninterrupted during the refresh — critical
        // since this view backs live report reads.
        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_account_balances');

        // The view refresh is company-agnostic (it's a single global materialized view covering
        // all tenants, filtered at query time by company_id) — refreshing it doesn't leak data,
        // it just recomputes all rows. For very large deployments, mv_account_balances is
        // additionally range-partitioned by company_id hash bucket so refreshes can be scoped;
        // see the # Performance section.
    }

    public function uniqueId(): string
    {
        // Debounce: multiple posting events within a short window collapse to one refresh job.
        return 'refresh-account-balances-view';
    }
}
```

```php
// Debounced dispatch from the observer — ShouldBeUnique + a delay collapses a burst of postings
// (e.g. importing 500 invoices) into a single refresh rather than 500.
final class JournalEntryObserver
{
    public function updated(JournalEntry $entry): void
    {
        if ($entry->wasChanged('status') && $entry->status === JournalEntryStatus::Posted) {
            RefreshAccountBalancesView::dispatch($entry->company_id, $entry->fiscal_period_id)
                ->delay(now()->addSeconds(5))   // debounce window
                ->onQueue('cache-warm');
        }
    }
}
```

`ShouldBeUnique` (Laravel's unique-job middleware, backed by an atomic Redis lock keyed on
`uniqueId()`) ensures that if fifty journal entries post within the same five-second debounce window,
only one `REFRESH MATERIALIZED VIEW` executes, not fifty — this is itself a form of stampede protection
applied to the write side of the cache (see § 12 for the read-side equivalent).

A belt-and-suspenders nightly cron additionally forces a full refresh of every materialized view for
every company, independent of event-driven refreshes, guarding against any missed invalidation:

```php
// routes/console.php
Schedule::command('cache:refresh-materialized-views')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
```

# Dashboard Cache

## 10.1 Tile-Level Caching, Not Whole-Dashboard Caching

Each dashboard tile (Cash Position, P&L Summary, AR/AP Summary, Inventory Alerts, Upcoming Payroll,
Recent Activity) is cached under its own key, not as one blob for the whole dashboard. This allows a
single tile whose underlying data just changed (e.g. Cash Position after a bank transfer) to be
invalidated and recomputed without discarding the other seven tiles that are still perfectly valid,
which would happen with a monolithic dashboard cache key.

```php
// app/Http/Controllers/Api/V1/DashboardController.php
final class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = app('active_company_id');

        $tiles = [
            'cash_position'    => $this->dashboardCache->tile($companyId, 'cash-position', fn () => $this->cashPositionService->compute($companyId)),
            'profit_loss'      => $this->dashboardCache->tile($companyId, 'profit-loss-summary', fn () => $this->plService->currentMonthSummary($companyId)),
            'ar_ap_summary'    => $this->dashboardCache->tile($companyId, 'ar-ap-summary', fn () => $this->arApService->summary($companyId)),
            'inventory_alerts' => $this->dashboardCache->tile($companyId, 'inventory-alerts', fn () => $this->inventoryService->lowStockAlerts($companyId)),
            'upcoming_payroll' => $this->dashboardCache->tile($companyId, 'upcoming-payroll', fn () => $this->payrollService->nextRunPreview($companyId)),
            'recent_activity'  => $this->recentActivityFeed($companyId), // ZSET-backed, §10.3
        ];

        return $this->success($tiles);
    }
}
```

## 10.2 Proactive Warm Job (Never Serve a Cold Dashboard)

```php
// app/Services/Cache/DashboardCache.php
final class DashboardCache
{
    public function tile(int $companyId, string $tileKey, Closure $compute): mixed
    {
        $key = "company:{$companyId}:dashboard:{$tileKey}";
        $ttl = config('caching.ttl.dashboard_tile');

        $value = Cache::get($key);
        if ($value !== null) {
            return $value;
        }

        // Cold miss on a dashboard tile is treated as exceptional (should be pre-warmed);
        // log it for observability, then compute synchronously so the user isn't blocked.
        Log::channel('cache')->warning('dashboard_tile_cold_miss', ['company_id' => $companyId, 'tile' => $tileKey]);
        $value = $compute();
        Cache::put($key, $value, $ttl);
        return $value;
    }
}
```

```php
// app/Jobs/WarmDashboardTilesJob.php — scheduled every 45s per active company (companies with
// at least one user session active in the last 15 minutes), keeping tiles perpetually warm so
// the cache-aside `tile()` method above almost never observes a real miss.
final class WarmDashboardTilesJob implements ShouldQueue
{
    public function handle(ActiveCompanyLocator $locator, DashboardTileComputers $computers): void
    {
        foreach ($locator.activeCompanyIds() as $companyId) {
            foreach ($computers.all() as $tileKey => $compute) {
                $key = "company:{$companyId}:dashboard:{$tileKey}";
                $ttl = config('caching.ttl.dashboard_tile');
                Cache::put($key, $compute($companyId), $ttl);
            }
        }
    }
}

Schedule::job(new WarmDashboardTilesJob)->everySeconds(45)->withoutOverlapping()->onOneServer();
```

## 10.3 Recent Activity Feed as a Sorted Set

The "Recent Activity" dashboard tile is backed by a Redis ZSET rather than a cached aggregate query,
because it is fundamentally a time-ordered, capped-length feed — a natural fit for `ZADD`/`ZREVRANGE`
rather than repeated SQL `ORDER BY created_at DESC LIMIT 20` queries:

```php
final class RecentActivityFeed
{
    public function record(int $companyId, AuditLogEntry $entry): void
    {
        $key = "company:{$companyId}:dashboard:recent-activity";
        Redis::connection('cache')->zadd($key, $entry->created_at->timestamp, json_encode([
            'id' => $entry->id, 'actor' => $entry->actor_name, 'action' => $entry->action,
            'entity' => $entry->entity_type, 'at' => $entry->created_at->toIso8601String(),
        ]));
        Redis::connection('cache')->zremrangebyrank($key, 0, -51); // keep only the newest 50
        Redis::connection('cache')->expire($key, 86400 * 7);
    }

    public function recent(int $companyId, int $limit = 20): array
    {
        $key = "company:{$companyId}:dashboard:recent-activity";
        $raw = Redis::connection('cache')->zrevrange($key, 0, $limit - 1);
        return array_map(fn ($json) => json_decode($json, true), $raw);
    }
}
```

# Performance

## 11.1 Latency Budgets

| Path | p50 target | p99 target |
|---|---|---|
| Redis GET/HGETALL (Tier 1 hit) | < 1ms | < 5ms |
| Materialized view SELECT (Tier 2) | < 15ms | < 80ms |
| Raw journal_lines aggregation, indexed (Tier 3, open period, single company) | < 100ms | < 800ms |
| Full dashboard load (6-8 tiles, warm) | < 40ms | < 150ms |

## 11.2 Pipelining and Batch Fetches

Whenever a request needs N related cache keys (e.g. balances for every account on a Chart of Accounts
screen), the repository issues a single pipelined `MGET`/`HMGET` batch rather than N round trips:

```php
public function balancesFor(int $companyId, array $accountIds): array
{
    $conn = Redis::connection('cache');
    $keys = array_map(fn ($id) => "company:{$companyId}:accounting:balance:account:{$id}", $accountIds);

    $results = $conn->pipeline(function ($pipe) use ($keys) {
        foreach ($keys as $key) {
            $pipe->hgetall($key);
        }
    });

    return array_combine($accountIds, $results);
}
```

## 11.3 Serialization Format

Cached payloads use JSON for anything that must remain human-inspectable during incident debugging
(`redis-cli GET` should show something legible) — entities, lists, reports. The AI conversation context
cache (§3.2), which is write-heavy, high-frequency, and never inspected manually in production, uses
`msgpack` via `igbinary`/`MessagePack\Packer` for a roughly 30-40% size and 2x (de)serialization-speed
improvement over JSON at the cost of human-readability, an acceptable trade for that specific key
category only.

## 11.4 Connection Management

`phpredis` (the `REDIS_CLIENT` in §1.2) is used over `predis` in production for its C-extension
performance and native persistent-connection support (`'persistent' => true` in the `cache` connection
config, §1.2), avoiding a fresh TCP+TLS handshake to Redis on every PHP-FPM request. Predis remains the
local-dev fallback when the phpredis extension is unavailable in a contributor's environment.

## 11.5 Compression for Large Report Payloads

```php
final class ReportCacheCodec
{
    private const COMPRESS_THRESHOLD_BYTES = 32_768;

    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return strlen($json) > self::COMPRESS_THRESHOLD_BYTES
            ? 'gz:'.base64_encode(gzcompress($json, 6))
            : 'raw:'.$json;
    }

    public static function decode(string $stored): array
    {
        [$mode, $payload] = explode(':', $stored, 2);
        $json = $mode === 'gz' ? gzuncompress(base64_decode($payload)) : $payload;
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
```

A General Ledger detail report for a busy account across a full fiscal year can exceed several hundred
KB of JSON; gzip at level 6 typically reduces this by 70-85%, keeping Redis memory usage and network
transfer proportional to actual information content rather than JSON verbosity.

## 11.6 Partitioning Large Materialized Views

For deployments exceeding roughly 5,000 tenants or any single tenant exceeding tens of millions of
journal lines, `mv_account_balances` and `mv_customer_balances` are declared as PostgreSQL **declarative
partitions by `HASH(company_id)`** (16 or 32 partitions) so that `REFRESH MATERIALIZED VIEW
CONCURRENTLY` and vacuum/analyze operations can, in a future iteration, be scoped per-partition rather
than always touching the entire view:

```sql
-- Not required at initial scale, documented here as the designed scaling path.
CREATE TABLE mv_account_balances_partitioned (
    LIKE mv_account_balances
) PARTITION BY HASH (company_id);

CREATE TABLE mv_account_balances_p0 PARTITION OF mv_account_balances_partitioned FOR VALUES WITH (MODULUS 16, REMAINDER 0);
-- ... p1..p15 similarly
```

# Consistency

## 12.1 Consistency Model: Read-Your-Writes for the Writer, Bounded Eventual Consistency for Others

QAYD's cache offers **read-your-writes consistency for the actor who performed the write** (because
write-through updates the cache synchronously in `afterCommit()` before the HTTP response is returned to
that user) and **bounded eventual consistency for every other concurrent reader**, bounded by the shorter
of (a) the TTL of the affected key, or (b) the latency of the event-driven invalidation hook, which is
typically single-digit milliseconds after commit since it fires from the same process via
`DB::afterCommit()`, not from a polling job.

## 12.2 Cache Versioning to Detect Stale Reads

Every write-through balance HASH carries a `version` field (a UUID, §7.1) that is not itself meaningful
data but lets a client (or a background reconciliation job) detect "I read a value, then it changed
underneath me" races when a computation spans multiple reads:

```php
final class BalanceConsistencyChecker
{
    public function verify(int $companyId, int $accountId): void
    {
        $cached = Redis::connection('cache')->hgetall("company:{$companyId}:accounting:balance:account:{$accountId}");
        $db = Account::where('company_id', $companyId)->where('id', $accountId)->value('running_balance');

        if ($cached && bccomp($cached['amount'], (string) $db, 4) !== 0) {
            Log::channel('cache')->error('balance_cache_drift_detected', [
                'company_id' => $companyId, 'account_id' => $accountId,
                'cached' => $cached['amount'], 'db' => $db,
            ]);
            // Self-heal: re-source from the database, which is always authoritative.
            app(BalanceCacheWriter::class)->refresh($companyId, $accountId);
        }
    }
}
```

A scheduled reconciliation job runs this checker over a random sample of accounts per company nightly,
and alerts if drift is detected on more than a trivial threshold of accounts — drift should never happen
given the write-through design, so any detection indicates a bug (a missed `afterCommit`, a direct SQL
write bypassing the Service layer) rather than expected behavior.

## 12.3 Double-Delete Pattern for Update Race Conditions

For cache-aside entities (not write-through balances), QAYD applies the double-delete pattern to close a
known race: (1) Thread A reads stale data from DB, (2) Thread B updates DB and invalidates cache, (3)
Thread A's stale read gets written into the now-empty cache slot, reintroducing staleness. QAYD mitigates
this by deleting the cache key **both** immediately after the DB write **and again after a short delay**,
which flushes out any straggler write that lost the race:

```php
public function update(int $companyId, int $accountId, array $data): Account
{
    $account = DB::transaction(function () use ($companyId, $accountId, $data) {
        $account = Account::where('company_id', $companyId)->where('id', $accountId)->lockForUpdate()->first();
        $account->fill($data)->save();
        return $account;
    });

    $key = "company:{$companyId}:accounting:accounts:{$accountId}";
    Cache::forget($key);                                    // delete #1, immediate
    dispatch(function () use ($key) { Cache::forget($key); })
        ->delay(now()->addMilliseconds(500))                 // delete #2, delayed
        ->onQueue('cache-invalidation');

    return $account;
}
```

## 12.4 Transactional Boundary Alignment

No cache write is ever issued from inside an open `DB::transaction()` closure for data that is being
mutated in that same transaction — only from `DB::afterCommit()`. Writing to Redis before the Postgres
transaction commits risks a cache reflecting a write that a subsequent rollback (e.g. a failed validation
inside a nested service call, or a deadlock retry) undoes, which would leave Redis holding data that no
longer exists in Postgres — a correctness violation that is materially worse than ordinary staleness.

# Stampede Protection

## 13.1 The Problem

When a hot cache key expires (a Trial Balance for a company's current open period, a heavily-viewed
dashboard tile, a permission set for a high-traffic service account), dozens of concurrent requests can
observe the miss simultaneously and all fall through to recompute the same expensive aggregate at once —
a "thundering herd" that multiplies load on PostgreSQL by the request concurrency instead of by 1.

## 13.2 Distributed Lock via `Cache::lock()`

```php
final class TrialBalanceRepository
{
    public function get(int $companyId, int $fiscalPeriodId, array $params = []): array
    {
        $key = "company:{$companyId}:report:trial-balance:{$fiscalPeriodId}:".CacheKeyHasher::forParams($params);

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $lock = Cache::lock("lock:{$key}", seconds: 20, owner: (string) Str::uuid());

        if ($lock->get()) {
            try {
                // Re-check inside the lock: another process may have populated the cache
                // between our initial Cache::get() miss and acquiring the lock.
                if ($cached = Cache::get($key)) {
                    return $cached;
                }
                $data = $this->loadFromMaterializedViewOrRaw($companyId, $fiscalPeriodId, $params);
                Cache::put($key, $data, $this->ttlFor($fiscalPeriodId));
                return $data;
            } finally {
                $lock->release();
            }
        }

        // Lock held by another process: block briefly waiting for it to populate the cache,
        // rather than recomputing redundantly. block() polls Redis at a short interval.
        return $lock->block(15, function () use ($key, $companyId, $fiscalPeriodId, $params) {
            return Cache::get($key) ?? $this->loadFromMaterializedViewOrRaw($companyId, $fiscalPeriodId, $params);
        });
    }
}
```

`Cache::lock()` on the `redis` driver is implemented via `SET key value NX EX seconds` (atomic
check-and-set with expiry) under the hood — a single Redis command, no separate coordination service
required, and self-healing if the lock holder crashes (the `EX` ensures the lock cannot be held forever).

## 13.3 Probabilistic Early Expiration (Jitter)

To avoid many keys with identical TTLs (e.g. every dashboard tile warmed by the same scheduled job,
§10.2) expiring in the exact same millisecond and causing a correlated stampede, QAYD applies TTL jitter
of ±10% at write time:

```php
function jitteredTtl(int $baseTtl): int
{
    $jitter = (int) round($baseTtl * 0.10);
    return $baseTtl + random_int(-$jitter, $jitter);
}

Cache::put($key, $value, jitteredTtl(config('caching.ttl.dashboard_tile')));
```

## 13.4 Request Coalescing for Identical In-Flight Computations

For the report tier specifically, an additional Redis-based "in-flight" marker prevents even the
lock-waiting requests from being more expensive than necessary under very high concurrency (hundreds of
simultaneous requests for the same freshly-expired report):

```php
final class InFlightCoalescer
{
    public function coalesce(string $key, Closure $compute, int $ttl): mixed
    {
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $inFlightKey = "inflight:{$key}";
        $conn = Redis::connection('cache');

        // SETNX-equivalent: only the first caller proceeds to compute; it publishes the result
        // via Redis Pub/Sub so waiters don't even need to poll Cache::get() in a loop.
        if ($conn->set($inFlightKey, '1', ['NX', 'EX' => 20])) {
            $value = $compute();
            Cache::put($key, $value, $ttl);
            $conn->publish("channel:{$key}", json_encode($value));
            $conn->del($inFlightKey);
            return $value;
        }

        $result = null;
        $sub = $conn->subscribe(["channel:{$key}"], function ($message) use (&$result, &$sub) {
            $result = json_decode($message, true);
            $sub->unsubscribe();
        });
        return $result ?? Cache::get($key) ?? $compute(); // fallback if pub/sub misses
    }
}
```

In practice the simpler `Cache::lock()` pattern in §13.2 is used for the vast majority of QAYD's cached
reports; the Pub/Sub coalescer is reserved for the small number of extremely high-fan-in keys
(cross-tenant system status, global reference data refresh) where lock-and-poll latency is unacceptable.

# Monitoring

## 14.1 Redis-Native Metrics

The following `INFO` fields are scraped every 15 seconds by a Prometheus `redis_exporter` sidecar and
graphed in Grafana, with the listed alert thresholds:

| Metric | Alert threshold | Meaning |
|---|---|---|
| `used_memory` / `maxmemory` ratio | > 80% sustained 10m | Approaching eviction pressure |
| `evicted_keys` (rate) | > 0 on `session`/`queue` DBs; > 100/min sustained on `cache` DB | Unexpected eviction of non-evictable data, or cache undersized |
| `keyspace_hits` / (`keyspace_hits` + `keyspace_misses`) | < 85% sustained 15m | Cache effectiveness degrading — investigate TTLs, invalidation over-firing |
| `connected_clients` | > 80% of configured `maxclients` | Connection pool exhaustion risk |
| `instantaneous_ops_per_sec` | Baseline-relative spike > 3x | Possible stampede or runaway invalidation loop |
| `latency` (via `LATENCY HISTORY`) | p99 > 10ms | Slow command or network issue |
| `blocked_clients` | > 0 sustained | Clients stuck on `BLPOP`/lock `block()` waits — investigate stuck lock holder |
| Cluster: `cluster_state` | != `ok` | Cluster degraded, some slots unreachable |

## 14.2 Slow Log

```
# redis.conf
slowlog-log-slower-than 5000   # microseconds; log anything over 5ms
slowlog-max-len 512
```

A scheduled job pulls `SLOWLOG GET` every 5 minutes and ships entries to the application log channel
`cache`, correlating slow commands with the key patterns in this document (a slow `SMEMBERS` on a
`company:{id}:tag:*` registry, for instance, indicates a tag registry has grown unexpectedly large and
warrants investigation into a missing invalidation).

## 14.3 Application-Level Cache Observability

```php
// app/Services/Cache/ObservedCache.php — thin wrapper used in hot paths to emit structured
// hit/miss telemetry without littering business code with logging calls.
final class ObservedCache
{
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $start = hrtime(true);
        $hit = Cache::has($key);
        $value = Cache::remember($key, $ttl, $callback);
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        Metrics::increment($hit ? 'cache.hit' : 'cache.miss', tags: ['key_prefix' => $this->prefixOf($key)]);
        Metrics::histogram('cache.remember.duration_ms', $elapsedMs, tags: ['key_prefix' => $this->prefixOf($key)]);

        return $value;
    }

    private function prefixOf(string $key): string
    {
        // company:42:report:trial-balance:... -> report:trial-balance  (strip tenant + variable suffix)
        $parts = explode(':', $key);
        return implode(':', array_slice($parts, 2, 2));
    }
}
```

These `cache.hit`/`cache.miss`/`cache.remember.duration_ms` metrics, tagged by key-prefix (never by raw
key, which would create unbounded metric cardinality across tenants), feed the Grafana "Cache Health"
dashboard tracked alongside Laravel Horizon's queue metrics and Postgres `pg_stat_statements` for the
materialized-view refresh queries.

## 14.4 Laravel Telescope / Horizon

Non-production environments run Laravel Telescope with the `cache` watcher enabled, giving per-request
visibility into every `Cache::get/put/forget` call issued — invaluable during development for catching a
missing invalidation hook before it reaches production. Horizon monitors the `cache-warm` and
`cache-invalidation` queues specifically (§5.3, §10.2, §12.3), with a dedicated Horizon supervisor
configuration so that a backlog in report-warming jobs cannot starve the higher-priority
payroll/tax/webhook queues:

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-cache' => [
            'connection' => 'redis',
            'queue' => ['cache-warm', 'cache-invalidation'],
            'balance' => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 10,
            'tries' => 3,
        ],
    ],
],
```

# Examples

## 15.1 End-to-End: Caching a Trial Balance Report

**Request:** `GET /api/v1/accounting/reports/trial-balance?fiscal_period_id=2026-06`
Header: `X-Company-Id: 42`

1. `ResolveActiveCompany` middleware confirms user 88 belongs to company 42 (permission-set cache hit,
   `company:42:auth:permissions:88`).
2. `TrialBalanceController` calls `TrialBalanceRepository::get(42, periodId=1180, params=[])`.
3. Cache key `company:42:report:trial-balance:1180:d41d8cd98f` (empty-params hash) — miss.
4. `Cache::lock('lock:company:42:report:trial-balance:1180:d41d8cd98f', 20)` acquired.
5. `mv_account_balances` queried for `company_id=42 AND fiscal_period_id=1180` — 340 account rows,
   14ms.
6. Result formatted into the Trial Balance shape, cached with TTL 300s (period 1180 is the current open
   period — June 2026), lock released.

Response (truncated):
```json
{
  "success": true,
  "data": {
    "fiscal_period": { "id": 1180, "name": "June 2026", "status": "open" },
    "accounts": [
      { "code": "1000", "name_en": "Cash", "name_ar": "النقد", "debit": "125000.0000", "credit": "0.0000", "balance": "125000.0000" },
      { "code": "1200", "name_en": "Accounts Receivable", "name_ar": "الذمم المدينة", "debit": "48200.0000", "credit": "0.0000", "balance": "48200.0000" }
    ],
    "totals": { "debit": "612400.0000", "credit": "612400.0000", "is_balanced": true }
  },
  "message": "Trial balance retrieved.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7f2a1e3c-...",
  "timestamp": "2026-07-16T14:22:03Z"
}
```

7. Ninety seconds later, `journal_entries.posted` fires for a new entry touching account 1000 (Cash).
   `ReportCacheInvalidator::invalidateForPosting()` runs, busts the `trial-balance` tag for company 42,
   deleting `company:42:report:trial-balance:1180:d41d8cd98f` along with any other parameter-hash
   variants that had been cached for that period.
8. The next request for the same report is a clean miss, recomputes from the (now-stale-but-about-to-
   refresh) materialized view; if the view hasn't refreshed yet, Tier 3 raw aggregation covers the gap
   while `RefreshAccountBalancesView` runs in the background debounce window.

## 15.2 End-to-End: Write-Through Customer Balance on Invoice Payment

1. Cashier records a receipt of KWD 500.000 against invoice `INV-2026-0451` (customer 77, company 42).
2. `ReceiptAllocationService::allocate()` runs inside `DB::transaction()`: updates `receipts`,
   `receipt_allocations`, `invoices.paid_amount`, and posts the corresponding journal entry
   (Dr. Bank, Cr. Accounts Receivable).
3. The journal posting trigger (`trg_journal_lines_balance_after_insert`, §7.2) updates
   `accounts.running_balance` for both the Bank and AR control accounts inside the same transaction.
4. `DB::afterCommit()` fires: `BalanceCacheWriter::refresh(42, bankAccountId)` and
   `BalanceCacheWriter::refresh(42, arControlAccountId)` write fresh HASH values; separately,
   `CustomerBalanceCacheWriter::refresh(42, 77)` re-reads `mv_customer_balances`-equivalent logic (or, for
   sub-100ms freshness needs, computes directly from `invoices` for that one customer) and writes
   `company:42:sales:balance:customer:77`.
5. `JournalEntryPosted` and `InvoicePaymentReceived` events fire, busting the `ar-aging` tag and the
   `cash-position` dashboard tile key.
6. The cashier's own subsequent screen refresh, and any other user's dashboard load in the next 45
   seconds (bounded by the dashboard warm job interval, §10.2), see the updated cash position and AR
   balance — read-your-writes for the cashier immediately, bounded eventual consistency for everyone
   else.

## 15.3 End-to-End: Dashboard Warm Job Cycle

1. `WarmDashboardTilesJob` runs at `T+0`, `T+45s`, `T+90s`, ... for every company with an active session
   in the last 15 minutes (tracked via a `company:{id}:active` SET with 15-minute member TTLs, refreshed
   on every authenticated request).
2. At `T+45s`, it recomputes and writes all 5 computed tiles for company 42 with TTL 60s (jittered to
   54-66s, §13.3).
3. A user opens the dashboard at `T+50s`: `DashboardCache::tile()` reads `Cache::get()` directly — hit,
   no computation, ~1ms per tile, ~6ms total for the page.
4. At `T+61s` (before the next warm cycle at `T+90s`), the TTL on one tile (say, jittered to 61s)
   expires. A user request at `T+62s` observes a cold miss on exactly that one tile, logs a
   `dashboard_tile_cold_miss` warning (§10.2), computes synchronously (typically 20-60ms), and serves it
   — a rare, bounded, self-logging degradation rather than a silent failure.

## 15.4 End-to-End: Cache Invalidation Cascade on Period Close

1. Controller finance user closes June 2026 (`fiscal_periods.status: open -> closed`) after final
   adjusting entries.
2. `FiscalPeriodClosingService::close()` validates no draft journal entries remain in the period, sets
   `fiscal_periods.status = 'closed'`, `closed_at = now()`, `closed_by = user_id`.
3. `FiscalPeriodClosed` event dispatched after commit.
4. `RecachePeriodReportsOnClose` listener (§5.5) dispatches `ReportCacheWarmJob` for
   trial-balance/balance-sheet/profit-loss/general-ledger, each of which recomputes from
   `mv_account_balances` (forced synchronous `REFRESH MATERIALIZED VIEW CONCURRENTLY` first, to guarantee
   the closed period's snapshot is final) and writes the result with
   `config('caching.ttl.report_closed_period')` (30 days, sliding).
5. Every subsequent read of June 2026's Trial Balance for the next month is served from this
   permanently-warm cache entry until either the housekeeping TTL lapses (unlikely to matter — a
   read within the TTL window slides it forward) or an auditor-approved reopening of the period
   explicitly busts it via the same `trial-balance` tag mechanism.

## 15.5 End-to-End: Stampede Protection Under Load Test

Load test scenario: 200 concurrent requests hit `GET /api/v1/accounting/reports/trial-balance` for the
same company/period in the instant after its cache key expires (simulating two hundred users opening the
Reports page at the top of the hour).

1. All 200 requests observe `Cache::get($key)` return null within the same few milliseconds.
2. All 200 attempt `Cache::lock("lock:{$key}", 20)->get()`. Exactly one acquires it (Redis `SET NX EX` is
   atomic); the other 199 receive `false` from the non-blocking `get()` attempt inside the repository's
   fallback branch and instead call `$lock->block(15, ...)`.
3. The lock holder computes the report (Tier 2 materialized view read, ~15-80ms) and writes the cache
   key, then releases the lock.
4. The 199 blocked callers, polling at Laravel's default lock-wait interval, observe the now-populated
   cache key on their next poll (within tens of milliseconds of the holder's release) and return it
   without ever touching PostgreSQL themselves.
5. Net PostgreSQL load for 200 concurrent identical requests: **one** materialized-view query, not two
   hundred — the stampede is fully absorbed by the lock.

# End of Document
