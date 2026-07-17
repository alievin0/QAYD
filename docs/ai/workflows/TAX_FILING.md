# Tax Filing (GCC VAT / Kuwait-Ready / KSA ZATCA) — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: TAX_FILING
---

# Purpose

Tax Filing is the workflow that turns a closed fiscal period into a submitted, reconciled, and settled tax return, with an autonomous finance workforce doing every step of preparation and exactly zero steps of legal execution. It is the temporal, cross-agent counterpart to the Tax Management module (`docs/accounting/TAX.md`, which owns `tax_transactions`/`tax_returns`/`tax_return_lines` and the deterministic calculation engine) and to the Tax Advisor agent (`docs/ai/agents/TAX_AGENT.md`, which owns that single agent's reasoning, autonomy, and tool contract). This document owns neither the schema nor the single-agent behavior; it owns the **orchestration** — the sequence in which Tax Advisor, Compliance Agent, Auditor, and Approval Assistant hand a filing to one another and to named humans, from the moment a period closes to the moment the government acknowledges the return and the ledger reflects a settled position.

State up front, because it governs every subsequent section: QAYD's AI layer is an autonomous finance workforce, not a chatbot bolted onto a form. Before a Tax Manager opens QAYD on filing day, Tax Advisor has already aggregated the period, drafted the return, reconciled it to the General Ledger, and Compliance Agent has already checked it for gaps — accounting becomes supervised, not manual, and the deadline stops being a scramble. But the workforce metaphor has a hard edge: filing a tax return is a legal act carrying the company's registration number, and QAYD enforces, structurally, that no AI agent ever performs it. The FastAPI AI layer does not hold a service credential for `tax.filing.approve` or `tax.submit` — not a lower-confidence-gated version, not an emergency override, none. The entire value of this workflow is compressing everything that happens *before* that line to nearly zero human effort, and leaving everything *at and after* that line entirely, unambiguously, human.

This document covers the workflow generically across the GCC VAT Framework Agreement (live and materially identical in mechanics across Saudi Arabia, the UAE, Bahrain, and Oman), the Kuwait-ready dormant path (no domestic VAT as of this specification, fully provisioned to activate with zero code change), and Saudi Arabia's ZATCA e-invoicing mandate (Phases 1 and 2), which attaches additional hooks to the same orchestration rather than requiring a different one. Every fact stated here is consistent with, and does not duplicate, the DDL, enums, endpoints, and agent contracts already established in `TAX.md` and `TAX_AGENT.md`; this document's own contribution is the workflow-run ledger (`ai_workflow_runs`, `ai_workflow_steps`) that ties multiple agents' `ai_tasks`/`ai_decisions` rows into one traceable filing episode, and the settlement journal entry that the filing step produces.

# Trigger & Preconditions

Two independent triggers can start a Tax Filing run for a given `(tax_registration_id, period)` pair; both converge on the same orchestration, and a de-duplication guard (below) ensures they can never race each other into two returns for the same period.

| Trigger | Source | Behavior |
|---|---|---|
| `period.closed` | Accounting core, fired when a `fiscal_periods` row transitions to `status = 'closed'` | For every `tax_registrations` row (`status = 'active'`) whose `filing_frequency` implies the closed period completes a filing period (monthly registration + any month-end; quarterly registration + a quarter-end month), start a run immediately |
| `schedule.deadline_scan` (daily cron, 03:00 company-local time) | AI Layer scheduler | Safety net: scans every active `tax_registrations` row for a period whose `due_date` is within 14 days and for which no non-terminal `tax_returns` row yet exists — catches a period that closed out-of-band, a registration added retroactively, or a scan that a `period.closed` listener missed |

Preconditions checked before the first agent task is created:

1. **Registration is active.** `tax_registrations.status = 'active'` and `effective_from <= period_end <= COALESCE(effective_to, 'infinity')`.
2. **Period is closed, not reopened.** `fiscal_periods.status = 'closed'`; a period a human has since reopened (`status = 'open'` again, e.g., for a correction) halts any in-flight run for that period at its current step rather than proceeding — see Failure, Retry & Rollback.
3. **No competing non-terminal return exists.** `tax_returns` carries `CONSTRAINT uq_tax_returns_period UNIQUE (tax_registration_id, period_start, period_end)`, defined in `TAX.md` — this is the structural, database-level de-duplication guard; the workflow's own idempotency check (below) exists to fail fast with a clear message rather than relying on the constraint violation as the only signal.
4. **AI automation is enabled for the Tax Advisor at this company.** `ai_agent_settings` for `agent_code = 'tax_advisor'` has `enabled = true`. If disabled, the workflow does not start; instead a plain deadline reminder (no draft, no aggregation) is raised to the Tax Manager's queue, and every subsequent step in this document becomes a fully manual process using the same deterministic `TaxCalculationService`/`TaxReturnService` a human would drive from the UI.
5. **At least one human holds each required permission.** `tax.filing.prepare`, `tax.filing.approve`, and `tax.submit` must each resolve to at least one active user via `roles`/`permissions` for the company/branch, checked by the Approval Assistant before it creates an approval request — an unresolvable approver chain is itself raised as a `high`-priority configuration alert rather than silently stalling.

Idempotency key: the workflow computes `workflow_idempotency_key = sha256(company_id | tax_registration_id | period_start | period_end)` and stores it on `ai_workflow_runs`; a second trigger firing for the same key while a run is `running` or `awaiting_human` is a no-op that attaches to the existing run instead of creating a second one.

# Participating Agents

| Agent | `agent_code` | Role in This Workflow | Autonomy Ceiling Here |
|---|---|---|---|
| **Tax Advisor** | `tax_advisor` | Aggregates posted `tax_transactions`, drafts `tax_returns`/`tax_return_lines`, cross-checks the General Ledger, drafts the settlement journal entry proposal and the ZATCA readiness check, moves the return `draft → under_review` | Autonomous through `under_review`; zero authority beyond it |
| **Compliance Agent** | `compliance_agent` | Runs the pre-flight regulatory gate (certificate expiries, registration coverage, e-invoicing field completeness, filing-calendar risk) before the draft may leave `draft`; independently monitors the deadline calendar | Auto-flag (blocking or advisory); never edits the return itself |
| **Auditor** | `auditor` | Independent, asynchronous, risk-weighted review of filed returns and their lineage; does not sit in the critical path of getting a return filed, but can reopen scrutiny on an already-`filed`/`accepted` return | Suggest-only; publishes findings, never mutates a return or a journal entry |
| **Approval Assistant** | `approval_assistant` | Owns the `ai_approval_requests`/`ai_approval_steps` lifecycle for the two human gates this workflow requires (content approval, legal submission): routes to the correct role, tracks the SLA clock, escalates on timeout, notifies on resolution | `auto` for routing/reminders only; never populates a `decision` |

Two further agents consume this workflow's output without participating in its critical path, named here because they appear in the worked example:

- **General Accountant** (`general_accountant`) posts the settlement journal entry the moment `tax_returns.status` reaches `filed` — Tax Advisor drafts the entry's proposed lines for the human reviewer to see before approval, but never posts a journal entry itself, per the platform-wide division of labor between the two agents (see `TAX_AGENT.md`, Collaboration With Other Agents).
- **CFO** and **Treasury Manager** consume the resulting `net_tax_payable` for cash-flow visibility and for preparing the eventual bank remittance — the remittance itself is a separate `bank.transfer`-gated workflow, referenced but not specified here.

No agent outside this list touches a `tax_returns` row. `Forecast Agent` and `Fraud Detection` read the rolling tax position (see `AI_FINANCE_OS.md`, Continuous Tax Review) but are not part of the filing orchestration itself.

# Orchestration (step-by-step, ASCII flow, with human-approval gates)

```
 period.closed  OR  schedule.deadline_scan (T-14d safety net)
        │
        ▼
 [0] Preconditions check (registration active, period closed, no
     competing return, automation enabled, approver chain resolvable)
        │  fail ──────────────────────────────▶ config/compliance alert, halt
        ▼  pass
 ai_workflow_runs row created (status='running', current_step='aggregate')
        │
        ▼
 [1] TAX ADVISOR — Aggregate
     Sum posted tax_transactions for the period, by tax_direction and
     tax_return_lines box mapping. Deterministic arithmetic only.
        │
        ▼
 [2] TAX ADVISOR — Draft
     POST /api/v1/tax/returns  (tax.filing.prepare, autonomous)
     tax_returns.status = 'draft', ai_draft_generated = true
        │
        ▼
 [3] TAX ADVISOR — GL Cross-Check
     Compare aggregated lines against ledger_entries for the mapped
     Output/Input Tax GL accounts for the same period.
        │
        ├─ variance > 0.01 (base currency) ──▶ [3a] Escalate to General
        │                                          Accountant reconciliation
        │                                          task. current_step=
        │                                          'blocked_gl_variance'.
        │                                          Resume at [3] once resolved.
        ▼  variance = 0
 [4] COMPLIANCE AGENT — Pre-Flight Check
     Certificate expiries, registration coverage, e-invoicing field
     completeness (KSA ZATCA QR/hash-chain fields present on every
     source invoice), filing-calendar risk.
        │
        ├─ blocking fail ─────────────────────▶ [4a] Joint task to Tax
        │                                          Manager; draft stays in
        │                                          'draft'. current_step=
        │                                          'blocked_compliance'.
        │                                          Resume at [4] once resolved.
        ▼  pass (or advisory-only, non-blocking)
 [5] TAX ADVISOR — Submit for Review
     POST /api/v1/tax/returns/{id}/submit-for-review (autonomous,
     workflow-state transition only, zero legal effect)
     tax_returns.status = 'under_review'
        │
        ▼
 [6] HUMAN — Tax Manager line-by-line review (no permission gate beyond
     tax.filing.read/prepare; may edit tax_return_lines directly)
        │
        ▼
 [7] APPROVAL ASSISTANT creates ai_approval_requests
     (subject_type='tax_submission', 2 sequential steps)
        │
        ▼
 ┌───────────────────────────  HUMAN GATE 1  ───────────────────────────┐
 │ [8] Finance Manager, holding tax.filing.approve, approves the        │
 │     content: POST /api/v1/tax/returns/{id}/approve                   │
 │     tax_returns.status: under_review → ready_to_file                 │
 └───────────────────────────────────┬──────────────────────────────────┘
                                      ▼
 ┌───────────────────────────  HUMAN GATE 2  ───────────────────────────┐
 │ [9] CFO (or the company's designated filer), holding tax.submit,     │
 │     executes the legal submission:                                  │
 │     POST /api/v1/tax/returns/{id}/file                               │
 │     tax_returns.status: ready_to_file → filed                        │
 └───────────────────────────────────┬──────────────────────────────────┘
                                      ▼
 [10] DETERMINISTIC — same DB transaction as the status flip to 'filed':
      • Government adapter call (ZatcaFilingAdapter / FtaFilingAdapter /
        ManualFilingAdapter) where the jurisdiction offers one
      • General Accountant posts the settlement journal entry
        (entry_type='tax', source_module='tax', source_id=tax_returns.id)
      • tax_transactions.tax_return_line_id backfilled — locks in which
        transactions this return covers
        │
        ├─ adapter rejects ───────────────────▶ [10a] status → under_review
        │                                          (automatic), settlement
        │                                          entry reversed, rejection
        │                                          reason attached. Resume
        │                                          at [6].
        ▼  adapter accepts (or manual/no adapter)
 [11] Government acknowledgment recorded
      POST /api/v1/tax/returns/{id}/record-ack
      tax_returns.status: filed → accepted (where the jurisdiction's
      adapter distinguishes filed-vs-accepted; otherwise remains 'filed')
        │
        ▼
 [12] AUDITOR — asynchronous, risk-weighted post-filing review
      (does not block; may reopen scrutiny, never reopens the return itself)
        │
        ▼
 ai_workflow_runs.status = 'completed'
        │
        ▼
 (hand-off, separate workflow) Treasury Manager prepares the bank
 remittance for net_tax_payable, gated by its own bank.transfer approval
```

Two properties of this flow are load-bearing and worth stating explicitly. First, every loop-back (`3a`, `4a`, `10a`) returns to an earlier *named* step rather than restarting the workflow — `ai_workflow_runs.current_step` is the resumption pointer, so a reconciliation that takes three days does not force Tax Advisor to re-aggregate from zero once it resolves. Second, steps 8 and 9 are deliberately two different permissions, checked by two different Laravel middleware evaluations, satisfied in the general case by two different named humans — nothing downstream of step 7 can be satisfied by an AI holding elevated credentials, because no such credential exists to hold.

# Data & Tables Touched

Every table below except `ai_workflow_runs` and `ai_workflow_steps` is owned by a document this workflow depends on and never duplicates: the `tax_*` tables by `docs/accounting/TAX.md`; each agent's own decision-log table by `docs/ai/agents/TAX_AGENT.md` (`ai_tasks`, its flavor of `ai_decisions`), `docs/ai/agents/COMPLIANCE_AGENT.md` (`compliance_assessments`, `compliance_filings`), and `docs/ai/agents/AUDITOR_AGENT.md` (its own flavor of `ai_decisions`); `ai_approval_requests`/`ai_approval_steps` by `docs/ai/AI_COMMAND_CENTER.md`; and `domain_events`/`event_inbox`/`event_type_registry` by `docs/database/DATABASE_EVENTS.md`. This document's only new tables are the workflow-run ledger below.

| Table | Read | Written By | Step(s) |
|---|---|---|---|
| `tax_registrations` | Yes | — (trigger/precondition source of truth; never written by this workflow) | 0 |
| `fiscal_periods` | Yes | Accounting core (`period.closed` producer) | 0 |
| `tax_transactions` | Yes (aggregation, GL cross-check source) | `TaxCalculationService`'s own commit path (upstream of this workflow); this workflow only backfills `tax_return_line_id` | 1, 3, 10 |
| `tax_returns` | Yes | `TaxCalculationService`/`TaxReturnService` via `prepare_tax_return_draft`, `submit_return_for_review`, `approve`, `file`, `record-ack` | 2, 5, 8, 9, 11 |
| `tax_return_lines` | Yes | Same, populated at draft creation, editable by a human during review | 2, 6 |
| `exemption_certificates`, `recovery_ratios` | Read-only (compliance pre-flight, expiry checks) | — | 4 |
| `ledger_entries` | Read-only (GL cross-check) | — | 3 |
| `journal_entries`, `journal_lines` | Read (cross-check) + Written (settlement entry) | `JournalPostingService`, consuming `tax.return.filed` | 3, 10 |
| `ai_tasks` | Written | Tax Advisor, Compliance Agent (one row per unit of work) | 1, 2, 3, 4 |
| `ai_decisions` (Tax Advisor's decision log, per `TAX_AGENT.md`) | Written | Tax Advisor | 1, 2, 3 |
| `compliance_assessments`, `compliance_filings` | Written | Compliance Agent | 4 |
| `ai_decisions` (Auditor's finding log, per `AUDITOR_AGENT.md`) | Written | Auditor (async) | 12 |
| `ai_approval_requests`, `ai_approval_steps` | Written | Approval Assistant | 7, 8, 9 |
| `ai_workflow_runs`, `ai_workflow_steps` | Written | This workflow's own orchestrator (FastAPI AI layer, service-account writes, RLS-scoped like every other AI-layer write) | 0-12 |
| `domain_events` | Written | DB triggers / `TaxReturnService` | 2, 5, 8, 9, 10, 11 |
| `event_inbox` | Written | Every consumer's delivery-claim row | Consumption of every event above |
| `notifications`, `audit_logs` | Written | Every step that changes state or requires human attention | 0-12 |
| `attachments` | Read (certificates, e-invoice evidence) + Written (manual-filing acknowledgment scan, where no e-filing API exists) | Compliance Agent (read); a human, via the Laravel API (write) | 4, 11 |

## Why this workflow keeps its own run ledger, and sibling workflows do not

`docs/ai/workflows/PAYROLL_PROCESS.md` tracks its entire orchestration through one business table's own status column — `payroll_runs.status` is created the moment a run starts and only leaves existence at the very end, so the run's own row *is* the workflow's state machine, end to end. Tax Filing cannot do the same, for a structural reason specific to this workflow's shape: **`tax_returns` does not exist yet for roughly half of this workflow's lifecycle.** Step 0 (trigger evaluation, precondition checks, idempotency dedup) happens entirely before a `tax_returns` row is created — there is no row to hang a status on until step 2. Symmetrically, step 12 (the Auditor's asynchronous, unscheduled, indefinitely-recurring post-filing review) happens entirely after `tax_returns.status` has reached a terminal value (`accepted`, permanently) — a business row that stopped changing has nothing left to report against a workflow's own SLA and observability needs.

`ai_workflow_runs` exists to close both gaps: it is created at step 0, before any business row exists, and it remains queryable (`status = 'completed'`, the row itself never deleted) long after `tax_returns` has gone quiet, giving Reporting Agent and the CFO's dashboards one place to answer "how long did this filing actually take, end to end, including the two weeks before a draft existed and the six weeks of Auditor follow-up after it was accepted" — a question `tax_returns.created_at`/`filed_at` alone cannot answer, since it only brackets steps 2 through 9.

## `ai_workflow_runs` / `ai_workflow_steps` — DDL

```sql
CREATE TYPE ai_workflow_type AS ENUM (
    'tax_filing', 'payroll_process', 'month_end_close', 'year_end_close',
    'order_to_cash', 'purchase_to_pay', 'bank_reconciliation', 'financial_reporting',
    'revenue_recognition'
);

CREATE TYPE ai_workflow_run_status AS ENUM (
    'running', 'awaiting_human', 'blocked', 'completed', 'failed', 'cancelled'
);

CREATE TABLE ai_workflow_runs (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    workflow_type         ai_workflow_type NOT NULL,
    idempotency_key       VARCHAR(64) NOT NULL,
    status                ai_workflow_run_status NOT NULL DEFAULT 'running',
    current_step          VARCHAR(64) NOT NULL,
    trigger_event         VARCHAR(64) NOT NULL,        -- 'period.closed' | 'schedule.deadline_scan' | 'manual'
    related_entity_type   VARCHAR(60) NULL,             -- 'tax_returns', populated from step 2 onward
    related_entity_id     BIGINT NULL,
    context               JSONB NOT NULL DEFAULT '{}',  -- tax_registration_id, period_start, period_end, due_date
    blocked_reason        VARCHAR(32) NULL,             -- 'gl_variance' | 'compliance' | 'authority_rejection' | NULL
    started_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at          TIMESTAMPTZ NULL,
    failure_reason        TEXT NULL,
    created_by            BIGINT NULL REFERENCES users(id),   -- NULL for system/scheduler-triggered runs
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    CONSTRAINT uq_ai_workflow_runs_idempotency UNIQUE (company_id, workflow_type, idempotency_key)
);
CREATE INDEX idx_ai_workflow_runs_company_status ON ai_workflow_runs(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_ai_workflow_runs_related        ON ai_workflow_runs(related_entity_type, related_entity_id);
CREATE INDEX idx_ai_workflow_runs_blocked        ON ai_workflow_runs(company_id) WHERE status = 'blocked';

CREATE TABLE ai_workflow_steps (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ai_workflow_run_id  BIGINT NOT NULL REFERENCES ai_workflow_runs(id),
    step_code           VARCHAR(64) NOT NULL,       -- 'aggregate' | 'draft' | 'gl_cross_check' | ...
    step_order          SMALLINT NOT NULL,
    actor_type          VARCHAR(16) NOT NULL CHECK (actor_type IN ('ai_agent','human','system')),
    actor_code          VARCHAR(64) NULL,            -- agent_code, or a role name for a human step
    actor_user_id       BIGINT NULL REFERENCES users(id),    -- populated once a specific human acts
    status              VARCHAR(16) NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','in_progress','completed','blocked','skipped','failed')),
    ai_task_id          BIGINT NULL REFERENCES ai_tasks(id),
    decision_ref        JSONB NULL,    -- {"table":"ai_decisions","agent_code":"tax_advisor","id":553301}
    started_at          TIMESTAMPTZ NULL,
    completed_at        TIMESTAMPTZ NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_ai_workflow_steps UNIQUE (ai_workflow_run_id, step_order)
);
CREATE INDEX idx_ai_workflow_steps_run ON ai_workflow_steps(ai_workflow_run_id, step_order);
```

`decision_ref` is a loose, JSONB-typed pointer rather than a hard foreign key, for a specific and honestly-stated reason: `TAX_AGENT.md`, `COMPLIANCE_AGENT.md`, and `AUDITOR_AGENT.md` each independently define their own decision-log table under the name `ai_decisions` (respectively: `confidence_score NUMERIC(5,4)` with tax-specific columns; a differently-shaped `compliance_assessments` table entirely; and `confidence NUMERIC(5,4)` with a `category`/`severity` pair and an `ai_agent_id` foreign key). All three happen to share one convenient property — a `NUMERIC(5,4)`, 0.0000-1.0000 confidence scale — which is what lets this workflow present one unified confidence story across three agents (see Confidence & Escalation Rules) without a rescaling layer. But they are not one physically unified table, so `ai_workflow_steps.decision_ref` records which table and which id a given step's AI output lives in rather than asserting a foreign key against a table shape that varies by agent. A future platform-wide unification of the three `ai_decisions` variants — tracked as a cross-cutting concern, not owned by this document — would let `decision_ref` collapse into a real foreign key without any change to `ai_workflow_steps`'s own structure.

`ai_workflow_runs`/`ai_workflow_steps` never themselves emit a `domain_events` row. They are AI-layer-internal execution telemetry — created and updated by the FastAPI orchestrator as it drives Tax Advisor, Compliance Agent, and Approval Assistant through the sequence in Orchestration — not a business fact a downstream module would subscribe to. Anything a human needs to see (a blocked run, a completed filing) surfaces through `notifications`, sourced from the *business* events in Events Emitted/Consumed below, never by a consumer polling `ai_workflow_runs` directly.

# Journal Entries Produced (where financial)

## No entry at Draft, Review, or either approval gate

Steps 1 through 9 — aggregate, draft, GL cross-check, compliance pre-flight, submit-for-review, human review, and both approval gates — are pure workflow-state transitions against `tax_returns`/`tax_return_lines`. None of them touches `journal_entries`. This is deliberate and follows directly from `TAX.md`'s own accounting model: every VAT/WHT amount this return aggregates was **already posted to the General Ledger at invoice/bill/payroll-commit time**, transaction by transaction (see `TAX.md` → Accounting Integration → Journal Entries for the invoice- and bill-level examples). By the time a period closes, `Output Tax Payable — {Jurisdiction}` and `Input Tax Receivable — {Jurisdiction}` already carry the period's correct balances. Steps 1-9 of this workflow only *read* and *organize* what is already true in the ledger; they do not create new financial fact.

## Settlement entry — produced at Filing (step 10)

Filing does produce one genuinely new journal entry, for a reason distinct from liability recognition: `Output Tax Payable`/`Input Tax Receivable` are **operational** accounts that keep accumulating every day as new invoices and bills post, indifferent to filing-period boundaries. The moment a return is filed, the specific set of transactions it covers becomes a legally fixed, immutable fact — enforced structurally by the `tax_transactions.tax_return_line_id` backfill at this exact step, which "locks in" precisely which rows this return answers for. The settlement entry's job is to move the *filed* period's share of those operational balances out of the ever-accumulating operational accounts and into a period-specific, authority-facing liability (or, in a refund position, a receivable) that Treasury can plan a specific remittance against and that reconciles 1:1, forever, against `tax_returns.net_tax_payable` for this one return. `TAX.md`'s own Chart of Accounts table lists the operational accounts (`Output Tax Payable`, `Input Tax Receivable`, `Input Tax Receivable — Non-Recoverable`, `Withholding Tax Payable`, and so on) that accumulate continuously; it does not separately name a per-filing settlement account, since that account belongs to the filing workflow, not to transaction-level tax calculation. This document introduces it:

| Account | Type | Normal Balance | Purpose |
|---|---|---|---|
| Tax Payable to Authority — {Jurisdiction} | Liability | Credit | The exact `net_tax_payable` of one filed return, from filing until remittance clears it |
| Tax Recoverable from Authority — {Jurisdiction} | Asset | Debit | Used instead of the line above when a return's net position is a refund/credit carry-forward (`net_tax_payable < 0`) |

`JournalPostingService` (Accounting core) builds this entry automatically the moment it consumes the `tax.return.filed` domain event (see Events Emitted/Consumed) — inside the same database transaction as `tax_returns.status: ready_to_file → filed`, so the two either both commit or neither does. No AI agent constructs or posts this entry; the General Accountant agent's role here is limited to what `TAX_AGENT.md` → Collaboration With Other Agents already defines — cross-checking the resulting entry's balance as one of its own period-close-readiness signals, never authoring or posting it.

**Generic mapping** (VAT/GST; withholding and other system types follow the same operational-to-settlement pattern against their own operational accounts, per `TAX.md` → Accounting Integration):

| Line | Account | Side | Source |
|---|---|---|---|
| 1 | Output Tax Payable — {Jurisdiction} | Debit | `SUM(tax_amount)` across every `tax_transactions` row this return's lines now reference, `tax_direction = 'output'` |
| 2 | Output Tax Payable — {Jurisdiction} (Self-Assessed) | Debit | Same, `tax_direction = 'output_self_assessed'` (reverse-charge output leg) |
| 3 | Input Tax Receivable — {Jurisdiction} | Credit | `SUM(recoverable_amount)`, `tax_direction = 'input'` |
| 4 | Input Tax Receivable — {Jurisdiction} (Self-Assessed) | Credit | Same, `tax_direction = 'input_reverse_charge'` |
| 5a | Tax Payable to Authority — {Jurisdiction} | Credit | Balancing line, only if `net_tax_payable ≥ 0` |
| 5b | Tax Recoverable from Authority — {Jurisdiction} | Debit | Balancing line instead of 5a, only if `net_tax_payable < 0` |

Every line inherits `cost_center_id`/`project_id`/`department_id`/`branch_id` from `tax_returns.branch_id`, matching the platform-wide dimension-tagging rule; `source_type = 'tax_return'`, `source_id = tax_returns.id`.

## Reversal entry — produced on government rejection (step 10a)

If the jurisdiction's e-filing adapter rejects the submission (a malformed e-invoice hash chain, an expired registration number, a duplicate submission the authority's own system detects), the settlement entry that had just posted is reversed in the exact same transaction that reverts `tax_returns.status: filed → under_review` — the exact debit/credit mirror of the entry above, tagged `journal_entries.reversal_of_id`. `tax_transactions.tax_return_line_id` is simultaneously cleared (unlocked) for every row this return had claimed, since the return no longer legally covers them until it is re-filed. No `UPDATE` is ever issued against the original settlement entry's lines.

## No entry at Acknowledgment (step 11)

Recording a government acknowledgment (`tax_returns.status: filed → accepted`, where the jurisdiction's adapter distinguishes the two) is metadata capture only — `government_reference_number`/`government_ack_payload` — and never touches `journal_entries`. The settlement entry already posted at `filed`; acceptance confirms it was correct, it does not create it.

## The remittance entry is explicitly out of scope here

Nothing in this document produces the entry that actually clears `Tax Payable to Authority — {Jurisdiction}` against the bank (Debit that account, Credit the operating bank account) — that entry belongs to the separate, `bank.transfer`-gated payment workflow that Treasury Manager and Banking own, exactly as Participating Agents already states. This document's own settlement entry is what makes that later payment workflow well-defined: it hands Treasury one clean, dated, jurisdiction-specific liability line to pay, instead of an ever-moving operational tax-account balance that mixes filed and unfiled periods.

## Refund positions

When `net_tax_payable < 0`, line 5b above posts instead of 5a and no cash leaves the company at all in connection with this return — see Edge Cases for the carry-forward-vs-cash-refund policy decision this implies.

# Events Emitted/Consumed

Every event below follows the canonical envelope and outbox mechanics defined in `docs/database/DATABASE_EVENTS.md` — `domain_events` as the one authoritative outbox, `event_type_registry` as the single gate on which names may ever be emitted, and `event_inbox` guaranteeing at-least-once delivery is handled idempotently at every consumer.

## A note on terminology, stated once

Three documents in this set refer to the moment a `tax_returns` row reaches `filed` under three different spellings: `docs/database/DATABASE_EVENTS.md`'s own AI-layer subscription map lists `tax.return_filed` (module-scoped, underscore); `docs/accounting/TAX.md` → Accounting Integration lists `tax.return.filed` (module-and-entity-scoped, all-dotted) among the events that drive `JournalPostingService`; and `docs/ai/agents/COMPLIANCE_AGENT.md` names its own sibling event `tax_return.draft_updated` (entity-first). All three denote, or are adjacent to, the identical underlying trigger condition this document also needs to name precisely. This document adopts `TAX.md`'s spelling — `tax.return.<verb>` — as canonical, because `TAX.md` is the schema and lifecycle owner of `tax_returns` (per the shared design context's rule that a table's owning document is authoritative over anything referencing that table), and extends that exact pattern to every other stage this workflow's own orchestration needs to name (`draft_updated`, `submitted_for_review`, `ready_to_file`, `filed`, `acknowledged`, `rejected_by_authority`, `amended`). `tax.return_filed` and `tax_return.draft_updated` are not separately, additionally fired by this workflow — any consumer still wired to either spelling should subscribe to the `tax.return.*` name below that covers the same condition.

## Event Catalog

| Event Name | Producer | Trigger Condition | Key Payload Fields | Primary Consumers |
|---|---|---|---|---|
| `period.closed` | Accounting core (`fiscal_periods` trigger) | `fiscal_periods.status → 'closed'` | `fiscal_period_id`, `company_id`, `period_start`, `period_end` | This workflow's trigger listener (step 0); Tax Advisor; Payroll (own workflow) |
| `tax.return.draft_updated` | `TaxReturnService` | New draft created, or an existing draft's lines are recomputed/edited while still `draft`/`under_review` | `tax_return_id`, `tax_registration_id`, `status`, `net_tax_payable` | Compliance Agent (recompute filing-readiness score); this workflow (advances `current_step`) |
| `tax.return.submitted_for_review` | `TaxReturnService` | `draft → under_review` | `tax_return_id`, `submitted_by` (`NULL` for the autonomous Tax Advisor transition) | Notifications (Tax Manager's queue) |
| `tax.return.ready_to_file` | `TaxReturnService` | `under_review → ready_to_file` (Gate 1 cleared) | `tax_return_id`, `approved_by`, `approved_at` | Approval Assistant (opens Gate 2's `ai_approval_requests`); Notifications |
| **`tax.return.filed`** | `TaxReturnService` | `ready_to_file → filed` (Gate 2 cleared, adapter call attempted) | `tax_return_id`, `filed_by`, `filed_at`, `net_tax_payable`, `currency_code`, `government_reference_number` (nullable until ack) | `JournalPostingService` (settlement entry); Compliance Agent; CFO/Treasury Manager (remittance prep); Reporting Agent |
| `tax.return.rejected_by_authority` | `TaxReturnService`, on adapter rejection | `filed → under_review` (step 10a) | `tax_return_id`, `rejection_reason`, `rejected_at` | `JournalPostingService` (reversal entry); Notifications (Tax Manager, urgent) |
| `tax.return.acknowledged` | `TaxReturnService` | `filed → accepted` (step 11) | `tax_return_id`, `government_reference_number`, `government_ack_payload` | Reporting Agent; Auditor (post-filing review eligible from this point) |
| `tax.return.amended` | `TaxReturnService` | An `accepted`/`filed` return superseded (`status → amended`) and a new `tax_returns` row created referencing it | `original_tax_return_id`, `amended_tax_return_id`, `reason` | Compliance Agent; Auditor; Reporting Agent |

`tax.return.filed` is the authoritative source for two facts already relied upon elsewhere in this document set without previously being registered under one settled name: it is what `JournalPostingService` consumes to build the settlement entry (Journal Entries Produced), and it is what `event_type_registry` needs a row for (`producer_module = 'tax'`, `producer_layer = 'service'`, since the payload is a simple, already-in-memory state transition enriched with actor/correlation context — not an aggregation only the database can cheaply total at commit time).

## Canonical payload — `tax.return.filed`

```json
{
  "event_id": "018f8e21-6a10-7c40-9b3e-2d7c1f4a9032",
  "event_name": "tax.return.filed",
  "event_version": 1,
  "company_id": 4110,
  "branch_id": 62,
  "aggregate_type": "tax_return",
  "aggregate_id": 9021,
  "occurred_at": "2026-07-16T10:04:11Z",
  "produced_by": "tax-service",
  "causation_id": "018f8e1a-9910-7000-8b2e-2a1c5e6f7a10",
  "correlation_id": "018f8d02-1120-7000-8b1e-3a2c5e6f7a00",
  "actor": { "type": "user", "user_id": 118 },
  "payload": {
    "tax_return_id": 9021,
    "tax_registration_id": 3,
    "return_type": "vat",
    "period_start": "2026-06-01",
    "period_end": "2026-06-30",
    "filed_by": 118,
    "filed_at": "2026-07-16T10:04:11Z",
    "net_tax_payable": "106800.0000",
    "currency_code": "SAR",
    "government_reference_number": null
  },
  "metadata": { "source": "service", "request_id": "e6d4a301-9f22-4b17-9d0e-2a6c7f5e4c33" }
}
```

`government_reference_number` is `null` at the moment of this event and populated by the subsequent `record-ack` call/`tax.return.acknowledged` event — the filing call and the government's synchronous acknowledgment are two steps even when, as in ZATCA's case, they happen seconds apart (see Worked Example), because a filing that times out waiting on the government's response must still leave the ledger in a well-defined state (`filed`, settlement entry posted, reference pending) rather than blocking the whole database transaction on an external network call.

## AI-layer subscription

```php
private const AGENT_SUBSCRIPTIONS = [
    'tax_advisor'      => ['period.closed', 'tax.return.rejected_by_authority', 'schedule.deadline_scan'],
    'compliance_agent' => ['tax.return.draft_updated', 'tax.return.filed', 'tax.return.acknowledged'],
    'auditor'          => ['tax.return.acknowledged', 'tax.return.amended'],
    // treasury_manager, cfo subscriptions to tax.return.filed for remittance-planning purposes
    // are owned by their own agent documents, not repeated here
];
```

Delivery is at-least-once; `event_inbox` (`event_id`, `consumer_module`) guarantees a redelivered `tax.return.filed` does not cause `JournalPostingService` to post a second settlement entry for the same return — its handler is written against the natural key `tax_return_id`, matching the platform's effectively-once processing model exactly as `docs/ai/workflows/PAYROLL_PROCESS.md` documents for its own events.

# Confidence & Escalation Rules

## One native scale, no rescaling layer needed

Unlike `docs/ai/workflows/PAYROLL_PROCESS.md`, which has to reconcile two different confidence conventions (a 0-100 scale for Payroll Manager/Treasury Manager outputs, a 0-1 scale for Compliance Agent's own table), this workflow's three active AI participants happen to share one scale natively: Tax Advisor's `ai_decisions.confidence_score` (`TAX_AGENT.md`), Compliance Agent's `compliance_assessments.confidence` (`COMPLIANCE_AGENT.md`), and Auditor's `ai_decisions.confidence` (`AUDITOR_AGENT.md`) are all `NUMERIC(5,4)`, bounded `0.0000`-`1.0000`. This is stated explicitly because it is the reason this workflow's escalation ladder below can present one unified number per finding without a conversion step — not an accident this document is relying on without checking, but a verified property of the three specific agent documents this workflow orchestrates.

## Escalation ladder

| Condition | Source | Action | Destination / SLA |
|---|---|---|---|
| Tax Advisor classification confidence < 0.70, below materiality | Upstream (product/transaction classification, prior to this workflow) | Suggest-only; does not block this workflow | Tax Manager's routine queue |
| Tax Advisor classification confidence < 0.70, exposure above materiality (default 1% of the registration's trailing-quarter net tax payable) | Upstream | Bypasses the routine digest | Tax Manager **and** Auditor, priority `high` |
| GL cross-check variance > 0.01 (base currency) at step 3 | Tax Advisor | Blocks the draft from advancing; `ai_workflow_runs.blocked_reason = 'gl_variance'` | General Accountant reconciliation task; resume at step 3 |
| `compliance_assessments.flag_type = 'advisory'` | Compliance Agent | Informational; never gates any transition | Co-surfaced with the draft for the human reviewer at step 6 |
| `compliance_assessments.flag_type = 'blocking'` | Compliance Agent | Holds the draft at step 4; `ai_workflow_runs.blocked_reason = 'compliance'` | Tax Manager, joint task; resume at step 4 once cleared |
| Compliance confidence-below-threshold on a `blocking`-eligible finding, OR the dual LLM+rule-engine self-consistency check disagrees | Compliance Agent (`self_consistency_agree = false`) | Automatically downgraded to `advisory`; never allowed to block on a single, unverified reasoning pass | Surfaced, not gated |
| A `blocking` finding cites a knowledge-base passage with `review_status != 'human_reviewed'` | Compliance Agent | Structurally impossible to emit — capped at `advisory`, `confidence ≤ 0.60`, regardless of the retrieval's apparent strength | Surfaced as a "requires human-reviewed source" gap |
| Missing `tax_rates` row discovered during aggregation | Should not occur here — `TaxCalculationService` already raises `TaxRateNotConfiguredException` at the *original transaction's* commit time, upstream of this workflow. Observed at step 1, it indicates an already-posted transaction was force-committed around that guard | Aggregation halts; `ai_workflow_runs.status = 'failed'` | Escalated to engineering/Owner, priority `urgent` — a data-integrity incident, not a tax judgment call |
| `ai_approval_requests` (Gate 1, `tax.filing.approve`) unresolved past `sla_due_at` | Approval Assistant | Auto-escalated per `docs/ai/AI_COMMAND_CENTER.md`'s standing SLA rule | Surfaces on the CFO's Urgent Actions panel |
| `ai_approval_requests` (Gate 2, `tax.submit`) unresolved past `sla_due_at`, with `due_date` inside 3 business days | Approval Assistant | Escalated directly, bypassing the routine digest, given the statutory deadline exposure | CEO/Owner notification, not only the designated filer |
| Auditor finding (any `category`) at `severity = critical`, any confidence | Auditor | Published, never blocking; reopens scrutiny on an already-`accepted` return | CFO and Tax Manager queues |

## Materiality and the two human gates specifically

`TAX_AGENT.md`'s own materiality-weighted escalation rule (1% of trailing-quarter net tax payable, company-configurable) governs whether a *classification* concern reaches the Auditor early. It does not, and structurally cannot, shorten either of this workflow's two approval gates — materiality changes who else gets looped in, never whether `tax.filing.approve` and `tax.submit` are required. A KWD 12 exposure flag and a KWD 1.2 million exposure flag pass through the identical two-gate sequence; only the first one's escalation path differs.

## Numbers are fetched, never generated

Every monetary figure appearing in a `reasoning` string — Tax Advisor's aggregation narrative, Compliance Agent's e-invoicing completeness explanation — must have arrived via a tool-call return value in the same execution trace, per `TAX_AGENT.md`'s Deterministic-First Principle. This workflow adds nothing new here; it inherits the guardrail and applies it at the one place a workflow orchestrator could otherwise be tempted to shortcut it: the settlement journal entry's proposed lines (visible to the Gate 2 approver before they file) are always the literal `SUM(...)` tool output, never a value the orchestration layer's own narration re-states from memory.

# Failure, Retry & Rollback

| Failure Point | Detection | Recovery |
|---|---|---|
| GL cross-check variance > tolerance (step 3) | Tax Advisor's own Cross-Check node | Draft blocked from advancing (`blocked_reason = 'gl_variance'`); escalated to General Accountant; the *same* `ai_workflow_runs` row resumes at step 3 once the reconciliation posts — no restart, no re-aggregation from zero |
| Compliance pre-flight blocking failure (step 4) | Compliance Agent, synchronous | Draft held at `draft`; joint task to Tax Manager; resume at step 4 once cleared |
| Compliance pre-flight failure discovered *after* Gate 1 already cleared (a certificate expires, or Compliance's own continuous monitoring — not the one-time pre-flight — raises a new blocking finding while the return sits at `ready_to_file`) | Compliance Agent's continuous deadline/certificate monitoring, independent of the one-time step-4 gate | Gate 1's approval is invalidated (`ai_approval_requests` for Gate 1 marked `superseded`, a fresh one required); `tax_returns.status` does not silently proceed to Gate 2 on a stale approval — see Edge Cases |
| AI layer unreachable (Tax Advisor or Compliance Agent down) at trigger time | Orchestrator's own connection timeout | Behaves identically to AI automation being disabled (Trigger & Preconditions #4): a plain deadline reminder substitutes for the draft, and every step becomes a fully manual `TaxCalculationService`/`TaxReturnService` operation. Logged distinctly in `ai_logs`/platform monitoring as an infra event (for engineering visibility) even though the human-facing behavior is identical to the deliberate-disable case |
| A quoted monetary figure in any `reasoning` fails the numeric-consistency check | Server-side parser, pre-persistence, per `TAX_AGENT.md` | Rejected before a human ever sees it; logged to `ai_logs` as an integrity failure; treated as an engineering defect, not a judgment call |
| Human rejects at Gate 1 (`tax.filing.approve` holder finds an error) | Human action | `tax_returns.status` remains `under_review` (it had not yet left it); required `rejection_reason`; resumes at step 6 (human review) or, if the fix requires re-aggregation, at step 1 — the Tax Manager's own judgment, not a forced full restart, since content errors at this stage are lower blast-radius than a payroll number |
| Human rejects at Gate 2 (`tax.submit` holder declines to file, having noticed something the content approver missed) | Human action | `tax_returns.status: ready_to_file → under_review` (mirrors the automatic step-10a reversal path exactly, minus the settlement entry since none has posted yet); Gate 1's approval is consumed/closed, not silently reused — a corrected draft must clear Gate 1 again before Gate 2 is offered a second time |
| Government adapter rejects the filing call (step 10a) | Adapter response / webhook | Settlement entry reversed atomically with `tax_returns.status: filed → under_review`; `tax_transactions.tax_return_line_id` unlocked; resumes at step 6 |
| Government adapter times out with no response (network partition, government portal outage) | Adapter call timeout | `tax_returns` remains `ready_to_file` (never optimistically flipped to `filed` on an unconfirmed call); the filing attempt is retried with the same `Idempotency-Key`, so a retry never risks a duplicate submission to the government system itself |
| Idempotency: a retried orchestration step (network blip mid-run) | Idempotency-keyed tool calls, matching `ACCOUNTANT_AGENT.md`'s own `Idempotency-Key` convention | A retried `POST` returns the original cached response; no duplicate draft, no duplicate settlement entry, no duplicate government submission |
| `period.closed` and `schedule.deadline_scan` both fire for the same period | `ai_workflow_runs`'s own `UNIQUE (company_id, workflow_type, idempotency_key)` constraint | The second trigger attaches to the existing `running`/`awaiting_human` run rather than creating a second one — see Trigger & Preconditions |
| A domain event (`tax.return.filed`, `tax.return.acknowledged`) delivered twice | `event_inbox` claim per `(event_id, consumer_module)` | No-op on redelivery; `JournalPostingService` never double-posts the settlement entry |
| Permanent failure (malformed payload, an `event_name` absent from `event_type_registry`) | Consumer-side validation | Routed straight to the dead-letter queue on first occurrence — never retried, since retrying a deterministic failure only delays visibility of a real defect |
| A period is reopened (`fiscal_periods.status: closed → open`) while a run is mid-flight | Precondition #2 re-checked on every step transition, not only at trigger time | The in-flight run halts at its current step (does not silently continue against data that may still change); resumes automatically once the period closes again |
| A `filed`/`accepted` return is later found to be wrong | Human discovery, or an Auditor finding | Never edited. The original row's `status → 'amended'`; a new `tax_returns` row is created, `notes` referencing the original, and runs the *entire* Tax Filing workflow again from step 1 as a fresh `ai_workflow_runs` row (`trigger_event = 'manual'`) — see Edge Cases |

Rollback, precisely defined for this workflow, never means an `UPDATE` against a `filed`/`accepted` `tax_returns` row or a posted `journal_lines` row. It always means one of: (a) a named-step resume before `filed`, where the same `ai_workflow_runs` row picks up exactly where it left off; (b) an automatic reversing settlement entry plus a status reversion, for the narrow window between a government rejection and re-filing; or (c) a full amendment run — a new `tax_returns` row and a new `ai_workflow_runs` row — once a return has reached its terminal, accepted state. This mirrors, without exception, the platform-wide rule that financial records are never hard-deleted and posted lines are never mutated in place.

# SLAs & Timing

| Stage | Target | Notes |
|---|---|---|
| `period.closed` → `ai_workflow_runs` created | Same transaction / near-real-time | Event-driven, step 0 |
| Aggregate + Draft + GL cross-check (steps 1-3) | p95 < 15 minutes for a registration with up to ~5,000 posted `tax_transactions` rows in the period | Deterministic summation over already-classified rows; no LLM classification is performed at this stage |
| Compliance pre-flight (step 4) | p95 < 10 minutes for a high-volume KSA registration (e-invoicing field completeness checked per invoice) | Runs immediately after step 3 passes, not in parallel with it, since it needs a stable draft to check completeness against |
| Time from `period.closed` to `under_review` | ≤ 4 hours, matching `TAX_AGENT.md`'s own Time-to-draft-return target, extended through this workflow's own compliance gate | End-to-end steps 0-5 |
| Human review (step 6) | Policy target: complete with enough runway that `due_date` minus the review time still clears both gates 3+ business days ahead of `due_date` | Not system-enforced; escalation intensifies as `due_date` approaches per the 14/7/3/1-day bands `TAX_AGENT.md` already defines |
| Gate 1 (`tax.filing.approve`) SLA | 2 business days by default (company-configurable), auto-reminder at 50% elapsed | Standard `ai_approval_requests` SLA per `AI_COMMAND_CENTER.md`; tightens automatically as `due_date` nears (see below) |
| Gate 2 (`tax.submit`) SLA | 1 business day by default once Gate 1 clears | Shorter than Gate 1 by design — by the time Gate 2 opens, content is already approved; the remaining decision is narrowly "am I the right, informed person to file this now" |
| Deadline-proximity SLA override | Both gates' `sla_due_at` are recomputed to `due_date − 3 business days` whenever that is *earlier* than the default window | Ensures the two-gate sequence never structurally consumes the safety margin `TAX_AGENT.md`'s own deadline alerts (14/7/3/1 days) are trying to preserve |
| Filing call + government adapter round-trip (step 10) | p95 < 2 minutes for ZATCA/FTA synchronous acknowledgment | Kuwait/manual-only jurisdictions have no round-trip; `record-ack` there is a human pasting in a manual reference, not a system wait |
| `tax.return.acknowledged` emission | Within seconds of the adapter's synchronous response, or same business day for asynchronous/manual acknowledgment paths | Trigger/service-based, not polled |
| Full run, `period.closed` → `accepted` | Target ≥ 3 business days of margin before `due_date`, matching `TAX_AGENT.md`'s 0%-deadline-miss-rate target | The whole point of starting at `period.closed` rather than waiting for a human to remember is to make this margin large by default, not to file at the last minute reliably |
| Auditor's asynchronous post-filing review | No SLA — runs on the Auditor's own sampling schedule, weighted toward higher-value and lower-confidence-at-the-time returns | Detective, not preventive; see Controls & Audit |
| Compliance's continuous (non-pre-flight) deadline/certificate monitoring | Daily | Independent of any specific run; can invalidate an already-cleared Gate 1 (see Failure, Retry & Rollback) |

# Worked Example (a full run with numbers)

**Setup.** Dar Al-Khaleej Holding W.L.L. (`company_id 4110`) is headquartered in Kuwait with an active branch in Riyadh (`branch_id 62`), registered for Saudi VAT under `tax_registrations` id `3`, monthly filing basis, accrual. This example continues directly from `docs/ai/agents/TAX_AGENT.md` → Worked Scenarios § 3 ("Quarterly KSA VAT Return: Preparation Through Filing" — despite its heading, the worked figures are a single monthly period, June 2026), carried here through the orchestration layer that document intentionally left unspecified: the workflow-run ledger, the two independent human approval gates and who actually sits in them, the settlement journal entry with real account balances, the domain event that fires, and the Auditor's asynchronous follow-up.

**Step 0 — Trigger and preconditions.** On 2026-07-02 at 00:15 Kuwait time, `fiscal_periods` for June 2026 transitions to `status = 'closed'`, firing `period.closed`. The orchestrator checks every precondition in Trigger & Preconditions: `tax_registrations` id `3` is `active`; no non-terminal `tax_returns` row exists yet for `(3, '2026-06-01', '2026-06-30')`; `ai_agent_settings` has `tax_advisor.enabled = true` for this company; Faisal Al-Mutairi (Finance Manager) and Abdullah Al-Qahtani (CFO) each resolve as active holders of `tax.filing.approve` and `tax.submit` respectively. All pass. `ai_workflow_runs` id `14092` is created:

```json
{
  "id": 14092,
  "company_id": 4110,
  "branch_id": 62,
  "workflow_type": "tax_filing",
  "idempotency_key": "b7f1c4a0e9d2...e402",
  "status": "running",
  "current_step": "aggregate",
  "trigger_event": "period.closed",
  "context": { "tax_registration_id": 3, "period_start": "2026-06-01", "period_end": "2026-06-30", "due_date": "2026-07-31" },
  "started_at": "2026-07-02T00:15:04Z"
}
```

**Steps 1-3 — Aggregate, Draft, GL Cross-Check.** As detailed in `TAX_AGENT.md` § 3: standard-rated domestic sales output tax SAR 168,000.0000, reverse-charge self-assessed output tax SAR 6,750.0000 (total output tax SAR 174,750.0000); standard-rated purchases recoverable input tax SAR 61,200.0000, reverse-charge self-assessed recoverable input tax SAR 6,750.0000 (total recoverable input tax SAR 67,950.0000). `tax_returns` id `9021` is created in `draft`, `net_tax_payable = 174,750.0000 − 67,950.0000 = 106,800.0000 SAR`. The GL cross-check finds `gl_variance = 0.0000`. `ai_workflow_steps` rows 1-3 complete within 6 minutes of the run starting; `ai_workflow_runs.related_entity_type/id` backfills to `('tax_returns', 9021)`; `current_step → 'compliance_preflight'`.

**Step 4 — Compliance pre-flight.** Compliance Agent runs `check_type = 'data_check'` (`KSA_ZATCA_EINVOICE_QR_HASH_CHAIN`) and `check_type = 'deadline_check'` (`KSA_VAT_MONTHLY_FILING_DEADLINE`) against the period:

```json
{
  "id": 90501,
  "requirement_id": 3301,
  "check_type": "data_check",
  "result": "pass",
  "confidence": 0.9600,
  "flag_type": "none",
  "reasoning": "All 214 sales invoices posted against tax_registration_id=3 for the period carry a complete ZATCA Phase 2 QR payload and a valid previous-invoice hash reference; zero gaps found across the full population.",
  "evidence": [{ "table": "tax_transactions", "field": "source_document_id", "value": "214 invoices, jurisdiction SA, period 2026-06" }]
}
```

```json
{
  "id": 90502,
  "requirement_id": 3288,
  "check_type": "deadline_check",
  "result": "pass",
  "confidence": 0.9900,
  "flag_type": "none",
  "reasoning": "due_date 2026-07-31 is 29 days out; draft reached under_review readiness on day 2 of the filing window, far inside every 14/7/3/1-day alert band.",
  "evidence": [{ "table": "tax_returns", "id": 9021, "field": "due_date", "value": "2026-07-31" }]
}
```

Both pass. `current_step → 'submit_for_review'`.

**Step 5 — Submit for Review.** Tax Advisor calls `submit_return_for_review` autonomously. `tax_returns.status: draft → under_review`. `tax.return.submitted_for_review` fires. `ai_decisions` id `553301` (Tax Advisor, `decision_type = 'return_draft'`, `confidence_score = 0.9700`) is the row of record for the draft itself, matching `TAX_AGENT.md`'s stated figure exactly.

**Step 6 — Human review.** On 2026-07-03, Lubna Al-Otaibi (Tax Manager, Riyadh branch) opens return `9021`, reviews all seven populated `tax_return_lines` boxes, confirms the reverse-charge import line against its source bill, and requests no changes — exactly as `TAX_AGENT.md`'s own Scenario 3 describes. `ai_workflow_steps` step 6: `actor_type = 'human'`, `actor_user_id` = Lubna's id, `status = 'completed'`, `notes = "No changes required."`.

**Step 7 — Gate 1 request opens.** Approval Assistant creates `ai_approval_requests` id `91145`:

```json
{
  "id": 91145,
  "company_id": 4110,
  "branch_id": 62,
  "subject_type": "tax_submission",
  "subject_id": 9021,
  "source_decision_id": 553301,
  "source_agent_code": "tax_advisor",
  "title": "Approve KSA VAT Return — Riyadh Branch, June 2026 (SAR 106,800.0000 net payable)",
  "amount": "106800.0000",
  "currency_code": "SAR",
  "status": "pending",
  "required_permission": "tax.filing.approve",
  "sla_due_at": "2026-07-06T00:00:00Z"
}
```

with one `ai_approval_steps` row, `approver_role = 'Finance Manager'`.

**Step 8 — Gate 1 (content approval).** On 2026-07-16 at 09:15, Faisal Al-Mutairi (Finance Manager, `user_id 3341`) reviews the AI validation badge (clean cross-check, both compliance checks passed) and approves: `POST /api/v1/tax/returns/9021/approve`. `tax_returns.status: under_review → ready_to_file`. `tax.return.ready_to_file` fires. `ai_approval_requests` id `91145` → `approved`.

*(The 13-day gap between step 6 and step 8 is not a delay in this workflow — it is Lubna's ordinary review cadence for a return nowhere near its `due_date`; exactly the comfortable margin the system is designed to create by starting at `period.closed` rather than at a human's memory of the deadline.)*

**Step 9 — Gate 2 request opens and clears.** Approval Assistant, seeing `tax.return.ready_to_file`, opens a **second, independent** `ai_approval_requests` row, id `91146`, `required_permission = 'tax.submit'`, `approver_role = 'CFO'` — a separate request rather than a second step on request `91145`, because these two gates are governed by two different permissions carrying two different legal weights (correctness of content vs. an irreversible act of filing), and Approval Assistant never opens the second until the first has actually resolved to `approved`. At 2026-07-16T10:03:40Z, Abdullah Al-Qahtani (CFO, `user_id 118`) reviews and calls:

```json
POST /api/v1/tax/returns/9021/file
{
  "approved_by_confirmation": true,
  "approver_notes": "Reviewed against GL trial balance for the period; variance within tolerance.",
  "submit_to_government_api": true
}
```

**Step 10 — Filing and settlement.** In one database transaction: `tax_returns.status: ready_to_file → filed`, `filed_by = 118`, `filed_at = 2026-07-16T10:04:11Z`; `ZatcaFilingAdapter` submits; `tax_transactions.tax_return_line_id` backfills for every one of the period's rows; `tax.return.filed` fires (payload shown in Events Emitted/Consumed). `JournalPostingService`, consuming that event, posts the settlement entry:

| Line | Account | Debit (SAR) | Credit (SAR) |
|---|---|---:|---:|
| 1 | Output Tax Payable — Saudi Arabia VAT | 168,000.0000 | |
| 2 | Output Tax Payable — Saudi Arabia VAT (Self-Assessed) | 6,750.0000 | |
| 3 | Input Tax Receivable — Saudi Arabia VAT | | 61,200.0000 |
| 4 | Input Tax Receivable — Saudi Arabia VAT (Self-Assessed) | | 6,750.0000 |
| 5a | Tax Payable to Authority — Saudi Arabia | | 106,800.0000 |
| — | **Total** | **174,750.0000** | **174,750.0000** |

`journal_entries` id `92104`, `source_type = 'tax_return'`, `source_id = 9021`, `branch_id = 62`. `journal.posted` fires.

**Step 11 — Acknowledgment.** ZATCA's e-filing channel responds within the same request cycle: `government_reference_number = "ZATCA-VAT-2026-06-SA-000481223"`, acknowledged at `2026-07-16T10:04:19Z`. `record-ack` is called; `tax_returns.status: filed → accepted`; `tax.return.acknowledged` fires. `ai_workflow_runs` id `14092`: `status → 'completed'`, `completed_at = 2026-07-16T10:04:20Z`. Elapsed time from `period.closed` to `accepted`: 14 days, 10 hours — comfortably inside the ≥ 3-business-day-before-`due_date` target, with 15 days of margin still remaining before `due_date`.

**Step 12 — Downstream and Auditor follow-up.** CFO Abdullah Al-Qahtani's own dashboard now shows a SAR 106,800.0000 fixed liability due to ZATCA, sourced from `journal_entries` id `92104` rather than a moving operational balance; Treasury Manager picks this up as a scheduled, non-discretionary outflow ahead of the actual remittance (a separate, `bank.transfer`-gated workflow, not detailed here). On 2026-08-04, as part of its regular, independent, risk-weighted sampling — not triggered by anything in this run — the Auditor re-derives return `9021`'s box totals directly from the same posted `tax_transactions` population Tax Advisor aggregated five weeks earlier, via `GET /api/v1/tax/reports/audit-report` (permission `tax.audit.read`), and confirms zero variance from the original aggregation, without needing to trust it. No further action is raised; the finding is logged at `result: pass` for Tax Advisor's own auto-apply-accuracy metric (`TAX_AGENT.md` → Metrics & Evaluation).

Total human actions across the entire run: one line-by-line review (Lubna), two approvals (Faisal, Abdullah) — three people, three permissions, zero AI-authored mutation to any ledger row.

# Controls & Audit

## The hard gate, restated at the workflow level

`TAX_AGENT.md` → Guardrails & Human Approval already establishes, at the single-agent level, that no AI service credential in QAYD is ever provisioned with `tax.filing.approve` or `tax.submit`. This workflow is where that guarantee actually matters end to end: Gates 1 and 2 (steps 8-9) are the only two places in the entire 12-step sequence where a state transition affecting `tax_returns.status` past `under_review` can occur, and both are, without exception, `ai_approval_requests` rows resolved by a named human bearer token — never a workflow-orchestrator convenience call, never a default-approve-on-timeout, never a per-company policy override.

## Two gates, not one two-step chain — and why that is not a stylistic choice

`docs/ai/AI_COMMAND_CENTER.md` documents a different approval shape elsewhere in the platform: a single `ai_approval_requests` row with multiple sequential `ai_approval_steps`, used when the same underlying `required_permission` escalates through multiple role tiers (its own worked example is a two-step Finance Manager → CFO chain on one payment). This workflow deliberately does **not** use that shape for its own two gates, because `tax.filing.approve` and `tax.submit` are not the same permission escalating through two roles — they are two structurally distinct permissions, gating two transitions with materially different legal weight (is the content right, vs. am I now committing the company to a legal filing). Collapsing them into one multi-step chain would let a single `ai_approval_steps` UI render them as "the same kind of sign-off, just two people," which is precisely the framing this workflow needs to avoid. Two independent `ai_approval_requests` rows, the second opened only once the first resolves, keep the two decisions visibly and structurally distinct — including in every dashboard and audit report that lists open/resolved approvals by `required_permission`.

## Segregation of duties

Three actors, three permissions, in a fixed order: **Tax Advisor** drafts (no permission gate beyond `tax.filing.prepare`, which its own service account holds); **a Finance-tier human** approves content (`tax.filing.approve`); **a CFO-tier or explicitly designated human** files (`tax.submit`). Ordinary RBAC can technically grant one person both `tax.filing.approve` and `tax.submit` in a small company with few Finance-tier users, but the two actions remain two separately logged, separately timestamped approvals even then; nothing about this workflow collapses them into a single click, and a company that wants a true second-person check simply avoids granting both permissions to one role.

## MFA

Both Gate 1 and Gate 2 require a session with `mfa_verified: true`, regardless of the approver's base role — a session that has authenticated but not completed MFA can view the draft and the AI validation badge but is rejected with `403`/`mfa_required` on the `/approve` or `/file` call itself, mirroring the platform-wide rule `docs/ai/workflows/PAYROLL_PROCESS.md` applies to its own three-stage chain.

## Retention

Tax records carry retention obligations that are jurisdiction-specific — commonly cited in the six-year range for KSA and the five-year range for the UAE, figures a company's own tax counsel confirms per registration, since this document, like `TAX.md` before it, is a compliance and workflow engine, not a legal-advice engine. QAYD's own default, applied uniformly regardless of the specific jurisdiction's statutory minimum, is to retain every `ai_workflow_runs`/`ai_workflow_steps` row, every `ai_decisions`/`compliance_assessments` row, and every `ai_approval_requests`/`ai_approval_steps` row tied to a `tax_returns` row for the greater of that jurisdiction's statutory minimum or 10 years — matching `TAX.md`'s own module-level retention default — and to exempt any such row from routine archival for as long as the `tax_returns` row it supports remains inside a jurisdiction's audit-lookback window, regardless of company-level data-retention settings. A `tax_returns` row under an active legal hold or ongoing government audit cannot be archived at all, mirroring the `retention_holds` mechanism `COMPLIANCE_AGENT.md` already defines.

## The Auditor as a detective control

Exactly as in `PAYROLL_PROCESS.md`, the Auditor never gates any step of this workflow — it holds no create/update/approve/file/reverse permission on `tax_returns`, `tax_return_lines`, or `journal_entries`, by architecture, not by a permission an administrator merely chose not to grant. Its participation is entirely asynchronous and post-hoc: independently re-deriving box totals for already-`accepted` returns (Worked Example, step 12), and — the one place its confidence/severity model gives it standing to reopen scrutiny — publishing a `critical`-severity finding against an already-filed return regardless of how confident the original filing appeared, without ever itself touching the row.

## Immutability and the amendment escape valve

A `filed`/`accepted` `tax_returns` row is never edited, per `TAX.md`'s Tax Philosophy #4. Every correction discovered after acceptance — whether from an Auditor finding, a late-arriving vendor document, or a Tax Manager's own second look — flows through the amendment path: the original row's `status → 'amended'`, a new `tax_returns` row is created referencing it, and that new row runs this entire workflow again, start to finish, including both approval gates. Nothing about materiality, confidence, or urgency ever shortens the amendment's own path through Gates 1 and 2 — an amendment carries exactly the same legal weight as an original filing, because it is one.

## Sourcing and numeric-consistency, inherited and enforced here

Every `ai_decisions`/`compliance_assessments` row this workflow's steps produce is rejected pre-persistence if it lacks `reasoning` or cites a figure absent from its own tool-call trace, per `TAX_AGENT.md` Guardrail #4 and the platform-wide numeric-consistency check. This workflow's own contribution is applying the identical check to the one place it introduces new arithmetic of its own: the settlement entry's proposed lines, checked against the literal `SUM(...)` tool output before either approver ever sees them (Confidence & Escalation Rules).

# Edge Cases

| Edge Case | Handling |
|---|---|
| Multi-registration company files KSA, UAE, and Bahrain VAT for overlapping periods | Each `(tax_registration_id, period)` pair is an independent `ai_workflow_runs` row with its own idempotency key; each posts its own settlement entry against its own jurisdiction's accounts; `TAX.md`'s Multi Country Support rule that QAYD never merges two legal entities'/jurisdictions' filings governs here without exception |
| Refund/credit position (`net_tax_payable < 0`) | Settlement entry posts line 5b (Debit `Tax Recoverable from Authority`) instead of 5a; the workflow's own steps are otherwise identical. Whether the company claims a cash refund or elects carry-forward to the next period is a Tax Manager policy decision recorded on `tax_returns.notes`, not a system default — a cash-refund election typically opens its own, separate inbound-funds process at the tax authority, tracked but not orchestrated by this document |
| An accepted return is found to be wrong | Never edited. `status → 'amended'`; a new `tax_returns` row plus a fresh `ai_workflow_runs` row (`trigger_event = 'manual'`) runs the full workflow again, including both gates — see Controls & Audit |
| A blocking compliance finding lands *after* Gate 1 has already approved, while the return sits at `ready_to_file` awaiting Gate 2 | Gate 1's `ai_approval_requests` is marked `superseded` rather than left standing; `tax_returns` reverts to `under_review`; Gate 2 is never offered a request built on a now-stale approval. The Finance Manager must re-approve once the compliance issue clears, even though nothing about the return's own numbers changed |
| AI layer infrastructure outage (not a deliberate company-level disable) | Functionally identical to a deliberate disable from the human's perspective — full manual fallback, per Failure, Retry & Rollback — but logged distinctly for engineering/on-call visibility rather than appearing as a normal "AI disabled" configuration state |
| Two branches of the same company close their respective periods on different days (e.g., a newly onboarded branch's reconciliation lags) | Each branch's registration runs its own independent `ai_workflow_runs` row against its own `period.closed` firing; no cross-branch blocking, no forced synchronization |
| A jurisdiction with no e-filing API (Kuwait's dormant-VAT path, or any manual-only registration) | Step 10's government adapter is `ManualFilingAdapter`, which performs no external call; the human filer instead attaches a scanned confirmation or a manually-entered reference number via `record-ack`, backed by an `attachments` row, as the acknowledgment evidence — the settlement entry still posts identically, since it depends only on the `filed` transition, not on the adapter's shape |
| A KWD-base-currency company files a SAR-denominated KSA return | The settlement entry posts in the branch's ledger currency per the company's chart of accounts (converted at the transaction-date exchange-rate snapshots already carried on the underlying `tax_transactions` rows, per `TAX.md`'s Multi Currency Support); `tax_returns.filing_currency_code` remains SAR for the statutory form regardless of the ledger-posting currency |
| A `tax_registrations` row is deregistered mid-period (the company stops being VAT-registered in a jurisdiction partway through a month) | The final period's return still covers the full period through `tax_registrations.effective_to`; Tax Advisor marks it a closing return (`tax_returns.notes`) so Compliance's deadline monitoring stops expecting a subsequent period's return for that registration |
| `period.closed` fires twice for the same period (a re-close after a brief reopen-and-fix cycle) | `ai_workflow_runs`'s `UNIQUE (company_id, workflow_type, idempotency_key)` constraint means the second firing attaches to the existing run if it is still open, or is a pure no-op if the run already reached `completed` for that exact period — a re-close never spawns a second filing for a period already accepted |
| A human repeatedly rejects at the same gate (three or more cycles on one return) | Not treated as routine — the third rejection on the same `tax_returns` row within one filing cycle automatically opens a notification to the CFO/Owner about the *process*, not just the return, since repeated rejection at a late stage usually signals a gap earlier in the pipeline (a misconfigured `tax_rule`, a chronically incomplete e-invoicing source) that re-approving the same return will not fix |
| A company with `tax_advisor.enabled = false` still needs its statutory filing calendar tracked | Precondition #4's fallback reminder-only path still fires regardless of the automation toggle, since deadline awareness is a compliance floor, not an AI convenience — only draft generation and the GL cross-check are skipped, never the reminder |

# Future Improvements

- **Real-time government status webhooks**, replacing the synchronous request/response `record-ack` pattern with a webhook listener against ZATCA's and the UAE FTA's e-filing status APIs, so `tax.return.acknowledged` fires from an authoritative push rather than the filer's own follow-up call — the settlement entry and event contract defined here do not change, only what triggers `record-ack`.
- **Continuous, pre-close draft refresh.** Rather than assembling the first draft cold at `period.closed`, maintain a living, continuously-recomputed preview throughout the open period (already partially implied by `tax.return.draft_updated`'s "fires on update, not only on creation" semantics) so a Tax Manager can see the shape of next month's filing days before it formally starts.
- **Joint cash-timing scenarios with Forecast Agent and Treasury Manager** — "what does filing three days earlier do to this month's cash position," computed on the same validated `net_tax_payable` figure this workflow already produces, surfaced as a suggest-only scenario exactly as `PAYROLL_PROCESS.md` proposes for its own funding what-ifs.
- **A portfolio-level `ai_workflow_runs` dashboard** spanning every branch and registration a group holds, extending `TAX_AGENT.md`'s own proposed consolidated filing-calendar view with the actual run-history and SLA-adherence data this document's ledger already captures.
- **Active-learning from Gate 1 review friction.** Every edit a Tax Manager makes during step 6, not only outright rejections, is a training signal for Tax Advisor's per-company classification precedent — currently only captured at the classification level per `TAX_AGENT.md`; extending it to a per-box, per-line-type "review friction" metric would show which parts of a draft consistently need a human's touch.
- **Auto-drafted amendments.** The moment an Auditor finding on an accepted return crosses a materiality threshold, automatically prepare (never file) the amendment's `tax_returns` draft, so a human's decision to amend starts from a ready draft instead of a blank one — filing the amendment still requires both gates in full.
- **A natural-language filing-status query surface** ("why is this month's filing running two days later than usual") backed entirely by data this document already specifies (`ai_workflow_steps` timestamps, `compliance_assessments` results) — no new data path, a conversational front end over the existing `ai_conversations`/`ai_messages` tables and tool calls.
- **Cash-refund election automation.** Where a jurisdiction's portal exposes an API for it, let a human's carry-forward-vs-cash-refund decision (Edge Cases) trigger the refund claim's own tracked sub-process directly from the accepted return, rather than requiring a manual hop to a separate authority-facing form.

# End of Document
