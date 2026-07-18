# Sales (Order-to-Cash) — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: SALES
---

# Purpose

The Sales screen is the order-to-cash hub of QAYD's Next.js frontend — the single landing surface a Sales Manager, Sales Employee, or Finance user opens to see the whole commercial pipeline in one place, then branch into whichever document type needs attention. It is the frontend counterpart to `docs/accounting/SALES.md` (which owns every table, workflow, and endpoint this screen renders and never re-derives) and to `docs/ai/agents/SALES_AGENT.md` (which owns the reasoning, autonomy level, and confidence handling behind every AI-sourced element this screen surfaces). Where those two documents define *what is true* and *what the AI is allowed to do*, this document defines how a human sees and acts on it, conforming throughout to the cross-cutting rules in `FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, and `LAYOUT_SYSTEM.md`; where this document is silent, those govern, and where it appears to contradict one of them, that is a defect to raise, not a decision to resolve unilaterally in code.

Concretely, this document specifies the screen that composes:

- A **pipeline overview KPI band** — today's revenue, open pipeline value (raw and probability-weighted), orders awaiting approval, and overdue AR — the same four figures `docs/accounting/SALES.md`'s Sales Dashboard report already computes server-side, rendered here as the first thing a Sales Manager reads each morning.
- A **module sub-navigation** across the five order-to-cash document types this document's task names — Quotations, Sales Orders, Invoices, Receipts, Credit Notes — plus Customers (owned by Accounting/CRM's own specification, linked to but not respecified here), each a full `DataTable`-backed list screen this document does not redefine.
- A **Recent Documents panel** that defaults to a merged, cross-type feed of the newest activity across all five document tables, and becomes a real, filtered, paginated instance of the selected type's own `DataTable` the moment a user picks a specific tab — one component, reused, never a bespoke bulk list per type.
- A **permission-gated Quick Create menu** into a draft quotation, order, invoice, receipt, or credit note, using the platform's intercepted-route quick-create pattern so a fast create never leaves this screen and a direct link to the same URL always renders the full page.
- **AI Sales Agent insight** — a condensed, dismissible rail synthesizing the Sales Agent's own pending recommendations (invoice/receipt drafts awaiting review, collections nudges, a revenue forecast) and any fraud/duplicate step-up currently blocking a document, so a human sees exactly what the always-on AI colleague found without this screen computing any of it itself.

Like every screen in QAYD, this one **owns no business logic**. It never confirms a sales order, never posts an invoice, never allocates a receipt, and never lets a Sales Agent recommendation execute itself. Every figure rendered here was computed and validated by Laravel; every mutation this screen triggers is a call to `/api/v1/sales/...` guarded by the exact permission key the API itself enforces (`FRONTEND_ARCHITECTURE.md` Principles 1 and 4).

# Route & Access

## App Router path

```
app/(app)/sales/
├── layout.tsx                            # Sub-nav: Overview | Customers | Quotations | Orders | Invoices | Credit Notes | Receipts (sales.read gate)
├── page.tsx                               # ★ THIS DOCUMENT — the Sales Hub ("Overview" tab: pipeline KPIs, Recent Documents, Quick Create, AI rail)
├── @modal/
│   ├── (.)quotations/new/page.tsx         # Intercepted quick-create — same route/mutation as quotations/new/page.tsx, rendered in a Dialog
│   ├── (.)orders/new/page.tsx
│   ├── (.)invoices/new/page.tsx
│   ├── (.)receipts/new/page.tsx
│   └── (.)credit-notes/new/page.tsx
├── customers/[[...segment]]/page.tsx      # Owned by Accounting/CRM's CUSTOMERS.md + its own frontend screen doc — linked from here, not respecified
├── quotations/
│   ├── page.tsx                           # List Page Template — its own screen document
│   └── new/page.tsx                       # Canonical full-page create (intercepted by @modal above)
├── orders/
│   ├── page.tsx
│   └── new/page.tsx
├── invoices/
│   ├── page.tsx
│   ├── new/page.tsx
│   └── [invoiceId]/page.tsx               # Detail Page Template — its own screen document
├── credit-notes/
│   ├── page.tsx
│   └── new/page.tsx
└── receipts/
    ├── page.tsx
    └── new/page.tsx
```

**A reconciliation note, because two sibling documents currently point elsewhere.** `FRONTEND_ARCHITECTURE.md`'s own App Router tree does not yet list a `sales/page.tsx` at the module root, and `NAVIGATION_SYSTEM.md`'s primary-nav module map currently fixes the Sales sidebar entry's root route at `/sales/quotations` — the same "no bare module-root page" shape `BANKING.md` uses, where the Sidebar's Banking entry points straight at `/banking/accounts` and there is no `banking/page.tsx`. This document departs from that shape deliberately, for the same reason `DASHBOARD.md` departed from a stale routing clause in `AI_COMMAND_CENTER.md`: the screen this task specifies is not "the Quotations list, enriched" — it is a genuine cross-document landing (pipeline KPIs plus a merged feed across five document tables plus an AI rail), and a screen that conceptually outranks all five of its own sibling tabs deserves to sit at the module's own root rather than being awkwardly attached to one arbitrarily-chosen tab. `app/(app)/sales/page.tsx` is therefore a real, new file this document introduces, rendered inside `sales/layout.tsx`'s sub-nav as its own first tab, **Overview**; the Sidebar's Sales entry and the Command Palette's Navigate group should both point at `/sales` (not `/sales/quotations`) going forward, and `FRONTEND_ARCHITECTURE.md`'s route tree and `NAVIGATION_SYSTEM.md`'s module-map root route should be read as stale on this one point and reconciled to match — nothing else about either document changes.

## Permission gate

| Gate | Permission | Effect if absent |
|---|---|---|
| Screen visible at all | `sales.read` | Sidebar's Sales entry (and this route) does not render; a direct hit to `/sales` renders the shell-level `error.tsx` with "You don't have access to this," per `NAVIGATION_SYSTEM.md → Server truth, client courtesy` — never a silent redirect. |
| Pipeline KPI band | `sales.report.read` | The whole band is omitted (not a row of permission-denied placeholders) — matching `DASHBOARD.md`'s own KPI Strip precedent; Recent Documents and the AI rail render independently. |
| Recent Documents — Quotations tab/source | `sales.quotation.read` | Tab omitted from the segmented control; its rows omitted from the "All" merge. |
| Recent Documents — Orders tab/source | `sales.order.read` | Same treatment. |
| Recent Documents — Invoices tab/source | `sales.invoice.read` | Same treatment. |
| Recent Documents — Receipts tab/source | `sales.receipt.read` | Same treatment. |
| Recent Documents — Credit Notes tab/source | `sales.credit_note.read` | Same treatment. |
| Customers tab | `accounting.customer.read` (owning module: Accounting/CRM, per `NAVIGATION_SYSTEM.md`'s grouping rule — the permission always matches the record's true owner, never a Sales-flavored duplicate) | Tab omitted. |
| Quick Create → Quotation | `sales.quotation.create` | Menu item omitted, not disabled — `COMPONENT_LIBRARY.md`'s `DropdownMenu` row-action precedent, applied to this menu. |
| Quick Create → Order | `sales.order.create` | Menu item omitted. |
| Quick Create → Invoice | `sales.invoice.create` | Menu item omitted. |
| Quick Create → Receipt | `sales.receipt.create` | Menu item omitted. |
| Quick Create → Credit Note | `sales.credit_note.create` | Menu item omitted. |
| AI Sales Agent rail | `sales.ai.read` | Rail omitted entirely (mirrors `BANKING.md`'s AI Treasury panel, wrapped in `<Can permission="sales.ai.read">`). |
| Accept an AI recommendation | `sales.ai.approve_draft` **and** the specific resource's own create/update permission (`docs/ai/agents/SALES_AGENT.md`'s two-gate rule) | "Accept" disabled with a permission tooltip rather than omitted, since the card itself is already addressed to a specific reviewer — mirrors `BANKING.md`'s treatment of a permission-gated `ApprovalCard`. |
| Fraud/duplicate step-up `ApprovalCard` | `sales.order.confirm` / `sales.quotation.accept` (whichever the blocked document needs next), or `sales.credit.override` for a credit-blocked order | Approve/Reject disabled with a tooltip; the card itself always renders, since hiding a document that is blocked on the viewer's own next step would look like a missed notification. |

## Roles

Reproduced from `docs/accounting/SALES.md`'s own Permissions table and translated into what a role concretely sees on this screen:

| Role | What renders |
|---|---|
| Owner, CEO, CFO | Full hub: KPI band, every Recent Documents tab, Quick Create for every document type, full AI rail including forecast and fraud step-up cards. |
| Sales Manager | Full hub; Quick Create for Quotations/Orders/Leads; can approve a fraud step-up or a credit-blocked order below the company's configured threshold; sees the AI rail in full. |
| Sales Employee | KPI band (own-scope revenue/pipeline only, per `sales.report.read`'s "own scope" note), Quotations/Orders tabs with their own documents emphasized, Quick Create for Quotations/Orders only; sees AI recommendations addressed to them (upsell/cross-sell prompts surfaced upstream in the quote builder) but not the fraud/credit approval cards, which require a permission they do not hold. |
| Finance | Full KPI band, Invoices/Receipts/Credit Notes tabs and their Quick Create actions; Quotations/Orders tabs are read-only preview rows in the merged feed (no create action); reviews and accepts `invoice_draft`/`receipt_draft` AI recommendations. |
| Warehouse | Hub renders with an empty-but-not-broken KPI band and no Quick Create actions (their Sales permissions are limited to delivery-stage actions this hub does not surface — see `docs/accounting/SALES.md`'s Deliveries section, out of this document's scope); typically reaches Sales documents through the Orders detail screen instead. |
| Auditor / External Auditor | Fully read-only: KPI band, every Recent Documents tab, and the AI rail render with every mutating control (Quick Create, Accept, Approve/Reject) omitted. |
| AI Agent (service account) | Never renders this screen as a user — its read-mostly grant feeds the AI rail described in **AI Integration**, and it structurally cannot hold `sales.order.confirm`, `sales.invoice.post`, `sales.receipt.create`, or `sales.ai.approve_draft` regardless of any role grant, per the platform-wide rule that a Sales Agent recommendation is always accepted under the *human* reviewer's own session, never the agent's. |

# Layout & Regions

`app/(app)/sales/page.tsx` instantiates a hybrid of `LAYOUT_SYSTEM.md`'s **Dashboard Template** (for the KPI band and the AI rail) and its **List Page Template** (for the Recent Documents region, which becomes a genuine List Page instance the moment a specific document-type tab is selected) — the same kind of template blend `DASHBOARD.md` and `BANKING.md` both use for their own hub screens, never a sixth, bespoke template.

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│  Overview  Customers  Quotations  Orders  Invoices  Credit Notes  Receipts   [sub-nav tabs] │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│  Sales                                                        [Search…]        [+ New ▾]   │
│  Open pipeline KD 48,200.000 (weighted KD 19,680.000) · 4 orders awaiting approval          │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ [Today's revenue    ] [Open pipeline        ] [Orders awaiting  ] [Overdue AR             ]│
│ [KD 12,480.500  ▲2% ] [KD 48,200.000 ~~~~~~ ] [approval:  4     ] [KD 9,340.250 · 11 inv. ]│
├───────────────────────────────────────────────────────────┬─────────────────────────────────┤
│  Recent documents     [All][Quot.][Ord.][Inv.][CN][Rcpt]  │  AI · Sales Agent               │
│  ┌───────────────────────────────────────────────────────┐│  ● Forecast P50 KD 210,000       │
│  │● QT  QT-KWC-2026-00512  Al Rayyan Trading    1,835.400 ││    (P10 180,000–P90 250,000)     │
│  │    sent · 2h ago                                       ││  ─ Pending review ────────────── │
│  │  SO  SO-KWC-2026-08841  Al Rayyan Trading    1,835.400 ││  ▸ Invoice draft — Al Rayyan      │
│  │    confirmed · 2h ago                                  ││    96% confidence                 │
│  │  INV INV-KWC-2026-14502 Al Rayyan Trading    1,835.400 ││  ▸ Collections nudge — Bader Co.  │
│  │    posted · yesterday                                  ││    34 days overdue                │
│  │● RC  RC-KWC-2026-07310  Al Rayyan Trading    1,835.400 ││  ─ Needs approval ──────────────  │
│  │    cleared · yesterday                                 ││  ⚠ Fraud step-up — SO-08902       │
│  │  …                                                     ││    risk score 78/100              │
│  │                                     [View all in tab →]││  [ View all AI activity → ]      │
│  └─────────────────────────────────────────────────────────┘└─────────────────────────────────┘
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Grid position | Content | Notes |
|---|---|---|---|
| Sub-nav | Full width, part of `sales/layout.tsx`'s own shell, not this page | `Tabs`: Overview (active) · Customers · Quotations · Orders · Invoices · Credit Notes · Receipts, each a real `<Link>` per `NAVIGATION_SYSTEM.md`'s Sub-navigation convention | Server Component; not streamed |
| Page Header | Full width | `<h1>Sales</h1>`, a one-line pipeline summary ("Open pipeline KD 48,200.000 (weighted KD 19,680.000) · 4 orders awaiting approval"), a `Search…` box (records search scoped to `sales/*`, same fan-out mechanism as the Command Palette's Records group), and the permission-gated `SalesQuickCreateMenu` | Renders immediately with the page shell |
| KPI band | `col-span-12`, one row | Four `KpiTile`s: Today's Revenue, Open Pipeline (value + weighted value in the same tile's secondary line), Orders Awaiting Approval, Overdue AR (amount + count) | Own `<Suspense>` boundary — `GET /api/v1/sales/dashboard` |
| Recent Documents | `col-span-8` at `lg`+ | `RecentSalesDocumentsPanel` — a segmented control (All · Quotations · Orders · Invoices · Credit Notes · Receipts) over either the merged feed (`SalesDocumentsFeed`, "All") or a real `DataTable` bound to the selected resource | Own `<Suspense>` boundary per active source; switching tabs is a client-side resource swap, not a navigation |
| AI Sales Agent rail | `col-span-4` at `lg`+ | `SalesAiInsightsRail` — a compact forecast chip (P10/P50/P90, per `docs/ai/agents/SALES_AGENT.md`'s mandatory range format), up to three condensed `AIProposalPanel` cards ("Pending review"), and any fraud/duplicate step-up rendered as an inline `ApprovalCard` ("Needs approval") | Own `<Suspense>` boundary; scrolls independently of Recent Documents beside it, mirroring `DASHBOARD.md`'s AI Summary Rail |

At `xl`+ the platform-wide AI Rail (the `360px` companion panel `LAYOUT_SYSTEM.md` defines as a fourth shell region, distinct from this page's own `SalesAiInsightsRail` column) may additionally dock inline — it is a companion to whatever the user is currently looking at, never a duplicate of the module-scoped rail that is part of this page's own content and remains visible regardless of whether the global AI Rail is toggled, exactly as `DASHBOARD.md` describes for its own AI Summary Rail.

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue, composed into a small set of new Sales-scoped components living in `components/sales/` per `PROJECT_STRUCTURE.md`'s module-folder convention. No primitive is hand-rolled, and — per `COMPONENT_LIBRARY.md`'s own stated contract — this screen never builds a second table, money cell, or confidence indicator that already exists elsewhere in the library.

| Component | Source | Role on this screen |
|---|---|---|
| `Tabs` | Primitive (`components/ui/tabs.tsx`) | Overview/Customers/Quotations/Orders/Invoices/Credit Notes/Receipts sub-nav, rendered by `sales/layout.tsx`; a second, smaller `Tabs` instance drives the Recent Documents segmented control |
| `KpiTile` | Finance (`components/dashboard/kpi-tile.tsx`) | Today's Revenue, Open Pipeline, Orders Awaiting Approval, Overdue AR |
| `TrendSparkline` | Finance (`components/dashboard/trend-sparkline.tsx`) | 30-day trend inside the Today's Revenue tile |
| `AmountCell` | Finance (`components/accounting/amount-cell.tsx`) | Every monetary figure outside a `KpiTile` — Recent Documents row amounts, AI recommendation payload figures |
| `CurrencyTag` | Finance (`components/shared/currency-tag.tsx`) | Multi-currency annotation on a Recent Documents row when a document's `currency_code` differs from the company's base currency |
| `StatusPill` | Finance (`components/shared/status-pill.tsx`), domains `sales_quotation` / `sales_order` / `invoice` / `receipt` / `credit_note` (added by this document, below) | Document status in every Recent Documents row and in each per-type `DataTable` |
| `DataTable` | Finance (`components/shared/data-table.tsx`) | The Recent Documents panel's per-type view (Quotations/Orders/Invoices/Credit Notes/Receipts tabs), bound to the exact same `resource` each dedicated list screen uses |
| `ConfidenceBadge` | AI (`components/ai/confidence-badge.tsx`) | Every AI-sourced figure in the rail — the forecast chip, each recommendation card, each fraud-score card |
| `AIProposalPanel` | AI (`components/ai/ai-proposal-panel.tsx`), rendered `compact` | Pending-review recommendation cards (invoice/receipt drafts, collections nudges) |
| `ApprovalCard` | Shared (`components/shared/approval-card.tsx`), `kind="ai_recommendation"` | Fraud/duplicate step-up items and credit-blocked orders awaiting the viewer's own sign-off |
| `Sheet` | Primitive (Radix side-dialog) | Recent Documents row-click quick view; slides from the inline-end edge, mirrors under RTL |
| `DropdownMenu` | Primitive | `SalesQuickCreateMenu`'s options list, permission-filtered |
| `Dialog` | Primitive | Each `@modal/(.)…/new` intercepted quick-create form |
| `Skeleton`, `EmptyState`, `ErrorState` | Shared | Per-region loading/empty/error rendering — see **States** |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` | Wraps the KPI band, every Quick Create item, the AI rail, and every AI action button |
| `SalesPipelineKpiBand` | **New** — `components/sales/sales-pipeline-kpi-band.tsx` | Composes the four `KpiTile`s from one `GET /api/v1/sales/dashboard` response |
| `SalesQuickCreateMenu` | **New** — `components/sales/sales-quick-create-menu.tsx` | The `+ New` split button/menu, each item wrapped in its own permission check |
| `RecentSalesDocumentsPanel` | **New** — `components/sales/recent-sales-documents-panel.tsx` | The segmented control plus the swap between `SalesDocumentsFeed` ("All") and a per-type `DataTable` |
| `SalesDocumentsFeed` | **New** — `components/sales/sales-documents-feed.tsx` | The merged, cross-table "All" preview — a fan-out, not a unified index (see below) |
| `SalesDocumentTypeBadge` | **New** — `components/sales/sales-document-type-badge.tsx` | Small icon+label chip distinguishing a Quotation/Order/Invoice/Receipt/Credit Note row inside the merged feed, since `StatusPill` alone only carries *status*, not document *type* |
| `SalesAiInsightsRail` | **New** — `components/sales/sales-ai-insights-rail.tsx` | Composes the forecast chip, up to three `AIProposalPanel`s, and any fraud/credit `ApprovalCard`s into the right-hand rail |

## `StatusPill` domain additions this document owns

`COMPONENT_LIBRARY.md`'s own `StatusPill` section leaves `invoice` and its siblings as a placeholder comment ("follow the identical shape, each owned by its module's screen spec"); this document supplies the real tables, reusing `docs/accounting/SALES.md`'s own status enums verbatim:

```tsx
// components/shared/status-pill.tsx — additions owned by this document
const SALES_QUOTATION_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:     { label: 'Draft',     tone: 'neutral' },
  sent:      { label: 'Sent',      tone: 'accent'  },
  accepted:  { label: 'Accepted',  tone: 'success' },
  rejected:  { label: 'Rejected',  tone: 'danger'  },
  expired:   { label: 'Expired',   tone: 'warning' },
  revised:   { label: 'Revised',   tone: 'neutral' },
  cancelled: { label: 'Cancelled', tone: 'danger'  },
};

const SALES_ORDER_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:              { label: 'Draft',              tone: 'neutral' },
  pending_approval:   { label: 'Pending approval',   tone: 'warning' },
  confirmed:          { label: 'Confirmed',           tone: 'accent'  },
  reserved:           { label: 'Reserved',            tone: 'accent'  },
  picking:            { label: 'Picking',             tone: 'accent'  },
  packed:             { label: 'Packed',              tone: 'accent'  },
  shipped:            { label: 'Shipped',             tone: 'accent'  },
  partially_invoiced: { label: 'Partially invoiced',  tone: 'warning' },
  invoiced:           { label: 'Invoiced',            tone: 'success' },
  cancelled:          { label: 'Cancelled',           tone: 'danger'  },
  closed:             { label: 'Closed',              tone: 'neutral' },
};

const INVOICE_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:          { label: 'Draft',          tone: 'neutral' },
  posted:         { label: 'Posted',         tone: 'accent'  },
  partially_paid: { label: 'Partially paid', tone: 'warning' },
  paid:           { label: 'Paid',           tone: 'success' },
  overdue:        { label: 'Overdue',        tone: 'danger'  },
  void:           { label: 'Void',           tone: 'danger'  },
};

const RECEIPT_STATUS: Record<string, { label: string; tone: Tone }> = {
  pending:   { label: 'Pending',   tone: 'warning' },
  cleared:   { label: 'Cleared',   tone: 'success' },
  bounced:   { label: 'Bounced',   tone: 'danger'  },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
};

const CREDIT_NOTE_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:   { label: 'Draft',   tone: 'neutral' },
  posted:  { label: 'Posted',  tone: 'accent'  },
  applied: { label: 'Applied', tone: 'success' },
  void:    { label: 'Void',    tone: 'danger'  },
};

Object.assign(STATUS_TABLES, {
  sales_quotation: SALES_QUOTATION_STATUS,
  sales_order: SALES_ORDER_STATUS,
  invoice: INVOICE_STATUS,
  receipt: RECEIPT_STATUS,
  credit_note: CREDIT_NOTE_STATUS,
});
```

`posted`/`confirmed`/`shipped` all take the `accent` tone rather than `success`, for the same reason `BANKING.md` gives `cleared` a different tone than `reconciled`: `accent` marks "the system's pipeline has processed this, but the loop is not yet fully closed" (an order confirmed but not yet fully invoiced, an invoice posted but not yet paid), while `success` is reserved for the state where nothing further is owed or expected (`invoiced`, `paid`, `applied`, `accepted`).

## `SalesDocumentsFeed` — a fan-out, not a unified index

The "All" view of Recent Documents cannot bind to a single `DataTable resource`, because `docs/accounting/SALES.md` deliberately keeps quotations, orders, invoices, receipts, and credit notes as five separate tables with five separate endpoints — Sales has no cross-entity read model this document is entitled to invent. `SalesDocumentsFeed` instead follows the exact pattern `NAVIGATION_SYSTEM.md`'s Command Palette already establishes for its own Records group — "a fan-out, not a unified index... calls the same per-resource endpoints... in parallel, permission-filtered before firing":

```tsx
// components/sales/sales-documents-feed.tsx
'use client';

import { useQueries } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { usePermissions } from '@/hooks/use-permissions';
import { SalesDocumentTypeBadge } from './sales-document-type-badge';
import { StatusPill } from '@/components/shared/status-pill';
import { AmountCell } from '@/components/accounting/amount-cell';
import type { SalesDocumentSource, MergedSalesDocument } from '@/types/sales';

const SOURCES: SalesDocumentSource[] = [
  { type: 'sales_quotation', resource: 'sales/quotations',    permission: 'sales.quotation.read',   numberField: 'quotation_number',   href: (r) => `/sales/quotations/${r.id}` },
  { type: 'sales_order',     resource: 'sales/orders',        permission: 'sales.order.read',       numberField: 'order_number',       href: (r) => `/sales/orders/${r.id}` },
  { type: 'invoice',         resource: 'sales/invoices',      permission: 'sales.invoice.read',      numberField: 'invoice_number',      href: (r) => `/sales/invoices/${r.id}` },
  { type: 'receipt',         resource: 'sales/receipts',      permission: 'sales.receipt.read',      numberField: 'receipt_number',      href: (r) => `/sales/receipts/${r.id}` },
  { type: 'credit_note',     resource: 'sales/credit-notes',  permission: 'sales.credit_note.read',  numberField: 'credit_note_number',  href: (r) => `/sales/credit-notes/${r.id}` },
];

export function SalesDocumentsFeed({ onRowClick }: { onRowClick: (doc: MergedSalesDocument) => void }) {
  const permissions = usePermissions();
  const sources = SOURCES.filter((s) => permissions.includes(s.permission));

  const queries = useQueries({
    queries: sources.map((s) => ({
      queryKey: ['sales', 'recent-documents', s.type],
      queryFn: () => apiClient.get(`/api/v1/${s.resource}`, { params: { sort: '-created_at', per_page: 5 } }),
      staleTime: 30_000,
    })),
  });

  const merged: MergedSalesDocument[] = sources
    .flatMap((s, i) => (queries[i]?.data?.data ?? []).map((row: any) => ({
      ...row, type: s.type, href: s.href(row), documentNumber: row[s.numberField],
    })))
    .sort((a, b) => +new Date(b.created_at) - +new Date(a.created_at))
    .slice(0, 12);

  return (
    <ul className="divide-y divide-ink-150">
      {merged.map((doc) => (
        <li
          key={`${doc.type}-${doc.id}`}
          className="flex items-center gap-3 py-2.5 cursor-pointer hover:bg-ink-100/60"
          onClick={() => onRowClick(doc)}
        >
          <SalesDocumentTypeBadge type={doc.type} />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-ink-950">{doc.documentNumber} · {doc.customer_name_en}</p>
            <StatusPill domain={doc.type} status={doc.status} size="sm" />
          </div>
          <AmountCell amount={doc.total_amount} currencyCode={doc.currency_code} emphasis="strong" />
        </li>
      ))}
    </ul>
  );
}
```

This view is deliberately **read/navigate-only** — it never exposes row selection or a Bulk Action Bar, because a merged fan-out has no single resource endpoint a bulk action could target uniformly (see **Edge Cases**). Selecting a specific tab in `RecentSalesDocumentsPanel` unmounts `SalesDocumentsFeed` and mounts a real `<DataTable resource="sales/quotations" .../>` (or the equivalent for the other four types) with full sort, filter, pagination, and — on that specific type's own list screen and on this embedded instance alike — its own Bulk Action Bar, exactly mirroring `BANKING.md`'s "one table implementation, two entry points" pattern for `BankTransactionsTable`.

# Data & State

## Endpoints

Every figure and every row on this screen resolves to an endpoint `docs/accounting/SALES.md` or `docs/ai/agents/SALES_AGENT.md` already defines; this screen introduces no business endpoint of its own.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Pipeline KPI band | `GET /api/v1/sales/dashboard` | `sales.report.read` | Returns `{ today_revenue_base_currency, open_pipeline_value, open_pipeline_weighted_value, orders_awaiting_approval, overdue_invoices_count, overdue_invoices_amount, as_of }`, per `docs/accounting/SALES.md`'s own worked example; Redis-cached server-side like every other dashboard bundle in the platform. The fuller `GET /api/v1/sales/reports/dashboard?branch_id=` form (`docs/accounting/SALES.md → Reports`) is the same aggregation exposed under the Reports module's `report_key` convention for scheduling/export; this screen calls the shorter canonical path since it needs neither. |
| Recent Documents — Quotations source | `GET /api/v1/sales/quotations?sort=-created_at&per_page=5` (feed) or the full paginated form (tab) | `sales.quotation.read` | |
| Recent Documents — Orders source | `GET /api/v1/sales/orders?sort=-created_at&per_page=5` (feed) or full form (tab) | `sales.order.read` | |
| Recent Documents — Invoices source | `GET /api/v1/sales/invoices?sort=-created_at&per_page=5` (feed) or full form (tab) | `sales.invoice.read` | |
| Recent Documents — Receipts source | `GET /api/v1/sales/receipts?sort=-created_at&per_page=5` (feed) or full form (tab) | `sales.receipt.read` | |
| Recent Documents — Credit Notes source | `GET /api/v1/sales/credit-notes?sort=-created_at&per_page=5` (feed) or full form (tab) | `sales.credit_note.read` | |
| Quick Create — Quotation | `POST /api/v1/sales/quotations` | `sales.quotation.create` | `@modal/(.)quotations/new`; carries an `Idempotency-Key` on every submission |
| Quick Create — Order | `POST /api/v1/sales/orders` | `sales.order.create` | Direct order (no quotation), e.g. a repeat-customer or POS shortcut |
| Quick Create — Invoice | `POST /api/v1/sales/invoices` | `sales.invoice.create` | Generates a draft from a delivery/order the form's own picker resolves |
| Quick Create — Receipt | `POST /api/v1/sales/receipts` | `sales.receipt.create` | Optional `auto_allocate` per `docs/accounting/SALES.md`'s allocation strategies |
| Quick Create — Credit Note | `POST /api/v1/sales/credit-notes` | `sales.credit_note.create` | Against an invoice/return the form's own picker resolves |
| AI recommendations (condensed) | `GET /api/v1/sales/ai/recommendations?limit=5&sort=-created_at&status=pending` | `sales.ai.read` | Same `ai_recommendations` rows `docs/ai/agents/SALES_AGENT.md` defines; capped and grouped into "Pending review" vs. "Needs approval" client-side by `recommendation_type` |
| AI forecast | `GET /api/v1/sales/ai/forecast` | `sales.ai.read` | P10/P50/P90 revenue range; refreshed nightly by the Sales Agent's own `ai_tasks` sweep, per `docs/ai/agents/SALES_AGENT.md → Inputs → Scheduled triggers` |
| Accept a recommendation | `POST /api/v1/sales/ai/recommendations/{id}/accept` | `sales.ai.approve_draft` **+** the resource's own create/update permission | Materializes the recommendation through the module's ordinary validated endpoint, under the accepting user's own session — never the agent's |
| Dismiss a recommendation | `POST /api/v1/sales/ai/recommendations/{id}/dismiss` | `sales.ai.read` | Reason required per `docs/ai/agents/SALES_AGENT.md`'s guardrail |
| Fraud/credit approval | Routes to `/approvals/{id}` (cross-module Approval Center) or an inline `ApprovalCard` calling `POST /api/v1/sales/orders/{id}/confirm` / `POST /api/v1/sales/quotations/{id}/accept` after an override is recorded | `sales.order.confirm`, `sales.credit.override` | The Hub never resolves a credit/stock/fraud block itself — it always routes to the document's own confirmation flow, which is the only place the deterministic gate lives |

## Query keys and cache tuning

```ts
// lib/api/query-keys.ts (sales-scoped factories, additive to the platform's shared factories)
export const salesKeys = {
  all: ['sales'] as const,
  dashboard: () => [...salesKeys.all, 'dashboard'] as const,
  recentDocuments: (type: SalesDocumentType | 'all') => [...salesKeys.all, 'recent-documents', type] as const,
  quotations: (filters: QuotationFilters) => [...salesKeys.all, 'quotations', filters] as const,
  orders: (filters: OrderFilters) => [...salesKeys.all, 'orders', filters] as const,
  invoices: (filters: InvoiceFilters) => [...salesKeys.all, 'invoices', filters] as const,
  receipts: (filters: ReceiptFilters) => [...salesKeys.all, 'receipts', filters] as const,
  creditNotes: (filters: CreditNoteFilters) => [...salesKeys.all, 'credit-notes', filters] as const,
};

export const aiSalesKeys = {
  recommendations: (limit: number) => ['ai', 'sales', 'recommendations', { limit }] as const,
  forecast: () => ['ai', 'sales', 'forecast'] as const,
};
```

Cache tuning follows `FRONTEND_ARCHITECTURE.md`'s data-class table exactly, applied to this screen's own resources:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Live/derived figures | `dashboard` (pipeline KPIs) | `0` (always stale) | Correctness over avoiding a refetch; kept fresh via Realtime invalidation, matching every other KPI band in the platform |
| Transactional lists | `recentDocuments(*)`, `quotations`, `orders`, `invoices`, `receipts`, `creditNotes` | `30_000` (30s) | Frequent enough writes (a POS channel can create and invoice a sale in the same request) that a stale list is a real annoyance, not worth sub-minute polling |
| AI feeds | `aiSalesKeys.recommendations` | `10_000`, `refetchOnWindowFocus: true` | Matches `FRONTEND_ARCHITECTURE.md`'s AI-feed row verbatim |
| Nightly-recomputed reference | `aiSalesKeys.forecast` | `900_000` (15 min), served with the response's own `as_of`/`generated_at` | The Sales Agent's forecast is recomputed once per night per `docs/ai/agents/SALES_AGENT.md`; no benefit to polling faster than the source, and the forecast chip always shows when it was generated rather than implying a live number |

## Server-first paint, then Suspense per region

`app/(app)/sales/page.tsx` is a Server Component that prefetches the dashboard bundle (the one query every other region's first paint benefits from having warm, matching `BANKING.md`'s identical choice for its own account roster) and streams the KPI band, Recent Documents, and AI rail behind their own `<Suspense>` boundaries:

```tsx
// app/(app)/sales/page.tsx
import { Suspense } from 'react';
import { dehydrate, HydrationBoundary } from '@tanstack/react-query';
import { getQueryClient } from '@/lib/api/query-client-server';
import { apiServer } from '@/lib/api/server-client';
import { salesKeys } from '@/lib/api/query-keys';
import { Can } from '@/components/auth/can';
import { WidgetErrorBoundary } from '@/components/shared/widget-error-boundary';
import { WidgetSkeleton } from '@/components/dashboard/widget-skeleton';
import { SalesPageHeader } from '@/components/sales/sales-page-header';
import { SalesPipelineKpiBand } from '@/components/sales/sales-pipeline-kpi-band';
import { RecentSalesDocumentsPanel } from '@/components/sales/recent-sales-documents-panel';
import { SalesAiInsightsRail } from '@/components/sales/sales-ai-insights-rail';

export const dynamic = 'force-dynamic';
export const fetchCache = 'default-no-store'; // tenant-scoped — never statically cached

export default async function SalesHubPage() {
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: salesKeys.dashboard(),
    queryFn: () => apiServer.get('/sales/dashboard'),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="space-y-6">
        <SalesPageHeader />
        <WidgetErrorBoundary widgetId="sales_kpi_band">
          <Suspense fallback={<WidgetSkeleton variant="kpi-strip" />}>
            <SalesPipelineKpiBand />
          </Suspense>
        </WidgetErrorBoundary>
        <div className="grid grid-cols-12 gap-6">
          <WidgetErrorBoundary widgetId="recent_sales_documents">
            <Suspense fallback={<WidgetSkeleton variant="table" className="col-span-12 lg:col-span-8" />}>
              <RecentSalesDocumentsPanel className="col-span-12 lg:col-span-8" />
            </Suspense>
          </WidgetErrorBoundary>
          <Can permission="sales.ai.read">
            <WidgetErrorBoundary widgetId="sales_ai_rail">
              <Suspense fallback={<WidgetSkeleton variant="rail" className="col-span-12 lg:col-span-4" />}>
                <SalesAiInsightsRail className="col-span-12 lg:col-span-4" />
              </Suspense>
            </WidgetErrorBoundary>
          </Can>
        </div>
      </div>
    </HydrationBoundary>
  );
}
```

A slow AI recommendation fetch never delays the KPI band or the Recent Documents panel beside it, and — per `FRONTEND_ARCHITECTURE.md`'s three-granularity error model — a failure in one region renders that region's own retry card without touching the others (see **States**).

## Realtime

The Sales Hub subscribes to the platform's standard private, company-scoped Reverb channels, following the exact `private-company.{company_id}.<feature>[.{sub_id}]` convention every other module uses:

| Channel | Events carried | Effect |
|---|---|---|
| `private-company.{id}.sales` | `sales_quotation.sent`, `sales_order.confirmed`, `sales_order.credit_blocked`, `delivery.delivered`, `invoice.posted`, `invoice.overdue`, `payment.received`, `receipt.bounced`, `credit_note.issued` — the exact webhook/domain-event list `docs/accounting/SALES.md → Notifications` defines | Drives cache invalidation/patching, below |
| `private-company.{id}.ai-jobs` | Any `ai_recommendations` row authored by `sales_agent`, or a completed `ai_tasks` sweep (`forecast_refresh`, `collections_sweep`, `fraud_rescan`) | Refreshes the AI rail the moment the Sales Agent's LangGraph run affecting this screen finishes |
| `private-company.{id}.approvals` | New/updated `approval_requests` rows scoped to a Sales document | Refreshes the "Needs approval" section of the AI rail and the Topbar's approvals badge |
| `private-company.{id}.notifications.{user_id}` | `sales_order.credit_blocked`, `receipt.bounced` | Feeds the Topbar's notification bell only — this screen's own regions do not duplicate this stream, matching `DASHBOARD.md`'s identical rule |

Event handling follows `FRONTEND_ARCHITECTURE.md`'s "invalidate, or patch — chosen per event, not by default": `invoice.posted` and `payment.received` invalidate `salesKeys.dashboard()` and the relevant `salesKeys.recentDocuments(*)`/per-type list keys as a full `invalidateQueries` call (a KPI figure is never patched optimistically from a realtime push); `sales_order.credit_blocked` and a new fraud-score `ai_recommendations` row patch the AI rail's affected card in place via `setQueryData`, mirroring `BANKING.md`'s identical treatment of a fraud hold arriving while its card is already open. On WebSocket reconnect after any extended drop, every one of this screen's realtime-fed query keys is invalidated once, as a single batch — a missed `sales_order.credit_blocked` event during an outage must surface the moment connectivity returns, never wait for a manual refresh.

## AI agents feeding this screen

| Agent | Contribution to this screen |
|---|---|
| `sales_agent` (Sales Agent) | Owns every recommendation and forecast this screen's AI rail renders — see **AI Integration** for the full behavior |
| Forecast Agent | Computes the statistical model behind the forecast chip; the Sales Agent packages it into the P10/P50/P90 range shown here, never re-deriving the statistics itself |
| Fraud Detection | Scores every quotation-acceptance and order-confirmation attempt in real time; a score at or above the company's configured threshold (default 70/100) is what produces the "Needs approval" `ApprovalCard` in the rail |
| CFO | Supplies the `credit_risk_tier` signal a credit-precheck warning cites; never changes `customers.credit_limit` itself |
| General Accountant | Runs a GL account-mapping sanity check on every `invoice_draft`/`receipt_draft` before the Sales Agent surfaces it, so an accepted draft never fails posting for an account-mapping reason |

# Interactions & Flows

**Opening the screen.** The page shell (sub-nav, header, permission-gated Quick Create menu) paints immediately; the KPI band typically resolves within one Redis-cache round trip, since `GET /api/v1/sales/dashboard` is prefetched server-side exactly like `BANKING.md`'s own cash-position bundle. Recent Documents and the AI rail stream in independently a beat later — no region blocks another, per **Data & State**.

**Switching the Recent Documents tab.** Clicking Quotations/Orders/Invoices/Credit Notes/Receipts swaps `RecentSalesDocumentsPanel`'s internal state from the merged `SalesDocumentsFeed` to a real `<DataTable resource="sales/{type}" defaultSort="-created_at" searchable rowActions={...} />` — a client-side resource swap, not a page navigation, so the KPI band and AI rail beside it are untouched. The segmented control's active-tab indicator animates via Framer Motion `layoutId`, per `COMPONENT_LIBRARY.md`'s `Tabs` primitive. "All" is the default on every fresh visit (not persisted per-user), because the Hub's whole purpose is a fast morning scan across everything, not a remembered preference for one document type.

**Using Quick Create.** Selecting an item from `SalesQuickCreateMenu` (`+ New ▾`) navigates to that type's canonical `new` route (e.g. `/sales/invoices/new`); because the click originates from within an already-rendered page inside the app shell, Next.js's intercepting-route convention renders the matching `@modal/(.)…/new/page.tsx` as a `Dialog` instead of a full navigation, exactly the `journal-entries/new` pattern `FRONTEND_ARCHITECTURE.md` establishes. A hard refresh, a bookmark, or a shared link to the same URL always renders the full page — there is exactly one canonical route and one canonical form per document type; the modal is a presentational convenience over it, never a second implementation. None of the five Quick Create targets is on the platform's "sensitive, always full-page, never a modal" list (`bank.transfer`, `payroll.approve`/`payroll.release`, `tax.submit`, a permission change, a deletion) — creating a **draft** quotation, order, invoice, receipt, or credit note is reversible and pre-commitment, so it is safe to quick-create; the genuinely consequential actions on each of these documents — confirming an order, posting an invoice, allocating a receipt, posting a credit note — remain separate, deliberate steps taken from that document's own detail screen, never bundled into this quick-create flow.

**Clicking a KPI tile.** Each `KpiTile`'s `onClick` drills into the record set behind the number: Today's Revenue → `/accounting/financial-statements/profit-and-loss?range=today` (the same nested statement route `BALANCE_SHEET.md` establishes, reused rather than re-invented); Open Pipeline → `/sales/quotations?filter[status][in]=sent,accepted&sort=-total_amount`; Orders Awaiting Approval → `/sales/orders?filter[status]=pending_approval`; Overdue AR → `/sales/invoices?filter[status]=overdue&sort=-due_date`. A KPI click never opens a modal over the Hub — a number always means "go look at the source," matching `DASHBOARD.md`'s identical rule.

**Clicking a Recent Documents row.** In the "All" feed, a row click opens a `Sheet` quick view (document number, customer, line-item summary, status, and the same `AmountCell` the row itself shows) with an "Open full record" link to that document's own detail/list screen; in a per-type tab, the row click follows `DataTable`'s own `onRowClick` contract identically to the dedicated list screen it mirrors.

**Acting on an AI recommendation.** A "Pending review" card's `AIProposalPanel` renders Accept, Send for approval, and Dismiss (reason required) — never a one-click "Do it," because, per `docs/ai/agents/SALES_AGENT.md`'s own Autonomy Level table, no Sales Agent action is configured `auto` today; every recommendation this rail can show is either `suggest_only` (Accept calls the real create/update endpoint under the reviewer's own session) or `requires_approval` (rendered as an `ApprovalCard`, not an `AIProposalPanel`, per the fraud/credit row below). Accepting an `invoice_draft` or `receipt_draft` opens that document in its own full detail screen in `draft` status for a final look before the human separately posts it — the Hub never posts anything on the agent's behalf, matching Scenario 1 of `docs/ai/agents/SALES_AGENT.md`'s own worked example almost exactly.

**Resolving a fraud/duplicate step-up or a credit-blocked order.** A "Needs approval" `ApprovalCard` in the rail — e.g. a fraud score of 78/100 against order `SO-KWC-2026-08902` — links to that order's own confirmation flow; Approve/Reject from the card itself is available only to a viewer holding the relevant permission (`sales.order.confirm` with an override, or `sales.credit.override`), and rejecting always requires a reason, per the platform's mandatory-reason rule for every dismissal/rejection. The Hub never resolves the block itself — it is a launch point into the document's own gate, never a parallel approval path.

**Searching from the header.** The Page Header's `Search…` box is a records-only search scoped to `sales/*` resources (quotations, orders, invoices, receipts, credit notes, customers the viewer can read), fanning out to the same per-resource `/search` endpoints the Command Palette's Records group calls, filtered by permission before firing — typing "Al Rayyan" surfaces the customer and every open document referencing it, exactly as `NAVIGATION_SYSTEM.md`'s own worked example describes for a different customer name.

# AI Integration

The Sales Hub's AI surface is the Sales Agent's own condensed home screen — the same relationship `DASHBOARD.md`'s AI Summary Rail has to the full AI Command Center, applied to one module: a user who wants "what does the Sales Agent currently think I should look at" opens this rail; a user who wants the full backlog and conversation history opens `/ai/insights` or a dedicated "Ask Sales Agent" thread (out of this document's scope, per `docs/ai/agents/SALES_AGENT.md → Conversational inputs`). Every element obeys the platform-wide AI contract in full:

- **Confidence and reasoning are never hidden.** The forecast chip and every recommendation/fraud card carries its own `ConfidenceBadge`, with the underlying `reasoning` string available on hover/focus, exactly as `COMPONENT_LIBRARY.md` specifies — no bare number, ever. A recommendation whose confidence falls below 0.50 is either suppressed or explicitly labeled "insufficient history" rather than shown as a precise-looking percentage, per `docs/ai/agents/SALES_AGENT.md`'s own confidence-banding rule.
- **No autonomy level on this rail is `auto`.** Every action `docs/ai/agents/SALES_AGENT.md`'s Autonomy Level table assigns the Sales Agent is `suggest_only` or `requires_approval` — there is no code path on this screen that renders a one-click "Do it" for a price suggestion, an invoice draft, a receipt draft, a collections nudge, or a duplicate flag. This is a stronger guarantee than the generic "AI proposes, human approves" framing: it is not merely that this screen chooses not to render an auto-execute button, it is that the underlying agent has no permission grant that would make one meaningful, per the Sales module's own Permissions table ("AI Agent permission scope is intentionally read-mostly... it can never hold `sales.order.confirm`, `sales.invoice.post`, `sales.receipt.create`").
- **The forecast is always a range, never a point estimate.** The forecast chip shows P10/P50/P90 (e.g. "KD 180,000 – KD 250,000, P50 KD 210,000"), matching `docs/ai/agents/SALES_AGENT.md`'s explicit rule that "a single point estimate is never presented as a system output." Clicking the chip opens the fuller forecast surface (`/ai/forecast`, scoped to Sales) rather than expanding inline, keeping this rail's own footprint condensed.
- **A fraud/duplicate step-up overrides the calm register.** Exactly as `DASHBOARD.md` reserves its one Error-Red left-border treatment for a confirmed fraud hold, a "Needs approval" card on this screen — the only place this rail departs from the accent-only "AI touched this" visual language — is not dismissible from the Hub; it can only be resolved from the document's own confirmation flow or the Approval Center, so a user cannot swipe away a held order without seeing why.
- **Grounding is enforced upstream, not re-verified here.** Every number this rail displays already passed `docs/ai/agents/SALES_AGENT.md`'s Ground & Validate node before being persisted to `ai_recommendations` — this screen trusts and renders `supporting_document_ids` as clickable references (e.g. "citing QT-2026-00512, SO-2026-08841") rather than re-deriving or re-checking the claim itself, consistent with the platform-wide rule that the frontend never recomputes what the API/AI layer already validated.
- **Sensitive actions never terminate on this screen.** Approving a credit override or a fraud-flagged order confirmation always routes through that document's own gate or the Approval Center (`/approvals`), exactly as every other entry point into that flow does; the Hub never renders a one-click approval for anything on the sensitive-action list, regardless of confidence.

**Worked example**, reusing `docs/ai/agents/SALES_AGENT.md`'s own Scenario 1 verbatim so this screen's behavior is independently checkable against that document: after delivery `20441` for Al Rayyan Trading (customer `1042`) is marked delivered, the Sales Agent assembles an `invoice_draft` recommendation at 96% confidence citing sales order `9931` and delivery `20441`. It appears in this Hub's "Pending review" section the moment `ai-jobs` broadcasts it; Finance user Sara — holding both `sales.ai.approve_draft` and `sales.invoice.create` — accepts it from the card, which opens invoice `71310` in `draft` status on its own detail screen for her review before she separately posts it. The Hub itself never called `sales.invoice.create` or `sales.invoice.post` — Sara's own session did, exactly once, at the moment she clicked Accept. A second worked example — the nightly collections sweep for customer `2210`'s 34-days-overdue invoice (`docs/ai/agents/SALES_AGENT.md`'s Scenario 2) — surfaces identically as a "Collections nudge" card in the same "Pending review" section, carrying the Treasury Manager's `ai_predicted_next_payment_date`/confidence and a draft dunning message a human sends, never the agent.

# States

Every region has its own loading, empty, and error presentation — no region blocks another, and none ever renders a bare spinner as its primary loading state.

| Region | Loading | Empty | Error |
|---|---|---|---|
| KPI band | Skeleton tiles matching the final layout (`Skeleton className="h-8 w-32"` inside each tile shell) | A brand-new company with no sales activity yet: a dedicated "No sales recorded yet" card with a "Create your first quotation" CTA — never the generic zero-value tiles a filtered-to-zero period would show | Widget-level `<ErrorBoundary>` renders a small inline "Couldn't load this figure — Retry" card per failed tile; the other tiles remain interactive |
| Recent Documents — "All" feed | Skeleton rows (5–6) matching `SalesDocumentsFeed`'s row height | "No documents yet across quotations, orders, invoices, receipts, or credit notes" with a link into Quick Create | A source that fails independently is simply omitted from the merge (its rows do not appear) rather than blanking the whole feed; if every source fails, the panel shows one retry card, not five |
| Recent Documents — per-type tab | `DataTable`'s own skeleton rows | `DataTable`'s own `emptyState` prop, per type — e.g. "No quotations yet. Draft one to start the pipeline." | `DataTable`'s own `ErrorState`, unaffected by any other region |
| AI rail | Skeleton chip + three skeleton cards | Calm "You're all caught up — no open recommendations or risks right now" message, explicitly not styled as an error or a warning, matching `DASHBOARD.md`'s identical empty-state tone | A distinct "AI insights are temporarily unavailable" state (not a generic error, not an infinite spinner) when the AI engine returns `503`, respecting any `Retry-After` header |
| Quick Create menu | Renders synchronously from session permissions; never shows a loading state | An empty menu (a role with zero create permissions anywhere in Sales) collapses to a disabled `+ New` button with a tooltip rather than an empty dropdown | Not applicable — no network call until an item is chosen |

A route-level `error.tsx` remains the outermost safety net for a genuinely unexpected failure (e.g., the session itself becoming invalid mid-render), but per the three-granularity model this should essentially never fire for an individual region's ordinary `4xx`/`5xx` — those are caught and handled inline, one region at a time.

# Responsive Behavior

Following `LAYOUT_SYSTEM.md`'s Dashboard Template responsive table, applied to this screen's specific regions:

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | Sub-nav tabs collapse into a horizontal-scroll strip; the KPI band becomes a snap-scroll carousel (one tile per screen width); Recent Documents and the AI rail stack full-width, Recent Documents first (it is the higher-frequency task); `SalesQuickCreateMenu` collapses to a single floating `+` icon button that opens the same permission-filtered list as a `Sheet` from the bottom edge; the persistent Sidebar is replaced by the bottom tab bar, of which Sales is one destination |
| `md` (768–1023px) | KPI band becomes two tiles per row; Recent Documents and the AI rail remain stacked, single column |
| `lg` (1024–1279px) | KPI band becomes a full row; Recent Documents (8/12) and the AI rail (4/12) activate the side-by-side split |
| `xl`+ (≥1280px) | Same layout, generous margins; the global AI Rail companion panel may dock alongside this page's own `SalesAiInsightsRail` without competing for the same column, exactly as `DASHBOARD.md` describes for its own rail |

Per `LAYOUT_SYSTEM.md`'s table-responsive strategy, the per-type `DataTable` instances inside Recent Documents follow the same three-tier degradation every other List Page Template screen uses: horizontal scroll with a frozen identifier column at `md`+, column-priority hiding below `lg`/`xl`, and a card transform only below `sm`. Sales' own five document tables default to **Comfortable** density (per `LAYOUT_SYSTEM.md → Density Modes`, which names Invoices explicitly as a Comfortable-by-default screen, alongside Customers/Vendors/Products) rather than Compact, since these are lower-row-count, more-varied-per-row screens than a Journal Entries ledger — a user can still switch any individual tab to Compact via its own density toggle, persisted per table identity. The merged "All" feed has no density toggle of its own; it always renders at a single, fixed comfortable row height, since it is a preview, never a working ledger view.

`KpiTile` and the new composed components use Tailwind v4 container queries (`@container`), not only viewport breakpoints, so the same tile renders correctly whether it is one-per-carousel-slide on mobile or one-of-four in a full desktop row, matching `DASHBOARD.md`'s identical `@container` pattern verbatim. Touch targets on the Quick Create menu, every Recent Documents row, and every AI card's action buttons maintain the platform's 44×44px minimum hit area below `md`, implemented once in the shared `IconButton`/`Button` components rather than per-instance on this screen.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and `LAYOUT_SYSTEM.md`'s RTL contract and applied concretely to this screen's content:

- **Logical properties only.** The sub-nav tab order, the KPI band's tile order, the Quick Create menu's item order, and every Recent Documents row all use `ms-*`/`me-*`/`text-start`/`text-end` exclusively; flipping `dir="rtl"` on `<html>` mirrors the whole page — sidebar and sub-nav to the opposite edge, the KPI band reading start-to-end in the opposite direction, the Recent Documents/AI rail 8/4 split flipping sides — with zero screen-specific RTL code.
- **Numerals, currency codes, and document numbers never mirror.** Every `KpiTile` value, every `AmountCell` in Recent Documents and the AI rail, and every document number (`QT-KWC-2026-00512`, `SO-KWC-2026-08841`) renders inside a `dir="ltr"` / `unicode-bidi: isolate` span, per `AmountCell`'s and `Bidi`'s existing implementation — a document number reads left-to-right even inside a fully Arabic sentence describing it (e.g. "تم اعتماد عرض السعر QT-KWC-2026-00512").
- **Numeric alignment is physically fixed, not logical (Exception A).** Every amount column in the per-type `DataTable` instances uses hard-coded `text-right`, not `text-end`, in both directions, so a bilingual Finance team scanning magnitude and decimal alignment sees figures land on the same edge regardless of interface language.
- **Charts never mirror (Rule 5).** The Today's Revenue tile's `TrendSparkline` and the forecast chip's underlying P10/P50/P90 band keep a fixed left-to-right time/probability axis in both `dir="ltr"` and `dir="rtl"` — only the tile's position within the grid mirrors.
- **Bilingual document and customer data throughout.** Every Recent Documents row and every AI recommendation's cited customer name renders `name_en`/`name_ar` per the active locale, with the API always returning both fields regardless of `Accept-Language`, per `COMPONENT_LIBRARY.md`'s bilingual-data convention — the client, not the server, owns which language displays.
- **Directional icons mirror; status/type icons never do.** `SalesDocumentTypeBadge`'s icon set (a distinct, non-directional Lucide glyph per document type) never flips; the sub-nav's overflow chevron (on narrow widths) does flip, per `FRONTEND_ARCHITECTURE.md → RTL Layout Mirroring, Rule 4`.

| Context | English | Arabic |
|---|---|---|
| Sub-nav tab | Overview | نظرة عامة |
| KPI label | Open pipeline | خط المبيعات المفتوح |
| KPI label | Overdue AR | ذمم مدينة متأخرة |
| Quick Create | New quotation | عرض سعر جديد |
| Recommendation | Suggested by the Sales Agent — 96% confidence. | مقترح من وكيل المبيعات — بثقة 96%. |
| Empty state | No sales recorded yet. Create your first quotation to start the pipeline. | لا توجد مبيعات مسجلة حتى الآن. أنشئ أول عرض سعر لبدء خط المبيعات. |
| Fraud step-up | Needs approval — risk score 78/100. | يتطلب اعتمادًا — درجة المخاطر 78/100. |

Arabic copy on this screen is authored directly by a fluent professional-register writer, not machine-translated from the English strings above, matching the platform's stated voice discipline and reusing `docs/accounting/SALES.md`'s own bilingual terminology consistently — عرض سعر for quotation, أمر بيع for sales order, فاتورة for invoice, إيصال for receipt, إشعار دائن for credit note — the same terms this document's Recent Documents type badges and per-type tab labels use throughout rather than approximating.

# Dark Mode

The Sales Hub introduces no new color, elevation, or radius token — every surface resolves through the same `:root[data-theme="dark"]` remap `COMPONENT_LIBRARY.md` defines, verified per-component rather than assumed. This document follows `COMPONENT_LIBRARY.md`'s token naming (`--qayd-ink-950`…`--qayd-ink-0`, `--qayd-accent-700/600/500/100`) throughout, for the same reason `DASHBOARD.md` gives for making that same choice: every component this screen composes (`KpiTile`, `DataTable`, `AmountCell`, `StatusPill`, `ConfidenceBadge`, `ApprovalCard`) is implemented in `COMPONENT_LIBRARY.md` using exactly that scale, and describing them with `DESIGN_LANGUAGE.md`'s newer `ink-1`…`ink-12` naming here would describe code that does not match what those components actually ship. Reconciling the two token blocks belongs to those two documents, not to this screen spec.

- **KPI tiles and cards** use `bg-surface`/`bg-ink-100` in light mode and their dark-remapped equivalents; elevation gets *lighter*, not darker, in dark mode, matching the platform's "physical light" dark-mode strategy.
- **The Today's Revenue sparkline and the forecast band** read their stroke color from `--qayd-accent-600`/`--qayd-ink-700` via CSS variables, never a hard-coded hex, so both lift to their dark-mode-tuned equivalents automatically.
- **Sales document status tones** (`neutral`/`accent`/`success`/`warning`/`danger`, per the `STATUS_TABLES` additions in **Components Used**) resolve through the same tokens in both themes, remapped for contrast, never a separate dark-only color choice — a `posted` invoice's `accent` pill and a `paid` invoice's `success` pill stay distinguishable from each other in dark mode exactly as they are in light mode.
- **The fraud-hold `ApprovalCard`'s red left border** is the one place this screen intentionally departs from its otherwise-calm palette, in both themes, using the same `danger` token pair — never a separate, louder dark-mode-only red.

Every Storybook story for the five new composed components (`SalesPipelineKpiBand`, `RecentSalesDocumentsPanel`, `SalesDocumentsFeed`, `SalesAiInsightsRail`, `SalesQuickCreateMenu`) ships the platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this screen's own Playwright suite captures the same four-way screenshot set at the route level per `FRONTEND_ARCHITECTURE.md`'s testing convention.

# Accessibility

The Sales Hub targets WCAG 2.1 AA as a floor, identically in both languages and both themes, with the following screen-specific applications of the platform's general rules:

- **Landmark structure.** Each region is a real landmark, not a visually-implied section: a visually-hidden `<h2>` labels the KPI band, Recent Documents, and the AI rail for screen-reader navigation even where the sighted design omits a visible heading, mirroring `DASHBOARD.md`'s identical pattern.
- **Keyboard path.** Tab order flows the sub-nav tabs → Quick Create menu → KPI band (each tile a single focusable stop when it has an `onClick`) → Recent Documents' segmented control → its rows/table cells → the AI rail's cards and their action buttons. No region requires a mouse to reach or activate any control, and the Recent Documents `DataTable`'s own arrow-key row navigation and `Enter`-to-open-detail contract (`DESIGN_LANGUAGE.md → Virtualization & interaction`) apply identically inside this embedded instance.
- **Realtime updates announce politely, never assertively.** A new row arriving in Recent Documents, a KPI tick, or a new AI recommendation card uses `aria-live="polite"` exclusively — none of this screen's realtime feeds ever interrupt whatever a screen-reader user is currently reading, matching `DASHBOARD.md`'s identical rule.
- **Sparklines and the forecast band carry a mandatory `aria-label`.** The Today's Revenue tile's inline `TrendSparkline` may be `aria-hidden="true"` only where an adjacent text delta already states the trend in words — the one documented exception `COMPONENT_LIBRARY.md` allows.
- **Color is never the only channel.** Every `StatusPill` pairs a leading dot with a text label; the fraud-hold card's red border is paired with an explicit "Needs approval" label and an `AlertTriangle` icon, so a colorblind user or a grayscale view loses no information a sighted, color-normal user has.
- **Permission-gated controls are legible, not mysterious.** A Quick Create item whose permission is absent is omitted entirely (existence-sensitive, per `COMPONENT_LIBRARY.md`'s row-action precedent); an `ApprovalCard`'s Approve/Reject that is visible-but-disabled because the viewer lacks the specific approval permission always carries a tooltip naming it (e.g. "Requires `sales.credit.override`"), never a silently greyed-out button.
- **Focus management on drill-down.** Clicking a KPI tile or a Recent Documents row in a per-type tab navigates to a new route, so focus lands on the destination page's own heading via the platform's standard route-change focus reset; the "All" feed's row click opens a `Sheet` instead, which traps focus within itself per Radix's own `Dialog` accessibility contract and returns focus to the triggering row on close.

# Performance

- **Streamed, not blocking.** Per **Data & State**, the KPI band, Recent Documents, and the AI rail are each their own `Suspense` boundary; the slowest region (typically the AI rail, if a Sales Agent run is still in flight) never delays the KPI band's first paint, and a failure in one region never takes down another (`react-error-boundary` per region, as established for the AI Command Center and reused here).
- **Cached at the source.** `GET /api/v1/sales/dashboard` is Redis-cached server-side exactly like every other dashboard bundle in the platform; a cache miss on the deterministic KPI figures (today's revenue, pipeline value — plain aggregation over posted/open documents) recomputes synchronously inline since it is cheap SQL, while a cache miss on the AI-authored forecast never blocks the page — it serves the last-cached payload with an explicit `stale: true` marker rather than making the user wait on an LLM call to render their home screen.
- **The "All" feed's fan-out is bounded, not open-ended.** `SalesDocumentsFeed` issues at most five parallel requests (one per document type the viewer can read), each capped at `per_page=5`, merged and capped again at 12 total rows client-side — it never fetches more than a screenful of data to produce a preview, and it is never virtualized, since it is deliberately small; the moment a user wants real depth, they switch to a per-type tab, which is a genuine, virtualized-above-200-rows `DataTable`.
- **Debounced, stable interactions.** The header's records search debounces at 300ms per `NAVIGATION_SYSTEM.md`'s Command Palette convention; every per-type `DataTable` tab uses `placeholderData: keepPreviousData` so switching tabs or paging dims the previous result rather than flashing empty.
- **Realtime patches are the exception, not the rule.** Only a fraud/credit card's in-place `setQueryData` patch (see **Data & State → Realtime**) bypasses a full refetch for a tick-by-tick feel; every other realtime event on this screen goes through an ordinary `invalidateQueries` call, which can never drift from what the server actually holds.
- **Bundle budget.** The Sales Hub route's shell is held to the platform's stated first-load JS budget for this route, enforced in CI against `performance-budgets.json`; the AI rail's components are loaded eagerly (they are small, text-and-badge-heavy, not chart-heavy) while any chart-bearing sub-screen this Hub links out to (e.g. a fuller forecast view) code-splits its charting dependency via `next/dynamic({ ssr: false })`, matching `DASHBOARD.md`'s identical treatment of `RevenueExpenseChart`.
- **Web Vitals are tracked against a realistic baseline.** LCP/CLS/INP for this route are tagged with company transaction-volume band, so a genuine regression is distinguishable from the expected difference between a brand-new company's empty pipeline and a high-volume wholesale distributor's thousands of open documents.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A company switch fires while `SalesDocumentsFeed`'s fan-out requests are still in flight | `queryClient.clear()` runs before `router.refresh()` per `FRONTEND_ARCHITECTURE.md → Company switching`; any in-flight response is discarded on arrival because its query key no longer exists in the cleared cache. `router.push('/dashboard')` (the platform's fixed post-switch landing, not this screen) always follows, per `NAVIGATION_SYSTEM.md`'s own switch flow. |
| A role holds `sales.read` but none of the five entity-level `.read` permissions the KPI band or Recent Documents need — a narrow custom role, not one of the platform's named defaults | The KPI band renders zero tiles if `sales.report.read` is also absent; Recent Documents renders its own empty-region fallback rather than a "you don't have access" wall for the whole page, per this document's own **Route & Access** rule. If the same role also lacks every Quick Create permission, that menu collapses to nothing and the AI rail (gated separately on `sales.ai.read`) may be the only region left with content. |
| A fraud/duplicate score arrives (`sales_order.credit_blocked` or a new `ai_recommendations` fraud row) for a document while its "Needs approval" card is already open and being read | The affected `ApprovalCard`/recommendation card patches in place via `setQueryData`, matching `BANKING.md`'s identical rule for a fraud hold arriving mid-read — it never disappears or jumps position, and never disturbs the viewer's current scroll position. |
| A Sales Employee's own `sales.order.confirm` permission is capped "below threshold" (per `docs/accounting/SALES.md`'s Permissions table) and they open a Recent Documents row for an order that exceeds it | The row opens normally (read access is unaffected); the document's own detail screen's confirm action renders disabled with a tooltip rather than omitted, since the employee legitimately created the order and needs to understand why they cannot confirm it themselves — this Hub does not attempt to resolve or explain the threshold itself, only link to the screen that does. |
| The AI engine (FastAPI layer) is down or returns `503` for an extended period | The KPI band and Recent Documents are entirely unaffected, since neither calls the AI engine — the direct, visible payoff of keeping this screen's deterministic figures on a separate data path from its AI rail. The forecast chip and every recommendation card show the same muted "AI insights are temporarily unavailable" state, never a stale number without its `stale: true` marker. |
| A POS-channel sale is created and invoiced in the same atomic request (`pos_combined_checkout = true`, per `docs/accounting/SALES.md → Sales Channels → POS`) | `SalesDocumentsFeed` surfaces it as two nearly-simultaneous rows — a `invoiced` sales order and a `posted`/`paid` invoice with the same `created_at` to the second — rather than attempting to collapse them into one synthetic row; the merge sorts by `created_at` and ties are broken by document-type precedence (order before invoice), so the pair always renders in its true creation order. |
| A user double-submits a Quick Create form (a slow network, an impatient double-click) | The client-generated `Idempotency-Key` (one per logical submission attempt, per `FRONTEND_ARCHITECTURE.md`'s Principle 9) ensures the second request returns the original response rather than creating a duplicate quotation/order/invoice/receipt/credit note — this is the deterministic half of the Duplicate Detection `docs/accounting/SALES.md`'s own AI Responsibilities section names, and it applies regardless of whether the Sales Agent's fuzzy duplicate pass ever runs. |
| A company holds sales documents in more than one transaction currency (KWD-denominated retail alongside a USD wholesale export line) | Every KPI figure is the sum converted to the company's base currency using each document's stored `exchange_rate`, rendered through `AmountCell`/`formatAmount` at the base currency's own decimal precision (3 places for KWD); a `CurrencyTag` in `emphasis="muted"` mode annotates a Recent Documents row only when that specific document's currency differs from the base currency, so a single-currency company's feed stays uncluttered. |
| Two browser tabs have the Sales Hub open; a user accepts an AI recommendation in one tab | The other tab's `aiSalesKeys.recommendations` query is invalidated by the same `ai-jobs`/`approvals` realtime event both tabs subscribe to, so the accepted card disappears from both without a manual refresh — this is session-independent Realtime behavior, not a company-switch-style hard reset, exactly as `DASHBOARD.md` describes for its own cross-tab AI feed consistency. |
| A user wants to bulk-confirm several draft orders or bulk-post several draft invoices from the Hub | Not supported from the "All" feed or from this page's own KPI/AI regions, by design — `docs/accounting/SALES.md`'s bulk endpoints (`POST /sales/bulk/orders/confirm`, `POST /sales/bulk/invoices/post`) are exposed only through the dedicated Orders/Invoices list screens' own `DataTable` Bulk Action Bar, where row selection is meaningful against one real resource; a merged, five-table preview has no single resource a bulk action could target without silently doing something different per row type, so this Hub always redirects a user who needs bulk action to the relevant per-type tab or dedicated screen. |
| A quotation's `valid_until` passes while it is still showing as "sent" in the merged feed (the nightly expiry cron has not yet run, or the realtime event for it was missed) | The row shows its last-known status (`sent`) until `sales_quotation.expired`-equivalent invalidation arrives or the feed's own 30-second `staleTime` elapses and refetches — this screen never client-side computes "is this expired yet" from `valid_until`, since expiry is a server-side state transition (`docs/accounting/SALES.md → Quotations → Workflow`), not a date comparison the UI is entitled to perform on its own. |

# End of Document
