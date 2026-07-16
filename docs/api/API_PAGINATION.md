# API Pagination — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: Pagination
---

# Purpose

Every list endpoint in the QAYD API — `GET /api/v1/accounting/journal-entries`, `GET /api/v1/sales/invoices`, `GET /api/v1/inventory/stock-movements`, `GET /api/v1/banking/bank-transactions`, and every other collection resource — returns a bounded page of results, never an unbounded array. This document is the single normative reference for how pagination works across the QAYD API: which pagination style each endpoint family uses, the request parameters clients send, the exact shape of `meta.pagination` in the response envelope, how cursors are constructed and kept stable, how pagination interacts with sorting and filtering, the performance contract (keyset pagination on indexed columns, not `OFFSET`-scans), what happens under concurrent writes, and the edge cases every client integration (Next.js web, Flutter mobile, third-party integrations, the FastAPI AI engine) must handle.

QAYD is a multi-tenant, double-entry accounting platform. Some collections are small and bounded (chart of accounts for a mid-size company: a few hundred rows). Others are unbounded and grow forever by design — `journal_lines`, `ledger_entries`, `stock_movements`, `audit_logs`, `bank_transactions` — a five-year-old company can have tens of millions of ledger rows. A pagination strategy that is fine for `GET /api/v1/accounting/accounts` (a few hundred rows, offset pagination is harmless) actively corrupts correctness and destroys performance for `GET /api/v1/accounting/ledger-entries` (offset pagination on a 40-million-row table walks and discards millions of rows per request, and page contents shift under concurrent posting). This document specifies both strategies, states exactly which endpoint families use which, and gives Laravel implementation patterns for each.

This document does not restate authentication, authorization, or the general response envelope beyond what is needed to specify pagination fields; see the API Overview and API Errors documents for those. It focuses entirely on: how a client asks for "the next page," and how the server answers.

# Strategy (offset/page vs cursor)

QAYD supports two pagination strategies, selected per endpoint family based on data shape, not per client whim. A client cannot request cursor pagination on an endpoint that only supports page pagination, or vice versa — the endpoint's supported style is fixed and documented in its own resource doc; this document defines the mechanics of both styles so every module doc can reference them by name instead of re-specifying them.

**Page (offset) pagination** — `page` + `per_page`. The server computes `OFFSET = (page - 1) * per_page` and runs `LIMIT per_page OFFSET <offset>` (via Laravel's `paginate()`), plus a `SELECT COUNT(*)` to populate `total`. This is simple, allows jumping to an arbitrary page number, and gives the client a stable "page 4 of 19" UI affordance. Its costs are: (1) `OFFSET` on a large table requires the database to scan and discard all preceding rows — cost grows linearly with page depth; (2) `COUNT(*)` on a large filtered table is itself an expensive scan; (3) rows inserted or deleted between two page requests shift every subsequent row by one position, so page 3 can silently repeat or skip a row relative to page 2 (see Consistency Under Writes).

**Cursor (keyset) pagination** — `cursor` (+ `per_page`). The server does not use `OFFSET`. It uses a `WHERE (sort_column, id) < (last_sort_value, last_id) ORDER BY sort_column DESC, id DESC LIMIT per_page` predicate — a "keyset" — driven entirely by an opaque, encoded pointer to the last row the client saw. This has O(per_page) cost regardless of how deep the client pages, because the database seeks directly to the keyset position using an index rather than counting through prior rows. It cannot report a page number or jump to "page 7" — only forward/backward relative to a cursor — and it typically omits `total` (or returns an approximate/capped total) because counting the full result set defeats the point of avoiding a full scan.

**Rule for choosing the strategy per resource**:

| Table shape | Examples | Strategy | Rationale |
|---|---|---|---|
| Small, bounded, rarely exceeds a few thousand rows per company | `accounts`, `cost_centers`, `projects`, `tax_codes`, `warehouses`, `employees`, `products` (most SMEs) | Page (offset) | Depth is shallow; `COUNT(*)` and `OFFSET` are cheap; users expect page numbers and total counts in list/grid UIs. |
| Large, append-only, unbounded growth, high write concurrency | `journal_lines`, `ledger_entries`, `stock_movements`, `audit_logs`, `bank_transactions`, `ai_messages`, `notifications` | Cursor (keyset) | `OFFSET` cost is unacceptable at scale; concurrent inserts (posting, syncing) make offset-based pages unstable; clients typically scroll/stream rather than jump to page N. |
| Medium, filtered heavily, moderate growth | `invoices`, `bills`, `customers`, `vendors`, `sales_orders`, `purchase_orders` | Page (offset), with cursor as an opt-in alternative via `?cursor=` | Most companies stay in the thousands-of-rows range; UIs want page numbers, but high-volume companies (retail, high invoice count) may opt into cursor mode for the same endpoint by passing `cursor` instead of `page`. |

An endpoint that supports both modes documents this explicitly in its own resource doc (e.g. `GET /api/v1/accounting/invoices` supports `page` and `cursor`, `GET /api/v1/accounting/ledger-entries` supports `cursor` only). If a request to a cursor-only endpoint includes `page`, the server ignores it and returns `422` with `errors: [{"field":"page","message":"This endpoint only supports cursor pagination. Use the cursor field returned in meta.pagination.next_cursor."}]`. The reverse (passing `cursor` to a page-only endpoint) returns the equivalent `422`.

# Defaults (per_page 25, max limits)

Every list endpoint applies these defaults and limits, enforced identically for both pagination strategies:

| Parameter | Default | Minimum | Maximum | Notes |
|---|---|---|---|---|
| `per_page` | `25` | `1` | `100` (standard endpoints), `500` (bulk/export endpoints explicitly marked `bulk: true` in their resource doc, e.g. reconciliation import review) | Values above the max are clamped to the max, not rejected, to keep client integrations resilient; values below the minimum are clamped to `1`. |
| `page` | `1` | `1` | Unbounded, but see Edge Cases for "page beyond end" | Only meaningful in offset mode. |
| `cursor` | `null` (first page) | — | — | Only meaningful in cursor mode. Omitted or `null` cursor always returns the first page in the current sort order. |

Clamping (rather than `422`) is deliberate: a client mobile app hardcoded to `per_page=250` should not hard-fail against a `100`-max endpoint; it silently gets `100`. The clamp is visible to the client because `meta.pagination.per_page` in the response always reflects the *effective* value actually used, not the value requested. Clients must read `meta.pagination.per_page`, not assume their request value was honored verbatim.

The `per_page` maximum exists to bound response payload size and Laravel Eloquent hydration cost per request; it is enforced in `App\Http\Middleware\NormalizePagination`, applied globally to every `/api/v1/*` route, before the controller runs:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class NormalizePagination
{
    protected int $defaultPerPage = 25;
    protected int $maxPerPage = 100;
    protected int $bulkMaxPerPage = 500;

    public function handle(Request $request, Closure $next)
    {
        $max = $request->route()?->defaults('bulk_pagination') ? $this->bulkMaxPerPage : $this->maxPerPage;

        $perPage = (int) $request->query('per_page', $this->defaultPerPage);
        $perPage = max(1, min($perPage, $max));
        $request->query->set('per_page', $perPage);

        $page = (int) $request->query('page', 1);
        $request->query->set('page', max(1, $page));

        return $next($request);
    }
}
```

Rate limiting interacts with `per_page`: a client requesting the maximum `per_page` on every call consumes the same "1 request" against Redis-backed rate limits as a client requesting `per_page=1` — pagination limits and rate limits are independent axes (see API Rate Limiting document) — but endpoints MAY apply a stricter per-minute limit to bulk-mode (`per_page` up to `500`) requests specifically, documented per endpoint.

# Request Params

| Param | Type | Applies to | Description |
|---|---|---|---|
| `page` | integer ≥ 1 | Page-mode endpoints | 1-indexed page number. |
| `per_page` | integer, 1–100 (or 1–500 for bulk endpoints) | Both modes | Rows per page, subject to Defaults clamping. |
| `cursor` | opaque base64url string | Cursor-mode endpoints (and opt-in on hybrid endpoints) | Pointer to resume after. See Cursor Pagination for encoding. Mutually exclusive with `page`. |
| `sort` | string, e.g. `-created_at`, `code`, `-total_amount,id` | Both modes | Comma-separated sort keys; leading `-` means descending. See Sorting Interaction — this materially affects cursor validity. |
| `filter[<field>]` / query filters | varies per resource | Both modes | Documented per resource; e.g. `filter[status]=posted`, `filter[date_from]=2026-01-01`. Filters are applied server-side before pagination, i.e. `total` and cursors reflect the filtered set, not the whole table. |
| `search` | string | Both modes (where the resource supports search) | Full-text or `ILIKE` search term, applied before pagination like a filter. |

Example page-mode request:

```
GET /api/v1/accounting/accounts?page=2&per_page=25&sort=code&filter[status]=active HTTP/1.1
Host: api.qayd.app
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
X-Company-Id: 4821
Accept: application/json
```

Example cursor-mode request (first page — no cursor):

```
GET /api/v1/accounting/ledger-entries?per_page=50&sort=-posted_at&filter[account_id]=1042 HTTP/1.1
Host: api.qayd.app
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
X-Company-Id: 4821
Accept: application/json
```

Example cursor-mode request (subsequent page):

```
GET /api/v1/accounting/ledger-entries?per_page=50&sort=-posted_at&filter[account_id]=1042&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE1VDA5OjIyOjAwWiIsImlkIjo5ODIwMzR9 HTTP/1.1
Host: api.qayd.app
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
X-Company-Id: 4821
Accept: application/json
```

Passing both `page` and `cursor` in the same request is a client error: the server returns `422` with `errors: [{"field":"cursor","message":"page and cursor are mutually exclusive; provide only one."}]`.

# meta.pagination Response Shape

Every list response uses the standard envelope. The `meta.pagination` object's shape differs slightly by mode, but both share the same top-level keys so client SDKs can use one `Pagination` type with optional fields:

```json
{
  "success": true,
  "data": [ /* array of resources */ ],
  "message": "Accounts retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": {
      "mode": "page",
      "page": 2,
      "per_page": 25,
      "total": 178,
      "total_pages": 8,
      "cursor": null,
      "next_cursor": null,
      "prev_cursor": null,
      "has_more": true
    }
  },
  "request_id": "6f2c9e2a-9b3f-4b41-8a70-1a1e0c9d4e77",
  "timestamp": "2026-07-16T12:00:03Z"
}
```

Cursor-mode response:

```json
{
  "success": true,
  "data": [ /* array of ledger entry resources */ ],
  "message": "Ledger entries retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": {
      "mode": "cursor",
      "page": null,
      "per_page": 50,
      "total": null,
      "total_pages": null,
      "cursor": "eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE1VDA5OjIyOjAwWiIsImlkIjo5ODIwMzR9",
      "next_cursor": "eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE0VDE2OjAxOjAwWiIsImlkIjo5ODE5ODh9",
      "prev_cursor": "eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE1VDEwOjAwOjAwWiIsImlkIjo5ODIwNTB9",
      "has_more": true
    }
  },
  "request_id": "8b1d4f70-3c2a-4e91-9d55-2f6a7b0c1e44",
  "timestamp": "2026-07-16T12:00:04Z"
}
```

Field reference (shared by both modes):

| Field | Type | Page mode | Cursor mode |
|---|---|---|---|
| `mode` | `"page"` \| `"cursor"` | Always set | Always set |
| `page` | integer \| null | Current page number | Always `null` |
| `per_page` | integer | Effective rows per page (post-clamp) | Effective rows per page (post-clamp) |
| `total` | integer \| null | Total rows matching filters (exact) | `null` by default (see below for `total_hint`) |
| `total_pages` | integer \| null | `ceil(total / per_page)` | Always `null` |
| `cursor` | string \| null | Always `null` | The cursor that produced *this* page (echoes the request cursor, or `null` for the first page) |
| `next_cursor` | string \| null | Always `null` | Opaque cursor to fetch the next page; `null` if this is the last page |
| `prev_cursor` | string \| null | Always `null` | Opaque cursor to fetch the previous page; `null` if this is the first page |
| `has_more` | boolean | `page < total_pages` | `next_cursor !== null` |

`total` in cursor mode is intentionally `null` on unbounded tables (`ledger_entries`, `journal_lines`, `audit_logs`) because computing it requires a full scan of the filtered set — exactly the cost cursor pagination exists to avoid. Where a company genuinely needs an approximate count for UI purposes (e.g. "about 12,000 entries"), the endpoint may expose a separate, explicitly-named, cached field `meta.pagination.total_hint` (an approximate count refreshed periodically from `pg_stat_user_tables` reltuples or a Redis-cached exact count with a TTL), never silently substituted for `total`. Clients must never treat `total_hint` as exact and must not use it to compute `total_pages`.

# Cursor Pagination

## Encoding

A cursor is an opaque, base64url-encoded (no padding) JSON object naming the sort column value(s) and the primary key of the last row on the current page — the exact tuple the keyset `WHERE` predicate needs to resume. It is never a raw row ID alone, because sorting is rarely by ID alone (see Sorting Interaction) and a single-column cursor cannot disambiguate rows that tie on the sort column.

Decoded shape for `sort=-posted_at` on `ledger_entries`:

```json
{ "posted_at": "2026-07-15T09:22:00Z", "id": 982034 }
```

Decoded shape for a compound sort `sort=-total_amount,id` on `invoices`:

```json
{ "total_amount": "1250.0000", "id": 55231 }
```

The cursor additionally embeds a short-lived HMAC-style integrity tag so the server can detect a tampered or hand-edited cursor before running it as a query predicate:

```json
{ "v": 1, "k": { "posted_at": "2026-07-15T09:22:00Z", "id": 982034 }, "sig": "3a9f1c7e2b8d4e56" }
```

`sig` is `substr(HMAC_SHA256(APP_CURSOR_KEY, json_encode(k) . sort . endpoint), 0, 16)`. The full structure is base64url-encoded to produce the string the client sees. Laravel implementation:

```php
namespace App\Services\Pagination;

class CursorCodec
{
    public function encode(array $keyValues, string $sort, string $endpoint): string
    {
        $payload = ['v' => 1, 'k' => $keyValues];
        $sig = substr(hash_hmac('sha256', json_encode($keyValues) . $sort . $endpoint, config('app.cursor_key')), 0, 16);
        $payload['sig'] = $sig;

        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    public function decode(string $cursor, string $sort, string $endpoint): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'));
        $payload = json_decode($json, true);

        if (!$payload || ($payload['v'] ?? null) !== 1) {
            throw new InvalidCursorException('Malformed cursor.');
        }

        $expected = substr(hash_hmac('sha256', json_encode($payload['k']) . $sort . $endpoint, config('app.cursor_key')), 0, 16);
        if (!hash_equals($expected, $payload['sig'] ?? '')) {
            throw new InvalidCursorException('Cursor signature mismatch — cursor was issued for a different sort order or endpoint.');
        }

        return $payload['k'];
    }
}
```

Binding the signature to `sort` and `endpoint` is what makes "Sorting Interaction" and cross-endpoint cursor misuse fail safely (see below) instead of silently returning wrong or corrupted pages.

## Stability

A cursor must remain valid and produce a correct "next batch after this point" result even if rows are inserted, updated, or soft-deleted between issuing the cursor and consuming it — this is the entire point of keyset pagination over offset. Stability rests on two properties the schema and query MUST guarantee:

1. **The sort key is (effectively) monotonic and unique when combined with the primary key.** `ORDER BY posted_at DESC, id DESC` never has an ambiguous "next row" because `(posted_at, id)` is unique — `id` is the tie-breaker for any two rows sharing a `posted_at` timestamp (common when a batch of journal lines posts in the same request/transaction). Every cursor-mode endpoint's sort MUST append the primary key as the final tie-breaker automatically, whether or not the client's `sort` param includes it — the server appends `,id` (or `,-id` matching the last key's direction) server-side if absent.
2. **Rows already returned are never re-fetched by construction of the `WHERE` predicate**, because the predicate is a strict inequality on the last-seen tuple: `WHERE (posted_at, id) < (:last_posted_at, :last_id)` (for descending order) — not an offset count. A new row inserted with a *later* `posted_at` than the cursor's position sorts *before* the cursor's position and is simply never visited by continuing to page forward from that cursor — it does not shift any row the client has already seen or will see next. A new row inserted *behind* the cursor's position (i.e., it would have appeared on a page already fetched) is naturally picked up if the client re-fetches from the start, and is invisible to a client that only pages forward from an already-issued cursor — this is standard, accepted keyset-pagination semantics and is explicitly called out to integrators as "you may not see rows inserted behind your cursor without starting a fresh page-1 fetch."

For `journal_lines` and `ledger_entries` specifically — QAYD's largest, highest-write-concurrency tables — the default cursor sort is `posted_at DESC, id DESC`, using the `idx_ledger_entries_company_account_posted` composite index (`company_id, account_id, posted_at DESC, id DESC`) so the keyset predicate is a pure index range seek, never a sequential scan, even filtered by `account_id` and scoped to `company_id`. See Performance for the full index list.

## Large tables: journal_lines / ledger_entries

`GET /api/v1/accounting/ledger-entries` is cursor-only (no `page` mode) precisely because it is the table most likely to hold tens of millions of rows per company after a few years of operation, and it is the table under the heaviest write concurrency (every journal posting inserts new rows). Its resource doc (Journal & Ledger module) specifies:

- Default sort: `posted_at DESC, id DESC` (most recent activity first — the common "recent ledger activity" query pattern).
- Supported alternate sorts: `posted_at ASC, id ASC` (chronological, for statement generation) and `account_id, posted_at DESC, id DESC` (grouped-by-account browsing) — each alternate sort has its own supporting index (see Performance).
- `filter[account_id]`, `filter[cost_center_id]`, `filter[project_id]`, `filter[date_from]`, `filter[date_to]` are mandatory-adjacent filters in practice: an unfiltered cursor scan across all accounts for a large company is legal but discouraged in the endpoint doc's "Performance Notes," which recommends UIs always scope by `account_id` or a date range.
- `total` is always `null`; the endpoint returns `total_hint` computed from a Redis-cached count keyed by `(company_id, filter hash)` with a 5-minute TTL, refreshed asynchronously by a queued job rather than on the request path.

`journal_lines` (the write-side table, as opposed to `ledger_entries`, its posted-projection read model) is queried directly only via `GET /api/v1/accounting/journal-entries/{id}` (nested lines of one entry, never independently paginated at scale — an entry has at most a few hundred lines) — bulk cross-entry querying of lines goes through `ledger_entries`, which is the documented read model for exactly this reason (see Related Modules in the Accounting Engine document). This document does not re-litigate that architecture; it only notes why `ledger_entries`, not `journal_lines`, is the cursor-paginated bulk-read surface.

# Links

To spare clients from constructing pagination URLs by hand, every list response includes a `links` object as a sibling of `data`, following the same envelope pattern across both modes. This is a convenience mirror of `meta.pagination` — the source of truth remains `meta.pagination.next_cursor` / `page`, but `links` gives ready-to-fetch absolute URLs:

Page mode:

```json
{
  "success": true,
  "data": [ /* ... */ ],
  "message": "Accounts retrieved successfully.",
  "errors": [],
  "meta": { "pagination": { "mode": "page", "page": 2, "per_page": 25, "total": 178, "total_pages": 8, "has_more": true } },
  "links": {
    "self": "https://api.qayd.app/api/v1/accounting/accounts?page=2&per_page=25",
    "first": "https://api.qayd.app/api/v1/accounting/accounts?page=1&per_page=25",
    "prev": "https://api.qayd.app/api/v1/accounting/accounts?page=1&per_page=25",
    "next": "https://api.qayd.app/api/v1/accounting/accounts?page=3&per_page=25",
    "last": "https://api.qayd.app/api/v1/accounting/accounts?page=8&per_page=25"
  },
  "request_id": "6f2c9e2a-9b3f-4b41-8a70-1a1e0c9d4e77",
  "timestamp": "2026-07-16T12:00:03Z"
}
```

Cursor mode — `first` and `last` are omitted (there is no cheap "last page" concept in keyset pagination; `last` would require the full-scan cost the strategy exists to avoid):

```json
{
  "links": {
    "self": "https://api.qayd.app/api/v1/accounting/ledger-entries?per_page=50&sort=-posted_at&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE1VDEwOjAwOjAwWiIsImlkIjo5ODIwNTB9",
    "next": "https://api.qayd.app/api/v1/accounting/ledger-entries?per_page=50&sort=-posted_at&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE0VDE2OjAxOjAwWiIsImlkIjo5ODE5ODh9",
    "prev": "https://api.qayd.app/api/v1/accounting/ledger-entries?per_page=50&sort=-posted_at&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE1VDEwOjAwOjAwWiIsImlkIjo5ODIwNTB9"
  }
}
```

`links.next` / `links.prev` are `null` (omitted key, present with `null` value for consistent client typing) when `next_cursor` / `prev_cursor` are `null`. All `links` values are fully qualified absolute URLs including scheme and host, so client SDKs can `fetch(links.next)` directly with only the `Authorization` and `X-Company-Id` headers reattached — headers are never embedded in the URL.

# Sorting Interaction

Sorting and pagination are coupled: the sort order defines what "next" means, so changing `sort` mid-pagination invalidates any cursor issued under the old sort.

- **Page mode**: changing `sort` between two requests for the *same* `page` number is legal and simply returns a different set of rows at that offset — there is no invalidation concept because page mode carries no memory of prior state. The client is responsible for resetting to `page=1` when it changes sort, the same as any traditional paginated table UI; the server does not reject a `sort` change combined with `page=4`, it just executes the reordered query at that offset.
- **Cursor mode**: a cursor is cryptographically bound to the `sort` string used to create it (see the `sig` computation in Cursor Pagination, which hashes in the `sort` parameter). If a client sends a previously issued cursor together with a *different* `sort` value than the one that produced it, the server detects the signature mismatch and returns `422`:

```json
{
  "success": false,
  "data": null,
  "message": "Invalid pagination cursor.",
  "errors": [
    {
      "field": "cursor",
      "code": "CURSOR_SORT_MISMATCH",
      "message": "This cursor was issued for a different sort order. Restart pagination from cursor=null with the new sort value."
    }
  ],
  "meta": { "pagination": null },
  "request_id": "1a2b3c4d-5e6f-4708-9a0b-1c2d3e4f5061",
  "timestamp": "2026-07-16T12:00:05Z"
}
```

This is a deliberate hard failure rather than a best-effort reinterpretation, because silently continuing to page under a mismatched sort would return rows in an order the client did not ask for and cannot make sense of — a correctness bug disguised as a working response. Client SDKs (PHP, JS/TS, Python, Flutter/Dart) enforce this locally too: the generated `PaginationCursor` type carries the `sort` it was issued under, and calling `.nextPage()` after mutating the request's sort throws a client-side `StaleCursorError` before the request is even sent, in addition to the server-side guard.

- **Default tie-breaker append**: as noted in Cursor Pagination, the server always appends the primary key to whatever `sort` the client requests, for cursor-mode endpoints, to guarantee a total order. This appended key is part of what gets signed — a client that explicitly sends `sort=-posted_at,id` and one that sends `sort=-posted_at` (letting the server append `,id`) receive cursors signed against the *server's fully-resolved* sort string, not the client's raw input, so both forms interoperate and produce identical, valid cursors.
- **Filter changes** are treated identically to sort changes for cursor validity: the cursor signature also binds `endpoint`, but not the filter query string, by design — filters change which rows exist in the keyset walk, not the ordering rule itself, so a cursor issued under `filter[account_id]=1042` combined with a new request for `filter[account_id]=1099` is not rejected outright; it is honored, and simply resumes the *new* filtered set from the same `(posted_at, id)` position, which is a well-defined (if usually not what the client wants) operation. Resource docs that consider this confusing for a given endpoint may opt to bind specific filter fields into the cursor signature as well — this is called out per-endpoint if applicable; the ledger-entries endpoint does not, since scoping by `account_id` mid-pagination is a supported "drill in" pattern in the family-caregiver-style dashboards this platform's UIs use elsewhere is not relevant here, but the equivalent "switch account mid-scroll" pattern in the Ledger UI relies on this permissiveness.

# Performance

**Keyset over offset for large tables.** The core performance rule: any endpoint over a table expected to exceed roughly 50,000 rows per company (`journal_lines`, `ledger_entries`, `stock_movements`, `audit_logs`, `bank_transactions`, `ai_messages`, `notifications`) MUST use cursor (keyset) pagination, never `OFFSET`. `OFFSET n` requires PostgreSQL to scan and discard `n` rows before returning the page; on a `10,000,000`-row `ledger_entries` table, `page=40000&per_page=25` (offset 1,000,000) takes orders of magnitude longer than `page=1`, and gets slower the deeper a client pages — an attacker-adjacent worst case is a client (or bot) sweeping every page sequentially, degrading database performance company-wide since PostgreSQL connections are shared per read-replica. Keyset queries have flat cost: `WHERE (posted_at, id) < (:v1, :v2) ORDER BY posted_at DESC, id DESC LIMIT 50` costs the same whether it is "page 2" or "page 40,000" worth of depth into the result, because it seeks directly via the index rather than counting.

**Required indexes.** Every column combination used in a cursor `ORDER BY` / `WHERE` keyset predicate must have a matching composite B-tree index with `company_id` (and, where applicable, the primary filter column) leading, so the planner can satisfy `company_id = ? AND account_id = ? ORDER BY posted_at DESC, id DESC` as a single index range scan:

```sql
-- ledger_entries: default sort (recent activity per account)
CREATE INDEX idx_ledger_entries_company_account_posted
    ON ledger_entries (company_id, account_id, posted_at DESC, id DESC);

-- ledger_entries: chronological statement generation
CREATE INDEX idx_ledger_entries_company_posted_asc
    ON ledger_entries (company_id, posted_at ASC, id ASC);

-- ledger_entries: cross-account browsing grouped by account
CREATE INDEX idx_ledger_entries_company_acct_posted_asc
    ON ledger_entries (company_id, account_id, posted_at ASC, id ASC)
    WHERE deleted_at IS NULL;

-- journal_lines: nested-under-entry read (bounded, but still indexed)
CREATE INDEX idx_journal_lines_entry ON journal_lines (journal_entry_id, id);

-- audit_logs: cursor by recency, scoped to company and record
CREATE INDEX idx_audit_logs_company_created
    ON audit_logs (company_id, created_at DESC, id DESC);

-- stock_movements: cursor by recency, scoped to company and product/warehouse
CREATE INDEX idx_stock_movements_company_product_created
    ON stock_movements (company_id, product_id, created_at DESC, id DESC);
```

Every offset-mode endpoint over a *small* table also gets a supporting index for its default sort (e.g. `CREATE INDEX idx_accounts_company_code ON accounts (company_id, code);`), because even a cheap `OFFSET` still benefits from an index-only scan rather than a sequential scan plus filesort.

**Avoiding COUNT(*) cost in page mode.** Laravel's `paginate()` runs a `COUNT(*)` matching the filtered `WHERE` clause on every single request, including deep pages, purely to compute `total`/`total_pages`. For endpoints over medium tables (`invoices`, `customers`) with heavy filters, this is bounded by the same indexes used for the main query and stays cheap because `company_id` scoping keeps the filtered set to, realistically, tens of thousands of rows at most for offset-mode tables (by the routing rule in Strategy, anything larger is cursor-only and skips `COUNT(*)` entirely, returning `total: null`).

**N+1 avoidance under pagination.** Every paginated Eloquent query in a controller MUST eager-load its declared relations (`with([...])`) before pagination executes, not after — pagination limits row count per page, but does nothing about per-row relation queries; a page of 25 invoices each lazy-loading `customer` and `invoiceItems` is 51 additional queries per request. Controllers use a `Service`-layer method that accepts the sort/filter/pagination params and returns an already-eager-loaded, already-paginated result set:

```php
namespace App\Services\Accounting;

class LedgerEntryService
{
    public function paginate(int $companyId, array $filters, string $sort, ?string $cursor, int $perPage): CursorPage
    {
        $query = LedgerEntry::query()
            ->where('company_id', $companyId)
            ->when($filters['account_id'] ?? null, fn ($q, $v) => $q->where('account_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('posted_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('posted_at', '<=', $v))
            ->with(['account:id,code,name_en,name_ar', 'journalEntry:id,reference,description']);

        return $this->cursorPaginator->paginate($query, $sort, $cursor, $perPage, endpoint: 'ledger-entries');
    }
}
```

**Read replicas.** List endpoints (all `GET` collection routes) are routed to PostgreSQL read replicas via Laravel's `sticky` connection config disabled for reads (list endpoints do not need same-request read-your-write consistency the way a single-resource fetch immediately after a write does); this is what makes Consistency Under Writes below relevant — replica lag is a real, bounded source of staleness.

# Consistency Under Writes

QAYD's ledger tables are under constant write pressure — journal posting, bank sync, inventory movement — while clients page through them. Both pagination modes give well-defined, documented (not accidental) consistency guarantees:

- **Page mode and "drift."** Because offset position is purely arithmetic (`row N of the current query`), rows inserted or deleted ahead of the client's current position shift every row after them by one slot. Concrete failure mode: a client fetches `page=1` (rows 1–25 by `created_at DESC`), a new row is inserted at the top between requests, then fetches `page=2` expecting rows 26–50 — but the insertion shifted everything down one slot, so `page=2` now starts at what was row 25 on the old ordering, meaning the client sees row 25 *again* (a duplicate) and never sees what was previously row 50 on that pass (a skip), until it eventually reaches the new end of the set. This is standard, well-known offset-pagination behavior; QAYD does not attempt to hide it in page mode, and documents it here explicitly so integrators do not mistake a duplicate/skipped row for a data bug. UIs built on page mode (chart of accounts browser, product catalog grid) are chosen for tables where this level of churn during a single browsing session is rare and the consequence is cosmetic, not financial.
- **Cursor mode and monotonic resumption.** As specified in Cursor Pagination → Stability, a keyset cursor only ever asks "give me rows strictly after this exact point in this exact order" — it cannot duplicate or skip a row relative to rows *already returned*, regardless of concurrent writes, because the predicate is not relative to a shifting count. The only externally visible effect of concurrent writes in cursor mode is: (a) new rows landing *ahead of* the client's cursor position in sort order become visible on the client's *next* forward page fetch (this is expected — the client is walking forward through time on `posted_at DESC`, so newer rows are "ahead" for descending order and are seen on `prev_cursor` walks, not `next_cursor` walks); (b) new rows landing *behind* the cursor's already-consumed range are never surfaced to that in-flight pagination walk, only to a fresh walk starting at `cursor=null`. This is stated explicitly to integrators building "live activity feed" style UIs on `ledger_entries` or `audit_logs`: use `prev_cursor` (walking toward newest) combined with the real-time Reverb WebSocket channel for the "new entries appeared above" banner pattern, not by re-polling `cursor=null` on a timer.
- **Soft deletes mid-pagination.** QAYD never hard-deletes financial records (`deleted_at`). A row soft-deleted between two page fetches disappears from both modes' results going forward (the base query always adds `WHERE deleted_at IS NULL` unless the endpoint explicitly supports `filter[include_deleted]=true` for audit/restore views, which is documented per-endpoint). In cursor mode, if the soft-deleted row was the exact row the current cursor points to, the keyset predicate still works correctly because it compares against the *value* of the sort/PK columns embedded in the cursor, not against the row's continued existence — the row does not need to still exist for `WHERE (posted_at, id) < (:v1, :v2)` to evaluate correctly.
- **Read-replica lag.** Because list endpoints read from replicas (see Performance), a row just written via a `POST`/`PATCH` on the primary may not yet be visible in a `GET` list call made microseconds later — typical replication lag is sub-100ms but is not zero. Endpoints that must reflect a just-created resource in a list view immediately (e.g., a UI that creates an invoice then immediately shows it in the invoice list) either read the created resource from the `201` response body directly and prepend it client-side, or the specific endpoint is marked `read_consistency: primary` in its resource doc to force that one list route off the replica — this is the exception, not the default, and is called out per-endpoint where relevant (e.g., the journal posting confirmation screen that must show the just-posted entry in the same request/response cycle uses the primary).

# Examples

**Example 1 — first page, offset mode, small table:**

Request:

```
GET /api/v1/accounting/accounts?per_page=10&sort=code HTTP/1.1
Host: api.qayd.app
Authorization: Bearer <token>
X-Company-Id: 4821
```

Response `200 OK`:

```json
{
  "success": true,
  "data": [
    { "id": 101, "code": "1000", "name_en": "Cash", "name_ar": "النقدية", "account_type": "asset", "status": "active" },
    { "id": 102, "code": "1010", "name_en": "Bank - KWD Current", "name_ar": "البنك - جاري دينار", "account_type": "asset", "status": "active" }
  ],
  "message": "Accounts retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": { "mode": "page", "page": 1, "per_page": 10, "total": 214, "total_pages": 22, "cursor": null, "next_cursor": null, "prev_cursor": null, "has_more": true }
  },
  "links": {
    "self": "https://api.qayd.app/api/v1/accounting/accounts?page=1&per_page=10&sort=code",
    "first": "https://api.qayd.app/api/v1/accounting/accounts?page=1&per_page=10&sort=code",
    "prev": null,
    "next": "https://api.qayd.app/api/v1/accounting/accounts?page=2&per_page=10&sort=code",
    "last": "https://api.qayd.app/api/v1/accounting/accounts?page=22&per_page=10&sort=code"
  },
  "request_id": "b1e2f3a4-5c6d-47e8-9f01-2a3b4c5d6e7f",
  "timestamp": "2026-07-16T12:01:00Z"
}
```

**Example 2 — cursor mode, first page, large table:**

Request:

```
GET /api/v1/accounting/ledger-entries?per_page=2&sort=-posted_at&filter[account_id]=101 HTTP/1.1
Host: api.qayd.app
Authorization: Bearer <token>
X-Company-Id: 4821
```

Response `200 OK`:

```json
{
  "success": true,
  "data": [
    { "id": 982050, "journal_entry_id": 55210, "account_id": 101, "debit": "1500.0000", "credit": "0.0000", "currency_code": "KWD", "posted_at": "2026-07-15T10:00:00Z" },
    { "id": 981988, "journal_entry_id": 55198, "account_id": 101, "debit": "0.0000", "credit": "320.0000", "currency_code": "KWD", "posted_at": "2026-07-14T16:01:00Z" }
  ],
  "message": "Ledger entries retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": {
      "mode": "cursor", "page": null, "per_page": 2, "total": null, "total_hint": 48213, "total_pages": null,
      "cursor": null,
      "next_cursor": "eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE0VDE2OjAxOjAwWiIsImlkIjo5ODE5ODh9",
      "prev_cursor": null,
      "has_more": true
    }
  },
  "links": {
    "self": "https://api.qayd.app/api/v1/accounting/ledger-entries?per_page=2&sort=-posted_at&filter[account_id]=101",
    "next": "https://api.qayd.app/api/v1/accounting/ledger-entries?per_page=2&sort=-posted_at&filter[account_id]=101&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE0VDE2OjAxOjAwWiIsImlkIjo5ODE5ODh9",
    "prev": null
  },
  "request_id": "c2d3e4f5-6a7b-4809-8c12-3d4e5f607182",
  "timestamp": "2026-07-16T12:01:05Z"
}
```

**Example 3 — following `next_cursor`:**

Request:

```
GET /api/v1/accounting/ledger-entries?per_page=2&sort=-posted_at&filter[account_id]=101&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE0VDE2OjAxOjAwWiIsImlkIjo5ODE5ODh9 HTTP/1.1
Host: api.qayd.app
Authorization: Bearer <token>
X-Company-Id: 4821
```

Response `200 OK` (illustrative subsequent page, `has_more: false` — end of the filtered set reached):

```json
{
  "success": true,
  "data": [
    { "id": 979102, "journal_entry_id": 55004, "account_id": 101, "debit": "0.0000", "credit": "1180.0000", "currency_code": "KWD", "posted_at": "2026-07-10T09:15:00Z" }
  ],
  "message": "Ledger entries retrieved successfully.",
  "errors": [],
  "meta": {
    "pagination": {
      "mode": "cursor", "page": null, "per_page": 2, "total": null, "total_hint": 48213, "total_pages": null,
      "cursor": "eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE0VDE2OjAxOjAwWiIsImlkIjo5ODE5ODh9",
      "next_cursor": null,
      "prev_cursor": "eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTEwVDA5OjE1OjAwWiIsImlkIjo5NzkxMDJ9",
      "has_more": false
    }
  },
  "links": {
    "self": "https://api.qayd.app/api/v1/accounting/ledger-entries?per_page=2&sort=-posted_at&filter[account_id]=101&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE0VDE2OjAxOjAwWiIsImlkIjo5ODE5ODh9",
    "next": null,
    "prev": "https://api.qayd.app/api/v1/accounting/ledger-entries?per_page=2&sort=-posted_at&filter[account_id]=101&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTEwVDA5OjE1OjAwWiIsImlkIjo5NzkxMDJ9"
  },
  "request_id": "d3e4f5a6-7b8c-4910-9d23-4e5f60718293",
  "timestamp": "2026-07-16T12:01:09Z"
}
```

**Example 4 — invalid cursor (tampered/expired signature):**

Response `422 Unprocessable Entity`:

```json
{
  "success": false,
  "data": null,
  "message": "Invalid pagination cursor.",
  "errors": [
    { "field": "cursor", "code": "CURSOR_SIGNATURE_INVALID", "message": "The provided cursor could not be verified. Restart pagination from cursor=null." }
  ],
  "meta": { "pagination": null },
  "request_id": "e4f5a6b7-8c9d-4a21-8e34-5f6071829304",
  "timestamp": "2026-07-16T12:01:12Z"
}
```

**Example 5 — mutually exclusive params:**

Request:

```
GET /api/v1/accounting/ledger-entries?page=2&cursor=eyJwb3N0ZWRfYXQiOiIyMDI2LTA3LTE1In0 HTTP/1.1
```

Response `422 Unprocessable Entity`:

```json
{
  "success": false,
  "data": null,
  "message": "Invalid pagination parameters.",
  "errors": [
    { "field": "cursor", "code": "PAGINATION_MODE_CONFLICT", "message": "page and cursor are mutually exclusive; provide only one." }
  ],
  "meta": { "pagination": null },
  "request_id": "f5a6b7c8-9d0e-4b32-9f45-607182930415",
  "timestamp": "2026-07-16T12:01:15Z"
}
```

# Edge Cases

- **Page beyond the end (offset mode).** Requesting `page=999` on a 22-page result set does not `404` — it returns `200 OK` with `data: []` and `meta.pagination.page: 999`, `total_pages: 22`, `has_more: false`. Returning `404` for "page too high" is deliberately avoided because the total can shrink between a client caching `total_pages` and a later request (rows deleted), and a `404` on a *list* endpoint is reserved for "the collection's parent resource does not exist" (e.g. company not found), not "this particular page is empty." Clients should treat an empty `data` array with `page > 1` as "you've reached the end," not as an error.
- **Cursor pointing past the end, or to a since-deleted row's exact position (cursor mode).** The keyset `WHERE` predicate is evaluated against the encoded values, not against the row's continued existence (see Consistency Under Writes → Soft deletes mid-pagination). If no rows satisfy the predicate, the response is `200 OK` with `data: []`, `next_cursor: null`, `has_more: false` — never an error. A cursor is a position in a timeline, not a claim that a specific row still exists.
- **Cursor with a malformed/non-base64 string, or valid base64 that decodes to non-JSON.** Returns `422` with `code: "CURSOR_MALFORMED"`, distinct from `CURSOR_SIGNATURE_INVALID` (used when decoding succeeds but the HMAC does not match) and `CURSOR_SORT_MISMATCH` (used when decoding and signature succeed against a *different* sort/endpoint pairing than the one requested) — three distinct, distinguishable error codes so client SDKs and support tooling can tell "user pasted garbage," "cursor is stale/tampered," and "cursor used against the wrong sort" apart.
- **Changing filters mid-cursor-walk that would exclude the cursor's anchor row from the new filtered set.** As covered in Sorting Interaction, this is permitted, not rejected: the walk simply resumes at the same `(sort_value, id)` position within the *new* filter's result set. If the new filter is disjoint from where the cursor points (e.g., switching `filter[account_id]` to an account whose entries are all chronologically after the cursor's anchor timestamp), the first response under the new filter may legitimately return `data: []`, `has_more: false` even though that account clearly has entries — because none of them satisfy "strictly after this timestamp." Client UIs that let users change a filter mid-scroll must reset `cursor` to `null` when the filter target fundamentally changes (e.g., switching the account being viewed), and only rely on cursor-preserving filter refinement for filters that narrow the *same* logical scope (e.g., adding `filter[date_from]` while keeping the same `account_id`).
- **`per_page` requested as `0` or negative.** Clamped to the minimum (`1`), not rejected — consistent with the general clamping philosophy in Defaults.
- **Sort on a non-existent or non-sortable field.** Returns `422` with `errors: [{"field":"sort","code":"SORT_FIELD_NOT_ALLOWED","message":"Field 'internal_notes' is not sortable on this resource. Allowed: posted_at, id, account_id."}]`. Each endpoint's resource doc enumerates its allowlisted sortable fields; sorting is never permitted on arbitrary raw columns to avoid unindexed sort performance cliffs and to avoid leaking column existence on fields the requester's role should not be able to key off of.
- **Empty collection entirely (zero rows match filters, first request).** `200 OK`, `data: []`, `total: 0` / `total_pages: 0` (page mode) or `next_cursor: null`, `has_more: false` (cursor mode) — never an error; an empty result set is a valid, successful answer to "list these resources."
- **Duplicate `sort` tie-breaker collision beyond the primary key.** Not possible by construction: the primary key is globally unique per table, so appending it as the final sort component (per Cursor Pagination → Stability) always fully disambiguates the order, even in pathological cases where every other sort column ties across many rows (e.g., a bulk import that posts thousands of `journal_lines` with an identical `posted_at` timestamp in one batch).
- **Company/tenant boundary and cursors.** A cursor is opaque but is not itself a security boundary — it does not need to encode `company_id`, because every query using it still applies `WHERE company_id = :active_company_id` from the authenticated request's `X-Company-Id` context before the keyset predicate is applied. A cursor generated under Company A's context and replayed under Company B's `X-Company-Id` header (a different, authorized company on the same user's token) simply resumes Company B's own timeline at that position — since `company_id` scoping is always ANDed first, this cannot leak Company A's rows to Company B; at worst it produces a confusing (but never cross-tenant) empty or mismatched-looking page, which is why per Sorting Interaction's guidance UIs reset `cursor=null` on any context switch, including switching the active company.

# End of Document
