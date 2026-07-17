# Payroll Process — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: PAYROLL_PROCESS
---

# Purpose

QAYD's AI layer is not a chatbot bolted onto a payroll screen. It is an autonomous finance workforce —
Payroll Manager, Compliance Agent, Auditor, Treasury Manager, and Approval Assistant working continuously
and in coordination — that watches every payroll period from the moment it opens, computes and
cross-checks the run before a human asks for it, hunts for the handful of anomalies a reviewer would
otherwise have to find by eye across a multi-page register, and assembles every downstream artifact
(payslip content, WPS salary file, compliance evidence, funding confirmation) so that by the time a human
opens the run, the only work left is judgment: does this number make sense, and do I authorize it to
become real money leaving the company. Traditional payroll software waits for a Payroll Officer to
reconcile a spreadsheet once a month; this workflow inverts that — accounting becomes supervised, not
manual, and the accountant becomes the supervisor of a payroll run the AI layer already validated
overnight.

This document specifies the **Payroll Process** — the single, canonical, end-to-end orchestration that
carries a payroll run from the moment a period opens to the moment every employee has been paid, every
statutory obligation has been evidenced, and every ledger has closed to the cent. It is the workflow-level
companion to `docs/accounting/PAYROLL.md` (the module's business logic, database design, and lifecycle
state machine — the source of truth this document never contradicts or duplicates) and to
`docs/ai/agents/PAYROLL_AGENT.md` (the Payroll Manager agent's own mandate, tools, and reasoning strategy).
Where those two documents specify *what a payroll run is* and *what one agent does*, this document
specifies *how the whole pipeline moves* — which agent acts when, which human gate stands where, which
events fire and who consumes them, what happens when a step fails, and what a complete run looks like with
real numbers from first calculation to the final `payroll.completed` event.

Payroll is QAYD's highest-blast-radius workflow after Banking's own money-movement paths: it touches every
employee's income, moves the company's cash, and carries statutory exposure under Kuwait Labour Law No.
6/2010 and PIFSS Law No. 61/1976. The organizing principle of everything that follows is therefore the
platform's least negotiable rule, restated here in its payroll-specific form: **the AI layer computes,
cross-checks, validates, and prepares — a human always calculates the decision to submit, and three
distinct humans, in a fixed sequence, always decide whether a run's numbers become money that leaves the
company.** No confidence score, no run size, and no company configuration shortens that sequence. Every
other design choice in this document exists in service of that one sentence.

# Trigger & Preconditions

A payroll process instance begins the moment a `payroll_runs` row is created in `draft` status against an
open `payroll_periods` row. Three distinct trigger paths create that row, and the workflow described below
is identical regardless of which one fired — only the `run_type` differs:

| Trigger | `run_type` | Origin | Typical Timing |
|---|---|---|---|
| Scheduled period close | `regular` | A scheduled job creates the period's regular run automatically once `payroll_periods.period_end` passes, per the company's configured `frequency` | Same day as, or the morning after, period end |
| Manual creation | `regular` \| `supplementary` \| `bonus_run` \| `correction` | A Payroll Officer calls `POST /api/v1/payroll/runs` directly — a late-added new hire, a one-off bonus batch, or a correction to a prior run | On demand |
| Termination event | `final_settlement` | `employees.employment_status` transitions to `terminated`, per the Employee Lifecycle state machine in `docs/accounting/PAYROLL.md` | Within the same payroll cycle as the termination date |

**Preconditions.** The workflow will not admit a run past `draft` → `calculated` unless every row below
holds; a failed precondition surfaces as a validation error on the Calculate call, never as a silent
partial calculation:

| # | Precondition | Enforced By | Failure Behavior |
|---|---|---|---|
| 1 | Every employee in scope has `employment_status IN ('active','on_leave','terminated')` with a valid `base_salary`/`hourly_wage` component effective for the period (`payroll_ready = true`) | `EmployeeOnboardingService::assertPayrollReady()` | Employee excluded from this run, flagged to HR, does not block the rest of the run |
| 2 | The target `payroll_periods` row is `status = 'open'` | `PayrollCalculationService` | 409 Conflict — cannot calculate against a closed period |
| 3 | No other **non-terminal** run already exists for the same `(payroll_period_id, run_type)` pair | `uq_payroll_period`-adjacent application check | 409 Conflict — must complete or cancel the existing run first |
| 4 | Every `salary_components` template referenced by an in-scope employee resolves to a `payroll_gl_account_mappings` row | `PayrollJournalBuilder` (checked pre-emptively at Calculate, not deferred to Post) | Run calculates but is flagged `gl_mapping_incomplete`; Submit is blocked until Finance maps the missing component |
| 5 | A statutory ruleset (`statutory_rules`, `social_insurance_rates`, indemnity parameters) exists and is `effective` for the run's `country_code` and period | Compliance Agent's rule store, read via `get_statutory_ruleset` | Run calculates; Compliance emits an advisory (not blocking) flag that statutory validation is incomplete for manual review |
| 6 | The fiscal period covering `payroll_periods.period_end` is open in Accounting | Compliance Agent's `fiscal_period.close_requested` gate | Post is blocked until Finance reopens the fiscal period or the run is re-targeted |
| 7 | The FastAPI AI layer is reachable (soft precondition) | Payroll Manager's own scheduled/event trigger | Not reachable → the run proceeds through the human pipeline unaffected, marked `payroll_ai_validation_runs.verdict = 'skipped_ai_unavailable'` |

Precondition 7 is deliberately the only one that is *soft*: this workflow is designed so the AI layer is a
dependency that removes a safety net when absent, never a bottleneck that stops a human from paying people
on time.

# Participating Agents

Five AI agents and four human roles participate in every payroll process instance. Each agent's full
mandate is specified in its own document; this table states only what each one does **in this specific
workflow**, in the order it typically engages:

| Participant | Type | Role in This Workflow | Autonomy Recap |
|---|---|---|---|
| **Payroll Officer** | Human | Creates/reviews the draft, triggers Calculate, acknowledges AI flags, Submits, and is the first approval stage | Full CRUD within `draft`/`calculated`; first approver |
| **Payroll Manager** | AI Agent (`docs/ai/agents/PAYROLL_AGENT.md`) | Cross-checks the calculation, runs trend validation against trailing history, scans for statistical/operational anomalies, requests a compliance pass, previews WPS readiness, assembles payslip content | Auto (compute/validate/flag); never submits, approves, posts, or disburses |
| **Compliance Agent** (PIFSS/WPS) | AI Agent (`docs/ai/agents/COMPLIANCE_AGENT.md`) | Runs the PIFSS rate/contribution-cap and WPS-format pre-release assessment on every `payroll_run.calculated` event, before the run may reach `release_requested`; tracks the statutory payment-window deadline | Auto-raise a blocking or advisory flag; only a human can clear a blocking flag |
| **Treasury Manager** | AI Agent (`docs/ai/agents/TREASURY_AGENT.md`) | Ingests the run's `pay_date` and `total_net` as a fixed, non-discretionary, priority-one outflow in its daily liquidity pass; confirms the operating account can cover disbursement; flags a funding gap before disbursement day | Auto (notify-only); never drafts or submits a transfer on payroll's behalf beyond a proposed payment line |
| **Auditor** | AI Agent (`docs/ai/agents/AUDITOR_AGENT.md`) | Detective, not preventive: independently and asynchronously re-samples posted runs and the Payroll Manager's own historical decisions after the fact; receives every `payroll_data_integrity_flag` at confidence ≥ 90 in real time, bypassing the normal chain | Auto (scan, score, publish finding); never blocks, holds, or reverses anything itself |
| **Approval Assistant** | AI Agent | Routes every `ai_decisions` row this workflow produces to the correct human inbox with the correct urgency; generates the bilingual (EN/AR) plain-language narrative of already-validated payslip content on request | Auto-generate narration/routing (read-only, no state change) |
| **Finance Manager** | Human | Second approval stage; reviews AI flags and Compliance holds; calls Post and Disburse | `payroll.run.post`, `payroll.disburse` |
| **CEO** | Human | Third and final approval stage on every run, regardless of size or confidence | `payroll.run.approve` at the `ceo` stage only |
| **HR Manager** | Human | Upstream data owner (new hires, terminations, leave) whose approvals feed the calculation inputs this workflow consumes | Not part of the approval chain itself |

No sixth participant exists with authority to shorten this list. The service-account role every AI agent
above authenticates as is never granted `payroll.run.approve`, `payroll.run.post`, or `payroll.disburse`
under any company configuration — not a missing checkbox, an absent tool.

# Orchestration

The workflow is a strict state machine on `payroll_runs.status` (`payroll_run_status`, defined in
`docs/accounting/PAYROLL.md`), with the AI validation pass interleaved between `calculated` and `Submit`
as an advisory layer that can slow a human down with a flag but can never itself advance or block a state
transition at the database level. Twelve steps carry a regular run from trigger to close:

| # | Step | Actor | State Transition | AI Involvement |
|---|---|---|---|---|
| 1 | Draft creation | Scheduler or Payroll Officer | `— → draft` | None |
| 2 | Calculate | Payroll Officer (`payroll.run.calculate`) | `draft → calculated` | None (Laravel `PayrollCalculationService` is the sole authority on the arithmetic) |
| 3 | AI validation pass | Payroll Manager (auto, event-triggered) | `calculated` (no transition) | Crosscheck, trend validation, anomaly scan, compliance delegation, WPS preview, payslip content assembly |
| 4 | Compliance pre-release scan | Compliance Agent (auto, `payroll_run.calculated` consumer) | `calculated` (no transition) | PIFSS rate/cap + WPS-format assessment; may raise a blocking flag |
| 5 | Treasury funding check | Treasury Manager (auto, daily pass) | `calculated`/`approved` (no transition) | Confirms operating-account coverage for `total_net` ahead of `pay_date` |
| 6 | Human review & flag acknowledgment | Payroll Officer | `calculated` (no transition) | Approval Assistant routes flags to the Officer's queue |
| 7 | Submit | Payroll Officer (`payroll.run.submit`) | `calculated → pending_officer_approval` | None — no AI tool exists for this call |
| 8 | Three-stage approval | Payroll Officer → Finance Manager → CEO | `pending_officer_approval → pending_finance_approval → pending_ceo_approval → approved` | Read-only: each approver sees the AI validation badge and Compliance status inline |
| 9 | Post | Finance Manager/Payroll Officer (`payroll.run.post`) | `approved → posted` | Payroll Manager's validated content becomes the payslip source; General Accountant cross-checks the resulting journal entry balances |
| 10 | Disburse | Finance Manager (`payroll.disburse`) | `posted → paid` (per employee, then run-level) | Payroll Manager's WPS preview is already known-clean; Compliance tracks the statutory payment window |
| 11 | Payment confirmation & payslip unlock | Bank acknowledgment / Finance confirmation | `payroll_items.payment_status → paid` | None |
| 12 | Close-out | System (`payroll.completed` event) | Run fully paid, all payslips issued | Auditor's asynchronous sampling begins on this run from this point forward |

## ASCII Flow — state machine with human-approval gates

```
                         ┌─────────┐
           (trigger) ───▶│  draft  │
                         └────┬────┘
                              │ Calculate (human, payroll.run.calculate)
                              ▼
                        ┌─────────────┐
                        │  calculated │◀───────────────────┐ recalculate (idempotent,
                        └──────┬──────┘                     │ re-run any number of times)
                              │                              │
                    ┌─────────┴──────────┐                   │
                    ▼                    ▼                   │
         ┌────────────────────┐  ┌───────────────────┐        │
         │ Payroll Manager     │  │ Compliance Agent    │       │
         │ (crosscheck, trend,  │  │ (PIFSS/WPS scan,     │       │
         │  anomaly scan, WPS   │  │  advisory/blocking   │       │
         │  preview, payslip     │  │  flag)                │       │
         │  content)             │  └──────────┬───────────┘       │
         └──────────┬────────────┘             │                   │
                    │   flags/holds, if any     │                   │
                    ▼                           ▼                   │
              ┌─────────────────────────────────────┐               │
              │  Human review & acknowledgment        │──(edit input)┘
              │  (Payroll Officer; Finance Manager     │
              │   clears any Compliance blocking flag) │
              └────────────────┬────────────────────────┘
                                │ Submit (human, payroll.run.submit — no AI tool exists)
                                ▼
                    ┌────────────────────────────┐
                    │ pending_officer_approval     │──reject──▶ draft (full restart,
                    └──────────────┬───────────────┘             rejection_reason required)
                                   │ approve (Payroll Officer)
                                   ▼
                    ┌────────────────────────────┐
                    │ pending_finance_approval      │──reject──▶ draft
                    └──────────────┬───────────────┘
                                   │ approve (Finance Manager)
                                   ▼
                    ┌────────────────────────────┐
                    │ pending_ceo_approval          │──reject──▶ draft
                    └──────────────┬───────────────┘
                                   │ approve (CEO)
                                   ▼
                             ┌───────────┐
                             │ approved  │
                             └─────┬─────┘
                                   │ Post (human, payroll.run.post — atomic DB transaction)
                                   ▼
                             ┌───────────┐        payslips generated automatically,
                             │  posted   │───────▶ not yet visible to employees
                             └─────┬─────┘
                                   │ Disburse (human, payroll.disburse)
                                   ▼
                     ┌──────────────────────────┐
                     │ WPS export / bank file /   │
                     │ cash / wallet / cheque       │
                     └──────────────┬───────────────┘
                                    │ per-employee payment confirmation
                                    ▼
                              ┌───────────┐
                              │   paid    │──────▶ payslips become visible to employees
                              └─────┬─────┘        per-employee, as their own line clears
                                    │ all payroll_items.payment_status = 'paid'
                                    ▼
                        ┌────────────────────────┐
                        │  payroll.completed event │──▶ Accounting, Banking, Treasury,
                        └────────────────────────┘     Notifications, Reporting, Forecast

              (from posted or paid, at any time)
                                    │ Reverse (human, payroll.run.reverse)
                                    ▼
                              ┌───────────┐
                              │ reversed  │──▶ spawns a fresh draft, pre-populated
                              └───────────┘     with corrected inputs
```

## Agent orchestration graph — the AI validation pass (step 3-4-5 in detail)

The Payroll Manager runs as a LangGraph-style directed graph (full detail in
`docs/ai/agents/PAYROLL_AGENT.md` → Reasoning & Prompt Strategy); this workflow only needs its
inter-agent hand-offs, since that is the part other agents in this document depend on:

```
FETCH RUN STATE ──▶ CROSS-CHECK CALC ──▶ TREND COMPARISON ──▶ ANOMALY SCAN
                                                                      │
              ┌───────────────────────────────────────────────────────┘
              ▼
   request_compliance_scan(run_id)  ────────────────────▶  COMPLIANCE AGENT
              │                                                  │
              │◀─────────── verdict (pass/warning/blocking) ─────┘
              ▼
   WPS READINESS PREVIEW ──▶ PAYSLIP CONTENT BUILD ──▶ EMIT ai_decisions +
                                                        payroll_ai_validation_runs row
                                                                  │
                                                                  ▼
                                              notify Treasury Manager (funding notice,
                                              via notifications/domain-event path, T-3
                                              business days before pay_date)
```

Every arrow above is a Laravel API call or a domain-event hand-off, never a direct function call across
agent boundaries — the same rule that governs every other agent-to-agent interaction on this platform.
The Compliance Agent's verdict is synchronous and can block; the Treasury notification is asynchronous and
never blocks — a funding shortfall is escalated to the CFO/CEO as its own concern, it does not stop the
approval chain from proceeding (money not yet being available on `pay_date` is a treasury problem to solve
before disbursement, not a reason to prevent Finance from approving the run's correctness ahead of time).

# Data & Tables Touched

Every table below is owned by `docs/accounting/PAYROLL.md` unless otherwise noted; this workflow
introduces no new business table, only reads and writes against the module's existing schema plus the
shared AI-core tables (`ai_decisions`, owned by `docs/ai/agents/CFO_AGENT.md`) and the shared event
infrastructure (`domain_events`, `event_inbox`, owned by `docs/database/DATABASE_EVENTS.md`).

| Table | Read | Written By | Step(s) |
|---|---|---|---|
| `payroll_periods` | Yes | Scheduler / Payroll Officer | 1 |
| `payroll_runs` | Yes | `PayrollCalculationService`, `PayrollApprovalService`, `PayrollJournalBuilder` | 1, 2, 7, 8, 9, 10, 12 |
| `payroll_run_approvals` | Yes | `PayrollApprovalService` (human-only writer; `CHECK` enforces `users.is_service_account = false`) | 8 |
| `employees`, `employee_salary_components`, `salary_components` | Read-only (effective-dated) | — (Payroll never mutates these mid-run) | 2, 3 |
| `attendance`, `leave_requests`, `employee_leave_balances` | Read-only | — | 2, 3 |
| `employee_loans`, `loan_installments` | Read + status update (`pending → paid`/`skipped`) | `PayrollCalculationService` | 2 |
| `payroll_items`, `payroll_item_lines` | Read + Write (regenerated on every recalculation while `draft`/`calculated`) | `PayrollCalculationService` | 2, 3, 9, 10, 11 |
| `payroll_gl_account_mappings` | Read-only | — | 2 (precondition check), 9 |
| `journal_entries`, `journal_lines` | Written | `PayrollJournalBuilder` | 9, 10 |
| `payslips` | Written | `PayslipGenerationService` (queued, one job/employee) | 9 |
| `wps_export_batches` | Written | `WpsFileGenerator` | 10 |
| `bank_transactions`, `bank_accounts` | Read + Written (disbursement leg) | Banking module, consumed by Treasury Manager | 5, 10, 11 |
| `ai_decisions` | Written | Payroll Manager, Compliance Agent, Treasury Manager, Auditor | 3, 4, 5, 12 (Auditor, async) |
| `payroll_ai_validation_runs` | Written | Payroll Manager (rollup row) | 3 |
| `compliance_assessments`, `compliance_alerts` | Written | Compliance Agent | 4 |
| `notifications`, `audit_logs` | Written | Every step that changes state | 1-12 |
| `domain_events` | Written | DB triggers / Laravel services | 7 (`payroll_run.submitted`), 8 (`payroll.approved`), 9 (`journal.posted`), 10 (WPS batch events), 12 (`payroll.completed`) |

# Journal Entries Produced

Every posted run produces exactly one balanced accrual journal entry at Post, and one balanced settlement
journal entry per disbursement batch at Disburse — never a set of ad hoc entries outside `journal_entries`
and `journal_lines`. This section specifies the mapping in full; the Worked Example below shows both
entries populated with real figures for a specific run.

## Accrual entry — produced at Post (`source_type = 'payroll_run'`)

`PayrollJournalBuilder` maps each `payroll_item_lines.component_type` to a debit or credit account via
`payroll_gl_account_mappings` (`company_id`, `component_type`, `debit_account_id`, `credit_account_id` —
data, not code, so Finance remaps accounts without an engineering change):

| Line | Account | Side | Source `component_type` / Aggregate |
|---|---|---|---|
| 1 | 5100 · Salaries & Wages Expense | Debit | `SUM(gross_salary)` across all earning components |
| 2 | 5150 · Employer Social Insurance Expense | Debit | `SUM(social_insurance_employer)` |
| 3 | 5160 · End-of-Service Indemnity Expense | Debit | `SUM(indemnity_accrual)` |
| 4 | 2100 · Salaries Payable (net) | Credit | `SUM(net_salary)` |
| 5 | 2110 · PIFSS Payable | Credit | `SUM(social_insurance_employee) + SUM(social_insurance_employer)` |
| 6 | 2120 · Employee Loans Receivable | Credit | `SUM(loan_repayment + advance_repayment)` |
| 7 | 2150 · Other Payroll Deductions Payable | Credit | `SUM(deduction)` (garnishments, disciplinary, uniform/equipment recovery) |
| 8 | 2130 · Provision for End-of-Service Indemnity | Credit | `SUM(indemnity_accrual)` (mirrors line 3, employer-cost side) |

`SUM(debits) = SUM(credits)` is enforced by the platform's standing double-entry rule before the entry is
allowed to leave `draft` inside `PayrollJournalBuilder`'s own transaction — the same invariant every other
module's posting engine enforces, per `docs/database/DATABASE_ARCHITECTURE.md`. Every `journal_lines` row
additionally carries the employee's active `cost_center_id`/`department_id`/`branch_id` dimension tags,
copied at calculation time, so payroll expense slices by department or branch through ordinary General
Ledger queries with zero Payroll-specific reporting logic. `journal_entries.status` transitions to
`posted` atomically with `payroll_runs.status = 'posted'` inside one database transaction — either both
succeed or neither does (see Failure, Retry & Rollback).

## Settlement entry — produced at Disburse (`source_type = 'payroll_disbursement'`)

Posting the accrual entry records the *liability*; it does not move cash. A second, automatically
generated entry clears the liability as disbursement is confirmed:

| Line | Account | Side |
|---|---|---|
| 1 | 2100 · Salaries Payable (net) | Debit |
| 2 | 1010 · Bank — Operating Account | Credit |

Splitting accrual from settlement into two entries is deliberate: Finance closes the books on payroll
*expense* for a period even if the actual bank transfer clears a day or two later, exactly as SAP and
Oracle GL treat payroll accrual versus payment. If disbursement spans multiple payment methods (bank
transfer, cash, wallet, cheque) or partially fails on specific lines (see Failure, Retry & Rollback), the
settlement entry is split per method/batch rather than forced into a single line, so the credit side of
line 2 above may in practice be several lines against `1010`, `1020 · Petty Cash`, or a wallet-clearing
account, each tied to its own `bank_transactions`/`payroll_cash_disbursements` evidence row.

## Reversal entry

A posted-but-erroneous run is never edited. `POST /api/v1/payroll/runs/{id}/reverse` creates a third
entry type — the exact debit/credit mirror of the accrual entry — sets `payroll_runs.status = 'reversed'`
and `journal_entries` accordingly, and spawns a fresh `draft` run (`reversed_run_id` linking back) for
re-calculation and re-approval. No `UPDATE` is ever issued against a posted `journal_lines` row.

# Events Emitted/Consumed

Every event below follows the canonical envelope and outbox mechanics defined in
`docs/database/DATABASE_EVENTS.md` — `fn_emit_domain_event()`, the `domain_events` outbox, and
`event_type_registry` as the single gate on which names may ever be emitted. This workflow is the
authoritative source for two events already relied upon by sibling agent documents
(`payroll_run.calculated`, consumed by both the Payroll Manager and the Compliance Agent, and
`payroll_run.release_requested`, consumed synchronously by the Compliance Agent) but not yet present in
`event_type_registry`'s seed catalog; it registers them here (`producer_layer = 'service'`, emitted by
`PayrollCalculationService` and `PayrollApprovalService` respectively) so the gap is closed rather than
left as an implicit, undocumented convention.

**A note on terminology, stated once.** `docs/database/DATABASE_EVENTS.md` and
`docs/ai/agents/TREASURY_AGENT.md` both describe the payroll run's terminal state informally as
`status = 'completed'`. The authoritative `payroll_run_status` enum owned by `docs/accounting/PAYROLL.md`
has no `'completed'` value — its terminal success state is `'paid'`. This document treats "completed," as
used in the event name `payroll.completed` and in those two documents' prose, as the plain-language label
for the precise condition **`payroll_runs.status = 'paid'` AND every `payroll_items.payment_status =
'paid'` AND every `payslips` row for the run has been generated** — i.e., the run is fully disbursed and
fully documented, not a thirteenth status value layered onto the enum. The trigger below is written
against this precise condition.

| Event Name | Producer | Trigger Condition | Key Payload Fields | Primary Consumers |
|---|---|---|---|---|
| `payroll_run.created` | `PayrollCalculationService` | New `payroll_runs` row in `draft` | `payroll_run_id`, `payroll_period_id`, `run_type` | Notifications |
| `payroll_run.calculated` | `PayrollCalculationService` | `draft → calculated` | `payroll_run_id`, `total_gross`, `total_net`, `employee_count` | Payroll Manager (triggers step 3), Compliance Agent (triggers step 4) |
| `payroll_run.submitted` | `PayrollApprovalService` | `calculated → pending_officer_approval` | `payroll_run_id`, `submitted_by` | Notifications (next approver) |
| `payroll_run.release_requested` | `PayrollApprovalService` | `approved`, immediately before Post is permitted to execute | `payroll_run_id`, `requested_by` | Compliance Agent (synchronous pre-release gate — may block) |
| **`payroll.approved`** | DB trigger on `payroll_runs` (per `docs/database/DATABASE_EVENTS.md`) | Chain fully cleared, entering `approved` | `payroll_run_id`, `approved_by`, `approved_at` | Payroll (release step), Audit |
| `journal.posted` | DB trigger on `journal_entries` | Accrual entry `draft → posted` | `journal_entry_id`, `lines[]`, `total_debit`, `total_credit` | Reports, Tax, Ledger projection, Audit |
| `wps_export_batch.generated` \| `.submitted` \| `.acknowledged` \| `.failed` | DB trigger on `wps_export_batches` | `status` transition | `wps_export_batch_id`, `payroll_run_id`, `total_amount`, `employee_count` | Banking, Treasury Manager, Notifications |
| **`payroll.completed`** | DB trigger on `payroll_runs` | Full disbursement + full payslip generation, per the terminology note above | `payroll_run_id`, `period_start`, `period_end`, `total_gross`, `total_net`, `total_deductions`, `employee_count`, `journal_entry_id` | Accounting, Banking (bulk transfer confirmation), Treasury Manager, Notifications, Reporting Agent, Forecast Agent |
| `payslip.issued` | DB trigger on `payslips` | Payslip finalized and delivered | `payslip_id`, `employee_id`, `payroll_run_id`, `net_pay` | Notifications |
| `payroll_run.reversed` | `PayrollApprovalService` | `posted`/`paid → reversed` | `payroll_run_id`, `reversal_journal_entry_id`, `reason` | Accounting, Audit |

## `payroll.completed` — canonical payload

Reusing the exact field set already registered in `event_type_registry`
(`docs/database/DATABASE_EVENTS.md` → Domain Event Catalog), with `total_deductions` computed as
`SUM(gross_salary) − SUM(net_salary)` across the run's `payroll_items` (i.e., every employee-side
withholding — statutory, loan, and other deductions combined — excluding employer-cost components):

```json
{
  "event_id": "018f7a2c-4b91-7c10-9e2a-6b1e0c8f7a44",
  "event_name": "payroll.completed",
  "event_version": 1,
  "company_id": 4821,
  "branch_id": null,
  "aggregate_type": "payroll_run",
  "aggregate_id": 55012,
  "occurred_at": "2026-07-06T11:42:03.118Z",
  "produced_by": "payroll-service",
  "causation_id": "018f7a1b-9a10-7000-8b1e-3a2c5e6f7a30",
  "correlation_id": "018f79e0-1120-7000-8b1e-3a2c5e6f7a00",
  "actor": { "type": "system", "trigger": "trg_fn_payroll_runs_events" },
  "payload": {
    "payroll_run_id": 55012,
    "period_start": "2026-06-01",
    "period_end": "2026-06-30",
    "total_gross": "132400.0000",
    "total_net": "120350.0000",
    "total_deductions": "12050.0000",
    "employee_count": 42,
    "journal_entry_id": 90512
  },
  "metadata": { "source": "trigger", "request_id": "n/a" }
}
```

## AI-layer subscription

Per `docs/database/DATABASE_EVENTS.md` → AI Events, the `NotifyAiLayerListener` bridge forwards a
filtered subset of the events above to each agent's FastAPI endpoint. This workflow's addition to that
subscription map:

```php
private const AGENT_SUBSCRIPTIONS = [
    'payroll_manager'  => ['payroll_run.calculated', 'payroll.completed'],
    'compliance_agent' => ['payroll_run.calculated', 'payroll_run.release_requested'],
    'treasury_manager' => ['payroll.approved', 'payroll.completed', 'bank.transaction.cleared'],
    // fraud_detection, forecast_agent subscriptions unchanged — see their own agent documents
];
```

Delivery is at-least-once with idempotent handling at every hop, per the platform's stated
effectively-once processing model: the `event_inbox` table (`event_id`, `consumer_module`) guarantees a
re-delivered `payroll.completed` event does not cause Banking to generate a second bulk-transfer
confirmation or Notifications to email 42 employees a second payslip-ready notice — each consumer's
handler is written against a natural key (`payroll_run_id` for the run-level effects,
`(payroll_item_id)` for the per-employee ones), never a blind relative update.

# Confidence & Escalation Rules

Two independent confidence conventions meet in this workflow. Each agent's own table of record keeps its
native scale permanently — the switch between them is itself a deliberate signal that the two measure
different things — and only the specific value that crosses into the shared queue is rescaled for display:

- **`ai_decisions.confidence_score`** (Payroll Manager, Treasury Manager, Auditor outputs) — the shared
  platform scale, `NUMERIC(5,2)`, 0-100, defined once in `docs/ai/agents/CFO_AGENT.md`. Never emitted as
  exactly 100.00; 99.90 is the practical ceiling, so no output is ever presented as certain.
- **`compliance_assessments.confidence`** (Compliance Agent's own internal table) — `NUMERIC(5,4)`, 0-1,
  calibrated against data completeness, knowledge-base recency, and historical check-type accuracy. When a
  Compliance finding is escalated into the shared `ai_decisions` feed (via `propose_blocking_flag` or a
  `notify_agent` hand-off), its confidence is carried across rescaled to the 0-100 convention
  (`round(confidence * 100, 2)`) so a human reviewing one unified queue never has to mentally convert
  between two scales mid-review.

## Escalation ladder

| Severity / Confidence | Decision Type(s) | Action | SLA |
|---|---|---|---|
| Confidence < 70 (any decision type) | Any | Always surfaced to the Payroll Officer regardless of severity — low confidence is itself the signal | Reviewed before Submit |
| Confidence 70-89, `severity = info`/`warning` | `payroll_anomaly_flag`, `payroll_trend_validation` | Surfaced inline; does not block Submit unless `severity = critical` | Reviewed before Submit |
| Confidence ≥ 90 | `payroll_data_integrity_flag` | Blocks Submit until acknowledged **and** independently, automatically notifies the Auditor role via `notifications`, bypassing the normal approval-chain notification path entirely | < 4 business hours to Auditor acknowledgment |
| Confidence ≥ 95 (any decision type) | Any | Still requires an explicit Payroll Officer acknowledgment click — never auto-clears regardless of how high the score is | Reviewed before Submit |
| Compliance `flag_type = 'blocking'` | Any `compliance_assessments` result | Holds `compliance_filings.hold_active = true` on the run's WPS/PIFSS obligation; only Finance Manager, CFO, or Owner may clear it, with a logged reason | Recommended < 1 business day; unresolved holds surface on the CFO's weekly digest |
| Compliance `flag_type = 'advisory'` | Any | Informational; never gates any transition | N/A |
| Treasury `treasury_liquidity_alert` (`breached = true`) tied to this run's `pay_date` | — | Escalates to CFO Agent narrative immediately; direct CEO notification after 12h unacknowledged | 12h to acknowledgment |
| Any confidence score, `decision_type` unresolved by the AI layer's own numeric-consistency check | — | Rejected before persistence; logged as an `ai_logs` integrity failure; never shown to a human as a normal decision | N/A — engineering defect, not a judgment call |

## Self-consistency gate on blocking compliance flags

Because a blocking flag halts a statutory, money-adjacent process, the Compliance Agent never emits one
from a single reasoning pass. Per `docs/ai/agents/COMPLIANCE_AGENT.md` → Reasoning & Prompt Strategy, any
evaluation that would produce `flag_type = 'blocking'` runs twice — once retrieval-grounded against the
regulation knowledge base, once as a structured rule-engine pass over the same facts with no LLM
involvement — and a disagreement between the two automatically downgrades the output to `advisory` with
`result = 'warning'`, forcing a human to look at exactly the case where the deterministic rules and the
model's reading of the law disagreed. A blocking flag additionally requires the cited knowledge-base
passage to carry `review_status = 'human_reviewed'`; an `ingested`-but-unreviewed passage can only ever
support an advisory flag, regardless of how confident the retrieval was.

## Numbers are fetched, never generated

The single most important guardrail in this entire escalation model runs in code, not in a prompt: every
monetary figure appearing in a `reasoning` string must have arrived via a tool-call return value in the
same execution trace. A server-side numeric-consistency check parses every number quoted in `reasoning`
before persistence and rejects the write — logging an `ai_logs` integrity failure — if a figure cannot be
matched to a tool result. No confidence score, however high, exempts a decision from this check.

# Failure, Retry & Rollback

| Failure Point | Detection | Recovery |
|---|---|---|
| Calculation chunk job fails mid-batch (default chunk size 200 employees, Redis-backed job batching) | Laravel's native batch-failure callback | The whole run does not advance to `calculated`; only the failed chunk retries (bounded retry count, exponential backoff); a run never partially calculates as "done" |
| AI layer unreachable at Calculate → `calculated` | Payroll Manager's own trigger listener times out / connection refused | Run proceeds unaffected; `payroll_ai_validation_runs.verdict = 'skipped_ai_unavailable'`; rendered plainly as not-yet-validated, never as clean; Submit remains available to the human pipeline |
| Compliance Agent scan request times out or errors | `request_compliance_scan` call fails | The run's overall AI verdict is marked incomplete pending compliance input — never defaulted to "compliant" in the check's absence; a human may still proceed on their own judgment |
| A quoted figure in a draft `reasoning` string fails the numeric-consistency check | Server-side parser, pre-persistence | Rejected before a human ever sees it; logged to `ai_logs` as an integrity failure, treated as an engineering defect to fix, not a judgment call to surface |
| Rejection at any approval stage | Human action (`payroll.run.reject`) | Run returns fully to `draft` with a required `rejection_reason`; the entire three-stage chain restarts — there is no "resume from where it was rejected" |
| A statutory ruleset update lands while a run is `calculated` but not yet `approved` | Compliance Agent's rule-version comparison on the next validation pass | The run is automatically re-flagged `payroll_ai_validation_runs.verdict = 'stale'`; a fresh validation pass is required before Submit remains available |
| Post transaction fails partway (e.g., a `payroll_gl_account_mappings` row is missing for a component discovered only at Post time) | `PayrollJournalBuilder`'s own pre-flight check inside the transaction | The entire Post call rolls back atomically — `journal_entries` and `payroll_runs.status = 'posted'` change together in one DB transaction or neither changes; the run remains `approved`, Post is retried after Finance completes the mapping |
| WPS bank rejects one or more lines (closed account, invalid IBAN) | Bank acknowledgment webhook or manual upload rejection notice | Only the affected `payroll_items.payment_status` rows are set `'failed'` — the rest of the run's disbursement is unaffected and proceeds to `paid` as normal; Finance re-issues a corrected, smaller disbursement batch for just the failed lines once bank details are fixed |
| A posted-but-not-yet-paid run, or an already-paid run, is found to be wrong | Human discovery, or an Auditor finding | Never edited. `POST /api/v1/payroll/runs/{id}/reverse` creates the mirror-image journal entry, marks the run `reversed`, and spawns a fresh `draft` run pre-populated with corrected inputs |
| Idempotency: a retried orchestration step (network blip mid-pass) | Idempotency-keyed tool calls (`trigger_calculation`, `submit_decision`) | A retried call does not double-trigger a recalculation or duplicate a decision row; the same `Idempotency-Key` returns the original cached response |
| A domain event (`payroll.completed`, a WPS batch event) is delivered twice | `event_inbox` claim per `(event_id, consumer_module)` | Second delivery is a no-op for every consumer; none of Banking's transfer-confirmation logic, Notifications' payslip emails, or Treasury's liquidity recompute double-applies |
| Permanent failure (malformed payload, an `event_name` absent from `event_type_registry`) | Consumer-side validation | Never retried — routed straight to the dead-letter queue on first occurrence, since retrying a deterministic failure only delays visibility of a real defect |

Rollback, precisely defined for this workflow, never means an `UPDATE` against a posted `payroll_items`,
`payroll_item_lines`, or `journal_lines` row. It always means one of: (a) a chunk-level retry before the
run leaves `draft`/`calculated`, where re-running the calculation regenerates rather than appends; (b) a
full chain restart back to `draft` on rejection; or (c) a reversing journal entry plus a fresh draft run
after `posted`. This mirrors, without exception, the platform-wide rule that financial records are never
hard-deleted and posted lines are never mutated in place.

# SLAs & Timing

| Stage | Target | Notes |
|---|---|---|
| Draft → Calculate | Same business day the period closes (scheduled) or on demand | Not a hard SLA — human-initiated for manual runs |
| Calculation engine, per run | p50 < 1 minute for a company with several thousand employees | Chunked at 200 employees/job, parallelized across Redis-backed queue workers |
| AI validation pass (crosscheck + trend + anomaly scan) | p50 < 60 seconds, p95 < 5 minutes for a 200-employee run | Per `docs/ai/agents/PAYROLL_AGENT.md` → Metrics & Evaluation |
| Compliance pre-release scan | p95 < 2 minutes | Runs in parallel with, not after, the Payroll Manager's pass |
| Human flag acknowledgment (Payroll Officer) | Same business day as validation completes | Policy target, not system-enforced |
| Officer → Finance Manager approval | 24 business hours (company-configurable), auto-reminder at 12h | Notification-driven, not a hard timeout — a run does not auto-reject on SLA breach |
| Finance Manager → CEO approval | 24 business hours (company-configurable), auto-reminder at 12h | Same |
| Full chain, Submit → `approved` | Target ≤ 3 business days ahead of `pay_date` | Leaves headroom for Disburse and the WPS statutory payment window |
| Post (`approved → posted`) | Same business day as final approval | Atomic transaction; sub-second once triggered |
| Payslip generation (all employees) | < 10 minutes for a 1,000-employee run | Queued, one job per employee; never blocks the Post response |
| Disburse → bank acknowledgment → `paid` | Same day to +2 business days, bank-dependent | Tracked against the WPS statutory payment window (configurable days after `period_end`) |
| `payroll.completed` emission | Within seconds of the last `payroll_items.payment_status` reaching `paid` | Trigger-based, same transaction as the final status write |
| Critical (≥ 90 confidence) data-integrity flag → Auditor acknowledgment | < 4 business hours | Bypasses the normal chain notification path entirely — see Confidence & Escalation Rules |
| Compliance blocking flag → human clearance | Recommended < 1 business day | No hard system timeout; unresolved holds escalate visibility via the CFO's weekly digest |
| Treasury liquidity breach tied to this run | 12h to acknowledgment, else CEO notified directly | Per `docs/ai/agents/TREASURY_AGENT.md` |

# Worked Example

**Setup.** Al-Salam Trading Co. (`company_id 4821`), 42 active employees, monthly payroll,
`country_code = 'KW'`. Payroll period `payroll_periods` id `881` covers 2026-06-01 to 2026-06-30, with
`pay_date = 2026-07-05`. This example continues directly from the clean-run scenario in
`docs/ai/agents/PAYROLL_AGENT.md` → Worked Scenarios § 1, carried here through the full orchestration —
Post, disbursement, WPS export, and the `payroll.completed` event — which that document intentionally left
at "Fatima clicks Post."

**Step 1-2 — Draft and Calculate.** On 2026-07-02 at 05:00 Kuwait time, a scheduled job creates
`payroll_runs` id `55012` (`run_type = 'regular'`, `payroll_period_id = 881`) in `draft`. Payroll Officer
Fatima Al-Rashidi (`user_id 2290`) opens the run and clicks **Calculate**.
`PayrollCalculationService` executes the twelve-step engine from `docs/accounting/PAYROLL.md` →
Calculation Engine and writes `payroll_items`/`payroll_item_lines` for all 42 employees:

| Field | Value (KWD) |
|---|---|
| `total_gross` | 132,400.0000 |
| Employee PIFSS withholding (`social_insurance_employee`) | 8,850.0000 |
| Employer PIFSS cost (`social_insurance_employer`) | 12,980.0000 |
| Loan/advance repayments | 2,450.0000 |
| Other deductions (garnishment/disciplinary/uniform recovery) | 750.0000 |
| Indemnity accrual (`indemnity_accrual`, employer cost, non-cash) | 5,516.6667 |
| `total_net` | 120,350.0000 |
| `total_employer_cost` (12,980.0000 + 5,516.6667) | 18,496.6667 |

`total_net` reconciles exactly: `132,400.0000 − 8,850.0000 − 2,450.0000 − 750.0000 = 120,350.0000`.
`payroll_runs.status` moves to `calculated`.

**Step 3 — AI validation pass.** Within 45 seconds, triggered by `payroll_run.calculated`, the Payroll
Manager writes two `ai_decisions` rows:

```json
{
  "id": 770998,
  "agent_code": "PAYROLL_AGENT",
  "decision_type": "payroll_calculation_crosscheck",
  "status": "approved",
  "confidence_score": 99.10,
  "reasoning": "Shadow recompute matches posted figures exactly across all 42 employees and all seven component categories; zero residual difference.",
  "requires_approval": false
}
```

```json
{
  "id": 771001,
  "agent_code": "PAYROLL_AGENT",
  "decision_type": "payroll_trend_validation",
  "status": "approved",
  "confidence_score": 96.00,
  "reasoning": "Total gross moved +3.1% against the 3-month trailing average, fully explained by employee #1204's approved 2026-06-01 promotion (+KWD 450 base) and employee #1391's first-month proration. No unexplained residual movement.",
  "requires_approval": false
}
```

The anomaly scan finds nothing above the company's configured sensitivity threshold.

**Step 4 — Compliance pre-release scan.** Consuming the same `payroll_run.calculated` event, the
Compliance Agent runs `check_type = 'rate_check'` against requirement `KW_PIFSS_MONTHLY_CONTRIBUTION` and
`check_type = 'data_check'` against `KW_WPS_SALARY_FILE`:

```json
{
  "assessment_id": 90344,
  "company_id": 4821,
  "requirement_id": 2201,
  "check_type": "rate_check",
  "result": "pass",
  "confidence": 0.9700,
  "flag_type": "none",
  "reasoning": "Employee/employer PIFSS split (8850.0000 / 12980.0000) reconciles to the seeded KW social_insurance_rates row (8% employee, 11.5% employer) applied to this run's pifss_base; every social_insurance_eligible component correctly excluded the run's one commission line.",
  "sources": [
    { "table": "payroll_items", "id": null, "field": "pifss_base", "value": "run 55012, all 42 employees" },
    { "table": "compliance_requirement_templates", "id": 2201, "field": "code", "value": "KW_PIFSS_MONTHLY_CONTRIBUTION" }
  ]
}
```

`preview_wps_export(55012)` returns `ready: true` for all 42 employees — every active IBAN passes the
Kuwait IBAN checksum, no duplicate `iban_hash` across the run. A `payroll_ai_validation_runs` row is
written: `id 4402`, `verdict = 'clean'`, `flags_count = 0`, `critical_flags_count = 0`,
`safe_to_submit = true`.

**Step 5 — Treasury funding check.** Three business days ahead of `pay_date` (2026-07-02), Treasury
Manager's daily liquidity pass ingests `payroll_runs` id `55012`'s `pay_date` and `total_net` as a fixed,
non-discretionary outflow. `liquidity_ratio` remains comfortably above the company's configured floor; no
`treasury_liquidity_alert` fires — a routine `treasury_cash_position_summary` simply reflects the upcoming
disbursement.

**Step 6-7 — Review and Submit.** Fatima sees a single green "AI-validated: no flags" badge (Approval
Assistant having routed both decisions to her queue with `urgency: low`), reviews the register herself,
and clicks **Submit** — a purely human action; no AI tool exists to perform it. The run enters
`pending_officer_approval`; Fatima's Submit, combined with her role as Payroll Officer of record, satisfies
the Officer stage.

**Step 8 — Three-stage approval.** Finance Manager Yousef Al-Kandari (`user_id 1187`) reviews the AI
validation badge and the clean Compliance verdict, and approves → `pending_ceo_approval`. CEO
(`user_id 101`) approves → `approved`. `payroll.approved` fires.

**Step 9 — Post.** Fatima clicks **Post**. `PayrollJournalBuilder` writes the accrual entry
(`journal_entries` id `90512`) atomically with `payroll_runs.status = 'posted'`:

| Line | Account | Debit (KWD) | Credit (KWD) |
|---|---|---:|---:|
| 1 | 5100 · Salaries & Wages Expense | 132,400.0000 | |
| 2 | 5150 · Employer Social Insurance Expense | 12,980.0000 | |
| 3 | 5160 · End-of-Service Indemnity Expense | 5,516.6667 | |
| 4 | 2100 · Salaries Payable (net) | | 120,350.0000 |
| 5 | 2110 · PIFSS Payable (employee + employer) | | 21,830.0000 |
| 6 | 2120 · Employee Loans Receivable | | 2,450.0000 |
| 7 | 2150 · Other Payroll Deductions Payable | | 750.0000 |
| 8 | 2130 · Provision for End-of-Service Indemnity | | 5,516.6667 |
| — | **Total** | **150,896.6667** | **150,896.6667** |

`journal.posted` fires. Payslips generate automatically for all 42 employees (`is_visible_to_employee =
false`); the Approval Assistant narrates them bilingually from the Payroll Manager's already-validated
content.

**Step 10 — Disburse.** Yousef clicks **Disburse** on `pay_date` (2026-07-05). `WpsFileGenerator`
produces `wps_export_batches` id `6120` (`bank_code = 'NBK'`, `file_format = 'CBK_SIF'`,
`total_amount = 120,350.0000`, `employee_count = 42`, `status = 'generated'`) — already known-clean from
the step-4 preview. Finance uploads it to the bank portal; `status → 'submitted'`. The settlement journal
entry (`journal_entries` id `90513`) posts: Debit `2100 · Salaries Payable` 120,350.0000, Credit
`1010 · Bank — Operating Account` 120,350.0000.

**Step 11 — Confirmation.** The bank acknowledges within one business day; `wps_export_batches.status →
'acknowledged'`. As the acknowledgment webhook clears, all 42 `payroll_items.payment_status` rows move to
`paid`, and `payroll_runs.status` moves to `paid`. Each employee's payslip becomes visible the moment their
own line clears.

**Step 12 — Close-out.** With every `payroll_items.payment_status = 'paid'` and every `payslips` row
generated, the trigger fires `payroll.completed` (exact payload shown in Events Emitted/Consumed above).
Accounting confirms `journal_entry_id 90512` is posted; Banking's bulk-transfer consumer reconciles the
WPS batch against the bank statement line once it arrives; Notifications emails 42 payslip-ready
notices. Total elapsed time from `calculated` to `payroll.completed`: under 4 days, entirely inside the
3-business-day-ahead-of-`pay_date` target. Zero AI-authored mutations occurred anywhere in the run; every
state transition from `calculated` onward was a human click. Weeks later, the Auditor's own scheduled
sampling independently re-derives this run's PIFSS split and indemnity accrual from the same source data,
confirming the Payroll Manager's original crosscheck (decision `770998`) without needing to trust it.

# Controls & Audit

## The four-layer approval guardrail

Payroll release — the moment an approved run's numbers stop being an internal draft and become money
actually leaving the company — is enforced four separate times, at four separate layers, deliberately
redundant so a defect in any single layer cannot itself become a bypass:

1. **RBAC layer.** No AI service-account role is ever granted `payroll.run.approve`, `payroll.run.post`,
   or `payroll.disburse`, under any company configuration. This is a platform invariant, not a per-company
   setting an admin could misconfigure.
2. **Application layer.** Every approval-tagged endpoint additionally checks that the calling principal is
   a human user (`users.is_service_account = false`) before evaluating the permission at all — enforced at
   the database level via a `CHECK` constraint on `payroll_run_approvals.approver_id`.
3. **Database layer.** The moment `payroll_runs.status` leaves `draft`/`calculated`, a Postgres
   `BEFORE UPDATE` trigger on `payroll_items`/`payroll_item_lines` raises an exception on any attempted
   write outside the Post/Disburse transactions themselves.
4. **UI layer.** Every AI recommendation renders as a card with visible `reasoning` and `sources` and an
   explicit Accept/Override/Dismiss control — never a pre-checked "approve" button. Accepting a
   recommendation and approving a run are always two distinct, separately logged actions.

## Segregation of duties

The three-stage chain (Payroll Officer → Finance Manager → CEO) requires three named, distinct human
accounts at three distinct permission tiers — `PayrollApprovalService` enforces the caller's role matches
the run's current stage, rejecting a mismatched attempt with `409 Conflict` even if the caller separately
holds `payroll.run.approve`. A single person preparing, submitting, *and* being the sole approver at every
stage is architecturally impossible for any company with more than one Finance-tier user configured; where
a Payroll Officer is also the sole qualifying approver at their own stage (as in the Worked Example above),
the remaining two stages still require two additional, independent human sign-offs before any money moves.
Every human override of an AI or Compliance flag requires a recorded `override_reason`
(`payroll_run_approvals.override_reason`) — a permanent, queryable part of the audit trail, never a silent
click-through.

## MFA

Every human approval action in the chain — Officer, Finance Manager, and CEO stage alike — requires a
session with `mfa_verified: true`, regardless of the approver's base role. A session that has authenticated
but not completed MFA can view the Payroll Manager's validation report but is rejected with
`403`/`mfa_required` on the approval action itself.

## Salary privacy

Salary and identity data carry protection independent of, and in addition to, ordinary RBAC:

- **`payroll.salary.view`** gates every individual salary amount — not implied by `accounting.read` or any
  generic manager role. Row-level security additionally scopes it to a branch-level Payroll Officer's own
  branch, mirroring the platform's company-isolation RLS pattern extended with a branch predicate.
- **PII is never sent to the AI layer in plaintext, under any permission.** `national_id`, `passport_number`,
  and bank account/IBAN fields cross into the FastAPI layer only as a display mask (`***-***-1234`) and a
  deterministic salted hash (`national_id_hash`, `iban_hash` — HMAC-SHA256 keyed per company) — sufficient
  for duplicate-detection anomaly signals without the AI layer, its prompts, or its logs ever holding a real
  number. Laravel computes and masks these at write time; every agent in this workflow only ever reads the
  derived form.
- **Encryption at rest.** `national_id_encrypted`, `passport_number_encrypted`, `iban_encrypted`, and
  `account_number_encrypted` use AES-256-GCM (Laravel `encrypted` cast), rotated per the platform's
  standing key-rotation policy.
- **`ai_memory` is strictly per-company.** A threshold or pattern the Payroll Manager learns from one
  company's payroll history never adjusts a threshold applied to another company — enforced by
  `company_id`-keying in both the Postgres row and the Redis cache key, not by convention.

## Retention and audit trail

Every mutation to `employees` (payroll-relevant fields), `employee_salary_components`, `employee_loans`,
`payroll_runs` (every status transition), and `payroll_run_approvals` writes to `audit_logs` with
old-value/new-value diffs, actor, timestamp, IP, and device — AI-agent-authored recommendation records are
tagged with the agent name and confidence rather than a human `user_id`, so the trail always distinguishes
"a human decided this" from "AI suggested this and a human decided." Payroll records are retained a
statutory minimum of 5 years in Kuwait; QAYD defaults to 10 years for the full employee ledger, enforced
via `docs/ai/agents/COMPLIANCE_AGENT.md`'s `retention_policies`/`retention_holds` tables — a run under an
active legal hold cannot be archived regardless of retention-age.

## The Auditor as a detective control

The Auditor never gates this workflow at any step — it is a detective, not a preventive, control by
architecture, holding no create/update/delete/post/approve/reverse/void permission on any table this
workflow touches. Its participation is entirely asynchronous and post-hoc: on its own schedule, it samples
historical `ai_decisions` rows the Payroll Manager produced, independently re-deriving the PIFSS split and
indemnity accrual against already-posted, already-paid runs to verify the Payroll Manager's own historical
accuracy (Worked Example, Step 12), and it re-checks `payroll_run_approvals` for segregation-of-duties
violations (creator-equals-approver, missing approval levels) across every run in a period. Its only
real-time interaction with this specific workflow is receiving the ≥ 90-confidence data-integrity
notification described in Confidence & Escalation Rules — everything else is a rolling control-health
signal consumed by the CFO Agent and Reporting Agent, never a gate this workflow waits on.

# Edge Cases

| Edge Case | Handling |
|---|---|
| Payroll Officer unavailable mid-chain (leave, absence) | Finance Manager or Owner reassigns the pending `payroll_run_approvals` stage to an eligible alternate holder of `payroll.run.approve` at that stage; the stage sequence itself is never skipped, only the individual approver changes, and the reassignment is itself an audited action |
| Two runs for the same period in flight simultaneously (a `regular` run plus a `supplementary` run for a late new hire) | Each is an independently gated state machine; each posts its own journal entry; they are never merged into one GL posting |
| Fiscal period closes while a run is still mid-approval-chain | Compliance Agent's synchronous `fiscal_period.close_requested` gate holds the close while an unposted run references that period; Finance must either post/reject the run first or explicitly override with a logged reason |
| A bank holiday shifts `pay_date` | The statutory WPS payment-window deadline recalculates from the shifted date; Compliance flags (advisory, or blocking if the shift itself breaches the window) accordingly |
| A new employee is added to payroll scope after Submit | Excluded from the already-submitted run (its `payroll_items` are locked at Submit); added to the next `regular` run or a same-period `supplementary` run instead |
| AI layer partially degraded (Compliance reachable, Payroll Manager is not, or vice versa) | Each agent's own availability is tracked independently in `payroll_ai_validation_runs`; a partial pass is rendered as partial, never rounded up to "fully validated" |
| A `payroll.completed` webhook (or any WPS batch event) is delivered twice | No-op on redelivery per the `event_inbox` claim — see Failure, Retry & Rollback |
| A multi-branch company runs per-branch payroll simultaneously with `payroll.journal_granularity = 'per_dimension'` | Each branch's run is an independent state machine and posts its own journal entry; the consolidated Payroll Cost report is unaffected since it is derived from dimension-tagged `journal_lines`, not from run count |
| A company holds WPS-exemption status and pays predominantly by cash/cheque | The disbursement step skips WPS export entirely for those `payroll_items`; a signed cash-disbursement receipt (`payroll_cash_disbursements`) or cheque issuance record substitutes as audit evidence |
| A run is Submitted, then a statutory rate correction lands from the Compliance Agent's rule store before approval completes | The run is automatically re-flagged `stale`; the approval chain does not continue against a validation performed against a ruleset version that no longer exists — a fresh validation pass is required before the next approval stage may act |
| A confidence score computed by any agent in this workflow would round to 100 | Hard-capped at 99.90 in the output-serialization layer — never surfaced as certain |

# Future Improvements

- **Real-time WPS bank API submission**, replacing the manual SIF-file upload with a direct bank-specific
  Laravel integration adapter where the receiving bank exposes one — the `preview_wps_export` contract does
  not change, only what happens after `generated`.
- **Continuous, cross-workflow readiness scoring.** Rather than surfacing a gap (missing bank account,
  unmapped GL account) only when a run reaches `calculated`, the reserved `payroll_readiness_score`
  decision type would run continuously against `active`/`onboarding` employees, days ahead of month-end.
- **Joint funding what-if with the Forecast Agent and Treasury Manager**: "what would next month's run
  cost, and can the operating account cover it, if the proposed 2027 raise band takes effect now" —
  computed on the same validated component rules, surfaced as a suggest-only scenario.
- **A natural-language query surface for Finance** ("why did this month's payroll run rise 12%?"), backed
  by exactly the data this document already specifies — no new data path, a conversational front end over
  `ai_conversations`/`ai_messages` atop the existing tool calls.
- **An active-learning loop from confirmed overrides.** Every human acceptance, edit, or dismissal of a
  Payroll Manager or Compliance flag is training signal for this company's own recalibrated thresholds,
  per the learning loop described in `docs/ai/memory/*` — never crossing into another company's model
  behavior.
- **Smarter delegation-aware approval routing**, informed by `leave_requests` QAYD already holds, so a
  pending approval automatically suggests (never auto-assigns) the correct alternate approver when the
  primary one is on approved leave, closing the Edge Cases gap above with less manual reassignment.
- **Opt-in, anonymized cross-company benchmarking** of payroll-cost-per-employee and overtime rate against
  an anonymized peer aggregate — never enabled by default, never crossing tenant boundaries without
  affirmative, revocable consent.

# End of Document
