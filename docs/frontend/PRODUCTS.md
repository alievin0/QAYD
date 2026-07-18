# Products — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PRODUCTS
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Products screen set: the catalog **List**
and the combined **Detail/Create** surface over the `products` table and its family of child tables
(`product_categories`, `units_of_measure`, `product_variants`, `product_bundle_items`,
`product_barcodes`, `product_batches`, `product_serials`, `price_lists`, `price_list_items`,
`product_price_suggestions`, `product_price_history`) that `docs/accounting/PRODUCTS.md` defines as the
item master of the entire platform. Every SKU a Sales quotation lines up, every unit cost an Inventory
valuation report sums, every COGS line a posted invoice generates — all of it resolves, at some point,
back to exactly one row in this screen's data model. This is therefore one of the highest-leverage
surfaces in QAYD from a data-quality standpoint: a mis-mapped `cogs_account_id` or a duplicated SKU
created here silently corrupts every downstream document that references it until someone notices, so
this screen's job is to make correct data entry the path of least resistance and to make every
AI-assisted shortcut (classification, duplicate detection, price optimization) visibly reviewable
rather than silently trusted.

Two sub-surfaces are covered by this one document, because they share one route family, one
permission surface, and one set of composed components, per `docs/frontend/README.md` and
`COMPONENT_LIBRARY.md`:

1. **List** (`/inventory/products`) — browse, filter by status/category/type/brand/tags, full-text and
   semantic search, barcode lookup, bulk act, import, and export. The default landing surface for every
   role holding `products.read`.
2. **Detail/Create** (`/inventory/products/new`, and `/inventory/products/[productId]`) — a single
   composed surface that renders as a read-first, tabbed record view (Overview, Variants, Pricing,
   Inventory, Duplicates, History) with an explicit **Edit** affordance that switches the Overview tab's
   fields into the same `ProductForm` a brand-new product uses. Unlike `JOURNAL_ENTRIES.md`'s
   draft/rejected-only editability, a product remains substantially editable across `draft`, `active`,
   and `discontinued` — the backend gates individual **fields**, not the whole record, once a product has
   transacted (`SKU_IMMUTABLE_AFTER_TRANSACTION`, `COSTING_METHOD_LOCKED_WITH_STOCK`,
   `product.unit_of_measure_changed` blocked with history). Only `archived` is structurally different: it
   has no ordinary Edit affordance at all, only a narrow, explicitly-labeled correction flow (`# Layout &
   Regions`).

This screen never computes a financial truth, a stock quantity, or a valuation of its own. Every price
resolution, every `inventory_summary` rollup, and every account-mapping fallback chain shown here is
computed by the Laravel API (`PriceResolutionService`, `ProductService`) and rendered as-is, matching
`FRONTEND_ARCHITECTURE.md`'s Principle 1 — nothing on this screen recomputes a weighted-average cost, a
reorder point, or a tax rate; it displays what `docs/accounting/PRODUCTS.md` already defines those to
be and lets the server's `422`/`409` responses be the only authoritative rejection of an invalid change.

**Two audiences share one shell**, per the platform's standing rule (`docs/frontend/README.md`): an
Inventory Manager, Senior Accountant, or Accountant doing high-volume catalog entry — creating SKUs,
attaching barcodes, running a 5,000-row import, correcting a category tree — wants dense, keyboard- and
scan-first data entry with minimal friction. A CFO, Finance Manager, or Owner reviewing this same catalog
wants a calmer, decision-first surface: approving or rejecting an AI price suggestion, resolving a
duplicate-candidate pair, correcting an account mapping that would otherwise misdirect every future
journal entry the product generates. Both are built from the same `DataTable`, `ProductForm`,
`ApprovalCard`-family components, `StatusPill`, and `AmountCell` — composed differently, gated by
different permission keys on the same record, never a parallel screen or a parallel data model.

A third author feeds this screen exactly as it does Journal Entries: **AI-assisted catalog entry.** A
product classified during bulk import, a duplicate pair flagged by the Auditor agent, or a price change
proposed by the CFO agent all enter this screen's data through the identical
`ProductService::create()`/`update()` code path a human uses — never a parallel table or a bespoke "AI
product" concept — and are distinguished only by the platform-wide AI-provenance affordances
(`ConfidenceBadge`, the `Sparkles` glyph, a filterable AI-assisted origin) covered in full under
`# AI Integration`. Per `docs/accounting/PRODUCTS.md`'s explicit autonomy table, only catalog-hygiene
fields on a first-time `draft` (category, brand, product type, tax category) may be auto-applied above a
confidence threshold; duplicate merges, price changes, and bundle creation are always suggest-only and
always require a human holding the specific gated permission (`products.merge`,
`products.pricing.approve`, `products.create`) to accept them.

# Route & Access

## Route tree

```text
app/(app)/inventory/products/
├── page.tsx                     # List — Server Component, first-paint fetch
├── loading.tsx                  # List skeleton (streamed while the Server Component fetches)
├── new/
│   └── page.tsx                 # Create — ProductForm, mode="create"
└── [productId]/
    └── page.tsx                 # Detail — ProductDetail (read view) with an in-place Edit affordance
```

`[productId]` follows the platform's dynamic-segment convention — camelCase, named for the entity,
never the generic `[id]` (`FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — and its API-resource
mirror is `/api/v1/products/{id}`.

**Why `/inventory/products`, not a bare `/products`.** `NAVIGATION_SYSTEM.md → Sub-navigation per
module` places this screen's sidebar entry under **Inventory** — `Products — /inventory/products —
products.read (owning module: Products, a sibling of Inventory)` — for exactly the reason it gives for
`Customers` living under Sales and `Vendors` living under Purchasing: *"grouping is an
information-architecture decision; the permission gate always matches the record's true owner."* A user
finds this screen exactly where a Warehouse Employee or Inventory Manager already expects a catalog to
live, but nothing on this screen or its API calls ever resolves through an `inventory.*` permission
except the two narrow stock-receiving endpoints noted below — every read and every catalog mutation here
is gated by the Products module's own permission grammar (`products.*`), never a re-derived
`accounting.products.*` or `inventory.products.*` duplicate.

**One route, two rendered modes, chosen by an explicit Edit affordance — not by status.** This is a
deliberate departure from `JOURNAL_ENTRIES.md`'s "chosen by server-known status" pattern, because
Products' editability is field-scoped, not record-scoped (`# Purpose`):

```tsx
// app/(app)/inventory/products/[productId]/page.tsx
import { getProduct } from "@/lib/api/products";
import { ProductDetail } from "@/components/products/product-detail";
import { notFound } from "next/navigation";

export default async function ProductPage({
  params,
}: {
  params: Promise<{ productId: string }>;
}) {
  const { productId } = await params;
  const product = await getProduct(productId).catch(() => null);
  if (!product) notFound();

  // ProductDetail owns the read/edit toggle internally (useState, not a route change) — see
  // # Layout & Regions. This keeps the URL stable and bookmarkable across Overview/Edit and every tab,
  // and lets a mid-edit user switch to the Variants or Pricing tab without losing the route.
  return <ProductDetail initialProduct={product} />;
}
```

`archived` products render `ProductDetail` identically, but its Edit button is replaced entirely by a
"Correct data" action (`# Layout & Regions`, `# Interactions & Flows`) — never the same button silently
routed to a different endpoint, so a user is never surprised that "editing" an archived product
temporarily un-archives it.

## Access gate

The route sits inside the `(app)` route group, behind `middleware.ts`'s session-cookie check and the
resolved active-company context (`X-Company-Id`), per `FRONTEND_ARCHITECTURE.md`. A user without
`products.read` never sees "Products" under the Inventory section in the Sidebar or in the Command
Palette's fuzzy-search index, and a direct hit on `/inventory/products` or a specific `productId` renders
the shared `403` boundary rather than a `404` — the route exists, the record might exist, the viewer
simply may not see it. A `productId` that exists in a different company renders `not-found.tsx` instead,
per `FRONTEND_ARCHITECTURE.md`'s "403 vs. 404" rule: the API returns `404`, never `403`, for a
cross-tenant record specifically so a caller can never learn a product with that id exists elsewhere.

## Permission surface on this screen

Every key below is defined once, authoritatively, in `docs/accounting/PRODUCTS.md → Permissions`; this
table is this screen's map of key → concrete UI effect.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `products.read` | The route itself; every list row; every detail tab | Route renders the shared `403` boundary; nav item hidden |
| `products.create` | "New Product" button, `/new` route, the `N` shortcut, Save Draft in the Composer, accepting an AI bundle suggestion | Button/route removed from the DOM |
| `products.update` | Edit affordance on the Overview tab; Activate/Discontinue/Reactivate lifecycle actions; base `default_cost_price`/`default_selling_price` fields | Detail renders read-only; lifecycle buttons omitted |
| `products.delete` | "Delete" in the overflow menu (hard-delete-if-untransacted, else soft-delete/reject) | Menu item omitted |
| `products.archive` | "Archive" lifecycle action; "Correct data" on an already-archived product | Menu item / action omitted |
| `products.merge` | "Merge" action on a Duplicates-tab candidate pair | Row's Merge action omitted; candidate still visible read-only |
| `products.import` | "Import" entry point in the List's Page Header | Button omitted |
| `products.export` | "Export" button in the List's Filter Bar and the Detail overflow menu | Button omitted |
| `products.category.manage` | The category-tree admin affordance inside `CategoryPicker`'s "Manage categories" trigger | Trigger omitted; picker remains usable read/select-only |
| `products.pricing.read` | The Pricing tab's price lists, price history, and AI price-suggestion queue (read) | Tab renders a permission notice instead of price data |
| `products.pricing.update` | Editing/adding a `price_list_items` row inside the Pricing tab | Rows render read-only; "Add price" omitted |
| `products.pricing.approve` | Approve/Reject on an AI price suggestion card | Card renders read-only (no action row) |
| `products.account_mapping.update` | The Account Mapping section's four account pickers plus Tax Category, inside Edit mode | Fields render read-only even while the rest of the form is editable (field-level, not whole-form, gating) |
| `products.ai_agent` | Not directly rendered — scopes which write calls the AI service account may make; a defense-in-depth layer beneath the human-facing keys above | N/A (server-only) |
| `inventory.serialize` | "Register serials" action on a serialized product's Inventory tab | Action omitted |
| `inventory.batch_track` | "Register batch" action on a batch-tracked product's Inventory tab | Action omitted |

The last two rows are a deliberate, documented exception worth naming explicitly: registering serial
numbers or a batch/lot (`POST /products/{id}/serials`, `POST /products/{id}/batches`) hangs off a
Products-owned URL but is gated by **Inventory's** permission grammar, not Products', because receiving
stock is Inventory's domain event even when the receiving screen happens to be this one — the same
"grouping is IA, the gate matches the true owner" rule the route itself already follows.

Default role grants (Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, External
Auditor, Inventory Manager, Warehouse Employee, Sales Manager, Sales Employee, Purchasing Manager,
Purchasing Employee, Read Only, AI Agent) are exactly the table in `docs/accounting/PRODUCTS.md →
Permissions` — this document does not restate role-by-role grants to avoid the two tables drifting out
of sync; `usePermission()` is the only source either query. Two grants worth calling out because they
shape this screen's dual-audience framing concretely: **Inventory Manager** holds `products.create`/
`.update`/`.archive`/`.import`/`.export` but not `.merge`, `.pricing.approve`, or
`.account_mapping.update` — they can build and maintain the catalog but cannot silently move money or
collapse two products' reporting history. **CFO** holds the inverse emphasis — `.pricing.approve` and
`.account_mapping.update` but not `.create` — a CFO reviews and approves what Inventory/Accounting staff
already built, rather than typing SKUs.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `P` navigates to this screen
from anywhere in the app; `N`, scoped to this route, opens `/new` (reachable only when
`products.create` is held). Inside the List, `/` focuses the search box and `B` focuses the dedicated
barcode-lookup field (`# Layout & Regions`) without needing a mouse — a deliberate nod to the barcode
scan-and-search workflow this screen shares conceptually with a POS terminal, even though QAYD's web
client is not itself a point-of-sale surface.

# Layout & Regions

Both sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing List Page Template and Detail Page Template
verbatim — `LAYOUT_SYSTEM.md → List Page Template` names "Products" explicitly among the template's
worked examples ("Journal Entries, Invoices, Bills, Customers, Vendors, Products, Bank Transactions"), so
no new template shape is introduced here.

## List — List Page Template

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Products                                                     [+ New Product] │
│ 5,412 products                                  [Import ▾]  [Export ▾]  [⋯]  │
├──────────────────────────────────────────────────────────────────────────────┤
│ [Search…]  [🔖 Scan/enter barcode]  [Status▾][Category▾][Type▾][Brand▾]  [▤][⚙]│
├──────────────────────────────────────────────────────────────────────────────┤
│ ☐ │ 🖼 │ SKU        │ Name              │ Category   │ Type      │ Stock │Price│Status│⋯│
│ ☐ │▢  │ ELC-000042 │ Samsung 55" QLED  │ TVs        │ Physical  │  480  │350.0│●Active│⋯│
│ ☐ │▢  │ SVC-000011 │ Installation Fee  │ Services   │ Service   │   –   │ 15.0│●Active│⋯│
│ ☐ │▢  │ ELC-000039 │ LG 50" LED  ⚠dup  │ TVs        │ Physical  │   12  │280.0│●Draft │⋯│
│ … │…  │ …          │ …                 │ …          │ …         │  …    │ …   │ …    │⋯│
├──────────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 5,412                                       [‹ Prev] [Next ›] │
└──────────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, live product count (`meta.pagination.total`), primary action ("New Product",
  gated `products.create`), secondary actions menu (Import → `products.import`, Export →
  `products.export`), and an overflow (`⋯`) holding "Bulk update" (`products.update`) and "Manage
  categories" (`products.category.manage`).
- **Filter Bar** — search (`q`, full-text + semantic over `search_vector`/`embedding`, see `# AI
  Integration`), a **dedicated barcode field** distinct from the general search box (`BarcodeLookupField`,
  below), and the four filters this screen calls out explicitly: **Status** (multi-select over
  `draft`/`active`/`discontinued`/`archived`, defaulting to everything except `archived`), **Category**
  (`CategoryPicker` in multi-select tree mode — selecting a parent includes its descendants via the
  materialized `path` column, matching `docs/accounting/PRODUCTS.md`'s own category-scoped query
  strategy), **Product Type** (multi-select over the ten-value `product_type_enum`, grouped visually into
  *Stocked* (physical/raw material/finished/semi-finished goods), *Service & Digital*
  (service/digital/subscription), and *Other* (bundle/asset/expense item)), and **Brand** (a
  server-side-searched combobox over distinct `brand` values). A density toggle and column-visibility
  menu sit at the Filter Bar's end edge.
- **Bulk Action Bar** replaces the Filter Bar's right side once ≥1 row is selected: "12 selected ·
  Re-category · Adjust price % · Export · Clear" — each re-checks its own permission and posts through
  `POST /products/bulk` as one filtered-set operation, per `docs/accounting/PRODUCTS.md → API → Example
  5`, never `N` individual calls (unlike Journal Entries' per-row bulk-approve, a bulk *catalog* edit is
  naturally one filtered-set request because it shares one `operation`/`params` payload against a
  `filter`, not N independent lifecycle decisions).
- **Data Region** — `DataTable` (`resource="products"`, `paginationMode="page"`). Columns: a small
  primary-image thumbnail (from the polymorphic `attachments` table,
  `metadata->>'role' = 'primary'`), SKU (monospace), bilingual Name (renders `name_ar` under an Arabic
  locale, `name_en` otherwise, with a small "translation missing" indicator when the other is blank —
  `docs/accounting/PRODUCTS.md → Product Profile → Product Name`), Category, Product Type (`Badge`),
  **Stock** (the live `inventory_summary.total_available` rollup for stocked types, an em dash for
  non-stocked types — never a stored, potentially-stale number, since Products itself stores no
  quantity), **Price** (`AmountCell`, `default_selling_price`), Status (`StatusPill domain="product"`),
  and a row-actions menu. A small `⚠` duplicate-suspected glyph renders inline next to the name when
  `docs/accounting/PRODUCTS.md → AI Responsibilities → Duplicate Detection` has an open candidate pair
  for that row (`# AI Integration`).
- **Pagination Footer** — page controls; "Showing 1–25 of 5,412."

### `BarcodeLookupField`

A second, purpose-built input beside the general search box, because a barcode scan is a categorically
different interaction from typed full-text search: it expects an exact match against
`GET /api/v1/products/barcode/{code}` (the backend's own "O(1) barcode/QR lookup, POS hot path"), not a
ranked fuzzy result set, and it should resolve the instant a scanner's terminating keystroke (typically
`Enter`, emitted by virtually every USB/Bluetooth barcode scanner acting as a keyboard) fires.

```tsx
// components/products/barcode-lookup-field.tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Input } from "@/components/ui/input";
import { Barcode } from "lucide-react";
import { useTranslations } from "next-intl";
import { apiFetch } from "@/lib/api/client";
import { useApiToast } from "@/hooks/use-api-toast";

export function BarcodeLookupField() {
  const t = useTranslations("products.list.barcodeLookup");
  const router = useRouter();
  const toast = useApiToast();
  const [value, setValue] = useState("");
  const [pending, setPending] = useState(false);

  async function onSubmit(code: string) {
    if (!code.trim()) return;
    setPending(true);
    try {
      const match = await apiFetch<{ product_id: number }>(`/api/v1/products/barcode/${encodeURIComponent(code)}`);
      router.push(`/inventory/products/${match.product_id}`);
    } catch (err) {
      // 404 BARCODE_NOT_FOUND — offer to create a new product pre-filled with this code,
      // rather than a dead-end error (see # Interactions & Flows → Barcode-first lookup).
      toast.fromApiError(err, {
        action: { label: t("createFromCode"), href: `/inventory/products/new?barcode=${encodeURIComponent(code)}` },
      });
    } finally {
      setPending(false);
      setValue("");
    }
  }

  return (
    <Input
      value={value}
      onChange={(e) => setValue(e.target.value)}
      onKeyDown={(e) => e.key === "Enter" && onSubmit(value)}
      placeholder={t("placeholder")}
      aria-label={t("a11y.label")}
      icon={<Barcode className="size-4" />}
      loading={pending}
      className="w-56"
    />
  );
}
```

## Detail/Create — a read-first Detail Page Template with an inline Composer

```
┌───────────────────────────────────────────────────────┬───────────────┐
│ ‹ Products   ELC-000042 · Samsung 55" QLED  ●Active    │  Summary       │
│                          [Discontinue ▾] [Edit] [⋯]    │  ───────────   │
├─ Overview │ Variants │ Pricing │ Inventory │ Duplicates │ History ─────┤ 🖼 image
│  (Overview, view mode)                                  │  SKU ELC-000042│
│  Identity        Samsung 55" QLED 4K TV / تلفزيون...    │  Category TVs  │
│  Category        TVs  ›  Electronics                     │  On hand  480  │
│  Unit / Costing  Piece · Weighted Average                │  Available 415 │
│  Pricing         350.000 KWD (Standard Retail)            │  Price 350.000│
│  Account mapping 4001 · 5001 · 1301 · VAT-STANDARD         │  KWD           │
├───────────────────────────────────────────────────────┤  AI classified │
│  (Edit mode, same tab): ProductForm sections            │  92% confidence│
│  Identity → Category → Unit & Stocking → Pricing →      │  [View reasoning]│
│  Account Mapping → Tax → Images → Custom Fields          ├───────────────┤
│  [Cancel]                    [Save Draft]  [Activate]    │               │
└───────────────────────────────────────────────────────┴───────────────┘
```

- **Page Header** — back-to-list link, SKU + bilingual name, `StatusPill domain="product"`, the single
  lifecycle action applicable to the current status and the viewer's permissions (Activate on `draft`,
  Discontinue on `active`, Reactivate on `discontinued`, Archive on `discontinued` with zero stock/open
  documents — never more than one primary lifecycle action shown at once), an "Edit" toggle (replaced by
  "Correct data" on `archived`, `# Interactions & Flows`), and an overflow menu (Duplicate, Merge into…,
  Delete, Export this product).
- **Tab/Segment Nav** — `Tabs`: **Overview** (default — identity, category, unit/costing, the default
  price, account mapping, tax, images, custom fields), **Variants** (only rendered when
  `product_variants` rows exist or the product type supports them), **Pricing** (price lists, price
  history, AI price suggestions), **Inventory** (the read-only `inventory_summary` rollup by warehouse,
  min/max/reorder levels, and — for serialized/batch-tracked products — a read-only serials/batches
  count with a "Register serials"/"Register batch" action), **Duplicates** (AI-flagged candidate pairs,
  only rendered when `GET /products/{id}/duplicates` returns ≥1 row), **History** (merged
  `audit_logs` + `product_price_history` + AI classification log timeline).
- **Main Column (8/12)** — the active tab's content. Overview toggles, in place, between
  `ProductOverviewRead` and `ProductForm` (edit mode) — never a separate route, so switching tabs
  mid-edit (e.g. to check the Pricing tab while filling the Account Mapping section) does not discard
  the in-progress form; React Hook Form's state persists across the tab switch because `ProductForm`
  stays mounted, only visually hidden, exactly as `LAYOUT_SYSTEM.md`'s Detail template permits for a
  tabbed sub-view that shares one record's edit session.
- **Summary Rail (4/12)** — primary product image, SKU, category breadcrumb, the live
  `inventory_summary` mini-rollup (on hand/available, stocked types only), default price (`AmountCell`,
  `emphasis="strong"`, `CurrencyTag`), and — only when the product's category/brand/type/tax fields were
  AI-classified at creation — the AI provenance block: `ConfidenceBadge` and a "View reasoning" trigger
  opening the classification's reasoning in a `Sheet` (`# AI Integration`).
- **Activity Timeline** — rendered at the bottom of the History tab's Main Column content, never
  relegated to a rail footnote, per `LAYOUT_SYSTEM.md → Detail Page Template`.

### Archived correction flow, rendered distinctly

An `archived` product's Page Header shows "Correct data" instead of "Edit" (gated `products.archive`,
matching the backend's own restriction to "an Owner/Admin correcting a data-entry error"). Clicking it
opens a confirming `Dialog` explaining the exact mechanics — *"This will temporarily return
ELC-000042 to Discontinued so you can fix it, then re-archive it automatically when you save"* — before
calling `PATCH /products/{id}/unarchive-for-correction`. This is never the same "Edit" button silently
routed differently; the distinct label and the explanatory dialog exist precisely because the operation
has a side effect (a real, if transient, status change) an ordinary field edit does not.

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, or is one of the screen-specific
composed components this document introduces. A component gap discovered while building this screen is
proposed as an addition to `COMPONENT_LIBRARY.md`, never hand-rolled locally and never duplicated if a
near-equivalent already exists (`AccountPicker`'s combobox shape is reused for `CategoryPicker` rather
than a second bespoke tree-select being invented).

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The List's server-paginated, sortable, filterable product table (`resource="products"`) |
| `StatusPill` | `components/shared/status-pill.tsx` | Lifecycle status, list and detail (`domain="product"`, new lookup table added by this doc, see `# Dark Mode`) |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Cost/price figures everywhere: list Price column, Summary Rail, Pricing tab rows, price-suggestion cards |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Currency indicator beside a price wherever a product's `currency_code` could differ from the company base currency |
| `AccountPicker` | `components/accounting/account-picker.tsx` | The Account Mapping section's four pickers (revenue/expense/inventory/cogs), `accountTypeFilter` set per field |
| `Badge` | `components/ui/badge.tsx` | Product Type chips, tags, the "AI classified" origin chip |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Classification confidence (Summary Rail), price-suggestion confidence, duplicate-candidate similarity score |
| `ApprovalCard` | `components/shared/approval-card.tsx` | Not used directly for pricing/duplicates (see `ProductPriceSuggestionCard`/`DuplicateCandidateCard` below) — reused verbatim only for the rare case a company routes a bulk-import result through a formal approval, kept out of the default flow per `docs/accounting/PRODUCTS.md`'s narrower, screen-specific gating (`products.pricing.approve`, `products.merge`) |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` | Bundle-suggestion review (`# AI Integration`) — the one AI surface on this screen shaped exactly like an AI Command Center recommendation (recommended action + alternative + accept/send-for-approval/dismiss) |
| `KpiTile` | `components/dashboard/kpi-tile.tsx` | Optional List-header stat strip (total active SKUs, low-stock count, pending price suggestions) — see `# Edge Cases` for when it is omitted entirely rather than shown empty |
| `Tabs` | `components/ui/tabs.tsx` | Detail page's Overview / Variants / Pricing / Inventory / Duplicates / History segmentation |
| `Sheet` | `components/ui/sheet.tsx` | AI reasoning drill-in; mobile row-detail drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | Activate/Archive/Merge/Correct-data confirmations, Barcode-attach, Import |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | List row actions; Detail overflow menu |
| `Command`/`Popover` | `components/ui/command.tsx`, `components/ui/popover.tsx` | The searchable-combobox base `CategoryPicker` and `UnitOfMeasurePicker` are built on, matching `AccountPicker`'s own foundation |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `error-state.tsx` | List/Detail empty and error rendering (`# States`) |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing |

## Screen-specific components introduced by this document

| Component | Composes | Purpose |
|---|---|---|
| `ProductForm` | `AccountPicker`, `CategoryPicker`, `UnitOfMeasurePicker`, `ProductImageUploader`, RHF + Zod | The create/edit surface; renders its sections conditionally by `product_type` (below) |
| `ProductDetail` | `Tabs`, `ProductOverviewRead`, `ProductForm`, `ProductVariantsTable`, `ProductPricingTab`, `ProductInventoryTab`, `ProductDuplicatesTab`, `ProductHistoryTab` | The Detail route's single mount point; owns the read/edit toggle |
| `CategoryPicker` | `Command` + `Popover` (same shape as `AccountPicker`) | Searchable combobox over `product_categories`' tree, rendering each option's full breadcrumb path and, in the List's Filter Bar, a multi-select-with-descendants mode |
| `UnitOfMeasurePicker` | `Command` + `Popover` | Searchable combobox over `units_of_measure`, grouped by `unit_type` (count/weight/volume/length/time) |
| `BarcodeLookupField` | `Input` | The List's dedicated barcode-scan/entry field, `# Layout & Regions` |
| `BarcodeManager` | `Input`, `Select`, `Badge` | The Overview tab's barcode list editor — add/remove `product_barcodes` rows, set symbology, toggle `is_primary` |
| `ProductVariantsTable` | `DataTable`-adjacent plain `<table>`, `Input` | Lists/edits `product_variants` rows (attributes, price/cost delta, barcode, status) |
| `ProductPricingTab` | `AmountCell`, `Combobox`, price-list row editor | Price Lists sub-view, quantity-break rows, Price History timeline |
| `ProductPriceSuggestionCard` | `Card`, `ConfidenceBadge`, `AmountCell`, `Button` | One pending `product_price_suggestions` row with Approve/Reject, modeled on `ApprovalCard`'s shape but scoped to the pricing-approval permission and the suggestion's own floor/ceiling guardrail display |
| `DuplicateCandidateCard` | `Card`, `ConfidenceBadge`, `Button`, `AlertDialog` | One `product_duplicate_candidates` pair with Merge/Keep Both/Not a Duplicate |
| `ProductImageUploader` | Shared attachments upload primitive | Drag-drop multi-image upload with a primary-image toggle and bilingual alt text |
| `CustomFieldsSection` | `Input`/`Select`/`Switch`/`DatePicker`, driven by schema | Renders one input per `product_custom_field_definitions` row applicable to the product's category/type — a company-configured, not hardcoded, form section |

## `ProductForm`'s type-aware sections

Unlike `JournalEntryForm`'s fixed shape, `ProductForm` renders a different subset of sections depending
on `product_type`, mirroring the backend's own per-type `CHECK` constraints
(`docs/accounting/PRODUCTS.md → Database Design`) so a user is never shown a field they cannot legally
fill for the selected type:

```tsx
// components/products/product-form.tsx (excerpt — section visibility)
const STOCKED_TYPES = ["physical_product", "raw_material", "finished_goods", "semi_finished_goods"];
const NON_STOCKED_NO_INVENTORY = ["service", "digital_product", "subscription", "expense_item"];

function useVisibleSections(productType: ProductType) {
  return {
    stockAndCosting: STOCKED_TYPES.includes(productType),          // costing_method, inventory_account_id, min/max/reorder
    accountMapping: true,                                          // always present; which sub-fields render varies
    recurrence: productType === "subscription",                    // recurrence_unit, recurrence_interval
    bundleComposition: productType === "bundle",                   // product_bundle_items editor + bundle_pricing_mode
    serialAndBatch: STOCKED_TYPES.includes(productType),           // is_serialized / is_batch_tracked toggles
    inventoryAccountField: !NON_STOCKED_NO_INVENTORY.includes(productType),
  };
}
```

Switching `product_type` mid-edit on a product that already has values in a now-hidden field (e.g.
switching a `physical_product` to `service`) clears the now-invalid fields client-side and shows an
inline notice — *"Switching to Service will remove this product's inventory account mapping and costing
method"* — before the user saves, rather than silently submitting a payload the backend's
`ck_products_nonstocked_no_inventory_account` constraint would reject with a `422`. This is the same
"fast, friendly, purely cosmetic check before the round trip" pattern `JournalEntryForm`'s balance banner
uses — the server re-validates the identical rule unconditionally.

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/PRODUCTS.md → API`'s table, annotated with the hook and query
key this screen wires it to. All are versioned under `/api/v1/products` (plus the sibling
`/api/v1/product-categories`), carry `X-Company-Id`, and return the standard envelope.

| Method & Path | Permission | Hook | Query key |
|---|---|---|---|
| `GET /products` | `.read` | `useProducts(filters)` | `productKeys.list(filters)` |
| `GET /products/{id}` | `.read` | `useProduct(id)` | `productKeys.detail(id)` |
| `POST /products` | `.create` | `useCreateProduct()` | invalidates `productKeys.lists()` |
| `PATCH /products/{id}` | `.update` (`.account_mapping.update` for mapping fields) | `useUpdateProduct(id)` | invalidates `detail(id)` + `lists()` |
| `DELETE /products/{id}` | `.delete` | `useDeleteProduct()` | removes from `lists()`; invalidates on settle |
| `POST /products/{id}/activate` | `.update` | `useActivateProduct()` | pessimistic, see `## Mutations` |
| `POST /products/{id}/discontinue` | `.update` | `useDiscontinueProduct()` | pessimistic |
| `POST /products/{id}/reactivate` | `.update` | `useReactivateProduct()` | pessimistic |
| `POST /products/{id}/archive` | `.archive` | `useArchiveProduct()` | pessimistic |
| `PATCH /products/{id}/unarchive-for-correction` | `.archive` | `useUnarchiveForCorrection()` | pessimistic; opens edit mode on success |
| `POST /products/merge` | `.merge` | `useMergeProducts()` | invalidates `lists()`; redirects to the surviving `detail(keepId)` |
| `GET /products/barcode/{code}` | `.read` | `useProductByBarcode(code)` | `productKeys.byBarcode(code)`, `staleTime: 0` (hot path, `# Performance`) |
| `POST /products/{id}/barcodes` | `.update` | `useAddBarcode(id)` | invalidates `detail(id)` |
| `GET /products/{id}/price` | `.read` | `useResolvedPrice(id, params)` | `productKeys.price(id, params)` |
| `POST /products/{id}/price-lists/{priceListId}/items` | `.pricing.update` | `useSetPriceListItem(id)` | invalidates `productKeys.priceLists(id)` |
| `GET /products/{id}/price-history` | `.read` | `useProductPriceHistory(id)` | `productKeys.priceHistory(id)` |
| `GET /products/{id}/price-suggestions` | `.pricing.read` | `useProductPriceSuggestions(id)` | `productKeys.priceSuggestions(id)` |
| `POST /products/price-suggestions/{id}/approve` | `.pricing.approve` | `useApprovePriceSuggestion()` | pessimistic; invalidates `priceSuggestions`, `priceHistory`, `priceLists` |
| `POST /products/price-suggestions/{id}/reject` | `.pricing.approve` | `useRejectPriceSuggestion()` | optimistic (visibility-only, no financial mutation) |
| `GET /product-categories` | `.read` | `useProductCategories()` | `categoryKeys.tree()`, `staleTime: 5m` |
| `POST /product-categories` | `.category.manage` | `useCreateProductCategory()` | invalidates `categoryKeys.tree()` |
| `GET /products/search` | `.read` | `useProductSearch(q)` | `productKeys.search(q)` |
| `POST /products/import` | `.import` | `useImportProducts()` | invalidates `lists()` on job completion |
| `GET /products/import/{jobId}` | `.import` | `useImportJobStatus(jobId)` | `productKeys.importJob(jobId)`, polled every 2s while `status: "queued" \| "running"` |
| `GET /products/export` | `.export` | `useExportProducts()` | not cached — async `report_runs` job, see `# Performance` |
| `POST /products/bulk` | `.update` | `useBulkUpdateProducts()` | invalidates `lists()` on settle |
| `POST /products/{id}/variants` | `.update` | `useCreateVariant(id)` | invalidates `productKeys.variants(id)` |
| `POST /products/{id}/bundle-items` | `.update` | `useAddBundleItem(id)` | invalidates `detail(id)` |
| `GET /products/{id}/duplicates` | `.read` | `useProductDuplicates(id)` | `productKeys.duplicates(id)` |
| `POST /products/{id}/serials` | `inventory.serialize` | `useRegisterSerials(id)` | invalidates `productKeys.serials(id)`, `detail(id)` |
| `POST /products/{id}/batches` | `inventory.batch_track` | `useRegisterBatch(id)` | invalidates `productKeys.batches(id)`, `detail(id)` |
| `GET /products/reports/{reportKey}` | `reports.read` | `useProductReport(reportKey, params)` | `productKeys.report(reportKey, params)` |

## Query key factory

```ts
// lib/query/keys.ts (excerpt)
export const productKeys = {
  all: ["products"] as const,
  lists: () => [...productKeys.all, "list"] as const,
  list: (filters: ProductFilters) => [...productKeys.lists(), filters] as const,
  details: () => [...productKeys.all, "detail"] as const,
  detail: (id: number | string) => [...productKeys.details(), id] as const,
  byBarcode: (code: string) => [...productKeys.all, "barcode", code] as const,
  search: (q: string) => [...productKeys.all, "search", q] as const,
  variants: (id: number | string) => [...productKeys.detail(id), "variants"] as const,
  priceLists: (id: number | string) => [...productKeys.detail(id), "price-lists"] as const,
  price: (id: number | string, params: PriceResolutionParams) => [...productKeys.detail(id), "price", params] as const,
  priceHistory: (id: number | string) => [...productKeys.detail(id), "price-history"] as const,
  priceSuggestions: (id: number | string) => [...productKeys.detail(id), "price-suggestions"] as const,
  duplicates: (id: number | string) => [...productKeys.detail(id), "duplicates"] as const,
  serials: (id: number | string) => [...productKeys.detail(id), "serials"] as const,
  batches: (id: number | string) => [...productKeys.detail(id), "batches"] as const,
  importJob: (jobId: string) => [...productKeys.all, "import-job", jobId] as const,
  report: (key: string, params: unknown) => [...productKeys.all, "report", key, params] as const,
};

export const categoryKeys = {
  all: ["product-categories"] as const,
  tree: () => [...categoryKeys.all, "tree"] as const,
};
```

`ProductFilters` mirrors `docs/accounting/PRODUCTS.md → API`'s documented query parameters: `status`
(array), `product_type` (array), `category_id` (with an implicit descendants expansion resolved
server-side against `product_categories.path`), `brand`, `tags` (array, matched via `tags @>` on the
server), `q`, `sort`, `page`, `per_page`. The List's four named filters map directly onto this set;
Category additionally resolves through `useProductCategories()`'s cached tree so `CategoryPicker` never
re-fetches the whole tree per filter interaction.

## Reference data

`CategoryPicker`, `UnitOfMeasurePicker`, `AccountPicker` (reused), the tax-category select, and the
preferred-vendor combobox all depend on read-mostly reference collections, fetched independently with a
generous `staleTime` per `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`'s "rarely-changing
reference/master data" tier (5 minutes):

| Reference | Endpoint | Consumed by |
|---|---|---|
| Product categories (tree) | `GET /api/v1/product-categories` | `CategoryPicker` (Overview section + List filter) |
| Units of measure | `GET /api/v1/units-of-measure` | `UnitOfMeasurePicker` (base unit + `unit_conversions` editor) |
| Accounts (Chart of Accounts) | `GET /api/v1/accounting/accounts` | `AccountPicker` ×4 in Account Mapping (`accountTypeFilter` set per field: `asset` for inventory, `revenue` for revenue, `expense` for expense/COGS) |
| Tax codes | `GET /api/v1/accounting/tax-codes` | Tax Category select |
| Vendors | `GET /api/v1/purchasing/vendors` | `preferred_vendor_id` combobox |
| Warehouses | `GET /api/v1/inventory/warehouses` | Inventory tab's per-warehouse rollup and `product_warehouse_settings` override editor |
| Custom field definitions | `GET /api/v1/products/custom-field-definitions` | `CustomFieldsSection`, filtered client-side by the product's `category_id`/`product_type` against each definition's `applicable_category_id`/`applicable_product_type` |

The List itself follows the "transactional lists" cache tier (`staleTime: 30_000`) — new SKUs, price
changes, and stock crossing a reorder threshold are frequent enough that a stale list is a real
annoyance. The single exception is `useProductByBarcode`, set to `staleTime: 0` deliberately (`#
Performance`) — a barcode scan must always reflect the current price and stock state, never a
30-second-old cached answer, matching the backend's own framing of this endpoint as the platform's
single highest-QPS, freshest-required read path.

## Client state ownership

Consistent with `FRONTEND_ARCHITECTURE.md → State Management`:

| State | Owner |
|---|---|
| The product list, one product's detail, its variants, price lists, duplicates, history | TanStack Query cache, keyed as above |
| The in-progress `ProductForm` values (identity → images) | React Hook Form's internal state, schema-validated by `productSchema` (`# Interactions & Flows`) |
| Whether the Overview tab is in read or edit mode | Local `useState` inside `ProductDetail` — deliberately not a URL search param, so switching tabs mid-edit never round-trips through the router |
| List density, column visibility, saved filter presets | Zustand (`useDensityStore`) + `users.settings` sync |
| Selected rows for bulk action | Local state inside `DataTable`, cleared on navigation |
| The barcode-scan input buffer | Local `useState` inside `BarcodeLookupField` — ephemeral, cleared on submit |
| A mid-create draft's `Idempotency-Key` | `sessionStorage`, keyed to the Composer's `formInstanceId`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` |

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md`'s Principle 10, a reversible, non-financial action updates the cache
immediately and rolls back on failure; a mutation that changes transactable catalog state or moves
toward a financial commitment waits for the server's `2xx` first.

```ts
// hooks/products/use-products.ts (excerpt)
export function useActivateProduct() {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey("product-activate");
  return useMutation({
    // No onMutate — activation can fail readiness checks (missing price, missing account mapping)
    // and the UI must never imply "active" before the server actually agrees.
    mutationFn: (id: number) => apiFetch(`/api/v1/products/${id}/activate`, { method: "POST", idempotencyKey }),
    onSuccess: (product: Product) => {
      qc.setQueryData(productKeys.detail(product.id), product);
      qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
  });
}

export function useRejectPriceSuggestion(productId: number) {
  const qc = useQueryClient();
  return useMutation({
    // Rejecting a suggestion changes nothing financial — it only dismisses a proposal — so it is
    // the reversible, low-stakes half of Principle 10 and updates optimistically.
    mutationFn: (suggestionId: number) =>
      apiFetch(`/api/v1/products/price-suggestions/${suggestionId}/reject`, { method: "POST" }),
    onMutate: async (suggestionId) => {
      await qc.cancelQueries({ queryKey: productKeys.priceSuggestions(productId) });
      const previous = qc.getQueryData(productKeys.priceSuggestions(productId));
      qc.setQueryData(productKeys.priceSuggestions(productId), (old?: PriceSuggestion[]) =>
        old?.filter((s) => s.id !== suggestionId));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(productKeys.priceSuggestions(productId), ctx?.previous),
  });
}
```

`useApprovePriceSuggestion`, `useMergeProducts`, `useArchiveProduct`, and `useDiscontinueProduct` follow
`useActivateProduct`'s exact shape — no `onMutate`, a client-generated `Idempotency-Key` per logical
attempt, and a confirming `Dialog`/`AlertDialog` in front of each (merge and archive additionally require
the user to type or select the specific target, never a bare "Confirm" on an implicit default).

## Realtime

The List and an open Detail record both subscribe to the company's private products channel via Echo,
re-firing the domain events `docs/accounting/PRODUCTS.md` already names (`product.activated`,
`product.discontinued`, `product.reorder_point_breached`) plus the AI-staging events its AI
Responsibilities section implies (a new duplicate candidate, a new price suggestion):

```ts
// hooks/products/use-product-realtime.ts
"use client";
import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { echo } from "@/lib/realtime/echo";
import { productKeys } from "@/lib/query/keys";

export function useProductRealtime(companyId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`company.${companyId}.products`);

    channel.listen(".product.activated", (e: ProductActivatedEvent) => {
      queryClient.setQueryData(productKeys.detail(e.product_id), (old?: Product) =>
        old ? { ...old, status: "active", activated_at: e.activated_at } : old);
      queryClient.invalidateQueries({ queryKey: productKeys.lists(), refetchType: "inactive" });
    });

    channel.listen(".product.reorder_point_breached", (e: ReorderBreachedEvent) => {
      // Patches the Inventory tab's rollup if open; the List's own KPI strip (# Layout & Regions)
      // is only marked stale, matching the non-disruptive realtime-list pattern (# Edge Cases).
      queryClient.invalidateQueries({ queryKey: productKeys.detail(e.product_id) });
      queryClient.invalidateQueries({ queryKey: productKeys.lists(), refetchType: "inactive" });
    });

    for (const event of [".product.price_suggestion_ready", ".product.duplicate_detected", ".product.discontinued"]) {
      channel.listen(event, (e: { product_id: number }) => {
        queryClient.invalidateQueries({ queryKey: productKeys.detail(e.product_id) });
        queryClient.invalidateQueries({ queryKey: productKeys.lists(), refetchType: "inactive" });
      });
    }

    return () => { echo.leave(`company.${companyId}.products`); };
  }, [companyId, queryClient]);
}
```

## AI agents feeding this screen

Per `docs/accounting/PRODUCTS.md → AI Responsibilities`, seven agent behaviors surface here, each
through the ordinary read/write API — never a bespoke AI endpoint this screen special-cases:

| Agent | What appears on this screen | Autonomy |
|---|---|---|
| General Accountant / Document AI | Suggested `category_id`, `brand`, `product_type`, `tax_category_id` on a new/imported draft | Auto-applied ≥0.85 confidence on draft-only, human-untouched fields; suggest-only below |
| Auditor | Duplicate-candidate pairs on the Duplicates tab and the List's inline `⚠` glyph | Suggest-only, always — exact-barcode matches escalate as high-priority |
| Forecast Agent | The Inventory tab's demand-forecast curve (informational) | Auto-generate, informational only — no action here |
| Inventory Manager (AI) | Suggested `reorder_point`/`reorder_quantity` on the Inventory tab; a linked draft Purchase Request when stock is projected to breach zero | Suggest-only; the draft PR lands in Purchasing's own queue, not this screen |
| CFO (AI) / Reporting Agent | Pending rows on the Pricing tab's suggestion queue (`ProductPriceSuggestionCard`) | Strictly suggest-only, gated `products.pricing.approve`, floor/ceiling-bounded |
| Reporting Agent | Bundle-composition suggestions (`AIProposalPanel`, surfaced from the List's overflow or a dedicated notification, not a persistent tab) | Suggest-only; accepting hands off to the normal `products.create` flow |
| Document AI | The List's search box's semantic-plus-full-text ranking | Fully automated, read-only |

# Interactions & Flows

**Creating a manual product.** From the List, "New Product" (gated `products.create`) opens `/new`.
`ProductForm` mounts with `mode="create"`, `status` implicitly `draft`, and only the Identity section
expanded (name_en/name_ar — at least one required by `productSchema`, mirroring
`ck_products_name_present` — and `product_type`, required to unlock the rest of the form's conditional
sections, `# Components Used`). SKU can be typed or generated: an "Auto-generate" toggle beside the SKU
field calls a lightweight preview (not a write) showing the pattern `{category_code}-{sequence}` the
instant a category is selected, matching `ProductService::generateSku()`; leaving it off requires a
manual, uniqueness-checked SKU (debounced availability check against `GET /products?sku=...`, purely a
UX nicety — the authoritative uniqueness check is still the server's partial unique index at save time).

**Filling category, unit, and stock/costing.** Selecting a category via `CategoryPicker` pre-fills the
form's default account-mapping and tax-category fields from that category's own defaults
(`default_revenue_account_id`, etc.) as a one-time convenience default — the instant the user touches any
of those fields directly, the pre-fill is treated as an explicit user value, never silently re-applied
if the category changes again afterward, matching the backend's "a product's own mapping, once
explicitly set, always wins over the category default" rule. For a stocked type (`# Components Used`'s
`useVisibleSections`), the Stock & Costing section requires `costing_method` and shows Min/Max/Reorder
fields; a non-stocked type hides the section entirely and the form's schema drops those fields from its
required set rather than merely hiding them visually.

**Attaching a barcode.** `BarcodeManager` inside the Overview section (or reached directly from
`BarcodeLookupField`'s "create from this code" handoff, `# Layout & Regions`) lets a user add one or more
`product_barcodes` rows: a symbology `Select` (`ean13`/`ean8`/`upc_a`/`upc_e`/`code128`/`code39`/`qr`/
`itf14`/`gs1_128`/`internal`), the value itself, and an `is_primary` radio (exactly one primary,
enforced client-side by disabling the toggle on every other row the instant one is set, mirroring the
backend's partial unique index). A `qr` row additionally reveals a `raw_payload` textarea for the full
decoded string. Submitting calls `POST /products/{id}/barcodes` per row; a `409` (the barcode value
already belongs to another product in this company) surfaces inline against that row specifically,
never as a page-level toast that leaves the user unsure which row failed.

**Activating a product.** The Page Header's "Activate" (or the Composer's primary button on `/new`)
opens a lightweight confirming `Dialog`, then calls `POST /products/{id}/activate`. A validation failure
returns the exact shape `docs/accounting/PRODUCTS.md → API → Example 2` documents:

```json
{
  "success": false,
  "data": null,
  "message": "Product cannot be activated: readiness checks failed.",
  "errors": [
    { "code": "NO_ACTIVE_PRICE", "field": "default_selling_price", "message": "No active price found on any sales price list." },
    { "code": "MISSING_ACCOUNT_MAPPING", "field": "inventory_account_id", "message": "Stocked product types require an inventory account before activation." }
  ]
}
```

`useApiToast().fromApiError()` maps each `errors[].field` onto `form.setError(field, ...)` when the form
is open (edit mode), scrolling to and focusing the first invalid field, exactly like a normal `422` on
save — activation's readiness check is rendered with the same mechanism as any other server validation,
never a special-cased error UI.

**Variants.** The Variants tab's "Add variant" opens an inline row: an `attributes` key/value builder
(free-form pairs like `color: Red`, `size: XL`, stored as `product_variants.attributes JSONB`), a
suggested `variant_sku` (parent SKU + a short attribute-derived suffix, editable), and cost/selling
**price deltas** (offsets from the parent's default prices, not absolute prices — matching
`cost_price_delta`/`selling_price_delta` in the schema) so a size/color upcharge is visibly relative to
the base product rather than a second, easily-desynced absolute price.

**Reviewing an AI price suggestion.** The Pricing tab's suggestion queue renders one
`ProductPriceSuggestionCard` per pending `product_price_suggestions` row: current vs. suggested price
(`AmountCell` pair), expected margin/volume delta, `ConfidenceBadge` with the full `reasoning` in its
tooltip, and the configured `floor_price`/`ceiling_price` band shown as a small range beneath the
suggested figure so an approver can see at a glance that the suggestion respects the company's
guardrails. Approve (`products.pricing.approve`) writes straight to `price_list_items` and appends a
`product_price_history` row with `changed_by_ai: true`; Reject dismisses it (optimistic, `## Mutations`).
Neither action is reversible from this card once submitted — Approve's confirming `Dialog` restates the
new price before it fires, per Principle 10.

**Duplicate resolution.** The Duplicates tab lists each `product_duplicate_candidates` pair as a
`DuplicateCandidateCard`: both products' thumbnail/SKU/name side by side, the similarity score
(`ConfidenceBadge`), and which signal drove the match (embedding similarity, fuzzy SKU/name match, or an
exact shared barcode — the last rendered in a `danger`-toned "Data integrity: identical barcode" banner
rather than the ordinary `ai-accent` tone, since the backend treats this case as high-priority). Three
actions: **Merge** (gated `products.merge`, opens a `Dialog` requiring the user to explicitly pick the
surviving `product_id` — never an implicit "first one wins" default — and restates, in the confirmation
copy, that invoice/bill/inventory/price-list/stock-movement references on the non-surviving product will
be re-pointed and it will be soft-deleted); **Keep Both** (dismisses this specific candidate pair
without affecting either product); **Not a Duplicate** (dismisses and is recorded, so the Auditor agent's
future re-scans do not keep re-flagging the same pair).

**Discontinue / Reactivate / Archive.** Discontinue needs no guard condition (any `active` product) and
shows a brief, non-blocking notice about what remains true afterward — existing open documents still
honor it, it drops out of new-document pickers. Archive is only offered once the product is
`discontinued`, and its confirming `Dialog` proactively shows *why* it either can or cannot proceed yet
(current stock across warehouses, count of open documents, days remaining in the retention window) —
never a bare disabled button with no explanation, matching `# Accessibility`'s RBAC-vs-business-rule
distinction.

**Bulk operations.** Selecting rows and choosing "Adjust price %" or "Re-category" from the Bulk Action
Bar opens a small `Dialog` collecting the operation's parameters, then calls `POST /products/bulk` once
against the current filter set (`docs/accounting/PRODUCTS.md → API → Example 5`) — a single request, not
N per-row calls, since a catalog-wide adjustment is naturally one filtered operation rather than N
independent lifecycle decisions. The response's `matched`/`updated`/`price_history_rows_created` counts
render as a results toast.

**Import.** "Import" opens a `Dialog` accepting a CSV/XLSX/JSON file, a column-mapping step, and an
"AI-classify unmapped fields" toggle (`docs/accounting/PRODUCTS.md → API → Example 4`'s
`ai_classify_unmapped_fields` option) — when enabled, any column the user didn't explicitly map (e.g. a
supplier catalog's free-text category names) is resolved through the same classification agent that
handles single-product creation, at the same confidence thresholds. The response's
`created`/`updated`/`skipped`/`ai_classification_applied`/`errors_file_url` shape renders as a results
summary with a downloadable errors file, mirroring `JOURNAL_ENTRIES.md`'s bulk-import UX exactly.

# AI Integration

AI touches this screen at exactly four points, and — per the platform's non-negotiable rule — none of
them ever writes a price, merges a product, or changes an account mapping without a human holding the
specific gated permission deciding first. There is no "AI tab"; every one of the four is a rendering of
an existing tab or field, never a parallel surface.

**1. Classification, at creation and import time.** The General Accountant / Document AI agent proposes
`category_id`, `brand`, `product_type`, and `tax_category_id` for a brand-new `draft` — typed manually or
produced by an import row — from its name/description/image/spec-sheet input. Below 0.85 confidence,
the suggestion sits in the `product_classification_suggestions` staging queue, surfaced as an inline,
dismissible suggestion chip beside each affected field ("AI suggests: Electronics › TVs — 71%. Use
this?"), never auto-applied. At or above 0.85 confidence, **and only while the product is still a
first-time `draft` with that field still unset by a human**, the value is pre-filled directly into the
form and the Summary Rail's provenance block appears immediately — this is the one auto-apply path this
screen has, and it is narrowly scoped to catalog-hygiene fields on an as-yet-untouched draft, never to a
value a human has already typed over, and never to price, account mapping, or lifecycle state. Every
auto-applied value is logged to `audit_logs` with `changed_by_ai: true`, so it is fully reversible and
fully visible on the History tab even though no approval gate blocked it in the first place — visible,
not silent, is the operative distinction from a gated action, not "no oversight at all."

**2. Duplicate Detection.** Covered in full under `# Interactions & Flows`. The Auditor agent re-scans
the whole active catalog nightly and on every new product creation; its output is always a candidate
*pair*, never an automatic merge, because — per `docs/accounting/PRODUCTS.md` — "merging two products is
destructive to historical reporting granularity and can never be AI-autonomous." The one asymmetry this
screen renders explicitly is exact-barcode duplication: two distinct products sharing one
`product_barcodes.barcode_value` is a data-integrity error, not a heuristic guess, and is styled with
the platform's `danger` tone rather than the `ai-accent` tone every other AI surface on this screen uses
— a deliberate signal that this specific finding is a near-certain fact, not a probabilistic suggestion.

**3. Price Optimization.** The CFO agent (in coordination with the Reporting Agent) proposes price
changes, always bounded by the company's configured `floor_price`/`ceiling_price` per category — a
suggestion outside those bounds is rejected by the backend's own `FormRequest` before it is even
persisted to the staging table, so this screen never has to defensively re-check a bound the server
already refused to violate. `product_price_suggestions.confidence_score` is stored as a
`NUMERIC(4,3)` between 0 and 1 — already a fraction, exactly like `journal_lines.ai_confidence` — so
every `ConfidenceBadge` here calls `normalizeConfidence(suggestion.confidence_score, 'fraction')`, never
`'percentage'` (reserved for the AI Command Center's separately-scaled `ai_decisions.confidence_score`,
0–100). Passing the wrong mode would silently render a 92%-confident suggestion as "1% confidence" — the
identical caution `JOURNAL_ENTRIES.md` calls out for its own AI confidence fields, restated here because
it is exactly as easy a mistake to make on this screen.

**4. Bundle Suggestions.** The Reporting Agent's market-basket analysis over co-purchased
`invoice_items` proposes a new `bundle` product with a pre-filled `product_bundle_items` composition.
This is the one AI output on this screen shaped like an AI Command Center recommendation rather than a
staging-table row tied to an existing record, so it renders through `AIProposalPanel` exactly as
documented in `COMPONENT_LIBRARY.md`: a recommended action ("Bundle these 3 frequently co-purchased
SKUs into a new 'Home Theater Starter Kit' at a 5% combined discount"), at least one named alternative
("Keep selling separately — no bundling"), and three affordances — Send for approval (routes to a
`products.create`-gated user, who reviews and, if accepted, is handed straight into `/new` pre-filled
from the suggestion, never a separate "AI bundle creation" endpoint), and Dismiss (reason required).
`AIProposalPanel`'s `autonomyLevel` for this agent is always `"suggest_only"` — the "Do it" one-click
affordance is structurally absent, since creating a new catalog record is never something this screen
lets an AI output execute by itself.

**Smart search, read-only.** The List's search box combines classic full-text (`ix_products_search`)
with semantic nearest-neighbor search over `products.embedding` (`ix_products_embedding`, `pgvector`
HNSW), so a misspelled query or an Arabic-typed query against an English-named product still surfaces
the right row. This is fully automated and carries no approval affordance at all — search never mutates
data, so `# AI Integration`'s human-gate rule simply does not apply to it; the only "AI responsibility"
here is the embedding-maintenance background job, which is invisible housekeeping, not a rendered
decision.

**What never appears here.** The Demand Forecast Agent's curve (Inventory tab) and the Recommendation
Engine's "customers who bought this also bought" hints are both informational, computed live, and
carry no persisted staging row or approval affordance of their own — they inform a Sales or Purchasing
screen's upsell/replenishment prompts, not an action on this screen. Rendering them here is read-only
context, never a decision this screen asks the viewer to make.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | List: `Skeleton` rows shaped like the table; Detail: header + rail skeleton |
| Empty (no products at all) | Brand-new company, zero `products` rows | `EmptyState`: "No products yet," primary CTA "Add your first product" (`.create`), secondary "Import your catalog" (`.import`) |
| Empty (filtered to nothing) | Filters legitimately produce zero rows | Lighter `EmptyState` variant, "No products match your filters," never conflated with the true-empty case |
| Draft | `status: "draft"` | `ProductForm` fully editable; Activate disabled with an inline reason until readiness is met |
| Active | `status: "active"` | `ProductOverviewRead` by default; Edit available; SKU/costing-method/unit fields lock individually once `has_posted_transactions`/`has_stock_movements` is true on the resource |
| Discontinued | `status: "discontinued"` | Read view with a calm, non-alarming notice ("No longer available for new orders; existing documents unaffected"); Reactivate and (once eligible) Archive offered |
| Archived | `status: "archived"` | Fully read-only; "Correct data" replaces "Edit" (`# Layout & Regions`); excluded from the List's default Status filter but reachable by explicitly selecting it |
| Activation blocked | `422` from `POST .../activate` | Inline, field-mapped errors (`NO_ACTIVE_PRICE`, `MISSING_ACCOUNT_MAPPING`, …) exactly as returned, never a generic "cannot activate" message |
| SKU/costing/unit change blocked | `409` (`SKU_IMMUTABLE_AFTER_TRANSACTION`, `COSTING_METHOD_LOCKED_WITH_STOCK`, unit-change rejection) | The specific field renders `disabled` with an inline reason proactively (from `has_posted_transactions`/`has_stock_movements`), so the 409 is rarely even reached — see `# Edge Cases` for the residual race |
| Archive blocked | `409` from `POST .../archive` | Confirming dialog shows the exact blocking facts (remaining stock by warehouse, open document count, days left in the retention window) before the user even attempts the call |
| Duplicate suspected (inline) | `GET /products/{id}/duplicates` returns ≥1 row | `⚠` glyph on the List row; the Duplicates tab renders instead of being hidden |
| Exact-barcode duplicate (high priority) | A duplicate candidate whose match reason is a shared `product_barcodes.barcode_value` | `danger`-toned banner, not the ordinary `ai-accent` duplicate styling (`# AI Integration`) |
| Version conflict | `409` on a concurrent `PATCH` | Toast: "This product was changed by someone else — reload and retry"; unsaved local edits remain in the form until the user chooses to reload |
| Merge completed | `POST /products/merge` succeeds | Redirect to the surviving product's Detail; a one-time banner notes the merge and links the (now soft-deleted) source SKU for reference |
| Import job running / completed / partial | `useImportJobStatus` polling | A `Dialog`-hosted progress state, then the created/updated/skipped/errors-file results summary |
| Bulk operation result | Response from `POST /products/bulk` | Toast with matched/updated counts; any per-row failures (rare — a filtered bulk op is usually all-or-nothing per the operation type) listed in an expandable detail |
| Session permission revoked mid-view | A subsequent mutation's `403` | The affected control collapses to its disabled-with-tooltip form; nothing trusts the page's initial-load permission snapshot |
| Error | `403` / `404` / `5xx` / network failure | Shared `403` boundary, `not-found.tsx`, or `ErrorState` with retry, per which failure occurred |

# Responsive Behavior

**List, Mobile (`base`–`sm`).** Per `RESPONSIVE_DESIGN.md → Adaptive Patterns → Pattern 1`, rows become
cards headed by the primary image thumbnail, SKU, and `StatusPill`, with a two-column definition list of
category, type, and a single Price figure (`AmountCell`) — the Stock rollup is shown only for stocked
types and omitted entirely (not a dash) for services/digital/subscription rows, since a dash for every
non-stocked row across a long mobile scroll is noise, not information. `BarcodeLookupField` is promoted
to a persistent, thumb-reachable button in the mobile Filter Bar (opening a full-width scan/entry sheet)
rather than an inline text field, matching this screen's barcode-first workflow. The general Status/
Category/Type/Brand filters collapse behind a single badge-counted "Filters" trigger opening a `Sheet`.

**List, Tablet (`md`–`lg`).** The card layout persists through `md`; from `lg` the table itself renders
with the Stock and Price columns visible and the image thumbnail shrunk, matching
`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`'s general table-reflow guidance.

**Composer/Form, Mobile (`base`–`sm`).** Unlike `JournalEntryForm`'s flatter shape, `ProductForm` has
eight potential sections (Identity, Category, Unit & Stocking, Pricing, Account Mapping, Tax, Images,
Custom Fields) — rendering all of them expanded on a small screen would make the form scroll
punishingly long. Below `md:`, sections render as a single-open `Accordion` (Identity expanded by
default, every other section collapsed but reachable), each header showing a small completeness
indicator (a filled vs. outline dot) so a user can see at a glance which sections still need attention
without expanding each one. The sticky footer (Cancel / Save Draft / Activate) persists across every
section per `LAYOUT_SYSTEM.md → Form Page Template`.

**Composer/Form, Tablet (`md`–`lg`).** All sections render expanded in a single column, no accordion —
tablet width comfortably fits one section's fields without the accordion's added interaction cost.

**Composer/Form, Desktop (`xl`+).** The full two-region Form Page Template: Form Body plus a Live Help
Rail showing the classification AI suggestion card (when present), the category's inherited
default-mapping preview, and — once at least one price-list row exists — a small price-history
sparkline.

**Detail, all tiers.** The Summary Rail moves above the Tab/Segment Nav on Mobile/Tablet and returns to
a true 4/12 rail from `lg:` up, per `LAYOUT_SYSTEM.md`'s Detail Page Template reflow. `ProductVariantsTable`
and `ProductPricingTab`'s price-list rows use the sticky-first-column, horizontal-scroll treatment
`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` specifies, rather than a card transform — both
are homogeneous, narrow-enough tabular data (a handful of columns) where a scrollable table reads faster
than a stack of repeating cards for the typical 2–15 row count these sub-views hold.

**Virtualization.** The List's `DataTable` virtualizes past ~200 rows via `@tanstack/react-virtual`,
identical to every other `DataTable` instance in the platform (`RESPONSIVE_DESIGN.md → Virtualization at
scale`); `CategoryPicker`'s dropdown list virtualizes past ~150 categories for the same reason.

**Touch targets.** Every interactive element — the barcode scan button, row-action icons, the mobile
accordion headers, the primary/remove-image controls in `ProductImageUploader` — meets the platform's
44×44px minimum regardless of density (`LAYOUT_SYSTEM.md → Density Modes`).

# RTL & Localization

Every string on this screen ships as an EN/AR pair through the platform's typed dictionaries, and the
screen inherits `THEMING.md`'s RTL-as-a-theming-dimension and `LAYOUT_SYSTEM.md → RTL Layout Mirroring`
without a per-component RTL branch, plus several module-specific applications of those rules:

- **Bilingual product identity is the rule, never the exception.** `name_ar`/`name_en`,
  `description`/`description_ar`, and `product_categories.name_ar`/`name_en` all render the active
  locale's field and fall back to the other only when it is genuinely blank — never the reverse. A
  product with only `name_en` populated under an Arabic session shows a small "Arabic name missing"
  indicator in `ProductForm` (surfaced to AI for a backfill suggestion, per
  `docs/accounting/PRODUCTS.md → Product Profile`), never a silently-blank name.
- **SKU, barcode values, model numbers, and category codes stay LTR-embedded** inside Arabic sentences
  (a duplicate-suspected banner's narrative, the "AI suggests: ELC-000042" chip, the readiness-error
  text) via the shared `Bidi`/`LtrInline` wrapper (`unicode-bidi: isolate` + `dir="ltr"`), exactly as
  `JOURNAL_ENTRIES.md` handles journal numbers and confidence percentages — so `"6291041500213"` or
  `"92%"` never reorders inside a right-to-left paragraph.
- **Money and quantity columns are always `text-end` in both directions** — `AmountCell`'s own
  `align="end"` default, applied uniformly to Price, Cost, and the Inventory tab's on-hand/available
  figures. Unlike `JOURNAL_ENTRIES.md`'s Debit-before-Credit exception, **this screen's table columns
  mirror normally under RTL** — there is no universal accounting convention forcing SKU/Category/Type/
  Stock/Price into one physical order the way debit-before-credit is fixed platform-wide, so
  `DataTable`'s ordinary logical-property mirroring (`LAYOUT_SYSTEM.md → RTL Layout Mirroring → Rule 1`)
  applies here without a documented exception, and the column order simply reverses along with every
  other RTL layout on the platform.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching
  `AmountCell`'s implementation and the platform-wide rule that a mixed numeral system inside a single
  catalog row reads as an error to an accountant.
- **Category breadcrumbs mirror direction.** `CategoryPicker`'s "TVs › Electronics" trail renders with
  its separator glyph mirrored (`ChevronLeft` vs. `ChevronRight`, resolved from `dir`) but its text order
  unchanged (parent-to-child reads in the same logical sequence regardless of direction — only the
  visual chevron flips, per `ICONOGRAPHY.md`'s directional-icon table).
- **Product type, lifecycle, and barcode-symbology vocabulary is translated precisely, not
  transliterated**, throughout this screen and its `next-intl` dictionaries: منتج مادي (physical
  product), خدمة (service), منتج رقمي (digital product), اشتراك (subscription), حزمة (bundle), مادة خام
  (raw material), منتج نهائي (finished goods), منتج نصف مصنع (semi-finished goods), أصل (asset), بند
  مصروف (expense item); مسودة (draft), نشط (active), متوقف (discontinued), مؤرشف (archived);
  الباركود (barcode), رمز الاستجابة السريعة (QR code), الرقم التسلسلي (serial number), رقم التشغيلة
  (batch number), قائمة الأسعار (price list), المتغيرات (variants), الفئة (category), وحدة القياس
  (unit of measure).
- **Image alt text is bilingual by construction.** `ProductImageUploader` requires (or AI-suggests, per
  `# AI Integration`) both `alt_text_en` and `alt_text_ar` in an image's `attachments.metadata`, rendered
  to screen readers in the viewer's active locale — never machine-translated on the fly.
- **AI reasoning renders in the viewer's session locale**, generated server-side, not machine-translated
  client-side, per `FRONTEND_ARCHITECTURE.md → AI Integration Layer`'s identical rule for every other AI
  surface on the platform.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a bare Tailwind palette utility outside the shared component files it
composes.

- **Surfaces and elevation.** The List and Detail canvases sit on `--surface-canvas`; every `Card`
  section in `ProductForm` and the Detail's Summary Rail sit on `--surface-base`; the Barcode/Merge/
  Import `Dialog`s, the AI reasoning `Sheet`, and `CategoryPicker`'s `Popover` sit on
  `--surface-overlay`. Elevation steps lighter toward the viewer in dark mode (canvas → base → raised →
  overlay), and every container carries its paired `--border-subtle` even where the light-mode
  equivalent relies on shadow alone — a borderless dark `Card` disappears against a near-black canvas,
  the single most common dark-mode regression this screen must avoid, exactly as `JOURNAL_ENTRIES.md`
  notes for its own Composer cards.
- **`StatusPill domain="product"`** is a new lookup table this document adds to the shared
  `STATUS_TABLES` registry (`COMPONENT_LIBRARY.md → StatusPill`), reasoned from
  `docs/accounting/PRODUCTS.md → Product Lifecycle`'s own description of each state:

  | Status | Label | Tone | Rationale |
  |---|---|---|---|
  | `draft` | Draft | `neutral` | Not yet transactable; matches Journal Entries' own `draft` tone |
  | `active` | Active | `success` | "The normal operating state" — the backend's own words for it |
  | `discontinued` | Discontinued | `warning` | Transitional/caution, not a failure — existing obligations still honored, matching the calm, non-alarming tone the backend's description itself uses |
  | `archived` | Archived | `neutral` | Terminal and hidden by default, not a negative outcome — mirrors Journal Entries' `archived` tone |

  Each tone resolves through `DARK_MODE.md`'s semantic tokens (`--status-success`/`--status-warning`)
  plus `--accent` where relevant, independently contrast-checked in both themes per
  `DARK_MODE.md → Color & Contrast In Dark` — a pairing is never assumed to pass in dark mode because
  its light-mode counterpart passed.
- **Cost and price figures are never status-colored.** Every `AmountCell` on this screen keeps its
  ink/text-primary tone in both themes; only `StatusPill`, `ConfidenceBadge`, and a low-stock indicator
  on the Inventory tab carry semantic status color. A low `quantity_available` crossing below
  `reorder_point` is a `warning`-toned badge on the *stock number*, never a recoloring of the *price* —
  a product is not "cheap" or "expensive" in a good/bad sense, and the UI never implies otherwise through
  color, mirroring `JOURNAL_ENTRIES.md`'s identical debit/credit-are-not-good-or-bad rule.
- **AI provenance uses one reserved hue, never a finance-status hue.** The classification confidence
  badge, the price-suggestion queue's accent border, the bundle-suggestion `AIProposalPanel`, and every
  `ConfidenceBadge` on this screen use `--ai-accent`/`--ai-accent-subtle` exclusively, in both themes —
  never `--status-success`/`--status-warning`/`--status-error`. The one deliberate exception is the
  exact-barcode duplicate banner (`# AI Integration`), which is intentionally rendered in
  `--status-danger` rather than `--ai-accent` precisely because it represents a near-certain data
  defect, not a probabilistic AI opinion — the two hues are never conflated, and this is the one place on
  this screen where an AI-originated finding is deliberately *not* styled in the AI-provenance hue.
- **Product imagery gets a neutral mounting surface in dark mode.** A supplier-provided product photo is
  very often a white-background JPEG; placing it directly on a near-black `--surface-canvas` produces a
  jarring bright rectangle. `ProductImageUploader`'s thumbnail and the Detail Summary Rail's primary
  image both render inside a fixed, theme-independent light-neutral mat (a small `--surface-raised`-toned
  card with a 1px `--border-subtle` and rounded corners) rather than bleeding edge-to-edge against the
  canvas — the photo's own background is never forced transparent or color-matched, since QAYD does not
  control the source image, but the frame around it is designed for this exact common case.
- **Contrast.** Every pairing above is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text,
  borders, focus rings) in both themes, per `DARK_MODE.md → Color & Contrast In Dark`.
- **Exports render light, always.** A PDF/XLSX/CSV export of the catalog or a single product — regardless
  of the exporting user's active theme — renders in QAYD's fixed light/print palette
  (`DARK_MODE.md → Exported PDFs always render light`), since a catalog export is routinely shared with a
  supplier or auditor who has never opened the dark-mode app.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented without deviation, plus the
screen-specific applications below.

- **Only the plain, accessible `<table>` pattern is used on this screen — never the ARIA `grid`
  pattern.** `ACCESSIBILITY.md → Data Tables Accessibility` names exactly two surfaces in the platform
  that warrant the heavier `grid` pattern (the Journal Entry line editor and the Bank Reconciliation
  matching grid); neither `ProductVariantsTable` nor `ProductPricingTab`'s price-list rows is a third.
  Both are genuinely row-oriented, Tab-to-move editing over a handful of rows, not a wide
  spreadsheet-style entry surface a user needs arrow-key cell navigation to traverse efficiently — real
  `scope="col"` headers, `<button>` sort controls with `aria-sort`, and ordinary `Tab` order are the
  correct, and sufficient, pattern here. Reaching for the grid pattern "to be safe" is the exact
  anti-pattern `ACCESSIBILITY.md` warns against.
- **The barcode scanner always has a visible manual-entry fallback.** `BarcodeLookupField` and
  `BarcodeManager` never require camera access to function — a user without a camera, without granted
  permission, or who simply prefers typing can always type a code directly into the same field a scanner
  would populate. A successful lookup announces via `role="status" aria-live="polite"` ("Product found:
  Samsung 55 inch QLED 4K TV"); a failed lookup escalates to the same live region rather than a silent
  no-op.
- **Image upload always has a non-drag path.** `ProductImageUploader`'s drag-and-drop zone is
  accompanied by a real, keyboard-operable "Choose files" `Button` opening the native file picker — drag
  is never the only way to attach an image, per `ACCESSIBILITY.md → Forms Accessibility`'s general rule
  against interaction-exclusive affordances.
- **RBAC-disabled and business-rule-disabled controls explain themselves, and never identically.** A
  "Save" disabled because the viewer lacks `products.account_mapping.update` carries an
  `aria-describedby` naming the exact missing permission; the SKU field disabled because
  `has_posted_transactions` is true carries a different, specific `aria-describedby` ("This SKU can't be
  changed because it's already been used on a posted transaction") — the two are never collapsed into a
  generic "You can't do this right now," per `ACCESSIBILITY.md`'s explicit rule that conflating them is a
  P1 defect.
- **A sensitive action with no permission is omitted, not disabled.** Merge, Archive, Delete, Import, and
  Export are removed from the DOM entirely for a role lacking the corresponding permission, never
  rendered disabled with no explanation — a disabled control with no visible content behind it leaves a
  screen-reader user unable to tell whether the capability exists at all.
- **Confidence and duplicate-similarity are never color-only.** Every `ConfidenceBadge` renders its
  percentage as a real text node beside the pill; the exact-barcode duplicate banner pairs its `danger`
  tone with an explicit "Data integrity issue" label, never relying on red alone.
- **Focus management on overlays.** The AI reasoning `Sheet`'s `onOpenAutoFocus` moves focus to its
  heading; closing any `Dialog`/`Sheet`/`AlertDialog` on this screen returns focus to the control that
  opened it. The mobile Accordion's auto-expand-on-validation-error (`# Interactions & Flows`) moves
  focus to the newly-revealed section's first invalid field, never leaving focus stranded on a
  now-collapsed header.
- **Keyboard.** `G` `P` (`# Route & Access`) opens this screen; `N` opens the Composer; `/` focuses
  search and `B` focuses the barcode field; inside `CategoryPicker`/`UnitOfMeasurePicker`, arrow keys
  move the `Command` list and typeahead jumps to a matching prefix, identical to `AccountPicker`'s
  existing keyboard model.
- **Realtime updates never yank focus or splice silently.** A `.product.reorder_point_breached` push
  affecting a row an Inventory Manager is currently reading updates that row's cached data without
  reordering the list or moving keyboard focus, per the platform-wide realtime-accessibility rule.

# Performance

- **Server-paginated, never client-sorted or client-filtered.** The List's `DataTable` issues a new
  request for every sort, filter, or page change — a company with tens of thousands of SKUs never ships
  more than one page's worth of rows to the browser at once.
- **The barcode lookup path is treated as a latency-critical surface, not an ordinary cached read.**
  `useProductByBarcode` sets `staleTime: 0` and issues no client-side debounce beyond waiting for the
  scanner's terminating `Enter` — matching the backend's own framing of `GET /products/barcode/{code}`
  as an O(1) index lookup expected to complete "well under 150ms server-side." The frontend's only
  performance obligation here is to add negligible overhead on top of that budget: no intermediate
  loading skeleton delay threshold, no batching, a single `fetch` per scan.
- **Virtualization past ~200 rows**, on both the List and `CategoryPicker`'s dropdown (`# Responsive
  Behavior`), using `@tanstack/react-virtual` with a density- or breakpoint-aware row-height estimate.
- **Cache tuning matches data volatility**, per `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`:
  the List is the 30-second transactional-list tier; categories/units/accounts/tax codes/vendors are the
  5-minute reference tier; barcode lookups are an always-fresh, uncached tier of their own (above); a
  single open Detail record additionally receives surgical realtime patches (`# Data & State`) so it is
  never meaningfully stale between polls.
- **Debounced, cancelled reference-data search.** `CategoryPicker` and `UnitOfMeasurePicker` debounce
  250ms and are only `enabled` while their `Popover` is open, identical to `AccountPicker`'s own
  contract, so a large category tree never fires a request per keystroke.
- **Idempotent, single-attempt mutations.** Every write endpoint this screen calls (`create`, `activate`,
  `discontinue`, `archive`, `merge`, `import`, price-suggestion `approve`) carries a client-generated
  `Idempotency-Key` scoped to one logical submission attempt, so a slow response on a flaky connection
  followed by an impatient second tap can never double-create, double-activate, or double-merge a
  product.
- **Async, non-blocking import/export.** `POST /products/import` and `GET /products/export` are async
  jobs the frontend polls/subscribes to rather than holding a request open; a 5,400-row import never
  blocks the UI thread or the tab.
- **Deferred, code-split overlays.** The Import `Dialog`, the Merge confirmation, the AI reasoning
  `Sheet`, and the duplicate-candidate detail are dynamically imported (`next/dynamic`) rather than
  bundled into the List's or Detail's initial route chunk.
- **Images are optimized, not embedded raw.** `ProductImageUploader`'s thumbnails and the Detail's
  primary image render through `next/image` with responsive `sizes`, lazy-loaded below the fold in the
  Variants/Gallery sub-views; the List's row thumbnail requests the smallest server-generated variant
  the design calls for, never the full-resolution upload.
- **Embedding-based search is entirely server-side.** No product embedding is computed, cached, or
  compared client-side; `GET /products/search` returns a ranked result set the frontend renders as-is.
- **SSR-seeded first paint.** Both the List's first page and a Detail route's initial fetch happen
  server-side and hydrate straight into the TanStack Query cache, so the client's first `useQuery` for
  the same keys resolves instantly rather than re-issuing a request the server already made.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| SKU edit attempted on a product that has since transacted (race between page load and a transaction posting elsewhere) | The field was rendered enabled based on a stale `has_posted_transactions: false`; the save returns `409 SKU_IMMUTABLE_AFTER_TRANSACTION`, and the field locks in place with its explanatory reason, matching the "server is the only authoritative rejection" principle |
| Costing method change attempted while stock exists | `409 COSTING_METHOD_LOCKED_WITH_STOCK`; the field locks with a reason naming the blocking stock quantity, no override offered anywhere in this UI |
| Unit of measure change attempted with movement history | `409`, no override; the inline reason explains that changing the base unit after stock has moved would corrupt historical valuation, matching the backend's own stated rationale verbatim in the copy |
| Two users edit the same product concurrently | `409` toast, "reload and retry"; the second writer's in-progress local edits are not silently discarded |
| Activating a `bundle` with zero `product_bundle_items` rows | `422` naming the Bundle Composition section specifically; the Variants/Bundle tab is auto-focused |
| Activating a `subscription` with no `recurrence_unit`/`recurrence_interval` | `422` naming the Recurrence fields; both are required together, never independently |
| Archiving attempted with nonzero stock in any warehouse | `409`; the confirming dialog shows the exact remaining quantity per warehouse before the call is even made, so this is rarely surfaced as a raw error |
| Hard delete attempted on a product with any transaction history | `409 PRODUCT_HAS_TRANSACTIONS`; the UI explains the record was soft-deleted (discontinued path) instead, and offers Discontinue/Archive as the intended next step rather than a dead-end error |
| Merge selected on a pair where neither side is clearly "correct" | The Merge dialog requires an explicit choice of surviving `product_id` — there is no default/preselected side, so a user cannot accidentally confirm a merge without deciding which SKU wins |
| A price suggestion's 14-day `expires_at` passes while still `pending` | The card renders in a muted, `expired` visual state with Approve/Reject both disabled and a short "This suggestion expired" caption, rather than silently disappearing from the queue |
| An AI classification suggestion is applied, then the user manually changes the field again afterward | The manual change is the final word; the AI provenance badge is removed from that specific field (though the original suggestion remains visible in History), since the field is no longer AI-sourced |
| A category referenced by existing products is deactivated (`status: 'inactive'`) | Existing products keep the reference with a small "Category inactive" label; `CategoryPicker` excludes it from selection for any *new* assignment going forward, matching the same non-retroactive pattern `JOURNAL_ENTRIES.md` uses for a deactivated account |
| A serialized product's sale requires exact serial-count matching at the point of sale | Out of this screen's scope by design (Sales/POS enforces it) — this screen only shows `is_serialized` and a read-only registered-serials count with a link into the serials list, never a sale-time selection UI |
| A batch-tracked product's batch nears expiry | Surfaced as a read-only badge on the Inventory tab linking into the Inventory module's own FEFO tooling — this screen does not own expiry-driven issuing logic |
| Company switch mid-`ProductForm` with unsaved changes | Per the platform's company-switch contract, the switcher's confirmation dialog names the in-progress draft specifically before discarding it, rather than a generic "unsaved changes" warning |
| Browser back/forward or a hard navigation away from a dirty form | A navigation guard prompts to discard, matching Cancel's own confirm-if-dirty behavior |
| Realtime push arrives while a user is actively scrolling or reading the List | Held aside behind a dismissible "New activity — Refresh" banner rather than spliced into visible rows, per the platform-wide non-disruptive-realtime-list pattern; the cached page is only marked stale, never silently replaced under the user's scroll position |
| Reconnecting after an extended disconnect | Every realtime-fed query key for this screen is invalidated once on reconnect, since WebSocket frames broadcast while disconnected are permanently missed |
| A product referenced by an already-posted invoice line has its name/category/tax changed later | The posted `invoice_items` row's own snapshot (`product_name_en`, `sku`, `tax_rate`, taken at post time) is entirely unaffected — this screen's edit never retroactively alters a historical document, mirroring the platform's posted-record immutability rule |
| An account referenced by a product's mapping is later deactivated | The product's existing mapping is unaffected and still displays exactly as set; only the *live* `AccountPicker` used for a new mapping selection excludes the now-inactive account going forward |
| A KPI strip (total active SKUs, low-stock count, pending price suggestions) would render on a role that cannot see one of its constituent numbers | The individual tile is omitted, not shown as a zero or a lock icon — consistent with `NAVIGATION_SYSTEM.md`'s "hide, always, when the answer is this role never touches this" rule; the strip itself is omitted entirely if every tile would be hidden |

# End of Document
