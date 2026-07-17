# Bank Reconciliation Workflow — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: BANK_RECONCILIATION
---

# Purpose

A human accountant reconciling a bank account by hand opens a statement, opens the ledger, and ticks
lines off against each other one at a time until the two numbers agree. QAYD's AI layer exists so that,
by the time a human ever looks at a statement, the ticking is already done. Bank reconciliation is one of
the clearest illustrations of the platform's founding claim: **the AI is not a chatbot, it is an
autonomous finance workforce.** The moment a statement lands — via Open Banking sync, a CSV/MT940/CAMT.053
upload, or a scheduled sweep — the Banking Agent, Treasury Manager, Fraud Detection, and General
Accountant have usually already matched the overwhelming majority of lines, drafted the journal entries
for the handful that need one, and screened the batch for anything that looks like it needs a human's
judgment, before the accountant opens the reconciliation workbench at all. Traditional software waits for
the accountant to start reconciling. QAYD has usually finished the mechanical 90% of it before she asks.

This document specifies the **workflow**, not the module and not any single agent: it is the operational
glue between `docs/accounting/BANKING.md` (the data model and matching-rule specification this workflow
executes against) and four agent documents — `docs/ai/agents/BANKING_AGENT.md`,
`docs/ai/agents/TREASURY_AGENT.md`, `docs/ai/agents/FRAUD_AGENT.md`, and
`docs/ai/agents/ACCOUNTANT_AGENT.md`. It does not redefine any table, permission, or autonomy rule those
five documents already own; it defines the order in which the agents act, the exact points at which a
human must act instead, and what happens when something fails partway through. Every non-negotiable from
the platform's shared design context still applies here without exception: the AI layer never writes to
PostgreSQL directly; every AI action is a proposal that passes through the Laravel API's own validation
and RBAC exactly as a human's request would; every output carries a confidence score, explicit reasoning,
and cited source documents; sensitive operations — closing a reconciliation period, posting an adjustment,
moving money — always terminate in a named human approval, regardless of how confident the AI is; and
every read and write is scoped to exactly one `company_id`, with zero code path across the tenant
boundary. The accountant does not disappear from this workflow. She becomes its supervisor.

# Trigger & Preconditions

Reconciliation starts from exactly two kinds of trigger, mirrored in `ai_tasks.triggered_by`
(`ai_task_trigger`: `schedule` | `event` | `user_request` | `agent_request`, `docs/ai/agents/CFO_AGENT.md`
§ Outputs):

| Trigger | `ai_task_trigger` | Concretely | Source |
|---|---|---|---|
| Statement import completes | `event` | A human or a scheduled job uploads/pulls a statement through any of the four ingestion channels below; the moment ingestion finishes, the matching sweep fires on the resulting batch | `docs/accounting/BANKING.md` § Bank Reconciliation |
| Open Banking sync (recurring) | `event` | The bank/aggregator's own polling interval (default every 4 hours; instant where webhook push is supported) delivers new lines without a human action | `docs/accounting/BANKING.md` § API, "Open Banking integration" |
| Staleness sweep | `schedule` | The Laravel scheduler kicks a sync/reconciliation pass for any `bank_accounts` row whose last successful sync exceeds a company-configured staleness window (accounts with no live feed, e.g. `cash`/`petty_cash`, are swept on a fixed cadence instead) | This document |
| Period-close sweep | `schedule` | A pass runs automatically in the days ahead of a fiscal period's scheduled close, so open reconciliations are not discovered for the first time during month-end close | This document, mirrors `docs/ai/agents/ACCOUNTANT_AGENT.md` § Worked Scenarios (month-end accrual sweep) |

**Ingestion channels** (unchanged from, and must stay consistent with, `docs/accounting/BANKING.md` §
Bank Reconciliation): Open Banking API (ISO 20022 CAMT.053 or a bank/aggregator's native feed, under a
stored `open_banking_consent_id`); structured file (MT940, CAMT.053 XML); spreadsheet (CSV, XLSX, with a
per-`bank_name` reusable `import_template` column mapping); and scanned/native PDF, routed through
Document AI / OCR Agent before it becomes a `bank_statement_lines` candidate.

**Preconditions**, checked before Stage 1 (Orchestration) is allowed to run:

| Precondition | Enforced By | Failure Behavior |
|---|---|---|
| `bank_accounts.status = 'active'` | Laravel `FormRequest` on the import/sync endpoint | `422`; frozen/closed/pending-verification accounts cannot ingest a statement |
| No overlapping `bank_reconciliations` row already `in_progress`/`discrepancy` for the same `bank_account_id` + period | `uq_bank_reconciliations_period` unique constraint | New import is accepted (lines still land), but a second concurrent reconciliation row for the same period is rejected — the existing one is reused |
| Arithmetic tie-out: `opening_balance + Σ statement lines = closing_balance` | Import-time validation, `docs/accounting/BANKING.md` § Bank Reconciliation | Import blocked; a parsing/OCR-confidence warning is surfaced instead of silently proceeding with an unreliable batch |
| `company_ai_agent_settings.is_enabled = true` for `banking_agent` | Read at the start of Stage 2 | If disabled, Stage 1 (ingest) still runs, but Stage 2 (matching) degrades to a fully manual workbench — zero auto-commit — until a Finance Manager re-enables it |
| OCR field confidence ≥ 95 (PDF channel only) | Document AI / OCR Agent, per field | Fields below 95 land as `unmatched` candidates with the low-confidence field highlighted, rather than blocking the whole batch |

# Participating Agents

| Agent | Identity | Role in This Workflow | Owning Document |
|---|---|---|---|
| **Banking Agent** | `agent_key: banking_agent`, `parent_agent_key: treasury_manager` | Primary orchestrator of Stages 1–3: ingests and normalizes statements, runs the matching sweep, auto-commits qualifying matches, auto-creates known-pattern fee/interest/profit-distribution drafts, flags duplicates, proposes reconciliation adjustments | `docs/ai/agents/BANKING_AGENT.md` |
| **Treasury Manager** | `agent_code: TREASURY_AGENT` | Contributes the AI-similarity sub-signal into Banking Agent's match score (capped, never alone crossing the auto-commit threshold); consumes the resulting *reconciled* (not merely booked) cash position for its own liquidity view; flags FX exposure movement from realized settlement deltas surfaced during matching | `docs/ai/agents/TREASURY_AGENT.md` |
| **Fraud Detection** | `agent_code: fraud_detection` | Second-line, cross-transaction screen: subscribed to `bank.synced`, scores newly-landed lines and any flagged payee/velocity pattern surfaced during matching; opens a `fraud_cases` row and may request a hold only above a company-tuned threshold — never resolves a case itself | `docs/ai/agents/FRAUD_AGENT.md` |
| **General Accountant** | `agent_code: general_accountant` | Drafts the journal entries this workflow's adjustments require (bank charges, realized FX gain/loss, interest/profit distribution, unexplained-variance suspense) as `journal_entries.status='draft'`, `entry_type='ai_generated'`; validates the debit=credit invariant before any proposal is persisted; never submits, approves, posts, or reverses its own draft | `docs/ai/agents/ACCOUNTANT_AGENT.md` |

**Supporting, non-orchestrating participants.** Document AI / OCR Agent (a Banking Agent sub-capability
per `docs/accounting/BANKING.md` § AI Responsibilities) extracts structured lines from a scanned/PDF
statement before Stage 1 can run against it. Approval Assistant routes every `requires_approval = true`
`ai_decisions` row this workflow produces to the correct human queue and reports back the
`approved`/`rejected`/`edited` outcome that closes the loop. **Human roles**: Accountant and Senior
Accountant work the exception queue and journal-entry review; Finance Manager (and above) closes and, if
ever necessary, reopens a reconciliation period, and approves adjustments regardless of amount; Auditor
and External Auditor hold read-only visibility into the whole trail. **Explicitly out of scope for this
document** (consumers of its output, not participants in its execution): CFO Agent reads the resulting
cash position and liquidity ratio into its own board narrative; Compliance Agent is invoked only if a
realized FX gain/loss or an unexplained variance turns out to have a statutory filing consequence.

# Orchestration

```
 Statement / Sync Trigger
 (Open Banking API · MT940/CAMT.053 file · CSV/XLSX · scanned PDF · scheduled sweep)
              │
              ▼
 ┌────────────────────────────────────────────────────────────────────┐
 │ STAGE 1 — INGEST & NORMALIZE                  (Banking Agent, auto) │
 │  bank_statement_lines rows created, status='unmatched'              │
 │  tie-out check: opening_balance + Σ lines = closing_balance          │
 └───────────────────────────────┬──────────────────────────────────────┘
                                  │ blocked + surfaced if tie-out fails
                                  ▼  emits: bank.statement.imported
 ┌────────────────────────────────────────────────────────────────────┐
 │ STAGE 2 — MATCHING SWEEP     (Banking Agent auto; Treasury Manager   │
 │  contributes an AI-similarity sub-signal, capped, never solely        │
 │  decisive; candidates pulled from bank_transactions / receipts /       │
 │  vendor_payments)                                                       │
 │                                                                          │
 │   score ≥ auto_commit_threshold (90) AND ≥1 deterministic rule ≥30       │
 │        └──▶ auto-commit  (match_method = 'auto_rule')                    │
 │   known recurring fee / interest_earned / profit_distribution pattern,    │
 │   amount within historical variance band                                  │
 │        └──▶ auto-create + draft the transaction, link in the same step     │
 │   below threshold, or no deterministic anchor                               │
 │        └──▶ exception queue                                                  │
 └───────────────────────────────┬──────────────────────────────────────────────┘
                                  │ emits: bank.synced (imported / matched / unmatched counts)
                                  ▼
 ┌────────────────────────────────────────────────────────────────────┐
 │ STAGE 3 — EXCEPTION QUEUE                (reconciliation workbench)  │
 │  unmatched lines + AI's best candidate + score + why-a-rule-didn't-   │
 │  fully-fire; Fraud Detection screens new/changed payees, velocity      │
 │  spikes, and near-duplicate signatures surfaced while matching          │
 └───────────────────────────────┬──────────────────────────────────────────┘
                                  │
                    ╔═════════════▼═════════════╗
                    ║   HUMAN GATE — bank.reconcile   ║
                    ║  accept AI suggestion /            ║
                    ║  manual pair / split /              ║
                    ║  "no matching transaction exists"    ║
                    ╚═════════════╤═════════════╝
                                  ▼
 ┌────────────────────────────────────────────────────────────────────┐
 │ STAGE 4 — ADJUSTMENT PROPOSALS      (Banking Agent proposes; General  │
 │  Accountant drafts the journal entry for anything requiring judgment:  │
 │  realized FX gain/loss, unexplained-variance suspense, a document-first │
 │  backfill's underlying transaction)                                       │
 └───────────────────────────────┬────────────────────────────────────────────┘
                                  │  ╔═══ HUMAN GATE — Senior Accountant /  ═══╗
                                  │  ║     Finance Manager approves the draft   ║
                                  ▼  ╚══════════════════════════════════════════╝
                                     emits: journal.posted
 ┌────────────────────────────────────────────────────────────────────┐
 │ STAGE 5 — VARIANCE COMPUTATION                                        │
 │  adjusted_bank_balance = bank_balance_at_period_end                    │
 │                          + outstanding_deposits − outstanding_withdrawals│
 │  variance = book_balance_at_period_end − adjusted_bank_balance           │
 │                                                                            │
 │   variance = 0        → status = 'balanced'                               │
 │   variance ≠ 0         → status = 'discrepancy' → loop to Stage 3/4        │
 └───────────────────────────────┬────────────────────────────────────────────┘
                                  ▼
                    ╔═════════════▼═════════════╗
                    ║  HUMAN GATE — bank.reconcile.close  ║
                    ║        Finance Manager only           ║
                    ╚═════════════╤═════════════╝
                                  ▼  emits: bank.reconciliation.closed
                          STAGE 6 — CLOSED
                                  │
                                  ▼ (only if genuinely needed, reason required)
                    ╔═════════════▼═════════════╗
                    ║ HUMAN GATE — bank.reconcile.reopen  ║
                    ║        Finance Manager only           ║
                    ╚════════════════════════════╝
```

**Stage 1 — Ingest & Normalize.** Every channel converges on the same `bank_statement_lines` shape,
tagged with one `statement_import_id`, `period_start`/`period_end`, and the bank-reported
`opening_balance`/`closing_balance` for the period. This stage is fully automatic and irreversible in the
sense that a landed line is never edited — only its `status`/`matched_bank_transaction_id` change later; a
correction to bad data means a fresh, separately-tagged import, never a silent rewrite.

**Stage 2 — Matching Sweep**, the heart of this workflow, runs immediately after import and again on any
`bank_transactions` row newly reaching `cleared`. For each `unmatched` line, Banking Agent retrieves
candidates from three sources — unreconciled `bank_transactions` (primary), cleared `receipts` with no
linked transaction, and `vendor_payments` with `bank_transaction_id IS NULL` (both document-first, for a
cash leg that was recorded in Sales/Purchasing but never got its own bank row) — and scores every
candidate pair against the matching-rule table below, reused verbatim from `docs/accounting/BANKING.md` §
Bank Reconciliation:

| Rule | Signal | Weight | Fires When |
|---|---|---|---|
| Exact reference match | `bank_reference` / end-to-end ID equal on both sides | 45 | Any wire/ACH rail that returns a reference |
| Amount + date exact | Amount equal to the cent; `value_date` within ±2 business days (default `matching_window_days`) | 30 | Core rule for manual/check transactions with no reference |
| Amount + counterparty fuzzy | Amount exact; payee/payer name or IBAN fuzzy-matches (Levenshtein/phonetic) | 15 | Bank-abbreviated payee names ("GULF OFF SUPP TRD" vs. "Gulf Office Supplies W.L.L.") |
| Recurring pattern | Matches a `standing_orders`/recurring schedule's expected amount and date | 10 | Standing orders, payroll-adjacent recurring transfers |
| AI similarity (Treasury Manager) | Learned similarity over this account's historical matched pairs, `ai_memory` | up to 10, additive, capped | Never alone sufficient — at least one rule above must already have fired |

A candidate reaching `auto_commit_threshold` (company-configurable, default 90, on
`company_ai_agent_settings`) **and** anchored by at least one deterministic rule scoring ≥ 30 commits
automatically: the `bank_transactions` row moves to `reconciled`, the `bank_statement_lines` row becomes
`matched`, and a `bank_reconciliation_matches` row records exactly which rules fired
(`match_method='auto_rule'`). A known recurring fee/interest/profit-distribution pattern with no candidate
transaction at all follows a separate, narrower auto-post path (Journal Entries Produced, below) rather
than the matching-score path, since there is nothing to match against — only a pattern to recognize.
Everything else queues.

**Stage 3 — Exception Queue.** The workbench (`GET
/api/v1/banking/bank-accounts/{id}/reconciliation/unmatched`) shows every remaining `unmatched` line with
the AI's best candidate (if any), its score, and an explicit reason the score fell short ("amount and date
match; reference absent; counterparty is a known alias with only one prior confirmation"). Fraud Detection
runs in parallel here, not sequentially blocking the queue: any new payee, unusual amount, or velocity
pattern surfaced by a candidate match is forwarded as a signal rather than silently absorbed into a lower
AI-confidence score.

**Stage 4 — Adjustment Proposals.** A user with `bank.reconcile` resolves each exception (accept, manual
pair, split, or "no matching transaction exists" — the last of which is the trigger to create a new
transaction, pre-filled from the statement line). Where the correct resolution requires judgment rather
than a mechanical fact — a realized FX delta on a cross-currency settlement, a residual unexplained
variance after everything else is matched — General Accountant drafts the journal entry; Banking Agent
never posts a journal line itself, per its explicit mandate boundary in `docs/ai/agents/BANKING_AGENT.md`.

**Stage 5 — Variance Computation** is pure, deterministic arithmetic, run after every matching pass
(auto and manual) completes for the period; it is what decides whether the period can proceed to Stage 6
or must loop back.

**Stage 6 — Close.** A Finance Manager reviews the `bank_reconciliations` summary — `matched_line_count`,
`unmatched_line_count`, `variance`, and any open `fraud_cases` referencing this account/period — and calls
`POST /api/v1/banking/reconciliations/{id}/close`. This is the one action in the entire workflow that is
never, under any circumstance, available to an AI principal: `bank.reconcile.close` is absent from every
agent's tool registry in this roster, not merely permission-denied.

# Data & Tables Touched

| Table | Access | Notes |
|---|---|---|
| `bank_accounts` | Read | Currency, `gl_account_id`, thresholds context; never mutated by this workflow |
| `bank_statement_lines` | Read / Write | Created in Stage 1; `status`/`matched_bank_transaction_id`/`match_method`/`match_confidence` updated through Stages 2–4; never edited once imported |
| `bank_transactions` | Read / Write | Match candidates (read); `status` moves to `reconciled` on commit; new `fee`/`interest_earned`/adjustment/backfill rows created here (write) |
| `bank_reconciliations` | Read / Write | One row per account per period; created at first import, updated through every stage, `status` transitions `in_progress → discrepancy|balanced → closed` |
| `bank_reconciliation_matches` | Write | One row per committed match; `rules_fired` JSONB + `final_score`, the audit detail behind every auto-commit |
| `bank_transaction_status_history` | Write (generic trigger) | Append-only state-transition log for every `bank_transactions` status change this workflow causes |
| `receipts`, `vendor_payments` | Read | Document-first candidate pools (Sales/Purchasing-owned; read-only here) |
| `standing_orders` | Read | Recurring-pattern rule signal |
| `exchange_rates` | Read | FX-delta computation on cross-currency matches |
| `ai_memory` | Read / Write | Learned payee aliases, recurring-fee signatures; reinforced or down-weighted per accept/reject outcome |
| `ai_tasks` | Write | One row per matching-sweep/adjustment-drafting invocation; `status`, `retry_count`, `result_decision_id` |
| `ai_decisions` | Write | Every match candidate, adjustment proposal, duplicate flag, and FX exposure note this workflow produces |
| `ai_logs` | Write (append-only) | Every tool call, permission check, and decision creation, success or denied |
| `fraud_signals`, `fraud_cases` | Write (conditional) | Only when Fraud Detection's cross-transaction screen crosses its own notify/hold thresholds |
| `journal_entries`, `journal_lines` | Write | Adjustment entries only (fee, FX, interest/profit distribution, variance suspense) — see Journal Entries Produced |
| `audit_logs` | Write (generic trigger) | Every mutation above, platform-wide, independent of this workflow's own logic |
| `company_ai_agent_settings` | Read | `auto_commit_threshold`, `matching_window_days`, `is_enabled` — Finance-Manager-owned configuration this workflow consumes but never writes |

# Journal Entries Produced

This workflow introduces no new entry shapes of its own. Every journal entry a bank reconciliation cycle
produces reuses a template already defined in `docs/accounting/JOURNAL_ENTRIES.md` and
`docs/accounting/BANKING.md` § Accounting Integration; this section sequences *when* each one fires during
reconciliation and states, once, the single genuinely new item this workflow needs — a suspense line for a
residual that survives every matching pass and still cannot be explained.

| Entry | `entry_type` | Fires At | Typical Debit | Typical Credit | Drafted By | Approval |
|---|---|---|---|---|---|---|
| Bank charge / known-pattern fee | `ai_generated` | Stage 2, auto-create path (no candidate transaction, recurring signature in `ai_memory`) | 6410 · Bank Charges Expense | Bank (`bank_accounts.gl_account_id`) | Banking Agent (draft), advanced by a rate-limited, company-whitelisted policy action — never by the Banking Agent's own posting permission | None per-instance; capped at 5/account/day, then degrades to suggest-only (see Confidence & Escalation Rules) |
| Interest earned / profit distribution | `ai_generated` | Stage 2, same known-pattern auto-create path | Bank | 7100 · Interest Income, or 7105 · Profit Distribution Income on `is_shariah_compliant` accounts per `BANKING.md` § Compliance | Banking Agent (draft), same policy-advance path | Same daily cap as above |
| Realized FX gain/loss on a matched cross-currency settlement | `ai_generated` | Stage 4, on a committed match where the booked rate and the settled rate differ | 7810 · FX Loss (realized) | 7800 · FX Gain (realized), or the settlement's own clearing/bank line depending on direction | General Accountant, from the Banking Agent's annotated delta — the Banking Agent computes and cites the number, it never posts it | Senior Accountant / Finance Manager, regardless of amount |
| Document-first backfill's cash leg | `ai_generated` | Stage 3, on an accepted document-first proposal | Bank (a deposit-type backfill) or the sub-ledger control account (a payment-type backfill) | The sub-ledger control account (deposit) or Bank (payment) | Banking Agent (draft transaction); General Accountant confirms the account mapping | The `bank.reconcile` user who accepted the proposal, then the transaction's normal posting review |
| Unexplained variance suspense | `adjusting` | Stage 4, only after Stage 3's exception queue is fully worked and a residual still remains | 1290 · Suspense — Unreconciled Bank Variance (book balance exceeds adjusted bank balance) | Same account, reversed sides (adjusted bank balance exceeds book balance) | General Accountant, always — never the Banking Agent, which has no tool-registry path to this entry type | Senior Accountant **and** Finance Manager, at any amount |

Two adjacent entries are deliberately not this workflow's to produce, and this document does not duplicate
them. **Unrealized FX revaluation** on an open foreign-currency bank balance is Currency Revaluation's job
(`docs/accounting/JOURNAL_ENTRIES.md` § Currency Revaluation, executed by the periodic
`RevalueForeignCurrencyBalances` job and sequenced by `docs/ai/workflows/MONTH_END_CLOSE.md` Phase 3) — bank
reconciliation only ever books a *realized* delta on a settlement it is actually matching, never a
mark-to-market estimate on a balance nothing has moved yet. **Payroll's** Debit Salaries/Wages Payable /
Credit Bank and **Purchasing's** Debit AP Control / Credit Bank entries are posted by their own modules the
moment the underlying `payroll_runs` disbursement or `vendor_payments` clears; this workflow reads those
`bank_transactions` rows as match candidates but never re-posts, duplicates, or re-derives their journal
entries — Banking's own **Accounting Integration** rule ("Banking never maintains its own parallel
accounting truth") applies to this workflow exactly as it applies to the module as a whole.

**Example — the known-pattern fee, drafted and policy-advanced in one Stage 2 pass** (the exact KWD 3.500
NBK monthly maintenance fee from the Worked Example below):

```json
{
  "journal_entry": {
    "entry_type": "ai_generated",
    "status": "draft",
    "source_module": "banking",
    "source_type": "bank_transactions",
    "source_id": 90501,
    "entry_date": "2026-07-15",
    "currency_code": "KWD",
    "description": "Monthly account maintenance fee — NBK Operating — recurring pattern, 12/13 months",
    "ai_confidence": 0.9820,
    "lines": [
      { "account_code": "6410", "debit": "3.5000", "credit": "0.0000" },
      { "account_code": "1010", "debit": "0.0000", "credit": "3.5000", "bank_account_id": 12 }
    ]
  }
}
```

**Example — unexplained variance suspense, drafted by the General Accountant after Stage 3 is exhausted**
(the KWD 42.750 residual from Worked Example, Part B):

```json
{
  "journal_entry": {
    "entry_type": "adjusting",
    "status": "draft",
    "source_module": "banking",
    "source_type": "bank_reconciliations",
    "source_id": 4103,
    "entry_date": "2026-07-31",
    "currency_code": "KWD",
    "description": "Unreconciled bank variance — NBK Operating, period 2026-07-16..2026-07-31 — pending investigation",
    "ai_confidence": 0.4100,
    "reasoning": "Every bank_statement_lines row for this period is matched or explicitly ignored; a KWD 42.750 gap remains between the adjusted bank balance and the book balance with no remaining candidate on either side. Confidence is deliberately low — this is a disclosed unknown, not a resolved fact.",
    "lines": [
      { "account_code": "1290", "debit": "42.7500", "credit": "0.0000", "reconciliation_id": 4103 },
      { "account_code": "1010", "debit": "0.0000", "credit": "42.7500", "bank_account_id": 12 }
    ],
    "adjustment_reason": "Residual variance after full matching sweep and manual workbench review; escalated per Confidence & Escalation Rules for Finance Manager investigation before next cycle."
  }
}
```

Note the honestly low `ai_confidence` on the suspense draft — General Accountant's own guardrail against
false precision (`docs/ai/agents/ACCOUNTANT_AGENT.md` § Worked Scenarios, Scenario 3) applies with full
force here: a suspense entry records that money is missing, not why, and the draft says so in its own
`reasoning` field rather than manufacturing a plausible-sounding cause it cannot actually cite a source
document for.

# Events Emitted/Consumed

Every event below carries the platform's canonical envelope (`event_id`, `event_name`, `company_id`,
`aggregate_type`, `aggregate_id`, `occurred_at`, `causation_id`, `correlation_id`, `payload`); only the
payload highlights are shown to keep the table readable.

| Event | Direction | Fires At | Primary Consumers | Payload Highlights |
|---|---|---|---|---|
| `bank.statement.imported` | Emitted | Stage 1, the instant a batch of `bank_statement_lines` lands and its arithmetic tie-out passes | Stage 2 of this same workflow (triggers the matching sweep); the Banking Agent's own `ai_tasks` queue | `bank_account_id`, `statement_import_id`, `period_start`/`period_end`, `opening_balance`/`closing_balance`, `line_count` |
| `bank.synced` | Emitted | Stage 2, the instant the matching sweep finishes scoring a batch (whether triggered by an import or by a `bank_transactions` row newly reaching `cleared`) | `docs/ai/agents/ACCOUNTANT_AGENT.md` Scenarios 1–2 trigger directly off this event; `docs/ai/workflows/MONTH_END_CLOSE.md` Phase 1's completeness sweep consumes it; Fraud Detection's cross-transaction screen wakes on it | `bank_account_id`, `statement_import_id`, `imported_count`, `auto_matched_count`, `unmatched_count` |
| `bank.reconciled` | Emitted | Stage 5, the instant `variance` computes to `0.0000` and `bank_reconciliations.status` moves to `balanced` — distinct from, and earlier than, the human close | Auditor (cross-checks reconciled transactions against journal entries, per `MONTH_END_CLOSE.md`'s own Events table); Treasury Manager (its liquidity view now treats this account's balance as *reconciled*, not merely `cleared`) | `bank_account_id`, `reconciliation_id`, `period_start`/`period_end`, `matched_line_count` |
| `bank.reconciliation.closed` | Emitted | Stage 6, on the Finance Manager's `POST /banking/reconciliations/{id}/close` | `MONTH_END_CLOSE.md` Phase 4's gate (a period cannot proceed past Phase 4 for an account still open); Financial Reporting's cash-position freshness; External Auditor notification if configured | `reconciliation_id`, `closed_by`, `closed_at`, final `matched_line_count`, `variance` |
| `bank.fraud_flag.raised` | Emitted | Stage 3, whenever Fraud Detection's cross-transaction screen opens a `fraud_cases` row against a signal surfaced while matching | Auditor, Finance Manager, Approval Assistant (routes the case to the correct human queue) | `fraud_case_id`, `signal_type`, `risk_score`, `related_entity_type`/`related_entity_id` |
| `bank.duplicate_suspected` | Emitted | Stage 2/3, whenever a duplicate-likelihood signal fires on a new transaction or import | The user attempting the conflicting action (blocking confirmation modal) | `likely_duplicate_of`, `score` |
| `journal.posted` | Emitted | Stage 4, whenever any adjustment from Journal Entries Produced completes its approval and posts | Auditor (continuous review); Trial Balance regeneration trigger; `MONTH_END_CLOSE.md` Phase 2/6 | `journal_entry_id`, `entry_type`, `source_type='bank_transactions'` or `'bank_reconciliations'`, `total` |
| `vendor.bank_account_changed` | Consumed | Stage 3, as corroborating context whenever a document-first candidate involves a vendor whose bank details changed recently | Informs Fraud Detection's own risk score for that candidate; never blocks the match proposal by itself | `vendor_id`, `changed_at`, `changed_by` |
| `fiscal_period.close_requested` | Anticipated, not consumed | Governs the timing of the period-close sweep trigger in Trigger & Preconditions | This workflow runs *ahead of*, and is a precondition for, `MONTH_END_CLOSE.md` Phase 4/5 — it does not react to that event, it exists so the event's own gate finds nothing outstanding | — |

**`bank.synced` payload, in full** — the pivotal event three sibling documents key off directly:

```json
{
  "event_id": "018f38a1-6b2e-7000-9c3a-1f2e3d4c5b6a",
  "event_name": "bank.synced",
  "company_id": 4821,
  "aggregate_type": "bank_accounts",
  "aggregate_id": 12,
  "occurred_at": "2026-07-16T09:00:41Z",
  "causation_id": "018f38a0-1a2b-7000-8c3d-4e5f6a7b8c9d",
  "correlation_id": "e3b0c442-98fc-4e1c-8b7a-2f9d4a1c3e5f",
  "payload": {
    "bank_account_id": 12,
    "statement_import_id": "e3b0c442-98fc-4e1c-8b7a-2f9d4a1c3e5f",
    "imported_count": 47,
    "auto_matched_count": 44,
    "unmatched_count": 3
  }
}
```

`correlation_id` is set to the `statement_import_id` so every downstream event this one batch produces —
the three individual `ai_decisions` rows, any `bank.fraud_flag.raised`, and the eventual `bank.reconciled` —
can be traced back to the single import that started the chain, independent of how many hours or days
elapse before the chain's last link fires.

# Confidence & Escalation Rules

Three different confidence scales are in play in this workflow, and this workflow does not collapse them
into one — each belongs to the agent document that defines it, and this section only states how they gate
progress through the six stages.

| Source | Scale | Owning Document | What It Gates |
|---|---|---|---|
| Match `final_score` (rules_fired sum) | 0–100 | `docs/accounting/BANKING.md` § Bank Reconciliation | Auto-commit eligibility at Stage 2 — deterministic, not probabilistic |
| `ai_decisions.confidence_score` (Banking Agent, Treasury Manager, General Accountant) | 0–100 | `docs/ai/agents/BANKING_AGENT.md` § Outputs | How a proposal is presented/prioritized in the workbench — never whether it needs review |
| `fraud_signals.risk_score` / `fraud_cases.risk_score` (Fraud Detection) | 0.000–1.000 | `docs/ai/agents/FRAUD_AGENT.md` § Outputs | Whether a case opens (`min_risk_score_to_notify`) and whether a hold is requested (`min_risk_score_to_hold`) |

## Per-artifact confidence bands

| Artifact | Band | Behavior |
|---|---|---|
| Match candidate | `final_score` ≥ 90 **and** ≥ 1 deterministic rule ≥ 30 | Auto-commit (`match_method='auto_rule'`) |
| Match candidate | `final_score` < 90, or no deterministic rule ≥ 30 | Suggest-only; shown in the workbench with the specific rule(s) that fell short |
| Match candidate | `final_score` < 60 | Hidden from the default workbench view (available under "show all") per `docs/accounting/BANKING.md` § AI Responsibilities — a barely-there guess is not surfaced as if it were a real candidate |
| Known-pattern fee/interest auto-create | Description matches a stored signature **and** amount within the account's historical variance band | Auto (subject to the 5/account/day cap) |
| Known-pattern fee/interest auto-create | Amount outside the historical variance band | Escalates to suggest-only even though the description pattern matched — an anomalous amount on an otherwise-recognized payee is exactly the case this workflow does not wave through |
| Duplicate flag | Any positive likelihood | Suggest-only, blocking — the action proceeds only with an explicit typed override |
| Duplicate flag | Score ≥ 95 | Override requires a logged reason in `audit_logs`, not just a confirmation click |
| Document-first backfill | Any score | Always suggest-only — creating a new financial record is never auto-committed, regardless of confidence |
| Reconciliation adjustment (fee/interest/FX/suspense) | Any confidence | Always requires Senior Accountant / Finance Manager approval — `entry_type='ai_generated'` carries the mandatory human-approval floor from `docs/accounting/JOURNAL_ENTRIES.md` regardless of amount or confidence, per `docs/ai/agents/ACCOUNTANT_AGENT.md` § Guardrails, Rule 3 |
| Fraud signal | `risk_score` < `min_risk_score_to_notify` (company-tuned, typical default 0.50) | Recorded to `fraud_signals`, never triaged into a case |
| Fraud signal | `risk_score` ≥ `min_risk_score_to_notify` | Auto-opens a `fraud_cases` row (`status='open'`) — a deterministic threshold comparison, not a discretionary judgment call, per `docs/ai/agents/FRAUD_AGENT.md` § Autonomy Level |
| Fraud signal | `risk_score` ≥ `min_risk_score_to_hold` (typical default 0.80) | A hold is *requested*; whether it actually applies is the owning module's own deterministic policy reading the open case, never Fraud Detection calling a mutation endpoint directly |

## Escalation ladder

| Condition | Escalates To | Timing |
|---|---|---|
| A match candidate sits below auto-commit threshold | No escalation — routine workbench item for `bank.reconcile` | Continuous |
| Duplicate score ≥ 95 overridden by a human | Logged to `audit_logs` with the typed reason; visible to Auditor | Immediate |
| A `fraud_cases` row opens against a line surfaced during matching | Auditor and Finance Manager, simultaneously with the case opening | Immediate (`bank.fraud_flag.raised`) |
| A `fraud_cases` hold request is applied to a transaction this workflow is trying to match | The match itself is unaffected (a held transaction can still be matched against its statement line); the hold blocks that transaction's own onward disbursement/clearance, resolved through Fraud Detection's own document | Immediate |
| Stage 3 exception untouched by any human | The `bank.reconcile`-holding reviewer, then their manager role | 48 hours |
| Non-zero variance persists after a full matching pass (Stage 5 loops back) | Senior Accountant | Same business day the loop-back occurs |
| Suspense adjustment (Journal Entries Produced) drafted | Finance Manager, in addition to the standard Senior Accountant review, regardless of amount | Immediate — never batched into a routine review queue |
| Reconciliation not `balanced` by T+2 business days from period end | Finance Manager, proactively | At T+2, mirrors `docs/ai/workflows/MONTH_END_CLOSE.md` Phase 4's own stated escalation for this exact workflow |
| Reconciliation not `closed` by T+10 business days | CFO and Auditor role, as a standing risk item, independent of the underlying cause | At T+10 |
| Auto-match precision (weekly audit sample, `docs/ai/agents/BANKING_AGENT.md` § Metrics) drops below 99% | Auto-commit is frozen for the affected account pending review — a company-level circuit breaker, not a per-transaction one | On the weekly sample result |
| Auto-posted fee/interest transactions exceed 5/account/day | Every subsequent candidate that day escalates to suggest-only regardless of pattern confidence | Same day, real-time |
| Two participating agents disagree on the same line (e.g., Banking Agent's candidate looks clean, Fraud Detection flags the same payee for an unrelated reason) | Surfaced together in the workbench; neither silently overrides the other, and a human resolves it | Immediate, mirrors `docs/ai/workflows/MONTH_END_CLOSE.md`'s identical edge case |

# Failure, Retry & Rollback

**Failure is task-scoped, not batch-scoped, wherever the underlying work is divisible.** A single
`ai_tasks` row failing — a matching-sweep call that times out, a tool call to `banking.get_exchange_rate`
erroring on an unlisted currency pair — does not fail the whole statement import. `ai_tasks.status` (the
shared `ai_task_status` enum: `queued`/`running`/`awaiting_agent`/`awaiting_human`/`completed`/`failed`/
`cancelled`, defined once in `docs/ai/agents/CFO_AGENT.md` and reused verbatim by every agent in this
roster) moves to `failed` only after the queue worker's own retry policy is exhausted, incrementing
`ai_tasks.retry_count` on each attempt; `error` is populated with the specific failure, and — because
matching-sweep tasks are not `blocking` in the sense a period-close task can be — the remaining unmatched
lines in the same batch simply stay `unmatched` and reappear in the workbench rather than the entire import
being marked failed.

**Every retryable write in this workflow is idempotent by construction**, so a retried attempt after a
transient failure never doubles an effect:

- **Statement ingestion** de-duplicates on `(bank_account_id, value_date, amount, direction, bank_reference,
  raw_description)`. A retried or accidentally re-triggered import of the same file skips every line already
  present and reports the count as `skipped_duplicate_count`, per `docs/accounting/BANKING.md` § Edge Cases
  and `docs/ai/agents/BANKING_AGENT.md` § Failure Modes.
- **Match commits** are wrapped in `SELECT ... FOR UPDATE` on the `bank_statement_lines` row (`BANKING.md` §
  Edge Cases). A retried scoring pass that lands on an already-committed line receives `409 Conflict` /
  `ALREADY_MATCHED` and discards its own proposal rather than overwriting the winning one.
  `bank_reconciliation_matches` additionally has no `UNIQUE` constraint *forcing* one match per line, but the
  service layer never issues a second commit for a line whose `status` has already left `unmatched`.
  Two-sided races resolve to exactly one committed match, never zero and never two.
- **Auto-created fee/interest drafts** carry the statement line's own id as their idempotency anchor — a
  redelivered `bank.synced` event for an already-processed batch (see the redelivery case in
  `docs/ai/agents/ACCOUNTANT_AGENT.md` § Failure Modes) is a no-op against a line already `matched`,
  never a second draft transaction.
- **`ai_decisions` persistence** (`ai.persist_decision`) is called at most once per proposal per task; a
  retried task that already wrote its decision on a prior attempt is short-circuited by `ai_tasks.
  result_decision_id` already being set.

**Rollback never means deletion, at any stage.** This is the platform's own immutability rule, applied
specifically to reconciliation:

- **Unmatching** (`DELETE /banking/reconciliation-matches/{id}`) is the correct undo for a match — including
  an auto-committed one — discovered wrong before the period closes. Both the `bank_statement_lines` row and
  the `bank_transactions` row return to their pre-match state (`unmatched` / `cleared`); the
  `bank_reconciliation_matches` row itself is soft-deleted, not hard-deleted, so the fact that a rule once
  fired incorrectly on this pair remains in the historical record for audit and for the AI-similarity
  model's own training signal (a corrected mistake is a valuable label, not something to erase).
- **A posted adjustment discovered wrong** (a fee that was in fact a duplicate, an FX delta computed against
  a stale rate) is never edited. A fresh reversing entry is drafted by the General Accountant, dated in the
  still-open current period, exactly as `docs/accounting/JOURNAL_ENTRIES.md` specifies for any posted entry
  correction.
- **A closed reconciliation period** can only be undone by `POST /banking/reconciliations/{id}/reopen`
  (`bank.reconcile.reopen`, Finance Manager only), with a mandatory logged reason. Reopening is always a
  bigger event than progressing: it re-opens every `bank_statement_lines`/`bank_transactions` row in that
  period to re-matching, and — because a reconciliation this far downstream is frequently already inside a
  *closed* fiscal period by the time anyone notices the problem — commonly requires the Journal Entries
  submodule's own period-reopening procedure (`accounting.period.reopen`, Owner/CFO) to run first. This
  workflow never shortcuts that chain to make an undo cheaper than the close it is undoing.
- **A failed statement import** (arithmetic tie-out mismatch) never partially lands. Nothing is written to
  `bank_statement_lines` for a batch that fails its tie-out check; there is no partial, malformed batch to
  roll back, only a warning to re-upload a corrected file or accept the parsing/OCR-confidence caveat.
- **A run interrupted mid-sweep** (worker restart, queue outage) resumes from the last completed
  `ai_tasks` row for that `statement_import_id` rather than reprocessing already-committed lines — the same
  resumption pattern `docs/ai/workflows/MONTH_END_CLOSE.md` § Failure, Retry & Rollback documents for its own
  phase-scoped tasks.
- **Reversing a genuinely mistaken suspense entry** (the residual is later explained) follows the same
  reversing-entry rule as any adjustment: the original suspense line is never edited; a reversing entry plus
  the correctly-classified entry post as two new, fully traceable rows once the explanation is confirmed.

# SLAs & Timing

| Stage | Target | Escalation If Missed |
|---|---|---|
| Stage 1 — Ingest & Normalize | Open Banking: near real-time to hourly depending on provider polling/webhook support; structured file/spreadsheet/PDF: batch, on upload, OCR adds seconds-to-minutes by page count | A stalled import (no `bank_statement_lines` written within 10 minutes of an accepted upload) is treated as a system defect, not a business escalation |
| Stage 2 — Matching Sweep | p95 < 30 seconds for accounts under 10,000 unmatched lines (`docs/ai/agents/BANKING_AGENT.md` § Metrics, "Matching sweep latency") | p95 > 5 minutes investigated as a system defect |
| Stage 2 — Time-to-reconciled (arrival → `reconciled` or workbench-resolved) | p50 < 5 minutes, p95 < 4 hours | p95 > 24 hours notifies the account's Finance Manager |
| Stage 3 — Exception queue aging | A new exception is expected to be touched by a Senior Accountant within 1 business day | Untouched 48 hours → escalates per the ladder above |
| Stage 4 — Adjustment approval latency | Tier-1 amounts (below the company's Tier-2 threshold): median < 24 hours; Tier-2 (two-level approval, and every suspense entry regardless of amount): median < 48 hours — reusing `docs/ai/agents/ACCOUNTANT_AGENT.md` § Metrics, "Approval-chain latency" verbatim, since these are the same drafts flowing through the same queue | Ages in the review queue; flagged stale after 5 business days, matching the General Accountant's own `accountant_journal_draft` escalation rule |
| Stage 5 — Variance Computation | Synchronous, sub-second — deterministic arithmetic, no model call on the happy path | A computation that does not complete synchronously is a system defect, investigated as such |
| Stage 6 — Close | Same business day the reconciliation reaches `balanced`, once a Finance Manager reviews the summary | — |
| **Full cycle — statement arrival → `closed`** | **Median < 2 business days** (`docs/ai/agents/BANKING_AGENT.md` § Metrics, "Reconciliation cycle time"; independently restated as this workflow's own Phase 4 target in `docs/ai/workflows/MONTH_END_CLOSE.md`) | Finance Manager notified proactively at T+2 if any account remains unbalanced; CFO and Auditor role at T+10 |

**Auto-match rate** (the % of statement lines auto-committed without human action) is not itself a timing
SLA, but it is the single biggest lever on every timing number above: `docs/ai/agents/BANKING_AGENT.md`'s
own target is ≥ 90% by a company's second full month, with an alert if it sustains below 70% for two
weeks. A company well below its own historical auto-match rate will miss the Stage 2/full-cycle SLAs for a
structural reason (more of the batch is landing in the manual workbench), not a system-performance one —
the escalation in that case routes to a threshold/configuration review, not to Engineering.

# Worked Example

## Part A — The routine cycle

**Company:** Al-Rawda Trading & Logistics W.L.L. (Kuwait, base currency KWD, `company_id` 4821). **Account:**
NBK Operating (KWD), `bank_account_id` 12. **Trigger:** `event` — the account's bi-weekly Open Banking sync
delivers a CAMT.053 feed (`statement_import_id: e3b0c442-98fc-4e1c-8b7a-2f9d4a1c3e5f`, period 2026-07-01 →
2026-07-15, opening balance KWD 182,430.5000, closing balance KWD 196,880.2500, 47 lines — the same import
`docs/accounting/BANKING.md` § API and `docs/ai/agents/BANKING_AGENT.md` § Worked Scenarios both already
introduce; this section is the same cycle read end-to-end through all four participating agents rather than
through the Banking Agent alone).

**Stage 1 — Ingest.** All 47 lines land as `bank_statement_lines`, `status='unmatched'`. The arithmetic tie-
out (`182430.50 + Σ lines = 196880.25`) holds, so the import proceeds and `bank.statement.imported` fires.

**Stage 2 — Matching Sweep.** The Banking Agent retrieves candidates from the account's unreconciled
`bank_transactions`, cleared `receipts` with no linked transaction, and `vendor_payments` with
`bank_transaction_id IS NULL`, and scores every pair. Treasury Manager's AI-similarity contribution rides
along on every candidate the deterministic rules already anchored — it never runs standalone. 44 of 47
lines clear the account's 90-point auto-commit bar on some combination of exact-reference, amount+date,
fuzzy-counterparty, and recurring-pattern signals (the specific combination that fired for each line is
persisted in that line's own `bank_reconciliation_matches.rules_fired`, not collapsed into one company-wide
number) and auto-commit, `match_method='auto_rule'`; each corresponding `bank_transactions` row moves to
`reconciled`. Three lines do not:

- **Line #771201** (KWD 4,250.0000, bank reference `FT2607201234`). The exact-reference rule (45) and
  amount+date-exact rule (30) both fire against `bank_transactions #90344` — the Gulf Office Supplies
  vendor payment settling `BILL-2026-00891` — and because the statement's own counterparty field repeats the
  payee name QAYD already holds for that transaction, the amount+counterparty-fuzzy rule (15) fires as well;
  Treasury Manager's learned-similarity signal adds its capped contribution on top. `final_score` clears 90
  by a wide margin; `confidence_score = 100.00`. Auto-committed.
- **Line #771244** (KWD 3.5000, description "MONTHLY A/C FEE"). No candidate transaction exists — nothing
  in QAYD's own ledger ever initiated this line, because the bank charged it unilaterally. It matches a
  recurring-fee signature in `ai_memory` (12 of the last 13 months, same description, amount within the
  account's historical variance band). The Banking Agent auto-creates the `fee` transaction directly and the
  paired journal entry (Journal Entries Produced, above) is policy-advanced in the same operation — the
  daily auto-post counter for this account moves from 0 to 1, comfortably under the cap of 5.
- **Line #771247** (KWD 615.0000, description "KNET TRANSFER — CUST 4471"). No candidate `bank_transactions`
  row exists, but `receipts #5521` — a cleared customer receipt, same amount and date, never linked to a
  bank transaction — plausibly explains it. `final_score` is 68, below auto-commit. The Banking Agent
  persists a document-first backfill proposal rather than acting.

In parallel with matching, not after it, **Fraud Detection**'s routine cross-transaction screen scores every
line surfaced during the sweep. Customer 4471 behind line #771247 has an 18-month clean payment history on
this account with no velocity anomaly; `risk_score` computes to 0.06, well under `min_risk_score_to_notify`
— recorded to `fraud_signals` at `status='new'` and never triaged into a case. None of the 47 lines this
cycle crosses any fraud threshold; this is the ordinary, silent-majority outcome for Fraud Detection's
involvement in a routine sweep, not a special case.

**Stage 3 — Exception Queue.** A Senior Accountant opens the workbench the same afternoon. For line
#771201's fuzzy-counterparty rule to have fired at all required an exact string repeat — a different
invoice for the same vendor rendered as "GULF OFF SUPP TRD" the following cycle would score only 45 (fuzzy,
weight 15) + 30 (amount+date) = 75, below threshold, and would correctly queue here instead; this cycle's
batch does not happen to contain that case, so nothing further is needed on the two already-committed lines.
For line #771247, the reviewer accepts the document-first suggestion: this creates the missing
`bank_transactions` row (`ai_generated=true`, `source_document_type='receipts'`, `source_document_id=5521`)
and commits the match in the same step (`match_method='ai_suggested_accepted'`). The Banking Agent
reinforces the corroborating `ai_memory` signal for customer 4471's KNET pattern.

**Stage 4 — Adjustment Proposals.** Only the fee line required a posting, and it already posted as part of
Stage 2's policy-advanced auto-create — there is no further adjustment to draft this cycle; the residual
after Stage 3 is exactly zero.

**Stage 5 — Variance Computation.**

```
book_balance_at_period_end   = 196,880.2500
bank_balance_at_period_end   = 196,880.2500  (bank-reported closing_balance)
outstanding_deposits         =        0.0000
outstanding_withdrawals      =        0.0000
adjusted_bank_balance         = 196,880.2500
variance                      =       0.0000   →  status = 'balanced'
```

`bank_reconciliations` row: `matched_line_count=47`, `unmatched_line_count=0`, `status='balanced'`.
`bank.reconciled` fires; Treasury Manager's liquidity view now treats NBK Operating's balance as genuinely
reconciled cash, not merely a cleared-but-unverified figure.

**Stage 6 — Close.** The following business day, a Finance Manager reviews the summary — 47/47 matched,
zero open `fraud_cases` against this account/period — and calls `POST /banking/reconciliations/{id}/close`.
`bank.reconciliation.closed` fires. **Elapsed time, statement arrival to closed: 2 calendar days**, inside
the target band.

## Part B — A genuine variance requiring judgment

**Same account, next cycle.** NBK Operating's following bi-weekly sync covers 2026-07-16 → 2026-07-31,
opening balance KWD 196,880.2500 (carried forward from Part A), closing balance KWD 211,540.0000, 52 lines.
Stage 1 and Stage 2 proceed exactly as in Part A: the tie-out holds, 49 lines auto-commit, and 3 land in the
exception queue. This time, Stage 3 fully resolves all three — two accepted matches and one confirmed "no
matching transaction exists," itself creating a small new `outgoing_payment` draft — and the workbench shows
zero remaining unmatched lines. Stage 5 nonetheless computes a non-zero residual:

```
book_balance_at_period_end   = 211,582.7500
bank_balance_at_period_end   = 211,540.0000
outstanding_deposits         =       0.0000
outstanding_withdrawals      =       0.0000
adjusted_bank_balance         = 211,540.0000
variance                      =      42.7500   →  status = 'discrepancy'
```

Every line is matched, yet the two balances disagree by KWD 42.7500 — the signature of a bank-side event
with no QAYD-side counterpart at all (a bank-initiated correction to a prior period's own posting, visible
only in the running balance the bank reports, not as a distinct line item this statement). The Banking Agent
cannot propose a match for something that has no candidate line to match against; it proposes a
**reconciliation adjustment**, `decision_type='bank_reconciliation_adjustment_proposal'`,
`confidence_score=41.00` — deliberately low, because "unexplained after full matching" is not a recognized
recurring pattern the way the KWD 3.500 fee in Part A was. The Senior Accountant confirms no missed candidate
exists on either side; the General Accountant drafts the suspense entry shown in Journal Entries Produced.
Because a suspense entry escalates to the Finance Manager regardless of amount (Confidence & Escalation
Rules), the Finance Manager reviews it alongside the Senior Accountant on T+3 rather than T+1 — one business
day past this workflow's own 2-business-day median — and, finding no further explanation available, approves
it with the stated reason ("residual after full matching sweep; will re-open and reclassify if the bank's own
year-end statement detail later explains it"). The entry posts; `bank_reconciliations`' `variance` recomputes
to `0.0000`; `status` moves to `balanced`, then, the same day, to `closed` on the Finance Manager's own
action. **Elapsed time: 3 business days** — past the median, inside the T+10 hard escalation ceiling, and
itself the trigger for the T+2 proactive Finance Manager notification in the escalation ladder, which in this
telling is the same human who then resolves it.

# Controls & Audit

**Segregation of duties is structural, not procedural.** The Banking Agent's Laravel principal never holds
`bank.reconcile.close`, `bank.reconcile.reopen`, `bank.transfer`, or either payment-approval key — this is
checked at the principal-type level, so no per-company permission misconfiguration can grant an `ai_agent`
principal those keys (`docs/ai/agents/BANKING_AGENT.md` § Guardrails). The General Accountant's service
account is permanently ungranted `accounting.journal.submit/approve/post/reverse`
(`docs/ai/agents/ACCOUNTANT_AGENT.md` § Guardrails, Rule 1) — the agent that drafts a suspense or FX
adjustment can structurally never also approve it. Fraud Detection can open a case and request a hold; it can
never resolve a case or release a hold (`docs/ai/agents/FRAUD_AGENT.md` § Autonomy Level, both rows fixed at
**Never**). No agent participating in this workflow holds create-and-approve on the same artifact, and the
one human-side rule mirrors it: the Finance Manager who closes a period's reconciliation is never required
to be, and in the escalation path above happens to also be, the same person who approved its one adjustment
— the platform does not force a second reviewer where one qualified human's two distinct actions (approve
the entry, then separately close the period) already satisfy the control, but nothing prevents a company
from requiring two different people if it chooses to configure its roles that way.

**The deterministic-anchor rule is this workflow's central control, restated.** Every row this workflow ever
labels `match_method='auto_rule'` is defensible to an auditor without reference to model internals, because
a non-AI, independently verifiable fact — an exact reference, an exact amount and date, a fuzzy name match,
or a recurring schedule — cleared at least a 30-point bar before any AI-similarity contribution was even
added to the score. The label "AI-matched" (`ai_suggested_accepted`) always implies a human clicked accept;
the label "auto-matched" always implies the deterministic floor held. This distinction is preserved forever
in `bank_reconciliation_matches.rules_fired` and `.match_method`, never collapsed into a single opaque score
at write time.

**Every mutation is independently auditable.** `bank_transaction_status_history` append-only-logs every
status change; `bank_reconciliation_matches` is the permanent audit detail behind every commit, auto or
manual; `ai_logs` records every tool call this workflow's agents make, successful or permission-denied,
before its result ever reaches a reasoning step — a denied attempt is exactly as observable after the fact
as a successful one. `audit_logs` captures who/when/old-value/new-value/reason for every write, the same
platform-wide trigger every other workflow in QAYD relies on, not a bespoke mechanism this workflow invented.

**Reopening is always a bigger event than progressing.** Closing a period requires `bank.reconcile.close`;
reopening one requires the strictly rarer `bank.reconcile.reopen`, a mandatory reason, and — because the
underlying fiscal period is frequently already `locked` by the time a reopened reconciliation is needed —
often the Journal Entries submodule's own period-reopening chain first. This workflow never makes "undo"
cheaper than "do," mirroring the identical principle `docs/ai/workflows/MONTH_END_CLOSE.md` § Controls &
Audit states for its own period-lock/unlock pair.

**Confidence calibration is itself audited, on a fixed cadence, by a human who did not produce the score.**
A weekly stratified sample of at least 50 auto-committed rows per active account (or all of them, if fewer
exist) is reviewed by a rotating Senior Accountant; the result feeds `docs/ai/agents/BANKING_AGENT.md` §
Metrics' confidence-calibration curve, and a sustained drop in auto-match precision below 99% freezes
auto-commit for the affected account — a company-level circuit breaker that the Banking Agent cannot itself
lift.

**Suspense discipline.** A `1290 · Suspense — Unreconciled Bank Variance` line is never left to accumulate
quietly. Every such entry carries a mandatory `reconciliation_id` and `adjustment_reason`, is reviewed by the
Finance Manager at posting regardless of amount, and is explicitly revisited at the *next* reconciliation
cycle for that account — a suspense balance that persists unexplained across two consecutive periods is
itself an Auditor-visible finding, not a number the workflow is content to let sit.

**The Auditor and External Auditor roles read the same trail the workflow relied on, not a re-assembled
summary.** `bank_reconciliations`, `bank_reconciliation_matches`, every `ai_decisions` row this workflow
produced, any `fraud_cases` referencing the account/period, and the full `audit_logs`/`ai_logs` chain behind
them are all directly, read-only visible — the same pattern `docs/ai/workflows/MONTH_END_CLOSE.md` § Controls
& Audit establishes for its own close packet, applied here to a single account/period rather than a whole
company-wide close.

# Edge Cases

| # | Case | Handling |
|---|---|---|
| 1 | A statement line arrives with no corresponding QAYD-side event at all (a bank-initiated fee, correction, or interest posting QAYD never triggered) | Matches a known pattern → auto-created (Journal Entries Produced); otherwise queued for a human to create the appropriate transaction — never silently dropped, per `docs/accounting/BANKING.md` § Edge Cases |
| 2 | Two evaluations propose different candidates for the same statement line concurrently (a scheduled sweep racing a manual "accept" click) | `SELECT ... FOR UPDATE` on the `bank_statement_lines` row; the losing writer gets `409 ALREADY_MATCHED` and discards its proposal |
| 3 | An internal transfer between two of the company's own bank accounts appears on both accounts' statements | Modeled as one `transfers` row with two `bank_transactions` legs (`from_transaction_id`/`to_transaction_id`); each leg matches independently against its own account's statement line — never double-counted as two unrelated external matches |
| 4 | A single statement line nets several small QAYD-side transactions (e.g., several POS terminal batches settling as one daily deposit) | Many:1 matching — one statement line, several `bank_transactions` rows, recorded as multiple `bank_reconciliation_matches` rows sharing the one `bank_statement_line_id` |
| 5 | A previously auto-committed match is later found wrong (a coincidental amount+date collision between two unrelated transactions) | Unmatched via `DELETE /banking/reconciliation-matches/{id}` while the period remains open — never silently left, never deleted, the corrected pairing re-enters the workbench |
| 6 | An FX rate is unavailable for a required currency pair on the settlement date | The candidate match itself can still commit on its deterministic signals; the *realized-delta annotation* is blocked with `RATE_UNAVAILABLE` until a Finance Manager supplies a rate, rather than silently using a stale one (`docs/accounting/BANKING.md` § Edge Cases) |
| 7 | The company disables the Banking Agent mid-cycle (`company_ai_agent_settings.is_enabled = false`) | Ingestion is unaffected — statements still land as `bank_statement_lines` — but zero scoring, zero auto-commit, zero proposals; the workbench reverts to fully manual, and Treasury Manager's and Fraud Detection's own contributions to this workflow degrade the same way, independently, per their own agent documents |
| 8 | A reconciliation period spans a fiscal-year boundary | Reconciliation periods follow bank statement cycles, independent of `fiscal_periods`; resulting journal entries still post into whichever fiscal period contains each entry's `entry_date` |
| 9 | A closed reconciliation period needs a late-arriving item (a vendor bill payment discovered a month later, dated inside the closed period) | Never inserted into the closed period. Lands as an unmatched line in the *next open* period, flagged `crosses_closed_period=true` |
| 10 | The same statement period is uploaded twice (user error, or a manual CSV upload racing a scheduled Open Banking sync) | The ingestion hash check skips every line already present; matching state is undisturbed, `skipped_duplicate_count` reported in the import summary |
| 11 | Fraud Detection and the Banking Agent reach different conclusions about the same line (a clean-looking match on a payee Fraud Detection separately flags for an unrelated velocity pattern) | Both signals are surfaced together in the workbench; neither is suppressed by the other, and a human resolves it — mirrors the identical principle in `docs/ai/workflows/MONTH_END_CLOSE.md` § Edge Cases |
| 12 | A `receipts` or `vendor_payments` row backing an accepted document-first proposal is soft-deleted before the proposal is actually applied | `SOURCE_DOCUMENT_DELETED`; the Banking Agent re-sweeps the line as unmatched rather than completing a stale proposal |
| 13 | A company sets `auto_commit_threshold` unrealistically low (e.g., 40) | The deterministic-anchor floor still applies structurally — AI-similarity alone is capped at 10 and can never singlehandedly reach even a 40-point bar without a deterministic rule contributing first |
| 14 | A second reconciliation trigger fires for a period that already has an `in_progress`/`discrepancy` row for the same account | `uq_bank_reconciliations_period` prevents a duplicate row; the second trigger's new lines simply land against the existing reconciliation |
| 15 | A payment-provider settlement batch arrives net of a fee QAYD cannot itemize (a lump `pos_settlement` figure) | Posts at the net amount with an estimated fee line from the provider's published rate card; any variance versus the provider's later itemized statement posts as a small adjustment, not a silent correction to the original entry |

# Future Improvements

- **Streaming, push-based matching.** Extend Stage 2 from a 4-hour Open Banking poll (for institutions
  without webhook support) to true event-driven matching for every institution type, collapsing
  time-to-reconciled toward the p50 already achieved by webhook-native banks — a workflow-level consumer of
  the real-time ingestion goal `docs/accounting/BANKING.md` § Future Improvements already states at the
  module level.
- **Consolidated multi-account close packet.** Today each `bank_accounts` row reconciles and closes
  independently; a company with many accounts could review and close them as one packet feeding directly
  into `docs/ai/workflows/MONTH_END_CLOSE.md` Phase 4, rather than opening each account's workbench in turn.
- **Predictive reconciliation-readiness.** Forecast, not just report, this cycle's expected `balanced`
  timestamp from the trailing velocity of exception-queue resolution — the same technique
  `docs/ai/workflows/MONTH_END_CLOSE.md` § Future Improvements proposes for its own `close_score`, applied one
  level down to a single account's reconciliation pace.
- **Auto-suggested standing order creation.** When a document-first or fee/interest pattern proves stable
  across enough consecutive cycles, propose converting it into a `standing_orders` row (with its own approval
  chain) instead of re-detecting and re-posting the same pattern every single cycle indefinitely.
- **Image-based check matching.** For markets and accounts still settling by paper check, OCR the check
  image itself (payee, amount, check number) as an additional deterministic-rule input, rather than relying
  only on the bank's own textual statement description.
- **Cross-period duplicate detection.** Today's duplicate scan runs within a rolling window at ingestion;
  extend it to catch a duplicate spanning a period boundary (a line the bank itself re-reports a month later
  after its own correction), which the ingestion hash check alone would not catch across two separate
  imports.
- **Tighter Fraud Detection feedback loop on reconciliation-surfaced signals.** Feed this workflow's own
  accept/override/dismiss outcomes on cross-transaction screening back into Fraud Detection's model-retraining
  loop as labeled ground truth specific to the reconciliation context, extending the platform-wide loop
  `docs/accounting/BANKING.md` § Future Improvements already commits to in general.
- **Per-workflow AI cost accounting.** Attribute this workflow's aggregate OCR, embedding, and LLM-reasoning
  cost per company per cycle to the platform's planned per-agent usage-quota hook, mirroring
  `docs/ai/workflows/PURCHASE_TO_PAY.md` § Future Improvements' identical proposal for its own document-heavy
  workflow.
- **Suspense aging dashboard.** A standing, always-visible view of every open `1290` suspense balance by
  account and age, rather than one only surfaced inside the reconciliation workbench that produced it — so a
  Finance Manager sees "these three residuals are still open" without having to remember which cycles
  produced them.

# End of Document
