# Inventory Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: INVENTORY_SCREEN
---

# Purpose

This document is the structural screen specification for the Inventory module's landing route,
`app/(app)/inventory/items/page.tsx` — the page a Warehouse Employee, an Inventory Manager, a Purchasing
Manager, or a Finance Manager actually lands on the instant they click "Inventory" in the sidebar, since
`NAVIGATION_SYSTEM.md`'s primary-navigation module map points that entry (icon `Boxes`) directly at
`/inventory/items` rather than at a bare `/inventory` segment, which resolves to nothing (see
`# Edge Cases`). `FRONTEND_ARCHITECTURE.md`'s own committed App Router tree lists `inventory/items/page.tsx`
as a sibling of `products/`, `movements/`, `transfers/`, `counts/[countId]/`, and `warehouses/`, with no
`inventory/page.tsx` leaf of its own — this document does not introduce one; it specifies, in full, the
leaf the platform already treats as the module's front door. It is written against the platform's
`SCREEN DOC STRUCTURE` template so it can be read, reviewed, and diffed section-by-section alongside every
other document in this series — Dashboard, Accounting, Banking, Sales, Login, the AI Home screen, and, at
the deeper per-report tier, General Ledger, Journal Entries, Trial Balance, Balance Sheet, Profit & Loss,
Cash Flow, Bank Reconciliation, and the AI Command Center — using the identical fourteen headings.

It is the concrete, structured companion to `docs/frontend/INVENTORY.md`, which specifies this same route —
together with `movements/`, `transfers/`, and `counts/[countId]/`, and the routeless Adjustment `Dialog` — in
fuller, more discursive prose. The two documents must never disagree: where this one is silent,
`docs/frontend/INVENTORY.md`, `docs/accounting/INVENTORY.md`, `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`,
and `ACCESSIBILITY.md` govern, in that order; where this document appears to contradict one of them on a
fact — a route, a permission key, a component name, an endpoint, a worked figure — that is a defect in one
of the two documents to raise and resolve, never a decision an engineer resolves unilaterally in code. Every
concrete number used to illustrate this document (SKU codes, warehouse codes, adjustment/transfer/count
reference numbers) is reused verbatim from `docs/frontend/INVENTORY.md`'s own worked examples rather than
invented afresh, precisely so the two documents read as one continuous account of the same screens rather
than two documents that happen to describe similar things.

Every screen in Inventory answers a piece of the same question `docs/accounting/INVENTORY.md`'s own Purpose
section poses: **what do we have, where is it, and what is it worth** — for every role that touches physical
stock, from a Warehouse Employee scanning a carton at a receiving dock to a CFO reconciling the Inventory
control account at period close. This screen, the module's landing surface, is where that question is
answered first, at a glance, with the module's own operational alerts and AI-authored recommendations
surfaced ahead of anything a user has to go looking for. Concretely, this screen composes seven pieces of
surface, each owned elsewhere in the platform's data and business-logic layers and only ever rendered,
never computed, here:

- **A stock-on-hand table with a Valuation view** — the `inventory_items` projection rendered as a dense,
  filterable table, one row per `(product, warehouse, bin)`, showing every quantity bucket the backend
  maintains (on hand, reserved, on order, in transit, available to sell) and, behind a permission-gated view
  toggle, the base-currency value of that stock and the valuation method that produced it.
- **A KPI band** — Total Value, Below Reorder, Out of Stock, and Open Transfers, each a bounded aggregate
  call, never a client-side sum over the table beneath it.
- **A reorder / low-stock alert banner** — a condensed, actionable, deterministic read of exactly the SKUs
  the Below Reorder tile is counting, with a one-click path into the fully filtered table.
- **A full AI Inventory Insights panel** — the Inventory Manager agent's reorder suggestions, dead-stock
  clustering, ABC/XYZ classification notes, demand-forecast highlights, and rebalancing-transfer drafts,
  rendered in full here rather than condensed, for reasons `# AI Integration` states explicitly.
- **Quick entry points into Movements, Transfers, and Stock Counts** — a compact strip naming what is
  currently open or in progress in each of the module's other three screens, each a doorway rather than a
  re-implementation.
- **The Adjustment workflow's own launch point** — the header's and every row's "Adjust stock" action,
  opening the `StockAdjustmentDialog` that `docs/frontend/INVENTORY.md` specifies in full.
- **A filter bar scoping every region above the table** — warehouse, category, a below-reorder-only toggle,
  and search, mirrored to the URL so a filtered view is a shareable link.

This screen owns no business logic. It never computes `available_to_sell`, never decides whether an
adjustment clears the approval threshold, and never lets an AI agent's confidence score substitute for a
human's sign-off on a stock adjustment, a transfer, or a count closure. Every figure rendered here was
computed and validated by Laravel; every mutation this screen triggers calls `/api/v1/inventory/...`,
guarded by the exact permission key the API itself enforces (`FRONTEND_ARCHITECTURE.md`, Principles 1 and
4). Three properties, mirrored directly from `docs/frontend/INVENTORY.md`'s own founding principles, drive
every decision below: the frontend renders exactly the buckets the backend maintains and computes none of
them itself; every mutation is a workflow — a `Dialog`, a full page, or a dedicated workbench route — never
an editable table cell; and AI proposes, while humans and policy dispose.

Two audiences share this one screen, composed from the same components rather than built twice. A
**Warehouse Employee** uses it operationally: dense quantity columns, no Valuation view (withheld by
permission, not merely by default), and the Adjust stock / Transfer / Count entry points as their primary
daily surface. A **Finance Manager, CFO, Inventory Manager, or Auditor** uses it analytically: the Valuation
view, the Total Value tile, and the AI Insights panel's reorder and dead-stock economics are the reason they
open this screen at all, and every one of their approval affordances — for a pending Adjustment or Transfer
— surfaces inline here rather than only in the separate Approval Center. A **Sales or Purchasing** role sees
a narrower read of the same table (quantities, never cost, unless Purchasing), reflecting
`inventory.valuation.view`'s deliberate scoping in `docs/accounting/INVENTORY.md → Security`.

# Route & Access

## App Router path

Reproduced from `FRONTEND_ARCHITECTURE.md`'s own committed route tree, with `layout.tsx` made explicit — an
addition `docs/frontend/INVENTORY.md` already notes that tree's own excerpt "omitted for brevity," not a
fact this document introduces:

```
app/(app)/inventory/
├── layout.tsx                      # Sub-nav: Products | Items | Movements | Transfers | Stock Counts |
│                                    # Warehouses, gated on the parent inventory.read permission
├── products/
│   └── page.tsx                    # Owned by the future Products frontend doc — linked, not respecified
├── items/
│   └── page.tsx                    # ★ THIS DOCUMENT — the Inventory landing screen, + Valuation view toggle
├── movements/
│   └── page.tsx                    # Stock Movement History — docs/frontend/INVENTORY.md, linked from here
├── transfers/
│   ├── page.tsx                    # Transfers list — docs/frontend/INVENTORY.md, linked from here
│   └── new/
│       └── page.tsx                # Sensitive — always a full page, never a quick-create modal
├── counts/
│   └── [countId]/
│       └── page.tsx                # Stock Count workbench — docs/frontend/INVENTORY.md, linked from here
└── warehouses/
    └── page.tsx                    # Owned by the future Warehouses frontend doc — linked, not respecified
```

`items/page.tsx` is the only file this document specifies in depth. There is deliberately no
`inventory/page.tsx` at the module's own root segment — see `# Edge Cases` for what a bare `/inventory` hit
resolves to; every in-product link (the sidebar, any breadcrumb, any AI-surfaced deep link) points at
`/inventory/items` directly, exactly matching `NAVIGATION_SYSTEM.md`'s own primary-navigation module-map row
for Inventory ("Inventory | `Boxes` | `/inventory/items` | `inventory.read` | Owner, CEO, Finance Manager,
Inventory Manager, Warehouse Employee, Purchasing Manager"). Adjustments, per
`docs/frontend/INVENTORY.md → Route & Access`, have no route of their own anywhere in this tree at all — a
Stock Adjustment is created from a `Dialog` opened off this exact screen (or off Movements), never a
dedicated page, and a direct link to a specific pending one is still fully shareable without a persisted
route: `/inventory/items?adjust=771` opens this screen with the Adjustment `Dialog` pre-loaded for
`stock_adjustments.id = 771`.

## Breadcrumb

`Inventory` only — a single, linked crumb pointing at `/inventory/items` itself, mirroring
`docs/frontend/INVENTORY.md`'s framing of this exact route as "the module's default landing screen and the
one every other Inventory screen links back to." Drilling into Movements, Transfers, or a Stock Count
extends the trail to `Inventory / Movements`, `Inventory / Transfers`, or `Inventory / Count PHY-2026-000012`
respectively, with the leading `Inventory` crumb always resolving back here — never to the bare, page-less
`/inventory` segment.

## Permission gate

Every permission key below is defined once, authoritatively, in `docs/accounting/INVENTORY.md →
Permissions`; this table is this screen's own map of key to concrete UI effect on the landing route
specifically, not a redefinition, and it is a subset of the fuller table
`docs/frontend/INVENTORY.md → Route & Access` gives across all four Inventory screens.

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible at all | `inventory.read` | Sidebar's Inventory entry and every route in this tree do not render; a direct hit renders the shell-level `403` boundary, never a silent redirect. |
| Total Value tile, Valuation view toggle, unit cost anywhere on the table | `inventory.valuation.view` | Tile and toggle are **omitted**, not disabled — unit cost is commercially sensitive (`docs/accounting/INVENTORY.md → Security`), so its existence on this screen is itself gated, not merely its value. |
| "Adjust stock" (header action and every row action) | `inventory.adjust` | Action omitted from the header's `DropdownMenu` and from every row's own menu. |
| Approving a pending Adjustment inline | `inventory.adjust.approve` | The inline `ApprovalCard` renders its Approve/Reject pair disabled with a permission tooltip rather than omitted — the request is already addressed to a specific approver — and is additionally disabled outright when the caller is the same user who requested it. |
| "New transfer" (Workflow Strip's Transfers card) | `inventory.transfer.create` | Button omitted; the Transfers card itself, and its open-count figure, still render under the baseline `inventory.read`. |
| Approving a pending Transfer inline | `inventory.transfer.approve` | Same disabled-with-tooltip treatment as Adjustment approval. |
| "New count" (Workflow Strip's Stock Counts card) | `inventory.count.create` | Button omitted; the card's in-progress-count summary still renders under `inventory.read`. |
| Accepting an AI reorder-point suggestion | `inventory.settings.manage` | The AI panel's "Send for approval" action on a Stock Optimization card is disabled with a permission tooltip — the recommendation itself remains visible, since seeing that a suggestion exists is not sensitive. |
| Bulk import / export | `inventory.import` / `inventory.export` | Buttons omitted independently of each other from the header. |
| Serial/batch detail actions surfaced from a row's `Sheet` | `inventory.serial.manage` / `inventory.batch.manage` | Mutating actions omitted from the detail `Sheet`; its read-only history remains visible under ordinary `inventory.read`. |

**Reconciling the Transfers card's gate.** `NAVIGATION_SYSTEM.md`'s own sub-navigation table gates the
Transfers sub-item on `inventory.adjust`, which — read against `docs/accounting/INVENTORY.md`'s own Role
Grant Table — would be too narrow: Purchasing Manager and Purchasing Employee both hold
`inventory.transfer.create` but neither holds `inventory.adjust` at all. This document applies
`docs/frontend/INVENTORY.md`'s own resolution verbatim: the Transfers card's "New transfer" affordance is
gated on its true owning permission, `inventory.transfer.create`, not the nav map's shorthand annotation. A
role holding only `inventory.read` still sees the Transfers card and its open-count figure.

## Roles — what renders on this screen

Reproduced from `docs/accounting/INVENTORY.md → Permissions → Role Grant Table` and
`docs/frontend/INVENTORY.md → Route & Access → Roles`, translated into what a role concretely sees on
**this specific landing screen**:

| Role | What renders |
|---|---|
| Owner, Inventory Manager | The full screen: every KPI tile, the Valuation toggle, the complete AI panel with every action live, every row action, and both Workflow Strip "New" buttons. |
| CEO, CFO, Finance Manager | Full read plus Valuation; inline approval on a pending Adjustment; none of the three can create an Adjustment or a Transfer from this screen (no `inventory.adjust`/`inventory.transfer.create`) — they see the approval side of those queues only. Finance Manager alone among these three can also launch a new Stock Count. |
| Senior Accountant, Accountant, Auditor, External Auditor | Read-only across the whole screen, Valuation view included (unit cost is audit-relevant for these roles); every mutating control is omitted, never merely disabled. |
| Warehouse Employee | The hands-on operational view: full quantity columns, "Adjust stock" and "New transfer"/"New count" all live, but never the Valuation toggle or the Total Value tile (`inventory.valuation.view` withheld), and no AI-suggestion acceptance (`inventory.settings.manage` withheld). |
| Sales Manager, Sales Employee | Quantities only (no Valuation, no Total Value tile); no adjust/transfer/count creation; hold `inventory.reserve`/`.release` in the abstract, which manifests here only as a "Release reservation" action reachable from a row's detail `Sheet`. |
| Purchasing Manager, Purchasing Employee | Read plus Valuation (Purchasing needs landed cost to negotiate and three-way-match); can launch a new Transfer from the Workflow Strip; cannot create an Adjustment. |
| Read Only | The floor of this screen's surface: quantities, no Valuation, no mutating control anywhere. |
| AI service account (Inventory Manager agent) | Never renders this screen as a "user." Its scoped read access feeds the AI panel described in `# AI Integration`; it is structurally incapable of holding any `.approve`, `.dispatch`, `.receive`, or `.close` permission regardless of role grant — its own drafts appear in the ordinary human queues, visually tagged, never in a separate AI-only list. |

## Keyboard entry point

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `I` navigates to this screen from
anywhere in the app (landing on `/inventory/items`, exactly as a sidebar click would); `A`, scoped to this
route, opens the unscoped Adjustment `Dialog` (only rendered reachable when `inventory.adjust` is held — the
shortcut and the header button share one permission check, never two).

# Layout & Regions

Reference layout at Laptop (`lg`, 1024px) and up, inside the persistent app shell (`Sidebar`/`Topbar`, out
of this document's scope per `NAVIGATION_SYSTEM.md`) and, at `md:` and up, under `inventory/layout.tsx`'s own
six-tab sub-nav; see `# Responsive Behavior` for how each region reflows below this tier.

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Products   Items ●   Movements   Transfers   Stock Counts   Warehouses    [sub-nav tabs]  │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  Inventory                                        [Import] [Export ▾] [Adjust stock ▾]    │
│  1,204 SKUs across 4 warehouses                                                            │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐                                │
│ │ Total value │ │ Below       │ │ Out of     │ │ Open        │   KPI band (KpiTile × 4)     │
│ │ KD 218,406  │ │ reorder     │ │ stock      │ │ transfers   │                              │
│ │ ▲ 3.1%      │ │ 22 SKUs ⚠  │ │ 4 SKUs ⛔  │ │ 6           │                              │
│ └────────────┘ └────────────┘ └────────────┘ └────────────┘                                │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ⚠ 22 SKUs below reorder — WGT-100 Widget (18 left), FLT-220 Filter (12 left)… [Review ▾]   │  Alert banner
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌ AI · Inventory Manager ─────────────────────────────────────────────────────────────┐    │
│ │ ● 3 reorder suggestions ready (avg. 87% conf.) · 1 dead-stock cluster (KD 4,120)    │    │
│ │ ● Demand forecast: WGT-100 +18% next 30d · 1 rebalancing transfer drafted            │    │
│ │                                     [Review]        [Dismiss]        [View reasoning]│   │
│ └───────────────────────────────────────────────────────────────────────────────────────┘   │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  [Warehouse: All ▾] [Category: All ▾] [Below reorder only ☐] [Search…]  ● Stock on hand / Valuation │
│  ┌────────────────────────────────────────────────────────────────────────────────────┐    │
│  │ Product        Wh/Bin      On hand  Reserved  On order  In transit  Avail.  Status │    │
│  │ ──────────────────────────────────────────────────────────────────────────────────│    │
│  │ WGT-100 Widget  KWT-01/B-04    240      35         0          0       205    ● OK  │    │
│  │ FLT-220 Filter  KWT-01/A-12     18       6        50          0        12   ⚠ Low  │    │
│  │ PMP-310 Pump    FAH-02          0        0         0         40         0   ⛔ Out  │    │
│  │ …(virtualized rows past ~200 — cursor-paginated)…                                  │    │
│  └────────────────────────────────────────────────────────────────────────────────────┘    │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ Movements — view the full history →   Transfers — 6 open →   Stock Counts — 1 in progress →│  Workflow strip
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Products · Items (active) · Movements · Transfers · Stock Counts · Warehouses, real `<Link>`s per `inventory/layout.tsx` (Server Component) | Not streamed — part of the layout shell |
| Page header | `<h1>Inventory</h1>`, a one-line SKU/warehouse-count summary, and three permission-gated actions (`Import`, `Export`, `Adjust stock` — the last a `DropdownMenu` that opens the Adjustment `Dialog` pre-scoped to whichever row is currently selected, or unscoped if none is) | Renders immediately with the page shell |
| KPI band | Four `KpiTile`s: Total Value (Valuation-permission-gated), Below Reorder, Out of Stock, Open Transfers | Own `<Suspense>` — the new bounded `GET /inventory/items/summary` call (`# Data & State`) |
| Reorder / Low-Stock Alert banner | `ReorderAlertBanner`, a deterministic, non-AI read of the top few `needs_reorder = true` SKUs, with a "Review" action that applies `filter[below_reorder]=true` to the table below | Own `<Suspense>`, independent of the KPI band and the table |
| AI Inventory Insights panel | A dismissible `AIProposalPanel`-family surface synthesizing the Inventory Manager agent's reorder/dead-stock/demand-forecast/rebalancing-transfer output — omitted entirely without an AI-visibility scope | Own `<Suspense>`; independently failable without blanking the rest of the page |
| Filter bar | `WarehouseBinPicker` (warehouse only, bin unscoped at this level), a category `Select`, a "Below reorder only" `Switch`, `Input` search, and the **Stock on hand / Valuation** view toggle (`Tabs`, `size="sm"`) — the toggle itself is omitted, not disabled, without `inventory.valuation.view` | Not streamed — client-side filter state feeding the table query |
| Main grid | `InventoryItemsTable` (from `docs/frontend/INVENTORY.md → Components Used`), a preset `DataTable` — the identical component, columns, and both view presets that document specifies in full | Own `<Suspense>` — `GET /inventory/items` |
| Workflow Strip | `InventoryWorkflowStrip` (**new**, `# Components Used`) — three compact cards naming what is open in Movements, Transfers, and Stock Counts, each a doorway into the screen `docs/frontend/INVENTORY.md` specifies for it | Own `<Suspense>` — reads the same summary payload as the KPI band |

**The reorder alert banner and the AI panel are deliberately two different regions, not one.** The banner is
a plain, deterministic navigation aid — it states a count and a fact the backend's own maintained
`needs_reorder` boolean already computed (`docs/accounting/INVENTORY.md → Performance`), with no confidence
score and no AI provenance styling, because "22 SKUs are below their configured reorder point" is not an AI
opinion, it is a fact read off an index. The AI panel beneath it is where the Inventory Manager agent's own
recommendation about **what to do** about that fact lives — a specific reorder quantity, a vendor, a
confidence score, and an Approve/Dismiss pair. Collapsing the two into one card would quietly imply that a
plain fact and an AI-generated recommendation carry the same epistemic weight, which
`docs/frontend/INVENTORY.md → AI Integration`'s own confidence-and-reasoning discipline exists specifically
to avoid.

**The AI Inventory Insights panel shown inline above is part of this screen's own regular content, not the
platform-wide global AI Rail.** It is the identical region `docs/frontend/INVENTORY.md → Layout & Regions →
AI panel (all four screens)` describes for the Items screen specifically ("Contents differ by screen:
reorder suggestions and dead-stock/ABC-XYZ on Items"), always present at `lg:` and up regardless of
breakpoint, reflowing per `# Responsive Behavior` rather than disappearing. This is a distinct concept from
`LAYOUT_SYSTEM.md`'s fourth shell region — the platform-wide **global AI Rail** companion panel every screen
in the product carries (a persistent 360px rail at `3xl:` and above, an overlay `Sheet` below it) — mirroring
the exact disambiguation `docs/frontend/DASHBOARD.md → Layout & Regions` draws between its own AI Summary
Rail column and that same global companion panel: "a companion to this page, not a duplicate." When the
global AI Rail is additionally docked at `3xl:`, it shows the identical Inventory Manager queue filtered to
this screen's own scope — never a second, disagreeing copy of the content already inline in the page.

Each region above streams independently behind its own `<Suspense>` boundary per
`FRONTEND_ARCHITECTURE.md → Streaming with Suspense`: a slow AI-synthesis call for the Insights panel never
delays the KPI band, the alert banner, or the table from painting, and a failure in one region renders only
that region's own error state without taking the rest of the page down with it (`# States`).

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` and `docs/frontend/INVENTORY.md → Components
Used` as-is — this screen introduces exactly one new composed component (`InventoryWorkflowStrip`) and one
new bounded hook (`useInventorySummary`, `# Data & State`); everything else is reuse, never a parallel
implementation of a table, a money cell, or a confidence indicator that already exists.

| Component | Source | Role on this screen |
|---|---|---|
| `Tabs` | Primitive | Sub-nav (Products/Items/Movements/Transfers/Stock Counts/Warehouses); Stock-on-hand/Valuation view toggle |
| `KpiTile` | Finance (`components/dashboard/kpi-tile.tsx`) | Total Value, Below Reorder, Out of Stock, Open Transfers |
| `TrendSparkline` | Finance | 30-day on-hand-value trend inside the Total Value tile |
| `ReorderAlertBanner` | `components/inventory/reorder-alert-banner.tsx` (owned by `docs/frontend/INVENTORY.md`) | The below-reorder/out-of-stock summary banner, reused verbatim; shares visual language with the Dashboard's own "Inventory Alerts" widget and the AI Command Center's identical widget, condensed here to the SKUs actually visible on this screen |
| `InventoryItemsTable` | `components/inventory/inventory-items-table.tsx` (owned by `docs/frontend/INVENTORY.md`) | The main grid, both the Stock on hand and Valuation column presets, reused without modification |
| `QuantityCell` | `components/inventory/quantity-cell.tsx` (owned by `docs/frontend/INVENTORY.md`) | Every quantity-bucket cell in the main grid |
| `AmountCell` | Finance (`components/accounting/amount-cell.tsx`) | Every base-currency value cell — the Total Value tile, the Valuation view's Unit Cost/Total Value columns |
| `CurrencyTag` | Finance (`components/accounting/currency-tag.tsx`) | Base-currency annotation wherever a figure could be read as ambiguous across currencies |
| `StatusPill` | Finance (`components/shared/status-pill.tsx`), domain `stock_item` | Availability state (Available/Low/Out) on every row |
| `WarehouseBinPicker` | `components/inventory/warehouse-bin-picker.tsx` (owned by `docs/frontend/INVENTORY.md`) | The Filter bar's Warehouse scoping control |
| `ValuationLayersSheet` | `components/inventory/valuation-layers-sheet.tsx` (owned by `docs/frontend/INVENTORY.md`) | A row's "View cost layers" drill-down, Valuation view only |
| `StockAdjustmentDialog` | `components/inventory/stock-adjustment-dialog.tsx` (owned by `docs/frontend/INVENTORY.md`) | The Adjustment workflow launched from the header or any row |
| `AIProposalPanel` | AI (`components/ai/ai-proposal-panel.tsx`) | The AI Inventory Insights panel's reorder-suggestion, dead-stock, and rebalancing-transfer cards |
| `ConfidenceBadge` | AI (`components/ai/confidence-badge.tsx`) | Every AI-authored figure on this screen: reorder-suggestion confidence, ABC/XYZ classification confidence, demand-forecast confidence |
| `ApprovalCard` | Shared (`components/shared/approval-card.tsx`), `kind="stock_adjustment"` / `kind="stock_transfer"` | Any adjustment/transfer awaiting the caller's own approval, surfaced inline above the table |
| `Sheet` | Primitive | Item detail (buckets, valuation, aging, serials/batches), `ValuationLayersSheet`, filter overflow below `md:`, the global AI Rail's overlay presentation |
| `DropdownMenu` | Primitive | Header actions, every row's action menu |
| `Dialog` / `AlertDialog` | Primitive | Adjustment workflow, discard/cancel confirmations |
| `Skeleton` | Primitive | Per-region loading placeholders (`# States`) |
| `EmptyState` / `ErrorState` | Shared | Zero-items onboarding, filtered-empty table, per-region fetch failure |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `Badge` | Primitive | ABC/XYZ classification chips, AI-provenance tags |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, mapped from the API envelope |
| `InventoryWorkflowStrip` | `components/inventory/inventory-workflow-strip.tsx` (**new**, this document) | The three-card Movements/Transfers/Stock Counts entry-point strip |

## `InventoryWorkflowStrip`

The one genuinely new component this screen introduces — a thin, read-mostly composition over existing
primitives (`Card`, `Button`, `PermissionGate`, `Link`) rather than a new table, form, or data-fetching
pattern, matching `COMPONENT_LIBRARY.md`'s stated contract that a screen-specific composed component "never
introduces a new design token, a new API shape, or a new permission key." It exists so that a user who lands
here never has to already know that Movements, Transfers, and Stock Counts exist as separate screens — the
strip names what is currently true in each and hands off a single click into `docs/frontend/INVENTORY.md`'s
own fully specified screen for it:

```tsx
// components/inventory/inventory-workflow-strip.tsx
'use client';

import Link from 'next/link';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { PermissionGate } from '@/components/shared/permission-gate';
import { ArrowLeftRight, ScanBarcode, History } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { InventorySummary } from '@/types/inventory';

interface InventoryWorkflowStripProps {
  summary: Pick<InventorySummary, 'openTransfersCount' | 'activeCountsCount' | 'movementsTodayCount'>;
  activeCountId: number | null;
}

export function InventoryWorkflowStrip({ summary, activeCountId }: InventoryWorkflowStripProps) {
  const t = useTranslations('inventory.workflowStrip');
  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
      <Card className="flex items-center justify-between p-4">
        <div className="flex items-center gap-2">
          <History className="size-4 text-ink-500" aria-hidden="true" />
          <span className="text-sm">{t('movementsToday', { count: summary.movementsTodayCount })}</span>
        </div>
        <Link href="/inventory/movements" className="text-sm font-medium text-accent">
          {t('viewHistory')} →
        </Link>
      </Card>
      <Card className="flex items-center justify-between p-4">
        <div className="flex items-center gap-2">
          <ArrowLeftRight className="size-4 text-ink-500" aria-hidden="true" />
          <span className="text-sm">{t('openTransfers', { count: summary.openTransfersCount })}</span>
        </div>
        <PermissionGate permission="inventory.transfer.create">
          <Button asChild size="sm" variant="ghost">
            <Link href="/inventory/transfers/new">{t('newTransfer')}</Link>
          </Button>
        </PermissionGate>
      </Card>
      <Card className="flex items-center justify-between p-4">
        <div className="flex items-center gap-2">
          <ScanBarcode className="size-4 text-ink-500" aria-hidden="true" />
          <span className="text-sm">
            {activeCountId
              ? t('countInProgress', { count: summary.activeCountsCount })
              : t('noActiveCounts')}
          </span>
        </div>
        {activeCountId ? (
          <Link href={`/inventory/counts/${activeCountId}`} className="text-sm font-medium text-accent">
            {t('resume')} →
          </Link>
        ) : (
          <PermissionGate permission="inventory.count.create">
            <Button asChild size="sm" variant="ghost">
              <Link href="/inventory/counts">{t('newCount')}</Link>
            </Button>
          </PermissionGate>
        )}
      </Card>
    </div>
  );
}
```

Two properties are load-bearing, not incidental to the illustration: first, every figure the strip renders
(`movementsTodayCount`, `openTransfersCount`, `activeCountsCount`, `activeCountId`) comes from the same
bounded `GET /inventory/items/summary` payload the KPI band already fetched (`# Data & State`) — the strip
performs no fetch of its own and computes nothing, consistent with `# Purpose`'s founding rule. Second,
every action the strip exposes is a `<Link>` to a real, bookmarkable route or an existing `Dialog`/workbench
`docs/frontend/INVENTORY.md` already specifies — the strip itself never renders a table, a form, or a
lifecycle action inline, exactly the discipline `docs/frontend/DASHBOARD.md`'s own `RecentActivityFeed` and
`QuickActionsBar` apply ("a lightweight, non-virtualized list... never a full `DataTable`").

## Icon usage

No new icon is introduced on this screen beyond what `ICONOGRAPHY.md` and `docs/frontend/INVENTORY.md → Icon
additions` already assign: `Boxes` (module, sub-nav), `TriangleAlert` (below-reorder warning, reused on the
alert banner), `OctagonAlert` (out-of-stock, reused on the KPI tile and `StatusPill`), `ArrowLeftRight`
(Transfers, reused on the Workflow Strip's Transfers card — and, per that document's RTL note, this icon
never mirrors under `dir="rtl"`), `ScanBarcode` (Stock Counts, reused on the Workflow Strip's Stock Counts
card), and `History` (**new row, this document** — Movements' own entry point on the Workflow Strip; a
generic "past events" glyph distinct from `ScanBarcode`'s "counting action" and `ArrowLeftRight`'s
"relocation" meanings, added to `ICONOGRAPHY.md`'s semantic table in the same PR per that document's own
contribution rule).

# Data & State

## Endpoints

Every path below except one is declared verbatim in `docs/accounting/INVENTORY.md → API` and
`docs/frontend/INVENTORY.md → Data & State`, versioned under `/api/v1/inventory/`, Bearer-authenticated,
scoped via `X-Company-Id`, and returning the platform's standard envelope (`success`, `data`, `message`,
`errors`, `meta`, `request_id`, `timestamp`).

| Method | Path | Permission | Used by |
|---|---|---|---|
| GET | `/inventory/items/summary` | `inventory.read` (`total_value_base`/`total_value_delta_pct_30d` additionally require `inventory.valuation.view`, omitted from the response rather than zeroed for a caller lacking it) | **This document's own minimal extension** — the KPI band's four tiles and the Workflow Strip's three figures, in one bounded call |
| GET | `/inventory/items` | `inventory.read` | `InventoryItemsTable`, both view presets |
| GET | `/inventory/items/{id}` | `inventory.read` (+ `inventory.valuation.view` for nested layer/cost fields) | Item detail `Sheet`, `ValuationLayersSheet` |
| POST | `/inventory/adjustments` | `inventory.adjust` | `StockAdjustmentDialog` submit (draft) |
| POST | `/inventory/adjustments/{id}/approve` | `inventory.adjust.approve` | `ApprovalCard`, `kind="stock_adjustment"`, surfaced inline on this screen |
| GET | `/inventory/adjustments` | `inventory.adjust` (own) or `inventory.adjust.approve` (all pending) | Backs the inline "pending approval" queue this screen's `ApprovalCard`s render above the table |
| GET | `/inventory/transfers` | `inventory.read` | The Workflow Strip's open-transfers count |
| POST | `/inventory/transfers/{id}/approve` | `inventory.transfer.approve` | `ApprovalCard`, `kind="stock_transfer"`, surfaced inline on this screen |
| GET | `/inventory/counts` | `inventory.read` | The Workflow Strip's active-count summary and `activeCountId` |
| GET | `/inventory/search` | `inventory.read` | The global `CommandPalette`'s Records group, and this screen's own barcode/serial/batch quick lookup |
| POST | `/inventory/import` | `inventory.import` | Header's "Import" action |
| GET | `/inventory/export` | `inventory.export` | Header's "Export" menu |
| POST | `/inventory/items/{id}/accept-reorder-suggestion` | `inventory.settings.manage` | AI panel's "Send for approval" on a Stock Optimization card |

**This document's own minimal, necessary extension.** `GET /inventory/items/summary` is implied by this
screen's own KPI band and Workflow Strip but is not individually named in
`docs/accounting/INVENTORY.md → API` — it follows the exact bounded-bundle convention
`docs/frontend/DASHBOARD.md → Data & State` already establishes for its own `GET /api/v1/dashboard`
bootstrap call ("each independently Redis-cached under its own key so one tile's invalidation never
discards the other seven"), applied here to Inventory's four KPI figures plus the three Workflow Strip
counts, rather than requiring the landing screen to issue four to seven separate list requests purely to
derive aggregate counts the backend's own indexes (`needs_reorder`, `stock_status`) already maintain.

Sample response:

```json
{
  "success": true,
  "data": {
    "total_value_base": "218406.3000",
    "total_value_currency": "KWD",
    "total_value_delta_pct_30d": 3.1,
    "below_reorder_count": 22,
    "out_of_stock_count": 4,
    "open_transfers_count": 6,
    "active_counts_count": 1,
    "active_count_id": 12,
    "movements_today_count": 41,
    "below_reorder_preview": [
      { "product_id": 4501, "sku": "WGT-100", "name_en": "Widget", "quantity_on_hand": "18.0000", "reorder_point": "25.0000" },
      { "product_id": 4522, "sku": "FLT-220", "name_en": "Filter", "quantity_on_hand": "12.0000", "reorder_point": "20.0000" }
    ]
  },
  "message": "Inventory summary",
  "errors": [],
  "meta": {},
  "request_id": "a1b2c3d4-5e6f-4708-8899-0a1b2c3d4e5f",
  "timestamp": "2026-07-18T07:05:00Z"
}
```

A caller lacking `inventory.valuation.view` receives the identical envelope with `total_value_base`,
`total_value_currency`, and `total_value_delta_pct_30d` omitted from `data` entirely — never present with a
`null`/`0` placeholder — so the Total Value `KpiTile` has no code path that could momentarily flash a zero
value before a client-side permission check hides it; the field's absence is itself the permission gate,
matching `# Route & Access`'s "omitted, not disabled" rule for this exact figure.

## Filters

Every filter on this screen's own table uses the platform's bracketed filter grammar
(`docs/api/API_FILTERING_SORTING.md`), reused verbatim from `docs/frontend/INVENTORY.md → Data & State →
Filters`: `filter[warehouse_id]`, `filter[category_id]`, `filter[below_reorder]` (the boolean shortcut the
alert banner's "Review" action and the filter bar's own `Switch` both set to `true`, served by the backend's
partial index on `needs_reorder`), and `q` powering the table's own search box against `SEARCHABLE_FIELDS`
(SKU, product name in both languages, barcode). `GET /inventory/items/summary` accepts the identical
`warehouse_id` scoping parameter as the main table, so switching the Filter bar's warehouse re-derives the
KPI band and Workflow Strip for that warehouse alone rather than leaving them showing a company-wide figure
beside a now-scoped table.

## Query keys, hooks

```ts
// lib/api/query-keys.ts — extends docs/frontend/INVENTORY.md's own inventoryKeys factory
export const inventoryKeys = {
  // items, movements, adjustments, transfers, counts, ai — declared in full in docs/frontend/INVENTORY.md
  summary: (filters: { warehouseId: number | null }) =>
    ['inventory', 'summary', filters] as const,
};
```

```ts
// lib/api/hooks/use-inventory-summary.ts
export function useInventorySummary(warehouseId: number | null) {
  return useQuery({
    queryKey: inventoryKeys.summary({ warehouseId }),
    queryFn: () =>
      api.get<InventorySummary>('/inventory/items/summary', {
        params: { warehouse_id: warehouseId },
      }),
    staleTime: 10_000, // "Live/derived figures" tier — identical to useInventoryItems, docs/frontend/INVENTORY.md
    placeholderData: keepPreviousData,
  });
}
```

`staleTime: 10_000` mirrors `docs/frontend/INVENTORY.md → Data & State`'s own stated rationale for
`useInventoryItems` verbatim: `inventory_items` is a Redis-cached, trigger-maintained projection whose own
backing cache already carries a sub-second invalidation window, so a second, slightly looser client-side
staleness window on top of it avoids a redundant round trip on every render without ever showing data
meaningfully behind reality — and Realtime, below, force-invalidates immediately regardless of this window.

## Realtime

This screen subscribes to the identical channel `docs/frontend/INVENTORY.md → Data & State → Realtime`
already documents — no new channel name is introduced:

```ts
// lib/api/hooks/use-inventory-realtime.ts (reused verbatim from docs/frontend/INVENTORY.md)
'use client';
import { useEffect, useState } from 'react';
import { echo } from '@/lib/realtime/echo';

export function useInventoryRealtime(companyId: string, warehouseId: number | null) {
  const [hasNewActivity, setHasNewActivity] = useState(false);

  useEffect(() => {
    const channelName = warehouseId
      ? `company.${companyId}.inventory.${warehouseId}`
      : `company.${companyId}.inventory`;
    const channel = echo.private(channelName);
    const bump = () => setHasNewActivity(true);
    channel
      .listen('.inventory.stock_received', bump)
      .listen('.inventory.stock_sold', bump)
      .listen('.inventory.adjusted', bump)
      .listen('.inventory.reorder_point_reached', bump)
      .listen('.inventory.backorder_available', bump)
      .listen('.inventory.batch_expired', bump);
    return () => { echo.leave(channelName); };
  }, [companyId, warehouseId]);

  return { hasNewActivity, dismiss: () => setHasNewActivity(false) };
}
```

On this specific screen, `hasNewActivity` drives the same non-disruptive "New activity — Refresh" banner
`# States` describes and additionally, on `.inventory.reorder_point_reached`, silently invalidates
`inventoryKeys.summary(...)` so the KPI band's Below Reorder tile and the alert banner catch up the moment
the event lands — a realtime push never splices a row into the table a user is mid-scroll through, and it
never re-sorts or re-numbers rows already on screen, matching that document's own rule verbatim.

## AI agents feeding this screen

| Agent | Role on this screen | Autonomy |
|---|---|---|
| Inventory Manager | Reorder-point/quantity suggestions, dead-stock ranking, ABC/XYZ classification, rebalancing-transfer drafts — the AI Insights panel's primary content | Suggest-only or requires-approval, per action (`# AI Integration`) |
| Forecast Agent | The demand-forecast highlight inside the AI Insights panel and the Total Value tile's `TrendSparkline` | Suggest-only |
| Fraud Detection | Not primary content here — shrinkage/duplicate-serial flags surface on Movements and a row's Serials tab, linked from this screen, never duplicated on it | Suggest-only, routes to Auditor |
| Auditor | Shrinkage investigation context on flagged rows, reached via the same links | Suggest-only |

The Warehouse Optimization capability's *re-slotting* recommendations are, per
`docs/frontend/INVENTORY.md → Data & State`, deliberately not rendered anywhere in the Inventory frontend
today — that surface belongs to the future Warehouses document. Its *rebalancing-transfer* output, by
contrast, belongs on this screen's AI panel in full, because a rebalancing recommendation resolves into an
ordinary `stock_transfers` row that flows through the identical Workflow Strip → Transfers screen a
human-authored transfer does.

## Client state ownership

Consistent with `FRONTEND_ARCHITECTURE.md → State Management`:

| State | Owner |
|---|---|
| The summary bundle, the item list (both view presets), pending adjustment/transfer approvals | TanStack Query cache, keyed as above and in `docs/frontend/INVENTORY.md → Data & State` |
| Warehouse/category filter selection, below-reorder-only toggle, the Stock-on-hand/Valuation view | URL search params (`?warehouse=3&category=12&below_reorder=true&view=valuation`) — a filtered, scoped view of this screen is always a shareable link, never local-only `useState` |
| Which AI Insights card the user dismissed this session | `sessionStorage`, keyed per card id — a dismissed suggestion does not reappear on a refresh within the same session, but is never permanently hidden without an explicit "don't show again" the AI panel's own settings expose |
| The Adjustment `Dialog`'s in-progress form values | React Hook Form's internal state, schema-validated by `stockAdjustmentSchema`, scoped to the `Dialog`'s own lifetime |
| List density, column visibility | Zustand (`useDensityStore`, per-table-keyed) + `users.settings` sync |
| A mid-adjustment draft's `Idempotency-Key` | `sessionStorage`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` |

## Mutations — the one this screen's header and rows initiate directly

The Adjustment create mutation is reused verbatim from `docs/frontend/INVENTORY.md → Data & State →
useAdjustStock` and is not reproduced here to avoid two documents diverging on the same hook's
implementation; its `onSuccess` toast branches on `status` (`pending_approval` above the company's
configured threshold, auto-posted below it), and it invalidates both `inventoryKeys.items.all` and, on this
screen specifically, `inventoryKeys.summary(...)` so the KPI band's Below Reorder/Out of Stock tiles and the
alert banner reflect the adjustment's effect without a manual refresh:

```ts
// hooks/inventory/use-inventory-summary-invalidation.ts — this screen's own addition
export function useAdjustStockWithSummaryRefresh() {
  const queryClient = useQueryClient();
  const base = useAdjustStock(); // docs/frontend/INVENTORY.md's own hook, imported not reimplemented
  return {
    ...base,
    mutate: (payload: CreateAdjustmentPayload) =>
      base.mutate(payload, {
        onSuccess: () => queryClient.invalidateQueries({ queryKey: inventoryKeys.summary({ warehouseId: null }) }),
      }),
  };
}
```

Every other mutation surfaced on this screen (approving a pending Adjustment or Transfer, accepting a
reorder suggestion) is the identical hook `docs/frontend/INVENTORY.md → Data & State` already defines,
invoked from this screen's own `ApprovalCard`/`AIProposalPanel` instances rather than re-implemented — this
document adds no new mutation shape beyond the summary-invalidation wrapper above.

# Interactions & Flows

**Landing on the screen.** A sidebar click on "Inventory," a `G I` shortcut, or a bookmarked
`/inventory/items` link all resolve to the identical first paint: the sub-nav and page header render
immediately from the shell, then the KPI band, alert banner, AI panel, table, and Workflow Strip each settle
independently behind their own `<Suspense>` boundary as `# Layout & Regions` describes. Unlike a selection-
gated screen such as Bank Reconciliation, this screen never requires a choice before it fetches — it defaults
to an unscoped, company-wide, paginated table, because a company's total SKU count is bounded and browsable
in a way a multi-year movement history is not; the Warehouse/Category filters narrow an already-rendering
table rather than gating whether it renders at all.

**Reading the KPI band.** Each `KpiTile`'s `onClick` drills into the record set behind its number, mirroring
`docs/frontend/DASHBOARD.md`'s identical rule that a KPI number always means "go look at the source," never
"expand in place": Total Value → the table's own Valuation view (`?view=valuation`); Below Reorder →
`?below_reorder=true` on the same table; Out of Stock → `?filter[stock_status]=out`; Open Transfers →
navigates to `/inventory/transfers`. No click ever opens a modal over this screen.

**Acting on the reorder alert banner.** The banner's "Review" action applies the identical
`?below_reorder=true` filter the Below Reorder tile's click does — the two controls are two doorways into
the same state, never two different filters that could disagree. The banner is dismissible for the current
session only (`sessionStorage`); it reappears on the next visit or the moment a new SKU crosses its reorder
point, since a below-reorder condition is an operational fact, not a notification a user "read."

**Toggling Stock on hand ↔ Valuation.** Reused verbatim from `docs/frontend/INVENTORY.md → Interactions &
Flows`: toggling the view swaps `InventoryItemsTable`'s column set and adds `include=valuation` to the
identical `GET /inventory/items` request; the URL reflects it as `?view=valuation` so the state survives a
refresh or a shared link. Switching to Valuation without `inventory.valuation.view` is not reachable at all
— the toggle itself does not render.

**Adjusting stock from this screen.** A row's "Adjust stock" action, or the header's unscoped equivalent,
opens `StockAdjustmentDialog` exactly as `docs/frontend/INVENTORY.md` specifies; on success, this screen's
own `useAdjustStockWithSummaryRefresh` wrapper (`# Data & State`) additionally refreshes the KPI band and
alert banner so a Warehouse Employee who just corrected a shortage sees the Below Reorder count drop (or the
Out of Stock count clear) without a manual reload.

**Launching a new Transfer or Stock Count from the Workflow Strip.** "New transfer" navigates to the
full-page `transfers/new/page.tsx`; "New count" navigates to the Stock Counts index, where the count-creation
`Dialog` `docs/frontend/INVENTORY.md` specifies opens. Neither ever renders inline on this landing screen —
both are, per that document, sensitive-adjacent or long-running enough to warrant their own page/workbench,
and this screen's job is only to be the doorway, never a second, partial implementation of either flow.

**Acting on an AI Insights card.** "Send for approval" on a Stock Optimization (reorder-point) card opens the
target item's Settings edit fields pre-filled with the suggested values for a human holding
`inventory.settings.manage` to confirm or tune before calling
`POST /inventory/items/{id}/accept-reorder-suggestion` — there is no one-click "Do it" path for this specific
action regardless of confidence, because promoting a reorder point is a standing policy change, not a
one-time transaction (`docs/accounting/INVENTORY.md → AI Responsibilities`). A Dead Stock card's
recommendation links out to the affected SKU's row on this same table (scrolled into view and briefly
highlighted) rather than performing any action of its own. A rebalancing-transfer draft's card opens the
already-created `stock_transfers` row (created at `status='draft'`, `auto_generated=true`) on the ordinary
Transfer detail page, tagged with a small AI-provenance `Badge`, never a parallel AI-exclusive approval
control. An ABC/XYZ classification never appears as a card at all — it is a plain `Badge` next to the
product name on the table, with no Accept/Reject affordance, per `# AI Integration`.

**Searching and filtering.** Free-text search (`q`) and every filter debounce at 300ms before triggering a
new query key, with `placeholderData: keepPreviousData` on the table, the KPI band, and the alert banner
alike, so a filtered view never flashes blank on every keystroke.

# AI Integration

Every AI-authored figure or affordance on this screen carries a `confidence` (0–1, via `normalizeConfidence`)
and a `reasoning` string, per the platform-wide rule that no card shows a bare number the AI computed without
showing its work; below 60% confidence, the corresponding suggestion is visibly marked low-confidence and
excluded from any automatic calculation it would otherwise feed, exactly as
`docs/accounting/INVENTORY.md → AI Responsibilities` specifies for Demand Forecasting.

**This screen renders the Inventory Manager agent's full surface, not a condensed teaser.** This is a
deliberate, explicit departure from `docs/frontend/DASHBOARD.md`'s own AI Summary Rail, which caps itself at
three condensed cards specifically because Dashboard is the *company-wide* home screen answering "where do
the numbers stand," with AI narration appearing only as an annotation on top of that overview. This screen is
the *module's own* operational home, and its whole reason for existing includes "what should I restock, and
what is quietly going dead" — questions only the Inventory Manager agent can answer — so its AI panel is the
same undiluted surface `docs/frontend/INVENTORY.md → Layout & Regions` already specifies for the Items
screen, not a three-card summary that links elsewhere for the full picture.

| Capability | Where it renders here | Autonomy | Confidence handling |
|---|---|---|---|
| Demand Forecasting | The AI panel's forecast highlight line, and the Total Value tile's `TrendSparkline` | Suggest-only | Below 0.6: "low confidence — insufficient history" badge; excluded from the reorder-suggestion calculation entirely |
| Stock Optimization (reorder point/quantity) | `AIProposalPanel` card in the AI panel | Suggest-only; writes to `suggested_reorder_point` only, never live `reorder_point`, until a human accepts | Shown alongside the Demand Forecast confidence it inherits from |
| Dead Stock Detection | A ranked-cluster card in the AI panel: product(s), days since last sale, tied-up value | Suggest-only | High confidence at 2× the configured dead-stock threshold with zero sales; moderate near the threshold itself, worded as "slowing," not "dead" |
| ABC Analysis / XYZ Analysis | A small `Badge` next to the product name in the table (both views) | Auto-write (label only, no ledger effect) — no Accept/Reject UI | ABC shown with its % of total value; XYZ shown with its coefficient of variation |
| Warehouse Optimization — rebalancing transfers | `AIProposalPanel` card, resolving into an ordinary Transfer detail page | Suggest-only; the resulting draft transfer requires the identical human approval any transfer needs | Confidence tied to the statistical significance of the pick-frequency/rebalancing sample |
| Reorder Prediction | A "View draft request" link from a Stock Optimization card into the Purchasing queue | Requires-approval, unless a company-configured auto-approve threshold is set | Confidence reflects forecast + vendor lead-time variance |

Shrinkage Detection, Fraud Detection, and Anomaly Detection are deliberately **not** surfaced on this
screen's own AI panel — per `docs/frontend/INVENTORY.md → AI Integration`, those capabilities render as
row-level flags on Movements and a serial's detail tab, screens this document links to rather than
duplicates. Manufacturing a second copy of a Movements-scoped flag here would violate the same "a screen
never manufactures a UI slot for an agent output that belongs to a screen it does not own" discipline that
document itself states for Warehouse Optimization's re-slotting output.

**Unavailable AI.** If any AI call fails, times out, or AI is disabled for the company, the AI Insights panel
collapses to a quiet "AI insights unavailable right now" note and every ABC/XYZ `Badge` simply does not
render; the KPI band, the alert banner, the table, and the Workflow Strip remain fully available, since none
of them depend on the AI engine. AI is strictly additive on this screen, never a gate on anything a Warehouse
Employee or Finance Manager needs to do their job.

**AI provenance is always visually distinct from a human action, never color-coded as good or bad.** Every
AI-authored row (`auto_generated=true`) reachable from this screen carries a small `Sparkles`-accompanied
`Badge` using the reserved `--ai-accent` token (`# Dark Mode`) next to its status `StatusPill` — a viewer can
always tell "a person raised this" from "the Inventory Manager agent raised this," without that distinction
ever implying the AI-raised one is more or less trustworthy.

**Sensitive actions never terminate on this screen without a human decision.** Accepting a reorder
suggestion, approving a pending Adjustment, and approving a pending Transfer each require the caller's own
permission and a real Approve/Reject action; nothing on this screen's AI panel exposes a "Do it" affordance
for any of these, matching `docs/accounting/INVENTORY.md → AI Responsibilities`'s explicit framing that
promoting a reorder point is "pending Inventory Manager approval," never `auto`.

# States

Every region defined in `# Layout & Regions` has its own loading, empty, and error presentation; no region
blocks another, and none ever renders a bare spinner as its primary loading state.

| Region | Loading | Empty | Error |
|---|---|---|---|
| KPI band | `Skeleton` tiles matching the final `KpiTile` layout, shimmer sweep | A brand-new company with no stock ever received still renders the four tiles at zero — never hidden — since the tiles themselves are not permission-gated at the field level (only Total Value is) | Inline retry card per failed tile; the other three remain interactive |
| Reorder / Low-Stock banner | A single skeleton line at the banner's own height | "Nothing below reorder right now" — the one empty state on this screen that is good news, worded as such rather than reusing generic "no results" copy | Banner collapses to nothing rather than showing a broken partial state; the KPI band's own Below Reorder tile remains the fallback source of truth |
| AI Insights panel | Skeleton cards matching the panel's own final card count | Calm "No open AI insights right now" message, explicitly not styled as an error or a warning | A distinct "AI insights are temporarily unavailable" state (not a generic error, not an infinite spinner), respecting any `Retry-After` header |
| Filter bar + main table | `Skeleton` shaped to the table's own column geometry, shimmer sweep; never a generic spinner | "No stock tracked yet — add a product and receive stock against a purchase order to see it here" (true empty), distinct from "No items match your filters" (filtered-to-zero, with a "Clear filters" action) | `ErrorState` with a retry action |
| Workflow Strip | Three skeleton cards | Each card renders its own zero-state text ("No movements today," "No open transfers," "No active counts") rather than disappearing — the strip's whole purpose is to always answer "what's happening elsewhere," including "nothing" | Each card fails independently since all three read the same summary payload; a summary-fetch failure collapses all three to a single retry row rather than three separate broken cards |

Two cross-cutting states apply across every region above, reused verbatim from
`docs/frontend/INVENTORY.md → States`:

- **Realtime new activity.** A `stock_movements`-producing event landing in the currently-scoped warehouse
  surfaces as a dismissible, non-disruptive "New activity — Refresh" banner above the table; existing rows
  never mutate in place.
- **Warehouse count lock active.** A `409 WAREHOUSE_COUNT_LOCK` returned by the Adjustment `Dialog` or a
  Workflow Strip action against a warehouse currently under physical count surfaces a persistent,
  non-dismissible banner: "{Warehouse} is locked for a physical inventory count (started {time}) — mutating
  actions are unavailable until it closes," distinct from a one-off toast because the lock can last hours.

# Responsive Behavior

`RESPONSIVE_DESIGN.md` extends Tailwind v4's breakpoint scale with one custom tier
(`sm` 640px, `md` 768px, `lg` 1024px, `xl` 1280px, `2xl` 1536px, `3xl` 1920px) and maps all six onto five
semantic device tiers (Mobile, Tablet, Laptop, Desktop, Ultra Wide); this screen is authored mobile-first
against that same scale, with no client-side `isMobile` branching anywhere in it.

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px, Mobile) | The persistent `Sidebar` is replaced by the bottom tab bar; the sub-nav collapses into a horizontally-scrollable pill row. The KPI band becomes a horizontal-scroll, snap-aligned carousel — one tile per screen width — the identical mechanism `docs/frontend/DASHBOARD.md → Responsive Behavior` uses for its own KPI Strip, since both compose the same `KpiTile`. The alert banner collapses to a single line with a chevron that expands the below-reorder preview list inline. The AI Insights panel becomes a single summary chip ("3 insights ready") that opens the full panel as a bottom `Sheet`. The main table follows Rule 3 (below) with its scan-first `BarcodeScanInput` given primary focus, since a Warehouse Employee holding a phone or a handheld scanner is this tier's principal user. The Workflow Strip's three cards stack full-width, one per row. |
| `md` (768–1023px, Tablet) | KPI band becomes two tiles per row; the Filter bar's Warehouse/Category/Below-reorder-only controls collapse into a single "Filters (n)" trigger opening a `Sheet`, per Rule 5. |
| `lg` (1024–1279px, Laptop) | The reference layout in `# Layout & Regions`: full KPI row, the alert banner and AI panel both inline above the table, the table itself at full column width, the Workflow Strip three-across. |
| `xl`–`2xl` (1280–1535px, Desktop) | Same bento layout with generous margins; content is capped at the platform's `max-w-[1440px]` centered container above `2xl`, since a stretched table is harder to scan, not easier. |
| `3xl` (≥1920px, Ultra Wide) | The screen's own inline AI Insights panel is unchanged — it is part of this page's regular content, not the global rail (`# Layout & Regions`) — but the platform-wide global AI Rail companion panel may additionally dock as a persistent 360px rail alongside it, per `LAYOUT_SYSTEM.md`'s fourth shell region and the identical threshold `docs/frontend/INVENTORY.md`, `docs/frontend/GENERAL_LEDGER.md`, and `docs/frontend/BANKING.md` all use for their own AI panels. |

**The main table is Rule 3 — sticky-first-column horizontal scroll — reused verbatim from
`docs/frontend/INVENTORY.md → Responsive Behavior`.** A Stock on Hand row genuinely carries six to eight
simultaneously-relevant numeric buckets, so it does not decompose into a simple document card the way a
Transfer or Adjustment row does; the product/SKU column pins via `sticky start-0` (the **logical** start,
never `left-0`, `# RTL & Localization`), and the quantity/cost columns scroll horizontally inside their own
`overflow-x-auto` region below `lg:`. Virtualization (`@tanstack/react-virtual`) mounts once the loaded
window exceeds roughly 200 rows, the identical threshold `RESPONSIVE_DESIGN.md → Virtualization at scale`
specifies for General Ledger and journal-line views, with `estimateSize` reading the active breakpoint tier
rather than a single hardcoded row height.

**Touch targets and the Workflow Strip's card layout.** Every tappable control on this screen — the KPI
tiles, the alert banner's Review chevron, each Workflow Strip card's link/button, every row action — maintains
the platform's 44×44px minimum hit area below `md:`, implemented once in the shared `IconButton`/`Button`
components rather than per instance on this screen. The Workflow Strip's `md:grid-cols-3` class (shown in
its own snippet under `# Components Used`) is the one piece of this screen's layout that reflows purely
through a Tailwind container-query-free breakpoint utility, since its three cards carry no internal density
variation worth a `@container` query the way `KpiTile` does.

# RTL & Localization

Every string on this screen ships as an EN/AR pair; the screen inherits `THEMING.md`'s RTL-as-a-theming-
dimension and `LAYOUT_SYSTEM.md`'s structural RTL mirroring without a single per-component RTL branch, plus
the applications below, reused from `docs/frontend/INVENTORY.md → RTL & Localization` and applied to this
screen's own regions.

- **Bilingual products and warehouses.** Every row, KPI tile caption, alert-banner SKU name, and Workflow
  Strip label renders `product.name_ar`/`warehouse.name_ar` under an Arabic session, falling back to the
  English value only if the Arabic one is genuinely absent, never the reverse.
- **Quantities and monetary figures never switch to Eastern Arabic-Indic digits.** Every `QuantityCell` and
  `AmountCell` on the KPI band and the table renders Western Arabic digits (`numberingSystem: 'latn'`) and
  stays `dir="ltr"`/right-aligned in both directions, matching Gulf financial-document convention.
- **SKUs and reference numbers stay LTR-isolated.** `WGT-100`, `TRF-2026-000340`, and `ADJ-2026-000771` —
  whether inside the alert banner's prose, a toast, or the AI panel's narrative — render inside the shared
  bidi-isolation wrapper (`dir="ltr"` + `unicode-bidi: isolate`) so they never visually reorder inside a
  right-to-left sentence.
- **The pinned product column uses `sticky start-0`, never `sticky left-0`** — `RESPONSIVE_DESIGN.md` calls
  this the single highest-risk line of code in its entire responsive specification, and it applies
  identically here.
- **`ArrowLeftRight` and `History` do not mirror.** Per `ICONOGRAPHY.md`'s directional-icon table, an icon
  encoding "movement between two places" or "a timeline of past events" — as opposed to "reading/navigation
  direction" — stays visually identical in both languages.
- **AI narrative text is generated in the viewer's own session locale** and rendered as plain translated
  prose, never re-translated client-side; Arabic inventory terminology is used precisely — مخزون (Inventory),
  كمية متاحة (Available quantity), تعديل مخزون (Stock adjustment), نقل مخزون (Stock transfer), جرد (Stock
  count), إعادة الطلب (Reorder).

| Context | English | Arabic |
|---|---|---|
| Breadcrumb | Inventory | المخزون |
| Below-reorder banner | 22 SKUs below reorder | 22 صنفًا دون حد إعادة الطلب |
| AI reorder card | Suggested by the Inventory Manager agent — 87% confidence. | مقترح من وكيل إدارة المخزون — بثقة 87%. |
| Workflow Strip | 6 open transfers | 6 عمليات نقل مفتوحة |
| Empty state | No stock tracked yet — add a product and receive stock against a purchase order to see it here. | لا يوجد مخزون مسجّل بعد. أضف منتجًا واستلم مخزونًا مقابل أمر شراء لتظهر هنا. |

Arabic copy on this screen is authored directly by a fluent professional-register writer, not
machine-translated from the English strings above, matching the platform's stated voice discipline.

# Dark Mode

Dark mode is a token remap here exactly as everywhere else in QAYD; this screen follows
`docs/frontend/INVENTORY.md → Dark Mode`'s own semantic token vocabulary verbatim (`--surface-canvas`,
`--surface-base`, `--surface-raised`, `--surface-overlay`, `--border-subtle`, `--border-default`,
`--text-primary`, `--status-success`, `--status-warning`, `--status-error`, `--ai-accent`,
`--ai-accent-subtle`) rather than introducing a fourth naming scheme alongside the two `COMPONENT_LIBRARY.md`
and `DESIGN_LANGUAGE.md` already carry (a platform-level drift `docs/frontend/DASHBOARD.md → Dark Mode`
itself flags) — since this screen renders the identical surfaces that document already committed to a
specific vocabulary for, describing them with a different set of names here would describe code that does
not match what those components actually ship.

- **Surfaces and elevation.** The page canvas sits on `--surface-canvas`; the KPI tiles, the alert banner,
  the AI Insights panel, the table, and the Workflow Strip's cards sit on `--surface-base`; the Adjustment
  `Dialog` and any `Sheet` sit on `--surface-raised`/`--surface-overlay`. The sticky product column carries
  its paired `--border-subtle`/`--border-default` hairline in dark mode even where the light-mode equivalent
  leans on shadow alone.
- **Quantity and value cells never take a status color by themselves.** Every `QuantityCell`/`AmountCell`
  renders in `--text-primary` regardless of theme. Only the `stock_item` `StatusPill` (Available =
  `--status-success`, Low = `--status-warning`, Out of stock = `--status-error`) and a `QuantityCell`
  explicitly passed `emphasis="warning"` ever carry a semantic status color.
- **A below-reorder SKU is `--status-warning`, not `--status-error`.** Only a zero-or-negative
  `available_to_sell` escalates to `--status-error`, on both the table's `StatusPill` and the KPI band's Out
  of Stock tile — "getting low" and "cannot fulfill an order right now" are different urgencies and must read
  as visually different urgencies, on the alert banner as much as anywhere else.
- **AI provenance uses the reserved AI accent, never a stock-health color.** Every `auto_generated=true`
  `Badge`, the ABC/XYZ classification chips, and every `ConfidenceBadge` on this screen's AI panel resolve
  through `--ai-accent`/`--ai-accent-subtle`, keeping "this was AI-authored" and "this stock is
  healthy/at-risk" visually distinct in both themes.
- **Contrast.** Every token pairing here — including the KPI band's carousel snap indicators and the
  Workflow Strip's card borders — is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text) in both
  themes.
- **Print/export independence.** Any exported valuation snapshot from this screen always renders in QAYD's
  fixed light/print palette regardless of the viewer's active theme.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented here without deviation, reusing
`docs/frontend/INVENTORY.md → Accessibility`'s patterns for the regions this screen shares with it and adding
the landing-specific applications below.

- **KPI tiles are single focusable stops, mirroring `KpiTile`'s own contract from
  `docs/frontend/DASHBOARD.md → Accessibility`.** Each tile with an `onClick` is one `Tab` stop; a tile's
  inline `TrendSparkline` is `aria-hidden="true"` where an adjacent text delta already states the trend in
  words.
- **The main table uses the plain accessible `<table>` pattern, never the ARIA `grid` pattern** — reading,
  sorting, filtering, paginating, and clicking a row to navigate is exactly the category
  `ACCESSIBILITY.md → Data Tables Accessibility` reserves that lighter pattern for.
- **Landmark structure.** A visually-hidden `<h2>` labels the KPI band, the alert banner, the AI Insights
  panel, and the Workflow Strip for screen-reader navigation even where the sighted design omits a visible
  heading, mirroring the identical pattern `docs/frontend/DASHBOARD.md → Accessibility` establishes for its
  own regions.
- **Live regions announce politely, never assertively.** The alert banner's count, a realtime "New activity"
  banner, and a newly-arrived AI Insights card all use `aria-live="polite"` exclusively — a fast-moving
  figure must never interrupt whatever a screen-reader user is currently reading. `<tbody aria-live="polite"
  aria-busy={isLoading}>` on the main table announces "Loading inventory items…" and, on completion, the new
  row count.
- **Row-level accessible names are unique, always.** "Adjust stock" and "Transfer" row actions carry
  `aria-label={t('a11y.adjustStock', { product: row.sku })}` — "Adjust stock for WGT-100 Widget" — never a
  bare "Adjust."
- **Every disabled control explains itself, and distinguishes *why* it is disabled.** An Approve button
  disabled because the caller is the requester, one disabled for a missing permission, and one disabled
  because a warehouse is locked for a physical count each carry a distinct `aria-describedby`, never
  collapsed into a generic "You can't do this."
- **Focus management on overlays.** The Adjustment `Dialog`, the alert banner's expanded preview, and the
  AI Insights panel's mobile `Sheet` all move focus to their own heading on open and return it to the
  triggering control on close.
- **Color is never the only channel.** The KPI band's Below Reorder/Out of Stock states, the `StatusPill`
  severity ramp, and the alert banner's warning treatment are every one paired with a text label or an icon.
- **`BarcodeScanInput` is never scan-only**, reused verbatim from `docs/frontend/INVENTORY.md`: it always
  renders a visible, focusable, correctly-labeled text `<input>` alongside the scan listener.

# Performance

- **This screen renders exactly the figures the backend maintains and computes none of them itself.** The
  KPI band's Total Value, the table's `available_to_sell`, and every quantity bucket come directly from the
  API response; the frontend's only arithmetic is display formatting, never a bucket subtraction or a sum
  performed client-side, matching `docs/accounting/INVENTORY.md → Performance`'s description of
  `inventory_items` as "a maintained projection, never recomputed on read."
- **The new summary endpoint is one bounded call, not four-to-seven.** `GET /inventory/items/summary`
  replaces what would otherwise be independent `below_reorder`/`out_of_stock`/`open_transfers`/
  `active_counts` list requests purely to derive counts, mirroring `docs/frontend/DASHBOARD.md`'s own
  bootstrap-bundle rationale; each figure inside it is independently Redis-cached server-side so one figure's
  invalidation never discards the others.
- **The below-reorder filter and count are index-driven**, mapping straight onto the backend's own partial
  index on its maintained `needs_reorder` boolean, so this screen's Below Reorder tile and alert banner stay
  O(rows-below-threshold) regardless of total catalog size.
- **Virtualization past ~200 rows** on the main table, per `RESPONSIVE_DESIGN.md → Virtualization at scale`
  (`# Responsive Behavior`).
- **AI calls never block core rendering.** The reorder-suggestion, dead-stock, ABC/XYZ, and demand-forecast
  queries are independent of `useInventorySummary`/`useInventoryItems`'s own fetch; a slow or failed AI call
  degrades only the AI Insights panel, never the KPI band or the table beneath it.
- **Debounced filter and search inputs** at 300ms, with `placeholderData: keepPreviousData` on every list and
  summary query, per `FRONTEND_ARCHITECTURE.md`'s platform-wide debounce rule.
- **`next/dynamic`-split, scan-heavy components.** `BarcodeScanInput` and any camera/scanner integration it
  wraps are code-split out of this screen's main bundle, so a desktop CFO who never scans anything never
  downloads that code path.
- **Streamed, not blocking.** Per `# Data & State` and `# Layout & Regions`, every region is its own
  `Suspense` boundary; the slowest widget on the page — typically the AI Insights panel, if an agent run is
  still in flight — never delays the KPI band's first paint, and a failure in one region never takes down
  another.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A bare hit to `/inventory` | Resolves to the nearest `not-found` boundary — the ordinary Next.js behavior for a route-group segment with a `layout.tsx` but no `page.tsx` of its own. Every in-product link (sidebar, breadcrumb, any AI-surfaced target) points at `/inventory/items` directly, so this path is reachable only via a hand-typed or stale-bookmarked URL. |
| A deep link carries filter state — `/inventory/items?warehouse=3&below_reorder=true` | Every filter value is honored and mirrored back into the Filter bar's own controls on load, never silently reset to defaults; the KPI band and Workflow Strip also scope to `warehouse=3` via the same `warehouse_id` parameter, so the whole screen — not just the table — reflects the link's intended scope. |
| A brand-new company with no stock ever received opens this screen | The KPI band renders all four tiles at zero (not hidden); the alert banner shows its positive "nothing below reorder" state; the AI Insights panel shows "no open AI insights right now" (not an error, since there is genuinely nothing to analyze yet); the table shows the true-empty `EmptyState` linking to `/inventory/products` and `/purchasing/purchase-orders`; the Workflow Strip's three cards each show their own zero-state text. No region contradicts another about whether the company "has inventory yet." |
| The AI engine is down for an extended period | The KPI band, alert banner, table, and Workflow Strip are entirely unaffected, since none of them call the AI engine — this is the direct, visible payoff of keeping the AI Insights panel its own independently-failable region rather than fusing it into the KPI band's own fetch. |
| A warehouse is locked for a physical count while a user is on this screen | Every mutating action scoped to that warehouse (Adjust, the Workflow Strip's New Transfer targeting it) returns `409 WAREHOUSE_COUNT_LOCK`; the persistent banner from `# States` is what the user sees instead of a bare failed-request toast, naming the active count and, once available, its expected unlock time. |
| Two roles open the identical `/inventory/items` URL | The route carries no per-widget permission variance the way Dashboard's bare `/dashboard` does across its whole company, but the Valuation toggle, the Total Value tile, and every mutating control still resolve independently per the caller's own grant (`# Route & Access`) — a Warehouse Employee and a CFO looking at the same URL see structurally different screens, neither of which is treated as broken. |
| A company or active warehouse switch fires while a region's fetch is still in flight | `queryClient.clear()` runs before `router.refresh()`, per `FRONTEND_ARCHITECTURE.md → Company switching`; the in-flight response is discarded on arrival since its query key no longer exists in the cleared cache, and can never be written into the new company's `inventoryKeys.*` entries. |
| The Workflow Strip's counts and the main table's own realtime state briefly disagree | `.inventory.reorder_point_reached` and similar events invalidate `inventoryKeys.summary(...)` on arrival (`# Data & State → Realtime`), but the Strip's figures are still a point-in-time snapshot between invalidations — this is an accepted, disclosed few-second tradeoff, identical in kind to `docs/frontend/DASHBOARD.md → Edge Cases`'s own acknowledgment for its Liquidity Ratio tile and AI panel timing. |
| A company holds stock valued in more than one currency context (rare, but possible where a consignment or intercompany flow briefly carries a foreign-currency layer) | The Total Value tile's single headline figure is always the sum converted to the company's base currency, rendered through `AmountCell` at that currency's own decimal precision — it never averages or truncates precision across a mixed set, and a `CurrencyTag` in `emphasis="muted"` mode annotates the tile only when more than one currency actually contributes. |
| A role's permission is revoked mid-session (e.g., `inventory.adjust.approve` removed while an `ApprovalCard` is open) | The next attempted click's `403` collapses that card to its permission-denied state with an explanatory toast, rather than a stuck spinner or a silent failure — this screen never trusts its own last-known permission snapshot past the next mutating request. |
| A very large catalog (tens of thousands of SKUs) loads this screen for the first time | The main table virtualizes past ~200 rows exactly as it does for any company size, and the summary endpoint's four figures are pre-aggregated server-side rather than derived from a full table scan on every page view — first paint time does not scale with catalog size beyond the summary call's own bounded cost. |
| A user wants a printed or exported snapshot of this exact screen | Not supported as a literal "print this page" action, for the same reason `docs/frontend/DASHBOARD.md → Edge Cases` declines it for its own screen: five independently-cached, independently-timestamped regions with live AI provenance styling are not a defensible single-instant record. A user needing a shareable artifact is pointed at the Inventory Valuation report or the table's own Export action, both of which carry a dedicated export path with real provenance. |

# End of Document
