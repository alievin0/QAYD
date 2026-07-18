# Payroll Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PAYROLL_SCREEN
---

# Purpose

This document is the concrete, structured screen specification for Payroll's landing route family —
`app/(app)/payroll/runs/page.tsx` (the run list) and `app/(app)/payroll/runs/[runId]/page.tsx` (the
stage-aware run detail) — the surface a Payroll Officer, Finance Manager, CFO, CEO, HR Manager, or
Auditor actually lands on the instant they click "Payroll" in the sidebar
(`NAVIGATION_SYSTEM.md → Primary Navigation`, row 7: **Payroll** · `Users` icon · gate `payroll.read`,
pointing directly at `/payroll/runs`, never a bare `/payroll` segment — see **Edge Cases** for what that
bare hit resolves to). It is written against the platform's `SCREEN DOC STRUCTURE` template so that it
reads, reviews, and diffs section-by-section alongside every other screen document in this series —
Dashboard, Accounting, General Ledger, Journal Entries, Trial Balance, Balance Sheet, Profit & Loss, Cash
Flow, Banking, Bank Reconciliation, and the AI Command Center — using the identical fourteen headings this
folder's `ACCOUNTING_SCREEN.md`, `BANKING_SCREEN.md`, and `SALES_SCREEN.md` already use.

This document is the concrete, structured companion to `docs/frontend/PAYROLL.md`, which specifies the
same route family in fuller prose; the two must never disagree. Where this document is silent,
`docs/frontend/PAYROLL.md`, `docs/accounting/PAYROLL.md` (the backend module of record for every table,
status value, permission key, and business rule named below), `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`,
`DARK_MODE.md`, and `ACCESSIBILITY.md` govern, in that order; where this document appears to contradict
one of them on a fact — a route, a permission key, a status value, a component name, an API shape — that
is a defect in one of the two documents to raise and resolve, never a decision an engineer resolves
unilaterally in code.

Every dinar that leaves a company's bank account as salary, every PIFSS (Kuwait's Public Institution for
Social Security) contribution schedule filed on employees' behalf, and every end-of-service indemnity
(EOS) accrual sitting on the Balance Sheet as a provision passes through this screen's data model in
exactly one shape: a `payroll_runs` row, calculated once by `PayrollCalculationService` on the Laravel
API, approved through a mandatory three-stage human chain, posted to the General Ledger, and disbursed —
never edited after the fact, only reversed and re-issued as a fresh `draft`. This screen renders that
regulation faithfully; it never computes a payroll figure of its own, never decides that an AI-flagged
anomaly is safe to ignore, and never lets a calculated total substitute for a human's sign-off on money
actually leaving the company. Two sub-surfaces share one route family, one data model, and one permission
surface:

1. **List** (`/payroll/runs`) — every `payroll_runs` row across every period, branch, and run type the
   viewer is entitled to see, filterable by status/period/branch/run type, with `total_gross`,
   `total_net`, and `total_employer_cost` masked for any role that lacks `payroll.salary.view`.
2. **Detail** (`/payroll/runs/[runId]`) — the full lifecycle of one run, rendered as a persistent
   lifecycle header plus five tabs (Employees, Approvals, WPS & Disbursement, Payslips, History) covering
   every stage `docs/accounting/PAYROLL.md → Payroll Processing` names: `draft` → **Calculate** →
   `calculated` → **Submit** → `pending_officer_approval` → **Payroll Officer approves** →
   `pending_finance_approval` → **Finance Manager approves** → `pending_ceo_approval` → **CEO approves**
   → `approved` → **Post** → `posted` → **Disburse** → `paid`, with **Reverse** available from `posted`
   or `paid`, and **Reject** returning the run to `draft` from any pending stage.

**A terminology note, stated once so it never needs restating.** This document's task brief and everyday
Finance conversation both use the word "release" for the moment money actually moves; the established
vocabulary in `docs/accounting/PAYROLL.md` and `docs/frontend/PAYROLL.md` resolves that single word into
two distinct, both human-gated concepts this screen renders separately: (a) the three-stage approval
chain itself, which `ApprovalCard kind="payroll_release"` renders on the Approvals tab, and (b) the
**Disburse** action (`payroll.disburse`) that follows posting and actually generates payment instructions.
Neither has an autonomous or AI-driven path under any configuration — there is no status value, no
permission grant, and no confidence threshold that lets a payroll run's funds release without an explicit,
attributable human click at every one of the three approval stages and again at Disburse.

Payroll carries one structural property most other screens in QAYD do not share to this degree: **salary
visibility is a second, independent permission axis, orthogonal to read access to the run itself.** A
Senior Accountant or Accountant holds `payroll.read` — they can see that a July payroll run exists, its
status, and its employee count — but not `payroll.salary.view`, so they can never see what it actually
cost. `SalaryAmountCell` (`# Components Used`) exists because of that split, and every other region of
this screen — the List's Gross/Net columns, the Employees tab's table, the Payslips tab — is built
assuming the split is real and enforced at the API response level, never assumed away for convenience.
The three-stage approval chain (Payroll Officer → Finance Manager → CEO) is, per
`docs/accounting/PAYROLL.md → Purpose`, unconditional: unlike Journal Entries, where a low-value entry can
post directly with no human approval at all, no payroll run — regardless of size, run type, or company —
ever reaches `posted` without all three signatures. There is no tiered exemption to reproduce client-side,
which makes this screen's primary-action logic a pure function of `status`, never additionally branched on
a permission-dependent amount threshold.

Nothing in this document introduces a validation rule, a permission key, a status value, a table column,
or an API shape not already defined in `docs/accounting/PAYROLL.md`; this document is strictly the
rendering, interaction, and state-management contract for that backend specification, concretized into the
exact route tree, wireframes, component props, endpoint tables, and JSON payloads an engineer builds
against.

# Route & Access

## App Router path

```text
app/(app)/payroll/
├── runs/
│   ├── page.tsx                     # ★ THIS DOCUMENT — List, Server Component, first-paint fetch
│   ├── loading.tsx                  # List skeleton, streamed while the Server Component fetches
│   └── [runId]/
│       ├── page.tsx                 # ★ THIS DOCUMENT — Detail, the stage-aware lifecycle shell, five tabs
│       └── loading.tsx              # Detail skeleton (header + stepper + tab-bar shape)
├── employees/        …              # Sibling sidebar leaf — its own screen document, out of scope here
├── payslips/          …             # Sibling sidebar leaf (self-service view) — its own screen document
├── attendance/         …            # Sibling sidebar leaf — its own screen document
└── loans/               …           # Sibling sidebar leaf — its own screen document
```

`runs/page.tsx` and `runs/[runId]/page.tsx` are the only two files this document specifies in depth —
together they are what the task brief's shorthand "route `app/(app)/payroll`" resolves to concretely, since
there is no `app/(app)/payroll/page.tsx` at the module's own bare root (see **Edge Cases**). `[runId]`
follows the platform's dynamic-segment convention — camelCase, named for the entity, never the generic
`[id]` (`FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — mirroring its API-resource path,
`/api/v1/payroll/runs/{id}`. The four sibling leaves shown above — Employees, Payslips, Attendance, Loans
— are real entries in Payroll's own sidebar section per `NAVIGATION_SYSTEM.md`'s Payroll sub-item list,
sitting alongside "Payroll Runs" as distinct destinations rather than in-page tabs of this route the way
Banking's Accounts/Transactions/Transfers are tabs of one shared `layout.tsx`; Payroll deliberately has no
comparable shared tab layout at its own root, because each sibling leaf is substantial enough to be its
own primary destination, not a filtered view of the same resource. This document does not specify their
exact segment paths beyond naming them, exactly as `docs/frontend/PAYROLL.md → Route & Access` itself
declines to — each is "its own screen document, outside this one's scope," referenced here only as a
cross-link surface (a loan cross-link from a payroll item's breakdown, an attendance cross-link from the
same place) never as content this document re-specifies.

**Deliberately no `/payroll/runs/new` route.** Creating a payroll run is a three-field decision
(`payroll_period_id`, `run_type`, optional `branch_id` for multi-branch companies) — every other figure on
the run is generated by the calculation engine, never hand-typed the way a Journal Entry's lines are. A
dedicated route for three fields would be empty scaffolding around a `Dialog`'s worth of content.
`NewPayrollRunDialog` (`# Components Used`) opens from the List's Page Header, gated
`payroll.run.create`, and on success navigates straight to `/payroll/runs/{id}`, landing on the Employees
tab's pre-calculation empty state with **Calculate** as the one enabled primary action.

## Permission gate

Every key below is defined once, authoritatively, in `docs/accounting/PAYROLL.md → Permissions`; this
table is this screen's map of key → concrete UI effect, not a redefinition.

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible at all | `payroll.read` | Sidebar entry and route both absent; a direct hit renders the shared `403` boundary, never a silent redirect |
| Every `SalaryAmountCell` figure — `total_gross`, `total_net`, `total_employer_cost`, every `payroll_items`/`payroll_item_lines` amount | `payroll.salary.view` | Renders a redacted placeholder with a lock glyph and an explanatory tooltip in place of the figure — never a `403` on the whole page, since the run's existence and status remain visible to a `payroll.read`-only viewer |
| "New Payroll Run" button, `NewPayrollRunDialog` | `payroll.run.create` | Button removed from the DOM, not disabled |
| "Calculate"/"Recalculate", and the List's bulk "Recalculate" action | `payroll.run.calculate` | Button/bulk action omitted |
| "Submit for approval" | `payroll.run.submit` | Button omitted |
| Approve/Reject/Delegate on the Approvals tab, only at the viewer's own currently-pending stage | `payroll.run.approve` | `ApprovalCard` renders read-only (amount, requester, stage, confidence — no action row) |
| "Post" | `payroll.run.post` | Button omitted |
| "Disburse", WPS file generation and download | `payroll.disburse` | WPS & Disbursement tab renders read-only ("awaiting Finance to disburse"); button omitted |
| "Reverse" (overflow menu, `posted`/`paid` only) | `payroll.run.reverse` | Menu item omitted |
| Per-employee "View loan" / "View attendance" cross-links | `payroll.loan.create`/`payroll.loan.approve` (referenced only) / `attendance.read` | Cross-link omitted |
| Payslips tab's per-employee view/download | `payroll.payslip.view` | Tab renders "Requires `payroll.payslip.view`" empty state rather than every row individually omitted |
| "View cost report" / "View forecast" cross-links | `reports.payroll.view` | Links omitted |
| `NewPayrollRunDialog`'s inline "Open a new payroll period" affordance | `payroll.periods.manage` | Replaced with static text: "Ask a Finance Manager to open a payroll period for this month" |
| Encrypted PII (national ID, passport, bank account) | `payroll.pii.view` | Incidental only — this screen never surfaces those fields directly; they live on the Employee Master screen |

## Roles

| Role | What renders on this screen |
|---|---|
| Owner | Everything: every figure unmasked, every lifecycle action at every stage, Disburse, Reverse |
| CEO | Everything except Create/Calculate/Post/Disburse; the CEO's own Approve is valid only at the `ceo` stage — out-of-order approval is structurally prevented (`# Interactions & Flows`) |
| CFO | Everything Finance Manager has, plus every read surface a Finance Manager sees |
| Finance Manager | Everything except Create/Calculate are optional per company policy; Approve valid only at the `finance_manager` stage; Post and Disburse both available |
| Payroll Officer | Full `payroll.salary.view`, Create, Calculate, Submit, and Approve at the `payroll_officer` stage only; no Post, no Disburse; additionally branch-scoped by row-level security, so a branch-restricted Payroll Officer's List simply never contains another branch's runs — there is no row to mask, because the API never returns it |
| HR Manager | Full read + salary view + payslip view; no lifecycle mutation of any kind on this screen |
| Senior Accountant | `payroll.read` only — sees every run's existence, status, and employee count with every monetary figure masked; no lifecycle actions |
| Accountant | Identical floor to Senior Accountant on this screen |
| Auditor / External Auditor | Fully read-only, but with `payroll.salary.view` — every figure and every AI reasoning disclosure renders unmasked; no mutating control renders at all; independently notified out-of-band on any critical Fraud Detection flag regardless of what happens on this screen |
| Employee | Never renders this screen — an Employee role uses the separate self-service `/payroll/payslips` route for their own payslip only, out of this document's scope |
| AI service account | Never renders this screen as a user; structurally incapable of holding `payroll.run.approve`, `payroll.run.post`, or `payroll.disburse` regardless of any role grant — enforced at the database level via a `CHECK` on `payroll_run_approvals.approver_id` requiring a non-service-account user |

A cross-company `runId` (one that exists but belongs to a different `company_id`) renders the shared
`not-found.tsx`, never `403` — the API returns `404` specifically so a caller can never learn that a
payroll run with that id exists in a company they cannot see, a rule this screen leans on more heavily
than most given how sensitive the mere existence of a payroll run's aggregate figures already is.

## Keyboard entry points

`G` then `P` navigates to `/payroll/runs` from anywhere in the app (`ACCESSIBILITY.md → Global keyboard
shortcuts`). There is no screen-scoped `N` shortcut for `NewPayrollRunDialog` — a keyboard shortcut for a
dialog a Payroll Officer opens at most a handful of times per month is a marginal saving that
`ACCESSIBILITY.md` reserves single-letter global shortcuts against.

# Layout & Regions

Both sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing List and Detail Page Templates verbatim — no new
layout shape is introduced for Payroll.

## List — `runs/page.tsx`

```text
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

| Region | Contents | Streaming boundary |
|---|---|---|
| Page Header | `<h1>Payroll Runs</h1>`, live count from `meta.pagination.total`, primary action gated `payroll.run.create` | Server Component — renders with the shell, first paint |
| Filter Bar | Search (`q`, over period proximity and run number) plus four named filters: Status (multi-select, the ten `payroll_run_status` values), Period (`PayrollPeriodSelect`), Run type (multi-select over the five `payroll_run_type` values), Branch (only for multi-branch companies) | Own `<Suspense>` shell around the table below |
| Bulk Action Bar | Replaces the Filter Bar's end side once ≥1 row is selected: "3 selected · Recalculate · Clear" — the only bulk action, gated `payroll.run.calculate`, scoped to rows whose `status IN ('draft','calculated')` | Client-side selection state |
| Data Region | `DataTable` (`resource="payroll/runs"`, `paginationMode="page"`), Gross/Net columns rendered through `SalaryAmountCell` | Own `<Suspense>` — `GET /payroll/runs` |
| Pagination Footer | Page controls, "Showing 1–25 of 214" | n/a |

The second List row above shows the masked rendering a Senior Accountant or Accountant sees for the exact
same underlying data a Finance Manager sees as real figures one row above and below it — the entire reason
`SalaryAmountCell` exists as its own component rather than a thin re-skin of the shared `AmountCell`.

## Detail — `runs/[runId]/page.tsx`, a persistent lifecycle header over five tabs

```text
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

| Region | Contents | Streaming boundary |
|---|---|---|
| Page Header | Back-to-list link; a human-readable run label composed client-side from the period's `name_en`/`name_ar` and `run_type` (never a bare `#512`); `StatusPill domain="payroll_run"`; the single lifecycle action that applies right now (`# Interactions & Flows` resolves exactly which one); an overflow menu for Reverse (`posted`/`paid` only) and Export (`reports.payroll.view`) | Server Component — renders with the shell once the run's core row is prefetched |
| Lifecycle Stepper (`PayrollLifecycleStepper`) | Seven-stop horizontal rail: Draft → Calculated → Officer → Finance → CEO → Posted → Paid, collapsing the three `pending_*_approval` values into their three named stops and folding `approved` into an instantaneous tick, since approving the CEO stage triggers posting synchronously in the same request cycle | Client Component, patched via Realtime |
| Tab/Segment Nav | `Tabs`: **Employees** (default), **Approvals**, **WPS & Disbursement**, **Payslips**, **History** | n/a |
| Employees tab | Read-only `PayrollItemsTable` (one row per employee: name, department, gross, net, payment status); an Anomaly Summary banner above the table when the current calculation carries any unacknowledged warning; each row opens `PayrollItemBreakdownSheet` | Own `<Suspense>` — `GET /payroll/runs/{id}/items` |
| Approvals tab | The three-stage `ApprovalCard` sequence (`kind="payroll_release"`); the full `AnomalyReviewPanel` above the stages, since acknowledging a critical flag is a precondition for the chain to begin | Own `<Suspense>`, keyed off the run detail's own `warnings[]` echo |
| WPS & Disbursement tab | Payment-method breakdown (bank transfer/cash/wallet/cheque counts and totals); the Disburse action once `posted`; the `wps_export_batches` history once at least one batch exists | Own `<Suspense>` — `GET` on the run detail plus a lazily-fetched batch list |
| Payslips tab | One row per employee: language, generation status, `is_visible_to_employee`, a "View" action opening `PayslipViewer` | Own `<Suspense>`, one call per distinct employee row expanded |
| History tab | The merged `payroll_run_approvals` + `audit_logs` timeline, always at the bottom of this tab's content, never a rail footnote | Own `<Suspense>` |

There is deliberately no Summary Rail the way Journal Entries' Detail page has one — every figure a rail
would show (total gross/net, currency, period, creator) is either already in the Page Header/Stepper or
one tab-click away in Employees, and a persistent rail displaying `SalaryAmountCell`-masked figures on
every tab regardless of which tab a `payroll.read`-only viewer is looking at would be redundant chrome for
exactly the audience most likely to see nothing but redacted glyphs in it.

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, or is one of the screen-specific
composed components this document (and its companion, `docs/frontend/PAYROLL.md`) specify for this exact
surface. A component gap discovered while building this screen is proposed as an addition to
`COMPONENT_LIBRARY.md`, never hand-rolled locally and left there.

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The List's server-paginated, sortable, filterable run table (`resource="payroll/runs"`) |
| `StatusPill` | `components/shared/status-pill.tsx` | Every lifecycle-status rendering, list and detail (`domain="payroll_run"`) |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every non-sensitive figure that is not a salary amount — e.g., a `wps_export_batches.total_amount` once already gated by the tab-level `payroll.disburse`/`payroll.salary.view` check, or a loan `principal_amount` reached via cross-link |
| `SalaryAmountCell` | `components/payroll/salary-amount-cell.tsx` (screen-specific) | Every `gross_salary`/`net_salary`/`total_deductions`/`total_statutory`/`employer_cost`/`payroll_item_lines.amount` figure, list and detail |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Currency indicator beside every masked or unmasked amount |
| `PayrollPeriodSelect` | `components/payroll/payroll-period-select.tsx` (screen-specific) | The List's Period filter and `NewPayrollRunDialog`'s period field |
| `NewPayrollRunDialog` | `components/payroll/new-payroll-run-dialog.tsx` (screen-specific) | The List's "New Payroll Run" action — the three-field create flow |
| `PayrollLifecycleStepper` | `components/payroll/payroll-lifecycle-stepper.tsx` (screen-specific, thin wrapper over the shared `<Stepper>` primitive) | The Detail page's persistent lifecycle header |
| `PayrollLifecycleActionButton` | `components/payroll/payroll-lifecycle-action-button.tsx` (screen-specific, new — see below) | The Page Header's single primary action, resolved as a pure function of `status` |
| `PayrollItemsTable` | `components/payroll/payroll-items-table.tsx` (screen-specific) | The Employees tab's read-only, per-employee table — the plain `<table>` accessibility pattern, never a grid (`# Accessibility`) |
| `PayrollItemBreakdownSheet` | `components/payroll/payroll-item-breakdown-sheet.tsx` (screen-specific) | The per-employee earnings/deductions/PIFSS/EOS drill-in, opened from a `PayrollItemsTable` row |
| `AnomalyReviewPanel` | `components/payroll/anomaly-review-panel.tsx` (screen-specific) | The Approvals tab's grouped warnings list (`type`, `severity`, `message`, `ai_confidence`) sourced from the last `.../calculate` response |
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

The single most consequential component on this screen. `docs/accounting/PAYROLL.md → Security → Salary
Privacy` establishes that salary visibility is enforced independently of `payroll.read`, at the API
response level — a caller lacking `payroll.salary.view` never has the real figure sent to their browser in
the first place, matching `FRONTEND_ARCHITECTURE.md` Principle 4's rule that hiding is a UX courtesy while
the actual boundary is server-side. `SalaryAmountCell`'s entire job is to render *whatever the API
actually returned* correctly.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `amount` | `string \| null` | yes | `null` means "the API withheld this field for the current caller" and must never be coerced to `"0.0000"` — a real zero and a masked figure render completely differently (`# Edge Cases`) |
| `currencyCode` | `string` | yes | Passed straight through to `AmountCell` when unmasked |
| `mode` | `'debit' \| 'credit' \| 'signed' \| 'plain'` | no, default `'plain'` | Identical semantics to `AmountCell`'s own prop when unmasked; ignored while masked |
| `emphasis` | `'default' \| 'strong' \| 'muted'` | no | Passed through when unmasked; the masked glyph always renders at `'muted'` weight regardless of what is requested |
| `requiredPermission` | `string` | no, default `'payroll.salary.view'` | Named explicitly so the same component serves any future screen with an analogous masked-figure need |

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
  // this branch renders regardless of `canView`, so a stale client-side permission snapshot can
  // never invent a number the server did not actually provide.
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

Two decisions carry real weight. First, the redacted glyph `•••.•••` is a fixed-width placeholder that
never resembles a plausible currency figure — deliberately not `"0.000"` or a blurred version of real
digits, so a Finance Manager glancing over a Senior Accountant's shoulder cannot mistake "masked" for
"this run cost nothing." Second, the component checks `canView` in addition to `amount === null` rather
than trusting either signal alone: if a permission is revoked mid-session before the cached
`payroll_runs` query is invalidated, `!canView` forces the redacted rendering even though `amount` is
technically still a non-null string sitting in the TanStack Query cache.

## `PayrollLifecycleActionButton`

New in this document — a small composed component making concrete what `docs/frontend/PAYROLL.md →
Purpose` states in prose ("the lifecycle action's label is a pure function of `status`"). It resolves
exactly one primary action from the run's current `status`, never two competing candidates:

```tsx
// components/payroll/payroll-lifecycle-action-button.tsx
'use client';

import { Button } from '@/components/ui/button';
import { PermissionGate } from '@/components/shared/permission-gate';
import type { PayrollRunStatus } from '@/types/payroll';
import { useTranslations } from 'next-intl';

const ACTION_BY_STATUS: Partial<Record<PayrollRunStatus, { labelKey: string; permission: string }>> = {
  draft: { labelKey: 'calculate', permission: 'payroll.run.calculate' },
  calculated: { labelKey: 'submit', permission: 'payroll.run.submit' },
  approved: { labelKey: 'post', permission: 'payroll.run.post' },
  posted: { labelKey: 'disburse', permission: 'payroll.disburse' },
  // pending_officer_approval / pending_finance_approval / pending_ceo_approval / paid / rejected /
  // reversed intentionally have no entry — the Page Header's primary action slot is simply empty,
  // and the applicable control lives on the Approvals or WPS & Disbursement tab instead.
};

export function PayrollLifecycleActionButton({
  status, onAction, disabled,
}: { status: PayrollRunStatus; onAction: () => void; disabled?: boolean }) {
  const t = useTranslations('payroll.lifecycle');
  const action = ACTION_BY_STATUS[status];
  if (!action) return null;
  return (
    <PermissionGate permission={action.permission}>
      <Button onClick={onAction} disabled={disabled}>{t(action.labelKey)}</Button>
    </PermissionGate>
  );
}
```

Because the lookup table has no branch on amount, run type, or caller role beyond the `PermissionGate`
itself, this component cannot render two mutually-exclusive primary actions at once — a defect class the
Journal Entry Composer's own primary button has to actively guard against because a low-value entry there
can legitimately skip its approval tier.

## `PayrollPeriodSelect`

The shared `PeriodPicker`'s `mode="fiscal_period"` is explicitly scoped to the `fiscal_years`/
`fiscal_periods` accounting-close calendar (`COMPONENT_LIBRARY.md → PeriodPicker`) — not a company's
payroll calendar. Payroll runs are scoped to `payroll_periods`, a distinct table with its own `frequency`
(`monthly`/`biweekly`/`weekly`/`semi_monthly`) and `pay_date`. Reusing `PeriodPicker` here would silently
conflate two calendars that are allowed to diverge (a company can close its books monthly while paying
biweekly). `PayrollPeriodSelect` is a thin `Select` over `GET /api/v1/payroll/periods`, grouped by
`status` (`open`/`closed`), keeping the visual vocabulary familiar even though the underlying data source
differs.

## `AnomalyReviewPanel`

Renders the `warnings[]` array `POST /payroll/runs/{id}/calculate` returns, grouped by `severity`
(`critical` first, then `warning`, then `info`), each entry showing the affected employee's name (resolved
client-side from the already-fetched `PayrollItemsTable` rows — no extra request), `type`
(`anomaly`/`compliance`/`fraud`), `message`, and a `ConfidenceBadge` for `ai_confidence`. A `critical`
entry additionally renders an inline "Acknowledge" action requiring a typed reason before Submit unlocks;
`warning` and `info` entries are informational only and never block anything.

```tsx
// components/payroll/anomaly-review-panel.tsx (excerpt)
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

export function AnomalyReviewPanel({ warnings, employeeNamesById, acknowledged, onAcknowledge }: Props) {
  const t = useTranslations('payroll.anomalyReview');
  const [openReasonFor, setOpenReasonFor] = useState<number | null>(null);
  const [reason, setReason] = useState('');

  return (
    <div className="space-y-3">
      {(['critical', 'warning', 'info'] as const).map((severity) => {
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
                  <Button size="sm" variant="outline" onClick={() => setOpenReasonFor(i)}>
                    {t('acknowledge')}
                  </Button>
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

`onAcknowledge`'s collected `(warning, reason)` pairs are held in local mutation state and attached to the
eventual `POST .../submit` call; the reason ultimately lands permanently in
`payroll_run_approvals.override_reason` at the `payroll_officer` stage.

## `PayrollItemsTable`, `PayrollItemBreakdownSheet`

`PayrollItemsTable` is a read-only, plain `<table>` — real `scope="col"` headers, sortable columns as
real `<button>`s — never the ARIA `grid` pattern: `ACCESSIBILITY.md → Data Tables Accessibility` names
exactly two surfaces in the entire platform that warrant the grid pattern (the Journal Entry line editor
and the Bank Reconciliation matching grid), and this screen's Employees tab, being computed, read-only
content with no cell-to-cell keying need, deliberately does not become a third. Clicking a row (or its
"View breakdown" action) opens `PayrollItemBreakdownSheet`, which renders that employee's
`payroll_item_lines` grouped into the four `component_category` sections
(`earning`/`deduction`/`statutory`/`employer_cost`) the schema defines, labeled for this screen as:

| `component_category` | Section label | Contents | Nets against pay? |
|---|---|---|---|
| `earning` | **Earnings** | `base_salary`, `hourly_wage`, `commission`, `bonus`, `allowance`, `overtime`, `night_shift`, `holiday_pay` | Sums into `gross_salary` |
| `deduction` | **Deductions** | Loan/advance installments, other deductions (garnishments, unexcused-absence, disciplinary) | Subtracted to reach `net_salary` |
| `statutory` | **Statutory Withholdings (PIFSS)** | `social_insurance_employee` — the employee's own PIFSS share | Subtracted to reach `net_salary` |
| `employer_cost` | **Employer Contributions (PIFSS + EOS)** | `social_insurance_employer` (the employer's PIFSS share) and `indemnity_accrual` (the End-of-Service indemnity provision) | Informational only — never netted against pay, per `docs/accounting/PAYROLL.md`'s explicit rule that the employer's PIFSS share and the EOS accrual "appear on the payslip's informational 'employer contributions' section, not the deductions section" |

Each line shows its `salary_component` name (bilingual), `quantity`/`rate` where applicable, and its
`SalaryAmountCell` amount, with a running Net Salary total at the foot of the Deductions/Statutory groups.
The Employer Contributions group is the one section on this screen where a genuinely large figure —
`indemnity_accrual`, an employer-cost accrual that is never paid to the employee this period — sits
beside `net_salary` without being mistaken for take-home pay, which is precisely why it is visually and
semantically separated rather than folded into Deductions.

## `PayslipViewer`

Renders one `payslips` row's breakdown (`period`, `gross_salary`, `net_salary`, `lines[]`,
`download_url`), with a language toggle (`en`/`ar`) that switches which `payslips` row (of the
`uq_payslip_item_lang` unique pair per `payroll_item_id`) is being viewed rather than machine-translating
one row client-side — the two language versions are independently generated server-side. "Download" opens
`download_url` (a time-limited signed Cloudflare R2 URL) directly; the viewer never proxies or caches the
PDF bytes itself.

## `WpsExportBatchCard`

Renders one `wps_export_batches` row: `bank_code`, `file_format` (`CBK_SIF`), `total_amount`,
`employee_count`, `status` (`generated`/`submitted`/`acknowledged`/`failed`), `submitted_at`,
`acknowledged_at`, and a signed download link resolved from `file_attachment_id`. A Finance-only "Mark as
submitted" / "Mark as acknowledged" action progresses `status` where no bank API callback is configured —
this screen never assumes an automated bank integration beyond what `docs/accounting/PAYROLL.md → Payment
Methods → Bank Transfer` documents.

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/PAYROLL.md → API`'s table, annotated with which hook and query
key this screen wires it to. All are versioned under `/api/v1/payroll/`, carry `X-Company-Id`, and return
the standard envelope (`success`, `data`, `message`, `errors`, `meta.pagination`, `request_id`,
`timestamp`).

| Method & Path | Permission | Hook | Query key |
|---|---|---|---|
| `GET /payroll/periods` | `.read` | `usePayrollPeriods()` | `payrollKeys.periods()` |
| `POST /payroll/periods` | `.periods.manage` | `useCreatePayrollPeriod()` | invalidates `payrollKeys.periods()` |
| `POST /payroll/runs` | `.run.create` | `useCreatePayrollRun()` | invalidates `payrollRunKeys.lists()` |
| `GET /payroll/runs` | `.read` | `usePayrollRuns(filters)` | `payrollRunKeys.list(filters)` |
| `GET /payroll/runs/{id}` | `.read` | `usePayrollRun(id)` | `payrollRunKeys.detail(id)` |
| `POST /payroll/runs/{id}/calculate` | `.run.calculate` | `useCalculatePayrollRun()` | pessimistic; seeds `detail(id)` and `items(id)` on success |
| `GET /payroll/runs/{id}/items` | `.read` | `usePayrollRunItems(id)` | `payrollRunKeys.items(id)` |
| `POST /payroll/runs/{id}/submit` | `.run.submit` | `useSubmitPayrollRun()` | pessimistic, optimistic status patch on `2xx` only |
| `POST /payroll/runs/{id}/approve` | `.run.approve` | `useApprovePayrollRun()` | pessimistic |
| `POST /payroll/runs/{id}/reject` | `.run.approve` | `useRejectPayrollRun()` | pessimistic |
| `POST /payroll/runs/{id}/post` | `.run.post` | `usePostPayrollRun()` | pessimistic |
| `POST /payroll/runs/{id}/disburse` | `.disburse` | `useDisbursePayrollRun()` | pessimistic; invalidates `wpsBatches(id)` |
| `POST /payroll/runs/{id}/reverse` | `.run.reverse` | `useReversePayrollRun()` | invalidates `detail(id)` + `detail(newDraftId)` + `lists()` |
| `GET /payroll/payslips/{id}` | `.payslip.view` | `usePayslip(id)` | `payrollKeys.payslip(id)` |
| `GET /payroll/employees/{id}/payslips` | `.payslip.view` / `.payslip.view.self` | `useEmployeePayslips(employeeId)` | `payrollKeys.employeePayslips(employeeId)` |
| `POST /payroll/runs/bulk-calculate` | `.run.calculate` | `useBulkCalculatePayrollRuns()` | invalidates `lists()` on settle |
| `GET /payroll/reports/summary` | `reports.payroll.view` | `usePayrollSummaryReport()` | not embedded — cross-linked only |
| `GET /payroll/reports/cost` | `reports.payroll.view` | `usePayrollCostReport()` | not embedded — cross-linked only |

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

`PayrollRunFilters` mirrors the API's own resource shape: `status` (array), `payroll_period_id`,
`run_type` (array), `branch_id`, `q`, `sort`. The List's four named filters map directly: **Status** →
`status`, **Period** → `payroll_period_id`, **Run type** → `run_type`, **Branch** → `branch_id`.

## Cache tuning by data class

Applying `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` to this screen's own resources, without
inventing a bespoke class:

| Data class | Resources on this screen | `staleTime` | Rationale |
|---|---|---|---|
| Transactional lists | `payrollRunKeys.list(filters)` | `30_000` | Identical tier to Journal Entries' own list — frequent enough activity to want a reasonably fresh page, infrequent enough that 30 seconds is not wasteful |
| Rarely-changing reference | `payrollKeys.periods()` | `300_000` (5 min) | A company opens a new payroll period on the order of once a month |
| Single-run detail | `payrollRunKeys.detail(id)` | `30_000`, kept fresh primarily via targeted Realtime invalidation rather than polling | A run in active review benefits from staying current without a background poll competing with the calculation engine's own load |
| AI-sourced warnings | Embedded in the calculation response, not independently cached | n/a | Warnings are a function of the run's current calculated state, re-derived by the API on every read, never independently persisted client-side beyond the current session |

## Client state ownership

| State | Owner |
|---|---|
| The run list, one run's detail, its items, its WPS batches, its history | TanStack Query cache, keyed as above |
| `NewPayrollRunDialog`'s in-progress field values | Local `useState` (three fields — not substantial enough to warrant React Hook Form) |
| The current calculation's `warnings[]` and which critical flags have been acknowledged this session | Local component state on the Detail page, seeded from the last `.../calculate` response and re-seeded from `GET /runs/{id}` echoing the same array for the run's current state |
| List density, column visibility, saved filter presets | Zustand (`useDensityStore`) + `users.settings` sync |
| Active Detail tab | URL search param (`?tab=approvals`) — a direct link to "the Approvals tab of July's run" is shareable, never component-local state alone |
| Selected rows for the List's bulk Recalculate | Local component state inside `DataTable`, cleared on navigation |
| A mutation's `Idempotency-Key`, one per logical action instance | `sessionStorage`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` |

## Mutations — pessimistic almost everywhere

Every mutation that changes the run's lifecycle state is pessimistic — Calculate, Submit, Approve, Reject,
Post, Disburse, Reverse all wait for the server's `2xx` before the UI treats them as done, and all render a
confirming dialog first except Submit (`# Interactions & Flows` explains the exemption). There is no
reversible, low-stakes action on this screen — even a `draft` run's Recalculate is pessimistic, since it
discards and regenerates every `payroll_items` row server-side and the client has no safe optimistic guess
for what dozens or thousands of employees' new totals will be.

```ts
// hooks/payroll/use-payroll-runs.ts (excerpt)
export function useCalculatePayrollRun(runId: number) {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey(`payroll-run-calculate-${runId}`);
  return useMutation({
    // No onMutate — recalculating regenerates every payroll_items/payroll_item_lines row and can
    // legitimately take real time for a large company, so there is nothing safe to guess optimistically.
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
    // Disburse is the single most consequential click on this screen — real money moves as a direct
    // consequence — so it waits for the server's 2xx before the UI treats it as done, with no exception.
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
`useReversePayrollRun` follow the identical shape — no `onMutate`, a client-generated `Idempotency-Key`
per logical attempt, and a confirming `Dialog`/`AlertDialog` restating the run's period, employee count,
and (where `payroll.salary.view` allows) net total before it fires.

## Server-first paint, then Suspense per region

```tsx
// app/(app)/payroll/runs/page.tsx
export const dynamic = 'force-dynamic';
export const fetchCache = 'default-no-store'; // tenant-scoped — never statically cached

export default async function PayrollRunsPage({ searchParams }: { searchParams: PayrollRunFilters }) {
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: payrollRunKeys.list(searchParams),
    queryFn: () => apiServer.get('/payroll/runs', { params: searchParams }),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="space-y-6">
        <PayrollRunsPageHeader />
        <WidgetErrorBoundary widgetId="payroll_runs_table">
          <Suspense fallback={<WidgetSkeleton variant="table" />}>
            <PayrollRunsTable filters={searchParams} />
          </Suspense>
        </WidgetErrorBoundary>
      </div>
    </HydrationBoundary>
  );
}

// app/(app)/payroll/runs/[runId]/page.tsx
export default async function PayrollRunDetailPage({ params }: { params: { runId: string } }) {
  const queryClient = getQueryClient();
  await Promise.all([
    queryClient.prefetchQuery({
      queryKey: payrollRunKeys.detail(params.runId),
      queryFn: () => apiServer.get(`/payroll/runs/${params.runId}`),
    }),
    queryClient.prefetchQuery({
      queryKey: payrollRunKeys.items(params.runId),
      queryFn: () => apiServer.get(`/payroll/runs/${params.runId}/items`),
    }),
  ]);

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <PayrollRunDetailShell runId={params.runId} />
    </HydrationBoundary>
  );
}
```

The Detail route prefetches both the run's core row and its items in parallel — the Employees tab is the
default landing tab, so its data should already be warm on first paint, while Approvals/WPS &
Disbursement/Payslips/History each fetch lazily behind their own tab-activation boundary rather than all
five tabs' data loading up front for a run the viewer may only ever look at one tab of.

## Realtime

Both the List and the Detail page subscribe to the company's private Payroll channel via Echo:

```ts
// hooks/payroll/use-payroll-run-realtime.ts
'use client';
export function usePayrollRunRealtime(companyId: string) {
  const queryClient = useQueryClient();
  useEffect(() => {
    const channel = echo.private(`payroll.${companyId}`);
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
    // A critical AI flag (fraud/compliance) independently notifies the Auditor role regardless of
    // this screen's own state — this hook only refreshes the Anomaly Review panel if it is open.
    channel.listen('.payroll_run.ai_flag_raised', (e: { payroll_run_id: number }) => {
      queryClient.invalidateQueries({ queryKey: payrollRunKeys.detail(e.payroll_run_id) });
    });
    return () => { echo.leave(`payroll.${companyId}`); };
  }, [companyId, queryClient]);
}
```

Every event above only invalidates — never surgically patches a cached object the way a high-frequency
screen's realtime hook might. A company issues one payroll run per period, not dozens of journal entries
per hour, so a refetch-on-invalidate is fast enough and simpler to reason about here.

## AI agents feeding this screen

| Agent | What appears on this screen | Autonomy |
|---|---|---|
| Payroll Validation | Line-by-line validation entries in `AnomalyReviewPanel` (missing components, formula mismatches, pro-ration errors) | Suggest-only |
| Anomaly Detection | Severity-tiered flags in `AnomalyReviewPanel`; a `critical` flag blocks Submit until acknowledged | Suggest-only, blocking on `critical` |
| Fraud Detection | A `danger`-toned entry in `AnomalyReviewPanel` (ghost employee, duplicate bank account/IBAN, unmatched commission, loan-then-resign pattern); independently notifies the Auditor role regardless of what happens here | Suggest-only; escalates out-of-band |
| Compliance Checks | `type: "compliance"` entries citing the specific Kuwait Labour Law article in `message`; `critical` compliance flags block Submit exactly like Anomaly Detection's | Suggest-only, blocking on `critical` |
| Salary Forecasting, Overtime Analysis, Leave Prediction | Not embedded — a "View payroll forecast" cross-link (`reports.payroll.view`) out to their own reporting surfaces, avoiding a second copy of that content inline | Suggest-only (read/report) |
| Payroll Explanation | The Payslips tab's "Explain this payslip" trigger, opening a bilingual reasoning `Sheet` on request | Auto-generate on request (read-only, no state change) |

# Interactions & Flows

**Creating a run.** "New Payroll Run" (`payroll.run.create`) opens `NewPayrollRunDialog`:
`PayrollPeriodSelect` (defaulting to the earliest `open` period with no existing `regular` run yet), a
`run_type` select (defaulting `regular`, options `regular`/`supplementary`/`final_settlement`/
`bonus_run`/`correction`), and — only for multi-branch companies — a `branch_id` select. Submitting calls
`POST /payroll/runs`:

```json
POST /api/v1/payroll/runs
X-Company-Id: 101
{ "payroll_period_id": 44, "run_type": "regular", "branch_id": 3 }
```
```json
{
  "success": true,
  "data": {
    "id": 512, "company_id": 101, "branch_id": 3, "payroll_period_id": 44,
    "run_type": "regular", "status": "draft",
    "total_gross": "0.0000", "total_net": "0.0000", "employee_count": 0, "currency_code": "KWD"
  },
  "message": "Payroll run created in draft status.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "7c2b1e4a-3f9d-4e21-9a10-8b5c7d2e1f00", "timestamp": "2026-07-16T06:00:00Z"
}
```

The dialog closes and the app navigates to `/payroll/runs/512`, landing on the Employees tab's
pre-calculation empty state with **Calculate** (`PayrollLifecycleActionButton`) as the one enabled primary
action. There is no balance check, no line entry, nothing else to fill in — the entire "compose a payroll
run" act is these three fields, which is why this flow is a `Dialog`, never a route.

**Calculating.** Clicking **Calculate** immediately disables the button and shows an indeterminate
"Calculating payroll for this period…" inline state — never a determinate progress bar, since no job-id
or polling endpoint is documented for this action; the calculation is internally chunked and queued but
externally synchronous. The response returns the completed totals directly:

```json
POST /api/v1/payroll/runs/512/calculate
```
```json
{
  "success": true,
  "data": {
    "id": 512, "status": "calculated",
    "total_gross": "148500.0000", "total_net": "121430.5000", "total_employer_cost": "16335.0000",
    "employee_count": 87,
    "warnings": [
      { "employee_id": 2044, "type": "compliance", "severity": "critical",
        "message": "Garnishment deduction would exceed 25% of net pay cap.", "ai_confidence": 0.97 },
      { "employee_id": 2101, "type": "anomaly", "severity": "warning",
        "message": "Overtime hours 42% above 6-month baseline.", "ai_confidence": 0.81 }
    ]
  },
  "message": "Calculation complete for 87 employees with 2 warnings.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "a1f0c9d2-88b1-4a7e-9c3d-201f4e6b7a55", "timestamp": "2026-07-16T06:05:12Z"
}
```

For a company with several thousand employees this request can legitimately outlast QAYD's default
request timeout; the client sets a generous, calculation-specific timeout, and if it is still exceeded
shows "This is taking longer than usual for a large payroll — it's still running" rather than a hard
failure, since the underlying job continues server-side regardless of the browser's own connection. On
success, `data.warnings[]` populates `AnomalyReviewPanel` on the Approvals tab and a compact "2 warnings,
1 critical" badge appears on both the Detail page's header and the corresponding List row.
**Recalculating** a `draft` or `calculated` run behaves identically and is explicitly non-destructive to
review in progress: the engine deletes and regenerates `payroll_items`/`payroll_item_lines` rather than
appending, so a Payroll Officer who approves a late leave request or corrects an attendance record
mid-review can simply click Recalculate again before Submit — the previous, now-stale
`AnomalyReviewPanel` state is discarded and replaced by the new response's own `warnings[]`, never merged
with the old set.

**Employees excluded from calculation.** A response whose `employee_count` is lower than the company's
total active headcount for that branch/period surfaces a dismissible banner above `PayrollItemsTable`: "N
employees were not included — not payroll-ready," reflecting a `payroll_ready` gate (missing bank details,
missing a base salary component, or an unrecognized `country_code`). The banner links to the Employee
Master screen's own readiness checklist rather than attempting to resolve the gap here, since this screen
owns calculation, not onboarding.

**Reviewing and acknowledging anomalies.** `AnomalyReviewPanel` groups every warning by severity. A
`critical`-severity entry's "Acknowledge" action requires a typed reason before it can be confirmed.
**Submit for approval** stays disabled, with an explicit `aria-describedby` reason ("2 critical flags
still need acknowledgment"), until every `critical` entry has one; `warning`/`info` entries never block
anything. This is the one meaningful gate this screen enforces client-side ahead of the server, and it is
purely a friendly, avoid-a-wasted-round-trip check — the actual enforcement is the API's own rule that a
critical flag "blocks Submit until acknowledged," re-checked server-side regardless of what the client
believes it already confirmed.

**Submitting.** Unlike Post/Disburse/Reverse, **Submit does not open a confirming dialog** — the
just-completed calculation review and any required anomaly acknowledgments already are the affirmative,
already-visible confirmation a financial mutation needs, and a second modal stacked on top of an
already-deliberate multi-step review would be friction without a corresponding safety benefit.
`POST .../submit` moves `calculated → pending_officer_approval` unconditionally — there is no
approval-exempt direct-post path for any payroll run, regardless of amount.

**Approving at each stage.** The Approvals tab renders all three stages as a sequence of `ApprovalCard`s
(`kind="payroll_release"`), matching the currently-pending stage against the viewer's role
(`payroll_officer`/`finance_manager`/`ceo`); only that one card is interactive — a CFO or CEO cannot
accidentally approve stage one out of order before a Payroll Officer has. Every Approve on this
screen — at all three stages — opens a confirming dialog restating the run's period, employee count, and
(where `payroll.salary.view` allows) net total before it submits:

```json
POST /api/v1/payroll/runs/512/approve
{ "comment": "Reviewed totals against last month; variance explained by 2 new hires." }
```
```json
{
  "success": true,
  "data": {
    "id": 512, "status": "pending_ceo_approval",
    "approvals": [
      { "stage": "payroll_officer", "approver_id": 55, "decision": "approved", "decided_at": "2026-07-16T07:10:00Z" },
      { "stage": "finance_manager", "approver_id": 12, "decision": "approved", "decided_at": "2026-07-16T09:22:41Z" }
    ]
  },
  "message": "Approved at Finance Manager stage. Awaiting CEO approval.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "5e8a3c11-0d4f-4b6a-8e19-6c2f1a9d3b77", "timestamp": "2026-07-16T09:22:41Z"
}
```

Rejecting at any stage requires a typed reason and returns the run fully to `draft` — there is no "resume
from where it was rejected," so the rejection reason is shown prominently on the now-`draft` run, and
Recalculate/Submit must both be run again from scratch. Approving the **CEO** stage triggers Posting
synchronously in the same request cycle; the UI simply reflects whatever terminal status the response
actually contains (ordinarily `posted` already) rather than rendering an intermediate "approved, about to
post" state the backend does not meaningfully expose.

**Posting.** For the rare case where the CEO-approval response has not already resolved to `posted` (a
transient `approved` state briefly observed), the Page Header's `PayrollLifecycleActionButton` reads
"Post," gated `payroll.run.post`. Clicking it opens a confirming `Dialog` restating the amount and
employee count before firing `usePostPayrollRun()`.

**Disbursing (releasing funds).** Once `posted`, the WPS & Disbursement tab's primary action reads
"Disburse" (`payroll.disburse`), opening a confirming `Dialog` naming the total net amount, employee
count, and payment-method breakdown — "You are about to disburse KWD 121,430.500 net pay to 87 employees
— 82 via bank transfer (WPS), 3 by cheque, 2 by cash." Confirming calls `POST .../disburse`:

```json
{
  "success": true,
  "data": {
    "id": 512, "status": "paid", "paid_at": "2026-07-28T10:15:00Z",
    "wps_batch": {
      "id": 88, "bank_code": "NBK", "file_format": "CBK_SIF",
      "total_amount": "104200.0000", "employee_count": 82, "status": "generated"
    }
  },
  "message": "Disbursement initiated for 87 employees.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "9b6f2d18-4c71-4e0a-8b52-1a7d3e9c0f66", "timestamp": "2026-07-28T10:15:00Z"
}
```

On success, the tab immediately shows the newly generated `wps_export_batches` row
(`status: 'generated'`) via `WpsExportBatchCard`, with a "Download SIF file" link resolving the batch's
signed `file_attachment_id` URL, and each `bank_transfer` `payroll_items` row's `payment_status` flips
from `pending` toward `paid` once Finance marks the batch submitted/acknowledged — there is no bank-API
callback assumed beyond what `docs/accounting/PAYROLL.md → Payment Methods → Bank Transfer` documents; a
manual portal upload is the default workflow, and this screen's own affordance for progressing
`wps_export_batches.status` is a plain "Mark as submitted" / "Mark as acknowledged" action for Finance. A
`cash`/`cheque` line's disbursement is recorded through its own `payroll_cash_disbursements`/
`payroll_cheque_issuances` cross-link, visible as a status chip per row but out of this screen's direct
editing scope.

**A WPS line fails.** A bank-rejected line sets that one `payroll_items.payment_status = 'failed'`
without rolling back the rest of the batch. `PayrollItemsTable` renders a `failed` row with a
`danger`-toned status chip and a scoped "Re-issue payment" row action (`payroll.disburse`) that opens a
small `Dialog` for corrected bank details and generates a fresh, smaller `wps_export_batches` entry for
just that employee — never re-touching the successful lines.

**Reversing.** "Reverse" (`payroll.run.reverse`, overflow menu, `posted`/`paid` only) opens a `Dialog`
collecting a mandatory reason. Submitting calls `POST .../reverse`; the response links both records
(`payroll_runs.reversed_run_id` on the original, a fresh `draft` run pre-populated with corrected inputs
on the other end), and the Detail page immediately shows a "Reversed by [new draft run] →" cross-link once
the mutation settles. Reversal is always whole-run — there is no per-employee partial-reversal UI; a
single employee's error inside an otherwise-correct paid run is instead corrected via an `arrears`
component in the *next* run, never a same-run edit this screen would have no way to expose safely.

**Working the Payslips tab.** Each row shows generation status (`payslips` rows exist per language once
the run reaches `posted`) and `is_visible_to_employee`, which only flips `true` once that employee's own
`payment_status` reaches `paid` — a payslip can therefore be fully generated and viewable by HR/Finance on
this screen while still showing "Not yet released to employee" for the employee's own self-service view,
and this screen never implies otherwise:

```json
GET /api/v1/payroll/payslips/9931
```
```json
{
  "success": true,
  "data": {
    "id": 9931, "employee_id": 2044, "language": "ar", "period": "2026-06-01 to 2026-06-30",
    "gross_salary": "950.0000", "net_salary": "801.2500", "currency_code": "KWD",
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
  "errors": [], "meta": { "pagination": null },
  "request_id": "c4d7e2f8-11a9-4b3d-9f0e-77a8b1c5d9e2", "timestamp": "2026-07-16T10:00:00Z"
}
```

"View" opens `PayslipViewer` in a `Sheet`; "Explain" opens the bilingual AI reasoning `Sheet` for that
same payslip on request (`# AI Integration`).

**Bulk recalculate from the List.** Selecting ≥1 `draft`/`calculated` row swaps the Filter Bar for the
Bulk Action Bar's single "Recalculate" action, firing one `POST /payroll/runs/bulk-calculate` call rather
than N individual calls, and reports the aggregate result — "3 runs recalculated, 1 skipped (not in a
recalculable status)" — never silently dropping the skipped row without explanation.

**A validation error.** Every mutating action on this screen surfaces a `422` through the shared
`useApiToast`/form-error mapping rather than a silent failure — for example, a related loan-creation
attempt reached via this screen's cross-link:

```json
POST /api/v1/payroll/loans  { "principal_amount": -500 }
```
```json
{
  "success": false, "data": null, "message": "Validation failed.",
  "errors": [{ "field": "principal_amount", "code": "min_value",
               "message": "principal_amount must be greater than 0." }],
  "meta": { "pagination": null },
  "request_id": "e19f2a4c-6b3d-4a1e-9c8f-3d0b7e5a1f44", "timestamp": "2026-07-16T10:05:00Z"
}
```

# AI Integration

AI touches this screen at exactly three points, never a separate "AI tab": **validating** (the
calculation response's `warnings[]`, rendered in `AnomalyReviewPanel`), **explaining** (the Payslips tab's
on-request reasoning `Sheet`), and **escalating** (Fraud Detection's independent, out-of-band Auditor
notification). Per the platform's non-negotiable rule, no AI output on this screen ever calculates,
submits, approves, posts, or disburses anything by itself — there is no "Do it" affordance anywhere on
this screen, structurally, because every one of the eight agent behaviors in
`docs/accounting/PAYROLL.md → AI Responsibilities` is `Suggest-only`. The `AIProposalPanel`/three-button
pattern `FRONTEND_ARCHITECTURE.md → AI Integration Layer` describes for actionable recommendations
elsewhere in the product simply has no instance on this screen, because Payroll has no agent whose
autonomy is ever `auto`.

**Payroll Validation and Anomaly Detection.** Both land in `AnomalyReviewPanel`, distinguished only by
`type` — the panel does not visually separate them into two sub-panels, since a Payroll Officer reviewing
a run does not need to know which internal agent produced a given flag, only its severity and what it
says. The worked example above — `{ employee_id: 2044, type: "compliance", severity: "critical",
message: "Garnishment deduction would exceed 25% of net pay cap.", ai_confidence: 0.97 }` — renders as a
`critical` card in the Compliance-grouped section, requiring acknowledgment before Submit, with
`ConfidenceBadge` showing 97% and, on hover/focus, the same `message` string as the reasoning tooltip —
this endpoint's shape has no separate `reasoning` field, so `ConfidenceBadge`'s tooltip slot receives the
same string rather than requesting a field the API does not return.

**Compliance Checks.** Always cites the specific statute in `message` — a message reading "Overtime
multiplier 1.15× is below the statutory minimum 1.25× (Kuwait Labour Law No. 6/2010, Art. 66)" renders
verbatim, never paraphrased or truncated, since the citation is precisely what lets a Finance Manager
verify the flag against the actual law rather than trusting the AI's bare assertion.

**Fraud Detection.** Never renders a raw internal fraud score — the score is an internal signal the API
does not expose for direct display. Its visible consequence on this screen is a `danger`-toned entry in
`AnomalyReviewPanel` (e.g., "This employee's IBAN is also registered to employee #2098" for a
duplicate-bank-account flag, or "No attendance or login activity recorded since hire" for a suspected
ghost employee) and, independently of anything a Payroll Officer does with that card, a
`notifications`/Realtime push to every user holding the Auditor role — this screen does not gate that
escalation behind acknowledgment, and acknowledging the card here (or ignoring it) never suppresses the
independent Auditor notification, so a compromised Payroll Officer account cannot suppress the alert.

**Payroll Explanation.** Triggered on demand from the Payslips tab's "Explain" action, powering a
bilingual reasoning `Sheet` generated in the viewer's own session locale — never machine-translated
client-side. The `Sheet`'s content is always labeled "AI-generated explanation — verify against the
payslip," rendered as static, unremovable caption text rather than a dismissible banner a user could
permanently hide.

**Confidence display.** Every `ai_confidence` field this screen encounters (payroll run calculation
warnings) is already a `0.00–1.00` fraction, so every `ConfidenceBadge` on this screen calls
`normalizeConfidence(value, 'fraction')` — never `'percentage'`, which is reserved for the AI Command
Center's separately-scaled `ai_decisions.confidence_score` (0–100). Passing the wrong mode would silently
render a 97%-confident compliance flag as "1% confidence," so this is stated explicitly rather than left
to a component author's assumption.

**No autonomous execution, anywhere.** To restate the single most important sentence in this section:
every agent in `docs/accounting/PAYROLL.md → AI Responsibilities`'s table is marked `Suggest-only`,
several with an additional blocking behavior on `critical` severity, and none with `auto` autonomy. A
payroll run's calculation, submission, approval, posting, and disbursement are, without exception,
human-initiated clicks on this screen, gated by the permission table in `# Route & Access` — the AI
layer's entire footprint here is advisory content rendered alongside those clicks, never a replacement for
one of them.

# States

No region on this screen falls through to a bare blank void; every state below is a deliberate rendering,
never an accident of an unhandled response shape.

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | List: `Skeleton` rows shaped like the table; Detail: header/stepper/tab-bar skeleton |
| Empty (no runs at all) | Brand-new company, zero `payroll_runs` rows | `EmptyState`: "No payroll runs yet," CTA "New Payroll Run" gated `.create` |
| Empty (filtered to nothing) | Filters legitimately produce zero rows | Lighter `EmptyState` variant, "No runs match your filters" |
| Draft, pre-calculation | `status: 'draft'`, `employee_count: 0` | Employees tab shows an empty-state illustration and "Calculate" as the only enabled action; Approvals/WPS & Disbursement/Payslips tabs render a locked "Available after calculation" placeholder |
| Draft, calculated | `status: 'calculated'` | `PayrollItemsTable` populated; Anomaly Summary banner if `warnings[]` non-empty; Submit enabled once every `critical` flag is acknowledged |
| Draft, calculated, critical flags outstanding | ≥1 unacknowledged `critical` warning | Submit disabled with an explicit `aria-describedby` reason naming the count; `AnomalyReviewPanel`'s Acknowledge actions are the only path forward |
| Pending approval — viewer is the current-stage approver | `status IN ('pending_officer_approval','pending_finance_approval','pending_ceo_approval')`, viewer's role matches the open stage | That stage's `ApprovalCard` fully interactive (Approve/Reject) |
| Pending approval — viewer is not the current-stage approver | Same statuses, viewer's role does not match | All three `ApprovalCard`s render read-only; the open stage shows "Awaiting [Role]" rather than an actionable control |
| Approved (transient) | `status: 'approved'` | Rare to observe — the CEO-approval response usually already reflects the synchronous post; if seen, renders "Approved — posting…" |
| Rejected | `status: 'rejected'` | Read-only until reopened; the rejection reason and rejecting stage are surfaced prominently on the Approvals tab; Recalculate/Submit both required again from a `draft`-equivalent starting point |
| Posted | `status: 'posted'` | Employees/Approvals/Payslips tabs fully read-only; WPS & Disbursement tab's "Disburse" is the only remaining lifecycle action |
| Paid | `status: 'paid'` | Fully read-only; `is_visible_to_employee` flips true per employee as each one's `payment_status` reaches `paid`; overflow menu offers Reverse |
| Reversed | `status: 'reversed'` | Read-only, with a persistent cross-link to the new draft run it generated |
| WPS batch generated / submitted / acknowledged | `wps_export_batches.status` progression | `WpsExportBatchCard` reflects each status with its own tone; "acknowledged" is the terminal, success-toned state |
| WPS batch failed / a specific line failed | `wps_export_batches.status = 'failed'` or a single `payroll_items.payment_status = 'failed'` | Batch-level failure surfaces a banner with a retry path; a single failed line shows a scoped `danger` chip and "Re-issue payment" row action, never blocking the rest of the batch |
| Salary masked | Viewer lacks `payroll.salary.view`, or the API returned `null` for a monetary field | Every `SalaryAmountCell` renders the redacted glyph + lock + tooltip |
| Version conflict | `409` on a mutation whose underlying state changed since the page loaded (e.g., another user approved the same stage moments earlier) | Toast: "This run was updated by someone else — reload to see the latest state"; no silent overwrite |
| Session permission revoked mid-view | A subsequent mutation attempt returns `403` after the permission set changes | The affected control collapses to its disabled-with-tooltip form; `SalaryAmountCell` re-masks on the next render regardless of what the stale cache still holds |
| Error | `403` / `404` / `5xx` / network failure | Shared `403` boundary, `not-found.tsx`, or `ErrorState` with retry, per which failure occurred |

# Responsive Behavior

Payroll follows `RESPONSIVE_DESIGN.md`'s five semantic device tiers exactly — Mobile (unprefixed), Tablet
(`md:`, 768px), Laptop (`lg:`, 1024px), Desktop (`xl:`/`2xl:`, 1280/1536px), Ultra Wide (`3xl:`, 1920px) —
never a bespoke breakpoint of its own.

| Tier | Behavior |
|---|---|
| Mobile (<768px) | **List:** rows become cards, each headed by the period label and `StatusPill`, with a two-column definition list of run type, employee count, and pay date; the Gross/Net figures collapse to a single `SalaryAmountCell` line (masked or not) rather than two separate columns. Filters collapse behind a single badge-counted "Filters" trigger opening a `Sheet`; the density toggle is hidden. **Detail:** the Lifecycle Stepper collapses from a seven-stop horizontal rail to a single-line compact summary — "Stage 3 of 6 · Awaiting Finance Manager" — with a tap target expanding the full stepper into a bottom `Sheet` on demand. The five tabs become a horizontally-scrollable `Tabs` row (never a `Select` dropdown). `PayrollItemsTable` transforms to a card list, each card showing name, department, gross, net, and payment status, with remaining fields reachable via the card's own row-click `Sheet`. |
| Tablet (768–1023px) | **List:** unchanged card layout gains a denser two-column card grid. **Detail:** the Stepper renders in full but at reduced label density (stage names only, no sub-caption); `PayrollItemsTable` gains its second and third priority columns rather than fully collapsing to cards. |
| Laptop (1024–1279px)+ | The full layout in **Layout & Regions**: the real `<table>` List with every filter visible, and the Detail page's full seven-stop Stepper, five-tab bar, and `PayrollItemsTable` at full column width. |
| Desktop (1280px+) | Unchanged from Laptop at more generous margins; `PayrollItemsTable` gains the sticky-first-column (employee name) horizontal-scroll treatment for any company whose payroll includes many optional dimension/component columns too wide to fit without scrolling even at this tier. |
| Ultra Wide (≥1920px) | Unchanged bento shape, content capped at `max-w-[1440px]`; the platform's global AI Rail companion panel may dock alongside this screen without competing with any region here, since Payroll has no persistent ambient panel of its own. |

**Virtualization.** `PayrollItemsTable` virtualizes past roughly 200 rows via `@tanstack/react-virtual`,
identical in mechanism to the Journal Entries List and its own line collection, since a several-thousand-
employee company's Employees tab is exactly the realistic large-collection case this pattern exists for.
The size estimator reads the active tier (dense rows at Laptop-and-up, taller card rows at
Mobile/Tablet) so the same data renders correctly at every width without a bespoke per-screen
virtualization config.

**Touch targets.** Every interactive element — the Acknowledge button, `ApprovalCard`'s Approve/Reject
pair, row actions, the Stepper's mobile expand trigger — meets the platform's 44×44px minimum hit area
with an 8px minimum gap between adjacent controls, material here specifically because Approve and Reject
sit side by side and a mis-tap carries real financial consequences. Approving a sensitive stage from a
mobile session additionally requires a biometric re-confirmation step layered on top of, never instead of,
the ordinary permission and confirming-dialog checks.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, inheriting `THEMING.md`'s
RTL-as-a-theming-dimension and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a single per-component
RTL branch.

- **Logical properties only.** The Filter Bar's control order, the Stepper's reading order, and every
  card's icon/label pairing use `ms-*`/`me-*`/`text-start`/`text-end` exclusively; flipping `dir="rtl"`
  mirrors the whole screen with zero screen-specific code.
- **Numerals, currency codes, and confidence percentages never mirror.** Every `AmountCell`, every
  `ConfidenceBadge` percentage, and any run-number reference renders inside a `dir="ltr"`/
  `unicode-bidi: isolate` span so a figure never reorders inside a right-to-left sentence.
- **Numeric alignment is physically fixed (the platform's documented Exception A).** The List's Gross/Net
  columns and every monetary figure use hard-coded `text-right`, not `text-end`, in both directions, so a
  bilingual Finance team scanning magnitude and decimal alignment sees figures land on the same edge
  regardless of interface language. `SalaryAmountCell`'s redacted glyph carries the same `dir="ltr"`
  wrapper as a real figure, so a column of masked and unmasked amounts stays visually aligned.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching the Gulf
  financial-document convention that a mixed numeral system inside a single payroll figure reads as an
  error to an accountant.
- **Bilingual employee and salary-component names.** `PayrollItemsTable`, `PayrollItemBreakdownSheet`, and
  `PayslipViewer` all render `name_ar` under an Arabic session and fall back to `name_en` only if the
  Arabic name is genuinely absent — never the reverse.
- **AI-authored reasoning is never machine-translated by the frontend.** The compliance flag's statute
  citation and the Payroll Explanation `Sheet`'s narrative both arrive already localized from the API's
  own content-negotiation contract; this screen's own localization responsibility is limited to chrome —
  button labels, empty-state copy, dialog titles — never the model's own words.
- **The WPS SIF bank file's own internal field layout never localizes.** The Ministry/bank-mandated fixed
  format is identical regardless of which language the Finance Manager who clicked "Disburse" was working
  in — the UI's surrounding chrome (button labels, the batch status chip) is fully bilingual, but the
  generated file's actual field order and encoding never vary by session language.
- **Payroll-specific Arabic terminology** is used precisely and consistently:

| Context | English | Arabic |
|---|---|---|
| Page title | Payroll Runs | كشوف الرواتب |
| Status: draft | Draft | مسودة |
| Status: calculated | Calculated | تم الاحتساب |
| Status: pending Payroll Officer | Pending Officer Approval | بانتظار اعتماد مسؤول الرواتب |
| Status: pending Finance Manager | Pending Finance Approval | بانتظار اعتماد المدير المالي |
| Status: pending CEO | Pending CEO Approval | بانتظار اعتماد الرئيس التنفيذي |
| Status: approved | Approved | معتمد |
| Status: posted | Posted | مرحّل |
| Status: paid | Paid | مدفوع |
| Reverse | Reverse | عكس |
| Social insurance (PIFSS) | PIFSS / Social Insurance | التأمينات الاجتماعية |
| End-of-service indemnity | End-of-Service Indemnity | مكافأة نهاية الخدمة |
| Wage Protection System | WPS | نظام حماية الأجور |
| Payslip | Payslip | قسيمة الراتب |
| Restricted figure tooltip | Restricted — requires the payroll salary view permission | مقيّد — يتطلب صلاحية عرض الرواتب |

Arabic copy on this screen is authored directly by a fluent professional-register writer, never
machine-translated from the English column above — a fraud-hold-equivalent compliance banner or a
confirming-dialog sentence that reads precise and calm in English must read identically precise and calm
in Arabic.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a bare Tailwind palette utility outside the shared component files it
composes.

- **Surfaces and elevation.** The List and Detail canvases sit on `--surface-canvas`; every `Card` region
  (Anomaly Review groups, `PayrollItemBreakdownSheet`'s line-group sections, `WpsExportBatchCard`) sits on
  `--surface-base`; every `Dialog`/`Sheet`/`Tooltip` (confirmations, the AI reasoning `Sheet`,
  `SalaryAmountCell`'s tooltip) sits on `--surface-overlay`, each carrying its paired border token even
  where its light-mode equivalent relies on shadow alone.
- **`StatusPill domain="payroll_run"` tones**, fixed platform-wide and not re-derived on this screen:
  `draft` → neutral, `calculated` → accent (ready for the next human step), the three
  `pending_*_approval` values → warning, `approved` → accent (transitional), `rejected` → danger,
  `posted` → **accent, not success** — a deliberate distinction: `posted` means the accounting liability
  is recorded, but no cash has left the company yet, so labeling it `success` this early would overstate
  what has actually happened — `paid` → success (cash has genuinely moved), `reversed` → neutral.
- **Anomaly severity tones map to the platform's existing three-tier semantic set, never a bespoke fourth
  color**: `critical` → `--status-error`, `warning` → `--status-warning`, `info` → a neutral `--ink-500`.
- **The masked salary glyph is never status-colored.** `SalaryAmountCell`'s redacted state renders in
  `--ink-500` (a neutral, "information withheld" tone) in both themes — never `--status-warning` or
  `--status-error`, since a masked figure is not itself a problem to flag, only an access boundary to
  communicate; conflating the two would make a Senior Accountant's entirely normal, expected view of a run
  look like something is wrong with it.
- **AI provenance uses `--ai-accent` exclusively**, never a finance-status hue, for every `ConfidenceBadge`
  on this screen — a `critical`-severity, high-confidence compliance flag is exactly the case where it
  would be tempting (and wrong) to conflate "the AI is very sure" with "this is very bad" into one color;
  the two remain visually independent, side by side.
- **Contrast.** Every pairing above, including the redacted glyph's own lock-icon-plus-text combination,
  is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text) in both themes.
- **Exports render light, always.** A PDF/XLSX run summary export, and every generated payslip PDF, render
  in QAYD's fixed light/print palette regardless of the exporting or generating user's active theme — a
  payslip is routinely handed to an employee or a bank who has never opened the dark-mode app.
- Every Storybook story for `PayrollItemsTable`, `SalaryAmountCell`, and `AnomalyReviewPanel` ships the
  platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and
  this screen's own Playwright suite captures the same four-way screenshot set at the route level.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented without deviation, plus the Payroll-specific
applications below.

- **`PayrollItemsTable` uses the plain, accessible `<table>` pattern — never the ARIA `grid`.**
  `ACCESSIBILITY.md → Data Tables Accessibility` names exactly two surfaces in the entire platform that
  warrant the grid pattern (the Journal Entry line editor and the Bank Reconciliation matching grid); this
  screen's Employees tab is read-only, computed content with no cell-to-cell keying need and deliberately
  does not become a third. Real `scope="col"` headers, sortable headers as real `<button>`s with
  `aria-sort`, and a uniquely-worded `aria-label` on every row action ("View breakdown for Fahad Al-Ali,"
  never a bare "View").
- **A masked figure has a real, distinct text equivalent for assistive technology.**
  `SalaryAmountCell`'s `VisuallyHidden` text reads "Restricted — requires the payroll salary view
  permission," never merely re-announcing the visual `•••.•••` glyph or, worse, silence — a screen-reader
  user must be able to tell the difference between "this figure is zero" and "this figure is withheld
  from you" exactly as clearly as a sighted user can from the lock icon.
- **RBAC-disabled and business-rule-disabled controls explain themselves, and explain themselves
  differently.** A Submit button disabled for lacking `payroll.run.submit` carries an `aria-describedby`
  naming that permission; the same button disabled because critical anomalies remain unacknowledged
  carries a distinct `aria-describedby` naming the exact count and linking conceptually to
  `AnomalyReviewPanel` — the two are never collapsed into one generic "You can't do this right now."
- **A sensitive action with no permission is omitted, not disabled.** Disburse, Post, Reverse, and the
  Approve/Reject controls at a stage the viewer cannot act on are removed from the DOM for a role lacking
  the corresponding permission — never rendered disabled with no explanation.
- **The Lifecycle Stepper is a real ordered list**, not a row of styled `<div>`s: each stage is a `<li>`
  with `aria-current="step"` on the active one and a `VisuallyHidden` status word ("completed," "current,"
  "upcoming," "rejected") beyond what color alone conveys.
- **Focus management on overlays.** Every `Dialog`/`Sheet`/`AlertDialog` on this screen (confirmations, the
  anomaly-acknowledgment reason field, `PayslipViewer`, the AI reasoning `Sheet`) moves focus to its
  heading on open and returns focus to the triggering control on close.
- **Keyboard.** `G` then `P` opens the List; every confirming `Dialog`/`AlertDialog`'s primary action
  responds to `Cmd`/`Ctrl+Enter`; `Tab` order through `AnomalyReviewPanel` moves severity group by
  severity group, critical first, matching its own visual priority.
- **Realtime updates never yank focus or silently splice.** A `.payroll_run.posted`/`.payroll_run.paid`
  push affecting the run a user currently has open updates its cached status without moving keyboard focus
  or reordering a List a second window might have open.

# Performance

- **Server-paginated, never client-sorted or client-filtered.** The List's `DataTable` issues a new
  request for every sort, filter, or page change; a company with hundreds of historical runs never ships
  more than one page's worth to the browser.
- **Virtualization past ~200 rows** on `PayrollItemsTable`, using `@tanstack/react-virtual` with a
  density- or breakpoint-aware row-height estimate.
- **Cache tuning matches data volatility**, per `# Data & State → Cache tuning by data class`: the List's
  `staleTime` is 30 seconds (transactional-list tier, identical to Journal Entries); `payroll/periods`
  reference data is 5 minutes; a single open run Detail is additionally kept fresh via targeted Realtime
  invalidation rather than polling.
- **The calculation request gets its own extended client-side timeout**, distinct from the platform
  default, reflecting the calculation engine's chunked, queued, potentially longer-running work for large
  companies — the UI never treats a slow calculation as a failure purely because it outran an ordinary
  CRUD-sized timeout.
- **Idempotent, single-attempt mutations.** Every write endpoint this screen calls carries a
  client-generated `Idempotency-Key` scoped to one logical submission attempt, so a slow response on a
  flaky connection followed by an impatient second tap can never double-calculate, double-approve,
  double-post, or double-disburse a run — the last of which would be a genuinely severe incident
  (duplicate salary payment) if it were ever allowed to occur.
- **Payslip PDFs are pre-generated server-side, never client-rendered.** Payslip generation is queued and
  parallelized, one job per employee, specifically so posting a large run does not block on rendering
  hundreds of PDFs synchronously; this screen's `PayslipViewer` and Payslips tab simply reflect whatever
  generation status the API currently reports and link to the signed `download_url` once ready.
- **Deferred, code-split overlays.** `NewPayrollRunDialog`, every confirming `Dialog`, `PayslipViewer`, and
  the AI reasoning `Sheet` are dynamically imported (`next/dynamic`) rather than bundled into the List's or
  Detail's initial route chunk, since none of them is needed for first paint.
- **SSR-seeded first paint.** Both the List's first page and a Detail route's initial fetch happen
  server-side and hydrate directly into the TanStack Query cache, so the client's first `useQuery` for the
  same keys resolves instantly rather than re-issuing a request the server already made.
- **Bundle and Web Vitals.** The route's shell is held to the platform's stated first-load JS budget,
  enforced in CI against `performance-budgets.json`; LCP is measured against `PayrollItemsTable`'s first
  meaningful row paint on the Detail page, and INP is watched specifically on the Approve/Reject buttons
  and the Disburse confirmation, the two highest-stakes interactive surfaces on this screen.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A bare hit to `/payroll` | Resolves to the nearest `not-found` boundary — there is no `app/(app)/payroll/page.tsx` at the module's own root segment. Every in-product link (sidebar, breadcrumb, any AI-surfaced target) points at `/payroll/runs` directly, so this path is reachable only via a hand-typed or stale-bookmarked URL. |
| A `SalaryAmountCell` receives `amount: null` alongside an otherwise fully-populated row | Renders the redacted glyph regardless of any other field's presence — `null` is never coerced to `"0.0000"` or silently treated as a formatting error; this is the single highest-stakes rendering rule on this screen |
| A stale permission snapshot still shows `payroll.salary.view` as granted after it was revoked mid-session | `SalaryAmountCell` re-checks `usePermission` on every render regardless of what a previously-cached amount string still holds, and re-masks immediately — never trusting the first-load permission snapshot as permanently valid |
| Mid-period hire/termination pro-ration | Not independently computed by the frontend — `PayrollItemBreakdownSheet` simply displays whatever `proration_factor` and line amounts the calculation engine returned, adding only a small caption ("11/30 days — hired Jul 20") reading directly from the API's own fields, never re-deriving the fraction itself |
| An employee's gross pay for the period is legitimately `0.0000` (zero attendance, hourly-wage component) | Rendered as a real, unmasked `0.000`, visually distinct from a masked figure — a Payroll Officer must be able to tell "this employee earned nothing this period" apart from "I'm not allowed to see this employee's figure," which is exactly why `SalaryAmountCell` treats `null` and `"0.0000"` as opposite, never adjacent, cases |
| Negative net pay would occur (deductions/installments exceed gross) | Not shown as negative — the calculation engine already caps this server-side and defers the excess installment to the next period; this screen's only responsibility is to surface the resulting system note/warning in `AnomalyReviewPanel`, never to independently clamp or recompute a figure |
| An employee is transferred mid-period between branches with different overtime rules | `PayrollItemBreakdownSheet` shows two attendance-derived line groups (pre-/post-transfer) rather than one blended figure, reflecting the calculation engine's own split, never a frontend-side merge |
| A resigned employee's final settlement can't fully cover an outstanding loan | The Employees tab's breakdown for that employee shows the loan's remaining `outstanding_balance` alongside the settlement, with a "Flagged for HR/Finance follow-up" note — the screen never implies the balance was silently written off |
| A bank rejects one WPS line | Only that `payroll_items` row shows `payment_status: 'failed'` with a scoped "Re-issue payment" action; the rest of the batch and the run's own status are entirely unaffected |
| An AI anomaly flag turns out to be a false positive (e.g., a legitimate large one-off bonus) | The Payroll Officer's typed acknowledgment reason is permanently retained and visible on the History tab; the system does not auto-suppress the same flag shape in a future run based on this one override |
| Two browser tabs; one approves a stage of a run while the other is mid-review of the same run | The idle tab's next action against that run fails with a `409` conflict response (`# States`), and this screen re-fetches the run fresh rather than trusting stale local state |
| Company switch mid-review | Switching companies discards all cached Payroll Runs state, including any in-progress, not-yet-submitted anomaly acknowledgments; if any exist, the switcher's own confirmation dialog names the in-progress run specifically before proceeding |
| Session permission revoked mid-view | The next mutation attempt's `403` collapses the affected control to its disabled-with-tooltip form and shows a toast explaining why; `SalaryAmountCell` independently re-masks per the permission-recheck rule above regardless of which mutation triggered the discovery |
| Realtime push arrives while a user is mid-review of `AnomalyReviewPanel` or mid-typing an acknowledgment reason | The push only invalidates cached queries; it never overwrites in-progress local acknowledgment state or a half-typed reason — the user's next explicit action (Recalculate, Submit) is what actually reconciles against the fresher server state |
| Reconnecting after an extended WebSocket disconnect | Every Payroll realtime-fed query key is invalidated once on reconnect, since frames broadcast while disconnected are permanently missed — a stale cache is never silently trusted as current |
| A very large run (thousands of employees) | `PayrollItemsTable` virtualizes past ~200 rows; the calculation request's extended timeout and indeterminate progress messaging apply specifically to this case |
| A company operates a non-monthly payroll frequency (`biweekly`/`weekly`/`semi_monthly`) | `PayrollPeriodSelect` and the List's Period filter both reflect `payroll_periods.frequency` as configured per company — this screen never assumes monthly, even though it is the overwhelmingly common Kuwait practice |
| Printing a payslip or run summary directly via the browser rather than Export | Not specially handled — users are steered toward the Export/Download actions instead, the only paths that produce a durable, correctly light-themed, bank-and-employee-safe document |

# End of Document
