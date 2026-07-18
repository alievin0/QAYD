# Inventory — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: INVENTORY
---

# Purpose

This document specifies the Inventory screens: the operational home for stock quantity, stock value, and
stock movement inside the QAYD web application. It is the frontend counterpart to
`docs/accounting/INVENTORY.md` (which owns every table, business rule, valuation method, and endpoint these
screens render) and conforms to the cross-cutting rules in `FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`,
`COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and
`ACCESSIBILITY.md`. Where this document is silent, those documents govern; where this document appears to
contradict one of them, that is a defect to raise, not a decision to resolve unilaterally in code.

Inventory answers, at a glance and with full drill-down, the same three questions
`docs/accounting/INVENTORY.md`'s own Purpose section poses — **what do we have**, **where is it**, and
**what is it worth** — for every role that touches physical stock, from a Warehouse Employee scanning a
carton at a receiving dock to a CFO reconciling the Inventory control account at period close. Concretely,
this document specifies four screens under the `/inventory/` route group, plus one screen-less workflow:

- **Stock on Hand** (`/inventory/items`) — the `inventory_items` projection rendered as a dense, filterable
  `DataTable`: one row per `(product, warehouse, bin)`, showing every quantity bucket the backend maintains
  (on hand, reserved, on order, in transit, available to sell) and, in its **Valuation** view, the
  base-currency value of that stock and the valuation method that produced it. This is the module's default
  landing screen and the one every other Inventory screen links back to.
- **Movements** (`/inventory/movements`) — the read-only, append-only history of every `stock_movements`
  row: what changed, by how much, at what cost, and which source document (a receipt, a sale, a transfer,
  an adjustment, a count) caused it. Structurally, this is Inventory's own General Ledger: a screen that
  computes nothing and explains everything, exactly mirroring `docs/frontend/GENERAL_LEDGER.md`'s own
  three founding properties (the frontend computes nothing that matters; scale is the default assumption;
  every number drills down) applied to physical stock instead of posted journal lines.
- **Transfers** (`/inventory/transfers`) — the list and lifecycle workbench for relocating stock between
  warehouses: draft, submit, approve, dispatch, and receive, with partial-receipt and shortfall handling.
- **Stock Counts** (`/inventory/counts/[countId]`) — the cycle-count and physical-inventory workbench: a
  scan-first, blind-count-capable line-entry surface that turns a warehouse walk-through into a closed count
  and, where variances exist, a linked `stock_adjustments` row.
- **Adjustments** — deliberately **not** a fifth screen. A stock adjustment (damage, shrinkage, a
  count variance, a manual correction) is short enough in form-surface and lifecycle that it is created
  from a `Dialog` opened off an Items or Movements row, never its own route — see `# Route & Access` for why
  this differs from Transfers, which *does* get a dedicated full page.

This document does **not** respecify **Products** (`/inventory/products`) or **Warehouses**
(`/inventory/warehouses`) — both share Inventory's sidebar section and its `inventory.read` parent gate
(`NAVIGATION_SYSTEM.md → Sub-navigation`), but each is owned by its own module (`docs/accounting/PRODUCTS.md`,
`docs/accounting/WAREHOUSES.md`) and its own future frontend document. Where this document needs to name a
product's valuation method, a warehouse's bin hierarchy, or a bin's pick sequence, it references those
modules' own tables (`products.valuation_method`, `warehouses.bin_tracking_enabled`,
`warehouse_bins.pick_sequence`) rather than re-deriving them — the same "reference it, don't duplicate it"
discipline `docs/accounting/INVENTORY.md` itself applies to `products` and `warehouses`.

Three properties, mirrored directly from the backend module's own founding principles, drive every decision
in this document:

1. **The frontend renders exactly the buckets the backend maintains, and computes none of them itself.**
   `quantity_on_hand`, `quantity_reserved`, `quantity_on_order`, `quantity_in_transit`, `quantity_damaged`,
   and `quantity_quarantined` are all server-maintained projection columns; `available_to_sell` is a
   server-computed value the API returns pre-computed, never a client-side subtraction the frontend performs
   on the six stored buckets, so a rounding or timing difference between two independently-computed copies
   of the same formula can never exist (`docs/accounting/INVENTORY.md → Stock Tracking`).
2. **Every mutation is a workflow, never a table edit.** Nothing on the Stock on Hand grid is an editable
   cell. Adjusting, transferring, and counting stock are each a distinct, permission-gated, human-driven
   flow — a `Dialog` for Adjustments, a full page for Transfers, a dedicated workbench route for Counts —
   and every one of them ends in an explicit submit/approve step before it touches a ledger balance, per the
   platform's sensitive-operation dual-control rule (`docs/accounting/INVENTORY.md → Permissions`: "posting
   it — the point at which it affects the general ledger — requires a role with `inventory.adjust.approve`,
   held by someone other than the requester").
3. **AI proposes, humans and policy dispose.** The Inventory Manager, Forecast, Fraud Detection, and Auditor
   agents surface reorder suggestions, dead-stock flags, ABC/XYZ classifications, shrinkage anomalies, and
   rebalancing-transfer drafts throughout these screens, but nothing they produce ever posts, dispatches, or
   closes anything by itself — an AI-authored `stock_adjustments` or `stock_transfers` row is created at
   `status='draft'`/`pending_approval` with `auto_generated=true` and flows through the identical
   human-approval affordance a person-created row uses (`# AI Integration`).

This document assumes the reader has `docs/frontend/FRONTEND_ARCHITECTURE.md` (App Router structure, data
layer, cache tuning, realtime), `docs/frontend/COMPONENT_LIBRARY.md` (every named component below),
`docs/frontend/RESPONSIVE_DESIGN.md` (the finance-table responsive ladder and virtualization rules),
`docs/frontend/ACCESSIBILITY.md` (table and grid semantics), `docs/frontend/DARK_MODE.md` (semantic color
tokens), `docs/frontend/NAVIGATION_SYSTEM.md` (the sidebar/sub-nav tree), `docs/frontend/ICONOGRAPHY.md`
(the icon set this document extends), `docs/api/API_PAGINATION.md` and `docs/api/API_FILTERING_SORTING.md`
(cursor/page mechanics and the filter grammar), and `docs/accounting/INVENTORY.md` (the backend module) open
alongside it. Nothing in this document introduces a new table or a new backend business rule that
`docs/accounting/INVENTORY.md` does not already establish; where an endpoint or field is a necessary,
minimal extension of what that document declares (a handful exist — flagged explicitly where they occur),
it follows the identical REST and permission conventions the rest of the module already uses rather than
inventing a new shape.

# Route & Access

## App Router path

Reproducing `FRONTEND_ARCHITECTURE.md`'s committed route tree verbatim, with the one addition
(`layout.tsx`) that document's own ASCII excerpt omitted for brevity but that every multi-tab module in the
same tree (`accounting/`, `banking/`, `sales/`) already has, and that Inventory's own six-item sub-nav
(`NAVIGATION_SYSTEM.md → Sub-navigation → Inventory`) cannot render without:

```
app/(app)/inventory/
├── layout.tsx                      # Sub-nav: Products | Items | Movements | Transfers | Stock Counts |
│                                    # Warehouses, gated on the parent inventory.read permission
├── products/
│   └── page.tsx                    # Owned by the future Products frontend doc — linked, not respecified
├── items/
│   └── page.tsx                    # ★ THIS DOCUMENT — Stock on Hand, + the Valuation view toggle
├── movements/
│   └── page.tsx                    # ★ THIS DOCUMENT — Stock Movement History
├── transfers/
│   ├── page.tsx                    # ★ THIS DOCUMENT — Transfers list
│   └── new/
│       └── page.tsx                # ★ THIS DOCUMENT — Sensitive, always full-page (mirrors Banking Transfers)
├── counts/
│   └── [countId]/
│       └── page.tsx                # ★ THIS DOCUMENT — Stock Count workbench
└── warehouses/
    └── page.tsx                    # Owned by the future Warehouses frontend doc — linked, not respecified
```

Adjustments have no route of their own anywhere in this tree — deliberately. Per `# Purpose`, an adjustment
is created from a `Dialog` opened off a row on Items or Movements. This is not an oversight this document
works around; it is the same reasoning `docs/frontend/BANKING.md` applies to Bank Reconciliation
("opened from an account, never a bare index") turned one notch further: a reconciliation session runs long
enough across multiple sittings to deserve its own bookmarkable `[id]` route, while a single adjustment's
form surface (a reason code, a before/after quantity per line, a note) and its typical lifecycle (seconds to
minutes from draft to posted) do not. A direct link to a specific pending adjustment is still fully
shareable without a persisted route: `/inventory/items?adjust=771` opens the Items screen with the
Adjustment `Dialog` pre-loaded for `stock_adjustments.id = 771`, following the exact "deep-linkable state,
query string only" convention `docs/frontend/GENERAL_LEDGER.md` establishes for its own filter state.

**Transfer creation is a full page, not a dialog, for the same reason Banking Transfers is one.**
`stock_transfers` carries the identical create/approve/dispatch/receive permission split
(`docs/accounting/INVENTORY.md → Permissions`) that makes a bank transfer "sensitive" in
`FRONTEND_ARCHITECTURE.md`'s sense, and a transfer's own accounting consequence — a real
Debit-Inventory-at-destination/Credit-Inventory-at-source journal entry the instant branch-level inventory
sub-accounts are enabled (`docs/accounting/INVENTORY.md → Inventory Lifecycle → Transfer`) — is not something
this document treats as a quick-create modal, matching `FRONTEND_ARCHITECTURE.md`'s platform-wide rule
verbatim: "sensitive mutations never render as a quick-create modal." A Stock Adjustment, despite also
requiring an approval, does not clear that same bar: it corrects a single warehouse's own already-on-hand
quantity rather than relocating stock the receiving warehouse must track in transit for hours or days, and
the committed route tree above conspicuously has no `adjustments/` segment anywhere under `inventory/` —
the strongest available evidence that a dedicated page was never intended for it.

## Permission gates

| Gate | Permission | Effect if absent |
|---|---|---|
| Any Inventory screen visible at all | `inventory.read` | Sidebar's Inventory entry (and every route in this tree) does not render; a direct hit renders the shell-level `error.tsx`, never a silent redirect, per `NAVIGATION_SYSTEM.md → Permission-Aware Nav → Server truth, client courtesy`. |
| Unit cost, valuation layers, the Valuation view toggle | `inventory.valuation.view` | The toggle and every cost-bearing column/field are omitted, not disabled — per `docs/accounting/INVENTORY.md → Security`, unit cost is "commercially sensitive," so its *existence* on this screen is itself gated, unlike an ordinary permission-denied control that is safe to show-and-disable. |
| "Adjust stock" row action | `inventory.adjust` | Action omitted from the row's `DropdownMenu`. |
| Approve a pending adjustment | `inventory.adjust.approve` | The `ApprovalCard`'s Approve/Reject pair renders disabled with a permission tooltip rather than omitted — the request is already addressed to a specific approver, and hiding the whole card would look like a missed notification, mirroring `docs/frontend/BANKING.md`'s identical reasoning for `bank.payment.approve`. Additionally disabled outright (not just permission-gated) when `requested_by === currentUser.id`, mirroring the server's own dual-control rule that an approver is never the requester. |
| "New transfer" | `inventory.transfer.create` | Button omitted from both the Items page header and the Transfers list header. |
| Approve a pending transfer | `inventory.transfer.approve` | Same disabled-with-tooltip treatment as adjustment approval. |
| Dispatch an approved transfer | `inventory.transfer.dispatch` | Action omitted; a Warehouse Employee at the *destination* warehouse still sees the transfer in the list (read), just without a Dispatch button that only makes sense at the source. |
| Receive a transfer at destination | `inventory.transfer.receive` | Action omitted, symmetric to Dispatch. |
| Create/reserve a manual stock reservation | `inventory.reserve` | Omitted — in the overwhelming majority of cases a reservation is system-generated from a confirmed `sales_order`, not created directly on this screen; see `# Edge Cases`. |
| Release a reservation | `inventory.release` | Omitted. |
| Create a Stock Count | `inventory.count.create` | "New count" button omitted from the Stock Counts entry point. |
| Submit counted quantities | `inventory.count.submit` | Count-line inputs render read-only. |
| Close a count | `inventory.count.close` | "Close count" action disabled with a permission tooltip. `NAVIGATION_SYSTEM.md`'s own sub-navigation table names this same action `inventory.count.approve` — see the note directly below; this document uses the backend module's own literal key. |
| Bulk import / export | `inventory.import` / `inventory.export` | Buttons omitted independently of each other. |
| Configure reorder policy, valuation method, expiry windows | `inventory.settings.manage` | The reorder-point/quantity edit fields on an item's detail `Sheet` render read-only; the "Accept suggestion" action for an AI reorder-point recommendation is likewise gated on this key, since accepting a suggestion is itself a settings change. |
| Serial record management / recall execution | `inventory.serial.manage` | Serial-detail actions (status override, recall) omitted; the serial's read-only history remains visible under ordinary `inventory.read`. |
| Batch record management / quality-hold release | `inventory.batch.manage` | Batch-detail mutation actions omitted, read-only history remains. |

**Reconciling two permission spellings.** `NAVIGATION_SYSTEM.md → Sub-navigation → Inventory` lists Stock
Counts' close action as `inventory.count.approve`; `docs/accounting/INVENTORY.md → Permissions` and its own
`API` table both declare the literal key as `inventory.count.close` (`POST /inventory/counts/{id}/close`
requires `inventory.count.close`). These are not two different permissions — `inventory.count.approve` is
the nav document's descriptive shorthand for the same action, not a key this document invents a second
enforcement path for. The application enforces, and this document therefore uses throughout, the backend
module's own literal spelling: `inventory.count.close`. This is the identical refinement pattern
`docs/frontend/GENERAL_LEDGER.md` documents for `accounting.read` → `accounting.ledger.read`: a screen-level
document defers to its owning backend module's exact key, never a coarser or differently-spelled nav-map
gloss of it.

**Reconciling the Transfers nav gate.** The same nav table gates the entire "Transfers" sub-item on
`inventory.adjust`. Read against `docs/accounting/INVENTORY.md`'s own Role Grant Table, that gate would be
too narrow to be correct: Purchasing Manager and Purchasing Employee both hold `inventory.transfer.create`
(they legitimately move newly-received goods between warehouses) but neither holds `inventory.adjust` at
all — gating the tab on `inventory.adjust` would hide it from two roles the backend module explicitly grants
transfer capability to. `NAVIGATION_SYSTEM.md`'s own stated rule for exactly this situation is "grouping is
an information-architecture decision; the permission gate always matches the record's true owner" — this
document applies that rule as written: the Transfers tab (and its "New transfer" action) is gated on its
true owning permission, `inventory.transfer.create`, not the nav map's shorthand annotation. A role holding
`inventory.read` alone still sees the Transfers tab and list (read-only); `inventory.transfer.create` is
what additionally reveals the "New transfer" button.

## Roles — what renders

Reproduced from `docs/accounting/INVENTORY.md → Permissions → Role Grant Table` and translated into what a
role concretely sees on **these** screens, not the API's permission grant in the abstract:

| Role | What renders |
|---|---|
| Owner, Inventory Manager | Full four screens, every row action, Valuation view, Settings edits, approve/dispatch/receive/close on every workflow. |
| CEO, CFO, Finance Manager | Items/Movements read plus the Valuation view; approve pending Adjustments; none of the three can *create* an Adjustment or a Transfer (no `inventory.adjust`/`inventory.transfer.create`) — they only ever see the approval side of those queues. CFO and Finance Manager additionally reach the Settings edit fields; Finance Manager alone among these three can also create and close Stock Counts. |
| Senior Accountant, Accountant, Auditor, External Auditor | Read-only across all four screens, Valuation view included (unit cost is an audit-relevant figure for these roles); every mutating control is omitted, never merely disabled. |
| Warehouse Employee | The hands-on operational role: full Items/Movements, can create (not approve) Adjustments and Transfers, can create/submit/run Stock Counts end to end except the final Close (`inventory.count.close` is not granted), but never sees the Valuation view or unit cost anywhere (`inventory.valuation.view` withheld) and cannot reach Settings. |
| Sales Manager, Sales Employee | Items/Movements read (quantities only, Valuation view omitted); hold `inventory.reserve`/`inventory.release` in the abstract, but on these screens that manifests only as the ability to manually release a stuck reservation from an item's detail `Sheet` — the ordinary path to a reservation is a confirmed `sales_order`, which this document does not re-implement (`# Edge Cases`). |
| Purchasing Manager, Purchasing Employee | Items/Movements read plus the Valuation view (Purchasing legitimately needs landed cost to negotiate and to three-way-match); can create Transfers (moving newly received goods onward) but never Adjustments. |
| Read Only | Items/Movements read only, no Valuation view, no mutating control anywhere. |
| AI service account (Inventory Manager agent) | Never renders these screens as a "user." Its scoped read access feeds the AI panels described in `# AI Integration`; it is structurally incapable of holding any `.approve`, `.dispatch`, `.receive`, or `.close` permission regardless of role grant, per the platform-wide rule that sensitive stock- and ledger-affecting operations are never AI-only. Its own drafts (`auto_generated=true` Adjustments/Transfers) appear in the ordinary human queues, visually tagged, never in a separate AI-only list. |

# Layout & Regions

All four layouts below sit inside the persistent app shell (`Sidebar`/`Topbar`, out of this document's
scope per `NAVIGATION_SYSTEM.md`) and, at `md:` and up, under `inventory/layout.tsx`'s own six-tab sub-nav.
Each region streams independently behind its own `<Suspense>` boundary per
`FRONTEND_ARCHITECTURE.md → Streaming with Suspense`: a slow AI panel never delays a table from painting,
and a failure in one region renders only that region's own `ErrorState` (`# States`).

## Stock on Hand (`items/page.tsx`) — Laptop (`lg`, 1024px) and up

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Products   Items   Movements   Transfers   Stock Counts   Warehouses      [sub-nav tabs]  │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  Inventory · Stock on hand                          [Import] [Export ▾] [Adjust stock ▾]  │
│  1,204 SKUs across 4 warehouses                                                            │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐                                │
│ │ Total value │ │ Below       │ │ Out of     │ │ Open        │   KPI band (KpiTile × 4)     │
│ │ KD 218,406  │ │ reorder     │ │ stock      │ │ transfers   │                              │
│ │ ▲ 3.1%      │ │ 22 SKUs ⚠  │ │ 4 SKUs ⛔  │ │ 6           │                              │
│ └────────────┘ └────────────┘ └────────────┘ └────────────┘                                │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌ AI · Inventory Manager ─────────────────────────────────────────────────────────────┐    │
│ │ ● 3 reorder suggestions ready · 1 dead-stock cluster (KD 4,120 tied up)             │    │
│ │                                              [Review]                     [Dismiss] │    │
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
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

Region inventory:

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Products · Items (active) · Movements · Transfers · Stock Counts · Warehouses, real `<Link>`s per `inventory/layout.tsx` (Server Component) | Not streamed — part of the layout shell |
| Page header | `<h1>Inventory · Stock on hand</h1>`, a one-line SKU/warehouse-count summary, and three permission-gated actions (`Import`, `Export`, `Adjust stock` — the last a `DropdownMenu` that opens the Adjustment `Dialog` pre-scoped to whichever row is currently selected, or unscoped if none is) | Renders immediately with the page shell |
| KPI band | Four `KpiTile`s: Total Value (base currency, Valuation-permission-gated), Below Reorder count, Out of Stock count, Open Transfers count | Own `<Suspense>` boundary — bounded summary call, independent of the table |
| AI panel | A dismissible `AIProposalPanel`-family card synthesizing the Inventory Manager agent's reorder/dead-stock/ABC-XYZ output — omitted entirely if the caller's role has no AI-visibility scope | Own `<Suspense>` boundary; independently failable without blanking the rest of the page |
| Filter bar | `WarehouseBinPicker` (warehouse only, bin left unscoped at this level), a category `Select`, a "Below reorder only" `Switch`, `Input` search, and the **Stock on hand / Valuation** view toggle (`Tabs`, `size="sm"`) — the toggle itself is omitted, not disabled, without `inventory.valuation.view` | Not streamed — client-side filter state feeding the table query |
| Main grid | `InventoryItemsTable`, a preset `DataTable` — columns and both view presets specified in full in `# Components Used` and `# Data & State` | Own `<Suspense>` boundary — `GET /inventory/items` |

**The Valuation view is a column preset over the identical table and endpoint, not a second screen.**
Toggling it swaps the visible columns (On Hand/Reserved/On Order/In Transit/Available → Unit Cost/Valuation
Method/Total Value/Cost Layers) and requests the same `GET /api/v1/inventory/items` list with an additional
`include=valuation` query parameter, exactly the way `docs/frontend/BANKING.md`'s Accounts and Transactions
screens reuse one `<BankTransactionsTable/>` behind two different default filters rather than building two
tables. Clicking any row's "View cost layers" action (Valuation view only) opens a `Sheet` detail —
`ValuationLayersSheet` — rendering the FIFO/LIFO/Specific-Identification open-layer ladder or the
Weighted/Moving-Average recompute history for that exact `(product, warehouse)`, per
`docs/accounting/INVENTORY.md → Inventory Valuation`'s six methods.

## Movements (`movements/page.tsx`)

Structurally the simplest of the four screens — a single filtered, cursor-paginated history table, with no
KPI band and no create action, because nothing on this screen is ever created directly; every row here was
produced by an event elsewhere (a receipt, a sale, a transfer, an adjustment, a count closing):

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Inventory · Movements                                                     [Export ▾]     │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  [Warehouse: All ▾] [Product: Search…] [Type: All ▾] [Date range: Last 30 days ▾]           │
│  ┌────────────────────────────────────────────────────────────────────────────────────┐    │
│  │ Date      Product         Warehouse   Type ▾            Qty     Unit cost   Source │    │
│  │ ──────────────────────────────────────────────────────────────────────────────────│    │
│  │ Jul 16    WGT-100 Widget  KWT-01      ⊕ Purchase receipt +150   2.200 KWD  PO-1002 │    │
│  │ Jul 16    WGT-100 Widget  KWT-01      ⊖ Sale             −120    2.000 KWD  INV-3301│   │
│  │ Jul 15    FLT-220 Filter  FAH-02      ⊖ Adjustment (damage) −8   2.100 KWD  ADJ-771 │    │
│  │ …(virtualized past ~200 rows, identical to Ledger's own rule)…                      │    │
│  └────────────────────────────────────────────────────────────────────────────────────┘    │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

Every row's Source cell is a live link resolved the same way `docs/frontend/GENERAL_LEDGER.md`'s
`LedgerDrillDownSheet` resolves `journal_entries.source_type`/`source_id` — here resolving
`stock_movements.source_type`/`source_id` to `purchase_order → /purchasing/purchase-orders/{id}`,
`sales_order`/`delivery → /sales/orders/{id}`, `stock_transfer → /inventory/transfers/{id}`,
`stock_adjustment → /inventory/items?adjust={id}` (the query-string opener from `# Route & Access`), and
`stock_count → /inventory/counts/{id}`; a `source_type` outside this fixed set renders as plain,
non-linked text rather than a broken link, exactly mirroring the Ledger's own rule for an unmapped source.

## Transfers (`transfers/page.tsx` list, `transfers/new/page.tsx` create)

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Inventory · Transfers                                                  [New transfer]     │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  [Status: Open ▾] [Warehouse: All ▾] [Search…]                                              │
│  ┌────────────────────────────────────────────────────────────────────────────────────┐    │
│  │ Transfer #      From → To                Status            Items   Requested      │    │
│  │ ──────────────────────────────────────────────────────────────────────────────────│    │
│  │ TRF-2026-000340 Main Wh → Fahaheel Br.    ● Pending approval   2   Jul 16 · A.Saleh│    │
│  │ TRF-2026-000338 Fahaheel Br. → Main Wh    ◐ In transit         1   Jul 14 · System │    │
│  │ TRF-2026-000335 Main Wh → Salmiya Br.     ✓ Completed          5   Jul 10 · A.Saleh│    │
│  └────────────────────────────────────────────────────────────────────────────────────┘    │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

`transfers/new/page.tsx` is a three-step form (Source/Destination → Line items → Review), each item line a
`WarehouseBinPicker`-scoped product row with quantity and optional serial/batch picker, following the exact
"create-then-approve flow" composition `COMPONENT_LIBRARY.md → Pattern 2` already establishes for Journal
Entries: the same route renders the edit form while `status ∈ {draft, rejected}`, an `ApprovalCard` once
`pending_approval`, and a read-only lifecycle timeline (Dispatch/Receive buttons appearing only at the
stage they apply to) once `approved` or later — never three separate pages branching on status.

## Stock Counts (`counts/[countId]/page.tsx`)

A count is always *created* from a compact `Dialog` (warehouse, count type, blind-count toggle, scope
filter chips such as "ABC class A only" or a bin range) reachable from a "New count" button on this screen's
own index — the index itself is `GET /inventory/counts` rendered as a plain list identical in shape to
Transfers' list above — and creating one immediately navigates to its `[countId]` workbench:

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Count PHY-2026-000012 · Main Warehouse · Physical · Blind          ● In progress          │
│  Scope: All active SKUs                              Started Jul 16, 08:02 by A. Saleh     │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  Progress: 812 / 1,204 lines counted                          [Scan/enter next item…]      │
│  ┌────────────────────────────────────────────────────────────────────────────────────┐    │
│  │ Product          Bin        Expected*   Counted   Variance   Recount?   Counted by │    │
│  │ ──────────────────────────────────────────────────────────────────────────────────│    │
│  │ WGT-100 Widget    B-04-02    (hidden)      238        —          —      A. Saleh   │    │
│  │ FLT-220 Filter    A-12-01    (hidden)       10        —      Required   A. Saleh   │    │
│  │ PMP-310 Pump      C-01-08    (hidden)      pending    —          —          —      │    │
│  │ …                                                                                    │    │
│  └────────────────────────────────────────────────────────────────────────────────────┘    │
│                                                        [Save progress]   [Close count]      │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

`Expected*` renders literally as `(hidden)` whenever `blind_count = true` (the default), per
`docs/accounting/INVENTORY.md → Inventory Operations → Cycle Count` — the counter must never see the system
quantity while entering their own count, which is the entire point of a blind count. The line-entry region
is this document's one genuinely spreadsheet-like surface (`# Accessibility`); every other table on these
four screens is a plain read/sort/filter/navigate table.

## Adjustment (`Dialog`, no route)

```
┌─ Adjust stock — WGT-100 Widget ───────────────────────────────────────── ✕ ┐
│ Warehouse: Main Warehouse (Kuwait)              Bin: B-04-02                │
│ Reason: [ Damage ▾ ]                                                        │
│ Quantity before: 60         Quantity after: [ 52 ]         Δ −8            │
│ Notes: [ Water damage on shelf B-04-02, found during morning walk-through ] │
├──────────────────────────────────────────────────────────────────────────┤
│ Estimated value impact: −KD 16.800 (at current cost)                      │
│ This adjustment exceeds KD 100.000 and will route for a second approval. │
├──────────────────────────────────────────────────────────────────────────┤
│                                              [Cancel]   [Submit for approval]│
└──────────────────────────────────────────────────────────────────────────┘
```

The "exceeds threshold" notice is not a client-side guess: it reflects
`companies.adjustment_approval_threshold` (`docs/accounting/INVENTORY.md → Inventory Operations →
Adjustment`), fetched once per session and re-validated server-side on submit — the `Dialog` shows it
proactively so a Warehouse Employee is never surprised that a routine correction became a two-person
approval, but the server's own `422`/`APPROVAL_REQUIRED` response (`# Data & State`) is what actually
decides the resulting `status`, never the client's own threshold comparison.

## AI panel (all four screens)

Persistent 360px rail at `3xl:` and above, collapsible `Sheet` below it — the identical `3xl:` threshold and
region `LAYOUT_SYSTEM.md` defines as the shell's fourth "AI Rail" region and `RESPONSIVE_DESIGN.md` uses for
the AI Command Center's own Ask AI panel and `docs/frontend/GENERAL_LEDGER.md`'s AI panel. Contents differ
by screen (`# AI Integration`): reorder suggestions and dead-stock/ABC-XYZ on Items, shrinkage/anomaly flags
on Movements, rebalancing-transfer provenance on Transfers, and a count-quality read (expected recount rate
vs. historical baseline) on Stock Counts.

# Components Used

| Component | Source | Role on these screens |
|---|---|---|
| `Tabs` | Primitive | Sub-nav (Products/Items/Movements/Transfers/Stock Counts/Warehouses); Stock-on-hand/Valuation view toggle |
| `KpiTile` | Finance (`components/dashboard/kpi-tile.tsx`) | Total Value, Below Reorder, Out of Stock, Open Transfers |
| `TrendSparkline` | Finance | 30-day on-hand-value trend inside the Total Value tile; Demand Forecast Agent's predicted-demand shape inside an item's detail `Sheet` |
| `InventoryItemsTable` | **New** — `components/inventory/inventory-items-table.tsx`, wraps `DataTable` | Stock on Hand's main grid, both view presets |
| `QuantityCell` | **New** — `components/inventory/quantity-cell.tsx` | Every quantity-bucket cell; modeled directly on `AmountCell`'s contract (`# Components Used` implementation below) but unitless/currency-less |
| `AmountCell` | Finance (`components/accounting/amount-cell.tsx`) | Every base-currency value cell in the Valuation view and on the KPI band |
| `CurrencyTag` | Finance (`components/accounting/currency-tag.tsx`) | Transaction-currency annotation on a Movements row whose `currency_code` differs from the company's base currency |
| `StatusPill` | Finance (`components/shared/status-pill.tsx`), new domains `stock_item` (Available/Low/Out), `stock_adjustment`, `stock_transfer`, `stock_count` | Availability state on Items rows; lifecycle state on Adjustments/Transfers/Counts |
| `WarehouseBinPicker` | **New** — `components/inventory/warehouse-bin-picker.tsx` | Warehouse (and, where `bin_tracking_enabled`, bin) scoping on every filter bar and every create form |
| `ValuationLayersSheet` | **New** — `components/inventory/valuation-layers-sheet.tsx` | The Valuation view's "View cost layers" drill-down |
| `AgingBar` | Finance (`components/accounting/aging-bar.tsx`) | Reused verbatim for the Inventory Aging report inside an item's detail `Sheet` — its fixed `0-30/31-60/61-90/90+` bucket set is the identical bucket set `docs/accounting/INVENTORY.md → Reports → Inventory Aging` already declares, so no new aging component is built |
| `StockMovementsTable` | **New** — `components/inventory/stock-movements-table.tsx`, wraps `DataTable` | Movements' main grid |
| `StockAdjustmentDialog` | **New** — `components/inventory/stock-adjustment-dialog.tsx` | The Adjustment workflow (React Hook Form + Zod) |
| `StockTransferForm` | **New** — `components/inventory/stock-transfer-form.tsx` | `transfers/new/page.tsx`'s three-step wizard |
| `StockCountGrid` | **New** — `components/inventory/stock-count-grid.tsx` | Stock Counts' line-entry surface — the ARIA `grid` pattern, extending `ACCESSIBILITY.md`'s roving-tabindex hook (`# Accessibility`) |
| `BarcodeScanInput` | **New** — `components/inventory/barcode-scan-input.tsx` | Scan-first entry on receiving, picking, and counting flows, with an always-visible keyboard fallback; the same component `FRONTEND_ARCHITECTURE.md → Bundle Optimization` already names as "the barcode scanner used only in Inventory's mobile-web counting flow" and `next/dynamic`-splits out of the main bundle |
| `ReorderAlertBanner` | **New** — `components/inventory/reorder-alert-banner.tsx` | The below-reorder/out-of-stock summary banner, shared visual language with the Dashboard's and AI Command Center's own "Inventory Alerts" widget (`docs/frontend/DASHBOARD.md`, `docs/frontend/AI_COMMAND_CENTER.md`) — same underlying data, condensed here to the items actually visible on this screen |
| `ConfidenceBadge` | AI (`components/ai/confidence-badge.tsx`) | Reorder-suggestion confidence, ABC/XYZ classification confidence, shrinkage/fraud flag confidence |
| `AIProposalPanel` | AI (`components/ai/ai-proposal-panel.tsx`) | Reorder-point promotion, dead-stock action, rebalancing-transfer review |
| `ApprovalCard` | Shared (`components/shared/approval-card.tsx`), `kind="stock_adjustment"` / `kind="stock_transfer"` | Any adjustment/transfer awaiting the caller's own approval |
| `Sheet` | Primitive | Item detail (buckets, valuation, aging, serials/batches), `ValuationLayersSheet`, filter overflow below `md:` |
| `DropdownMenu` | Primitive | Row actions on every table, permission-filtered (items omitted, never disabled, for role-structural gaps) |
| `Dialog` / `AlertDialog` | Primitive | Adjustment workflow, count-creation, recount confirmation, forced-close confirmation |
| `Skeleton` | Primitive | Per-region loading placeholders (`# States`) |
| `EmptyState` / `ErrorState` | Shared | Zero-items onboarding, filtered-empty tables, per-region fetch failure |

## `QuantityCell`

A new, minimal sibling to `AmountCell` for the one figure type Inventory renders constantly that `AmountCell`
cannot: a bare quantity with no currency. It is deliberately not `AmountCell` with `currencyCode` made
optional — that would invite a column to silently drop currency formatting by omission — but a distinct,
narrow component with the identical numeral, alignment, and RTL discipline:

```tsx
// components/inventory/quantity-cell.tsx
import { cn } from '@/lib/utils';

interface QuantityCellProps {
  quantity: string;               // raw NUMERIC(18,4) string, e.g. "205.0000"
  unitLabel?: string;              // from units_of_measure, e.g. "pcs", "kg" — omitted renders a bare number
  emphasis?: 'default' | 'muted' | 'warning' | 'strong';
  align?: 'start' | 'end';
}

export function QuantityCell({ quantity, unitLabel, emphasis = 'default', align = 'end' }: QuantityCellProps) {
  const numeric = Number(quantity);
  const formatted = new Intl.NumberFormat('en', {
    maximumFractionDigits: numeric % 1 === 0 ? 0 : 2,
    numberingSystem: 'latn', // Western Arabic numerals even under an Arabic locale — same rule as AmountCell
  }).format(numeric);

  return (
    <span
      dir="ltr"
      className={cn(
        'font-mono tabular-nums whitespace-nowrap',
        align === 'end' ? 'text-end' : 'text-start',
        emphasis === 'muted' && 'text-ink-500',
        emphasis === 'warning' && 'text-status-warning',
        emphasis === 'strong' && 'font-semibold text-ink-950',
      )}
    >
      {formatted}
      {unitLabel && <span className="ms-1 text-ink-500">{unitLabel}</span>}
    </span>
  );
}
```

`emphasis="warning"` is reserved for a bucket that is non-zero but should draw a second look — a Damaged or
Quarantined cell with a value greater than zero — never applied to On Hand/Reserved/On Order/In
Transit/Available under ordinary circumstances, so a warning-colored quantity always means "look at this,"
never "here is a normal number."

## `InventoryItemsTable`

```tsx
// components/inventory/inventory-items-table.tsx
'use client';

import { DataTable } from '@/components/shared/data-table';
import { QuantityCell } from '@/components/inventory/quantity-cell';
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { usePermission } from '@/hooks/use-permission';
import type { ColumnDef } from '@tanstack/react-table';
import type { InventoryItem } from '@/types/inventory';
import { PackagePlus, Eye, ArrowLeftRight, Layers } from 'lucide-react';

interface InventoryItemsTableProps {
  view: 'stock' | 'valuation';
  defaultFilters?: Record<string, unknown>;
  onAdjust: (item: InventoryItem) => void;
  onTransfer: (item: InventoryItem) => void;
  onOpenValuation: (item: InventoryItem) => void;
}

export function InventoryItemsTable({ view, defaultFilters, onAdjust, onTransfer, onOpenValuation }: InventoryItemsTableProps) {
  const canViewValuation = usePermission('inventory.valuation.view');
  const stockColumns: ColumnDef<InventoryItem>[] = [
    { accessorKey: 'product.sku', header: 'Product' },
    { accessorKey: 'warehouse_bin_label', header: 'Warehouse / Bin' },
    { accessorKey: 'quantity_on_hand', header: 'On hand', cell: ({ row }) => <QuantityCell quantity={row.original.quantity_on_hand} /> },
    { accessorKey: 'quantity_reserved', header: 'Reserved', cell: ({ row }) => <QuantityCell quantity={row.original.quantity_reserved} emphasis="muted" /> },
    { accessorKey: 'quantity_on_order', header: 'On order', cell: ({ row }) => <QuantityCell quantity={row.original.quantity_on_order} emphasis="muted" /> },
    { accessorKey: 'quantity_in_transit', header: 'In transit', cell: ({ row }) => <QuantityCell quantity={row.original.quantity_in_transit} emphasis="muted" /> },
    { accessorKey: 'available_to_sell', header: 'Available', cell: ({ row }) => <QuantityCell quantity={row.original.available_to_sell} emphasis="strong" /> },
    { accessorKey: 'stock_status', header: 'Status', cell: ({ row }) => <StatusPill domain="stock_item" status={row.original.stock_status} /> },
  ];
  const valuationColumns: ColumnDef<InventoryItem>[] = [
    { accessorKey: 'product.sku', header: 'Product' },
    { accessorKey: 'warehouse_bin_label', header: 'Warehouse / Bin' },
    { accessorKey: 'valuation_method', header: 'Method' },
    { accessorKey: 'average_cost_base', header: 'Unit cost', cell: ({ row }) => <AmountCell amount={row.original.average_cost_base} currencyCode="KWD" /> },
    { accessorKey: 'total_value_base', header: 'Total value', cell: ({ row }) => <AmountCell amount={row.original.total_value_base} currencyCode="KWD" emphasis="strong" /> },
  ];

  return (
    <DataTable
      columns={view === 'valuation' && canViewValuation ? valuationColumns : stockColumns}
      resource="inventory/items"
      paginationMode="page"
      defaultSort="product.sku"
      defaultFilters={{ ...defaultFilters, include: view === 'valuation' ? 'valuation' : undefined }}
      searchable
      getRowId={(row) => String(row.id)}
      emptyState={{ title: 'No stock tracked yet', description: 'Add a product and receive stock against a purchase order to see it here.' }}
      onRowClick={view === 'valuation' ? onOpenValuation : undefined}
      rowActions={(row) => [
        { label: 'Adjust stock', icon: PackagePlus, permission: 'inventory.adjust', onClick: () => onAdjust(row) },
        { label: 'Transfer', icon: ArrowLeftRight, permission: 'inventory.transfer.create', onClick: () => onTransfer(row) },
        { label: 'View cost layers', icon: Layers, permission: 'inventory.valuation.view', onClick: () => onOpenValuation(row) },
        { label: 'View movements', icon: Eye, onClick: () => router.push(`/inventory/movements?filter[product_id]=${row.productId}`) },
      ]}
    />
  );
}
```

`view === 'valuation' && canViewValuation` is the load-bearing guard: even if a component prop somehow
requested the Valuation columns for a caller lacking `inventory.valuation.view`, the table falls back to the
Stock columns rather than ever rendering a cost figure to a role the permission model withholds it from —
the same defense-in-depth `DataTable`'s `rowActions` already applies per-action, applied here to an entire
column set.

## Icon additions

`ICONOGRAPHY.md → Semantic Icon Set` already assigns `Boxes` to Inventory generally and the `Package*`
family to movement types; this document extends that same table with the concepts it introduces, following
its own stated contribution rule ("any engineer encountering a concept not yet listed must add a row here
in the same PR"):

| Concept | Icon | Rationale |
|---|---|---|
| Stock Transfers | `ArrowLeftRight` | Reuses the identical directional-exchange glyph the table already assigns to Bank Reconciliation's transfer-matching secondary icon — a stock transfer is the same concept (movement from one place to another) applied to goods instead of cash. |
| Valuation / cost layers | `Layers` | Reuses the glyph already listed as Chart of Accounts' "account grouping" secondary icon — a more literal fit here, since FIFO/LIFO valuation genuinely is a stack of layers. |
| Below-reorder warning | `TriangleAlert` | No new icon — reuses the platform's existing "Alerts — warning" glyph directly. |
| Out-of-stock / zero-available | `OctagonAlert` | No new icon — reuses the platform's existing "Alerts — critical" glyph, reserved for the highest severity tier, which a zero-available SKU genuinely is. |
| Stock Counts | `ScanBarcode` | **New row.** A cycle count or physical inventory is QAYD's one workflow built scan-first from the ground up; this glyph is distinct from generic `Barcode` (the raw concept) to signal "the counting action," not merely "a barcode exists." |
| Barcode (`product_barcodes`) | `Barcode` | **New row.** |
| QR Code (bin labels, serial verification) | `QrCode` | **New row.** |
| RFID | `Radio` | **New row.** Lucide has no literal RFID glyph; a radio-frequency broadcast icon is the nearest unambiguous stand-in and is used nowhere else in the platform's icon set. |

# Data & State

## Endpoints

Every path below is declared verbatim in `docs/accounting/INVENTORY.md → API`, versioned under
`/api/v1/inventory/`, Bearer-authenticated, scoped via `X-Company-Id`, and returning the platform's standard
envelope (`success`, `data`, `message`, `errors`, `meta`, `request_id`, `timestamp`):

| Method | Path | Permission | Used by |
|---|---|---|---|
| GET | `/inventory/items` | `inventory.read` | `InventoryItemsTable`, both view presets |
| GET | `/inventory/items/{id}` | `inventory.read` (+ `inventory.valuation.view` for nested layer/cost fields) | Item detail `Sheet`, `ValuationLayersSheet` |
| GET | `/inventory/movements` | `inventory.read` | `StockMovementsTable` |
| POST | `/inventory/movements` | `inventory.movements.create` | Not exposed as a primary action on these screens — reserved for the rare manual-movement case, gated behind an "Advanced" menu item Warehouse Employee roles do not typically hold |
| POST | `/inventory/adjustments` | `inventory.adjust` | `StockAdjustmentDialog` submit (draft) |
| POST | `/inventory/adjustments/{id}/submit` | `inventory.adjust` | `StockAdjustmentDialog`, when saved as draft before submitting |
| POST | `/inventory/adjustments/{id}/approve` | `inventory.adjust.approve` | `ApprovalCard`, `kind="stock_adjustment"` |
| POST | `/inventory/transfers` | `inventory.transfer.create` | `StockTransferForm` submit (draft) |
| POST | `/inventory/transfers/{id}/dispatch` | `inventory.transfer.dispatch` | Transfer detail lifecycle action |
| POST | `/inventory/transfers/{id}/receive` | `inventory.transfer.receive` | Transfer detail lifecycle action, supports partial-quantity confirmation per line |
| POST | `/inventory/reservations` | `inventory.reserve` | Item detail `Sheet`'s manual-reservation action (rare; see `# Edge Cases`) |
| POST | `/inventory/reservations/{id}/release` | `inventory.release` | Item detail `Sheet`'s "Release reservation" action |
| GET | `/inventory/search` | `inventory.read` | The global `CommandPalette`'s Records group (`NAVIGATION_SYSTEM.md`), and this screen's own barcode/serial/batch lookup in `BarcodeScanInput` |
| POST | `/inventory/import` | `inventory.import` | Items page header's "Import" action |
| GET | `/inventory/export` | `inventory.export` | Items/Movements/Transfers/Counts export menus |
| POST | `/inventory/bulk-adjust` | `inventory.adjust` | Stock Count close, generating variance adjustments in one batch |
| POST | `/inventory/rfid-events` | `inventory.movements.create` | Ingestion only — device-authenticated, never called from a human session on these screens |
| GET | `/inventory/counts` | `inventory.count.create` region gate not required for read; `inventory.read` | Stock Counts index |
| POST | `/inventory/counts` | `inventory.count.create` | "New count" `Dialog` |
| POST | `/inventory/counts/{id}/lines` | `inventory.count.submit` | `StockCountGrid` line entry |
| POST | `/inventory/counts/{id}/close` | `inventory.count.close` | Stock Count workbench's "Close count" action |

**This document's own minimal, necessary extensions.** Four endpoints are implied by the screens above but
not individually spelled out in `docs/accounting/INVENTORY.md → API` — each follows the exact list/detail
convention every other resource in the same table already uses, rather than inventing a new shape:

| Method | Path | Permission | Why it must exist |
|---|---|---|---|
| GET | `/inventory/adjustments` | `inventory.adjust` (own) or `inventory.adjust.approve` (all pending) | Backs the "Pending approval" queue an approver role needs; the backend already declares `POST .../adjustments`, `.../submit`, `.../approve` for the identical resource — a list/detail counterpart is the same pattern `stock_transfers`, `stock_counts` all already have |
| GET | `/inventory/adjustments/{id}` | Same as above | The `ApprovalCard`'s detail view and the Movements screen's "Source" drill-down target for an adjustment-caused row |
| GET | `/inventory/transfers` | `inventory.read` | Backs the Transfers list; the backend declares create/dispatch/receive for `stock_transfers` but not the list itself |
| GET | `/inventory/transfers/{id}` | `inventory.read` | Backs the Transfer detail/lifecycle page |
| GET | `/inventory/counts/{id}` | `inventory.read` | Backs the Stock Count workbench header and its nested `stock_count_lines`; the backend declares the lines-submit and close actions but not a bare detail fetch |
| PATCH | `/inventory/items/{id}` | `inventory.settings.manage` | Edits `reorder_point`/`reorder_quantity`/`max_stock_level` — the exact fields `inventory.settings.manage`'s own declared description ("configure... reorder policies") already covers |
| POST | `/inventory/items/{id}/accept-reorder-suggestion` | `inventory.settings.manage` | Promotes the AI Stock Optimization agent's `suggested_reorder_point` to the live `reorder_point`, per `docs/accounting/INVENTORY.md → AI Responsibilities` ("written to a draft field... pending Inventory Manager approval to promote it") |

## Filters

Every list on these four screens uses the platform's bracketed filter grammar
(`docs/api/API_FILTERING_SORTING.md`): `filter[warehouse_id]`, `filter[product_id]`,
`filter[category_id]`, `filter[below_reorder]` (boolean shortcut for
`quantity_on_hand <= reorder_point`, served by the backend's own partial index on
`needs_reorder`, `docs/accounting/INVENTORY.md → Performance`), `filter[movement_type][in]`,
`filter[source_type]`/`filter[source_id]`, `filter[status][in]` (Adjustments/Transfers/Counts), and
`filter[created_at][between]` or the named `range=` shortcut for Movements' date filter. `q` powers the
`InventoryItemsTable`/`StockMovementsTable`'s search box against each resource's declared
`SEARCHABLE_FIELDS` (SKU, product name in both languages, barcode).

## Query keys, hooks

```ts
// lib/api/query-keys.ts — extends the platform's per-resource key factory
export const inventoryKeys = {
  items: {
    all: ['inventory', 'items'] as const,
    list: (filters: ItemFilters, view: 'stock' | 'valuation') =>
      [...inventoryKeys.items.all, 'list', view, filters] as const,
    detail: (id: number, withValuation: boolean) =>
      [...inventoryKeys.items.all, 'detail', id, { withValuation }] as const,
  },
  movements: {
    all: ['inventory', 'movements'] as const,
    list: (filters: MovementFilters) => [...inventoryKeys.movements.all, 'list', filters] as const,
  },
  adjustments: {
    all: ['inventory', 'adjustments'] as const,
    list: (filters: AdjustmentFilters) => [...inventoryKeys.adjustments.all, 'list', filters] as const,
    detail: (id: number) => [...inventoryKeys.adjustments.all, 'detail', id] as const,
  },
  transfers: {
    all: ['inventory', 'transfers'] as const,
    list: (filters: TransferFilters) => [...inventoryKeys.transfers.all, 'list', filters] as const,
    detail: (id: number) => [...inventoryKeys.transfers.all, 'detail', id] as const,
  },
  counts: {
    all: ['inventory', 'counts'] as const,
    list: (filters: CountFilters) => [...inventoryKeys.counts.all, 'list', filters] as const,
    detail: (id: number) => [...inventoryKeys.counts.all, 'detail', id] as const,
  },
  ai: {
    reorderSuggestions: (filters: ItemFilters) => ['inventory', 'ai', 'reorder-suggestions', filters] as const,
    deadStock: (filters: ItemFilters) => ['inventory', 'ai', 'dead-stock', filters] as const,
    flags: (filters: ItemFilters) => ['inventory', 'ai', 'flags', filters] as const,
  },
};
```

```ts
// lib/api/hooks/use-inventory-items.ts
export function useInventoryItems(filters: ItemFilters, view: 'stock' | 'valuation') {
  return useQuery({
    queryKey: inventoryKeys.items.list(filters, view),
    queryFn: () => api.get<ItemsPage>('/inventory/items', {
      params: { ...toFilterParams(filters), include: view === 'valuation' ? 'valuation' : undefined, per_page: 25 },
    }),
    placeholderData: keepPreviousData,
    staleTime: 10_000, // "Live/derived figures" data class, per FRONTEND_ARCHITECTURE.md's cache-tuning table
  });
}

export function useAdjustStock() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateAdjustmentPayload) => api.post<Adjustment>('/inventory/adjustments', payload),
    onSuccess: (adjustment) => {
      queryClient.invalidateQueries({ queryKey: inventoryKeys.items.all });
      queryClient.invalidateQueries({ queryKey: inventoryKeys.adjustments.all });
      toast.success(
        adjustment.status === 'pending_approval'
          ? t('inventory.adjustment.createdPendingApproval', { number: adjustment.adjustmentNumber })
          : t('inventory.adjustment.created', { number: adjustment.adjustmentNumber }),
      );
    },
  });
}

export function useAcceptReorderSuggestion() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (itemId: number) => api.post(`/inventory/items/${itemId}/accept-reorder-suggestion`),
    onSuccess: (_, itemId) => {
      queryClient.invalidateQueries({ queryKey: inventoryKeys.items.detail(itemId, false) });
      queryClient.invalidateQueries({ queryKey: inventoryKeys.ai.reorderSuggestions });
    },
  });
}
```

`useInventoryItems`'s `staleTime: 10_000` — not `0` — is a deliberate departure from
`docs/frontend/GENERAL_LEDGER.md`'s ledger-entries hook (`staleTime: 0`): a ledger line is either posted or
it does not exist, so any staleness at all is wrong the instant it happens, while `inventory_items` is a
Redis-cached, trigger-maintained projection whose own backing cache already carries a sub-second
invalidation window (`docs/accounting/INVENTORY.md → Performance`) — layering a second, slightly looser
client-side staleness window on top of an already-fresh server cache avoids a redundant network round-trip
on every render without ever showing data meaningfully behind reality, and Realtime (below) still
force-invalidates immediately on any movement affecting the visible scope regardless of this window.

## Realtime

`private-company.{company_id}.inventory.{warehouse_id}` (company-wide `private-company.{company_id}.inventory`
when no single warehouse is scoped), following the platform's fixed
`private-company.{id}.<feature>[.{sub_id}]` channel convention `docs/frontend/GENERAL_LEDGER.md` already
documents for its own ledger channel:

```ts
// lib/api/hooks/use-inventory-realtime.ts
'use client';
import { useEffect, useState } from 'react';
import { echo } from '@/lib/realtime/echo';

export function useInventoryRealtime(companyId: string, warehouseId: number | null) {
  const [hasNewActivity, setHasNewActivity] = useState(false);

  useEffect(() => {
    const channelName = warehouseId ? `company.${companyId}.inventory.${warehouseId}` : `company.${companyId}.inventory`;
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

Exactly as `docs/frontend/GENERAL_LEDGER.md` insists for its own ledger channel, a realtime event never
splices a row into a table a user is mid-scroll through — it only raises the same non-disruptive "New
activity — Refresh" banner (`# States`), because a `stock_movements` row landing while a Warehouse Employee
is scrolling Items or Movements must never reorder or renumber the rows they are currently looking at.

## AI agents feeding these screens

| Agent | Role on these screens | Autonomy |
|---|---|---|
| Inventory Manager | Reorder-point/quantity suggestions, dead-stock ranking, ABC/XYZ classification, rebalancing-transfer drafts | Suggest-only or requires-approval, per action (`# AI Integration`) |
| Forecast Agent | Demand-forecast sparkline feeding the reorder-suggestion confidence and an item's detail `Sheet` | Suggest-only |
| Fraud Detection | Duplicate-serial flags, shrinkage-pattern flags on Movements/Adjustments | Suggest-only, routes to Auditor |
| Auditor | Shrinkage investigation context on flagged rows | Suggest-only |

The Warehouse Optimization capability's *re-slotting* recommendations (bin-to-bin, pick-frequency-driven)
are deliberately **not** rendered anywhere in this document — that surface belongs to the future Warehouses
frontend document, since re-slotting is a bin/rack concern, not a quantity/value concern, matching
`docs/frontend/GENERAL_LEDGER.md`'s own discipline of not "manufactur[ing] a UI slot" for an agent output
that belongs to a screen it does not own. The same agent's *rebalancing-transfer* output, by contrast,
belongs here in full, because a rebalancing recommendation resolves into an ordinary `stock_transfers` row
that flows through the identical Transfers screen a human-authored transfer does.

# Interactions & Flows

**Landing on Stock on Hand.** Unlike `docs/frontend/GENERAL_LEDGER.md`'s Ledger screen, Items does not
require a selection before it fetches — it defaults to an unscoped, company-wide, paginated list (the same
"list screen" composition `COMPONENT_LIBRARY.md → Pattern 1` establishes for Journal Entries), because a
company's total SKU count is bounded and browsable in a way a multi-year ledger's line count is not; the
Warehouse/Category filters narrow an already-rendering table rather than gating whether it renders at all.

**Switching Stock on hand ↔ Valuation.** Toggling the view swaps `InventoryItemsTable`'s column set and
adds `include=valuation` to the same request (`# Data & State`); the URL reflects it as `?view=valuation` so
the state survives a refresh or a shared link. Switching to Valuation without `inventory.valuation.view`
is not reachable at all — the toggle itself does not render (`# Route & Access`).

**Below reorder only.** The `Switch` in the filter bar maps directly to `filter[below_reorder]=true`,
served by the backend's partial index on its own maintained `needs_reorder` boolean
(`docs/accounting/INVENTORY.md → Performance`) rather than a client-side comparison against `reorder_point`
— this screen never computes "is this item low" itself, matching the platform-wide rule that the frontend
computes nothing that matters.

**Adjust stock.** A row's "Adjust stock" action (or the page header's unscoped `Adjust stock` button, which
opens the identical `Dialog` with an empty product picker) opens `StockAdjustmentDialog` pre-filled with the
row's current `quantity_on_hand` as `quantity_before`. Submitting calls `POST /inventory/adjustments`; the
response's `status` (`pending_approval` above the company's threshold, `approved`/auto-posted below it, per
`docs/accounting/INVENTORY.md`'s own threshold logic) drives the confirmation toast and, for the
pending-approval case, surfaces an `ApprovalCard` to any approver currently viewing Items or the
Notifications bell (`inventory.adjustment_pending_approval`, `# Notifications` in the backend document).

**New transfer.** Navigates to the full-page `transfers/new/page.tsx` (`# Route & Access`). The three-step
form validates each item line's requested quantity against that line's own `available_to_sell` client-side
as a courtesy (immediate, no round-trip), but the authoritative check — the `SELECT ... FOR UPDATE` row lock
`docs/accounting/INVENTORY.md → Edge Cases` describes for exactly this race — always happens server-side on
submit; a client-side "looks fine" is never treated as a guarantee the server will accept the same quantity
a moment later.

**Dispatch and receive.** Once a transfer clears `approved`, its detail page's lifecycle timeline reveals a
"Dispatch" button (source warehouse's Warehouse Employee/Manager) and, after dispatch, a "Receive" button at
the destination. Receive supports confirming a **partial** quantity per line — a `NumberInput` pre-filled
with the dispatched quantity, editable down — and a shortfall automatically and visibly generates a linked
`stock_adjustments` row (`reason_code: 'damage'`) the receiving user sees inline in the same confirmation
step, never as a silent, separately-discovered surprise (`docs/accounting/INVENTORY.md → Edge Cases`).

**Creating and running a Stock Count.** "New count" opens a compact `Dialog` (warehouse, `count_type`
[cycle/physical/spot], `blind_count` toggle defaulting on, scope filter chips) and, on create, navigates to
`/inventory/counts/{id}`. The `StockCountGrid` accepts input via `BarcodeScanInput` (scan a product/bin QR,
the matching row auto-focuses) or manual arrow-key/tab navigation (`# Accessibility`); each entered quantity
calls `POST /inventory/counts/{id}/lines` debounced per row, so a long count session is saved incrementally
rather than only on a final submit a network drop could lose. A physical count's warehouse-lock
(`warehouses.count_in_progress = true`) is reflected the instant the count starts: any other open tab
attempting a sale/transfer/adjustment against that same warehouse receives the backend's own
`409 WAREHOUSE_COUNT_LOCK` and this document's own persistent banner surfaces it (`# States`).

**Closing a count.** "Close count" is disabled until either every line has a submitted quantity (physical
count) or the count's own partial-coverage allowance is met (cycle count), mirroring
`docs/accounting/INVENTORY.md → Inventory Operations → Physical Inventory`'s coverage rule exactly; clicking
it calls `POST /inventory/counts/{id}/close`, which the backend resolves into a batch of variance
adjustments via `POST /inventory/bulk-adjust` — the UI shows this as a single confirmation
(`AlertDialog`: "This will generate N adjustments totaling −KD X.XXX. Continue?") rather than exposing the
intermediate bulk-adjust call as a separate step.

**AI: accepting a reorder suggestion.** `AIProposalPanel`'s "Send for approval" opens the item's Settings
edit fields pre-filled with the suggested values for a human (holding `inventory.settings.manage`) to
confirm or tune before calling `POST /inventory/items/{id}/accept-reorder-suggestion`; there is no "Do it"
one-click path for this specific action regardless of confidence, because promoting a reorder point is a
standing policy change, not a one-time transaction, and `docs/accounting/INVENTORY.md → AI Responsibilities`
frames it as "pending Inventory Manager approval," never `auto`.

**AI: an ABC/XYZ badge.** Rendered as a plain, small `Badge` next to the product name — never wrapped in an
`AIProposalPanel` and never given an Accept/Reject affordance, because
`docs/accounting/INVENTORY.md → AI Responsibilities` explicitly frames both classifications as "auto-write...
no approval needed — it's a label, not a ledger change." This document takes that framing literally: no
approval UI exists for a control that was never designed to need one.

**AI: a rebalancing-transfer draft.** Surfaces in `AIProposalPanel` on the Items/Transfers AI panel exactly
like a reorder suggestion, but its "Send for approval"/"Do it" both resolve to the *same*
`inventory.transfer.approve`-gated approval path a human-created transfer uses — clicking through opens the
already-created `stock_transfers` row (created at `status='draft'`, `auto_generated=true`) on the ordinary
Transfer detail page, tagged with a small AI-provenance `Badge` (`--ai-accent`), never a parallel
AI-exclusive approval control.

# AI Integration

Every AI-authored figure or affordance on these screens carries a `confidence` (0–1, via
`normalizeConfidence`) and a `reasoning` string, per the platform-wide rule "no card shows a bare number the
AI computed without showing its work"; below 60% confidence, the corresponding suggestion is visibly marked
low-confidence and excluded from any automatic calculation it would otherwise feed, exactly as
`docs/accounting/INVENTORY.md → AI Responsibilities` specifies for Demand Forecasting.

| Capability | Where it renders | Autonomy | Confidence handling |
|---|---|---|---|
| Demand Forecasting | 30/60/90-day trend inside an item's detail `Sheet`, `TrendSparkline` | Suggest-only | Below 0.6: "low confidence — insufficient history" badge; excluded from the reorder-suggestion calculation entirely |
| Stock Optimization (reorder point/quantity) | `AIProposalPanel` on the Items AI panel | Suggest-only; writes to `suggested_reorder_point` only, never live `reorder_point`, until a human accepts | Shown alongside the Demand Forecast confidence it inherits from |
| Reorder Prediction | Draft `purchase_requests`, linked from the same panel with a "View draft request" action to Purchasing | Requires-approval, unless a company-configured auto-approve threshold is set (still never surfaced as an "auto" control on this screen) | Confidence reflects forecast + vendor lead-time variance; below-threshold requests route straight to the Purchasing queue, above-threshold ones are flagged here for review |
| Dead Stock Detection | A ranked list card on the Items AI panel: product, days since last sale, tied-up value, recommendation | Suggest-only | High confidence at 2× the configured dead-stock threshold with zero sales; moderate near the threshold itself, worded as "slowing," not "dead" |
| ABC Analysis / XYZ Analysis | A small `Badge` next to the product name on Items (both views) | Auto-write (label only, no ledger effect) — no Accept/Reject UI exists, per `# Interactions & Flows` | ABC shown with its % of total value; XYZ shown with its coefficient of variation; a SKU with too little history defaults to class C / "new SKU," flagged as such rather than guessed |
| Shrinkage Detection | Flag icon (`ConfidenceBadge`, icon-only) on Movements/Adjustments rows | Suggest-only, surfaced to Auditor/Inventory Manager | Confidence scales with sample size and deviation from that warehouse's own historical shrinkage baseline |
| Fraud Detection | Duplicate-serial `Badge` on an item's Serials tab; a fraud-risk case card routed to Auditor, not rendered inline on the main grid | Requires-approval — never blocks a user or reverses a movement automatically | Confidence breakdown per contributing signal, shown in the `Tooltip` (e.g., "3 duplicate-serial events + 2 after-hours adjustments = 0.78") |
| Anomaly Detection | Realtime toast/banner on Movements for a movement far outside a product/warehouse's normal statistical range | Suggest-only, immediate notification; never blocks the already-posted movement | Threshold-tunable per company; shown as a flag on the affected row, not a blocking modal |
| Warehouse Optimization — rebalancing transfers | `AIProposalPanel` on the Items/Transfers AI panel, resolving into an ordinary Transfer detail page | Suggest-only; the resulting draft transfer requires the identical human approval any transfer needs | Confidence tied to the statistical significance of the pick-frequency/rebalancing sample |

**Unavailable AI.** If any AI call fails, times out, or AI is disabled for the company, the AI panel on the
affected screen collapses to a quiet "AI insights unavailable right now" note and every ABC/XYZ `Badge`
simply does not render — every other capability (reading, filtering, sorting, adjusting, transferring,
counting) remains fully available. AI is strictly additive on these screens, never a gate on anything a
human needs to do their job, exactly mirroring `docs/frontend/GENERAL_LEDGER.md`'s and
`docs/frontend/BANKING.md`'s identical "Unavailable AI" rule.

**AI provenance is always visually distinct from a human action, never color-coded as good or bad.** Every
AI-authored row (`auto_generated=true`) carries a small `Sparkles`-accompanied `Badge` using the reserved
`--ai-accent` token (`# Dark Mode`) next to its status `StatusPill` — a viewer can always tell "a person
raised this" from "the Inventory Manager agent raised this" without that distinction ever implying the
AI-raised one is more or less trustworthy; both carry the identical approval mechanics once they exist as a
draft.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint) | Any of the four screens' primary query in flight, no cached data | `Skeleton` shaped to that screen's own table/KPI-band geometry, shimmer sweep; never a generic spinner |
| Loaded, has data | Normal case | Table/KPI band/AI panel render independently per their own `<Suspense>` boundary |
| Empty — no items at all | A new company with no stock ever received | `EmptyState`: "No stock tracked yet — add a product and receive stock against a purchase order to see it here," linking to `/inventory/products` and `/purchasing/purchase-orders` |
| Filtered to zero | Warehouse/category/search filters legitimately exclude every row | Lighter `EmptyState` variant: "No items match your filters," with a "Clear filters" action — never conflated with the true empty state above |
| Below-reorder toggle, zero results | Every SKU is currently above its reorder point | A positive-framed `EmptyState`: "Nothing below reorder right now" — the one empty state on these screens that is good news, and worded as such rather than reusing the generic "no results" copy |
| Fetching next page | Scrolling near the end of a virtualized Movements/Items window | Trailing row-shaped `Skeleton` strip, `isFetchingNextPage` |
| Realtime new activity | A `stock_movements`-producing event lands in the currently-scoped warehouse | Dismissible, non-disruptive banner: "New activity — Refresh"; existing rows never mutate in place, per `# Data & State → Realtime` |
| Warehouse count lock active | A `409 WAREHOUSE_COUNT_LOCK` is returned by any mutating action against a warehouse currently under physical count | Persistent, non-dismissible banner on Items/Movements/Transfers/the Adjustment `Dialog` for that warehouse: "{Warehouse} is locked for a physical inventory count (started {time}) — mutating actions are unavailable until it closes," distinct from a one-off toast because the lock can last hours |
| Adjustment/Transfer pending approval | The caller holds the matching `.approve` permission and at least one item awaits it | `ApprovalCard`s surface inline above the relevant table, not only in the separate Approval Center, per `docs/frontend/BANKING.md`'s identical precedent for cash-side approvals |
| Stock Count in progress, count-lines partially entered | A count's own `status = 'in_progress'` | Progress bar + "N / total lines counted" header stat; "Close count" stays disabled until coverage requirements are met (`# Interactions & Flows`) |
| Stock Count stalled beyond `count_max_duration_hours` | Backend auto-escalation notification fires | A `Badge` "Stalled — escalated to Inventory Manager" on the count header; a Finance Manager (not the original counter) additionally sees a "Force close" action, distinctly labeled from an ordinary close (`# Edge Cases`) |
| AI unavailable | Any AI endpoint fails, times out, or is disabled for the company | AI panel collapses to a quiet unavailable note; ABC/XYZ badges simply do not render; every core capability remains available (`# AI Integration`) |
| Error | `403`/`404`/`5xx`/network failure on any request | `ErrorState` with a retry action; a `403` discovered mid-session (a permission revoked while the tab was open) collapses only the affected control or region, never the whole page |

# Responsive Behavior

`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` defines a five-rule ladder and names its own
worked examples (Trial Balance, General Ledger, Balance Sheet); this section classifies each of the four
Inventory screens against that same ladder rather than inventing a sixth pattern.

**Stock on Hand and Movements — Rule 3, sticky-first-column horizontal scroll.** Neither table decomposes
into a simple document card: a Stock on Hand row genuinely carries six to eight simultaneously-relevant
numeric buckets (`docs/accounting/INVENTORY.md → Stock Tracking`), and a Movements row carries date,
quantity, unit cost, and a source reference that are all "headline," not "detail." Both extend
`RESPONSIVE_DESIGN.md`'s own named list of Rule-3 tables to include Inventory's two ledger-like grids,
exactly the way `docs/frontend/GENERAL_LEDGER.md` is itself named there. The product/SKU column pins via
`sticky start-0` (the **logical** start, never `left-0` — `# RTL & Localization`); the quantity/cost columns
scroll horizontally inside their own `overflow-x-auto` region below `lg:`.

**Transfers, Adjustments (pending-approval queue), and Stock Counts index — Rule 2, card transformation.**
Each row here genuinely has a "headline" (transfer/adjustment/count number + `StatusPill`) and a "detail"
split (from/to warehouse, item count, requester, date) exactly like an invoice or a bill — below `md:`, each
becomes a card whose header is the number and status, whose body is a two-column definition list of the
`priority: 1–2` fields, and whose remaining detail is reachable only by tapping through, never rendered
smaller and smaller until illegible.

**Stock Count line-entry grid — its own mobile-specific fallback.** The ARIA `grid` pattern
(`# Accessibility`) assumes a keyboard and a reasonably wide viewport; neither is realistic for a warehouse
worker holding a phone or a rugged handheld scanner in portrait orientation. Below `md:`, `StockCountGrid`
does not attempt a cramped spreadsheet — it falls back to a one-item-at-a-time **counting mode**: a single
large card showing the current product/bin, a `+`/`−` stepper plus a direct numeric entry, and "Next item"/
"Previous item" buttons, with `BarcodeScanInput` focused by default so a scan alone advances to that item's
row without any tapping at all. This is a deliberate, mobile-native redesign of the interaction, not a
shrunken version of the desktop grid — consistent with `FRONTEND_ARCHITECTURE.md`'s own note that the
barcode scanner component exists specifically for "Inventory's mobile-web counting flow."

**Filters, sort, and export collapse into overflow affordances below `md:`**, per Rule 5: the
Warehouse/Category/Below-reorder filter row becomes a single "Filters (n)" trigger opening a `Sheet`, and
per-screen export buttons collapse into a kebab `DropdownMenu`, on all four screens uniformly.

**Virtualization at scale.** Movements — Inventory's own append-only, potentially multi-thousand-row
history — mounts `@tanstack/react-virtual` once its loaded window exceeds roughly 200 rows, the identical
threshold and mechanism `RESPONSIVE_DESIGN.md → Virtualization at scale` already specifies for General
Ledger and journal-line views; Stock on Hand virtualizes on the same threshold for any company whose SKU
count genuinely warrants it. `estimateSize` reads the active breakpoint tier (40px dense desktop rows vs.
56px mobile card rows), not a single hardcoded height, exactly as that section requires.

**AI panel.** Overlay `Sheet` below `3xl:`; persistent 360px rail at `3xl:` and above, matching
`LAYOUT_SYSTEM.md`'s fourth shell region and the identical threshold `docs/frontend/GENERAL_LEDGER.md` and
`docs/frontend/BANKING.md` both use for their own AI panels.

# RTL & Localization

Every string on these four screens ships as an EN/AR pair; the screens inherit `THEMING.md`'s RTL-as-a-
theming-dimension and `LAYOUT_SYSTEM.md`'s structural RTL mirroring without a single per-component RTL
branch, plus the module-specific applications below.

- **Bilingual products, warehouses, and reason codes.** Every row renders `product.name_ar`/
  `warehouse.name_ar`/`bin.label_ar` under an Arabic session, falling back to the English value only if the
  Arabic one is genuinely absent, never the reverse — the same rule `docs/frontend/GENERAL_LEDGER.md`
  states for bilingual account names, applied here to the bilingual + enterprise fields convention
  `docs/accounting/INVENTORY.md`'s own addendum establishes (`name_en`/`name_ar` on every master-data table).
  `reason_code` (Damage, Theft, Expiry, Count Variance, …) and `movement_type` labels come from a fixed
  translation dictionary keyed to the backend enum value, never machine-translated on the fly.
- **Quantities are not currency, but follow the identical numeral discipline.** Every `QuantityCell` and
  `AmountCell` renders Western Arabic digits (`numberingSystem: 'latn'`) even under an `ar` session and
  stays `dir="ltr"`/right-aligned in both directions — QAYD never switches a quantity or a monetary figure
  to Eastern Arabic-Indic digits, matching Gulf financial-document convention and
  `docs/frontend/GENERAL_LEDGER.md`'s identical rule for ledger amounts.
- **SKUs, serial values, batch numbers, and barcode strings stay LTR-isolated** inside Arabic sentences (an
  adjustment's confirmation toast, the AI panel's narrative, a serial's recall notice) via the shared
  bidi-isolation wrapper (`dir="ltr"` + `unicode-bidi: isolate`) `docs/frontend/GENERAL_LEDGER.md` already
  applies to journal entry numbers — `WGT-100`, `SN-04821`, and a scanned GS1 barcode string must never
  visually reorder inside a right-to-left paragraph.
- **The pinned product column on Stock on Hand and Movements uses `sticky start-0`, never `sticky
  left-0`** — `RESPONSIVE_DESIGN.md` calls this the single highest-risk line of code in its entire
  responsive specification, and it applies identically to Inventory's two sticky-column tables.
- **`ArrowLeftRight` (Transfers) does not mirror.** Per `ICONOGRAPHY.md`'s directional-icon table, an icon
  encoding "movement between two places" — as opposed to "reading/navigation direction" — stays visually
  identical in both languages; only the From/To *label order* within a transfer row swaps to the logical
  start/end, never the icon itself.
- **The Stock Count grid's roving-tabindex arrow keys respect mirrored visual order automatically**, per
  `ACCESSIBILITY.md`'s own statement for the identical mechanism on the Journal Entry line editor: the
  grid's DOM row/column indices never change per locale, only which physical direction "column + 1" paints
  on screen, so no separate RTL branch of the keyboard handler is ever written.
- **AI narrative text (reorder rationale, dead-stock summaries, shrinkage reasoning) is generated in the
  viewer's own session locale** and rendered as plain translated prose, never re-translated client-side;
  Arabic inventory terminology is used precisely — مخزون (Inventory), كمية متاحة (Available quantity), حجز
  (Reservation), تعديل مخزون (Stock adjustment), نقل مخزون (Stock transfer), جرد (Stock count).

# Dark Mode

Dark mode is a token remap here exactly as everywhere else in QAYD, per `DARK_MODE.md`; nothing on these
four screens references a raw hex value or a Tailwind palette utility.

- **Surfaces and elevation.** The page canvas sits on `--surface-canvas`; the KPI tiles, the table itself,
  and its sticky header/footer bands sit on `--surface-base`; the Adjustment `Dialog`, `ValuationLayersSheet`,
  and the AI panel sit on `--surface-raised`/`--surface-overlay`. The sticky product column on Stock on Hand
  and Movements carries its paired `--border-subtle`/`--border-default` hairline in dark mode even where the
  light-mode equivalent leans on shadow alone, per `DARK_MODE.md → Surfaces & Elevation In Dark`'s
  "a borderless dark surface disappears against the near-black canvas" rule.
- **Quantity and value cells never take a status color by themselves.** Every `QuantityCell`/`AmountCell`
  renders in `--text-primary` regardless of theme; a bucket is not "good" or "bad" by default. Only three
  things on these screens ever carry a semantic status color: the `stock_item` `StatusPill`
  (Available = `--status-success`, Low = `--status-warning`, Out of stock = `--status-error`), the
  `stock_adjustment`/`stock_transfer`/`stock_count` lifecycle `StatusPill`s, and a `QuantityCell` explicitly
  passed `emphasis="warning"` for a non-zero Damaged/Quarantined bucket (`# Components Used`) — never an
  On Hand/Reserved/On Order/In Transit/Available figure under ordinary circumstances.
- **Finance status semantics, applied to stock health.** Reusing `DARK_MODE.md → Finance status
  semantics`'s exact four-state mapping: `posted`/`completed`/`closed`/`Available` = `--status-success`;
  `pending_approval`/`in_transit`/`in_progress`/`Low stock` = `--status-warning`; `rejected`/`cancelled`/
  `voided`/`Out of stock` = `--status-error`; `draft`/`scheduled` = neutral. A below-reorder SKU is
  deliberately `--status-warning`, not `--status-error` — only a zero-or-negative `available_to_sell`
  escalates to `--status-error` — because "getting low" and "cannot fulfill an order right now" are
  different urgencies and must read as visually different urgencies.
- **AI provenance uses the reserved AI accent, never a stock-health color.** Every `auto_generated=true`
  `Badge`, the ABC/XYZ classification chips, and the per-row `ConfidenceBadge` all resolve through
  `--ai-accent`/`--ai-accent-subtle` — the same reserved hue `DARK_MODE.md` defines specifically so it is
  never reused for a success/warning/error meaning, keeping "this was AI-authored" and "this stock is
  healthy/at-risk" visually distinct in both themes, exactly as `docs/frontend/GENERAL_LEDGER.md` requires
  for its own AI provenance markers.
- **Contrast.** Every token pairing here — including the sticky product column's dividing border and the
  Stock Count grid's active-cell focus ring — is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text)
  in both themes, per `DARK_MODE.md → Color & Contrast In Dark`.
- **Print/export independence.** The Inventory Valuation PDF/XLSX export and any emailed count-variance
  report always render in QAYD's fixed light/print palette regardless of the viewer's active theme, per
  `DARK_MODE.md → Exported PDFs always render light`.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented here without deviation.

- **Stock on Hand, Movements, Transfers list, and Stock Counts index all use the plain accessible
  `<table>` pattern**, never the ARIA `grid` pattern — every one of them is "read, sort, filter, paginate,
  click a row to navigate, click a row action," the exact category `ACCESSIBILITY.md → Data Tables
  Accessibility` reserves that lighter pattern for, extending its own named list (Trial Balance, General
  Ledger, Journal Entries list, Invoices, Bills, Customers, Vendors, Products, Payroll runs list) to include
  these four.
- **The Stock Count line-entry grid is the ARIA `grid` pattern, extending `ACCESSIBILITY.md`'s own
  stated intent verbatim** — that document names the Journal Entry line editor as "QAYD's canonical `grid`
  implementation" and states "every future inline-editable tabular surface... follows the same skeleton";
  `StockCountGrid` reuses its `role="grid"`/`aria-rowcount`/`aria-colcount` structure and its exact
  `useRovingGrid` hook (`row`/`col` state, arrow keys moving which single cell holds `tabIndex={0}`) without
  modification — only the cell editor differs (a counted-quantity `NumberInput` instead of a journal line's
  account/amount fields).
- **The horizontally-scrolling region on Stock on Hand and Movements is itself keyboard-operable**:
  `role="region"` + `aria-label` + `tabIndex={0}` on the wrapper around the quantity/cost columns, so a
  keyboard user can focus the region and scroll it with arrow keys without that focus stop trapping `Tab`,
  per the identical pattern `docs/frontend/GENERAL_LEDGER.md` documents for its own money columns.
- **Sortable headers are real `<button>`s with `aria-sort` on the parent `<th>`**, never a clickable `<th>`
  itself, on every plain-table screen here.
- **Row-level accessible names are unique, always.** "Adjust stock" and "Transfer" row actions carry
  `aria-label={t('a11y.adjustStock', { product: row.sku })}` — "Adjust stock for WGT-100 Widget" — never a
  bare "Adjust," per `ACCESSIBILITY.md`'s `IconButton` lint rule for any icon-only control rendered inside a
  `.map()` over rows.
- **Loading and streaming are announced, not silently swapped.** `<tbody aria-live="polite"
  aria-busy={isLoading}>` on every table here, identical to `docs/frontend/GENERAL_LEDGER.md`'s own rule —
  a screen reader hears "Loading inventory items…" and, on completion, the new row count.
- **Realtime pushes never yank focus or silently splice rows.** A `stock_movements` event affecting the
  scoped warehouse while a user is mid-scroll surfaces as a polite, dismissible banner only, per
  `ACCESSIBILITY.md → Live regions`'s own worked example (written about the General Ledger screen, applied
  identically here).
- **`StockAdjustmentDialog` and `StockTransferForm` follow `ACCESSIBILITY.md → Forms Accessibility`
  without exception**: every field has a real, visible `<label>`, never a placeholder standing in for one;
  React Hook Form + Zod errors map back onto the exact field they describe; the reason-code `Select` and the
  before/after quantity inputs are grouped under a single `fieldset`/`legend` pairing so a screen-reader
  user hears "Adjustment details" once, not five disconnected fields.
- **Every disabled control explains itself, and distinguishes *why* it is disabled.** An Approve button
  disabled because the caller is the requester ("You cannot approve your own request"), one disabled for a
  missing permission ("Requires `inventory.adjust.approve`"), and one disabled because a warehouse is
  locked for a physical count ("Unavailable — {warehouse} is locked for count PHY-2026-000012") each carry
  a distinct `aria-describedby`, never collapsed into a generic "You can't do this," extending
  `ACCESSIBILITY.md`'s RBAC-aware disabled-control rule to cover a business-state reason alongside a
  permission reason.
- **`BarcodeScanInput` is never scan-only.** It always renders a visible, focusable, correctly-labeled text
  `<input>` alongside the scan listener, so a keyboard-only user or a device with no attached scanner has
  the identical capability a warehouse handheld provides — "scan-first, keyboard-entry-fallback" is an
  accessibility requirement here, not only an operational convenience, per
  `docs/accounting/INVENTORY.md`'s own stated design intent for barcode entry.
- **Focus management on overlays.** The Adjustment `Dialog`, `ValuationLayersSheet`, and the count-creation
  `Dialog` all move focus to their own heading on open and return it to the triggering row/button on close,
  per `ACCESSIBILITY.md → Focus Management`.

# Performance

- **`inventory_items` is a maintained projection; this document never recomputes it.** Every quantity and
  the `available_to_sell` figure the Stock on Hand table renders comes directly from the API response — the
  frontend's only arithmetic is display formatting (`QuantityCell`/`AmountCell`), never a bucket subtraction
  performed client-side, matching `docs/accounting/INVENTORY.md → Performance`'s own description of
  `inventory_items` as "a maintained projection, never recomputed on read."
- **The below-reorder filter is index-driven, not a comparison the client asks Postgres to evaluate ad
  hoc.** `filter[below_reorder]=true` maps straight onto the backend's own partial index on its maintained
  `needs_reorder` boolean, so the "low stock" view stays O(rows-below-threshold) regardless of total catalog
  size, per the same backend document's Performance section.
- **Virtualization past ~200 rows** on Movements and, for larger catalogs, Stock on Hand, per
  `RESPONSIVE_DESIGN.md → Virtualization at scale` (`# Responsive Behavior`).
- **Cursor pagination on Movements**, page-mode pagination on Items/Transfers/Counts lists (bounded,
  browsable collections), matching each endpoint's own documented pagination mode
  (`docs/api/API_PAGINATION.md → Strategy`) rather than one pagination style applied uniformly regardless of
  what the underlying resource actually is.
- **The Redis-cached `available_to_sell` hot path means this screen can refresh aggressively without
  hammering Postgres.** `docs/accounting/INVENTORY.md → Performance` describes a cache-tag-invalidated Redis
  layer specifically for this figure on popular SKU/warehouse pairs; this document's own `staleTime: 10_000`
  choice for `useInventoryItems` (`# Data & State`) is deliberately layered on top of, not a substitute for,
  that already-fresh server cache.
- **Bulk operations (import, RFID ingestion, count close/bulk-adjust) are async by construction.** Each
  returns `202 Accepted` with a job reference per `docs/accounting/INVENTORY.md → Performance`; the
  corresponding UI (Import dialog, count-close confirmation) shows a progress toast that resolves via a
  Reverb completion event, never a spinner blocking the tab for the duration of a large batch.
- **AI calls never block core table rendering.** The reorder-suggestion, dead-stock, ABC/XYZ, and flag
  queries are independent of `useInventoryItems`/`useStockMovements`'s own fetch; a slow or failed AI call
  degrades only the AI panel, never the table underneath it.
- **Debounced filter and search inputs** at 300ms before triggering a new query key, with
  `placeholderData: keepPreviousData` on every list query, per `FRONTEND_ARCHITECTURE.md`'s platform-wide
  debounce rule — a filtered Stock on Hand table never flashes to a blank grid on every keystroke.
- **`next/dynamic`-split, scan-heavy components.** `BarcodeScanInput` and any camera/scanner integration it
  wraps are code-split out of the main Items/Counts bundle exactly as `FRONTEND_ARCHITECTURE.md → Bundle
  Optimization` already declares, so a desktop CFO who never scans anything never downloads that code path.

# Edge Cases

1. **Two sales orders race for the last units of the same SKU.** The backend's `SELECT ... FOR UPDATE` row
   lock on the `inventory_items` row (`docs/accounting/INVENTORY.md → Edge Cases`) means the second request
   is forced into `backordered`, never a negative `available_to_sell`; this screen renders that outcome as
   the ordinary Backordered reservation state on the item's detail `Sheet`, not as an error.
2. **A duplicate serial value collision on receiving.** Surfaces as an inline validation error on the
   receiving flow (owned by Purchasing, not this document) requiring correction or a disambiguating suffix;
   the resulting `duplicate_flag` still appears on this screen's Serials tab as a Fraud Detection flag
   regardless of how the collision was resolved.
3. **A batch expires while units are already reserved against a confirmed sales order.** No automatic
   cancellation happens; this screen's item detail `Sheet` surfaces the
   `inventory.reserved_stock_expired` notification as an actionable card (substitute a fresher batch,
   request a waiver, or cancel the line) rather than a passive toast that could be missed, per
   `docs/accounting/INVENTORY.md → Edge Cases`.
4. **A dispatched transfer arrives short (damage in transit).** The Receive flow's partial-quantity
   confirmation is a first-class step, and the resulting linked `stock_adjustments` row is shown inline in
   the same confirmation, never discovered later as an unexplained variance on Movements.
5. **A product's valuation method changes mid-year.** The Valuation view's cost-layer drill-down shows a
   "Method changed on {date}" annotation whenever a product's layer history spans the changeover, per
   `docs/accounting/INVENTORY.md → Edge Cases`'s prospective-only valuation-method-change rule — this
   screen never silently blends pre- and post-change figures without disclosing the boundary.
6. **A warehouse is locked for a physical count.** Every mutating action against that warehouse — Adjust,
   Transfer dispatch/receive, a manual reservation — returns `409 WAREHOUSE_COUNT_LOCK`; this document's
   persistent banner (`# States`) is what a user sees instead of a bare failed-request toast, naming the
   active count and, once available, its expected unlock time.
7. **A sale is attempted against consignment stock whose vendor contract has lapsed.** The `409
   CONSIGNMENT_CONTRACT_EXPIRED` this produces is a Sales-screen concern, but this document's own
   Stock on Hand row for a consignment-type warehouse (`warehouses.warehouse_type = 'consignment'`) always
   carries a "Vendor-owned, unpurchased" annotation, so a Warehouse Employee understands in advance why a
   sale against that quantity might later be blocked.
8. **An approval permission is revoked mid-session.** If `inventory.adjust.approve` or
   `inventory.transfer.approve` is revoked while an `ApprovalCard` for that item is open, the next attempted
   click's `403` collapses that card to its permission-denied state with an explanatory toast, rather than a
   stuck spinner or a silent failure — the frontend never trusts its own last-known permission snapshot.
9. **AI is unavailable, disabled, or returns a low-confidence output.** Never blocks reading, adjusting,
   transferring, or counting stock (`# AI Integration`); only the AI panel and its badges degrade.
10. **Switching warehouse or company mid-scroll on Movements.** Resets `cursor` to `null` and discards the
    in-flight `useInfiniteQuery` state, matching `docs/frontend/GENERAL_LEDGER.md`'s identical rule and the
    platform's company-switch cache-discard contract — a cursor issued while browsing one warehouse (or one
    company) is never replayed against a different one.
11. **Exporting a very large valuation or movement range.** Mirrors the platform's async-report pattern: a
    range whose estimated row count exceeds the export endpoint's synchronous threshold returns `202
    Accepted` with a job id, surfaced via the export menu's "Recent exports" list and a completion
    notification rather than a held-open spinner.
12. **A physical count stalls past `count_max_duration_hours` (default 48).** The count header shows the
    "Stalled — escalated" `Badge` (`# States`); once the backend's further grace period lapses, a Finance
    Manager who was **not** the original counter sees a distinctly-labeled "Force close" action, and the
    resulting adjustment's `StatusPill`/audit entry is rendered as a forced close, never presented
    identically to an ordinarily-completed count, per `docs/accounting/INVENTORY.md → Edge Cases`.
13. **A product is deleted while it still carries on-hand inventory.** The Products module's delete action
    is blocked synchronously by Inventory's own domain-event check; if a user reaches this screen via a
    stale link to a since-blocked delete flow, the item still renders normally here — this document never
    needs a special "deletion pending" state, because the underlying `inventory_items` row was never allowed
    to disappear in the first place.
14. **A returned unit's original cost layer has long since closed.** The Movements row for that return still
    renders a unit cost (the backend's documented fallback: the current valuation method's normal receiving
    logic, or the current weighted-average cost) rather than a blank or zero figure — this screen never
    presents a return as costing nothing.

# End of Document
