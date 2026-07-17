# Month-End Close — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: MONTH_END_CLOSE
---

# Purpose

Month-End Close is the workflow that converts a fiscal period's raw, continuously-posted activity into a
signed-off, locked, audit-ready set of books and financial statements. It is the single business process
that touches more of QAYD's agent roster and more of the Accounting Engine's tables than any other workflow
in the platform, because closing a period is not one task — it is the point at which every module's
independent stream of activity (Sales, Purchasing, Banking, Inventory, Payroll, Tax) must be reconciled
against a single General Ledger, proven internally consistent, and turned into statements a CFO would sign
and a bank or auditor would accept without question.

Traditional accounting software treats month-end close as a manual fire-drill: an accountant exports a
trial balance to a spreadsheet, chases down unreconciled bank lines, guesses at accruals from memory,
manually maps a chart of accounts to statement line items, and writes a one-page summary for the owner —
a multi-day process repeated, imperfectly, every month. QAYD inverts this. Because the General Accountant,
Banking, Auditor, Tax Advisor, and Compliance agents are already watching the ledger continuously
throughout the month (per their own module and agent specifications), the bulk of what a traditional close
does in the first week of the following month has already happened, quietly and pre-drafted, by the time
the period actually ends. Month-End Close, as specified in this document, is the **orchestration layer**
that sequences what those already-running agents have prepared into one coherent, gated, auditable path
from `fiscal_periods.status = 'open'` to `'locked'` — and it is the workflow that proves, at every step,
that a human remains the one who decides when the books are true.

This document specifies: the exact trigger and preconditions that start a close; the eight agents that
participate and what each owns in this workflow specifically (their full individual mandates are specified
in their own agent documents — this document only describes their role in this business process); the
step-by-step orchestration with every human-approval gate; the tables read and written; the journal entries
a close cycle produces; the domain events emitted and consumed; how confidence and escalation govern
whether a step proceeds automatically or waits for a human; what failure, retry, and rollback mean when
"undo" can never mean "delete" in an immutable ledger; the timing SLAs QAYD holds itself to; a fully worked
example with real numbers; the controls and audit trail a statutory auditor can rely on; the edge cases the
workflow must survive; and the roadmap beyond version 1.0.

# Trigger & Preconditions

## Triggers

| Trigger | Source | Description |
|---|---|---|
| `schedule` | AI Layer scheduler | The default path. A close-readiness sweep starts automatically **T-2 business days** before `fiscal_periods.end_date`, giving the source-completeness and sub-ledger tie-out phases (see Orchestration) time to surface exceptions before the period actually ends. |
| `manual` | A Finance Manager, CFO, or Owner clicks **Start Month-End Close** in the Next.js Close Command Center | Available at any time from T-5 business days onward; used when a company wants to close early (e.g., ahead of a lender covenant test date) or when the scheduled sweep was dismissed and needs to be re-run. |
| `system` | Laravel scheduler, day after `fiscal_periods.end_date` | A safety-net trigger: if neither a `schedule` nor a `manual` run already exists for the period, one is created automatically so a close is never simply forgotten. |

Every trigger, regardless of source, resolves to exactly one row in `period_close_runs` (see Data & Tables
Touched) for a given `(company_id, fiscal_period_id)` pair — the workflow's own orchestration record,
distinct from `fiscal_periods` itself, which remains owned by the Journal Entries / Fiscal Periods module.

## Preconditions

A close run may only be created, and will immediately fail validation otherwise, when all of the following
hold:

1. **The target fiscal period exists and is currently `open`.** A period in `future`, `soft_close`,
   `locked`, or `closed` cannot have a new close run initiated against it — the API returns `409 Conflict`
   naming the current status.
2. **No other `period_close_runs` row for the same `fiscal_period_id` is `in_progress`.** Enforced by a
   `UNIQUE (fiscal_period_id)` constraint (see Data & Tables Touched) — a second trigger against an
   already-running close is a no-op that attaches to the existing run rather than creating a duplicate.
3. **The company's chart of accounts satisfies the Chart of Accounts module's fiscal-year preconditions**
   (at least one active Retained Earnings account and one Current Year Net Profit account — Chart of
   Accounts BR-14), since a close that could never validly reach a Balance Sheet is not worth starting.
4. **The immediately preceding fiscal period, if one exists, is at least `soft_close` or later.** QAYD does
   not support closing period N while period N-1 remains fully `open`, because Trial Balance and Financial
   Statement generation for period N depend on a stable Balance Forward from period N-1 (see the General
   Ledger module's Balance Forward definition). An exception applies only under a company's explicit
   `accounting.fiscal_year.parallel_open` configuration for rare parallel-year operation.
5. **The company has not disabled the relevant AI agents.** If `company_ai_agent_settings.is_enabled =
   false` for one of the participating agents (e.g., the Banking Agent), the close proceeds with that
   agent's steps executed manually by a human instead of automatically — disabling an agent degrades a
   close to a more manual one, it never blocks a close from happening.

None of these preconditions requires that source-module activity for the period is already complete —
completeness is itself the first phase of the workflow, not a prerequisite for starting it.

# Participating Agents

Each agent below is fully specified in its own document (`ACCOUNTANT_AGENT.md`, `BANKING_AGENT.md`,
`AUDITOR_AGENT.md`, `TAX_AGENT.md`, `CFO_AGENT.md`, `COMPLIANCE_AGENT.md`); this section describes only what
each one is asked to do **inside this specific workflow**, not its full individual mandate.

| Agent | Role in Month-End Close | Primary Artifact Produced |
|---|---|---|
| **General Accountant** | Drafts the period's currency-revaluation, depreciation, and accrual/deferral adjusting entries; drafts a correcting or reclassifying entry if the Auditor confirms a real (non-timing) control-account mismatch. | `journal_entries` (`status='draft'`, `entry_type IN ('adjusting','ai_generated')`) |
| **Banking Agent** | Drives every `bank_accounts` row's `bank_reconciliations` for the period to `balanced`, surfacing any unresolved line as a proposal rather than closing silently around it. | `bank_reconciliations.status = 'balanced'` |
| **Auditor** | Independently re-verifies the Unadjusted and Adjusted Trial Balance (structural, statistical, and control-account-tie-out checks); publishes `trial_balance_ai_findings`. | `trial_balance_ai_findings` |
| **Tax Advisor** | Aggregates the period's posted `tax_transactions` into a liability view and cross-checks it against the GL, confirming the correct tax provision (including "zero provision due," a structural fact, not an estimate, for a company with no active registration for a given tax type). | `ai_decisions` (`decision_type='liability_computation'`) |
| **Reporting Agent** | Runs the Financial Statements module's Report Generation Engine once the Adjusted Trial Balance is approved, producing the Balance Sheet, Income Statement, Cash Flow Statement, and Statement of Changes in Equity as a linked snapshot set. | `financial_statement_snapshots` (`status='draft'` → `'under_review'`) |
| **CFO** | Reads the finalized-or-finalizing statements and the period's variance against budget/prior period, produces the ratio pack and narrative, and issues a "ready to close" or "not yet ready" recommendation — never the close decision itself. | `ai_decisions` (`decision_type='cfo_variance_narrative'`, optionally `'cfo_liquidity_alert'`) |
| **Compliance Agent** | Runs the synchronous pre-close gate on `fiscal_period.close_requested`: segregation-of-duties history, open control attestations, retention holds, and any statutory filing at risk — able to place a **blocking** hold that stops the period from reaching `soft_close`. | `compliance_assessments`, `compliance_alerts`, and an optional blocking `ai_decisions` row |
| **Approval Assistant** | Consolidates every pending human action this close cycle generates — accrual-draft reviews, Trial Balance sign-off, statement finalization, compliance-hold clearance, the close sign-off itself — into one role-scoped **Close Approval Queue**, with SLA countdowns per item. It introduces no new decision logic of its own; it is a routing and aggregation layer over `ai_decisions`, `journal_entry_approvals`, `trial_balance_approvals`, and `period_close_tasks`. |

The Trial Balance's own review step, per its module specification, is additionally reviewed by Fraud
Detection for duplicate/anomaly patterns; that agent is not a primary participant of this workflow and is
mentioned here only because its findings land in the same unified feed the Auditor's do (see the Trial
Balance module's `trial_balance_ai_findings.source` distinction between `system` and `ai`).

# Orchestration

Month-End Close runs as eleven phases. Phases 1–4 run largely in parallel across modules; phases 5 onward
are strictly sequential, each gated on the phase before it. Every phase materializes as one row in
`period_close_tasks` (see Data & Tables Touched), so the workflow's own state is always queryable
independent of any individual agent's internal state.

```
                          [ TRIGGER: schedule | manual | system ]
                                          │
                                          ▼
                         period_close_runs row created (status='in_progress')
                                          │
        ┌─────────────────────────────────┼─────────────────────────────────┐
        ▼                                 ▼                                 ▼
 PHASE 1  Source-Module          PHASE 1  Banking Agent          PHASE 1  Payroll & Inventory
 Completeness Sweep              drives bank_reconciliations     completeness (payroll_runs
 (Sales/Purchasing: all          for every bank_accounts row     posted; stock_movements for
 invoices/bills for the          toward 'balanced'                the period fully posted)
 period posted)
        └─────────────────────────────────┼─────────────────────────────────┘
                                          ▼
                    PHASE 2  Sub-Ledger Tie-Out (AR / AP / Inventory → Control Accounts)
                    Auditor compares customers.balance_base_currency, vendors.balance_due,
                    and open inventory_valuations layers against their GL control-account
                    balances; a real (non-timing) mismatch routes to General Accountant
                    for a correcting entry — see Confidence & Escalation Rules
                                          │
                                          ▼
                    PHASE 3  Adjusting & Accrual Entries (General Accountant + Tax Advisor)
                    Currency revaluation → Depreciation → Accrual/deferral drafts →
                    Tax liability confirmation, each a draft journal_entries row routed
                    through the standard Journal Entries approval-threshold table
                                          │
                                          ▼
                    PHASE 4  Bank Reconciliation Closed (Banking Agent + Finance Manager)
                    'balanced' → 'closed' via bank.reconcile.close (human-only, ever)
                                          │
                                          ▼
              ══════════════ HUMAN-APPROVAL GATE: adjusting entries posted ══════════════
                                          │
                                          ▼
                    PHASE 5  Fiscal Period Soft-Close Gate (Compliance Agent)
                    fiscal_period.close_requested fires; synchronous SoD/attestation/
                    retention check; a blocking flag here halts all forward progress
                                          │
                                clear ────┼──── blocking
                                          ▼            └──▶ period_close_runs.status='blocked'
                    fiscal_periods.status: open → soft_close
                                          │
                                          ▼
                    PHASE 6  Trial Balance Generate → Validate → Review → Approve
                    Unadjusted TB (pre Phase 3) → Adjusted TB (post Phase 3) →
                    Auditor + Fraud Detection findings → Senior Accountant/Finance
                    Manager (+ CFO above Tier-2) approval chain
                                          │
              ══════════ HUMAN-APPROVAL GATE: Adjusted Trial Balance approved ══════════
                                          │
                                          ▼
                    PHASE 7  Draft Financial Statements (Reporting Agent)
                    Balance Sheet, Income Statement, Cash Flow Statement, Statement of
                    Changes in Equity generated in mode='historical', status='draft'
                    → 'under_review'; balance-identity and CF-to-BS cash-tie validated
                                          │
                                          ▼
                    PHASE 8  Variance Review & Narrative (CFO)
                    Ratio pack, budget/prior-period variance, liquidity check,
                    "ready to close" recommendation
                                          │
                                          ▼
              ═══════════════ HUMAN-APPROVAL GATE: close sign-off ══════════════════════
                                          │
                                          ▼
                    PHASE 9  Human Close Sign-Off (Finance Manager, or CFO ≥ Tier-2)
                    Reviews the full close packet: ATB + findings, draft statements,
                    CFO narrative, any compliance notes
                                          │
                                          ▼
                    PHASE 10  Period Lock
                    fiscal_periods.status: soft_close → locked
                    Event period.closed emitted
                                          │
                              (last period of fiscal year?)
                                          │
                                       yes │ no ──▶ [ CLOSE COMPLETE ]
                                          ▼
                    PHASE 11  Year Closing (CFO/Owner approval, always, any amount)
                    Revenue/Expense accounts swept to Retained Earnings;
                    fiscal_years.status → 'closed'
```

**Why phases 1–4 run in parallel.** Bank reconciliation, sub-ledger tie-out, and adjusting-entry drafting
have no data dependency on one another at the start of a close — the Banking Agent does not need the
Auditor's tie-out result to begin matching statement lines, and the General Accountant's depreciation
schedule does not depend on the bank feed. Running them concurrently is what compresses a close from the
sequential multi-week exercise a purely manual process would require into the multi-day target described
in SLAs & Timing. Phase 3's accrual drafting deliberately *waits* on nothing from Phase 1/2 except the
period's `end_date` having been reached, so a same-day draft is possible the moment the period closes.

**Why Phase 5 sits where it does.** The Compliance Agent's gate is placed immediately after adjusting
entries post (not before) so that a blocking condition discovered mid-close (e.g., a payroll run for the
period that failed a PIFSS rate check) is caught before the period is allowed to move past `open` — but
after the routine adjusting-entry work is already done, so a cleared gate does not force re-doing anything.
This exact hook — `fiscal_period.close_requested` triggering a **synchronous** pre-close check that may
block — is the same event and behavior specified in the Compliance Agent's own document; this workflow does
not redefine it, only sequences when it fires.

# Data & Tables Touched

## Tables this workflow reads (never writes to directly from the AI layer)

`accounts`, `account_types`, `fiscal_years`, `fiscal_periods`, `journal_entries`, `journal_lines`,
`ledger_entries`, `customers` (`balance_base_currency`), `vendors` (`balance_due`), `inventory_items`,
`inventory_valuations`, `stock_movements`, `bank_accounts`, `bank_transactions`, `bank_statement_lines`,
`bank_reconciliations`, `payroll_runs`, `payroll_items`, `tax_registrations`, `tax_transactions`,
`tax_returns`, `compliance_requirements`, `compliance_filings`, `cost_centers`, `projects`, `departments`,
`ai_memory`, `ai_logs`.

## Tables this workflow's participating agents write to, always through the Laravel API

`journal_entries` / `journal_lines` (new `draft` rows only — posting itself remains the Posting Engine's
sole responsibility, invoked by a human action or a pre-authorized policy, never by an agent directly),
`trial_balance_snapshots` / `trial_balance_snapshot_lines` / `trial_balance_snapshot_adjustments` /
`trial_balance_ai_findings` / `trial_balance_approvals`, `financial_statement_snapshots` /
`financial_statement_snapshot_lines` / `financial_statement_notes`, `bank_reconciliations` (status
transitions only), `fiscal_periods` (status transitions only, via the Fiscal Periods module's own service),
`compliance_assessments` / `compliance_alerts` (AI-owned tables, exempt from the "never write directly" rule
for the same reason `ai_logs` is — they are observability rows, not financial facts), `ai_decisions`,
`ai_tasks`.

## Tables this document introduces: the workflow's own orchestration record

Neither `fiscal_periods` nor `ai_tasks` alone is sufficient to answer "where is this company's June close
right now, across eight agents and eleven phases." `period_close_runs` and `period_close_tasks` are the
concrete, queryable representation of this workflow's own state — introduced here because Month-End Close
is the first document in this batch that needs a cross-agent checklist, rather than a single agent's task
queue.

```sql
CREATE TYPE period_close_status AS ENUM (
  'not_started', 'in_progress', 'blocked', 'ready_for_signoff',
  'signed_off', 'locked', 'failed', 'rolled_back'
);

CREATE TYPE period_close_task_status AS ENUM (
  'pending', 'running', 'completed', 'skipped', 'failed', 'requires_human'
);

CREATE TYPE period_close_task_type AS ENUM (
  'source_completeness_check', 'sub_ledger_tie_out', 'currency_revaluation', 'depreciation',
  'accrual_deferral_draft', 'tax_provision_confirm', 'bank_reconciliation_close', 'compliance_gate',
  'soft_close_transition', 'trial_balance_generate', 'trial_balance_review', 'trial_balance_approve',
  'financial_statements_draft', 'variance_review', 'human_signoff', 'period_lock', 'year_closing'
);

CREATE TYPE period_close_trigger AS ENUM ('schedule', 'manual', 'system');

CREATE TABLE period_close_runs (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    branch_id                   BIGINT NULL REFERENCES branches(id),
    fiscal_period_id            BIGINT NOT NULL REFERENCES fiscal_periods(id),
    status                      period_close_status NOT NULL DEFAULT 'not_started',
    triggered_by                period_close_trigger NOT NULL DEFAULT 'schedule',
    started_at                  TIMESTAMPTZ NULL,
    ready_for_signoff_at        TIMESTAMPTZ NULL,
    signed_off_by               BIGINT NULL REFERENCES users(id),
    signed_off_at               TIMESTAMPTZ NULL,
    locked_at                   TIMESTAMPTZ NULL,
    trial_balance_snapshot_id   BIGINT NULL REFERENCES trial_balance_snapshots(id),
    financial_statement_snapshot_ids JSONB NOT NULL DEFAULT '[]',
    blocking_compliance_alert_ids JSONB NOT NULL DEFAULT '[]',
    close_score                 NUMERIC(5,2) NULL CHECK (close_score BETWEEN 0 AND 100),
    rollback_reason             TEXT NULL,
    is_year_end                 BOOLEAN NOT NULL DEFAULT false,
    created_by                  BIGINT NULL REFERENCES users(id),
    updated_by                  BIGINT NULL REFERENCES users(id),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                  TIMESTAMPTZ NULL,
    CONSTRAINT uq_period_close_runs_period UNIQUE (fiscal_period_id)
);
CREATE INDEX idx_pcr_company_status ON period_close_runs (company_id, status);
CREATE INDEX idx_pcr_period ON period_close_runs (fiscal_period_id);

CREATE TABLE period_close_tasks (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    period_close_run_id     BIGINT NOT NULL REFERENCES period_close_runs(id) ON DELETE CASCADE,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    task_type                period_close_task_type NOT NULL,
    sequence_order           SMALLINT NOT NULL,
    status                   period_close_task_status NOT NULL DEFAULT 'pending',
    owning_agent_code        VARCHAR(40) NULL REFERENCES ai_agents(code),  -- NULL = pure Laravel/system step
    ai_task_id               BIGINT NULL REFERENCES ai_tasks(id),
    ai_decision_id           BIGINT NULL,       -- polymorphic-by-convention: the agent's own decisions table
    blocking                 BOOLEAN NOT NULL DEFAULT true,
    started_at               TIMESTAMPTZ NULL,
    completed_at             TIMESTAMPTZ NULL,
    attempt_count            SMALLINT NOT NULL DEFAULT 0,
    error_message            TEXT NULL,
    notes                    TEXT NULL,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_period_close_tasks_run_type UNIQUE (period_close_run_id, task_type)
);
CREATE INDEX idx_pct_run ON period_close_tasks (period_close_run_id, sequence_order);
CREATE INDEX idx_pct_status ON period_close_tasks (company_id, status) WHERE status IN ('failed','requires_human');
```

`period_close_tasks.blocking = false` exists for the rare task a company configures as informational only
(e.g., a company that does not track departmental dimensions may mark `variance_review` non-blocking for a
given branch); every task type shipped with the platform defaults to `blocking = true`, since a close that
can silently skip a step is not a close a human can trust.

# Journal Entries Produced

Every journal entry a Month-End Close cycle produces is drafted by the General Accountant (or, for
Currency Revaluation and Depreciation, generated by their respective scheduled background jobs and then
routed through the identical draft → review → post path) and follows the exact templates already defined
in the Journal Entries submodule — this document does not introduce a new entry shape, it sequences when
the existing ones fire during a close.

| Entry | `entry_type` | Typical Debit | Typical Credit | Drafted By |
|---|---|---|---|---|
| Currency Revaluation | `revaluation` | Unrealized FX Loss (if a receivable's base-currency value fell) | Foreign-currency Asset/AR account | `RevalueForeignCurrencyBalances` job, reviewed by Finance Manager or CFO |
| Depreciation | `depreciation` | Depreciation Expense (by asset category/cost center) | Accumulated Depreciation (contra-asset) | `RunAssetDepreciation` job |
| Accrual (expense incurred, not yet billed) | `adjusting` / `ai_generated` | Expense account | Accrued Expenses (liability) | General Accountant, from a `journal_entry_templates` recurring pattern or an ad hoc estimate |
| Deferral (prepaid expense amortized) | `adjusting` | Expense account | Prepaid Asset | General Accountant |
| Control-account correcting entry (confirmed sub-ledger tie-out mismatch) | `ai_generated` | Varies — cited to the Auditor's `control_account_mismatch` finding | Varies | General Accountant, `accountant_reclassification` |
| Tax provision (only if a net liability actually exists for the period) | `tax` | Tax Expense | Tax Payable to Authority | Confirmed by Tax Advisor's liability computation; posted per the Tax module's own `tax.committed` path, not directly by this workflow |
| Year Closing (last period of the fiscal year only) | `year_closing` | Every Revenue account's credit balance (zeroed) | Every Expense account's debit balance (zeroed); net to Retained Earnings | System-generated, requires CFO or Owner approval regardless of amount |

Every one of these entries, when AI-originated, is created `status = 'draft'`, never `pending_approval` or
`posted` directly — the platform-wide rule that AI never auto-posts applies to every entry this workflow
touches without exception. `entry_type = 'ai_generated'` entries carry the mandatory ≥ 1 human approval
level regardless of amount, per the Journal Entries approval-routing table; `revaluation` entries require
one Finance Manager or CFO approval level; `year_closing` always requires CFO or Owner, at any amount.

**Example — accrual entry drafted by the General Accountant during Phase 3, before human review:**

```json
{
  "entry_type": "adjusting",
  "journal_date": "2026-07-01",
  "memo": "June 2026 utilities accrual — no invoice received yet, estimated from 6-month trailing average",
  "lines": [
    { "account_id": 6140, "debit": "640.0000", "description": "Utilities Expense — June accrual" },
    { "account_id": 2410, "credit": "640.0000", "description": "Accrued Expenses" }
  ],
  "meta": {
    "ai_generated": true, "ai_agent": "general_accountant", "confidence": 0.79,
    "reasoning": "No June utilities bill posted as of period end. Trailing 6-month average for this vendor's utilities line is KWD 640.000/month with low variance (σ = KWD 18.400). Estimated, not matched to a source document.",
    "source_documents": []
  }
}
```

Note the empty `source_documents` array and the explicitly disclosed estimate basis in `reasoning` — an
accrual with no matched invoice is exactly the case the General Accountant's own guardrails require it to
disclose honestly rather than imply a matched source it does not have.

# Events Emitted/Consumed

| Event | Direction | Producer | Primary Consumers | Payload highlights |
|---|---|---|---|---|
| `period_close.initiated` | Emitted | Period Close orchestration service | Approval Assistant, Finance Manager notification | `company_id`, `fiscal_period_id`, `triggered_by` |
| `bank.synced` | Consumed | Banking module | Banking Agent (Phase 1) | new statement lines to match |
| `bank.reconciled` | Consumed | Banking module, on `bank_reconciliations.status = 'balanced'` | Auditor (cross-checks reconciled transactions against journal entries) | `bank_account_id`, `period_start`/`period_end` |
| `invoice.posted` / `bill.posted` | Consumed | Sales / Purchasing | Phase 1 completeness sweep | source-document existence for the period |
| `payroll_run.completed` | Consumed | Payroll | Phase 1 completeness sweep, Compliance Agent | `payroll_run_id`, period |
| `stock_movement.posted` | Consumed | Inventory | Phase 1/2 (sub-ledger tie-out inputs) | `product_id`, `warehouse_id`, `quantity`, `value` |
| `journal.posted` | Consumed | Accounting (Posting Engine) | Auditor (continuous review), Trial Balance regeneration trigger | `journal_entry_id`, `entry_type`, `total` |
| `fiscal_period.close_requested` | Emitted (Phase 5) | Period Close orchestration service | Compliance Agent (synchronous gate) | `fiscal_period_id`, `requested_by` |
| `period_close.blocked` | Emitted | Period Close orchestration service, on a Compliance blocking flag | Finance Manager, CFO, Approval Assistant | `blocking_compliance_alert_ids` |
| `trial_balance.generated` | Emitted | Trial Balance module | Auditor, Fraud Detection, Reporting Agent (readiness signal) | `snapshot_id`, `type` (unadjusted/adjusted), `status` |
| `financial_statement.finalized` | Emitted | Financial Statements module, on `financial_statement_snapshots.status = 'final'` | CFO Agent (board-pack triggers reference this event in its own document), Tax module, downstream consolidation | `snapshot_id`, `statement_type`, `fiscal_period_id` |
| `period_close.ready_for_signoff` | Emitted | Period Close orchestration service (after Phase 8) | Finance Manager / CFO, Approval Assistant | `close_score`, open findings summary |
| `period_close.signed_off` | Emitted | Period Close orchestration service (Phase 9) | Approval Assistant, Auditor | `signed_off_by`, `signed_off_at` |
| **`period.closed`** | Emitted | Period Close orchestration service (Phase 10, terminal) | Every module subscribed to period boundaries (Reports, Consolidation, next period's Balance Forward computation), Approval Assistant, External Auditor notification if configured | see below |
| `fiscal_year.closed` | Emitted | Fiscal Periods / Journal Entries module (Phase 11, year-end only) | CFO, Reporting Agent, Tax Advisor (year-end statutory reserve context) | `fiscal_year_id` |

**`period.closed` payload example:**

```json
{
  "event": "period.closed",
  "company_id": 4821,
  "fiscal_period_id": 3389,
  "fiscal_year_id": 3350,
  "period_label": "June 2026",
  "period_close_run_id": 71042,
  "trial_balance_snapshot_id": 91260,
  "financial_statement_snapshot_ids": [91277, 91278, 91279, 91280],
  "signed_off_by": 118,
  "signed_off_at": "2026-07-02T16:40:00+03:00",
  "locked_at": "2026-07-03T08:05:00+03:00",
  "close_score": 96.5,
  "occurred_at": "2026-07-03T08:05:00+03:00"
}
```

# Confidence & Escalation Rules

## The close-readiness score

`period_close_runs.close_score` is a single, continuously recomputed 0–100 figure the close packet displays
throughout the run — a workflow-level composite, distinct from any individual agent's own confidence score,
that answers "how ready is this period to lock, right now."

```
close_score =
    25 × (source_completeness_pct)                     -- Phase 1: bank rec balanced, all modules posted
  + 25 × (sub_ledger_tie_out_pct)                       -- Phase 2: AR/AP/Inventory variance within tolerance
  + 20 × (trial_balance_clean_pct)                      -- Phase 6: no open Critical/High Auditor finding
  + 15 × (compliance_gate_clear ? 15 : 0)                -- Phase 5: no active blocking flag
  + 15 × (financial_statements_balanced ? 15 : 0)         -- Phase 7: VR-01 balance identity holds
```

A `close_score` below 100 is normal and expected mid-run; it is a progress indicator, never a gate by
itself. The only hard gates are the explicit human-approval steps in Orchestration — a `close_score` of 100
does not skip Phase 9's human sign-off, and a `close_score` below 100 does not by itself prevent a human
from proceeding past a *non-blocking* item with a documented reason.

## Per-artifact confidence bands

| Artifact | Confidence source | Auto-advance threshold | Human gate |
|---|---|---|---|
| Accrual/deferral draft (General Accountant) | Weighted precedent + document-match strength, per the General Accountant's own scoring formula | None — `ai_generated` entries always require ≥ 1 human approval regardless of confidence | Accountant/Senior Accountant review; Finance Manager/CFO above Tier-2 |
| `control_account_mismatch` finding (Auditor) | 0.40–0.69 soft signal typical for a timing-lag variance; ≥ 0.90 for a structural/rule violation | Never auto-resolved | Finance Manager acknowledges, confirms, or dismisses with a mandatory reason |
| Tax liability confirmation (Tax Advisor) | 1.00 when the result is a structural fact (no active registration ⇒ zero provision); lower when an estimate is involved | Auto-surfaced as informational when confidence = 1.00 and amount = 0 | Tax Manager review whenever a non-zero provision or any estimate is involved |
| Compliance gate result | Self-consistency check: LLM-grounded pass + independent rule-engine pass must both agree before a `blocking` flag is ever issued | A single-pass disagreement downgrades automatically to `advisory`, never silently to `blocking` | Owner/CFO/Auditor only, to override a confirmed blocking flag |
| Financial statement draft (Reporting Agent) | Deterministic — VR-01 balance identity and VR-03 cash-tie either hold or do not; this is not a probabilistic confidence | A failing VR-01/VR-03 blocks `final` status entirely, no threshold applies | Finance Manager/CFO before `final`; External Auditor sign-off is separate, later |
| Variance narrative (CFO) | Weighted blend of arithmetic completeness and interpretive judgment share, per the CFO Agent's own formula | Never auto-applied — always `pending_approval` before the close packet references it as final | Finance Manager or CFO |

## Escalation ladder

| Condition | Escalates to | Timing |
|---|---|---|
| Compliance blocking flag raised | Finance Manager immediately; CFO/Owner if unresolved 24h | Immediate (per Compliance Agent's own escalation rule) |
| Critical/High Auditor finding on the Adjusted Trial Balance | Finance Manager, CFO, Auditor role simultaneously | Immediate |
| A `period_close_tasks` row stuck `requires_human` past its task-level SLA | The task's assigned reviewer, then their manager role | Per SLAs & Timing |
| `close_score` has not reached 90 by T+2 business days | Finance Manager notified proactively, not only on request | Once, at T+2 |
| Close cycle exceeds 5 business days without sign-off | CFO and Auditor role, as a standing risk item | At T+5 |
| Close cycle exceeds 10 business days | Owner, as a governance escalation independent of the underlying cause | At T+10 |

# Failure, Retry & Rollback

**"Failure" is task-scoped, not run-scoped, wherever possible.** A single `period_close_tasks` row failing
(e.g., the Reporting Agent's statement generation times out, or a tool call to the Banking Agent's matching
service errors) does not fail the whole `period_close_runs` row. The task retries automatically with
exponential backoff up to `max_attempts` (platform default: 3), incrementing `attempt_count`; only once
retries are exhausted does the task move to `status = 'failed'` and, if `blocking = true`, the run itself
moves to `blocked` and a human is notified with the specific `error_message`.

**Retries are idempotent by construction.** Every write this workflow's agents make (a draft journal entry,
a `trial_balance_snapshots` generation call, a `financial_statement_snapshots` generation call) carries the
same idempotency-key discipline every other AI-agent write in the platform uses — a retried
`propose_journal_entry` call after a timeout does not produce a second draft; a retried Trial Balance
generation call for the same scope/period produces the same version, not a spurious new one, unless the
underlying `journal_lines` genuinely changed between attempts.

**Rollback never means deletion.** This is the platform's own immutability rule, applied specifically to
the close:

- A `draft` entry not yet approved can simply be edited or discarded — cheap, no ledger impact yet.
- A `posted` entry discovered to be wrong during the close is never edited or deleted. It is corrected by a
  fresh reversing or adjusting entry, exactly as the Journal Entries submodule specifies, dated in the still-
  open current period.
- **Un-doing a `soft_close`.** If Phase 9's reviewer decides, after all, that the period is not ready (a
  late-discovered issue, a re-opened sub-ledger dispute), the period can move `soft_close → open` — a
  controlled, logged transition requiring the same class of elevated permission and mandatory reason as
  reopening a `locked` period, even though it is a smaller step back. `period_close_runs.status` moves to
  `rolled_back` with `rollback_reason` populated, and a fresh close run may be initiated once the underlying
  issue is fixed. The prior run's full history remains permanently queryable.
- **Un-doing a `locked` period.** Only the Journal Entries submodule's formal reopening procedure applies:
  `accounting.period.reopen` (Owner/CFO only), a mandatory logged reason, and automatic re-notification of
  the External Auditor role if one is configured — reopening a period that was already locked is always a
  materially bigger event than rolling back a soft-close, and this workflow does not shortcut that.
- **Un-doing a Year Closing entry.** Never edited. A mis-timed or incorrect Year Closing is undone by
  reversing the Year Closing entry itself, exactly as any other posted entry would be, then re-running Phase
  11 once the underlying period-level issue is corrected.

**A failed close never leaves a partial, malformed artifact.** Because nothing in this workflow persists a
*financial* fact until its own module's terminal write succeeds (a draft entry is not posted until a human
approves it; a Trial Balance snapshot is not `is_current = true` until Validate passes), an interrupted close
— a server restart mid-run, a queue outage — resumes from the last completed `period_close_tasks` row rather
than reprocessing already-finished work or leaving a half-posted entry behind.

# SLAs & Timing

| Phase | Target elapsed time | Escalation if missed |
|---|---|---|
| Phase 1 — Source completeness sweep | Continuous throughout the month; final sweep completes within 4 hours of period end | Finance Manager notified if any source module still shows unposted activity 24h after period end |
| Phase 2 — Sub-ledger tie-out | p95 < 10 minutes (deterministic aggregation, no LLM call on the happy path) | Auditor role notified if a mismatch remains unresolved 48h after being raised |
| Phase 3 — Adjusting entries drafted | p95 < 2 minutes from period end for previously-templated entries (depreciation, recurring accruals); p95 < 4 hours for ad hoc estimates requiring retrieval | Escalates to the review queue as stale after 24h unreviewed |
| Phase 4 — Bank reconciliation closed | Median < 2 business days from period end, per the Banking module's own reconciliation-cycle-time target | Finance Manager notified if any account remains unbalanced past T+2 |
| Phase 5 — Compliance gate | Synchronous, target < 5 seconds for the automated check itself | A cleared-but-overridden blocking flag is itself logged and reviewed at the next control cycle |
| Phase 6 — Trial Balance approval | Target same business day the Adjusted TB is generated; hard target ≤ 2 business days | Escalates to Finance Manager after 24h, to CFO after 48h |
| Phase 7 — Financial statements drafted | p95 < 90 seconds from Adjusted TB approval (matches the Reporting Agent's underlying Report Generation Engine's real-time generation performance target) | Investigated as a system defect if it regularly exceeds 5 minutes |
| Phase 8 — CFO variance narrative | p95 < 90 seconds once statements are `under_review`, matching the CFO Agent's own board-pack time-to-insight target | — |
| Phase 9 — Human sign-off | Target same business day the close packet reaches `ready_for_signoff`; SLA ceiling 2 business days | Escalates per the escalation ladder above |
| Phase 10 — Period lock | Immediate, same request as sign-off unless the company disables auto-lock | — |
| **Full cycle (period end → locked)** | **Target ≤ 3 business days; SLA ceiling 5 business days** | See Confidence & Escalation Rules escalation ladder for T+5/T+10 |

These targets deliberately mirror the "close the books in days, not weeks" goal already stated in the
General Ledger module's own Business Goals — this workflow is the concrete mechanism that goal is measured
against.

# Worked Example

**Company:** Al-Rawda Trading & Logistics W.L.L. (Kuwait, base currency KWD, `company_id` 4821).
**Period:** June 2026, `fiscal_period_id` 3389, the final monthly period of Q2-2026 (`fiscal_period_id`
3390 in the CFO Agent's own quarterly board-narrative scenario is the parent quarter this period rolls
into). **Trigger:** `schedule`, 2026-06-28 (T-2 business days before `end_date = 2026-06-30`).

**T-2 (2026-06-28) — Phase 1 begins.** `period_close_runs#71042` is created, `status='in_progress'`. The
Banking Agent's matching sweep runs against NBK Operating's June statement import — the same 47-line import
detailed in the Banking Agent's own Scenario 1 — auto-committing 44 lines and proposing the remaining 3;
by 2026-06-29 all three are resolved (two accepted matches, one document-first backfill accepted), and
`bank_reconciliations` for the account reaches `status='balanced'`.

**T+0 (2026-06-30) — Phase 1 completes, Phase 2 runs.** All invoices, bills, and the June `payroll_run` are
posted. The Auditor's sub-ledger tie-out finds: Accounts Receivable — Control KWD 812,400.000 exactly equal
to `SUM(customers.balance_base_currency)`; Accounts Payable — Control KWD 341,200.000 exactly equal to
`SUM(vendors.balance_due)`; but Inventory — Control KWD 268,450.000 against `SUM` of open
`inventory_valuations` layers of KWD 268,270.000 — a KWD 180.000 variance. Confidence on this
`control_account_mismatch` finding is 0.62 (a soft signal, not a rule violation): the Auditor's reasoning
notes a stock count posted at 23:40 on 2026-06-30 whose valuation-layer refresh had not yet completed
before the tie-out ran. The Finance Manager reviews it the next morning, confirms it as the known nightly
valuation-refresh timing lag, and dismisses it with `resolution_reason: "Timing lag, count #8821 posted
late — layer refresh completed 2026-07-01 00:15, confirmed zero real variance."` — recorded into `ai_memory`
as a recognized pattern so an identical late-count timing lag is not re-flagged at the same severity next
month.

**T+1 (2026-07-01) — Phase 3.** The General Accountant drafts three entries: (1) Depreciation — KWD
3,250.000, Dr Depreciation Expense / Cr Accumulated Depreciation, confidence 0.99 (formulaic, from the
asset register's straight-line schedule); (2) Currency Revaluation — an open USD 92,000.000 trade
receivable booked at a weighted-average rate of 0.3081 (KWD 28,345.200) revalued at the period-end spot
rate of 0.3072 (KWD 28,262.400), an unrealized loss of KWD 82.800, Dr Unrealized FX Loss / Cr Accounts
Receivable — Control (USD-denominated line), confidence 1.00 (deterministic re-translation, not a
judgment call); (3) the utilities accrual shown in full under Journal Entries Produced, KWD 640.000,
confidence 0.79. The Tax Advisor confirms, separately, that Al-Rawda holds no active Kuwait VAT
registration and is not a foreign-owned or KSE-listed entity subject to Kuwait corporate tax or Zakat —
its `liability_computation` decision reports a structural KWD 0.000 net tax provision for the period at
confidence 1.00, included in the close packet as "no tax provision required this period" rather than
omitted silently. All three journal entries are reviewed and approved by the Accountant and Senior
Accountant the same day (all comfortably under the company's Tier-2 threshold) and post.

**T+1, later — Phase 4 and Phase 5.** The Finance Manager formally closes the NBK Operating reconciliation
(`bank.reconcile.close`). `fiscal_period.close_requested` fires; the Compliance Agent's synchronous gate
finds no open blocking condition — the company's monthly bank-reconciliation control attestation is current,
no retention hold intersects any record touched this period, and no payroll rate/cap mismatch was found on
the June run. `fiscal_periods#3389.status` moves `open → soft_close`.

**T+2 (2026-07-02) — Phase 6.** The Adjusted Trial Balance (`trial_balance_snapshots#91260`) is generated:
total debits and credits both KWD 2,144,572.800 (the Unadjusted Trial Balance's KWD 2,140,600.000 plus the
three adjusting entries' combined KWD 3,972.800), `status='validated'`, zero open Critical/High findings
(the one Low-severity inventory-timing finding is already dismissed). The Senior Accountant reviews, the
Finance Manager approves; `status` moves to `approved`.

**T+2, continuing — Phase 7 and Phase 8.** The Reporting Agent generates the four statements in
`mode='historical'` for `fiscal_period_id` 3389: Net Revenue KWD 613,000.000; Cost of Sales KWD
403,300.000; Gross Profit KWD 209,700.000 (34.2% margin); Operating Expenses KWD 138,750.000 (including this
period's KWD 3,250.000 depreciation and KWD 640.000 accrual); Operating Profit KWD 70,950.000; Other
Expenses (the KWD 82.800 unrealized FX loss); Profit Before Tax KWD 70,867.200; Income Tax Expense KWD
0.000; **Profit for the Period KWD 70,867.200**. The Balance Sheet's `TOTAL ASSETS = TOTAL LIABILITIES +
EQUITY` identity holds exactly (VR-01 passes, `is_balanced=true`, `variance=0.0000`), and the Cash Flow
Statement's period-end cash figure ties to the Balance Sheet's Cash and Cash Equivalents line (VR-03
passes). Snapshots move `draft → under_review`. The CFO Agent's variance narrative (confidence 0.91) notes
revenue KWD 613,000.000 against a budget of KWD 590,000.000 (+3.9%, favorable), gross margin 0.8 points
below budget (freight-cost drift, consistent with the pattern the CFO Agent's own quarterly narrative later
references for the full Q2), and a current ratio of 1.58 — comfortably above the company's configured 1.50
policy floor, so no `cfo_liquidity_alert` fires. The recommendation: **"ready to close."**

**T+2, evening — Phase 9.** The Finance Manager reviews the full close packet — the Adjusted Trial Balance,
the one dismissed finding, the four draft statements, and the CFO narrative — and signs off.
`period_close_runs#71042.signed_off_by/at` is set; `financial_statement_snapshots` move `under_review →
final`.

**T+3 (2026-07-03), 08:05 — Phase 10.** `fiscal_periods#3389.status` moves `soft_close → locked`. The
`period.closed` event (shown in full under Events Emitted/Consumed) fires with `close_score = 96.5` — not
100, because the workflow's own scoring formula weights the one dismissed-but-once-flagged finding into the
Trial Balance component rather than rounding it away. June is not the fiscal year's final period, so Phase
11 does not run this cycle. Total elapsed time from period end to lock: **3 business days**, inside the
target band.

# Controls & Audit

**Segregation of duties, end to end.** The accountant who drafts an adjusting entry is never its sole
approver (the Journal Entries submodule's segregation-of-duties rule applies unchanged inside a close); the
Auditor who raises a Trial Balance finding never resolves its own finding (only a human holding the
appropriate role does); the Finance Manager who signs off the close is not the same actor as the CFO/Owner
required for a Year Closing entry. No agent in this workflow holds a permission that would let it both
propose and approve the same artifact — this is enforced structurally at the permission-grant level in
every participating agent's own document, not merely by this workflow's convention.

**Every phase transition is independently auditable.** Each `period_close_tasks` row's `started_at`/
`completed_at`/`status` history, each `journal_entries`/`trial_balance_snapshots`/
`financial_statement_snapshots` state transition, and every `ai_decisions` row this cycle produces writes a
corresponding `audit_logs` entry (who, when, old value, new value, reason where applicable) — the same
platform-wide mutation-audit rule every other workflow in QAYD follows.

**Tamper-evidence on the two artifacts an external party will actually request.** `trial_balance_snapshots`
and `financial_statement_snapshots` each carry a `content_hash` (sha256 over the ordered line set),
computed at generation time — a closed period's numbers are reproducible byte-for-byte from the same
`journal_lines`, and any divergence between a stored snapshot and a fresh regeneration against the same
cutoff is treated as a data-integrity incident to investigate, never quietly reconciled away.

**The close packet is the External Auditor's starting point, not a separate deliverable built for them.**
A company with an External Auditor role configured grants that role read-only access to
`period_close_runs`, the Adjusted and Post-Closing Trial Balance snapshots, the `final` financial
statement snapshots, every `trial_balance_ai_findings` and `compliance_assessments` row for the period, and
the full `audit_logs` trail behind them — the same records the internal close relied on, not a
re-assembled summary.

**Reopening is always a bigger event than progressing.** Whether rolling a `soft_close` period back to
`open` or formally reopening a `locked` one, the permission required, the mandatory reason, and the External
Auditor re-notification (where configured) scale with how far the period had already progressed — this
workflow never makes "undo" cheaper than "do."

**Year-end adds one more control.** When Phase 11 runs, the statutory reserve transfer (e.g., Kuwait's
Commercial Companies Law 10%-of-net-profit-until-50%-of-capital rule) is AI-suggested by the Financial
Statements module's own reserve-transfer logic and requires explicit CFO/Finance Manager approval before
posting — it is never bundled silently into the Year Closing entry itself.

# Edge Cases

| # | Case | Handling |
|---|---|---|
| 1 | Bank reconciliation cannot reach `balanced` by the close deadline | The close does not proceed past Phase 4 for that account; Finance Manager may explicitly close with a documented open reconciling item under an elevated override, or delay the close — the workflow never silently closes around an unresolved bank line |
| 2 | Compliance blocking flag active at Phase 5 (e.g., a PIFSS rate mismatch found on the period's payroll run) | `period_close_runs.status = 'blocked'`; only Owner, CFO, or Auditor may clear the flag via `compliance.block.override`, logged with reason; the close resumes automatically once cleared |
| 3 | A late-arriving vendor bill is discovered after the period has already `locked` | Never inserted into the closed period. It posts in the next open period as a `prior_period_adjustment`-tagged entry, exactly mirroring the pattern the Banking Agent and Tax Advisor documents already use for late-arriving items |
| 4 | Auditor finds a Critical segregation-of-duties violation while reviewing the Adjusted Trial Balance | Blocks Trial Balance approval entirely until resolved, per the Auditor's own guardrail that a rule-violation finding is never softened by a memory-matched precedent |
| 5 | A multi-branch company has one branch's local activity lag behind another's | `period_close_runs` is company-level; `fiscal_periods.module_lock` (JSONB, per-module/per-branch) lets Sales close for Branch A while Payroll is still finalizing for Branch B — the company-level period only reaches `soft_close` once every tracked module/branch reports ready |
| 6 | An AI-drafted accrual later proves materially wrong once the real invoice arrives | Never edits the posted accrual. The next period's close proposes a fresh reversing entry plus a separately dated true-up, fully traceable as two distinct events |
| 7 | Draft financial statements fail the balance identity (VR-01) | Generation still returns 200 with `is_balanced=false` and the exact variance; Phase 7 cannot advance to `under_review`/`final`; root-caused to a specific unposted or unbalanced entry, never hidden |
| 8 | The company has subsidiaries requiring consolidation | Each entity's own Month-End Close proceeds and locks independently on its own timeline; the Consolidated statement (a separate, later generation mode) still requires its own approved elimination set per the Financial Statements module's BR-06 before it can be finalized — consolidation timing never blocks a single entity's own close |
| 9 | A required approver (Finance Manager) is unavailable at the sign-off deadline | The Journal Entries submodule's delegation mechanism applies identically inside a close: the approval is delegated to another eligible user, fully attributed on the record |
| 10 | A second trigger fires for a period that already has an `in_progress` run | No duplicate `period_close_runs` row is created (enforced by the `UNIQUE (fiscal_period_id)` constraint); the second trigger attaches as an observer to the existing run |
| 11 | The company is brand new, closing its first-ever period | No prior-period comparative exists; the CFO Agent's narrative explicitly discloses "insufficient history" rather than fabricating a trend, exactly as its own document specifies |
| 12 | A retention hold exists on a record the close packet references (e.g., an invoice under litigation hold) | The hold does not block the close; it is surfaced as an informational note in the close packet so the human signing off sees it, since retention holds govern purge-eligibility, not period-close eligibility |
| 13 | The scheduled T-2 sweep fires but the company has disabled one participating agent (e.g., the Banking Agent) | That phase's work reverts to fully manual — a human accountant performs the reconciliation directly — while every other agent's phase proceeds unaffected; a disabled agent degrades a close, it never blocks one |
| 14 | Two sibling agents disagree during the close (e.g., Fraud Detection flags a customer balance the sub-ledger tie-out would otherwise treat as clean) | The disagreement is surfaced explicitly in the close packet; neither agent silently overrides the other, and a human resolves it |
| 15 | A close is initiated manually (T-5) far ahead of period end, then substantial new activity posts afterward | The existing `period_close_runs` row is not invalidated; Phase 1's completeness sweep simply re-runs and naturally finds the newly-posted activity before Phase 2 proceeds |

# Future Improvements

- **Continuous close-readiness, not only a close-window score.** Expose `close_score`'s underlying
  computation as a live, always-on dashboard tile rather than a figure that only appears once a
  `period_close_runs` row exists, so a Finance Manager can see "if we closed right now, we'd be at 84" on
  any ordinary business day.
- **Batch review mode for adjusting entries.** Group every `accountant_month_end_accrual` and
  `accountant_reclassification` proposal from Phase 3 into a single reviewable batch for the Finance
  Manager, rather than N separate queue items — already foreshadowed as a Future Improvement in the General
  Accountant's own document, concretized here as the close workflow's specific consumer of that capability.
- **Auditor-authored correcting-entry suggestions.** Extend the Auditor's `control_account_mismatch` and
  other Trial Balance findings to propose the specific correcting entry's accounts and amounts, for the
  General Accountant or a human to review and submit, tightening the finding-to-correction loop inside
  Phase 2/6 without granting the Auditor any create or post capability.
- **Cross-company close benchmarking.** An anonymized, aggregate-only view of close-cycle-time and
  close-score distributions across comparable companies, so a Finance Manager can see whether a 3-day close
  is fast or slow for a company of this size and industry — never exposing another tenant's underlying
  figures.
- **Predictive close-readiness forecasting.** Rather than only reporting `close_score` as of now, forecast
  it forward ("at the current pace of bank-line resolution, you will likely be ready to sign off by 2026-07-02
  14:00") using the same trailing-velocity approach the Banking Agent already applies to its own reconciliation-
  pace estimates.
- **Tighter Compliance-to-close integration.** Surface any control attestation coming due within the close
  window directly inside the close packet itself, rather than requiring a Finance Manager to separately visit
  the Compliance dashboard to notice it.
- **Cross-cycle learning for accrual accuracy.** Feed each period's realized-vs-estimated variance on
  AI-drafted accruals back into `ai_memory` so next month's estimate for the same recurring expense starts
  from a tighter, self-correcting prior rather than a flat trailing average.
- **Year-end close simulation.** A dry-run mode that walks a company through Phase 11's Year Closing and
  statutory reserve transfer against a still-open final period, so a Finance Manager can preview the closing
  entry's effect before the period actually locks.

# End of Document
