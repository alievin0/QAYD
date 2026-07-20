# Accounting Service (Double-Entry Core) — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: ACCOUNTING_SERVICE
---

# Purpose

This document specifies the **Accounting Service** — the backend module that owns the double-entry
core of QAYD: the chart of accounts, journal entries and their lines, the posting engine, the general
ledger, the trial balance, fiscal periods and their close/lock, and the assembly of the financial
statements. It is the single most invariant-heavy module in the platform, because it is where the
platform's central promise lives: **every posting balances (debit = credit), posting is transactional
and atomic, and a posted record is immutable — a mistake is corrected by a reversing entry, never by
an edit.**

Where [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) describes the whole Laravel application and
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) defines the shared Action/Service pattern and the
`PostingService` invariant in the abstract, this document is the concrete `accounting` module: the
Actions, Services, tables, permissions, events, and error semantics an engineer implements against
PostgreSQL. It is the backend-service counterpart to the domain specifications in
[../accounting/CHART_OF_ACCOUNTS.md](../accounting/CHART_OF_ACCOUNTS.md),
[../accounting/JOURNAL_ENTRIES.md](../accounting/JOURNAL_ENTRIES.md),
[../accounting/GENERAL_LEDGER.md](../accounting/GENERAL_LEDGER.md),
[../accounting/TRIAL_BALANCE.md](../accounting/TRIAL_BALANCE.md), and
[../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md); where a domain doc goes
deeper on accounting theory, it wins on the theory, and this document wins on the service shape.

The module is also the terminal consumer of the rest of the platform. Sales, Purchasing, Banking,
Inventory, Payroll, and Tax never write a `journal_line` themselves; they emit domain events, and the
Accounting Service reacts by posting the ledger effect through the *same* Actions a human uses. And it
is where AI-drafted entries land: an AI proposal enters as a `draft` with `origin = 'ai_draft'`,
awaiting a human holding `accounting.journal.post`. There is exactly one posting code path, and no
caller — human, module, scheduler, or AI — is exempt from it.

All money is stored `NUMERIC(19,4)`; KWD is the default base currency and presents to 3 decimals while
computing on the stored 4. Everything is Laravel 12 / PHP 8.4+ per
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md).

# Responsibilities

The Accounting Service owns the derivation chain from a chart of accounts to a set of financial
statements, and every invariant along it:

- **Chart of accounts** — the `accounts` tree and its `account_types` classification: creation,
  reclassification, activation/deactivation, control-account designation, opening balances, and the
  guarantee that a posted account cannot be silently renumbered or retyped.
- **Journal entries** — the `journal_entries` header and `journal_lines` body across the finite state
  machine `draft → pending_approval → posted → reversed/voided`, with lines mutable *only* while the
  parent is a draft.
- **The posting engine** — the single authorized writer of posted lines, enforcing the
  debit = credit invariant inside one database transaction, resolving the fiscal period, assigning the
  entry number, and projecting each posted line into `ledger_entries`.
- **The general ledger** — `ledger_entries` as the deterministic, rebuildable projection of posted
  lines, and the account-activity / running-balance reads built on it.
- **The trial balance** — the real-time balanced check and the durable, approvable
  `trial_balance_snapshots` artifact.
- **Fiscal periods and close/lock** — `fiscal_years` and `fiscal_periods` status transitions
  (`open → closed → locked`), the module-lock matrix, and the rule that no posting enters a
  non-open period.
- **Financial statements** — assembling the Balance Sheet, Income Statement, Cash Flow Statement, and
  Statement of Changes in Equity as views over posted lines, validating the accounting equation before
  presenting.
- **Dimensions** — carrying `cost_center_id`, `project_id`, `department_id`, and `branch_id` on every
  line so the same ledger data slices by business dimension without duplicating accounts.
- **AI-draft intake** — accepting `origin = 'ai_draft'` entries as drafts only, never letting the AI
  layer post directly.

It does **not** own the source documents that generate automatic entries (an invoice belongs to Sales,
a payroll run to Payroll); it reacts to their events. It does not own approval-chain *mechanics* (that
is the cross-cutting workflow engine); it declares which entries require approval and consumes the
decision.

# Domain Model

The domain is a strict derivation with a single direction: primary facts are `journal_entries` +
`journal_lines`; everything else is computed or projected from posted lines and is rebuildable.

```
   account_types (7 natures, system-seeded, is_balance_sheet)
        │ classifies
        ▼
     accounts (COA tree, parent_id, normal_balance, control_account_of)
        │ referenced by
        ▼
   journal_entries (header, status, entry_type, origin) 1───* journal_lines (debit|credit, dimensions)
        │  post()  [debit = credit, period open, atomic]
        ▼
   ledger_entries (1:1 projection of each posted line, signed_base_amount)   ← rebuildable from lines
        │ aggregated by
        ├──▶ trial_balance_snapshots (+ _lines, _approvals, _adjustments)   ← durable, approvable
        └──▶ financial_statement_snapshots (BS / P&L / CF / SoCE)           ← performance cache of a view

   fiscal_years (future→open→closing→closed) 1───* fiscal_periods (open→closed→locked, module_lock)
```

The invariants that define the domain:

- **The balance rule.** For every `posted` `journal_entries` row,
  `SUM(journal_lines.debit_amount) = SUM(journal_lines.credit_amount)` exactly, to four decimals, in
  the entry's currency *and* independently in base currency. Zero tolerance; re-derived server-side
  from the lines at posting time, never trusted from the client or the cached header totals.
- **Immutability of posted records.** No field on a `posted`, `reversed`, `voided`, or `archived`
  entry or any of its lines ever changes via `UPDATE`. A correction is a reversing entry
  (`reverses_entry_id` / `reversed_by_entry_id`), preserving an unbroken audit chain.
- **Lines mutable only while the parent is a draft.** `journal_lines` are freely editable while the
  header is `draft` (or `rejected`), under optimistic concurrency (`version`); the moment the header is
  `posted` they are frozen at the application layer, at the RLS/trigger layer, and by the absence of any
  update path in the service.
- **Single direction of derivation.** `ledger_entries` is a 1:1 projection of posted `journal_lines`;
  balances, trial balances, and statements are always computed from posted lines, never stored as an
  independent number that could drift. Dropping and rebuilding `ledger_entries` from the lines produces
  byte-identical reports — the nightly integrity job does exactly this.
- **No posting into a non-open period, no posting to an inactive account.** Both are re-checked at
  posting time (not merely at draft creation), under a `FOR UPDATE` lock on the period row.
- **AI never posts.** Any entry with `origin = 'ai_draft'` is created `draft` unconditionally; a
  database trigger rejects an attempt to insert such a row directly as `posted`, regardless of caller.

The recurring value objects are `JournalDraft` (a balanced-or-not proposed header + lines, the input
to the posting engine), `Money` (a fixed-scale `NUMERIC(19,4)` value object — never a float — that the
balance assertion compares with `equals`), and `LedgerProjection` (the set of rows a post writes).

# Key Classes

Actions carry the use cases; the `PostingService` is a domain service because posting, balance
assertion, period resolution, and projection genuinely share internals and must never be duplicated
across callers. Every entrypoint takes a DTO; the controller stays thin.

```php
namespace App\Actions\Accounting;

use App\Data\Accounting\JournalEntryData;
use App\Domain\Accounting\JournalDraft;
use App\Events\Accounting\JournalPosted;
use App\Exceptions\Accounting\ImmutableRecordException;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Repositories\Accounting\JournalRepository;
use Illuminate\Support\Facades\DB;

/** Post a draft (or approved) entry to the ledger. The single authorized post path for a human click. */
final class PostJournalEntryAction
{
    public function __construct(
        private readonly JournalRepository $journals,
        private readonly PostingService $posting,
    ) {}

    public function execute(int $entryId, User $actor, ?string $idempotencyKey = null): JournalEntry
    {
        return DB::transaction(function () use ($entryId, $actor, $idempotencyKey): JournalEntry {
            $entry = $this->journals->lockForPosting($entryId);   // SELECT ... FOR UPDATE

            if ($entry->isTerminal()) {                           // posted/reversed/voided
                throw new ImmutableRecordException($entry, action: 'reverse');   // 409 immutable_record
            }

            $draft = JournalDraft::fromEntry($entry);
            $posted = $this->posting->post($draft, $actor, $idempotencyKey);   // asserts balance, projects

            event(new JournalPosted(
                companyId: $posted->company_id,
                journalEntryId: $posted->id,
                entryType: $posted->entry_type,
                sourceType: $posted->source_type,
                sourceId: $posted->source_id,
            ));   // dispatched to listeners only after commit

            return $posted;
        });
    }
}
```

The `PostingService` is the invariant's home — it is where debit = credit is proven and where the
`ledger_entries` projection is written, in the same transaction:

```php
namespace App\Services\Accounting;

use App\Domain\Accounting\JournalDraft;
use App\Exceptions\Accounting\UnbalancedEntryException;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\InactiveAccountException;
use App\Models\Accounting\JournalEntry;
use App\Repositories\Accounting\LedgerRepository;
use App\Repositories\Accounting\FiscalPeriodRepository;

final class PostingService
{
    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly FiscalPeriodRepository $periods,
    ) {}

    public function post(JournalDraft $draft, $actor, ?string $idempotencyKey): JournalEntry
    {
        $this->assertBalanced($draft);                     // debit = credit, both currencies, zero tolerance

        $period = $this->periods->lockOpenForDate($draft->entryDate());  // FOR UPDATE; open only
        if ($period === null) {
            throw new ClosedPeriodException($draft->entryDate());        // 422 closed_period
        }

        foreach ($draft->lines() as $line) {
            if (! $this->ledger->accountIsPostable($line->accountId)) {  // active + leaf + in company
                throw new InactiveAccountException($line->accountId);    // 422 inactive_account
            }
        }

        $entry = $this->ledger->markPosted($draft, $actor, $period);     // status=posted, number, totals
        $this->ledger->projectLines($entry);                             // one ledger_entries row per line
        return $entry;
    }

    private function assertBalanced(JournalDraft $draft): void
    {
        // Re-derive from the lines themselves — never trust the cached header totals or the client.
        $debit  = $draft->totalDebit();     // Money value object over NUMERIC(19,4)
        $credit = $draft->totalCredit();

        if (! $debit->equals($credit)) {
            throw new UnbalancedEntryException($debit, $credit);   // 422 balance_mismatch
        }
    }
}
```

The full collaborator set:

- **`CreateJournalEntryAction`, `UpdateJournalDraftAction`, `SubmitForApprovalAction`,
  `PostJournalEntryAction`, `ReverseJournalEntryAction`, `VoidJournalEntryAction`** — the entry
  lifecycle. Update/void refuse to touch a posted record.
- **`PostingService`** — the double-entry invariant, period resolution, entry-number assignment, and
  the `ledger_entries` projection. Invoked identically by a human click, a cross-module listener, a
  scheduled depreciation job, and an approved AI draft.
- **`CreateAccountAction`, `ReclassifyAccountAction`, `SetOpeningBalanceAction`,
  `DeactivateAccountAction`** — chart-of-accounts management, guarding the "posted accounts can't be
  silently retyped" rule.
- **`TrialBalanceService`** — the real-time balanced check (`fn_compute_trial_balance`) and the durable
  `GenerateTrialBalanceSnapshotAction` that freezes, and can approve/export, a snapshot.
- **`ClosePeriodAction`, `LockPeriodAction`, `ReopenPeriodAction`, `CloseFiscalYearAction`** — the
  fiscal calendar state machine and the module-lock matrix.
- **`FinancialStatementService`** — assembles the four statements as views over posted lines, validates
  the accounting equation, and caches to `financial_statement_snapshots`.
- **`AccountBalanceProjector`** — the queued listener that refreshes `mv_account_balances` and the
  monthly-movement materialized views after a post.

# Endpoints Backed

Every endpoint lives under `/api/v1/accounting` and returns the platform envelope. Money is serialized
as strings; account and statement-line names are bilingual (`name_en` / `name_ar`). Money-moving posts
require an `Idempotency-Key`.

| Method & path | Action / Service | Permission |
|---|---|---|
| `GET/POST /accounting/accounts` | `CreateAccountAction` / list | `accounting.coa.manage` / `accounting.journal.read` |
| `PATCH /accounting/accounts/{id}` | `UpdateAccountAction` | `accounting.coa.manage` |
| `POST /accounting/accounts/{id}/reclassify` | `ReclassifyAccountAction` | `accounting.coa.manage` |
| `POST /accounting/accounts/{id}/deactivate` | `DeactivateAccountAction` | `accounting.coa.manage` |
| `POST /accounting/accounts/{id}/opening-balance` | `SetOpeningBalanceAction` | `accounting.coa.manage` |
| `GET/POST /accounting/journal-entries` | `CreateJournalEntryAction` / list | `accounting.journal.create` / `accounting.journal.read` |
| `PATCH /accounting/journal-entries/{id}` | `UpdateJournalDraftAction` | `accounting.journal.create` (draft only) |
| `POST /accounting/journal-entries/{id}/submit` | `SubmitForApprovalAction` | `accounting.journal.create` |
| `POST /accounting/journal-entries/{id}/post` | `PostJournalEntryAction` | `accounting.journal.post` |
| `POST /accounting/journal-entries/{id}/reverse` | `ReverseJournalEntryAction` | `accounting.journal.reverse` |
| `POST /accounting/journal-entries/{id}/void` | `VoidJournalEntryAction` | `accounting.journal.void` |
| `GET /accounting/ledger/accounts/{id}/activity` | `LedgerQueryService` | `accounting.journal.read` |
| `GET /accounting/trial-balance` | `TrialBalanceService::compute` | `accounting.trial_balance.read` |
| `POST /accounting/trial-balance/snapshots` | `GenerateTrialBalanceSnapshotAction` | `accounting.trial_balance.generate` |
| `POST /accounting/trial-balance/snapshots/{id}/approve` | `TrialBalanceService::approve` | `accounting.trial_balance.approve` |
| `GET /accounting/statements/{type}` | `FinancialStatementService::assemble` | `accounting.report.read` |
| `POST /accounting/periods/{id}/close` | `ClosePeriodAction` | `accounting.period.close` |
| `POST /accounting/periods/{id}/lock` | `LockPeriodAction` | `accounting.period.lock` |
| `POST /accounting/periods/{id}/reopen` | `ReopenPeriodAction` | `accounting.period.reopen` |
| `POST /accounting/fiscal-years/{id}/close` | `CloseFiscalYearAction` | `accounting.fiscal_year.close` |

`{type}` is one of `balance-sheet`, `income-statement`, `cash-flow`, `changes-in-equity`. A read of a
posted entry returns `403`/`409` on any write attempt; a cross-tenant id returns `404` at route-model
binding. Long statement/snapshot generations that exceed the latency budget return `202 Accepted` and
finish on the `reports` queue, per [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md).

A `POST /accounting/journal-entries` request and its envelope response illustrate the wire contract —
money as strings, bilingual account names on the read side, dimensions on each line:

```jsonc
// POST /api/v1/accounting/journal-entries
// Headers: X-Company-Id: <uuid>, Idempotency-Key: <uuid>
{
  "entry_date": "2026-07-20",
  "entry_type": "standard",
  "description": "Office rent — July 2026",
  "currency_code": "KWD",
  "lines": [
    { "account_id": 4021, "debit_amount": "1500.000", "credit_amount": "0.000",
      "cost_center_id": 12, "department_id": 3, "memo": "HQ lease" },
    { "account_id": 1010, "debit_amount": "0.000",    "credit_amount": "1500.000" }
  ]
}
```

```jsonc
// 201 Created — the platform envelope
{
  "success": true,
  "data": {
    "id": 88214,
    "entry_number": "JE-2026-000482",
    "status": "draft",                 // never 'posted' straight from create
    "entry_date": "2026-07-20",
    "total_debit": "1500.000",
    "total_credit": "1500.000",
    "balanced": true,
    "lines": [
      { "line_number": 1, "account": { "id": 4021, "code": "5100",
        "name_en": "Rent Expense", "name_ar": "مصروف الإيجار" },
        "debit_amount": "1500.000", "credit_amount": "0.000" },
      { "line_number": 2, "account": { "id": 1010, "code": "1000",
        "name_en": "Cash", "name_ar": "النقدية" },
        "debit_amount": "0.000", "credit_amount": "1500.000" }
    ]
  },
  "message": "accounting.journal.created",
  "errors": [],
  "meta": {},
  "request_id": "01J...",
  "timestamp": "2026-07-20T09:14:22Z"
}
```

An unbalanced submission never reaches `draft`-to-`posted`: `POST .../{id}/post` on lines whose debit
and credit totals differ returns `422` with `errors[0].code = "balance_mismatch"` and the two totals
in `meta`, so the client sees exactly which side is short.

# Database Tables Owned

The `accounting` module owns the tables below. All carry the platform's standard columns (`id`,
`company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`); money
is `NUMERIC(19,4)`; JSON configuration is `JSONB`. The canonical DDL lives in the domain docs; the
service-relevant shape is summarized here.

```sql
CREATE TYPE journal_entry_status AS ENUM ('draft','pending_approval','posted','reversed','voided');
CREATE TYPE journal_entry_origin AS ENUM ('manual','system','import','ai_draft');
CREATE TYPE fiscal_period_status AS ENUM ('future','open','closed','locked');

-- account_types: 7 system-seeded natures (asset/liability/equity/revenue/expense/other income/other
--   expense), company_id NULL, is_balance_sheet, normal_balance — never editable by a tenant.
-- accounts: the COA tree (parent_id, normal_balance, status, is_control_account,
--   control_account_of IN ('customers','vendors'), currency_restriction) — see CHART_OF_ACCOUNTS.md.

CREATE TABLE journal_entries (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    fiscal_period_id     BIGINT NOT NULL REFERENCES fiscal_periods(id),
    entry_number         VARCHAR(30) NOT NULL,            -- JE-2026-000482, assigned at post
    entry_date           DATE NOT NULL,
    entry_type           journal_entry_type NOT NULL DEFAULT 'standard',
    origin               journal_entry_origin NOT NULL DEFAULT 'manual',   -- 'ai_draft' ⇒ never auto-post
    status               journal_entry_status NOT NULL DEFAULT 'draft',
    description          TEXT NOT NULL,
    source_type          VARCHAR(50) NULL,                -- 'invoice','bill','payroll_run',...
    source_id            BIGINT NULL,
    reverses_entry_id    BIGINT NULL REFERENCES journal_entries(id),
    reversed_by_entry_id BIGINT NULL REFERENCES journal_entries(id),
    total_debit          NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_credit         NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code        VARCHAR(3) NOT NULL DEFAULT 'KWD',
    created_by_agent     VARCHAR(50) NULL,                -- AI agent id; NULL = human-authored
    ai_confidence        NUMERIC(5,4) NULL,
    version              INTEGER NOT NULL DEFAULT 1,       -- optimistic concurrency on drafts
    posted_by            BIGINT NULL REFERENCES users(id),
    posted_at            TIMESTAMPTZ NULL,
    -- + standard columns
    CONSTRAINT uq_journal_entries_company_number UNIQUE (company_id, entry_number),
    CONSTRAINT chk_journal_entries_balanced CHECK (status <> 'posted' OR total_debit = total_credit)
);

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
    customer_id        BIGINT NULL REFERENCES customers(id),
    vendor_id          BIGINT NULL REFERENCES vendors(id),
    cost_center_id     BIGINT NULL REFERENCES cost_centers(id),
    project_id         BIGINT NULL REFERENCES projects(id),
    department_id      BIGINT NULL REFERENCES departments(id),
    -- + standard columns
    CONSTRAINT uq_journal_lines_entry_number UNIQUE (journal_entry_id, line_number),
    CONSTRAINT chk_journal_lines_one_side CHECK (
        (debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0)),
    CONSTRAINT chk_journal_lines_nonnegative CHECK (debit_amount >= 0 AND credit_amount >= 0)
);

CREATE TABLE ledger_entries (                            -- 1:1 projection of each posted line
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    journal_entry_id   BIGINT NOT NULL REFERENCES journal_entries(id),
    journal_line_id    BIGINT NOT NULL REFERENCES journal_lines(id),
    account_id         BIGINT NOT NULL REFERENCES accounts(id),
    fiscal_period_id   BIGINT NOT NULL REFERENCES fiscal_periods(id),
    fiscal_year_id     BIGINT NOT NULL REFERENCES fiscal_years(id),
    entry_date         DATE NOT NULL,
    posted_at          TIMESTAMPTZ NOT NULL,
    base_debit_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_credit_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    signed_base_amount NUMERIC(19,4) NOT NULL,            -- +debit / -credit, normalized for fast SUM()
    -- dimensions + source copied from the line for filter-free reporting
    CONSTRAINT uq_ledger_entries_journal_line UNIQUE (journal_line_id)  -- append-only, never updated
);

-- fiscal_years (future→open→closing→closed) and fiscal_periods (open→closed→locked, module_lock JSONB)
--   — see GENERAL_LEDGER.md for full DDL.
-- trial_balance_snapshots (+ _lines, _approvals, _adjustments, _ai_findings, _exports) — see TRIAL_BALANCE.md.
-- financial_statement_templates / _lines / _snapshots — see FINANCIAL_STATEMENTS.md.
```

Two database-level guards back the application invariants independently, so an application bug cannot
breach them: `trg_journal_lines_no_update_when_posted` rejects any `UPDATE` to a line whose parent is
`posted`/`reversed`/`voided`, and `trg_no_ai_autopost` rejects inserting an `origin = 'ai_draft'` entry
directly as `posted`. `ledger_entries` has no update path at all.

# Multi-Tenancy Enforcement

Every accounting table is company-scoped and enforced by all four independent layers described in
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md):

1. **HTTP** — `ResolveTenantCompany` validates `X-Company-Id` against a live `company_users` row and
   issues `SET LOCAL app.current_company_id` inside the request transaction envelope.
2. **Eloquent** — every accounting model uses `BelongsToCompany`, so `CompanyScope` adds
   `WHERE company_id = ?` to every query and fills `company_id` on every insert. Actions never set
   `company_id` for isolation and never call `withoutCompanyScope()` on the business path.
3. **Policy** — accounting Policies answer only "may this actor do this in the active company?"; the
   tenant boundary itself was already enforced upstream, so a cross-tenant id has 404'd at route-model
   binding before a Policy runs.
4. **PostgreSQL RLS** — every accounting table carries a policy
   `USING (company_id = current_setting('app.current_company_id', true)::bigint)`; if the GUC is unset,
   the policy evaluates false and the query returns **zero rows**, never another tenant's rows. This is
   the deliberate fail-to-empty of the last line.

The double-entry transaction wraps the `SET LOCAL`, so tenant scoping and RLS hold for every statement
inside the post — the balance re-derivation, the period lock, the line projection — even under PgBouncer
transaction pooling. The `AccountBalanceProjector` and any depreciation/close job re-establish the
tenant context (`SET LOCAL app.current_company_id`) in `handle()` before touching a model, because a
queue worker has no ambient request context.

A concrete consequence: a trial balance, a ledger activity query, and a Balance Sheet are all built
from `SUM()` over `ledger_entries` — and because RLS scopes that table, there is no code path by which
Company A's posted lines can enter Company B's trial balance, even if a reporting query forgot a
`where('company_id', …)` clause. The scope and RLS supply it.

# Events, Queues & Realtime

The Accounting Service is the platform's ledger consumer: it *reacts* to source-document events by
posting, and it *emits* ledger facts other modules and the dashboard consume. Cross-module listeners
are queued and dispatch only after commit.

**Consumed** (the module posts the ledger effect via its own Actions — it never writes another
module's tables):

| Consumed event | Listener | Effect |
|---|---|---|
| `sales.invoice.posted` | `CreateJournalForInvoice` | `PostJournalEntryAction` with `source_type='invoice'`, idempotency-guarded. |
| `purchasing.bill.approved` | `CreateJournalForBill` | Dr expense/asset, Cr accounts payable. |
| `banking.payment.received` | `CreateJournalForReceipt` | Dr bank, Cr accounts receivable. |
| `inventory.goods.received` | `CreateJournalForGrn` | Inventory / GRNI postings. |
| `payroll.payroll.released` | `CreateJournalForPayroll` | Salary, deductions, WPS liability. |
| `tax.return.submitted` | `CreateJournalForTax` | Tax liability recognition. |

Each listener carries the source idempotency key `{source_module}:{source_type}:{source_id}:{event}`
and checks `(source_type, source_id)` before creating, so a queue redelivery is a no-op — a posted
entry never double-posts.

**Emitted:**

| Event | Emitted by | Representative reactions |
|---|---|---|
| `accounting.journal.posted` | `PostJournalEntryAction` | AR/AP sub-ledger caches; `AccountBalanceProjector`; report-cache invalidation; Reverb push. |
| `accounting.journal.reversed` | `ReverseJournalEntryAction` | Downstream reversal notifications; audit. |
| `accounting.period.closed` / `period.locked` / `period.reopened` | period Actions | Close-checklist progress; lock enforcement; audit. |
| `accounting.account.reclassified` | `ReclassifyAccountAction` | Statement-mapping cache flush. |
| `accounting.trial_balance.generated` | snapshot Action | Approval routing; export jobs. |
| `journal.ai_draft_ready` | AI-draft intake | Notify holders of `accounting.journal.post` to review. |

Queues: `default` for the queued posting listeners (they must stay ahead of source-document
throughput) and the balance projector; `reports` for trial-balance snapshot and financial-statement
generation and exports; `ai` for AI duplicate-detection and anomaly narration on drafts; `maintenance`
for the nightly `ledger_entries` integrity rebuild-and-compare and materialized-view refresh.

**Realtime (Reverb).** Posting broadcasts a compact `journal.posted` projection (entry id, type,
total, currency) on `private-company.{companyId}` so the live dashboard and open ledger screens
re-fetch; the payload is never the full entry. Trial-balance and statement figures update the same way.

# Integrations

- **Every posting module** — Sales, Purchasing, Banking, Inventory, Payroll, Tax — integrates
  *inbound* via domain events. They emit; Accounting posts. This one-way coupling is what keeps a
  source module replaceable without touching the ledger, and what guarantees there is a single posting
  code path.
- **The workflow engine** — journal entries above a company-configured threshold, `year_closing`
  entries, all `ai_draft` entries, and every manual reversal require an approval chain; the Accounting
  Service declares the requirement and consumes the workflow decision, then invokes the posting engine
  on final approval. The maker can never be the sole approver (segregation of duties).
- **The FastAPI AI engine** — proposes entries by calling `/api/v1` like any client with a scoped
  service token; a proposal lands as an `origin = 'ai_draft'`, `status = 'draft'` entry with
  `created_by_agent` and `ai_confidence` set. The AI can read balances and draft postings; it can never
  post, approve, reverse, close, or lock. A human holding `accounting.journal.post` disposes.
- **Reports and Tax** — read Accounting's outputs (trial balance, statements) query-only; Tax reads
  Income Statement figures to compute provisions. Neither writes accounting tables.
- **Object storage (R2)** — generated statement and trial-balance PDF/Excel exports are written to R2
  and served via signed, expiring URLs; the export job runs on the `reports` queue.

# Permissions

The module's permissions follow the `accounting.<entity>.<action>` grammar and are deny-by-default. The
service-critical set:

| Permission | Guards | Sensitivity |
|---|---|---|
| `accounting.journal.read` | Reading entries, ledger activity | — |
| `accounting.journal.create` | Creating and editing a *draft* entry | — |
| `accounting.journal.post` | Committing a draft/approved entry to the ledger | posting is the money-affecting act |
| `accounting.journal.reverse` | Creating a reversing entry against a posted one | sensitive |
| `accounting.journal.void` | Voiding a draft/unposted entry | sensitive |
| `accounting.coa.manage` | Creating, reclassifying, deactivating accounts; opening balances | sensitive |
| `accounting.trial_balance.generate` / `.approve` | Freezing and approving a TB snapshot | approve is sensitive |
| `accounting.period.close` / `.lock` / `.reopen` | Fiscal-period state transitions | sensitive |
| `accounting.fiscal_year.close` | Closing a fiscal year and its closing entries | sensitive |

Two authorization rules are load-bearing:

- **AI-drafted entries need a human post.** Holding `accounting.journal.create` (which the AI's scoped
  token may hold) lets a caller *draft*; only `accounting.journal.post` — which the AI service token
  never holds — commits. This is the mechanical enforcement of "AI proposes, human disposes."
- **Segregation of duties on posting/approval.** The `created_by` of an entry may not be its sole
  approver, and the initiator of a reversal or a period reopen is checked against the approver, so no
  single credential drives a sensitive ledger change to completion alone.

Per [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), the AI receives only data
the acting user is permitted to see: an agent proposing a journal entry for a user who cannot read
payroll accounts is never handed payroll balances to reason over.

# Error Handling

The service throws typed domain exceptions; the global handler renders them to the envelope and status
code. No controller assembles an error. Exceptions carry structured context (the two totals, the
current and allowed states) so the handler localizes `errors[].message` without knowing HTTP.

| Thrown by service | Meaning | Rendered |
|---|---|---|
| `UnbalancedEntryException` | `SUM(debits) != SUM(credits)` at post time | 422 `balance_mismatch` |
| `ImmutableRecordException` | Edit/void/delete of a posted/reversed/voided entry or line | 409 `immutable_record` (names the reversal action) |
| `ClosedPeriodException` | Post/edit dated into a `closed`/`locked` period | 422 `closed_period` |
| `InactiveAccountException` | A line targets an inactive or non-postable account | 422 `inactive_account` |
| `InvalidStateTransitionException` | Post of a non-draft/non-approved, or illegal lifecycle move | 409 `invalid_state_transition` |
| `OptimisticLockException` | Draft edited with a stale `version` | 409 `version_conflict` |
| `AiAutopostForbiddenException` | Attempt to post an `origin='ai_draft'` entry without human authority | 403 `ai_autopost_forbidden` |
| `PeriodModuleLockException` | Close attempt while a source module hasn't confirmed its postings | 409 `period_module_lock` |
| `AccountingEquationViolation` | Assembled Balance Sheet fails Assets = Liabilities + Equity | 422 `statement_out_of_balance` (diagnostic, never a silently wrong statement) |
| `IdempotencyKeyReusedException` | Same post key, different body | 409 `idempotency_key_conflict` |

Two behaviors deserve emphasis, because they are where a financial platform most often goes wrong:

- **Balance failure rolls the whole transaction back.** If the re-derived debit total does not equal
  the re-derived credit total, `PostingService::assertBalanced` throws before any `ledger_entries` row
  is written; the entry stays in its pre-post status and the response identifies the imbalance to four
  decimals. There is no partial post.
- **A posted record is corrected, never edited.** Every mutating exception on a terminal entry
  (`immutable_record`) returns the name of the correct remedy — `reverse` — in its context, so a client
  is guided to the mechanically honest fix instead of an impossible edit.

# Testing

Testing follows the [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) model, with the double-entry
invariant as the single most important thing to prove.

- **Unit-test `PostingService` in isolation**: it rejects an unbalanced draft (`422 balance_mismatch`)
  before writing any projection; it refuses a draft dated into a closed period; it refuses a line on an
  inactive account; it writes exactly one `ledger_entries` row per posted line with the correct
  `signed_base_amount`; the balance check uses `Money`/`NUMERIC(19,4)` equality, never float tolerance.
- **Unit-test the Actions** with repositories mocked: `PostJournalEntryAction` throws
  `ImmutableRecordException` on a posted entry; `ReverseJournalEntryAction` produces a mirror entry with
  swapped debit/credit and sets `reverses_entry_id` / `reversed_by_entry_id`; `UpdateJournalDraftAction`
  refuses a non-draft; an `origin='ai_draft'` entry can be created but not posted by an AI-scoped caller.
- **Feature-test through `/api/v1/accounting`**: posting an invoice-triggered entry twice (event
  redelivery) creates exactly one journal; a `PATCH` to a posted entry returns `409`; posting into a
  locked period returns `422`; a Balance Sheet that fails the accounting equation returns a diagnostic,
  never a wrong statement.
- **Tenant-isolation tests**: a token for Company A gets `404` for Company B's entry; Company A's posted
  lines never appear in Company B's trial balance even with an unscoped reporting query (RLS supplies
  the boundary); the RLS test runs with no GUC set and asserts zero rows.
- **Integrity test**: rebuilding `ledger_entries` from posted `journal_lines`
  (`TRUNCATE; INSERT ... SELECT`) reproduces byte-identical balances and statements — the nightly job's
  assertion, exercised in CI.
- **Event/listener tests**: `accounting.journal.posted` dispatches after commit; the cross-module
  posting listeners are idempotent under retry; a rolled-back post fires no downstream event.

A feature is not done until it ships with its migration, API, permissions, audit logging, AI support,
documentation, and tests — the MODULE_ARCHITECTURE release checklist.

# Related Documents

- [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) — the Action/Service pattern and the abstract `PostingService` invariant.
- [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) — the request lifecycle, module boundary map, and inter-module events.
- [../accounting/CHART_OF_ACCOUNTS.md](../accounting/CHART_OF_ACCOUNTS.md), [../accounting/JOURNAL_ENTRIES.md](../accounting/JOURNAL_ENTRIES.md), [../accounting/GENERAL_LEDGER.md](../accounting/GENERAL_LEDGER.md) — the domain depth for accounts, entries, and the ledger.
- [../accounting/TRIAL_BALANCE.md](../accounting/TRIAL_BALANCE.md), [../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md) — trial balance and statement assembly.
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) — the four-layer tenant enforcement.
- [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md), [../api/API_IDEMPOTENCY.md](../api/API_IDEMPOTENCY.md) — envelope and idempotency for money-moving posts.

# End of Document
