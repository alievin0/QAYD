# Year-End Close — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: YEAR_END_CLOSE
---

# Purpose

Year-End Close is the single highest-blast-radius workflow QAYD executes for any company. It is the moment every fiscal period's worth of journal activity — twelve months of sales, purchases, banking, payroll, and inventory movement, already closed period by period — gets swept, adjusted, provisioned, taxed, audited, locked, and rolled forward into a fresh fiscal year with statutory Financial Statements as the permanent record. Traditional close is a four-to-six-week fire drill run almost entirely by hand: a controller chases depreciation schedules, an external consultant estimates the tax provision, a spreadsheet reconciles the trial balance, and the auditor doesn't see a clean file until weeks after the year actually ended. QAYD's AI layer does not wait for that fire drill. The moment the twelfth fiscal period closes, a coordinated team of specialized agents — the General Accountant, the CFO, the Auditor, the Tax Advisor, the Compliance Agent, the Reporting Agent, and the Approval Assistant — begins drafting every adjustment the close will need, continuously, before any human has opened a spreadsheet.

This document is the orchestration specification for that team. It does not re-specify what any single agent is internally capable of — the General Accountant's drafting logic lives in its own agent document, the CFO's ratio and narrative capability lives in its own agent document, and the double-entry mechanics of a journal entry live in the Journal Entries and General Ledger module documents. What this document owns is the *choreography*: the exact sequence in which those already-specified capabilities must fire, the exact human-approval gate that sits between every AI-drafted output and anything becoming a permanent accounting fact, the exact tables every step reads and writes, the exact journal entries the workflow produces, the exact events it emits and consumes, and the exact way it fails safely and rolls back when something is wrong.

Three properties are non-negotiable and repeated throughout this document because Year-End Close is where a violation of any of them would be most damaging. First, the AI layer never writes to the database directly and never posts a journal entry — every adjusting entry, every depreciation run, every provision, every tax computation, and the Closing Entry itself are `ai_draft`-originated proposals that a human posts through the same Laravel API, RBAC, and immutability rules that govern every other entry in the platform (see General Ledger). Second, the Closing Entry and the Statutory Reserve transfer are gated by human approval unconditionally — not because the AI's confidence is ever expected to be low (the arithmetic is deterministic and typically scores above 95), but because the *action* is irreversible-by-editing (only reversible by a new entry) and touches the one number — Retained Earnings — that every stakeholder outside the company (a bank, an auditor, a tax authority, a shareholder) will read as fact. The ledger's own transition to `fiscal_years.status = 'closed'` is a mechanical, un-gated consequence of those two approvals having posted, not a third independent decision point (see Orchestration Step 10) — the next independent human gate after that point is Compliance Sign-off, which governs whether the statutory filing package is released and whether the new fiscal year is allowed to open for normal business. Third, nothing in this workflow crosses a `company_id` boundary; every read and every draft is scoped to the single active company, exactly as specified in every other module in this platform.

The outcome this workflow is built to produce: a fiscal year that closes in business days rather than weeks, with every adjusting entry pre-drafted and explained before a human is asked to approve it, a statutory statement set and audit pack ready the same day the year locks, and a new fiscal year that opens with its opening balances already correct — because they were carried forward mechanically from an already-verified Closing Entry, never re-keyed by hand.

# Trigger & Preconditions

Year-End Close can start from three trigger types, matching the `ai_tasks.triggered_by` enum (`schedule`, `event`, `user_request`) shared across the AI layer (see the CFO Agent document for the canonical `ai_tasks` definition, reused verbatim here):

| Trigger type | Concrete trigger | Who/what fires it |
|---|---|---|
| `event` | The twelfth (final) `fiscal_periods` row of the fiscal year transitions to `status = 'closed'`, emitting `fiscal_period.closed` | The Period Close workflow (General Ledger module), consumed automatically |
| `schedule` | A nightly scheduled job checks, for every company, whether `fiscal_years.end_date` is within a company-configured lead window (default 5 calendar days) of today and the year is not yet `closing`/`closed` | QAYD scheduler (`scheduler:year-end-close-watcher`) |
| `user_request` | A Finance Manager, CFO, or Owner explicitly starts the workflow via `POST /api/v1/accounting/fiscal-years/{id}/close-checklist` before the scheduled window, e.g. to front-load early-close preparation | Human, through the UI or API |

Whichever trigger fires, the workflow does not skip straight to closing. It always begins at **Step 0 — Readiness Check** (see Orchestration), which computes a **Year-End Close Readiness Score** and will refuse to proceed past Step 1 if the score is below the company's configured floor (default 95/100). The individual readiness conditions, each independently verifiable against already-canonical tables, are:

1. **All fiscal periods closed.** Every `fiscal_periods` row for the target `fiscal_year_id` has `status = 'closed'` (not `'open'` or `'locked'` without having passed through `'closed'` first), and `module_lock` shows every module (`sales`, `purchases`, `banking`, `inventory`, `payroll`) as `"closed"` for all twelve periods.
2. **Ledger integrity.** The Adjusted Trial Balance for the full year is balanced — `SUM(debit) = SUM(credit)` across every posted `journal_line` in the fiscal year, to the `NUMERIC(19,4)` fils — verified via the Trial Balance module's own snapshot mechanism, not recomputed independently by this workflow.
3. **No unreconciled bank activity.** Every `bank_reconciliations` row covering a period inside the fiscal year is `status = 'completed'`; zero `bank_statement_lines` inside the year remain unmatched.
4. **No stray drafts.** Zero `journal_entries` dated inside the fiscal year remain in `status IN ('draft', 'pending_approval')` other than the AI-drafted adjusting entries this workflow itself is about to create — any pre-existing stray draft is either resolved (posted or voided) or explicitly deferred to next year with a logged reason before Step 1.
5. **Fixed assets current.** The Fixed Assets sub-ledger's depreciation run is current through the fiscal year's final period for every `fixed_assets` row (`fixed_asset_depreciation_schedules` has no unposted schedule line dated on or before `fiscal_years.end_date`).
6. **Payroll current.** The last `payroll_runs` row of the fiscal year has `status = 'paid'`, and the End-of-Service Indemnity accrual (accrued monthly per the Payroll module) is current through the final period.
7. **Tax current.** Every `tax_returns` row due for a period inside the fiscal year is `status IN ('filed', 'accepted')`, or is explicitly flagged `is_provisional = true` with a disclosed reason (see Edge Cases).
8. **No open material exceptions.** No `ai_decisions` row of `decision_type = 'audit_exception'` (Auditor) or `'fraud_alert'` (Fraud Detection) with `status = 'pending_approval'` references an account whose balance exceeds the company's materiality threshold.
9. **Special accounts configured.** `companies.retained_earnings_account_id`, `companies.statutory_reserve_account_id`, `companies.income_tax_expense_account_id`, and `companies.income_tax_payable_account_id` are all set (added to `companies` by this document; General Ledger already defines `retained_earnings_account_id`, the others are new columns this workflow requires and introduces).
10. **Fiscal year state.** `fiscal_years.status = 'open'` (not already `'closing'` or `'closed'`) and no other `fiscal_year_close_runs` row already exists in a non-terminal state for the same `fiscal_year_id` (enforced by a unique constraint — see Data & Tables Touched).

Each condition is independently scored and weighted into the Readiness Score; a full worked readiness computation appears in Worked Example. A score at or above the floor auto-advances the workflow to Step 1; a score below the floor halts at Step 0 and notifies the Controller/Finance Manager with the specific unmet conditions — the workflow never silently proceeds around a gap in its own preconditions.

# Participating Agents

| Agent (roster name) | `agent_code` (matches `ai_agents.code`) | Role in this workflow | Autonomy in this workflow |
|---|---|---|---|
| General Accountant | `general_accountant` | Runs the readiness checklist; drafts every adjusting, depreciation, provision, and the Closing Entry itself as an `ai_draft`-origin proposal | Auto (compute/draft) → always `requires_approval` before posting |
| CFO | `cfo` | Reviews the adjusted P&L's variance against budget/prior year before the Closing Entry is approved; co-approves any provision above the materiality threshold; drafts the post-close board narrative | Suggest-only |
| Auditor | `auditor` | Runs the pre-close and post-close exception scans; assembles the Audit Pack; can hard-block progression past Step 7 | Suggest-only, with `escalate`/block authority |
| Tax Advisor | `tax_advisor` | Computes the period's tax provision (income tax on any foreign-owned profit share, Zakat/NLST/KFAS where applicable, deferred tax where recognized) and drafts the tax provision entry | Suggest-only, `requires_approval` |
| Compliance Agent | `compliance` | Confirms every statutory prerequisite for a lawful year-end close is met (reserve computation rule, required disclosures, filing prerequisites) and issues the final compliance attestation | Suggest-only, with `compliance.block.period_close` block authority |
| Reporting Agent | `reporting_agent` | Drafts the Statutory Reserve/Voluntary Reserve transfer (BR-08); generates the statutory Financial Statement snapshot set (`is_statutory_filing = true`); assembles statement content into the Audit Pack | Suggest-only, `requires_approval` |
| Approval Assistant | `approval_assistant` | Owns no judgment of its own; routes every gate in this workflow to the correct human role, tracks each gate's SLA timer, and escalates on breach | Mechanical/auto routing only |

`agent_code` values above are the exact, lowercase `ai_agents.code` identifiers this workflow's every `ai_tasks`/`ai_decisions`/`journal_entries.created_by_agent` row carries (per `general_accountant`'s and `tax_advisor`'s own self-declaration in `ACCOUNTANT_AGENT.md`/`TAX_AGENT.md`, and `auditor`'s/`compliance`'s in `AUDITOR_AGENT.md`/`COMPLIANCE_AGENT.md`); the boxed step labels in the ASCII flow below are rendered upper-case purely for diagram legibility and are not a separate identifier.

Two agents are consulted but do not own a step: **Forecast Agent** may be asked for a next-year opening budget seed once the year locks (informational, not part of the close itself), and **Fraud Detection** runs its standing continuous monitoring across the same period and is read (never re-scored) by the Auditor and CFO Agent if it has already raised a flag material to the year being closed. Neither introduces a new gate; both are read-only inputs to the Auditor's and CFO Agent's own steps.

Every agent in this table calls the same fixed set of Laravel `/api/v1` endpoints through the FastAPI layer that every other agent in the roster uses (see the CFO Agent and General Accountant Agent documents for the shared tool-wrapper pattern) — this document does not introduce a parallel access path. Where this workflow needs an endpoint not documented elsewhere, it is named explicitly in Orchestration and Journal Entries Produced below.

# Orchestration

Year-End Close runs as a sixteen-step saga, tracked by a dedicated `fiscal_year_close_runs` row (see Data & Tables Touched) whose `status` advances monotonically through the steps below. Every step that produces a number is drafted by an agent and screened by the shared SELF-CHECK discipline (source-citation completeness, confidence disclosure) described in the CFO Agent document; every step that could change a permanent financial fact is separated from its own approval gate — the agent never approves its own draft, and `accounting.ai.approve_draft` can never be held by an AI identity (General Ledger, Permissions).

```
 TRIGGER (event: fiscal_period.closed[period=12] | schedule: T-5d to fiscal_years.end_date | user_request)
   │
   ▼
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 0 · READINESS CHECK                    GENERAL_ACCOUNTANT + AUDITOR │
 │  Evaluate the 10 preconditions; compute readiness_score (AUTO)          │
 │  fiscal_year_close_runs created, status='readiness_check'               │
 └───────────────────────────────┬─────────────────────────────────────────┘
           score < floor ◄───────┤───────► score ≥ floor (default 95)
           HALT, notify Controller                │
           with unmet conditions                    ▼
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 1 · PERIOD FREEZE (system, AUTO)                                   │
 │  fiscal_years.status: open → closing                                    │
 │  Only entry_type IN ('adjusting','depreciation','tax','closing')        │
 │  accepted into this fiscal year from this point forward                 │
 └───────────────────────────────┬─────────────────────────────────────────┘
                                  ▼
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 2 · ADJUSTING ENTRIES (draft)          GENERAL_ACCOUNTANT          │
 │  Accruals, prepayment amortization, unearned-revenue recognition        │
 └───────────────────────────────┬─────────────────────────────────────────┘
                        ◄── GATE 1: Senior Accountant / Controller ──►
                        reject → revise & resubmit    approve ▼
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 3 · DEPRECIATION & AMORTIZATION RUN    GENERAL_ACCOUNTANT          │
 └───────────────────────────────┬─────────────────────────────────────────┘
                        ◄── GATE 2: Controller ──►
                                  ▼ approve
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 4 · PROVISIONS (ECL, inventory NRV, indemnity true-up, accruals)   │
 │           GENERAL_ACCOUNTANT drafts                                     │
 └───────────────────────────────┬─────────────────────────────────────────┘
                        ◄── GATE 3: Controller (+ CFO if > materiality) ──►
                                  ▼ approve
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 5 · TAX PROVISION                      TAX_ADVISOR drafts          │
 └───────────────────────────────┬─────────────────────────────────────────┘
                        ◄── GATE 4: Tax Manager + CFO ──►
                                  ▼ approve
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 6 · PRE-CLOSE (ADJUSTED) TRIAL BALANCE  system + AUDITOR (AUTO)     │
 │  SUM(debit) = SUM(credit)?  NO → HALT, return to Step 2                 │
 └───────────────────────────────┬─────────────────────────────────────────┘
                                  ▼ YES
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 7 · PRE-CLOSE AUDIT                     AUDITOR                    │
 │  Exception scan: unusual entries, related-party, override indicators    │
 │  Open material exception? → HALT / escalate, block Step 8               │
 └───────────────────────────────┬─────────────────────────────────────────┘
                                  ▼ no open exception (or formally waived)
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 8 · CLOSING ENTRY                       GENERAL_ACCOUNTANT drafts  │
 │  Sweep every Revenue & Expense account → Retained Earnings              │
 └───────────────────────────────┬─────────────────────────────────────────┘
                ◄── GATE 5: DUAL APPROVAL — Finance Manager AND CFO ──►
                                  ▼ both approve
                    POST /api/v1/accounting/fiscal-years/{id}/close
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 9 · STATUTORY / VOLUNTARY RESERVE TRANSFER (BR-08) REPORTING_AGENT │
 │  Dr Retained Earnings / Cr Statutory (or Voluntary) Reserve, capped     │
 └───────────────────────────────┬─────────────────────────────────────────┘
                        ◄── GATE 6: Finance Manager or CFO ──►
                                  ▼ approve — posts (entry_type='closing')
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 10 · FISCAL YEAR LEDGER CLOSE            system (AUTO)             │
 │  fiscal_years.status: closing → closed; closed_at/closed_by set        │
 │  emits: year.closed  — no separate gate: mechanical consequence of     │
 │  Steps 8+9 having posted (General Ledger BR-12: unblocks FY N+1        │
 │  ordinary posting); unblocks Trial Balance's own `post_closing`        │
 │  precondition (Trial Balance BR-03: requires status='closed')          │
 └───────────────────────────────┬─────────────────────────────────────────┘
                                  ▼ AUTO, immediate
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 11 · POST-CLOSING TRIAL BALANCE (PCTB)  system + AUDITOR (AUTO)     │
 │  All Revenue/Expense = 0? Assets = Liabilities + Equity?  NO → HALT,    │
 │  diagnose, reverse the offending Step 8/9 entry, redraft, re-run        │
 └───────────────────────────────┬─────────────────────────────────────────┘
                                  ▼ YES
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 12 · STATUTORY FINANCIAL STATEMENTS     REPORTING_AGENT            │
 │  is_statutory_filing = true; template/currency version locked (VR-10)   │
 └───────────────────────────────┬─────────────────────────────────────────┘
                                  ▼
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 13 · AUDIT PACK ASSEMBLY                AUDITOR + REPORTING_AGENT  │
 └───────────────────────────────┬─────────────────────────────────────────┘
                                  ▼
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 14 · COMPLIANCE SIGN-OFF                 COMPLIANCE_AGENT          │
 │  compliance.block.period_close clears, or a logged CFO/Owner override   │
 └───────────────────────────────┬─────────────────────────────────────────┘
                        ◄── GATE 7: Owner or CFO (final authority) ──►
                                  ▼ approve — releases the statutory filing
                                     package and unblocks Step 15
 ┌─────────────────────────────────────────────────────────────────────────┐
 │ STEP 15 · CLOSE RUN FINALIZED & NEW FISCAL YEAR OPENING    system        │
 │  fiscal_year_close_runs.status → 'locked'                                │
 │  FY N+1: opening_balance entry carries every FINAL (post-PCTB) Balance  │
 │  Sheet closing balance forward into period 1; emits fiscal_year.opened  │
 └─────────────────────────────────────────────────────────────────────────┘
```

Every Approval Assistant gate (GATE 1–7) is mechanically identical in structure regardless of which step it follows: the Approval Assistant creates a routed approval task addressed to the required role(s), starts an SLA timer (SLAs & Timing), and blocks the workflow's advance to the next step until every required approver has acted. A rejection at any gate returns the workflow to the owning agent for redrafting — it never auto-retries with the same content, and it never silently skips the rejected item. GATE 5 (the Closing Entry) is the only gate requiring two distinct human approvers rather than one; the Approval Assistant enforces that the two approvals come from two different `user_id` values holding two different roles, not the same person acting twice.

**Why Step 10 has no gate of its own, and why this is not a loophole.** `fiscal_years.status: closing → closed` looks, superficially, like the single most consequential transition in the whole saga — but by the time the workflow reaches it, both entries that actually determine the year's final numbers (the Closing Entry at Step 8, the Reserve Transfer at Step 9) have already cleared their own human gates and posted. The status flip is bookkeeping metadata catching up to a fact two humans already approved; gating it a third time would be gating the same decision twice under two different names, which is exactly the kind of approval-fatigue anti-pattern the Approval Assistant's own design (see its agent-level document) exists to prevent. This sequencing is not a stylistic choice: `docs/accounting/TRIAL_BALANCE.md` Business Rule 3 requires `fiscal_years.status = 'closed'` (with the `closing`-type entries already posted) as a precondition for generating a `post_closing`-type snapshot at all — attempting Step 11 while the year is still `status = 'closing'` would return `422` from the Trial Balance API, not a soft warning. Placing Step 10 immediately after Step 9, before Steps 11–15, is therefore the only ordering that lets this workflow call the Trial Balance module's own endpoint legally. It also matches `docs/accounting/GENERAL_LEDGER.md` Business Rule 12 (Fiscal Year N+1 cannot open for ordinary transactional posting until Fiscal Year N's closing entries have posted) — Step 10 is precisely the moment that precondition clears, which is why day-to-day Sales/Purchasing/Banking activity for the new fiscal year is never blocked waiting on Steps 11–15's audit-and-compliance tail (see Edge Cases).

# Data & Tables Touched

## Tables this workflow reads (never writes to directly from the AI layer)

`companies`, `accounts`, `account_types`, `fiscal_years`, `fiscal_periods`, `journal_entries`,
`journal_lines`, `ledger_entries`, `customers`, `vendors`, `inventory_items`, `inventory_valuations`,
`fixed_assets`, `fixed_asset_depreciation_schedules`, `fixed_asset_disposals`, `bank_reconciliations`,
`payroll_runs`, `payroll_items`, `employees` (End-of-Service Indemnity accrual base), `tax_registrations`,
`tax_transactions`, `tax_returns`, `trial_balance_snapshots`, `trial_balance_snapshot_lines`,
`trial_balance_ai_findings`, `financial_statement_snapshots`, `financial_statement_templates`
(`materiality_threshold`), `compliance_requirements`, `compliance_filings`, `control_attestations`,
`cost_centers`, `projects`, `departments`, `period_close_runs` / `period_close_tasks` (Month-End Close's
own orchestration record — read, never written, to confirm all twelve are `locked`), `ai_memory`, `ai_logs`,
`ai_decisions`, `audit_logs`.

## Tables this workflow's participating agents write to, always through the Laravel API

`journal_entries` / `journal_lines` (new `draft` rows only, `entry_type IN ('adjusting', 'depreciation',
'tax', 'closing')` per the Step 1 freeze rule — posting remains the Posting Engine's sole responsibility,
invoked by a human action, never by an agent directly), `trial_balance_snapshots` /
`trial_balance_snapshot_lines` / `trial_balance_ai_findings` / `trial_balance_approvals`,
`financial_statement_snapshots` / `financial_statement_snapshot_lines` / `financial_statement_notes`,
`compliance_assessments` / `compliance_alerts` (AI-owned observability rows, exempt from the "never write
directly" rule for the same reason `ai_logs` is), `control_attestations` (Compliance Agent's own sign-off
row), `ai_decisions`, `ai_tasks`.

## Tables this document introduces: the workflow's own orchestration record

`period_close_runs`/`period_close_tasks` (Month-End Close) answer "where is June's close right now."
Nothing existing answers the parallel question this workflow needs — "where is FY2026's *year-end* close
right now, across seven agents, sixteen steps, and seven human gates, and which of the twelve prerequisite
period closes is it built on." `fiscal_year_close_runs` and `fiscal_year_close_tasks` are that record,
deliberately shaped as a sibling of `period_close_runs`/`period_close_tasks` rather than a reinvention of
them — same ownership model, same immutable-audit-trail posture, one level up in scope.

```sql
CREATE TYPE fiscal_year_close_status AS ENUM (
  'not_started', 'readiness_check', 'in_progress', 'closing_entry_pending_approval',
  'reserve_transfer_pending_approval', 'ledger_closed', 'finalizing', 'blocked',
  'locked', 'failed', 'rolled_back'
);

CREATE TYPE fiscal_year_close_task_type AS ENUM (
  'readiness_check', 'period_freeze', 'adjusting_entries', 'depreciation_amortization',
  'provisions', 'tax_provision', 'pre_close_trial_balance', 'pre_close_audit', 'closing_entry',
  'reserve_transfer', 'ledger_close', 'post_closing_trial_balance', 'statutory_financial_statements',
  'audit_pack_assembly', 'compliance_signoff', 'new_fiscal_year_opening'
);

CREATE TYPE fiscal_year_close_task_status AS ENUM (
  'pending', 'running', 'completed', 'skipped', 'failed', 'requires_human'
);

-- ai_task_trigger ('schedule','event','user_request','agent_request') is reused verbatim from
-- CFO_AGENT.md — not redefined here, per the platform-wide "shared enum, never redefined per
-- document" convention already established for ai_decision_type.

CREATE TABLE fiscal_year_close_runs (
    id                               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                       BIGINT NOT NULL REFERENCES companies(id),
    fiscal_year_id                   BIGINT NOT NULL REFERENCES fiscal_years(id),
    status                           fiscal_year_close_status NOT NULL DEFAULT 'not_started',
    triggered_by                     ai_task_trigger NOT NULL,
    readiness_score                  NUMERIC(5,2) NULL CHECK (readiness_score BETWEEN 0 AND 100),
    readiness_unmet_conditions       JSONB NOT NULL DEFAULT '[]',
    started_at                       TIMESTAMPTZ NULL,
    closing_entry_id                 BIGINT NULL REFERENCES journal_entries(id),
    reserve_transfer_entry_id        BIGINT NULL REFERENCES journal_entries(id),
    ledger_closed_at                 TIMESTAMPTZ NULL,
    post_closing_trial_balance_id    BIGINT NULL REFERENCES trial_balance_snapshots(id),
    financial_statement_snapshot_ids JSONB NOT NULL DEFAULT '[]',
    audit_pack_url                   VARCHAR(500) NULL,        -- signed R2 URL to the assembled bundle
    audit_pack_generated_at          TIMESTAMPTZ NULL,
    compliance_signed_off_by         BIGINT NULL REFERENCES users(id),
    compliance_signed_off_at         TIMESTAMPTZ NULL,
    next_fiscal_year_id              BIGINT NULL REFERENCES fiscal_years(id),
    locked_at                        TIMESTAMPTZ NULL,
    rollback_reason                  TEXT NULL,
    attempt_number                   SMALLINT NOT NULL DEFAULT 1,
    created_by                       BIGINT NULL REFERENCES users(id),
    updated_by                       BIGINT NULL REFERENCES users(id),
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                       TIMESTAMPTZ NULL
);
CREATE INDEX idx_fycr_company_status ON fiscal_year_close_runs (company_id, status);
CREATE INDEX idx_fycr_year ON fiscal_year_close_runs (fiscal_year_id);
-- Only one NON-TERMINAL run per fiscal year at a time (partial unique index, not a plain UNIQUE
-- constraint) — this is what lets a 'rolled_back' or 'failed' run be superseded by a fresh
-- attempt_number without ever deleting the prior row's audit trail:
CREATE UNIQUE INDEX uq_fycr_year_active ON fiscal_year_close_runs (fiscal_year_id)
    WHERE status NOT IN ('locked', 'rolled_back', 'failed') AND deleted_at IS NULL;

CREATE TABLE fiscal_year_close_tasks (
    id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fiscal_year_close_run_id  BIGINT NOT NULL REFERENCES fiscal_year_close_runs(id) ON DELETE CASCADE,
    company_id                BIGINT NOT NULL REFERENCES companies(id),
    task_type                 fiscal_year_close_task_type NOT NULL,
    sequence_order             SMALLINT NOT NULL,             -- 0..15, matches Orchestration Step N
    status                     fiscal_year_close_task_status NOT NULL DEFAULT 'pending',
    owning_agent_code          VARCHAR(40) NULL REFERENCES ai_agents(code),  -- NULL = pure system step
    ai_task_id                 BIGINT NULL REFERENCES ai_tasks(id),
    blocking                   BOOLEAN NOT NULL DEFAULT true,
    gate_label                 VARCHAR(10) NULL,               -- 'GATE_1' .. 'GATE_7'; NULL for AUTO steps
    started_at                 TIMESTAMPTZ NULL,
    completed_at               TIMESTAMPTZ NULL,
    attempt_count              SMALLINT NOT NULL DEFAULT 0,
    error_message              TEXT NULL,
    notes                      TEXT NULL,
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_fyct_run_type UNIQUE (fiscal_year_close_run_id, task_type)
);
CREATE INDEX idx_fyct_run ON fiscal_year_close_tasks (fiscal_year_close_run_id, sequence_order);
CREATE INDEX idx_fyct_status ON fiscal_year_close_tasks (company_id, status)
    WHERE status IN ('failed', 'requires_human');
```

`fiscal_year_close_tasks.sequence_order` maps 1:1 onto the sixteen boxes in the Orchestration diagram (0
through 15); a company's Close Command Center renders this table directly as the year-end checklist a
Finance Manager sees, exactly as `period_close_tasks` already does one level down for a single month.

## New columns this document adds to `companies`

Trigger & Preconditions item 9 already named four of these; the full set this workflow requires to run
without any hardcoded per-company assumption is:

```sql
ALTER TABLE companies
    ADD COLUMN statutory_reserve_account_id      BIGINT NULL REFERENCES accounts(id),
    ADD COLUMN voluntary_reserve_account_id       BIGINT NULL REFERENCES accounts(id),
    ADD COLUMN income_tax_expense_account_id      BIGINT NULL REFERENCES accounts(id),
    ADD COLUMN income_tax_payable_account_id      BIGINT NULL REFERENCES accounts(id),
    ADD COLUMN statutory_reserve_rate             NUMERIC(5,4) NOT NULL DEFAULT 0.1000,
    ADD COLUMN statutory_reserve_cap_pct_capital  NUMERIC(5,4) NOT NULL DEFAULT 0.5000,
    ADD COLUMN is_shareholding_company            BOOLEAN NOT NULL DEFAULT false,
    ADD COLUMN foreign_ownership_pct               NUMERIC(5,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN year_end_close_readiness_floor      NUMERIC(5,2) NOT NULL DEFAULT 95.00,
    ADD COLUMN year_end_close_lead_days            SMALLINT NOT NULL DEFAULT 5;
```

`statutory_reserve_rate` (default 10%) and `statutory_reserve_cap_pct_capital` (default 50%) are seeded
from Kuwait's Commercial Companies Law figures already cited in `docs/accounting/CHART_OF_ACCOUNTS.md`, but
are per-company columns, not a hardcoded constant, precisely because a KSA- or UAE-incorporated tenant's
equivalent statutory rule (or the absence of one) differs — the Reporting Agent's Step 9 draft always reads
these columns, never a jurisdiction literal in code. `is_shareholding_company = false` (the default) means
BR-08's reserve transfer is *voluntary-only* for that company: the Reporting Agent still drafts a proposal
if the Board has a standing voluntary-reserve policy in `ai_memory`, but Step 9 auto-skips with
`fiscal_year_close_tasks.status = 'skipped'` and no draft at all if no such policy exists, rather than
silently applying a mandatory-company rule to a company it does not apply to (see Edge Cases).

# Journal Entries Produced

Every entry below follows the Journal Entries submodule's own shape (`journal_entries` + `journal_lines`,
`NUMERIC(19,4)` amounts) and its own AI-origin rule: `created_by_agent` set, `ai_confidence` populated
0–1, `ai_reasoning` populated, `status = 'draft'` on creation, never anything closer to `posted` until a
human acts. The worked numbers below belong to **Al-Wasat Consumer Holding K.S.C.P.** (`company_id`
5190), the running example for this document (introduced in full under Worked Example) — shown here so
the two sections can cite the same entries rather than each inventing its own illustrative numbers.

| # | Entry | `entry_type` | Typical Debit | Typical Credit | Drafted By | Confidence driver |
|---|---|---|---|---|---|---|
| 1 | Prepaid/accrual true-up (adjusting) | `adjusting` | Various Expense | Prepaid Asset / Accrued Expense | `general_accountant` | Document-match strength; lower when estimated |
| 2 | Depreciation & amortization (annual true-up over the year's schedule) | `depreciation` | Depreciation Expense (by cost center) | Accumulated Depreciation (contra-asset) | `general_accountant`, from `fixed_asset_depreciation_schedules` | 0.95–1.00 — formulaic once the asset register and useful-life inputs are current |
| 3 | Expected Credit Loss (ECL) provision top-up | `adjusting` | Bad Debt / ECL Expense | Allowance for Expected Credit Losses (contra-AR) | `general_accountant`, cross-checked by `auditor` | Statistical (aging-bucket default rates); moderate confidence, always human-reviewed |
| 4 | Inventory Net Realizable Value (NRV) write-down | `adjusting` | Inventory Write-down Expense | Inventory (or Inventory Valuation Reserve) | `general_accountant`, sourced from Inventory module's NRV check | High when a recent sale price / vendor quote exists; lower when modeled from aging alone |
| 5 | End-of-Service Indemnity (EOSI) true-up | `adjusting` | Employee Benefits Expense | End-of-Service Indemnity Provision (liability) | `general_accountant`, from Payroll's per-employee accrual | High — deterministic formula off tenure and last-drawn salary |
| 6 | Tax/levy provision (Corporate Tax, Zakat, NLST, KFAS — whichever apply) | `tax` | Income Tax / Zakat / NLST / KFAS Expense | Income Tax Payable / Zakat Payable / NLST Payable / KFAS Payable | `tax_advisor` | High on the arithmetic once taxable-profit inputs are confirmed; disclosed as an estimate until the return itself is prepared |
| 7 | **Closing Entry** | `closing` | Every Revenue account (credit balance, zeroed) | Every Expense account (debit balance, zeroed); net **Cr Retained Earnings** | `general_accountant`, system-templated | 0.99+ — deterministic sweep of already-posted balances, not a judgment call |
| 8 | **Statutory Reserve Transfer (BR-08)** | `closing` | Retained Earnings | Statutory Reserve (capped) or Voluntary Reserve | `reporting_agent` | 1.00 on the arithmetic (rate × net profit, capped); the only judgment is *whether* a voluntary transfer should exceed the mandatory minimum, which stays human-decided |
| 9 | New Fiscal Year Opening Balance | `opening_balance` | Every asset/contra-liability closing balance | Every liability/equity closing balance, including the just-updated Retained Earnings and Statutory Reserve | System-generated (no agent; deterministic carry-forward) | N/A — not AI-originated, no confidence field |

**Entry 7 — the Closing Entry, drafted at Step 8, before Gate 5:**

```json
{
  "entry_type": "closing",
  "journal_date": "2026-12-31",
  "fiscal_period_id": 4472,
  "description": "FY2026 Closing Entry — sweep Revenue and Expense to Retained Earnings",
  "reference": "YEC-2026-FY-CLOSE",
  "lines": [
    { "account_id": 4000, "debit": "8240000.0000", "description": "Net Revenue — FY2026 (zeroed)" },
    { "account_id": 5100, "credit": "5120000.0000", "description": "Cost of Sales — FY2026 (zeroed)" },
    { "account_id": 6000, "credit": "1878400.0000", "description": "Operating Expenses incl. Q4 accrual/deferral true-up — FY2026 (zeroed)" },
    { "account_id": 6210, "credit": "42500.0000",  "description": "Depreciation Expense — FY2026 (zeroed)" },
    { "account_id": 6330, "credit": "71950.0000",  "description": "ECL + Inventory NRV + EOSI provisions — FY2026 (zeroed)" },
    { "account_id": 6910, "credit": "67629.0000",  "description": "Corporate Tax + Zakat + NLST + KFAS expense — FY2026 (zeroed)" },
    { "account_id": 3400, "credit": "1059521.0000", "description": "Net Profit for FY2026 → Retained Earnings" }
  ],
  "meta": {
    "ai_generated": true, "ai_agent": "general_accountant", "confidence": 0.99,
    "reasoning": "Deterministic sweep: every Revenue and Expense account's FY2026 closing balance, per the Adjusted (post-provision, post-tax-provision) Trial Balance approved at Step 6/Gate 4, zeroed against Retained Earnings. Total debits 8,240,000.0000 = total credits 8,240,000.0000. No estimation in this entry itself — every zeroed balance traces to an already-posted, already-approved source line.",
    "source_documents": [
      { "type": "trial_balance_snapshots", "id": 91982, "label": "FY2026 Adjusted Trial Balance, approved 2027-01-07" }
    ]
  }
}
```

Debits: 8,240,000.0000. Credits: 5,120,000.0000 + 1,878,400.0000 + 42,500.0000 + 71,950.0000 +
67,629.0000 + 1,059,521.0000 = 8,240,000.0000. Balanced to the fils.

**Entry 8 — the Statutory Reserve Transfer, drafted at Step 9, before Gate 6, showing the cap binding:**

```json
{
  "entry_type": "closing",
  "journal_date": "2026-12-31",
  "fiscal_period_id": 4472,
  "description": "FY2026 Statutory Reserve transfer — Kuwait CCL 10%-of-net-profit rule, capped at 50% of Share Capital",
  "reference": "YEC-2026-FY-RESERVE",
  "lines": [
    { "account_id": 3400, "debit": "90000.0000",  "description": "Retained Earnings" },
    { "account_id": 3210, "credit": "90000.0000", "description": "Statutory Reserve" }
  ],
  "meta": {
    "ai_generated": true, "ai_agent": "reporting_agent", "confidence": 1.00,
    "reasoning": "companies.statutory_reserve_rate = 10.00% x FY2026 net profit 1,059,521.0000 = 105,952.1000 (the uncapped transfer). companies.statutory_reserve_cap_pct_capital = 50.00% x Share Capital 6,000,000.0000 = 3,000,000.0000 (the cap). Statutory Reserve's balance immediately before this entry is 2,910,000.0000; only 90,000.0000 of room remains under the cap (3,000,000.0000 - 2,910,000.0000). The transfer is capped at 90,000.0000, not the full 105,952.1000 the rate alone would produce. No voluntary top-up beyond the statutory minimum is proposed, per this company's ai_memory policy (no standing voluntary-reserve election on file).",
    "source_documents": [
      { "type": "accounts", "id": 3210, "label": "Statutory Reserve account, balance 2,910,000.0000 pre-transfer" },
      { "type": "companies", "id": 5190, "label": "statutory_reserve_rate=0.1000, statutory_reserve_cap_pct_capital=0.5000, share capital account balance 6,000,000.0000" }
    ]
  }
}
```

This is the entry the task's "retained-earnings rollover" traces through in full: **Retained Earnings
opens FY2026 at 2,940,300.0000 → +1,059,521.0000 (Entry 7, this year's net profit) = 3,999,821.0000 →
-90,000.0000 (Entry 8, capped reserve transfer) = 3,909,821.0000, the balance the New Fiscal Year Opening
entry (Step 15) carries into FY2027.** Statutory Reserve moves 2,910,000.0000 → 3,000,000.0000 — now
sitting exactly at its 50%-of-capital ceiling, which the Reporting Agent's next fiscal year draft will
read and, correctly, propose a **zero** transfer for FY2027 regardless of that year's net profit, until
Share Capital itself increases and raises the cap (see Edge Cases).

**Why the Reserve Transfer is `entry_type = 'closing'`, not `'adjusting'`.** It is not correcting an
estimate and it does not touch a single Income Statement account — like the Closing Entry itself, it only
exists because the fiscal year is ending, is only computable once the Closing Entry has posted (it needs
the *final* net profit and the *final* Retained Earnings balance as inputs), and is subject to the same
Step 1 freeze rule (`entry_type IN ('adjusting','depreciation','tax','closing')`). Classifying it as
`'closing'` alongside the sweep entry itself keeps both of the year's equity-affecting postings under the
one `entry_type` a Post-Closing Trial Balance's own precondition checks for (Trial Balance BR-03).

# Events Emitted/Consumed

| Event | Direction | Producer | Primary Consumers | Payload highlights |
|---|---|---|---|---|
| `fiscal_period.closed` (`period=12`) | Consumed | Month-End Close (General Ledger) | Step 0 readiness check, event-trigger path | `fiscal_period_id`, `fiscal_year_id`, `period_number=12` |
| `fiscal_year_close.readiness_failed` | Emitted (Step 0) | Year-End Close orchestration service | Finance Manager/Controller notification, Approval Assistant | `readiness_score`, `readiness_unmet_conditions` |
| `fiscal_year_close.started` | Emitted (Step 1) | Year-End Close orchestration service | Approval Assistant, all seven participating agents (subscribe to begin their own draft work) | `company_id`, `fiscal_year_id`, `fiscal_year_close_run_id` |
| `journal.posted` (`entry_type` adjusting/depreciation/tax) | Consumed | Accounting (Posting Engine), per approved Steps 2–5 | Step 6 pre-close Trial Balance regeneration trigger | `journal_entry_id`, `entry_type`, `total_debit` |
| `trial_balance.generated` (`type='adjusted'`) | Consumed | Trial Balance module (Step 6) | Auditor (Step 7), Fraud Detection | `snapshot_id`, `is_balanced` |
| `fiscal_year_close.closing_entry_drafted` | Emitted (Step 8) | Year-End Close orchestration service | Approval Assistant → GATE 5 (Finance Manager + CFO) | `journal_entry_id`, `net_profit`, `confidence` |
| `fiscal_year_close.reserve_transfer_drafted` | Emitted (Step 9) | Year-End Close orchestration service | Approval Assistant → GATE 6 | `journal_entry_id`, `transfer_amount`, `capped: true\|false` |
| **`year.closed`** | Emitted (Step 10, mechanical) | Year-End Close orchestration service, immediately after Steps 8+9 post | Trial Balance module (unlocks `post_closing` generation), Reporting Agent, Tax Advisor, CFO, Forecast Agent, every module subscribed to fiscal-year boundaries | see payload below |
| `trial_balance.generated` (`type='post_closing'`) | Consumed | Trial Balance module (Step 11) | Auditor, Step 12 statement-generation trigger | `snapshot_id`, `is_balanced`, `revenue_expense_zeroed` |
| `financial_statement.finalized` (`period_type='yearly'`, `is_statutory_filing=true`) | Emitted (Step 12) | Financial Statements module | Auditor (Step 13), CFO, Tax Advisor (annual return preparation), external systems if configured | `snapshot_ids[]`, `fiscal_year_id` |
| `fiscal_year_close.audit_pack_ready` | Emitted (Step 13) | Year-End Close orchestration service | Compliance Agent (Step 14), Finance Manager, External Auditor role if configured | `audit_pack_url`, `contents_manifest` |
| `compliance.block.period_close` | Emitted conditionally (Step 14) | Compliance Agent, on a statutory prerequisite gap | Finance Manager → CFO → Owner escalation chain (24h SLA, per `COMPLIANCE_AGENT.md`) | `blocking_reason`, `compliance_requirement_id` |
| `fiscal_year_close.compliance_signed_off` | Emitted (Step 14, GATE 7 clears) | Year-End Close orchestration service | Approval Assistant, Step 15 trigger | `signed_off_by`, `signed_off_at` |
| **`fiscal_year.opened`** | Emitted (Step 15, terminal) | Year-End Close orchestration service | CFO, Forecast Agent (next-year opening budget seed), Tax Advisor, Reporting Agent, every module subscribed to fiscal-year boundaries | `fiscal_year_id` (the new year), `opening_balance_entry_id` |
| `fiscal_year_close.locked` | Emitted (Step 15, terminal) | Year-End Close orchestration service | Approval Assistant (closes the Close Approval Queue for this year), Auditor (starting point of any later statutory audit engagement) | `fiscal_year_close_run_id`, `total_elapsed_business_days` |

**`year.closed` payload example** (fired at Step 10, the moment `fiscal_years.status` becomes `'closed'` —
deliberately early, so downstream consumers that only care that the ledger is final and immutable, not
that the audit/compliance tail has finished, are not held up unnecessarily):

```json
{
  "event": "year.closed",
  "company_id": 5190,
  "fiscal_year_id": 4410,
  "fiscal_year_close_run_id": 8801,
  "fiscal_year_name": "FY2026",
  "closing_entry_id": 771042,
  "reserve_transfer_entry_id": 771043,
  "net_profit_base_currency": "1059521.0000",
  "retained_earnings_account_id": 3400,
  "retained_earnings_closing_balance": "3909821.0000",
  "closed_at": "2027-01-08T15:20:00+03:00",
  "occurred_at": "2027-01-08T15:20:00+03:00"
}
```

**`fiscal_year.opened` payload example** (fired at Step 15, once Compliance Sign-off/GATE 7 clears — the
event every downstream forecasting or budgeting process should actually wait for, since it is the one that
guarantees the opening balances it will read are the audited-final ones, not a provisional figure):

```json
{
  "event": "fiscal_year.opened",
  "company_id": 5190,
  "fiscal_year_id": 4550,
  "fiscal_year_name": "FY2027",
  "previous_fiscal_year_id": 4410,
  "opening_balance_entry_id": 771090,
  "fiscal_year_close_run_id": 8801,
  "occurred_at": "2027-01-11T10:05:00+03:00"
}
```

Two events — `year.closed` and `fiscal_year.opened` — deliberately do not fire at the same moment, and a
consumer's choice of which one to subscribe to is itself meaningful: subscribe to `year.closed` for
anything that only needs "the numbers for this year are now final and will never change again in place"
(e.g., a lender's covenant-check integration polling for year-end figures); subscribe to
`fiscal_year.opened` for anything that needs the *new* year's opening position to already reflect the
fully-reviewed, compliance-cleared close (e.g., the Forecast Agent's next-year budget seed, which would
otherwise have to guess whether a Retained Earnings figure it read was still subject to late audit
adjustment).

# Confidence & Escalation Rules

## The Year-End Close Readiness Score

`fiscal_year_close_runs.readiness_score` is the Step 0 composite already introduced in Trigger &
Preconditions — ten independently-verifiable conditions, weighted, recomputed every time Step 0 runs
(once on the T-5 schedule, again on the `fiscal_period.closed[period=12]` event if the first attempt was
below floor):

```
readiness_score =
    20 x (all_12_periods_closed ? 1 : partial_fraction_closed)          -- condition 1
  + 15 x (ledger_balanced ? 1 : 0)                                       -- condition 2
  + 10 x (bank_fully_reconciled_pct)                                     -- condition 3
  + 10 x (no_stray_drafts ? 1 : draft_free_fraction)                     -- condition 4
  + 10 x (fixed_assets_current ? 1 : 0)                                  -- condition 5
  + 10 x (payroll_current ? 1 : 0)                                       -- condition 6
  + 10 x (tax_current_or_disclosed_provisional ? 1 : 0)                  -- condition 7
  + 10 x (no_open_material_exception ? 1 : 0)                            -- condition 8
  +  3 x (special_accounts_configured ? 1 : 0)                           -- condition 9
  +  2 x (fiscal_year_state_valid ? 1 : 0)                               -- condition 10
```

This is a **gate**, unlike Month-End Close's own `close_score` (a progress indicator only) — a
`readiness_score` below `companies.year_end_close_readiness_floor` (default 95) halts the workflow at
Step 0 and does not create the downstream `fiscal_year_close_tasks` rows at all. This asymmetry is
intentional: a month's close-readiness figure is informative because the workflow proceeds regardless and
a human simply sees where it stands; Year-End Close's readiness score is load-bearing because the twelve
prerequisite conditions (all periods closed, fixed assets current, payroll current, tax current) are
themselves outputs of twelve separate Month-End Close runs and a fiscal-year-scoped tax/payroll cycle —
if any is genuinely unmet, every subsequent step's output (the Closing Entry's net profit figure chief
among them) would be computed against an incomplete year, which this workflow refuses to do silently.

## Per-artifact confidence bands

| Artifact | Confidence source | Auto-advance? | Human gate |
|---|---|---|---|
| Adjusting/accrual true-up draft (Step 2) | Same document-match / precedent-strength formula as Month-End Close's own accrual drafts (`ACCOUNTANT_AGENT.md`) | Never — `entry_type='adjusting'` always requires >=1 approval | Senior Accountant/Controller; CFO if >= Tier-2 (KWD 5,000) |
| Depreciation run (Step 3) | 0.95-1.00 — formulaic once the asset register is current; the only variance is a mid-year useful-life or method change requiring judgment | Never auto-posts | Controller, then CFO/Owner if the annual charge >= Tier-2 (in practice, almost always) |
| ECL / NRV / EOSI provisions (Step 4) | 0.55-0.85 typical — these are estimates against statistical models or aging buckets, disclosed as such in `ai_reasoning`, never presented with false precision | Never auto-posts | Controller; CFO co-approval mandatory whenever any single provision exceeds `financial_statement_templates.materiality_threshold` |
| Tax/levy provision (Step 5) | 0.85-1.00 on the arithmetic once taxable-profit inputs are confirmed (KFAS/NLST/Zakat rates and the corporate-tax foreign-ownership split are structural facts once `companies.foreign_ownership_pct` is set); lower only if a specific input is itself disputed (e.g., an unresolved related-party pricing question) | Never auto-posts | Tax Manager + CFO (GATE 4) |
| Pre-close Adjusted Trial Balance (Step 6) | Deterministic — `SUM(debit) = SUM(credit)` either holds or does not | A failing balance is not a confidence question, it is a hard HALT back to Step 2 | N/A — this is a system check, not an approval |
| Pre-close audit exception scan (Step 7) | Per `AUDITOR_AGENT.md`'s own category-specific confidence bands; a `control_violation` or `segregation_of_duties` finding is treated as a rule violation (near-certain), not a soft signal | A Critical/High finding hard-blocks Step 8 regardless of confidence | Finance Manager/Auditor role clears or the Owner formally waives with a logged reason |
| **Closing Entry (Step 8)** | 0.99+ — a deterministic sweep of already-posted, already-approved balances; the number is not "estimated," it is "totaled" | Never auto-posts, at any confidence, any amount | **GATE 5 — dual approval: Finance Manager AND CFO**, from two distinct `user_id`s |
| **Reserve Transfer (Step 9)** | 1.00 on the rate x profit x cap arithmetic; the only non-mechanical element (a voluntary top-up beyond the statutory minimum) is explicitly called out as a recommendation, not folded into the "confidence" figure | Never auto-posts | GATE 6 — Finance Manager or CFO |
| Post-Closing Trial Balance (Step 11) | Deterministic — Revenue/Expense = 0 and the balance-sheet identity either hold or do not | A failure is a hard HALT (see Failure, Retry & Rollback); it is never silently accepted at a lower confidence | N/A — system check |
| Statutory Financial Statements (Step 12) | Deterministic on VR-01/VR-03 (Financial Statements module); the CFO's accompanying narrative carries its own separately-disclosed confidence, per `CFO_AGENT.md` | VR-01/VR-03 failure blocks `final` status entirely | Finance Manager/CFO before `final`; External Auditor engagement is separate and later |
| Audit Pack completeness (Step 13) | Checklist-based, not probabilistic — every required document class either has a linked artifact or it does not | An incomplete pack blocks Step 14 | Auditor confirms completeness before Compliance Sign-off begins |
| Compliance attestation (Step 14) | Self-consistency check: an LLM-grounded statutory-prerequisite pass and an independent rule-engine pass must agree before a `compliance.block.period_close` flag is ever raised, exactly as `COMPLIANCE_AGENT.md` specifies for its own gates | A single-pass disagreement downgrades to advisory, never silently to blocking | **GATE 7 — Owner or CFO**, the platform's top-of-chain authority |

## Escalation ladder

| Condition | Escalates to | Timing |
|---|---|---|
| `readiness_score` below floor at Step 0 | Finance Manager/Controller immediately; re-evaluated automatically on the next `fiscal_period.closed[period=12]` event | Immediate, non-blocking notification |
| A provision (Step 4) exceeds `financial_statement_templates.materiality_threshold` | CFO, in addition to the Controller's routine review | Immediate |
| Tax Advisor confidence < 0.70 on any component of the tax/levy provision | Tax Manager AND Auditor queues simultaneously, priority "high" — mirrors `TAX_AGENT.md`'s own materiality-weighted escalation rule verbatim | Immediate |
| Auditor raises a Critical/High finding at Step 7 (pre-close) or during Step 11/13 review | Finance Manager, CFO, Auditor role simultaneously; hard-blocks the next step | Immediate |
| `compliance.block.period_close` raised at Step 14 | Finance Manager -> CFO -> Owner, per `COMPLIANCE_AGENT.md`'s own escalation chain | 24h per level |
| GATE 5 (Closing Entry, dual approval) unresolved | Both required approvers reminded at 50% of SLA; escalates to Owner if either seat has no response by the SLA ceiling | Per SLAs & Timing |
| Full cycle (Step 1 to Step 10, ledger close) exceeds its SLA ceiling | CFO and Auditor role, as a standing risk item | See SLAs & Timing |
| Full cycle (Step 1 to Step 15, fully locked) exceeds twice its target | Owner, as a governance escalation independent of the underlying cause | See SLAs & Timing |

A `readiness_score` at or above floor, or a per-artifact confidence at the top of its band, never
substitutes for a required gate — every GATE 1-7 fires regardless of how confident the upstream agent
was. Confidence changes *how much explanation and which reviewer tier* a human sees; it never changes
*whether* a human is asked.

# Failure, Retry & Rollback

**Failure is task-scoped, exactly as in Month-End Close.** A single `fiscal_year_close_tasks` row failing
— the Tax Advisor's provision computation timing out, a tool call to the Reporting Agent's statement
generator erroring — does not fail the whole `fiscal_year_close_runs` row. The task retries with
exponential backoff up to `max_attempts` (platform default 3), incrementing `attempt_count`; only once
retries are exhausted does the task move to `status = 'failed'` and, since every task in this workflow is
`blocking = true` by default (Year-End Close has no informational-only steps the way a company might mark
Month-End Close's departmental variance review), the run itself moves to `fiscal_year_close_status =
'blocked'` and the Controller/Finance Manager is notified with the specific `error_message`.

**Retries are idempotent by construction**, inherited from the platform-wide rule: a retried
`propose_journal_entry` call after a timeout does not produce a second draft Closing Entry; a retried
Trial Balance or Financial Statement generation call for the same scope produces the same version, not a
spurious new one, unless the underlying `journal_lines` genuinely changed between attempts.

**A rejection at any gate is not a failure — it is a redraft loop, by design.** If GATE 5's Finance
Manager rejects the Closing Entry (say, a late-discovered unposted bill changes the expense total), the
workflow does not retry the same draft: `fiscal_year_close_tasks` for `closing_entry` returns to
`pending`, the underlying issue is fixed upstream (the bill posts, Step 6's Trial Balance regenerates),
and the General Accountant redrafts from the corrected Adjusted Trial Balance. The same pattern applies to
every gate; the Approval Assistant never resubmits identical content hoping for a different answer.

## Rollback never means deletion — but Year-End Close has one rollback boundary Month-End Close does not

Everything below the ledger — a `draft` entry not yet approved, a not-yet-generated snapshot — is cheap to
undo exactly as in Month-End Close: discard the draft, regenerate the snapshot. Above the ledger, the
platform's immutability rule applies, but Year-End Close introduces a genuine one-way door that Month-End
Close's own period-level rollback does not have:

- **Before Step 10 (ledger still `status = 'closing'`).** A wrong Closing Entry or Reserve Transfer
  discovered before either has posted is simply corrected in its own draft and re-submitted to its gate —
  no different from correcting any other pre-post draft.
- **Between Step 8/9 posting and Step 10 (a vanishingly short, but real, window).** If a defect is caught
  in this window — before the mechanical `closing -> closed` transition has fired — the fix is to reverse
  the just-posted Closing Entry and/or Reserve Transfer with a same-day reversing entry (still dated inside
  the still-`closing` fiscal year, `entry_type = 'closing'`, `reverses_entry_id` set), then redraft and
  re-post the correct version, then let Step 10 proceed against the corrected figures. `fiscal_year_close_runs`
  logs both the failed and the corrected attempt; nothing is hidden.
- **After Step 10 (`fiscal_years.status = 'closed'`).** This is the one-way door. Unlike `fiscal_periods`,
  which has an explicit, permissioned reopen procedure (`accounting.period.reopen`, per Journal Entries), the
  canonical `fiscal_year_status` enum (`future`, `open`, `closing`, `closed`) has **no reverse transition** —
  `docs/accounting/GENERAL_LEDGER.md` is explicit that "a mis-timed year-end close is undone by reversing the
  Closing Entry, not by editing it," and there is no fifth state to fall back to. Concretely, if Step 11's
  Post-Closing Trial Balance fails validation (Revenue/Expense not actually zero, or the balance-sheet
  identity does not hold) *after* Step 10 has already fired:
  1. The workflow halts at Step 11 (`fiscal_year_close_tasks` for `post_closing_trial_balance` moves to
     `requires_human`; `fiscal_year_close_runs.status = 'blocked'`); the Auditor is paged immediately, since
     a PCTB failure this late is treated as a system-integrity incident, not a routine finding.
  2. The Auditor and General Accountant jointly root-cause the specific offending line — in practice almost
     always a race condition (a transaction posted into the fiscal year between Step 6's snapshot and Step
     8's sweep) or a configuration defect (an account flagged the wrong `account_type`, so the Closing
     Entry's sweep logic skipped it).
  3. The correction posts as a **reversing entry plus a corrected replacement**, dated on the first day of
     the (not-yet-formally-opened) next fiscal period — which the platform permits into a period still
     `status = 'future'` under an elevated, logged, Owner/CFO-only exception precisely for this narrow
     year-end-correction case (mirroring, at the fiscal-year level, the same "reversing entries may date into
     an adjacent period under elevated permission" pattern Month-End Close already uses for prior-period
     corrections one level down).
  4. Step 11 re-runs against the corrected figures. `fiscal_year_close_runs.rollback_reason` records the
     full narrative; the original failed attempt's audit trail is never deleted, only superseded.
  This path is rare by design — Step 6's Adjusted Trial Balance and Step 7's pre-close audit exist
  specifically to catch what would otherwise surface here, expensively, after the ledger is already closed.

- **Un-doing the Statutory Reserve Transfer specifically.** Because it is computed *from* the Closing
  Entry's net-profit output, a correction to the Closing Entry always invalidates the Reserve Transfer
  computed against it; the workflow never lets a corrected Closing Entry stand next to an un-recomputed
  Reserve Transfer. Reversing Entry 7 (Journal Entries Produced) automatically flags Entry 8 as
  `superseded_by_correction` and re-queues Step 9 for the Reporting Agent once the corrected Closing Entry
  posts.

**A failed close never leaves a partial, malformed artifact.** Nothing in this workflow persists a
financial fact until its own module's terminal write succeeds — a draft is not posted until a human
approves it, `fiscal_years.status` does not flip until both Step 8 and Step 9 have genuinely posted. An
interrupted run (a server restart mid-saga, a queue outage during Step 12's statement generation) resumes
from the last `completed` `fiscal_year_close_tasks` row rather than reprocessing finished work or leaving
a half-swept ledger behind.

**A close that must be abandoned entirely** (vanishingly rare — e.g., the company itself is dissolved
mid-close, or a restatement is ordered that invalidates the entire year's adjusting-entry set) moves
`fiscal_year_close_runs.status` to `'rolled_back'` with a mandatory `rollback_reason`, and requires Owner
sign-off distinct from any single gate above — the full history of the abandoned attempt remains
permanently queryable, and a fresh `fiscal_year_close_runs` row (`attempt_number` incremented) can be
initiated once the underlying issue is resolved — `uq_fycr_year_active`'s partial unique index allows
exactly one *non-terminal* run per fiscal year, so a new attempt is only possible once the prior one has
reached `'locked'`, `'rolled_back'`, or `'failed'`, never a second run racing an active one.

# SLAs & Timing

Year-End Close's timing target is deliberately expressed in **business days from the moment Period 12
locks**, not from the calendar fiscal year-end — the twelve individual Month-End Close cycles already
absorb the routine monthly work; this workflow's own clock starts only once its own precondition (all
twelve periods closed) is actually true.

| Step(s) | Target elapsed time (from Period 12 lock) | Escalation if missed |
|---|---|---|
| Step 0 — Readiness check | Immediate on the triggering event; a `schedule`-triggered early attempt (T-5 calendar days) is expected to fail and is not itself an SLA breach | Controller notified either way; only a post-event-trigger failure counts toward the SLA clock |
| Steps 2-5 — Adjusting, depreciation, provisions, tax provision drafted | p95 < 1 business day for previously-templated items (depreciation, recurring accruals, structural tax facts); p95 < 2 business days where a provision requires fresh statistical input (ECL aging, NRV) | Escalates to the review queue as stale after 2 business days unreviewed |
| Step 6 — Pre-close Adjusted Trial Balance approved | Same business day Steps 2-5 clear, target ≤ 1 business day after | Finance Manager notified after 24h |
| Step 7 — Pre-close audit | p95 < 30 minutes for the automated scan; Critical/High findings reviewed same business day | Auditor role notified if a finding sits unresolved > 24h |
| Step 8 — Closing Entry drafted, GATE 5 cleared | Target same business day as Step 7 clears; ceiling 2 business days for the dual approval specifically (two distinct approvers coordinating) | 50%-of-SLA reminder to both approvers; Owner notified if either seat is silent past the ceiling |
| Step 9 — Reserve Transfer drafted, GATE 6 cleared | Target same business day as Step 8 (computable immediately once the Closing Entry posts) | Finance Manager/CFO reminded after 24h |
| Step 10 — Ledger close | Immediate, mechanical, same transaction as Step 9's approval | — (no human action, nothing to escalate) |
| Step 11 — Post-Closing Trial Balance | p95 < 5 minutes (deterministic recomputation); a failure here is root-caused as an incident, not merely retried | Immediate Auditor page on failure |
| Step 12 — Statutory Financial Statements drafted | p95 < 90 seconds, matching the Reporting Agent's underlying Report Generation Engine performance target (same engine Month-End Close uses) | Investigated as a system defect if it regularly exceeds 5 minutes |
| Step 13 — Audit Pack assembled | p95 < 10 minutes (aggregation of already-generated artifacts, no new computation) | Auditor notified if assembly stalls > 1 hour |
| Step 14 — Compliance Sign-off, GATE 7 cleared | Target ≤ 2 business days from Audit Pack readiness; this is the step most likely to be gated by human availability (Owner/CFO calendar), not by system speed | Escalates per the escalation ladder in Confidence & Escalation Rules |
| Step 15 — Close run finalized, new fiscal year opened | Immediate, same transaction as GATE 7 clearing | — |
| **Steps 1-10 (ledger genuinely closed)** | **Target ≤ 3 business days; ceiling 5 business days** | CFO and Auditor role notified as a standing risk item past the ceiling |
| **Full cycle (Steps 1-15, fully locked)** | **Target ≤ 7 business days; ceiling 10 business days** | Owner notified as a governance escalation past the ceiling, independent of the specific cause |

These targets are deliberately looser than Month-End Close's own 3/5-business-day cycle, in direct
proportion to the added weight of Year-End Close's tax provision, statutory reserve computation, full
Audit Pack assembly, and Compliance Sign-off — none of which a monthly cycle carries. A company that
consistently beats the 7-business-day target is, in practice, one whose twelve Month-End Close cycles were
already clean; a company that regularly needs the full 10-business-day ceiling is a candidate for the
Auditor's own trend analysis to flag as a process-health signal, independent of any single year's result.

# Worked Example

**Company:** Al-Wasat Consumer Holding K.S.C.P. (Kuwait Shareholding Company, Public — listed on Boursa
Kuwait), `company_id` 5190, base currency KWD, `companies.is_shareholding_company = true`,
`companies.foreign_ownership_pct = 0.1000` (10% free float held by non-GCC investors, 90% Kuwaiti/GCC),
Share Capital (account 3100) KWD 6,000,000.0000. **Fiscal year:** FY2026, `fiscal_year_id` 4410
(2026-01-01 to 2026-12-31), twelve monthly periods `fiscal_period_id` 4461 (January) through 4472
(December). **`fiscal_year_close_runs`** row 8801, `attempt_number` 1.

**2026-12-27 (T-5 calendar days) — first Step 0 attempt, `schedule` trigger.** December (`fiscal_period_id`
4472) is still `status = 'open'` — its own Month-End Close cycle has not yet reached Phase 10. Step 0
computes the readiness score anyway, as designed, so the gap is visible early rather than discovered cold
on year-end day:

```
condition 1  all_12_periods_closed        11/12 closed  → 20 x 0.917 = 18.33
condition 2  ledger_balanced (11 periods)  yes           → 15
condition 3  bank_fully_reconciled_pct     100%          → 10
condition 4  no_stray_drafts               yes           → 10
condition 5  fixed_assets_current          NO (Dec depreciation not yet run)      → 0
condition 6  payroll_current               NO (Dec payroll_run still processing) → 0
condition 7  tax_current_or_disclosed      yes (structural — no interim return)  → 10
condition 8  no_open_material_exception    yes           → 10
condition 9  special_accounts_configured   yes           → 3
condition 10 fiscal_year_state_valid       yes           → 2
                                                    TOTAL = 78.33
```

78.33 is well under the 95 floor — Step 0 halts, `fiscal_year_close.readiness_failed` fires with
`readiness_unmet_conditions: ["all_12_periods_closed", "fixed_assets_current", "payroll_current"]`, and
the Controller is notified informationally. This is not an SLA breach (SLAs & Timing) — it is the
scheduled early check doing exactly what it is for for.

**2027-01-05, 08:10 — December locks; second Step 0 attempt, `event` trigger.** `fiscal_period_id` 4472's
own Month-End Close cycle reaches its Phase 10 and emits `fiscal_period.closed` (`period=12`). Year-End
Close's event-trigger path re-evaluates the same `fiscal_year_close_runs#8801` row:

```
condition 1  all_12_periods_closed        12/12 closed  → 20
condition 2  ledger_balanced               yes          → 15
condition 3  bank_fully_reconciled_pct     97% (one small dormant-account fee pending documentation) → 9.7
condition 4  no_stray_drafts               yes          → 10
condition 5  fixed_assets_current          yes (Dec depreciation posted)         → 10
condition 6  payroll_current               yes (Dec payroll_run.status='paid')   → 10
condition 7  tax_current_or_disclosed      yes                                   → 10
condition 8  no_open_material_exception    yes                                   → 10
condition 9  special_accounts_configured   yes                                   → 3
condition 10 fiscal_year_state_valid       yes                                   → 2
                                                    TOTAL = 99.7
```

99.7 clears the 95 floor. Step 1 fires immediately: `fiscal_years#4410.status: open -> closing`;
`fiscal_year_close_tasks` rows 0-15 are created against `fiscal_year_close_runs#8801`.

**2027-01-05, through the day — Steps 2-5.** The General Accountant drafts the adjusting true-up: KWD
9,200.0000 of prepaid-insurance amortization catch-up plus KWD 9,200.0000 of an unbilled year-end services
accrual, net KWD 18,400.0000, confidence 0.81 (the services accrual is estimated from a trailing vendor
average, disclosed as such). Because the combined amount exceeds the company's Tier-2 threshold (KWD
5,000), GATE 1 requires both the Senior Accountant and the CFO; both approve same-day. The Depreciation &
Amortization run posts KWD 42,500.0000 (confidence 0.97, formulaic off `fixed_asset_depreciation_schedules`),
cleared at GATE 2 the same way. Provisions (Step 4) total KWD 71,950.0000 across three lines — Expected
Credit Loss top-up KWD 26,000.0000 (confidence 0.68, an aging-bucket statistical model), Inventory NRV
write-down KWD 14,750.0000 (confidence 0.74, based on two recent below-cost sale prices for the affected
SKUs), End-of-Service Indemnity true-up KWD 31,200.0000 (confidence 0.93, a deterministic tenure/salary
formula) — and because the ECL line alone exceeds `financial_statement_templates.materiality_threshold`
(KWD 20,000 at this company), GATE 3 pulls in the CFO alongside the Controller. All three post 2027-01-06.

**2027-01-06/07 — Step 5, Tax Provision.** The Tax Advisor computes Profit Before Tax from the now-fully-
adjusted ledger: Net Revenue 8,240,000.0000 - Cost of Sales 5,120,000.0000 - Operating Expenses (1,860,000.0000
base + 18,400.0000 adjusting) - Depreciation 42,500.0000 - Provisions 71,950.0000 = **1,127,150.0000**.
Against this base: KFAS (1.00%) 11,271.5000; NLST (2.50%) 28,178.7500; Zakat (1.00%) 11,271.5000; Kuwait
corporate tax (15% on the 10% foreign-owned profit share: 15% x (10% x 1,127,150.0000) = 15% x 112,715.0000)
16,907.2500 — **total tax/levy provision 67,629.0000**, confidence 0.92 (the four rates and the ownership
split are structural facts once `companies.foreign_ownership_pct` is set; the only softer input is
confirming no cross-border related-party adjustment applies this year). GATE 4 — Tax Manager and CFO — both
approve 2027-01-07 morning.

**2027-01-07 — Steps 6-7.** The Adjusted Trial Balance (`trial_balance_snapshots#91982`) generates and
validates: balanced to the fils, zero open Critical/High findings. The Auditor's pre-close scan (Step 7)
raises exactly one Low-severity finding — a dormant vendor account that suddenly received a single small
posting in December — reviewed and dismissed same day as a legitimate, documented one-off reactivation, not
a control concern; the dismissal is logged with its reason, not silently cleared.

**2027-01-07 (drafted) / 2027-01-08 (approved) — Step 8, the Closing Entry.** The General Accountant drafts
the entry shown in full under Journal Entries Produced (Entry 7): Net Revenue 8,240,000.0000 zeroed;
Cost of Sales 5,120,000.0000, Operating Expenses 1,878,400.0000, Depreciation 42,500.0000, Provisions
71,950.0000, and Tax/levy expense 67,629.0000 all zeroed; net **1,059,521.0000 credited to Retained
Earnings** (account 3400). Confidence 0.99. **GATE 5 — dual approval** clears 2027-01-08, 09:00 KWT: the
Finance Manager approves first, the CFO second (the Approval Assistant enforces both are distinct
`user_id`s holding distinct roles); the entry posts immediately after the second approval.

**2027-01-08, 14:30 — Step 9, the Statutory Reserve Transfer.** The Reporting Agent computes the mandatory
10% transfer (`companies.statutory_reserve_rate`): 10% x 1,059,521.0000 = 105,952.1000 uncapped. Statutory
Reserve's balance immediately before this entry is KWD 2,910,000.0000; the 50%-of-capital cap
(`companies.statutory_reserve_cap_pct_capital` x Share Capital 6,000,000.0000) is KWD 3,000,000.0000,
leaving only KWD 90,000.0000 of room. The transfer is **capped at 90,000.0000** — the entry shown in full
under Journal Entries Produced (Entry 8). Confidence 1.00 on the arithmetic; the Board has no standing
voluntary-reserve election on file, so no top-up beyond the statutory minimum is proposed. GATE 6 —
Finance Manager — approves the same afternoon; the entry posts.

**2027-01-08, 15:20 — Step 10, Fiscal Year Ledger Close (mechanical, no gate).** `fiscal_years#4410.status:
closing -> closed`. `year.closed` fires with the exact payload shown in full under Events
Emitted/Consumed: `retained_earnings_closing_balance: "3909821.0000"` — the year's opening Retained
Earnings of KWD 2,940,300.0000, plus this year's net profit of KWD 1,059,521.0000 (Step 8), less the KWD
90,000.0000 reserve transfer (Step 9). Statutory Reserve now stands at exactly KWD 3,000,000.0000 — its
cap. Total elapsed from Period 12's lock (2027-01-05, 08:10) to the ledger genuinely closing: **a little
over 3 business days**, inside the Steps 1-10 target band.

**2027-01-08, continuing — Step 11.** The Post-Closing Trial Balance generates and validates cleanly on
the first attempt: every Revenue and every Expense account reads exactly zero; `TOTAL ASSETS = TOTAL
LIABILITIES + TOTAL EQUITY` holds with zero variance. No rollback path is exercised this cycle (see
Failure, Retry & Rollback for what would have happened had it not).

**2027-01-09 — Steps 12-13.** The Reporting Agent generates the full statutory statement set
(`is_statutory_filing = true`, template and currency version locked per VR-10): the Income Statement shows
Net Revenue 8,240,000.0000, Gross Profit 3,120,000.0000 (37.9% margin), Profit Before Tax 1,127,150.0000,
total tax/levy expense 67,629.0000, **Profit for the Year 1,059,521.0000**; the Balance Sheet shows
Retained Earnings 3,909,821.0000 and Statutory Reserve 3,000,000.0000; the Statement of Changes in Equity
shows the full rollforward line by line. The CFO's accompanying narrative (confidence 0.90) notes Net
Revenue 4.3% above the FY2026 budget of KWD 7,900,000.0000 and Net Profit up 17.4% year-over-year against
FY2025's KWD 902,300.0000, with gross margin essentially flat despite this year's larger-than-usual ECL
and NRV provisions — the recommendation: **"ready to close."** The Auditor and Reporting Agent jointly
assemble the Audit Pack: the Post-Closing Trial Balance, the one dismissed pre-close finding, the full
`ai_decisions` history for the year (every adjusting/depreciation/provision/tax draft and its approval
trail), the tax workpapers behind the KFAS/NLST/Zakat/corporate-tax computation, the depreciation schedule,
and the finalized statutory statements with their disclosure notes.

**2027-01-09 (drafted) / 2027-01-11 (approved) — Step 14, Compliance Sign-off.** The Compliance Agent's
attestation confirms every statutory prerequisite: the Ministry of Commerce annual filing window is still
open, the Boursa Kuwait disclosure deadline has not passed, no retention hold intersects any record touched
this cycle, and no control attestation is overdue — `compliance.block.period_close` never fires. The Owner,
travelling, reviews and approves **GATE 7** on 2027-01-11 at 10:00 KWT — roughly 2 business days after the
Audit Pack was ready, within the Step 14 target.

**2027-01-11, 10:05 — Step 15.** `fiscal_year_close_runs#8801.status -> 'locked'`. FY2027 (`fiscal_year_id`
4550) — already accepting ordinary Sales/Purchasing/Banking postings since 2027-01-01 under General Ledger
BR-12's rule that Step 10 already satisfied — now receives its formal, audited `opening_balance` entry
carrying every FY2026 Balance Sheet closing figure forward, including Retained Earnings 3,909,821.0000 and
Statutory Reserve 3,000,000.0000. `fiscal_year.opened` fires with the exact payload shown in full under
Events Emitted/Consumed. **Total elapsed time, Period 12 lock to fully locked: 6 business days** — inside
the 7-business-day target, comfortably under the 10-day ceiling. Because Statutory Reserve is now sitting
exactly at its cap, the Reporting Agent's FY2027 Step 9 draft (a year from now) will read
`companies.statutory_reserve_cap_pct_capital x` Share Capital against the account's own balance and propose
a transfer of **KWD 0.0000** regardless of FY2027's net profit — until Share Capital itself increases and
raises the ceiling (see Edge Cases).

# Controls & Audit

**Segregation of duties is enforced structurally, not by convention.** No agent in this workflow holds a
permission letting it both propose and approve the same artifact — `accounting.ai.approve_draft` can never
be held by an AI identity (General Ledger, Permissions), and this is checked at the permission-grant layer
in every participating agent's own document, not merely asserted here. Within the human side of the chain,
GATE 5's dual-approval rule is enforced at the database level, not only in application logic: the Approval
Assistant's routing service rejects a second approval attempt from the `user_id` that supplied the first.
The Auditor who raises a pre-close or post-close finding never resolves its own finding — only a human
holding the appropriate role does, per `AUDITOR_AGENT.md`'s own guardrail. The Tax Advisor who computes the
provision is never the same actor who approves it at GATE 4, and the Reporting Agent who drafts the
Reserve Transfer at Step 9 never approves it at GATE 6.

**Every phase transition is independently auditable, twice over.** Each `fiscal_year_close_tasks` row's
`started_at`/`completed_at`/`status` history is one audit trail; every `journal_entries`,
`trial_balance_snapshots`, and `financial_statement_snapshots` state transition, and every `ai_decisions`
row this cycle produces, writes its own corresponding `audit_logs` entry (who, when, old value, new value,
reason where applicable) — the same platform-wide mutation-audit rule every other workflow in QAYD follows,
applied here across seven agents and sixteen steps instead of one agent and one step.

**Tamper-evidence on the four artifacts an external party will actually request.** The Post-Closing Trial
Balance and the `final` statutory Financial Statement snapshots each carry a `content_hash` (sha256 over
the ordered line set, per `TRIAL_BALANCE.md` and `FINANCIAL_STATEMENTS.md`'s own VR-10 immutability
trigger) computed at generation time. The Closing Entry and the Reserve Transfer, once posted, are
ordinary immutable `journal_entries` rows subject to the platform's standard posted-entry protections. A
divergence between any stored artifact and a fresh regeneration against the same cutoff is treated as a
data-integrity incident to investigate, never quietly reconciled away — this is the same posture
Month-End Close already holds for its own monthly snapshots, unchanged at year-end scale.

**The Audit Pack is the External Auditor's starting point, not a deliverable assembled for them
separately.** A company with an External Auditor role configured grants that role read-only access to
`fiscal_year_close_runs`, every `fiscal_year_close_tasks` row and its linked `ai_decisions`, the Adjusted
and Post-Closing Trial Balance snapshots, the `final` statutory statement snapshots, the full tax
workpapers behind the year's provision, and the complete `audit_logs` trail behind all of it — the exact
records the internal close relied on, generated once and read by both audiences, never a summary
re-assembled after the fact for external consumption.

**The Closing Entry and Reserve Transfer are the two highest-scrutiny postings QAYD ever produces, and
their approval trail reflects it.** Both require named human approval regardless of confidence or amount
(Confidence & Escalation Rules); both are logged with the full `ai_reasoning`/`source_documents` payload
shown under Journal Entries Produced, not merely the resulting debit/credit; and both are the two entries
`docs/accounting/TRIAL_BALANCE.md` Business Rule 3 names explicitly as the precondition for the
Post-Closing Trial Balance itself — meaning any auditor validating the PCTB is, by construction, also
validating that these two entries exist, are posted, and total what the ledger says they total.

**Reopening is always a bigger event than progressing, and at the fiscal-year level there is deliberately
no "reopen" at all.** Where Month-End Close's own period-level rollback can move a period backward
(`soft_close -> open`, or a formally logged `locked` reopening), Year-End Close's ledger-level `closed`
status has no reverse transition in the canonical `fiscal_year_status` enum — corrections after Step 10
happen exclusively through reversing entries (Failure, Retry & Rollback), never through an un-close. This
is a deliberate asymmetry: a month can be revisited because eleven more months of the same year still
follow it; a closed fiscal year has already been reported to a bank, a regulator, or a shareholder by the
time anyone would want to revisit it, and the platform's control model reflects that by making the
correction path visible (a new, clearly-dated reversing entry) rather than letting the original record
move.

**Statutory Reserve's cap logic is itself a control, not merely a calculation.** Because
`companies.statutory_reserve_cap_pct_capital` is read fresh every year against the account's *current*
balance (Journal Entries Produced, Entry 8's `reasoning` field), the Reserve Transfer can never
accidentally over-fund the reserve past its legal ceiling even if a prior year's transfer was computed
incorrectly and only caught later — the cap check is idempotent to history, not merely to this year's own
math.

# Edge Cases

| # | Case | Handling |
|---|---|---|
| 1 | The company is not a shareholding company (`is_shareholding_company = false`) | Step 9 is not skipped, it is *evaluated differently*: the Reporting Agent checks `ai_memory` for a Board-elected voluntary-reserve policy; if none exists, `fiscal_year_close_tasks` for `reserve_transfer` moves straight to `status = 'skipped'` with a logged reason ("no statutory or voluntary reserve requirement on file"), and Step 10 proceeds on the Closing Entry alone — BR-08's rate/cap columns are never applied to a company they were not configured for |
| 2 | Net loss, not net profit, for the fiscal year | The Closing Entry debits Retained Earnings instead of crediting it (or credits Accumulated Losses if the company tracks it as a separate contra-equity account); the Reserve Transfer (Step 9) computes to KWD 0.0000 and is skipped — Kuwait's CCL reserve requirement is a percentage *of profit*, and there is no profit to take a percentage of; the Compliance Agent's attestation explicitly notes "no statutory reserve transfer required — net loss year" rather than a silent absence |
| 3 | Statutory Reserve is already at or above its cap before this year even starts | Step 9's computation (rate x profit, capped at `cap_pct x capital` minus the account's *current* balance) naturally floors at KWD 0.0000 once the cap is reached, exactly as FY2027 will for Al-Wasat in the Worked Example — no special-case branch is needed, the same formula that produced the KWD 90,000.0000 partial transfer this year produces KWD 0.0000 next year |
| 4 | Share Capital increases mid-cycle (a rights issue closes between Step 6 and Step 9) | The cap computation at Step 9 reads Share Capital's balance *as of the Reserve Transfer's own draft time*, not a value cached earlier in the run — a capital increase that has already posted raises the room available for this year's transfer; one that is still `pending_approval` on the equity side does not count until it posts |
| 5 | Readiness check (Step 0) never reaches 95 because one period is stuck in a Month-End Close dispute for weeks | `fiscal_year_close_runs` stays in `readiness_check` indefinitely; there is no forced timeout that proceeds anyway — Year-End Close will wait as long as the underlying Month-End Close dispute takes, since proceeding on eleven-of-twelve periods would compute every downstream figure against an incomplete year. The Controller sees this as a standing, dated notification, escalating per the same ladder Month-End Close itself uses for a stuck period |
| 6 | A Critical Auditor finding surfaces at Step 7, after Steps 2-6 have already drafted and been approved | Step 7 hard-blocks Step 8 regardless of how clean Steps 2-6 looked; if the finding implicates one of the already-approved adjusting entries, that entry is reversed and redrafted, Step 6's Trial Balance regenerates, and the workflow re-enters Step 6 rather than Step 2 — only the affected entry redoes its own gate, not the whole batch |
| 7 | The tax provision (Step 5) depends on a return or ruling not yet finalized by the tax authority (e.g., a disputed related-party transfer-pricing position) | The Tax Advisor drafts the provision using its best-supported position, explicitly flagged `is_provisional = true` on the resulting `tax_returns` row with the disclosed reason, exactly as Trigger & Preconditions condition 7 allows; the Closing Entry proceeds on the provisional figure, and a later ruling is booked as a subsequent-year true-up, never as a silent retroactive edit to a closed year |
| 8 | A multi-branch or multi-subsidiary company needs a consolidated statement in addition to each entity's own close | Each legal entity's own Year-End Close proceeds and locks independently on its own timeline, exactly as Month-End Close's own multi-branch handling works one level down; the Consolidated statement (Financial Statements module, a separate generation mode) still requires its own approved elimination set (BR-06) before it can be finalized, and consolidation timing never blocks any single entity's own close |
| 9 | A required GATE 5/6/7 approver is unavailable for an extended period (the Owner is unreachable, as in the Worked Example, but for longer) | The Journal Entries submodule's delegation mechanism applies identically at year-end: the approval delegates to another eligible user holding the same or higher role, fully attributed on the record — GATE 5 and GATE 7's elevated-authority requirement narrows who is eligible to delegate to, it does not remove the delegation path entirely |
| 10 | The company is brand-new, closing its first-ever fiscal year | No prior fiscal year exists to carry an opening Retained Earnings balance from — the Closing Entry's net profit becomes the *entirety* of Retained Earnings, and the CFO Agent's variance narrative discloses "no prior-year comparative" exactly as it does for a first Month-End Close, rather than fabricating a trend |
| 11 | A transaction is discovered, after Step 10, that should have been dated inside the now-`closed` fiscal year | Never inserted into the closed year under any circumstance. It posts in the new fiscal year as a `prior_period_adjustment`-tagged entry, with disclosure in the new year's own Notes if material — mirroring exactly how Month-End Close already handles a late-arriving vendor bill discovered after a period locks, one level up |
| 12 | The company operates under `accounting.fiscal_year.parallel_open` deliberately, year-round, rather than only in the brief Step 10-to-Step 15 window | Year-End Close's own Steps still run in full — parallel-open changes when FY N+1 accepts *ordinary* transactions, it does not change when this workflow's own gates fire or what they require; the workflow is agnostic to whether the company was already relying on parallel-open before this year's close even began |
| 13 | The Post-Closing Trial Balance (Step 11) fails after Step 10 has already fired | Handled in full under Failure, Retry & Rollback — root-caused as an integrity incident, corrected via a reversing entry dated into the new fiscal year under an elevated Owner/CFO exception, never by attempting to un-close the year |
| 14 | Two agents disagree during the close (Fraud Detection flags a large December customer receipt the Auditor's sub-ledger tie-out would otherwise treat as clean) | Surfaced explicitly in the Audit Pack rather than either agent silently overriding the other; a human resolves it, and the resolution is logged against both agents' original findings so neither appears to have been "wrong" without the context of what was actually decided |
| 15 | A second trigger fires for a fiscal year that already has a non-terminal `fiscal_year_close_runs` row | No duplicate row is created — `uq_fycr_year_active`'s partial unique index rejects it; the second trigger attaches as an observer to the existing run rather than racing it |
| 16 | The company changes its fiscal year-end mid-stream (a permitted one-time transition year per `GENERAL_LEDGER.md`) | The transition (short) year runs this exact same sixteen-step workflow unchanged — a transition year still has a defined `start_date`/`end_date` and its own twelve-or-fewer periods; the only difference is the transition year's own `fiscal_years` row is flagged accordingly upstream, which this workflow reads but does not need special-case logic for |

# Future Improvements

- **Continuous year-end readiness, not only a five-day-lead score.** Expose the same ten-condition
  computation Step 0 uses as a live, always-on dashboard tile visible from mid-year onward — "if the year
  ended today, you would be at 61/100, blocked mainly on Fixed Assets" — so a Controller sees the gap
  months before the T-5 window, not five calendar days before it matters.
- **Auditor-authored correcting-entry suggestions, extended to year-end.** `MONTH_END_CLOSE.md` already
  names this as a future capability for its own control-account mismatches; the same extension applies with
  more force here, since a Step 11 PCTB failure (Failure, Retry & Rollback) is exactly the case where a
  concrete, Auditor-proposed reversing-and-replacement entry pair would shorten the most expensive failure
  mode this workflow has.
- **Predictive Closing-Entry preview, weeks ahead of year-end.** Run the General Accountant's Step 8
  computation in a non-binding preview mode against the *current, still-open* trial balance at any point in
  Q4, so a CFO can see a running "if we closed today, net profit would be approximately X" figure, with the
  same confidence-and-reasoning discipline as the real draft, clearly labeled provisional.
- **Board-ready Reserve Transfer scenario modeling.** Let the CFO ask the Reporting Agent "what would the
  Reserve Transfer be if we increased Share Capital by KWD Y before year-end" and get a grounded answer
  against the same cap formula Entry 8 already uses — turning a one-way calculation into a two-way planning
  tool for capital-structure decisions that are, in practice, often made with exactly this year-end
  mechanic in mind.
- **Cross-year learning for provision accuracy.** Feed each year's realized-vs-estimated variance on the
  ECL and NRV provisions back into `ai_memory`, the same self-correcting loop `MONTH_END_CLOSE.md` proposes
  for monthly accruals, so next year's aging-bucket and NRV models start from a tighter, company-specific
  prior instead of a generic statistical default.
- **Automated statutory-filing-calendar cross-check inside the Audit Pack itself.** Rather than the
  Compliance Agent's attestation being a pass/fail gate a Finance Manager has to trust, surface the actual
  filing deadlines it checked (Ministry of Commerce, Boursa Kuwait, Zakat, NLST, KFAS, corporate tax) as a
  visible checklist inside the Audit Pack, so an External Auditor sees the same evidence the gate saw.
- **Year-end close simulation / dry run.** A non-binding walkthrough of Steps 8-15 against a still-open
  final period, so a Finance Manager can preview the Closing Entry's, the Reserve Transfer's, and the
  Post-Closing Trial Balance's likely shape days before Period 12 actually locks — explicitly foreshadowed
  as a Month-End Close future improvement and concretized here as this workflow's own consumer of it.
- **Multi-year statutory reserve forecasting.** Once Statutory Reserve reaches its cap (as in the Worked
  Example), proactively surface to the CFO, at the *next* Step 9, not just that this year's transfer is
  zero, but a forward projection of which future year a planned capital increase would first re-open room
  under the cap — turning a currently-reactive skip into a piece of forward capital-planning information.
- **Tighter linkage between the Forecast Agent's next-year budget seed and `fiscal_year.opened`.** Today the
  event carries the opening balances; a natural extension is for the Forecast Agent to automatically draft a
  first-cut FY N+1 budget the moment it fires, seeded from the just-closed year's actuals and the CFO's own
  variance narrative, rather than waiting for a human to request one separately.

# End of Document

