# Customers — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: CUSTOMERS
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Customer Management screen set: the
customers **List** and the customer **Profile** — the Accounts Receivable sub-ledger UI over the
`customers` / `customer_contacts` / `customer_addresses` / `customer_documents` / `customer_balances`
tables that `docs/accounting/CUSTOMERS.md` defines as the single system of record for every party a
QAYD tenant extends goods, services, or credit to. Every quotation, order, invoice, credit note, and
receipt in the platform resolves back to exactly one `customers` row, and this screen is where a
Sales Employee qualifies a new account, where a Finance Manager decides whether to raise a credit
limit or block a customer over their exposure, and where the AI layer's risk scoring and payment
prediction become a number a human actually looks at before deciding anything. This is, in other
words, the screen where QAYD's "AI proposes, a human approves" rule is exercised most often in
day-to-day operation outside of Journal Entries itself — a credit-limit increase, a blacklist, or a
merge are all financially and reputationally consequential, and none of them ever executes without a
human decision recorded against it.

Three sub-surfaces are covered by this one document, sharing one route family, one permission
surface, and one set of composed components, per `docs/frontend/README.md`'s "a screen is added to
this system by adding a route segment, a query hook, and a handful of composed components" rule:

1. **List** (`/customers`) — browse, search, filter by segment/status/type/credit standing, sort,
   bulk act, import, and export. The default landing surface for every role holding
   `accounting.customer.read`.
2. **Create / Edit** (`/customers/new`, and the edit mode of the Profile route) — the `CustomerForm`
   multi-section form (identity, contacts, addresses, financial terms) built on React Hook Form + Zod,
   mirroring `docs/accounting/CUSTOMERS.md → Workflow → Customer Creation`'s `StoreCustomerRequest`
   validation field-for-field.
3. **Profile** (`/customers/[customerId]`) — the full picture of one customer: identity and contacts,
   credit standing and AI risk signal, AR aging and running statement, commercial transaction history,
   compliance documents, and the complete audit timeline. This is also where the sensitive lifecycle
   actions live — credit-limit change requests, blacklist/reinstate, merge, and archive — each gated by
   its own permission key and, for the first three, routed through the shared Approval Center rather
   than executed inline.

This screen never computes a financial truth of its own. Credit exposure, the aging-bucket
assignment, the risk score, and the payment prediction are all server-computed —
`CustomerCreditService`, the nightly/event-driven Risk Scoring agent, and the Aging Report engine, per
`docs/accounting/CUSTOMERS.md → Credit Management` and `→ AI Responsibilities` — and the frontend's
only job is to render them, poll/subscribe for their latest value, and gate the handful of mutations
a human is allowed to make in response. Nothing in this document introduces a validation rule, a
permission key, a lifecycle state, or an API shape that is not already defined in that backend
specification; where this document introduces something screen-specific (a composed component, a
convenience endpoint that thinly wraps an existing backend service), it says so explicitly rather than
implying it was already specified elsewhere.

**Customer Management is promoted to a first-class, top-level nav destination (`app/(app)/customers`),
not nested under a `sales/` segment.** The platform's tech-stack draft in `FRONTEND_ARCHITECTURE.md`
sketches a provisional `sales/customers/[[...segment]]` route ahead of the Sales module's own screen
docs being written; this document supersedes that sketch for Customers specifically, the same way
Banking earned its own top-level module ahead of the rest of Purchasing/Inventory — a customer's
credit standing and AR exposure are consulted constantly from Accounting, Banking (receipt
allocation), and the not-yet-documented Sales screens alike, and a module boundary that buried it two
segments deep under Sales would work against that cross-module centrality. The **Transactions** tab
on the Profile route (`# Layout & Regions`) composes read views from Sales' own endpoints
(`/api/v1/sales/invoices`, `/quotations`, `/orders`, `/receipts`, `/credit-notes`, each filtered by
`customer_id`) that will grow their own dedicated screens in a later documentation batch; this
document does not duplicate that ownership, it only names the filtered composition it relies on today.

Two audiences share this one shell, per the platform's "two audiences, one shell" rule: a Sales
Employee doing high-volume, keyboard-friendly account qualification and day-to-day contact/address
maintenance (comfortable density, the Create/Edit form, light-touch Profile browsing), and a Finance
Manager or CFO making credit and risk decisions (the Credit & Risk tab, the Approval Center queue,
Aging and Statement drill-downs). Both are built from the same components — `DataTable`, `StatusPill`,
`AmountCell`, `ApprovalCard`, `ConfidenceBadge`, `AgingBar` — composed differently; there is no
separate "collections mode" build.

# Route & Access

## Route tree

```text
app/(app)/customers/
├── page.tsx                     # List — Server Component, first-paint fetch
├── loading.tsx                  # List skeleton (streamed while the Server Component fetches)
├── new/
│   └── page.tsx                 # CustomerForm — create mode
└── [customerId]/
    ├── page.tsx                 # Profile — view mode, OR CustomerForm in edit mode (see below)
    ├── loading.tsx               # Profile skeleton
    └── not-found.tsx             # Cross-company / genuinely-missing customer id
```

`[customerId]` follows the platform's dynamic-segment convention — camelCase, named for the entity,
never the generic `[id]` (`FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — and its API-resource
mirror is `/api/v1/accounting/customers/{id}`.

**One route, two rendered modes, chosen by server-known editability — not four routes.** There is
deliberately no separate `/[customerId]/edit` route, mirroring the exact pattern
`docs/frontend/JOURNAL_ENTRIES.md → Route & Access` establishes for its own detail route. Unlike a
journal entry, a customer record has no single "locked" lifecycle state that forbids editing outright
— routine field edits (contacts, addresses, tags, notes, non-threshold rating changes) remain
available in every lifecycle state except `blacklisted` (per `docs/accounting/CUSTOMERS.md → Workflow
→ Editing`) — so the mode switch here is driven by an explicit `?edit=1` query parameter opened from
the Profile's "Edit" action, not by status the way Journal Entries' draft/posted split works:

```tsx
// app/(app)/customers/[customerId]/page.tsx
import { getCustomer } from "@/lib/api/accounting";
import { CustomerProfile } from "@/components/accounting/customer-profile";
import { CustomerForm } from "@/components/accounting/customer-form";
import { notFound } from "next/navigation";

export default async function CustomerPage({
  params, searchParams,
}: {
  params: Promise<{ customerId: string }>;
  searchParams: Promise<{ edit?: string; tab?: string }>;
}) {
  const { customerId } = await params;
  const { edit, tab } = await searchParams;
  const customer = await getCustomer(customerId).catch(() => null);
  if (!customer) notFound();

  if (edit === "1") {
    if (customer.lifecycle_state === "blacklisted") notFound(); // never a bare 403 — see States
    return <CustomerForm mode="edit" customerId={customer.id} defaultValues={customer} />;
  }
  return <CustomerProfile initialCustomer={customer} initialTab={tab} />;
}
```

A blacklisted customer's Edit action is omitted from the Profile's overflow menu entirely (`#
Accessibility`'s "a sensitive action with no permission is omitted, not disabled" rule extends here to
"a routine action structurally inapplicable to this state is omitted too"), so the `?edit=1` guard
above is defense-in-depth against a stale bookmark or a hand-typed URL, never the only thing standing
between a blacklisted record and an edit attempt — the server's own `UpdateCustomerRequest` re-checks
`lifecycle_state <> 'blacklisted'` unconditionally.

## Access gate

The route sits inside the `(app)` route group behind `middleware.ts`'s session check and the resolved
`X-Company-Id` context, per `FRONTEND_ARCHITECTURE.md`. A user without `accounting.customer.read`
never sees "Customers" in the sidebar or the Command Palette's index (`NAVIGATION_SYSTEM.md →
Permission-Aware Nav`), and a direct hit on `/customers` or `/customers/[customerId]` renders the
shared `403` boundary rather than a `404`. A `customerId` that exists but belongs to a different
company renders the shared `not-found.tsx` instead — the API itself returns `404`, never `403`, for a
cross-tenant record (`docs/accounting/CUSTOMERS.md → Security → Tenant isolation`: "a `403` would leak
the fact that the ID space is shared; a `404` does not"), matching
`FRONTEND_ARCHITECTURE.md`'s identical "403 vs. 404" rule for Journal Entries.

A **Sales Employee's own-accounts scoping** is enforced server-side, never re-derived client-side: the
List's `GET /accounting/customers` call carries no client-computed "mine only" filter by default for a
Sales Employee — the API itself returns only the rows `sales_owner_id` maps to that user (per
`docs/accounting/CUSTOMERS.md → Permissions → Role Table`, "✅ (own accounts)"), and the frontend
renders whatever page the server returns without assuming anything about its completeness. A Sales
Manager or above sees the company's full customer book by the same call with no extra parameter,
because the scoping difference lives entirely in the Laravel Policy layer, not in a frontend-side
role branch.

## Permission surface on this screen

Every permission key below is defined once, authoritatively, in
`docs/accounting/CUSTOMERS.md → Permissions`; this table is this screen's map of key → concrete UI
effect, not a redefinition. Naming follows the platform's `<area>.<entity>.<action>` grammar exactly
as the backend spec states it — singular `customer`, not a pluralized shorthand.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `accounting.customer.read` | The route itself; every list row; the Profile view | Route renders the shared `403` boundary; nav item hidden |
| `accounting.customer.create` | "New Customer" button, `/new` route, the `N` shortcut | Button/route removed from the DOM, not disabled |
| `accounting.customer.update` | "Edit" action; inline contact/address/tag/note edits | Menu item omitted; Overview tab's edit affordances render read-only |
| `accounting.customer.delete` | "Delete" on a zero-history customer's overflow menu; "Restore" on a soft-deleted record | Menu item omitted |
| `accounting.customer.archive` | "Archive" / "Reactivate" toggle | Menu item omitted |
| `accounting.customer.blacklist` | "Blacklist" / "Reinstate" action on the Credit & Risk tab | Action omitted; blacklist status still visible read-only |
| `accounting.customer.merge` | "Merge with…" entry point (List bulk bar and Profile overflow menu); "Unmerge" on a merged-away record | Entry point omitted |
| `accounting.customer.approve` | Approve/Reject on the credit-limit, blacklist, and merge `ApprovalCard`s in the Approval Center | Card renders read-only (amount/reason/confidence, no action row) |
| `accounting.customer.credit_override` | "Approve over-limit exception" affordance surfaced from a blocked Sales document (deep-linked here) | Affordance omitted; document stays held |
| `accounting.customer.credit_limit.set` | Direct credit-limit edit for a change **below** the auto-approval threshold | Field renders read-only; any edit attempt routes through the request-for-approval flow instead |
| `accounting.customer.import` | "Import" entry point in the List's page header | Button omitted |
| `accounting.customer.export` | "Export" button in the List's Filter Bar and the Profile's "Export statement" action | Button omitted |
| `accounting.customer.export.unredacted` | The unredacted-identifiers toggle inside the Export dialog | Toggle omitted; export always redacts `national_id`/`tax_registration_number`/`commercial_registration_number` to `***last4` |
| `reports.customer.aging` | The Statement tab's Aging summary and the "Aging Report" link in the List's secondary actions | Aging section replaced by a plain "Requires reports.customer.aging" notice; Statement's transaction ledger still renders |
| `reports.customer.credit_exposure` | The List's "Credit Exposure" saved view and Credit & Risk tab's exposure breakdown | View/section omitted |
| `ai.customer.override` | Dismiss/accept affordances on Recommendations, Duplicate Detection warnings, and Merge Candidates | Suggestions render read-only (visible, no action) |

Default role grants (Owner, Admin, Sales Manager, Sales Employee, Finance Manager, Accountant,
Customer Service, Auditor, AI Agent) are exactly the table in
`docs/accounting/CUSTOMERS.md → Permissions → Role Table` — this document does not restate role-by-role
grants to avoid the two tables drifting out of sync; `usePermission()` is the only place either query
resolves against. Auditor holds `accounting.customer.read`, `accounting.customer.export`, and both
`reports.*` keys only — this screen's UI never renders a create/edit/blacklist/merge/approve control
for that role at all.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `C` navigates to this screen
from anywhere in the app; `N`, scoped to this route, opens `/new` (only reachable when
`accounting.customer.create` is held — the shortcut and the button share one permission check).
`/` inside the List focuses the search box; `Cmd/Ctrl+K` opens the Command Palette, which indexes
individual customers by `customer_code` and `legal_name` for direct navigation, respecting the same
own-accounts RBAC scoping as the List itself.

# Layout & Regions

All three sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing page templates verbatim — no new template
shape is introduced for Customers, per that document's "a screen never invents a sixth layout shape"
rule.

## List — List Page Template

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Customers                                                [+ New Customer]  │
│ 2,314 customers                                  [Import ▾]  [Export ▾]    │
├────────────────────────────────────────────────────────────────────────────┤
│ [Search…] [Segment ▾] [Status ▾] [Type ▾] [Credit standing ▾] [▤] [⚙]     │
├────────────────────────────────────────────────────────────────────────────┤
│ ☐│Code        │Name                  │Type      │Status     │Balance  │Risk│⋯│
│ ☐│CUST-004821 │Al Salam Trading Co.  │Business  │● Customer │1,650.000│ 62 │⋯│
│ ☐│CUST-005190 │Gulf Fresh Foods Co.  │Business  │● Customer │8,420.000│●81 │⋯│
│ ☐│CUST-000317 │Fahad Boutik          │Individual│● Inactive │    0.000│  – │⋯│
│ ☐│CUST-002204 │Ministry of Public Wks│Government│● Customer │   0.000 │ 11 │⋯│
│ …│ …          │ …                    │ …        │ …         │  …      │ … │⋯│
├────────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 2,314                                    [‹ Prev] [Next ›] │
└────────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, live customer count (`meta.pagination.total`), primary action ("New
  Customer," gated `accounting.customer.create`), secondary actions menu (Import →
  `accounting.customer.import`, Export → `accounting.customer.export`, "Open Aging Report" →
  `reports.customer.aging`, deep-linking to the company-wide Aging Report rather than duplicating it
  on this screen).
- **Filter Bar** — search (`q`, full-text over `legal_name`/`display_name`/`name_ar`/`name_en`/
  `customer_code`, backed by Smart Search — see `# AI Integration`), and four named filters:
  **Segment** (company-defined `segment` values plus an "All segments" default), **Status**
  (multi-select over `lifecycle_state`: Customer, Inactive, Archived, Blacklisted — Lead is excluded,
  since leads live in the Sales module's own screen, not here), **Type** (`customer_type`: Individual,
  Business, Government, Non-Profit, plus an "International" cross-cutting toggle over
  `is_international`), and **Credit standing** (a derived filter over `customer_balances`: "Within
  limit," "Approaching limit" (≥80% of `credit_limit`), "Over limit," "Credit hold," "Blacklisted" —
  the exact same bucket vocabulary the Credit & Risk tab's status chip uses, so a filter chosen here
  and a badge seen on a row always agree). A density toggle and column-visibility menu sit at the
  Filter Bar's end edge, matching `LAYOUT_SYSTEM.md → Density Modes`.
- **Bulk Action Bar** replaces the Filter Bar the moment ≥1 row is selected: "3 selected · Reassign
  owner · Add tags · Archive · Merge · Export · Clear." Merge is only enabled when **exactly two** rows
  are selected (`docs/accounting/CUSTOMERS.md → Workflow → Merge` is inherently a two-party operation);
  selecting a third row while Merge is the intended action disables it with an inline hint rather than
  silently merging the first two. Every bulk action re-checks its own permission and its own per-row
  eligibility server-side — a bulk Archive is `N` individual `POST .../archive` calls reported back
  per-row, never a single all-or-nothing endpoint, exactly mirroring
  `docs/frontend/JOURNAL_ENTRIES.md → Layout & Regions`'s identical bulk-action precedent.
- **Data Region** — `DataTable` (`resource="accounting/customers"`, `paginationMode="page"`). The
  **Balance** column renders through `AmountCell` (`mode="plain"`, since a customer balance is
  inherently one-sided exposure, never a debit/credit pair) and the **Risk** column renders a compact
  `RiskScoreBadge` (`# Components Used`) — a bare score for a healthy customer, and the same score with
  a small leading warning dot the moment it crosses the company's configured "elevated" threshold
  (default 70), never a full-width colored row (per `DESIGN_LANGUAGE.md`'s "a screen should read
  correctly with the accent/status color temporarily removed" principle — the row itself stays
  neutral; only the badge carries the signal).
- **Pagination Footer** — page controls; "Showing 1–25 of 2,314."

## Create / Edit — Form Page Template

```
┌──────────────────────────────────────────────────────┬──────────────────┐
│ New Customer                [Cancel] [Save Customer] │ Duplicate check  │
├────────────────────────────────────────────────────────┤  No similar     │
│  Identity                                              │  customer found │
│  Type [Business ▾]  Legal name [___________________]  │  ✓ Clear to save│
│  Name (AR) [___________]  CR Number [_______________]  ├──────────────────┤
│  Segment [SMB ▾]  Sales owner [Fahad Al-Mutairi ▾]     │  Financial terms │
├────────────────────────────────────────────────────────┤  preview:        │
│  Contacts                                    [+ Add]   │  Net 30, KWD,    │
│  ┌───────────────┬───────────┬──────────┬───────────┐  │  price list:     │
│  │ Full name     │ Job title │ Email    │ Primary   │  │  Standard Retail │
│  │ Fahad Al-Mut. │ Fin. Mgr. │ fahad@…  │    ●      │  │                  │
│  └───────────────┴───────────┴──────────┴───────────┘  │                  │
├────────────────────────────────────────────────────────┤                  │
│  Addresses                                    [+ Add]  │                  │
│  Billing/Shipping · Hawalli, Block 3, Bldg 12           │                  │
├────────────────────────────────────────────────────────┤                  │
│  Financial Terms                                       │                  │
│  Currency [KWD▾] Payment terms [Net 30▾] Credit limit [5,000.000]         │
│  Price list [Standard Retail ▾]  Tags [referred-by-partner-x ×] [+]       │
├──────────────────────────────────────────────────────┴──────────────────┤
│ ── sticky footer, appears once header scrolls out of view ──────────────│
│                    [Cancel]              [Save Customer]                 │
└────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — "New Customer" / "Edit CUST-004821"; Cancel (secondary, confirms discard if
  dirty); "Save Customer" (primary, `accounting.customer.create`/`.update`).
- **Form Body** — four `Card` sections: **Identity** (`customer_type`, `legal_name`, `name_ar`/
  `name_en`, the type-conditional identifier field — `national_id`, `commercial_registration_number`,
  `government_entity_code`, or `nonprofit_registration_number`, whichever `customer_type` requires,
  per `docs/accounting/CUSTOMERS.md → Customer Types`, `segment`, `sales_owner_id`), **Contacts**
  (`useFieldArray` grid, at least one row required, exactly one `is_primary`), **Addresses**
  (`useFieldArray` grid, at least one row required, at most one `is_default_billing` and one
  `is_default_shipping`), and **Financial Terms** (`preferred_currency`, `payment_terms_id`,
  `credit_limit` — disabled and routed to the approval flow above the auto-approval threshold, see
  `# Interactions & Flows` — `price_list_id`, `tags`).
- **Side Rail** (3–4/12, `xl:`+; collapses to an inline panel above the sticky footer below `xl:`,
  identical reflow to `JournalEntryForm`'s Live Preview Rail) — the **Duplicate check** card
  (`# AI Integration`, Duplicate Detection) and a read-only **Financial terms preview** summarizing
  what a new invoice for this customer will default to, computed client-side purely for legibility
  from already-selected form values (currency/terms/price-list labels), never a value the form submits
  independently of the fields themselves.
- **Sticky Footer Action Bar** — duplicates Cancel/Save once the header scrolls out of view, with the
  header's own buttons `aria-hidden` while it is visible, per `LAYOUT_SYSTEM.md`'s single-live-focus-
  target rule for this exact pattern.

## Profile — Detail Page Template

```
┌────────────────────────────────────────────────────────┬────────────────────┐
│ ‹ Customers   Al Salam Trading Co. W.L.L.   ● Customer  │ Balance (net)      │
│ CUST-004821 · Business · Owner: Fahad Al-Mutairi        │ KWD 1,650.000      │
│                       [New Invoice ▾]  [Edit]      [⋯]  │ Credit limit       │
├─ Overview │ Contacts & Addresses │ Credit & Risk ───────┤ KWD 5,000.000      │
│  Statement │ Transactions │ Documents │ History ────────┤ Risk score  62 ⚠  │
│                                                          │ [View reasoning]   │
│                        (active tab content)             │ Next payment       │
│                                                          │ (predicted)        │
│                                                          │ Aug 14 · 78% conf. │
└────────────────────────────────────────────────────────┴────────────────────┘
```

- **Page Header** — back-to-list link, `legal_name` (with `display_name` in a muted caption
  underneath when it differs), `StatusPill domain="customer"`, a secondary line (`customer_code` ·
  `customer_type` · Sales owner), the primary action ("New Invoice ▾," a `DropdownMenu` deep-linking
  into the not-yet-documented Sales screens for Quotation/Order/Invoice/Receipt, each pre-filled with
  this `customer_id`, gated by the corresponding Sales permission rather than a Customers one — this
  screen only supplies the entry point), "Edit" (gated `accounting.customer.update`, omitted entirely
  while `lifecycle_state = 'blacklisted'`), and an overflow menu: Request credit-limit change,
  Blacklist/Reinstate, Merge with…, Archive/Reactivate, Delete (zero-history only), Export statement.
- **Tab/Segment Nav** — `Tabs`: **Overview** (default), **Contacts & Addresses**, **Credit & Risk**,
  **Statement**, **Transactions**, **Documents**, **History** — seven sub-views, more than
  `docs/frontend/JOURNAL_ENTRIES.md`'s three, because a customer master genuinely carries seven
  independently useful facets (`docs/accounting/CUSTOMERS.md → Customer Profile` alone documents
  Identity, Contacts, Addresses, Tax, Payment Terms, Credit Limit, Documents, Notes, Tags, Custom
  Fields as distinct sub-topics); grouping them into seven tabs rather than one long scroll keeps each
  visit fast and printable/exportable independently (the Statement tab in particular is the one a user
  most often needs to export standalone).
- **Main Column (8/12)** — the active tab's content, detailed tab-by-tab below.
- **Summary Rail (4/12)** — persists across every tab (unlike Journal Entries' tab-scoped Summary
  Rail, because a customer's balance/credit/risk figures are relevant context regardless of which
  facet the user is currently looking at): net balance (`AmountCell`, `emphasis="strong"`), credit
  limit, the `RiskScoreBadge` with a "View reasoning" trigger opening the AI reasoning `Sheet` (`# AI
  Integration`), and the Payment Prediction figure (predicted next payment date + its own
  `ConfidenceBadge`) — both risk score and payment prediction render as an "Insufficient history" state
  rather than a number below their respective confidence floors (`# AI Integration`).

### Overview tab

Identity fields (read-only outside Edit mode), the primary contact and primary address rendered
inline as compact cards (not the full CRUD list — that is Contacts & Addresses), `tags` as removable
chips (`accounting.customer.update` required to remove one inline without opening Edit), `custom_fields`
rendered per their `custom_field_definitions` type, the Behavior Analysis archetype chip
(`docs/accounting/CUSTOMERS.md → AI Responsibilities → Behavior Analysis` — `growing`/`stable`/
`at_risk_of_churn`/`declining_engagement`/`insufficient_history`), and an **AI Recommendations** panel
(`# AI Integration`) surfacing this specific customer's open suggestions ("raise credit limit,"
"flag for Collections escalation," "consider a retention outreach").

### Contacts & Addresses tab

Two `DataTable`-style lists (not virtualized — a customer realistically has single-digit-to-low-tens
of rows of each) over `customer_contacts` and `customer_addresses`, each row editable inline via a
`Sheet` form, with the "make primary" / "make default billing" / "make default shipping" actions
implemented as a single radio-style toggle per list (never a checkbox, since the backend enforces
exactly one via a partial unique index — the UI's radio affordance matches that invariant visually
instead of allowing a multi-select state the server would reject).

### Credit & Risk tab

The credit-management control surface: current `credit_limit` and `credit_rating`, the
`CreditExposureBreakdown` composed component (`# Components Used`) showing
`balance_open_invoices`/`balance_unapplied_credits`/`balance_credit_notes_available`/`balance_net`,
the full `RiskScoreBadge` with its driver list expanded, the Payment Prediction card, the Collections
case status when one is open (`customer_collection_cases` state machine, rendered as a small
`Stepper`), and the "Request credit-limit change" / "Blacklist" / "Reinstate" actions themselves (`#
Interactions & Flows`).

### Statement tab

An `AgingBar` summary (extended with a fifth `'current'` bucket — see `# Components Used`) at the top,
an "as of" `PeriodPicker` (`mode="relative"`, defaulting to "Today"), and a chronological running-
balance ledger below it: every posted invoice, receipt, and credit note for this customer, each row
showing the document type, number, date, amount, and running balance, deep-linking to the source
document once the Sales module's own screens exist. "Export statement" (gated
`accounting.customer.export`) produces the same view as a PDF, always rendered in the platform's fixed
light/print palette regardless of the exporting user's active theme (`DARK_MODE.md → Exported PDFs
always render light`).

### Transactions tab

The full commercial history — quotations, orders, invoices, credit notes, receipts — each rendered as
its own compact, collapsed-by-default section backed by the corresponding Sales-module endpoint
filtered by `customer_id` (`# Data & State`). Distinct from Statement in intent: Statement is the
money view (a running balance an accountant reconciles against); Transactions is the document view (a
Sales Employee checking "did we already quote this customer for X").

### Documents tab

`customer_documents` as a list — commercial registration, tax certificates, KYC, signed agreements —
each with `document_type`, `status` (`StatusPill domain="customer_document"`), `expiry_date` where
applicable, and a signed-URL-backed preview/download (`docs/accounting/CUSTOMERS.md → Security →
Document access via signed URLs only` — every preview request mints a fresh, short-lived R2 URL
server-side; the frontend never caches or re-uses one past its 15-minute window). Documents expiring
within 30/7/0 days show an inline `warning`/`danger`-toned badge matching the
`customer.document.expiring` notification tiers.

### History tab

The merged `customer_documents`/`customer_notes`/`audit_logs` timeline (`GET .../timeline`), rendered
as a single reverse-chronological feed with a type filter (Notes / Edits / Lifecycle / AI activity),
plus a "New note" composer at the top (append-only, per `docs/accounting/CUSTOMERS.md → Customer
Profile → Notes`'s "editing a note creates a new version rather than overwriting" rule — there is no
inline note-editing affordance in this UI, only "Add a follow-up note").

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, or is one of the screen-specific
composed components this document introduces for this exact surface. A component gap discovered while
building this screen is proposed as an addition to `COMPONENT_LIBRARY.md`, never hand-rolled locally —
two such additive extensions are called out explicitly below because they widen an existing shared
component's contract rather than introducing a parallel one.

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The List's server-paginated, filterable customer table (`resource="accounting/customers"`) |
| `StatusPill` | `components/shared/status-pill.tsx` | Lifecycle state (list + Profile header), document status (Documents tab) — extended with two new `domain` values, see below |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Balance, credit limit, exposure breakdown, statement rows |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | `preferred_currency` indicator on the Profile header and Financial Terms section |
| `PeriodPicker` | `components/accounting/period-picker.tsx` | Statement tab's "as of" date and date-range selection |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Payment Prediction figure; composed inside `RiskScoreBadge` |
| `AgingBar` | `components/accounting/aging-bar.tsx` | Statement tab's aging summary — extended with a `'current'` bucket, see below |
| `ApprovalCard` | `components/shared/approval-card.tsx` | Credit-limit, blacklist, and merge approval requests in the Approval Center — extended `kind` union, see below |
| `KpiTile` | `components/dashboard/kpi-tile.tsx` | The List page's optional summary strip (total exposure, over-limit count, average DSO) when the company enables it |
| `Tabs` | `components/ui/tabs.tsx` | Profile's seven-tab segmentation |
| `Sheet` | `components/ui/sheet.tsx` | Contact/address inline edit; AI reasoning drill-in; mobile row-detail drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | Blacklist/reinstate confirmation, Archive confirmation, Merge wizard, Export options, discard-draft confirmation |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Row actions in the List; the Profile header's overflow menu; "New Invoice ▾" |
| `Badge` | `components/ui/badge.tsx` | `customer_type` label, tags, segment chip — plain informational badges, distinct from `StatusPill`'s stateful use |
| `Stepper` | `components/shared/stepper.tsx` | Collection case progression on the Credit & Risk tab |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, mapped from the API envelope |

## Extending `StatusPill` with `domain="customer"` and `domain="customer_document"`

`StatusPill`'s lookup-table pattern (`COMPONENT_LIBRARY.md → StatusPill`) is additive by design — a
new domain is a new entry in `STATUS_TABLES`, never a fork of the component. This screen adds two:

```ts
// components/shared/status-pill.tsx (addition)
const CUSTOMER_LIFECYCLE_STATUS: Record<string, { label: string; tone: Tone }> = {
  customer:    { label: "Customer",    tone: "success" },  // active, transacting — the "good" steady state
  inactive:    { label: "Inactive",    tone: "neutral" },
  archived:    { label: "Archived",    tone: "neutral" },
  blacklisted: { label: "Blacklisted", tone: "danger" },
  // 'lead' is intentionally absent — leads are never rendered on this screen (see # Layout & Regions)
};

const CUSTOMER_DOCUMENT_STATUS: Record<string, { label: string; tone: Tone }> = {
  pending_review: { label: "Pending review", tone: "neutral" },
  verified:        { label: "Verified",       tone: "success" },
  expired:         { label: "Expired",        tone: "danger"  },
  rejected:        { label: "Rejected",       tone: "danger"  },
};

STATUS_TABLES.customer = CUSTOMER_LIFECYCLE_STATUS;
STATUS_TABLES.customer_document = CUSTOMER_DOCUMENT_STATUS;
```

`customer` maps 1:1 onto `customers.lifecycle_state` from `docs/accounting/CUSTOMERS.md → Customer
Lifecycle`. The tone choices are deliberate, not arbitrary: `customer` (the fully active, transacting
state) gets `success` — the same tone Journal Entries reserves for `posted`, its own "fully realized,
good" terminal state — while `inactive` and `archived` both get the same quiet `neutral` tone Journal
Entries gives `reversed`/`archived`, since neither is a bad outcome, just a dormant one; `blacklisted`
gets `danger`, matching `voided`/`rejected` elsewhere in the platform. This is why a **credit-standing**
signal (over limit, on hold) is deliberately **not** folded into this same domain — those are
transient, correctable financial states layered on top of an otherwise-`customer` lifecycle row, not a
lifecycle state themselves, and conflating the two would make a customer's `StatusPill` flicker between
`success` and `danger` on every invoice posted, which is exactly the "color noise" `DESIGN_LANGUAGE.md`
warns against. Credit standing is instead the narrower, purpose-built `RiskScoreBadge`/`CreditStatusChip`
pairing below.

## Extending `AgingBar` with a `'current'` bucket

`AgingBar`'s documented bucket union is `'0-30' | '31-60' | '61-90' | '90+'`
(`COMPONENT_LIBRARY.md → AgingBar`), matching the generic AR/AP aging filter grammar in
`docs/api/API_FILTERING_SORTING.md`. `docs/accounting/CUSTOMERS.md → Credit Management → Overdue
Rules`, however, defines Customer Management's own bucket table with **five** buckets — Current,
1–30, 31–60, 61–90, 90+ — because "not yet due" is itself a meaningful, distinctly-styled segment on a
single customer's Statement tab (it is the majority of a healthy customer's open balance, and hiding it
would make the bar look like 100% of the balance is already overdue). This document therefore extends
`AgingBar`'s `buckets` prop union with a `'current'` member and its own bucket style, additively:

```ts
// components/accounting/aging-bar.tsx (addition, consistent with docs/accounting/CUSTOMERS.md →
// Credit Management → Overdue Rules' five-bucket definition)
const BUCKET_STYLE: Record<string, string> = {
  current: "bg-ink-300",     // not yet due — the platform's original "0-30" tint moves down one bucket
  "0-30":  "bg-ink-500",
  "31-60": "bg-warning/50",
  "61-90": "bg-warning",
  "90+":   "bg-danger",
};
```

Every other AR/AP surface in the platform (Banking's own aging views, a future Vendors screen's
equivalent) keeps `AgingBar`'s original four-bucket default; only the Customers Statement tab passes
the five-bucket variant, driven entirely by what the customer-scoped aging endpoint actually returns
(`# Data & State`) — the component itself is agnostic to how many buckets it is given and simply lays
out whatever `buckets[]` array it receives.

## Extending `ApprovalCard`'s `kind` union

`docs/accounting/CUSTOMERS.md → Workflow → Approval` models credit-limit increases, blacklist/
reinstate, and merge as rows in the same shared `approval_requests` table every other sensitive action
in the platform uses. This screen therefore extends `ApprovalCard`'s `kind` discriminant
(`COMPONENT_LIBRARY.md → ApprovalCard`) with three new values rather than building a parallel approval
UI:

```ts
// components/shared/approval-card.tsx (addition)
const PERMISSION_BY_KIND: Record<ApprovalKind, string> = {
  journal_entry: "accounting.journal.approve",
  ai_recommendation: "ai.approve",
  bank_transfer: "bank.transfer.approve",
  payroll_release: "payroll.release.approve",
  tax_submission: "tax.submit.approve",
  customer_credit_limit: "accounting.customer.approve",
  customer_blacklist: "accounting.customer.approve",
  customer_merge: "accounting.customer.approve",
};
```

Each new `kind` renders the same card shell — title, amount (credit-limit requests only; blacklist and
merge omit `amount` entirely, which `ApprovalCard`'s prop table already documents as optional),
requester, timestamp, and the approve/reject/delegate row — with a `kind`-specific `title` string
(`"Increase credit limit — CUST-004821"`, `"Blacklist CUST-000559"`, `"Merge CUST-005190 into
CUST-004821"`) and a `kind`-specific `detailHref` pointing at the relevant customer's Credit & Risk
tab. No new visual variant is introduced; this is a pure data/routing extension.

## `RiskScoreBadge` (screen-specific)

A compact rendering of `customer_balances.ai_risk_score`, deliberately **not** a reuse of
`ConfidenceBadge` despite the visual similarity: `ai_risk_score` (0–100, higher = riskier) is a
business metric with a genuine polarity — a high score is bad news for the company, a low score is
good news — while `ConfidenceBadge`'s 0–1 scale answers a categorically different question ("how sure
is the AI in what it just told you"). Collapsing the two into one component would either mislabel a
high-risk customer as "high confidence" (confusing "the AI is sure" with "you should be worried") or
force `ConfidenceBadge` to grow a `polarity` prop that every other, genuinely-neutral use of it (a
duplicate-match score, a payment-prediction score) does not want. `RiskScoreBadge` instead composes
`ConfidenceBadge` internally for the one sub-fact that *is* a confidence — how sure the Risk Scoring
agent is in the score itself — and renders the score's own risk band with the platform's signed-metric
color rule (`DESIGN_LANGUAGE.md`'s exception for "a number that is genuinely signed and the sign
genuinely means better or worse," the same exception Net Income and budget variance rely on).

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `score` | `number \| null` | yes | `customer_balances.ai_risk_score`, 0–100. `null` renders the "Insufficient history" state. |
| `confidence` | `number \| null` | no | The agent's own confidence in the score (0–1); composed into an inline `ConfidenceBadge` when present. |
| `drivers` | `string[]` | no | The `reasoning.drivers` array, e.g. `["3 invoices in 61-90 bucket", "DSO increased 40% QoQ"]`; rendered in the "View reasoning" `Sheet`, never inline. |
| `size` | `'sm' \| 'default'` | no | `'sm'` for the List's Risk column; `'default'` for the Profile's Summary Rail. |
| `onViewReasoning` | `() => void` | no | Opens the reasoning `Sheet`; omitted (no trigger rendered) if `drivers` is empty. |

**Implementation**

```tsx
// components/accounting/risk-score-badge.tsx
import { Badge } from "@/components/ui/badge";
import { ConfidenceBadge } from "@/components/ai/confidence-badge";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

function riskBand(score: number): { label: string; tone: "success" | "warning" | "danger" } {
  if (score < 30) return { label: "Low risk", tone: "success" };
  if (score < 70) return { label: "Medium risk", tone: "warning" };
  return { label: "High risk", tone: "danger" };
}

interface RiskScoreBadgeProps {
  score: number | null;
  confidence?: number | null;
  drivers?: string[];
  size?: "sm" | "default";
  onViewReasoning?: () => void;
}

export function RiskScoreBadge({ score, confidence, drivers, size = "default", onViewReasoning }: RiskScoreBadgeProps) {
  if (score == null) {
    return <span className="text-caption text-ink-500">Insufficient history</span>;
  }
  const { label, tone } = riskBand(score);

  return (
    <div className="flex items-center gap-2">
      <Badge tone={tone} className={cn(size === "sm" && "px-2 py-0 text-[11px]")}>
        <span className="font-mono tabular-nums" dir="ltr">{score}</span>
        {size === "default" && <span>{label}</span>}
      </Badge>
      {confidence != null && <ConfidenceBadge confidence={confidence} size="sm" showLabel={false} />}
      {drivers?.length ? (
        <Button variant="link" size="sm" onClick={onViewReasoning}>View reasoning</Button>
      ) : null}
    </div>
  );
}
```

**Usage**

```tsx
<RiskScoreBadge
  score={customer.balance.ai_risk_score}
  confidence={customer.balance.ai_risk_score_confidence}
  drivers={customer.balance.ai_risk_score_reasoning?.drivers}
  size="sm"
  onViewReasoning={() => setReasoningSheetOpen(true)}
/>
```

Below the agent's own confidence floor of 0.5 (`docs/accounting/CUSTOMERS.md → AI Responsibilities →
Customer Risk Scoring → Confidence handling`), the API returns `ai_risk_score: null` rather than a
low-confidence number, and `RiskScoreBadge` renders its "Insufficient history" branch — the frontend
never invents its own confidence gate on top of the server's; it simply renders whatever the field
contains.

## `PaymentPredictionCard` (screen-specific)

Renders `customer_balances.ai_predicted_next_payment_date` and
`ai_predicted_payment_confidence` (`docs/accounting/CUSTOMERS.md → AI Responsibilities → Payment
Prediction`) as a small card in the Summary Rail and, expanded, on the Credit & Risk tab. Below the
0.4 confidence floor the API again returns nulls and the card renders "Insufficient history" rather
than a number — the exact same null-means-insufficient-history contract `RiskScoreBadge` follows, kept
uniform across both agents so a frontend engineer only has to learn the pattern once.

```tsx
// components/accounting/payment-prediction-card.tsx
import { ConfidenceBadge } from "@/components/ai/confidence-badge";
import { formatDate } from "@/lib/i18n/format";

interface PaymentPredictionCardProps {
  predictedDate: string | null;   // ISO date
  confidence: number | null;      // 0–1
  compact?: boolean;
}

export function PaymentPredictionCard({ predictedDate, confidence, compact }: PaymentPredictionCardProps) {
  if (!predictedDate || confidence == null) {
    return <p className="text-caption text-ink-500">Not enough payment history to predict a date.</p>;
  }
  return (
    <div className="space-y-1">
      <p className="text-caption text-ink-500">{compact ? "Next payment (predicted)" : "Predicted next payment"}</p>
      <p className="text-body font-medium text-ink-950" dir="ltr">{formatDate(predictedDate)}</p>
      <ConfidenceBadge confidence={confidence} size="sm" />
    </div>
  );
}
```

## `CreditExposureBreakdown` (screen-specific)

The Credit & Risk tab's exposure detail, rendering the four `customer_balances` figures
`docs/accounting/CUSTOMERS.md → Credit Management → Credit Limit` names explicitly
(`balance_open_invoices`, `balance_unapplied_credits` — subtracted — `balance_credit_notes_available`
— subtracted — `balance_net`) as a small labeled stack with `AmountCell`, plus a horizontal
`credit_limit`-scaled meter bar (a thin, single-color bar, deliberately not a second `AgingBar` — the
meter shows one ratio, exposure ÷ limit, not a multi-bucket breakdown) that turns `warning`-toned past
80% of the limit and `danger`-toned past 100%, gated behind `reports.customer.credit_exposure`
(`# Route & Access`) since raw exposure figures are the one Credit & Risk sub-section this document
marks sensitive enough to warrant its own report permission, distinct from plain
`accounting.customer.read`.

## `CustomerForm` field arrays

`CustomerForm` follows the identical `useFieldArray` + Zod pattern `JournalEntryForm`/`JournalLineGrid`
establish (`COMPONENT_LIBRARY.md → JournalEntryForm`), applied to `contacts[]` and `addresses[]`
instead of `lines[]`:

```ts
// lib/schemas/customer.ts
import { z } from "zod";

const contactSchema = z.object({
  full_name: z.string().min(1, "fullNameRequired"),
  job_title: z.string().optional(),
  email: z.string().email().optional(),
  mobile: z.string().optional(),
  is_primary: z.boolean().default(false),
  is_billing_contact: z.boolean().default(false),
});

const addressSchema = z.object({
  address_type: z.enum(["billing", "shipping", "both", "registered_office", "other"]),
  line1: z.string().min(1, "line1Required"),
  line2: z.string().optional(),
  city: z.string().min(1, "cityRequired"),
  country_code: z.string().length(2),
  is_default_billing: z.boolean().default(false),
  is_default_shipping: z.boolean().default(false),
});

export const customerSchema = z
  .object({
    customer_type: z.enum(["individual", "business", "government", "non_profit"]),
    legal_name: z.string().min(1, "legalNameRequired"),
    name_ar: z.string().optional(),
    name_en: z.string().optional(),
    national_id: z.string().optional(),
    commercial_registration_number: z.string().optional(),
    government_entity_code: z.string().optional(),
    nonprofit_registration_number: z.string().optional(),
    segment: z.string().optional(),
    sales_owner_id: z.number().optional(),
    preferred_currency: z.string().length(3).default("KWD"),
    preferred_language: z.enum(["en", "ar"]).default("en"),
    payment_terms_id: z.number().nullable().optional(),
    credit_limit: z.number().min(0).default(0),
    price_list_id: z.number().nullable().optional(),
    tags: z.array(z.string()).default([]),
    contacts: z.array(contactSchema).min(1, "atLeastOneContact"),
    addresses: z.array(addressSchema).min(1, "atLeastOneAddress"),
  })
  // Mirrors docs/accounting/CUSTOMERS.md's chk_customers_identifier_by_type constraint —
  // client-side for fast feedback only; StoreCustomerRequest re-validates authoritatively.
  .superRefine((data, ctx) => {
    const requiredByType: Record<string, keyof typeof data> = {
      individual: "national_id",
      business: "commercial_registration_number",
      government: "government_entity_code",
      non_profit: "nonprofit_registration_number",
    };
    const field = requiredByType[data.customer_type];
    if (!data[field]) {
      ctx.addIssue({ code: "custom", message: "requiredIdentifierMissing", path: [field] });
    }
    if (data.contacts.filter((c) => c.is_primary).length !== 1) {
      ctx.addIssue({ code: "custom", message: "exactlyOnePrimaryContact", path: ["contacts"] });
    }
    if (data.addresses.filter((a) => a.is_default_billing).length > 1) {
      ctx.addIssue({ code: "custom", message: "onlyOneDefaultBillingAddress", path: ["addresses"] });
    }
  });

export type CustomerFormValues = z.infer<typeof customerSchema>;
```

The type-conditional-identifier `superRefine` branch is a direct, field-for-field mirror of
`docs/accounting/CUSTOMERS.md`'s `chk_customers_identifier_by_type` database `CHECK` constraint and its
`StoreCustomerRequest` validation — this is the fast, friendly, purely cosmetic rejection
`FRONTEND_ARCHITECTURE.md`'s Principle 1 describes; `POST /accounting/customers` re-derives and
re-enforces the identical rule server-side regardless of what the client already checked.

## `CustomerMergeDialog` (screen-specific)

The field-by-field survivor picker for `docs/accounting/CUSTOMERS.md → Workflow → Merge`, opened from
either the List's two-row Bulk Action Bar or a Profile's overflow menu. It is a `Dialog` (not a route)
because a merge is a short, focused decision, not a page-length task.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `survivorId` | `number` | yes | The record that continues to exist after merge. |
| `mergedId` | `number` | yes | The record that will be soft-deleted with `merged_into_customer_id` set. |
| `conflictingFields` | `{ field: string; survivorValue: string; mergedValue: string; aiSuggestion?: "survivor" \| "merged"; aiConfidence?: number }[]` | yes | Only fields that actually differ between the two records — a field both records agree on is never shown, keeping the dialog short even for two long-lived accounts. |
| `onSubmit` | `(fieldChoices: Record<string, "survivor" \| "merged">) => Promise<void>` | yes | Posts to `POST /customers/{survivorId}/merge`; the result is an `approval_request`, never an immediate merge (`# Interactions & Flows`). |

Each conflicting field renders as a two-option `RadioGroup` (survivor's value vs. merged-away record's
value), pre-selected to the AI Duplicate Detection agent's own suggestion when one exists
(`docs/accounting/CUSTOMERS.md → Workflow → Merge`, step 2: "pre-filled with AI-suggested field
choices and each choice's confidence"), with a small `ConfidenceBadge` next to the pre-selected option
— the pre-selection is always overridable before submit, never locked in by the AI's suggestion.

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/CUSTOMERS.md → API → Endpoint Table`'s list, annotated with
which hook and query key this screen wires it to. All are versioned under
`/api/v1/accounting/customers`, carry `X-Company-Id`, and return the standard envelope. Three rows
(marked *) are customer-scoped convenience endpoints this document introduces, thinly wrapping
services the backend spec already defines company-wide (the Aging Report engine, the Statement
concept implicit in the sub-ledger) rather than inventing new business logic — see the notes beneath
the table.

| Method & Path | Permission | Hook | Query key |
|---|---|---|---|
| `GET /customers` | `.read` | `useCustomers(filters)` | `customerKeys.list(filters)` |
| `GET /customers/{id}` | `.read` | `useCustomer(id)` | `customerKeys.detail(id)` |
| `POST /customers` | `.create` | `useCreateCustomer()` | invalidates `customerKeys.lists()` |
| `PUT` / `PATCH /customers/{id}` | `.update` | `useUpdateCustomer(id)` | invalidates `detail(id)` + `lists()` |
| `DELETE /customers/{id}` | `.delete` | `useDeleteCustomer()` | removes from `lists()` cache; invalidates on settle |
| `POST /customers/{id}/restore` | `.delete` | `useRestoreCustomer()` | invalidates `detail(id)` + `lists()` |
| `POST /customers/{id}/archive` | `.archive` | `useArchiveCustomer()` | optimistic status patch |
| `POST /customers/{id}/reactivate` | `.archive` | `useReactivateCustomer()` | optimistic status patch |
| `POST /customers/{id}/blacklist` | `.blacklist` | `useBlacklistCustomer()` | pessimistic — routes through approval, see `## Mutations` |
| `POST /customers/{id}/reinstate` | `.blacklist` | `useReinstateCustomer()` | pessimistic |
| `POST /customers/{survivorId}/merge` | `.merge` | `useMergeCustomer()` | pessimistic; invalidates both `detail(survivorId)` and `detail(mergedId)` on approval-decided realtime event |
| `POST /customers/{id}/unmerge` | `.merge` | `useUnmergeCustomer()` | invalidates both records' `detail()` |
| `POST /customers/import` | `.import` | `useImportCustomers()` | invalidates `lists()` on job completion |
| `GET /customers/export` | `.export` | `useExportCustomers()` | not cached — async `report_runs` job, see `# Performance` |
| `GET /customers/search` | `.read` | `useCustomerSearch(q)` | `customerKeys.search(q)`, powers the List's search box and the Command Palette |
| `POST /customers/bulk` | `.update` | `useBulkCustomerAction()` | invalidates `lists()` on settle |
| `GET /customers/{id}/balance` | `.read` | `useCustomerBalance(id)` | `customerKeys.balance(id)`, `staleTime: 0` — see cache tuning below |
| `GET /customers/{id}/timeline` | `.read` | `useCustomerTimeline(id)` | `customerKeys.timeline(id)` |
| `POST /customers/{id}/credit-overrides` | `.credit_override` | `useCreateCreditOverride()` | invalidates `balance(id)` |
| `GET /customers/approval-requests` | `.approve` | `useCustomerApprovalRequests(filters)` | `customerKeys.approvalRequests(filters)` — also surfaces in the platform-wide Approval Center |
| `POST /customers/approval-requests/{id}/decide` | `.approve` | `useDecideCustomerApproval()` | optimistic status patch on the approval row; invalidates the affected customer's `detail()`/`balance()` on settle |
| `GET`/`POST /customers/{id}/contacts` | `.read` / `.update` | `useCustomerContacts(id)` / `useAddCustomerContact(id)` | `customerKeys.contacts(id)` |
| `GET`/`POST /customers/{id}/addresses` | `.read` / `.update` | `useCustomerAddresses(id)` / `useAddCustomerAddress(id)` | `customerKeys.addresses(id)` |
| `GET`/`POST /customers/{id}/documents` | `.read` / `.update` | `useCustomerDocuments(id)` / `useAddCustomerDocument(id)` | `customerKeys.documents(id)` |
| `POST /customers/{id}/notes`* | `.update` | `useAddCustomerNote(id)` | invalidates `timeline(id)` |
| `GET /customers/{id}/aging`* | `reports.customer.aging` | `useCustomerAging(id, asOfDate)` | `customerKeys.aging(id, asOfDate)` |
| `GET /customers/{id}/statement`* | `.read` | `useCustomerStatement(id, dateRange)` | `customerKeys.statement(id, dateRange)` |

\* **`POST /customers/{id}/notes`** is the natural create-path for the `customer_notes` table
`docs/accounting/CUSTOMERS.md → Database Design` defines but whose endpoint table (written before this
document) does not enumerate separately — it follows the identical shape as the contacts/addresses/
documents `POST` rows immediately above it. **`GET /customers/{id}/aging`** is a customer-scoped
wrapper around the same `AgingReportService` the company-wide Aging Report
(`docs/accounting/CUSTOMERS.md → Reports → Aging Report`) already uses, called with a
`customer_id` filter and the same `as_of_date` parameter — it introduces no new bucket logic, only a
narrower scope. **`GET /customers/{id}/statement`** composes `invoices`, `receipts`, and
`credit_notes` filtered by this `customer_id` and ordered by document date into a single running-
balance feed; each row's `source_type`/`source_id` lets the Statement tab deep-link to the originating
Sales-module document. None of the three introduces a permission key, a business rule, or a data shape
beyond what `docs/accounting/CUSTOMERS.md` and the platform's existing Sales table definitions already
establish.

## Query key factory

```ts
// lib/query/keys.ts (excerpt)
export const customerKeys = {
  all: ["accounting", "customers"] as const,
  lists: () => [...customerKeys.all, "list"] as const,
  list: (filters: CustomerFilters) => [...customerKeys.lists(), filters] as const,
  search: (q: string) => [...customerKeys.all, "search", q] as const,
  details: () => [...customerKeys.all, "detail"] as const,
  detail: (id: number | string) => [...customerKeys.details(), id] as const,
  balance: (id: number | string) => [...customerKeys.detail(id), "balance"] as const,
  timeline: (id: number | string) => [...customerKeys.detail(id), "timeline"] as const,
  contacts: (id: number | string) => [...customerKeys.detail(id), "contacts"] as const,
  addresses: (id: number | string) => [...customerKeys.detail(id), "addresses"] as const,
  documents: (id: number | string) => [...customerKeys.detail(id), "documents"] as const,
  aging: (id: number | string, asOfDate: string) => [...customerKeys.detail(id), "aging", asOfDate] as const,
  statement: (id: number | string, range: DateRange) => [...customerKeys.detail(id), "statement", range] as const,
  approvalRequests: (filters: ApprovalFilters) => [...customerKeys.all, "approval-requests", filters] as const,
};
```

`CustomerFilters` mirrors the List's four named filters plus `q`, `sort`: `segment`, `lifecycle_state`
(array), `customer_type` (array), `is_international`, `credit_status` (`within_limit` |
`approaching_limit` | `over_limit` | `credit_hold` | `blacklisted` — a server-derived filter enum, not
a client-computed one, so a filter chosen in the UI and the badge rendered on a matching row can never
disagree), `sales_owner_id`, `tags`, `q`, `sort`.

## Reference data

`sales_owner_id` (a user picker), `payment_terms_id`, and `price_list_id` selects in `CustomerForm`
depend on read-mostly reference collections, fetched with the platform's "rarely-changing reference/
master data" `staleTime` tier (5 minutes, per `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`):

| Reference | Endpoint | Consumed by |
|---|---|---|
| Users (for Sales owner) | `GET /api/v1/foundation/users?filter[role]=sales` | `CustomerForm`'s Sales owner select |
| Payment terms | `GET /api/v1/accounting/payment-terms` | `CustomerForm`'s Financial Terms section |
| Price lists | `GET /api/v1/sales/price-lists` | `CustomerForm`'s Financial Terms section |
| Custom field definitions | `GET /api/v1/settings/custom-field-definitions?filter[entity]=customer` | Overview tab's Custom Fields renderer |

The List itself follows the "transactional lists" tier: `staleTime: 30_000` — customer records change
often enough (new orders, credit-limit approvals, lifecycle transitions) that a stale list is a real
annoyance, but not worth polling. `useCustomerBalance` follows the "live/derived figures" tier
(`staleTime: 0`, always considered stale, kept fresh instead via the realtime patch below) because a
credit-exposure figure a Finance Manager is about to act on must never be shown meaningfully behind.

## Client state ownership

Consistent with `FRONTEND_ARCHITECTURE.md → State Management`:

| State | Owner |
|---|---|
| The customer list, one customer's detail/balance/timeline/contacts/addresses/documents | TanStack Query cache, keyed as above |
| The in-progress `CustomerForm` values (identity + `contacts[]` + `addresses[]` + financial terms) | React Hook Form's internal state, schema-validated by `customerSchema` |
| List density, column visibility, saved filter presets (e.g. a Finance Manager's "Over limit" saved view) | Zustand (`useDensityStore`, per-table-keyed) + `users.settings` sync |
| Selected rows for bulk action, including the two-row Merge selection | Local component state inside `DataTable`, cleared on navigation |
| Active Profile tab | URL search param (`?tab=credit-risk`), never local state — a Finance Manager sharing a deep link to a specific customer's Credit & Risk tab must land there directly |
| The `CustomerMergeDialog`'s field-choice selections mid-review | Local `useState`, discarded if the dialog closes without submitting |
| A mid-form draft's `Idempotency-Key`, keyed to the form's `formInstanceId` | `sessionStorage`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` |

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md`'s Principle 10, this screen splits cleanly along the same line Journal
Entries does: a reversible, non-financial action updates the cache immediately and rolls back on
failure; anything that changes credit exposure, blocks a customer from transacting, or consolidates
two records waits for the server's `2xx` (and, for the three approval-gated actions, only reflects the
**request** having been accepted, never the underlying change, until the approval itself is decided).

```ts
// hooks/accounting/use-customers.ts (excerpt)
export function useArchiveCustomer(id: number) {
  const qc = useQueryClient();
  return useMutation({
    // Archive/reactivate is visibility-only — it never changes credit_limit, balance, or
    // transacting ability beyond what the eligibility check already guaranteed server-side —
    // so it is the reversible, low-stakes half of Principle 10 and updates optimistically.
    mutationFn: (archived: boolean) =>
      api.post(`/accounting/customers/${id}/${archived ? "archive" : "reactivate"}`, {}),
    onMutate: async (archived) => {
      await qc.cancelQueries({ queryKey: customerKeys.detail(id) });
      const previous = qc.getQueryData<Customer>(customerKeys.detail(id));
      qc.setQueryData(customerKeys.detail(id), (old?: Customer) =>
        old ? { ...old, lifecycle_state: archived ? "archived" : "customer" } : old);
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(customerKeys.detail(id), ctx?.previous),
    onSettled: () => qc.invalidateQueries({ queryKey: customerKeys.lists() }),
  });
}

export function useRequestCreditLimitChange(id: number) {
  const idempotencyKey = useIdempotencyKey("customer-credit-limit-request");
  return useMutation({
    // No onMutate — a pending credit-limit change must never appear to have taken effect
    // before Finance Manager approval; the UI shows "Requested" (a distinct, non-financial
    // status on the request row itself), never a provisional credit_limit value.
    mutationFn: (newLimit: number) =>
      api.post(`/accounting/customers/${id}/credit-limit-requests`, { requested_limit: newLimit }, idempotencyKey),
    onSuccess: (request) => {
      qc.setQueryData(customerKeys.approvalRequests({ customer_id: id }), (old: ApprovalRequest[] = []) =>
        [request, ...old]);
    },
  });
}

export function useBlacklistCustomer(id: number) {
  const idempotencyKey = useIdempotencyKey("customer-blacklist");
  return useMutation({
    // Pessimistic: blacklisting blocks every future commercial document for this customer —
    // exactly the class of mutation Principle 10 reserves for "waits for the server's 2xx" and
    // "always renders a confirming dialog before it fires" (see # Interactions & Flows).
    mutationFn: (payload: { reason_code: string; reason_notes: string }) =>
      api.post(`/accounting/customers/${id}/blacklist`, payload, idempotencyKey),
    onSuccess: (customer) => qc.setQueryData(customerKeys.detail(id), customer),
  });
}
```

`useMergeCustomer`, `useReinstateCustomer`, and `useDeleteCustomer` follow `useBlacklistCustomer`'s
exact shape — no `onMutate`, a client-generated `Idempotency-Key` per logical submission attempt, and a
confirming `Dialog`/`AlertDialog` (or, for Merge, the full `CustomerMergeDialog`) in front of every one.

## Realtime

The List and Profile both subscribe to the company's private accounting channel via Echo, re-firing
the exact domain events `docs/accounting/CUSTOMERS.md` already names (`customer.created`,
`customer.blacklist.applied`, `customer.credit_limit.approval_decided`, `customer.merged`, and the AI
events below) — this hook does not invent a parallel event vocabulary:

```ts
// hooks/accounting/use-customer-realtime.ts
"use client";
import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { echo } from "@/lib/realtime/echo";
import { customerKeys } from "@/lib/query/keys";

export function useCustomerRealtime(companyId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`company.${companyId}.accounting`);

    // Balance-affecting events patch the specific customer's cached balance surgically —
    // docs/accounting/CUSTOMERS.md's own rule that customer_balances recalculates
    // synchronously means the frontend can trust an immediate, precise payload here.
    channel.listen(".customer.balance_changed", (e: CustomerBalanceChangedEvent) => {
      queryClient.setQueryData(customerKeys.balance(e.customer_id), e.balance);
    });

    channel.listen(".customer.credit_limit.approval_decided", (e: CreditDecisionEvent) => {
      queryClient.invalidateQueries({ queryKey: customerKeys.detail(e.customer_id) });
      queryClient.invalidateQueries({ queryKey: customerKeys.approvalRequests({ customer_id: e.customer_id }) });
    });

    channel.listen(".customer.blacklist.applied", (e: { customer_id: number }) => {
      queryClient.invalidateQueries({ queryKey: customerKeys.detail(e.customer_id) });
      queryClient.invalidateQueries({ queryKey: customerKeys.lists(), refetchType: "inactive" });
    });

    channel.listen(".customer.merged", (e: { survivor_id: number; merged_id: number }) => {
      queryClient.invalidateQueries({ queryKey: customerKeys.detail(e.survivor_id) });
      queryClient.invalidateQueries({ queryKey: customerKeys.detail(e.merged_id) });
      queryClient.invalidateQueries({ queryKey: customerKeys.lists(), refetchType: "inactive" });
    });

    // AI-produced updates — risk score recompute, a new duplicate candidate, a new
    // recommendation — patch only the affected customer, never a company-wide invalidation,
    // since these fire far more often than a human-initiated mutation.
    for (const event of [".customer.risk_score_updated", ".customer.payment_prediction_updated",
                          ".customer.recommendation_available", ".customer.duplicate_candidate_found"]) {
      channel.listen(event, (e: { customer_id: number }) =>
        queryClient.invalidateQueries({ queryKey: customerKeys.balance(e.customer_id) }));
    }

    return () => { echo.leave(`company.${companyId}.accounting`); };
  }, [companyId, queryClient]);
}
```

The currently-open Profile record is patched surgically (its Summary Rail's balance/risk/prediction
figures reflect another user's or the AI layer's action within the same second, with the affected
figure briefly flashing an `accent-subtle` tint per `DESIGN_LANGUAGE.md`'s realtime row-update
pattern), while the List's cached pages are only marked `invalidateQueries(..., { refetchType:
"inactive" })` — refetched the next time they become the active query, never yanked out from under a
user mid-scroll.

## AI agents feeding this screen

Per `docs/accounting/CUSTOMERS.md → AI Responsibilities`, all eight agent behaviors surface here, each
through the ordinary read/create API — never a bespoke AI endpoint this screen has to special-case:

| Agent | What appears on this screen | Autonomy | Min. confidence to surface |
|---|---|---|---|
| Customer Risk Scoring | `RiskScoreBadge` on the List, Profile Summary Rail, and Credit & Risk tab | Suggest-only | 0.5 |
| Payment Prediction | `PaymentPredictionCard` on the Profile Summary Rail and Credit & Risk tab | Suggest-only | 0.4 |
| Fraud Detection | An Auditor/Finance-Manager-only banner on the Credit & Risk tab when a `fraud_flag` exists; never shown to the customer's own Sales Owner unless they also hold that role | Requires-approval | 0.6 (0.85 escalates further, see `# AI Integration`) |
| Duplicate Detection | The Create form's Duplicate check card; the List's Merge Candidates saved view; `CustomerMergeDialog`'s pre-selected field choices | Suggest-only (exact-identifier matches are a deterministic `409`, not an AI decision) | 0.5 |
| Smart Search | The List's search box and the Command Palette's customer index | Auto (read-only) | N/A |
| Recommendations | The Overview tab's AI Recommendations panel | Suggest-only | 0.5 |
| Behavior Analysis | The Overview tab's archetype chip | Suggest-only | N/A (6-month history gate) |
| Revenue Forecasting | A per-customer expected-revenue figure surfaced in the not-yet-documented Reports module, linked from Overview | Suggest-only | N/A (always shows a confidence interval) |

# Interactions & Flows

**Creating a customer.** "New Customer" (gated `accounting.customer.create`) opens `/new`.
`CustomerForm` mounts with `mode="create"` and one blank contact and one blank address already
present (`customerSchema`'s `min(1)` invariants, mirrored client-side). As the user fills the Identity
section, changing `customer_type` swaps which identifier field is required (`# Components Used`) and
clears any value already entered in a field that is no longer relevant, rather than silently
submitting a stale `national_id` alongside a now-`business` record. The Side Rail's Duplicate check
card starts in a neutral "Checking…" state, debounced 400ms off `legal_name`, and calls the same
`GET /customers/search`-backed fuzzy check the backend's own create-time Duplicate Detection sweep
uses (`# AI Integration`) — this is a client-side convenience preview only; the authoritative check
still runs synchronously inside `CustomerService::create()` when the form is actually submitted, and a
fuzzy match surfaced only at that point (network timing, a duplicate created by someone else in the
interim) still produces the identical non-blocking warning described below.

**Save.** Submitting calls `POST /accounting/customers`. A response carrying
`meta.warnings[].code === 'DUPLICATE_SUSPECTED'` (the identical envelope shape
`docs/frontend/JOURNAL_ENTRIES.md → Interactions & Flows` documents for its own duplicate check)
renders as a dismissible, non-blocking banner on the newly created customer's Profile — the record was
already created; "View possible duplicate" opens the candidate in a new tab, "Not a duplicate"
dismisses the banner and is retained in `audit_logs` as the human's override decision. An **exact**
identifier collision (same `commercial_registration_number`, `national_id`, or
`tax_registration_number` as an existing, non-archived customer) is not a warning at all — it is a
deterministic `409 Conflict` the form surfaces inline, naming the existing `customer_code` and
offering a "View existing customer" link instead of letting the create proceed
(`docs/accounting/CUSTOMERS.md → Business Rules #13`).

**Editing.** Opening `?edit=1` on any non-blacklisted customer resolves to `CustomerForm` in edit
mode. Routine fields (contacts, addresses, tags, notes, segment, non-threshold rating) save
immediately through `PATCH .../{id}` with no confirmation dialog. Editing `legal_name`, the default
billing address, or the tax registration number surfaces a one-time inline notice before submit — "This
will not change the 14 invoices already issued under the old name; new documents will use the updated
value" — matching `docs/accounting/CUSTOMERS.md → Workflow → Editing`'s non-retroactive snapshot rule,
so a user is never surprised later that a correction did not reach back into posted history.

**Requesting a credit-limit change.** From the Credit & Risk tab, "Request credit-limit change" opens
a small `Dialog` with the new limit and an optional reason. On submit, the response shape tells the UI
which of two paths it took, and the UI reflects exactly that rather than assuming: a request **below**
the company's auto-approval threshold and within the caller's `accounting.customer.credit_limit.set`
grant returns the customer with `credit_limit` already updated and a toast, "Credit limit updated to
KWD 8,000.000"; a request **above** the threshold (or from a caller who only holds `.credit_override`-
adjacent permissions, never direct `.set`) returns a `202`-equivalent `approval_request` row and a
toast, "Credit-limit change submitted for Finance Manager approval" — the Credit & Risk tab then shows
the existing `credit_limit` unchanged, plus a "Pending: KWD 8,000.000 (requested Jul 16)" annotation
until the request is decided (`docs/accounting/CUSTOMERS.md → Business Rules #15`).

**Deciding a credit-limit / blacklist / merge approval.** These render as `ApprovalCard`s (`kind`
extended per `# Components Used`) in both the platform-wide Approval Center and, contextually, at the
top of the relevant customer's Credit & Risk tab when the current viewer is an eligible approver.
Approve/Reject behave identically to Journal Entries' own approval flow — Reject requires a typed
reason, Approve on a credit-limit request applies the new limit atomically server-side and the client
simply reflects whatever `credit_limit` the response now contains.

**Blacklisting.** "Blacklist" opens a `Dialog` requiring `reason_code` (a `Select` over the fixed enum:
Fraud, Non-payment, Legal dispute, Sanctions match, Policy violation, Other) and `reason_notes`
(minimum 20 characters, enforced client-side and re-enforced server-side). Submitting is pessimistic
and, per `docs/accounting/CUSTOMERS.md`, immediate (blacklisting itself does not require a separate
approval step beyond the acting Finance-Manager-or-above's own permission — only *reinstatement* is
dual-approval-gated) — the confirming dialog itself is the safety gate here, restating in plain
language that every future quotation/order/invoice/subscription-renewal for this customer will be
blocked immediately. **Reinstating** a blacklisted customer instead always creates an `approval_request`
requiring a *second, different* Finance-Manager-or-above approver (four-eyes), and — per
`docs/accounting/CUSTOMERS.md → Customer Lifecycle → Blacklisted` — always resets the customer to a
zero credit limit and prepaid-only terms on reinstatement regardless of what the limit was before
blacklisting; the reinstatement confirmation dialog states this explicitly so a Finance Manager does
not expect the old limit to simply reappear.

**Merging.** Selecting exactly two rows in the List (or opening "Merge with…" from a Profile) opens a
customer-picker step (List entry point) or immediately opens `CustomerMergeDialog` (Profile entry
point, the other party already implied). After the field-by-field choices are made, submit calls
`POST /customers/{survivorId}/merge`; the response is always an `approval_request`, never an immediate
merge (`docs/accounting/CUSTOMERS.md → Workflow → Merge`, step 3) — the UI shows "Merge request
submitted for Admin approval" and both records remain independently visible and transactable until
the request is decided. Once approved, the realtime `.customer.merged` event (`# Data & State`)
invalidates both records; the merged-away customer's Profile thereafter shows a persistent banner,
"This customer was merged into CUST-004821 →," and every action on it beyond viewing history is
disabled. **Unmerging** (Admin-only, within the 30-day reversal window) is offered as a single action
on that banner and requires only a confirming `AlertDialog`, since the reversal itself re-derives from
the audit log server-side rather than asking the user to redo the field choices.

**Archiving.** A visibility-only toggle, gated `accounting.customer.archive`, offered only when the
client's own read of `customer_balances.balance_net` is zero and no open commercial documents exist —
this is a UX hint only; the server re-validates eligibility unconditionally
(`docs/accounting/CUSTOMERS.md → Workflow → Archive`) and returns a specific `409` naming exactly what
is still open if the hint was wrong (e.g., a `sales_order` confirmed after the page loaded). No
confirming dialog is shown (archiving is instantly reversible via "Reactivate" and changes nothing
financial), mirroring Journal Entries' identical "the one lifecycle action deliberately not wrapped in
a confirmation" precedent.

**Deleting.** Offered only for a customer with genuinely zero financial history, gated
`accounting.customer.delete`, restricted by default to Admin/Owner. The confirming `AlertDialog`
states plainly that this is different from Archive ("This customer has no invoices or payments and can
be permanently removed from view. Customers with any history should be archived instead."). A `409`
response (`CUSTOMER_HAS_FINANCIAL_HISTORY`) is not a generic error toast — it renders the exact
message the backend returns, naming the invoice count and balance that make deletion ineligible, with
a "Use Archive instead" action that immediately opens the Archive flow in its place.

**Bulk operations.** Selecting ≥2 rows (Merge aside, which requires exactly 2) offers Reassign owner,
Add tags, Remove tags, Change price list, Change segment, and Archive as bulk actions, each firing one
request per selected row (never a single endpoint) and reporting a per-row result, matching
`docs/accounting/CUSTOMERS.md → API → Bulk Operations`'s own "ineligible rows land in `details[]` with
a per-row reason rather than failing the whole batch" contract exactly.

**Import.** "Import" opens a `Dialog` accepting a CSV/XLSX file and a column-mapping step. On submit,
`useImportCustomers()` uploads the file and calls `POST .../import`; the response's `import_job_id`
and `estimated_completion_seconds` render as a progress toast, and the user is notified
(`customer.import.completed`) when the results file — a per-row success/failure/duplicate-warning
breakdown with the specific validation error for failures — is ready to download; no row is silently
dropped or silently overwritten.

**Exporting.** "Export" (List, gated `accounting.customer.export`) opens a `Dialog` offering
CSV/XLSX/PDF and, only for a caller holding the narrower `accounting.customer.export.unredacted`
permission, a toggle to include unredacted `national_id`/`tax_registration_number`/
`commercial_registration_number` values — off by default even for a caller who holds the permission,
so an accidental unredacted export requires an explicit, deliberate opt-in every time
(`docs/accounting/CUSTOMERS.md → Security → Field-level sensitivity`). Every exported file embeds the
exporting user's identity and timestamp in its footer, rendered by the backend, not the frontend, so
the watermark cannot be stripped by a client-side export path.

# AI Integration

Per `docs/frontend/README.md`'s platform-wide rule, "AI is visible, never silent": every AI-originated
figure on this screen carries its confidence and, on demand, its reasoning, and none of the eight
agents named in `# Data & State` ever changes a customer's `credit_limit`, `lifecycle_state`, or
identity fields directly. `AIProposalPanel`'s one-click "Do it" affordance structurally never renders
anywhere on this screen — every agent here is either `suggest-only` or `requires-approval`
(`docs/accounting/CUSTOMERS.md → AI Responsibilities`'s own summary table has no `auto` row for
anything that mutates a customer; Smart Search is the sole `auto` agent, and it is read-only by
definition), so there is no code path where an AI output on Customers executes itself.

**Customer Risk Scoring.** Appears as `RiskScoreBadge` in three places (List Risk column, Profile
Summary Rail, Credit & Risk tab) rendering the same underlying `customer_balances.ai_risk_score` and
"View reasoning" trigger everywhere it shows. Because the score is a business metric with a real
polarity rather than a neutral figure, it is the one AI output on this screen styled with the
platform's signed-metric color convention (success/warning/danger risk bands) — a deliberate,
named exception to "AI-touched things use the reserved AI accent, not a status hue," justified the
same way `docs/frontend/JOURNAL_ENTRIES.md → Dark Mode` justifies showing a Fraud-Detection flag's
`warning` tone *alongside*, never instead of, its `ConfidenceBadge`: risk banding is a fact about the
customer, and confidence is a fact about the AI's certainty in that fact, and both are shown, never
collapsed into one signal. The score never blocks anything by itself — it is an input to the nightly
Blocking Rules job and a factor a Finance Manager weighs manually; only the deterministic overdue-
bucket and credit-limit rules in `docs/accounting/CUSTOMERS.md → Credit Management` can actually hold
or block a transaction.

**Payment Prediction.** `PaymentPredictionCard` renders `ai_predicted_next_payment_date` +
`ai_predicted_payment_confidence`. It never triggers an automated dunning message by itself — a
Collections Officer or the (not-yet-documented) Approval Assistant surfaces the prediction alongside a
**draft** communication a human sends, per `docs/accounting/CUSTOMERS.md → AI Responsibilities →
Payment Prediction`'s explicit "never triggers automated communications by itself" rule.

**Fraud Detection.** Never rendered as a numeric score anywhere a customer's own Sales Owner would see
it by default — a `fraud_flag` (confidence ≥ 0.6) surfaces only to Finance Manager and Auditor roles as
a `warning`-toned banner at the top of the Credit & Risk tab: "This account was flagged by Fraud
Detection — review evidence." Clicking it opens a `Sheet` listing the flag's `evidence` (documents/
transactions referenced) and `reasoning`, with **Blacklist this customer** and **Dismiss flag**
actions — both require the viewer's own decision and reason; the flag itself never blacklists anyone.
At confidence ≥ 0.85 the banner additionally names that the Owner has also been notified
(`docs/accounting/CUSTOMERS.md → Notifications`), so a Finance Manager reviewing it understands the
matter is already visible above them, not something they alone are quietly deciding.

```json
// GET /api/v1/accounting/customers/5190/fraud-flags (illustrative — flag detail payload
// referenced from the Credit & Risk tab's banner, per docs/accounting/CUSTOMERS.md's
// ai_flags table, flaggable_type='customer')
{
  "success": true,
  "data": {
    "id": 7712,
    "flaggable_type": "customer",
    "flaggable_id": 5190,
    "flag_type": "velocity_anomaly",
    "confidence": 0.88,
    "reasoning": "New customer requested a KWD 15,000 credit limit on day 2, roughly 12x the segment median first-request limit (KWD 1,250) for a Business customer of this size and industry.",
    "evidence": [
      { "type": "customer_credit_limit_requests", "id": 442, "label": "Initial credit-limit request" },
      { "type": "customer_documents", "id": 981, "label": "Commercial registration certificate — issue date 4 days before signup" }
    ],
    "status": "open",
    "created_at": "2026-07-14T08:02:11Z"
  },
  "message": "Fraud flag retrieved.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "f1a2b3c4-d5e6-47f8-9012-3456789abcde",
  "timestamp": "2026-07-16T09:00:00Z"
}
```

**Duplicate Detection.** Surfaces at three moments, all non-blocking except the deterministic
exact-match case: the Create form's Duplicate check card (`# Interactions & Flows`), the post-save
`DUPLICATE_SUSPECTED` warning banner, and the standing **Merge Candidates** saved view in the List
(sorted by confidence descending, candidates below 0.5 never queued, per
`docs/accounting/CUSTOMERS.md → AI Responsibilities → Duplicate Detection`), which is the entry point
most Admin/Finance Manager users actually work from rather than waiting for a create-time collision.
Accepting a Merge Candidates row opens `CustomerMergeDialog` pre-populated exactly as if the user had
selected the pair manually.

**Smart Search.** Powers the List's search box end to end, including natural-language queries — "over
their credit limit in Hawalli" resolves through `GET /customers/search` into the same
`filter[credit_status]=over_limit&filter[city]=Hawalli` parameters a manually-built filter would
produce (`docs/accounting/CUSTOMERS.md → AI Responsibilities → Smart Search`), never a raw SQL string
the frontend has to sanitize itself. The search box shows a small "Interpreted as: Credit status = Over
limit, City = Hawalli" caption beneath the input when the API's `interpreted_filters` differ from a
literal text match, so a user understands why their query returned what it did and can correct the
interpretation by clicking directly into the equivalent Filter Bar control instead. A slow or failed
natural-language parse falls back to plain full-text matching within 800ms rather than leaving the box
in a loading state, per the backend's own progressive-enhancement contract.

**Recommendations.** The Overview tab's AI Recommendations panel lists this customer's open
suggestions ("raise credit limit — 18 consecutive on-time payments, DSO improved 22%," "flag for
Collections escalation — 2 broken promises in 60 days"), each with a confidence, a reasoning string,
and a single **"Create approval task"** button that routes into the exact human workflow the
suggestion implies (opening the credit-limit request dialog pre-filled with the suggested figure, or
opening the Collections case view) — the recommendation itself never performs the action; it only ever
hands off to the human-owned flow that can. Dismissing a recommendation requires a typed reason
(`ai.customer.override`), retained the same way `docs/accounting/CUSTOMERS.md → Workflow` records every
AI-suggestion override, human or automated, in `audit_logs`.

**Behavior Analysis.** The Overview tab's archetype chip (`growing` / `stable` / `at_risk_of_churn` /
`declining_engagement`) is read-only — it has no accept/reject affordance because it is a
classification, not a proposed action — and renders `insufficient_history` rather than guessing when
the customer has under six months of transaction history, per the backend's own minimum-history gate.

**Revenue Forecasting.** Surfaced only as a link from Overview into the not-yet-documented Reports
module's Revenue by Customer view, since the forecast itself is a reporting-layer artifact, not a
Customer Management action; this screen does not render the forecast figure directly to avoid
duplicating a number a future `REVENUE_BY_CUSTOMER.md` screen doc will own authoritatively.

**Confidence normalization.** Every confidence value this screen renders —
`ai_risk_score_confidence`, `ai_predicted_payment_confidence`, a fraud flag's `confidence`, a duplicate
candidate's `confidence`, a recommendation's `confidence` — is already stored 0.0000–1.0000
(`docs/accounting/CUSTOMERS.md`'s own convention, "every AI agent... writes every output with a
confidence score (0.0–1.0)"), so every `ConfidenceBadge` on this screen calls
`normalizeConfidence(value, 'fraction')`, never `'percentage'` — the AI Command Center's separately-
scaled 0–100 `ai_decisions.confidence_score` never appears on this screen at all, so there is no risk
of the wrong-mode mixup `COMPONENT_LIBRARY.md → ConfidenceBadge` warns about in the abstract.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | List: `Skeleton` rows shaped like the table; Profile: header + Summary Rail + active tab skeleton |
| Empty (no customers at all) | Brand-new company, zero `customers` rows | `EmptyState`: "No customers yet," CTA "New Customer" gated on `.create` |
| Empty (filtered to nothing) | Filters/search legitimately produce zero rows | Lighter `EmptyState` variant, "No customers match your filters," never conflated with true-empty |
| Lead (not shown here) | `lifecycle_state = 'lead'` | Never reachable on this screen — leads are excluded from every List query and every direct navigation resolves `not-found.tsx`, per `# Layout & Regions` |
| Customer (active) | `lifecycle_state = 'customer'` | Full read/write Profile; every tab and lifecycle action available per the caller's permissions |
| Inactive | `lifecycle_state = 'inactive'` | Full Profile, unchanged, plus a quiet `neutral` banner: "This customer has had no activity in 90+ days" — informational only, no action forced |
| Archived | `lifecycle_state = 'archived'` | Read-mostly Profile (contacts/addresses/tags remain editable; new commercial documents cannot be created against it); "Reactivate" is the sole lifecycle action offered |
| Blacklisted | `lifecycle_state = 'blacklisted'` | Profile renders with Edit omitted entirely (`# Route & Access`); a persistent `danger`-toned banner states the reason and who applied it; "Reinstate" (gated, dual-approval) is the sole lifecycle action offered |
| Merged away | `merged_into_customer_id IS NOT NULL` | Persistent banner, "This customer was merged into {survivor} →"; every action beyond History and Unmerge (Admin, ≤30 days) is disabled |
| Soft-deleted (recoverable) | `deleted_at IS NOT NULL`, within the recovery window | Not listed by default; reachable only via a direct link or an explicit "include deleted" List filter (Admin), rendering a "Restore" action in place of any other lifecycle control |
| Duplicate suspected (inline) | `meta.warnings[].code === 'DUPLICATE_SUSPECTED'` on an otherwise-successful save | Dismissible banner, never a blocking modal (`# Interactions & Flows`) |
| Exact-match duplicate | `409 CUSTOMER_HAS_...` / identifier-collision error on create | Inline error naming the existing `customer_code`, with a "View existing customer" link |
| Credit-limit change pending | An open `approval_request` of type `customer_credit_limit` exists for this customer | Credit & Risk tab shows the current limit plus a "Pending: {requested value}" annotation; the request-change action itself becomes "View pending request" |
| Risk score / prediction insufficient history | `ai_risk_score` / `ai_predicted_next_payment_date` is `null` | `RiskScoreBadge` / `PaymentPredictionCard` render their "Insufficient history" branch, never a zero or a blank |
| Delete blocked by financial history | `409 CUSTOMER_HAS_FINANCIAL_HISTORY` | The exact backend message rendered inline, with a "Use Archive instead" action (`# Interactions & Flows`) |
| Archive blocked by open documents | `409` naming the specific open order/delivery/contract/subscription | Inline error naming what is still open, never a generic "cannot archive" |
| Version conflict | `409` on a concurrent `PATCH` | Toast: "This customer was modified by someone else — reload and retry"; unsaved local edits are preserved in the form until the user chooses to reload |
| Session permission revoked mid-view | A subsequent mutation's `403` | The affected control collapses to its disabled-with-tooltip form; no stale enabled control is trusted from initial load |
| Error | `403` / `404` / `5xx` / network failure | Shared `403` boundary, `not-found.tsx`, or `ErrorState` with retry, per which failure occurred |

# Responsive Behavior

**List, Mobile (`base`–`sm`).** Follows `RESPONSIVE_DESIGN.md → Adaptive Patterns → Pattern 1`: rows
become cards, each headed by `legal_name` and `StatusPill`, with a two-column definition list of
`customer_code`, `customer_type`, and a single `RiskScoreBadge` (`size="sm"`) — the desktop's separate
Balance and Risk columns collapse into one `AmountCell mode="plain"` line plus the badge, since a card
the user is about to tap into does not need the scanning benefit multiple side-by-side columns give a
dense desktop table. Filters collapse behind a single badge-counted "Filters" trigger opening a
`Sheet`; the density toggle and column-visibility menu are hidden entirely.

**Create/Edit, Mobile (`base`–`sm`).** `CustomerForm`'s Contacts and Addresses `useFieldArray` grids
render as stacked cards per row (full-width fields, a full-width "Remove" button, matching
`RESPONSIVE_DESIGN.md → Forms On Mobile`'s multi-line-editor pattern exactly the way
`JournalLineGrid` degrades on the same breakpoint), never the desktop's compact grid rows. The Side
Rail's Duplicate check and Financial terms preview collapse into an inline panel directly above the
sticky Cancel/Save footer.

**Profile, Mobile (`base`–`sm`).** The Summary Rail moves above the Tab/Segment Nav (key facts —
balance, credit limit, risk — read before the detail tabs), and the seven tabs become a horizontally
scrollable `Tabs` strip rather than wrapping to a second line, per `LAYOUT_SYSTEM.md`'s Detail Page
Template reflow. The Statement tab's running-balance table and the Transactions tab's per-document
sections both use the sticky-first-column, horizontal-scroll treatment
`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` specifies, rather than a card transformation
— a chronological ledger benefits from staying tabular even at narrow widths, the same reasoning
Journal Entries' own `JournalLinesTable` relies on.

**Profile, Tablet/Desktop (`md`+).** The full two-column Detail Page Template: Main Column (8/12) +
Summary Rail (4/12) from `lg:` up; below that, the Summary Rail sits above the tabs as an inline
strip rather than a true rail, matching the Mobile reflow one breakpoint later than a true rail would
otherwise appear.

**Virtualization.** The List's `DataTable` virtualizes past ~200 rows via `@tanstack/react-virtual`;
the Statement and Transactions tabs virtualize their own row collections independently past the same
threshold, since a long-lived Business or Government customer can realistically accumulate thousands
of historical invoices.

**Touch targets.** Every interactive element — row-action icons, the Contacts/Addresses "make
primary"/"make default" radio controls, the Merge dialog's field-choice radio buttons — meets the
platform's 44×44px minimum regardless of active table density.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, inheriting
`THEMING.md`'s RTL-as-a-theming-dimension and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a
single per-component RTL branch, plus several module-specific applications:

- **Bilingual legal names.** `legal_name` is the name as it appears on legal documents and is never
  translated or mirrored; `name_ar`/`name_en` are the platform's separate bilingual display pair, and
  the List/Profile render `name_ar` under an Arabic session, falling back to `name_en` only when the
  Arabic value is genuinely absent — never the reverse, per the platform's bilingual-master-data
  convention already established for accounts and cost centers.
- **Balances, credit limits, and risk scores stay LTR-embedded and right-aligned in both
  directions.** Every `AmountCell` and the numeral inside `RiskScoreBadge` render `dir="ltr"` with
  `text-end` alignment regardless of session direction, exactly matching Journal Entries' identical
  numeral-alignment exception — a bilingual Finance team scanning magnitude by decimal alignment must
  see every number land on the same edge.
- **`AgingBar`'s five segments are physically fixed, never mirrored**, so the "Current" segment
  always sits at the visual start and "90+" at the visual end regardless of `dir`, matching the
  universal convention a printed Aging Report already uses and avoiding a reading-order flip that
  would make the bar mean something different in Arabic than in English.
- **Customer codes, ISO country/currency codes, and confidence percentages stay LTR-embedded** inside
  Arabic sentences (the Duplicate check card's narrative, the AI reasoning `Sheet`'s prose) via the
  shared `Bidi`/`LtrInline` wrapper, so "CUST-004821" or "78%" never reorders inside a right-to-left
  paragraph.
- **AI reasoning is generated in the viewer's session locale**, not machine-translated client-side,
  and Arabic accounting/CRM terminology is used precisely: عميل (customer), حد ائتماني (credit limit),
  تصنيف ائتماني (credit rating), متأخر السداد (overdue), قائمة سوداء (blacklist), دمج (merge), أرشفة
  (archive), تقييم المخاطر (risk scoring), التنبؤ بالدفع (payment prediction).
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching the
  platform-wide rule that a mixed numeral system inside a single ledger or customer record reads as an
  error to an accountant.
- **Directional chrome mirrors; content chrome does not.** The AI reasoning and Contacts/Addresses
  inline-edit `Sheet`s slide from the logical end edge; the back-link chevron mirrors; the module icon,
  `StatusPill`'s status dot, and every severity glyph do not, per `ICONOGRAPHY.md`'s directional-icon
  table.
- **Type-conditional identifier labels translate, the identifiers themselves do not.** "Commercial
  Registration Number" / "رقم السجل التجاري" is a translated label; the value entered (`CR-2019-004821`)
  is always rendered LTR-embedded regardless of locale, since it is an alphanumeric code, not prose.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a bare Tailwind palette utility outside the shared component files it
composes.

- **Surfaces and elevation.** The List and Profile canvases sit on `--surface-canvas`; every `Card`
  section in `CustomerForm` and every tab panel on the Profile sit on `--surface-base`; the AI
  reasoning `Sheet`, `CustomerMergeDialog`, and every confirmation `AlertDialog` sit on
  `--surface-overlay`, stepping lighter toward the viewer in dark mode exactly as
  `docs/frontend/JOURNAL_ENTRIES.md → Dark Mode` describes for its own equivalent surfaces.
- **Status tones.** `StatusPill`'s new `domain="customer"` and `domain="customer_document"` lookup
  tables (`# Components Used`) resolve through the same `--status-success`/`-warning`/`-error` plus
  `--accent` semantic tokens `DARK_MODE.md → Token Mapping` already defines — no new token is
  introduced, and each pairing is independently contrast-checked in both themes rather than assumed to
  pass because its light-mode counterpart did.
- **The risk score is the one AI-adjacent figure that is intentionally status-colored, and this is
  called out explicitly.** Every other AI-touched element on this screen (the Duplicate check card's
  border, `ConfidenceBadge` fills, the Recommendations panel's accent) uses the reserved
  `--ai-accent`/`--ai-accent-subtle` tokens in both themes, never a finance-status hue — but
  `RiskScoreBadge`'s risk band is a deliberate, documented exception (`# AI Integration`), using
  `--status-success`/`-warning`/`-error` because the score itself is a genuinely signed business
  metric, not a confidence-of-an-AI-answer figure. The two are shown side by side rather than merged
  into one color specifically so this distinction stays visible: the risk band answers "is this bad
  news," the adjacent `ConfidenceBadge` answers "how sure is the agent."
- **Debit/credit-adjacent balances are never status-colored.** `AmountCell` on this screen (the List's
  Balance column, the Statement tab's running balance, the Credit Exposure meter's underlying figures)
  keeps its ink/text-primary tone in both themes; only the credit-exposure meter *bar* itself (not the
  number beside it) turns `warning`/`danger` past 80%/100% of the limit, matching the platform's
  "color the signed ratio, not the raw figure" convention.
- **Contrast.** Every pairing above is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text,
  borders, focus rings, `AgingBar` segment boundaries) in both themes.
- **Exports render light, always.** A PDF/XLSX export of a customer statement or a filtered customer
  list renders in QAYD's fixed light/print palette regardless of the exporting user's active theme,
  since a statement is routinely forwarded to the customer's own accounts-payable contact, who has
  never opened the QAYD app at all.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented without deviation, plus the
screen-specific applications below.

- **One table pattern throughout — the plain, accessible `<table>`, never the ARIA `grid`.**
  `ACCESSIBILITY.md → Data Tables Accessibility` names the Journal Entry line editor and the Bank
  Reconciliation matching grid as the *only* two surfaces in the platform warranting the heavier
  `grid` pattern; nothing on Customers is spreadsheet-like in that sense (`CustomerForm`'s Contacts/
  Addresses arrays are short, sparse, and edited one field at a time, not arrow-keyed cell-to-cell),
  so the List, the Statement tab, the Transactions tab, and both `useFieldArray` grids in
  `CustomerForm` all use real `scope="col"` headers, sortable headers as real `<button>`s with
  `aria-sort`, and a horizontally-scrolling wrapper that is itself `role="region"` + `tabIndex={0}` +
  a real `aria-label`.
- **Risk and confidence are never color-only.** `RiskScoreBadge` carries a real text label ("High
  risk") beside its color band, plus a `VisuallyHidden` equivalent ("Risk score 62 out of 100, medium
  risk"); `ConfidenceBadge`'s percentage is a real text node beside the badge, never inferable only
  from a fill color.
- **RBAC-disabled and business-rule-disabled controls explain themselves, and differently.** A
  "Request credit-limit change" button disabled because the viewer lacks
  `accounting.customer.credit_limit.set` carries `aria-describedby` naming that permission; the
  Archive action disabled because the customer has a non-zero balance carries a different
  `aria-describedby` ("This customer has an outstanding balance of KWD 1,650.000 — settle it or
  archive is unavailable"), never a single generic "You can't do this right now," per
  `ACCESSIBILITY.md`'s explicit rule that conflating the two is a P1 defect.
- **A sensitive action with no permission is omitted, not disabled.** Blacklist, Merge, Delete, Import,
  and Export are removed from the DOM entirely for a role lacking the corresponding permission, never
  rendered disabled with no explanation, matching `NAVIGATION_SYSTEM.md`'s identical
  `actions.filter(hasPermission)` precedent.
- **Focus management on overlays.** `CustomerMergeDialog`'s `onOpenAutoFocus` moves focus to its
  heading; the Blacklist/Reinstate/Archive confirmation dialogs move focus to their primary warning
  text, not their close button; closing any overlay on this screen returns focus to the control that
  opened it.
- **The Duplicate check card is a live region proportional to its severity.** `role="status"
  aria-live="polite"` while it resolves from "Checking…" to "No similar customer found" / "Similar
  customer found"; the post-save `DUPLICATE_SUSPECTED` banner and an exact-match `409` both escalate to
  `role="alert"` (assertive), matching `ACCESSIBILITY.md`'s live-region severity tiers.
- **Keyboard.** `N` opens `/new` (`# Route & Access`); `/` focuses the List's search box; within
  `CustomerForm`'s field arrays, `Tab` moves through each row's fields in reading order and a
  dedicated "Add contact"/"Add address" button (never an auto-added blank row on `Tab` from the last
  field) keeps focus predictable. Every `Dialog`/`AlertDialog`'s primary action responds to
  `Cmd`/`Ctrl+Enter`.
- **Realtime updates never yank focus or splice silently.** A `.customer.balance_changed` push
  affecting the customer a Finance Manager is currently reviewing updates the Summary Rail's figures in
  place without moving keyboard focus or reordering the List a second window might have open, per the
  platform-wide realtime-accessibility rule.

# Performance

- **Server-paginated, never client-sorted or client-filtered.** The List's `DataTable` issues a new
  request for every sort, filter, or page change — a company with thousands of customers never ships
  more than one page's worth of rows to the browser at a time.
- **Virtualization past ~200 rows** on the List, the Statement tab, and the Transactions tab
  (`# Responsive Behavior`), using a density- or breakpoint-aware row-height estimate.
- **Cache tuning matches data volatility**, per `FRONTEND_ARCHITECTURE.md → Cache tuning by data
  class`: the List's `staleTime` is 30 seconds; `useCustomerBalance` is `staleTime: 0` and kept fresh
  via the realtime patch instead of polling; reference data (Sales owners, payment terms, price lists)
  is 5 minutes.
- **Debounced, cancelled duplicate/search checks.** The Create form's Duplicate check debounces 400ms
  off `legal_name` and is cancelled on unmount or a further keystroke; the List's Smart Search box
  debounces 250ms, matching `AccountPicker`'s established debounce precedent, so a fast typist never
  fires a request per keystroke.
- **Idempotent, single-attempt mutations.** Every write endpoint carrying real consequence (`create`,
  `blacklist`, `reinstate`, `merge`, `credit-limit-requests`, `bulk`) carries a client-generated
  `Idempotency-Key` scoped to one logical submission attempt, so a slow response on a flaky connection
  followed by an impatient second click can never double-create, double-blacklist, or double-submit a
  merge request.
- **Async, non-blocking export and import.** `GET /customers/export` and `POST /customers/import` both
  fire an async job rather than holding a request open; the frontend never blocks the UI on either,
  subscribing instead to the job's own completion signal.
- **Deferred, code-split overlays.** `CustomerMergeDialog`, the AI reasoning `Sheet`, the Import
  `Dialog`, and the Fraud Detection evidence `Sheet` are dynamically imported (`next/dynamic`) rather
  than bundled into the List's or Profile's initial route chunk, since none is needed for first paint.
- **SSR-seeded first paint.** Both the List's first page and a Profile route's initial fetch happen
  server-side and hydrate straight into the TanStack Query cache, so the client's first `useQuery` for
  the same keys resolves instantly.
- **The Profile's seven tabs are not all fetched eagerly.** Only the Overview tab's data (and the
  always-visible Summary Rail's balance) is part of the route's first-paint fetch; Contacts &
  Addresses, Credit & Risk, Statement, Transactions, Documents, and History each fetch on first
  activation and cache thereafter, so opening a customer with a long Statement history does not delay
  the initial paint of its Overview.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| Two users edit the same customer's contacts concurrently | `409` toast, "This customer was modified by someone else — reload and retry"; unsaved local edits are preserved until the user chooses to reload |
| A credit-limit request is approved while the requester's tab is still open | Realtime `.customer.credit_limit.approval_decided` invalidates the Profile in place; the "Pending" annotation clears and the new limit appears without a manual refresh |
| A customer is blacklisted while a Sales user has its Profile open mid-quotation elsewhere | Realtime `.customer.blacklist.applied` invalidates the customer's `detail()`; any open Sales document creation flow for that customer surfaces `403 CUSTOMER_BLACKLISTED` on its own next save attempt rather than this screen trying to reach into another module's in-progress form |
| A merge request is rejected | The two records remain fully independent and unaffected; a toast states the rejection reason, and `CustomerMergeDialog`'s draft field-choices are discarded (the user re-opens Merge from scratch if they still believe it is warranted) |
| Two AI Duplicate Detection candidates point at each other with different confidences | The Merge Candidates view shows the pair once (keyed by the unordered id pair), using the higher-confidence direction's suggested survivor as the pre-selected default, fully overridable |
| Exchange rate / multi-currency: a customer's `preferred_currency` change is attempted while an open invoice in the old currency exists | Blocked client-side with an inline explanation and re-blocked server-side with `422`; the field renders a "Requires Finance Manager approval to change" hint instead of a bare disabled state |
| Government customer with `credit_status = 'over_limit'` | The List and Credit & Risk tab render the over-limit badge exactly as for any other type, but the "New Sales Order" deep link is never itself disabled here — per `docs/accounting/CUSTOMERS.md`, Government customers are warn-only at the credit gate, and that gate lives in the Sales module's own order-confirmation flow, not in a block this screen would render |
| A `sales_owner_id` user is deactivated in Settings | The Profile and List continue to show their name (denormalized snapshot), with a small "deactivated" caption; reassigning the owner is offered inline rather than requiring a full Edit |
| Export requested while a bulk import for the same company is still processing | Both proceed independently — export reads the current committed state at request time; a toast on the export dialog notes "An import is still in progress; newly imported rows may not be included" |
| A customer referenced by `custom_fields` whose definition was later deleted in Settings | The Overview tab renders the stored value as a plain read-only key/value pair rather than erroring, since the rendering control (`custom_field_definitions`) no longer exists to say how to edit it |
| Realtime push arrives for a customer not currently in view (List paginated elsewhere, or Profile of a different customer open) | Held aside behind the List's non-disruptive "New activity — Refresh" banner rather than spliced into visible rows or silently reordering the current page |
| Reconnecting after an extended disconnect | Every realtime-fed query key for this screen is invalidated once on reconnect, since WebSocket frames broadcast while disconnected are permanently missed |
| A very long-lived customer (10,000+ historical invoices) | Statement and Transactions tabs virtualize and cursor-paginate rather than attempting to load the full history; the Statement's "Export" action, not the on-screen table, is the intended path to a complete historical record |
| Session permission revoked mid-view | The next mutation attempt's `403` collapses the affected control to disabled-with-tooltip and shows an explanatory toast, rather than a stuck spinner or silent no-op |

# End of Document
