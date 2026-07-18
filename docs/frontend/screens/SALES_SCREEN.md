# Sales Landing Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: SALES_SCREEN
---

# Purpose

This document is the concrete, build-ready screen specification for exactly one route —
`app/(app)/sales/page.tsx`, the landing surface of the Sales module — written to the platform's
Screen Doc structure (`# Route & Access` through `# Edge Cases`). It is the implementation-level
companion to `docs/frontend/SALES.md` (the "Sales Hub" module document), which already establishes
this screen's place in the Sales information architecture, its reconciliation with
`FRONTEND_ARCHITECTURE.md`'s route tree and `NAVIGATION_SYSTEM.md`'s module map, its component
inventory, and the narrative reasoning behind each of those calls. Where `SALES.md` argues *why*
this screen exists in the shape it does, this document specifies *exactly how it is built* — the
wire-level request/response payloads behind every region, the query-key and hook wiring an engineer
copies into `components/sales/`, and a fully worked, concrete design for the three AI-sourced
signals a Sales Manager most wants summarized in one place: the **revenue forecast**, **upsell/
cross-sell opportunity**, and **credit exposure warnings**. Every fact `SALES.md`, `docs/accounting/
SALES.md`, or `docs/ai/agents/SALES_AGENT.md` already fixed — routes, component names, permission
keys, status enums, query keys, realtime channels, endpoint paths — is reused verbatim here, never
re-derived or quietly changed. Where this document adds detail those three left implicit (the upsell
and credit-warning rollups chief among them), it is additive to their design, never a contradiction
of it, and is called out inline exactly where it departs from a one-line mention into a full
specification, the same discipline `SALES.md` itself uses when it reconciles a stale routing clause
elsewhere in the doc set.

Concretely, `app/(app)/sales/page.tsx` composes six things, in the order a Sales Manager, Sales
Employee, or Finance user encounters them on open:

1. **A module sub-navigation** — Overview (this screen) · Customers · Quotations · Orders ·
   Invoices · Credit Notes · Receipts — the same seven-tab bar `SALES.md → Route & Access` defines.
2. **A pipeline overview KPI band** — today's revenue, open pipeline value (raw and probability-
   weighted), orders awaiting approval, and overdue AR, sourced from the one aggregate endpoint the
   Sales module already exposes for this purpose.
3. **A Recent Documents panel** — a merged, cross-type feed across Quotations/Orders/Invoices/
   Receipts/Credit Notes by default, becoming one type's own `DataTable` the moment a tab is picked.
4. **A permission-gated Quick Create menu** — into a draft quotation, order, invoice, receipt, or
   credit note, using the platform's intercepted-route pattern so a fast create never leaves this
   screen.
5. **An AI Sales Agent insights rail** — a forecast chip, an upsell/cross-sell opportunity summary, a
   credit exposure summary, condensed pending-review recommendation cards, and any fraud/credit
   step-up currently blocking a document.
6. Nothing else. This screen renders no business logic of its own — it never confirms an order,
   posts an invoice, allocates a receipt, applies a credit tier, or lets a recommendation execute
   itself. Every number here was computed and validated by Laravel; every mutation this screen
   triggers is a call to `/api/v1/sales/...` guarded by the exact permission key the API itself
   enforces, per `FRONTEND_ARCHITECTURE.md`'s Principle 1 ("the frontend never contains business or
   financial logic") and Principle 4 ("respect RBAC by hiding and disabling, never by trusting the
   client").

The five document-type list screens this Hub's sub-nav links to (Quotations, Orders, Invoices,
Credit Notes, Receipts) and the Customers tab are each their own screen — `docs/frontend/INVOICES.md`
already specifies the Invoices list/detail pair in full, and the remaining four follow
`LAYOUT_SYSTEM.md`'s List Page Template identically, per `SALES.md`'s own route-tree comments — this
document does not redefine any of them. It also does not redefine the Sales Agent's own reasoning,
autonomy levels, or database schema (`docs/ai/agents/SALES_AGENT.md` owns all of that in full) or the
order-to-cash business rules and table design (`docs/accounting/SALES.md` owns those); this screen
only renders what those two documents already compute and validate.

# Route & Access

## App Router path

This screen occupies exactly the routes `SALES.md → Route & Access` already fixes; they are
reproduced here so this document is self-contained for an engineer building only this page:

```
app/(app)/sales/
├── layout.tsx                            # Sub-nav shell: Overview | Customers | Quotations | Orders | Invoices | Credit Notes | Receipts (sales.read gate)
├── page.tsx                               # ★ THIS DOCUMENT — the "Overview" tab: KPI band, Recent Documents, Quick Create, AI rail
├── @modal/
│   ├── (.)quotations/new/page.tsx         # Intercepted quick-create, rendered as a Dialog over this page
│   ├── (.)orders/new/page.tsx
│   ├── (.)invoices/new/page.tsx
│   ├── (.)receipts/new/page.tsx
│   └── (.)credit-notes/new/page.tsx
├── customers/[[...segment]]/page.tsx      # Owned by Accounting/CRM's own CUSTOMERS.md + screen doc
├── quotations/{page.tsx, new/page.tsx}    # List Page Template — its own screen document
├── orders/{page.tsx, new/page.tsx}
├── invoices/{page.tsx, new/page.tsx, [invoiceId]/page.tsx}   # Specified in docs/frontend/INVOICES.md
├── credit-notes/{page.tsx, new/page.tsx}
└── receipts/{page.tsx, new/page.tsx}
```

`app/(app)/sales/page.tsx` is a real, standalone file at the module root — not the Quotations list
"enriched" with extra widgets. `SALES.md`'s own route-tree note already reconciles this against two
sibling documents that currently point elsewhere (`FRONTEND_ARCHITECTURE.md`'s route tree omits a
bare `sales/page.tsx`, and `NAVIGATION_SYSTEM.md`'s module map currently fixes the Sidebar's Sales
entry at `/sales/quotations`); this document inherits that reconciliation rather than re-arguing it —
the Sidebar's Sales entry and the Command Palette's Navigate group both resolve to `/sales`, and the
two sibling documents are stale on this one point until their own route trees are updated to match.
One further, narrower point this document resolves on its own: `NAVIGATION_SYSTEM.md`'s Sub-
navigation table describes "Credit Notes tab additionally needs `sales.credit_note.read`" as a
qualifier on the Invoices row, which read literally would nest Credit Notes inside `/sales/invoices`.
This screen follows `SALES.md`'s own route tree instead — Credit Notes as a sibling top-level tab and
directory (`/sales/credit-notes`), gated on its own permission — because that is the shape this
Hub's sub-nav, its Recent Documents segmented control, and its Quick Create menu all already commit
to; `NAVIGATION_SYSTEM.md`'s phrasing should be read as shorthand for "the Credit Notes *permission*
is checked alongside Invoices' own," not as a routing instruction, and is a documentation wording fix
for that document to pick up, not a behavior this screen implements two ways.

## Permission gate

Every gate below reuses the exact permission key `docs/accounting/SALES.md → Permissions` or
`docs/ai/agents/SALES_AGENT.md → Tools & API Access` already defines — this screen introduces no new
permission except by clearly-marked reference to the one `SALES_AGENT.md` itself introduces
(`sales.ai.approve_draft`).

| Gate | Permission | Effect if absent |
|---|---|---|
| Screen visible at all | `sales.read` | Sidebar's Sales entry (and this route) does not render; a direct hit to `/sales` renders the shell-level `error.tsx`, never a silent redirect, per `NAVIGATION_SYSTEM.md → Server truth, client courtesy`. |
| Pipeline KPI band | `sales.report.read` | The whole band is omitted, not a row of permission-denied placeholders — Recent Documents and the AI rail render independently. |
| Recent Documents — Quotations source | `sales.quotation.read` | Tab and its rows in the merged feed are omitted. |
| Recent Documents — Orders source | `sales.order.read` | Same treatment. |
| Recent Documents — Invoices source | `sales.invoice.read` | Same treatment. |
| Recent Documents — Receipts source | `sales.receipt.read` | Same treatment. |
| Recent Documents — Credit Notes source | `sales.credit_note.read` | Same treatment. |
| Customers tab | `accounting.customer.read` (owning module: Accounting/CRM) | Tab omitted — the permission always matches the record's true owner, never a Sales-flavored duplicate, per `NAVIGATION_SYSTEM.md`'s grouping rule. |
| Quick Create → Quotation | `sales.quotation.create` | Menu item omitted, not disabled. |
| Quick Create → Order | `sales.order.create` | Menu item omitted. |
| Quick Create → Invoice | `sales.invoice.create` | Menu item omitted. |
| Quick Create → Receipt | `sales.receipt.create` | Menu item omitted. |
| Quick Create → Credit Note | `sales.credit_note.create` | Menu item omitted. |
| AI Sales Agent rail (forecast, upsell, credit exposure, pending review) | `sales.ai.read` | Rail omitted entirely, wrapped in `<Can permission="sales.ai.read">`. |
| Accept an AI recommendation (invoice/receipt draft, upsell line) | `sales.ai.approve_draft` **and** the resource's own create/update permission | "Accept" disabled with a permission tooltip, not omitted — the card is already addressed to a specific reviewer. |
| Fraud/duplicate step-up or credit-blocked `ApprovalCard` | `sales.order.confirm` (with override) or `sales.credit.override` | Approve/Reject disabled with a tooltip; the card itself always renders, since hiding a document blocked on the viewer's own next step would look like a missed notification. |

## Roles

Reproduced from `docs/accounting/SALES.md`'s Permissions table and `docs/ai/agents/SALES_AGENT.md`'s
own AI Agent row, translated into what each role concretely sees on this one screen:

| Role | What renders here |
|---|---|
| Owner, CEO, CFO | Full hub: KPI band, every Recent Documents tab, every Quick Create item, full AI rail (forecast, upsell summary, credit exposure list, pending review, fraud/credit step-up). |
| Sales Manager | Full hub; Quick Create for Quotations/Orders; can approve a fraud step-up or a credit-blocked order at or below the company's configured threshold; sees the full AI rail. |
| Sales Employee | KPI band scoped to `sales.report.read`'s "own scope" note; Quotations/Orders tabs with their own documents emphasized; Quick Create for Quotations/Orders only; sees the upsell/cross-sell summary (their own quotations only) and the forecast chip, but not the fraud/credit approval cards, which require a permission they do not hold. |
| Finance | Full KPI band; Invoices/Receipts/Credit Notes tabs and their Quick Create actions; Quotations/Orders are read-only preview rows in the merged feed; reviews and accepts `invoice_draft`/`receipt_draft` recommendations and the credit exposure list in full (their `sales.credit.override` grant surfaces the hard-blocked cards too). |
| Warehouse | Hub renders with an empty-but-not-broken KPI band and no Quick Create actions — their Sales permissions are delivery-stage only, out of this Hub's scope (`docs/accounting/SALES.md → Deliveries`). |
| Auditor / External Auditor | Fully read-only: KPI band, every Recent Documents tab, and the AI rail render with every mutating control (Quick Create, Accept, Approve/Reject, Dismiss) omitted. |
| AI Agent (service account) | Never renders this screen as a user. Its permission scope is read-mostly (`*.read` across Sales plus write access solely to `ai_recommendations`, per `docs/accounting/SALES.md → Permissions`) — it structurally cannot hold `sales.order.confirm`, `sales.invoice.post`, `sales.receipt.create`, or `sales.ai.approve_draft`, so every card this screen's rail renders is always accepted under a *human* reviewer's own session. |

# Layout & Regions

`app/(app)/sales/page.tsx` instantiates a hybrid of `LAYOUT_SYSTEM.md`'s **Dashboard Template** (KPI
Strip + Insights Feed, for the KPI band and the AI rail) and its **List Page Template** (for Recent
Documents, which becomes a genuine List Page instance the moment a document-type tab is active) —
the same blend `DASHBOARD.md` and `docs/frontend/BANKING.md` use for their own hub screens, never a
third, bespoke template.

```
┌─────────────────────────────────────────────────────────────────────────────────────────────┐
│  Overview  Customers  Quotations  Orders  Invoices  Credit Notes  Receipts    [sub-nav tabs]  │
├─────────────────────────────────────────────────────────────────────────────────────────────┤
│  Sales                                                          [Search…]         [+ New ▾]   │
│  Open pipeline KD 48,200.000 (weighted KD 19,680.000) · 4 orders awaiting approval             │
├─────────────────────────────────────────────────────────────────────────────────────────────┤
│ [Today's revenue     ] [Open pipeline         ] [Orders awaiting  ] [Overdue AR              ]│
│ [KD 12,480.500  ▲2%  ] [KD 48,200.000 ~~~~~~~ ] [approval:  4      ] [KD 9,340.250 · 11 inv. ]│
├──────────────────────────────────────────────────────────┬────────────────────────────────────┤
│  Recent documents      [All][Quot.][Ord.][Inv.][CN][Rcpt]│  AI · Sales Agent                  │
│  ┌──────────────────────────────────────────────────────┐│  ● Forecast P50 KD 210,000          │
│  │● QT  QT-KWC-2026-00512  Al Rayyan Trading   1,835.400 ││    (P10 180,000 – P90 250,000)      │
│  │    sent · 2h ago                                      ││  ─ Upsell & cross-sell ───────────  │
│  │  SO  SO-KWC-2026-08841  Al Rayyan Trading   1,835.400 ││  ▸ 5 open quotations have a          │
│  │    confirmed · 2h ago                                 ││    ranked upsell line · view →      │
│  │  INV INV-KWC-2026-14502 Al Rayyan Trading   1,835.400 ││  ─ Credit exposure ────────────────  │
│  │    posted · yesterday                                 ││  ▸ Al Rayyan Trading — 99% of        │
│  │● RC  RC-KWC-2026-07310  Al Rayyan Trading   1,835.400 ││    KD 6,000.000 limit                │
│  │    cleared · yesterday                                ││  ─ Pending review ─────────────────  │
│  │  …                                                     ││  ▸ Invoice draft — Al Rayyan (96%)   │
│  │                                    [View all in tab →] ││  ▸ Collections nudge — Bader Co.     │
│  └────────────────────────────────────────────────────────┘│  ─ Needs approval ──────────────────│
│                                                             │  ⚠ Fraud step-up — SO-08902 (78/100) │
│                                                             │  [ View all AI activity → ]         │
└─────────────────────────────────────────────────────────────────────────────────────────────┘
```

```
Mobile (base–sm, <768px)
┌───────────────────────────┐
│ ≡  Sales            🔔 👤 │
├───────────────────────────┤
│ ⇤ Overview Custmrs Quot ⇥ │  ← horizontal-scroll sub-nav
├───────────────────────────┤
│ Sales                 [+] │
│ Open pipeline KD 48,200   │
├───────────────────────────┤
│ ⇤  [Today's rev  ▲2%]  ⇥  │  ← snap-scroll KPI carousel, one tile per screen
├───────────────────────────┤
│ Recent documents          │
│ [All▾]                    │
│ ● QT-00512  Al Rayyan     │
│   sent · 2h ago  1,835.400│
│ …                         │
├───────────────────────────┤
│ AI · Sales Agent          │
│ ● Forecast P50 KD 210,000 │
│ ▸ 5 upsell opportunities  │
│ ▸ Al Rayyan — 99% limit   │
│ ▸ Invoice draft (96%)     │
│ ⚠ Fraud step-up (78/100)  │
├───────────────────────────┤
│ 🏠  💰  📄  🔔  ☰          │  ← bottom tab bar
└───────────────────────────┘
```

| Region | Grid position | Content | Notes |
|---|---|---|---|
| Sub-nav | Full width, part of `sales/layout.tsx`'s shell | `Tabs`: Overview (active) · Customers · Quotations · Orders · Invoices · Credit Notes · Receipts | Server Component; not streamed |
| Page Header | Full width | `<h1>Sales</h1>`, one-line pipeline summary, records `Search…` box, permission-gated `SalesQuickCreateMenu` | Renders immediately with the page shell |
| KPI band | `col-span-12`, one row | Four `KpiTile`s: Today's Revenue, Open Pipeline (value + weighted value), Orders Awaiting Approval, Overdue AR | Own `<Suspense>` — `GET /api/v1/sales/dashboard` |
| Recent Documents | `col-span-8` at `lg`+ | `RecentSalesDocumentsPanel` — segmented control over the merged feed ("All") or a real `DataTable` per type | Own `<Suspense>` per active source; tab switch is a client-side resource swap |
| AI Sales Agent rail | `col-span-4` at `lg`+ | `SalesAiInsightsRail` — forecast chip, upsell/cross-sell summary, credit exposure list, up to three condensed pending-review cards, any fraud/credit step-up | Own `<Suspense>`; scrolls independently of Recent Documents |

At `xl`+ the platform-wide 360px AI Rail companion panel (`LAYOUT_SYSTEM.md`'s fourth shell region)
may additionally dock inline — it is a companion to whatever the user is currently looking at, never
a duplicate of this page's own `SalesAiInsightsRail` column, which remains visible regardless of
whether the global rail is toggled, exactly as `DASHBOARD.md` describes for its own AI Summary Rail.
The rail's five internal sections — forecast, upsell/cross-sell, credit exposure, pending review,
needs approval — render top-to-bottom in that fixed order (never reordered by recency), because a
Sales Manager's morning scan benefits from a stable "look here first" structure more than from a
strict chronological feed; a section with nothing to show collapses to zero height rather than
rendering an empty-state card of its own (see **States**).

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue,
composed into Sales-scoped components living in `components/sales/` per `PROJECT_STRUCTURE.md`'s
module-folder convention. This screen never builds a second table, money cell, or confidence
indicator that already exists elsewhere in the library.

| Component | Source | Role on this screen |
|---|---|---|
| `Tabs` | Primitive (`components/ui/tabs.tsx`) | Sub-nav; a second, smaller instance drives the Recent Documents segmented control |
| `KpiTile` | Finance (`components/dashboard/kpi-tile.tsx`) | Today's Revenue, Open Pipeline, Orders Awaiting Approval, Overdue AR |
| `TrendSparkline` | Finance (`components/dashboard/trend-sparkline.tsx`) | 30-day trend inside the Today's Revenue tile |
| `AmountCell` | Finance (`components/accounting/amount-cell.tsx`) | Every monetary figure outside a `KpiTile` |
| `CurrencyTag` | Finance (`components/shared/currency-tag.tsx`) | Multi-currency annotation on a Recent Documents row |
| `StatusPill` | Finance (`components/shared/status-pill.tsx`), domains `sales_quotation`/`sales_order`/`invoice`/`receipt`/`credit_note` per `SALES.md`'s `STATUS_TABLES` additions | Document status throughout |
| `DataTable` | Finance (`components/shared/data-table.tsx`) | Recent Documents' per-type view, bound to the same `resource` each dedicated list screen uses |
| `ConfidenceBadge` | AI (`components/ai/confidence-badge.tsx`) | Every AI-sourced figure: forecast chip, upsell summary, credit exposure rows, pending-review cards, fraud card |
| `AIProposalPanel` | AI (`components/ai/ai-proposal-panel.tsx`), rendered `compact` | Pending-review cards (invoice/receipt drafts, collections nudges) |
| `ApprovalCard` | Shared (`components/shared/approval-card.tsx`), `kind="ai_recommendation"` | Fraud/duplicate step-up and credit-blocked orders awaiting the viewer's own sign-off |
| `Sheet` | Primitive (Radix side-dialog) | Recent Documents row-click quick view |
| `DropdownMenu` | Primitive | `SalesQuickCreateMenu`'s options list, permission-filtered |
| `Dialog` | Primitive | Each `@modal/(.)…/new` intercepted quick-create form |
| `Skeleton`, `EmptyState`, `ErrorState` | Shared | Per-region loading/empty/error rendering — see **States** |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` | Wraps the KPI band, every Quick Create item, the AI rail, and every AI action button |
| `SalesPipelineKpiBand` | `components/sales/sales-pipeline-kpi-band.tsx` (per `SALES.md`) | Composes the four `KpiTile`s from one dashboard response |
| `SalesQuickCreateMenu` | `components/sales/sales-quick-create-menu.tsx` (per `SALES.md`) | The `+ New` split button, each item permission-checked |
| `RecentSalesDocumentsPanel` | `components/sales/recent-sales-documents-panel.tsx` (per `SALES.md`) | Segmented control plus the feed/`DataTable` swap |
| `SalesDocumentsFeed` | `components/sales/sales-documents-feed.tsx` (per `SALES.md`) | The merged, cross-table "All" preview |
| `SalesDocumentTypeBadge` | `components/sales/sales-document-type-badge.tsx` (per `SALES.md`) | Icon+label chip distinguishing a row's document type |
| `SalesAiInsightsRail` | `components/sales/sales-ai-insights-rail.tsx` (per `SALES.md`) | Composes the forecast chip, the two new summary components below, pending-review cards, and step-up cards |
| `SalesUpsellSummaryCard` | **New** — `components/sales/sales-upsell-summary-card.tsx` | This document's concrete elaboration of the rail's upsell/cross-sell section |
| `SalesCreditExposureList` | **New** — `components/sales/sales-credit-exposure-list.tsx` | This document's concrete elaboration of the rail's credit-warnings section |

## `SalesUpsellSummaryCard` — new component this document introduces

`SALES.md`'s own Roles table already notes that a Sales Employee "sees AI recommendations addressed
to them (upsell/cross-sell prompts surfaced upstream in the quote builder)" — this document supplies
the Hub-level rendering that statement presupposes but never specified. The ranked candidate list
itself lives exactly where `SALES.md` says it does: inside the quote builder, computed on demand by
`GET /api/v1/sales/ai/quotations/{id}/upsell-candidates` (`docs/ai/agents/SALES_AGENT.md → Tools &
API Access`). This Hub only ever shows a **portfolio-wide rollup** — a count plus a short preview —
of the same `upsell_line`/`cross_sell_line` rows the Approval Assistant has already written to
`ai_recommendations` across every open quotation the viewer can read, and a click always hands off
into that specific quotation's own builder rather than trying to let a line be accepted from the Hub:

```tsx
// components/sales/sales-upsell-summary-card.tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { Card } from '@/components/ui/card';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { Sparkles } from 'lucide-react';
import Link from 'next/link';
import { aiSalesKeys } from '@/lib/api/query-keys';

export function SalesUpsellSummaryCard() {
  const { data, isPending } = useQuery({
    queryKey: aiSalesKeys.upsellSummary(),
    queryFn: () =>
      apiClient.get('/api/v1/sales/ai/recommendations', {
        params: { recommendation_type: 'upsell_line,cross_sell_line', status: 'pending', per_page: 3, sort: '-confidence' },
      }),
    staleTime: 10_000,
  });

  if (isPending) return <RailSectionSkeleton rows={2} />;
  const rows = data?.data ?? [];
  if (rows.length === 0) return null; // section collapses to zero height, see Layout & Regions

  return (
    <Card padding="sm" className="space-y-2">
      <p className="text-caption font-medium text-ink-500 uppercase tracking-wide">Upsell & cross-sell</p>
      <Link href="/sales/quotations?filter[has_ai_upsell]=true" className="flex items-center gap-2 text-sm hover:text-accent-600">
        <Sparkles className="h-3.5 w-3.5" />
        {data.meta.pagination.total} open quotation{data.meta.pagination.total === 1 ? '' : 's'} with a ranked upsell line
      </Link>
      <ul className="space-y-1.5">
        {rows.map((rec) => (
          <li key={rec.id}>
            <Link
              href={`/sales/quotations/${rec.recommendable_id}?tab=ai`}
              className="flex items-center justify-between gap-2 text-caption text-ink-700 hover:text-accent-600"
            >
              <span className="truncate">{rec.payload.customer_name_en} · {rec.payload.candidate_product_name_en}</span>
              <ConfidenceBadge confidence={normalizeConfidence(rec.confidence, 'fraction')} size="sm" showLabel={false} />
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  );
}
```

Accepting an upsell/cross-sell line never happens from this card — there is deliberately no "Do it"
or "Accept" button here, only a navigation link, because the Autonomy Level `docs/ai/agents/
SALES_AGENT.md` assigns this action ("Suggest an upsell/cross-sell line — Suggest-only — Dismissible
card; accepting adds the line through the normal quote-editing path, re-running pricing/tax/
approval") is defined in terms of the quote builder's own line-editing flow, which this rollup does
not reimplement. Adding the line, once a rep opens the linked quotation, uses the same
`sales.quotation.update` permission the builder's manual line-add already requires per `docs/
accounting/SALES.md`'s API endpoint table — this document does not gate it a second, Hub-specific
way.

## `SalesCreditExposureList` — new component this document introduces

The Sales Agent writes two structurally different kinds of credit signal, and this document is
deliberately explicit about not conflating them, because they carry different weight and different
controls:

1. **Advisory** — a `credit_precheck_warning` recommendation (Approval Assistant, informed by the
   CFO Agent's `credit_risk_tier`), raised while a quotation is still being built, well before any
   gate would block it. Nothing is blocked; the card is purely a heads-up.
2. **Deterministic gate** — `sales_order.credit_blocked`, raised only at order confirmation, when
   `customers.outstanding_ar_balance + this order's total_amount` actually exceeds
   `customers.credit_limit` (`docs/accounting/SALES.md`'s confirmation-gate business rule). This is
   never AI — it is plain Laravel arithmetic the Sales Agent's advisory only ever anticipates.

`SalesCreditExposureList` renders only the first kind — the softer, pre-block advisories — as a
compact list; the second kind was already fully specified by `SALES.md` as the rail's "Needs
approval" `ApprovalCard` and is unchanged here.

```tsx
// components/sales/sales-credit-exposure-list.tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { Card } from '@/components/ui/card';
import { AmountCell } from '@/components/accounting/amount-cell';
import { AlertCircle } from 'lucide-react';
import Link from 'next/link';
import { aiSalesKeys } from '@/lib/api/query-keys';

export function SalesCreditExposureList() {
  const { data, isPending } = useQuery({
    queryKey: aiSalesKeys.creditExposure(),
    queryFn: () =>
      apiClient.get('/api/v1/sales/ai/recommendations', {
        params: { recommendation_type: 'credit_precheck_warning', status: 'pending', per_page: 5, sort: '-confidence' },
      }),
    staleTime: 10_000,
  });

  if (isPending) return <RailSectionSkeleton rows={2} />;
  const rows = data?.data ?? [];
  if (rows.length === 0) return null;

  return (
    <Card padding="sm" className="space-y-2">
      <p className="text-caption font-medium text-ink-500 uppercase tracking-wide">Credit exposure</p>
      <ul className="space-y-1.5">
        {rows.map((rec) => (
          <li key={rec.id}>
            <Link
              href={`/sales/customers/${rec.payload.customer_id}`}
              className="flex items-start gap-2 text-caption hover:text-accent-600"
            >
              <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning-600" />
              <span>
                {rec.payload.customer_name_en} — {rec.payload.exposure_pct}% of{' '}
                <AmountCell amount={rec.payload.credit_limit} currencyCode="KWD" size="sm" /> limit
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  );
}
```

Neither new component introduces a new API endpoint. Both call the exact same
`GET /api/v1/sales/ai/recommendations` list endpoint the rail's "Pending review" section already
uses (per `SALES.md → Data & State`), filtered by a different `recommendation_type` — consistent
with `FRONTEND_ARCHITECTURE.md`'s Principle 1: a presentational grouping is a client-side
composition decision, never a reason to grow new API surface.

# Data & State

## Endpoints

Every figure and every row on this screen resolves to an endpoint `docs/accounting/SALES.md` or
`docs/ai/agents/SALES_AGENT.md` already defines; this screen introduces zero business endpoints.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Pipeline KPI band | `GET /api/v1/sales/dashboard` | `sales.report.read` | See worked example below |
| Recent Documents (5 sources) | `GET /api/v1/sales/{quotations\|orders\|invoices\|receipts\|credit-notes}?sort=-created_at&per_page=5` (feed) or the full paginated form (tab) | `sales.{type}.read` | Per-type, see `SALES.md → SalesDocumentsFeed` |
| Quick Create (5 forms) | `POST /api/v1/sales/{quotations\|orders\|invoices\|receipts\|credit-notes}` | `sales.{type}.create` | Every submission carries a client-generated `Idempotency-Key`, per Principle 9 |
| Forecast chip | `GET /api/v1/sales/ai/forecast` | `sales.ai.read` | P10/P50/P90 revenue range; refreshed nightly, see worked example below |
| Upsell/cross-sell summary | `GET /api/v1/sales/ai/recommendations?recommendation_type=upsell_line,cross_sell_line&status=pending&per_page=3&sort=-confidence` | `sales.ai.read` | Same list endpoint as Pending Review, different filter — see **Components Used** |
| Credit exposure summary | `GET /api/v1/sales/ai/recommendations?recommendation_type=credit_precheck_warning&status=pending&per_page=5&sort=-confidence` | `sales.ai.read` | Same list endpoint, different filter |
| Pending-review recommendations | `GET /api/v1/sales/ai/recommendations?recommendation_type=invoice_draft,receipt_draft,collections_nudge&status=pending&per_page=5&sort=-created_at` | `sales.ai.read` | Grouped into cards client-side by `recommendation_type` |
| Accept a recommendation | `POST /api/v1/sales/ai/recommendations/{id}/accept` | `sales.ai.approve_draft` **+** the resource's own create/update permission | Materializes through the module's ordinary validated endpoint, under the accepting user's own session |
| Dismiss / acknowledge a recommendation | `POST /api/v1/sales/ai/recommendations/{id}/dismiss` | `sales.ai.read` | Used identically for a pending-review card, an upsell rollup row, and a credit exposure row |
| Fraud/credit approval | Routes to `/approvals/{id}` or an inline `ApprovalCard` calling `POST /api/v1/sales/orders/{id}/confirm` after an override is recorded | `sales.order.confirm`, `sales.credit.override` | The Hub never resolves the block itself |

**Worked example — pipeline KPI band**, reproduced from `docs/accounting/SALES.md → Reports` so this
screen's own `SalesPipelineKpiBand` implementation can be checked against it directly:

```json
GET /api/v1/sales/dashboard
X-Company-Id: 41

{
  "success": true,
  "data": {
    "today_revenue_base_currency": "12480.5000",
    "open_pipeline_value": "48200.0000",
    "open_pipeline_weighted_value": "19680.0000",
    "orders_awaiting_approval": 4,
    "overdue_invoices_count": 11,
    "overdue_invoices_amount": "9340.2500",
    "as_of": "2026-07-16T12:00:00Z"
  },
  "message": "Dashboard metrics for company 41.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "4f5a6b7c-8d9e-0f1a-2b3c-4d5e6f7a8b9c",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

**Worked example — forecast chip:**

```json
GET /api/v1/sales/ai/forecast
X-Company-Id: 41

{
  "success": true,
  "data": {
    "scope": { "branch_id": null, "channel": null, "product_category_id": null },
    "period": "2026-07",
    "p10": "180000.0000",
    "p50": "210000.0000",
    "p90": "250000.0000",
    "model_confidence": 0.740,
    "reasoning": "Time-series + seasonality model over 24 months of invoices.total_amount, pipeline-weighted by open quotations/orders in the current period; WAPE 8.4% over the trailing 6 months.",
    "generated_at": "2026-07-16T02:00:00Z",
    "stale": false
  },
  "message": "Revenue forecast for company 41.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

**Worked example — upsell/cross-sell summary** (the same shape `SalesUpsellSummaryCard` consumes):

```json
GET /api/v1/sales/ai/recommendations?recommendation_type=upsell_line,cross_sell_line&status=pending&per_page=3&sort=-confidence
X-Company-Id: 41

{
  "success": true,
  "data": [
    {
      "id": 991820,
      "recommendable_type": "sales_quotations",
      "recommendable_id": 55210,
      "agent_name": "sales_agent",
      "recommendation_type": "cross_sell_line",
      "payload": {
        "customer_id": 1180,
        "customer_name_en": "Bu Ftaira Trading",
        "candidate_product_id": 340,
        "candidate_product_name_en": "Filing cabinet, 4-drawer",
        "affinity_score": 0.81
      },
      "confidence": 0.810,
      "reasoning": "72% of customers who bought this desk model in the last 12 months also purchased this filing cabinet within 30 days.",
      "supporting_document_ids": [55088, 55112, 55140],
      "status": "pending"
    }
  ],
  "message": "Pending upsell/cross-sell recommendations.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 3, "total": 5, "cursor": null } },
  "request_id": "1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

**Worked example — credit exposure summary** (the same shape `SalesCreditExposureList` consumes),
reusing the exact customer and figures `docs/ai/agents/SALES_AGENT.md`'s own conversational example
cites:

```json
GET /api/v1/sales/ai/recommendations?recommendation_type=credit_precheck_warning&status=pending&per_page=5&sort=-confidence
X-Company-Id: 41

{
  "success": true,
  "data": [
    {
      "id": 991702,
      "recommendable_type": "sales_quotations",
      "recommendable_id": 9931,
      "agent_name": "sales_agent",
      "recommendation_type": "credit_precheck_warning",
      "payload": {
        "customer_id": 1042,
        "customer_name_en": "Al Rayyan Trading",
        "outstanding_ar_balance": "4105.0000",
        "credit_limit": "6000.0000",
        "projected_ar_if_accepted": "5940.4000",
        "exposure_pct": 99
      },
      "confidence": 0.910,
      "reasoning": "Current outstanding_ar_balance KWD 4,105.000 (3 open invoices) + this quotation's total_amount KWD 1,835.400 would bring exposure to 99% of the KWD 6,000.000 limit.",
      "supporting_document_ids": [9931, 71180, 71204, 71299],
      "status": "pending"
    }
  ],
  "message": "Pending credit exposure warnings.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 5, "total": 1, "cursor": null } },
  "request_id": "2b3c4d5e-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

## Query keys and hooks

Extends `SALES.md`'s own `salesKeys`/`aiSalesKeys` factories rather than introducing a parallel one:

```ts
// lib/api/query-keys.ts (additive to the factories SALES.md already defines)
export const aiSalesKeys = {
  recommendations: (limit: number) => ['ai', 'sales', 'recommendations', { limit }] as const,
  forecast: () => ['ai', 'sales', 'forecast'] as const,
  upsellSummary: () => ['ai', 'sales', 'recommendations', 'upsell-summary'] as const,
  creditExposure: () => ['ai', 'sales', 'recommendations', 'credit-exposure'] as const,
};
```

```ts
// hooks/use-sales-dashboard.ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { salesKeys } from '@/lib/api/query-keys';
import type { SalesDashboard } from '@/types/sales';

export function useSalesDashboard() {
  return useQuery({
    queryKey: salesKeys.dashboard(),
    queryFn: () => apiClient.get<SalesDashboard>('/api/v1/sales/dashboard'),
    staleTime: 0, // always stale — correctness over avoiding a refetch, kept fresh via realtime invalidation
  });
}

// hooks/use-sales-ai-rail.ts
export function useSalesForecast() {
  return useQuery({
    queryKey: aiSalesKeys.forecast(),
    queryFn: () => apiClient.get('/api/v1/sales/ai/forecast'),
    staleTime: 900_000, // 15 min — matches the nightly refresh cadence, served with its own generated_at
  });
}

export function useSalesUpsellSummary() {
  return useQuery({
    queryKey: aiSalesKeys.upsellSummary(),
    queryFn: () =>
      apiClient.get('/api/v1/sales/ai/recommendations', {
        params: { recommendation_type: 'upsell_line,cross_sell_line', status: 'pending', per_page: 3, sort: '-confidence' },
      }),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
}

export function useSalesCreditExposure() {
  return useQuery({
    queryKey: aiSalesKeys.creditExposure(),
    queryFn: () =>
      apiClient.get('/api/v1/sales/ai/recommendations', {
        params: { recommendation_type: 'credit_precheck_warning', status: 'pending', per_page: 5, sort: '-confidence' },
      }),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
}
```

## Cache tuning by data class

Follows `FRONTEND_ARCHITECTURE.md`'s data-class table exactly:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Live/derived figures | `salesKeys.dashboard()` | `0` (always stale) | Correctness over avoiding a refetch; kept fresh via realtime invalidation |
| Transactional lists | `salesKeys.recentDocuments(*)` and the five per-type list keys | `30_000` | Frequent enough writes (a POS channel can create and invoice in one request) that a stale list is a real annoyance |
| AI feeds (pending review, upsell summary, credit exposure) | `aiSalesKeys.recommendations`, `.upsellSummary()`, `.creditExposure()` | `10_000`, `refetchOnWindowFocus: true` | Matches `FRONTEND_ARCHITECTURE.md`'s AI-feed row verbatim |
| Nightly-recomputed reference | `aiSalesKeys.forecast()` | `900_000` (15 min), served with its own `generated_at` | No benefit polling faster than the nightly source; the chip always shows when it was generated |

## Realtime

The exact channels `SALES.md → Realtime` already defines — this screen adds no new subscription:

| Channel | Events carried | Effect on this screen |
|---|---|---|
| `private-company.{id}.sales` | `sales_quotation.sent`, `sales_order.confirmed`, `sales_order.credit_blocked`, `invoice.posted`, `invoice.overdue`, `payment.received`, `receipt.bounced`, `credit_note.issued` | Invalidates `salesKeys.dashboard()` and the relevant per-type/recent-documents keys as a full `invalidateQueries` call — a KPI figure is never patched optimistically from a realtime push |
| `private-company.{id}.ai-jobs` | Any `ai_recommendations` row authored by `sales_agent` (including `upsell_line`, `cross_sell_line`, `credit_precheck_warning` rows this document's two new components consume), or a completed `forecast_refresh`/`collections_sweep`/`fraud_rescan` `ai_tasks` sweep | Invalidates `aiSalesKeys.recommendations`, `.upsellSummary()`, and `.creditExposure()` together, since all three read from the same underlying table filtered differently — one event can affect more than one rail section at once |
| `private-company.{id}.approvals` | New/updated `approval_requests` rows scoped to a Sales document | Refreshes the "Needs approval" section and the Topbar's approvals badge |
| `private-company.{id}.notifications.{user_id}` | `sales_order.credit_blocked`, `receipt.bounced` | Feeds the Topbar's notification bell only, per `DASHBOARD.md`'s identical rule |

`sales_order.credit_blocked` and a new fraud-score `ai_recommendations` row patch the affected
`ApprovalCard` in place via `setQueryData`, mirroring `docs/frontend/BANKING.md`'s identical
treatment of a fraud hold arriving while its card is already open. A new `credit_precheck_warning` or
`upsell_line` row, by contrast, goes through an ordinary `invalidateQueries` on its own summary key —
these are advisory list rows, not a single card a user could already be mid-decision on, so there is
no in-place patch to preserve. On WebSocket reconnect after any extended drop, every realtime-fed
query key on this screen is invalidated once, as a single batch, per `SALES.md`'s identical rule.

## AI agents feeding this screen

| Agent | Contribution to this screen |
|---|---|
| `sales_agent` (Sales Agent) | Orchestrates every recommendation and forecast this screen's rail renders |
| Forecast Agent | Computes the statistical model behind the forecast chip; the Sales Agent packages it into the P10/P50/P90 range shown here |
| Approval Assistant | Ranks upsell/cross-sell candidates per quotation and decides *when* to surface one (never mid-negotiation, always at a natural pause, per `docs/ai/agents/SALES_AGENT.md → Reasoning & Prompt Strategy`) — the portfolio rollup this screen shows is a read of its output, not a re-ranking |
| CFO Agent | Supplies the `credit_risk_tier` signal that feeds a `credit_precheck_warning`'s framing; never changes `customers.credit_limit` itself |
| Fraud Detection | Scores every quotation-acceptance and order-confirmation attempt in real time; a score at or above the company's configured threshold (default 70/100) produces the "Needs approval" `ApprovalCard` |
| General Accountant | Runs a GL account-mapping sanity check on every `invoice_draft`/`receipt_draft` before the Sales Agent surfaces it |

# Interactions & Flows

**Opening the screen.** The shell (sub-nav, header, permission-gated Quick Create menu) paints
immediately; the KPI band resolves within one Redis-cache round trip, since `GET /api/v1/sales/
dashboard` is prefetched server-side in `page.tsx` exactly like `docs/frontend/BANKING.md`'s own
cash-position bundle. Recent Documents and the AI rail stream in independently a beat later — no
region blocks another (see **Data & State**, **Performance**).

**Switching the Recent Documents tab.** Clicking Quotations/Orders/Invoices/Credit Notes/Receipts
swaps `RecentSalesDocumentsPanel`'s internal state from the merged `SalesDocumentsFeed` to a real
`<DataTable resource="sales/{type}" defaultSort="-created_at" searchable />` — a client-side resource
swap, not a navigation. "All" is the default on every fresh visit, never persisted per user, because
the Hub's purpose is a fast morning scan across everything, not a remembered preference for one type.

**Using Quick Create.** Selecting an item from `SalesQuickCreateMenu` navigates to that type's
canonical `new` route; because the click originates from within this already-rendered page, Next.js's
intercepting-route convention renders the matching `@modal/(.)…/new/page.tsx` as a `Dialog` instead of
a full navigation. A hard refresh, bookmark, or shared link to the same URL always renders the full
page — the modal is a presentational convenience over one canonical route and one canonical form,
never a second implementation. None of the five targets is on the platform's "always full-page, never
a modal" list (`bank.transfer`, `payroll.approve`, `tax.submit`, a permission change, a deletion) —
creating a **draft** is reversible and pre-commitment; the genuinely consequential actions on each
document (confirming an order, posting an invoice, allocating a receipt, posting a credit note) remain
separate, deliberate steps on that document's own detail screen.

**Clicking a KPI tile.** Today's Revenue → `/accounting/financial-statements/profit-and-loss?
range=today`; Open Pipeline → `/sales/quotations?filter[status][in]=sent,accepted&sort=-total_amount`;
Orders Awaiting Approval → `/sales/orders?filter[status]=pending_approval`; Overdue AR →
`/sales/invoices?filter[status]=overdue&sort=-due_date`. A KPI click never opens a modal over the Hub
— a number always means "go look at the source."

**Clicking a Recent Documents row.** In the "All" feed, a row click opens a `Sheet` quick view with an
"Open full record" link; in a per-type tab, the row click follows `DataTable`'s own `onRowClick`
contract identically to the dedicated list screen it mirrors.

**Reviewing the upsell/cross-sell summary.** Clicking the header link ("5 open quotations have a
ranked upsell line") navigates to `/sales/quotations?filter[has_ai_upsell]=true`, a pre-filtered
Quotations tab, not a modal or an inline expansion — this rollup is a scan-and-jump surface, never a
place to accept a line. Clicking an individual preview row (`{customer} · {candidate product}`) opens
that specific quotation directly, landing on its own "AI" tab where the full ranked candidate list and
its Accept/Dismiss affordances live, per `docs/ai/agents/SALES_AGENT.md`'s placement of this feature
inside the quote builder. A rep who dismisses a candidate from within the quotation removes it from
this rollup on the next `ai-jobs` invalidation; dismissing is never available from the rollup card
itself, which has no per-row action beyond the link.

**Reviewing the credit exposure list.** Clicking a row (e.g. "Al Rayyan Trading — 99% of KD 6,000.000
limit") opens that customer's own record, where the full AR aging and open-document breakdown behind
the percentage lives. This is a read-only, informational surface; there is no Approve/Reject here
because nothing is blocked — only the deterministic `sales_order.credit_blocked` gate, rendered
separately as an `ApprovalCard`, carries an approval decision. A user with `sales.ai.read` only can
still dismiss a stale advisory (e.g. the underlying quotation was since rejected) via the row's own
overflow action, calling `POST .../dismiss` with an optional reason.

**Acting on a pending-review card.** An `AIProposalPanel` renders Accept, Send for approval, and
Dismiss — never a one-click "Do it," because no Sales Agent action reaching this rail is configured
`auto` today (`docs/ai/agents/SALES_AGENT.md`'s Autonomy Level table). Accepting an `invoice_draft` or
`receipt_draft` opens that document in `draft` status on its own detail screen for a final look before
the human separately posts it — the Hub never posts anything on the agent's behalf.

**Resolving a fraud step-up or a credit-blocked order.** A "Needs approval" `ApprovalCard` links to
that order's own confirmation flow; Approve/Reject is available only to a viewer holding the relevant
permission, and rejecting always requires a reason. The Hub never resolves the block itself.

**Searching from the header.** The `Search…` box is a records-only search scoped to `sales/*`
resources, fanning out to the same per-resource `/search` endpoints the Command Palette's Records
group calls, filtered by permission before firing.

# AI Integration

This screen's AI surface is the Sales Agent's condensed home — the same relationship `DASHBOARD.md`'s
AI Summary Rail has to the full AI Command Center, applied to one module. Every element obeys the
platform-wide AI contract (`FRONTEND_ARCHITECTURE.md`'s Principle 3 and AI Integration Layer) in full,
concretely applied to each of the four signal types this rail carries:

**Forecast.** Always a range, never a point estimate — "KD 180,000 – KD 250,000, P50 KD 210,000,"
matching `docs/ai/agents/SALES_AGENT.md`'s explicit rule that "a single point estimate is never
presented as a system output." Refreshed nightly by the Sales Agent's own `ai_tasks` sweep
(`task_type = 'forecast_refresh'`); a cache miss serves the last-cached payload with `stale: true`
rather than blocking the page on an LLM call. Clicking the chip opens `/ai/forecast` scoped to Sales
rather than expanding inline. Forecast accuracy is tracked as WAPE against realized
`invoices.total_amount` for the same scope/period, per `docs/ai/agents/SALES_AGENT.md`'s own metric.

**Upsell & cross-sell.** Autonomy is `suggest_only`, unconditionally — the Autonomy Level table's own
row reads "Dismissible card; accepting adds the line through the normal quote-editing path, re-running
pricing/tax/approval." This Hub renders only the portfolio rollup (**Components Used**); the ranked,
per-quotation candidate list — 1–3 candidates with an affinity rationale, per the Approval Assistant's
own contribution table — lives in the quote builder, and the Approval Assistant chooses *when* to
interrupt a rep with a candidate ("never mid-negotiation-call, always at natural pause points"), a
timing decision this Hub does not second-guess or duplicate. Confidence below 0.50 is suppressed
rather than shown as a precise-looking percentage, per the Sales Agent's confidence-banding rule;
0.50–0.85 renders as an editable suggestion; above 0.85 still requires the same one click.

**Credit warnings — two signals, never conflated.** The `credit_precheck_warning` recommendation
(this rail's "Credit exposure" section) is a suggest-only advisory the CFO Agent's `credit_risk_tier`
and the customer's live `outstanding_ar_balance` jointly inform — it exists to give a rep a heads-up
"well before the deterministic credit/stock gates at order confirmation would otherwise block them
cold" (`docs/ai/agents/SALES_AGENT.md`'s own stated purpose for this feature). The
`sales_order.credit_blocked` gate, by contrast, is never AI — it is the exact arithmetic `docs/
accounting/SALES.md`'s confirmation-gate business rule fixes (`outstanding_ar_balance + this order's
total_amount` vs. `credit_limit`), and it is rendered as an `ApprovalCard`, not a rollup row, because
resolving it is a real, audited decision (`sales.credit.override`) that a rollup row's lack of
approval controls would misrepresent. The one configurable automation adjacent to either signal —
`auto_apply_risk_tier` — only ever changes a customer's risk *tier label*; it never touches
`credit_limit` itself, and this screen never renders a control for that company setting, which lives
in Customer/Company settings, not here.

**Fraud/duplicate step-up.** A "Needs approval" card is the one place this rail departs from an
otherwise accent-only, calm visual language, exactly as `DASHBOARD.md` reserves its one Error-Red
left-border treatment for a confirmed fraud hold. **Worked example**, reproducing `docs/ai/agents/
SALES_AGENT.md`'s Scenario 3 verbatim so this screen's behavior is independently checkable: a new
POS-channel customer record is created, and three orders are placed in the next four minutes, each
individually just under the company's KWD 500 no-approval retail threshold, each paying with a
different card. On the third order's confirmation attempt, Fraud Detection returns a score of
`82/100`, drivers `["3 orders from a customer created <15 min ago", "each order individually just
under the approval threshold", "3 distinct payment cards on one new customer within 4 minutes"]`.
Because `82 ≥ 70`, the order is forced into `pending_approval` regardless of its own value being
under the retail auto-approval threshold, and a "Needs approval" card appears in this rail, addressed
to the Sales Manager role. The card is not dismissible from the Hub; it can only be resolved from the
order's own confirmation flow or the Approval Center, so a user cannot swipe away a held order without
seeing why. (`SALES.md`'s own illustrative rail wireframe shows a separate, similarly-shaped example —
order `SO-KWC-2026-08902` at 78/100 — which this document treats as a second, independent
illustration of the same feature, not the same event as Scenario 3's 82/100 case.)

**Grounding is enforced upstream, not re-verified here.** Every number this rail displays already
passed the Sales Agent's Ground & Validate node before being persisted to `ai_recommendations`; this
screen trusts and renders `supporting_document_ids` as clickable references rather than re-deriving
the claim itself, per the platform-wide rule that the frontend never recomputes what the API/AI layer
already validated.

**The three-button pattern, applied concretely.** Per `FRONTEND_ARCHITECTURE.md`'s "three-button
pattern," which of Accept/Send-for-approval/Dismiss renders for a given card is computed server-side
(the response's own `can_execute_directly`-equivalent field), never guessed at by the client. For this
rail specifically: a pending-review card (`invoice_draft`, `receipt_draft`, `collections_nudge`)
renders all three; the upsell/cross-sell rollup renders only a navigation link (no accept path exists
at the rollup level, per **Interactions & Flows**); the credit exposure rollup renders a navigation
link plus an optional Dismiss; the fraud/credit step-up renders as an `ApprovalCard` with
Approve/Reject/Delegate, never Accept/Dismiss, because it is a gated decision, not a proposal.

# States

Every region owns its own loading, empty, and error presentation; no region blocks another, and none
ever renders a bare spinner as its primary loading state.

| Region | Loading | Empty | Error |
|---|---|---|---|
| KPI band | Skeleton tiles matching the final layout | A brand-new company with no sales activity: "No sales recorded yet — Create your first quotation" | Widget-level `<ErrorBoundary>` renders a small inline "Couldn't load this figure — Retry" per failed tile; other tiles stay interactive |
| Recent Documents — "All" feed | 5–6 skeleton rows matching `SalesDocumentsFeed`'s row height | "No documents yet across quotations, orders, invoices, receipts, or credit notes," linking into Quick Create | A source that fails independently is simply omitted from the merge; if every source fails, one retry card renders, not five |
| Recent Documents — per-type tab | `DataTable`'s own skeleton rows | `DataTable`'s own per-type `emptyState` | `DataTable`'s own `ErrorState`, unaffected by any other region |
| Forecast chip | Skeleton chip | Never empty once the company has ≥30 days of history; before that, "Not enough history yet to forecast" replaces the chip | "AI insights are temporarily unavailable" — muted, not a generic error, respecting any `Retry-After` |
| Upsell & cross-sell summary | 2-row skeleton | Section collapses to zero height — no "no upsell opportunities" card, since this is routine, not a gap to apologize for | Section collapses silently to zero height on error too; it never competes for attention with the KPI band or the credit/fraud sections that do carry real risk |
| Credit exposure list | 2-row skeleton | Section collapses to zero height, same reasoning | Same silent collapse — a transient failure here must never look like "no customers are at risk," so QA and the Playwright suite explicitly assert the loading→error transition never passes through the empty render |
| Pending review cards | Skeleton chip + up to 3 skeleton cards | "You're all caught up — no open recommendations right now," explicitly not styled as an error or a warning | "AI insights are temporarily unavailable" |
| Needs approval (fraud/credit step-up) | Skeleton card | No card renders at all — this section only ever appears when something is actually blocked | Same muted AI-unavailable state; a genuinely blocked order still shows via the ordinary Sales notification path even if this rail's own fetch fails, so nothing is silently lost |
| Quick Create menu | Renders synchronously from session permissions; never shows a loading state | A role with zero create permissions anywhere in Sales collapses to a disabled `+ New` button with a tooltip | Not applicable — no network call until an item is chosen |

The distinction between "collapses to zero height" (upsell/credit exposure sections, when genuinely
empty) and "collapses to zero height on error, too" is deliberate and easy to get wrong in
implementation: a transient `5xx` on `aiSalesKeys.creditExposure()` must never render identically to
"this company has no credit risk today," because the two states carry very different consequences if
misread by a Sales Manager scanning the rail quickly. `SalesCreditExposureList` and
`SalesUpsellSummaryCard` therefore both distinguish `isError` from "loaded, zero rows" internally (see
**Components Used**) even though both visually resolve to "nothing rendered" in the common case — the
error path additionally logs to the client-side telemetry pipeline so a systemic failure is visible in
aggregate even though no individual user sees an alarming empty box. A route-level `error.tsx` remains
the outermost safety net for a genuinely unexpected failure, but per the platform's three-granularity
model this should essentially never fire for an individual region's ordinary `4xx`/`5xx`.

# Responsive Behavior

Following `LAYOUT_SYSTEM.md`'s Dashboard Template responsive table, applied to this screen's regions:

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | Sub-nav collapses into a horizontal-scroll strip; the KPI band becomes a snap-scroll carousel (one tile per screen width); Recent Documents and the AI rail stack full-width, Recent Documents first; `SalesQuickCreateMenu` collapses to a floating `+` icon opening the same permission-filtered list as a bottom `Sheet`; the Sidebar is replaced by the bottom tab bar (see the mobile wireframe in **Layout & Regions**) |
| `md` (768–1023px) | KPI band becomes two tiles per row; Recent Documents and the AI rail remain stacked, single column |
| `lg` (1024–1279px) | KPI band becomes a full row; Recent Documents (8/12) and the AI rail (4/12) activate the side-by-side split |
| `xl`+ (≥1280px) | Same layout, generous margins; the global AI Rail companion panel may dock alongside this page's own rail without competing for the same column |

The per-type `DataTable` instances inside Recent Documents follow the platform's three-tier table
degradation: horizontal scroll with a frozen identifier column at `md`+, column-priority hiding below
`lg`/`xl`, and a card transform only below `sm`. Sales' five document tables default to **Comfortable**
density (`LAYOUT_SYSTEM.md → Density Modes`, which names Invoices explicitly as Comfortable-by-default,
alongside Customers/Vendors/Products) rather than Compact, since these are lower-row-count, more
varied-per-row screens than a Journal Entries ledger; density changes row height and padding only,
never font size, per that document's hard rule. The merged "All" feed has no density toggle of its
own — it always renders at a single, fixed comfortable row height, since it is a preview, never a
working ledger view. On mobile, the upsell and credit-exposure sections render as ordinary stacked
list items within the single-column AI rail, with no special carousel treatment of their own — they
are text-and-badge-heavy, not chart-heavy, and read fine at full width down to 320px.

`KpiTile` and this screen's composed components use Tailwind v4 container queries (`@container`), not
only viewport breakpoints, matching `DASHBOARD.md`'s identical pattern. Touch targets on the Quick
Create menu, every Recent Documents row, and every AI card's action buttons maintain the platform's
44×44px minimum hit area below `md`, implemented once in the shared `IconButton`/`Button` components.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and `LAYOUT_SYSTEM.md`'s RTL contract:

- **Logical properties only.** The sub-nav order, KPI tile order, Quick Create menu order, and every
  Recent Documents/AI-rail row use `ms-*`/`me-*`/`text-start`/`text-end` exclusively; flipping
  `dir="rtl"` mirrors the whole page — including the Recent Documents/AI rail 8/4 split — with zero
  screen-specific RTL code.
- **Numerals, currency codes, and document/customer identifiers never mirror.** Every `KpiTile`
  value, every `AmountCell`, and every document number (`QT-KWC-2026-00512`) renders inside a
  `dir="ltr"` / `unicode-bidi: isolate` span, exactly as it would inside an otherwise-Arabic sentence
  such as an AI reasoning string citing an amount.
- **Numeric alignment is physically fixed (Exception A).** Every amount column in the per-type
  `DataTable` instances uses hard-coded `text-right`, not `text-end`, in both directions.
- **Charts never mirror (Rule 5).** The Today's Revenue `TrendSparkline` and the forecast band's
  underlying P10/P50/P90 axis keep a fixed left-to-right time/probability axis in both directions.
- **Bilingual data throughout.** Every row's cited customer name renders `name_en`/`name_ar` per the
  active locale — including the upsell summary's `customer_name_en`/`candidate_product_name_en` and
  the credit exposure list's `customer_name_en`, which the API always returns bilingually regardless
  of `Accept-Language`; the client, not the server, owns which language displays.
- **Directional icons mirror; status/type/alert icons never do.** `SalesDocumentTypeBadge`'s icon set
  and `SalesCreditExposureList`'s `AlertCircle` glyph never flip; the sub-nav's overflow chevron does.

| Context | English | Arabic |
|---|---|---|
| Rail section | Upsell & cross-sell | البيع الإضافي والمتقاطع |
| Rail section | Credit exposure | التعرض الائتماني |
| Upsell summary link | 5 open quotations have a ranked upsell line | 5 عروض أسعار مفتوحة لديها اقتراح بيع إضافي مرتب |
| Credit exposure row | Al Rayyan Trading — 99% of KD 6,000.000 limit | مؤسسة الريان التجارية — 99% من حد 6,000.000 د.ك |
| Fraud step-up | Needs approval — risk score 82/100. | يتطلب اعتمادًا — درجة المخاطر 82/100. |
| Empty rail section | You're all caught up — no open recommendations right now. | لا توجد توصيات مفتوحة حاليًا — كل شيء تحت السيطرة. |

Arabic copy is authored directly by a fluent professional-register writer, never machine-translated,
reusing `docs/accounting/SALES.md`'s own bilingual terminology consistently — عرض سعر for quotation,
أمر بيع for sales order, فاتورة for invoice, إيصال for receipt, إشعار دائن for credit note, حد
ائتماني for credit limit — the same terms this screen's type badges, tab labels, and credit exposure
copy use throughout rather than approximating.

# Dark Mode

This screen introduces no new color, elevation, or radius token — every surface resolves through the
same `:root[data-theme="dark"]` remap `COMPONENT_LIBRARY.md` defines, following its token naming
(`--qayd-ink-950`…`--qayd-ink-0`, `--qayd-accent-700/600/500/100`) throughout, for the same reason
`SALES.md` gives for making that same choice: every component this screen composes is implemented
using exactly that scale, and describing them with `DESIGN_LANGUAGE.md`'s newer `ink-1`…`ink-12`
naming here would describe code that does not match what those components actually ship.

- KPI tiles and cards use `bg-surface`/`bg-ink-100` in light mode and their dark-remapped
  equivalents; elevation gets *lighter*, not darker, in dark mode, matching the platform's "physical
  light" strategy.
- The `SalesCreditExposureList`'s `AlertCircle` icon reads its color from `--qayd-warning-600`, never
  a hard-coded hex, so it lifts to its dark-tuned equivalent automatically — and stays visually
  distinct from the fraud step-up card's `danger`-toned left border in both themes, since a soft
  advisory and a hard block must never look like the same severity.
- Sales document status tones (`neutral`/`accent`/`success`/`warning`/`danger`) resolve through the
  same tokens in both themes, remapped for contrast, never a separate dark-only color choice.
- Every Storybook story for `SalesUpsellSummaryCard` and `SalesCreditExposureList` ships the
  platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`),
  and this screen's Playwright suite captures the same four-way screenshot set at the route level.

# Accessibility

Targets WCAG 2.1 AA as a floor, identically in both languages and both themes:

- **Landmark structure.** Each region is a real landmark: a visually-hidden `<h2>` labels the KPI
  band, Recent Documents, and the AI rail — including each of its five internal sections — for
  screen-reader navigation even where the sighted design omits a visible heading.
- **Keyboard path.** Sub-nav tabs → Quick Create menu → KPI band (one focusable stop per tile) →
  Recent Documents' segmented control → its rows/cells → the AI rail's forecast chip → upsell summary
  link → credit exposure rows → pending-review cards and their action buttons → any step-up card. No
  region requires a mouse to reach or activate any control.
- **Realtime updates announce politely, never assertively.** A new row in Recent Documents, a KPI
  tick, a new upsell/credit-exposure row, or a new pending-review card uses `aria-live="polite"`
  exclusively — none of this screen's realtime feeds ever interrupt whatever a screen-reader user is
  currently reading.
- **Sparklines and the forecast band carry a mandatory `aria-label`.** The Today's Revenue tile's
  inline `TrendSparkline` may be `aria-hidden="true"` only where an adjacent text delta already states
  the trend in words.
- **Color is never the only channel.** Every `StatusPill` pairs a leading dot with a text label; the
  credit exposure list's `AlertCircle` is paired with the explicit percentage-and-limit text, not a
  color alone; the fraud step-up card's red border is paired with an explicit "Needs approval" label
  and an `AlertTriangle` icon.
- **Permission-gated controls are legible, not mysterious.** A Quick Create item whose permission is
  absent is omitted entirely; an `ApprovalCard`'s Approve/Reject that is visible-but-disabled because
  the viewer lacks the specific permission always carries a tooltip naming it (e.g. "Requires
  `sales.credit.override`"), never a silently greyed-out button.
- **Focus management on drill-down.** Clicking a KPI tile or a per-type Recent Documents row
  navigates to a new route, so focus lands on the destination's own heading via the platform's
  standard route-change focus reset; the "All" feed's row click and a credit-exposure row's overflow
  menu instead open a `Sheet`/`DropdownMenu`, which traps focus per Radix's own contract and returns
  focus to the triggering element on close.

# Performance

- **Streamed, not blocking.** The KPI band, Recent Documents, and the AI rail are each their own
  `Suspense` boundary; the slowest region (typically the AI rail, if a Sales Agent run is still in
  flight) never delays the KPI band's first paint, and a failure in one region never takes down
  another (`react-error-boundary` per region).
- **Cached at the source.** `GET /api/v1/sales/dashboard` is Redis-cached server-side; a cache miss
  on the deterministic KPI figures recomputes synchronously inline since it is cheap SQL, while a
  cache miss on the AI-authored forecast never blocks the page — it serves the last-cached payload
  with `stale: true` rather than making the user wait on an LLM call.
- **The rail's three list-backed sections are bounded, not open-ended.** Upsell summary, credit
  exposure, and pending review each cap their own request at `per_page` 3–5 and never paginate inline
  — the moment a user wants real depth on any of the three, they follow the section's own link into a
  filtered list screen or the quote builder, which is a genuine, virtualized-above-200-rows surface.
- **The "All" feed's fan-out is bounded.** `SalesDocumentsFeed` issues at most five parallel requests,
  each capped at `per_page=5`, merged and capped again at 12 total rows client-side.
- **Debounced, stable interactions.** The header's records search debounces at 300ms; every per-type
  `DataTable` tab uses `placeholderData: keepPreviousData` so switching tabs or paging dims the
  previous result rather than flashing empty.
- **Realtime patches are the exception, not the rule.** Only a fraud/credit card's in-place
  `setQueryData` patch bypasses a full refetch; every other realtime event on this screen goes through
  an ordinary `invalidateQueries` call, which can never drift from what the server actually holds.
- **Bundle budget.** This route's shell is held to the platform's stated first-load JS budget,
  enforced in CI against `performance-budgets.json`; the AI rail's components load eagerly (small,
  text-and-badge-heavy), while any chart-bearing screen this Hub links out to code-splits its charting
  dependency via `next/dynamic({ ssr: false })`.
- **Web Vitals are tracked against a realistic baseline.** LCP/CLS/INP for this route are tagged with
  company transaction-volume band, so a genuine regression is distinguishable from the expected
  difference between a brand-new company's empty pipeline and a high-volume distributor's thousands
  of open documents.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A company switch fires while the AI rail's four independent queries are still in flight | `queryClient.clear()` runs before `router.refresh()`; any in-flight response is discarded on arrival because its query key no longer exists in the cleared cache; `router.push('/dashboard')` always follows, per the platform's fixed post-switch landing. |
| A role holds `sales.read` but none of the five entity-level `.read` permissions the KPI band or Recent Documents need | The KPI band renders zero tiles if `sales.report.read` is also absent; Recent Documents renders its own empty-region fallback rather than a page-wide access wall. If every Quick Create permission is also absent, that menu collapses to nothing and the AI rail (gated separately on `sales.ai.read`) may be the only region left with content. |
| A `credit_precheck_warning` is still pending in the rail for a quotation that has since been rejected or expired | The row is not removed proactively by client-side date logic — expiry/rejection is a server-side state transition, never a date comparison the UI performs on its own — but the next `ai-jobs` invalidation (which fires on the quotation's own status-change event) or the section's 10-second `staleTime` refetch clears it; a stale row pointing at a now-closed quotation is a brief, self-correcting inconsistency, not a data-integrity problem, since the link always opens the quotation's own current, authoritative state. |
| The same customer has both a pending `credit_precheck_warning` (soft) and an active `sales_order.credit_blocked` (hard) at the same time, for two different documents | Both render — the advisory row in "Credit exposure" for the quotation still being drafted, and the `ApprovalCard` in "Needs approval" for the order that already tripped the deterministic gate — because they describe two different documents at two different lifecycle stages; this screen never collapses them into one, since doing so would hide that one problem is still preventable and the other already requires a decision. |
| A Sales Employee's own quotation carries an upsell/cross-sell recommendation, but they lack `sales.quotation.update` (a narrow custom role) | The summary row still renders (read access via `sales.ai.read` is unaffected), but clicking through to the quotation shows the AI tab's Accept action disabled with a permission tooltip rather than omitted, since the employee legitimately owns the quotation and needs to understand why they cannot add the line themselves. |
| The AI engine (FastAPI layer) is down or returns `503` for an extended period | The KPI band and Recent Documents are entirely unaffected, since neither calls the AI engine. The forecast chip, upsell summary, credit exposure list, and pending-review cards all show the same muted "AI insights are temporarily unavailable" state, never a stale number without its `stale: true` marker. |
| A user double-submits a Quick Create form | The client-generated `Idempotency-Key` (one per logical submission attempt, per Principle 9) ensures the second request returns the original response rather than creating a duplicate — the deterministic half of Duplicate Detection, applying regardless of whether the Sales Agent's own fuzzy duplicate pass ever runs. |
| Two browser tabs have this screen open; a user accepts a pending-review card or dismisses a credit-exposure row in one tab | The other tab's corresponding query is invalidated by the same `ai-jobs` realtime event both tabs subscribe to, so the change disappears from both without a manual refresh — session-independent Realtime behavior, not a company-switch-style hard reset. |
| A user wants to bulk-confirm several draft orders or bulk-post several draft invoices from this Hub | Not supported here by design — `docs/accounting/SALES.md`'s bulk endpoints are exposed only through the dedicated Orders/Invoices list screens' own `DataTable` Bulk Action Bar, where row selection is meaningful against one real resource; this Hub always redirects a user who needs bulk action to the relevant per-type tab. |
| A company has `auto_apply_risk_tier = true` and a customer's tier silently moves from `medium` to `high` between page loads | This screen never renders a control for that setting and never explains the tier change itself — the credit exposure row simply reflects the current `credit_risk_tier`-informed `exposure_pct` the API computed; a Sales Manager wanting to know *why* the tier changed follows the row's link to the customer record, where that history is owned. |

# End of Document
