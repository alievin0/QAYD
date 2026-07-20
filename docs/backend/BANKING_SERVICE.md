# Banking Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: BANKING
---

# Purpose

The Banking Service is the backend application layer that implements the Banking & Treasury module
described functionally in [../accounting/BANKING.md](../accounting/BANKING.md). Where that document
defines *what* the module does — cash positions, statement reconciliation, payment runs, multi-currency
treasury — this document specifies *how* the Laravel 12 / PHP 8.4 backend realizes it: the Actions,
Services, DTOs, repositories, jobs, events, and policies that sit between a validated `/api/v1/banking/*`
request and a committed database write, all following the shared contract in
[./SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md).

The Banking Service owns the *cash side* of the platform: it is the single writer of `bank_accounts`,
`bank_transactions`, `bank_statement_lines`, `bank_reconciliations`, `transfers`, and `standing_orders`,
and the single place the reconciliation-scoring engine and the two-key payment-approval chain live. It
does **not** own the double-entry ledger. Every cash movement that must hit the books is posted as a
balanced journal entry through the Accounting Service
([../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md)); this service never writes
`journal_entries`/`journal_lines` itself, it only calls the posting engine and stores the returned
`journal_entry_id`.

Two invariants dominate every design decision here and are enforced structurally, not by convention:

1. **A bank transaction cannot reach `cleared` or `reconciled` without a posted, balanced journal
   entry.** The database enforces `status NOT IN ('cleared','reconciled') OR journal_entry_id IS NOT NULL`;
   the service enforces that the `journal_entry_id` points at a `posted` entry.
2. **Money never leaves the company on one key.** Outgoing payments, transfers, and standing orders run
   the Finance Manager → CEO two-key chain, and the AI principal is structurally barred from every
   money-moving permission — it proposes and reconciles, a human authorizes.

All amounts are `NUMERIC(19,4)` money value objects; the platform base currency defaults to KWD and
every foreign-currency row stores both the transaction amount and the base-currency `base_amount`.

# Responsibilities

The Banking Service is responsible for:

- **Account lifecycle.** Onboarding `bank_accounts` (commercial/Islamic/investment/digital banks,
  payment providers, wallets, cash, petty cash, virtual accounts, cards, and loan facilities) through a
  verification gate (`pending_verification → active`), and managing per-account authorized users via
  `bank_account_users`.
- **Transaction ledger.** Creating, validating, approving, and posting every `bank_transactions` row
  through its `draft → … → cleared → reconciled` state machine, and maintaining `bank_accounts`
  running balances incrementally under row locks.
- **Statement ingestion.** Importing immutable `bank_statement_lines` from MT940, CAMT.053, CSV, XLSX,
  and PDF-OCR sources and from Open Banking feeds, batching each import under a `statement_import_id`
  and blocking any import whose arithmetic does not tie out.
- **The reconciliation engine.** Scoring candidate statement-line-to-transaction matches
  deterministically, auto-committing only above threshold and only when anchored by a deterministic
  rule, computing period variance, and gating period close behind `bank.reconcile.close`.
- **Payments and transfers.** Running vendor, payroll, refund, and internal/inter-company payment flows
  and two-leg `transfers` through the two-key approval chain, and dispatching approved payments to
  their rails at `value_date`.
- **Standing orders.** Materializing recurring `standing_order_execution` / `recurring_payment`
  transactions on schedule within a pre-approved envelope.
- **Multi-currency.** Snapshotting `exchange_rate` per transaction, computing `base_amount`, and
  driving period-end FX revaluation through the Accounting Service.
- **AI surface.** Exposing read + propose-only endpoints the Banking Agent
  ([../ai/agents/BANKING_AGENT.md](../ai/agents/BANKING_AGENT.md)) calls under a scoped service account
  that can never hold `bank.transfer`, `bank.payment.approve`, or `bank.payment.approve.final`.

The service does **not** own customer invoicing (Sales), vendor billing (Purchasing), payroll
calculation (Payroll), tax determination (Tax), or the ledger itself (Accounting). It owns the cash
leg of each and the one journal entry that records it.

# Domain Model

The aggregate boundaries the service enforces:

- **`BankAccount`** — the aggregate root for a place money can sit. Holds `currency_code` (immutable
  after first transaction), `institution_type`, `account_type`, `status`, the running `current_balance`
  / `current_balance_base` / `available_balance` / `frozen_balance`, the mandatory `gl_account_id`
  linking it to its Chart-of-Accounts control account, and its `bank_account_users` collection. A
  strategy object per `institution_type` (`CommercialBankStrategy`, `IslamicBankStrategy`,
  `PaymentProviderStrategy`, …) resolves type-specific behavior (IBAN/SWIFT requirements, import
  formats, interest vs. `profit_distribution`) without branching the schema.
- **`BankTransaction`** — the canonical cash-movement record. It is the child of one `BankAccount`; a
  two-sided movement is two `BankTransaction` rows joined by a single `Transfer`. Its financial fields
  (`amount`, `bank_account_id`, `currency_code`) become immutable once it leaves `draft`; corrections
  are reversing entries via `reversed_transaction_id`, never edits.
- **`BankStatementLine`** — an immutable fact imported from the bank, belonging to a
  `statement_import_id` batch. Never edited after import (soft-deleted only if the whole import is
  voided and re-run). Reconciliation links it to transactions; it is never rewritten to force agreement.
- **`BankReconciliation`** — a per-account, per-period tie-out, with `bank_reconciliation_matches`
  recording exactly which rules fired for every committed match. Owns the `variance` computation and
  the `in_progress → discrepancy|balanced → closed` lifecycle.
- **`Transfer`** — the two-leg root for internal, external, and inter-company movements; carries its
  own two-key approval columns and both `from_transaction_id`/`to_transaction_id` legs.
- **`StandingOrder`** — a recurrence definition with a pre-approved envelope that materializes
  transactions on `next_run_date`.

Value objects: `Money` (`NUMERIC(19,4)` fixed-scale), `Iban` (ISO 13616 mod-97), `SwiftBic` (ISO
9362), `MatchScore` (the 0–100 composite), and `ApprovalChain` (the two-key state).

**Transaction state machine.** `BankTransaction` is governed by an explicit state machine the service
guards on every transition; illegal moves throw `InvalidStateTransitionException` rather than mutating
the row:

```
draft ─▶ pending_approval ─▶ approved ─▶ scheduled ─▶ submitted ─▶ pending_clearance ─▶ cleared ─▶ reconciled
   │             │                                          │
   ▼             ▼                                          ▼
 voided       rejected                                   failed ─▶ retried | cancelled
```

`draft` is fully editable; `approved` freezes the financial fields (`amount`, `bank_account_id`,
`currency_code`) and creates the journal entry in draft accounting state; `cleared` posts the journal
and updates the running balance; `reconciled` is set by the reconciliation engine and is not a terminal
replacement of `cleared`. A `cleared`/`reconciled` row is never edited — a correction is a reversing
transaction via `reversed_transaction_id`, mirroring the ledger's own immutability rule. Fees and
interest/`profit_distribution` lines are the narrow exception that may originate directly in `cleared`
from a statement line, because the company did not initiate them and there is nothing to approve.

# Key Classes

The service follows the Action-by-default / Service-when-shared rule from
[./SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md). Classes live under `app/Banking/*` and resolve
from the container with their collaborators injected.

**Account & transaction Actions:**

- `OnboardBankAccountAction` — creates a `pending_verification` account, wires its `gl_account_id`,
  seeds `bank_account_users`.
- `VerifyBankAccountAction` — moves `pending_verification → active` after micro-deposit / consent /
  bank-letter proof.
- `CreateBankTransactionAction` — creates a `draft`; never posts.
- `SubmitBankTransactionAction` — routes `draft → pending_approval` (or straight to `approved` for
  non-sensitive types under the auto-approval threshold).
- `ClearBankTransactionAction` — the money-critical Action: posts the journal entry and moves the row
  to `cleared` inside one transaction (see below).
- `ReverseBankTransactionAction` — creates a reversing transaction against a `cleared`/`reconciled` row.

**Domain services (shared internals):**

- `ReconciliationEngine` — the deterministic scorer and auto-committer.
- `ApprovalService` — the two-key chain, including the maker≠checker guard.
- `BalanceService` — incremental running-balance maintenance under `SELECT … FOR UPDATE`.
- `StatementImportService` — normalizes MT940/CAMT.053/CSV/XLSX/PDF into `bank_statement_lines` and
  runs the tie-out check.
- `PaymentDispatchService` — sends approved payments to their rail at `value_date`.

The clear-and-post Action, showing the atomic journal-post + balance-update + event emission:

```php
namespace App\Banking\Actions;

use App\Banking\Repositories\BankTransactionRepository;
use App\Banking\Services\BalanceService;
use App\Accounting\Contracts\JournalPoster;      // ../backend/ACCOUNTING_SERVICE.md
use App\Accounting\Data\JournalDraft;
use App\Banking\Events\BankTransactionCleared;
use App\Banking\Exceptions\UnbalancedBankEntryException;
use App\Models\Banking\BankTransaction;
use Illuminate\Support\Facades\DB;

final class ClearBankTransactionAction
{
    public function __construct(
        private readonly BankTransactionRepository $transactions,
        private readonly JournalPoster $ledger,      // Accounting Service — we never write journal_lines
        private readonly BalanceService $balances,
    ) {}

    public function execute(int $transactionId): BankTransaction
    {
        return DB::transaction(function () use ($transactionId): BankTransaction {
            // Lock the row and its account so concurrent clears cannot double-apply.
            $txn = $this->transactions->lockForUpdate($transactionId);

            $draft = JournalDraft::forBankTransaction($txn);        // debit/credit shape lives in Accounting
            $entry = $this->ledger->post($draft, idempotencyKey: "bank_txn:{$txn->id}:cleared");
            // The posting engine asserts SUM(debits) == SUM(credits); a mismatch throws upstream.

            $txn->journal_entry_id = $entry->id;
            $txn->status           = 'cleared';
            $txn->posted_date      = now()->toDateString();
            $this->transactions->save($txn);

            $this->balances->applyCleared($txn);   // running current_balance/base under the same lock

            event(new BankTransactionCleared($txn->id, companyId: $txn->company_id));
            return $txn->refresh();
        });
    }
}
```

The database `CHECK (status NOT IN ('cleared','reconciled') OR journal_entry_id IS NOT NULL)` is the
backstop; the Action is the guarantee. If the Accounting Service returns an unbalanced-entry error, the
whole transaction rolls back and the row stays in its prior state — there is never a window where a
bank balance moved without a balanced journal behind it.

**DTOs.** Actions receive immutable readonly DTOs, never the raw `Request`, so the same Action is
callable from a controller, a queued job, the `ScheduledPaymentDispatcher`, and the AI proposal-commit
path. A DTO is built from a FormRequest (HTTP) or constructed directly (jobs, AI):

```php
namespace App\Banking\Data;

use App\Http\Requests\Banking\StoreBankTransactionRequest;

final readonly class BankTransactionData
{
    public function __construct(
        public int $bankAccountId,
        public string $transactionType,
        public string $amount,          // NUMERIC(19,4) string — never a float
        public string $currencyCode,
        public string $valueDate,
        public ?string $bankReference = null,
        public ?string $payeePayerName = null,
        public ?string $payeePayerIban = null,
        public ?int $costCenterId = null,
        public bool $aiGenerated = false,
    ) {}

    public static function fromRequest(StoreBankTransactionRequest $r): self
    {
        return new self(
            bankAccountId:   (int) $r->integer('bank_account_id'),
            transactionType: $r->string('transaction_type'),
            amount:          $r->string('amount'),
            currencyCode:    $r->string('currency_code'),
            valueDate:       $r->string('value_date'),
            bankReference:   $r->input('bank_reference'),
            payeePayerName:  $r->input('payee_payer_name'),
            payeePayerIban:  $r->input('payee_payer_iban'),
            costCenterId:    $r->input('cost_center_id'),
        );
    }
}
```

Because the Banking Agent's proposal-commit path constructs the same DTO (with `aiGenerated: true`) from
a validated payload, an AI-originated draft and a human-originated draft run through byte-for-byte the
same Action, validation, and invariants — the AI simply cannot submit or approve what it drafts.

**Institution-type strategies.** `BankAccountService` resolves one strategy per `institution_type`
(`CommercialBankStrategy`, `IslamicBankStrategy`, `InvestmentBankStrategy`, `DigitalBankStrategy`,
`PaymentProviderStrategy`, `WalletStrategy`, `CashStrategy`, `PettyCashStrategy`) that answers
type-specific questions — is IBAN/SWIFT required, which import formats apply, does the account use
`interest_earned` or `profit_distribution` (Islamic), does it need a `custodian_user_id` (petty cash)
or a `parent_bank_account_id` (virtual). Reconciliation, reporting, and the API stay uniform regardless
of institution because the strategy, not the schema, carries the difference.

# Endpoints Backed

Every endpoint is under `/api/v1/banking/`, authenticated with a Sanctum/JWT bearer token, scoped to
the active company by the `X-Company-Id` header, and returns the standard platform envelope
(`success`, `data`, `message`, `errors`, `meta.pagination`, `request_id`, `timestamp`) per
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md). Controllers are thin: they authorize via policy,
build a DTO from the FormRequest, and call one Action/Service.

| Method | Path | Permission | Backed by |
|---|---|---|---|
| GET | `/banking/bank-accounts` | `bank.read` | `BankAccountRepository::paginate` |
| POST | `/banking/bank-accounts` | `bank.account.create` | `OnboardBankAccountAction` |
| GET | `/banking/bank-accounts/{id}` | `bank.read` | `BankAccountRepository::findOrFail` |
| PATCH | `/banking/bank-accounts/{id}` | `bank.account.update` | `UpdateBankAccountAction` (non-financial fields only) |
| POST | `/banking/bank-accounts/{id}/verify` | `bank.account.verify` | `VerifyBankAccountAction` |
| POST | `/banking/bank-accounts/{id}/freeze` | `bank.account.freeze` | `FreezeBankAccountAction` |
| POST | `/banking/bank-accounts/{id}/close` | `bank.account.close` | `CloseBankAccountAction` (requires zero balance) |
| GET | `/banking/bank-accounts/{id}/balance` | `bank.read` | `BalanceService::snapshot` |
| POST | `/banking/bank-accounts/{id}/users` | `bank.account.manage_users` | `GrantAccountRoleAction` |
| DELETE | `/banking/bank-accounts/{id}/users/{userId}` | `bank.account.manage_users` | `RevokeAccountRoleAction` |
| POST | `/banking/bank-accounts/{id}/open-banking/connect` | `bank.account.manage` | `OpenBankingConnectAction` |
| POST | `/banking/bank-accounts/{id}/open-banking/sync` | `bank.account.manage` | `SyncOpenBankingJob` (dispatched) |
| GET | `/banking/bank-transactions` | `bank.read` | `BankTransactionRepository::paginate` |
| POST | `/banking/bank-transactions` | `bank.transaction.create` | `CreateBankTransactionAction` |
| GET | `/banking/bank-transactions/{id}` | `bank.read` | `BankTransactionRepository::findWithJournal` |
| PATCH | `/banking/bank-transactions/{id}` | `bank.transaction.update` | `UpdateDraftTransactionAction` (draft only) |
| POST | `/banking/bank-transactions/{id}/submit` | `bank.transaction.submit` | `SubmitBankTransactionAction` |
| POST | `/banking/bank-transactions/{id}/approve` | `bank.payment.approve` | `ApprovalService::approveFirstKey` |
| POST | `/banking/bank-transactions/{id}/approve-final` | `bank.payment.approve.final` | `ApprovalService::approveSecondKey` |
| POST | `/banking/bank-transactions/{id}/reject` | `bank.payment.approve` \| `.final` | `ApprovalService::reject` |
| POST | `/banking/bank-transactions/{id}/void` | `bank.transaction.void` | `VoidBankTransactionAction` |
| POST | `/banking/bank-transactions/{id}/reverse` | `bank.transaction.reverse` | `ReverseBankTransactionAction` |
| GET | `/banking/transfers` | `bank.read` | `TransferRepository::paginate` |
| POST | `/banking/transfers` | `bank.transfer` | `CreateTransferAction` (starts `pending_finance_manager`) |
| POST | `/banking/transfers/{id}/approve` | `bank.payment.approve` | `ApprovalService::approveFirstKey` |
| POST | `/banking/transfers/{id}/approve-final` | `bank.payment.approve.final` | `ApprovalService::approveSecondKey` → dispatch |
| GET | `/banking/standing-orders` | `bank.read` | `StandingOrderRepository::paginate` |
| POST | `/banking/standing-orders` | `bank.standing_order.create` | `CreateStandingOrderAction` (full chain) |
| PATCH | `/banking/standing-orders/{id}` | `bank.standing_order.update` | `UpdateStandingOrderAction` (envelope changes re-trigger chain) |
| POST | `/banking/standing-orders/{id}/pause` | `bank.standing_order.update` | `PauseStandingOrderAction` |
| DELETE | `/banking/standing-orders/{id}` | `bank.standing_order.cancel` | `CancelStandingOrderAction` |
| POST | `/banking/statement-imports` | `bank.reconcile` | `StatementImportService::import` |
| GET | `/banking/statement-imports/{id}` | `bank.reconcile` | `StatementImportRepository::status` |
| GET | `/banking/bank-accounts/{id}/reconciliation/unmatched` | `bank.reconcile` | `ReconciliationEngine::workbench` |
| POST | `/banking/reconciliation-matches` | `bank.reconcile` | `ReconciliationEngine::commitManualMatch` |
| DELETE | `/banking/reconciliation-matches/{id}` | `bank.reconcile` | `ReconciliationEngine::unmatch` (open period only) |
| GET | `/banking/bank-accounts/{id}/reconciliations` | `bank.read` | `BankReconciliationRepository::paginate` |
| POST | `/banking/reconciliations/{id}/close` | `bank.reconcile.close` | `CloseReconciliationAction` |
| POST | `/banking/reconciliations/{id}/reopen` | `bank.reconcile.reopen` | `ReopenReconciliationAction` (reason required) |
| GET | `/banking/cash-position` | `bank.read` | `CashPositionService::live` |
| GET | `/banking/cash-flow-forecast` | `bank.read` | `ForecastRepository::rolling13Week` (read-only replica) |

A create-transfer request and its two-key response envelope:

```json
POST /api/v1/banking/transfers
{
  "from_bank_account_id": 101,
  "to_bank_account_id": 205,
  "transfer_type": "internal",
  "amount": "10000.0000",
  "from_currency_code": "KWD",
  "to_currency_code": "USD",
  "exchange_rate": "0.325000",
  "value_date": "2026-07-20",
  "memo": "Sweep to USD investment account"
}
```

```json
{
  "success": true,
  "data": {
    "id": 5512,
    "status": "draft",
    "approval_chain_status": "pending_finance_manager",
    "amount": "10000.0000",
    "base_amount": "10000.0000",
    "from_transaction_id": null,
    "to_transaction_id": null
  },
  "message": "Transfer created; awaiting Finance Manager approval.",
  "request_id": "req_01J...",
  "timestamp": "2026-07-16T09:14:22Z"
}
```

# Database Tables Owned

The Banking Service is the sole writer of the following tables (full DDL in
[../accounting/BANKING.md](../accounting/BANKING.md) → Database Design). Every table carries the
platform standard columns (`id`, `company_id NOT NULL`, `branch_id`, `created_by`, `updated_by`,
`created_at`, `updated_at`, `deleted_at`); every index leads with `company_id`; no financial row is
ever hard-deleted.

| Table | Role | Key constraints the service relies on |
|---|---|---|
| `bank_accounts` | Aggregate root; running balances; `gl_account_id` FK to `accounts` | IBAN/SWIFT/currency regex `CHECK`s; `virtual → parent_bank_account_id NOT NULL`; `petty_cash → custodian_user_id NOT NULL`; unique `(company_id, iban)` where not null |
| `bank_account_users` | Per-account authorized users with role | `UNIQUE (bank_account_id, user_id, role)` |
| `bank_transactions` | Canonical cash ledger | `amount > 0`; `base_amount > 0`; `status NOT IN ('cleared','reconciled') OR journal_entry_id IS NOT NULL` |
| `bank_statement_lines` | Immutable imported bank facts, batched by `statement_import_id` | `direction IN ('credit','debit')`; currency regex; never updated post-import |
| `bank_reconciliations` | Per-account/period tie-out and `variance` | `UNIQUE (bank_account_id, period_start, period_end, reconciliation_type)` |
| `bank_reconciliation_matches` | Audit of every rule that fired per committed match | `final_score`, `rules_fired` JSONB, `match_method` |
| `transfers` | Two-leg movement root, two-key columns | `from_bank_account_id <> to_bank_account_id`; intercompany requires both company ids |
| `standing_orders` | Recurrence + pre-approved envelope | `day_of_month BETWEEN 1 AND 31`; `day_of_week BETWEEN 0 AND 6` |
| `bank_oauth_tokens` | Open Banking OAuth token pairs (separate table, separately encrypted) | Never returned by any API response |
| `bank_transaction_status_history` | Append-only lightweight status timeline for UI | Append-only |

The service reads but never writes: `accounts` (Accounting), `journal_entries`/`journal_lines`
(Accounting), `receipts`/`receipt_allocations` (Sales), `vendor_payments`/`bills` (Purchasing),
`payroll_runs` (Payroll), `exchange_rates` (shared FX table).

The `cleared/reconciled` invariant, restated as it appears in DDL, is the single most important guard
the service builds on:

```sql
CONSTRAINT chk_bank_transactions_cleared_needs_journal
  CHECK (status NOT IN ('cleared', 'reconciled') OR journal_entry_id IS NOT NULL)
```

# Multi-Tenancy Enforcement

Tenancy is ambient and defence-in-depth, exactly as in
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
[./SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md):

- **Ambient `company_id`.** Every model uses the `BelongsToCompany` trait, so Actions never set
  `company_id` for isolation — the trait fills it and the `CompanyScope` global scope + PostgreSQL RLS
  (`app.current_company_id`) enforce it on every statement. A cross-tenant id resolves to
  `ModelNotFoundException → 404`, never a leak.
- **Transactional envelope.** Money-moving Actions run inside `DB::transaction`, which wraps
  `SET LOCAL app.current_company_id`, so RLS holds for every statement even under PgBouncer transaction
  pooling — critical because `BalanceService` and `ReconciliationEngine` issue `SELECT … FOR UPDATE`
  inside these transactions.
- **Inter-company transfers never cross a journal.** An `is_intercompany` transfer posts a *separate*
  balanced journal entry against a reciprocal Intercompany Receivable/Payable account in **each**
  company's own books; QAYD never posts one `journal_entries` row spanning two `company_id` values.
  Both sides require the full Finance Manager → CEO chain regardless of amount.
- **AI service account is tenant- and permission-scoped.** The Banking Agent runs one scoped service
  session per company (`actor_type = 'ai_agent'`); it can read only that company's data and holds only
  `bank.read`, propose-only reconciliation, and draft-create. It never sees another company's rows and
  is structurally ineligible for the money-moving permissions.
- **Balance integrity job is tenant-rebinding.** The nightly balance-recompute job extends
  `TenantAwareJob`, re-establishing `company_id` + `SET LOCAL app.current_company_id` in `handle()`
  before reading any row.

# Events, Queues & Realtime

The service emits past-tense domain events inside the write transaction; Laravel holds queued listener
dispatch until commit, so a rolled-back clear never fires a downstream post. The canonical envelope is
`{event_id, event_name, company_id, aggregate_type, aggregate_id, occurred_at, causation_id,
correlation_id, payload}`.

**Domain events (and the webhooks they surface):** `bank.transaction.created`,
`bank.transaction.cleared`, `bank.transaction.approved`, `bank.transaction.rejected`,
`bank.transfer.completed`, `bank.reconciliation.closed`, `bank.statement.imported`,
`bank.fraud_flag.raised`, `bank.liquidity_ratio.breached`.

**Queues** (named per priority, per BACKEND_ARCHITECTURE):

| Queue | Banking work routed here |
|---|---|
| `realtime` | Reverb broadcasts (balance/approval/reconciliation UI updates) |
| `default` | Cross-module listeners: post/relieve AR/AP on clear, notify approvers |
| `ai` | `NotifyAiLayerListener` fan-out to the FastAPI engine on `events-ai`; Banking Agent match/duplicate/FX analysis triggers |
| `integrations` | `SyncOpenBankingJob`, `ScheduledPaymentDispatcher`, statement-file/OCR ingestion |
| `reports` | Cash-flow-forecast snapshot generation, currency-exposure recompute |
| `maintenance` | Nightly `RecomputeAccountBalanceJob` integrity check |

**Realtime.** Broadcast events implement `ShouldBroadcast` on the company-scoped private channel
`private-company.{id}` and its `.approvals` sub-channel, so a Finance Manager sees a payment enter
`pending_approval` and a CEO sees it reach `approved` without polling. `bank.fraud_flag.raised`
(risk ≥ 80) and `bank.liquidity_ratio.breached` always broadcast and always notify regardless of user
preferences.

**Scheduled jobs.** `ScheduledPaymentDispatcher` runs every 15 minutes and submits `scheduled`
payments to their rail at `value_date` (respecting account cutoff). `MaterializeStandingOrdersJob`
runs daily to create due `standing_order_execution`/`recurring_payment` rows within the approved
envelope, escalating any over-envelope execution to `pending_approval` and firing
`bank.standing_order.envelope_exceeded`.

```php
namespace App\Banking\Listeners;

use App\Banking\Events\BankTransactionCleared;
use App\Sales\Actions\RelieveReceivableAction;      // Sales owns AR relief
use Illuminate\Contracts\Queue\ShouldQueue;

final class RelieveReceivableOnClear implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly RelieveReceivableAction $relieve) {}

    public function handle(BankTransactionCleared $event): void
    {
        // Cross-module effect via event, never a direct write into Sales' tables.
        $this->relieve->forClearedReceipt($event->transactionId);   // idempotent
    }
}
```

# Integrations

- **Accounting Service** ([../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md)) — the
  only path to the ledger. Banking builds a `JournalDraft` (deposit, transfer, fee, FX revaluation
  shapes are documented in BANKING.md → Accounting Integration) and calls the posting engine, which
  asserts balance and returns a posted `journal_entry_id`. Banking never duplicates the posting engine
  and never writes `journal_lines`.
- **Sales / Purchasing / Payroll** — cross-module via events only. A cleared `receipts` row (Sales)
  produces exactly one `incoming_payment`; a cleared `vendor_payments` row (Purchasing) produces one
  `outgoing_payment`; a `payroll_runs` completion produces one `outgoing_payment` per net pay. Banking
  owns the cash leg and its single journal entry; AR/AP relief and payslip reconciliation stay in the
  originating module.
- **Tax Service** ([./TAX_SERVICE.md](./TAX_SERVICE.md)) — a VAT remittance is an ordinary
  `bank_transactions`/`transfers` row debiting the tax-payable account; Tax can *prepare* the payment
  instruction but the transfer runs the same `bank.transfer` human chain.
- **FX** — reads the shared `exchange_rates` table; a transaction-level `exchange_rate` always wins over
  the daily reference rate for that row's `base_amount`, while the reference rate drives period-end
  revaluation.
- **Open Banking / payment rails** — `SyncOpenBankingJob` and `PaymentDispatchService` call banks,
  aggregators, and payment providers on the `integrations` queue; OAuth tokens live in
  `bank_oauth_tokens` (separately encrypted, never returned by any API) and the FastAPI layer never
  receives raw banking secrets.
- **FastAPI AI engine** ([../api/INTERNAL_API.md](../api/INTERNAL_API.md)) — the Banking Agent calls
  `/api/v1/banking/*` like any client, permission-checked as its scoped service account. Its match,
  duplicate, and FX proposals arrive back through the normal FormRequest + audit path; it never writes
  the database directly. See [../ai/workflows/BANK_RECONCILIATION.md](../ai/workflows/BANK_RECONCILIATION.md).

# The Reconciliation Engine

`ReconciliationEngine` is the domain service that proves `bank_transactions` and `bank_statement_lines`
describe the same reality. It runs immediately after a `StatementImportService` import and again when
any `bank_transactions` row reaches `cleared`.

**Statement import and the tie-out gate.** `StatementImportService` normalizes four channels into the
same immutable `bank_statement_lines` shape, each batch tagged with a `statement_import_id`: structured
files (MT940, CAMT.053 XML), spreadsheets (CSV, XLSX, with a per-bank saved column mapping), scanned
documents (PDF, routed through the Document AI/OCR Agent with per-field confidence), and the Open
Banking feed (`SyncOpenBankingJob`, CAMT.053/proprietary JSON). Every import runs a hard arithmetic
tie-out before a single line is committed: `opening_balance + Σ statement lines = closing_balance`. A
mismatch raises `StatementTieOutException → 422` and writes no lines — a mis-parsed or partial statement
never silently pollutes the ledger. OCR fields below 95% confidence are highlighted for manual
correction before the batch finalizes; imported lines are never edited afterward (a bad import is voided
and re-run).

**Deterministic scoring.** For each unmatched statement line it evaluates rules in order, accumulating a
0–100 confidence score:

| Rule | Signal | Weight |
|---|---|---|
| Exact reference match | statement `bank_reference` == `bank_transactions.bank_reference` | 45 |
| Amount + date exact | amount to the fil; `value_date` within `matching_window_days` (default ±2) | 30 |
| Amount + counterparty fuzzy | amount exact; payee/IBAN fuzzy (Levenshtein/phonetic) | 15 |
| Recurring pattern | matches a `standing_orders`/`recurring_payment` expected amount+date | 10 |
| AI similarity | Banking Agent learned-similarity model | up to +10, **capped** |

**The AI cap is a structural rule, not a tunable.** The AI-similarity contribution is added only after
at least one deterministic rule (exact reference ≥ 45, or amount+date ≥ 30) has already fired; AI alone
can never cross the auto-commit threshold. `ReconciliationEngine::score()` enforces this before it will
consider a match auto-committable.

**Auto-commit.** A candidate is committed automatically only when both the composite score ≥ the
company `auto_commit_threshold` (default 90) **and** a deterministic rule contributed ≥ 30. On commit,
the transaction moves to `reconciled`, the line to `matched`, and a `bank_reconciliation_matches` row
records `rules_fired`, `final_score`, and `match_method` (`auto_rule`, `ai_suggested_accepted`,
`manual`, or `split`).

**Concurrency.** Matching selects candidate rows `FOR UPDATE` so two workers (e.g. a post-import sweep
and a post-clear sweep) can never double-commit the same line/transaction pair.

**Variance and close.** After matching, the engine computes
`variance = book_balance_at_period_end − adjusted_bank_balance`, where
`adjusted_bank_balance = bank_closing_balance + outstanding_deposits − outstanding_withdrawals`. Exactly
zero closes as `balanced`; any non-zero keeps the period in `discrepancy` until the missing
transaction is found or an explicit `adjustment` is posted (which itself routes through approval). Period
close (`bank.reconcile.close`) locks the period's lines and transactions; reopening needs
`bank.reconcile.reopen` and an audited reason.

# The Two-Key Payment Chain

`ApprovalService` enforces the platform rule that money never leaves on one key. Outgoing payments,
transfers, refunds, and standing orders carry `finance_manager_approved_by` and `ceo_approved_by`. The
service guarantees the two are never equal:

```php
public function approveSecondKey(Approvable $subject, User $actor): void
{
    if ($subject->finance_manager_approved_by === $actor->id) {
        throw new MakerCheckerViolationException();   // → 409, "approver == maker"
    }
    // Step-up MFA required above the company high-value threshold (default KWD 25,000).
    $this->assertFreshMfa($actor, $subject->base_amount);
    $subject->ceo_approved_by = $actor->id;
    $subject->approval_chain_status = 'approved';
    // ... transition to approved/scheduled; dispatch handled by PaymentDispatchService
}
```

`bank.payment.approve.final` is rejected with `409 Conflict` if the finalizer is the first-key
approver, and the AI principal type is barred from `bank.transfer`, `bank.payment.approve`, and
`bank.payment.approve.final` at the route level regardless of any granted permission — the concrete
enforcement of human-in-the-loop.

**Anti-fraud controls the service enforces around the chain.** New accounts start
`pending_verification` and cannot be a payment destination until `VerifyBankAccountAction` proves the
IBAN via micro-deposit, Open Banking consent, or a reviewed bank letter — blocking the "add
attacker-controlled account, pay it" pattern. A configurable cooling-off period (default 4 hours)
separates a payee's bank details being added/changed from that payee becoming eligible for an
above-threshold payment. Refunds are never auto-approved regardless of amount (a historically common
fraud vector). The Fraud Detection agent screens every outgoing payment before it can leave
`pending_approval`; a `hold_recommended` flag forces a mandatory, non-dismissible second-look banner the
approver must acknowledge with a typed reason, and a risk score ≥ 80 notifies the Auditor in real time
regardless of whether the approver proceeds. Above the company high-value threshold (default
KWD 25,000), `approve-final` additionally requires a fresh step-up MFA challenge, independent of the
permission check.

# Permissions

Permissions follow the RBAC grammar `<area>.<action>` / `<area>.<entity>.<action>` from
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), default-deny, company-scoped,
checked in FormRequests (endpoint gate) and, for the maker≠checker rule, mid-Action.

| Permission | Grants |
|---|---|
| `bank.read` | View accounts, transactions, balances, reports |
| `bank.account.create` / `.update` / `.verify` / `.freeze` / `.close` | Account lifecycle |
| `bank.account.manage_users` | Grant/revoke per-account roles |
| `bank.account.manage` | Open Banking connect/sync |
| `bank.transaction.create` / `.update` / `.submit` / `.void` / `.reverse` | Transaction lifecycle |
| `bank.payment.approve` | Finance Manager first key |
| `bank.payment.approve.final` | CEO second key (only the CEO role holds it) |
| `bank.transfer` | Create a transfer (initiators only; approval is separate) |
| `bank.standing_order.create` / `.update` / `.cancel` | Standing orders |
| `bank.reconcile` | Import statements, use the workbench, commit matches |
| `bank.reconcile.close` / `.reopen` | Period close / reopen (Finance Manager+) |
| `treasury.read` / `treasury.manage` | Dashboards, thresholds, exposure notes |

**The AI Agent grant is the most restricted row by design.** It holds `bank.read` (own scoped read),
suggest-only reconciliation (match candidates + draft `ai_generated = true` transactions a human must
submit), and nothing else. The three money-moving permissions check the *principal type* in addition to
the grant, and the AI service account's type is never eligible — this is where "AI proposes, humans
authorize" is enforced in code, not documentation.

# Error Handling

Actions and services throw typed domain exceptions; the global handler maps them to the platform
envelope and status code (per [./SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md)). The service never
returns error arrays or HTTP responses.

| Exception | Meaning | Rendered |
|---|---|---|
| `UnbalancedBankEntryException` | posting engine reported debits ≠ credits | 422 `balance_mismatch` |
| `InvalidStateTransitionException` | e.g. clearing a `draft`, editing a non-draft | 409 `invalid_state_transition` |
| `ImmutableRecordException` | editing a `cleared`/`reconciled` transaction | 409 `immutable_record` (names the reverse action) |
| `MakerCheckerViolationException` | second-key approver == first-key approver | 409 `maker_checker_violation` |
| `StatementTieOutException` | `opening + Σ lines ≠ closing` on import | 422 `statement_tie_out_failed` |
| `DuplicateTransactionException` | duplicate-detection blocking flag | 409 `duplicate_suspected` (typed-reason override) |
| `CurrencyImmutableException` | changing an account currency after first txn | 422 `currency_immutable` |
| `InsufficientAvailableBalanceException` | payment exceeds `available_balance` | 422 `insufficient_funds` |
| `AiPrincipalForbiddenException` | AI principal hit a money-moving route | 403 `ai_forbidden` |
| `ClosedPeriodException` | re-matching inside a closed reconciliation | 409 `reconciliation_closed` |

Exceptions carry structured context (the two balances, current and allowed states) so the handler can
populate `errors[].message` in the caller's language without the service knowing about HTTP. Encrypted
fields (`iban`, `account_number`, `swift_bic`, `payee_payer_iban`) are redacted from all exception
context and logs.

# Testing

Per the platform testing pyramid, a Banking feature is not done until it ships with its migration, API,
permissions, audit logging, AI support, and tests.

- **Unit-test Actions** with repositories and the `JournalPoster` mocked: assert `ClearBankTransaction
  Action` posts exactly one balanced draft, stores the returned `journal_entry_id`, updates the running
  balance, emits `BankTransactionCleared` inside the transaction, and rolls back entirely if the poster
  throws.
- **Unit-test the reconciliation engine** in isolation: exact-reference match scores 45; amount+date
  scores 30; AI-only never auto-commits (the cap); a ≥ 90 score with a ≥ 30 deterministic contribution
  auto-commits and writes a `bank_reconciliation_matches` row with the right `rules_fired`; a 6-day-late
  amount match stays below threshold and lands in the workbench.
- **Unit-test `ApprovalService`**: second key by the first-key approver raises
  `MakerCheckerViolationException`; step-up MFA is required above the high-value threshold.
- **Feature-test through `/api/v1/banking`**: prove the thin controller + FormRequest + policy +
  envelope cooperate; prove tenant isolation (company A gets 404 for company B's account); prove
  default-deny (missing permission ⇒ 403); prove the AI principal type gets 403 on `/transfers`,
  `/approve`, and `/approve-final` even when the permission row is present.
- **Invariant tests**: the DB `CHECK` blocks a `cleared` row with no `journal_entry_id`; a statement
  import whose totals don't tie out is rejected with 422 and writes no lines; a `cleared` transaction
  cannot be `PATCH`ed (409, response names `/reverse`).
- **Idempotency/event tests**: a retried clear (same `bank_txn:{id}:cleared` key) posts no second
  journal; `RelieveReceivableOnClear` is safe to run twice; concurrent clears of the same row serialize
  under the `FOR UPDATE` lock and produce one balance delta.

# End of Document
