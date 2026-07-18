# Warehouses — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: WAREHOUSES
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Warehouses screen set: the **List**
(`/inventory/warehouses`) and the combined **Profile / Structure / Stock-by-Location / Transfers / Reports /
History** detail surface (`/inventory/warehouses/[warehouseId]`) over the `warehouses` table and its
six-level structural family (`warehouse_zones`, `warehouse_areas`, `warehouse_aisles`, `warehouse_rows`,
`warehouse_shelves`, `warehouse_bins`) that `docs/accounting/WAREHOUSES.md` defines as the physical and
logical backbone of every location `inventory_items` and `stock_movements` can ever point at. It is the
frontend counterpart to that backend module (which owns every table, enum, business rule, AI agent, and
endpoint these screens render) and conforms to the cross-cutting rules in `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`,
and `ACCESSIBILITY.md`. Where this document is silent, those documents govern; where this document appears
to contradict one of them, that is a defect to raise, not a decision to resolve unilaterally in code.

This document also **completes two deferrals** made by its own sibling. `docs/frontend/INVENTORY.md →
Purpose` states that Warehouses "share[s] Inventory's sidebar section and its `inventory.read` parent gate
... but each is owned by its own module (`docs/accounting/WAREHOUSES.md`) and its own future frontend
document" — this is that document. That same document further defers, explicitly and by name, one AI
capability outright: *"The Warehouse Optimization capability's re-slotting recommendations (bin-to-bin,
pick-frequency-driven) are deliberately not rendered anywhere in this document — that surface belongs to the
future Warehouses frontend document."* `# AI Integration` below is where that capability finally renders.

Warehouses answers a distinct triad of questions from Inventory's own "what do we have, where is it, what is
it worth": **where do our locations physically exist, how much can each one hold, and how full is it right
now.** Concretely, this document specifies:

- **List** (`/inventory/warehouses`) — browse every `warehouses` row for the active company: filter by
  type, branch, region, and status; see a live fill-rate reading per warehouse; create a new warehouse; and,
  where GPS coordinates are populated, switch to a **Map** view. The default landing surface for every role
  holding `warehouse.read`.
- **Warehouse Profile** (`/inventory/warehouses/[warehouseId]`) — a single, tabbed record view: **Overview**
  (identity, type, address, GPS, timezone, working hours, manager, capacity, and the type-specific fields —
  temperature/humidity for Cold Storage, operator details for Third-Party); **Structure** (the
  Zone → Area → Aisle → Row → Shelf → Bin tree, capacity per level, and the Warehouse Optimization /
  Space Utilization AI surfaces); **Stock by Location** (the identical tree, read-only, annotated with the
  on-hand quantity and value every `inventory_items` row inside it carries); **Transfers** (every
  `stock_transfers` row where this warehouse is source or destination, plus a "New transfer" shortcut
  pre-scoped to it); **Reports** (the eight warehouse-scoped reports `docs/accounting/WAREHOUSES.md →
  Reports` defines); and **History** (the merged `audit_logs` and structural `_history` timeline).
- **Create** (`/inventory/warehouses/new`) — a full-page, type-aware form for a brand-new warehouse.

This document does **not** respecify **Items**, **Movements**, **Adjustments**, or **Stock Counts**
(`docs/frontend/INVENTORY.md`) or **Products** (`docs/frontend/PRODUCTS.md`) — each is owned by its own
document and linked from here, never re-explained. Most importantly, this document does **not** respecify
the **Transfers list and lifecycle workbench** at `/inventory/transfers` — `docs/frontend/INVENTORY.md →
Route & Access` already fully owns that route, its `StockTransferForm`, its approval/dispatch/receive
mechanics, and its permission gates. The **Transfers tab** this document adds to a Warehouse Profile is a
*warehouse-scoped lens* onto that identical resource (`GET /inventory/transfers?filter[warehouse_id]=…`),
reusing `docs/frontend/INVENTORY.md`'s own components and endpoints rather than building a second
implementation of the same lifecycle — the same "reference it, don't duplicate it" discipline
`docs/accounting/WAREHOUSES.md` itself applies to `inventory_items`/`stock_movements`/`stock_transfers`
("here they are referenced by name... but their own DDL is not re-declared").

Three properties, mirrored directly from the backend module's own "Warehouse Philosophy," drive every
decision in this document:

1. **One schema, infinite granularity, rendered as one screen at every depth.** A brand-new `Retail Store`
   warehouse with zero zones and zero bins, and a 20,000-bin distribution center, are the *same* `page.tsx`
   and the *same* `WarehouseStructureTree` component — the tree simply has nothing, or everything, to
   expand. No screen branches on "does this company use bins."
2. **Capacity and fill rate are declared and reported, never summed client-side.** Every capacity figure
   this document renders — a bin's `capacity_units`, a zone's fill-rate percentage — comes from the API
   exactly as `docs/accounting/WAREHOUSES.md → Warehouse Structure → Capacity` defines it ("never
   auto-derived by summing children's capacities") and, for fill rate specifically, from the Space
   Utilization agent's materialized rollup (`# Performance`), never a client-side aggregation across
   however many bins are currently loaded in the tree.
3. **Crossing a warehouse boundary is always a workflow; moving within one is always a location edit.**
   This document renders the identical rule `docs/accounting/WAREHOUSES.md → Warehouse Philosophy` states
   verbatim — "movement across the boundary of a warehouse is always a `stock_transfer`... movement within
   it is always a `stock_movement`" — as a hard UI boundary: nothing on the Structure tab can relocate stock
   between two warehouses, and nothing on the Transfers tab can rename or resize a bin.

**Two audiences share one shell.** A Warehouse Manager or Inventory Manager configuring a new distribution
center — adding zones, bulk-importing 4,000 bins, setting a manager and working hours — wants dense,
keyboard- and scan-first data entry. An Owner, CFO, or Auditor reviewing warehouse health wants a calmer,
decision-first surface: a fill-rate reading that flags which zone is about to overflow, an AI-drafted
rebalancing transfer awaiting approval, an audit trail of who changed a bin's capacity and when. Both are
built from the same `DataTable`, `WarehouseStructureTree`, `StatusPill`, `AmountCell`/`QuantityCell`, and
`AIProposalPanel`-family components — composed differently, gated by different permission keys on the same
record, never a parallel screen or a parallel data model.

This document assumes `docs/frontend/FRONTEND_ARCHITECTURE.md` (App Router structure, data layer, cache
tuning, realtime), `docs/frontend/COMPONENT_LIBRARY.md` (every named component below), `docs/frontend/
RESPONSIVE_DESIGN.md` (the finance-table responsive ladder and the mobile-form/virtualization rules),
`docs/frontend/ACCESSIBILITY.md` (table, grid, and — extended here — tree semantics), `docs/frontend/
DARK_MODE.md` (semantic color tokens), `docs/frontend/NAVIGATION_SYSTEM.md` (the sidebar/sub-nav tree),
`docs/frontend/ICONOGRAPHY.md` (the icon set this document extends), `docs/api/API_PAGINATION.md` and
`docs/api/API_FILTERING_SORTING.md` (cursor/page mechanics and the filter grammar), `docs/frontend/
INVENTORY.md` (the Transfers screen this document scopes into, and the shared component precedents it
established), and `docs/accounting/WAREHOUSES.md` (the backend module) open alongside it.

# Route & Access

## App Router path

`FRONTEND_ARCHITECTURE.md`'s committed route tree lists this module's leaf as a single flat
`warehouses/page.tsx`; this document extends it with the dynamic detail segment and the create route,
exactly as `docs/frontend/PRODUCTS.md` extends its own abbreviated `products/page.tsx` leaf and
`docs/frontend/INVENTORY.md` extends `transfers/page.tsx` and `counts/[countId]/page.tsx`:

```
app/(app)/inventory/warehouses/
├── page.tsx                        # List — Server Component, first-paint fetch
├── loading.tsx                     # List skeleton
├── new/
│   └── page.tsx                    # Create — WarehouseForm, mode="create"
└── [warehouseId]/
    ├── page.tsx                    # Profile — tabs: Overview | Structure | Stock by Location | Transfers | Reports | History
    └── loading.tsx                 # Profile skeleton
```

`[warehouseId]` — never the generic `[id]` — follows `FRONTEND_ARCHITECTURE.md → Conventions → Naming`
exactly as `[productId]` and `[countId]` already do; its API-resource mirror is `/api/v1/warehouses/{id}`.

**Why `/inventory/warehouses`, not a bare `/warehouses`.** `NAVIGATION_SYSTEM.md → Sub-navigation per
module` places this screen's sidebar entry under **Inventory** — "Warehouses — `/inventory/warehouses` —
`inventory.read`" — for the identical reason `docs/frontend/PRODUCTS.md` gives for its own placement under
the same parent: *"grouping is an information-architecture decision; the permission gate always matches the
record's true owner."* A Warehouse Employee or Inventory Manager finds this screen exactly where they
already expect a warehouse list to live, alongside Items, Movements, Transfers, and Stock Counts — but
nothing on this screen or its mutating API calls resolves through `inventory.*` except the narrow, genuinely
cross-module surfaces named explicitly below (the Transfers tab, the Stock by Location tab's quantity/value
figures). Every other read and every structural mutation here is gated by Warehouses' own `warehouse.*`
grammar.

**One route, six tabs, chosen by a query param, not a sub-route.** Mirroring `docs/frontend/PRODUCTS.md`'s
"one route, two rendered modes" and `docs/frontend/INVENTORY.md`'s Stock-on-hand/Valuation toggle, the
Warehouse Profile is one Server Component (`[warehouseId]/page.tsx`) rendering a client `WarehouseDetail`
that owns a `Tabs` switch across Overview/Structure/Stock by Location/Transfers/Reports/History, reflected
as `?tab=structure` etc. so a specific tab is bookmarkable and shareable without a persisted nested route.
This is a deliberate departure from giving Structure or Transfers their own `[warehouseId]/structure/
page.tsx` segment: none of these six views has a lifecycle of its own the way a Stock Count or a Bank
Reconciliation session does (`docs/frontend/INVENTORY.md → Route & Access`'s own criterion for *when* a
sub-view earns a real route) — they are six simultaneous facets of one record, not six sequential stages of
one document.

**Bulk bin import is a `Dialog`, not a route**, reachable from the Structure tab's own header, for the same
reason `docs/frontend/INVENTORY.md`'s Adjustment is a `Dialog` and not a page: its form surface (a CSV/JSON
upload, a preview grid, a submit) and its lifecycle (seconds to a few minutes, synchronous below 2,000 rows)
are both too short-lived to deserve a bookmarkable URL, and `docs/accounting/WAREHOUSES.md → API` names it a
single `POST /warehouses/{id}/bins/bulk-import` call, not a multi-step document.

## Access gate

The route sits inside the `(app)` route group, behind `middleware.ts`'s session-cookie check and the
resolved active-company context (`X-Company-Id`), per `FRONTEND_ARCHITECTURE.md`. A user without
`warehouse.read` never sees "Warehouses" in the Inventory sub-nav or in the Command Palette's fuzzy-search
index, and a direct hit on `/inventory/warehouses` or a specific `warehouseId` renders the shared `403`
boundary, never a silent redirect. A `warehouseId` belonging to a different company than the header claims
returns `404`, never `403` — `docs/accounting/WAREHOUSES.md → Security` states this explicitly ("a request
presenting a `warehouse_id` belonging to a different company... returns `404`, not `403`... the API never
confirms the existence of a resource outside the caller's tenant"), and this document's `not-found.tsx`
handling follows that verbatim.

## Permission gates

| Gate | Permission | Effect if absent |
|---|---|---|
| Any Warehouses screen visible at all | `warehouse.read` | Sidebar's Warehouses entry (and every route in this tree) does not render; a direct hit renders the shared `error.tsx` `403` boundary. |
| "New Warehouse" | `warehouse.create` | Button and `/new` route omitted; the `N` shortcut (scoped to this route) does not fire. |
| Edit affordance, type change, working-hours/manager edits | `warehouse.update` | Overview tab renders read-only; no Edit toggle. |
| Archive / Restore | `warehouse.archive` | Overflow menu item omitted on both List rows and the Profile header. |
| Structure tab content (view) | `warehouse.structure.read` | Tab renders a permission notice instead of the tree; Stock by Location's location grouping falls back to a flat warehouse-level rollup (no per-bin breakdown) since it cannot resolve bin identities without this key. |
| Add/edit/archive a zone/area/aisle/row/shelf/bin, bulk bin import | `warehouse.structure.manage` | Every mutating control on the Structure tab is omitted, not disabled; the tree remains fully browsable read-only. |
| Stock by Location's quantity/value figures | `inventory.read` (+ `inventory.valuation.view` for value) | Tab omitted entirely without `inventory.read`; present but value-column-free without `inventory.valuation.view`, mirroring `docs/frontend/INVENTORY.md`'s identical omit-not-disable rule for the same permission. |
| "New transfer" (Transfers tab) | `inventory.transfer.create` | Button omitted; the tab itself remains visible read-only under bare `inventory.read`. |
| Approve / dispatch / receive / resolve a transfer | `inventory.transfer.approve` / `inventory.transfer.dispatch` / `inventory.transfer.receive` / `inventory.transfer.resolve` | Identical to `docs/frontend/INVENTORY.md → Route & Access`'s own table for these four keys — this document does not restate their disabled-with-tooltip treatment, it reuses the exact components that already implement it. |
| Reports tab | `reports.read` | Tab omitted. |
| Export a report | `reports.export` | Export action per report omitted independently of view access. |
| `restricted_product_id` link-through on a Bin | `products.read` | Renders as plain text (the product's name, no link) rather than a dead or 403-bound link. |
| 3PL operator/contract detail | `procurement.contracts.read` | `operator_contract_id`'s linked contract terms render nulled, per `docs/accounting/WAREHOUSES.md → Security`'s "sensitive-field masking" rule; `operator_name`/`operator_reference_code` remain visible under ordinary `warehouse.read`. |

**Reconciling the transfer dispatch/receive spelling.** `docs/accounting/WAREHOUSES.md → API` names the
outbound-dispatch and inbound-receipt actions `POST /inventory/transfers/{id}/ship` (permission
`inventory.transfer.create`) and `POST /inventory/transfers/{id}/receive` (permission `inventory.receive`).
`docs/frontend/INVENTORY.md → Data & State`, sourced from `docs/accounting/INVENTORY.md → API` — the
document that frontend actually implements against — names the same two actions `POST .../dispatch`
(permission `inventory.transfer.dispatch`) and `POST .../receive` (permission `inventory.transfer.receive`).
This is the identical class of cross-document drift `docs/frontend/INVENTORY.md` itself flags and resolves
for `inventory.count.close` vs. `inventory.count.approve`: two backend documents describing the same
underlying action with different endpoint paths and different permission keys. Because this document's own
Transfers tab is an explicit reuse of `docs/frontend/INVENTORY.md`'s already-specified components and wired
endpoints (`# Purpose`), it follows that document's spelling — `/dispatch` and `inventory.transfer.dispatch`,
`/receive` and `inventory.transfer.receive` — rather than inventing a third spelling to split the difference.
The endpoint-naming drift between `docs/accounting/WAREHOUSES.md` and `docs/accounting/INVENTORY.md` is a
defect for those two backend documents to reconcile; it is not resolved by this document picking either one
unilaterally.

## Roles — what renders

`docs/accounting/WAREHOUSES.md → Permissions → Role matrix` names its rows **Owner, Admin, Warehouse
Manager, Inventory Clerk, Finance, Auditor, AI Agent** — role names that do not appear in the platform's own
canonical default-role list (`Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, HR
Manager, Payroll Officer, Inventory Manager, Warehouse Employee, Sales Manager, Sales Employee, Purchasing
Manager, Purchasing Employee, Read Only, External Auditor`) that every other frontend document —
`docs/frontend/INVENTORY.md`, `docs/frontend/PRODUCTS.md` — reproduces verbatim. Rather than introduce a
third, screen-local role vocabulary, this document maps the backend table's rows onto their nearest
canonical equivalents, exactly as it reconciled the dispatch/receive spelling above:

| Backend matrix's role | Canonical role this document renders for |
|---|---|
| Admin | No distinct "Admin" role exists in the canonical list; treated identically to **Owner** — every permission in the matrix's Admin row is a strict subset of Owner's own grants. |
| Warehouse Manager | **Inventory Manager**, for the company/branch-wide operational and structural authority the matrix describes, further narrowed per `docs/accounting/WAREHOUSES.md`'s own scoping note ("scoped to warehouses where they are set as `manager_user_id`, or where their `branch_id` matches") — a **Warehouse Employee** who is additionally the `manager_user_id` on one specific `warehouses` row inherits that row's Warehouse-Manager-level actions on that row alone, never company-wide. |
| Inventory Clerk | **Warehouse Employee** — the identical hands-on operational role `docs/frontend/INVENTORY.md` already uses for the same receive/pick/pack/ship/adjust/transfer-create capability set. |
| Finance | **Finance Manager**. |
| Auditor | **Auditor**, and, per `docs/accounting/WAREHOUSES.md`'s own note, **External Auditor** renders identically (read-only, time-boxed). |
| AI Agent | Never renders as a "user" (below). |

Translated concretely:

| Role | What renders |
|---|---|
| Owner | Full List and Profile, every tab, every action: create/edit/archive warehouses, manage structure at every level, bulk bin import, initiate/approve/dispatch/receive/resolve transfers, all reports, all exports. |
| Inventory Manager | Identical to Owner for warehouses where they hold `manager_user_id` or a matching `branch_id`; outside that scope, read-only List and Profile (still sees fill-rate and AI panels company-wide — visibility is not scope-restricted the way mutation is). |
| Finance Manager, CFO, CEO | Read-only List/Profile including all reports; approve a pending transfer only when its `scope = inter_company` (`docs/accounting/WAREHOUSES.md`'s own note: Finance's transfer-approval grant is restricted to inter-company scope); cannot create/edit/archive a warehouse or manage structure. |
| Senior Accountant, Accountant | Read-only List/Profile/Reports; no transfer-approval capability at all (absent from the backend matrix entirely — narrower than Finance Manager here). |
| Warehouse Employee | The hands-on role: full Structure management (add/edit zone through bin, bulk import) and Stock by Location on warehouses they are assigned to; can create and submit a transfer, cannot approve one; sees the AI panels but every AI "accept" action still routes through a human confirmation step (`# AI Integration`). |
| Auditor, External Auditor | Read-only across every tab including Reports and the merged History timeline; no mutating control anywhere, matching `docs/accounting/WAREHOUSES.md`'s "their entire interaction with this module is `reports.read` plus `audit_logs` read access." |
| Read Only | Read-only List/Profile, no Reports tab (absent `reports.read` by default), no Transfers tab actions. |
| AI service account (Warehouse Optimization / Space Utilization / Transfer Recommendations agents) | Never renders these screens as a "user." Its scoped read access feeds the AI panels described in `# AI Integration`; it is structurally incapable of holding `warehouse.structure.manage`, `inventory.transfer.approve`, or any other mutating key regardless of role configuration — `docs/accounting/WAREHOUSES.md → Permissions`'s own matrix hard-codes this ("these are hard-coded as human-only in the Service layer regardless of what a company's custom role configuration grants an API-key-authenticated AI Agent principal"). Its drafts (`auto_generated=true` transfers, `warehouse_optimization_suggestions` rows) appear in the ordinary human queues, visually tagged, never in a separate AI-only list. |

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`'s `G`-prefixed navigation pattern (`G` then
`P` for Products, per `docs/frontend/PRODUCTS.md`): `G` then `H` navigates to this screen from anywhere in
the app (`H` for the physical location itself, since `W` collides with the shortcut table's existing usage
elsewhere) — this document's own addition to that shared registry, per `ICONOGRAPHY.md`'s stated
contribution rule that "any engineer encountering a concept not yet listed must add a row here in the same
PR," applied to the keyboard-shortcut table rather than the icon table. `N`, scoped to this route, opens
`/new` where `warehouse.create` is held. Inside the List, `/` focuses the search box. Inside an open
Structure tab, `→`/`←` expand/collapse the focused node and `↑`/`↓` move between visible nodes
(`# Accessibility`).

# Layout & Regions

All layouts sit inside the persistent app shell (`Sidebar`/`Topbar`, out of scope here per
`NAVIGATION_SYSTEM.md`) and, at `md:` and up, under `inventory/layout.tsx`'s own six-tab sub-nav
(`docs/frontend/INVENTORY.md → Route & Access`). Each region streams independently behind its own
`<Suspense>` boundary per `FRONTEND_ARCHITECTURE.md → Streaming with Suspense`: a slow AI panel never
delays the table or the tree from painting, and a failure in one region renders only that region's own
`ErrorState` (`# States`).

## List (`page.tsx`)

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Products   Items   Movements   Transfers   Stock Counts   Warehouses      [sub-nav tabs]  │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  Inventory · Warehouses                          [Import] [Export ▾] [+ New Warehouse]     │
│  6 warehouses across 2 branches                            [ ● Table ] [ ○ Map ]           │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐                                │
│ │ Warehouses  │ │ Active      │ │ Over        │ │ Open        │   KPI band (KpiTile × 4)    │
│ │ 6           │ │ 5           │ │ capacity    │ │ transfers   │                              │
│ │             │ │             │ │ 1 ⚠         │ │ 3           │                              │
│ └────────────┘ └────────────┘ └────────────┘ └────────────┘                                │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌ AI · Warehouse Optimization + Space Utilization ────────────────────────────────────┐     │
│ │ ● Zone B — Cold in WH-KWT-01 is at 96% capacity · 1 structural suggestion ready     │     │
│ │                                              [Review]                     [Dismiss] │     │
│ └───────────────────────────────────────────────────────────────────────────────────────┘   │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  [Type: All ▾] [Branch: All ▾] [Status: Active ▾] [Region: All ▾] [Search…]                 │
│  ┌────────────────────────────────────────────────────────────────────────────────────┐    │
│  │ Code        Name              Type       Branch    Manager     Fill    Status       │    │
│  │ ──────────────────────────────────────────────────────────────────────────────────│    │
│  │ WH-KWT-01   Kuwait Main Wh.   Main       HQ        A. Saleh    85% ▓▓▓▓░ ●Active   │    │
│  │ WH-KWT-02   Fahaheel Branch   Retail St. Fahaheel   M. Ridha    62% ▓▓▓░░ ●Active   │    │
│  │ WH-KWT-03   Returns Dock      Returns    HQ         —           18% ▓░░░░ ●Active   │    │
│  │ …(page-mode paginated, 25/page)…                                                    │    │
│  └────────────────────────────────────────────────────────────────────────────────────┘    │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Products · Items · Movements · Transfers · Stock Counts · Warehouses (active), real `<Link>`s per `inventory/layout.tsx` | Not streamed — part of the layout shell |
| Page header | `<h1>Inventory · Warehouses</h1>`, a live warehouse/branch-count summary, `Import`/`Export` (gated `warehouse.create`/`warehouse.read`+`reports.export`-adjacent export), `+ New Warehouse` (gated `warehouse.create`), and the **Table / Map** view toggle | Renders immediately with the page shell |
| KPI band | Four `KpiTile`s: Warehouses (total), Active, Over Capacity (Space Utilization threshold breaches, ⚠ accent), Open Transfers (cross-reads `docs/frontend/INVENTORY.md`'s Transfers count, filtered to any warehouse) | Own `<Suspense>` boundary |
| AI panel | A dismissible `AIProposalPanel`-family card synthesizing the Warehouse Optimization and Space Utilization agents' company-wide output — omitted entirely without AI-visibility scope | Own `<Suspense>` boundary, independently failable |
| Filter bar | `Select`s for Type (the ten-value `warehouse_type_enum`), Branch, Status (`warehouse_status_enum`), Region (`region_code`, server-searched combobox), `Input` search | Not streamed — client filter state |
| Main grid | `WarehousesTable` (a preset `DataTable`) when Table is active; `WarehouseMapView` when Map is active — same data, same filters, two renderers (`# Components Used`) | Own `<Suspense>` boundary — `GET /warehouses` |

**Fill rate is a single inline bar, not a second screen.** Each row's Fill column renders the same
`FillRateBar` primitive the Structure tab uses per node (`# Components Used`), driven by
`warehouses.fill_rate_pct` — an API-computed field from the Space Utilization agent's materialized rollup
(`# Data & State`, `# Performance`), never a client-side division of two capacity numbers pulled from
separate calls.

**Map is a view, not a route.** Toggling to Map keeps `?view=map` in the URL (deep-linkable, refresh-safe,
exactly the convention `docs/frontend/INVENTORY.md` uses for `?view=valuation`) and requests the identical
`GET /warehouses` list already loaded for Table, filtered client-side to rows carrying non-null
`latitude`/`longitude` — no second endpoint, no second query key. A company with no georeferenced
warehouses sees the Map toggle disabled with a tooltip ("No warehouses have GPS coordinates yet") rather
than an empty map.

## Warehouse Profile (`[warehouseId]/page.tsx`)

```
┌───────────────────────────────────────────────────────┬───────────────┐
│ ‹ Warehouses  WH-KWT-01 · Kuwait Main Warehouse ●Active │  Summary       │
│                        [Change type ▾] [Edit] [⋯]       │  ───────────   │
├─ Overview │ Structure │ Stock by Location │ Transfers │ Reports │ History┤ Fill 85% ▓▓▓▓░  │
│  (Overview, view mode)                                   │  Manager       │
│  Identity      WH-KWT-01 · Kuwait Main Warehouse /        │  A. Saleh      │
│                المستودع الرئيسي - الكويت                  │  GPS  ⌖ 29.3375,│
│  Type          Main  ·  Branch: HQ                        │       47.9313  │
│  Address       Street 12, Block 4, Shuwaikh Industrial     │  [ mini-map ]  │
│  GPS           29.3375, 47.9313                [mini-map] │  Timezone      │
│  Timezone      Asia/Kuwait                                 │  Asia/Kuwait   │
│  Working hours Sun–Thu 08:00–18:00 · Fri/Sat closed         │  Currency KWD  │
│  Capacity      1,500 m³ · 40,000 kg · 960 bin positions      │  Open transfers│
├───────────────────────────────────────────────────────┤  2             │
│  (Edit mode, same tab): WarehouseForm sections             ├───────────────┤
│  Identity → Type & Behavior → Address & GPS →              │  AI · Space    │
│  Manager & Hours → Capacity → Currency → (type-specific)     │  Utilization   │
│  [Cancel]                                    [Save]         │  85% fill      │
└───────────────────────────────────────────────────────┴───────────────┘
```

- **Page Header** — back-to-list link, code + bilingual name, `StatusPill domain="warehouse"`, a "Change
  type" action (gated `warehouse.update`, opens a confirming `Dialog` when `on_hand_quantity > 0` anywhere
  in the warehouse — `# Interactions & Flows`), "Edit" (replaced by read-only when `warehouse.update` is
  absent), and an overflow menu (Archive, Export this warehouse's profile, View in Map).
- **Tab/Segment Nav** — `Tabs`: **Overview** (default), **Structure**, **Stock by Location**, **Transfers**,
  **Reports**, **History**, each reflected as `?tab=`.
- **Main Column (8/12)** — the active tab's content. Overview toggles, in place, between a read view and
  `WarehouseForm` (edit mode) via local `useState`, never a route change, so switching to check Structure
  mid-edit never discards in-progress form state — the identical pattern `docs/frontend/PRODUCTS.md`
  establishes for its own Overview tab.
- **Summary Rail (4/12)** — manager, a `WarehouseMiniMap` (a single-point map, distinct from the List's
  multi-pin `WarehouseMapView`), timezone, default currency, an open-transfers count linking to the
  Transfers tab, and the AI provenance block for whichever agent output is most relevant to the current tab
  (Space Utilization's fill rate on Overview/Structure, Transfer Recommendations' draft count on Transfers).
- **Activity Timeline** — rendered at the bottom of the History tab's Main Column content, never relegated
  to a rail footnote, per `LAYOUT_SYSTEM.md → Detail Page Template`.

### Structure tab

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Structure                                     [+ Add zone] [Bulk import bins] [Export ▾]  │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  ▾ Zone A — Ambient                                       820 m³ / 1,200 m³  68% ▓▓▓░░     │
│    ▾ Area — Fast-Pick (fast_pick)                           210 m³ / 300 m³  70% ▓▓▓░░     │
│      ▾ Aisle 01                                                                             │
│        ▸ Row 01 — left    (3 shelves, 24 bins)                                              │
│        ▸ Row 01 — right   (3 shelves, 22 bins)                                              │
│      ▸ Aisle 02                                                                             │
│    ▸ Area — Bulk Storage (bulk_storage)                     580 m³ / 850 m³  68% ▓▓▓░░     │
│  ▾ Zone B — Cold                                          288.9 m³ / 300 m³  96% ▓▓▓▓▓ ⚠   │
│    ▸ Area — Receiving (receiving)                                                            │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

`WarehouseStructureTree` (`mode="manage"`) renders every populated level — a warehouse that never modeled
Areas or Rows (`docs/accounting/WAREHOUSES.md → Warehouse Structure`'s explicitly supported skip-a-level
case) shows Aisles nested directly under a Zone with no empty "Area" placeholder row ever rendered. Each
node's row carries its own `FillRateBar` wherever a `capacity_*` field is declared at that level (blank,
not zero, where capacity was never declared — capacity is opt-in, per `# Purpose`). A node's row action menu
(gated `warehouse.structure.manage`) offers Add child / Edit / Archive, scoped to what that level's schema
actually permits (a Bin has no "Add child" — it is the leaf).

### Stock by Location tab

The identical tree, `mode="stock"`: read-only, no row-action menu, every node additionally annotated with
on-hand quantity and (permission-gated) value aggregated from `inventory_items` for everything beneath it,
and a Bin-level node expandable to its per-SKU rows — each linking to
`/inventory/items?filter[product_id]=…&filter[warehouse_id]=…`, the canonical Items detail
`docs/frontend/INVENTORY.md` already owns. This tab never recomputes an on-hand figure itself; every number
is the same server-maintained projection Items itself renders (`# Data & State`).

### Transfers tab

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Transfers involving WH-KWT-01                                        [New transfer]       │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  [Direction: All ▾] [Status: Open ▾]                                                        │
│  ┌────────────────────────────────────────────────────────────────────────────────────┐    │
│  │ Transfer #       Scope           From → To               Status          Items      │    │
│  │ ──────────────────────────────────────────────────────────────────────────────────│    │
│  │ TRF-2026-005502  Inter-warehouse WH-KWT-01 → WH-KWT-02   ● Pending appr.    2       │    │
│  │ TRF-2026-005498  Inter-branch    WH-KWT-03 → WH-KWT-01   ◐ In transit       1       │    │
│  └────────────────────────────────────────────────────────────────────────────────────┘    │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

A thin, warehouse-scoped wrapper around `docs/frontend/INVENTORY.md`'s own `StockTransferForm`/table
components (`# Components Used`): the list query is the same `GET /inventory/transfers` with
`filter[warehouse_id]=…` added, "New transfer" navigates to the same `/inventory/transfers/new` full page
with `?source_warehouse_id=…` pre-filled, and clicking a row opens the same transfer-detail lifecycle
`docs/frontend/INVENTORY.md → Layout & Regions → Transfers` already specifies in full — this tab adds
exactly one column that screen does not: **Scope** (`inter_warehouse` / `inter_branch` / `inter_company`,
`# AI Integration` and `# Interactions & Flows` explain why that distinction matters enough to surface here).

### Reports tab

A grid of report cards — one per report `docs/accounting/WAREHOUSES.md → Reports` defines (Warehouse
Capacity, Inventory by Warehouse, Stock Movements, Receiving Report, Shipping Report, Transfer Report,
Warehouse Performance, Cycle Count Accuracy) — each pre-scoped to `warehouse_id={warehouseId}`, opening
either an inline `DataTable` preview or `/reports/{definitionId}?warehouse_id=…` for the full
`report_definitions`/`report_runs` experience the platform-wide Reports module owns.

### History tab

A single merged, reverse-chronological timeline: `audit_logs` rows for this warehouse and every structural
row beneath it, interleaved with the warehouse-specific events `docs/accounting/WAREHOUSES.md → Audit Logs`
names (`warehouse.type_changed`, `structure.capacity_overridden`, `bin.restricted_product_changed`,
`transfer.self_approval_exception`, …), each rendered with old/new value, actor, and reason where captured.

## Create (`new/page.tsx`)

A full page, not a `Dialog` — a warehouse's profile has too many first-class fields (bilingual identity,
type, address, GPS, timezone, working hours, manager, capacity, currency, and up to nine additional
type-specific fields for Cold Storage/Third-Party) to fit a quick-create surface, the same reasoning
`docs/frontend/PRODUCTS.md` gives for its own `/new`. `WarehouseForm` renders in sections — Identity → Type
& Behavior → Address & GPS → Manager & Working Hours → Capacity → Currency → type-specific fields — the
last of which appears or disappears the instant `type` changes, mirroring `ProductForm`'s type-aware
section visibility (`docs/frontend/PRODUCTS.md → Components Used`) applied to `warehouse_type_enum` instead
of `product_type`.

# Components Used

| Component | Source | Role on these screens |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | `WarehousesTable`'s underlying grid |
| `Tabs` | Primitive | Sub-nav; Warehouse Profile's six-tab switch; List's Table/Map toggle |
| `KpiTile` | Finance (`components/dashboard/kpi-tile.tsx`) | Warehouses, Active, Over Capacity, Open Transfers |
| `TrendSparkline` | Finance | 90-day fill-rate trend inside the Warehouse Capacity report and a warehouse's Summary Rail |
| `StatusPill` | Shared (`components/shared/status-pill.tsx`), new domains `warehouse` (active/inactive/under_maintenance/closed) and `warehouse_structure` (active/inactive/blocked/full) | List/Profile lifecycle state; every structural node's `status` |
| `AmountCell` | Finance | Value figures in Stock by Location and the warehouse-scoped Reports |
| `QuantityCell` | Inventory (`components/inventory/quantity-cell.tsx`, `docs/frontend/INVENTORY.md`) | On-hand quantities in Stock by Location, reused verbatim |
| `ConfidenceBadge` | AI (`components/ai/confidence-badge.tsx`) | Warehouse Optimization / Space Utilization / Transfer Recommendations / Receiving Prediction confidence |
| `AIProposalPanel` | AI (`components/ai/ai-proposal-panel.tsx`) | List's and Profile's AI panels |
| `ApprovalCard` | Shared, `kind="stock_transfer"` | Reused unchanged from `docs/frontend/INVENTORY.md` inside the Transfers tab |
| `StockTransferForm`, the Transfers list/lifecycle table | Inventory (`docs/frontend/INVENTORY.md → Components Used`) | The Transfers tab's entire mutating surface — not reimplemented here |
| `Sheet` | Primitive | Bin/SKU drill-down on Stock by Location; AI reasoning drill-in; filter overflow below `md:` |
| `Dialog` / `AlertDialog` | Primitive | Bulk bin import, Archive confirm, type-change warning, transfer-scope explainer |
| `DropdownMenu` | Primitive | List row actions; Profile overflow menu; Structure node actions |
| `PermissionGate` | Shared (`components/shared/permission-gate.tsx`) | Wraps every mutating affordance named in `# Route & Access` |
| `EmptyState` / `ErrorState` | Shared | List/Profile/Structure empty and error rendering (`# States`) |
| `Skeleton` | Primitive | Per-region loading placeholders |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing |

## Screen-specific components introduced by this document

| Component | Composes | Purpose |
|---|---|---|
| `WarehousesTable` | `DataTable`, `StatusPill`, `FillRateBar` | The List's server-paginated, sortable, filterable grid (`resource="warehouses"`) |
| `WarehouseMapView` | A `next/dynamic`-split map primitive, `WarehouseMapPin`, `Sheet` | The List's alternate Map view; renders a text/table alternative alongside the map itself (`# Accessibility`) — never map-only |
| `WarehouseStructureTree` | `Command`-adjacent disclosure rows, `FillRateBar`, `StatusPill domain="warehouse_structure"` | The Structure and Stock by Location tabs' shared tree, `mode="manage" \| "stock"` (below) |
| `FillRateBar` | `Progress` primitive | A reusable capacity/fill-rate bar: List rows, every Structure/Stock-by-Location node, the Warehouse Capacity report, the Summary Rail |
| `WarehouseMiniMap` | Same map primitive as `WarehouseMapView`, single-point mode | Overview tab and Summary Rail's single-warehouse GPS display |
| `WorkingHoursEditor` | `Switch`, `TimePicker` × 7 rows | The Manager & Working Hours form section's per-weekday open/close grid |
| `WarehouseForm` | `CategoryPicker`-adjacent `Select`s, `WorkingHoursEditor`, `WarehouseMiniMap`, RHF + Zod | Create/edit surface; type-aware sections mirroring `ProductForm` |
| `BinBulkImportDialog` | `Dialog`, a CSV/JSON dropzone, a validation preview `DataTable` | The Structure tab's bulk bin import flow |
| `WarehouseTransfersTab` | `docs/frontend/INVENTORY.md`'s `StockTransferForm`/table, a `scope` `Badge` | The Transfers tab's thin, filtered wrapper (`# Layout & Regions`) |

## `WarehouseStructureTree`

The centerpiece new component — there is no existing tree-disclosure primitive in `COMPONENT_LIBRARY.md` to
reuse (`CategoryPicker`'s tree is a searchable *combobox*, a materially different interaction from an
always-visible, expandable, capacity-annotated structure browser), so this is a genuinely new addition,
proposed here for `COMPONENT_LIBRARY.md` exactly as `docs/frontend/INVENTORY.md` proposed `StockCountGrid`
when no existing grid fit its own requirements:

```tsx
// components/warehouses/warehouse-structure-tree.tsx
'use client';

import { useState } from 'react';
import { FillRateBar } from '@/components/warehouses/fill-rate-bar';
import { StatusPill } from '@/components/shared/status-pill';
import { usePermission } from '@/hooks/use-permission';
import { useWarehouseStructureLevel } from '@/lib/api/hooks/use-warehouse-structure';
import type { StructuralLevel, StructuralNode } from '@/types/warehouses';

interface WarehouseStructureTreeProps {
  warehouseId: number;
  mode: 'manage' | 'stock';
  onAddChild?: (parent: StructuralNode) => void;
  onEditNode?: (node: StructuralNode) => void;
  onArchiveNode?: (node: StructuralNode) => void;
  onOpenBinStock?: (bin: StructuralNode) => void;
}

// Each node fetches its own children lazily on first expand — never the whole six-level tree in one
// call, per docs/accounting/WAREHOUSES.md → Performance's explicit max-include-depth-3 rule for the
// structural tree endpoints. A node that has never been expanded carries no `children` key at all,
// distinguishing "not yet loaded" from "loaded, has zero children" (which renders no disclosure arrow).
export function WarehouseStructureTree({ warehouseId, mode, onAddChild, onEditNode, onArchiveNode, onOpenBinStock }: WarehouseStructureTreeProps) {
  const canManage = usePermission('warehouse.structure.manage');
  const [expanded, setExpanded] = useState<Set<number>>(new Set());

  return (
    <div role="tree" aria-label="Warehouse structure" className="rounded-lg border border-border-subtle">
      <StructureLevelRows
        warehouseId={warehouseId}
        parentLevel={null}
        parentId={null}
        depth={0}
        mode={mode}
        expanded={expanded}
        onToggle={(id) => setExpanded((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; })}
        canManage={canManage && mode === 'manage'}
        onAddChild={onAddChild}
        onEditNode={onEditNode}
        onArchiveNode={onArchiveNode}
        onOpenBinStock={onOpenBinStock}
      />
    </div>
  );
}

function StructureLevelRows({ warehouseId, parentLevel, parentId, depth, mode, expanded, ...handlers }: {
  warehouseId: number; parentLevel: StructuralLevel | null; parentId: number | null; depth: number;
  mode: 'manage' | 'stock'; expanded: Set<number>; onToggle: (id: number) => void;
} & Pick<WarehouseStructureTreeProps, 'onAddChild' | 'onEditNode' | 'onArchiveNode' | 'onOpenBinStock' | 'canManage'>) {
  // Fetches exactly one level, scoped to its parent — GET /warehouses/{id}/zones, or the nested
  // .../zones/{zoneId}/areas, etc. — never `?include=` beyond the immediate child level.
  const { data: nodes, isLoading } = useWarehouseStructureLevel(warehouseId, parentLevel, parentId);
  if (isLoading) return <StructureRowSkeleton depth={depth} />;
  return (
    <>
      {nodes?.map((node) => (
        <div key={node.id} role="treeitem" aria-expanded={node.hasChildren ? expanded.has(node.id) : undefined}
             aria-level={depth + 1} style={{ paddingInlineStart: `${depth * 20}px` }}>
          <NodeRow node={node} mode={mode} depth={depth} expanded={expanded.has(node.id)} {...handlers} />
          {expanded.has(node.id) && node.hasChildren && (
            <StructureLevelRows warehouseId={warehouseId} parentLevel={node.level} parentId={node.id}
                                 depth={depth + 1} mode={mode} expanded={expanded} {...handlers} />
          )}
        </div>
      ))}
    </>
  );
}
```

`FillRateBar`'s value on every node is read directly from that node's own `fill_rate_pct` field — the
server-computed figure `docs/accounting/WAREHOUSES.md → Reports → Warehouse Capacity` and the Space
Utilization agent maintain — never `occupied / capacity` computed inline from the two raw numbers, for the
identical reason `InventoryItemsTable`'s `available_to_sell` is never a client-side subtraction
(`docs/frontend/INVENTORY.md → Purpose`). A node with no declared capacity at that level renders `FillRateBar`
in a distinct "no capacity declared" muted state, never a `0%` or `—` that could be misread as "empty."

## Icon additions

`ICONOGRAPHY.md → Semantic Icon Set` already reserves `Warehouse` "exclusively for the physical-location
concept, never reused for generic 'storage'"; this document extends that same table with the concepts it
introduces, per its own stated contribution rule:

| Concept | Icon | Rationale |
|---|---|---|
| Bin (the atomic storage location) | `MapPin` | A bin is the one structural level that is genuinely a addressable point, not a container — `MapPin` is already the platform's point-on-a-map glyph and is used nowhere else in the structural hierarchy, keeping it unambiguous that this is the level `inventory_items.bin_id` resolves to. |
| Capacity / fill rate | `Gauge` | **New row.** No existing glyph maps to "how full is this," and `Gauge`'s dial metaphor reads correctly in both LTR and RTL without mirroring. |
| GPS / Map view | `Map` (view toggle), `MapPin` (a plotted warehouse pin, reused from Bin above at a different size token) | `Map` is distinct from `MapPin` deliberately: one is "show me the map," the other is "here is a specific point." |
| Working hours | `Clock` | No new row — reuses the platform's existing generic time glyph, since no warehouse-specific connotation is needed beyond "a schedule." |
| Cold Storage / temperature | `Thermometer` | **New row.** Used on the Overview tab's Cold Storage fields and the `warehouse.temperature.out_of_range` notification. |
| Third-Party (3PL) operator | `Handshake` | **New row.** Signals "operated on our behalf by an external party," distinct from `Building2` (Vendors) since a 3PL is explicitly not modeled as a `vendors` counterparty for the stock itself (`docs/accounting/WAREHOUSES.md → Warehouse Types`). |
| Bulk bin import | `UploadCloud` | No new row — reuses the platform's existing generic bulk-import glyph already used by Products' and Inventory's own import actions. |
| Warehouse Optimization / re-slotting suggestion | `Sparkles` (AI provenance) + `LayoutGrid` | **New row for `LayoutGrid`** — a structural-rearrangement suggestion is visually distinct from a plain data insight; `LayoutGrid` evokes "rearranging a grid of spaces," directly analogous to what the agent proposes. |

# Data & State

## Endpoints

Every path below is declared in `docs/accounting/WAREHOUSES.md → API`, versioned under `/api/v1/warehouses`,
Bearer-authenticated, scoped via `X-Company-Id`, returning the platform's standard envelope (`success`,
`data`, `message`, `errors`, `meta`, `request_id`, `timestamp`).

| Method | Path | Permission | Used by |
|---|---|---|---|
| GET | `/warehouses` | `warehouse.read` | `WarehousesTable`, `WarehouseMapView` |
| POST | `/warehouses` | `warehouse.create` | `WarehouseForm`, create mode |
| GET | `/warehouses/{id}` | `warehouse.read` | Profile header, Overview tab, Summary Rail |
| PUT | `/warehouses/{id}` | `warehouse.update` | `WarehouseForm`, edit mode |
| DELETE | `/warehouses/{id}` | `warehouse.archive` | Archive action (`409` if `on_hand_quantity > 0` anywhere) |
| POST | `/warehouses/{id}/restore` | `warehouse.archive` | Restore action |
| GET | `/warehouses/{id}/zones` (and nested `.../areas`, `.../aisles`, `.../rows`, `.../shelves`, `.../bins`) | `warehouse.structure.read` | `WarehouseStructureTree`'s level-by-level lazy fetch, both modes |
| POST/PUT/DELETE at every nested level above | `warehouse.structure.manage` | Structure tab's Add/Edit/Archive node actions |
| GET | `/warehouses/{id}/bins/search` | `warehouse.structure.read` | `BarcodeScanInput`-style bin lookup reused inside the bulk-import preview and the Stock by Location tab's own search box |
| POST | `/warehouses/{id}/bins/bulk-import` | `warehouse.structure.manage` | `BinBulkImportDialog` |
| GET | `/warehouses/export` | `warehouse.read` | List/Profile export menus (`?include=structure` for the full tree) |
| POST | `/warehouses/import` | `warehouse.create` | List header's "Import" action |
| GET | `/warehouses/reports/capacity` | `reports.read` | Reports tab's Warehouse Capacity card; also backs `FillRateBar` values throughout (`# Performance`) |
| GET | `/warehouses/reports/inventory-by-warehouse` | `reports.read` | Reports tab; Stock by Location's node-level rollups (below) |
| GET | `/warehouses/reports/movements` | `reports.read` | Reports tab's Stock Movements card |
| GET | `/warehouses/reports/performance` | `reports.read` | Reports tab's Warehouse Performance scorecard |
| GET | `/inventory/transfers` | `inventory.read` | Transfers tab, `filter[warehouse_id]=…` added — owned by `docs/frontend/INVENTORY.md` |
| POST | `/inventory/transfers` | `inventory.transfer.create` | Transfers tab's "New transfer," `?source_warehouse_id=…` pre-filled — owned by `docs/frontend/INVENTORY.md` |

**This document's own minimal, necessary extensions.** Two endpoints are implied by the screens above but
not individually named in `docs/accounting/WAREHOUSES.md → API`, following the exact shape every other
resource in the same table already uses:

| Method | Path | Permission | Why it must exist |
|---|---|---|---|
| GET | `/warehouses/{id}/stock-summary` | `inventory.read` (+ `inventory.valuation.view` for the value fields) | Backs the Stock by Location tab's per-node quantity/value annotation without requiring the client to fetch every `inventory_items` row for the warehouse and aggregate it locally — the same "the frontend computes nothing that matters" discipline `docs/frontend/INVENTORY.md → Purpose` states, applied to a location-grouped rollup instead of a flat list. Shape mirrors `GET /inventory/items` but grouped by `location_path` ancestry rather than returned as flat rows. |
| GET | `/warehouses/{id}/reports/receiving`, `/shipping`, `/cycle-count-accuracy` | `reports.read` | `docs/accounting/WAREHOUSES.md → Reports` fully specifies these three reports' columns and intent (Receiving Report, Shipping Report, Cycle Count Accuracy) but its own `# API` table lists only capacity/inventory-by-warehouse/movements/performance — these three follow the identical `/warehouses/reports/*` pattern already established for the other four. |

## Query key factory

```ts
// lib/api/query-keys.ts (excerpt)
export const warehouseKeys = {
  all: ['warehouses'] as const,
  lists: () => [...warehouseKeys.all, 'list'] as const,
  list: (filters: WarehouseFilters) => [...warehouseKeys.lists(), filters] as const,
  details: () => [...warehouseKeys.all, 'detail'] as const,
  detail: (id: number) => [...warehouseKeys.details(), id] as const,
  structureLevel: (warehouseId: number, level: StructuralLevel | null, parentId: number | null) =>
    [...warehouseKeys.detail(warehouseId), 'structure', level ?? 'root', parentId] as const,
  stockSummary: (warehouseId: number) => [...warehouseKeys.detail(warehouseId), 'stock-summary'] as const,
  capacity: (warehouseId: number, level?: StructuralLevel) =>
    [...warehouseKeys.detail(warehouseId), 'capacity', level] as const,
  transfers: (warehouseId: number, filters: TransferFilters) =>
    [...warehouseKeys.detail(warehouseId), 'transfers', filters] as const,
  reports: (warehouseId: number, reportKey: string, params: unknown) =>
    [...warehouseKeys.detail(warehouseId), 'reports', reportKey, params] as const,
  ai: {
    optimizationSuggestions: (warehouseId?: number) => ['warehouses', 'ai', 'optimization', warehouseId] as const,
    spaceUtilization: (warehouseId?: number) => ['warehouses', 'ai', 'space-utilization', warehouseId] as const,
    transferRecommendations: (warehouseId?: number) => ['warehouses', 'ai', 'transfer-recommendations', warehouseId] as const,
  },
};
```

## Cache tuning — two tiers for the same resource

`FRONTEND_ARCHITECTURE.md → Cache tuning by data class` places `warehouses` in the "rarely-changing
reference/master data" tier (5 minutes), since that table's own worked examples describe *other* screens
consuming warehouses as a **reference picker** (`WarehouseBinPicker` on Items/Transfers, the Products
Inventory tab's per-warehouse rollup). This document's own List and Profile are a different caller of the
identical endpoint acting as the **primary CRUD surface** for that data, not a reference lookup — so this
document deliberately runs a second, shorter-lived tier on top of the same `GET /warehouses`/`GET
/warehouses/{id}` calls when *this screen* is the caller:

| Caller | `staleTime` | Rationale |
|---|---|---|
| `WarehouseBinPicker`, `WarehousePicker`-style reference widgets elsewhere in the app | 5 minutes (`FRONTEND_ARCHITECTURE.md`'s stated tier) | A picker only needs "close enough," and a 5-minute-stale warehouse list is invisible to whoever is filling a form. |
| This document's own `WarehousesTable`, Profile detail query | 30 seconds ("transactional lists" tier) | This screen is where a warehouse's status, manager, and — especially — fill rate are expected to be current; a Warehouse Manager checking whether a zone just crossed 90% cannot tolerate a 5-minute-old reading. |
| `FillRateBar`/capacity figures specifically (`# Performance`) | 5 minutes, matching the backend's own materialized-view refresh cadence, with an explicit "Refresh now" force-recompute action | Matches the actual freshness the underlying data can offer rather than polling faster than the source ever updates. |

Both tiers key off the identical `warehouseKeys.list`/`detail` factory — they are simply invoked with
different `staleTime` overrides at the call site, never two different query keys for the same data, so a
mutation from this screen still invalidates whatever a picker elsewhere has cached.

## Realtime

`private-company.{company_id}.warehouses.{warehouse_id}` (company-wide `private-company.{company_id}
.warehouses` when no single warehouse is scoped), following the platform's fixed
`private-company.{id}.<feature>[.{sub_id}]` channel convention:

```ts
// lib/api/hooks/use-warehouse-realtime.ts
'use client';
import { useEffect, useState } from 'react';
import { echo } from '@/lib/realtime/echo';

export function useWarehouseRealtime(companyId: string, warehouseId: number | null) {
  const [hasNewActivity, setHasNewActivity] = useState(false);
  useEffect(() => {
    const channelName = warehouseId
      ? `company.${companyId}.warehouses.${warehouseId}`
      : `company.${companyId}.warehouses`;
    const channel = echo.private(channelName);
    const bump = () => setHasNewActivity(true);
    channel
      .listen('.warehouse.capacity.threshold_exceeded', bump)
      .listen('.warehouse.temperature.out_of_range', bump)
      .listen('.warehouse.status.changed', bump)
      .listen('.warehouse.ai.transfer_recommended', bump)
      .listen('.inventory.transfer.submitted', bump)
      .listen('.inventory.transfer.disputed', bump);
    return () => { echo.leave(channelName); };
  }, [companyId, warehouseId]);
  return { hasNewActivity, dismiss: () => setHasNewActivity(false) };
}
```

Exactly as `docs/frontend/INVENTORY.md` insists for its own channels, a realtime event never splices a row
into a table mid-scroll or reorders the Structure tree while a user is browsing it — it only raises the
same non-disruptive "New activity — Refresh" banner (`# States`).

## AI agents feeding these screens

| Agent | Role on these screens | Autonomy |
|---|---|---|
| Warehouse Optimization | Structural-change suggestions (split a zone, redesignate an area, flag a mismatched warehouse type) — the capability `docs/frontend/INVENTORY.md` explicitly deferred here | Suggest-only |
| Space Utilization | Per-node fill-rate figures (`FillRateBar` everywhere), the "Over Capacity" KPI, the 90%-threshold notification | Auto-generates the report/notification; never blocks an operation |
| Transfer Recommendations | Draft `stock_transfers` rows surfaced on the List/Profile AI panel and, once accepted, on the Transfers tab via the ordinary transfer detail | Draft-only; always requires human submission and the standard approval workflow |
| Receiving Prediction | An informational receiving-volume forecast card on the Overview tab | Suggest-only, informational |
| Demand Forecasting | Feeds Transfer Recommendations' rebalancing logic; not directly rendered on these screens (referenced, not surfaced, per `# AI Integration`) | Suggest-only, upstream of another agent |

## Reference data

| Reference | Endpoint | Consumed by |
|---|---|---|
| Branches | `GET /api/v1/branches` | `WarehouseForm`'s Branch select, List's Branch filter |
| Users (manager picker) | `GET /api/v1/users?role=manager_eligible` | `WarehouseForm`'s Manager field |
| Products (`restricted_product_id`) | `GET /api/v1/products/search` | A Bin's restriction picker/link-through, gated `products.read` for the link |
| Procurement contracts | `GET /api/v1/purchasing/procurement-contracts` | Third-Party type's `operator_contract_id` field, gated `procurement.contracts.read` |
| Currencies | `GET /api/v1/currencies` | `WarehouseForm`'s Default Currency select |

## Client state ownership

| State | Owner |
|---|---|
| Warehouse list, one warehouse's detail, structure levels, stock summary, transfers, reports | TanStack Query cache, keyed as above |
| The in-progress `WarehouseForm` values | React Hook Form's internal state, schema-validated by `warehouseSchema` |
| Active Profile tab | URL search param (`?tab=`), never local-only state — must survive a refresh/share |
| Structure tree's expanded-node set | Local `useState` inside `WarehouseStructureTree` — deliberately not persisted to the URL; a tree with thousands of bins would make every expand/collapse a URL mutation, unlike a bounded tab switch |
| List's Table/Map toggle, column visibility, density | URL search param + Zustand (`useDensityStore`), matching `docs/frontend/PRODUCTS.md`'s identical split |
| Selected rows for bulk action | Local state inside `DataTable`, cleared on navigation |

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md`'s Principle 10: a reversible, non-financial action updates the cache
immediately; a mutation that changes structural/financial state waits for the server's `2xx` first.

```ts
// hooks/warehouses/use-warehouses.ts (excerpt)
export function useArchiveWarehouse() {
  const qc = useQueryClient();
  return useMutation({
    // No onMutate — archiving can be rejected with a 409 if any inventory_items row for this
    // warehouse still has on_hand_quantity > 0 (docs/accounting/WAREHOUSES.md → API), and the UI
    // must never imply "archived" before the server actually agrees.
    mutationFn: (id: number) => apiFetch(`/api/v1/warehouses/${id}`, { method: 'DELETE' }),
    onSuccess: (_, id) => {
      qc.invalidateQueries({ queryKey: warehouseKeys.detail(id) });
      qc.invalidateQueries({ queryKey: warehouseKeys.lists() });
    },
  });
}

export function useDismissOptimizationSuggestion() {
  const qc = useQueryClient();
  return useMutation({
    // Dismissing a suggestion changes nothing structural — it only hides a proposal — the
    // reversible, low-stakes half of Principle 10, and updates optimistically.
    mutationFn: (suggestionId: number) =>
      apiFetch(`/api/v1/warehouses/optimization-suggestions/${suggestionId}/dismiss`, { method: 'POST' }),
    onMutate: async (suggestionId) => {
      await qc.cancelQueries({ queryKey: warehouseKeys.ai.optimizationSuggestions() });
      const previous = qc.getQueryData(warehouseKeys.ai.optimizationSuggestions());
      qc.setQueryData(warehouseKeys.ai.optimizationSuggestions(), (old?: OptimizationSuggestion[]) =>
        old?.filter((s) => s.id !== suggestionId));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(warehouseKeys.ai.optimizationSuggestions(), ctx?.previous),
  });
}
```

`useUpdateWarehouse`, `useChangeWarehouseType`, and every structural-node mutation (`useCreateZone`,
`useArchiveBin`, …) follow `useArchiveWarehouse`'s exact shape — no `onMutate`, a client-generated
`Idempotency-Key` per logical attempt, and a confirming `Dialog`/`AlertDialog` in front of any action that
can be rejected server-side for a reason the client cannot fully pre-validate (stock on hand, a
mid-count lock, a child row still referencing a soon-to-be-archived parent).

# Interactions & Flows

**Creating a warehouse.** "New Warehouse" (gated `warehouse.create`) opens `/new`. `WarehouseForm` mounts
with `mode="create"` and only Identity + Type expanded — `type` is required to unlock every later section,
since it drives which type-specific fields even exist (`# Components Used`). Code (`WH-KWT-01`-style) is
free-text, uniqueness-checked against `GET /warehouses?code=…` as a debounced UX courtesy; the authoritative
check is the server's own `uq_warehouses_company_code` constraint at save time, returned as a `422` if
violated. Selecting `Cold Storage` reveals the temperature/humidity range fields; selecting `Third-Party`
reveals `operator_name`/`operator_contract_id`/`operator_reference_code`; switching away from either after
populating them shows the identical "this will remove X" inline notice `ProductForm` shows for its own
type-switch (`docs/frontend/PRODUCTS.md → Components Used`), never a silent field drop.

**Configuring structure.** From an empty Structure tab, "+ Add zone" is the only enabled action (Areas
cannot exist without a Zone, per `docs/accounting/WAREHOUSES.md → Warehouse Structure`'s mandatory-parent
rule). Each node's own row exposes "Add child" scoped to what its level legally permits — a Zone can add an
Area *or* skip straight to an Aisle (nullable `area_id`), a Bin can add nothing further. The tree never
renders an empty placeholder row for a level the company chose not to model; skipping straight from
Warehouse to Bins renders Bins directly under the Warehouse's own root row.

**Bulk bin import.** `BinBulkImportDialog` accepts a CSV/JSON upload, renders a validation preview
(row-by-row: will create / will skip — duplicate code or barcode / error, with the specific reason), and
submits via `POST /warehouses/{id}/bins/bulk-import`. Above 2,000 rows the response is `202 Accepted` with a
job reference (`# Performance`); the dialog closes to a progress toast resolved by the
`warehouse.import.completed` Reverb event rather than holding the dialog open for the duration.

**Switching Structure ↔ Stock by Location.** Toggling the tab swaps `WarehouseStructureTree`'s `mode` prop;
the tree's already-expanded node set is preserved across the switch (a Warehouse Employee who drilled down
to a specific aisle to manage it should see that same aisle already open when they flip to check its stock),
but the underlying query key differs (`structureLevel` vs. the node's `stockSummary` slice), so switching
never shows stale management-mode data under a stock-mode label or vice versa.

**Drilling into a bin's stock.** Expanding a Bin node in Stock-by-Location mode reveals its per-SKU rows
inline (not a separate `Sheet`, since a bin typically holds a handful of SKUs, not hundreds); each row's
"View in Items" action opens `/inventory/items?filter[product_id]=…&filter[warehouse_id]=…`, landing the
user on `docs/frontend/INVENTORY.md`'s own canonical Items screen rather than duplicating any of that
screen's adjust/transfer affordances here.

**A fill-rate threshold is crossed.** The moment a zone's `fill_rate_pct` crosses its configured threshold
(default 90%, per `docs/accounting/WAREHOUSES.md → Notifications`), the realtime channel fires
`warehouse.capacity.threshold_exceeded`; this document's response is the same non-disruptive banner pattern
used platform-wide (`# States`) plus that node's `FillRateBar` flipping to its danger tone — never a blocking
dialog, since a receiving clerk can still physically receive into an over-capacity zone
(`docs/accounting/WAREHOUSES.md`: "the system warns, it does not hard-block").

**Initiating a transfer from a warehouse.** The Transfers tab's "New transfer" and the Overview tab's own
overflow "Transfer stock from here" both resolve to the identical `/inventory/transfers/new?source_warehouse
_id=…` route `docs/frontend/INVENTORY.md` owns — this document never renders its own transfer-creation form.
What this document *does* add is the **Scope badge**: the instant a destination warehouse is picked, a small
inline note computes (client-side, informationally only — the server is still the authority) whether the
pair is `inter_warehouse` (same branch, same company — no accounting entry), `inter_branch` (a real
Inventory-account journal entry between cost centers), or `inter_company` (a full journal entry with
revenue/COGS or receivable/payable effect, per `docs/accounting/WAREHOUSES.md → Warehouse Transfers`) —
surfaced so a Warehouse Employee understands *before* submitting whether they are about to trigger a
same-branch relocation or a cross-entity financial event.

**AI: reviewing a Warehouse Optimization suggestion.** "Review" on the List/Profile AI panel opens a `Sheet`
with the proposed structural change (e.g. "Split Zone B — Cold into two zones; Bin 004–040 are consistently
above 95% while 041–080 sit below 40%"), its `reasoning`, and its `confidence`. Accepting never applies the
change directly — it pre-fills the Structure tab's own Add/Edit forms with the suggested values for a human
holding `warehouse.structure.manage` to confirm, identical in spirit to `docs/frontend/INVENTORY.md`'s
reorder-suggestion flow ("there is no 'Do it' one-click path... because [this] is a standing policy change,
not a one-time transaction").

**AI: accepting a Transfer Recommendation.** Functions exactly as `docs/frontend/INVENTORY.md → Interactions
& Flows` already specifies for this same agent's output — this document only adds that the resulting draft,
wherever it touches *this* warehouse (as source or destination), also appears inline on this warehouse's own
Transfers tab and AI panel, tagged with the identical AI-provenance `Badge`, never a second, parallel
acceptance flow.

# AI Integration

Every AI-authored figure or affordance on these screens carries a `confidence` (0–1) and a `reasoning`
string, per the platform-wide rule that no card shows a bare number the AI computed without showing its
work; below 60% confidence, the corresponding suggestion is visibly marked low-confidence, matching
`docs/accounting/WAREHOUSES.md → AI Responsibilities`'s own per-agent confidence handling.

| Capability | Where it renders | Autonomy | Confidence handling |
|---|---|---|---|
| Warehouse Optimization | A ranked suggestion list on the List's and a Profile's AI panel; each suggestion's detail `Sheet` | Suggest-only | Below 0.6 hidden from the default view (available in an "all suggestions" expanded list); ≥ 0.85 marked "high confidence," per `docs/accounting/WAREHOUSES.md → AI Responsibilities` |
| Space Utilization | `FillRateBar` everywhere on List/Structure/Stock by Location/Reports; the "Over Capacity" KPI; the threshold notification | Auto-generates the report/notification; never blocks an operation | Confidence reflects how complete the underlying product-dimension data is; below 70% dimension coverage the fill-rate figures carry a "data-completeness caveat" note rather than presenting as exact |
| Transfer Recommendations | AI panel card, resolving into an ordinary Transfer detail on acceptance (`docs/frontend/INVENTORY.md`'s existing flow, surfaced here too when this warehouse is source or destination) | Requires-approval, always — the agent may draft, never submit | Drafts below 0.5 confidence are held in a "needs review" queue, not the primary list, per `docs/accounting/WAREHOUSES.md`'s "to avoid alert fatigue" rule |
| Receiving Prediction | An informational forecast card on the Overview tab (expected receiving volume/timing) | Suggest-only, informational | Confidence weighted down for fewer than 5 historical data points, flagged "low history" rather than suppressed |
| Demand Forecasting | Not directly rendered on these screens — feeds Transfer Recommendations' rebalancing logic and Inventory's own reorder-point logic | N/A here | N/A here |
| Picking Route Optimization, Stock Placement Suggestions | **Deliberately not rendered on this desktop admin surface.** Both are floor-operations concerns tied to a live pick/put-away task on a handheld device, not a warehouse's structural configuration or its list/detail record — they belong to a future Warehouse Operations / mobile handheld document, the same way this document itself was, until now, a deferral inside `docs/frontend/INVENTORY.md` | — | — |

**The re-slotting deferral, resolved.** `docs/frontend/INVENTORY.md → AI Integration` explicitly withheld
Warehouse Optimization's bin-to-bin, pick-frequency-driven re-slotting suggestions from its own scope
("that surface belongs to the future Warehouses frontend document"). They render here, on the Structure tab,
as a specific suggestion *type* within the same `AIProposalPanel` used for the broader
zone/area-redesignation suggestions above — a re-slotting suggestion's detail `Sheet` additionally shows the
specific bin-to-bin moves proposed (from/to bin, product, quantity), each of which, if accepted, generates
an ordinary `internal_relocation` `stock_movements` row (never a `stock_transfers` document, since re-slotting
never crosses a warehouse boundary, per `# Purpose`'s third founding property) through Inventory's existing
manual-movement path.

**Unavailable AI.** If any AI call fails, times out, or AI is disabled for the company, the AI panel on the
affected screen collapses to a quiet "AI insights unavailable right now" note; `FillRateBar` values remain
fully available regardless (Space Utilization's rollup is a deterministic report, not a probabilistic
suggestion, and its unavailability is handled as an ordinary data-fetch error, not an "AI unavailable" state
— see `# States`). Every other capability — browsing, creating, editing structure, transferring — remains
fully available, mirroring `docs/frontend/INVENTORY.md`'s and `docs/frontend/BANKING.md`'s identical rule
that AI is strictly additive, never a gate on anything a human needs to do their job.

**AI provenance is always visually distinct, never color-coded as good or bad.** Every AI-authored
suggestion and every `auto_generated=true` draft transfer carries a small `Sparkles`-accompanied `Badge`
using the reserved `--ai-accent` token (`# Dark Mode`) — distinct from `FillRateBar`'s own success/warning/
danger tones, so "this is AI-authored" and "this location is full" are never visually conflated into one
signal.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint) | List/Profile primary query in flight, no cached data | `Skeleton` shaped to that region's own geometry; never a generic spinner |
| Loaded, has data | Normal case | KPI band, AI panel, and main grid/tree each render independently per their own `<Suspense>` boundary |
| Empty — no warehouses at all | A new company that has not created its first warehouse | `EmptyState`: "No warehouses yet — create your first warehouse to start tracking stock locations," primary action "New Warehouse" (gated `warehouse.create`) |
| Filtered to zero | Type/Branch/Status/Region filters legitimately exclude every row | Lighter `EmptyState` variant: "No warehouses match your filters," with a "Clear filters" action |
| Structure/Stock by Location tab, zero structure | A warehouse with no zones/bins modeled at all | A **positive-framed** `EmptyState`, not an error: "This warehouse has no structure configured — all stock is tracked at the warehouse level," per `docs/accounting/WAREHOUSES.md → Edge Cases`'s "warehouse with zero structure" case; "+ Add zone" remains the sole call to action for a role holding `warehouse.structure.manage` |
| Structure tree, a level fetching | A node's children have never been loaded and the user just expanded it | An indented `Skeleton` row set at that depth only — never a full-tree spinner (`# Performance`) |
| Fetching next page (List) | Scrolling a large warehouse count | Trailing row-shaped `Skeleton`, `isFetchingNextPage` |
| Realtime new activity | A `warehouse.*`/`inventory.transfer.*` event lands for the currently-scoped warehouse(s) | Dismissible, non-disruptive banner: "New activity — Refresh"; existing rows/tree nodes never mutate in place |
| Capacity threshold exceeded | A zone/area/bin's `fill_rate_pct` crosses its configured threshold | That node's `FillRateBar` flips to its danger tone plus a small inline `⚠`; the List's "Over Capacity" KPI increments; never a blocking dialog |
| Warehouse status = `closed` / `under_maintenance` | `warehouses.status` in either state | A persistent, non-dismissible banner on the Profile: "{Warehouse} is {closed/under maintenance} — new receiving/shipping/inbound transfers are unavailable," reflecting `docs/accounting/WAREHOUSES.md`'s own stated blocking behavior |
| Warehouse locked for a physical count | A `409 WAREHOUSE_COUNT_LOCK` is returned by a mutating action scoped to this warehouse | The identical persistent banner `docs/frontend/INVENTORY.md → States` already defines, extended to render on this warehouse's Overview/Structure tabs too, naming the active count |
| Pending transfer/approval | The caller holds the matching `.approve` permission and at least one transfer involving this warehouse awaits it | `ApprovalCard`s surface inline on the Transfers tab, reused unchanged from `docs/frontend/INVENTORY.md` |
| Map view, no georeferenced warehouses | Every row lacks `latitude`/`longitude` | The Map toggle itself renders disabled with an explanatory tooltip rather than an empty map canvas |
| AI unavailable | Any AI endpoint fails, times out, or is disabled for the company | AI panel collapses to a quiet unavailable note; `FillRateBar` values remain available (deterministic report, not an AI call) |
| Error | `403`/`404`/`5xx`/network failure on any request | `ErrorState` with a retry action; a `403` discovered mid-session collapses only the affected region, never the whole page |

# Responsive Behavior

`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` defines a five-rule ladder; this section classifies
each of this document's surfaces against that same ladder rather than inventing a sixth pattern.

**Warehouses List — Rule 2, card transformation.** A warehouse row's headline (code, bilingual name,
`StatusPill`) and its detail split (type, branch, manager, fill rate) is structurally the same shape
`docs/frontend/INVENTORY.md` already classifies Transfers/Adjustments/Stock Counts under — below `md:`, each
row becomes a card whose header is the code/name/status and whose body is a two-column definition list of
type/branch/manager/fill-rate, extending that same named list to include Warehouses.

**Warehouse Profile tabs — collapse into a `Select` below `md:`**, per `LAYOUT_SYSTEM.md`'s standard tab
overflow behavior for any six-plus-tab detail page, identical to how `docs/frontend/PRODUCTS.md`'s own
six-tab Detail page behaves.

**`WarehouseStructureTree` — its own mobile-specific fallback**, mirroring `docs/frontend/INVENTORY.md`'s
`StockCountGrid` precedent exactly: an always-expanded, indented, six-level tree assumes a keyboard and a
reasonably wide viewport, neither realistic for a Warehouse Employee holding a phone on a receiving dock.
Below `md:`, the tree does not attempt a cramped nested list — it falls back to a **breadcrumb drill-down**:
one level renders full-width at a time (tap a Zone, see its Areas full-screen with a "Zone A" breadcrumb and
a back affordance; tap an Area, see its Aisles; and so on down to Bins), each screen still carrying that
node's own `FillRateBar` and row actions at comfortable touch-target size. This is a deliberate,
mobile-native redesign of the interaction, not a shrunken desktop tree, consistent with
`FRONTEND_ARCHITECTURE.md`'s own precedent for the barcode scanner's "Inventory's mobile-web counting flow."

**Map view — full-bleed below `md:`.** A pin tap opens a bottom `Sheet` card (code, name, fill rate, quick
actions) rather than a hover tooltip, which has no mobile equivalent.

**Transfers tab** inherits `docs/frontend/INVENTORY.md → Responsive Behavior`'s existing Rule-2 card
treatment for transfer rows unchanged — this document adds no new responsive rule for that reused surface.

**Filters and export collapse into overflow affordances below `md:`**, per Rule 5, on every surface here
uniformly: the Type/Branch/Status/Region filter row becomes a single "Filters (n)" trigger opening a `Sheet`;
export buttons collapse into a kebab `DropdownMenu`.

**Virtualization.** The Warehouses List itself rarely approaches the ~200-row threshold
`RESPONSIVE_DESIGN.md → Virtualization at scale` sets (a company's warehouse count is bounded in a way its
SKU or ledger-line count is not), so virtualization there is a defensive default rather than a routine
necessity. `WarehouseStructureTree`, by contrast, virtualizes the currently-expanded level's row set past
that same ~200-node threshold — never the whole tree at once, since it is already lazy-loaded level by
level (`# Performance`) and a single distribution center's Bin level alone can exceed 20,000 rows.

# RTL & Localization

Every string on these screens ships as an EN/AR pair; the screens inherit `THEMING.md`'s RTL-as-a-theming-
dimension and `LAYOUT_SYSTEM.md`'s structural RTL mirroring without a single per-component RTL branch, plus
the module-specific applications below.

- **Bilingual warehouses and every structural level.** Every row and every tree node renders `name_ar` under
  an Arabic session, falling back to `name_en` only if the Arabic value is genuinely absent, never the
  reverse — the identical rule `docs/frontend/INVENTORY.md` and `docs/frontend/PRODUCTS.md` both state for
  their own bilingual master-data fields, applied here to `warehouses`/`warehouse_zones`/`…_areas`/`…_aisles`
  /`…_rows`/`…_shelves`/`…_bins`, all of which carry `name_en`/`name_ar` per the platform's bilingual +
  enterprise field convention.
- **`location_path` stays LTR-isolated.** A resolved location code like `WH-01/ZA/AR-RCV/A03/R-L02/S02/B012`
  is wrapped in the shared bidi-isolation wrapper (`dir="ltr"` + `unicode-bidi: isolate`)
  `docs/frontend/INVENTORY.md` already applies to SKUs and serial values — this alphanumeric path must never
  visually reorder inside a right-to-left sentence (an AI reasoning string, a toast, a breadcrumb).
- **GPS coordinates and the map itself do not mirror.** A latitude/longitude pair and the rendered map
  canvas are a real-world spatial representation, not a reading-direction affordance — `WarehouseMapView`
  and `WarehouseMiniMap` render identically under `dir="rtl"`, the same explicit non-mirroring callout
  `docs/frontend/INVENTORY.md` gives `ArrowLeftRight` for an analogous reason (a directional-in-appearance
  glyph/rendering that encodes a physical concept, not a navigation direction).
- **The tree's expand/collapse chevrons *do* mirror.** Unlike the map, `ChevronRight`/`ChevronLeft` on
  `WarehouseStructureTree` are a navigational/reading-direction affordance, not a spatial-object icon, so
  they flip under `dir="rtl"` per `ICONOGRAPHY.md`'s own directional-icon table — the contrast between this
  and the map's non-mirroring is deliberate and drawn from that same table's own stated distinction between
  "reading/navigation direction" icons and "physical object with no inherent orientation" icons.
- **Working hours respect locale week-start, not the data's own key order.** `working_hours`' JSONB keys are
  fixed (`sun`..`sat`) regardless of locale, per `docs/accounting/WAREHOUSES.md → Warehouse Profile`, but
  `WorkingHoursEditor` and the Overview tab's read view render the seven rows in the viewer's own
  locale-appropriate week order (Sunday-first for `ar-KW`), a pure display transform over the same
  underlying fixed keys.
- **Quantities, capacities, fill-rate percentages, and GPS coordinates stay Western Arabic digits**
  (`numberingSystem: 'latn'`) even under an `ar` session, identical to `docs/frontend/INVENTORY.md`'s rule
  for every quantity/amount figure.
- **AI narrative text is generated in the viewer's own session locale**, rendered as plain translated prose,
  never re-translated client-side; Arabic warehouse terminology is used precisely — مستودع (Warehouse),
  منطقة (Zone), رف (Shelf), موقع تخزين (Bin/storage location), معدل الإشغال (Fill rate), نقل مخزون (Stock
  transfer).

# Dark Mode

Dark mode is a token remap here exactly as everywhere else in QAYD, per `DARK_MODE.md`; nothing on these
screens references a raw hex value or a Tailwind palette utility.

- **Surfaces and elevation.** The page canvas sits on `--surface-canvas`; the KPI tiles, `WarehousesTable`,
  and `WarehouseStructureTree`'s row band sit on `--surface-base`; the Bulk Import `Dialog`, the AI panel,
  and the Structure tab's node-detail `Sheet` sit on `--surface-raised`/`--surface-overlay`. Tree rows carry
  their own `--border-subtle` hairline per depth level in dark mode, per `DARK_MODE.md`'s "a borderless dark
  surface disappears against the near-black canvas" rule.
- **`FillRateBar` uses the platform's finance status semantics, not a bespoke scale.** Reusing
  `DARK_MODE.md → Finance status semantics`'s exact mapping: below 70% fill = `--status-success`; 70–90% =
  `--status-warning`; above 90% = `--status-error` — the identical three-tier mapping
  `docs/accounting/WAREHOUSES.md → Notifications`'s own default 90% threshold implies, applied consistently
  everywhere `FillRateBar` renders (List rows, Structure/Stock-by-Location nodes, the Warehouse Capacity
  report).
- **`StatusPill domain="warehouse"`** maps `active` = `--status-success`, `under_maintenance` =
  `--status-warning`, `closed` = `--status-error` (it blocks new receiving/shipping/inbound transfers, a
  genuinely "stop" state), `inactive` = neutral. `domain="warehouse_structure"` maps `active` = neutral/
  success, `blocked` = `--status-error`, `full` = `--status-warning` (advisory, not blocking, per
  `docs/accounting/WAREHOUSES.md`'s "capacity is advisory at the write layer").
- **A deterministic threshold flag is never mistaken for an AI opinion.** The 90%-capacity warning is a
  plain system rule, not an AI judgment, and therefore never carries `--ai-accent` — only a genuinely
  AI-generated suggestion (Warehouse Optimization's structural change, a Transfer Recommendation draft)
  does, keeping "the system detected a threshold" and "the AI proposed a change" visually distinct even
  though both can appear side by side on the same AI panel.
- **Map tiles follow the viewer's theme where the tile provider supports a dark tile set**, falling back to
  the light tile set with a subtle dimming overlay where it does not — an honest engineering note rather
  than a claim of full dark-mode map parity, since map tile theming is a third-party provider capability
  QAYD's own token system does not control.
- **Contrast.** Every token pairing here — including `FillRateBar`'s three tones and the tree's per-depth
  hairlines — is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text) in both themes, per
  `DARK_MODE.md → Color & Contrast In Dark`.
- **Print/export independence.** The Warehouse Capacity PDF/XLSX export and any emailed
  threshold-exceeded report always render in QAYD's fixed light/print palette regardless of the viewer's
  active theme, per `DARK_MODE.md → Exported PDFs always render light`.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented here without deviation.

- **The Warehouses List and the Transfers tab use the plain accessible `<table>` pattern**, never the ARIA
  `grid` pattern — both are "read, sort, filter, paginate, click a row to navigate, click a row action," the
  exact category `ACCESSIBILITY.md → Data Tables Accessibility` reserves that lighter pattern for, extending
  its own named list (already including Trial Balance, General Ledger, Products, and — per
  `docs/frontend/INVENTORY.md` — Items, Movements, Transfers, Stock Counts) to include Warehouses.
- **`WarehouseStructureTree` is this platform's first ARIA `tree` implementation** — a genuinely new pattern
  this document introduces and proposes as an addition to `ACCESSIBILITY.md`'s registry, following the WAI-
  ARIA Authoring Practices Tree View pattern: `role="tree"` on the container, `role="treeitem"` per row,
  `aria-expanded` on any node with children, `aria-level` reflecting depth, and `aria-selected` on the
  currently-focused node. Keyboard: `→` expands a collapsed node or moves focus to its first child if
  already expanded; `←` collapses an expanded node or moves focus to its parent if already collapsed; `↑`/
  `↓` move focus between visible nodes only (never into a collapsed node's hidden children); `Home`/`End`
  jump to the first/last visible node; `Enter`/`Space` activates the focused node's primary action (open its
  detail, or toggle expansion for a node with no other action). Roving `tabindex` — exactly one node is ever
  in the tab order at a time — mirrors the identical mechanism `docs/frontend/INVENTORY.md`'s
  `useRovingGrid` hook already establishes for `StockCountGrid`, adapted from a 2D grid to a 1D visible-node
  list.
- **A lazily-loaded node's loading state is announced.** The `Skeleton` row set that appears while a level
  fetches carries `aria-live="polite"` on its containing region so a screen-reader user hears "Loading Zone
  A's contents…" rather than silence followed by an unannounced content swap.
- **`FillRateBar` is `role="meter"`** with `aria-valuenow`/`aria-valuemin`/`aria-valuemax` and a visible text
  equivalent (`"85%"`) alongside the bar itself — the fill state is never color-only information, satisfying
  both the meter semantics and `ACCESSIBILITY.md`'s general "never color alone" rule.
- **The Map view always ships a text/table alternative.** `WarehouseMapView` renders a visually-hidden (but
  screen-reader- and keyboard-reachable) list of the same warehouses/coordinates the map plots, and the List
  itself remains switchable back to Table with a single, always-visible toggle — map-only information never
  exists on this screen.
- **`WorkingHoursEditor`'s seven rows are grouped under one `fieldset`/`legend`** ("Working hours"), each
  weekday row carrying its own visible `<label>`, matching `ACCESSIBILITY.md → Forms Accessibility`'s
  fieldset-grouping rule for a multi-field logical unit, identical to the Adjustment `Dialog`'s reason-code
  grouping in `docs/frontend/INVENTORY.md`.
- **Every disabled control explains itself and distinguishes *why*.** An Archive button disabled for
  on-hand stock ("Cannot archive — stock is still on hand"), one disabled for a missing permission
  ("Requires `warehouse.archive`"), and one disabled because the warehouse is locked for a physical count
  each carry a distinct `aria-describedby`, extending `ACCESSIBILITY.md`'s RBAC-aware disabled-control rule
  to a business-state reason exactly as `docs/frontend/INVENTORY.md` already does for its own Approve
  button.
- **Focus management on overlays.** The Bulk Import `Dialog`, the Archive/type-change confirming dialogs,
  and any node-detail `Sheet` all move focus to their own heading on open and return it to the triggering
  row/button on close, per `ACCESSIBILITY.md → Focus Management`.
- **Realtime pushes never yank focus or silently splice rows or tree nodes.** A threshold-exceeded or
  transfer event affecting the scoped warehouse while a user is mid-interaction surfaces as a polite,
  dismissible banner only, per `ACCESSIBILITY.md → Live regions`'s existing worked example, applied
  identically here.

# Performance

- **Structure is fetched level by level, never as one deep tree.** `docs/accounting/WAREHOUSES.md →
  Performance` caps structural-tree endpoints at "an explicit maximum include depth of 3 relations per
  request to prevent an accidental full-tree-of-20,000-bins payload"; `WarehouseStructureTree` honors this
  precisely — each expand triggers exactly one new request for that node's immediate children, never a
  deep `?include=` chain, and a 20,000-bin distribution center's Structure tab loads only the handful of
  Zone rows on first paint.
- **Fill rate is read from a materialized rollup, not computed on every render.** `docs/accounting/
  WAREHOUSES.md → Performance` describes `warehouse_capacity_rollup`, a materialized view refreshed every 5
  minutes; this document's `staleTime` for `FillRateBar`-feeding queries matches that cadence exactly
  (`# Data & State`), with an explicit "Refresh now" action calling the backend's own live-recompute path
  for the rare moment a user needs up-to-the-second accuracy (immediately before closing a physical count,
  per that same backend section's own worked example).
- **Bulk bin import is async by construction above 2,000 rows.** Matches `docs/accounting/WAREHOUSES.md →
  Performance`'s own `INSERT ... ON CONFLICT DO UPDATE` chunked-batch description; the corresponding UI
  (`BinBulkImportDialog`) shows a progress toast resolved via the `warehouse.import.completed` Reverb event
  rather than a spinner blocking the tab for the duration of a large layout import.
- **Report endpoints read from a replica where available.** `/warehouses/reports/*` calls tolerate the small
  replication lag `docs/accounting/WAREHOUSES.md → Performance` describes; this document's Reports tab never
  assumes read-your-own-write consistency immediately after a structural mutation — a "just updated, reports
  may lag briefly" note accompanies any report opened within seconds of a structural change.
- **Virtualization** applies to `WarehouseStructureTree`'s currently-expanded level past ~200 visible nodes,
  matching `RESPONSIVE_DESIGN.md → Virtualization at scale`'s threshold and mechanism (`# Responsive
  Behavior`), and to the Warehouses List defensively, though it rarely approaches that threshold in
  practice.
- **`next/dynamic`-split the map.** `WarehouseMapView` and `WarehouseMiniMap`'s underlying map primitive are
  code-split out of the main List/Profile bundle exactly as `FRONTEND_ARCHITECTURE.md → Bundle Optimization`
  already establishes for Inventory's barcode scanner — a CFO who never opens Map view never downloads a
  mapping library.
- **AI calls never block core rendering.** The Warehouse Optimization/Space Utilization/Transfer
  Recommendations queries are independent of the List's/Profile's own table and tree fetches; a slow or
  failed AI call degrades only the AI panel.
- **Debounced filters and search** at 300ms with `placeholderData: keepPreviousData` on every list/tree
  query, per `FRONTEND_ARCHITECTURE.md`'s platform-wide debounce rule — filtering the Warehouses List or
  searching within a Structure level never flashes to a blank state on every keystroke.

# Edge Cases

1. **A warehouse with zero structure.** Renders the positive-framed empty state on Structure/Stock by
   Location (`# States`); `inventory_items` rows for it carry `bin_id = NULL` and `location_path` resolves
   to the warehouse's own name — every screen (Items, Movements, Transfers) continues to work identically,
   simply reporting location at the warehouse level.
2. **Skipping intermediate structural levels.** A warehouse models Zones and Bins but no Areas/Aisles/Rows/
   Shelves — the tree renders Bins directly under their Zone with no empty placeholder rows for the
   unmodeled levels, per `docs/accounting/WAREHOUSES.md`'s explicitly supported skip pattern.
3. **Reorganizing a live warehouse (merging two aisles).** The old Aisle's structural rows are soft-deleted,
   new ones created under the surviving Aisle; any `inventory_items` still pointing at a soft-deleted bin
   must be explicitly relocated via an `internal_relocation` movement — this document never auto-migrates a
   stock reference on a structural delete, and the Structure tab's Archive action for a node still carrying
   on-hand stock returns the same `409` the backend defines, surfaced as an explanatory toast rather than a
   silent failure. The History tab still resolves a soft-deleted ancestor by its name as it existed at
   deletion time, never hiding the row.
4. **A bin's capacity is exceeded by a legitimate, manually-overridden put-away.** Capacity is advisory at
   the write layer (`docs/accounting/WAREHOUSES.md → Database Design → Constraints`); this document does not
   block the underlying put-away (owned by Inventory's own receiving flow) but reflects the consequence
   immediately — the bin's `StatusPill domain="warehouse_structure"` flips to `full` and its `FillRateBar`
   crosses into the danger tone the next time this warehouse's data refreshes.
5. **A transfer where source and destination are the same warehouse.** Rejected client-side as a courtesy
   (the destination picker excludes the already-selected source) and, if bypassed, server-side with `422
   SAME_SOURCE_DESTINATION` — a same-warehouse relocation is modeled as an `internal_relocation` stock
   movement via Picking/Put-Away, never a `stock_transfers` document, per `# Purpose`'s third founding
   property.
6. **A transfer continues after its source warehouse closes mid-flight.** An `approved`/`in_transit`
   transfer is allowed to complete even after `warehouses.status` becomes `closed` — closing blocks *new*
   outbound activity, not an already-approved transfer's completion — so this warehouse's Transfers tab
   still shows that transfer progressing normally alongside the persistent "closed" banner (`# States`) on
   every other tab.
7. **An inter-company transfer's destination company has no matching product record.** The paired
   transaction fails on the destination side (`404 PRODUCT_NOT_FOUND_IN_COMPANY`) before any accounting
   entry posts on the source side; this warehouse's Transfers tab shows the outbound leg held at
   `pending_approval` (never silently auto-progressing) with an inline note naming the specific catalog
   mismatch, reflecting the two-phase-commit-like guard `docs/accounting/WAREHOUSES.md → Edge Cases`
   describes.
8. **A Cold Storage temperature excursion during a count.** The affected `stock_counts` row is flagged
   `temperature_excursion_during_count = true`; this document's History tab and the Overview tab's
   temperature fields both surface the excursion as a distinct, non-dismissed banner (`critical` priority
   per `docs/accounting/WAREHOUSES.md → Notifications`) rather than folding it into the ordinary count-in-
   progress state.
9. **A 3PL-operated warehouse's reported stock disagrees with QAYD's own records.** Resolved through the
   same cycle-count/variance/adjustment-approval path as an internal discrepancy — this document renders no
   special-cased "3PL reconciliation" screen, only an ordinary `stock_counts` row referencing the 3PL's
   `operator_reference_code` as its counted source, consistent with `docs/accounting/WAREHOUSES.md`'s stated
   choice to reuse the count mechanism rather than build a parallel one.
10. **Concurrent put-away to the same bin from two clerks.** Enforced via optimistic locking on
    `warehouse_bins.updated_at` at the Service layer; the second request is rejected `409
    BIN_STATE_CHANGED`, surfaced here as a toast on that bin's row/detail if a user happens to be viewing its
    occupancy live, prompting a re-fetch before retrying rather than silently overstating capacity.
11. **A warehouse's type change is blocked while stock is on hand.** `Main → Virtual` and similarly
    incompatible transitions are rejected server-side while any `inventory_items` row for that warehouse has
    `on_hand_quantity > 0`; the "Change type" `Dialog` states this proactively for any transition known to be
    restricted, and surfaces the server's own rejection message verbatim for any transition this document's
    client-side list did not anticipate — the client's own warning is a courtesy, never the authority.
12. **Deleting a product still referenced by a bin's `restricted_product_id`.** Blocked `409` until every
    referencing bin's restriction is cleared or reassigned; this document's own Structure tab surfaces which
    specific bins reference a given product from that product's own Inventory tab link-through
    (`docs/frontend/PRODUCTS.md`), rather than requiring a manual company-wide bin search to find them.
13. **A warehouse's `manager_user_id` is deactivated or removed from the company.** Notification routing
    that depends on the manager (capacity thresholds, transfer approvals scoped to that warehouse) falls
    back to the company's default Inventory Manager recipients rather than silently dropping the
    notification; the Overview tab's Manager field renders a "Manager unassigned — notifications routed to
    Inventory Manager" note until a new manager is set, rather than showing a stale name or a blank field.
14. **AI is unavailable, disabled, or returns a low-confidence output.** Never blocks browsing, creating, or
    editing a warehouse, managing structure, or transferring stock (`# AI Integration`); only the AI panel
    and its badges degrade.

# End of Document
