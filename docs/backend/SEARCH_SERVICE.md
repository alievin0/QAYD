# Search Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: SEARCH_SERVICE
---

# Purpose

The Search Service is the Laravel-side engine behind QAYD's two search surfaces — the global command
palette (`⌘K`) and the persistent `/search` results page — specified from the frontend in
[../frontend/components/SEARCH_BAR.md](../frontend/components/SEARCH_BAR.md). It answers one question,
fast and safely: *"given a short query string and the identity of the person typing it, what can they
navigate to, what records match, and — if they ask — what does the AI say?"* Everything hard about that
question is on the backend: a query has to fan out across a dozen entity types living in a dozen module
tables, rank them coherently, return them in under the frontend's latency budget, and — the rule that
governs every design decision below — return *nothing the user is not permitted to see*.

The service is built directly on PostgreSQL's own search machinery — `tsvector` full-text,
`pg_trgm` trigram similarity for typo tolerance, and `pgvector` embeddings for the AI-answer path — so
that AI retrieval and record search never need a second datastore to keep in sync with the ledger
(the extension rationale in [../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md)).
It exposes one aggregate endpoint (`GET /api/v1/search`) that the palette and the results page both call
with different shapes, the per-type "see all" endpoint behind it, and the AI-answer path that turns a
natural-language query into a *cited proposal* — never a committed action, per the platform-wide rule
that a search keystroke posts nothing.

This document is the backend counterpart to the frontend command-palette contract; where
[../frontend/components/SEARCH_BAR.md](../frontend/components/SEARCH_BAR.md) owns the component behavior,
the debounce, and the client-side nav/action filtering, this document owns the endpoints those
components call, the indexing strategy that keeps results fresh, the per-tenant index isolation, the
RBAC filtering that is the security boundary (the client filter is a courtesy on top of it), the
ranking, and the AI-answer path. Everything here is Laravel 12 / PHP 8.4 per
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md).

# Responsibilities

| # | Responsibility | Boundary |
|---|---|---|
| 1 | Serve the aggregate `GET /api/v1/search` — fan out one query across every searchable entity type | It searches committed records; it never mutates one |
| 2 | Serve the per-type `GET /api/v1/search/{type}` for the "see all" cursor-paginated single-type view | Same ranking, deeper page — one type, full pagination |
| 3 | RBAC-filter every result server-side, per type, before it leaves the process | A user never receives a record type, record, or nav target they cannot read — the hard boundary |
| 4 | Maintain per-tenant, per-entity search indexes and keep them fresh as records change | Index freshness is bounded and event-driven; a stale hit is corrected, never served as truth |
| 5 | Rank within and across types coherently (trigram similarity, full-text rank, recency, type priority) | The service ranks rows; the frontend orders only the *groups* |
| 6 | Serve the AI-answer path — retrieve, ground, and return a cited answer with confidence | The answer is a proposal with citations; it commits nothing (routes to the human-gate to act) |
| 7 | Honor the frontend's debounce/latency contract — fast, bounded, cacheable responses | ≤ the frontend's per-type preview cap; short-TTL cacheable per (tenant, user-scope, query) |
| 8 | Return bilingual result titles regardless of `Accept-Language` | `title_en` + `title_ar` both, so an Arabic-UI user can match an English account name |

What this service does **not** own: the *command-palette component* and its client-side nav/action
filtering ([../frontend/components/SEARCH_BAR.md](../frontend/components/SEARCH_BAR.md)); the *authoritative
records* it indexes (each owning module); the *AI reasoning* behind an answer
([AI_SERVICE.md](./AI_SERVICE.md) and the FastAPI engine); and the *permission definitions* it enforces
([../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)). It is the read-only fan-out,
ranking, and RBAC-filtering layer over data other services own.

# Domain Model

Search has almost no domain model of its own — it is a *view over other modules' aggregates*. What it
does own is the notion of a **searchable entity type** and the **index projection** that makes a row
findable without scanning the source table live.

- **`SearchableType`** — the enumerated set of entity types the platform exposes to global search, each
  mapping to an owning module, a read permission, a canonical deep-link URL shape, and a set of indexed
  fields. The initial roster: `account`, `journal_entry`, `invoice`, `bill`, `customer`, `vendor`,
  `product`, `employee`, `bank_transaction`, `document`, `ai_decision`. Each type declares whether it is
  full-text-indexed, trigram-indexed, or both.
- **`search_index`** — a per-tenant projection table (one row per searchable record) carrying
  `company_id`, `entity_type`, `entity_id`, `title_en`, `title_ar`, `subtitle`, `search_vector`
  (`tsvector` over the bilingual title/subtitle/keywords), `trgm_text` (the concatenated searchable text
  for `pg_trgm` similarity), `status`, `amount`/`currency_code` (nullable, for result-row rendering),
  `href` (the canonical deep link), and `updated_at`. It is a *derived* table — never a source of truth —
  rebuilt from the owning module's row on change. Types that are cheap to query live (small tables like
  `accounts`) may be searched directly instead of projected; the projection exists for the high-volume,
  frequently-mutated types where a live `ILIKE` scan would not meet the latency budget.
- **`SearchResultGroup` / `SearchResultItem`** — the response DTOs the frontend's
  `SearchResultGroup`/`SearchResultItem` render against: a group is one `entity_type` with its ≤N items;
  an item is `{ type, id, title_en, title_ar, subtitle, status, amount, href }`. These carry both
  language titles by contract, so the client renders by locale without a second round-trip.
- **`AiSearchAnswer`** — the AI-answer path's response: `{ text, agent_code, confidence_score,
  reasoning, citations[] }`, where each citation resolves to a real, permission-checked source record's
  canonical URL. It is the search-surface shape of the platform's proposal object; it never carries an
  executed reference.

The freshness lifecycle of a `search_index` row:

```
(record created)  ──► index_upserted        (owning module's afterCommit event → ReindexEntityJob)
(record updated)  ──► index_upserted         (same path; title/status/amount reprojected)
(record deleted)  ──► index_removed          (soft-delete or hard-delete → row pruned)
(drift detected)  ──► index_reconciled       (nightly reconcile pass repairs any missed event)
```

# Key Classes

Namespaced under `App\Services\Search`, controllers under `App\Http\Controllers\Api\V1\Search`. The
per-type search logic sits behind a common interface so a new searchable type is one class, and the
RBAC filter is applied centrally so no type can forget it.

```php
<?php

namespace App\Services\Search;

use App\Data\Search\SearchQuery;
use App\Data\Search\SearchResults;
use App\Models\User;

/**
 * The aggregate fan-out. Runs each permitted searchable type's search in parallel-ish
 * (bounded), caps each to per_type, ranks, and assembles the grouped response.
 * The single entrypoint GET /api/v1/search resolves to.
 */
final class SearchService
{
    /** @param iterable<EntitySearcher> $searchers keyed by SearchableType */
    public function __construct(
        private readonly SearchProviderRegistry $registry,
        private readonly SearchRbacFilter $rbac,
        private readonly SearchCache $cache,
    ) {}

    public function aggregate(SearchQuery $query, User $actor): SearchResults
    {
        return $this->cache->remember($query, $actor, function () use ($query, $actor): SearchResults {
            $groups = [];
            foreach ($this->rbac->readableTypes($actor) as $type) {          // types the actor may read at all
                $searcher = $this->registry->for($type);
                $hits     = $searcher->search($query->q, perType: $query->perType); // ranked, capped
                $hits     = $this->rbac->filterRows($actor, $type, $hits);   // belt-and-braces per-row check
                if ($hits->isNotEmpty()) {
                    $groups[] = SearchResultGroup::make($type, $hits);
                }
            }
            return SearchResults::make($groups, $query);
        });
    }
}
```

```php
<?php

namespace App\Services\Search;

/** One implementation per searchable entity type. The registry maps a type to its searcher. */
interface EntitySearcher
{
    public function type(): SearchableType;

    /** The read permission a user must hold for this type to appear at all. */
    public function readPermission(): string;

    /** Ranked, capped hits for a query — over the search_index projection or a live scan. */
    public function search(string $q, int $perType): SearchHitCollection;
}
```

```php
<?php

namespace App\Services\Search;

use App\Models\Search\SearchIndexEntry;
use Illuminate\Support\Facades\DB;

/** The default searcher: queries the per-tenant search_index projection with combined FT + trigram rank. */
final class IndexedEntitySearcher implements EntitySearcher
{
    public function __construct(
        private readonly SearchableType $type,
        private readonly string $permission,
    ) {}

    public function type(): SearchableType { return $this->type; }
    public function readPermission(): string { return $this->permission; }

    public function search(string $q, int $perType): SearchHitCollection
    {
        // CompanyScope + RLS already bound this query to the active tenant's rows.
        $rows = SearchIndexEntry::query()
            ->where('entity_type', $this->type->value)
            ->whereRaw("(search_vector @@ plainto_tsquery('simple', ?) OR trgm_text % ?)", [$q, $q])
            ->orderByRaw(
                // full-text rank, blended with trigram similarity and recency; type priority is applied by the caller
                "ts_rank(search_vector, plainto_tsquery('simple', ?)) * 2
                 + similarity(trgm_text, ?)
                 + (1.0 / (1 + EXTRACT(EPOCH FROM (now() - updated_at)) / 2592000)) DESC",
                [$q, $q],
            )
            ->limit($perType)
            ->get();

        return SearchHitCollection::fromIndexRows($rows);
    }
}
```

| Class | Layer | Responsibility |
|---|---|---|
| `SearchService` | Application | Aggregate fan-out across permitted types; caching; assembly |
| `EntitySearcher` (interface) | Domain | One per searchable type; declares its read permission and its ranked search |
| `IndexedEntitySearcher` | Infrastructure | Default searcher over the `search_index` projection (FT + trigram + recency) |
| `LiveEntitySearcher` | Infrastructure | For small/cheap types searched directly against the source table |
| `SearchProviderRegistry` | Application | Maps a `SearchableType` to its searcher; the extension seam for a new type |
| `SearchRbacFilter` | Domain | The security boundary: readable-types gate + per-row read re-check |
| `SearchIndexProjector` | Application | Rebuilds a `search_index` row from an owning module's record on change |
| `SearchCache` | Infrastructure | Short-TTL Redis cache keyed by (company, actor-scope-hash, query) |
| `AiSearchAnswerService` | Application | The AI-answer path: relays the query to the engine and returns a cited proposal |
| `ReindexEntityJob` | Application (queued) | Reprojects one entity on an owning module's `afterCommit` change event |

`SearchRbacFilter` is the class the whole security story hinges on, and it is deliberately two-layered so
no single mistake widens the boundary:

```php
<?php

namespace App\Services\Search;

use App\Models\User;

final class SearchRbacFilter
{
    /** @return list<SearchableType> the types this actor may read at all — the coarse gate */
    public function readableTypes(User $actor): array
    {
        return array_values(array_filter(
            SearchableType::cases(),
            fn (SearchableType $t) => $actor->canInCompany($t->readPermission()),
        ));
    }

    /** Per-row re-check: even within a readable type, drop any row the actor cannot open. */
    public function filterRows(User $actor, SearchableType $type, SearchHitCollection $hits): SearchHitCollection
    {
        // For row-level-restricted types (e.g. branch-scoped invoices, own-department payroll),
        // the per-row Policy is the belt to the readable-types braces. Most types short-circuit
        // to true here because their permission is not row-scoped.
        if (! $type->hasRowLevelScope()) {
            return $hits;
        }
        return $hits->filter(fn (SearchHit $h) => $actor->canReadEntity($h->ref()));
    }
}
```

# Endpoints Backed

All endpoints follow the platform envelope, require `X-Company-Id`, and are **read-only** (`GET`). There
is no single gating permission on the search route itself — a role with zero readable record types still
gets a working search (Navigate results and, if held, Ask AI), per
[../frontend/components/SEARCH_BAR.md](../frontend/components/SEARCH_BAR.md) — because RBAC is applied
*per result group*, not as a route gate.

| Method | Path | Permission | Description |
|---|---|---|---|
| `GET` | `/api/v1/search` | Per-group | The aggregate: `q`, `per_type` (default 5), optional `type=` filter, optional `ask=1` for the AI answer. Returns grouped results (only groups the caller can read) + optional `aiAnswer` |
| `GET` | `/api/v1/search/{type}` | The type's read key | The "see all" single-type view, cursor-paginated per [../api/API_PAGINATION.md](../api/API_PAGINATION.md); `403` if the caller lacks the type's read permission |
| `GET` | `/api/v1/{module}/search` | The module's read key | Optional per-module preview endpoints the palette may fan out to directly (e.g. `/api/v1/sales/search`); same ranking, scoped to that module's types |

The aggregate is deliberately called with two shapes over the same machinery: the palette caps
`per_type=5` for a fast preview; the results page uses the same endpoint and pages each type via
`{type}` behind "See all". A `q` under the 2-character full-text minimum
([../api/API_FILTERING_SORTING.md](../api/API_FILTERING_SORTING.md)) returns an empty `groups` without
touching Postgres.

```json
GET /api/v1/search?q=gulf%20prime&per_type=5
X-Company-Id: 4021

{
  "success": true,
  "data": {
    "groups": [
      {
        "type": "vendor",
        "count_hint": 2,
        "items": [
          {
            "type": "vendor", "id": 5521,
            "title_en": "Gulf Prime Distribution Co.", "title_ar": "شركة جلف برايم للتوزيع",
            "subtitle": "Vendor · 47 bills", "status": "active",
            "amount": null, "href": "/purchasing/vendors/5521"
          }
        ]
      },
      {
        "type": "bill",
        "count_hint": 12,
        "items": [
          {
            "type": "bill", "id": 88231,
            "title_en": "Bill GR-2291 · Gulf Prime Distribution Co.", "title_ar": "فاتورة GR-2291 · جلف برايم",
            "subtitle": "Bill · 2026-07-14", "status": "posted",
            "amount": { "value": "186.5000", "currency_code": "KWD" },
            "href": "/purchasing/bills?filter[id]=88231"
          }
        ]
      }
    ],
    "aiAnswer": null
  },
  "meta": { "took_ms": 34 },
  "request_id": "3a91…",
  "timestamp": "2026-07-14T09:31:02Z"
}
```

With `ask=1`, the same call additionally populates `data.aiAnswer` from the AI-answer path:

```json
GET /api/v1/search?q=how%20much%20do%20we%20owe%20Gulf%20Prime&ask=1

"aiAnswer": {
  "text": "You currently owe Gulf Prime Distribution Co. KWD 1,240.500 across 4 open bills, the oldest 22 days overdue.",
  "agent_code": "general_accountant",
  "confidence_score": "0.9100",
  "reasoning": "Summed accounts_payable open bills where vendor_id = 5521 and status = 'posted' and paid_amount < total_amount.",
  "citations": [
    { "type": "bill", "id": 88231, "label": "Bill GR-2291", "href": "/purchasing/bills?filter[id]=88231" },
    { "type": "bill", "id": 88240, "label": "Bill GR-2310", "href": "/purchasing/bills?filter[id]=88240" }
  ]
}
```

# Database Tables Owned

The Search Service owns exactly one derived table and reads (never writes) every source table it
indexes.

| Table | Owned write path | Notes |
|---|---|---|
| `search_index` | `SearchIndexProjector` via `ReindexEntityJob` | Per-tenant projection; `company_id` scoped + RLS; `GIN` on `search_vector`, `GIN (gin_trgm_ops)` on `trgm_text` |
| (source tables) | Read-only | `accounts`, `invoices`, `bills`, `customers`, `vendors`, `products`, `employees`, `bank_transactions`, `ai_decisions`, `journal_entries`, `documents` — indexed, never mutated |

`search_index` carries `company_id BIGINT NOT NULL` under the identical Row Level Security policy that
protects every tenant table, scoped to `current_setting('app.current_company_id')::bigint`. This is the
foundation of **per-tenant index isolation**: there is one physical `search_index` table, but a query
can only ever match the active company's rows, so a full-text or trigram hit is *physically incapable* of
crossing tenants — the same guarantee the ledger has, extended to the search projection, because search
is exactly the channel most able to accidentally surface Company A's data to Company B if the boundary
were merely a `where` clause a developer could forget. Its indexes lead with `company_id`
(`(company_id, entity_type)` btree, plus the two `GIN` indexes over the tsvector/trgm columns) so both
the tenant filter and the search predicate stay sargable together via `btree_gin`
([../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md)). The table is a rebuildable
cache: losing it entirely degrades search to live scans and a nightly reproject restores it — it is never
a system of record.

# Multi-Tenancy Enforcement

Tenancy is enforced in three concentric rings, and index isolation is the search-specific one.

1. **The header ring.** Every `/api/v1/search*` request carries `X-Company-Id`; `EnsureCompanyScope`
   verifies the `company_users` row (else `403`) and sets `app.current_company_id` for the connection. A
   `company_id` in a query param is ignored for scoping.
2. **The index-isolation ring.** `search_index` (and every source table a live searcher reads) carries
   Row Level Security scoped to `app.current_company_id`. A `plainto_tsquery` or `similarity()` predicate
   runs *inside* that scope, so no crafted query — however it is spelled — can return another tenant's
   row. This is why one shared physical index table is safe: isolation is enforced by the database, not
   by every searcher remembering a `where company_id = ?`.
3. **The RBAC ring.** Within the tenant, `SearchRbacFilter` gates by readable *type* (coarse) and
   re-checks each *row* for row-level-scoped types (fine). A user in a branch-scoped role searching
   "invoice" gets only their branch's invoices, because the per-row Policy is the same one the Invoices
   screen applies.

The cache key composition is itself a tenancy control: `SearchCache` keys every cached response by
`(company_id, actor_permission_scope_hash, normalized_q)`. Because the actor's permission scope is part
of the key, two users in the same company with different read grants never share a cached result set — a
cache hit can never widen what a user sees. A crafted `GET /api/v1/search/{type}` for a type the caller
cannot read returns `403` from Laravel regardless of the UI, which is the closing rule the frontend spec
relies on: the client-side nav/action filter is a courtesy, and this endpoint is the security boundary
([../frontend/components/SEARCH_BAR.md](../frontend/components/SEARCH_BAR.md), `# RBAC-filtered results`).

# Events, Queues & Realtime

**Inbound: source changes → reindex.** Search freshness is event-driven. Every owning module already
emits past-tense domain events on its own writes (`InvoicePosted`, `VendorUpdated`, `CustomerCreated`,
`AiDecisionResolved`, …). The Search Service subscribes to the change events of every searchable type on
the dedicated `search-index` queue; a queued listener bound `afterCommit()` dispatches a
`ReindexEntityJob` that reprojects exactly one entity's `search_index` row:

```php
<?php

namespace App\Jobs\Search;

use App\Jobs\Concerns\TenantAwareJob;
use App\Services\Search\SearchIndexProjector;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ReindexEntityJob extends TenantAwareJob implements ShouldQueue
{
    public string $queue = 'search-index';
    public int $tries = 5;

    public function __construct(
        public readonly int $companyId,
        public readonly string $entityType,
        public readonly int $entityId,
    ) {}

    public function handle(SearchIndexProjector $projector): void
    {
        $this->bindTenant($this->companyId);                 // SET LOCAL app.current_company_id
        $projector->upsert($this->entityType, $this->entityId); // rebuild one row; delete-projects on soft/hard delete
        // Idempotent: reprojecting the same entity twice yields the same row (upsert on (company_id, entity_type, entity_id)).
    }
}
```

Freshness is therefore bounded by queue latency (seconds), not a nightly batch. Because the queue is
at-least-once and the projection is an idempotent upsert on `(company_id, entity_type, entity_id)`,
redelivery is harmless.

**Reconcile pass.** A scheduled nightly `ReconcileSearchIndexJob` per tenant walks each searchable type's
source table against `search_index` and repairs any drift (an event lost while a worker was down, a row
edited by a raw migration) — the durable source tables are truth, and the index is reconciled to them,
never the reverse. This mirrors the platform's "durable table is the source of truth, the derived pipe
is best-effort-plus-reconcile" discipline used for the event relay in
[../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md).

**No realtime broadcast.** Search is a request/response read surface; it emits no Reverb events of its
own. The frontend palette holds one HTTP connection and debounces; it does not subscribe to a search
channel. (The AI-answer path, when it involves a longer engine turn, streams over the AI Service's own
SSE relay, not a search channel — see Integrations.)

**Latency contract.** The frontend debounces at 200ms and caps `per_type` at 5 for the palette
([../frontend/components/SEARCH_BAR.md](../frontend/components/SEARCH_BAR.md)); the backend's obligation is
to keep the aggregate call fast enough that a debounced keystroke feels instant. `SearchCache` gives a
short TTL (30s) keyed per (tenant, scope, query) so re-opening the palette on the same query is served
from Redis, and each per-type search is `LIMIT`-bounded and index-backed so a cold call stays within the
budget. `meta.took_ms` is returned so the frontend and monitoring can watch the contract.

# Integrations

- **The owning business modules** — every searchable module (Accounting, Sales, Purchasing, Banking,
  Payroll, Products, the AI Service) is a *source*: they emit the change events that drive reindexing and
  own the authoritative rows. Search reads them and reprojects; it never writes them, per
  [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md)'s inter-service rules.
- **PostgreSQL search extensions** — `tsvector`/`to_tsvector('simple', …)` for bilingual full-text,
  `pg_trgm` for typo-tolerant similarity, `pgvector` for the AI-answer retrieval — all inside the same
  transactional database as the ledger, so no external search cluster is kept in sync
  ([../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md)).
- **The AI Service / FastAPI engine** — the `ask=1` AI-answer path. `AiSearchAnswerService` does not
  itself reason; it calls the AI Service's boundary ([AI_SERVICE.md](./AI_SERVICE.md)), which relays the
  query to the FastAPI engine, retrieves grounded context (structured queries through Laravel tools +
  `ai_memory` vector retrieval, all in the caller's RBAC scope), and returns a cited answer. Because
  retrieval runs in the *asking user's* scope, the AI answer can never surface a record the user could
  not have found through search directly. A slow or unreachable engine degrades this path to
  `aiAnswer: null` (AI-optional), never blocking the record results.
- **Redis** — the `search-index` queue and the short-TTL response cache.
- **Cloudflare R2** — indirectly: a `document` search hit's `href` resolves through the File Service's
  signed-URL path ([FILE_SERVICE.md](./FILE_SERVICE.md)), never a public object URL.

# Permissions

Search has no route-level permission; it applies the platform RBAC grammar
(`<area>.<action>`, per [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md))
*per result group and per row*.

| Concern | Rule |
|---|---|
| A result group appears | Only if the actor holds the type's read permission (`accounting.account.read`, `sales.invoice.read`, `documents.read`, …) — `SearchRbacFilter::readableTypes` |
| A row within a readable, row-scoped type | Re-checked against the same Policy the owning screen uses (`filterRows`) — a branch-scoped role sees only its branch |
| `GET /api/v1/search/{type}` | `403` if the caller lacks that type's read permission |
| The AI-answer path (`ask=1`) | Gated by `ai.chat`/`ai.analyze` per [AI_SERVICE.md](./AI_SERVICE.md); retrieval runs in the actor's scope |
| A role with zero readable types | Still gets a working search — empty `groups`, plus Ask AI if held — never an access wall |

The governing invariant: **search is a faster path to the permitted surface, never a wider one.** Two
enforcement layers make it belt-and-braces — the server-side per-group/per-row filter here (the boundary)
and the client-side nav/action filter in the palette (a courtesy that avoids rendering an unreachable
item), exactly as [../frontend/components/SEARCH_BAR.md](../frontend/components/SEARCH_BAR.md) states.
Neither the cache nor the index can widen the boundary: the cache key includes the actor's permission
scope, and the index query runs under RLS + the readable-types gate.

# Error Handling

Search degrades per-group and per-path so one failure never blanks the surface.

| Condition | Behavior | Notes |
|---|---|---|
| One type's searcher errors (e.g. a slow module table) | That group is omitted / returned with a group-level error marker; other groups still return | The frontend renders "Couldn't load {type} results" for just that group |
| `q` below the 2-char minimum | Empty `groups`, no Postgres call | Matches the full-text minimum; Navigate (client-side) still works |
| `GET /api/v1/search/{type}` for an unreadable type | `403` | The boundary — regardless of what the UI rendered |
| AI engine unreachable on `ask=1` | `aiAnswer: null` + a `meta` note; record results unaffected | AI-optional: the absence of an answer never blocks the search |
| `search_index` unavailable / lagging | Fall back to a live scan for affected types; reconcile repairs drift | The index is a cache, never a system of record |
| Stale hit (record deleted between index and click) | The result's `href` resolves to the owning route's `403`/`404` boundary | Caught by the route's error boundary, not a search error |
| Cache poisoning attempt via crafted scope | Impossible — key includes actor permission-scope hash + `company_id` | A cache hit can never widen visibility |

Typed exceptions (`UnreadableSearchTypeException` → `403`, `SearchQueryTooShortException` → handled as
empty, not error) are mapped by the global handler. Every response carries `meta.took_ms` and the
`request_id`, and per-group failures are logged with the failing type so a slow module is visible in
monitoring without degrading the whole surface.

# Testing

- **RBAC filtering (feature, security-critical).** With a permission set lacking a type, the aggregate
  returns no group for it; `GET /api/v1/search/{type}` for that type returns `403`. For a row-scoped
  type, a branch-scoped actor gets only their branch's rows. This is the most-covered path — a
  regression is a data-leak — and is asserted per type.
- **Per-tenant isolation (feature).** A query that would match Company B's data under `X-Company-Id: A`
  returns nothing; asserted directly against `search_index` and against a live-searcher type, proving the
  RLS boundary holds for both paths.
- **Cache scope (feature).** Two users in one company with different read grants issuing the same query
  never share a cached result set — the second user's response reflects only their own permitted groups.
- **Ranking (unit).** Given seeded rows, an exact title match outranks a fuzzy one; a trigram-only match
  (typo) still returns; recency breaks ties within a type. The service ranks rows; group order is the
  frontend's job and is not re-derived here.
- **Freshness (feature).** Posting an invoice enqueues `ReindexEntityJob`, and the new title/status is
  searchable after the job runs; deleting the record removes it from results; reprojecting twice is
  idempotent.
- **Reconcile (feature).** With a deliberately dropped reindex event, the nightly reconcile pass repairs
  the drift so the index matches the source table.
- **AI-answer degradation (feature).** With the fake AI engine unreachable, `ask=1` returns
  `aiAnswer: null` and the record groups are unaffected; with it reachable, the answer carries a
  confidence and citations that each resolve to a permission-checked record.
- **Latency contract (feature).** A repeated query within the TTL is served from cache
  (`meta.took_ms` low, no DB hit); a sub-minimum query fires no query.
- **Bilingual (feature).** An Arabic-UI request still returns both `title_en` and `title_ar`, and an
  English account name matches from an Arabic session.

Tooling per [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md): Pest/PHPUnit, PHPStan + Pint in
CI; the AI engine is faked. The tests prove the fan-out, isolation, RBAC, freshness, and ranking — never
the model's answer quality, which lives in the FastAPI repo's own evals.

# End of Document
