# Reports — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: REPORTS
---

# Purpose

The Reports screen is QAYD's single, general-purpose reporting surface — the frontend rendering of the
"one engine, many surfaces" principle `docs/accounting/REPORTS.md` establishes for the entire Reporting &
Analytics module. It is three logically distinct sub-surfaces sharing one route family and one data model:
a **Report Library** (`/reports`) for discovering and running any of the sixteen seeded Standard Reports or
a company's own Custom Reports across twelve categories; a **Report Builder** (`/reports/new`,
`/reports/[definitionId]` in edit mode) — a no-code, drag-and-drop composition surface over fields, filters,
grouping, sorting, and calculated fields; and a **Report Run** view (`/reports/[definitionId]` in run mode,
`/reports/runs/[runId]`) that generates a report — synchronously for light queries, asynchronously via a
polled/streamed `report_runs` job for heavy ones — and renders its result as a print-faithful table or
chart, exportable to PDF/Excel/CSV/JSON. Layered on top of all three is the Reporting Agent's two-way
natural-language surface: it can narrate any generated report, answer ad hoc questions against the same
governed data a Custom Report would query, and draft an entirely new report definition from a plain-English
request. This document is the binding frontend specification for all of it, consuming the shared
conventions already fixed by `FRONTEND_ARCHITECTURE.md`, `COMPONENT_LIBRARY.md`, `DESIGN_LANGUAGE.md`,
`NAVIGATION_SYSTEM.md`, `LAYOUT_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, `ACCESSIBILITY.md`,
`THEMING.md`, and `ICONOGRAPHY.md`, and the business logic already fixed by `docs/accounting/REPORTS.md`.

**Boundary with Dashboard, the AI Command Center, and the four dedicated Financial Statement screens.**
Four routing/ownership facts, stated once so no other section has to re-derive them. First, Dashboard
(`DASHBOARD.md`) and the AI Command Center (`AI_COMMAND_CENTER.md`) are both, structurally, thin
role-scoped **consumers** of this module's same `report_definitions` → `report_runs` pipeline — a KPI tile
is "a report definition with `output_format = 'widget'` and a small, cached, frequently-refreshed query"
per `docs/accounting/REPORTS.md → Dashboard Architecture` — but neither is this screen: Dashboard is the
numbers-first home page every role lands on regardless of permission, the AI Command Center is the
autonomous workforce's own home page, and this document is where a user goes specifically to browse the
full report catalogue, build something new, or wait on a heavy generation job. Second, four of the sixteen
Standard Reports — Balance Sheet, Income Statement, Cash Flow, and Trial Balance — are, in the accounting
document's own words, "owned functionally" by their own dedicated screens (`BALANCE_SHEET.md`,
`PROFIT_AND_LOSS.md`, `CASH_FLOW.md`, `TRIAL_BALANCE.md`) and "exposed here as a Standard Report for
scheduling, sharing, export, and AI narrative purposes." This screen still lists all four Library cards,
still lets a user schedule/export/share them or request an AI narrative from here, and `/reports/[id]`
still resolves for one of their `id`s — but clicking one of those four specific cards navigates to its own
dedicated route for the actual generate/review/approve workflow, exactly mirroring `DASHBOARD.md`'s own
drill-through rule for its KPI tiles, rather than opening the generic Builder/canvas this document
specifies. The other twelve Standard Reports (General Ledger, Inventory Valuation, Customer/Vendor Aging,
Sales/Purchase Analysis, Payroll Summary, Tax Reports, Cash Position, Bank Reconciliation, Profitability,
KPIs) and every Custom Report render inline, in full, on this screen — there is no second dedicated screen
waiting to be built for any of them. Third, this document is also the frontend owner of the Report
Builder itself, which no other document specifies. Fourth, this screen composes **three** of
`LAYOUT_SYSTEM.md`'s five canonical Page Templates in one route family — the Library is a **List Page
Template** variant (cards instead of table rows), the Builder is a **Form Page Template** variant (three
sectioned panes instead of stacked form sections), and a generated report's canvas is a direct instance of
the **Report Page Template** `TRIAL_BALANCE.md` already uses — a fact worth stating plainly because it is
the single most distinguishing architectural property of this screen relative to every other document in
this set, each of which is one template, not three.

Three properties shape every section below. First, **this screen has no primary data of its own** —
identically to Trial Balance, every figure a generated report shows is a `report_runs`/`report_snapshots`
row computed once by the backend and frozen at generation time, and the Builder's entire client-side
surface is limited to assembling a `report_definitions.config` JSON payload; the frontend never evaluates a
calculated-field formula, never sums a column client-side for anything that will be persisted, and never
computes a `report_runs.status` transition itself. Second, **this screen is simultaneously three different
registers that must never blur into one another**: a browsing/discovery surface (calm, card-based, fast),
a precise no-code authoring tool (dense, three-pane, autosaving), and a print-faithful document viewer
(the exact register `TRIAL_BALANCE.md` already established) — a user should never be confused about which
of the three they are in, and the visual language of each is deliberately distinct even though all three
share one design system. Third, **this is the deepest natural-language surface in the product outside the
dedicated Ask AI page** — the Reporting Agent's Question Answering and Natural-Language-Report-drafting
responsibilities (`docs/accounting/REPORTS.md → AI Responsibilities`) are documented nowhere else at this
level of detail, and this document is their frontend home.

The audience is, by design, the broadest of any screen in the platform: every role holding at least one
`reports.{category}.read` grant sees a non-empty, correctly-scoped Library — Owner, CEO, CFO, Finance
Manager, Senior Accountant, Accountant, Auditor, HR Manager, Payroll Officer, Inventory Manager, Warehouse
Employee, Sales Manager, Sales Employee, Purchasing Manager, Purchasing Employee, Read Only, and External
Auditor (the last two view/export/scoped-share only, per the accounting document's role matrix). Building
and scheduling Custom Reports is available to any role holding `reports.custom.create`/`reports.schedule.*`
for at least one category it can already read; sharing externally and dismissing AI-flagged anomalies are
reserved for the narrower Finance/Audit tier exactly as the accounting document's Permissions section
states.

# Route & Access

**Routing reconciliation.** Three segments are already fixed by sibling documents and this document reuses
all three verbatim: `FRONTEND_ARCHITECTURE.md → Route tree` and `NAVIGATION_SYSTEM.md → Sub-navigation per
module` both name `/reports` (the Library), `/reports/[definitionId]` (a single report's detail/view), and
`/reports/runs/[runId]` (a single generation's live status, streamed over
`private-company.{id}.report-runs.{run_id}`) — nothing below renames or relocates any of the three. This
document adds one segment neither of those files anticipated: `/reports/new`, the Builder's creation entry
point, following the identical `new` + `[id]` pairing `FRONTEND_ARCHITECTURE.md`'s own route tree already
uses for Journal Entries (`journal-entries/new` / `journal-entries/[entryId]`) and Bank Transfers
(`transfers/new` / implicit `[id]`) — `/reports/new` renders the same `ReportBuilderScreen` component
`/reports/[definitionId]?mode=edit` renders for an existing draft, differing only in that its first autosave
is a `POST /reports/definitions` rather than a `PUT /reports/definitions/{id}`, exactly mirroring
`TRIAL_BALANCE.md`'s own observation that a specific historical version is "also expressed as a query
parameter on the same route, never a separate path."

| Property | Value |
|---|---|
| App Router paths | `app/(app)/reports/page.tsx` (Library) · `app/(app)/reports/new/page.tsx` (Builder, new) · `app/(app)/reports/[definitionId]/page.tsx` (Builder edit mode, or run/view mode for a published report) · `app/(app)/reports/runs/[runId]/page.tsx` (a single run's live status and, once completed, its rendered result) |
| Route group / shell | `(app)`, sibling to `accounting/`, `financial-statements/`, `ai/` under the shared `(app)` shell (`FRONTEND_ARCHITECTURE.md`) |
| Nav entry | Sidebar position 9 of 10 · **Reports** · icon `BarChart3` · href `/reports` · gate `reports.read` (`NAVIGATION_SYSTEM.md → Primary Navigation`) |
| Sub-navigation | Report Library — `/reports` — `reports.read`; Report Detail — `/reports/[definitionId]` — `reports.read`, `reports.export` to export; Scheduled Runs — `/reports/runs/[runId]` — `reports.read` (`NAVIGATION_SYSTEM.md → Sub-navigation per module`) |
| Breadcrumb | `Reports` (root); `Reports / {report name}` on detail/builder; `Reports / {report name} / Run {timestamp}` on a run page |
| Rendering mode | `export const dynamic = "force-dynamic"; export const fetchCache = "default-no-store";` — tenant-scoped, never statically cached, per `FRONTEND_ARCHITECTURE.md → Tenant-scoped data is never statically cached` |
| Deep-linkable state | `/reports?category=sales&q=aging&tab=library\|my-reports\|scheduled`; `/reports/[definitionId]?mode=view\|edit&run_id=&compare_with=&branch_id=&…` (the report's own resolved parameters); `/reports/runs/[runId]` takes no query state — the run's own `params` JSONB is the sole source of truth for what it was generated with |

**Permission reconciliation.** `NAVIGATION_SYSTEM.md`'s module map gates the Sidebar's Reports item —
and therefore the ability to open `/reports` at all — behind the single coarse `reports.read`, the same
baseline `DASHBOARD.md`'s AI Summary Rail and every `AI_COMMAND_CENTER.md` panel already check. This
document does not loosen or replace that gate; it is the parent permission every finer check below composes
with, exactly as `DASHBOARD.md`'s own "`reports.read` (baseline) + each individual KPI's own domain
permission, silently omitted rather than denied" pattern already establishes for its KPI Strip. Underneath
that baseline, `docs/accounting/REPORTS.md → Permissions` is the authoritative source for everything this
screen actually reads and does: `reports.{category}.read` (twelve keys — financial, operational, inventory,
sales, purchasing, payroll, tax, banking, compliance, audit, executive, custom) gates which catalogue
entries and which report bodies a given viewer can see at all, and discoverability itself is default-deny —
a Sales Employee lacking `reports.payroll.read` never sees a "Payroll Summary" card, disabled or otherwise,
in the Library; `reports.custom.create/.update/.update.any/.delete` gate the Builder's write path;
`reports.schedule.create/.read/.update/.delete` gate the Schedule tab; `reports.{category}.share` gates
internal/external sharing; `ai.reports.narrative` gates the explicit "Generate AI narrative" action,
distinct from the Executive Summary that renders automatically on generation; and
`reports.payroll.employee.read`/`reports.audit.read` gate the two PII- and audit-sensitive behaviors named
in `# AI Integration` and `# Edge Cases`. The flat `reports.export` `NAVIGATION_SYSTEM.md`'s Report Detail
row already names composes with, rather than replaces, the category-level export key — `reports.financial
.export` is the only per-category export key the accounting document's table spells out explicitly, since
the other eleven categories ride on their own `.read` grant for export ability unless a company's
Permission Studio grants `reports.export` more broadly — so the Export button's `Can` guard checks both, and
a viewer holding `reports.{category}.read` but neither export key sees a fully rendered report with the
Export menu item omitted, never disabled, per the same "omission over disablement for existence-sensitive
actions" rule `TRIAL_BALANCE.md → Accessibility` already documents for its own Export menu.

| Control | Permission | Rendered when absent |
|---|---|---|
| Open `/reports` at all | `reports.read` | Nav item hidden from Sidebar/Command Palette; direct navigation renders the shell's `403` boundary |
| See a category's catalogue entries | `reports.{category}.read` | Category section omitted from the Library entirely — discoverability is default-deny, not merely execution |
| Open a report and generate/run it | `reports.{category}.read` | In practice the card is never rendered; a stale deep link renders the shell `403` |
| Create a new Custom Report (Builder) | `reports.custom.create` | "New Report" primary action omitted from the Library header |
| Edit a Custom Report you own | `reports.custom.update` | Builder opens in read-only view mode; "Edit" affordance omitted |
| Edit any Custom Report | `reports.custom.update.any` | Same, for a report owned by another user |
| Delete a Custom Report you own | `reports.custom.delete` | "Delete" option omitted from the card/row `DropdownMenu` |
| Duplicate any report (incl. a Standard Report) into an editable Custom Report | `reports.custom.create` | "Duplicate" option omitted |
| Export (any format) | `reports.export` (+ the category's own export key, where a company's role config sets one) | Export menu item omitted, never disabled |
| Create/edit/delete a Schedule | `reports.schedule.create` / `.update` / `.delete` | Schedule tab renders read-only, or is omitted entirely if `.read` is also absent |
| View Scheduled Runs | `reports.schedule.read` | Schedule tab omitted from the report's tab bar |
| Share internally or via external link | `reports.{category}.share` | Share button omitted |
| Trigger an AI narrative | `ai.reports.narrative` | "Generate narrative" action omitted; the automatic Executive Summary still renders, gated only by `reports.{category}.read` |
| View individual-employee payroll detail | `reports.payroll.employee.read` | Payroll Summary renders in aggregate-only mode; no "Show employee detail" toggle appears |
| Dismiss an AI-flagged anomaly | `reports.audit.read` | Findings render read-only; the dismiss control is omitted |
| Ask AI (natural-language Q&A / draft-from-text) | `reports.{category}.read`, resolved per question | The composer still accepts the question; an out-of-scope question returns an explicit in-band refusal rather than being blocked at the input itself |

Role grants, condensed to the platform's default role vocabulary exactly as `TRIAL_BALANCE.md` condenses
its own table (Owner/CEO/CFO collapse to the backend's "Owner"/"Admin" tier; Senior Accountant collapses to
"Accountant"; Read Only/External Auditor collapse to a read-plus-scoped-share-only guest):

| Permission | Owner / CEO / CFO | Finance Manager | Sr. Accountant / Accountant | Auditor / External Auditor | Sales / Purchasing / Inventory / Payroll / HR roles | Read Only |
|---|---|---|---|---|---|---|
| `reports.read` (baseline) | Yes | Yes | Yes | Yes | Yes (module-scoped) | Yes |
| `reports.financial.read` | Yes | Yes | Yes | Yes | No | view-only |
| Own-module `reports.*.read` | Yes | Yes | — | Yes | Yes | view-only |
| `reports.custom.create` | Yes | Yes | Yes | No | Yes (own category scope) | No |
| `reports.custom.update.any` | Yes | Yes | No | No | No | No |
| `reports.schedule.create` | Yes | Yes | Yes | No | Module-scoped | No |
| `reports.{category}.share` (external link) | Yes | Yes | No | No (scoped share only) | No | No |
| `ai.reports.narrative` | Yes | Yes | Yes | Yes (view only) | Module-scoped | No |
| `reports.audit.read` | Yes | No | No | Yes | No | No |
| `reports.payroll.employee.read` | Yes | No | No | No | HR Manager / Payroll Officer only | No |

# Layout & Regions

Each of the three sub-surfaces is its own region composition; none reuses another's chrome wholesale, per
the second defining property in `# Purpose`.

**Report Library (`/reports`)** — a List Page Template variant: a category rail (the twelve
`report_category` values plus "All" and "Custom") replaces the Filter Bar's usual field-filter popovers,
because the primary navigational axis here is category, not a column filter; a `Tabs` row alongside it
switches between **Library** (the full catalogue), **My Reports** (`report_favorites`), and **Scheduled**
(a `DataTable` over `report_schedules`); and the Data Region is a responsive card grid
(`ReportCatalogGrid`/`ReportCatalogCard`) rather than a table, because a report is a thing to be recognized
and launched, not a row to be scanned across many columns.

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Reports                                                    [+ New Report]  │ PageHeader
│ [Search reports…]                              [Library] [My Reports] [Scheduled] │ Search + Tabs
├───────────┬────────────────────────────────────────────────────────────────┤
│ All        │ Financial ──────────────────────────────────────────────────  │
│ Financial  │ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐         │ Category rail
│ Sales      │ │ Balance Sheet │ │ Trial Balance │ │ Profitability │         │ (→ Sheet on
│ Purchasing │ │ Standard · std│ │ Standard · std│ │ Standard · std│         │  mobile) +
│ Inventory  │ │ Last run 2h ago│ │ Last run today│ │ Never run    │         │  card grid,
│ Payroll    │ │        [Run ▾]│ │        [Run ▾]│ │       [Run ▾] │         │  grouped by
│ Tax        │ └───────────────┘ └───────────────┘ └───────────────┘         │  category
│ Banking    │ Sales ──────────────────────────────────────────────────────  │
│ Compliance │ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐         │
│ Audit      │ │ Sales Analysis│ │ ★Vendor       │ │ Customer Aging│         │
│ Executive  │ │ Standard · std│ │  Concentration│ │ Standard · std│         │
│ Custom     │ │        [Run ▾]│ │ Custom · you  │ │        [Run ▾]│         │
│            │ └───────────────┘ │ Draft [Edit]  │ └───────────────┘         │
│            │                   └───────────────┘                          │
└───────────┴────────────────────────────────────────────────────────────────┘
```

Each `ReportCatalogCard` shows: category icon (`FileBarChart2` per `ICONOGRAPHY.md → Reports`), bilingual
name, a `Standard`/`Custom` `Badge`, an owner chip for Custom Reports ("you" or the owner's name), a
`StatusPill` for `draft`/`active`/`archived`, a relative "Last run" timestamp or "Never run" (sourced from
the Redis-cached rollup described in `# Performance`, never an N+1 per-card query), a favorite-star toggle,
and a primary "Run" split-button whose caret opens Schedule/Share/Duplicate/Edit/Delete — exactly the same
compact-card-plus-overflow-menu shape `COMPONENT_LIBRARY.md`'s composition patterns already use elsewhere,
applied to a report rather than a record.

**Report Builder (`/reports/new`, `/reports/[definitionId]?mode=edit`)** — a Form Page Template variant:
three panes replace the template's usual single sectioned form body, per the Drag & Drop mechanics
`docs/accounting/REPORTS.md → Report Builder` specifies.

```
┌─ New Report — Vendor Concentration Risk (draft · autosaving…) ──────────────┐
│ [Cancel]                                             [Preview]  [Publish]   │ Builder Header
├───────────┬───────────────────────────────────────┬────────────────────────┤
│ FIELDS     │ CANVAS                                 │ CONFIGURATION           │
│ ▸ Vendors  │  Vendor Name │ Total Spend │ Concentr. │ Filters                 │
│  ⠿ vendor_ │  Vendor A     │ 128,400.00  │  34.2%    │  bill_date between      │
│    name    │  Vendor B     │  81,220.00  │  21.7%    │  [this_fiscal_year_start│
│  ⠿ payment │  Vendor C     │  62,010.00  │  16.6%    │   .. today]             │
│    terms   │  …            │  …          │  …        │ ─────────────────────  │ Fields /
│ ▸ Bills    │                                         │ Group by                │ Canvas /
│  ⠿ bill_   │  drag a field from FIELDS onto           │  ⠿ vendor_id            │ Configuration
│    date    │  CANVAS to add a column ↑                │ ─────────────────────  │
│  ⠿ total_  │                                         │ Sort                     │
│    spend   │                                         │  total_spend ↓ desc     │
│ ▸ Calculated                                          │ ─────────────────────  │
│  [+ Add calculated field]                             │ Calculated fields        │
│            │                                         │  concentration_pct  [ƒ]  │
└───────────┴───────────────────────────────────────┴────────────────────────┘
```

**Fields** groups the permitted `report_data_sources` for the report's chosen category (draggable, one
group per joinable source); **Canvas** is the live layout — a table by default, switchable to any chart
type — that a field becomes a column of the moment it is dropped; **Configuration** is a stacked set of
collapsible sections (Filters, Group by, Sort, Calculated Fields) that always reflects whatever is currently
selected on the Canvas, exactly as the accounting document's own three-pane description states. Below
`xl`, the three panes collapse into a single-pane step wizard (`# Responsive Behavior`).

**Report Run / view (`/reports/[definitionId]?mode=view`, `/reports/runs/[runId]`)** — a direct instance of
`LAYOUT_SYSTEM.md`'s **Report Page Template**, identical in region shape to `TRIAL_BALANCE.md`'s own canvas
but generalized to an arbitrary column set and an output-format toggle:

```
┌────────────────────────────────────────────────────────────────────────────┐
│ ‹ Reports    Sales Analysis                          [Save as Custom ▾]    │ Report Header
│ Q2 2026 ▾  vs. Q1 2026 ▾ | Branch ▾  Group by: Category, Rep ▾             │ (params + actions)
│                                            [↻ Refresh] [Schedule] [Export ▾]│
├────────────────────────────────────────────────────────────────────────────┤
│ ✨ AI Insight — Net revenue rose 8.1% QoQ in Electronics…  [Explain][Ask AI]│ AI Insights strip
├────────────────────────────────────────────────────────────────────────────┤
│ [ Table ] [ Bar ] [ Line ] [ Donut ]                                        │ output-format toggle
│ Category    │ Rep              │ Net Revenue │ Margin % │ vs. Prior         │
│ Electronics │ Fahad Al-Mutairi │  48,250.500 │  34.2%   │ +8.1%             │ Report Canvas
│ Electronics │ Dana Al-Ajmi     │  31,120.000 │  29.9%   │ -2.3%             │
│ …           │ …                │  …          │  …       │ …                 │
├────────────────────────────────────────────────────────────────────────────┤
│ TOTAL                          │ 214,870.750 │  32.4%   │ +5.4%             │
└────────────────────────────────────────────────────────────────────────────┘
```

While a run is `queued`/`running` — whether reached via `/reports/[definitionId]` immediately after
clicking "Run," or via a direct `/reports/runs/[runId]` link from a scheduled-email or notification — the
canvas region is replaced by a compact, unmissable status card rather than a skeleton of a table whose shape
isn't known yet for a brand-new ad hoc report:

```
┌─ Report run — Sales Analysis ─────────────────────────────────────────────┐
│ Queued at 09:16 · triggered by Khalid Marafie                             │
│ ● Running… (est. 40s remaining)                          [Cancel run]     │
│ You can navigate away — we'll notify you when it's ready.                 │
└────────────────────────────────────────────────────────────────────────────┘
```

**Drill-down Panel** (an overlay on the Run/view surface, not a stacked region) — a `Sheet` sliding from the
logical end edge, opened by clicking any result cell whose column carries a `source_refs`-bearing key
(`journal_line_id`, `invoice_id`, `bill_id`, …), identical in mechanics to `TRIAL_BALANCE.md`'s own
Drill-down Panel, generalized to whatever entity the clicked cell's data source actually names rather than
always a `journal_lines` row.

**Ask AI composer** (a persistent, collapsible dock at the foot of the Run/view canvas, never a separate
region competing with the report's own content) — collapsed to a single input by default; expanding it
reveals the streaming conversation described in `# AI Integration`.

# Components Used

Every visual element on this screen is a primitive from `COMPONENT_LIBRARY.md`, a named finance component
already catalogued there, or a screen-specific composition of both living in `components/reports/*`, per
the same convention `components/accounting/trial-balance/*` already establishes.

| Component | Source | Role on this screen |
|---|---|---|
| `PageHeader` | `components/layout/page-header.tsx` | Library title + "New Report"; Builder title + Cancel/Preview/Publish; Run/view title + `StatusPill` + action slot |
| `Tabs` (shadcn/Radix) | `components/ui/tabs.tsx` | Library / My Reports / Scheduled switch; the generated report's Table/Bar/Line/Donut output-format toggle |
| `ReportCategoryRail` | `components/reports/category-rail.tsx` (screen-specific) | Library's category navigation; collapses into a `Sheet` on mobile |
| `ReportCatalogGrid` / `ReportCatalogCard` | `components/reports/catalog-card.tsx` (screen-specific) | Library's responsive card grid |
| `Combobox` (built on `Command`+`Popover`) | `components/shared/combobox.tsx` | Data-source pickers in the Builder's Fields pane; Branch/Department/Project/Cost Center filters in the params bar |
| `PeriodPicker` | `components/accounting/period-picker.tsx` (named finance component) | The Run/view params bar's period, as-of-date, and comparison-period selection |
| `ReportBuilderScreen` | `components/reports/builder/builder-screen.tsx` (screen-specific) | Orchestrates Fields/Canvas/Configuration, the 3-second autosave, and Publish |
| `ReportFieldPalette` | `components/reports/builder/field-palette.tsx` (screen-specific) | Fields pane — draggable field list grouped by joinable `report_data_sources` |
| `ReportBuilderCanvas` | `components/reports/builder/canvas.tsx` (screen-specific) | Canvas pane — live column/chart layout and drop target |
| `ReportConfigPanel` | `components/reports/builder/config-panel.tsx` (screen-specific) | Configuration pane — Filters / Group by / Sort / Calculated Fields, scoped to whatever is selected on the Canvas |
| `CalculatedFieldEditor` | `components/reports/builder/calculated-field-editor.tsx` (screen-specific) | Formula editor over the whitelisted function set (arithmetic, `SUM`/`AVG`/`COUNT`/`MIN`/`MAX`/`COUNT_DISTINCT`, `IF`, date/string functions) |
| `SortableFieldList` | `components/reports/builder/sortable-field-list.tsx` (screen-specific, wraps `dnd-kit`) | Drag-reorder for columns, group-by order, and sort precedence, with a keyboard "Move up / Move down" fallback |
| `ReportParamsBar` | `components/reports/params-bar.tsx` (screen-specific) | Run/view surface's period + comparison + dimension-filter row |
| `ReportResultTable` | `components/reports/result-table.tsx` (screen-specific) | The generated report's table canvas — grouped, subtotalled, sticky; bespoke for the same reason `TrialBalanceTable` is (see note below) |
| `ReportChartCanvas` | `components/charts/report-chart-canvas.tsx` (thin Recharts wrapper per `components/charts/` conventions, `FRONTEND_ARCHITECTURE.md → Folder Structure`) | Bar / Line / Donut output-format rendering |
| `KpiTile` | `components/accounting/kpi-tile.tsx` (named finance component) | Widget-shaped preview when a `output_format = 'widget'` dashboard-category definition is opened directly |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every money cell in `ReportResultTable` |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` (named finance component) | Multi-currency breakdown chip on a row with amounts beyond the base currency |
| `TrendSparkline` | `components/accounting/trend-sparkline.tsx` (named finance component) | Trend Analysis calculated-field preview inline in a column header; KPI Scorecard rows |
| `StatusPill` | `components/accounting/status-pill.tsx` | `report_runs.status` (queued/running/completed/failed/cancelled); `report_definitions.status` (draft/active/archived) |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` (named finance component) | Expanded view of a finding, anomaly, or risk record |
| `AiCardShell` / `ConfidenceBadge` / `ReasoningDisclosure` | `components/ai/ai-card-shell.tsx`, `components/ai/confidence-badge.tsx`, `components/ai/reasoning-disclosure.tsx` | The mandatory envelope for Executive Summary, Narrative Reports, and every other AI-authored surface on this screen |
| `ReportAiInsightsStrip` | `components/reports/ai-insights-strip.tsx` (screen-specific) | The collapsible strip atop the Run/view canvas |
| `AskAiComposer` | `components/ai/ask-ai-composer.tsx` (shared with `app/(app)/ai/chat`) | The docked natural-language Q&A / draft-from-text input |
| `Sheet` | `components/ui/sheet.tsx` | Drill-down Panel; mobile category rail and filter sheet; the single-pane Builder step wizard below `xl`; Schedule/Share on mobile |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `alert-dialog.tsx` | Generate-params dialog, Schedule create/edit, Share create, Delete/Archive/Cancel-run confirmation |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Card/row "⋯" actions, Export menu |
| `Tooltip` | `components/ui/tooltip.tsx` | Permission-denied explanations, formula-function reference hints, "Why 81%?" confidence previews |
| `Skeleton` | `components/ui/skeleton.tsx` | Loading states, shape-matched to whichever of the three sub-surfaces is loading |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `error-state.tsx` | Empty library/category, empty search result, generation error |
| `DataTable` | `components/shared/data-table.tsx` | The Scheduled tab's `report_schedules` list; a report's own Run History list (`report_runs?report_definition_id=`) — **not** the generated report's own result grid, see below |
| `useApiToast` | `hooks/use-api-toast.ts` | Every mutation's success/error surface |
| `Can` | `components/auth/can.tsx` | Every permission-gated affordance in `# Route & Access`'s control table |

**Why `ReportResultTable` is bespoke, not `DataTable`.** `DataTable` (`COMPONENT_LIBRARY.md → DataTable`) is
built around a fixed-shape REST resource with `SORTABLE_FIELDS`/`SEARCHABLE_FIELDS` and page/cursor
pagination against a stable column set — exactly right for the Scheduled tab's `report_schedules` list or a
report's Run History, both genuine paginated collections of uniform rows. A generated report's result,
however, is an arbitrary, per-definition `columns`/`rows`/`subtotals`/`footer` payload already delivered
whole by a single `GET /reports/runs/{id}` call, frequently grouped into nested subtotal rows — the same
reason `TRIAL_BALANCE.md` builds `TrialBalanceTable` instead of reusing `DataTable`, and the same reason
`BANK_RECONCILIATION.md` declines to force `TanStack Table` onto its two-pane matching surface. `ReportResultTable`
therefore extends the `ResponsiveColumnDef<TData>` pattern `RESPONSIVE_DESIGN.md → Finance Tables On Small
Screens` already defines, adding grouping and an output-format union with `ReportChartCanvas`:

```tsx
// components/reports/result-table.tsx
interface ReportResultTableProps {
  columns: ReportColumnMeta[];       // { key, label_en, label_ar, type: 'string'|'currency'|'percentage'|'date'|'number' }
  rows: Record<string, unknown>[];
  subtotals?: { group: string; values: Record<string, unknown> }[];
  footer?: Record<string, unknown>;
  groupBy: string[];
  outputFormat: 'table' | 'bar' | 'line' | 'donut';
  density: 'comfortable' | 'compact';
  onDrillDown?: (row: Record<string, unknown>, column: ReportColumnMeta) => void;
  currencyCode: string;
}
```

**The Builder's drag-and-drop, with its mandatory non-drag alternative.** `SortableFieldList` wraps
`dnd-kit`'s pointer and touch sensors, per `RESPONSIVE_DESIGN.md`'s own statement that "reordering report
widgets" is one of the two named uses of `dnd-kit` in the platform. Because WCAG 2.2's **2.5.7 Dragging
Movements** is an AA criterion `ACCESSIBILITY.md → Where each WCAG principle is enforced` names explicitly
against "reordering report widgets" as its own worked example, every draggable row in the Fields palette,
the Canvas column order, and the Group-by/Sort lists in Configuration ships a keyboard-operable, non-drag
equivalent — up/down icon buttons, always visible (not revealed only on hover/focus, since a switch-control
or screen-magnifier user needs them discoverable the same way a mouse user discovers the drag handle):

```tsx
// components/reports/builder/sortable-field-list.tsx ("use client")
import { DndContext, closestCenter, PointerSensor, KeyboardSensor, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy, arrayMove, useSortable } from '@dnd-kit/sortable';

export function SortableFieldList({ items, onReorder }: { items: FieldRef[]; onReorder: (next: FieldRef[]) => void }) {
  const sensors = useSensors(useSensor(PointerSensor), useSensor(KeyboardSensor));
  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={({ active, over }) => {
        if (!over || active.id === over.id) return;
        const oldIndex = items.findIndex((i) => i.id === active.id);
        const newIndex = items.findIndex((i) => i.id === over.id);
        onReorder(arrayMove(items, oldIndex, newIndex));
      }}
    >
      <SortableContext items={items.map((i) => i.id)} strategy={verticalListSortingStrategy}>
        {items.map((item, idx) => (
          <SortableFieldRow key={item.id} item={item}>
            {/* Non-drag alternative — always visible, never hover-only */}
            <IconButton
              aria-label={t('reports.builder.moveUp', { field: item.label })}
              disabled={idx === 0}
              onClick={() => onReorder(arrayMove(items, idx, idx - 1))}
              icon={ArrowUp}
            />
            <IconButton
              aria-label={t('reports.builder.moveDown', { field: item.label })}
              disabled={idx === items.length - 1}
              onClick={() => onReorder(arrayMove(items, idx, idx + 1))}
              icon={ArrowDown}
            />
          </SortableFieldRow>
        ))}
      </SortableContext>
    </DndContext>
  );
}
```

# Data & State

## Endpoints consumed

All under `/api/v1/reports/`, Bearer + `X-Company-Id`, standard envelope, per `docs/accounting/REPORTS.md
→ API`:

| Method | Path | Permission | Used for |
|---|---|---|---|
| GET | `/reports/definitions` | `reports.{category}.read` | Library grid; category filter; search |
| GET | `/reports/definitions/{id}` | `reports.{category}.read` | Report Detail header; Builder's initial config load |
| POST | `/reports/definitions` | `reports.custom.create` | Builder's first autosave for a brand-new report (`/reports/new`) |
| PUT | `/reports/definitions/{id}` | `reports.custom.update` (own) / `.update.any` | Every subsequent Builder autosave and the explicit Publish |
| DELETE | `/reports/definitions/{id}` | `reports.custom.delete` (own) / `.any` | Delete action (Standard Reports never expose this control) |
| POST | `/reports/definitions/{id}/duplicate` | `reports.custom.create` | "Duplicate" — including cloning a Standard Report into an editable Custom Report |
| POST | `/reports/definitions/{id}/generate` | `reports.{category}.read` | "Run" — the Generate-params dialog's submit |
| GET | `/reports/runs/{id}` | `reports.{category}.read` | Run status poll fallback; the completed result payload the canvas renders |
| GET | `/reports/runs/{id}/export` | `reports.{category}.export` | Export menu — `?format=pdf\|xlsx\|csv\|json` |
| POST | `/reports/runs/{id}/ai-narrative` | `reports.{category}.read` + `ai.reports.narrative` | "Generate AI narrative" |
| GET | `/reports/schedules` | `reports.schedule.read` | Scheduled tab; a report's own Schedule sub-panel |
| POST | `/reports/schedules` | `reports.schedule.create` | Schedule dialog submit |
| PUT | `/reports/schedules/{id}` | `reports.schedule.update` | Editing an existing schedule |
| DELETE | `/reports/schedules/{id}` | `reports.schedule.delete` | Deactivating/removing a schedule |
| POST | `/reports/definitions/{id}/share` | `reports.{category}.share` | Share dialog submit (internal or external link) |
| GET | `/reports/shared/{token}` | none (token-gated) | The public external-share landing page, outside the authenticated shell |
| DELETE | `/reports/shares/{id}` | owner or `reports.{category}.share.any` | Revoking a share |
| GET | `/reports/data-sources` | `reports.custom.create` | Builder's Fields palette — the semantic-layer catalogue scoped to the requesting user's permissions |
| POST | `/reports/ask` | `reports.{category}.read`, resolved per question | Ask AI composer's natural-language Q&A |
| POST | `/reports/draft-from-text` | `reports.custom.create` | Ask AI composer's "draft a new report" mode |
| GET | `/reports/favorites` | authenticated | My Reports tab |
| POST | `/reports/favorites` | authenticated | The favorite-star toggle on any card |
| GET | `/dashboards/{code}` | `reports.{category}.read` | Opening a `category = 'dashboard'` definition directly from the Library |

## Query keys

```ts
// lib/api/query-keys.ts (extends the factory pattern from FRONTEND_ARCHITECTURE.md)
export const reportKeys = {
  all: ["reports"] as const,
  definitions: (filters: ReportDefinitionFilters) => [...reportKeys.all, "definitions", filters] as const,
  definition: (id: number) => [...reportKeys.all, "definition", id] as const,
  dataSources: (category?: ReportCategory) => [...reportKeys.all, "data-sources", category] as const,
  favorites: () => [...reportKeys.all, "favorites"] as const,
  schedules: (definitionId?: number) => [...reportKeys.all, "schedules", definitionId ?? "all"] as const,
  run: (runId: number) => [...reportKeys.all, "run", runId] as const,
  runHistory: (definitionId: number, filters: RunHistoryFilters) =>
    [...reportKeys.definition(definitionId), "run-history", filters] as const,
  ask: (definitionId: number | null) => [...reportKeys.all, "ask", definitionId] as const,
};
```

## Cache tuning by sub-surface and run status

Cache lifetime is a function of which of the three sub-surfaces is active and, on the Run/view surface, the
run's own `status` — mirroring both `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` and
`TRIAL_BALANCE.md`'s own "Cache tuning by snapshot status" table:

| Data | `staleTime` | Rationale |
|---|---|---|
| `reports/definitions` (Library grid) | 60 seconds | Master-data-adjacent; a new Custom Report appearing a minute late is not disruptive |
| `reports/data-sources` (Builder Fields palette) | 5 minutes | Reference data — the semantic layer changes only when engineering ships a new exposed table |
| A `draft` definition open in the Builder | `0`, never background-refetched | The autosave loop is the single writer; refetching over it would race the user's own in-flight edits |
| `report_runs.status IN ('queued','running')` | `0`, polled every 2s as a Reverb fallback | Actively changing; correctness over efficiency, identical to Trial Balance's `generating` row |
| `report_runs.status = 'completed'`, within the category's snapshot reuse window (default 15 min, per `docs/accounting/REPORTS.md → Caching`) | 15 minutes (or the category's configured window) | Re-requesting the identical `(definition_id, params, as_of)` tuple serves the existing `report_snapshots` row rather than re-running the query |
| `report_runs.status = 'completed'`, past the reuse window | 30 seconds, `force_refresh` available via the Refresh action | The snapshot itself is still valid/immutable; only the *default* re-serve behavior expires |
| `report_runs.status IN ('failed','cancelled')` | `Infinity` (terminal, re-run creates a new run row) | Nothing about a failed run changes on its own |
| `reports/schedules` | 30 seconds | Frequent enough manual edits (pausing a schedule before a holiday) to be worth a short stale window |
| `reports/favorites` | 5 minutes, invalidated immediately on the favorite-toggle mutation | Low-frequency, single-user data |

## Realtime

Two Laravel Reverb channels, per the platform-wide `private-company.{id}.<feature>[.{sub_id}]` convention:

| Channel | Events | Effect |
|---|---|---|
| `private-company.{id}.report-runs.{run_id}` | `report_run.queued`, `report_run.running`, `report_run.completed`, `report_run.failed` | Subscribed only while a specific run's `status IN ('queued','running')`, per `FRONTEND_ARCHITECTURE.md → Realtime` and `NAVIGATION_SYSTEM.md`'s own routing note for `/reports/runs/[runId]`; on `completed` the client invalidates `reportKeys.run(runId)` and the status card cross-fades into the rendered canvas |
| `private-company.{id}.notifications.{user_id}` | `report.generation_completed`, `report.generation_failed`, `report.scheduled_run_delivered`, `report.share_viewed`, `report.ai_narrative_ready`, `report.anomaly_detected` | Topbar bell + toast; a toast whose `run_id` matches the currently-open run additionally triggers a targeted `invalidateQueries` rather than waiting out `staleTime` |

A user who clicked "Run" and then navigated elsewhere in the app never loses the result: the notification
fires regardless of which screen they are on, and its deep link resolves straight to
`/reports/runs/{runId}`, which by then reads the already-`completed` row from cache with no further wait.

## AI agents feeding this screen

Per `docs/accounting/REPORTS.md → AI Responsibilities`, five agents contribute to this one screen, each
rendered through the shared `AiCardShell`/`ConfidenceBadge` envelope but visually and functionally distinct
by where they attach:

| Agent | Surfaces on this screen | Autonomy |
|---|---|---|
| **Reporting Agent** (primary) | Executive Summary (auto, in the Insights strip), Narrative Reports (explicit, `POST /ai-narrative`), Trend Detection callouts (auto-annotate on chart/table render), Question Answering (`/reports/ask`), Natural Language Reports (`/reports/draft-from-text`) | Suggest-only throughout; auto-annotate for Trend Detection only, since that is a deterministic statistical fact rather than a judgment call |
| **CFO Agent** | Risk Analysis (concentration, liquidity, covenant-proximity flags in the Insights strip), Recommendations (jointly with Reporting Agent) | Suggest-only; a critical-severity risk also fires a Notification |
| **Forecast Agent** | Forecasting mode on any trend-capable report — p10/p50/p90 band overlay on `ReportChartCanvas` | Suggest-only; never auto-populates a budget or reorder |
| **Fraud Detection** | Anomaly Detection findings in the Insights strip and the Findings Bar | **Auto-flag** (raised without a user request); dismissal requires `reports.audit.read` + a logged reason |
| **Compliance Agent** | Risk Analysis's regulatory-deadline flags (jointly with CFO Agent) | Suggest-only |

## Mutations

```ts
// hooks/reports/use-generate-report.ts
export function useGenerateReport(definitionId: number) {
  const router = useRouter();
  return useMutation({
    mutationFn: (body: GenerateReportRequest) =>
      api.post<GenerateReportResponse>(`/reports/definitions/${definitionId}/generate`, body, crypto.randomUUID()),
    // No onMutate — generation is a real backend job, never assumed complete client-side (Principle 10).
    onSuccess: (data) => {
      if (data.status === "completed") {
        router.push(`/reports/${definitionId}?mode=view&run_id=${data.run_id}`);
      } else {
        router.push(`/reports/runs/${data.run_id}`); // 202 — hand off to the polling/Reverb-subscribed run page
      }
    },
  });
}

// hooks/reports/use-save-report-draft.ts — the Builder's 3-second autosave, per docs/accounting/REPORTS.md
export function useSaveReportDraft(definitionId: number | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (config: ReportBuilderConfig) =>
      definitionId
        ? api.put<ReportDefinition>(`/reports/definitions/${definitionId}`, { config })
        : api.post<ReportDefinition>("/reports/definitions", { ...config, status: "draft" }),
    onSuccess: (data) => {
      queryClient.setQueryData(reportKeys.definition(data.id), data);
      // A brand-new draft's first save mints an id; the Builder replaces the URL via router.replace,
      // never router.push, so autosave never grows the browser history stack.
    },
  });
}

// hooks/reports/use-create-schedule.ts
export function useCreateSchedule(definitionId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateScheduleRequest) => api.post(`/reports/schedules`, { ...body, report_definition_id: definitionId }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: reportKeys.schedules(definitionId) }),
  });
}

// hooks/reports/use-ask-reporting-agent.ts — streaming, per the platform's Streaming AI chat pattern
export function useAskReportingAgent(definitionId: number | null) {
  return useChat({
    api: "/api/ai/reports-ask", // Next.js Route Handler proxying POST /api/v1/reports/ask over SSE
    body: { report_definition_id: definitionId },
  });
}
```

## Form schemas

```ts
// lib/schemas/reports.ts
export const reportFilterConditionSchema = z.object({
  field: z.string().min(1),
  op: z.enum(["equals", "contains", "starts_with", "greater_than", "less_than", "between", "in", "not_in", "is_true", "is_false"]),
  value: z.union([z.string(), z.number(), z.array(z.union([z.string(), z.number()]))]),
});

export const reportFilterGroupSchema: z.ZodType<ReportFilterGroup> = z.lazy(() =>
  z.object({
    op: z.enum(["AND", "OR"]),
    conditions: z.array(z.union([reportFilterConditionSchema, reportFilterGroupSchema])).min(1),
  }),
);

export const calculatedFieldSchema = z.object({
  id: z.string().regex(/^[a-z][a-z0-9_]*$/, "validation.reports.calculatedFieldIdFormat"),
  label: z.string().min(1, "validation.reports.labelRequired"),
  // Server re-validates against the exact same whitelist; the client schema only blocks obviously-invalid
  // input early — it never becomes the authority on which functions are safe (Principle 1).
  formula: z.string().min(1).refine(usesOnlyWhitelistedFunctions, "validation.reports.formulaWhitelist"),
  format: z.enum(["number_2dp", "percentage_1dp", "percentage_2dp", "currency", "date"]),
});

export const reportBuilderConfigSchema = z.object({
  name_en: z.string().min(1, "validation.reports.nameRequired"),
  name_ar: z.string().nullable(),
  category: z.enum([
    "financial", "operational", "inventory", "sales", "purchasing",
    "payroll", "tax", "banking", "compliance", "audit", "executive", "custom",
  ]),
  data_sources: z.array(z.string()).min(1, "validation.reports.dataSourceRequired"),
  fields: z.array(z.string()).min(1, "validation.reports.fieldRequired"),
  filters: reportFilterGroupSchema.optional(),
  group_by: z.array(z.string()).default([]),
  sort: z.array(z.object({ field: z.string(), direction: z.enum(["asc", "desc"]) })).default([]),
  calculated_fields: z.array(calculatedFieldSchema).default([]),
  output_format: z.enum(["table", "chart", "widget", "document"]).default("table"),
});

export const generateReportParamsSchema = z.object({
  period_start: z.string().date().optional(),
  period_end: z.string().date().optional(),
  as_of_date: z.string().date().optional(),
  branch_id: z.number().int().positive().nullable().optional(),
  comparison: z.enum(["none", "prior_period", "prior_year", "budget"]).default("none"),
  force_refresh: z.boolean().default(false),
}).refine((v) => v.as_of_date || (v.period_start && v.period_end), {
  message: "validation.reports.periodOrDateRequired",
  path: ["period_start"],
});

export const createScheduleSchema = z.object({
  name: z.string().min(1, "validation.reports.scheduleNameRequired"),
  frequency: z.enum(["once", "daily", "weekly", "monthly", "quarterly", "yearly", "cron"]),
  cron_expression: z.string().optional(),
  run_at_time: z.string().regex(/^\d{2}:\d{2}(:\d{2})?$/).optional(),
  timezone: z.string().default("Asia/Kuwait"),
  export_format: z.enum(["pdf", "xlsx", "csv", "json"]).default("pdf"),
  include_ai_summary: z.boolean().default(false),
  delivery_method: z.enum(["email", "shared_folder", "webhook"]).default("email"),
  recipients: z.array(z.object({ type: z.enum(["user", "role", "email"]), value: z.string() })).min(1, "validation.reports.recipientRequired"),
}).refine((v) => v.frequency !== "cron" || Boolean(v.cron_expression), {
  message: "validation.reports.cronExpressionRequired",
  path: ["cron_expression"],
});

export const createShareSchema = z.object({
  scope: z.enum(["internal_user", "internal_role", "external_link"]),
  shared_with_user_id: z.number().int().positive().optional(),
  shared_with_role: z.string().optional(),
  report_snapshot_id: z.number().int().positive().optional(), // required when scope = external_link
  expires_at: z.string().datetime().optional(),
  password: z.string().min(8, "validation.reports.sharePasswordMinLength").optional(),
  max_views: z.number().int().positive().optional(),
});
```

## SSR hydration

```tsx
// app/(app)/reports/page.tsx
export default async function ReportsLibraryPage({ searchParams }: { searchParams: Record<string, string> }) {
  const queryClient = getQueryClient();
  const filters = parseReportDefinitionFilters(searchParams); // { category, q, tab }

  await Promise.all([
    queryClient.prefetchQuery({
      queryKey: reportKeys.definitions(filters),
      queryFn: () => apiServer.get("/reports/definitions", { params: filters }),
    }),
    queryClient.prefetchQuery({
      queryKey: reportKeys.favorites(),
      queryFn: () => apiServer.get("/reports/favorites"),
    }),
  ]);

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <ReportsLibraryScreen initialFilters={filters} />
    </HydrationBoundary>
  );
}

// app/(app)/reports/[definitionId]/page.tsx
export default async function ReportDetailPage({
  params, searchParams,
}: { params: { definitionId: string }; searchParams: Record<string, string> }) {
  const queryClient = getQueryClient();
  const definitionId = Number(params.definitionId);

  await queryClient.prefetchQuery({
    queryKey: reportKeys.definition(definitionId),
    queryFn: () => apiServer.get(`/reports/definitions/${definitionId}`),
  });

  // If a run_id is already resolved in the URL (e.g. a bookmarked, still-fresh generated view),
  // prefetch it too so the canvas paints on first render instead of a client-side waterfall.
  if (searchParams.run_id) {
    await queryClient.prefetchQuery({
      queryKey: reportKeys.run(Number(searchParams.run_id)),
      queryFn: () => apiServer.get(`/reports/runs/${searchParams.run_id}`),
    });
  }

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <ReportDetailScreen definitionId={definitionId} mode={searchParams.mode ?? "view"} />
    </HydrationBoundary>
  );
}
```

# Interactions & Flows

**Opening the Library.** Default view is the **Library** tab, "All" category, sorted by `updated_at desc`
within each category group. **My Reports** reads `report_favorites` and re-sorts by `last_used_params`
recency; **Scheduled** is a `DataTable` over `report_schedules` scoped to the active company, columns
`name`, `report_definition`, `frequency`, `next_run_at`, `is_active`, with row actions Pause/Resume/Edit/
Delete gated on `reports.schedule.update`/`.delete`.

**Filtering by category / searching.** Clicking a category rail item updates `?category=` and re-fetches
`reports/definitions`; typing in the search box debounces 250ms against the same endpoint's `search`
parameter (matching `name_en`/`name_ar`, per the accounting document's Filtering & Pagination section). The
two compose — searching "aging" while "Sales" is selected returns only Customer Aging, not Vendor Aging.

**Running a Standard Report.** Clicking a card's "Run" opens a Generate-params `Dialog` pre-filled with the
report's `default_params` (period, comparison, dimension filters) — the same parameter surface
`TRIAL_BALANCE.md`'s own Generate dialog uses, generalized to whatever parameters that report's category
requires. Submitting calls `POST /reports/definitions/{id}/generate`. A light report (most Standard Reports
scoped to a single period) returns `200 OK` with `status: "completed"` synchronously, and the dialog closes
directly into the Run/view canvas. A heavy report (a multi-year trend, a group-wide consolidated analysis)
returns `202 Accepted` with a `poll_url`, and the user is routed to `/reports/runs/{run_id}` per
`# Data & State`'s mutation hook — they may navigate away entirely and return later via the resulting
notification's deep link.

**Building a new Custom Report.** "New Report" opens `/reports/new` with a lightweight first step — name,
category, one or more data sources from `GET /reports/data-sources` (already scoped to the requesting
user's permissions, so a Sales Employee's Fields palette never lists `payroll_items`) — then drops straight
into the three-pane Builder. Dragging a field from Fields onto Canvas appends it as a column (table mode) or
maps it to an axis/series (chart mode); every change debounces into the 3-second autosave described in
`# Data & State`, so the Builder Header's status text cycles `Saving…` → `Saved` → (on the next edit)
`Saving…` again, and a browser refresh or an accidental tab close never loses more than three seconds of
work. "Preview" runs the current draft config against a small sample (capped, uncached, never persisted as
a `report_runs` row) so a builder can see real output before committing to a full generation. "Publish"
transitions `status: draft → active`, makes the report visible to others per its Sharing configuration, and
is the only action that increments `report_definitions.version` per the accounting document's Versioning
rule — every autosave before that first Publish is silently overwritten, not versioned, because there is
nothing yet worth preserving history of.

**Editing an existing Custom Report.** Opening `/reports/[definitionId]?mode=edit` on an already-`active`
report re-enters the same three-pane Builder, gated on `reports.custom.update` (own) or `.update.any`.
Publishing a structural change (added/removed field, changed filter default, changed calculated-field
formula) increments `version` and sets `parent_definition_id`, exactly as `TRIAL_BALANCE.md`'s own snapshot
lineage does for a regenerated Trial Balance — a `report_runs` row generated before the edit continues to
resolve against the version it was actually run with (`report_snapshots.report_definition_version`), so an
old run's rendered output never silently reflects a filter someone changed last week.

**Adding a calculated field.** "+ Add calculated field" opens `CalculatedFieldEditor` — a formula input
constrained to the whitelisted function set (arithmetic; `SUM`/`AVG`/`COUNT`/`MIN`/`MAX`/`COUNT_DISTINCT`;
`IF(condition, then, else)`; `DATE_DIFF`/`DATE_TRUNC`; `CONCAT`/`UPPER`/`LOWER`), a live syntax check as the
user types, and a "Preview" chip showing the formula evaluated against the current sample once syntax is
valid. The formula is never evaluated client-side beyond this cosmetic syntax check — the actual value shown
is always a real server computation, per the platform-wide rule that the frontend computes nothing.

**Reordering fields, grouping, and sort precedence.** Every reorderable list in Configuration is a
`SortableFieldList` (`# Components Used`): drag to reorder, or use the always-visible up/down buttons.
Reordering the Group-by list changes subtotal nesting immediately in the live Canvas preview; reordering
Sort changes tie-break precedence (e.g., sort by `total_outstanding` descending, then `customer_name`
ascending as a tiebreaker), matching `docs/accounting/REPORTS.md → Sorting` exactly.

**Duplicating a report.** The "Duplicate" action (`reports.custom.create`, available on both Standard and
Custom cards) calls `POST /reports/definitions/{id}/duplicate` and opens the resulting `draft` copy directly
in the Builder — this is the primary on-ramp for a user who wants "the Sales Analysis report, but grouped by
region instead of rep" without starting from a blank canvas.

**Saving as a Template.** From the Builder's overflow menu, "Save as Template" sets `is_template = true` on
Publish, stripping company-specific filter *values* while retaining structure — the resulting template
reappears in "New Report"'s first step as a starting point alongside the raw category/data-source picker.

**Scheduling.** The Schedule dialog (`createScheduleSchema`) collects frequency, a cron expression when
`frequency = 'cron'`, time/day-of-week/day-of-month as appropriate, timezone (defaulting to the company's,
`Asia/Kuwait`), export format, whether to `include_ai_summary`, delivery method, and recipients (user, role,
or raw email, any mix). Submitting calls `POST /reports/schedules`; the response's `next_run_at` renders
immediately in the report's Schedule sub-panel and in the Scheduled tab's list, so a Sales Manager can
confirm "yes, this actually fires next Monday at 8 AM Kuwait time" without waiting for the first real run.

**Sharing.** "Share" offers an internal share (grant a specific user or role `view`/`edit` on the
definition itself) or an external link (a signed, time-limited, optionally password-protected URL to one
specific completed `report_snapshots` row, never to the live, re-runnable definition) — exactly the two
scopes `docs/accounting/REPORTS.md → Sharing` defines. An external share's create dialog requires picking
which completed run to freeze the link to; there is no "share the report" option without first resolving to
a specific snapshot, which is what guarantees an External Auditor's link never silently changes underneath
them.

**Exporting.** The Export `DropdownMenu` offers PDF / Excel (`.xlsx`) / CSV / JSON, each a
`GET /reports/runs/{id}/export?format=` call; for a heavy export the response is itself a `202` with a poll
URL, following the identical async pattern as generation (`# Performance`). A "Recent exports" sub-list
inside the same menu re-surfaces prior exports without regenerating the file, mirroring
`TRIAL_BALANCE.md`'s own export-menu precedent. A report generated from a `draft` Custom Report definition
(previewed but not yet published) carries a visible "Preview — not a published report" note in the export
menu item itself, the same "explain before the click, not after the download" discipline Trial Balance's
watermark note uses for an unapproved snapshot.

**Ask AI — natural-language question.** Expanding the docked composer and asking a question (e.g., "why did
gross margin drop in March?") calls `POST /reports/ask` scoped to whatever report is currently open (or
unscoped from the Library, in which case the agent resolves which report(s) the question implies). The
answer streams in as prose with the specific rows it cites attached inline, per `# AI Integration`.

**Ask AI — draft a new report from text.** The same composer accepts "show me all overdue invoices for
customers in the Farwaniya region, grouped by sales rep" and, instead of answering, recognizes a
report-drafting request and calls `POST /reports/draft-from-text`; the response is a draft `report_definitions`
row the user is navigated straight into (`/reports/{id}?mode=edit`), fully editable in the Builder exactly
as if they had built it by hand, before any Publish.

**Drill-down.** Clicking a cell whose column carries a `source_refs`-bearing key opens the Drill-down
`Sheet`, listing the contributing records; a "View source" link navigates to the owning module's own detail
screen (a `journal_entries/{id}` for a GL-backed figure, an `invoices/{id}` for a Sales Analysis row) and
closes the Sheet on navigate rather than stacking a second overlay atop a route change, identical to
`TRIAL_BALANCE.md`'s own Drill-down behavior.

**Versions vs. Run History — two different histories, never conflated.** A report *definition*'s version
history (`parent_definition_id` chain) is about the report's *shape* changing over time and is surfaced in
the Builder's "Version history" overflow item; a report's *Run History* (`report_runs` filtered to that
`report_definition_id`) is about *when it was generated and by whom* and is surfaced as its own `DataTable`
tab on the Run/view surface. Opening an old definition version is read-only and clearly labeled, matching
`TRIAL_BALANCE.md`'s "Viewing version 1 of 2 — superseded" banner precedent; opening an old run from Run
History simply re-renders that run's frozen `report_snapshots` payload, which never changes regardless of
what the definition looks like today.

# AI Integration

Every AI-authored surface on this screen renders through the platform's mandatory `AiCardShell` +
`ConfidenceBadge` + `ReasoningDisclosure` envelope (`FRONTEND_ARCHITECTURE.md → AI Integration Layer`),
never a screen-specific one-off card — the "colored border plus AI badge" rule holds here exactly as it
does everywhere else in the product.

**Confidence-scale reconciliation — the single most important detail to get right on this screen.**
`docs/accounting/REPORTS.md`'s own worked JSON examples put every AI confidence value in this module on a
**0.00–1.00** scale (`"confidence": 0.81`, `"confidence": 0.98`), which is the same scale
`journal_lines.ai_confidence`/`accounts.ai_suggestion_confidence` use elsewhere in the platform — **not**
the 0–100 scale `ai_decisions.confidence_score` uses for the AI Command Center's recommendations. Per
`COMPONENT_LIBRARY.md`'s own explicit Edge Case warning that these "are two genuinely different scales on
two different tables… not a typo to fix in one component," every confidence value this screen renders —
`report_runs.ai_metadata.confidence`, a finding's `confidence`, a forecast's interval confidence — passes
through `normalizeConfidence(raw, "report_runs.ai_metadata")` before reaching `ConfidenceBadge`; a missing or
wrong `sourceField` argument here is exactly the bug class that document calls "the single most likely
AI-panel bug across the whole frontend," and it is unit-tested for this screen specifically rather than left
to convention.

**Executive Summary.** Renders automatically in the Insights strip the moment a report finishes generating
— no explicit request needed, since this is low-risk read-only narration, gated only by
`reports.{category}.read`:

```json
{
  "text": "Net revenue for Q2 2026 rose 8.1% quarter-over-quarter in Electronics, led by Fahad Al-Mutairi's territory, while margin compressed slightly across the category due to a vendor price increase in April.",
  "confidence": 0.81,
  "source_refs": ["report_runs.918273.rows[0..1]", "purchase_analysis.vendor_4021.price_change_2026-04-03"]
}
```

`ConfidenceBadge` renders this at 81% with an amber "verify" cue only if it fell below 60 (per the
accounting document's own threshold); `ReasoningDisclosure` expands to show the `source_refs` as clickable
jumps into the Drill-down Sheet. The summary is never merged into an exported "official" report body unless
a human explicitly checks "Include AI summary" at export/schedule time.

**Narrative Reports.** A longer, board-pack-register narrative triggered explicitly via "Generate AI
narrative" (`ai.reports.narrative` + `reports.{category}.read`), calling `POST /reports/runs/{id}/ai-narrative`.
Every paragraph's factual claims are individually traceable to `source_refs`, rendered via the same
`ReasoningDisclosure`; the result is stored on that specific run's `ai_metadata.narrative` and versioned
alongside it — regenerating the narrative for the same run replaces this field, it never creates a second
run.

**Forecasts.** On any trend-capable report, a "Forecast" toggle overlays `ReportChartCanvas` with a
statistical-mode projection (available with zero AI dependency) or an AI-mode projection from the Forecast
Agent, rendered as a shaded p10/p50/p90 band, never a bare point estimate in AI mode. Autonomy is strictly
suggest-only: a forecast never auto-populates a `budgets` row or triggers a reorder; accepting one deep-links
into the owning module's own create flow. Forecast accuracy is tracked externally on the AI Dashboard's own
"Forecast accuracy tracker" widget, not duplicated on this screen.

**Risk Analysis and Recommendations.** The CFO Agent, Compliance Agent, and Reporting Agent jointly surface
risk flags and action-oriented recommendations in the Insights strip. Recommendations reuse the platform's
three-button envelope *structurally* but never its full behavior here: because Reporting has no write
access to any other module's operational tables, `can_execute_directly` is always server-computed `false`
for a Reporting-sourced recommendation, so the "Do it" slot never renders — only a `Link`-styled "Open in
{module}" deep link (e.g., into Purchasing's create-PO flow) and "Dismiss" ever appear. This is what makes
the accounting document's own claim — "Reporting has no write access to Purchasing, Sales, or any other
module's operational tables" — structurally true on this screen rather than a UI convention a user could
route around.

**Anomaly Detection.** Fraud Detection findings **auto-flag** — they appear in the Findings Bar and Insights
strip without anyone requesting a scan — but dismissing one as a false positive requires `reports.audit.read`
and a mandatory typed reason, itself audit-logged; the agent never silently retracts its own flag. A
`critical`-severity anomaly additionally fires the `report.anomaly_detected` notification event to the
relevant Finance/Audit role.

**Trend Detection.** Embedded directly in any chart-mode report: the Reporting Agent auto-annotates a data
point deviating more than 1.5σ from its trailing moving average, or a sustained 3-plus-period directional
trend crossing a materiality threshold, with a small callout marker — treated as a deterministic statistical
fact rather than a judgment call, so it renders on load without an explicit "generate insights" click, unlike
Narrative Reports.

**Question Answering — worked refusal example.** A Sales Employee asking "what's our total payroll cost
this quarter?" through the Ask AI composer never receives a guessed or partially-redacted number; the agent
returns an explicit in-band refusal identical in spirit to the accounting document's own worked example:

```json
{
  "success": false,
  "message": "You do not have permission to view Payroll reports",
  "errors": [{ "code": "FORBIDDEN_REPORT_CATEGORY", "detail": "Missing permission: reports.payroll.read" }]
}
```

The composer renders this as a calm, first-person assistant message ("I can't answer that — you don't have
permission to view Payroll reports"), never as a raw error toast, and never omits only the restricted part
of a broader answer while silently answering the rest. A question that cannot be mapped to any available
data source at all gets the equivalent honest "I don't have a way to answer that" rather than a
plausible-sounding fabrication.

**Streaming implementation.** Ask AI reuses the platform's Streaming AI chat pattern verbatim
(`FRONTEND_ARCHITECTURE.md → AI Integration Layer → Streaming AI chat`): a Next.js Route Handler proxies
`POST /api/v1/reports/ask` over Server-Sent Events using the httpOnly-cookie-derived bearer token, and the
client composer is a thin `useChat` wrapper (`hooks/reports/use-ask-reporting-agent.ts`, `# Data & State`).
Streamed prose arrives already localized to the caller's session language; the frontend never
re-translates it, and any figure the agent cites renders through the same bidi-isolated, currency-aware
formatting components the rest of the product uses.

**Unavailable AI.** If the Reporting Agent (or any collaborating agent) is unreachable, times out, or is
disabled for the company, the Insights strip shows a single calm `StatusPill` ("AI insights temporarily
unavailable") rather than an error state or an infinite spinner, and every human-driven action — Generate,
Export, Schedule, Share — remains fully available, exactly matching `TRIAL_BALANCE.md`'s own "AI is
additive, never a required gate" rule.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading — Library, first paint | Initial navigation, no hydrated cache | `Skeleton` shaped as a category rail + card grid (shimmer sweep per `DESIGN_LANGUAGE.md → Motion`), never a generic spinner |
| Loading — Builder, first paint | Opening `/reports/[definitionId]?mode=edit` | Three-pane skeleton; Fields pane skeleton resolves first (it depends only on `reports/data-sources`, a 5-minute-stale reference fetch) |
| Empty — filtered to nothing | A category + search combination matches zero definitions | `EmptyState`, "No reports match your filters," with a "Clear filters" action — never conflated with a genuinely empty category |
| Empty — My Reports | User has favorited nothing yet | `EmptyState` pointing at the Library's star toggle, not a bare blank tab |
| Empty — Scheduled | No `report_schedules` rows yet | `EmptyState` with a CTA into any report's own Schedule action, gated on `reports.schedule.create` |
| Draft, autosaving | Builder open, `status: draft` | Header status text cycles `Saving…` → `Saved`; no blocking spinner, no modal |
| Autosave failed | A `PUT`/`POST` to `/reports/definitions` errors (network blip, `5xx`) | Header status text shows "Couldn't save — retrying," a non-blocking `Toast`, and an automatic retry with backoff; the in-memory draft is never discarded while retries continue |
| Generating | `report_runs.status IN ('queued','running')` | The compact status card from `# Layout & Regions`; Reverb-subscribed with a 2s poll fallback identical to `TRIAL_BALANCE.md`'s own `generating` state |
| Completed — synchronous | `200 OK` returned directly from `generate` | Canvas renders immediately, no intermediate status card |
| Completed — asynchronous | `202` followed later by `report_run.completed` | Status card cross-fades into the canvas in place; if the user has navigated away, the notification's deep link lands directly on the completed canvas |
| Failed | `report_runs.status = 'failed'` | `ErrorState` naming `error_code`/`error_message` in plain language, a "Try again" action that re-opens the Generate-params dialog pre-filled, and — for a `BALANCE_SHEET_UNBALANCED`-class `error_code` inherited from a Financial-Statements-owned report — a link into that report's own screen rather than attempting to resolve it here |
| Cancelled | User clicked "Cancel run" while `queued`/`running` | A neutral (not `danger`-toned) `StatusPill`, "Cancelled," with a "Run again" action — cancellation is a deliberate user choice, not a failure |
| Served from snapshot cache | A re-request for the identical `(definition_id, params, as_of)` lands within the category's reuse window | A small "Generated {n} minutes ago · cached" note beside the Refresh action, plus an explicit "Force refresh" option — never presented as if it were freshly computed this instant |
| Stale, past reuse window | Same tuple, window elapsed | Identical rendering, note text switches to "may be out of date — Refresh to recompute" |
| No data for given filters | Filters legitimately produce zero rows (e.g. a brand-new branch with no postings yet) | A lighter `EmptyState` variant, "No results for these filters," never conflated with a failed run |
| AI unavailable | Reporting Agent (or a collaborator) unreachable/disabled | Calm `StatusPill` in the Insights strip; every human action stays available (`# AI Integration`) |
| Low-confidence AI narrative | `confidence < 0.6` after `normalizeConfidence` | `ConfidenceBadge` renders amber with a "low confidence — verify" label alongside the number, never hiding the number behind the qualitative label |
| Permission revoked mid-session | A role change removes a permission this screen was relying on (e.g. `reports.custom.update` mid-edit) | The next mutation's `403` collapses the affected control to its disabled/omitted form with an explanatory `Toast`, never a silently-stuck loading state |
| Error | `403`/`404`/`5xx`/network failure on any request | `ErrorState` with a retry action; a `404` on `/reports/[definitionId]` (deleted or never existed) is visually and textually distinct from a `403` (exists, not permitted), per `NAVIGATION_SYSTEM.md`'s enumeration-discipline rule |

# Responsive Behavior

**Mobile (`base`–`sm`) — Library.** The category rail collapses behind a "Categories" trigger opening a
`Sheet`; the Library/My Reports/Scheduled `Tabs` become a horizontally-scrollable chip row; the card grid
becomes a single column, each `ReportCatalogCard` full-width with its "Run" action and overflow menu
retained at full 44×44px touch size.

**Mobile — Builder.** The three-pane layout is impossible to usefully compress into a 390px viewport, so
below `xl` the Builder becomes a **single-pane step wizard** — Data Source → Fields → Filters → Grouping →
Sort → Calculated Fields → Preview — each step a full-screen `Sheet`-like view with Back/Next, the same
3-second autosave firing per step rather than only at the end, so a dropped connection mid-wizard never
loses more than the current step's edits. `SortableFieldList`'s drag becomes a single-column list with the
same always-visible up/down buttons as desktop — nothing about the non-drag alternative changes with
viewport, since it is not a desktop-only accommodation.

**Mobile — Run/view.** A table-mode result reuses `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`
exactly as `TRIAL_BALANCE.md` does: the canvas becomes a stack of result cards showing only the
highest-priority columns plus a "View full row" action opening the remaining fields in a `Sheet`. A
chart-mode result already reflows naturally via `ResponsiveContainer`'s percentage sizing inside an
explicit-height parent (`RESPONSIVE_DESIGN.md`'s own Recharts guidance); the AI Insights strip and any
open Findings Bar stay pinned above the result, never scrolling away, matching the platform rule that a
report's status/summary must never leave the viewport while the data it summarizes is being reviewed.

**Tablet and up (`md`+).** The Builder renders its full three-pane layout from `xl`; between `md` and `xl`
it renders two panes (Fields collapses into a `Popover` triggered from the Canvas toolbar) rather than
jumping straight to the mobile wizard, since a tablet in landscape has room for two panes comfortably. The
Run/view canvas is a real `<table>` with the identifier column `sticky start-0` and a `sticky bottom-0`
totals row, identical in mechanics to `TrialBalanceTable`.

**Charts.** Every `ReportChartCanvas` instance wraps Recharts' `ResponsiveContainer` inside a parent with an
explicit height, never a bare percentage height inside a flex/grid parent, per `RESPONSIVE_DESIGN.md`'s own
documented Recharts failure mode; the Forecast band overlay and Trend Detection callout markers both scale
with the same container rather than being positioned in independent absolute coordinates that would drift
on resize.

**Touch and virtualization.** Row-action and drag-handle touch targets meet the platform's 44×44px minimum
regardless of density; `ReportResultTable` mounts `@tanstack/react-virtual` once `row_count` crosses
roughly 200, using the active density token's row height as the size estimate, identical to
`TrialBalanceTable`'s own threshold. `SortableFieldList`'s `dnd-kit` sensors include the pointer/touch
sensor pair `RESPONSIVE_DESIGN.md` already names for "reordering report widgets," and the drag-axis
constraint sign flips with `dir` using the same `dir === 'rtl' ? -1 : 1` convention that document's
`SwipeableApprovalCard` example already establishes, rather than a Builder-specific reimplementation.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, inheriting `THEMING.md → RTL as a
theming dimension` and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a single per-component RTL branch,
plus several module-specific applications:

- **Bilingual everything, including the semantic layer itself.** Report names (`name_en`/`name_ar`),
  category labels, and — critically — every column label sourced from `report_data_sources.fields`
  (`label_en`/`label_ar`) render in the active locale with an English fallback only when the Arabic label is
  genuinely absent, never the reverse; this applies identically inside the Builder's Fields palette and the
  generated report's own column headers, so a bilingual Finance team building a report in Arabic sees
  Arabic field names while building, not just while viewing the result.
- **Debit/Credit column order is fixed only where a report actually has debit/credit columns.** Unlike
  Trial Balance or the General Ledger, most reports in this generic engine (Sales Analysis, Customer Aging,
  KPI Scorecard) have no debit/credit concept at all, so `LAYOUT_SYSTEM.md → Rule 2, Exception B`'s
  "Debit-before-Credit is physically fixed" rule applies conditionally here: a Custom Report built directly
  against `journal_lines`/`ledger_entries` (or a duplicate of a GL-shaped Standard Report) inherits the fixed
  physical ordering, while an ordinary Sales or Inventory report's columns follow normal logical-property
  mirroring like any other table. `ReportResultTable` reads a `preserveColumnOrder: boolean` flag, computed
  server-side from whether the underlying data source is ledger-derived, rather than the frontend guessing
  from column names.
- **Numeric alignment is physically fixed regardless of the debit/credit exception above** — every money,
  percentage, and count column in `ReportResultTable` is `text-right` in both directions, never `text-end`,
  so magnitude scanning by decimal alignment works identically for a bilingual reviewer in either session
  language.
- **Report codes, dates, confidence percentages, and formula strings stay LTR-embedded** inside Arabic
  narrative (AI Executive Summaries, Narrative Reports, Ask AI answers, and the Builder's own formula
  preview chip) via the shared `Bidi`/`LtrInline` wrapper (`unicode-bidi: isolate` + `dir="ltr"`), so a
  `STD_SALES_ANALYSIS` code, a `2026-07-16` date, or a `concentration_pct` formula reference never reorders
  inside a right-to-left sentence.
- **AI-authored prose is generated server-side in the session locale**, never machine-translated
  client-side, per `FRONTEND_ARCHITECTURE.md`'s content-negotiation contract — Arabic accounting/reporting
  terminology is used precisely (تقرير for Report, مخطط for Chart, حقل محسوب for Calculated Field, جدولة for
  Schedule).
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching every other
  financial screen in the platform.
- **The Builder's drag interactions are direction-aware, not hardcoded.** `SortableFieldList`'s drag-axis
  sign, the Fields palette's expand/collapse chevrons, and the Canvas's column-reorder handles all read the
  resolved `dir` at render time rather than assuming LTR, per `RESPONSIVE_DESIGN.md`'s own precedent.
- **Charts never mirror.** Every `ReportChartCanvas` keeps a fixed left-to-right time axis regardless of
  `dir`, rendered inside a `dir="ltr"` wrapper, per `LAYOUT_SYSTEM.md → Rule 5 — Charts never mirror` — a
  revenue trend reads earliest-to-latest identically in an Arabic and an English session.
- **Directional chrome mirrors; content chrome does not.** The Drill-down/Schedule/Share `Sheet`s slide from
  the logical end edge; the category rail's expand chevrons and breadcrumb chevrons mirror via `rtl-flip`;
  the `BarChart3` nav icon, the `FileBarChart2` report icon, the `Sparkles` AI glyph, and every severity icon
  do not, per `ICONOGRAPHY.md`'s directional-icon table.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`/`THEMING.md`. Nothing on this
screen references a raw hex value or a Tailwind palette utility directly; every surface, border, category
chip, and status color resolves through the semantic tokens those documents already define.

- **Surfaces and elevation.** The Library's card grid sits on `--color-bg-canvas`; each `ReportCatalogCard`
  and the Report Header/Canvas sit on `--color-bg-surface`; the Builder's three panes and every `Sheet`
  (Drill-down, Schedule, Share, mobile Filters) sit on `--color-bg-surface-raised`. Elevation reads as the
  surface getting lighter toward the viewer in dark mode (canvas → surface → raised), never via shadow
  alone — the Builder's pane dividers and the Canvas's sticky header/footer both carry their paired
  `--color-border-subtle` hairline in dark mode even where the light-mode equivalent leans on shadow, for
  the same reason `TRIAL_BALANCE.md` gives: a borderless dark panel disappears against a near-black canvas.
- **Category chips and `StatusPill` tones** (`draft`/`active`/`archived`, `queued`/`running`/`completed`/
  `failed`/`cancelled`) resolve through `--color-accent`, `--color-warning`, `--color-success`,
  `--color-danger` exactly as `Badge`'s `cva` variants already define — no report category invents its own
  hue; the twelve categories are distinguished by icon and label, never by a bespoke per-category color,
  keeping the palette exactly as disciplined here as everywhere else in the product.
- **A generated report's own money figures never take a status color.** Every `AmountCell` in
  `ReportResultTable` renders in `--color-fg-primary` regardless of theme, matching the platform's
  debit/credit-figures-are-neutral rule; only a genuinely signed metric (a Comparative view's variance,
  a Forecast band's confidence shading) ever carries `success`/`warning`/`danger`.
- **AI provenance uses the single platform accent, deliberately.** `AiCardShell`, the Insights strip's
  border, the `Sparkles` glyph, and `ConfidenceBadge`'s fill all resolve through `--color-accent`/
  `--color-accent-subtle-bg` — QAYD does not introduce a second, AI-specific hue, per
  `TRIAL_BALANCE.md → Dark Mode`'s explicit statement of this rule and `DESIGN_LANGUAGE.md`'s "the accent is
  also the AI's signature." A user who has learned that accent-brass means "AI touched this" on any other
  screen in the product reads it identically here.
- **Charts re-render on theme change; they do not restyle via CSS alone.** Recharts (the platform's
  charting library, wrapped in `components/charts/`) renders SVG `fill`/`stroke` as JavaScript string props,
  which a Tailwind `dark:` class on a wrapping `<div>` cannot reach, per `DARK_MODE.md`'s own documented
  caveat. `ReportChartCanvas` reads the resolved CSS custom properties (`--color-accent`,
  `--color-fg-secondary`, `--color-border-subtle`) through a small hook at render time and re-renders on
  theme change, and every chart explicitly overrides its tooltip's `contentStyle` to consume
  `--color-bg-surface-raised`/`--color-border-subtle`/`--color-fg-primary` rather than the library's default
  opaque-white tooltip box, exactly as `DARK_MODE.md` prescribes for every chart in the product.
- **Print/export always renders light, regardless of the viewer's theme.** Every PDF/Excel export generated
  from a `report_runs` row renders through the backend's fixed light/print palette independent of the
  requesting user's in-app theme preference, per `DARK_MODE.md`'s explicit statement that this applies to
  "any other server-generated PDF export (`reports.report_runs`)" — a scheduled board-pack or an External
  Auditor's shared snapshot is routinely opened by someone who has never once opened QAYD in dark mode, and
  a dark PDF would be illegible on paper regardless.
- **Contrast.** Every token pairing above is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text —
  chart data marks, category chip borders, the sticky-column divider) in both themes, per
  `DARK_MODE.md → Color & Contrast In Dark`; chart data-series colors specifically are checked against
  `ACCESSIBILITY.md`'s 1.4.11 non-text contrast criterion, not merely against each other, since a
  low-contrast series against the chart's own background is as real a defect as low-contrast text.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented here without deviation, plus the platform's
stricter internal bar wherever this screen touches AI provenance or an existence-sensitive action.

- **`ReportResultTable` uses real table semantics.** A genuine `<table>` with a `<caption>` naming the
  report and its resolved period, `scope="col"` headers, and `scope="row"` on the identifier column —
  `ACCESSIBILITY.md → Data Tables Accessibility`'s "plain table pattern," never a styled `<div>` grid,
  because a generated report is a display artifact, not an inline-editable spreadsheet; the Builder's own
  Canvas preview during authoring uses the same real-table markup for the identical reason.
- **Every chart ships a "View as table" toggle.** A bar/line/donut `ReportChartCanvas` is never the sole
  representation of its data — the same `columns`/`rows` payload backing it is always one click away as a
  real `<table>`, so a screen-reader user, a user relying on a braille display, or simply someone who wants
  to copy exact figures is never limited to an SVG's inaccessible-by-default data marks.
- **Drag-and-drop always ships its non-drag alternative, per WCAG 2.2's 2.5.7 Dragging Movements.**
  `SortableFieldList`'s always-visible up/down buttons (`# Components Used`) are not a mobile-only or
  keyboard-only affordance; they render for every user, every viewport, every input method, exactly as
  `ACCESSIBILITY.md`'s own worked example for "reordering report widgets" requires.
- **Confidence and AI provenance carry the platform's triple redundancy.** Every `ConfidenceBadge` shows a
  numeric percentage, a qualitative band (amber below 60%), and a `VisuallyHidden` text equivalent ("81%
  confidence, Reporting Agent") — never color alone, matching the same rule `AmountCell` applies to
  debit/credit.
- **Live regions are tiered by how much the change matters, not applied uniformly.** A run transitioning
  `generating → completed` announces via `role="status" aria-live="polite"`; a transition to `failed`
  escalates to `role="alert"` (assertive), mirroring `TRIAL_BALANCE.md → Accessibility`'s identical tiering
  for its own Status Banner. A new Fraud Detection finding arriving mid-review via Reverb appends to the
  Findings Bar with a polite announcement ("New finding: duplicate invoice number detected") rather than
  reordering or auto-expanding whatever the reviewer is currently reading.
- **The Ask AI composer's streamed answer is announced incrementally, not chunk-by-chunk.** Per the
  platform's chat pattern, the composer's live region debounces announcements to sentence boundaries rather
  than firing on every streamed token, so a screen-reader user hears coherent phrases rather than a rapid,
  unintelligible token-by-token stream.
- **Every disabled/omitted control explains itself.** A permission-gated action renders with
  `aria-describedby` naming the exact missing permission ("Requires `reports.schedule.create`"), worded
  distinctly from a business-state-gated disablement ("This report is still generating — try again once
  it completes") — the two are never collapsed into a generic "You can't do this," per
  `ICONOGRAPHY.md`/`TRIAL_BALANCE.md`'s shared convention.
- **Export is omitted, not disabled, for the same specific reason `TRIAL_BALANCE.md` gives.** Because
  Export sits inside a `DropdownMenu` whose trigger is otherwise always meaningful, and because a disabled
  export item with no visible content behind it leaves a screen-reader user unable to tell whether the
  capability exists at all, the item is filtered from the menu's own data source
  (`actions.filter(hasPermission)`) rather than rendered disabled.
- **Keyboard.** `Cmd/Ctrl+Enter` confirms the Generate-params, Schedule, and Share dialogs; the mobile
  Builder wizard's Back/Next are real, focusable buttons reachable in DOM order, never a swipe-only
  progression; the category rail implements the same roving-tabindex pattern (`↓`/`↑` between visible
  items, `→`/`←` direction-aware to expand/collapse) `NAVIGATION_SYSTEM.md`'s Sidebar already establishes,
  rather than a screen-specific reimplementation.
- **Focus management on overlays.** The Drill-down Sheet's `onOpenAutoFocus` moves focus to its heading, not
  its close button, and closing it (Escape, backdrop click, or navigating into a linked record) returns
  focus to the triggering cell; the Schedule and Share Dialogs behave identically on submit/cancel, matching
  `TRIAL_BALANCE.md`'s own overlay-focus precedent throughout.

# Performance

- **Async generation is never a blocking request.** Any `generate` call whose estimated scan exceeds the
  backend's configured row threshold returns `202 Accepted` immediately; the frontend never holds a loading
  spinner open for a multi-minute computation, subscribing to the run's Reverb channel and letting the user
  keep working elsewhere, per `# Data & State → Realtime`.
- **Light queries are backed by materialized views and rollups, not raw scans, and the frontend benefits
  from this transparently.** `mv_gl_monthly_balances`, `mv_sales_daily`, and `mv_inventory_valuation_current`
  (`docs/accounting/REPORTS.md → Materialized Views`) back most Standard Reports' interactive-speed path;
  the Library's "Last run"/"Next scheduled" badges and the Scheduled tab's list read from Redis-cached
  rollups and `report_daily_rollups` rather than issuing an N+1 query per card — a Library with sixty
  catalogue entries across twelve categories renders its badges from one batched call, not sixty.
- **Virtualization past ~200 rows.** `ReportResultTable` mounts `@tanstack/react-virtual` once `row_count`
  crosses roughly 200, using the active density mode's row height as the size estimate, identical in
  mechanism to `TrialBalanceTable`.
- **Snapshot reuse avoids redundant computation.** Re-requesting the identical `(definition_id, params,
  as_of)` tuple within the category's configured freshness window (default 15 minutes for financial
  reports) serves the existing `report_snapshots` row rather than re-running the query, unless the caller
  explicitly passes `force_refresh: true` — the frontend's Refresh action is the only UI path that sets it.
- **Deferred, code-split heavy dependencies.** `ReportChartCanvas`'s Recharts dependency, `dnd-kit` (used
  only by the Builder), and the Ask AI composer's chat bundle are all dynamically imported
  (`next/dynamic`) rather than shipped in the Library's initial route chunk — a Warehouse Employee who only
  ever runs a table-mode Inventory report never downloads a charting library, and a Read Only viewer who
  never opens the Builder never downloads `dnd-kit`.
- **Debounced, cancellable formula validation.** `CalculatedFieldEditor`'s live syntax check and sample-data
  preview debounce 300ms and cancel any in-flight preview request via `queryClient.cancelQueries` before
  issuing the next one, so fast typing never stacks redundant server round-trips.
- **SSR-seeded first paint.** Per `# Data & State`'s hydration pattern, the Library's definitions/favorites
  and a Report Detail page's definition (and, where already resolved, its current run) are fetched once,
  server-side, and hydrated into the client cache — the client's first `useQuery` for the same keys resolves
  instantly rather than re-issuing a request the server already made.
- **Cache-busted by domain event, not only by TTL.** The Redis-cached rollups feeding Library badges and KPI
  previews are explicitly invalidated by the same domain events the accounting document names
  (`invoice.posted`, `journal_entry.posted`, `payroll.completed`) in addition to their TTL, so a "Last run"
  badge or a live Cash Position preview updates immediately on the underlying event rather than waiting out
  a stale cache window.

# Edge Cases

1. **Concurrent "Run" clicks on the same report.** The Run action disables itself immediately on click,
   before the network round trip resolves, and reuses a single `Idempotency-Key` per submission attempt; a
   genuine race's `409 Conflict` renders a friendly "Already generating — hang on" toast rather than a raw
   error, identical to `TRIAL_BALANCE.md`'s own Generate-button precedent.
2. **Permission revoked mid-Builder-session.** If `reports.custom.update` is revoked while a draft is open,
   the next autosave's `403` collapses the Builder to a read-only view with a `Toast` explaining why, and
   Publish is blocked with an explanation rather than the screen silently continuing to appear editable.
3. **A Custom Report's data source is narrowed after publishing** (a permission change removes the
   builder's own access to one of the joined `report_data_sources`). The report continues running for
   viewers who still hold that source's `required_permission`; a viewer who has lost it sees a banner naming
   the specific source now inaccessible rather than a silent partial result or a generic error.
4. **A scheduled run's recipient has since lost the report's read permission.** The scheduled email simply
   does not go to that recipient; the omission is logged as a system finding visible in the report's own
   Schedule sub-panel ("delivery skipped for {role}: permission no longer granted"), never a silent gap a
   Finance Manager only discovers by noticing an email never arrived.
5. **Company base-currency change spanning a scheduled report's history.** Historical scheduled runs
   generated before the change render with an explicit footnote disclosing the historical conversion rate
   used, never a silent re-expression of past figures in the new base currency, mirroring
   `TRIAL_BALANCE.md`'s identical rule for its own Comparative view.
6. **A calculated field's formula references a since-removed or renamed field.** Reopening the Builder
   surfaces a broken-reference warning inline on the affected calculated field and blocks Publish until it
   is resolved or removed; the previously-published version keeps running unaffected for anyone who already
   has it scheduled, until the next edit actually touches it.
7. **Company switch while the Builder has unsaved (not-yet-autosaved) changes.** Per the platform's
   company-switch contract, switching discards all cached Reports state; the switcher's confirmation dialog
   names the in-progress edit explicitly before proceeding, identical to `TRIAL_BALANCE.md`'s Edge Case for
   an open Generate dialog.
8. **A huge, unscoped ad hoc Custom Report** (no date range, no dimension filter, against a
   many-million-row source). The Builder's Preview step and the Generate-params dialog both surface a
   persistent, dismissible nudge steering the author toward scoping by date/branch/account, rather than
   silently allowing (or silently queuing for minutes) an unscoped scan, mirroring `TRIAL_BALANCE.md`'s
   identical nudge for an unscoped Ledger view.
9. **An external share link is expired, revoked, or past `max_views`.** `GET /reports/shared/{token}`
   renders a calm, generic "This link is no longer available" public page — never a raw `404`, and never a
   page that reveals whether a token ever existed at all, which would itself leak information to anyone
   probing token values.
10. **A `report_snapshots` payload has aged out of retention while its `report_runs` metadata row survives**
    (per `docs/accounting/REPORTS.md → History`). Run History still lists the run — who ran it, when, with
    what parameters — but opening it shows "This report's content is no longer available under the
    company's retention policy; re-run to regenerate," never a broken or blank canvas pretending the data is
    still there.
11. **Payroll Summary detail visibility is re-checked per viewer, not per generator.** A run an HR Manager
    generated with `include_employee_detail: true` is later opened or exported by a Finance Manager lacking
    `reports.payroll.employee.read`; the canvas and every export format degrade to the aggregate-only view
    for that viewer, computed from the same frozen snapshot — the underlying run is never regenerated
    per-viewer, but employee-level rows are filtered at read/export time by the requesting user's own
    permission, exactly matching the accounting document's own multi-sheet-export conditioning.
12. **An Ask AI question spans categories the asker only partially holds.** ("Compare our sales pipeline to
    payroll cost as a percentage of revenue" from a Sales Manager without `reports.payroll.read`.) The agent
    answers the in-scope part and names precisely which part it is declining and why, rather than either a
    blanket refusal or a silent omission that could read as "there's no payroll cost."
13. **Draft-from-text requests a report over a data source the requesting role cannot access.** The server
    refuses to produce the draft at all (`POST /reports/draft-from-text` itself returns the permission-denied
    envelope), not merely to publish it later — a role never even sees a populated-but-unusable Builder draft
    referencing data it was never allowed to query.
14. **A direct link to `/reports/runs/[runId]` for a run whose report a role change has since put out of
    reach.** The page renders the shell's `403` boundary; any previously-cached client-side data for that run
    (from an earlier, still-permitted visit) is discarded on the permission check's failure rather than
    served from a stale local cache, per Principle 4's "never assume a permission snapshot is still valid."
15. **A user wants a literal browser print of this screen.** Not supported as a "print this page" action, for
    the same reason `DASHBOARD.md` declines it for its own screen: the Library's cards and the Builder's
    authoring chrome have no meaning on paper. The screen instead steers toward Export's PDF format, the only
    path producing a durable, re-downloadable, correctly light-themed artifact with real provenance.
16. **Duplicating a Standard Report, then QAYD later ships an updated template for that same Standard
    Report.** The duplicate is a fully independent `report_definitions` row from the moment of duplication —
    it has its own `version`/`parent_definition_id` chain rooted at the duplicate, and it never silently
    inherits a subsequent platform update to the original Standard Report's own `config`.
17. **A brand-new company with no scheduled reports, no favorites, and only the sixteen seeded Standard
    Reports.** Renders as a normal, fully-functional Library — every Standard Report card is runnable from
    day one — never an onboarding dead end or a "not enough data yet" error, since Standard Reports need no
    Custom Report authoring to exist.

# End of Document
