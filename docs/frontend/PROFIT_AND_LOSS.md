# Profit & Loss (Income Statement) — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PROFIT_AND_LOSS
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Profit & Loss screen — the Income Statement
(Statement of Profit or Loss) rendering of the Accounting Engine's Financial Statements module
(`docs/accounting/FINANCIAL_STATEMENTS.md → Income Statement`). "Profit & Loss" is this product's
colloquial, Gulf-SME-familiar screen title; the formal statement name a controller, auditor, or exported
PDF sees is resolved through the exact same `financial_statement_terminology` label-swap dictionary the
backend already defines for GAAP/IFRS terminology (`FINANCIAL_STATEMENTS.md → GAAP Compatibility`) — under
`framework = 'IFRS'` the exported document is titled "Statement of Profit or Loss," under
`framework = 'GAAP'` it is titled "Income Statement," and this screen's own page header always shows the
shorter, audience-tested "Profit & Loss" regardless of framework, with "(Income Statement)" rendered as a
muted secondary label beside it. Throughout this document, "Net Profit" (and its negative form, "Net Loss")
is this product's colloquial shorthand for the statement's own bottom line, the `PROFIT_FOR_PERIOD` line
(`label_en: "Profit for the Period"`) — the two terms name the exact same number; no separate concept is
introduced.

Like every screen in this documentation set, this one contains no financial logic of its own. It never
computes Gross Profit, decides how an expense is classified, or determines whether a period's revenue
recognition schedule has fully posted — the Report Generation Engine on the Laravel API does all of that,
and this screen's only job is to request a statement, render exactly what comes back, and offer the human
affordances (period, comparison, scope, presentation, drill-down, export, share, schedule, review, approve)
that `FINANCIAL_STATEMENTS.md` already defines as the module's capabilities. This screen is gated by the
same `accounting.financial-statements.*` permission family `docs/frontend/BALANCE_SHEET.md` established —
renamespaced from the backend's `reports.financial_statements.*` — because a company's Income Statement is
core accounting output, not a user-configurable ad hoc report; it shares that permission family, its route
prefix, its underlying `financial_statement_snapshots` table, and most of its component inventory with its
two siblings, Balance Sheet and Cash Flow, exactly as `BALANCE_SHEET.md → Route & Access` already states:
"Sibling routes `accounting/financial-statements/profit-and-loss` and `accounting/financial-statements/cash-flow`
share this exact page shape, this exact permission family, and most of this screen's components... they are
documented in their own screen specs but are not a separate architecture." This document is that promised
spec for Profit & Loss.

Three properties distinguish this screen from its Balance Sheet and Trial Balance siblings, and every
section below is organized around them.

1. **A statement of flow over a period, never a snapshot at a point in time.** Every Income Statement line
   is a *within-period movement* — `SUM(journal_lines)` between `period_start` and `period_end` for revenue
   and expense accounts, which are closed to Retained Earnings at each fiscal year-end and therefore never
   carry a cumulative balance the way a Balance Sheet account does. Per `FINANCIAL_STATEMENTS.md → Reporting
   Periods → Monthly`: "Monthly Income Statements are never simply 'the year-to-date total for this
   month'... year-to-date figures are a separate, explicitly requested comparative/cumulative view." This
   single fact drives the whole Period & Granularity control described under `# Route & Access` and
   `# Interactions & Flows` — MTD, QTD, and YTD are not three different backend concepts, they are three
   different `period_start`/`period_end` window resolutions issued against the identical generation
   pipeline, and the UI must never blur "this month's movement" with "this year so far" behind one ambiguous
   date picker.
2. **Formula-derived subtotals whose grouping, not their underlying data, is a presentation choice.** Gross
   Profit, Operating Profit (EBIT), Profit Before Tax, and Profit for the Period are `is_subtotal`/`is_total`
   rows in `financial_statement_lines` computed bottom-up from a `formula` expression over sibling lines,
   never a direct account mapping. The by-nature/by-function toggle (`financial_statement_templates.expense_presentation`)
   regroups the same posted ledger data into two legitimate faces without touching a single account balance —
   a genuinely different control from anything Balance Sheet or Trial Balance exposes, and the one most
   likely to be misunderstood by an engineer new to this screen as "recomputing" rather than "re-presenting."
3. **The single most-opened financial statement in the product, including mid-period.** Unlike Balance Sheet
   and Trial Balance — typically opened at period boundaries during close — Profit & Loss is the screen an
   Owner, CFO, or Finance Manager opens on an ordinary Tuesday to answer "is the business making money this
   month." Its AI layer is correspondingly the busiest of the three statements: a CFO Narrative and a
   margin/variance rail that update meaningfully while a period is still open and `mode = 'live'`, not only
   once a month at close.

The audience mirrors `docs/accounting/FINANCIAL_STATEMENTS.md → Permissions`'s role matrix exactly, adapted
onto the frontend's `accounting.financial-statements.*` namespace: Owner, Admin, Finance Manager, CFO, and
Auditor (internal) hold meaningful read/write breadth; External Auditor access is exclusively per-share-link
(`financial_statement_shares`), never a standing role grant; the AI Agent identity holds `read` only and can
enrich a snapshot with insights but can never call `.generate`, `.approve`, `.export`, `.share`, or
`.consolidation.manage` under any circumstance — the same platform-wide rule every other screen in this set
enforces. Worked examples in this document reuse the platform's own running example company, **Al-Noor
Trading & Contracting W.L.L.** (`company_id: 4821`), its CFO **Mariam Al-Sabah** and Finance Manager
**Khalid Marafie**, per `docs/frontend/NAVIGATION_SYSTEM.md`'s persona set.

# Route & Access

## Route tree

```text
app/(app)/accounting/financial-statements/income-statement/
├── page.tsx              # Server Component — first-paint fetch, hydrates the client grid
├── loading.tsx           # Statement-shaped skeleton (see # States)
└── error.tsx             # Route-level error boundary (generation failure, distinct from "Net Loss")
```

Per `docs/frontend/ACCOUNTING.md`'s own routing-facts section — the most recent, most specific word on where
Financial Statements live — this route nests under the Accounting module's own segment
(`accounting/financial-statements/<statement-type>`) rather than a top-level `/financial-statements`, which
`NAVIGATION_SYSTEM.md`'s earlier sketch is treated as superseding (that older path is retained only as a
permanent redirect: `{ source: "/financial-statements/:statement*", destination:
"/accounting/financial-statements/:statement*", permanent: true }`). This screen is the sub-nav tab bar's
fifth tab ("Financial Statements") rendered by the shared `app/(app)/accounting/layout.tsx`, and within that
tab's own segmented control it sits alongside Balance Sheet and Cash Flow.

`page.tsx` is a thin Server Component. It parses `searchParams` against `incomeStatementSearchParamsSchema`
(below), prefetches the primary statement query into a `QueryClient`, and hands a `dehydrate()`d cache to a
client `<IncomeStatementScreen>`, mirroring the identical Server-Component-prefetch → `HydrationBoundary` →
Client-Component-`useQuery` handoff `docs/frontend/FRONTEND_ARCHITECTURE.md → Data Layer` and
`docs/frontend/BALANCE_SHEET.md → Route & Access` both already establish for every statement-shaped route:

```tsx
// app/(app)/accounting/financial-statements/income-statement/page.tsx
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { incomeStatementKeys } from "@/lib/api/query-keys";
import { parseIncomeStatementParams } from "@/lib/schemas/income-statement";
import { IncomeStatementScreen } from "@/components/accounting/income-statement-screen";

export default async function IncomeStatementPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const params = parseIncomeStatementParams(await searchParams);
  const queryClient = getQueryClient();

  await queryClient.prefetchQuery({
    queryKey: incomeStatementKeys.view(params),
    queryFn: () =>
      apiServer.get("/accounting/financial-statements/income-statement", { params }),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <IncomeStatementScreen initialParams={params} />
    </HydrationBoundary>
  );
}
```

Every toolbar control (period + granularity, comparison, scope, presentation, common-size, framework) reads
from and writes back to these URL search params via `router.replace(..., { scroll: false })`, never a
Zustand store, for the identical reason `FRONTEND_ARCHITECTURE.md → State Management` and
`BALANCE_SHEET.md` both give: a P&L view must survive a refresh and must be a link Khalid can paste to
Mariam ("here's July MTD by-function, vs budget, Riyadh branch") without her re-selecting five controls.

## Access gate

The route sits inside `(app)`, behind `middleware.ts`'s session check and the resolved `X-Company-Id`
context. A user without `accounting.financial-statements.read` never sees "Profit & Loss" in the Accounting
sidebar's Financial Statements group, never sees it in the Command Palette's fuzzy-search index (RBAC
filters the index at the data layer before the list is built, per `ACCESSIBILITY.md → Command Palette`),
and a direct hit on the URL renders the shared `<ForbiddenState>` rather than a generic 404 — the route and
the underlying `fiscal_periods` scope genuinely exist, the viewer simply lacks the grant.

## Permission surface on this screen

Every key below is authoritative on the backend (`FINANCIAL_STATEMENTS.md → Permissions`); this table maps
key → concrete effect on *this* screen only, exactly as `BALANCE_SHEET.md`'s own permission table does —
these are the same nine keys, since Income Statement, Balance Sheet, and Cash Flow share one permission set,
not three parallel ones.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `accounting.financial-statements.read` | The route itself; the statement grid; comparative columns; common-size %; AI Margins/Trend/Variance/Narrative rail | Route renders `<ForbiddenState>`; nav item and Command Palette entry hidden |
| `accounting.financial-statements.generate` | "Regenerate" (force a fresh live pull, bypassing the 60-second server cache); Department/Project/Consolidated scope switch; the Presentation (by-nature/by-function) toggle where it triggers a new generation rather than a pure client-side relabel | Button/toggle visible, `disabled`, tooltip "Requires `accounting.financial-statements.generate`"; the screen still renders the last-cached or last-snapshotted view |
| `accounting.financial-statements.review` | Moving a `draft`/`under_review` snapshot forward toward `final` | "Send for review" action omitted; snapshot stays in `draft` |
| `accounting.financial-statements.approve` | "Mark Final"; approving the CFO Narrative before export/share; approving a department/project reconciliation override; approving a pending consolidation elimination set | Approve affordances on the AI rail's Narrative tab, `ApprovalCard`s, and the snapshot status menu are omitted, not disabled |
| `accounting.financial-statements.export` | The Export menu (PDF/Excel/CSV/JSON) | Menu item removed from the toolbar overflow |
| `accounting.financial-statements.share` | The Share dialog (external time-limited link) | Menu item removed |
| `accounting.financial-statements.schedule` | The Schedule dialog (recurring board-pack distribution) | Menu item removed |
| `accounting.financial-statements.consolidation.manage` | Viewing/approving the intercompany revenue/COGS elimination set behind a Consolidated Group scope | Consolidated scope still selectable for preview (read covers it), but "Approve eliminations" is omitted |
| `accounting.financial-statements.notes.write` | "Add disclosure note" on a materiality-flagged line (e.g. Revenue disaggregation, Staff Costs) | Action omitted; the materiality gap still surfaces as a read-only AI flag |

Default role grants (Owner, Admin, Finance Manager, CFO, Auditor (internal), External Auditor, AI Agent)
mirror `FINANCIAL_STATEMENTS.md → Permissions → Role matrix` exactly, renamespaced identically to
`BALANCE_SHEET.md`'s own table; `usePermission()` (`FRONTEND_ARCHITECTURE.md → Authentication & Session`) is
the only place either the backend or this screen resolves a grant, so the two never drift apart by having a
second copy. The AI Agent identity holds `read` and nothing else — it enriches a snapshot with Margins,
Trend, Variance, and the CFO Narrative draft, and it can never post the reserve-transfer entry, approve its
own narrative, or move a snapshot toward `final`.

## Keyboard entry points

`G` then `A` opens Accounting; from there this screen is one click into "Financial Statements → Profit &
Loss," or reachable directly from the Command Palette, whose fuzzy index matches any of "profit and loss,"
"P&L," "income statement," or "net income" — a deliberate multi-synonym index entry, since real users search
under all four names for the identical screen and the palette must not force one vocabulary
(`ACCESSIBILITY.md → Command Palette`). `Cmd/Ctrl+P` (overriding the browser's print dialog) opens the
Export menu pre-focused on PDF; `Esc` from any open drill-down `Sheet` returns focus to the row that opened
it.

# Layout & Regions

The screen composes the platform's Report Page Template — identical to Balance Sheet's and Trial Balance's
— with the same right-hand AI Insights rail that docks inline in the page grid at `xl:` (1280px) and above,
because margin/variance/narrative content is core, always-relevant content here, not an on-demand assistant.

## Desktop (`xl:` and above)

```text
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Accounting / Financial Statements / Profit & Loss (Income Statement)                     │
│ Profit & Loss                                                [Final ✓]  [History ▾]  [⋯]  │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ [Jul 2026 (MTD) ▾] [vs Prior Year ▾] [Company ▾] [KWD ▾] [By nature ▾] [☐ Common-size]   │
│                                                    [↻ Regenerate] [Export ▾] [Share] [Schedule]│
├────────────────────────────────────────────────────────────────────┬─────────────────────┤
│ Line (sticky start)               Jul 2026    Jul 2025   Δ%   %Rev│  AI Insights (360px) │
│ ▾ Revenue                                                          │  Tabs: Margins │     │
│    Gross Revenue                 168,420.000 152,860.000 10.2%102.4%│  Trend │ Variance │  │
│    Less: Sales Returns & Allow.   (2,150.000)  (1,980.000)  8.6% (1.3%)│ Narrative        │
│    Less: Sales Discounts          (1,870.000)  (1,720.000)  8.7% (1.1%)├─────────────────┤
│ Net Revenue                      164,400.000 148,900.000 10.4%100.0%│  Gross margin 41.1% │
│ Cost of Sales                      96,840.000  84,200.000 15.0% 58.9%│  Op. margin   18.0% │
│ Gross Profit                       67,560.000  59,200.000 14.1% 41.1%│  Net margin   14.7% │
│ ▾ Operating Expenses                                                │  ──────────────────  │
│    Selling & Distribution          18,220.000  16,410.000 11.0% 11.1%│ 91% ● Variance:     │
│    General & Administrative        14,675.000  13,050.000 12.5%  8.9%│  "Cost of Sales grew │
│    Depreciation & Amortization      3,940.000   3,610.000  9.1%  2.4%│   15.0% vs 10.4%     │
│    Expected Credit Loss             1,120.000     940.000 19.1%  0.7%│   revenue growth"    │
│ Total Operating Expenses           37,955.000  34,010.000 11.6% 23.1%│  [Show reasoning]    │
│ Operating Profit (EBIT)            29,605.000  25,190.000 17.5% 18.0%│  ──────────────────  │
│ ▸ Other Income and Expenses        (1,230.000)   (760.000)61.8% (0.7%)│ CFO Narrative       │
│ Profit Before Tax                  28,375.000  24,430.000 16.2% 17.3%│ [Unreviewed AI Draft]│
│ Income Tax Expense                  4,256.000   3,790.000 12.3%  2.6%│ Approve · Reject     │
│ Profit for the Period              24,119.000  18,640.000 29.4% 14.7%│                      │
└────────────────────────────────────────────────────────────────────┴─────────────────────┘
```

## Mobile / Tablet (below `xl:`)

```text
┌───────────────────────────────┐
│ ‹ Profit & Loss          [⋯]  │
│ [Jul 2026 (MTD) ▾]            │
│ Tabs:  Statement | AI Insights │
├───────────────────────────────┤
│ Net Revenue        164,400.000│  (sticky summary strip)
│ Net Profit           24,119.000│
├───────────────────────────────┤
│ Line (sticky start) → scroll →│
│ ▾ Revenue                      │
│    Gross Revenue …     168,420│
│    Net Revenue …       164,400│
├───────────────────────────────┤
│ [Filters] [Columns] [⋯ Export]│
└───────────────────────────────┘
```

## Regions

| Region | Content |
|---|---|
| Page Header | Breadcrumb, title ("Profit & Loss" + muted "(Income Statement)"), snapshot `StatusPill` (`draft`/`under_review`/`final`/`superseded`), "History," overflow menu |
| Toolbar | Period + granularity (`PeriodPicker`), comparison type (`ComparisonSelect`), scope (`ScopeSelect` — all five `fs_scope_type` values on this screen, see `# Components Used`), presentation currency, by-nature/by-function `ToggleGroup`, common-size `Switch`, Regenerate, Export, Share, Schedule |
| Summary Strip (mobile only; optional `KpiTile` row on wide desktop above the grid) | Net Revenue, Gross Profit, Operating Profit, Profit for the Period — the four numbers a reader wants before scrolling the full hierarchy |
| Statement Grid | The sticky-first-column hierarchical `<table>` — Revenue → Cost of Sales → Gross Profit → Operating Expenses → Operating Profit → Other Income/Expenses → Profit Before Tax → Income Tax → Profit for the Period |
| AI Insights Rail | Docked inline at `xl:`+; a full-screen `Sheet` (mobile/tablet) or a `Tabs`-selected pane (below `xl:`) — Margins, Trend, Variance, Narrative |
| Drill-down Sheet | Slides from the logical end edge when a line is expanded past the statement-line level; never navigates away from the statement |

Unlike Balance Sheet, this screen has no `BalanceIdentityBanner` region — there is no analogous single
pass/fail invariant a P&L must satisfy (Assets = Liabilities + Equity has no Income Statement equivalent).
Where Balance Sheet dedicates a sticky band to that check, this screen instead gives the Profit for the
Period row itself the visual weight (`emphasis="strong"`, bold border-top) and, where a department/branch
scope is active, an "Unallocated" line plus a reconciliation self-check described under `# Edge Cases`
(Business Rule 9) stands in for the kind of structural guarantee the Balance Sheet's banner communicates.

# Components Used

Every component below is either a primitive/finance component already cataloged in `COMPONENT_LIBRARY.md`
and reused unmodified, a statement-family component `BALANCE_SHEET.md` already introduced and this screen
shares outright (per that document's own statement that sibling statement routes "share... most of this
screen's components... not a separate architecture"), or a screen-owned addition this document introduces
because the concept genuinely does not exist on Balance Sheet or Trial Balance.

| Component | Source | Role on this screen |
|---|---|---|
| `PeriodPicker` (`mode="relative"`, extended) | `components/accounting/period-picker.tsx` | Period + granularity selection; this screen registers three additional entries into the shared `RELATIVE_RANGES` list — `mtd`, `qtd`, `ytd` — alongside the existing `this_fiscal_period`/`this_fiscal_year` (see `# Data & State`) |
| `ComparisonSelect` | `components/accounting/comparison-select.tsx` (introduced by `BALANCE_SHEET.md`) | None / Prior Period / Prior Year / Budget — reused unmodified; Budget is exercised far more often here than on Balance Sheet |
| `ScopeSelect` | `components/accounting/scope-select.tsx` (introduced by `BALANCE_SHEET.md`) | Company / Branch / **Department** / **Project** / Consolidated Group — this screen renders all five `fs_scope_type` values, unlike Balance Sheet's own usage (Company/Branch/Consolidated Group only), because department- and project-scoped P&L is this exact statement type's primary scoped use case per `FINANCIAL_STATEMENTS.md → Report Generation Engine → Department`: "assets/liabilities are rarely departmentally attributed" |
| `ExpensePresentationToggle` *(new)* | `components/accounting/expense-presentation-toggle.tsx` | By Nature / By Function `ToggleGroup`, bound to `financial_statement_templates.expense_presentation`; the one toolbar control with no equivalent anywhere on Balance Sheet or Cash Flow |
| `StatementGrid` / `StatementLineRow` | `components/accounting/statement-grid.tsx` (introduced by `BALANCE_SHEET.md`) | The sticky-first-column, expandable statement tree, reused unmodified except for one additive prop, `formatNegative="parentheses"` (see below), and a `classification` filter tuned to this statement's `fs_line_classification` subset (`revenue`, `cost_of_sales`, `operating_expense`, `other_income_expense`, `tax_expense`) rather than the Balance Sheet's asset/liability/equity subset |
| `StatusPill` (`domain="financial_statement_snapshot"`) | `components/shared/status-pill.tsx` | `draft` / `under_review` / `final` / `superseded` — the identical `fs_snapshot_status` enum and lookup table Balance Sheet already extended `StatusPill` with, since Income Statement snapshots are rows in the same `financial_statement_snapshots` table, merely filtered to `statement_type = 'income_statement'` |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every monetary figure; `emphasis="strong"` on Gross Profit / Operating Profit / Profit for the Period; see `# RTL & Localization` for the parentheses-vs-minus-sign distinction this screen actually exercises, unlike Trial Balance's raw Debit/Credit columns |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Presentation-currency indicator beside the currency selector and inside export dialogs |
| `KpiTile` | `components/dashboard/kpi-tile.tsx` | Optional summary strip (Net Revenue, Gross Profit, Operating Profit, Profit for the Period) above the grid on wide screens; the mandatory sticky mobile summary (see `# Responsive Behavior`) |
| `TrendSparkline` | `components/dashboard/trend-sparkline.tsx` | AI rail's Trend tab — Horizontal Analysis series per headline line |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Every AI-authored figure — margin interpretation, variance narrative, CFO Narrative |
| `AIProposalPanel` / `ApprovalCard` | `components/ai/ai-proposal-panel.tsx`, `components/shared/approval-card.tsx` | CFO Narrative and any margin/risk flag's approve/reject inside the AI Insights rail |
| `Tabs` | `components/ui/tabs.tsx` | AI rail's Margins/Trend/Variance/Narrative sub-navigation; Statement/AI Insights switch below `xl:` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Export format menu, snapshot History menu, row-action overflow |
| `Dialog` | `components/ui/dialog.tsx` | Share link dialog, Schedule dialog, "Reason required" regeneration dialog |
| `Sheet` | `components/ui/sheet.tsx` | Line drill-down; account-level and GL drill-down; mobile Filters/Columns/AI Insights |
| `Switch` | `components/ui/switch.tsx` | Common-size toggle; "Show low-confidence AI analysis" toggle |
| `Skeleton` | `components/ui/skeleton.tsx` | Statement-shaped loading state; AI rail "Generating…" placeholder |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `components/shared/error-state.tsx` | Zero-transaction period; generation/validation failure |
| `Tooltip` | `components/ui/tooltip.tsx` | "Why 91%?" on every `ConfidenceBadge`; disabled-control permission explanations |
| `Can` | `components/auth/can.tsx` | Every permission-gated affordance in the table above |

`ExpensePresentationToggle` is the one component this screen owns outright:

```tsx
// components/accounting/expense-presentation-toggle.tsx
'use client';

import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';

interface ExpensePresentationToggleProps {
  value: 'by_nature' | 'by_function';
  onChange: (value: 'by_nature' | 'by_function') => void;
  disabled?: boolean;
}

export function ExpensePresentationToggle({ value, onChange, disabled }: ExpensePresentationToggleProps) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <ToggleGroup
          type="single"
          value={value}
          onValueChange={(v) => v && onChange(v as 'by_nature' | 'by_function')}
          disabled={disabled}
          aria-label="Expense presentation"
        >
          <ToggleGroupItem value="by_nature">By nature</ToggleGroupItem>
          <ToggleGroupItem value="by_function">By function</ToggleGroupItem>
        </ToggleGroup>
      </TooltipTrigger>
      <TooltipContent className="max-w-xs text-start">
        Regroups Operating Expenses into Cost of Sales / Distribution / Administrative / Other (by function)
        instead of Selling &amp; Distribution / G&amp;A / Depreciation / ECL (by nature). Same posted ledger,
        same totals — only the grouping changes.
      </TooltipContent>
    </Tooltip>
  );
}
```

`StatementGrid`'s one additive prop for this screen, `formatNegative`, follows the exact numeral rule
`DESIGN_LANGUAGE.md → Numeral formatting rules` states for *rendered financial statements* specifically
(as opposed to raw, editable ledger views): negative subtotals — most commonly a Net Loss, but also
contra-revenue lines and an unfavorable Other Income/Expenses subtotal — render in parentheses, e.g.
`(4,860.000)`, never with a leading minus sign, matching how a printed Balance Sheet or P&L reads correctly
even in grayscale:

```tsx
// components/accounting/statement-grid.tsx (excerpt — this screen's usage)
<StatementGrid
  lines={incomeStatementLines}
  classification={['revenue', 'cost_of_sales', 'operating_expense', 'other_income_expense', 'tax_expense']}
  formatNegative="parentheses"   // vs. AmountCell's default leading minus-sign glyph
  showCommonSize={commonSizeEnabled}
  commonSizeBaseLineCode="NET_REVENUE"   // vs. Balance Sheet's 'TOTAL_ASSETS'
  onDrillDown={openDrillDownSheet}
/>
```

# Data & State

## Endpoints consumed

All under `/api/v1/accounting/financial-statements/income-statement`, Bearer + `X-Company-Id`, standard
envelope, per `docs/accounting/FINANCIAL_STATEMENTS.md → API`. This is a statement-type-scoped alias family
over the shared generic engine (`/accounting/financial-statements/generate|preview|{snapshot}|...` with
`statement_type=income_statement`) — the identical pattern `BALANCE_SHEET.md → Route & Access`'s own SSR
sample already establishes (`apiServer.get("/accounting/financial-statements/balance-sheet", { params })`),
applied here to the Income Statement's own resource segment.

| Method | Path | Permission | Used for |
|---|---|---|---|
| GET | `/income-statement` | `.read` | Resolve the current/live statement for the active period, granularity, scope, comparison on page load |
| GET | `/income-statement/{snapshot}` | `.read` | A specific existing snapshot by id (opened from History or a share link) |
| GET | `/income-statement/{snapshot}/lines` | `.read` | The line array feeding `StatementGrid`, grouped by `fs_line_classification` |
| GET | `/income-statement/{snapshot}/common-size` | `.read` | Percentage-of-Net-Revenue column set — a pure transform per `FINANCIAL_STATEMENTS.md → Common Size`, free to compute even for an archived snapshot |
| GET | `/income-statement/{snapshot}/trend` | `.read` | Horizontal Analysis series (trailing periods) feeding the AI rail's Trend tab and every `TrendSparkline` |
| GET | `/income-statement/comparative` | `.read` | Prior-period / prior-year / budget comparison columns |
| GET | `/income-statement/{snapshot}/insights` | `.read` | AI Responsibilities output — margins, variance, trend, CFO Narrative |
| GET | `/income-statement/{snapshot}/drill-down` | `.read` | Contributing accounts/journal lines for a clicked statement line |
| GET | `/income-statement/{snapshot}/history` | `.history.read` (implied by `.read`) | Version lineage |
| POST | `/income-statement/generate` | `.generate` | Generate dialog submit / Regenerate |
| POST | `/income-statement/{snapshot}/review` | `.review` | "Start Review" |
| POST | `/income-statement/{snapshot}/approve` | `.approve` | Each `ApprovalCard` step, including CFO Narrative sign-off |
| POST | `/income-statement/{snapshot}/export` | `.export` | Export menu |
| POST | `/income-statement/{snapshot}/share` | `.share` | Share dialog |
| DELETE | `/financial-statements/shares/{share}` | `.share` | Revoke a share link (shared endpoint across all three statement types) |
| POST | `/financial-statements/schedules` | `.schedule` | Recurring board-pack schedule (shared endpoint; `statement_types` array includes `income_statement`) |

## Query keys

```ts
// lib/api/query-keys.ts (extends the factory pattern from FRONTEND_ARCHITECTURE.md)
export const incomeStatementKeys = {
  all: ["accounting", "financial-statements", "income-statement"] as const,
  view: (params: IncomeStatementParams) => [...incomeStatementKeys.all, "view", params] as const,
  detail: (snapshotId: number) => [...incomeStatementKeys.all, "detail", snapshotId] as const,
  lines: (snapshotId: number, filters: IncomeStatementLineFilters) =>
    [...incomeStatementKeys.detail(snapshotId), "lines", filters] as const,
  commonSize: (snapshotId: number) => [...incomeStatementKeys.detail(snapshotId), "common-size"] as const,
  trend: (snapshotId: number, trailingPeriods: number) =>
    [...incomeStatementKeys.detail(snapshotId), "trend", trailingPeriods] as const,
  insights: (snapshotId: number) => [...incomeStatementKeys.detail(snapshotId), "insights"] as const,
  drillDown: (snapshotId: number, lineCode: string) =>
    [...incomeStatementKeys.detail(snapshotId), "drill-down", lineCode] as const,
  comparative: (snapshotIds: number[]) => [...incomeStatementKeys.all, "comparative", snapshotIds] as const,
};
```

`IncomeStatementParams` is `{ granularity: 'mtd' | 'qtd' | 'ytd' | 'fiscal_period' | 'custom', period_start,
period_end, fiscal_period_id?, scope_type, branch_id?, department_id?, project_id?, consolidated?: boolean,
expense_presentation, comparison, presentation_currency }`. Switching any control re-resolves `view(params)`
first (a cheap lookup) before fetching `detail`/`lines`, mirroring Trial Balance's own "never assume the
previously-loaded snapshot id is still the right one" rule.

## Granularity resolution — MTD / QTD / YTD

This screen's `PeriodPicker` adds three entries to the shared component's `RELATIVE_RANGES` list, each
resolving to a *different* combination of `fiscal_period_id` and `mode`, grounded exactly in
`FINANCIAL_STATEMENTS.md → Reporting Periods`:

| Granularity | Resolves to | `generation_mode` |
|---|---|---|
| `mtd` | The current open monthly `fiscal_periods` row, `period_start`/`period_end` = that month's boundaries | `live` — "the default mode for the current open fiscal period, where balances change intra-day" |
| `qtd` | A **Custom** period: `period_start` = the current quarter's first monthly period's start, `period_end` = today | `live` — quarters are derived from three monthly periods (`parent_period_id`); a quarter is a `fiscal_periods` row only once fully elapsed, so QTD before quarter-end is definitionally a Custom range, never a `fiscal_period_id` lookup |
| `ytd` | A **Custom** period: `period_start` = the fiscal year's start month, `period_end` = today | `live`, unless the user explicitly requests `persist=true` with a reason, per `Reporting Periods → Custom`: "Custom-period statements are always generated in `mode = 'live'`... unless explicitly requested with `persist = true` and an authorization reason" |
| A specific closed month/quarter/year (via `PeriodPicker mode="fiscal_period"`) | That exact `fiscal_periods.id` | `historical` — served from `financial_statement_snapshots` if one already exists for that logical key |

This table is the reason the toolbar never shows a plain "This month" date range picker doing double duty
for both MTD and a closed prior month — the two are architecturally different requests (`live` vs.
`historical`), and collapsing them into one control would eventually show a stale cached snapshot where a
user expected a fresh intra-day pull, or vice versa.

## Cache tuning by snapshot status

Mirrors `TRIAL_BALANCE.md → Data & State → Cache tuning by snapshot status`, keyed on the shared
`fs_snapshot_status` enum:

| `status` (or live-mode state) | `staleTime` | Rationale |
|---|---|---|
| `generating` (async consolidated run) | `0`, polled every 2s as a Reverb fallback | Actively changing |
| `mode: 'live'` (MTD/QTD/YTD) | 60 seconds, matching the backend's own Redis result-cache TTL | No point refetching more often than the server itself can produce a new number |
| `draft` / `under_review` (a persisted, closed-period snapshot) | 30 seconds | Findings/comments can still be added by another reviewer concurrently |
| `final` | 5 minutes | Structurally immutable per `fn_block_final_snapshot_mutation`; only lifecycle metadata can still change |
| `superseded` / `archived` | `Infinity` | Immutable, locked, permanent |

## Realtime

Three Laravel Reverb channels, per the platform-wide `private-company.{id}.<feature>[.{sub_id}]`
convention:

| Channel | Events | Effect |
|---|---|---|
| `private-company.{id}.financial-statements.{snapshot_id}` | `financial_statement.generated`, `financial_statement.snapshot.status_changed` | Subscribed while a snapshot's generation is in flight; on `generated` the client invalidates `detail`/`lines`/`insights` for that id |
| `private-company.{id}.revrec` | Revenue-recognition schedule commits (per `FRONTEND_ARCHITECTURE.md → Realtime → Transport and channel convention`, explicitly named there as feeding "the P&L accuracy banner") | A quiet, dismissable inline banner — "Revenue recognition posted 3 new entries for this period — refresh?" — above the grid when the currently-viewed period is still open (`mode: 'live'`); never auto-refetches without the user's click, so a Finance Manager mid-review of a number is never silently rug-pulled |
| `private-company.{id}.notifications.{user_id}` | `financial_statement.snapshot.final`, `financial_statement.ai.risk_flag_high_confidence`, `financial_statement.note.pending_approval`, `financial_statement.consolidation.eliminations_pending_review`, `financial_statement.schedule.run_completed`, `financial_statement.schedule.run_failed` | Topbar bell + toast; a toast matching the open snapshot id additionally triggers a targeted `invalidateQueries` |

## AI agents feeding this screen

Per `docs/accounting/FINANCIAL_STATEMENTS.md → AI Responsibilities`, filtered to the rows this screen
actually surfaces: **Reporting Agent** (Financial Analysis, Ratio Analysis for the Margins tab, Variance
Analysis, Executive Summary/CFO Narrative), **Forecast Agent** (Trend Analysis and the pro-forma
next-period Income Statement forecast), and **Fraud Detection Agent** + **Compliance Agent** (Risk
Detection — margin compression, an expense category outrunning revenue, related-party revenue
concentration — and Anomaly Detection on any line's period-over-period movement). None of the three writes
to the database directly; each produces AI-authored output the backend validates and caches on the snapshot
response as `ai_insights`, rendered here exactly as received, never re-derived.

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md → Principle 10`: reversible, non-financial actions are optimistic; anything
that changes the ledger's authoritative state is pessimistic.

```ts
// hooks/accounting/use-income-statement.ts
export function useDismissInsight(snapshotId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ insightId, reason }: { insightId: number; reason: string }) =>
      api.patch(`/accounting/financial-statements/income-statement/insights/${insightId}`,
        { status: "dismissed", resolution_note: reason }),
    onMutate: async ({ insightId }) => {
      await qc.cancelQueries({ queryKey: incomeStatementKeys.insights(snapshotId) });
      const previous = qc.getQueryData(incomeStatementKeys.insights(snapshotId));
      qc.setQueryData(incomeStatementKeys.insights(snapshotId), (old: Insight[]) =>
        old.map((i) => (i.id === insightId ? { ...i, status: "dismissed" } : i)));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(incomeStatementKeys.insights(snapshotId), ctx?.previous),
    onSettled: () => qc.invalidateQueries({ queryKey: incomeStatementKeys.insights(snapshotId) }),
  });
}

export function useApproveCfoNarrative(snapshotId: number) {
  // No onMutate — approving the CFO Narrative for external distribution is a financial-workflow
  // action; the UI only shows "approved" after the server's 2xx, per Principle 10.
  return useMutation({
    mutationFn: (body: { action: "approved" | "rejected"; comment: string }) =>
      api.post(`/accounting/financial-statements/income-statement/${snapshotId}/approve`,
        { ...body, target: "cfo_narrative" }, crypto.randomUUID()),
  });
}
```

## Form schemas

```ts
// lib/schemas/income-statement.ts
export const generateIncomeStatementSchema = z
  .object({
    granularity: z.enum(["mtd", "qtd", "ytd", "fiscal_period", "custom"]),
    fiscal_period_id: z.number().int().positive().nullable().optional(),
    period_start: z.string().date().optional(),
    period_end: z.string().date().optional(),
    scope_type: z.enum(["company", "branch", "department", "project", "consolidated_group"]),
    branch_id: z.number().int().positive().nullable().optional(),
    department_id: z.number().int().positive().nullable().optional(),
    project_id: z.number().int().positive().nullable().optional(),
    expense_presentation: z.enum(["by_nature", "by_function"]).default("by_nature"),
    comparison: z.object({
      type: z.enum(["none", "prior_period", "prior_year_same_period", "budget"]),
      budget_id: z.number().int().positive().optional(),
    }),
    presentation_currency: z.string().length(3),
  })
  .refine((v) => v.granularity !== "custom" || (v.period_start && v.period_end), {
    message: "validation.incomeStatement.customRangeRequired",
    path: ["period_start"],
  })
  .refine((v) => v.comparison.type !== "budget" || Boolean(v.comparison.budget_id), {
    message: "validation.incomeStatement.budgetRequired",
    path: ["comparison", "budget_id"],
  });
```

## SSR hydration

```tsx
// app/(app)/accounting/financial-statements/income-statement/page.tsx (hydration excerpt)
const params = parseIncomeStatementParams(await searchParams); // resolves granularity → period_start/end
const current = await apiServer.get("/accounting/financial-statements/income-statement", { params });
const snapshotId = current.data.snapshot_id ?? null;

if (snapshotId) {
  await Promise.all([
    queryClient.prefetchQuery({
      queryKey: incomeStatementKeys.detail(snapshotId),
      queryFn: () => apiServer.get(`/accounting/financial-statements/income-statement/${snapshotId}`),
    }),
    queryClient.prefetchQuery({
      queryKey: incomeStatementKeys.lines(snapshotId, {}),
      queryFn: () => apiServer.get(`/accounting/financial-statements/income-statement/${snapshotId}/lines`),
    }),
    queryClient.prefetchQuery({
      queryKey: incomeStatementKeys.insights(snapshotId),
      queryFn: () => apiServer.get(`/accounting/financial-statements/income-statement/${snapshotId}/insights`),
    }),
  ]);
}
```

# Interactions & Flows

**First open, current month.** Landing on `/accounting/financial-statements/income-statement` with no query
string defaults to `granularity=mtd`, `scope_type=company`, `comparison=prior_year_same_period`,
`expense_presentation=by_nature`. This resolves to `mode: 'live'` against the still-open fiscal period, so
the numbers a Finance Manager sees on the morning of the 16th genuinely reflect everything posted through
last night's batch and any same-day manual entries — never a stale month-end-only figure.

**Switching granularity (MTD → QTD → YTD → a specific closed month).** Each change re-resolves
`period_start`/`period_end` per the table in `# Data & State` and re-fetches `view(params)`. Moving from MTD
to a prior closed month is the one granularity change that flips `generation_mode` from `live` to
`historical` — the toolbar's period label visibly changes weight/style (a small `Badge` reading "Live" next
to MTD/QTD/YTD, absent on a closed-period selection) so a reader never mistakes an intra-day, still-moving
number for a closed, audited one.

**Switching comparison (None → Prior Period → Prior Year → Budget).** Selecting "vs Budget" opens a
`Combobox` to pick the specific budget dataset (`report_definitions`-linked, per
`FINANCIAL_STATEMENTS.md → Comparative`) when the company has more than one active budget (e.g., an
original board-approved budget and a mid-year reforecast); with exactly one active budget the comparison
applies immediately with no extra click. Each comparison column adds a `variance_amount`/`variance_percent`
pair per line, and the Margins tab's ratio deltas recompute against whichever comparison is active — "Gross
margin 41.1%, +2.6pp vs Budget" reads differently from "+3.1pp vs Prior Year," and the AI rail always labels
which one it means rather than a bare "+2.6pp."

**Switching scope (Company → Branch → Department → Project → Consolidated Group).** Department and Project
are this screen's own primary scoped use case (`# Components Used`). Selecting a department whose
`financial_statement_templates.department_scope_mode = 'income_statement_only'` (the default) succeeds
immediately; the same department selector would 422 if a user somehow reached it from the Balance Sheet
screen instead, which is exactly why `ScopeSelect` on *that* screen omits Department/Project altogether
rather than exposing a control that mostly fails. Selecting Consolidated Group runs the full consolidation
pipeline (translate → combine → eliminate → allocate NCI) as an async job; the toolbar's Regenerate button
becomes "Generating consolidated view…" with a Reverb-subscribed progress state rather than a blocking
spinner (`# Performance`).

**Switching presentation (By Nature ↔ By Function).** A pure re-grouping of the identical posted ledger
data (`FINANCIAL_STATEMENTS.md → Income Statement → By-function alternative`) — Operating Expenses
regroups from Selling & Distribution / G&A / Depreciation & Amortization / Expected Credit Loss into Cost of
Sales / Distribution Costs / Administrative Expenses / Other Operating Expenses. Gross Profit, Operating
Profit, and Profit for the Period are unchanged to the cent; only the rows between Net Revenue and Operating
Profit reflow. The toggle persists per-company (via the active `financial_statement_templates` row, not a
personal UI preference), so switching it is itself gated on `.generate` (it is functionally requesting a
statement against a different template) rather than a free client-side relabel.

**Common-size toggle.** Adds a `%Rev` column to every line, computed server-side
(`GET /income-statement/{snapshot}/common-size`) as `line.current_value / NET_REVENUE.current_value * 100`
— never computed client-side, so an archived, `final` snapshot's common-size view is exactly reproducible
from the same immutable `financial_statement_snapshot_lines` rows years later.

**Drill-down.** Clicking any line (e.g., "Cost of Sales," "General and Administrative Expenses") opens the
Drill-down `Sheet` (`GET /income-statement/{snapshot}/drill-down?line_code=…`), listing the contributing
accounts, then the posted `journal_lines` under each. "View in General Ledger" navigates to
`/accounting/ledger?account_id={id}&date_from={period_start}&date_to={period_end}` — note the **date range**
params, not Balance Sheet's single `as_of` param, because a P&L line is a period's worth of movement, not a
point-in-time balance; this is the one drill-down URL shape genuinely specific to a flow statement.
"Open journal entry" navigates to `/accounting/journal-entries/{journalEntryId}`. Both close the Sheet on
navigate rather than stacking a second overlay on top of a route change, per the identical rule
`TRIAL_BALANCE.md → Interactions & Flows` states for its own drill-down.

**Review → Approve → Export/Share/Schedule.** Identical lifecycle to Balance Sheet: "Start Review" assigns
the acting user as reviewer; the reviewer resolves or dismisses AI insights before the primary action
switches to "Send for Approval"; `ApprovalCard`s render one per required step, with the CFO Narrative's own
approval (`target: "cfo_narrative"`) as a distinct step from the snapshot's overall `final` approval, since
a Finance Manager can legitimately approve the numbers while the CFO Narrative — a longer-form, more
externally-visible artifact — is still being wordsmithed. Export offers PDF/Excel/CSV/JSON exactly as
Balance Sheet does, with the same visible "DRAFT — NOT APPROVED" watermark note on a `draft`/`under_review`
snapshot's export menu item, before the click.

**Regenerating a live MTD view mid-review.** Because `mode: 'live'` is never persisted as a snapshot, there
is nothing to "regenerate" in the closed-period sense — clicking "Refresh" simply re-issues the same request
and lets the 60-second Redis cache (or a cache-bust from the `revrec`/`journal.posted` realtime events)
decide whether a new number comes back. The "reason required" regeneration dialog only appears when
refreshing a **closed, `final`** period's snapshot — the exact same distinction Trial Balance and Balance
Sheet already draw between an ordinary live refresh and a reasoned, audited regeneration.

# AI Integration

Per `FINANCIAL_STATEMENTS.md → AI Responsibilities`, this screen surfaces five of the module's nine AI
responsibilities through four rail tabs — Margins, Trend, Variance, Narrative — with Risk Detection and
Anomaly Detection findings folded into whichever tab their underlying line belongs to, rather than a fifth
"Risks" tab (a deliberate difference from Balance Sheet's separate Ratios/Variance/**Risks**/Narrative
layout: Income Statement risk flags are almost always *about* a margin or a variance already on screen, so
surfacing them as a `Badge` on the relevant `ConfidenceBadge` reads more precisely than a parallel list).

**Margins tab.** Auto-computed, always shown (ratios are deterministic math, carrying no confidence score
of their own, per `AI Responsibilities → Ratio Analysis`): Gross Margin, Operating Margin, Net Margin, and
Interest Coverage, each with a plain-language read the Reporting Agent attaches ("Gross margin held above
40% for the third consecutive month"). Where the active scope also has a Balance Sheet available for the
same period, ROA/ROE/Asset Turnover/Inventory Turnover/Receivables Turnover/DSO/DIO/DPO/Cash Conversion
Cycle render here too — the Income Statement is the numerator source for all of them — each linking to the
Balance Sheet screen for its denominator's own detail.

**Trend tab.** Horizontal Analysis (`FINANCIAL_STATEMENTS.md → Horizontal Analysis`) rendered as a
`TrendSparkline` per headline line across the trailing 12 monthly periods plus the prior 2 fiscal years,
computed against a fixed base period exactly as the backend defines it. Per `AI Responsibilities → Trend
Analysis`, this tab requires a minimum of 3 comparable historical periods before returning any trend claim;
a brand-new company or a newly-onboarded branch instead shows a calm `insufficient_history` state — never a
misleading flat line drawn from too few points. The same tab hosts the Forecast Agent's pro-forma
next-period Income Statement, explicitly labeled "Forecast — Not an Audited Statement," with the confidence
band widening visibly as the forecast horizon extends, per `AI Responsibilities → Forecasting`.

**Variance tab.** A line-by-line narrative flagging the 5–10 largest/most unusual movements against
whichever comparison is active. Worked example, Al-Noor Trading, July 2026 vs. July 2025:

```json
{
  "id": 88014,
  "finding_type": "variance",
  "line_code": "COST_OF_SALES",
  "source": "ai",
  "ai_agent": "reporting_agent",
  "title": "Cost of Sales outpacing revenue growth",
  "description": "Cost of Sales grew 15.0% (KWD 84,200.000 → 96,840.000) against 10.4% Net Revenue growth over the same period, compressing Gross Margin from 43.4% to 41.1%.",
  "confidence": 0.91,
  "reasoning": "Cost of Sales' movement is concentrated in two accounts (Freight-In, Raw Materials) rather than diffused across the account, and both correlate with a documented supplier price increase effective May 2026 visible in the underlying journal lines.",
  "source_references": [{ "type": "financial_statement_snapshot_lines", "id": 771102 }],
  "suggested_action": "No correcting entry implied — this reflects a real cost increase. Consider reviewing supplier pricing or the sales price list for the affected product categories."
}
```

The inline treatment mirrors `TRIAL_BALANCE.md → AI Integration`'s "worked example" pattern exactly: the
rail shows "AI found a likely driver (91% confidence) — Explain," which opens the full `AIProposalPanel`
with `description`, a confidence ring, `reasoning`, a one-click jump into the Drill-down Sheet for the named
accounts, and `suggested_action` as plain text — never an auto-apply button, since a variance narrative
about cost movement is an observation, not a correctable error, and the platform never implies otherwise.

**Narrative tab — the CFO Narrative.** A 150–300 word plain-language executive summary
(`AI Responsibilities → Executive Summary`), watermarked "Unreviewed AI Draft" until a CFO or Finance
Manager has signed off, at which point the watermark becomes "AI-generated — reviewed by Mariam Al-Sabah on
16 Jul 2026." Worked example:

> "Al-Noor Trading's Net Revenue reached KWD 164,400 in July 2026, up 10.4% year-on-year, continuing the
> growth trend seen since Q1. Gross Profit grew more slowly, at 14.1%, as Cost of Sales absorbed a
> documented supplier price increase — Gross Margin sits at 41.1%, down from 43.4% a year ago. Operating
> discipline offset most of that pressure: Operating Expenses grew only 11.6%, holding Operating Margin at
> 18.0%. Profit for the Period reached KWD 24,119, a 29.4% increase driven largely by a stronger prior-year
> base and a smaller effective tax rate. Recommendation: monitor the Cost of Sales trend into Q3 — a further
> 1–2 point Gross Margin decline would begin to outweigh the operating-expense discipline."

Note the narrative's own restraint: it names a real risk (Gross Margin compression) without editorializing
past what the underlying figures support, and its one recommendation is a monitoring instruction, not a
directive — matching `DESIGN_LANGUAGE.md → Design Principle 7`: "the AI stays humble in the UI, exactly as
it stays humble in the API."

Approve/Reject renders through the same `ApprovalCard` pattern as every sensitive action platform-wide;
Reject requires a mandatory reason, fed back into `ai_memory` per the backend's own contract. Per
`FINANCIAL_STATEMENTS.md → AI Responsibilities`'s guardrail, the narrative's own confidence score is "the
weighted minimum of its constituent claims' confidence scores, so one weak claim cannot hide behind an
otherwise strong report" — this screen renders that single blended score on the tab's `ConfidenceBadge`
rather than a separate score per sentence, keeping the approval decision legible at a glance.

**Unavailable AI.** If the Reporting Agent/Forecast Agent call fails, times out, or is disabled for the
company, the affected tab shows a single, calm `StatusPill` ("AI analysis pending/unavailable") rather than
an error state, and every human-driven action (Review, Approve, Export) remains fully available — identical
to `TRIAL_BALANCE.md → AI Integration`'s "Unavailable AI" rule: AI is additive, never a required gate.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | `Skeleton` shaped exactly like the Page Header + toolbar + grid (shimmer sweep per `DESIGN_LANGUAGE.md → Motion → Named patterns`), never a generic spinner |
| Live (MTD/QTD/YTD) | `generation_mode: 'live'` | A small, neutral "Live" `Badge` beside the period label; no separate banner — this is the *normal* state for an open period, not an alert |
| Generating (consolidated/async) | `status: "generating"` on a queued consolidation job | The grid region shows the skeleton; a determinate-feeling "Generating consolidated view…" indicator; Reverb-subscribed, 2s poll fallback |
| Healthy (Net Profit) | `PROFIT_FOR_PERIOD.current_value >= 0` | Plain `ink-950` rendering, no celebratory treatment — per `DESIGN_LANGUAGE.md → Design Principle 5`, a good month gets quiet clarity, not a success band |
| Net Loss | `PROFIT_FOR_PERIOD.current_value < 0` | The figure itself stays plain `ink-950`, formatted in parentheses (`# RTL & Localization`); only its variance-vs-comparison delta chip takes `negative` color. Announced via `role="status" aria-live="polite"`, **not** `role="alert"` — see `# Accessibility` for why a Net Loss is deliberately not treated as an error state |
| Zero-transaction period | No posted `journal_entries` in the resolved period (BR-01) | A fully-structured, all-zero statement — every line renders `0.000`, never an error or an `EmptyState`; a small `info`-toned note reads "No posted transactions for this period" |
| Has AI insights pending | A Margins/Trend/Variance/Narrative tab's job has not yet returned | Calm `StatusPill` ("AI analysis pending") in place of the tab's content; every other action stays available |
| Insufficient trend history | Fewer than 3 comparable historical periods (`AI Responsibilities → Trend Analysis`) | Trend tab shows a plain, honest "Not enough history yet — needs at least 3 comparable periods" message, never a flat or fabricated line |
| Empty (no snapshot for scope) | No `is_current` row for the resolved logical key, closed-period request | `EmptyState`: "No Profit & Loss generated for {period} yet," CTA gated on `.generate`; a lesser copy variant when the viewer lacks `.generate` |
| Empty (filtered to nothing) | A department/project scope with genuinely zero activity in the period | Lighter `EmptyState` variant, "No activity for {department} in {period}," never conflated with the no-snapshot-at-all case |
| Stale/superseded | Viewing a non-current version via History | Persistent, undismissable banner naming the reason (`ImpersonationBanner`-style, per `NAVIGATION_SYSTEM.md`'s precedent); every mutating action disabled |
| Error | `403`/`404`/`5xx`/network failure | `ErrorState` with a retry action; a `403` discovered mid-session collapses only the affected control, per Principle 4 |

# Responsive Behavior

This screen follows `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` exactly, the same mechanism
Trial Balance and Balance Sheet already apply — this section states the outcome for *this* statement's own
priority tiers, not a new mechanism.

**Mobile (`base`–`sm`).** The Page Header collapses to a back-chevron + title; the granularity/comparison
controls stack into a horizontally-scrollable chip row; scope and presentation collapse behind a single
"Filters (n)" trigger opening a `Sheet`. A sticky summary strip — Net Revenue and Profit for the Period only
— pins above the card list and never scrolls away, giving a reader the two numbers that matter most before
they open a single line. The canvas becomes a stack of cards, one per statement line, showing only
`priority: 1` fields (label, current-period amount) with `priority: 2` fields (comparison amount, Δ%) a
tap away via "View full breakdown," which opens that line's full detail (current, comparison, common-size
%, and the Drill-down Sheet trigger) in a full-height `Sheet` — no figure is ever permanently unreachable on
a phone, only reached with one extra tap.

**Tablet and up (`md`+).** The canvas is a real `<table>` with the line-label column pinned via `sticky
start-0` and the money columns scrolling horizontally inside their own `overflow-x-auto` region. Priority
tiers: Net Revenue, Gross Profit, Operating Profit, and Profit for the Period (`priority: 1`) are always
visible; Cost of Sales and Total Operating Expenses (`priority: 2`) show from `md`; the Revenue
sub-lines (Gross Revenue, Returns, Discounts) and the Operating Expense sub-lines (`priority: 3`/`4`) hide
below `lg` and reappear at `lg`+, matching the platform's general rule that a subtotal is always visible
while the lines composing it can legitimately hide first on a small screen.

**Comparison and common-size columns.** Both are additive column sets that render from `xl`+ without
crowding the base statement; below `xl`, enabling a comparison instead opens a dedicated `Sheet` presenting
the expanded view full-width, exactly as `TRIAL_BALANCE.md → Responsive Behavior` handles its own
comparison columns.

**No virtualization needed, unlike Trial Balance.** An Income Statement's `financial_statement_lines`
hierarchy runs to roughly 30–80 rows even at the most granular by-function, fully-disaggregated
presentation — nowhere near Trial Balance's thousands-of-accounts scale — so `StatementGrid` on this screen
never mounts `@tanstack/react-virtual`; every row renders directly, which also means the "capped at 12 rows"
stagger animation on first paint (`DESIGN_LANGUAGE.md → Named patterns`) actually completes in its intended
single pass here rather than needing the batched-13th-slot fallback a large Trial Balance triggers.

**Touch and virtualization of the AI rail.** Row-action icons (line expand/collapse, drill-down) meet the
platform's 44×44px touch minimum regardless of density; the AI rail's Trend tab sparklines render at a
fixed, non-scrolling height on mobile rather than a scrollable mini-chart, since a 12-point trend line adds
negligible DOM cost compared to the statement grid itself.

# RTL & Localization

Every string ships as an EN/AR pair through `next-intl`; the screen inherits `THEMING.md → RTL as a
theming dimension` and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a per-component RTL branch, plus
the following module-specific applications.

- **No Debit/Credit column pair on this screen — a genuine structural contrast with Trial Balance.** Every
  `financial_statement_snapshot_lines` row already carries a single signed `current_value`/`comparison_value`
  pair, not a Debit/Credit pair, so the platform's "Debit-before-Credit is physically fixed, never mirrored"
  rule (`LAYOUT_SYSTEM.md → Rule 2, Exception B`) simply does not apply here. What *does* apply, and applies
  more visibly on this screen than on any other statement, is the **parentheses-for-negative** rule
  (`DESIGN_LANGUAGE.md → Numeral formatting rules`): a Net Loss, a contra-revenue line, or an unfavorable
  Other Income/Expenses subtotal renders as `(4,860.000)`, and because the whole amount — parentheses
  included — sits inside the same `dir="ltr"` isolation `AmountCell` already applies to every numeral, the
  parenthesis pair never reorders or visually detaches from its figure inside an Arabic-direction row.
- **Numeric alignment is physically fixed** (`Exception A`): every money column is `text-right` in both
  directions, never `text-end`, so a bilingual Finance team scanning magnitude by decimal alignment sees
  amounts land on the same edge regardless of session language.
- **Bilingual line labels.** Every line renders `label_ar` when the active locale is `ar`, falling back to
  `label_en` only if the Arabic label is genuinely absent — never the reverse.
- **Account codes, snapshot dates, and confidence percentages stay LTR-embedded** inside Arabic sentences
  (the Variance narrative, the CFO Narrative, the Status Banner-equivalent period label) via the shared
  `Bidi`/`LtrInline` wrapper, so a KWD figure or a percentage never reorders inside a right-to-left
  paragraph.
- **AI reasoning and the CFO Narrative are generated in the viewer's session locale**, per the backend
  spec — rendered as plain translated prose, never re-translated client-side. Arabic accounting terminology
  used precisely: بيان الدخل (Income Statement), الإيرادات (Revenue), تكلفة المبيعات (Cost of Sales),
  إجمالي الربح (Gross Profit), الربح التشغيلي (Operating Profit), صافي الربح (Net Profit) /
  صافي الخسارة (Net Loss).
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching Gulf
  financial-document convention.
- **Directional chrome mirrors; content chrome does not.** The Drill-down and History `Sheet`s slide from
  the logical end edge; breadcrumb chevrons mirror via `rtl-flip`; the `FileBarChart2` nav icon and every
  status/severity icon do not, per `ICONOGRAPHY.md`'s directional-icon table.
- **Charts never mirror.** The Trend tab's `TrendSparkline`s keep a fixed left-to-right time axis regardless
  of `dir`, rendered inside a `dir="ltr"` wrapper, consistent with the platform-wide time-series rule.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`/`THEMING.md`; nothing on this
screen references a raw hex value or a Tailwind palette utility.

- **Surfaces and elevation.** The canvas sits on `--color-bg-canvas`; the Statement Grid card and its
  sticky header/footer sit on `--color-bg-surface`; the Drill-down Sheet and AI rail sit on
  `--color-bg-surface-raised`. Elevation is communicated by the surface getting *lighter* toward the viewer
  in dark mode (canvas → surface → raised), never by shadow alone — the sticky Profit for the Period footer
  row carries its `--color-border-subtle` hairline in dark mode even where the light-mode equivalent leans
  on shadow, because a borderless dark row disappears against the near-black canvas.
- **Net Profit/Loss never takes a status color, only its delta does.** Per `DESIGN_LANGUAGE.md`'s debit/credit
  rule extended to signed statement totals: "Net Income on a P&L... is where a number is genuinely signed
  and the sign genuinely means 'better' or 'worse.' Even there, QAYD colors a small delta annotation next to
  the figure... rather than washing the entire numeral in red or green." Concretely: Profit for the Period
  renders in plain `ink-950` whether it is a profit or a loss (distinguished only by parentheses, per
  `# RTL & Localization`); its variance-vs-comparison delta chip is what carries `positive`/`negative`
  color, and only that chip.
- **AI provenance.** The Margins/Trend/Variance/Narrative rail uses the single platform accent
  (`--color-accent`/`--color-accent-subtle-bg`) for the `ai-insight` card border and the `ConfidenceBadge`
  fill — no second, AI-specific hue, per `DESIGN_LANGUAGE.md`'s "the accent is also the AI's signature" rule,
  identical to Balance Sheet's and Trial Balance's own AI rail treatment.
- **Contrast.** Every pairing is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text, borders, focus
  rings) in both themes per `DARK_MODE.md → Color & Contrast In Dark`.
- **Print/export independence.** Exported PDFs always render in QAYD's fixed light/print palette regardless
  of the viewer's active theme (`DARK_MODE.md → Exported PDFs always render light`) — a board pack is
  routinely forwarded to an Owner or an external bank who never opens the dark-mode app at all.

# Accessibility

Baseline is WCAG 2.2 AA, per `ACCESSIBILITY.md`, implemented without deviation.

- **Real table semantics.** The Statement Grid is a genuine `<table>` with a `<caption>` ("Profit & Loss —
  July 2026, By Nature"), `scope="col"` headers, and `scope="row"` on the line-label cell —
  `ACCESSIBILITY.md → Screen Readers` names financial statements explicitly among the tables that must
  never be a styled `<div>` grid.
- **A Net Loss is deliberately `role="status" aria-live="polite"`, never `role="alert"`.** This is a
  considered departure from Trial Balance's own live-region tiering, worth stating explicitly because it is
  easy to over-alarm: `ACCESSIBILITY.md`'s assertive-announcement tier is reserved for genuinely blocking
  error states (an out-of-balance Trial Balance, a failed generation) — a Net Loss is a true, important, but
  entirely non-error financial fact, and announcing it assertively would train screen-reader users to treat
  ordinary bad-quarter news as a system malfunction. The figure's parenthesized formatting and its
  `VisuallyHidden` text equivalent ("Net loss of KWD 4,860.000") carry the meaning without borrowing the
  error channel's urgency.
- **Amounts are never color-only.** Every `AmountCell` on this screen carries the platform's standing triple
  redundancy — for statement lines specifically, a parenthesis pair (not a bare glyph) plus a
  `VisuallyHidden` equivalent ("Net loss of KWD 4,860.000" vs. "Net profit of KWD 24,119.000") — so a
  colorblind reviewer confirms profitability exactly as confidently as anyone else, and a screen reader user
  never has to infer sign from an unannounced color.
- **Every disabled control explains itself.** A Generate/Approve/Export button disabled for lack of
  permission carries `aria-describedby` naming the exact permission key, distinct in wording from a button
  disabled by a business-rule state ("Complete Review before requesting Approval").
- **Export is omitted, not disabled**, from the `DropdownMenu` when `.export` is absent — identical
  reasoning to `TRIAL_BALANCE.md → Accessibility`'s own rule: a disabled menu item with nothing behind it
  leaves a screen-reader user unable to tell whether the feature exists at all.
- **Focus management on overlays.** The Drill-down Sheet's `onOpenAutoFocus` moves focus to its heading;
  closing it (Escape, backdrop click, or navigating to a linked journal entry/GL view) returns focus to the
  triggering line. `Cmd/Ctrl+Enter` confirms the Generate dialog and the Approve/Reject confirmation dialogs.
- **Keyboard.** Tab order follows visual/DOM order (toolbar → summary strip → grid → AI rail); arrow-key
  navigation is not applicable — the grid is a read-only display table, not an ARIA `grid`, per
  `ACCESSIBILITY.md`'s distinction reserved for genuinely editable surfaces like the Journal Entry line
  editor.
- **Command Palette indexes every synonym.** "Profit and loss," "P&L," "income statement," and "net income"
  all resolve to this one route (`# Route & Access`), so a keyboard-only user's mental model for the screen's
  name never blocks them from finding it.

# Performance

- **Fewer rows than every sibling statement.** An Income Statement's line count (roughly 30–80 rows even at
  full by-function granularity) is an order of magnitude smaller than Trial Balance's full chart-of-accounts
  scan; `StatementGrid` never virtualizes here (`# Responsive Behavior`), and first paint is bound almost
  entirely by the network round trip, not client-side render cost.
- **60-second live-mode caching mirrors the backend's own Redis TTL exactly** (`# Data & State`), so an MTD
  view auto-refreshing on focus never issues more than one real aggregation query per minute per unique
  view, regardless of concurrent viewer count — identical to `FINANCIAL_STATEMENTS.md → Performance →
  Redis caching for real-time`.
- **Snapshot-first for closed periods.** Once a closed month/quarter/year has been generated once, every
  subsequent request for that exact logical key is a single indexed lookup against
  `financial_statement_snapshots`/`financial_statement_snapshot_lines` — no ledger aggregation runs at all,
  the dominant case in practice since most Income Statement views are for already-closed periods (board
  packs, comparatives, audits).
- **Async generation for consolidation only.** A company-, branch-, department-, or project-scoped
  generation is synchronous and fast; only Consolidated Group scope is dispatched as a queued job, per
  `FINANCIAL_STATEMENTS.md → Report Generation Engine → Consolidated`, precisely because it is the one mode
  requiring N subsidiary generations plus translation plus elimination.
- **Debounced, cancellable comparison and common-size fetches.** Switching comparison type or toggling
  common-size debounces 200ms and cancels any in-flight request via `queryClient.cancelQueries` before
  issuing the next one, identical to Trial Balance's own comparison-fetch debounce.
- **Deferred, code-split overlays.** The Generate dialog, History Sheet, Export `DropdownMenu` content, and
  the Schedule dialog are dynamically imported (`next/dynamic`) rather than bundled into the initial route
  chunk.
- **SSR-seeded first paint.** Per `# Data & State`'s hydration pattern, the header, lines, and insights for
  the resolved current view are fetched once, server-side, and hydrated into the client cache.

# Edge Cases

1. **First fiscal period of a new company.** No prior-period/prior-year comparative exists; the comparison
   column, ROA/ROE/turnover ratios, and the Trend tab all return `null` with an explicit `reason:
   "no_prior_period_balance"`/`"insufficient_history"` rather than a misleading zero or a fabricated flat
   trend line, per `FINANCIAL_STATEMENTS.md → Edge Cases`.
2. **Zero-transaction period.** Per BR-01, renders a fully-structured, all-zero statement — never an error —
   important for a dormant branch or a department created mid-year with no postings yet.
3. **Net Loss.** Not an error state (`# States`, `# Accessibility`); the figure renders in parentheses in
   plain ink, and the AI Risk Detection responsibility always evaluates a Net Loss for high-confidence
   flagging (margin compression, an unusual Other Income/Expenses swing) regardless of the company's
   configured anomaly sensitivity threshold, mirroring the backend's identical treatment of negative equity
   on the Balance Sheet.
4. **Department-scoped view that does not sum to the company total.** Per Business Rule 9
   ("segment and dimension totals must reconcile to the whole"), the sum of every department-scoped Income
   Statement for a period must equal the unscoped company-level statement, net of an explicit
   "Unallocated" line; the engine self-checks this on every scheduled comparative run, and this screen
   surfaces a discrepancy as a distinct `info`-toned reconciliation note rather than silently trusting an
   inconsistent departmental rollup.
5. **A department/project scope requested against a template restricted to Income-Statement-only.** Because
   `financial_statement_templates.department_scope_mode` defaults to `'income_statement_only'`, this request
   always succeeds here — this is precisely the statement type that scope restriction was designed to
   permit; the same department id would 422 if a caller reached it from the Balance Sheet screen instead.
6. **Subsidiary acquired or disposed of mid-year, viewed at Consolidated Group scope.** The consolidated
   Income Statement includes the subsidiary's results only from the acquisition date (or up to the disposal
   date); any gain/loss on disposal renders as a distinct, clearly labeled line rather than blended into
   Other Operating Income, per `FINANCIAL_STATEMENTS.md → Consolidation → Subsidiaries`.
7. **Intercompany revenue and unrealized profit elimination at Consolidated Group scope.** The Cost of Sales
   line on a consolidated P&L can differ materially from the simple sum of subsidiary Cost of Sales lines
   once the unrealized-intercompany-profit-in-inventory elimination posts (`Dr. Cost of Sales (Group) /
   Cr. Inventory (Group)`, per `FINANCIAL_STATEMENTS.md → Consolidation → Eliminations`); the Drill-down
   Sheet on a consolidated Cost of Sales line surfaces the elimination entry alongside the subsidiaries'
   own entries so this never reads as an unexplained discrepancy.
8. **A drill-down leads to a blocked correcting entry (GAAP mode).** When `framework = 'GAAP'`, attempting to
   post a reversing entry against a previously-recognized impairment loss from this screen's Drill-down
   Sheet is blocked server-side (Business Rule 7) with a 409; the Sheet surfaces the exact message rather
   than a generic failure toast, since the restriction is a real accounting rule, not a bug.
9. **Missing exchange rate on a translated branch/subsidiary P&L.** Per VR-08, generation is blocked rather
   than defaulted to a rate of 1.0; the error response names the exact currency pair and date required, and
   this screen's error surface repeats that detail verbatim rather than a generic "generation failed."
10. **Regeneration of a filed statutory-year Income Statement.** Blocked from silent update by the same
    immutability trigger Balance Sheet's snapshots carry; a material correction requires a new, reasoned
    superseding snapshot and a mandatory restatement disclosure Note, never an in-place edit.
11. **Concurrent live-mode viewers during the same 60-second window.** The second viewer is served the first
    viewer's cached result rather than re-running the aggregation; a posting during that window busts the
    cache immediately (cache-bust on `journal_entries` posting events, not solely TTL expiry) so a Finance
    Manager refreshing right after entering a late invoice never sees a stale-but-not-yet-expired number.
12. **A closed period is later reopened.** Any snapshot generated against that period keeps rendering as
    valid history when opened directly, but loses `is_current` and gains an informational finding
    ("underlying period was reopened; regenerate before relying on this as current") at the top of the
    Variance tab.
13. **Presentation switch (by nature ↔ by function) attempted without `.generate`.** The `ToggleGroup`
    renders visible-but-disabled with a permission tooltip rather than silently doing nothing on click,
    consistent with the platform's disabled-with-explanation rule for every other business-rule-gated
    control on this screen.
14. **Session permission change mid-view.** If `.approve` is revoked while a CFO Narrative `ApprovalCard` is
    open, the next mutation attempt's `403` collapses that card to its read-only rendering with a toast
    explaining why, rather than a broken/stuck loading state — the frontend never assumes its own last-known
    permission snapshot is still valid, per `FRONTEND_ARCHITECTURE.md → Principle 4`.
15. **Company switch mid-Generate.** Switching the active company discards all cached Income Statement
    state; if a Generate dialog with unsaved granularity/scope selections is open, the switcher's
    confirmation dialog names that in-progress action before proceeding.

# End of Document
