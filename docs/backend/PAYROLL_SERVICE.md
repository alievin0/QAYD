# Payroll Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: PAYROLL_SERVICE
---

# Purpose

This document specifies the backend **Payroll Service** — the module that owns employees, salary
structures, and the payroll-run engine that calculates, reviews, approves, posts, and releases pay for
Kuwait/GCC organisations. It is the concrete instantiation of the shared service-layer contract in
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) for the payroll concern, and the backend counterpart
of [../accounting/PAYROLL.md](../accounting/PAYROLL.md).

Payroll is the most sensitive source module on the platform for three reasons, each of which shapes the
design below. First, it moves money: a released run is a real disbursement, so the run engine is
approval-gated, idempotent, and its posted journal is immutable. Second, it is heavy statutory
computation: PIFSS/GOSI social-insurance contributions, end-of-service indemnity accrual, and Kuwait
Labour Law provisions must be applied correctly and kept as editable rate data, not hard-coded constants.
Third, it holds the most sensitive PII on the platform — salaries, national IDs, bank accounts — so
field-level encryption, salary-visibility scoping, and an immutable payslip guard are first-class
concerns, cross-linked to [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md).

Like every source module, Payroll records the payroll facts and *announces* them; it does not post
journal entries itself. When a run is approved it hands off to [ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md),
which posts the balanced payroll journal. This document specifies the employee/salary model, the run
engine and its state machine, the statutory mechanisms, payslips, and the async run job — and references
the posting engine rather than duplicating it.

# Responsibilities

The Payroll Service owns:

- **Employees and salary structures.** The payroll-relevant employee master (personal data, national/
  civil ID, PIFSS number, insurance status, bank account, department/branch/position, employment type)
  and effective-dated salary components (base salary, allowances, commission, overtime rules, statutory
  components).
- **The payroll-run engine.** The deterministic calculate -> review -> approve -> post -> release pipeline,
  including pro-ration, attendance/leave-driven pay, variable earnings, statutory contributions, loan/
  advance installments, and net-pay computation, with full line-item traceability.
- **Deductions, allowances, and accruals.** Loans/advances, disciplinary/garnishment deductions
  (statutory-capped), allowances, and the monthly **end-of-service indemnity accrual** (an employer-cost,
  non-cash provision).
- **PIFSS/GOSI social insurance.** Employee and employer contribution computation from the eligible base,
  as an editable `country_code`-scoped rate table, plus the monthly contribution schedule for filing.
- **Payslips.** Immutable, bilingual (EN/AR) payslip PDFs generated on posting and made visible to the
  employee once their pay is released.
- **Async run execution.** Running a payroll calculation as a queued job with progress, so a large
  workforce's run does not block a request.
- **Statutory outputs.** The WPS (Wage Protection System) bank file / Salary Information File on
  disbursement, and the PIFSS contribution schedule.

Explicitly **not** its responsibility: the posting engine and the GL (Accounting), the bank transfer
mechanics of a payment (Banking executes the transfer; Payroll produces the file), corporate tax/Zakat
(Tax module), or general reporting assembly (Reporting).

# Domain Model

- **Employee** — the payroll-relevant extension of the foundation employee: `employment_status`
  (`applicant -> hired -> onboarding -> active -> on_leave -> terminated -> archived`), `employment_type`,
  civil/national ID, `pifss_number`, `is_gcc_national`, `insurance_status` (`covered`/`exempt`), preferred
  payment method, and encrypted bank/PII fields. A rehire is a new employment period against the same
  `employee_id`, which matters for cumulative-service indemnity.
- **SalaryComponent** / **EmployeeSalaryComponent** — the catalogue of earning/deduction/statutory
  components (each flagged `is_social_insurance_eligible` for the PIFSS base) and the effective-dated
  assignment of a component to an employee at an amount/formula. A future raise is a new effective-dated
  row, never an edit of a posted run's inputs.
- **PayrollPeriod** — the pay cycle (typically monthly) a run belongs to.
- **PayrollRun** — the aggregate the engine operates on: `run_type`, `status`
  (`draft -> calculated -> pending_officer_approval -> pending_finance_approval -> pending_ceo_approval ->
  approved -> posted -> paid`, or `reversed`), run-level totals (`total_gross`, `total_net`,
  `total_employer_cost`) that are always `SUM()` over its items, and links to the posted `journal_entry_id`
  and any `reversed_run_id`.
- **PayrollRunApproval** — one immutable row per approval stage (`payroll_officer`/`finance_manager`/`ceo`),
  recording approver, decision, comment, and any `override_reason`; `UNIQUE (payroll_run_id, stage)`.
- **PayrollItem** — one row per employee per run: `gross_salary`, `pifss_base`, `total_deductions`,
  `total_statutory`, `net_salary`, `employer_cost`, `proration_factor`, `payment_method`, `payment_status`
  (`pending`/`paid`/`failed`); `UNIQUE (payroll_run_id, employee_id)`.
- **PayrollItemLine** — one row per component per item; every dinar on the payslip traces to exactly one
  line with a `salary_component_id` and, where applicable, a `source_reference` (an `attendance` range or a
  `leave_requests.id`).
- **Payslip** — the immutable rendered document, one per `(payroll_item, language)`, visible to the
  employee only once their pay is released.
- **WpsExportBatch** — the bank-format Salary Information File produced at disbursement, moving
  `generated -> submitted -> acknowledged` (or `failed`).

The invariants: **run totals are derived, never independently posted** (they equal the sum of their
items, so they cannot drift); **items/lines are read-only once the run leaves `draft`/`calculated`**
(enforced by both the Service layer and a PostgreSQL `BEFORE UPDATE` trigger); and **a posted/released run
is immutable** — a correction is a reversing journal plus a fresh draft, never an edit.

# Key Classes

- `App\Actions\Payroll\CreatePayrollRunAction` — open a `draft` run for a period.
- `App\Services\Payroll\PayrollCalculationService` — the deterministic per-employee calculation engine
  (the twelve-step order below); idempotent and re-runnable while `draft`/`calculated`.
- `App\Actions\Payroll\CalculatePayrollRunAction` — dispatch the calculation (inline for small runs,
  queued `CalculatePayrollRun` for large ones); regenerates items/lines rather than appending.
- `App\Actions\Payroll\SubmitPayrollRunAction` / `ApprovePayrollRunAction` / `RejectPayrollRunAction` — the
  approval-chain transitions, each writing a `payroll_run_approvals` row and enforcing maker != checker.
- `App\Actions\Payroll\PostPayrollRunAction` — build one balanced journal draft and hand off to Accounting
  atomically with `payroll_runs.status = 'posted'` (permission `payroll.run.post`).
- `App\Actions\Payroll\ReleasePayrollRunAction` — the disbursement action (`posted -> paid`, permission
  `payroll.release`/`payroll.disburse`), generating payment instructions and the WPS file.
- `App\Services\Payroll\StatutoryService` — PIFSS/GOSI contribution and indemnity accrual computation from
  the `country_code`-scoped rate tables.
- `App\Services\Payroll\PayrollJournalBuilder` — maps each `payroll_item_lines.component_type` to a GL
  account via `payroll_gl_account_mappings` and assembles the balanced draft.
- `App\Services\Payroll\WpsFileGenerator` — produces the bank SIF; `App\Services\Payroll\PayslipRenderer`
  renders the immutable bilingual PDF into R2.
- `App\Jobs\Payroll\CalculatePayrollRun` — the queued calculation worker (`TenantAwareJob`, queue
  `payroll`).

The calculation engine executes, per employee per run, in a fixed order so later components can reference
earlier ones deterministically:

1. Resolve **active salary components** as of the period (effective-dated).
2. Resolve **pro-ration** for mid-period hire/termination (`days_employed / total_days`).
3. Aggregate **attendance-derived earnings** (regular hours, overtime x multiplier, night-shift, holiday).
4. Aggregate **leave-driven pay** (full/half/unpaid treatment per `leave_types.pay_treatment`).
5. Pull **pending variable earnings** (commission ledger, one-off bonuses dated in period).
6. Sum **gross_salary** and, separately, **pifss_base** (the social-insurance-eligible subset).
7. Compute **statutory contributions**: `social_insurance_employee`, `social_insurance_employer`,
   `tax_withholding` (a real 0.0000 for Kuwait), `indemnity_accrual` (employer-cost accrual, not deducted).
8. Compute **loan/advance installments** due this period.
9. Compute **other deductions** (unexcused absence, disciplinary, garnishment capped at the statutory %).
10. Compute **net_salary** = gross - employee statutory withholdings - installments - other deductions.
11. Persist one `payroll_items` row per employee + one `payroll_item_lines` row per component.
12. Aggregate run totals as `SUM()` over items — never independently recomputed.

```php
namespace App\Actions\Payroll;

use App\Events\Payroll\PayrollRunApproved;
use App\Exceptions\Payroll\MakerCheckerViolationException;
use App\Models\Payroll\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollJournalBuilder;
use App\Support\Enums\PayrollRunStatus;
use Illuminate\Support\Facades\DB;

final class PostPayrollRunAction
{
    public function __construct(private readonly PayrollJournalBuilder $builder) {}

    /** approved -> posted: build the balanced journal and hand off to Accounting atomically. */
    public function execute(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Approved) {
            throw new \App\Exceptions\Payroll\InvalidStateTransitionException($run->status, 'post');
        }

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            // Build ONE balanced draft (or one per dimension if configured). Accounting owns the post.
            $draft = $this->builder->forRun($run);          // Dr expenses / Cr payables + provisions

            // The journal post and the status flip commit together — both succeed or neither does.
            $entry = app(\App\Actions\Accounting\PostJournalEntryAction::class)->execute(
                $draft,
                idempotencyKey: "payroll_run:{$run->id}:post",
            );

            $run->update([
                'status'           => PayrollRunStatus::Posted,
                'journal_entry_id' => $entry->id,
                'posted_at'        => now(),
            ]);

            event(new PayrollRunApproved($run, actorId: $actor->id));   // dispatched after commit
            return $run->refresh();
        });
    }
}
```

The full posted entry balances expense against payables and provisions (worked in
[../accounting/PAYROLL.md](../accounting/PAYROLL.md)) — e.g. `Dr Salaries & Wages Expense`,
`Dr Employer Social Insurance Expense`, `Dr End-of-Service Indemnity Expense` against
`Cr Salaries Payable (net)`, `Cr PIFSS Payable`, `Cr Employee Loans Receivable`, and
`Cr Provision for End-of-Service Indemnity`. Disbursement then posts a second entry clearing Salaries
Payable against the bank account, so accrual of the liability (post) and settlement (release) are separate
journals — Accounting posts both; Payroll only supplies the drafts.

## Statutory mechanisms (PIFSS/GOSI, indemnity, WPS)

`StatutoryService` implements Kuwait/GCC statutory rules as a **country strategy** — every rule is resolved
through a `country_code`-scoped rate table (`statutory_rules`, `social_insurance_rates`, `overtime_rules`),
never hard-coded, so the initial Kuwait ruleset ships as data and Saudi GOSI, UAE, and other GCC schemes
are added the same way. This document describes the mechanism; the authoritative figures live in the seed
data and are kept in sync with the issuing authority's circulars, because statutory rates are periodically
revised.

- **Social insurance (PIFSS in Kuwait; GOSI in Saudi).** The employee share and the employer share are
  both computed from the same `pifss_base` — the sum of components flagged `is_social_insurance_eligible`
  (base wage plus the specific fixed allowances the regulation designates), excluding variable pay
  (commission, overtime, one-off bonuses) — but at different rates and subject to a maximum contributable
  wage ceiling. Both rates and the ceiling are editable `social_insurance_rates` rows keyed by
  `nationality_category` (Kuwaiti national, GCC national, non-GCC expatriate — the last being exempt), so a
  rate revision is a data change, not a deploy. The employer share is materially higher than the employee
  share, reflecting Kuwait's employer-funded design, and for Kuwait these contributions are the retirement
  mechanism (no separate pension component). Payroll produces a monthly PIFSS contribution schedule (PIFSS
  number, base, employee share, employer share per covered employee) formatted for the authority's
  e-services portal; direct API submission is additive future work, not a redesign.
- **End-of-service indemnity.** Accrued **monthly** as an employer-cost, non-cash component
  (`indemnity_accrual`) so the Balance Sheet's Provision for Indemnity always reflects the running
  obligation rather than a surprise lump sum — mirroring how SAP/Oracle treat Gulf gratuity provisioning.
  The mechanism: an entitlement of 15 days' wage per year for the first five years and one month's wage per
  year thereafter, computed on the last basic wage, pro-rated for partial years, capped at 18 months, with
  a resignation schedule (`indemnity_resignation_schedule` rate table) reducing entitlement for short
  service. The **actual** calculation happens once, at termination, in the `final_settlement` run from the
  full service history (respecting rehire accumulation/reset rules); any difference from the accrued
  provision is a one-time GL adjustment at settlement — the provision is an estimate, the settlement is the
  authoritative statutory figure.
- **Tax.** Kuwait levies no personal income tax, so `tax_withholding` always computes to a real `0.0000`
  for `country_code = 'KW'` — the component and its full plumbing (calculation, journal line, payslip line)
  are exercised as a proven-correct zero, so the same path is ready for a future country requiring nonzero
  withholding without a redesign.
- **WPS (Wage Protection System).** On disbursement, `WpsFileGenerator` produces a bank-format Salary
  Information File (CSV or fixed-width per the receiving bank's specification) containing, per employee,
  civil ID, IBAN, net amount, currency, and a payment reference tying back to `payroll_items.id`; the file
  is attached to a `wps_export_batches` row that moves `generated -> submitted -> acknowledged`/`failed`.
  Payroll tracks the statutory payment window (salary due within a configurable number of days after
  `payroll_periods.period_end`) and the Compliance Agent raises a warning if a run has not reached `paid`
  within it.

## The approval state machine

The run moves through a strictly ordered, fully audited chain, and any approver may reject at their stage,
returning the run to `draft` with a required `rejection_reason` that re-opens it for recalculation:

```
draft --calculate--> calculated --submit--> pending_officer_approval
   --(Payroll Officer)--> pending_finance_approval
   --(Finance Manager)--> pending_ceo_approval
   --(CEO)--> approved --post--> posted --disburse--> paid
```

Each transition writes an immutable `payroll_run_approvals` row (`approver_id`, `role_at_approval`,
`decision`, `comment`, `decided_at`, optional `override_reason`), so the complete decision trail — who
approved what, when, and why any critical AI flag was overridden — is reconstructable and non-editable. The
moment a run leaves `draft`/`calculated`, its items and lines are read-only at both the Service layer and
the database (the `BEFORE UPDATE` trigger), so nothing under an approved run can silently change beneath an
approver who has already signed off.

## Payslips and sensitive-PII handling

A `payslips` row (a bilingual PDF rendered from an HTML template in the employee's preferred language,
stored in R2 and linked via `attachment_id`) is generated automatically the moment a run is `posted` —
before disbursement — so HR/Finance can review the exact document the employee will receive. But
`is_visible_to_employee` is set true only once the individual `payroll_items.payment_status` reaches
`paid`: the document is generated early, its *visibility* is gated on payment completion, so an employee is
never shown a promise of pay that a reversal later revokes. Payslips are immutable once released — a
correction is an arrears line in a future run or a reversal, never an edit — and the RLS self-service guard
restricts an employee session to their own released payslips at the database layer.

Sensitive PII (national/civil ID, IBAN, wallet id) is encrypted at rest (field-level), never returned
without `payroll.pii.view`, and never written to logs or emitted in event payloads — cross-linked to
[../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md). Structured logs record the actor, company,
permission checked, and outcome, never the monetary payload or the PII values themselves.

# Endpoints Backed

All endpoints are versioned under `/api/v1/payroll/`, require a Sanctum/JWT bearer token, are scoped to
the active company via `X-Company-Id`, and return the standard platform envelope per
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md). Money-moving transitions (post, release) require an
`Idempotency-Key`.

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | /api/v1/payroll/employees/{id}/salary-components | payroll.salary.view | View an employee's salary structure |
| PUT | /api/v1/payroll/employees/{id}/salary-components | payroll.salary.manage | Set/update salary components (effective-dated) |
| GET | /api/v1/payroll/periods | payroll.read | List payroll periods |
| POST | /api/v1/payroll/periods | payroll.periods.manage | Create a payroll period |
| POST | /api/v1/payroll/runs | payroll.run.create | Create a payroll run (draft) |
| POST | /api/v1/payroll/runs/{id}/calculate | payroll.calculate | Calculate/recalculate a run (async for large runs) |
| GET | /api/v1/payroll/runs/{id} | payroll.read | Retrieve a run with status/totals |
| GET | /api/v1/payroll/runs/{id}/items | payroll.read | List per-employee items (salary detail scoped) |
| POST | /api/v1/payroll/runs/{id}/submit | payroll.run.submit | Submit a calculated run for approval |
| POST | /api/v1/payroll/runs/{id}/approve | payroll.approve | Approve at the caller's stage |
| POST | /api/v1/payroll/runs/{id}/reject | payroll.approve | Reject with a required reason (returns to draft) |
| POST | /api/v1/payroll/runs/{id}/post | payroll.run.post | Post the payroll journal via Accounting |
| POST | /api/v1/payroll/runs/{id}/disburse | payroll.release | Release/disburse pay; generate the WPS file |
| POST | /api/v1/payroll/runs/{id}/reverse | payroll.run.reverse | Reverse a posted run (mirror journal + fresh draft) |
| GET | /api/v1/payroll/payslips/{id} | payroll.payslip.view / .self | Retrieve a payslip (self-service or admin) |
| GET | /api/v1/payroll/employees/{id}/payslips | payroll.payslip.view | List an employee's payslips |
| POST | /api/v1/payroll/loans | payroll.loan.create | Create a loan/advance |
| POST | /api/v1/payroll/loans/{id}/approve | payroll.loan.approve | Approve a loan |
| POST | /api/v1/payroll/bonuses | payroll.bonus.create | Record a one-off bonus |
| GET | /api/v1/payroll/reports/summary | payroll.read | Aggregate payroll summary |
| POST | /api/v1/payroll/runs/bulk-calculate | payroll.calculate | Calculate multiple runs (queued) |

```json
{
  "success": true,
  "data": {
    "run_id": 512,
    "status": "pending_finance_approval",
    "period": "2026-07",
    "total_gross": "50000.0000",
    "total_net": "43050.0000",
    "total_employer_cost": "57583.3333",
    "employee_count": 42,
    "currency_code": "KWD"
  },
  "message": "Run approved by Payroll Officer; routed to Finance Manager",
  "errors": [],
  "meta": {},
  "request_id": "d2a4f6b8-1122-4c33-9e44-5566778899aa",
  "timestamp": "2026-07-20T10:12:00Z"
}
```

# Database Tables Owned

The Payroll Service owns these tables (full DDL in [../accounting/PAYROLL.md](../accounting/PAYROLL.md)).
All carry the platform standard columns; money is `NUMERIC(19,4)`; every table is `company_id NOT NULL`.

| Table | Role | Notes |
|---|---|---|
| `employees` | Payroll employee master | `employment_status`/`employment_type` enums; encrypted PII; `pifss_number`, `insurance_status` |
| `salary_components` / `employee_salary_components` | Component catalogue + effective-dated assignment | `is_social_insurance_eligible` flag drives the PIFSS base |
| `employee_loans` / `loan_installments` | Loans/advances + schedule | installments due within a period feed the calc |
| `leave_types` / `leave_requests` | Leave catalogue + requests | `pay_treatment` (full/half/unpaid/tiered) |
| `attendance` | Clock-in/out, overtime, absence | source of attendance-derived earnings |
| `payroll_periods` | Pay cycles | monthly by default |
| `payroll_runs` | The run aggregate | `status`, derived totals, `journal_entry_id`, `reversed_run_id` |
| `payroll_run_approvals` | Immutable approval trail | stage/approver/decision/override; `UNIQUE (run, stage)` |
| `payroll_items` | Per-employee per-run | gross/pifss_base/net/employer_cost/proration; `UNIQUE (run, employee)` |
| `payroll_item_lines` | Per-component line detail | full traceability to a component + `source_reference` |
| `payslips` | Immutable rendered documents | one per `(item, language)`; `is_visible_to_employee` gated on release |
| `wps_export_batches` | Bank Salary Information File | `generated -> submitted -> acknowledged`/`failed` |

Statutory rate data lives in the `country_code`-scoped configuration tables (`statutory_rules`,
`social_insurance_rates`, `overtime_rules`, and the seed for `indemnity_resignation_schedule`), read by
`StatutoryService` — these are data, not code, so a PIFSS rate revision is a data change, not a deploy.
GL-account mapping lives in `payroll_gl_account_mappings` so Finance can remap accounts without engineering.

# Multi-Tenancy Enforcement

Payroll is company-scoped per [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md), with an extra layer of PII scoping
on top of the standard tenant isolation.

- **Ambient tenant scope.** Every table is `company_id NOT NULL`; `BelongsToCompany` fills it and
  `CompanyScope` scopes reads. An employee, run, item, or payslip query can never cross a tenant boundary,
  and a cross-tenant id 404s at route-model binding.
- **RLS backstop.** Every statement carries `SET LOCAL app.current_company_id`; the RLS policies on
  `payroll_items`, `payslips`, and `employees` filter even a query that forgot its predicate — essential
  because payroll data is the highest-value target on the platform.
- **The payslip immutability + visibility RLS guard.** `payslips.is_visible_to_employee` is only set true
  once the item's `payment_status` reaches `paid`, and the RLS policy for a self-service (employee-role)
  session additionally restricts `payslips`/`payroll_items` to rows for that employee's own `employee_id`
  — so an employee session can read only their own released payslips, never a colleague's and never an
  unreleased one, enforced at the database, not only the application.
- **Item/line write-lock.** A PostgreSQL `BEFORE UPDATE` trigger raises if a `payroll_items`/
  `payroll_item_lines` row is modified while its run's status is not in (`draft`,`calculated`) — defense in
  depth against an application bug bypassing the Service-layer lock.
- **Jobs re-bind tenant context.** `CalculatePayrollRun` extends `TenantAwareJob`, re-issuing
  `SET LOCAL app.current_company_id` under PgBouncer transaction pooling before touching any employee row.
- **No cross-tenant path.** Only the `RequirePlatformAdmin` analytics path may read across companies; no
  operational payroll query uses `withoutCompanyScope()`.

# Events, Queues & Realtime

Payroll emits money-affecting events that Accounting consumes, and runs its heavy calculation on the queue.

**Events emitted** (past-tense, ids + minimal payload): `PayrollRunCalculated`, `PayrollRunSubmitted`,
`PayrollRunApproved`, `PayrollRunPosted`, `PayrollReleased`, `PayslipGenerated`, `PayrollRunReversed`, and
the fraud/anomaly `PayrollAnomalyFlagged`. `PayrollReleased` and high-confidence fraud flags additionally
notify the Auditor role independently of the approval chain, so a compromised Payroll Officer account
cannot suppress the alert.

**Ledger posting is Accounting's job.** `PostPayrollRunAction` calls `PostJournalEntryAction` directly
within the same module boundary for the atomic post+status-flip (both are same-transaction requirements),
using `payroll_run:{id}:post` as the idempotency guard; the disbursement's second journal
(`payroll_disbursement`) is posted from the `PayrollReleased` handler.

**Queues.** Per [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md), the calculation of a large workforce
runs on the named `payroll` queue as a tenant-aware, idempotent job with progress:

```php
namespace App\Jobs\Payroll;

use App\Jobs\Concerns\TenantAwareJob;
use App\Services\Payroll\PayrollCalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class CalculatePayrollRun extends TenantAwareJob implements ShouldQueue
{
    public string $queue = 'payroll';
    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $companyId,     // required for tenant re-binding
        public readonly int $payrollRunId,
    ) {}

    public function handle(PayrollCalculationService $calc): void
    {
        $this->bindTenant($this->companyId);            // SET LOCAL app.current_company_id + container bind
        // Re-runnable: deletes and regenerates this run's items/lines rather than appending,
        // so a corrected input (late leave approval, attendance fix) can be recalculated freely.
        $calc->recalculate($this->payrollRunId);        // emits PayrollRunCalculated on completion
    }
}
```

**Progress.** A queued calculation writes `meta.progress_pct` and per-employee completion count on the
run; the client polls `GET /runs/{id}`. The idempotency TTL for `payroll.release` is 72h (per
SERVICE_ARCHITECTURE), reflecting how sensitive a double-release would be.

**Realtime.** `PayrollRunApproved` (stage transitions), `PayslipGenerated`, and `PayrollReleased` are
`ShouldBroadcast` on the company-scoped private channel (Reverb) and its `.approvals` sub-channel, so the
approval UI updates live and an employee's self-service view surfaces a payslip the moment it is visible.

# Integrations

- **Accounting (ledger posting).** Payroll builds the balanced journal draft (`PayrollJournalBuilder`) and
  hands off to [ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md) at post; the disbursement journal is posted
  at release. Payroll never writes `journal_entries`/`journal_lines`.
- **Banking.** The disbursement produces the WPS Salary Information File for Finance to upload, or, where a
  bank exposes an API, a bank-specific adapter submits it; Banking (not Payroll) executes the actual
  transfer, and `payroll_items.payment_status` reaches `paid` only on the bank's acknowledgment or a manual
  Finance confirmation.
- **Attendance & Leave.** The calculation reads `attendance` and `leave_requests` for hours, overtime, and
  leave pay treatment; these feed steps 3-4 of the engine. Kuwait Labour Law No. 6/2010 provisions are
  encoded as validation and calculation rules rather than free text: standard 48-hour week (36 in Ramadan
  for Muslim employees via a per-employee flag), overtime minimums (1.25x ordinary, 1.5x rest-day/holiday,
  editable only upward in `overtime_rules`), a minimum weekly paid rest day, tiered sick-leave pay
  (`leave_types.pay_treatment = 'tiered'` with a `tier_schedule` JSONB), and notice-period minimums
  validated against tenure at termination — so an out-of-policy overtime multiplier or an under-notice
  termination is caught at calculation time, not discovered in an audit.
- **AI engine (General Accountant, Fraud, Forecast, Compliance agents).** Per
  [../api/INTERNAL_API.md](../api/INTERNAL_API.md), the FastAPI engine reads draft run data through the
  Laravel API and returns proposals — line-by-line validation, anomaly detection (salary jumps, duplicate
  bank accounts, overtime spikes), ghost-employee/fraud flags, salary-cost forecasting, compliance checks
  (garnishment cap, overtime floor, PIFSS base, indemnity formula, each citing the specific law/article),
  and bilingual payslip explanations. Every proposal carries `confidence`, `reasoning`, and
  `supporting_references`, respects the acting user's RBAC, and **no agent can transition
  `payroll_runs.status`, edit salary components/loans/payslips, or initiate disbursement.** Critical
  compliance/anomaly flags block Submit until a Finance Manager acknowledges with a logged override.
- **Reporting.** Payroll Summary, Insurance (PIFSS reconciliation), and payroll-cost reports are assembled
  by [REPORTING_SERVICE.md](./REPORTING_SERVICE.md); employee-level detail requires
  `reports.payroll.employee.read`.

# Permissions

Authorization follows the RBAC grammar `<area>.<action>` from
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), company-scoped, default-deny, and
enforced by policies from FormRequests and Actions. The lifecycle is deliberately split across distinct
permissions so no single role can drive pay end-to-end.

| Permission | Grants |
|---|---|
| `payroll.read` | View runs, periods, aggregate items |
| `payroll.salary.view` / `payroll.salary.manage` | View / set employee salary structures |
| `payroll.periods.manage` | Create/manage payroll periods |
| `payroll.run.create` | Create a run (draft) |
| `payroll.calculate` | Calculate/recalculate a run |
| `payroll.run.submit` | Submit a calculated run for approval |
| `payroll.approve` | Approve/reject at the caller's stage (maker != checker) |
| `payroll.run.post` | Post the payroll journal via Accounting |
| `payroll.release` / `payroll.disburse` | Release/disburse pay and generate the WPS file |
| `payroll.run.reverse` | Reverse a posted run |
| `payroll.payslip.view` / `payroll.payslip.view.self` | View any / only own payslips |
| `payroll.pii.view` | View sensitive PII fields (national ID, IBAN) |
| `payroll.loan.create` / `payroll.loan.approve` / `payroll.bonus.create` | Loans and bonuses |

The **separation of duties** is the point: `payroll.calculate`, `payroll.approve`, `payroll.run.post`, and
`payroll.release` are distinct grants held by distinct roles (Payroll Officer -> Finance Manager -> CEO ->
Finance Manager for disbursement), and every approval stage enforces maker != checker via the
`payroll_run_approvals` `UNIQUE (run, stage)` constraint and a policy check — a `MakerCheckerViolationException`
(403) is raised if an approver tries to approve a run they created or a stage they already acted on.
`payroll.salary.view`/`payroll.pii.view` gate the most sensitive fields so an operator can run payroll
without necessarily seeing raw salaries or national IDs where policy separates those duties.

# Error Handling

The service throws typed domain exceptions mapped by the global handler per
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md); exceptions carry the current/allowed state so the
handler localises `errors[].message` without HTTP knowledge.

| Thrown | Meaning | Rendered |
|---|---|---|
| `InvalidStateTransitionException` | e.g. post a run not `approved`, submit a `draft` | 409 `invalid_state_transition` |
| `MakerCheckerViolationException` | approver == maker / re-approving own stage | 403 `maker_checker_violation` |
| `ImmutableRecordException` | edit a posted run, released payslip, or locked item/line | 409 `immutable_record` (names reverse/arrears path) |
| `UnbalancedEntryException` | the built payroll journal draft does not balance | 422 `balance_mismatch` |
| `IdempotencyKeyReusedException` | same `Idempotency-Key`, different body on post/release | 409 `idempotency_key_conflict` |
| `CriticalComplianceFlagException` | a critical AI compliance/anomaly flag not yet acknowledged | 422 `compliance_ack_required` |
| `WpsGenerationException` | bank SIF generation failed (missing IBAN/civil ID) | 422 `wps_generation_failed` |
| `InsufficientPermissionException` | actor lacks the stage/PII permission | 403 `permission_denied` |

Two design points. First, **a paid or posted run is never edited.** An underpayment discovered later is an
`arrears` component in the *next* run referencing the original via `source_reference`; a genuine error
(wrong employee, wrong amount entirely) is a reversal — `POST /runs/{id}/reverse` posts the exact
debit/credit mirror journal, marks the run `reversed`, and creates a fresh `draft` with corrected inputs.
Second, the **post+status-flip is atomic**: if the journal fails to balance or Accounting rejects it, the
`payroll_runs.status` does not advance to `posted` — either both the ledger and the run move together or
neither does, so a run can never appear posted without its journal.

# Testing

Payroll's layering is chosen for testability, and the sensitivity of the data raises the bar on isolation
and immutability tests.

- **Unit-test `PayrollCalculationService`** end-to-end for a fixed employee set: assert the twelve-step
  order, correct pro-ration for a mid-period hire, attendance/overtime aggregation, PIFSS base excluding
  variable pay, a real `0.0000` Kuwait tax line, the indemnity accrual, and that run totals equal the
  `SUM()` of items. Assert re-running regenerates rather than appends.
- **Unit-test `StatutoryService`** against seeded `social_insurance_rates`/`indemnity_resignation_schedule`
  rows: employee vs. employer PIFSS split, GCC-national vs. exempt expatriate, indemnity accrual at 15
  days/year for the first five years and one month/year thereafter, capped at 18 months — using editable
  rate rows, never hard-coded constants (a rate change is a data change the test seeds).
- **Unit-test the transition Actions** with Accounting mocked: assert the state machine, that post is atomic
  with the journal, and that maker == checker throws before any write.
- **Feature-test through `/api/v1/payroll`** to prove thin controller + FormRequest + policy + envelope,
  and to prove **tenant isolation** (company A cannot read company B's runs/payslips), **self-service
  scoping** (an employee session reads only their own released payslips — enforced by RLS), and
  **default-deny** (missing `payroll.approve` -> 403).
- **Immutability tests** assert the `BEFORE UPDATE` trigger blocks item/line edits once a run leaves
  `draft`/`calculated`, that a released payslip cannot be regenerated, and that a correction is an arrears
  line or a reversal, never an edit.
- **Idempotency tests** assert a retried `post`/`release` with the same key does not double-post or
  double-disburse, and a mismatched body 409s.
- **Compliance-gate tests** assert a critical AI compliance flag blocks Submit until acknowledged with a
  logged `override_reason`.
- **PII tests** assert encrypted fields are never returned without `payroll.pii.view` and never written to
  logs, cross-referencing [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md).

# End of Document
