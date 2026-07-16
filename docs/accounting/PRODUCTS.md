# Product Management (Item Master) — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Products (Item Master)
---

# Purpose

The Products module is the item master of QAYD. It is the single, authoritative catalog of every
tradeable, consumable, or billable "thing" a company transacts against: physical goods, services,
digital products, subscriptions, bundles, raw materials, semi-finished goods, finished goods,
capitalizable assets below the fixed-asset threshold, and generic expense line items. Every other
transactional module — Sales (`sales_quotations`, `sales_orders`, `invoices`, `invoice_items`),
Purchasing (`purchase_requests`, `purchase_orders`, `bills`, `bill_items`), Inventory
(`inventory_items`, `stock_movements`, `stock_adjustments`), and Accounting (`journal_lines` via
posted revenue/COGS/inventory entries) — references a `product_id` rather than duplicating product
attributes. Products is the upstream dependency for all of these; it owns no transactional ledger
data itself, but it owns the master data that transactional documents pull pricing, tax treatment,
unit of measure, account mapping, and identity (SKU/barcode/serial/batch) from.

Concretely, the Products module is responsible for:

1. **Identity** — assigning and enforcing uniqueness of SKU, barcode(s), QR payload, and (where
   applicable) serial numbers and batch/lot numbers, per company.
2. **Classification** — categorizing products into a hierarchical category tree, tagging them with
   brand/model/attributes, and typing them (`product_type` enum) so downstream modules know how to
   treat them (e.g., a `service` never touches inventory; a `raw_material` only appears in
   manufacturing/BOM contexts, not direct sale).
3. **Costing and pricing policy** — declaring the costing method (FIFO or Weighted Average) that
   Inventory's valuation engine must use, the default cost price, and the price lists that Sales
   and Purchasing consult to resolve a selling or buying price for any customer/vendor/currency/date
   combination.
4. **Account mapping** — declaring which Chart of Accounts accounts (`accounts.id`) receive the
   automatic journal entries generated when this product is sold, purchased, received into stock,
   or consumed as COGS, and which tax code applies by default.
5. **Variant and unit-of-measure modeling** — supporting products that vary by size/color/etc.
   (`product_variants`) and quantities expressed in multiple interoperable units
   (`units_of_measure`, `unit_conversions`), e.g. selling in "box" while stocking in "piece."
6. **AI-assisted catalog hygiene** — classification, duplicate detection, demand forecasting, price
   optimization, and smart search/recommendation, always operating through the same Laravel API and
   permission model as a human user, never writing to the database directly.

Products does **not** track quantity on hand — that is Inventory's `inventory_items` table. Products
does **not** post journal entries — that is Accounting's `journal_entries`/`journal_lines`, triggered
by domain events that Sales/Purchasing/Inventory emit when they consume a product's account mapping.
This separation of concerns (item master vs. stock ledger vs. general ledger) is deliberate and is
enforced throughout this document: a product can exist with zero inventory records (e.g., before its
first purchase), and an `inventory_items` row can never exist without a valid, non-deleted
`product_id` foreign key.

# Vision

QAYD's Products module is designed to behave like the item master inside SAP S/4HANA's Material
Master or Oracle Fusion's Item Master — a single normalized source of truth that every downstream
process consumes without re-entering data — but built for a Gulf-first, AI-native, multi-tenant SaaS
company rather than an on-premise enterprise deployment. Three principles guide every design decision
in this document:

- **One product, one truth, many contexts.** A product's identity (SKU, name, category, unit,
  account mapping) is defined once. Its price varies by price list (retail, wholesale, VIP,
  vendor-specific); its cost varies by warehouse and costing method; its stock varies by warehouse
  and batch/serial. The Products table itself never stores a "the" price or "the" quantity — those
  live in `price_list_items` and `inventory_items` respectively, both foreign-keyed to `products`.
- **AI accelerates data entry and catalog quality without owning correctness.** A company onboarding
  5,000 SKUs from a spreadsheet or a photo of a supplier catalog should be able to lean on OCR and
  classification AI to pre-fill category, unit, and even suggested pricing — but every AI suggestion
  is a draft with a confidence score that a human (or, for low-risk fields, a policy threshold)
  approves before it is committed as fact.
- **Bilingual and Gulf-first by construction, not as an afterthought.** Every product-facing text
  field ships with `name_en` and `name_ar` from day one; every currency-facing field defaults to the
  company's base currency (KWD in the reference deployment) while supporting AED/SAR/USD; every
  numeric field uses the platform's fixed precision (`NUMERIC(19,4)` for money, `NUMERIC(18,4)` for
  quantities) so that rounding never silently corrupts inventory valuation or margin reporting.

The long-term vision is a catalog that scales from a five-SKU boutique to a multi-branch distributor
with 200,000 SKUs, serialized electronics, batch-tracked pharmaceuticals, and multi-level bundles,
without ever requiring a schema migration to add a "new kind" of product — because `product_type`,
JSONB `custom_fields`, and the variant/unit/price-list model already cover the shape of that growth.

# Product Lifecycle

Every product record moves through exactly one lifecycle state machine, stored in
`products.status`. The states are `draft`, `active`, `discontinued`, and `archived`. Transitions are
one-directional except for the explicit re-activation path from `discontinued` back to `active`
(e.g., a seasonal SKU returning to the catalog). `archived` is terminal — an archived product can
never be un-archived; a new product record must be created if the item returns to the catalog.

```
   ┌────────┐   activate()    ┌────────┐  discontinue()  ┌──────────────┐   archive()   ┌──────────┐
   │ draft  │ ───────────────▶│ active │ ───────────────▶│ discontinued │──────────────▶│ archived │
   └────────┘                 └────────┘                 └──────────────┘               └──────────┘
        │                          ▲                             │
        │        delete()          │        reactivate()         │
        └──────────(hard delete    └─────────────────────────────┘
                    only if never
                    transacted)
```

**Draft.** The initial state for every product created via the API or AI-assisted import. A draft
product is fully editable, is visible only to users with `products.read` inside the creating company,
and is **excluded** from all Sales/Purchasing pickers, POS search, and the public catalog API. Draft
products may lack a finalized cost/price, account mapping, or barcode — the only mandatory fields at
draft creation are `company_id`, `name_en` (or `name_ar`), `product_type`, and `unit_of_measure_id`.
A product cannot leave `draft` for `active` until validation in the "Validation Rules" analogue below
(see Business Rules embedded in each section) passes: it must have a non-null `sku`, a costing method,
a default selling price on at least one active price list, and (for physical/finished/raw/semi-finished
types) a mapped inventory account.

**Active.** The normal operating state. Active products are transactable: they can appear on sales
orders, invoices, purchase orders, bills, POS screens, and inventory documents. Only active products
are indexed into the AI smart-search vector store (see AI Responsibilities). Price changes, category
re-assignment, and stock movements are all permitted freely while active. A product remains active
indefinitely unless explicitly discontinued or archived — there is no automatic expiry.

**Discontinued.** Set manually (or by the AI's slow-moving/dead-stock recommendation, always
requiring human approval — see AI Responsibilities) when the company stops buying/making a product
but existing stock or open documents referencing it must still be honored. A discontinued product:
- **cannot** be added to *new* sales orders, purchase orders, or quotations (409 Conflict returned by
  the API, see API Endpoints/Edge Cases);
- **can** still be fulfilled on invoices/bills/deliveries that reference it from *before* the
  discontinue date, and can still be adjusted, transferred, or counted in Inventory (its stock does
  not vanish);
- **can** be reactivated back to `active` at any time by a user holding `products.update`, which is
  logged to `audit_logs` with `action = 'product.reactivated'`.

**Archived.** The terminal state, reserved for products that have zero on-hand stock across all
warehouses (`SUM(inventory_items.quantity_on_hand) = 0`), zero open (non-fully-delivered/non-fully-
paid) sales or purchase documents referencing them, and have been discontinued for at least the
company's configured retention window (default 365 days, configurable per company in
`companies.settings->>'product_archive_after_days'`). Archiving is a **soft** operation
(`deleted_at IS NULL`, `status = 'archived'` — archived products are not soft-deleted; soft-delete is
reserved for erroneous records). Archived products:
- disappear from every UI list and picker by default, but remain queryable via
  `GET /api/v1/products?status=archived` for historical reporting (profitability, ABC/XYZ analysis
  over closed periods still needs them);
- **cannot** be edited except by an Owner/Admin correcting a data-entry error via a dedicated
  `PATCH /api/v1/products/{id}/unarchive-for-correction` endpoint, which forces the record back to
  `discontinued` for the edit and re-archives it on save;
- are excluded from AI classification/forecasting inputs going forward, but remain in historical
  training data for demand forecasting seasonality models.

**Hard delete** is permitted **only** for products that have never appeared on any posted
transaction (no `invoice_items`, `bill_items`, `stock_movements`, `journal_lines` reference them) —
this is the only place in the entire platform where a `products` row can be physically removed rather
than soft-deleted, because an untransacted draft carries no financial history to preserve. The
Service layer (`ProductService::delete()`) enforces this by running an existence check across all
five referencing tables inside a transaction before issuing the `DELETE`; if any reference exists, it
falls back to a soft delete (`deleted_at = now()`) and returns `409 Conflict` with
`errors: [{"code":"PRODUCT_HAS_TRANSACTIONS","message":"..."}]` explaining that the caller should
discontinue or archive instead.

| From state | Event | To state | Guard condition | Who can trigger |
|---|---|---|---|---|
| (none) | `create()` | `draft` | company_id present, product_type valid | `products.create` |
| `draft` | `activate()` | `active` | sku unique, unit set, ≥1 price, account mapping set (for stocked types) | `products.update` |
| `active` | `discontinue()` | `discontinued` | none (any active product) | `products.update`, or AI suggestion + approval |
| `discontinued` | `reactivate()` | `active` | sku still unique (not reused elsewhere) | `products.update` |
| `discontinued` | `archive()` | `archived` | zero stock, zero open documents, ≥ retention window elapsed | `products.archive` (Admin/Owner) |
| `draft`/`discontinued` | `delete()` | (removed) | never transacted (checked across all modules) | `products.delete` (Admin/Owner) |

# Product Types

`products.product_type` is a fixed PostgreSQL enum (`product_type_enum`) that determines which
downstream behaviors apply. This is the single most important field on the record because it gates
inventory tracking, account mapping requirements, and eligibility for sales/purchase documents.

| Type | Tracked in Inventory? | Sellable? | Purchasable? | Typical account mapping | Notes |
|---|---|---|---|---|---|
| `physical_product` | Yes | Yes | Yes | Inventory (asset), COGS, Revenue | Standard stocked SKU; costing method mandatory |
| `service` | No | Yes | Rarely (subcontracted services can be purchased) | Revenue (no inventory/COGS accounts) | No quantity_on_hand ever created; billed by hours/units of effort |
| `digital_product` | No | Yes | Rarely | Revenue; optional deferred-revenue account for license terms | License keys tracked via `product_serials` (repurposed as license keys) without physical stock |
| `subscription` | No | Yes | No | Revenue (recognized per period), Deferred Revenue | Drives `sales_orders`/`invoices` with `recurrence` metadata; see Sales Integration |
| `bundle` | Indirectly (via components) | Yes | No (bundles are assembled, not bought) | Revenue at bundle level; COGS is the sum of component COGS | Component list in `product_bundle_items`; stock is validated against component availability, not a bundle-level `inventory_items` row |
| `raw_material` | Yes | No (not directly sold) | Yes | Inventory (asset), no Revenue account | Consumed by manufacturing/BOM processes (Manufacturing module, out of scope here) |
| `finished_goods` | Yes | Yes | No (produced, not purchased) — exception: also purchasable if company resells manufactured-elsewhere goods | Inventory (asset), COGS, Revenue | Output of a production order; costing method mandatory |
| `semi_finished_goods` | Yes | No (intermediate) | Rarely | Inventory (asset), no Revenue account (internal transfer only) | Exists between raw material and finished goods in a BOM chain |
| `asset` | No (not stock-tracked; tracked by Fixed Assets module if capitalized) | No | Yes (as a purchase, e.g., office equipment) | Fixed Asset account (if capitalized) or Expense account (if expensed below capitalization threshold) | Bridges Products and a future Fixed Assets module; below-threshold assets post straight to expense |
| `expense_item` | No | No | Yes | Expense account only | Generic non-stock purchase line (e.g., "Office Supplies", "Courier Fee") used on `bill_items` when no real product exists |

Business rules enforced by `ProductService` per type:

- **`physical_product`, `finished_goods`, `semi_finished_goods`, `raw_material`** (collectively the
  "stocked types") **require** a non-null `costing_method` (`fifo` or `weighted_average`), a non-null
  `inventory_account_id`, and a non-null `unit_of_measure_id` before they can transition to `active`.
  Attempting to activate without these returns `422` with field-level errors.
- **`service`, `digital_product`, `subscription`, `expense_item`, `asset`** **must not** set
  `inventory_account_id`, `cogs_account_id`, or `costing_method` — the API rejects (`422`,
  `NON_STOCKED_TYPE_CANNOT_HAVE_INVENTORY_ACCOUNT`) any attempt to set these fields on a non-stocked
  type, preventing Inventory from ever creating a phantom `inventory_items` row for a service.
- **`bundle`** requires at least one row in `product_bundle_items` before activation, and its
  `selling_price` (if fixed rather than sum-of-components) must be explicitly flagged via
  `products.bundle_pricing_mode` (`fixed` | `sum_of_components`).
- **`subscription`** requires `recurrence_unit` (`day`|`week`|`month`|`year`) and
  `recurrence_interval` (integer ≥ 1) to be set — these drive the Sales module's recurring invoice
  generator.
- **`asset`** requires a `capitalization_threshold_currency` comparison at purchase time (handled by
  Purchasing/Accounting, not Products): if the bill line amount exceeds the company's fixed-asset
  threshold (`companies.settings->>'asset_capitalization_threshold'`), Purchasing posts to the Fixed
  Asset account; otherwise it posts straight to the mapped Expense account. Products only supplies the
  default account suggestion; the actual posting decision is Accounting's.

Every product type shares the same `products` table (single-table, discriminated by
`product_type`) rather than per-type tables, because the overwhelming majority of columns (name, SKU,
category, brand, tags, images, status, timestamps) are common, and type-specific optionality is
enforced at the application layer via `CHECK` constraints and FormRequest validation rather than
schema fragmentation. This mirrors how SAP's Material Master and NetSuite's Item record use a single
polymorphic entity with a "type" discriminator rather than separate tables per material type.

# Product Profile

The Product Profile is the set of descriptive and identifying attributes carried on every
`products` row (plus the child tables for multi-valued identifiers). This section defines the
meaning, format, and validation rule for each field. Full column types appear in Database Design;
this section is the business-facing contract.

**SKU (Stock Keeping Unit).** `products.sku VARCHAR(64) NOT NULL`. Unique per `company_id` (partial
unique index, soft-delete aware — see Database Design). SKUs are either user-supplied at creation or
auto-generated by `ProductService::generateSku()` using the pattern
`{category_code}-{sequence:6}` (e.g., `ELC-000042` for the 42nd product under category `ELC`). Once
a product has ever appeared on a posted transaction, its SKU becomes **immutable** — an attempt to
`PATCH` the SKU on a transacted product returns `409 Conflict` (`SKU_IMMUTABLE_AFTER_TRANSACTION`).
Draft, never-transacted products may have their SKU changed freely.

**Barcode.** Products may carry **multiple** barcodes (a physical product often has a manufacturer
barcode, a company-relabeled barcode, and a case/carton barcode). These live in the child table
`product_barcodes`, one row per symbology instance, with a `barcode_type` enum
(`ean13`|`ean8`|`upc_a`|`upc_e`|`code128`|`code39`|`qr`|`itf14`|`gs1_128`|`internal`) and a
`is_primary` flag (exactly one primary barcode per product, enforced by a partial unique index).
Barcode values are globally unique **within a company** (not globally across companies — two
tenants may legitimately stock the same manufacturer barcode). Barcode lookups are the highest-QPS
read path in the module (POS scanning) and are covered by a dedicated index and a dedicated API
endpoint (`GET /api/v1/products/barcode/{code}`, see API section) that bypasses full-text search
entirely for an O(1) index lookup.

**QR Code.** QR is modeled as `barcode_type = 'qr'` inside `product_barcodes` rather than a separate
table — a QR code is functionally a barcode with a different symbology and typically encodes a URL or
a structured payload (`{"sku":"ELC-000042","batch":"B2026-07","company":"..."}`) rather than a plain
number. The `product_barcodes.raw_payload TEXT` column stores the full decoded string for QR (where
`barcode_value` alone would truncate a URL); `barcode_value` stores a normalized/hashed short form for
indexing and exact-match lookup.

**Serial Number.** For products where every physical unit must be individually traceable (high-value
electronics, warrantied equipment, license keys for `digital_product`), each unit is a row in
`product_serials`: `product_id`, `serial_number` (unique per company), `status`
(`in_stock`|`reserved`|`sold`|`returned`|`defective`|`revoked`), `warehouse_id`, `batch_id` (nullable —
a serialized unit can still belong to a batch), and `sold_on_document_type`/`sold_on_document_id`
(polymorphic pointer to the `invoice_items` row that sold it). `products.is_serialized BOOLEAN` gates
whether Sales/Inventory require a serial selection at the point of sale/receipt — if true, an
`invoice_items` row for this product cannot be posted without exactly `quantity` serial numbers
attached (enforced by a Service-layer check, not a DB trigger, so the error message stays
user-friendly).

**Batch Number.** For products tracked by lot/batch (pharma, food, cosmetics — expiry-sensitive
goods), `product_batches` holds `product_id`, `batch_number` (unique per product per company),
`manufacture_date`, `expiry_date`, `quantity_received`, `quantity_remaining`, `warehouse_id`, and
`vendor_id` (traceability back to the supplier lot). `products.is_batch_tracked BOOLEAN` gates the
same kind of enforcement as serialization: stock movements against a batch-tracked product must
specify a `batch_id`, and FEFO (First-Expiry-First-Out) issuing logic in Inventory (owned by the
Inventory module doc, not repeated here) reads `product_batches.expiry_date` to pick which batch to
consume first.

**Product Name / Arabic Name / English Name.** Per the platform's bilingual convention,
`products.name_en VARCHAR(255)` and `products.name_ar VARCHAR(255)` are both present; at least one is
required (a `CHECK` constraint enforces `name_en IS NOT NULL OR name_ar IS NOT NULL`), and the UI
falls back to whichever is populated when the other is blank, with a "translation missing" badge
surfaced to AI for backfill suggestion (see AI Responsibilities). There is no generic "Product Name"
column separate from these two — "Product Name" in the section heading refers to this bilingual pair
collectively, matching the platform-wide `name_en`/`name_ar` convention, not a third redundant field.

**Description.** `products.description TEXT` (English) and `products.description_ar TEXT` (Arabic),
both nullable, sanitized rich text (HTML or Markdown per `companies.settings->>'description_format'`).
Descriptions feed the AI smart-search embedding and any future customer-facing catalog surface but
never feed financial documents — `invoice_items` snapshots `product_name_en`/`product_name_ar` at time
of sale independent of later description edits, per the platform's immutability rule for posted
records.

**Category.** `products.category_id BIGINT REFERENCES product_categories(id)`, nullable at `draft`
but required to activate. `product_categories` is a self-referencing tree (`parent_id`), unlimited
depth, with a materialized `path` column (e.g., `'/1/14/57'`) maintained by the Service layer on every
insert/move for O(1) ancestor/descendant queries without recursive CTEs on the hot path (recursive
CTEs remain available for admin tree-editing UI, just not for the per-request "products in category X
and its descendants" queries). Each category carries its own default account mapping
(`default_revenue_account_id`, `default_expense_account_id`, `default_cogs_account_id`,
`default_tax_category_id`) that a new product inherits at creation time as a convenience default — a
product's own mapping, once explicitly set, always wins over the category default.

**Brand.** `products.brand VARCHAR(120)`, free text, with AI-assisted normalization (the AI Duplicate
Detection agent flags `"Samsung"` vs `"SAMSUNG"` vs `"samsung electronics"` as likely the same brand
and suggests a canonical spelling — see AI Responsibilities) rather than a separate `brands` table in
v1, because Gulf SME catalogs skew toward long-tail, inconsistent brand naming that a rigid FK would
force into constant admin upkeep. A normalized `brands` lookup table is listed under Future
Improvements once catalog volume justifies the migration cost.

**Model.** `products.model_number VARCHAR(120)`, free text, typically the manufacturer's model/part
number, distinct from the company's own `sku`.

**Unit.** `products.unit_of_measure_id BIGINT NOT NULL REFERENCES units_of_measure(id)` — the
**stocking/base** unit (the unit `inventory_items.quantity_on_hand` is expressed in). A product may
additionally be *sold* or *purchased* in a different unit (e.g., stocked in `piece`, sold in `box of
12`) — that is resolved via `unit_conversions` and a `unit_of_measure_id` override column on
`sales_order_items`/`purchase_order_items`, converted back to the base unit for inventory posting.
See Database Design for the `units_of_measure`/`unit_conversions` DDL.

**Weight.** `products.weight NUMERIC(12,4)` and `products.weight_unit VARCHAR(10)`
(`kg`|`g`|`lb`|`oz`), used for shipping calculation and, for `raw_material`/`finished_goods` in
weight-based industries (food, chemicals), as an alternate quantity dimension reconciled against
`unit_conversions`.

**Dimensions.** `products.length_cm`, `products.width_cm`, `products.height_cm`, all
`NUMERIC(10,2)`, nullable, used for shipping/logistics and warehouse slotting (bin-size compatibility
checks live in the Warehouses extension tables, out of scope for this document).

**Images.** Stored via the foundation polymorphic `attachments` table
(`attachable_type = 'product'`, `attachable_id = products.id`), never as a column on `products`
itself. `attachments.metadata JSONB` carries
`{"role":"primary"|"gallery","sort_order":n,"alt_text_en":"...","alt_text_ar":"..."}`. Exactly one
attachment per product may have `metadata->>'role' = 'primary'`, enforced at the Service layer (since
`attachments` is a shared foundation table this module does not own and cannot add a DB constraint
to).

**Attachments.** Non-image files (spec sheets, MSDS/safety data sheets, certificates of origin,
supplier catalogs) use the same `attachments` table with `metadata->>'role' = 'document'` and a
`metadata->>'document_type'` sub-classification (`spec_sheet`|`certificate`|`msds`|`manual`|`other`).

**Tags.** `products.tags JSONB NOT NULL DEFAULT '[]'`, an array of free-text strings
(`["seasonal","eco","best-seller"]`) used for filtering, AI-assisted merchandising, and promotional
targeting. GIN-indexed (`jsonb_path_ops`) for containment queries (`tags @> '["seasonal"]'`).

**Custom Fields.** `products.custom_fields JSONB NOT NULL DEFAULT '{}'`, an open schema-less bag for
company-specific attributes that don't warrant a first-class column (e.g., a furniture retailer's
`{"material":"oak","finish":"matte"}` or a pharmacy's `{"controlled_substance_schedule":"II"}`).
Custom field *definitions* (label, data type, required-on-activate flag, applicable category) are
declared per company in a lightweight `product_custom_field_definitions` table (see Database Design)
so the frontend can render appropriate input widgets and validation can enforce required custom
fields without hardcoding them into a PHP FormRequest.

# Pricing

Pricing in QAYD is deliberately **not** a single column on `products`. A product's cost and selling
price both vary by context — warehouse (cost, via costing method), currency, customer tier
(wholesale vs. retail), date (promotions, price history), and even AI-recommended dynamic adjustment.
The `products` table stores only a **default** cost and a **default** selling price as convenience
fields for the simplest case (a single-price SME); everything beyond that is resolved through
`price_lists` and `price_list_items`.

**Cost Price.** `products.default_cost_price NUMERIC(19,4) NOT NULL DEFAULT 0` is the standard/last
cost used as a fallback whenever Inventory's costing engine has no better data yet (e.g., a brand
new product before its first Goods Receipt). Once at least one `stock_movements` receipt exists, the
authoritative cost for valuation purposes comes from the costing method (FIFO layers or weighted
average, computed in Inventory/`inventory_valuations`, **not** stored redundantly on `products`).
`products.default_cost_price` is updated automatically after every Goods Receipt to reflect the
latest purchase cost (last-cost tracking), purely as a UI convenience and as the seed value for the
weighted-average recalculation — it is never itself the number used in a posted COGS journal entry
for FIFO-costed products.

**Selling Price.** `products.default_selling_price NUMERIC(19,4) NOT NULL DEFAULT 0` is the
fallback retail price used when no `price_list_items` row matches the sale's price list, currency,
and date. In practice, every company is seeded with a `Standard Retail` price list at onboarding, and
`default_selling_price` is kept in sync with that list's item for the product's base unit — but the
column exists independently so that quoting a price never requires a join in the degenerate
single-price case.

**Wholesale / Retail.** Modeled as two (or more) distinct `price_lists` rows scoped to the same
company: a `Standard Retail` list (`price_list_type = 'sales'`, `customer_tier = 'retail'`) and a
`Wholesale` list (`customer_tier = 'wholesale'`), each with its own `price_list_items` rows per
product. A customer's `customers.default_price_list_id` determines which list Sales resolves against
by default; a specific `sales_order_items` line can override the resolved price list explicitly
(with a permission check — see Permissions, `sales.override_price`).

**Discount Rules.** Modeled via the `discounts` table (owned by the Sales module's extended canonical
tables) referencing `product_id`, `category_id`, or `brand` as its applicability scope, with
`discount_type` (`percentage`|`fixed_amount`), `min_quantity`, `valid_from`/`valid_to`, and
`requires_approval_above` (a percentage ceiling above which the discount needs Sales Manager
sign-off). Products contributes only the scoping columns (`category_id`, `brand`) that Discounts
join against; the discount rule engine itself is Sales' responsibility.

**Promotions.** Modeled via `promotions` (Sales-owned), which can reference a specific `product_id`
list, a `category_id`, or `coupon_code` via `coupons`. Products emits a `product.price_changed`
domain event whenever `default_selling_price` or an active `price_list_items` row changes, which
Promotions listens to in order to invalidate any promotion whose discount was computed against a
stale base price.

**Dynamic Pricing.** The AI Price Optimization agent (see AI Responsibilities) writes **proposed**
price changes to a `product_price_suggestions` staging table (never directly to `price_list_items`),
each row carrying `suggested_price`, `current_price`, `confidence_score NUMERIC(4,3)`, `reasoning
TEXT`, `expected_margin_delta`, and `status` (`pending`|`approved`|`rejected`|`expired`). A human with
`products.pricing.approve` reviews the suggestion queue; on approval, `ProductService::applyPriceSuggestion()`
writes the new price into `price_list_items` and records the change in `product_price_history` (below)
with `changed_by_ai = true` and a pointer back to the suggestion row. Dynamic pricing is
**suggest-only** in v1 — full autonomous repricing is a Future Improvement gated behind a company-level
opt-in and hard min/max guardrails (`product_price_suggestions.floor_price`/`ceiling_price`).

**Price History.** Every accepted change to any `price_list_items.price` or
`products.default_selling_price`/`default_cost_price` is appended (never updated) to
`product_price_history`: `product_id`, `price_list_id` (nullable, null = default price change),
`old_price`, `new_price`, `currency_code`, `effective_from`, `changed_by` (`user_id`, nullable if
system/AI), `changed_by_ai BOOLEAN`, `reason TEXT`, `created_at`. This is the basis for the Price
Optimization AI's training signal (what price changes historically correlated with margin/volume
outcomes) and for the "Price History" tab a Finance user sees on a product's detail page. Unlike
`audit_logs` (which is generic and covers every table), `product_price_history` is a
purpose-built, densely-indexed table because price-change queries ("show me this product's price
every quarter for 3 years") are frequent enough in Sales/Finance workflows to deserve a dedicated,
narrow, query-optimized shape rather than filtering the generic audit log.

Price resolution at the moment of sale follows this deterministic precedence, executed by
`PriceResolutionService::resolve(product, customer, currency, quantity, date)`:

1. An explicit manual override on the document line (if the user has `sales.override_price`).
2. The most specific active `price_list_items` row: customer-specific price list >
   customer-tier price list (wholesale/retail) > company's default sales price list.
3. Within the resolved price list, the row with the highest `min_quantity` that is `<= requested
   quantity` (quantity-break pricing) and whose `valid_from`/`valid_to` window contains `date`.
4. Currency conversion, if the resolved row's `currency_code` differs from the document's currency,
   using the exchange rate service (same mechanism as multi-currency invoices platform-wide).
5. Fallback to `products.default_selling_price` in the company's base currency if no
   `price_list_items` row matches at all.

The identical precedence chain, substituting `price_list_type = 'purchase'` and vendor for customer,
resolves **vendor pricing** for Purchasing (see Purchasing Integration).

# Inventory Integration

Products and Inventory are strictly separated: **Products defines what can be stocked and how it is
valued; Inventory tracks how much of it exists, where.** No inventory quantity, warehouse location,
or stock movement is ever stored on the `products` table. The boundary is enforced by a single rule:
`inventory_items` (owned by the Inventory module) has `product_id BIGINT NOT NULL REFERENCES
products(id)`, and Inventory code never writes to `products` except through the two Products-exposed
service methods described below.

**Warehouses.** A stocked product (see Product Types) can have zero, one, or many `inventory_items`
rows — one per (`product_id`, `warehouse_id`, and, if serialized/batch-tracked, per
`serial_id`/`batch_id`). Products does not restrict which warehouses a product may be stocked in;
any warehouse-level restriction (e.g., "this hazardous chemical may only be stored in warehouse
`WH-CHEM`") is expressed via `products.custom_fields->>'allowed_warehouse_ids'` and enforced by
Inventory's receiving/transfer validation, not by a Products-owned constraint.

**Stock / Reserved Stock / Available Stock.** These three numbers are **Inventory's**
responsibility, computed from `inventory_items.quantity_on_hand`, `stock_reservations` (rows tied to
open, unfulfilled `sales_order_items`), and the derived `quantity_available = quantity_on_hand -
quantity_reserved`. Products exposes a **read-only, denormalized rollup** for UI convenience —
`GET /api/v1/products/{id}` includes an `inventory_summary` object aggregated live (not cached
on `products`) at read time:
```json
"inventory_summary": {
  "total_on_hand": 480.0000,
  "total_reserved": 65.0000,
  "total_available": 415.0000,
  "by_warehouse": [
    {"warehouse_id": 3, "warehouse_name_en": "Main Warehouse", "on_hand": 300.0000, "reserved": 40.0000},
    {"warehouse_id": 7, "warehouse_name_en": "Salmiya Branch", "on_hand": 180.0000, "reserved": 25.0000}
  ]
}
```
This aggregation query is deliberately excluded from the `products` table's own columns to avoid a
denormalized number silently drifting from the true stock ledger; it is always computed fresh
(optionally Redis-cached for 30 seconds under high read load — see Performance).

**Minimum Stock / Maximum Stock / Reorder Point.** These are **planning parameters that Products
owns**, because they are properties of the item's replenishment policy, not of any single
warehouse's current count (a company may set a single reorder point per product, or override it per
warehouse). Modeled as `products.min_stock_level`, `products.max_stock_level`, and
`products.reorder_point`, all `NUMERIC(18,4)`, all in the product's base unit, all nullable
(no policy = no automatic reorder suggestion). Optionally overridden per warehouse via
`product_warehouse_settings (product_id, warehouse_id, min_stock_level, max_stock_level,
reorder_point, reorder_quantity)` — a narrow join table owned by Products because it is a
*policy* table, not a *stock* table (it has no `quantity_on_hand`). Inventory's low-stock/reorder
job reads these thresholds and, when a product's `quantity_available` crosses below its (warehouse-
specific or global) `reorder_point`, emits a `product.reorder_point_breached` domain event that both
Inventory (dashboard alert) and the AI Stock Prediction agent consume.

**Transfers.** Stock transfers between warehouses (`stock_transfers`/`stock_transfer_items`) reference
`product_id` for identity and quantity/unit resolution only; Products has no transfer-approval logic
of its own. Products does enforce one constraint at the API boundary: a `discontinued` or `archived`
product cannot be the target of a *new* transfer request creation (`422`,
`PRODUCT_NOT_TRANSFERABLE`), though an already-open transfer may still complete.

**Adjustments.** `stock_adjustments` (Inventory-owned) post inventory value changes (shrinkage,
damage, recount corrections) through `journal_lines` using **Products' account mapping** —
specifically `products.inventory_account_id` (debit/credit target) and a company-level default
"Inventory Shrinkage" expense account for the offsetting side. Products' only involvement is
supplying that account mapping; the adjustment approval workflow, quantity math, and journal posting
are entirely within Inventory's and Accounting's domains.

The domain events Products emits that Inventory subscribes to: `product.activated` (Inventory may
now accept receipts against it), `product.costing_method_changed` (Inventory must NOT allow this
change if `inventory_items.quantity_on_hand > 0` for that product anywhere — enforced by Inventory
rejecting the change request that Products forwards, returning `409
COSTING_METHOD_LOCKED_WITH_STOCK`), `product.discontinued`, `product.unit_of_measure_changed`
(blocked entirely if any `inventory_items` or `stock_movements` history exists for the product —
changing the base unit after stock has moved would silently corrupt historical valuation, so this is
a hard `409` with no override).

# Accounting Integration

Every stocked or revenue/expense-generating product carries an **account mapping** — a set of
foreign keys into the Chart of Accounts (`accounts.id`) that downstream modules use to build the
`journal_lines` of an automatic posting, without ever hardcoding an account number in Sales,
Purchasing, or Inventory code. This is the mechanism by which "sell a product" becomes "debit
Accounts Receivable / credit Revenue, debit COGS / credit Inventory" without a human choosing
accounts on every single invoice line.

| Column on `products` | Points to | Used when | Debit or Credit side |
|---|---|---|---|
| `revenue_account_id` | `accounts.id` (type = revenue) | An `invoice_items` line for this product is posted | Credit (revenue recognized) |
| `expense_account_id` | `accounts.id` (type = expense) | A `bill_items` line for this product is posted (non-stocked types: `service`, `expense_item`, `asset` below threshold) | Debit (expense recognized) |
| `inventory_account_id` | `accounts.id` (type = asset) | A Goods Receipt increases stock, or a sale decreases it | Debit on receipt, credit on issue |
| `cogs_account_id` | `accounts.id` (type = expense) | An `invoice_items` line for a stocked type is posted (cost side of the sale) | Debit (cost of goods sold recognized) |
| `tax_category_id` | `tax_codes.id` | Any sale/purchase line for this product, to resolve the applicable `tax_rates` row | N/A (drives `tax_transactions`, not itself a GL account) |

If a product's own mapping column is `NULL`, resolution falls back to its `product_categories`
default mapping (`default_revenue_account_id`, etc.); if the category also has no mapping, resolution
falls back to the company-level default accounts configured in
`companies.settings->>'default_accounts'`. If **none** of the three levels resolve a required
account for the transaction being posted, the posting is rejected with `422`
(`ACCOUNT_MAPPING_UNRESOLVED`) rather than silently posting to a wrong or blank account — QAYD never
guesses a financial account.

**Worked example — selling one unit of a `physical_product`:**

```
Product: "Samsung 55\" QLED TV" (SKU ELC-000042)
  revenue_account_id      -> 4001 "Sales Revenue - Electronics"
  cogs_account_id         -> 5001 "Cost of Goods Sold - Electronics"
  inventory_account_id    -> 1301 "Inventory - Electronics"
  tax_category_id         -> VAT-STANDARD (0% in Kuwait; 15% if company operates in Saudi Arabia)

Sale of 1 unit at 350.0000 KWD, weighted-average cost 260.0000 KWD:

journal_entries (source_type='invoice', source_id=<invoice_id>, status='posted')
journal_lines:
  1) debit  Accounts Receivable (1101)        350.0000
     credit Sales Revenue - Electronics (4001) 350.0000
  2) debit  Cost of Goods Sold - Electronics (5001) 260.0000
     credit Inventory - Electronics (1301)          260.0000
```

Both lines belong to the **same** `journal_entries` row (one balanced entry per invoice, potentially
multiple `journal_lines` per product line item) so that `SUM(debits) = SUM(credits)` holds at the
entry level as required by the platform's double-entry rule. The COGS line's *credit* amount is
supplied by Inventory's costing engine (FIFO layer cost or current weighted average at the moment of
sale), not by `products.default_cost_price` — Products only supplies the *accounts*, never the
*amounts*, for COGS/inventory postings.

**Tax Category.** `products.tax_category_id BIGINT REFERENCES tax_codes(id)` selects a default tax
treatment (e.g., `VAT-STANDARD`, `VAT-ZERO-RATED`, `VAT-EXEMPT`, `EXCISE-TOBACCO`). The Tax module
resolves the actual `tax_rates` row (rate percentage, effective date range) at the moment of the
transaction from this category, so a single VAT-rate change (e.g., a future Gulf-wide rate change)
never requires touching every product — only the `tax_rates` row tied to the category.

Products never writes to `journal_entries`/`journal_lines` directly. It emits domain events
(`product.sold`, `product.purchased`, `product.stock_adjusted` — technically emitted by Sales/
Purchasing/Inventory respectively, carrying the resolved `product_id` and account mapping snapshot)
that Accounting's posting engine subscribes to. This keeps Products a pure master-data module with
zero ledger-writing responsibility, consistent with the platform's event-driven cross-module
communication rule.

# Purchasing Integration

**Purchase Orders.** `purchase_order_items.product_id` references `products.id`. At PO line creation,
`PriceResolutionService::resolve()` (see Pricing) is invoked with `price_list_type = 'purchase'` and
the selected `vendor_id` to pre-fill `unit_cost`, which the buyer can override (subject to
`purchasing.override_price` permission and a variance-approval threshold if the override exceeds
`companies.settings->>'po_price_variance_approval_pct'`).

**Vendor Pricing.** Modeled identically to sales price lists but with `price_list_type = 'purchase'`
and `vendor_id` instead of `customer_id`/`customer_tier` as the scoping key on `price_list_items`.
A product can have multiple vendors each with their own price, `vendor_sku` (the vendor's own part
number for this product, stored in `price_list_items.vendor_sku`), `min_order_quantity`, and
`lead_time_days`. `products.preferred_vendor_id` (nullable FK to `vendors.id`) records the default
vendor Purchasing pre-selects when creating a new PO line for this product, but any active vendor
price list row remains selectable.

**Lead Time.** `price_list_items.lead_time_days INTEGER` (purchase-type rows only) feeds two
consumers: (1) Purchasing's expected-delivery-date calculation on new POs
(`expected_date = po_date + lead_time_days`), and (2) the AI Stock Prediction agent's reorder-point
calculation, since `reorder_point` should structurally cover expected demand across the lead time
window (`reorder_point ≈ average_daily_demand × lead_time_days × safety_factor`) — Products stores
the *result* (`reorder_point`) as a settable field, while the AI agent computes and *suggests* it
from this same lead-time input among others.

**Receiving.** A `goods_receipts`/`goods_receipt_items` row against a PO line increases
`inventory_items.quantity_on_hand` for the received `product_id`/`warehouse_id`, posts a journal
entry debiting `products.inventory_account_id` and crediting Accounts Payable/GRNI (Goods Received
Not Invoiced), and — for `is_serialized`/`is_batch_tracked` products — requires the receiver to
supply the serial numbers or batch number/expiry being received, creating the corresponding
`product_serials`/`product_batches` rows at that moment. Receiving also triggers
`ProductService::updateLastCost()`, which refreshes `products.default_cost_price` to the newly
received unit cost (last-cost tracking, purely informational for weighted-average/FIFO-costed
products where the real valuation lives in Inventory's cost layers).

**Returns.** Vendor returns (`debit_notes`, Purchasing-owned) reference `product_id` and reduce stock
via a `stock_movements` row of type `purchase_return`, reversing the original receipt's journal
impact proportionally. Products' involvement is limited to supplying the same account mapping used on
the original receipt so the reversal nets to the correct accounts; Products enforces no restriction
on returning a `discontinued` product (returns of no-longer-sold products are common and must not be
blocked).

# Sales Integration

**Invoices / Invoice Items.** `invoice_items.product_id` references `products.id`, but per the
platform's immutability rule for posted financial records, `invoice_items` **snapshots**
`product_name_en`, `product_name_ar`, `sku`, `unit_of_measure_id`, and the resolved
`tax_category_id`/`tax_rate` at post time into its own columns. A later rename, re-categorization, or
tax-category change on the `products` row never retroactively alters a posted invoice — this mirrors
how `journal_lines` never retroactively changes when an `accounts.name` is edited.

**POS.** The point-of-sale search-and-scan flow is the single highest-frequency read path against
Products: barcode scan → `GET /api/v1/products/barcode/{code}` → price resolution → line add, all
expected to complete in well under 150ms server-side (see Performance). POS also enforces the
`is_serialized`/`is_batch_tracked` prompts inline (scan-to-add flow captures the serial/batch at the
point of sale rather than as a separate step).

**Returns.** Customer returns (`credit_notes`, Sales-owned) reference `product_id`, restock via a
`stock_movements` row of type `sales_return` (only if `products.is_returnable_to_stock = true` — a
product flag that lets a company mark certain categories, e.g. hygiene products or opened
perishables, as "return processed but not restocked"), and reverse the proportional revenue/COGS
journal impact.

**Subscriptions.** For `product_type = 'subscription'`, Sales' recurring-billing engine reads
`products.recurrence_unit`/`recurrence_interval` to generate the next `invoices` row on schedule.
Products' `revenue_account_id` for a subscription product typically points to a Deferred Revenue
liability account rather than a straight revenue account when the company's accounting policy
requires period-based recognition (configurable per product via
`products.revenue_recognition_method` enum: `immediate`|`deferred_straight_line`); the actual
recognition-schedule journal entries are generated by Accounting, not Products.

**Projects.** When Sales' project-billing flow bills a product against a `projects` dimension
(e.g., materials billed to a specific construction project), the resolved `project_id` and
`cost_center_id` travel with the `invoice_items`/`journal_lines` row as dimensions (per the shared
design context's dimensions addendum), letting project profitability reports slice by `product_id`
without Products itself needing any project awareness.

# Database Design

All tables below follow the platform-standard tenant columns (`id`, `company_id`, `branch_id`,
`created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) unless explicitly noted as a
system-level (non-tenant) lookup table. Money columns are `NUMERIC(19,4)`; quantities are
`NUMERIC(18,4)`; exchange rates are `NUMERIC(18,6)`. Every tenant table indexes `company_id`.
Soft-deleted rows are excluded from uniqueness via partial indexes (`WHERE deleted_at IS NULL`) so a
deleted SKU can be reused.

```sql
-- ============================================================================
-- ENUM TYPES
-- ============================================================================
CREATE TYPE product_type_enum AS ENUM (
  'physical_product', 'service', 'digital_product', 'subscription', 'bundle',
  'raw_material', 'finished_goods', 'semi_finished_goods', 'asset', 'expense_item'
);

CREATE TYPE product_status_enum AS ENUM ('draft', 'active', 'discontinued', 'archived');

CREATE TYPE costing_method_enum AS ENUM ('fifo', 'weighted_average');

CREATE TYPE barcode_type_enum AS ENUM (
  'ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39', 'qr', 'itf14', 'gs1_128', 'internal'
);

CREATE TYPE serial_status_enum AS ENUM (
  'in_stock', 'reserved', 'sold', 'returned', 'defective', 'revoked'
);

CREATE TYPE price_list_type_enum AS ENUM ('sales', 'purchase');

CREATE TYPE customer_tier_enum AS ENUM ('retail', 'wholesale', 'vip', 'custom');

CREATE TYPE bundle_pricing_mode_enum AS ENUM ('fixed', 'sum_of_components');

CREATE TYPE revenue_recognition_enum AS ENUM ('immediate', 'deferred_straight_line');

CREATE TYPE recurrence_unit_enum AS ENUM ('day', 'week', 'month', 'year');

-- ============================================================================
-- product_categories — hierarchical category tree
-- ============================================================================
CREATE TABLE product_categories (
  id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                  BIGINT NOT NULL REFERENCES companies(id),
  branch_id                   BIGINT NULL REFERENCES branches(id),
  parent_id                   BIGINT NULL REFERENCES product_categories(id),
  code                        VARCHAR(20) NOT NULL,
  name_en                     VARCHAR(160) NOT NULL,
  name_ar                     VARCHAR(160) NULL,
  description                 TEXT NULL,
  path                        VARCHAR(500) NOT NULL DEFAULT '/',   -- materialized ancestor path, e.g. /1/14/57
  depth                       SMALLINT NOT NULL DEFAULT 0,
  default_revenue_account_id  BIGINT NULL REFERENCES accounts(id),
  default_expense_account_id  BIGINT NULL REFERENCES accounts(id),
  default_cogs_account_id     BIGINT NULL REFERENCES accounts(id),
  default_inventory_account_id BIGINT NULL REFERENCES accounts(id),
  default_tax_category_id    BIGINT NULL REFERENCES tax_codes(id),
  image_attachment_id         BIGINT NULL REFERENCES attachments(id),
  sort_order                  INTEGER NOT NULL DEFAULT 0,
  tags                        JSONB NOT NULL DEFAULT '[]',
  custom_fields               JSONB NOT NULL DEFAULT '{}',
  status                      VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
  created_by                  BIGINT NULL REFERENCES users(id),
  updated_by                  BIGINT NULL REFERENCES users(id),
  created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                  TIMESTAMPTZ NULL,
  CONSTRAINT ck_category_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE UNIQUE INDEX ux_product_categories_company_code
  ON product_categories (company_id, code) WHERE deleted_at IS NULL;
CREATE INDEX ix_product_categories_company ON product_categories (company_id);
CREATE INDEX ix_product_categories_parent ON product_categories (parent_id);
CREATE INDEX ix_product_categories_path ON product_categories USING gin (path gin_trgm_ops);

-- ============================================================================
-- units_of_measure — system + company-defined units
-- ============================================================================
CREATE TABLE units_of_measure (
  id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    BIGINT NULL REFERENCES companies(id),   -- NULL = system-provided global unit (e.g. 'piece','kg')
  code          VARCHAR(20) NOT NULL,                     -- e.g. 'PCE','KG','BOX','LTR'
  name_en       VARCHAR(80) NOT NULL,
  name_ar       VARCHAR(80) NULL,
  unit_type     VARCHAR(20) NOT NULL DEFAULT 'count' CHECK (unit_type IN ('count','weight','volume','length','time')),
  is_base_unit  BOOLEAN NOT NULL DEFAULT true,
  created_by    BIGINT NULL REFERENCES users(id),
  updated_by    BIGINT NULL REFERENCES users(id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at    TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_uom_company_code
  ON units_of_measure (COALESCE(company_id, 0), code) WHERE deleted_at IS NULL;

-- ============================================================================
-- product_custom_field_definitions — company-defined schema for products.custom_fields
-- ============================================================================
CREATE TABLE product_custom_field_definitions (
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  field_key           VARCHAR(60) NOT NULL,
  label_en            VARCHAR(120) NOT NULL,
  label_ar            VARCHAR(120) NULL,
  data_type           VARCHAR(20) NOT NULL CHECK (data_type IN ('text','number','boolean','date','select')),
  select_options      JSONB NULL,                 -- for data_type = 'select'
  applicable_category_id BIGINT NULL REFERENCES product_categories(id),  -- NULL = applies to all categories
  applicable_product_type product_type_enum NULL, -- NULL = applies to all types
  is_required_on_activate BOOLEAN NOT NULL DEFAULT false,
  sort_order          INTEGER NOT NULL DEFAULT 0,
  created_by          BIGINT NULL REFERENCES users(id),
  updated_by          BIGINT NULL REFERENCES users(id),
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at          TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_custom_field_defs_company_key
  ON product_custom_field_definitions (company_id, field_key) WHERE deleted_at IS NULL;

-- ============================================================================
-- products — the item master
-- ============================================================================
CREATE TABLE products (
  id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                BIGINT NOT NULL REFERENCES companies(id),
  branch_id                 BIGINT NULL REFERENCES branches(id),
  sku                       VARCHAR(64) NOT NULL,
  product_type              product_type_enum NOT NULL,
  status                    product_status_enum NOT NULL DEFAULT 'draft',
  name_en                   VARCHAR(255) NULL,
  name_ar                   VARCHAR(255) NULL,
  description               TEXT NULL,
  description_ar            TEXT NULL,
  category_id               BIGINT NULL REFERENCES product_categories(id),
  brand                     VARCHAR(120) NULL,
  model_number              VARCHAR(120) NULL,
  unit_of_measure_id        BIGINT NULL REFERENCES units_of_measure(id),
  weight                    NUMERIC(12,4) NULL,
  weight_unit               VARCHAR(10) NULL CHECK (weight_unit IN ('kg','g','lb','oz')),
  length_cm                 NUMERIC(10,2) NULL,
  width_cm                  NUMERIC(10,2) NULL,
  height_cm                 NUMERIC(10,2) NULL,
  is_serialized             BOOLEAN NOT NULL DEFAULT false,
  is_batch_tracked          BOOLEAN NOT NULL DEFAULT false,
  is_returnable_to_stock    BOOLEAN NOT NULL DEFAULT true,
  costing_method            costing_method_enum NULL,
  default_cost_price        NUMERIC(19,4) NOT NULL DEFAULT 0,
  default_selling_price     NUMERIC(19,4) NOT NULL DEFAULT 0,
  currency_code             CHAR(3) NOT NULL DEFAULT 'KWD',
  min_stock_level           NUMERIC(18,4) NULL,
  max_stock_level           NUMERIC(18,4) NULL,
  reorder_point             NUMERIC(18,4) NULL,
  reorder_quantity          NUMERIC(18,4) NULL,
  revenue_account_id        BIGINT NULL REFERENCES accounts(id),
  expense_account_id        BIGINT NULL REFERENCES accounts(id),
  inventory_account_id      BIGINT NULL REFERENCES accounts(id),
  cogs_account_id           BIGINT NULL REFERENCES accounts(id),
  tax_category_id           BIGINT NULL REFERENCES tax_codes(id),
  revenue_recognition_method revenue_recognition_enum NOT NULL DEFAULT 'immediate',
  recurrence_unit           recurrence_unit_enum NULL,
  recurrence_interval       INTEGER NULL CHECK (recurrence_interval IS NULL OR recurrence_interval >= 1),
  bundle_pricing_mode       bundle_pricing_mode_enum NULL,
  preferred_vendor_id       BIGINT NULL REFERENCES vendors(id),
  tags                      JSONB NOT NULL DEFAULT '[]',
  custom_fields             JSONB NOT NULL DEFAULT '{}',
  search_vector             TSVECTOR NULL,   -- maintained by trigger, see below
  embedding                 VECTOR(1536) NULL, -- pgvector, for AI smart search / similarity (see AI Responsibilities)
  discontinued_at           TIMESTAMPTZ NULL,
  archived_at               TIMESTAMPTZ NULL,
  created_by                BIGINT NULL REFERENCES users(id),
  updated_by                BIGINT NULL REFERENCES users(id),
  created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                TIMESTAMPTZ NULL,
  CONSTRAINT ck_products_name_present CHECK (name_en IS NOT NULL OR name_ar IS NOT NULL),
  CONSTRAINT ck_products_stocked_requires_costing CHECK (
    product_type NOT IN ('physical_product','raw_material','finished_goods','semi_finished_goods')
    OR (costing_method IS NOT NULL AND inventory_account_id IS NOT NULL AND unit_of_measure_id IS NOT NULL)
    OR status = 'draft'
  ),
  CONSTRAINT ck_products_nonstocked_no_inventory_account CHECK (
    product_type NOT IN ('service','digital_product','subscription','expense_item')
    OR inventory_account_id IS NULL
  ),
  CONSTRAINT ck_products_subscription_recurrence CHECK (
    product_type <> 'subscription' OR status = 'draft'
    OR (recurrence_unit IS NOT NULL AND recurrence_interval IS NOT NULL)
  ),
  CONSTRAINT ck_products_bundle_pricing_mode CHECK (
    product_type <> 'bundle' OR status = 'draft' OR bundle_pricing_mode IS NOT NULL
  )
);
CREATE UNIQUE INDEX ux_products_company_sku ON products (company_id, sku) WHERE deleted_at IS NULL;
CREATE INDEX ix_products_company ON products (company_id);
CREATE INDEX ix_products_company_status ON products (company_id, status);
CREATE INDEX ix_products_category ON products (category_id);
CREATE INDEX ix_products_type ON products (company_id, product_type);
CREATE INDEX ix_products_tags ON products USING gin (tags jsonb_path_ops);
CREATE INDEX ix_products_custom_fields ON products USING gin (custom_fields);
CREATE INDEX ix_products_search ON products USING gin (search_vector);
CREATE INDEX ix_products_embedding ON products USING hnsw (embedding vector_cosine_ops);

CREATE FUNCTION trg_products_search_vector() RETURNS trigger AS $$
BEGIN
  NEW.search_vector :=
    setweight(to_tsvector('simple', coalesce(NEW.sku,'')), 'A') ||
    setweight(to_tsvector('english', coalesce(NEW.name_en,'')), 'A') ||
    setweight(to_tsvector('arabic', coalesce(NEW.name_ar,'')), 'A') ||
    setweight(to_tsvector('simple', coalesce(NEW.brand,'') || ' ' || coalesce(NEW.model_number,'')), 'B') ||
    setweight(to_tsvector('english', coalesce(NEW.description,'')), 'C');
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER products_search_vector_update
  BEFORE INSERT OR UPDATE OF sku, name_en, name_ar, brand, model_number, description
  ON products FOR EACH ROW EXECUTE FUNCTION trg_products_search_vector();

-- ============================================================================
-- unit_conversions — factor to convert between two units of the SAME unit_type
-- Defined after products because product_id may scope a conversion to one product.
-- ============================================================================
CREATE TABLE unit_conversions (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  from_unit_id      BIGINT NOT NULL REFERENCES units_of_measure(id),
  to_unit_id        BIGINT NOT NULL REFERENCES units_of_measure(id),
  product_id        BIGINT NULL REFERENCES products(id),   -- NULL = generic conversion applicable to all products
  conversion_factor NUMERIC(18,6) NOT NULL CHECK (conversion_factor > 0),  -- 1 from_unit = conversion_factor * to_unit
  created_by        BIGINT NULL REFERENCES users(id),
  updated_by        BIGINT NULL REFERENCES users(id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL,
  CONSTRAINT ck_conversion_diff_units CHECK (from_unit_id <> to_unit_id)
);
CREATE UNIQUE INDEX ux_unit_conversions_scope
  ON unit_conversions (company_id, from_unit_id, to_unit_id, COALESCE(product_id, 0))
  WHERE deleted_at IS NULL;

-- ============================================================================
-- product_variants — size/color/etc. variations of a parent product
-- ============================================================================
CREATE TABLE product_variants (
  id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id         BIGINT NOT NULL REFERENCES companies(id),
  parent_product_id  BIGINT NOT NULL REFERENCES products(id),
  variant_sku        VARCHAR(64) NOT NULL,
  attributes         JSONB NOT NULL DEFAULT '{}',   -- {"color":"Red","size":"XL"}
  barcode            VARCHAR(64) NULL,
  cost_price_delta   NUMERIC(19,4) NOT NULL DEFAULT 0,   -- offset from parent's default_cost_price
  selling_price_delta NUMERIC(19,4) NOT NULL DEFAULT 0,  -- offset from parent's default_selling_price
  weight             NUMERIC(12,4) NULL,
  image_attachment_id BIGINT NULL REFERENCES attachments(id),
  status             VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
  created_by         BIGINT NULL REFERENCES users(id),
  updated_by         BIGINT NULL REFERENCES users(id),
  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at         TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_product_variants_company_sku
  ON product_variants (company_id, variant_sku) WHERE deleted_at IS NULL;
CREATE INDEX ix_product_variants_parent ON product_variants (parent_product_id);
CREATE INDEX ix_product_variants_attributes ON product_variants USING gin (attributes);

-- ============================================================================
-- product_bundle_items — components of a product_type = 'bundle'
-- ============================================================================
CREATE TABLE product_bundle_items (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  bundle_product_id     BIGINT NOT NULL REFERENCES products(id),
  component_product_id  BIGINT NOT NULL REFERENCES products(id),
  quantity               NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
  unit_of_measure_id     BIGINT NULL REFERENCES units_of_measure(id),
  sort_order              INTEGER NOT NULL DEFAULT 0,
  created_by             BIGINT NULL REFERENCES users(id),
  updated_by             BIGINT NULL REFERENCES users(id),
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at             TIMESTAMPTZ NULL,
  CONSTRAINT ck_bundle_not_self CHECK (bundle_product_id <> component_product_id)
);
CREATE UNIQUE INDEX ux_bundle_items_pair
  ON product_bundle_items (bundle_product_id, component_product_id) WHERE deleted_at IS NULL;
CREATE INDEX ix_bundle_items_bundle ON product_bundle_items (bundle_product_id);
CREATE INDEX ix_bundle_items_component ON product_bundle_items (component_product_id);

-- ============================================================================
-- product_warehouse_settings — per-warehouse replenishment policy override
-- ============================================================================
CREATE TABLE product_warehouse_settings (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  product_id        BIGINT NOT NULL REFERENCES products(id),
  warehouse_id      BIGINT NOT NULL REFERENCES warehouses(id),
  min_stock_level   NUMERIC(18,4) NULL,
  max_stock_level   NUMERIC(18,4) NULL,
  reorder_point     NUMERIC(18,4) NULL,
  reorder_quantity  NUMERIC(18,4) NULL,
  created_by        BIGINT NULL REFERENCES users(id),
  updated_by        BIGINT NULL REFERENCES users(id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_pws_product_warehouse
  ON product_warehouse_settings (product_id, warehouse_id) WHERE deleted_at IS NULL;

-- ============================================================================
-- price_lists — sales or purchase price list header
-- ============================================================================
CREATE TABLE price_lists (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  branch_id         BIGINT NULL REFERENCES branches(id),
  name_en           VARCHAR(160) NOT NULL,
  name_ar           VARCHAR(160) NULL,
  price_list_type   price_list_type_enum NOT NULL,
  customer_tier     customer_tier_enum NULL,          -- sales-type only
  customer_id       BIGINT NULL REFERENCES customers(id),   -- customer-specific override list
  vendor_id         BIGINT NULL REFERENCES vendors(id),     -- purchase-type, vendor-specific list
  currency_code     CHAR(3) NOT NULL DEFAULT 'KWD',
  is_default        BOOLEAN NOT NULL DEFAULT false,
  valid_from        DATE NULL,
  valid_to          DATE NULL,
  status            VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
  created_by        BIGINT NULL REFERENCES users(id),
  updated_by        BIGINT NULL REFERENCES users(id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL,
  CONSTRAINT ck_price_list_scope CHECK (
    (price_list_type = 'sales' AND vendor_id IS NULL) OR
    (price_list_type = 'purchase' AND customer_id IS NULL AND customer_tier IS NULL)
  )
);
CREATE UNIQUE INDEX ux_price_lists_one_default_per_type
  ON price_lists (company_id, price_list_type) WHERE is_default AND deleted_at IS NULL;
CREATE INDEX ix_price_lists_company ON price_lists (company_id, price_list_type);
CREATE INDEX ix_price_lists_customer ON price_lists (customer_id) WHERE customer_id IS NOT NULL;
CREATE INDEX ix_price_lists_vendor ON price_lists (vendor_id) WHERE vendor_id IS NOT NULL;

-- ============================================================================
-- price_list_items — per-product price row within a price list, quantity-break aware
-- ============================================================================
CREATE TABLE price_list_items (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  price_list_id     BIGINT NOT NULL REFERENCES price_lists(id),
  product_id        BIGINT NOT NULL REFERENCES products(id),
  unit_of_measure_id BIGINT NULL REFERENCES units_of_measure(id),  -- NULL = product's base unit
  min_quantity      NUMERIC(18,4) NOT NULL DEFAULT 0,
  price             NUMERIC(19,4) NOT NULL CHECK (price >= 0),
  currency_code     CHAR(3) NOT NULL DEFAULT 'KWD',
  vendor_sku        VARCHAR(64) NULL,          -- purchase-type rows: vendor's own part number
  min_order_quantity NUMERIC(18,4) NULL,        -- purchase-type rows
  lead_time_days    INTEGER NULL,               -- purchase-type rows
  valid_from        DATE NULL,
  valid_to          DATE NULL,
  created_by        BIGINT NULL REFERENCES users(id),
  updated_by        BIGINT NULL REFERENCES users(id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_price_list_items_scope
  ON price_list_items (price_list_id, product_id, COALESCE(unit_of_measure_id, 0), min_quantity,
                        COALESCE(valid_from, '0001-01-01'))
  WHERE deleted_at IS NULL;
CREATE INDEX ix_price_list_items_product ON price_list_items (product_id);
CREATE INDEX ix_price_list_items_list ON price_list_items (price_list_id);

-- ============================================================================
-- product_price_suggestions — AI Price Optimization staging table (suggest-only)
-- Defined before product_price_history because the latter references it by FK.
-- ============================================================================
CREATE TABLE product_price_suggestions (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  product_id            BIGINT NOT NULL REFERENCES products(id),
  price_list_id         BIGINT NULL REFERENCES price_lists(id),
  current_price         NUMERIC(19,4) NOT NULL,
  suggested_price       NUMERIC(19,4) NOT NULL,
  floor_price           NUMERIC(19,4) NULL,
  ceiling_price         NUMERIC(19,4) NULL,
  expected_margin_delta NUMERIC(8,4) NULL,          -- percentage points
  expected_volume_delta NUMERIC(8,4) NULL,          -- percentage
  confidence_score      NUMERIC(4,3) NOT NULL CHECK (confidence_score BETWEEN 0 AND 1),
  reasoning             TEXT NOT NULL,
  model_version         VARCHAR(40) NOT NULL,
  status                VARCHAR(20) NOT NULL DEFAULT 'pending'
                         CHECK (status IN ('pending','approved','rejected','expired')),
  reviewed_by           BIGINT NULL REFERENCES users(id),
  reviewed_at           TIMESTAMPTZ NULL,
  expires_at            TIMESTAMPTZ NOT NULL DEFAULT (now() + interval '14 days'),
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_price_suggestions_pending ON product_price_suggestions (company_id, status) WHERE status = 'pending';
CREATE INDEX ix_price_suggestions_product ON product_price_suggestions (product_id);

-- ============================================================================
-- product_price_history — append-only ledger of every price change
-- ============================================================================
CREATE TABLE product_price_history (
  id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id     BIGINT NOT NULL REFERENCES companies(id),
  product_id     BIGINT NOT NULL REFERENCES products(id),
  price_list_id  BIGINT NULL REFERENCES price_lists(id),   -- NULL = default price change
  price_field    VARCHAR(30) NOT NULL CHECK (price_field IN ('default_cost_price','default_selling_price','price_list_item')),
  old_price      NUMERIC(19,4) NULL,
  new_price      NUMERIC(19,4) NOT NULL,
  currency_code  CHAR(3) NOT NULL DEFAULT 'KWD',
  effective_from TIMESTAMPTZ NOT NULL DEFAULT now(),
  changed_by     BIGINT NULL REFERENCES users(id),
  changed_by_ai  BOOLEAN NOT NULL DEFAULT false,
  ai_suggestion_id BIGINT NULL REFERENCES product_price_suggestions(id),
  reason         TEXT NULL,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_price_history_product_time ON product_price_history (product_id, effective_from DESC);
CREATE INDEX ix_price_history_company_time ON product_price_history (company_id, effective_from DESC);

-- ============================================================================
-- product_barcodes — one or more scannable identifiers per product
-- ============================================================================
CREATE TABLE product_barcodes (
  id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id     BIGINT NOT NULL REFERENCES companies(id),
  product_id     BIGINT NOT NULL REFERENCES products(id),
  variant_id     BIGINT NULL REFERENCES product_variants(id),
  barcode_type   barcode_type_enum NOT NULL,
  barcode_value  VARCHAR(128) NOT NULL,
  raw_payload    TEXT NULL,               -- full decoded QR payload, when longer than barcode_value
  is_primary     BOOLEAN NOT NULL DEFAULT false,
  created_by     BIGINT NULL REFERENCES users(id),
  updated_by     BIGINT NULL REFERENCES users(id),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at     TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_barcodes_company_value
  ON product_barcodes (company_id, barcode_value) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX ux_barcodes_one_primary_per_product
  ON product_barcodes (product_id) WHERE is_primary AND deleted_at IS NULL;
CREATE INDEX ix_barcodes_product ON product_barcodes (product_id);

-- ============================================================================
-- product_batches — lot/batch tracking (expiry, traceability)
-- ============================================================================
CREATE TABLE product_batches (
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  product_id          BIGINT NOT NULL REFERENCES products(id),
  warehouse_id        BIGINT NULL REFERENCES warehouses(id),
  batch_number        VARCHAR(80) NOT NULL,
  vendor_id           BIGINT NULL REFERENCES vendors(id),
  manufacture_date     DATE NULL,
  expiry_date          DATE NULL,
  quantity_received    NUMERIC(18,4) NOT NULL DEFAULT 0,
  quantity_remaining    NUMERIC(18,4) NOT NULL DEFAULT 0,
  unit_cost             NUMERIC(19,4) NOT NULL DEFAULT 0,
  status                VARCHAR(20) NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active','expired','recalled','depleted')),
  created_by           BIGINT NULL REFERENCES users(id),
  updated_by           BIGINT NULL REFERENCES users(id),
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at            TIMESTAMPTZ NULL,
  CONSTRAINT ck_batch_remaining_not_negative CHECK (quantity_remaining >= 0),
  CONSTRAINT ck_batch_remaining_le_received CHECK (quantity_remaining <= quantity_received)
);
CREATE UNIQUE INDEX ux_batches_company_product_number
  ON product_batches (company_id, product_id, batch_number) WHERE deleted_at IS NULL;
CREATE INDEX ix_batches_product ON product_batches (product_id);
CREATE INDEX ix_batches_expiry ON product_batches (company_id, expiry_date) WHERE status = 'active';

-- ============================================================================
-- product_serials — individually traceable units (serialized inventory / license keys)
-- ============================================================================
CREATE TABLE product_serials (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  product_id             BIGINT NOT NULL REFERENCES products(id),
  batch_id               BIGINT NULL REFERENCES product_batches(id),
  warehouse_id           BIGINT NULL REFERENCES warehouses(id),
  serial_number          VARCHAR(128) NOT NULL,
  status                 serial_status_enum NOT NULL DEFAULT 'in_stock',
  received_on_document_type  VARCHAR(40) NULL,     -- e.g. 'goods_receipt'
  received_on_document_id    BIGINT NULL,
  sold_on_document_type      VARCHAR(40) NULL,     -- e.g. 'invoice_item'
  sold_on_document_id        BIGINT NULL,
  warranty_expiry_date        DATE NULL,
  created_by             BIGINT NULL REFERENCES users(id),
  updated_by             BIGINT NULL REFERENCES users(id),
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at             TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_serials_company_number
  ON product_serials (company_id, serial_number) WHERE deleted_at IS NULL;
CREATE INDEX ix_serials_product_status ON product_serials (product_id, status);
CREATE INDEX ix_serials_sold_document ON product_serials (sold_on_document_type, sold_on_document_id);
```

**Relationships summary.** `products` is the hub: `product_categories` (many-to-one),
`units_of_measure` (many-to-one, base unit), `unit_conversions` (one-to-many, alternate units),
`product_variants` (one-to-many, self-referencing via `parent_product_id`), `product_bundle_items`
(many-to-many self-join for bundle composition), `product_barcodes`/`product_batches`/
`product_serials` (one-to-many identifiers), `price_list_items` (many-to-many through `price_lists`),
`product_price_history`/`product_price_suggestions` (one-to-many audit/AI trails), and
`product_warehouse_settings` (many-to-many through `warehouses`, foundation table). Every FK from a
child table back to `products(id)` is `ON DELETE RESTRICT` implicitly (no `ON DELETE CASCADE`
anywhere in this schema) — because `products` rows are essentially never hard-deleted once
referenced, cascading delete is never the correct behavior; the Service layer's existence check
(see Product Lifecycle) is the actual delete-guard, not the FK.

**Indexing strategy.** Three query shapes dominate: (1) barcode-exact-match (POS scan) — served by
`ux_barcodes_company_value`, a unique B-tree, sub-millisecond; (2) full-text/fuzzy catalog search —
served by `ix_products_search` (GIN over `tsvector`) combined with `pg_trgm` for typo tolerance on
`sku`/`name_en`, and `ix_products_embedding` (HNSW over `pgvector`) for AI semantic similarity search
(see AI Responsibilities); (3) category-scoped listing — served by `ix_products_category` plus the
materialized `path` column on `product_categories`, avoiding recursive CTEs on the read path.

**Versioning.** `products` itself has no explicit version column; instead, every mutation writes to
the generic `audit_logs` table (old value, new value, per changed column) per the platform's blanket
audit rule, and price-specific mutations additionally write to the purpose-built
`product_price_history` table described above. This two-tier approach (generic audit + purpose-built
price ledger) balances "audit everything" against "keep the hot, frequently-queried price-history
table narrow and fast" — a pattern this document recommends other modules with similarly hot,
frequently-time-sliced sub-histories (e.g., stock valuation) adopt as well.

# AI Responsibilities

Every AI agent below operates through the FastAPI AI Layer, which never writes to PostgreSQL
directly. Each agent calls the same Laravel `/api/v1/products/*` endpoints a human user would,
carrying an `X-Actor-Type: ai_agent` header and the service account's Sanctum token, so every AI
write passes through the identical FormRequest validation and permission checks (`products.ai_agent`
permission, see Permissions) as a human write, and every AI-originated row is distinguishable via
`created_by`/`updated_by` pointing to the AI service user plus a `metadata->>'ai_agent'` tag where
the target table supports JSONB metadata.

**Product Classification.** Agent: **General Accountant** (in its catalog-hygiene capacity) /
**Document AI**. Input: a new product's `name_en`/`name_ar`/`description`/uploaded image or spec
sheet attachment, or a raw row from a bulk import file. Output: a suggested `category_id`, `brand`,
`product_type`, and `tax_category_id`, each with an independent `confidence_score`. Autonomy:
**suggest-only** below 0.85 confidence (written to a `product_classification_suggestions` staging
queue surfaced in the import-review UI); **auto-applied** at or above 0.85 confidence **only** during
first-time draft creation (never overwriting a value a human has already set) and always logged to
`audit_logs` with `changed_by_ai = true` so it remains reversible. Confidence is computed from a
weighted blend of text-embedding similarity to already-categorized products in the same company and,
where available, image classification against a general product-taxonomy model.

**Duplicate Detection.** Agent: **Auditor**. Input: the full active catalog for a company, re-run
nightly (via `mcp__scheduled-tasks`-style cron, not in the request path) and on-demand when a new
product is created. Technique: cosine similarity over `products.embedding` (pgvector, `hnsw` index)
combined with fuzzy string matching (`pg_trgm`) on `name_en`/`name_ar`/`sku`/`brand`+`model_number`
and exact matching on any shared `product_barcodes.barcode_value`. Output: a
`product_duplicate_candidates` pair list with a similarity score and a suggested resolution
(`merge`|`keep_both`|`not_duplicate`). Autonomy: **suggest-only, always** — merging two products is
destructive to historical reporting granularity and can never be AI-autonomous; a human with
`products.merge` must review the candidate pair, choose the surviving `product_id`, and trigger
`ProductService::merge(keepId, mergeId)`, which re-points every `invoice_items`, `bill_items`,
`inventory_items`, `price_list_items`, and `stock_movements` foreign key from `mergeId` to `keepId`
inside a single transaction, then soft-deletes the `mergeId` row. An exact-barcode duplicate (two
distinct `product_id`s sharing one `product_barcodes.barcode_value`) is escalated as **high-priority**
because it is a data-integrity error, not a heuristic guess.

**Demand Forecasting.** Agent: **Forecast Agent**. Input: 12+ months of `stock_movements`
(sales-issue type) and `invoice_items` history per product, seasonality signals (Ramadan/Eid/school-
year cycles for the Gulf market, configurable per company calendar), and macro signals from the
Accounting Engine's fiscal calendar. Output: a rolling 30/60/90-day demand forecast per
product/warehouse with a confidence interval, written to `product_demand_forecasts` (owned jointly
with Inventory; Products stores the forecast row, Inventory's reorder job reads it). Autonomy:
**auto-generate, suggest-only for action** — the forecast numbers themselves are informational and
refresh automatically; any *action* derived from them (raising `reorder_point`, generating a draft
Purchase Request) requires human approval via the same mechanism as Stock Prediction below.

**Stock Prediction.** Agent: **Inventory Manager** (AI). Input: current `inventory_summary`
(available stock), the Demand Forecast Agent's output, and `price_list_items.lead_time_days` for the
product's preferred vendor. Output: a recommended `reorder_point`/`reorder_quantity` adjustment and,
when available stock is projected to breach zero before the next feasible reorder-to-receipt cycle
completes, a **draft** `purchase_requests` row (never a submitted or approved PO — Purchasing's human
approval chain is untouched). Autonomy: **suggest-only**; the draft purchase request appears in the
Purchasing Manager's queue tagged `origin = 'ai_stock_prediction'` with the full reasoning chain
attached (`ai_conversations`/`ai_messages` linkage) so the approver can see *why* the AI thinks 200
units of SKU ELC-000042 are needed by August 3rd.

**Price Optimization.** Agent: **CFO** (AI) in coordination with the **Reporting Agent**. Input:
`product_price_history`, sales velocity by price point (a natural experiment when a price has
changed over time), competitor price signals (only if the company has opted into an external price-
monitoring integration — out of scope for this document), and current margin vs. the company's
target margin band per category. Output: rows in `product_price_suggestions` (see Database Design)
with `suggested_price`, `expected_margin_delta`, `expected_volume_delta`, `confidence_score`, and full
`reasoning`. Autonomy: **strictly suggest-only**, gated by `products.pricing.approve`; the AI can
never write to `price_list_items` directly. Guardrails: every suggestion must fall within
`floor_price`/`ceiling_price` bounds the company configures per category (e.g., "never suggest more
than 20% above current retail" or "never suggest below cost + 5% margin") — a suggestion outside
those bounds is rejected by the Laravel FormRequest before it is even persisted to the staging table,
regardless of the AI's confidence.

**Bundle Suggestions.** Agent: **Reporting Agent** (market-basket analysis capacity). Input:
co-purchase patterns across `invoice_items` (products frequently bought together within the same
invoice or within a short time window by the same customer). Output: a suggested new `bundle`
product with a pre-filled `product_bundle_items` composition and a suggested `bundle_pricing_mode`
(typically `sum_of_components` with a small discount), written to a
`product_bundle_suggestions` staging row. Autonomy: **suggest-only**; creating the actual bundle
product and its `product_bundle_items` rows requires a human with `products.create` to accept the
suggestion, which then calls the normal product-creation API (no separate "AI bundle creation"
endpoint exists — the AI proposes, the human uses the standard write path to accept).

**Smart Search.** Agent: **Document AI** / general retrieval layer. Every product's
`name_en`/`name_ar`/`description`/`brand`/`model_number`/`tags` are embedded (`products.embedding
VECTOR(1536)`, refreshed asynchronously via a queued Laravel job whenever any of those fields change)
so that `GET /api/v1/products/search?q=...` can combine classic full-text (`ix_products_search`) with
semantic nearest-neighbor search (`ix_products_embedding`) and return relevant results even when the
query uses synonyms, misspellings, or a different language than the matched product's primary name
(e.g., an Arabic-typed query matching an English-named product via cross-lingual embedding
similarity). Autonomy: **fully automated, read-only** — search never mutates data, so no approval
chain applies; the only "AI responsibility" here is embedding maintenance, which is transparent
background housekeeping.

**Recommendation Engine.** Agent: **Reporting Agent**. Surfaces "customers who bought this also
bought" (from co-purchase patterns, same underlying data as Bundle Suggestions) and "you may be
running low on" (from Stock Prediction's forecast) recommendations to the Sales UI (upsell prompts at
POS/quotation time) and to the Purchasing UI (replenishment prompts). Autonomy: **suggest-only,
zero-friction** — recommendations are advisory UI hints with no persisted staging row of their own
(they are computed live from the same forecast/co-purchase tables at request time); accepting a
recommendation is simply the user adding the recommended product to their document through the
normal Sales/Purchasing flow, generating no special audit trail beyond the normal
`sales_order_items`/`purchase_order_items` creation log.

| AI Agent | Primary Data In | Primary Output | Autonomy | Approval Required |
|---|---|---|---|---|
| General Accountant / Document AI | name/description/image/spec sheet | category, brand, type, tax_category | Auto ≥0.85 confidence on draft-only fields | No (below threshold: suggest) |
| Auditor | embeddings, trigram, barcodes | duplicate candidate pairs | Suggest-only | Yes — `products.merge` |
| Forecast Agent | stock_movements, invoice_items, seasonality | demand forecast curve | Auto-generate (informational) | No (informational only) |
| Inventory Manager (AI) | forecast, lead time, available stock | reorder_point, draft purchase_requests | Suggest-only | Yes — Purchasing approval chain |
| CFO (AI) / Reporting Agent | price history, velocity, margin targets | price change suggestion | Suggest-only, guardrail-bounded | Yes — `products.pricing.approve` |
| Reporting Agent | co-purchase basket analysis | bundle composition suggestion | Suggest-only | Yes — `products.create` |
| Document AI | text/embedding index | smart search results | Fully automated, read-only | No |
| Reporting Agent | forecast + co-purchase | live recommendations | Fully automated, read-only, zero-friction | No |

# Reports

All reports below are computed on read (no report pre-aggregates data into `products` itself) by
joining `products` against Inventory's `inventory_items`/`inventory_valuations`, Sales'
`invoice_items`, and Purchasing's `bill_items`/`purchase_order_items`. Each report is registered in
the foundation `report_definitions` table with a `report_key`, is schedulable via
`report_schedules`, and produces immutable snapshots in `report_runs` for point-in-time audit
(a report re-run against a closed fiscal period must reproduce the same figures indefinitely, so
`report_runs.parameters` and `report_runs.result_snapshot` are stored, not just the report
definition).

**Inventory Valuation.** Grouped by `product_id`/`warehouse_id`, sums `inventory_items.quantity_on_hand
× unit_cost` (FIFO layer sum or current weighted average per `products.costing_method`), rolled up by
`category_id` and company total. Reconciles to the Inventory Account balance in the General Ledger —
a material variance between this report's total and `accounts.balance` for each product's
`inventory_account_id` is itself flagged by the Auditor AI agent as a control exception.

```sql
SELECT p.id, p.sku, p.name_en, p.category_id, ii.warehouse_id,
       ii.quantity_on_hand, iv.unit_cost, (ii.quantity_on_hand * iv.unit_cost) AS valuation
FROM products p
JOIN inventory_items ii ON ii.product_id = p.id
JOIN LATERAL (
  SELECT unit_cost FROM inventory_valuations
  WHERE product_id = p.id AND warehouse_id = ii.warehouse_id
  ORDER BY valued_at DESC LIMIT 1
) iv ON true
WHERE p.company_id = :company_id AND p.deleted_at IS NULL;
```

**Product Profitability.** Per product, per period: `SUM(invoice_items.line_total) -
SUM(invoice_items.quantity * cogs_at_time_of_sale)` (COGS snapshotted per line at post time, per the
Sales Integration section), divided by revenue for a gross margin percentage. Supports drill-down to
`customer_id`, `cost_center_id`, and `project_id` dimensions carried on the source `invoice_items`/
`journal_lines` rows.

**Top Selling.** Ranks products by `SUM(invoice_items.quantity)` or `SUM(invoice_items.line_total)`
over a selected period, filterable by category/branch/warehouse, with a period-over-period delta
column. Feeds directly into the Recommendation Engine's "trending" surfacing and into
merchandising decisions (which products to feature).

**Slow Moving.** Products whose `SUM(stock_movements.quantity WHERE movement_type = 'sales_issue')`
over the trailing 90 days falls below a configurable velocity threshold (default: fewer than 1 unit
sold per 30 days) relative to their current `quantity_on_hand`. This report is the direct human-facing
surface for what the Forecast Agent flags internally; a Slow Moving product above a second, higher
threshold and duration is auto-nominated (never auto-executed) for the Discontinue workflow.

**Dead Stock.** A stricter subset of Slow Moving: zero `stock_movements` of type `sales_issue` in the
trailing 180 days (configurable) AND `quantity_on_hand > 0`. Reports the tied-up inventory valuation
(`quantity_on_hand × unit_cost`) as a "capital at risk" figure, prioritized by valuation descending so
Finance sees the biggest write-off exposure first.

**Purchase Analysis.** Per product/vendor: total quantity purchased, average unit cost trend over
time (sourced from `product_price_history` and `bill_items.unit_cost`), and price-variance-from-quote
(comparing `bill_items.unit_cost` against the `price_list_items` row that was active at PO creation
time, flagging any purchase that landed materially above the agreed vendor price).

**Sales Analysis.** Mirrors Purchase Analysis for the sell side: quantity sold, average realized
price vs. list price (to quantify discount leakage), and channel breakdown (POS vs. invoiced vs.
subscription-recurring) where the company uses multiple sales channels.

**Margin Analysis.** Gross margin percentage by product, category, brand, and time bucket, with a
"margin erosion" alert (a product whose trailing-30-day margin has dropped more than a configurable
number of percentage points vs. its trailing-90-day baseline) that feeds the Price Optimization AI
agent's priority queue.

**ABC Analysis.** Classic revenue-contribution Pareto classification: products ranked by trailing-12-
month revenue descending, cumulative-percentage-banded into A (top ~80% of revenue), B (next ~15%),
C (remaining ~5%), with thresholds configurable per company. Used to prioritize cycle-count frequency
in Inventory (A-items counted monthly, C-items counted annually) and to prioritize which products the
Price Optimization AI reviews first.

**XYZ Analysis.** Complementary to ABC: classifies products by **demand variability** (coefficient of
variation of `stock_movements` quantity over trailing periods) into X (stable/predictable demand), Y
(some variability), Z (highly erratic/unpredictable demand — often new or seasonal products). Combined
ABC/XYZ (e.g., "AX" = high-revenue, stable-demand; "CZ" = low-revenue, erratic-demand) drives
differentiated `reorder_point` safety-stock sizing: AX products carry tight safety stock (predictable,
so less buffer needed relative to value), CZ products either carry generous buffer or are moved to a
make-to-order/no-stock policy, both fed as a recommendation into the Inventory Manager AI agent's
reorder-point suggestions rather than computed ad hoc by that agent in isolation.

# API

All endpoints are versioned under `/api/v1/products` (and sibling sub-resources), require a Bearer
token, resolve the active tenant from `X-Company-Id`, and return the standard response envelope
defined in the platform-wide API conventions. Pagination defaults to 25 items/page, cursor-paginated
for the barcode/search endpoints given their high QPS.

| Method | Path | Permission | Description |
|---|---|---|---|
| `GET` | `/api/v1/products` | `products.read` | List/filter/search products (query params: `status`, `product_type`, `category_id`, `brand`, `tags`, `q`, `sort`, `page`, `per_page`) |
| `GET` | `/api/v1/products/{id}` | `products.read` | Retrieve one product with `inventory_summary` rollup |
| `POST` | `/api/v1/products` | `products.create` | Create a new product (starts in `draft`) |
| `PATCH` | `/api/v1/products/{id}` | `products.update` | Partial update; SKU/costing-method/unit locked post-transaction (see Business Rules) |
| `DELETE` | `/api/v1/products/{id}` | `products.delete` | Hard-delete if never transacted, else soft-delete/reject |
| `POST` | `/api/v1/products/{id}/activate` | `products.update` | Transition `draft` → `active` (validates readiness) |
| `POST` | `/api/v1/products/{id}/discontinue` | `products.update` | Transition `active` → `discontinued` |
| `POST` | `/api/v1/products/{id}/reactivate` | `products.update` | Transition `discontinued` → `active` |
| `POST` | `/api/v1/products/{id}/archive` | `products.archive` | Transition `discontinued` → `archived` (validates zero stock/open docs) |
| `POST` | `/api/v1/products/merge` | `products.merge` | Merge a duplicate product into a surviving `product_id` |
| `GET` | `/api/v1/products/barcode/{code}` | `products.read` | O(1) barcode/QR lookup (POS hot path) |
| `POST` | `/api/v1/products/{id}/barcodes` | `products.update` | Attach a new barcode/QR to a product |
| `GET` | `/api/v1/products/{id}/price` | `products.read` | Resolve price for a given customer/vendor/currency/quantity/date |
| `POST` | `/api/v1/products/{id}/price-lists/{price_list_id}/items` | `products.pricing.update` | Set/override a price-list-scoped price row |
| `GET` | `/api/v1/products/{id}/price-history` | `products.read` | Paginated price-change ledger |
| `GET` | `/api/v1/products/{id}/price-suggestions` | `products.pricing.read` | List pending AI price suggestions |
| `POST` | `/api/v1/products/price-suggestions/{id}/approve` | `products.pricing.approve` | Approve an AI price suggestion; writes to price_list_items |
| `POST` | `/api/v1/products/price-suggestions/{id}/reject` | `products.pricing.approve` | Reject/dismiss an AI price suggestion |
| `GET` | `/api/v1/product-categories` | `products.read` | List/tree of categories |
| `POST` | `/api/v1/product-categories` | `products.category.manage` | Create a category |
| `GET` | `/api/v1/products/search` | `products.read` | Full-text + semantic smart search |
| `POST` | `/api/v1/products/import` | `products.import` | Bulk import (CSV/XLSX/JSON), returns an async job id |
| `GET` | `/api/v1/products/import/{job_id}` | `products.import` | Poll import job status/results |
| `GET` | `/api/v1/products/export` | `products.export` | Bulk export (CSV/XLSX/JSON) of the filtered catalog |
| `POST` | `/api/v1/products/bulk` | `products.update` | Bulk field update (e.g., re-category, re-tag, price adjust %) across a filtered set |
| `POST` | `/api/v1/products/{id}/variants` | `products.update` | Create a variant under a parent product |
| `POST` | `/api/v1/products/{id}/bundle-items` | `products.update` | Add a component to a `bundle`-type product |
| `GET` | `/api/v1/products/{id}/duplicates` | `products.read` | List AI-flagged duplicate candidates for this product |
| `POST` | `/api/v1/products/{id}/serials` | `inventory.serialize` | Register serial numbers for a received batch of a serialized product |
| `POST` | `/api/v1/products/{id}/batches` | `inventory.batch_track` | Register a new batch/lot for a batch-tracked product |
| `GET` | `/api/v1/products/reports/{report_key}` | `reports.read` (product-specific reports listed in Reports section) | Run/retrieve a product report |

**Example 1 — Create a physical product (request):**
```json
POST /api/v1/products
X-Company-Id: 55
{
  "product_type": "physical_product",
  "name_en": "Samsung 55\" QLED 4K TV",
  "name_ar": "تلفزيون سامسونج كيو ال اي دي 55 بوصة 4K",
  "sku": "ELC-000042",
  "category_id": 14,
  "brand": "Samsung",
  "model_number": "QN55Q60C",
  "unit_of_measure_id": 1,
  "costing_method": "weighted_average",
  "default_cost_price": "260.0000",
  "default_selling_price": "350.0000",
  "currency_code": "KWD",
  "inventory_account_id": 1301,
  "cogs_account_id": 5001,
  "revenue_account_id": 4001,
  "tax_category_id": 3,
  "min_stock_level": "5.0000",
  "reorder_point": "10.0000",
  "reorder_quantity": "25.0000",
  "is_serialized": true,
  "tags": ["electronics", "featured"],
  "custom_fields": {"screen_size_inches": 55, "energy_rating": "A+"}
}
```

**Example 1 — Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "id": 4821,
    "company_id": 55,
    "sku": "ELC-000042",
    "product_type": "physical_product",
    "status": "draft",
    "name_en": "Samsung 55\" QLED 4K TV",
    "name_ar": "تلفزيون سامسونج كيو ال اي دي 55 بوصة 4K",
    "category_id": 14,
    "brand": "Samsung",
    "model_number": "QN55Q60C",
    "unit_of_measure_id": 1,
    "costing_method": "weighted_average",
    "default_cost_price": "260.0000",
    "default_selling_price": "350.0000",
    "currency_code": "KWD",
    "inventory_account_id": 1301,
    "cogs_account_id": 5001,
    "revenue_account_id": 4001,
    "tax_category_id": 3,
    "is_serialized": true,
    "is_batch_tracked": false,
    "tags": ["electronics", "featured"],
    "custom_fields": {"screen_size_inches": 55, "energy_rating": "A+"},
    "created_at": "2026-07-16T09:12:04Z",
    "updated_at": "2026-07-16T09:12:04Z"
  },
  "message": "Product created as draft.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "5f2a0b3e-1c9d-4a2e-9f1a-7d3c8e0b6a11",
  "timestamp": "2026-07-16T09:12:04Z"
}
```

**Example 2 — Activate the product (request/response):**
```json
POST /api/v1/products/4821/activate
```
```json
{
  "success": true,
  "data": {"id": 4821, "status": "active", "activated_at": "2026-07-16T09:15:41Z"},
  "message": "Product activated.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "9c1d4e2a-6b3f-4a8c-9e2d-0a1b2c3d4e5f",
  "timestamp": "2026-07-16T09:15:41Z"
}
```
If validation fails (e.g., no active price-list row exists yet), the API instead returns:
```json
{
  "success": false,
  "data": null,
  "message": "Product cannot be activated: readiness checks failed.",
  "errors": [
    {"code": "NO_ACTIVE_PRICE", "field": "default_selling_price", "message": "No active price found on any sales price list."},
    {"code": "MISSING_ACCOUNT_MAPPING", "field": "inventory_account_id", "message": "Stocked product types require an inventory account before activation."}
  ],
  "meta": {"pagination": null},
  "request_id": "9c1d4e2a-6b3f-4a8c-9e2d-0a1b2c3d4e5f",
  "timestamp": "2026-07-16T09:15:41Z"
}
```

**Example 3 — Barcode lookup (POS hot path):**
```json
GET /api/v1/products/barcode/6291041500213
```
```json
{
  "success": true,
  "data": {
    "product_id": 4821,
    "sku": "ELC-000042",
    "name_en": "Samsung 55\" QLED 4K TV",
    "name_ar": "تلفزيون سامسونج كيو ال اي دي 55 بوصة 4K",
    "barcode_type": "ean13",
    "resolved_price": {"amount": "350.0000", "currency_code": "KWD", "price_list_id": 2},
    "is_serialized": true,
    "requires_serial_at_sale": true,
    "tax_rate_percent": "0.00"
  },
  "message": "Product found.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c8d",
  "timestamp": "2026-07-16T09:20:11Z"
}
```
`404 Not Found` if the barcode is unregistered, with `errors: [{"code":"BARCODE_NOT_FOUND", ...}]`.

**Example 4 — Bulk import (request/response):**
```json
POST /api/v1/products/import
Content-Type: multipart/form-data
file: catalog_2026_q3.xlsx
mapping: {"col_a":"sku","col_b":"name_en","col_c":"category_code","col_d":"default_selling_price"}
options: {"ai_classify_unmapped_fields": true, "dry_run": false}
```
```json
{
  "success": true,
  "data": {"job_id": "imp_7f3a1c9e", "status": "queued", "row_count": 5400},
  "message": "Import job queued.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "3d4e5f6a-7b8c-4d9e-0f1a-2b3c4d5e6f7a",
  "timestamp": "2026-07-16T09:25:00Z"
}
```
```json
GET /api/v1/products/import/imp_7f3a1c9e
```
```json
{
  "success": true,
  "data": {
    "job_id": "imp_7f3a1c9e",
    "status": "completed",
    "row_count": 5400,
    "created": 5122,
    "updated": 210,
    "skipped": 40,
    "ai_classification_applied": 4870,
    "errors_file_url": "https://cdn.qayd.app/imports/imp_7f3a1c9e/errors.csv"
  },
  "message": "Import completed with 40 skipped rows; see errors file.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "8e9f0a1b-2c3d-4e5f-6a7b-8c9d0e1f2a3b",
  "timestamp": "2026-07-16T09:31:47Z"
}
```

**Example 5 — Bulk operation (price adjustment across a filtered set):**
```json
POST /api/v1/products/bulk
{
  "filter": {"category_id": 14, "status": "active"},
  "operation": "adjust_price_percent",
  "params": {"price_list_id": 2, "percent_change": 5.0, "reason": "Q3 cost increase pass-through"}
}
```
```json
{
  "success": true,
  "data": {"matched": 312, "updated": 312, "price_history_rows_created": 312},
  "message": "Bulk price adjustment applied to 312 products.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "4f5a6b7c-8d9e-4f0a-1b2c-3d4e5f6a7b8c",
  "timestamp": "2026-07-16T09:40:02Z"
}
```

Standard error codes reused across every endpoint above: `400` malformed request, `401` missing/
invalid token, `403` permission denied (`errors: [{"code":"FORBIDDEN","required_permission":"..."}]`),
`404` product/category/price-list not found, `409` state-conflict (e.g., `SKU_IMMUTABLE_AFTER_
TRANSACTION`, `COSTING_METHOD_LOCKED_WITH_STOCK`, `PRODUCT_HAS_TRANSACTIONS`,
`PRODUCT_NOT_TRANSFERABLE`), `422` validation (`errors[]` populated per field), `429` rate-limited
(applies most aggressively to `/products/search` and `/products/barcode/{code}` under a per-company
token bucket), `500` internal.

# Permissions

Permission keys follow the platform's `<area>.<action>` and `<area>.<entity>.<action>` convention,
default-deny. `products.ai_agent` is a special key granted only to the AI service account, scoping
which write endpoints an AI-authenticated request may call at all (a defense-in-depth layer beneath
the per-endpoint permission — even if `products.ai_agent` holds `products.update`, individual
Service-layer methods additionally check `if (actor.isAiAgent() && field.requiresHumanApproval())
throw AuthorizationException` for the specific fields listed as suggest-only above).

| Permission Key | Description |
|---|---|
| `products.read` | View products, categories, price lists (read-only) |
| `products.create` | Create new draft products |
| `products.update` | Edit product fields, activate/discontinue/reactivate |
| `products.delete` | Hard-delete untransacted products; force soft-delete otherwise |
| `products.archive` | Archive a discontinued, zero-stock product |
| `products.merge` | Merge duplicate products (destructive, high-privilege) |
| `products.import` | Run bulk catalog imports |
| `products.export` | Run bulk catalog exports |
| `products.category.manage` | Create/edit/reorder the category tree and its default account mappings |
| `products.pricing.read` | View price lists, price history, AI price suggestions |
| `products.pricing.update` | Directly edit `price_list_items` (manual price changes) |
| `products.pricing.approve` | Approve or reject AI price suggestions |
| `products.account_mapping.update` | Change `revenue_account_id`/`expense_account_id`/`inventory_account_id`/`cogs_account_id`/`tax_category_id` (sensitive — mis-mapping silently misdirects every future journal entry for the product) |
| `products.ai_agent` | Reserved for the AI service account; scopes which endpoints AI may call |
| `sales.override_price` | Override the resolved price on a sales document line (not a Products permission, but consumed by Pricing's precedence chain) |

| Role | read | create | update | delete | archive | merge | import/export | category.manage | pricing.read | pricing.update | pricing.approve | account_mapping.update |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Owner | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| CEO | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| CFO | ✓ | – | ✓ (pricing/account fields only) | – | – | – | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| Finance Manager | ✓ | – | ✓ (account mapping) | – | – | – | ✓ (export) | – | ✓ | – | ✓ | ✓ |
| Senior Accountant | ✓ | ✓ | ✓ | – | – | – | ✓ | ✓ | ✓ | ✓ | – | – |
| Accountant | ✓ | ✓ | ✓ | – | – | – | – | – | ✓ | – | – | – |
| Auditor | ✓ | – | – | – | – | – | ✓ (export only) | – | ✓ | – | – | – |
| External Auditor | ✓ | – | – | – | – | – | ✓ (export only) | – | ✓ | – | – | – |
| Inventory Manager | ✓ | ✓ | ✓ (stock-related fields: unit, batch/serial flags, reorder levels) | – | ✓ | – | ✓ | – | ✓ | – | – | – |
| Warehouse Employee | ✓ | – | – | – | – | – | – | – | – | – | – | – |
| Sales Manager | ✓ | ✓ (drafts for new SKUs pending Finance mapping) | ✓ (non-financial fields) | – | – | – | – | – | ✓ | – | – | – |
| Sales Employee | ✓ | – | – | – | – | – | – | – | ✓ | – | – | – |
| Purchasing Manager | ✓ | ✓ | ✓ (vendor-related fields, preferred_vendor_id) | – | – | – | ✓ | – | ✓ | ✓ (purchase price lists only) | – | – |
| Purchasing Employee | ✓ | – | – | – | – | – | – | – | ✓ | – | – | – |
| Read Only | ✓ | – | – | – | – | – | – | – | ✓ | – | – | – |
| AI Agent | ✓ | ✓ (drafts) | ✓ (below-threshold suggest-only fields) | – | – | – | – | – | ✓ | ✓ (staging tables only, never `price_list_items` directly) | – | – |

Note that `products.merge`, `products.archive`, and `products.account_mapping.update` are
intentionally withheld from every non-Owner/CEO/CFO/Finance-Manager role by default, consistent with
the shared design context's rule that sensitive, hard-to-reverse operations always sit behind a
narrow, senior-role permission set — merging silently collapses two products' historical reporting
identity, archiving is a gate against premature removal of reportable history, and account-mapping
errors corrupt every future journal entry the product generates until caught.

# Notifications

Products emits notifications (via the foundation `notifications` table, delivered through Laravel
Reverb for in-app real-time and, where configured, email/SMS/push fan-out) for the following events:

| Event | Trigger | Recipients | Channel(s) |
|---|---|---|---|
| `product.reorder_point_breached` | `quantity_available` crosses below `reorder_point` | Inventory Manager, Purchasing Manager | In-app, email |
| `product.stockout` | `quantity_available` reaches zero for an active product | Inventory Manager, Sales Manager, Sales Employees actively quoting that SKU | In-app, real-time (Reverb) |
| `product.price_suggestion_created` | AI Price Optimization agent writes a new `product_price_suggestions` row | CFO, Finance Manager (holders of `products.pricing.approve`) | In-app |
| `product.price_suggestion_expiring` | A pending suggestion is within 48h of `expires_at` | Same as above | In-app, email |
| `product.duplicate_detected` | Auditor AI agent creates a new high-similarity `product_duplicate_candidates` row | Senior Accountant, Auditor role holders | In-app |
| `product.batch_expiring` | `product_batches.expiry_date` is within a configurable window (default 30 days) of today, `quantity_remaining > 0` | Inventory Manager, Warehouse Employees at the batch's warehouse | In-app, email |
| `product.discontinued` | A product transitions to `discontinued` | Sales Manager, Purchasing Manager (so open pipelines referencing it are re-evaluated) | In-app |
| `product.import_completed` | A bulk import job finishes | The user who submitted the import | In-app, email |
| `product.merge_completed` | A merge operation completes | The user who approved the merge, plus Senior Accountant | In-app |
| `product.reactivated` | A discontinued product is reactivated | Purchasing Manager, Sales Manager | In-app |

All notifications carry a deep link back to the specific product/suggestion/batch record and, for
AI-originated notifications, the confidence score and a one-line reasoning summary so the recipient
can triage without opening the full detail view. Notification preferences (channel opt-out, digest
vs. real-time) are managed at the user level by the foundation Notifications system, not by Products.

# Security

**Tenant isolation.** Every query the Products Service layer issues is scoped by the authenticated
request's resolved `company_id` (derived from `X-Company-Id` cross-checked against the bearer
token's authorized companies via `company_users`); there is no code path in `ProductRepository` that
accepts a caller-supplied `company_id` without that cross-check. Every table in this document carries
`company_id NOT NULL` and every index used for lookups leads with it, so a cross-tenant query is both
impossible at the application layer and inefficient (hence unlikely to be attempted) at the database
layer.

**Field-level authorization.** Financially sensitive fields — `revenue_account_id`,
`expense_account_id`, `inventory_account_id`, `cogs_account_id`, `cost_price`, `tax_category_id` —
are gated by `products.account_mapping.update`, a permission distinct from the general
`products.update`, so a Sales Manager who can rename a product or change its description cannot
silently redirect its COGS postings to a different account. The Laravel FormRequest strips any
disallowed field from the validated payload before it ever reaches the Service layer (fail-closed:
unknown or unauthorized fields are dropped, never partially applied).

**AI write boundary.** As stated in AI Responsibilities, no AI agent can write directly to
`price_list_items`, `revenue_account_id`/`inventory_account_id`/etc., or merge products. Every AI
write path terminates at a staging table (`product_price_suggestions`,
`product_classification_suggestions`, `product_duplicate_candidates`,
`product_bundle_suggestions`) or, for auto-applied low-risk classification on brand-new drafts only,
at a field that has never yet been set by a human — the Service layer checks
`if (field_already_set_by_human) reject_ai_write()` before any AI-originated `UPDATE` regardless of
confidence.

**Injection and input handling.** All product text fields (`name_en`, `name_ar`, `description`,
`custom_fields` values) are stored via parameterized queries (Eloquent/query builder, never raw SQL
string interpolation) and HTML-sanitized on the way into `description`/`description_ar` if the
company's `description_format` is `html`, using an allow-list sanitizer (no `<script>`, no inline
event handlers) before storage — sanitizing at write time, not merely at render time, so that every
consumer (mobile app, AI embedding pipeline, export files) receives already-safe content.

**Barcode/QR payload validation.** `product_barcodes.raw_payload` (used for QR) is treated as
untrusted external input even though it originates from the company's own printed/scanned materials
— a maliciously crafted QR payload scanned at a POS terminal is validated against an expected schema
(`{"sku":"...","batch":"...","company":"..."}`) before any of its fields are used to look up or
mutate a record, and the payload is never `eval`'d, deserialized as executable code, or used to
construct a dynamic query.

**Rate limiting and abuse prevention.** `/products/barcode/{code}` and `/products/search` are
rate-limited per API token (not just per IP) to prevent catalog-scraping by a compromised token, at a
default of 300 requests/minute, configurable per company plan tier. Bulk `/products/import` and
`/products/export` are capped in row-count per request (default 50,000 rows) and queued
asynchronously rather than processed inline, both to protect the request timeout budget and to bound
the blast radius of a malformed import file.

**Secrets and credentials.** Products holds no secrets of its own (no API keys, no payment data); it
references `vendor_id`/`customer_id` by ID only, never embedding vendor banking or customer payment
details, which live in their respective owning modules under their own security controls.

# Audit Logs

Every mutating operation on `products`, `product_categories`, `product_variants`,
`product_bundle_items`, `price_lists`, `price_list_items`, `product_barcodes`, `product_batches`, and
`product_serials` writes an entry to the foundation `audit_logs` table with: `company_id`,
`user_id` (nullable if system/AI — see `actor_type` below), `actor_type`
(`human`|`ai_agent`|`system`), `action` (`product.created`|`product.updated`|`product.activated`|
`product.discontinued`|`product.archived`|`product.deleted`|`product.merged`|
`price_list_item.changed`|`barcode.attached`|`serial.status_changed`|`batch.status_changed`, etc.),
`entity_type`, `entity_id`, `old_values JSONB` (only the changed columns, not a full row snapshot, to
keep the log compact), `new_values JSONB`, `reason TEXT` (required for `product.merged`,
`product.deleted`, and any `account_mapping.update` — the FormRequest rejects those specific
mutations without a non-empty `reason` field), `ip_address`, `user_agent`/`device`, and
`created_at`.

Two categories of change get **additional**, purpose-built logging beyond the generic `audit_logs`
row, because they are queried far more often and with far narrower filters than "show me all changes
to this product":

- **Price changes** → `product_price_history` (see Database Design), queried by
  product+date-range constantly (Price History tab, Margin Analysis report, Price Optimization AI
  training signal).
- **Lifecycle transitions** → surfaced via `audit_logs` filtered to the four lifecycle `action`
  values above, but additionally denormalized onto the `products` row itself as
  `discontinued_at`/`archived_at` timestamp columns (see Database Design) purely so that "products
  discontinued before date X" style queries don't require a join to `audit_logs` at all.

Audit log rows are themselves immutable and append-only (no `UPDATE`/`DELETE` grants on `audit_logs`
for any application role, including Owner — corrections happen by writing a new compensating audit
row, never by editing history) and are retained for a minimum of 7 years to satisfy Gulf-region
financial record-keeping requirements, matching the platform's broader immutable-financial-record
posture for posted accounting entries.

# Performance

**Read path optimization.** The two highest-frequency reads are barcode-exact-match (POS scanning,
target p99 < 50ms) and catalog search (target p99 < 250ms for up to 200,000 SKUs per company).
Barcode lookup is served entirely by the `ux_barcodes_company_value` unique B-tree index — a single
index-only scan, no join required for the identity resolution itself (price resolution is a
follow-on query, cached — see below). Search combines `ix_products_search` (GIN tsvector) for exact/
prefix term matches with `ix_products_embedding` (HNSW) for semantic fallback only when the tsvector
query returns fewer than a configurable minimum result count, avoiding the more expensive vector scan
on the common case where full-text already found good matches.

**Caching.** Price resolution results are cached in Redis with key
`price:{company_id}:{product_id}:{customer_id|vendor_id}:{currency}:{quantity_bucket}` and a 5-minute
TTL, invalidated eagerly on the `product.price_changed` domain event (see Pricing) rather than relying
on TTL expiry alone — so a price change is never visible-stale for longer than the cache invalidation
event's own propagation delay (sub-second via Redis pub/sub). The `inventory_summary` rollup on
`GET /api/v1/products/{id}` is cached for 30 seconds only (deliberately short — stock levels are
operationally sensitive and a stale "in stock" answer at POS is a worse failure mode than an extra
query), and is invalidated immediately on any `stock_movements` insert for that product via the same
event-driven invalidation pattern.

**Write path optimization.** Bulk imports and bulk operations (`/products/import`, `/products/bulk`)
never run inline in the HTTP request — they are dispatched to a Laravel queue (Redis-backed) and
processed in chunks of 500 rows per job, each chunk wrapped in its own database transaction so a
failure partway through an import doesn't require re-processing already-committed rows, and so no
single transaction holds locks across tens of thousands of rows.

**Index maintenance.** The `embedding` (pgvector HNSW) and `search_vector` (GIN tsvector) indexes are
maintained incrementally by the `products_search_vector_update` trigger and an asynchronous embedding-
refresh queue job respectively — embeddings are deliberately **not** recomputed synchronously on
every text-field edit (an LLM embedding call inline in a write request would blow the write-path
latency budget), so there is a bounded eventual-consistency window (typically under 60 seconds) where
a just-edited product's semantic search ranking still reflects its previous text. This is an accepted
trade-off, documented here so downstream consumers (e.g., Smart Search callers) understand that
"searchable immediately" and "semantically re-ranked immediately" are different guarantees.

**Partitioning strategy for scale.** `products` itself is not partitioned in v1 (a single company's
catalog rarely exceeds a few hundred thousand rows, well within a single B-tree's efficient range with
proper indexing). `product_price_history` and `audit_logs`-sourced product events, however, grow
unbounded over time and are candidates for **time-range partitioning by `created_at`** (monthly or
quarterly partitions) once a company's history exceeds roughly 10 million rows in either table —
flagged explicitly under Future Improvements rather than implemented pre-emptively, since premature
partitioning adds operational complexity (partition maintenance, cross-partition query planning) that
is not justified until the data volume threshold is actually reached.

**N+1 avoidance.** List endpoints (`GET /api/v1/products`) eager-load `category`, `primary_barcode`,
and `unit_of_measure` in a single query via explicit joins/subqueries rather than lazy-loading
relations per row — a 25-row page of products never issues more than 4-5 total queries regardless of
how many relations are requested, enforced by a repository-layer contract test that fails the build
if a new relation is added without updating the eager-load list.

# Edge Cases

- **Selling a product with `is_serialized = true` without specifying serial numbers.** Rejected at
  the Sales Service layer (not at the database) with `422 SERIAL_NUMBERS_REQUIRED`, listing exactly
  how many serials are needed vs. supplied. The API additionally exposes
  `GET /api/v1/products/{id}/available-serials?warehouse_id=X` so the front-end can present a picker
  rather than requiring the user to already know valid serial numbers.
- **Changing `costing_method` on a product that already has stock.** Hard-blocked (`409
  COSTING_METHOD_LOCKED_WITH_STOCK`) regardless of who requests it (human or AI) — switching FIFO to
  weighted-average (or vice versa) mid-stream would require re-deriving historical valuation layers
  retroactively, which the platform never does to posted/existing data. The only path is: sell down
  to zero stock, then change the method, then resume purchasing.
- **Two companies both scanning the same manufacturer barcode.** Fully supported by design —
  `product_barcodes` uniqueness is scoped to `(company_id, barcode_value)`, so Company A's "Coca-Cola
  330ml can" and Company B's identical product with the identical EAN-13 barcode coexist as two
  independent `products` rows with no cross-tenant collision or leakage.
- **A price list row's `valid_from`/`valid_to` window overlaps another row for the same product at
  the same `min_quantity`.** Prevented by the partial unique index
  `ux_price_list_items_scope`, which includes `valid_from` in its key — but overlapping (not
  identical) date ranges are **not** caught by that index alone, since it is a range-overlap problem,
  not an equality problem. `PriceListService::create()` runs an explicit
  `&&` (range-overlap operator) check via a `daterange(valid_from, valid_to)` computed comparison
  before insert and returns `409 OVERLAPPING_PRICE_WINDOW` if a conflict is found, rather than
  silently creating ambiguous, order-dependent pricing.
- **A bundle whose component product is discontinued or has insufficient stock.** The bundle itself
  remains sellable (a `bundle` has no `inventory_items` row of its own), but at the moment of sale,
  Sales' availability check walks `product_bundle_items` and validates each component's
  `quantity_available` against the bundle-sale quantity; if any component is short, the sale is
  blocked with `409 BUNDLE_COMPONENT_INSUFFICIENT_STOCK` naming the specific short component(s) rather
  than a generic "out of stock" on the bundle.
- **A batch-tracked product's batch expires while `quantity_remaining > 0`.** The nightly expiry job
  transitions the batch's `status` to `'expired'`; the batch's remaining quantity is **not**
  automatically zeroed or written off — that requires a human-initiated `stock_adjustments` entry
  (Inventory-owned) referencing the expired batch, because writing off inventory value is itself a
  financial event requiring the same approval chain as any other stock adjustment, not something
  Products or a background job should do unilaterally.
- **Attempting to archive a product with zero visible stock but an in-flight, unposted stock
  transfer.** Archive validation checks not just `inventory_items.quantity_on_hand = 0` but also the
  absence of any open (`status NOT IN ('completed','cancelled')`) `stock_transfers`/
  `stock_transfer_items` row referencing the product, returning `409
  PRODUCT_HAS_OPEN_LOGISTICS` if one exists — stock "in transit" is not the same as stock that has
  fully left the ledger.
- **Merging two products where both have open, unfulfilled sales orders.** `ProductService::merge()`
  re-points `sales_order_items.product_id` from the merged-away product to the surviving product for
  **open** orders (so fulfillment can continue against the surviving SKU) but explicitly does **not**
  alter any already-posted `invoice_items`/`journal_lines` — historical financial records keep
  referencing whichever `product_id` was actually transacted at the time, even after a merge, per the
  platform's immutability rule; the surviving product's profitability report for that historical
  period will therefore show a `product_id` that technically no longer exists as an active catalog
  entry, which the UI resolves by displaying "product merged into {surviving SKU}" rather than a
  broken reference.
- **A `subscription`-type product's `recurrence_interval` is changed after active subscriptions
  exist.** Blocked (`409 RECURRENCE_LOCKED_WITH_ACTIVE_SUBSCRIPTIONS`) if any `sales_orders` with
  `recurrence_status = 'active'` reference the product — changing billing cadence must go through
  Sales' subscription-amendment flow (which creates a new subscription term rather than silently
  altering the product definition every existing subscriber is anchored to).
- **Currency mismatch between a product's `currency_code` and the price list it's being added to.**
  `PriceListService` performs currency conversion at the moment of resolution (see Pricing
  precedence chain) rather than rejecting the combination outright — a KWD-denominated product can
  have a USD-denominated price-list row, resolved via the platform's exchange-rate service at
  transaction time, because Gulf distributors frequently import in one currency and sell in another.
- **Bulk import file contains a SKU that already exists.** Governed by an explicit `on_conflict`
  import option (`skip`|`update`|`error`), defaulting to `skip` with the row surfaced in the
  `errors_file_url` output — imports never silently overwrite existing product data unless the
  operator explicitly opts into `update` mode.
- **An AI classification suggestion is generated for a product that a human edits before the
  suggestion is reviewed.** The suggestion is invalidated (`status` forced to `expired`) by a
  Service-layer check comparing the suggestion's `created_at` against the product's `updated_at` at
  review time — a suggestion computed against stale field values is never applied, even if a
  reviewer clicks "approve" on a since-superseded suggestion; the API returns `409
  SUGGESTION_STALE` and prompts a fresh classification run instead.

# Future Improvements

- **Normalized `brands` table.** Once catalog volume and cross-company brand-matching needs justify
  it, replace the free-text `products.brand` column with a proper `brands` lookup table
  (`id, company_id, name_en, name_ar, logo_attachment_id, website_url`), migrating existing free-text
  values via the same AI Duplicate-Detection-style normalization already used for brand-spelling
  suggestions, so the migration itself is largely AI-assisted rather than a manual data-cleanup
  project.
- **Time-range partitioning for `product_price_history` and product-related `audit_logs` volume.**
  As flagged under Performance, once a company's historical row count in either table crosses roughly
  10 million rows, introduce monthly/quarterly range partitioning keyed on `created_at`/`effective_from`
  to keep index sizes and vacuum times bounded, with older partitions optionally moved to cheaper
  storage tiers.
- **Autonomous (not suggest-only) dynamic pricing, opt-in per company.** Once a company has a proven
  track record of consistently approving the Price Optimization AI's suggestions within tight
  floor/ceiling guardrails, offer an opt-in "auto-apply suggestions within ±3% of current price"
  mode — still logged identically to a human-approved change, still reversible, but removing the
  human click for the narrowest, lowest-risk band of adjustments. This remains gated behind explicit
  company-level opt-in and a hard ceiling on the autonomy band; full unrestricted autonomous pricing
  is explicitly out of scope indefinitely, consistent with the platform-wide rule that AI never
  bypasses human approval for financially consequential actions without an explicit, bounded,
  revocable delegation.
- **Multi-level Bill of Materials (BOM) beyond the current flat bundle model.** `product_bundle_items`
  today models a single-level bundle (a bundle of simple components). A future Manufacturing module
  extension would need multi-level BOMs (a `finished_goods` item built from `semi_finished_goods`
  items, themselves built from `raw_material`), which this document intentionally does not attempt to
  model — it is flagged here as the natural next step, to be owned by a dedicated Manufacturing/BOM
  module document rather than folded into Products' `product_bundle_items` table, which should remain
  scoped to simple sales bundles.
- **Vision-based catalog onboarding.** Extend the AI Product Classification agent to ingest a photo
  of a physical shelf or a supplier's printed catalog page (rather than requiring structured
  spreadsheet import) and emit a batch of draft products with OCR'd names, prices, and barcode
  candidates — a natural extension of the existing Document AI/OCR Agent capability already used for
  spec-sheet attachment parsing, packaged as a dedicated "photo-to-catalog" import mode.
  alongside the existing spreadsheet-based `/products/import` flow rather than replacing it.
- **Cross-company benchmark insights (opt-in, anonymized).** For companies that opt in, surface
  anonymized, aggregated benchmarks (e.g., "your margin on this product category is in the bottom
  quartile of similar Gulf SMEs") computed from de-identified, aggregated `product_price_history` and
  profitability data across the QAYD tenant base, strictly never exposing another company's raw
  product names, prices, or volumes — only statistical aggregates above a minimum-tenant-count
  threshold to prevent re-identification of any single competitor's pricing.
- **Localization beyond Arabic/English.** The bilingual `name_en`/`name_ar` pattern generalizes
  cleanly to a full `product_translations (product_id, locale, name, description)` child table if
  QAYD expands beyond the Gulf into markets requiring French, Urdu, Hindi, or other localized catalog
  presentation, without touching the core `products` table's identity or financial columns.

# End of Document
