# Database Engineering Standards — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_STANDARDS
---

# Purpose

This document is the binding engineering standard for every schema, migration, query, and
transaction written against the QAYD PostgreSQL database. QAYD is an AI Financial Operating
System: the database holds the general ledger, sub-ledgers (AR/AP), inventory, payroll, tax, and
reporting data for every tenant company on the platform. A defect in this layer is not cosmetic —
an unbalanced journal entry, a lost update on a bank reconciliation, or a race condition in stock
valuation is a financial-integrity incident, potentially a regulatory one. This standard exists so
that every engineer, and every AI agent proposing schema or query changes, produces database code
that is correct under concurrency, safe to deploy without downtime, defensible in an audit, and
fast enough to serve interactive dashboards at tenant scale.

The audience is any backend engineer (human or AI) working in Laravel 12 / PHP 8.4+ against
PostgreSQL 15+, and any reviewer approving a pull request that touches a `.sql` file, a Laravel
migration, a model, or a repository query. This document assumes familiarity with the platform
facts fixed in the shared design context: multi-tenancy via `company_id`, soft deletes on all
financial rows, `NUMERIC` money columns, double-entry accounting semantics, and the canonical table
set (`accounts`, `journal_entries`, `journal_lines`, `ledger_entries`, `customers`, `vendors`,
`invoices`, `bills`, `bank_accounts`, `inventory_items`, `stock_movements`, `payroll_runs`,
`tax_transactions`, `audit_logs`, and related tables).

The standard is organized as fifteen sections, each ending in a "Standard" or "Rule" statement
that is directly enforceable in code review. Where a rule has an exception, the exception is
named explicitly — silent deviation is not permitted. Every SQL example in this document is
runnable PostgreSQL 15 syntax; every Laravel example targets Laravel 12 / PHP 8.4 conventions
(typed properties, enums, `Illuminate\Support\Facades\DB`, Eloquent model events). Nothing here is
aspirational: if a rule cannot be enforced today, the section says so and names the tracking
mechanism (a CI check, a linter rule, or a manual review-checklist item) that closes the gap.

# Normalization

QAYD's transactional schema (accounting, sales, purchasing, banking, inventory sub-ledgers) is
normalized to Third Normal Form (3NF) at minimum, and to Boyce-Codd Normal Form (BCNF) wherever a
table has more than one candidate key. Normalization is not applied for its own sake — it is
applied because QAYD's core guarantee (SUM(debits) = SUM(credits), to the cent, forever) can only
be defended if primary financial facts are stored exactly once and derived facts are computed, not
duplicated, from that single source. Denormalization is used deliberately and narrowly; see the
"Denormalization" section for where and why the exceptions exist.

**1NF — atomic values, no repeating groups.** Every column holds a single, atomic value for its
declared type; there are no delimited lists in a `VARCHAR`, and no numbered column families
(`phone_1`, `phone_2`, `phone_3`). Where a business entity can have a variable number of related
facts, that is a child table with a foreign key back to the parent, not repeated columns. Example:
customer phone numbers and emails are not columns on `customers` — they live in
`customer_contacts`:

```sql
CREATE TABLE customer_contacts (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    customer_id   BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    contact_type  TEXT NOT NULL CHECK (contact_type IN ('phone','email','fax','whatsapp')),
    value         TEXT NOT NULL,
    label         TEXT,               -- 'billing', 'primary', 'accounts_payable', ...
    is_primary    BOOLEAN NOT NULL DEFAULT FALSE,
    created_by    BIGINT REFERENCES users(id),
    updated_by    BIGINT REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ
);
CREATE UNIQUE INDEX customer_contacts_one_primary_per_type
    ON customer_contacts (customer_id, contact_type)
    WHERE is_primary AND deleted_at IS NULL;
```

The one sanctioned exception to "no lists in a column" is JSONB for genuinely schemaless,
non-relational, non-queried-by-value data: `custom_fields JSONB` and `tags JSONB` on master-data
tables. These are metadata, not facts the ledger depends on, and they are never joined against in
a financial calculation. If a field inside `custom_fields` becomes something the business logic
filters, sorts, or aggregates on, it must be promoted to a real column and backfilled — JSONB is a
staging ground for attributes that have not yet earned a column, not a way to avoid normalizing.

**2NF — no partial dependency on a composite key.** Tables with a composite natural key store only
attributes that depend on the whole key, not a subset of it. `invoice_items` illustrates this:

```sql
CREATE TABLE invoice_items (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    invoice_id    BIGINT NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
    line_no       SMALLINT NOT NULL,
    product_id    BIGINT REFERENCES products(id),
    description   TEXT NOT NULL,
    quantity      NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    unit_price    NUMERIC(19,4) NOT NULL CHECK (unit_price >= 0),
    discount_pct  NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (discount_pct BETWEEN 0 AND 100),
    tax_rate_id   BIGINT REFERENCES tax_rates(id),
    line_total    NUMERIC(19,4) NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (invoice_id, line_no)
);
```

`description` and `unit_price` depend only on `(invoice_id, line_no)` together — they are not
duplicated attributes of `product_id` alone, because a line item is allowed to override a
product's catalog description or price at the time of sale (a partial dependency on `product_id`
would mean "every line referencing product 42 must have the same description," which is false;
prices and descriptions are pinned to the invoice line at the moment of billing, which is the
correct historical record even if the product catalog changes later).

**3NF — no transitive dependency on a non-key attribute.** A customer's outstanding balance is not
a column on `customers`; it is transitively dependent on `invoices.amount_due` through
`invoices.customer_id`, and is computed:

```sql
-- WRONG (3NF violation): customers.balance updated by triggers/app code on every invoice/payment
-- ALTER TABLE customers ADD COLUMN balance NUMERIC(19,4);

-- RIGHT: derive it
SELECT c.id, c.name_en,
       COALESCE(SUM(i.amount_due), 0) AS outstanding_balance
FROM customers c
LEFT JOIN invoices i
       ON i.customer_id = c.id
      AND i.status IN ('posted','partially_paid','overdue')
      AND i.deleted_at IS NULL
WHERE c.company_id = :company_id
GROUP BY c.id, c.name_en;
```

A city name is not stored redundantly against a `city_id` foreign key when a full country/city
reference table exists — `customer_addresses` stores `city_id BIGINT REFERENCES cities(id)`, not a
free-text `city TEXT` next to it. Where free text is intentionally allowed (a written address line
a data-entry clerk typed in Arabic that does not map cleanly to a lookup table), it is documented
as such and never used as a join key.

**BCNF — every determinant is a candidate key.** `accounts` (Chart of Accounts) is the clearest
BCNF case in the schema: `(company_id, code)` is unique, `id` is the surrogate primary key, and no
non-key column determines another non-key column. The temptation to violate BCNF appears most
often in `journal_lines`: it might seem convenient to store `account_code` and `account_name_en`
directly on the line for "fast reporting," but `account_code` determines `account_name_en`
(a violation — a non-key attribute determines another non-key attribute), so both are left off
`journal_lines` entirely and joined from `accounts` at query time. The single deliberate exception
to this, `ledger_entries`, is documented in the Denormalization section below with the write path
that keeps it correct.

**Functional dependency discipline for the Chart of Accounts.**

```sql
CREATE TABLE account_types (
    id          SMALLINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        TEXT NOT NULL UNIQUE,          -- 'asset','liability','equity','revenue','expense'
    normal_balance TEXT NOT NULL CHECK (normal_balance IN ('debit','credit')),
    name_en     TEXT NOT NULL,
    name_ar     TEXT NOT NULL
);

CREATE TABLE accounts (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT REFERENCES branches(id),
    code            TEXT NOT NULL,
    name_en         TEXT NOT NULL,
    name_ar         TEXT NOT NULL,
    account_type_id SMALLINT NOT NULL REFERENCES account_types(id),
    parent_id       BIGINT REFERENCES accounts(id),
    currency_code   CHAR(3) NOT NULL DEFAULT 'KWD',
    is_control_account BOOLEAN NOT NULL DEFAULT FALSE,  -- e.g. AR/AP control accounts
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','archived')),
    tags            JSONB NOT NULL DEFAULT '{}',
    custom_fields   JSONB NOT NULL DEFAULT '{}',
    created_by      BIGINT REFERENCES users(id),
    updated_by      BIGINT REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ,
    UNIQUE (company_id, code)
);
CREATE INDEX accounts_company_idx ON accounts (company_id) WHERE deleted_at IS NULL;
CREATE INDEX accounts_parent_idx ON accounts (parent_id) WHERE deleted_at IS NULL;
```

`account_type_id` determines `normal_balance` (asset is always debit-normal), so `normal_balance`
lives once on `account_types`, never repeated per account, and never re-derived by application
code guessing from the account name — the join is authoritative.

**Standard.** New transactional tables must reach 3NF by design; any column whose value is fully
derivable from other columns already reachable by a join is not allowed in the initial migration
unless it is justified and documented under "Denormalization" below, with the specific query
latency or write-amplification problem it solves. Reviewers reject a migration that adds a
redundant lookup column ("for convenience") without that justification.

# Denormalization

Normalization is the default; denormalization is an explicit, reviewed, and documented exception
made for one of exactly three reasons: (1) a read path is on the interactive critical path (a
dashboard, an invoice PDF, a real-time balance check) and the fully normalized join is too
expensive to run per-request at tenant scale; (2) a table is a historical record that must survive
changes to the "current" data it was derived from (a line item's price must not change retroactively
because the product's catalog price changed); or (3) a reporting/analytics workload needs a shape
that would require joining ten tables per row, and that workload is read-only and eventually
consistent by nature (trial balance, aging reports, inventory valuation snapshots).

**`ledger_entries` — the General Ledger projection.** This is QAYD's canonical denormalization.
`journal_lines` is the source of truth; `ledger_entries` is a materialized, query-optimized
projection of every *posted* line, flattened with the account, dimension, and running-balance
context a report needs, so that a Trial Balance or an account statement never has to join
`journal_entries` ⋈ `journal_lines` ⋈ `accounts` ⋈ `fiscal_periods` on the hot path.

```sql
CREATE TABLE ledger_entries (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT REFERENCES branches(id),
    journal_entry_id  BIGINT NOT NULL REFERENCES journal_entries(id),
    journal_line_id   BIGINT NOT NULL REFERENCES journal_lines(id),
    account_id        BIGINT NOT NULL REFERENCES accounts(id),
    fiscal_year_id    BIGINT NOT NULL REFERENCES fiscal_years(id),
    fiscal_period_id  BIGINT NOT NULL REFERENCES fiscal_periods(id),
    entry_date        DATE NOT NULL,
    cost_center_id    BIGINT REFERENCES cost_centers(id),
    project_id        BIGINT REFERENCES projects(id),
    department_id     BIGINT REFERENCES departments(id),
    debit             NUMERIC(19,4) NOT NULL DEFAULT 0 CHECK (debit >= 0),
    credit            NUMERIC(19,4) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    currency_code     CHAR(3) NOT NULL,
    base_amount       NUMERIC(19,4) NOT NULL,    -- signed: +debit / -credit in base currency
    running_balance    NUMERIC(19,4),             -- account running balance as of this entry (optional, see below)
    posted_at         TIMESTAMPTZ NOT NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (journal_line_id)
);
CREATE INDEX ledger_entries_company_account_date_idx
    ON ledger_entries (company_id, account_id, entry_date);
CREATE INDEX ledger_entries_period_idx
    ON ledger_entries (company_id, fiscal_period_id);
CREATE INDEX ledger_entries_cost_center_idx
    ON ledger_entries (cost_center_id) WHERE cost_center_id IS NOT NULL;
```

`ledger_entries` is never written to directly by application code, and it is never the target of a
manual correction. It is populated exclusively inside the same database transaction that flips a
`journal_entries.status` from `draft` to `posted` (see "Transactions" below), by a Laravel service
method (`JournalPostingService::post()`), never by a database trigger, so that the AI/observability
layer sees one clear code path for "how a ledger line came to exist." `running_balance` is
maintained incrementally at post time (read the previous balance for that `(account_id)` with a
row lock, add the signed amount, write it) rather than recomputed with a window function on every
read — this is the concrete "why": a live account statement for a busy control account must render
in single-digit milliseconds, and `SUM() OVER (ORDER BY entry_date)` across tens of thousands of
rows does not hold that bar at scale.

**Reporting snapshots — `inventory_valuations`, `report_runs`.** Point-in-time valuations are
denormalized snapshots by design, not a live view, because the valuation method (FIFO/weighted
average) is a computation over the full movement history and must not silently change for a prior
period when new movements are recorded today:

```sql
CREATE TABLE inventory_valuations (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    warehouse_id    BIGINT NOT NULL REFERENCES warehouses(id),
    product_id      BIGINT NOT NULL REFERENCES products(id),
    valuation_date  DATE NOT NULL,
    method          TEXT NOT NULL CHECK (method IN ('fifo','weighted_average')),
    quantity_on_hand NUMERIC(18,4) NOT NULL,
    unit_cost       NUMERIC(19,4) NOT NULL,
    total_value     NUMERIC(19,4) NOT NULL,
    computed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, warehouse_id, product_id, valuation_date, method)
);
```

This table is append-only per `(warehouse_id, product_id, valuation_date, method)`; a re-run for
the same date with the same method upserts (`ON CONFLICT ... DO UPDATE`) but a closed fiscal period
must never have its valuation silently rewritten — closing a period should copy the last
valuation row into an immutable `fiscal_period_closings` archive before any later recompute is
permitted to touch that date.

**Materialized views for dashboards.** Where a denormalized table would need application-level
refresh logic but the query is a pure aggregate with no per-row business decision attached
(e.g., "revenue by month by branch for the last 24 months" on a company dashboard), a materialized
view is preferred over a hand-maintained summary table, because Postgres owns the refresh
correctness:

```sql
CREATE MATERIALIZED VIEW mv_monthly_revenue_by_branch AS
SELECT le.company_id,
       le.branch_id,
       date_trunc('month', le.entry_date)::date AS period_month,
       SUM(le.credit - le.debit) AS revenue_base_amount
FROM ledger_entries le
JOIN accounts a ON a.id = le.account_id
JOIN account_types at ON at.id = a.account_type_id
WHERE at.code = 'revenue'
GROUP BY le.company_id, le.branch_id, date_trunc('month', le.entry_date);

CREATE UNIQUE INDEX mv_monthly_revenue_by_branch_uidx
    ON mv_monthly_revenue_by_branch (company_id, branch_id, period_month);

-- Refresh nightly via a scheduled Laravel command; CONCURRENTLY requires the unique index above
-- and avoids blocking readers during refresh.
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_monthly_revenue_by_branch;
```

**Standard.** Any denormalized table or materialized view must document, in a comment on the table
(`COMMENT ON TABLE ... IS '...'`) and in the migration's PR description, (a) which table is the
source of truth, (b) the exact write path that keeps it in sync (service method, scheduled job, or
`REFRESH MATERIALIZED VIEW`), and (c) the maximum acceptable staleness window. A denormalized table
with no documented refresh path is a defect, not a feature, and must be rejected in review.

# Transactions

Every write that must be atomic with another write is wrapped in a single PostgreSQL transaction,
and every such transaction is kept as short as physically possible: no HTTP calls, no AI inference
calls, no `Storage::put()` to R2, and no `Mail::send()` inside an open transaction. QAYD's default
isolation level is `READ COMMITTED` (the PostgreSQL default), which is sufficient for the majority
of CRUD writes because each statement sees a fresh snapshot and row-level locking (see "Locking")
handles write-write conflicts. Two categories of operation require a stronger isolation level or
explicit locking, and both are named below.

**The canonical example: posting a journal entry.** Posting is the single most safety-critical
write in the system — it must never leave a partially-posted entry, never post an unbalanced
entry, and never double-post the same source document (e.g., posting the same invoice twice
because a retried HTTP request raced a slow one).

```php
// app/Services/Accounting/JournalPostingService.php
public function post(JournalEntry $entry): JournalEntry
{
    return DB::transaction(function () use ($entry) {
        // 1. Lock the header row to serialize concurrent post attempts on the same entry.
        $locked = JournalEntry::where('id', $entry->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== JournalEntryStatus::Draft) {
            throw new JournalAlreadyPostedException($locked->id);
        }

        // 2. Re-validate balance under the lock — never trust a value computed before locking.
        $totals = $locked->lines()->selectRaw('SUM(debit) AS d, SUM(credit) AS c')->first();
        if (bccomp((string) $totals->d, (string) $totals->c, 4) !== 0) {
            throw new UnbalancedJournalEntryException($locked->id, $totals->d, $totals->c);
        }

        // 3. Flip state and materialize ledger_entries in the same transaction.
        $locked->update([
            'status'    => JournalEntryStatus::Posted,
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ]);

        foreach ($locked->lines as $line) {
            LedgerEntry::create([
                'company_id'       => $locked->company_id,
                'branch_id'        => $locked->branch_id,
                'journal_entry_id' => $locked->id,
                'journal_line_id'  => $line->id,
                'account_id'       => $line->account_id,
                'fiscal_year_id'   => $locked->fiscal_year_id,
                'fiscal_period_id' => $locked->fiscal_period_id,
                'entry_date'       => $locked->entry_date,
                'debit'            => $line->debit,
                'credit'           => $line->credit,
                'currency_code'    => $line->currency_code,
                'base_amount'      => $line->debit - $line->credit,
                'posted_at'        => $locked->posted_at,
            ]);
        }

        AuditLog::record($locked, 'posted', auth()->user());

        return $locked;
    }, attempts: 3); // Laravel retries on serialization/deadlock failures
}
```

`DB::transaction()`'s `$attempts` parameter causes Laravel to catch a
`PDOException`/`QueryException` carrying SQLSTATE `40001` (serialization_failure) or `40P01`
(deadlock_detected) and retry the entire closure. This is what makes it safe to rely on optimistic
concurrency elsewhere without hand-writing retry loops at every call site.

**Elevated isolation: SERIALIZABLE for cross-row invariants.** Posting a single entry is protected
by a row lock, but some operations protect an invariant that spans rows no single lock covers —
for example, "the sum of all unposted lines against a control account this period must not exceed
its configured credit limit," checked across many journal entries concurrently. For these, QAYD
uses `SERIALIZABLE`:

```php
DB::transaction(function () use ($companyId, $periodId) {
    // Postgres detects the write skew and raises 40001 if another concurrent
    // SERIALIZABLE transaction created a conflicting read/write dependency.
    DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    // ... read control account exposure, decide, write ...
}, attempts: 5);
```

`SERIALIZABLE` in Postgres is implemented via Serializable Snapshot Isolation (SSI): it does not
block readers/writers preemptively, it detects a dangerous structure of concurrent
read/write dependencies at commit time and aborts one transaction with `40001`. It is used
sparingly — only where the invariant genuinely cannot be expressed as a single-row `CHECK`
constraint or a row lock — because its abort rate rises with contention, and every caller of a
`SERIALIZABLE` block must be wrapped in Laravel's retry-on-conflict transaction as shown above.

**Idempotency for external/webhook-triggered writes.** Payment gateway callbacks, bank feed
imports, and AI-agent-submitted drafts can be delivered more than once. Every such entry point
requires an idempotency key stored with a unique constraint, checked inside the same transaction
as the effect:

```sql
CREATE TABLE idempotency_keys (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    scope         TEXT NOT NULL,          -- e.g. 'bank.webhook', 'ai.invoice_draft'
    key           TEXT NOT NULL,
    response_hash TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, scope, key)
);
```

```php
DB::transaction(function () use ($companyId, $scope, $key, $payload) {
    try {
        DB::table('idempotency_keys')->insert([
            'company_id' => $companyId, 'scope' => $scope, 'key' => $key,
            'created_at' => now(),
        ]);
    } catch (UniqueConstraintViolationException) {
        return; // already processed — no-op, do not reprocess side effects
    }
    // ... perform the effect exactly once ...
});
```

**Savepoints for partial rollback inside a larger unit of work.** Laravel exposes nested
transactions as savepoints automatically when `DB::transaction()` calls are nested; QAYD uses this
for multi-document workflows (e.g., "convert quotation to order, and if inventory reservation
fails, roll back only the reservation attempt but keep the order created in `draft`"):

```php
DB::transaction(function () {
    $order = SalesOrder::create([...]);
    try {
        DB::transaction(function () use ($order) {
            $this->inventoryService->reserve($order); // may throw InsufficientStockException
        });
    } catch (InsufficientStockException $e) {
        $order->update(['status' => SalesOrderStatus::PendingStock]);
        event(new StockReservationFailed($order, $e));
    }
});
```

**No distributed transactions across services.** The AI layer (FastAPI) never participates in a
database transaction with Laravel — it has no direct database credentials at all (per the platform
rule that the AI layer never writes to the database). Cross-service consistency (e.g., "AI marks an
invoice draft as reviewed, which should trigger a webhook") uses the transactional outbox pattern:
the state change and an `outbox_events` row are written in the same Postgres transaction; a
separate worker polls `outbox_events` and delivers webhooks/queue jobs, retrying on failure without
ever needing a two-phase commit across systems.

```sql
CREATE TABLE outbox_events (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    event_type    TEXT NOT NULL,          -- 'invoice.posted', 'payroll.completed', ...
    payload       JSONB NOT NULL,
    status        TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','dispatched','failed')),
    attempts      SMALLINT NOT NULL DEFAULT 0,
    available_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    dispatched_at TIMESTAMPTZ
);
CREATE INDEX outbox_events_pending_idx ON outbox_events (available_at) WHERE status = 'pending';
```

**Standard.** No transaction may hold a lock across a network call to a service outside PostgreSQL
(HTTP, S3/R2, SMTP, the AI layer). Every transaction that writes financial state re-validates its
invariants after acquiring its locks, not before. Every `DB::transaction()` around a write that can
legitimately be retried by an upstream caller carries `attempts` ≥ 2 and is idempotent via the
`idempotency_keys` table when the caller is external.

# Constraints

Constraints are the last line of defense — if application validation has a bug, a race condition,
or is simply bypassed (a raw SQL script run by an operator, a bugged migration backfill), the
database itself must refuse to store an impossible financial fact. QAYD requires every
transactional table to declare its invariants as real constraints, not only as Laravel
`FormRequest` validation rules.

**Primary and foreign keys.** Every table uses a `BIGINT GENERATED ALWAYS AS IDENTITY` surrogate
primary key (never a natural key, even when a natural key like `(company_id, code)` exists — the
natural key becomes a `UNIQUE` constraint instead, so it can change without cascading a primary key
rename). Foreign keys are always declared, always indexed (see "Indexes"), and always specify
`ON DELETE` behavior explicitly rather than relying on the database default of `NO ACTION`:

```sql
ALTER TABLE invoice_items
    ADD CONSTRAINT invoice_items_invoice_fk
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT;

ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_account_fk
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE customer_contacts
    ADD CONSTRAINT customer_contacts_customer_fk
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE;
```

`RESTRICT` (or the equivalent `NO ACTION`) is the default choice for any table referenced by a
financial or transactional record — an `accounts` row must never be deletable while
`journal_lines` reference it, full stop; the correct operation is to set the account
`status = 'archived'`, never delete it. `CASCADE` is reserved for genuinely dependent child rows
with no independent financial meaning, such as `customer_contacts` or `invoice_items` deleting with
their parent — and even there, because `invoices` are soft-deleted, a hard `DELETE` on `invoices`
essentially never happens in production; the cascade exists mainly to keep test/staging cleanup
simple.

**NOT NULL and defaults.** Every standard column (`company_id`, `created_at`, `updated_at`) is
`NOT NULL` per the platform rule; `deleted_at` is the one standard column that is nullable by
definition. Money and quantity columns default to `0`, never to `NULL`, so that `SUM()` aggregates
behave predictably without `COALESCE` scattered through every report query.

**CHECK constraints encode business rules directly.** The single most important CHECK constraint
in the schema is the one that keeps a journal line from being meaningless — a line must be a debit
or a credit, never both, never neither:

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_debit_xor_credit
    CHECK (
        (debit > 0 AND credit = 0) OR
        (credit > 0 AND debit = 0)
    );

ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_amounts_nonnegative
    CHECK (debit >= 0 AND credit >= 0);
```

Quantity and rate sanity:

```sql
ALTER TABLE stock_movements
    ADD CONSTRAINT stock_movements_quantity_nonzero CHECK (quantity <> 0);

ALTER TABLE invoice_items
    ADD CONSTRAINT invoice_items_quantity_positive CHECK (quantity > 0);

ALTER TABLE exchange_rates
    ADD CONSTRAINT exchange_rates_rate_positive CHECK (rate > 0);
```

State-machine columns are constrained to their known-valid enum values at the database level, in
addition to being PHP enums in the application, because a raw `UPDATE` (a hotfix, a data-migration
script) must not be able to write a status the application does not know how to render or process:

```sql
ALTER TABLE journal_entries
    ADD CONSTRAINT journal_entries_status_valid
    CHECK (status IN ('draft','posted','reversed','voided'));
```

**Whole-entry balance as a deferrable constraint.** A journal entry's own lines must balance
(`SUM(debit) = SUM(credit)`), but this cannot be a simple per-row `CHECK` — it is a constraint over
a set of sibling rows. QAYD enforces it two ways that must both hold: an application-level check
inside the posting transaction (shown in "Transactions" above, which is the *authoritative* gate
because it runs exactly once at post time and can produce a precise error), and a database-level
`CONSTRAINT TRIGGER` declared `DEFERRABLE INITIALLY DEFERRED` as a defense-in-depth backstop that
fires at commit time, after all lines of the entry have been inserted in the same transaction:

```sql
CREATE OR REPLACE FUNCTION fn_check_journal_balance() RETURNS TRIGGER AS $$
DECLARE
    v_debit  NUMERIC(19,4);
    v_credit NUMERIC(19,4);
    v_status TEXT;
BEGIN
    SELECT status INTO v_status FROM journal_entries WHERE id = NEW.journal_entry_id;
    IF v_status <> 'posted' THEN
        RETURN NULL; -- only enforce balance for entries that reached 'posted'
    END IF;
    SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0)
      INTO v_debit, v_credit
      FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;
    IF v_debit <> v_credit THEN
        RAISE EXCEPTION 'Journal entry % is unbalanced: debit=% credit=%',
            NEW.journal_entry_id, v_debit, v_credit;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER trg_journal_balance
    AFTER INSERT OR UPDATE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION fn_check_journal_balance();
```

`DEFERRABLE INITIALLY DEFERRED` is essential here: without it, the trigger would fire after the
*first* line insert, when the entry is necessarily still unbalanced (a two-line entry has one debit
and zero credits until the second `INSERT` runs), and would reject every valid entry. Deferring
until commit lets the whole batch of `INSERT`s land before the invariant is checked once.

**Uniqueness beyond the primary key.** Business-meaningful uniqueness is always a real `UNIQUE`
constraint, scoped by `company_id`, and — because financial rows are soft-deleted — expressed as a
partial unique index so that a voided/soft-deleted row does not permanently block reuse of a code:

```sql
CREATE UNIQUE INDEX invoices_company_number_uidx
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;

CREATE UNIQUE INDEX accounts_company_code_uidx
    ON accounts (company_id, code)
    WHERE deleted_at IS NULL;
```

**Non-overlapping ranges with EXCLUDE constraints.** `fiscal_periods` must never overlap within a
fiscal year — this is not expressible as a `CHECK` or `UNIQUE` constraint because it is a
pairwise-row invariant over a range type. PostgreSQL's `EXCLUDE` constraint (via the `btree_gist`
extension) enforces it declaratively:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE fiscal_periods (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
    period_no      SMALLINT NOT NULL,
    date_range     DATERANGE NOT NULL,
    status         TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed','locked')),
    closed_at      TIMESTAMPTZ,
    closed_by      BIGINT REFERENCES users(id),
    EXCLUDE USING GIST (company_id WITH =, date_range WITH &&)
);
```

**Guarding closed periods.** Once a fiscal period is `closed`, no journal line may post with an
`entry_date` inside it. This is enforced in `fn_check_journal_balance`'s sibling validation path
(and mirrored as an application-level guard before the transaction even starts, to fail fast with a
friendly error rather than a raw trigger exception).

```sql
ALTER TABLE journal_entries
    ADD CONSTRAINT journal_entries_period_open_at_post
    CHECK (true); -- placeholder marker; real enforcement is the trigger below, since it must
                   -- read fiscal_periods, which a plain CHECK cannot do.

CREATE OR REPLACE FUNCTION fn_prevent_post_to_closed_period() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.status = 'posted' AND OLD.status IS DISTINCT FROM 'posted' THEN
        IF EXISTS (
            SELECT 1 FROM fiscal_periods
            WHERE id = NEW.fiscal_period_id AND status IN ('closed','locked')
        ) THEN
            RAISE EXCEPTION 'Cannot post journal entry %: fiscal period % is closed',
                NEW.id, NEW.fiscal_period_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prevent_post_to_closed_period
    BEFORE UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION fn_prevent_post_to_closed_period();
```

**Standard.** A migration that introduces a state column without a `CHECK` enumerating its valid
values is rejected in review. A migration that introduces a monetary or quantity column without a
non-negativity (or explicitly-signed) `CHECK` where negative values are not a valid business state
is rejected. Every foreign key names its `ON DELETE` behavior explicitly — an unqualified foreign
key (defaulting to `NO ACTION`) is treated as an oversight, not an intentional choice, and is
flagged in review.

# Indexes

Indexes in QAYD are chosen to match real query patterns, not added speculatively "just in case."
Every index has a named reason, traceable to either a specific API endpoint's `WHERE`/`ORDER BY`
clause, a report's aggregation path, or a foreign key that is joined/filtered on in a hot query.
Because every business table is filtered by `company_id` on essentially every query (tenant
isolation is universal), `company_id` is the leading column of the large majority of composite
indexes — a bare index on `company_id` alone is rarely useful by itself and is avoided in favor of
composite indexes that also cover the next most selective predicate.

**B-tree is the default and covers the majority of cases.**

```sql
CREATE INDEX invoices_company_status_date_idx
    ON invoices (company_id, status, invoice_date DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX journal_lines_journal_entry_idx
    ON journal_lines (journal_entry_id);

CREATE INDEX bank_transactions_account_date_idx
    ON bank_transactions (bank_account_id, transaction_date DESC);
```

Column order in a composite index follows equality-predicates-first, then range/sort columns —
`(company_id, status, invoice_date DESC)` serves `WHERE company_id = ? AND status = ? ORDER BY
invoice_date DESC` as a single index scan with no separate sort step.

**Partial indexes exploit the soft-delete and status patterns that pervade this schema.** Because
"active, non-deleted" rows are the overwhelming majority of queries, and deleted/archived rows are
rarely scanned, partial indexes both shrink the index and make it more selective:

```sql
CREATE INDEX customers_company_active_idx
    ON customers (company_id)
    WHERE deleted_at IS NULL AND status = 'active';

CREATE INDEX journal_entries_draft_idx
    ON journal_entries (company_id, created_at)
    WHERE status = 'draft' AND deleted_at IS NULL;
```

The second index above exists specifically to serve the "pending approvals" dashboard widget,
which only ever queries drafts — indexing posted/reversed/voided rows for that query would be pure
waste.

**Covering indexes with `INCLUDE` avoid a heap fetch for narrow, hot read paths.** The invoice list
endpoint (`GET /api/v1/sales/invoices`) renders `invoice_number`, `status`, `total_amount`, and
`due_date` in its table view; a covering index lets Postgres answer the query from the index alone
(an index-only scan) when the visibility map is up to date:

```sql
CREATE INDEX invoices_list_covering_idx
    ON invoices (company_id, status, invoice_date DESC)
    INCLUDE (invoice_number, total_amount, due_date, customer_id)
    WHERE deleted_at IS NULL;
```

**GIN indexes for JSONB and full-text search.** `custom_fields`/`tags` JSONB columns and free-text
search across customer/vendor names use GIN, never a sequential scan with `LIKE '%...%'`:

```sql
CREATE INDEX products_custom_fields_gin_idx ON products USING GIN (custom_fields);

ALTER TABLE customers ADD COLUMN search_vector tsvector
    GENERATED ALWAYS AS (
        to_tsvector('simple', coalesce(name_en,'') || ' ' || coalesce(name_ar,'') || ' ' || coalesce(tax_id,''))
    ) STORED;
CREATE INDEX customers_search_gin_idx ON customers USING GIN (search_vector);
```

**BRIN for large, naturally time-ordered append-mostly tables.** `ledger_entries` and
`audit_logs` grow monotonically with `entry_date`/`created_at` and are rarely updated after insert
— a BRIN index on the date column is a fraction of the size of a B-tree and is sufficient for the
range-scan queries ("this fiscal period," "last 90 days of audit history") that dominate their
access pattern, while the B-tree indexes shown earlier still serve the equality/composite lookups:

```sql
CREATE INDEX ledger_entries_entry_date_brin_idx
    ON ledger_entries USING BRIN (entry_date) WITH (pages_per_range = 32);

CREATE INDEX audit_logs_created_at_brin_idx
    ON audit_logs USING BRIN (created_at);
```

**Index maintenance.** Adding an index on a table already carrying production traffic always uses
`CREATE INDEX CONCURRENTLY` (see "Migration Rules") to avoid the exclusive lock a plain
`CREATE INDEX` takes. Bloated indexes (heavy `UPDATE`/`DELETE` churn on a hot table) are rebuilt
with `REINDEX INDEX CONCURRENTLY` (PostgreSQL 12+), never a blocking `REINDEX`. `pg_stat_user_indexes`
is reviewed quarterly to find indexes with `idx_scan = 0` in production — an unused index only costs
write amplification and is dropped once confirmed dead.

**Standard.** Every new index ships in the same PR as the query it was written to serve, with the
query (or the endpoint name) named in the migration's comment or PR description. Every index on a
table larger than 100k rows in any tenant is created `CONCURRENTLY`. No index is added to "maybe
help" without an `EXPLAIN (ANALYZE, BUFFERS)` before/after comparison attached to the PR.

# Locking

PostgreSQL's MVCC means readers never block writers and writers never block readers, but
writer-vs-writer conflicts on the same row are real, and QAYD chooses pessimistic or optimistic
locking per-case based on contention likelihood and the cost of a lost update.

**Pessimistic locking — `SELECT ... FOR UPDATE`.** Used wherever a read-then-write sequence
absolutely must not race with another transaction reading and writing the same row(s): journal
entry posting (shown in full under "Transactions"), inventory quantity decrement at the moment of
stock allocation, and running-balance maintenance on `ledger_entries`.

```sql
-- Reserve stock for a sales order line: lock the specific inventory_items row so two
-- concurrent orders cannot both "see" the same available quantity and both succeed.
BEGIN;
SELECT quantity_available
FROM inventory_items
WHERE company_id = $1 AND warehouse_id = $2 AND product_id = $3
FOR UPDATE;
-- application checks quantity_available >= requested_qty, then:
UPDATE inventory_items
SET quantity_available = quantity_available - $4,
    quantity_reserved  = quantity_reserved + $4,
    updated_at = now()
WHERE company_id = $1 AND warehouse_id = $2 AND product_id = $3;
COMMIT;
```

`FOR UPDATE` takes the strongest row lock (blocks other `FOR UPDATE`/`FOR NO KEY UPDATE`/`UPDATE`/
`DELETE` on the same row) and is the correct choice here because a lost update on stock quantity is
an overselling incident, not a cosmetic bug.

**`FOR NO KEY UPDATE` for updates that do not touch a referenced/referencing key column.** Most
`UPDATE`s (e.g., bumping `updated_at`, changing a non-key status field) only need this weaker lock,
which does not conflict with a concurrent `FOR KEY SHARE` taken by a foreign-key check in another
table — Postgres chooses this automatically for plain `UPDATE` statements that do not modify a
column referenced by a foreign key, so no special syntax is usually required; it is called out here
because occasionally an explicit `FOR NO KEY UPDATE` in a `SELECT` is used to pre-lock a row for an
upcoming update without blocking concurrent FK-check locks unnecessarily.

**`FOR UPDATE SKIP LOCKED` for worker/queue-style dequeue patterns.** `outbox_events` dispatch and
scheduled `report_runs` pickup use `SKIP LOCKED` so that N concurrent workers never block on each
other and never double-process the same row:

```sql
WITH picked AS (
    SELECT id FROM outbox_events
    WHERE status = 'pending' AND available_at <= now()
    ORDER BY available_at
    FOR UPDATE SKIP LOCKED
    LIMIT 20
)
UPDATE outbox_events o
SET status = 'dispatched', dispatched_at = now(), attempts = attempts + 1
FROM picked WHERE o.id = picked.id
RETURNING o.*;
```

**Optimistic locking — a `version`/`lock_version` column.** Used where contention is rare but the
cost of a full row lock across a longer-lived edit session (e.g., a caregiver-facing form left open
in a browser tab, or an AI agent's proposed draft awaiting human approval) would hold a lock far
longer than a request lifecycle should. QAYD adds `lock_version INTEGER NOT NULL DEFAULT 0` to
tables edited through multi-step UI forms (`sales_quotations`, `purchase_requests`,
`report_definitions`):

```sql
ALTER TABLE sales_quotations ADD COLUMN lock_version INTEGER NOT NULL DEFAULT 0;
```

```php
$updated = DB::table('sales_quotations')
    ->where('id', $id)
    ->where('lock_version', $request->input('lock_version'))
    ->update([
        'status'       => $newStatus,
        'lock_version' => DB::raw('lock_version + 1'),
        'updated_at'   => now(),
    ]);

if ($updated === 0) {
    throw new OptimisticLockConflictException($id); // 409 Conflict to the client
}
```

The API returns `409 Conflict` with the current server state so the client can re-fetch and let the
user decide how to merge, rather than silently overwriting a concurrent edit.

**Advisory locks for cross-row sequence generation.** Invoice/bill/journal-entry human-readable
numbering (e.g., `INV-2026-000418`) must never collide or gap unpredictably under concurrency, but
locking the whole `invoices` table per number would serialize all invoice creation company-wide.
QAYD uses a session-scoped Postgres advisory lock keyed by `(company_id, document_type)`, held only
for the few milliseconds it takes to read-and-increment a per-company counter row:

```sql
CREATE TABLE document_number_sequences (
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    document_type  TEXT NOT NULL,
    next_number    BIGINT NOT NULL DEFAULT 1,
    PRIMARY KEY (company_id, document_type)
);
```

```php
DB::transaction(function () use ($companyId, $docType) {
    $lockKey = crc32($companyId . ':' . $docType);
    DB::statement('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

    $seq = DB::table('document_number_sequences')
        ->where('company_id', $companyId)->where('document_type', $docType)
        ->lockForUpdate()->first();

    $number = $seq->next_number;
    DB::table('document_number_sequences')
        ->where('company_id', $companyId)->where('document_type', $docType)
        ->update(['next_number' => $number + 1]);

    return $number;
});
```

`pg_advisory_xact_lock` is released automatically at transaction end (commit or rollback), which
avoids the classic bug of a manually-released advisory lock never being released because an
exception skipped the unlock call.

**Deadlock avoidance.** Deadlocks in QAYD arise almost exclusively from acquiring row locks on
multiple tables in inconsistent order across different code paths (transaction A locks
`inventory_items` then `sales_orders`; transaction B locks `sales_orders` then `inventory_items`).
The standing rule is a fixed lock acquisition order for any operation touching more than one
lockable table: **header before lines, parent before child, and — where multiple domain tables are
locked in one transaction — always in this order: `accounts` → `journal_entries` →
`journal_lines` → `inventory_items` → `bank_accounts`.** This order is documented once here and
referenced by comment at every call site that takes more than one lock:

```php
// Lock order per DATABASE_STANDARDS.md "Locking": journal_entries before inventory_items.
$entry = JournalEntry::lockForUpdate()->find($entryId);
$stock = InventoryItem::lockForUpdate()->find($inventoryItemId);
```

Postgres detects true deadlocks automatically (default `deadlock_timeout = 1s`) and aborts one
participant with SQLSTATE `40P01`; because every multi-row-lock transaction in QAYD runs inside
`DB::transaction(..., attempts: N)`, a deadlock victim retries automatically rather than surfacing
an error to the user. The lock-ordering rule exists to keep the deadlock rate low, not to eliminate
retries as a mechanism — both layers of defense are required.

# Concurrency

Concurrency correctness in QAYD is reasoned about at three levels: what MVCC guarantees for free,
what isolation level a given operation needs on top of that, and what application-level
coordination (locks, unique constraints, idempotency keys) closes the remaining gap.

**MVCC baseline.** Every statement in `READ COMMITTED` (the default) sees a snapshot taken at the
start of that statement; a long-running report query never blocks a concurrent `UPDATE`, and vice
versa. This is why QAYD never uses `SELECT ... FOR SHARE` defensively "just in case" on read paths
— plain reads are already consistent and non-blocking, and adding locks to read-only reporting
queries would only create unnecessary contention against writers.

**Where `READ COMMITTED` is not enough: non-repeatable reads inside a single business
transaction.** A payroll run reads `salary_components` for an employee, computes deductions, then
writes `payroll_items` — if a concurrent HR edit changes a salary component between the read and
the write, `READ COMMITTED` would silently use the pre-edit value with no error. QAYD closes this
gap by taking `FOR SHARE` locks on the read for anything that will directly determine a monetary
write within the same transaction, so a concurrent editor is blocked (briefly) rather than allowed
to silently invalidate the in-flight calculation:

```sql
SELECT * FROM salary_components
WHERE employee_id = $1 AND effective_date <= $2
FOR SHARE;
```

**Serialization failures and retry semantics.** As established under "Transactions," any operation
using `SERIALIZABLE` or relying on advisory/row locks under contention must be wrapped in a
retryable transaction. The retry budget is capped (3–5 attempts) and each retry uses exponential
backoff with jitter at the application layer for anything beyond Laravel's built-in immediate retry,
to avoid a thundering-herd of a retries against the same hot row:

```php
retry(3, function () use ($closure) {
    return DB::transaction($closure);
}, function (int $attempt) {
    return random_int(10, 50) * $attempt; // ms backoff with jitter
});
```

**Read/write splitting and replica lag.** Reporting queries (Trial Balance, aging reports,
dashboard aggregates) are routed to a read replica where available. Because streaming replication
is asynchronous, replica reads are eventually consistent — a report immediately after posting an
entry may not yet reflect it. QAYD's rule: any read that a user action depends on *seeing
immediately* (e.g., "your invoice was posted, here is its updated status") reads from the primary;
only background/dashboard/report reads that tolerate a few hundred milliseconds of lag are routed
to a replica. Laravel's `DB::connection('reporting')` is used explicitly at those call sites, never
implicitly — there is no silent global read/write split that could surprise a future maintainer.

**Race conditions specific to inventory and cash.** Two classes of concurrency bug are treated as
P0 severity because they map directly to money or stock leaking: (1) overselling — two concurrent
orders both reading `quantity_available` before either writes, both succeeding, and going negative;
prevented by the `FOR UPDATE` pattern shown under "Locking," plus a defense-in-depth `CHECK
(quantity_available >= 0)` constraint that would abort a buggy code path that skipped the lock
entirely. (2) double-payment allocation — a receipt being applied to the same invoice twice by two
concurrent requests (a retried API call and the original both landing); prevented by the
idempotency-key pattern from "Transactions" plus a `UNIQUE (receipt_id, invoice_id)` constraint on
`receipt_allocations` so that even a code path that forgot the idempotency check cannot double-book
the same allocation pair.

```sql
ALTER TABLE receipt_allocations
    ADD CONSTRAINT receipt_allocations_unique_pair UNIQUE (receipt_id, invoice_id);

ALTER TABLE inventory_items
    ADD CONSTRAINT inventory_items_qty_nonnegative
    CHECK (quantity_available >= 0 AND quantity_reserved >= 0);
```

**Standard.** Any code path that reads a value and later writes a dependent value in the same
transaction, where a concurrent writer to that value would make the calculation wrong, must lock
the read (`FOR UPDATE`/`FOR SHARE`) or use `SERIALIZABLE` with retry — "we'll probably be fine, it's
rare" is not an acceptable justification for skipping this in a financial system. Every table
protecting a physical or monetary quantity that cannot legally go negative carries a `CHECK`
constraint enforcing that, independent of and in addition to any application-level guard.

# Performance

QAYD's performance standard is driven by two numbers: interactive API endpoints must return in
under 200ms at p95 for a tenant with realistic data volume (assume 500k `ledger_entries` rows, 50k
`invoices`, 100k `stock_movements` as the "large tenant" benchmark), and background/report jobs must
not starve interactive traffic — they run on a separate connection pool and, where they are
genuinely heavy, on a read replica.

**`EXPLAIN (ANALYZE, BUFFERS)` is mandatory before merging any new non-trivial query.** A query is
"non-trivial" if it joins more than two tables, aggregates, or is expected to run against a table
that will exceed 10k rows for any tenant. The PR description includes the plan; reviewers reject a
plan showing a `Seq Scan` on a table where an index was expected to be used, unless the planner's
choice is justified (e.g., the table is small enough that a sequential scan is genuinely cheaper —
Postgres's cost-based planner is usually right about this for tables under a few thousand rows).

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT i.id, i.invoice_number, i.total_amount, c.name_en
FROM invoices i
JOIN customers c ON c.id = i.customer_id
WHERE i.company_id = 42 AND i.status = 'overdue'
ORDER BY i.due_date
LIMIT 25;
```

**Pagination is keyset-based for any list that can grow large, never `OFFSET` beyond the first
page.** `OFFSET 10000` forces Postgres to scan and discard 10,000 rows on every request; QAYD's API
convention (per the shared design context) supports cursor pagination precisely to avoid this:

```sql
-- Page 1
SELECT * FROM invoices
WHERE company_id = $1 AND deleted_at IS NULL
ORDER BY id DESC LIMIT 25;

-- Subsequent page, using the last row's id as the cursor
SELECT * FROM invoices
WHERE company_id = $1 AND deleted_at IS NULL AND id < $2
ORDER BY id DESC LIMIT 25;
```

**Connection pooling via PgBouncer in transaction mode.** Laravel's PHP-FPM/Octane workers open
short-lived connections; without pooling, PostgreSQL's per-connection memory overhead
(`work_mem`, backend process) does not scale to hundreds of concurrent PHP workers. QAYD runs
PgBouncer between the application and PostgreSQL, `pool_mode = transaction`, sized to
`default_pool_size` matched to `max_connections` minus headroom for `superuser_reserved_connections`
and replication:

```ini
[databases]
qayd_production = host=127.0.0.1 port=5432 dbname=qayd pool_mode=transaction

[pgbouncer]
max_client_conn = 2000
default_pool_size = 40
```

Session-level features (advisory locks held across statements without a wrapping transaction,
`SET` outside a transaction) are avoided in transaction-mode pooling because the underlying server
connection can be handed to a different client between transactions; every advisory lock QAYD takes
uses the transaction-scoped variant (`pg_advisory_xact_lock`, released at commit) specifically for
this reason.

**Avoiding N+1 queries.** Eloquent relationships are always eager-loaded on any endpoint returning
a collection with nested data; the standard code-review check is grep-ing the diff for a loop over
a collection followed by a relationship access with no preceding `with()`/`load()`:

```php
// WRONG — N+1: one query per invoice to fetch its customer
$invoices = Invoice::where('company_id', $companyId)->get();
foreach ($invoices as $invoice) { echo $invoice->customer->name_en; }

// RIGHT
$invoices = Invoice::where('company_id', $companyId)->with('customer', 'items.product')->get();
```

**Partitioning for the largest append-mostly tables.** `ledger_entries` and `audit_logs` are
declared as range-partitioned by month once a tenant's row count history indicates it will benefit
(operationally: any table projected to exceed ~20 million rows within its first two years).
Partitioning keeps each partition's indexes small (better cache locality, faster `VACUUM`) and
allows old partitions to be moved to cheaper storage or dropped per a data-retention policy without
a slow `DELETE`:

```sql
CREATE TABLE ledger_entries (
    -- ...same columns as earlier definition...
    entry_date DATE NOT NULL
) PARTITION BY RANGE (entry_date);

CREATE TABLE ledger_entries_2026_07 PARTITION OF ledger_entries
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE ledger_entries_2026_08 PARTITION OF ledger_entries
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
```

New monthly partitions are created by a scheduled Laravel command a month ahead of need; a missing
future partition is a paging alert, not a silent insert failure.

**Batch writes use `COPY` or multi-row `INSERT`, never per-row round trips.** Bank statement
imports (potentially thousands of `bank_statement_lines` per file) use PostgreSQL's `COPY` via
Laravel's `DB::unprepared` bulk path or chunked multi-row inserts, not one `INSERT` per line:

```php
DB::table('bank_statement_lines')->insert($chunkOf500Rows); // chunked, not row-by-row
```

**`statement_timeout` and `lock_timeout` are set per role, not left at server defaults of
`0` (unlimited).** The application's database role has a conservative `statement_timeout` so a
runaway query cannot pin a connection (and, transitively, a PgBouncer slot) forever; migrations use
a separate role with a longer timeout but a strict `lock_timeout`:

```sql
ALTER ROLE qayd_app SET statement_timeout = '15s';
ALTER ROLE qayd_app SET lock_timeout = '5s';
ALTER ROLE qayd_migrator SET lock_timeout = '2s';  -- fail fast rather than blocking production traffic
```

**Autovacuum tuning for high-churn tables.** `inventory_items` and `bank_accounts` (frequent
`UPDATE`s to running quantities/balances) get more aggressive per-table autovacuum settings than
the cluster default, so dead tuples do not bloat the table and its indexes between vacuum runs:

```sql
ALTER TABLE inventory_items SET (autovacuum_vacuum_scale_factor = 0.02, autovacuum_analyze_scale_factor = 0.01);
```

**Standard.** Any PR introducing a query against a table expected to exceed 10k rows per tenant
must include an `EXPLAIN (ANALYZE, BUFFERS)` output. Any list endpoint must use keyset pagination.
Any loop touching an Eloquent relationship must be preceded by eager loading; this is enforced by a
Larastan/PHPStan custom rule where feasible and by manual review otherwise.

# Security

Database security in QAYD is layered: network-level access control, database-role least
privilege, Row Level Security as a second independent enforcement of tenant isolation, encryption
at rest and in transit, and injection-proofing at the query-construction layer. No single layer is
trusted alone — the application-layer `company_id` scoping that every repository applies is
necessary but not sufficient; RLS exists precisely so that a bug in application code (a forgotten
`where('company_id', ...)`) cannot leak Company A's data to Company B.

**Network access.** PostgreSQL is never reachable from the public internet. It sits in a private
subnet; only the Laravel application servers, the migration CI runner, and a bastion host for
break-glass access can reach port 5432, enforced by security group / VPC rules, not by
`pg_hba.conf` alone. All connections require TLS:

```conf
# postgresql.conf
ssl = on
ssl_min_protocol_version = 'TLSv1.2'

# pg_hba.conf — reject any non-SSL connection from the application subnet
hostssl qayd_production qayd_app 10.0.1.0/24 scram-sha-256
```

```ini
# Laravel .env — refuse to connect without SSL, and verify the server certificate
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/etc/ssl/certs/rds-ca-bundle.pem
```

**Least-privilege database roles.** The application connects as `qayd_app`, a role with `SELECT,
INSERT, UPDATE, DELETE` on business tables but explicitly **no** `TRUNCATE`, no `DROP`, and no
`DDL` privileges at all — schema changes run only through the separate `qayd_migrator` role used
exclusively by the CI/CD migration step, never by the running application:

```sql
CREATE ROLE qayd_app LOGIN PASSWORD :'app_password';
GRANT CONNECT ON DATABASE qayd_production TO qayd_app;
GRANT USAGE ON SCHEMA public TO qayd_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO qayd_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO qayd_app;
REVOKE TRUNCATE ON ALL TABLES IN SCHEMA public FROM qayd_app;

CREATE ROLE qayd_migrator LOGIN PASSWORD :'migrator_password';
GRANT ALL PRIVILEGES ON SCHEMA public TO qayd_migrator;

CREATE ROLE qayd_readonly LOGIN PASSWORD :'reporting_password';
GRANT CONNECT ON DATABASE qayd_production TO qayd_readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO qayd_readonly; -- used by BI/reporting connection only
```

A fourth role, `qayd_ai_readonly`, is granted `SELECT` only on the specific views the AI layer is
allowed to read for context-grounding (never the base tables directly, and never any table holding
`vendor_bank_accounts`, `payroll_items`, or `employees.national_id`-class PII) — this is the
database-level enforcement of the platform rule that the AI layer never writes to the database and
sees only the active company's data:

```sql
CREATE ROLE qayd_ai_readonly LOGIN PASSWORD :'ai_password';
GRANT SELECT ON v_ai_invoice_context, v_ai_customer_context TO qayd_ai_readonly;
-- no GRANT on employees, vendor_bank_accounts, payroll_items, tax_transactions to this role, ever
```

**Row Level Security as a second, independent tenant-isolation layer.** RLS policies are enabled on
every business table and enforce `company_id = current_setting('app.current_company_id')::bigint`
as a database-level guarantee, so that even a raw SQL query issued through a debugging tool, an
ORM bug, or a future service that connects with the `qayd_app` role cannot cross tenant boundaries:

```sql
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoices FORCE ROW LEVEL SECURITY;  -- applies even to the table owner

CREATE POLICY invoices_tenant_isolation ON invoices
    USING (company_id = current_setting('app.current_company_id', true)::bigint)
    WITH CHECK (company_id = current_setting('app.current_company_id', true)::bigint);
```

```php
// Laravel middleware, set once per request from the authenticated user's active company:
DB::statement('SET app.current_company_id = ?', [$request->user()->active_company_id]);
```

Every tenant-scoped table (all tables carrying `company_id`) gets this same policy generated by a
migration helper (`Schema::enableTenantRls('invoices')`) rather than hand-written per table, so
coverage is complete and consistent; CI includes a check that fails the build if any table with a
`company_id` column lacks an enabled RLS policy.

**Column-level protection for the most sensitive fields.** `vendor_bank_accounts.iban` and
`employees.national_id` are stored encrypted at the application layer using Laravel's `encrypted`
Eloquent cast (AES-256-GCM, key from `APP_KEY`/a dedicated KMS-backed key for production) rather
than relying solely on disk-level (at-rest) encryption — this protects the value even from someone
with direct, authorized database read access who has no business reason to see it in the clear
(e.g., an analyst querying the reporting replica):

```php
protected $casts = [
    'iban' => 'encrypted',
];
```

Disk-level encryption at rest (managed by the cloud provider's volume encryption, AES-256) and
encrypted automated backups are mandatory regardless of column-level encryption — they are
complementary, not substitutes for each other.

**Injection-proofing.** All application queries go through Eloquent's query builder or parameterized
`DB::statement()`/`DB::select()` calls with bound parameters; raw string interpolation into SQL is
banned outright, including for seemingly "safe" values like an internal enum, because a future edit
that makes that value user-controlled silently reintroduces the vulnerability:

```php
// BANNED
DB::select("SELECT * FROM invoices WHERE status = '{$status}'");

// REQUIRED
DB::select('SELECT * FROM invoices WHERE status = ?', [$status]);
```

A static-analysis rule (a custom PHPStan/Larastan rule, backed by a CI grep fallback for
`->raw(` and string interpolation inside `DB::` calls) blocks merges that introduce interpolated SQL.

**Audit logging is itself a security control, not only a compliance artifact.** Every `INSERT`,
`UPDATE`, and (soft) `DELETE` on a financial table writes an `audit_logs` row capturing the actor,
timestamp, IP, device fingerprint, and a diff of old/new values — this is what lets a security
review reconstruct exactly who changed what, which is often the fastest way to detect and scope an
account-compromise incident.

```sql
CREATE TABLE audit_logs (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    actor_user_id BIGINT REFERENCES users(id),
    actor_type    TEXT NOT NULL DEFAULT 'user' CHECK (actor_type IN ('user','system','ai_agent')),
    action        TEXT NOT NULL,             -- 'created','updated','posted','voided','deleted'
    entity_type   TEXT NOT NULL,
    entity_id     BIGINT NOT NULL,
    old_values    JSONB,
    new_values    JSONB,
    reason        TEXT,
    ip_address    INET,
    user_agent    TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
) PARTITION BY RANGE (created_at);
CREATE INDEX audit_logs_entity_idx ON audit_logs (company_id, entity_type, entity_id);
```

`audit_logs` rows are themselves immutable (no `UPDATE`/`DELETE` grant to `qayd_app` on this table
beyond `INSERT`) and are retained per the company's regulatory retention requirement (commonly 5–7
years for financial records in Gulf jurisdictions), enforced by not dropping partitions before that
horizon rather than by a `DELETE` statement.

**Secrets management.** Database credentials, encryption keys, and API keys for the AI/notification
layers are never committed to the repository or stored in plaintext `.env` files in production —
they are injected at deploy time from the platform's secrets manager (AWS Secrets Manager /
equivalent), rotated on a defined schedule, and the `qayd_migrator` credential is scoped to CI's
ephemeral runner identity, never a long-lived static password checked into a pipeline config file.

**Standard.** Every table with a `company_id` column has `FORCE ROW LEVEL SECURITY` enabled with a
tenant-isolation policy before it is allowed into a production migration; CI blocks merges that add
such a table without the corresponding policy. No raw string-interpolated SQL is permitted anywhere
in the codebase. Any new column holding a national ID, bank account/IBAN, or full payment card
reference must use application-layer field encryption in addition to disk-level encryption, and
must never be exposed to the `qayd_ai_readonly` role.

# Migration Rules

QAYD ships schema changes exclusively through Laravel migrations, run by CI against a staging clone
before any production apply, and every migration is written to be safe against a live, high-traffic
table — "safe" meaning it does not hold a long-lived exclusive lock, does not do a full-table
rewrite while the table is under concurrent write load, and can be rolled back or is explicitly
documented as non-reversible with a stated reason.

**Adding a column is always nullable or defaulted without a table rewrite.** As of PostgreSQL 11+,
adding a column with a constant default no longer rewrites the table, so `ADD COLUMN ... DEFAULT`
is safe. Adding a column with `NOT NULL` and no default, however, requires an existing value for
every row and is done in the safe expand/contract sequence, never as a single blocking step on a
large table:

```php
// Migration 1 — expand: add nullable, no lock beyond a brief metadata change
Schema::table('invoices', function (Blueprint $table) {
    $table->string('payment_terms_code')->nullable();
});
```

```sql
-- Backfill in batches, outside a single giant transaction, to avoid long-held locks
-- and to bound the size of any one transaction's write set.
UPDATE invoices SET payment_terms_code = 'NET30'
WHERE id BETWEEN 1 AND 100000 AND payment_terms_code IS NULL;
-- repeated in batches by a Laravel command, not as one unbounded statement
```

```php
// Migration 2 — contract: add the NOT NULL constraint only after backfill is verified complete
Schema::table('invoices', function (Blueprint $table) {
    $table->string('payment_terms_code')->nullable(false)->change();
});
```

**Adding a `CHECK` or `NOT NULL` constraint to an existing large table uses `NOT VALID` +
`VALIDATE CONSTRAINT`, never a single blocking `ADD CONSTRAINT`.** A plain `ADD CONSTRAINT`
requires an `ACCESS EXCLUSIVE` lock for the entire duration of scanning every existing row to prove
compliance — on a large `journal_lines` table in a busy tenant this can block all reads and writes
for minutes. `NOT VALID` takes the same strong lock but only long enough to register the
constraint's existence (near-instant); `VALIDATE CONSTRAINT` then does the expensive full-table
scan while holding only a `SHARE UPDATE EXCLUSIVE` lock, which does not block normal reads/writes:

```sql
-- Step 1: near-instant, brief lock only
ALTER TABLE journal_lines
    ADD CONSTRAINT journal_lines_amounts_nonnegative
    CHECK (debit >= 0 AND credit >= 0) NOT VALID;

-- Step 2: scans existing rows without blocking concurrent reads/writes
ALTER TABLE journal_lines
    VALIDATE CONSTRAINT journal_lines_amounts_nonnegative;
```

The same two-step pattern applies to adding a foreign key to an existing large table:

```sql
ALTER TABLE invoice_items
    ADD CONSTRAINT invoice_items_tax_rate_fk
    FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) NOT VALID;

ALTER TABLE invoice_items
    VALIDATE CONSTRAINT invoice_items_tax_rate_fk;
```

**Every index on a populated table is created `CONCURRENTLY`.** A plain `CREATE INDEX` takes a lock
that blocks writes for the full build duration; `CONCURRENTLY` builds the index without blocking
writes, at the cost of taking roughly twice as long and requiring the migration to handle the
(rare) case of a failed concurrent build leaving an invalid index behind:

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS invoices_company_status_idx
    ON invoices (company_id, status);
```

`CREATE INDEX CONCURRENTLY` cannot run inside a transaction block; Laravel migrations that use it
must set `public $withinTransaction = false;` on the migration class so Laravel does not wrap it in
an implicit transaction.

```php
class AddInvoicesCompanyStatusIndex extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS invoices_company_status_idx ON invoices (company_id, status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS invoices_company_status_idx');
    }
}
```

**Renaming or dropping a column never happens directly in one deploy step.** A column rename is
executed as: (1) add the new column, (2) dual-write to both columns from the application for one
release cycle, (3) backfill historical rows, (4) switch reads to the new column, (5) stop writing
the old column, (6) drop the old column in a later migration once confidence is established that no
code path or report depends on it. This sequence exists because Laravel deploys are not
instantaneous and not perfectly atomic with the migration — there is always a window where old and
new application code can run against the same schema version, and a same-step rename breaks the
old code for the duration of that window.

**`lock_timeout` is set explicitly for every migration** so a migration that unexpectedly contends
with a long-running production transaction fails fast and loud, rather than queueing behind it and
blocking every subsequent query on that table for an unbounded time:

```php
public function up(): void
{
    DB::statement("SET lock_timeout = '3s'");
    Schema::table('bills', function (Blueprint $table) {
        $table->boolean('is_recurring')->default(false);
    });
}
```

**Migrations are tested against a production-shaped staging clone before applying to production.**
CI runs every pending migration against a nightly-refreshed, PII-scrubbed copy of production
(row counts and index sizes representative of the largest tenant) and captures the lock duration
and row-rewrite count for review; a migration whose staging run shows a lock held longer than 2
seconds on a table with live-traffic equivalents is rejected and must be split into a safer
expand/contract sequence.

**Every migration has a working `down()` or explicitly declares itself irreversible with a reason.**
A migration that drops a column, drops a table, or performs a lossy data transformation implements
`down()` to restore structure (even if historical data cannot be perfectly restored) or documents in
the migration file's docblock why rollback is intentionally unsupported (e.g., "irreversible: this
migration permanently merges duplicate customer records after manual data-quality review; rollback
would require restoring from the pre-migration backup, referenced as backup id `bkp-2026-07-16`").

**Standard.** No migration may take an `ACCESS EXCLUSIVE` lock on a table with production write
traffic for longer than the team's agreed threshold (2 seconds) without an explicit,
reviewer-approved maintenance-window exception. Every `ADD CONSTRAINT`/`ADD FOREIGN KEY` on an
existing table uses `NOT VALID` + `VALIDATE CONSTRAINT`. Every `CREATE INDEX` on an existing table
uses `CONCURRENTLY`. Every migration is dry-run against a staging clone in CI before it is eligible
to merge.

# Testing

Database correctness in QAYD is tested at three layers: schema-level invariants (does the
constraint actually reject what it should, using pgTAP), application-level behavior (does the
Laravel service produce the right rows, using Pest/PHPUnit against a real Postgres instance), and
concurrency behavior (does the locking strategy actually prevent the race it claims to prevent).

**pgTAP for schema and constraint tests.** Every constraint introduced under "Constraints" has a
corresponding pgTAP test asserting both that a compliant row is accepted and that a violating row
is rejected — a constraint with no test proving it rejects bad data is, in practice, undocumented
and unverified:

```sql
-- tests/pgtap/journal_lines_constraints.sql
BEGIN;
SELECT plan(4);

SELECT lives_ok(
    $$ INSERT INTO journal_lines (company_id, journal_entry_id, account_id, debit, credit)
       VALUES (1, 1, 1, 100.0000, 0) $$,
    'a debit-only line is accepted'
);

SELECT throws_ok(
    $$ INSERT INTO journal_lines (company_id, journal_entry_id, account_id, debit, credit)
       VALUES (1, 1, 1, 100.0000, 50.0000) $$,
    '23514', -- check_violation
    NULL,
    'a line with both debit and credit set is rejected'
);

SELECT throws_ok(
    $$ INSERT INTO journal_lines (company_id, journal_entry_id, account_id, debit, credit)
       VALUES (1, 1, 1, -10.0000, 0) $$,
    '23514',
    NULL,
    'a negative debit is rejected'
);

SELECT throws_ok(
    $$ INSERT INTO invoices (company_id, invoice_number, customer_id)
       VALUES (1, 'INV-0001', 1) $$, -- duplicate of an existing seeded row
    '23505', -- unique_violation
    NULL,
    'a duplicate invoice_number within the same company is rejected'
);

SELECT * FROM finish();
ROLLBACK;
```

pgTAP suites run in CI against an ephemeral PostgreSQL 15 container (via `docker compose`), seeded
with the minimum fixture rows each test needs, and always wrapped in `BEGIN; ... ROLLBACK;` so tests
never leave residue for the next run.

**Testing the balance-enforcement trigger explicitly.** Because the deferred constraint trigger
under "Constraints" only fires at commit, and pgTAP wraps each test in its own transaction, the
trigger test must post an entry and then run `SET CONSTRAINTS ALL IMMEDIATE` before the assertion
to force the deferred check without actually committing:

```sql
SELECT throws_ok(
    $$
    INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
        VALUES (:posted_entry_id, 1, 100.0000, 0);
    SET CONSTRAINTS trg_journal_balance IMMEDIATE;
    $$,
    NULL, '%unbalanced%',
    'inserting an unmatched debit into a posted entry raises the balance trigger'
);
```

**Application-level tests against a real database, not a mock.** QAYD's Pest test suite for
services touching money never mocks the query builder — it runs against a real, ephemeral
PostgreSQL test database (`RefreshDatabase` trait, migrations run once per test suite, each test
wrapped in a transaction that rolls back), because the constraints and locking behavior under test
only exist in real PostgreSQL, not in an in-memory substitute:

```php
it('rejects posting an unbalanced journal entry', function () {
    $entry = JournalEntry::factory()
        ->has(JournalLine::factory()->debit(100))
        ->has(JournalLine::factory()->credit(90))
        ->create(['status' => JournalEntryStatus::Draft]);

    expect(fn () => app(JournalPostingService::class)->post($entry))
        ->toThrow(UnbalancedJournalEntryException::class);

    expect($entry->refresh()->status)->toBe(JournalEntryStatus::Draft);
    expect(LedgerEntry::where('journal_entry_id', $entry->id)->count())->toBe(0);
});

it('prevents posting into a closed fiscal period', function () {
    $period = FiscalPeriod::factory()->closed()->create();
    $entry = JournalEntry::factory()->balanced()->create(['fiscal_period_id' => $period->id]);

    expect(fn () => app(JournalPostingService::class)->post($entry))
        ->toThrow(QueryException::class); // surfaces the trg_prevent_post_to_closed_period exception
});
```

**Concurrency tests exercise the actual race, not just the code path.** A row-lock test spins up two
real concurrent connections (Pest's `Process`/`Http::pool`-style concurrency helpers, or a small
PHP script forking two PDO connections) and asserts that the second writer blocks until the first
commits, and that the final state is consistent — this is the only way to catch a regression where a
future refactor accidentally drops the `lockForUpdate()` call:

```php
it('serializes concurrent stock reservations and never oversells', function () {
    $item = InventoryItem::factory()->create(['quantity_available' => 10]);

    $results = collect([5, 5, 5])->map(fn ($qty) => spawn(function () use ($item, $qty) {
        return app(InventoryService::class)->reserve($item->id, $qty);
    }))->map(fn ($future) => $future->await(catch: true));

    $succeeded = $results->filter(fn ($r) => ! $r instanceof Throwable);
    expect($succeeded->count())->toBe(2); // exactly two of the three 5-unit reservations fit in 10
    expect($item->refresh()->quantity_available)->toBe(0.0);
});
```

**Data-quality tests run against production (read-only) on a schedule, not only against fixtures.**
A nightly scheduled Laravel command runs a fixed battery of invariant-checking queries against the
real production replica and pages the on-call engineer if any returns a non-zero count — this is
distinct from unit/integration tests because it catches drift introduced by a hotfix, a manual data
correction, or an as-yet-unknown bug path that no test anticipated:

```sql
-- Invariant: every posted journal entry balances.
SELECT je.id FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
WHERE je.status = 'posted'
GROUP BY je.id
HAVING SUM(jl.debit) <> SUM(jl.credit);

-- Invariant: no inventory_items row is negative.
SELECT id FROM inventory_items WHERE quantity_available < 0 OR quantity_reserved < 0;

-- Invariant: every ledger_entries row traces to a posted journal_entries row.
SELECT le.id FROM ledger_entries le
JOIN journal_entries je ON je.id = le.journal_entry_id
WHERE je.status <> 'posted';
```

**Standard.** Every new `CHECK`/`UNIQUE`/foreign-key constraint ships with a pgTAP test proving both
the accept and reject paths. Every service method that acquires a row lock or relies on an
isolation-level guarantee ships with a concurrency test that actually exercises two simultaneous
connections, not only a sequential unit test. The nightly production invariant-check battery is
extended whenever a new cross-table financial invariant is introduced, in the same PR that
introduces the invariant.

# Documentation

Every table, and every non-obvious column, carries a live `COMMENT` in the database itself, not only
in an external wiki that can drift out of sync — `psql`'s `\d+` and any SQL client immediately shows
a maintainer why a column exists and what it is not for:

```sql
COMMENT ON TABLE ledger_entries IS
    'Denormalized, append-only projection of posted journal_lines. Source of truth is '
    'journal_lines; this table is written exclusively by JournalPostingService::post() '
    'inside the same transaction that posts the journal entry. Never write here directly.';

COMMENT ON COLUMN journal_lines.debit IS
    'Debit amount in the line''s currency_code. Exactly one of debit/credit is nonzero '
    '(see CHECK journal_lines_debit_xor_credit). Zero, never NULL.';

COMMENT ON COLUMN accounts.is_control_account IS
    'TRUE for AR/AP/inventory control accounts that must reconcile to a sub-ledger total '
    '(customers.balance / vendors.balance / inventory_valuations). Never posted to directly '
    'by a manual journal entry outside the sub-ledger posting service.';
```

**Schema documentation is regenerated, not hand-maintained, wherever automation exists.** An ERD
(entity-relationship diagram) is regenerated from the live schema on every merge to `main` via a CI
step (`schemaspy`/equivalent against a throwaway migrated database) and published to the internal
docs site, so the diagram a new engineer reads is never more than one merge behind reality.
TypeScript types for the Next.js frontend and PHP model annotations are likewise generated from the
schema (`php artisan model:show`, IDE helper generation) rather than hand-typed, for the same reason.

**Architecture Decision Records for schema-shape decisions.** Any decision that a future engineer
might reasonably question — "why is `ledger_entries` denormalized," "why advisory locks instead of
a `SELECT ... FOR UPDATE` for numbering," "why `SERIALIZABLE` only for control-account exposure
checks" — is captured as a short ADR in `docs/adr/` at the time the decision is made, linked from
the migration's PR description, not reconstructed from memory months later:

```markdown
# ADR-0014: Materialize ledger_entries instead of querying journal_lines directly

Status: Accepted — 2026-06-02
Context: Trial Balance and account statement endpoints must render in <200ms p95 for tenants
with 500k+ posted lines; a live join across journal_entries/journal_lines/accounts/fiscal_periods
exceeded that budget under EXPLAIN ANALYZE at 50k+ rows.
Decision: Materialize a flat, indexed projection (ledger_entries) written exclusively inside
JournalPostingService::post(), in the same transaction as the post.
Consequences: An extra write per journal line at post time (~5-10ms); reporting reads become
single-table index scans. journal_lines remains the sole source of truth; ledger_entries is
never the target of a manual correction — see DATABASE_STANDARDS.md "Denormalization."
```

**Migration files are self-documenting.** Every migration's class-level docblock states the intent
in one sentence, and any migration using a non-obvious technique (`NOT VALID`, `CONCURRENTLY`,
`lock_timeout`, an irreversible `down()`) comments the reason inline at the point of use, referencing
this document by name (`DATABASE_STANDARDS.md`) so a future reader who does not remember the
rationale can find the full reasoning quickly.

**Runbooks accompany any manual operational procedure.** Fiscal period closing, month-end
`inventory_valuations` snapshotting, and the annual archival of old `audit_logs` partitions are
documented as runbooks (exact commands, expected duration, rollback procedure) in
`docs/runbooks/`, reviewed and rehearsed on staging before the first production run of any new
runbook.

**Standard.** A table or column without a `COMMENT` describing its purpose and (where relevant) its
write-path ownership is not permitted past review. A schema decision that a reviewer had to ask "why
did you do it this way" about gets an ADR before the PR merges, not after.

# Review Checklist

Every pull request that adds or modifies a migration, a raw SQL query, or a query-building
Eloquent scope is checked against this list before approval. A reviewer who cannot answer "yes" to
every applicable item requests changes rather than approving with a comment to "fix later."

- [ ] Does every new table have `company_id NOT NULL REFERENCES companies(id)`, the full standard
      column set, and (if it holds business/financial data) `deleted_at` soft-delete support?
- [ ] Is `FORCE ROW LEVEL SECURITY` enabled with a tenant-isolation policy on every new table
      carrying `company_id`?
- [ ] Does every foreign key specify `ON DELETE` explicitly, and is `RESTRICT` used for anything
      referenced by financial records?
- [ ] Does every money column use `NUMERIC(19,4)`, every rate `NUMERIC(18,6)`, every quantity
      `NUMERIC(18,4)` — never `FLOAT`/`DOUBLE PRECISION`/`REAL`?
- [ ] Does every state/status column have a `CHECK` constraint enumerating valid values that
      matches the corresponding PHP enum exactly?
- [ ] Does every quantity or monetary column that cannot legally be negative have a `CHECK
      (... >= 0)` constraint?
- [ ] Is any new index justified by a named query/endpoint, and is `EXPLAIN (ANALYZE, BUFFERS)`
      attached showing the before/after plan?
- [ ] Is every `CREATE INDEX` on a populated table using `CONCURRENTLY`, with
      `$withinTransaction = false` set on the migration class?
- [ ] Is every new `CHECK`/`UNIQUE`/foreign-key constraint on an existing populated table added via
      `NOT VALID` + a follow-up `VALIDATE CONSTRAINT`?
- [ ] Does any read-then-write sequence within a transaction lock its reads (`FOR UPDATE`/
      `FOR SHARE`) where a concurrent writer could invalidate the calculation?
- [ ] Is `DB::transaction()` used with a retry count wherever `SERIALIZABLE` isolation or contended
      row locks are involved?
- [ ] Does every externally-triggered write path (webhook, AI-agent draft, retried API call) have
      an idempotency-key guard?
- [ ] Is any denormalized table or materialized view documented with its source of truth, refresh
      path, and staleness bound (in a `COMMENT ON TABLE` and the PR description)?
- [ ] Does the migration avoid holding a table-level exclusive lock for longer than ~2 seconds
      against a table that carries production write traffic (verified against the staging-clone
      dry run)?
- [ ] Does the migration have a working `down()`, or an explicit, reasoned "irreversible" docblock?
- [ ] Are all new constraints covered by a pgTAP test proving both the accept and reject path?
- [ ] Are all new tables/columns documented with `COMMENT ON TABLE`/`COMMENT ON COLUMN`?
- [ ] Is every raw SQL string free of interpolated user input (bound parameters only)?
- [ ] Does the query avoid `SELECT *` in favor of naming the columns actually needed, especially on
      wide tables like `journal_lines`/`invoices`?
- [ ] If the change touches lock acquisition across more than one table, does it follow the
      documented lock order (`accounts` → `journal_entries` → `journal_lines` →
      `inventory_items` → `bank_accounts`)?

# Anti-Patterns

The following patterns have caused real incidents in financial systems of QAYD's shape and are
explicitly banned. Each entry states the pattern, why it fails, and the sanctioned alternative.

**Storing money as `FLOAT`/`DOUBLE PRECISION`.** Binary floating point cannot represent most decimal
fractions exactly (`0.1 + 0.2 != 0.3` in IEEE 754), which means summed invoice totals silently drift
from the sum of their line items by fractions of a cent that compound over millions of transactions.
QAYD uses `NUMERIC(19,4)` exclusively for money, `NUMERIC(18,6)` for exchange rates, and never
performs money arithmetic in PHP `float`/`double` — PHP-side arithmetic on retrieved `NUMERIC`
values uses `bcmath`/`brick/money`, never native float operators.

```sql
-- WRONG
total_amount DOUBLE PRECISION;
-- RIGHT
total_amount NUMERIC(19,4) NOT NULL DEFAULT 0;
```

**Entity-Attribute-Value (EAV) tables for structured business data.** A generic
`entity_attributes (entity_type, entity_id, attr_key, attr_value)` table looks flexible but makes
every query a self-join, defeats foreign keys and `CHECK` constraints entirely, and turns type
safety into a runtime string-parsing problem. QAYD uses real typed columns for anything the business
logic reads structurally, and reserves JSONB `custom_fields` only for genuinely unstructured,
never-joined-on metadata (see "Normalization").

**Hard deletes on any table that ever represented a financial fact.** `DELETE FROM invoices WHERE
...` destroys the audit trail and can make a previously-correct Trial Balance unreproducible.
Every financial table is soft-deleted (`deleted_at`), and "deleting" a posted document is modeled
as a reversing/voiding journal entry (a compensating transaction), never a row removal.

**Business logic hidden in triggers that duplicates application logic.** A trigger that
recalculates `invoices.total_amount` on every `invoice_items` change, in parallel with a Laravel
service that also recalculates it, creates two sources of truth that can silently diverge (e.g., the
trigger runs a different rounding rule than the PHP code). QAYD's rule: **triggers are reserved for
constraint enforcement and denormalized-table synchronization only** (the balance-check trigger,
`updated_at` touch triggers) — they never compute or own primary business calculations that the
Laravel service layer is also responsible for.

**Giant, long-running transactions that batch unrelated work.** A monthly close job that opens one
transaction, loops over every customer, recalculates balances, and writes reports, all before a
single commit, holds locks and consumes `wraparound`-relevant transaction ID space for the entire
run, and turns any single failure deep into the loop into a full rollback of hours of computed work.
QAYD structures long batch jobs as many small, independently-committed transactions (one per
customer/document), each idempotent and safely re-runnable, coordinated by a job-status table rather
than a single transactional boundary.

**Unbounded `OFFSET` pagination on large tables.** Already covered under "Performance" — repeating
here because it recurs specifically as an anti-pattern reviewers must reject on sight: `OFFSET 50000
LIMIT 25` forces a full scan-and-discard of 50,000 rows on every request and gets linearly slower as
a tenant's data grows, eventually timing out. Keyset pagination is mandatory for any endpoint whose
result set is not bounded to a small, fixed size.

**Foreign keys without a supporting index.** PostgreSQL does not automatically index the referencing
column of a foreign key (unlike the referenced/primary-key side, which is always indexed). An
unindexed FK column makes every `DELETE`/`UPDATE` on the parent row scan the entire child table to
check for dependents, and makes every join on that relationship slow. Every foreign key column in
QAYD's schema has an explicit supporting index, verified by a CI query against
`information_schema` that flags any FK column with no matching index.

```sql
-- CI check: foreign key columns with no covering index
SELECT conrelid::regclass AS table_name, a.attname AS fk_column
FROM pg_constraint c
JOIN unnest(c.conkey) AS k(attnum) ON true
JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
WHERE c.contype = 'f'
AND NOT EXISTS (
    SELECT 1 FROM pg_index i
    WHERE i.indrelid = c.conrelid
    AND a.attnum = i.indkey[0]
);
```

**Trusting application-level validation alone for financial invariants.** A `FormRequest` rule that
checks "debits equal credits" is necessary for a fast, friendly user-facing error, but it is not
sufficient — a background job, a data-migration script, or a future code path can bypass it. Every
invariant enforced in application code that protects money or quantity correctness has a
corresponding database-level constraint or trigger, per "Constraints." Relying on the application
layer alone is treated as an incomplete implementation, not a stylistic choice.

**Silent truncation of numeric precision.** Casting a `NUMERIC(19,4)` value to a narrower type (a
PHP `int`, a JSON number handled by a client that parses it as IEEE double) anywhere in the request/
response pipeline loses precision invisibly. API responses serialize money as a string
(`"total_amount": "1250.5000"`), never a bare JSON number, specifically so that no JSON parser in any
client language can silently round it through double-precision float conversion.

**Ignoring `EXPLAIN` output that shows a sequential scan "because it's still fast today."** A
sequential scan on a 500-row table in a demo tenant looks identical in response time to an indexed
lookup; the same code path against a two-year-old, heavily-used tenant with 300,000 rows is a
different story. Every non-trivial query is evaluated against the "large tenant" benchmark defined
under "Performance," not against whatever data happens to exist in the reviewer's local database.

# End of Document

