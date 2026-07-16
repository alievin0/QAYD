# Database Constraints — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_CONSTRAINTS
---

# Purpose

This document specifies the complete constraint architecture for the QAYD PostgreSQL database layer:
every mechanism PostgreSQL 15+ offers for enforcing data integrity at the row, table, and
cross-table level, and the exact policy for when each mechanism is mandatory versus optional in
QAYD's schema. QAYD is an AI Financial Operating System — a multi-tenant Laravel 12 (PHP 8.4+)
backend over PostgreSQL, serving a double-entry accounting core plus sales, purchasing, banking,
inventory, payroll, and tax modules to Gulf-region companies. The database is the **system of
record for money**. Application code (Laravel FormRequests, Services, Repositories) validates
first and validates thoroughly, but the database is the last line of defense: no application bug,
race condition, direct SQL access, background job, or AI-generated write path may ever be able to
persist an unbalanced journal entry, a negative inventory count that violates policy, an orphaned
foreign key, a duplicate posted invoice number, or a corrupted fiscal calendar. This document gives
any backend engineer or AI code-generation agent the concrete, copy-pasteable SQL and Laravel
migration patterns needed to implement that guarantee across QAYD's canonical tables: `accounts`,
`account_types`, `fiscal_years`, `fiscal_periods`, `journal_entries`, `journal_lines`,
`ledger_entries`, `customers`, `vendors`, `invoices`, `invoice_items`, `bills`, `receipts`,
`bank_accounts`, `bank_transactions`, `inventory_items`, `stock_movements`, `companies`, and
`audit_logs`. It covers constraint types (primary key, foreign key, unique, check, not null,
default, exclusion, deferrable), cascade/restrict semantics, trigger-enforced invariants that
exceed declarative constraint expressiveness, the layered relationship between application-level
and database-level validation, and the migration discipline required to add or tighten constraints
on live, multi-tenant, always-on financial tables without downtime or data loss. Every example in
this document is written against the exact table names and column conventions defined in the
platform's shared design context (`company_id`, `branch_id`, `created_by`, `updated_by`,
`created_at`, `updated_at`, `deleted_at`, `NUMERIC(19,4)` money, `NUMERIC(18,6)` rates,
`NUMERIC(18,4)` quantities) so it can be lifted directly into Laravel migrations.

# Constraint Philosophy (DB is last line of defense)

QAYD treats constraints as a layered defense system with the database at the innermost, most
trusted layer. The layering, from outermost (most convenient, least trustworthy) to innermost
(least convenient, most trustworthy), is:

1. **Frontend validation** (Next.js/React, Zod schemas) — fast user feedback, zero trust. Never
   the only guard against bad data; purely a UX courtesy.
2. **Laravel FormRequest validation** — the first server-side gate. Validates shape, types,
   business-rule pre-checks (e.g. "credit note amount must not exceed remaining invoice balance").
   Runs before any Service or Repository code executes.
3. **Laravel Service layer business rules** — cross-entity checks that need multiple queries or
   external state (e.g. "this fiscal period is not closed", "this customer's credit limit is not
   exceeded"), plus orchestration of multi-table writes inside a DB transaction.
4. **PostgreSQL constraints** — the innermost, non-negotiable layer. This is the layer this
   document specifies. It is the only layer that:
   - Cannot be bypassed by a bug in Service-layer logic, a forgotten validation branch, a stale
     cache read, a manually-run SQL script, a psql session opened by an engineer during an
     incident, a queue worker retrying a job with a corrupted payload, a future microservice that
     writes to the same tables without going through Laravel, or an AI agent's generated code path
     that skipped a check.
   - Cannot be bypassed by concurrent execution. Application-level "check-then-write" logic
     (`SELECT balance; if balance >= amount then UPDATE`) is inherently racy under concurrent
     requests unless the database itself refuses the invalid end state. A `CHECK (balance >= 0)`
     constraint turns a race condition into a guaranteed `23514` error instead of a silently
     corrupted balance.
   - Is versioned, reviewed, and deployed with the same migration discipline as schema itself —
     constraints are not an afterthought bolted on after a production incident; they are written
     at table-creation time as part of the DDL.

The governing rule for QAYD: **if violating a rule would ever produce an incorrect financial
statement, an unbalanced ledger, or data corruption that a human could not safely reconstruct,
that rule must be enforced by a PostgreSQL constraint or trigger, not by application code alone.**
Application code may add friendlier error messages and earlier rejection, but it may never be the
*only* thing standing between a bug and a corrupted balance sheet. Conversely, constraints are not
a substitute for application-level validation: a `CHECK` constraint produces a raw `23514`
Postgres error with a machine-oriented message; it is the Laravel layer's job to catch that
exception and translate it into the standard API error envelope (`422` with a human-readable
message in `errors[]`). The two layers are complementary, not redundant: the app layer optimizes
for good UX and early rejection; the database layer optimizes for absolute correctness regardless
of code path.

# Primary Keys

Every table in QAYD has exactly one primary key, always a single-column surrogate key using
PostgreSQL's identity column syntax — never natural keys (invoice numbers, tax IDs, account codes)
as primary keys, because natural keys change, are reused across tenants, and complicate
foreign-key relationships. Natural/business keys are enforced separately as `UNIQUE` constraints
scoped by `company_id` (see the Unique section).

```sql
CREATE TABLE accounts (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    branch_id     BIGINT NULL REFERENCES branches(id),
    account_type_id BIGINT NOT NULL REFERENCES account_types(id),
    parent_id     BIGINT NULL REFERENCES accounts(id),
    code          VARCHAR(20) NOT NULL,
    name_en       VARCHAR(255) NOT NULL,
    name_ar       VARCHAR(255) NOT NULL,
    normal_balance CHAR(1) NOT NULL,          -- 'D' or 'C'
    is_control_account BOOLEAN NOT NULL DEFAULT false,
    status        VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL
);
```

`GENERATED ALWAYS AS IDENTITY` is preferred over `SERIAL`/`bigserial` because:

- It is SQL-standard syntax (Postgres 10+), whereas `SERIAL` is a Postgres-specific shorthand that
  creates a hidden sequence with weaker ownership semantics.
- `GENERATED ALWAYS` (as opposed to `GENERATED BY DEFAULT`) refuses application-supplied explicit
  `id` values on `INSERT` unless the statement uses `OVERRIDING SYSTEM VALUE`, which prevents an
  entire class of bugs where a Laravel Eloquent model accidentally sets `id` from untrusted input
  (e.g. a mass-assignment vulnerability or a bulk-import script echoing a source system's ID into
  the wrong column).
- Sequence ownership is automatic and travels with `pg_dump`/`pg_restore` and Laravel's own schema
  dumps without extra configuration.

`BIGINT` (not `INTEGER`) is mandatory on every primary key in QAYD. `journal_lines`,
`stock_movements`, and `audit_logs` are the highest-cardinality tables in the system and will
exceed the 2.1 billion row ceiling of a 4-byte `INTEGER` identity within the operating lifetime of
a single large tenant; retrofitting a primary key's underlying type after production traffic exists
is an extremely expensive, downtime-risking migration (it requires rewriting every row of the
table and every table with a foreign key referencing it), so QAYD standardizes on `BIGINT`
everywhere from day one, even for small lookup tables such as `account_types`, to keep every
foreign key in the system uniformly typed.

Composite primary keys are avoided on business tables (they complicate ORMs, foreign keys, and
partitioning) but are the correct choice on pure join/pivot tables that have no independent
identity, such as `company_users`:

```sql
CREATE TABLE company_users (
    company_id  BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id     BIGINT NOT NULL REFERENCES roles(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, user_id)
);
```

Even here, QAYD still prefers a surrogate `id` if the pivot ever needs to be referenced from
another table (e.g. `audit_logs` recording "which membership row changed") — a surrogate key keeps
every future foreign key single-column. As a rule: **use a composite primary key only when no other
table will ever need to point at a single row of this table by foreign key.**

# Foreign Keys

Every relationship between tenant-scoped business tables is enforced by an explicit foreign key —
QAYD never relies on "the application always sets this correctly" for referential integrity. All
foreign keys are indexed (Postgres does not auto-index the referencing column, only the referenced
column via its PK/unique constraint, so every `REFERENCES` column gets its own `CREATE INDEX`).

```sql
CREATE TABLE journal_lines (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT NULL REFERENCES branches(id),
    journal_entry_id  BIGINT NOT NULL REFERENCES journal_entries(id) ON DELETE RESTRICT,
    account_id        BIGINT NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    cost_center_id    BIGINT NULL REFERENCES cost_centers(id) ON DELETE SET NULL,
    project_id        BIGINT NULL REFERENCES projects(id) ON DELETE SET NULL,
    department_id     BIGINT NULL REFERENCES departments(id) ON DELETE SET NULL,
    line_no           SMALLINT NOT NULL,
    description       VARCHAR(500) NULL,
    debit_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code     CHAR(3) NOT NULL,
    exchange_rate     NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_amount_debit  NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_amount_credit NUMERIC(19,4) NOT NULL DEFAULT 0,
    created_by        BIGINT NULL REFERENCES users(id),
    updated_by        BIGINT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ NULL,
    CONSTRAINT fk_journal_lines_currency
        FOREIGN KEY (currency_code) REFERENCES currencies(code)
);

CREATE INDEX idx_journal_lines_company_id       ON journal_lines (company_id);
CREATE INDEX idx_journal_lines_journal_entry_id  ON journal_lines (journal_entry_id);
CREATE INDEX idx_journal_lines_account_id        ON journal_lines (account_id);
CREATE INDEX idx_journal_lines_cost_center_id    ON journal_lines (cost_center_id) WHERE cost_center_id IS NOT NULL;
CREATE INDEX idx_journal_lines_project_id        ON journal_lines (project_id) WHERE project_id IS NOT NULL;
```

Every foreign key in QAYD explicitly names its `ON DELETE` (and, where relevant, `ON UPDATE`)
action rather than accepting Postgres's default of `NO ACTION`. `NO ACTION` and `RESTRICT` behave
almost identically for QAYD's purposes (both block the delete), but QAYD writes `RESTRICT`
explicitly wherever the intent is "this must never be deletable while children exist" so the
migration is self-documenting; `NO ACTION` is used only where a deferred check is intentionally
desired (see Deferrable Constraints).

**Cross-tenant foreign-key safety.** A foreign key alone does not guarantee that the referenced row
belongs to the *same* company — `journal_lines.account_id` referencing `accounts.id` only proves
the account exists somewhere in the database, not that it belongs to the same `company_id` as the
journal line. QAYD closes this gap two ways:

1. Every write path goes through the Laravel Repository layer, which always adds
   `->where('company_id', $activeCompanyId)` to the query that loads the referenced row before
   using its ID, so a cross-tenant ID can never be looked up successfully in the first place.
2. As a hard database-level backstop, QAYD uses a composite foreign key that includes `company_id`
   on both sides wherever the target table is itself company-scoped, which makes cross-tenant
   linkage a `23503` foreign-key violation rather than a silent bug:

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT fk_journal_lines_account_same_company
    FOREIGN KEY (account_id, company_id) REFERENCES accounts (id, company_id);
```

This requires `accounts` to expose a matching unique constraint on `(id, company_id)` (trivially
true, since `id` alone is already unique — Postgres allows a foreign key to reference any unique
or primary-key constraint, not only the primary key itself):

```sql
ALTER TABLE accounts ADD CONSTRAINT uq_accounts_id_company UNIQUE (id, company_id);
```

QAYD applies this composite-FK-with-tenant-column pattern to every foreign key from a child table
into `accounts`, `customers`, `vendors`, `products`, `warehouses`, `bank_accounts`, `cost_centers`,
`projects`, and `fiscal_periods` — the tables most likely to be referenced from a write path where
a stale or manipulated ID could otherwise cross a tenant boundary.

Foreign keys to immutable reference/lookup tables that are not company-scoped (`account_types`,
`currencies`, `roles`, `permissions`) use a plain single-column FK, since there is no tenant
dimension to cross.

# Unique

Uniqueness in QAYD is almost always **scoped by `company_id`** (and sometimes `branch_id`), because
business keys like invoice numbers, account codes, and SKUs are meaningful only within one
tenant's numbering sequence, not globally across the whole database.

```sql
-- Account codes are unique per company, not globally.
ALTER TABLE accounts
    ADD CONSTRAINT uq_accounts_company_code UNIQUE (company_id, code);

-- Posted invoice numbers must be unique per company (soft-deleted rows excluded — see below).
CREATE UNIQUE INDEX uq_invoices_company_invoice_number
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;

-- A customer's tax registration number, if present, must not be reused within the same company.
CREATE UNIQUE INDEX uq_customers_company_tax_number
    ON customers (company_id, tax_number)
    WHERE deleted_at IS NULL AND tax_number IS NOT NULL;

-- A bank account's IBAN must be unique per company (two companies may legitimately share
-- ownership of the same physical bank account in group-consolidation scenarios, so uniqueness
-- is NOT global).
CREATE UNIQUE INDEX uq_bank_accounts_company_iban
    ON bank_accounts (company_id, iban)
    WHERE deleted_at IS NULL;
```

**Partial unique indexes with soft delete** are the standard QAYD pattern, not a plain table-level
`UNIQUE` constraint, because QAYD never hard-deletes financial rows. A naive
`UNIQUE (company_id, invoice_number)` table constraint would permanently block reissuing a
cancelled invoice's number, because the cancelled row's `deleted_at` is set but the row physically
remains. The `WHERE deleted_at IS NULL` partial index scopes uniqueness to only the "live" rows,
so:

- Voiding invoice `INV-2026-00042` (setting `deleted_at`) frees up `INV-2026-00042` to be reused by
  a new invoice, while the original voided row remains in the table forever for audit purposes.
- Two live invoices can never collide on the same number within a company.
- Two *soft-deleted* invoices with the same number can coexist (the partial index simply does not
  index them), which is correct: history is allowed to contain the same number twice if it was
  legitimately voided and reissued.

QAYD's rule: **any `UNIQUE` constraint on a table that has a `deleted_at` column must be
implemented as a partial unique index with `WHERE deleted_at IS NULL`, never as a plain table-level
`UNIQUE` constraint.** Plain `UNIQUE` constraints are reserved for tables that are never
soft-deleted (pure lookup/reference tables such as `currencies.code`, `account_types.code`).

Multi-column uniqueness combining a nullable column also uses a partial or expression index
carefully, because Postgres treats `NULL` as distinct from every other `NULL` in a standard unique
index (two rows with `NULL` in the unique column never conflict) — which is usually exactly what
QAYD wants (e.g. many customers legitimately have no `tax_number`), so the `AND tax_number IS NOT
NULL` clause above is not strictly required for correctness but is kept for index size and
documentation clarity.

Example: enforcing a single default price list per company:

```sql
CREATE UNIQUE INDEX uq_price_lists_company_default
    ON price_lists (company_id)
    WHERE is_default = true AND deleted_at IS NULL;
```

This is the canonical Postgres idiom for "at most one row per group may have flag X true" — an
invariant that a plain `UNIQUE` constraint cannot express at all, but a partial unique index
expresses in one line.

# Check

`CHECK` constraints encode row-local business invariants — rules that can be verified by looking
only at the columns of the row being inserted or updated, with no need to query other rows or other
tables. QAYD uses `CHECK` aggressively because it is the cheapest, most self-documenting integrity
mechanism available and it fails fast (`23514`) before a trigger or an application round-trip is
even needed.

```sql
CREATE TABLE invoices (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    customer_id     BIGINT NOT NULL REFERENCES customers(id),
    invoice_number  VARCHAR(30) NOT NULL,
    type            VARCHAR(20) NOT NULL DEFAULT 'sales',
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    issue_date      DATE NOT NULL,
    due_date        DATE NOT NULL,
    currency_code   CHAR(3) NOT NULL REFERENCES currencies(code),
    exchange_rate   NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    discount_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount    NUMERIC(19,4) NOT NULL DEFAULT 0,
    paid_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,

    CONSTRAINT chk_invoices_amounts_nonnegative CHECK (
        subtotal_amount >= 0 AND tax_amount >= 0 AND discount_amount >= 0
        AND total_amount >= 0 AND paid_amount >= 0
    ),
    CONSTRAINT chk_invoices_paid_not_exceed_total CHECK (paid_amount <= total_amount),
    CONSTRAINT chk_invoices_due_after_issue CHECK (due_date >= issue_date),
    CONSTRAINT chk_invoices_exchange_rate_positive CHECK (exchange_rate > 0),
    CONSTRAINT chk_invoices_currency_format CHECK (currency_code ~ '^[A-Z]{3}$'),
    CONSTRAINT chk_invoices_type CHECK (type IN ('sales', 'proforma', 'recurring')),
    CONSTRAINT chk_invoices_status CHECK (
        status IN ('draft', 'sent', 'partially_paid', 'paid', 'overdue', 'void')
    ),
    CONSTRAINT chk_invoices_total_matches_components CHECK (
        total_amount = subtotal_amount + tax_amount - discount_amount
    )
);
```

`CHECK (amount >= 0)` is applied to **every** money and quantity column in the schema where the
domain never allows a negative value (subtotals, tax, invoice totals, paid amounts, inventory
`quantity_on_hand` where the company's policy forbids negative stock, product prices, exchange
rates). It is not applied to columns where negative values are meaningful, such as
`journal_lines.debit_amount`/`credit_amount` individually (each is separately constrained to
`>= 0`, but the *signed* net effect of a reversing entry is expressed by posting an equal-and-
opposite line, never by a negative debit), or `stock_movements.quantity_delta` (deliberately signed:
positive for receipts, negative for issues).

```sql
CREATE TABLE stock_movements (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    warehouse_id    BIGINT NOT NULL REFERENCES warehouses(id),
    product_id      BIGINT NOT NULL REFERENCES products(id),
    movement_type   VARCHAR(20) NOT NULL,
    quantity_delta  NUMERIC(18,4) NOT NULL,
    unit_cost       NUMERIC(19,4) NOT NULL DEFAULT 0,
    reference_type  VARCHAR(50) NULL,
    reference_id    BIGINT NULL,
    created_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_stock_movements_type CHECK (
        movement_type IN ('receipt', 'issue', 'adjustment_in', 'adjustment_out',
                           'transfer_in', 'transfer_out')
    ),
    CONSTRAINT chk_stock_movements_delta_nonzero CHECK (quantity_delta <> 0),
    CONSTRAINT chk_stock_movements_unit_cost_nonnegative CHECK (unit_cost >= 0),
    CONSTRAINT chk_stock_movements_direction_matches_type CHECK (
        (movement_type IN ('receipt', 'adjustment_in', 'transfer_in') AND quantity_delta > 0)
        OR
        (movement_type IN ('issue', 'adjustment_out', 'transfer_out') AND quantity_delta < 0)
    )
);
```

**The debit-XOR-credit rule on `journal_lines`.** Double-entry accounting requires every journal
line to be either a debit line or a credit line — never both, and never neither. This is enforced
with a `CHECK` constraint that expresses exclusive-or arithmetically, which is both the clearest and
the fastest form (no boolean casting, no `CASE`):

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT chk_journal_lines_debit_xor_credit CHECK (
        (debit_amount > 0 AND credit_amount = 0)
        OR
        (credit_amount > 0 AND debit_amount = 0)
    ),
    ADD CONSTRAINT chk_journal_lines_amounts_nonnegative CHECK (
        debit_amount >= 0 AND credit_amount >= 0
    ),
    ADD CONSTRAINT chk_journal_lines_exchange_rate_positive CHECK (exchange_rate > 0),
    ADD CONSTRAINT chk_journal_lines_currency_format CHECK (currency_code ~ '^[A-Z]{3}$');
```

Equivalently, and slightly more compactly using boolean XOR:

```sql
CONSTRAINT chk_journal_lines_debit_xor_credit CHECK (
    (debit_amount > 0) IS DISTINCT FROM (credit_amount > 0)
)
```

QAYD prefers the explicit `(A AND NOT B) OR (B AND NOT A)`-style formulation over `IS DISTINCT
FROM` in production DDL because it is unambiguous to every engineer reading it (including AI code
generators), even though both compile to equivalent query plans; `IS DISTINCT FROM` is acceptable
in ad-hoc analytical SQL but avoided in constraint definitions for readability.

**`currency_code` validation.** Every currency-code column in the schema is validated at two levels
simultaneously: a foreign key into a `currencies` reference table (which is the authoritative list
of ISO 4217 codes QAYD supports commercially — initially KWD, AED, SAR, USD, with room to add more)
and a regex `CHECK` guaranteeing the *shape* is always three uppercase letters even before the
foreign key is checked (this protects against a currency code that technically doesn't exist in
`currencies` yet due to replication lag or a race in a multi-step onboarding flow, while still
catching obviously malformed input like lowercase or wrong-length strings immediately):

```sql
CREATE TABLE currencies (
    code        CHAR(3) PRIMARY KEY CHECK (code ~ '^[A-Z]{3}$'),
    name_en     VARCHAR(50) NOT NULL,
    name_ar     VARCHAR(50) NOT NULL,
    decimal_places SMALLINT NOT NULL DEFAULT 2 CHECK (decimal_places BETWEEN 0 AND 4),
    is_active   BOOLEAN NOT NULL DEFAULT true
);

ALTER TABLE bank_accounts
    ADD CONSTRAINT chk_bank_accounts_currency_format CHECK (currency_code ~ '^[A-Z]{3}$'),
    ADD CONSTRAINT fk_bank_accounts_currency FOREIGN KEY (currency_code) REFERENCES currencies(code);
```

`CHECK` constraints referencing only immutable literals (regexes, fixed value lists, arithmetic
between sibling columns) are always marked implicitly `IMMUTABLE`-safe by Postgres and cost
essentially nothing per row; QAYD does not shy away from adding many small `CHECK` constraints per
table because their combined overhead is negligible next to the cost of a corrupted financial
record.

# Not Null

QAYD's default posture is **`NOT NULL` unless there is a specific, named business reason for a
column to be optional.** Every column is reviewed at migration-authoring time with the question
"can this legitimately be unknown for a live row, forever, and does the business logic have a
defined meaning for that unknown state?" If the answer is no, the column is `NOT NULL`, with a
sensible `DEFAULT` if a value is not always supplied by the caller.

Columns that are always `NOT NULL` in QAYD, without exception, on every business table:
`id`, `company_id`, `created_at`, `updated_at`, and every column that participates in a `CHECK` or
uniqueness constraint whose semantics assume a real value is present (e.g. `debit_amount` /
`credit_amount` on `journal_lines` — a `NULL` there would make the debit-XOR-credit `CHECK`
evaluate to `NULL` on comparison, which Postgres treats as "constraint satisfied," silently
defeating the constraint; this is exactly why `debit_amount`/`credit_amount` also carry `DEFAULT 0`
and `NOT NULL` together, not just a `CHECK`).

Columns that are legitimately nullable, with the specific reason documented in-line:

```sql
CREATE TABLE journal_entries (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),          -- nullable: HQ-level entries have no branch
    fiscal_period_id BIGINT NOT NULL REFERENCES fiscal_periods(id),
    entry_number    VARCHAR(30) NOT NULL,
    entry_date      DATE NOT NULL,
    source_type     VARCHAR(50) NOT NULL DEFAULT 'manual',
    source_id       BIGINT NULL,                                   -- nullable: manual entries have no source document
    description     VARCHAR(500) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    posted_at       TIMESTAMPTZ NULL,                               -- nullable: unset until status transitions to 'posted'
    posted_by       BIGINT NULL REFERENCES users(id),               -- nullable: unset until posted
    reversed_entry_id BIGINT NULL REFERENCES journal_entries(id),   -- nullable: only set on reversing entries
    voided_at       TIMESTAMPTZ NULL,                               -- nullable: unset unless voided
    voided_by       BIGINT NULL REFERENCES users(id),
    void_reason     VARCHAR(500) NULL,
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,

    CONSTRAINT chk_journal_entries_status CHECK (
        status IN ('draft', 'posted', 'reversed', 'voided')
    ),
    -- A posted or reversed/voided entry MUST have posted_at/posted_by populated.
    CONSTRAINT chk_journal_entries_posted_fields CHECK (
        (status = 'draft' AND posted_at IS NULL AND posted_by IS NULL)
        OR
        (status IN ('posted', 'reversed', 'voided') AND posted_at IS NOT NULL AND posted_by IS NOT NULL)
    ),
    CONSTRAINT chk_journal_entries_void_fields CHECK (
        (status <> 'voided' AND voided_at IS NULL AND voided_by IS NULL)
        OR
        (status = 'voided' AND voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)
    )
);
```

This pattern — a nullable "state-dependent" column paired with a `CHECK` that ties its
nullability to a status enum — is used throughout QAYD wherever a lifecycle produces
fields that only make sense after a transition (`invoices.paid_amount` reaching `total_amount`
alongside `status = 'paid'`, `bills.approved_by`/`approved_at`, `payroll_runs.finalized_at`).
It gives the database the power to reject an impossible state (`status = 'posted'` with no
`posted_by`) that a missing `NOT NULL` alone could never express, because a plain `NOT NULL` on
`posted_by` would incorrectly forbid *draft* entries from ever being created.

`NOT NULL` is preferred over an equivalent `CHECK (column IS NOT NULL)` wherever a plain
column-level nullability rule (with no conditional logic) is all that's needed, because
`NOT NULL` constraints are stored as a fast boolean flag in the column's `pg_attribute` metadata,
checked before any `CHECK` expression is even evaluated, and they are what Postgres's query
planner uses to prove a column can never be `NULL` for join-elimination and other optimizations —
a `CHECK` constraint expressing the same rule does not give the planner the same guarantee.

# Default Values

QAYD assigns explicit `DEFAULT` values to remove ambiguity about what "not specified" means and to
keep every existing row valid after a later `ALTER TABLE ... ADD COLUMN`. The default value is
always chosen to be the safest, most conservative business state, never a convenience value that
could mask a missing input:

```sql
ALTER TABLE invoices ALTER COLUMN status SET DEFAULT 'draft';        -- never defaults to 'sent' or 'paid'
ALTER TABLE journal_entries ALTER COLUMN status SET DEFAULT 'draft'; -- never auto-posts
ALTER TABLE accounts ALTER COLUMN is_control_account SET DEFAULT false;
ALTER TABLE accounts ALTER COLUMN status SET DEFAULT 'active';
ALTER TABLE bills ALTER COLUMN paid_amount SET DEFAULT 0;
ALTER TABLE products ALTER COLUMN tags SET DEFAULT '[]'::jsonb;
ALTER TABLE products ALTER COLUMN custom_fields SET DEFAULT '{}'::jsonb;
```

`created_at`/`updated_at` default to `now()`, which is the transaction start timestamp
(`STATEMENT_TIMESTAMP`-equivalent semantics via `now()` which actually returns `CURRENT_TIMESTAMP`,
i.e. the transaction's start time — every statement in the same transaction sees the same `now()`
value, which is what QAYD wants for consistent multi-row inserts within one business transaction).
`updated_at` also gets a trigger (see Trigger-Enforced Invariants) to bump it on every `UPDATE`,
since a column `DEFAULT` only fires on `INSERT`.

`exchange_rate` defaults to `1`, which is correct and safe specifically because it is only ever
used when `currency_code` equals the company's base currency — for any foreign-currency
transaction, the application layer is required to supply an explicit rate, and a `CHECK
(exchange_rate > 0)` constraint (see Check) guards against a stray `0` ever being defaulted in by
a bug, which would otherwise zero out `base_amount_debit`/`base_amount_credit` and silently
corrupt the ledger.

Money columns default to `0`, never `NULL`, specifically because arithmetic against `NULL` in
Postgres propagates `NULL` (`5 + NULL = NULL`), which would silently corrupt every downstream
`SUM()` in a trial balance or invoice total unless every single query in the codebase remembered
to wrap the column in `COALESCE`. Defaulting to `0` and enforcing `NOT NULL` together removes this
entire class of bug at the schema level — no `COALESCE` is ever required when reading a QAYD money
column.

JSONB columns (`tags`, `custom_fields`) default to an empty JSON container (`'[]'::jsonb` or
`'{}'::jsonb` as appropriate) rather than `NULL`, so application code can always call
`jsonb_array_length()` / key-lookup operators without a null-check branch.

`GENERATED ... AS IDENTITY` columns (see Primary Keys) are a special category of default: the
value is not a literal but a sequence-derived generated value, and QAYD always uses `GENERATED
ALWAYS` rather than `GENERATED BY DEFAULT` for primary keys specifically so that no default can be
silently overridden by application-supplied input (see Primary Keys for the security rationale).

# Cascade

`ON DELETE CASCADE` in QAYD is reserved for **compositional** relationships — where the child row
has no independent meaning or lifecycle apart from its parent, and destroying the parent should
destroy the child too. It is never used on a relationship where the child is itself a financial
record with its own audit trail requirement.

```sql
-- invoice_items belong entirely to their invoice; they have no independent existence.
CREATE TABLE invoice_items (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    invoice_id    BIGINT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    product_id    BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    line_no       SMALLINT NOT NULL,
    description   VARCHAR(500) NULL,
    quantity      NUMERIC(18,4) NOT NULL,
    unit_price    NUMERIC(19,4) NOT NULL,
    tax_rate_id   BIGINT NULL REFERENCES tax_rates(id) ON DELETE SET NULL,
    line_total    NUMERIC(19,4) NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_invoice_items_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_invoice_items_unit_price_nonnegative CHECK (unit_price >= 0),
    CONSTRAINT chk_invoice_items_line_total_nonnegative CHECK (line_total >= 0),
    CONSTRAINT uq_invoice_items_invoice_line UNIQUE (invoice_id, line_no)
);
```

Note carefully: `invoice_items.invoice_id` cascades, but `invoice_items.product_id` does **not** —
deleting the parent `invoice` legitimately deletes its line items (they are compositional parts of
the invoice, and in practice this path is rarely even hit because `invoices` itself is
soft-deleted, not hard-deleted — see Restrict), but deleting a `product` must never silently delete
historical invoice line items that reference it; that relationship uses `RESTRICT`.

Other QAYD `ON DELETE CASCADE` relationships, all compositional in the same sense:

```sql
ALTER TABLE company_users     -- membership rows die with the company
    ADD CONSTRAINT fk_company_users_company FOREIGN KEY (company_id)
        REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE stock_count_lines -- count lines belong entirely to their stock count
    ADD CONSTRAINT fk_stock_count_lines_count FOREIGN KEY (stock_count_id)
        REFERENCES stock_counts(id) ON DELETE CASCADE;

ALTER TABLE rfq_responses     -- a vendor's RFQ response lines die with the response header
    ADD CONSTRAINT fk_rfq_response_items_response FOREIGN KEY (rfq_response_id)
        REFERENCES rfq_responses(id) ON DELETE CASCADE;

ALTER TABLE price_list_items  -- price list line items belong entirely to their price list
    ADD CONSTRAINT fk_price_list_items_list FOREIGN KEY (price_list_id)
        REFERENCES price_lists(id) ON DELETE CASCADE;
```

QAYD's rule of thumb for `CASCADE`: **ask "if I hard-delete the parent, is the child a meaningful
standalone record that anyone would ever want to query in isolation, for audit or reporting
purposes, after the parent is gone?" If yes, do not cascade — use `RESTRICT` (and, in practice,
prevent the hard delete from happening at all via soft deletes). If no — the child is purely a
detail row of the parent with zero independent reporting value — `CASCADE` is correct and saves the
application from having to manually clean up orphaned detail rows.**

`ON UPDATE CASCADE` is used far less often in QAYD because primary keys are immutable surrogate
identities that are never updated in place. The one legitimate use is on foreign keys that
reference a natural key that *can* change under controlled conditions:

```sql
ALTER TABLE bank_accounts
    ADD CONSTRAINT fk_bank_accounts_currency FOREIGN KEY (currency_code)
        REFERENCES currencies(code) ON UPDATE CASCADE ON DELETE RESTRICT;
```

If a `currencies.code` value were ever corrected (a data-entry fix, extremely rare), `ON UPDATE
CASCADE` propagates that fix everywhere it's referenced instead of breaking every referencing row.

# Restrict

`RESTRICT` (or the equivalent default `NO ACTION` when checked immediately) is QAYD's default
choice for every foreign key that points at a table which is itself a durable business/master-data
record: `accounts`, `customers`, `vendors`, `products`, `warehouses`, `users`, `fiscal_periods`,
`tax_rates`, `cost_centers`, `projects`, `bank_accounts`. The intent is that these records can
never be hard-deleted while any transaction still references them — which, combined with QAYD's
system-wide soft-delete policy, means in practice **a `RESTRICT`-guarded parent row is only ever
soft-deleted, never actually removed from the table, so the `RESTRICT` action is a defense against
a bug or an ad-hoc script attempting a real `DELETE`, not something the application ever triggers
in the happy path.**

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT fk_journal_lines_account FOREIGN KEY (account_id)
        REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE invoices
    ADD CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE RESTRICT;

ALTER TABLE bills
    ADD CONSTRAINT fk_bills_vendor FOREIGN KEY (vendor_id)
        REFERENCES vendors(id) ON DELETE RESTRICT;

ALTER TABLE journal_entries
    ADD CONSTRAINT fk_journal_entries_fiscal_period FOREIGN KEY (fiscal_period_id)
        REFERENCES fiscal_periods(id) ON DELETE RESTRICT;
```

An attempt to hard-delete an `accounts` row that has even one `journal_lines` row pointing at it —
regardless of how old, regardless of whether the journal line's own row is soft-deleted — raises
Postgres error `23503` ("update or delete on table \"accounts\" violates foreign key constraint"),
which Laravel's exception handler translates into a `409 Conflict` response with a message such as
"This account cannot be deleted because it has posted transaction history." This is the correct UX:
the *application* offers "deactivate" (a soft, reversible `status = 'inactive'` update) as the
supported action for master data with history, and reserves true hard `DELETE` only for rows that
were created in error and never used — in which case `RESTRICT` simply never fires because no
child rows exist yet.

`RESTRICT` is also QAYD's choice for the two "which came first" self-referencing hierarchies in the
schema, to prevent orphaning a subtree:

```sql
ALTER TABLE accounts
    ADD CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id)
        REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE cost_centers
    ADD CONSTRAINT fk_cost_centers_parent FOREIGN KEY (parent_id)
        REFERENCES cost_centers(id) ON DELETE RESTRICT;
```

Deleting a parent account node while child accounts still point to it via `parent_id` is blocked;
the caller must first re-parent or delete the children, which forces a deliberate, ordered cleanup
rather than an accidental subtree orphan.

`ON DELETE SET NULL` is QAYD's third option, reserved specifically for **optional dimensional
tags** where losing the association on delete is acceptable and even desirable, because the parent
row itself remains fully valid and meaningful without it:

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT fk_journal_lines_cost_center FOREIGN KEY (cost_center_id)
        REFERENCES cost_centers(id) ON DELETE SET NULL;

ALTER TABLE journal_lines
    ADD CONSTRAINT fk_journal_lines_project FOREIGN KEY (project_id)
        REFERENCES projects(id) ON DELETE SET NULL;
```

`cost_center_id`/`project_id` columns must therefore be nullable (see Not Null) specifically to
support this action — `SET NULL` can never be used on a `NOT NULL` column; Postgres will reject the
constraint definition outright if the column disallows nulls. This is exactly why `journal_lines`
declares those dimension columns nullable while every monetary and identity column on the same
table is `NOT NULL`: the nullability of each column is a deliberate decision tied to its cascade
behavior, not an oversight.

# Deferrable Constraints (balanced journal entry at COMMIT)

By default, every Postgres constraint is checked **immediately** — at the end of the statement
that violates it, before the statement returns. This is the correct default for almost every
constraint in the schema, but it creates a specific problem for `journal_lines`: a balanced journal
entry is a *transaction-level* invariant (`SUM(debit_amount) = SUM(credit_amount)` across all lines
of one `journal_entry_id`), not a row-level invariant that any single `INSERT` can satisfy on its
own. If QAYD inserted a two-line journal entry as two separate `INSERT` statements inside one
database transaction, the balance is momentarily violated between the first and second `INSERT` —
and if that invariant were checked immediately (statement-by-statement), the first `INSERT` alone
would always fail, because a single unbalanced line is never valid in isolation.

Postgres solves this with `DEFERRABLE` constraints: a constraint declared `DEFERRABLE INITIALLY
DEFERRED` is checked once, at `COMMIT` time (or at `SET CONSTRAINTS ... IMMEDIATE`/an explicit
savepoint boundary), after every statement in the transaction has run — which is exactly the
semantics QAYD needs for the balanced-entry rule. Because the balance check spans multiple rows,
it cannot be expressed as a column-level `CHECK` (`CHECK` constraints are inherently row-local in
Postgres); QAYD implements it as a **deferrable constraint trigger**, which is the mechanism
Postgres provides for exactly this class of "aggregate invariant checked at commit" rule:

```sql
CREATE OR REPLACE FUNCTION fn_check_journal_entry_balanced() RETURNS trigger AS $$
DECLARE
    v_journal_entry_id BIGINT;
    v_total_debit  NUMERIC(19,4);
    v_total_credit NUMERIC(19,4);
    v_status VARCHAR(20);
BEGIN
    v_journal_entry_id := COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);

    SELECT status INTO v_status FROM journal_entries WHERE id = v_journal_entry_id;
    -- Only posted (or transitioning-to-posted) entries must balance; drafts may be incomplete.
    IF v_status = 'draft' THEN
        RETURN NULL;
    END IF;

    SELECT COALESCE(SUM(debit_amount), 0), COALESCE(SUM(credit_amount), 0)
      INTO v_total_debit, v_total_credit
      FROM journal_lines
     WHERE journal_entry_id = v_journal_entry_id
       AND deleted_at IS NULL;

    IF v_total_debit <> v_total_credit THEN
        RAISE EXCEPTION
            'Journal entry % is not balanced: total debit % <> total credit %',
            v_journal_entry_id, v_total_debit, v_total_credit
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER trg_journal_entry_balanced
    AFTER INSERT OR UPDATE OR DELETE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION fn_check_journal_entry_balanced();
```

`CREATE CONSTRAINT TRIGGER` (as opposed to a plain `CREATE TRIGGER`) is what makes this
deferrable: it participates in the same `DEFERRABLE`/`INITIALLY DEFERRED`/`SET CONSTRAINTS`
machinery as a native foreign key or unique constraint, and Postgres guarantees it runs exactly
once at commit (or at an explicit `SET CONSTRAINTS trg_journal_entry_balanced IMMEDIATE` checkpoint)
regardless of how many intermediate `INSERT`/`UPDATE`/`DELETE` statements touched
`journal_lines` for that entry within the transaction. The Laravel Service layer that posts a
journal entry therefore writes code shaped like:

```php
DB::transaction(function () use ($entryData, $lines) {
    $entry = JournalEntry::create([...$entryData, 'status' => 'posted', 'posted_at' => now()]);
    foreach ($lines as $line) {
        JournalLine::create([...$line, 'journal_entry_id' => $entry->id]);
    }
    // No explicit balance check needed here — if the lines are unbalanced, COMMIT (implicit
    // at the end of the transaction() closure) raises a check_violation and the whole
    // transaction rolls back atomically.
});
```

This is strictly superior to an application-level `if (array_sum($debits) !== array_sum($credits))
throw ...` pre-check, because the deferred constraint trigger fires unconditionally regardless of
which code path produced the rows — a reversing-entry generator, a bulk CSV import job, a future AI
posting agent, or a manual `psql` fix during an incident are all subject to the same guarantee with
zero chance of a forgotten check.

Deferrable uniqueness constraints follow the identical pattern for a different, common accounting
problem: **re-sequencing** a set of rows that share a unique column, such as re-ordering
`invoice_items.line_no` for one invoice. A naive `UPDATE` that swaps `line_no` values one row at a
time will collide with the still-immediate unique constraint mid-transaction even though the final
state is valid. QAYD declares the affected uniqueness constraint deferrable specifically to allow
this:

```sql
ALTER TABLE invoice_items
    DROP CONSTRAINT uq_invoice_items_invoice_line,
    ADD CONSTRAINT uq_invoice_items_invoice_line
        UNIQUE (invoice_id, line_no) DEFERRABLE INITIALLY DEFERRED;
```

With this in place, a Service method that reorders line items in one transaction (swap line 3 and
line 5, for instance) succeeds, because Postgres only validates the constraint once, at commit,
against the final row values — exactly mirroring the balanced-entry pattern above, just using a
native `UNIQUE` constraint's own built-in deferrability instead of a constraint trigger.

# Exclusion Constraints (non-overlapping fiscal periods via GiST)

QAYD's fiscal calendar requires that a company's `fiscal_periods` never overlap in time — every
day of the fiscal year belongs to exactly one period (a month, typically), and adjacent periods
must be contiguous with no gaps and no overlaps, scoped per `company_id` (each tenant runs its own
independent fiscal calendar). This is a fundamentally different shape of constraint than anything
covered so far: it is not "is this single value unique," it's "does this new row's date range
intersect any existing row's date range for the same company" — a range-overlap predicate that a
plain `UNIQUE` index cannot express at all (uniqueness only compares for exact equality, not
overlap). Postgres's `EXCLUDE` constraint, built on a GiST (Generalized Search Tree) index, is
purpose-built for exactly this:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE fiscal_periods (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id) ON DELETE RESTRICT,
    period_no      SMALLINT NOT NULL,
    name_en        VARCHAR(50) NOT NULL,
    name_ar        VARCHAR(50) NOT NULL,
    start_date     DATE NOT NULL,
    end_date       DATE NOT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'open',
    closed_at      TIMESTAMPTZ NULL,
    closed_by      BIGINT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_fiscal_periods_dates_valid CHECK (end_date >= start_date),
    CONSTRAINT chk_fiscal_periods_status CHECK (status IN ('open', 'closed', 'locked')),
    CONSTRAINT chk_fiscal_periods_closed_fields CHECK (
        (status = 'open' AND closed_at IS NULL AND closed_by IS NULL)
        OR
        (status IN ('closed', 'locked') AND closed_at IS NOT NULL AND closed_by IS NOT NULL)
    ),
    CONSTRAINT uq_fiscal_periods_company_year_no UNIQUE (company_id, fiscal_year_id, period_no),

    -- No two periods for the SAME company may cover overlapping date ranges.
    CONSTRAINT excl_fiscal_periods_no_overlap EXCLUDE USING GIST (
        company_id WITH =,
        daterange(start_date, end_date, '[]') WITH &&
    )
);

CREATE INDEX idx_fiscal_periods_company_id ON fiscal_periods (company_id);
CREATE INDEX idx_fiscal_periods_fiscal_year_id ON fiscal_periods (fiscal_year_id);
```

Reading the `EXCLUDE` clause: it says "reject any new or updated row where, for some existing row,
`company_id` is equal (`WITH =`) **and** the two rows' `daterange(start_date, end_date, '[]')`
values overlap (`WITH &&`, the range-overlap operator)." The `btree_gist` extension is required
because `=` on a plain scalar (`company_id`) is not natively indexable by GiST on its own — the
extension supplies GiST operator-class support for ordinary B-tree-indexable types so they can be
combined with range operators inside a single multi-column `EXCLUDE ... USING GIST` index. The
`'[]'` bound-inclusivity flag on `daterange` makes both `start_date` and `end_date` inclusive
endpoints, matching the business rule that a period runs *through* its end date, not up to but
excluding it; this also means two periods where one's `end_date` equals the next one's `start_date`
**would** be flagged as overlapping (a single shared day counts as overlap), which is intentional —
QAYD's fiscal periods must be back-to-back with the next period starting the day *after* the
previous one ends (e.g. period 1 ends `2026-01-31`, period 2 starts `2026-02-01`), never on the
same day, and the exclusion constraint itself is what enforces that adjacency rule with zero extra
application code.

The identical technique protects `fiscal_years` from overlapping within a company:

```sql
ALTER TABLE fiscal_years
    ADD CONSTRAINT excl_fiscal_years_no_overlap EXCLUDE USING GIST (
        company_id WITH =,
        daterange(start_date, end_date, '[]') WITH &&
    );
```

And QAYD reuses the same range-exclusion pattern for a structurally identical business rule in a
completely different module — preventing a `price_list_items` row from having two *concurrently
active* prices for the same product:

```sql
ALTER TABLE price_list_items
    ADD CONSTRAINT excl_price_list_items_no_overlap EXCLUDE USING GIST (
        price_list_id WITH =,
        product_id WITH =,
        tsrange(valid_from, valid_to, '[)') WITH &&
    );
```

Here the bound flag is `'[)'` (inclusive start, exclusive end) rather than `'[]'`, deliberately
different from the fiscal-period example — a price that is "valid to" a given timestamp is
correctly understood as no longer valid *at* that exact timestamp, so a new price can legitimately
start at the exact instant the old one ends without the exclusion constraint flagging a false
overlap. Choosing the bound-inclusivity flag correctly for each domain (inclusive-inclusive for
whole calendar days, inclusive-exclusive for continuous timestamps) is one of the most common
sources of off-by-one bugs when implementing `EXCLUDE` constraints, and this document's examples
are the canonical reference for both flag choices in QAYD.

Exclusion-constraint violations surface to Laravel as Postgres error `23P01`
("exclusion_violation"), which the exception handler maps to a `409 Conflict` with a message like
"This period overlaps an existing fiscal period for this company."

# Trigger-Enforced Invariants

Some invariants cannot be expressed by any declarative constraint (`CHECK`, `UNIQUE`, `EXCLUDE`,
foreign key) because they require aggregating other rows, consulting a different table, or
mutating a derived value as a side effect. QAYD implements these as `BEFORE`/`AFTER` triggers,
kept as small, single-purpose `plpgsql` functions, each with a name that states exactly what it
guards.

**Immutability of posted journal lines.** Once a `journal_entries` row transitions to `status =
'posted'`, none of its `journal_lines` may ever be updated or hard-deleted — corrections must go
through a reversing entry, never an edit. `CHECK` constraints cannot see the parent row's status,
so this is a trigger:

```sql
CREATE OR REPLACE FUNCTION fn_prevent_posted_line_mutation() RETURNS trigger AS $$
DECLARE
    v_status VARCHAR(20);
BEGIN
    SELECT status INTO v_status FROM journal_entries
     WHERE id = COALESCE(OLD.journal_entry_id, NEW.journal_entry_id);

    IF v_status IN ('posted', 'reversed', 'voided') THEN
        RAISE EXCEPTION
            'Cannot modify journal_lines of a % journal entry (id=%); post a reversing entry instead',
            v_status, COALESCE(OLD.journal_entry_id, NEW.journal_entry_id)
            USING ERRCODE = 'raise_exception';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prevent_posted_line_mutation
    BEFORE UPDATE OR DELETE ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION fn_prevent_posted_line_mutation();
```

**Preventing edits to a closed fiscal period.** No `journal_entries` row may be inserted, posted,
or have its date changed into a `fiscal_periods` row whose `status` is `closed` or `locked`:

```sql
CREATE OR REPLACE FUNCTION fn_prevent_entry_in_closed_period() RETURNS trigger AS $$
DECLARE
    v_period_status VARCHAR(20);
BEGIN
    SELECT status INTO v_period_status FROM fiscal_periods WHERE id = NEW.fiscal_period_id;

    IF v_period_status IN ('closed', 'locked') AND NEW.status <> 'draft' THEN
        RAISE EXCEPTION
            'Cannot post to fiscal period % because it is %', NEW.fiscal_period_id, v_period_status
            USING ERRCODE = 'raise_exception';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prevent_entry_in_closed_period
    BEFORE INSERT OR UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION fn_prevent_entry_in_closed_period();
```

**`updated_at` auto-touch.** A generic, reusable trigger bumps `updated_at` on every row update
across every table in the schema, since a column `DEFAULT` only fires on `INSERT`:

```sql
CREATE OR REPLACE FUNCTION fn_touch_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_touch_updated_at BEFORE UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at();
CREATE TRIGGER trg_touch_updated_at BEFORE UPDATE ON invoices
    FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at();
CREATE TRIGGER trg_touch_updated_at BEFORE UPDATE ON accounts
    FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at();
-- Repeated identically for every table with an updated_at column.
```

**Denormalized `invoices.status` maintenance from payment allocations.** When a `receipts` row is
allocated against an invoice (via `receipt_allocations`), the invoice's `paid_amount` and `status`
must stay consistent without every call site remembering to recompute them:

```sql
CREATE OR REPLACE FUNCTION fn_recalc_invoice_paid_amount() RETURNS trigger AS $$
DECLARE
    v_invoice_id BIGINT := COALESCE(NEW.invoice_id, OLD.invoice_id);
    v_paid NUMERIC(19,4);
    v_total NUMERIC(19,4);
BEGIN
    SELECT COALESCE(SUM(allocated_amount), 0) INTO v_paid
      FROM receipt_allocations
     WHERE invoice_id = v_invoice_id AND deleted_at IS NULL;

    SELECT total_amount INTO v_total FROM invoices WHERE id = v_invoice_id;

    UPDATE invoices
       SET paid_amount = v_paid,
           status = CASE
                       WHEN v_paid = 0 THEN 'sent'
                       WHEN v_paid >= v_total THEN 'paid'
                       ELSE 'partially_paid'
                    END
     WHERE id = v_invoice_id
       AND status NOT IN ('draft', 'void');

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_recalc_invoice_paid_amount
    AFTER INSERT OR UPDATE OR DELETE ON receipt_allocations
    FOR EACH ROW EXECUTE FUNCTION fn_recalc_invoice_paid_amount();
```

Note this trigger's own `UPDATE` on `invoices` still passes through `chk_invoices_paid_not_exceed_total`
and every other `CHECK` on that table — trigger-maintained derived data is not exempt from the
declarative constraints already in place; the two layers compose.

**Preventing negative stock where company policy forbids it.** `inventory_items.quantity_on_hand`
is a materialized balance maintained by `stock_movements` inserts. Whether negative stock is
allowed is a *per-company policy flag* (`companies.allow_negative_stock`), which a static `CHECK
(quantity_on_hand >= 0)` cannot express (it would forbid negative stock for every company
unconditionally). This is trigger territory:

```sql
CREATE OR REPLACE FUNCTION fn_enforce_stock_policy() RETURNS trigger AS $$
DECLARE
    v_allow_negative BOOLEAN;
    v_new_qty NUMERIC(18,4);
BEGIN
    SELECT allow_negative_stock INTO v_allow_negative
      FROM companies WHERE id = NEW.company_id;

    SELECT quantity_on_hand + NEW.quantity_delta INTO v_new_qty
      FROM inventory_items
     WHERE company_id = NEW.company_id AND warehouse_id = NEW.warehouse_id
       AND product_id = NEW.product_id
     FOR UPDATE;                       -- lock the row to prevent a concurrent-movement race

    IF NOT v_allow_negative AND v_new_qty < 0 THEN
        RAISE EXCEPTION
            'Stock movement would drive product % below zero in warehouse % (policy forbids negative stock)',
            NEW.product_id, NEW.warehouse_id
            USING ERRCODE = 'raise_exception';
    END IF;

    UPDATE inventory_items SET quantity_on_hand = v_new_qty
     WHERE company_id = NEW.company_id AND warehouse_id = NEW.warehouse_id
       AND product_id = NEW.product_id;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_enforce_stock_policy
    BEFORE INSERT ON stock_movements
    FOR EACH ROW EXECUTE FUNCTION fn_enforce_stock_policy();
```

The explicit `FOR UPDATE` row lock inside the trigger is deliberate and important: without it, two
concurrent `stock_movements` inserts for the same product/warehouse could both read the same
starting `quantity_on_hand`, both compute a valid-looking resulting balance independently, and both
commit — a classic lost-update race that would corrupt the stock balance despite every individual
check passing. Locking the `inventory_items` row for the duration of the trigger serializes
concurrent movements against the same product/warehouse, which is exactly the behavior QAYD wants
(it is the same real-world resource, so contention here is correct, not a bug).

Every trigger function in QAYD follows two conventions strictly: it uses `RAISE EXCEPTION ... USING
ERRCODE = 'raise_exception'` (or a more specific SQLSTATE where one exists, such as
`check_violation`/`23514`) so Laravel's Postgres exception listener can pattern-match the error
code rather than parsing English text; and it is idempotent-safe to re-run under `CREATE OR REPLACE
FUNCTION`, so a migration that tweaks trigger logic never needs to drop and recreate the trigger
object itself, only replace the underlying function body.

# Validation (app + DB layering)

QAYD's validation is deliberately duplicated across layers — this is not wasted effort, it is
defense in depth, and each layer validates the same rule for a different reason:

| Rule | Frontend (Zod) | Laravel FormRequest | Laravel Service | PostgreSQL |
|---|---|---|---|---|
| `invoice.total_amount >= 0` | UX: disable submit | `numeric\|min:0` | — | `CHECK` |
| Journal entry balances | Live running total in UI | — | Pre-check for fast `422` before hitting DB | `CONSTRAINT TRIGGER` at commit (authoritative) |
| Invoice number uniqueness | — | — | — | partial `UNIQUE INDEX` (authoritative) |
| Fiscal period not closed | Greyed-out date picker | — | `if ($period->status !== 'open') throw ValidationException` | `BEFORE` trigger (authoritative) |
| Currency code valid ISO 4217 | dropdown of known codes only | `Rule::in($activeCurrencies)` | — | regex `CHECK` + FK to `currencies` |
| Customer belongs to active company | — | scoped Eloquent query (`where company_id`) | Repository always company-scoped | composite FK `(id, company_id)` |
| Debit XOR credit per line | UI enforces "enter either debit or credit" | `prohibits` rule between the two fields | — | `CHECK` |

The pattern in every row of that table is the same: **the earlier layers exist to give the user
(or the calling service) a fast, friendly, specific error before a round-trip to the database is
even attempted; the database layer exists to make the rule true, unconditionally, for every
possible code path, including ones that don't exist yet.** A Laravel FormRequest rule can be
deleted by an inexperienced contributor refactoring a controller; a `CHECK` constraint cannot be
silently bypassed by any application-layer code change, because it lives in the schema, not in the
codebase that changes underneath it.

Two concrete implications for how QAYD's backend is written:

1. **Every Service method that writes to a constrained table wraps the write in a `try/catch` for
   Postgres constraint-violation exceptions**, even when a FormRequest already validated the input,
   because the constraint is expected to occasionally be the thing that actually catches a bug (a
   race condition, a stale cache, a bad migration). Laravel's `QueryException` exposes the
   underlying SQLSTATE via `$exception->getCode()`; QAYD's global exception handler maps common
   SQLSTATEs (`23505` unique_violation, `23503` foreign_key_violation, `23514` check_violation,
   `23P01` exclusion_violation) to the standard `422`/`409` API envelope automatically, so
   individual Service methods do not need bespoke catch blocks for the common cases — only for
   rules that need a friendlier, rule-specific message than the generic mapper provides.
2. **Application-level validation is never loosened on the assumption that "the database will
   catch it anyway."** A `CHECK` violation surfaces after the query round-trip, as a raw and
   comparatively expensive error path (transaction abort, exception unwinding, translated error
   response); a FormRequest rejection surfaces in microseconds before any query runs. The database
   constraint is the safety net, not the primary control.

# Migration-Safe Constraint Changes (NOT VALID → VALIDATE)

Adding a `CHECK` or `FOREIGN KEY` constraint to a table that already has rows requires Postgres to
scan the entire table to confirm every existing row satisfies the new rule. On QAYD's largest
tables (`journal_lines`, `stock_movements`, `audit_logs` — potentially hundreds of millions of rows
for a large tenant after a few years), a naive `ALTER TABLE ... ADD CONSTRAINT ...` takes an
`ACCESS EXCLUSIVE` lock on the table for the entire duration of that scan, which blocks every read
and write against the table — an unacceptable outage window on a table the application writes to
continuously.

Postgres's two-phase `NOT VALID` → `VALIDATE CONSTRAINT` pattern splits this into a fast phase (an
`ACCESS EXCLUSIVE` lock held only for the instant it takes to register the constraint's existence,
without checking old rows) and a slow phase (a full-table scan that only takes a `SHARE UPDATE
EXCLUSIVE` lock, which permits concurrent reads and writes throughout the scan). This is the
**mandatory** pattern in QAYD for adding any constraint to a table already carrying production
data:

```sql
-- Migration 1 (fast, brief lock): register the constraint, marked NOT VALID.
-- New rows are checked against it immediately from this point forward; old rows are not yet checked.
ALTER TABLE invoices
    ADD CONSTRAINT chk_invoices_total_matches_components CHECK (
        total_amount = subtotal_amount + tax_amount - discount_amount
    ) NOT VALID;

-- Migration 2 (slow, but non-blocking): scan existing rows without an exclusive lock.
ALTER TABLE invoices VALIDATE CONSTRAINT chk_invoices_total_matches_components;
```

The equivalent two-phase pattern for a foreign key follows the identical shape:

```sql
ALTER TABLE journal_lines
    ADD CONSTRAINT fk_journal_lines_cost_center
        FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) NOT VALID;

ALTER TABLE journal_lines VALIDATE CONSTRAINT fk_journal_lines_cost_center;
```

QAYD's Laravel migration convention wraps this as **two separate migration files**, run
back-to-back but as distinct deployable units, so that if the `VALIDATE CONSTRAINT` phase is slow
on a very large tenant's table, it can be deployed and monitored independently of the
schema-registration phase, and — critically — so a deploy is never blocked waiting on a full-table
scan of a live financial table:

```php
// database/migrations/2026_07_16_000001_add_invoice_total_check_not_valid.php
public function up(): void {
    DB::statement("
        ALTER TABLE invoices
        ADD CONSTRAINT chk_invoices_total_matches_components CHECK (
            total_amount = subtotal_amount + tax_amount - discount_amount
        ) NOT VALID
    ");
}
public function down(): void {
    DB::statement("ALTER TABLE invoices DROP CONSTRAINT chk_invoices_total_matches_components");
}

// database/migrations/2026_07_16_000002_validate_invoice_total_check.php
public function up(): void {
    DB::statement("ALTER TABLE invoices VALIDATE CONSTRAINT chk_invoices_total_matches_components");
}
public function down(): void {
    // No-op: validating a constraint that already exists is not reversible in a meaningful sense.
}
```

`UNIQUE` constraints have no `NOT VALID` option, because uniqueness is fundamentally an index
property, not a row-check property — but the equivalent non-blocking technique exists via
`CREATE UNIQUE INDEX CONCURRENTLY` followed by attaching that index as the constraint:

```sql
-- Step 1: build the index without locking the table against writes (CONCURRENTLY cannot run
-- inside a transaction block, so this must be its own migration/statement, not batched with others).
CREATE UNIQUE INDEX CONCURRENTLY uq_invoices_company_invoice_number_new
    ON invoices (company_id, invoice_number) WHERE deleted_at IS NULL;

-- Step 2: attach the pre-built index as the actual constraint — this is fast because the
-- expensive part (building the index) already happened concurrently in step 1.
ALTER TABLE invoices
    ADD CONSTRAINT uq_invoices_company_invoice_number
    UNIQUE USING INDEX uq_invoices_company_invoice_number_new;
```

`CREATE INDEX CONCURRENTLY` cannot run inside Laravel's automatic migration transaction wrapper, so
QAYD migrations that use it must disable the implicit transaction for that specific migration
class:

```php
class AddInvoiceUniqueIndexConcurrently extends Migration
{
    public $withinTransaction = false;   // required for CONCURRENTLY

    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uq_invoices_company_invoice_number_new
            ON invoices (company_id, invoice_number) WHERE deleted_at IS NULL
        ");
    }
}
```

`EXCLUDE` constraints (see the fiscal-periods GiST example) have no `NOT VALID` phase at all in
Postgres — adding one to a populated table always requires the full validating scan under an
`ACCESS EXCLUSIVE` lock. Because `fiscal_periods` and `fiscal_years` are low-row-count,
low-write-frequency tables (a company creates on the order of 12 rows per year), this is an
acceptable, brief lock in practice and QAYD does not attempt to work around it; the same technique
would be unacceptable in production if ever needed on a high-traffic table, at which point the
correct approach is a blue-green table swap (build a new table, backfill, atomically rename) rather
than an in-place `ALTER TABLE`.

Every migration in QAYD that adds or validates a constraint on a table expected to exceed roughly
one million rows is required to state, in its migration file's doc comment, the expected row count
at deploy time and the chosen lock strategy (`NOT VALID` two-phase, `CONCURRENTLY` index, or "small
table, direct `ALTER TABLE` accepted") — this is a code-review gate, not merely a suggestion, for
any migration touching `journal_lines`, `stock_movements`, `audit_logs`, `ledger_entries`,
`bank_transactions`, or `ai_messages`.

# Best Practices

- **Name every constraint explicitly.** Never rely on Postgres's auto-generated constraint names
  (`invoices_customer_id_fkey`). QAYD's naming convention is `<type>_<table>_<column(s)/intent>`:
  `pk_`, `fk_`, `uq_`, `chk_`, `excl_`. Explicit names make `DROP CONSTRAINT` in a future migration
  unambiguous and make constraint-violation error messages immediately traceable to a specific rule
  without cross-referencing the schema.
- **Index every foreign-key column.** Postgres does not do this automatically; an unindexed FK
  column makes `ON DELETE`/`ON UPDATE` cascade checks and ordinary join queries do full sequential
  scans on the child table.
- **Prefer partial indexes for soft-deleted uniqueness** (`WHERE deleted_at IS NULL`) over plain
  `UNIQUE` constraints on any table that is never hard-deleted.
- **Push aggregate/cross-row invariants into deferrable constraint triggers**, never into
  a plain `AFTER` trigger with an immediate check, when the invariant is naturally
  multi-statement (balanced journal entries, re-sequencing unique line numbers).
- **Use `NOT VALID` + `VALIDATE CONSTRAINT` for every constraint added to a populated table** larger
  than a few thousand rows, as a non-negotiable migration standard, not merely a nice-to-have.
- **Give every `CHECK` constraint a name that states the rule**, not a generic
  `invoices_check1` — future engineers (and AI agents generating migrations) should be able to
  understand the business rule from `\d+ invoices` output alone, without opening the migration
  history.
- **Co-locate the constraint with the `CREATE TABLE`, not as a bolted-on later `ALTER TABLE`**,
  whenever the table is new — constraints are part of the table's contract from birth, not an
  afterthought.
- **Test constraints with negative-case tests**, not just happy-path tests: every `CHECK`,
  `EXCLUDE`, and constraint trigger in QAYD has at least one Pest test that asserts the *expected
  Postgres exception* is thrown for the invalid input, verifying the constraint actually fires
  rather than merely existing in the schema.
- **Keep trigger functions small and single-purpose.** One trigger function enforces one
  invariant; do not combine "check balance" and "check period is open" in the same function, so
  each can be tested, disabled, and reasoned about independently.
- **Document bound-inclusivity choices (`'[]'` vs `'[)'`) inline** wherever a range type is used in
  an `EXCLUDE` constraint — this is the single most common source of subtle off-by-one bugs in
  range-based schemas.

# Anti-Patterns

- **Relying solely on application-level validation for financial correctness.** A Laravel
  `FormRequest` rule that checks "debit + credit balance" without a matching database-level
  deferred constraint trigger is a false sense of security — any bypass of that specific code path
  (a bulk import, a future microservice, a manual fix) produces a silently unbalanced ledger with
  no database-level alarm.
- **Storing money as `FLOAT`/`DOUBLE PRECISION` or `INTEGER` cents without a fixed-point type.**
  QAYD standardizes on `NUMERIC(19,4)` specifically because binary floating point cannot represent
  most decimal fractions exactly, and repeated additions/subtractions across thousands of journal
  lines accumulate visible rounding error in a trial balance. `NUMERIC` is exact decimal arithmetic
  and the several-percent CPU overhead versus a native float is irrelevant next to the correctness
  requirement.
- **Table-level `UNIQUE` constraints on soft-deleted tables.** This silently prevents legitimate
  reuse of a business key after a row is voided/cancelled and forces awkward workarounds
  (`invoice_number = 'INV-00042-VOID-1'`) instead of the correct partial-unique-index fix.
  
- **Using `ON DELETE CASCADE` on a foreign key into a financial master table** (`accounts`,
  `customers`, `products`) "to make deletes easier." This is the single most dangerous mistake a
  new engineer can make on this schema: it converts "accidentally delete one customer row" into
  "silently and permanently destroy every historical invoice, receipt, and journal line for that
  customer," with no possibility of audit reconstruction.
- **Skipping `NOT VALID` and running a direct `ALTER TABLE ... ADD CONSTRAINT` on a
  hundred-million-row table during business hours.** This takes an `ACCESS EXCLUSIVE` lock for the
  duration of a full-table scan, which in practice means every read and write against that table —
  including the API endpoints serving live traffic — queues up and times out.
- **Implementing "at most one default row per group" with application-level "unset the old default,
  then set the new one" logic instead of a partial unique index.** This is racy under concurrent
  requests and is exactly the kind of invariant a one-line partial unique index solves
  unconditionally.
- **Writing a `CHECK` constraint that calls a `VOLATILE` function or references `now()`/`current_date`
  directly inside the boolean expression** (e.g. `CHECK (created_at <= now())`). Postgres
  constraints must be side-effect-free and, more importantly, a constraint referencing `now()`
  changes truth value over time for a row that never changes — a row that was valid when inserted
  can later "become" invalid purely because time passed, which is not enforceable by Postgres in a
  meaningful way and produces confusing, non-reproducible failures. Time-relative business rules
  belong in application logic or in a trigger that captures the comparison timestamp at write time,
  not in a static `CHECK`.
- **Silently swallowing constraint-violation exceptions and retrying the write with slightly
  different data** in application code, rather than surfacing the violation to the caller. If a
  `CHECK` or `EXCLUDE` constraint fires, the correct application response is to reject the request
  with a clear error — never to "self-heal" by mutating the input until the database accepts it,
  which defeats the entire purpose of having the constraint.
- **Adding a foreign key without also composite-scoping it by `company_id`** when the referenced
  table is itself company-scoped. This leaves a real, exploitable cross-tenant data-leak surface
  even though "a foreign key exists" gives a false sense that referential integrity is fully
  covered.

# Edge Cases

- **Reversing a posted journal entry that included a now-deactivated account.** The reversing entry
  must post to the exact same `account_id`, even if that account's `status` has since become
  `inactive`. QAYD's account-deactivation flow only sets `status = 'inactive'`; it never soft- or
  hard-deletes the row, and no constraint blocks *posting* to an inactive account — only the
  application-layer "create new transaction" UI hides inactive accounts from pickers. The database
  layer correctly stays permissive here because a reversing entry is not a new business decision,
  it is a mechanical mirror of history.
- **A journal entry with exactly one line.** The `chk_journal_lines_debit_xor_credit` constraint
  permits a single line in isolation (it is either a valid debit or a valid credit row on its own),
  but the deferred balance-check constraint trigger will always reject the transaction at commit,
  because one non-zero debit can never equal a total credit of zero. This is correct: a balanced
  entry requires a minimum of two lines by definition, and the two independent constraints combine
  to enforce that minimum without either one needing to hard-code "at least 2 rows" itself.
- **Multi-currency journal entries where `base_amount` rounding causes a one-cent balance
  discrepancy.** When lines are entered in a foreign currency and converted to base currency via
  `exchange_rate`, rounding each line's `base_amount_debit`/`base_amount_credit` independently to
  4 decimal places can leave the *base-currency* totals off by a fraction of a cent even though the
  *transaction-currency* totals balance exactly. QAYD's deferred balance trigger checks
  transaction-currency `debit_amount`/`credit_amount` equality (the true double-entry invariant),
  never the derived base-currency columns, specifically to avoid rejecting economically-balanced
  entries due to rounding artifacts in a converted value; base-currency rounding differences are
  instead swept to a dedicated `rounding_adjustment` line capped at a small configurable tolerance
  (e.g. 0.01 in base currency) inserted automatically by the posting Service, itself a normal,
  fully-constrained `journal_lines` row.
- **Concurrent posting of two journal entries into a fiscal period that is being closed at the same
  moment.** The `fiscal_periods.status` transition to `closed` and a competing `journal_entries`
  insert into that same period are a genuine race; QAYD's period-close Service acquires an
  application-level `SELECT ... FOR UPDATE` lock on the target `fiscal_periods` row before flipping
  its status, and the `trg_prevent_entry_in_closed_period` trigger re-reads `fiscal_periods.status`
  inside the same transaction as the entry insert — whichever transaction commits first wins, and
  the loser sees either a fresh "period already closed" trigger exception or, if it started first,
  successfully posts before the close committed. This is intentional and correct: there is no
  "right" answer to who should win an actual real-time race between a closing accountant and an
  in-flight transaction; the guarantee QAYD provides is that the *final state is never
  inconsistent* (no entry is posted into a period that is durably closed), not that one particular
  ordering always wins.
- **A soft-deleted (voided) invoice being referenced by a `receipt_allocations` row created before
  the void.** Voiding an invoice never hard-deletes it, so the foreign key from
  `receipt_allocations.invoice_id` remains valid even after `invoices.deleted_at` is set; historical
  receipt allocations continue to resolve correctly for audit and reconciliation purposes. The
  application layer is responsible for surfacing the invoice's voided status prominently when
  rendering that historical allocation, but the database layer deliberately does not forbid the
  reference from existing, because forbidding it would make voided-invoice payment history
  unqueryable.
- **A `fiscal_periods` exclusion-constraint violation caused by an off-by-one in bound inclusivity
  during bulk calendar generation.** When a Service auto-generates twelve monthly periods for a new
  fiscal year, an implementation bug that sets each period's `end_date` to the *first* day of the
  next month (rather than the last day of the current month) causes every adjacent pair of periods
  to overlap by exactly one day under the `'[]'`-inclusive `daterange`, and the `EXCLUDE` constraint
  correctly rejects the second period's insert with a `23P01` error. This is the constraint doing
  its job — the fix is in the period-generation Service's date arithmetic (use
  `(date_trunc('month', d) + interval '1 month - 1 day')::date` for `end_date`), never a relaxation
  of the constraint.
- **Currency code changing meaning for a company mid-year (rare but real: a company's functional/
  base currency is redenominated by regulatory change).** QAYD does not support in-place changes to
  `companies.base_currency_code` on a company with any existing posted `journal_lines` — the
  constraint model assumes a company's base currency is immutable for its entire operating history
  in this schema. If a base-currency change is ever required, in practice it is one of QAYD's
  documented "requires a new company record with historical data migrated forward" operations, not
  a supported live `UPDATE`, and no constraint attempts to make an in-place base-currency change
  safe, because no set of constraints could make that operation safe — the risk must be eliminated
  procedurally, not merely checked for.

# End of Document
