# Filtering, Sorting & Search — QAYD API Layer

Version: 1.0
Status: Design Specification
Module: API
Submodule: Filtering, Sorting & Search

---

# Purpose

Every list endpoint in the QAYD API — `GET /api/v1/accounting/journal-entries`, `GET /api/v1/sales/invoices`,
`GET /api/v1/inventory/inventory-items`, and every other collection resource across Accounting, Sales,
Purchasing, Banking, Inventory, Payroll, Tax, and Reports — must expose the same predictable, composable
query-string grammar for filtering, sorting, and full-text search. This document is the single contract
that Laravel 12 controllers, FormRequests, and Query Builders on the backend, and the Next.js 15 web
client, Flutter mobile client, and FastAPI AI engine on the consuming side, all implement identically.

The goals are:

1. **Uniformity.** A caller who has learned to filter `invoices` by `status` and `total_amount` already
   knows how to filter `bills`, `journal_entries`, or `stock_movements`. There is exactly one filter
   grammar, one operator set, and one sort grammar for the entire platform.
2. **Security by default.** Every filterable and sortable field is drawn from an explicit per-resource
   whitelist. Company isolation (`company_id`) is never a client-controlled filter; it is injected
   server-side from the authenticated session and the `X-Company-Id` header. No raw SQL fragment ever
   originates from client input — every filter is compiled through Laravel's parameterized Eloquent/Query
   Builder API, never through string concatenation.
3. **Predictable performance.** Every field that is whitelisted for filtering or sorting is backed by a
   database index. Full-text search uses PostgreSQL `tsvector`/`pg_trgm`, not `LIKE '%term%'` table scans.
4. **Composability with pagination.** Filtering, sorting, and search parameters combine losslessly with
   both offset (`page`) and cursor pagination, and are echoed back in `meta` so clients can reconstruct
   "next page with the same view" deterministically.
5. **Reusability across UIs.** The same query grammar that a Next.js data-table sends is what a saved
   "Filtered View" persists, what a webhook replay can reference, and what the AI engine's Reporting Agent
   generates when it drafts an ad-hoc financial query — because it too must go through this same
   `/api/v1/...` surface rather than touching PostgreSQL directly.

This document defines the query-string grammar, the operator set, the per-resource field whitelist
mechanism, date/amount range conventions, full-text search, multi-field sorting, pagination interplay,
saved filters/views, the index and performance requirements every table owner must satisfy, SQL-injection
prevention at the framework level, worked examples with full envelopes, and the edge cases every
implementation must handle identically.

# Filter Syntax

All filtering uses PHP/Laravel's native bracketed query-string array syntax:

```
filter[<field>][<operator>]=<value>
```

- `<field>` is a field name drawn from the resource's **Allowed Filter Fields** whitelist (see below).
  Dotted paths (e.g. `filter[customer.name_en][like]=acme`) address a whitelisted relationship column,
  never an arbitrary join.
- `<operator>` is one of the operators defined in **Supported Operators**. Omitting the operator segment
  defaults to `eq`: `filter[status]=posted` is shorthand for `filter[status][eq]=posted`.
- `<value>` is URL-encoded. Comma-separated values are used for list operators (`in`, `not_in`, `between`).
- Multiple `filter[...]` parameters in one request are combined with logical **AND**.
- Multiple values passed to the same field+operator via repeated array notation
  (`filter[status][in]=draft,posted`) are combined with logical **OR** *within that single condition*.
- There is currently no client-composable OR-across-fields or nested boolean-group syntax. Complex
  cross-field OR logic is exposed instead as a named **Saved Filter** (server-defined) or a dedicated
  endpoint parameter (e.g. `q` for full-text search, which internally ORs across several text columns).
  This constraint is deliberate: it keeps every filter parseable without a recursive grammar and keeps
  the injection-prevention surface small and auditable.

**Canonical example — Laravel route and controller signature:**

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'company.context', 'permission:accounting.journal.read'])
    ->get('/api/v1/accounting/journal-entries', [JournalEntryController::class, 'index']);
```

```php
// app/Http/Controllers/Api/V1/Accounting/JournalEntryController.php
public function index(ListJournalEntriesRequest $request, JournalEntryFilterService $filters)
{
    $query = JournalEntry::query()->where('company_id', $request->companyId());

    $query = $filters->apply($query, $request->validated('filter', []));
    $query = $this->sortService->apply($query, $request->validated('sort'), JournalEntry::SORTABLE_FIELDS);

    $paginator = $query->paginate($request->perPage(), ['*'], 'page', $request->page());

    return JournalEntryResource::collection($paginator)->additional([
        'success' => true,
        'message' => 'Journal entries retrieved successfully.',
        'errors'  => [],
        'request_id' => (string) Str::uuid(),
        'timestamp'  => now()->toISOString(),
    ]);
}
```

A single request combining several filters, a search term, and multi-field sort:

```
GET /api/v1/accounting/journal-entries
    ?filter[status][in]=posted,reversed
    &filter[entry_date][between]=2026-01-01,2026-06-30
    &filter[total_debit][gte]=1000
    &filter[cost_center_id]=14
    &q=rent
    &sort=-entry_date,reference_no
    &page=1&per_page=25
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 8821
Accept: application/json
```

# Supported Operators

Every operator below is implemented as a named method on a shared `FilterOperator` enum/service
(`app/Services/Api/Filtering/FilterOperator.php`) so every module reuses the exact same compiled SQL
pattern rather than each controller inventing its own.

| Operator   | Meaning                          | Value format                          | Example                                          | Compiled SQL shape                          |
|------------|-----------------------------------|----------------------------------------|---------------------------------------------------|----------------------------------------------|
| `eq`       | Equals                             | scalar                                 | `filter[status][eq]=posted`                        | `WHERE status = ?`                          |
| `ne`       | Not equals                         | scalar                                 | `filter[status][ne]=draft`                          | `WHERE status <> ?`                         |
| `gt`       | Greater than                       | number, date, datetime                 | `filter[total_amount][gt]=500`                      | `WHERE total_amount > ?`                    |
| `gte`      | Greater than or equal               | number, date, datetime                 | `filter[entry_date][gte]=2026-01-01`                | `WHERE entry_date >= ?`                     |
| `lt`       | Less than                           | number, date, datetime                 | `filter[total_amount][lt]=10000`                    | `WHERE total_amount < ?`                    |
| `lte`      | Less than or equal                  | number, date, datetime                 | `filter[due_date][lte]=2026-08-01`                  | `WHERE due_date <= ?`                       |
| `in`       | Value is one of a list              | comma-separated list                   | `filter[status][in]=posted,reversed`                | `WHERE status IN (?, ?)`                    |
| `not_in`   | Value is none of a list             | comma-separated list                   | `filter[status][not_in]=voided`                     | `WHERE status NOT IN (?)`                   |
| `like`     | Case-insensitive partial match      | string (server wraps in `%..%`)        | `filter[description][like]=rent`                    | `WHERE description ILIKE ?` (`%rent%`)      |
| `between`  | Inclusive range                     | two comma-separated values             | `filter[entry_date][between]=2026-01-01,2026-01-31` | `WHERE entry_date BETWEEN ? AND ?`          |
| `is_null`  | Field is (or is not) null           | `true` / `false`                       | `filter[reversed_at][is_null]=true`                 | `WHERE reversed_at IS NULL`                 |

Rules that apply to every operator:

- Type coercion happens server-side per field definition (see Allowed Filter Fields). A `date`-typed
  field rejects a non-parseable value with `422`; it never silently casts.
- `in` / `not_in` accept at most **100** comma-separated values per request; beyond that the API returns
  `422` with `"errors": [{"field": "filter.status", "message": "Too many values for 'in' operator (max 100)."}]`.
- `like` always compiles to a **parameterized** `ILIKE` bound value — the wildcard characters `%rent%`
  are added by the server, never accepted raw from the client, and any literal `%`/`_` in the user's
  input is escaped before wrapping.
- `between` requires exactly two values in ascending order for numeric/date fields; if `low > high`, the
  API returns `422`. For `is_null`, any value other than the literal strings `true`/`false` returns `422`.
- Operators not listed here (e.g. regex matches, JSON path operators) are intentionally unsupported at the
  generic filter layer. A resource that needs JSONB containment (e.g. `custom_fields`) exposes it as an
  explicit, whitelisted field-operator pair rather than a generic capability — see Edge Cases.

# Allowed Filter Fields

No field is filterable or sortable unless it appears in that resource's explicit whitelist. This is the
primary defense against both SQL injection and inadvertent data exposure (e.g. filtering by a column that
should not be queryable cross-tenant, or that would force a full table scan).

Each Eloquent model that backs a list endpoint declares two static maps:

```php
// app/Models/JournalEntry.php
final class JournalEntry extends Model
{
    use SoftDeletes;

    /**
     * field => ['type' => ..., 'operators' => [...], 'column' => ... (optional, if different from key)]
     */
    public const FILTERABLE_FIELDS = [
        'status'          => ['type' => 'enum',    'operators' => ['eq', 'ne', 'in', 'not_in'],
                               'allowed' => ['draft', 'posted', 'reversed', 'voided']],
        'entry_date'      => ['type' => 'date',     'operators' => ['eq', 'gt', 'gte', 'lt', 'lte', 'between']],
        'reference_no'    => ['type' => 'string',   'operators' => ['eq', 'like']],
        'total_debit'     => ['type' => 'decimal',  'operators' => ['eq', 'gt', 'gte', 'lt', 'lte', 'between']],
        'total_credit'    => ['type' => 'decimal',  'operators' => ['eq', 'gt', 'gte', 'lt', 'lte', 'between']],
        'cost_center_id'  => ['type' => 'bigint',   'operators' => ['eq', 'in', 'not_in', 'is_null']],
        'project_id'      => ['type' => 'bigint',   'operators' => ['eq', 'in', 'not_in', 'is_null']],
        'department_id'   => ['type' => 'bigint',   'operators' => ['eq', 'in', 'not_in', 'is_null']],
        'branch_id'       => ['type' => 'bigint',   'operators' => ['eq', 'in', 'not_in', 'is_null']],
        'created_by'      => ['type' => 'bigint',   'operators' => ['eq', 'in']],
        'created_at'      => ['type' => 'datetime', 'operators' => ['gt', 'gte', 'lt', 'lte', 'between']],
        'reversed_at'     => ['type' => 'datetime', 'operators' => ['is_null', 'gte', 'lte']],
    ];

    public const SORTABLE_FIELDS = [
        'entry_date', 'reference_no', 'total_debit', 'total_credit', 'created_at', 'status',
    ];

    public const SEARCHABLE_FIELDS = ['reference_no', 'description', 'memo'];
}
```

Enforcement flow, executed by the shared `FilterService::apply()` before any query touches the database:

1. Reject any `filter[<field>]` key not present in `FILTERABLE_FIELDS` → `422`
   (`"message": "Unknown filter field: 'internal_notes'."`).
2. Reject any operator not present in that field's `operators` list → `422`
   (`"message": "Operator 'like' is not permitted on field 'total_debit'."`).
3. For `enum`-typed fields, reject any value not in `allowed` → `422`.
4. Every accepted filter is applied via Eloquent's `where()`/`whereIn()`/`whereBetween()`/`whereNull()`
   builder methods — which always bind parameters — never via `whereRaw()` with interpolated strings.
5. `company_id` (and `branch_id` when branch-scoping is active on the session) is **never** read from
   `filter[...]`; it is applied unconditionally from the authenticated context before user filters, and a
   client-supplied `filter[company_id]=...` is rejected outright with `403` — not merely ignored — because
   an attempt to filter by `company_id` is treated as a tenant-isolation probe.

Representative whitelist for three more resources, to make the pattern concrete:

| Resource (path)                                   | Filterable fields                                                                                  | Sortable fields                              | Searchable fields (`q`)                |
|-----------------------------------------------------|-----------------------------------------------------------------------------------------------------|-----------------------------------------------|------------------------------------------|
| `GET /api/v1/sales/invoices`                       | `status`, `customer_id`, `invoice_date`, `due_date`, `total_amount`, `currency_code`, `branch_id`   | `invoice_date`, `due_date`, `total_amount`, `invoice_no`, `status`, `created_at` | `invoice_no`, `customer.name_en`, `customer.name_ar` |
| `GET /api/v1/inventory/stock-movements`            | `product_id`, `warehouse_id`, `movement_type`, `movement_date`, `quantity`, `reference_type`        | `movement_date`, `quantity`, `created_at`     | `reference_no`, `notes`                |
| `GET /api/v1/banking/bank-transactions`            | `bank_account_id`, `transaction_date`, `amount`, `direction`, `is_reconciled`, `category`           | `transaction_date`, `amount`, `created_at`    | `description`, `counterparty_name`     |

# Date & Amount Range Filters

Date and amount ranges are the highest-frequency filter shape in a financial API (fiscal-period reports,
aging buckets, statement date ranges) and follow one consistent convention across every resource:

- **Dates** use ISO-8601 `YYYY-MM-DD`. **Datetimes** use full ISO-8601 with timezone offset
  (`2026-06-30T23:59:59+03:00`); the API always stores and compares in UTC internally and converts at the
  edge using the company's configured timezone (Kuwait: `Asia/Kuwait`, UTC+03:00, no DST).
- Every date/datetime field supports `gt`, `gte`, `lt`, `lte`, and `between`. `between` is the preferred
  shape for a bounded range and is inclusive on both ends:

  ```
  filter[entry_date][between]=2026-01-01,2026-01-31
  ```

  is equivalent to, and preferred over, the pair:

  ```
  filter[entry_date][gte]=2026-01-01&filter[entry_date][lte]=2026-01-31
  ```

  Both are valid; `between` is a single validated unit (rejects reversed bounds), while the `gte`+`lte`
  pair allows an open-ended range on one side (e.g. "everything from 2026-01-01 onward").
- **Amounts** (`NUMERIC(19,4)` columns such as `total_amount`, `total_debit`, `balance`) accept decimal
  strings with up to 4 fraction digits; the server parses with arbitrary-precision decimal handling
  (`brick/math` in PHP) — never floating point — to avoid rounding artifacts at filter boundaries.
  `between` on amount fields is inclusive: `filter[total_amount][between]=100.00,499.9999` matches a row
  of exactly `500.00`? No — `499.9999 < 500.00`, so it would not; boundary inclusivity is exact to the
  stored 4-decimal precision, with no implicit rounding.
- **Relative date shortcuts.** For common reporting windows, resources that expose a `report_definitions`-
  style date range also accept named relative windows via a dedicated `range` parameter layered on top of
  `filter[<date_field>]`, resolved server-side against the company's fiscal calendar
  (`fiscal_years`/`fiscal_periods`):

  | `range` value        | Resolves to (server-side, company-timezone aware)             |
  |-----------------------|------------------------------------------------------------------|
  | `today`               | `[start_of_today, end_of_today]`                                  |
  | `this_week`           | Monday–Sunday of the current week                                 |
  | `this_month`          | 1st–last day of the current calendar month                        |
  | `this_fiscal_period`  | Current open `fiscal_periods` row for the company                 |
  | `this_fiscal_year`    | Current open `fiscal_years` row for the company                   |
  | `last_30_days`        | `[today - 29 days, today]`                                        |
  | `last_quarter`        | Previous completed fiscal quarter per the company's fiscal calendar |

  `range` and an explicit `filter[<date_field>][between]` are mutually exclusive on the same field; sending
  both returns `422` (`"message": "Cannot combine 'range' with an explicit filter on 'entry_date'."`).
- **Aging buckets** (AR/AP specific) are exposed as a derived enum filter rather than raw date math, since
  "overdue" depends on `due_date` vs. "today" server-side, not a client-suppliable range:
  `filter[aging_bucket][in]=0-30,31-60,61-90,90+` on `GET /api/v1/sales/invoices` and
  `GET /api/v1/purchasing/bills`, computed via a generated column / view rather than trusted client math.

# Full-Text Search

A single `q` query parameter provides free-text search across a resource's `SEARCHABLE_FIELDS`, ORed
together, combined with `AND` against any other active `filter[...]` conditions.

```
GET /api/v1/sales/invoices?q=acme+rent&filter[status]=posted&sort=-invoice_date
```

**Implementation, two tiers depending on field cardinality and language needs:**

1. **Structured/code-like fields** (`invoice_no`, `reference_no`, SKUs, tax codes) use PostgreSQL
   `pg_trgm` trigram similarity for fast partial/typo-tolerant matching:

   ```sql
   CREATE EXTENSION IF NOT EXISTS pg_trgm;

   CREATE INDEX idx_invoices_invoice_no_trgm
       ON invoices USING gin (invoice_no gin_trgm_ops);
   ```

   Query shape (compiled by the Query Builder, parameters bound):

   ```sql
   SELECT * FROM invoices
   WHERE company_id = $1
     AND invoice_no % $2            -- similarity threshold match
   ORDER BY similarity(invoice_no, $2) DESC;
   ```

2. **Free-text/narrative fields** (`description`, `memo`, `notes`, bilingual `name_en`/`name_ar`) use a
   generated `tsvector` column with a `GIN` index, combining English and Arabic configurations since QAYD
   is bilingual:

   ```sql
   ALTER TABLE journal_entries
       ADD COLUMN search_vector tsvector
       GENERATED ALWAYS AS (
           setweight(to_tsvector('english', coalesce(reference_no, '')), 'A') ||
           setweight(to_tsvector('english', coalesce(description, '')), 'B') ||
           setweight(to_tsvector('simple',  coalesce(memo, '')), 'C')
       ) STORED;

   CREATE INDEX idx_journal_entries_search_vector
       ON journal_entries USING gin (search_vector);
   ```

   Arabic text uses PostgreSQL's `simple` configuration (no English stemming applied to Arabic tokens) and
   relies on `unaccent`-style normalization plus trigram fallback for partial word matches, since Arabic
   morphology does not benefit from the English `to_tsvector` stemmer:

   ```sql
   CREATE EXTENSION IF NOT EXISTS unaccent;
   CREATE INDEX idx_customers_name_ar_trgm
       ON customers USING gin (name_ar gin_trgm_ops);
   ```

   Query shape:

   ```sql
   SELECT *, ts_rank(search_vector, query) AS rank
   FROM journal_entries, to_tsquery('english', $1) query
   WHERE company_id = $2
     AND search_vector @@ query
   ORDER BY rank DESC;
   ```

**Grammar and behavior rules for `q`:**

- The raw `q` string is tokenized server-side; Laravel builds the `tsquery`/trigram predicate via
  parameter binding — the string is **never** interpolated into `to_tsquery()` as a raw SQL literal
  (naive `to_tsquery($userInput)` is itself a query-syntax injection risk independent of SQL injection,
  since malformed operators like `&`/`|`/`!` throw; QAYD uses `plainto_tsquery`/`websearch_to_tsquery`
  which treat the input as plain terms, not tsquery syntax).
- Multi-word `q` values are treated as an implicit AND of terms unless the client wraps a phrase in quotes
  (`q="rent+payment"`), which is passed to `phraseto_tsquery`.
- `q` results outside a `rank`-based `sort` still respect an explicit `sort=` parameter if the client
  supplies one; when no `sort` is given on a search request, results default to relevance rank descending,
  not `created_at`.
- Minimum query length is 2 characters; a shorter `q` returns `422`
  (`"message": "Search query 'q' must be at least 2 characters."`).
- Search is always additionally scoped by `company_id` — the GIN index above is a plain per-table index;
  it is not partial-per-company, so every search predicate carries the `company_id = ?` clause first,
  letting the planner use the composite `(company_id)` B-tree filter alongside the GIN bitmap scan.

# Sorting

```
sort=<field>              -- ascending
sort=-<field>             -- descending (leading hyphen)
sort=<field1>,-<field2>   -- multi-field, evaluated left to right
```

- Each `<field>` must appear in the resource's `SORTABLE_FIELDS` whitelist (see Allowed Filter Fields);
  an unlisted field returns `422` (`"message": "Field 'internal_score' is not sortable on this resource."`).
- Multi-field sort compiles to `ORDER BY entry_date DESC, reference_no ASC` in declaration order — the
  first field is primary, subsequent fields break ties.
- Default sort (when `sort` is omitted) is defined per resource and documented in that resource's own doc;
  the platform-wide fallback is `-created_at` (newest first).
- Sorting on a relationship column follows the same dotted-path whitelist convention as filtering
  (`sort=customer.name_en`), and is only permitted where the join is already required by an active filter
  or is a cheap, indexed foreign-key join — sorting must never silently introduce an unindexed join.
- Nullable sortable columns use `NULLS LAST` for ascending and `NULLS FIRST` for descending by platform
  convention, so nulls never unexpectedly surface at the top of a default ascending sort
  (`ORDER BY due_date ASC NULLS LAST`).
- When `q` is present and no explicit `sort` is given, the effective order is relevance rank (see Full-Text
  Search); an explicit `sort` always overrides relevance ordering.

# Combining With Pagination

Filtering, sorting, and search parameters are orthogonal to pagination and always compose:

```
GET /api/v1/accounting/journal-entries
    ?filter[status]=posted
    &sort=-entry_date
    &page=2&per_page=50
```

- **Offset pagination** (`page`, `per_page`) recomputes `COUNT(*)` and `LIMIT/OFFSET` against the *fully
  filtered/searched* query — `meta.pagination.total` always reflects the filtered result set size, never
  the unfiltered table size.
- **Cursor pagination** (`cursor` param, opaque base64-encoded token of the last-seen sort key tuple) is
  required for `per_page` beyond 200 rows and is strongly recommended for any endpoint paired with
  `sort` on a high-cardinality column, since offset pagination degrades on large `OFFSET` values. The
  cursor token encodes the *current filter+sort signature* so that a stale cursor (filters changed between
  requests) is rejected with `409 Conflict` rather than silently returning a wrong page:

  ```json
  {
    "success": false,
    "data": null,
    "message": "Cursor is no longer valid for the current filter/sort combination.",
    "errors": [{"field": "cursor", "message": "filter_signature_mismatch"}],
    "meta": {"pagination": {"page": null, "per_page": 50, "total": null, "cursor": null}},
    "request_id": "6f2c1e0a-2222-4b3a-9a11-000000000001",
    "timestamp": "2026-07-16T12:03:11Z"
  }
  ```

- `meta.pagination` in every successful response echoes the resolved `per_page` (server-clamped to the
  resource's max, default 25, hard cap 200 for offset / 500 for cursor) and, for cursor mode, the
  `cursor` to request the next page — `meta.pagination.cursor` is `null` when there is no further page.
- Filters and sort are **not** repeated inside `meta`; only pagination state lives there. Clients that need
  to persist "this exact view" (filters + sort + search) do so via **Saved Filters/Views**, not by parsing
  `meta`.

# Saved Filters/Views

Users frequently reuse a specific filter/sort/search combination (e.g. "Unpaid invoices over 90 days,
sorted by due date"). QAYD persists these as first-class records rather than leaving them to client-side
local storage, so a saved view is available across web and mobile and can be shared with teammates or
referenced by a scheduled `report_schedules` run.

```sql
CREATE TABLE saved_filters (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    branch_id     BIGINT NULL REFERENCES branches(id),
    user_id       BIGINT NOT NULL REFERENCES users(id),
    resource      VARCHAR(100) NOT NULL,        -- e.g. 'sales.invoices', 'accounting.journal-entries'
    name_en       VARCHAR(150) NOT NULL,
    name_ar       VARCHAR(150) NULL,
    query         JSONB NOT NULL,               -- {"filter": {...}, "sort": "...", "q": "..."}
    is_shared     BOOLEAN NOT NULL DEFAULT false, -- visible to whole company vs. owner only
    is_default    BOOLEAN NOT NULL DEFAULT false, -- auto-applied when opening this resource's list view
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL,
    CONSTRAINT chk_saved_filters_resource CHECK (resource <> '')
);

CREATE INDEX idx_saved_filters_company_resource ON saved_filters (company_id, resource);
CREATE INDEX idx_saved_filters_user ON saved_filters (user_id);
CREATE UNIQUE INDEX uq_saved_filters_default_per_user
    ON saved_filters (company_id, user_id, resource)
    WHERE is_default = true AND deleted_at IS NULL;
```

`query` is stored as the exact validated `filter`/`sort`/`q` structure — never as a raw query string —
so it is re-validated against the resource's *current* whitelist every time it is applied (a field that
was later de-whitelisted, e.g. after a column deprecation, is silently dropped from a stored view rather
than causing a `500` for the caller).

| Method | Path                                              | Permission                    | Description                                       |
|--------|----------------------------------------------------|--------------------------------|----------------------------------------------------|
| GET    | `/api/v1/saved-filters?resource=sales.invoices`     | `<resource>.read`              | List saved filters visible to the caller           |
| POST   | `/api/v1/saved-filters`                             | `<resource>.read`              | Create a saved filter/view                         |
| GET    | `/api/v1/saved-filters/{id}`                        | `<resource>.read` + ownership   | Retrieve one saved filter                          |
| PATCH  | `/api/v1/saved-filters/{id}`                        | owner or `<resource>.manage`   | Update name/query/sharing/default flag              |
| DELETE | `/api/v1/saved-filters/{id}`                        | owner or `<resource>.manage`   | Soft-delete a saved filter                         |
| POST   | `/api/v1/saved-filters/{id}/apply`                  | `<resource>.read`              | Returns the target list, pre-filtered by this view |

Example — creating and then applying a saved view:

```
POST /api/v1/saved-filters
X-Company-Id: 8821
Content-Type: application/json

{
  "resource": "sales.invoices",
  "name_en": "Overdue > 90 days",
  "name_ar": "متأخرة أكثر من 90 يوم",
  "query": {
    "filter": {"status": {"eq": "posted"}, "aging_bucket": {"in": ["90+"]}},
    "sort": "due_date",
    "q": null
  },
  "is_shared": true
}
```

```json
{
  "success": true,
  "data": {
    "id": 501,
    "resource": "sales.invoices",
    "name_en": "Overdue > 90 days",
    "name_ar": "متأخرة أكثر من 90 يوم",
    "query": {"filter": {"status": {"eq": "posted"}, "aging_bucket": {"in": ["90+"]}}, "sort": "due_date", "q": null},
    "is_shared": true,
    "is_default": false,
    "created_at": "2026-07-16T12:04:02Z"
  },
  "message": "Saved filter created successfully.",
  "errors": [],
  "meta": {"pagination": {"page": 1, "per_page": 25, "total": 1, "cursor": null}},
  "request_id": "9a11cd30-4444-4b9a-8c11-000000000002",
  "timestamp": "2026-07-16T12:04:02Z"
}
```

```
GET /api/v1/saved-filters/501/apply&page=1&per_page=25
```
resolves internally to the equivalent of
`GET /api/v1/sales/invoices?filter[status]=posted&filter[aging_bucket][in]=90%2B&sort=due_date&page=1&per_page=25`,
re-validated against the live `invoices` whitelist before execution.

# Performance & Index Requirements

Every field that appears in a `FILTERABLE_FIELDS` or `SORTABLE_FIELDS` whitelist is a contractual
obligation to index it — whitelisting a column without an index is treated as an implementation bug, not
a style preference, and is flagged in code review / CI.

**Index rules by field type:**

| Field pattern                                        | Required index                                                        |
|--------------------------------------------------------|--------------------------------------------------------------------------|
| `company_id` (always the first predicate)              | Composite leading column on every multi-column index below               |
| Foreign keys used in `eq`/`in` filters (`customer_id`, `product_id`, `cost_center_id`, ...) | `CREATE INDEX ... ON table (company_id, fk_column)`                     |
| Enum/status columns filtered frequently                | `CREATE INDEX ... ON table (company_id, status)`; consider partial index for hot status values, e.g. `WHERE status = 'draft'` |
| Date/datetime range columns (`entry_date`, `due_date`, `created_at`) | `CREATE INDEX ... ON table (company_id, entry_date)`, B-tree, supports `gt/gte/lt/lte/between` and `ORDER BY` |
| Amount columns used in range filters                   | `CREATE INDEX ... ON table (company_id, total_amount)` when independently filtered; otherwise covered by a composite with the date column when both are commonly filtered together |
| Free-text search columns                                | `GIN` index on generated `tsvector` (see Full-Text Search)               |
| Trigram/partial-match string columns                     | `GIN` index with `gin_trgm_ops`                                          |
| Multi-field sort combinations used by a default view    | A composite B-tree matching the sort column order, e.g. `(company_id, entry_date DESC, reference_no ASC)` |

Example composite index actually shipped for `journal_entries`:

```sql
CREATE INDEX idx_journal_entries_company_status_date
    ON journal_entries (company_id, status, entry_date DESC);

CREATE INDEX idx_journal_entries_company_cost_center
    ON journal_entries (company_id, cost_center_id)
    WHERE cost_center_id IS NOT NULL;
```

**Query-plan discipline:**

- Every new filterable field added to a resource requires an `EXPLAIN (ANALYZE, BUFFERS)` review in the
  PR before merge, confirming an index scan is used, not a sequential scan, at realistic row counts
  (backend CI seeds a 500k-row fixture per core financial table for this check).
- `per_page` is hard-capped (200 offset / 500 cursor) specifically so a pathological filter combination
  cannot force the database to materialize an unbounded result set.
- Redis caches the **count** portion of expensive filtered queries (e.g. dashboard KPI tiles that reuse
  the same filter shape across many users) with a short TTL (30–60s) keyed on
  `company_id + resource + filter_signature_hash`; the underlying row data is never cached at the
  per-record level to avoid stale financial figures — only aggregate counts/sums used for UI badges.
- Full-text queries always carry `company_id` as a co-predicate so the planner can intersect the cheap
  B-tree bitmap with the GIN bitmap rather than scanning the GIN index across all tenants.
- `ORDER BY <sortable> ... LIMIT <per_page>` is index-satisfiable for every whitelisted sortable column,
  which is precisely why `SORTABLE_FIELDS` is a strict subset, not "any column on the model."

# SQL Injection Prevention

QAYD's filtering/sorting/search layer is designed so that **no client-supplied string ever reaches the
database as raw SQL**, at three independent layers:

1. **Whitelisting (structural defense).** As described in Allowed Filter Fields, a field name and an
   operator are only ever translated into a query if both are found in a static, developer-defined array
   on the model. There is no code path where a client-supplied field name is interpolated into a column
   reference — `Model::FILTERABLE_FIELDS` is the only source of column identifiers.
2. **Parameter binding (the query builder never concatenates).** Every terminal Eloquent/Query Builder
   call used by `FilterService` — `where`, `orWhere`, `whereIn`, `whereNotIn`, `whereBetween`,
   `whereNull`, `whereNotNull`, `whereRaw` with `?` placeholders only for computed expressions like
   `similarity(column, ?)` — binds values as PDO parameters. Values are never `sprintf`'d or string-
   concatenated into SQL text. `whereRaw`/`DB::raw` are permitted **only** for the fixed, hand-reviewed
   SQL fragments in this document (e.g. the `tsvector`/trigram expressions), never for a user-controlled
   field or value.
3. **Input validation (defense in depth before it reaches the filter compiler).** The `FormRequest` for
   every list endpoint validates the raw `filter` array's shape before `FilterService` ever sees it:

   ```php
   // app/Http/Requests/Api/V1/Accounting/ListJournalEntriesRequest.php
   public function rules(): array
   {
       return [
           'filter'          => ['sometimes', 'array'],
           'filter.*'        => ['array'],           // each field must map to an operator=>value array
           'q'               => ['sometimes', 'string', 'min:2', 'max:200'],
           'sort'            => ['sometimes', 'string', 'max:200', 'regex:/^-?[a-zA-Z0-9_.,-]+$/'],
           'page'            => ['sometimes', 'integer', 'min:1'],
           'per_page'        => ['sometimes', 'integer', 'min:1', 'max:200'],
           'cursor'          => ['sometimes', 'string', 'max:500'],
       ];
   }
   ```

   The `sort` regex rejects anything containing whitespace, quotes, semicolons, SQL comment markers
   (`--`, `/*`), or parentheses before the value ever reaches field-whitelist resolution — a second,
   independent barrier even though whitelist lookup alone would already reject a malicious field name.

4. **Enum/type coercion closes the value-side gap.** Even a whitelisted, correctly-parameterized field can
   be abused if its *value* is trusted blindly for downstream use (e.g. building a dynamic report label).
   Enum-typed filters (`status`, `movement_type`, `direction`) are checked against `allowed` values server-
   side; anything outside the list is a `422`, not silently passed through to the database as a bound
   parameter that happens not to match any row.
5. **Least privilege at the database role level.** The application's PostgreSQL role used by Laravel has
   no `DROP`/`ALTER`/`CREATE` grants at runtime, and no `pg_read_file`/superuser extensions — so even a
   hypothetical injection that bypassed layers 1–4 could not escalate beyond row-level data access already
   permitted to the authenticated tenant, and RLS-equivalent `company_id` scoping (enforced at the
   Eloquent global-scope layer, described in the Accounting/Multi-tenancy docs) still applies.
6. **Full-text search specifically** never passes `q` to `to_tsquery()` raw; it uses `plainto_tsquery`/
   `websearch_to_tsquery`, which treat operators like `&`, `|`, `!`, `<->` in the user's input as literal
   search terms rather than tsquery syntax, closing a query-language-injection vector that is distinct from
   classic SQL injection but handled by the same "never trust raw input into a query DSL" principle.

# Examples

**1. Filter by status + amount range, sorted by date descending, page 1:**

```
GET /api/v1/sales/invoices?filter[status][in]=posted,partially_paid&filter[total_amount][gte]=100&sort=-invoice_date&page=1&per_page=25
Authorization: Bearer eyJhbGciOi...
X-Company-Id: 8821
```

```json
{
  "success": true,
  "data": [
    {
      "id": 9021, "invoice_no": "INV-2026-000441", "customer_id": 331,
      "status": "posted", "invoice_date": "2026-07-14", "due_date": "2026-08-13",
      "currency_code": "KWD", "total_amount": "1250.0000"
    },
    {
      "id": 9014, "invoice_no": "INV-2026-000434", "customer_id": 118,
      "status": "partially_paid", "invoice_date": "2026-07-10", "due_date": "2026-08-09",
      "currency_code": "KWD", "total_amount": "480.5000"
    }
  ],
  "message": "Invoices retrieved successfully.",
  "errors": [],
  "meta": {"pagination": {"page": 1, "per_page": 25, "total": 2, "cursor": null}},
  "request_id": "1a2b3c4d-0001-4a00-9000-000000000010",
  "timestamp": "2026-07-16T12:10:00Z"
}
```

**2. Date-range filter using `between` on a fiscal reporting endpoint:**

```
GET /api/v1/accounting/journal-entries?filter[entry_date][between]=2026-06-01,2026-06-30&filter[status]=posted&sort=entry_date
```

```json
{
  "success": true,
  "data": [
    {"id": 55012, "reference_no": "JE-2026-06-0088", "entry_date": "2026-06-02", "status": "posted", "total_debit": "3200.0000", "total_credit": "3200.0000"}
  ],
  "message": "Journal entries retrieved successfully.",
  "errors": [],
  "meta": {"pagination": {"page": 1, "per_page": 25, "total": 1, "cursor": null}},
  "request_id": "1a2b3c4d-0002-4a00-9000-000000000011",
  "timestamp": "2026-07-16T12:11:00Z"
}
```

**3. Full-text search combined with a status filter:**

```
GET /api/v1/sales/invoices?q=acme+consulting&filter[status]=posted
```

```json
{
  "success": true,
  "data": [
    {"id": 8890, "invoice_no": "INV-2026-000410", "customer_id": 331, "status": "posted", "total_amount": "5000.0000"}
  ],
  "message": "Invoices retrieved successfully.",
  "errors": [],
  "meta": {"pagination": {"page": 1, "per_page": 25, "total": 1, "cursor": null}},
  "request_id": "1a2b3c4d-0003-4a00-9000-000000000012",
  "timestamp": "2026-07-16T12:12:00Z"
}
```

**4. Multi-field sort with cursor pagination on a large ledger view:**

```
GET /api/v1/accounting/ledger-entries?filter[account_id]=4021&sort=-entry_date,-id&per_page=100
```

```json
{
  "success": true,
  "data": [
    {"id": 771002, "account_id": 4021, "entry_date": "2026-07-15", "debit": "0.0000", "credit": "220.0000"},
    {"id": 771001, "account_id": 4021, "entry_date": "2026-07-15", "debit": "0.0000", "credit": "95.5000"}
  ],
  "message": "Ledger entries retrieved successfully.",
  "errors": [],
  "meta": {"pagination": {"page": null, "per_page": 100, "total": 4820, "cursor": "eyJlbnRyeV9kYXRlIjoiMjAyNi0wNy0xNSIsImlkIjo3NzEwMDF9"}},
  "request_id": "1a2b3c4d-0004-4a00-9000-000000000013",
  "timestamp": "2026-07-16T12:13:00Z"
}
```

**5. curl snippet exercising `is_null` and `not_in`:**

```bash
curl -G "https://api.qayd.app/api/v1/inventory/stock-movements" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Company-Id: 8821" \
  --data-urlencode "filter[reference_type][not_in]=adjustment,write_off" \
  --data-urlencode "filter[quantity][gt]=0" \
  --data-urlencode "sort=-movement_date"
```

**6. Rejected request — unknown filter field:**

```
GET /api/v1/sales/invoices?filter[internal_margin_pct][gt]=10
```

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    {"field": "filter.internal_margin_pct", "message": "Unknown filter field: 'internal_margin_pct'."}
  ],
  "meta": {"pagination": {"page": null, "per_page": 25, "total": null, "cursor": null}},
  "request_id": "1a2b3c4d-0005-4a00-9000-000000000014",
  "timestamp": "2026-07-16T12:14:00Z"
}
```

# Edge Cases

- **Filtering on a soft-deleted record.** All list endpoints default to excluding `deleted_at IS NOT NULL`
  rows. A client wanting to include soft-deleted rows must hold `<resource>.manage` (or higher) permission
  and pass the explicit flag `include_deleted=true` — never a `filter[deleted_at]` clause, since
  `deleted_at` is deliberately absent from every `FILTERABLE_FIELDS` whitelist to prevent a caller from
  reasoning about it as an ordinary filterable column.
- **Conflicting filters that can never match (`filter[total_amount][gt]=1000&filter[total_amount][lt]=500`).**
  The API does not attempt to detect logical impossibility; it compiles both predicates and returns an
  empty `data: []` with `meta.pagination.total: 0` — a `200`, not an error, since the request is
  syntactically and semantically valid, just empty.
- **`between` with reversed bounds** (`filter[entry_date][between]=2026-06-30,2026-06-01`) returns `422`
  rather than silently swapping the values, so a client bug surfaces immediately instead of returning
  confusing results.
- **Large `in` lists approaching the 100-value cap.** Requests at exactly 100 values succeed; 101+ returns
  `422`. Clients needing to filter by more identifiers than the cap should instead combine a narrower
  filter (date range, status) or use a Saved Filter backed by a join against a client-maintained ID list
  table — the generic `in` operator is intentionally not a bulk-lookup mechanism.
- **Currency-aware amount filters.** `filter[total_amount][gte]=1000` on a multi-currency resource
  (`invoices`) filters the **base-currency** amount column (`base_amount`) by default, not the
  transaction-currency `total_amount`, to keep comparisons meaningful across mixed-currency result sets;
  a resource that must filter transaction-currency amounts exposes a distinct, explicitly-named field
  (e.g. `filter[transaction_amount][gte]=...`) alongside an implicit `filter[currency_code]=...` guard,
  and the API rejects `filter[transaction_amount]` without an accompanying `currency_code` filter with
  `422`, since an unscoped cross-currency numeric comparison is not meaningful.
- **`q` matching zero rows vs. malformed input.** A well-formed but non-matching `q` returns `200` with
  empty `data`. A `q` shorter than 2 characters, or exceeding 200 characters, returns `422`. A `q`
  containing only stripped stop-words (e.g. `"the"`) resolves to an effectively empty `tsquery` and is
  treated as "no search," falling back to the filter-only result set with a `meta.warnings` note (a
  non-blocking sibling of `errors[]` reserved for advisory messages) —
  `"warnings": ["Search term had no indexable content; showing filtered results only."]`.
- **Sorting by a field the caller cannot otherwise see.** If RBAC field-level masking hides a column
  (rare, but used for e.g. certain payroll compensation fields), that field is also removed from
  `SORTABLE_FIELDS`/`FILTERABLE_FIELDS` resolution for that caller's role at request time — permission
  checks are applied before whitelist resolution, not after, so a masked field behaves identically to an
  unknown field (`422 Unknown filter field`) rather than leaking its existence via a `403`.
- **Timezone boundary ambiguity on date filters.** `filter[entry_date][eq]=2026-07-16` on a `DATE` column
  is unambiguous (no time component), but the equivalent filter against a `TIMESTAMPTZ` column
  (`created_at`) is expanded server-side to the full company-timezone day
  (`created_at >= '2026-07-16T00:00:00+03:00' AND created_at < '2026-07-17T00:00:00+03:00'`) rather than
  an exact-instant match, since a bare date against a timestamp column almost always means "that calendar
  day" to the caller.
- **Cursor reused after the underlying filter/sort signature changed.** Handled as shown in Combining With
  Pagination — `409 Conflict`, forcing the client to re-request page 1 with the new filter set rather than
  risk silently skipping or duplicating rows.
- **Saved Filter referencing a since-removed field.** As described in Saved Filters/Views, invalid fields
  inside a stored `query` JSONB are dropped silently at apply-time (not a hard error), and the response's
  `meta.warnings` notes which stored conditions were skipped, so a stale saved view degrades gracefully
  instead of becoming permanently unusable.
- **Simultaneous `filter[status][eq]` and `filter[status][in]` on the same field in one request.** The API
  treats each operator on a field as an independent AND'd condition rather than the last-one-wins; sending
  both compiles to `WHERE status = ? AND status IN (?, ?)`, which is very likely to under-return results
  unless the two conditions are logically compatible — this is treated as caller error, not a framework
  bug, and is not specially rejected, since it is a valid (if usually unintended) AND composition.
- **Empty `filter[status][in]=` (empty value after the equals sign).** Returns `422`
  (`"message": "Operator 'in' requires at least one value."`) rather than compiling to `WHERE status IN ()`,
  which PostgreSQL would otherwise reject at the SQL level with a less useful error.

# End of Document
