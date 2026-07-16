# General Ledger — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: General Ledger
---

# Purpose

The General Ledger (GL) is the central financial record of record for a company within QAYD. Every economic event that touches the books of the company — a sale, a purchase, a bank movement, a payroll run, an inventory revaluation, a tax accrual, a manual adjustment — ultimately resolves into one or more **journal entries**, and every posted journal entry resolves into one or more **ledger entries** against the **chart of accounts**. The General Ledger module owns the posting engine, the account tree, the fiscal calendar, the balance-computation logic, and the reporting surface (Trial Balance, Account Statement, Balance Sheet inputs, Income Statement inputs) that every other module in QAYD ultimately depends on to answer the question "what does the business actually own, owe, earn, and spend, as of any given moment."

This document specifies the General Ledger submodule of the Accounting Engine to implementation-ready depth: the double-entry posting engine (real-time, scheduled, and batch), the PostgreSQL data model (including the `ledger_entries` projection and the materialized views that make reporting fast at scale), the search and reporting surface, the RBAC permission model, the AI agent responsibilities and their guardrails, and the versioned REST API. It is written so that a backend engineer building the Laravel service layer, a frontend engineer building the Next.js ledger screens, and an AI engineer building the FastAPI reasoning layer can each implement their portion without needing to ask a design question that isn't already answered here.

The General Ledger is not a UI feature bolted onto a database — it is the settlement layer of the entire platform. Sales, Purchases, Banking, Inventory, Payroll, and Tax do not maintain their own notion of "the company's money." They each raise domain events; the Accounting Engine listens, and the General Ledger is where those events become permanent, auditable, immutable financial history. Everything downstream — financial statements, board reporting, tax filings, external audit, AI-driven anomaly detection — reads from this ledger and only this ledger.

# Vision

QAYD's General Ledger is designed to be the ledger an external auditor trusts without needing to interview the engineering team. Three properties drive every design decision in this document:

1. **Single source of truth, single direction of derivation.** `journal_entries` and `journal_lines` are the only tables that hold primary accounting data. `ledger_entries` is a deterministic, rebuildable projection of posted `journal_lines`. Account balances, Trial Balances, and financial statements are always computed (or cached) from posted lines — never hand-edited, never stored as an independent number that could drift from the entries that justify it. If `ledger_entries` were dropped and rebuilt from `journal_lines` tomorrow, every report in the system would produce byte-identical output.

2. **Immutability with full correction traceability.** Once a journal entry transitions to `posted`, its lines never change. Every correction — a data-entry mistake, a reclassification, a year-end adjustment, a full reversal — is itself a new, fully attributed journal entry that links back to what it corrects. A GL that lets you silently edit history is a GL an auditor cannot certify; QAYD's GL makes editing history structurally impossible and correction structurally cheap.

3. **AI as a tireless second set of eyes, never as an unsupervised hand.** The AI layer (FastAPI) continuously reads the ledger to explain activity, flag anomalies, predict likely miscodings, and forecast trends — but it never posts, never approves, and never touches the database directly. Every AI-authored suggestion is a draft that a human with the correct permission must approve before it becomes an entry. The vision is a GL that gets smarter every day without ever becoming a black box.

The long-term vision extends this ledger to multi-company consolidated reporting (elimination entries between related companies), continuous close (real-time period-end estimates rather than a monthly scramble), and an AI close-assistant that can produce a fully substantiated first draft of the monthly close memo — all built on the same immutable, event-sourced foundation described here.

# Accounting Concepts

This section defines, precisely and unambiguously, the accounting vocabulary that the rest of this document — and the code that implements it — relies on. Every term below maps to a specific column, computation, or state transition in the Data Model and Posting Engine sections that follow.

## General Ledger

The General Ledger is the complete, chronological, double-entry record of every posted financial transaction of a company, organized by account. It is not a single table in the everyday sense — architecturally in QAYD it is the **logical view** produced by joining posted `journal_lines` to `accounts`, `fiscal_periods`, and the relevant dimensions (`cost_centers`, `projects`, `departments`, `branches`). The physical `ledger_entries` table exists purely as a performance projection of that logical view (see Data Model). When this document says "the GL records X," it means: a row exists in `journal_lines` belonging to a `posted` `journal_entries` row, and that row is reflected in `ledger_entries`.

## Posting

Posting is the irreversible act of transitioning a `journal_entries` row from `draft` to `posted`. Posting is the single event that:
- Validates that `SUM(debit_amount) = SUM(credit_amount)` across all lines of the entry, to the cent, in both transaction currency and base currency.
- Validates that every account referenced is `active`, is a postable (leaf) account, and belongs to an open `fiscal_period`.
- Locks the entry and its lines against further edits (`is_locked = true` is implied by `status = 'posted'`; the API rejects any `PATCH` to a posted entry's lines).
- Writes one `ledger_entries` row per `journal_line`, and enqueues the balance-cache and materialized-view refresh jobs described in Posting Engine and Performance.
- Emits the domain event `journal.posted` on the internal event bus, which other modules (Sales AR reconciliation, Banking, Reporting) may subscribe to.

Posting is what separates a journal entry that exists (as a draft, for review) from a journal entry that counts (as history).

## Account Activity

Account Activity is the set of all `journal_lines` (via `ledger_entries`) posted against a specific `account_id`, optionally filtered by date range, fiscal period, branch, currency, or dimension. Account Activity is the raw material for the Account Statement report and for the "explain this balance" AI capability — every number on every financial statement must be traceable, line by line, back to the Account Activity that produced it.

## Balance Forward

Balance Forward (also called Brought-Forward Balance) is the net balance of an account carried into a fiscal period from all prior posted activity. It is computed, never stored as an independent editable number:

```
balance_forward(account, period) =
    opening_balance(account, fiscal_year_of(period))
    + SUM(period_activity(account, p) for p in periods before `period` in the same fiscal year)
```

Balance Forward for the first period of a fiscal year is exactly the fiscal year's Opening Balance for that account.

## Running Balance

Running Balance is the balance of an account after each individual posted line, in chronological (posting) order. It is the "balance" column shown on an Account Statement: `running_balance[i] = running_balance[i-1] ± line[i].signed_amount`, seeded by Balance Forward at the start of the requested range. Running Balance is always computed at query time (or served from the `mv_account_running_balance` cache described in Performance) — it is never persisted per-line, because persisting it per-line would require rewriting every subsequent row on every new posting, which is exactly the kind of mutable derived state this ledger is designed to avoid.

## Closing Balance

Closing Balance is the Running Balance as of the last posted line within a given period or date range — i.e., the balance an account carries at the end of a period, before any activity in the following period. `closing_balance(account, period) = balance_forward(account, period) + period_activity(account, period)`. For Balance-Sheet accounts (Assets/Liabilities/Equity), the Closing Balance of fiscal year N becomes the Opening Balance of fiscal year N+1. For Income-Statement accounts (Revenue/Expense), the Closing Balance is swept to zero at year-end via Closing Entries (see Ledger Architecture) and the corresponding balance is transferred to the Retained Earnings equity account.

## Opening Balance

Opening Balance is the balance an account carries into a fiscal year. For a brand-new company or a brand-new account, Opening Balance is established by a dedicated `journal_entries.entry_type = 'opening_balance'` entry posted to the first period of the first fiscal year the account participates in. For continuing years, the Opening Balance of Balance-Sheet accounts is the prior year's Closing Balance, mechanically carried forward by the year-end Closing Entries process — never manually retyped.

## Period Activity

Period Activity is the net movement (`SUM(debit) - SUM(credit)` for debit-normal accounts, or the inverse for credit-normal accounts) of an account within one specific `fiscal_period`, drawn exclusively from `journal_lines` on `posted` entries dated within that period's `start_date`/`end_date`. Period Activity is the figure shown in monthly/quarterly movement columns on the Ledger Summary and Movement reports.

## Historical Ledger

The Historical Ledger is the full, permanent, queryable record of every posted entry ever made in the company's lifetime, across every closed and open fiscal year. Because posted entries are never deleted (soft-delete is only ever applied via a compensating reversal, never a physical or logical hide of history — see Business Rules), the Historical Ledger is simply "all rows in `ledger_entries` with no date filter." Closed fiscal years and periods do not remove their entries from the Historical Ledger; closing only prevents new postings into that period going forward.

## Audit Trail

The Audit Trail is the append-only record of every state-changing action taken against accounting data: who created a draft, who edited a draft line, who posted it, who reversed it, and — separately from the accounting record itself — who merely *viewed* a sensitive report. It is implemented via the foundation `audit_logs` table (old value, new value, actor, timestamp, IP, device — see Security) plus the accounting-specific `created_by`/`updated_by`/`posted_by`/`reversed_by` columns on `journal_entries`. The Audit Trail answers "who did this and when" for compliance and forensic purposes; the ledger itself (via reversing entries) answers "what is the corrected financial truth."

# Business Goals

1. **Zero silent balance drift.** At any moment, for any account, `SUM(ledger_entries.signed_base_amount)` for that account must equal the account's cached balance in `mv_account_balances` to the cent. Any divergence is a P0 data-integrity incident, not a reporting nuisance.
2. **Sub-second Trial Balance for companies with 10M+ posted lines.** Achieved via `ledger_entries` projection, per-period balance materialized views, and B-tree/BRIN indexing strategy (see Performance).
3. **Close the books in days, not weeks.** Automate 80% of period-end adjusting/accrual/closing entries via AI-drafted, human-approved templates; provide a real-time "period close readiness" score.
4. **Full external-audit defensibility with zero manual reconstruction.** Every number on every financial statement must drill down, in the UI, to the exact journal entry and source document that produced it, in at most three clicks.
5. **Multi-entity scale from day one.** A single QAYD deployment must support one company with one ledger, or a group of 50 related companies each with an isolated ledger, without a data-model change — only configuration (see Multi Company).
6. **AI-assisted, human-governed.** Reduce manual coding and reconciliation effort by giving accountants AI-drafted journal entries, anomaly flags, and natural-language "explain this number" answers — while guaranteeing no AI output becomes an accounting fact without a human (or a pre-approved policy) explicitly approving it.
7. **Currency-correct globally, Gulf-correct by default.** Every posted line is unambiguous in both transaction currency and company base currency (default KWD, with AED/SAR/USD supported), with the exchange rate used permanently recorded on the line, never recomputed retroactively.

# Definitions

| Term | Definition |
|---|---|
| **Journal Entry** | A `journal_entries` row: a dated, described, balanced set of debit/credit lines representing one financial event. |
| **Journal Line** | A `journal_lines` row: one debit or one credit against one account, within one journal entry. |
| **Chart of Accounts (COA)** | The `accounts` table: the hierarchical tree of every account a company can post to, rooted in the five `account_types` (Asset, Liability, Equity, Revenue, Expense). |
| **Postable Account / Leaf Account** | An account with no children (`is_leaf = true`) that permitted to receive journal lines directly. Parent (summary) accounts are never posted to directly; their balance is the sum of their descendants. |
| **Control Account** | A GL account (e.g., Accounts Receivable, Accounts Payable) that must always reconcile to the sum of its sub-ledger (Customers or Vendors respectively). QAYD enforces this via the reconciliation check described in Business Rules. |
| **Normal Balance** | The side (debit or credit) on which an account type's balance is expected to increase. Assets & Expenses are debit-normal; Liabilities, Equity & Revenue are credit-normal. |
| **Fiscal Year** | A `fiscal_years` row: a company-configured 12-month (or short/long transition-year) accounting period, e.g. 2026-01-01 to 2026-12-31, or a non-calendar year such as 2026-04-01 to 2027-03-31. |
| **Fiscal Period** | A `fiscal_periods` row: a sub-division of a fiscal year (typically monthly) with its own `open`/`closed`/`locked` status, into which journal entries are dated and posted. |
| **Draft Entry** | A `journal_entries` row with `status = 'draft'`: fully editable, not yet reflected in any balance or report, visible only to its author and to users with `accounting.journal.read`. |
| **Posted Entry** | A `journal_entries` row with `status = 'posted'`: immutable, reflected in `ledger_entries`, balances, and every report. |
| **Reversing Entry** | A new journal entry that posts the exact mirror image (debits and credits swapped) of a previously posted entry, used to fully undo its financial effect while preserving history. |
| **Adjusting Entry** | A journal entry made at period-end to recognize revenue/expense in the correct period (accruals, prepayments, depreciation, provisions) independent of cash movement. |
| **Closing Entry** | A year-end journal entry that zeroes every Income-Statement account by transferring its balance to Retained Earnings, so the new fiscal year's P&L accounts start at zero. |
| **Ledger Entry** | An `ledger_entries` row: the immutable, denormalized projection of one posted `journal_line`, used for fast balance and report queries. |
| **Dimension** | An optional analytical tag on a journal line — `cost_center_id`, `project_id`, `department_id`, `branch_id` — that allows slicing the same GL data by business dimension without duplicating accounts. |
| **Trial Balance** | The report listing every account's debit or credit closing balance as of a point in time; total debits must equal total credits, always. |
| **Base Currency** | The company's functional reporting currency (`companies.base_currency`, e.g. KWD), in which every ledger balance and financial statement is ultimately expressed. |
| **Transaction Currency** | The currency in which the underlying economic event was denominated (`journal_lines.currency_code`), which may differ from base currency. |

# Business Rules

1. **Balance rule.** For every `journal_entries` row with `status = 'posted'`: `SUM(journal_lines.debit_amount) = SUM(journal_lines.credit_amount)` in base currency, exactly, to 4 decimal places, and this sum is re-verified server-side at posting time regardless of what the client submitted.
2. **No direct edits to posted data.** `UPDATE` and `DELETE` on `journal_lines` are forbidden by database trigger (`trg_journal_lines_immutable`) once the parent entry is `posted`. The only sanctioned mutation path for posted financial history is a new reversing or adjusting entry.
3. **No posting to non-leaf accounts.** The database enforces `accounts.is_leaf = true` via a `CHECK`-backed trigger before any `journal_lines.account_id` insert; summary/parent accounts exist only to roll up children in reports.
4. **No posting to a closed or locked period.** `journal_entries.entry_date` must fall within a `fiscal_periods` row whose `status = 'open'`. Posting into a `closed` or `locked` period is rejected with `422` unless the actor holds `accounting.period.reopen` and explicitly reopens the period first (itself an audited action).
5. **No posting to an inactive account.** `accounts.status` must be `active` at the moment of posting (not merely at draft creation) — an account deactivated after a draft was created blocks that draft from posting until reassigned.
6. **Currency consistency per line.** Every `journal_lines` row carries `currency_code`, `exchange_rate`, `amount` (transaction currency) and `base_amount` (= `amount * exchange_rate`, rounded half-up to `base_currency_decimals`). `exchange_rate` is captured at document date and frozen forever on that line — it is never recalculated retroactively even if the "current" rate changes.
7. **Control account reconciliation.** Any account flagged `accounts.is_control_account = true` (AR, AP) may only be posted to by the Sales/Purchases module's automated journal-posting service (via `journal_entries.source_type IN ('invoice','bill','receipt','vendor_payment', ...)`), never by manual journal entry, unless the actor holds `accounting.control_account.override` (reserved for period-end reconciling adjustments, always logged with a mandatory reason).
8. **Reversal, not deletion.** "Deleting" a posted entry via the API (`DELETE /journal-entries/{id}`) does not delete rows; it creates a full reversing entry dated on or after the original and soft-deletes only the *visibility flag* of the original for list views, while the original row and its lines remain physically present and queryable in the Historical Ledger and Audit Trail forever.
9. **Draft entries auto-expire.** A `draft` entry not touched for 90 days is flagged `stale` and surfaced to its author and Finance Manager; it is never auto-deleted, only auto-flagged, since even an abandoned draft is a record of intent that may matter to an auditor.
10. **Multi-currency rounding differences post to a dedicated account.** Any residual rounding difference (max ±0.01 in base currency, arising from multi-line FX conversion) is automatically posted to `accounts.code = '9999'` ("Rounding Differences") rather than silently absorbed into any operating account, and is visible on every Trial Balance.
11. **Every automated (module-originated) entry must reference its source document.** `journal_entries.source_type` and `source_id` are mandatory when `origin = 'system'`; a system-originated entry with no traceable source is a data-integrity violation the posting engine refuses to commit.
12. **Fiscal year closing is sequential.** Fiscal Year N+1 cannot be opened for regular transactional posting until Fiscal Year N's Closing Entries have been posted (or the company explicitly operates in "parallel open years" mode, permission-gated to `accounting.fiscal_year.parallel_open`).
13. **Dimensions are optional but immutable once posted.** `cost_center_id`, `project_id`, `department_id` on a `journal_line` may be null at draft time validation-permitting, but once posted they follow the same immutability rule as every other column on the line — a dimension re-tag is a reclassifying journal entry, not an edit.
14. **AI never posts.** Any `journal_entries` row with `created_by_agent IS NOT NULL` is created in `status = 'draft'` unconditionally; the database trigger `trg_no_ai_autopost` rejects any attempt to insert such a row directly as `posted`, regardless of caller.

# Ledger Architecture

## Ledger Structure

QAYD's ledger is a **single logical ledger per company**, built from two physical layers:

1. **System of record layer** — `journal_entries` + `journal_lines`. This is where every accounting fact is born and where corrections happen.
2. **Read/reporting layer** — `ledger_entries` (row-level projection, one row per posted `journal_line`) plus a set of materialized views (`mv_account_balances`, `mv_trial_balance`, `mv_account_running_balance`) that pre-aggregate the projection for the query patterns the Reports section describes.

There is exactly one `ledger_entries` row per posted `journal_lines` row (a 1:1 projection, not a summarization), which is what makes it safely rebuildable: `TRUNCATE ledger_entries; INSERT INTO ledger_entries SELECT ... FROM journal_lines jl JOIN journal_entries je ON ... WHERE je.status = 'posted';` is a valid, idempotent, zero-data-loss disaster-recovery procedure, and is in fact how the nightly integrity-check job verifies the projection is correct (see Security).

## Ledger Hierarchy

Accounts form a strict tree via `accounts.parent_id`, rooted at the five `account_types`. A typical hierarchy:

```
1000 Assets (type, non-postable)
 ├─ 1100 Current Assets (summary, non-postable)
 │   ├─ 1110 Cash and Cash Equivalents (summary)
 │   │   ├─ 1111 Cash on Hand (KWD) — leaf, postable
 │   │   └─ 1112 Petty Cash — leaf, postable
 │   ├─ 1120 Bank Accounts (summary)
 │   │   ├─ 1121 NBK Current Account — KWD — leaf
 │   │   └─ 1122 Al Rajhi Current Account — SAR — leaf
 │   └─ 1130 Accounts Receivable (summary, control account parent)
 │       └─ 1131 Trade Receivables — leaf, is_control_account=true
 └─ 1200 Non-Current Assets (summary)
     └─ 1210 Property & Equipment — leaf
```

Reports roll balances up this tree: a Balance Sheet line for "Current Assets" is the recursive sum of every leaf descendant's Closing Balance, computed via the recursive CTE shown in Data Model. The tree depth is unbounded in the schema but conventionally capped at 5 levels by UI validation (`accounting.chart_of_accounts.max_depth` company setting, default 5) to keep reports legible.

## Multi Company

Every table in this module carries `company_id NOT NULL`. There is no cross-company query path in the application layer: every Eloquent model in Laravel applies a global scope `WhereCompany` bound to the authenticated user's active company (from `X-Company-Id`), and every raw SQL query in reporting/materialized-view refresh jobs is parameterized by `company_id`. A user who belongs to multiple companies (via `company_users`) switches context explicitly; QAYD never merges two companies' ledgers in a single query. Group/consolidated reporting across related companies (a Future Improvement) will be implemented as an explicit elimination-entry mechanism on top of this isolation, never by relaxing it.

## Multi Branch

`branch_id` is nullable on `journal_lines` and `accounts`. Two modes, configured per company (`companies.branch_accounting_mode`):
- `shared_coa` (default): one Chart of Accounts for the whole company; branch is purely a dimension on the line (like `cost_center_id`), used for branch-level P&L slicing but not separate balancing.
- `branch_ledgers`: each branch effectively balances independently (an inter-branch "Due to/from Branch X" control account pair is auto-created per branch), used by companies that need branch-level trial balances that individually balance to zero before company-level consolidation. This mode is set once at company setup and is not designed to be switched mid-year.

## Multi Currency

Every company has exactly one `base_currency` (`companies.base_currency`, ISO 4217, default `KWD`). Any `journal_line` may be posted in any active currency (`companies.active_currencies`, e.g. `['KWD','AED','SAR','USD']`); the line stores both the transaction-currency amount and the base-currency amount, with the exchange rate frozen at posting time (Business Rule 6). Realized FX gain/loss (e.g., a KWD invoice paid in USD at a different rate than invoiced) is posted automatically by the Banking/Sales modules to `accounts.code = '7900'` ("Realized FX Gain/Loss") as its own journal line — the GL itself never silently absorbs an FX delta. Unrealized FX revaluation of foreign-currency balance-sheet accounts at period-end is an Adjusting Entry (below), typically AI-drafted and human-approved.

## Fiscal Years

`fiscal_years` defines the company's accounting calendar: `start_date`, `end_date`, `status` (`future` → `open` → `closing` → `closed`). A company may run non-calendar fiscal years (e.g., April–March) and may run a short "transition year" exactly once when changing its fiscal year-end. Only one fiscal year may be `open` for normal transactional posting at a time by default (Business Rule 12); `closing` is an intermediate state during which only closing/adjusting entries by users with `accounting.fiscal_year.close` may post into it, and ordinary transactional posting is blocked.

## Fiscal Periods

`fiscal_periods` sub-divides each fiscal year, conventionally into 12 monthly periods (also supports 13-period, 4-4-5 weekly, or quarterly configurations via `period_type`). Each period has independent `status`: `open` (normal posting), `closed` (no new posting without explicit reopen), `locked` (closed and additionally protected from reopening except by `accounting.period.hard_lock_override`, typically applied once external audit sign-off is received for that period). Period close is not all-or-nothing across modules: `fiscal_periods.module_lock` (JSONB) allows, e.g., closing Sales and Purchases for a period while Payroll's accrual is still finalizing, so Accounting can lock the GL once every source module confirms it has posted everything for that period.

## Adjusting Entries

Adjusting Entries (`journal_entries.entry_type = 'adjusting'`) recognize revenue/expense in the correct period independent of cash timing: accrued expenses not yet billed, prepaid expenses being amortized, depreciation, bad-debt provisions, unrealized FX revaluation, inventory valuation adjustments. They post like any ordinary entry but are tagged so the Reports section can show "period activity excluding/including adjustments" and so the AI layer can specifically recommend a checklist of expected adjustments at period-end (see AI Responsibilities). Recurring adjusting entries (e.g., monthly straight-line depreciation) are configured once as a `journal_entry_templates` row with a schedule and generated automatically each period by the Scheduled Posting engine (still landing as `draft` for review before posting, unless the template is explicitly marked `auto_post = true` by a Finance Manager for low-risk, formulaic entries like depreciation).

## Closing Entries

At fiscal year-end, a single system-generated Closing Entry (`entry_type = 'closing'`) sweeps every Revenue and Expense account's Closing Balance to zero, with the offsetting amount posted to the designated Retained Earnings equity account (`accounts.code` configured per company, `companies.retained_earnings_account_id`). This is the only journal entry type that is allowed to touch every Income Statement account in one transaction; it is generated by `POST /api/v1/accounting/fiscal-years/{id}/close`, requires `accounting.fiscal_year.close`, and is itself subject to the same immutability and reversal rules as any other posted entry (a mis-timed year-end close is undone by reversing the Closing Entry, not by editing it).

## Reversing Entries

A Reversing Entry (`entry_type = 'reversing'`, `reverses_entry_id` pointing at the original) is generated by `POST /api/v1/accounting/journal-entries/{id}/reverse`. It copies every line of the original with debit and credit swapped, dated either on the original entry's date (same-period reversal) or on a caller-specified later date (cross-period reversal, common when an error is discovered in a closed period and must be corrected in the current open period instead). The original entry's `status` becomes `reversed` (a fourth, terminal state alongside `draft`/`posted`/`voided`) while remaining fully queryable; the new reversing entry is a normal `posted` entry in its own right, fully auditable and itself reversible only via a further explicit reversal (QAYD does not allow reversing a reversal to silently "restore" the original — that requires posting a fresh entry that happens to match the original, which is the mechanically honest way to express "we changed our mind again").

# Data Model

## Tables

Full PostgreSQL DDL follows. Standard columns (`id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) are included explicitly on every table per the platform convention, even though the shared context treats them as implicit, so this DDL is directly runnable.

```sql
-- ============================================================
-- ENUM TYPES
-- ============================================================
CREATE TYPE account_type_code AS ENUM ('asset','liability','equity','revenue','expense');
CREATE TYPE account_status AS ENUM ('active','inactive','archived');
CREATE TYPE normal_balance_side AS ENUM ('debit','credit');

CREATE TYPE fiscal_year_status AS ENUM ('future','open','closing','closed');
CREATE TYPE fiscal_period_status AS ENUM ('future','open','closed','locked');
CREATE TYPE period_type AS ENUM ('monthly','quarterly','weekly_4_4_5','custom');

CREATE TYPE journal_entry_status AS ENUM ('draft','pending_approval','posted','reversed','voided');
CREATE TYPE journal_entry_type AS ENUM (
    'standard','adjusting','closing','reversing','opening_balance', 'recurring'
);
CREATE TYPE journal_entry_origin AS ENUM ('manual','system','import','ai_draft');

-- ============================================================
-- ACCOUNT TYPES (system-level, seeded, rarely modified per company)
-- ============================================================
CREATE TABLE account_types (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                account_type_code NOT NULL,
    name_en             VARCHAR(100) NOT NULL,
    name_ar             VARCHAR(100) NOT NULL,
    normal_balance      normal_balance_side NOT NULL,
    is_balance_sheet    BOOLEAN NOT NULL,        -- true for asset/liability/equity, false for revenue/expense
    sort_order          SMALLINT NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_account_types_code UNIQUE (code)
);

-- ============================================================
-- CHART OF ACCOUNTS
-- ============================================================
CREATE TABLE accounts (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    account_type_id      BIGINT NOT NULL REFERENCES account_types(id),
    parent_id            BIGINT NULL REFERENCES accounts(id),
    code                 VARCHAR(20) NOT NULL,
    name_en              VARCHAR(150) NOT NULL,
    name_ar              VARCHAR(150) NOT NULL,
    description          TEXT NULL,
    is_leaf              BOOLEAN NOT NULL DEFAULT true,
    is_control_account    BOOLEAN NOT NULL DEFAULT false,
    control_account_of    VARCHAR(20) NULL CHECK (control_account_of IN ('customers','vendors',NULL)),
    currency_restriction  VARCHAR(3) NULL,        -- if set, only this ISO 4217 currency may post here
    normal_balance        normal_balance_side NOT NULL,
    status                account_status NOT NULL DEFAULT 'active',
    opening_balance_locked BOOLEAN NOT NULL DEFAULT false,   -- true once an opening_balance entry exists
    tags                  JSONB NOT NULL DEFAULT '[]',
    custom_fields         JSONB NOT NULL DEFAULT '{}',
    created_by            BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    CONSTRAINT uq_accounts_company_code UNIQUE (company_id, code),
    CONSTRAINT chk_accounts_leaf_no_children_violation CHECK (true) -- enforced by trigger, see below
);
CREATE INDEX idx_accounts_company ON accounts (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_parent ON accounts (parent_id);
CREATE INDEX idx_accounts_type ON accounts (company_id, account_type_id);
CREATE INDEX idx_accounts_status ON accounts (company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_control ON accounts (company_id) WHERE is_control_account = true;

-- ============================================================
-- FISCAL CALENDAR
-- ============================================================
CREATE TABLE fiscal_years (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    name          VARCHAR(50) NOT NULL,             -- e.g. 'FY2026'
    start_date    DATE NOT NULL,
    end_date      DATE NOT NULL,
    status        fiscal_year_status NOT NULL DEFAULT 'future',
    closed_at     TIMESTAMPTZ NULL,
    closed_by     BIGINT NULL REFERENCES users(id),
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL,
    CONSTRAINT chk_fiscal_years_dates CHECK (end_date > start_date),
    CONSTRAINT uq_fiscal_years_company_name UNIQUE (company_id, name)
);
CREATE INDEX idx_fiscal_years_company ON fiscal_years (company_id, start_date);
-- Non-overlap enforced via exclusion constraint per company:
CREATE EXTENSION IF NOT EXISTS btree_gist;
ALTER TABLE fiscal_years ADD CONSTRAINT excl_fiscal_years_no_overlap
    EXCLUDE USING gist (company_id WITH =, daterange(start_date, end_date, '[]') WITH &&)
    WHERE (deleted_at IS NULL);

CREATE TABLE fiscal_periods (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
    period_type    period_type NOT NULL DEFAULT 'monthly',
    period_number  SMALLINT NOT NULL,               -- 1..12 (or 1..13, 1..4)
    name           VARCHAR(50) NOT NULL,             -- e.g. 'Jan 2026'
    start_date     DATE NOT NULL,
    end_date       DATE NOT NULL,
    status         fiscal_period_status NOT NULL DEFAULT 'future',
    module_lock    JSONB NOT NULL DEFAULT '{}',      -- {"sales":"closed","purchases":"open",...}
    closed_at      TIMESTAMPTZ NULL,
    closed_by      BIGINT NULL REFERENCES users(id),
    reopened_at    TIMESTAMPTZ NULL,
    reopened_by    BIGINT NULL REFERENCES users(id),
    reopen_reason  TEXT NULL,
    created_by     BIGINT NULL REFERENCES users(id),
    updated_by     BIGINT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at     TIMESTAMPTZ NULL,
    CONSTRAINT chk_fiscal_periods_dates CHECK (end_date > start_date),
    CONSTRAINT uq_fiscal_periods_year_number UNIQUE (fiscal_year_id, period_number)
);
CREATE INDEX idx_fiscal_periods_company_dates ON fiscal_periods (company_id, start_date, end_date);
CREATE INDEX idx_fiscal_periods_status ON fiscal_periods (company_id, status);

-- ============================================================
-- JOURNAL ENTRIES (header) & JOURNAL LINES (the source of truth)
-- ============================================================
CREATE TABLE journal_entries (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    fiscal_period_id   BIGINT NOT NULL REFERENCES fiscal_periods(id),
    entry_number       VARCHAR(30) NOT NULL,        -- human-readable, e.g. JE-2026-000482
    entry_date         DATE NOT NULL,
    entry_type         journal_entry_type NOT NULL DEFAULT 'standard',
    origin             journal_entry_origin NOT NULL DEFAULT 'manual',
    status             journal_entry_status NOT NULL DEFAULT 'draft',
    description        TEXT NOT NULL,
    reference          VARCHAR(100) NULL,           -- external reference / PO#, invoice#, etc.
    source_type        VARCHAR(50) NULL,            -- 'invoice','bill','receipt','payroll_run', etc.
    source_id          BIGINT NULL,                 -- polymorphic id into source_type's table
    reverses_entry_id  BIGINT NULL REFERENCES journal_entries(id),
    reversed_by_entry_id BIGINT NULL REFERENCES journal_entries(id),
    total_debit        NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_credit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code      VARCHAR(3) NOT NULL DEFAULT 'KWD',
    created_by_agent   VARCHAR(50) NULL,            -- e.g. 'general_accountant_agent'; NULL = human-authored
    ai_confidence      NUMERIC(5,4) NULL,           -- 0..1, only set when created_by_agent is set
    ai_reasoning       TEXT NULL,
    submitted_for_approval_at TIMESTAMPTZ NULL,
    approved_by        BIGINT NULL REFERENCES users(id),
    approved_at        TIMESTAMPTZ NULL,
    posted_by          BIGINT NULL REFERENCES users(id),
    posted_at          TIMESTAMPTZ NULL,
    voided_by          BIGINT NULL REFERENCES users(id),
    voided_at          TIMESTAMPTZ NULL,
    void_reason        TEXT NULL,
    tags               JSONB NOT NULL DEFAULT '[]',
    custom_fields      JSONB NOT NULL DEFAULT '{}',
    created_by         BIGINT NULL REFERENCES users(id),
    updated_by         BIGINT NULL REFERENCES users(id),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at         TIMESTAMPTZ NULL,
    CONSTRAINT uq_journal_entries_company_number UNIQUE (company_id, entry_number),
    CONSTRAINT chk_journal_entries_balanced CHECK (
        status <> 'posted' OR total_debit = total_credit
    ),
    CONSTRAINT chk_ai_confidence_range CHECK (ai_confidence IS NULL OR (ai_confidence >= 0 AND ai_confidence <= 1))
);
CREATE INDEX idx_journal_entries_company_date ON journal_entries (company_id, entry_date);
CREATE INDEX idx_journal_entries_status ON journal_entries (company_id, status);
CREATE INDEX idx_journal_entries_period ON journal_entries (fiscal_period_id);
CREATE INDEX idx_journal_entries_source ON journal_entries (source_type, source_id);
CREATE INDEX idx_journal_entries_reference ON journal_entries (company_id, reference);
CREATE INDEX idx_journal_entries_reverses ON journal_entries (reverses_entry_id);

CREATE TABLE journal_lines (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    journal_entry_id   BIGINT NOT NULL REFERENCES journal_entries(id) ON DELETE RESTRICT,
    line_number        SMALLINT NOT NULL,
    account_id         BIGINT NOT NULL REFERENCES accounts(id),
    debit_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code      VARCHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate      NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_debit_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_credit_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    memo               VARCHAR(255) NULL,
    customer_id        BIGINT NULL REFERENCES customers(id),
    vendor_id          BIGINT NULL REFERENCES vendors(id),
    cost_center_id      BIGINT NULL REFERENCES cost_centers(id),
    project_id          BIGINT NULL REFERENCES projects(id),
    department_id       BIGINT NULL REFERENCES departments(id),
    tags               JSONB NOT NULL DEFAULT '[]',
    custom_fields      JSONB NOT NULL DEFAULT '{}',
    created_by         BIGINT NULL REFERENCES users(id),
    updated_by         BIGINT NULL REFERENCES users(id),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at         TIMESTAMPTZ NULL,
    CONSTRAINT uq_journal_lines_entry_number UNIQUE (journal_entry_id, line_number),
    CONSTRAINT chk_journal_lines_one_side CHECK (
        (debit_amount > 0 AND credit_amount = 0) OR
        (credit_amount > 0 AND debit_amount = 0)
    ),
    CONSTRAINT chk_journal_lines_nonnegative CHECK (debit_amount >= 0 AND credit_amount >= 0)
);
CREATE INDEX idx_journal_lines_entry ON journal_lines (journal_entry_id);
CREATE INDEX idx_journal_lines_account ON journal_lines (account_id);
CREATE INDEX idx_journal_lines_company_account ON journal_lines (company_id, account_id);
CREATE INDEX idx_journal_lines_customer ON journal_lines (customer_id) WHERE customer_id IS NOT NULL;
CREATE INDEX idx_journal_lines_vendor ON journal_lines (vendor_id) WHERE vendor_id IS NOT NULL;
CREATE INDEX idx_journal_lines_cost_center ON journal_lines (cost_center_id) WHERE cost_center_id IS NOT NULL;
CREATE INDEX idx_journal_lines_project ON journal_lines (project_id) WHERE project_id IS NOT NULL;

-- ============================================================
-- LEDGER ENTRIES — the posted-line projection (read model)
-- ============================================================
CREATE TABLE ledger_entries (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    journal_entry_id   BIGINT NOT NULL REFERENCES journal_entries(id),
    journal_line_id    BIGINT NOT NULL REFERENCES journal_lines(id),
    account_id         BIGINT NOT NULL REFERENCES accounts(id),
    fiscal_period_id   BIGINT NOT NULL REFERENCES fiscal_periods(id),
    fiscal_year_id     BIGINT NOT NULL REFERENCES fiscal_years(id),
    entry_date         DATE NOT NULL,
    posted_at          TIMESTAMPTZ NOT NULL,
    debit_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_debit_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_credit_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    signed_base_amount NUMERIC(19,4) NOT NULL,       -- +base_debit or -base_credit, normalized for fast SUM()
    currency_code      VARCHAR(3) NOT NULL,
    customer_id        BIGINT NULL REFERENCES customers(id),
    vendor_id          BIGINT NULL REFERENCES vendors(id),
    cost_center_id      BIGINT NULL REFERENCES cost_centers(id),
    project_id          BIGINT NULL REFERENCES projects(id),
    department_id       BIGINT NULL REFERENCES departments(id),
    entry_type         journal_entry_type NOT NULL,
    source_type        VARCHAR(50) NULL,
    source_id          BIGINT NULL,
    description        TEXT NOT NULL,
    reference          VARCHAR(100) NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_ledger_entries_journal_line UNIQUE (journal_line_id)
);
CREATE INDEX idx_ledger_account_date ON ledger_entries (company_id, account_id, entry_date);
CREATE INDEX idx_ledger_period ON ledger_entries (fiscal_period_id, account_id);
CREATE INDEX idx_ledger_year ON ledger_entries (fiscal_year_id);
CREATE INDEX idx_ledger_customer ON ledger_entries (customer_id) WHERE customer_id IS NOT NULL;
CREATE INDEX idx_ledger_vendor ON ledger_entries (vendor_id) WHERE vendor_id IS NOT NULL;
CREATE INDEX idx_ledger_source ON ledger_entries (source_type, source_id);
CREATE INDEX idx_ledger_cost_center ON ledger_entries (cost_center_id) WHERE cost_center_id IS NOT NULL;
CREATE INDEX idx_ledger_project ON ledger_entries (project_id) WHERE project_id IS NOT NULL;
-- BRIN index for very large tables where entry_date is naturally append-ordered:
CREATE INDEX idx_ledger_entry_date_brin ON ledger_entries USING BRIN (entry_date);

-- ============================================================
-- JOURNAL ENTRY TEMPLATES (recurring adjusting/standard entries)
-- ============================================================
CREATE TABLE journal_entry_templates (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    branch_id      BIGINT NULL REFERENCES branches(id),
    name           VARCHAR(150) NOT NULL,
    entry_type     journal_entry_type NOT NULL DEFAULT 'recurring',
    description    TEXT NOT NULL,
    line_template  JSONB NOT NULL,           -- [{account_id, side, amount_formula, memo}, ...]
    schedule_cron  VARCHAR(50) NOT NULL,     -- e.g. '0 0 1 * *' = first of month
    auto_post      BOOLEAN NOT NULL DEFAULT false,
    next_run_at    TIMESTAMPTZ NULL,
    last_run_at    TIMESTAMPTZ NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by     BIGINT NULL REFERENCES users(id),
    updated_by     BIGINT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at     TIMESTAMPTZ NULL
);
CREATE INDEX idx_je_templates_company ON journal_entry_templates (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_je_templates_next_run ON journal_entry_templates (next_run_at) WHERE status = 'active';

-- ============================================================
-- POSTING JOB QUEUE (background/batch posting tracking)
-- ============================================================
CREATE TABLE posting_jobs (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    job_type       VARCHAR(30) NOT NULL,     -- 'batch','scheduled','import','recurring_template'
    status         VARCHAR(20) NOT NULL DEFAULT 'queued', -- queued|running|completed|failed|partial
    total_entries  INTEGER NOT NULL DEFAULT 0,
    posted_entries INTEGER NOT NULL DEFAULT 0,
    failed_entries INTEGER NOT NULL DEFAULT 0,
    error_log      JSONB NOT NULL DEFAULT '[]',
    started_at     TIMESTAMPTZ NULL,
    completed_at   TIMESTAMPTZ NULL,
    requested_by   BIGINT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_posting_jobs_company_status ON posting_jobs (company_id, status);

-- ============================================================
-- HISTORY / VERSIONING TABLES
-- ============================================================
CREATE TABLE accounts_history (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id     BIGINT NOT NULL REFERENCES accounts(id),
    version_number INTEGER NOT NULL,
    snapshot       JSONB NOT NULL,          -- full row snapshot before change
    changed_fields JSONB NOT NULL,          -- {field: [old, new]}
    changed_by     BIGINT NULL REFERENCES users(id),
    changed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    change_reason  TEXT NULL
);
CREATE INDEX idx_accounts_history_account ON accounts_history (account_id, version_number);

CREATE TABLE journal_entries_history (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    journal_entry_id BIGINT NOT NULL REFERENCES journal_entries(id),
    version_number   INTEGER NOT NULL,
    snapshot         JSONB NOT NULL,        -- full entry+lines snapshot (draft edits only; posted is immutable)
    changed_fields   JSONB NOT NULL,
    changed_by       BIGINT NULL REFERENCES users(id),
    changed_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_je_history_entry ON journal_entries_history (journal_entry_id, version_number);
```

## Relationships

- `account_types (1) —— (N) accounts` via `account_type_id`.
- `accounts (1) —— (N) accounts` self-referencing tree via `parent_id`.
- `fiscal_years (1) —— (N) fiscal_periods` via `fiscal_year_id`.
- `fiscal_periods (1) —— (N) journal_entries` via `fiscal_period_id`.
- `journal_entries (1) —— (N) journal_lines` via `journal_entry_id`; a balanced entry has ≥ 2 lines.
- `journal_lines (1) —— (1) ledger_entries` via `journal_line_id` (created only on posting; never before).
- `accounts (1) —— (N) journal_lines` via `account_id`; and transitively `(N) ledger_entries`.
- `journal_entries (0..1) —— (0..1) journal_entries` self-referencing pair via `reverses_entry_id` / `reversed_by_entry_id`.
- `customers (1) —— (N) journal_lines`, `vendors (1) —— (N) journal_lines`, `cost_centers (1) —— (N) journal_lines`, `projects (1) —— (N) journal_lines`, `departments (1) —— (N) journal_lines` — all optional dimensions.
- `journal_entry_templates (1) —— (N) journal_entries` implicitly via `source_type = 'journal_entry_template'`, `source_id = template.id` on generated entries.
- Polymorphic: `journal_entries.source_type` + `source_id` reference rows in `invoices`, `bills`, `receipts`, `vendor_payments`, `payroll_runs`, `stock_adjustments`, `bank_transactions`, etc., owned by their respective modules — the GL never foreign-keys directly into another module's table to avoid circular schema coupling; integrity is enforced at the service layer when the source event is raised.

## Indexes

Beyond the DDL above, the following composite/partial indexes are mandated for the query patterns in Search Engine and Reports:

```sql
CREATE INDEX idx_journal_entries_search ON journal_entries (company_id, status, entry_date DESC);
CREATE INDEX idx_journal_lines_amount ON journal_lines (company_id, debit_amount, credit_amount);
CREATE INDEX idx_ledger_entries_composite_report
    ON ledger_entries (company_id, fiscal_year_id, account_id, entry_date);
-- Full-text search on description/reference for the Search Engine:
CREATE INDEX idx_journal_entries_fts ON journal_entries
    USING GIN (to_tsvector('simple', coalesce(description,'') || ' ' || coalesce(reference,'')));
```

## Constraints

All monetary `CHECK` constraints and uniqueness constraints are declared inline in the DDL above. Two additional trigger-enforced constraints (Postgres `CHECK` cannot reference other rows, so these are triggers) are required:

```sql
-- 1. Prevent posting to a non-leaf account
CREATE OR REPLACE FUNCTION fn_enforce_leaf_account_posting() RETURNS TRIGGER AS $$
DECLARE v_is_leaf BOOLEAN; v_status account_status;
BEGIN
    SELECT is_leaf, status INTO v_is_leaf, v_status FROM accounts WHERE id = NEW.account_id;
    IF v_is_leaf IS NOT TRUE THEN
        RAISE EXCEPTION 'Account % is not a leaf/postable account', NEW.account_id;
    END IF;
    IF v_status <> 'active' THEN
        RAISE EXCEPTION 'Account % is not active', NEW.account_id;
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER trg_journal_lines_leaf_check
    BEFORE INSERT ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION fn_enforce_leaf_account_posting();

-- 2. Prevent any UPDATE/DELETE on journal_lines belonging to a posted entry
CREATE OR REPLACE FUNCTION fn_journal_lines_immutable() RETURNS TRIGGER AS $$
DECLARE v_status journal_entry_status;
BEGIN
    SELECT status INTO v_status FROM journal_entries WHERE id = OLD.journal_entry_id;
    IF v_status = 'posted' THEN
        RAISE EXCEPTION 'Cannot modify journal_lines of a posted journal_entry (id=%)', OLD.journal_entry_id;
    END IF;
    RETURN OLD;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER trg_journal_lines_immutable
    BEFORE UPDATE OR DELETE ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION fn_journal_lines_immutable();

-- 3. Prevent AI-authored entries from being inserted directly as posted
CREATE OR REPLACE FUNCTION fn_no_ai_autopost() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.created_by_agent IS NOT NULL AND NEW.status = 'posted' AND
       (TG_OP = 'INSERT' OR OLD.status <> 'posted') THEN
        -- allow only if a human approved_by/posted_by is also set (i.e. it went through the approval flow)
        IF NEW.approved_by IS NULL OR NEW.posted_by IS NULL THEN
            RAISE EXCEPTION 'AI-authored journal_entries must be approved and posted by a human user';
        END IF;
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER trg_no_ai_autopost
    BEFORE INSERT OR UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION fn_no_ai_autopost();
```

## History Tables

`accounts_history` and `journal_entries_history` capture every mutation to mutable rows (accounts metadata, and draft-stage journal entry edits). They are populated by `AFTER UPDATE` triggers that snapshot the pre-image row as JSONB and diff it against the new row to populate `changed_fields`. Posted `journal_entries`/`journal_lines` never generate history-table rows after posting because they cannot be mutated post-posting (Business Rule 2) — their "history" is simply their own permanent row plus, where relevant, the reversing entry that supersedes them.

## Soft Delete

Every table carries `deleted_at`. Soft delete on `accounts` means "no longer selectable for new postings" (enforced by application-layer scopes plus the `status = 'inactive'`/`'archived'` state which is the primary mechanism — `deleted_at` is reserved for genuine data-entry mistakes, e.g. an account created twice by accident with zero activity). Soft delete is **never** applied to `journal_entries` or `journal_lines` once posted; "removing" a posted entry from view is achieved by setting a UI-only `is_hidden_from_default_view` flag alongside the mandatory reversing entry (Business Rule 8), while the row itself, `deleted_at IS NULL`, remains permanently and fully queryable by anyone with `accounting.journal.read` and by every report and audit tool.

## Versioning

Draft journal entries are versioned optimistically: every `PATCH` to a draft entry or its lines increments an implicit `lock_version` integer column (add `lock_version INTEGER NOT NULL DEFAULT 1` to `journal_entries` in practice) and the API rejects a write whose `If-Match`/`lock_version` header doesn't match current state with `409 Conflict`, preventing two accountants from silently clobbering each other's concurrent edits to the same draft. Posted entries need no optimistic-lock versioning because they are never edited; their "version" is simply "the chain of entry → reversing entry → possibly another correcting entry," which is itself queryable via `reverses_entry_id`/`reversed_by_entry_id`.

# Posting Engine

## Real Time Posting

Real-time posting is the default and most common path: a user (or another module's service) calls `POST /api/v1/accounting/journal-entries` with `status: "posted"` intent (or creates a draft, then calls the `/post` action), and the Laravel `JournalPostingService` executes synchronously within a single database transaction:

```
BEGIN;
  1. Re-validate the entry is balanced (debits == credits, base currency).
  2. Re-validate every account is active + leaf.
  3. Re-validate the fiscal_period is open (re-check at commit time, not just at draft-creation time —
     a period may have closed between draft creation and posting).
  4. Lock the fiscal_period row (SELECT ... FOR UPDATE) to serialize against a concurrent period-close.
  5. Set journal_entries.status = 'posted', posted_by, posted_at.
  6. INSERT one ledger_entries row per journal_line (the projection).
  7. Enqueue (not execute inline) the balance-cache refresh job for each affected account
     (see Performance — mv_account_balances is refreshed asynchronously within ~2s SLA, not synchronously,
     to keep the posting transaction itself fast).
  8. Emit domain event `journal.posted` (company_id, journal_entry_id, affected_account_ids[]) via Reverb.
COMMIT;
```

The whole transaction typically completes in under 50ms for a normal entry (≤ 20 lines). If any validation fails, the entire transaction rolls back and the entry remains `draft` with a `422` response detailing every failed line.

## Scheduled Posting

Scheduled posting drives `journal_entry_templates` with a `schedule_cron`. A Laravel scheduled command (`php artisan accounting:run-templates`, invoked every 15 minutes by the platform's cron) selects templates where `next_run_at <= now()` and `status = 'active'`, materializes each template's `line_template` (resolving `amount_formula`, e.g. `= straight_line(asset.cost, asset.useful_life_months)` for depreciation) into a concrete draft `journal_entries` + `journal_lines` set, and either:
- leaves it as `draft` for a human to review and post (default), or
- posts it immediately via the same `JournalPostingService` used by real-time posting, if `auto_post = true` (permission-gated: only a Finance Manager or Admin may mark a template `auto_post`).

`next_run_at` is then advanced per the cron expression, and `last_run_at` is stamped. A failed template run (e.g., a referenced account was deactivated) logs to `posting_jobs` and notifies the template's owner without blocking other templates' runs.

## Batch Posting

Batch posting handles bulk operations: month-end depreciation runs across hundreds of assets, bulk import of opening balances during onboarding, or posting an entire day's worth of AI-drafted entries after bulk human approval. `POST /api/v1/accounting/journal-entries/batch-post` accepts an array of `journal_entry_id`s (all must be `draft`, all must belong to the caller's active company) and creates a `posting_jobs` row (`job_type = 'batch'`), then dispatches a Laravel queued job (`BatchPostJournalEntries`) that processes entries in chunks of 100, each chunk in its own DB transaction (so one bad entry in a 500-entry batch doesn't roll back the other 499). Per-entry failures are recorded in `posting_jobs.error_log` as `[{entry_id, error_code, message}]`; the job's overall `status` becomes `completed` (all succeeded), `partial` (some failed), or `failed` (systemic failure, e.g. period locked mid-batch).

## Background Jobs

Everything downstream of the atomic posting transaction is asynchronous, queued on Redis via Laravel's queue driver, to keep the user-facing posting call fast:
- `RefreshAccountBalanceCache` (per affected `account_id` × `fiscal_period_id`) — updates `mv_account_balances` incrementally rather than a full materialized-view `REFRESH`.
- `RefreshTrialBalanceCache` (per `company_id` × `fiscal_period_id`) — debounced, coalesces multiple postings within a 5-second window into one refresh.
- `RunAnomalyDetection` (per posted entry) — hands the entry to the FastAPI AI layer for asynchronous anomaly scoring (see AI Responsibilities); never blocks posting.
- `NotifyDownstreamModules` — publishes `journal.posted` to any module-specific listener (e.g., Sales AR reconciliation recalculating a customer's outstanding balance).
- `WriteAuditLog` — writes the `audit_logs` row for the posting action (who/when/what).

## Rollback

"Rollback" in the GL never means deleting a posted row. Two distinct mechanisms exist depending on where in the lifecycle the failure is caught:
1. **Pre-posting rollback (transactional):** any validation failure inside the `BEGIN...COMMIT` block of Real Time Posting or a batch chunk triggers a normal SQL `ROLLBACK` — the entry never transitions out of `draft`, no `ledger_entries` rows are created, nothing to undo.
2. **Post-posting rollback (business rollback):** once an entry is `posted`, the only rollback is a Reversing Entry (see Ledger Architecture). The API exposes this explicitly as `POST /journal-entries/{id}/reverse` rather than overloading `DELETE` with destructive semantics — `DELETE` on a posted entry is implemented as "create the reversing entry, then soft-hide the original from default list views," described in Business Rule 8.

## Retry

Background jobs (balance-cache refresh, anomaly detection, notification dispatch) use Laravel's queue retry policy: 3 attempts with exponential backoff (10s, 60s, 300s), after which the job is moved to the `failed_jobs` table and an alert is raised to the on-call engineering channel — a failed cache-refresh job never corrupts data (the cache is always rebuildable from `ledger_entries`), it only risks a temporarily stale balance display, which the UI mitigates by showing a "as of {last_refreshed_at}" timestamp on any cached figure and offering a manual "recompute now" action that reads `ledger_entries` directly (uncached) for users with `accounting.report.export`. Synchronous posting itself is never retried automatically — a failed posting attempt is surfaced immediately to the caller with the specific validation error, since blind retry of a financial write is unsafe without human judgment on why it failed.

# Search Engine

The Search Engine underlies both the ledger browsing UI and the `GET /api/v1/accounting/journal-entries/search` and `GET /api/v1/accounting/ledger-entries/search` endpoints. It supports the following filters, all combinable (AND semantics across filters, OR semantics within a multi-select filter):

| Filter | Field(s) | Notes |
|---|---|---|
| Account | `account_id` (single or array), or `account_code` prefix (e.g. `11%` for all Cash & Bank) | Prefix match walks the account tree without a recursive query by using `accounts.code` as a sortable string key. |
| Date | `entry_date` range (`from`, `to`), or named ranges (`this_month`, `last_quarter`, `fiscal_year_id`) | Always resolved server-side against `fiscal_periods` for named ranges. |
| Journal | `journal_entry_id`, `entry_number` (exact or prefix) | |
| Reference | `reference` (ILIKE / full-text via `idx_journal_entries_fts`) | |
| Customer | `customer_id` | Only meaningful on lines touching AR/control accounts. |
| Vendor | `vendor_id` | Only meaningful on lines touching AP/control accounts. |
| Project | `project_id` | |
| Cost Center | `cost_center_id` | |
| Branch | `branch_id` | |
| Currency | `currency_code` | Transaction currency, distinct from base-currency amount filters. |
| Amount | `amount_min`, `amount_max` on `debit_amount`/`credit_amount`/`base_amount` | Supports "find the exact ماء 1,250.000 entry" workflows for reconciliation. |
| User | `created_by`, `posted_by`, `approved_by` | |
| Status | `status` (draft/pending_approval/posted/reversed/voided), multi-select | |
| Tags | `tags` (JSONB containment, `@>`) | Free-form tagging for cross-cutting workflows, e.g. `["year-end-2026"]`. |

Example query (`GET /api/v1/accounting/ledger-entries/search`):

```
GET /api/v1/accounting/ledger-entries/search
    ?account_id=1131
    &from=2026-01-01&to=2026-06-30
    &customer_id=4021
    &amount_min=500
    &sort=entry_date:desc
    &page=1&per_page=25
```

resolves server-side to:

```sql
SELECT le.* FROM ledger_entries le
WHERE le.company_id = :company_id
  AND le.account_id = 1131
  AND le.entry_date BETWEEN '2026-01-01' AND '2026-06-30'
  AND le.customer_id = 4021
  AND (le.base_debit_amount >= 500 OR le.base_credit_amount >= 500)
ORDER BY le.entry_date DESC, le.id DESC
LIMIT 25 OFFSET 0;
```

Full-text search across `description`/`reference` on `journal_entries` uses the GIN index declared in Data Model, exposed as a single `q` query parameter (`?q=elevenlabs invoice`) that is tokenized and matched via `to_tsvector`/`to_tsquery('simple', ...)`, ANDed with any other structured filters present in the same request. Search results are always scoped to `company_id` before any other predicate is applied — the query planner is guided to use `idx_ledger_account_date`/`idx_journal_entries_search` first via the leading `company_id` column in each composite index.

# Reports

All reports below are derived, read-only views over `journal_lines`/`ledger_entries`; none store independent numbers. Each is exposed both as a JSON API response (paginated where the result set can be large) and as an export (`format=pdf|xlsx|csv` query parameter, see API).

| Report | Description | Primary Source |
|---|---|---|
| **General Ledger** | Every posted line for a chosen account (or set of accounts) over a date range, with running balance. | `ledger_entries` filtered + ordered, seeded by Balance Forward. |
| **Account Statement** | Same as General Ledger but framed for a single control-account sub-ledger entity (a specific customer's AR activity, or a specific vendor's AP activity), typically emailed as a PDF. | `ledger_entries` filtered by `account_id = <control account>` AND `customer_id`/`vendor_id`. |
| **Ledger Summary** | One row per account: opening balance, period debit total, period credit total, closing balance — the backbone of the Trial Balance. | `mv_trial_balance` (see Performance). |
| **Ledger Details** | Full drill-down of Ledger Summary: expand any account row into its constituent `ledger_entries`. | `ledger_entries`, lazy-loaded per account on expand. |
| **Movement Report** | Net movement (period activity) per account, per dimension (cost center / project / branch), for a chosen period — used for departmental/project P&L slicing. | `ledger_entries` GROUP BY `account_id, cost_center_id/project_id`. |
| **Running Balance** | Time series of an account's balance after every transaction — powers the line-chart view of cash/bank balances over time. | `mv_account_running_balance`. |
| **Yearly/Monthly/Daily Activity** | Aggregated period activity at year, month, or day granularity for trend charts and the AI Forecast Agent's training input. | `ledger_entries` GROUP BY `date_trunc('day'/'month'/'year', entry_date)`. |
| **Custom Reports** | User-defined report built from `report_definitions` (foundation table): a saved combination of filters, grouping, and columns from the above primitives, schedulable via `report_schedules` to auto-run and email/export on a cron (`report_runs` records each execution). | `report_definitions.query_spec` (JSONB) interpreted by `ReportBuilderService`, ultimately compiling to the same `ledger_entries` queries above. |

Example Trial Balance response (`GET /api/v1/accounting/reports/trial-balance?fiscal_period_id=42`):

```json
{
  "success": true,
  "data": {
    "fiscal_period": { "id": 42, "name": "Jun 2026", "status": "open" },
    "as_of": "2026-06-30",
    "currency": "KWD",
    "rows": [
      {
        "account_id": 1121, "code": "1121", "name_en": "NBK Current Account",
        "opening_balance": "18250.5000", "period_debit": "42100.0000",
        "period_credit": "39775.2500", "closing_balance": "20575.2500",
        "normal_balance": "debit"
      },
      {
        "account_id": 1131, "code": "1131", "name_en": "Trade Receivables",
        "opening_balance": "9820.0000", "period_debit": "15400.0000",
        "period_credit": "11250.0000", "closing_balance": "13970.0000",
        "normal_balance": "debit"
      },
      {
        "account_id": 2110, "code": "2110", "name_en": "Trade Payables",
        "opening_balance": "6100.0000", "period_debit": "4200.0000",
        "period_credit": "7900.0000", "closing_balance": "9800.0000",
        "normal_balance": "credit"
      }
    ],
    "totals": { "total_debit": "34590.2500", "total_credit": "34590.2500", "is_balanced": true }
  },
  "message": "Trial balance retrieved successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b6b6c1d4-9e2a-4a2e-9a3f-0e9d6a2a7c11",
  "timestamp": "2026-07-16T09:12:03Z"
}
```

# Permissions

Permission keys introduced or consumed by this module (naming per the platform's `<area>.<action>` / `<area>.<entity>.<action>` convention):

| Permission Key | Description |
|---|---|
| `accounting.read` | View chart of accounts, ledger entries, and reports (read-only). |
| `accounting.account.create` / `.update` / `.deactivate` | Manage the Chart of Accounts. |
| `accounting.journal.create` | Create draft journal entries. |
| `accounting.journal.update` | Edit an existing draft journal entry. |
| `accounting.journal.post` | Post a draft (or AI-drafted) journal entry — the irreversible transition. |
| `accounting.journal.reverse` | Reverse a posted journal entry. |
| `accounting.journal.void` | Void a draft/pending entry (never a posted one — posted entries can only be reversed). |
| `accounting.control_account.override` | Manually post to a control account (AR/AP) outside the automated sub-ledger flow. |
| `accounting.period.close` | Close a fiscal period. |
| `accounting.period.reopen` | Reopen a closed (not locked) period. |
| `accounting.period.hard_lock_override` | Reopen a `locked` period (highest-sensitivity period action). |
| `accounting.fiscal_year.close` | Run year-end Closing Entries. |
| `accounting.fiscal_year.parallel_open` | Operate two fiscal years open simultaneously. |
| `accounting.report.read` | View standard reports (Trial Balance, GL, Statements). |
| `accounting.report.export` | Export/schedule reports; also grants access to uncached "recompute now" report views. |
| `accounting.ai.approve_draft` | Approve (and thereby enable posting of) an AI-drafted journal entry. |

Default role → permission matrix:

| Permission | Owner | Admin | Finance Manager | Accountant | Auditor | Employee | AI Agent |
|---|---|---|---|---|---|---|---|
| `accounting.read` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ (scoped) |
| `accounting.account.create/update` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.account.deactivate` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.journal.create` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | draft-only |
| `accounting.journal.update` | ✅ | ✅ | ✅ | ✅ (own drafts) | ❌ | ❌ | ❌ |
| `accounting.journal.post` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ (never) |
| `accounting.journal.reverse` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.journal.void` | ✅ | ✅ | ✅ | ✅ (own drafts) | ❌ | ❌ | ❌ |
| `accounting.control_account.override` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.period.close` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.period.reopen` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.period.hard_lock_override` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `accounting.fiscal_year.close` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.report.read` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ (scoped) |
| `accounting.report.export` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| `accounting.ai.approve_draft` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ (never self-approve) |

Auditor (and the "External Auditor" role variant) is always read-only across every accounting permission — an auditor who can post or reverse an entry is a broken control, so the role is structurally denied every write permission at the seed-data level, not merely by default configuration a company could loosen. AI Agent identities (`created_by_agent` values registered in the platform's agent registry) are granted only `accounting.journal.create` restricted to `status = 'draft'` output (enforced by `trg_no_ai_autopost`) and read access scoped to the company context of the conversation that invoked them — never `accounting.journal.post`, `accounting.journal.reverse`, `accounting.period.*`, or `accounting.ai.approve_draft` (an agent can never approve its own or another agent's draft).

# AI Responsibilities

The relevant agents for the General Ledger are the **General Accountant Agent**, the **Auditor Agent**, the **Fraud Detection Agent**, the **Forecast Agent**, and the **Reporting Agent**. Every agent obeys the platform-wide AI contract: it calls the same Laravel API as a human user (never touches PostgreSQL directly), it carries the caller's permission scope, and every output is stamped with a confidence score, a plain-language reasoning trail, and links to the supporting `journal_entries`/`ledger_entries`/source documents it used.

## Explain Ledger Activity

**Agent:** General Accountant Agent. **Autonomy:** suggest-only (read/explain, no write). **Input:** a natural-language question ("why did Trade Payables jump in May?") plus the resolved account/date scope. **Process:** the agent queries `GET /api/v1/accounting/ledger-entries/search` for the account and period, clusters the resulting lines by `source_type`/counterparty, and produces a ranked, plain-English breakdown ("62% of the KWD 4,200 increase is 3 new goods-receipt-linked bills from Vendor X; 28% is a reclassifying journal entry JE-2026-000418 moving KWD 1,150 from Accrued Expenses; the remainder is FX revaluation."). **Output:** structured JSON (`{summary, contributing_entries: [{journal_entry_id, amount, weight}], confidence}`) rendered as a narrative in the UI. **Confidence handling:** confidence is high (>0.9) when contributing entries fully reconcile the delta to the cent; confidence drops and the agent explicitly states "unreconciled residual of KWD X" whenever its clustering doesn't fully explain the movement, rather than forcing a narrative to fit.

## Detect Anomalies

**Agent:** Fraud Detection Agent + Auditor Agent (complementary lenses). **Autonomy:** suggest-only; flags surface as `ai_flags` on the entry, visible to Accountant/Finance Manager/Auditor roles, never auto-blocking posting for entries below the "hard stop" threshold (see below). **Triggers evaluated on every `journal.posted` event (async, via `RunAnomalyDetection`):**
- Unusual posting time (e.g., a large manual entry posted at 2 AM by a user who has never posted outside business hours).
- Round-number bias (entries that are suspiciously exact, e.g. exactly 5,000.0000, posted manually to a P&L account, at a rate statistically inconsistent with that account's history).
- Split-transaction pattern (multiple same-day, same-account entries just under an approval threshold, from the same user).
- Duplicate-looking entries (see Duplicate Detection below).
- Control-account override usage outside the reconciliation window.
- A single user both creating and posting an unusually high share of high-value entries with no second-party review (segregation-of-duties signal).
**Output:** each flag carries `severity` (`info`/`warning`/`critical`), `reasoning`, `confidence`, and a `recommended_action` ("route to Finance Manager for review before period close"). **Hard stop exception:** the only anomaly type permitted to block rather than merely flag is `trg_no_ai_autopost`-class structural violations (already enforced at the DB layer, not by the AI); every genuinely AI-judgment-based anomaly is advisory.

## Predict Errors

**Agent:** General Accountant Agent, invoked at draft-save time (before posting), not after. **Process:** compares the draft entry's account/amount/description pattern against the account's historical posting pattern (e.g., "this account has never received a debit line before — 97% of prior entries were credits; are you sure?") and against common misclassification signatures (e.g., an amount matching a recent bill exactly, posted to the wrong expense account instead of Accounts Payable). **Output:** a non-blocking warning banner in the entry-creation UI with `confidence` and a one-line suggested fix; the user may dismiss and post regardless — this is advisory friction, not a gate.

## Recommend Corrections

**Agent:** General Accountant Agent. **Autonomy:** suggest-only, always producing a `draft` (never posts). **Process:** when an anomaly or a user's "something looks off in this account" query resolves to a specific likely miscoding, the agent drafts the correcting (adjusting or reclassifying) journal entry itself — populated lines, amounts, description, and `ai_reasoning` — and submits it via `POST /journal-entries` with `origin: 'ai_draft'`, `status: 'draft'`. It never calls `/post`; the drafted correction sits in the approving accountant's queue exactly like a human-authored draft, gated by `accounting.ai.approve_draft` then `accounting.journal.post`.

## Summarize Activity

**Agent:** Reporting Agent. **Autonomy:** read-only. **Process:** on a schedule (e.g., weekly) or on-demand, produces a natural-language digest of ledger activity for a period — top accounts by movement, unusual account activity, period-over-period deltas — delivered via the notification system (see Notifications) to Finance Manager/Owner. **Confidence handling:** always states the exact date range and record count underlying the summary, so the digest is self-auditing.

## Forecast Trends

**Agent:** Forecast Agent. **Autonomy:** read-only, advisory. **Input:** `ledger_entries` aggregated at monthly granularity (Yearly/Monthly/Daily Activity report) over a trailing window (default 24 months, configurable). **Process:** time-series projection (seasonal-naive baseline plus a learned adjustment) per account or account group, producing a forecasted Trial Balance / cash position N periods forward. **Output:** forecast values always carry a confidence interval, never a single point estimate presented as fact, and always cite the historical window used. Never feeds directly into any posted entry — forecast output is a planning artifact (e.g., cash-runway dashboard), structurally separate from the GL's factual record.

## Duplicate Detection

**Agent:** Fraud Detection Agent, invoked synchronously at draft-save time for module-originated entries (e.g., bill posting) and asynchronously post-posting for manual entries. **Process:** fuzzy-matches new entries against recent entries on the same account with matching amount (±0.5%), similar description (trigram similarity), and same counterparty within a rolling 30-day window. **Output:** a `possible_duplicate_of: journal_entry_id` flag with `confidence`; surfaced to the creator before posting (for module-originated entries, the source module's own service layer — e.g., Purchases bill entry — is expected to block on a high-confidence duplicate at the document level before it ever reaches journal creation).

## Fraud Detection

**Agent:** Fraud Detection Agent. **Autonomy:** suggest-only; the single most conservative agent in the GL — it is explicitly barred from ever taking a blocking action against a specific transaction, only from raising a `critical` flag routed to Owner/Admin/Finance Manager/Auditor simultaneously (never to the flagged user alone) and, for the highest-severity pattern (e.g., strong evidence of a fabricated vendor + control-account override combination), automatically requiring a second Finance Manager approval on that specific entry going forward (`accounting.control_account.override` becomes dual-control for that user/account pair via a temporary policy row) rather than acting on the entry itself — the human governance chain is what changes, never the historical record.

# API

All endpoints are versioned under `/api/v1/accounting/`, require a Sanctum/JWT bearer token, and are scoped to the active company via `X-Company-Id`. Every response uses the standard envelope defined in the shared platform conventions.

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/accounting/accounts` | `accounting.read` | List Chart of Accounts (tree or flat), filterable by type/status. |
| POST | `/api/v1/accounting/accounts` | `accounting.account.create` | Create a new account. |
| PATCH | `/api/v1/accounting/accounts/{id}` | `accounting.account.update` | Update account metadata (not its historical postings). |
| POST | `/api/v1/accounting/accounts/{id}/deactivate` | `accounting.account.deactivate` | Deactivate an account (blocks future postings; history unaffected). |
| GET | `/api/v1/accounting/fiscal-years` | `accounting.read` | List fiscal years and their periods. |
| POST | `/api/v1/accounting/fiscal-years` | `accounting.fiscal_year.close` (create scope) | Create a fiscal year and its periods. |
| POST | `/api/v1/accounting/fiscal-years/{id}/close` | `accounting.fiscal_year.close` | Run year-end closing entries. |
| POST | `/api/v1/accounting/fiscal-periods/{id}/close` | `accounting.period.close` | Close a period. |
| POST | `/api/v1/accounting/fiscal-periods/{id}/reopen` | `accounting.period.reopen` | Reopen a closed period. |
| GET | `/api/v1/accounting/journal-entries` | `accounting.read` | List journal entries (filters per Search Engine). |
| GET | `/api/v1/accounting/journal-entries/search` | `accounting.read` | Full search endpoint, all filters. |
| POST | `/api/v1/accounting/journal-entries` | `accounting.journal.create` | Create a draft (or AI draft) entry. |
| GET | `/api/v1/accounting/journal-entries/{id}` | `accounting.read` | Retrieve one entry with lines. |
| PATCH | `/api/v1/accounting/journal-entries/{id}` | `accounting.journal.update` | Edit a draft entry (rejected if not draft). |
| POST | `/api/v1/accounting/journal-entries/{id}/post` | `accounting.journal.post` | Post a draft entry. |
| POST | `/api/v1/accounting/journal-entries/{id}/reverse` | `accounting.journal.reverse` | Reverse a posted entry. |
| POST | `/api/v1/accounting/journal-entries/{id}/void` | `accounting.journal.void` | Void a draft/pending entry. |
| POST | `/api/v1/accounting/journal-entries/batch-post` | `accounting.journal.post` | Batch-post an array of draft entry ids. |
| GET | `/api/v1/accounting/ledger-entries/search` | `accounting.read` | Search posted ledger entries. |
| GET | `/api/v1/accounting/reports/trial-balance` | `accounting.report.read` | Trial Balance report. |
| GET | `/api/v1/accounting/reports/general-ledger` | `accounting.report.read` | General Ledger report for one/many accounts. |
| GET | `/api/v1/accounting/reports/account-statement` | `accounting.report.read` | Account Statement for a customer/vendor control account. |
| GET | `/api/v1/accounting/reports/movement` | `accounting.report.read` | Movement report by dimension. |
| POST | `/api/v1/accounting/reports/{type}/export` | `accounting.report.export` | Export/schedule any report. |
| POST | `/api/v1/accounting/ai/explain-activity` | `accounting.read` | Invoke General Accountant Agent's explanation. |
| GET | `/api/v1/accounting/ai/flags` | `accounting.read` | List AI anomaly/duplicate flags. |
| POST | `/api/v1/accounting/ai/flags/{id}/approve-correction` | `accounting.ai.approve_draft` | Approve an AI-drafted correcting entry (moves it to postable). |

## Request/Response Examples

**1. Create a draft journal entry**

```json
POST /api/v1/accounting/journal-entries
{
  "entry_date": "2026-07-16",
  "fiscal_period_id": 43,
  "entry_type": "standard",
  "description": "Reclassify prepaid insurance to expense for July",
  "reference": "PREPAID-2026-07",
  "lines": [
    { "account_id": 5210, "debit_amount": 350.0000, "currency_code": "KWD",
      "cost_center_id": 12, "memo": "July portion of annual policy" },
    { "account_id": 1160, "credit_amount": 350.0000, "currency_code": "KWD",
      "memo": "Prepaid Insurance - July release" }
  ]
}
```

Response:

```json
{
  "success": true,
  "data": {
    "id": 90441,
    "entry_number": "JE-2026-000512",
    "status": "draft",
    "total_debit": "350.0000",
    "total_credit": "350.0000",
    "fiscal_period_id": 43,
    "lines": [
      { "id": 210981, "line_number": 1, "account_id": 5210, "debit_amount": "350.0000", "credit_amount": "0.0000" },
      { "id": 210982, "line_number": 2, "account_id": 1160, "debit_amount": "0.0000", "credit_amount": "350.0000" }
    ]
  },
  "message": "Journal entry created as draft.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1f0a9b62-3d4e-4a10-9f8a-2b6d7a9e3c55",
  "timestamp": "2026-07-16T10:04:11Z"
}
```

**2. Post the entry**

```json
POST /api/v1/accounting/journal-entries/90441/post
{}
```

```json
{
  "success": true,
  "data": {
    "id": 90441, "entry_number": "JE-2026-000512", "status": "posted",
    "posted_by": 118, "posted_at": "2026-07-16T10:05:02Z"
  },
  "message": "Journal entry posted successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "77e6a9c1-5b0d-4a2e-8b9a-2c1f0e3d9a44",
  "timestamp": "2026-07-16T10:05:02Z"
}
```

**3. Attempt to post into a closed period (error example)**

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    { "field": "fiscal_period_id", "code": "PERIOD_CLOSED",
      "message": "Fiscal period 'Jun 2026' is closed. Reopen the period (requires accounting.period.reopen) or choose an open period." }
  ],
  "meta": { "pagination": null },
  "request_id": "a20d5e77-9c11-4f0a-8e2b-6b7c1d0e9f33",
  "timestamp": "2026-07-16T10:06:40Z"
}
```

**4. Reverse a posted entry**

```json
POST /api/v1/accounting/journal-entries/90441/reverse
{ "reversal_date": "2026-07-17", "reason": "Original amount was incorrect — should be 320.0000, not 350.0000" }
```

```json
{
  "success": true,
  "data": {
    "original_entry_id": 90441,
    "reversing_entry": {
      "id": 90478, "entry_number": "JE-2026-000549", "status": "posted",
      "entry_type": "reversing", "reverses_entry_id": 90441,
      "entry_date": "2026-07-17", "total_debit": "350.0000", "total_credit": "350.0000"
    }
  },
  "message": "Journal entry reversed. Original entry is now marked 'reversed' and remains queryable.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9d1b2e44-6a0c-4f9d-9a1e-3b8c7d2f0e12",
  "timestamp": "2026-07-17T08:15:30Z"
}
```

**5. Ledger entries search with pagination**

```json
GET /api/v1/accounting/ledger-entries/search?account_id=1131&from=2026-01-01&to=2026-06-30&sort=entry_date:desc&page=1&per_page=25
```

```json
{
  "success": true,
  "data": [
    { "id": 550231, "entry_date": "2026-06-28", "account_id": 1131,
      "debit_amount": "1200.0000", "credit_amount": "0.0000",
      "description": "Invoice INV-2026-3301 - Al Zahra Trading Co.",
      "customer_id": 4021, "journal_entry_id": 90390 },
    { "id": 550119, "entry_date": "2026-06-15", "account_id": 1131,
      "debit_amount": "0.0000", "credit_amount": "800.0000",
      "description": "Receipt RCPT-2026-1187 applied to INV-2026-3210",
      "customer_id": 4021, "journal_entry_id": 90271 }
  ],
  "message": "Ledger entries retrieved successfully.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 118, "cursor": "eyJpZCI6NTUwMTE5fQ==" } },
  "request_id": "6b2f9a13-7d0e-4c8a-9b1e-5a3c8d2f7e01",
  "timestamp": "2026-07-16T11:20:15Z"
}
```

# Validation Rules

Validation happens at three layers, and each layer is authoritative for a different class of rule — the frontend is convenience, the FormRequest is contract, the database trigger is the last line of defense that must hold even if every layer above it is bypassed (a queue worker, a raw migration script, a future integration).

**Frontend (Next.js, immediate UX feedback, non-authoritative):**
- Debit and credit total must match before the "Post" button is enabled; live running total shown as the user types lines.
- Account picker only lists `is_leaf = true AND status = 'active'` accounts for the active company/branch.
- Currency selector restricted to `companies.active_currencies`.
- Warns (does not block) on empty `memo`/`reference` for entries above a configurable materiality threshold.

**Laravel FormRequest (`StoreJournalEntryRequest`, `PostJournalEntryRequest`, authoritative for shape/business rules before the DB is touched):**

| Rule | Validation |
|---|---|
| `entry_date` | required, valid date, must fall within an existing `fiscal_periods` row for the company. |
| `fiscal_period_id` | required, must belong to company, must resolve to `status IN ('open')` at submission time for a `post` action (draft creation tolerates `future` too, for pre-staging). |
| `lines` | required array, `min:2` (a journal entry with fewer than two lines cannot balance). |
| `lines.*.account_id` | required, exists in `accounts`, `company_id` matches, `is_leaf = true`, `status = 'active'`. |
| `lines.*.debit_amount` / `credit_amount` | required_without each other, numeric, `min:0.0001`, exactly one of the pair must be > 0 per line (mirrors the DB `CHECK`). |
| Balance | `SUM(debit_amount * exchange_rate) === SUM(credit_amount * exchange_rate)` in base currency, tolerance `0.0000` (zero — no rounding leniency at the request layer; rounding differences are resolved by an explicit rounding line, Business Rule 10, never by request-layer tolerance). |
| `currency_code` | required, must be in `companies.active_currencies`; if an account has `currency_restriction` set, every line against it must match. |
| `exchange_rate` | required if `currency_code <> companies.base_currency`, numeric, `> 0`; system auto-populates from the daily rate table if omitted, but never silently overrides a caller-supplied rate. |
| `customer_id` / `vendor_id` | must belong to the same `company_id`; forbidden together on a single line. |
| `cost_center_id` / `project_id` / `department_id` | must belong to the same `company_id`; status must be `active` if the dimension table has a status column. |
| Control account restriction | if `account_id` resolves to `is_control_account = true`, request is rejected with `403` unless caller holds `accounting.control_account.override` or the request's `origin` is a recognized system source. |

**Database (last line of defense, described fully in Data Model/Business Rules):** the `chk_journal_lines_one_side`, `chk_journal_lines_nonnegative`, `chk_journal_entries_balanced`, `fn_enforce_leaf_account_posting`, `fn_journal_lines_immutable`, and `fn_no_ai_autopost` constraints/triggers re-assert every rule above at the storage layer, so no code path — including a future integration, a data migration, or direct psql access during an incident — can produce an unbalanced or improperly-postable row.

# Notifications

Notifications are dispatched through the foundation `notifications` table and delivered via in-app, email, and (for critical items) SMS/push, per each user's notification preferences. GL-specific triggers:

| Event | Recipients | Channel | Content |
|---|---|---|---|
| `journal.posted` (high value, above company-configured materiality threshold) | Finance Manager, Owner | In-app + email | Entry summary, amount, account, poster. |
| `journal.draft.stale` (90 days untouched, Business Rule 9) | Draft's `created_by`, Finance Manager | In-app | "This draft has been untouched for 90 days." |
| `ai.anomaly_flag.critical` | Owner, Admin, Finance Manager, Auditor (simultaneously, per AI Responsibilities) | In-app + email + SMS | Flag reasoning, confidence, link to entry. |
| `ai.anomaly_flag.warning` | Finance Manager, entry's creator | In-app | Flag reasoning, confidence. |
| `ai.correction_draft.ready` | Accountant/Finance Manager holding `accounting.ai.approve_draft` | In-app | Link to the AI-drafted correcting entry for review. |
| `fiscal_period.closing_reminder` | All users with `accounting.journal.create` who have open drafts in the period | In-app + email | "Period {name} closes in 3 days; you have {n} unposted drafts." |
| `fiscal_period.closed` / `.reopened` | Finance Manager, Owner, Auditor | In-app + email | Who closed/reopened, when, reason if reopened. |
| `fiscal_year.closing_entries_posted` | Owner, Finance Manager, Auditor | In-app + email | Link to the Closing Entry and post-close Trial Balance. |
| `report_schedule.completed` / `.failed` | The schedule's owner | In-app + email | Link to generated report or failure reason. |
| `control_account.reconciliation_variance` | Finance Manager, Accountant | In-app | Raised when a control account's GL balance diverges from its sub-ledger total (see Edge Cases) beyond a configured tolerance. |

Every notification payload includes `request_id`/`journal_entry_id` (as applicable) so a user can jump directly from the notification to the underlying record — notifications are pointers into the ledger, never a parallel narrative that could drift from it.

# Security

## Encryption

All data in transit is TLS 1.2+ (enforced at the Cloudflare/load-balancer edge; no plaintext HTTP path exists for `/api/v1/*`). PostgreSQL data at rest is encrypted via the hosting provider's disk-level encryption (AES-256); no accounting-specific column-level encryption is applied to `journal_lines`/`ledger_entries` amounts, since these are not classified as personally-sensitive data — the sensitive adjacency (customer/vendor identity) is protected via the `customers`/`vendors` tables' own security posture (out of scope for this document, owned by the Customers/Vendors module docs) and via RLS-equivalent scoping described below. Exported report files (PDF/XLSX) written to Cloudflare R2 are encrypted at rest by R2 and served only via short-lived signed URLs, never public paths.

## Audit Logging

Every state-changing action in this module writes a row to `audit_logs` (foundation table: `actor_id`, `action`, `entity_type`, `entity_id`, `old_value` JSONB, `new_value` JSONB, `ip_address`, `device`, `created_at`), in addition to the domain-specific `created_by`/`posted_by`/`approved_by`/`voided_by`/`reversed_by` columns already on `journal_entries`. Specifically logged: account create/update/deactivate, draft create/update, post, reverse, void, period close/reopen, fiscal year close, AI-draft approval, control-account override usage, and every export of a report containing amounts (`accounting.report.export` actions are logged with the exact filter parameters used, so "who saw what numbers, filtered how, and when" is fully reconstructable — critical for detecting reconnaissance ahead of fraud, not just the fraud itself). Audit log rows are themselves immutable (no `UPDATE`/`DELETE` grants on `audit_logs` for any application role) and retained for a minimum of 7 years per standard Gulf commercial-record retention practice, configurable longer per company/jurisdiction.

## Access Control

Multi-tenancy isolation (Business Rule / platform convention: every query scoped by `company_id` from `X-Company-Id`, enforced by a Laravel global model scope applied automatically to every Eloquent query against every table in this document) is the primary access-control boundary; there is no code path in the Laravel service layer that constructs a query without it, and integration tests assert this by attempting cross-company reads in CI and asserting `404`/`403`. Within a company, the RBAC permission matrix in Permissions is enforced at the Laravel Policy layer (`JournalEntryPolicy`, `AccountPolicy`, `FiscalPeriodPolicy`), evaluated on every controller action before the FormRequest even validates payload shape — a user without `accounting.journal.post` receives `403` before the server does any work interpreting the post request. Field-level access control additionally hides `ai_reasoning`/`ai_confidence` from roles without `accounting.read` scoped to AI visibility (configurable per company for competitive-sensitivity reasons — some companies don't want line staff to see that an entry was AI-flagged as anomalous, only that it requires re-review). Session tokens (Sanctum/JWT) are scoped with a maximum 24-hour lifetime and are invalidated immediately on password change, permission change, or company-membership removal.

# Performance

## Indexes

The index set in Data Model is tuned for the two dominant query shapes: (a) "give me all activity for account X in date range Y" (`idx_ledger_account_date`, `idx_ledger_entries_composite_report`), and (b) "give me every account's totals for period P" (`idx_ledger_period`). The `idx_ledger_entry_date_brin` BRIN index is deliberately cheap (a few KB regardless of table size) and effective specifically because `ledger_entries` rows are inserted in ever-increasing `entry_date`/`posted_at` order in the overwhelming majority of real-world usage (backdated entries into an already-open period are the exception, not the rule), making BRIN's range-summarization assumption valid; it is used as a secondary filter to prune partitions/pages before the B-tree indexes narrow further.

## Caching

`mv_account_balances` is a summary table (not a Postgres `MATERIALIZED VIEW` in the literal sense, for incremental-refresh reasons — implemented as a regular table maintained by the `RefreshAccountBalanceCache` job) keyed by `(company_id, account_id, fiscal_period_id)` holding `opening_balance`, `period_debit`, `period_credit`, `closing_balance`, `last_refreshed_at`. It is updated incrementally: on each posted entry, the job adds the new lines' amounts to the affected `(account_id, fiscal_period_id)` rows rather than recomputing from scratch, making the refresh O(affected accounts) rather than O(table size). A nightly `accounting:verify-ledger-integrity` job recomputes every row from `ledger_entries` directly and diffs against the cache, alerting on any discrepancy (which should never occur, and if it does, is treated as a P0 bug in the incremental-update logic, not "expected drift").

```sql
CREATE TABLE mv_account_balances (
    company_id        BIGINT NOT NULL,
    account_id        BIGINT NOT NULL,
    fiscal_period_id  BIGINT NOT NULL,
    opening_balance    NUMERIC(19,4) NOT NULL DEFAULT 0,
    period_debit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    period_credit      NUMERIC(19,4) NOT NULL DEFAULT 0,
    closing_balance    NUMERIC(19,4) NOT NULL DEFAULT 0,
    last_refreshed_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, account_id, fiscal_period_id)
);
```

Redis caches the current-period Trial Balance response (`GET /reports/trial-balance` for the company's currently-open period) with a 5-second TTL and a cache-buster on any `journal.posted` event affecting that period, giving sub-10ms response for the overwhelmingly common "show me today's trial balance" dashboard load while never serving data staler than 5 seconds (and the response always carries `last_refreshed_at` so the client can display cache freshness).

## Materialized Views

`mv_trial_balance` and `mv_account_running_balance` are true Postgres `MATERIALIZED VIEW`s, refreshed concurrently (`REFRESH MATERIALIZED VIEW CONCURRENTLY`, requiring the unique index shown) on a debounced schedule (Background Jobs) rather than per-transaction, because a full concurrent refresh of a multi-million-row view is not cheap enough to run on every posting:

```sql
CREATE MATERIALIZED VIEW mv_trial_balance AS
SELECT le.company_id, le.fiscal_period_id, le.account_id,
       a.code, a.name_en, a.name_ar, a.normal_balance,
       SUM(le.base_debit_amount) AS period_debit,
       SUM(le.base_credit_amount) AS period_credit,
       SUM(le.signed_base_amount) AS net_movement
FROM ledger_entries le
JOIN accounts a ON a.id = le.account_id
GROUP BY le.company_id, le.fiscal_period_id, le.account_id, a.code, a.name_en, a.name_ar, a.normal_balance;
CREATE UNIQUE INDEX uq_mv_trial_balance ON mv_trial_balance (company_id, fiscal_period_id, account_id);

CREATE MATERIALIZED VIEW mv_account_running_balance AS
SELECT le.company_id, le.account_id, le.id AS ledger_entry_id, le.entry_date,
       SUM(le.signed_base_amount) OVER (
           PARTITION BY le.company_id, le.account_id
           ORDER BY le.entry_date, le.id
       ) AS running_balance
FROM ledger_entries le;
CREATE UNIQUE INDEX uq_mv_running_balance ON mv_account_running_balance (company_id, account_id, ledger_entry_id);
```

## Partitioning

`journal_lines` and `ledger_entries` are range-partitioned by `entry_date` on a yearly boundary once a company's row count crosses ~5 million rows (a per-company decision made by the platform's data-ops runbook, not a schema-wide default, since most companies never reach this volume). Partitioning by year aligns naturally with fiscal-year closing (a fully closed year's partition becomes effectively read-only and is an ideal candidate for a colder, cheaper storage tier) and keeps every index in this document scoped to a manageable partition size rather than one monolithic multi-hundred-million-row table.

```sql
CREATE TABLE ledger_entries_y2026 PARTITION OF ledger_entries
    FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
```

(Applied only after converting the base table to `PARTITION BY RANGE (entry_date)` during a scheduled migration window — existing rows are migrated via `pg_partman`-style backfill, not a blocking `ALTER TABLE`.)

## Background Processing

All refresh, anomaly-detection, notification, and report-generation work is queued (Redis + Laravel queues), never executed inline with a user's HTTP request, per the Posting Engine's Background Jobs section — this is the single biggest lever keeping p99 posting latency under 200ms regardless of company size, because the work that scales with historical data volume (materialized view refresh, AI analysis) is decoupled from the work that scales with a single transaction's line count (the atomic posting transaction itself).

# Edge Cases

1. **Posting straddling a period boundary during close.** A user is mid-edit on a draft dated the last day of a period when Finance Manager closes that period. Resolution: the period-close action itself does not touch existing drafts; it only blocks *new* posts into it going forward. The draft posts successfully if posted before close completes (transaction-serialized via the period row lock in Real Time Posting step 4); if close completes first, the subsequent post attempt receives `PERIOD_CLOSED` and the user must either get the period reopened or re-date the entry into the next open period.
2. **Exchange rate unavailable for a required currency/date pair.** If no daily rate exists for the requested `currency_code`/`entry_date` combination and the caller didn't supply one, the request is rejected with `422` (`code: EXCHANGE_RATE_MISSING`) rather than silently defaulting to 1.0 or the nearest available date — a wrong assumed rate is worse than a blocked entry.
3. **Reversing a partially-reconciled entry.** Reversing a posted entry that a bank reconciliation (owned by the Banking module) has already matched leaves the reconciliation's matched-line reference dangling. The Posting Engine emits `journal.reversed` specifically (distinct from `journal.posted`) so the Banking module's own listener can un-match and flag the reconciliation for manual re-review — the GL does not attempt to reach into Banking's tables itself (module isolation, Ledger Architecture).
4. **Control account variance.** If, due to a bug elsewhere or a permitted manual override, a control account's GL balance no longer equals the sum of its sub-ledger (e.g., `accounts.code = '1131'` Trade Receivables vs. `SUM(customers.outstanding_balance)`), the nightly reconciliation job raises `control_account.reconciliation_variance` (Notifications) rather than auto-correcting — auto-correcting a control-account variance without human investigation risks masking the actual root-cause bug.
5. **Zero-amount lines.** Rejected outright (`chk_journal_lines_nonnegative` combined with the FormRequest's `min:0.0001`) — a zero-value line carries no accounting meaning and is almost always a data-entry error (e.g., a formula-driven template line that resolved to zero for an inactive asset); the template-generation flow specifically omits zero-resolving lines rather than posting them.
6. **Same account on both sides of one entry.** Permitted (e.g., reclassifying between two cost centers on the same GL account, using different `cost_center_id` per line) — the balance check operates on total debit vs. total credit across the whole entry, not per-account, so this is a legitimate and common pattern for dimension-only reclassification.
7. **Retroactive account deactivation with existing balance.** An account may be deactivated (`status = 'inactive'`) even with a non-zero balance (e.g., winding down a discontinued bank account) — deactivation blocks *new* postings but never hides historical activity; the UI surfaces "inactive, non-zero balance" accounts prominently on the Chart of Accounts screen as an outstanding item requiring a closing/transfer entry before final archival.
8. **Fiscal year with more than 12 periods.** The `weekly_4_4_5` and `custom` `period_type`s support 13+ periods per year (common in retail); every period-based report in this document groups by `fiscal_period_id`, never by a hardcoded month number, so this requires zero special-casing anywhere in Reports or the Posting Engine.
9. **AI drafts referencing since-deactivated accounts.** If an AI-drafted correction sits unapproved long enough that its target account is deactivated in the interim, the approval action (`/ai/flags/{id}/approve-correction`) re-validates account status at approval time (mirroring the posting engine's re-validation philosophy) and rejects with a clear error rather than surfacing a stale, now-invalid draft as approvable.
10. **Concurrent batch-post of entries that reference each other.** If a batch includes both an entry and its own template-generated dependent (rare, but possible with chained recurring templates), the `BatchPostJournalEntries` job processes entries within a chunk in `entry_date`, then `id` order, ensuring earlier-dated foundational entries post (and thus their account balances update) before any same-batch entry that might logically depend on them for context — though no journal entry is ever technically blocked from posting by another's posted status, since balance validity per entry is always self-contained (Business Rule 1).

# Future Improvements

1. **Consolidated multi-company reporting.** Elimination-entry framework for related companies (parent/subsidiary) to produce a group Trial Balance and group financial statements without relaxing the per-company data isolation described in Multi Company.
2. **Continuous close.** Real-time period-close readiness scoring (percentage of expected adjusting entries posted, all sub-ledgers reconciled, all AI flags resolved) surfaced as a live dashboard, moving the close from a end-of-month event to a continuously-monitored state.
3. **AI-assisted close memo generation.** Extending the Reporting Agent to draft a fully substantiated month-end close narrative (variance explanations, control account reconciliation summary, open-item list) as a human-reviewable first draft.
4. **Configurable materiality thresholds per account/account-type**, driving both the notification thresholds in Notifications and the sensitivity of the Fraud Detection Agent's flagging, rather than a single company-wide threshold.
5. **Intercompany journal automation.** A structured `is_intercompany` flag and paired-entry auto-generation for transactions between two companies in the same group, replacing today's manual mirrored entries.
6. **Ledger-level XBRL/iXBRL export** for jurisdictions requiring structured regulatory filing directly from posted ledger data.
7. **Real-time collaborative draft editing** (multiple accountants co-editing a large draft entry with live presence indicators), building on the existing optimistic-locking `lock_version` foundation in Versioning.
8. **Configurable dual-control (maker-checker) posting** for any account or amount threshold a company chooses, generalizing today's hardcoded control-account override behavior into a company-configurable approval-chain engine.

# End of Document
