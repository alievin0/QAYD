# Sales Invoices — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: INVOICES
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Sales Invoices screen set: the list (with
its sibling Credit Notes tab), the invoice builder (create/edit), and the invoice detail view over the
`invoices` / `invoice_items` tables that `docs/accounting/SALES.md → Invoices` defines as "the legal,
tax-relevant demand for payment and the trigger for every Accounting-facing posting" the Sales module
produces. Every dinar of revenue QAYD will ever show on a Trial Balance, a Profit & Loss statement, or
a Customer Aging report passes through this screen's data model in exactly one shape — a header
carrying customer, currency, and totals, with two or more priced, taxed lines — whether that invoice
was typed by a Sales Employee closing a wholesale deal, generated automatically from a delivered POS
sale, or drafted by the FastAPI AI layer's Sales Agent from a just-confirmed order. This makes the
screen one of the highest-traffic surfaces in the entire product: it is where a Sales Employee turns a
fulfilled promise into a collectible debt, and where a Finance user turns that debt into a posted,
audited fact in the General Ledger.

Three sub-surfaces are covered by this one document, because they share one route family, one
permission surface, and — per `docs/frontend/README.md` and `COMPONENT_LIBRARY.md` — one set of
composed components:

1. **List** (`/sales/invoices`) — browse, filter by status/customer/period, search, sort, bulk-act,
   export, and read the account's AR aging at a glance. A segmented **Credit Notes** tab lives on this
   same route (`docs/frontend/NAVIGATION_SYSTEM.md`'s own sub-navigation table: "Invoices —
   `/sales/invoices` — `sales.read`, Credit Notes tab additionally needs `sales.credit_note.read`") —
   one screen, one filter/sort/export mechanism, reused for both of Sales' AR-reversing document types
   rather than a second, near-duplicate screen.
2. **Builder** (`/sales/invoices/new`, and the edit mode of the detail route for a `draft`) — the
   `InvoiceForm` + `InvoiceItemsTable` line editor: customer, currency, and per-line product/quantity/
   price/discount/tax, with a live, client-side subtotal/discount/tax/total preview. This is where a
   human — or, upstream of a human review, the AI layer's Sales Agent — authors a `draft` invoice.
3. **Detail** (`/sales/invoices/[invoiceId]`) — the full lifecycle view of one invoice: its line items,
   its payment/allocation history, its activity trail, its linked credit notes, and — while it is still
   a `draft` — the same builder, in edit mode, in place. A sibling detail route
   (`/sales/invoices/credit-notes/[creditNoteId]`) covers one credit note in the same depth.

This screen never computes a financial truth of its own. Every tax calculation, every balance check,
and every posting the client appears to perform is a fast, friendly, purely cosmetic preview that exists
to avoid a wasted round trip — the Sales module's `InvoiceService`/`JournalEntryPostingService` pairing
on the Laravel API is the only party whose answer can ever actually post an invoice to Accounts
Receivable, exactly as `docs/accounting/SALES.md → Invoices → Posting` describes: posting "validates the
invoice balances (`SUM(line_total) + tax_amount == total_amount`, to the cent)" and only then "the
Accounting Engine's posting service creates a balanced `journal_entries` row." Nothing in this document
introduces a validation rule, a permission key, an API shape, or a status value that contradicts that
backend specification; where this document names a permission or endpoint not already listed verbatim in
`SALES.md` (`sales.invoice.send`, `sales.invoice.export`, the `/pdf` and `/send` endpoints), it is an
explicit, narrow, additive refinement in the same spirit `docs/frontend/GENERAL_LEDGER.md` used for
`accounting.ledger.read` — never a contradiction of the backend's own permission grammar or table design.

Two audiences share this one shell, per the platform's "two audiences, one shell" rule
(`docs/frontend/README.md`): a Sales Employee or Sales Manager building and sending invoices quickly
(dense `Compact` table density, keyboard-first line entry, a builder tuned for "get this invoice out the
door") and a Finance user — Finance Manager, Accountant, or the Owner/CFO — reviewing, posting, and
collecting against them (calmer `Comfortable` density, an AR-aging-first reading of the list, no
hand-holding but also no line-editing keyboard model). Both are built from the exact same components —
`DataTable`, `InvoiceForm`, `AgingBar`, `StatusPill`, `AmountCell`, `ApprovalCard` — composed
differently; there is no separate "Finance mode" build. `sales.invoice.post` is the hinge permission
that separates the two in practice: a Sales Employee typically holds `sales.invoice.create` but not
`.post` (per `docs/accounting/SALES.md → Permissions`, only Owner and Finance hold `sales.invoice.post`
by default), so the builder's primary button resolves to "Save Draft" for one audience and to "Post"
for the other from the identical component tree.

A third author exists on this screen that neither of the two human audiences above accounts for: the AI
layer's **Sales Agent**. The moment a sales order is confirmed (for `on_order_confirmation` billing) or a
delivery is marked delivered (for `on_delivery` billing), the Sales Agent assembles an `invoice_draft`
recommendation — line items, prices, and `cogs_unit_cost` resolved directly from the order/delivery, with
the General Accountant agent's account-mapping sanity check attached — and a Finance or Sales user
accepts it into a real `draft` invoice with one click, never a silent auto-post
(`docs/ai/workflows/ORDER_TO_CASH.md → Orchestration`: "`sales.ai.approve_draft` + `sales.invoice.create`
(human)"). This screen structurally cannot render a one-click "post it" affordance for an AI-drafted
invoice — accepting the draft only ever produces a `draft` row a human must still separately post — so
the client-side path to post an AI-authored invoice without a human decision simply does not exist in
the component tree, not merely in the disabled state of a button.

# Route & Access

## Route tree

```text
app/(app)/sales/invoices/
├── page.tsx                          # List — Server Component, first-paint fetch; ?tab=invoices|credit_notes
├── loading.tsx                       # List skeleton (streamed while the Server Component fetches)
├── new/
│   └── page.tsx                      # Builder — create mode
├── [invoiceId]/
│   └── page.tsx                      # Detail — view mode, OR Builder in edit mode (draft only)
└── credit-notes/
    └── [creditNoteId]/
        └── page.tsx                  # Credit Note detail — the Credit Notes tab's own drill-down
```

`[invoiceId]` and `[creditNoteId]` follow the platform's dynamic-segment convention — camelCase, named
for the entity, never the generic `[id]` (`docs/frontend/FRONTEND_ARCHITECTURE.md → Conventions →
Naming`) — and their API-resource mirrors are `/api/v1/sales/invoices/{id}` and
`/api/v1/sales/credit-notes/{id}` respectively.

**Credit Notes is a tab of this screen, not a second screen.** Per `NAVIGATION_SYSTEM.md`'s own
sub-navigation table, the Credit Notes surface lives at this same `/sales/invoices` route, distinguished
only by a `?tab=credit_notes` query parameter — mirroring exactly the pattern
`docs/frontend/GENERAL_LEDGER.md` used for its own General Ledger/Account Statement duality ("one
screen, one route, specialized by filter/tab state, not a second near-duplicate screen"). Both tabs
share the Page Header, the Filter Bar's search/period controls, and the Export menu; only the
status/aging vocabulary and the DataTable's `resource`/columns differ per tab (`# Layout & Regions`).
The Credit Notes tab's own row drill-down is the one place this document introduces a second dynamic
route (`credit-notes/[creditNoteId]`), because a credit note's detail view — reason, applied/remaining
balance, its own approval chain for large amounts — is materially different content from an invoice's,
not a filtered re-read of the same fields.

**One route, two rendered modes for the invoice detail, chosen by server-known status.** There is
deliberately no separate `/sales/invoices/[invoiceId]/edit` route, mirroring
`docs/frontend/JOURNAL_ENTRIES.md`'s identical decision for its own Detail route:

```tsx
// app/(app)/sales/invoices/[invoiceId]/page.tsx
import { getInvoice } from "@/lib/api/sales";
import { InvoiceDetail } from "@/components/sales/invoice-detail";
import { InvoiceForm } from "@/components/sales/invoice-form";
import { notFound } from "next/navigation";

export default async function InvoicePage({
  params,
}: {
  params: Promise<{ invoiceId: string }>;
}) {
  const { invoiceId } = await params;
  const invoice = await getInvoice(invoiceId).catch(() => null);
  if (!invoice) notFound();

  return invoice.status === "draft" ? (
    <InvoiceForm mode="edit" invoiceId={invoice.id} defaultValues={invoice} />
  ) : (
    <InvoiceDetail initialInvoice={invoice} />
  );
}
```

Per `docs/accounting/SALES.md → Invoices → Void vs. Credit Note`, `draft` is the *only* mutable status —
"a `posted` invoice can never be voided in place; the only correction mechanism is issuing a
`credit_notes` document." There is therefore structurally no URL that implies a posted invoice can be
edited: a user who lands on this route for a `posted`/`partially_paid`/`paid`/`overdue`/`void` invoice
always sees the read-only `InvoiceDetail`, never a form with disabled fields (a disabled form inviting an
edit attempt is worse UX than no form at all, per the identical rule `JOURNAL_ENTRIES.md` states for its
own Detail route).

## Access gate

The route sits inside the `(app)` route group, behind `middleware.ts`'s session-cookie check and the
resolved active-company context (`X-Company-Id`), per `FRONTEND_ARCHITECTURE.md`. A user without
`sales.read` never sees "Invoices" in the Sales sidebar section or in the Command Palette's fuzzy-search
index (RBAC filters at the data layer before the list is constructed, per `NAVIGATION_SYSTEM.md →
Permission-Aware Nav`), and a direct hit on `/sales/invoices` renders the shared `403` boundary
(`# States`) rather than a `404`. A cross-company hit (an `invoiceId` that exists but belongs to a
different `company_id`) renders the shared `not-found.tsx` instead, per `FRONTEND_ARCHITECTURE.md`'s
"403 vs. 404" rule: the API itself returns `404`, never `403`, for a cross-tenant record so a caller can
never learn that an invoice with that id exists somewhere they cannot see.

## Permission surface on this screen

Every permission key below is defined once, authoritatively, in `docs/accounting/SALES.md →
Permissions`, except the three marked **(new)** — narrow, additive refinements this document introduces
for affordances the backend module names in prose (PDF send, list export) or in its AI workflow
(`sales.ai.approve_draft`, already defined in `docs/ai/workflows/ORDER_TO_CASH.md → Participating
Agents`, reused here verbatim, not redefined) but had not yet mapped onto a concrete permission the
frontend gates on.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `sales.read` | The route itself; the "Invoices" nav item | Route renders the shared `403` boundary; nav item hidden |
| `sales.invoice.read` | Every list row (Invoices tab); the Detail route; the PDF preview | Route renders `403`; nav item hidden even if `sales.read` is held |
| `sales.invoice.create` | "New Invoice" button, `/new` route, the `N` shortcut, "Save Draft" in the Builder, accepting an AI `invoice_draft` | Button/route removed from the DOM, not disabled |
| `sales.invoice.post` | "Post" label/behavior on the Builder's primary button and the Detail page's lifecycle action; bulk-post | Falls back to a "Submit" affordance only where the company's workflow defines one (see Edge Cases) — otherwise omitted, since ordinary invoice posting has no separate approval-submit step in `SALES.md` |
| `sales.invoice.void` | "Void" action on a `draft` invoice's Detail page and row-action menu | Menu item omitted |
| `sales.invoice.send` **(new)** | "Send" (email PDF to customer) on the Detail page and row-action menu | Menu item omitted; "Preview PDF" (read-only) remains available under `sales.invoice.read` alone |
| `sales.invoice.export` **(new)** | "Export" in the List's Page Header (Invoices tab) | Button omitted. A screen-scoped refinement of `sales.report.export`, exactly as `docs/frontend/GENERAL_LEDGER.md` established for `accounting.ledger.read` refining `accounting.read`: every role holding `sales.report.export` today holds this identically, so the refinement changes no default grant, only the key a future narrower role could target |
| `sales.credit_note.read` | The Credit Notes tab itself; its rows; its Detail route | Tab hidden entirely from the segmented control, not shown-and-empty |
| `sales.credit_note.create` | "Issue Credit Note" action from an Invoice Detail's overflow menu; the Credit Notes tab's own "New Credit Note" | Action omitted |
| `sales.credit_note.post` | "Post" action on a credit note's Detail page | Falls back to a read-only view of the pending `ai_approval_requests`/draft state |
| `sales.receipt.read` | The Payments tab's allocation list | Tab content replaced with a permission notice; the invoice's own amounts remain visible |
| `sales.receipt.create` | "Record Payment" action on the Payments tab | Action omitted |
| `sales.receipt.allocate` | Manual (non-auto) allocation controls inside "Record Payment" | Falls back to auto-allocate-only mode |
| `sales.refund.create` | "Issue Refund" action from a credit note's Detail page | Action omitted |
| `sales.ai.approve_draft` | "Accept" on an AI-drafted invoice or receipt-allocation recommendation card | `AIProposalPanel` renders read-only (recommendation, confidence, reasoning — no Accept action row); "Dismiss" remains available under `sales.invoice.read` |
| `sales.pricing.read` | The Builder's discount/price-list reference lookups | Fields fall back to plain manual entry with no price-list suggestion |

Default role grants (Owner, Sales Manager, Sales Employee, Finance, Warehouse, Auditor, AI Agent) are
exactly the table in `docs/accounting/SALES.md → Permissions` for every key that document already
defines — this document does not restate role-by-role grants for those keys, to avoid the two tables
drifting out of sync; `usePermission()` is the only source either query. For the three **(new)** keys,
default grants mirror their nearest existing sibling exactly: `sales.invoice.send` mirrors
`sales.invoice.create`'s grant (Owner, Sales Manager, Finance — the same people who create and send
invoices in the first place); `sales.invoice.export` mirrors `sales.report.export`'s existing grant
verbatim (Owner, Sales Manager, Finance, Auditor). Auditor holds `sales.invoice.read`,
`sales.credit_note.read`, `sales.receipt.read`, and `sales.invoice.export`/`sales.report.export` only —
this screen's UI never renders a Post/Void/Send/Record Payment control for that role at all, since
`usePermission` resolves to `false` unconditionally regardless of which invoice or tab is in view.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`, whose table names this exact affordance:
`N` is "context-aware: New Journal Entry on the Journal Entries route, **New Invoice on Sales**" — scoped
to this route, `N` opens `/sales/invoices/new`, only rendered reachable when `sales.invoice.create` is
held (the shortcut and the button share one permission check, never two). There is no dedicated `G`-chord
destination for Sales in the platform-wide chord table (`Dashboard`, `Accounting`, `Ledger`, `Banking`,
`Reports`, `AI` are the six defined today); reaching this screen from elsewhere uses `Cmd/Ctrl+K` → "Go to
Invoices," the Command Palette's own navigation entry for every permission-visible nav leaf.

# Layout & Regions

All three sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing page templates verbatim — no new template is
introduced for Invoices, per that document's "a screen never invents a sixth layout shape" rule.

## List — List Page Template

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Invoices                                                        [+ New Invoice]│
│ [ Invoices  |  Credit Notes ]                          [Import ▾]  [Export ▾]  │
├──────────────────────────────────────────────────────────────────────────────┤
│ Opening AR      0–30d          31–60d          61–90d          90+           │
│ 38,940.250 KWD  ▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ │  ← AgingBar
├──────────────────────────────────────────────────────────────────────────────┤
│ [Search invoice #, customer…]  [Status ▾][Customer ▾][Period ▾][Aging ▾] [▤][⚙]│
├──────────────────────────────────────────────────────────────────────────────┤
│ ☐ │ Invoice #       │ Customer         │ Date   │ Due     │ Status  │ Total  │⋯│
│ ☐ │ INV-KWC-2026-14502│ Al Zahra Trading│ Jul 16 │ Aug 15  │ ● Posted│ 1,835.4│⋯│
│ ☐ │ INV-KWC-2026-14501│ Boubyan Retail  │ Jul 15 │ Jul 15  │ ● Overdue│  620.0│⋯│
│ ☐ │ INV-KWC-2026-14500│ Diyar Real Estate│Jul 15 │ —       │ ● Draft │ 4,120.0│⋯│
│ …│  …               │ …                │ …      │ …       │ …       │  …     │⋯│
├──────────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 1,204                                       [‹ Prev] [Next ›] │
└──────────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, tab count badge ("1,204 invoices" / "58 credit notes," recomputed per active
  tab from `meta.pagination.total`), primary action ("New Invoice," gated `sales.invoice.create`, hidden
  entirely on the Credit Notes tab where the equivalent action is "New Credit Note" gated
  `sales.credit_note.create`), secondary actions menu (Import → out of this document's scope, mirrors
  `docs/accounting/CHART_OF_ACCOUNTS.md`'s staged-review import pattern if enabled for a company; Export
  → `sales.invoice.export` on the Invoices tab, `sales.report.export` on the Credit Notes tab).
- **Tab strip** — a two-item segmented control, `Invoices` (default) and `Credit Notes`, backed by
  `?tab=invoices|credit_notes` in the URL so a bookmarked or shared link reopens on the same tab. The
  Credit Notes tab is entirely absent from the DOM — not rendered-and-disabled — for a viewer lacking
  `sales.credit_note.read`, per `# Route & Access`.
- **AR summary band (`AgingBar`)** — rendered only on the Invoices tab, sourced from the bounded
  `GET /api/v1/sales/reports/aging` summary call (`# Data & State`), never computed by summing the
  currently-loaded page of rows. Clicking a bucket segment applies `filter[aging_bucket][in]=<bucket>`
  to the table below it, reusing `AgingBar`'s existing `onBucketClick` contract verbatim — the same
  contract `docs/frontend/DASHBOARD.md` already wires from its own AR KPI tile
  (`router.push('/sales/invoices?filter[aging_bucket][in]=31-60')`), so a click landing on this screen
  from the Dashboard and a click on this screen's own strip produce the identical URL shape.
- **Filter Bar** — search (`q`, trigram/full-text over `invoice_number`, `reference_number`, and the
  joined customer name, per `docs/api/API_FILTERING_SORTING.md → Full-Text Search`), and four controls:
  **Status** (multi-select over `draft | posted | partially_paid | paid | overdue | void` on the Invoices
  tab, `draft | posted | applied | void` on the Credit Notes tab), **Customer** (a searchable combobox
  over `docs/accounting/CUSTOMERS.md`'s `GET /api/v1/accounting/customers`, gated
  `accounting.customer.read` — reference data this screen reads but does not own), **Period**
  (`PeriodPicker`, `mode="relative"` by default, resolving against `invoice_date` on the Invoices tab and
  `credit_note.created_at` on the Credit Notes tab), and **Aging** (Invoices tab only — the same
  `0-30 | 31-60 | 61-90 | 90+` enum the summary band's buckets use, so the dropdown and the band's own
  click-through always agree). A density toggle and column-visibility menu sit at the bar's trailing edge.
- **Bulk Action Bar** replaces the Filter Bar's trailing side once ≥1 row is selected: "4 selected ·
  Post · Export · Clear" on the Invoices tab — each bulk action re-checks its own permission and
  re-validates server-side per row via `POST /api/v1/sales/bulk/invoices/post`, reported back per-row
  (a bulk "Post" is one request whose `data.results[i]` array carries each invoice's own success/failure,
  per `docs/accounting/SALES.md → API`'s bulk-operations contract — never a single all-or-nothing call).
- **Data Region** — `DataTable` (`resource="sales/invoices"` or `"sales/credit-notes"` depending on the
  active tab, `paginationMode="page"` — see `# Data & State` for why this screen is page-mode, not
  cursor-mode, pagination).
- **Pagination Footer** — page controls; "Showing 1–25 of 1,204."

The List keeps a single, signed **Total** column (via `AmountCell mode="signed"`) rather than
`JOURNAL_ENTRIES.md`'s deliberate two-column Debit/Credit split — an invoice, unlike a journal entry, has
exactly one economically meaningful figure per row (`total_amount`), so a second column would only
duplicate it. A **Balance Due** column (`AmountCell emphasis="strong"` when `> 0`, muted when `0`) sits
beside Total specifically because "how much was this invoice for" and "how much is still owed on it" are
both load-bearing at a glance for a Finance user scanning AR — collapsing to one column would force a
second click to answer the more operationally important of the two questions.

## Builder — Form Page Template

```
┌───────────────────────────────────────────────────┬───────────────┐
│ New Invoice          [Cancel] [Save Draft] [Post]  │ Live totals    │
├───────────────────────────────────────────────────┤ Subtotal 1,748.0│
│  Header Info                                       │ Discount   0.0 │
│  Customer [Al Zahra Trading ▾]  Currency [KWD ▾]   │ Tax       87.4 │
│  Invoice date [__]  Due date [__ · Net 30]         │ ─────────────  │
│  Sales order [SO-…-08841 ▾ optional]  Ref [__]     │ Total  1,835.4 │
├───────────────────────────────────────────────────┤                │
│  Line items                                         │  AI suggests   │
│  ┌───────────┬────┬───────┬────────┬─────┬───┐     │  invoice from  │
│  │ Product    │Qty │ Price │Discount│ Tax │ ⋯ │     │  order 9931    │
│  │ Product 205│ 50 │ 24.000│   0.00 │ 5%  │ ⋯ │     │  96% confidence│
│  │ Product 318│ 20 │ 32.000│   0.00 │ 5%  │ ⋯ │     │  [Apply] [✕]   │
│  └───────────┴────┴───────┴────────┴─────┴───┘     │                │
│  [+ Add line]                                       │                │
├───────────────────────────────────────────────────┤                │
│  Attachments (delivery note, PO)      [Upload]      │                │
├───────────────────────────────────────────────────┴───────────────┤
│ ── sticky footer, appears once header scrolls out of view ────────│
│          [Cancel]        [Save Draft]        [Post]                │
└─────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — "New Invoice" / "Edit INV-KWC-2026-14500"; Cancel (secondary, confirms discard if
  dirty); Save Draft (secondary, `sales.invoice.create`/`.update` — invoices have no separate `.update`
  key in `SALES.md`'s table, so editing a `draft` reuses `sales.invoice.create`, matching that table's
  own grant list exactly, since only `draft`/`rejected`-equivalent invoices are ever editable); Post
  (primary — label and behavior resolved from `sales.invoice.post`, see `# Interactions & Flows`).
- **Form Body** — three `Card` sections: Header Info (customer, currency, invoice date, due date —
  auto-computed from the customer's `payment_terms` and editable, optional `sales_order_id`/`delivery_id`
  link, reference, cost center/project), Line Items (`InvoiceItemsTable`), Attachments (polymorphic
  `attachments`, `attachable_type = 'invoices'`).
- **Live Totals Rail** (3–4/12, `xl:`+; becomes an inline panel above the sticky footer below `xl:`) —
  the running subtotal/discount/tax/total, purely a client-side preview of the same arithmetic the
  server's `chk_invoice_totals` constraint enforces authoritatively, and, when the AI layer has a
  matching `invoice_draft` recommendation for an order/delivery this invoice was opened from, an inline
  "Apply AI draft" card (never auto-filled without the explicit click, per the platform's
  no-silent-autofill-of-financial-values rule — identical to `JOURNAL_ENTRIES.md`'s own "AI suggests
  template" treatment).
- **Sticky Footer Action Bar** — duplicates Cancel/Save Draft/Post once the header scrolls out of view;
  the header's own buttons gain `aria-hidden` while the footer bar is the visible instance, so there is
  never a duplicate, ambiguous pair of live focus targets (`LAYOUT_SYSTEM.md → Form Page Template`).

## Detail — Detail Page Template

```
┌───────────────────────────────────────────────────┬───────────────┐
│ ‹ Invoices   INV-KWC-2026-14502        ● Posted    │  Summary       │
│                             [Record Payment] [⋯]   │  ───────────   │
├─ Details │ Payments │ Activity ──────────────────┤  Total 1,835.400│
│  Al Zahra Trading Co. · Net 30 · Due Aug 15, 2026  │  KWD           │
│  Product          │ Qty │ Price  │ Tax │ Line total│  Balance due   │
│  Product 205       │ 50 │ 24.000 │ 5%  │ 1,260.000 │  1,835.400     │
│  Product 318       │ 20 │ 32.000 │ 5%  │   575.400 │  Order SO-08841│
│                                                     │  Journal 553012│
├─────────────────────────────────────────────────────┤  AI-drafted    │
│  (Payments tab: receipts + allocations against       │  Confidence 96%│
│   this invoice; Activity tab: full lifecycle +        │  [View reasoning]│
│   version timeline + linked credit notes)             ├───────────────┤
└───────────────────────────────────────────────────┴───────────────┘
```

- **Page Header** — back-to-list link, invoice number, `StatusPill domain="invoice"`, the one lifecycle
  action that applies right now (Post when `draft`; Record Payment when `posted`/`partially_paid`/
  `overdue`; nothing primary when `paid`/`void` beyond the overflow menu), and an overflow menu for
  Void (draft only) / Issue Credit Note / Preview PDF / Send / Export.
- **Tab/Segment Nav** — `Tabs`: **Details** (default — the line items and header facts), **Payments**
  (receipt allocations against this invoice), **Activity** (status history + AI provenance + linked
  documents) — exactly the three sub-views `docs/frontend/LAYOUT_SYSTEM.md → Detail Page Template`
  names by name as its own worked example for this entity ("e.g. Invoice: Details / Payments /
  Activity"), so this document is filling in an example the platform's layout spec already committed to
  rather than inventing a new tab shape.
- **Main Column (8/12)** — the active tab's content: the read-only `InvoiceLinesTable` (Details), the
  `ReceiptAllocationList` (Payments), or the merged status-history + `audit_logs` + AI-provenance
  timeline (Activity).
- **Summary Rail (4/12)** — total amount (`AmountCell emphasis="strong"`), `CurrencyTag`, balance due,
  due date (with an `overdue`-tone `StatusPill` inline when applicable), linked sales order/delivery,
  linked journal entry id(s) (a clickable reference to `/accounting/journal-entries/{id}`, gated by that
  screen's own `accounting.journal.read` — this screen never assumes the viewer can also open it), and —
  only when `invoices.created_by` resolves to the AI service account or the invoice's originating
  `ai_recommendations` row is still referenced — the AI provenance block: `ConfidenceBadge`, agent name
  ("Sales Agent"), and a "View reasoning" trigger opening the AI reasoning `Sheet` (`# AI Integration`).
- **Activity Timeline** — always rendered at the bottom of the Main Column inside the Activity tab,
  never relegated to a rail footnote, per `LAYOUT_SYSTEM.md → Detail Page Template`.

## Credit Note Detail

A lighter variant of the same Detail Page Template at `/sales/invoices/credit-notes/[creditNoteId]`:
Page Header (credit note number, `StatusPill domain="credit_note"`, Post/Void actions), a two-tab
`Tabs` (**Details** — lines mirroring `invoice_items`, plus the reversed invoice's own link; **Activity**
— status history including any open `ai_approval_requests` step), and a Summary Rail carrying total,
applied amount, remaining amount, the originating invoice, and — for a credit note still awaiting the
Approval Assistant's routed sign-off on a large amount — an inline `ApprovalCard kind="credit_note"`
above the tabs rather than inside the rail, matching the prominence `JOURNAL_ENTRIES.md` gives its own
`ApprovalCard` for a `pending_approval` entry.

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, extended where noted, or is a
screen-specific composed component this document introduces for this exact surface. No new primitive or
generic finance component is introduced here without an explicit note that it is a candidate for
promotion into `COMPONENT_LIBRARY.md` — a component gap discovered while building this screen is
proposed as an addition to that catalogue, never hand-rolled locally and left undocumented, per
`JOURNAL_ENTRIES.md`'s own stated convention.

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The List's server-paginated, sortable, filterable table on both tabs (`resource="sales/invoices"` \| `"sales/credit-notes"`) |
| `StatusPill` | `components/shared/status-pill.tsx` | Every lifecycle-status rendering (`domain="invoice"` — already reserved in `COMPONENT_LIBRARY.md`'s own type union; `domain="credit_note"` — **extension**, see below) |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every total/balance-due/line-total figure, `mode="signed"` for the list's Total column, `mode="plain"` inside line items |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Header currency indicator on the Builder and the Summary Rail |
| `AgingBar` | `components/accounting/aging-bar.tsx` | The List's AR summary band (Invoices tab only) |
| `PeriodPicker` | `components/accounting/period-picker.tsx` | The List's Period filter |
| `CustomerPicker` | `components/sales/customer-picker.tsx` (screen-specific — new, see below) | The List's Customer filter; the Builder's Customer field |
| `ProductPicker` | `components/sales/product-picker.tsx` (screen-specific — new, see below) | Every line's product cell inside `InvoiceItemsTable` |
| `InvoiceForm` | `components/sales/invoice-form.tsx` (screen-specific — new, see below) | The Builder's outer form (header fields, Save Draft/Post, live totals) |
| `InvoiceItemsTable` | `components/sales/invoice-items-table.tsx` (screen-specific — new, see below) | The Builder's line-item editor — a plain, accessible table, not the ARIA `grid` pattern (`# Accessibility`) |
| `InvoiceDetail` | `components/sales/invoice-detail.tsx` (screen-specific) | The Detail route's read-only composition: header + `Tabs` (Details/Payments/Activity) |
| `InvoiceLinesTable` | `components/sales/invoice-lines-table.tsx` (screen-specific) | The Details tab's read-only line rendering |
| `ReceiptAllocationList` | `components/sales/receipt-allocation-list.tsx` (screen-specific — new, see below) | The Payments tab's allocation history |
| `RecordPaymentSheet` | `components/sales/record-payment-sheet.tsx` (screen-specific — new, see below) | The "Record Payment" quick-capture flow |
| `CreditNoteForm` | `components/sales/credit-note-form.tsx` (screen-specific, reuses `InvoiceItemsTable`'s read-only line rendering pre-filled from the source invoice) | "Issue Credit Note" |
| `ApprovalCard` | `components/shared/approval-card.tsx` | The AI-drafted-invoice accept/dismiss card (`kind="ai_recommendation"`); the large-credit-note approval chain (`kind="credit_note"` — **extension**, see below) |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | AI-provenance confidence display, Summary Rail and reasoning `Sheet` |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` | The Builder's "Apply AI draft" card when an `invoice_draft` recommendation exists for the linked order/delivery |
| `Tabs` | `components/ui/tabs.tsx` | Detail page's Details / Payments / Activity segmentation; the List's Invoices / Credit Notes segmentation |
| `Sheet` | `components/ui/sheet.tsx` | `RecordPaymentSheet`; the PDF preview panel; the AI reasoning drill-in; the mobile row-detail drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | Void confirmation, Send confirmation (recipient review), discard-draft confirmation, duplicate-suspected warning |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Row actions in the List; the Detail page's overflow menu |
| `Badge` | `components/ui/badge.tsx` | Aging-bucket chips in the Filter Bar; the "Reversed"/"AI-drafted" annotations |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, mapped from the API envelope |

## `StatusPill` extension — `domain="invoice"` and `domain="credit_note"`

`COMPONENT_LIBRARY.md`'s own `StatusPill` implementation ships a placeholder comment reserving this exact
work: "`invoice`, `bill`, `payroll_run`, `tax_return`, `bank_transfer` tables follow the identical shape,
each owned by its module's screen spec and imported here rather than redefined." This document is that
module's screen spec, so the two lookup tables are defined here, in full, once:

```tsx
// components/shared/status-pill.tsx (addition — merged into STATUS_TABLES)
const INVOICE_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:           { label: 'Draft', tone: 'neutral' },
  posted:          { label: 'Posted', tone: 'accent' },
  partially_paid:  { label: 'Partially paid', tone: 'warning' },
  paid:            { label: 'Paid', tone: 'success' },
  overdue:         { label: 'Overdue', tone: 'danger' },
  void:            { label: 'Void', tone: 'neutral' },
};

const CREDIT_NOTE_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:   { label: 'Draft', tone: 'neutral' },
  posted:  { label: 'Posted', tone: 'accent' },
  applied: { label: 'Applied', tone: 'success' },
  void:    { label: 'Void', tone: 'neutral' },
};

// STATUS_TABLES = { ...STATUS_TABLES, invoice: INVOICE_STATUS, credit_note: CREDIT_NOTE_STATUS };
```

`overdue` is the one status on this screen carrying `tone: 'danger'` on a document that is not rejected
or voided — a deliberate exception, because "money owed past its due date" is exactly the kind of
finance-semantic red `DARK_MODE.md → Finance status semantics` reserves that tone for, distinct from the
platform's general "danger tone means something failed" convention. `partially_paid` renders `warning`,
not `success`, because an invoice that is only partly collected is not yet a closed, healthy state —
this mirrors `docs/accounting/CUSTOMERS.md`'s own aging-severity framing of the identical status.

## `ApprovalCard` extension — `kind="credit_note"`

`COMPONENT_LIBRARY.md`'s `ApprovalCard` ships with a `PERMISSION_BY_KIND` discriminant already covering
`journal_entry | ai_recommendation | bank_transfer | payroll_release | tax_submission`. This document
extends that map with one new discriminant, following the exact extensible pattern the component was
designed for, rather than forking a parallel "credit note approval" component:

```tsx
// components/shared/approval-card.tsx (addition)
const PERMISSION_BY_KIND: Record<ApprovalKind, string> = {
  // ...existing entries unchanged
  credit_note: 'sales.credit_note.post',
};
```

This is the concrete rendering of `docs/ai/workflows/ORDER_TO_CASH.md → Orchestration`'s rule that
"large credit notes ≥ threshold route through `ai_approval_requests` `subject_type='credit_note'` first":
the Approval Assistant agent opens the routing row (never approves it itself), and this screen's
`ApprovalCard kind="credit_note"` is where a human Finance user or Sales Manager actually approves,
rejects, or delegates it, with `approvalLevel` rendering "Approval 1 of 2" exactly as it already does
for a multi-level journal-entry chain.

## `CustomerPicker` and `ProductPicker`

Two new searchable comboboxes, built on the identical shadcn `Command` + `Popover` pattern
`COMPONENT_LIBRARY.md`'s `AccountPicker` already establishes, reused here rather than re-invented because
an invoice's two reference lookups (which customer, which product) have exactly the same shape problem
`AccountPicker` solves for the Chart of Accounts: a company-scoped list too long for a native `<select>`,
searched by code/SKU and bilingual name.

**`CustomerPicker` props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `number \| null` | yes | Selected `customers.id`. |
| `onChange` | `(customerId: number, customer: Customer) => void` | yes | On selection, the Builder auto-fills `currency_code` (from the customer's default) and `due_date` (from `payment_terms`), both still editable. |
| `activeOnly` | `boolean` | no, default `true` | Filters to `customer_status != 'prospect_only'`-equivalent active accounts; a Sales Employee creating a brand-new customer's first invoice still reaches them via the picker's own "Create customer" affordance, which hands off to `docs/accounting/CUSTOMERS.md`'s own creation flow rather than duplicating it here. |
| `showCreditBadge` | `boolean` | no, default `true` in the Builder, `false` in the List filter | Renders a small inline badge next to a customer whose `outstanding_ar_balance` is at or above 90% of `credit_limit` — advisory only, mirroring the exact 90%-and-100% two-tier framing `docs/ai/workflows/ORDER_TO_CASH.md`'s credit-limit check defines, since this is informational context for the person raising the invoice, never a client-side block (only Sales Order confirmation enforces the credit gate; invoicing an already-confirmed, already-fulfilled order never re-blocks on credit). |

```tsx
// components/sales/customer-picker.tsx
'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { apiClient } from '@/lib/api-client';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem } from '@/components/ui/command';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ChevronsUpDown, Check } from 'lucide-react';
import { useLocale } from 'next-intl';

export function CustomerPicker({ value, onChange, activeOnly = true, showCreditBadge = true }: CustomerPickerProps) {
  const locale = useLocale();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebouncedValue(search, 250);

  const { data, isFetching } = useQuery({
    queryKey: ['accounting', 'customers', 'picker', { debouncedSearch, activeOnly }],
    queryFn: () =>
      apiClient.get<{ data: Customer[] }>('/api/v1/accounting/customers', {
        params: { q: debouncedSearch || undefined, 'filter[status]': activeOnly ? 'active' : undefined, per_page: 50, sort: 'name_en' },
      }),
    enabled: open,
  });

  const selected = data?.data.find((c) => c.id === value);
  const creditRatio = (c: Customer) => Number(c.outstanding_ar_balance) / Number(c.credit_limit || 1);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between font-normal">
          {selected
            ? <span className="truncate">{locale === 'ar' ? selected.name_ar : selected.name_en}</span>
            : <span className="text-ink-500">{'Select a customer…'}</span>}
          <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput placeholder="Search by name, phone, or tax ID…" value={search} onValueChange={setSearch} />
          <CommandList>
            {isFetching && <div className="p-4 text-sm text-ink-500">Searching…</div>}
            <CommandEmpty>No customer found.</CommandEmpty>
            <CommandGroup>
              {data?.data.map((customer) => (
                <CommandItem key={customer.id} value={String(customer.id)} onSelect={() => { onChange(customer.id, customer); setOpen(false); }}>
                  <Check className={value === customer.id ? 'opacity-100 me-2 h-4 w-4' : 'opacity-0 me-2 h-4 w-4'} />
                  <span className="truncate">{locale === 'ar' ? customer.name_ar : customer.name_en}</span>
                  {showCreditBadge && creditRatio(customer) >= 0.9 && (
                    <Badge tone={creditRatio(customer) >= 1 ? 'danger' : 'warning'} className="ms-auto">
                      {Math.round(creditRatio(customer) * 100)}% of limit
                    </Badge>
                  )}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
```

**`ProductPicker` props** mirror `AccountPicker`'s shape closely: `value`/`onChange` over `products.id`,
`sellableOnly` (default `true`, filters to `status = 'active'` products that are not
purchasing-only), and `onSelect` additionally returning the resolved `unit_price` (from the customer's
applicable `price_lists`/`price_list_items`, per `docs/accounting/SALES.md → Quotations → Line fields`)
and `cogs_unit_cost` context so `InvoiceItemsTable` can pre-fill a new line's price without a second
round trip. It queries `GET /api/v1/products` (`products.read` — a reference permission this screen
reads but does not own, exactly as `AccountPicker` reads Chart of Accounts data it does not own).

## `InvoiceItemsTable`

The Builder's line-item editor is deliberately **not** promoted to the ARIA `grid` pattern
`JournalLineGrid` uses. `ACCESSIBILITY.md → Data Tables Accessibility` states the `grid` pattern is
"reserved exclusively for surfaces that are genuinely spreadsheet-like," and names **exactly two** such
surfaces platform-wide: "the Journal Entry line editor, and the Bank Reconciliation matching grid." An
invoice's line items are add/remove/edit rows over a small, bounded list (rarely more than a handful of
lines), not a dense arrow-key-navigated ledger — so `InvoiceItemsTable` follows the same plain, accessible
`<table>` + `Tab`-order pattern `COMPONENT_LIBRARY.md`'s own general-purpose `JournalEntryForm` reference
implementation already uses for its lines region (the version *before* that screen's own promotion to
`JournalLineGrid`), never the heavier roving-tabindex grid. Introducing a third `grid`-pattern surface
here would contradict `ACCESSIBILITY.md`'s explicit "exactly two" framing for no interaction benefit this
screen actually needs.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `items` | `InvoiceItemFormValues[]` | yes | The current `useFieldArray` field list. |
| `onChangeItem` | `(index: number, patch: Partial<InvoiceItemFormValues>) => void` | yes | Applied via `form.setValue`, never local component state. |
| `onAddItem` | `() => void` | yes | Appends a blank line; focuses its Product cell. |
| `onRemoveItem` | `(index: number) => void` | yes | Disabled below 1 remaining item (`fields.length <= 1`) — unlike a journal entry, a single-line invoice is a valid, common shape (one product, or one lump-sum service line). |
| `currencyCode` | `string` | yes | Header currency, inherited by every line. |

```tsx
// components/sales/invoice-items-table.tsx
'use client';

import { ProductPicker } from '@/components/sales/product-picker';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { AmountCell } from '@/components/accounting/amount-cell';
import { Trash2, Plus } from 'lucide-react';
import { useTranslations } from 'next-intl';

export function InvoiceItemsTable({ items, onChangeItem, onAddItem, onRemoveItem, currencyCode }: InvoiceItemsTableProps) {
  const t = useTranslations('invoiceForm.items');

  return (
    <div className="rounded-lg border border-ink-150">
      <table className="w-full text-sm">
        <caption className="sr-only">{t('tableCaption')}</caption>
        <thead className="bg-ink-100 text-ink-700">
          <tr>
            <th scope="col" className="p-2 text-start">{t('product')}</th>
            <th scope="col" className="p-2 text-end w-24">{t('quantity')}</th>
            <th scope="col" className="p-2 text-end w-32">{t('unitPrice')}</th>
            <th scope="col" className="p-2 text-end w-28">{t('discount')}</th>
            <th scope="col" className="p-2 text-end w-24">{t('tax')}</th>
            <th scope="col" className="p-2 text-end w-32">{t('lineTotal')}</th>
            <th scope="col" className="p-2 w-10" />
          </tr>
        </thead>
        <tbody>
          {items.map((item, index) => {
            const lineSubtotal = Number(item.quantity || 0) * Number(item.unit_price || 0) - Number(item.discount_amount || 0);
            const lineTax = lineSubtotal * (Number(item.tax_rate_snapshot || 0) / 100);
            return (
              <tr key={item.id} className="border-t border-ink-150">
                <td className="p-2">
                  <ProductPicker
                    value={item.product_id}
                    onChange={(id, product, resolvedPrice) =>
                      onChangeItem(index, { product_id: id, unit_price: resolvedPrice ?? item.unit_price, uom_id: product.default_uom_id })
                    }
                    aria-label={t('a11y.productForLine', { line: index + 1 })}
                  />
                </td>
                <td className="p-2">
                  <Input variant="numeric" value={item.quantity} onChange={(e) => onChangeItem(index, { quantity: e.target.value })}
                         aria-label={t('a11y.quantityForLine', { line: index + 1 })} />
                </td>
                <td className="p-2">
                  <Input variant="numeric" value={item.unit_price} onChange={(e) => onChangeItem(index, { unit_price: e.target.value })}
                         aria-label={t('a11y.priceForLine', { line: index + 1 })} />
                </td>
                <td className="p-2">
                  <Input variant="numeric" value={item.discount_amount} onChange={(e) => onChangeItem(index, { discount_amount: e.target.value })}
                         aria-label={t('a11y.discountForLine', { line: index + 1 })} />
                </td>
                <td className="p-2 text-end text-ink-500">{item.tax_rate_snapshot}%</td>
                <td className="p-2 text-end">
                  <AmountCell amount={(lineSubtotal + lineTax).toFixed(4)} currencyCode={currencyCode} />
                </td>
                <td className="p-2">
                  <Button type="button" variant="ghost" size="icon" disabled={items.length <= 1}
                          onClick={() => onRemoveItem(index)} aria-label={t('a11y.removeLine', { line: index + 1 })}>
                    <Trash2 className="size-4 text-ink-500" />
                  </Button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <div className="p-2">
        <Button type="button" variant="ghost" size="sm" onClick={onAddItem}>
          <Plus className="size-3.5" /> {t('addLine')}
        </Button>
      </div>
    </div>
  );
}
```

The **Discount** column accepts a plain `discount_amount` — matching `invoice_items.discount_amount
NUMERIC(19,4)` exactly (`docs/accounting/SALES.md → Database Design → Invoices`) — rather than the
richer `discount_type`/`discount_value`/`discount_id`/`price_rule_id` structure `sales_quotation_items`
and `sales_order_items` carry. This is intentional, not a simplification this document invented: an
invoice generated from a confirmed order already has its discount frozen and copied verbatim onto
`invoice_items` (`docs/accounting/SALES.md → Sales Orders → Confirmation gate`, "Freeze pricing"), so by
the time a line reaches this table its discount is already a resolved amount, not a rule to re-evaluate.
For a manually-created invoice with no order behind it (a standalone service invoice), a small
percentage-entry convenience toggle in the UI (`10%` → computed `discount_amount` before the value ever
reaches `form.setValue`) is offered purely as client-side arithmetic, never transmitted to the API as a
percentage — the wire payload is always the plain `discount_amount` figure the column header states.

## `ReceiptAllocationList` and `RecordPaymentSheet`

`ReceiptAllocationList` renders the Payments tab's content: a plain, accessible table of every
`receipt_allocations` row referencing this invoice (receipt number, date, method, amount allocated,
allocating user), sorted `-allocated_at`, with a footer showing `amount_paid`/`balance_due` — both
already-computed fields the invoice payload itself carries, never re-summed client-side. `RecordPaymentSheet`
is a `Sheet` opened from the Detail page's "Record Payment" action, pre-scoped to this invoice's customer
and currency: it composes the same receipt-capture form the standalone `/sales/receipts` screen owns
(out of this document's scope), with `auto_allocate: true` and `allocation_method: 'manual'` pre-targeting
this specific invoice's `balance_due` as the default amount — a Finance user can still widen it to a full
receipt-capture flow covering multiple open invoices for the same customer by navigating to `/sales/receipts/new`
instead, which this Sheet's footer link offers rather than trying to cram every receipt scenario into
one invoice-scoped drawer.

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/SALES.md → API`'s own table (invoices/receipts/credit-notes
rows) or `docs/ai/workflows/ORDER_TO_CASH.md`'s AI-draft acceptance step, annotated with which hook and
query key this screen wires it to. All are versioned under `/api/v1/sales/`, carry `X-Company-Id`, and
return the standard envelope. Rows marked **(new)** are this document's narrow, named additions
(`# Route & Access`), never a contradiction of the backend's existing shape.

| Method & Path | Permission | Hook | Query key |
|---|---|---|---|
| `GET /sales/invoices` | `sales.invoice.read` | `useInvoices(filters)` | `invoiceKeys.list(filters)` |
| `GET /sales/invoices/{id}` | `sales.invoice.read` | `useInvoice(id)` | `invoiceKeys.detail(id)` |
| `POST /sales/invoices` | `sales.invoice.create` | `useCreateInvoice()` | invalidates `invoiceKeys.lists()` |
| `PATCH /sales/invoices/{id}` | `sales.invoice.create` (draft-only edit) | `useUpdateInvoice(id)` | invalidates `detail(id)` + `lists()` |
| `POST /sales/invoices/{id}/post` | `sales.invoice.post` | `usePostInvoice()` | pessimistic — see `## Mutations` |
| `POST /sales/invoices/{id}/void` | `sales.invoice.void` | `useVoidInvoice()` | invalidates `detail(id)` + `lists()` |
| `GET /sales/invoices/{id}/pdf` **(new)** | `sales.invoice.read` | `useInvoicePdf(id)` | `invoiceKeys.pdf(id)`, `enabled: previewOpen` |
| `POST /sales/invoices/{id}/send` **(new)** | `sales.invoice.send` | `useSendInvoice()` | invalidates `detail(id)` (stamps `last_sent_at`) |
| `GET /sales/reports/aging` | `sales.report.read` | `useInvoiceAging(filters)` | `invoiceKeys.aging(filters)` |
| `POST /sales/bulk/invoices/post` | `sales.invoice.post` | `useBulkPostInvoices()` | invalidates `lists()` on settle |
| `GET /sales/invoices/export` **(new, `sales.invoice.export`)** | `sales.invoice.export` | `useExportInvoices()` | not cached — async `report_runs` job, see `# Performance` |
| `GET /sales/credit-notes` | `sales.credit_note.read` | `useCreditNotes(filters)` | `creditNoteKeys.list(filters)` |
| `GET /sales/credit-notes/{id}` | `sales.credit_note.read` | `useCreditNote(id)` | `creditNoteKeys.detail(id)` |
| `POST /sales/credit-notes` | `sales.credit_note.create` | `useCreateCreditNote()` | invalidates `creditNoteKeys.lists()` + `invoiceKeys.detail(invoiceId)` |
| `POST /sales/credit-notes/{id}/post` | `sales.credit_note.post` | `useCreditNotePost()` | pessimistic |
| `GET /sales/receipts` | `sales.receipt.read` | `useReceipts(filters)` | out of this document's scope (`/sales/receipts` screen) |
| `POST /sales/receipts` | `sales.receipt.create` | `useCreateReceipt()` | invalidates `invoiceKeys.detail(invoiceId)` + `invoiceKeys.lists()` |
| `POST /sales/receipts/{id}/allocate` | `sales.receipt.allocate` | `useAllocateReceipt()` | invalidates `invoiceKeys.detail(invoiceId)` |
| `POST /sales/refunds` | `sales.refund.create` | `useCreateRefund()` | invalidates `creditNoteKeys.detail(creditNoteId)` |
| `POST /sales/invoices/{id}/accept-ai-draft` **(new)** | `sales.ai.approve_draft` + `sales.invoice.create` | `useAcceptInvoiceDraft()` | materializes `ai_recommendations` into a real `draft` invoice; invalidates `lists()` |

The one endpoint this document names that is not a literal path in `docs/accounting/SALES.md`'s own
table, `POST /sales/invoices/{id}/accept-ai-draft`, is a thin, explicit wrapper: it is functionally
`POST /sales/invoices` with the accepted `ai_recommendations.payload` as its body plus the
recommendation's own `id` for traceability, exactly matching the endpoint table step
`docs/ai/workflows/ORDER_TO_CASH.md → Orchestration` already gives for this transition ("Create invoice
(draft) — `POST /api/v1/sales/invoices` — `sales.invoice.create` **+** `sales.ai.approve_draft` if
materializing an `ai_recommendations` row"). This document names it as its own hook purely so the
frontend's "accept an AI draft" interaction has one obvious call site to invalidate/track, not because
the backend exposes a second create path.

## Query key factory

```ts
// lib/query/keys.ts (excerpt)
export const invoiceKeys = {
  all: ["sales", "invoices"] as const,
  lists: () => [...invoiceKeys.all, "list"] as const,
  list: (filters: InvoiceFilters) => [...invoiceKeys.lists(), filters] as const,
  details: () => [...invoiceKeys.all, "detail"] as const,
  detail: (id: number | string) => [...invoiceKeys.details(), id] as const,
  activity: (id: number | string) => [...invoiceKeys.detail(id), "activity"] as const,
  pdf: (id: number | string) => [...invoiceKeys.detail(id), "pdf"] as const,
  aging: (filters: AgingFilters) => [...invoiceKeys.all, "aging", filters] as const,
};

export const creditNoteKeys = {
  all: ["sales", "credit-notes"] as const,
  lists: () => [...creditNoteKeys.all, "list"] as const,
  list: (filters: CreditNoteFilters) => [...creditNoteKeys.lists(), filters] as const,
  detail: (id: number | string) => [...creditNoteKeys.all, "detail", id] as const,
};
```

`InvoiceFilters` mirrors the query parameters `docs/api/API_FILTERING_SORTING.md` documents against this
exact resource: `filter[status]` (array, `in`), `filter[customer_id]`, `filter[invoice_date][between]` /
`filter[due_date][between]` (or the named `range=` shortcut — mutually exclusive with an explicit
`between` on the same request, per that document's own rule), `filter[aging_bucket][in]` (the derived
`0-30|31-60|61-90|90+` enum, "computed via a generated column / view rather than trusted client math"),
`currency_code`, `q` (trigram search over `invoice_number`/`reference_number` plus the joined customer
name), and `sort` (default `-invoice_date`, switchable to `due_date` ascending for an aging-first read —
the exact sort a saved view named "Overdue > 90 days" already uses in that document's own worked
example). The List's four named filters map onto this parameter set directly: **Status** → `status`,
**Customer** → `customer_id`, **Period** → a resolved `invoice_date` range from `PeriodPicker`, **Aging**
→ `aging_bucket` (Invoices tab only — the Credit Notes tab has no aging concept, since a credit note is
either open or fully applied/refunded, not "overdue").

## Reference data

`CustomerPicker`, `ProductPicker`, the Status/Customer/Period/Aging filters, and tax-code resolution
inside `InvoiceItemsTable` all depend on read-mostly reference collections fetched through their own
short, independent queries with a generous `staleTime` (5 minutes, per `FRONTEND_ARCHITECTURE.md → Cache
tuning by data class`'s "rarely-changing reference/master data" tier) rather than being bundled into the
invoice payload:

| Reference | Endpoint | Consumed by |
|---|---|---|
| Customers | `GET /api/v1/accounting/customers` (`accounting.customer.read`) | `CustomerPicker`, the List's Customer filter |
| Products | `GET /api/v1/products` (`products.read`) | `ProductPicker` |
| Tax codes / rates | `GET /api/v1/accounting/tax-codes` (`accounting.read`) | `InvoiceItemsTable`'s per-line tax resolution |
| Fiscal periods | `GET /api/v1/accounting/fiscal-periods` | `PeriodPicker` (List filter) |
| Sales orders / deliveries | `GET /api/v1/sales/orders`, `GET /api/v1/sales/deliveries` | The Builder's optional "linked order/delivery" picker, and the Summary Rail's linked-document display |

## Cache tuning

Invoices and credit notes are both, unambiguously, the **"Transactional lists"** data class
`FRONTEND_ARCHITECTURE.md → Cache tuning by data class` names by literal example — the table's own row
reads `journal-entries, invoices, bills, purchase-orders — 30 seconds — "Frequent enough writes that a
stale list is a real annoyance, but not worth polling."` Both List queries therefore use `staleTime:
30_000`. The AR aging summary band, by contrast, is a **"Live/derived figures"** resource — correctness
of "how much is overdue right now" matters more than avoiding a refetch, so `useInvoiceAging` sets
`staleTime: 0` and leans on Realtime invalidation (`## Realtime`) rather than polling, the identical
tier `GENERAL_LEDGER.md`'s own balance strip uses for the same reason.

```ts
// hooks/sales/use-invoices.ts
export function useInvoices(filters: InvoiceFilters) {
  return useQuery({
    queryKey: invoiceKeys.list(filters),
    queryFn: () => api.get<Invoice[]>("/sales/invoices", { params: toQueryParams(filters) }),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}

export function useInvoiceAging(filters: AgingFilters) {
  return useQuery({
    queryKey: invoiceKeys.aging(filters),
    queryFn: () => api.get<InvoiceAgingSummary>("/sales/reports/aging", { params: filters }),
    staleTime: 0,
  });
}
```

## Pagination mode — page, not cursor

Unlike `GENERAL_LEDGER.md`'s cursor-only `ledger-entries`, the Invoices and Credit Notes lists use
**page-mode** pagination. `FRONTEND_ARCHITECTURE.md → Pagination: page mode vs. cursor mode` names
`invoices` directly as its own worked example of a bounded list: "Bounded lists (`accounts`, `invoices`,
`vendors`) use `useQuery` with `page`/`per_page` and `placeholderData: keepPreviousData`." This is a
deliberate platform-wide distinction, not an oversight: an AR ledger of a few thousand invoices is
bounded and page-number navigation ("page 4 of 49") is a meaningful, useful affordance for a Finance
user; an append-only ledger-entries stream spanning years of postings is not. `DataTable`'s own
`paginationMode="page"` is therefore the correct, matching mode for both tabs of this screen.

## Client state ownership

Consistent with `FRONTEND_ARCHITECTURE.md → State Management`:

| State | Owner |
|---|---|
| The invoice/credit-note lists, one record's detail, its activity, its PDF, the aging summary | TanStack Query cache, keyed as above |
| The in-progress Builder form values (header fields + `items[]`) | React Hook Form's internal state, schema-validated by `invoiceSchema` (`# Interactions & Flows`) |
| Active tab (Invoices/Credit Notes), Filter Bar state | URL search params — `?tab=`, `?filter[...]=`, `?sort=`, `?page=` — so a filtered, tabbed view is always a shareable, bookmarkable link, per `NAVIGATION_SYSTEM.md`'s stated rule that a Command Palette or Dashboard drill-through link "is the exact URL the Invoices screen's own filter UI would have produced by hand" |
| List density, column visibility | Zustand (`useDensityStore`, per-table-keyed) + `users.settings` sync |
| Selected rows for bulk action | Local component state inside `DataTable`, cleared on navigation or tab switch |
| Which row's PDF preview `Sheet` is open | Local `useState`, ephemeral |
| A mid-invoice draft's `Idempotency-Key`, keyed to the Builder's `formInstanceId` | `sessionStorage`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` |

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md`'s Principle 10 ("optimistic where safe, pessimistic where it moves
money"), this screen sorts cleanly into both halves: Post, Void, Send, credit-note Post, and receipt
capture/allocation all change the ledger's or the customer's authoritative state and therefore wait for
the server's `2xx` before the UI treats them as done, always behind a confirming dialog; dismissing an AI
recommendation, toggling density, and expanding a row are reversible, low-stakes, and update optimistically.

```ts
// hooks/sales/use-invoices.ts (excerpt)
export function usePostInvoice() {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey("invoice-post");
  return useMutation({
    // No onMutate — posting an invoice is money-moving (it creates Accounts Receivable), and always
    // renders a confirming dialog before it fires, per Principle 10.
    mutationFn: (id: number) => api.post(`/sales/invoices/${id}/post`, {}, idempotencyKey),
    onSuccess: (invoice: Invoice) => {
      qc.setQueryData(invoiceKeys.detail(invoice.id), invoice);
      qc.invalidateQueries({ queryKey: invoiceKeys.lists() });
      qc.invalidateQueries({ queryKey: invoiceKeys.aging({}) });
    },
  });
}

export function useCreateInvoice() {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey("invoice-create");
  return useMutation({
    // Creation is itself money-affecting per docs/accounting/SALES.md's own idempotency note
    // ("every POST that creates a money-affecting document... accepts an Idempotency-Key header"),
    // so this, too, has no onMutate — a draft invoice appears only once the server confirms it exists.
    mutationFn: (values: InvoiceInput) => api.post<Invoice>("/sales/invoices", values, idempotencyKey),
    onSuccess: () => qc.invalidateQueries({ queryKey: invoiceKeys.lists() }),
  });
}

export function useDismissAiRecommendation(recommendationId: number) {
  const qc = useQueryClient();
  return useMutation({
    // Dismissing a suggestion changes nothing financial — reversible, low-stakes, optimistic.
    mutationFn: (reason: string) => api.post(`/ai/recommendations/${recommendationId}/dismiss`, { reason }),
    onMutate: async (reason) => {
      await qc.cancelQueries({ queryKey: ["ai", "recommendations", recommendationId] });
      const previous = qc.getQueryData(["ai", "recommendations", recommendationId]);
      qc.setQueryData(["ai", "recommendations", recommendationId], (old: any) => ({ ...old, status: "dismissed", rejected_reason: reason }));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(["ai", "recommendations", recommendationId], ctx?.previous),
  });
}
```

`useVoidInvoice`, `useSendInvoice`, `useCreditNotePost`, and `useCreateReceipt`/`useAllocateReceipt`
follow `usePostInvoice`'s exact shape — no `onMutate`, a client-generated `Idempotency-Key` per logical
attempt, and a confirming `AlertDialog`/`Dialog` in front of every one of them (`# Interactions & Flows`).

## Realtime

The List, the Detail page, and the AI Rail's Sales-scoped feed all subscribe to the company's private
Sales channel via Echo, following the fixed `private-company.{id}.<feature>[.{sub_id}]` convention
`GENERAL_LEDGER.md` establishes, re-firing the exact domain events `docs/accounting/SALES.md` and
`docs/ai/workflows/ORDER_TO_CASH.md → Events Emitted/Consumed` already name — this hook invents no
parallel event vocabulary:

```ts
// hooks/sales/use-invoice-realtime.ts
'use client';
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/realtime/echo';
import { invoiceKeys, creditNoteKeys } from '@/lib/query/keys';

export function useInvoiceRealtime(companyId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`company.${companyId}.sales.invoices`);

    channel.listen('.invoice.posted', (e: InvoicePostedEvent) => {
      queryClient.setQueryData(invoiceKeys.detail(e.invoice_id), (old?: Invoice) =>
        old ? { ...old, status: 'posted', posted_at: e.posted_at, journal_entry_id: e.journal_entry_id } : old);
      queryClient.invalidateQueries({ queryKey: invoiceKeys.lists(), refetchType: 'inactive' });
      queryClient.invalidateQueries({ queryKey: invoiceKeys.aging({}) }); // AR moved — always refresh
    });

    for (const event of ['.invoice.paid', '.invoice.overdue', '.payment.received', '.credit_note.issued',
                          '.ai.recommendation_created']) {
      channel.listen(event, (e: any) => {
        queryClient.invalidateQueries({ queryKey: invoiceKeys.lists(), refetchType: 'inactive' });
        if (e.invoice_id) queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(e.invoice_id) });
        if (event === '.credit_note.issued') queryClient.invalidateQueries({ queryKey: creditNoteKeys.lists(), refetchType: 'inactive' });
      });
    }

    return () => { echo.leave(`company.${companyId}.sales.invoices`); };
  }, [companyId, queryClient]);
}
```

A currently-open Detail page is patched surgically via `setQueryData` (so a Finance user watching an
invoice mid-review sees another user's receipt land within the same second, the row briefly flashing an
`accent-subtle` tint per `DESIGN_LANGUAGE.md → Motion → Realtime row update`), while the List's own cached
pages are only marked `invalidateQueries(..., { refetchType: 'inactive' })` — refetched the next time
they become the active query, never yanked out from under a user mid-scroll, matching the identical
non-disruptive pattern `GENERAL_LEDGER.md`'s own realtime banner uses instead of a live splice
(`# Edge Cases`).

## AI agents feeding this screen

Per `docs/accounting/SALES.md → AI Responsibilities` and `docs/ai/workflows/ORDER_TO_CASH.md →
Participating Agents`, four agent behaviors surface here, each through the ordinary create/read API —
never a bespoke AI endpoint this screen has to special-case:

| Agent | What appears on this screen | Autonomy |
|---|---|---|
| Sales Agent | New `invoice_draft` recommendation cards (Builder's "Apply AI draft," or a List row that opens straight into a pre-filled Builder) | Suggest-only — lands as a real `draft` invoice only after `sales.ai.approve_draft` + `sales.invoice.create` accept it |
| General Accountant | The mapping-sanity-check confidence folded into the same `invoice_draft` recommendation's `confidence` figure (never a second, separate card) | Suggest-only |
| Fraud Detection | An additional required approval level and a `warning`-tone note on a large credit note's `ApprovalCard` | Escalation-only; never blocks posting outright |
| Approval Assistant | The routed approval chain for a credit note ≥ threshold (`ApprovalCard kind="credit_note"`, `approvalLevel`) | Routing only — never approves/rejects itself |
| Forecast Agent | Not rendered on this screen directly — its payment-date prediction informs Dashboard/collections messaging, not an affordance here (`docs/ai/workflows/ORDER_TO_CASH.md`: "informs any pre-due-date collections messaging, not the posting itself") | — |

# Interactions & Flows

**Creating a manual invoice.** From the List, "New Invoice" (gated `sales.invoice.create`) opens `/new`.
`InvoiceForm` mounts with `mode="create"`, one blank line already present. The Sales Employee picks a
customer (`CustomerPicker` auto-fills currency and due date from that customer's defaults), optionally
links a confirmed sales order or delivery (which, if selected, offers to bulk-populate `items[]` from
that document's own lines — a deterministic copy, not an AI action), adds/edits lines via
`InvoiceItemsTable`, and watches the Live Totals Rail update after every keystroke. "Save Draft" calls
`useCreateInvoice()`; the response's `id` becomes the URL (`router.push('/sales/invoices/{id}')`) so a
second "Save Draft" click edits the same record via `PATCH`, never creates a duplicate — the
`Idempotency-Key` generated at form-open time additionally protects the exact "double-tap Save" case a
flaky connection can produce.

**Accepting an AI-drafted invoice.** A Sales Employee or Finance user with `sales.ai.approve_draft` sees
a distinct row treatment in the List (an `ai-accent` left border and a small "AI-drafted" `Badge`) for
any confirmed order/delivered delivery the Sales Agent has already assembled an `invoice_draft` for but
no human has yet accepted, and the same recommendation surfaces in the AI Rail regardless of which page
they are on. Clicking it opens the Builder pre-filled from the recommendation's `payload` — customer,
currency, every line's product/quantity/price, `cogs_unit_cost` — with an `AIProposalPanel`-style card
in the Live Totals Rail showing the General Accountant's mapping-check confidence and reasoning. The
user can edit any field before saving (this is a real, editable `draft` the moment it is accepted, not a
locked AI output), and "Save Draft" here calls `useAcceptInvoiceDraft()` instead of the plain
`useCreateInvoice()`, which additionally marks the source `ai_recommendations` row `status: 'accepted'`.
Editing a field before saving does not retroactively change what the recommendation itself said — the
AI provenance block on the eventual Detail page shows the recommendation's original confidence/reasoning
verbatim, with the human's edits visible separately in the invoice's own activity history, so "what the
AI proposed" and "what actually got invoiced" both stay independently auditable.

**Posting an invoice.** From the Builder (a fresh or edited `draft`) or the Detail page's Page Header, the
primary action's label and behavior resolve from `sales.invoice.post`: a holder sees "Post" and clicking
it opens a confirming `AlertDialog` ("Post invoice INV-…? This creates a balanced journal entry and
cannot be undone in place — corrections after this point require a credit note.") before
`usePostInvoice()` fires; a non-holder's Builder instead shows only "Save Draft" (no Post button
rendered at all, per the platform's "hide, don't disable-with-no-recourse" rule for a permission that
genuinely will never apply to that role) and the Detail page's own action row is simply absent, since
`docs/accounting/SALES.md` defines no separate "submit for approval" step for an ordinary invoice — only
`sales.invoice.post` gates posting, full stop. On success, the Detail page's Summary Rail immediately
shows the returned `journal_entry_id`(s) as clickable references (Entry A always; Entry B alongside it
only when this invoice's billing policy is `on_order_confirmation`, per `docs/accounting/SALES.md →
Accounting Integration`'s exact posting-timing rule), and the List row's `StatusPill` flips from `Draft`
to `Posted` via the realtime patch described in `# Data & State`.

**Recording a payment.** From a `posted`/`partially_paid`/`overdue` invoice's Detail page, "Record
Payment" opens `RecordPaymentSheet`, pre-filled with the customer, currency, and the current
`balance_due` as the default amount. Choosing a payment method, reference number, and confirming calls
`useCreateReceipt()` with `auto_allocate: true` targeting this invoice — the identical shape
`docs/accounting/SALES.md → API → Example 4` already documents for a bank-transfer receipt. If the AI
layer has an existing `receipt_allocation_suggestion` for an already-captured, not-yet-fully-allocated
receipt from this same customer (per `docs/ai/workflows/ORDER_TO_CASH.md`'s `receipt_allocation_
suggestion` recommendation type), the Sheet shows that suggestion inline above the manual-entry form
first — "Receipt RC-…-07310 (KWD 1,835.400, unallocated) looks like a match for this invoice, 91%
confidence" — with Accept (calls `useAllocateReceipt()` against the existing receipt instead of creating
a new one) and Dismiss, never an automatic allocation. On success, the Payments tab's
`ReceiptAllocationList` gains the new row, `balance_due` recomputes, and — the instant allocation zeroes
it — the `StatusPill` flips to `Paid` via the same `.payment.received`/derived `.invoice.paid` realtime
pair `docs/ai/workflows/ORDER_TO_CASH.md → Events Emitted/Consumed` documents.

**Issuing a credit note.** From a `posted` (or later-status) invoice's overflow menu, "Issue Credit
Note" (gated `sales.credit_note.create`) opens `CreditNoteForm`, pre-filled read-only from the source
invoice's lines with an editable `quantity`/`unit_price` per line (a partial credit note need not
reverse every line in full) and a required `reason` (`return | pricing_error | goodwill |
duplicate_invoice | cancellation`, per `docs/accounting/SALES.md → Refunds → Credit note fields`).
Saving calls `useCreateCreditNote()`; if the computed `total_amount` crosses the company's configured
large-credit-note threshold, the response's credit note carries an open `ai_approval_requests` row the
Detail page immediately renders as an `ApprovalCard kind="credit_note"` above the tabs, and "Post" is
simply not the primary action available to the requesting user until that chain resolves — a second
approver's own visit to the same Detail page is where the actual Approve/Reject happens, never a second,
parallel approval screen. Once approved (or immediately, for a below-threshold credit note),
`sales.credit_note.post` holders see "Post," which fires the mirror-image journal entry
(`docs/accounting/SALES.md → Accounting Integration`, item 4) and updates the source invoice's own
Activity tab with a "Credit note CN-… issued" entry linking back.

**Voiding a draft invoice.** Only ever reachable for `status = 'draft'` — the action is entirely absent
from the overflow menu and row-action menu for any other status, per `docs/accounting/SALES.md → Void vs.
Credit Note`'s rule that a posted invoice can never be voided in place. Clicking "Void" opens an
`AlertDialog` requiring a reason (stored, never optional) before `useVoidInvoice()` fires; because a
`draft` invoice never posted, void has no accounting-reversal step of its own — it is a pure status
transition with an audit trail, distinct from a credit note's reversing posting.

**Previewing and sending a PDF.** "Preview PDF" (gated `sales.invoice.read` — previewing what you can
already read has no elevated permission requirement) opens a `Sheet` rendering the generated PDF
(backed by `invoices.pdf_attachment_id`, regenerated on demand if stale) inline. "Send" (gated
`sales.invoice.send`) opens a confirming `Dialog` reviewing the recipient — defaulted from the
customer's own primary contact email, editable — before `useSendInvoice()` fires; this mirrors
`GENERAL_LEDGER.md`'s own "Email statement" confirmation pattern exactly, because both are the same
underlying category of action: an externally-visible send that a human must explicitly review the
recipient for, never a background action a mutation quietly performs as a side effect of something else.

**Bulk-posting.** Selecting ≥1 `draft` row in the Invoices tab surfaces the Bulk Action Bar's "Post"
item (gated `sales.invoice.post`, and only ever offered when every selected row is itself a `draft` —
mixing statuses in one bulk-post attempt is prevented client-side by simply not rendering the action
when the selection is heterogeneous, rather than sending a request the server would partially reject).
`useBulkPostInvoices()` calls `POST /sales/bulk/invoices/post` once; the response's per-row
`data.results[i]` array is rendered as a small results `Dialog` listing which invoices posted and which
failed and why (e.g., one draft in the batch was concurrently voided by another user) — a partial
failure never rolls back the batch's successful postings, per `docs/accounting/SALES.md → API`'s bulk
contract.

**Filtering by aging bucket, and saved views.** Clicking a segment on the AR summary band's `AgingBar`
applies `filter[aging_bucket][in]=<bucket>` and scrolls the table into view; the identical URL is
produced whether the click originated here, from the Dashboard's own AR KPI tile, or from a Command
Palette customer result narrowed further by a user. A frequently-reused combination (e.g. "Overdue > 90
days, sorted by due date") is saved via the platform's `saved_filters` mechanism
(`docs/api/API_FILTERING_SORTING.md → Saved Filters/Views`) — the exact worked example that document
gives is this screen's own `resource: "sales.invoices"` — and reappears as a named chip in the Filter
Bar, re-validated against the live filter whitelist every time it is applied rather than replayed as a
raw stored query string.

# AI Integration

This screen is one of the platform's clearest illustrations of `FRONTEND_ARCHITECTURE.md`'s Principle 3
("AI proposes; a human, or an explicit audited policy, approves") because an AI-authored invoice is not
a hypothetical edge case here — it is the *common* path for any company billing `on_order_confirmation`
or `on_delivery`, per `docs/accounting/SALES.md → Invoices → Generation triggers`. Three AI surfaces
appear on this screen, and none of them can execute a money-moving action without a human clicking
through the same Accept/Post buttons every manually-typed invoice requires.

**Invoice-draft proposals.** The Sales Agent assembles an `invoice_draft` recommendation the moment its
triggering event fires (`sales_order.confirmed` for `on_order_confirmation` billing, `delivery.delivered`
for `on_delivery` billing), with the General Accountant's account-mapping sanity check attached as the
same recommendation's `confidence` figure — never a second, separately-scored card competing for
attention. The exact payload shape, reused here verbatim from `docs/ai/workflows/ORDER_TO_CASH.md →
Worked Example`:

```json
{
  "recommendable_type": "sales_orders",
  "recommendable_id": 9931,
  "agent_name": "sales_agent",
  "recommendation_type": "invoice_draft",
  "payload": {
    "sales_order_id": 9931, "customer_id": 1042, "currency_code": "KWD",
    "items": [
      { "product_id": 205, "quantity": "50.0000", "unit_price": "24.0000", "cogs_unit_cost": "16.8000" },
      { "product_id": 318, "quantity": "20.0000", "unit_price": "32.0000", "cogs_unit_cost": "21.5000" }
    ],
    "subtotal_amount": "1748.0000", "tax_amount": "87.4000", "total_amount": "1835.4000"
  },
  "confidence": 0.960,
  "reasoning": "Order 9931 fully confirmed with no amendments; line quantities and prices resolved directly from the order; General Accountant's account-mapping check passed with no anomaly.",
  "supporting_document_ids": [9931],
  "status": "pending"
}
```

`ConfidenceBadge` renders `96%` with the reasoning string in its `Tooltip`; the card's Accept action —
gated `sales.ai.approve_draft` **and** `sales.invoice.create`, both required, per the endpoint table's
own AND — opens the Builder pre-filled rather than posting anything directly, since `invoice_draft`'s
own downstream effect is always "materialize a `draft` a human still separately posts," never a
one-click "Do it" that both creates and posts in the same click. This is a deliberate, structural
narrowing of `AIProposalPanel`'s general three-button pattern: this recommendation type never renders a
"Do it" button at all, because `can_execute_directly` (the server-computed field that would gate one) is
never `true` for `recommendation_type: 'invoice_draft'` — creating AND posting a financial document
purely from an AI proposal is exactly the sensitive, money-moving action Principle 3 forbids a one-click
path for, regardless of confidence.

**Receipt-allocation suggestions.** The Sales Agent's `receipt_allocation_suggestion` recommendation
type (same enum, `docs/ai/workflows/ORDER_TO_CASH.md → Data & Tables Touched`) surfaces inside
`RecordPaymentSheet` when an unallocated or partially-allocated receipt exists for the invoice's
customer — the AI is proposing "this money that already arrived probably belongs to this invoice," not
proposing to create new money. Accept calls the existing `useAllocateReceipt()` mutation against the
already-`cleared` receipt; Dismiss requires a reason and never re-surfaces the same pairing.

**Large-credit-note approval routing.** Described fully in `# Interactions & Flows`; the Approval
Assistant's role here is exclusively routing (`docs/ai/workflows/ORDER_TO_CASH.md`: "Routing only — it
never approves, rejects, or decides anything itself"), so this screen never shows an "AI approved this"
state for a credit note — only a human decision, rendered through `ApprovalCard`, ever closes that loop.

**Stale recommendations.** If a user attempts to accept an `invoice_draft` (or a `receipt_allocation_
suggestion`) whose underlying `recommendable` has since changed — the order was amended after the draft
was proposed, or the candidate receipt was allocated elsewhere in the meantime — the accept call returns
`422 AI_RECOMMENDATION_STALE` (`docs/ai/workflows/ORDER_TO_CASH.md → Edge Cases`) rather than posting
against now-incorrect quantities. The UI surfaces this as a specific, named toast ("This suggestion is
out of date — the order has changed since it was proposed") and refreshes the card's own query, which
either re-fetches a freshly-regenerated recommendation or removes the card entirely if none remains
valid — never a generic "Something went wrong."

**Unavailable AI.** If the recommendations feed fails, times out, or AI is disabled for the company, the
List simply shows no AI-accent rows and the Builder's Live Totals Rail omits the "Apply AI draft" card —
every other capability (manual invoice creation, posting, payment recording, credit notes) remains fully
available, per the platform's "AI is strictly additive, never a gate" rule.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (List, first paint) | Query in flight, no cached data | `Skeleton` shaped to the Filter Bar + `DataTable`'s row/column geometry and the `AgingBar`'s bar shape, shimmer sweep; never a generic spinner |
| Loaded, has rows | Normal case | Table, aging band, and pagination footer render |
| Empty (no invoices ever created) | Zero rows, no filters active | `EmptyState`: "No invoices yet" with a "New Invoice" action (only rendered when `sales.invoice.create` is held) |
| Filtered to zero | Any filter/search legitimately excludes every row | `EmptyState` variant: "No invoices match your filters" with a "Clear filters" action — never conflated with the true-empty state above |
| Credit Notes tab, zero rows | No credit notes issued yet | Lighter `EmptyState`: "No credit notes issued" — no primary action rendered here even when `sales.credit_note.create` is held, since a credit note is always issued *from* an invoice's overflow menu, never from a blank Credit Notes tab with nothing to reverse |
| Fetching next page | Page-control click | Brief `isFetching` overlay on the table body (`aria-busy`), page number updates only once the new page's data resolves — never an optimistic page-number flip |
| Builder — loading reference data | `CustomerPicker`/`ProductPicker` combobox open, query in flight | "Searching…" row inside the `Command` list, per `AccountPicker`'s own established pattern |
| Builder — AI draft available | An unaccepted `invoice_draft` recommendation exists for the linked order/delivery | The Live Totals Rail's "Apply AI draft" card renders instead of staying empty; dismissible without blocking manual entry |
| Detail — AI-drafted invoice, reasoning not yet loaded | `GET .../ai-explanation`-equivalent still in flight | The Summary Rail's `ConfidenceBadge` renders immediately from the invoice payload's own cached `confidence`; "View reasoning" opens a `Sheet` with its own independent loading skeleton, never blocking the rest of the Detail page |
| Realtime new activity (List) | A `.payment.received`/`.invoice.posted`/`.credit_note.issued` event lands while a user is mid-scroll | Non-disruptive banner: "New activity — Refresh," never a live splice into the visible rows, per the platform's realtime-list rule |
| Permission revoked mid-session | A role change removes `sales.invoice.post` (etc.) while the Detail page is open | The next attempted action's `403` collapses that specific control to its permission-denied state with a toast explaining why — the frontend never assumes its own last-known permission snapshot is still valid, per `FRONTEND_ARCHITECTURE.md`'s Principle 4 |
| Error | `403`/`404`/`5xx`/network failure on any request | `ErrorState` with a retry action; a `403` on the whole route renders the shared "You don't have access to this" boundary rather than the Next.js default error screen |
| Bulk action, partial failure | `POST /sales/bulk/invoices/post` returns mixed `data.results[i]` outcomes | A results `Dialog` lists which rows succeeded/failed and why; the List's own cache invalidates to reflect only the rows that actually changed |

# Responsive Behavior

This screen follows `RESPONSIVE_DESIGN.md`'s platform-wide breakpoint tiers (Mobile/`base`–`sm`,
Tablet/`md:`, Laptop/`lg:`, Desktop/`xl:`–`2xl:`, Ultra Wide/`3xl:`) and reuses that document's own
**worked Invoices-list example** verbatim rather than inventing a parallel responsive treatment:

```tsx
// app/(app)/sales/invoices/page.tsx — RESPONSIVE_DESIGN.md's own citation of this exact route
export default function InvoicesPage() {
  return (
    <Container>
      <div className="flex flex-col gap-4 py-6 md:flex-row md:items-center md:justify-between">
        <h1 className="text-[length:var(--text-heading)] font-semibold">{t('sales.invoices.title')}</h1>
        <div className="flex gap-2">
          <FiltersTrigger /> {/* below md: opens a Sheet; md and up: renders the filter bar inline */}
          <ExportMenu />     {/* below md: kebab DropdownMenu; md and up: two inline buttons */}
        </div>
      </div>
      <InvoicesTable />
      <CreateInvoiceFab className="md:hidden" /> {/* below md; md+ uses the inline header button instead */}
    </Container>
  );
}
```

`FiltersTrigger`, `ExportMenu`, and `CreateInvoiceFab`/the header button are each a single component
with the responsive branch inside them, so this page composes four already-solved responsive problems
(list-to-card, filter-bar-to-sheet, buttons-to-kebab, header-button-to-FAB) without a single ad hoc
breakpoint check inside the page itself, per `RESPONSIVE_DESIGN.md`'s stated rule that "the page only
decides *what* to render, never *how* it reflows."

**Mobile (`base`–`sm`).** The List's table becomes a stack of invoice cards (`ResponsiveDataView`'s
card-mode, per `RESPONSIVE_DESIGN.md → Pattern 1 — Data tables become cards`), each showing invoice
number, customer, `StatusPill`, due date, and total/balance-due, tapping through to the Detail page. The
`AgingBar` collapses to a single stacked horizontal bar plus a 2×2 bucket legend grid rather than the
desktop's wider single row. The Credit Notes tab remains a segmented control at the top, still
horizontally swipeable if narrower than the viewport. The Builder's `InvoiceItemsTable` becomes a stack
of per-line cards — `RESPONSIVE_DESIGN.md → Forms On Mobile → Multi-line editors: journal lines and
invoice items` names this screen's own line editor directly alongside the Journal Entry line editor as
the platform's two worked examples of this exact table-to-card transformation:

```tsx
// components/sales/invoice-item-row.tsx
function InvoiceItemRow({ index, control }: { index: number; control: Control<InvoiceFormValues> }) {
  const tier = useBreakpoint();
  const isCompact = tier === 'base' || tier === 'sm';

  if (isCompact) {
    return (
      <div className="rounded-xl border border-border p-3">
        <div className="grid grid-cols-1 gap-3">
          <ProductCombobox control={control} name={`items.${index}.product_id`} />
          <div className="grid grid-cols-2 gap-3">
            <MoneyField control={control} name={`items.${index}.quantity`} label={t('sales.quantity')} />
            <MoneyField control={control} name={`items.${index}.unit_price`} label={t('sales.unitPrice')} />
          </div>
          <Button variant="ghost" size="sm" className="min-h-11 text-destructive" onClick={() => remove(index)}>
            <Trash2 className="size-4" /> {t('common.removeLine')}
          </Button>
        </div>
      </div>
    );
  }

  // lg: and up — a dense, inline editable row; the ProductPicker's popover width tracks the trigger
  return (
    <div className="grid grid-cols-[2fr_1fr_1fr_1fr_1fr_2.5rem] items-center gap-3 border-b border-border py-2">
      <ProductCombobox control={control} name={`items.${index}.product_id`} />
      <MoneyField control={control} name={`items.${index}.quantity`} />
      <MoneyField control={control} name={`items.${index}.unit_price`} />
      <MoneyField control={control} name={`items.${index}.discount_amount`} />
      <span className="text-end text-ink-500">{watch(`items.${index}.tax_rate_snapshot`)}%</span>
      <Button variant="ghost" size="icon" className="justify-self-end" onClick={() => remove(index)}>
        <Trash2 className="size-4" />
      </Button>
    </div>
  );
}
```

The Builder's Live Totals Rail collapses to an inline panel directly above the sticky Save Draft/Post
bar rather than a persistent side column, and — per `RESPONSIVE_DESIGN.md → Forms On Mobile → Sticky
submission and error handling` — the action bar stays `sticky bottom-0`, offset above the safe area, so
a long invoice with many lines never hides its own Save/Post button below the fold; a failed submit
auto-scrolls to the first invalid field. A sales employee drafting an invoice between client visits
benefits from the same debounced IndexedDB draft-autosave `RESPONSIVE_DESIGN.md` specifies for exactly
this scenario, reconciled against the server draft on reconnect using the same `Idempotency-Key`
generated at form-open time — a dropped connection mid-submission never produces two invoices.

**Tablet and up (`md:`+).** The List's Filter Bar renders inline instead of behind a `Sheet` trigger; the
Builder gains its two/three-column header grid and the persistent Live Totals Rail at `xl:`+. The Detail
page's Summary Rail becomes a true 4/12 column at `lg:`+; below that it stacks beneath the Main Column's
active tab content.

**Touch targets.** Every interactive control on this screen — row-action icon buttons, `AgingBar`
bucket segments, the FAB, line-item remove buttons — meets the platform's 44×44px minimum regardless of
density, per `RESPONSIVE_DESIGN.md → Touch Targets & Gestures`.

# RTL & Localization

Every string on this screen ships as an EN/AR pair; direction is a single root-level attribute no
component here branches on individually, per `docs/frontend/README.md`'s "no `*.rtl.tsx` file" rule.

- **Bilingual customers and products.** `CustomerPicker` and `ProductPicker` render `name_ar` under an
  Arabic session, falling back to `name_en` only if the Arabic name is genuinely absent — never the
  reverse. The Invoice PDF itself renders fully in the customer's/company's configured document
  language, independent of the *viewer's* current UI session language (a Kuwaiti company can issue an
  Arabic-language PDF to a customer while the Sales Employee who generated it is working in an
  English-language session).
- **Invoice/credit-note numbers, dates, and confidence percentages stay LTR-isolated** inside Arabic
  sentences (a List row's due-date cell, the AI reasoning tooltip's narrative, a toast referencing
  `INV-KWC-2026-14502`) via the shared bidi-isolation wrapper (`dir="ltr"` + `unicode-bidi: isolate"`),
  so a reference number never visually reorders inside a right-to-left sentence.
- **Amount columns stay physically `text-end` in both directions**, via `AmountCell`'s own `align="end"`
  default — Total, Balance Due, and every line-item figure land on the same edge regardless of session
  language, so a bilingual Finance team scanning magnitude by decimal alignment sees no discontinuity
  switching between an English and an Arabic session.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching
  `AmountCell`'s platform-wide implementation and Gulf financial-document convention — an invoice total
  never renders in Eastern Arabic-Indic digits.
- **`AgingBar`'s bucket order is physically fixed, never mirrored** — `0-30 → 31-60 → 61-90 → 90+` reads
  left-to-right in both languages, because the bar encodes a magnitude progression (newer debt to older
  debt), not a reading direction, exactly matching `ICONOGRAPHY.md`'s directional-glyph rule that an
  inflow/outflow or severity-progression indicator "encodes a financial direction, not a reading
  direction, and must look identical in both languages."
- **Directional chrome mirrors; content chrome does not.** `RecordPaymentSheet`, the PDF preview `Sheet`,
  and the mobile row-detail drawer slide from the logical end edge (left in RTL); breadcrumb chevrons
  mirror; `StatusPill`'s status dot and every severity glyph do not.
- **The Builder's two/three-column header grid mirrors as a unit** via logical grid flow, never a
  manual `flex-row-reverse` — Customer, Currency, Invoice Date, and Due Date read in the
  culturally-correct order for the session language without a single per-field RTL branch.
- **Exported PDFs are the one place direction is a document property, not a session property** — an
  invoice generated for a customer whose configured language is Arabic renders fully right-to-left
  (line-item table column order mirrored, Arabic numerals for narrative text where the company's own
  numeral-system setting requests it, Western digits for the total per the platform default) regardless
  of which language the Sales Employee who clicked "Send" happened to be working in.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a Tailwind palette utility; every surface, border, and status color
resolves through the semantic tokens `DARK_MODE.md → Token Mapping` defines.

- **Surfaces and elevation.** The List's canvas sits on `--surface-canvas`; the `AgingBar`'s track, the
  table, and the sticky pagination footer sit on `--surface-base`; `RecordPaymentSheet`, the PDF preview
  `Sheet`, and every `Dialog`/`AlertDialog` sit on `--surface-raised`/`--surface-overlay`. Elevation is
  communicated by the surface getting lighter toward the viewer in dark mode, never by shadow alone, so
  the Builder's sticky footer and the List's sticky pagination bar both carry their paired
  `--border-subtle`/`--border-default` hairline even where the light-mode equivalent leans on shadow.
- **Invoice status colors reuse the platform's four-state finance-status mapping exactly.** `Draft` →
  neutral ink; `Posted` → accent; `Partially paid`/`overdue-approaching` → `--status-warning`;
  `Overdue` → `--status-error`; `Paid` → `--status-success`; `Void` → neutral ink, struck-through tone in
  the List row. No new hue is introduced for invoices — `# Components Used`'s `INVOICE_STATUS`/
  `CREDIT_NOTE_STATUS` tables consume the same `Tone` union (`neutral | accent | success | warning |
  danger`) `StatusPill` already re-tunes per-theme in `DARK_MODE.md → Finance status semantics`.
- **`AgingBar`'s bucket colors escalate through the same warning→danger ramp**, never a rainbow gradient
  — `0-30` neutral ink, `31-60` half-opacity warning, `61-90` full warning, `90+` danger — re-tuned for
  dark-mode contrast exactly as `DARK_MODE.md → Charts & Data Viz In Dark`'s "financial charts map to
  semantic tokens, not the categorical palette" rule requires, since an aging bar is a severity
  indicator, not a categorical breakdown.
- **AI provenance uses the reserved AI accent, never a finance-semantic color.** The AI-drafted row's
  left border, the `invoice_draft` card's `Sparkles`-style glyph, and every `ConfidenceBadge` resolve
  through `--ai-accent`/`--ai-accent-subtle` in both themes — "this was AI-touched" and "this invoice is
  overdue" stay visually distinct colors regardless of theme.
- **Contrast.** Every token pairing on this screen — including the `AgingBar`'s bucket segments against
  their track, and the sticky footer's dividing border — is independently verified at ≥4.5:1 (text) /
  ≥3:1 (non-text) in both themes, per `DARK_MODE.md → Color & Contrast In Dark`.
- **Exported PDFs and sent invoice emails always render in QAYD's fixed light/print palette** regardless
  of the viewer's active theme, per `DARK_MODE.md → Exported PDFs always render light` — a PDF emailed
  to a customer is never expected to open a dark-mode-aware viewer, and a Finance user working in dark
  mode all day would otherwise be surprised by an invoice PDF that suddenly looked inverted.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, which this screen implements without deviation.

- **Plain accessible `<table>` for the List, on both tabs — never the ARIA `grid` pattern.**
  `ACCESSIBILITY.md → Data Tables Accessibility` names "Invoices" directly, by name, in its list of
  tables "whose primary interaction is *read, sort, filter, paginate, click a row to navigate, click a
  row action*" — the same plain-table pattern Trial Balance, General Ledger, and the Journal Entries
  list use. Credit Notes, sharing the identical `DataTable`, follows suit with no separate decision
  required.
- **The Builder's `InvoiceItemsTable` is also a plain table, not the ARIA `grid` pattern**, per
  `# Components Used`'s own justification: `ACCESSIBILITY.md` reserves the heavier `grid` pattern for
  "exactly two" surfaces platform-wide (the Journal Entry line editor, the Bank Reconciliation matching
  grid), and an invoice's line items — add/remove/edit rows over a small, bounded list — do not warrant
  a third. Keyboard users move cell-to-cell with ordinary `Tab`/`Shift+Tab`, which is sufficient for the
  typical 1–10 line count this table holds.
- **Sortable List headers are real `<button>`s with `aria-sort` on the parent `<th>`**, and the
  horizontally-scrolling region wrapping the table on narrow desktop widths is itself keyboard-operable
  (`role="region"` + `aria-label` + `tabIndex={0}`), per `ACCESSIBILITY.md`'s worked pattern for exactly
  this class of table.
- **Row-level accessible names are unique, always.** Every icon-only row action (View, Post, Void, Send)
  carries `aria-label={t('a11y.postInvoice', { number: invoice.invoiceNumber })}` — "Post invoice
  INV-KWC-2026-14502" — never a bare "Post," matching `ACCESSIBILITY.md`'s `IconButton` lint rule for
  any icon-only control rendered inside a row `.map()`.
- **Loading and streaming are announced, not silently swapped.** `<tbody aria-live="polite"
  aria-busy={isLoading}>` on the List; the Builder's Live Totals Rail similarly wraps its running
  subtotal/tax/total in a `aria-live="polite"` region so a screen-reader user typing a new quantity
  hears the updated total without needing to re-navigate to it.
- **`AgingBar` is `role="img"` with a full-summary `aria-label`, and each bucket is a real `<button>`**
  exposing its own amount and count as visible-and-accessible text, never color/position alone — per
  `COMPONENT_LIBRARY.md`'s own accessibility note for this exact component.
- **RBAC-aware disabled controls explain themselves, and are distinguished from business-rule-disabled
  controls.** A "Post" button rendered because the viewer holds `sales.invoice.post` but the invoice is
  currently out of balance (a defensive UI state that should be unreachable given the server's own
  arithmetic, but is still specified) carries `aria-describedby` text specific to *that* reason ("This
  invoice's totals don't reconcile — re-check line item amounts"); a control omitted entirely for lack
  of permission is omitted, never rendered disabled-with-no-explanation, per `ACCESSIBILITY.md → RBAC-
  aware disabled controls must explain themselves`. "Send" specifically, when `sales.invoice.send` is
  absent, is never shown as a disabled button next to a working "Preview PDF" — it is not in the DOM,
  because a determined but unauthorized user should not even learn the affordance exists to ask for it.
- **Focus management on overlays.** `RecordPaymentSheet`'s, the credit-note `Dialog`'s, and every
  `AlertDialog`'s `onOpenAutoFocus` moves focus to the sheet/dialog's own heading; closing any of them
  (Escape, backdrop click, or completing the action) returns focus to the control that opened it, never
  to the page's top.
- **Keyboard shortcuts.** `N` opens the Builder (context-aware per `ACCESSIBILITY.md`'s own citation of
  this screen); `Cmd/Ctrl+Enter` confirms the currently-open dialog (Void confirmation, Send
  confirmation, the bulk-post results dialog's "Done"); arrow keys are never wired to `InvoiceItemsTable`
  (there is no grid to navigate — see above), so they behave as ordinary page scroll, not a trapped grid
  navigation a user would have to learn is absent.
- **Amounts carry meaning beyond color.** Per `AmountCell`'s platform-wide contract, "color is always a
  secondary signal" — Total and Balance Due never rely on color alone to communicate magnitude or sign;
  an `overdue` invoice's redness on the `StatusPill` is always paired with the word "Overdue," never a
  bare red dot.

# Performance

- **Page-mode pagination, not cursor, by design.** Per `# Data & State`, this screen's scroll/page cost
  is the ordinary `LIMIT/OFFSET` cost `docs/api/API_FILTERING_SORTING.md → Combining With Pagination`
  describes for a bounded resource — `meta.pagination.total` always reflects the fully filtered result
  set, computed once per page request, never an unbounded scan.
- **`staleTime: 30_000` for both lists**, per the "Transactional lists" cache-tuning tier
  `FRONTEND_ARCHITECTURE.md` names `invoices` under by literal example; `staleTime: 0` for the AR aging
  summary band, kept fresh via Realtime invalidation rather than polling, matching the "Live/derived
  figures" tier's own rationale.
- **`placeholderData: keepPreviousData`** on both the List query and the aging-summary query so neither
  the table nor the band flashes empty between a filter change and its resolved response.
- **Debounced filter/search inputs.** The List's `q` search and the Customer/Product combobox searches
  debounce 250–300ms before triggering a new query key, per the platform-wide debounce rule already
  established for `AccountPicker`.
- **Reference data is cached generously.** `CustomerPicker`, `ProductPicker`, tax codes, and fiscal
  periods all sit in the 5-minute "rarely-changing reference data" tier, so reopening the Builder for a
  second invoice in the same session rarely re-fetches them.
- **SSR-seeded first paint.** The List's Page Header (title, tab strip) renders on the server; the first
  page of invoices/credit notes for a resolved default filter set (or any URL-carried filters, e.g. from
  a bookmarked "Overdue > 90 days" saved view) is prefetched server-side and hydrated into the client
  cache, so the first client-side `useQuery` call for those same keys resolves instantly.
- **PDF generation is asynchronous for anything not already cached.** `GET /sales/invoices/{id}/pdf`
  returns the existing `pdf_attachment_id`'s signed URL immediately if the invoice's line items haven't
  changed since it was last generated; otherwise it regenerates synchronously for a typical
  single-digit-line invoice (well under the export endpoint's async threshold) — the Preview `Sheet`
  shows a brief generation skeleton rather than blocking the whole Detail page.
- **List/aggregate export follows the platform's async-report pattern.** A filtered export whose
  estimated row count exceeds the export endpoint's synchronous threshold returns `202 Accepted` with a
  job id; the Export menu's own "Recent exports" list re-surfaces the finished file via notification
  rather than holding a spinner open on the button, per the identical pattern `GENERAL_LEDGER.md`
  specifies for its own export.
- **Bulk-post is one request, not N.** `POST /sales/bulk/invoices/post` posts every selected `draft` in
  one call with per-item sub-transactions server-side, so selecting 25 drafts and bulk-posting them does
  not fire 25 separate round trips from the client.
- **AI calls never block the core reading/writing experience.** The `invoice_draft`/`receipt_allocation_
suggestion` feeds are independent queries that never gate the List's or the Builder's own render — a
  slow or failed AI call degrades only the AI-accent row treatment and the "Apply AI draft" card, never
  a Sales Employee's ability to type and post an invoice by hand.

# Edge Cases

1. **Concurrent receipt allocation racing an invoice to zero balance.** Per `docs/ai/workflows/
   ORDER_TO_CASH.md → Edge Cases`, a receipt allocation targeting an invoice whose `balance_due` reached
   zero moments earlier (two receipts racing the same invoice) returns `409 INVOICE_ALREADY_SETTLED`;
   `RecordPaymentSheet` surfaces this as a specific message ("This invoice was just settled by another
   payment — choose a different invoice or record this as an on-account credit") and refreshes the
   invoice's cached `balance_due` rather than silently retrying.
2. **A receipt bounces after the invoice it settled was already marked `paid`.** The reversing entry
   re-opens the exact `receipt_allocations` amount, `status` reverts to `partially_paid`/`unpaid`, and —
   critically — the customer's aging clock resumes from the invoice's *original* `due_date`, never from
   the bounce date (`docs/ai/workflows/ORDER_TO_CASH.md`); the List and `AgingBar` reflect this the
   moment the realtime event lands, without a page refresh.
3. **Mixed stock/service lines on one invoice.** Only the stock-tracked lines contribute a COGS/Inventory
   posting at Entry B; a service line's contribution to Entry A (revenue, tax) still posts normally.
   `InvoiceLinesTable` never implies a COGS figure exists for a line where `cogs_unit_cost` is `null`.
4. **`on_delivery` billing policy — COGS already posted before invoicing.** For these invoices, Entry B
   was posted at the delivery's own `delivery.shipped` event, tagged by `delivery_item_id`; posting the
   invoice here only produces Entry A, and the Summary Rail's "Journal entries" list correctly shows one
   entry, not two, for this billing policy — never a duplicate COGS posting.
5. **Marketplace-channel invoice with a commission deduction line.** Per `docs/accounting/SALES.md →
   Accounting Integration`, item 6, this invoice's Entry A carries a distinct `Accounts Receivable
   (marketplace as receivable party)` debit net of commission plus a separate `Marketplace Commission
   Expense` line — `InvoiceLinesTable` renders the commission line like any other line item (a negative-
   value line referencing the "Marketplace Commission" product), never hidden or netted away from the
   visible total.
6. **A credit note with a retained restocking fee.** The fee posts as its own small revenue line within
   the same journal entry rather than being netted into the reversal — the Credit Note Detail's line
   table shows both the reversed product lines and the retained-fee line distinctly, matching
   `docs/accounting/SALES.md → Refunds`'s explicit "not netted" rule.
7. **Store credit left open on a credit note.** A credit note whose `remaining_amount > 0` and no refund
   has been issued shows `status: 'posted'` with a visible "Available as store credit: KWD X" note on its
   Detail page rather than appearing "done" — this remaining amount is discoverable at the customer's
   next invoice (via the Builder's own credit-note-application affordance, out of this document's line-
   level detail but referenced from the Summary Rail) and is never auto-expired silently even past a
   configured `store_credit_expiry_days`, which instead flags it for finance review.
8. **Tax rate changed mid-cycle.** A quotation/order line's `tax_rate_snapshot` is frozen at the time it
   was quoted/ordered; an invoice generated from that document later reuses the frozen snapshot, never
   the tax code's current rate — the Builder's tax column for an order-derived line is therefore
   read-only by convention (editable only for a fully manual, non-order-derived invoice line), and this
   document does not surface a tax-rate mismatch as an error, since re-pricing an already-quoted line
   would itself be the defect.
9. **Void attempted on a non-draft invoice via a stale cached menu.** Structurally unreachable through
   this screen's own UI (the action is never rendered for a non-draft status), but a stale client bundle
   or a direct API call still receives the server's own `422`/`409` rejection — the frontend's own
   omission of the control is a courtesy, never the actual enforcement boundary, per `FRONTEND_
   ARCHITECTURE.md`'s Principle 4.
10. **Export of a very large filtered invoice list.** Mirrors the platform's async-report pattern: a
    filter combination matching tens of thousands of invoices returns `202 Accepted` with a job id rather
    than holding the Export button's spinner open indefinitely.
11. **AI recommendation accepted, then the underlying order/receipt changes before the resulting draft is
    saved.** Handled by `422 AI_RECOMMENDATION_STALE` (`# AI Integration`) — the Builder never silently
    submits stale AI-sourced quantities.
12. **A customer with no configured `payment_terms`.** `CustomerPicker`'s auto-fill of `due_date` falls
    back to the company's own default payment term (commonly `due_on_receipt`) rather than leaving the
    field blank or crashing the auto-fill logic; the field remains editable either way.
13. **Session permission change mid-view.** If `sales.invoice.post` is revoked while the Detail page is
    open, the next click's `403` collapses that action to its permission-denied state with a toast
    explaining why, rather than a stuck or silently-failing button — identical to the pattern
    `GENERAL_LEDGER.md` specifies for its own Export action losing its permission mid-session.
14. **Multi-currency invoice where the receipt's currency/rate differs.** Per `docs/accounting/SALES.md
    → Accounting Integration`, item 2, an FX gain/loss line posts alongside the ordinary Cash/Bank-to-AR
    entry; the Payments tab's `ReceiptAllocationList` surfaces this as a small "FX adjustment" annotation
    on the relevant allocation row rather than an unexplained discrepancy between the receipt's amount
    and the amount actually cleared against the invoice.

# End of Document
