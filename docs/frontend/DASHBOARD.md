# Dashboard — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: DASHBOARD
---

# Purpose

The Dashboard is QAYD's company home screen — the first thing every authenticated user sees after login or a company switch, and the one screen every role in the platform lands on regardless of what they hold permission for. It answers a narrower, more conventional question than its sibling surface at `/ai/command-center`: not "what has the autonomous finance workforce found and proposed," but the question a Finance Manager, an Owner, or an Accountant has asked of every accounting product for forty years — **where do the numbers stand right now, how did they get here, and where do I go to do something about it.** KPI tiles for cash, revenue, expenses, and AR/AP; a trend chart for revenue against expenses; a compact feed of what changed recently in the ledger; and a row of quick actions into the modules that actually create financial records. This document is the binding frontend specification for that screen.

Dashboard and the AI Command Center are deliberately two different documents, two different routes, and two different registers, even though both are instances of the same `DashboardTemplate` region shape defined in `LAYOUT_SYSTEM.md` (KPI Strip, Chart Region, a narrower feed rail, a lower bento row) and even though both, ultimately, render data that traces back to the same `journal_lines`. Dashboard is the **conventional overview** — deterministic, SQL-derived figures a controller could reproduce on a whiteboard, presented calmly and quickly, with AI narration appearing only as a condensed, clearly-labeled annotation on top of that overview, never as its primary content. The AI Command Center, by contrast, is the **autonomous workforce's own home screen** — twenty-plus widgets, a Morning Briefing, an Approval Center, an Automation Center, a full Insights and Recommendations feed, Ask AI — documented in full in `../ai/AI_COMMAND_CENTER.md` and, at the frontend layer, in `AI_COMMAND_CENTER.md` alongside this document. A user who wants "what happened, in numbers" opens Dashboard. A user who wants "what did the AI workforce do about it, and what does it want me to decide" opens the AI Command Center. Dashboard links out to that deeper surface at exactly the two or three points where a condensed AI observation belongs on a numbers-first screen; it never re-implements it.

**A note on this route, because the platform's own documents have not been fully consistent about it as the product evolved.** `FRONTEND_ARCHITECTURE.md`'s App Router tree still comments its `dashboard/page.tsx` leaf as "AI Command Center home"; `NAVIGATION_SYSTEM.md`'s primary-nav module map describes the Dashboard nav item's content as "a grid of independently-permissioned AI Command Center widgets"; and `RESPONSIVE_DESIGN.md`'s Dashboard Reflow section opens by naming the dashboard route and the AI Command Center as the same twenty-widget screen. All three predate the split this document formalizes. `LAYOUT_SYSTEM.md`'s own Dashboard Template already anticipated that split — it lists two routes, `app/(app)/dashboard/page.tsx` and `app/(app)/ai/command-center/page.tsx`, as two separate instances of one region shape, captioned "Home Dashboard, AI Command Center" — and `AI_COMMAND_CENTER.md` went further still, renaming the Command Center's own canonical segment to `/command-center` and stating that "`app/(app)/dashboard/page.tsx` is retained only as a permanent redirect to `/command-center`." That specific redirect clause is superseded, on this one point only, by this document: `/dashboard` is Dashboard's own, non-redirecting route, exactly as `LAYOUT_SYSTEM.md`'s original two-route reading intended and exactly as this document's own Route & Access section fixes below. Nothing else about the AI Command Center changes — every link this document gives to that deeper surface uses its own canonical `/command-center` segment, per `AI_COMMAND_CENTER.md`'s explicit instruction that "every other document should read `/command-center`." `FRONTEND_ARCHITECTURE.md` and `NAVIGATION_SYSTEM.md`'s own route-tree comments and nav descriptions should be read as stale on this specific point and reconciled to match; this document is the current, authoritative statement of what renders at `/dashboard`.

Three constraints inherited from `FRONTEND_ARCHITECTURE.md` bind every pixel of this document and are not re-argued here:

1. **The frontend computes nothing.** Every figure on this screen — the cash balance, the revenue delta, the AR aging bucket — is a value Laravel already validated and persisted. The Dashboard's own logic is limited to fetching, caching, formatting, filtering by period/branch, and routing a click to the right drill-down destination.
2. **AI is visible, labeled, and never silent — but it is also never the loudest thing on this particular screen.** Where an AI-sourced annotation appears (a health-score chip, a "why did revenue change" narrative, a condensed Top Recommended Actions card), it carries its confidence score and reasoning exactly as `DESIGN_LANGUAGE.md` and `COMPONENT_LIBRARY.md` require, and it is visually distinct from the deterministic KPI tiles beside it.
3. **RBAC is enforced by the API; the UI only reflects it.** A widget whose backing endpoint the current role cannot read is never rendered, not even as a blurred preview or a disabled skeleton — per the platform-wide rule restated in this document's own `# Accessibility` section, existence itself can be sensitive information.

# Route & Access

| | |
|---|---|
| Route | `app/(app)/dashboard/page.tsx` (Server Component) + `app/(app)/dashboard/loading.tsx` (shell-level skeleton) |
| Nav item | **Dashboard** — `LayoutDashboard` icon, position 1 of 10 in the primary Sidebar, per `NAVIGATION_SYSTEM.md`'s module map |
| Gating permission | **None.** Dashboard carries no single visibility permission — every authenticated member of a company can open `/dashboard`. Each widget's own data endpoint enforces its own permission independently; a role that fails every widget's permission still sees the page shell, the filter bar, and whichever Quick Actions it holds, never a "you don't have access" wall. |
| Breadcrumb | `Dashboard` only — no parent segment. Dashboard is the IA root; `PageHeader`'s breadcrumb trail for this route is a single, non-linked crumb, and the mobile page title collapses to the same single word. |
| Company/branch scope | Fixed to the active `X-Company-Id`; branch is an optional, URL-mirrored filter (`?branch={id}`) applied uniformly across every widget on the page — see `# Data & State`. |

Because no single permission gates the route, the same URL renders structurally different content per role, exactly as `NAVIGATION_SYSTEM.md` describes for this screen: an Owner or CFO sees the full KPI Strip (Cash, Revenue, Expenses, AR, AP) plus the Chart Region plus the condensed AI panel; a Sales Employee sees a Sales-scoped subset (their own pipeline's revenue KPI, no Cash or AP tiles, no AI panel unless `reports.read` is separately granted); a Warehouse Employee typically sees only Quick Actions relevant to inventory and an empty-but-not-broken KPI Strip. The permission keys the page's own widgets check, reused verbatim from their owning modules, are:

| Widget | Permission checked |
|---|---|
| Cash Position tile | `bank.read` |
| Revenue / Expenses tiles, Revenue-vs-Expense chart | `reports.financial.read` |
| AR Aging tile | `reports.customer.aging` |
| AP Aging tile | `reports.purchasing.read` |
| KPI Strip (composite) | `reports.read` (baseline) + each individual KPI's own domain permission, silently omitted rather than denied — see `REPORTING_TOOLS.md`'s discoverability-is-default-deny posture, reused here |
| Recent Activity | `accounting.read` (falls back to whatever narrower per-entry permission the underlying record itself requires for the deep link to resolve) |
| Quick Actions row | Each action's own create permission (`accounting.journal.create`, `sales.invoice.create`, `purchasing.bill.create`, `bank.reconcile`) |
| AI summary rail (Top Recommended Actions / Top Risks) | `reports.read` to view; the individual recommendation's own action permission to execute; `ai.approve` to dismiss a critical risk flag |

No control on this page is ever rendered disabled-and-unexplained: a Quick Action whose create permission is absent is omitted from the row entirely (its *existence* would tell a Sales Employee that vendor bills can be created, which is not itself sensitive, but the platform's own precedent in `COMPONENT_LIBRARY.md`'s row-action rule is applied uniformly here for consistency), while a KPI tile that fails only because of a *business-state* reason (a brand-new company with no posted journal lines yet) renders its dedicated empty state instead of hiding, per `# States` below.

# Layout & Regions

Dashboard instantiates `LAYOUT_SYSTEM.md`'s **Dashboard Template** with five regions on top of the persistent app shell (Sidebar, Topbar, Content, optional AI Rail). Unlike the AI Command Center's twenty-widget bento grid, Dashboard deliberately uses only four content regions plus a filter bar — generous whitespace over density, matching Design Principle 4 ("density with air") and the explicit instruction that a dashboard's 1–3 column grid stays restrained rather than gapless. Mapped onto `LAYOUT_SYSTEM.md`'s own named regions for this template: the KPI Strip below is that document's "KPI Strip" (3–4 equal stat cards, full width); the Chart Region below is its "Chart Region" (8/12, trend charts); the AI Summary Rail is its "Insights Feed" (4/12, AI findings/proposals, scrollable independently); and the Aging Summary plus Recent Activity plus Quick Actions together are its "Secondary Widgets" lower bento row (recent activity, upcoming payables, reconciliation status) — Dashboard's specific choice of which secondary widgets to show is this document's own, but the region shape itself is not reinvented.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Dashboard                                    [ This fiscal period ▾ ] [Branch ▾] │  ← Filter Bar (sticky, --z-sticky)
├─────────────────────────────────────────────────────────────────────────────┤
│ [Cash: KD 42,318.600] [Revenue: KD 96,220 ▲13.3%] [Expenses: KD 61,040 ▲9.1%] [AR 44,020 / AP 19,880] │  ← KPI Strip, 4x1, full width
├───────────────────────────────────────────────┬───────────────────────────────┤
│                                                 │  AI Summary                    │
│         Revenue vs. Expenses — 90 days         │  ● Health score 78/100          │
│         (line chart, Chart Region, 8/12)       │  ─ Top Recommended Actions ──── │
│                                                 │  ▸ Approve July vendor run 92%  │
│                                                 │  ▸ Categorize 214 txns    97%  │
│                                                 │  ─ Top Risks ─────────────────── │
├───────────────────────────┬─────────────────────┤  ▸ Diyar AR concentration 92%   │
│  AR Aging          │  AP Aging               │  [ View all in AI Command Center ] │
│  0-30 ██████░░ 61-90 ██░  │  0-30 ███░ 31-60 █░ │  (rail, 4/12, scrolls independently)│
├───────────────────────────┴─────────────────────┴───────────────────────────┤
│  Recent Activity                              │  Quick Actions                │
│  ● JE-1091 posted by S. Rahman — 2h ago        │  [+ Journal Entry] [+ Invoice] │
│  ● INV-2208 paid by Diyar Real Estate — 4h ago │  [+ Bill] [Reconcile a bank →] │
│  ● Bill BL-771 approved — yesterday            │                                 │
│  [ View all activity → ]                       │                                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

| Region | Grid position | Content | Notes |
|---|---|---|---|
| **Filter Bar** | Full width, sticky below Topbar (`--z-sticky`, offset `var(--topbar-h)`) | `PeriodPicker` (mode `relative`, default `this_fiscal_period`), `BranchSelect` (from the Company & Branch Switcher's own branch list, default "All branches") | Cascades into every other region's query; mirrored to `?range=` / `?branch=` URL params so a filtered view is a shareable link, per `NAVIGATION_SYSTEM.md`'s branch-switch convention |
| **KPI Strip** | `col-span-12`, one row (`4x1`-equivalent) | Four to six `KpiTile`s: Cash Position, Revenue, Expenses, AR, AP, and (role-permitting) Gross Margin | Full-width row of equal tiles at `lg`+; horizontal-scroll snap carousel below `md` — see `# Responsive Behavior` |
| **Chart Region** | `col-span-8` at `lg`+ | `RevenueExpenseChart` — a two-series line/area chart, Revenue in `accent`, Expenses in `ink-500`, default 90-day window, range toggle (30/60/90/custom) | Never mirrors under RTL (Rule 5, `LAYOUT_SYSTEM.md`) |
| **AI Summary Rail** | `col-span-4` at `lg`+ | Business Health Score chip, up to 3 condensed `AIProposalPanel` cards (Top Recommended Actions) and up to 3 condensed risk rows (Top Risks), a single "View all in AI Command Center" link | The *only* AI-forward content on this screen; scrolls independently of the Chart Region beside it |
| **Aging Summary** | `col-span-6` + `col-span-6` at `lg`+, stacked full-width below `md` | Two `AgingBar` instances (AR, AP) side by side | Each bucket segment is independently clickable per `AgingBar`'s own `onBucketClick` contract |
| **Recent Activity** | `col-span-8` (or full width on narrower breakpoints) | A capped, reverse-chronological feed of the last ~10 posted/approved/paid events across modules | Backed by the same Redis-ZSET activity feed the legacy `DashboardController@index` tile bundle already exposes — see `# Data & State` |
| **Quick Actions** | `col-span-4` | A short, permission-filtered row/column of primary create actions | Never more than 4 buttons; a 5th candidate action is a design-review signal to promote Quick Actions into a menu, not to keep adding buttons |

At `xl`+ the AI Rail (the platform-wide `360px` companion panel, distinct from this page's own AI Summary Rail column) may additionally dock inline per `LAYOUT_SYSTEM.md`'s App Shell Layout; when open, it shows the same condensed proposal queue any other screen would show, filtered to whatever the user is currently looking at — it is a companion to this page, not a duplicate of the AI Summary Rail region, which is part of Dashboard's own content and remains visible whether or not the global AI Rail is toggled.

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue, composed into a handful of new Dashboard-scoped components living in `components/dashboard/` per `PROJECT_STRUCTURE.md`'s module-folder convention. No primitive is hand-rolled.

| Component | Source | Role on this screen |
|---|---|---|
| `KpiTile` | `components/dashboard/kpi-tile.tsx` (existing) | Cash, Revenue, Expenses, AR, AP, Gross Margin tiles — each with `label`, `value`, `format`, `delta`, optional `sparklineData`, optional `confidence` |
| `TrendSparkline` | `components/dashboard/trend-sparkline.tsx` (existing) | The small inline trend line inside each `KpiTile`; `aria-hidden` where an adjacent text delta already states the trend (documented exception in `COMPONENT_LIBRARY.md`'s accessibility table) |
| `AmountCell` | `components/accounting/amount-cell.tsx` (existing) | Every raw monetary figure rendered outside a `KpiTile` — aging-bucket amounts, Recent Activity line amounts |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` (existing) | Muted currency annotation beside a base-currency figure when a company has multi-currency bank accounts |
| `StatusPill` | `components/shared/status-pill.tsx` (existing) | Recent Activity row status (posted/paid/approved), reusing each domain's own status/tone lookup table |
| `AgingBar` | `components/accounting/aging-bar.tsx` (existing) | AR and AP aging summaries, wired to `onBucketClick` for drill-down |
| `PeriodPicker` | `components/accounting/period-picker.tsx` (existing) | Filter Bar's date-range control, `mode="relative"` |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` (existing) | On the Business Health Score chip and every AI Summary Rail card |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` (existing), rendered in a `compact` presentation | Top Recommended Actions cards — same three-button contract (Do it / Send for approval / Dismiss), condensed copy |
| `Card`, `Badge`, `Button`, `Tooltip`, `DropdownMenu`, `Skeleton` | `components/ui/*` (existing shadcn primitives) | Region shells, statuses, action buttons, permission tooltips, loading placeholders |
| `EmptyState`, `ErrorState` | `components/shared/*` (existing) | Per-region empty/error rendering — see `# States` |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` (existing) | Wraps every Quick Action and every AI-panel action button |
| `RevenueExpenseChart` | `components/dashboard/revenue-expense-chart.tsx` (**new**, thin Recharts wrapper per `components/charts/` conventions) | The Chart Region's two-series line/area chart, pre-themed to `accent`/`ink-500`, redraws (never rescales a raster) at every breakpoint |
| `AgingSummaryCard` | `components/dashboard/aging-summary-card.tsx` (**new**) | Composes two `AgingBar`s with their own headers and "View full aging report" links |
| `RecentActivityFeed` | `components/dashboard/recent-activity-feed.tsx` (**new**) | A lightweight, non-virtualized list (capped at 10 visible rows; never a full `DataTable` — this feed is a summary, not a ledger) with a "View all" link into the owning module's own list screen |
| `QuickActionsBar` | `components/dashboard/quick-actions-bar.tsx` (**new**) | The permission-filtered row of create-action buttons, each wrapped in `PermissionGate` |
| `DashboardFilterBar` | `components/dashboard/dashboard-filter-bar.tsx` (**new**) | Composes `PeriodPicker` with a `BranchSelect`, writes both to the URL, exposes a single `{ range, branchId }` value every other region's query hook reads |
| `AiSummaryRail` | `components/dashboard/ai-summary-rail.tsx` (**new**) | Composes the Business Health Score chip, up to 3 `AIProposalPanel`s, and up to 3 risk rows into the right-hand rail |

The four "new" components above are pure composition — none introduces a new design token, a new API shape, or a new permission key; each is a documented arrangement of existing primitives and finance components, matching `COMPONENT_LIBRARY.md`'s stated contract that a screen never hand-rolls a table, a money cell, or a confidence indicator that already exists in the shared library.

# Data & State

## Endpoints

Dashboard reads from three families of endpoint, matching the platform's own module ownership boundaries — it never re-derives a figure a module's owning endpoint already computed.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Bootstrap tile bundle | `GET /api/v1/dashboard` | `reports.read` (per-tile permission enforced individually server-side) | Backed by the existing `DashboardController@index`; returns `{ cash_position, profit_loss, ar_ap_summary, recent_activity }` (plus `inventory_alerts`/`upcoming_payroll` for roles that hold those permissions), each independently Redis-cached under its own key so one tile's invalidation never discards the other seven |
| KPI Strip detail | `GET /api/v1/reports/kpis` | `reports.read` + each KPI's own domain permission | The same `kpi_strip` widget definition the AI Command Center's registry documents; Dashboard requests the finance-relevant subset only (`keys=cash_position,revenue,expenses,gross_margin,ar_total,ap_total`) |
| Revenue trend | `GET /api/v1/reports/revenue-trends` | `reports.financial.read` | Chart Region's Revenue series; supports `range`, `branch_id`, `compare_to` params |
| Expense trend | `GET /api/v1/reports/expense-trends` | `reports.financial.read` | Chart Region's Expenses series; identical query shape to Revenue Trends so both series share one date axis |
| Cash position | `GET /api/v1/banking/bank-accounts` | `bank.read` | Live reconciled balance across every `bank_accounts` row, summed to base currency; the one KPI Strip figure sourced from Banking rather than Reports |
| AR aging | `GET /api/v1/reports/ar-aging` | `reports.customer.aging` | Bucketed `0-30 / 31-60 / 61-90 / 90+`, feeds the AR `AgingBar` and the AR KPI tile's total |
| AP aging | `GET /api/v1/reports/ap-aging` | `reports.purchasing.read` | Same bucket set, vendor side |
| Recent activity (full) | `GET /api/v1/accounting/journal-entries?sort=-created_at&per_page=10` (+ equivalent calls per module for a cross-module feed) | `accounting.journal.read` (per source module) | The "View all activity" destination; the Dashboard's own feed reads the pre-aggregated bundle above instead of calling this directly |
| Quick Action targets | `/accounting/journal-entries/new`, `/sales/invoices/new`, `/purchasing/bills/new`, `/banking/{id}/reconciliation` | matches each target route's own gate | Navigations, not API calls, from this screen |
| AI recommendations (condensed) | `GET /api/v1/ai/recommendations?limit=3` | `reports.read` (+ the recommendation's own action permission to execute) | Same `ai_decisions` rows the AI Command Center's `ai_recommendations_feed` widget reads, capped and re-labeled "Top Recommended Actions" on this screen |
| AI risks (condensed) | `GET /api/v1/ai/risks?category=all&limit=3&sort=-severity` | `reports.read` | Same `ai_risk_flags` table the AI Command Center's `detected_risks_radar` widget reads |
| Business Health Score | `GET /api/v1/ai/health-score` | `reports.read` | Single composite chip at the top of the AI Summary Rail; expands to the same five-component breakdown `AI_COMMAND_CENTER.md` documents in full, on click, via a `Sheet` — Dashboard does not re-render that breakdown inline |

## Query keys and cache tuning

```ts
// lib/query/keys.ts (dashboard-scoped factories)
export const dashboardKeys = {
  all: ["dashboard"] as const,
  tiles: (filters: DashboardFilters) => [...dashboardKeys.all, "tiles", filters] as const,
  kpis: (filters: DashboardFilters) => [...dashboardKeys.all, "kpis", filters] as const,
  revenueTrend: (filters: TrendFilters) => [...dashboardKeys.all, "revenue-trend", filters] as const,
  expenseTrend: (filters: TrendFilters) => [...dashboardKeys.all, "expense-trend", filters] as const,
  arAging: (filters: DashboardFilters) => [...dashboardKeys.all, "ar-aging", filters] as const,
  apAging: (filters: DashboardFilters) => [...dashboardKeys.all, "ap-aging", filters] as const,
  recentActivity: () => [...dashboardKeys.all, "recent-activity"] as const,
};

export const aiSummaryKeys = {
  recommendations: (limit: number) => ["ai", "recommendations", { limit }] as const,
  risks: (limit: number) => ["ai", "risks", { limit }] as const,
  healthScore: () => ["ai", "health-score"] as const,
};
```

`DashboardFilters` is `{ branchId: number | null; range: RelativeRange }`, the single shape every widget-level query key includes, produced once by `DashboardFilterBar` and threaded down as props — no two widgets independently re-derive the active filter from the URL, avoiding the class of bug where a branch switch updates the KPI Strip but leaves the Chart Region showing the previous branch's data for one extra render.

Cache tuning follows `FRONTEND_ARCHITECTURE.md`'s data-class table exactly, applied to this screen's own resources:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Live/derived figures | `tiles` (cash/profit/AR-AP bundle), `kpis` | `0` (always stale) | Correctness over avoiding a refetch; kept fresh primarily via Realtime invalidation, not polling |
| Trend/aggregate charts | `revenueTrend`, `expenseTrend`, `arAging`, `apAging` | `60_000` (60s) | Recomputed on `invoice.paid`/`bill.approved` domain events regardless of staleness; a 60s ceiling bounds the worst case if an event is missed |
| Recent activity | `recentActivity` | `30_000` | Frequent enough writes that a stale feed is a real annoyance, not worth sub-minute polling |
| AI feeds | `aiSummaryKeys.recommendations`, `.risks` | `10_000`, `refetchOnWindowFocus: true` | Matches `FRONTEND_ARCHITECTURE.md`'s AI-feed row verbatim — a returning user should see what accumulated while away |
| Health score | `aiSummaryKeys.healthScore` | `900_000` (15 min) | Recomputed once per ledger close per `AI_COMMAND_CENTER.md`; no benefit to fetching it more often |

## Server-first paint, then Suspense per region

`app/(app)/dashboard/page.tsx` is a Server Component that resolves the default filter (the caller's fiscal-period default, no branch) and streams each region behind its own `<Suspense>` boundary, so a slow AI endpoint never blocks the KPI Strip from painting — the exact pattern `FRONTEND_ARCHITECTURE.md` establishes for this route:

```tsx
// app/(app)/dashboard/page.tsx
import { Suspense } from "react";
import { DashboardFilterBar } from "@/components/dashboard/dashboard-filter-bar";
import { KpiStrip } from "@/components/dashboard/kpi-strip";
import { RevenueExpenseChart } from "@/components/dashboard/revenue-expense-chart";
import { AiSummaryRail } from "@/components/dashboard/ai-summary-rail";
import { AgingSummaryCard } from "@/components/dashboard/aging-summary-card";
import { RecentActivityFeed } from "@/components/dashboard/recent-activity-feed";
import { QuickActionsBar } from "@/components/dashboard/quick-actions-bar";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant-scoped — never statically cached, per FRONTEND_ARCHITECTURE.md

export default function DashboardPage({
  searchParams,
}: { searchParams: { branch?: string; range?: string } }) {
  const filters = { branchId: searchParams.branch ? Number(searchParams.branch) : null,
                     range: (searchParams.range as RelativeRange) ?? "this_fiscal_period" };

  return (
    <div className="space-y-6">
      <DashboardFilterBar initialFilters={filters} />
      <Suspense fallback={<WidgetSkeleton variant="kpi-strip" />}>
        <KpiStrip filters={filters} />
      </Suspense>
      <div className="grid grid-cols-12 gap-6">
        <Suspense fallback={<WidgetSkeleton variant="chart" className="col-span-12 lg:col-span-8" />}>
          <RevenueExpenseChart filters={filters} className="col-span-12 lg:col-span-8" />
        </Suspense>
        <Suspense fallback={<WidgetSkeleton variant="rail" className="col-span-12 lg:col-span-4" />}>
          <AiSummaryRail filters={filters} className="col-span-12 lg:col-span-4" />
        </Suspense>
      </div>
      <Suspense fallback={<WidgetSkeleton variant="aging" />}>
        <AgingSummaryCard filters={filters} />
      </Suspense>
      <div className="grid grid-cols-12 gap-6">
        <Suspense fallback={<WidgetSkeleton variant="feed" className="col-span-12 lg:col-span-8" />}>
          <RecentActivityFeed className="col-span-12 lg:col-span-8" />
        </Suspense>
        <QuickActionsBar className="col-span-12 lg:col-span-4" />
      </div>
    </div>
  );
}
```

Each streamed region pairs with its own `<ErrorBoundary>` one level inside the `Suspense` fallback (per `FRONTEND_ARCHITECTURE.md`'s three-granularity error model) — a Forecast/AI timeout on `AiSummaryRail` renders that one rail's inline retry card without touching the KPI Strip or the chart beside it. `QuickActionsBar` is not wrapped in `Suspense` at all: it renders synchronously from the already-resolved session permissions (`SessionProvider`), with no network call of its own.

## Realtime

Dashboard subscribes to the same private, company-scoped Reverb channel convention every module uses, plus the AI job channel for its own condensed rail:

| Channel | Purpose |
|---|---|
| `private-company.{id}.dashboard.{dashboard_id}` | Live-refresh any tile flagged `realtime: true` — Cash Position and the KPI Strip's live figures |
| `private-company.{id}.ai-jobs` | Refreshes the AI Summary Rail the moment an agent finishes a run affecting insights/recommendations |
| `private-company.{id}.notifications.{user_id}` | Feeds the Topbar's notification bell only — Dashboard's own regions do not duplicate this stream |

Reverb event listeners purge or patch specific query keys rather than the whole page, mirroring `AI_COMMAND_CENTER.md`'s cache-invalidation table applied to Dashboard's own resources: `invoice.paid` purges `dashboardKeys.tiles`, `dashboardKeys.kpis`, and `dashboardKeys.revenueTrend`; `bank.synced` purges `dashboardKeys.tiles` (Cash Position) alone; `journal_entry.posted` purges `dashboardKeys.tiles`, `dashboardKeys.kpis`, and `dashboardKeys.recentActivity`; any new `ai_decisions` or `ai_risk_flags` row purges `aiSummaryKeys.recommendations`/`.risks` respectively. A high-frequency, low-risk figure (the Cash Position tile's live number while a bank sync is actively running) may patch the cache directly via `setQueryData` for a tick, but is always reconciled by a full invalidation once the sync reaches a terminal state, per the platform's "invalidate by default, patch only for high-frequency low-risk ticks" rule.

## AI agents feeding this screen

| Agent | Contribution to Dashboard |
|---|---|
| `REPORTING_AGENT` | Computes the Revenue/Expense trend series (pure aggregation, **auto**) and the KPI Strip's headline commentary |
| `GENERAL_ACCOUNTANT` | Auto-categorization insights that surface as Recent Activity annotations; narrates expense-trend drivers when the 70% explanatory threshold is cleared |
| `CFO_AGENT` | Computes the Business Health Score composite and its five sub-scores |
| `TREASURY_MANAGER` | Owns the Cash Position tile's traffic-light threshold and short "why" narrative when a threshold is crossed |
| `FRAUD_DETECTION` | Cross-checks any expense spike before it is allowed to render as a plain "cost went up" figure; a confirmed hold surfaces as a Top Risk card, never silently |
| `APPROVAL_ASSISTANT` | Decides, for each Top Recommended Action, whether the condensed card renders "Do it" or "Send for approval," reusing the exact same `can_execute_directly` server-computed field the full AI Command Center relies on |

# Interactions & Flows

**Opening the screen.** First paint renders the shell (Sidebar, Topbar, Filter Bar) immediately; the KPI Strip typically resolves within one Redis-cache round trip, tracking toward `REPORTS.md`'s own stated Dashboard budget — "initial load targets under 1.5 seconds to first meaningful paint (KPI tiles from Redis-cached rollups render immediately; any widget still loading shows a skeleton state rather than blocking the rest of the dashboard)" — a budget that document states for this exact screen; the Chart Region, AI Summary Rail, Aging Summary, and Recent Activity stream in independently as their own Suspense boundaries settle, each replacing its skeleton in place with no layout shift.

**Changing the period or branch filter.** `DashboardFilterBar` writes the new `{ range, branchId }` to the URL (`router.push`, not a client-only state change) and every widget's query key changes accordingly, triggering a scoped refetch — the KPI Strip and both trend charts refetch; the AI Summary Rail's recommendations/risks do **not** refetch on a period change (an AI recommendation is not "for last month"), only on a branch change where the underlying record set actually differs. `placeholderData: keepPreviousData` keeps every affected region showing its previous values, dimmed, rather than flashing empty while the new filter resolves.

**Clicking a KPI tile.** Each `KpiTile`'s `onClick` drills into the record set behind the number, pre-filtered to match: Cash → `/banking/accounts`; Revenue → `/accounting/financial-statements/profit-and-loss?range=...` (the exact nested route `BALANCE_SHEET.md` establishes for every statement type — Dashboard reuses it rather than inventing a parallel `/accounting/statements/...` path); AR → `/sales/invoices?filter[status][in]=open&sort=-due_date`; AP → `/purchasing/bills?filter[status][in]=open`. The click never opens a modal over the Dashboard — a KPI number always means "go look at the source," never "expand in place," keeping this screen a launch point rather than a second copy of every report.

**Clicking a chart segment or an aging bucket.** The `RevenueExpenseChart` and both `AgingBar`s drill into their owning module's filtered list, reusing `AgingBar`'s existing `onBucketClick` contract verbatim (`router.push('/sales/invoices?filter[aging_bucket][in]=31-60')`, for example).

**Using a Quick Action.** Each button in `QuickActionsBar` navigates to its target's canonical full-page route (`/accounting/journal-entries/new`, `/sales/invoices/new`, `/purchasing/bills/new`, or the reconciliation workbench for a bank account selected via a short picker) — never an inline modal on the Dashboard itself, consistent with `FRONTEND_ARCHITECTURE.md`'s rule that sensitive-adjacent creation flows are always full, linkable pages, with the intercepting-route quick-create pattern (`@modal/(.)new`) available only from within that target module's own list screen, not from Dashboard.

**Acting on a Top Recommended Action.** The condensed `AIProposalPanel` renders at most three controls — Do it (only if `can_execute_directly` is true), Send for approval, Dismiss (reason required) — identical in contract to the full AI Command Center's Recommendations feed, just capped to three cards and stripped of the alternatives-expansion affordance to keep the rail scannable; "View all in AI Command Center" is always present and always the way to see a card's alternatives in full.

**Personalizing the layout.** A "Customize" affordance in the KPI Strip's header opens a lightweight settings `Sheet` that lets a user hide or reorder KPI tiles for their own view only, persisted to `user_dashboard_layouts.layout_overrides` (`PATCH /api/v1/users/me/dashboard-layout`) — this never mutates the shared dashboard definition other users see, mirroring `REPORTS.md`'s per-user override layer exactly. Dashboard does not expose the AI-suggested layout promotion (`dashboard_customization` widget) the AI Command Center offers; that remains scoped to the richer surface.

# AI Integration

Dashboard's AI surface is intentionally the smallest of any screen in the platform that touches AI at all — one composite score, up to three recommendations, up to three risks — because this screen's job is to answer "where do the numbers stand," not "what has the workforce found." Every AI-sourced element still obeys the platform-wide contract in full:

- **Confidence and reasoning are never hidden.** The Business Health Score chip carries its own `ConfidenceBadge`; each Top Recommended Action and Top Risk card shows a `ConfidenceBadge` with the underlying `reasoning` available on hover/focus via the badge's own tooltip slot, exactly as `COMPONENT_LIBRARY.md` specifies — no bare number, ever.
- **Visual provenance is consistent.** Every AI-sourced card on this screen uses the same `border-s-2 border-s-accent` treatment plus the small "AI" badge every other AI-authored surface in the product uses (`LAYOUT_SYSTEM.md`'s `ai-insight` card variant) — a user should never have to guess whether a number came from the ledger or from an agent.
- **The three-button pattern is exact, not approximated.** `can_execute_directly` is a field the API computes and returns; the condensed `AIProposalPanel` on Dashboard never independently re-evaluates a recommendation's autonomy level or confidence threshold — if the field is `false`, "Do it" does not render, full stop, matching the platform's structural (not merely cosmetic) guarantee that a gated action has no client-side path to execute itself from this screen.
- **A confirmed fraud hold overrides the calm register.** If `FRAUD_DETECTION` has placed a payment hold, the corresponding Top Risk card renders with the platform's one exception to "never alarming" — a distinct Error-Red left-border treatment no other state on this screen uses — and is not dismissible from Dashboard; it can only be resolved from the Fraud Alerts panel or the Approval Center it links to, so a user cannot swipe away a held payment without ever seeing why.
- **Sensitive actions never terminate on this screen.** Approving a payroll release, a bank transfer, or a tax submission recommended from the AI Summary Rail always routes through the Approval Center (`/approvals`) exactly as every other entry point into that flow does; Dashboard never renders a one-click "approve" for anything on the sensitive-action list, regardless of confidence.

# States

Every region has its own loading, empty, and error presentation — no region blocks another, and none ever renders a bare spinner as its primary loading state.

| Region | Loading | Empty | Error |
|---|---|---|---|
| KPI Strip | Skeleton tiles matching final layout (`Skeleton className="h-8 w-32"` inside each tile shell, per `KpiTile`'s own `loading` prop) | Brand-new company with no posted journal lines: a dedicated "Your chart of accounts isn't set up yet" card with a setup CTA — never the generic zero-value tiles a filtered-to-zero period would otherwise show | Widget-level `<ErrorBoundary>` renders a small inline "Couldn't load this figure — Retry" card per failed tile; the other tiles remain interactive |
| Revenue/Expense chart | Low-contrast shimmer sweep (1.6s, `ink-3` → `ink-4` → `ink-3`) matching the chart's final aspect ratio | "No transactions yet this period" with a link to create the first journal entry or connect a bank feed | Same inline `ErrorBoundary` card, offering a period-range fallback ("Try the last fiscal year instead") when the failure is a timeout on a very wide range |
| Aging Summary | Skeleton bars at the same height as the real `AgingBar` | "No open invoices" / "No open bills" per side, independently — one side can be empty while the other has data | Retry card; each `AgingBar` fails independently since they hit different endpoints |
| AI Summary Rail | Three skeleton cards + a skeleton chip | Calm "You're all caught up — no open recommendations or risks right now" message, explicitly not styled as an error or a warning | A distinct "AI insights are temporarily unavailable" state (not a generic error, not an infinite spinner) when the AI engine itself returns `503`, respecting any `Retry-After` header |
| Recent Activity | 5–6 skeleton rows | "No activity yet — post your first journal entry to see it here" with a CTA into Quick Actions | Inline retry row; the "View all activity" link remains functional even if the condensed feed itself fails, since it points at the underlying module's own list page |
| Quick Actions | Renders synchronously from session permissions; never shows a loading state | An empty row (rare — only a role with zero create permissions anywhere) collapses to nothing rather than an empty bordered box | Not applicable — no network call |

A route-level `error.tsx` remains the outermost safety net for a genuinely unexpected failure (e.g., the session itself becoming invalid mid-render), but per the three-granularity model this should essentially never fire for an individual widget's ordinary `4xx`/`5xx` — those are caught and handled inline, one region at a time.

# Responsive Behavior

Dashboard follows `LAYOUT_SYSTEM.md`'s per-template responsive table for the Dashboard Template exactly:

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | KPI Strip becomes a horizontal-scroll, snap-aligned carousel (one tile per screen-width, swipe to advance); Chart Region, AI Summary Rail, Aging Summary, Recent Activity, and Quick Actions each stack full-width, one per row, in the order given in `# Layout & Regions`; the persistent Sidebar is replaced by the bottom tab bar (Dashboard is one of its five destinations) |
| `md` (768–1023px) | KPI Strip becomes two tiles per row; regions remain single-column below the strip |
| `lg` (1024–1279px) | KPI Strip becomes a full row; the Chart Region / AI Summary Rail pair activates its 8/4 split; Aging Summary's two `AgingBar`s sit side by side |
| `xl`+ (≥1280px) | Same bento layout, generous margins; the global AI Rail companion panel may dock inline alongside Dashboard's own AI Summary Rail without competing for the same column, since the AI Rail renders as its own fourth shell region outside the Content grid |

`KpiTile` and the two new composed cards use Tailwind v4 container queries (`@container`), not only viewport breakpoints, so the same tile renders correctly whether it is one-per-carousel-slide on mobile or one-of-six in a full desktop row:

```tsx
<div className="@container">
  <Card className="@sm:flex @sm:items-center @sm:justify-between grid gap-2">
    <CardTitle className="@sm:text-2xl text-xl">KD 42,318.600</CardTitle>
    <TrendSparkline className="@sm:block hidden" data={cashTrend} ariaLabel="Cash position, last 7 days" />
  </Card>
</div>
```

The Chart Region's own horizontal space is the one place Dashboard explicitly does **not** collapse to a card stack even on mobile — a trend line is read as a shape, not a table, so it stays a chart at every width, scrolling horizontally inside its own bordered container only if a custom range genuinely exceeds the viewport rather than reflowing into anything else. Touch targets on Quick Actions and every aging-bucket segment maintain the platform's 44×44px minimum hit area below `md`, implemented once in the shared `IconButton`/`Button` components rather than per instance on this screen.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and `LAYOUT_SYSTEM.md`'s RTL contract and applied, concretely, to this screen's specific content:

- **Logical properties only.** The Filter Bar's `PeriodPicker`/`BranchSelect` pairing, the KPI Strip's tile order, the Quick Actions row, and the Recent Activity list all use `ms-*`/`me-*`/`text-start`/`text-end` exclusively; flipping `dir="rtl"` on `<html>` mirrors the whole page — sidebar to the opposite edge, KPI Strip reading start-to-end in the opposite direction, Quick Actions button order reversed — with zero screen-specific RTL code.
- **Numerals, currency codes, and dates never mirror.** Every `KpiTile` value, every `AmountCell` in the Aging Summary and Recent Activity feed, and every date in a Recent Activity timestamp renders inside a `dir="ltr"` / `unicode-bidi: isolate` span, per `AmountCell`'s and `Bidi`'s existing implementation — `KD 42,318.600` reads left-to-right even inside a fully Arabic sentence describing it.
- **Numeric alignment is physically fixed, not logical (Exception A).** Every amount column in the Aging Summary uses hard-coded `text-right`, not `text-end`, in both directions, so a bilingual Finance team scanning magnitude and decimal alignment sees figures land on the same edge regardless of interface language.
- **Charts never mirror (Rule 5).** `RevenueExpenseChart` keeps a fixed left-to-right time axis (earliest to latest) and a fixed legend order in both `dir="ltr"` and `dir="rtl"` — only the chart's container position within the 8/4 grid split mirrors, never its internal axis. This is the single most consequential RTL rule on this screen, since a mirrored trend chart is one of the most common and most confusing RTL mistakes in Arabic-market financial software.
- **Bilingual names throughout.** Recent Activity's actor names and any customer/vendor name surfaced in a drill-down tooltip render `name_en`/`name_ar` per the active locale, with the API always returning both fields regardless of `Accept-Language` so the client — not the server — owns which language displays, per `COMPONENT_LIBRARY.md`'s bilingual-data convention.

| Context | English | Arabic |
|---|---|---|
| KPI delta | +13.3% vs last month | +13.3% مقارنة بالشهر الماضي |
| Cash tile label | Cash position | الوضع النقدي |
| AI recommendation | Suggested by the Reporting Agent — 88% confidence. | مقترح من وكيل التقارير — بثقة 88%. |
| Empty state | No activity yet — post your first journal entry to see it here. | لا توجد أنشطة حتى الآن. أنشئ أول قيد محاسبي لتظهر هنا. |
| Quick action | New journal entry | قيد محاسبي جديد |

Arabic copy on this screen is authored directly by a fluent professional-register writer, not machine-translated from the English strings above, matching the platform's stated voice discipline — a KPI delta or an AI recommendation that sounds precise and calm in English must sound identically precise and calm in Arabic, not merely correctly translated.

# Dark Mode

Dashboard introduces no new color, elevation, or radius token — every surface resolves through the same `:root[data-theme="dark"]` remap `COMPONENT_LIBRARY.md` defines, verified per-component rather than assumed. One naming note for whoever implements this next: `COMPONENT_LIBRARY.md`'s own token block (`--qayd-ink-950`…`--qayd-ink-0`, `--qayd-accent-700/600/500/100`, `success`/`warning`/`danger`/`info-600`) and `DESIGN_LANGUAGE.md`'s later token block (`--qayd-ink-1`…`--qayd-ink-12`, `accent`/`accent-subtle`/`accent-strong`/`accent-on`, `positive`/`negative`/`warning`, deliberately no `info`) are two different numbering conventions for what is conceptually the same near-monochrome-plus-one-accent system — a platform-level drift between those two documents, not something introduced here. This document names tokens the way `COMPONENT_LIBRARY.md` does throughout, because every component Dashboard composes (`KpiTile`, `AgingBar`, `AmountCell`, `StatusPill`, `ConfidenceBadge`) is implemented in that document using exactly that scale; describing them with `DESIGN_LANGUAGE.md`'s newer names here would describe code that does not match what those components actually ship. The reconciliation of the two token blocks belongs to `COMPONENT_LIBRARY.md` and `DESIGN_LANGUAGE.md` themselves, not to an individual screen spec:

- **KPI tiles and cards** use `bg-surface`/`bg-ink-100` in light mode and their dark-remapped equivalents; elevation gets *lighter*, not darker, in dark mode (a `KpiTile`'s card background is a step lighter than the canvas behind it), matching the platform's stated "physical light" dark-mode strategy rather than a naive inversion.
- **Chart colors** in `RevenueExpenseChart` read from `--qayd-accent-600` and `--qayd-ink-700` via CSS variables, never a hard-coded hex, so the Revenue series lifts from `#0E7A5F` to its dark-mode-lifted equivalent automatically and stays legible against the dark canvas without a second, hand-tuned palette.
- **Semantic states** (the Cash tile's amber/red threshold treatment, a held-payment risk card's red border) use the same `success`/`warning`/`danger` tokens in both themes, remapped for contrast, never a separate dark-only color choice.
- **`AgingBar`'s bucket fills** (`bg-ink-300`, `bg-warning/50`, `bg-warning`, `bg-danger`) resolve through the same token set, so the 0–30/31–60/61–90/90+ severity ramp reads identically in both themes.

Every Storybook story for the four new composed components (`RevenueExpenseChart`, `AgingSummaryCard`, `RecentActivityFeed`, `AiSummaryRail`) ships the platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and Dashboard's own Playwright suite captures the same four-way screenshot set at the route level per `FRONTEND_ARCHITECTURE.md`'s testing convention, so a token regression on this specific screen is caught in CI rather than in a support ticket.

# Accessibility

Dashboard targets WCAG 2.1 AA as a floor, identically in both languages and both themes, with the following screen-specific applications of the platform's general rules:

- **Landmark structure.** The page's regions are real landmarks, not visually-implied sections: a visually-hidden `<h2>` labels each region for screen-reader navigation even where the sighted design omits a visible heading, mirroring the pattern already established for AI Command Center's own Cash Flow Status panel:

  ```tsx
  <section aria-labelledby="revenue-expense-heading">
    <VisuallyHidden asChild><h2 id="revenue-expense-heading">{t("dashboard.revenue_vs_expenses")}</h2></VisuallyHidden>
    <RevenueExpenseChart />
  </section>
  ```

- **Keyboard path.** Tab order flows Filter Bar (`PeriodPicker` → `BranchSelect`) → KPI Strip (each tile a single focusable stop when it has an `onClick`, otherwise a static, non-tabbable region per `KpiTile`'s own contract) → Chart Region's range toggle → AI Summary Rail's cards and their action buttons → Aging Summary's clickable bucket segments → Recent Activity's row links → Quick Actions. No region requires a mouse to reach or activate any control.
- **Realtime updates announce politely, never assertively.** A Cash Position tick arriving over the `.dashboard` Reverb channel, or a new card appearing in the AI Summary Rail, uses `aria-live="polite"` exclusively — a fast-moving KPI or AI feed must never interrupt whatever a screen-reader user is currently reading, matching the same rule `AI_COMMAND_CENTER.md` applies to its own insight feeds.
- **Sparklines and charts carry a mandatory `aria-label`; they are never purely decorative next to an unlabeled number.** Where a `KpiTile`'s text delta already states the trend in words ("up 13.3% vs last month"), its inline `TrendSparkline` may be `aria-hidden="true"` — the one documented exception — but `RevenueExpenseChart` itself always carries a full-summary `aria-label` since no adjacent text fully restates a two-series 90-day trend.
- **Color is never the only channel.** The Cash tile's threshold state, `AgingBar`'s severity ramp, and a held-payment risk card's red border are every one paired with a text label or an icon — a colorblind user or a grayscale print of this screen loses no information a sighted, color-normal user has.
- **Permission-gated controls are legible, not mysterious.** A Quick Action whose create permission is absent is omitted entirely (existence-sensitive, per `COMPONENT_LIBRARY.md`'s row-action precedent); a KPI tile or chart that is visible but currently empty for a business-state reason (not a permission reason) always names why, in text, rather than presenting an unexplained blank.
- **Focus management on drill-down.** Clicking a KPI tile, a chart segment, or an aging bucket navigates to a new route rather than mutating this page in place, so focus lands on the destination page's own heading via the platform's standard route-change focus-reset — Dashboard introduces no bespoke focus-trap logic of its own.

# Performance

- **Streamed, not blocking.** Per `# Data & State`, every region is its own `Suspense` boundary; the slowest widget on the page (typically the AI Summary Rail, if an agent run is still in flight) never delays the KPI Strip's first paint, and a failure in one region never takes down another (`react-error-boundary` per region, as established for the AI Command Center and reused here).
- **Cached at the source.** The bootstrap tile bundle (`GET /api/v1/dashboard`) is Redis-cached per tile server-side, tracking toward `REPORTS.md`'s own stated budget for this screen ("Dashboard initial load targets under 1.5 seconds to first meaningful paint"); a cache miss on a deterministic tile (Cash, Revenue, Expenses — plain aggregation over posted tables) recomputes synchronously inline since it is cheap SQL, while a cache miss on an AI-authored tile never blocks the page — it serves the last-cached payload with an explicit `stale: true` marker rather than making the user wait on an LLM call to render their home screen.
- **Bundle budget.** The dashboard route's shell (excluding individually `next/dynamic`-split widget chunks — the chart library in particular) is held to the platform's stated first-load JS budget for this exact route, enforced in CI against `performance-budgets.json`, not left to reviewer judgment.
- **Code-split the chart.** `RevenueExpenseChart`'s underlying Recharts dependency is loaded via `next/dynamic({ ssr: false })` with a matching skeleton `loading`, so a role whose permissions never surface the Chart Region (a narrow Sales Employee view, for instance) never downloads it.
- **Debounced, stable filter changes.** The Filter Bar's branch selector and any free-text search inside Recent Activity's "View all" destination debounce at 300ms; every filtered query uses `placeholderData: keepPreviousData` so a filter change dims the previous result rather than flashing an empty region.
- **Realtime patches are the exception, not the rule.** Only the Cash Position tile's live figure during an active bank sync uses a direct `setQueryData` patch for a tick-by-tick feel; every other realtime event on this screen goes through an ordinary `invalidateQueries` call, which can never drift from what the server actually holds.
- **Web Vitals are tracked against a realistic baseline.** LCP/CLS/INP for this route are tagged with company-size band (a company with a large chart of accounts and transaction volume is expected to have different tail latencies than a brand-new company with an empty ledger), so a genuine regression is distinguishable from a scale-dependent, expected slowdown.

# Edge Cases

Every row below is a scenario this specific screen must handle explicitly, not a generic restatement of `FRONTEND_ARCHITECTURE.md`'s platform-wide edge-case posture — each resolution is the concrete behavior Dashboard's own components, endpoints, and cache keys produce.

| Edge case | Frontend behavior |
|---|---|
| A company switch fires while one of Dashboard's own widget fetches is still in flight (e.g., the Chart Region's `revenueTrend` request is mid-flight when the user picks a different company from `WorkspaceSwitcher`) | `queryClient.clear()` runs before `router.refresh()` per `FRONTEND_ARCHITECTURE.md → Company switching`; the in-flight request's eventual response is discarded on arrival because its query key no longer exists in the (cleared) cache — it can never be written into the new company's `dashboardKeys.*` entries. `router.push('/dashboard')` (not `router.back()`) always follows, so Dashboard itself is the landing screen after a switch, exactly as `NAVIGATION_SYSTEM.md`'s own switch flow specifies. |
| The URL's `?branch=` param names a branch the caller's role no longer has access to — revoked mid-session, or the branch itself was deleted between the link being shared and being opened | Every widget's own endpoint 403s/404s on that `branch_id` independently; the Filter Bar itself does not pre-validate the param client-side (that would require a second round-trip before rendering anything). Each affected region renders its ordinary `ErrorBoundary` retry card rather than the page redirecting or silently falling back to "All branches," so the user is never shown a different scope than the one their link claimed without being told. The `BranchSelect` control's own options list (fetched from the active company's current branch set) simply will not contain the stale branch, which is the cue to pick a valid one. |
| A role holds `reports.read` but none of the finer-grained keys any individual KPI needs (`reports.financial.read`, `bank.read`, `reports.customer.aging`, `reports.purchasing.read`) — a narrow custom role, not one of the platform's named defaults | The KPI Strip renders zero tiles rather than a row of permission-denied placeholders; the Chart Region, Aging Summary, and AI Summary Rail render their own empty-region fallbacks in the same way (never a "you don't have access" wall for the whole page, per this document's own `# Route & Access` rule). If the same role also lacks every Quick Action's create permission, that row collapses to nothing and Recent Activity is the only region left with content — a legitimate, minimal-but-not-broken rendering of the page, not an error state. |
| A brand-new company's first user opens a bookmarked or shared Dashboard link that already carries a non-default `?range=last_quarter&branch=3` from before any data existed | The filter values themselves are honored and mirrored back into the Filter Bar's controls (a stale filter is not silently reset to defaults); every region still renders its brand-new-company empty state from `# States` rather than a filtered-to-zero-results empty state, because the distinguishing signal each endpoint returns is "no posted `journal_lines` for this company at all," not "no rows matched this specific filter" — the two are visually and textually different empty states, and Dashboard is careful to show the former even when a filter is also active. |
| A company holds bank accounts in more than one currency (a `bank_accounts` set spanning KWD and, say, AED) | The Cash Position tile's single headline figure is always the sum converted to the company's base currency using each account's stored `exchange_rate`, per `DESIGN_CONTEXT.md`'s multi-currency rule, and renders through `AmountCell`/`formatAmount` at the base currency's own decimal precision (3 places for KWD) — it never averages or truncates precision from a mixed set. A `CurrencyTag` in `emphasis="muted"` mode annotates the tile only when more than one currency actually contributes, so a single-currency company's tile stays uncluttered by a redundant currency label. |
| The AI engine (FastAPI layer) is down or returns `503` for an extended period — long enough that the AI Summary Rail's "temporarily unavailable" state (`# States`) has been showing for several minutes | The KPI Strip, Chart Region, Aging Summary, and Recent Activity are entirely unaffected, since none of them call the AI engine — this is the direct, visible payoff of Dashboard's own founding split from the AI Command Center: a finance team can still see cash, revenue, expenses, and AR/AP with the AI layer fully down. The Business Health Score chip specifically (also AI-sourced) shows the same muted-dash-plus-retry treatment as any other AI-dependent element; it never shows a stale score without the `stale: true`/`stale_since` marker `AI_COMMAND_CENTER.md`'s own widget-registry cache policy defines. |
| Two browser tabs have Dashboard open; the user changes the branch filter in one tab | Per `NAVIGATION_SYSTEM.md`'s branch-switch contract, a branch change is a URL-mirrored, client-scoped filter, not a session-level change — the second tab's own `?branch=` param (or lack of one) is untouched, and its `activeBranchId` in `useShellStore` is independent per the store's own hydration, so the two tabs can legitimately show two different branches at once with no synchronization bug. This is unlike a *company* switch, which is session-level and — per `FRONTEND_ARCHITECTURE.md`'s own cross-tab discussion — a genuinely different concern this document does not need to solve for branch-level filtering. |
| Laravel Reverb's WebSocket connection drops for an extended period (a laptop sleeping, a flaky office connection) and later reconnects | Every one of Dashboard's own realtime-fed query keys (`dashboardKeys.tiles`, `.kpis`, `.revenueTrend`, `.expenseTrend`, `.arAging`, `.apAging`, `.recentActivity`, `aiSummaryKeys.*`) is invalidated once, as a single batch, on reconnect — mirroring `AI_COMMAND_CENTER.md`'s own reconnect rule verbatim. A `bank.synced` or `invoice.paid` event that fired during the outage and was missed must still be reflected the moment connectivity returns; Dashboard never trusts a stale Cash Position figure just because no error was ever shown for it. |
| A Top Recommended Action or Top Risk card's underlying `ai_decisions`/`ai_risk_flags` row changes state (is actioned from the full AI Command Center, or superseded by a fresher agent run) between the condensed card rendering on Dashboard and the user clicking one of its buttons | The mutation (`accept`, `send for approval`, `dismiss`) re-fetches the target decision immediately before submitting and compares it against what the card displayed; on a mismatch the action is replaced with a "This changed — review in AI Command Center" state rather than silently acting on stale intent, the same conflict posture `AI_COMMAND_CENTER.md`'s own Approval Center applies to its rows. |
| A KPI tile's headline figure is large enough to threaten the tile's fixed card width — a holding company's Revenue tile at several million KWD, rendered inside the mobile carousel's single-tile-width slide | `KpiTile`'s `numeral-hero`/`display-md`-class figure is allowed to wrap to a second line before it is ever abbreviated; Dashboard does not abbreviate a headline KPI figure to "KD 1.2M" the way a narrow secondary card elsewhere in the platform might, because a dashboard's whole purpose is the exact number, not a rounded impression of it — this is a deliberate exception to the general `RESPONSIVE_DESIGN.md` numeral-overflow guidance, justified by `DESIGN_LANGUAGE.md`'s Principle 3 ("numbers are the hero"). |
| A personalized layout override (`user_dashboard_layouts.layout_overrides`, written via `PATCH /api/v1/users/me/dashboard-layout` from the KPI Strip's "Customize" sheet) still names a KPI tile the user's role permission for was revoked after the override was saved | The KPI Strip renders the override's ordering and hidden/shown choices only for tiles the *current* permission set actually allows; a now-inaccessible tile named in a stale override is skipped silently rather than erroring or re-inserting itself — the override is treated as a preference over an always-current, permission-filtered tile set, never as a promise that a specific tile will exist. |
| A user wants a paper or PDF copy of what Dashboard is showing | Not supported as a literal "print this page" action, for the same reason `AI_COMMAND_CENTER.md` declines it for its own screen: the AI Summary Rail's confidence badges and provenance styling have no meaning on paper, and a screenshot of five independently-cached, independently-timestamped regions is not a defensible single-instant record. Dashboard instead points a user who needs a shareable, printable artifact at the relevant financial statement (`/accounting/financial-statements/profit-and-loss`, `/accounting/trial-balance`) or `REPORTS.md`'s own Export flow, both of which carry a dedicated print/export stylesheet and a real `report_runs`/snapshot record — an artifact with actual provenance, unlike a raw print of the dashboard shell. |
| A Quick Action's create permission (`accounting.journal.create`, `sales.invoice.create`, `purchasing.bill.create`, `bank.reconcile`) is revoked between the page rendering `QuickActionsBar` and the user's click — a stale session snapshot, most commonly a role change made by an admin in another tab | `QuickActionsBar` renders synchronously from `SessionProvider`'s permission snapshot with no network call of its own (per `# Performance`), so the button itself does not disappear mid-session; the destination route's own server-side check is the actual authority, and a user who clicks through to `/accounting/journal-entries/new` (or any other target) with a now-stale permission sees that route's own 403 handling, never a Dashboard-side crash — consistent with `FRONTEND_ARCHITECTURE.md`'s Principle 4, that hiding a control is a courtesy and the server's answer is what actually governs. |
| A very long bilingual company or branch name overflows the Filter Bar's `BranchSelect` trigger at narrower desktop widths (`lg`, 1024px), especially under Arabic where a formal legal company name can run long | The trigger truncates with `truncate` and exposes the full name via the control's own accessible name (`aria-label`/underlying `title`), matching the same pattern `RESPONSIVE_DESIGN.md`'s own long-name edge case specifies for mobile cards, applied here to a desktop-width dropdown trigger; the popover's own option list never truncates, since a user actively choosing a branch needs to read the full name to disambiguate two similarly-prefixed branches. |

# End of Document
