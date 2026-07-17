# Cash Flow Statement — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: CASH_FLOW
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Cash Flow Statement screen — the Statement of
Cash Flows rendering of the Accounting Engine's Financial Statements module
(`docs/accounting/FINANCIAL_STATEMENTS.md → Financial Statements → Cash Flow Statement`), extended on this
one screen with a forward-looking AI Forecast/Treasury cash-projection overlay and a live liquidity view
bridged in from the Banking module (`docs/accounting/BANKING.md`, `docs/frontend/BANKING.md`). The screen
renders, for any company, branch, or consolidated group, for any fiscal period, the full Operating /
Investing / Financing breakdown the backend derives — by the indirect method by default, by the direct
method on request — from posted `journal_lines` and from the period-over-period movement of the Balance
Sheet's own account balances, reconciling to the exact movement in Cash and Cash Equivalents between the
period's opening and closing Balance Sheet dates.

Like every screen in this documentation set, this one contains no financial logic of its own. It never
computes a cash movement, decides which journal lines belong to Operating versus Investing versus Financing,
or determines whether the statement's Cash and Cash Equivalents at End of Period actually equals the Balance
Sheet's own Cash and Cash Equivalents line for the same `as_of_date` — the Report Generation Engine on the
Laravel API does all of that (`FINANCIAL_STATEMENTS.md → Report Generation Engine`, `→ Cash Flow Statement →
Computation rule`), and this screen's only job is to request a statement, render exactly what comes back, and
offer the human affordances — comparison, scope, currency, method, drill-down, export, share, schedule,
review, approve — that the backend module already defines as its capabilities. The one client-side
computation this screen performs at all is the Cash Flow ratio set under `# AI Integration` (Operating Cash
Flow Ratio, Free Cash Flow, Cash Flow Margin, Cash Flow to Debt) — and even those are rendered from figures
the `/insights` endpoint already computed server-side, exactly the same "convenience, never authority"
posture `BALANCE_SHEET.md` establishes for its own common-size column.

Four properties make this screen the most structurally distinct of the three primary statements, and every
section below is organized around them:

1. **The statement's own headline identity is a reconciliation, not a balance.** Balance Sheet's screen
   exists to prove `Assets = Liabilities + Equity`; this screen exists to prove that the sum of Operating,
   Investing, and Financing cash movement, added to the period's opening cash, produces exactly the closing
   cash figure the Balance Sheet independently reports for the same date (`FINANCIAL_STATEMENTS.md →
   Validation Rules → VR-03`). That single arithmetic fact — not a debit/credit equality — is this screen's
   equivalent of `BalanceIdentityBanner`, and it behaves differently on failure, deliberately: VR-03's
   backend framing is that a mismatch is *always* a mapping-configuration bug, never a legitimate data state
   a user is expected to resolve by posting a correcting entry (contrast Balance Sheet's VR-01, which *is* a
   routine, user-actionable, out-of-balance state). `# States` and `# Accessibility` both design around this
   distinction explicitly rather than reusing `BalanceIdentityBanner`'s failure treatment unmodified.
2. **It is the only primary statement with a native forward-looking overlay.** The Balance Sheet and Income
   Statement describe what already happened; the Cash Flow screen additionally carries the Forecast Agent's
   rolling 13-week cash-flow projection (`decision_type: 'forecast_cash_projection'`, first defined in
   `docs/accounting/BANKING.md → Cash Management → Forecast` and `→ AI Responsibilities`, consumed
   operationally by the Treasury Manager per `docs/ai/agents/TREASURY_AGENT.md`) as a confidence-banded
   ribbon extending past the audited statement's own period end. No other financial-statement screen in this
   product renders a number that has not yet happened.
3. **It is the one statement screen that visibly touches live Banking data.** A Liquidity Strip — the exact
   `KpiTile` band `docs/frontend/BANKING.md → Layout & Regions` already documents for the Banking Home
   screen's Cash Position band — is reused verbatim here, because a CFO reading "Net Increase in Cash: KWD
   34,220.150 this month" wants the answer to "and how much do we actually have in the bank right now" one
   glance away, not a navigation away.
4. **Its drill-down is three different mechanisms wearing one interaction, not one.** A Balance Sheet line
   drills into a static balance's contributing journal entries. A Cash Flow line drills into one of three
   genuinely different things depending on the line's `financial_statement_lines.line_kind` and
   `cash_flow_category` — a non-cash adjustment's own journal lines, a working-capital line's two
   contributing Balance Sheet snapshots, or an Investing/Financing line's cash-touching journal entries — and
   `# Interactions & Flows` documents each path by name rather than pretending one drill-down affordance
   covers all three equally well.

This screen is gated by the same `accounting.financial-statements.*` permission family `BALANCE_SHEET.md`
establishes — deliberately not a separate `accounting.cash-flow.*` namespace, because the underlying
`financial_statement_snapshots` row, its review/approval lifecycle, and its export/share/schedule mechanics
are one module regardless of `statement_type`. Every fact this document states about the six-stage
generation pipeline, the indirect-method computation rule, the direct-method alternative and its mandatory
reconciliation Note, VR-03, and the seven `financial_statement_*` tables is inherited verbatim from
`FINANCIAL_STATEMENTS.md`; nothing here redefines them. Sibling routes
`accounting/financial-statements/balance-sheet` and `accounting/financial-statements/profit-and-loss` share
this exact page shape and permission family, documented in their own screen specs.

# Route & Access

## Route tree

```text
app/(app)/accounting/financial-statements/cash-flow/
├── page.tsx              # Server Component — first-paint fetch, hydrates the client grid
├── loading.tsx           # Statement-shaped skeleton (see # States)
└── error.tsx             # Route-level error boundary (generation/reconciliation failure)
```

`page.tsx` is a thin Server Component, structurally identical to `balance-sheet/page.tsx`
(`BALANCE_SHEET.md → Route & Access`): it parses and validates `searchParams` against
`cashFlowSearchParamsSchema`, prefetches the primary statement query into a `QueryClient`, and hands a
`dehydrate()`d cache to a client `<CashFlowScreen>`, following the identical Server-Component-prefetch →
`HydrationBoundary` → Client-Component-`useQuery` handoff `FRONTEND_ARCHITECTURE.md → Data Layer` mandates
for every statement-shaped route:

```tsx
// app/(app)/accounting/financial-statements/cash-flow/page.tsx
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { cashFlowKeys } from "@/lib/api/query-keys";
import { parseCashFlowParams } from "@/lib/schemas/cash-flow";
import { CashFlowScreen } from "@/components/accounting/cash-flow-screen";

export default async function CashFlowPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const params = parseCashFlowParams(await searchParams);
  const queryClient = getQueryClient();

  await queryClient.prefetchQuery({
    queryKey: cashFlowKeys.view(params),
    queryFn: () => apiServer.get("/accounting/financial-statements/cash-flow", { params }),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <CashFlowScreen initialParams={params} />
    </HydrationBoundary>
  );
}
```

Every toolbar control — period, comparison, scope, presentation currency, method (indirect/direct), and
whether the Forecast overlay is shown — reads from and writes back to these same URL search params via
`router.replace(..., { scroll: false })`, never a Zustand store, for the identical reason
`BALANCE_SHEET.md → Route & Access` gives: a Cash Flow view must survive a refresh and must be shareable as
a plain link ("here's the Q2 Cash Flow with the 13-week forecast overlay, consolidated, in KWD") without the
recipient re-selecting five controls by hand.

## Access gate

The route sits inside `(app)`, behind `middleware.ts`'s session check and the resolved `X-Company-Id`
context. A user without `accounting.financial-statements.read` never sees "Cash Flow" in the Accounting
sidebar's Financial Statements group, never sees it in the Command Palette's fuzzy-search index (RBAC
filters the index at the data layer, per `ACCESSIBILITY.md → Command Palette`), and a direct hit on the URL
renders `<ForbiddenState>` rather than a generic 404 — the route and the underlying fiscal period genuinely
exist; the viewer is simply not permitted to see them.

## Permission surface on this screen

Every permission key below is authoritative on the backend (`FINANCIAL_STATEMENTS.md → Permissions`,
adapted to the `accounting.*` namespace exactly as `BALANCE_SHEET.md` establishes); this table is this
screen's map of key → concrete UI effect.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `accounting.financial-statements.read` | The route itself; the statement grid; comparative columns; the AI Insights rail's Ratios/Variance/Narrative tabs | Route renders `<ForbiddenState>`; nav item and Command Palette entry hidden |
| `accounting.financial-statements.generate` | "Regenerate" (force a fresh live pull); the Indirect/Direct method preview toggle; consolidated/branch scope switch | Button and toggle removed from the toolbar; screen renders the last-cached or last-snapshotted view |
| `accounting.financial-statements.review` | Moving a `draft`/`under_review` snapshot forward toward `final` | "Send for review" action omitted |
| `accounting.financial-statements.approve` | "Mark Final"; approving a pending consolidation elimination set; approving the CFO treasury-briefing narrative before it can be exported/shared | Approve affordances on the Reconciliation Band, the AI Insights rail, and the snapshot status menu are omitted, not disabled |
| `accounting.financial-statements.export` | The Export menu (PDF/Excel/CSV/JSON) | Menu item removed from the toolbar overflow |
| `accounting.financial-statements.share` | The Share dialog (external time-limited link) | Menu item removed |
| `accounting.financial-statements.schedule` | The Schedule dialog (recurring board-pack distribution) | Menu item removed |
| `accounting.financial-statements.consolidation.manage` | Viewing/approving the intercompany elimination set behind a Consolidated Group scope | Consolidated scope stays selectable for viewing; the "Approve eliminations" action is omitted |
| `accounting.financial-statements.notes.write` | The "Add reconciliation note" action on the direct-vs-indirect disclosure, and any materiality-flagged line | Action omitted; the gap still surfaces as a read-only AI flag |

Default role grants mirror `FINANCIAL_STATEMENTS.md → Permissions → Role matrix` exactly, renamespaced from
`reports.financial_statements.*` to `accounting.financial-statements.*` per `BALANCE_SHEET.md`'s own
precedent: Owner, Admin, Finance Manager, and CFO hold every key above; Senior Accountant/Accountant hold
every key except `.approve` and `.consolidation.manage`; Auditor and External Auditor hold `.read`,
`.review`, and `.export` only; the AI Agent identity holds `.read` and nothing else — it can enrich a
snapshot with insights and it can never call `.generate`, `.approve`, `.export`, `.share`, or
`.consolidation.manage`, matching the platform-wide rule that AI never bypasses authorization.

## Cross-module permission surface — the Liquidity Strip

The Liquidity Strip (`# Layout & Regions`, `# Components Used`) is a verbatim reuse of
`docs/frontend/BANKING.md → Layout & Regions`'s Cash Position band, so it is gated by that screen's own
permissions, not a new accounting-scoped grant: `bank.read` for the Cash Position / Available Balance /
Restricted Balance tiles, `treasury.read` for the Liquidity Ratio tile and the Forecast ribbon's data source
(`docs/accounting/BANKING.md → Permissions`). Consistent with `BANKING.md`'s own stated rule for this exact
band ("Tile renders with an em dash and a `Lock` icon plus a tooltip naming the missing permission, rather
than being omitted"), a viewer who holds `accounting.financial-statements.read` but lacks `bank.read`/
`treasury.read` still sees all four Liquidity Strip tiles — locked, not hidden — because the Strip's
presence on this screen is itself informative ("there is more context here than you currently have access
to"), the identical reasoning `BANKING.md` already applied on its own screen.

This produces one genuinely asymmetric, worth-naming case: `docs/accounting/BANKING.md → Permissions → Role
assignment table` grants the **Treasury** role full `bank.read`/`treasury.read`, but Treasury is not among
the default roles `FINANCIAL_STATEMENTS.md` grants `accounting.financial-statements.read` to. A pure
Treasury-role user therefore does **not** see "Cash Flow" in the Accounting sidebar at all — they reach the
equivalent forecast/liquidity picture natively from Banking's own Treasury Dashboard
(`docs/frontend/BANKING.md`), never from this screen. The reverse holds for a Senior Accountant: full access
to this screen's audited statement, a locked Liquidity Strip unless the company has additionally granted
`treasury.read`. Neither gap is a bug; `# Edge Cases` documents both explicitly so a future reviewer does not
"fix" one into a permission the platform's default role design deliberately withholds.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `A` opens Accounting; from there
this screen is one click into "Financial Statements → Cash Flow," or reachable directly from the Command
Palette by typing "cash flow" or "liquidity." `Cmd/Ctrl+P` opens the Export menu pre-focused on PDF; `Esc`
from any open drill-down `Sheet` or the Forecast tab's detail popover returns focus to the control that
opened it.

# Layout & Regions

The screen composes the platform's Report Page Template (`LAYOUT_SYSTEM.md → Page Templates → Report Page
Template`) — the same template Balance Sheet and Trial Balance use — with two additions specific to this
screen: the docked AI Insights rail Balance Sheet already establishes for statements with a persistent
analysis surface, here re-themed around Forecast/Liquidity/Variance/Narrative rather than Ratios/Variance/
Risks/Narrative; and a full-width Liquidity Strip sitting between the toolbar and the statement grid, absent
from Balance Sheet and Profit & Loss because only Cash Flow is contractually about cash movement in the
first place.

## Desktop (`xl:` and above)

```text
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Accounting / Financial Statements / Cash Flow                                            │
│ Cash Flow Statement                                          [Final ✓]  [History ▾]  [⋯]  │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ [Jul 2026 ▾] [vs Jun 2026 ▾] [Company ▾] [KWD ▾] [Indirect ▾] [☐ Show forecast] [↻]      │
│                                                          [Export ▾] [Share] [Schedule]     │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ Cash position  61,000.000  │ Available  54,312.200  │ Restricted  0.000  │ Liquidity 1.46 │  Liquidity Strip
├────────────────────────────────────────────────────────────────────┬─────────────────────┤
│ ✓ End of period cash reconciles to Balance Sheet          (sticky) │  AI Insights (360px) │
├────────────────────────────────────────────────────────────────────┤  Tabs: Forecast │    │
│ Line (sticky start)                              Jul 2026  Jun 2026│  Liquidity │ Variance │
│ Cash Flows from Operating Activities                                │  Narrative           │
│   Profit for the Period                          48,200.000 41,900.0├─────────────────────┤
│   Adjustments: Depreciation and Amortization      6,140.000  5,900.0│  ▂▃▅▆▇ 13-wk forecast│
│   (Increase)/Decrease in Trade Receivables      (12,400.000)(3,100.0│  Week of Jul 20      │
│   Increase/(Decrease) in Trade Payables           4,850.000  2,200.0│  Net +8,140  ●92%    │
│ Cash Generated from Operations                   46,790.000 46,900.0│  Week of Oct 12      │
│ Net Cash from Operating Activities               41,220.000 40,650.0│  Net +6,200  ●61%    │
│ ▸ Cash Flows from Investing Activities           (9,800.000)(4,200.0│  ──────────────────  │
│ ▸ Cash Flows from Financing Activities            2,800.000 (6,000.0│  Liquidity ratio 1.46 │
│ Net Increase/(Decrease) in Cash                  34,220.150 30,450.0│  above 1.20 floor ✓  │
│ Cash and Cash Equivalents at Beginning of Period 226,879.850196,429.8│  ──────────────────  │
│ Cash and Cash Equivalents at End of Period       261,100.000226,879.8│  CFO Treasury Brief   │
└────────────────────────────────────────────────────────────────────┴  [Unreviewed AI Draft]┘
                                                                        Approve · Reject
```

## Mobile / Tablet (below `xl:`)

```text
┌───────────────────────────────┐
│ ‹ Cash Flow              [⋯]  │
│ [Jul 2026 ▾]                  │
│ Tabs: Statement│Forecast│Liq. │
├───────────────────────────────┤
│ ✓ Reconciled        (sticky)  │
├───────────────────────────────┤
│ Line → scroll →               │
│ ▾ Operating Activities         │
│    Profit for the Period 48,200│
│    D&A adj.                6,140│
├───────────────────────────────┤
│ [Filters] [Columns] [⋯ Export]│
└───────────────────────────────┘
```

## Regions

| Region | Content |
|---|---|
| Page Header | Breadcrumb, title, snapshot `<StatusPill>` (`draft`/`under_review`/`final`/`superseded`), "History," overflow menu |
| Toolbar | Period (`PeriodPicker`), comparison type, scope (Company/Branch/Consolidated Group), presentation currency, method (`Indirect`/`Direct`), "Show forecast" `Switch`, Regenerate, Export, Share, Schedule |
| Liquidity Strip | Four `KpiTile`s reused verbatim from `BANKING.md`'s Cash Position band — Cash Position, Available Balance, Restricted Balance, Liquidity Ratio — each with its own `<Suspense>` boundary (`BANKING.md → Layout & Regions`) |
| Reconciliation Band | Full-width, sticky below the Liquidity Strip — the End-of-Period-Cash-reconciles-to-Balance-Sheet check (VR-03); see `# Components Used` and `# States` for why this is deliberately quieter than `BalanceIdentityBanner`'s failure state |
| Statement Grid | The sticky-first-column hierarchical `<table>` — Operating / Investing / Financing sections, each collapsible, each with its own subtotal, culminating in the Net Increase/Decrease and the Beginning/End-of-Period cash rows |
| AI Insights Rail | Docked inline at `xl:`+; a full-screen `Sheet` (mobile/tablet) or `Tabs`-selected pane below `xl:` — Forecast, Liquidity, Variance, Narrative |
| Drill-down Sheet | Slides from the logical end edge; its content depends on which of the three drill-down mechanisms the clicked line uses (`# Interactions & Flows`) |

# Components Used

Every visual element is either a primitive/finance component already cataloged in `COMPONENT_LIBRARY.md`
and reused unmodified, a component `DARK_MODE.md`/`BANKING.md` already specified and this screen composes
without touching, or a screen-owned finance component this document introduces.

| Component | Source | Role on this screen |
|---|---|---|
| `PeriodPicker` (`mode="fiscal_period"`) | `components/accounting/period-picker.tsx` | Period selection, grouped Open / Locked-Closed |
| `StatusPill` (`domain="financial_statement_snapshot"`) | `components/shared/status-pill.tsx` | `draft` / `under_review` / `final` / `superseded` |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every monetary figure; parentheses-for-negative on this rendered statement, per `DESIGN_LANGUAGE.md → Numeral formatting rules`, which names Cash Flow explicitly |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Presentation-currency indicator |
| `KpiTile` | `components/dashboard/kpi-tile.tsx` | The four Liquidity Strip tiles, reused verbatim from `BANKING.md` |
| `TrendSparkline` | `components/dashboard/trend-sparkline.tsx` | 30-day trend inside the Cash Position tile (identical instance to Banking's own); a compact forecast-shape preview inside the Liquidity Ratio tile's tooltip |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Every forecast week bucket, every ratio interpretation, the CFO narrative |
| `AIProposalPanel` / `ApprovalCard` | `components/ai/ai-proposal-panel.tsx`, `components/shared/approval-card.tsx` | CFO treasury-briefing narrative and liquidity-alert approve/reject inside the AI Insights rail |
| `Tabs` | `components/ui/tabs.tsx` | AI Insights rail's Forecast/Liquidity/Variance/Narrative sub-navigation; Statement/Forecast/Liquidity switch below `xl:` |
| `ToggleGroup` | `components/ui/toggle-group.tsx` | Indirect / Direct method toggle |
| `Switch` | `components/ui/switch.tsx` | "Show forecast" toggle; "Show low-confidence AI analysis" toggle |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Export format menu, snapshot History menu |
| `Dialog` | `components/ui/dialog.tsx` | Share link dialog, Schedule dialog, "Reason required" regeneration dialog |
| `Sheet` | `components/ui/sheet.tsx` | Drill-down Panel, mobile Filters/Columns/AI Insights |
| `Skeleton` | `components/ui/skeleton.tsx` | Statement-shaped loading state, Liquidity Strip and Forecast tab "Loading…" placeholders |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `error-state.tsx` | Zero-transaction period; generation/reconciliation failure |
| `Tooltip` | `components/ui/tooltip.tsx` | "Why 92%?" on every `ConfidenceBadge`; locked-permission and disabled-control explanations |
| `CashFlowWaterfall` | `components/charts/cash-flow-waterfall.tsx` (already specified in `DARK_MODE.md → Financial charts map to semantic tokens`) | Renders Operating/Investing/Financing/Net Change as status-toned bars; extended below with an additive forecast ribbon |
| `ScopeSelect` / `ComparisonSelect` | `components/accounting/scope-select.tsx`, `comparison-select.tsx` (shared with `BALANCE_SHEET.md`) | Company / Branch: {name} / Consolidated Group; None / Prior Period / Prior Year / Budget |
| `CashFlowReconciliationBand` *(new)* | `components/accounting/cash-flow-reconciliation-band.tsx` | The VR-03 pass/fail band, deliberately not `BalanceIdentityBanner` — see below |
| `CashFlowStatementGrid` *(new)* | `components/accounting/cash-flow-statement-grid.tsx` | The sticky-first-column, expandable Operating/Investing/Financing tree, built on the identical primitives `StatementGrid` (`BALANCE_SHEET.md`) uses |
| `CashFlowForecastPanel` *(new)* | `components/accounting/cash-flow-forecast-panel.tsx` | The AI Insights rail's Forecast tab: `CashFlowWaterfall` + a confidence ribbon + a per-week detail list |
| `LiquidityStrip` *(reused, not new)* | `components/banking/liquidity-strip.tsx` | Same component `BANKING.md` mounts on the Banking Home screen; imported here rather than re-implemented |

`CashFlowReconciliationBand` is a distinct component from `BalanceIdentityBanner`, not a themed variant of it,
because the two identities differ in kind, not just in label. `BalanceIdentityBanner`'s failing state invites
a user action (view the diagnostic, find the unbalanced entry); VR-03's failing state is, per
`FINANCIAL_STATEMENTS.md`, always a mapping-configuration defect the engine itself should not have produced —
so this band's failing state offers no "view diagnostic" affordance at all, only a calm explanation and a
"Contact support" path, matching `# States` and `# Accessibility` below:

```tsx
// components/accounting/cash-flow-reconciliation-band.tsx
import { CheckCircle2, AlertOctagon } from 'lucide-react';
import { AmountCell } from '@/components/accounting/amount-cell';
import { cn } from '@/lib/utils';

interface CashFlowReconciliationBandProps {
  isReconciled: boolean;
  endOfPeriodCash: string;          // this statement's own computed figure
  balanceSheetCash: string | null;  // the Balance Sheet's Cash and Cash Equivalents line for the same as_of_date
  currencyCode: string;
  onContactSupport: () => void;
}

export function CashFlowReconciliationBand({
  isReconciled, endOfPeriodCash, balanceSheetCash, currencyCode, onContactSupport,
}: CashFlowReconciliationBandProps) {
  return (
    <div
      role={isReconciled ? 'status' : 'alert'}
      className={cn(
        'sticky top-[var(--liquidity-strip-h)] z-sticky flex items-center justify-between gap-3',
        'border-b px-4 py-2.5 text-sm',
        isReconciled ? 'border-ink-150 bg-ink-0 text-ink-950' : 'border-danger/30 bg-danger/5 text-danger',
      )}
    >
      <span className="flex items-center gap-2">
        {isReconciled
          ? <CheckCircle2 className="h-4 w-4 text-ink-500" aria-hidden />
          : <AlertOctagon className="h-4 w-4 text-danger" aria-hidden />}
        {isReconciled
          ? <>End of period cash reconciles to the Balance Sheet
              (<AmountCell amount={endOfPeriodCash} currencyCode={currencyCode} showCurrency />)</>
          : <>This statement could not be reconciled to the Balance Sheet — this indicates a
              configuration issue, not a data entry to fix yourself.</>}
      </span>
      {!isReconciled && (
        <button type="button" onClick={onContactSupport} className="text-sm underline underline-offset-2">
          Contact support
        </button>
      )}
    </div>
  );
}
```

Note what is absent relative to `BalanceIdentityBanner`: no "View diagnostic" button, no variance amount
rendered as something to investigate line-by-line — because per `FINANCIAL_STATEMENTS.md → VR-03`, the
correct next step for a genuine mismatch is an engineering investigation into the template's
`cash_flow_category`/`is_non_cash_adjustment` mapping, never a user re-deriving it from the grid. In the
passing state, the band still follows Design Principle 5's quiet-certainty rule identically to
`BalanceIdentityBanner` — plain `ink-950` on `ink-0`, a small neutral checkmark, never a green success band.

`CashFlowWaterfall` is extended additively, never forked, with an optional `forecastSeries` prop — the same
composition-over-modification discipline `COMPONENT_LIBRARY.md`'s authoring conventions require of every
finance component in the catalog:

```tsx
// components/accounting/cash-flow-forecast-panel.tsx (excerpt)
'use client';
import { CashFlowWaterfall } from '@/components/charts/cash-flow-waterfall';
import { useChartTokens } from '@/hooks/use-chart-tokens';
import { Area, ComposedChart, Line } from 'recharts';

interface ForecastWeek {
  weekStart: string;            // ISO date
  projectedNet: string;         // NUMERIC(19,4) string — signed
  confidenceLow: string;
  confidenceHigh: string;
  confidenceScore: number;      // 0–100, decays with horizon per TREASURY_AGENT.md
}

export function CashFlowForecastPanel({
  actualLines, forecastWeeks, currencyCode,
}: { actualLines: CashFlowLine[]; forecastWeeks: ForecastWeek[]; currencyCode: string }) {
  const tokens = useChartTokens(); // re-reads CSS custom properties on theme change — DARK_MODE.md

  return (
    <div className="space-y-3">
      <CashFlowWaterfall data={actualLines} currencyCode={currencyCode} />
      {/* The forecast ribbon is a second, explicitly separate chart region — never appended onto the
          same bars as the audited actuals, so a glance never mistakes a projection for a posted figure. */}
      <ComposedChart width={320} height={120} data={forecastWeeks}>
        <Area
          dataKey="confidenceHigh" stroke="none" fill={tokens.accentSubtleBg} fillOpacity={0.5}
        />
        <Area dataKey="confidenceLow" stroke="none" fill={tokens.surfaceOverlay} fillOpacity={1} />
        <Line dataKey="projectedNet" stroke={tokens.accent600} strokeWidth={1.5} dot={false} />
      </ComposedChart>
    </div>
  );
}
```

The ribbon's fill draws from the **accent** token family (`--chart-accent`/`--accent-subtle-bg`), never from
the categorical `--chart-1`…`--chart-5` palette and never from the `--status-*` tokens the actuals bars use —
per `DARK_MODE.md → AI provenance color — reserved, never financial`, the accent is QAYD's one reserved
signature for "AI produced this," and a forecast is exactly the class of AI-produced, not-yet-audited figure
that rule exists to keep visually distinct from a posted actual, in both themes, without introducing a sixth
chart hue.

# Data & State

## Endpoints consumed

Accounting endpoints are under `/api/v1/accounting/financial-statements/cash-flow`, Bearer +
`X-Company-Id`, standard envelope, per `FINANCIAL_STATEMENTS.md → API` (the screen calls this
statement-scoped alias; Laravel routes it to the same `FinancialStatementService` the generic
`/financial-statements/*` endpoints use, with `statement_type=cash_flow_statement` fixed server-side —
identical in spirit to how `BALANCE_SHEET.md`'s screen calls its own `/balance-sheet` alias). Banking
endpoints for the Liquidity Strip are the exact ones `docs/accounting/BANKING.md → API` and
`docs/ai/agents/TREASURY_AGENT.md → Tools & API Access` already define.

| Method | Path | Permission | Used for |
|---|---|---|---|
| GET | `/accounting/financial-statements/cash-flow` | `.read` | Resolve the current snapshot for the active period/scope/method on page load |
| GET | `/accounting/financial-statements/cash-flow/{id}` | `.read` | Snapshot header (status, totals, reconciliation result) |
| GET | `/accounting/financial-statements/cash-flow/{id}/lines` | `.read` | Operating/Investing/Financing line data feeding `CashFlowStatementGrid` |
| GET | `/accounting/financial-statements/cash-flow/{id}/drill-down` | `.read` | Contributing rows behind a line — shape depends on `line_kind` (`# Interactions & Flows`) |
| GET | `/accounting/financial-statements/cash-flow/comparative` | `.read` | Comparison-period variance columns |
| GET | `/accounting/financial-statements/cash-flow/{id}/insights` | `.read` | Ratio/variance/narrative AI output for this snapshot |
| GET | `/accounting/financial-statements/cash-flow/{id}/notes` | `.read` | Direct-vs-indirect reconciliation Note and any materiality-flagged disclosures |
| GET | `/accounting/financial-statements/cash-flow/{id}/history` | `.read` (implicit; no separate history-read key on this statement) | Version lineage |
| POST | `/accounting/financial-statements/cash-flow/generate` | `.generate` | Generate dialog submit; also used for the Indirect ↔ Direct method preview toggle (`mode=preview`) |
| POST | `/accounting/financial-statements/cash-flow/{id}/refresh` | `.generate` | Refresh action on an existing logical snapshot |
| POST | `/accounting/financial-statements/cash-flow/{id}/review` | `.review` | "Start Review" |
| POST | `/accounting/financial-statements/cash-flow/{id}/approve` | `.approve` | Each approval-chain step; also gates approving the CFO treasury-briefing narrative before export |
| POST | `/accounting/financial-statements/cash-flow/{id}/export` | `.export` | Export menu |
| POST | `/accounting/financial-statements/cash-flow/{id}/share` | `.share` | Share dialog |
| DELETE | `/accounting/financial-statements/shares/{shareId}` | `.share` | Revoke a share link |
| POST | `/accounting/financial-statements/schedules` | `.schedule` | Recurring board-pack distribution |
| POST | `/accounting/financial-statements/notes` | `.notes.write` | Add/version a reconciliation or disclosure note |
| GET | `/banking/cash-position` | `bank.read` | Liquidity Strip — Cash Position, Available, Restricted tiles |
| GET | `/banking/liquidity` | `treasury.read` | Liquidity Strip — Liquidity Ratio tile |
| GET | `/banking/cash-flow-forecast` | `treasury.read` | Forecast tab — the Forecast Agent's weekly `forecast_cash_projection` buckets |
| GET | `/ai/decisions?agent_code=TREASURY_AGENT&decision_type=treasury_liquidity_alert` | `ai.analyze` | Liquidity tab — the current liquidity-alert decision, if any, with its full reasoning |

## Query keys

```ts
// lib/api/query-keys.ts (extends the factory pattern from FRONTEND_ARCHITECTURE.md)
export const cashFlowKeys = {
  all: ["accounting", "cash-flow"] as const,
  view: (params: CashFlowParams) => [...cashFlowKeys.all, "view", params] as const,
  detail: (snapshotId: number) => [...cashFlowKeys.all, "detail", snapshotId] as const,
  lines: (snapshotId: number, method: "indirect" | "direct") =>
    [...cashFlowKeys.detail(snapshotId), "lines", method] as const,
  insights: (snapshotId: number) => [...cashFlowKeys.detail(snapshotId), "insights"] as const,
  notes: (snapshotId: number) => [...cashFlowKeys.detail(snapshotId), "notes"] as const,
  drillDown: (snapshotId: number, lineCode: string) =>
    [...cashFlowKeys.detail(snapshotId), "drill-down", lineCode] as const,
  comparative: (snapshotIds: number[]) => [...cashFlowKeys.all, "comparative", snapshotIds] as const,
};

export const liquidityKeys = {
  position: () => ["banking", "cash-position"] as const,
  liquidity: () => ["banking", "liquidity"] as const,
  forecast: (horizonWeeks: number) => ["banking", "cash-flow-forecast", horizonWeeks] as const,
};
```

`CashFlowParams` is `{ period | as_of, branch_id, department_id, project_id, currency_code, method,
compare_with }` — the same logical-key shape `BALANCE_SHEET.md`'s `balanceSheetKeys.view` uses, with
`method` (`indirect`/`direct`) added, since switching method re-resolves which snapshot is "current" exactly
as switching scope does.

## Cache tuning by snapshot status

Mirrors `TRIAL_BALANCE.md → Data & State → Cache tuning by snapshot status` exactly for the statement
itself, plus the AI-feed class `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` defines for anything
agent-produced:

| Resource | `staleTime` | Rationale |
|---|---|---|
| Snapshot at `status: "generating"` | `0`, polled every 2s as a Reverb fallback | Actively changing |
| Snapshot at `draft`/`under_review` | 30 seconds | Findings/notes can be resolved concurrently by another reviewer |
| Snapshot at `final` | 5 minutes | Structurally immutable per the platform's snapshot trigger |
| Snapshot at `archived` | `Infinity` | Immutable, locked, permanent |
| `/banking/cash-position`, `/banking/liquidity` | `0` (always stale) | Live/derived figures, kept fresh via Realtime invalidation rather than polling, per `FRONTEND_ARCHITECTURE.md`'s "Live/derived figures" row |
| `/banking/cash-flow-forecast`, `/insights` | 10 seconds, `refetchOnWindowFocus: true` | Async, agent-produced — a returning user should see what accumulated while away |

## Realtime

Three Laravel Reverb channels, per the platform-wide `private-company.{id}.<feature>[.{sub_id}]` convention:

| Channel | Events | Effect |
|---|---|---|
| `private-company.{id}.financial-statements.{snapshot_id}` | `financial_statement.generated`, `financial_statement.validated` | Subscribed only while the open snapshot's `status = 'generating'`; on `generated`, invalidates `detail`/`lines`/`insights` and the Reconciliation Band animates from skeleton to resolved |
| `private-company.{id}.treasury` | `treasury.liquidity_recomputed`, `treasury.forecast_refreshed`, `bank.liquidity_ratio.breached` | Invalidates `liquidityKeys.liquidity()`/`.forecast()`; a `breached` event additionally surfaces the persistent Liquidity banner described in `# Interactions & Flows` without waiting for the next `staleTime` window |
| `private-company.{id}.notifications.{user_id}` | `financial_statement.review_requested`, `.approval_step_completed`, `.approved`, `.rejected`, `.exported`, `.ai_forecast_ready` | Topbar bell + toast; a toast matching the currently-open snapshot id additionally triggers a targeted `invalidateQueries` |

## AI agents feeding this screen

Per `FINANCIAL_STATEMENTS.md → AI Responsibilities` (statement-agnostic: Reporting Agent's Financial
Analysis/Ratio Analysis/Variance Analysis/Executive Summary apply per statement, Cash Flow included) plus
`docs/accounting/BANKING.md → AI Responsibilities` and `docs/ai/agents/TREASURY_AGENT.md`:

- **Reporting Agent** — Financial Analysis, the Cash-Flow-specific ratio interpretations (`# AI Integration`),
  Variance Analysis against the comparison period, and the base Executive Summary paragraph.
- **Forecast Agent** — the rolling 13-week `forecast_cash_projection`, consumed here as the read-only
  overlay; never recomputed client-side, never merged into the audited grid.
- **Treasury Manager** — the live liquidity ratio, the cash-position summary, and — as a read-only teaser
  link only, never rendered inline — the existence of a pending `treasury_payment_run_proposal`, per its own
  strict mandate boundary (`docs/ai/agents/TREASURY_AGENT.md → Role & Mandate`).
- **CFO Agent** — synthesizes the above into the weekly treasury-briefing narrative, gated behind CFO/Finance
  Manager approval before it can be exported or shared, identically to Balance Sheet's CFO narrative.

None of the four writes to the database directly; each produces either a `financial_statement_snapshots`
enrichment (`ai_insights` JSONB) or its own `ai_decisions` row that this screen only reads and links to.

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md → Principle 10`: reversible, non-financial actions are optimistic; anything
touching the ledger's authoritative state, or anything that will leave the company (export, share), is
pessimistic.

```ts
// hooks/accounting/use-cash-flow.ts
export function useDismissInsight(snapshotId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ insightId, reason }: { insightId: number; reason: string }) =>
      api.patch(`/accounting/financial-statements/cash-flow/${snapshotId}/insights/${insightId}`, {
        status: "dismissed", resolution_note: reason,
      }),
    onMutate: async ({ insightId }) => {
      await qc.cancelQueries({ queryKey: cashFlowKeys.insights(snapshotId) });
      const previous = qc.getQueryData(cashFlowKeys.insights(snapshotId));
      qc.setQueryData(cashFlowKeys.insights(snapshotId), (old: Insight[]) =>
        old.map((i) => (i.id === insightId ? { ...i, status: "dismissed" } : i)));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(cashFlowKeys.insights(snapshotId), ctx?.previous),
    onSettled: () => qc.invalidateQueries({ queryKey: cashFlowKeys.insights(snapshotId) }),
  });
}

export function useApproveCashFlowStep(snapshotId: number) {
  // No onMutate — approving a statement step, or approving the CFO narrative for export, only shows
  // "approved" after the server's 2xx, per Principle 10's "pessimistic where it moves toward an
  // externally-visible artifact."
  return useMutation({
    mutationFn: (body: { action: "approved" | "rejected" | "requested_changes"; comment: string }) =>
      api.post(`/accounting/financial-statements/cash-flow/${snapshotId}/approve`, body, crypto.randomUUID()),
  });
}

export function useToggleForecastOverlay() {
  // Purely a display preference, not a mutation — no network call, no idempotency key, just a
  // router.replace of the `show_forecast` search param; included here only to make explicit that
  // this is the one "toggle" on this screen with zero server round trip.
}
```

## Form schemas

```ts
// lib/schemas/cash-flow.ts
export const generateCashFlowSchema = z
  .object({
    statement_type: z.literal("cash_flow_statement"),
    method: z.enum(["indirect", "direct"]).default("indirect"),
    fiscal_period_id: z.number().int().positive().nullable(),
    period_start: z.string().date().optional(),
    period_end: z.string().date().optional(),
    branch_id: z.number().int().positive().nullable().optional(),
    department_id: z.number().int().positive().nullable().optional(),
    project_id: z.number().int().positive().nullable().optional(),
    presentation_currency: z.string().length(3).default("KWD"),
    mode: z.enum(["live", "preview"]).default("live"),
  })
  .refine((v) => v.fiscal_period_id !== null || (Boolean(v.period_start) && Boolean(v.period_end)), {
    message: "validation.cashFlow.periodRequired",
    path: ["fiscal_period_id"],
  });

export const shareCashFlowSchema = z.object({
  permission: z.enum(["view_only", "view_and_export"]),
  recipient_email: z.string().email().optional(),
  recipient_label: z.string().min(1).max(150),
  expires_in_days: z.number().int().min(1).max(90).default(14),
  include_forecast_appendix: z.boolean().default(false),
  // include_forecast_appendix requires accounting.financial-statements.approve on the CFO narrative
  // (Edge Case 15) — enforced server-side; the client hides the checkbox rather than disabling it
  // when the viewer lacks that key, per NAVIGATION_SYSTEM.md's "omit vs. disable" rule for the
  // same reason TRIAL_BALANCE.md omits (never disables) its own Export menu item.
});

export const addReconciliationNoteSchema = z.object({
  note_number: z.string().min(1).max(10),
  title_en: z.string().min(1).max(200),
  title_ar: z.string().max(200).optional(),
  content_text: z.string().min(1, "validation.cashFlow.noteContentRequired"),
});
```

## SSR hydration

`page.tsx` (`# Route & Access`) prefetches only the primary statement query. The client `<CashFlowScreen>`
fires the Liquidity Strip and Forecast tab's queries independently, each behind its own `<Suspense>`
boundary — matching `BANKING.md`'s own stated reason for the identical choice on its Cash Position band:
"a slow Forecast/CFO-agent computation ... never blocks the primary content around it." Concretely, the
statement grid can paint, fully interactive, before the 13-week forecast has resolved:

```tsx
// components/accounting/cash-flow-screen.tsx (excerpt)
export function CashFlowScreen({ initialParams }: { initialParams: CashFlowParams }) {
  return (
    <>
      <CashFlowToolbar params={initialParams} />
      <Suspense fallback={<LiquidityStripSkeleton />}>
        <LiquidityStrip />
      </Suspense>
      <CashFlowReconciliationBandContainer params={initialParams} />
      <div className="grid gap-4 xl:grid-cols-[1fr_360px]">
        <CashFlowStatementGrid params={initialParams} />
        <Suspense fallback={<AiInsightsRailSkeleton />}>
          <CashFlowAIInsightsRail params={initialParams} />
        </Suspense>
      </div>
    </>
  );
}
```

# Interactions & Flows

**First-time / empty scope.** No `is_current` snapshot exists for the resolved scope. The canvas renders
`EmptyState` ("No Cash Flow Statement generated for July 2026 yet") with a primary "Generate" action (gated
on `.generate`). A period with zero posted transactions still generates a valid, fully-zeroed statement —
`FINANCIAL_STATEMENTS.md → Business Rules → BR-01` — rendered, not treated as an error state; see `# Edge
Cases` for how each of the three sections behaves when only one or two of them are genuinely empty.

**Switching Indirect ↔ Direct method.** The `ToggleGroup` calls `POST .../generate` with `mode=preview` and
the other method, without disturbing the `live`/`final` snapshot the toolbar's other controls still point
at. Per `FINANCIAL_STATEMENTS.md → Cash Flow Statement → Direct method alternative`, a direct-method preview
always carries a mandatory reconciliation Note back to the indirect-method total; the screen renders that
Note inline, directly beneath the Direct-method grid, rather than making the user open the Notes panel to
find it — the one place on this screen where a Note is shown unprompted rather than on demand. Previewing
the alternative method never changes which method is the company's `financial_statement_templates`-configured
default, and never creates a new "current" snapshot; it is discarded on navigating away unless explicitly
exported.

**Comparison period.** Selecting "vs. Jun 2026" calls `GET .../comparative?snapshot_ids=…` and adds a
Variance amount/percent column pair per line, highlighted (`Badge` `tone="warning"`) whenever the movement
exceeds the line's materiality threshold — identical mechanics to `BALANCE_SHEET.md`'s own Comparative
handling, reused verbatim rather than re-specified.

**Scope: Branch / Consolidated Group.** Selecting a specific branch filters the underlying computation to
that `branch_id`; because Cash and Cash Equivalents is rarely branch-attributed (head-office-pooled banking
is the common Gulf SME pattern), a branch-scoped Cash Flow Statement shows an explicit "Unallocated / Head
Office" line for any movement that cannot be attributed to the branch, exactly the caveat
`FINANCIAL_STATEMENTS.md → Report Generation Engine → Branch` documents for every branch-scoped statement.
Selecting Consolidated Group runs the full elimination pipeline; an unapproved elimination set renders the
grid watermarked "Preview — Eliminations Not Yet Approved," identical to Balance Sheet's own consolidated
preview treatment.

**Drill-down — three mechanisms, one Sheet.** Every line in the grid is clickable; which drill-down view
opens depends on the line's own `line_kind`/`cash_flow_category`, and the Sheet's header names which
mechanism produced its contents so a Finance Manager never has to infer it from the shape of the data alone:

1. **Non-cash adjustment lines** (Depreciation and Amortization, Expected Credit Loss Expense, Finance
   Costs/Income, unrealized FX, Gain/Loss on Disposal) open a Sheet listing the specific `journal_lines`
   posted to the underlying `accounts.is_non_cash_adjustment = true` account for the period — mechanically
   identical to a Balance Sheet line's drill-down.
2. **Working-capital change lines** ("(Increase)/Decrease in Trade and Other Receivables," "Increase/
   (Decrease) in Trade and Other Payables," …) open a Sheet that does **not** list journal lines at all. It
   shows the arithmetic itself — `Closing Balance Sheet balance (Jul 2026): KWD 154,900.000` minus `Opening
   Balance Sheet balance (Jun 2026): KWD 142,500.000` equals the `KWD 12,400.000` decrease shown on the
   grid — with each of the two balances a live link into that account's own Balance Sheet snapshot line,
   because a working-capital movement genuinely has no single journal entry to point at; it is a computed
   delta between two independently-generated statements, and the drill-down says so rather than performing
   an artificial per-transaction breakdown the backend itself does not compute this way.
3. **Investing/Financing lines** ("Purchase of Property, Plant and Equipment," "Proceeds from Borrowings," …)
   open a Sheet listing the cash-touching `journal_lines` whose contra account carries the matching
   `accounts.cash_flow_category` — e.g. a fixed-asset purchase entry (Dr. Property Plant & Equipment / Cr.
   Bank) surfaces here because the PP&E account is tagged `cash_flow_category = 'investing'`, per
   `FINANCIAL_STATEMENTS.md`'s own computation rule.

"View in General Ledger" and "Open journal entry" (mechanisms 1 and 3 only — mechanism 2 instead offers
"Open Balance Sheet" for each of its two linked balances) close the Sheet on navigate rather than stacking a
second overlay on top of a route change, identical to `TRIAL_BALANCE.md`'s own rule.

**Forecast tab.** Hovering a weekly bucket in `CashFlowForecastPanel`'s ribbon shows a `Tooltip` with that
week's projected net movement, its confidence band (narrower near-term, wider at the 13-week horizon, per
`docs/ai/agents/TREASURY_AGENT.md → Autonomy Level` and `docs/accounting/BANKING.md → Cash Management →
Forecast`), and the two or three largest contributing items (an invoice due, a payroll run, a bill).
Clicking "Review in Banking" on a week carrying a flagged `treasury_payment_run_proposal` teaser navigates to
`/banking/approvals/{proposalId}` — this screen never renders the proposal's own approve/reject controls
inline, consistent with the Treasury Manager's own mandate boundary (`TREASURY_AGENT.md → Role & Mandate`)
that payment-run review lives in Banking's approval surface, not duplicated here.

**Liquidity breach.** If `GET /banking/liquidity` (or a `bank.liquidity_ratio.breached` Reverb event) reports
`breached: true`, a persistent, non-dismissable banner renders above the Liquidity Strip ("Liquidity ratio
0.94 is below the company's 1.20 policy floor — see Treasury Dashboard"), visible to every Finance Manager/
CFO/CEO viewing this screen regardless of which toolbar filters they have set, because a liquidity breach is
a company-wide fact, not a property of the specific period being reported on.

**Review → Approve → Export → Share → Schedule.** Mechanically identical to `TRIAL_BALANCE.md → Interactions
& Flows` and `BALANCE_SHEET.md`'s equivalent flows: "Start Review" assigns the acting user as reviewer and
expands any open findings; each `ApprovalCard` step is interactive only for the current step's assigned
role; Export's `DropdownMenu` watermarks a `draft`/`under_review` snapshot's PDF as "DRAFT — NOT APPROVED"
in the menu item itself, before the click; Share and Schedule additionally gate whether the AI Treasury
Brief (the CFO narrative + the Forecast ribbon, bundled as a supplementary appendix) is included, per
`shareCashFlowSchema.include_forecast_appendix` above — never bundled by default, since the audited statement
and the AI forecast are, per platform rule, never merged into one artifact without an explicit, separately
logged approval.

**History.** Identical mechanics to `TRIAL_BALANCE.md → Interactions & Flows → History`: opening a
non-current version renders the whole canvas in a persistent "Viewing version 1 of 2 — superseded" banner
with every mutating action disabled, including the method toggle and the "Show forecast" switch — a
historical Cash Flow Statement is viewed exactly as it was generated, forecast overlay included only if one
was captured at that time, never refreshed with today's projection.

# AI Integration

Four AI surfaces compose on this screen, each visually distinct per the platform's "colored border plus AI
badge" rule (`FRONTEND_ARCHITECTURE.md → AI Integration Layer`, `DESIGN_LANGUAGE.md`): the Ratio/Variance/
Narrative outputs the Reporting Agent produces for every statement, and the Forecast/Liquidity outputs
specific to this one screen.

## Cash Flow ratios

The Reporting Agent's Ratio Analysis responsibility (`FINANCIAL_STATEMENTS.md → AI Responsibilities`) is
statement-agnostic; for Cash Flow it computes and interprets a Cash-Flow-specific set the Balance
Sheet/Income Statement ratio catalog does not otherwise cover, rendered in the AI Insights rail's Liquidity
tab:

| Ratio | Formula | Category |
|---|---|---|
| Operating Cash Flow Ratio | Net Cash from Operating Activities ÷ Current Liabilities | Liquidity |
| Free Cash Flow | Net Cash from Operating Activities − Purchase of Property, Plant and Equipment | Liquidity |
| Cash Flow Margin | Net Cash from Operating Activities ÷ Net Revenue | Efficiency |
| Cash Flow to Debt | Net Cash from Operating Activities ÷ Total Liabilities | Solvency |

Each renders through `AmountCell`/plain text plus a `ConfidenceBadge`-scored one-line interpretation exactly
as Balance Sheet's ratio tile does — the ratios themselves are deterministic math (no confidence score); only
their plain-language read carries one, per the same "ratios are auto-computed, always shown; interpretation
is suggest-only" split `FINANCIAL_STATEMENTS.md → AI Responsibilities` draws for every statement.

## Forecast overlay — worked example

`GET /banking/cash-flow-forecast` returns the Forecast Agent's own confidence-scored `ai_decisions` row
(`decision_type: 'forecast_cash_projection'`), banded by week, exactly the shape
`docs/accounting/BANKING.md → Cash Management → Forecast` specifies ("each weekly bucket carries a
confidence band, not a single number"):

```json
{
  "success": true,
  "data": {
    "id": 881410,
    "agent_code": "FORECAST_AGENT",
    "decision_type": "forecast_cash_projection",
    "confidence_score": 88.0,
    "reasoning": "Weeks 1-4 are near-deterministic: they resolve from already-scheduled bills, the completed payroll calendar, and confirmed invoice due dates. Weeks 5-13 blend the same confirmed items with a trailing-13-week average of uncategorized recurring flow; confidence narrows accordingly.",
    "payload": {
      "base_currency": "KWD",
      "weekly_buckets": [
        { "week_start": "2026-07-20", "projected_inflow": "58200.0000", "projected_outflow": "50060.0000", "net": "8140.0000", "confidence_low": "6900.0000", "confidence_high": "9380.0000", "confidence_score": 92 },
        { "week_start": "2026-07-27", "projected_inflow": "41500.0000", "projected_outflow": "46200.0000", "net": "-4700.0000", "confidence_low": "-6800.0000", "confidence_high": "-2600.0000", "confidence_score": 85 },
        { "week_start": "2026-10-12", "projected_inflow": "39800.0000", "projected_outflow": "33600.0000", "net": "6200.0000", "confidence_low": "1100.0000", "confidence_high": "11300.0000", "confidence_score": 61 }
      ]
    },
    "sources": [
      { "type": "bank_transactions", "id": null, "label": "Trailing 13-week actuals, accounts 12/45/78" },
      { "type": "bills", "id": 61180, "label": "Gulf Paper Trading Co. — due 2026-07-20" },
      { "type": "payroll_runs", "id": 2207, "label": "July payroll — completed" }
    ],
    "requires_approval": false
  },
  "message": "Forecast retrieved",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9c2a7e14-6b3f-4a02-8d51-e0f7a9c4b812",
  "timestamp": "2026-07-17T05:03:12Z"
}
```

`CashFlowForecastPanel` renders `weekly_buckets` as the confidence ribbon in `# Components Used`; the widening
band between week `2026-07-20` (confidence 92, band ±KWD ~1,240) and week `2026-10-12` (confidence 61, band
±KWD ~5,100) is the visual point of the whole overlay — a viewer should be able to tell, without reading a
single number, that the near-term projection is far more trustworthy than the 13th week's. This is fully
autonomous, read-only generation (`requires_approval: false`) per `docs/accounting/BANKING.md → AI
Responsibilities → Forecast Agent`; nothing about displaying it requires a human gate, because it asserts
nothing about what will be done, only what is statistically projected.

## Liquidity and Treasury

The Liquidity tab renders the Treasury Manager's own `treasury_liquidity_alert` decision inline — the exact
worked shape `docs/ai/agents/TREASURY_AGENT.md → Outputs` specifies, reused rather than re-derived:

```json
{
  "id": 992310,
  "agent_code": "TREASURY_AGENT",
  "decision_type": "treasury_liquidity_alert",
  "confidence_score": 97.0,
  "payload": {
    "operating_cash_base": "61000.0000",
    "near_cash_base": "12000.0000",
    "next_30_day_committed_outflows_base": "50000.0000",
    "liquidity_ratio": 1.46,
    "policy_floor": 1.20,
    "breached": false
  },
  "requires_approval": false
}
```

A `treasury_payment_run_proposal` referenced by `contributing_forecast_decision_id` renders only as a named
teaser card ("A draft 3-line payment run is pending Finance Manager review — Review in Banking") — this
screen never renders that proposal's line items, discount rationale, or approve/reject controls, because
doing so would duplicate a surface `TREASURY_AGENT.md → Role & Mandate` explicitly assigns to Banking's own
Approval Center, and duplicating an approval surface across two screens is exactly the kind of drift the
platform's single-source-of-truth rule for approvals forbids.

## CFO treasury briefing narrative

The CFO Agent's weekly briefing — "USD exposure grew 12% this week; facility utilization on the Burgan
overdraft crossed 70%" style output, per `docs/accounting/BANKING.md → AI Responsibilities → CFO (agent)` —
renders in the Narrative tab exactly as Balance Sheet's CFO narrative does: watermarked "Unreviewed AI Draft"
until a CFO or Finance Manager approves it, with a visible "AI-generated — reviewed by [approver] on [date]"
attribution once signed off, and blocked from `include_forecast_appendix` export/share until that approval
exists.

## Escalation and unavailability

A liquidity breach or a Fraud Detection `hold_recommended` flag on a payee inside a referenced payment-run
proposal renders with the same persistent, non-collapsible banner treatment `TRIAL_BALANCE.md → AI
Integration → Escalation` documents for a critical Trial Balance finding. If the Forecast Agent or Treasury
Manager call fails, times out, or is disabled for the company, the Forecast/Liquidity tabs show a calm
`StatusPill` ("AI forecast pending/unavailable") rather than an error state, and every human-driven action —
Review, Approve, Export, Share, Schedule — remains fully available: the audited statement never depends on
the AI layer to be usable, matching the platform-wide rule that AI is additive, never a required gate.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | `Skeleton` shaped like Toolbar + Liquidity Strip + Reconciliation Band + Grid, shimmer sweep per `DESIGN_LANGUAGE.md → Motion → Named patterns`, never a spinner |
| Generating | `status: "generating"` | Reconciliation Band shows an indeterminate "Generating your Cash Flow Statement…" bar; grid shows skeleton; Reverb-subscribed, 2s poll fallback |
| Reconciled | `is_reconciled: true` (VR-03 passes) | Band: plain `ink-950` on `ink-0`, small neutral checkmark, end-of-period cash figure shown inline — quiet certainty, never a green banner |
| Reconciliation failure (VR-03) | `is_reconciled: false` | Band: `danger` tone, no diagnostic drill-in, "Contact support" only — deliberately different from Balance Sheet's user-actionable imbalance state, per `# Purpose` and `# Components Used` |
| Has warnings, otherwise healthy | `has_warnings: true` | Band stays neutral; a secondary `warning` `Badge` sits beside the snapshot `StatusPill` |
| Zero transactions this period | No posted journal activity in scope (BR-01) | Grid renders fully-zeroed, all three sections present, labeled "No posted transactions for this period" in `meta` — never an error, never hidden |
| One section genuinely empty | e.g. no Investing activity this period | That section renders its header and a single "No investing activity this period" row rather than collapsing/hiding the section — a company can legitimately have zero investing activity in a given month, and hiding the section would read as a data gap rather than a true zero |
| Forecast: insufficient history | Fewer than 3 comparable historical periods (`FINANCIAL_STATEMENTS.md → AI Responsibilities → Forecast Agent` rule, reused here) | Forecast tab shows `insufficient_history`, not a zero-confidence chart — an empty-state message, not a misleadingly flat ribbon |
| Liquidity Strip locked | Viewer lacks `bank.read`/`treasury.read` | Tiles render with an em dash and a `Lock` icon plus a permission tooltip, per `BANKING.md`'s own precedent — never omitted |
| Empty (no snapshot for scope) | No `is_current` row for the resolved logical key | `EmptyState`, CTA gated on `.generate`; a lesser copy variant when the viewer lacks `.generate` |
| Stale/superseded | Viewing a non-current version via History | Persistent read-only banner naming the reason; every mutation, including the method toggle and forecast switch, disabled |
| Error | `403`/`404`/`5xx`/network failure | `ErrorState` with retry; a `403` mid-session collapses the affected control to its disabled-with-tooltip form rather than crashing the page |

# Responsive Behavior

This screen follows `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` for the statement grid exactly
as `TRIAL_BALANCE.md` and `BALANCE_SHEET.md` do, plus the chart-specific rules `RESPONSIVE_DESIGN.md →
Images/Charts responsiveness → Charts` sets for the Forecast ribbon.

**Mobile (`base`–`sm`).** The Page Header collapses to a back-chevron + title; toolbar controls stack into a
horizontally-scrollable chip row; the Liquidity Strip's four `KpiTile`s become a horizontally-scrollable chip
row of their own (identical adaptation to `BANKING.md`'s own Cash Position band on mobile) rather than a
2×2 grid, so the Liquidity Ratio tile — the one most likely to carry a `Lock` icon or a breach warning — is
never pushed below the fold. The grid becomes a stack of section cards (Operating / Investing / Financing),
each showing only its subtotal and a "View lines" expansion, per `RESPONSIVE_DESIGN.md → Pattern 1 — Data
tables become cards`. The Reconciliation Band and a collapsed AI status chip stay pinned above the card
list. The AI Insights rail becomes its own tab (`Statement | Forecast | Liquidity`) rather than a `Sheet`
overlay, because the Forecast ribbon needs real width to be legible — a narrow overlay sheet would force the
chart below a usable size.

**Tablet and up (`md`+).** The grid is a real `<table>` with the line-description column pinned via `sticky
start-0` and the money columns scrolling horizontally inside their own `overflow-x-auto` region; the Net
Increase/Decrease and Beginning/End-of-Period rows form a `sticky bottom-0` footer group, never scrolled out
of view while reviewing the sections above them.

**Charts.** `CashFlowWaterfall` and `CashFlowForecastPanel`'s ribbon both mount inside `ChartFrame`
(`RESPONSIVE_DESIGN.md → Charts`) — an explicit-height `aspect-[16/9] md:aspect-[21/9]` wrapper around
`ResponsiveContainer` — because a bare percentage-height `ResponsiveContainer` inside this screen's grid
layout would collapse to zero height, the same well-known Recharts failure mode that document already
names. The Forecast ribbon's confidence-band fill and line remain legible down to the mobile viewport width
by dropping the weekly x-axis tick labels below `md` (retained only in the per-week `Tooltip` on tap) rather
than shrinking the type past a readable size.

**Touch and virtualization.** Row-action and drill-down affordances meet the platform's 44×44px touch
minimum regardless of density; a consolidated, multi-branch Cash Flow Statement with a deep working-capital
breakdown virtualizes past ~150 lines via `@tanstack/react-virtual`, consistent with the row-count threshold
`TRIAL_BALANCE.md`/`DESIGN_LANGUAGE.md → Virtualization & interaction` set for dense finance tables generally.

# RTL & Localization

Every string ships as an EN/AR pair through `next-intl`; the screen inherits `THEMING.md → RTL as a theming
dimension` and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a per-component RTL branch, plus this
screen's own specific applications:

- **Section and line labels are bilingual**, rendering `label_ar` when the active locale is `ar` and falling
  back to `label_en` only if genuinely absent, per the identical rule `TRIAL_BALANCE.md → RTL & Localization`
  states for account names. Arabic accounting terminology used precisely: قائمة التدفقات النقدية (Statement
  of Cash Flows), الأنشطة التشغيلية (Operating Activities), الأنشطة الاستثمارية (Investing Activities),
  الأنشطة التمويلية (Financing Activities), السيولة (Liquidity), التوقع (Forecast).
- **Parentheses-for-negative is the fixed convention on this screen**, per `DESIGN_LANGUAGE.md → Data Density
  → Numeral formatting rules`, which names Cash Flow explicitly alongside Balance Sheet and P&L as a
  "rendered financial statement": `(12,400.000)` in both LTR and RTL, never a leading minus sign, because
  this is the exported/printed-statement convention Kuwaiti and international auditors read fluently
  regardless of interface language.
- **Money columns are physically fixed** (`text-right`, never `text-end`) and rendered `dir="ltr"` inside the
  row, per `LAYOUT_SYSTEM.md`'s numeric-alignment exception, so a bilingual Finance team scanning magnitude
  by decimal alignment sees every figure land on the same edge regardless of session language.
- **Forecast week dates, confidence percentages, and account codes stay LTR-embedded** inside Arabic sentences
  (the Forecast tooltip's reasoning text, the CFO narrative) via the shared `Bidi`/`LtrInline` wrapper
  (`unicode-bidi: isolate` + `dir="ltr"`), so a week date or a confidence score never reorders inside an
  otherwise-Arabic paragraph.
- **The Forecast ribbon's time axis never mirrors.** Consistent with the platform-wide rule
  `TRIAL_BALANCE.md → RTL & Localization → "Charts never mirror"` states for `TrendSparkline`, the 13-week
  ribbon keeps a fixed left-to-right, earliest-to-latest time axis under `dir="rtl"` sessions, rendered
  inside its own `dir="ltr"` wrapper — a forecast that reads right-to-left in time would be actively
  confusing regardless of interface language.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching Gulf
  financial-document convention platform-wide.
- **Directional chrome mirrors; content chrome does not.** The Drill-down Sheet slides from the logical end
  edge (left in RTL); the `Scale`/`Wallet`/`Sparkles` icons and every status/severity icon do not mirror, per
  `ICONOGRAPHY.md → RTL-Aware Icons`.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`/`THEMING.md`. Nothing on this
screen references a raw hex value or a Tailwind palette utility.

- **Surfaces and elevation.** The canvas sits on `--color-bg-canvas`; the Liquidity Strip, Reconciliation
  Band, and grid sit on `--color-bg-surface`; the Drill-down/History `Sheet`s and the Forecast tab's expanded
  detail popover sit on `--color-bg-surface-raised`. Elevation reads as the surface getting lighter toward the
  viewer in dark mode, never via shadow alone — the sticky footer row and the Liquidity Strip both carry their
  paired `--color-border-subtle` hairline in dark mode for the same reason `TRIAL_BALANCE.md` requires it: a
  borderless dark card disappears against the near-black canvas.
- **Reconciliation Band tones.** Reconciled → plain `ink-950`/`ink-0`, never a green fill, per Design
  Principle 5. Reconciliation failure → `--color-danger`, the one deliberately prominent, saturated treatment
  on this screen — but, per `# Components Used`'s distinction from `BalanceIdentityBanner`, this danger
  treatment pairs with calm, non-actionable copy rather than an invitation to investigate, since the backend
  frames a VR-03 failure as an engineering defect, not a user-fixable data state.
- **Grid figures never take a status color.** Every `AmountCell` in the statement grid renders in
  `--color-fg-primary` regardless of theme or sign; the parentheses convention (`# RTL & Localization`), not
  color, is this screen's negative-number signal — identical to the platform-wide debit/credit rule
  `TRIAL_BALANCE.md`/`ACCESSIBILITY.md` state, applied here to inflow/outflow instead of debit/credit.
- **`CashFlowWaterfall`'s bars are the deliberate exception.** Per `DARK_MODE.md → Financial charts map to
  semantic tokens, not the categorical palette`, the waterfall's bars *do* draw `--status-success`/
  `--status-error` by sign, because a waterfall chart's entire visual purpose is showing the shape of positive
  versus negative movement at a glance — this is the one place on the screen where a financial figure
  legitimately carries a status color, and it is a chart, not the audited numeric grid itself. The two
  conventions (neutral grid text, colored waterfall bars) coexist by design, not by oversight.
- **The forecast ribbon is accent, never status or categorical.** Per `# Components Used`, the ribbon's fill
  and line use `--chart-accent`/`--accent-subtle-bg` exclusively — AI provenance color, reserved per
  `DARK_MODE.md`, and re-picked (not filter-derived) per-theme for the same pairwise-perceptual-distance and
  colorblind-simulation discipline that document applies to every chart token.
- **Chart re-render on theme change.** `CashFlowWaterfall` and `CashFlowForecastPanel` both consume
  `useChartTokens()` (`DARK_MODE.md → The Tailwind-doesn't-reach-SVG-fill problem`), whose effect dependency
  array is `[resolvedTheme]` — a chart mounted before a theme toggle re-reads every custom property the
  instant the toggle fires, rather than silently continuing to render the previous theme's colors.
- **Sparklines follow the two-color rule.** The Liquidity Strip's `TrendSparkline` instances use only
  `--text-tertiary` for the line and a single semantic accent dot for the current-value marker, its color
  (`--status-success`/`--status-error`) driven by the API's own `trend_direction` field, never inferred
  client-side from whether the line moved up or down — per `DARK_MODE.md → Sparklines and KPI micro-charts`,
  the sign of "good" for a metric like the liquidity ratio is server-owned business logic, not a client
  heuristic.
- **Tooltips and legends** on both charts override `contentStyle` to consume `--surface-overlay`/
  `--border-default`/`--text-primary`, avoiding the "default white tooltip" bug `DARK_MODE.md` names for
  third-party charting libraries.
- **Contrast** is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text, borders, focus rings, the
  confidence-band fill against its surrounding surface) in both themes, per `DARK_MODE.md → Color & Contrast
  In Dark`.
- **Print/export independence.** Exported PDFs always render in QAYD's fixed light/print palette regardless
  of the viewer's active theme (`DARK_MODE.md → Exported PDFs always render light`) — a Cash Flow Statement
  is routinely forwarded to a bank or an external auditor who never opens the dark-mode app.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented without deviation, plus this screen's own
chart- and reconciliation-specific applications.

- **Real table semantics.** The statement grid is a genuine `<table>` with a `<caption>` ("Cash Flow
  Statement — July 2026, Indirect Method"), `scope="col"` headers, and `scope="row"` on the line-description
  cell — never a styled `<div>` grid, per `ACCESSIBILITY.md → Screen Readers → Tables use real table
  semantics`.
- **Parenthesized amounts get an explicit spoken equivalent.** Because this screen's negative-number
  convention is typographic (parentheses) rather than a leading sign glyph like Trial Balance's debit/credit
  columns, a screen reader encountering `(12,400.000)` unaided would read the punctuation literally rather
  than "decrease." `AmountCell` on this screen therefore always pairs a parenthesized figure with a
  `VisuallyHidden` prefix — `"decrease of"` / `"increase of"` — resolved from the same sign the visual
  parentheses already encode, so the accessible name and the visible convention never diverge:

  ```tsx
  <span className="sr-only">{isNegative ? 'decrease of' : 'increase of'}</span>
  <AmountCell amount={line.currentValue} currencyCode={currencyCode} />
  ```

- **The Reconciliation Band is a live region proportional to its severity.** `role="status" aria-live="polite"`
  on the transition from Generating to Reconciled; the reconciliation-failure transition escalates to
  `role="alert"` (assertive) — but because that failure state offers no interactive diagnostic (`#
  Components Used`), its live-region announcement is a single sentence plus the support-contact affordance,
  never a long list of figures a screen-reader user would have to sit through for a state that is, per the
  backend's own framing, not theirs to fix.
- **Forecast and Liquidity updates arriving via Reverb never yank focus.** A refreshed forecast or a
  liquidity-breach event updates its tab's content with a polite live-region announcement ("Liquidity ratio
  updated: 1.46"), never reordering or auto-expanding a panel the user is currently reading, per the
  platform-wide realtime-accessibility rule `TRIAL_BALANCE.md → Accessibility` states.
- **Charts carry a non-visual equivalent.** `CashFlowWaterfall` and the forecast ribbon are `role="img"` with
  a descriptive `aria-label` summarizing the shape ("Cash flow by activity: Operating +41,220, Investing
  -9,800, Financing +2,800"), and each additionally exposes a "View as table" `Button` that reveals the exact
  same weekly-bucket or per-section data as a genuine, screen-reader-navigable `<table>` — a chart is never
  the *only* representation of a figure this screen considers load-bearing, matching the platform's blanket
  rule that a visual-only element is never the sole encoding of financial information.
- **Every disabled control explains itself,** distinguishing a permission-denial tooltip ("Requires
  `accounting.financial-statements.approve`") from a business-rule-state tooltip ("This snapshot is being
  regenerated — try again in a moment") and from the Liquidity Strip's own permission-lock tooltip ("Requires
  `treasury.read`") — three distinct reasons a control might be inert, never collapsed into a generic "You
  can't do this," per `ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`.
- **Keyboard.** Tab order follows visual/DOM order: header controls → Liquidity Strip → Reconciliation Band
  → grid → AI Insights rail. The grid is a read-only display table, not an ARIA `grid`, consistent with
  `ACCESSIBILITY.md`'s distinction reserving that pattern for genuinely editable surfaces. `Cmd/Ctrl+Enter`
  confirms the Generate/Share/Schedule dialogs and the Approve/Reject confirmation.
- **Export is omitted, not disabled**, for the identical reason `TRIAL_BALANCE.md → Accessibility` gives for
  its own Export menu: a disabled item with no content behind it leaves a screen-reader user unable to tell
  whether the capability exists at all versus is merely unavailable right now, so the item is filtered from
  the menu's own data source rather than rendered disabled.
- **Focus management on overlays.** The Drill-down Sheet's `onOpenAutoFocus` moves focus to its heading
  (which names the specific drill-down mechanism per `# Interactions & Flows`), not its close button;
  closing it returns focus to the triggering cell.

# Performance

- **Async generation, not a blocking request.** Any Generate/Refresh/method-preview whose estimated scan
  exceeds the backend's configured row threshold returns `202 Accepted` immediately; the frontend subscribes
  to the snapshot's Reverb channel rather than holding a spinner open, identical to `TRIAL_BALANCE.md`'s own
  rule.
- **Independent Suspense boundaries prevent AI latency from blocking the audited grid.** The Liquidity Strip
  and the AI Insights rail's Forecast/Liquidity tabs each resolve behind their own `<Suspense>` boundary
  (`# Data & State → SSR hydration`), so a slow Forecast Agent computation never delays Time to Interactive
  for the statement grid itself — the one region on this screen that must never wait on the AI layer.
- **Chart bundle is code-split and screen-scoped.** Unlike Balance Sheet and Trial Balance, this screen is
  the first financial-statement screen to ship a real charting dependency; `CashFlowWaterfall` and
  `CashFlowForecastPanel` are `next/dynamic`-imported with `ssr: false`, so a Payroll Officer or a user who
  never opens Financial Statements never downloads Recharts at all, per `FRONTEND_ARCHITECTURE.md → Code
  splitting`.
- **Virtualization past ~150 lines.** A consolidated, multi-branch statement with a deep working-capital
  breakdown virtualizes via `@tanstack/react-virtual`, using the active density mode's fixed row height as
  the size estimate.
- **Status-aware caching.** `final` and `archived` snapshots are cached aggressively (`# Data & State →
  Cache tuning`); this is what lets a Finance Manager reopen last month's approved Cash Flow Statement
  instantly from History without a network round trip.
- **Deferred, code-split overlays.** The Generate/Share/Schedule dialogs and the History Sheet's content are
  dynamically imported rather than bundled into the initial route chunk.
- **Debounced, cancellable comparison and method-preview fetches.** Switching the comparison-period selector
  or the Indirect/Direct toggle debounces 200ms and cancels any in-flight request via
  `queryClient.cancelQueries` before issuing the next one.
- **SSR-seeded first paint.** The header, first page of lines, and the resolved current snapshot's headline
  totals are fetched once, server-side, and hydrated into the client cache, per `# Data & State`.
- **Read-replica reporting.** Comparative and consolidated-scope queries are served from a Postgres read
  replica where available (`FINANCIAL_STATEMENTS.md → Report Generation Engine`), transparently to the
  frontend.

# Edge Cases

1. **VR-03 reconciliation mismatch.** Rendered as the deliberately non-diagnostic, support-directed failure
   state (`# States`, `# Components Used`) — never a "find the unbalanced entry" flow, because the backend
   treats this as a mapping-configuration defect, not a user-actionable data problem.
2. **Forecast insufficient history.** Fewer than 3 comparable historical periods returns
   `insufficient_history`; the Forecast tab shows an explanatory empty state, never a flat, falsely-confident
   ribbon at zero.
3. **Direct-method preview on a template that has never generated a Direct statement before.** Allowed —
   `mode=preview` always computes fresh — but the resulting preview always carries the mandatory
   reconciliation Note back to the Indirect total (`FINANCIAL_STATEMENTS.md → Direct method alternative`),
   surfaced inline rather than requiring the user to open the Notes panel.
4. **Liquidity Strip permission gaps in both directions.** A viewer with `accounting.financial-statements.read`
   but no `bank.read`/`treasury.read` sees locked, not hidden, tiles (`# States`); a pure Treasury-role viewer
   with full Banking access but no `accounting.financial-statements.read` never reaches this route at all
   (`# Route & Access → Cross-module permission surface`) — neither asymmetry is a bug to reconcile away.
5. **A period with genuinely zero Investing or Financing activity.** The section still renders with its
   header and an explicit "No investing activity this period" row rather than collapsing — a true zero must
   be visually indistinguishable from "the AI/mapping silently dropped this category" only by reading the
   words, never by the section disappearing.
6. **Single-currency companies never show a permanent zero FX-effect row.** "Effect of Exchange Rate Changes
   on Cash" is omitted entirely (not shown as a static `0.000`) for a company whose `base_currency` is the
   only currency it has ever transacted in, since the line has no meaning to explain away for that company.
7. **Consolidated scope with an unapproved intercompany elimination set.** Renders watermarked "Preview —
   Eliminations Not Yet Approved," identical to Balance Sheet's own consolidated-preview treatment; an
   unrealized-intercompany-profit elimination affecting closing inventory also shifts the Investing section's
   figures, and the drill-down for that specific movement names the elimination entry explicitly rather than
   presenting it as an ordinary journal line.
8. **Branch-scoped Cash Flow Statement.** Cash and Cash Equivalents is rarely branch-attributed; an
   "Unallocated / Head Office" line absorbs any movement the branch filter cannot attribute, per
   `FINANCIAL_STATEMENTS.md → Report Generation Engine → Branch`.
9. **Forecast Agent or Treasury Manager unavailable, disabled, or timed out.** The audited statement, its
   Review/Approve/Export/Share/Schedule flows, and the Liquidity Strip's own direct Banking-sourced tiles
   remain fully usable; only the Forecast tab and the Treasury-sourced liquidity-alert card degrade to a
   calm "pending/unavailable" pill.
10. **Company base-currency change spanning a comparative period.** The affected period pair renders with an
    explicit footnote disclosing the historical conversion rate used, never a silent re-expression of history
    in the new currency.
11. **Concurrent Regenerate or method-toggle clicks.** Both disable immediately on click and reuse a single
    `Idempotency-Key` per logical submission attempt; a genuine race's `409 Conflict` renders a friendly
    "Already generating — hang on" toast.
12. **Viewing a superseded snapshot via History.** The forecast overlay, if shown at all, is exactly the one
    captured at that snapshot's generation time — it is never silently refreshed with today's live
    projection, even though the toggle to show/hide it remains visually present (disabled) on a historical
    view, so a reviewer is never misled into thinking an old statement carries a current forecast.
13. **Session permission revoked mid-view.** If a role change revokes `.approve` while an `ApprovalCard` is
    open, the next mutation attempt's `403` collapses that card to read-only with an explanatory toast,
    rather than a stuck loading state.
14. **Company switch mid-Generate.** Switching the active company discards all cached Cash Flow state; if a
    Generate dialog is open with unsaved scope selections, the switcher's confirmation dialog names that
    in-progress action explicitly before proceeding.
15. **Approving the statement does not approve the AI Treasury Brief.** "Mark Final" on the Cash Flow
    Statement itself and approving the CFO narrative for inclusion in an export/share are two independent
    approval actions against two different resources (`financial_statement_snapshots` vs. the CFO Agent's
    narrative decision) — the UI never implies that finalizing the statement also clears the AI content for
    external distribution, and `shareCashFlowSchema.include_forecast_appendix` cannot be checked until the
    second, separate approval exists.

# End of Document

