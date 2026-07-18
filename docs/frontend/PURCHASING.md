# Purchasing — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PURCHASING
---

# Purpose

The Purchasing screen is the frontend home for QAYD's procure-to-pay (P2P) cycle: the hub a Purchasing
Manager, Purchasing Employee, Finance Manager, Warehouse Employee, CFO, or Owner opens to see, in one
place, everything the company has asked to buy, committed to buy, received, been billed for, and paid —
and the launch point into the six working queues that carry a purchase through that lifecycle. It is the
UI half of the module specified in `docs/accounting/PURCHASING.md`, which owns every table, business
rule, lifecycle state, and endpoint this screen renders and never re-derives; this document owns how a
human sees that lifecycle, moves between its six document types, and gets from a spend number to the
approval, the match exception, or the AI flag sitting behind it. A shorter, earlier pass over the same
domain exists at `docs/accounting/PURCHASES.md` (five documents, no Purchase Requests/RFQs/Goods
Receipts/Quality Inspection/Procurement Contracts); `docs/accounting/PURCHASING.md` supersedes it, and
this document follows the newer, fuller module throughout — its table names, lifecycle states, and
permission keys, not the earlier draft's.

Three properties, inherited directly from the backend module's own founding principles, drive every
decision below:

1. **Three-way match is the spine of this screen, not a footnote.** Per the backend document's
   Purchasing Philosophy, "three-way match is the default, not an exception path" — no Bill clears for
   payment unless its quantities and amounts reconcile against the Goods Receipt it claims to bill, and
   the Goods Receipt in turn reconciles against the Purchase Order it was received against. This screen
   gives that discipline a permanent, always-visible surface (`# Layout & Regions` → Three-Way Match
   Status) rather than making a user go looking for it inside an individual Bill.
2. **AI shortens cycles; it never owns a decision.** Every AI-sourced element on this screen — a
   duplicate-bill flag, a price-comparison anomaly, a vendor-recommendation shortlist — carries its
   confidence, its reasoning, and the exact documents it reasoned over, and every one of them ends in a
   human action, never an autonomous write. `purchasing.payment.release` and `purchasing.payment.hold`
   are two of the only permission rows in the entire platform marked "never" grantable to the AI service
   account (`docs/accounting/PURCHASING.md → Permissions`), and this screen enforces that structurally,
   not just cosmetically.
3. **The hub computes nothing.** Every KPI, every match-status count, every aging figure on this screen
   is a value Laravel already validated, aggregated, and — for the expensive rollups — Redis-cached. The
   screen's own logic is limited to fetching, caching, filtering by period/branch/currency, and routing a
   click to the right tab, record, or approval.

This document assumes the reader has `docs/frontend/FRONTEND_ARCHITECTURE.md` (App Router structure,
data layer, cache tuning, streaming), `docs/frontend/COMPONENT_LIBRARY.md` (every named component
below), `docs/frontend/LAYOUT_SYSTEM.md` (the five page templates this screen composes), `docs/frontend/
RESPONSIVE_DESIGN.md` (the five-tier breakpoint system and the finance-table reflow rules), `docs/
frontend/ACCESSIBILITY.md`, `docs/frontend/DARK_MODE.md`, `docs/frontend/NAVIGATION_SYSTEM.md` (the
sidebar/nav tree), `docs/frontend/AI_COMMAND_CENTER.md` (the `AIProposalPanel`/`ApprovalCard` contract
this screen's condensed panels reuse verbatim), and `docs/accounting/PURCHASING.md` (the backend module)
open alongside it. Nothing here introduces a new table, a new business rule, or a new API response shape
those documents do not already establish or directly extend.

**Scope.** This document specifies the Purchasing **hub/landing** screen at `/purchasing` in full depth —
its KPI strip, its Pending Approvals surface, its Three-Way Match Status panel, and its AI Purchasing
Insights rail — and gives each of the six tabs it fronts (Purchase Requests, RFQs, Purchase Orders, Goods
Receipts, Bills, Vendor Payments) enough concrete detail (columns, primary states, key actions, API
resource) to implement their own `List Page Template` instances consistently with this hub and with each
other. It does not re-specify each tab's own Detail or Form pages line item by line item — a Purchase
Order's own multi-step creation form, a Bill's own three-way-match detail view, a Vendor Payment's own
allocation grid — each of which is substantial enough to be its own screen document in the same series as
`GENERAL_LEDGER.md` or `BANKING.md`, and is referenced here only by its route, its permission, and its
relationship to the hub. Vendors (`/purchasing/vendors`) remains exactly where `NAVIGATION_SYSTEM.md`
already places it — owned by Accounting/AP (`accounting.vendor.read`), grouped under the Purchasing
sidebar section for IA convenience — but is not one of this hub's six tabs and is out of this document's
scope, per the task that produced it.

# Route & Access

## App Router path

```
app/(app)/purchasing/
├── layout.tsx                       # Sub-nav: Overview | Requests | RFQs | Orders | Receipts | Bills | Payments (purchasing.read gate)
├── page.tsx                         # ★ THIS DOCUMENT — Purchasing Overview (the hub/landing)
├── requests/
│   ├── page.tsx                     # Purchase Requests — List Page Template
│   ├── new/page.tsx                 # Form Page Template
│   └── [id]/page.tsx                # Detail Page Template (own screen document)
├── rfqs/
│   ├── page.tsx                     # RFQs — List Page Template
│   └── [id]/page.tsx                # Comparison grid + award action (own screen document)
├── purchase-orders/
│   ├── page.tsx                     # Purchase Orders — List Page Template
│   ├── new/page.tsx                 # Form Page Template
│   └── [id]/page.tsx                # Detail Page Template, incl. amendments
├── goods-receipts/
│   ├── page.tsx                     # Goods Receipts — List Page Template
│   ├── new/page.tsx                 # Form Page Template (post against a PO; Quality Inspection sub-flow)
│   └── [id]/page.tsx                # Detail Page Template
├── bills/
│   ├── page.tsx                     # Bills — List Page Template
│   ├── new/page.tsx                 # Form Page Template (manual or OCR-assisted)
│   └── [id]/page.tsx                # Detail Page Template — three-way match detail, override, approve
└── vendor-payments/
    ├── page.tsx                     # Vendor Payments — List Page Template
    ├── new/page.tsx                 # Form Page Template — allocation grid, always full-page (sensitive)
    └── [id]/page.tsx                # Detail Page Template — approval/release, maker≠checker
```

`page.tsx` at the module root is this document's own subject and is a deliberate, narrow departure from
the pattern `docs/frontend/BANKING.md` sets for its own module (Banking has no bare `banking/page.tsx`;
its sidebar entry points straight at `accounts/page.tsx`). Purchasing differs because it fronts six
structurally distinct document types with no single dominant entity the way Banking's `bank_accounts`
dominates that module — a purchasing user's first question is "what across all six queues needs me right
now," not "show me one list." A hub landing page answers that question once, cheaply, before the user
picks a queue; Banking's own choice not to have one is equally deliberate for its own, different shape,
and neither pattern is right for the other module.

**Reconciling two stale documents.** `docs/frontend/NAVIGATION_SYSTEM.md`'s Purchasing sub-navigation
table and `docs/frontend/FRONTEND_ARCHITECTURE.md`'s own App Router tree comment both predate the full
procure-to-pay module this document targets. `NAVIGATION_SYSTEM.md` lists only Vendors, Purchase Orders,
Bills, and Vendor Payments as sub-items, gates Purchase Orders' approval action on a `purchasing.bill.
approve`-family key, and gates Vendor Payments' disbursement on `bank.transfer`;
`FRONTEND_ARCHITECTURE.md`'s route-tree comment lists only `vendors/`, `purchase-orders/`, and `bills/`
under `purchasing/`. Neither yet reflects Purchase Requests, RFQs, Goods Receipts, Quality Inspection, or
the module's own granular permission families (`purchasing.request.*`, `purchasing.rfq.*`, `purchasing.
order.*`, `warehouse.receiving.*`, `purchasing.bill.*`, `purchasing.payment.*`) that `docs/accounting/
PURCHASING.md` establishes. This document is the current, authoritative statement of what renders under
`app/(app)/purchasing/`: Purchase Orders approve via `purchasing.order.approve`, not a Bill-family key;
Vendor Payments approve and release via `purchasing.payment.approve`/`purchasing.payment.release`
(maker≠checker enforced server-side), not `bank.transfer` — `bank.transfer` remains Banking's own
permission for a bank-to-bank transfer and was never the correct gate for disbursing a vendor payment.
`NAVIGATION_SYSTEM.md` and `FRONTEND_ARCHITECTURE.md`'s route-tree comments should be read as stale on
this specific module and reconciled to match; nothing else in either document changes.

## Permission gate

| Gate | Permission | Effect if absent |
|---|---|---|
| Module visible at all (Sidebar entry, hub route) | `purchasing.read` | Sidebar's Purchasing entry and every route under `/purchasing/*` do not render; a direct hit renders the shell-level `error.tsx`, never a silent redirect (`NAVIGATION_SYSTEM.md → Permission-Aware Nav`). |
| Purchase Requests tab + list | `purchasing.request.read` | Sub-nav tab omitted; hub's Open Purchase Requests KPI tile omitted, not shown empty. |
| RFQs tab + list | `purchasing.rfq.read` | Sub-nav tab omitted. |
| Purchase Orders tab + list | `purchasing.order.read` | Sub-nav tab omitted; hub's Open Purchase Orders KPI tile omitted. |
| Goods Receipts tab + list | `warehouse.receiving.read` | Sub-nav tab omitted — note the cross-module key: Goods Receipts sit at the Purchasing/Warehouse boundary and are gated by Warehouse's own read permission, exactly as `docs/accounting/PURCHASING.md`'s endpoint table specifies, not a `purchasing.*` key. |
| Bills tab + list, Three-Way Match Status panel | `purchasing.bill.read` | Sub-nav tab omitted; Three-Way Match Status panel omitted entirely (its existence implies bill data the caller cannot see); Bills Due This Week KPI tile omitted. |
| Vendor Payments tab + list | `purchasing.payment.read` | Sub-nav tab omitted; Pending Vendor Payments KPI tile omitted. |
| Create a Purchase Request | `purchasing.request.create` | "New Purchase Request" quick action omitted. |
| Approve a Purchase Request | `purchasing.request.approve` | Row/card action omitted; a `Pending Approvals` card addressed to another approver still renders (read-only) so the queue is never a mystery. |
| Create/issue an RFQ | `purchasing.rfq.create` / `purchasing.rfq.issue` | Actions omitted. |
| Award an RFQ | `purchasing.rfq.award` | Action omitted. |
| Create a Purchase Order | `purchasing.order.create` | "New Purchase Order" quick action omitted. |
| Approve / send a Purchase Order | `purchasing.order.approve` / `purchasing.order.send` | Actions omitted. |
| Post / reverse a Goods Receipt | `warehouse.receiving.create` / `warehouse.receiving.reverse` | Actions omitted. |
| Approve a Quality Inspection deviation | `purchasing.inspection.approve_deviation` | Action omitted (reachable from a Goods Receipt's own detail, not this hub). |
| Create / override-variance / approve / post a Bill | `purchasing.bill.create` / `.override_variance` / `.approve` / `.post` | Actions omitted; a variance flagged for someone else's review still renders read-only in the Three-Way Match Status panel. |
| Create / approve / release a Vendor Payment | `purchasing.payment.create` / `.approve` / `.release` | Actions omitted. `purchasing.payment.release` additionally enforces maker≠checker server-side above the two-person threshold — see `# Edge Cases`. |
| Place or lift a payment hold | `purchasing.payment.hold` | Action omitted. Never held by the AI service account under any role configuration — a Fraud Detection alert can request a hold; only a human with this key can impose one. |
| Export any Purchasing report | `reports.export` | Export menu item omitted per report. |

## Roles

| Role | What renders on this hub |
|---|---|
| Owner, CEO, CFO | Full hub: every KPI tile, the full Pending Approvals queue (their own tier), full Three-Way Match Status, full AI Insights rail, every quick action their own approval tier allows. |
| Purchasing Manager | Full hub except Bill/Payment approve-and-post/release actions (Finance-only past the match stage); sees Three-Way Match Status and the AI Insights rail in full, since sourcing, RFQ, and PO decisions are this role's core job. |
| Purchasing Employee | Sees their own department's Purchase Requests and RFQs they created or were invited to work; Purchase Order creation only below their auto-approval threshold; no Bill or Vendor Payment actions; no Three-Way Match Status panel unless `purchasing.bill.read` is separately granted. |
| Finance Manager | Sees Bills and Vendor Payments in full (create, override-variance, approve, and — subject to maker≠checker — release), plus the Three-Way Match Status panel as a primary surface; Purchase Requests/RFQs/Purchase Orders render read-only for approval-tier visibility, per `docs/accounting/PURCHASING.md`'s tiered approval chains, which route larger POs through Finance Manager and CFO steps alongside Purchasing Manager. |
| Warehouse Employee | Sees only the Goods Receipts tab and its own KPI tile; Quality Inspection actions where granted; every other tab and KPI tile is omitted, and the hub renders a narrow, mostly-empty-but-not-broken page rather than a permission wall. |
| Auditor / External Auditor | Full read visibility across every tab, the Three-Way Match Status panel, and the AI Insights rail (read-only); zero mutating controls anywhere on the hub, matching the platform's hard, non-configurable constraint that the Auditor role can never hold a create/approve/release permission in this module. |
| AI service account | Never renders this screen as a "user." Its scoped, read-mostly access feeds the AI Insights rail and the draft-only capabilities described in `# AI Integration`; it is structurally incapable of holding `purchasing.payment.release` or `purchasing.payment.hold` regardless of role configuration. |

# Layout & Regions

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Overview  Requests  RFQs  Orders  Receipts  Bills  Payments              [sub-nav tabs]   │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ Purchasing                                    [ This fiscal period ▾ ] [Branch ▾] [KWD ▾] │  Filter bar
│ Procure-to-pay overview                                    [+ New PR] [+ New PO] [+ Bill] │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ [Open PRs: 14, KD 6,204] [Open POs: 37, KD 84,210] [Bills due: 9, KD 21,340] [YTD vs budget: 71%]│ KPI Strip
├──────────────────────────────────────────────┬─────────────────────────────────────────────┤
│  Pending Approvals (you)                      │  AI · Purchasing Insights                    │
│  ┌ PO-2026-004417 — Gulf Paper Trading ─────┐ │  ⚠ Possible duplicate — BILL-2026-000789      │
│  │ KD 1,388.360 · step 2 of 3     [Approve] │ │    matches BILL-2026-000317, 92% confidence   │
│  └───────────────────────────────────────────┘ │  ▸ Price outlier on RFQ-2026-0091 comparison  │
│  ┌ VP-2026-000902 — release pending ────────┐ │    vendor "FastTrade" 2.3σ below the mean      │
│  │ KD 1,240.000 · maker≠checker      [⋯]    │ │  ▸ Vendor shortlist ready — RFQ-2026-0102       │
│  └───────────────────────────────────────────┘ │    4 vendors ranked, 88% confidence  [Review]  │
│  [ View all in Approval Center → ]             │  [ View all in AI Command Center → ]           │
├──────────────────────────────────────────────┴─────────────────────────────────────────────┤
│  Three-Way Match Status                                                                     │
│  Not yet matched  ██████░░░░  12    Matched (auto)  ████████████ 58    Variance flagged  ███ 5│
│  [ Review flagged bills → ]                                                                  │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│  Recent activity                                          │  Outstanding by tab             │
│  ● GR-2026-000512 posted — 2h ago                          │  Requests    3 pending          │
│  ● BILL-2026-000789 flagged possible duplicate — 3h ago     │  RFQs        1 awaiting quotes  │
│  ● PO-2026-004417 sent to Gulf Paper Trading — yesterday    │  Orders      37 open            │
│  [ View all activity → ]                                    │  Receipts    2 pending inspection│
│                                                              │  Bills       5 variance flagged │
│                                                              │  Payments    3 pending release  │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Grid position | Content | Notes |
|---|---|---|---|
| **Sub-nav tabs** | Full width, below the module `layout.tsx` | Overview · Requests · RFQs · Orders · Receipts · Bills · Payments | Each a real route (`# Route & Access`), not client-only tab state — a bookmarked or shared link to any tab lands directly on it. Gated per-tab per the permission table above; a role with zero visible tabs sees no Purchasing entry in the Sidebar at all (`NAVIGATION_SYSTEM.md`'s "hide a parent whose every child is hidden" rule). |
| **Filter bar** | Full width, sticky below the sub-nav | `PeriodPicker` (`mode="relative"`, default `this_fiscal_period`), `BranchSelect`, `CurrencySelect` (reporting currency override, default company base) | Mirrors into `?range=&branch=&currency=` and is read as the *default* filter by every tab this hub links into — clicking "37 open" under Orders carries the same period/branch scope forward as an initial filter, though each tab's own `DataTable` remains independently adjustable from there. |
| **Page header** | Full width | Title, one-line subtitle, up to three permission-filtered Quick Actions (`+ New Purchase Request`, `+ New Purchase Order`, `+ Enter Bill`) | Never more than three buttons inline; a fourth candidate (e.g., `+ New RFQ`) lives in an overflow `DropdownMenu`, per the platform's Quick Actions row cap. |
| **KPI Strip** | `col-span-12`, one row | Four `KpiTile`s: Open Purchase Requests, Open Purchase Orders (committed spend), Bills Due This Week, YTD Spend vs. Budget | Sourced from the `Purchase Dashboard` report (`docs/accounting/PURCHASING.md → Reports`); horizontal-scroll carousel below `md:`, per `RESPONSIVE_DESIGN.md`'s Dashboard reflow table. |
| **Pending Approvals** | `col-span-7` at `lg:`+ | Up to 5 `ApprovalCard`s scoped to `subject_type IN (purchase_request, purchase_order, bill, vendor_payment)` and addressed to the caller's own pending step, grouped by `sla_due_at` urgency | Identical contract to the AI Command Center's own Approval Center queue (`docs/frontend/AI_COMMAND_CENTER.md`), filtered to this module; "View all in Approval Center" opens `/approvals?subject_type[in]=purchase_request,purchase_order,bill,vendor_payment`. |
| **AI Purchasing Insights** | `col-span-5` at `lg:`+ | Up to 3 condensed `AIProposalPanel`/insight cards: duplicate-bill/fraud flags, price-comparison anomalies, vendor-recommendation shortlists | The only AI-forward region on this screen besides the individual cards embedded in the Bills/RFQs tabs themselves — see `# AI Integration`. |
| **Three-Way Match Status** | `col-span-12` | A single `ThreeWayMatchStatusCard`: three segmented counts (Not yet matched, Matched, Variance flagged) plus a smaller Override-approved count, each segment clickable through to the Bills tab pre-filtered by `match_status` | This is the hub's own dedicated rendering of `bills.match_status`; it is not a KPI tile because a match-status count is a control surface (something to act on), not a trend figure. |
| **Recent activity** | `col-span-7` (or full width below `lg:`) | A capped, reverse-chronological feed of the last ~10 posted/sent/flagged/approved events across all six document types | Same pattern and same Redis-backed activity feed convention as `docs/frontend/DASHBOARD.md`'s own Recent Activity region, scoped to Purchasing's own domain events. |
| **Outstanding by tab** | `col-span-5` (or full width below `lg:`) | A compact six-row list — one row per tab — naming the single most actionable open count for that queue (pending PRs, RFQs awaiting quotes, open POs, receipts pending inspection, bills flagged, payments pending release) | Each row is a direct link into its tab, pre-filtered to that exact condition; this is the hub's explicit answer to "which of the six queues needs me right now," restated as a compact list beside the fuller activity feed. |

Each of the six tabs this hub fronts is a `List Page Template` instance (`docs/frontend/LAYOUT_SYSTEM.md
→ Page Templates`) with the region shape that template already defines (Page Header, Filter Bar, Bulk
Action Bar, Data Region, Pagination Footer) — this document does not reinvent that shape per tab. What
follows is each tab's own concrete filling of that template, sufficient to implement its `DataTable`
columns and default state without yet being that tab's own full screen document:

| Tab | Primary columns | Default sort | Primary row action | Bulk action |
|---|---|---|---|---|
| **Purchase Requests** (`/purchasing/requests`) | Request # · Requester · Department · Priority · Estimated total · Status · Required by | `-request_date` | Approve / Reject (at caller's step) | — |
| **RFQs** (`/purchasing/rfqs`) | RFQ # · Title · Invited vendors · Response deadline · Responses received · Status | `-issue_date` | Open comparison grid | — |
| **Purchase Orders** (`/purchasing/purchase-orders`) | PO # · Vendor · Order date · Expected delivery · Total (base) · Received % · Status | `-order_date` | Approve / Send / Amend | Bulk-receive (`purchase-order-items/bulk-receive`) |
| **Goods Receipts** (`/purchasing/goods-receipts`) | Receipt # · PO # · Vendor · Warehouse · Receipt date · Inspection status · Status | `-receipt_date` | Post / Reverse / Open inspection | — |
| **Bills** (`/purchasing/bills`) | Bill # · Vendor invoice ref · Vendor · Due date · Total (base) · Match status · Status | `-bill_date` | Match / Override variance / Approve / Post | Bulk-approve (matched only) |
| **Vendor Payments** (`/purchasing/vendor-payments`) | Payment # · Vendor · Payment date · Method · Total (base) · Status | `-payment_date` | Approve / Release | Bulk-allocate (statement run) |

# Components Used

Every visual element on this hub is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue, composed into
a small set of Purchasing-scoped components living in `components/purchasing/`, per `PROJECT_STRUCTURE.
md`'s module-folder convention. No primitive is hand-rolled.

| Region | Component(s) | Notes |
|---|---|---|
| Sub-nav tabs | `PurchasingSubNav` (`components/purchasing/purchasing-sub-nav.tsx`, **new**) | A thin, permission-filtered wrapper around plain `<Link>`s styled as the platform's underline-indicator tab bar (`Tabs`'s visual language reused for route-level navigation, not the `Tabs` component itself — these are six distinct pages, not one page's client-side sections). |
| Filter bar | `PeriodPicker`, `BranchSelect`, `CurrencySelect` | Identical composition and URL-mirroring convention to `docs/frontend/DASHBOARD.md`'s own `DashboardFilterBar`, reused here as `PurchasingFilterBar` (`components/purchasing/purchasing-filter-bar.tsx`, **new**). |
| Page header | `PageHeader`, `Button`, `DropdownMenu`, `Can` | `Can permission="purchasing.request.create"` etc. wraps each Quick Action independently. |
| KPI Strip | `KpiTile` ×4 | `Open Purchase Requests` and `Open Purchase Orders` render a plain count + `AmountCell`-formatted value (deterministic SQL aggregates, no `confidence` prop); `Bills Due This Week` renders an amber delta when the overdue sub-count is non-zero; `YTD Spend vs. Budget` renders as a percentage with a `TrendSparkline`. |
| Pending Approvals | `ApprovalCard` ×N, `Stepper` (inside a card, for multi-step chains) | Exactly the contract `docs/frontend/AI_COMMAND_CENTER.md` documents in full for its own Approval Center — this region is a filtered instance of the same queue, not a re-implementation. |
| AI Purchasing Insights | `AiInsightsRail` → `PurchasingAiInsightsRail` (`components/purchasing/purchasing-ai-insights-rail.tsx`, **new**), composing `AIProposalPanel` (`compact` presentation) and `ConfidenceBadge` | See `# AI Integration` for the three card kinds this rail renders. |
| Three-Way Match Status | `ThreeWayMatchStatusCard` (`components/purchasing/three-way-match-status-card.tsx`, **new**) | A segmented bar built from four `AmountCell`/count pairs, each an accessible `<button>` (`# Accessibility`) that navigates to `/purchasing/bills?filter[match_status][in]=...`. |
| Recent activity | `RecentActivityFeed` (existing pattern from `docs/frontend/DASHBOARD.md`, reused as `PurchasingActivityFeed`) | A lightweight, non-virtualized, capped list — never a full `DataTable`. |
| Outstanding by tab | `Card`, `Badge`, plain `<Link>`s | A compact composed list, `components/purchasing/outstanding-by-tab.tsx` (**new**), no separate query of its own — it reads the same six counts the KPI Strip and Three-Way Match Status card already fetched. |
| Each tab's `DataTable` | `DataTable`, `StatusPill`, `AmountCell`, `CurrencyTag`, `PermissionGate`/`Can` | One `DataTable` instantiation per tab, each with its own `columns`, `resource`, and `emptyState`, per `# Data & State`. |
| Loading | `Skeleton`, shaped to each region's exact geometry | Never a generic spinner, per the platform's "never blank" rule. |
| Empty / error | `EmptyState`, `ErrorState` | Distinct per region — see `# States`. |

`StatusPill`'s existing `domain` union (`docs/frontend/COMPONENT_LIBRARY.md`) already includes `'bill'`;
this document is the first to specify its own `STATUS_TABLES` entry for that domain, and extends the
union with the five remaining document types this module owns:

```tsx
// components/shared/status-pill.tsx — extended by this document
type PurchasingDomain =
  | 'purchase_request' | 'rfq' | 'purchase_order' | 'goods_receipt' | 'bill' | 'vendor_payment';

const PURCHASE_REQUEST_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:            { label: 'Draft', tone: 'neutral' },
  pending_approval:  { label: 'Pending approval', tone: 'warning' },
  approved:          { label: 'Approved', tone: 'accent' },
  rejected:          { label: 'Rejected', tone: 'danger' },
  sourcing:          { label: 'Sourcing', tone: 'accent' },
  fulfilled:         { label: 'Fulfilled', tone: 'success' },
  cancelled:         { label: 'Cancelled', tone: 'neutral' },
};

const RFQ_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:           { label: 'Draft', tone: 'neutral' },
  issued:          { label: 'Issued', tone: 'accent' },
  responses_open:  { label: 'Responses open', tone: 'warning' },
  closed:          { label: 'Closed', tone: 'neutral' },
  awarded:         { label: 'Awarded', tone: 'success' },
  cancelled:       { label: 'Cancelled', tone: 'neutral' },
};

const PURCHASE_ORDER_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:               { label: 'Draft', tone: 'neutral' },
  pending_approval:    { label: 'Pending approval', tone: 'warning' },
  approved:            { label: 'Approved', tone: 'accent' },
  sent:                { label: 'Sent to vendor', tone: 'accent' },
  partially_received:  { label: 'Partially received', tone: 'warning' },
  fully_received:      { label: 'Fully received', tone: 'success' },
  closed:              { label: 'Closed', tone: 'neutral' },
  rejected:            { label: 'Rejected', tone: 'danger' },
  cancelled:           { label: 'Cancelled', tone: 'neutral' },
};

const GOODS_RECEIPT_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:     { label: 'Draft', tone: 'neutral' },
  posted:    { label: 'Posted', tone: 'success' },
  reversed:  { label: 'Reversed', tone: 'neutral' },
};

const BILL_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:             { label: 'Draft', tone: 'neutral' },
  pending_match:     { label: 'Pending match', tone: 'warning' },
  matched:           { label: 'Matched', tone: 'accent' },
  variance_flagged:  { label: 'Variance flagged', tone: 'danger' },
  pending_approval:  { label: 'Pending approval', tone: 'warning' },
  approved:          { label: 'Approved', tone: 'accent' },
  posted:            { label: 'Posted', tone: 'success' },
  paid:              { label: 'Paid', tone: 'success' },
  partially_paid:    { label: 'Partially paid', tone: 'warning' },
  disputed:          { label: 'Disputed', tone: 'danger' },
  cancelled:         { label: 'Cancelled', tone: 'neutral' },
  void:              { label: 'Void', tone: 'neutral' },
};

const VENDOR_PAYMENT_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:             { label: 'Draft', tone: 'neutral' },
  pending_approval:  { label: 'Pending approval', tone: 'warning' },
  approved:          { label: 'Approved — awaiting release', tone: 'accent' },
  processing:        { label: 'Processing', tone: 'warning' },
  completed:         { label: 'Completed', tone: 'success' },
  failed:            { label: 'Failed', tone: 'danger' },
  cancelled:         { label: 'Cancelled', tone: 'neutral' },
};
```

`ThreeWayMatchStatusCard`'s own props mirror `AgingBar`'s shape (`docs/frontend/COMPONENT_LIBRARY.md`)
deliberately, since both are "a labeled, segmented, clickable count bar," not a new interaction pattern:

```tsx
// components/purchasing/three-way-match-status-card.tsx
interface MatchStatusBucket {
  matchStatus: 'not_matched' | 'matched' | 'variance' | 'override_approved';
  label: string;
  count: number;
  baseCurrencyAmount: string;
}

interface ThreeWayMatchStatusCardProps {
  buckets: MatchStatusBucket[];
  currencyCode: string;
  onBucketClick: (matchStatus: MatchStatusBucket['matchStatus']) => void;
  loading?: boolean;
}
```

# Data & State

## Endpoints

Every endpoint below is reused verbatim from `docs/accounting/PURCHASING.md → API` — this document
introduces no new path, no new verb, and no new response shape. All requests carry `Authorization:
Bearer <token>` and `X-Company-Id`; all responses use the standard envelope (`success`, `data`, `message`,
`errors`, `meta.pagination`, `request_id`, `timestamp`).

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Hub bootstrap bundle | `GET /api/v1/purchasing/reports/purchase-dashboard` | `reports.export` read-equivalent + `purchasing.read` | The `Purchase Dashboard` report row from `docs/accounting/PURCHASING.md → Reports`: "open PRs pending approval, open POs by status, bills due this week, YTD spend vs. budget." Redis-cached server-side; `meta.pagination: null`. Seeds the KPI Strip and the Outstanding-by-tab list in one call. |
| Purchase Requests list | `GET /api/v1/purchasing/purchase-requests` | `purchasing.request.read` | `?filter[status]=&filter[requester_id]=&filter[department_id]=&range=` |
| Create / submit / approve / reject a PR | `POST /purchasing/purchase-requests`, `.../{id}/submit`, `.../{id}/approve`, `.../{id}/reject` | `purchasing.request.create` / `.submit` / `.approve` (both approve and reject) | |
| RFQs list | `GET /api/v1/purchasing/rfqs` | `purchasing.rfq.read` | |
| Create / issue an RFQ, record a response, comparison grid, award | `POST /purchasing/rfqs`, `.../{id}/issue`, `.../{id}/responses`, `GET .../{id}/comparison`, `POST .../{id}/award` | `purchasing.rfq.create` / `.issue` / `.respond`\|`.enter_response` / `.read` / `.award` | The comparison grid endpoint returns the AI ranking overlay inline — see `# AI Integration`. |
| Purchase Orders list | `GET /api/v1/purchasing/purchase-orders` | `purchasing.order.read` | `?filter[status]=&filter[vendor_id]=&range=` |
| Create / update / submit / approve / send / amend / cancel a PO | `POST /purchasing/purchase-orders`, `PUT .../{id}`, `.../{id}/submit`, `.../{id}/approve`, `.../{id}/send`, `.../{id}/amend`, `.../{id}/cancel` | `purchasing.order.create` / `.update` / `.submit` / `.approve` / `.send` / `.amend` / `.cancel` | |
| Bulk-receive | `POST /purchasing/purchase-order-items/bulk-receive` | `warehouse.receiving.create` | One call spans multiple PO lines in a single Goods Receipt. |
| Goods Receipts list | `GET /api/v1/purchasing/goods-receipts` | `warehouse.receiving.read` | |
| Create/post, retrieve, reverse a GR | `POST /purchasing/goods-receipts`, `GET .../{id}`, `POST .../{id}/reverse` | `warehouse.receiving.create` / `.read` / `.reverse` | |
| Quality Inspection record, approve deviation | `POST /purchasing/quality-inspections`, `POST .../{id}/approve-deviation` | `purchasing.inspection.create` / `.approve_deviation` | Reached from a Goods Receipt's own detail page, not this hub. |
| Bills list | `GET /api/v1/purchasing/bills` | `purchasing.bill.read` | `?filter[status]=&filter[vendor_id]=&filter[match_status][in]=&filter[due_date][lte]=` |
| Enter, match, override-variance, approve, post a Bill | `POST /purchasing/bills`, `POST .../{id}/match`, `POST .../{id}/override-variance`, `POST .../{id}/approve`, `POST .../{id}/post` | `purchasing.bill.create` / `.match` / `.override_variance` / `.approve` / `.post` | |
| Bulk-approve bills | `POST /purchasing/bills/bulk-approve` | `purchasing.bill.approve` | Only `matched` bills accepted; each still individually audit-logged. |
| Vendor Payments list | `GET /api/v1/purchasing/vendor-payments` | `purchasing.payment.read` | Extrapolated, consistent with the `<area>.<entity>.read` pattern every sibling entity in this module already follows (`purchasing.request.read`, `purchasing.order.read`, `purchasing.bill.read`); the backend document's own summary permission row `purchasing.*.read (all read scopes)` covers exactly this case. |
| Create, approve, release a Vendor Payment | `POST /purchasing/vendor-payments`, `POST .../{id}/approve`, `POST .../{id}/release` | `purchasing.payment.create` / `.approve` / `.release` | Release enforces maker≠checker server-side above the two-person threshold (`# Edge Cases`). |
| Bulk-allocate a payment across bills | `POST /purchasing/vendor-payments/bulk-allocate` | `purchasing.payment.create` | Vendor statement-run payment. |
| Three-Way Match Status counts | `GET /api/v1/purchasing/bills?filter[match_status][in]=not_matched,matched,variance,override_approved&group_by=match_status` (or a dedicated `GET /purchasing/reports/three-way-match-status` where the company's report registry defines one) | `purchasing.bill.read` | Bounded, four-row aggregate; never itself paginated. |
| Pending Approvals (scoped) | `GET /api/v1/approvals?subject_type[in]=purchase_request,purchase_order,bill,vendor_payment&assigned_to=me` | `ai.approve` to act, `reports.read` to view | Same `ai_approval_requests` rows and `ApprovalCard` contract as the AI Command Center's own Approval Center, filtered to this module (`docs/frontend/AI_COMMAND_CENTER.md`). |
| AI recommendations (condensed) | `GET /api/v1/ai/recommendations?module=purchasing&limit=3` | `reports.read` (+ the recommendation's own action permission to execute) | Vendor Recommendation shortlists, Purchase Optimization notes. |
| AI risks / flags (condensed) | `GET /api/v1/ai/risks?module=purchasing&limit=3&sort=-severity` | `reports.read` | Duplicate Bill Detection and Fraud Detection flags. |
| Recent activity | Pre-aggregated inside the bootstrap bundle above; "View all activity" links to each tab's own list filtered `?sort=-created_at` | `purchasing.read` (per source document's own read permission for the deep link to resolve) | |

## Query keys, hooks

```ts
// lib/api/query-keys.ts — extends the exact factory FRONTEND_ARCHITECTURE.md already declares
export const purchasingKeys = {
  all: ['purchasing'] as const,
  dashboard: (filters: PurchasingFilters) => [...purchasingKeys.all, 'dashboard', filters] as const,
  matchStatus: (filters: PurchasingFilters) => [...purchasingKeys.all, 'match-status', filters] as const,
  requests: (filters: RequestFilters) => [...purchasingKeys.all, 'requests', filters] as const,
  rfqs: (filters: RfqFilters) => [...purchasingKeys.all, 'rfqs', filters] as const,
  orders: (filters: OrderFilters) => [...purchasingKeys.all, 'orders', filters] as const,
  receipts: (filters: ReceiptFilters) => [...purchasingKeys.all, 'receipts', filters] as const,
  bills: (filters: BillFilters) => [...purchasingKeys.all, 'bills', filters] as const,
  payments: (filters: PaymentFilters) => [...purchasingKeys.all, 'payments', filters] as const,
  recentActivity: () => [...purchasingKeys.all, 'recent-activity'] as const,
};

export const purchasingAiKeys = {
  recommendations: (limit: number) => ['ai', 'recommendations', { module: 'purchasing', limit }] as const,
  risks: (limit: number) => ['ai', 'risks', { module: 'purchasing', limit }] as const,
};
```

```ts
// lib/api/hooks/use-purchasing-dashboard.ts
export function usePurchasingDashboard(filters: PurchasingFilters) {
  return useQuery({
    queryKey: purchasingKeys.dashboard(filters),
    queryFn: () => api.get<PurchaseDashboard>('/purchasing/reports/purchase-dashboard', { params: filters }),
    staleTime: 0, // Live/derived figures — FRONTEND_ARCHITECTURE.md data-class table
  });
}

export function useThreeWayMatchStatus(filters: PurchasingFilters) {
  return useQuery({
    queryKey: purchasingKeys.matchStatus(filters),
    queryFn: () =>
      api.get<MatchStatusBucket[]>('/purchasing/bills', {
        params: { ...toFilterParams(filters), group_by: 'match_status' },
      }),
    staleTime: 0,
  });
}

export function usePendingApprovals() {
  return useQuery({
    queryKey: ['approvals', { subjectTypes: ['purchase_request', 'purchase_order', 'bill', 'vendor_payment'], assignedTo: 'me' }],
    queryFn: () =>
      api.get<ApprovalRequest[]>('/approvals', {
        params: { 'subject_type[in]': 'purchase_request,purchase_order,bill,vendor_payment', assigned_to: 'me' },
      }),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
}
```

`PurchasingFilters` is `{ branchId: number | null; range: RelativeRange; currencyCode: string }`, produced
once by `PurchasingFilterBar` and threaded down as props, exactly mirroring `DashboardFilters`'s own shape
and reasoning in `docs/frontend/DASHBOARD.md` — no two widgets on this hub independently re-derive the
active filter from the URL.

## Cache tuning

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Live/derived figures | `dashboard` bundle, `matchStatus` | `0` (always stale) | Correctness over avoiding a refetch; kept fresh primarily via Realtime invalidation. |
| Transactional lists | `requests`, `rfqs`, `orders`, `bills`, `payments`, `receipts` | `30_000` | The exact tier `docs/frontend/FRONTEND_ARCHITECTURE.md`'s cache-tuning table already assigns to `journal-entries, invoices, bills, purchase-orders` — frequent enough writes that a stale list is an annoyance, not worth sub-minute polling. |
| Recent activity | `recentActivity` | `30_000` | Matches Dashboard's own tuning for the identical pattern. |
| AI feeds | `purchasingAiKeys.recommendations`, `.risks` | `10_000`, `refetchOnWindowFocus: true` | Matches `FRONTEND_ARCHITECTURE.md`'s AI-feed row verbatim. |
| Pending Approvals | scoped `approvals` query | `10_000`, `refetchOnWindowFocus: true` | Identical tuning to the AI Command Center's own Approval Center panel. |

## Server-first paint, then Suspense per region

`app/(app)/purchasing/page.tsx` is a Server Component resolving the default filter and streaming each
region behind its own `<Suspense>` boundary, so a slow AI-feed call never blocks the KPI Strip — the same
pattern `docs/frontend/DASHBOARD.md` establishes for its own route:

```tsx
// app/(app)/purchasing/page.tsx
export const dynamic = 'force-dynamic';
export const fetchCache = 'default-no-store'; // tenant-scoped — never statically cached

export default function PurchasingOverviewPage({ searchParams }: { searchParams: RawFilters }) {
  const filters = resolveFilters(searchParams);
  return (
    <div className="space-y-6">
      <PurchasingFilterBar initialFilters={filters} />
      <Suspense fallback={<WidgetSkeleton variant="kpi-strip" />}>
        <PurchasingKpiStrip filters={filters} />
      </Suspense>
      <div className="grid grid-cols-12 gap-6">
        <Suspense fallback={<WidgetSkeleton variant="list" className="col-span-12 lg:col-span-7" />}>
          <PendingApprovalsCard filters={filters} className="col-span-12 lg:col-span-7" />
        </Suspense>
        <Suspense fallback={<WidgetSkeleton variant="rail" className="col-span-12 lg:col-span-5" />}>
          <PurchasingAiInsightsRail filters={filters} className="col-span-12 lg:col-span-5" />
        </Suspense>
      </div>
      <Suspense fallback={<WidgetSkeleton variant="bar" />}>
        <ThreeWayMatchStatusCard filters={filters} />
      </Suspense>
      <div className="grid grid-cols-12 gap-6">
        <Suspense fallback={<WidgetSkeleton variant="feed" className="col-span-12 lg:col-span-7" />}>
          <PurchasingActivityFeed className="col-span-12 lg:col-span-7" />
        </Suspense>
        <OutstandingByTab className="col-span-12 lg:col-span-5" filters={filters} />
      </div>
    </div>
  );
}
```

Each streamed region pairs with its own `<ErrorBoundary>` one level inside the `Suspense` fallback, per
`FRONTEND_ARCHITECTURE.md`'s three-granularity error model — a Fraud Detection timeout on the AI Insights
rail never touches the KPI Strip or the Three-Way Match Status card beside it.

## Realtime

| Channel | Purpose |
|---|---|
| `private-company.{id}.purchasing` | Company-wide domain events for this module: `purchase_request.approved`, `purchase_order.issued`, `purchase_order.sent`, `goods_receipt.posted`, `quality_inspection.completed`, `bill.matched`, `bill.approved`, `bill.posted`, `vendor_payment.released` |
| `private-company.{id}.approvals` | Shared with the AI Command Center's own Approval Center — refreshes the Pending Approvals region the moment a new step lands or an existing one resolves. |
| `private-company.{id}.ai-jobs` | Refreshes the AI Purchasing Insights rail the moment Fraud Detection, the General Accountant Agent, or the Vendor Recommendation agent finishes a run. |

Reverb events purge specific query keys rather than the whole page: `bill.matched`/`bill.approved` purges
`purchasingKeys.matchStatus` and `purchasingKeys.bills`; `purchase_order.issued`/`.sent` purges
`purchasingKeys.orders` and `purchasingKeys.dashboard`; `goods_receipt.posted` purges
`purchasingKeys.receipts`, `purchasingKeys.orders` (received-quantity rollup), and `purchasingKeys.
dashboard`; `vendor_payment.released` purges `purchasingKeys.payments` and `purchasingKeys.dashboard`. A
reconnect after a dropped WebSocket invalidates every one of this hub's realtime-fed query keys once, as
a single batch, mirroring `docs/frontend/AI_COMMAND_CENTER.md`'s own reconnect rule — a stale Three-Way
Match Status count silently trusted after a drop would be a correctness bug, not a cosmetic one.

## AI agents feeding this screen

| Agent | Contribution to this hub | Autonomy |
|---|---|---|
| **Reporting Agent** | Computes the KPI Strip's YTD-vs-budget figure and the underlying Spend Analysis narrative | Auto (pure aggregation) |
| **General Accountant (procurement-tuned)** | Price Comparison anomaly overlay condensed onto this hub from any recently closed RFQ | Suggest-only |
| **General Accountant / Treasury Manager (procurement-tuned)** | Vendor Recommendation shortlists for open RFQs, condensed as an actionable card | Suggest-only — pre-populates `invited_vendor_ids`, never invites without confirmation |
| **Fraud Detection / Auditor** | Duplicate Bill Detection flags and broader Fraud Detection risk alerts | Suggest/alert-only; can *request* a payment hold, never impose one itself |
| **Forecast Agent** | Feeds the Purchase Requests tab's `source = 'ai_forecast'` drafts (visible as a `Badge` on the row, not a hub-level card) | Draft-creation only, capped at `draft` status |
| **CFO / Treasury Manager (advisory)** | Purchase Optimization notes surfaced in the AI Insights rail alongside Vendor Recommendation cards | Fully advisory — never creates or modifies a document |

# Interactions & Flows

**Opening the hub.** First paint renders the shell (Sidebar, Topbar, sub-nav, filter bar) immediately;
the KPI Strip typically resolves within one Redis-cache round trip. Pending Approvals, the AI Insights
rail, the Three-Way Match Status card, Recent Activity, and Outstanding-by-tab each stream in
independently behind their own `Suspense` boundary, replacing their skeleton in place with no layout
shift, exactly as `docs/frontend/DASHBOARD.md` establishes for the company Dashboard.

**Changing the period, branch, or reporting currency.** `PurchasingFilterBar` writes `{ range, branchId,
currencyCode }` to the URL (`router.push`) and every region's query key changes accordingly — the KPI
Strip, the Three-Way Match Status card, and Recent Activity all refetch; the AI Insights rail's
recommendations and risks do **not** refetch on a period change (a duplicate-bill flag is not "for last
month"), only on a branch change where the underlying document set genuinely differs, mirroring
`docs/frontend/DASHBOARD.md`'s identical rule for its own AI Summary Rail. `placeholderData:
keepPreviousData` keeps every affected region showing its previous values, dimmed, rather than flashing
empty.

**Switching tabs.** Each sub-nav item is a real navigation (`<Link>`), not a client-side tab switch; the
hub's own `?range=&branch=&currency=` query parameters are read as the destination tab's *default* filter
seed (so a Finance Manager who has already scoped the hub to "Al Ahmadi branch, this fiscal period" lands
on the Bills tab with that same scope pre-applied), but each tab's own `DataTable` state (search, column
sort, page/cursor) resets fresh on navigation — a filter is a shared starting point across tabs, never a
shared live state that would let scrolling one tab's list silently affect another's.

**Clicking a KPI tile.** Each tile drills into its owning tab, pre-filtered to match: Open Purchase
Requests → `/purchasing/requests?filter[status][in]=pending_approval`; Open Purchase Orders →
`/purchasing/purchase-orders?filter[status][in]=sent,partially_received`; Bills Due This Week →
`/purchasing/bills?filter[due_date][lte]=<end_of_week>&filter[status][nin]=paid,void`; YTD Spend vs.
Budget → the Spend Analysis report (`/purchasing/reports/spend-analysis?range=ytd`). A KPI click never
opens a modal over the hub — it always means "go look at the source."

**Acting on a Pending Approval.** Clicking **Approve** on an `ApprovalCard` calls `POST /api/v1/approvals/
{id}/approve` with a client-generated `Idempotency-Key`, optimistically flips that step in the cache, and
rolls back on any `4xx`/`5xx` — identical to the AI Command Center's own `useApproveRequest` hook.
**Reject** requires a non-empty reason before its own confirm button un-disables. A multi-step chain (a
Purchase Order routed Purchasing Manager → Finance Manager → CFO) renders its `Stepper`; only the step
assigned to the current user's role is interactive, every other step is a disabled, explained preview.
"View all in Approval Center" opens the full `/approvals` queue filtered to this module's four subject
types.

**Reviewing the Three-Way Match Status card.** Clicking any segment (Not yet matched, Matched, Variance
flagged, Override approved) navigates to `/purchasing/bills?filter[match_status][in]=<segment>`, landing
directly on the Bills tab's own `DataTable` pre-filtered — this card is a control surface into that tab,
not a duplicate rendering of it.

**Acting on an AI Purchasing Insight.** A duplicate-bill banner opens the flagged Bill's own detail page
(`/purchasing/bills/{id}`), scrolled to its match-status section; a price-comparison anomaly card opens
the closed RFQ's comparison grid (`/purchasing/rfqs/{id}`) with the outlier line pre-highlighted; a vendor
-recommendation card's **Review** action opens the target RFQ's invite-list draft for confirmation before
issue. Every one of these is a hand-off to the owning tab's own screen, never an inline action completed
on this hub.

**Using a Quick Action.** Each button in the page header navigates to its target's canonical full-page
route (`/purchasing/requests/new`, `/purchasing/purchase-orders/new`, `/purchasing/bills/new`) — never an
inline modal, per `FRONTEND_ARCHITECTURE.md`'s rule that sensitive-adjacent creation flows are always
full, linkable pages.

**Bulk operations.** Bulk-receive (from the Purchase Orders tab, spanning multiple PO lines into one
Goods Receipt), bulk-approve (matched Bills only), and bulk-allocate (one Vendor Payment across many
Bills for a statement run) all live on their owning tabs, gated by the same `Bulk Action Bar` region the
`List Page Template` already defines — this hub never exposes a cross-tab bulk action of its own.

# AI Integration

This hub is one of three places QAYD's Purchasing AI capabilities become visible to a human (the other
two being the individual Bill and RFQ detail pages each capability's own action ultimately opens), and it
is the *first* place a Purchasing Manager, Finance Manager, or CFO sees them, since every card here is
capped to three and stripped of its alternatives-expansion affordance, exactly the same condensation
`docs/frontend/DASHBOARD.md` applies to its own Top Recommended Actions rail. Every AI-sourced element
obeys the platform-wide contract in full: `confidence` (0.00–1.00) and `reasoning` are never hidden, and
nothing here ever auto-commits a financial write.

**Duplicate Bill / Fraud.** *Agent: Fraud Detection / Auditor* (`docs/accounting/PURCHASING.md → AI
Responsibilities → Duplicate Bill Detection`, `Fraud Detection`). A Bill scoring above the duplicate
threshold (default 0.75 confidence) surfaces on this hub as a condensed card: *"Possible duplicate —
BILL-2026-000789 matches BILL-2026-000317, 92% confidence"*, with `reasoning` naming the specific
near-match signal (same vendor, amount within 0.5%, adjacent date, different `vendor_bill_reference`).
Clicking it opens the flagged Bill, held at `pending_match` with the same banner inline; a Finance user
must explicitly confirm "Not a duplicate" (logged) before the Bill can proceed to approval — the agent
never auto-rejects it. Broader Fraud Detection alerts (a vendor's bank account changing shortly before a
large payment, a segregation-of-duties violation, a round-number invoice with no supporting Goods
Receipt, threshold-gaming just under `non_po_bill_max_amount`) render with the same card shape but route
to the Auditor and Finance Manager roles specifically; the highest-severity pattern (bank-account-change-
then-large-payment) can *request* a payment hold, but the hold is only actually placed if a user holding
`purchasing.payment.hold` acts on it — this is one of the platform's few "never" rows for an AI service
account (`# Route & Access`), and this hub enforces it structurally: there is no client code path that
calls `purchasing.payment.hold`'s endpoint from an AI-authored card without that human action in between.

**Price Comparison.** *Agent: General Accountant* (`docs/accounting/PURCHASING.md → AI Responsibilities →
Price Comparison`). The comparison grid itself, computed for every closed RFQ, is deterministic — this
hub's own condensed card surfaces only the AI overlay on top of it: an anomaly note when one vendor's
line price sits more than two standard deviations from that RFQ's response-set mean, in either direction
(flagging both suspiciously cheap and suspiciously expensive quotes). *"Price outlier on RFQ-2026-0091 —
vendor 'FastTrade' 2.3σ below the mean, 81% confidence"* links straight into that RFQ's comparison grid
(`/purchasing/rfqs/{id}`) with the outlier line pre-highlighted, so a Purchasing Manager evaluating an
award decision sees the anomaly in the same view as the numbers it is about.

**Vendor Recommendation.** *Agent: General Accountant / Treasury Manager, procurement-tuned*
(`docs/accounting/PURCHASING.md → AI Responsibilities → Vendor Recommendation`). For an approved Purchase
Request not yet routed to an RFQ, or a new RFQ being drafted, the agent pre-populates a ranked vendor
shortlist — *"Vendor shortlist ready — RFQ-2026-0102, 4 vendors ranked, 88% confidence"* — scored on
historical `on_time_delivery_rate`, `quality_score`, `average_lead_time_days`, and prior `rfq_responses`
for the same product. Clicking **Review** opens the target RFQ's own invite-list draft, pre-filled but
requiring explicit confirmation before issue; the agent never invites a vendor without that confirmation.
A recommendation scoring below 0.55 confidence (typically a new product category with no purchase
history) still renders but is visually de-emphasized with a "low history — verify manually" label rather
than omitted, so a Purchasing Employee is never left guessing why a shortlist looks thin.

**The three-button contract, unaltered.** Where a card's underlying decision is `can_execute_directly`
(the API-computed field), the condensed `AIProposalPanel` on this hub offers exactly the same three
controls the full AI Command Center offers — Do it, Send for approval, Dismiss (reason required) — never
an approximation of them; if `can_execute_directly` is `false`, "Do it" simply does not render, matching
the platform's structural guarantee that a gated action has no client-side path to execute itself from
this screen. Every Purchasing capability in `docs/accounting/PURCHASING.md → AI Responsibilities` is
either "suggest-only," "draft-creation only," or — for the Price Comparison grid's own deterministic
computation — "fully automatic but not a financial write" (the grid itself is math, not a judgment); none
reaches "auto-execute a sensitive action," so `can_execute_directly` is `false` for every card this hub
renders today, and "Do it" is consequently never shown here — only Send for approval and Dismiss. This is
expected, not a bug to fix: Purchasing's most consequential actions (issuing a PO, approving a Bill,
releasing a Payment) are precisely the ones the platform reserves for a human with the matching
permission, per the module's own approval-tier design.

**Unavailable AI.** If the recommendations or risks call fails, times out, or the AI engine is disabled
for the company, the AI Purchasing Insights rail renders a distinct "AI insights are temporarily
unavailable" state — not a generic error, not an infinite spinner — and every other capability on this
hub (KPI Strip, Pending Approvals, Three-Way Match Status, Recent Activity, every tab) remains fully
available. AI on this hub is strictly additive, never a gate on anything a human needs to do their job.

# States

Every region on this hub carries its own loading, empty, and error presentation — no region blocks
another, and none ever renders a bare spinner as its primary loading state.

| Region | Loading | Empty | Error |
|---|---|---|---|
| KPI Strip | Four `Skeleton` tiles matching `KpiTile`'s final geometry | Brand-new company with no posted purchasing documents: a dedicated "No purchasing activity yet" card with a "Create your first Purchase Request" CTA — never the generic zero-value tiles a filtered-to-zero period would otherwise show | Widget-level `<ErrorBoundary>` renders a small inline "Couldn't load this figure — Retry" card per tile; the other tiles remain interactive |
| Pending Approvals | Three skeleton `ApprovalCard` shapes | Calm "Nothing waiting on you" — explicitly reassuring copy, since an empty queue is good news here, matching `docs/frontend/AI_COMMAND_CENTER.md`'s identical empty-state posture for its own Approval Center | Inline retry card; "View all in Approval Center" link stays functional even if this condensed query fails, since it points at the underlying `/approvals` route |
| AI Purchasing Insights | Three skeleton cards | "No open insights right now — duplicate checks, price comparisons, and vendor recommendations will appear here" | A distinct "AI insights are temporarily unavailable" state (`# AI Integration`), respecting any `Retry-After` header on a `503` |
| Three-Way Match Status | Skeleton bar at the real component's height | Never truly empty while any Bill exists — a company with zero bills renders "No bills yet" instead of a zero-count bar, distinct from "everything happens to be Matched" (which renders normally, all-green) | Retry card; this region is omitted entirely rather than erroring for a caller lacking `purchasing.bill.read` (`# Route & Access`) |
| Recent activity | 5–6 skeleton rows | "No activity yet — create your first Purchase Request or Purchase Order to see it here," with a CTA into Quick Actions | Inline retry row; "View all activity" remains functional independently |
| Outstanding by tab | Six skeleton rows | Each row independently shows "0" rather than being omitted — a healthy queue of zero is itself useful information here, unlike an omitted KPI tile | Row-level dash with a tooltip on the specific failed count, other rows unaffected |
| Each tab's `DataTable` | `Skeleton` shaped to that tab's own column geometry | Tab-specific `emptyState` (e.g., Bills: "No bills yet — enter your first vendor bill"); a *filtered* zero-result set renders `DataTable`'s automatic lighter "no rows match your filters" variant, never conflated with genuine zero-activity | `ErrorState` with retry, per `DataTable`'s own contract |
| Fetching next page (any tab) | Trailing row-shaped `Skeleton` strip | — | — |
| Realtime new-activity available | Dismissible, non-disruptive banner ("New activity — Refresh") above the affected region; existing rows never mutate in place | — | — |

# Responsive Behavior

This hub follows `docs/frontend/RESPONSIVE_DESIGN.md`'s five-tier breakpoint system exactly — **Mobile**
(`base`, <640px), **Tablet** (`sm:`/`md:`, 640–1023px, persistent-chrome threshold at 768px), **Laptop**
(`lg:`, 1024px), **Desktop** (`xl:`/`2xl:`, 1280px/1536px), **Ultra Wide** (`3xl:`, 1920px) — and each of
the six tabs' own `DataTable`s follow that document's **Finance Tables On Small Screens** rules verbatim
(card-per-row below `md:`, a real `<table>` with priority-ranked columns from `md:` up, virtualization
past ~200 rows).

**Mobile (`base`–`sm`).** The sub-nav collapses to a horizontally-scrollable chip row (the same pattern
`docs/frontend/NAVIGATION_SYSTEM.md` uses for its "More" bottom-sheet elsewhere, applied here to six
sibling tabs rather than the full nav tree); the Filter Bar's three controls stack into a single
"Filters" trigger opening a `Sheet`. The KPI Strip becomes a horizontal-scroll, snap-aligned carousel —
one tile per screen width — identical to `docs/frontend/DASHBOARD.md`'s own KPI Strip behavior. Pending
Approvals, the AI Insights rail, the Three-Way Match Status card, Recent Activity, and Outstanding-by-tab
each stack full-width, one per row, in the order given in `# Layout & Regions`. Each tab's list becomes a
stack of row-shaped cards (e.g., a Bill card showing Bill #, vendor, due date, total, and a `StatusPill`,
ending in a row-action button) per `RESPONSIVE_DESIGN.md`'s card-conversion rule.

**Tablet (`md:`, 768px+).** The KPI Strip becomes two tiles per row; the persistent icon-rail Sidebar
replaces the mobile Sheet/bottom-tab-bar pattern; each tab's `DataTable` gains its second and third
priority column instead of collapsing fully to cards.

**Laptop (`lg:`, 1024px+).** The KPI Strip becomes a full row; Pending Approvals and the AI Insights rail
activate their 7/5 split; the Three-Way Match Status card's four segments sit inline in one row instead
of stacking.

**Desktop (`xl:`/`2xl:`, 1280px/1536px+).** Same bento layout, generous margins; every tab's `DataTable`
shows every priority-1-through-4 column with no horizontal scroll; content is capped at `max-w-[1440px]`
above `2xl:`, per the platform's stated reasoning that a finance grid stretched to fill a very wide window
is harder to read, not easier.

**Ultra Wide (`3xl:`, 1920px+).** The global AI Rail companion panel (the platform-wide `360px` ambient
panel, distinct from this hub's own AI Purchasing Insights region) may dock inline alongside this hub's
content without competing for the same column, exactly as `docs/frontend/LAYOUT_SYSTEM.md` describes for
its Dashboard Template.

Touch targets on every clickable KPI tile, `ApprovalCard` action, and Three-Way Match Status segment
maintain the platform's 44×44px minimum hit area at every tier below `lg:`, implemented once in the
shared `Button`/`IconButton` components rather than per instance on this hub.

# RTL & Localization

Every rule below is inherited from `docs/frontend/LAYOUT_SYSTEM.md`'s RTL contract and
`docs/frontend/THEMING.md`'s RTL-as-a-theming-dimension, applied concretely to this hub's content.

- **Logical properties only.** The sub-nav tab order, the Filter Bar's control order, the KPI Strip's
  tile order, and the Outstanding-by-tab list all use `ms-*`/`me-*`/`text-start`/`text-end` exclusively;
  flipping `dir="rtl"` on `<html>` mirrors the whole hub with zero screen-specific RTL code.
- **Document numbers stay LTR-isolated inside Arabic sentences.** `PO-2026-004417`, `BILL-2026-000789`,
  `VP-2026-000902`, `RFQ-2026-0091` — every reference to a document number, in a `StatusPill`, an
  `ApprovalCard`, an AI reasoning string, or the Recent Activity feed, renders inside a `dir="ltr"` +
  `unicode-bidi: isolate` span, per the platform's bidi-isolation convention, so a document number never
  visually reorders inside a right-to-left sentence.
- **Numeric alignment is physically fixed.** Every `AmountCell` on this hub — the KPI Strip's spend
  figures, the Three-Way Match Status card's per-segment amounts, every tab's own money columns — stays
  `text-end` in both directions, so a bilingual Finance team scanning magnitude and decimal alignment
  sees figures land on the same edge regardless of interface language.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching Gulf
  financial-document convention and `AmountCell`'s own platform-wide implementation.
- **Bilingual names throughout.** Every vendor name (in a Purchase Order row, a Bill row, an AI
  Vendor Recommendation card) renders `vendor_name_ar` under an Arabic session and falls back to
  `vendor_name_en` only if the Arabic name is genuinely absent, per the platform's bilingual-fields
  convention — never the reverse.
- **Directional icons flip; meaning-bearing icons don't**, per `docs/frontend/ICONOGRAPHY.md`'s
  directional-icon table — the sub-nav's active-tab indicator and breadcrumb chevrons mirror; the
  Three-Way Match Status card's segment order and any inflow/outflow-style glyph do not, since they
  encode a financial or sequential meaning, not a reading direction.
- **AI reasoning text is generated in the viewer's session locale** and rendered as plain translated
  prose, never re-translated client-side; Arabic procurement terminology is used precisely — طلب شراء
  (Purchase Request), أمر شراء (Purchase Order), إيصال استلام (Goods Receipt), فاتورة مورد (Vendor Bill),
  مطابقة ثلاثية (Three-Way Match).

| Context | English | Arabic |
|---|---|---|
| KPI tile | Open Purchase Orders | أوامر الشراء المفتوحة |
| Match status segment | Variance flagged | مطابقة بها فرق |
| AI insight | Possible duplicate — 92% confidence. | احتمال تكرار الفاتورة — بثقة 92%. |
| Empty state | Nothing waiting on you. | لا يوجد ما ينتظر إجراءك. |
| Quick action | New Purchase Request | طلب شراء جديد |

Arabic copy on this hub is authored directly by a fluent professional-register writer, not machine-
translated from the English strings above, matching the platform's stated voice discipline.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `docs/frontend/DARK_MODE.md`. Nothing on
this hub references a raw hex value or a Tailwind palette utility; every surface, border, and status
color resolves through the semantic tokens that document defines.

- **Surfaces and elevation.** The page canvas sits on `--surface-canvas`; the KPI tiles, the Pending
  Approvals and AI Insights cards, the Three-Way Match Status card, and every tab's own table surface sit
  on `--surface-base`; any overlay (a `Sheet` opened from a row action, the mobile Filters sheet) sits on
  `--surface-raised`/`--surface-overlay`. Elevation communicates by getting *lighter* toward the viewer in
  dark mode, never by shadow alone — a borderless dark card disappears against the near-black canvas,
  which `DARK_MODE.md` calls out as the single most common dark-mode regression its component library
  guards against, so every card on this hub carries its paired `--border-subtle` hairline in dark mode
  even where the light-mode equivalent relies on shadow alone.
- **Money never takes a status color.** Every `AmountCell` on this hub renders in `--text-primary`
  regardless of theme; only `StatusPill`s, the Three-Way Match Status segments' fill, and the AI risk
  cards' left borders ever carry `--status-success`/`--status-warning`/`--status-error` (with their
  paired `-subtle` background tokens) — a large committed-spend figure is not "bad" and a fully-received
  PO is not inherently "good," and this hub never implies otherwise through color on the amount itself.
- **AI provenance uses the reserved AI accent, never a finance-semantic color.** Every card in the AI
  Purchasing Insights rail — its border accent, its `ConfidenceBadge` fill, its small "AI" mark — resolves
  through `--ai-accent`/`--ai-accent-subtle` exclusively, in both themes, so "the AI flagged this" and
  "this bill is overdue" remain two separately colored, separately legible facts even when both are true
  of the same row (a duplicate-flagged Bill that is also overdue shows a `--status-error` due-date pill
  and a `--ai-accent` confidence ring side by side, never one color standing in for both).
- **Contrast.** Every token pairing on this hub is independently verified at ≥4.5:1 (text) / ≥3:1
  (non-text, including the Three-Way Match Status card's segment dividers) in both themes, per
  `DARK_MODE.md → Color & Contrast In Dark`.
- **Print/export independence.** Any exported Spend Analysis or Purchase Dashboard PDF always renders in
  QAYD's fixed light/print palette regardless of the viewer's active theme, per `DARK_MODE.md → Exported
  PDFs always render light`.

# Accessibility

Baseline is WCAG 2.2 AA per `docs/frontend/ACCESSIBILITY.md`, implemented without deviation and applied
concretely below.

- **Landmark structure.** Each region (KPI Strip, Pending Approvals, AI Purchasing Insights, Three-Way
  Match Status, Recent Activity, Outstanding by tab) is a real `<section>` with a visually-hidden `<h2>`
  labeling it for screen-reader navigation, even where the sighted design omits a visible heading —
  matching `docs/frontend/DASHBOARD.md`'s identical pattern.
- **Keyboard path.** Tab order flows sub-nav tabs → Filter Bar (`PeriodPicker` → `BranchSelect` →
  `CurrencySelect`) → page-header Quick Actions → KPI Strip (each tile a single focusable stop when it
  has an `onClick`) → Pending Approvals' cards and their action buttons → AI Insights rail's cards → the
  Three-Way Match Status card's four clickable segments → Recent Activity's row links → Outstanding-by-
  tab's row links. No region requires a mouse to reach or activate any control.
- **The Three-Way Match Status card's segments are real `<button>`s**, each with a full accessible name
  ("Variance flagged, 5 bills, 2,140.000 KWD — view bills"), never a bare colored `<div>` with a click
  handler — a screen-reader user gets the count and the amount before deciding whether to activate it.
- **Sortable headers on every tab's `DataTable`** are real `<button>`s with `aria-sort` on the parent
  `<th>`, never a clickable `<th>` or a `<div>`, per `docs/frontend/COMPONENT_LIBRARY.md`'s `DataTable`
  accessibility contract.
- **Realtime updates announce politely, never assertively.** A new Pending Approval landing, a Three-Way
  Match Status count changing, or a fresh AI Insights card arriving all use `aria-live="polite"`
  exclusively — never `"assertive"` — matching `docs/frontend/AI_COMMAND_CENTER.md`'s "auto-detect, never
  alarming" posture applied here.
- **Confidence and reasoning are always real text**, never conveyed through a bare progress-bar width
  alone: `ConfidenceBadge`'s percentage and qualitative label are both text nodes, and its `Tooltip`
  carrying `reasoning` is reachable and dismissible via focus, not hover-only.
- **RBAC-aware disabled controls explain themselves.** A Pending Approval step disabled because it isn't
  yet the caller's turn in a multi-step chain carries `aria-describedby` naming that reason, worded
  distinctly from a control disabled for lack of permission ("Requires `purchasing.payment.release`") —
  the two causes are never collapsed into a generic "You can't do this," per `ACCESSIBILITY.md`'s RBAC-
  aware disabled-control pattern.
- **Every icon-only row action carries a unique accessible name** — "Approve Purchase Order PO-2026-
  004417," never a bare "Approve" repeated identically across every row in a `.map()`, per
  `ACCESSIBILITY.md`'s `IconButton` lint rule.
- **Amounts carry meaning beyond color.** Every `AmountCell` on this hub pairs its value with a leading
  sign glyph and, where relevant, a text label — a colorblind user or a grayscale print of this hub's
  KPI Strip loses no information a sighted, color-normal user has.
- **Focus management on navigation and overlays.** Clicking a KPI tile, a Three-Way Match Status segment,
  or a Recent Activity row navigates to a new route rather than mutating this page in place, so focus
  lands on the destination's own heading via the platform's standard route-change focus-reset; opening
  the mobile Filters `Sheet` moves focus to its heading and returns it to the trigger on close.

# Performance

- **Streamed, not blocking.** Per `# Data & State`, every region is its own `Suspense` boundary; the
  slowest widget on this hub (typically the AI Purchasing Insights rail, if an agent run is still in
  flight) never delays the KPI Strip's first paint, and a failure in one region never takes down another.
- **Cached at the source.** `GET /purchasing/reports/purchase-dashboard` is Redis-cached server-side; a
  cache miss on a deterministic figure (open PR/PO counts, bills due this week — plain aggregation over
  posted tables) recomputes synchronously inline since it is cheap SQL, while a cache miss on an AI-
  authored element never blocks the page — it serves the last-cached payload with an explicit `stale:
  true` marker rather than making the user wait on an agent run to render their hub.
- **Cursor pagination on every tab, not offset.** Each of the six tabs' `DataTable` instances use
  `paginationMode="cursor"` against their own endpoint, so scroll cost on the Bills or Purchase Orders
  tab stays `O(per_page)` regardless of how many pages a long session has already fetched, matching
  `docs/api/API_PAGINATION.md`'s own performance guarantee for exactly this kind of resource.
- **Virtualization past ~200 rows.** Any tab's `DataTable` mounts `@tanstack/react-virtual` once its
  loaded row count crosses roughly 200, per `docs/frontend/RESPONSIVE_DESIGN.md → Virtualization at
  scale` — most relevant to the Bills and Purchase Orders tabs for a company with meaningful transaction
  volume.
- **Debounced, stable filter changes.** The Filter Bar's branch/currency selectors and any tab-level
  search debounce at 300ms, with `placeholderData: keepPreviousData` on every affected query so a filter
  change dims the previous result rather than flashing empty.
- **Realtime patches are the exception, not the rule.** Only a high-frequency, low-risk figure (none on
  this hub qualifies as such today) would ever use a direct `setQueryData` patch; every realtime event
  described in `# Data & State → Realtime` goes through an ordinary `invalidateQueries` call, which can
  never drift from what the server actually holds.
- **Web Vitals tracked against a realistic baseline.** LCP/CLS/INP for this route are tagged with
  company-size band, since a company with thousands of open Purchase Orders and a brand-new company with
  none have legitimately different tail latencies, per `FRONTEND_ARCHITECTURE.md → Web Vitals`.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A company switch fires while one of this hub's own widget fetches is still in flight | `queryClient.clear()` runs before `router.refresh()`, per `FRONTEND_ARCHITECTURE.md → Company switching`; the in-flight request's eventual response is discarded on arrival since its query key no longer exists in the cleared cache. |
| A role holds `purchasing.read` but none of the finer-grained keys any individual region needs (a narrow custom role) | The KPI Strip, Pending Approvals, AI Insights rail, and Three-Way Match Status card each render their own empty-region fallback independently rather than a "you don't have access" wall for the whole hub; if the role also lacks every Quick Action's create permission, the header row collapses to nothing and the sub-nav shows only the tabs that role can read. |
| A Vendor Payment approaching release is attempted by the same user who created it, above the two-person threshold (`purchasing.payment.release`'s maker≠checker rule) | The API returns `403` with `code: "maker_checker_violation"`; the row's Release action surfaces an inline explanation ("This payment must be released by someone other than its creator") rather than a generic permission-denied toast, and the Pending Approvals card for that payment stays visible to a *different* eligible releaser. |
| A three-way match variance is detected on Bill submission (quantity and/or price outside tolerance) | The API returns `409` with a structured `errors[]` array naming each violated tolerance (e.g., "Billed quantity 2050 exceeds goods-receipt accepted quantity 2000 by 2.50%, outside the configured 0% tolerance"); the Bill lands in `variance_flagged` and its Three-Way Match Status bucket updates to Variance flagged on the next fetch/realtime push — the hub never silently drops or hides a variance. |
| A Goods Receipt line requires Quality Inspection before its quantity is usable | The Goods Receipts tab's row shows an "Inspection pending" sub-status distinct from its own lifecycle `status`; the received quantity does not yet count toward the receiving PO's "fully received" rollup on this hub's KPI Strip until inspection resolves, matching `docs/accounting/PURCHASING.md`'s quarantine-hold business rule. |
| A non-PO Bill (`purchase_order_id IS NULL`, under `non_po_bill_max_amount`) | Skips three-way match entirely; its row on the Bills tab shows `match_status: null` rendered as a plain "No PO" tag rather than an empty or error cell, and it is excluded from the Three-Way Match Status card's four segments (which are scoped to PO-backed bills only) while still counting toward the KPI Strip's overall spend figures. |
| The AI engine is down or returns `503` for an extended period | The KPI Strip, Pending Approvals, Three-Way Match Status, and every tab remain entirely unaffected, since none of them call the AI engine — only the AI Purchasing Insights rail degrades to its "temporarily unavailable" state (`# AI Integration`). |
| An AI Vendor Recommendation card's underlying RFQ is awarded (or cancelled) by another user between the card rendering and this user clicking **Review** | The mutation re-fetches the target RFQ immediately before opening it and compares state; on a mismatch, the card is replaced with "This changed — open RFQ-2026-0102 to review" rather than opening a stale invite-list draft against an RFQ that no longer accepts one, mirroring `docs/frontend/AI_COMMAND_CENTER.md`'s identical conflict posture for its own Approval Center rows. |
| Two browser tabs have this hub open; the user changes the branch filter in one | Per the platform's branch-switch contract, this is a URL-mirrored, client-scoped filter, not a session-level change — the second tab's own filter state is untouched and the two can legitimately show two different branches at once with no synchronization bug, unlike a company switch, which is session-level. |
| Laravel Reverb's WebSocket connection drops and later reconnects | Every realtime-fed query key on this hub (`purchasingKeys.dashboard`, `.matchStatus`, the six list keys, the scoped `approvals` query, `purchasingAiKeys.*`) is invalidated once, as a single batch, on reconnect — a `bill.approved` event that fired during the outage must still be reflected the moment connectivity returns; this hub never trusts a stale Three-Way Match Status count just because no error was ever shown for it. |
| A brand-new company's first user opens this hub before creating any purchasing document | Every region renders its dedicated brand-new-company empty state (`# States`) rather than a filtered-to-zero-results empty state — the distinguishing signal each endpoint returns is "no purchasing documents for this company at all," not "no rows matched this specific filter," and the two are visually and textually different. |
| A company holds Purchase Orders and Bills across more than one currency (KWD, USD, AED vendors) | Every KPI Strip figure and the Three-Way Match Status card's per-segment amounts are always the sum converted to the company's base currency (or the Filter Bar's chosen reporting currency), per the platform's multi-currency rule; a `CurrencyTag` in muted emphasis annotates a tab's row only when that specific document's transaction currency differs from the currently displayed reporting currency. |
| A Purchase Order is amended after being sent (BR-PO-3 in the backend module: a `sent` PO's price/quantity is immutable, so a change creates a new amendment revision linked via `amends_po_id`) | The Purchase Orders tab shows only the current, non-superseded revision by default; the superseded original remains reachable from that PO's own detail page's revision history, never resurrected as a second active row on this hub or on the tab's list. |

# End of Document
