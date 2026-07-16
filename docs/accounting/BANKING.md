# Banking & Treasury — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Banking & Treasury
---

# Purpose

The Banking & Treasury module is the system of record for every monetary movement that touches a
company's bank accounts, cash accounts, digital wallets, and virtual accounts inside QAYD. It exists
to answer, at any instant and for any company, three questions with certainty: *how much cash do we
have, where is it, and is our book balance the same number the bank thinks we have*. Every other
financial subsystem in QAYD — Sales, Purchasing, Payroll, Inventory, Tax — eventually resolves to a
cash event, and every one of those cash events is created, reconciled, and reported through this
module.

Concretely, Banking & Treasury is responsible for:

- Maintaining the authoritative list of bank accounts, cash accounts, petty cash floats, and virtual
  accounts a company holds, in any currency, at any supported institution.
- Recording every transaction that moves money into, out of, or between those accounts: deposits,
  withdrawals, incoming and outgoing transfers, card settlements, checks, standing orders, and bank
  fees or interest.
- Reconciling the company's internal transaction ledger against bank-issued statements (CSV, Excel,
  PDF, or a live Open Banking feed), automatically where confidence is high and through a guided
  manual workflow where it is not.
- Producing a real-time cash position, a rolling cash-flow forecast, and the treasury views (liquidity,
  intercompany funding, currency exposure) that a CFO or Treasury function needs to run the business.
- Executing payment runs — to customers as refunds, to vendors as bill payments, to employees as
  payroll, and between the company's own accounts — under an approval chain that a human, never an AI
  agent alone, ultimately authorizes.
- Converting every foreign-currency cash event into the company's base currency, tracking unrealized
  and realized FX gain/loss, and periodically revaluing foreign-currency balances.
- Posting a fully balanced journal entry into the General Ledger for every one of the above events, so
  that Accounting, Tax, and Financial Statements are always derived from the same single source of
  truth as the bank ledger.
- Giving the AI layer (Treasury Manager, Fraud Detection, Forecast Agent, Document AI/OCR Agent)
  read access to transaction history and statement data so it can propose matches, flag anomalies, and
  forecast liquidity — while never being permitted to move money on its own.

Banking & Treasury does not own customer invoicing, vendor billing, or payroll calculation — those
belong to Sales, Purchasing, and Payroll respectively. It owns the *cash side* of those processes: the
receipt that settles an invoice, the payment that settles a bill, the disbursement that pays a payslip.

# Vision

QAYD's Banking & Treasury module is designed to make a mid-market Gulf company's treasury function
feel like it belongs to a company ten times its size — without a ten-times-larger finance team. The
long-run vision has four pillars:

1. **Statement-in, books-reconciled, in minutes, not days.** Today most SME finance teams in the
   region reconcile bank statements manually in a spreadsheet, once a week if at all. QAYD's matching
   engine, backed by the AI layer, closes a typical month's reconciliation to 95%+ auto-matched lines,
   with the remaining exceptions surfaced as a short, prioritized worklist instead of a blank
   spreadsheet.
2. **One true cash position, always live.** A CFO should never have to ask "which of these five bank
   portals is current." QAYD aggregates every account — commercial, Islamic, investment, digital,
   wallet, petty cash, virtual — into one live dashboard with drill-down to the transaction level,
   refreshed continuously where Open Banking connectivity exists and on statement import otherwise.
3. **Forecast before you're surprised.** Liquidity problems are rarely a surprise in hindsight; they
   are a forecast nobody ran. The Forecast Agent turns historical inflow/outflow patterns, open
   receivables and payables, and payroll/tax calendars into a rolling 13-week cash-flow forecast that
   updates every time a new transaction, invoice, or bill is recorded.
4. **AI accelerates, humans authorize.** Every AI capability in this module — matching, fraud scoring,
   duplicate detection, payment suggestions — is designed to remove drudgery from the accountant's day
   without ever removing a human decision-maker from an operation that moves money out of the company.
   This is a design constraint, not a compliance afterthought: it is enforced structurally in the
   permission model and the payment-approval workflow described below.

The module is built so that a company operating purely in KWD with two commercial bank accounts and a
company operating across KWD/AED/SAR/USD with a treasury desk, an Islamic bank relationship, and a
digital-wallet payout rail use exactly the same schema, exactly the same API, and exactly the same
reconciliation engine — only the configuration differs.

# Banking Philosophy

QAYD's banking design rests on six non-negotiable principles:

**1. The bank statement is ground truth for cash; the ledger is ground truth for accounting meaning.**
QAYD never edits an imported bank-statement line. `bank_statement_lines` rows are immutable once
imported (they may only be soft-deleted if a statement import is voided and re-run). Reconciliation
links a statement line to one or more `bank_transactions` rows; it never rewrites either side to force
agreement. Where a real difference exists (a bank fee QAYD didn't know about, a timing difference, a
bank error), the system creates an explicit adjusting transaction with its own audit trail — it never
silently absorbs the difference.

**2. Every transaction that changes a bank balance posts a balanced journal entry — no exceptions.**
A deposit with no counterpart journal line is not a valid deposit; it is a data-entry incident. The
Banking module never allows a `bank_transactions` row to reach `status = 'cleared'` without a
`journal_entry_id` pointing to a posted, balanced entry in `journal_entries`/`journal_lines`.

**3. Movement of money out of the company is always a two-key operation.** A single individual —
human or AI — can never both initiate and authorize an outgoing payment or an inter-bank transfer
above a company-configured threshold. The approval chain is **Finance Manager → CEO** for any
outgoing payment, wire transfer, or transfer between bank accounts that exceeds the configured
auto-approval limit (default: any amount for wires/transfers, and bill/vendor payments above a
company-set micro-payment threshold, e.g. KWD 50, may auto-approve if pre-approved in a payment run
already signed off by the Finance Manager). AI never appears in this chain as an approver; it only
prepares the payment batch.

**4. Multi-currency is a first-class concept, not an edge case.** Every bank account has exactly one
account currency. Every transaction records its transaction-currency amount, the account's currency,
the exchange rate used, and the resulting base-currency amount at the moment of the transaction. FX
gain/loss is computed, not estimated, at settlement and at every periodic revaluation.

**5. Reconciliation confidence is scored, not binary.** The matching engine does not simply mark a
line "matched" or "unmatched." Every proposed match — automatic or AI-suggested — carries a confidence
score and the specific matching rule(s) that fired. Anything under the confidence threshold configured
per company (default 90%) is routed to manual review rather than auto-committed.

**6. Nothing is deleted, everything is versioned.** Bank accounts are closed, not deleted. Transactions
are voided/reversed, not deleted. Reconciliations are unmatched and re-matched, not erased. The system
always answers "who changed what, when, and why," because in banking data that question is asked by
auditors, not just engineers.

# Supported Financial Institutions

QAYD models a "financial institution" as any external or internal entity that can hold or move money
on behalf of a company. The `bank_accounts` table (defined fully in **Database Design**) has an
`institution_type` enum that determines which fields are relevant, which reconciliation import formats
apply, and which AI/compliance checks run. The supported institution types and their operating
characteristics:

| Institution Type | Examples (Kuwait / GCC context) | IBAN Required | SWIFT Required | Open Banking | Typical Import Format | Notes |
|---|---|---|---|---|---|---|
| Commercial Bank | NBK, KFH (conventional arm), Gulf Bank, Burgan Bank, CBK, ABK, Boubyan (conventional products) | Yes | Yes | Where available (CBK Open Banking framework) | CSV, MT940/CAMT.053, PDF, API | Default institution type; supports checks, wires, standing orders. |
| Islamic Bank | Kuwait Finance House, Boubyan Bank, Warba Bank, Ahli United Bank Islamic window | Yes | Yes | Where available | CSV, MT940/CAMT.053, PDF, API | `is_shariah_compliant = true`; no interest fields — `profit_distribution` replaces `interest_earned`; product types restricted to Murabaha/Wakala deposit accounts, current accounts (Qard Hasan structure), and Islamic financing facilities. |
| Investment Bank | Markaz, KAMCO Invest, private banking desks | Yes | Yes | Rare | PDF, manual entry, custodian file feed | Used for treasury placements, money-market funds, sukuk holdings; `account_subtype = 'investment'`; feeds the Cash Management liquidity forecast as a "near-cash" bucket rather than operating cash. |
| Digital Bank | Fintech-licensed digital-only banks (regional neobanks) | Yes | Sometimes (may use a sponsor bank's SWIFT/BIC) | Yes, API-native | API (Open Banking), CSV | Typically fastest reconciliation path — near-real-time statement lines via webhook. |
| Payment Provider | Tap Payments, MyFatoorah, Stripe (where used), PayPal | No (uses provider account ID) | No | Provider-specific settlement API | Settlement CSV, provider API/webhook | Modeled as `institution_type = 'payment_provider'`; settlement batches land as `bank_transactions` of `transaction_type = 'incoming_payment'` net of provider fees, which post separately as `fee` transactions. |
| Wallet | Company-held digital wallet balances (e.g. STC Pay for business, KNET wallet balances) | No | No | Provider webhook where available | CSV export, provider API | `account_type = 'wallet'`; balances typically capped by regulator; treated as operating cash for forecasting. |
| Cash Account | Till/register cash pooled at a branch level | No | No | N/A | Manual entry only | `account_type = 'cash'`; requires `branch_id`; reconciled against a physical cash count (`stock_counts`-style count reconciliation is out of scope; cash counts use `bank_reconciliations` with `reconciliation_type = 'cash_count'`). |
| Petty Cash | Small discretionary float held by a department or employee custodian | No | No | N/A | Manual entry only | `account_type = 'petty_cash'`; has a `custodian_user_id` and a `float_limit`; replenishment is itself a `bank_transactions` withdrawal from the funding bank account. |
| Virtual Account | Bank-issued sub-account/IBAN used to segment incoming receivables (e.g. per-customer or per-project collection IBANs) | Yes (a real, addressable IBAN) | Inherits parent SWIFT | Yes, where the issuing bank supports it | API, CSV | `account_type = 'virtual'`; always has a `parent_bank_account_id`; funds swept from a virtual account into the parent account post as an internal `transfer`. |

Every institution type shares the same `bank_accounts` row shape; type-specific behavior is enforced
in the Service layer (Laravel `BankAccountService`) via a strategy per `institution_type`, not via
separate tables. This keeps reconciliation, reporting, and the API completely uniform regardless of
what kind of institution is behind an account.

# Bank Account Structure

A `bank_accounts` row is the single representation of any place a company's money can sit, as
described above. The following fields are the ones a caller (UI, API consumer, AI agent) needs to
understand; the exhaustive column list with types and constraints is in **Database Design**.

| Field | Type | Description |
|---|---|---|
| `bank_name` | VARCHAR(160) | Legal name of the institution (e.g. "Kuwait Finance House"). Free text for institutions not in a curated list; `bank_code` links to a curated `institution_type`-aware lookup when available. |
| `branch_name` | VARCHAR(160) | Branch/agency name as printed on the account documentation. |
| `branch_code` | VARCHAR(20) | Bank-assigned branch/sort code, where the institution uses one. |
| `iban` | VARCHAR(34) | International Bank Account Number, validated against ISO 13616 (mod-97 check digit) and normalized to remove spaces. Unique per company + institution. Nullable for wallet/cash/petty-cash. |
| `swift_bic` | VARCHAR(11) | 8 or 11-character SWIFT/BIC code, validated against ISO 9362 format. Nullable for wallet/cash/petty-cash. |
| `account_number` | VARCHAR(40) | Raw account number as issued by the bank, independent of IBAN (some institutions still quote a local account number alongside the IBAN). |
| `currency_code` | CHAR(3) | ISO 4217 code. Immutable after first transaction — an account cannot change currency; open a new account instead. |
| `account_type` | ENUM | `checking`, `savings`, `wallet`, `cash`, `petty_cash`, `virtual`, `investment`, `credit_card`, `loan`. |
| `institution_type` | ENUM | See **Supported Financial Institutions**: `commercial_bank`, `islamic_bank`, `investment_bank`, `digital_bank`, `payment_provider`, `wallet`, `cash`, `petty_cash`. |
| `status` | ENUM | `active`, `dormant`, `frozen`, `closed`. `frozen` blocks all outgoing transactions but permits incoming; `closed` blocks all new transactions and requires the balance to be zero or swept out first. |
| `opening_balance` | NUMERIC(19,4) | Balance recorded the moment the account was onboarded into QAYD (not necessarily the bank's own account-opening balance if onboarded mid-life). |
| `opening_balance_date` | DATE | Effective date of `opening_balance`. |
| `current_balance` | NUMERIC(19,4) | Book balance: opening balance plus every posted `bank_transactions` row to date. Maintained as a running total, recomputed nightly by a reconciliation job as an integrity check against the sum of transactions. |
| `available_balance` | NUMERIC(19,4) | `current_balance` minus `frozen_balance` minus any amount held against pending, not-yet-cleared outgoing transactions. This is the number Payment Processing checks before allowing a new payment to be scheduled against the account. |
| `frozen_balance` | NUMERIC(19,4) | Amount held for legal/regulatory reasons (e.g. a court order, a bank-side lien, a KYC hold) or an internal treasury hold. Never available for scheduling. |
| `is_shariah_compliant` | BOOLEAN | True for Islamic-bank accounts; drives which financial fields/labels the UI shows (profit distribution vs. interest). |
| `authorized_users` | Relationship | See below — the set of users permitted to view, initiate, or approve transactions on this account, each with a role. |
| `is_default_disbursement` | BOOLEAN | Marks the account payroll and vendor-payment runs default to unless overridden per run. |

**Authorized Users.** Rather than a single "owner," every bank account has a many-to-many relationship
to `users` through a `bank_account_users` join table carrying a per-account role:

| Role on account | Can view balance | Can view transactions | Can initiate payment | Can approve payment | Can reconcile | Can close account |
|---|---|---|---|---|---|---|
| `viewer` | Yes | Yes | No | No | No | No |
| `operator` | Yes | Yes | Yes (creates draft) | No | Yes | No |
| `approver` | Yes | Yes | No | Yes | No | No |
| `treasury_admin` | Yes | Yes | Yes | Yes* | Yes | Yes |

\* A `treasury_admin` may hold approval rights at the system-permission level, but the **two-key rule**
in **Banking Philosophy** still applies: a `treasury_admin` who initiated a specific payment cannot be
the same user who approves that same payment, enforced at the Service layer regardless of role.

A company may add a bank account only through a controlled onboarding flow that requires the account's
IBAN (or account number, for institution types without one) to be independently verified — either via
a micro-deposit verification, an Open Banking consent flow, or an uploaded bank letter/statement
reviewed by a Finance Manager — before the account's `status` can move from `pending_verification` to
`active`. This exists specifically to block a fraud pattern where a compromised account is used to add
an attacker-controlled account as a payment destination.

# Bank Transactions

`bank_transactions` is the canonical ledger of every cash movement. Every row belongs to exactly one
`bank_accounts` row (the account whose balance it affects) and, for two-sided movements (transfers),
is mirrored by a linked row on the counterpart account, both referenced from a single `transfers`
record.

**Transaction types** (`transaction_type` enum) and their behavior:

| Type | Direction | Typical Source | Requires Approval Chain | Posts To |
|---|---|---|---|---|
| `deposit` | In | Manual entry, cash deposit, check deposit | No | Debit Bank, Credit source (AR/Other Income/Owner Contribution) |
| `withdrawal` | Out | Manual entry, ATM cash withdrawal | Above threshold only | Debit destination, Credit Bank |
| `transfer_in` | In | Counterpart of a `transfers` row | N/A (approval lives on the transfer) | Debit Bank, Credit Transit/other bank |
| `transfer_out` | Out | Counterpart of a `transfers` row | Yes — see **Bank Account Structure** two-key rule | Debit Transit/other bank, Credit Bank |
| `incoming_payment` | In | Customer receipt, payment-provider settlement | No | Debit Bank, Credit AR / Unearned Revenue |
| `outgoing_payment` | Out | Vendor payment, refund, payroll disbursement | Yes | Debit AP / Expense / Payroll Payable, Credit Bank |
| `fee` | Out | Bank-charged fee (monthly maintenance, wire fee, card fee) | No (system-generated from statement) | Debit Bank Charges Expense, Credit Bank |
| `interest_earned` | In | Conventional bank interest credit | No | Debit Bank, Credit Interest Income |
| `profit_distribution` | In | Islamic bank profit-sharing credit | No | Debit Bank, Credit Profit Distribution Income |
| `loan_disbursement` | In | Draw against a credit facility/loan | Yes (facility-level pre-approval) | Debit Bank, Credit Loan Payable |
| `loan_repayment` | Out | Scheduled or ad hoc loan repayment | Yes above threshold | Debit Loan Payable + Interest Expense, Credit Bank |
| `credit_card_charge` | Out | Corporate card transaction settling to the linked account | No (card-level controls apply) | Debit Expense (by category), Credit Credit Card Payable |
| `debit_card_transaction` | Out | Direct debit-card spend against a bank account | No | Debit Expense, Credit Bank |
| `atm_withdrawal` | Out | Cash withdrawal via ATM | Above threshold | Debit Cash on Hand, Credit Bank |
| `pos_settlement` | In | Point-of-sale batch settlement (net of merchant fee) | No | Debit Bank, Credit Sales/AR; Debit Merchant Fees Expense (fee leg) |
| `check_deposit` | In | Physical/remote check deposit, pending clearance | No | Debit Checks-in-Clearing (asset), Credit AR — reclassified to Bank on clearance |
| `check_issued` | Out | Check written against the account, pending presentment | Yes above threshold | Debit AP, Credit Checks Outstanding (liability) — reclassified to Bank on presentment |
| `wire_transfer_out` | Out | SWIFT/local wire | Yes, always | Debit destination, Credit Bank |
| `wire_transfer_in` | In | Incoming SWIFT/local wire | No | Debit Bank, Credit source |
| `standing_order_execution` | Out | Automatic execution of a `standing_orders` schedule | Pre-approved at schedule creation | Debit destination, Credit Bank |
| `recurring_payment` | Out | Subscription-style recurring vendor/utility payment | Pre-approved at schedule creation | Debit Expense, Credit Bank |
| `adjustment` | Either | Reconciliation-driven correction (see **Bank Reconciliation**) | Yes | Varies |

**Lifecycle.** Every `bank_transactions` row moves through a state machine:

```
draft -> pending_approval -> approved -> scheduled -> submitted -> pending_clearance -> cleared
                 |                                          |
                 v                                          v
              rejected                                  failed --> retried | cancelled
                                                              
cleared -> reconciled   (set by Bank Reconciliation, not a terminal replacement of "cleared")
any non-terminal state -> voided   (draft/pending_approval/approved/scheduled only; never voids a cleared/reconciled row directly — see Adjustments)
```

- `draft`: created by a user, an import, or an AI suggestion; not yet posted to the GL; fully editable.
- `pending_approval`: submitted into the approval chain (Finance Manager → CEO for sensitive types).
- `approved`: chain satisfied; the journal entry is created in `draft` accounting state but the bank
  transaction itself is now immutable in its financial fields (amount, account, currency).
- `scheduled`: approved and has a future `value_date`; will auto-submit to the payment rail on that date.
- `submitted`: sent to the bank/payment rail (wire instruction filed, check printed, ACH file sent).
- `pending_clearance`: bank has acknowledged but not confirmed final settlement (typical for checks,
  cross-border wires, and card settlement batches).
- `cleared`: the bank confirms the funds have moved; `current_balance` on the account updates; the
  linked journal entry posts (`journal_entries.status = 'posted'`).
- `reconciled`: a **Bank Reconciliation** matching engine has linked this row to a `bank_statement_lines`
  row with sufficient confidence, and a Finance user (or the auto-match rule set) has confirmed it.
- `failed`: the bank/rail rejected the transaction (insufficient funds, invalid IBAN, compliance hold);
  can be `retried` (creates a new draft copying the payload) or `cancelled`.
- `voided`: cancels a transaction before clearance; if it was already posted in draft-journal form, the
  journal entry is voided in lockstep.

A **cleared and reconciled** transaction is never edited. If a cleared transaction turns out to be
wrong (wrong amount entered, wrong account, duplicate), it is corrected exclusively via a **reversing
transaction** referencing the original through `reversed_transaction_id`, exactly mirroring the
immutability rule that governs `journal_entries`.

**Bank fees and interest.** Fees and interest/profit typically do not originate inside QAYD — they
originate on the bank statement. The reconciliation engine, on encountering a statement line with no
plausible match to an existing `bank_transactions` row and a description matching a fee/interest
pattern (see **Bank Reconciliation → Matching Rules**), auto-creates a `fee` or
`interest_earned`/`profit_distribution` transaction in `cleared` status directly, posts its journal
entry, and links it to the statement line in the same operation — this is one of the few transaction
types allowed to originate from reconciliation rather than from a payment/receipt flow, precisely
because the company did not initiate it and there is nothing to "approve" after the fact beyond normal
bookkeeping review.

**Loans and credit facilities.** A credit facility (overdraft line, term loan, revolving facility) is
modeled as a `bank_accounts` row of `account_type = 'loan'` with a negative-normal balance convention:
`current_balance` represents the outstanding principal as a positive number, and `available_balance`
represents undrawn headroom (`credit_limit - current_balance`). Disbursements and repayments are
ordinary `bank_transactions` rows against that account, exactly like a checking account, which keeps
loan tracking inside the same reconciliation and reporting machinery instead of a bespoke loan module.

**Credit and debit cards.** A corporate card is modeled as a `bank_accounts` row of
`account_type = 'credit_card'` linked to a settlement account via `settlement_account_id`. Individual
card swipes import as `credit_card_charge` transactions (typically via statement import, since card
networks rarely offer per-swipe Open Banking feeds to the cardholding company); the monthly settlement
payment from the settlement account to the card account posts as a `transfer` between the two
`bank_accounts` rows. Debit-card spend against a checking account, by contrast, posts directly as a
`debit_card_transaction` against that checking account — there is no separate card "account" for a
debit card because it draws directly from the linked bank account's balance.

**ATM and POS.** `atm_withdrawal` converts bank balance to physical cash — the offsetting debit is to a
`Cash on Hand` account (which may itself be represented by a `cash` or `petty_cash` `bank_accounts`
row if the withdrawn cash is tracked as a QAYD-managed float, or to a generic GL cash account if not).
`pos_settlement` represents a merchant-acquirer batch deposit, net of the acquirer's discount fee; QAYD
records the gross sales credit and the fee debit as two lines of the same journal entry so the P&L
shows both revenue and merchant-fee expense rather than a single netted deposit.

**Standing orders and recurring payments.** These are schedules, not transactions in themselves. A
`standing_orders` row (full DDL in **Database Design**) defines a recurrence (`frequency`,
`day_of_month` or `day_of_week`, `start_date`, `end_date`/`max_occurrences`), a payee, an amount (fixed
or formula-derived, e.g. "100% of `rent_expense` budget line"), and the source account. On each due
date, a scheduled job materializes a `bank_transactions` row of type `standing_order_execution` or
`recurring_payment` in `scheduled` status, inheriting the standing order's pre-approval (a standing
order itself went through the Finance Manager → CEO chain once, at creation or at any amount change;
individual executions do not re-trigger the full chain unless the amount exceeds the originally
approved envelope, in which case the specific execution is held in `pending_approval`).

# Bank Reconciliation

Reconciliation is the process of proving that `bank_transactions` (QAYD's internal ledger of what the
company believes happened) and `bank_statement_lines` (what the bank says happened) describe the same
reality. It runs per `bank_accounts` row, per statement period, and produces a `bank_reconciliations`
row summarizing the result.

**Statement Import.** A statement enters QAYD through one of four channels, each normalized into the
same `bank_statement_lines` shape before matching runs:

| Channel | Format(s) | Ingestion Path | Latency |
|---|---|---|---|
| Open Banking API | ISO 20022 CAMT.053, bank-proprietary JSON/REST (via aggregator or direct bank API where the bank offers it under CBK's Open Banking framework) | `POST /api/v1/banking/bank-accounts/{id}/open-banking/sync` triggers a Laravel queued job that calls the bank/aggregator, normalizes the payload, and inserts `bank_statement_lines` | Near real-time to hourly, depending on provider polling/webhook support |
| Structured file | MT940, CAMT.053 XML | `POST /api/v1/banking/statement-imports` with `format=mt940|camt053` | Batch, on upload |
| Spreadsheet | CSV, XLSX | Same endpoint, `format=csv|xlsx`, with a column-mapping step (bank-specific column layouts are saved per `bank_accounts.bank_name` as a reusable `import_template`) | Batch, on upload |
| Document | PDF (scanned or native bank statement) | Same endpoint, `format=pdf`; routed through the Document AI/OCR Agent, which extracts line items, dates, and amounts with a per-field confidence score before they are written as `bank_statement_lines` | Batch, on upload; OCR adds seconds-to-minutes depending on page count |

Every import creates an immutable `bank_statement_lines` batch tagged with a `statement_import_id`,
the statement's `period_start`/`period_end`, and the bank-reported `opening_balance`/`closing_balance`
for that period — these two numbers are the arithmetic check the reconciliation must ultimately tie
out against (`opening_balance + Σ statement lines = closing_balance`, verified on import; a mismatch
blocks the import and surfaces a parsing/OCR-confidence warning rather than silently proceeding).

**Automatic Matching.** The matching engine runs immediately after import and again on any new
`bank_transactions` row reaching `cleared` status. It attempts, per unmatched `bank_statement_lines`
row, to find one or more unmatched `bank_transactions` rows whose combination satisfies a matching
rule. Matches are 1:1, 1:many (one statement line = several transactions, e.g. a single payroll wire
batch matching several `outgoing_payment` rows to different employees is *not* modeled this way —
payroll batches post as one bank transaction per net pay, see **Payment Processing** — but a single
customer receipt covering two invoices is modeled as one statement line to one transaction with two
`receipt_allocations`), or many:1 (several small transactions netting to one statement line, e.g.
several POS terminal batches settling as one daily deposit).

**Matching Rules**, evaluated in order, each contributing to a cumulative confidence score
(0–100, threshold for auto-commit configurable per company, default 90):

| Rule | Signal | Weight | Notes |
|---|---|---|---|
| Exact reference match | Statement line's bank reference / end-to-end ID equals `bank_transactions.bank_reference` | 45 | Highest-confidence signal; set on any transaction submitted through a wire/ACH rail that returns a reference. |
| Amount + date exact | `amount` equal to the cent, `value_date` within a configurable window (default ±2 business days) | 30 | Core rule for manual/check transactions with no bank reference. |
| Amount + counterparty fuzzy | Amount exact; counterparty name/IBAN on the statement line fuzzy-matches (Levenshtein/phonetic) the transaction's payee/payer | 15 | Bank-abbreviated payee names ("ABC TRD CO" vs "ABC Trading Company W.L.L.") are common. |
| Recurring pattern | Statement line matches a known `standing_orders`/`recurring_payment` schedule's expected amount and date | 10 | |
| AI similarity score | Treasury Manager AI agent's learned similarity model over historical matched pairs for this account | up to 10 (additive, capped so AI alone cannot cross the auto-commit threshold without at least one deterministic signal ≥ 30) | See **AI Responsibilities**. |

A candidate match that reaches the threshold is committed automatically: the `bank_transactions` row
moves to `reconciled`, the `bank_statement_lines` row is marked `matched`, and a
`bank_reconciliation_matches` join row records which rules fired and the final score, for audit.

**Manual Matching.** Statement lines and transactions that do not clear the auto-commit threshold are
queued in a reconciliation workbench (`GET /api/v1/banking/bank-accounts/{id}/reconciliation/unmatched`)
showing the AI's best candidate(s), their score, and the reasons a rule didn't fully fire (e.g. "amount
matches, date is 6 days outside window"). A user with `bank.reconcile` permission can:

- Accept the AI's suggested match (one click, records `matched_by = user_id`, `match_method = 'ai_suggested_accepted'`).
- Manually pair a different statement line and transaction (`match_method = 'manual'`).
- Split a statement line across multiple transactions or vice versa.
- Mark a statement line as **no matching transaction exists** — this is the trigger to create a new
  transaction (typically a `fee`, `interest_earned`, or `adjustment`) directly from the workbench,
  pre-filled from the statement line's amount/date/description.

**Difference Detection.** After all matching (auto + manual) completes for a period, the engine
computes:

```
book_balance_at_period_end   = bank_accounts.current_balance as of period_end
bank_balance_at_period_end   = bank_statement_lines closing_balance for the period
outstanding_deposits         = Σ cleared, unreconciled deposit-type transactions dated <= period_end
outstanding_withdrawals      = Σ cleared, unreconciled withdrawal/payment-type transactions dated <= period_end
adjusted_bank_balance        = bank_balance_at_period_end + outstanding_deposits - outstanding_withdrawals
variance                     = book_balance_at_period_end - adjusted_bank_balance
```

A `variance` of exactly zero closes the reconciliation as `balanced`. Any non-zero `variance` keeps the
`bank_reconciliations` row in `status = 'discrepancy'` and requires either identifying the missing
transaction(s) or posting an explicit `adjustment` transaction (e.g. an unrecorded bank fee, a bank
error later corrected by the bank itself, or — rarely — a genuine unexplained variance that is escalated
rather than silently posted).

**Adjustments.** An adjustment transaction always carries `reconciliation_id` linking it to the specific
`bank_reconciliations` row that surfaced it, `adjustment_reason` (free text, required), and — because it
changes the books based on someone's judgment rather than a straightforward bank fee — routes through
the same **Approval Workflow** as any other financial correction: a Senior Accountant or Finance
Manager must approve before it posts, regardless of amount.

**Approval Workflow (reconciliation-specific).** Closing a `bank_reconciliations` period
(`status: discrepancy|balanced -> closed`) requires `bank.reconcile.close` permission (Finance Manager
and above). Once closed, the underlying `bank_transactions` and `bank_statement_lines` rows for that
period are locked from further re-matching — reopening a closed reconciliation requires
`bank.reconcile.reopen` (Finance Manager and above) and is itself audit-logged with a required reason,
mirroring how a closed `fiscal_periods` row is reopened in Accounting.

# Cash Management

**Cash Flow.** QAYD computes cash flow at two grains. The *actual* cash flow statement (direct method)
is derived purely from posted `bank_transactions`, grouped into Operating, Investing, and Financing
activities by mapping each transaction's underlying journal account to one of the three categories (a
configurable mapping on `accounts`, defaulting sensibly by `account_types`). The *projected* cash flow
(the forecast) blends actuals with open commitments — unpaid `invoices` (expected inflow on
`due_date`), unpaid `bills` (expected outflow on `due_date`), scheduled `payroll_runs`, upcoming
`standing_orders`/`recurring_payment` executions, and open `tax_returns` liabilities — into the same
weekly buckets.

**Liquidity.** The Liquidity Dashboard (see **Reports**) aggregates `available_balance` across every
`active` `bank_accounts` row, broken out by currency and by "operating" vs. "near-cash" (investment
accounts, term deposits) vs. "restricted" (frozen balances, amounts held against approved-but-unsettled
payments). A company's **liquidity ratio** for treasury purposes is computed as:

```
liquidity_ratio = (operating_cash + near_cash) / next_30_day_committed_outflows
```

where `next_30_day_committed_outflows` sums approved-but-unsettled payments, scheduled standing orders,
forecast payroll, and forecast tax liabilities due within 30 days. A ratio below a company-configured
floor (default 1.2) triggers a Notification to the Finance Manager and CFO roles and feeds the
Treasury Manager AI agent's liquidity-risk flag.

**Forecast.** The 13-week rolling cash-flow forecast (`cash_flow_forecasts` — see the Forecast Agent in
**AI Responsibilities** for the model; the persisted forecast itself is stored as JSONB snapshots on a
lightweight `cash_flow_forecasts` table so historical forecast accuracy can be measured against
subsequent actuals) buckets projected inflows/outflows by week across: confirmed invoices (by due date
and, for repeat customers, a learned late-payment offset), confirmed bills, payroll calendar, tax
calendar, standing orders, and a residual "other operating" line derived from trailing 13-week
averages of uncategorized recurring flow. Each weekly bucket carries a confidence band, not a single
number.

**Treasury.** The Treasury view surfaces: the current facility utilization across every `loan`/credit
`bank_accounts` row (drawn vs. available), the currency exposure table (net asset/liability position
per non-base currency — see **Multi Currency**), and any term-deposit / investment maturity calendar
(from `investment_bank` accounts) so a Treasury user can see upcoming maturities that will convert to
operating liquidity.

**Intercompany Transfers.** Where a QAYD tenant operates multiple legal entities as separate
`companies` rows under one organizational umbrella, a transfer of funds between two companies' bank
accounts is still a `transfers` row, but with `is_intercompany = true` and both a `from_company_id` and
`to_company_id` populated (in addition to the standard `company_id` scoping, which is set to the
initiating company for permission purposes). Each side posts its own journal entry against a
reciprocal **Intercompany Receivable/Payable** account, since the double-entry rule (**Accounting
Integration**) must balance within each company's own books — QAYD never posts a single journal entry
that spans two `company_id` values, consistent with the platform's strict tenant isolation. Intercompany
transfers always require Finance Manager → CEO approval **on both sides**, regardless of amount.

# Payment Processing

Payment Processing is where money actually leaves (or, for refunds, re-leaves) the company, so it
inherits the strictest controls in the module.

**Customer Payments (Receipts).** Not a payment out, but included here as the inbound mirror: a
customer receipt against one or more `invoices` posts as an `incoming_payment` `bank_transactions` row
linked to `receipts`/`receipt_allocations` (owned by the Sales module; Banking only owns the cash leg
and its journal posting). No approval chain applies to incoming money.

**Vendor Payments.** A vendor payment settles one or more `bills`. It is created as a `payment_batches`
concept at the API level (a batch may contain many individual `outgoing_payment` transactions, one per
vendor per source account) but each individual transaction still carries its own approval state. A
Finance Manager reviews a proposed batch — often AI-prepared, see **AI Responsibilities → Payment
Suggestions** — selects which bills to pay this run, and submits the batch to
`pending_approval`; the CEO (or a delegate with `bank.payment.approve.final`) gives final sign-off,
after which each transaction moves to `approved` and then `scheduled` for its `value_date`.

**Payroll Payments.** A `payroll_runs` completion event (owned by Payroll) creates one
`outgoing_payment` transaction per employee's net pay, all tagged with the same `payroll_run_id`,
against the payroll disbursement account. Payroll payments still require Finance Manager → CEO
approval as a single batch action (approving the batch approves all constituent transactions
atomically) — payroll release is explicitly listed as a sensitive operation in the platform-wide
permission rules and Banking enforces it identically for the cash leg even though Payroll enforces its
own `payroll.approve` gate for the calculation itself.

**Refunds.** A customer refund (owned jointly with Sales via `refunds`) is an `outgoing_payment` from
the company to a customer, reversing part or all of a prior receipt. It follows the same approval chain
as any vendor payment; it is never auto-approved regardless of amount, because refunds are a
historically common fraud vector (a compromised account issuing "refunds" to an attacker-controlled
IBAN).

**Internal Transfers.** Movement between two of the company's own `bank_accounts` rows — e.g. sweeping
a virtual account into its parent, funding a petty cash float, moving cash from an operating account
into a term-deposit account. Modeled as a `transfers` row with `transfer_type = 'internal'`. Same
Finance Manager → CEO chain applies above the company's configured internal-transfer auto-approval
threshold (default: internal transfers below KWD 1,000 between two accounts the same `treasury_admin`
controls may auto-approve; above that, or between accounts with different sets of authorized users,
always requires the full chain).

**Scheduled Payments.** Any `outgoing_payment`, `transfer_out`, or refund may be created with a future
`value_date` rather than executing immediately. Once approved, it sits in `scheduled` state and a
queued job (`ScheduledPaymentDispatcher`, run every 15 minutes) submits it to the appropriate rail
(wire, check printing, ACH/local clearing file, payment-provider payout API) exactly at `value_date`
(respecting the account's cutoff time for same-day value). A scheduled payment can be cancelled by an
approver up until the moment it is dispatched; once `submitted`, cancellation is no longer possible
through QAYD and must be pursued with the bank directly (recorded as a `cancellation_requested` note,
not a state QAYD can force).

**Payment Rails Summary:**

| Rail | Used For | Settlement Latency | Reversible by QAYD Before Dispatch |
|---|---|---|---|
| SWIFT wire | Cross-border vendor payments, large domestic transfers | Same day to 2 business days | Yes |
| Local clearing (ACH-equivalent / KNET B2B) | Domestic vendor and payroll payments | Same day to next day | Yes |
| Check | Vendors preferring physical checks, security deposits | Days (presentment-dependent) | Yes (void before printing/mailing) |
| Payment-provider payout API | Marketplace-style vendor/creator payouts, refunds routed through the original collection provider | Minutes to 1 day | Yes, until provider confirms dispatch |
| Internal ledger transfer | Between own accounts at the same bank | Instant to same day | Yes |

# Multi Currency

**Exchange Rates.** QAYD maintains an `exchange_rates` table (company-scoped, since some companies may
subscribe to a premium rate feed while others rely on a daily central-bank rate) keyed by
`(from_currency, to_currency, rate_date)`, storing `rate` as NUMERIC(18,6) and `source`
(`central_bank`, `provider_feed`, `manual`). Rates are fetched daily via a scheduled job from a
configurable provider (default: Central Bank of Kuwait daily reference rates for KWD pairs; a
commercial FX API for cross-rates QAYD's base provider doesn't quote directly) and are also
manually overridable by a Finance Manager for a specific transaction where the company's bank quoted a
different rate at execution (common with wires, where the bank's dealt rate differs from the published
reference rate) — the transaction-level `exchange_rate` always wins over the daily reference rate for
that specific transaction's base-currency amount; the daily reference rate is used for period-end
revaluation of open balances.

**Currency Conversion.** Every `bank_transactions` row in a currency other than the company's
`base_currency` (set on `companies`) stores both `amount` (transaction currency) and
`base_amount = amount * exchange_rate` at the rate in effect at `value_date`. This base amount, not the
transaction-currency amount, is what posts to the General Ledger — the GL is always expressed in the
company's base currency; the transaction-currency amount is retained for statement matching and
customer/vendor-facing documents only.

**Revaluation.** At each period close (or on demand), a **Currency Revaluation** job recomputes the
base-currency value of every foreign-currency `bank_accounts` balance using the period-end reference
rate and compares it to the currently booked base-currency balance. The difference posts as an
unrealized FX gain or loss:

```sql
-- Illustrative revaluation calculation for one foreign-currency bank account
current_base_balance   = bank_accounts.current_balance_base   -- as currently booked
market_base_balance     = bank_accounts.current_balance * period_end_rate
fx_delta                 = market_base_balance - current_base_balance
-- fx_delta > 0  => unrealized gain (Debit Bank Account [base-ccy contra], Credit Unrealized FX Gain)
-- fx_delta < 0  => unrealized loss (Debit Unrealized FX Loss, Credit Bank Account [base-ccy contra])
```

Unrealized revaluation entries are tagged `is_reversing = true` and automatically reverse on the first
day of the next period, so realized gain/loss recognized when the balance is actually settled/converted
is never double-counted against the unrealized entry.

**FX Gain/Loss.** *Realized* FX gain/loss arises when a foreign-currency balance is actually converted
or a foreign-currency transaction settles against an open item booked at a different rate (e.g. an
invoice booked at one rate, the receipt clearing at another). The difference between the base-currency
amount at booking and the base-currency amount at settlement posts directly to **Realized FX Gain/Loss**
as part of the settlement's own journal entry — see the worked example in **Accounting Integration**.

**Currency Exposure Table** (feeds the Treasury view in **Cash Management**):

| Currency | Assets (bank + investment, base-ccy equiv.) | Liabilities (loans, payables settled in that ccy) | Net Exposure | Hedged | Unhedged Exposure |
|---|---|---|---|---|---|
| KWD (base) | — | — | — | N/A | N/A |
| AED | Σ AED accounts × rate | Σ AED-denominated liabilities × rate | Assets − Liabilities | forward contracts, if tracked | Net exposure − hedged |
| SAR | Σ SAR accounts × rate | Σ SAR-denominated liabilities × rate | Assets − Liabilities | — | — |
| USD | Σ USD accounts × rate | Σ USD-denominated liabilities × rate | Assets − Liabilities | — | — |

QAYD does not natively model FX forward/hedge instruments in v1; the "Hedged" column above is a manual
annotation field (`exposure_hedge_notes`) a Treasury user can populate for reporting completeness. This
is called out explicitly in **Future Improvements**.

# Accounting Integration

Banking never maintains its own parallel accounting truth. Every `bank_transactions` row that reaches
`cleared` status carries a `journal_entry_id` pointing to a balanced entry in `journal_entries` /
`journal_lines`, posted through the same Accounting Engine every other module uses. This section shows
the exact debit/credit shape for the module's core events.

**Journal Entries — Deposit.** A KWD 5,000 customer deposit into the operating account, settling
invoice INV-2026-00417:

| Line | Account | Debit | Credit | Dimension |
|---|---|---|---|---|
| 1 | 1010 · Bank — NBK Operating (KWD) | 5,000.0000 | | `bank_account_id = <acct>` |
| 2 | 1200 · Accounts Receivable — Customer X | | 5,000.0000 | `customer_id = <cust>` |

```json
{
  "journal_entry": {
    "source_module": "banking",
    "source_type": "bank_transactions",
    "source_id": 88213,
    "entry_date": "2026-07-16",
    "currency_code": "KWD",
    "description": "Customer receipt — INV-2026-00417",
    "lines": [
      { "account_code": "1010", "debit": "5000.0000", "credit": "0.0000", "customer_id": 4471 },
      { "account_code": "1200", "debit": "0.0000",   "credit": "5000.0000", "customer_id": 4471 }
    ]
  }
}
```

**Journal Entries — Transfer (between own accounts, cross-currency).** KWD 10,000 swept from the
operating account into a USD investment account at 3.2500 KWD/USD → USD 30,769.23:

| Line | Account | Debit | Credit | Dimension |
|---|---|---|---|---|
| 1 | 1050 · Bank — Investment Account (USD) [base-ccy equiv.] | 10,000.0000 | | `bank_account_id = <usd_acct>` |
| 2 | 1010 · Bank — NBK Operating (KWD) | | 10,000.0000 | `bank_account_id = <kwd_acct>` |

The USD account's own ledger of transaction-currency amounts (`amount = 30769.23`,
`currency_code = 'USD'`, `exchange_rate = 0.325000`) is stored on the `bank_transactions` row itself;
the journal always posts the `base_amount` (KWD 10,000.0000) so the GL never needs to know a
transaction's original currency to balance.

**Journal Entries — Fee.** A KWD 3.500 monthly account maintenance fee detected during reconciliation:

| Line | Account | Debit | Credit |
|---|---|---|---|
| 1 | 6410 · Bank Charges Expense | 3.5000 | |
| 2 | 1010 · Bank — NBK Operating (KWD) | | 3.5000 |

**Journal Entries — FX Revaluation.** A USD 50,000 balance booked at KWD 0.3080/USD (base KWD
15,400.0000) revalued at period-end rate KWD 0.3065/USD (market base KWD 15,325.0000) — an unrealized
loss of KWD 75.0000:

| Line | Account | Debit | Credit |
|---|---|---|---|
| 1 | 7810 · Unrealized FX Loss | 75.0000 | |
| 2 | 1050 · Bank — Investment Account (USD) [revaluation contra] | | 75.0000 |

This entry is flagged `is_reversing = true` and auto-reverses on the first day of the following fiscal
period, so if the balance is later actually converted, the **realized** gain/loss computed at that
moment starts from the original booked rate, not the interim revaluation.

**General Ledger.** Every posted `journal_lines` row from the entries above lands in `ledger_entries`,
the GL projection, exactly as any Sales or Purchasing entry would — Banking has no bespoke GL table.
**Financial Statements.** The Balance Sheet's Cash and Cash Equivalents line sums every `active`
`bank_accounts.current_balance_base` across `checking`, `savings`, `wallet`, `cash`, `petty_cash`, and
`virtual` account types (investment and loan-type accounts appear in their own Balance Sheet lines);
the Cash Flow Statement is generated as described in **Cash Management**.

**Tax.** Bank fees are typically not subject to VAT in GCC jurisdictions (financial services are
largely VAT-exempt), so `fee` and `interest_earned`/`profit_distribution` transactions default to a
`tax_code` of `exempt`; where a specific institution or jurisdiction does charge tax on a fee line
(e.g. some card-processing fees), the `tax_transactions` row is created exactly as any other taxable
expense, referencing the `bank_transactions.id` as its source.

**Sales.** Every `receipts` row (Sales-owned) that clears creates exactly one `incoming_payment`
`bank_transactions` row; the Sales module's own `receipt_allocations` distribute that single cash
receipt across one or more `invoices`, but Banking only cares about the one cash movement and its one
journal entry — invoice-level AR relief is a `receipts`-side concern, not duplicated in Banking's
journal line.

**Purchasing.** Symmetric to Sales: a `vendor_payments` row (Purchasing-owned) that clears creates
exactly one `outgoing_payment` `bank_transactions` row; AP relief across one or more `bills` is handled
by Purchasing's own allocation, while Banking posts the single cash-side journal entry (Debit AP
control account net of any early-payment discount, Credit Bank).

**Payroll.** A `payroll_runs` disbursement batch creates one `outgoing_payment` per employee net pay
(see **Payment Processing**); each posts Debit Salaries/Wages Payable, Credit Bank, at the individual
employee level so the Payroll module's own `payslips` reconcile 1:1 against a specific bank transaction
for audit and payslip-dispute resolution.

**Inventory.** Inventory has no direct cash leg in the normal flow (goods receipt and invoicing are the
Purchasing-side triggers), but Banking integrates indirectly: an inventory-related vendor payment
carries `cost_center_id`/`project_id` dimensions inherited from the originating `purchase_orders`/
`bills`, letting a Cost of Goods / project-costing report slice cash outflow by inventory-driving
project even though Inventory itself never touches `bank_transactions`.

# AI Responsibilities

Every AI capability below is delivered by the FastAPI AI Layer, calling into the Laravel API under the
same user-permission context as the human it is assisting (or, for autonomous background analysis, a
scoped system service account with **read-only** banking permissions — the AI layer never holds
`bank.transfer` or `bank.payment.approve` and structurally cannot call those endpoints). Every AI
output — a suggested match, a fraud flag, a forecast — is written to the database (where it is
persisted at all) via the Laravel API's normal validation and audit path, never by a direct
database write from the AI layer, per the platform-wide rule.

| Agent | Responsibility in this Module | Inputs | Outputs | Autonomy | Confidence Handling |
|---|---|---|---|---|---|
| **Treasury Manager** | Proposes bank-statement-to-transaction matches beyond deterministic rules; maintains the liquidity ratio view; flags facility-utilization risk | Unmatched `bank_statement_lines`, unmatched `bank_transactions`, historical matched pairs for the account | Ranked match candidates with a similarity score (feeds **Bank Reconciliation** matching weight table); liquidity-risk notifications | Suggest-only below auto-commit threshold; contributes to auto-match score only in combination with a deterministic rule (see **Bank Reconciliation**) | Every suggestion carries a 0–100 score and the specific historical pairs it learned from; scores <70 are hidden from the default workbench view (available under "show all") |
| **Fraud Detection** | Screens every `outgoing_payment`, `transfer_out`, and `wire_transfer_out` before it can leave `pending_approval`; flags anomalous payee, amount, timing, or velocity patterns | The transaction under review, the account's historical payment patterns, the payee's history with this company, IP/device metadata of the initiating user | A risk score (0–100) and a structured reason list (`new_payee`, `unusual_amount_for_payee`, `unusual_time_of_day`, `velocity_spike`, `payee_details_changed_recently`); a `hold_recommended` boolean | Suggest-only; a `hold_recommended = true` does not block the transaction outright but forces a mandatory second-look banner in the approver's UI and cannot be dismissed silently — the approver must explicitly acknowledge the flag with a typed reason before approving | Risk score ≥ 80 additionally notifies the Auditor role in real time regardless of whether the approver proceeds |
| **Forecast Agent** | Produces the rolling 13-week cash-flow forecast in **Cash Management** | Historical `bank_transactions`, open `invoices`/`bills` with due dates, `payroll_runs` calendar, `tax_returns` calendar, `standing_orders` schedule | Weekly bucketed inflow/outflow projections with a confidence band per bucket | Fully autonomous generation (read-only, non-financial-record output); never autonomous in acting on the forecast | Confidence band narrows as the forecast horizon shortens (week 1 is near-deterministic from already-scheduled items; week 13 is a statistical projection with the widest band) — displayed explicitly, not hidden behind a single point estimate |
| **CFO (agent)** | Synthesizes Treasury, Forecast, and Fraud signals into a plain-language weekly treasury briefing surfaced to Finance Manager/CFO/CEO roles | Outputs of the other agents above, plus the Liquidity Dashboard snapshot | A narrative summary with the 3-5 most decision-relevant items (e.g. "USD exposure grew 12% this week; facility utilization on the Burgan overdraft crossed 70%") | Suggest-only; produces a report, never an action | Cites the specific underlying figures/reports for every claim it makes, so a human can verify before acting |
| **Document AI / OCR Agent** | Extracts structured line items from PDF/scanned bank statements during **Statement Import** | The uploaded PDF/image | Structured `bank_statement_lines` candidates with a per-field extraction confidence | Auto-commits fields above a 95% confidence threshold; fields below that threshold are highlighted in the import review screen for manual correction before the batch is finalized | Field-level (not just line-level) confidence, since a statement line's date might OCR perfectly while its amount is ambiguous, or vice versa |
| **Duplicate Detection** (a Treasury Manager sub-capability) | Screens new `bank_transactions` and imported `bank_statement_lines` for likely duplicates (double-entry of the same manual deposit, a statement re-imported over an overlapping period) | New/candidate rows vs. existing rows on the same account within a rolling window | A duplicate-likelihood score and the specific matching row it believes is the duplicate | Suggest-only; never auto-deletes or auto-merges — surfaces a blocking confirmation ("this looks identical to transaction #88190 posted 2 minutes ago — proceed anyway?") that the user must explicitly override | Score ≥ 95 requires an explicit typed reason to override, logged to `audit_logs` |
| **Payment Suggestions** (a Treasury Manager sub-capability) | Prepares a proposed vendor-payment batch — which open `bills` to pay this run, from which account, respecting `available_balance` and upcoming commitments from the **Forecast** | Open `bills` with due dates and early-payment-discount terms, current `available_balance` per account, the 13-week forecast | A draft `payment_batches` proposal with a rationale per line (e.g. "pay now — 2% discount expires in 3 days"; "defer — due in 18 days, cash position tight this week per forecast") | Suggest-only; the draft batch requires a Finance Manager to review, edit, and submit into the approval chain — AI never submits a batch | Each suggested line carries its own confidence/rationale text, and the batch as a whole shows the resulting projected `available_balance` after the run so the reviewer sees the consequence before approving |
| **Risk Analysis** (a Fraud Detection sub-capability, portfolio-level) | Periodic (nightly) sweep across all accounts for patterns invisible at the single-transaction level: a payee receiving payments from multiple unrelated companies on the platform (possible shared fraud infrastructure), a sudden change in an account's authorized-user list followed by unusual activity | Cross-account (within tenant-isolation limits — never across `company_id`) transaction history, `bank_account_users` change history, `audit_logs` | Risk bulletins routed to the Auditor and CFO roles | Fully autonomous analysis and notification generation; zero ability to act on findings | Every bulletin links to the exact underlying rows and audit-log entries so the finding is independently verifiable |
| **Liquidity Forecasting** (folded into Forecast Agent above, called out separately here for clarity of autonomy) | Computes the `liquidity_ratio` trigger described in **Cash Management** and raises the associated notification | `available_balance` across accounts, `next_30_day_committed_outflows` | Boolean trigger + the underlying ratio | Fully autonomous computation and notification; zero autonomy over any resulting cash movement | The notification always shows the full formula inputs, not just the resulting ratio |
| **Expense Classification** | Suggests a GL account and dimension set (`cost_center_id`, `project_id`, `department_id`) for `fee`, `withdrawal`, and `outgoing_payment` transactions lacking a clear source document (e.g. a manual withdrawal with only a terse bank description) | Transaction description, amount, historical classification of similar past transactions on this account | A suggested `account_code` + dimensions with a confidence score | Suggest-only; pre-fills the field but requires the creating/approving user to confirm before the transaction can leave `draft` | Confidence <60 leaves the field blank rather than guessing, to avoid silently normalizing a wrong classification into muscle-memory approval |
| **Bank Statement Explanation** | On request, produces a plain-language walkthrough of a specific statement period ("this statement shows 42 lines; 39 auto-matched; the 3 exceptions are: [list]; net movement was +KWD 12,400, driven mainly by...") | The `bank_reconciliations` row and its linked `bank_statement_lines`/`bank_transactions` | A narrative explanation, no data mutation | Fully autonomous (read-only synthesis) | Not applicable — this capability never asserts a fact without pointing at the specific row it came from |

# Database Design

All tables below follow the platform-wide standard columns
(`id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`)
in addition to the columns listed explicitly. `company_id` is `NOT NULL` and indexed on every table;
soft deletes (`deleted_at`) apply everywhere; no financial row is ever hard-deleted.

```sql
-- ============================================================================
-- ENUM TYPES
-- ============================================================================

CREATE TYPE bank_account_type AS ENUM (
  'checking', 'savings', 'wallet', 'cash', 'petty_cash', 'virtual', 'investment',
  'credit_card', 'loan'
);

CREATE TYPE bank_institution_type AS ENUM (
  'commercial_bank', 'islamic_bank', 'investment_bank', 'digital_bank',
  'payment_provider', 'wallet', 'cash', 'petty_cash'
);

CREATE TYPE bank_account_status AS ENUM (
  'pending_verification', 'active', 'dormant', 'frozen', 'closed'
);

CREATE TYPE bank_account_user_role AS ENUM (
  'viewer', 'operator', 'approver', 'treasury_admin'
);

CREATE TYPE bank_transaction_type AS ENUM (
  'deposit', 'withdrawal', 'transfer_in', 'transfer_out', 'incoming_payment',
  'outgoing_payment', 'fee', 'interest_earned', 'profit_distribution',
  'loan_disbursement', 'loan_repayment', 'credit_card_charge',
  'debit_card_transaction', 'atm_withdrawal', 'pos_settlement', 'check_deposit',
  'check_issued', 'wire_transfer_out', 'wire_transfer_in',
  'standing_order_execution', 'recurring_payment', 'adjustment'
);

CREATE TYPE bank_transaction_status AS ENUM (
  'draft', 'pending_approval', 'approved', 'rejected', 'scheduled', 'submitted',
  'pending_clearance', 'cleared', 'reconciled', 'failed', 'voided'
);

CREATE TYPE bank_statement_line_status AS ENUM (
  'unmatched', 'matched', 'ignored'
);

CREATE TYPE bank_reconciliation_status AS ENUM (
  'in_progress', 'discrepancy', 'balanced', 'closed', 'reopened'
);

CREATE TYPE bank_reconciliation_type AS ENUM (
  'statement', 'cash_count'
);

CREATE TYPE match_method AS ENUM (
  'auto_rule', 'ai_suggested_accepted', 'manual', 'split'
);

CREATE TYPE transfer_type AS ENUM ('internal', 'external', 'intercompany');

CREATE TYPE approval_chain_status AS ENUM (
  'not_required', 'pending_finance_manager', 'pending_ceo', 'approved', 'rejected'
);

CREATE TYPE standing_order_frequency AS ENUM (
  'daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually'
);

CREATE TYPE standing_order_status AS ENUM ('active', 'paused', 'expired', 'cancelled');

-- ============================================================================
-- TABLE: bank_accounts
-- ============================================================================
CREATE TABLE bank_accounts (
  id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id               BIGINT NOT NULL REFERENCES companies(id),
  branch_id                BIGINT NULL REFERENCES branches(id),
  bank_name                VARCHAR(160) NOT NULL,
  bank_code                VARCHAR(20)  NULL,
  branch_name              VARCHAR(160) NULL,
  branch_code              VARCHAR(20)  NULL,
  iban                     VARCHAR(34)  NULL,
  swift_bic                VARCHAR(11)  NULL,
  account_number           VARCHAR(40)  NULL,
  currency_code            CHAR(3)      NOT NULL,
  account_type             bank_account_type NOT NULL,
  institution_type         bank_institution_type NOT NULL,
  status                   bank_account_status NOT NULL DEFAULT 'pending_verification',
  is_shariah_compliant     BOOLEAN NOT NULL DEFAULT FALSE,
  opening_balance          NUMERIC(19,4) NOT NULL DEFAULT 0,
  opening_balance_date     DATE NOT NULL,
  current_balance          NUMERIC(19,4) NOT NULL DEFAULT 0,
  current_balance_base     NUMERIC(19,4) NOT NULL DEFAULT 0,
  available_balance        NUMERIC(19,4) NOT NULL DEFAULT 0,
  frozen_balance           NUMERIC(19,4) NOT NULL DEFAULT 0,
  credit_limit             NUMERIC(19,4) NULL,
  custodian_user_id        BIGINT NULL REFERENCES users(id),
  float_limit              NUMERIC(19,4) NULL,
  parent_bank_account_id   BIGINT NULL REFERENCES bank_accounts(id),
  settlement_account_id    BIGINT NULL REFERENCES bank_accounts(id),
  gl_account_id            BIGINT NOT NULL REFERENCES accounts(id),
  is_default_disbursement  BOOLEAN NOT NULL DEFAULT FALSE,
  open_banking_consent_id  VARCHAR(120) NULL,
  external_provider_ref    VARCHAR(120) NULL,
  tags                     JSONB NOT NULL DEFAULT '[]',
  custom_fields            JSONB NOT NULL DEFAULT '{}',
  created_by               BIGINT NULL REFERENCES users(id),
  updated_by               BIGINT NULL REFERENCES users(id),
  created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at               TIMESTAMPTZ NULL,

  CONSTRAINT chk_bank_accounts_iban_format
    CHECK (iban IS NULL OR iban ~ '^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$'),
  CONSTRAINT chk_bank_accounts_swift_format
    CHECK (swift_bic IS NULL OR swift_bic ~ '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$'),
  CONSTRAINT chk_bank_accounts_currency_format
    CHECK (currency_code ~ '^[A-Z]{3}$'),
  CONSTRAINT chk_bank_accounts_float_requires_custodian
    CHECK (account_type <> 'petty_cash' OR custodian_user_id IS NOT NULL),
  CONSTRAINT chk_bank_accounts_virtual_requires_parent
    CHECK (account_type <> 'virtual' OR parent_bank_account_id IS NOT NULL),
  CONSTRAINT chk_bank_accounts_frozen_le_current
    CHECK (frozen_balance <= current_balance OR account_type = 'loan')
);

CREATE UNIQUE INDEX uq_bank_accounts_company_iban
  ON bank_accounts (company_id, iban) WHERE iban IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_bank_accounts_company_id       ON bank_accounts (company_id);
CREATE INDEX idx_bank_accounts_branch_id         ON bank_accounts (branch_id);
CREATE INDEX idx_bank_accounts_status            ON bank_accounts (status);
CREATE INDEX idx_bank_accounts_account_type      ON bank_accounts (account_type);
CREATE INDEX idx_bank_accounts_parent            ON bank_accounts (parent_bank_account_id);
CREATE INDEX idx_bank_accounts_gl_account        ON bank_accounts (gl_account_id);

-- ============================================================================
-- TABLE: bank_account_users  (authorized users, many-to-many with role)
-- ============================================================================
CREATE TABLE bank_account_users (
  id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id       BIGINT NOT NULL REFERENCES companies(id),
  bank_account_id  BIGINT NOT NULL REFERENCES bank_accounts(id),
  user_id          BIGINT NOT NULL REFERENCES users(id),
  role             bank_account_user_role NOT NULL DEFAULT 'viewer',
  granted_by       BIGINT NULL REFERENCES users(id),
  granted_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  revoked_at       TIMESTAMPTZ NULL,
  created_by       BIGINT NULL REFERENCES users(id),
  updated_by       BIGINT NULL REFERENCES users(id),
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at       TIMESTAMPTZ NULL,

  CONSTRAINT uq_bank_account_users_active
    UNIQUE (bank_account_id, user_id, role)
);

CREATE INDEX idx_bank_account_users_account ON bank_account_users (bank_account_id);
CREATE INDEX idx_bank_account_users_user    ON bank_account_users (user_id);

-- ============================================================================
-- TABLE: bank_transactions
-- ============================================================================
CREATE TABLE bank_transactions (
  id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                BIGINT NOT NULL REFERENCES companies(id),
  branch_id                 BIGINT NULL REFERENCES branches(id),
  bank_account_id            BIGINT NOT NULL REFERENCES bank_accounts(id),
  transaction_type          bank_transaction_type NOT NULL,
  status                    bank_transaction_status NOT NULL DEFAULT 'draft',
  amount                    NUMERIC(19,4) NOT NULL CHECK (amount > 0),
  currency_code             CHAR(3) NOT NULL,
  exchange_rate             NUMERIC(18,6) NOT NULL DEFAULT 1,
  base_amount               NUMERIC(19,4) NOT NULL,
  value_date                DATE NOT NULL,
  posted_date               DATE NULL,
  description               VARCHAR(500) NULL,
  bank_reference             VARCHAR(120) NULL,
  payee_payer_name          VARCHAR(200) NULL,
  payee_payer_iban          VARCHAR(34) NULL,
  counterparty_bank_account_id BIGINT NULL REFERENCES bank_accounts(id),
  transfer_id               BIGINT NULL REFERENCES transfers(id),
  payroll_run_id            BIGINT NULL REFERENCES payroll_runs(id),
  source_document_type      VARCHAR(60) NULL,
  source_document_id        BIGINT NULL,
  journal_entry_id          BIGINT NULL REFERENCES journal_entries(id),
  reconciliation_id         BIGINT NULL REFERENCES bank_reconciliations(id),
  reversed_transaction_id   BIGINT NULL REFERENCES bank_transactions(id),
  adjustment_reason         VARCHAR(500) NULL,
  approval_chain_status     approval_chain_status NOT NULL DEFAULT 'not_required',
  initiated_by              BIGINT NULL REFERENCES users(id),
  finance_manager_approved_by BIGINT NULL REFERENCES users(id),
  finance_manager_approved_at TIMESTAMPTZ NULL,
  ceo_approved_by            BIGINT NULL REFERENCES users(id),
  ceo_approved_at            TIMESTAMPTZ NULL,
  cost_center_id             BIGINT NULL REFERENCES cost_centers(id),
  project_id                 BIGINT NULL REFERENCES projects(id),
  department_id              BIGINT NULL REFERENCES departments(id),
  ai_generated                BOOLEAN NOT NULL DEFAULT FALSE,
  ai_confidence               NUMERIC(5,2) NULL,
  tags                        JSONB NOT NULL DEFAULT '[]',
  custom_fields                JSONB NOT NULL DEFAULT '{}',
  created_by                 BIGINT NULL REFERENCES users(id),
  updated_by                 BIGINT NULL REFERENCES users(id),
  created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                 TIMESTAMPTZ NULL,

  CONSTRAINT chk_bank_transactions_currency_format CHECK (currency_code ~ '^[A-Z]{3}$'),
  CONSTRAINT chk_bank_transactions_base_amount CHECK (base_amount > 0),
  CONSTRAINT chk_bank_transactions_cleared_needs_journal
    CHECK (status NOT IN ('cleared', 'reconciled') OR journal_entry_id IS NOT NULL)
);

CREATE INDEX idx_bank_transactions_company        ON bank_transactions (company_id);
CREATE INDEX idx_bank_transactions_account         ON bank_transactions (bank_account_id);
CREATE INDEX idx_bank_transactions_status          ON bank_transactions (status);
CREATE INDEX idx_bank_transactions_type            ON bank_transactions (transaction_type);
CREATE INDEX idx_bank_transactions_value_date       ON bank_transactions (value_date);
CREATE INDEX idx_bank_transactions_reference         ON bank_transactions (bank_reference);
CREATE INDEX idx_bank_transactions_transfer          ON bank_transactions (transfer_id);
CREATE INDEX idx_bank_transactions_journal_entry     ON bank_transactions (journal_entry_id);
CREATE INDEX idx_bank_transactions_reconciliation     ON bank_transactions (reconciliation_id);
CREATE INDEX idx_bank_transactions_source             ON bank_transactions (source_document_type, source_document_id);
CREATE INDEX idx_bank_transactions_unreconciled
  ON bank_transactions (bank_account_id, status) WHERE status = 'cleared';

-- ============================================================================
-- TABLE: bank_statement_lines  (immutable once imported; see Bank Reconciliation)
-- ============================================================================
CREATE TABLE bank_statement_lines (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  bank_account_id        BIGINT NOT NULL REFERENCES bank_accounts(id),
  statement_import_id     UUID NOT NULL,
  import_format           VARCHAR(20) NOT NULL, -- 'mt940' | 'camt053' | 'csv' | 'xlsx' | 'pdf' | 'api'
  line_number              INT NULL,
  value_date               DATE NOT NULL,
  booking_date              DATE NULL,
  amount                   NUMERIC(19,4) NOT NULL,
  direction                 VARCHAR(6) NOT NULL CHECK (direction IN ('credit', 'debit')),
  currency_code             CHAR(3) NOT NULL,
  running_balance            NUMERIC(19,4) NULL,
  bank_reference              VARCHAR(120) NULL,
  raw_description             VARCHAR(500) NULL,
  counterparty_name           VARCHAR(200) NULL,
  counterparty_iban            VARCHAR(34) NULL,
  status                       bank_statement_line_status NOT NULL DEFAULT 'unmatched',
  matched_bank_transaction_id  BIGINT NULL REFERENCES bank_transactions(id),
  match_method                 match_method NULL,
  match_confidence              NUMERIC(5,2) NULL,
  matched_by                    BIGINT NULL REFERENCES users(id),
  matched_at                    TIMESTAMPTZ NULL,
  ocr_field_confidence           JSONB NULL,
  period_start                   DATE NOT NULL,
  period_end                     DATE NOT NULL,
  created_by                     BIGINT NULL REFERENCES users(id),
  updated_by                     BIGINT NULL REFERENCES users(id),
  created_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                     TIMESTAMPTZ NULL,

  CONSTRAINT chk_bank_statement_lines_currency_format CHECK (currency_code ~ '^[A-Z]{3}$')
);

CREATE INDEX idx_bank_statement_lines_account       ON bank_statement_lines (bank_account_id);
CREATE INDEX idx_bank_statement_lines_import         ON bank_statement_lines (statement_import_id);
CREATE INDEX idx_bank_statement_lines_status          ON bank_statement_lines (status);
CREATE INDEX idx_bank_statement_lines_period           ON bank_statement_lines (period_start, period_end);
CREATE INDEX idx_bank_statement_lines_matched_txn        ON bank_statement_lines (matched_bank_transaction_id);
CREATE INDEX idx_bank_statement_lines_unmatched
  ON bank_statement_lines (bank_account_id) WHERE status = 'unmatched';

-- ============================================================================
-- TABLE: bank_reconciliations
-- ============================================================================
CREATE TABLE bank_reconciliations (
  id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id              BIGINT NOT NULL REFERENCES companies(id),
  bank_account_id           BIGINT NOT NULL REFERENCES bank_accounts(id),
  reconciliation_type       bank_reconciliation_type NOT NULL DEFAULT 'statement',
  period_start              DATE NOT NULL,
  period_end                DATE NOT NULL,
  bank_opening_balance       NUMERIC(19,4) NOT NULL,
  bank_closing_balance        NUMERIC(19,4) NOT NULL,
  book_balance_at_period_end   NUMERIC(19,4) NOT NULL,
  outstanding_deposits          NUMERIC(19,4) NOT NULL DEFAULT 0,
  outstanding_withdrawals        NUMERIC(19,4) NOT NULL DEFAULT 0,
  adjusted_bank_balance           NUMERIC(19,4) NOT NULL,
  variance                        NUMERIC(19,4) NOT NULL DEFAULT 0,
  status                          bank_reconciliation_status NOT NULL DEFAULT 'in_progress',
  matched_line_count                INT NOT NULL DEFAULT 0,
  unmatched_line_count               INT NOT NULL DEFAULT 0,
  closed_by                          BIGINT NULL REFERENCES users(id),
  closed_at                          TIMESTAMPTZ NULL,
  reopened_by                         BIGINT NULL REFERENCES users(id),
  reopened_at                         TIMESTAMPTZ NULL,
  reopen_reason                       VARCHAR(500) NULL,
  created_by                          BIGINT NULL REFERENCES users(id),
  updated_by                          BIGINT NULL REFERENCES users(id),
  created_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                          TIMESTAMPTZ NULL,

  CONSTRAINT uq_bank_reconciliations_period
    UNIQUE (bank_account_id, period_start, period_end, reconciliation_type)
);

CREATE INDEX idx_bank_reconciliations_account ON bank_reconciliations (bank_account_id);
CREATE INDEX idx_bank_reconciliations_status   ON bank_reconciliations (status);
CREATE INDEX idx_bank_reconciliations_period    ON bank_reconciliations (period_start, period_end);

-- Join table recording every rule that fired for a committed match (audit detail)
CREATE TABLE bank_reconciliation_matches (
  id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                BIGINT NOT NULL REFERENCES companies(id),
  bank_reconciliation_id     BIGINT NOT NULL REFERENCES bank_reconciliations(id),
  bank_statement_line_id      BIGINT NOT NULL REFERENCES bank_statement_lines(id),
  bank_transaction_id          BIGINT NOT NULL REFERENCES bank_transactions(id),
  rules_fired                   JSONB NOT NULL DEFAULT '[]',
  final_score                    NUMERIC(5,2) NOT NULL,
  match_method                    match_method NOT NULL,
  created_by                      BIGINT NULL REFERENCES users(id),
  created_at                      TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                      TIMESTAMPTZ NULL
);

CREATE INDEX idx_brm_reconciliation ON bank_reconciliation_matches (bank_reconciliation_id);
CREATE INDEX idx_brm_statement_line ON bank_reconciliation_matches (bank_statement_line_id);
CREATE INDEX idx_brm_transaction    ON bank_reconciliation_matches (bank_transaction_id);

-- ============================================================================
-- TABLE: transfers  (single record for a two-sided movement of money)
-- ============================================================================
CREATE TABLE transfers (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id              BIGINT NOT NULL REFERENCES companies(id),
  from_bank_account_id      BIGINT NOT NULL REFERENCES bank_accounts(id),
  to_bank_account_id         BIGINT NOT NULL REFERENCES bank_accounts(id),
  transfer_type              transfer_type NOT NULL DEFAULT 'internal',
  is_intercompany              BOOLEAN NOT NULL DEFAULT FALSE,
  from_company_id              BIGINT NULL REFERENCES companies(id),
  to_company_id                 BIGINT NULL REFERENCES companies(id),
  amount                          NUMERIC(19,4) NOT NULL CHECK (amount > 0),
  from_currency_code              CHAR(3) NOT NULL,
  to_currency_code                 CHAR(3) NOT NULL,
  exchange_rate                     NUMERIC(18,6) NOT NULL DEFAULT 1,
  value_date                         DATE NOT NULL,
  status                              bank_transaction_status NOT NULL DEFAULT 'draft',
  approval_chain_status                approval_chain_status NOT NULL DEFAULT 'pending_finance_manager',
  finance_manager_approved_by            BIGINT NULL REFERENCES users(id),
  finance_manager_approved_at             TIMESTAMPTZ NULL,
  ceo_approved_by                          BIGINT NULL REFERENCES users(id),
  ceo_approved_at                          TIMESTAMPTZ NULL,
  from_transaction_id                       BIGINT NULL REFERENCES bank_transactions(id),
  to_transaction_id                          BIGINT NULL REFERENCES bank_transactions(id),
  memo                                        VARCHAR(500) NULL,
  created_by                                  BIGINT NULL REFERENCES users(id),
  updated_by                                  BIGINT NULL REFERENCES users(id),
  created_at                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                  TIMESTAMPTZ NULL,

  CONSTRAINT chk_transfers_different_accounts CHECK (from_bank_account_id <> to_bank_account_id),
  CONSTRAINT chk_transfers_intercompany_companies
    CHECK (NOT is_intercompany OR (from_company_id IS NOT NULL AND to_company_id IS NOT NULL))
);

CREATE INDEX idx_transfers_from_account ON transfers (from_bank_account_id);
CREATE INDEX idx_transfers_to_account    ON transfers (to_bank_account_id);
CREATE INDEX idx_transfers_status         ON transfers (status);
CREATE INDEX idx_transfers_value_date      ON transfers (value_date);

-- ============================================================================
-- TABLE: standing_orders
-- ============================================================================
CREATE TABLE standing_orders (
  id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id               BIGINT NOT NULL REFERENCES companies(id),
  bank_account_id            BIGINT NOT NULL REFERENCES bank_accounts(id),
  name                        VARCHAR(160) NOT NULL,
  payee_name                   VARCHAR(200) NOT NULL,
  payee_iban                    VARCHAR(34) NULL,
  vendor_id                      BIGINT NULL REFERENCES vendors(id),
  transaction_type_on_execution   bank_transaction_type NOT NULL DEFAULT 'recurring_payment',
  amount                            NUMERIC(19,4) NULL,
  amount_formula                     VARCHAR(200) NULL,
  currency_code                       CHAR(3) NOT NULL,
  frequency                            standing_order_frequency NOT NULL,
  day_of_month                          SMALLINT NULL CHECK (day_of_month BETWEEN 1 AND 31),
  day_of_week                            SMALLINT NULL CHECK (day_of_week BETWEEN 0 AND 6),
  start_date                              DATE NOT NULL,
  end_date                                DATE NULL,
  max_occurrences                          INT NULL,
  occurrences_executed                      INT NOT NULL DEFAULT 0,
  next_run_date                              DATE NOT NULL,
  approved_envelope_amount                    NUMERIC(19,4) NULL,
  status                                       standing_order_status NOT NULL DEFAULT 'active',
  approval_chain_status                        approval_chain_status NOT NULL DEFAULT 'pending_finance_manager',
  finance_manager_approved_by                   BIGINT NULL REFERENCES users(id),
  finance_manager_approved_at                    TIMESTAMPTZ NULL,
  ceo_approved_by                                 BIGINT NULL REFERENCES users(id),
  ceo_approved_at                                 TIMESTAMPTZ NULL,
  created_by                                      BIGINT NULL REFERENCES users(id),
  updated_by                                      BIGINT NULL REFERENCES users(id),
  created_at                                      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                      TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                      TIMESTAMPTZ NULL,

  CONSTRAINT chk_standing_orders_amount_present
    CHECK (amount IS NOT NULL OR amount_formula IS NOT NULL),
  CONSTRAINT chk_standing_orders_currency_format CHECK (currency_code ~ '^[A-Z]{3}$')
);

CREATE INDEX idx_standing_orders_account       ON standing_orders (bank_account_id);
CREATE INDEX idx_standing_orders_status         ON standing_orders (status);
CREATE INDEX idx_standing_orders_next_run        ON standing_orders (next_run_date) WHERE status = 'active';

-- ============================================================================
-- HISTORY / VERSIONING
-- ============================================================================
-- Every mutation to the tables above additionally writes a row to the
-- foundation `audit_logs` table (auditable_type, auditable_id, actor_id,
-- action, old_values JSONB, new_values JSONB, reason, ip_address, device,
-- created_at). Financial-state transitions on bank_transactions and transfers
-- (draft -> pending_approval -> approved -> ... ) are additionally mirrored
-- into a dedicated append-only history table so state-machine timing survives
-- independent of the generic audit log's retention policy:

CREATE TABLE bank_transaction_status_history (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  bank_transaction_id      BIGINT NOT NULL REFERENCES bank_transactions(id),
  from_status               bank_transaction_status NULL,
  to_status                  bank_transaction_status NOT NULL,
  changed_by                  BIGINT NULL REFERENCES users(id),
  reason                        VARCHAR(500) NULL,
  changed_at                    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_bths_transaction ON bank_transaction_status_history (bank_transaction_id);
CREATE INDEX idx_bths_changed_at   ON bank_transaction_status_history (changed_at);
```

**Relationships summary.**

- `bank_accounts` 1—N `bank_transactions`, `bank_statement_lines`, `bank_reconciliations`,
  `standing_orders`, `bank_account_users`.
- `bank_accounts` 1—N `bank_accounts` via `parent_bank_account_id` (virtual accounts) and
  `settlement_account_id` (credit cards).
- `transfers` 1—1 `bank_transactions` (×2, `from_transaction_id`/`to_transaction_id`) — a transfer is
  the aggregate root; its two transaction legs are children, never created independently.
- `bank_reconciliations` 1—N `bank_statement_lines` (by `period_start`/`period_end` + `bank_account_id`,
  not a direct FK, since lines are imported before a reconciliation row necessarily exists) and 1—N
  `bank_reconciliation_matches`.
- `bank_transactions` N—1 `journal_entries` — every cleared transaction points to exactly one balanced
  entry; `journal_entries` has no reverse pointer (an entry could in principle be referenced by exactly
  one source row across the whole platform, enforced by `source_type`+`source_id` uniqueness at the
  Accounting Engine level, not repeated here).
- `standing_orders` 1—N `bank_transactions` (each execution creates one transaction row referencing the
  standing order via `source_document_type = 'standing_orders'`, `source_document_id`).

# API

All endpoints are under `/api/v1/banking/`, require a Sanctum/JWT bearer token, and are scoped to the
active company via the `X-Company-Id` header. Every response uses the platform-standard envelope
(`success`, `data`, `message`, `errors`, `meta.pagination`, `request_id`, `timestamp`). Pagination
defaults to 25 per page and supports cursor pagination via `?cursor=`. List endpoints support
`?filter[field]=value`, `?sort=-created_at`, and `?search=`.

**Bank Accounts**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/banking/bank-accounts` | `bank.read` | List bank accounts for the active company, filterable by `status`, `account_type`, `institution_type`, `currency_code` |
| POST | `/banking/bank-accounts` | `bank.account.create` | Create a bank account (`status` starts at `pending_verification`) |
| GET | `/banking/bank-accounts/{id}` | `bank.read` | Retrieve one bank account with current balances |
| PATCH | `/banking/bank-accounts/{id}` | `bank.account.update` | Update non-financial fields (name, authorized users list changes go through a dedicated endpoint) |
| POST | `/banking/bank-accounts/{id}/verify` | `bank.account.verify` | Move `pending_verification -> active` after micro-deposit/consent/document verification |
| POST | `/banking/bank-accounts/{id}/freeze` | `bank.account.freeze` | Set `status = frozen` with a required reason |
| POST | `/banking/bank-accounts/{id}/close` | `bank.account.close` | Close an account; requires `current_balance = 0` |
| GET | `/banking/bank-accounts/{id}/balance` | `bank.read` | Live balance snapshot: current, available, frozen |
| POST | `/banking/bank-accounts/{id}/users` | `bank.account.manage_users` | Grant a role to a user on this account |
| DELETE | `/banking/bank-accounts/{id}/users/{userId}` | `bank.account.manage_users` | Revoke a user's role on this account |
| POST | `/banking/bank-accounts/{id}/open-banking/connect` | `bank.account.manage` | Initiate an Open Banking consent flow with the institution |
| POST | `/banking/bank-accounts/{id}/open-banking/sync` | `bank.account.manage` | Trigger an on-demand Open Banking sync (also runs on a schedule) |

**Bank Transactions**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/banking/bank-transactions` | `bank.read` | List transactions, filterable by `bank_account_id`, `transaction_type`, `status`, date range |
| POST | `/banking/bank-transactions` | `bank.transaction.create` | Create a draft transaction |
| GET | `/banking/bank-transactions/{id}` | `bank.read` | Retrieve one transaction with its journal entry and reconciliation status |
| PATCH | `/banking/bank-transactions/{id}` | `bank.transaction.update` | Update a `draft` transaction only |
| POST | `/banking/bank-transactions/{id}/submit` | `bank.transaction.submit` | Move `draft -> pending_approval` (or straight to `approved` if under the auto-approval threshold and not a sensitive type) |
| POST | `/banking/bank-transactions/{id}/approve` | `bank.payment.approve` | Finance Manager approval step |
| POST | `/banking/bank-transactions/{id}/approve-final` | `bank.payment.approve.final` | CEO final approval step |
| POST | `/banking/bank-transactions/{id}/reject` | `bank.payment.approve` \| `bank.payment.approve.final` | Reject at either chain step, with a required reason |
| POST | `/banking/bank-transactions/{id}/void` | `bank.transaction.void` | Void a non-cleared transaction |
| POST | `/banking/bank-transactions/{id}/reverse` | `bank.transaction.reverse` | Create a reversing transaction against a cleared/reconciled one |

**Transfers**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/banking/transfers` | `bank.read` | List transfers |
| POST | `/banking/transfers` | `bank.transfer` | Create a transfer between two bank accounts; always starts `pending_finance_manager` |
| POST | `/banking/transfers/{id}/approve` | `bank.payment.approve` | Finance Manager approval |
| POST | `/banking/transfers/{id}/approve-final` | `bank.payment.approve.final` | CEO final approval — triggers dispatch |

**Standing Orders**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/banking/standing-orders` | `bank.read` | List standing orders |
| POST | `/banking/standing-orders` | `bank.standing_order.create` | Create; requires full approval chain before `status = active` |
| PATCH | `/banking/standing-orders/{id}` | `bank.standing_order.update` | Amount/schedule changes above the approved envelope re-trigger the chain |
| POST | `/banking/standing-orders/{id}/pause` | `bank.standing_order.update` | Pause future executions |
| DELETE | `/banking/standing-orders/{id}` | `bank.standing_order.cancel` | Cancel (soft-delete) |

**Reconciliation & Statement Import**

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/banking/statement-imports` | `bank.reconcile` | Upload a statement file (`multipart/form-data`, `format` field) |
| GET | `/banking/statement-imports/{id}` | `bank.reconcile` | Import status, parsed line count, OCR confidence summary |
| GET | `/banking/bank-accounts/{id}/reconciliation/unmatched` | `bank.reconcile` | Workbench: unmatched statement lines + candidate matches |
| POST | `/banking/reconciliation-matches` | `bank.reconcile` | Commit a manual or accepted-AI-suggested match |
| DELETE | `/banking/reconciliation-matches/{id}` | `bank.reconcile` | Unmatch (only within an open reconciliation period) |
| GET | `/banking/bank-accounts/{id}/reconciliations` | `bank.read` | List reconciliation periods and their status |
| POST | `/banking/reconciliations/{id}/close` | `bank.reconcile.close` | Close a `balanced` (or explicitly accepted) period |
| POST | `/banking/reconciliations/{id}/reopen` | `bank.reconcile.reopen` | Reopen a closed period, reason required |

**Cash Management & Forecast**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/banking/cash-position` | `bank.read` | Live aggregated cash position across all accounts |
| GET | `/banking/cash-flow-forecast` | `bank.read` | 13-week rolling forecast with confidence bands |
| GET | `/banking/liquidity` | `treasury.read` | Liquidity ratio and its inputs |
| GET | `/banking/currency-exposure` | `treasury.read` | Net exposure table by currency |

**Webhooks fired by this module:** `bank.transaction.created`, `bank.transaction.cleared`,
`bank.transaction.approved`, `bank.transaction.rejected`, `bank.transfer.completed`,
`bank.reconciliation.closed`, `bank.statement.imported`, `bank.fraud_flag.raised`,
`bank.liquidity_ratio.breached`.

**Example — create an outgoing vendor payment (draft):**

Request:
```json
POST /api/v1/banking/bank-transactions
{
  "bank_account_id": 12,
  "transaction_type": "outgoing_payment",
  "amount": "4250.0000",
  "currency_code": "KWD",
  "value_date": "2026-07-20",
  "description": "Payment — Bill BILL-2026-00891",
  "payee_payer_name": "Gulf Office Supplies W.L.L.",
  "payee_payer_iban": "KW81CBKU0000000000001234560101",
  "source_document_type": "bills",
  "source_document_id": 891
}
```

Response (201):
```json
{
  "success": true,
  "data": {
    "id": 90344,
    "company_id": 7,
    "bank_account_id": 12,
    "transaction_type": "outgoing_payment",
    "status": "draft",
    "amount": "4250.0000",
    "currency_code": "KWD",
    "base_amount": "4250.0000",
    "value_date": "2026-07-20",
    "approval_chain_status": "not_required",
    "created_at": "2026-07-16T09:12:04Z"
  },
  "message": "Bank transaction created as draft.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8f2a1e3c-9e7b-4b7f-8c31-1a6c5d0f9b21",
  "timestamp": "2026-07-16T09:12:04Z"
}
```

**Example — submit for approval, fraud check runs inline:**

Request:
```json
POST /api/v1/banking/bank-transactions/90344/submit
```

Response (200):
```json
{
  "success": true,
  "data": {
    "id": 90344,
    "status": "pending_approval",
    "approval_chain_status": "pending_finance_manager",
    "fraud_check": {
      "risk_score": 22,
      "hold_recommended": false,
      "reasons": []
    }
  },
  "message": "Transaction submitted for Finance Manager approval.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3d4e9a10-2b6f-4a91-9c2e-77f8a0c1d4e5",
  "timestamp": "2026-07-16T09:13:02Z"
}
```

**Example — Finance Manager approval, elevated fraud flag path:**

Response when `hold_recommended = true` and the approver has not yet acknowledged:
```json
{
  "success": false,
  "data": null,
  "message": "Fraud review acknowledgment required before approval.",
  "errors": [
    {
      "code": "FRAUD_HOLD_ACK_REQUIRED",
      "detail": "Risk score 84: new payee, unusual amount for payee. Provide acknowledgment_reason to proceed.",
      "field": "acknowledgment_reason"
    }
  ],
  "meta": { "pagination": null },
  "request_id": "5b6c7d8e-1f2a-4b3c-9d4e-5f6a7b8c9d0e",
  "timestamp": "2026-07-16T09:14:11Z"
}
```

**Example — creating a transfer between own accounts:**

Request:
```json
POST /api/v1/banking/transfers
{
  "from_bank_account_id": 12,
  "to_bank_account_id": 18,
  "amount": "10000.0000",
  "from_currency_code": "KWD",
  "to_currency_code": "USD",
  "value_date": "2026-07-17",
  "memo": "Fund USD investment account"
}
```

Response (201):
```json
{
  "success": true,
  "data": {
    "id": 5521,
    "transfer_type": "internal",
    "status": "draft",
    "approval_chain_status": "pending_finance_manager",
    "amount": "10000.0000",
    "exchange_rate": "0.325000",
    "converted_amount": "30769.2308",
    "from_bank_account_id": 12,
    "to_bank_account_id": 18
  },
  "message": "Transfer created; awaiting Finance Manager approval.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9c8b7a6d-5e4f-3a2b-1c0d-9e8f7a6b5c4d",
  "timestamp": "2026-07-16T09:20:44Z"
}
```

**Example — statement import status:**

Response:
```json
{
  "success": true,
  "data": {
    "statement_import_id": "e3b0c442-98fc-4e1c-8b7a-2f9d4a1c3e5f",
    "bank_account_id": 12,
    "format": "camt053",
    "period_start": "2026-07-01",
    "period_end": "2026-07-15",
    "opening_balance": "182430.5000",
    "closing_balance": "196880.2500",
    "line_count": 47,
    "auto_matched": 44,
    "unmatched": 3,
    "status": "completed"
  },
  "message": "Statement import completed.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
  "timestamp": "2026-07-16T09:00:00Z"
}
```

**Error example — invalid IBAN on account creation:**
```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    { "code": "VALIDATION_ERROR", "field": "iban", "detail": "IBAN failed mod-97 checksum validation." }
  ],
  "meta": { "pagination": null },
  "request_id": "7f6e5d4c-3b2a-1908-7654-3210fedcba98",
  "timestamp": "2026-07-16T09:02:17Z"
}
```

**Open Banking integration.** Where a bank exposes an Open Banking API under the CBK framework or an
equivalent regional regime, QAYD's `open-banking/connect` endpoint redirects the user through the
bank's consent screen (OAuth2-based), stores the resulting `open_banking_consent_id` and a refresh
token (encrypted — see **Security**), and the scheduled sync job pulls new statement lines via CAMT.053
or the bank's native JSON feed on a per-bank polling interval (default 4 hours; instant where the bank
supports webhook push). Consents expire per the bank's policy (commonly 90 days) and QAYD proactively
notifies the `treasury_admin` 7 days before expiry to re-consent.

# Reports

| Report | Purpose | Key Fields / Grouping | Refresh |
|---|---|---|---|
| **Cash Position** | Live snapshot of every account's balance | `bank_account_id`, `bank_name`, `account_type`, `currency_code`, `current_balance`, `available_balance`, `frozen_balance`, `current_balance_base`; grouped by currency and by institution type | Real-time |
| **Bank Reconciliation Report** | Per-account, per-period reconciliation summary | `bank_account_id`, `period_start/end`, `bank_closing_balance`, `book_balance_at_period_end`, `variance`, `matched_line_count`, `unmatched_line_count`, `status` | On reconciliation close, viewable live for open periods |
| **Cash Flow Statement** | Direct-method operating/investing/financing cash flow, actual | Grouped by activity category, by month/quarter/year, comparative to prior period | Daily |
| **Cash Flow Forecast Dashboard** | 13-week rolling projection with confidence bands | Weekly buckets, category breakdown (invoices due, bills due, payroll, tax, standing orders, other), confidence band | Recomputed on every relevant new record (invoice, bill, payroll run, tax return, transaction) |
| **Payment History** | All executed payments in a period | `bank_transactions` of outgoing types, filterable by vendor/employee/customer, `status`, `value_date`, approval trail (`initiated_by`, `finance_manager_approved_by`, `ceo_approved_by`) | Real-time |
| **Outstanding Payments** | Approved-but-not-yet-cleared payments, and scheduled future payments | `status IN ('approved','scheduled','submitted','pending_clearance')`, grouped by `value_date` and account, with running impact on `available_balance` | Real-time |
| **Bank Charges Report** | All `fee` transactions in a period, by account and by fee sub-type (maintenance, wire, card) | `transaction_type = 'fee'`, grouped by `bank_account_id`, month | Monthly, on demand |
| **FX Report** | Realized and unrealized FX gain/loss by currency and period | Split by realized (from settlements) vs. unrealized (from revaluation), by currency pair | Monthly, on demand |
| **Liquidity Dashboard** | Operating vs. near-cash vs. restricted cash, liquidity ratio trend | See **Cash Management** formula; trend line over trailing 13 weeks | Real-time |
| **Treasury Dashboard** | Facility utilization, currency exposure, investment maturities, standing-order commitments | Aggregates from `bank_accounts` (loan/investment types), `transfers`, `standing_orders`, **Multi Currency** exposure table | Real-time |

Every report is exportable as CSV/XLSX/PDF via the platform's generic `report_definitions` /
`report_runs` mechanism (owned by the Reports module) and can be scheduled (`report_schedules`) to
email a Finance Manager, CFO, or Treasury role on a recurring cadence (e.g. the Liquidity Dashboard
every Monday morning).

# Permissions

| Permission Key | Description |
|---|---|
| `bank.read` | View bank accounts, transactions, balances, and reports (read-only) |
| `bank.account.create` | Onboard a new bank account |
| `bank.account.update` | Edit non-financial account fields |
| `bank.account.verify` | Complete account verification (`pending_verification -> active`) |
| `bank.account.freeze` | Freeze an account |
| `bank.account.close` | Close a zero-balance account |
| `bank.account.manage_users` | Grant/revoke authorized-user roles on an account |
| `bank.account.manage` | Manage Open Banking connections/sync |
| `bank.transaction.create` | Create draft transactions |
| `bank.transaction.update` | Edit a draft transaction |
| `bank.transaction.submit` | Submit a draft into the approval chain |
| `bank.transaction.void` | Void a non-cleared transaction |
| `bank.transaction.reverse` | Create a reversing entry against a cleared/reconciled transaction |
| `bank.payment.approve` | Finance Manager approval step (first key) |
| `bank.payment.approve.final` | CEO final approval step (second key) |
| `bank.transfer` | Create a transfer between bank accounts |
| `bank.standing_order.create` | Create a standing order/recurring payment schedule |
| `bank.standing_order.update` | Modify an existing standing order |
| `bank.standing_order.cancel` | Cancel a standing order |
| `bank.reconcile` | Import statements, view/act on the reconciliation workbench |
| `bank.reconcile.close` | Close a reconciliation period |
| `bank.reconcile.reopen` | Reopen a closed reconciliation period |
| `treasury.read` | View Liquidity Dashboard, Treasury Dashboard, currency exposure |
| `treasury.manage` | Configure liquidity thresholds, forecast parameters, exposure hedge notes |

**Role assignment table:**

| Role | bank.read | account.create/update | verify/freeze/close | manage_users | txn.create/update/submit | payment.approve | payment.approve.final | bank.transfer | standing_order.* | reconcile / close / reopen | treasury.read/manage |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Owner | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes / Yes / Yes | Yes / Yes |
| CEO | Yes | Yes | Yes | Yes | Yes | Yes | **Yes (only role with `.final`)** | Yes | Yes | Yes / Yes / Yes | Yes / Yes |
| CFO | Yes | Yes | Yes | Yes | Yes | Yes | No | Yes | Yes | Yes / Yes / Yes | Yes / Yes |
| Finance Manager | Yes | Yes | Yes | Yes | Yes | **Yes (first key)** | No | Initiates only | Yes | Yes / Yes / Yes | Yes / Yes |
| Treasury | Yes | Yes | No | No | Yes | No | No | Initiates only | Yes | Yes / No / No | Yes / Yes |
| Senior Accountant | Yes | No | No | No | Yes | No | No | No | No | Yes / No / No | Yes / No |
| Accountant | Yes | No | No | No | Yes (create/update only) | No | No | No | No | Yes (matching only) / No / No | Yes / No |
| Auditor / External Auditor | Yes | No | No | No | No | No | No | No | No | Yes (read-only view) / No / No | Yes / No |
| Read Only | Yes | No | No | No | No | No | No | No | No | No / No / No | Yes / No |
| AI Agent | Yes (own scoped read) | No | No | No | Suggest-only (creates draft `ai_generated=true` rows a human must submit) | **No** | **No** | **No** | Suggest-only draft | Suggest-only (match candidates) / No / No | Read-only analysis |

The AI Agent row is intentionally the most restricted row in the table: it is structurally incapable
of calling `bank.transfer`, `bank.payment.approve`, or `bank.payment.approve.final` — those routes
check the authenticated principal's type in addition to its permission grant, and the AI service
account's principal type is never eligible for those three permissions regardless of what is granted
to it in the `roles`/`permissions` tables. This is the concrete enforcement of the platform rule that
sensitive banking operations are never AI-only.

# Notifications

| Event | Recipients | Channel | Notes |
|---|---|---|---|
| `bank.transaction.pending_approval` | Finance Manager(s) with access to the account | In-app, email | Includes fraud-check summary |
| `bank.transaction.approved` (awaiting final) | CEO/delegate | In-app, email, push | |
| `bank.transaction.rejected` | Initiator | In-app, email | Includes rejection reason |
| `bank.transaction.cleared` | Initiator, Finance Manager | In-app | |
| `bank.transaction.failed` | Initiator, Finance Manager | In-app, email | Includes bank/rail failure reason |
| `bank.reconciliation.discrepancy_detected` | Finance Manager, Senior Accountant | In-app, email | Fires when a period closes matching but a real variance persists after all auto/manual matching |
| `bank.reconciliation.closed` | Finance Manager, Auditor | In-app | |
| `bank.statement.import_failed` | Finance Manager | In-app, email | Includes parse/OCR error detail |
| `bank.open_banking.consent_expiring` | Treasury Admin | Email | 7 days before expiry |
| `bank.fraud_flag.raised` (risk_score >= 80) | Auditor, CFO, approving Finance Manager | In-app, email, push | Cannot be silenced; always delivered regardless of user notification preferences |
| `bank.liquidity_ratio.breached` | Finance Manager, CFO, CEO | In-app, email | Includes the full ratio formula inputs |
| `bank.standing_order.envelope_exceeded` | Finance Manager | In-app, email | An execution amount exceeded the originally approved envelope and is held for re-approval |
| `bank.account.frozen` | All authorized users on the account | In-app, email | |
| `bank.duplicate_suspected` | Creating user, Finance Manager | In-app, blocking modal | Requires explicit override with reason |

# Security

**Encryption.** All `bank_accounts` sensitive fields (`iban`, `account_number`, Open Banking refresh
tokens, any stored payee bank details on `bank_transactions`) are encrypted at rest using
application-level envelope encryption (AES-256-GCM, per-tenant data encryption keys wrapped by a master
key held in the platform's secrets manager), in addition to PostgreSQL's own disk-level encryption.
Encrypted columns are never included in plain form in application logs; the Laravel logging channel
configuration explicitly redacts `iban`, `account_number`, `swift_bic`, and `payee_payer_iban` from any
exception context.

**Bank Token Storage.** Open Banking OAuth2 access/refresh tokens (`open_banking_consent_id` and its
associated token pair, stored in a dedicated `bank_oauth_tokens` table, not inlined on `bank_accounts`)
are encrypted with a key that is distinct from the general application data-encryption key and rotated
independently. Tokens are never returned by any API response, including to a `treasury_admin` — the
`/open-banking/connect` and `/open-banking/sync` endpoints operate on the tokens server-side only.

**Secrets Management.** Provider API keys (FX rate feed, Document AI/OCR vendor if externally hosted,
payment-provider settlement API credentials) are stored in the platform's central secrets manager and
injected only at runtime by the Laravel backend process; the FastAPI AI layer never receives raw banking
secrets — it receives only the specific, already-authorized data it needs for a given analysis call,
scoped by the requesting user's session.

**Audit Logging.** Every state change on `bank_accounts`, `bank_transactions`, `transfers`, and
`standing_orders` writes to `audit_logs` with the acting principal (human user or the AI system
account, distinguishable by `actor_type`), old/new values, IP address, device fingerprint, and — for
approval-chain actions — the specific chain step satisfied. `bank_transaction_status_history` provides
a lighter-weight, append-only timeline optimized for fast timeline rendering in the UI without querying
the generic audit log.

**Approval Workflow (security dimension).** The two-key rule is enforced at the database-transaction
level, not merely in application logic: the `finance_manager_approved_by` and `ceo_approved_by` columns
on `bank_transactions`/`transfers`/`standing_orders` have an application-level uniqueness constraint
(enforced in the `ApprovalService`) that the two values, when both present, can never be equal, and
`approve-final` is rejected outright with a `409 Conflict` if `finance_manager_approved_by = auth()->id()`.
Session tokens used to call `approve-final` are additionally checked for step-up authentication (a
fresh MFA challenge within the last 15 minutes) when the amount exceeds a company-configured
high-value threshold (default KWD 25,000 or equivalent), independent of the permission check.

**Additional controls:** rate limiting on all mutating banking endpoints (stricter than the platform
default on `bank.transfer` and `bank.payment.approve*`); IP allow-listing optionally configurable per
company for `treasury_admin` sessions; a mandatory cooling-off period (configurable, default 4 hours)
between a payee's bank details being added/changed and that payee becoming eligible for a payment
above the auto-approval threshold, specifically to blunt a same-session "add attacker IBAN, immediately
pay it" attack pattern.

# Performance

- **Balance maintenance** is incremental, not recomputed from full history on every read:
  `bank_accounts.current_balance` updates in the same database transaction that moves a
  `bank_transactions` row to `cleared`, guarded by a `SELECT ... FOR UPDATE` row lock on the
  `bank_accounts` row to prevent lost updates under concurrent clearing. A nightly reconciliation job
  recomputes the balance from `opening_balance + Σ transactions` as an integrity check and raises an
  alert (not a silent auto-correct) if it disagrees with the incrementally maintained value.
- **Indexing strategy** favors the query patterns the module actually runs: account-scoped transaction
  listing (`idx_bank_transactions_account`), unreconciled-transaction lookups for the matching engine
  (`idx_bank_transactions_unreconciled`, a partial index on `status = 'cleared'`), and unmatched
  statement-line lookups (`idx_bank_statement_lines_unmatched`, partial on `status = 'unmatched'`) —
  partial indexes keep these hot paths small even as historical `bank_transactions` volume grows into
  the millions of rows per large tenant.
- **Reconciliation matching** runs as a queued job (Laravel queue, Redis-backed) rather than inline on
  the import request, so a large statement import (thousands of lines from a busy payment-provider
  account) returns immediately with `status: processing` and the client polls
  `GET /banking/statement-imports/{id}` or receives the `bank.statement.imported` webhook on
  completion.
- **Cash Position and Liquidity Dashboard** reads are served from a materialized aggregate
  (`bank_account_balances_mv`, refreshed on every balance-changing transaction via a lightweight trigger
  rather than a full materialized-view `REFRESH`, to keep the dashboard real-time without expensive
  full recomputation) rather than aggregating `bank_accounts` on every dashboard load.
- **Cash Flow Forecast** generation is computationally heavier (it touches `invoices`, `bills`,
  `payroll_runs`, `tax_returns`, and `standing_orders` in addition to `bank_transactions`), so it is
  computed asynchronously by the Forecast Agent on a schedule (every 4 hours, plus on-demand
  regeneration capped at once per 10 minutes per company to prevent abuse) and cached; the API serves
  the cached forecast with a `generated_at` timestamp rather than recomputing per request.
- **Statement line uniqueness** across re-imports of an overlapping period is checked via a hash of
  `(bank_account_id, value_date, amount, direction, bank_reference, raw_description)` computed on
  ingestion, indexed, and checked before insert, so re-importing an already-imported period is fast to
  detect and does not require an O(n²) comparison against every existing line.
- **Multi-currency conversion** at read time (displaying any historical balance in a currency other
  than the one it was booked in) is never computed on the fly from a live rate — every
  `bank_transactions` row already stores its `base_amount` at the rate in effect when it cleared,
  so historical reports never depend on the current day's exchange rate and remain performant and
  stable regardless of `exchange_rates` table growth.
- **Partitioning.** `bank_transactions` and `bank_statement_lines` are range-partitioned by
  `value_date` (monthly partitions) for tenants above a configurable row-count threshold (default 5
  million rows), keeping the hot recent-months partitions small for both the matching engine and
  dashboard queries while historical partitions remain queryable for reporting without degrading
  current-period performance.

# Edge Cases

| Edge Case | Handling |
|---|---|
| A statement line arrives before the corresponding `bank_transactions` row exists in QAYD (e.g. a bank fee QAYD never initiated) | Matching engine finds no candidate; if the description matches a known fee/interest pattern it auto-creates the transaction directly from the statement line (see **Bank Transactions**); otherwise it is queued in the manual workbench for a user to create the appropriate transaction. |
| A `bank_transactions` row is approved and scheduled, but the bank rejects it at execution (invalid IBAN, insufficient funds at the bank's side even though QAYD's `available_balance` showed sufficient funds — e.g. a hold the bank applied that QAYD wasn't aware of) | Moves to `failed` with the rail's rejection reason; `available_balance` is recalculated to release the hold; a notification fires to the initiator and Finance Manager; the transaction can be `retried` (creates a new draft) or `cancelled`. |
| Two reconciliation matches are proposed for the same statement line by two different rule evaluations (e.g. exact reference match AND a manual match attempt racing) | The commit is wrapped in a `SELECT ... FOR UPDATE` on the `bank_statement_lines` row; the losing writer receives a `409 Conflict` with `ALREADY_MATCHED` and the client refreshes the workbench view. |
| A company closes a reconciliation period, then later needs to record a transaction dated within that closed period (a late-arriving vendor bill payment discovered a month later) | The transaction can still be created with a `value_date` inside a closed period, but it cannot be matched into the closed `bank_reconciliations` row — it becomes an unmatched line in the *next open* period's reconciliation, exactly like a real-world "prior period adjustment," and is flagged `crosses_closed_period = true` for the accountant's attention. |
| An FX rate is unavailable for a required currency pair on a given date (provider outage, unlisted exotic currency) | The transaction is blocked from moving past `draft` with a `RATE_UNAVAILABLE` validation error until a Finance Manager manually supplies a rate (audit-logged as `rate_source = 'manual_override'`); the system never silently falls back to a stale rate without flagging it as such (`rate_is_stale = true`, with the staleness age shown). |
| A virtual account's parent account is closed while the virtual account still has authorized users/activity | Closing a parent with active (non-closed) child virtual accounts is blocked (`409 PARENT_HAS_ACTIVE_CHILDREN`); all virtual children must be closed or reparented first. |
| An approver tries to approve their own initiated payment (e.g. a Finance Manager who is also configured as a `treasury_admin` and created the draft) | Blocked at the service layer regardless of the user's permission grants — `initiated_by = auth()->id()` on a transaction disqualifies that same user from either approval step on that transaction. |
| A standing order's `amount_formula` evaluates to a value materially different from `approved_envelope_amount` on a given execution (e.g. a percentage-of-budget formula where the budget changed) | The materialized execution is held in `pending_approval` rather than auto-approved, even though the schedule itself was pre-approved, whenever the computed amount exceeds the envelope by more than a configurable tolerance (default 5%). |
| A payment-provider settlement batch (`pos_settlement`) arrives net of a fee QAYD cannot itemize (the provider reports only a lump net figure) | The transaction posts with the net amount to Bank and a single estimated fee line to Merchant Fees Expense based on the provider's published rate card; when the provider's own itemized statement later becomes available (via a separate reconciliation pass), any variance between the estimate and the actual fee posts as a small `adjustment`. |
| A wire transfer is submitted in a currency the destination bank cannot receive directly (requires an intermediary correspondent bank) | The rail integration returns a `CORRESPONDENT_REQUIRED` response before submission is finalized; QAYD surfaces this to the initiator with the option to select a known correspondent path (where the bank integration exposes one) or route through a currency conversion first. |
| Duplicate statement import — the same period is uploaded twice (user error, or an Open Banking sync overlapping a manual CSV upload) | The ingestion hash check (see **Performance**) detects the overlap; lines already present are skipped and reported in the import summary as `skipped_duplicate_count`, not re-inserted, so matching state is never disturbed by a re-import. |
| A company operates in a fiscal year that doesn't align with the calendar year, and a reconciliation period spans a fiscal-year boundary | Reconciliation periods are always defined by actual bank statement cycle dates, independent of `fiscal_periods`; the resulting journal entries still post into whichever `fiscal_periods` row contains each entry's `entry_date`, so Banking's period boundaries and Accounting's fiscal boundaries can differ without conflict. |
| An employee's payroll payment fails (wrong IBAN on file) after the payroll batch's overall approval already completed | Only that one employee's `bank_transactions` row moves to `failed`; the rest of the batch proceeds to `cleared` independently — a single failure never blocks or reverses the other transactions in the same `payroll_run_id` batch. |

# Compliance

**PSD2 / Open Banking equivalents.** While PSD2 itself is an EU regime, QAYD's Open Banking integration
is built to the same architectural pattern it standardized (strong customer authentication via OAuth2,
explicit time-bound consent, no storage of the customer's actual bank login credentials — QAYD never
sees or stores a bank username/password, only the delegated API token) so that the same integration
layer extends cleanly to any GCC market that adopts an equivalent framework, including Kuwait's
evolving Open Banking initiatives under the Central Bank of Kuwait and similar regimes in Saudi Arabia
(SAMA Open Banking Framework) and the UAE (CBUAE Open Finance).

**PCI (Payment Card Industry).** QAYD's Banking module does not store raw cardholder data (full card
number, CVV, expiry) at any point — corporate card transactions arrive as already-tokenized or
already-settled line items from the card issuer/network via statement import or a settlement API,
never as raw card data captured by QAYD itself. This keeps the module's PCI scope to SAQ-A-equivalent
(no cardholder data environment) rather than requiring full PCI-DSS Level 1 compliance, which would
apply only if QAYD ever directly captured card numbers (it does not, by design, in this module).

**Regional Banking Standards.**

| Standard / Body | Relevance to Banking & Treasury |
|---|---|
| CBK (Central Bank of Kuwait) regulations | IBAN format validation (Kuwait IBANs: `KW` + 2 check digits + 4-char bank code + 22 alphanumeric), local clearing rail integration, KYC requirements on account onboarding |
| SAMA (Saudi Central Bank) | AED/SAR account handling for companies with Saudi banking relationships; SAMA Open Banking Framework compatibility |
| CBUAE (Central Bank of the UAE) | UAE IBAN validation, CBUAE Open Finance framework compatibility |
| AAOIFI Shari'ah standards | Governs the `is_shariah_compliant` account behavior — no interest fields, profit-distribution terminology, and (at the product-configuration level, outside this module's direct scope) ensuring any linked financing facility follows an AAOIFI-compliant structure |
| AML/CFT (Anti-Money-Laundering / Counter-Terrorist-Financing) regional requirements | Enforced primarily through the Fraud Detection agent's flags, the mandatory cooling-off period on new payees, and the requirement that every `bank_accounts` row pass identity verification before `status = active`; large or unusual transactions surfaced to the Auditor role rather than QAYD independently filing regulatory reports, which remains a human/compliance-officer responsibility outside the platform |
| ISO 20022 (CAMT.053) / ISO 15022 (MT940) | Native statement-import format support, described in **Bank Reconciliation** |
| ISO 13616 (IBAN) / ISO 9362 (SWIFT/BIC) | Structural validation constraints enforced at the database (`chk_bank_accounts_iban_format`, `chk_bank_accounts_swift_format`) and application layer (full mod-97 checksum validation in the `BankAccountService`) |

QAYD's compliance posture in this module is deliberately narrow and precise: it enforces the technical
and data-structure requirements (validation, encryption, consent handling, audit trails, approval
chains) that regulatory regimes require of a financial software platform, while explicitly leaving
regulatory *filing* and *legal interpretation* responsibilities with the company's own compliance
function — QAYD equips the Auditor and Compliance roles with the data and flags they need, it does not
itself act as the regulated entity.

# Future Improvements

- **Native FX hedging instruments.** Model forward contracts, currency swaps, and options directly as
  first-class records (a `fx_hedges` table linked to `transfers`/exposure) so the Currency Exposure
  Table's "Hedged" column becomes computed rather than a manual annotation field.
- **Real-time payment rail webhooks for every institution type.** Today, settlement confirmation
  latency varies by rail and by whether a given bank has modern webhook support; a unified
  confirmation-latency SLA dashboard per institution would help Treasury plan cutoff times more
  precisely.
- **Multi-bank cash pooling / notional pooling.** Support a company-level "virtual" consolidated
  balance across multiple physical accounts at the same banking group, with automatic interest
  optimization sweeps, beyond the current explicit `transfers`-based sweeping.
- **Predictive fraud model retraining loop.** Formalize a feedback loop where every
  accept/override/reject decision on a Fraud Detection flag feeds back into the model's training set
  with an explicit human-labeled outcome, closing the loop between AI suggestion and ground truth.
- **Automated regulatory filing assistance.** Extend the Compliance Agent (platform-wide) to draft (never
  submit) large-transaction or suspicious-activity report content pre-filled from `bank_transactions`
  and Fraud Detection data, for the company's compliance officer to review and file through the
  appropriate regulator's own channel.
- **Deeper Islamic-finance product modeling.** Model specific AAOIFI-compliant deposit structures
  (Mudarabah, Wakala) with their own profit-calculation methodology rather than treating
  `profit_distribution` as a generic credit line, once tenant demand justifies the added complexity.
- **Cross-border payment cost optimization.** Recommend the lowest-total-cost rail/correspondent path
  for a given wire (comparing published fee schedules and historical FX spread performance across the
  company's available banking relationships) before a wire is submitted, rather than after the fact.
- **Configurable approval-chain topology.** Today the chain is fixed at Finance Manager → CEO for
  sensitive operations, matching the platform-wide rule; a future release could let a company insert
  additional intermediate approval steps (e.g. a Treasury sign-off between Finance Manager and CEO for
  transfers above a very high threshold) without weakening the floor the platform guarantees.
- **Standardized bank statement column-mapping marketplace.** Crowd-source and validate CSV/XLSX
  `import_template` column mappings per bank across tenants (with appropriate anonymization), so a new
  company onboarding a bank another QAYD tenant already uses gets an accurate mapping on the first
  import rather than a manual mapping exercise.

# End of Document
