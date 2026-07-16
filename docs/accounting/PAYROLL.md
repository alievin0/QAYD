# Payroll — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Payroll
---

# Purpose

Payroll is the module of QAYD responsible for the end-to-end lifecycle of compensating employees:
capturing employee master data relevant to pay, defining and assigning salary components, integrating
attendance and leave into pay calculation, running the payroll calculation engine, routing every payroll
run through a mandatory human approval chain, disbursing net pay through one or more payment methods,
generating payslips, posting the payroll journal entry into the General Ledger, and satisfying
country-specific statutory obligations — with first-class support for Kuwait and the wider Gulf
(PIFSS social insurance, end-of-service indemnity, and the Wage Protection System / WPS bank file).

Payroll in QAYD is deliberately built as a **regulated financial subsystem**, not a convenience feature.
Every number that leaves this module and lands in an employee's bank account, or in the General Ledger,
must be reproducible, auditable, and reversible through the same double-entry discipline that governs
Sales, Purchasing, and Banking. Payroll data is among the most sensitive data any ERP holds — it reveals
who earns what, who is on probation, who has a garnishment, who is on maternity leave — and the module is
designed around that sensitivity from the schema up: field-level encryption on identity numbers and bank
account numbers, salary-visibility scoping independent of the general RBAC tree, and an approval chain
that is never satisfied by an AI agent acting alone, no matter how high its confidence score.

Concretely, Payroll owns five responsibilities:

1. **Master data**: represent an employee as a payroll subject — identity, tax/social-insurance
   registration, banking details, employment terms, and the historical trail of every change to those
   terms (promotions, transfers, salary revisions).
2. **Pay determination**: compute, for a given payroll period, exactly how much each employee is owed,
   decomposed into base pay, allowances, overtime, commission, bonuses, deductions, loan installments,
   statutory withholdings, and employer-side statutory contributions — each traceable to a rule and a
   source (a contract term, an attendance record, an approved leave request, an approved loan).
3. **Controlled release**: never let a payroll run become "money that leaves the company" without a
   three-stage human approval (Payroll Officer → Finance Manager → CEO), and never let it post to the
   ledger or disburse funds while in `draft` or `pending_approval` state.
4. **Statutory compliance**: produce, per country, the artifacts regulators and banks require — in
   Kuwait, the PIFSS social-insurance contribution file, the end-of-service indemnity accrual and
   calculation, and the WPS salary file submitted to the bank/Central Bank of Kuwait (CBK) SIF format.
5. **Explainability**: every payslip must be explainable in plain language, in both English and Arabic,
   to the employee who receives it and to the auditor who reviews it a year later.

# Vision

Payroll should feel, to a Finance Manager, exactly as trustworthy as a payroll run from SAP
SuccessFactors Employee Central Payroll or Workday Payroll — a system where nothing moves without a
paper trail, every number can be traced back to a rule and a source record, and corrections are made by
reversing and re-issuing, never by silently editing a posted number. At the same time, it should feel, to
an HR Manager, as fast and low-friction as Gusto or Zoho Payroll — most payroll runs for a stable
workforce should be a single click of "Calculate" followed by a short review, not a multi-day manual
reconciliation exercise.

The long-term vision is a payroll engine that:

- Runs unattended calculation for 95%+ of employees in a run, with AI-assisted validation flagging only
  the anomalies (a spike in overtime, a salary that moved outside policy bounds, a loan installment that
  would take net pay below the statutory minimum) for human attention — never auto-approving them.
- Treats every country's statutory rule set (Kuwait PIFSS/WPS today; Saudi GOSI, UAE WPS/MOHRE, and
  broader Gulf/GCC rules as QAYD expands) as data — versioned rule tables and configurable formulas —
  not hard-coded logic, so adding a country is a configuration exercise, not a rewrite.
- Gives every employee, through the Next.js self-service portal, a payslip they can read and understand
  without calling HR: a plain-language breakdown of gross, every addition, every deduction, and net, in
  their preferred language (Arabic RTL or English).
- Gives Finance an accounting integration so exact that the payroll journal entry reconciles to the bank
  transfer file to the cent, every run, with zero manual journal adjustments in steady state.
- Never — under any configuration, in any country, at any confidence score — lets an AI agent post a
  payroll run, release a payment, or approve its own recommendation. The approval chain terminates in a
  human, always.

# Payroll Philosophy

QAYD's payroll philosophy rests on six non-negotiable principles, mirrored from how SAP, Oracle HCM, and
Workday treat payroll as the highest-blast-radius module in an ERP:

**1. Payroll is a calculation, not a form.** A payroll run is the deterministic output of a function
`calculate(employee, period, rules, inputs) → payroll_item`. Given the same inputs and the same version
of the rule set, the output must be identical every time. This is why every component that feeds a
calculation (a salary component, a tax rate, a leave policy) is versioned and effective-dated — the
engine always calculates against the rule version that was active during the period being paid, even if
someone edits the "current" rule the next day.

**2. Immutability after posting.** Once a payroll run reaches `posted`, none of its `payroll_items`,
`payroll_item_lines`, or the resulting `journal_entries` may be edited. Every correction is a **reversal**
(a mirror-image journal entry and a mirror-image payroll item marked `reversal_of`) followed, if needed,
by a fresh corrected run. This is identical to how Accounting treats posted journal lines and is what
makes a payroll audit possible three years later.

**3. Separation of computation and authorization.** The calculation engine (Laravel Service layer) may
run as many times as needed in `draft` state — recalculating, previewing, adjusting inputs — with zero
financial consequence. Nothing is "real" until it passes the full three-stage approval chain. This
separation is what allows the AI layer to run validation and forecasting freely on draft data without any
risk of it influencing money movement.

**4. Multi-currency and multi-country by default, not by exception.** Even though the first deployments
are Kuwait-based and pay in KWD, every monetary column carries `currency_code` and `exchange_rate` next to
the base-currency amount, and every statutory rule is scoped by `country_code`, following the platform's
standing multi-currency convention. Kuwait-specific logic (PIFSS, indemnity, WPS) lives behind a country
strategy interface, not inline in the calculation engine.

**5. Privacy is structural, not procedural.** Salary and bank data do not merely have permission checks
bolted onto standard CRUD — visibility of `employee_salary_components`, `payroll_items`, and `payslips`
is scoped independently, so that even a role with broad `accounting.*` permissions cannot see individual
salaries unless explicitly granted `payroll.salary.view`. Sensitive columns (national ID, bank account
number, IBAN) are stored encrypted at rest (Laravel encrypted casts, AES-256-GCM) regardless of role.

**6. AI proposes, humans dispose.** The AI layer (Payroll Manager agent, Fraud Detection agent, Forecast
Agent, Compliance Agent) is a first-class citizen in the payroll workflow — flagging anomalies before a
Payroll Officer even opens the review screen — but it is architecturally incapable of transitioning a
payroll run past `draft`/`calculated`. Every AI output is a recommendation object with a confidence score
and a reasoning trail, written to `ai_messages`, never a direct mutation of `payroll_runs` or
`payroll_items`.

# Employee Lifecycle

Payroll does not own employee lifecycle events end-to-end — Recruitment/Hiring/Onboarding are primarily
an HR module concern — but Payroll is a **subscriber** to every lifecycle event that has a compensation
consequence, and it owns the payroll-relevant side effects of each stage. The lifecycle is modeled as a
state machine on `employees.employment_status`:

```
applicant -> hired -> onboarding -> active -> ( promoted | transferred | on_leave )* -> terminated -> ( rehired -> active | archived )
```

## Recruitment

Recruitment (candidate sourcing, interviews, offers) happens upstream of Payroll, in the HR/Recruitment
module, against `candidates` and `job_requisitions` tables outside Payroll's ownership. Payroll's only
touchpoint is the **offer**: when a `job_offer` is accepted, HR calls
`POST /api/v1/payroll/employees/{id}/salary-components` (see API section) to seed the new hire's initial
compensation package *before* their first day, so payroll is ready to run from day one. This call creates
`employee_salary_components` rows in `status = 'pending'`, activated automatically on the employee's
`hire_date`.

## Hiring

On hire, HR creates the `employees` record with `employment_status = 'hired'` and a `hire_date`. Payroll
listens for the `employee.hired` domain event and:
- Validates that at least one **base salary** component exists and is effective on-or-before `hire_date`.
- Validates that the employee's `country_code` maps to a known statutory rule set (Kuwait today); if not,
  the employee is flagged `payroll_ready = false` and cannot be included in a payroll run until resolved.
- Pre-creates a placeholder `bank_accounts`-linked payout record in `status = 'awaiting_bank_details'`.

## Onboarding

During onboarding, the employee (or HR on their behalf) supplies the payroll-critical master data:
national ID, civil ID (Kuwait), passport, PIFSS registration number (Kuwaiti/GCC nationals) or work-permit
number (expatriates), bank account/IBAN, and emergency contacts. Payroll enforces a **payroll-readiness
gate**: `employment_status` cannot transition to `active` while any of `national_id`, `bank_account_id`,
or a validated `base_salary` component is missing — mirroring Workday's "hire-to-pay" onboarding
checklist pattern. The gate is enforced in `EmployeeOnboardingService::assertPayrollReady()` and surfaced
to the frontend as a checklist, not a hard block on saving partial data.

## Employment

Once `active`, the employee is included in every payroll run for periods that overlap
`[hire_date, termination_date)`. Mid-period hires and terminations are **pro-rated** by calendar days
(configurable to working days) against the period's total days — see Payroll Processing → Calculation
Engine for the exact pro-ration formula.

## Promotion

A promotion is modeled as a new-effective-dated row in `employee_salary_components` (a new base salary
value with `effective_from` = promotion date, and the prior row's `effective_to` set to the day before)
plus an `employee_position_history` entry recording old/new `position_id`, `department_id`, and
`grade_id`. Promotions never overwrite history — the calculation engine always selects the salary
component version whose effective range contains the period being calculated, which is what makes
retroactive promotions (backdated with arrears) calculable: the engine re-runs the affected historical
periods' "should have earned" figure and posts the delta as an `arrears` payroll component in the current
run.

## Transfer

A transfer changes `branch_id`, `department_id`, and/or `cost_center_id` effective on a given date. Like
promotion, this is effective-dated on the employee record (`employee_assignment_history`) so that a
payroll run for period *P* always resolves the branch/department/cost-center that was active during *P*
— critical for Department/Branch Payroll reports (see Reports) and for journal-line dimension tagging in
Accounting Integration.

## Leave

Leave (annual, sick, unpaid, maternity, paternity, emergency, custom) is a first-class Payroll input; see
Leave Management and Attendance Integration for the full model. An employee `on_leave` for an entire
payroll period is still included in the run (to correctly calculate whichever portion of pay, if any, is
payable during that leave type), not excluded.

## Termination

Termination sets `employment_status = 'terminated'`, `termination_date`, and `termination_reason`
(resignation, dismissal, end-of-contract, retirement, death). Termination triggers the **final
settlement** workflow: a special `payroll_runs.run_type = 'final_settlement'` run is generated covering
(a) any unpaid pro-rated salary up to `termination_date`, (b) accrued-but-untaken annual leave paid in
lieu, (c) end-of-service indemnity per Kuwait Labour Law No. 6/2010 (see Compliance), (d) outstanding loan
balances, which are either deducted in full from the final settlement or written off per HR decision, and
(e) recovery of any advances. The final settlement run requires the full three-stage approval chain like
any other run — it is never auto-approved, even though it is often small.

## Rehire

A previously terminated employee whose `employee_id` is reactivated (`employment_status: terminated ->
active`) is a **rehire**, not a new record — QAYD never creates a duplicate `employees` row for the same
national ID. Rehire resets `hire_date` to the new start date for pro-ration/seniority purposes but
preserves the full historical `employee_salary_components`, `payroll_items`, and `payslips` trail under
the same `employee_id`, which matters for indemnity calculations that consider cumulative service (see
Compliance — some jurisdictions reset indemnity service years on rehire, others accumulate; this is a
per-country configurable rule, `indemnity_service_reset_on_rehire`).

## Archive

An employee who has been terminated for longer than the configured retention trigger (default: never
auto-archived; archival is a manual HR action) can be marked `is_archived = true`. Archiving does not
delete or soft-delete any payroll history — `payslips`, `payroll_items`, and `employee_salary_components`
remain queryable for audit and tax purposes (Kuwait requires payroll records retained for a minimum of 5
years; QAYD defaults retention to 10 years for the full employee ledger). Archiving only removes the
employee from active-employee UI lists and dropdowns.

# Employee Master Data

Employee master data in Payroll is a payroll-focused superset of the HR employee record — HR module owns
the `employees` table's identity/organizational core (per section 3 canonical tables), and Payroll extends
it with payroll-specific columns and companion tables. All fields below live on `employees` unless a
dedicated table is named.

## Personal Information

`first_name_en`, `last_name_en`, `first_name_ar`, `last_name_ar`, `date_of_birth`, `gender`, `nationality`
(ISO 3166-1 alpha-2), `marital_status` (`single|married|divorced|widowed`), `religion` (optional, used
only where statutory leave/holiday entitlements depend on it), `photo_attachment_id` (polymorphic FK into
`attachments`). Personal information is bilingual by the platform convention (`name_en`/`name_ar` pattern)
so payslips and statutory filings render correctly in Arabic without transliteration errors.

## National ID

`national_id_encrypted` (Laravel `encrypted` cast, AES-256-GCM at rest; for Kuwait this is the Civil ID
number, 12 digits), `national_id_expiry`, `national_id_country`. The plaintext value is never logged, never
included in API responses to roles without `payroll.pii.view`, and never sent to the AI layer — AI agents
receive a masked value (`***-***-1234`) sufficient for anomaly detection (duplicate-ID fraud checks) without
exposing the full number.

## Passport

`passport_number_encrypted`, `passport_country`, `passport_expiry`, `passport_attachment_id`. Required for
expatriate employees; QAYD flags (does not block) payroll inclusion when `passport_expiry` falls within the
current payroll period plus 30 days, since an expired passport can affect work-permit and visa-linked
employment validity in Kuwait.

## Tax Number

`tax_identification_number` — nullable, populated only for countries with a personal income tax regime.
Kuwait levies no personal income tax on salaries, so this column is null for Kuwait-based employees; it
exists for forward compatibility as QAYD expands to jurisdictions that do withhold income tax.

## Social Insurance

Modeled via `employee_social_insurance` (one row per country registration, since an employee could in
theory carry historical registrations from a prior country): `pifss_number` (Kuwaiti/GCC nationals only),
`insurance_status` (`registered|exempt|pending`), `registration_date`, `contribution_category` (maps to
the PIFSS contribution-rate table — see Compliance), `is_gcc_national` (bool — GCC nationals working in
Kuwait are also PIFSS-eligible per GCC Unified Economic Agreement, at a different employer/employee split
than Kuwaiti nationals). Non-Kuwaiti, non-GCC expatriates are `insurance_status = 'exempt'` since PIFSS
does not apply to them (Kuwait has no social-insurance scheme for non-GCC expatriates; their end-of-service
indemnity is the substitute long-service benefit — see Compliance).

## Bank Account

`bank_accounts` (foundation table, extended for Payroll) rows linked via `employee_bank_accounts`
(`employee_id`, `bank_account_id`, `is_primary`, `effective_from`, `effective_to`). Columns:
`bank_name`, `iban_encrypted`, `account_number_encrypted`, `swift_bic`, `branch_code`, `currency_code`.
IBAN is validated against the Kuwait IBAN format (`KW` + 2 check digits + 4 bank code + 22 alphanumeric,
30 characters total) via a `KuwaitIbanValidator` when `currency_code = 'KWD'`. An employee may have at
most one `is_primary = true` bank account at any time; WPS disbursement always targets the primary account.

## Department / Branch / Manager / Position

`department_id`, `branch_id` (both FK to foundation tables, both dimensions per the platform's dimension
convention), `manager_id` (self-referencing FK to `employees.id`, nullable for top-of-org roles),
`position_id` (FK to `positions` — title, `grade_id`, `job_family`). These four fields drive approval
routing (a Payroll Officer typically scopes to a branch; a manager's chain determines leave-approval
routing) and drive the Department/Branch Payroll reports.

## Employment Type

`employment_type` enum: `full_time | part_time | contractor | temporary | intern`. Employment type
determines which salary-component templates apply by default (e.g., `hourly_wage` is the default base-pay
component for `part_time`/`temporary`, `contractor` employees are typically paid via `bills`/`vendor
payments` in the Purchasing module rather than through Payroll at all — QAYD supports both models and the
choice is a company-level setting, `payroll.include_contractors`).

## Salary

`employee_salary_components` — see Payroll Components for the full table design. The `employees` table
itself never stores a raw salary figure; the current base salary is always *derived* by querying the
active `employee_salary_components` row of `component_type = 'base_salary'` for "today," which guarantees
the calculation engine and any UI display are always reading from the same source of truth.

## Working Hours

`standard_weekly_hours` (default 48 for Kuwait private sector per Labour Law No. 6/2010, Art. 64, with
Friday as the default weekly rest day; configurable per contract), `work_schedule_id` (FK to
`work_schedules` defining daily start/end times and which weekdays are working days), `is_shift_worker`
(bool, drives eligibility for `night_shift` and rotating-shift allowances).

## Contracts

`employee_contracts`: `contract_type` (`unlimited|limited`), `start_date`, `end_date` (nullable for
unlimited), `probation_end_date`, `notice_period_days`, `contract_document_attachment_id`,
`renewal_status`. A `limited` (fixed-term) contract nearing `end_date` within 60 days surfaces a renewal
reminder via `notifications`; it does not auto-terminate — HR must act.

## Documents

Polymorphic `attachments` (`attachable_type = 'Employee'`) hold scanned IDs, contracts, certificates,
offer letters, and termination letters. Documents are stored in Cloudflare R2 with signed, time-limited
URLs — never publicly readable — and access is gated by the same `payroll.pii.view`/`hr.documents.view`
permissions as the structured PII fields.

## Emergency Contacts

`employee_emergency_contacts`: `full_name`, `relationship`, `phone_number`, `is_primary`. Not payroll
data per se, but retained on the employee master record and surfaced on the same profile screen; included
here for completeness of the master-data model per SAP/Workday employee-file conventions.

# Payroll Components

A **salary component** (`salary_components`) is a company-level *template* defining a type of pay or
deduction; an **employee salary component** (`employee_salary_components`) is that template *assigned* to
a specific employee with an amount, formula, or rate and an effective-dated validity window. This
two-layer model — identical in spirit to SAP's Wage Type and Workday's Compensation Element — is what lets
Finance change a company-wide rule (say, the overtime multiplier) once and have it apply prospectively to
every employee who uses that component, while still letting an individual employee's *amount* vary.

`salary_components.component_type` values, and how each is computed:

| Component Type | Category | Computation | Taxable/Contributable | Recurs |
|---|---|---|---|---|
| `base_salary` | Earning | Fixed amount or hourly_rate × hours | Yes (PIFSS-eligible portion only) | Every period |
| `hourly_wage` | Earning | `rate × hours_worked` from attendance | Yes | Every period |
| `commission` | Earning | `% × sales_value` from Sales module event | No (excluded from PIFSS base) | Variable |
| `bonus` | Earning | Fixed amount, one-off or formula (e.g. 1 month salary) | No | One-off |
| `allowance` | Earning | Fixed amount (housing, transport, phone, cost-of-living) | Depends on sub-type | Every period |
| `overtime` | Earning | `hourly_rate × multiplier × overtime_hours` | Yes | Variable, from attendance |
| `night_shift` | Earning | Fixed amount or `% × base` per night-shift day worked | Yes | Variable |
| `holiday_pay` | Earning | `daily_rate × multiplier` for work on public holidays | Yes | Variable |
| `deduction` | Deduction | Fixed amount or formula (e.g. unpaid-leave days) | N/A | Variable |
| `loan_repayment` | Deduction | Installment amount from `loan_installments` | N/A | Recurs until settled |
| `advance_repayment` | Deduction | Full or partial recovery of an advance | N/A | Recurs until settled |
| `tax_withholding` | Statutory | Country tax-table lookup | N/A | Every period (where applicable) |
| `social_insurance_employee` | Statutory | `% × pifss_base` (employee share) | N/A | Every period |
| `social_insurance_employer` | Statutory (employer cost) | `% × pifss_base` (employer share) | N/A | Every period |
| `indemnity_accrual` | Statutory (employer cost, not paid) | Formula per Compliance | N/A | Every period (accrual only) |

## Base Salary

The `base_salary` component is mandatory — the payroll-readiness gate (Employee Lifecycle → Onboarding)
will not allow an employee into `active` status without one. It is either a **fixed** monthly amount
(`amount_type = 'fixed'`, `amount`) or **formula-driven** from an hourly rate for `part_time`/`hourly_wage`
employment types (`amount_type = 'formula'`, `formula = 'hourly_rate * standard_hours'`).

## Hourly Wage

For non-salaried employees, `hourly_wage` replaces `base_salary` as the primary earning: gross pay for the
period is `hourly_rate × total_regular_hours` pulled from `attendance` (clock-in/clock-out deltas net of
unpaid breaks), summed per period and rounded to 2 decimal places at the daily level, then to 4 decimals
(`NUMERIC(19,4)`) at the period total, per platform money-storage convention.

## Commission

Commission integrates with the Sales module via the `sale.invoiced` / `sale.paid` domain events (company
policy, `commission_basis`, decides whether commission accrues on invoicing or on collection). The
Payroll Service subscribes to these events and writes pending `commission` earning lines into a
`employee_commission_ledger`, which the calculation engine pulls into the next payroll run whose period
covers the event date. Commission is never entered manually into a payroll run without a linked source
sale record — this traceability is what lets the AI Fraud Detection agent flag a commission line with no
matching sales event as an anomaly.

## Bonus

Bonuses are either **discretionary** (HR/Finance manually creates a one-off `employee_salary_components`
row of `component_type = 'bonus'`, `effective_from = effective_to =` the target period) or **formulaic**
(e.g., an annual bonus policy computed as `1 × base_salary` and auto-generated by a scheduled job ahead of
the relevant run, still requiring the same approval chain before it is payable).

## Allowance

Allowances are typically fixed recurring amounts: `housing_allowance`, `transport_allowance`,
`phone_allowance`, `cost_of_living_allowance`. Each allowance sub-type is its own `salary_components` row
(not a generic "allowance" bucket) so that Compliance rules (e.g., which allowances count toward the PIFSS
contribution base, per PIFSS Law No. 61/1976 and its amendments — generally basic wage plus certain fixed
allowances, excluding variable pay) can be configured per sub-type via
`salary_components.is_social_insurance_eligible` (bool).

## Overtime

Overtime is **never** manually entered as a flat number in ordinary operation — it is derived from
`attendance` records exceeding `standard_daily_hours`, multiplied by the statutory or contractual
multiplier. Kuwait Labour Law No. 6/2010 (Art. 66-67) sets minimum overtime multipliers of 1.25× for
regular overtime and 1.5× for overtime on the weekly rest day or a public holiday; QAYD stores these as
configurable `overtime_rules` (`multiplier`, `applies_to` enum `weekday|rest_day|public_holiday`) so a
company can apply a more generous (never less generous) multiplier. Overtime hours are capped per company
policy (`max_overtime_hours_per_period`) with any excess flagged, not silently dropped, for a Payroll
Officer to review.

## Night Shift

A fixed differential (amount or % of base) applied per day where `attendance.shift_type = 'night'`
(commonly defined as work occurring substantially between 22:00–06:00). Multiple night-shift differentials
in the same period simply sum.

## Holiday Pay

Work performed on a gazetted Kuwait public holiday is paid at the `holiday_pay` multiplier (statutory
minimum 1.5× under Art. 67, configurable higher) for hours actually worked that day, layered on top of
(not replacing) the employee's regular pay for that day if the holiday falls on what would otherwise be a
paid rest day.

## Deductions

Generic `deduction` components cover unpaid-leave day recovery, disciplinary deductions (must reference an
HR case record, never a bare number), uniform/equipment cost recovery, and court-ordered garnishments
(`deduction_subtype = 'garnishment'`, capped at the statutory maximum percentage of net pay per Kuwaiti
law — configurable, default cap 25% of net, i.e. no garnishment deduction may reduce net pay below 75% of
what it would otherwise be, protecting subsistence income).

## Loans

See `employee_loans` / `loan_installments` in Database Design. A loan is approved once (its own mini
approval — HR Manager or Finance Manager depending on amount thresholds) and then automatically generates
`loan_repayment` deduction lines every period until `outstanding_balance` reaches zero, without requiring
re-approval of each installment.

## Advances

An advance (salary paid ahead of the normal cycle, e.g., an emergency cash advance) is modeled identically
to a loan with `loan_type = 'advance'`, typically recovered in full in the very next payroll run rather than
amortized, though multi-period recovery is supported via the same `loan_installments` schedule.

## Taxes

`tax_withholding` is a no-op for Kuwaiti payroll today (no personal income tax) but the component exists
and is wired end-to-end (calculation engine, journal posting, payslip line, Tax Report) so that enabling a
future country's income-tax regime is a matter of populating `tax_rates`/`tax_codes` (owned by the Tax
module, referenced here) rather than engineering new plumbing.

## Insurance

`social_insurance_employee` and `social_insurance_employer` implement PIFSS: employee share and employer
share are both computed from the same `pifss_base` (sum of PIFSS-eligible earning components) but at
different rates and, critically, the employer share is an **employer cost**, never deducted from the
employee's net pay — it appears on the payslip's informational "employer contributions" section, not the
"deductions" section, and posts to a separate GL expense account (see Accounting Integration).

## Retirement

For Kuwait, PIFSS contributions themselves function as the retirement/pension mechanism (no separate
company pension plan is modeled for the initial release). The schema reserves
`salary_components.component_type = 'retirement_contribution'` for future company-sponsored retirement
plans (relevant for expatriate-heavy companies or future non-Kuwait markets) without requiring a schema
migration when that need arises.

## Net Salary

`net_salary = gross_salary − Σ(deductions) − Σ(statutory_withholdings) − Σ(loan_repayments)`. Net salary
is a **computed, stored** value on `payroll_items` (not purely derived at read time) so that a posted
payslip's net figure is immutable and independently auditable even if a downstream component's formula
later changes.

## Gross Salary

`gross_salary = Σ(all earning-category components for the period)` — base salary (pro-rated where
applicable), allowances, overtime, night shift, holiday pay, commission, and bonuses. Gross salary is the
PIFSS contribution base only for the subset of components flagged `is_social_insurance_eligible`; the
concepts are related but distinct, and the schema keeps `gross_salary` and `pifss_base` as separate stored
columns on `payroll_items` to avoid ever conflating them.

# Attendance Integration

Payroll's calculation engine treats `attendance` as an **input**, never a Payroll-owned system of record —
attendance capture (biometric devices, mobile geofenced clock-in, kiosk) belongs to a Time & Attendance
capability that writes into the shared `attendance` table; Payroll reads it read-only per period.

## Clock In / Clock Out

`attendance` rows carry `clock_in_at`, `clock_out_at` (both `TIMESTAMPTZ`), `clock_in_method`
(`biometric|mobile_geofence|kiosk|manual`), `clock_in_location` (`POINT`, nullable), and a computed
`worked_minutes` (generated at insert/update time by a trigger, not recalculated ad hoc by Payroll, so
that Payroll's aggregation query is a simple `SUM`). A manual entry (`clock_in_method = 'manual'`) always
carries `approved_by` — Payroll refuses to include unapproved manual attendance in a calculation.

## Clock Out

Where `clock_out_at IS NULL` for a completed working day, the record is `status = 'incomplete'` and is
excluded from paid-hours aggregation for `hourly_wage` employees (with a notification to the employee's
manager) rather than assumed to be a full day — protecting against both underpayment (silently dropping
the day) and overpayment (silently assuming a full day worked).

## Late

`is_late` (bool, computed against `work_schedules.start_time` plus a configurable grace period, default 10
minutes) and `late_minutes`. Lateness by itself does not deduct pay unless company policy
(`late_deduction_policy`) explicitly ties it to a deduction formula (e.g., deduct 1 day's pay per 3 late
occurrences in a period) — QAYD ships this disabled by default, since aggressive lateness deduction rules
are a common source of Kuwait Labour Court disputes if not documented in the employment contract.

## Early Leave

Symmetric to Late: `is_early_leave`, `early_leave_minutes`, governed by the same optional policy hook.

## Absence

A working day with zero `attendance` rows and no covering approved `leave_requests` row is an
**unexcused absence** (`attendance_summary.absence_type = 'unexcused'`). Kuwait Labour Law permits
dismissal for 7+ cumulative unexcused absence days in a year or 20+ intermittent days (Art. 41) — QAYD
surfaces a compliance warning to HR at these thresholds but never auto-terminates. Unexcused absence
defaults to a full-day deduction at the daily rate (`base_salary / days_in_period`) unless overridden.

## Remote Work

`attendance.work_mode` (`onsite|remote|hybrid`) is captured for reporting and does not itself change pay
calculation, but remote-work clock-ins accept a wider geofence tolerance (`remote_geofence_radius_m`,
company-configurable) than onsite biometric/geofence clock-ins.

## Shift Management

`work_schedules` define named shift patterns (`morning`, `evening`, `night`, `rotating`) with
`start_time`, `end_time`, `break_minutes`, and `applicable_weekdays` (bit-mask or array of ISO weekday
numbers). `employee_shift_assignments` link an employee to a schedule for a date range, allowing rotating
shifts to be modeled as a sequence of assignments rather than a single static schedule. The attendance
aggregation for a payroll period always resolves, for each attendance day, the shift assignment active on
that date — which is what correctly classifies a given worked day as eligible for `night_shift`
differential.

# Leave Management

`leave_types` is a company-configurable catalog (not a hard-coded enum) so that Custom Leave Types are a
data-entry exercise, not a code change — but QAYD ships six system leave types pre-seeded per company on
creation, matching Kuwait Labour Law No. 6/2010 minimums, which companies may extend but not reduce below
the statutory floor (enforced by `leave_types.is_statutory_minimum` + `minimum_days_per_year` guard in the
Service layer).

| Leave Type | Statutory Minimum (Kuwait private sector) | Paid | Accrues | Carries Over |
|---|---|---|---|---|
| Annual | 30 calendar days/year (after 9 months' service; 21 days in first year is common contractual practice, statutory floor is nuanced by tenure) | Yes | Monthly (2.5 days/month typical) | Up to configurable cap, else paid out |
| Sick | 15 days full pay + 10 days half pay + 10 days unpaid per year (Art. 68, tiered) | Tiered | N/A (annual reset) | No |
| Unpaid | Employer discretion | No | N/A | N/A |
| Emergency | Company policy (not statutory in Kuwait; typically drawn from annual leave balance or a small separate bucket) | Company policy | Company policy | No |
| Maternity | 70 days (Art. 22), full pay | Yes | N/A | N/A |
| Paternity | Not statutory under Kuwait private-sector law as of this writing; QAYD ships it as a configurable, company-policy leave type at 0 days default so companies granting it can configure their own entitlement | Company policy | N/A | N/A |
| Custom | Company-defined | Company-defined | Company-defined | Company-defined |

`leave_requests` carries `leave_type_id`, `start_date`, `end_date`, `days_requested` (excludes weekly rest
days and public holidays by default, per `leave_types.excludes_rest_days`/`excludes_public_holidays`),
`status` (`pending|approved|rejected|cancelled`), and `approved_by`. Approved leave requests are the
authoritative source Payroll reads to (a) exclude the days from "unexcused absence" deduction, (b) apply
the correct pay treatment for that leave type (full pay, half pay, unpaid, or a Maternity full-pay
override), and (c) — for Annual leave specifically — decrement `employee_leave_balances.days_remaining`
and, on termination, compute the accrued-but-untaken balance payable in the final settlement.

Leave balance accrual runs as a scheduled monthly job (`AccrueLeaveBalancesJob`) that reads each active
employee's `leave_types.accrual_rate` and writes an `employee_leave_ledger` entry (`accrual|usage|payout|
adjustment`, `days_delta`), with `employee_leave_balances.days_remaining` maintained as a running total —
mirroring the ledger-plus-balance pattern already used for `inventory_items`/`stock_movements` elsewhere
in the platform, for consistency and so the AI Leave Prediction agent (see AI Responsibilities) can reason
over the ledger's time series.

# Payroll Processing

## Payroll Period

A `payroll_periods` row (`period_start`, `period_end`, `pay_date`, `frequency` — `monthly|biweekly|
weekly|semi_monthly`) is generated ahead of time by a scheduled job for the company's configured
frequency (Kuwait practice is overwhelmingly monthly, `pay_date` typically the last working day of the
month or within the first days of the following month per Art. 55 which requires wages be paid at least
once a month). Multiple `payroll_runs` can reference the same period (e.g., a regular run plus a
supplementary run for a late new-hire, or a `final_settlement` run for a mid-period termination) but each
run is independently calculated, approved, and posted.

## Calculation Engine

The calculation engine (`PayrollCalculationService`) executes, per employee per run, in a fixed order so
that later components can reference earlier ones deterministically:

1. Resolve the employee's **active salary components** as of the period (effective-dated lookup).
2. Resolve **pro-ration factor**: for employees hired or terminated mid-period,
   `proration = days_employed_in_period / total_days_in_period` (calendar-day basis by default,
   `working_day_basis` configurable per company) applied to fixed-amount earning components; formula-
   and attendance-driven components (hourly wage, overtime) are naturally pro-rated since they only sum
   actual worked time within the employed window.
3. Aggregate **attendance-derived earnings**: regular hours, overtime hours × multiplier, night-shift
   days, holiday-worked days.
4. Aggregate **leave-driven pay treatment**: full-pay leave days count as worked for base-salary purposes;
   half-pay leave days contribute 50% of the daily rate; unpaid leave days deduct the full daily rate.
5. Pull **pending variable earnings**: unposted `employee_commission_ledger` entries, one-off bonuses
   dated within the period.
6. Sum **gross_salary** and, separately, **pifss_base** (the subset flagged social-insurance-eligible).
7. Compute **statutory contributions**: `social_insurance_employee`, `social_insurance_employer`,
   `tax_withholding` (no-op for Kuwait), `indemnity_accrual` (employer-cost accrual only, not deducted).
8. Compute **loan/advance installments** due this period from `loan_installments` where
   `due_date` falls within the period and `status = 'pending'`.
9. Compute **other deductions**: unexcused-absence deduction, disciplinary deductions, garnishments
   (capped at the configured percentage of net-before-garnishment).
10. Compute **net_salary** = gross_salary − employee statutory withholdings − loan/advance installments −
    other deductions.
11. Persist one `payroll_items` row per employee plus one `payroll_item_lines` row per component
    (full line-item traceability — every dollar/dinar on the payslip traces to exactly one line with a
    `component_id` and, where applicable, a `source_reference` such as an `attendance` date range or a
    `leave_requests.id`).
12. Aggregate the run-level totals (`payroll_runs.total_gross`, `total_net`, `total_employer_cost`) as
    `SUM()` over `payroll_items` — never independently recomputed, so the run total and the sum of its
    items can never drift.

The engine is **idempotent and re-runnable** while `payroll_runs.status IN ('draft','calculated')`:
re-running `calculate()` deletes and regenerates that run's `payroll_items`/`payroll_item_lines` rather
than appending, so a Payroll Officer can adjust an input (approve a late leave request, correct an
attendance record) and recalculate freely before submitting for approval.

## Approvals

See Payroll Permissions and Security for the full approval-chain design. In summary: `draft` →
(Calculate) → `calculated` → (Submit) → `pending_officer_approval` → (Payroll Officer reviews & approves)
→ `pending_finance_approval` → (Finance Manager reviews & approves) → `pending_ceo_approval` → (CEO
approves) → `approved` → (Post) → `posted` → (Disburse) → `paid`. Any approver may `reject` at their
stage, returning the run to `draft` with a required `rejection_reason`, which re-opens it for recalculation.
Each transition writes a `payroll_run_approvals` row (`approver_id`, `role_at_approval`, `decision`,
`comment`, `decided_at`) — a complete, non-editable approval trail.

## Locking

The moment a run leaves `draft` and enters `pending_officer_approval` or later, its `payroll_items` and
`payroll_item_lines` become **read-only** at the database-role level in addition to the application level
(a Postgres `BEFORE UPDATE` trigger raises an exception if `payroll_runs.status NOT IN ('draft',
'calculated')` and someone attempts to modify a child row directly) — defense in depth against an
application-layer bug bypassing the Service-layer check.

## Posting

Posting (`approved → posted`) is the moment Payroll hands off to Accounting: the
`PayrollPostingService` builds one balanced `journal_entries` header per payroll run (see Accounting
Integration for the exact line structure) and transitions `journal_entries.status` to `posted` atomically
with `payroll_runs.status = 'posted'` inside a single DB transaction — either both succeed or neither does.

## Payment

Posting the journal entry records the *accounting* liability; it does not itself move money. A separate
**Disburse** action (`posted → paid`, permission `payroll.disburse`, itself requiring Finance Manager
role) generates the payment instructions per Payment Methods below and, for bank transfer/WPS, produces
the bank file. `payroll_items.payment_status` moves to `paid` only once the disbursement batch is marked
complete (either by a bank-file acknowledgment webhook where the bank supports one, or by manual
confirmation from the Finance Manager).

## Payslip Generation

A `payslips` row (PDF, generated via a headless-Chromium/Puppeteer-style render of an HTML payslip
template in the employee's preferred language, stored in R2, linked via `attachment_id`) is generated
automatically the moment a run is `posted` — before disbursement — so that even before pay lands in an
account, HR/Finance can review the exact document the employee will receive. Employees can view/download
their own payslips through self-service the moment their individual `payroll_items.payment_status`
reaches `paid` (payslip *visibility* is gated on payment completion even though the *document* is
generated earlier, so an employee is never shown a promise of pay that later gets reversed).

## Reversal

A posted-but-not-yet-paid run, or an individual erroneous `payroll_items` row within a paid run, is never
edited — it is reversed. `POST /api/v1/payroll/runs/{id}/reverse` creates a new `journal_entries` row
that is the exact debit/credit mirror of the original posting, marks the original `payroll_runs.status =
'reversed'`, and creates a new `draft` run pre-populated with corrected inputs for re-calculation and
re-approval. This mirrors the platform-wide double-entry rule that posted lines are immutable.

## Corrections

A correction affecting only *future* periods (e.g., a salary raise effective next month) never touches a
posted run — it's simply a new `employee_salary_components` row effective in the future, picked up
naturally by the next calculation. A correction affecting a *past, already-paid* period (an
underpayment discovered later) is handled as an `arrears` component included in the *next* payroll run's
calculation, referencing the original run via `source_reference`, rather than reversing the historical run
— reversing a paid run is reserved for genuine errors (wrong employee paid, wrong amount entirely), not for
routine retroactive adjustments.

# Payment Methods

`payroll_items` carries `payment_method` (`bank_transfer|cash|wallet|cheque`) resolved at disbursement
time from the employee's configured preference (`employees.preferred_payment_method`), defaulting to
`bank_transfer` since Kuwait's WPS regulation (Ministerial Resolution requiring salary payment through
licensed banks/exchange companies for most private-sector employers above a size threshold) makes bank
transfer the practical default and, for in-scope employers, the *legally required* method.

## Bank Transfer

The primary and WPS-compliant method. Disbursement generates a `wps_export_batches` row containing every
`payroll_items` row with `payment_method = 'bank_transfer'` and `payment_status = 'pending'` for the run,
formatted per the receiving bank's Salary Information File (SIF) specification (fixed-width or
delimited text per CBK/bank standard — see Compliance for the field layout) and made available for
Finance to upload to the bank portal, or, where the bank exposes an API, submitted directly via a
bank-specific Laravel integration adapter.

## Cash

Supported for small workforces or specific worker categories where bank transfer is impractical (subject
to the company's WPS-exemption status, if any). Cash disbursement requires a `payroll_cash_disbursements`
signature record (`employee_id`, `amount`, `disbursed_by`, `disbursed_at`, `signature_attachment_id` — a
photographed signed receipt) as the audit substitute for a bank confirmation.

## Wallet

Digital-wallet payout (e.g., a mobile-money or fintech wallet integration) is modeled identically to bank
transfer but targets a `employee_wallet_accounts` record (`wallet_provider`, `wallet_id_encrypted`) instead
of `bank_accounts`, and produces a provider-specific payout API call rather than a SIF file. This method is
reserved for future expansion beyond Kuwait where wallet-based payroll disbursement is more prevalent.

## Cheque

Legacy method retained for completeness and for exceptional cases (e.g., a departing employee whose bank
account was closed before final settlement). A `payroll_cheque_issuances` record
(`cheque_number`, `bank_account_id` [company's own bank account the cheque draws on], `issued_date`,
`status: issued|cleared|voided`) tracks lifecycle; clearing a cheque is what finally marks the
`payroll_items.payment_status = 'paid'`.

# Accounting Integration

Every posted payroll run produces exactly one balanced journal entry (or, for very large companies, one
journal entry per branch/cost-center if `payroll.journal_granularity = 'per_dimension'` is configured) —
never a set of ad hoc entries created outside the standard `journal_entries`/`journal_lines` tables. This
guarantees Payroll never becomes a shadow ledger; it is a proper source module posting into the single
General Ledger, exactly like Sales and Purchasing do.

## Journal Entries

`journal_entries.source_type = 'payroll_run'`, `source_id = payroll_runs.id`. The entry is generated by
`PayrollJournalBuilder`, which maps each `payroll_item_lines.component_type` to a configurable GL account
via `payroll_gl_account_mappings` (`company_id`, `component_type`, `debit_account_id`,
`credit_account_id`), so the mapping is data, not code, and Finance can remap accounts without an
engineering change.

## The Full Payroll Journal Entry

For a payroll run with `total_gross = 50,000.0000 KWD`, employee PIFSS withholding of `3,750.0000`,
employer PIFSS cost of `5,500.0000`, loan repayments of `1,200.0000`, and indemnity accrual of
`2,083.3333`, the posted journal entry is:

| Line | Account | Debit | Credit | Dimension |
|---|---|---:|---:|---|
| 1 | 5100 · Salaries & Wages Expense | 50,000.0000 | | per cost_center/department |
| 2 | 5150 · Employer Social Insurance Expense | 5,500.0000 | | per cost_center/department |
| 3 | 5160 · End-of-Service Indemnity Expense | 2,083.3333 | | per cost_center/department |
| 4 | 2100 · Salaries Payable (net) | | 43,050.0000 | — |
| 5 | 2110 · PIFSS Payable (employee + employer) | | 9,250.0000 | — |
| 6 | 2120 · Employee Loans Receivable | | 1,200.0000 | — |
| 7 | 2130 · Provision for End-of-Service Indemnity | | 2,083.3333 | — |
| — | **Total** | **57,583.3333** | **57,583.3333** | balanced |

Line 4 (Salaries Payable) is then cleared to zero on disbursement by a second, automatically generated
journal entry (`source_type = 'payroll_disbursement'`): Debit 2100 · Salaries Payable 43,050.0000 /
Credit 1010 · Bank — Operating Account 43,050.0000. Splitting *accrual of the liability* (posting) from
*settlement of the liability* (disbursement) into two journal entries is deliberate — it lets Finance
close the books on payroll expense for a month even if the actual bank transfer clears a day or two later,
exactly as SAP and Oracle GL treat payroll accrual vs. payment.

## General Ledger

Every line above lands in `journal_lines` with `account_id`, `debit`/`credit` in
`NUMERIC(19,4)`, `currency_code`, `exchange_rate`, and `base_amount`, plus optional
`cost_center_id`/`project_id`/`department_id`/`branch_id` dimension tags copied from the employee's
active assignment at calculation time — enabling the General Ledger and Trial Balance (both derived views
per platform convention) to slice payroll expense by department or branch without any Payroll-specific
reporting logic; it's the same GL machinery every other module uses.

## Financial Statements

Salaries & Wages Expense, Employer Social Insurance Expense, and End-of-Service Indemnity Expense all roll
up into Operating Expenses on the Income Statement. Salaries Payable, PIFSS Payable, and the Indemnity
Provision appear as Current/Non-Current Liabilities on the Balance Sheet (Indemnity Provision is
classified non-current unless termination is imminent/probable for a specific employee, per standard
provisioning practice) — again with zero Payroll-specific statement logic; these are ordinary GL accounts
flowing through the existing Financial Statements engine.

## Banking

The disbursement journal entry's credit side reduces the `bank_accounts` balance tracked in
`bank_transactions`, and the eventual bank statement import (Banking module) reconciles against the
`payroll_disbursement` `bank_transactions` row exactly as any other outgoing transfer would in
`bank_reconciliations`.

## Tax

Where a future country's tax regime requires it, `tax_withholding` component lines post to a
`2140 · Income Tax Payable` liability account and feed `tax_transactions` (owned by the Tax module),
which aggregates into the periodic `tax_returns` filing.

## Projects / Departments / Cost Centers

Because every `journal_lines` row carries the employee's dimension tags, Project-based labor costing
(e.g., billing internal payroll cost to a client project) and Department/Cost-Center payroll cost reporting
both fall out of standard GL queries filtered by `project_id`/`department_id`/`cost_center_id` — see
Reports → Department Payroll, Branch Payroll, and Payroll Cost.

# AI Responsibilities

All Payroll AI functionality is delivered by the FastAPI AI layer per the platform-wide contract: the AI
engine never writes to the database, every recommendation is submitted back through the Laravel API as a
proposal object carrying `confidence` (0.00–1.00), `reasoning` (natural-language explanation), and
`supporting_references` (array of source record IDs — e.g., specific `attendance` rows or a prior period's
`payroll_items`), and every proposal respects the same RBAC the human user would need to act on it.

| Agent | Inputs | Outputs | Autonomy | Confidence Handling |
|---|---|---|---|---|
| Payroll Validation (part of General Accountant agent) | Draft `payroll_items`, prior 3 runs, `salary_components` rules | Line-by-line validation report: components missing, formula mismatches, pro-ration errors | Suggest-only | Confidence < 0.7 always surfaced; ≥ 0.95 still requires Payroll Officer acknowledgment before Submit |
| Anomaly Detection | Current run vs. trailing 6-month baseline per employee | Flags: salary jump outside policy band, unusual overtime spike, duplicate bank account across employees | Suggest-only, blocks Submit until acknowledged | Each flag carries a severity (info/warning/critical); critical flags require an explicit override reason logged in `payroll_run_approvals.override_reason` |
| Fraud Detection | Employee master data, bank accounts, historical payslips, HR case records | Flags: ghost employee (no attendance ever, no login activity), same bank account/IBAN across 2+ active employees, commission with no matching sale, loan issued and immediately maxed out then employee resigns | Suggest-only, escalates directly to Auditor role notification | High-confidence (≥0.9) fraud flags additionally notify the Auditor role via `notifications`, independent of the normal approval chain, so a compromised Payroll Officer account cannot suppress the alert |
| Salary Forecasting (Forecast Agent) | `employee_salary_components` trend, planned hires/terminations, historical `payroll_runs` totals | Next-3/6/12-month payroll cost projection, by department/branch, with confidence interval | Suggest-only (read/report feature) | Displayed with explicit confidence band, never a single point estimate |
| Overtime Analysis | `attendance` overtime hours by employee/department over time | Trend report + recommendation (e.g., "Department X consistently exceeds policy overtime cap — consider headcount review") | Suggest-only | Statistical confidence stated per recommendation |
| Leave Prediction | `employee_leave_ledger`, seasonal patterns, upcoming public holidays | Predicted leave-balance liability, predicted short-staffing windows | Suggest-only | Probabilistic; framed as "likely range," never as a guarantee |
| Compliance Checks (Compliance Agent) | Current `payroll_items`, active `country_code` statutory rule set, contract terms | Flags: garnishment exceeding statutory cap, overtime multiplier below statutory minimum, PIFSS base miscalculated, indemnity formula deviation | Suggest-only, but a critical compliance flag blocks Submit until a Finance Manager explicitly acknowledges | Compliance flags always cite the specific law/article in `reasoning` |
| Payroll Explanation (Approval Assistant) | A specific `payroll_items` row and its `payroll_item_lines` | Plain-language, bilingual (EN/AR) narrative explaining every line to an employee or approver on request | Auto-generate on request (read-only, no state change) | N/A — purely explanatory, always labeled "AI-generated explanation, verify against payslip" |

No agent above can transition `payroll_runs.status`, create/edit `employee_salary_components`,
`employee_loans`, or `payslips`, or initiate disbursement. Every agent output that would influence money
movement lands as a row in `ai_messages` linked to the relevant `payroll_runs`/`payroll_items` id and is
rendered in the approval UI as a card the human approver reads before deciding — never as a pre-checked
"approve" button.

# Database Design

All tables include the platform's standard columns (`id`, `company_id`, `branch_id`, `created_by`,
`updated_by`, `created_at`, `updated_at`, `deleted_at`) per the shared convention even where not
re-listed in each `CREATE TABLE` below for brevity; they are included in full in the DDL.

```sql
-- ============================================================
-- EMPLOYEES (payroll-relevant extension of the foundation table)
-- ============================================================
CREATE TYPE employment_status AS ENUM (
  'applicant','hired','onboarding','active','on_leave','terminated','archived'
);
CREATE TYPE employment_type AS ENUM ('full_time','part_time','contractor','temporary','intern');
CREATE TYPE marital_status AS ENUM ('single','married','divorced','widowed');
CREATE TYPE preferred_payment_method AS ENUM ('bank_transfer','cash','wallet','cheque');

CREATE TABLE employees (
  id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                  BIGINT NOT NULL REFERENCES companies(id),
  branch_id                   BIGINT NULL REFERENCES branches(id),
  employee_code               VARCHAR(30) NOT NULL,
  first_name_en                VARCHAR(100) NOT NULL,
  last_name_en                 VARCHAR(100) NOT NULL,
  first_name_ar                VARCHAR(100),
  last_name_ar                  VARCHAR(100),
  date_of_birth                 DATE NOT NULL,
  gender                        VARCHAR(10) NOT NULL CHECK (gender IN ('male','female')),
  nationality                   CHAR(2) NOT NULL,
  marital_status                marital_status,
  national_id_encrypted          TEXT NOT NULL,
  national_id_expiry             DATE,
  national_id_country            CHAR(2) NOT NULL,
  passport_number_encrypted       TEXT,
  passport_country               CHAR(2),
  passport_expiry                DATE,
  tax_identification_number       VARCHAR(40),
  department_id                  BIGINT REFERENCES departments(id),
  manager_id                     BIGINT REFERENCES employees(id),
  position_id                    BIGINT REFERENCES positions(id),
  employment_type                 employment_type NOT NULL DEFAULT 'full_time',
  employment_status                employment_status NOT NULL DEFAULT 'applicant',
  hire_date                        DATE,
  termination_date                 DATE,
  termination_reason                VARCHAR(50),
  standard_weekly_hours              NUMERIC(5,2) NOT NULL DEFAULT 48.00,
  work_schedule_id                    BIGINT REFERENCES work_schedules(id),
  is_shift_worker                      BOOLEAN NOT NULL DEFAULT false,
  preferred_payment_method              preferred_payment_method NOT NULL DEFAULT 'bank_transfer',
  preferred_language                    VARCHAR(5) NOT NULL DEFAULT 'en',
  country_code                          CHAR(2) NOT NULL DEFAULT 'KW',
  payroll_ready                          BOOLEAN NOT NULL DEFAULT false,
  is_archived                            BOOLEAN NOT NULL DEFAULT false,
  photo_attachment_id                     BIGINT REFERENCES attachments(id),
  tags                                     JSONB NOT NULL DEFAULT '[]',
  custom_fields                            JSONB NOT NULL DEFAULT '{}',
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uq_employees_code UNIQUE (company_id, employee_code),
  CONSTRAINT chk_employees_dates CHECK (termination_date IS NULL OR termination_date >= hire_date)
);
CREATE INDEX idx_employees_company ON employees (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_employees_status ON employees (company_id, employment_status);
CREATE INDEX idx_employees_manager ON employees (manager_id);
CREATE INDEX idx_employees_department ON employees (department_id);

-- ============================================================
-- SALARY COMPONENTS (company-level templates)
-- ============================================================
CREATE TYPE component_category AS ENUM ('earning','deduction','statutory','employer_cost');
CREATE TYPE component_amount_type AS ENUM ('fixed','formula','percentage');

CREATE TABLE salary_components (
  id                            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                    BIGINT NOT NULL REFERENCES companies(id),
  code                           VARCHAR(50) NOT NULL,
  name_en                          VARCHAR(150) NOT NULL,
  name_ar                          VARCHAR(150) NOT NULL,
  component_type                    VARCHAR(50) NOT NULL,
  category                          component_category NOT NULL,
  amount_type                        component_amount_type NOT NULL DEFAULT 'fixed',
  formula                              TEXT,
  is_social_insurance_eligible          BOOLEAN NOT NULL DEFAULT false,
  is_taxable                             BOOLEAN NOT NULL DEFAULT true,
  is_recurring                            BOOLEAN NOT NULL DEFAULT true,
  gl_debit_account_id                       BIGINT REFERENCES accounts(id),
  gl_credit_account_id                       BIGINT REFERENCES accounts(id),
  status                                      VARCHAR(20) NOT NULL DEFAULT 'active',
  tags JSONB NOT NULL DEFAULT '[]', custom_fields JSONB NOT NULL DEFAULT '{}',
  branch_id BIGINT REFERENCES branches(id),
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uq_salary_components_code UNIQUE (company_id, code)
);
CREATE INDEX idx_salary_components_company ON salary_components (company_id) WHERE deleted_at IS NULL;

-- ============================================================
-- EMPLOYEE SALARY COMPONENTS (assignment, effective-dated)
-- ============================================================
CREATE TABLE employee_salary_components (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  branch_id               BIGINT REFERENCES branches(id),
  employee_id              BIGINT NOT NULL REFERENCES employees(id),
  salary_component_id       BIGINT NOT NULL REFERENCES salary_components(id),
  amount                     NUMERIC(19,4),
  rate                        NUMERIC(18,6),
  currency_code                 CHAR(3) NOT NULL DEFAULT 'KWD',
  effective_from                 DATE NOT NULL,
  effective_to                    DATE,
  status                            VARCHAR(20) NOT NULL DEFAULT 'active'
                                     CHECK (status IN ('pending','active','superseded','cancelled')),
  approved_by                       BIGINT REFERENCES users(id),
  approved_at                        TIMESTAMPTZ,
  source_reference                    VARCHAR(100),
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT chk_esc_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)
);
CREATE INDEX idx_esc_employee_period ON employee_salary_components (employee_id, effective_from, effective_to);
CREATE UNIQUE INDEX uq_esc_no_overlap_base ON employee_salary_components (employee_id, salary_component_id, effective_from)
  WHERE deleted_at IS NULL;

-- ============================================================
-- EMPLOYEE LOANS & INSTALLMENTS
-- ============================================================
CREATE TYPE loan_type AS ENUM ('loan','advance');
CREATE TYPE loan_status AS ENUM ('pending_approval','active','settled','written_off','cancelled');

CREATE TABLE employee_loans (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  branch_id             BIGINT REFERENCES branches(id),
  employee_id             BIGINT NOT NULL REFERENCES employees(id),
  loan_type                 loan_type NOT NULL DEFAULT 'loan',
  principal_amount            NUMERIC(19,4) NOT NULL CHECK (principal_amount > 0),
  currency_code                 CHAR(3) NOT NULL DEFAULT 'KWD',
  outstanding_balance             NUMERIC(19,4) NOT NULL,
  number_of_installments             INT NOT NULL CHECK (number_of_installments > 0),
  installment_amount                  NUMERIC(19,4) NOT NULL,
  start_period_id                       BIGINT REFERENCES payroll_periods(id),
  reason                                  TEXT,
  status                                    loan_status NOT NULL DEFAULT 'pending_approval',
  approved_by                                BIGINT REFERENCES users(id),
  approved_at                                 TIMESTAMPTZ,
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL
);
CREATE INDEX idx_loans_employee ON employee_loans (employee_id);
CREATE INDEX idx_loans_status ON employee_loans (company_id, status);

CREATE TABLE loan_installments (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  loan_id                BIGINT NOT NULL REFERENCES employee_loans(id),
  installment_number       INT NOT NULL,
  due_date                    DATE NOT NULL,
  amount                       NUMERIC(19,4) NOT NULL,
  status                        VARCHAR(20) NOT NULL DEFAULT 'pending'
                                  CHECK (status IN ('pending','paid','skipped','written_off')),
  payroll_item_id                 BIGINT REFERENCES payroll_items(id),
  paid_at                            TIMESTAMPTZ,
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uq_installment_number UNIQUE (loan_id, installment_number)
);
CREATE INDEX idx_installments_due ON loan_installments (company_id, due_date, status);

-- ============================================================
-- LEAVE TYPES & REQUESTS
-- ============================================================
CREATE TABLE leave_types (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id               BIGINT NOT NULL REFERENCES companies(id),
  code                       VARCHAR(30) NOT NULL,
  name_en                      VARCHAR(100) NOT NULL,
  name_ar                       VARCHAR(100) NOT NULL,
  pay_treatment                  VARCHAR(20) NOT NULL DEFAULT 'unpaid'
                                   CHECK (pay_treatment IN ('full_pay','half_pay','unpaid','tiered')),
  accrual_rate                    NUMERIC(6,3) NOT NULL DEFAULT 0,
  minimum_days_per_year              NUMERIC(6,2) NOT NULL DEFAULT 0,
  max_carry_over_days                  NUMERIC(6,2) NOT NULL DEFAULT 0,
  is_statutory_minimum                   BOOLEAN NOT NULL DEFAULT false,
  excludes_rest_days                       BOOLEAN NOT NULL DEFAULT true,
  excludes_public_holidays                   BOOLEAN NOT NULL DEFAULT true,
  status                                       VARCHAR(20) NOT NULL DEFAULT 'active',
  branch_id BIGINT REFERENCES branches(id),
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uq_leave_types_code UNIQUE (company_id, code)
);

CREATE TABLE leave_requests (
  id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  branch_id                BIGINT REFERENCES branches(id),
  employee_id                BIGINT NOT NULL REFERENCES employees(id),
  leave_type_id                 BIGINT NOT NULL REFERENCES leave_types(id),
  start_date                       DATE NOT NULL,
  end_date                            DATE NOT NULL,
  days_requested                        NUMERIC(6,2) NOT NULL,
  reason                                  TEXT,
  status                                    VARCHAR(20) NOT NULL DEFAULT 'pending'
                                             CHECK (status IN ('pending','approved','rejected','cancelled')),
  approved_by                                BIGINT REFERENCES users(id),
  approved_at                                 TIMESTAMPTZ,
  attachment_id                                BIGINT REFERENCES attachments(id),
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT chk_leave_dates CHECK (end_date >= start_date)
);
CREATE INDEX idx_leave_requests_employee ON leave_requests (employee_id, start_date, end_date);
CREATE INDEX idx_leave_requests_status ON leave_requests (company_id, status);

-- ============================================================
-- ATTENDANCE
-- ============================================================
CREATE TABLE attendance (
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  branch_id               BIGINT REFERENCES branches(id),
  employee_id               BIGINT NOT NULL REFERENCES employees(id),
  work_date                    DATE NOT NULL,
  clock_in_at                    TIMESTAMPTZ,
  clock_out_at                     TIMESTAMPTZ,
  worked_minutes                     INT,
  overtime_minutes                     INT NOT NULL DEFAULT 0,
  shift_type                             VARCHAR(20) DEFAULT 'day' CHECK (shift_type IN ('day','night')),
  work_mode                                VARCHAR(20) DEFAULT 'onsite'
                                             CHECK (work_mode IN ('onsite','remote','hybrid')),
  clock_in_method                            VARCHAR(20) CHECK (clock_in_method IN
                                               ('biometric','mobile_geofence','kiosk','manual')),
  is_late                                      BOOLEAN NOT NULL DEFAULT false,
  late_minutes                                  INT NOT NULL DEFAULT 0,
  is_early_leave                                  BOOLEAN NOT NULL DEFAULT false,
  early_leave_minutes                               INT NOT NULL DEFAULT 0,
  status                                              VARCHAR(20) NOT NULL DEFAULT 'complete'
                                                        CHECK (status IN ('complete','incomplete','absent')),
  approved_by                                           BIGINT REFERENCES users(id),
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uq_attendance_employee_day UNIQUE (employee_id, work_date)
);
CREATE INDEX idx_attendance_employee_period ON attendance (employee_id, work_date);
CREATE INDEX idx_attendance_company_period ON attendance (company_id, work_date);

-- ============================================================
-- PAYROLL PERIODS, RUNS, APPROVALS
-- ============================================================
CREATE TYPE payroll_run_status AS ENUM (
  'draft','calculated','pending_officer_approval','pending_finance_approval',
  'pending_ceo_approval','approved','rejected','posted','paid','reversed'
);
CREATE TYPE payroll_run_type AS ENUM ('regular','supplementary','final_settlement','bonus_run','correction');

CREATE TABLE payroll_periods (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  period_start           DATE NOT NULL,
  period_end                DATE NOT NULL,
  pay_date                    DATE NOT NULL,
  frequency                     VARCHAR(20) NOT NULL DEFAULT 'monthly'
                                  CHECK (frequency IN ('monthly','biweekly','weekly','semi_monthly')),
  status                          VARCHAR(20) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uq_payroll_period UNIQUE (company_id, period_start, period_end),
  CONSTRAINT chk_period_dates CHECK (period_end >= period_start)
);

CREATE TABLE payroll_runs (
  id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id           BIGINT NOT NULL REFERENCES companies(id),
  branch_id              BIGINT REFERENCES branches(id),
  payroll_period_id        BIGINT NOT NULL REFERENCES payroll_periods(id),
  run_type                    payroll_run_type NOT NULL DEFAULT 'regular',
  status                        payroll_run_status NOT NULL DEFAULT 'draft',
  total_gross                     NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_net                          NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_employer_cost                   NUMERIC(19,4) NOT NULL DEFAULT 0,
  employee_count                            INT NOT NULL DEFAULT 0,
  currency_code                              CHAR(3) NOT NULL DEFAULT 'KWD',
  journal_entry_id                             BIGINT REFERENCES journal_entries(id),
  reversed_run_id                                BIGINT REFERENCES payroll_runs(id),
  submitted_at                                     TIMESTAMPTZ,
  approved_at                                        TIMESTAMPTZ,
  posted_at                                            TIMESTAMPTZ,
  paid_at                                                TIMESTAMPTZ,
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL
);
CREATE INDEX idx_payroll_runs_company_status ON payroll_runs (company_id, status);
CREATE INDEX idx_payroll_runs_period ON payroll_runs (payroll_period_id);

CREATE TABLE payroll_run_approvals (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  payroll_run_id       BIGINT NOT NULL REFERENCES payroll_runs(id),
  stage                   VARCHAR(30) NOT NULL
                            CHECK (stage IN ('payroll_officer','finance_manager','ceo')),
  approver_id                BIGINT NOT NULL REFERENCES users(id),
  decision                     VARCHAR(20) NOT NULL CHECK (decision IN ('approved','rejected')),
  comment                        TEXT,
  override_reason                  TEXT,
  decided_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_run_stage UNIQUE (payroll_run_id, stage)
);
CREATE INDEX idx_run_approvals_run ON payroll_run_approvals (payroll_run_id);

-- ============================================================
-- PAYROLL ITEMS & LINES
-- ============================================================
CREATE TABLE payroll_items (
  id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id           BIGINT NOT NULL REFERENCES companies(id),
  branch_id              BIGINT REFERENCES branches(id),
  payroll_run_id           BIGINT NOT NULL REFERENCES payroll_runs(id),
  employee_id                BIGINT NOT NULL REFERENCES employees(id),
  department_id                 BIGINT REFERENCES departments(id),
  cost_center_id                   BIGINT REFERENCES cost_centers(id),
  gross_salary                       NUMERIC(19,4) NOT NULL DEFAULT 0,
  pifss_base                           NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_deductions                       NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_statutory                          NUMERIC(19,4) NOT NULL DEFAULT 0,
  net_salary                                 NUMERIC(19,4) NOT NULL DEFAULT 0,
  employer_cost                                NUMERIC(19,4) NOT NULL DEFAULT 0,
  proration_factor                               NUMERIC(6,4) NOT NULL DEFAULT 1.0000,
  currency_code                                    CHAR(3) NOT NULL DEFAULT 'KWD',
  payment_method                                     VARCHAR(20) NOT NULL DEFAULT 'bank_transfer',
  payment_status                                       VARCHAR(20) NOT NULL DEFAULT 'pending'
                                                          CHECK (payment_status IN ('pending','paid','failed')),
  reversal_of_item_id                                    BIGINT REFERENCES payroll_items(id),
  created_by BIGINT REFERENCES users(id), updated_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uq_payroll_item_employee_run UNIQUE (payroll_run_id, employee_id)
);
CREATE INDEX idx_payroll_items_employee ON payroll_items (employee_id);
CREATE INDEX idx_payroll_items_run ON payroll_items (payroll_run_id);

CREATE TABLE payroll_item_lines (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  payroll_item_id       BIGINT NOT NULL REFERENCES payroll_items(id),
  salary_component_id     BIGINT NOT NULL REFERENCES salary_components(id),
  category                   component_category NOT NULL,
  quantity                     NUMERIC(18,4),
  rate                           NUMERIC(18,6),
  amount                           NUMERIC(19,4) NOT NULL,
  source_type                        VARCHAR(50),
  source_id                            BIGINT,
  notes                                   TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_pil_item ON payroll_item_lines (payroll_item_id);
CREATE INDEX idx_pil_component ON payroll_item_lines (salary_component_id);

-- ============================================================
-- PAYSLIPS
-- ============================================================
CREATE TABLE payslips (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  payroll_item_id        BIGINT NOT NULL REFERENCES payroll_items(id),
  employee_id               BIGINT NOT NULL REFERENCES employees(id),
  language                     VARCHAR(5) NOT NULL DEFAULT 'en',
  attachment_id                  BIGINT REFERENCES attachments(id),
  is_visible_to_employee            BOOLEAN NOT NULL DEFAULT false,
  generated_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_payslip_item_lang UNIQUE (payroll_item_id, language)
);
CREATE INDEX idx_payslips_employee ON payslips (employee_id);

-- ============================================================
-- WPS EXPORT BATCHES
-- ============================================================
CREATE TABLE wps_export_batches (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  payroll_run_id         BIGINT NOT NULL REFERENCES payroll_runs(id),
  bank_code                 VARCHAR(10) NOT NULL,
  file_format                  VARCHAR(20) NOT NULL DEFAULT 'CBK_SIF',
  file_attachment_id              BIGINT REFERENCES attachments(id),
  total_amount                       NUMERIC(19,4) NOT NULL,
  employee_count                        INT NOT NULL,
  status                                   VARCHAR(20) NOT NULL DEFAULT 'generated'
                                              CHECK (status IN ('generated','submitted','acknowledged','failed')),
  submitted_at                                TIMESTAMPTZ,
  acknowledged_at                               TIMESTAMPTZ,
  created_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_wps_batches_run ON wps_export_batches (payroll_run_id);
```

**Relationships summary**: `employees 1—N employee_salary_components N—1 salary_components`;
`employees 1—N employee_loans 1—N loan_installments`; `employees 1—N leave_requests N—1 leave_types`;
`employees 1—N attendance`; `payroll_runs 1—N payroll_items 1—N payroll_item_lines N—1 salary_components`;
`payroll_items 1—N payslips`; `payroll_runs 1—N payroll_run_approvals`; `payroll_runs 1—N
wps_export_batches`; `payroll_runs 1—1 journal_entries` (nullable until posted).

**History & versioning**: `employee_salary_components` and `leave_types` implement history via
effective-dating (`effective_from`/`effective_to`) rather than a separate history table — the current row
is simply the one whose range contains "today." `payroll_runs.reversed_run_id` and
`payroll_items.reversal_of_item_id` implement corrections as linked new rows rather than edits, giving a
complete version chain without ever needing `UPDATE` on posted financial data.

# API

All endpoints are versioned under `/api/v1/payroll/`, require a Bearer token, are scoped to
`X-Company-Id`, and return the standard QAYD response envelope.

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | /api/v1/payroll/employees/{id}/salary-components | payroll.salary.view | List an employee's salary components (effective-dated) |
| POST | /api/v1/payroll/employees/{id}/salary-components | payroll.salary.manage | Assign/update a salary component |
| GET | /api/v1/payroll/periods | payroll.read | List payroll periods |
| POST | /api/v1/payroll/periods | payroll.periods.manage | Create a payroll period |
| POST | /api/v1/payroll/runs | payroll.run.create | Create a new draft payroll run for a period |
| POST | /api/v1/payroll/runs/{id}/calculate | payroll.run.calculate | Run/re-run the calculation engine |
| GET | /api/v1/payroll/runs/{id} | payroll.read | Get run detail with totals |
| GET | /api/v1/payroll/runs/{id}/items | payroll.read | List payroll items for a run |
| POST | /api/v1/payroll/runs/{id}/submit | payroll.run.submit | Submit calculated run for approval |
| POST | /api/v1/payroll/runs/{id}/approve | payroll.run.approve | Approve at the caller's current stage |
| POST | /api/v1/payroll/runs/{id}/reject | payroll.run.approve | Reject at the caller's current stage |
| POST | /api/v1/payroll/runs/{id}/post | payroll.run.post | Post the approved run to the GL |
| POST | /api/v1/payroll/runs/{id}/disburse | payroll.disburse | Generate payment instructions / WPS file |
| POST | /api/v1/payroll/runs/{id}/reverse | payroll.run.reverse | Reverse a posted run |
| GET | /api/v1/payroll/payslips/{id} | payroll.payslip.view | Get payslip metadata + signed download URL |
| GET | /api/v1/payroll/employees/{id}/payslips | payroll.payslip.view.self OR payroll.payslip.view | List an employee's payslips |
| POST | /api/v1/payroll/attendance | attendance.write | Bulk-import/create attendance records |
| GET | /api/v1/payroll/attendance | attendance.read | Query attendance for a period |
| POST | /api/v1/payroll/leave-requests | leave.request | Submit a leave request |
| POST | /api/v1/payroll/leave-requests/{id}/approve | leave.approve | Approve a leave request |
| POST | /api/v1/payroll/loans | payroll.loan.create | Create an employee loan/advance |
| POST | /api/v1/payroll/loans/{id}/approve | payroll.loan.approve | Approve a pending loan |
| POST | /api/v1/payroll/bonuses | payroll.bonus.create | Create a one-off bonus component |
| GET | /api/v1/payroll/reports/summary | reports.payroll.view | Payroll Summary report |
| GET | /api/v1/payroll/reports/cost | reports.payroll.view | Payroll Cost report by dimension |
| POST | /api/v1/payroll/runs/bulk-calculate | payroll.run.calculate | Recalculate multiple runs (multi-branch) |

## Example — Create a Payroll Run

Request:
```json
POST /api/v1/payroll/runs
X-Company-Id: 101
{
  "payroll_period_id": 44,
  "run_type": "regular",
  "branch_id": 3
}
```
Response:
```json
{
  "success": true,
  "data": {
    "id": 512,
    "company_id": 101,
    "branch_id": 3,
    "payroll_period_id": 44,
    "run_type": "regular",
    "status": "draft",
    "total_gross": "0.0000",
    "total_net": "0.0000",
    "employee_count": 0,
    "currency_code": "KWD"
  },
  "message": "Payroll run created in draft status.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7c2b1e4a-3f9d-4e21-9a10-8b5c7d2e1f00",
  "timestamp": "2026-07-16T06:00:00Z"
}
```

## Example — Calculate a Payroll Run

Request: `POST /api/v1/payroll/runs/512/calculate`

Response:
```json
{
  "success": true,
  "data": {
    "id": 512,
    "status": "calculated",
    "total_gross": "148500.0000",
    "total_net": "121430.5000",
    "total_employer_cost": "16335.0000",
    "employee_count": 87,
    "warnings": [
      { "employee_id": 2044, "type": "compliance", "severity": "critical",
        "message": "Garnishment deduction would exceed 25% of net pay cap.",
        "ai_confidence": 0.97 },
      { "employee_id": 2101, "type": "anomaly", "severity": "warning",
        "message": "Overtime hours 42% above 6-month baseline.",
        "ai_confidence": 0.81 }
    ]
  },
  "message": "Calculation complete for 87 employees with 2 warnings.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "a1f0c9d2-88b1-4a7e-9c3d-201f4e6b7a55",
  "timestamp": "2026-07-16T06:05:12Z"
}
```

## Example — Approve a Payroll Run (Finance Manager stage)

Request:
```json
POST /api/v1/payroll/runs/512/approve
{ "comment": "Reviewed totals against last month; variance explained by 2 new hires." }
```
Response:
```json
{
  "success": true,
  "data": {
    "id": 512,
    "status": "pending_ceo_approval",
    "approvals": [
      { "stage": "payroll_officer", "approver_id": 55, "decision": "approved",
        "decided_at": "2026-07-16T07:10:00Z" },
      { "stage": "finance_manager", "approver_id": 12, "decision": "approved",
        "decided_at": "2026-07-16T09:22:41Z" }
    ]
  },
  "message": "Approved at Finance Manager stage. Awaiting CEO approval.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "5e8a3c11-0d4f-4b6a-8e19-6c2f1a9d3b77",
  "timestamp": "2026-07-16T09:22:41Z"
}
```

## Example — Get a Payslip

Request: `GET /api/v1/payroll/payslips/9931`

Response:
```json
{
  "success": true,
  "data": {
    "id": 9931,
    "employee_id": 2044,
    "language": "ar",
    "period": "2026-06-01 to 2026-06-30",
    "gross_salary": "950.0000",
    "net_salary": "801.2500",
    "currency_code": "KWD",
    "lines": [
      { "component": "base_salary", "amount": "750.0000" },
      { "component": "housing_allowance", "amount": "150.0000" },
      { "component": "transport_allowance", "amount": "50.0000" },
      { "component": "social_insurance_employee", "amount": "-93.7500" },
      { "component": "loan_repayment", "amount": "-55.0000" }
    ],
    "download_url": "https://cdn.qayd.app/signed/payslip-9931.pdf?exp=1752700000&sig=…"
  },
  "message": "Payslip retrieved.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "c4d7e2f8-11a9-4b3d-9f0e-77a8b1c5d9e2",
  "timestamp": "2026-07-16T10:00:00Z"
}
```

## Example — Validation Error (422)

Request: `POST /api/v1/payroll/loans` with `principal_amount: -500`

Response:
```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    { "field": "principal_amount", "code": "min_value", "message": "principal_amount must be greater than 0." }
  ],
  "meta": { "pagination": null },
  "request_id": "e19f2a4c-6b3d-4a1e-9c8f-3d0b7e5a1f44",
  "timestamp": "2026-07-16T10:05:00Z"
}
```

# Reports

| Report | Grain | Key Dimensions | Notes |
|---|---|---|---|
| Payroll Summary | Per run | Company, branch | Total gross/net/employer cost, headcount, variance vs. prior run |
| Employee Payslip | Per employee, per period | Employee | The payslip itself, re-exposed as a queryable report row |
| Department Payroll | Per department, per period | Department | Rolls up `payroll_items` via `employees.department_id` at calculation time |
| Branch Payroll | Per branch, per period | Branch | Rolls up via `payroll_items.branch_id` |
| Payroll Cost | Per cost center/project, per period | Cost center, project | Sourced from `journal_lines` dimension tags — reconciles to GL by construction |
| Overtime | Per employee/department, trailing N periods | Employee, department | Sourced from `attendance.overtime_minutes` × rate; feeds the AI Overtime Analysis agent |
| Leave | Per employee/leave type | Employee, leave type | Balances, usage, upcoming expirations, projected liability |
| Salary Comparison | Per position/grade | Position, grade | Internal equity check — flags outliers vs. band, informs (never auto-changes) compensation review |
| Tax Report | Per period | Country | No-op output for Kuwait today; ready for jurisdictions with income tax |
| Insurance Report | Per period | PIFSS contribution category | Employee + employer PIFSS contributions, reconciles to the PIFSS filing |

Every report is generated by the standard `report_definitions`/`report_runs` machinery (per platform
convention) with a Payroll-specific `report_definitions.category = 'payroll'` and enforces
`payroll.salary.view`/`payroll.reports.view` at the row level, not just at the report-launch level, so a
scoped Payroll Officer (branch-restricted) sees only their branch's rows even inside a company-wide report
export.

# Permissions

| Permission Key | Description |
|---|---|
| payroll.read | View payroll periods/runs (no salary amounts) |
| payroll.salary.view | View individual salary amounts and components |
| payroll.salary.manage | Create/update employee salary components |
| payroll.run.create | Create a new payroll run |
| payroll.run.calculate | Trigger/re-trigger calculation |
| payroll.run.submit | Submit a calculated run into the approval chain |
| payroll.run.approve | Approve/reject at the caller's approval stage |
| payroll.run.post | Post an approved run to the GL |
| payroll.disburse | Generate payment instructions / release funds |
| payroll.run.reverse | Reverse a posted run |
| payroll.loan.create | Create employee loans/advances |
| payroll.loan.approve | Approve loans above a company threshold |
| payroll.bonus.create | Create discretionary bonuses |
| payroll.payslip.view | View any employee's payslip |
| payroll.payslip.view.self | View only one's own payslip |
| payroll.pii.view | View encrypted PII (national ID, passport, bank account) |
| attendance.read / attendance.write | View / import attendance |
| leave.request / leave.approve | Submit / approve leave requests |
| reports.payroll.view | Run and export payroll reports |
| payroll.periods.manage | Create/close payroll periods |

| Role | payroll.read | salary.view | run.create/calculate | run.approve (stage) | run.post | disburse | payslip.view (all) | pii.view |
|---|---|---|---|---|---|---|---|---|
| Owner | Yes | Yes | Yes | All stages | Yes | Yes | Yes | Yes |
| CEO | Yes | Yes | No | ceo stage only | No | No | Yes | Limited |
| CFO | Yes | Yes | Yes | finance_manager stage | Yes | Yes | Yes | Limited |
| Finance Manager | Yes | Yes | Yes | finance_manager stage | Yes | Yes | Yes | Limited |
| Payroll Officer | Yes | Yes | Yes | payroll_officer stage | No | No | Yes (scoped) | Yes (scoped) |
| HR Manager | Yes | Yes | No | No | No | No | Yes | Yes |
| Senior Accountant | Yes | No | No | No | No | No | No | No |
| Accountant | Yes | No | No | No | No | No | No | No |
| Auditor / External Auditor | Yes | Yes (read-only) | No | No | No | No | Yes (read-only) | Yes (read-only) |
| Employee | No | Own only | No | No | No | No | Own only | Own only |
| AI Agent | Read-only, masked PII | No (masked) | No | No | No | No | No | No |

Sensitive operations — payroll run posting, disbursement, loan approval above threshold, permission
changes — always require the human approval chain; no permission grant can make an AI Agent role a valid
approver at any stage, enforced at the database level via a `CHECK` on `payroll_run_approvals.approver_id`
requiring the referenced `users.id` to correspond to a human account (`users.is_service_account = false`).

# Notifications

Payroll fires notifications on: `leave_requests` submitted/approved/rejected (to employee and manager);
`payroll_runs` reaching each approval stage (to the next approver — Payroll Officer notified on
`calculated`→submit, Finance Manager on officer approval, CEO on finance approval); `payroll_runs.posted`
(to Finance, informational); `payroll_runs.paid` (to each employee, "Your payslip for June 2026 is ready");
`employee_loans` approved/rejected (to employee); `national_id_expiry`/`passport_expiry` within 30 days (to
HR); AI critical anomaly/fraud/compliance flags (to the Auditor role, independent of the approval chain, as
described in AI Responsibilities); `wps_export_batches` submitted/acknowledged/failed (to Finance).
Notifications ride the shared `notifications` table and Laravel Reverb channel per company, with a
Payroll-specific channel (`payroll.{company_id}`) so a Finance Manager's UI badge updates in real time as
approvals move through the chain without polling.

# Security

## Encryption

`national_id_encrypted`, `passport_number_encrypted`, `iban_encrypted`, `account_number_encrypted`, and
`wallet_id_encrypted` use Laravel's `encrypted` Eloquent cast (AES-256-GCM, application-level key, rotated
per the platform's standing key-rotation policy). These columns are never included in any AI-layer payload
in plaintext — the Laravel API masks them (`***-***-1234`, IBAN last-4 only) before any data crosses into
the FastAPI AI layer, and the AI layer's audit log records that only masked values were ever received.

## Salary Privacy

Salary visibility is enforced independently of general accounting permissions via `payroll.salary.view`,
which is not implied by `accounting.read` or any generic "manager" role — a department manager sees their
team's attendance and leave requests but not their salaries unless explicitly granted. Row-level scoping
additionally restricts `payroll.salary.view` holders with a `branch_id` scope (e.g., a branch-level Payroll
Officer) to `payroll_items`/`employee_salary_components` rows within their branch, enforced via Postgres
row-level security policies mirroring the platform's company-isolation RLS pattern, extended with a
branch-scope predicate for Payroll tables specifically.

## Role Based Access

All Payroll endpoints resolve permissions through the shared `roles`/`permissions`/`company_users` tables;
no Payroll-specific auth mechanism exists. Default-deny: an endpoint with no matching permission grant
returns 403, never a degraded read.

## Audit Logs

Every mutation to `employees` (payroll-relevant fields), `employee_salary_components`, `employee_loans`,
`payroll_runs` (every status transition), and `payroll_run_approvals` writes to `audit_logs` with
old-value/new-value diffs, actor, timestamp, IP, and device — including AI-agent-authored recommendation
records, tagged with the agent name and confidence rather than a human `user_id`, so the audit trail always
distinguishes "a human decided this" from "AI suggested this and a human decided."

## Approval Workflow

The three-stage chain (Payroll Officer → Finance Manager → CEO) is enforced as a strict state machine in
`PayrollApprovalService` — an approval call is rejected with 409 Conflict if the caller's role does not
match the run's current `status`'s required stage, even if the caller holds `payroll.run.approve`
generally (the permission grants *eligibility* to approve at one's stage; the state machine enforces
*sequence*). A run can never skip a stage, and a rejection at any stage returns the run fully to `draft`,
requiring the entire chain to restart — there is no "resume from where it was rejected."

# Performance

Payroll calculation for a company with several thousand employees is designed to complete a full
run in well under a minute: `PayrollCalculationService::calculate()` processes employees in
chunks (default 200) inside queued Laravel jobs dispatched to Redis-backed queues, parallelized across
workers, with the parent `payroll_runs` row moving to `calculated` only once every chunk job reports
success (tracked via a Redis-backed batch counter, Laravel's native job batching). Heavy aggregation
queries (`attendance` sums, `employee_commission_ledger` pulls) use covering indexes
(`idx_attendance_employee_period`, and equivalents on the commission ledger) and are scoped to the exact
period date range to avoid full-table scans. `payroll_items`/`payroll_item_lines` reads for reporting are
never queried live against the transactional tables at report-run scale — the Reports engine's
`report_runs` materializes results into a cached result set, refreshed on demand, consistent with how GL
reporting elsewhere in QAYD avoids re-aggregating raw ledger rows on every dashboard load. Payslip PDF
generation is queued and parallelized (one job per employee) rather than generated synchronously in the
posting request, so posting a 1,000-employee run does not block on rendering 1,000 PDFs before returning.

# Edge Cases

- **Mid-period hire/termination**: handled by the pro-ration factor (Payroll Processing → Calculation
  Engine, step 2); an employee hired on the 20th of a 30-day month receives `11/30` of fixed components,
  full attendance-derived pay for days actually worked.
- **Employee with zero attendance and an hourly_wage component**: gross pay for the period is legitimately
  `0.0000` — the engine does not substitute a default and does not exclude the employee from the run
  (they still appear with a zero payslip), which is itself a useful AI Anomaly Detection signal if it
  persists across periods (potential ghost employee).
  - **Negative net pay** (deductions/loan installments exceed gross): the engine caps deductions so
  `net_salary` never goes negative — any installment that would breach the floor is automatically
  deferred (a new `loan_installments` row is inserted for the next period, `status = 'pending'`, and the
  current one is marked `status = 'skipped'` with a system note), and a warning surfaces in the
  calculation response; garnishments are similarly capped, never causing negative net pay.
- **Employee transferred mid-period between branches with different overtime multipliers**: the engine
  splits attendance-derived earnings for that period at the transfer date boundary, applying each branch's
  active rule set to its portion — never a single blended rate.
- **Retroactive salary change (arrears)**: handled via the `arrears` component in the next run referencing
  the original period, not a reversal of historical runs, per Payroll Processing → Corrections.
  - **Employee resigns with an active loan balance greater than their final settlement net pay**: the
  shortfall is recorded as an `employee_loans.status` remaining `active` with `outstanding_balance > 0`
  after the settlement, flagged to HR/Finance for out-of-payroll recovery (legal/collections process) — the
  system never forces net pay negative or silently writes off the balance without an explicit
  `written_off` status change by an authorized user.
- **Duplicate national ID across two `employees` rows** (data-entry error or attempted fraud): a unique
  partial index on `national_id_encrypted` cannot be applied directly (it's encrypted, non-deterministic
  ciphertext per row), so uniqueness is enforced via a separate deterministic HMAC-hash column
  (`national_id_hash`, indexed `UNIQUE (company_id, national_id_hash) WHERE deleted_at IS NULL`) computed
  alongside the encrypted value at write time — giving both encryption-at-rest and duplicate detection.
- **Public holiday falling on an employee's normal rest day**: no double-count; the employee receives
  their normal rest-day treatment, not both rest-day and holiday pay, unless they actually worked that day
  (in which case `holiday_pay` applies to hours worked, per Payroll Components → Holiday Pay).
- **Company changes a salary component's formula mid-cycle** (e.g., revises the overtime multiplier):
  the change is itself effective-dated (`salary_components` are not directly mutable in place for
  formula/rate changes — a new version row is created with its own `effective_from`), so any `draft`
  run recalculated after the change still uses whichever version was active during the run's period, not
  the just-edited "current" version, preventing silent retroactive changes to historical calculations.
- **Bank rejects a WPS file line** (e.g., closed account, invalid IBAN): the specific `payroll_items` row
  is set `payment_status = 'failed'`, not rolled back into the whole run; Finance re-issues payment for
  just that employee via a corrected bank detail and a fresh, smaller disbursement batch, without touching
  the already-successful lines.
- **AI flags a false positive anomaly** (e.g., a legitimate large one-off bonus flagged as a salary
  spike): the Payroll Officer's `override_reason` on acknowledgment is retained permanently in
  `payroll_run_approvals`, both as an audit trail and as a (future) feedback signal a human curator can use
  to retrain/tune the anomaly-detection thresholds — the system does not auto-suppress similar future
  flags based on a single override.

# Compliance

Payroll's compliance layer is built as a **country strategy** — every statutory rule referenced below is
resolved through a `country_code`-scoped configuration table (`statutory_rules`,
`social_insurance_rates`, `overtime_rules`) rather than hard-coded, so the initial Kuwait ruleset ships as
data and additional Gulf/GCC countries are added the same way.

## Labor Laws

Kuwait private-sector employment is governed primarily by Labour Law No. 6 of 2010 (as amended). QAYD's
Payroll module encodes the following provisions directly into calculation and validation logic:

- **Working hours** (Art. 64-65): standard 48 hours/week private sector (8 hours/day, 6 days/week), 36
  hours/week in Ramadan for Muslim employees (configurable per-employee flag,
  `employees.reduced_ramadan_hours`).
- **Overtime** (Art. 66-67): minimum 1.25× for ordinary overtime, 1.5× for rest-day/holiday overtime —
  encoded as the default `overtime_rules` seed data, editable only upward.
- **Weekly rest day** (Art. 21): minimum one paid rest day per week, default Friday, configurable.
- **Annual leave** (Art. 70): minimum 30 calendar days per year after the first year of continuous
  service (proportional in the first year); leave may not be waived for cash except on termination.
- **Sick leave** (Art. 68): tiered — 15 days full pay, next 10 days three-quarter or half pay (subject to
  medical certification), next 10 days unpaid, within a 12-month rolling window — encoded in
  `leave_types.pay_treatment = 'tiered'` with a `tier_schedule` JSONB defining the day thresholds and pay
  fractions.
- **Maternity leave** (Art. 22): 70 days full pay, plus provisions for extended unpaid leave; QAYD models
  the extension as a separate `maternity_extended` leave type at `pay_treatment = 'unpaid'`.
- **End-of-service indemnity** (Art. 51-56): the terminal benefit calculated and accrued monthly (see
  below).
- **Termination notice and severance** (Art. 41, 47-50): notice-period minimums by service length,
  encoded on `employee_contracts.notice_period_days` with a floor validated against tenure at
  termination time.

## Tax Rules

Kuwait levies **no personal income tax** on salaries paid to employees (Kuwaiti nationals or expatriates)
in the private sector. `tax_withholding` therefore always calculates to `0.0000` for `country_code =
'KW'` employees under the seeded Kuwait `statutory_rules` — the component and its full plumbing
(calculation, journal line, payslip line, Tax Report) exist and are exercised (as a real zero, not a
skipped step) so the same code path is proven correct ahead of any future country requiring nonzero
withholding. Corporate-level Kuwait taxes (Zakat, National Labour Support Tax, Kuwait Foundation for the
Advancement of Sciences contribution, and foreign-entity income tax under Decree No. 3/1955) are Zakat/Tax
module concerns outside Payroll's scope.

## Country Specific Rules — Gulf / Kuwait

### PIFSS Social Insurance

The Public Institution for Social Security (PIFSS) administers Kuwait's mandatory social-insurance scheme
under Law No. 61 of 1976 (as amended), covering **Kuwaiti nationals** and, per the GCC Unified Economic
Agreement, other **GCC nationals** working in Kuwait (contributing to their home-country scheme at rates
their home country sets, remitted via PIFSS as a collection agent for GCC nationals per bilateral
arrangements). Non-GCC expatriates are not covered by PIFSS.

`social_insurance_rates` (seeded per `country_code = 'KW'`, `nationality_category`):

| Nationality Category | Employee Share | Employer Share | Contribution Base |
|---|---|---|---|
| Kuwaiti national | 8% (indicative; actual current statutory rate must be kept in sync with PIFSS circulars — QAYD stores it as an editable rate row, not a hard-coded constant, precisely because these rates are periodically revised) | 11.5% (indicative, employer share is materially higher than employee share, reflecting Kuwait's employer-funded design) | Basic wage + eligible fixed allowances, capped at the PIFSS maximum contributable wage ceiling (also a configurable rate-table value, since PIFSS periodically raises the ceiling) |
| GCC national | Rate per home-country GCC scheme (configurable per nationality) | Employer share per Kuwait rate, remitted to PIFSS as collecting agent | Same base definition |
| Non-GCC expatriate | N/A — exempt | N/A — exempt (indemnity substitutes, see below) | N/A |

The `pifss_base` computed per Payroll Components → Insurance excludes variable pay (commission, overtime,
one-off bonuses) by default (`salary_components.is_social_insurance_eligible = false` for those
component types in the seed data) and includes base salary plus the specific allowances PIFSS regulations
designate as contributable — this mapping is reviewed and versioned in `statutory_rules` whenever PIFSS
issues a clarifying circular, without requiring an application deployment.

Payroll produces a monthly **PIFSS contribution schedule** (feeding the Insurance Report) listing, per
covered employee, PIFSS number, contribution base, employee share, and employer share, formatted for
submission to PIFSS's e-services portal; QAYD does not currently integrate PIFSS's submission API directly
(a manual upload of the generated schedule is the initial workflow), but the schedule's structure is kept
aligned with PIFSS's published file layout so integration is additive, not a redesign.

### End-of-Service Indemnity

Under Labour Law No. 6/2010 Art. 51-56, every employee whose service ends (other than dismissal for
serious misconduct under Art. 41, which forfeits or reduces indemnity per the article's specific
conditions) is entitled to an end-of-service gratuity:

- **15 days' wage per year** for each of the first five years of continuous service.
- **One month's wage per year** for each year of service beyond five years.
- Calculated on the **last basic wage** (not gross, and specifically excluding allowances unless the
  contract states otherwise), pro-rated for partial years.
- Capped at **1.5 years' total wage** (18 months) as the statutory maximum, per Art. 51's ceiling.
- Resignation before 3 years of service typically forfeits indemnity entirely or reduces it to a fraction
  under Art. 51's schedule (1/3 for 3-5 years, 2/3 for 5-10 years, full entitlement beyond 10 years, for
  *resignation* specifically — dismissal-with-cause and completion-of-fixed-term scenarios follow
  different schedules) — QAYD encodes this as an `indemnity_resignation_schedule` rate table keyed by
  years-of-service bands, applied only when `termination_reason = 'resignation'`.

QAYD **accrues** indemnity monthly as an employer-cost, non-cash component
(`indemnity_accrual`, Payroll Components table) so the Balance Sheet's Provision for Indemnity
(GL account 2130, Accounting Integration) always reflects the company's running obligation rather than a
surprise lump sum at termination — this mirrors how SAP and Oracle treat end-of-service/gratuity
provisioning in Gulf payroll configurations. The **actual calculation and payment** of indemnity happens
once, at termination, in the `final_settlement` run (Employee Lifecycle → Termination), computed from the
full service history (respecting rehire rules — Employee Lifecycle → Rehire) and the applicable
resignation/dismissal schedule, not simply drawn down from the accrued provision balance (the provision is
an accounting estimate; the termination calculation is the authoritative statutory figure, and any
difference between the two is recognized as a one-time GL adjustment at settlement).

### WPS — Wage Protection System

Kuwait's Wage Protection System requires in-scope private-sector employers to pay salaries through
licensed local banks or exchange companies within a defined window after the pay period end, submitting
salary data electronically so the Public Authority for Manpower (PAM) can monitor timely, complete
payment. QAYD implements this via `wps_export_batches` (Database Design): on disbursement, the
`WpsFileGenerator` service produces a bank-format Salary Information File (SIF) — a structured file (CSV
or fixed-width, per the receiving bank's published WPS specification) containing, per employee: civil ID,
IBAN, net salary amount, currency, and a payment reference tying back to `payroll_items.id`. The generated
file is attached (`wps_export_batches.file_attachment_id`) for Finance to submit through the bank's WPS
portal or API; `status` progresses `generated → submitted → acknowledged` (or `failed`, triggering the
per-line failure handling in Edge Cases). QAYD tracks the **statutory payment window** (salary due within
a configurable number of days after `payroll_periods.period_end`, matching the employer's registered pay
cycle) and raises a compliance warning via the Compliance Agent if a run has not reached `paid` status
within that window.

# Future Improvements

- **Direct PIFSS e-services API integration**, replacing the manual-upload contribution schedule with a
  submit-and-reconcile API call once PIFSS exposes a stable programmatic interface.
- **Direct bank WPS API submission** for banks that support it, removing the manual portal-upload step
  entirely for those banking partners.
- **Multi-country statutory rule packs** for Saudi Arabia (GOSI contribution rates and end-of-service
  rules under Saudi Labour Law), UAE (WPS via the Central Bank/MOHRE, no social insurance for expatriates,
  gratuity under the UAE Labour Law), Qatar, Bahrain, and Oman — each delivered as a `statutory_rules`
  seed pack plus a country-strategy class implementing the same interface Kuwait uses today, requiring no
  changes to the core calculation engine.
- **Collective bargaining / union agreement overlays** — a configurable rule layer that can override
  specific statutory minimums upward for unionized workforces, versioned separately from company-wide
  policy.
- **Self-service salary certificate generation** — an on-demand, digitally signed salary/employment
  certificate (frequently required by employees for bank loans, visas, or embassy applications in Kuwait),
  auto-populated from `employee_salary_components` and `payroll_items` history.
- **Real-time payroll cost simulation** — letting a Finance Manager model the cost impact of a proposed
  raise, new hire, or restructuring against the Forecast Agent's projection before committing the change,
  surfaced as a "what-if" panel in the Next.js frontend.
- **Deeper AI Fraud Detection graph analysis** — extending beyond duplicate-IBAN/ghost-employee heuristics
  to a proper relationship-graph model correlating employees, bank accounts, approvers, and vendors across
  Payroll and Purchasing to catch collusive fraud patterns spanning both modules.
- **Configurable multi-tier approval chains** — today fixed at Payroll Officer → Finance Manager → CEO;
  future versions could let a company insert additional stages (e.g., a Regional Finance Director) or
  branch differently by run size/type, while preserving the non-negotiable rule that the chain always
  terminates in a human.
- **Automated leave-liability funding recommendations** — an AI Forecast Agent extension recommending how
  much cash a company should reserve against its accrued annual-leave and indemnity liabilities, based on
  workforce trend data.

# End of Document
