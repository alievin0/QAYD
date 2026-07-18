# Reports Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: REPORTS_SCREEN
---

# Purpose

This document is the concrete, build-ready engineering specification for exactly one route family —
`app/(app)/reports/*`, the Reports module's landing surface and its two attached sub-surfaces — written to
the platform's Screen Doc structure (`# Route & Access` through `# Edge Cases`). It is the implementation-
level companion to `docs/frontend/REPORTS.md`, which already establishes this screen's place in the
platform's information architecture, its reconciliation with `FRONTEND_ARCHITECTURE.md`'s route tree and
`NAVIGATION_SYSTEM.md`'s module map, its full component inventory, its endpoint catalogue, and the narrative
reasoning behind every one of those calls, at a level of detail that already exceeds most of this document
set's other module documents. Where `docs/frontend/REPORTS.md` argues *why* this screen is shaped the way it
is — three registers sharing one design system, one report engine behind every surface, a strict boundary
with the four Financial-Statement screens it does not own — this document goes one further level down: the
exact file tree an engineer opens, the four components that document names but does not fully code, a
concrete Reverb subscription hook, a concrete export-and-download sequence, and one fully worked end-to-end
user journey stitching Generate → Realtime → Canvas → Export → Schedule into a single, traceable path with
real IDs. Every fact `docs/frontend/REPORTS.md` or `docs/accounting/REPORTS.md` already fixed — routes,
permission keys, component names, table schemas, endpoint paths, query keys, cache windows, realtime
channels, AI agent responsibilities — is reused verbatim here, never re-derived and never quietly changed.
Where this document adds detail neither of those two left fully concrete, it is called out inline as
additive, exactly the discipline `docs/frontend/screens/SALES_SCREEN.md` and
`docs/frontend/screens/BANKING_SCREEN.md` already apply to their own module documents.

Concretely, `app/(app)/reports/*` composes three registers that must never blur into one another, each its
own route and its own visual language even though all three share one design system and one underlying
`report_definitions` → `report_runs` pipeline: the **Report Library** (`/reports`), a calm, card-based
discovery surface listing every Standard Report the sixteen-report catalogue seeds plus every Custom Report
a company has authored, across the twelve permissioned categories (financial, operational, inventory,
sales, purchasing, payroll, tax, banking, compliance, audit, executive, custom); the **Report Builder**
(`/reports/new`, `/reports/[definitionId]?mode=edit`), a dense, three-pane, autosaving, no-code authoring
tool; and the **Report Run / view** surface (`/reports/[definitionId]?mode=view`,
`/reports/runs/[runId]`), a print-faithful document viewer generalized from the exact Report Page Template
`docs/frontend/TRIAL_BALANCE.md` already establishes. Four of the sixteen Standard Reports — Balance Sheet,
Income Statement, Cash Flow, and Trial Balance — are functionally owned by their own dedicated screens
(`BALANCE_SHEET.md`, `PROFIT_AND_LOSS.md`, `CASH_FLOW.md`, `TRIAL_BALANCE.md`); this screen still lists,
schedules, exports, shares, and AI-narrates them, but a click on one of those four cards navigates to its
own dedicated route for the actual review/approve workflow rather than opening the generic Builder/canvas
this document specifies. The other twelve Standard Reports and every Custom Report render fully, inline, on
this screen's own routes — there is no second screen waiting to be built for any of them.

The audience is the broadest of any screen in the platform: every role holding at least one
`reports.{category}.read` grant sees a non-empty, correctly-scoped Library — from Owner/CEO/CFO down to a
Warehouse Employee who only holds `reports.inventory.read` for their own warehouse. Building and scheduling
Custom Reports is available to any role holding `reports.custom.create`/`reports.schedule.*` for at least
one category it can already read; sharing externally, dismissing AI-flagged anomalies, and viewing
individual-employee payroll detail are reserved for the narrower tiers `docs/accounting/REPORTS.md`'s own
Role matrix already fixes. Nothing on this screen contains business or financial logic: every number a user
sees was computed and validated by Laravel, every mutation this screen triggers calls
`/api/v1/reports/...` guarded by the exact permission key the API itself enforces, and no calculated-field
formula, subtotal, or `report_runs.status` transition is ever evaluated client-side.

# Route & Access

## App Router path

```
app/(app)/reports/
├── page.tsx                                 # ★ Library — GET /reports/definitions + /reports/favorites
├── loading.tsx                              # Cold first-hit fallback: category rail + card-grid skeleton
├── error.tsx                                # Route-level boundary — see States for the per-region case this rarely reaches
├── new/
│   └── page.tsx                             # ★ Builder, creation entry — <ReportBuilderScreen mode="create" />
├── [definitionId]/
│   ├── page.tsx                              # ★ Builder edit mode (?mode=edit) or Run/view mode (?mode=view, default)
│   └── loading.tsx                           # Three-pane skeleton or Report Page Template skeleton, chosen by resolved mode
└── runs/
    └── [runId]/
        ├── page.tsx                          # ★ A single run's live status, then its rendered result once completed
        └── loading.tsx
```

There is deliberately **no `reports/layout.tsx`**. Unlike Sales or Banking, whose own Hub screens carry a
module-level `Tabs` sub-navigation (Overview · Customers · Quotations · … for Sales; Accounts · Transactions
· Transfers for Banking), Reports has no such module-level tab bar — the Library's own internal
`Tabs` (Library / My Reports / Scheduled, `# Layout & Regions`) already serves that role, and the Builder and
Run/view surfaces are deliberately chrome-light, single-purpose canvases that would only be crowded by a
persistent module tab strip above them. An engineer should not add one; the four files above inherit the
shared `(app)` shell's `Sidebar`/`Topbar` directly.

Every dynamic-segment page ships a `generateMetadata` so the browser tab and any shared-link preview name
the actual report, not a generic "Reports" title:

```tsx
// app/(app)/reports/[definitionId]/page.tsx
export async function generateMetadata(
  { params }: { params: { definitionId: string } },
): Promise<Metadata> {
  const definition = await apiServer
    .get(`/reports/definitions/${params.definitionId}`)
    .catch(() => null);
  return {
    title: definition ? `${definition.data.name_en} — Reports — QAYD` : "Report — QAYD",
  };
}
```

| Property | Value |
|---|---|
| Nav entry | Sidebar position 9 of 10 · **Reports** · icon `BarChart3` · href `/reports` · gate `reports.read` |
| Breadcrumb | `Reports` (root); `Reports / {report name}` on detail/builder; `Reports / {report name} / Run {timestamp}` on a run page |
| Rendering mode | `export const dynamic = "force-dynamic"; export const fetchCache = "default-no-store";` on every file above — tenant-scoped data is never statically cached |
| Deep-linkable state | `/reports?category=sales&q=aging&tab=library\|my-reports\|scheduled`; `/reports/[definitionId]?mode=view\|edit&run_id=&compare_with=&branch_id=…`; `/reports/runs/[runId]` (no query state — the run's own `params` JSONB is authoritative) |

## Permission gate

| Control | Permission | Behavior if absent |
|---|---|---|
| `/reports` visible at all | `reports.read` | Sidebar entry and route absent; a direct hit renders the shell's `403` boundary, never a silent redirect |
| A category's catalogue entries | `reports.{category}.read` | Category section omitted from the Library entirely — discoverability is default-deny, not merely execution |
| Open/run a report | `reports.{category}.read` | Card never rendered in practice; a stale deep link renders the shell `403` |
| "New Report" (Builder) | `reports.custom.create` | Primary action omitted from the Library header |
| Edit a Custom Report (own / any) | `reports.custom.update` / `.update.any` | Builder opens read-only; "Edit" affordance omitted |
| Delete a Custom Report (own / any) | `reports.custom.delete` / `.any` | "Delete" omitted from the card menu |
| Duplicate any report into an editable Custom Report | `reports.custom.create` | "Duplicate" omitted |
| Export (any format) | `reports.export` + the category's own export key where set | Export menu item omitted, never disabled |
| Create/edit/delete a Schedule | `reports.schedule.create` / `.update` / `.delete` | Schedule tab read-only or omitted if `.read` is also absent |
| Share internally or externally | `reports.{category}.share` | Share button omitted |
| Trigger an AI narrative | `ai.reports.narrative` | Action omitted; the automatic Executive Summary still renders on `reports.{category}.read` alone |
| View individual-employee payroll detail | `reports.payroll.employee.read` | Payroll Summary renders aggregate-only; no detail toggle appears |
| Dismiss an AI-flagged anomaly | `reports.audit.read` | Findings render read-only; dismiss control omitted |
| Ask AI (Q&A / draft-from-text) | `reports.{category}.read`, resolved per question | Composer still accepts the question; an out-of-scope question returns an in-band refusal, not a blocked input |

## Roles

| Role | What renders on this screen |
|---|---|
| Owner, CEO, CFO | Full Library across all twelve categories, full Builder, full Run/view, Schedule, internal and external Share, AI narrative, anomaly dismissal |
| Finance Manager | Same as above minus `reports.audit.read`'s dismiss control and payroll employee-level detail |
| Senior Accountant / Accountant | Financial/operational/inventory/purchasing categories read; Custom Report authoring in their own category scope; no external share, no anomaly dismissal |
| Auditor / External Auditor | Read/export/scoped-share only — every mutating control (New Report, Edit, Delete, Schedule create, external Share create) is omitted; External Auditor additionally never sees live generation, only prior scoped share links |
| HR Manager / Payroll Officer | Payroll category with `reports.payroll.employee.read`; all other categories per their own base grants |
| Sales / Purchasing / Inventory Manager or Employee | Their own category's catalogue, own-scope Custom Report authoring, own-scope scheduling; Employee-tier roles are additionally row-scoped where the accounting document's Role matrix specifies (e.g. a Sales Employee's Sales Analysis defaults to their own pipeline) |
| Read Only | Every category renders view-only; every mutating and sharing control is omitted, never disabled |
| AI service account | Never renders this screen as a user; its delegated, per-request read access feeds `# AI Integration` only, and it structurally cannot hold `reports.custom.update.any`, `reports.schedule.create`, or any `.share` key regardless of role configuration |

# Layout & Regions

## Report Library (`/reports`) — Desktop (`lg`+)

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Reports                                                    [+ New Report]  │ PageHeader
│ [Search reports…]                              [Library] [My Reports] [Scheduled] │ Search + Tabs
├───────────┬────────────────────────────────────────────────────────────────┤
│ All        │ Financial ──────────────────────────────────────────────────  │
│ Financial  │ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐         │ Category rail
│ Sales      │ │ Balance Sheet │ │ Trial Balance │ │ Profitability │         │ + card grid,
│ Purchasing │ │ Standard · std│ │ Standard · std│ │ Standard · std│         │ grouped by
│ Inventory  │ │ Last run 2h ago│ │ Last run today│ │ Never run    │         │ category
│ Payroll    │ │        [Run ▾]│ │        [Run ▾]│ │       [Run ▾] │         │
│ Tax        │ └───────────────┘ └───────────────┘ └───────────────┘         │
│ Banking    │ Sales ──────────────────────────────────────────────────────  │
│ Compliance │ ┌───────────────┐ ┌───────────────┐                          │
│ Audit      │ │ Sales Analysis│ │ Customer Aging│                          │
│ Executive  │ │ Standard · std│ │ Standard · std│                          │
│ Custom     │ │        [Run ▾]│ │        [Run ▾]│                          │
└───────────┴────────────────────────────────────────────────────────────────┘
```

## Report Library — Mobile (`base`–`sm`, <768px)

```
┌───────────────────────────┐
│ ≡  Reports           🔔 👤│
├───────────────────────────┤
│ [Library▾] Search…    [+] │  ← Tabs collapse to a chip; New Report as an icon action
├───────────────────────────┤
│ [Categories ▾]            │  ← taps open a Sheet listing all 12 + All + Custom
├───────────────────────────┤
│ Financial                 │
│ ┌───────────────────────┐ │
│ │ Balance Sheet          │ │  ← one card per row, full width
│ │ Standard · Last run 2h │ │
│ │                [Run ▾] │ │
│ └───────────────────────┘ │
│ ┌───────────────────────┐ │
│ │ Trial Balance          │ │
│ │ Standard · Last run tdy│ │
│ │                [Run ▾] │ │
│ └───────────────────────┘ │
├───────────────────────────┤
│ 🏠  📊  🔔  ☰              │  ← bottom tab bar
└───────────────────────────┘
```

| Region | Content | Streaming boundary |
|---|---|---|
| Page Header | `<h1>Reports</h1>`, search box, `Tabs` (Library/My Reports/Scheduled), permission-gated "New Report" | Renders with the page shell |
| Category rail | Twelve categories + All + Custom, `ReportCategoryRail` | Own `<Suspense>` — cheap, count-only aggregate, never N+1 per category |
| Card grid | `ReportCatalogGrid`/`ReportCatalogCard`, grouped by category, sorted `updated_at desc` within each | Own `<Suspense>` — `GET /reports/definitions` |
| My Reports tab | `report_favorites`, re-sorted by `last_used_params` recency | Own `<Suspense>` — `GET /reports/favorites` |
| Scheduled tab | `DataTable` over `report_schedules` | Own `<Suspense>` — `GET /reports/schedules` |

## Report Builder (`/reports/new`, `/reports/[definitionId]?mode=edit`)

```
┌─ New Report — Vendor Concentration Risk (draft · autosaving…) ──────────────┐
│ [Cancel]                                             [Preview]  [Publish]   │ Builder Header
├───────────┬───────────────────────────────────────┬────────────────────────┤
│ FIELDS     │ CANVAS                                 │ CONFIGURATION           │
│ ▸ Vendors  │  Vendor Name │ Total Spend │ Concentr. │ Filters                 │
│  ⠿ vendor_ │  Vendor A     │ 128,400.00  │  34.2%    │  bill_date between      │
│    name    │  Vendor B     │  81,220.00  │  21.7%    │   [this_fiscal_year_start│
│ ▸ Bills    │  drag a field onto CANVAS to add a column ↑ │ .. today]           │
│  [+ Add calculated field]                             │ Group by / Sort / Calc.│
└───────────┴───────────────────────────────────────┴────────────────────────┘
```

| Region | Content | Streaming boundary |
|---|---|---|
| Builder Header | Report name (inline-editable), autosave status text, Cancel/Preview/Publish | Client Component, mounted immediately |
| Fields pane | `ReportFieldPalette` — permitted `report_data_sources`, draggable | Own `<Suspense>` — `GET /reports/data-sources` |
| Canvas pane | `ReportBuilderCanvas` — live table/chart layout, drop target | Client-only, driven by in-memory draft state |
| Configuration pane | `ReportConfigPanel` — Filters/Group by/Sort/Calculated Fields, scoped to Canvas selection | Client-only |

Below `xl`, the three panes collapse into a single-pane step wizard (`# Responsive Behavior`); the Builder's
`Cancel`/`Preview`/`Publish` header remains pinned regardless of tier.

## Report Run / view (`/reports/[definitionId]?mode=view`, `/reports/runs/[runId]`)

```
┌────────────────────────────────────────────────────────────────────────────┐
│ ‹ Reports    Sales Analysis                          [Save as Custom ▾]    │ Report Header
│ Q2 2026 ▾  vs. Q1 2026 ▾ | Branch ▾  Group by: Category, Rep ▾             │
│                                            [↻ Refresh] [Schedule] [Export ▾]│
├────────────────────────────────────────────────────────────────────────────┤
│ ✨ AI Insight — Net revenue rose 8.1% QoQ in Electronics…  [Explain][Ask AI]│ AI Insights strip
├────────────────────────────────────────────────────────────────────────────┤
│ [ Table ] [ Bar ] [ Line ] [ Donut ]                                        │
│ Category    │ Rep              │ Net Revenue │ Margin % │ vs. Prior         │ Report Canvas
│ Electronics │ Fahad Al-Mutairi │  48,250.500 │  34.2%   │ +8.1%             │
├────────────────────────────────────────────────────────────────────────────┤
│ TOTAL                          │ 214,870.750 │  32.4%   │ +5.4%             │
└────────────────────────────────────────────────────────────────────────────┘
```

While `report_runs.status IN ('queued','running')`, the Canvas region is replaced entirely by
`ReportRunStatusCard` (`# Components Used`) rather than a skeleton whose shape is not yet known.

| Region | Content | Streaming boundary |
|---|---|---|
| Report Header | Title, `StatusPill`, `ReportParamsBar`, Refresh/Schedule/Export | Renders with the shell once the definition resolves |
| AI Insights strip | `ReportAiInsightsStrip` — Executive Summary, Findings Bar, Forecast toggle | Own `<Suspense>`, independently failable |
| Report Canvas | `ReportResultTable` or `ReportChartCanvas`, or `ReportRunStatusCard` while generating | Own `<Suspense>` — `GET /reports/runs/{id}` |
| Drill-down Panel | `Sheet` overlay, opened on a `source_refs`-bearing cell click | Not a stacked region — an overlay |
| Ask AI composer | Docked, collapsible at the canvas foot | Lazy-mounted on first expand (`# Performance`) |

# Components Used

The full 26-entry catalogue this route family draws on lives in `docs/frontend/REPORTS.md → Components
Used`; reproduced below is the subset each of the four route files composes directly, plus four components
that document names but does not fully implement — coded out in full immediately after, the same treatment
`docs/frontend/screens/SALES_SCREEN.md` gives its own two new rail components.

| Component | Source | Mounted by |
|---|---|---|
| `PageHeader` | `components/layout/page-header.tsx` | All four routes |
| `Tabs` | `components/ui/tabs.tsx` | Library's Library/My Reports/Scheduled switch; Run/view's Table/Bar/Line/Donut toggle |
| `ReportCategoryRail` | `components/reports/category-rail.tsx` | Library |
| `ReportCatalogGrid` | `components/reports/catalog-grid.tsx` | Library |
| `ReportBuilderScreen` | `components/reports/builder/builder-screen.tsx` | `new/page.tsx`, `[definitionId]/page.tsx` (edit mode) |
| `ReportFieldPalette` / `ReportBuilderCanvas` / `ReportConfigPanel` | `components/reports/builder/*` | Builder |
| `SortableFieldList` | `components/reports/builder/sortable-field-list.tsx` | Builder's Fields/Canvas/Configuration reorder lists |
| `CalculatedFieldEditor` | `components/reports/builder/calculated-field-editor.tsx` | Configuration pane |
| `ReportParamsBar` | `components/reports/params-bar.tsx` | Run/view |
| `ReportResultTable` / `ReportChartCanvas` | `components/reports/result-table.tsx`, `components/charts/report-chart-canvas.tsx` | Run/view canvas |
| `ReportAiInsightsStrip` | `components/reports/ai-insights-strip.tsx` | Run/view |
| `AskAiComposer` | `components/ai/ask-ai-composer.tsx` | Run/view (docked) |
| `StatusPill`, `AmountCell`, `CurrencyTag`, `TrendSparkline` | `components/accounting/*` | Cards, canvas, params bar |
| `AiCardShell` / `ConfidenceBadge` / `ReasoningDisclosure` | `components/ai/*` | Every AI-authored surface, all four routes |
| `Sheet`, `Dialog` / `AlertDialog`, `DropdownMenu`, `Tooltip` | Primitives | Drill-down, Generate/Schedule/Share dialogs, card/row menus |
| `Skeleton`, `EmptyState`, `ErrorState` | Shared | Per-region loading/empty/error, all four routes |
| `DataTable` | `components/shared/data-table.tsx` | Scheduled tab; a report's own Run History sub-tab |
| `Can` | `components/auth/can.tsx` | Every permission-gated affordance in `# Route & Access` |

## `ReportCatalogCard` — fully coded

`docs/frontend/REPORTS.md` names this component's responsibilities in prose but does not code it; this is
the concrete implementation the Library's card grid mounts, one per `report_definitions` row:

```tsx
// components/reports/catalog-card.tsx
'use client';

import Link from 'next/link';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { StatusPill } from '@/components/accounting/status-pill';
import {
  DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { FileBarChart2, Star, MoreVertical } from 'lucide-react';
import { useToggleFavorite } from '@/hooks/reports/use-toggle-favorite';
import { useT } from '@/hooks/use-t';
import { Can } from '@/components/auth/can';
import { relativeTime } from '@/lib/format';
import type { ReportDefinition } from '@/types/reports';

interface ReportCatalogCardProps {
  report: ReportDefinition;
  isFavorite: boolean;
  onRun: (report: ReportDefinition) => void;
}

export function ReportCatalogCard({ report, isFavorite, onRun }: ReportCatalogCardProps) {
  const t = useT();
  const toggleFavorite = useToggleFavorite();
  const name = t.locale === 'ar' && report.name_ar ? report.name_ar : report.name_en;

  return (
    <Card padding="md" className="space-y-3" data-testid={`report-card-${report.code}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          <FileBarChart2 className="h-4 w-4 text-ink-500" aria-hidden />
          <span className="font-medium text-ink-900">{name}</span>
        </div>
        <button
          type="button"
          aria-pressed={isFavorite}
          aria-label={t(isFavorite ? 'reports.unfavorite' : 'reports.favorite', { name })}
          onClick={() => toggleFavorite.mutate({ reportDefinitionId: report.id, isFavorite: !isFavorite })}
          className="h-11 w-11 -m-2 flex items-center justify-center rounded-md hover:bg-ink-50 focus-visible:ring-2"
        >
          <Star className={isFavorite ? 'h-4 w-4 fill-warning-500 text-warning-500' : 'h-4 w-4 text-ink-400'} />
        </button>
      </div>

      <div className="flex items-center gap-2">
        <Badge variant={report.is_system ? 'neutral' : 'accent'}>
          {report.is_system ? t('reports.standard') : t('reports.custom')}
        </Badge>
        {!report.is_system && (
          <span className="text-caption text-ink-500">{report.owner_display_name ?? t('reports.you')}</span>
        )}
        <StatusPill domain="report_definition" value={report.status} />
      </div>

      <p className="text-caption text-ink-500">
        {report.last_run_at
          ? t('reports.lastRun', { time: relativeTime(report.last_run_at) })
          : t('reports.neverRun')}
      </p>

      <div className="flex items-center justify-between gap-2 pt-1">
        <button
          type="button"
          onClick={() => onRun(report)}
          className="h-11 px-4 rounded-md bg-accent-600 text-white text-sm font-medium hover:bg-accent-700 focus-visible:ring-2"
        >
          {t('reports.run')}
        </button>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              aria-label={t('reports.moreActions', { name })}
              className="h-11 w-11 flex items-center justify-center rounded-md hover:bg-ink-50"
            >
              <MoreVertical className="h-4 w-4" aria-hidden />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <Can permission="reports.schedule.create">
              <DropdownMenuItem asChild><Link href={`/reports/${report.id}?tab=schedule`}>{t('reports.schedule')}</Link></DropdownMenuItem>
            </Can>
            <Can permission={`reports.${report.category}.share`}>
              <DropdownMenuItem asChild><Link href={`/reports/${report.id}?action=share`}>{t('reports.share')}</Link></DropdownMenuItem>
            </Can>
            <Can permission="reports.custom.create">
              <DropdownMenuItem asChild><Link href={`/reports/${report.id}?action=duplicate`}>{t('reports.duplicate')}</Link></DropdownMenuItem>
            </Can>
            {!report.is_system && (
              <Can permission={report.is_owner ? 'reports.custom.update' : 'reports.custom.update.any'}>
                <DropdownMenuItem asChild><Link href={`/reports/${report.id}?mode=edit`}>{t('reports.edit')}</Link></DropdownMenuItem>
              </Can>
            )}
            {!report.is_system && (
              <Can permission={report.is_owner ? 'reports.custom.delete' : 'reports.custom.delete.any'}>
                <DropdownMenuItem className="text-danger-600">{t('reports.delete')}</DropdownMenuItem>
              </Can>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </Card>
  );
}
```

## `ReportRunStatusCard` — fully coded

The compact status surface that replaces the Canvas region while `report_runs.status IN ('queued',
'running')`, wired to both the 2-second poll fallback and the Reverb channel `# Data & State` defines:

```tsx
// components/reports/report-run-status-card.tsx
'use client';

import { useSuspenseQuery } from '@tanstack/react-query';
import { reportKeys } from '@/lib/api/query-keys';
import { api } from '@/lib/api/client';
import { useReportRunChannel } from '@/hooks/reports/use-report-run-channel';
import { useCompany } from '@/hooks/use-company';
import { StatusPill } from '@/components/accounting/status-pill';
import { useT } from '@/hooks/use-t';
import { relativeTime } from '@/lib/format';

export function ReportRunStatusCard({ runId }: { runId: number }) {
  const t = useT();
  const { company } = useCompany();
  const { data: run } = useSuspenseQuery({
    queryKey: reportKeys.run(runId),
    queryFn: () => api.get(`/reports/runs/${runId}`),
    refetchInterval: (query) =>
      ['queued', 'running'].includes(query.state.data?.status) ? 2000 : false,
  });

  // Reverb-first; the refetchInterval above is the documented 2s fallback only
  useReportRunChannel(company.id, runId, run.status);

  if (!['queued', 'running'].includes(run.status)) return null; // the Canvas takes over — see Layout & Regions

  return (
    <div role="status" aria-live="polite" className="rounded-lg border border-border-subtle bg-bg-surface p-4 space-y-2">
      <div className="flex items-center gap-2">
        <StatusPill domain="report_run" value={run.status} />
        <span className="text-caption text-ink-500">
          {t('reports.queuedAt', { time: relativeTime(run.queued_at) })}
          {run.triggered_by_user_name ? ` · ${t('reports.triggeredBy', { name: run.triggered_by_user_name })}` : ''}
        </span>
      </div>
      <p className="text-sm text-ink-700">{t('reports.canNavigateAway')}</p>
    </div>
  );
}
```

## `GenerateReportDialog` — fully coded

The Generate-params dialog a card's "Run" action opens, wired directly to the exact
`generateReportParamsSchema` and `useGenerateReport` hook `docs/frontend/REPORTS.md → Data & State` defines
— this component adds no parallel validation or mutation logic of its own:

```tsx
// components/reports/generate-report-dialog.tsx
'use client';

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { generateReportParamsSchema, type GenerateReportParams } from '@/lib/schemas/reports';
import { useGenerateReport } from '@/hooks/reports/use-generate-report';
import { PeriodPicker } from '@/components/accounting/period-picker';
import { Combobox } from '@/components/shared/combobox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { useT } from '@/hooks/use-t';
import type { ReportDefinition } from '@/types/reports';

interface GenerateReportDialogProps {
  report: ReportDefinition;
  open: boolean;
  onOpenChange: (value: boolean) => void;
}

export function GenerateReportDialog({ report, open, onOpenChange }: GenerateReportDialogProps) {
  const t = useT();
  const generate = useGenerateReport(report.id);
  const form = useForm<GenerateReportParams>({
    resolver: zodResolver(generateReportParamsSchema),
    defaultValues: report.default_params,
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader><DialogTitle>{t('reports.generateTitle', { name: report.name_en })}</DialogTitle></DialogHeader>
        <form
          onSubmit={form.handleSubmit((values) =>
            generate.mutate(values, { onSuccess: () => onOpenChange(false) }))}
          className="space-y-4"
        >
          <PeriodPicker
            value={{ start: form.watch('period_start'), end: form.watch('period_end'), asOf: form.watch('as_of_date') }}
            onChange={(v) => {
              form.setValue('period_start', v.start);
              form.setValue('period_end', v.end);
              form.setValue('as_of_date', v.asOf);
            }}
            error={form.formState.errors.period_start?.message}
          />
          <Combobox
            label={t('reports.comparison')}
            value={form.watch('comparison')}
            onChange={(v) => form.setValue('comparison', v)}
            options={[
              { value: 'none', label: t('reports.comparisonNone') },
              { value: 'prior_period', label: t('reports.comparisonPriorPeriod') },
              { value: 'prior_year', label: t('reports.comparisonPriorYear') },
              { value: 'budget', label: t('reports.comparisonBudget') },
            ]}
          />
          <Combobox
            label={t('common.branch')}
            value={form.watch('branch_id')}
            onChange={(v) => form.setValue('branch_id', v)}
            resource="branches"
            clearable
          />
          <DialogFooter>
            <button type="button" onClick={() => onOpenChange(false)} className="h-11 px-4 rounded-md">
              {t('common.cancel')}
            </button>
            <button type="submit" disabled={generate.isPending} className="h-11 px-4 rounded-md bg-accent-600 text-white">
              {generate.isPending ? t('reports.generating') : t('reports.runNow')}
            </button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
```

## `ReportExportMenu` — fully coded

```tsx
// components/reports/report-export-menu.tsx
'use client';

import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useExportReportRun } from '@/hooks/reports/use-export-report-run';
import { Can } from '@/components/auth/can';
import { useT } from '@/hooks/use-t';
import { Download } from 'lucide-react';

export function ReportExportMenu({ runId, category }: { runId: number; category: string }) {
  const t = useT();
  const exportRun = useExportReportRun(runId);

  return (
    <Can permission="reports.export" fallbackPermission={`reports.${category}.export`}>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button type="button" className="h-11 px-3 rounded-md border border-border-subtle inline-flex items-center gap-2">
            <Download className="h-4 w-4" aria-hidden /> {t('reports.export')}
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {(['pdf', 'xlsx', 'csv', 'json'] as const).map((format) => (
            <DropdownMenuItem key={format} disabled={exportRun.isPending} onSelect={() => exportRun.mutate(format)}>
              {t(`reports.exportFormat.${format}`)}
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </Can>
  );
}
```

# Data & State

## Endpoints

The complete 24-endpoint reference is `docs/frontend/REPORTS.md → Data & State → Endpoints consumed`; the
subset this route family calls directly, unabridged, is:

| Method | Path | Permission | Used for |
|---|---|---|---|
| GET | `/reports/definitions` | `reports.{category}.read` | Library grid; category filter; search |
| GET | `/reports/definitions/{id}` | `reports.{category}.read` | Report Detail header; Builder's initial config load |
| POST | `/reports/definitions` | `reports.custom.create` | Builder's first autosave on `/reports/new` |
| PUT | `/reports/definitions/{id}` | `reports.custom.update` / `.update.any` | Every subsequent Builder autosave and Publish |
| DELETE | `/reports/definitions/{id}` | `reports.custom.delete` / `.any` | Delete (Custom Reports only) |
| POST | `/reports/definitions/{id}/duplicate` | `reports.custom.create` | "Duplicate" |
| POST | `/reports/definitions/{id}/generate` | `reports.{category}.read` | Generate-params dialog submit |
| GET | `/reports/runs/{id}` | `reports.{category}.read` | Run status poll fallback; the completed result payload |
| GET | `/reports/runs/{id}/export` | `reports.{category}.export` | Export menu, `?format=pdf\|xlsx\|csv\|json` |
| POST | `/reports/runs/{id}/ai-narrative` | `reports.{category}.read` + `ai.reports.narrative` | "Generate AI narrative" |
| GET / POST / PUT / DELETE | `/reports/schedules[/{id}]` | `reports.schedule.read/create/update/delete` | Scheduled tab; a report's own Schedule sub-panel |
| POST | `/reports/definitions/{id}/share` | `reports.{category}.share` | Share dialog submit |
| GET | `/reports/data-sources` | `reports.custom.create` | Builder's Fields palette |
| POST | `/reports/ask` | `reports.{category}.read`, resolved per question | Ask AI composer Q&A |
| POST | `/reports/draft-from-text` | `reports.custom.create` | Ask AI composer's draft-a-report mode |
| GET / POST | `/reports/favorites` | authenticated | My Reports tab; favorite-star toggle |

## Query keys

Extends the exact `reportKeys` factory `docs/frontend/REPORTS.md` already defines — this document introduces
no parallel factory:

```ts
// lib/api/query-keys.ts (reused verbatim from docs/frontend/REPORTS.md)
export const reportKeys = {
  all: ["reports"] as const,
  definitions: (filters: ReportDefinitionFilters) => [...reportKeys.all, "definitions", filters] as const,
  definition: (id: number) => [...reportKeys.all, "definition", id] as const,
  dataSources: (category?: ReportCategory) => [...reportKeys.all, "data-sources", category] as const,
  favorites: () => [...reportKeys.all, "favorites"] as const,
  schedules: (definitionId?: number) => [...reportKeys.all, "schedules", definitionId ?? "all"] as const,
  run: (runId: number) => [...reportKeys.all, "run", runId] as const,
};
```

## Realtime — concrete subscription hook

`docs/frontend/REPORTS.md → Realtime` fixes the channel name and the events it carries
(`private-company.{id}.report-runs.{run_id}` → `report_run.queued/running/completed/failed`); this is the
concrete Laravel Echo binding `ReportRunStatusCard` and `runs/[runId]/page.tsx` both call:

```tsx
// hooks/reports/use-report-run-channel.ts
'use client';

import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/echo'; // shared Laravel Echo client, FRONTEND_ARCHITECTURE.md → Realtime
import { reportKeys } from '@/lib/api/query-keys';
import type { ReportRunStatus } from '@/types/reports';

export function useReportRunChannel(companyId: number, runId: number, status: ReportRunStatus) {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!['queued', 'running'].includes(status)) return; // subscribe only while the run can still change
    const channelName = `private-company.${companyId}.report-runs.${runId}`;
    const channel = echo.private(channelName);
    const invalidate = () => queryClient.invalidateQueries({ queryKey: reportKeys.run(runId) });

    channel
      .listen('.report_run.running', invalidate)
      .listen('.report_run.completed', invalidate)
      .listen('.report_run.failed', invalidate);

    return () => {
      echo.leave(channelName); // unsubscribe the instant status leaves queued/running — Performance
    };
  }, [companyId, runId, status, queryClient]);
}
```

## Export-and-download — concrete polling hook

`docs/accounting/REPORTS.md → Export` states that an export whose rendering exceeds roughly five seconds
returns a `202 Accepted` with a polling URL, "following the same async pattern as report generation itself,"
without spelling out the exact response shape; this is the concrete contract this screen implements, and the
hook `ReportExportMenu` calls:

```ts
// hooks/reports/use-export-report-run.ts
import { useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api/client';
import { pollUntil } from '@/lib/api/poll-until';
import { toastFromApiError } from '@/hooks/use-api-toast';

type ExportFormat = 'pdf' | 'xlsx' | 'csv' | 'json';

export function useExportReportRun(runId: number) {
  return useMutation({
    mutationFn: async (format: ExportFormat) => {
      const first = await api.get(`/reports/runs/${runId}/export`, { params: { format } });
      if (first.data.status === 'completed') return first.data;
      // 202 — poll the same idempotent GET, carrying the export_id, until it flips to completed
      return pollUntil(
        () => api.get(`/reports/runs/${runId}/export`, { params: { format, export_id: first.data.export_id } }),
        (r) => r.data.status === 'completed',
        { intervalMs: 2000, timeoutMs: 120_000 },
      );
    },
    onSuccess: (data) => {
      const anchor = document.createElement('a');
      anchor.href = data.download_url; // signed, time-limited R2 URL — never proxied through the Next.js server
      anchor.download = data.file_name;
      anchor.click();
    },
    onError: (error) => toastFromApiError(error),
  });
}
```

The `202` shape this hook expects — consistent with `report_snapshots.storage_ref` already being an R2
object key per `docs/accounting/REPORTS.md → Database Design`:

```json
{
  "success": true,
  "data": { "export_id": "exp_7f3a1c9e", "run_id": 918273, "format": "xlsx", "status": "processing" },
  "message": "Export queued",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b2c4d6e8-1234-4abc-9def-0123456789ab",
  "timestamp": "2026-07-18T09:16:05Z"
}
```

and once the poll resolves:

```json
{
  "success": true,
  "data": {
    "export_id": "exp_7f3a1c9e",
    "run_id": 918273,
    "format": "xlsx",
    "status": "completed",
    "file_name": "Sales_Analysis_Q2_2026.xlsx",
    "download_url": "https://cdn.qayd.app/exports/exp_7f3a1c9e.xlsx?sig=…&exp=1752831600",
    "expires_at": "2026-07-18T10:16:05Z"
  },
  "message": "Export ready",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b2c4d6e8-1234-4abc-9def-0123456789ab",
  "timestamp": "2026-07-18T09:16:41Z"
}
```

## AI agents feeding this screen

Reused verbatim from `docs/frontend/REPORTS.md → AI agents feeding this screen`: **Reporting Agent**
(primary — Executive Summary, Narrative Reports, Trend Detection, Question Answering, Natural Language
Reports), **CFO Agent** (Risk Analysis, joint Recommendations), **Forecast Agent** (p10/p50/p90 forecast
overlays), **Fraud Detection** (auto-flagged Anomaly Detection), **Compliance Agent** (regulatory-deadline
flags). All five render through the one `AiCardShell`/`ConfidenceBadge` envelope; none holds write access to
any operational table this screen displays.

# Interactions & Flows

The full interaction catalogue — filtering, the Builder's drag-and-drop mechanics, scheduling, sharing,
versioning — is specified exhaustively in `docs/frontend/REPORTS.md → Interactions & Flows`; this section
does not repeat it. What follows is one fully worked, end-to-end journey threading Generate → Realtime →
Canvas → Export → Schedule into a single traceable path with real IDs, so an engineer can verify their own
implementation against one concrete script rather than only against isolated bullet points.

**Worked journey — Fahad, a Finance Manager, generates Q2 Sales Analysis for the CFO.**

1. Fahad opens `/reports`, the "Sales" category already selected from his last visit's `?category=`
   deep link. The card grid shows `Sales Analysis` (`report_definitions.id = 42`, `code = STD_SALES_ANALYSIS`,
   `is_system = true`), badged `Standard`, captioned "Last run 2h ago."
2. He clicks `[Run ▾]`. `GenerateReportDialog` opens pre-filled from `report_definitions.default_params`; he
   sets `period_start = 2026-04-01`, `period_end = 2026-06-30`, `comparison = prior_period`,
   `group_by = ["product_category", "sales_rep"]`, and submits.
3. `useGenerateReport(42)` fires `POST /reports/definitions/42/generate` with `X-Company-Id: 7`. Because a
   single-quarter Sales Analysis for one company is a light query, the response is a synchronous `200 OK`
   with `run_id: 918273`, `status: "completed"`, 46 rows, and an `ai_summary` at `confidence: 0.81`. The
   dialog closes and the browser navigates to `/reports/42?mode=view&run_id=918273` — no status card is ever
   shown for this run, since it never enters `queued`/`running` from the client's perspective.
4. The Report Canvas renders the five columns the response carried (`product_category`, `sales_rep`,
   `net_revenue` as `AmountCell` in KWD, `gross_margin_pct` and `comparison_variance_pct` as percentages),
   subtotalled by `product_category`, footer `grand_total_net_revenue: 214870.7500`. The Insights strip shows
   the Executive Summary text automatically, no click required, at 81% confidence with a "Q2 2026 rose 8.1%
   quarter-over-quarter in Electronics…" narrative and two clickable `source_refs`.
5. Fahad wants the CFO to have an Excel copy. He opens the Export menu (`ReportExportMenu`) and picks
   `.xlsx`. `useExportReportRun(918273)` calls `GET /reports/runs/918273/export?format=xlsx`; because this
   46-row result renders in well under five seconds, the response returns `status: "completed"` on the first
   call, no polling loop engaged, and the browser immediately downloads `Sales_Analysis_Q2_2026.xlsx` from a
   signed, one-hour-lived R2 URL. Had Fahad instead run this same report unfiltered across a 40-branch
   holding group — the module document's own worked contrast, `run_id: 918274`, returned `202 Accepted` with
   `status: "queued"` — the identical Export menu action would instead enter the polling branch of
   `useExportReportRun` and resolve a few seconds later once the workbook actually finishes rendering
   server-side.
6. Rather than emailing the file manually every quarter, Fahad clicks Schedule. The dialog collects
   `frequency: "quarterly"`, `run_at_time: "08:00"`, `timezone: "Asia/Kuwait"`, `export_format: "xlsx"`,
   `include_ai_summary: true`, and `recipients: [{ type: "user", value: "cfo@company.example" }]`.
   `useCreateSchedule(42)` calls `POST /reports/schedules`; the response's `next_run_at` renders immediately
   in the report's own Schedule sub-panel and the Library's Scheduled tab, so Fahad can confirm the next run
   actually lands before the quarter ends, without waiting for it to fire for real.

**Ask AI — worked example, reused verbatim for consistency.** From the same open report, Fahad expands the
docked composer and asks "why did gross margin drop in March?" `POST /reports/ask` (scoped to
`report_definition_id: 42`) streams back prose citing the specific rows and the vendor price change behind
the compression, exactly the answer `docs/frontend/REPORTS.md → AI Integration` already specifies; had he
instead typed "show me all overdue invoices for customers in the Farwaniya region, grouped by sales rep," the
composer recognizes a drafting request rather than a question and calls `POST /reports/draft-from-text`
instead, landing him on a brand-new `/reports/{id}?mode=edit` draft, fully editable before any Publish.

# AI Integration

Every AI-authored element on this screen renders through the platform's mandatory `AiCardShell` +
`ConfidenceBadge` + `ReasoningDisclosure` envelope — no screen-specific one-off card exists here or anywhere
else in the product.

**Confidence-scale reconciliation — reproduced verbatim because it is the single most important detail to
get right on this screen.** Every confidence value this screen renders — `report_runs.ai_metadata.confidence`,
a Finding's `confidence`, a forecast's interval confidence — sits on the **0.00–1.00** scale
`journal_lines.ai_confidence` and `accounts.ai_suggestion_confidence` also use, **not** the 0–100 scale
`ai_decisions.confidence_score` uses for the AI Command Center. Every value passes through
`normalizeConfidence(raw, "report_runs.ai_metadata")` before reaching `ConfidenceBadge`:

```tsx
// components/reports/ai-insights-strip.tsx (excerpt)
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';

<ConfidenceBadge
  confidence={normalizeConfidence(run.ai_metadata.confidence, 'report_runs.ai_metadata')}
  size="sm"
/>
```

A missing or wrong `sourceField` argument here is unit-tested for this screen specifically, per
`COMPONENT_LIBRARY.md`'s own warning that this mismatch is "the single most likely AI-panel bug across the
whole frontend."

| AI surface | Component | Source | Autonomy | Confidence representation |
|---|---|---|---|---|
| Executive Summary | `ReportAiInsightsStrip` | Auto-attached to `GET /reports/runs/{id}`'s response | Auto-render, suggest-only | 0.00–1.00 |
| Narrative Report | `ReportAiInsightsStrip` (expanded) | `POST /reports/runs/{id}/ai-narrative`, explicit | Suggest-only, gated `ai.reports.narrative` | 0.00–1.00 |
| Forecast band | `ReportChartCanvas` overlay | Bundled in `ai_metadata.forecast` for trend-capable reports | Suggest-only; never auto-populates a budget | p10/p50/p90 range, no single scalar |
| Anomaly / fraud finding | Findings Bar in `ReportAiInsightsStrip` | Bundled in `ai_metadata.anomalies`, pushed live over the run's Reverb channel | **Auto-flag**; dismiss requires `reports.audit.read` + a typed reason | 0.00–1.00 |
| Trend Detection callout | Inline chart annotation marker | Same `ai_metadata` payload | Auto-annotate — a deterministic statistical fact, not a judgment call | n/a (1.5σ threshold, not a confidence score) |
| Ask AI Q&A | `AskAiComposer` | `POST /reports/ask` over SSE | Suggest-only; explicit in-band refusal on out-of-scope questions | 0.00–1.00 per cited claim |
| Draft-from-text | `AskAiComposer` → new Builder draft | `POST /reports/draft-from-text` | Suggest-only; always lands as an editable, unpublished draft | n/a |

**Why "Do it" never appears here.** Reporting has read-only access to every other module's operational
tables; `can_execute_directly` is always server-computed `false` for a Reporting-sourced recommendation, so
every Insights-strip recommendation renders only a `Link`-styled "Open in {module}" deep link plus "Dismiss"
— never a one-click execute, structurally, not by UI convention alone.

**Unavailable AI.** If the Reporting Agent or any collaborating agent is unreachable, disabled, or times out,
the Insights strip renders a single calm `StatusPill` ("AI insights temporarily unavailable"); every
human-driven action on this screen — Generate, Export, Schedule, Share — remains fully available regardless,
exactly matching `docs/frontend/TRIAL_BALANCE.md`'s "AI is additive, never a required gate" rule.

# States

The full state table (twenty rows, including snapshot-cache freshness and permission-revocation mid-session)
is `docs/frontend/REPORTS.md → States`; reproduced here, condensed, plus two states specific to this
document's own concrete additions:

| State | Trigger | Rendering |
|---|---|---|
| Loading — Library first paint | Cold navigation, no hydrated cache | Category-rail + card-grid `Skeleton`, never a bare spinner |
| Loading — Builder first paint | Opening `/reports/new` or `?mode=edit` | Three-pane skeleton; the Fields pane resolves first (a 5-minute-stale reference fetch) |
| Empty — filtered to nothing | Category + search combination matches zero definitions | `EmptyState`, "No reports match your filters," with "Clear filters" |
| Draft, autosaving | Builder open, `status: draft` | Header status cycles `Saving…` → `Saved`, no blocking spinner |
| Generating | `report_runs.status IN ('queued','running')` | `ReportRunStatusCard` in place of the Canvas; Reverb-subscribed, 2s poll fallback |
| Completed — synchronous | `200 OK` from `generate` | Canvas renders immediately, no status card ever shown |
| Completed — asynchronous | `202` then `report_run.completed` | Status card cross-fades into the Canvas in place |
| Failed | `report_runs.status = 'failed'` | `ErrorState` naming `error_code`, "Try again" re-opens Generate pre-filled |
| Served from snapshot cache | Identical `(definition_id, params, as_of)` within the reuse window | "Generated {n} min ago · cached" note + explicit "Force refresh" |
| **Export queued (new)** | `GET /reports/runs/{id}/export` returns `202` | `ReportExportMenu`'s triggering item shows an inline spinner; the menu itself stays open until the poll resolves, never a page-level blocking state |
| **Export ready (new)** | Poll resolves `status: "completed"` | Browser download fires immediately via the signed `download_url`; a `Toast` confirms "Sales_Analysis_Q2_2026.xlsx downloaded" |
| AI unavailable | Reporting Agent unreachable/disabled | Calm `StatusPill` in the Insights strip; every human action stays available |
| Error | `403`/`404`/`5xx`/network failure | `ErrorState` with retry; a `404` (deleted/never existed) is visually distinct from a `403` (exists, not permitted) |

# Responsive Behavior

`docs/frontend/REPORTS.md → Responsive Behavior` states these rules as prose; recast here into the five-tier
table `docs/frontend/screens/BANKING_SCREEN.md` uses, for cross-document scanability:

| Tier | Library | Builder | Run / view |
|---|---|---|---|
| Mobile (<768px) | Category rail behind a "Categories" trigger opening a `Sheet`; `Tabs` become a horizontal-scroll chip row; card grid single column | Three panes collapse to a single-pane step wizard (Data Source → Fields → Filters → Grouping → Sort → Calculated Fields → Preview); autosave fires per step | Table canvas becomes a stack of result cards (priority columns + "View full row"); Insights strip pinned above, never scrolling away |
| Tablet (768–1023px) | Two-column card grid; rail still a `Sheet` trigger | Two panes — Fields collapses into a `Popover` from the Canvas toolbar | A real `<table>` begins to appear, identifier column `sticky start-0` |
| Laptop (1024–1279px) | Full rail + three-column grid | Full three-pane layout | Real `<table>`, `sticky bottom-0` totals row, no horizontal scroll |
| Desktop (1280–1919px) | Same, generous whitespace | Same | Same; the platform's global AI Rail companion panel may dock alongside without competing with this screen's own Insights strip |
| Ultra Wide (≥1920px) | Grid gains a fourth column; content capped at a readable max-width | Panes hold their Laptop proportions rather than stretching arbitrarily | Same, capped width |

**Drag-and-drop stays keyboard-operable at every tier.** `SortableFieldList`'s always-visible up/down
buttons are not a mobile-only or keyboard-only accommodation — they render identically at every viewport and
every input method, per WCAG 2.2's 2.5.7 Dragging Movements. **Charts** wrap Recharts'
`ResponsiveContainer` inside an explicit-height parent at every tier, never a bare percentage height inside a
flex/grid parent. **Virtualization** engages on `ReportResultTable` once `row_count` crosses roughly 200,
using the active density token's row height as the size estimate, identical in mechanism to
`TrialBalanceTable`.

# RTL & Localization

Every rule in `docs/frontend/REPORTS.md → RTL & Localization` applies unchanged — bilingual column labels
sourced from `report_data_sources.fields` down to the Builder's own Fields palette, numeric alignment
physically fixed via `text-right` (never `text-end`) for every money/percentage/count column, charts that
never mirror their time axis, and AI-authored prose generated server-side in the session locale rather than
machine-translated client-side. Reproduced here as the concrete bilingual term pairing an engineer wires
directly into `next-intl`'s message catalogue for this route family:

| Context | English | Arabic |
|---|---|---|
| Page title | Reports | التقارير |
| New Report | New Report | تقرير جديد |
| Run | Run | تشغيل |
| Generate | Generate | إنشاء |
| Export | Export | تصدير |
| Schedule | Schedule | جدولة |
| Share | Share | مشاركة |
| Calculated field | Calculated field | حقل محسوب |
| Group by | Group by | تجميع حسب |
| Generate AI narrative | Generate AI narrative | إنشاء ملخص ذكاء اصطناعي |
| Never run | Never run | لم يُشغَّل بعد |
| Ask AI | Ask AI | اسأل الذكاء الاصطناعي |

Arabic copy is authored directly by a fluent professional-register writer, never machine-translated from the
English column above, per the same discipline `docs/frontend/screens/BANKING_SCREEN.md` applies to its own
term table.

# Dark Mode

Reused without deviation from `docs/frontend/REPORTS.md → Dark Mode`: the Library's card grid sits on
`--color-bg-canvas` with each `ReportCatalogCard` and the Report Header/Canvas one step lighter on
`--color-bg-surface`; category chips and every `StatusPill` tone resolve through the same
`--color-accent`/`--color-warning`/`--color-success`/`--color-danger` tokens `Badge`'s `cva` variants already
define, never a bespoke per-category hue; a generated report's own money figures stay in
`--color-fg-primary` regardless of theme; `ReportChartCanvas` reads resolved CSS custom properties through a
render-time hook and re-renders on theme change, since Recharts' SVG `fill`/`stroke` props cannot be reached
by a wrapping `dark:` class; and every PDF/Excel export always renders through the backend's fixed light
palette regardless of the requesting viewer's in-app theme. `ReportCatalogCard`, `ReportRunStatusCard`, and
`ReportExportMenu` — the three components this document adds — introduce no new token: every color reference
inside each resolves through the same semantic tokens above. Every Storybook story for these three new
components ships the platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`,
`dark/RTL`).

# Accessibility

Baseline WCAG 2.2 AA, reused from `docs/frontend/REPORTS.md → Accessibility` without deviation:
`ReportResultTable` uses real `<table>` semantics with a `<caption>` and `scope`-annotated headers, never a
styled `<div>` grid; every chart ships a "View as table" toggle so no data is ever locked inside an
inaccessible SVG mark; drag-and-drop always ships its non-drag, always-visible alternative; every
`ConfidenceBadge` carries a numeric percentage, a qualitative band, and a `VisuallyHidden` text equivalent,
never color alone; a run transitioning to `completed` announces `role="status" aria-live="polite"`, a
transition to `failed` escalates to `role="alert"`; and every permission-gated control that is disabled
rather than omitted carries `aria-describedby` naming the exact missing permission. Concrete keyboard paths
this screen's own components add:

| Shortcut | Action | Surface |
|---|---|---|
| `/` | Focus the Library search box | Library |
| `Cmd/Ctrl+Enter` | Confirm the focused dialog (Generate, Schedule, Share) | All |
| `Esc` | Close the focused dialog/`Sheet`, returning focus to its trigger | All |
| `↑` / `↓` | Move focus between category rail items (roving tabindex) | Library |
| The visible up/down `IconButton` pair | Reorder the focused field/group/sort row without a drag gesture | Builder |
| `Cmd/Ctrl+S` | Force a visible autosave confirmation (the 3-second timer already guarantees correctness) | Builder |
| `Cmd/Ctrl+K` → "Reports" | Command Palette Navigate-group jump straight to `/reports` | Global |

`GenerateReportDialog`'s `onOpenAutoFocus` moves focus to its heading, not its first field; closing it
(submit, cancel, or `Esc`) returns focus to the card's "Run" button that opened it — the identical
overlay-focus contract `docs/frontend/screens/BANKING_SCREEN.md` documents for its own dialogs.

# Performance

Reused from `docs/frontend/REPORTS.md → Performance`: async generation is never a blocking request; light
queries are backed by materialized views (`mv_gl_monthly_balances`, `mv_sales_daily`,
`mv_inventory_valuation_current`) rather than raw scans; `ReportResultTable` virtualizes past ~200 rows;
snapshot reuse serves an identical `(definition_id, params, as_of)` tuple from cache rather than
recomputing; and Library badges are cache-busted by domain event (`invoice.posted`, `journal_entry.posted`,
`payroll.completed`), not only by TTL. Concrete to this route family's own bundle:

| Dependency | Used by | Loading strategy |
|---|---|---|
| Recharts (`ReportChartCanvas`) | Run/view chart mode, Builder chart preview | `next/dynamic({ ssr: false })`, loaded only once `output_format` ever resolves to `chart` |
| `dnd-kit` (`SortableFieldList`) | Builder only | `next/dynamic`; never downloaded by a role that never opens the Builder |
| SSE chat client (`AskAiComposer`) | Docked composer on Run/view | `next/dynamic`, loaded on first expand, not on page load |
| PDF/Excel rendering (headless Chromium, PhpSpreadsheet) | Server-side only | Never shipped to the browser — export is always a server round trip, polled per `# Data & State` |

LCP is measured against the Library's card-grid paint (or the Run/view Canvas paint, whichever route is
current) — the screen's actual meaningful content, never the shell — and INP is watched specifically on the
"Run" button and the Export menu's format items, the two highest-frequency interactive controls on this
screen.

# Edge Cases

The full seventeen-item list is `docs/frontend/REPORTS.md → Edge Cases`; condensed here into the tabular
format `docs/frontend/screens/BANKING_SCREEN.md` uses, plus three cases specific to this route family's own
concrete additions.

| Edge case | Behavior |
|---|---|
| Concurrent double-click on "Run" | Disables immediately on click; one `Idempotency-Key` per attempt; a genuine race's `409` renders a friendly "Already generating" toast |
| Permission revoked mid-Builder-session | Next autosave's `403` collapses the Builder to read-only with a `Toast`; Publish is blocked |
| A joined data source is permission-narrowed after publishing | Report keeps running for viewers who still hold that source's permission; a viewer who lost it sees a named banner, never a silent partial result |
| Scheduled recipient loses read permission | Delivery is silently skipped for that recipient only, logged in the report's own Schedule sub-panel |
| Calculated field references a removed/renamed field | Reopening the Builder surfaces a broken-reference warning and blocks Publish; the previously published version is unaffected |
| Company switch mid-Builder-edit | Cached Reports state is discarded per the platform's company-switch contract; the confirmation dialog names the in-progress edit |
| Huge, unscoped ad hoc Custom Report | Preview and Generate both surface a persistent, dismissible scoping nudge rather than silently running an unbounded scan |
| Expired/revoked/max-views external share link | `GET /reports/shared/{token}` renders a calm "This link is no longer available" — never a `404`, never revealing whether the token ever existed |
| Snapshot payload aged out of retention, run metadata survives | Run History still lists who/when/params; opening it explains the retention policy rather than showing a broken canvas |
| Payroll Summary viewed by a lower-privileged role than the generator | Canvas/exports degrade to aggregate-only for that viewer, computed from the same frozen snapshot, never regenerated |
| Ask AI question spans permitted and unpermitted categories | Agent answers the in-scope part and names precisely what it declines and why |
| Draft-from-text over an inaccessible data source | Server refuses to produce the draft at all — never a populated-but-unusable draft |
| **A `/reports/runs/[runId]` link opened under a different company than the run belongs to (new)** | The `X-Company-Id` mismatch resolves to a `404`, not a `403` — a different company's run is treated as nonexistent, never disclosed as "exists but forbidden," per the same enumeration-discipline rule already applied to an expired external share token |
| **`/reports/new` opened by a company with zero `report_data_sources` exposed to the caller's permissions (new)** | The Builder's first step renders an `EmptyState` ("No data sources available for your permissions") instead of a three-panes-deep empty Fields palette |
| **The browser's native Back button pressed mid-way through the Mobile Builder's step wizard (new)** | The wizard's own Back/Next buttons move between steps via component state, not routing; the native Back button instead exits the Builder to the previous route entirely. Because the 3-second autosave already bounds any loss to three seconds regardless of exit path, this is an accepted tradeoff rather than a defect requiring per-step URL encoding |

# End of Document
