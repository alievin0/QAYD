# Database Naming Conventions — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_NAMING_CONVENTIONS
---

# Purpose

This document is the single normative reference for every identifier that appears anywhere in the
QAYD PostgreSQL schema: databases, schemas, tables, columns, primary keys, foreign keys, indexes,
constraints, views, materialized views, functions, triggers, enumerated types, UUID columns, JSONB
columns, audit tables, history tables, sequences, and migration files. It exists because QAYD is a
multi-module, multi-tenant Accounting Engine (Accounting, Sales, Purchasing, Inventory, Banking,
Payroll, Tax, Reports) built by many engineers and, increasingly, by AI agents that generate DDL and
Laravel migrations autonomously. Without one binding naming law, five modules will independently
invent five different spellings for the same concept — `cust_id`, `customerId`, `fk_customer`,
`customer_ref`, `id_customer` — and every join, every ORM model, every generated migration, and every
AI-authored query becomes a guessing game. A naming convention that is inconsistently applied is worse
than useless: it creates false confidence that a pattern exists when it does not.

The rules below are not aesthetic preferences. Every rule exists to satisfy one or more of these
engineering constraints, which recur throughout the document and justify individual decisions:

1. **Grep-ability.** An engineer or AI agent must be able to find every reference to a concept with a
   single case-sensitive substring search. `customer_id` must always mean the same thing everywhere it
   appears — never `customerId` in one file and `cust_id` in another.
2. **Predictability without lookup.** Given a table name, a competent engineer must be able to guess
   its primary key name, its foreign key column names, its timestamp column names, and its soft-delete
   column name correctly on the first try, without opening the schema.
3. **PostgreSQL identifier safety.** PostgreSQL folds unquoted identifiers to lowercase and truncates
   identifiers beyond 63 bytes silently. Every rule that constrains casing, reserved words, and length
   exists to keep identifiers valid and unambiguous without quoting.
4. **ORM and code-generation compatibility.** Laravel/Eloquent, TypeScript codegen, and AI-driven
   scaffolding all assume snake_case tables/columns, singular relation names, and `<table_singular>_id`
   foreign keys. Deviating breaks convention-over-configuration and forces explicit mapping everywhere.
5. **Auditability and multi-tenancy.** QAYD is a financial system: every table must make it obvious,
   from its column names alone, whether it is tenant-scoped, soft-deletable, and audit-tracked.
6. **Bilingual (EN/AR) and multi-currency correctness.** Naming must encode which columns are
   localized (`name_en`/`name_ar`) and which carry monetary values with an explicit currency
   (`*_amount` + `currency_code`), so that an AI agent or engineer can never silently mix currencies or
   render the wrong language column.

This document assumes PostgreSQL 15+, Laravel 12 migrations as the DDL authoring mechanism, and the
platform-wide standard columns already defined in the shared design context (`id`, `company_id`,
`branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`). Every naming rule
below is written to be mechanically checkable — by a linter, by code review, or by an AI agent
validating its own generated migration before submitting it — and every rule is illustrated with
paired GOOD/BAD SQL so there is never ambiguity about which side of the line a given identifier falls
on.

# General Principles

**P1 — snake_case everywhere, no exceptions.** Every identifier — database, schema, table, column,
index, constraint, view, function, trigger, type, sequence — is lowercase `snake_case`. Never
`camelCase`, never `PascalCase`, never `kebab-case`, never mixed case relying on quoting.

```sql
-- GOOD
CREATE TABLE sales_orders (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_number TEXT NOT NULL,
    total_amount NUMERIC(19,4) NOT NULL
);

-- BAD — camelCase requires double-quoting on every reference forever
CREATE TABLE "salesOrders" (
    "id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    "orderNumber" TEXT NOT NULL,
    "totalAmount" NUMERIC(19,4) NOT NULL
);
```

The BAD example is not merely a style violation: unquoted, `"salesOrders"` and `salesorders` are
different identifiers in PostgreSQL, and any tool, migration, or hand-written query that forgets the
quotes silently fails or silently hits a different (non-existent) relation. snake_case removes this
entire failure class because it is case-fold-safe.

**P2 — English, ASCII identifiers only.** Every SQL identifier is English ASCII, even though the
*data* stored is bilingual (see `name_en`/`name_ar` in **Columns**). Arabic labels are a `_ar` column
suffix on an otherwise English identifier, never a differently-scripted identifier.

```sql
-- GOOD
name_ar TEXT,
description_ar TEXT

-- BAD — non-ASCII identifiers are legal in Postgres but break every external tool
"اسم" TEXT
```

**P3 — Full words, no cryptic abbreviations.** Abbreviate only from the fixed whitelist in the
Abbreviation Table below. Otherwise spell the word out. `qty` is allowed because it is in the
whitelist and used consistently platform-wide; `amt`, `nm`, `desc`, `cust`, `vend` are not, because
they save four keystrokes and cost every future reader a lookup.

| Allowed abbreviation | Meaning | Never write instead |
|---|---|---|
| `qty` | quantity | `quantity` in full is also fine; `qnty`, `qy` are not |
| `id` | identifier | `identifier`, `ident` |
| `no` (only as `_no` suffix, e.g. `invoice_no`) | number | `num`, `nbr` |
| `pct` | percent | `percentage` in full is also fine; `prcnt` is not |
| `img` | image (only in `attachments`-style contexts) | `pic`, `photo` unless it is literally a photo entity |
| `qa` | quality assurance (only in `quality_inspections`) | — |
| `hr` | human resources (only as a domain prefix, e.g. none currently used — prefer `payroll`/`employees`) | — |

```sql
-- GOOD
quantity_ordered NUMERIC(18,4),
discount_percentage NUMERIC(5,2)

-- BAD
qtyOrdrd NUMERIC(18,4),
disc_pct_2 NUMERIC(5,2)   -- cryptic AND has a meaningless numeric suffix
```

**P4 — Singular type/enum names, plural table names.** This is the single most commonly violated rule
across ORMs, so it gets a rule of its own here and a full section under **Tables**. A table stores a
collection of rows — plural. A type describes one value — singular. See the **Tables** and **Enums**
sections for the exhaustive treatment.

**P5 — Reserved words are forbidden as bare identifiers.** Never name a column or table using a
PostgreSQL or SQL-standard reserved word (`order`, `group`, `user`, `table`, `column`, `check`,
`references`, `constraint`, `default`, `values`, `null`, `type`, `date`, `time`, `all`, `analyze`,
`when`, `case`). QAYD already resolves the two most common collisions platform-wide: sales orders live
in `sales_orders` (never a bare `orders`) and the identity table is `users` — plural avoids the
reserved singular `user` automatically, which is one more reason plural tables are mandatory.

```sql
-- GOOD
CREATE TABLE sales_orders ( ... );
sort_order INTEGER,      -- 'order' embedded in a longer identifier is fine; bare 'order' is not
group_code TEXT

-- BAD — requires quoting on literally every statement, forever, or breaks outright
CREATE TABLE "order" ( ... );
CREATE TABLE "group" ( ... );
"check" BOOLEAN
```

**P6 — 63-byte identifier ceiling, budget for it explicitly.** PostgreSQL truncates identifiers longer
than 63 bytes silently — no error, no warning — which can make two differently-named constraints
collide invisibly. Composite constraint/index names (see **Indexes**, **Constraints**) are the most at
risk. Budget names to stay comfortably under 63 characters; if a generated name (e.g. an index on five
columns) would exceed it, shorten the descriptive suffix, not the required prefix.

```sql
-- RISKY — 61 chars, fine today, one more qualifier away from silent truncation collision
CREATE INDEX idx_journal_lines_company_id_account_id_fiscal_period_id
    ON journal_lines (company_id, account_id, fiscal_period_id);

-- SAFER — shortened, unambiguous, room to grow
CREATE INDEX idx_journal_lines_company_account_period
    ON journal_lines (company_id, account_id, fiscal_period_id);
```

**P7 — Every generated identifier is deterministic from inputs.** Given a rule (`idx_<table>_<cols>`,
`fk_<table>_<column>`, `uq_<table>_<cols>`), two different engineers or AI agents building the same
table on the same day must independently produce the *same* index/constraint name. This is what makes
naming a specification rather than a suggestion — see **Indexes** and **Constraints** for the exact
grammars.

**P8 — One concept, one column name, across all tables.** If `customer_id` means "references
`customers.id`" in `invoices`, it must mean exactly that in every other table that has the concept —
never `customer` in one table and `client_id` in another for the same referent. The canonical noun for
a concept is fixed the first time it is introduced platform-wide and is never re-litigated per module.

**P9 — No Hungarian-notation type prefixes on columns.** Never prefix a column with its SQL type
(`str_name`, `int_quantity`, `bool_is_active`). The type is visible in `information_schema` and in the
`\d` output; encoding it in the name is redundant and rots the moment the column type changes.

```sql
-- GOOD
is_active BOOLEAN NOT NULL DEFAULT true,
quantity NUMERIC(18,4) NOT NULL

-- BAD
bool_is_active BOOLEAN NOT NULL DEFAULT true,
int_quantity NUMERIC(18,4) NOT NULL   -- also lies: it's NUMERIC, not int
```

**P10 — Schema per bounded context is optional; naming is not.** QAYD may or may not split modules
into PostgreSQL schemas (`accounting.*`, `inventory.*`) as the platform grows; if/when it does, table
names inside a schema still follow every rule in this document unchanged — schema-qualification is not
a license to abbreviate or to drop the tenant/audit column conventions.

# Tables

**T1 — Always plural, always snake_case.** A table is a collection of entities; name it with the
plural noun for the entity. This one rule, applied consistently, is what lets any engineer guess a
table name from a domain word with zero lookup.

```sql
-- GOOD
CREATE TABLE customers ( ... );
CREATE TABLE invoices ( ... );
CREATE TABLE invoice_items ( ... );
CREATE TABLE journal_entries ( ... );

-- BAD
CREATE TABLE customer ( ... );        -- singular
CREATE TABLE Invoice ( ... );          -- PascalCase
CREATE TABLE tbl_invoice_items ( ... ); -- Hungarian 'tbl_' prefix, and singular
```

**T2 — Join / association tables are named `<left_plural>_<right_plural>` in a fixed, alphabetically
tie-broken order that matches how the platform already names `company_users`.** Pick the ordering once
per pair and never swap it. When one side is clearly the "owner" (e.g. a role grants many permissions),
name it owner-first regardless of alphabetical order, because readability beats alphabetization.

```sql
-- GOOD — owner-first, matches existing 'company_users'
CREATE TABLE role_permissions (
    role_id       BIGINT NOT NULL REFERENCES roles(id),
    permission_id BIGINT NOT NULL REFERENCES permissions(id),
    PRIMARY KEY (role_id, permission_id)
);

-- BAD — inconsistent ordering vs. the established company_users precedent
CREATE TABLE users_companies ( ... );   -- should be company_users
CREATE TABLE permission_role ( ... );   -- singular AND wrong order
```

**T3 — Child/detail tables are `<parent_plural_singular_stem>_<child_plural>`, never `_details` or
`_lines` inconsistently across modules.** QAYD's fixed pattern: order-line-style children use
`_items` (`invoice_items`, `sales_order_items`, `purchase_order_items`, `bill_items`,
`sales_quotation_items`); journal-style children use `_lines` (`journal_lines`); count-style children
use `_lines` as well (`stock_count_lines`). Do not invent a third suffix (`_details`, `_rows`,
`_entries` for a detail table) for a concept that already has an established suffix elsewhere.

```sql
-- GOOD — consistent with the platform's existing invoice_items / bill_items
CREATE TABLE credit_note_items ( ... );

-- BAD — same concept, gratuitously different suffix
CREATE TABLE credit_note_details ( ... );
CREATE TABLE credit_note_rows ( ... );
```

**T4 — Pure lookup/reference tables are still plural, and are named for what they enumerate, not for
the word "lookup" or "type" alone.** `account_types`, `units_of_measure`, `tax_codes`, `tax_rates` are
correct; a generic `lookups` or `types` table that stuffs unrelated enumerations into one polymorphic
table is forbidden (see **Anti-Patterns**).

```sql
-- GOOD
CREATE TABLE account_types (
    id      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code    TEXT NOT NULL UNIQUE,
    name_en TEXT NOT NULL,
    name_ar TEXT NOT NULL,
    normal_balance TEXT NOT NULL CHECK (normal_balance IN ('debit','credit'))
);

-- BAD — one polymorphic bucket for every enumeration in the system
CREATE TABLE lookups (
    id   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind TEXT NOT NULL,     -- 'account_type' | 'tax_code' | 'unit' | ...
    code TEXT NOT NULL,
    name TEXT NOT NULL
);
```

**T5 — Module-scoping goes in front of the noun only when the plain noun would collide across
modules or would otherwise be ambiguous; do not prefix every table with its module name.** `invoices`
does not need to become `sales_invoices` because the shared design context already resolved the
collision by routing purchase-side documents to `bills`. Only add a disambiguating prefix when a
genuine collision exists — e.g. `stock_adjustments` vs. a hypothetical payroll adjustment would be
`payroll_adjustments`, not a bare `adjustments`.

```sql
-- GOOD — disambiguated because "adjustments" alone is ambiguous across modules
CREATE TABLE stock_adjustments ( ... );

-- BAD — needless module prefix on an already-unambiguous noun
CREATE TABLE accounting_invoices ( ... );   -- 'invoices' alone is already unambiguous platform-wide
```

**T6 — Never pluralize irregularly or inconsistently.** `inventory_items` (not `inventorys` or
`inventory`), `companies` (not `companys`), `taxes` only where the platform means literal tax records —
QAYD instead uses `tax_transactions`/`tax_returns` to avoid the ambiguous bare plural of an already
irregular noun. When a domain noun's English plural is awkward, prefer a synonym or a compound that
pluralizes cleanly.

```sql
-- GOOD
CREATE TABLE companies ( ... );
CREATE TABLE branches ( ... );

-- BAD
CREATE TABLE companys ( ... );
CREATE TABLE branchs ( ... );
```

**T7 — Views and materialized views are plural table-like nouns too, but carry the prefixes defined
in their own sections (`vw_`, `mv_`) so they are never mistaken for base tables.** Covered fully under
**Views** / **Materialized Views**; the table-naming pluralization rule still applies to the noun
portion after the prefix.

# Columns

**C1 — snake_case, singular concept per column, no redundant table-name stutter.** A column name
never repeats its own table's name (`invoices.invoice_number` is acceptable only because "invoice
number" is the universally recognized business term; prefer the shorter form when no ambiguity
results, e.g. `invoices.number` is actually preferred over `invoices.invoice_number` platform-wide
UNLESS the column is also referenced denormalized on a child/related table, in which case the fuller
name disambiguates which number is meant).

```sql
-- GOOD — on invoices itself, short form; on invoice_items, the fuller form disambiguates
CREATE TABLE invoices (
    id     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number TEXT NOT NULL
);
CREATE TABLE receipt_allocations (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_number_snap TEXT   -- explicit "snapshot" naming; never silently rename a denormalized copy
);

-- BAD — stutters uselessly and is inconsistent with itself
CREATE TABLE invoices (
    invoices_invoice_number TEXT
);
```

**C2 — Foreign key columns are always `<singular_referenced_concept>_id`.** This is elaborated fully
under **Foreign Keys**, but it is stated here as a column rule because it governs 80% of all column
names in the schema: `company_id`, `customer_id`, `vendor_id`, `account_id`, `product_id`,
`warehouse_id`, `cost_center_id`, `project_id`, `department_id`, `branch_id`.

**C3 — Timestamps always end in `_at` and are always `TIMESTAMPTZ`, never `TIMESTAMP` (no time
zone).** `created_at`, `updated_at`, `deleted_at`, `posted_at`, `approved_at`, `paid_at`, `voided_at`,
`reversed_at`, `due_at`, `submitted_at`. A bare date-only business field (no time-of-day meaning) ends
in `_date` instead and is typed `DATE`: `invoice_date`, `due_date`, `bill_date`, `payment_date`.

```sql
-- GOOD
posted_at   TIMESTAMPTZ,
due_date    DATE NOT NULL

-- BAD
posted_date TIMESTAMPTZ,        -- wrong suffix for a timestamp
due_at      DATE,               -- wrong suffix for a pure date
created     TIMESTAMP,          -- no _at suffix AND missing time zone
```

**C4 — Booleans start with `is_`, `has_`, or `can_`, never a bare adjective, never `flag`.**
`is_active`, `is_posted`, `is_default`, `has_attachments`, `can_approve`. Always `NOT NULL DEFAULT
<value>` — a nullable boolean is a three-valued logic trap.

```sql
-- GOOD
is_posted BOOLEAN NOT NULL DEFAULT false

-- BAD
posted BOOLEAN,             -- ambiguous: adjective, not a predicate; also nullable
active_flag BOOLEAN NULL    -- 'flag' suffix + nullable tri-state
```

**C5 — Money columns end in `_amount`, are `NUMERIC(19,4)`, and are ALWAYS paired with a
`currency_code CHAR(3)` (ISO 4217) on the same row (or an unambiguous single-currency context such as
`base_currency_amount` alongside the company's fixed base currency).** Never store a bare `amount`,
`total`, or `price` column without an adjacent currency column on the same row, and never let two
`_amount` columns on the same table silently disagree about which currency they are in.

```sql
-- GOOD — transaction currency AND base currency amounts, both explicit
CREATE TABLE invoices (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT NOT NULL REFERENCES companies(id),
    currency_code          CHAR(3) NOT NULL,
    exchange_rate          NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount        NUMERIC(19,4) NOT NULL,
    tax_amount             NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount           NUMERIC(19,4) NOT NULL,
    base_currency_amount   NUMERIC(19,4) NOT NULL
);

-- BAD — an amount with no currency in sight
CREATE TABLE invoices (
    total NUMERIC(19,4)     -- total of WHAT currency? unanswerable from the schema alone
);
```

**C6 — Bilingual text columns are `<field>_en` / `<field>_ar`, never a single `name` column with
locale switched at the application layer, and never a `locale`/`lang` discriminator column on a
master-data row.** `name_en`, `name_ar`, `description_en`, `description_ar`. A column with no
language-neutral content (e.g. an ISO code, an email address) has no suffix.

```sql
-- GOOD
name_en TEXT NOT NULL,
name_ar TEXT NOT NULL

-- BAD — one column, locale resolved by application code, can't index or query per-language
name TEXT NOT NULL,
locale TEXT NOT NULL   -- and this implies ONE ROW PER LANGUAGE, which duplicates the whole entity
```

**C7 — Quantities end in no special suffix beyond the domain word itself (`quantity`,
`quantity_ordered`, `quantity_received`) and are `NUMERIC(18,4)`, never `INTEGER`, because fractional
units (kg, liters, hours) are first-class in QAYD's Inventory and Payroll modules.**

```sql
-- GOOD
quantity_ordered  NUMERIC(18,4) NOT NULL DEFAULT 0,
quantity_received NUMERIC(18,4) NOT NULL DEFAULT 0

-- BAD
qty_ord INTEGER   -- cryptic abbreviation AND wrong type for fractional inventory
```

**C8 — Status/lifecycle columns are named `status`, typed as the table's dedicated enum (see
**Enums**), never a free-text `state` column, never an integer status code with meaning only in
application code.**

```sql
-- GOOD
status journal_entry_status NOT NULL DEFAULT 'draft'

-- BAD
state INTEGER NOT NULL DEFAULT 0   -- 0 means what? only the PHP enum class knows
```

**C9 — Codes and short human-facing identifiers end in `_code`, are `TEXT` (never artificially
length-capped with `VARCHAR(n)` unless a real business rule caps it), and are unique per company where
relevant.** `currency_code`, `tax_code` (as a foreign key to `tax_codes.code` in some contexts, or the
table's own natural key), `country_code`, `cost_center_code`, `project_code`.

**C10 — Percentages end in `_percentage` or the whitelisted `_pct`, are `NUMERIC(5,2)`, and are stored
as the human number (`15.00` for 15%), never as a pre-divided fraction (`0.15`), unless the column is
explicitly named `_rate` (see C11) to signal the fractional convention.**

```sql
-- GOOD
discount_percentage NUMERIC(5,2) NOT NULL DEFAULT 0   -- 15.00 == 15%

-- BAD — ambiguous whether 0.15 means 0.15% or 15%
discount NUMERIC(5,2)
```

**C11 — Rates (exchange rates, interest rates, tax rates expressed as a multiplier) end in `_rate`,
are `NUMERIC(18,6)`, and ARE stored as the fractional multiplier (`0.05` for 5%).** The `_rate` vs.
`_percentage` suffix is the signal for which convention applies — never mix the two conventions under
the same suffix.

```sql
exchange_rate NUMERIC(18,6) NOT NULL DEFAULT 1,   -- 0.325000 KWD per USD, e.g.
tax_rate      NUMERIC(18,6) NOT NULL DEFAULT 0     -- 0.050000 == 5%
```

**C12 — JSONB flexible-schema columns end in no special suffix (`tags`, `custom_fields`,
`metadata`) but the type is always explicitly `JSONB`, never `JSON`.** Elaborated under **JSON
Columns**.

**C13 — Never use generic, overloaded column names (`data`, `value`, `info`, `details`, `misc`,
`extra`) on a business table.** If the column is genuinely schema-flexible, name it for its purpose
(`custom_fields`) and type it `JSONB`; if it is a specific business fact, name that fact.

```sql
-- BAD
data JSONB,
info TEXT,
misc TEXT
```

# Primary Keys

**PK1 — Every table has exactly one primary key column, named `id`, typed `BIGINT GENERATED ALWAYS AS
IDENTITY`.** This is the platform default per the shared design context. `id` is never composite for
an entity table (composite primary keys are reserved for genuine many-to-many join tables — see
**T2**).

```sql
-- GOOD
CREATE TABLE customers (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ...
);

-- BAD
CREATE TABLE customers (
    customer_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,   -- table-name stutter on the PK
    ...
);
CREATE TABLE customers (
    id SERIAL PRIMARY KEY   -- SERIAL is legacy; use GENERATED ALWAYS AS IDENTITY
);
```

**PK2 — The constraint name for a primary key, when explicitly named (recommended for every table so
`\d` output and error messages are self-explanatory), is `pk_<table>`.**

```sql
-- GOOD
CREATE TABLE customers (
    id BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    CONSTRAINT pk_customers PRIMARY KEY (id)
);

-- BAD — relies on the auto-generated default name (customers_pkey), inconsistent with every other
-- explicitly named constraint in the schema
CREATE TABLE customers (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY
);
```

**PK3 — Join/association tables use a composite primary key over the two (or more) foreign keys,
named `pk_<join_table>`, and do NOT also add a surrogate `id` column unless the join table itself
needs to be referenced by other tables (e.g. it carries its own audit trail as a first-class entity).**

```sql
-- GOOD — pure join table, composite PK, no wasted surrogate key
CREATE TABLE role_permissions (
    role_id       BIGINT NOT NULL REFERENCES roles(id),
    permission_id BIGINT NOT NULL REFERENCES permissions(id),
    granted_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT pk_role_permissions PRIMARY KEY (role_id, permission_id)
);

-- GOOD — join table promoted to an entity because attachments reference it and it needs full audit
CREATE TABLE stock_transfer_items (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY,
    stock_transfer_id  BIGINT NOT NULL REFERENCES stock_transfers(id),
    product_id         BIGINT NOT NULL REFERENCES products(id),
    CONSTRAINT pk_stock_transfer_items PRIMARY KEY (id)
);
```

**PK4 — Never expose the raw sequential `id` as the externally-shareable business identifier for
sensitive financial documents; pair it with a `uuid` public identifier or a human `number` (see
**UUIDs**), but the internal primary key itself remains the plain `BIGINT identity` for join
performance.** Do not "solve" external exposure by switching the primary key itself to `UUID`
platform-wide — see **UUIDs** for the full trade-off and the platform's resolution.

**PK5 — Never use a natural/business key as the primary key, even when it looks stable (email,
national ID, invoice number).** Business keys change; surrogate `id` never does. Enforce uniqueness on
the natural key with a `UNIQUE` constraint instead (see **Constraints**).

```sql
-- BAD
CREATE TABLE customers (
    email TEXT PRIMARY KEY   -- emails get corrected, merged, deactivated — a PK must never change
);

-- GOOD
CREATE TABLE customers (
    id    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email TEXT,
    CONSTRAINT uq_customers_company_email UNIQUE (company_id, email)
);
```

# Foreign Keys

**FK1 — Foreign key columns are always `<singular_referenced_table_stem>_id`, matching the singular
form of the referenced table's plural name.** `customers` → `customer_id`; `sales_orders` →
`sales_order_id`; `cost_centers` → `cost_center_id`; `fiscal_periods` → `fiscal_period_id`.

```sql
-- GOOD
CREATE TABLE invoices (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES customers(id),
    branch_id   BIGINT NULL REFERENCES branches(id)
);

-- BAD
CREATE TABLE invoices (
    cust      BIGINT REFERENCES customers(id),   -- abbreviated, missing _id suffix
    branch_fk BIGINT REFERENCES branches(id)     -- '_fk' suffix instead of '_id'
);
```

**FK2 — When a table has more than one foreign key to the SAME referenced table for DIFFERENT roles,
disambiguate with a role prefix before `_id`, not after, and never reuse the bare `_id` suffix for
both.** Example: `sales_orders` referencing `employees` twice, once for the salesperson and once for
the approver.

```sql
-- GOOD
CREATE TABLE sales_orders (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    salesperson_id   BIGINT NULL REFERENCES employees(id),
    approved_by_id   BIGINT NULL REFERENCES employees(id)
);

-- BAD — both columns reference employees but nothing disambiguates their role
CREATE TABLE sales_orders (
    employee_id   BIGINT REFERENCES employees(id),
    employee_id_2 BIGINT REFERENCES employees(id)   -- numeric suffix is meaningless
);
```

Note the platform's standing convention already in the shared design context: `created_by` and
`updated_by` are this exact pattern applied to `users` — role prefix (`created_by`, `updated_by`)
without a redundant `_id` suffix, because `_by` already signals "a user reference" unambiguously. Do
not add `_id` to `created_by`/`updated_by` (`created_by_id` is redundant and inconsistent with the
platform standard already fixed in the shared context); DO add `_id` to every other foreign key that
does not have an equally unambiguous role-suffix already carrying that meaning.

**FK3 — Foreign key constraints, when explicitly named (mandatory for every FK in QAYD, so that
constraint-violation error messages and `\d` output are self-explanatory in production logs), follow
`fk_<table>_<column_without_id_suffix>`.**

```sql
-- GOOD
CREATE TABLE invoices (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id BIGINT NOT NULL,
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

-- BAD — relies on the Postgres default auto-name (invoices_customer_id_fkey), which is fine
-- functionally but breaks the platform-wide grep-ability rule (P1) since it's not predictable
-- from the fk_ grammar an engineer would type first
CREATE TABLE invoices (
    customer_id BIGINT REFERENCES customers(id)
);
```

**FK4 — Every foreign key declares an explicit `ON DELETE` policy; never leave it to the implicit
`NO ACTION` default without at least commenting the intent, and NEVER use `ON DELETE CASCADE` on a
financial/transactional table.** QAYD's fixed policy table:

| Referencing relationship | ON DELETE policy | Rationale |
|---|---|---|
| Child line to its parent document (`invoice_items.invoice_id` → `invoices.id`) | `CASCADE` | Deleting a still-draft, never-posted parent legitimately removes its lines; posted documents are never hard-deleted at all (soft delete only) |
| Any reference to a master-data entity (`invoices.customer_id` → `customers.id`) | `RESTRICT` | A customer with invoices must never be hard-deletable; force soft-delete of the customer instead |
| Any reference to `companies`/`branches`/tenant root | `RESTRICT` | Tenant roots are never deletable while child data exists |
| Optional dimension reference (`journal_lines.cost_center_id` → `cost_centers.id`) | `SET NULL` | Removing a cost center should not destroy historical journal lines, only detach the dimension |
| `created_by`/`updated_by` → `users.id` | `SET NULL` | A deleted user must not block deleting/retaining their historical records |

```sql
-- GOOD
CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id)
    REFERENCES invoices(id) ON DELETE CASCADE,
CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id)
    REFERENCES customers(id) ON DELETE RESTRICT,
CONSTRAINT fk_journal_lines_cost_center FOREIGN KEY (cost_center_id)
    REFERENCES cost_centers(id) ON DELETE SET NULL

-- BAD — cascade on a master-data reference silently wipes financial history
CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id)
    REFERENCES customers(id) ON DELETE CASCADE
```

**FK5 — Polymorphic references (e.g. `attachments.attachable_id`) do NOT use the `_id`-suffix +
`REFERENCES` pattern at all, because no single foreign key constraint can span multiple target tables.**
Name the pair `<name>able_type` (TEXT, storing the referenced table's singular stem, e.g. `'invoice'`,
`'bill'`) and `<name>able_id` (BIGINT, no `REFERENCES` clause, validated at the application layer plus
a `CHECK` on the allowed type values).

```sql
-- GOOD
CREATE TABLE attachments (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    attachable_type TEXT NOT NULL,
    attachable_id   BIGINT NOT NULL,
    file_path       TEXT NOT NULL,
    CONSTRAINT ck_attachments_attachable_type
        CHECK (attachable_type IN ('invoice','bill','sales_order','purchase_order','employee'))
);
CREATE INDEX idx_attachments_attachable ON attachments (attachable_type, attachable_id);

-- BAD — pretends to be a normal FK, which is impossible for a polymorphic target
CREATE TABLE attachments (
    invoice_id BIGINT REFERENCES invoices(id),   -- what about attachments on bills, orders, employees?
    bill_id    BIGINT REFERENCES bills(id)        -- a growing pile of nullable FKs, one per target type
);
```

**FK6 — Self-referencing hierarchy foreign keys are named `parent_id`, never `parent_<table>_id`,
because the referenced table is self-evident from context.** `cost_centers.parent_id`,
`product_categories.parent_id`, `accounts.parent_id` (for the Chart of Accounts tree).

```sql
-- GOOD
CREATE TABLE cost_centers (
    id        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id BIGINT NULL REFERENCES cost_centers(id) ON DELETE RESTRICT
);

-- BAD
CREATE TABLE cost_centers (
    parent_cost_center_id BIGINT REFERENCES cost_centers(id)   -- redundant self-reference naming
);
```

# Indexes

**IDX1 — Every index name follows `idx_<table>_<column_or_columns>`, columns joined by underscore in
the same order they appear in the index definition.**

```sql
-- GOOD
CREATE INDEX idx_invoices_company_id ON invoices (company_id);
CREATE INDEX idx_invoices_customer_id ON invoices (customer_id);
CREATE INDEX idx_journal_lines_company_account ON journal_lines (company_id, account_id);

-- BAD
CREATE INDEX invoices_idx1 ON invoices (company_id);         -- meaningless numbered suffix
CREATE INDEX index_on_invoices_for_customer ON invoices (customer_id);  -- verbose, non-grammatical
```

**IDX2 — Every tenant-scoped table indexes `company_id` on its own, even when `company_id` also
appears as the leading column of a composite index for a different query pattern.** Multi-tenant
isolation queries (`WHERE company_id = ?`) must always have a cheap index path independent of which
other filters a specific feature needs.

```sql
CREATE INDEX idx_invoices_company_id ON invoices (company_id);
CREATE INDEX idx_invoices_company_status ON invoices (company_id, status);
```

**IDX3 — Partial indexes are named with a `_active` (or other condition-describing) suffix appended
after the column list, and the condition is repeated in a trailing SQL comment for readability.**

```sql
-- GOOD
CREATE INDEX idx_invoices_company_status_active ON invoices (company_id, status)
    WHERE deleted_at IS NULL; -- excludes soft-deleted rows from the hot index

CREATE UNIQUE INDEX idx_customers_company_email_active ON customers (company_id, lower(email))
    WHERE deleted_at IS NULL;

-- BAD — no signal in the name that this index is partial; a reader has to open the DDL to know
CREATE INDEX idx_invoices_company_status ON invoices (company_id, status)
    WHERE deleted_at IS NULL;
```

**IDX4 — Foreign key columns are always indexed, without exception, because PostgreSQL does NOT
automatically index foreign keys (unlike the primary-key side), and every `ON DELETE`/`ON UPDATE`
check and every join needs it.**

```sql
CREATE INDEX idx_invoice_items_invoice_id ON invoice_items (invoice_id);
CREATE INDEX idx_invoice_items_product_id ON invoice_items (product_id);
```

**IDX5 — GIN indexes on JSONB columns are prefixed `gin_`, not `idx_`, so the index type is visible
in the name and never confused with a routine B-tree index when reading migration diffs.**

```sql
-- GOOD
CREATE INDEX gin_products_custom_fields ON products USING GIN (custom_fields);
CREATE INDEX gin_products_tags ON products USING GIN (tags);
CREATE INDEX gin_customers_custom_fields_jsonb_path_ops
    ON customers USING GIN (custom_fields jsonb_path_ops);

-- BAD — GIN index hidden behind the generic idx_ prefix, indistinguishable from a B-tree at a glance
CREATE INDEX idx_products_custom_fields ON products USING GIN (custom_fields);
```

**IDX6 — Full-text search indexes are prefixed `gin_` as well (since `tsvector` search also uses
GIN), with a `_fts` (full-text search) marker in the suffix.**

```sql
CREATE INDEX gin_products_name_en_fts
    ON products USING GIN (to_tsvector('english', name_en));
CREATE INDEX gin_products_name_ar_fts
    ON products USING GIN (to_tsvector('arabic', name_ar));
```

**IDX7 — Unique indexes used purely for enforcing case-insensitive or normalized uniqueness (not
declared as table constraints) are still named with the `idx_` prefix plus an explicit descriptive
suffix, never the `uq_` prefix — `uq_` is reserved for `UNIQUE` table CONSTRAINTS (see Constraints);
an index created via `CREATE UNIQUE INDEX` outside a constraint keeps the `idx_` family since it may
carry a `WHERE` clause a constraint cannot.**

```sql
CREATE UNIQUE INDEX idx_customers_company_tax_number_active
    ON customers (company_id, tax_number)
    WHERE deleted_at IS NULL AND tax_number IS NOT NULL;
```

**IDX8 — Covering indexes (`INCLUDE`) are named exactly as their underlying column list dictates;
included (non-key) columns are NOT added to the index name, since they don't participate in lookups,
only in avoiding heap fetches.**

```sql
CREATE INDEX idx_invoices_company_status ON invoices (company_id, status)
    INCLUDE (total_amount, currency_code);
```

# Constraints

**CK1 — `CHECK` constraints are named `ck_<table>_<short_description>`.**

```sql
-- GOOD
CONSTRAINT ck_journal_lines_debit_or_credit
    CHECK (debit_amount = 0 OR credit_amount = 0),
CONSTRAINT ck_invoices_total_non_negative
    CHECK (total_amount >= 0),
CONSTRAINT ck_products_status_valid
    CHECK (status IN ('active','inactive','discontinued'))

-- BAD
CONSTRAINT chk1 CHECK (debit_amount = 0 OR credit_amount = 0),
CONSTRAINT invoices_check CHECK (total_amount >= 0)
```

**CK2 — `UNIQUE` constraints are named `uq_<table>_<columns>`, and on any tenant-scoped table a
"business-unique" field is unique PER COMPANY, meaning `company_id` is always the leading column of
the composite unique constraint — never a bare single-column unique that would collide across
tenants.**

```sql
-- GOOD — invoice numbers are only unique within a company, not globally
CONSTRAINT uq_invoices_company_number UNIQUE (company_id, number)

-- BAD — globally unique invoice numbers make no sense in a multi-tenant system, and this also
-- silently blocks Company B from ever using the same invoice number Company A already used
CONSTRAINT uq_invoices_number UNIQUE (number)
```

**CK3 — `NOT NULL` is not itself separately "named" (Postgres has no constraint name for column
`NOT NULL` unless declared via `CONSTRAINT ... CHECK (col IS NOT NULL)`), but the rule is: every
column that is logically mandatory for a valid business row IS declared `NOT NULL` at the column
level; do not simulate "required" with an application-only check.**

```sql
-- GOOD
customer_id BIGINT NOT NULL REFERENCES customers(id)

-- BAD — nullable at the DB level "just in case," enforced only in Laravel FormRequest validation,
-- which a raw SQL script, a data migration, or another service bypassing the API can violate
customer_id BIGINT REFERENCES customers(id)
```

**CK4 — `EXCLUDE` constraints (rare, used for e.g. preventing overlapping fiscal periods) are named
`ex_<table>_<short_description>` and always specify the operator class explicitly.**

```sql
CREATE TABLE fiscal_periods (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
    period_range   DATERANGE NOT NULL,
    CONSTRAINT ex_fiscal_periods_no_overlap
        EXCLUDE USING GIST (company_id WITH =, period_range WITH &&)
);
```

**CK5 — Default values are declared for every column where a sane default exists, and defaults are
never hidden purely in application code, because direct SQL access (migrations, admin tooling, other
services) must produce valid rows too.**

```sql
-- GOOD
status TEXT NOT NULL DEFAULT 'draft',
currency_code CHAR(3) NOT NULL DEFAULT 'KWD',
created_at TIMESTAMPTZ NOT NULL DEFAULT now()

-- BAD
status TEXT NOT NULL   -- no default; every INSERT from every tool must remember to supply it
```

**CK6 — Composite `CHECK` constraints spanning double-entry balance rules belong on the aggregate
level (`journal_entries`), verified by a trigger (see **Triggers**) rather than a plain `CHECK`,
because a `CHECK` constraint cannot aggregate across sibling rows in `journal_lines`. Never attempt
to fake a cross-row invariant with a single-row `CHECK`.**

# Views

**V1 — Views are prefixed `vw_`, followed by the plural noun for what they present, e.g.
`vw_trial_balance`, `vw_customer_balances`, `vw_open_invoices`.** The `vw_` prefix exists so that a
query author can immediately tell, from the relation name alone, that they are reading a derived
projection and not a base table — critical in an accounting system where writing to the wrong relation
must be structurally impossible, not just discouraged.

```sql
-- GOOD
CREATE VIEW vw_open_invoices AS
SELECT
    i.id,
    i.company_id,
    i.customer_id,
    i.number,
    i.currency_code,
    i.total_amount,
    COALESCE(SUM(ra.allocated_amount), 0) AS allocated_amount,
    i.total_amount - COALESCE(SUM(ra.allocated_amount), 0) AS balance_amount
FROM invoices i
LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
WHERE i.deleted_at IS NULL
GROUP BY i.id, i.company_id, i.customer_id, i.number, i.currency_code, i.total_amount;

-- BAD — no prefix, indistinguishable from a base table named 'open_invoices'
CREATE VIEW open_invoices AS SELECT ...;
```

**V2 — Views never expose soft-deleted rows implicitly filtered out silently without documenting it;
the `WHERE deleted_at IS NULL` clause is mandatory and is called out in a comment directly above the
view definition.**

```sql
-- GOOD
-- NOTE: excludes soft-deleted invoices (deleted_at IS NULL). Callers needing deleted rows
-- must query the base table `invoices` directly with an explicit override.
CREATE VIEW vw_open_invoices AS ...
```

**V3 — A view never becomes a target for `INSERT`/`UPDATE`/`DELETE` in QAYD, even where PostgreSQL's
automatically-updatable-view machinery would technically allow it on a single-table view without
aggregation.** All writes go through the Laravel Repository layer against base tables only; a `vw_*`
name signals "read-only projection" and that guarantee must never be violated, because it is the exact
signal engineers rely on per **V1**.

**V4 — Views that exist purely to translate bilingual columns for a given request-locale are named
`vw_<table>_localized` and take no parameters (locale switching happens in the application layer via
column selection, not inside the view) — QAYD prefers exposing both `name_en`/`name_ar` through the API
and letting the frontend choose, so localized views are rare and reserved for reporting contexts.**

```sql
CREATE VIEW vw_products_localized AS
SELECT id, company_id, code, name_en, name_ar, status
FROM products
WHERE deleted_at IS NULL;
```

# Materialized Views

**MV1 — Materialized views are prefixed `mv_`, distinct from the `vw_` prefix, because a materialized
view has fundamentally different operational properties (stale data until `REFRESH`, holds physical
storage, can be indexed) that a caller MUST be aware of.** `ledger_entries` — the General Ledger
read-model projection named explicitly in the shared design context — is the canonical example: even
though its base name has no prefix in the context doc (it predates this convention as a foundation
table), any NEW materialized view built on top of it in this or future modules follows the `mv_`
rule going forward, e.g. `mv_trial_balance_snapshot`, `mv_customer_aging_buckets`.

```sql
-- GOOD
CREATE MATERIALIZED VIEW mv_customer_aging_buckets AS
SELECT
    company_id,
    customer_id,
    SUM(balance_amount) FILTER (WHERE age_days <= 30)  AS bucket_0_30_amount,
    SUM(balance_amount) FILTER (WHERE age_days BETWEEN 31 AND 60) AS bucket_31_60_amount,
    SUM(balance_amount) FILTER (WHERE age_days BETWEEN 61 AND 90) AS bucket_61_90_amount,
    SUM(balance_amount) FILTER (WHERE age_days > 90)   AS bucket_over_90_amount
FROM vw_open_invoices_with_age
GROUP BY company_id, customer_id
WITH DATA;

CREATE UNIQUE INDEX idx_mv_customer_aging_buckets_company_customer
    ON mv_customer_aging_buckets (company_id, customer_id);

-- BAD — no way to tell from the name this is a physically materialized, refresh-lagged relation
CREATE MATERIALIZED VIEW customer_aging_buckets AS ...;
```

**MV2 — Every materialized view carries a unique index over its natural grouping key specifically so
that `REFRESH MATERIALIZED VIEW CONCURRENTLY` is possible (it requires at least one unique index);
name that index `idx_<mv_name>_<key_columns>` following the standard **Indexes** grammar unchanged —
the `mv_` prefix already lives on the relation name, not the index name.**

**MV3 — The refresh mechanism (cron, Laravel scheduled command, trigger-driven) is documented directly
above the `CREATE MATERIALIZED VIEW` statement in a comment, naming the exact Laravel Artisan command
or job class responsible, so the staleness window is never a mystery.**

```sql
-- Refreshed every 15 minutes by App\Console\Commands\RefreshCustomerAgingBuckets
-- (scheduled in App\Console\Kernel via ->everyFifteenMinutes()).
CREATE MATERIALIZED VIEW mv_customer_aging_buckets AS ...;
```

**MV4 — Never name a materialized view identically to a plain view with the prefix swapped as the
only difference in an active migration path (`vw_trial_balance` becoming `mv_trial_balance`) without a
deprecation window; drop the old view only in a subsequent migration once all callers have moved,
and record the rename in **Migrations**.**

# Functions

**F1 — Functions are named `<verb>_<noun>`, snake_case, and the verb is drawn from a fixed
platform vocabulary: `get_`, `calculate_`, `validate_`, `post_`, `reverse_`, `apply_`, `sync_`,
`refresh_`.** A function name must make the caller's intent readable without opening the body.

```sql
-- GOOD
CREATE FUNCTION calculate_invoice_total(p_invoice_id BIGINT)
RETURNS NUMERIC(19,4)
LANGUAGE plpgsql
AS $$
DECLARE
    v_total NUMERIC(19,4);
BEGIN
    SELECT COALESCE(SUM(quantity * unit_price - discount_amount + tax_amount), 0)
    INTO v_total
    FROM invoice_items
    WHERE invoice_id = p_invoice_id;
    RETURN v_total;
END;
$$;

-- BAD
CREATE FUNCTION inv_calc(p_id BIGINT) RETURNS NUMERIC AS $$ ... $$;  -- cryptic verb+noun, no unit
```

**F2 — Every function parameter is prefixed `p_` to visually separate parameters from column names
and local variables inside `plpgsql` bodies, preventing the classic ambiguous-reference bug where a
parameter and a column share a bare name.**

```sql
-- GOOD — p_ prefix removes all ambiguity
CREATE FUNCTION post_journal_entry(p_journal_entry_id BIGINT, p_posted_by_id BIGINT)
RETURNS VOID LANGUAGE plpgsql AS $$
BEGIN
    UPDATE journal_entries
    SET status = 'posted', posted_at = now(), posted_by_id = p_posted_by_id
    WHERE id = p_journal_entry_id;
END;
$$;

-- BAD — journal_entry_id as both parameter and (implicitly) a column reference is a landmine
CREATE FUNCTION post_journal_entry(journal_entry_id BIGINT) RETURNS VOID LANGUAGE plpgsql AS $$
BEGIN
    UPDATE journal_entries SET status = 'posted' WHERE id = journal_entry_id;  -- which one wins?
END;
$$;
```

**F3 — Local variables inside `plpgsql` are prefixed `v_`, matching the `p_`/`v_` convention above,
for the same disambiguation reason.**

**F4 — Trigger functions (distinct from ordinary functions) are named `trg_<action>`, and are covered
fully in **Triggers**; do not reuse the plain function-naming grammar (`F1`) for a function whose only
caller is a trigger, since the `trg_` prefix carries operational meaning (never called directly by
application code).**

**F5 — Functions that return a table/set use `RETURNS TABLE(...)` with explicitly named output
columns following the same **Columns** rules (snake_case, `_amount`/`_at`/`_id` suffixes), never a
bare `RETURNS SETOF <table>` when the function computes derived columns not on the base table.**

```sql
CREATE FUNCTION get_customer_aging(p_company_id BIGINT, p_as_of_date DATE)
RETURNS TABLE (
    customer_id      BIGINT,
    invoice_id       BIGINT,
    invoice_number   TEXT,
    balance_amount   NUMERIC(19,4),
    age_days         INTEGER
)
LANGUAGE sql STABLE AS $$
    SELECT
        i.customer_id,
        i.id,
        i.number,
        i.total_amount - COALESCE(SUM(ra.allocated_amount), 0),
        (p_as_of_date - i.invoice_date)::INTEGER
    FROM invoices i
    LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
    WHERE i.company_id = p_company_id AND i.deleted_at IS NULL
    GROUP BY i.customer_id, i.id, i.number, i.total_amount, i.invoice_date;
$$;
```

**F6 — Functions that mutate data (as opposed to pure calculation/read functions) always take an
explicit `p_<actor>_by_id` parameter for the acting user, even when the function is invoked from a
context that "obviously" knows the actor, because the audit trail (**Audit Tables**) requires it at
every mutation boundary and a function is a mutation boundary.**

# Triggers

**TR1 — Trigger objects (the `CREATE TRIGGER` binding) are named `trg_<table>_<event>_<purpose>`,
where `<event>` is one of `before_insert`, `after_insert`, `before_update`, `after_update`,
`before_delete`, `after_delete`.**

```sql
-- GOOD
CREATE TRIGGER trg_journal_entries_before_update_prevent_posted_edit
    BEFORE UPDATE ON journal_entries
    FOR EACH ROW
    EXECUTE FUNCTION prevent_posted_journal_edit();

-- BAD
CREATE TRIGGER check_journal BEFORE UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_edit();
```

**TR2 — The trigger FUNCTION backing a trigger is named for what it enforces, verb-first, without the
`trg_` prefix on the function itself (the prefix belongs on the trigger binding, not necessarily
duplicated onto the function, since the same function may back triggers on multiple tables) —
`prevent_posted_journal_edit()`, `stamp_updated_at()`, `enforce_balanced_journal_entry()`,
`cascade_soft_delete_children()`.**

```sql
-- GOOD — one generic function, reused by many trigger bindings across tables
CREATE FUNCTION stamp_updated_at() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_invoices_before_update_stamp_updated_at
    BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION stamp_updated_at();
CREATE TRIGGER trg_bills_before_update_stamp_updated_at
    BEFORE UPDATE ON bills FOR EACH ROW EXECUTE FUNCTION stamp_updated_at();
```

**TR3 — Every trigger that enforces a business invariant carries a comment directly above the
`CREATE TRIGGER` statement explaining WHY the invariant exists, because triggers are invisible in
application code and the single most common source of "magic" behavior that confuses new engineers.**

```sql
-- Enforces: posted journal_entries are immutable per the platform's double-entry rules
-- (see DESIGN_CONTEXT.md section 4). Corrections must create a reversing entry instead.
CREATE TRIGGER trg_journal_entries_before_update_prevent_posted_edit
    BEFORE UPDATE ON journal_entries
    FOR EACH ROW
    WHEN (OLD.status = 'posted')
    EXECUTE FUNCTION prevent_posted_journal_edit();
```

**TR4 — Audit-logging triggers (writing into `audit_logs`) are named `trg_<table>_after_<event>_audit`
and are the SOLE mechanism by which `audit_logs` rows are ever written for DB-level changes — never
rely exclusively on application-layer audit calls for a financial table, since raw SQL access must be
caught too.** Full grammar and payload shape in **Audit Tables**.

```sql
CREATE TRIGGER trg_journal_entries_after_update_audit
    AFTER UPDATE ON journal_entries
    FOR EACH ROW
    EXECUTE FUNCTION write_audit_log();
```

**TR5 — Never stack more than one trigger per event on the same table without an explicit execution
order comment; PostgreSQL fires same-event triggers in alphabetical name order, which is exactly why
TR1's naming grammar embeds the event AND the purpose — so that ordering-sensitive triggers can be
deliberately alpha-sequenced (`trg_x_before_update_10_validate`, `trg_x_before_update_20_calculate`)
rather than accidentally ordered.**

# Enums

**E1 — PostgreSQL native `ENUM` types are named `<singular_noun>_status` or `<singular_noun>_type`
(never plural — a type describes ONE value, per **P4**), and are created once, referenced by many
columns/tables sharing the same domain of values.**

```sql
-- GOOD
CREATE TYPE journal_entry_status AS ENUM ('draft', 'posted', 'reversed', 'voided');
CREATE TYPE invoice_status AS ENUM ('draft', 'sent', 'partially_paid', 'paid', 'overdue', 'voided');
CREATE TYPE account_normal_balance AS ENUM ('debit', 'credit');

CREATE TABLE journal_entries (
    id     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    status journal_entry_status NOT NULL DEFAULT 'draft'
);

-- BAD
CREATE TYPE journal_entry_statuses AS ENUM (...);   -- plural type name
CREATE TYPE JournalEntryStatus AS ENUM (...);        -- PascalCase
```

**E2 — Enum VALUES are always lowercase `snake_case` strings, never mixed case, never abbreviated,
matching the register of every other string constant in the platform (matches API JSON convention).**

```sql
-- GOOD
CREATE TYPE bill_status AS ENUM ('draft', 'pending_approval', 'approved', 'paid', 'voided');

-- BAD
CREATE TYPE bill_status AS ENUM ('Draft', 'PendingApproval', 'APPROVED', 'pd');
```

**E3 — QAYD prefers native PostgreSQL `ENUM` types over a bare `TEXT` + `CHECK` for any status/type
column that is (a) shared by more than one table, or (b) queried/filtered frequently, because native
enums are smaller on disk, sort predictably, and self-document via `\dT+`. For a STATUS column that is
local to exactly one table and unlikely to be reused, `TEXT` + a `ck_` CHECK constraint (see
**Constraints**) is acceptable and slightly easier to alter (native enum value removal is
awkward in Postgres; adding a value is cheap via `ALTER TYPE ... ADD VALUE`, but that command cannot
run inside the same transaction as other DDL, which matters for **Migrations**).**

```sql
-- GOOD — local, single-table, rarely-changing status: TEXT + CHECK is pragmatic
CREATE TABLE stock_counts (
    id     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'open',
    CONSTRAINT ck_stock_counts_status CHECK (status IN ('open', 'counting', 'reconciled', 'closed'))
);

-- GOOD — shared across many tables/modules: native enum
CREATE TYPE approval_status AS ENUM ('pending', 'approved', 'rejected');
```

**E4 — Never encode an enum's meaning as a bare integer (`status SMALLINT`) anywhere in the schema,
even as a micro-optimization; the byte savings are immaterial next to the debugging cost of a status
code whose meaning lives only in a PHP enum class that can drift from the database.**

```sql
-- BAD
status SMALLINT NOT NULL DEFAULT 0  -- 0=draft 1=posted 2=reversed 3=voided, per... which file, exactly?
```

**E5 — Adding a new enum value is a normal, documented migration (`ALTER TYPE journal_entry_status ADD
VALUE 'pending_review' AFTER 'draft';`), run as its own migration file NOT combined with other DDL in
the same transaction (Postgres forbids `ALTER TYPE ... ADD VALUE` inside a transaction block that also
runs other statements on some versions/contexts) — see **Migrations** for the exact Laravel migration
shape.**

# UUIDs

**U1 — The internal, join-performance-critical primary key of every table remains `BIGINT GENERATED
ALWAYS AS IDENTITY` (per **PK1**); a `UUID` column is added ADDITIONALLY, never as a replacement, on
any table whose rows are exposed to external systems, end users via shareable links, webhooks, or the
mobile/AI layer where guessing a sequential ID would be a security or business-intelligence leak (e.g.
guessing `invoices.id = 1042` reveals roughly how many invoices exist platform-wide).**

```sql
-- GOOD — sequential BIGINT for joins/FKs, UUID for anything the outside world sees
CREATE TABLE invoices (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid       UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    CONSTRAINT uq_invoices_uuid UNIQUE (uuid)
);
CREATE INDEX idx_invoices_uuid ON invoices (uuid);

-- BAD — UUID as the sole primary key on a high-volume child table forces 16-byte keys into every
-- FK and every index across the whole schema, bloating storage and hurting cache locality with no
-- offsetting benefit since journal_lines/invoice_items are never referenced externally
CREATE TABLE invoice_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid()
);
```

**U2 — The UUID column is always named exactly `uuid` (never `guid`, never `external_id`, never
`public_id`), typed `UUID` (never `TEXT` storing a UUID string), generated server-side with
`gen_random_uuid()` (the `pgcrypto`-free, built-in PostgreSQL 13+ function — never require the
`uuid-ossp` extension, which is unnecessary on modern PostgreSQL).**

```sql
-- GOOD
uuid UUID NOT NULL DEFAULT gen_random_uuid()

-- BAD
guid VARCHAR(36) DEFAULT uuid_generate_v4()::text   -- wrong name, wrong type, unneeded extension
```

**U3 — API responses reference resources by `uuid` in the URL/path wherever the resource is
externally addressable (`GET /api/v1/accounting/invoices/{uuid}`), never by the raw sequential `id`;
internal service-to-service and repository-layer code uses `id` for joins and lookups. This split is
enforced at the Laravel route-model-binding layer (`Route::bind` on `uuid`), not by naming discipline
alone, but the naming discipline (U1/U2) is what makes the split possible without per-table special
casing.**

**U4 — Tables that are purely internal implementation detail and never addressed externally (join
tables, `_items`/`_lines` children accessed only nested under their parent, materialized-view-backing
tables) do NOT get a `uuid` column — adding one "just in case" violates **Anti-Patterns** (speculative
columns) and every `uuid` column that exists must have an actual external caller.**

# JSON Columns

**J1 — Every semi-structured column is typed `JSONB`, never `JSON` (the latter preserves whitespace
and key order and supports no indexing — there is no scenario in QAYD where that trade-off is wanted).**

```sql
-- GOOD
custom_fields JSONB NOT NULL DEFAULT '{}'::jsonb,
tags          JSONB NOT NULL DEFAULT '[]'::jsonb

-- BAD
custom_fields JSON,   -- no GIN indexing possible, preserves irrelevant whitespace
```

**J2 — `JSONB` columns always declare a `NOT NULL DEFAULT` of the correct empty shape (`'{}'::jsonb`
for an object, `'[]'::jsonb` for an array) so that application code never has to special-case `NULL`
vs. empty.**

**J3 — Every `JSONB` column used in `WHERE`/`ORDER BY` gets a matching `gin_` index (per **IDX5**),
and the operator class is chosen deliberately: default GIN (`jsonb_ops`) for `@>`/`?`/`?&`/`?|`
containment queries on varied keys; `jsonb_path_ops` when queries are exclusively `@>` containment
on a narrower key set, since `jsonb_path_ops` produces a smaller, faster index for that specific
pattern.**

```sql
-- General containment + key-existence queries: default GIN
CREATE INDEX gin_products_tags ON products USING GIN (tags);

-- Exclusively @> containment lookups on custom_fields: jsonb_path_ops is smaller/faster
CREATE INDEX gin_products_custom_fields_path_ops
    ON products USING GIN (custom_fields jsonb_path_ops);
```

**J4 — `JSONB` is for genuinely variable, sparse, or user-defined-field data ONLY (`custom_fields`,
`tags`, AI `reasoning`/`metadata` payloads on `ai_messages`) — never as an escape hatch to avoid
designing a proper normalized column or child table for data with a known, fixed shape queried
relationally.** If every row's JSONB blob has the same three keys and you filter/join on one of them
routinely, that key belongs in its own typed column, not buried in JSONB.

```sql
-- BAD — 'currency_code' and 'exchange_rate' are known, fixed-shape, frequently-filtered fields;
-- burying them in JSONB defeats indexing, typing, and CHECK constraints for no benefit
CREATE TABLE invoices (
    id      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payload JSONB NOT NULL   -- {"currency_code": "KWD", "exchange_rate": 1.0, "total_amount": ...}
);

-- GOOD — known fields are real columns; only genuinely open-ended data is JSONB
CREATE TABLE invoices (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    currency_code  CHAR(3) NOT NULL,
    exchange_rate  NUMERIC(18,6) NOT NULL DEFAULT 1,
    total_amount   NUMERIC(19,4) NOT NULL,
    custom_fields  JSONB NOT NULL DEFAULT '{}'::jsonb
);
```

**J5 — Individual keys accessed frequently from within a `JSONB` column may be exposed as a generated
column for indexing without denormalizing the source of truth out of JSONB, named
`<jsonb_column>_<key>` and marked `GENERATED ALWAYS AS (...) STORED`.**

```sql
ALTER TABLE ai_messages ADD COLUMN metadata_confidence NUMERIC(5,4)
    GENERATED ALWAYS AS ((metadata->>'confidence')::numeric) STORED;
CREATE INDEX idx_ai_messages_metadata_confidence ON ai_messages (metadata_confidence);
```

**J6 — Arrays of scalars that are genuinely just a list (not an object) still use `JSONB` in QAYD
(not PostgreSQL native `ARRAY` types), for consistency with the rest of the platform's JSON tooling
(Laravel casts, TypeScript codegen, AI agent payloads) — this is a deliberate platform-wide choice to
have exactly one flexible-list mechanism rather than two (`JSONB` arrays and native `ARRAY`).**

# Audit Tables

**AU1 — The platform-wide audit sink is the single foundation table `audit_logs` (already named in
the shared design context) — modules do NOT create their own per-table audit tables
(`invoices_audit`, `journal_entries_history_log`) for routine field-level change tracking; they all
write into the shared `audit_logs` polymorphic table via the `trg_<table>_after_<event>_audit` trigger
family (**TR4**).**

```sql
-- Canonical shape of the shared audit_logs table (foundation, referenced by every module)
CREATE TABLE audit_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    auditable_type  TEXT NOT NULL,
    auditable_id    BIGINT NOT NULL,
    action          TEXT NOT NULL,           -- 'created' | 'updated' | 'deleted' | 'posted' | 'voided'
    changed_by_id   BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    old_values      JSONB NULL,
    new_values      JSONB NULL,
    reason          TEXT NULL,
    ip_address      INET NULL,
    user_agent      TEXT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_audit_logs_action
        CHECK (action IN ('created','updated','deleted','posted','reversed','voided','approved','rejected'))
);
CREATE INDEX idx_audit_logs_company_id ON audit_logs (company_id);
CREATE INDEX idx_audit_logs_auditable ON audit_logs (auditable_type, auditable_id);
CREATE INDEX idx_audit_logs_changed_by_id ON audit_logs (changed_by_id);
CREATE INDEX gin_audit_logs_old_values ON audit_logs USING GIN (old_values);
CREATE INDEX gin_audit_logs_new_values ON audit_logs USING GIN (new_values);
```

**AU2 — `auditable_type` stores the referenced table's SINGULAR stem (`'invoice'`, `'journal_entry'`,
`'bill'`), matching the same convention as `attachable_type` (**FK5**), for consistency across every
polymorphic reference in the schema — never the plural table name, never the PHP class's fully
qualified name.**

```sql
-- GOOD
auditable_type = 'journal_entry', auditable_id = 4821

-- BAD
auditable_type = 'journal_entries'          -- plural, inconsistent with attachable_type convention
auditable_type = 'App\\Models\\JournalEntry' -- leaks an implementation detail into stored data
```

**AU3 — A module MAY additionally add its own narrowly-scoped audit-like table when the audit need is
domain-specific rather than generic field-change tracking (e.g. `bank_reconciliations` recording a
reconciliation event is itself the audit record for that action — it is a first-class business entity,
not a generic-audit duplicate). Such a table is NOT named with an `_audit`/`_log` suffix; it is named
for the business event itself, because it is not a copy of `audit_logs`, it IS the primary record.**

**AU4 — Every `audit_logs` row referencing a financial document additionally denormalizes
`company_id` (never rely on joining through `auditable_id` to recover tenant scope) so that Row Level
Security and tenant-isolation queries on `audit_logs` never require a cross-table join — this mirrors
the platform-wide rule that `company_id` is present and indexed on every business table (Design
Context section 2), including this shared audit sink.**

# History Tables

**H1 — "History table" in QAYD means a temporal/versioned table that stores EVERY prior state of a
row for point-in-time reconstruction and regulatory retention — distinct from `audit_logs`, which
stores discrete change EVENTS (who/when/what changed) rather than full row snapshots. Use a history
table when the business requirement is "show me exactly what this exchange rate / price list / tax
rate was on date X," not merely "who changed it."**

**H2 — History tables are named `<base_table_singular>_history` (never `_versions`, `_archive`,
`_log` — those words are reserved for other concepts elsewhere in the platform: `_log` collides with
`audit_logs`'s domain, `_archive` implies the live row was removed, which history tables never do).**

```sql
-- GOOD
CREATE TABLE exchange_rate_history (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    currency_code  CHAR(3) NOT NULL,
    rate           NUMERIC(18,6) NOT NULL,
    effective_from TIMESTAMPTZ NOT NULL,
    effective_to   TIMESTAMPTZ NULL,
    recorded_by_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_exchange_rate_history_company_currency
    ON exchange_rate_history (company_id, currency_code, effective_from);

-- BAD
CREATE TABLE exchange_rate_versions ( ... );   -- inconsistent suffix, competes with 'history' elsewhere
CREATE TABLE exchange_rate_log ( ... );          -- collides conceptually with audit_logs's domain
```

**H3 — Every history table uses the `effective_from`/`effective_to` (both `TIMESTAMPTZ`, `effective_to
NULL` meaning "current") pair to model validity ranges — never a bare `is_current` boolean alone
without the range, because a boolean cannot answer "what was true as of 2026-03-01" once several
versions have accumulated.**

```sql
-- GOOD — answers point-in-time queries directly
SELECT rate FROM exchange_rate_history
WHERE company_id = 7 AND currency_code = 'USD'
  AND effective_from <= '2026-03-01' AND (effective_to IS NULL OR effective_to > '2026-03-01');

-- BAD — 'is_current' alone cannot reconstruct history at an arbitrary past instant
CREATE TABLE exchange_rate_history (
    rate NUMERIC(18,6),
    is_current BOOLEAN
);
```

**H4 — A history table's rows are NEVER updated or deleted after insertion (append-only); the ONLY
mutation permitted is closing the previous row's `effective_to` when a new version is inserted, done
inside the same transaction/function that inserts the new row (`apply_new_exchange_rate(...)`,
following **F1**'s verb-first grammar).**

**H5 — For tables where PostgreSQL's native temporal features are more appropriate than a hand-rolled
history table (range types + `EXCLUDE` constraints, per **CK4**, e.g. `fiscal_periods`), prefer the
native approach over building yet another manual history table — do not reach for `<table>_history` by
default when a `tstzrange`/`daterange` column with a GiST exclusion constraint already solves the
overlap-free versioning problem on the base table itself.**

# Sequences

**S1 — Every `id BIGINT GENERATED ALWAYS AS IDENTITY` column has an implicit backing sequence managed
automatically by PostgreSQL; QAYD never creates a bare, manually-managed `CREATE SEQUENCE` for a
primary key — always use `GENERATED ALWAYS AS IDENTITY`, never `SERIAL`, and never a hand-rolled
sequence + `DEFAULT nextval(...)` for a primary key.**

```sql
-- GOOD
id BIGINT GENERATED ALWAYS AS IDENTITY

-- BAD (legacy pattern, avoid in all new tables)
id SERIAL PRIMARY KEY

-- WORSE (manual sequence management for something IDENTITY already solves cleanly)
CREATE SEQUENCE seq_customers_id;
id BIGINT DEFAULT nextval('seq_customers_id')
```

**S2 — Explicit, manually-created sequences ARE used, deliberately, for human-facing DOCUMENT NUMBERS
that must be gap-free-ish, per-company, and resettable per fiscal year — e.g. invoice numbering,
journal entry numbering — because these have business meaning distinct from the surrogate primary
key. Such sequences are named `seq_<document>_number_<scope>`.**

```sql
-- GOOD — one sequence per company per fiscal year, so numbering resets cleanly at year boundaries
-- and never collides across tenants
CREATE SEQUENCE seq_invoice_number_company_7_fy_2026 START 1 INCREMENT 1;

-- In practice these are provisioned dynamically per (company_id, fiscal_year_id) by a Laravel
-- service (NumberingService::sequenceFor($companyId, $fiscalYearId, 'invoice')), which composes
-- the sequence name deterministically:
-- seq_invoice_number_company_<company_id>_fy_<fiscal_year_id>
```

**S3 — Because dynamically-provisioned per-tenant sequences (S2) can proliferate into thousands of
objects, QAYD's actual numbering mechanism for high-volume documents uses a dedicated counter TABLE
(`document_number_counters`) with row-level locking (`SELECT ... FOR UPDATE`) rather than one
PostgreSQL `SEQUENCE` object per company per document type per year — the `seq_` naming convention in
S2 remains correct for the rare cases where a true DDL sequence is used (e.g. a single global
`seq_ai_conversation_reference`), but the primary numbering mechanism is the counter-table pattern
below, and that table itself follows ordinary **Tables**/**Columns** rules, not sequence-naming rules.**

```sql
-- GOOD — scalable numbering without one Postgres SEQUENCE per tenant per year
CREATE TABLE document_number_counters (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    document_type  TEXT NOT NULL,          -- 'invoice' | 'bill' | 'journal_entry' | ...
    fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
    next_number    BIGINT NOT NULL DEFAULT 1,
    CONSTRAINT uq_document_number_counters_scope
        UNIQUE (company_id, document_type, fiscal_year_id)
);
```

**S4 — Never expose a raw sequence-generated surrogate `id` value AS the human document number
(`invoices.number := invoices.id::text`) — the two are different concepts (S1 vs S2/S3) with different
lifecycles, and conflating them leaks internal row counts and breaks the moment numbering needs to
restart per fiscal year while `id` never does.**

# Migrations

**M1 — Laravel migration files follow Laravel's own timestamp-prefixed convention
(`<timestamp>_<snake_case_description>.php`), and the snake_case description embeds the exact verb +
table + intent, matching the SQL it produces so a `git log` of `database/migrations/` is a readable
history of schema evolution.**

```
-- GOOD
2026_07_16_093000_create_invoices_table.php
2026_07_16_093500_create_invoice_items_table.php
2026_07_20_140000_add_tax_number_to_customers_table.php
2026_08_01_090000_add_uq_invoices_company_number_to_invoices_table.php
2026_08_05_101500_create_exchange_rate_history_table.php

-- BAD
2026_07_16_093000_invoices.php                 -- no verb, ambiguous whether create/alter
2026_07_16_093500_fix.php                       -- meaningless description
2026_07_20_140000_update.php                    -- meaningless description
```

**M2 — One migration = one coherent schema change. A migration that creates a table also creates
every index and constraint that table needs on day one, in the same `up()` method, in the order:
columns, primary key, foreign keys, check constraints, unique constraints, then indexes — matching the
top-to-bottom order of THIS document's sections, so a migration file is naturally organized the same
way this specification is.**

```php
// GOOD — database/migrations/2026_07_16_093000_create_invoices_table.php
public function up(): void
{
    Schema::create('invoices', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'));
        $table->foreignId('company_id')->constrained('companies');
        $table->foreignId('branch_id')->nullable()->constrained('branches');
        $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
        $table->string('number');
        $table->char('currency_code', 3);
        $table->decimal('exchange_rate', 18, 6)->default(1);
        $table->decimal('subtotal_amount', 19, 4);
        $table->decimal('tax_amount', 19, 4)->default(0);
        $table->decimal('total_amount', 19, 4);
        $table->string('status')->default('draft');
        $table->jsonb('custom_fields')->default('{}');
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestampsTz();
        $table->softDeletesTz();
    });

    DB::statement('ALTER TABLE invoices ADD CONSTRAINT uq_invoices_uuid UNIQUE (uuid)');
    DB::statement('ALTER TABLE invoices ADD CONSTRAINT uq_invoices_company_number UNIQUE (company_id, number)');
    DB::statement('ALTER TABLE invoices ADD CONSTRAINT ck_invoices_total_non_negative CHECK (total_amount >= 0)');
    DB::statement('CREATE INDEX idx_invoices_company_id ON invoices (company_id)');
    DB::statement('CREATE INDEX idx_invoices_customer_id ON invoices (customer_id)');
    DB::statement('CREATE INDEX idx_invoices_company_status ON invoices (company_id, status) WHERE deleted_at IS NULL');
    DB::statement('CREATE INDEX gin_invoices_custom_fields ON invoices USING GIN (custom_fields)');
}

public function down(): void
{
    Schema::dropIfExists('invoices');
}
```

**M3 — Every migration is reversible: `down()` is implemented for real, never left as an empty
method or a `throw new Exception('irreversible')` placeholder, EXCEPT for migrations that
irreversibly transform already-posted financial data, which instead document in a comment exactly why
reversal is unsafe and what manual recovery procedure exists.**

**M4 — Enum-altering migrations that use native PostgreSQL types isolate the `ALTER TYPE ... ADD
VALUE` statement in its OWN migration file, never combined with other DDL, because Postgres disallows
running it inside a transaction block alongside other statements in some execution paths — Laravel
wraps migrations in transactions by default, so this migration explicitly disables that wrapping.**

```php
// GOOD — database/migrations/2026_09_01_100000_add_pending_review_to_journal_entry_status_enum.php
public $withinTransaction = false;

public function up(): void
{
    DB::statement("ALTER TYPE journal_entry_status ADD VALUE IF NOT EXISTS 'pending_review' AFTER 'draft'");
}
```

**M5 — Renaming a column or table is always a two-migration (or more) deprecation sequence, never a
single blind `RENAME`, when the identifier is already referenced by application code across multiple
deployed services: (1) add the new name as an additional column/view alongside the old, backfill, dual
-write; (2) once all readers/writers have moved, drop the old name in a later migration. Name the
migrations to reflect each discrete step, e.g. `..._add_total_amount_alias_to_invoices_table.php` then
`..._drop_deprecated_total_column_from_invoices_table.php`.**

**M6 — Data-only migrations (backfills, one-time corrections) are named with a `backfill_` or
`fix_data_` verb so they are visibly distinct, in a `git log` or a migrations table listing, from
structural schema migrations, and they are idempotent (safe to run twice) by construction —
`WHERE column IS NULL` guards, `ON CONFLICT DO NOTHING`, etc.**

```
-- GOOD
2026_09_10_083000_backfill_base_currency_amount_on_invoices_table.php

-- BAD
2026_09_10_083000_fix.php
```

# Comprehensive Examples

The following worked example ties every section above together across three related tables from the
Sales module — `sales_orders`, `sales_order_items`, and the `sales_order_status` enum they depend on —
showing every naming rule applied simultaneously in one internally consistent migration.

```sql
-- 1. Enum (see Enums E1-E3)
CREATE TYPE sales_order_status AS ENUM (
    'draft', 'confirmed', 'partially_delivered', 'delivered', 'invoiced', 'cancelled'
);

-- 2. Parent table (see Tables T1, Columns C1-C13, Primary Keys PK1-PK2, Foreign Keys FK1-FK4, UUIDs U1-U2)
CREATE TABLE sales_orders (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY,
    uuid                 UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id           BIGINT NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    branch_id            BIGINT NULL REFERENCES branches(id) ON DELETE SET NULL,
    customer_id          BIGINT NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    salesperson_id       BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    approved_by_id       BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    cost_center_id       BIGINT NULL REFERENCES cost_centers(id) ON DELETE SET NULL,
    project_id           BIGINT NULL REFERENCES projects(id) ON DELETE SET NULL,
    number               TEXT NOT NULL,
    order_date           DATE NOT NULL,
    delivery_date         DATE NULL,
    currency_code        CHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate        NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    discount_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount           NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_currency_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    status               sales_order_status NOT NULL DEFAULT 'draft',
    is_confirmed         BOOLEAN NOT NULL DEFAULT false,
    notes                TEXT NULL,
    tags                 JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by           BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by           BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at           TIMESTAMPTZ NULL,
    CONSTRAINT pk_sales_orders PRIMARY KEY (id),
    CONSTRAINT uq_sales_orders_uuid UNIQUE (uuid),
    CONSTRAINT uq_sales_orders_company_number UNIQUE (company_id, number),
    CONSTRAINT ck_sales_orders_total_non_negative CHECK (total_amount >= 0),
    CONSTRAINT ck_sales_orders_delivery_after_order
        CHECK (delivery_date IS NULL OR delivery_date >= order_date)
);

CREATE INDEX idx_sales_orders_company_id ON sales_orders (company_id);
CREATE INDEX idx_sales_orders_customer_id ON sales_orders (customer_id);
CREATE INDEX idx_sales_orders_company_status ON sales_orders (company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_sales_orders_salesperson_id ON sales_orders (salesperson_id);
CREATE INDEX gin_sales_orders_custom_fields ON sales_orders USING GIN (custom_fields);
CREATE INDEX gin_sales_orders_tags ON sales_orders USING GIN (tags);

-- 3. Child table (see Tables T3, Foreign Keys FK1-FK2)
CREATE TABLE sales_order_items (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id         BIGINT NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    sales_order_id     BIGINT NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
    product_id         BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    warehouse_id       BIGINT NULL REFERENCES warehouses(id) ON DELETE SET NULL,
    quantity_ordered   NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_delivered NUMERIC(18,4) NOT NULL DEFAULT 0,
    unit_price_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    discount_percentage NUMERIC(5,2) NOT NULL DEFAULT 0,
    tax_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
    line_total_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at         TIMESTAMPTZ NULL,
    CONSTRAINT pk_sales_order_items PRIMARY KEY (id),
    CONSTRAINT ck_sales_order_items_quantity_ordered_positive CHECK (quantity_ordered >= 0),
    CONSTRAINT ck_sales_order_items_delivered_not_exceeding_ordered
        CHECK (quantity_delivered <= quantity_ordered)
);

CREATE INDEX idx_sales_order_items_company_id ON sales_order_items (company_id);
CREATE INDEX idx_sales_order_items_sales_order_id ON sales_order_items (sales_order_id);
CREATE INDEX idx_sales_order_items_product_id ON sales_order_items (product_id);

-- 4. Trigger and function (see Triggers TR1-TR4, Functions F1-F3)
CREATE FUNCTION recalculate_sales_order_totals() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    v_sales_order_id BIGINT := COALESCE(NEW.sales_order_id, OLD.sales_order_id);
BEGIN
    UPDATE sales_orders so
    SET subtotal_amount = agg.subtotal_amount,
        tax_amount       = agg.tax_amount,
        total_amount     = agg.subtotal_amount + agg.tax_amount,
        updated_at       = now()
    FROM (
        SELECT
            COALESCE(SUM(line_total_amount), 0) AS subtotal_amount,
            COALESCE(SUM(tax_amount), 0)         AS tax_amount
        FROM sales_order_items
        WHERE sales_order_id = v_sales_order_id AND deleted_at IS NULL
    ) agg
    WHERE so.id = v_sales_order_id;
    RETURN NULL;
END;
$$;

CREATE TRIGGER trg_sales_order_items_after_insert_recalculate_totals
    AFTER INSERT ON sales_order_items
    FOR EACH ROW EXECUTE FUNCTION recalculate_sales_order_totals();
CREATE TRIGGER trg_sales_order_items_after_update_recalculate_totals
    AFTER UPDATE ON sales_order_items
    FOR EACH ROW EXECUTE FUNCTION recalculate_sales_order_totals();
CREATE TRIGGER trg_sales_order_items_after_delete_recalculate_totals
    AFTER DELETE ON sales_order_items
    FOR EACH ROW EXECUTE FUNCTION recalculate_sales_order_totals();

-- 5. View (see Views V1-V3)
CREATE VIEW vw_sales_orders_pending_delivery AS
SELECT
    so.id, so.uuid, so.company_id, so.customer_id, so.number,
    so.total_amount, so.currency_code, so.status
FROM sales_orders so
WHERE so.status IN ('confirmed', 'partially_delivered')
  AND so.deleted_at IS NULL;
```

# Anti-Patterns

This section catalogs the specific mistakes most likely to be introduced by a rushed engineer or an
under-specified AI code-generation pass, each with the concrete failure it causes.

**AP1 — The "God lookup table."** A single `lookups`/`types`/`codes` table storing every enumeration
in the system, discriminated by a `kind`/`category` column. This defeats foreign key integrity (a
`type_id` column can reference the wrong `kind` and Postgres will never catch it), defeats
`CHECK`/enum typing, and makes every join ambiguous about which subset of `lookups` is relevant. Use
dedicated tables (`account_types`, `tax_codes`) or native enums instead (**T4**, **E1**).

**AP2 — Mixed casing / mixed pluralization within the same schema.** One table `customer` (singular)
and another `Invoices` (PascalCase) in the same database is not a hypothetical — it is the single most
common real-world consequence of multiple engineers or multiple AI generation passes working without
this document. Every PR touching DDL must be checked against **Tables**/**Columns** before merge.

**AP3 — Storing money as `FLOAT`/`DOUBLE PRECISION` or `INTEGER` cents without saying so.** `FLOAT`
introduces binary rounding error that WILL eventually misstate a balanced journal entry by fractions
of a cent, which is catastrophic in an accounting system where debits must equal credits exactly.
`NUMERIC(19,4)` is mandatory, per the shared design context, for every monetary column, with no
exceptions for "it's just a display total."

```sql
-- BAD
total_amount DOUBLE PRECISION

-- GOOD
total_amount NUMERIC(19,4)
```

**AP4 — Skipping `currency_code` because "we only support KWD today."** Every money column added
without `currency_code` today becomes a company-wide multi-currency migration tomorrow, touching every
row and every report. Add it from day one on every monetary table, defaulted to the company's base
currency, per **C5**.

**AP5 — Nullable foreign keys used as a substitute for a real optional relationship model.** If
`invoices.customer_id` is sometimes null because "some invoices don't have a customer yet," that is
model confusion, not a nullable column problem — either the business rule genuinely allows a customer
-less invoice (document it, keep it nullable, per **C** rules) or drafts should not exist as `invoices`
rows at all until a customer is attached. Do not paper over an unclear business rule with `NULL`.

**AP6 — Hard-deleting financial rows "to keep the table small."** Violates the shared design context's
immutability rule directly. Every financial table has `deleted_at`; `DELETE FROM invoices WHERE ...`
must never appear in application code or migrations for a posted financial document — only
`UPDATE ... SET deleted_at = now()`.

**AP7 — Encoding business logic in a column name instead of a value.** Columns named
`is_paid_full`, `is_paid_partial`, `is_overdue_30`, `is_overdue_60` as separate booleans instead of one
`status invoice_status` enum plus a derived `age_days` calculation. This multiplies columns instead of
modeling state, and the booleans WILL drift out of sync with each other over time since nothing
enforces their mutual exclusivity.

**AP8 — Using `VARCHAR(n)` with an arbitrary length cap "just in case," instead of `TEXT`.**
PostgreSQL's `TEXT` and `VARCHAR(n)` have identical storage and performance; an arbitrary `VARCHAR(50)`
on a `name_en` column only adds a future migration when a legitimate name is 51 characters. Use `TEXT`
everywhere except where a real, business-mandated length limit exists (e.g. `currency_code CHAR(3)`
is correct because ISO 4217 codes are exactly three characters, not an arbitrary cap).

**AP9 — Composite indexes in the wrong column order.** An index on `(status, company_id)` is nearly
useless for the platform's dominant query pattern `WHERE company_id = ? AND status = ?`, because
`company_id` must be the LEADING column (per **IDX2**) for the tenant-isolation filter to use the
index efficiently across the whole table; `company_id` always leads a composite index unless a
documented, measured query pattern proves otherwise.

**AP10 — Silent unit mismatches between `_rate` and `_percentage` columns (C10/C11).** Storing
`tax_rate = 5` intending "5 percent" in a column typed and named as a fractional `_rate` is a silent
100x error the first time it multiplies into a total. Naming discipline is the primary defense: if a
column is named `_rate`, every writer must store the fraction; if named `_percentage`, every writer
stores the human number. Mixing the two conventions under a renamed column is an anti-pattern that has
caused real production incidents industry-wide and is exactly what this document exists to prevent.

**AP11 — Defining a foreign key without an index (IDX4) and discovering it only under load.**
PostgreSQL does not index foreign keys automatically. A `DELETE FROM customers WHERE id = ?` with no
index on `invoices.customer_id` forces a full sequential scan of `invoices` to check the `RESTRICT`
constraint, turning what should be a millisecond operation into a multi-second lock-held scan on a
growing financial table. Every foreign key column is indexed, without exception, as a matter of
mechanical migration hygiene, not case-by-case judgment.

**AP12 — Reusing a dropped column's old name for a new, differently-typed column in a later
migration.** If `invoices.total` was dropped in 2026 (having stored `DOUBLE PRECISION`, per AP3) and a
column literally named `total` is reintroduced in 2027 as `NUMERIC(19,4)`, any stale application code,
cached query, or report definition still referencing the old semantics will silently misbehave rather
than error. Retired names are retired permanently; use a new name (`total_amount`, per this document's
own **C5** rule) going forward.

# End of Document
