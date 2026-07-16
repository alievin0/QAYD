# Database Indexing Strategy — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_INDEXING
---

# Purpose

This document is the authoritative indexing specification for the QAYD Accounting Engine's PostgreSQL 15+ schema. It defines, table by table and index-type by index-type, every index QAYD's backend (Laravel 12 / PHP 8.4+, Repository + Service pattern) is expected to create, the exact `CREATE INDEX` DDL for each, the query patterns each index accelerates, and the operational discipline (monitoring, maintenance, anti-patterns, edge cases) required to keep those indexes healthy as the dataset grows from a single pilot company to thousands of multi-branch, multi-currency tenants running concurrent AI-driven and human-driven write traffic.

QAYD is multi-tenant at the row level: every business table carries a mandatory `company_id BIGINT NOT NULL REFERENCES companies(id)`, financial rows are never hard-deleted (`deleted_at TIMESTAMPTZ NULL`), and posted accounting rows are immutable. These three facts are the single biggest influence on this document's indexing decisions. Nearly every index in this system either (a) leads with `company_id` to keep tenant isolation cheap and to guarantee that the query planner never has to scan another tenant's rows, or (b) is a **partial index** filtered on `deleted_at IS NULL` and/or a `status` column, because the "live," "posted," or "active" subset of a table is what 95%+ of production queries touch, while the historical/soft-deleted/draft tail exists mostly for audit and rare lookups.

The database is PostgreSQL 15+, accessed through Laravel's Eloquent ORM and raw query builder, with Redis fronting hot read paths (account balances, dashboard aggregates) and Cloudflare R2 for large object storage (attachments, exports) — neither of which is an indexing concern, but both inform *why* certain OLTP-hot indexes below are narrower than a naive "index everything the AI might query" approach would produce: Redis absorbs the read-heavy repeated-aggregate traffic, so PostgreSQL indexes are optimized for the transactional read/write mix — point lookups, range scans bounded by fiscal period, and the search/autocomplete patterns the UI actually issues — not for arbitrary ad-hoc BI queries, which are routed to `report_runs` materializations instead of hitting OLTP tables directly.

Every `CREATE INDEX` statement in this document is written to run with `CONCURRENTLY` in production (omitted in some examples purely for readability inside migration files where a maintenance window is acceptable) and every index name follows the convention:

```
idx_<table>_<column_or_purpose>[_partial|_gin|_brin|_hash]
```

so that `pg_stat_user_indexes` output is self-describing without needing to cross-reference migration history. Unique constraints follow `uq_<table>_<columns>`, and exclusion constraints follow `exq_<table>_<columns>`.

# Indexing Principles

**1. Index for the query, not for the column.** An index is justified by a specific, named query pattern (a controller action, a report, an AI agent's read path) — never added speculatively "because it's a foreign key" or "because it might be filtered on someday." Every index below is tagged with the query pattern(s) it serves. If a query pattern is retired, its supporting index must be dropped in the same migration.

**2. `company_id` is the tenancy boundary, and it dominates column order.** For any composite index that includes `company_id` alongside other predicate columns, `company_id` is the leading column unless a more selective single-tenant lookup (e.g. `id`, a natural key, or a `deleted_at IS NULL` partial index scoped globally through a unique join) makes it redundant. This is not just a performance rule — it is a defense-in-depth control: query plans that must touch a `company_id`-first B-tree range before reaching any other tenant's rows make a company-scoping bug in application code (a missing `WHERE company_id = ?`) far more likely to surface as a slow, anomalous, all-tenant scan (visible in `pg_stat_statements` and alerting) rather than as a silent cross-tenant data leak that returns quickly because it hit a narrow single-column index.

**3. Selectivity governs whether an index is used at all.** PostgreSQL's planner will ignore a non-selective index and fall back to a sequential scan if the predicate matches a large fraction of rows — this is correct behavior, not a bug. Boolean/enum columns with few distinct values (e.g. `status` with 4 values across millions of rows) are indexed almost exclusively as **partial indexes** (filtering to the interesting subset, e.g. `status = 'posted'`) or as the trailing column of a composite index, never as a standalone B-tree over the full table.

**4. Every hot write-path table gets a soft-delete-aware partial index, not a full-table index with `deleted_at` as a filter column.** `WHERE deleted_at IS NULL` partial indexes are smaller, faster to scan, faster to maintain on every soft-delete `UPDATE`, and directly match the `SoftDeletes`-trait query shape Eloquent generates by default (`WHERE deleted_at IS NULL` is appended to every query unless `withTrashed()` is explicitly called).

**5. Write amplification is priced into every index decision.** Every additional index on a table adds write cost to every `INSERT`/`UPDATE`/`DELETE` (including soft-deletes, which are `UPDATE`s) that touches an indexed column, plus WAL volume, plus autovacuum work. High-write tables in QAYD — `journal_lines`, `ledger_entries`, `stock_movements`, `bank_transactions`, `audit_logs`, `notifications` — are held to a stricter index budget than low-write, read-heavy master-data tables like `accounts`, `customers`, `vendors`, `products`. As a rule of thumb this document targets 4–7 indexes (including the PK) on high-write ledger/log tables and allows 6–10 on read-heavy master-data tables that also support full-text/trigram search.

**6. HOT (Heap-Only Tuple) updates are protected by leaving update-hot columns unindexed and by fillfactor tuning.** Columns that are frequently updated in place without changing indexed values (e.g. `updated_at`, `updated_by`, non-indexed JSONB fields like `custom_fields` when not GIN-indexed) benefit from PostgreSQL's HOT update optimization, which avoids touching any index at all if no indexed column changed and the page has free space. Tables with frequent in-place updates (`inventory_items.quantity_on_hand`, `bank_accounts.current_balance`) are given a reduced `fillfactor` (typically 85–90) specifically to preserve HOT-update headroom — see the Optimization section.

**7. Prefer the narrowest index that still serves the query — covering indexes (`INCLUDE`) over wider composite keys.** When a query only needs a few extra columns returned alongside an equality/range predicate, those columns are added via `INCLUDE (...)`, not folded into the B-tree key itself. This keeps the key size (and thus tree depth and insert cost) minimal while still enabling an index-only scan for the common read shape.

**8. Every foreign key gets an index unless it is already the leading column of an existing composite or partial index.** PostgreSQL does **not** automatically index foreign keys (unlike some other RDBMSs). Unindexed FKs cause full-table scans on `ON DELETE`/`ON UPDATE` cascade checks and on the extremely common "show me all rows related to this parent" query (e.g. all `journal_lines` for a `journal_entries.id`, all `invoice_items` for an `invoices.id`). Every per-table plan below explicitly covers each FK.

**9. Text search uses `pg_trgm` + GIN for fuzzy/substring search, and `tsvector` + GIN for structured full-text search — chosen per field, not uniformly.** Customer/vendor/product name lookups in the UI's autocomplete widgets are substring- and typo-tolerant, which `pg_trgm` similarity/`ILIKE` serves well. Larger free-text fields (invoice/bill `notes`, `ai_conversations` content) use generated `tsvector` columns with language-aware `to_tsvector('english', ...)` (and a parallel Arabic configuration where bilingual columns are searched) for relevance-ranked full-text search.

**10. Index choice is re-validated against `EXPLAIN (ANALYZE, BUFFERS)` before merge, not assumed from column semantics.** This document specifies the intended index set; the Optimization and Monitoring sections describe the mandatory verification loop (plan inspection, `pg_stat_statements`, `pg_stat_user_indexes`) that confirms each index is actually chosen by the planner for its target query and is not sitting unused.

# Primary (B-tree PK)

Every table in the standard column set uses:

```sql
id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY
```

`PRIMARY KEY` on a single `BIGINT IDENTITY` column implicitly creates a unique B-tree index on `id` — this is the default, non-negotiable primary access path for every table and needs no explicit `CREATE INDEX` statement; PostgreSQL creates and names it automatically (e.g. `accounts_pkey`). `BIGINT IDENTITY` is chosen over `UUID` for all internal PKs for four concrete reasons that matter at QAYD's write volumes: (a) monotonically increasing integer PKs insert at the right edge of the B-tree, avoiding the random-insert page-split churn that random UUIDv4 PKs cause; (b) 8-byte keys keep every secondary index that references `id` (directly, or via FK) smaller than they would be with 16-byte UUID keys, which matters enormously on `journal_lines` and `stock_movements` at billions-of-rows scale; (c) sequential IDENTITY values compress far better in TOAST/WAL and in BRIN indexes on `id`-correlated columns; (d) integer PKs are cheaper to join in the multi-way joins the accounting engine relies on (`journal_lines` → `journal_entries` → `accounts` → `account_types`).

Representative PK declarations across the canonical schema (all follow the identical pattern; shown once per module for brevity — every table listed in "Per-Table Index Plan" declares its PK this way):

```sql
CREATE TABLE accounts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    -- ...
);

CREATE TABLE journal_lines (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    journal_entry_id BIGINT NOT NULL REFERENCES journal_entries(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    -- ...
);
```

Where a natural composite key exists conceptually (e.g. `stock_counts` × `stock_count_lines` at `product_id + warehouse_id`), QAYD still uses a surrogate `BIGINT IDENTITY` PK and enforces the natural key via a separate `UNIQUE` constraint (see Unique section) rather than a composite PK — this keeps every FK reference (`stock_count_line_id`) to a single 8-byte column instead of a multi-column composite FK, which is materially cheaper for both storage and join performance and is required by Eloquent's default single-column PK assumption.

**Partition-aware PKs.** For the two tables that are range-partitioned by time (`audit_logs`, `ledger_entries` — see BRIN and Maintenance sections), the PK is declared as `(id, created_at)` or `(id, entry_date)` — PostgreSQL requires the partition key to be part of any unique constraint on a partitioned table. Application code still treats `id` as if globally unique (IDENTITY sequences are never reset per-partition), but the physical PK index is composite for partitioning correctness:

```sql
CREATE TABLE audit_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- ...
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
```

# Unique

Uniqueness in QAYD is almost never "one column, globally unique" — it is virtually always **scoped to a company**, and it is virtually always **scoped to live rows**, because soft-deleted rows must not block a new row from reusing the same business code. The pattern is therefore a **partial unique index** on `(company_id, <natural_key_columns>) WHERE deleted_at IS NULL`, not a plain `UNIQUE` constraint.

```sql
-- Chart of Accounts: account code is unique per company, among live accounts.
CREATE UNIQUE INDEX uq_accounts_company_code
    ON accounts (company_id, code)
    WHERE deleted_at IS NULL;

-- Customers: customer code unique per company, live rows only.
CREATE UNIQUE INDEX uq_customers_company_code
    ON customers (company_id, code)
    WHERE deleted_at IS NULL;

-- Vendors: same pattern.
CREATE UNIQUE INDEX uq_vendors_company_code
    ON vendors (company_id, code)
    WHERE deleted_at IS NULL;

-- Products: SKU unique per company, live rows only.
CREATE UNIQUE INDEX uq_products_company_sku
    ON products (company_id, sku)
    WHERE deleted_at IS NULL;

-- Invoices: invoice number unique per company (never soft-deletable once posted,
-- but drafts can be discarded and their numbers must not be permanently burned
-- unless the numbering policy requires gapless sequences — see Edge Cases).
CREATE UNIQUE INDEX uq_invoices_company_number
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;

-- Bills: vendor bill number unique per company + vendor (two vendors may reuse
-- their own numbering schemes independently).
CREATE UNIQUE INDEX uq_bills_company_vendor_number
    ON bills (company_id, vendor_id, bill_number)
    WHERE deleted_at IS NULL;

-- Journal entries: entry number unique per company + fiscal year.
CREATE UNIQUE INDEX uq_journal_entries_company_fy_number
    ON journal_entries (company_id, fiscal_year_id, entry_number)
    WHERE deleted_at IS NULL;

-- Bank accounts: IBAN unique per company (a company should not register the
-- same bank account twice), live rows only.
CREATE UNIQUE INDEX uq_bank_accounts_company_iban
    ON bank_accounts (company_id, iban)
    WHERE deleted_at IS NULL;

-- Tax transactions: one tax transaction per source document + tax_code
-- (prevents double-taxing the same line under a race condition).
CREATE UNIQUE INDEX uq_tax_transactions_source_code
    ON tax_transactions (company_id, source_type, source_id, tax_code_id)
    WHERE deleted_at IS NULL;

-- Payroll runs: one run per company + employee-group + period (prevents
-- duplicate payroll execution for the same period).
CREATE UNIQUE INDEX uq_payroll_runs_company_period
    ON payroll_runs (company_id, pay_period_start, pay_period_end, branch_id)
    WHERE deleted_at IS NULL;
```

**Global (non-partial) uniqueness** is reserved for a small number of platform-level identifiers that are not company-scoped by design, e.g. `users.email` (a user account is global; company membership is modeled through `company_users`), and system-level lookup tables such as `account_types.code` where the code space is fixed and shared across all tenants (`ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE`):

```sql
CREATE UNIQUE INDEX uq_users_email ON users (lower(email));
CREATE UNIQUE INDEX uq_account_types_code ON account_types (code);
```

Note `lower(email)` — this is an **expression unique index** (see Expression section) so that `Ali@Example.com` and `ali@example.com` are treated as the same identity, which is the correct semantic for login lookups and is enforced at the database layer rather than relying solely on application-layer normalization.

**Composite natural keys that are not "the" identifying key but must still be unique** — e.g. `inventory_items` must have exactly one row per `(company_id, product_id, warehouse_id)` combination (stock is tracked per product per location, not per lot in the base table; lots/batches are tracked in `product_batches`):

```sql
CREATE UNIQUE INDEX uq_inventory_items_company_product_warehouse
    ON inventory_items (company_id, product_id, warehouse_id)
    WHERE deleted_at IS NULL;
```

# Composite

Composite (multi-column) B-tree indexes are the workhorse of this schema. The governing rule for column order is: **equality predicates first, in descending order of selectivity, then the range/sort predicate last.** PostgreSQL can use a leading subset of a composite index's columns, but it can only use a range comparison efficiently on the last column probed in a given scan — every column after the first range/inequality predicate becomes a filter rather than an index-condition, so range/date columns are always placed last, and any column meant to support `ORDER BY ... LIMIT` pagination is placed last to let the index directly satisfy the sort order without a separate `Sort` node.

```sql
-- Journal lines: "show me all lines posted to account X within date range" —
-- company_id (tenant scope, always equality) -> account_id (equality, highly
-- selective) -> entry_date (range, also gives free ORDER BY for statements).
CREATE INDEX idx_journal_lines_company_account_date
    ON journal_lines (company_id, account_id, entry_date);

-- Invoices: "list this customer's invoices, newest first" — the classic
-- list-view composite. company_id -> customer_id -> issue_date DESC.
CREATE INDEX idx_invoices_company_customer_issue_date
    ON invoices (company_id, customer_id, issue_date DESC);

-- Bills: mirror pattern for AP.
CREATE INDEX idx_bills_company_vendor_issue_date
    ON bills (company_id, vendor_id, issue_date DESC);

-- Stock movements: "movement history for a product at a warehouse, in order" —
-- three equality-ish scoping columns then the time range/order column.
CREATE INDEX idx_stock_movements_company_product_warehouse_date
    ON stock_movements (company_id, product_id, warehouse_id, movement_date DESC);

-- Bank transactions: reconciliation screen paginates by bank_account_id and
-- transaction_date, filtered by an is_reconciled flag (handled as a partial
-- index — see Partial section — but the base composite still needs to exist
-- for the unreconciled-and-reconciled combined date-range views used in
-- statement exports).
CREATE INDEX idx_bank_transactions_company_account_date
    ON bank_transactions (company_id, bank_account_id, transaction_date DESC);

-- Notifications: "unread notifications for this user, newest first" —
-- user_id leads because notifications are queried per-recipient, not
-- per-company (a user's notification bell spans whichever company context
-- they are in, filtered client-side by company via a WHERE on company_id
-- as the second predicate).
CREATE INDEX idx_notifications_user_company_created
    ON notifications (user_id, company_id, created_at DESC);

-- Audit logs: "audit trail for this specific record" — the single most
-- frequent audit_logs query (open a record's history drawer).
CREATE INDEX idx_audit_logs_company_auditable
    ON audit_logs (company_id, auditable_type, auditable_id, created_at DESC);

-- Payroll items: "all line items for a given payroll run" plus employee
-- drill-down.
CREATE INDEX idx_payroll_items_run_employee
    ON payroll_items (payroll_run_id, employee_id);

-- Invoice items: retrieving an invoice's lines in entry order.
CREATE INDEX idx_invoice_items_invoice_line_no
    ON invoice_items (invoice_id, line_number);
```

**Composite index column-order worked example.** Consider the query the Sales module issues on every invoice list page load:

```sql
SELECT id, invoice_number, customer_id, total_amount, status, due_date
FROM invoices
WHERE company_id = 42
  AND status = 'sent'
  AND due_date < now()
ORDER BY due_date ASC
LIMIT 25;
```

This is an "overdue invoices" query. `company_id` is equality and mandatory (leading column). `status = 'sent'` is low-cardinality (draft/sent/paid/void/overdue — 5–6 values), so it is a poor leading candidate on its own but works well as the second equality column once scoped by `company_id`, because within one tenant the sent-but-unpaid subset is typically small relative to all invoices. `due_date` is the range/sort column and goes last:

```sql
CREATE INDEX idx_invoices_company_status_due_date
    ON invoices (company_id, status, due_date)
    WHERE deleted_at IS NULL;
```

This single index also serves the AR aging report's per-bucket range scans (`due_date BETWEEN ... AND ...`) and the collections dashboard's `status IN ('sent','overdue')` filter (PostgreSQL can use a single-value bitmap-or expansion of an `IN` list against one composite index efficiently).

# GIN

GIN (Generalized Inverted Index) is used in QAYD for three distinct payload shapes: **JSONB containment/key-existence queries**, **`tsvector` full-text search**, and **`pg_trgm` trigram similarity/substring search**. All three share the same underlying index structure but require different operator classes.

**JSONB — `tags` and `custom_fields`.** Every bilingual/enterprise master-data table (per DESIGN_CONTEXT §9) carries `tags JSONB` and `custom_fields JSONB`. These are queried with containment (`@>`) and existence (`?`) operators from the AI layer (tag-based filtering, e.g. "find all vendors tagged high-risk") and from custom-field-driven saved views in the UI:

```sql
CREATE INDEX idx_customers_tags_gin
    ON customers USING GIN (tags jsonb_path_ops);

CREATE INDEX idx_vendors_custom_fields_gin
    ON vendors USING GIN (custom_fields);

CREATE INDEX idx_products_tags_gin
    ON products USING GIN (tags jsonb_path_ops);
```

`jsonb_path_ops` is preferred over the default `jsonb_ops` operator class for `tags` because `tags` is queried almost exclusively via `@>` containment (`tags @> '["vip"]'`) rather than via key-existence (`?`) or path queries — `jsonb_path_ops` produces a smaller index (roughly half the size of `jsonb_ops` for containment-only workloads) at the cost of not supporting the `?`/`?|`/`?&` existence operators, which is an acceptable trade because those operators are not part of the tag-filtering query surface. `custom_fields`, by contrast, is queried both by containment and by specific key lookup from dynamically-configured saved filters (`custom_fields -> 'warranty_months' = '12'`), so it keeps the default `jsonb_ops` class, which supports both containment and the `->`/`->>` key-extraction-then-filter path via a B-tree-over-expression fallback where needed (see Expression section for the targeted single-key case).

**Full-text search — `tsvector` generated columns.** Free-text fields that need relevance-ranked search (not just substring match) get a `GENERATED ALWAYS AS (...) STORED` `tsvector` column plus a GIN index, so the tsvector is computed once at write time rather than recomputed on every search read:

```sql
ALTER TABLE products
    ADD COLUMN search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(name_en, '')), 'A') ||
        setweight(to_tsvector('simple',  coalesce(name_ar, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(description, '')), 'B')
    ) STORED;

CREATE INDEX idx_products_search_vector_gin
    ON products USING GIN (search_vector);

ALTER TABLE customers
    ADD COLUMN search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(name_en, '')), 'A') ||
        setweight(to_tsvector('simple',  coalesce(name_ar, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(tax_number, '')), 'C')
    ) STORED;

CREATE INDEX idx_customers_search_vector_gin
    ON customers USING GIN (search_vector);
```

Arabic text uses the `simple` configuration (no stemming) rather than a language-specific Arabic configuration, because PostgreSQL's built-in text-search dictionaries do not ship an Arabic stemmer, and `simple` (token + lowercase/unaccent normalization only) produces predictable, debuggable substring-ish matching for Arabic company names, which in practice serve Gulf users better than an incorrect stemming attempt would. Query shape:

```sql
SELECT id, name_en, name_ar
FROM products
WHERE company_id = 42
  AND search_vector @@ to_tsquery('english', 'wireless & mouse')
ORDER BY ts_rank(search_vector, to_tsquery('english', 'wireless & mouse')) DESC
LIMIT 20;
```

Because this query is also always scoped by `company_id`, the composite in practice is planned as a bitmap-AND of the GIN index and a plain B-tree on `company_id` — see the `idx_products_company_status` partial index in the Per-Table plan, which the planner combines with `idx_products_search_vector_gin` via `BitmapAnd`.

**Trigram search — `pg_trgm` for autocomplete/typo-tolerant lookup.** Enable the extension once per database:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

Then index the columns the UI's live-search boxes hit with `ILIKE '%term%'` or `similarity()`:

```sql
CREATE INDEX idx_customers_name_en_trgm
    ON customers USING GIN (name_en gin_trgm_ops);

CREATE INDEX idx_vendors_name_en_trgm
    ON vendors USING GIN (name_en gin_trgm_ops);

CREATE INDEX idx_products_name_en_trgm
    ON products USING GIN (name_en gin_trgm_ops);

CREATE INDEX idx_products_sku_trgm
    ON products USING GIN (sku gin_trgm_ops);
```

`gin_trgm_ops` is chosen over `gist_trgm_ops` here specifically because these columns are read-mostly (master data changes far less often than it is searched), and GIN trigram indexes, while slightly more expensive to build/update than GiST trigram indexes, deliver materially faster lookups for the "many searches, few writes" access pattern that autocomplete represents — the opposite trade-off applies to `gist_trgm_ops`, covered next.

# GiST

GiST (Generalized Search Tree) earns its place in this schema for two specific capabilities that B-tree and GIN cannot provide: **range-type overlap/exclusion** and **write-heavy trigram indexing** where update throughput matters more than read latency.

**Range overlap — `fiscal_periods` and `price_list_items` validity windows.** A fiscal period is naturally a `daterange`, and QAYD must guarantee, at the database level, that no two active fiscal periods for the same company overlap — this is exactly what a GiST exclusion constraint enforces, closing a class of bug (overlapping periods causing double-posting ambiguity) that application-level validation alone cannot fully close under concurrent writes:

```sql
ALTER TABLE fiscal_periods
    ADD COLUMN period_range DATERANGE
    GENERATED ALWAYS AS (daterange(start_date, end_date, '[]')) STORED;

CREATE INDEX idx_fiscal_periods_range_gist
    ON fiscal_periods USING GIST (company_id, period_range);

ALTER TABLE fiscal_periods
    ADD CONSTRAINT exq_fiscal_periods_no_overlap
    EXCLUDE USING GIST (
        company_id WITH =,
        period_range WITH &&
    ) WHERE (deleted_at IS NULL);
```

The same pattern protects `price_list_items` (a product cannot have two overlapping active price rules on the same price list) and `vendor_contracts` (a vendor cannot have two overlapping active contracts of the same contract type):

```sql
ALTER TABLE price_list_items
    ADD COLUMN validity_range DATERANGE
    GENERATED ALWAYS AS (daterange(valid_from, valid_to, '[]')) STORED;

CREATE INDEX idx_price_list_items_validity_gist
    ON price_list_items USING GIST (price_list_id, product_id, validity_range);

ALTER TABLE price_list_items
    ADD CONSTRAINT exq_price_list_items_no_overlap
    EXCLUDE USING GIST (
        price_list_id WITH =,
        product_id WITH =,
        validity_range WITH &&
    ) WHERE (deleted_at IS NULL);
```

**Query pattern served:** "what price applies to product P on date D" becomes a single containment probe (`validity_range @> D::date`) instead of an `ORDER BY valid_from DESC LIMIT 1` heuristic that is both slower and semantically fragile around boundary dates:

```sql
SELECT unit_price
FROM price_list_items
WHERE price_list_id = 7
  AND product_id = 1345
  AND validity_range @> CURRENT_DATE
  AND deleted_at IS NULL;
```

**Write-heavy trigram — `gist_trgm_ops` on `attachments.filename`.** The polymorphic `attachments` table is high-volume (every invoice, bill, receipt, dose-adjacent document, etc. can carry attachments) and filename search is a secondary, lower-frequency admin feature (searching an audit export by filename), not a hot autocomplete path. Here the write/read ratio favors GiST's cheaper insert cost over GIN's cheaper lookup cost:

```sql
CREATE INDEX idx_attachments_filename_gist_trgm
    ON attachments USING GIST (filename gist_trgm_ops);
```

GiST is also the extension point reserved for a near-term roadmap item — geo-scoped delivery/branch lookups using PostGIS `geometry`/`geography` columns for warehouse and branch proximity queries — which is why the exclusion-constraint and trigram patterns above are documented in enough depth to extend directly once that column type is introduced (see Future Improvements in the parent module doc; out of scope for indexing DDL until the column exists).

# Partial

Partial indexes are the single highest-leverage indexing technique in this schema because of two schema-wide facts: (1) soft deletes mean every table's "live" rows are a subset, often a shrinking-percentage subset over time, of all physical rows; and (2) accounting/workflow `status` columns are low-cardinality and skewed — most historical rows settle into a small number of terminal statuses (`posted`, `paid`, `closed`) while the operationally interesting rows are the numerically small `draft`/`pending`/`open`/`unreconciled` working set that staff and the AI agents interact with continuously.

**The default soft-delete partial index**, applied to every business table's most common lookup column(s):

```sql
CREATE INDEX idx_accounts_company_active
    ON accounts (company_id, code)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_journal_entries_company_status_active
    ON journal_entries (company_id, status)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_customers_company_active
    ON customers (company_id, name_en)
    WHERE deleted_at IS NULL;
```

**Status-scoped partial indexes for the operational working set.** The overwhelming majority of application traffic queries the small "open" subset of a table, not the large "closed" archive. Indexing only the open subset keeps these indexes tiny (fitting comfortably in shared_buffers even for tables with hundreds of millions of historical rows) and keeps write cost bounded to the transition moment (an `UPDATE ... SET status = 'posted'` removes the row from the partial index's applicability, which PostgreSQL handles as a normal index delete+insert, not a special case, but the steady-state index size stays small because rows continuously age out):

```sql
-- Draft journal entries: this is what the accounting UI's "drafts" tab and the
-- posting-queue AI agent poll continuously.
CREATE INDEX idx_journal_entries_company_draft
    ON journal_entries (company_id, created_at)
    WHERE status = 'draft' AND deleted_at IS NULL;

-- Unposted/unreconciled bank transactions: reconciliation screen's default view.
CREATE INDEX idx_bank_transactions_unreconciled
    ON bank_transactions (company_id, bank_account_id, transaction_date)
    WHERE is_reconciled = false AND deleted_at IS NULL;

-- Open (unpaid/partially paid) invoices: AR dashboard, collections, aging.
CREATE INDEX idx_invoices_company_open
    ON invoices (company_id, due_date)
    WHERE status IN ('sent', 'overdue', 'partially_paid') AND deleted_at IS NULL;

-- Open (unpaid) bills: AP dashboard.
CREATE INDEX idx_bills_company_open
    ON bills (company_id, due_date)
    WHERE status IN ('received', 'overdue', 'partially_paid') AND deleted_at IS NULL;

-- Pending tax transactions awaiting filing.
CREATE INDEX idx_tax_transactions_pending
    ON tax_transactions (company_id, tax_period_id)
    WHERE status = 'pending' AND deleted_at IS NULL;

-- Unread notifications: the notification-bell badge count query, run on
-- essentially every page load, must be near-instant.
CREATE INDEX idx_notifications_unread
    ON notifications (user_id, company_id)
    WHERE read_at IS NULL;

-- Low-stock / reorder-point inventory: the inventory dashboard's alert widget.
CREATE INDEX idx_inventory_items_low_stock
    ON inventory_items (company_id, warehouse_id)
    WHERE quantity_on_hand <= reorder_point AND deleted_at IS NULL;

-- Failed/retryable payroll items (payroll exception queue).
CREATE INDEX idx_payroll_items_exceptions
    ON payroll_items (payroll_run_id)
    WHERE status IN ('failed', 'flagged') AND deleted_at IS NULL;
```

**Compounding partial + composite.** Partial indexes are frequently also composite, and the `WHERE` clause of a partial index must be either an exact textual match or a provably-implied subset of the query's `WHERE` clause for the planner to consider the index — this means the application/ORM layer's generated SQL predicate for "open invoices" must literally use `status IN ('sent','overdue','partially_paid')` (or a subset of it, or an equivalent the planner's predicate-implication logic can prove, such as `status = 'overdue'` alone, which is implied by the `IN` list) for `idx_invoices_company_open` to be selected; a differently-worded but logically equivalent predicate (e.g. `status <> 'paid' AND status <> 'draft' AND status <> 'void'`) is not guaranteed to be recognized as implying the indexed condition and should be avoided in favor of matching the indexed predicate's exact vocabulary. This is documented explicitly in the Repository layer's query-building conventions so raw-SQL and Eloquent scope definitions stay in lockstep with the partial index definitions.

# Expression

Expression indexes cover three recurring needs: **case-insensitive lookup**, **date-part extraction for period-bucketed reporting**, and **computed-column search on JSONB sub-keys** that don't warrant a full GIN index because only one specific key is queried at high frequency.

```sql
-- Case-insensitive email/username lookup (login, invite-acceptance flows).
CREATE UNIQUE INDEX uq_users_email_lower
    ON users (lower(email));

CREATE INDEX idx_customer_contacts_email_lower
    ON customer_contacts (lower(email));

-- Case-insensitive company-scoped code lookup where inputs may arrive in
-- mixed case from OCR/AI-extracted vendor bill numbers.
CREATE INDEX idx_bills_company_number_lower
    ON bills (company_id, lower(bill_number));

-- Month-bucketed ledger reporting: many financial statements group by
-- calendar month/year without a pre-existing fiscal_period_id filter
-- (e.g. ad hoc AI-generated trend queries). Indexing the extracted year+month
-- avoids a functional-scan of entry_date on every such report.
CREATE INDEX idx_journal_lines_company_year_month
    ON journal_lines (company_id, date_trunc('month', entry_date));

-- Single hot JSONB key: inventory_items.custom_fields ->> 'bin_location' is
-- queried by warehouse pick-list generation far more often than any other
-- custom field, so it earns a dedicated expression B-tree instead of relying
-- solely on the generic GIN index over the whole custom_fields document.
CREATE INDEX idx_inventory_items_bin_location
    ON inventory_items ((custom_fields ->> 'bin_location'))
    WHERE deleted_at IS NULL;

-- Age-in-days for overdue invoices, used by the collections-priority AI agent
-- to bucket accounts receivable into 0-30/31-60/61-90/90+ without recomputing
-- the interval on every scan of a large invoices table.
CREATE INDEX idx_invoices_days_overdue
    ON invoices ((CURRENT_DATE - due_date))
    WHERE status IN ('sent', 'overdue') AND deleted_at IS NULL;
```

The `idx_invoices_days_overdue` index deserves a caveat: because `CURRENT_DATE` is volatile (changes daily), the index does not need to be rebuilt daily — the *expression value stored per row* is fine to be "as of whenever the row was last touched" is a misunderstanding to avoid: PostgreSQL expression indexes are **not** materialized once and left stale; the expression is evaluated at each INSERT/UPDATE against the row's current column values combined with whatever the expression references, and `CURRENT_DATE` inside an index expression is evaluated at write time and therefore is **not** appropriate for a value that must change every day without the row being written. This index is included here as a **documented anti-pattern-turned-correct-pattern only for its specific narrow use** (see Anti-Patterns for the general prohibition on volatile functions in expression indexes) — QAYD's actual implementation instead indexes the stable `due_date` column (already covered by `idx_invoices_company_open`) and computes days-overdue in the application/report layer at query time via `CURRENT_DATE - due_date` as a **selected expression**, not an indexed one. The corrected index that IS deployed is simply `idx_invoices_company_open` from the Partial section; this expression-index example is retained here specifically as a worked lesson in the Edge Cases section's discussion of volatile-function pitfalls.

# BRIN

BRIN (Block Range Index) is reserved for large, strictly time-ordered, append-mostly tables where a B-tree's per-row granularity is wasted overhead relative to BRIN's per-block-range summary, and where physical insertion order correlates strongly with the indexed column — exactly the shape of `audit_logs`, `stock_movements`, `bank_transactions`, and `ledger_entries` at scale.

```sql
-- Audit logs: append-only, always inserted in created_at order, queried
-- almost exclusively by date-range ("show activity in the last 30 days")
-- once past the per-record audit trail (which uses the composite B-tree
-- idx_audit_logs_company_auditable instead).
CREATE INDEX idx_audit_logs_created_at_brin
    ON audit_logs USING BRIN (created_at) WITH (pages_per_range = 32);

-- Stock movements: append-only ledger of inventory events, insert-ordered
-- by movement_date in practice (backdated corrections are rare and tolerated
-- as minor BRIN summary imprecision — see Anti-Patterns/Edge Cases).
CREATE INDEX idx_stock_movements_movement_date_brin
    ON stock_movements USING BRIN (movement_date) WITH (pages_per_range = 64);

-- Bank transactions: imported in statement order, i.e. transaction_date order,
-- from bank feeds; large volume for high-transaction-count companies.
CREATE INDEX idx_bank_transactions_date_brin
    ON bank_transactions USING BRIN (transaction_date) WITH (pages_per_range = 64);

-- Ledger entries: the GL projection table, strictly append-ordered by
-- entry_date because it is only ever populated by the posting pipeline in
-- entry_date order (posting is sequential per fiscal period).
CREATE INDEX idx_ledger_entries_entry_date_brin
    ON ledger_entries USING BRIN (entry_date) WITH (pages_per_range = 64);

-- Notifications: high insert volume, read once (or never) then irrelevant;
-- BRIN on created_at supports the retention-cleanup job's range delete
-- ("purge notifications older than 180 days") without the write cost of
-- maintaining a B-tree that a purge job is the only long-term consumer of.
CREATE INDEX idx_notifications_created_at_brin
    ON notifications USING BRIN (created_at) WITH (pages_per_range = 32);
```

`pages_per_range` is tuned per table: a smaller value (32) gives finer block-range summaries (better selectivity, more index storage/maintenance cost) and is used on `audit_logs` and `notifications` where query ranges are often narrow (last N days); a larger value (64) is used on `stock_movements`, `bank_transactions`, and `ledger_entries` where typical queries span whole fiscal periods/months and coarser summaries cost less to maintain against high insert throughput. BRIN indexes are never used as the **sole** index for these tables — each also carries the composite B-tree indexes from the Composite section for the point/short-range lookups (a specific transaction by ID plus a small date window); BRIN specifically accelerates the wide-range analytical scans (month-over-month, quarter-over-quarter) that would otherwise force either a full sequential scan or an oversized B-tree range scan touching far more of the table than the query actually needs. BRIN's summarize-on-insert behavior is enabled by default in PG 15 (`autosummarize` reloption defaults appropriately when the table is a target of frequent inserts); this is explicitly set for clarity:

```sql
ALTER TABLE audit_logs SET (autosummarize = on);
ALTER TABLE stock_movements SET (autosummarize = on);
ALTER TABLE bank_transactions SET (autosummarize = on);
ALTER TABLE ledger_entries SET (autosummarize = on);
```

# Hash

Hash indexes are used sparingly in this schema — B-tree already handles equality lookups efficiently and additionally supports range queries a hash index cannot, so hash indexes are only justified where (a) the column is queried **exclusively** by exact-match equality, never by range or sort, and (b) the value is wide enough that a hash index's fixed-width, smaller-than-B-tree-for-wide-keys storage is a measurable win. In QAYD this applies to exactly two column shapes: **long opaque token/hash columns** and **webhook idempotency keys**.

```sql
-- Session/API tokens (Sanctum plaintext-token lookup column, distinct from
-- the hashed-token column Sanctum itself indexes internally): exact-match
-- only, never ranged, values are long random strings where hash indexing
-- avoids storing full-length keys in a B-tree.
CREATE INDEX idx_personal_access_tokens_token_hash
    ON personal_access_tokens USING HASH (token);

-- Webhook delivery idempotency: a webhook payload's dedupe key is a long
-- opaque hash (e.g. sha256 of payload + event type), checked only by
-- equality before re-delivering.
CREATE INDEX idx_webhook_deliveries_idempotency_hash
    ON webhook_deliveries USING HASH (idempotency_key);

-- Bank statement line dedupe key: bank feed imports compute a hash of
-- (bank_account_id, amount, value_date, raw_description) to detect and skip
-- duplicate imports of the same statement line on re-sync; equality-only.
CREATE INDEX idx_bank_statement_lines_dedupe_hash
    ON bank_statement_lines USING HASH (dedupe_hash);
```

Hash indexes in PostgreSQL 10+ are WAL-logged and crash-safe (this was not always true in older major versions — a fact worth stating explicitly since it is a common outdated objection to hash indexes), and support unique constraints as of PG 10, but QAYD does not rely on `UNIQUE ... USING HASH` — where uniqueness is required, a B-tree unique index is still used (per the Unique section) because unique constraint validation historically has the broadest tooling/replication support on B-tree, and because most of the "hash-worthy" columns above are dedupe/lookup keys where the application checks existence before insert rather than relying on the constraint to reject duplicates at commit time. The rule of thumb documented for engineers: **default to B-tree; reach for HASH only when profiling (`pg_stat_user_indexes` + `EXPLAIN`) shows a wide equality-only column where a B-tree's extra range-comparison support is pure overhead.**

# Per-Table Index Plan

This section enumerates, table by table, the complete index set for every table named in DESIGN_CONTEXT's canonical schema that this document is scoped to cover. For each table: the full `CREATE INDEX` DDL, and the specific query pattern(s) each index serves. Standard columns (`id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) are assumed present per DESIGN_CONTEXT §2 and are not re-declared in full `CREATE TABLE` form here — only the indexing DDL is shown, since table structure itself belongs to the Database Design section of the owning module's doc, not to this indexing-focused submodule.

## accounts

```sql
-- PK (implicit)
-- id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY

-- Tenant-scoped code uniqueness among live rows.
CREATE UNIQUE INDEX uq_accounts_company_code
    ON accounts (company_id, code)
    WHERE deleted_at IS NULL;

-- Chart-of-accounts tree traversal: "children of this account" is the
-- single most frequent accounts query (rendering the CoA tree widget).
CREATE INDEX idx_accounts_company_parent
    ON accounts (company_id, parent_id)
    WHERE deleted_at IS NULL;

-- Filter by account_type (asset/liability/equity/revenue/expense) for
-- financial statement generation (e.g. "all revenue accounts").
CREATE INDEX idx_accounts_company_type
    ON accounts (company_id, account_type_id)
    WHERE deleted_at IS NULL;

-- Active/inactive filter for account-picker dropdowns (only show postable,
-- active accounts to end users).
CREATE INDEX idx_accounts_company_active_status
    ON accounts (company_id, status)
    WHERE deleted_at IS NULL AND status = 'active';

-- Name search (trigram, covered above in the GIN section) — repeated here
-- for completeness of this table's full index set.
CREATE INDEX idx_accounts_name_en_trgm
    ON accounts USING GIN (name_en gin_trgm_ops);
```
Query patterns served: CoA tree render (`parent_id`), account picker (`status='active'`), trial balance/financial statement account filtering (`account_type_id`), account search box (trigram), uniqueness enforcement on `code`.

## journal_entries

```sql
CREATE UNIQUE INDEX uq_journal_entries_company_fy_number
    ON journal_entries (company_id, fiscal_year_id, entry_number)
    WHERE deleted_at IS NULL;

-- Draft queue (posting-pending AI agent + accountant "my drafts" tab).
CREATE INDEX idx_journal_entries_company_draft
    ON journal_entries (company_id, created_at)
    WHERE status = 'draft' AND deleted_at IS NULL;

-- Posted entries by date, for period close and GL drill-down navigation.
CREATE INDEX idx_journal_entries_company_posted_date
    ON journal_entries (company_id, entry_date)
    WHERE status = 'posted' AND deleted_at IS NULL;

-- Fiscal period scoping (period-close validation: "any unposted entries
-- remaining in this period?").
CREATE INDEX idx_journal_entries_company_fiscal_period
    ON journal_entries (company_id, fiscal_period_id, status)
    WHERE deleted_at IS NULL;

-- Reversal linkage: find the original entry a reversing entry points to,
-- and vice versa (both directions are queried — from the reversed entry,
-- "was I reversed", and from the reversing entry, "what did I reverse").
CREATE INDEX idx_journal_entries_reversed_from
    ON journal_entries (reversed_from_entry_id)
    WHERE reversed_from_entry_id IS NOT NULL;

-- Source-document linkage: journal entries auto-generated from invoices,
-- bills, payroll runs, etc. carry a polymorphic source reference, queried
-- from the source document's detail screen ("view the journal entry this
-- invoice posted").
CREATE INDEX idx_journal_entries_source
    ON journal_entries (company_id, source_type, source_id)
    WHERE deleted_at IS NULL;
```
Query patterns served: draft queue polling, period-close completeness checks, GL drill-down by date, reversal chain lookups, "view posting" from any source document (invoice, bill, payroll run, tax transaction).

## journal_lines

```sql
-- FK to parent entry — retrieving all lines of an entry (every entry-detail
-- page load) and required for cascade-delete-check performance.
CREATE INDEX idx_journal_lines_entry
    ON journal_lines (journal_entry_id);

-- Account activity + statement-of-account style range scans (covered in
-- Composite section, repeated here for table completeness).
CREATE INDEX idx_journal_lines_company_account_date
    ON journal_lines (company_id, account_id, entry_date);

-- Dimension-scoped reporting: cost-center and project P&L rely on filtering
-- lines by dimension.
CREATE INDEX idx_journal_lines_company_cost_center
    ON journal_lines (company_id, cost_center_id, entry_date)
    WHERE cost_center_id IS NOT NULL;

CREATE INDEX idx_journal_lines_company_project
    ON journal_lines (company_id, project_id, entry_date)
    WHERE project_id IS NOT NULL;

-- Month-bucketed trend reporting (expression index, repeated for table
-- completeness — see Expression section).
CREATE INDEX idx_journal_lines_company_year_month
    ON journal_lines (company_id, date_trunc('month', entry_date));

-- Covering index for trial-balance aggregation: the trial balance query
-- (SUM debit, SUM credit GROUP BY account_id) is one of the highest-value
-- targets for an index-only scan.
CREATE INDEX idx_journal_lines_trial_balance_covering
    ON journal_lines (company_id, account_id)
    INCLUDE (debit_amount, credit_amount, entry_date);
```
Query patterns served: entry detail line retrieval, per-account ledger/statement, cost-center/project P&L, monthly trend aggregation, trial balance computation via index-only scan (the `INCLUDE` covering index avoids a heap fetch for every summed row, which matters enormously given `journal_lines` is the single largest table in the schema at scale).

## ledger_entries

`ledger_entries` is the materialized GL projection — read-heavy, append-only, populated exclusively by the posting pipeline.

```sql
CREATE INDEX idx_ledger_entries_company_account_date
    ON ledger_entries (company_id, account_id, entry_date)
    INCLUDE (debit_amount, credit_amount, running_balance);

CREATE INDEX idx_ledger_entries_entry_date_brin
    ON ledger_entries USING BRIN (entry_date) WITH (pages_per_range = 64);

-- Back-reference to the journal_line that projected this row, for
-- drill-through from a GL line back to its source entry.
CREATE INDEX idx_ledger_entries_journal_line
    ON ledger_entries (journal_line_id);

-- Fiscal period scoping for financial statement generation.
CREATE INDEX idx_ledger_entries_company_period
    ON ledger_entries (company_id, fiscal_period_id, account_id);
```
Query patterns served: general ledger report (account + date range, index-only via `INCLUDE`), trial balance/balance sheet/income statement per fiscal period, drill-through to source journal line, wide-range analytical scans via BRIN.

## customers

```sql
CREATE UNIQUE INDEX uq_customers_company_code
    ON customers (company_id, code)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_customers_company_active
    ON customers (company_id, name_en)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_customers_name_en_trgm
    ON customers USING GIN (name_en gin_trgm_ops);

CREATE INDEX idx_customers_search_vector_gin
    ON customers USING GIN (search_vector);

-- Tax-number lookup (a customer's VAT/tax registration number is looked up
-- when matching an incoming e-invoicing document to an existing customer).
CREATE INDEX idx_customers_company_tax_number
    ON customers (company_id, tax_number)
    WHERE deleted_at IS NULL AND tax_number IS NOT NULL;

-- AR control-account reconciliation: customers grouped by their control
-- account for the sub-ledger-to-GL tie-out report.
CREATE INDEX idx_customers_control_account
    ON customers (company_id, control_account_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_customers_tags_gin
    ON customers USING GIN (tags jsonb_path_ops);
```
Query patterns served: customer directory list/search (name + trigram + full-text), AR sub-ledger tie-out, tax-number matching for e-invoicing, tag-based segmentation for AI-driven collections prioritization.

## vendors

```sql
CREATE UNIQUE INDEX uq_vendors_company_code
    ON vendors (company_id, code)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_vendors_company_active
    ON vendors (company_id, name_en)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_vendors_name_en_trgm
    ON vendors USING GIN (name_en gin_trgm_ops);

CREATE INDEX idx_vendors_company_tax_number
    ON vendors (company_id, tax_number)
    WHERE deleted_at IS NULL AND tax_number IS NOT NULL;

CREATE INDEX idx_vendors_control_account
    ON vendors (company_id, control_account_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_vendors_custom_fields_gin
    ON vendors USING GIN (custom_fields);

-- Preferred-vendor lookup per product category, for the Fraud Detection and
-- Treasury Manager AI agents' vendor-selection recommendations.
CREATE INDEX idx_vendors_company_category
    ON vendors (company_id, primary_category_id)
    WHERE deleted_at IS NULL;
```
Query patterns served: vendor directory, AP sub-ledger tie-out, purchasing recommendation queries, custom-field-driven vendor scoring.

## products

```sql
CREATE UNIQUE INDEX uq_products_company_sku
    ON products (company_id, sku)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_products_company_status
    ON products (company_id, status)
    WHERE deleted_at IS NULL AND status = 'active';

CREATE INDEX idx_products_company_category
    ON products (company_id, product_category_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_products_name_en_trgm
    ON products USING GIN (name_en gin_trgm_ops);

CREATE INDEX idx_products_sku_trgm
    ON products USING GIN (sku gin_trgm_ops);

CREATE INDEX idx_products_search_vector_gin
    ON products USING GIN (search_vector);

CREATE INDEX idx_products_tags_gin
    ON products USING GIN (tags jsonb_path_ops);

-- Barcode lookup: point-of-sale / warehouse scanning is exact-match, high
-- frequency, and latency-sensitive — a dedicated narrow index outperforms
-- relying on the trigram index for this exact-match path.
CREATE INDEX idx_product_barcodes_barcode
    ON product_barcodes (barcode)
    WHERE deleted_at IS NULL;
```
Query patterns served: catalog browse/filter by category and status, SKU/barcode exact-match at POS/warehouse scan speed, name search (typeahead + full-text), AI-driven tag segmentation (e.g. reorder recommendations by tag).

## invoices

```sql
CREATE UNIQUE INDEX uq_invoices_company_number
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_invoices_company_customer_issue_date
    ON invoices (company_id, customer_id, issue_date DESC);

CREATE INDEX idx_invoices_company_status_due_date
    ON invoices (company_id, status, due_date)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_invoices_company_open
    ON invoices (company_id, due_date)
    WHERE status IN ('sent', 'overdue', 'partially_paid') AND deleted_at IS NULL;

-- Source-quotation/order linkage: "which invoice was generated from this
-- sales order" drill-through.
CREATE INDEX idx_invoices_sales_order
    ON invoices (sales_order_id)
    WHERE sales_order_id IS NOT NULL;

-- Journal-entry linkage: "view the posting for this invoice."
CREATE INDEX idx_invoices_journal_entry
    ON invoices (journal_entry_id)
    WHERE journal_entry_id IS NOT NULL;

-- Currency-scoped reporting for multi-currency companies.
CREATE INDEX idx_invoices_company_currency
    ON invoices (company_id, currency_code)
    WHERE deleted_at IS NULL AND currency_code IS NOT NULL;
```
Query patterns served: invoice numbering uniqueness, customer statement/list view, AR aging and collections dashboards, sales-order-to-invoice drill-through, invoice-to-GL drill-through, multi-currency exposure reporting.

## invoice_items

```sql
CREATE INDEX idx_invoice_items_invoice_line_no
    ON invoice_items (invoice_id, line_number);

-- Product-level sales analysis: "how much of product X did we sell, and
-- when" — feeds the AI Forecast Agent's demand model.
CREATE INDEX idx_invoice_items_company_product_date
    ON invoice_items (company_id, product_id, created_at DESC);

-- Tax-code-scoped reporting for tax return preparation.
CREATE INDEX idx_invoice_items_tax_code
    ON invoice_items (tax_code_id)
    WHERE tax_code_id IS NOT NULL;
```
Query patterns served: invoice line rendering in document order, product-level sales history for demand forecasting, tax-code aggregation for VAT/tax return line items.

## bills

```sql
CREATE UNIQUE INDEX uq_bills_company_vendor_number
    ON bills (company_id, vendor_id, bill_number)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_bills_company_vendor_issue_date
    ON bills (company_id, vendor_id, issue_date DESC);

CREATE INDEX idx_bills_company_open
    ON bills (company_id, due_date)
    WHERE status IN ('received', 'overdue', 'partially_paid') AND deleted_at IS NULL;

CREATE INDEX idx_bills_purchase_order
    ON bills (purchase_order_id)
    WHERE purchase_order_id IS NOT NULL;

CREATE INDEX idx_bills_journal_entry
    ON bills (journal_entry_id)
    WHERE journal_entry_id IS NOT NULL;

-- Three-way-match support: goods receipt to bill matching for the
-- Auditor/Fraud Detection agents.
CREATE INDEX idx_bills_goods_receipt
    ON bills (goods_receipt_id)
    WHERE goods_receipt_id IS NOT NULL;
```
Query patterns served: AP aging/payables dashboard, vendor statement view, PO-to-bill and goods-receipt-to-bill three-way match, bill-to-GL drill-through.

## bank_accounts

```sql
CREATE UNIQUE INDEX uq_bank_accounts_company_iban
    ON bank_accounts (company_id, iban)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_bank_accounts_company_active
    ON bank_accounts (company_id)
    WHERE deleted_at IS NULL AND status = 'active';

CREATE INDEX idx_bank_accounts_gl_account
    ON bank_accounts (gl_account_id)
    WHERE deleted_at IS NULL;
```
Query patterns served: bank-account picker for payment/receipt forms, IBAN duplicate-registration prevention, bank-account-to-GL-account tie-out for the Treasury Manager agent's cash position rollup.

## bank_transactions

```sql
CREATE INDEX idx_bank_transactions_company_account_date
    ON bank_transactions (company_id, bank_account_id, transaction_date DESC);

CREATE INDEX idx_bank_transactions_unreconciled
    ON bank_transactions (company_id, bank_account_id, transaction_date)
    WHERE is_reconciled = false AND deleted_at IS NULL;

CREATE INDEX idx_bank_transactions_date_brin
    ON bank_transactions USING BRIN (transaction_date) WITH (pages_per_range = 64);

-- Reconciliation match linkage: a bank transaction matched to a specific
-- receipt/payment/transfer during reconciliation.
CREATE INDEX idx_bank_transactions_matched_source
    ON bank_transactions (matched_source_type, matched_source_id)
    WHERE matched_source_id IS NOT NULL;
```
Query patterns served: bank statement view (per account, date-descending), reconciliation working queue (unreconciled subset), wide-range treasury reporting via BRIN, matched-transaction drill-through.

## inventory_items

```sql
CREATE UNIQUE INDEX uq_inventory_items_company_product_warehouse
    ON inventory_items (company_id, product_id, warehouse_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_inventory_items_low_stock
    ON inventory_items (company_id, warehouse_id)
    WHERE quantity_on_hand <= reorder_point AND deleted_at IS NULL;

CREATE INDEX idx_inventory_items_bin_location
    ON inventory_items ((custom_fields ->> 'bin_location'))
    WHERE deleted_at IS NULL;

-- Per-product rollup across all warehouses (company-wide "how much of
-- product X do we have everywhere" query, used constantly by sales-order
-- availability checks).
CREATE INDEX idx_inventory_items_company_product
    ON inventory_items (company_id, product_id)
    INCLUDE (quantity_on_hand, quantity_reserved);
```
Query patterns served: per-location stock lookup (PK-equivalent unique key), low-stock/reorder alerting, warehouse pick-list generation by bin location, sales-order availability check via index-only scan across warehouses.

## stock_movements

```sql
CREATE INDEX idx_stock_movements_company_product_warehouse_date
    ON stock_movements (company_id, product_id, warehouse_id, movement_date DESC);

CREATE INDEX idx_stock_movements_movement_date_brin
    ON stock_movements USING BRIN (movement_date) WITH (pages_per_range = 64);

-- Source-document linkage: a stock movement generated from a sales
-- delivery, goods receipt, stock adjustment, or transfer.
CREATE INDEX idx_stock_movements_source
    ON stock_movements (company_id, source_type, source_id);

-- Batch/serial traceability for recall and expiry management.
CREATE INDEX idx_stock_movements_batch
    ON stock_movements (product_batch_id)
    WHERE product_batch_id IS NOT NULL;
```
Query patterns served: movement history per product/warehouse (stock card report), wide-range inventory valuation scans via BRIN, source-document drill-through, batch/lot traceability for recall response.

## payroll_runs

```sql
CREATE UNIQUE INDEX uq_payroll_runs_company_period
    ON payroll_runs (company_id, pay_period_start, pay_period_end, branch_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_payroll_runs_company_status
    ON payroll_runs (company_id, status)
    WHERE deleted_at IS NULL AND status IN ('draft', 'calculated', 'pending_approval');

CREATE INDEX idx_payroll_runs_journal_entry
    ON payroll_runs (journal_entry_id)
    WHERE journal_entry_id IS NOT NULL;
```
Query patterns served: duplicate-run prevention for a given period, payroll-officer working queue (runs awaiting approval/release — a sensitive operation requiring human approval per DESIGN_CONTEXT §6), payroll-to-GL drill-through.

## tax_transactions

```sql
CREATE UNIQUE INDEX uq_tax_transactions_source_code
    ON tax_transactions (company_id, source_type, source_id, tax_code_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_tax_transactions_pending
    ON tax_transactions (company_id, tax_period_id)
    WHERE status = 'pending' AND deleted_at IS NULL;

CREATE INDEX idx_tax_transactions_company_period_code
    ON tax_transactions (company_id, tax_period_id, tax_code_id)
    INCLUDE (taxable_amount, tax_amount);
```
Query patterns served: double-tax prevention on the same source line, tax-return preparation working queue, tax-return line aggregation via index-only scan (`INCLUDE` covers the two amount columns the return needs to sum).

## audit_logs

```sql
CREATE INDEX idx_audit_logs_company_auditable
    ON audit_logs (company_id, auditable_type, auditable_id, created_at DESC);

CREATE INDEX idx_audit_logs_created_at_brin
    ON audit_logs USING BRIN (created_at) WITH (pages_per_range = 32);

-- "What did this user do" — support/security investigation query.
CREATE INDEX idx_audit_logs_company_user_date
    ON audit_logs (company_id, user_id, created_at DESC);

-- Action-type filtering (e.g. "show all permission changes," a Fraud
-- Detection/Compliance Agent query).
CREATE INDEX idx_audit_logs_company_action
    ON audit_logs (company_id, action, created_at DESC);
```
Query patterns served: per-record history drawer, per-user activity investigation, per-action-type compliance queries, wide-range retention/export scans via BRIN. `audit_logs` is range-partitioned by `created_at` (see Maintenance section); every index above is created per-partition automatically via `CREATE INDEX` on the parent partitioned table, which PostgreSQL propagates to all existing and future partitions.

## notifications

```sql
CREATE INDEX idx_notifications_user_company_created
    ON notifications (user_id, company_id, created_at DESC);

CREATE INDEX idx_notifications_unread
    ON notifications (user_id, company_id)
    WHERE read_at IS NULL;

CREATE INDEX idx_notifications_created_at_brin
    ON notifications USING BRIN (created_at) WITH (pages_per_range = 32);

-- Notification-type filtering for per-category notification preferences
-- (e.g. "show only invoice.paid notifications").
CREATE INDEX idx_notifications_user_type
    ON notifications (user_id, notification_type)
    WHERE read_at IS NULL;
```
Query patterns served: notification bell/inbox list (newest-first), unread-count badge (must be sub-millisecond, hence the narrow partial index), category-filtered notification preferences, retention-purge wide scans via BRIN.

# Optimization

**Covering indexes via `INCLUDE`.** Every aggregation-heavy query path in the Per-Table plan above (`journal_lines` trial balance, `ledger_entries` GL report, `inventory_items` availability rollup, `tax_transactions` return line aggregation) uses `INCLUDE` to add the columns the query selects/sums beyond the key columns it filters/joins on, specifically so PostgreSQL can satisfy the query with an **index-only scan** — reading only the index, never the heap — provided the visibility map confirms the relevant pages are all-visible (see the Maintenance section's `VACUUM` discussion, since index-only scans degrade back into regular index scans on pages the visibility map has not yet marked all-visible). Confirm index-only scan usage explicitly:

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT account_id, SUM(debit_amount) AS total_debit, SUM(credit_amount) AS total_credit
FROM journal_lines
WHERE company_id = 42 AND entry_date BETWEEN '2026-01-01' AND '2026-03-31'
GROUP BY account_id;
```

A healthy plan shows `Index Only Scan using idx_journal_lines_trial_balance_covering` with a `Heap Fetches: 0` (or near-zero) line — a nonzero, growing `Heap Fetches` count over repeated runs on the same data is the signal that autovacuum is not keeping the visibility map current enough for this table's write rate, which is addressed by the per-table autovacuum tuning in Maintenance.

**`work_mem` for sort- and hash-heavy report queries.** The default `work_mem` (typically 4MB) is too small for the sort/aggregate operations behind financial statements and reconciliation matching on large companies; QAYD sets a session-level override inside the Laravel query builder for the specific report-generation service calls (not globally, to avoid over-allocating memory across the connection pool under concurrent load):

```sql
SET LOCAL work_mem = '64MB';
-- ... run the trial balance / aging / reconciliation query ...
```

This is wrapped in the `ReportService` base class as `DB::statement("SET LOCAL work_mem = '64MB'")` issued inside the same transaction as the report query, so the elevated limit is scoped to that transaction only and reverts automatically at commit/rollback.

**Statistics targets for skewed columns.** `status` columns and other low-cardinality, skewed-distribution columns benefit from a higher `STATISTICS` target than the default (100) so the planner's row-count estimates for partial-index-eligible predicates (`status = 'posted'` vs `status = 'draft'`) are accurate even as the skew changes over a company's lifecycle (a new company has mostly drafts; a mature company has mostly posted/closed rows):

```sql
ALTER TABLE journal_entries ALTER COLUMN status SET STATISTICS 500;
ALTER TABLE invoices ALTER COLUMN status SET STATISTICS 500;
ALTER TABLE bills ALTER COLUMN status SET STATISTICS 500;
ANALYZE journal_entries;
ANALYZE invoices;
ANALYZE bills;
```

**Fillfactor for frequently-updated-in-place rows.** `inventory_items.quantity_on_hand`/`quantity_reserved` and `bank_accounts.current_balance` are updated far more often than most columns on their respective tables, and those updates do not touch any indexed key column (the indexes on these tables key on `product_id`/`warehouse_id`/`company_id`, not on the balance columns themselves) — this is exactly the profile HOT updates optimize for, provided the page has free space to write the new tuple version in place. Lowering `fillfactor` reserves that space at table-build/rewrite time:

```sql
ALTER TABLE inventory_items SET (fillfactor = 85);
ALTER TABLE bank_accounts SET (fillfactor = 90);
VACUUM FULL inventory_items;  -- or, non-blocking, wait for natural page turnover
```

`VACUUM FULL` is disruptive (takes an `ACCESS EXCLUSIVE` lock) and is only run once, at initial rollout of the `fillfactor` change, during a maintenance window — subsequent benefit accrues naturally as pages are rewritten through normal `UPDATE` traffic; it is not re-run routinely (see Maintenance for the routine, non-blocking alternative).

**Partial-index predicate alignment with ORM-generated SQL.** Because Eloquent appends `WHERE deleted_at IS NULL` automatically for any model using the `SoftDeletes` trait, and QAYD's status-scoped local scopes (e.g. `Invoice::open()`) are implemented as explicit `whereIn('status', [...])` calls matching the exact list embedded in each partial index's `WHERE` clause, a lint-level convention is enforced in code review: **any change to a model's soft-delete or status-scope query must be checked against this document's partial index definitions**, and a mismatch is treated as a bug in either the code or the index (whichever is stale), never tolerated as "the planner will just seq-scan instead" — an unindexed hot path in production is a P1, not a shrug.

**Query plan verification checklist (run before merging any new query or index):**
1. `EXPLAIN (ANALYZE, BUFFERS)` on representative production-scale data (not an empty dev table — planner choices flip entirely at scale).
2. Confirm the intended index appears in the plan, not a sequential scan or a different, less-selective index.
3. Confirm `Buffers: shared hit=` dominates over `read=` on repeated runs (cache-warm behavior is what production steady-state looks like).
4. For aggregation queries, confirm `Heap Fetches: 0` on an index-only scan, or an explicit justification if not.
5. Compare estimated vs. actual row counts in the plan — a large mismatch (order of magnitude or more) signals stale statistics; run `ANALYZE` and re-check before concluding an index is the wrong choice.

# Monitoring

**Unused index detection.** Every index has a write cost; an index with zero or near-zero scans over a representative observation window (a full peak-season billing cycle, minimum 30 days) is a maintenance liability with no offsetting benefit and is a drop candidate:

```sql
SELECT
    schemaname,
    relname AS table_name,
    indexrelname AS index_name,
    idx_scan,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes
WHERE idx_scan = 0
  AND indexrelname NOT LIKE '%_pkey'   -- never drop PK indexes regardless of scan count
ORDER BY pg_relation_size(indexrelid) DESC;
```

This query is run monthly as part of the standing DB-health routine (see the `zaman-ops-sop`-style operational cadence QAYD's own ops runbook follows) and any index it surfaces is investigated — either the query pattern it was meant to serve was never shipped, was retired, or (more concerning) the ORM is generating SQL that doesn't match the index's leading columns/predicate, in which case the fix is in the query layer, not a hasty index drop.

**Index bloat detection.** B-tree indexes bloat under heavy update/delete churn (every `UPDATE` to a soft-delete or status column leaves a dead index entry until vacuumed). The estimated-bloat query against `pgstattuple` (extension) or the well-known heuristic query against `pg_stats`/`pg_class` is run weekly on the high-churn tables (`journal_lines`, `stock_movements`, `bank_transactions`, `notifications`):

```sql
CREATE EXTENSION IF NOT EXISTS pgstattuple;

SELECT
    indexrelname,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size,
    (pgstatindex(indexrelid::regclass)).avg_leaf_density AS leaf_density_pct,
    (pgstatindex(indexrelid::regclass)).leaf_fragmentation AS fragmentation_pct
FROM pg_stat_user_indexes
WHERE relname IN ('journal_lines', 'stock_movements', 'bank_transactions', 'notifications')
ORDER BY pg_relation_size(indexrelid) DESC;
```

`avg_leaf_density` below ~70% (i.e. more than ~30% of leaf-page space is dead/free) on a large, high-churn index is the trigger for a `REINDEX CONCURRENTLY` (see Maintenance).

**Slow-query surfacing via `pg_stat_statements`.** Enabled at the cluster level (`shared_preload_libraries = 'pg_stat_statements'`), this extension is the primary signal for "an index is missing or is not being chosen":

```sql
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

SELECT
    query,
    calls,
    round(mean_exec_time::numeric, 2) AS mean_ms,
    round(total_exec_time::numeric, 2) AS total_ms,
    round((100 * total_exec_time / sum(total_exec_time) OVER())::numeric, 2) AS pct_of_total
FROM pg_stat_statements
WHERE query ILIKE '%journal_lines%' OR query ILIKE '%invoices%'
ORDER BY total_exec_time DESC
LIMIT 20;
```

Any query surfacing here with `mean_ms` above 100ms on a table covered by this document's index plan is escalated for `EXPLAIN` analysis the same day, not batched into a future sprint — sustained slow queries on `journal_lines`/`ledger_entries` directly degrade the accountant/auditor UX and the AI agents' ability to answer in-session.

**Table and index size growth tracking.** A weekly snapshot into a small `db_metrics` monitoring table (outside the OLTP schema, or exported to an external metrics store) tracks growth trajectory per table, so index-vs-heap size ratios and absolute growth rate inform proactive partitioning/archival decisions before a table becomes a performance problem rather than after:

```sql
SELECT
    relname AS table_name,
    pg_size_pretty(pg_total_relation_size(relid)) AS total_size,
    pg_size_pretty(pg_relation_size(relid)) AS table_size,
    pg_size_pretty(pg_indexes_size(relid)) AS indexes_size,
    n_live_tup,
    n_dead_tup,
    round(n_dead_tup::numeric / GREATEST(n_live_tup, 1) * 100, 2) AS dead_tup_pct
FROM pg_stat_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 25;
```

`dead_tup_pct` climbing above 10-15% between autovacuum runs on a high-write table is the leading indicator that the table's autovacuum thresholds need tightening before bloat becomes visible in query latency.

**Replication/Hyperdrive-aware read routing monitoring.** Because QAYD's AI layer and reporting workloads are steered toward read replicas / Cloudflare Hyperdrive-pooled connections where available, `pg_stat_replication` lag (on the primary) and replica-side `pg_stat_user_indexes` are both monitored — an index that performs well on the primary can still cause read-replica lag if it triggers expensive WAL-replay-side index maintenance; this is tracked as a secondary signal, not a primary indexing decision input, but is called out here because it is a real operational failure mode observed in high-write multi-tenant SaaS at QAYD's target scale.

# Maintenance

**Autovacuum tuning per table tier.** The cluster-wide autovacuum defaults are adequate for low-write master-data tables (`accounts`, `customers`, `vendors`, `products`) but are insufficient for the high-write ledger/log tables, which are given tighter, more aggressive per-table overrides so dead tuples are reclaimed fast enough to keep the visibility map current (protecting index-only scan performance) and to prevent transaction-ID wraparound risk at scale:

```sql
ALTER TABLE journal_lines SET (
    autovacuum_vacuum_scale_factor = 0.02,
    autovacuum_vacuum_threshold = 1000,
    autovacuum_analyze_scale_factor = 0.02,
    autovacuum_analyze_threshold = 1000
);

ALTER TABLE stock_movements SET (
    autovacuum_vacuum_scale_factor = 0.02,
    autovacuum_vacuum_threshold = 1000
);

ALTER TABLE bank_transactions SET (
    autovacuum_vacuum_scale_factor = 0.05,
    autovacuum_vacuum_threshold = 500
);

ALTER TABLE notifications SET (
    autovacuum_vacuum_scale_factor = 0.05,
    autovacuum_vacuum_threshold = 500,
    autovacuum_vacuum_cost_delay = 2
);

ALTER TABLE audit_logs SET (
    autovacuum_vacuum_scale_factor = 0.1,
    autovacuum_vacuum_threshold = 5000
);
```

The default `autovacuum_vacuum_scale_factor` of 0.2 means autovacuum waits until 20% of a table's rows are dead before triggering — on a 100-million-row `journal_lines` table that is 20 million dead tuples before cleanup starts, which is unacceptable; lowering the scale factor and adding an absolute row-count threshold (`autovacuum_vacuum_threshold`) triggers cleanup far earlier and keeps each individual vacuum run smaller and less disruptive.

**`REINDEX CONCURRENTLY` for bloat remediation.** Once `pgstattuple` monitoring flags leaf density degradation (per the Monitoring section), the remediation is always `REINDEX CONCURRENTLY`, never a plain blocking `REINDEX`, because plain `REINDEX` takes an exclusive lock that stalls all writes (and, on a PK/unique index, all reads too) to the target table — unacceptable on any production table in this schema, including during off-peak hours, because the AI agents and scheduled jobs (payroll runs, tax filing deadlines) do not observe a clean "off-peak" window globally across time zones:

```sql
REINDEX INDEX CONCURRENTLY idx_journal_lines_company_account_date;

-- For a full table's indexes when bloat is widespread:
REINDEX TABLE CONCURRENTLY journal_lines;
```

`REINDEX CONCURRENTLY` requires PostgreSQL 12+ (satisfied — QAYD runs 15+), builds the new index in parallel with the old one remaining active for reads, and briefly takes a `SHARE UPDATE EXCLUSIVE` lock only at the final swap — this is the only acceptable maintenance path on any table with continuous write traffic.

**Partition management for `audit_logs` and `ledger_entries`.** Both tables are range-partitioned by their time column, with monthly partitions created ahead of need by a scheduled Laravel command (`php artisan db:partition-maintain`) run weekly:

```sql
CREATE TABLE audit_logs_2026_08 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');

CREATE TABLE ledger_entries_2026_08 PARTITION OF ledger_entries
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
```

Every index declared on the parent partitioned table (composite B-trees, BRIN) automatically applies to each partition individually — PostgreSQL creates matching per-partition indexes and, since PG 11+, supports a genuinely global-looking index definition even though physically each partition has its own index structure. Old partitions of `audit_logs` beyond the compliance-mandated retention window (36 months, configurable per company's jurisdiction) are detached and archived to Cloudflare R2 as Parquet exports rather than deleted in place, via `ALTER TABLE audit_logs DETACH PARTITION audit_logs_2023_01 CONCURRENTLY;` followed by an export job and then `DROP TABLE`, which is dramatically cheaper than a `DELETE` of the same row volume would be (no per-row WAL, no vacuum-after-delete bloat) and avoids ever running a giant `DELETE FROM audit_logs WHERE created_at < ...` against a live table.

**Routine `ANALYZE` cadence.** Autovacuum's automatic `ANALYZE` triggering (tied to the same scale-factor settings above) is supplemented by an explicit nightly `ANALYZE` pass across all indexed tables during the lowest-traffic window per company's timezone cohort (QAYD batches this per-region: Gulf-timezone tenants get their `ANALYZE` window at 03:00 AST, other regions at their own local off-hours), ensuring planner statistics never drift stale across a full business day of month-end-close write bursts:

```sql
ANALYZE accounts;
ANALYZE journal_entries;
ANALYZE journal_lines;
ANALYZE ledger_entries;
ANALYZE invoices;
ANALYZE bills;
-- ... remainder of the canonical table list ...
```

**Index-build strategy for new indexes on existing large tables.** Every `CREATE INDEX` against a table already holding production data uses `CONCURRENTLY`, accepting the trade-off that concurrent builds take longer and cannot run inside the same transaction as other migration steps (Laravel migrations that add a concurrent index are split into their own migration file, outside any wrapping transaction, per Laravel's documented pattern for `->concurrently()` on `Schema::create`/`Schema::table` index calls):

```php
// Laravel migration — index build isolated from transactional DDL.
public function up(): void
{
    Schema::table('journal_lines', function (Blueprint $table) {
        $table->index(['company_id', 'account_id', 'entry_date'], null, 'concurrently');
    });
}
```

If a `CONCURRENTLY` build fails partway (rare, but possible under lock contention or an out-of-memory condition), it leaves behind an `INVALID` index that must be dropped and rebuilt — this is checked as a standard post-migration verification step (`SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid;`), never left in place silently.

# Anti-Patterns

**Indexing every column "just in case."** Each additional index on a write-heavy table (`journal_lines`, `stock_movements`) measurably slows every `INSERT`. QAYD's index budget discipline (Indexing Principles §5) exists specifically to prevent this; a new index proposal without a named query pattern and a demonstrated `EXPLAIN` improvement is rejected in review.

**Indexing low-cardinality boolean/enum columns as standalone B-trees.** `CREATE INDEX ON invoices (is_paid)` where `is_paid` is a boolean with a roughly 60/40 split is close to useless — the planner will ignore it in favor of a sequential scan for either value, because neither value is selective enough to beat scanning the heap directly. The correct pattern, used throughout this document, is either a partial index scoped to the *interesting, minority* value (`WHERE is_paid = false`) or folding the column into a composite index as a trailing/secondary equality predicate alongside a genuinely selective leading column.

**Volatile functions inside expression indexes.** As demonstrated (and then corrected) in the Expression section's `days_overdue` example, an expression index built on a function that is not `IMMUTABLE` (e.g. anything referencing `now()`, `CURRENT_DATE`, `CURRENT_TIMESTAMP`, or a non-deterministic random/sequence value) either fails outright at `CREATE INDEX` time (PostgreSQL rejects non-immutable expressions in index definitions for exactly this reason) or, if wrapped to appear immutable while secretly depending on wall-clock time, silently returns stale/wrong results for any row not re-written since the "current" value changed. QAYD's rule: **only `IMMUTABLE`-eligible expressions (pure functions of the row's own column values, with no reference to the current date/time/session/random state) are indexed**; anything time-relative is computed at query time against a stable stored column instead.

**Redundant/overlapping indexes.** A composite index `(company_id, status, due_date)` makes a separate standalone index `(company_id, status)` redundant for equality-only lookups on those two columns (the composite serves as a valid prefix), yet redundant indexes accumulate over a project's lifetime as different engineers add "the index I need" without checking `pg_indexes` first. The monthly unused-index audit (Monitoring section) also flags near-duplicate index definitions (via `pg_get_indexdef` string comparison against a leading-column overlap heuristic) so these are consolidated rather than left to double the write cost for no read benefit.

**Over-wide composite index keys.** Cramming five or six columns into one B-tree key (rather than promoting the tail columns to an `INCLUDE` clause) bloats every internal B-tree page, reduces the fan-out per page, and deepens the tree — directly increasing lookup cost. Any column in a composite index that is not part of an equality/range predicate the index needs to support, and is only ever *returned*, belongs in `INCLUDE`, not in the key list.

**Ignoring index-column order under the assumption "the planner will figure it out."** PostgreSQL's B-tree can use a range condition efficiently only as the first non-equality column encountered while walking the index's column list left to right; a composite index built as `(entry_date, company_id, account_id)` — date first — is nearly useless for QAYD's actual query shape (always `company_id = ?` plus a date range), because the planner cannot use the tenant-scoping equality predicate to narrow the B-tree traversal before it hits the date range; it degrades into scanning across all tenants' rows within the date range and filtering by `company_id` afterward. Column order is treated as a first-class design decision, verified against the query's actual predicate structure, never assumed.

**Trusting `EXPLAIN` without `ANALYZE` and `BUFFERS`.** `EXPLAIN` alone shows the planner's *estimated* cost and plan shape based on statistics, not what actually happened; a plan that *looks* like it should use an index can still fall back to a sequential scan at execution time if statistics are stale or a parameter value at runtime is far more common than the planner estimated. Every performance investigation in this document's Optimization checklist mandates `EXPLAIN (ANALYZE, BUFFERS)` specifically to close this gap.

**Building indexes without `CONCURRENTLY` on live tables.** Already covered in Maintenance, repeated here as an explicit anti-pattern because it is the single most common operational incident this document exists to prevent: a plain `CREATE INDEX` on `journal_lines` at production scale takes an `ACCESS EXCLUSIVE`-adjacent lock profile (specifically, it blocks writes for the build's duration, which at hundreds of millions of rows can be tens of minutes) — during which every posting, invoice, and AI-agent write against that table queues or times out.

# Edge Cases

**Backdated transactions and BRIN summary drift.** BRIN indexes assume physical insertion order correlates with the indexed column's logical order. QAYD's accounting rules permit backdated journal entries (a correction posted today for an event last month, common in month-end close cleanup) and backdated stock movements (a warehouse count correction). A backdated row physically lands at the *end* of the table (wherever the next free page is) while logically belonging to an *earlier* block range — this does not corrupt anything (BRIN correctly reports "possibly matches, check the row" for any block range whose summary interval brackets the value loosely enough), but it does gradually widen each block range's min/max summary interval over time as more out-of-order rows accumulate, reducing BRIN's pruning effectiveness. Mitigation: a quarterly `SELECT brin_summarize_range(...)` re-summarization pass is not sufficient by itself; instead, if the backdated-row fraction for `stock_movements` or `ledger_entries` exceeds roughly 5% of a quarter's inserts (tracked via a simple `movement_date < created_at::date - interval '7 days'` count), the remediation is a one-time `REINDEX` of the BRIN index (cheap, since BRIN indexes are tiny) rather than trying to prevent backdating at the application layer, which would conflict with legitimate accounting correction workflows.

**Partial index predicate drift when a `status` enum gains a new value.** Adding a new status value (e.g. introducing `'pending_bank_confirmation'` between `'sent'` and `'paid'` on invoices) silently makes every partial index whose `WHERE` clause lists explicit status values (`WHERE status IN ('sent', 'overdue', 'partially_paid')`) miss the new value unless the index definition is explicitly updated in the same migration that adds the enum value. This is treated as a mandatory joint change: the migration that alters a `status` check constraint or enum type is required (via code review checklist, not a database mechanism) to grep this document's Per-Table Index Plan for every partial index referencing that column and update both in the same PR.

**Composite unique index vs. race conditions on concurrent inserts.** A unique partial index (e.g. `uq_invoices_company_number`) correctly rejects a genuine duplicate at commit time even under concurrent transactions — but it does so via a constraint violation *after* both transactions have done their work, which the application must catch and retry (typically by re-fetching the next invoice number from a per-company sequence and re-attempting), not treat as an unrecoverable error. QAYD's invoice/journal-entry numbering uses a dedicated per-company-per-fiscal-year sequence table with `SELECT ... FOR UPDATE` row-locking specifically to avoid relying on unique-index-violation-and-retry as the primary numbering-gap-prevention mechanism; the unique index remains as the last-line defense against a bug in that sequence logic, not as the intended concurrency-control primitive.

**Gapless numbering requirements conflicting with soft-delete-scoped uniqueness.** Some jurisdictions require invoice numbers to be gapless (no skipped numbers, even for voided/deleted invoices) for tax compliance, which is in tension with this document's default `WHERE deleted_at IS NULL` partial-uniqueness pattern (which would otherwise allow a new invoice to reuse a soft-deleted invoice's number). QAYD resolves this per-company via a `numbering_policy` setting: companies under gapless-numbering jurisdictions get a **non-partial** unique index (`uq_invoices_company_number_gapless ON invoices (company_id, invoice_number)` with no `WHERE` clause) so a number can never be reused even after soft-delete, while companies without that requirement use the partial version. Both index definitions are documented here because the migration that provisions a new company's schema-level constraints branches on this setting at company-creation time — this is an explicit, intentional schema difference across tenants within the same physical database, not schema drift to be "fixed."

**Index-only scans silently degrading after a bulk `UPDATE`.** A large batch operation (e.g. an AI-driven bulk re-categorization of thousands of `journal_lines` cost-center assignments) creates a burst of dead tuples and un-vacuumed pages, temporarily disabling index-only scan eligibility for the affected pages (visibility map bits cleared) until the next autovacuum pass completes. For any bulk operation exceeding roughly 1% of a hot table's row count, the operational runbook triggers an explicit `VACUUM (ANALYZE)` immediately following the batch, rather than waiting for the next scheduled autovacuum cycle, to restore index-only scan performance before the next reporting cycle needs it.

**GIN index size and update cost on high-churn JSONB columns.** `custom_fields` on `inventory_items` is both GIN-indexed (generic containment) and expression-indexed (`bin_location` specifically) — if a company's AI-driven workflow starts writing to `custom_fields` at high frequency (e.g. an integration syncing external warehouse-management-system fields every few minutes), the GIN index's per-update cost (GIN updates are more expensive than B-tree updates because a single JSONB document change can touch many inverted-index postings lists) can become the dominant write cost on that table. The mitigation path, documented for the engineer who encounters this: move the high-churn field out of `custom_fields` into a proper first-class typed column with its own targeted (cheap) B-tree index, reserving `custom_fields`/GIN for genuinely low-frequency, schema-flexible attributes — JSONB-with-GIN is a flexibility mechanism for the long tail of rarely-changed attributes, not a general-purpose high-throughput column store.

**Foreign-key cascade checks on unindexed child tables during bulk parent deletes.** Even though QAYD never hard-deletes financial data, some foundation tables (`attachments`, `notifications`) do support hard deletion (retention-policy purges). A `DELETE FROM companies WHERE id = ?` in a test/staging environment (never production, per the immutability rule, but this does occur in QA data resets) triggers a cascade check against every child table referencing `company_id` — if any such child table lacks an index on `company_id` as a leading column, the cascade check degenerates into a sequential scan per child table per delete, which can make an otherwise-instant QA data-reset operation take minutes. Because this document's tenancy principle (Indexing Principles §2) already mandates `company_id`-leading indexes almost everywhere, this edge case is largely self-resolving in QAYD's schema — it is called out explicitly so that any *new* table added later that skips the standard `company_id`-first index (e.g. a narrow lookup table someone indexes only on a secondary key) is caught in review before it becomes the one slow cascade path in an otherwise-fast schema.

# End of Document
