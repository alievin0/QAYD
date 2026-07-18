# Payroll — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PAYROLL
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Payroll Runs screen set — the run list and the
single, stage-aware run detail page — over the `payroll_runs` / `payroll_items` / `payroll_item_lines` /
`payroll_run_approvals` / `wps_export_batches` / `payslips` tables that `docs/accounting/PAYROLL.md` defines
as the regulated financial subsystem responsible for compensating every employee QAYD tracks. Every dinar
that leaves a company's bank account as salary, every PIFSS contribution schedule filed with Kuwait's Public
Institution for Social Security, and every end-of-service indemnity accrual on the Balance Sheet passes
through this screen's data model in exactly one shape: a payroll run, calculated once, approved through a
mandatory three-stage human chain, posted to the General Ledger, and disbursed — never edited after the
fact, only reversed and re-issued. This is, by the backend specification's own framing, "a regulated
financial subsystem, not a convenience feature," and this document's job is to render that regulation
faithfully rather than to soften it into a friendlier-looking form that quietly drops a safeguard.

Two sub-surfaces are covered by this one document, sharing one route family, one data model, and one
permission surface, per `docs/frontend/README.md`'s "two audiences, one shell" rule:

1. **List** (`/payroll/runs`) — every `payroll_runs` row across every period, branch, and run type the
   viewer is entitled to see, filterable by status/period/branch/run type, with the aggregate figures
   (`total_gross`, `total_net`, `total_employer_cost`) masked for any role that lacks `payroll.salary.view`.
2. **Detail** (`/payroll/runs/[runId]`) — the full lifecycle of one run, rendered as a persistent lifecycle
   header plus five tabs (Employees, Approvals, WPS & Disbursement, Payslips, History) that together cover
   every stage `docs/accounting/PAYROLL.md → Payroll Processing` names: `draft` → **Calculate** →
   `calculated` → **Submit** → `pending_officer_approval` → **Payroll Officer approves** →
   `pending_finance_approval` → **Finance Manager approves** → `pending_ceo_approval` → **CEO approves** →
   `approved` → **Post** → `posted` → **Disburse** → `paid`, with **Reverse** available from `posted` or
   `paid` and **Reject** returning the run to `draft` from any pending stage.

This screen never computes a payroll figure of its own. Every gross/net/PIFSS/indemnity number, every
pro-ration factor, every anomaly flag is computed by `PayrollCalculationService` on the Laravel API; the
client's only arithmetic is cosmetic (formatting a `NUMERIC(19,4)` string for display, summing already-fetched
rows for a client-side subtotal caption) and is never trusted over what the server's next response says,
consistent with `FRONTEND_ARCHITECTURE.md`'s Principle 1. Nothing in this document introduces a validation
rule, a permission key, a status value, or an API shape that is not already defined in
`docs/accounting/PAYROLL.md`; this document is strictly the rendering, interaction, and state-management
contract for that backend specification.

Payroll carries one structural property no other screen in QAYD shares to the same degree: **salary
visibility is a second, independent permission axis, orthogonal to read access to the run itself.** A Senior
Accountant or Accountant holds `payroll.read` — they can see that a July payroll run exists, its status, and
its employee count — but not `payroll.salary.view`, so they can never see what it actually cost. This
document's single most safety-critical design decision, `SalaryAmountCell` (`# Components Used`), exists
because of that split, and every other section of this document — the List's columns, the Employees tab's
table, the Payslips tab — is built assuming that split is real and enforced at the API response level, never
assumed away for convenience.

The three-stage approval chain (Payroll Officer → Finance Manager → CEO) is, per
`docs/accounting/PAYROLL.md → Purpose`, unconditional: unlike Journal Entries, where a low-value entry can
post directly without any human approval at all, **no payroll run — regardless of size, run type, or
company — ever reaches `posted` without all three signatures.** There is no tiered exemption to reproduce in
the client, which makes this screen's primary-action logic simpler than the Journal Entry Composer's in one
specific respect: the lifecycle action's label is a pure function of `status`, never additionally branched
on a permission-dependent amount threshold.

# Route & Access

## Route tree

```text
app/(app)/payroll/runs/
├── page.tsx                     # List — Server Component, first-paint fetch
├── loading.tsx                  # List skeleton (streamed while the Server Component fetches)
└── [runId]/
    ├── page.tsx                 # Detail — the stage-aware lifecycle shell, five tabs
    └── loading.tsx              # Detail skeleton (header + tab-bar shape)
```

`[runId]` follows the platform's dynamic-segment convention — camelCase, named for the entity, never the
generic `[id]` (`FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — and its API-resource mirror is
`/api/v1/payroll/runs/{id}`. This route family sits directly under `app/(app)/payroll/`, a sibling of
`app/(app)/accounting/`, not nested beneath it — Payroll is its own primary-navigation module
(`NAVIGATION_SYSTEM.md → Primary Navigation`, row 7: `Payroll` · `Users` icon · gate `payroll.read`), even
though it posts into Accounting's General Ledger exactly as Sales and Purchasing do. `NAVIGATION_SYSTEM.md`'s
own Payroll sub-item list names this exact leaf — "Payroll Runs — `/payroll/runs/[runId]`" — alongside
sibling leaves (`Employees`, `Payslips`, `Attendance`, `Loans`) that are each their own screen document,
outside this one's scope; this document owns only the run list and run detail, and treats the others as
reference-data or cross-links, never re-specifying them.

**Deliberately no `/payroll/runs/new` route.** Unlike the Journal Entry Composer — a substantial multi-line
form a human authors by hand — creating a payroll run is a three-field decision (`payroll_period_id`,
`run_type`, optional `branch_id`); every other figure on the run is generated by the calculation engine, not
typed. A dedicated route for three fields would be empty scaffolding wrapped around a `Dialog`'s worth of
content. `NewPayrollRunDialog` (`# Components Used`) is opened from the List's Page Header, gated
`payroll.run.create`, and on success navigates straight to `/payroll/runs/{id}`, landing on the Employees tab
in its pre-calculation empty state with **Calculate** as the page's one enabled primary action — the
Composer/Detail split Journal Entries needs (`docs/frontend/JOURNAL_ENTRIES.md → Route & Access`) simply
does not arise here, because there is no editable body of hand-typed content for a `draft` payroll run to
resolve to; a `draft` run detail and a `calculated` run detail are the same route, the same component tree,
differing only in whether `payroll_items` rows exist yet.

## Access gate

The route sits inside the `(app)` route group behind `middleware.ts`'s session-cookie check and the resolved
active-company context (`X-Company-Id`), per `FRONTEND_ARCHITECTURE.md`. A user without `payroll.read` never
sees "Payroll" in the sidebar or the Command Palette's fuzzy-search index at all (RBAC filters at the data
layer before the nav tree is constructed, per `NAVIGATION_SYSTEM.md → Permission-Aware Nav` Rule 1: "hide,
always, when the answer is 'this role never touches this'" — a Warehouse Employee's sidebar has no Payroll
entry, not a greyed-out one), and a direct hit on either URL renders the shared `403` boundary
(`# States`), never a bare Next.js default error screen. A cross-company `runId` (one that exists but
belongs to a different `company_id`) renders the shared `not-found.tsx` instead, per
`FRONTEND_ARCHITECTURE.md`'s "403 vs. 404" rule: the API returns `404`, never `403`, for a cross-tenant
record specifically so a caller can never learn that a payroll run with that id exists in a company they
cannot see — a rule this screen leans on more heavily than most, given how sensitive the mere existence of a
payroll run's aggregate figures already is.

## Permission surface on this screen

Every permission key below is defined once, authoritatively, in `docs/accounting/PAYROLL.md → Permissions`;
this table is this screen's map of key → concrete UI effect, not a redefinition. Two of QAYD's shared
foundation documents (`COMPONENT_LIBRARY.md`'s `ApprovalCard` default and `NAVIGATION_SYSTEM.md`'s own
Payroll sub-item table) use shorter illustrative permission names — `payroll.approve`, `payroll.calculate` —
as generic cross-module examples; this screen always resolves against the precise, stage-aware keys below,
never the shorthand, exactly as `docs/frontend/JOURNAL_ENTRIES.md → Route & Access` did for its own module.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `payroll.read` | The route itself; every List row's non-monetary fields (period, status, employee count, run type, pay date); the Detail page's shell and every tab except what else is listed below | Route renders the shared `403` boundary; nav item hidden |
| `payroll.salary.view` | Every `SalaryAmountCell` on the List and every tab of the Detail page — `total_gross`, `total_net`, `total_employer_cost`, every `payroll_items`/`payroll_item_lines` amount | `SalaryAmountCell` renders a redacted placeholder with a lock glyph and an explanatory tooltip in place of the figure — never a `403` on the whole page, since the run's existence and status remain visible to a `payroll.read`-only viewer |
| `payroll.run.create` | "New Payroll Run" button and `NewPayrollRunDialog` trigger, List Page Header | Button removed from the DOM, not disabled |
| `payroll.run.calculate` | "Calculate"/"Recalculate" primary action; the List's bulk "Recalculate" action for multi-branch companies | Button/action omitted |
| `payroll.run.submit` | "Submit for approval" primary action once `status = 'calculated'` | Button omitted |
| `payroll.run.approve` | Interactive Approve/Reject/Delegate on the Approvals tab's `ApprovalCard`, only at the viewer's currently-pending stage | `ApprovalCard` renders read-only (amount, requester, stage, confidence — no action row) |
| `payroll.run.post` | "Post" primary action once `status = 'approved'` | Button omitted |
| `payroll.disburse` | "Disburse" primary action once `status = 'posted'`; WPS file generation and download on the WPS & Disbursement tab | Tab content renders read-only ("awaiting Finance to disburse"); button omitted |
| `payroll.run.reverse` | "Reverse" action on a `posted`/`paid` run's overflow menu | Menu item omitted |
| `payroll.loan.create` / `payroll.loan.approve` | Referenced only — a per-employee loan-repayment line's "View loan" cross-link on the Employees tab's breakdown drawer opens `/payroll/loans/{id}`, that screen's own document | N/A on this screen beyond the cross-link, which is itself omitted absent at least `payroll.read` on the Loans screen |
| `payroll.bonus.create` | Referenced only — bonus lines appear read-only in a payroll item's breakdown; creating one is the Bonuses screen's concern | N/A |
| `payroll.payslip.view` | The Payslips tab's per-employee view/download for any employee in the run | Tab renders a "Requires `payroll.payslip.view`" empty state rather than every row's action omitted individually |
| `payroll.payslip.view.self` | Not applicable to this internal operations screen — an Employee role viewing only their own payslip uses the separate self-service `/payroll/payslips` route, out of this document's scope | — |
| `payroll.pii.view` | Incidental only — this screen never surfaces national ID/passport/bank account numbers directly; those live on the Employee Master screen | — |
| `attendance.read` | Referenced only — the Employees tab's per-employee breakdown drawer's "View attendance" cross-link | Link omitted |
| `reports.payroll.view` | "View cost report" / "View forecast" links out to the Payroll Cost and Salary Forecasting surfaces (`# AI Integration`) | Links omitted |
| `payroll.periods.manage` | `NewPayrollRunDialog`'s inline "Open a new payroll period" affordance, shown only when no open `payroll_periods` row exists for the desired month | Affordance replaced with static text: "Ask a Finance Manager to open a payroll period for this month." |

Default role grants (Owner, CEO, CFO, Finance Manager, Payroll Officer, HR Manager, Senior Accountant,
Accountant, Auditor, External Auditor, Employee, AI Agent) are exactly the table in
`docs/accounting/PAYROLL.md → Permissions`; this document does not restate role-by-role grants to avoid the
two tables drifting apart. Two rows are worth calling out concretely because they shape this screen visibly
rather than abstractly: **Payroll Officer** holds `payroll.salary.view` but the row is additionally
branch-scoped by Postgres row-level security (`docs/accounting/PAYROLL.md → Security → Salary Privacy`), so
a branch-restricted Payroll Officer's List simply never contains another branch's runs at all — there is no
row to mask, because the API never returns it; and **AI Agent** is `Read-only, masked PII` with `No` on
every write permission in the table, which is what makes it structurally impossible for any control on this
screen to resolve to "the AI approved this" — `usePermission()` for an AI-authenticated context (which never
occurs in a human's browser session, but is asserted here for completeness matching
`docs/accounting/PAYROLL.md`'s own explicit database-level `CHECK` that `payroll_run_approvals.approver_id`
must reference a non-service-account user) always resolves every mutating check to `false`.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `P` navigates to this screen
(`/payroll/runs`) from anywhere in the app. There is no screen-scoped `N` shortcut the way Journal Entries
has one for `/new` — `NewPayrollRunDialog` is reachable only via its button, since a keyboard shortcut that
opens a dialog collecting `payroll_period_id`/`run_type` is a marginal saving on an action a Payroll Officer
performs at most a handful of times per month, and `ACCESSIBILITY.md` reserves global single-letter shortcuts
for genuinely high-frequency actions.

# Layout & Regions

Both sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing page templates verbatim — no new template shape is
introduced for Payroll, per that document's "a screen never invents a sixth layout shape" rule.

## List — List Page Template

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Payroll Runs                                        [+ New Payroll Run]  │
│ 214 runs                                                                  │
├──────────────────────────────────────────────────────────────────────────┤
│ [Search…] [Status ▾] [Period ▾] [Run type ▾] [Branch ▾]  [▤ Density] [⚙] │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐│ Period      │Run type │Status              │Employees│Gross  │Net    │⋯│
│ ☐│ Jul 2026     │Regular  │● Calculated         │   87    │148,500│121,430│⋯│
│ ☐│ Jun 2026     │Regular  │● Paid               │   85    │•••••  │•••••  │⋯│
│ ☐│ Jun 2026     │Final settlement│● Posted      │    1    │  2,940│  2,610│⋯│
│ …│ …            │ …       │ …                   │  …      │  …    │  …    │⋯│
├──────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 214                                      [‹ Prev] [Next ›]│
└──────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, live run count (`meta.pagination.total`), primary action ("New Payroll Run",
  gated `payroll.run.create`, opens `NewPayrollRunDialog`).
- **Filter Bar** — search (`q`, over `payroll_periods.pay_date` proximity and `payroll_runs.id`/run number
  where the company has one configured), and four named filters: **Status** (multi-select over the ten
  `payroll_run_status` values), **Period** (`PayrollPeriodSelect` — see `# Components Used` for why this is
  a screen-specific control rather than the shared `PeriodPicker`), **Run type** (multi-select over
  `regular`/`supplementary`/`final_settlement`/`bonus_run`/`correction`), **Branch** (only rendered for
  companies with more than one `branches` row the viewer can see). A density toggle and column-visibility
  menu sit at the Filter Bar's end edge, identical in behavior to every other `DataTable`-backed list.
- **Bulk Action Bar** replaces the Filter Bar's end side once ≥1 row is selected: "3 selected · Recalculate ·
  Clear" — "Recalculate" is the only bulk action this screen offers (`payroll.run.calculate`, calling
  `POST /payroll/runs/bulk-calculate`, the documented multi-branch recalculation endpoint), gated to rows
  whose `status IN ('draft','calculated')` only; a selected row outside that set is left selected but its
  individual recalculation is skipped and reported, never silently attempted.
- **Data Region** — `DataTable` (`resource="payroll/runs"`, `paginationMode="page"`).
- **Pagination Footer** — page controls; "Showing 1–25 of 214."

The Gross/Net columns render through `SalaryAmountCell`, never the shared `AmountCell` directly — the second
List row above shows the masked rendering a Senior Accountant or Accountant sees for the exact same data a
Finance Manager sees as real figures one row above and below it, which is the whole reason this screen
exists as its own component rather than a thin re-skin of the Journal Entries list.

## Detail — a persistent lifecycle header over five tabs

```
┌─────────────────────────────────────────────────────────────────────────┐
│ ‹ Payroll Runs   Payroll Run · July 2026 · Regular      ● Calculated     │
│                                              [Submit for approval] [⋯]   │
│ Draft ✓ ─ Calculated ● ─ Officer ○ ─ Finance ○ ─ CEO ○ ─ Posted ○ ─ Paid ○│
├─ Employees │ Approvals │ WPS & Disbursement │ Payslips │ History ────────┤
│                                                                           │
│  (active tab's content — see per-tab regions below)                     │
│                                                                           │
└───────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — back-to-list link; a human-readable run label composed client-side from the period's
  `name_en`/`name_ar` and `run_type` (never a bare `#512`, since a Payroll Officer thinks in "July's regular
  run," not an internal id); `StatusPill domain="payroll_run"`; the single lifecycle action that applies
  right now (`# Interactions & Flows` resolves exactly which one); an overflow menu for Reverse (`posted`/
  `paid` only) and Export (a PDF/XLSX run summary, `reports.payroll.view`).
- **Lifecycle Stepper** — a horizontal, seven-stop stepper (`Draft → Calculated → Officer → Finance → CEO →
  Posted → Paid`) collapsing `pending_officer_approval`/`pending_finance_approval`/`pending_ceo_approval`
  into their three named stops and folding `approved` into an instantaneous tick between `CEO` and `Posted`
  (per `docs/accounting/PAYROLL.md → Approvals`, approving the CEO stage triggers posting synchronously in
  the same request cycle, so `approved` is, like Journal Entries' identical transitional state, rarely
  observed as its own stop rather than shown mid-transition). `rejected` renders as a red backward arrow from
  whichever stop rejected it, landing the stepper's active marker back on `Draft`. This is the same
  `<Stepper>` primitive `FRONTEND_ARCHITECTURE.md → The Approval Center` already uses for a multi-step
  approval chain elsewhere in the product — not a bespoke Payroll timeline component.
- **Tab/Segment Nav** — `Tabs`: **Employees** (default), **Approvals**, **WPS & Disbursement**, **Payslips**,
  **History** — five sub-views, one more than Journal Entries' three, because a payroll run genuinely carries
  more independent lifecycle facets (a calculated breakdown, a multi-stage approval, a statutory bank-file
  export, and a generated employee-facing document) than a journal entry does.
- **Employees tab** — the read-only `PayrollItemsTable` (one row per employee: name, department, gross, net,
  payment status), each row opening a `PayrollItemBreakdownSheet` drawer with the full
  earnings/deductions/statutory/employer-cost line groups (`# Components Used`). An **Anomaly Summary**
  banner sits above the table whenever the current calculation carries any unacknowledged warning (bridging
  from the Approvals tab, never duplicating its full content).
- **Approvals tab** — the three-stage `ApprovalCard` sequence (`kind="payroll_release"`), each stage rendered
  as a `Stepper`-nested card naming its `approver_role`, its `decision`, and — once decided — its `comment`;
  only the viewer's own currently-pending stage is interactive. The full **Anomaly Review** panel (every
  `warnings[]` entry from the last calculation, grouped by severity) lives here, above the approval stages,
  since acknowledging a critical flag is a precondition for the chain to begin at all (`# Interactions &
  Flows`).
- **WPS & Disbursement tab** — payment-method breakdown (bank transfer/cash/wallet/cheque counts and totals),
  the Disburse action once `posted`, and the `wps_export_batches` history once at least one batch has been
  generated: bank code, file format (`CBK_SIF`), total amount, employee count, status, and a signed download
  link.
- **Payslips tab** — one row per employee: language, generation status, `is_visible_to_employee`, and a
  "View" action opening the payslip's own breakdown in a `Sheet` (`# Components Used → PayslipViewer`).
- **History tab** — the merged `payroll_run_approvals` + `audit_logs` timeline, always rendered at the bottom
  of this tab's content, never relegated to a rail footnote, per `LAYOUT_SYSTEM.md → Detail Page Template`.

There is deliberately no Summary Rail the way Journal Entries' Detail page has one — every figure a rail
would show (total gross/net, currency, period, creator) is either already in the Page Header/Stepper or one
tab-click away in Employees, and a persistent rail displaying `SalaryAmountCell`-masked figures on every tab
regardless of which tab a `payroll.read`-only viewer is actually looking at would be redundant chrome for
exactly the audience most likely to see nothing but redacted glyphs in it.

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, or is one of the screen-specific composed
components this document introduces for this exact surface. A component gap discovered while building this
screen is proposed as an addition to `COMPONENT_LIBRARY.md`, never hand-rolled locally and left there — the
one partial exception, `SalaryAmountCell`, is called out explicitly below as a strong candidate for
promotion, since Payroll is not the only future module (Employee compensation review, HR analytics) likely
to need the identical masking contract.

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The List's server-paginated, sortable, filterable run table (`resource="payroll/runs"`) |
| `StatusPill` | `components/shared/status-pill.tsx` | Every lifecycle-status rendering, list and detail (`domain="payroll_run"`) |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every non-sensitive figure that is not a salary amount — e.g., a `wps_export_batches.total_amount` once already gated by the tab-level `payroll.disburse`/`payroll.salary.view` check, or a loan `principal_amount` reached via cross-link |
| `SalaryAmountCell` | `components/payroll/salary-amount-cell.tsx` (screen-specific — new in this doc, see below) | Every `gross_salary`/`net_salary`/`total_deductions`/`total_statutory`/`employer_cost`/`payroll_item_lines.amount` figure, list and detail |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Currency indicator beside every masked or unmasked amount |
| `PayrollPeriodSelect` | `components/payroll/payroll-period-select.tsx` (screen-specific, see below) | The List's Period filter and `NewPayrollRunDialog`'s period field |
| `NewPayrollRunDialog` | `components/payroll/new-payroll-run-dialog.tsx` (screen-specific) | The List's "New Payroll Run" action — the three-field create flow (`# Route & Access`) |
| `PayrollLifecycleStepper` | `components/payroll/payroll-lifecycle-stepper.tsx` (screen-specific, thin wrapper over the shared `<Stepper>` primitive) | The Detail page's persistent lifecycle header |
| `PayrollItemsTable` | `components/payroll/payroll-items-table.tsx` (screen-specific) | The Employees tab's read-only, per-employee table — the plain `<table>` accessibility pattern, never a grid (`# Accessibility`) |
| `PayrollItemBreakdownSheet` | `components/payroll/payroll-item-breakdown-sheet.tsx` (screen-specific) | The per-employee earnings/deductions/statutory/employer-cost drill-in, opened from a `PayrollItemsTable` row |
| `AnomalyReviewPanel` | `components/payroll/anomaly-review-panel.tsx` (screen-specific, see below) | The Approvals tab's grouped warnings list (`type`, `severity`, `message`, `ai_confidence`) sourced from the last `.../calculate` response |
| `ApprovalCard` | `components/shared/approval-card.tsx` | The three-stage approval sequence on the Approvals tab (`kind="payroll_release"`) |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Every AI-sourced figure on this screen: anomaly/compliance warnings, the Payroll Explanation `Sheet` |
| `PayslipViewer` | `components/payroll/payslip-viewer.tsx` (screen-specific) | The Payslips tab's per-employee payslip breakdown, opened in a `Sheet` |
| `WpsExportBatchCard` | `components/payroll/wps-export-batch-card.tsx` (screen-specific) | The WPS & Disbursement tab's generated-batch history |
| `Tabs` | `components/ui/tabs.tsx` | Detail page's five-tab segmentation |
| `Sheet` | `components/ui/sheet.tsx` | `PayrollItemBreakdownSheet`, `PayslipViewer`, the AI reasoning drill-in, the mobile row-detail drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | `NewPayrollRunDialog`; Post/Disburse/Reverse confirmations; Reject and anomaly-acknowledgment reason collection |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | The Detail page's overflow menu (Reverse, Export) |
| `Badge` | `components/ui/badge.tsx` | Run-type chips in the List's Filter Bar and table |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, mapped from the API envelope |

## `SalaryAmountCell`

The single most consequential component this document introduces. `docs/accounting/PAYROLL.md → Security →
Salary Privacy` establishes that salary visibility is enforced independently of `payroll.read`, at the API
response level — a caller lacking `payroll.salary.view` never has the real figure sent to their browser in
the first place, exactly matching `FRONTEND_ARCHITECTURE.md` Principle 4's rule that hiding is a UX courtesy
while the actual boundary is server-side. `SalaryAmountCell`'s entire job is therefore to render *whatever
the API actually returned* correctly: a caller with the permission receives a normal `NUMERIC(19,4)` string
and sees a normal `AmountCell`; a caller without it receives `null` for that field, and `SalaryAmountCell` is
this screen's one choke point for turning that `null` into a redacted, explained placeholder — never a
component that independently decides, client-side, to hide a number it was actually sent.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `amount` | `string \| null` | yes | `null` means "the API withheld this field for the current caller," and must never be treated as `"0.0000"` — a real zero and a masked figure are rendered completely differently (see Edge Cases). |
| `currencyCode` | `string` | yes | Passed straight through to `AmountCell` when unmasked. |
| `mode` | `'debit' \| 'credit' \| 'signed' \| 'plain'` | no, default `'plain'` | Identical semantics to `AmountCell`'s own prop when unmasked; ignored while masked. |
| `emphasis` | `'default' \| 'strong' \| 'muted'` | no | Passed through when unmasked; the masked glyph always renders at `'muted'` weight regardless of what is requested, since a redacted figure should never visually compete with a real total on the same table. |
| `requiredPermission` | `string` | no, default `'payroll.salary.view'` | Named explicitly (rather than hard-coded) so the same component serves any future screen with an analogous masked-figure need, per this component's promotion candidacy above. |

**Implementation**

```tsx
// components/payroll/salary-amount-cell.tsx
'use client';

import { AmountCell, type AmountCellProps } from '@/components/accounting/amount-cell';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { VisuallyHidden } from '@/components/ui/visually-hidden';
import { usePermission } from '@/hooks/use-permission';
import { Lock } from 'lucide-react';
import { useTranslations } from 'next-intl';

interface SalaryAmountCellProps extends Omit<AmountCellProps, 'amount'> {
  amount: string | null;
  requiredPermission?: string;
}

export function SalaryAmountCell({
  amount, currencyCode, mode = 'plain', emphasis, requiredPermission = 'payroll.salary.view',
}: SalaryAmountCellProps) {
  const canView = usePermission(requiredPermission);
  const t = useTranslations('payroll.salaryAmountCell');

  // `amount === null` is the API's own signal that this caller was never sent the real figure —
  // this branch renders regardless of `canView`, because a stale client-side permission snapshot
  // must never invent a number the server did not actually provide (FRONTEND_ARCHITECTURE.md
  // Principle 4: hiding is a courtesy, the boundary is server-side; this is that rule applied to
  // data rather than to a control).
  if (amount === null || !canView) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex items-center gap-1 text-ink-500 select-none" dir="ltr">
            <Lock className="size-3" aria-hidden />
            <span aria-hidden>•••.•••</span>
            <VisuallyHidden>{t('a11y.restricted')}</VisuallyHidden>
          </span>
        </TooltipTrigger>
        <TooltipContent>{t('tooltip', { permission: requiredPermission })}</TooltipContent>
      </Tooltip>
    );
  }

  return <AmountCell amount={amount} currencyCode={currencyCode} mode={mode} emphasis={emphasis} />;
}
```

**Usage**

```tsx
<SalaryAmountCell amount={run.total_net} currencyCode={run.currency_code} emphasis="strong" />
```

Two decisions carry real weight here. First, the redacted glyph `•••.•••` is a fixed-width placeholder that
never resembles a plausible currency figure at a glance — it is deliberately not, say, `"0.000"` or a
blurred version of real digits, so a Finance Manager glancing over a Senior Accountant's shoulder cannot
mistake "masked" for "this run cost nothing." Second, the component checks `canView` **in addition to**
`amount === null` rather than trusting either signal alone: if a permission is revoked mid-session
(`# Edge Cases`) before the client's cached `payroll_runs` query is invalidated, the stale cache might still
hold a real figure fetched earlier in the session, and `!canView` forces the redacted rendering even though
`amount` is technically a non-null string sitting in the TanStack Query cache — the same "never trust a
stale permission snapshot" discipline `NAVIGATION_SYSTEM.md → Command Palette` and
`docs/frontend/JOURNAL_ENTRIES.md → States` both already apply to controls, applied here to previously-fetched
data instead.

## `PayrollPeriodSelect`

`PeriodPicker`'s `mode="fiscal_period"` is explicitly scoped to the `fiscal_years`/`fiscal_periods` calendar
(`COMPONENT_LIBRARY.md → PeriodPicker`) — a company's accounting close calendar, not its payroll calendar.
Payroll runs are scoped to `payroll_periods`, a distinct table with its own `frequency`
(`monthly`/`biweekly`/`weekly`/`semi_monthly`) and `pay_date`, per `docs/accounting/PAYROLL.md → Payroll
Processing → Payroll Period`. Reusing `PeriodPicker` here — the way `docs/frontend/JOURNAL_ENTRIES.md` reuses
it verbatim for its own fiscal-period filter — would silently conflate two calendars that are allowed to
diverge (a company can close its books monthly while paying biweekly). `PayrollPeriodSelect` is therefore a
deliberate, explicitly-justified departure: a thin `Select` over `GET /api/v1/payroll/periods`, grouped by
`status` (`open`/`closed`) exactly as `PeriodPicker`'s own fiscal-period grouping does, so the visual
vocabulary stays familiar even though the underlying data source does not.

## `AnomalyReviewPanel`

Renders the `warnings[]` array `POST /payroll/runs/{id}/calculate` returns (`docs/accounting/PAYROLL.md →
API → Example: Calculate a Payroll Run`), grouped by `severity` (`critical` first, then `warning`, then
`info`), each entry showing the affected employee's name (resolved client-side from the already-fetched
`PayrollItemsTable` rows — no extra request), `type` (`anomaly`/`compliance`/`fraud`), `message`, and a
`ConfidenceBadge` for `ai_confidence`. A `critical` entry additionally renders an inline "Acknowledge" action
requiring a typed reason before Submit unlocks (`# Interactions & Flows`); `warning` and `info` entries are
informational only and never block anything, matching `docs/accounting/PAYROLL.md`'s own severity semantics
exactly ("Confidence < 0.7 always surfaced; ≥ 0.95 still requires Payroll Officer acknowledgment before
Submit" is the Payroll Validation row's rule, and "critical flags require an explicit override reason" is the
Anomaly Detection row's).

```tsx
// components/payroll/anomaly-review-panel.tsx
'use client';

import { useState } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { AlertTriangle, ShieldAlert, Info } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { cn } from '@/lib/utils';

const SEVERITY_ICON = { critical: ShieldAlert, warning: AlertTriangle, info: Info } as const;
const SEVERITY_TONE = { critical: 'text-danger', warning: 'text-warning', info: 'text-ink-500' } as const;

export function AnomalyReviewPanel({
  warnings, employeeNamesById, acknowledged, onAcknowledge,
}: AnomalyReviewPanelProps) {
  const t = useTranslations('payroll.anomalyReview');
  const [openReasonFor, setOpenReasonFor] = useState<number | null>(null);
  const [reason, setReason] = useState('');

  const bySeverity = ['critical', 'warning', 'info'] as const;

  return (
    <div className="space-y-3">
      {bySeverity.map((severity) => {
        const group = warnings.filter((w) => w.severity === severity);
        if (group.length === 0) return null;
        const Icon = SEVERITY_ICON[severity];
        return (
          <Card key={severity} padding="md" className="space-y-2">
            <h3 className={cn('flex items-center gap-1.5 text-caption font-medium', SEVERITY_TONE[severity])}>
              <Icon className="size-4" aria-hidden /> {t(`severity.${severity}`)} ({group.length})
            </h3>
            {group.map((w, i) => (
              <div key={i} className="rounded-md border border-ink-150 p-3 space-y-1.5">
                <div className="flex items-start justify-between gap-2">
                  <p className="text-body text-ink-950">
                    <span className="font-medium">{employeeNamesById[w.employee_id]}</span> — {w.message}
                  </p>
                  <ConfidenceBadge confidence={normalizeConfidence(w.ai_confidence, 'fraction')} size="sm" />
                </div>
                {severity === 'critical' && !acknowledged.has(`${w.employee_id}:${w.type}`) && (
                  <>
                    <Button size="sm" variant="outline" onClick={() => setOpenReasonFor(i)}>
                      {t('acknowledge')}
                    </Button>
                    {openReasonFor === i && (
                      <div className="space-y-2 pt-1">
                        <Textarea
                          value={reason}
                          onChange={(e) => setReason(e.target.value)}
                          placeholder={t('reasonPlaceholder')}
                          aria-label={t('reasonPlaceholder')}
                        />
                        <Button
                          size="sm" disabled={reason.trim().length === 0}
                          onClick={() => { onAcknowledge(w, reason); setReason(''); setOpenReasonFor(null); }}
                        >
                          {t('confirmAcknowledge')}
                        </Button>
                      </div>
                    )}
                  </>
                )}
                {severity === 'critical' && acknowledged.has(`${w.employee_id}:${w.type}`) && (
                  <p className="text-caption text-ink-500">{t('acknowledged')}</p>
                )}
              </div>
            ))}
          </Card>
        );
      })}
    </div>
  );
}
```

`onAcknowledge`'s collected `(warning, reason)` pairs are held in the Detail page's local mutation state and
attached to the eventual `POST .../submit` call; the Payroll Officer's own subsequent stage approval on the
Approvals tab pre-fills its `comment` field from the same text, so the human never re-types a justification
they already gave once, and the reason ultimately lands exactly where
`docs/accounting/PAYROLL.md → Edge Cases` says it must: permanently in `payroll_run_approvals.override_reason`
at the `payroll_officer` stage.

## `PayrollItemsTable`, `PayrollItemBreakdownSheet`

`PayrollItemsTable` is a read-only, plain `<table>` — real `scope="col"` headers, sortable columns as real
`<button>`s — never the ARIA `grid` pattern: `ACCESSIBILITY.md → Data Tables Accessibility` names exactly
two surfaces in the entire platform that warrant the heavier grid pattern, "the Journal Entry line editor,
and the Bank Reconciliation matching grid," and this screen deliberately does not become a third. A payroll
item's figures are computed, not manually keyed in place one cell at a time, so there is no cell-to-cell
arrow-key navigation need to justify the grid's added complexity — the same reasoning
`docs/frontend/JOURNAL_ENTRIES.md → Components Used → JournalLinesTable` gives for its own read-only lines
table. Clicking a row (or its explicit "View breakdown" action) opens `PayrollItemBreakdownSheet`, which
renders that employee's `payroll_item_lines` grouped into the four `component_category` sections
`docs/accounting/PAYROLL.md → Database Design` defines — **Earnings**, **Deductions**, **Statutory
Withholdings**, and **Employer Contributions** (informational, explicitly not netted against pay, per
`docs/accounting/PAYROLL.md → Payroll Components → Insurance`: the employer's PIFSS share "appears on the
payslip's informational 'employer contributions' section, not the 'deductions' section") — each line showing
its `salary_component` name (bilingual), `quantity`/`rate` where applicable, and its `SalaryAmountCell`
amount, with a running Net Salary total at the foot of the Deductions/Statutory groups.

## `PayslipViewer`

Renders one `payslips` row's breakdown exactly matching `docs/accounting/PAYROLL.md → API → Example: Get a
Payslip`'s worked response shape (`period`, `gross_salary`, `net_salary`, `lines[]`, `download_url`), with a
language toggle (`en`/`ar`) that switches which `payslips` row (of the `uq_payslip_item_lang` pair) is being
viewed rather than machine-translating one row client-side — the two language versions are independently
generated server-side, and this component never assumes one can stand in for the other. "Download" opens
`download_url` (a time-limited signed Cloudflare R2 URL) directly; the viewer never proxies or caches the PDF
bytes itself.

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/PAYROLL.md → API`'s table, annotated with which hook and query key
this screen wires it to. All are versioned under `/api/v1/payroll/`, carry `X-Company-Id`, and return the
standard envelope.

| Method & Path | Permission | Hook | Query key |
|---|---|---|---|
| `GET /payroll/periods` | `.read` | `usePayrollPeriods()` | `payrollKeys.periods()` |
| `POST /payroll/periods` | `.periods.manage` | `useCreatePayrollPeriod()` | invalidates `payrollKeys.periods()` |
| `POST /payroll/runs` | `.run.create` | `useCreatePayrollRun()` | invalidates `payrollRunKeys.lists()` |
| `GET /payroll/runs` (list) | `.read` | `usePayrollRuns(filters)` | `payrollRunKeys.list(filters)` |
| `GET /payroll/runs/{id}` | `.read` | `usePayrollRun(id)` | `payrollRunKeys.detail(id)` |
| `POST /payroll/runs/{id}/calculate` | `.run.calculate` | `useCalculatePayrollRun()` | pessimistic — see `## Mutations`; seeds `payrollRunKeys.detail(id)` and `payrollRunKeys.items(id)` on success |
| `GET /payroll/runs/{id}/items` | `.read` | `usePayrollRunItems(id)` | `payrollRunKeys.items(id)` |
| `POST /payroll/runs/{id}/submit` | `.run.submit` | `useSubmitPayrollRun()` | pessimistic, optimistic status patch on `2xx` only |
| `POST /payroll/runs/{id}/approve` | `.run.approve` | `useApprovePayrollRun()` | pessimistic |
| `POST /payroll/runs/{id}/reject` | `.run.approve` | `useRejectPayrollRun()` | pessimistic |
| `POST /payroll/runs/{id}/post` | `.run.post` | `usePostPayrollRun()` | pessimistic |
| `POST /payroll/runs/{id}/disburse` | `.disburse` | `useDisbursePayrollRun()` | pessimistic; invalidates `payrollRunKeys.wpsBatches(id)` |
| `POST /payroll/runs/{id}/reverse` | `.run.reverse` | `useReversePayrollRun()` | invalidates `detail(id)` + `detail(newDraftId)` + `lists()` |
| `GET /payroll/payslips/{id}` | `.payslip.view` | `usePayslip(id)` | `payrollKeys.payslip(id)` |
| `GET /payroll/employees/{id}/payslips` | `.payslip.view` / `.payslip.view.self` | `useEmployeePayslips(employeeId)` | `payrollKeys.employeePayslips(employeeId)` (Payslips tab, one call per distinct employee row expanded) |
| `POST /payroll/runs/bulk-calculate` | `.run.calculate` | `useBulkCalculatePayrollRuns()` | invalidates `payrollRunKeys.lists()` on settle |
| `GET /payroll/reports/summary` | `reports.payroll.view` | `usePayrollSummaryReport()` | not embedded in this screen — cross-linked only |
| `GET /payroll/reports/cost` | `reports.payroll.view` | `usePayrollCostReport()` | not embedded in this screen — cross-linked only |

## Query key factory

```ts
// lib/query/keys.ts (excerpt)
export const payrollRunKeys = {
  all: ['payroll', 'runs'] as const,
  lists: () => [...payrollRunKeys.all, 'list'] as const,
  list: (filters: PayrollRunFilters) => [...payrollRunKeys.lists(), filters] as const,
  details: () => [...payrollRunKeys.all, 'detail'] as const,
  detail: (id: number | string) => [...payrollRunKeys.details(), id] as const,
  items: (id: number | string) => [...payrollRunKeys.detail(id), 'items'] as const,
  wpsBatches: (id: number | string) => [...payrollRunKeys.detail(id), 'wps-batches'] as const,
  history: (id: number | string) => [...payrollRunKeys.detail(id), 'history'] as const,
};

export const payrollKeys = {
  periods: () => ['payroll', 'periods'] as const,
  payslip: (id: number | string) => ['payroll', 'payslips', id] as const,
  employeePayslips: (employeeId: number | string) => ['payroll', 'employees', employeeId, 'payslips'] as const,
};
```

`PayrollRunFilters` mirrors `docs/accounting/PAYROLL.md`'s own resource shape: `status` (array, `in`),
`payroll_period_id`, `run_type` (array), `branch_id`, `q`, `sort`. The List's four named filters map directly:
**Status** → `status`, **Period** → `payroll_period_id` (resolved from whichever row `PayrollPeriodSelect`
chose), **Run type** → `run_type`, **Branch** → `branch_id`.

## Client state ownership

Consistent with `FRONTEND_ARCHITECTURE.md → State Management`:

| State | Owner |
|---|---|
| The run list, one run's detail, its items, its WPS batches, its history | TanStack Query cache, keyed as above |
| `NewPayrollRunDialog`'s in-progress field values | Local `useState` (three fields — not substantial enough to warrant React Hook Form, unlike the Journal Entry Composer) |
| The current calculation's `warnings[]` and which critical flags have been acknowledged this session | Local component state on the Detail page, seeded from the last `.../calculate` response and from `GET /runs/{id}` echoing the same array for the run's current calculated state (see `# Interactions & Flows` — the calculation engine is idempotent and re-runnable per `docs/accounting/PAYROLL.md → Calculation Engine`, so warnings are a function of the run's current state, not of the specific request that produced them, and this screen treats them accordingly rather than inventing a separate persistence layer for something the API already re-derives on read) |
| List density, column visibility, saved filter presets | Zustand (`useDensityStore`, per `LAYOUT_SYSTEM.md → Density Modes`) + `users.settings` sync |
| Active Detail tab | URL search param (`?tab=approvals`), so a direct link to "the Approvals tab of July's run" is shareable — never component-local state alone |
| Selected rows for the List's bulk Recalculate | Local component state inside `DataTable`, cleared on navigation |
| A mutation's `Idempotency-Key`, keyed to the logical action instance (one key per Calculate click, one per Submit click, etc.) | `sessionStorage`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` |

## Mutations — pessimistic almost everywhere

`docs/accounting/PAYROLL.md → Purpose` and `FRONTEND_ARCHITECTURE.md` Principle 10 (whose own worked examples
name "approving a payroll run" and "posting a journal entry" side by side as the canonical financial-mutation
case) agree completely here: **every mutation on this screen that changes the run's lifecycle state is
pessimistic** — Calculate, Submit, Approve, Reject, Post, Disburse, Reverse all wait for the server's `2xx`
before the UI treats them as done, and all render a confirming dialog first except Submit (justified below).
There is no reversible, low-stakes action on this screen analogous to Journal Entries' "Archive" — even a
`draft` run's Recalculate is pessimistic, since it discards and regenerates every `payroll_items` row
server-side and the client has no safe optimistic guess for what 87 employees' new totals will be.

```ts
// hooks/payroll/use-payroll-runs.ts (excerpt)
export function useCalculatePayrollRun(runId: number) {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey(`payroll-run-calculate-${runId}`);
  return useMutation({
    // No onMutate — recalculating regenerates every payroll_items/payroll_item_lines row and can
    // legitimately take real time for a large company (docs/accounting/PAYROLL.md → Performance's
    // chunked, queued calculation), so there is nothing safe to guess optimistically.
    mutationFn: () => api.post(`/payroll/runs/${runId}/calculate`, {}, idempotencyKey),
    onSuccess: (result: PayrollCalculationResult) => {
      qc.setQueryData(payrollRunKeys.detail(runId), (old?: PayrollRun) =>
        old ? { ...old, status: result.status, total_gross: result.total_gross,
                total_net: result.total_net, total_employer_cost: result.total_employer_cost,
                employee_count: result.employee_count } : old);
      qc.invalidateQueries({ queryKey: payrollRunKeys.items(runId) });
      qc.invalidateQueries({ queryKey: payrollRunKeys.lists() });
    },
  });
}

export function useDisbursePayrollRun(runId: number) {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey(`payroll-run-disburse-${runId}`);
  return useMutation({
    // Disburse is the single most consequential click on this screen — real money moves as a
    // direct consequence — so it is the platform's own named example of "waits for the server's
    // 2xx before the UI treats it as done," with no exception.
    mutationFn: () => api.post(`/payroll/runs/${runId}/disburse`, {}, idempotencyKey),
    onSuccess: (run: PayrollRun) => {
      qc.setQueryData(payrollRunKeys.detail(runId), run);
      qc.invalidateQueries({ queryKey: payrollRunKeys.wpsBatches(runId) });
      qc.invalidateQueries({ queryKey: payrollRunKeys.lists() });
    },
  });
}
```

`useSubmitPayrollRun`, `useApprovePayrollRun`, `useRejectPayrollRun`, `usePostPayrollRun`, and
`useReversePayrollRun` follow the identical shape — no `onMutate`, a client-generated `Idempotency-Key` per
logical attempt, and (Submit excepted, per `# Interactions & Flows`) a confirming `Dialog`/`AlertDialog` in
front of every one of them, each restating the run's period, employee count, and (where `payroll.salary.view`
allows) net total before it fires — a deliberate tightening of `ApprovalCard`'s own generic contract,
identical in spirit and justification to `docs/frontend/BANKING.md → Interactions & Flows`'s equivalent
tightening for `kind="bank_transfer"` cards: a payroll run is, by construction, always a company-scale sum,
never a trivially-small one the way an individual journal entry can be, so there is no size-based case for
leaving any of its three approval stages unconfirmed.

## Realtime

The List and the Detail page both subscribe to the company's private Payroll channel via Echo — the exact
channel name `docs/accounting/PAYROLL.md → Notifications` already specifies (`payroll.{company_id}`), never a
parallel channel this document invents:

```ts
// hooks/payroll/use-payroll-run-realtime.ts
'use client';
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/realtime/echo';
import { payrollRunKeys } from '@/lib/query/keys';

export function usePayrollRunRealtime(companyId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`payroll.${companyId}`);

    // Matches docs/accounting/PAYROLL.md → Notifications' exact event list: stage-transition
    // pushes, posted/paid, loan decisions, and WPS batch status — this hook does not invent a
    // parallel set of event names.
    for (const event of [
      '.payroll_run.calculated', '.payroll_run.submitted', '.payroll_run.approved',
      '.payroll_run.rejected', '.payroll_run.posted', '.payroll_run.paid', '.payroll_run.reversed',
      '.wps_export_batch.submitted', '.wps_export_batch.acknowledged', '.wps_export_batch.failed',
    ]) {
      channel.listen(event, (e: { payroll_run_id: number }) => {
        queryClient.invalidateQueries({ queryKey: payrollRunKeys.detail(e.payroll_run_id) });
        queryClient.invalidateQueries({ queryKey: payrollRunKeys.lists(), refetchType: 'inactive' });
      });
    }

    // A critical AI flag (fraud/compliance) notifies the Auditor role independently of the
    // approval chain (docs/accounting/PAYROLL.md → AI Responsibilities → Fraud Detection) — this
    // screen only needs to refresh the Anomaly Review panel if it is currently open on this run,
    // never a bespoke Auditor-only inbox this document would have to re-specify.
    channel.listen('.payroll_run.ai_flag_raised', (e: { payroll_run_id: number }) => {
      queryClient.invalidateQueries({ queryKey: payrollRunKeys.detail(e.payroll_run_id) });
    });

    return () => { echo.leave(`payroll.${companyId}`); };
  }, [companyId, queryClient]);
}
```

Every event above only invalidates — never patches a cached object surgically the way
`docs/frontend/JOURNAL_ENTRIES.md`'s realtime hook does for a single high-frequency screen. Payroll runs are
comparatively low in event volume (a company issues one run per period, not dozens of journal entries per
hour), so the added complexity of surgical `setQueryData` patching buys little here; a refetch-on-invalidate
is fast enough and simpler to reason about for a screen this infrequently mutated.

## AI agents feeding this screen

Per `docs/accounting/PAYROLL.md → AI Responsibilities`, all eight agent behaviors surface here, each through
the ordinary calculate/read API — never a bespoke AI endpoint this screen has to special-case:

| Agent | What appears on this screen | Autonomy |
|---|---|---|
| Payroll Validation | Line-by-line validation entries in `AnomalyReviewPanel` (missing components, formula mismatches, pro-ration errors) | Suggest-only |
| Anomaly Detection | Severity-tiered flags in `AnomalyReviewPanel`; a `critical` flag blocks Submit until acknowledged | Suggest-only, blocking on `critical` |
| Fraud Detection | A `danger`-toned entry in `AnomalyReviewPanel` (ghost employee, duplicate bank account/IBAN, unmatched commission, loan-then-resign pattern); independently notifies the Auditor role via `notifications`/Realtime regardless of what happens on this screen | Suggest-only; escalates out-of-band, never adds an approval stage the way a Journal Entry fraud flag does |
| Compliance Checks | `type: "compliance"` entries citing the specific Kuwait Labour Law article in `message`; `critical` compliance flags block Submit exactly like Anomaly Detection's | Suggest-only, blocking on `critical` |
| Salary Forecasting | Not embedded on this screen — a "View payroll forecast" link (`reports.payroll.view`) out to the Payroll Cost report, avoiding a second copy of that content inline (mirroring `docs/frontend/BANKING.md`'s identical "View full report" precedent for its own Treasury panel) | Suggest-only (read/report) |
| Overtime Analysis | Same treatment as Salary Forecasting — cross-linked, not duplicated | Suggest-only |
| Leave Prediction | Same treatment — cross-linked, not duplicated | Suggest-only |
| Payroll Explanation | The Payslips tab's "Explain this payslip" trigger, opening a bilingual reasoning `Sheet` on request | Auto-generate on request (read-only, no state change) |

# Interactions & Flows

**Creating a run.** "New Payroll Run" (`payroll.run.create`) opens `NewPayrollRunDialog`: `PayrollPeriodSelect`
(defaulting to the earliest `open` period with no existing `regular` run yet), a `run_type` select (defaulting
`regular`), and — only for multi-branch companies — a `branch_id` select. Submitting calls
`POST /payroll/runs`, which per `docs/accounting/PAYROLL.md → API`'s worked example returns the new row
immediately in `status: 'draft'` with every total at `0.0000`; the dialog closes and the app navigates to
`/payroll/runs/{id}`, landing on the Employees tab's pre-calculation empty state (`# States`) with
**Calculate** as the one enabled primary action. There is no balance check, no line entry, nothing else to
fill in — the entire "compose a payroll run" act is these three fields, which is the structural reason this
flow is a `Dialog` and not a route (`# Route & Access`).

**Calculating.** Clicking **Calculate** immediately disables the button and shows an indeterminate
"Calculating payroll for this period…" inline state — never a determinate progress bar, since no job-id or
polling endpoint is documented for this action and `docs/accounting/PAYROLL.md → Performance` describes the
calculation as internally chunked and queued but externally synchronous (the worked
`POST .../runs/512/calculate` example returns the completed totals directly in its response body). For a
company with several thousand employees this request can legitimately take longer than QAYD's default
request timeout; the client sets a generous, calculation-specific timeout rather than the platform default,
and if it is still exceeded shows "This is taking longer than usual for a large payroll — it's still running"
rather than a hard failure, since the underlying job continues server-side regardless of what the browser's
own connection does. On success, the response's `data.warnings[]` (if any) populate `AnomalyReviewPanel` on
the Approvals tab and a compact "2 warnings, 1 critical" badge appears on both the Detail page's header and
the corresponding List row. **Recalculating** a `draft` or `calculated` run behaves identically and is
explicitly non-destructive to review in progress — per `docs/accounting/PAYROLL.md → Calculation Engine`,
the engine deletes and regenerates `payroll_items`/`payroll_item_lines` rather than appending, so a Payroll
Officer who approves a late leave request or corrects an attendance record mid-review can simply click
Recalculate again before Submit, and the previous calculation's now-stale `AnomalyReviewPanel` state is
discarded and replaced by the new response's own `warnings[]`, never merged with the old set.

**Employees excluded from calculation.** A response whose `employee_count` is lower than the company's total
active headcount for that branch/period surfaces a dismissible banner above `PayrollItemsTable`: "N employees
were not included — not payroll-ready," reflecting `docs/accounting/PAYROLL.md → Employee Lifecycle →
Onboarding`'s `payroll_ready` gate (missing bank details, missing a base salary component, or an unrecognized
`country_code`). The banner links to the Employee Master screen's own readiness checklist (a separate
document) rather than attempting to resolve the gap on this screen, which owns calculation, not onboarding.

**Reviewing and acknowledging anomalies.** `AnomalyReviewPanel` (`# Components Used`) groups every warning by
severity. A `critical`-severity entry's "Acknowledge" action requires a typed reason before it can be
confirmed — mirroring the mandatory-reason contract `ApprovalCard`'s own Reject flow and `AIProposalPanel`'s
own Dismiss flow already establish platform-wide, applied here to an acknowledgment instead of a rejection.
**Submit for approval** stays disabled, with an explicit `aria-describedby` reason ("2 critical flags still
need acknowledgment"), until every `critical` entry has one; `warning`/`info` entries never block anything.
This is the one meaningful gate this screen enforces client-side ahead of the server, and — per
`FRONTEND_ARCHITECTURE.md` Principle 1 — it is purely a friendly, avoid-a-wasted-round-trip check: the actual
enforcement is `docs/accounting/PAYROLL.md → AI Responsibilities`'s own stated rule that a critical flag
"blocks Submit until acknowledged," re-checked server-side regardless of what the client believes it already
confirmed.

**Submitting.** Unlike Post/Disburse/Reverse, **Submit does not open a confirming dialog** — the
just-completed calculation review and any required anomaly acknowledgments already are the affirmative,
already-visible confirmation `FRONTEND_ARCHITECTURE.md` Principle 10 asks for, and a second modal stacked on
top of an already-deliberate multi-step review would be friction without a corresponding safety benefit,
identical in reasoning to `docs/frontend/JOURNAL_ENTRIES.md → Interactions & Flows`'s identical exemption for
its own Submit. `POST .../submit` moves `calculated → pending_officer_approval` unconditionally — there is no
approval-exempt direct-post path for any payroll run, regardless of amount, per `docs/accounting/PAYROLL.md →
Purpose`'s unconditional three-stage rule (`# Purpose`), so this screen's Submit button never has to
resolve two different behaviors the way the Journal Entry Composer's primary button does.

**Approving at each stage.** The Approvals tab renders all three stages as a sequence of `ApprovalCard`s
(`kind="payroll_release"`), matching the currently-pending stage against the viewer's role
(`payroll_officer`/`finance_manager`/`ceo`); only that one card is interactive, exactly mirroring
`FRONTEND_ARCHITECTURE.md → The Approval Center`'s out-of-order-approval prevention ("a CFO cannot accidentally
approve step 1 out of order before a Senior Accountant has"). Every Approve on this screen — at all three
stages, not only the terminal one — opens a confirming dialog restating the run's period, employee count, and
(where `payroll.salary.view` allows) net total before it submits, a deliberate tightening of `ApprovalCard`'s
generic contract identical in justification to `docs/frontend/BANKING.md`'s own tightening for
`kind="bank_transfer"`: every stage here gates the same company-scale sum, so there is no low-stakes stage to
leave unconfirmed the way a small Journal Entry might be. Rejecting at any stage requires a typed reason
(`ApprovalCard`'s own contract) and returns the run fully to `draft` — per
`docs/accounting/PAYROLL.md → Security → Approval Workflow`, "there is no 'resume from where it was rejected'"
— so the rejection reason is shown prominently on the now-`draft` run, and Recalculate/Submit must both be
run again from scratch. Approving the **CEO** stage triggers Posting synchronously in the same request cycle
(`docs/accounting/PAYROLL.md → Approvals`); the UI simply reflects whatever terminal status the response
actually contains (ordinarily `posted` already) rather than rendering an intermediate "approved, about to
post" state the backend does not meaningfully expose, identical to Journal Entries' equivalent transition.

**Posting.** For the rare case where the response to the CEO approval has not already resolved to `posted`
(a transient `approved` state briefly observed), the Detail page's lifecycle action reads "Post" directly,
gated `payroll.run.post`. Clicking it opens a confirming `Dialog` restating the amount and employee count
before firing `usePostPayrollRun()`, per Principle 10's rule applying even to an action with no separate
approval level left to clear.

**Disbursing.** Once `posted`, the WPS & Disbursement tab's primary action reads "Disburse"
(`payroll.disburse`), opening a confirming `Dialog` naming the total net amount, employee count, and payment
method breakdown ("You are about to disburse KWD 121,430.500 net pay to 87 employees — 82 via bank transfer
(WPS), 3 by cheque, 2 by cash"). Confirming calls `POST .../disburse`; on success, the tab immediately shows
the newly generated `wps_export_batches` row (`status: 'generated'`) via `WpsExportBatchCard`, with a
"Download SIF file" link resolving the batch's signed `file_attachment_id` URL, and each `bank_transfer`
`payroll_items` row's `payment_status` flips from `pending` toward `paid` once Finance marks the batch
submitted/acknowledged (there is no bank-API callback assumed here beyond what
`docs/accounting/PAYROLL.md → Payment Methods → Bank Transfer` documents — a manual portal upload is the
default workflow, and this screen's own affordance for progressing `wps_export_batches.status` is a plain
"Mark as submitted" / "Mark as acknowledged" action for Finance, not an automated integration this document
would be inventing). A `cash`/`cheque` line's disbursement is recorded through its own
`payroll_cash_disbursements`/`payroll_cheque_issuances` cross-link, out of this screen's direct editing scope
but visible as a status chip per row.

**A WPS line fails.** Per `docs/accounting/PAYROLL.md → Edge Cases`, a bank-rejected line sets that one
`payroll_items.payment_status = 'failed'` without rolling back the rest of the batch. `PayrollItemsTable`
renders a `failed` row with a `danger`-toned status chip and a scoped "Re-issue payment" row action
(`payroll.disburse`) that opens a small `Dialog` for corrected bank details and generates a fresh, smaller
`wps_export_batches` entry for just that employee — never re-touching the successful lines.

**Reversing.** "Reverse" (`payroll.run.reverse`, overflow menu, `posted`/`paid` only) opens a `Dialog`
collecting a mandatory reason. Submitting calls `POST .../reverse`; the response links both records
(`payroll_runs.reversed_run_id` on the original, a fresh `draft` run pre-populated with corrected inputs on
the other end), and the Detail page immediately shows a "Reversed by [new draft run] →" cross-link once the
mutation settles. Reversal is always whole-run — there is no per-employee partial-reversal UI, matching the
one documented endpoint's shape; a single employee's error inside an otherwise-correct paid run is instead
corrected via an `arrears` component in the *next* run (`docs/accounting/PAYROLL.md → Corrections`), never a
same-run edit this screen would have no way to expose safely.

**Working the Payslips tab.** Each row shows generation status (`payslips` rows exist per language once the
run reaches `posted`, per `docs/accounting/PAYROLL.md → Payslip Generation`) and `is_visible_to_employee`,
which only flips `true` once that employee's own `payment_status` reaches `paid` — a payslip can therefore be
fully generated and viewable by HR/Finance on this screen while still showing "Not yet released to employee"
for the employee's own self-service view, and this screen never implies otherwise. "View" opens
`PayslipViewer` in a `Sheet`; "Explain" (`# AI Integration`) opens the bilingual AI reasoning `Sheet` for that
same payslip on request.

**Bulk recalculate from the List.** Selecting ≥1 `draft`/`calculated` row swaps the Filter Bar for the Bulk
Action Bar's single "Recalculate" action, firing one `POST /payroll/runs/bulk-calculate` call (the documented
multi-branch endpoint) rather than N individual calls, and reports the aggregate result — "3 runs
recalculated, 1 skipped (not in a recalculable status)" — never silently dropping the skipped row without
explanation.

# AI Integration

AI touches this screen at exactly three points, never a separate "AI tab": **validating** (the calculation
response's `warnings[]`, rendered in `AnomalyReviewPanel`), **explaining** (the Payslips tab's on-request
reasoning `Sheet`), and **escalating** (Fraud Detection's independent, out-of-band Auditor notification). Per
the platform's non-negotiable rule, no AI output on this screen ever calculates, submits, approves, posts, or
disburses anything by itself — there is no "Do it" affordance anywhere on this screen, structurally, because
every one of `docs/accounting/PAYROLL.md → AI Responsibilities`'s eight agent rows is `Suggest-only`; the
`AIProposalPanel`/three-button pattern `FRONTEND_ARCHITECTURE.md → AI Integration Layer` describes for
actionable recommendations elsewhere in the product simply has no instance on this screen, because Payroll
has no agent whose `autonomy` is ever `auto`.

**Payroll Validation and Anomaly Detection.** Both land in `AnomalyReviewPanel`, distinguished only by
`type` (`anomaly` vs. a validation-shaped message) — the panel does not visually separate them into two
sub-panels, since a Payroll Officer reviewing a run does not need to know which internal agent produced a
given flag, only its severity and what it says. A worked example: `{ employee_id: 2044, type: "compliance",
severity: "critical", message: "Garnishment deduction would exceed 25% of net pay cap.", ai_confidence: 0.97 }`
— exactly `docs/accounting/PAYROLL.md → API`'s own worked calculation response — renders as a `critical` card
in the Compliance-grouped section, requiring acknowledgment before Submit, with `ConfidenceBadge` showing 97%
and, on hover/focus, the same message as the reasoning tooltip (there is no separate `reasoning` field beyond
`message` for this endpoint's shape, so `ConfidenceBadge`'s tooltip slot receives the same string rather than
requesting a field the API does not return).

**Compliance Checks.** Always cites the specific statute in `message`, per
`docs/accounting/PAYROLL.md → AI Responsibilities`'s explicit rule ("Compliance flags always cite the specific
law/article in reasoning"); this screen never paraphrases or drops that citation — a message reading "Overtime
multiplier 1.15× is below the statutory minimum 1.25× (Kuwait Labour Law No. 6/2010, Art. 66)" renders
verbatim, since the citation is precisely what lets a Finance Manager verify the flag against the actual law
rather than trusting the AI's bare assertion.

**Fraud Detection.** Never renders a raw internal fraud score — the score is an internal signal the API does
not expose for direct display (mirroring `docs/frontend/JOURNAL_ENTRIES.md`'s identical treatment of its own
Fraud Detection agent). Its visible consequence on this screen is a `danger`-toned entry in
`AnomalyReviewPanel` (e.g., "This employee's IBAN is also registered to employee #2098" for a duplicate-bank-
account flag, or "No attendance or login activity recorded since hire" for a suspected ghost employee) and,
independently of anything a Payroll Officer does with that card, a `notifications`/Realtime push to every user
holding the Auditor role — this screen does not gate that escalation behind acknowledgment, and acknowledging
the card here (or even ignoring it) never suppresses the independent Auditor notification, exactly matching
`docs/accounting/PAYROLL.md`'s statement that this path exists "so a compromised Payroll Officer account
cannot suppress the alert."

**Payroll Explanation.** `GET`-triggered on demand from the Payslips tab's "Explain" action, powering a
bilingual reasoning `Sheet` generated in the viewer's own session locale — never machine-translated
client-side, matching `FRONTEND_ARCHITECTURE.md → AI Integration Layer`'s identical rule for the "Ask AI" chat
surface. The `Sheet`'s content is always labeled "AI-generated explanation — verify against the payslip,"
per `docs/accounting/PAYROLL.md → AI Responsibilities`'s own required label for this specific agent, rendered
as static, unremovable caption text rather than a dismissible banner a user could permanently hide.

**Confidence display.** Every `ai_confidence` field this screen encounters
(`payroll_run` calculation warnings) is already a `0.00–1.00` fraction, so every `ConfidenceBadge` on this
screen calls `normalizeConfidence(value, 'fraction')` — never `'percentage'`, which is reserved for the AI
Command Center's separately-scaled `ai_decisions.confidence_score` (0–100). Passing the wrong mode would
silently render a 97%-confident compliance flag as "1% confidence," so — exactly as
`docs/frontend/JOURNAL_ENTRIES.md → AI Integration` calls out for its own, differently-scaled confidence
fields — this is stated explicitly rather than left to a component author's assumption.

**No autonomous execution, anywhere.** To restate the point plainly because it is the single most important
sentence in this section: every agent in `docs/accounting/PAYROLL.md → AI Responsibilities`'s table is marked
`Suggest-only`, several with an additional blocking behavior on `critical` severity, and none with `auto`
autonomy. A payroll run's calculation, submission, approval, posting, and disbursement are, without
exception, human-initiated clicks on this screen, gated by the permission table in `# Route & Access` — the
AI layer's entire footprint here is advisory content rendered alongside those clicks, never a replacement for
one of them.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | List: `Skeleton` rows shaped like the table; Detail: header/stepper/tab-bar skeleton |
| Empty (no runs at all) | Brand-new company, zero `payroll_runs` rows | `EmptyState`: "No payroll runs yet," CTA "New Payroll Run" gated on `.create` |
| Empty (filtered to nothing) | Filters legitimately produce zero rows | Lighter `EmptyState` variant, "No runs match your filters" |
| Draft, pre-calculation | `status: 'draft'`, `employee_count: 0` | Employees tab shows an empty-state illustration and "Calculate" as the only enabled action; Approvals/WPS & Disbursement/Payslips tabs render a locked "Available after calculation" placeholder |
| Draft, calculated | `status: 'calculated'` | `PayrollItemsTable` populated; Anomaly Summary banner if `warnings[]` non-empty; Submit enabled once every `critical` flag is acknowledged |
| Draft, calculated, critical flags outstanding | ≥1 unacknowledged `critical` warning | Submit disabled with an explicit `aria-describedby` reason naming the count; `AnomalyReviewPanel`'s Acknowledge actions are the only path forward |
| Pending approval — viewer is the current-stage approver | `status IN ('pending_officer_approval','pending_finance_approval','pending_ceo_approval')`, viewer's role matches the open stage | That stage's `ApprovalCard` fully interactive (Approve/Reject/Delegate) |
| Pending approval — viewer is not the current-stage approver | Same statuses, viewer's role does not match | All three `ApprovalCard`s render read-only; the open stage additionally shows "Awaiting [Role]" rather than an actionable control |
| Approved (transient) | `status: 'approved'` | Rare to observe — the CEO-approval response usually already reflects the synchronous post; if seen, renders "Approved — posting…" |
| Rejected | `status: 'rejected'` | Read-only until reopened; the rejection reason and rejecting stage are surfaced prominently on the Approvals tab; Recalculate/Submit both required again from a `draft`-equivalent starting point |
| Posted | `status: 'posted'` | Employees/Approvals/Payslips tabs fully read-only; WPS & Disbursement tab's "Disburse" is the only remaining lifecycle action |
| Paid | `status: 'paid'` | Fully read-only; `is_visible_to_employee` flips true per employee as each one's `payment_status` reaches `paid`; overflow menu offers Reverse |
| Reversed | `status: 'reversed'` | Read-only, with a persistent cross-link to the new draft run it generated |
| WPS batch generated / submitted / acknowledged | `wps_export_batches.status` progression | `WpsExportBatchCard` reflects each status with its own tone; "acknowledged" is the terminal, success-toned state |
| WPS batch failed / a specific line failed | `wps_export_batches.status = 'failed'` or a single `payroll_items.payment_status = 'failed'` | Batch-level failure surfaces a banner with a retry path; a single failed line shows a scoped `danger` chip and "Re-issue payment" row action, never blocking the rest of the batch |
| Salary masked | Viewer lacks `payroll.salary.view`, or the API returned `null` for a monetary field | Every `SalaryAmountCell` renders the redacted glyph + lock + tooltip (`# Components Used`) |
| Version conflict | `409` on a mutation whose underlying state changed since the page loaded (e.g., another user approved the same stage moments earlier) | Toast: "This run was updated by someone else — reload to see the latest state"; no silent overwrite |
| Session permission revoked mid-view | A subsequent mutation attempt returns `403` after the permission set changes | The affected control collapses to its disabled-with-tooltip form; `SalaryAmountCell` re-masks on the next render regardless of what the stale cache still holds (`# Components Used`) |
| Error | `403` / `404` / `5xx` / network failure | Shared `403` boundary, `not-found.tsx`, or `ErrorState` with retry, per which failure occurred (`# Route & Access`) |

# Responsive Behavior

**List, Mobile (`base`–`sm`).** Follows `RESPONSIVE_DESIGN.md → Adaptive Patterns`: rows become cards, each
headed by the period label and `StatusPill`, with a two-column definition list of run type, employee count,
and pay date; the Gross/Net figures collapse to a single `SalaryAmountCell` line (masked or not) rather than
two separate columns, since a card the user is about to tap into anyway does not need the List's own
side-by-side comparison value. Filters collapse behind a single badge-counted "Filters" trigger opening a
`Sheet`; the density toggle is hidden entirely.

**Detail, Mobile (`base`–`sm`).** The Lifecycle Stepper collapses from a seven-stop horizontal rail to a
single-line compact summary — "Stage 3 of 6 · Awaiting Finance Manager" — with a tap target that expands the
full stepper into a bottom `Sheet` on demand, per `RESPONSIVE_DESIGN.md → Adaptive Patterns`'s general rule
that a wide, multi-stop wayfinding control degrades to a compact summary-plus-drill-in rather than shrinking
each stop illegibly. The five tabs become a horizontally-scrollable `Tabs` row (never a `Select` dropdown —
five sub-views is comfortably within the platform's horizontal-tab-scroll threshold). `PayrollItemsTable`
follows `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`'s card transformation identically to how
Journal Entries' own read-only Lines tab is treated on mobile, since a payroll item row is exactly the kind of
varied, per-row content (name, department, gross, net, payment status) that benefits from a card rather than
a cramped horizontally-scrolling table.

**Detail, Tablet (`md`–`lg`).** The Stepper renders in full but at reduced label density (stage names only,
no sub-caption); `PayrollItemsTable` gains its second and third priority columns per
`RESPONSIVE_DESIGN.md`'s tablet-tier table rule rather than fully collapsing to cards.

**Detail, Desktop (`xl:`+).** The full Lifecycle Stepper with every stage's approver name and timestamp
visible inline; `PayrollItemsTable` at full column width with the sticky-first-column (employee name)
horizontal-scroll treatment `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` specifies for any
tabular grid too wide to fit without scrolling even at this tier (a company whose payroll includes many
optional dimension/component columns).

**Virtualization.** `PayrollItemsTable` virtualizes past roughly 200 rows via `@tanstack/react-virtual`,
identical in mechanism to the Journal Entries List and its own line collection, since a several-thousand-
employee company's Employees tab is exactly the realistic large-collection case this pattern exists for.

**Touch targets.** Every interactive element — the Acknowledge button, `ApprovalCard`'s controls, row
actions, the Stepper's mobile expand trigger — meets the platform's 44×44px minimum regardless of density.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, inheriting `THEMING.md`'s
RTL-as-a-theming-dimension and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a single per-component RTL
branch, plus several Payroll-specific applications:

- **Bilingual employee and salary-component names.** `PayrollItemsTable`, `PayrollItemBreakdownSheet`, and
  `PayslipViewer` all render `name_ar` under an Arabic session and fall back to `name_en` only if the Arabic
  name is genuinely absent — never the reverse.
- **Every monetary figure — masked or not — is `text-end` fixed, never mirrored**, exactly matching
  `AmountCell`'s own `align="end"` default: `SalaryAmountCell`'s redacted glyph carries the same `dir="ltr"`
  wrapper as a real figure, so a column of masked and unmasked amounts stays visually aligned regardless of
  session language.
- **Run labels, periods, confidence percentages, and civil-ID-adjacent references stay LTR-embedded** inside
  Arabic sentences (the Anomaly Review panel's compliance messages, the AI explanation `Sheet`'s prose) via
  the shared `Bidi`/`LtrInline` wrapper, so "97%" or "Art. 66" never reorders inside a right-to-left
  paragraph.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching `AmountCell`'s
  own `formatAmount` implementation and the Gulf financial-document convention that a mixed numeral system
  inside a single payroll figure reads as an error to an accountant.
- **Payroll-specific Arabic terminology** is used precisely and consistently across this screen's
  translations: كشف الرواتب (payroll run), مسودة (draft), تم الاحتساب (calculated), بانتظار اعتماد مسؤول
  الرواتب (pending Payroll Officer approval), بانتظار اعتماد المدير المالي (pending Finance Manager
  approval), بانتظار اعتماد الرئيس التنفيذي (pending CEO approval), معتمد (approved), مرحّل (posted), مدفوع
  (paid), عكس (reverse), التأمينات الاجتماعية (PIFSS/social insurance), مكافأة نهاية الخدمة (end-of-service
  indemnity), نظام حماية الأجور (Wage Protection System), قسيمة الراتب (payslip).
- **The WPS SIF bank file's own internal field layout never localizes.** `docs/accounting/PAYROLL.md →
  Compliance → WPS` defines a bank-mandated fixed format; the UI's surrounding chrome (button labels, the
  batch status chip) is fully bilingual, but the generated file's actual field order and encoding are
  identical regardless of which language the Finance Manager who clicked "Disburse" was working in — the
  same "content that must not vary by session language" principle
  `docs/frontend/JOURNAL_ENTRIES.md → Dark Mode`'s "exports render light, always" rule applies to theme, here
  applied to a compliance artifact's format instead.
- **The redacted salary glyph is direction-agnostic by construction** (`•••.•••`, no directional
  punctuation), so it requires no RTL-specific mirroring rule of its own.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a bare Tailwind palette utility outside the shared component files it composes.

- **Surfaces and elevation.** The List and Detail canvases sit on `--surface-canvas`; every `Card` region
  (Anomaly Review groups, `PayrollItemBreakdownSheet`'s line-group sections, `WpsExportBatchCard`) sits on
  `--surface-base`; every `Dialog`/`Sheet`/`Tooltip` (confirmations, the AI reasoning `Sheet`,
  `SalaryAmountCell`'s tooltip) sits on `--surface-overlay`, each carrying its paired border token even where
  its light-mode equivalent relies on shadow alone.
- **`StatusPill domain="payroll_run"` tones**, fixed platform-wide and not re-derived on this screen: `draft`
  → neutral, `calculated` → accent (ready for the next human step), `pending_officer_approval` /
  `pending_finance_approval` / `pending_ceo_approval` → warning, `approved` → accent (transitional, mirroring
  Journal Entries' identical convention for its own pre-posting transitional state), `rejected` → danger,
  `posted` → **accent, not success** — a deliberate refinement over a naive copy of Journal Entries' own
  table: `posted` means the accounting liability is recorded, but no cash has left the company yet, so
  labeling it `success` this early would overstate what has actually happened — `paid` → success (cash has
  genuinely moved), `reversed` → neutral.
- **Anomaly severity tones map to the platform's existing three-tier semantic set, never a bespoke fourth
  color**: `critical` → `--status-error`, `warning` → `--status-warning`, `info` → a neutral `--ink-500`,
  identical to how `docs/frontend/JOURNAL_ENTRIES.md`'s single dimension-required warning dot uses
  `--status-warning` — this screen simply has three tiers to Journal Entries' one, mapped onto the same
  underlying token set rather than inventing new ones.
- **The masked salary glyph is never status-colored.** `SalaryAmountCell`'s redacted state renders in
  `--ink-500` (a neutral, "information withheld" tone) in both themes — never `--status-warning` or
  `--status-error`, since a masked figure is not itself a problem to flag, only an access boundary to
  communicate, and conflating the two would make a Senior Accountant's entirely normal, expected view of a
  run look like something is wrong with it.
- **AI provenance uses `--ai-accent` exclusively**, never a finance-status hue, for every `ConfidenceBadge`
  on this screen — identical to the platform-wide rule `docs/frontend/JOURNAL_ENTRIES.md → Dark Mode`
  states in full, restated here because a `critical`-severity, high-confidence fraud flag is exactly the
  case where it would be tempting (and wrong) to conflate "the AI is very sure" with "this is very bad" into
  one color; the two remain visually independent, side by side.
- **Contrast.** Every pairing above, including the redacted glyph's own lock-icon-plus-text combination, is
  independently verified at ≥4.5:1 (text) / ≥3:1 (non-text) in both themes per `DARK_MODE.md → Color &
  Contrast In Dark`.
- **Exports render light, always.** A PDF/XLSX run summary export, and every generated payslip PDF, render in
  QAYD's fixed light/print palette regardless of the exporting or generating user's active theme — a payslip
  is routinely handed to an employee or a bank who has never opened the dark-mode app, per
  `DARK_MODE.md → Exported PDFs always render light`.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented without deviation, plus the Payroll-specific
applications below.

- **`PayrollItemsTable` uses the plain, accessible `<table>` pattern — never the ARIA `grid`.**
  `ACCESSIBILITY.md → Data Tables Accessibility` names exactly two surfaces in the entire platform that
  warrant the grid pattern (the Journal Entry line editor and the Bank Reconciliation matching grid); this
  screen's Employees tab is read-only, computed content with no cell-to-cell keying need, and deliberately
  does not become a third. Real `scope="col"` headers, sortable headers as real `<button>`s with `aria-sort`,
  and a uniquely-worded `aria-label` on every row action ("View breakdown for Fahad Al-Ali," never a bare
  "View").
- **A masked figure has a real, distinct text equivalent for assistive technology.** `SalaryAmountCell`'s
  `VisuallyHidden` text reads "Restricted — requires the payroll salary view permission," never merely
  re-announcing the visual `•••.•••` glyph or, worse, silence — a screen-reader user must be able to tell the
  difference between "this figure is zero" and "this figure is withheld from you" exactly as clearly as a
  sighted user can from the lock icon.
- **RBAC-disabled and business-rule-disabled controls explain themselves, and explain themselves
  differently.** A Submit button disabled for lacking `payroll.run.submit` carries an `aria-describedby`
  naming that permission; the same button disabled because critical anomalies remain unacknowledged carries a
  distinct `aria-describedby` naming the exact count and linking conceptually to `AnomalyReviewPanel` — the
  two are never collapsed into one generic "You can't do this right now," matching
  `ACCESSIBILITY.md`'s explicit rule that conflating them is a P1 defect.
- **A sensitive action with no permission is omitted, not disabled.** Disburse, Post, Reverse, and the
  Approve/Reject controls at a stage the viewer cannot act on are removed from the DOM for a role lacking the
  corresponding permission — never rendered disabled with no explanation.
- **The Lifecycle Stepper is a real ordered list**, not a row of styled `<div>`s: each stage is a
  `<li>` with `aria-current="step"` on the active one and a `VisuallyHidden` status word ("completed,"
  "current," "upcoming," "rejected") beyond what color alone conveys.
- **Focus management on overlays.** Every `Dialog`/`Sheet`/`AlertDialog` on this screen (confirmations, the
  anomaly-acknowledgment reason field, `PayslipViewer`, the AI reasoning `Sheet`) moves focus to its heading
  on open and returns focus to the triggering control on close, identical to
  `docs/frontend/JOURNAL_ENTRIES.md → Accessibility`'s rule.
- **Keyboard.** `G` then `P` (`# Route & Access`) opens the List; every confirming `Dialog`/`AlertDialog`'s
  primary action responds to `Cmd`/`Ctrl+Enter`; `Tab` order through `AnomalyReviewPanel` moves severity group
  by severity group, critical first, matching its own visual priority.
- **Realtime updates never yank focus or silently splice.** A `.payroll_run.posted`/`.payroll_run.paid` push
  affecting the run a user currently has open updates its cached status without moving keyboard focus or
  reordering the List a second window might have open, per the platform-wide realtime-accessibility rule.

# Performance

- **Server-paginated, never client-sorted or client-filtered.** The List's `DataTable` issues a new request
  for every sort, filter, or page change; a company with hundreds of historical runs never ships more than
  one page's worth to the browser.
- **Virtualization past ~200 rows** on `PayrollItemsTable`, using `@tanstack/react-virtual` with a density- or
  breakpoint-aware row-height estimate.
- **Cache tuning matches data volatility**, per `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`: the
  List's `staleTime` is 30 seconds (transactional-list tier, identical to Journal Entries); `payroll/periods`
  reference data is 5 minutes (rarely-changing tier); a single open run Detail is additionally kept fresh via
  targeted Realtime invalidation rather than polling.
- **The calculation request gets its own extended client-side timeout**, distinct from the platform default,
  reflecting `docs/accounting/PAYROLL.md → Performance`'s description of chunked, queued, potentially
  longer-running work for large companies — the UI never treats a slow calculation as a failure purely because
  it outran an ordinary CRUD-sized timeout.
- **Idempotent, single-attempt mutations.** Every write endpoint this screen calls carries a client-generated
  `Idempotency-Key` scoped to one logical submission attempt, so a slow response on a flaky connection
  followed by an impatient second tap can never double-calculate, double-approve, double-post, or
  double-disburse a run — the last of which would be a genuinely severe incident (duplicate salary payment)
  if it were ever allowed to occur.
- **Payslip PDFs are pre-generated server-side, never client-rendered.** `docs/accounting/PAYROLL.md →
  Performance` describes payslip generation as queued and parallelized, one job per employee, specifically so
  posting a large run does not block on rendering hundreds of PDFs synchronously; this screen's `PayslipViewer`
  and Payslips tab simply reflect whatever generation status the API currently reports and link to the signed
  `download_url` once ready — the frontend never attempts to generate, preview-render, or cache PDF bytes
  itself.
- **Deferred, code-split overlays.** `NewPayrollRunDialog`, every confirming `Dialog`, `PayslipViewer`, and the
  AI reasoning `Sheet` are dynamically imported (`next/dynamic`) rather than bundled into the List's or
  Detail's initial route chunk, since none of them is needed for first paint.
- **SSR-seeded first paint.** Both the List's first page and a Detail route's initial fetch happen
  server-side and hydrate directly into the TanStack Query cache, so the client's first `useQuery` for the
  same keys resolves instantly rather than re-issuing a request the server already made.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A `SalaryAmountCell` receives `amount: null` alongside an otherwise fully-populated row | Renders the redacted glyph regardless of any other field's presence — `null` is never coerced to `"0.0000"` or silently treated as a formatting error; this is the single highest-stakes rendering rule on this screen (`# Components Used`) |
| A stale permission snapshot still shows `payroll.salary.view` as granted after it was revoked mid-session | `SalaryAmountCell` re-checks `usePermission` on every render regardless of what a previously-cached amount string still holds, and re-masks immediately — never trusting the first-load permission snapshot as permanently valid |
| Mid-period hire/termination pro-ration | Not independently computed by the frontend — `PayrollItemBreakdownSheet` simply displays whatever `proration_factor` and line amounts the calculation engine returned; the UI adds a small caption ("11/30 days — hired Jul 20") reading directly from the API's own fields, never re-deriving the fraction itself |
| An employee's gross pay for the period is legitimately `0.0000` (zero attendance, hourly-wage component) | Rendered as a real, unmasked `0.000`, visually distinct from a masked figure — a Payroll Officer must be able to tell "this employee earned nothing this period" apart from "I'm not allowed to see this employee's figure," which is exactly why `SalaryAmountCell` treats `null` and `"0.0000"` as opposite, never adjacent, cases |
| Negative net pay would occur (deductions/installments exceed gross) | Not shown as negative — the calculation engine already caps this server-side and defers the excess installment to the next period (`docs/accounting/PAYROLL.md → Edge Cases`); this screen's only responsibility is to surface the resulting system note/warning in `AnomalyReviewPanel`, never to independently clamp or recompute a figure |
| An employee is transferred mid-period between branches with different overtime rules | `PayrollItemBreakdownSheet` shows two attendance-derived line groups (pre-/post-transfer) rather than one blended figure, reflecting the calculation engine's own split, never a frontend-side merge |
| A resigned employee's final settlement can't fully cover an outstanding loan | The Employees tab's breakdown for that employee shows the loan's remaining `outstanding_balance` alongside the settlement, with a "Flagged for HR/Finance follow-up" note — the screen never implies the balance was silently written off, matching the backend's explicit refusal to force net pay negative or auto-write-off |
| A bank rejects one WPS line | Only that `payroll_items` row shows `payment_status: 'failed'` with a scoped "Re-issue payment" action; the rest of the batch and the run's own status are entirely unaffected (`# Interactions & Flows`) |
| An AI anomaly flag turns out to be a false positive (e.g., a legitimate large one-off bonus) | The Payroll Officer's typed acknowledgment reason is permanently retained and visible on the History tab; the system does not auto-suppress the same flag shape in a future run based on this one override, matching `docs/accounting/PAYROLL.md → Edge Cases`'s explicit no-auto-suppression rule |
| Company switch mid-review | Per the platform's company-switch contract, switching companies discards all cached Payroll Runs state, including any in-progress, not-yet-submitted anomaly acknowledgments; if any exist, the switcher's own confirmation dialog names the in-progress run specifically before proceeding |
| Session permission revoked mid-view | The next mutation attempt's `403` collapses the affected control to its disabled-with-tooltip form and shows a toast explaining why; `SalaryAmountCell` independently re-masks per the permission-recheck rule above regardless of which mutation triggered the discovery |
| Realtime push arrives while a user is mid-review of `AnomalyReviewPanel` or mid-typing an acknowledgment reason | The push only invalidates cached queries (`# Data & State → Realtime`); it never overwrites in-progress local acknowledgment state or a half-typed reason — the user's next explicit action (Recalculate, Submit) is what actually reconciles against the fresher server state |
| Reconnecting after an extended disconnect | Every Payroll realtime-fed query key is invalidated once on reconnect, since WebSocket frames broadcast while disconnected are permanently missed — a stale cache is never silently trusted as current |
| A very large run (thousands of employees) | `PayrollItemsTable` virtualizes past ~200 rows exactly like the Journal Entries List (`# Performance`); the calculation request's extended timeout and indeterminate progress messaging (`# Interactions & Flows`) apply specifically to this case |
| Printing a payslip or run summary directly via the browser rather than Export | Not specially handled — users are steered toward the Export/Download actions instead, the only paths that produce a durable, correctly light-themed, bank-and-employee-safe document, per `DARK_MODE.md`'s exported-documents-always-render-light rule |

# End of Document
