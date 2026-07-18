# Purchase Orders — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PURCHASE_ORDERS
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Purchase Order screen set: the list, the
builder (create/edit/amend), and the detail view over the `purchase_orders` / `purchase_order_items`
tables that `docs/accounting/PURCHASING.md` defines as the first legally binding document in the
procure-to-pay (P2P) chain. A Purchase Request is internal intent; an RFQ is a solicitation; but a
Purchase Order is the moment a company commits real money to a specific vendor for specific goods or
services at a specific price — everything downstream (Goods Receipt, Quality Inspection, the Bill's
three-way match, the Vendor Payment) exists to fulfill, verify, and eventually settle the commitment this
screen creates. Getting this screen right therefore means two things at once: making the commitment fast
and unambiguous to create for a Purchasing Employee working through a routine reorder, and making it
impossible to silently drift once it exists — a `sent` PO's price and quantity are immutable by design
(`docs/accounting/PURCHASING.md` BR-PO-3), and every later correction is a visible amendment, never a
quiet edit.

Three sub-surfaces are covered by this one document, sharing one route family, one data model, one
permission surface, and one set of composed components, per `docs/frontend/README.md`'s "two audiences,
one shell" rule:

1. **List** (`/purchasing/purchase-orders`) — browse, filter by status/vendor/date/type, search, sort,
   export, and jump to the Outstanding Orders report. The default landing surface for every role holding
   `purchasing.order.read`.
2. **Builder** (`/purchasing/purchase-orders/new`, and the edit/amend modes of the detail route) — the
   `PurchaseOrderForm` + `PurchaseOrderLineItems` line editor with a live totals panel (subtotal, discount,
   tax, multi-currency total and base-currency equivalent), vendor/product pickers, and an RFQ-tolerance
   price check. This is where a human authors a `draft`, or drafts an **amendment** to a PO that has
   already been sent.
3. **Detail** (`/purchasing/purchase-orders/[purchaseOrderId]`) — the full lifecycle view of one PO: its
   lines and their receiving/billing progress, the Goods Receipts posted against it, the Bills that
   reference it (with three-way match status), its approval chain, and its amendment/history trail —
   plus, while it is still `draft` or `rejected`, the same Builder in place, per the identical
   one-route-two-modes pattern `docs/frontend/JOURNAL_ENTRIES.md → Route & Access` establishes for
   Journal Entries.

This screen never computes a financial or business truth of its own. Every total, every tolerance check,
and every over-receipt guard the client performs is a fast, friendly, purely cosmetic rejection that
exists to avoid a wasted round trip — the Laravel `PurchaseOrderService` is the only party whose answer
can ever actually create, send, or amend a PO, exactly as `docs/accounting/PURCHASING.md`'s BR-PO-1
states ("recomputed server-side on every save; a client-supplied mismatch is rejected with `422`").
Nothing in this document introduces a validation rule, a permission key, an API shape, or a status value
that is not already defined in that backend specification; this document is strictly the rendering,
interaction, and state-management contract for it, consistent with `FRONTEND_ARCHITECTURE.md`'s
Principle 1 ("the frontend never contains business or financial logic").

Two audiences share this shell: a **Purchasing Employee** or **Purchasing Manager** doing high-volume,
mostly-vendor-facing authoring (dense `Compact` table density on the List, a fast product/vendor-picker
workflow in the Builder) and a **Warehouse Employee** whose entire interaction with this screen is the
Detail page's "Receive Goods" action — they rarely if ever open the Builder. A third reader, **Finance**,
uses the Detail page's Bills tab to understand whether a PO's spend is fully matched and settled, and a
fourth, the **Auditor**, reads every one of these surfaces but can act on none of them. All four are built
from the same components — `DataTable`, `PurchaseOrderForm`, `ApprovalCard`, `StatusPill`, `AmountCell` —
composed differently; there is no separate build per audience.

A fifth party touches this screen without a login at all: the **Purchasing Agent**, QAYD's AI layer
specialist for procure-to-pay (`docs/ai/agents/PURCHASING_AGENT.md`). It never creates a Purchase Order
directly — of every document type in this module, only the Purchase Request can be AI-drafted
(`docs/accounting/PURCHASING.md` § AI Responsibilities: "the only capability in this module where AI
creates a database row directly reachable through the normal Purchase Request table") — so this screen's
relationship to AI is entirely about **provenance and advisory annotation** flowing in from an upstream
RFQ award or an AI-sourced Purchase Request, and forward-looking anomaly checks against the vendor's
quoted price, never a "Do it" button that issues a PO on its own (`# AI Integration`).

# Route & Access

## Route tree

```text
app/(app)/purchasing/purchase-orders/
├── page.tsx                     # List — Server Component, first-paint fetch
├── loading.tsx                  # List skeleton (streamed while the Server Component fetches)
├── new/
│   └── page.tsx                 # Builder — create mode, and amend mode via ?amends=<id>
└── [purchaseOrderId]/
    └── page.tsx                 # Detail — view mode, OR Builder in edit mode (see below)
```

Per `docs/frontend/FRONTEND_ARCHITECTURE.md → Conventions → Naming`, the dynamic segment is
`[purchaseOrderId]` — camelCase, named for the entity — never the generic `[id]` shorthand this
document's own task brief uses informally; its API-resource mirror is
`/api/v1/purchasing/purchase-orders/{id}`.

**One route, two rendered modes, chosen by server-known status — not four routes.** There is
deliberately no separate `/[purchaseOrderId]/edit` route, reusing the exact branch
`docs/frontend/JOURNAL_ENTRIES.md → Route & Access` establishes for its own Composer/Detail split:

```tsx
// app/(app)/purchasing/purchase-orders/[purchaseOrderId]/page.tsx
import { getPurchaseOrder } from "@/lib/api/purchasing";
import { PurchaseOrderDetail } from "@/components/purchasing/purchase-order-detail";
import { PurchaseOrderForm } from "@/components/purchasing/purchase-order-form";
import { notFound } from "next/navigation";

const EDITABLE_STATUSES = new Set(["draft", "rejected"]);

export default async function PurchaseOrderPage({
  params,
}: {
  params: Promise<{ purchaseOrderId: string }>;
}) {
  const { purchaseOrderId } = await params;
  const po = await getPurchaseOrder(purchaseOrderId).catch(() => null);
  if (!po) notFound();

  return EDITABLE_STATUSES.has(po.status) ? (
    <PurchaseOrderForm mode="edit" purchaseOrderId={po.id} defaultValues={po} />
  ) : (
    <PurchaseOrderDetail initialPurchaseOrder={po} />
  );
}
```

This mirrors `docs/accounting/PURCHASING.md`'s own Locking Rules exactly: `draft` and `rejected` are the
only statuses whose line items remain mutable (BR-PO-3 — a `sent` PO's `unit_price`, `quantity_ordered`,
and `tax_code_id` are immutable per line), so those are the only statuses that ever resolve to a form. A
`sent`, `partially_received`, `fully_received`, `approved`, `pending_approval`, `cancelled`, or `closed`
PO always renders the read-only `PurchaseOrderDetail`, never a form with fields disabled — a disabled
form inviting an edit attempt that the server will reject is worse UX than no form at all, and it is also
structurally wrong for `approved`/`pending_approval`/`sent`+ statuses where BR-PO-3/BR-PO-4 make an
in-place edit meaningless.

**A third Builder mode: `amend`.** A `sent`, `partially_received`, or `fully_received` PO cannot be
edited in place (BR-PO-3); its Detail page instead offers "Amend" (gated `purchasing.order.amend`),
which navigates to `/purchasing/purchase-orders/new?amends=<originalId>`. The Builder detects the
`amends` search param, fetches the original PO as `defaultValues` exactly as edit mode does, but submits
to `POST /purchasing/purchase-orders/{originalId}/amend` instead of the generic create endpoint
(`# Data & State`). The response is a **new** `purchase_orders` row (`amends_po_id` pointing at the
original, `amendment_number` incremented); the UI navigates to the new amendment's own Detail page on
success, and the original PO's Detail page immediately shows a "Superseded by PO-2026-004512 →"
cross-link once the mutation settles, matching the identical superseded/cross-link pattern
`docs/frontend/JOURNAL_ENTRIES.md → Interactions & Flows` uses for a reversed entry.

## Access gate

The route sits inside the `(app)` route group, behind `middleware.ts`'s session-cookie check and the
resolved active-company context (`X-Company-Id`), per `FRONTEND_ARCHITECTURE.md`. A user without
`purchasing.order.read` never sees "Purchase Orders" in the Purchasing sidebar section or in the Command
Palette's fuzzy-search index (RBAC filters at the data layer before the list is constructed, per
`NAVIGATION_SYSTEM.md → Permission-Aware Nav`), and a direct hit on the URL renders the shared `403`
boundary rather than a `404` — the route exists, the record might exist, the viewer simply may not see
it. A cross-company hit (a `purchaseOrderId` that exists but belongs to a different `company_id`) renders
the shared `not-found.tsx` instead, per the platform's "403 vs. 404" rule: the API itself returns `404`,
never `403`, for a cross-tenant record specifically so a caller can never learn that a PO with that id
exists somewhere they cannot see.

## Permission surface on this screen

Every permission key below is defined once, authoritatively, in `docs/accounting/PURCHASING.md` →
Permissions; this table is this screen's map of key → concrete UI effect, not a redefinition.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `purchasing.order.read` | The route itself; every list row; every detail view | Route renders the shared `403` boundary; nav item hidden |
| `purchasing.order.create` | "New Purchase Order" button, `/new` route (create mode), `N` shortcut, "Save Draft" in the Builder | Button/route removed from the DOM, not disabled |
| `purchasing.order.update` | Edit affordance on a `draft`/`rejected` PO | Detail route renders the read-only `PurchaseOrderDetail` even for a `draft`, with a banner explaining why |
| `purchasing.order.submit` | "Submit for approval" action when the PO requires approval | Builder's primary button hidden; falls through to `purchasing.order.approve`/direct-send logic |
| `purchasing.order.approve` | Approve / Reject on `ApprovalCard`, at the viewer's assigned tier; Delegate | `ApprovalCard` renders read-only (amount, requester, no action row) |
| `purchasing.order.send` | "Send to Vendor" action on an `approved` PO | Action omitted from the Detail page's lifecycle control and row-action menu |
| `purchasing.order.amend` | "Amend" action on a `sent`/`partially_received`/`fully_received` PO | Menu item omitted |
| `purchasing.order.cancel` | "Cancel" action, with reason | Menu item omitted |
| `purchasing.order.override_price` | Accepting a line's `unit_price` beyond the RFQ tolerance band (BR-PO-2, default ±2%) | The out-of-tolerance value is rejected client-side with a permission-specific reason (`# Interactions & Flows`); the server independently re-checks and is authoritative |
| `purchasing.order.force_close` | "Force close" on a PO with unpaid linked Bills (BR-PO-7) | Action omitted; "Close" stays blocked with the unpaid-bills reason and no override path shown |
| `warehouse.receiving.create` | "Receive Goods" primary action; the `ReceiveGoodsSheet`'s submit | Action omitted from the Detail page and the Goods Receipts tab |
| `warehouse.receiving.reverse` | "Reverse receipt" row action on an unreferenced posted GR | Menu item omitted |
| `purchasing.bill.read` | The Bills tab's rows (a read-only cross-reference to a Bill's match/payment status) | Tab hidden entirely — a viewer with no bill-read visibility should not learn even that a bill exists |
| `reports.export` | "Export" in the List's Filter Bar; single-PO PDF export from the Detail page's overflow menu | Button/menu item omitted |

Default role grants (Owner, Admin, Purchasing Manager, Purchasing Employee, Finance, Warehouse, Auditor)
are exactly the table in `docs/accounting/PURCHASING.md` → Permissions — this document does not restate
role-by-role grants to avoid the two tables drifting out of sync; `usePermission()`
(`FRONTEND_ARCHITECTURE.md → Conventions`) is the only source either query. The Auditor role holds
`purchasing.*.read` only, enforced at the Laravel Policy layer — this screen's UI never renders an
Approve/Send/Amend/Receive control for an Auditor at all, regardless of which PO or approval tier is in
view, since `usePermission` resolves to `false` unconditionally for that role. The **AI Agent** principal
holds no permission on this screen whatsoever — `purchasing.order.create/approve/send/amend/cancel` are
never granted to the `ai_agent` service account (`docs/ai/agents/PURCHASING_AGENT.md` § Guardrails &
Human Approval), so there is structurally no code path on this screen that renders an AI-authored PO
awaiting a one-click confirmation the way, say, an AI-drafted Purchase Request does on its own screen.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `P` navigates to this screen
from anywhere in the app; `N`, scoped to this route, opens `/new` (only rendered reachable when
`purchasing.order.create` is held — the shortcut and the button share one permission check, never two).

# Layout & Regions

All three sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing page templates verbatim — the List uses the
**List Page Template**, the Builder uses the **Form Page Template**, and the Detail view uses the
**Detail Page Template** with a specialized four-tab main column, matching the same
template-reuse discipline `docs/frontend/JOURNAL_ENTRIES.md` and `BANK_RECONCILIATION.md` both follow
("a screen never invents a sixth layout shape"; `BANK_RECONCILIATION.md`'s own workbench is, structurally,
a Detail Page Template with a specialized match-grid main column, not a new template).

## List — List Page Template

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Purchase Orders                                     [+ New Purchase Order]│
│ 612 orders                                [Outstanding Orders ↗] [Export ▾]│
├──────────────────────────────────────────────────────────────────────────┤
│ [Search…]  [Status ▾]  [Vendor ▾]  [Type ▾]  [Date ▾]  [▤ Density] [⚙]  │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐│ PO #        │ Vendor         │ Order date│ Amount        │ Status     │⋯│
│ ☐│ PO-2026-4417│ Gulf Paper Co. │ Jul 16    │ 4,515.00 USD  │ ● Draft     │⋯│
│ ☐│ PO-2026-4416│ Al-Rashid Trdg │ Jul 15    │ 12,040.500 KWD│ ● Sent      │⋯│
│ ☐│ PO-2026-4415│ Gulf Paper Co. │ Jul 12    │ 2,150.000 KWD │ ● Fully rec.│⋯│
│ …│ …           │ …              │ …         │ …             │ …          │⋯│
├──────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 612                                     [‹ Prev] [Next ›] │
└──────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, live order count (`meta.pagination.total`), primary action ("New Purchase
  Order", gated `purchasing.order.create`), and a secondary actions row: a direct link to the
  **Outstanding Orders** report (`docs/accounting/PURCHASING.md` § Reports — open PO lines not yet fully
  received, with days-overdue) and Export (`reports.export`).
- **Filter Bar** — search (`q`, full-text over `po_number` + vendor name + `notes`), and the filters this
  screen's brief calls out explicitly: **Status** (multi-select over the nine lifecycle values, grouped
  for scanability into *Open* — `draft`, `pending_approval`, `approved` — *In Progress* —
  `sent`, `partially_received` — and *Concluded* — `fully_received`, `closed`, `rejected`,
  `cancelled`) and **Vendor** (`VendorPicker` in filter mode — multi-select, searchable, defaults to
  `status=active` vendors but includes a "show blocked/inactive too" toggle so a Purchasing Manager can
  still find historical POs against a vendor since blocked). Two secondary filters sit alongside: **Type**
  (`po_type` — standard/blanket/services/drop_ship) and **Date** (`PeriodPicker`, `mode="date_range"` by
  default with a `mode="relative"` toggle for "This month"/"Last 30 days"). A density toggle and a
  column-visibility menu close the Filter Bar.
- **Bulk Action Bar** replaces the Filter Bar's end side once ≥1 row is selected: "4 selected · Export ·
  Clear" — bulk approve/send/cancel are deliberately **not** offered here (unlike
  `JOURNAL_ENTRIES.md`'s bulk-approve precedent), because a PO's amount-tiered approval routing and its
  vendor-specific send transmission are both consequential enough that the platform's design intent is
  one considered decision per PO, not a batch click; only the non-mutating Export survives as a bulk
  action on this screen.
- **Data Region** — `DataTable` (`resource="purchasing/purchase-orders"`, `paginationMode="page"`).
- **Pagination Footer** — page controls; "Showing 1–25 of 612."

The Amount column renders through `AmountCell` with `mode="plain"` and `showCurrency` — unlike a Journal
Entry's two-sided Debit/Credit split, a Purchase Order carries one signed commitment, so a single amount
column plus a `CurrencyTag`-style currency suffix is both sufficient and matches
`COMPONENT_LIBRARY.md → Pattern 1`'s generic single-"Amount" illustration exactly (this screen has no
Journal-Entries-style reason to depart from it, since `total_amount` is never legitimately out-of-balance
the way an in-progress journal draft's debits/credits can be). Rows spanning multiple transaction
currencies in the same list are exactly why `showCurrency` defaults on here, unlike a single-currency
accounting table where a column-level `CurrencyTag` alone would do.

## Builder — Form Page Template

```
┌──────────────────────────────────────────────────┬────────────────┐
│ New Purchase Order   [Cancel] [Save Draft] [Submit]│ Totals         │
├──────────────────────────────────────────────────┤ Subtotal 4,300.00│
│  Header Info                                       │ Discount   0.00│
│  Vendor [Gulf Paper Co. ▾]  Type [Standard ▾]      │ Tax      215.00│
│  Warehouse [Main WH ▾]  Currency [USD▾] Rate[0.3075]│ Total  4,515.00│
│  Order date [__]  Expected delivery [__]           │ USD             │
│  Payment terms [Net 30 ▾]  Incoterm [FOB ▾]        │ ≈ 1,388.36 KWD  │
├──────────────────────────────────────────────────┤ ────────────────│
│  Line Items                                        │ AI note         │
│  ┌────────────┬─────┬──────┬───────┬─────┬──────┐  │ Vendor ranked #1│
│  │ Product    │ Qty │ Price│ Disc %│ Tax │ Amount│  │ for this RFQ —  │
│  │ A4 Paper 80│2000 │ 2.15 │   0   │ VAT │4,300.00│  │ 96% on-time,    │
│  └────────────┴─────┴──────┴───────┴─────┴──────┘  │ 0.83 confidence │
│  [+ Add line]                                       │ [View reasoning]│
├──────────────────────────────────────────────────┤                │
│  Shipping / Billing Address        Notes            │                │
├──────────────────────────────────────────────────┴────────────────┤
│ ── sticky footer, appears once header scrolls out of view ──────── │
│         [Cancel]        [Save Draft]        [Submit for approval]  │
└──────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — "New Purchase Order" / "Edit PO-2026-004417" / "Amend PO-2026-004416"; Cancel
  (secondary, confirms discard if dirty); Save Draft (secondary, `purchasing.order.create`/`.update`);
  the primary action's label resolves per `# Interactions & Flows` — "Submit for approval," "Send to
  Vendor" (an approval-exempt low-value PO under the lowest tier), or "Save amendment."
- **Form Body** — four `Card` sections: **Header Info** (vendor, PO type, warehouse, currency + exchange
  rate, order date, expected delivery date, payment terms, incoterm — the last shown only for
  `po_type IN ('standard', 'blanket')`, since a `services` PO has no physical shipment and a `drop_ship`
  PO substitutes a `drop_ship_address` field for the warehouse selector), **Line Items**
  (`PurchaseOrderLineItems`), **Shipping / Billing Address** (defaulted from the selected vendor's
  `vendor_addresses` the moment `VendorPicker` resolves a vendor, editable per-PO), and **Notes**
  (`notes`, vendor-visible, and `internal_notes`, never shown to the vendor — rendered as two visibly
  distinct fields, never a single textarea, so a Purchasing Employee cannot accidentally leak an internal
  remark onto a transmitted PDF).
- **Totals Rail** (3–4/12, `xl:`+; becomes an inline panel above the sticky footer below `xl:`) — the
  live `subtotal_amount` / `discount_amount` / `tax_amount` / `total_amount` breakdown recomputed on every
  line edit, the transaction-currency total in `emphasis="strong"`, and — whenever `currency_code`
  differs from the company's base currency — a muted base-currency equivalent line
  (`total_amount_base`, `CurrencyTag emphasis="muted"`) directly beneath it, per `# RTL & Localization`'s
  multi-currency rendering rule. Below the totals, an **AI note** card appears only when the PO traces
  back to an RFQ award or an AI-forecast-sourced Purchase Request (`# AI Integration`); it never appears
  on a from-scratch, non-PO-history PO.
- **Sticky Footer Action Bar** — duplicates Cancel/Save Draft/primary-action once the header scrolls out
  of view, with the header's own buttons gaining `aria-hidden` while the footer bar is the visible
  instance, per `LAYOUT_SYSTEM.md → Form Page Template`'s "never two live focus targets for the same
  action" rule.

## Detail — Detail Page Template

```
┌────────────────────────────────────────────────────┬───────────────┐
│ ‹ Purchase Orders  PO-2026-004417   ● Sent          │  Summary       │
│                              [Amend ▾] [⋯]          │  ───────────   │
├─ Items │ Goods Receipts │ Bills │ History ─────────┤  Vendor         │
│  Product          │ Ordered │ Recv'd │ Billed │ ⋯   │  Gulf Paper Co. │
│  A4 Paper 80gsm    │ 2,000   │ 2,000  │ 2,000  │ ⋯   │  Total          │
│                                                      │  4,515.00 USD   │
├──────────────────────────────────────────────────────┤  ≈1,388.36 KWD │
│  (Goods Receipts tab: GR-2026-000512, posted Jul 29)  │  Currency USD  │
│  (Bills tab: BILL-2026-004102 · matched · posted)      │  Payment terms│
│  (History tab: amendments + approval + status timeline) │  Net 30       │
└────────────────────────────────────────────────────┴───────────────┘
```

- **Page Header** — back-to-list link, `po_number`, `StatusPill domain="purchase_order"`, and the single
  lifecycle action that applies right now (Approve/Reject via inline `ApprovalCard` when
  `pending_approval` and the viewer is an eligible approver; Send when `approved`; Receive Goods when
  `sent`/`partially_received`; nothing further once `fully_received`/`closed` beyond the overflow menu),
  and an overflow menu for Amend/Cancel/Force-close/Export.
- **Tab/Segment Nav** — `Tabs`: **Items** (default), **Goods Receipts**, **Bills**, **History** — the
  four sub-views this PO's lifecycle genuinely produces, matching `LAYOUT_SYSTEM.md → Detail Page
  Template`'s "only when the entity has sub-views" rule exactly the way Journal Entries' own three tabs
  do.
- **Main Column (8/12)** — the active tab's content: the read-only `PurchaseOrderItemsTable` (Items,
  with a per-line Ordered/Received/Billed/Returned progress readout), `GoodsReceiptsTable` (Goods
  Receipts, each row opening a read-only GR summary `Sheet`), `LinkedBillsTable` (Bills, each row linking
  out to `/purchasing/bills/{id}` — a future screen this document does not itself specify, see
  `# Components Used`), or the merged amendment-chain + approval-action + status-change timeline
  (History).
- **Summary Rail (4/12)** — vendor name/link, total amount (`AmountCell emphasis="strong"` plus the
  base-currency line when applicable), `CurrencyTag`, payment terms, incoterm, expected delivery date,
  and — only when the PO's `rfq_id` or any line's `source_pr_item_id` traces to an `ai_forecast` origin —
  the AI provenance block (`# AI Integration`).
- **Activity Timeline** — always rendered at the bottom of the Main Column inside the History tab, never
  relegated to a rail footnote, per `LAYOUT_SYSTEM.md → Detail Page Template`.

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, or is one of the screen-specific
composed components this document introduces for this exact surface. `VendorPicker` and `ProductPicker`
are new master-data pickers modeled directly on the existing `AccountPicker` pattern and are proposed as
additions to `COMPONENT_LIBRARY.md` rather than hand-rolled locally, per that document's own rule that
"a component gap discovered while building a screen is proposed as an addition... never hand-rolled
locally" — both are generic enough that Sales' own Sales Order screen (a future document) will need the
identical `ProductPicker`, and Vendors/Purchasing screens elsewhere will need the identical
`VendorPicker`.

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The List's server-paginated, sortable, filterable order table (`resource="purchasing/purchase-orders"`) |
| `StatusPill` | `components/shared/status-pill.tsx` | Every PO lifecycle-status rendering (`domain="purchase_order"`, introduced by this document — see below) and every Goods Receipt status rendering (`domain="goods_receipt"`, also introduced here) |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every line/subtotal/tax/total figure, list and detail, `mode="plain"` (a PO total has no debit/credit duality) |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Header currency indicator on the Builder and the Summary Rail; the muted base-currency annotation beneath a foreign-currency total |
| `PeriodPicker` | `components/accounting/period-picker.tsx` | The List's Date filter (`mode="date_range"`/`"relative"`) |
| `VendorPicker` | `components/purchasing/vendor-picker.tsx` (screen-specific — new in this doc, see below) | The Builder's header Vendor field; the List's Vendor filter |
| `ProductPicker` | `components/purchasing/product-picker.tsx` (screen-specific — new in this doc, see below) | Every line's product cell inside `PurchaseOrderLineItems` |
| `PurchaseOrderForm` | `components/purchasing/purchase-order-form.tsx` | The Builder's outer form (header fields, Save Draft/Submit/Send, totals panel) across `create`/`edit`/`amend` modes |
| `PurchaseOrderLineItems` | `components/purchasing/purchase-order-line-items.tsx` (screen-specific) | The editable line-item table inside `PurchaseOrderForm` — a plain accessible `<table>`, **not** the ARIA `grid` pattern (see below) |
| `PurchaseOrderDetail` | `components/purchasing/purchase-order-detail.tsx` (screen-specific) | The Detail route's read-only composition: `PurchaseOrderHeader` + conditional `ApprovalCard` + `Tabs` |
| `PurchaseOrderHeader` | `components/purchasing/purchase-order-header.tsx` (screen-specific) | The Detail page's back-link, PO number, `StatusPill`, and lifecycle action button |
| `PurchaseOrderItemsTable` | `components/purchasing/purchase-order-items-table.tsx` (screen-specific) | The Items tab's read-only rendering with per-line receiving/billing progress |
| `GoodsReceiptsTable` | `components/purchasing/goods-receipts-table.tsx` (screen-specific) | The Goods Receipts tab's list of GRs posted against this PO |
| `ReceiveGoodsSheet` | `components/purchasing/receive-goods-sheet.tsx` (screen-specific) | The "Receive Goods" action's slide-over form, posting a new `goods_receipts` row |
| `LinkedBillsTable` | `components/purchasing/linked-bills-table.tsx` (screen-specific) | The Bills tab's read-only cross-reference list; each row's `StatusPill domain="bill"` reads a lookup table owned by the future Bills screen document, not redefined here |
| `MatchStatusTag` | `components/purchasing/match-status-tag.tsx` (screen-specific) | The Bills tab's `match_status` indicator — a distinct semantic axis from `StatusPill`'s lifecycle status, see below |
| `ApprovalCard` | `components/shared/approval-card.tsx` | Inline approval banner on the Detail page (`kind` extended with `'purchase_order'` — see below) |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | AI-provenance confidence display, Totals Rail / Summary Rail and the reasoning `Sheet` |
| `Tabs` | `components/ui/tabs.tsx` | Detail page's Items / Goods Receipts / Bills / History segmentation |
| `Sheet` | `components/ui/sheet.tsx` | `ReceiveGoodsSheet`; a Goods Receipt's read-only detail drawer; the AI reasoning drill-in; the mobile row-detail drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | Send-to-vendor confirmation, Cancel reason, Force-close reason, discard-draft confirmation |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Row actions in the List; the Detail page's overflow menu |
| `Badge` | `components/ui/badge.tsx` | PO-type chips in the Filter Bar's Type groups; the underlying primitive `MatchStatusTag` composes |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, mapped from the API envelope |

## `StatusPill` domain extensions this document owns

Per `COMPONENT_LIBRARY.md → StatusPill`'s explicit design ("each [domain] owned by its module's screen
spec and imported here rather than redefined"), this document is the authoritative owner of two new
lookup tables added to `STATUS_TABLES`:

```tsx
// components/shared/status-pill.tsx (addition owned by this document)
const PURCHASE_ORDER_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:              { label: 'Draft',            tone: 'neutral' },
  pending_approval:   { label: 'Pending approval',  tone: 'warning' },
  approved:           { label: 'Approved',          tone: 'accent'  },
  sent:               { label: 'Sent to vendor',    tone: 'accent'  },
  partially_received: { label: 'Partially received',tone: 'warning' },
  fully_received:     { label: 'Fully received',    tone: 'success' },
  closed:             { label: 'Closed',            tone: 'neutral' },
  rejected:           { label: 'Rejected',           tone: 'danger'  },
  cancelled:          { label: 'Cancelled',          tone: 'danger'  },
};

const GOODS_RECEIPT_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:    { label: 'Draft',    tone: 'neutral' },
  posted:   { label: 'Posted',   tone: 'success' },
  reversed: { label: 'Reversed', tone: 'neutral' },
};

// STATUS_TABLES.purchase_order = PURCHASE_ORDER_STATUS;
// STATUS_TABLES.goods_receipt  = GOODS_RECEIPT_STATUS;
```

`sent` and `approved` deliberately share the `accent` tone — both represent "the company has committed,
the vendor side is still in motion" — distinguished by label text rather than color, matching the
platform's existing precedent of tone-sharing across adjacent, non-conflicting lifecycle stages
(Journal Entries' own `reversed`/`archived` share `neutral` for the identical reason). `partially_received`
uses `warning`, not because anything is wrong, but because it is this table's "still needs your attention"
state — an open line sitting partially fulfilled for weeks is exactly the signal the Outstanding Orders
report and this tone exist to surface.

## `ApprovalCard` — `kind: 'purchase_order'`

`docs/accounting/PURCHASING.md` → Approval Workflow's PO tiers (≤500 KWD-equivalent: Purchasing Manager;
500–10,000: Purchasing Manager → Finance Manager; >10,000: Purchasing Manager → Finance Manager → CFO)
route through the identical shared `ApprovalCard` Journal Entries uses, extended with one new entry in
its internal permission map:

```tsx
// components/shared/approval-card.tsx (addition owned by this document)
const PERMISSION_BY_KIND: Record<ApprovalKind, string> = {
  journal_entry: 'accounting.journal.approve',
  purchase_order: 'purchasing.order.approve', // added by this document
  // ai_recommendation, bank_transfer, payroll_release, tax_submission unchanged
};
```

No other change to `ApprovalCard` is required — `kind="purchase_order"`, `amount={{ value:
po.total_amount, currencyCode: po.currency_code }}`, and `approvalLevel={{ current, total }}` are already
generic props the component accepts (`COMPONENT_LIBRARY.md → ApprovalCard`).

## `VendorPicker`

A searchable combobox over `vendors`, used on this screen's Builder header and List filter, and intended
for reuse anywhere else in the platform a document references a vendor (Bills, Vendor Payments,
Procurement Contracts). Built on the identical `Command` + `Popover` (Radix) pattern as `AccountPicker`,
for the identical reason: a mid-size Gulf trading company's vendor master commonly holds several hundred
active vendors.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `number \| null` | yes | Selected `vendors.id`. |
| `onChange` | `(vendorId: number, vendor: Vendor) => void` | yes | |
| `statusFilter` | `'active' \| 'all'` | no, default `'active'` | A `blocked`/`suspended` vendor cannot receive a new PO (`docs/accounting/PURCHASING.md` § Vendor Integration) — the Builder always passes `'active'`; the List's filter passes `'all'` with a visually distinct "blocked" tag per row, since historical POs against a since-blocked vendor must remain findable. |
| `showScorecard` | `boolean` | no, default `false` | When `true` (the Builder's own usage), the selected vendor's option row and post-selection summary render `on_time_delivery_rate`, `quality_score`, and `average_lead_time_days` inline — read directly from the same `vendors` fields the Supplier Performance agent computes, not a separate AI call (`# AI Integration`). |
| `disabled` | `boolean` | no | Disabled once the PO has any linked Goods Receipt or Bill (BR-PO-8: vendor is immutable past that point). |

## `ProductPicker`

A searchable combobox over `products`, filtered to `status = 'active'` by default, used per-line inside
`PurchaseOrderLineItems`. Selecting a product auto-populates that line's `unit_of_measure_id` (from the
product's default UoM) and, when the PO has no `rfq_id`/awarded quotation to pre-fill from, the vendor's
last-paid `unit_price` for that product as a starting value the Purchasing Employee can override — never
silently locked.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `number \| null` | yes | Selected `products.id`. |
| `onChange` | `(productId: number, product: Product) => void` | yes | |
| `categoryFilter` | `number[]` | no | Restricts to specific `product_categories`, used when a line's `warehouse_id` implies a category scope. |
| `allowServiceLine` | `boolean` | no, default `true` | When `true`, an explicit "No product — service/free-text line" option is offered, producing a line with `product_id = null` per `docs/accounting/PURCHASING.md`'s services-PO line shape. |
| `vendorId` | `number \| null` | no | When set, the picker's search additionally surfaces "previously purchased from this vendor" results first — a read-only convenience ranking, not an AI recommendation. |

## `PurchaseOrderLineItems` — a plain table, deliberately not the ARIA `grid` pattern

`docs/frontend/ACCESSIBILITY.md → Custom widget roles` names exactly two surfaces in the entire platform
that warrant the heavier ARIA `grid` pattern: "the Journal Entry line editor, and the Bank Reconciliation
matching grid." A Purchase Order's line-item editor is **not** a third — it is, per
`RESPONSIVE_DESIGN.md → Multi-line editors: journal lines and invoice items`, in the same family as an
invoice's item list: a bounded, typically single-digit-to-low-double-digit set of rows a Purchasing
Employee fills in mostly by picking (`ProductPicker`) rather than by rapid-fire numeric keying across
dozens of rows the way a 40-line journal entry's Debit/Credit grid is used. `PurchaseOrderLineItems` is
therefore implemented as the plain, accessible `<table>` pattern (`ACCESSIBILITY.md → Plain table pattern,
in full`) with ordinary `Tab` order across cells — real `<input>`/`<button>` elements in a real `<table>`,
no `role="grid"`, no roving `tabindex`, no arrow-key cell navigation to implement or test. Applying the
grid pattern here "to be safe" is exactly the anti-pattern `ACCESSIBILITY.md` warns against for any
surface that does not genuinely need cell-level arrow-key navigation.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `lines` | `PurchaseOrderLineFormValues[]` | yes | The current `useFieldArray` field list. |
| `onChangeLine` | `(index: number, patch: Partial<PurchaseOrderLineFormValues>) => void` | yes | Applied via `form.setValue`, never local component state. |
| `onAddLine` | `() => void` | yes | Appends a blank line; focuses its Product cell. |
| `onRemoveLine` | `(index: number) => void` | yes | Disabled below 1 remaining line. |
| `currencyCode` | `string` | yes | Header currency, inherited by every line's price display. |
| `vendorId` | `number \| null` | yes | Forwarded to each row's `ProductPicker` for the "previously purchased from this vendor" ranking. |
| `priceTolerancePercent` | `number \| null` | no | Set only when the PO is being created from an awarded RFQ line (`source_rfq_item_id` present); drives the inline price-tolerance caption (`# Interactions & Flows`, BR-PO-2). |

Each row renders: `ProductPicker`, a free-text `description` (auto-filled from the product, editable),
`quantity_ordered`, a read-only `unit_of_measure_id` label (from the product, or a `Select` when
`product_id` is null), `unit_price`, `discount_percent`, a `tax_code_id` `Select`, the computed
`line_amount` (`quantity_ordered × unit_price × (1 − discount_percent/100)`, read-only, recomputed on
every keystroke), an optional per-line `warehouse_id` override (collapsed behind a small "override
destination" toggle — most POs ship every line to the header's one `warehouse_id`, so repeating that
picker on every row by default would violate `DESIGN_LANGUAGE.md`'s "density with air" principle exactly
as `LineDimensionsPopover` was designed to avoid on Journal Entries), and a Remove button. Below the last
row, `[+ Add line]`; below that, the header-level Header Info section computes and shows the totals
described in `# Layout & Regions`.

## `MatchStatusTag`

A narrow, screen-specific `Badge` wrapper for a Bill's `match_status` (`not_matched` | `matched` |
`variance` | `override_approved`) — deliberately **not** folded into `StatusPill`'s domain system, because
`match_status` is an orthogonal axis to a Bill's lifecycle `status`: a Bill can be `pending_approval` and
simultaneously `variance` (blocked on a human decision) or `matched` (clear to proceed), and collapsing
both axes into one badge would hide exactly the distinction a Finance reader of this screen's Bills tab
needs. Tone mapping: `not_matched` → `neutral`, `matched` → `success`, `variance` → `danger`,
`override_approved` → `warning` (a variance was accepted, not eliminated — still worth a lighter flag).

```tsx
// components/purchasing/match-status-tag.tsx
import { Badge } from '@/components/ui/badge';
import { useTranslations } from 'next-intl';

const MATCH_STATUS_TONE = {
  not_matched: 'neutral', matched: 'success', variance: 'danger', override_approved: 'warning',
} as const;

export function MatchStatusTag({ matchStatus }: { matchStatus: keyof typeof MATCH_STATUS_TONE }) {
  const t = useTranslations('purchaseOrders.matchStatus');
  return <Badge tone={MATCH_STATUS_TONE[matchStatus]}>{t(matchStatus)}</Badge>;
}
```

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/PURCHASING.md` → API's table, annotated with which hook and
query key this screen wires it to. All are versioned under `/api/v1/purchasing/`, carry `X-Company-Id`,
and return the standard envelope.

| Method & Path | Permission | Hook | Query key |
|---|---|---|---|
| `GET /purchasing/purchase-orders` | `.read` | `usePurchaseOrders(filters)` | `purchaseOrderKeys.list(filters)` |
| `GET /purchasing/purchase-orders/{id}` | `.read` | `usePurchaseOrder(id)` | `purchaseOrderKeys.detail(id)` |
| `POST /purchasing/purchase-orders` | `.create` | `useCreatePurchaseOrder()` | invalidates `purchaseOrderKeys.lists()` |
| `PUT /purchasing/purchase-orders/{id}` | `.update` | `useUpdatePurchaseOrder(id)` | invalidates `detail(id)` + `lists()` |
| `POST /purchasing/purchase-orders/{id}/submit` | `.submit` | `useSubmitPurchaseOrder()` | optimistic status patch |
| `POST /purchasing/purchase-orders/{id}/approve` | `.approve` | `useApprovePurchaseOrder()` | optimistic status patch |
| `POST /purchasing/purchase-orders/{id}/reject` | `.approve` | `useRejectPurchaseOrder()` | optimistic status patch |
| `POST /purchasing/purchase-orders/{id}/send` | `.send` | `useSendPurchaseOrder()` | pessimistic — see `## Mutations` |
| `POST /purchasing/purchase-orders/{id}/amend` | `.amend` | `useAmendPurchaseOrder()` | invalidates `detail(originalId)` + navigates to the new amendment's `detail(newId)` |
| `POST /purchasing/purchase-orders/{id}/cancel` | `.cancel` | `useCancelPurchaseOrder()` | optimistic status patch |
| `GET /purchasing/purchase-orders/{id}/history` | `.read` | `usePurchaseOrderHistory(id)` | `purchaseOrderKeys.history(id)` |
| `POST /purchasing/goods-receipts` | `warehouse.receiving.create` | `useCreateGoodsReceipt()` | invalidates `purchaseOrderKeys.detail(poId)` + `purchaseOrderKeys.goodsReceipts(poId)` |
| `GET /purchasing/goods-receipts/{id}` | `warehouse.receiving.read` | `useGoodsReceipt(id)` | `goodsReceiptKeys.detail(id)` |
| `POST /purchasing/goods-receipts/{id}/reverse` | `warehouse.receiving.reverse` | `useReverseGoodsReceipt()` | invalidates `goodsReceiptKeys.detail(id)` + the parent PO's `detail`/`goodsReceipts` keys |
| `GET /purchasing/bills` (filtered `?filter[purchase_order_id]=`) | `purchasing.bill.read` | `usePurchaseOrderBills(poId)` | `purchaseOrderKeys.bills(poId)` |
| `GET /purchasing/purchase-orders/export` | `.export`/`reports.export` | `useExportPurchaseOrders()` | not cached — fires an async `report_runs` job, see `# Performance` |

## Query key factories

```ts
// lib/query/keys.ts (excerpt)
export const purchaseOrderKeys = {
  all: ["purchasing", "purchase-orders"] as const,
  lists: () => [...purchaseOrderKeys.all, "list"] as const,
  list: (filters: PurchaseOrderFilters) => [...purchaseOrderKeys.lists(), filters] as const,
  details: () => [...purchaseOrderKeys.all, "detail"] as const,
  detail: (id: number | string) => [...purchaseOrderKeys.details(), id] as const,
  history: (id: number | string) => [...purchaseOrderKeys.detail(id), "history"] as const,
  goodsReceipts: (id: number | string) => [...purchaseOrderKeys.detail(id), "goods-receipts"] as const,
  bills: (id: number | string) => [...purchaseOrderKeys.detail(id), "bills"] as const,
};

export const goodsReceiptKeys = {
  all: ["purchasing", "goods-receipts"] as const,
  detail: (id: number | string) => [...goodsReceiptKeys.all, "detail", id] as const,
};
```

`PurchaseOrderFilters` mirrors the query parameters `docs/accounting/PURCHASING.md` → API documents:
`status`, `vendor_id`, `po_type`, `warehouse_id`, `date_from`/`date_to`, `currency_code`, `q`, `sort`. The
List's named filters map directly: **Status** → `status` (array, `in`), **Vendor** → `vendor_id` (array),
**Type** → `po_type`, **Date** → a resolved `date_from`/`date_to` pair from `PeriodPicker`.

## Reference data

`VendorPicker`, `ProductPicker`, and the tax-code `Select` inside `PurchaseOrderLineItems` all depend on
read-mostly reference collections fetched through their own short, independent query with a generous
`staleTime` (5 minutes, per `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`'s "rarely-changing
reference/master data" tier) rather than being bundled into the PO payload:

| Reference | Endpoint | Consumed by |
|---|---|---|
| Vendors | `GET /api/v1/purchasing/vendors` (`vendors.read`) | `VendorPicker` (`statusFilter`, `showScorecard`) |
| Products | `GET /api/v1/purchasing/products` (`products.read`) | `ProductPicker` (`categoryFilter`, `allowServiceLine`) |
| Warehouses | `GET /api/v1/purchasing/warehouses` | Header `warehouse_id` `Select`; `PurchaseOrderLineItems`' per-line override |
| Tax codes | `GET /api/v1/accounting/tax-codes` | Each line's `tax_code_id` `Select` |
| Cost centers / Projects | `GET /api/v1/accounting/cost-centers`, `GET /api/v1/accounting/projects` | Optional per-line dimensions, rendered the same collapsed-popover way Journal Entries' `LineDimensionsPopover` does, reused rather than reinvented |
| Procurement contracts | `GET /api/v1/purchasing/procurement-contracts?status=active` | The Builder's "Create from contract" entry point, which pre-locks `contracted_unit_price` per BR-PC-1 |
| RFQs (awarded, unconverted) | `GET /api/v1/purchasing/rfqs?status=awarded&awarded_po_id=null` | The Builder's "Create from RFQ award" entry point |

The List itself follows the "transactional lists" tier from the same cache-tuning table: `staleTime:
30_000`. A single open Detail record additionally receives surgical realtime patches (`## Realtime`,
below), so it is never meaningfully stale between polls regardless of the nominal `staleTime`.

## Client state ownership

Consistent with `FRONTEND_ARCHITECTURE.md → State Management`:

| State | Owner |
|---|---|
| The PO list, one PO's detail, its history, its Goods Receipts, its linked Bills | TanStack Query cache, keyed as above |
| The in-progress Builder form values (header fields + `lines[]`) | React Hook Form's internal state, schema-validated by `purchaseOrderSchema` (`# Interactions & Flows`) |
| The `ReceiveGoodsSheet`'s in-progress receiving form | React Hook Form's internal state, schema-validated by `goodsReceiptSchema`, scoped to the Sheet's own lifetime — discarded on close, never leaked into the parent PO's own form state |
| List density, column visibility, saved filter presets | Zustand (`useDensityStore`, per-table-keyed) + `users.settings` sync |
| Selected rows for bulk export | Local component state inside `DataTable`, cleared on navigation |
| Which Detail tab is active | URL search param (`?tab=goods-receipts`), so a deep link to "PO-2026-004417's Bills tab" is shareable and survives a refresh — never local-only `useState` |
| A mid-PO draft's `Idempotency-Key`, keyed to the Builder's `formInstanceId` | `sessionStorage`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` — never regenerated across retries of the same submission attempt |

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md`'s Principle 10, this screen follows the identical split
`docs/frontend/JOURNAL_ENTRIES.md → Mutations` establishes: a reversible, non-financial-state action
(cancelling a still-`draft` PO's edit session, dismissing an AI note) updates the cache immediately and
rolls back on failure; a mutation that changes the commitment's authoritative state (submit, approve,
reject, **send**, amend, cancel, receive goods) waits for the server's `2xx` before the UI treats it as
done.

```ts
// hooks/purchasing/use-purchase-orders.ts (excerpt)
export function useSendPurchaseOrder() {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey("purchase-order-send");
  return useMutation({
    // No onMutate — "Send to Vendor" is an irreversible external transmission (the vendor is notified
    // the moment this succeeds), so it always renders a confirming Dialog before it fires (Interactions
    // & Flows) and never shows an optimistic "sent" state ahead of the server's actual confirmation.
    mutationFn: (id: number) => api.post(`/purchasing/purchase-orders/${id}/send`, {}, idempotencyKey),
    onSuccess: (po: PurchaseOrder) => {
      qc.setQueryData(purchaseOrderKeys.detail(po.id), po);
      qc.invalidateQueries({ queryKey: purchaseOrderKeys.lists() });
    },
  });
}

export function useCreateGoodsReceipt(purchaseOrderId: number) {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey("goods-receipt-create");
  return useMutation({
    // A physical receiving event is likewise pessimistic — it triggers a real stock_movements posting
    // and a GR/IR clearing journal entry server-side (docs/accounting/PURCHASING.md BR-GR-3), so the UI
    // never shows received quantities as accepted before the server has actually posted them.
    mutationFn: (payload: CreateGoodsReceiptInput) =>
      api.post(`/purchasing/goods-receipts`, { ...payload, purchase_order_id: purchaseOrderId }, idempotencyKey),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: purchaseOrderKeys.detail(purchaseOrderId) });
      qc.invalidateQueries({ queryKey: purchaseOrderKeys.goodsReceipts(purchaseOrderId) });
    },
  });
}
```

`useSubmitPurchaseOrder`, `useApprovePurchaseOrder`, `useRejectPurchaseOrder`, `useAmendPurchaseOrder`,
and `useCancelPurchaseOrder` follow `useSendPurchaseOrder`'s exact shape — no `onMutate`, a
client-generated `Idempotency-Key` per logical submission attempt, and a confirming `Dialog`/`AlertDialog`
in front of every one of them (Submit's own confirmation is the Builder's already-visible, already-correct
totals panel, mirroring Journal Entries' identical reasoning for why Submit alone skips an extra modal).

## Realtime

The List and the Detail page both subscribe to the company's private purchasing channel via Echo,
re-firing the exact domain events `docs/accounting/PURCHASING.md` § Vendor Integration already names
under "Domain events published to Vendors" — this hook does not invent a parallel set of event names:

```ts
// hooks/purchasing/use-purchase-order-realtime.ts
'use client';
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/realtime/echo';
import { purchaseOrderKeys } from '@/lib/query/keys';

export function usePurchaseOrderRealtime(companyId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`company.${companyId}.purchasing`);

    channel.listen('.purchase_order.issued', (e: PurchaseOrderIssuedEvent) => {
      // Fired once a PO transitions to 'sent' — matches docs/accounting/PURCHASING.md's
      // "purchase_order.issued" event published to Vendors, reused verbatim here.
      queryClient.setQueryData(purchaseOrderKeys.detail(e.purchase_order_id), (old?: PurchaseOrder) =>
        old ? { ...old, status: 'sent', sent_at: e.sent_at } : old,
      );
      queryClient.invalidateQueries({ queryKey: purchaseOrderKeys.lists(), refetchType: 'inactive' });
    });

    channel.listen('.goods_receipt.posted', (e: GoodsReceiptPostedEvent) => {
      // Advances the parent PO's per-line quantity_received and, at full coverage, its own status —
      // the frontend patches optimistically-safe fields only and otherwise re-fetches the detail record,
      // since the exact line_status/header status transition math (BR-PO-6) lives server-side.
      queryClient.invalidateQueries({ queryKey: purchaseOrderKeys.detail(e.purchase_order_id) });
      queryClient.invalidateQueries({ queryKey: purchaseOrderKeys.goodsReceipts(e.purchase_order_id) });
    });

    for (const event of ['.purchase_order.submitted_for_approval', '.purchase_order.approved',
                          '.purchase_order.rejected', '.quality_inspection.completed', '.bill.posted']) {
      channel.listen(event, (e: { purchase_order_id?: number }) => {
        if (e.purchase_order_id) {
          queryClient.invalidateQueries({ queryKey: purchaseOrderKeys.detail(e.purchase_order_id) });
          queryClient.invalidateQueries({ queryKey: purchaseOrderKeys.bills(e.purchase_order_id) });
        }
        queryClient.invalidateQueries({ queryKey: purchaseOrderKeys.lists(), refetchType: 'inactive' });
      });
    }

    return () => { echo.leave(`company.${companyId}.purchasing`); };
  }, [companyId, queryClient]);
}
```

The currently-open Detail record is patched surgically for the fields a push payload actually carries
(status, `sent_at`), with the row briefly flashing an `accent-subtle` tint per `DESIGN_LANGUAGE.md →
Motion → Realtime row update` before easing back; anything that depends on server-computed aggregation
(received/billed quantities, `line_status`, the header's own rolled-up status) is invalidated and
re-fetched rather than hand-computed client-side, since duplicating BR-PO-6's exact roll-up logic in the
browser would be exactly the kind of business logic `# Purpose` states this frontend never owns. The
List's own cached pages are only marked `invalidateQueries(..., { refetchType: 'inactive' })` — refetched
the next time they become the active query, never yanked out from under a user mid-scroll.

# Interactions & Flows

**Creating a Purchase Order from scratch.** From the List, "New Purchase Order" (gated
`purchasing.order.create`) opens `/new`. `PurchaseOrderForm` mounts with `mode="create"`, `po_type`
defaulting to `standard`, `currency_code` defaulting to the company's base currency, and one blank line
already present. The Purchasing Employee picks a vendor (`VendorPicker`, `showScorecard`), which
resolves `shipping_address`/`billing_address` from that vendor's `vendor_addresses` and, if the vendor's
`status` is not `active`, blocks selection entirely with an inline reason rather than allowing a doomed
submission (`docs/accounting/PURCHASING.md` § Vendor Integration: "a blocked/suspended vendor cannot
receive a new PO, enforced at creation"). Each line's `ProductPicker` selection auto-fills description,
UoM, and a starting `unit_price` from the vendor's last-paid price for that product where one exists;
every value remains fully editable. The Totals Rail recomputes `subtotal_amount`/`tax_amount`/
`total_amount` on every keystroke — a fast, friendly, purely cosmetic preview, exactly mirroring the
platform-wide rule that the server independently re-derives and is authoritative (BR-PO-1).

**Creating a Purchase Order from an awarded RFQ.** The Builder's "Create from RFQ award" entry point
(a `Select` of the caller's own awarded-but-unconverted RFQs, `# Data & State` reference data) pre-fills
`vendor_id`, `rfq_id`, `currency_code`, and every line's `product_id`/`quantity_ordered`/`unit_price` from
the winning `rfq_responses.line_quotes`, with each line's `source_rfq_item_id` set. This is the common,
fastest path to a PO and the one under which BR-PO-2's price-tolerance check becomes active
(`priceTolerancePercent` passed to `PurchaseOrderLineItems`, `# Components Used`): editing a pre-filled
line's `unit_price` away from the awarded quotation by more than the configured tolerance (default 2%)
renders an inline, `warning`-toned caption under that line's price cell — "4.7% above the awarded RFQ
price of 2.150 — requires an override" — and the primary action stays enabled only for a holder of
`purchasing.order.override_price`; anyone else's out-of-tolerance edit is rejected client-side with that
same reason before it ever reaches the server, which independently re-checks and is the actual
authority. Overriding logs the reason exactly as `docs/accounting/PURCHASING.md` describes for BR-PO-2
("a larger deviation... is logged").

**Creating a Purchase Order from an approved Purchase Request or a contract release.** Analogous
entry points pre-fill from `purchase_request_items` (setting each line's `source_pr_item_id`) or from an
active `procurement_contracts` row (setting `procurement_contract_id`, locking `unit_price` to
`contracted_unit_price` per BR-PC-1 unless the user holds `purchasing.contract.override_price` — a
permission this screen surfaces identically to the RFQ-tolerance case, with its own distinct inline
reason). A PO sourced from a PR whose own `source = 'ai_forecast'` carries that provenance forward for
display purposes only (`# AI Integration`) — the PO row itself is never marked AI-originated, since only
Purchase Requests are directly AI-draftable in this module.

**Save Draft vs. the primary action.** Save Draft (`purchasing.order.create`/`.update`) is always
available once the header's required fields and at least one complete line are present. The primary
button's label and behavior are resolved from `purchasing.order.submit`/`.send`, never guessed: for a PO
whose `total_amount_base` falls below the lowest approval tier (≤500 KWD-equivalent still requires at
least Purchasing Manager sign-off per `docs/accounting/PURCHASING.md` § Approval Workflow's default
table, so an approval-exempt direct-send path is a company-configurable exception rather than the
default), the click calls `POST .../submit`; the response's resulting `status` is what the UI reflects —
usually `pending_approval`. The button is additionally disabled — with an inline reason, not a bare
`disabled` — while any required header field is empty or every line is incomplete.

**Approval.** Opening a `pending_approval` PO the viewer is an eligible approver for renders an inline
`ApprovalCard` (`kind="purchase_order"`) above the read-only `PurchaseOrderItemsTable`, following the
identical multi-level `Stepper` progression, mandatory-reason Reject, and out-of-order-approval
prevention `docs/frontend/JOURNAL_ENTRIES.md → Interactions & Flows` documents for its own `ApprovalCard`
usage — this document does not re-derive that mechanism, only names the one PO-specific difference:
approving the *final* required tier transitions the PO to `approved`, **not** directly to `sent` — sending
is always its own separate, explicit action (`# Interactions & Flows`, next), because transmitting a PO
to a vendor is an irreversible external event distinct from the internal decision to approve it, unlike
a Journal Entry's approval-then-synchronous-post pairing.

**Sending to the vendor.** "Send to Vendor" (gated `purchasing.order.send`, rendered only on an
`approved` PO) opens a confirming `Dialog` naming the vendor, the total, and the transmission channel
(`email`/`portal`/`manual` — a `Select` inside the dialog, defaulting to the vendor's preferred channel
from `vendor_contacts`). Confirming calls `POST .../send`; per `## Mutations`, this is pessimistic — the
UI shows a pending spinner on the button itself and only reflects `sent` once the server actually
confirms transmission, never optimistically. Once `sent`, every line's `unit_price`/`quantity_ordered`/
`tax_code_id` becomes permanently immutable (BR-PO-3) — the Detail page for a `sent`+ PO renders these
values as plain text even to a holder of `purchasing.order.update`, because no UI affordance should imply
an edit path the backend does not honor.

**Amending a sent PO.** Covered fully under `# Route & Access`'s `amend` mode: "Amend" opens the Builder
pre-filled from the current PO, submits to `POST .../amend`, and the resulting new PO row becomes the
live document going forward while the original is marked `superseded_by` and rendered permanently
read-only with a forward cross-link. The Builder's header in `amend` mode carries a persistent banner —
"Amending PO-2026-004416 — the original stays on record unchanged" — so a Purchasing Manager never
mistakes this for an in-place edit.

**Receiving goods.** "Receive Goods" (gated `warehouse.receiving.create`, rendered on a `sent` or
`partially_received` PO) opens `ReceiveGoodsSheet`, a slide-over pre-listing every line whose
`quantity_ordered − quantity_received` is still positive, each with an editable `quantity_received`
input (defaulting to the full remaining quantity — the "a truck delivered everything at once" common
case) plus `bin_location_id`, `condition` (`good`/`damaged`/`partial_damage`), and, for a batch/serial-
tracked product, a required `batch_number` or exact-count `serial_numbers` entry (BR-GR-2). Reducing a
line's quantity below the remaining balance leaves that line `partially_received` and immediately
re-openable for a future Goods Receipt against the same PO — receiving is explicitly designed for
multiple, incremental drops. Submitting calls `POST /purchasing/goods-receipts` with `purchase_order_id`
fixed to the current PO and every entered line in one request (the "common case for a truck delivering a
full PO in one drop" the platform's own bulk-receiving rationale describes); the Sheet closes only after
the server's `2xx`, per `## Mutations`'s pessimistic treatment of a physical, stock-moving event. A GR
whose lines include a product requiring inspection posts with `requires_inspection = true` and lands the
received quantity in a `pending_inspection` hold (BR-GR-4) rather than unrestricted stock — the Goods
Receipts tab's row for that GR shows a small "awaiting inspection" note, and this screen does not itself
render the Quality Inspection workflow (a Warehouse-owned surface out of this document's scope), only
this read-only signal that one is pending.

**Reversing a Goods Receipt.** "Reverse receipt" (`warehouse.receiving.reverse`) is offered only on a
`posted` GR with zero downstream references — no Quality Inspection, no Bill line billed against it
(BR-GR-6) — and is otherwise omitted, not disabled-with-no-explanation, from the row-action menu; a GR
that already has a reference must be corrected via a Purchase Return instead, and this screen's copy
says so explicitly rather than leaving a Warehouse Employee to guess why the option is missing.

**Understanding three-way match from this screen.** The Bills tab lists every Bill referencing this PO
(`GET /purchasing/bills?filter[purchase_order_id]=`), each row showing `bill_number`,
`vendor_bill_reference`, `total_amount`, its lifecycle `StatusPill domain="bill"`, and its
`MatchStatusTag`. This screen is a **read-only cross-reference** into Bills — resolving a `variance`
match (accepting it, disputing it, or correcting the Bill) is an action that belongs to the Bills screen
(`purchasing.bill.override_variance`, out of scope for this document) and every row here simply links
out to `/purchasing/bills/{id}`. What this screen does own is the *visibility* a Purchasing Manager or
Finance user needs without leaving the PO: at a glance, whether the PO's committed price and quantity are
reconciling cleanly against what has actually been billed, without needing to separately query Bills.

**Cancelling.** "Cancel" (`purchasing.order.cancel`) opens a `Dialog` collecting a mandatory reason.
Available from `draft` through `approved`; a `sent` PO additionally requires the dialog to confirm
vendor acknowledgment has been recorded before the action proceeds (`docs/accounting/PURCHASING.md` §
Purchase Orders' lifecycle note: "a `sent` PO can only be cancelled with vendor acknowledgment recorded,
since the vendor has already been notified"), rendered as a required checkbox inside the same dialog
rather than a second screen.

**Force-closing.** "Force close" (`purchasing.order.force_close`, rendered only when the PO would
otherwise be blocked from `closed` by an unpaid linked Bill, BR-PO-7) opens a `Dialog` naming the specific
unpaid Bill(s) and collecting a mandatory reason (e.g., "vendor dispute, will not be paying — abandoning
remainder"). Any Purchasing Manager or Finance user without this permission sees "Close" simply absent
from the menu, with the blocking reason surfaced as an informational note rather than a dead button.

**Bulk export from the List.** Selecting ≥1 row swaps the Filter Bar for the Bulk Action Bar; "Export"
is the only bulk action offered (`# Layout & Regions` explains why approve/send/cancel are deliberately
excluded from bulk here), firing the same async `report_runs` job path described in `# Performance`.

# AI Integration

Unlike Journal Entries, no output on this screen is ever itself an AI-authored Purchase Order — the
Purchasing Agent's mandate boundary is explicit that only a Purchase Request can be AI-drafted directly
(`docs/ai/agents/PURCHASING_AGENT.md` § Role & Mandate: "It may draft a Purchase Request; it never
[creates a Purchase Order]... It may rank vendors and recommend an RFQ shortlist; it never issues an RFQ
or awards a quotation"). AI therefore touches this screen at exactly three points, all **advisory**, none
of them a "Do it" affordance, and none of them structurally capable of appearing as one:

**1. Inherited provenance from an upstream AI-sourced document.** When a PO is created from a Purchase
Request whose `source = 'ai_forecast'` (the Purchase Prediction capability, `docs/ai/agents/
PURCHASING_AGENT.md` § Autonomy Level — draft-only, always landing in `draft` for a human to review and
submit before it ever reaches this screen), the Builder's header shows a small, dismissible provenance
note: "This PO traces to an AI-forecasted reorder for A4 Copy Paper 80gsm, reviewed and submitted by
[requester] on [date]." This is a read-only fact about the document's ancestry (via
`source_pr_item_id → purchase_request_items → purchase_requests.source`), not a live AI recommendation
happening on this screen — the AI's actual proposal and its confidence/reasoning were already presented,
reviewed, and approved on the Purchase Request's own screen, upstream of here. No confidence score is
re-shown at the PO stage, because none is computed at the PO stage; re-displaying the PR's original
confidence here would misleadingly suggest the AI had an opinion about the *Purchase Order* specifically,
which it did not.

**2. Vendor Recommendation surfaced inline via `VendorPicker`'s `showScorecard`.** When a Purchasing
Employee builds a PO directly (no RFQ), `VendorPicker`'s `showScorecard` mode reads the same
`on_time_delivery_rate`, `quality_score`, and `average_lead_time_days` fields the Vendor Recommendation
and Supplier Performance agents compute nightly (`docs/accounting/PURCHASING.md` § AI Responsibilities →
Vendor Recommendation, Supplier Performance) and renders them inline next to each candidate vendor —
"Gulf Paper Co. — 96% on-time, quality 4.6/5, avg. lead time 9 days." This is **not** a live model call
this screen makes; it is a plain, already-computed-and-stored read of the vendor's own scorecard fields,
consistent with `docs/ai/agents/PURCHASING_AGENT.md`'s statement that Supplier Performance computation is
"fully automatic... any resulting commercial decision... remains entirely human." When the PO instead
originates from an awarded RFQ, the Summary Rail additionally shows the confidence-scored ranking that
actually informed that award (`ConfidenceBadge`, sourced from the RFQ's own comparison grid,
`docs/accounting/PURCHASING.md` § Vendor Quotations) — e.g., "Ranked #1 for this RFQ · 96% on-time, 0.83
confidence · View reasoning" opening a `Sheet` with the Vendor Recommendation agent's stored reasoning
text, exactly the pattern `docs/frontend/JOURNAL_ENTRIES.md → AI Integration`'s "Explanation" capability
follows for its own `GET .../ai-explanation`-style endpoint.

**3. Price-tolerance and anomaly captions, never a silent correction.** BR-PO-2's ±2% (default,
company-configurable) tolerance check against an awarded RFQ price, and the Price Comparison agent's
statistical-outlier narrative computed at RFQ-close time (`docs/accounting/PURCHASING.md` § AI
Responsibilities → Price Comparison: ">2 standard deviations from the response set's mean"), both surface
on this screen as inline, `warning`-toned captions under the affected line — never a value the UI changes
on the user's behalf. A line whose price was flagged as a statistical outlier at RFQ stage carries that
flag forward onto the resulting PO's line as a small, dismissible note in the Builder and a permanent,
non-dismissible one on the read-only Detail view's Items tab, so the fact that a price was unusual remains
part of the PO's own permanent record even after the RFQ itself is long closed.

**What never appears here.** There is no `AIProposalPanel` on this screen (that component is reserved for
the AI Command Center's own `ai_decisions` feed) and no "auto-approve" or "auto-send" control of any
kind, because `purchasing.order.approve` and `purchasing.order.send` are never granted to the `ai_agent`
principal under any company configuration (`docs/ai/agents/PURCHASING_AGENT.md` § Guardrails & Human
Approval — these sit among the module's five structurally hard-coded sensitive gates). A Fraud Detection
alert touching a vendor this PO references (e.g., a recent `vendor.bank_account_changed` event) is
likewise never rendered on this screen directly — that alert routes to Finance/Auditor via the Vendor
Payments surface, since a bank-account change is a payment-time risk, not a commitment-time one; this
screen's only acknowledgment of vendor risk is the read-only scorecard fields in point 2 above.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | List: `Skeleton` rows shaped like the table; Detail: header + rail skeleton |
| Empty (no POs at all) | Brand-new company, zero `purchase_orders` rows | `EmptyState`: "No purchase orders yet," CTA "New Purchase Order" gated on `.create` |
| Empty (filtered to nothing) | Filters legitimately produce zero rows | Lighter `EmptyState` variant, "No orders match your filters," never conflated with the true-empty case |
| Draft (editable) | `status: 'draft'` | `PurchaseOrderForm` in edit mode |
| Draft, incomplete | Required header field empty or a line is incomplete | Primary action disabled with inline reason; Save Draft remains enabled |
| Pending approval — viewer is the current approver | `status: 'pending_approval'`, viewer eligible for the open tier | `ApprovalCard` fully interactive (Approve/Reject/Delegate) |
| Pending approval — viewer is not the current approver | Same status, viewer not eligible right now | `ApprovalCard` renders read-only, or the current tier is shown as a locked, greyed preview naming the required role |
| Approved, not yet sent | `status: 'approved'` | Read-only `PurchaseOrderDetail`; "Send to Vendor" is the one lifecycle action offered |
| Rejected | `status: 'rejected'` | Read-only `PurchaseOrderDetail` with the rejection reason surfaced prominently; Edit available (returns it to `draft` on save) to a holder of `.update` |
| Sent | `status: 'sent'` | Fully read-only Items; "Receive Goods," "Amend," and "Cancel" (with vendor-acknowledgment confirmation) are the lifecycle actions offered |
| Partially received | `status: 'partially_received'` | Same as `sent`, plus the Goods Receipts tab is non-empty and each remaining open line still accepts further receiving |
| Fully received | `status: 'fully_received'` | "Receive Goods" no longer offered; "Close" available once every linked Bill is `paid` (BR-PO-7), else blocked with the reason and, for an eligible role, "Force close" |
| Closed | `status: 'closed'` | Fully read-only, permanently queryable; excluded from the List's default filter but reachable via Status |
| Cancelled | `status: 'cancelled'` | Read-only, with the cancellation reason surfaced prominently |
| Amendment created | `POST .../amend` succeeds | Original PO's Detail page shows a persistent "Superseded by PO-2026-004512 →" cross-link; the new amendment opens automatically |
| RFQ-price tolerance exceeded (inline) | A Builder line's `unit_price` deviates from its `source_rfq_item_id`'s awarded price beyond tolerance | `warning`-toned inline caption; primary action requires `purchasing.order.override_price` to proceed (`# Interactions & Flows`) |
| Over-receipt attempted | `ReceiveGoodsSheet` submission would push a line's cumulative `quantity_received` beyond `quantity_ordered × (1 + tolerance)` (BR-PO-5/BR-GR-1) | `409` surfaced inline on the specific line with the exact tolerance and excess named, never a generic failure |
| Batch/serial fields missing | A line for a tracked product submitted without `batch_number`/matching `serial_numbers` count (BR-GR-2) | `422` inline on the specific field, blocking submission before the request round-trips |
| Version conflict | `409` on a `draft`/`rejected` save | Toast: "This order was modified by someone else — reload and retry"; unsaved local edits are preserved until the user chooses to reload |
| Vendor blocked mid-Builder | The selected vendor's `status` changes to `blocked`/`suspended` while the Builder is open | Save Draft still succeeds; Submit/Send are blocked with an inline reason naming the vendor's new status |
| Session permission revoked mid-view | A subsequent mutation attempt returns `403` after the permission set changes | The affected control collapses to its disabled-with-tooltip form; no stale enabled control is trusted from the page's initial load |
| Error | `403` / `404` / `5xx` / network failure | Shared `403` boundary, `not-found.tsx`, or `ErrorState` with retry, per which failure occurred (`# Route & Access`) |

# Responsive Behavior

**List, Mobile (`base`–`sm`).** Follows `RESPONSIVE_DESIGN.md → Adaptive Patterns → Pattern 1`: rows
become cards, each headed by the PO number and `StatusPill`, with a two-column definition list of the
`priority: 1–2` fields (vendor, order date, and the total amount via `AmountCell` plus a muted
`CurrencyTag`). Filters collapse behind a single badge-counted "Filters" trigger opening a `Sheet`; the
density toggle and column-visibility menu are hidden entirely, matching the identical mobile treatment
`docs/frontend/JOURNAL_ENTRIES.md → Responsive Behavior` documents for its own List.

**Builder, Mobile (`base`–`sm`).** `PurchaseOrderLineItems`' table rows do not render as table rows at
all below `sm:` — per `RESPONSIVE_DESIGN.md → Multi-line editors: journal lines and invoice items`, each
line instead renders as a stacked card: `ProductPicker` full-width, a two-column Qty/Price pair beneath
it, Discount %/Tax on a third row, the computed line amount right-aligned, then a full-width, `min-h-11`
"Remove line" button. The Totals Rail collapses into an inline panel directly above the sticky submission
bar; the sticky Cancel/Save Draft/primary-action bar sits `sticky bottom-0` above the safe area, and a
failed submit auto-scrolls to the first invalid field, per `RESPONSIVE_DESIGN.md → Sticky submission and
error handling`.

**Builder, Tablet (`md`–`lg`).** A two-column header grid; `PurchaseOrderLineItems` renders as the dense
inline table row from `lg:` up; the Totals Rail is still an inline panel below `xl:` rather than a true
side rail, matching the identical breakpoint boundary `RESPONSIVE_DESIGN.md` specifies for Journal
Entries' own line editor.

**Builder, Desktop (`xl:`+).** The full three-region Form Page Template: Form Body + a genuine Totals
Rail beside it, `PurchaseOrderLineItems` as a plain, dense table with ordinary `Tab` order (no roving
grid navigation to reflow at any tier, since this screen never adopted the ARIA `grid` pattern to begin
with — see `# Components Used`).

**Detail, all tiers.** The Summary Rail moves above the Tab/Segment Nav on Mobile/Tablet (key facts read
before the detail tabs) and returns to a true 4/12 rail from `lg:` up. `PurchaseOrderItemsTable`,
`GoodsReceiptsTable`, and `LinkedBillsTable` each use the sticky-first-column, horizontal-scroll treatment
`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` specifies for a tabular grid that does not
decompose into cards — a PO's line/receipt/bill lists are read-only and homogeneous enough on a small
screen that a card transformation would add friction, not clarity, matching the identical reasoning
`docs/frontend/JOURNAL_ENTRIES.md`'s own `JournalLinesTable` uses. `ReceiveGoodsSheet` becomes a
full-height bottom sheet on Mobile/Tablet and a right-side panel from `lg:` up, per the platform's
standard `Sheet` responsive behavior.

**Virtualization.** `DataTable` on the List virtualizes past roughly 200 rows via
`@tanstack/react-virtual`; `PurchaseOrderLineItems` does not need virtualization in practice (a Purchase
Order's line count is bounded by what a single vendor delivery reasonably contains — tens, not
hundreds, of lines), so this screen does not carry the extra virtualization machinery Journal Entries'
line editor needs for its occasional 40+ line consolidating entries.

**Touch targets.** Every interactive element — row-action icons, the mobile card's Remove-line and
Receive-line inputs — meets the platform's 44×44px minimum regardless of the active table density, via
hit-slop expansion on dense desktop rows rather than growing the row itself, per `LAYOUT_SYSTEM.md →
Density Modes`.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, and the screen inherits
`THEMING.md`'s RTL-as-a-theming-dimension and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a single
per-component RTL branch, plus the module-specific applications this screen introduces:

- **Bilingual vendors and products.** `VendorPicker`, `ProductPicker`, and every table on this screen
  render `name_ar` when the active locale is `ar` and fall back to `name_en` only if the Arabic name is
  genuinely absent — never the reverse, and never a mixed-language row where a PO/vendor/product code
  stays Latin (always LTR-isolated) but the name silently stays English under an Arabic session.
- **Amount alignment is physically fixed.** Every money column and the Totals Rail's figures are
  `text-end` in both directions — never re-derived from `text-right` — so a bilingual Purchasing team
  scanning magnitude by decimal alignment sees amounts land on the same edge regardless of session
  language, exactly as `AmountCell`'s own `align="end"` default guarantees.
- **PO numbers, dates, incoterms, and percentages stay LTR-embedded** inside Arabic sentences (the
  price-tolerance caption's narrative text, the amendment cross-link banner) via the shared
  `Bidi`/`LtrInline` wrapper (`unicode-bidi: isolate` + `dir="ltr"`), so "PO-2026-004417," "FOB," or
  "4.7%" never reorders inside a right-to-left paragraph.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching
  `AmountCell`'s own `formatAmount` implementation and the Gulf financial-document convention that a
  mixed numeral system inside a single order reads as an error to a Purchasing Manager.
- **Multi-currency presentation mirrors correctly.** A transaction-currency total followed by a muted
  base-currency equivalent (`# Layout & Regions`) keeps the same visual order — primary figure first,
  muted annotation beneath — in both directions; only the text alignment mirrors, never the
  primary/secondary hierarchy itself.
- **Arabic procurement terminology is used precisely throughout this screen and its translations:**
  أمر شراء (purchase order), مسودة (draft), معلق الموافقة (pending approval), معتمد (approved), مُرسَل
  للمورد (sent to vendor), استلام جزئي (partially received), استلام كامل (fully received), مغلق (closed),
  مرفوض (rejected), ملغى (cancelled), إذن استلام (goods receipt), تعديل أمر الشراء (PO amendment),
  المطابقة الثلاثية (three-way match), فاتورة المورد (vendor bill).
- **Directional chrome mirrors; content chrome does not.** `ReceiveGoodsSheet` and the AI reasoning
  `Sheet` slide from the logical end edge (left in RTL); breadcrumb chevrons and the Detail page's
  back-link arrow mirror via `rtl-flip`; the module icon and every status/severity glyph do not, per
  `ICONOGRAPHY.md`'s directional-icon table.
- **The plain `<table>` pattern's column order is logical, not physical**, so `PurchaseOrderLineItems`
  and every read-only table on this screen reorder their columns naturally under `dir="rtl"` — unlike
  Journal Entries' deliberate Debit/Credit exception, a PO line has no universal left-to-right accounting
  convention to preserve, so this screen has no equivalent "physically fixed column order" exception to
  document.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a bare Tailwind palette utility outside the shared component files it
already composes.

- **Surfaces and elevation.** The List and Detail canvases sit on `--surface-canvas`; every `Card`
  section in the Builder (Header Info, Line Items, Shipping/Billing Address, Notes) and the Detail's
  Summary/Totals rails sit on `--surface-base`; `ReceiveGoodsSheet`, the Send/Cancel/Force-close
  `Dialog`s, and `VendorPicker`/`ProductPicker`'s `Popover` sit on `--surface-overlay`. Elevation steps
  *lighter* toward the viewer (canvas → base → raised → overlay) in dark mode, and every container
  carries its paired `--border-subtle` even where the light-mode equivalent relies on shadow alone — a
  borderless dark `Card` disappears against the near-black canvas, the single most common dark-mode
  regression this document's siblings guard against and the same rule this screen inherits unchanged.
- **Status tones.** `StatusPill`'s `domain="purchase_order"` and `domain="goods_receipt"` lookup tables
  (`# Components Used`, this document's own addition to `STATUS_TABLES`) resolve through `DARK_MODE.md`'s
  semantic tokens (`--status-success`/`--status-warning`/`--status-error` plus the reserved `--accent` for
  `approved`/`sent`) and are independently contrast-checked in both themes — a pairing is never assumed to
  pass in dark mode because its light-mode counterpart passed, exactly as `DARK_MODE.md → Color &
  Contrast In Dark` requires for every new domain a screen document introduces.
- **Amounts are never status-colored.** Every figure rendered through `AmountCell` on this screen keeps
  its ink/text-primary tone in both themes; only `StatusPill` and `MatchStatusTag` carry a semantic status
  color — a large PO total is not "bad" and a small one is not "good," and the UI never implies otherwise
  through color alone.
- **AI provenance uses one reserved hue, never a finance-status hue.** The RFQ-ranking `ConfidenceBadge`,
  the Builder's AI-note card accent border, and the ancestry-provenance banner all use
  `--ai-accent`/`--ai-accent-subtle` exclusively, in both themes — never `--status-success`/`-warning`/
  `-error`, because a vendor's on-time percentage and the commercial soundness of this specific PO are two
  independent facts and collapsing them into one color would imply a correlation the data does not
  assert.
- **The price-tolerance warning dot** on a line flagged by BR-PO-2 uses `--status-warning`, not
  `--status-error` — an out-of-tolerance price blocks *submission* for a non-privileged user, not the
  ability to keep typing or to save a draft, and the visual weight matches that distinction in both
  themes.
- **`MatchStatusTag`'s four tones** (`not_matched`→neutral, `matched`→success, `variance`→danger,
  `override_approved`→warning) resolve through the identical semantic tokens `StatusPill` uses, keeping
  the Bills tab's cross-reference visually consistent with every other status surface on the platform
  rather than inventing a parallel palette for one small badge.
- **Contrast.** Every pairing above is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text, borders,
  focus rings) in both themes per `DARK_MODE.md → Color & Contrast In Dark`.
- **Exports render light, always.** A PDF/XLSX export of a PO or a filtered list — regardless of the
  exporting user's active theme — renders in QAYD's fixed light/print palette (`DARK_MODE.md → Exported
  PDFs always render light`), because a Purchase Order is routinely forwarded to a vendor or an auditor
  who has never opened the dark-mode app at all.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, which this screen implements without deviation, plus the
component-specific notes this screen's own choices require.

- **One table pattern used throughout — deliberately never the ARIA `grid`.** The List,
  `PurchaseOrderLineItems`, `PurchaseOrderItemsTable`, `GoodsReceiptsTable`, and `LinkedBillsTable` all use
  the plain, accessible `<table>` pattern — real `scope="col"` headers, sortable headers as real
  `<button>`s with `aria-sort` on the parent `<th>`, a horizontally-scrolling wrapper that is itself
  `role="region"` + `tabIndex={0}` + a real `aria-label`, and a uniquely-worded `aria-label` on every row
  action ("Receive goods for PO-2026-004417," never a bare "Receive"). `# Components Used` explains at
  length why this screen does not introduce a third ARIA `grid` surface alongside the two
  `ACCESSIBILITY.md` names by name (the Journal Entry line editor and the Bank Reconciliation matching
  grid) — this is a considered omission, not an oversight, and future engineers extending this screen
  should not "upgrade" `PurchaseOrderLineItems` to a grid without a documented, equally specific reason.
- **RBAC-disabled controls explain themselves, and business-rule-disabled controls explain themselves
  differently.** A Submit button disabled because the viewer lacks `purchasing.order.submit` carries
  `aria-describedby` naming the exact missing permission; the same button disabled because a required
  header field is empty carries a different, specific `aria-describedby` naming that field. A price cell
  rejected for exceeding BR-PO-2's tolerance without `purchasing.order.override_price` carries a third,
  distinct reason ("This price is 4.7% above the awarded RFQ quote — requires purchasing.order
  .override_price to proceed"). The three are never collapsed into one generic "You can't do this right
  now," per `ACCESSIBILITY.md`'s explicit rule that conflating them is a P1 defect.
- **A sensitive action with no permission is omitted, not disabled.** Send, Amend, Cancel, Force-close,
  Receive Goods, and Reverse-receipt are removed from the DOM entirely for a role lacking the
  corresponding permission — never rendered disabled with no explanation — matching
  `NAVIGATION_SYSTEM.md → Command Palette`'s identical `actions.filter(hasPermission)` precedent, because
  a disabled control with no visible content behind it leaves a screen-reader user unable to tell whether
  the feature exists at all.
- **Amounts and match status are never color-only.** Every `AmountCell` carries the platform's triple
  redundancy — sign/glyph, directional cue, and a `VisuallyHidden` text equivalent ("Total of 4,515.00
  US Dollars") — and every `MatchStatusTag`/`StatusPill` renders its label as a real text node beside the
  tone dot, never inferable from color alone.
- **Focus management on overlays.** `ReceiveGoodsSheet`'s `onOpenAutoFocus` moves focus to its heading,
  not its close button; closing any `Dialog`/`Sheet`/`AlertDialog` on this screen returns focus to the
  control that opened it. A price-tolerance override that auto-expands an inline reason field moves focus
  to that field rather than leaving focus stranded on the price input that triggered it.
- **Keyboard.** `N` (scoped to this route) opens the Builder; inside `PurchaseOrderLineItems`, `Tab`
  moves cell-to-cell in natural DOM order (no roving-tabindex machinery to test or maintain, per the
  deliberate non-grid choice above) and `Tab` from the last cell of the last row reaches `[+ Add line]`
  next, never trapping focus inside the table. Every `Dialog`/`AlertDialog`'s primary action responds to
  `Cmd`/`Ctrl+Enter`.
- **Realtime updates never yank focus or splice silently.** A `.goods_receipt.posted` push affecting a
  PO the Finance user is currently reading updates the Summary Rail and the Goods Receipts tab's cached
  data without reordering the Items table or moving keyboard focus, per the platform-wide realtime-
  accessibility rule `# Edge Cases` restates for this screen specifically.
- **`ReceiveGoodsSheet`'s per-line quantity inputs are individually labeled** ("Quantity received for A4
  Copy Paper 80gsm, line 1 of 3"), never relying on visual column position alone to convey which product
  a given input belongs to — critical given the Sheet's line count can legitimately reach the low tens for
  a large delivery.

# Performance

- **Server-paginated, never client-sorted or client-filtered.** The List's `DataTable` issues a new
  request for every sort, filter, or page change — a company with tens of thousands of Purchase Orders
  never ships more than one page's worth of rows to the browser at a time.
- **Denormalized progress fields read directly, never re-aggregated client-side.**
  `purchase_order_items.quantity_received`/`quantity_billed`/`quantity_returned` arrive pre-computed from
  the server (`docs/accounting/PURCHASING.md § Performance`: "maintained transactionally at write time
  specifically so that... list queries never require a runtime aggregation join") — `PurchaseOrderItemsTable`
  renders these fields directly and never sums `goods_receipt_items`/`bill_items` in the browser to derive
  them, both because that would duplicate business logic (`# Purpose`) and because it would be slower and
  staler than the number the API already maintains.
- **Cache tuning matches data volatility**, per `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`:
  the List's `staleTime` is 30 seconds (transactional-list tier); reference data feeding `VendorPicker`,
  `ProductPicker`, tax codes, and cost centers/projects is 5 minutes (rarely-changing reference tier); a
  single open Detail record additionally receives surgical realtime patches (`# Data & State`), so it is
  never meaningfully stale between polls regardless of the nominal `staleTime`.
- **Debounced, cancelled vendor/product search.** `VendorPicker` and `ProductPicker` debounce their query
  250ms and are only `enabled` while their `Popover` is open, so a vendor or product master with several
  hundred rows never fires a request per keystroke nor fetches speculatively before the picker is opened.
- **Idempotent, single-attempt mutations.** Every write endpoint this screen calls (`create`, `submit`,
  `approve`, `reject`, `send`, `amend`, `cancel`, the Goods Receipt `create`) carries a client-generated
  `Idempotency-Key` scoped to one logical submission attempt, so a slow response on a flaky connection
  followed by an impatient second tap can never double-send a PO to a vendor or double-post a Goods
  Receipt's stock movement.
- **Async, non-blocking export.** `GET /purchasing/purchase-orders/export` fires an async `report_runs`
  job rather than holding a request open; the frontend never blocks the UI, subscribing instead to the
  job's own completion signal and surfacing a toast with a download link when ready.
- **Deferred, code-split overlays.** `ReceiveGoodsSheet`, the AI reasoning `Sheet`, and the
  Send/Cancel/Force-close `Dialog`s are dynamically imported (`next/dynamic`) rather than bundled into the
  Builder's or List's initial route chunk, since none is needed for first paint.
- **SSR-seeded first paint.** Both the List's first page and a Detail route's initial fetch happen
  server-side and hydrate straight into the TanStack Query cache, so the client's first `useQuery` for the
  same keys resolves instantly rather than re-issuing a request the server already made.
- **`PurchaseOrderLineItems` does not virtualize.** Unlike Journal Entries' line grid, this screen's line
  count is bounded by realistic vendor-delivery sizes; adding virtualization here would be complexity with
  no measured benefit, consistent with `# Responsive Behavior`'s note on this exact point.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| Multi-currency exchange rate unavailable for the PO's currency/date | The currency field surfaces `FX_RATE_UNAVAILABLE` inline; Save Draft still succeeds, but Submit/Send are blocked with a reason, and a Finance-permissioned user sees an additional "override with a manual rate" affordance others do not — the identical pattern `docs/frontend/JOURNAL_ENTRIES.md → Edge Cases` documents for its own currency field |
| Two users edit the same `draft` PO concurrently | `409` toast with "reload and retry"; the second writer's in-progress local edits are not silently discarded — they remain visible until the user chooses to reload |
| Vendor delivers more than ordered, within tolerance | Accepted per BR-PO-5/BR-GR-1; the line's `quantity_received` simply reflects the accepted excess and `line_status` still resolves to `fully_received` |
| Vendor delivers more than ordered, beyond tolerance | `ReceiveGoodsSheet`'s submission is rejected with a `409` naming the exact tolerance and excess; the clerk must either reduce the entered quantity to the tolerance ceiling or ask a Purchasing Manager to amend the PO first |
| Vendor never delivers the remainder of a partially received line | The line stays `partially_received` indefinitely; "Force close" (BR-PO-7-adjacent line-level close, `purchasing.order.force_close`) lets a Purchasing Manager formally close the line short with a captured reason, excluding the undelivered balance from the Outstanding Orders report going forward without pretending it was received |
| A Bill arrives before any Goods Receipt exists for the PO | The Bills tab still lists it (`pending_match`), with a note that it cannot progress past matching until a corresponding GR exists — this screen does not block that visibility, since Finance may still want to see the vendor's claim for cash-flow-forecast purposes even before receiving is complete |
| A Goods Receipt exists but the vendor never bills | The GR/IR clearing balance this creates is not visible as a distinct figure on this screen (it is a General Ledger concern); the Goods Receipts tab simply shows the GR as `posted` with no linked Bill, and a company-level GR/IR aging view (a Reports-surface concern, out of scope here) is where an aging clearing balance is actually surfaced |
| A PO is cancelled after partial receipt | Only the un-received line balance is cancelled; quantity already received, inspected, or billed is entirely unaffected and continues its normal lifecycle — the Cancel dialog's copy states this explicitly so a Purchasing Manager does not mistake Cancel for an undo of physical receiving |
| A PO amendment is requested while a Goods Receipt is already in progress | BR-PO-3 already restricts amendment eligibility structurally — the Detail page's "Amend" action is gated on the PO's own status, not on receiving progress, so a `partially_received` PO can still be amended for its *remaining*, un-received lines; the Builder's amend mode pre-fills only the still-open quantity per line, never re-opening a quantity already physically received |
| A price override is entered by a user without `purchasing.order.override_price` | Rejected client-side before the request is sent, with the specific permission named in the inline reason (`# Accessibility`); the server independently re-checks and would reject it too, so this is purely a fast-fail courtesy, not the actual control |
| A discontinued product (`products.status = 'inactive'`) is referenced on an older PO | The PO's own Items tab renders it exactly as ordered — historical accuracy is never altered by a later product status change; only `ProductPicker`'s live search excludes the now-inactive product going forward for *new* lines |
| Company switch mid-Builder | Per the platform's company-switch contract, switching discards all cached Purchase Orders state; if the Builder has unsaved changes, the switcher's own confirmation dialog names the in-progress draft specifically before proceeding |
| Browser back/forward or a hard navigation away from a dirty Builder | A `beforeunload`/Next.js navigation guard prompts to discard, matching Cancel's own confirm-if-dirty behavior |
| Session permission revoked mid-view | The next mutation attempt's `403` collapses the affected control to its disabled-with-tooltip form and shows a toast explaining why, rather than a stuck spinner or a silent no-op |
| Realtime push arrives while a user is actively scrolling or reading the List | Held aside behind a dismissible "New activity — Refresh" banner rather than spliced into the visible rows, per the platform-wide non-disruptive-realtime-list pattern |
| Reconnecting after an extended disconnected period | Every realtime-fed query key for this screen is invalidated once on reconnect (WebSocket frames broadcast while disconnected are permanently missed), so a stale cache is never silently trusted as current |
| A batch/serial-tracked product's `ReceiveGoodsSheet` line is missing its required identifiers | Blocked client-side with a field-specific `422`-style inline error before submission, and re-validated server-side regardless (BR-GR-2) |
| A vendor's bank account changes while this PO's Bills are mid-payment-cycle | Not surfaced on this screen at all — that protection and its hold mechanism live entirely on the Vendor Payments surface (`docs/accounting/PURCHASING.md § Security`); this screen's scope ends at the PO/Bill relationship, not the payment itself |
| Printing directly via the browser rather than Export | Not specially handled — users are steered toward the Export menu instead, the only path that produces a durable, re-downloadable, correctly light-themed record, per `DARK_MODE.md`'s exported-PDFs-always-render-light rule |

# End of Document
