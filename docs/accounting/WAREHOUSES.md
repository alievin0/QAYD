# Warehouse Management — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Warehouses
---

# Purpose

The Warehouse Management submodule is the physical and logical backbone of QAYD's Inventory domain.
It defines *where* stock physically exists inside a company's operations — from the top-level
warehouse entity down to the individual storage bin — and exposes that structure to every module
that needs to know a location: Inventory (`inventory_items`, `stock_movements`), Sales
(`deliveries`, `sales_order_items`), Purchasing (`goods_receipts`, `purchase_order_items`), and
Accounting (inventory valuation postings by location).

A warehouse in QAYD is not a single flat record. It is a hierarchical structure —
Warehouse → Zone → Area → Aisle → Row → Shelf → Bin — that lets a company model anything from a
single unmarked storeroom to a multi-million-item distribution center with barcode-scanned bin
locations, without forcing every company to configure the full depth. Companies that do not need
granular bin tracking can stop modeling at the Warehouse level; companies running a 3PL-grade
operation can model down to the bin and even track dimensions and weight capacity at every level.

This document specifies:
1. The taxonomy of warehouse types QAYD supports and the business behavior attached to each type.
2. The full physical hierarchy (structure) a warehouse can be decomposed into, and the fields that
   describe capacity, dimensions, and identity at each level.
3. The warehouse master record itself (the "Warehouse Profile") — the fields a company configures
   once per physical or virtual location.
4. The inventory-operational lifecycle that happens *inside* a warehouse (receiving, put-away,
   picking, packing, shipping, transfers, returns, adjustments, cycle counts, physical counts) —
   scoped here to the warehouse-structural side; the numeric stock-ledger side is owned by the
   Inventory module (`inventory_items`, `stock_movements`) and referenced, not duplicated.
5. Warehouse-to-warehouse, warehouse-to-branch, and warehouse-to-company stock transfers, including
   the mandatory human approval workflow and the accounting/inventory side effects each transfer
   produces.
6. Complete PostgreSQL DDL for the warehouse hierarchy tables.
7. The AI agents that operate on warehouse data, their autonomy levels, and their confidence
   handling.
8. Reports, the REST API surface, RBAC permissions, notifications, security posture, audit
   logging, performance considerations, edge cases, and a forward roadmap.

Warehouses hold stock *locations*. They do not hold stock *quantities* — that is the
responsibility of `inventory_items`, which stores a row per (product, warehouse, bin) combination
with on-hand, reserved, and available quantities. This document treats `inventory_items`,
`stock_movements`, `stock_transfers`, `stock_transfer_items`, `stock_counts`, `stock_count_lines`,
and `inventory_valuations` as tables owned by the Inventory module doc; here they are referenced by
name wherever a warehouse-level operation triggers them, but their own DDL is not re-declared.

# Vision

QAYD's warehouse model is designed so that a five-person retail company and a 500-employee
distribution operator configure the *same* schema at different depths, and the AI layer gets
smarter as more structure is captured.

The long-term vision has four pillars:

**1. One schema, infinite granularity.** A company starts with a single `warehouses` row and a
single implicit "default bin." As the business grows, it adds zones, then aisles, then explicit
bins, without a schema migration or a re-platform. Every stock-bearing table
(`inventory_items`, `stock_movements`) already has a nullable `bin_id`, so the depth a company
chooses today does not lock in tomorrow's ceiling.

**2. The warehouse graph is the AI's spatial memory.** Every AI agent that reasons about stock
(Inventory Manager, Forecast Agent, Treasury Manager judging working-capital tie-up) reasons over
the warehouse graph, not a flat quantity number. Picking Route Optimization needs aisle and shelf
adjacency; Stock Placement needs bin capacity and velocity class; Demand Forecasting needs
per-warehouse seasonality (a coastal branch warehouse behaves differently from a bonded transit
warehouse). The structure defined in this document is the substrate every one of those agents
reads.

**3. Warehouses are first-class accounting dimensions.** Every warehouse belongs to exactly one
company and, optionally, one branch. Inventory valuation, COGS, and shrinkage postings can all be
sliced by warehouse because `warehouse_id` (and, where meaningful, `bin_id`) rides along on the
stock movement and, transitively, on the journal line via the branch/cost-center dimensions
described in the platform's dimensional model.

**4. Movement between warehouses is a controlled financial event, not a UI convenience.** A
transfer between two warehouses — whether in the same branch, a different branch, or (in
multi-entity groups) a different company — is never a silent quantity edit. It is a documented,
approved, auditable transaction with its own lifecycle, its own accounting treatment (including
inter-company settlement where applicable), and its own AI-assisted recommendation layer that
proposes transfers before a human requests them.

# Warehouse Philosophy

QAYD treats a warehouse as **a bounded custodial unit**: a physical or virtual space over which a
single, identifiable responsibility (a manager, a cost center, a set of employees) is accountable
for the accuracy of what is recorded as being inside it. This has concrete design consequences:

- **Every unit of stock is inside exactly one warehouse at every instant.** There is no
  "in-transit-and-nowhere" state that lasts longer than the duration of a single transfer document;
  in-transit stock is modeled as belonging to a `transit` type warehouse (or a transfer's
  in-transit sub-state) so that a trial balance of inventory value always accounts for 100% of
  recorded stock.
- **Structure is optional but consistent.** A company may choose not to model zones/aisles/bins at
  all (retail-store simplicity) or may model all six sub-levels (distribution-center rigor). The
  schema never requires a level to exist, but when a level does exist, its children must belong to
  it — a bin cannot exist without a shelf if shelves are being used at all in that warehouse, but a
  warehouse that never created a shelf record can put bins directly under an aisle (a `shelf_id`
  on `warehouse_bins` is nullable for exactly this reason; see Database Design).
- **A warehouse's type constrains, but does not dictate, its behavior.** Type (`Main`, `Retail
  Store`, `Cold Storage`, etc.) drives defaults — a `Cold Storage` warehouse defaults to
  temperature-monitoring fields and shorter cycle-count intervals; a `Virtual` warehouse defaults
  to zero physical structure and is used purely as a non-physical stock bucket (e.g. "damaged
  awaiting write-off," "consigned to customer"). Types never hard-block a feature; they set sane
  defaults an administrator can override.
- **Capacity is declared, not inferred.** QAYD does not attempt to compute warehouse capacity from
  product dimensions alone in v1. Capacity (volume, weight, bin-count) is declared at each
  structural level by the company and used by the AI layer as a *constraint*, while product
  dimensions (owned by the Products module: `products.length_cm`, `width_cm`, `height_cm`,
  `weight_kg`) are used to *check* a proposed placement against that constraint.
- **The warehouse hierarchy is a tree, never a graph.** Every child level has exactly one parent.
  This keeps path resolution (e.g. rendering "WH-01 / Zone A / Aisle 3 / Shelf 12 / Bin 004" as a
  single scannable location code) a simple recursive walk rather than a graph traversal, which
  matters at barcode-scan latency during picking.
- **Movement across the boundary of a warehouse is always a `stock_transfer`; movement within it
  is always a `stock_movement`.** This single rule disambiguates every inventory event in the
  system: relocating a pallet from Aisle 3 to Aisle 7 inside WH-01 is a `stock_movements` row with
  `movement_type = 'internal_relocation'`; moving the same pallet to WH-02 is a `stock_transfers`
  document that itself generates the underlying `stock_movements` rows (an outbound movement at
  the source warehouse, an inbound movement at the destination) once received.

# Warehouse Types

QAYD's `warehouses.type` enum recognizes ten types. Each type is a first-class value with distinct
default behavior; the type is set at creation and can be changed by a user holding
`warehouse.update`, subject to a warning if stock is on hand (changing type does not move stock,
but some type changes — e.g. `Main` → `Virtual` — are blocked while `on_hand_quantity > 0` across
any `inventory_items` row for that warehouse).

| Type | Code | Description | Default Behavior |
|---|---|---|---|
| Main | `main` | The company's primary, usually largest, warehouse. Typically one per company or per major branch. | Full structural depth enabled by default (zones through bins). Default destination for goods receipts with no destination specified. Included in all consolidated reports by default. |
| Regional | `regional` | A warehouse serving a geographic region, feeding smaller retail/distribution points downstream. | Structural depth enabled. Typically the source side of scheduled replenishment transfers to Retail Store / Distribution Center warehouses in its region (region is captured via `warehouses.region_code` plus the branch's address). |
| Retail Store | `retail_store` | The stock room / back-of-house of a physical point of sale. | Structure usually shallow (Zone + Bin, or Bin only). Cycle count frequency defaults to weekly (higher shrinkage risk, customer-facing). Point-of-sale integrations reserve stock directly against this warehouse. |
| Distribution Center | `distribution_center` | A high-throughput hub optimized for cross-docking and outbound shipping rather than long-term storage. | Full structural depth. Picking Route Optimization AI agent is enabled by default. Put-away defaults to "flow-through" (short dwell time) placement strategy. |
| Transit | `transit` | A non-physical or semi-physical bucket representing stock that has left the source warehouse but has not yet been received at the destination. | Created automatically per active `stock_transfers` document, or as one persistent warehouse per company if the company opts into a single shared transit pool. Never appears in "available to sell" quantity; always counted in total on-hand for balance-sheet completeness. |
| Returns | `returns` | Holds stock returned by customers (via `credit_notes` / RMA flows) pending inspection/disposition. | Received stock defaults to a `quarantine` sub-status (see Edge Cases) and is excluded from available-to-promise until a quality decision (restock / scrap / return-to-vendor) is recorded. |
| Cold Storage | `cold_storage` | Temperature- and/or humidity-controlled storage for perishable or regulated goods. | Additional fields active: `temperature_min_c`, `temperature_max_c`, `humidity_min_pct`, `humidity_max_pct`. Cycle count interval defaults to daily for expiry-sensitive product batches (`product_batches.expiry_date`). Notifications fire on threshold excursions if an IoT/monitoring integration is attached (out of scope for v1, hook reserved). |
| Manufacturing | `manufacturing` | Stock staged for or consumed by a production process (raw materials in, finished/semi-finished goods out). | Two conceptual sub-areas modeled as Zones: raw-material staging and finished-goods staging. Reserved for future Manufacturing/BOM module integration; today it behaves as a standard warehouse that Inventory/Purchasing can receive into and Sales can ship from. |
| Virtual | `virtual` | A non-physical bucket for stock that exists on the books but not in a real location a picker can walk to (in-transit-by-carrier not otherwise tracked, consigned-out, damaged-awaiting-scrap, sample/marketing stock). | No structural depth (zones/aisles/bins are disabled in the UI for this type; `warehouse_bins` rows are not created). Excluded from physical/cycle count scheduling. |
| Third-Party (3PL) | `third_party` | A warehouse physically operated by an external logistics provider on the company's behalf. | Additional fields active: `operator_name`, `operator_contract_id` (FK to `procurement_contracts` where a 3PL is procured as a vendor), `operator_reference_code` (the 3PL's own warehouse code, used to reconcile their stock reports against QAYD's). Stock ownership remains with the company (it stays on QAYD's balance sheet); the 3PL is not a `vendors` counterparty for the stock itself, only for the service fee. |

Type-specific behavior is implemented as configuration defaults on the `warehouses` row
(`default_structural_depth`, `default_count_frequency_days`, `is_available_to_promise`,
`requires_temperature_monitoring`) rather than as hard-coded branches in application logic, so an
administrator can override any default per warehouse without a schema change.

# Warehouse Structure

A warehouse decomposes into six optional, strictly nested levels. Each level is a table of its own
(full DDL in **Database Design**); each level carries an internal `code` (unique within its
parent), a bilingual `name_en`/`name_ar`, a `status`, capacity fields, and dimension fields.

### Hierarchy

```
Warehouse
  └── Zone            (e.g. "Zone A — Ambient", "Zone B — Cold")
        └── Area       (e.g. "Receiving Area", "Bulk Storage Area", "Fast-Pick Area")
              └── Aisle          (e.g. "Aisle 01")
                    └── Row      (e.g. "Row 03")
                          └── Shelf   (e.g. "Shelf 04", vertical level within the row)
                                └── Bin   (e.g. "Bin 012" — the atomic storage location)
```

Every level below Warehouse is optional in the sense that a company may skip straight from a
Warehouse to Bins (with no Zone/Area/Aisle/Row/Shelf rows at all — `warehouse_bins.aisle_id`,
`row_id`, and `shelf_id` are nullable and only `warehouse_id` is mandatory on a bin), or may skip
any single intermediate level (e.g. Zone → Aisle directly, with no Area row, because
`warehouse_aisles.area_id` is nullable while `zone_id` is not — a company that wants Areas must
still pass through Zones, since Area's *mandatory* parent is Zone; the schema enforces strict
nesting only where a table's FK is declared NOT NULL, and leaves it nullable everywhere skipping is
a supported pattern).

### Zones

The largest subdivision inside a warehouse — usually corresponds to a distinct operational or
environmental purpose: ambient vs. cold, bulk vs. fast-pick, hazardous-materials vs. general,
inbound-staging vs. outbound-staging. A zone has its own capacity envelope (used to prevent, e.g.,
over-allocating cold storage capacity across multiple product categories) and can be temperature-
controlled independently of the warehouse's overall type (a `Main` warehouse can still have one
cold `Zone`).

### Areas

A functional subdivision inside a zone: Receiving, Put-Away Staging, Bulk Storage, Fast-Pick,
Returns Processing, Packing, Shipping Staging, Quality Hold. Areas are the level at which
Picking Route Optimization typically groups its search space (a picker assigned a wave is routed
through areas in a sequence that minimizes backtracking) and at which Space Utilization AI reports
per-function fill rate (e.g. "Fast-Pick Area is at 96% capacity; Bulk Storage is at 41%").

### Aisles

A linear corridor of storage inside an area, the level at which walking/travel-time optimization
usually starts to matter. Aisles carry a `sequence_number` used to compute physical adjacency for
routing (Aisle 3 is adjacent to Aisle 2 and Aisle 4 by default, unless `is_reversed` or a custom
`adjacency_override` map is set for irregular layouts).

### Rows

A position along an aisle (think of an aisle as a corridor with racking on both sides; a Row is
one racking bay along that corridor, identified by a `side` field: `left` or `right`, plus a
`position_number` along the aisle).

### Shelves

A vertical level within a row (Shelf 1 at floor level, Shelf 2 above it, etc.). Shelves carry
`height_cm` and `max_weight_kg` — the two dimensions most often violated in real warehouses
(over-height product that will not physically fit, over-weight loads that will collapse racking) —
and the Stock Placement Suggestions AI agent checks both before proposing a put-away.

### Bins

The atomic, addressable storage location — the only level `inventory_items.bin_id` and
`stock_movements.bin_id` reference directly. A bin has a `bin_type` (`pallet`, `shelf_unit`,
`floor`, `pick_face`, `bulk`) that determines its default capacity units (pallet positions vs.
individual-unit pick faces vs. cubic volume) and a `barcode` used for scan-driven receiving,
put-away, and picking. A bin is optionally restricted to a single product
(`restricted_product_id`) — common for high-velocity SKUs given a dedicated pick face — or open to
mixed SKUs.

### Storage Locations

"Storage Location" is the general term for *any* level at which stock can be considered "located"
for reporting purposes — in QAYD this always resolves to a Bin if bins are modeled for that
warehouse, or to the lowest modeled level otherwise (Shelf, Row, Aisle, Area, Zone, or the
Warehouse itself if no substructure exists at all). The system computes and caches a human-readable
`location_path` (e.g. `WH-01/ZA/AR-RCV/A03/R-L02/S02/B012`) on `inventory_items` for fast display,
recomputed via a database trigger whenever the underlying bin (or the lowest modeled ancestor) is
reassigned.

### Capacity

Every structural level carries three optional capacity fields, all nullable (capacity constraints
are opt-in):

- `capacity_volume_m3 NUMERIC(12,4)` — total usable volume.
- `capacity_weight_kg NUMERIC(12,4)` — total usable weight.
- `capacity_units INTEGER` — a count-based capacity where volume/weight are impractical (e.g. "this
  bin holds 4 pallet positions" or "this shelf holds 200 pick-face units").

A level's capacity is never auto-derived by summing its children's capacities — a Zone's declared
capacity might legitimately be less than the sum of its Areas (shared aisles, safety clearance) and
the system does not attempt to reconcile the two; it only warns via the Space Utilization AI report
when a physical inconsistency (children's declared capacity exceeds parent's) is detected.

### Dimensions

Physical dimension fields (`length_cm`, `width_cm`, `height_cm NUMERIC(10,2)`) exist on Aisles,
Rows, Shelves, and Bins, used primarily for two purposes: (1) Stock Placement Suggestions checking
whether a specific product's carton/pallet physically fits a candidate bin, and (2) computing
travel distance for Picking Route Optimization when GPS/indoor-positioning is not available (a
simple Manhattan-distance estimate over aisle/row sequence numbers and known aisle widths).

# Warehouse Profile

The Warehouse Profile is the master record every other structural and operational table hangs
off. It is created once per physical or virtual location and rarely changes except for
administrative edits (manager reassignment, working-hours updates, status changes).

| Field | Type | Notes |
|---|---|---|
| Warehouse Code | `VARCHAR(30)` | Company-unique, human-assigned (e.g. `WH-KWT-01`). Immutable after creation if any `inventory_items` row references the warehouse (renaming the code, not the id, is a controlled operation — see Edge Cases). |
| Name | `name_en VARCHAR(150)`, `name_ar VARCHAR(150)` | Bilingual per platform convention. |
| Description | `description TEXT` | Free text; internal notes on layout, access instructions, special handling. |
| Company | `company_id BIGINT NOT NULL` | Standard tenant column. A warehouse belongs to exactly one company; it can never be shared across companies (cross-company stock use, e.g. a shared 3PL facility, is modeled as two separate `warehouses` rows, one per company, both pointing at the same `operator_reference_code`). |
| Branch | `branch_id BIGINT NULL` | Optional. A warehouse can be company-wide (no branch) or scoped to one branch. Branch-scoped warehouses are the default for `Retail Store` type. |
| Manager | `manager_user_id BIGINT NULL REFERENCES users(id)` | The user accountable for physical accuracy (cycle-count sign-off, discrepancy resolution). Drives default notification routing. |
| Address | `address_line1`, `address_line2 VARCHAR(255)`, `city VARCHAR(100)`, `state_province VARCHAR(100)`, `postal_code VARCHAR(20)`, `country_code CHAR(2)` | Standard postal address fields; `country_code` is ISO 3166-1 alpha-2. |
| GPS Coordinates | `latitude NUMERIC(10,7)`, `longitude NUMERIC(10,7)` | Used for map display, distance-based transfer-cost estimation, and delivery routing hand-off to Sales' `deliveries`. |
| Timezone | `timezone VARCHAR(50)` | IANA tz name (e.g. `Asia/Kuwait`). Governs `working_hours` interpretation and the timestamps shown to warehouse staff vs. the company's fiscal timezone. |
| Working Hours | `working_hours JSONB` | Per-weekday open/close, e.g. `{"sun":{"open":"08:00","close":"18:00"}, "mon": {...}, ..., "fri": null}` (null = closed). Consulted by Receiving/Shipping scheduling and by AI Receiving Prediction. |
| Status | `status warehouse_status_enum NOT NULL DEFAULT 'active'` | `active`, `inactive`, `under_maintenance`, `closed`. `closed` blocks new receiving/shipping/transfers-in but does not block outbound transfers needed to empty the warehouse. |
| Default Currency | `default_currency_code CHAR(3) NOT NULL` | ISO 4217. Used when the warehouse is used for valuation reporting in a currency other than the company's base currency (multi-currency group reporting); defaults to the company's base currency. |
| Documents | Linked via `attachments` (polymorphic, `attachable_type='warehouse'`) | Layout diagrams, fire-safety certificates, lease agreements, 3PL contracts. |
| Attachments | Same as Documents | Photos of the physical site, insurance certificates. QAYD does not distinguish "documents" from "attachments" at the data-model level — both are rows in the foundation `attachments` table; the distinction in this profile list is purely UI grouping (Documents = PDFs/contracts tab, Attachments = photos/media tab). |

Additional profile fields not in the requested list but required for the type-specific behavior
described earlier: `type warehouse_type_enum NOT NULL`, `region_code VARCHAR(20)`,
`is_available_to_promise BOOLEAN NOT NULL DEFAULT true`, `requires_temperature_monitoring
BOOLEAN NOT NULL DEFAULT false`, `temperature_min_c NUMERIC(5,2)`, `temperature_max_c
NUMERIC(5,2)`, `default_count_frequency_days SMALLINT NOT NULL DEFAULT 90`, `operator_name
VARCHAR(150)`, `operator_contract_id BIGINT REFERENCES procurement_contracts(id)`,
`operator_reference_code VARCHAR(50)`, plus the standard tenant columns (`created_by`,
`updated_by`, `created_at`, `updated_at`, `deleted_at`) and `tags JSONB`, `custom_fields JSONB`
per the platform's bilingual/enterprise field convention.

# Inventory Operations

This section defines the warehouse-structural side of each inventory operation — where it touches
the warehouse hierarchy, which structural fields it reads or writes, and which downstream tables
(owned by the Inventory module) it produces rows in. The numeric ledger mechanics
(`inventory_items.on_hand_quantity` math, weighted-average/FIFO costing) belong to the Inventory
module doc and are referenced, not restated.

### Receiving

Triggered by a `goods_receipts` row (Purchasing) or a `stock_transfers` row (inbound leg) reaching
status `received`. The receiving clerk (permission `inventory.receive`) scans or selects a
destination **Receiving Area** (a Warehouse's Area with `area_function = 'receiving'`); the system
creates a `stock_movements` row with `movement_type = 'receipt'`, `to_warehouse_id` set, and
`to_bin_id` NULL until put-away completes (interim location is the Receiving Area itself, tracked
via `to_area_id` on the movement row for Areas-only precision until a bin is assigned). A receipt
against a `Cold Storage` or temperature-controlled Zone requires the clerk to confirm the
temperature reading was within range at time of receipt (`stock_movements.temperature_reading_c`),
enforced client-side and re-validated server-side against the zone's declared range.

### Put Away

The act of moving received stock from the Receiving Area to its long-term Bin. Put-away can be
manual (clerk chooses a bin) or AI-suggested (Stock Placement Suggestions agent proposes a bin
given product velocity class, existing stock of the same SKU, and capacity). Put-away produces a
`stock_movements` row with `movement_type = 'putaway'`, source = Receiving Area, destination =
the chosen `bin_id`. Put-away is the point at which `inventory_items.bin_id` (or, for companies not
modeling bins, `inventory_items.area_id`/`aisle_id`, whichever is the lowest modeled level) is
first populated for that receipt line.

### Picking

The act of retrieving stock from its bin to fulfill an outbound document (`sales_order_items` via
a `deliveries` row, or the outbound leg of a `stock_transfers` row). Picking consumes a
`stock_reservations` row (created when the sales order was confirmed) and produces a
`stock_movements` row with `movement_type = 'pick'`, source = the specific `bin_id`, destination =
a **Packing Area**. Multi-bin picks for a single line (partial quantities spread across bins) are
supported: one `stock_movements` row per source bin. Pick sequencing (which bin to pick from first
when multiple bins hold the same SKU) defaults to FEFO (first-expiry-first-out) when
`product_batches.expiry_date` is tracked, otherwise FIFO by receipt date, and can be overridden by
the Picking Route Optimization AI agent's suggested sequence.

### Packing

The act of consolidating picked items into shippable units (cartons, pallets) inside a **Packing
Area**. Packing does not move warehouse-structural location (items remain logically "at" the
Packing Area) but does write a `stock_movements` row with `movement_type = 'pack'` when items move
between multiple picking waves' staging sub-areas, and it is the point at which a shipment's
`package_count`, `total_weight_kg`, and `total_volume_m3` are computed and attached to the
`deliveries` row for carrier hand-off.

### Shipping

The act of releasing packed stock out of the warehouse's custody entirely, closing the `deliveries`
row and producing the final `stock_movements` row with `movement_type = 'shipment'`,
`from_warehouse_id` set, `to_warehouse_id` NULL (stock leaves QAYD's location model; ownership
transfer is handled by Sales' invoice/revenue-recognition logic, out of scope here). Shipping from
a `Distribution Center` type warehouse defaults to same-day cross-dock behavior (no put-away step
required — receiving can flow directly to a Shipping Staging Area).

### Transfers

Movement of stock across a warehouse boundary. Fully specified in **Warehouse Transfers** below.

### Returns

Inbound stock arriving via an RMA (linked to a `credit_notes` row). Always received into a
`Returns`-type warehouse or a Returns Processing Area inside a general warehouse, with a mandatory
`quarantine` disposition until a quality decision (`restock`, `scrap`, `return_to_vendor`) is
recorded by a user holding `inventory.returns.inspect`.

### Adjustments

A manual correction to recorded quantity or location without a corresponding physical
receipt/shipment/transfer document — used for shrinkage write-offs, found-stock corrections, or
damage write-downs. Produces a `stock_adjustments` row (Inventory module) plus a `stock_movements`
row with `movement_type = 'adjustment'`. Adjustments always require a `reason_code` and, above a
company-configured value threshold, a second approver (`inventory.adjust.approve`) — this is the
one inventory operation for which QAYD enforces a maker-checker control at the transaction level
regardless of role, because it is the most common vector for both honest shrinkage and inventory
fraud.

### Cycle Count

A scheduled, partial, ongoing count of a subset of bins/SKUs (e.g. "count all bins in Aisle 3
today"), driven by `default_count_frequency_days` on the warehouse or by ABC-classification-aware
scheduling (fast-moving A-items counted weekly, slow C-items quarterly). Produces a `stock_counts`
row with `count_type = 'cycle'` and one `stock_count_lines` row per bin/SKU counted, each comparing
`expected_quantity` (system) to `counted_quantity` (physical). Variances beyond a configurable
tolerance auto-generate a `stock_adjustments` proposal requiring approval before posting.

### Physical Count

A full, scheduled, warehouse-wide (or company-wide) stock count, typically performed at fiscal
year-end or on a periodic full-stop basis. Produces a `stock_counts` row with `count_type =
'physical'`. Unlike cycle counts, a physical count can optionally place the entire warehouse in
`status = 'under_maintenance'` (or a dedicated `count_in_progress` sub-flag) to freeze all
receiving/picking/shipping for the duration, guaranteeing a clean snapshot. Variance resolution
follows the same adjustment-with-approval path as cycle counts, but at company-wide scale the
variance report itself is a first-class output (see **Reports** → Cycle Count Accuracy).

# Warehouse Transfers

A stock transfer moves inventory across a warehouse boundary. QAYD models three scopes, each with
identical mechanics but different accounting and approval consequences.

### Inter-Warehouse

Source and destination warehouses belong to the **same branch and same company**. This is the
simplest case: no inter-branch or inter-company accounting entries are generated (no journal entry
at all is required for a same-branch transfer — inventory value simply moves between two
`inventory_items` location buckets at the same valuation, since it stays within the same cost
center by default unless the two warehouses are explicitly assigned different `cost_center_id`
dimensions, in which case a zero-net, cost-center-reallocating journal entry is posted per the
Accounting Engine's dimensional rules).

### Inter-Branch

Source and destination warehouses belong to **different branches of the same company**. If the
branches are configured as separate cost centers/profit centers (common when branches report P&L
independently), the transfer posts a journal entry crediting the source branch's Inventory account
and debiting the destination branch's Inventory account at the transferred stock's current
weighted-average (or FIFO) cost — a value movement with no revenue/margin recognition, since
ownership stays inside the same legal company.

### Inter-Company

Source and destination warehouses belong to **different companies** (a multi-entity group where
both companies are registered under the same QAYD organization/tenant hierarchy, sharing the same
`ai_conversations`/AI context but strictly isolated data per platform rule 2). Because Company A
can never directly read or write Company B's data, an inter-company transfer is implemented as a
**paired transaction**: an outbound `stock_transfers` row in Company A generates a corresponding
sales-like disposal (typically a `bills`/`invoices` pair at an intercompany transfer price, or, if
the group elects "at cost" intercompany policy, a direct Inventory-to-Inventory-Receivable/Payable
journal entry) and an inbound `stock_transfers` row in Company B, linked by a shared
`intercompany_transfer_reference` UUID stored on both rows (never a direct FK across tenants,
preserving isolation). This is the only transfer scope that always produces a full journal entry
with revenue/COGS or receivable/payable effect, because a change of legal ownership has occurred.

### Approval Workflow

Every transfer, regardless of scope, follows the same state machine:

```
draft --> pending_approval --> approved --> in_transit --> received --> completed
                |                                 |
                +--> rejected                     +--> disputed --> (resolved -> received | reversed)
```

- **draft**: created by a user holding `inventory.transfer.create`; editable; no stock movement
  yet; `inventory_items` unaffected.
- **pending_approval**: submitted; visible to approvers per the permission table below. A transfer
  above a company-configured value threshold, or any inter-company transfer, always requires
  approval regardless of role (sensitive-operation rule from the platform's permission model).
  Below-threshold same-branch transfers may be configured by the company to auto-approve.
  Approval is required from a `inventory.transfer.approve` holder who did **not** create the
  transfer (four-eyes principle) unless the company has fewer than two eligible approvers, in
  which case self-approval is permitted and flagged in the audit log as a control exception.
- **approved**: an outbound `stock_movements` row (`movement_type = 'transfer_out'`) is created at
  the source warehouse; `inventory_items.reserved_quantity` at the source increases by the
  transferred quantity (stock is committed but not yet physically moved).
- **in_transit**: the outbound movement is confirmed physically dispatched. Source
  `inventory_items.on_hand_quantity` decreases; if the company uses a shared `transit`-type
  warehouse, the same quantity increases there; otherwise the quantity is tracked purely via the
  transfer document's own state until received.
- **received**: destination warehouse clerk confirms physical receipt (optionally scanning each
  `stock_transfer_items` line). An inbound `stock_movements` row (`movement_type = 'transfer_in'`)
  is created; destination `inventory_items.on_hand_quantity` increases.
- **completed**: system-set once every line is received and any required accounting entries (per
  scope, above) have posted successfully.
- **rejected**: an approver declines; no stock or accounting effect; transfer is terminal and
  immutable.
- **disputed**: destination reports a quantity/condition mismatch versus the transfer document.
  Requires resolution by a user holding `inventory.transfer.resolve`: either accept the physical
  count as received (auto-generates a `stock_adjustments` row for the variance) or reverse the
  transfer entirely.

### Tracking

Each `stock_transfers` row carries a `status`, `requested_ship_date`, `actual_ship_date`,
`expected_receipt_date`, `actual_receipt_date`, and a real-time `current_location` free-text/GPS
pair updatable by a carrier integration (reserved hook, not implemented in v1). Each
`stock_transfer_items` line tracks its own per-line quantity requested vs. shipped vs. received,
so a partial short-shipment does not block the rest of the transfer from completing.

### History

Every state transition writes an `audit_logs` row (who, when, old status, new status, reason where
applicable — rejection and dispute resolution always require a `reason` text). The full transfer
history (including every line-level scan event during shipping/receiving) is retained
indefinitely, soft-delete only, per the platform's "financial records are never hard-deleted" rule
— a `stock_transfers` row is a financial record because it can carry a journal-entry effect.

# Database Design

All tables follow the platform's standard tenant-table conventions: `id BIGINT GENERATED ALWAYS AS
IDENTITY PRIMARY KEY`, `company_id BIGINT NOT NULL REFERENCES companies(id)` (indexed),
`branch_id BIGINT NULL REFERENCES branches(id)`, `created_by`/`updated_by BIGINT NULL REFERENCES
users(id)`, `created_at`/`updated_at TIMESTAMPTZ NOT NULL DEFAULT now()`, `deleted_at TIMESTAMPTZ
NULL`. These are declared explicitly below rather than assumed, per the requirement that this
document be self-sufficient for implementation.

### Enums

```sql
CREATE TYPE warehouse_type_enum AS ENUM (
    'main', 'regional', 'retail_store', 'distribution_center', 'transit',
    'returns', 'cold_storage', 'manufacturing', 'virtual', 'third_party'
);

CREATE TYPE warehouse_status_enum AS ENUM ('active', 'inactive', 'under_maintenance', 'closed');

CREATE TYPE structural_status_enum AS ENUM ('active', 'inactive', 'blocked', 'full');

CREATE TYPE bin_type_enum AS ENUM ('pallet', 'shelf_unit', 'floor', 'pick_face', 'bulk');

CREATE TYPE area_function_enum AS ENUM (
    'receiving', 'putaway_staging', 'bulk_storage', 'fast_pick',
    'returns_processing', 'packing', 'shipping_staging', 'quality_hold', 'general'
);

CREATE TYPE rack_side_enum AS ENUM ('left', 'right', 'none');
```

### Table: `warehouses`

```sql
CREATE TABLE warehouses (
    id                              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                      BIGINT NOT NULL REFERENCES companies(id),
    branch_id                       BIGINT NULL REFERENCES branches(id),
    code                            VARCHAR(30)  NOT NULL,
    name_en                         VARCHAR(150) NOT NULL,
    name_ar                         VARCHAR(150) NOT NULL,
    description                     TEXT NULL,
    type                            warehouse_type_enum NOT NULL DEFAULT 'main',
    region_code                     VARCHAR(20) NULL,
    manager_user_id                 BIGINT NULL REFERENCES users(id),
    address_line1                   VARCHAR(255) NULL,
    address_line2                   VARCHAR(255) NULL,
    city                            VARCHAR(100) NULL,
    state_province                  VARCHAR(100) NULL,
    postal_code                     VARCHAR(20)  NULL,
    country_code                    CHAR(2) NULL,
    latitude                        NUMERIC(10,7) NULL,
    longitude                       NUMERIC(10,7) NULL,
    timezone                        VARCHAR(50) NOT NULL DEFAULT 'UTC',
    working_hours                   JSONB NOT NULL DEFAULT '{}'::jsonb,
    status                          warehouse_status_enum NOT NULL DEFAULT 'active',
    default_currency_code           CHAR(3) NOT NULL,
    is_available_to_promise         BOOLEAN NOT NULL DEFAULT true,
    requires_temperature_monitoring BOOLEAN NOT NULL DEFAULT false,
    temperature_min_c               NUMERIC(5,2) NULL,
    temperature_max_c               NUMERIC(5,2) NULL,
    humidity_min_pct                NUMERIC(5,2) NULL,
    humidity_max_pct                NUMERIC(5,2) NULL,
    default_structural_depth        SMALLINT NOT NULL DEFAULT 6,
    default_count_frequency_days    SMALLINT NOT NULL DEFAULT 90,
    operator_name                   VARCHAR(150) NULL,
    operator_contract_id            BIGINT NULL REFERENCES procurement_contracts(id),
    operator_reference_code         VARCHAR(50) NULL,
    capacity_volume_m3               NUMERIC(14,4) NULL,
    capacity_weight_kg               NUMERIC(14,4) NULL,
    capacity_units                   INTEGER NULL,
    tags                             JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields                    JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by                       BIGINT NULL REFERENCES users(id),
    updated_by                       BIGINT NULL REFERENCES users(id),
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                       TIMESTAMPTZ NULL,

    CONSTRAINT uq_warehouses_company_code UNIQUE (company_id, code),
    CONSTRAINT chk_warehouses_temp_range
        CHECK (temperature_min_c IS NULL OR temperature_max_c IS NULL
               OR temperature_min_c <= temperature_max_c),
    CONSTRAINT chk_warehouses_humidity_range
        CHECK (humidity_min_pct IS NULL OR humidity_max_pct IS NULL
               OR humidity_min_pct <= humidity_max_pct),
    CONSTRAINT chk_warehouses_capacity_nonneg
        CHECK (capacity_volume_m3 IS NULL OR capacity_volume_m3 >= 0)
);

CREATE INDEX idx_warehouses_company_id   ON warehouses (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_warehouses_branch_id    ON warehouses (branch_id)  WHERE deleted_at IS NULL;
CREATE INDEX idx_warehouses_type         ON warehouses (company_id, type);
CREATE INDEX idx_warehouses_status       ON warehouses (company_id, status);
CREATE INDEX idx_warehouses_manager      ON warehouses (manager_user_id);
```

### Table: `warehouse_zones`

```sql
CREATE TABLE warehouse_zones (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT NULL REFERENCES branches(id),
    warehouse_id      BIGINT NOT NULL REFERENCES warehouses(id),
    code              VARCHAR(30)  NOT NULL,
    name_en           VARCHAR(150) NOT NULL,
    name_ar           VARCHAR(150) NOT NULL,
    description       TEXT NULL,
    is_temperature_controlled BOOLEAN NOT NULL DEFAULT false,
    temperature_min_c NUMERIC(5,2) NULL,
    temperature_max_c NUMERIC(5,2) NULL,
    status            structural_status_enum NOT NULL DEFAULT 'active',
    capacity_volume_m3 NUMERIC(14,4) NULL,
    capacity_weight_kg NUMERIC(14,4) NULL,
    capacity_units     INTEGER NULL,
    sequence_number    SMALLINT NOT NULL DEFAULT 1,
    tags               JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields       JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,

    CONSTRAINT uq_warehouse_zones_wh_code UNIQUE (warehouse_id, code)
);

CREATE INDEX idx_wh_zones_company_id   ON warehouse_zones (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_zones_warehouse_id ON warehouse_zones (warehouse_id) WHERE deleted_at IS NULL;
```

### Table: `warehouse_areas`

```sql
CREATE TABLE warehouse_areas (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    branch_id      BIGINT NULL REFERENCES branches(id),
    warehouse_id   BIGINT NOT NULL REFERENCES warehouses(id),
    zone_id        BIGINT NOT NULL REFERENCES warehouse_zones(id),
    code           VARCHAR(30)  NOT NULL,
    name_en        VARCHAR(150) NOT NULL,
    name_ar        VARCHAR(150) NOT NULL,
    description    TEXT NULL,
    area_function  area_function_enum NOT NULL DEFAULT 'general',
    status         structural_status_enum NOT NULL DEFAULT 'active',
    capacity_volume_m3 NUMERIC(14,4) NULL,
    capacity_weight_kg NUMERIC(14,4) NULL,
    capacity_units      INTEGER NULL,
    sequence_number     SMALLINT NOT NULL DEFAULT 1,
    tags                JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by           BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,

    CONSTRAINT uq_warehouse_areas_zone_code UNIQUE (zone_id, code)
);

CREATE INDEX idx_wh_areas_company_id ON warehouse_areas (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_areas_warehouse  ON warehouse_areas (warehouse_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_areas_zone       ON warehouse_areas (zone_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_areas_function   ON warehouse_areas (warehouse_id, area_function);
```

### Table: `warehouse_aisles`

```sql
CREATE TABLE warehouse_aisles (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    warehouse_id    BIGINT NOT NULL REFERENCES warehouses(id),
    zone_id         BIGINT NOT NULL REFERENCES warehouse_zones(id),
    area_id         BIGINT NULL REFERENCES warehouse_areas(id),
    code            VARCHAR(30)  NOT NULL,
    name_en         VARCHAR(150) NOT NULL,
    name_ar         VARCHAR(150) NOT NULL,
    sequence_number SMALLINT NOT NULL,
    length_cm       NUMERIC(10,2) NULL,
    width_cm        NUMERIC(10,2) NULL,
    height_cm       NUMERIC(10,2) NULL,
    status          structural_status_enum NOT NULL DEFAULT 'active',
    capacity_volume_m3 NUMERIC(14,4) NULL,
    capacity_weight_kg NUMERIC(14,4) NULL,
    capacity_units      INTEGER NULL,
    tags                JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by           BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,

    CONSTRAINT uq_warehouse_aisles_wh_code UNIQUE (warehouse_id, code)
);

CREATE INDEX idx_wh_aisles_company_id ON warehouse_aisles (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_aisles_warehouse  ON warehouse_aisles (warehouse_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_aisles_zone       ON warehouse_aisles (zone_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_aisles_area       ON warehouse_aisles (area_id) WHERE deleted_at IS NULL;
```

### Table: `warehouse_rows`

```sql
CREATE TABLE warehouse_rows (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    warehouse_id    BIGINT NOT NULL REFERENCES warehouses(id),
    aisle_id        BIGINT NOT NULL REFERENCES warehouse_aisles(id),
    code            VARCHAR(30)  NOT NULL,
    name_en         VARCHAR(150) NOT NULL,
    name_ar         VARCHAR(150) NOT NULL,
    side            rack_side_enum NOT NULL DEFAULT 'none',
    position_number SMALLINT NOT NULL,
    status          structural_status_enum NOT NULL DEFAULT 'active',
    capacity_volume_m3 NUMERIC(14,4) NULL,
    capacity_weight_kg NUMERIC(14,4) NULL,
    capacity_units      INTEGER NULL,
    tags                JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by           BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,

    CONSTRAINT uq_warehouse_rows_aisle_code UNIQUE (aisle_id, code)
);

CREATE INDEX idx_wh_rows_company_id ON warehouse_rows (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_rows_warehouse  ON warehouse_rows (warehouse_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_rows_aisle      ON warehouse_rows (aisle_id) WHERE deleted_at IS NULL;
```

### Table: `warehouse_shelves`

```sql
CREATE TABLE warehouse_shelves (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    warehouse_id    BIGINT NOT NULL REFERENCES warehouses(id),
    row_id          BIGINT NOT NULL REFERENCES warehouse_rows(id),
    code            VARCHAR(30)  NOT NULL,
    name_en         VARCHAR(150) NOT NULL,
    name_ar         VARCHAR(150) NOT NULL,
    level_number    SMALLINT NOT NULL,
    height_cm       NUMERIC(10,2) NULL,
    width_cm        NUMERIC(10,2) NULL,
    depth_cm        NUMERIC(10,2) NULL,
    max_weight_kg   NUMERIC(10,2) NULL,
    status          structural_status_enum NOT NULL DEFAULT 'active',
    capacity_volume_m3 NUMERIC(14,4) NULL,
    capacity_units      INTEGER NULL,
    tags                JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by           BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,

    CONSTRAINT uq_warehouse_shelves_row_code UNIQUE (row_id, code)
);

CREATE INDEX idx_wh_shelves_company_id ON warehouse_shelves (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_shelves_warehouse  ON warehouse_shelves (warehouse_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_shelves_row        ON warehouse_shelves (row_id) WHERE deleted_at IS NULL;
```

### Table: `warehouse_bins`

```sql
CREATE TABLE warehouse_bins (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    warehouse_id         BIGINT NOT NULL REFERENCES warehouses(id),
    zone_id              BIGINT NULL REFERENCES warehouse_zones(id),
    area_id              BIGINT NULL REFERENCES warehouse_areas(id),
    aisle_id             BIGINT NULL REFERENCES warehouse_aisles(id),
    row_id               BIGINT NULL REFERENCES warehouse_rows(id),
    shelf_id             BIGINT NULL REFERENCES warehouse_shelves(id),
    code                 VARCHAR(30)  NOT NULL,
    name_en              VARCHAR(150) NULL,
    name_ar              VARCHAR(150) NULL,
    barcode              VARCHAR(80) NULL,
    bin_type             bin_type_enum NOT NULL DEFAULT 'shelf_unit',
    restricted_product_id BIGINT NULL REFERENCES products(id),
    length_cm            NUMERIC(10,2) NULL,
    width_cm             NUMERIC(10,2) NULL,
    height_cm             NUMERIC(10,2) NULL,
    max_weight_kg          NUMERIC(10,2) NULL,
    status                 structural_status_enum NOT NULL DEFAULT 'active',
    capacity_volume_m3      NUMERIC(14,4) NULL,
    capacity_units           INTEGER NULL,
    velocity_class           CHAR(1) NULL,
    tags                     JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields             JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by                BIGINT NULL REFERENCES users(id),
    updated_by                 BIGINT NULL REFERENCES users(id),
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                 TIMESTAMPTZ NULL,

    CONSTRAINT uq_warehouse_bins_wh_code UNIQUE (warehouse_id, code),
    CONSTRAINT chk_warehouse_bins_velocity CHECK (velocity_class IS NULL OR velocity_class IN ('A','B','C')),
    CONSTRAINT uq_warehouse_bins_barcode UNIQUE (company_id, barcode)
);

CREATE INDEX idx_wh_bins_company_id ON warehouse_bins (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_bins_warehouse  ON warehouse_bins (warehouse_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_bins_shelf      ON warehouse_bins (shelf_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wh_bins_barcode    ON warehouse_bins (barcode);
CREATE INDEX idx_wh_bins_product    ON warehouse_bins (restricted_product_id) WHERE restricted_product_id IS NOT NULL;
CREATE INDEX idx_wh_bins_status     ON warehouse_bins (warehouse_id, status);
```

### Relationships summary

| Table | Parent (mandatory FK) | Optional parents |
|---|---|---|
| `warehouses` | `companies` | `branches` |
| `warehouse_zones` | `warehouses` | — |
| `warehouse_areas` | `warehouse_zones` | — |
| `warehouse_aisles` | `warehouse_zones` | `warehouse_areas` |
| `warehouse_rows` | `warehouse_aisles` | — |
| `warehouse_shelves` | `warehouse_rows` | — |
| `warehouse_bins` | `warehouses` | `warehouse_zones`, `warehouse_areas`, `warehouse_aisles`, `warehouse_rows`, `warehouse_shelves`, `products` (restriction) |

Note that `warehouse_bins` denormalizes the *entire* ancestor chain (zone/area/aisle/row/shelf,
all nullable) rather than only pointing at its immediate parent. This is a deliberate
denormalization: it lets `inventory_items` and reporting queries filter/aggregate by any level
(e.g. "all stock in Zone B") with a single indexed join on `warehouse_bins`, instead of a
recursive CTE walk up a five-level tree on every query. A `BEFORE INSERT OR UPDATE` trigger
(`trg_warehouse_bins_denormalize_ancestors`) validates and back-fills `zone_id`/`area_id` from the
provided `shelf_id`/`row_id`/`aisle_id` chain when those are supplied and the higher-level IDs are
left null, and raises an exception if a supplied `area_id` does not actually belong to the
supplied `zone_id` (referential consistency across the denormalized columns is enforced in the
trigger, not by additional composite FKs, since PostgreSQL cannot express "this column must be
derivable from that column" as a plain constraint).

### Indexes

Beyond the per-table indexes listed above, two cross-cutting indexes support the most common
access patterns:

```sql
-- Fast "what's the full path for this bin" resolution (used by the location_path cache trigger)
CREATE INDEX idx_wh_bins_full_chain
    ON warehouse_bins (warehouse_id, zone_id, area_id, aisle_id, row_id, shelf_id)
    WHERE deleted_at IS NULL;

-- Fast barcode scan lookup company-wide (receiving/picking scan guns)
CREATE INDEX idx_wh_bins_barcode_lookup ON warehouse_bins (company_id, barcode)
    WHERE deleted_at IS NULL AND barcode IS NOT NULL;
```

### Constraints

- Every structural table's `code` is unique **within its immediate parent scope** (not
  company-wide) — two different warehouses may both have a `Zone A`; two different zones may both
  have an `Aisle 01`. This mirrors how warehouse staff actually name things.
- `warehouse_bins.barcode` is unique **company-wide** (a barcode scanner cannot disambiguate by
  warehouse context at scan time, so the physical barcode must be globally unique per tenant).
- All capacity fields (`capacity_volume_m3`, `capacity_weight_kg`, `capacity_units`) carry a
  `CHECK (... >= 0)` constraint at every level (omitted above per table for brevity but present in
  the actual migration files as `chk_<table>_capacity_nonneg`).
- `warehouse_bins.max_weight_kg` and `warehouse_shelves.max_weight_kg` are advisory limits enforced
  in the Service layer (Laravel `PutAwayService`) at write time, not at the database layer, because
  the enforcement depends on summing current product weights via `inventory_items` joined to
  `products.weight_kg` — a cross-table computation unsuitable for a `CHECK` constraint.

### History & Versioning

Structural tables (`warehouse_zones` through `warehouse_bins`) are never hard-deleted
(`deleted_at` soft-delete only), because historical `stock_movements` rows reference them by ID and
must remain resolvable for audit and historical reporting even after a bin is decommissioned. When
a company reorganizes a warehouse (e.g. merges two aisles), the old structural rows are soft-deleted
and new ones created; `stock_movements` rows keep pointing at the old (soft-deleted) IDs, and the
reporting layer resolves soft-deleted structural ancestors by name (`name_en`/`name_ar` as they
existed at deletion time) rather than hiding the row.

Every structural table additionally has a corresponding `_history` shadow table
(`warehouse_bins_history`, etc.) populated by an `AFTER UPDATE` trigger, capturing the full row
before each change (`operation CHAR(1)` — `U`pdate/`D`elete, `changed_at TIMESTAMPTZ`,
`changed_by BIGINT`, and a `JSONB` snapshot of the prior row). This is distinct from the
platform-wide `audit_logs` table: `audit_logs` records the business-meaning of a change ("Bin
capacity increased from 10 to 20 units by Ahmed at 14:02"), while the `_history` shadow tables give
engineering/support a byte-exact row-level rollback source. Both are retained indefinitely.

# AI Responsibilities

All AI agents operating on warehouse data run in the FastAPI AI Layer, never write directly to
PostgreSQL, and call the same Laravel `/api/v1/warehouses/*` and `/api/v1/inventory/*` endpoints a
human user would call — subject to the same permission checks (an AI agent's calls are made under
a dedicated `AI Agent` role, see **Permissions**). Every AI output carries `confidence
(0.00–1.00)`, `reasoning` (a human-readable explanation string), and `supporting_documents`
(references to the specific `stock_movements`, `inventory_items`, or historical report rows the
recommendation was derived from).

| Agent | Responsibility | Inputs | Outputs | Autonomy | Confidence Handling |
|---|---|---|---|---|---|
| Warehouse Optimization Agent | Recommends structural changes — splitting an over-capacity zone, re-designating an area's function, flagging a warehouse whose type no longer matches its usage pattern. | Historical `stock_movements`, `warehouse_zones`/`warehouse_areas` capacity vs. actual occupied volume, `inventory_valuations`. | A `warehouse_optimization_suggestions` draft record (proposed change + rationale) surfaced to the Warehouse Manager. | Suggest-only. | Confidence < 0.6 suggestions are hidden from the default dashboard view (available only in an "all suggestions" expanded list); ≥ 0.85 suggestions are highlighted as "high confidence." |
| Picking Route Optimization Agent | Computes the optimal pick sequence for a wave of `sales_order_items`/`stock_transfer_items` across multiple bins, minimizing travel distance using aisle/row adjacency and, where available, GPS/indoor positioning. | Open pick tasks, `warehouse_aisles.sequence_number`, `warehouse_bins` full chain, product velocity class. | An ordered pick list (bin sequence) attached to the pick task; picker's handheld app follows it. | Auto-apply for the *ordering* of an already-approved pick list (no approval required — this is a UX optimization, not a financial or stock-accuracy decision); never auto-creates or cancels a pick task. | Falls back to static FIFO/FEFO sequencing if confidence < 0.5 (e.g. insufficient adjacency data for a newly modeled warehouse). |
| Stock Placement Suggestions Agent | Proposes a destination bin during Put-Away, balancing velocity class (fast-movers near Packing), capacity fit (checked against product dimensions/weight), and existing-SKU consolidation. | `products` dimensions/weight, `warehouse_bins` capacity/dimensions/restricted_product, current `inventory_items` per bin. | A ranked list (top 3) of candidate bins presented to the put-away clerk; clerk picks one (or overrides). | Suggest-only; the clerk always makes the final placement call. | Each candidate carries its own confidence; a bin that fails a hard capacity/dimension check is never suggested regardless of confidence (hard constraint, not a probabilistic one). |
| Demand Forecasting Agent | Forecasts per-warehouse, per-product demand to inform replenishment transfer timing and quantity. | Historical `stock_movements` (outbound), `sales_order_items`, seasonality, `warehouse.region_code`. | A forecast curve per (warehouse, product, week) feeding the Transfer Recommendations agent and Purchasing's own reorder-point logic. | Suggest-only (feeds other agents/reports; never triggers a purchase or transfer itself). | Forecast intervals (P10/P50/P90) are surfaced alongside the point forecast so downstream consumers can size safety stock by their own risk tolerance. |
| Space Utilization Agent | Continuously computes fill rate (occupied vs. declared capacity) at every structural level and flags over-capacity or under-utilized zones/areas. | `warehouse_zones`/`areas`/`bins` capacity fields, live `inventory_items` volume derived from `products` dimensions × quantity. | The Warehouse Capacity report (see **Reports**) plus proactive notifications when a level crosses a configurable threshold (default 90%). | Auto-generates the report; notification-only, never blocks an operation itself (a receiving clerk can still receive into an over-capacity zone — the system warns, it does not hard-block, because physical reality sometimes exceeds a stale capacity declaration). | Confidence reflects how complete the underlying product-dimension data is (many companies do not populate every product's dimensions; the agent reports a data-completeness caveat below 70% dimension coverage). |
| Receiving Prediction Agent | Predicts expected receiving volume/timing per warehouse from open `purchase_orders`/`goods_receipts` and inbound `stock_transfers`, to help staff the Receiving Area appropriately. | Open POs with `expected_delivery_date`, open inbound transfers with `expected_receipt_date`, historical on-time-delivery rate per vendor. | A daily/weekly receiving-volume forecast per warehouse, surfaced on the warehouse dashboard. | Suggest-only. | Confidence weighted down for vendors/transfer-sources with fewer than 5 historical data points; flagged as "low history" rather than suppressed. |
| Transfer Recommendations Agent | Proposes inter-warehouse transfers when it detects an imbalance — one warehouse trending toward a stockout while a sibling warehouse (same company, same region) holds excess of the same SKU. | `inventory_items` on-hand/available across all company warehouses, Demand Forecasting output, `warehouse.region_code` for proximity weighting. | A draft `stock_transfers` row in `status = 'draft'` with a pre-filled reason (`ai_recommended: true`, `reasoning` text, `confidence`). | Requires approval — always. The agent may create a **draft** transfer but can never move it to `pending_approval` itself; a human user must review and submit it, and the standard Approval Workflow (including value-threshold and four-eyes rules) applies unchanged. | Draft transfers below 0.5 confidence are held in a "needs review" queue and do not appear in the primary drafts list to avoid alert fatigue. |

# Reports

All reports are scoped to the requesting user's accessible `company_id`/`branch_id`/`warehouse_id`
set (per **Permissions**) and are available both as an on-screen dashboard view and via the
`/api/v1/warehouses/reports/*` endpoints (see **API**) returning the standard response envelope
with `data.rows[]` plus `data.summary{}`.

### Warehouse Capacity

Per-warehouse (drill-down to zone/area/aisle/row/shelf/bin) declared capacity vs. currently
occupied volume/weight/units, with a fill-rate percentage and a trend sparkline (last 90 days).
Columns: `warehouse`, `level`, `level_name`, `capacity_volume_m3`, `occupied_volume_m3`,
`fill_rate_pct`, `capacity_weight_kg`, `occupied_weight_kg`, `bin_count`, `bins_full`,
`bins_available`. Driven by the Space Utilization AI agent's live computation.

### Inventory by Warehouse

Current on-hand, reserved, and available quantity and value per (warehouse, product), rolling up
from bin-level `inventory_items`. Columns: `warehouse`, `product_sku`, `product_name`,
`on_hand_qty`, `reserved_qty`, `available_qty`, `unit_cost`, `total_value`, `currency_code`.
Supports filtering by product category, ABC velocity class, and "below reorder point" flag.

### Stock Movements

A chronological ledger of every `stock_movements` row for a warehouse (or drill-down to a single
bin), across all movement types (receipt, putaway, pick, pack, shipment, transfer_out,
transfer_in, internal_relocation, adjustment). Columns: `movement_date`, `movement_type`,
`product_sku`, `quantity`, `from_location_path`, `to_location_path`, `reference_document_type`,
`reference_document_number`, `performed_by`.

### Receiving Report

Volume and timeliness of goods received per warehouse over a date range. Columns: `warehouse`,
`receipt_date`, `vendor_name`, `po_number`, `expected_date`, `actual_date`, `days_late`,
`line_count`, `total_qty_received`, `total_value_received`. Includes an on-time-receipt-rate
summary metric consumed by the Receiving Prediction AI agent's training signal.

### Shipping Report

Volume and timeliness of goods shipped per warehouse. Columns: `warehouse`, `ship_date`,
`customer_name`, `sales_order_number`, `requested_date`, `actual_ship_date`, `days_late`,
`package_count`, `total_qty_shipped`, `total_value_shipped`, `carrier`.

### Transfer Report

All `stock_transfers` in a date range with full lifecycle timing. Columns: `transfer_number`,
`scope` (inter_warehouse/inter_branch/inter_company), `source_warehouse`, `destination_warehouse`,
`status`, `requested_ship_date`, `actual_ship_date`, `actual_receipt_date`, `lead_time_days`,
`total_value`, `ai_recommended` (boolean), `approved_by`.

### Warehouse Performance

A composite operational scorecard per warehouse: average receiving-to-putaway time, average
pick-to-ship time, cycle count accuracy (see below), transfer on-time rate, capacity fill rate,
and stockout incidents. Presented as a scorecard with a trend arrow per metric versus the prior
period, intended as the primary input to the Warehouse Manager's periodic review and to the
Warehouse Optimization AI agent.

### Cycle Count Accuracy

Aggregates `stock_counts`/`stock_count_lines` results per warehouse: number of counts performed,
number of lines counted, number of variances found, total absolute variance value, variance rate
(%), and a breakdown by `reason_code` once adjustments are approved. Columns: `warehouse`,
`period`, `counts_performed`, `lines_counted`, `variance_lines`, `variance_rate_pct`,
`total_variance_value`, `top_variance_products` (array of the 5 SKUs with the largest absolute
variance in the period). This report is the primary feedback loop for tuning cycle-count
frequency and for evaluating whether a specific bin/aisle/warehouse has a systemic accuracy
problem warranting a physical count or a process review.

# API

All endpoints are under `/api/v1/warehouses` (and nested `/api/v1/warehouses/{warehouse_id}/...`
for structural sub-resources), require a Bearer token, are scoped by the `X-Company-Id` header,
and return the standard response envelope. List endpoints support `page`, `per_page` (default 25),
`sort`, `filter[...]`, and `search` query parameters.

### Endpoint table

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/warehouses` | `warehouse.read` | List warehouses, filterable by `type`, `status`, `branch_id`, `region_code`. |
| POST | `/api/v1/warehouses` | `warehouse.create` | Create a warehouse. |
| GET | `/api/v1/warehouses/{id}` | `warehouse.read` | Retrieve a single warehouse with its profile. |
| PUT | `/api/v1/warehouses/{id}` | `warehouse.update` | Update warehouse profile fields. |
| DELETE | `/api/v1/warehouses/{id}` | `warehouse.archive` | Soft-delete (archive) a warehouse. Rejected with `409` if `on_hand_quantity > 0` in any `inventory_items` row for it. |
| POST | `/api/v1/warehouses/{id}/restore` | `warehouse.archive` | Restore a soft-deleted warehouse. |
| GET | `/api/v1/warehouses/{id}/zones` | `warehouse.structure.read` | List zones for a warehouse. |
| POST | `/api/v1/warehouses/{id}/zones` | `warehouse.structure.manage` | Create a zone. |
| GET/POST/PUT/DELETE | `/api/v1/warehouses/{id}/zones/{zone_id}/areas` (and nested `.../aisles`, `.../rows`, `.../shelves`, `.../bins`) | `warehouse.structure.read` / `warehouse.structure.manage` | Full CRUD at every structural level, following the same nested-resource pattern. |
| GET | `/api/v1/warehouses/{id}/bins/search` | `warehouse.structure.read` | Search bins by barcode/code across the full structural chain (used by scan-gun apps); accepts `q` (barcode or code fragment). |
| POST | `/api/v1/warehouses/{id}/bins/bulk-import` | `warehouse.structure.manage` | Bulk-create bins from a CSV/JSON payload — used to seed a full bin layout in one call rather than one HTTP round-trip per bin. |
| GET | `/api/v1/warehouses/export` | `warehouse.read` | Export the warehouse list (and, with `?include=structure`, the full structural tree) as CSV or XLSX. |
| POST | `/api/v1/warehouses/import` | `warehouse.create` | Bulk-import warehouses from CSV/JSON. |
| POST | `/api/v1/inventory/transfers` | `inventory.transfer.create` | Create a draft stock transfer (owned by Inventory module; documented here for the warehouse-boundary contract). |
| POST | `/api/v1/inventory/transfers/{id}/submit` | `inventory.transfer.create` | Move a draft transfer to `pending_approval`. |
| POST | `/api/v1/inventory/transfers/{id}/approve` | `inventory.transfer.approve` | Approve a pending transfer. |
| POST | `/api/v1/inventory/transfers/{id}/reject` | `inventory.transfer.approve` | Reject a pending transfer (requires `reason`). |
| POST | `/api/v1/inventory/transfers/{id}/ship` | `inventory.transfer.create` | Mark a transfer's outbound leg dispatched (`in_transit`). |
| POST | `/api/v1/inventory/transfers/{id}/receive` | `inventory.receive` | Confirm receipt at the destination (`received`/`completed`). |
| GET | `/api/v1/warehouses/reports/capacity` | `reports.read` | Warehouse Capacity report. |
| GET | `/api/v1/warehouses/reports/inventory-by-warehouse` | `reports.read` | Inventory by Warehouse report. |
| GET | `/api/v1/warehouses/reports/movements` | `reports.read` | Stock Movements report. |
| GET | `/api/v1/warehouses/reports/performance` | `reports.read` | Warehouse Performance scorecard. |

### Request/response example 1 — Create a warehouse

`POST /api/v1/warehouses`

```json
{
  "code": "WH-KWT-01",
  "name_en": "Kuwait Main Warehouse",
  "name_ar": "المستودع الرئيسي - الكويت",
  "type": "main",
  "branch_id": 4,
  "manager_user_id": 112,
  "address_line1": "Street 12, Block 4, Shuwaikh Industrial",
  "city": "Kuwait City",
  "country_code": "KW",
  "latitude": 29.3375,
  "longitude": 47.9313,
  "timezone": "Asia/Kuwait",
  "working_hours": {
    "sun": {"open": "08:00", "close": "18:00"},
    "mon": {"open": "08:00", "close": "18:00"},
    "tue": {"open": "08:00", "close": "18:00"},
    "wed": {"open": "08:00", "close": "18:00"},
    "thu": {"open": "08:00", "close": "18:00"},
    "fri": null,
    "sat": null
  },
  "default_currency_code": "KWD"
}
```

Response `201 Created`:

```json
{
  "success": true,
  "data": {
    "id": 17,
    "company_id": 3,
    "branch_id": 4,
    "code": "WH-KWT-01",
    "name_en": "Kuwait Main Warehouse",
    "name_ar": "المستودع الرئيسي - الكويت",
    "type": "main",
    "status": "active",
    "default_currency_code": "KWD",
    "created_at": "2026-07-16T09:12:44Z",
    "updated_at": "2026-07-16T09:12:44Z"
  },
  "message": "Warehouse created successfully.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "b6f1c8f0-2a3e-4e9a-9d21-77f7a3a0b111",
  "timestamp": "2026-07-16T09:12:44Z"
}
```

### Request/response example 2 — Create a stock transfer (draft)

`POST /api/v1/inventory/transfers`

```json
{
  "source_warehouse_id": 17,
  "destination_warehouse_id": 22,
  "reason": "Rebalancing SKU-4021 ahead of forecasted regional demand spike",
  "items": [
    {"product_id": 4021, "quantity": 150, "source_bin_id": 903},
    {"product_id": 4088, "quantity": 40,  "source_bin_id": 911}
  ]
}
```

Response `201 Created`:

```json
{
  "success": true,
  "data": {
    "id": 5502,
    "transfer_number": "TRF-2026-005502",
    "scope": "inter_warehouse",
    "status": "draft",
    "source_warehouse_id": 17,
    "destination_warehouse_id": 22,
    "requested_ship_date": null,
    "ai_recommended": false,
    "items": [
      {"id": 1, "product_id": 4021, "quantity_requested": 150, "quantity_shipped": 0, "quantity_received": 0},
      {"id": 2, "product_id": 4088, "quantity_requested": 40,  "quantity_shipped": 0, "quantity_received": 0}
    ]
  },
  "message": "Transfer draft created.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "0d5a9f3b-6c11-4a8e-9a2c-1e7cf9d4a222",
  "timestamp": "2026-07-16T09:20:11Z"
}
```

### Request/response example 3 — Approve a transfer (error case: self-approval blocked)

`POST /api/v1/inventory/transfers/5502/approve`

```json
{}
```

Response `403 Forbidden` (approver is the same user who created the draft, and the company has
more than one eligible approver, so the four-eyes rule applies):

```json
{
  "success": false,
  "data": null,
  "message": "This transfer cannot be approved by its creator.",
  "errors": [
    {
      "code": "TRANSFER_SELF_APPROVAL_BLOCKED",
      "detail": "Transfer TRF-2026-005502 was created by user 112. A different user holding inventory.transfer.approve must approve it."
    }
  ],
  "meta": {"pagination": null},
  "request_id": "9a1e2d4c-3f55-4b8a-8e91-2c6a7d0b1333",
  "timestamp": "2026-07-16T09:24:03Z"
}
```

### Request/response example 4 — Bulk bin import

`POST /api/v1/warehouses/17/bins/bulk-import`

```json
{
  "bins": [
    {"code": "B-A01-R-L01-S01-001", "shelf_id": 340, "bin_type": "pick_face", "barcode": "8801221100011"},
    {"code": "B-A01-R-L01-S01-002", "shelf_id": 340, "bin_type": "pick_face", "barcode": "8801221100012"}
  ]
}
```

Response `200 OK`:

```json
{
  "success": true,
  "data": {"created": 2, "skipped": 0, "errors": []},
  "message": "2 bins imported successfully.",
  "errors": [],
  "meta": {"pagination": null},
  "request_id": "44f0e2b1-7a90-4c3d-9b12-5e8f1a2c4444",
  "timestamp": "2026-07-16T09:31:55Z"
}
```

### Request/response example 5 — Warehouse Capacity report

`GET /api/v1/warehouses/reports/capacity?warehouse_id=17&level=zone`

```json
{
  "success": true,
  "data": {
    "rows": [
      {
        "warehouse": "WH-KWT-01",
        "level": "zone",
        "level_name": "Zone A — Ambient",
        "capacity_volume_m3": 1200.0000,
        "occupied_volume_m3": 987.2500,
        "fill_rate_pct": 82.27,
        "bin_count": 480,
        "bins_full": 210,
        "bins_available": 270
      },
      {
        "warehouse": "WH-KWT-01",
        "level": "zone",
        "level_name": "Zone B — Cold",
        "capacity_volume_m3": 300.0000,
        "occupied_volume_m3": 288.9000,
        "fill_rate_pct": 96.30,
        "bin_count": 96,
        "bins_full": 91,
        "bins_available": 5
      }
    ],
    "summary": {"warehouse_fill_rate_pct": 85.11, "zones_over_threshold": 1}
  },
  "message": "OK",
  "errors": [],
  "meta": {"pagination": {"page": 1, "per_page": 25, "total": 2, "cursor": null}},
  "request_id": "12ab34cd-56ef-78gh-90ij-klmnopqrstuv",
  "timestamp": "2026-07-16T09:40:00Z"
}
```

### Error responses

| HTTP Code | Scenario |
|---|---|
| 400 | Malformed request body (e.g. `items[]` empty on a transfer create). |
| 401 | Missing/invalid Bearer token. |
| 403 | Permission denied, or a sensitive-operation rule blocked (self-approval, cross-company access attempt). |
| 404 | Warehouse/zone/area/aisle/row/shelf/bin ID not found or not in the caller's company. |
| 409 | Conflict — e.g. archiving a warehouse with on-hand stock, or duplicate `code` within the same parent scope. |
| 422 | Validation error — `errors[]` populated with field-level messages (e.g. `temperature_min_c` greater than `temperature_max_c`). |
| 429 | Rate limited (bulk-import endpoints are throttled to protect write throughput). |
| 500 | Internal server error. |

# Permissions

Permission keys follow the platform's `<area>.<action>` / `<area>.<entity>.<action>` convention.

| Permission Key | Description |
|---|---|
| `warehouse.read` | View warehouse list and profiles. |
| `warehouse.create` | Create new warehouses. |
| `warehouse.update` | Edit warehouse profile fields, including type changes. |
| `warehouse.archive` | Soft-delete/restore a warehouse. |
| `warehouse.structure.read` | View zones/areas/aisles/rows/shelves/bins. |
| `warehouse.structure.manage` | Create/update/archive structural sub-resources. |
| `inventory.receive` | Perform receiving into a warehouse. |
| `inventory.putaway` | Perform put-away into a bin. |
| `inventory.pick` | Perform picking from a bin. |
| `inventory.pack` | Perform packing. |
| `inventory.ship` | Release a shipment out of a warehouse. |
| `inventory.adjust` | Create a stock adjustment. |
| `inventory.adjust.approve` | Approve an adjustment above the value threshold. |
| `inventory.returns.inspect` | Record a quality disposition on returned stock. |
| `inventory.count.create` | Initiate a cycle or physical count. |
| `inventory.count.approve` | Approve count variances and resulting adjustments. |
| `inventory.transfer.create` | Create/submit/ship a stock transfer. |
| `inventory.transfer.approve` | Approve/reject a pending transfer. |
| `inventory.transfer.resolve` | Resolve a disputed transfer. |
| `reports.read` | View warehouse-related reports. |
| `reports.export` | Export report data. |

### Role matrix

| Role | warehouse.read | warehouse.create/update | warehouse.archive | structure.manage | inventory.receive/pick/pack/ship | inventory.adjust | inventory.adjust.approve | inventory.transfer.create | inventory.transfer.approve | reports.read |
|---|---|---|---|---|---|---|---|---|---|---|
| Owner | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Admin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Warehouse Manager | ✅ | ✅ (own warehouses) | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Inventory Clerk | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ (read-only) |
| Finance | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ✅ (inter-company only) | ✅ |
| Auditor | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| AI Agent | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ (draft-only via adjustments suggestion) | ❌ | ✅ (draft-only, cannot submit) | ❌ | ✅ |

Notes on the matrix:
- **Warehouse Manager** `warehouse.create`/`update` is scoped to warehouses where they are set as
  `manager_user_id`, or where their `branch_id` matches, enforced at the Service layer via a
  policy check, not merely the permission key (a permission key grants the *capability*; the
  policy narrows the *scope*).
- **Finance**'s `inventory.transfer.approve` is restricted to `inter_company` scope transfers
  only — Finance must approve any transfer that produces a journal entry crossing legal entities,
  even if they hold no general transfer-approval right for routine inter-warehouse moves.
- **AI Agent** never receives `inventory.adjust.approve` or `inventory.transfer.approve` under any
  configuration — these are hard-coded as human-only in the Service layer regardless of what a
  company's custom role configuration grants an API-key-authenticated AI Agent principal, per the
  platform rule that sensitive operations always require a human approval chain.
- **External Auditor** (referenced in the platform's default role list but not shown above for
  brevity) receives the same row as **Auditor**: `reports.read` and `warehouse.read` only, scoped
  read-only, typically time-boxed via a temporary role assignment.

# Notifications

Notifications are delivered through the foundation `notifications` table and pushed via Laravel
Reverb (in-app real-time) plus, where a user has opted in, email/SMS channels. Every notification
carries a `type`, a `related_entity_type`/`related_entity_id`, a `priority`
(`info`/`warning`/`critical`), and a deep link back to the relevant warehouse screen.

| Event | Trigger | Recipients | Priority |
|---|---|---|---|
| `warehouse.capacity.threshold_exceeded` | A zone/area/bin's fill rate crosses its configured threshold (default 90%). | Warehouse Manager, Inventory Manager | warning |
| `warehouse.temperature.out_of_range` | A recorded temperature reading (at receipt, or via a future IoT integration hook) falls outside a Cold Storage zone's declared range. | Warehouse Manager, Auditor | critical |
| `inventory.transfer.submitted` | A transfer moves to `pending_approval`. | All users holding `inventory.transfer.approve` for the destination/source scope. | info |
| `inventory.transfer.approved` | A transfer is approved. | Transfer creator, source & destination Warehouse Managers. | info |
| `inventory.transfer.rejected` | A transfer is rejected. | Transfer creator. | warning |
| `inventory.transfer.disputed` | A destination clerk reports a receipt mismatch. | Transfer creator, both Warehouse Managers, Finance (if inter-company). | critical |
| `inventory.transfer.overdue` | `expected_receipt_date` passes with the transfer still `in_transit`. | Both Warehouse Managers. | warning |
| `inventory.adjustment.pending_approval` | An adjustment exceeds the value threshold. | All users holding `inventory.adjust.approve`. | warning |
| `inventory.count.variance_detected` | A cycle/physical count line's variance exceeds tolerance. | Warehouse Manager. | warning |
| `inventory.count.completed` | A physical count is fully reconciled and closed. | Warehouse Manager, Finance. | info |
| `warehouse.ai.transfer_recommended` | The Transfer Recommendations AI agent creates a draft transfer. | Inventory Manager, Warehouse Manager (source & destination). | info |
| `warehouse.status.changed` | A warehouse's `status` changes (especially to `closed` or `under_maintenance`). | All users with `warehouse.read` scoped to that warehouse's branch. | warning |

Notification preferences (which channels, which priority floor) are configurable per user under
account settings, except `critical` priority notifications, which always fire in-app and via
email/SMS regardless of user preference — this is a deliberate override, consistent with the
platform's treatment of sensitive/safety-relevant events.

# Security

- **Tenant isolation**: every query against any warehouse-structural table is scoped by
  `company_id` at the Repository layer (never trusted from client input alone — the authenticated
  user's active company, resolved server-side from the Sanctum token plus the `X-Company-Id`
  header cross-checked against `company_users`, is the sole source of truth for the scope filter).
  A request presenting a `warehouse_id` belonging to a different company than the header claims
  returns `404`, not `403` — the API never confirms the existence of a resource outside the
  caller's tenant.
- **Branch-level restriction**: a user whose role is branch-scoped (e.g. a Retail Store's
  Inventory Clerk) is further restricted at the policy layer to warehouses whose `branch_id`
  matches their assigned branch(es); this is enforced in addition to, not instead of,
  `company_id` scoping.
- **Barcode/scan-endpoint hardening**: the bin-search and scan-driven receiving/picking endpoints
  are rate-limited per user session (default 120 requests/minute) to prevent a compromised or
  malfunctioning handheld device from flooding the API, and validate that the scanned barcode
  resolves to a bin within the warehouse the session's active pick/receive task is scoped to (a
  scan of a barcode from a different warehouse is rejected with a specific
  `BARCODE_WRONG_WAREHOUSE` error rather than silently accepted).
- **Sensitive-field masking**: `warehouses.operator_contract_id` and the underlying
  `procurement_contracts` financial terms are not returned in the default warehouse profile
  payload to users without `procurement.contracts.read`; the field is present but nulled out for
  users who only hold `warehouse.read`.
- **Approval integrity**: the four-eyes rule on transfer approval and adjustment approval is
  enforced server-side (checked against `created_by`/`updated_by` on the transaction, not
  client-supplied), and self-approval exceptions (single-approver companies) are themselves logged
  as a distinct audit event (`control_exception: self_approval`) so an Auditor role can review the
  full list of exceptions in one query.
- **AI Agent principal isolation**: the AI Layer authenticates to the Laravel API using a
  dedicated service-account bearer token per company, scoped to the `AI Agent` role only, never a
  human user's token — an AI recommendation can never inherit a human's elevated permissions
  because it never runs "as" that human.
- **Data at rest**: `warehouses` and all structural tables are stored in the same encrypted
  PostgreSQL instance as the rest of the platform (encryption at rest via the managed Postgres
  provider); no warehouse-specific field is deemed sensitive enough for column-level encryption
  beyond the platform-wide standard, with the exception of `operator_contract_id`'s downstream
  contract financial terms, which follow the Purchasing module's own encryption policy for
  `procurement_contracts`.
- **Attachment access**: documents/attachments linked to a warehouse via the polymorphic
  `attachments` table are served through signed, time-limited URLs (never a public bucket path),
  consistent with the platform's object-storage (Cloudflare R2) access pattern.

# Audit Logs

Every mutation to `warehouses` and every structural table writes an `audit_logs` row with:
`company_id`, `user_id`, `action` (`create`/`update`/`delete`/`restore`), `entity_type`,
`entity_id`, `old_values JSONB`, `new_values JSONB`, `reason TEXT NULL`, `ip_address INET`,
`device_info JSONB`, `created_at`.

Warehouse-specific audit events beyond generic CRUD:

| Action | Extra Captured Detail |
|---|---|
| `warehouse.type_changed` | Old type, new type, and whether on-hand stock existed at the time (a type change with stock on hand is legal but always flagged for Auditor review). |
| `warehouse.status_changed` | Old/new status, reason (mandatory free text when transitioning to `closed`). |
| `structure.capacity_overridden` | Any manual edit to a declared capacity field at any structural level, since capacity declarations feed the Space Utilization AI agent and a bad-faith capacity inflation is a plausible fraud vector (hiding shrinkage by claiming more space was "available" than actually existed). |
| `bin.restricted_product_changed` | Old/new `restricted_product_id`, since this affects put-away eligibility company-wide. |
| `transfer.self_approval_exception` | As described in **Security**. |
| `transfer.disputed` / `transfer.resolved` | Full before/after quantity, the resolving user, and whether resolution produced a `stock_adjustments` row. |
| `adjustment.approved` | Threshold amount at time of approval (thresholds can change over time; the audit row freezes the value that applied). |
| `count.variance_approved` | Per-line variance value and resulting adjustment reference. |

Audit logs are immutable and retained indefinitely (financial-record retention rule). They are
queryable via `/api/v1/audit-logs?entity_type=warehouse&entity_id={id}` (foundation endpoint, not
re-specified here) and are the primary evidence source for the Auditor and External Auditor
roles, who hold no write permission on warehouse data at all — their entire interaction with this
module is `reports.read` plus `audit_logs` read access.

# Performance

- **Denormalized bin ancestry** (see Database Design) avoids recursive CTE walks on the hot path
  (every `inventory_items` read, every scan-driven pick/putaway) at the cost of a small amount of
  redundant storage and a trigger-maintained invariant.
- **Partial indexes** (`WHERE deleted_at IS NULL`) on every structural table keep the working set
  of active rows small even as soft-deleted history accumulates over years of reorganizations.
- **Materialized capacity rollups**: the Warehouse Capacity report does not compute occupied
  volume live from `inventory_items` on every request for high-traffic dashboards; a materialized
  view `warehouse_capacity_rollup` refreshed every 5 minutes (via a scheduled Laravel job, not a
  synchronous trigger) backs the dashboard's default view, with a "refresh now" action available
  to force a live recompute when a user needs up-to-the-second accuracy (e.g. right before closing
  a physical count).
- **Bulk operations**: `bins/bulk-import` and the warehouse CSV/XLSX import path use
  `INSERT ... ON CONFLICT DO UPDATE` batched in chunks of 500 rows per statement inside a single
  transaction per chunk, rather than one `INSERT` per row, to keep large layout imports (a
  20,000-bin distribution center) within a reasonable request timeout; imports beyond 2,000 rows
  are automatically routed to an async queue job with a webhook (`warehouse.import.completed`)
  rather than a synchronous HTTP response.
- **Read replicas**: report endpoints (`/api/v1/warehouses/reports/*`) are configured to read from
  a PostgreSQL read replica where available, since they are read-heavy and tolerate the small
  replication lag; write endpoints always hit the primary.
- **Cache invalidation**: `location_path` on `inventory_items` and any Redis-cached
  bin/aisle/zone lookup (used by the scan-gun endpoints for sub-100ms response targets) is
  invalidated by the same trigger/event that updates the denormalized ancestry, keyed by
  `warehouse_bins.id`, so a structural reorganization never leaves a stale cached path visible to
  a picker.
- **N+1 avoidance**: the structural tree endpoints (`GET /api/v1/warehouses/{id}/zones` with
  `?include=areas.aisles.rows.shelves.bins`) use eager loading (`with()` in Laravel/Eloquent) with
  an explicit maximum include depth of 3 relations per request to prevent an accidental
  full-tree-of-20,000-bins payload from a poorly scoped API call; deeper traversal must be done
  level-by-level or via the dedicated `bins/search` endpoint.

# Edge Cases

- **A warehouse with zero structure.** A brand-new `Retail Store` warehouse with no zones, no
  bins at all. `inventory_items` rows for it have `bin_id = NULL` and the `location_path` cache
  resolves to just the warehouse's own name. All operations (receiving, picking) work identically,
  simply reporting location at the warehouse level.
- **Skipping intermediate levels.** A warehouse models Zones and Bins but no Areas/Aisles/
  Rows/Shelves. `warehouse_bins.zone_id` is set directly; `area_id` through `shelf_id` remain
  null. The denormalization trigger permits this because only `warehouse_id` is a hard NOT NULL
  requirement on `warehouse_bins`.
- **Reorganizing a live warehouse.** Merging Aisle 3 into Aisle 2: the old Aisle 3 row (and its
  Rows/Shelves/Bins) are soft-deleted; new Bin rows are created under Aisle 2 with fresh IDs. Any
  `inventory_items` still pointing at an old (now soft-deleted) bin must be explicitly relocated
  via a `stock_movements` "internal_relocation" — the system does not auto-migrate stock references
  when a structural row is deleted, because silently rewriting a financial location record without
  an explicit movement event would break the audit trail's completeness guarantee. The archive
  endpoint for a structural row with `inventory_items.on_hand_quantity > 0` still referencing it
  returns `409` until relocated.
- **Bin capacity is exceeded by a legitimate put-away.** A clerk manually overrides an AI
  suggestion and forces stock into a bin beyond its declared `capacity_units`. The system allows
  it (capacity is advisory at the write layer per **Database Design** → Constraints) but flags the
  bin `status = 'full'` automatically and raises a `warehouse.capacity.threshold_exceeded`
  notification.
- **A transfer where source and destination are the same warehouse.** Rejected at validation
  (`422`) with `SAME_SOURCE_DESTINATION` — a same-warehouse relocation must be modeled as an
  `internal_relocation` stock movement (via Picking/Put-Away flows), not a `stock_transfers`
  document, per the rule stated in **Warehouse Philosophy**.
- **A transfer partially shipped, then the source warehouse is closed.** The transfer is allowed
  to continue to `in_transit`/`received`/`completed` even though the source warehouse's `status`
  is now `closed` — closing a warehouse blocks *new* outbound activity, not the completion of
  already-`approved` transfers, to avoid stranding stock mid-transit.
- **Inter-company transfer where the destination company has no matching product record.** The
  paired transaction fails validation on the destination side (`404 PRODUCT_NOT_FOUND_IN_COMPANY`)
  before any accounting entry posts on the source side; the source-side outbound leg remains in
  `pending_approval` (never auto-progresses to `approved`) until the destination company's catalog
  is reconciled — this is a deliberate two-phase-commit-like guard against a half-completed
  intercompany transfer producing an unbalanced set of books across two otherwise-isolated tenants.
- **Cold Storage temperature excursion during a count.** If a physical count is in progress in a
  Cold Storage zone and a temperature reading outside range is logged mid-count, the count is not
  auto-invalidated, but the resulting `stock_counts` row is flagged `temperature_excursion_during_count
  = true` so the Warehouse Manager can judge whether counted quantities/condition need
  re-verification (spoiled stock might count as "present" but should really route to Returns/
  disposition).
- **A 3PL-operated warehouse's reported stock disagrees with QAYD's records.** Since the 3PL is
  not a `vendors` counterparty for the stock itself (per **Warehouse Types**), a disagreement is
  resolved the same way as an internal cycle-count variance: a `stock_counts` row of
  `count_type = 'cycle'` is created referencing the 3PL's `operator_reference_code` report as the
  "counted" source, routed through the normal variance-and-adjustment-approval path — QAYD does
  not create a special-cased reconciliation table for 3PL disagreements; it reuses the count
  mechanism.
- **Deleting a product that has `restricted_product_id` bins pointing at it.** Blocked (`409`)
  until every `warehouse_bins.restricted_product_id` referencing that product is cleared or
  reassigned; the Products module's archive endpoint checks this via a cross-module query rather
  than a DB-level FK `ON DELETE RESTRICT`, since `products` soft-deletes rather than hard-deletes
  and the check is therefore a business rule, not a physical constraint violation.
- **Concurrent put-away to the same bin from two different receiving clerks.** Handled via
  optimistic locking on `warehouse_bins.updated_at` (an `If-Unmodified-Since`-style check) at the
  Service layer; the second clerk's request is rejected with `409 BIN_STATE_CHANGED` and the
  client is expected to re-fetch the bin's current occupancy before retrying, preventing a lost-
  update race that would silently overstate a bin's true occupied capacity.

# Future Improvements

- **Live IoT temperature/humidity monitoring integration** for Cold Storage zones, replacing the
  current at-receipt manual reading with continuous sensor feeds and automatic
  `warehouse.temperature.out_of_range` notifications the moment a threshold is crossed, not only
  at the next manual check.
- **Indoor positioning (BLE/UWB) integration** to replace the Manhattan-distance travel estimate
  in Picking Route Optimization with actual measured picker movement, and to auto-populate aisle
  adjacency instead of requiring manual `sequence_number` configuration.
- **Automated slotting re-optimization runs**: a scheduled batch job (not just an on-demand
  suggestion) that periodically re-evaluates the entire bin-assignment map against updated
  velocity classes and proposes a batch re-slotting plan, rather than only suggesting placement at
  the moment of a single put-away.
- **Digital twin / 3D warehouse visualization**: rendering the Zone→Bin hierarchy as a navigable
  3D or 2.5D floor plan in the frontend, using the existing dimension fields
  (`length_cm`/`width_cm`/`height_cm`) already captured at every structural level.
- **Carrier-integrated live transit tracking**: replacing the placeholder `current_location`
  free-text field on `stock_transfers` with a real webhook-driven GPS feed from third-party
  carrier APIs, closing the gap between "in_transit" as a status and "in_transit" as a tracked
  physical reality.
- **Predictive capacity planning**: extending the Space Utilization agent from "what is my fill
  rate today" to "when will Zone B hit 100% given current inbound/outbound trend," feeding
  directly into Warehouse Optimization's structural-change suggestions and into Purchasing's
  own reorder timing (a purchase that will arrive into an already-full zone is a planning failure
  the AI layer should catch before the PO is even approved).
- **Automated 3PL reconciliation feed**: a structured API/EDI ingestion path for 3PL stock reports
  (rather than the manual cycle-count-style reconciliation described in Edge Cases), turning what
  is today a human-triggered comparison into a scheduled automatic one with AI-flagged
  discrepancies above a materiality threshold routed straight to the Warehouse Manager.
- **Cross-warehouse pick-and-ship optimization**: when a single sales order could be fulfilled
  from multiple company warehouses, extend Picking Route Optimization (currently single-warehouse
  scoped) into a network-level fulfillment optimizer that weighs shipping cost/time against
  inventory age/location, in coordination with the Sales module's delivery-splitting logic.
- **Wearable/AR pick guidance**: extending the handheld scan-gun pattern to AR-glasses-guided
  picking, using the same `location_path` and bin barcode data model already defined here, with no
  schema change required — only a new client surface consuming the existing pick-list API.

# End of Document
