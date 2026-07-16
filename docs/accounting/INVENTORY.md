# Inventory Management — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Inventory
---

# Purpose

Inventory Management is the module of QAYD responsible for tracking the physical and logical existence
of everything a company buys, makes, stores, moves, reserves, sells, and disposes of. It answers, at any
instant and for any company, three questions with full auditability: **what do we have**, **where is it**,
and **what is it worth**. It is the operational system of record for stock quantities per
`(product, warehouse, bin)` and the financial system of record for the value of that stock, which it
exposes to the Accounting Engine as posted journal entries against Inventory and Cost of Goods Sold (COGS)
control accounts.

Inventory sits at the intersection of three domains that must never disagree: the **Products** module
(item master — what a SKU is, its units of measure, its barcodes, its serial/batch policy), the
**Warehouses** module (where physical space exists — zones, aisles, bins), and the **Accounting Engine**
(what stock is worth in the company's base currency, and how movements affect the general ledger). Every
quantity change in Inventory is a `stock_movements` row; every quantity change that has a monetary
consequence emits a domain event that Accounting consumes to post a balanced journal entry. Inventory
itself never writes journal entries directly — it emits events, exactly as prescribed by the platform's
event-driven module communication rule.

Concretely, this module owns:

- The **quantity ledger**: `stock_movements`, the append-only, immutable log of every unit that entered,
  left, or moved inside the company's stock.
- The **current-state projection**: `inventory_items`, the fast-read table of on-hand, reserved,
  on-order, and in-transit quantities per product/warehouse/bin, kept in sync with `stock_movements`.
- The **commitment layer**: `stock_reservations`, which holds stock against sales orders, transfers, or
  production before it physically leaves a bin.
- The **correction layer**: `stock_adjustments`, for cycle-count variances, damage, shrinkage, and
  manual corrections, always with a reason code and an approval trail.
- The **movement layer**: `stock_transfers` and `stock_transfer_items`, for stock relocations between
  warehouses, branches, or bins, including in-transit tracking.
- The **audit layer**: `stock_counts` and `stock_count_lines`, for scheduled cycle counts and full
  physical inventories, with blind-count support and variance-to-adjustment workflows.
- The **valuation layer**: `inventory_valuations`, the historical record of unit cost by valuation
  method (FIFO, LIFO, Weighted Average, Standard Cost, Moving Average, Specific Identification) used to
  cost every outbound movement and to value on-hand stock for the balance sheet.
- **Traceability**: `product_serials` and `product_batches`, which give unit-level and lot-level
  identity to stock that requires it (medical devices, pharmaceuticals, electronics, food with shelf
  life), and which drive recall, warranty, and expiry workflows.

Inventory does not own the item master (`products`) or the physical location hierarchy
(`warehouses`, `warehouse_zones` … `warehouse_bins`) — it references them. It does not own pricing
(`price_lists`) or sales/purchase documents (`sales_orders`, `invoices`, `purchase_orders`, `bills`) —
those modules drive inventory movements via domain events, and Inventory reacts to them, never the
reverse for financial authority.

# Vision

The vision for Inventory in QAYD is a **real-time, self-correcting, AI-assisted stock ledger** that a
finance team can trust as much as the general ledger itself, and that a warehouse worker can operate
without ambiguity on a handheld scanner. Three principles anchor that vision:

**1. One quantity, one truth, everywhere.** At any moment, `inventory_items.quantity_on_hand` for a given
`(company_id, product_id, warehouse_id, bin_id)` must be mathematically derivable by summing
`stock_movements` for that key, and must in fact be kept as a maintained projection updated inside the
same database transaction as the movement insert. There is never a "warehouse app number" that disagrees
with an "accounting number." Both read from the same tables.

**2. Every unit is traceable, every value is defensible.** For serialized and batch-controlled goods, the
system can answer "where is serial X right now" and "which customers received batch Y" in one query — the
foundation of recalls and warranty service. For value, every layer of cost (FIFO/LIFO layer, weighted
average bucket, standard-cost variance) is stored, not just the final number, so a CFO or an external
auditor can reconstruct exactly how the $ value of ending inventory was computed for any close date.

**3. AI proposes, humans and policy dispose.** The module is built to host a Inventory Manager AI agent
and a Forecast Agent that continuously analyze the movement and reservation history to recommend reorder
points, flag dead stock, cluster ABC/XYZ classes, and detect shrinkage anomalies — but every AI output is
a draft with a confidence score and a reasoning trail; nothing AI recommends changes a ledger balance
without a human approval or a pre-authorized policy threshold, matching the platform-wide AI governance
rule.

The long-run vision is a stock engine that scales from a single-warehouse retailer in Kuwait tracking a
few hundred SKUs in KWD, to a multi-company, multi-currency, multi-warehouse manufacturing group tracking
hundreds of thousands of serialized and batch-controlled SKUs across GCC subsidiaries, without a schema
change — only configuration (valuation method per product, warehouse hierarchy depth, currency per
company) changes between those two deployments.

# Inventory Philosophy

Inventory in QAYD is modeled as an **event-sourced ledger with a materialized current-state
projection**, not as a single mutable "quantity" column that gets incremented and decremented in place.
This is a deliberate architectural choice with four consequences:

- **Immutability of history.** `stock_movements` rows are never updated or deleted once posted. A wrong
  movement is corrected by posting a reversing movement referencing the original (`reversed_movement_id`),
  never by editing the row. This gives the same immutability guarantee the platform requires of posted
  journal entries, and it means inventory history can always be replayed to reconstruct the state at any
  past instant — required for both financial audits and stock-count reconciliation.

- **Separation of physical and financial reality.** A unit can be physically in a bin but not "available"
  (it is reserved for an order); it can be "on order" (a purchase order exists) without being on any
  shelf; it can be "in transit" (it has left warehouse A but not yet arrived at warehouse B). QAYD models
  these as distinct, simultaneously-tracked quantity buckets on `inventory_items`
  (`quantity_on_hand`, `quantity_reserved`, `quantity_on_order`, `quantity_in_transit`,
  `quantity_damaged`, `quantity_quarantined`), rather than collapsing them into one number. "Available to
  sell" is always a derived value: `quantity_on_hand - quantity_reserved - quantity_damaged -
  quantity_quarantined`.

- **Cost follows layers, not a single average, unless a company chooses otherwise.** Because different
  companies (and different product categories within one company) legitimately need FIFO, Weighted
  Average, Standard Cost, or Specific Identification, the valuation method is a property of the product
  (`products.valuation_method`, referenced from Inventory) and the layer bookkeeping lives in
  `inventory_valuations`, which stores every open cost layer with its remaining quantity — never just a
  running average that discards how the number was built up.

- **Inventory is a subsidiary ledger of Accounting, structurally identical in spirit to Customers (AR)
  and Vendors (AP).** The `inventory_items` on-hand value, summed across all products and warehouses of a
  company at the company's base currency, must reconcile to the balance of the Inventory control account
  in the chart of accounts. Every event that changes that value (receipt, sale, adjustment, write-off)
  posts a balanced journal entry into `journal_entries`/`journal_lines` via a domain event — Inventory
  never writes to those tables directly, exactly as Section 4 of the platform's accounting rules requires
  for all sub-ledgers.

- **Warehouses are a strict hierarchy, and every quantity is scoped to the lowest level given.** A
  quantity can be tracked as loosely as "in this warehouse" or as tightly as "in this bin, on this shelf,
  in this row, in this aisle, in this area, in this zone, in this warehouse." QAYD supports both: bin-level
  tracking is optional per warehouse (`warehouses.bin_tracking_enabled`), and when disabled, movements post
  against the warehouse with `bin_id = NULL`. This lets a five-person retailer skip bin management entirely
  while a 3PL with high-density racking gets full slot-level accuracy.

- **No stock movement is free of a reason.** Every row in `stock_movements` carries a `movement_type` and
  a `source_type`/`source_id` pointing back to the document (invoice, bill, transfer, adjustment, count)
  that caused it. There is no such thing as an unexplained quantity change; this is the mechanism that
  makes the ledger auditable and the AI shrinkage/fraud detection agents effective, because every anomaly
  can be traced to a document, a user, and a timestamp.

# Inventory Lifecycle

The inventory lifecycle describes the path a unit of stock travels from the moment a company decides it
needs it, to the moment it leaves the books entirely. Each stage below maps to concrete tables, statuses,
and, where relevant, the accounting event it triggers.

## Purchase

The lifecycle begins before any physical stock exists, at the moment a `purchase_order` is approved in
the Purchasing module. Inventory's involvement at this stage is passive but essential: it increments
`inventory_items.quantity_on_order` for the ordered product/warehouse so that planning screens and the
AI Forecast Agent see incoming supply, without creating a `stock_movements` row (no physical or financial
event has happened yet — no journal entry is posted). The link is maintained via
`inventory_items.quantity_on_order`, recomputed as `SUM(purchase_order_items.quantity_ordered -
quantity_received)` for open purchase orders scoped to that product/warehouse, listened to via the
`purchase_order.approved`, `purchase_order.line_updated`, and `purchase_order.cancelled` domain events
published by Purchasing.

## Receive

Receiving is the first stage that creates a physical and financial fact. When a `goods_receipt` is posted
in Purchasing (partially or fully against a `purchase_order`), Purchasing emits `goods_receipt.posted`.
Inventory reacts by:

1. Inserting one `stock_movements` row per received line with `movement_type = 'purchase_receipt'`,
   `direction = 'in'`, the received quantity, and the unit cost from the goods receipt line (landed cost,
   inclusive of freight/duty allocations if the Purchasing module apportions them).
2. Incrementing `inventory_items.quantity_on_hand` and decrementing `quantity_on_order` by the same
   amount for the target `(product_id, warehouse_id, bin_id)`.
3. Opening a new cost layer in `inventory_valuations` (for FIFO/LIFO/Specific Identification) or updating
   the moving/weighted average bucket, at the received unit cost.
4. If the product is serialized, inserting one `product_serials` row per unit with `status = 'in_stock'`.
   If batch-controlled, inserting or incrementing the matching `product_batches` row (creating it if the
   supplier's batch/lot number is new, with its expiration date if the product is `is_perishable`).
5. Emitting `inventory.stock_received`, which Accounting consumes to post: **Debit Inventory (asset)**,
   **Credit Goods Received Not Invoiced (accrued liability)** — later reconciled against the vendor bill
   in the three-way match performed by Purchasing.

Put-away (assigning the received pallet/carton to a specific bin) may happen immediately at receiving or
as a distinct subsequent operation; QAYD supports both by allowing the receiving `stock_movements` row to
target a default "receiving dock" bin, followed by a `movement_type = 'putaway'` transfer row to the
final bin.

## Store

Storage is the steady state between receiving and any outbound event: stock sits in `quantity_on_hand`
at a specific bin. "Store" is not a transactional event by itself, but it is where cycle counting,
quarantine holds, and expiration monitoring apply continuously. The AI Warehouse Optimization agent
analyzes bin occupancy and pick-frequency during this stage to recommend slotting changes (moving
fast-moving SKUs closer to the pack station), emitted as suggestions, never automatic moves.

## Reserve

Reservation commits available stock to a demand document — most commonly a confirmed `sales_order` or a
`stock_transfer` awaiting shipment — before the physical pick happens. Sales emits `sales_order.confirmed`
(or Purchasing/Manufacturing emits an equivalent commitment event); Inventory reacts by inserting a
`stock_reservations` row with `status = 'active'`, and increasing `inventory_items.quantity_reserved` by
the reserved quantity — no `stock_movements` row is created, because no physical quantity has changed, and
no journal entry is posted, because reservation has no financial effect (it is a change in composition of
"on hand," not in value). Reservations can expire (`expires_at`), automatically releasing the hold if the
downstream document is not fulfilled in time, and are strictly FIFO against available stock by default,
with an override to reserve against specific serials/batches when required (e.g., a customer contractually
entitled to a specific lot).

## Transfer

A transfer relocates stock between two warehouses (or two bins within a warehouse, or two branches of the
same company). It is modeled as a `stock_transfers` header with one or more `stock_transfer_items` lines.
A transfer has three physical sub-events, each producing its own `stock_movements` row:

1. **Dispatch** (`movement_type = 'transfer_out'`) at the source: decrements source
   `quantity_on_hand`, increments source `quantity_in_transit` is not used at the source — instead a
   company-level `quantity_in_transit` bucket is incremented on the transfer's virtual "in-transit"
   location, or, more simply, the transfer's `status` moves to `in_transit` and the units are logically
   held by the transfer record itself until receipt.
2. **In-transit** — no additional movement row; the `stock_transfers.status = 'in_transit'` is the state,
   queryable via `stock_transfer_items`.
3. **Receipt at destination** (`movement_type = 'transfer_in'`): increments destination
   `quantity_on_hand`. If the transfer crosses company book boundaries that require an inter-branch or
   inter-company valuation, Accounting posts a **Debit Inventory (destination location)** / **Credit
   Inventory (source location)** journal entry at the same unit cost that left the source (transfers never
   change unit cost, only location) — a zero-net-value movement at the consolidated company level, but a
   real movement at the branch level if branch-level inventory sub-accounts are enabled.

Transfers between warehouses of the **same company** never touch COGS or revenue; transfers to a
**different company** (e.g., intercompany stock drop-ship) are treated as a sale-and-purchase pair and
routed through Sales/Purchasing instead, never as a `stock_transfers` row, to preserve arm's-length
company isolation.

## Sell

A sale is the primary outbound, revenue-generating event. When Sales posts a `delivery` against a
confirmed `sales_order` (or, in retail/POS flows, directly against an `invoice`), Sales emits
`delivery.shipped` (or `invoice.posted` for direct-ship models). Inventory reacts by:

1. Releasing the corresponding `stock_reservations` row (`status = 'fulfilled'`), decrementing
   `quantity_reserved`.
2. Inserting a `stock_movements` row with `movement_type = 'sale'`, `direction = 'out'`, decrementing
   `quantity_on_hand`.
3. Consuming cost layers according to the product's `valuation_method` (oldest layer first for FIFO,
   newest first for LIFO, the current weighted-average or moving-average cost, the specific unit's cost
   for Specific Identification, or standard cost with variance capture) and writing the consumed-cost
   detail into `inventory_valuations` as a `layer_consumption` row, then computing the total COGS for the
   movement.
4. If serialized, marking the consumed `product_serials` rows `status = 'sold'` with `customer_id` and
   `sold_at` populated (this is what makes warranty and recall lookups possible). If batch-controlled,
   decrementing the matching `product_batches.quantity_remaining`.
5. Emitting `inventory.stock_sold` with the computed COGS amount, which Accounting consumes to post
   **Debit Cost of Goods Sold (expense)**, **Credit Inventory (asset)** at the computed unit cost — a
   transaction entirely independent of, and reconciled against, the separate **Debit Accounts Receivable
   / Credit Revenue** entry that Sales posts for the invoice itself.

## Return

A return reverses a prior sale (customer return, via a `credit_notes` document from Sales) or a prior
purchase (return-to-vendor, via a `debit_notes` document from Purchasing). Inventory reacts to
`credit_note.posted` (customer return) by inserting a `stock_movements` row with
`movement_type = 'sales_return'`, `direction = 'in'`, incrementing `quantity_on_hand` — but only if the
returned condition is `resellable`; a `damaged` or `unsellable` disposition instead credits
`quantity_damaged` or routes directly to Dispose, never back into sellable on-hand. The unit cost
re-entering the ledger is the same cost at which it left (recorded on the original sale's
`layer_consumption`), reopening that same cost layer rather than fabricating a new one, so that gross
margin history is not distorted. Accounting posts **Debit Inventory**, **Credit Cost of Goods Sold**
(reversing the original COGS entry) for resellable returns. Vendor returns (`debit_note.posted`) mirror
this outbound: `movement_type = 'purchase_return'`, `direction = 'out'`, **Debit Accounts Payable /
Amount Due from Vendor**, **Credit Inventory**.

## Adjust

An adjustment is any quantity or value correction not caused by a sale, purchase, or transfer — cycle
count variances, breakage discovered on a shelf, write-downs for obsolescence, or manual corrections. It
always requires a `reason_code` (`damage`, `theft`, `expiry`, `count_variance`, `data_entry_error`,
`quality_hold_release`, `other`) and, above a company-configured threshold, a second-approver sign-off
before posting (see Business/Validation rules). Adjustments produce a `stock_adjustments` header, a
`stock_movements` row with `movement_type = 'adjustment'` and `direction` derived from the sign of the
quantity delta, and an Accounting event `inventory.adjusted` that posts **Debit Inventory-Shrinkage
Expense** (or **Debit Inventory** for a positive/found adjustment) against **Credit/Debit Inventory** at
the product's current valuation cost.

## Dispose

Disposal permanently removes unsellable stock (expired, recalled, obsolete, condemned by quality
inspection) from `quantity_on_hand` without a resale event. It is modeled as a specialized
`stock_adjustments` row with `reason_code = 'disposal'` and `direction = 'out'`, mandatorily requiring an
attached disposal certificate/photo (via the polymorphic `attachments` table) for regulated categories
(pharmaceuticals, food). Serialized units get `product_serials.status = 'disposed'`; batches get
`product_batches.status = 'disposed'` and `quantity_remaining = 0`. Accounting posts **Debit
Inventory-Write-off Expense**, **Credit Inventory** at full carrying cost — no salvage value assumed
unless a `disposal_recovery_amount` is separately recorded (e.g., scrap sale), in which case a small
recovery journal entry follows.

## Archive

Archival is the terminal, non-financial stage: once a product is fully consumed, a batch is fully
depleted, or a serial is fully disposed/sold and outside any warranty/return window, and the company has
closed the fiscal period in which the last movement occurred, the corresponding `inventory_items` row
(if it reaches zero across all buckets) is soft-deleted (`deleted_at` set) rather than physically removed
— its `stock_movements` history remains queryable forever for audit purposes, and `product_serials`/
`product_batches` rows are never deleted, only status-flagged, since serial/batch history has permanent
recall and warranty relevance even after the item count on hand is zero.

# Inventory Types

QAYD's `products.item_type` (referenced, owned by the Products module) determines which inventory
behaviors apply to a SKU. Inventory enforces different rules per type as follows.

## Raw Materials

Inputs to manufacturing or assembly, never sold directly to end customers. Tracked with full quantity and
valuation like any other stocked item, but typically consumed via a `movement_type = 'production_issue'`
event fired by a (future) Manufacturing module rather than a sale. Raw materials are almost always
batch-controlled when they are chemicals, food ingredients, or pharmaceutical precursors, so that a
downstream finished-goods batch can trace back to the exact raw-material lot used — the foundation of
food-safety and pharma recall compliance. `products.is_stocked = true`, `products.is_purchasable = true`,
`products.is_sellable = false` (unless the company also resells raw materials, which QAYD permits by
simply setting `is_sellable = true`).

## Finished Goods

The output of manufacturing, or simply purchased/produced goods ready for sale. Fully stocked, fully
valued, the default `item_type` for most retail and distribution companies. Finished goods carry the
richest set of behaviors: barcodes for point-of-sale scanning, price-list membership, serial/batch
tracking where applicable, and are the primary subject of demand forecasting.

## Semi-Finished Goods

Work-in-process items that have undergone partial transformation from raw materials but are not yet
sellable — e.g., a cut-but-unsewn garment panel, or a mixed-but-unbottled beverage batch. Tracked in
Inventory exactly like any stocked item (`inventory_items` row, its own valuation), but flagged
`products.is_wip = true`, which the AI Demand Forecasting agent excludes from finished-goods demand
models and which the ABC/XYZ classification treats as a distinct population (WIP is valued by
accumulated cost, not by market price).

## Consumables

Low-value items used internally (packaging tape, cleaning supplies, printer ink) that a company chooses
to track for cost control but not for granular valuation precision. QAYD allows `products.valuation_method
= 'expense_on_receipt'` for this type: the receiving journal entry debits an expense account directly
instead of Inventory, and `inventory_items` is not maintained at all for that SKU (zero valuation
overhead) — or, if the company wants quantity visibility without value precision, `quantity_on_hand` is
tracked while valuation defaults to a company-wide standard cost with no cost-layer detail.

## Services

Non-physical line items (installation labor, subscriptions, warranty extensions) that appear on
`sales_order_items`/`invoice_items` but never touch `inventory_items`, `stock_movements`, or any
warehouse. `products.item_type = 'service'` short-circuits Inventory entirely at the domain-event level:
Sales never emits `inventory.stock_sold` for a service line. Included here only because the same
`products` table spans both physical and service items, and Inventory's event listeners must explicitly
ignore service lines rather than fail on a missing warehouse context.

## Assets

Capitalizable equipment (a forklift, a company vehicle, a server) that a company may still want to track
by location and custodian, but which is depreciated rather than expensed as COGS on disposal. QAYD tracks
asset location via `inventory_items` with `products.item_type = 'asset'`, but valuation events route to a
**Fixed Assets** module/control account instead of Inventory/COGS — receiving an asset debits **Fixed
Assets**, not **Inventory**; there is no "sale" event, only "disposal" (with accumulated depreciation
netting) and "transfer" (relocation between departments/branches), which Inventory still models via the
same `stock_transfers` mechanism used for regular stock, because the physical relocation problem is
identical even though the financial treatment differs.

## Serialized Items

Any item requiring unit-level identity — electronics, vehicles, medical devices, firearms, high-value
tools. `products.tracking_type = 'serial'`. Every unit that enters stock gets exactly one
`product_serials` row; every movement of that item must reference the specific serial(s) involved
(`stock_movements.serial_id`, nullable for non-serialized items, mandatory via a CHECK-adjacent
application-level rule when the product is serialized). Quantity fields on `inventory_items` for a
serialized product are always integers and always equal `COUNT(product_serials WHERE status = 'in_stock'
AND warehouse_id = X)` — the serial table is the ground truth, and `inventory_items.quantity_on_hand` is a
cached count for query performance.

## Batch Controlled Items

Items produced or received in discrete lots that share a manufacturing date, supplier lot number, or
quality-control result, but where individual units are fungible within the lot — most pharmaceuticals,
cosmetics, and industrial chemicals. `products.tracking_type = 'batch'`. Every receipt creates or adds to
a `product_batches` row; every outbound movement decrements a specific batch's `quantity_remaining`
(chosen by FIFO-by-batch, oldest-expiry-first, or a manually specified batch when contractually required).
Quality holds are modeled as `product_batches.status = 'quarantined'`, which the reservation and sale
logic refuses to draw from until released.

## Lot Controlled Items

QAYD treats "lot controlled" as a synonym for batch-controlled with an emphasis on traceability rather
than expiry (common in metals, textiles, and construction materials where a "lot" identifies a production
run for quality/defect-liability purposes rather than a shelf life). The same `product_batches` table
serves both use cases; `product_batches.expiration_date` is simply left NULL for lot-controlled-but-
non-perishable items, and the Batch Numbers section below applies identically.

## Digital Products

License keys, downloadable software, or digital certificates. `products.item_type = 'digital'`. These are
"stocked" only in the sense of a finite pool of unique keys, modeled as serialized items
(`tracking_type = 'serial'`, `product_serials.serial_value` holding the license key text instead of a
physical serial) with `warehouse_id` pointing to a virtual "digital" warehouse that has no physical
address. There is no receiving/putaway physicality — keys are bulk-inserted via the Import API — but the
serial-consumption-on-sale logic, and the "which customer has key X" traceability, work identically to
physical serialized goods, which is precisely why QAYD reuses the serial mechanism rather than inventing a
parallel one.

# Stock Tracking

`inventory_items` maintains eight distinct, simultaneously-tracked quantity buckets per
`(company_id, product_id, warehouse_id, bin_id)`. Every bucket is a `NUMERIC(18,4)` column, non-negative
by CHECK constraint (except where explicitly noted), and every bucket's value must equal the signed sum of
the `stock_movements` rows that feed it — this equality is verified nightly by a reconciliation job and
any drift raises a P1 alert (see Performance and Security sections).

| Bucket | Column | Increases on | Decreases on | Included in "Available to Sell"? |
|---|---|---|---|---|
| Available | `quantity_on_hand` | Purchase receipt, sales return, positive adjustment, transfer-in, production receipt | Sale, purchase return, negative adjustment, transfer-out, disposal | Yes, minus Reserved/Damaged/Quarantined |
| Reserved | `quantity_reserved` | Sales order confirmation, transfer dispatch request, production allocation | Sales fulfillment/cancellation, reservation expiry, transfer completion | No (subtracted) |
| On Order | `quantity_on_order` | Purchase order approval | Goods receipt, PO cancellation/line reduction | No |
| In Transit | `quantity_in_transit` | Transfer dispatch | Transfer receipt at destination, transfer cancellation (reverses to source) | No |
| Damaged | `quantity_damaged` | Damage adjustment, damaged goods receipt | Disposal, repair-and-restock adjustment | No |
| Returned | *(virtual — tracked via `stock_movements.movement_type IN ('sales_return','purchase_return')`, folds into on-hand or damaged depending on disposition)* | — | — | Conditional |
| Expired | *(virtual — derived from `product_batches.status = 'expired'` joined to on-hand quantity of that batch)* | — | — | No |
| Quarantined | `quantity_quarantined` | Quality-hold adjustment, receipt of a batch pending QC | Quality release adjustment, disposal | No |

Two buckets — **Returned** and **Expired** — are deliberately not first-class columns on
`inventory_items`. Returned stock is not a steady state; the instant a return is processed it resolves
into either `quantity_on_hand` (resellable) or `quantity_damaged` (not resellable), so persisting a
separate "returned" number would create a third, disagreeing source of truth. Expired stock is a
time-dependent property of a **batch**, not of a warehouse slot — a batch expires the moment
`CURRENT_DATE > product_batches.expiration_date` regardless of which bin it sits in, so Inventory computes
it on read (or via the nightly expiration-scan job described in Expiration Dates) rather than maintaining
a redundant counter that would need a background job to keep current for every bin simultaneously. Both
are exposed identically to reporting and to the API as computed fields, so from the outside they behave
exactly like the six stored buckets.

**Available to Sell**, the number actually shown to a salesperson or exposed to an e-commerce channel, is
always computed, never stored:

```
available_to_sell = quantity_on_hand - quantity_reserved - quantity_damaged - quantity_quarantined
                     - (quantity_on_hand belonging to batches where status = 'expired')
```

This guarantees that a damaged, quarantined, or expired unit — even though it is physically still sitting
in `quantity_on_hand` until formally adjusted out — can never be sold, without requiring every adjustment
workflow to remember to also decrement on-hand at the exact moment a batch crosses its expiry date.

# Inventory Valuation

QAYD supports six valuation methods, selectable per product via `products.valuation_method`
(`fifo | lifo | weighted_average | specific_identification | standard_cost | moving_average`), because
different product categories within the same company legitimately need different treatments (e.g.,
perishable batch goods on FIFO, high-value serialized assets on Specific Identification, manufactured
goods on Standard Cost with variance analysis). The chosen method determines how `inventory_valuations`
computes the cost consumed by every outbound `stock_movements` row and how ending on-hand value is
reported.

## FIFO (First-In, First-Out)

Outbound movements consume the oldest open cost layer first. `inventory_valuations` stores one row per
receipt "layer" with `quantity_remaining` decremented as it is consumed; a layer with
`quantity_remaining = 0` is closed (`is_open = false`) but never deleted.

**Worked example.** Product SKU `WGT-100`, warehouse `WH-KWT-01`, starting empty.

| Date | Event | Qty | Unit Cost | Layer After Event |
|---|---|---|---|---|
| Jul 1 | Receive PO-1001 | 100 | KWD 2.000 | Layer A: 100 @ 2.000 |
| Jul 5 | Receive PO-1002 | 150 | KWD 2.200 | Layer A: 100 @ 2.000; Layer B: 150 @ 2.200 |
| Jul 10 | Sell 120 units | -120 | — | Consumes all 100 of Layer A (KWD 200.000) + 20 of Layer B (KWD 44.000) = **KWD 244.000 COGS**. Layer A closed; Layer B: 130 @ 2.200 remaining |
| Jul 15 | Sell 50 units | -50 | — | Consumes 50 of Layer B (KWD 110.000 COGS). Layer B: 80 @ 2.200 remaining |

Ending on-hand: 80 units, KWD 176.000 (80 × 2.200), fully attributable to Layer B.

Journal entries posted by Accounting on consumption of the domain events Inventory emits:

```
Jul 1  Dr Inventory                         200.000
       Cr Goods Received Not Invoiced               200.000

Jul 5  Dr Inventory                         330.000
       Cr Goods Received Not Invoiced               330.000

Jul 10 Dr Cost of Goods Sold                244.000
       Cr Inventory                                 244.000

Jul 15 Dr Cost of Goods Sold                110.000
       Cr Inventory                                 110.000
```

## LIFO (Last-In, First-Out)

Structurally identical to FIFO in QAYD's `inventory_valuations` layer model — the only difference is
consumption order: outbound movements consume the **newest** open layer first. LIFO is disabled by
default for companies whose base currency/jurisdiction disallows it for tax reporting (LIFO is not
IFRS-permitted, which governs most GCC jurisdictions including Kuwait); `products.valuation_method =
'lifo'` requires `companies.allow_lifo = true`, a flag the Auditor AI agent checks and flags as a
compliance risk if enabled for an IFRS-reporting company.

**Worked example**, same receipts as above, LIFO instead of FIFO:

| Date | Event | Qty | Consumption | COGS |
|---|---|---|---|---|
| Jul 10 | Sell 120 | Consumes 120 of Layer B (150 @ 2.200) | 120 × 2.200 = KWD 264.000 |
| Jul 15 | Sell 50 | Consumes remaining 30 of Layer B (KWD 66.000) + 20 of Layer A (KWD 40.000) | KWD 106.000 |

Ending on-hand: 80 units — all from Layer A, valued at KWD 160.000 (80 × 2.000). Note LIFO produces a
different ending value (KWD 160.000 vs FIFO's KWD 176.000) and different period COGS (KWD 370.000 total
vs FIFO's KWD 354.000) from the *identical* physical events — the reason valuation method choice is a
disclosed accounting policy, not an operational detail.

## Weighted Average

A single blended cost per product/warehouse is recomputed after every receipt:

```
new_average_cost = (quantity_on_hand_before × old_average_cost + quantity_received × receipt_unit_cost)
                    / (quantity_on_hand_before + quantity_received)
```

**Worked example**, same receipts:

- Jul 1: on-hand 0 → receive 100 @ 2.000 → average = 2.000, on-hand 100.
- Jul 5: on-hand 100 @ 2.000 → receive 150 @ 2.200 → average = (100×2.000 + 150×2.200) / 250 =
  (200 + 330) / 250 = **2.120**, on-hand 250.
- Jul 10: sell 120 @ 2.120 = KWD 254.400 COGS; on-hand 130 @ 2.120.
- Jul 15: sell 50 @ 2.120 = KWD 106.000 COGS; on-hand 80 @ 2.120 = KWD 169.600.

`inventory_valuations` stores weighted-average as a single open row per product/warehouse
(`layer_type = 'weighted_average'`) that is **updated** in place on every receipt (the only valuation
method where a row is mutated rather than a new layer appended), with a full history of the recomputation
preserved in `inventory_valuations_history` — an append-only audit trail of every average recalculation,
required because Weighted Average is the one method where the "layer" itself changes value over time and
that change must still be auditable.

## Specific Identification

Used exclusively for serialized/high-value items where each unit's actual purchase cost is tracked and
consumed on that exact unit's sale, sourced directly from `product_serials.purchase_cost` (populated at
receiving) rather than from a FIFO/LIFO/average layer. **Worked example**: three individually imported
vehicles, VINs SN-01 (cost KWD 4,200), SN-02 (cost KWD 4,350), SN-03 (cost KWD 4,100). Selling SN-02 posts
COGS of exactly KWD 4,350 — the other two units' cost is entirely unaffected, and there is no "layer" to
consume from; the journal entry is **Debit COGS 4,350.000 / Credit Inventory 4,350.000**, referencing
`product_serials.id = SN-02` in the movement's `reference_id` for full traceability.

## Standard Cost

Used mainly for manufactured or budgeted goods where a pre-set cost (`products.standard_cost`) is used for
all movements during a period, with variances captured separately rather than blended into inventory
value. **Worked example**: `products.standard_cost = KWD 2.100` for `WGT-100`. The Jul 1 receipt of 100
units at an actual cost of KWD 2.000 posts:

```
Dr Inventory (at standard)          210.000   (100 × 2.100)
Cr Goods Received Not Invoiced (at actual)   200.000   (100 × 2.000)
Cr Purchase Price Variance                   10.000   (favorable variance, credited as a gain)
```

Every subsequent sale of `WGT-100` posts COGS at the flat KWD 2.100 standard, regardless of which
receipt's actual cost it "physically" came from — inventory value never drifts with actual cost
fluctuation, and all variance is isolated in the **Purchase Price Variance** account for management
review, exactly matching the SAP/Oracle standard-costing pattern.

## Moving Average

Distinguished from Weighted Average by recomputing on **every** movement (including negative adjustments
and returns), not only on receipt, and by never resetting to a fresh average — it is the continuously
"moving" running cost. Functionally it produces identical numbers to Weighted Average in the pure-receipt
example above; the difference surfaces when a **negative** adjustment or a **return-to-stock at a
different cost** occurs, which Weighted Average would otherwise ignore between receipts. QAYD implements
both as the same `layer_type = 'weighted_average'` row in `inventory_valuations` with a
`recompute_trigger` flag (`on_receipt_only` for Weighted Average, `on_every_movement` for Moving Average)
controlling which movement types invoke the recomputation function — avoiding a second near-duplicate
code path for what is, mechanically, the same formula applied at a different frequency.

# Inventory Operations

## Receiving

Receiving is initiated from an open `purchase_order` or, for unplanned receipts (found stock, gifts,
manufacturing output), a standalone receipt against a virtual "no PO" source. The warehouse clerk (or
handheld scanner app) scans each carton's barcode, confirms quantity against the expected line, and
optionally records a supplier lot/batch number and expiration date at this step. QAYD supports both
**blind receiving** (quantities entered without seeing the expected PO quantity, to catch over/under-ship
discrepancies honestly) and **guided receiving** (expected quantity shown, exceptions flagged in red).
Over-receipt beyond a configurable tolerance (`warehouses.receipt_tolerance_pct`, default 5%) requires a
Purchasing Manager approval before the `goods_receipt` posts.

## Picking

Picking generates a pick list from confirmed `stock_reservations` for orders ready to ship, sequenced by
the warehouse's slotting logic (shortest walking path across zones/aisles, computed from
`warehouse_bins.pick_sequence`) rather than by order-line order. QAYD supports single-order picking,
batch picking (multiple orders' identical SKUs picked together then sorted at a pack station), and
wave picking (a scheduled release of many orders' pick lists at once, e.g., every two hours). A pick is
recorded as a `stock_movements` row with `movement_type = 'pick'` only if the company's configuration
treats "picked" as a distinct state from "shipped" (useful for large 3PL operations); smaller companies
skip straight to the "sale" movement at ship time.

## Packing

Packing confirms the picked quantity matches the order, applies packaging (recorded for shipping-cost
allocation, not for inventory value), and generates the shipping label. No `stock_movements` row is
created at packing in the default configuration — the movement happens at Ship — but QAYD exposes a
`fulfillment_status` state machine (`pending → picking → picked → packed → shipped`) on the
`stock_reservations` row so the warehouse UI can show granular progress without inflating the immutable
movement ledger with non-quantity-changing checkpoints.

## Shipping

Shipping is the trigger for the "Sell" lifecycle stage described above: the carrier pickup or
customer hand-off event, sourced from Sales' `delivery.shipped` event, produces the outbound
`stock_movements` row, releases the reservation, and posts COGS. Partial shipments against a single sales
order are fully supported — each partial delivery is its own `stock_movements` batch tied to its own
`delivery_id`.

## Transfer

Covered in depth under Inventory Lifecycle → Transfer. Operationally, a transfer can be initiated
manually by a Warehouse Manager, automatically by the AI Warehouse Optimization agent's rebalancing
suggestion (draft only, requires approval), or automatically by a **replenishment rule** (e.g., "when
Branch B's on-hand for SKU X falls below 10, transfer 50 from the Central Warehouse" — configured per
product/warehouse pair, executed as a system-generated draft transfer awaiting approval unless the company
enables `auto_approve_replenishment_transfers`).

## Adjustment

Covered under Inventory Lifecycle → Adjust. Adjustments always require `reason_code` and, above
`companies.adjustment_approval_threshold` (a base-currency value, default KWD 100 equivalent), a second
approver with `inventory.adjust.approve` permission distinct from the creator.

## Cycle Count

A cycle count is a partial, rolling physical verification — typically a subset of SKUs or a subset of
bins counted on a recurring schedule (e.g., "count all A-class SKUs weekly, B-class monthly, C-class
quarterly," a schedule the AI ABC Analysis agent can propose). A `stock_counts` header with
`count_type = 'cycle'` is created, scoped to specific `warehouse_id`/`bin_id`/`product_id` filters, and one
`stock_count_lines` row per counted SKU/bin is generated with the system's expected quantity
pre-populated (visible or hidden depending on `blind_count` setting). Counters enter actual quantities;
variances beyond a tolerance threshold trigger a required recount before the count can close.

## Physical Inventory

A full physical inventory freezes all outbound movement for the counted scope (typically an entire
warehouse) for its duration — `warehouses.count_in_progress = true` blocks new `stock_reservations` and
outbound `stock_movements` against that warehouse at the application layer, returning a `409 Conflict`
with `error_code = 'WAREHOUSE_COUNT_LOCK'` on any attempted sale/transfer/pick. `stock_counts` with
`count_type = 'physical'` typically requires 100% of active SKUs in scope to have a submitted count line
before it can be closed, unlike a cycle count, which may close with partial coverage.

## Stock Reservation

Covered under Inventory Lifecycle → Reserve.

## Stock Release

The inverse of reservation: cancelling a `sales_order` line, an expired reservation, or a rejected
transfer request releases the held quantity back to `quantity_on_hand` availability by setting
`stock_reservations.status = 'released'` and decrementing `quantity_reserved`. Release is idempotent —
releasing an already-released or already-fulfilled reservation is a no-op that returns success, not an
error, so that retried webhook deliveries from Sales cannot double-release or error out.

## Backorders

When a confirmed sales order line's quantity exceeds `available_to_sell`, Inventory does not reject the
reservation outright — it creates a **partial reservation** for the available quantity and a
`stock_reservations` row with `status = 'backordered'` for the shortfall, linked to the triggering
`purchase_order` if the AI Reorder Prediction agent (or a manual buyer action) has already raised one, or
flagged `needs_purchase_order = true` if not. Backordered reservations auto-convert to `active` (and
notify Sales via `inventory.backorder_available`) the instant a receiving event brings on-hand above the
outstanding backordered quantity, honoring first-in-first-served order among competing backorders for the
same SKU by `stock_reservations.created_at`.

# Serial Numbers

Serial numbers give a single stocked unit a unique, permanent identity across its entire lifecycle in the
company's books — from receipt, through every location it occupies, to sale (with the buying customer
recorded), to return, warranty service, or disposal. QAYD stores serials in `product_serials`, one row per
physical unit, never reused even after disposal (a disposed serial's row persists with
`status = 'disposed'` forever; a new unit with a coincidentally identical manufacturer serial string gets
its own row disambiguated by `(product_id, serial_value)` uniqueness scoped per company, with a
`duplicate_flag` the Fraud Detection AI agent raises if an identical serial string appears twice
`in_stock` simultaneously — a near-certain data-entry error or counterfeit signal).

Each `product_serials` row carries: the product and warehouse/bin it currently occupies (`NULL` once
sold/disposed), its `status` (`in_stock | reserved | in_transit | sold | returned | damaged | disposed |
recalled`), its `purchase_cost` (for Specific Identification valuation), acquisition date, the
`customer_id` and `sold_at` once sold, and an optional `warranty_expires_at`. Every `stock_movements` row
for a serialized product must reference exactly one `product_serials.id` — the application layer rejects
any attempt to move a quantity greater than 1 for a serialized SKU in a single movement row; each unit
moves as its own row, which is intentionally more granular than non-serialized movements but is what makes
"show me every event serial SN-04821 has ever been through" a single indexed query rather than an
inference from aggregate quantities.

Recalls are executed by flipping every affected `product_serials.status` to `recalled` in one bulk
operation filtered by `product_id` + `batch_id`/date-range criteria, which immediately (a) removes those
units from `available_to_sell` even if physically still `in_stock`, and (b) surfaces every already-sold
unit's `customer_id` for the Notifications module to alert, which is the single most important compliance
capability the serial model exists to provide.

# Batch Numbers

Batch (lot) numbers group fungible units that share a production run, supplier consignment, or
quality-control disposition, in `product_batches`. Unlike serials, a batch's `quantity_remaining` is a
single decrementing number — individual units within a batch are not distinguished from each other. Each
row carries: `batch_number` (unique per `product_id`, sourced from the supplier's lot code or generated
internally for in-house production), `quantity_received`, `quantity_remaining`, `manufactured_date`,
`expiration_date` (nullable for non-perishable lot-controlled goods), `status`
(`active | quarantined | expired | recalled | disposed`), `supplier_id`/`vendor_id` reference, and an
optional `quality_inspection_id` linking to the Purchasing module's QC record.

Outbound movements against a batch-controlled product select the consuming batch by one of three
strategies, configured per product (`products.batch_selection_strategy`): **FEFO** (First-Expired,
First-Out — the default and strongly recommended for perishables), **FIFO-by-receipt-date**, or
**manual** (the picker/salesperson explicitly chooses, required when a customer contract specifies a lot).
A single sales order line can, and often does, draw from multiple batches to satisfy its quantity — each
contributing batch's consumption is recorded as its own `stock_movements` row so that "which batches went
to customer Y" is answerable with the same precision serials provide, just aggregated at lot rather than
unit granularity.

Batch genealogy — which raw-material batches went into which finished-goods batch — is modeled via
`product_batches.parent_batch_ids` (a JSONB array of contributing batch IDs), populated by a future
Manufacturing module's production-completion event; Inventory stores and exposes this genealogy but does
not compute it, since the transformation logic belongs to Manufacturing.

# Expiration Dates

Every `product_batches` row carrying a non-null `expiration_date` is subject to a nightly scheduled job
(`inventory.batch_expiry_scan`) that: (1) flips `status` to `expired` for any batch whose
`expiration_date < CURRENT_DATE` and that is not already `disposed`; (2) removes expired batches'
remaining quantity from `available_to_sell` computations immediately (per the Stock Tracking section's
derived formula), without waiting for a manual adjustment; (3) emits `inventory.batch_expired` for every
newly-expired batch, which the Notifications module turns into an alert to the Inventory Manager and
Warehouse Manager roles, and which the AI Dead Stock Detection agent consumes as a strong signal.

Expiration is also proactively surfaced **before** the expiry date: `warehouses.expiry_warning_days`
(default 30) configures a lookahead window in which batches nearing expiry appear on the "Expiring Soon"
report (see Reports) and are excluded from FEFO-based new reservations in favor of even-older stock only
if a fresher batch exists to substitute — QAYD never lets a batch expire un-sold if an alternative
batch could have been picked earlier and this one pushed to a promotional/clearance channel instead,
which is precisely the recommendation the AI Dead Stock Detection agent drafts.

A batch reaching `status = 'expired'` while `quantity_remaining > 0` does not auto-dispose — disposal
still requires the Dispose lifecycle stage's explicit adjustment and, for regulated categories, its
attached certificate — expiration only removes sellability; physical destruction remains a deliberate,
audited human action.

# Barcode

Every `products` row (owned by the Products module) may carry one or more `product_barcodes` entries
(GTIN/EAN/UPC or company-internal codes), and Inventory is the primary consumer of barcode scans at
receiving, picking, cycle counting, and point-of-sale. A scan resolves to a specific `product_id` (and, if
the barcode encodes a GS1 Application Identifier structure with embedded batch/serial/expiry data — common
in pharma and food — Inventory's scan-parsing function extracts `AI (10)` batch number, `AI (17)`
expiration date, and `AI (21)` serial number directly from the single scanned string, auto-populating the
corresponding `product_batches`/`product_serials` lookup instead of requiring three separate manual
entries). Barcode scanning is the primary input mechanism assumed for all Inventory Operations on a
handheld device; every operation's mobile UI is designed scan-first, keyboard-entry-fallback.

# QR Codes

QR codes serve two distinct purposes in Inventory, both additive to barcode support rather than
replacing it. First, **internal location labeling**: every `warehouse_bins` row (and, at the company's
option, every `warehouse_shelves`/`warehouse_aisles` row) can have a generated QR code
(`warehouse_bins.qr_code_value`, a stable internal URL/UUID) printed and affixed to the physical shelf,
scanned during putaway and picking to confirm the operator is at the correct location — catching a
mis-shelved item before it becomes a phantom stock discrepancy. Second, **serialized-unit QR codes** for
high-value or consumer-facing serialized goods, where the QR encodes a verification URL
(`https://verify.qayd.app/serial/{uuid}`) that a customer can scan to confirm authenticity and warranty
status — a thin, public, read-only view over `product_serials`, exposing only non-sensitive fields
(product name, manufacture date, warranty status) and never company-internal cost or location data.

# RFID Support

RFID is supported as an alternative capture mechanism for the same `product_serials`/`product_batches`
identity model, not a parallel data model: `product_serials.rfid_tag_id` and
`product_batches.rfid_tag_id` store the tag's unique identifier, and a dedicated ingestion endpoint
(`POST /api/v1/inventory/rfid-events`) accepts a batch of `{tag_id, reader_location, timestamp, event_type}`
tuples from warehouse RFID gate readers, resolving each `tag_id` to its serial/batch and generating the
appropriate `stock_movements` row automatically — a gate read at a dock door tagged as "Warehouse B
Inbound" becomes a `transfer_in` movement without any human scanning action. RFID's principal operational
value in QAYD is **bulk simultaneous read** (an entire pallet's tags read in the time a barcode scanner
reads one carton) for high-throughput receiving/shipping docks and for near-real-time cycle counts (a
handheld RFID wand can count an entire rack in seconds, generating `stock_count_lines` rows automatically
rather than via manual entry) — the business logic downstream of the read (movement creation, valuation,
reservation) is identical regardless of whether the read arrived via barcode, RFID, or manual entry.

# Multi Warehouse Support

Warehouses form a strict, optional-depth hierarchy owned by the foundation/Warehouses module and
referenced throughout Inventory: `warehouses -> warehouse_zones -> warehouse_areas -> warehouse_aisles ->
warehouse_rows -> warehouse_shelves -> warehouse_bins`. Every level beneath `warehouses` itself is
optional — a small retailer's `warehouses` row can have `bin_tracking_enabled = false` and every movement
simply targets the warehouse with `bin_id = NULL`, while a high-density 3PL enables the full seven-level
chain and every movement targets a specific `warehouse_bins.id`. `inventory_items` is keyed at whichever
granularity the warehouse enables, and Inventory's aggregation queries always roll up correctly regardless
of depth because every level below `warehouses` carries its own `warehouse_id` for direct filtering
without needing to walk the full parent chain for simple "how much do we have in Warehouse X" questions.

Each warehouse has a `warehouse_type` (`standard | virtual_digital | consignment | quarantine |
in_transit | returns_processing`), which changes default behavior: a `quarantine`-type warehouse
auto-routes every receipt into it to `quantity_quarantined` rather than `quantity_on_hand`; a
`consignment`-type warehouse holds vendor-owned stock that is only valued (and only affects Inventory's
own balance sheet) once actually consumed/sold — received quantity increments `quantity_on_hand` for
visibility, but the receiving event posts **no journal entry** until a `consignment_usage` movement fires,
at which point Inventory posts the deferred receipt and the sale simultaneously.

Cross-warehouse visibility is a core reporting requirement: every stock report (see Reports) supports a
"consolidated across all warehouses of this company" view and a "per warehouse" breakdown in the same
query via a `GROUP BY warehouse_id` toggle, and the AI Warehouse Optimization agent explicitly reasons
across the full warehouse network to recommend stock rebalancing transfers that minimize total holding
cost and stockout risk company-wide, not warehouse-by-warehouse in isolation.

A product can be enabled for stocking in a subset of warehouses only: `inventory_items` rows simply do
not exist for warehouses where a product has never been stocked, and a `products_warehouses` allow-list,
owned by Products, can restrict which warehouses a SKU is *permitted* to be received into at all, catching
accidental cross-warehouse receiving errors before they happen.

# Multi Company Support

Every table Inventory owns carries `company_id NOT NULL`, enforced at both the database (indexed foreign
key) and the application layer (every repository method requires and filters by the active company from
the authenticated request's `X-Company-Id` context, per the platform's API conventions) — Company A's
`stock_movements` are structurally invisible to any query executed on behalf of Company B, with no code
path that joins across `company_id` values. `branch_id` provides an optional second tier of isolation
within a company (e.g., a Kuwait company with separate Salmiya and Fahaheel branches, each potentially
treated as a separate cost center or even a separate legal entity for branch-level financial statements),
nullable because not every company needs branch-level segregation.

Intercompany stock movement (a Kuwait parent company transferring goods to a Saudi subsidiary company) is
explicitly **not** modeled as a `stock_transfers` row, because `stock_transfers` assumes a single
`company_id` on both source and destination. Instead, intercompany stock movement is modeled as a sale
from Company A (posting **Debit Intercompany Receivable / Credit Revenue** and the matching COGS entry)
paired with a purchase into Company B (posting **Debit Inventory / Credit Intercompany Payable**), which
keeps the platform's strict company-isolation rule intact — no table row is ever visible to two
`company_id` values, and no `stock_movements` row for Company A implies a corresponding row for Company B
without going through the full Sales/Purchasing event chain, complete with arm's-length transfer pricing
if the company's tax configuration requires it.

The `product_serials`/`product_batches` uniqueness constraints (`serial_value` unique per
`(company_id, product_id)`, `batch_number` unique per `(company_id, product_id)`) are explicitly scoped by
company, so two unrelated companies on the same QAYD instance can legitimately use identical supplier
batch codes without collision.

# Multi Currency Support

Inventory's physical quantities have no currency, but every valuation figure does. `inventory_valuations`
and `stock_movements` store cost in **both** the transaction currency (the currency the original purchase
was denominated in — e.g., a USD-invoiced import) and the company's base currency (e.g., KWD), following
the platform's standard multi-currency pattern: `unit_cost_currency` (transaction-currency amount,
`NUMERIC(19,4)`), `currency_code` (ISO 4217), `exchange_rate` (`NUMERIC(18,6)`, the rate at the transaction
date), and `unit_cost_base` (`NUMERIC(19,4)`, the base-currency amount = `unit_cost_currency ×
exchange_rate`, computed and stored, never recomputed on read, so historical valuations are never
retroactively altered by later exchange-rate changes).

All Accounting-facing journal entries Inventory's domain events trigger post exclusively in the company's
base currency — Accounting has no concept of "this journal line is in USD," per the platform's rule that
the base-currency amount is what the general ledger records; the transaction-currency figures on
`inventory_valuations` exist purely for cost-transparency reporting (e.g., "what did we actually pay the
US supplier in dollars for this batch") and for re-export to a vendor's own USD-denominated invoice
reconciliation, not for GL posting.

When a batch/layer purchased in one currency is consumed by a sale invoiced in a different currency (e.g.,
USD-cost inventory sold against a KWD sales invoice — the overwhelmingly common case for an importer), no
currency conversion happens at consumption time: the COGS journal entry posts in base currency using the
`unit_cost_base` that was locked in at receipt time, entirely independent of the sale's invoice currency or
of exchange-rate movement between receipt and sale — inventory valuation is never re-marked to a current
exchange rate; only the originating receipt's locked-in rate matters, exactly mirroring how a fixed
asset's depreciable base is not revalued for later FX movement.

# Database Design

All tables below follow the platform-wide standard columns (`id`, `company_id`, `branch_id`,
`created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) in addition to the columns listed
explicitly. Money columns are `NUMERIC(19,4)`, exchange rates `NUMERIC(18,6)`, quantities
`NUMERIC(18,4)`. Every table is scoped by `company_id NOT NULL REFERENCES companies(id)`, indexed.

## inventory_items

The materialized current-state projection: one row per `(company_id, product_id, warehouse_id, bin_id)`.

```sql
CREATE TABLE inventory_items (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    product_id            BIGINT NOT NULL REFERENCES products(id),
    warehouse_id          BIGINT NOT NULL REFERENCES warehouses(id),
    bin_id                BIGINT NULL REFERENCES warehouse_bins(id),
    quantity_on_hand      NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_reserved     NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_on_order     NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_in_transit   NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_damaged      NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_quarantined  NUMERIC(18,4) NOT NULL DEFAULT 0,
    reorder_point         NUMERIC(18,4) NULL,
    reorder_quantity      NUMERIC(18,4) NULL,
    max_stock_level       NUMERIC(18,4) NULL,
    average_cost_base     NUMERIC(19,4) NOT NULL DEFAULT 0,
    last_movement_at      TIMESTAMPTZ NULL,
    last_counted_at       TIMESTAMPTZ NULL,
    created_by            BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    CONSTRAINT chk_inventory_items_nonneg CHECK (
        quantity_on_hand >= 0 AND quantity_reserved >= 0 AND quantity_on_order >= 0 AND
        quantity_in_transit >= 0 AND quantity_damaged >= 0 AND quantity_quarantined >= 0
    ),
    CONSTRAINT chk_inventory_items_reserved_le_onhand CHECK (
        quantity_reserved <= quantity_on_hand
    )
);
CREATE UNIQUE INDEX uq_inventory_items_key
    ON inventory_items (company_id, product_id, warehouse_id, COALESCE(bin_id, 0))
    WHERE deleted_at IS NULL;
CREATE INDEX idx_inventory_items_company_product ON inventory_items (company_id, product_id);
CREATE INDEX idx_inventory_items_company_warehouse ON inventory_items (company_id, warehouse_id);
CREATE INDEX idx_inventory_items_reorder ON inventory_items (company_id, warehouse_id)
    WHERE quantity_on_hand <= reorder_point;
```

## stock_movements

The append-only, immutable quantity ledger. Every physical quantity change in the company is one row
here.

```sql
CREATE TYPE stock_movement_type AS ENUM (
    'purchase_receipt', 'putaway', 'sale', 'sales_return', 'purchase_return',
    'transfer_out', 'transfer_in', 'pick', 'adjustment', 'disposal',
    'consignment_usage', 'production_issue', 'production_receipt', 'count_variance'
);
CREATE TYPE stock_movement_direction AS ENUM ('in', 'out');

CREATE TABLE stock_movements (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    product_id            BIGINT NOT NULL REFERENCES products(id),
    warehouse_id          BIGINT NOT NULL REFERENCES warehouses(id),
    bin_id                BIGINT NULL REFERENCES warehouse_bins(id),
    movement_type         stock_movement_type NOT NULL,
    direction             stock_movement_direction NOT NULL,
    quantity              NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    unit_cost_currency     NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code          CHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate          NUMERIC(18,6) NOT NULL DEFAULT 1,
    unit_cost_base         NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_cost_base         NUMERIC(19,4) NOT NULL GENERATED ALWAYS AS (quantity * unit_cost_base) STORED,
    serial_id              BIGINT NULL REFERENCES product_serials(id),
    batch_id               BIGINT NULL REFERENCES product_batches(id),
    source_type             VARCHAR(40) NOT NULL,   -- 'purchase_order' | 'sales_order' | 'stock_transfer' | 'stock_adjustment' | 'stock_count' | 'delivery' | 'credit_note' | 'debit_note'
    source_id                BIGINT NOT NULL,
    reference_id             BIGINT NULL,             -- freeform pointer (e.g. product_serials.id for Specific ID)
    reversed_movement_id      BIGINT NULL REFERENCES stock_movements(id),
    cost_center_id            BIGINT NULL REFERENCES cost_centers(id),
    project_id                BIGINT NULL REFERENCES projects(id),
    department_id             BIGINT NULL REFERENCES departments(id),
    notes                      TEXT NULL,
    created_by                BIGINT NULL REFERENCES users(id),
    updated_by                BIGINT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                TIMESTAMPTZ NULL
);
CREATE INDEX idx_stock_movements_company_product_wh ON stock_movements (company_id, product_id, warehouse_id, created_at);
CREATE INDEX idx_stock_movements_source ON stock_movements (company_id, source_type, source_id);
CREATE INDEX idx_stock_movements_serial ON stock_movements (serial_id) WHERE serial_id IS NOT NULL;
CREATE INDEX idx_stock_movements_batch ON stock_movements (batch_id) WHERE batch_id IS NOT NULL;
CREATE INDEX idx_stock_movements_created_at ON stock_movements (company_id, created_at);
-- No UPDATE/DELETE grant at the application layer beyond soft-delete of erroneous test data;
-- production corrections always insert a new row with reversed_movement_id set on the original.
```

## stock_reservations

Commits available stock against a demand document before physical movement occurs.

```sql
CREATE TYPE reservation_status AS ENUM ('active', 'backordered', 'fulfilled', 'released', 'expired');

CREATE TABLE stock_reservations (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    product_id         BIGINT NOT NULL REFERENCES products(id),
    warehouse_id       BIGINT NOT NULL REFERENCES warehouses(id),
    bin_id             BIGINT NULL REFERENCES warehouse_bins(id),
    serial_id          BIGINT NULL REFERENCES product_serials(id),
    batch_id           BIGINT NULL REFERENCES product_batches(id),
    quantity_requested NUMERIC(18,4) NOT NULL CHECK (quantity_requested > 0),
    quantity_reserved  NUMERIC(18,4) NOT NULL DEFAULT 0,
    status             reservation_status NOT NULL DEFAULT 'active',
    fulfillment_status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|picking|picked|packed|shipped
    source_type        VARCHAR(40) NOT NULL,   -- 'sales_order' | 'stock_transfer' | 'production_order'
    source_id           BIGINT NOT NULL,
    source_line_id       BIGINT NULL,
    expires_at            TIMESTAMPTZ NULL,
    fulfilled_at           TIMESTAMPTZ NULL,
    released_at            TIMESTAMPTZ NULL,
    needs_purchase_order    BOOLEAN NOT NULL DEFAULT false,
    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL
);
CREATE INDEX idx_stock_reservations_company_product_wh ON stock_reservations (company_id, product_id, warehouse_id, status);
CREATE INDEX idx_stock_reservations_source ON stock_reservations (company_id, source_type, source_id);
CREATE INDEX idx_stock_reservations_expiry ON stock_reservations (expires_at) WHERE status = 'active';
```

## stock_adjustments

Header for a manual correction (damage, shrinkage, count variance, disposal).

```sql
CREATE TYPE adjustment_reason AS ENUM (
    'damage', 'theft', 'expiry', 'count_variance', 'data_entry_error',
    'quality_hold_release', 'disposal', 'other'
);
CREATE TYPE adjustment_status AS ENUM ('draft', 'pending_approval', 'approved', 'posted', 'rejected');

CREATE TABLE stock_adjustments (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT NULL REFERENCES branches(id),
    warehouse_id      BIGINT NOT NULL REFERENCES warehouses(id),
    adjustment_number VARCHAR(30) NOT NULL,
    reason_code       adjustment_reason NOT NULL,
    status            adjustment_status NOT NULL DEFAULT 'draft',
    total_value_base  NUMERIC(19,4) NOT NULL DEFAULT 0,
    requested_by      BIGINT NOT NULL REFERENCES users(id),
    approved_by       BIGINT NULL REFERENCES users(id),
    approved_at       TIMESTAMPTZ NULL,
    posted_at         TIMESTAMPTZ NULL,
    stock_count_id    BIGINT NULL REFERENCES stock_counts(id),
    notes             TEXT NULL,
    created_by        BIGINT NULL REFERENCES users(id),
    updated_by        BIGINT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ NULL,
    CONSTRAINT uq_stock_adjustments_number UNIQUE (company_id, adjustment_number)
);

CREATE TABLE stock_adjustment_items (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    stock_adjustment_id BIGINT NOT NULL REFERENCES stock_adjustments(id),
    product_id        BIGINT NOT NULL REFERENCES products(id),
    bin_id            BIGINT NULL REFERENCES warehouse_bins(id),
    serial_id         BIGINT NULL REFERENCES product_serials(id),
    batch_id          BIGINT NULL REFERENCES product_batches(id),
    quantity_before   NUMERIC(18,4) NOT NULL,
    quantity_after    NUMERIC(18,4) NOT NULL,
    quantity_delta    NUMERIC(18,4) NOT NULL GENERATED ALWAYS AS (quantity_after - quantity_before) STORED,
    unit_cost_base    NUMERIC(19,4) NOT NULL,
    movement_id       BIGINT NULL REFERENCES stock_movements(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_stock_adjustments_company_status ON stock_adjustments (company_id, status);
CREATE INDEX idx_stock_adjustment_items_adjustment ON stock_adjustment_items (stock_adjustment_id);
```

## stock_transfers / stock_transfer_items

Relocation of stock between warehouses, branches, or bins.

```sql
CREATE TYPE transfer_status AS ENUM (
    'draft', 'pending_approval', 'approved', 'in_transit', 'completed', 'cancelled'
);

CREATE TABLE stock_transfers (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    transfer_number     VARCHAR(30) NOT NULL,
    source_warehouse_id BIGINT NOT NULL REFERENCES warehouses(id),
    destination_warehouse_id BIGINT NOT NULL REFERENCES warehouses(id),
    status              transfer_status NOT NULL DEFAULT 'draft',
    dispatched_at        TIMESTAMPTZ NULL,
    received_at           TIMESTAMPTZ NULL,
    requested_by           BIGINT NOT NULL REFERENCES users(id),
    approved_by             BIGINT NULL REFERENCES users(id),
    auto_generated           BOOLEAN NOT NULL DEFAULT false,   -- true for AI/replenishment-rule transfers
    notes                    TEXT NULL,
    created_by               BIGINT NULL REFERENCES users(id),
    updated_by               BIGINT NULL REFERENCES users(id),
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at               TIMESTAMPTZ NULL,
    CONSTRAINT uq_stock_transfers_number UNIQUE (company_id, transfer_number),
    CONSTRAINT chk_stock_transfers_diff_wh CHECK (source_warehouse_id <> destination_warehouse_id)
);

CREATE TABLE stock_transfer_items (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    stock_transfer_id   BIGINT NOT NULL REFERENCES stock_transfers(id),
    product_id          BIGINT NOT NULL REFERENCES products(id),
    source_bin_id       BIGINT NULL REFERENCES warehouse_bins(id),
    destination_bin_id  BIGINT NULL REFERENCES warehouse_bins(id),
    serial_id           BIGINT NULL REFERENCES product_serials(id),
    batch_id            BIGINT NULL REFERENCES product_batches(id),
    quantity            NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    unit_cost_base      NUMERIC(19,4) NOT NULL DEFAULT 0,
    dispatch_movement_id  BIGINT NULL REFERENCES stock_movements(id),
    receipt_movement_id   BIGINT NULL REFERENCES stock_movements(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_stock_transfers_company_status ON stock_transfers (company_id, status);
CREATE INDEX idx_stock_transfer_items_transfer ON stock_transfer_items (stock_transfer_id);
```

## stock_counts / stock_count_lines

Cycle counts and physical inventories.

```sql
CREATE TYPE count_type_enum AS ENUM ('cycle', 'physical', 'spot');
CREATE TYPE count_status AS ENUM ('scheduled', 'in_progress', 'pending_review', 'closed', 'cancelled');

CREATE TABLE stock_counts (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT NULL REFERENCES branches(id),
    warehouse_id      BIGINT NOT NULL REFERENCES warehouses(id),
    count_number      VARCHAR(30) NOT NULL,
    count_type        count_type_enum NOT NULL,
    status            count_status NOT NULL DEFAULT 'scheduled',
    blind_count       BOOLEAN NOT NULL DEFAULT true,
    scope_filter      JSONB NOT NULL DEFAULT '{}',  -- {"category_ids":[...],"bin_ids":[...],"abc_class":"A"}
    scheduled_for     DATE NULL,
    started_at        TIMESTAMPTZ NULL,
    closed_at         TIMESTAMPTZ NULL,
    assigned_to       BIGINT NULL REFERENCES users(id),
    reviewed_by       BIGINT NULL REFERENCES users(id),
    created_by        BIGINT NULL REFERENCES users(id),
    updated_by        BIGINT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ NULL,
    CONSTRAINT uq_stock_counts_number UNIQUE (company_id, count_number)
);

CREATE TABLE stock_count_lines (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    stock_count_id    BIGINT NOT NULL REFERENCES stock_counts(id),
    product_id        BIGINT NOT NULL REFERENCES products(id),
    bin_id            BIGINT NULL REFERENCES warehouse_bins(id),
    serial_id         BIGINT NULL REFERENCES product_serials(id),
    batch_id          BIGINT NULL REFERENCES product_batches(id),
    expected_quantity NUMERIC(18,4) NOT NULL,
    counted_quantity  NUMERIC(18,4) NULL,
    variance          NUMERIC(18,4) NULL GENERATED ALWAYS AS (counted_quantity - expected_quantity) STORED,
    recount_required  BOOLEAN NOT NULL DEFAULT false,
    counted_by        BIGINT NULL REFERENCES users(id),
    counted_at        TIMESTAMPTZ NULL,
    adjustment_id     BIGINT NULL REFERENCES stock_adjustments(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_stock_counts_company_status ON stock_counts (company_id, status);
CREATE INDEX idx_stock_count_lines_count ON stock_count_lines (stock_count_id);
CREATE INDEX idx_stock_count_lines_variance ON stock_count_lines (stock_count_id) WHERE variance <> 0;
```

## inventory_valuations

Open/closed cost layers, weighted/moving average buckets, and standard-cost variance records — the
financial detail behind every unit cost consumed.

```sql
CREATE TYPE valuation_layer_type AS ENUM (
    'fifo_layer', 'lifo_layer', 'weighted_average', 'standard_cost', 'specific_identification'
);

CREATE TABLE inventory_valuations (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    product_id            BIGINT NOT NULL REFERENCES products(id),
    warehouse_id          BIGINT NOT NULL REFERENCES warehouses(id),
    layer_type            valuation_layer_type NOT NULL,
    source_movement_id    BIGINT NULL REFERENCES stock_movements(id),
    quantity_original     NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_remaining    NUMERIC(18,4) NOT NULL DEFAULT 0,
    unit_cost_currency    NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code         CHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate         NUMERIC(18,6) NOT NULL DEFAULT 1,
    unit_cost_base        NUMERIC(19,4) NOT NULL DEFAULT 0,
    standard_cost_base    NUMERIC(19,4) NULL,
    variance_base         NUMERIC(19,4) NULL,
    recompute_trigger     VARCHAR(20) NOT NULL DEFAULT 'on_receipt_only', -- on_receipt_only | on_every_movement
    is_open               BOOLEAN NOT NULL DEFAULT true,
    opened_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    closed_at             TIMESTAMPTZ NULL,
    created_by            BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    CONSTRAINT chk_inventory_valuations_remaining CHECK (quantity_remaining >= 0 AND quantity_remaining <= quantity_original)
);
CREATE INDEX idx_inventory_valuations_open_layers ON inventory_valuations (company_id, product_id, warehouse_id, opened_at)
    WHERE is_open = true;
CREATE INDEX idx_inventory_valuations_company_product ON inventory_valuations (company_id, product_id);

-- Append-only recomputation history for weighted/moving average (never mutated once written)
CREATE TABLE inventory_valuations_history (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    inventory_valuation_id BIGINT NOT NULL REFERENCES inventory_valuations(id),
    triggering_movement_id BIGINT NULL REFERENCES stock_movements(id),
    quantity_before        NUMERIC(18,4) NOT NULL,
    quantity_after         NUMERIC(18,4) NOT NULL,
    unit_cost_before_base  NUMERIC(19,4) NOT NULL,
    unit_cost_after_base   NUMERIC(19,4) NOT NULL,
    recorded_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_inv_val_history_valuation ON inventory_valuations_history (inventory_valuation_id, recorded_at);
```

## product_serials

Unit-level identity for serialized/digital items.

```sql
CREATE TYPE serial_status AS ENUM (
    'in_stock', 'reserved', 'in_transit', 'sold', 'returned', 'damaged', 'disposed', 'recalled'
);

CREATE TABLE product_serials (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    product_id          BIGINT NOT NULL REFERENCES products(id),
    serial_value        VARCHAR(120) NOT NULL,
    rfid_tag_id         VARCHAR(120) NULL,
    warehouse_id        BIGINT NULL REFERENCES warehouses(id),
    bin_id              BIGINT NULL REFERENCES warehouse_bins(id),
    status              serial_status NOT NULL DEFAULT 'in_stock',
    purchase_cost_base  NUMERIC(19,4) NOT NULL DEFAULT 0,
    goods_receipt_id    BIGINT NULL,
    customer_id         BIGINT NULL REFERENCES customers(id),
    sold_at             TIMESTAMPTZ NULL,
    sale_reference_id   BIGINT NULL,
    warranty_expires_at DATE NULL,
    duplicate_flag      BOOLEAN NOT NULL DEFAULT false,
    disposed_at         TIMESTAMPTZ NULL,
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    CONSTRAINT uq_product_serials_value UNIQUE (company_id, product_id, serial_value)
);
CREATE INDEX idx_product_serials_company_status ON product_serials (company_id, status);
CREATE INDEX idx_product_serials_customer ON product_serials (customer_id) WHERE customer_id IS NOT NULL;
CREATE INDEX idx_product_serials_rfid ON product_serials (rfid_tag_id) WHERE rfid_tag_id IS NOT NULL;
```

## product_batches

Lot-level identity for batch/lot-controlled items.

```sql
CREATE TYPE batch_status AS ENUM ('active', 'quarantined', 'expired', 'recalled', 'disposed');

CREATE TABLE product_batches (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    product_id          BIGINT NOT NULL REFERENCES products(id),
    batch_number        VARCHAR(80) NOT NULL,
    rfid_tag_id         VARCHAR(120) NULL,
    vendor_id           BIGINT NULL REFERENCES vendors(id),
    quality_inspection_id BIGINT NULL REFERENCES quality_inspections(id),
    manufactured_date   DATE NULL,
    expiration_date     DATE NULL,
    quantity_received   NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_remaining  NUMERIC(18,4) NOT NULL DEFAULT 0,
    status              batch_status NOT NULL DEFAULT 'active',
    parent_batch_ids    JSONB NOT NULL DEFAULT '[]',
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    CONSTRAINT uq_product_batches_number UNIQUE (company_id, product_id, batch_number),
    CONSTRAINT chk_product_batches_remaining CHECK (quantity_remaining >= 0 AND quantity_remaining <= quantity_received)
);
CREATE INDEX idx_product_batches_company_status ON product_batches (company_id, status);
CREATE INDEX idx_product_batches_expiry ON product_batches (expiration_date) WHERE status = 'active';
```

## Relationships summary

- `inventory_items` N:1 `products`, `warehouses`, `warehouse_bins`; 1:N derived from `stock_movements`.
- `stock_movements` N:1 `products`, `warehouses`, `warehouse_bins`, `product_serials`, `product_batches`;
  polymorphic N:1 to its `source_type`/`source_id` (invoice, bill, transfer, adjustment, count).
- `stock_reservations` N:1 `products`, `warehouses`; polymorphic to `sales_orders`/`stock_transfers`.
- `stock_transfers` 1:N `stock_transfer_items`; each item N:1 `products`, optionally `product_serials`/
  `product_batches`.
- `stock_counts` 1:N `stock_count_lines`; each line optionally produces one `stock_adjustment_items` row.
- `inventory_valuations` N:1 `products`, `warehouses`; 1:N `inventory_valuations_history`.
- `product_serials`/`product_batches` N:1 `products`; referenced by `stock_movements`,
  `stock_reservations`, `stock_transfer_items`, `stock_count_lines`.

## History, Snapshots, and Versioning

Three complementary mechanisms give Inventory a complete historical record without duplicating data:

1. **Movement-level history** — `stock_movements` is itself the history; nothing is ever purged. Any
   "as of date X" quantity can be reconstructed by summing signed movement quantities up to that
   timestamp: `SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END) FILTER (WHERE created_at <= X)`.
2. **Period-end snapshots** — a nightly/period-close job writes `inventory_snapshots` (one row per
   `inventory_items` key per fiscal period close) freezing `quantity_on_hand` and its base-currency value
   at that instant, so financial-statement queries for a closed period never have to replay millions of
   movement rows; only open/current periods compute live.

```sql
CREATE TABLE inventory_snapshots (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    fiscal_period_id  BIGINT NOT NULL REFERENCES fiscal_periods(id),
    product_id        BIGINT NOT NULL REFERENCES products(id),
    warehouse_id      BIGINT NOT NULL REFERENCES warehouses(id),
    quantity_on_hand  NUMERIC(18,4) NOT NULL,
    value_base        NUMERIC(19,4) NOT NULL,
    snapshot_taken_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_inventory_snapshots UNIQUE (company_id, fiscal_period_id, product_id, warehouse_id)
);
CREATE INDEX idx_inventory_snapshots_period ON inventory_snapshots (company_id, fiscal_period_id);
```

3. **Row-level versioning via `updated_at`/audit_logs** — every UPDATE on a mutable row
   (`inventory_items`, `stock_reservations`, `stock_adjustments`, `stock_transfers`, `stock_counts`,
   `product_serials`, `product_batches`) fires an application-level audit hook writing before/after JSON
   into the foundation `audit_logs` table, giving field-level versioning without a parallel
   `*_versions` table per entity — consistent with the platform-wide audit-log rule that every mutation
   is logged with old value, new value, actor, and timestamp.

# AI Responsibilities

Inventory hosts primarily the **Inventory Manager** agent, with material support from the
**Forecast Agent**, **Fraud Detection** agent, and **Auditor** agent. Every agent below obeys the
platform-wide AI governance rule: it operates under the permissions of the acting user/service account,
never bypasses authorization, never writes to `inventory_items`/`stock_movements`/`inventory_valuations`
directly, and every output carries a `confidence_score` (0.00–1.00), a `reasoning` string, and an array of
`supporting_document_ids` (the specific `stock_movements`/`stock_reservations`/`sales_order` rows the
recommendation is based on).

| Responsibility | Inputs | Outputs | Autonomy | Confidence Handling |
|---|---|---|---|---|
| Demand Forecasting | 12–24 months of `stock_movements` (sale direction), seasonality calendar, `sales_orders` pipeline | Predicted daily/weekly demand per product/warehouse for the next 30/60/90 days | Suggest-only | Below 0.6 confidence, forecast is shown with a "low confidence — insufficient history" badge and excluded from auto-reorder calculations |
| Stock Optimization | Current `inventory_items` buckets, forecast output, holding-cost assumptions, service-level target | Recommended `reorder_point`/`max_stock_level` per product/warehouse | Suggest-only, written to a draft field (`inventory_items.suggested_reorder_point`) pending Inventory Manager approval to promote it to `reorder_point` | Confidence tied to forecast confidence; both surfaced together |
| Reorder Prediction | `inventory_items.quantity_on_hand` vs `reorder_point`, `quantity_on_order`, lead-time data from `vendors` | Draft `purchase_requests` with quantity/vendor/expected-date pre-filled | Requires-approval (never auto-creates a `purchase_order`, only a `purchase_request` a human promotes) unless `companies.auto_approve_reorders_below` threshold is configured | Confidence reflects forecast + lead-time variance; requests below threshold auto-route to Purchasing queue, above threshold flagged for Purchasing Manager review |
| Dead Stock Detection | `stock_movements` (no outbound in N days per `companies.dead_stock_threshold_days`, default 180), `inventory_valuations` (tied-up capital) | Ranked dead-stock list with tied-up value, days-since-last-sale, disposal/markdown/promotion recommendation | Suggest-only | High confidence when zero sales for 2x threshold; moderate confidence used for "slowing" flags near the threshold |
| ABC Analysis | 12 months of `stock_movements` value (quantity × unit_cost_base), revenue contribution | `products.abc_class` (`A`/`B`/`C`) assignment, recomputed monthly | Auto-write to a non-financial classification field only (no approval needed — it's a label, not a ledger change) | Reported with the % of total value each class represents; low-volume-history products default to class C with a "new SKU" flag |
| XYZ Analysis | Same movement history, coefficient-of-variation of demand | `products.xyz_class` (`X` stable / `Y` variable / `Z` erratic) | Auto-write, same rationale as ABC | Confidence expressed as the CoV value itself, shown alongside the letter grade |
| Shrinkage Detection | `stock_adjustments` (negative, reason `theft`/`damage`), `stock_counts` variances, movement timing anomalies (e.g., large negative adjustment shortly before a scheduled count) | Flagged product/warehouse/employee combinations with unusually high shrinkage rate | Suggest-only, surfaced to Auditor and Inventory Manager roles | Confidence scales with sample size and deviation from the warehouse's historical shrinkage baseline |
| Fraud Detection | Duplicate serial flags, adjustment patterns clustered around specific `created_by` users, unusually frequent manual overrides of FEFO/FIFO batch selection, round-number adjustments | Fraud-risk case with linked evidence rows | Requires-approval — never blocks a user or reverses a transaction automatically; escalates to Auditor role for human investigation | Confidence includes a breakdown per signal (e.g., "3 duplicate-serial events + 2 after-hours adjustments = 0.78") |
| Anomaly Detection | Real-time stream of `stock_movements`, statistical baseline per product/warehouse | Alerts on movements outside N standard deviations of normal (e.g., a single adjustment removing 40% of on-hand) | Suggest-only, immediate notification, does not block the movement (movements already posted are immutable) | Threshold-tunable per company; false-positive rate tracked and fed back to recalibrate the baseline |
| Purchase Suggestions | Combines Reorder Prediction, Dead Stock Detection (to avoid over-ordering slow movers), vendor lead times and MOQs | Consolidated buy-list grouped by vendor, ready to become one or more `purchase_requests` | Suggest-only | Aggregate confidence shown per vendor group, driven by the weakest link (lowest-confidence line in the group) |
| Warehouse Optimization | Bin-level pick frequency, travel-distance model between bins, current slotting | Re-slotting recommendations (move SKU X from bin A to bin B) and cross-warehouse rebalancing transfer drafts | Suggest-only; rebalancing transfers are created as `stock_transfers` with `status='draft'`, `auto_generated=true`, requiring a Warehouse Manager to approve before dispatch | Confidence tied to the statistical significance of the pick-frequency sample (a bin with 3 picks/month gets lower confidence than one with 300) |

# Reports

All reports below are backed by `report_definitions`/`report_runs` (foundation Reports module) with
Inventory supplying the report's SQL/query definition and parameter schema. Every report supports
`company_id` scoping (mandatory), optional `warehouse_id`/`branch_id` filters, a date range, and export to
CSV/XLSX/PDF via the shared export pipeline.

| Report | Primary Source | Key Columns | Typical Filter |
|---|---|---|---|
| Inventory Valuation | `inventory_items` + `inventory_valuations` | Product, Warehouse, Qty on Hand, Unit Cost, Total Value (base currency), Valuation Method | As-of date, warehouse |
| Stock Movement | `stock_movements` | Date, Product, Movement Type, Direction, Qty, Unit Cost, Source Document | Date range, movement type, warehouse |
| Inventory Aging | `inventory_valuations` (open layers) | Product, Layer Age (days since receipt), Qty Remaining, Value | Age bucket (0-30/31-60/61-90/90+) |
| Dead Stock | `stock_movements` (last outbound) + `inventory_items` | Product, Days Since Last Sale, Qty on Hand, Tied-up Value, AI Recommendation | Threshold days |
| Fast Moving | `stock_movements` (outbound velocity) | Product, Units Sold/Period, Turnover Rate | Period, top-N |
| Slow Moving | Same as Fast Moving, inverted sort | Product, Units Sold/Period, Days of Supply Remaining | Period, bottom-N |
| ABC | `products.abc_class` + valuation | Product, Class, % of Total Value, Cumulative % | Class filter |
| XYZ | `products.xyz_class` + demand CoV | Product, Class, Coefficient of Variation | Class filter |
| Turnover Ratio | COGS (from `stock_movements` sale rows) / Average Inventory Value | Product/Category, Turnover Ratio, Days Inventory Outstanding | Period, category |
| Reorder | `inventory_items` vs `reorder_point` | Product, Warehouse, On Hand, Reorder Point, Suggested Qty, Vendor | Warehouse, below-threshold only |
| Out of Stock | `inventory_items` where `available_to_sell <= 0` | Product, Warehouse, Last Sale Date, Open Backorders | Warehouse, category |
| Forecast | AI Demand Forecasting output | Product, Predicted Demand (30/60/90d), Confidence, Recommended Action | Horizon, confidence threshold |

# API

All endpoints are versioned under `/api/v1/inventory/`, require a Bearer (Sanctum/JWT) token, and are
scoped to the active company via the `X-Company-Id` header. Every response uses the platform's standard
envelope (`success`, `data`, `message`, `errors`, `meta`, `request_id`, `timestamp`).

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | /api/v1/inventory/items | inventory.read | List `inventory_items` with filters (product, warehouse, bin, below-reorder) |
| GET | /api/v1/inventory/items/{id} | inventory.read | Retrieve a single inventory item with current buckets |
| POST | /api/v1/inventory/movements | inventory.movements.create | Create a manual stock movement (rarely used directly; most movements are system-generated from domain events) |
| GET | /api/v1/inventory/movements | inventory.read | List movement history with filters |
| POST | /api/v1/inventory/adjustments | inventory.adjust | Create a stock adjustment (draft) |
| POST | /api/v1/inventory/adjustments/{id}/submit | inventory.adjust | Submit an adjustment for approval |
| POST | /api/v1/inventory/adjustments/{id}/approve | inventory.adjust.approve | Approve and post an adjustment |
| POST | /api/v1/inventory/transfers | inventory.transfer.create | Create a stock transfer (draft) |
| POST | /api/v1/inventory/transfers/{id}/dispatch | inventory.transfer.dispatch | Dispatch an approved transfer (posts transfer_out movement) |
| POST | /api/v1/inventory/transfers/{id}/receive | inventory.transfer.receive | Receive a transfer at destination (posts transfer_in movement) |
| POST | /api/v1/inventory/reservations | inventory.reserve | Create a stock reservation against a source document |
| POST | /api/v1/inventory/reservations/{id}/release | inventory.release | Release a reservation back to available stock |
| GET | /api/v1/inventory/search | inventory.read | Full-text/barcode/serial/batch search across products and their stock |
| POST | /api/v1/inventory/import | inventory.import | Bulk import opening balances / stock take results (CSV/XLSX) |
| GET | /api/v1/inventory/export | inventory.export | Bulk export current stock or movement history |
| POST | /api/v1/inventory/bulk-adjust | inventory.adjust | Bulk-create adjustments from a cycle-count or RFID-scan batch |
| POST | /api/v1/inventory/rfid-events | inventory.movements.create | Ingest a batch of RFID gate-reader events |
| GET | /api/v1/inventory/counts | inventory.count.read | List stock counts |
| POST | /api/v1/inventory/counts | inventory.count.create | Create a cycle or physical count |
| POST | /api/v1/inventory/counts/{id}/lines | inventory.count.submit | Submit counted quantities for count lines |
| POST | /api/v1/inventory/counts/{id}/close | inventory.count.close | Close a count, auto-generating variance adjustments |

## Sample: Create Adjustment (POST /api/v1/inventory/adjustments)

Request:
```json
{
  "warehouse_id": 12,
  "reason_code": "damage",
  "notes": "Water damage on shelf B-04-02 discovered during morning walk-through",
  "items": [
    {
      "product_id": 4501,
      "bin_id": 8832,
      "quantity_before": 60,
      "quantity_after": 52,
      "batch_id": 9911
    }
  ]
}
```

Response (201):
```json
{
  "success": true,
  "data": {
    "id": 771,
    "adjustment_number": "ADJ-2026-000771",
    "status": "pending_approval",
    "total_value_base": "16.8000",
    "items": [
      {
        "id": 1502,
        "product_id": 4501,
        "quantity_before": "60.0000",
        "quantity_after": "52.0000",
        "quantity_delta": "-8.0000",
        "unit_cost_base": "2.1000"
      }
    ]
  },
  "message": "Adjustment created and routed for approval (exceeds KWD 100 threshold)",
  "errors": [],
  "meta": {},
  "request_id": "b7e1c9a2-3f4d-4a11-9c2e-5d8f0a1b2c3d",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

## Sample: Create Transfer (POST /api/v1/inventory/transfers)

Request:
```json
{
  "source_warehouse_id": 3,
  "destination_warehouse_id": 7,
  "items": [
    { "product_id": 2210, "quantity": 40, "source_bin_id": 1201 },
    { "product_id": 2298, "quantity": 15, "batch_id": 6602 }
  ],
  "notes": "Weekly replenishment to Fahaheel branch"
}
```

Response (201):
```json
{
  "success": true,
  "data": {
    "id": 340,
    "transfer_number": "TRF-2026-000340",
    "status": "pending_approval",
    "source_warehouse_id": 3,
    "destination_warehouse_id": 7,
    "items": [
      { "id": 890, "product_id": 2210, "quantity": "40.0000" },
      { "id": 891, "product_id": 2298, "quantity": "15.0000", "batch_id": 6602 }
    ]
  },
  "message": "Transfer created, awaiting Warehouse Manager approval",
  "errors": [],
  "meta": {},
  "request_id": "c2a4d5e6-1122-4b33-8c44-9d0e1f2a3b4c",
  "timestamp": "2026-07-16T09:20:11Z"
}
```

## Sample: Reserve Stock (POST /api/v1/inventory/reservations)

Request:
```json
{
  "product_id": 5310,
  "warehouse_id": 3,
  "quantity_requested": 25,
  "source_type": "sales_order",
  "source_id": 88231,
  "source_line_id": 3
}
```

Response (200) — partial fulfillment triggering a backorder:
```json
{
  "success": true,
  "data": {
    "id": 15092,
    "status": "backordered",
    "quantity_requested": "25.0000",
    "quantity_reserved": "18.0000",
    "needs_purchase_order": true
  },
  "message": "18 of 25 units reserved; 7 units backordered pending replenishment",
  "errors": [],
  "meta": {},
  "request_id": "d3b5e6f7-2233-4c55-9d66-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T09:25:47Z"
}
```

## Sample error: Adjustment exceeding threshold without approval permission

```json
{
  "success": false,
  "data": null,
  "message": "This adjustment requires a second approver",
  "errors": [
    {
      "code": "APPROVAL_REQUIRED",
      "field": null,
      "detail": "Adjustments above KWD 100.0000 require inventory.adjust.approve, held by a user other than the requester"
    }
  ],
  "meta": {},
  "request_id": "e4c6f708-3344-4d66-8e77-2b3c4d5e6f70",
  "timestamp": "2026-07-16T09:30:00Z"
}
```

## Sample error: Warehouse count lock

```json
{
  "success": false,
  "data": null,
  "message": "Warehouse is locked for physical inventory count",
  "errors": [
    {
      "code": "WAREHOUSE_COUNT_LOCK",
      "field": "warehouse_id",
      "detail": "Warehouse 3 has an active physical inventory (count_number PHY-2026-000012); outbound movements are blocked until the count is closed"
    }
  ],
  "meta": {},
  "request_id": "f5d7a819-4455-4e77-9f88-3c4d5e6f7081",
  "timestamp": "2026-07-16T09:31:18Z"
}
```

# Permissions

Permission keys follow the platform naming convention `<area>.<action>` and, where useful,
`<area>.<entity>.<action>`. Default deny: a role with no explicit grant sees nothing.

| Permission Key | Description |
|---|---|
| inventory.read | View inventory items, movements, valuations, reports |
| inventory.movements.create | Create a manual stock movement (non-standard, rarely granted) |
| inventory.adjust | Create/submit a stock adjustment |
| inventory.adjust.approve | Approve and post a stock adjustment above threshold |
| inventory.transfer.create | Create a draft stock transfer |
| inventory.transfer.approve | Approve a stock transfer |
| inventory.transfer.dispatch | Dispatch an approved transfer from source |
| inventory.transfer.receive | Receive a transfer at destination |
| inventory.reserve | Create a stock reservation |
| inventory.release | Release a stock reservation |
| inventory.count.create | Create a cycle or physical count |
| inventory.count.submit | Enter counted quantities |
| inventory.count.close | Close a count and generate variance adjustments |
| inventory.import | Bulk import inventory data |
| inventory.export | Bulk export inventory data |
| inventory.valuation.view | View unit cost / valuation layer detail (sensitive — often restricted from Sales roles who should see quantity but not cost) |
| inventory.settings.manage | Configure valuation methods, reorder policies, expiry windows |
| inventory.serial.manage | Create/edit serial records, execute recalls |
| inventory.batch.manage | Create/edit batch records, release quality holds |

## Role Grant Table

| Role | Read | Adjust | Adjust Approve | Transfer | Transfer Approve | Reserve/Release | Count | Import/Export | Valuation View | Settings |
|---|---|---|---|---|---|---|---|---|---|---|
| Owner | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| CEO | Yes | No | Yes | No | Yes | No | No | Yes | Yes | No |
| CFO | Yes | No | Yes | No | No | No | No | Yes | Yes | Yes |
| Finance Manager | Yes | No | Yes | No | No | No | Yes | Yes | Yes | Yes |
| Senior Accountant | Yes | No | No | No | No | No | No | Yes | Yes | No |
| Accountant | Yes | No | No | No | No | No | No | No | Yes | No |
| Auditor | Yes | No | No | No | No | No | No | Yes | Yes | No |
| Inventory Manager | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Warehouse Employee | Yes | Yes | No | Yes | No | Yes | Yes | No | No | No |
| Sales Manager | Yes | No | No | No | No | Yes | No | No | No | No |
| Sales Employee | Yes | No | No | No | No | Yes | No | No | No | No |
| Purchasing Manager | Yes | No | No | Yes | No | No | No | No | Yes | No |
| Purchasing Employee | Yes | No | No | Yes | No | No | No | No | No | No |
| Read Only | Yes | No | No | No | No | No | No | No | No | No |
| External Auditor | Yes | No | No | No | No | No | No | Yes | Yes | No |
| AI Agent (Inventory Manager) | Yes | Draft only | No | Draft only | No | No | No | No | Yes | No |

The distinction between "Adjust" and "Adjust Approve" is deliberate: a Warehouse Employee can raise an
adjustment for a damaged carton they physically found, but posting it — the point at which it affects the
general ledger — requires a role with `inventory.adjust.approve`, held by someone other than the
requester, matching the platform's sensitive-operation dual-control rule. The AI Inventory Manager agent
is granted `inventory.adjust`/`inventory.transfer.create` at draft level only — it can never hold
`.approve`, `.dispatch`, or `.close` permissions, consistent with the platform rule that sensitive
financial-impacting actions always require a human in the loop.

# Notifications

Inventory emits notifications (via the foundation `notifications` table and Reverb realtime channels) on
the following triggers, each configurable per role/user for delivery channel (in-app, email, SMS via the
platform's notification preferences):

| Event | Recipients | Channel Default | Content |
|---|---|---|---|
| `inventory.reorder_point_reached` | Inventory Manager, Purchasing Manager | In-app + email | Product, warehouse, current qty, reorder point, suggested PO quantity |
| `inventory.backorder_available` | Sales role who created the order, customer (if opted in) | In-app + email | Product, quantity now available, order reference |
| `inventory.batch_expired` | Inventory Manager, Warehouse Manager | In-app + email | Batch number, product, expiration date, quantity affected |
| `inventory.batch_expiring_soon` | Inventory Manager | In-app | Batch number, days to expiry, recommended action |
| `inventory.count_variance_high` | Inventory Manager, Finance Manager | In-app + email | Count number, product, expected vs counted, variance value |
| `inventory.adjustment_pending_approval` | Users holding `inventory.adjust.approve` | In-app + email | Adjustment number, reason, value, requester |
| `inventory.transfer_pending_approval` | Warehouse Manager at destination | In-app | Transfer number, items, source/destination |
| `inventory.serial_recalled` | Inventory Manager, affected customers (via CRM), Compliance Agent | In-app + email + SMS (customer-facing) | Serial value, product, recall reason, next steps |
| `inventory.shrinkage_flagged` | Auditor, Inventory Manager | In-app + email | Warehouse/employee pattern, AI confidence, supporting evidence links |
| `inventory.warehouse_count_lock_engaged` | All users with pending transactions in that warehouse | In-app | Warehouse name, expected unlock time |
| `inventory.stock_out` | Sales Manager, Inventory Manager | In-app + email | Product, warehouse, last-sale date, open backorders |

# Security

- **Tenant isolation is structural, not just a WHERE clause.** Every repository method in the Laravel
  Service/Repository layer accepts the active `company_id` from the authenticated request context and
  injects it into every query at the query-builder level (a global scope on all Inventory Eloquent
  models), so a missing filter cannot silently leak cross-company data even if a developer forgets to
  add it explicitly to a new query.
- **Valuation data is a distinct permission surface from quantity data.** `inventory.read` shows
  quantities and locations to any role that needs operational visibility (Sales, Warehouse staff);
  `inventory.valuation.view` gates unit cost, layer detail, and margin-revealing figures, because unit
  cost is commercially sensitive (a salesperson knowing the exact landed cost of a product materially
  changes their negotiating behavior and is a data-minimization concern the platform's RBAC model exists
  to enforce).
- **Serial/batch PII adjacency.** `product_serials.customer_id` links a serial directly to a customer
  record; export and API responses containing serial data are filtered through the same data-access rules
  as `customers` — a Warehouse Employee role can see "this serial was sold" but not the customer's name
  or contact details unless they also hold a Sales/CRM-scoped permission, preventing the serial table from
  becoming a side channel to customer PII.
- **Immutable movement ledger prevents retroactive fraud.** Because `stock_movements` rows are never
  updated or deleted, an actor who wanted to hide theft by editing history cannot do so through the
  application; the only way to change a posted quantity is a new, separately-authored, separately-audited
  reversing/adjusting row, which itself requires the adjustment approval chain.
- **RFID/barcode ingestion endpoints are device-authenticated**, not just user-authenticated: warehouse
  scanning hardware uses a scoped API token (`token_type = 'device'`) with permissions limited to
  `inventory.movements.create` and `inventory.count.submit` only, so a compromised handheld device cannot
  be used to approve adjustments, dispatch transfers, or read valuation data.
- **Rate limiting and anomaly circuit-breakers** apply to the bulk-import and RFID-event endpoints
  specifically, since these accept high-volume automated input: `inventory.import` and
  `inventory.rfid-events` are rate-limited more permissively than interactive endpoints (per the
  platform's 429 convention) but are subject to the AI Anomaly Detection agent's real-time review, which
  can flag (never silently block) a suspiciously large batch for human confirmation before its movements
  are treated as final for reporting purposes.
- **Encryption at rest and in transit** follows the platform standard: PostgreSQL data at rest is
  encrypted at the storage layer; all API traffic is HTTPS-only; attachments (disposal certificates,
  quality-inspection photos) are stored in Cloudflare R2 with signed, time-limited access URLs, never
  public buckets.

# Audit Logs

Every mutation on an Inventory-owned table writes one row to the foundation `audit_logs` table with:
`company_id`, `user_id` (or `service_account_id` for AI/device actors), `action` (`create | update |
approve | reverse | delete`), `entity_type`, `entity_id`, `old_value` (JSONB), `new_value` (JSONB),
`reason` (free text, mandatory for adjustments/reversals), `ip_address`, `device_id`/`user_agent`, and
`created_at`. Specific Inventory audit requirements beyond the platform baseline:

- **Adjustment audit trail is two-sided.** Both the *request* (who flagged the discrepancy, with what
  reason) and the *approval* (who signed off, when, and — if the approver changed the requested quantity
  before approving — what changed) are separately logged rows, never merged into one entry, so a dispute
  over "who approved this write-off" always resolves to an unambiguous, timestamped record.
- **Valuation method changes are audited at the product level** with before/after `valuation_method`
  and a mandatory `reason` field, because changing a product's valuation method mid-stream has real
  financial-statement consequences and must be traceable to a specific business decision (e.g., "switched
  from Weighted Average to FIFO ahead of IFRS-aligned year-end reporting").
- **Serial status transitions to `recalled` or `disposed`** are logged with the triggering business
  event (recall notice reference, disposal certificate attachment ID) in addition to the standard
  before/after — these are the two transitions most likely to be scrutinized by an external auditor or a
  regulator.
- **AI-authored drafts are logged distinctly from human actions** — every `stock_adjustments`,
  `stock_transfers`, or `purchase_requests` row created by an AI agent carries `created_by =
  <service_account_id for that agent>` rather than a human `user_id`, and the audit log entry includes the
  agent's `confidence_score` and `reasoning` at creation time, preserved even if the AI's model or
  reasoning logic later changes — a permanent record of *why* the system suggested what it suggested.
- **Read access to valuation data is also logged** (not just mutations) for companies with
  `companies.audit_valuation_reads = true` (typically enabled for pharma/regulated clients), because who
  looked at unit cost can itself be a compliance-relevant fact in industries with strict cost-disclosure
  controls.

# Performance

- **`inventory_items` is a maintained projection, never recomputed on read.** Every `stock_movements`
  insert updates the corresponding `inventory_items` row's buckets inside the same database transaction
  (via a Postgres trigger or, equivalently, an application-level unit-of-work in the Service layer),
  so a dashboard query for "current stock" never has to aggregate the full movement history — it is a
  single indexed row lookup. The nightly reconciliation job described in Stock Tracking exists precisely
  to catch the rare case (bug, manual DB intervention, replayed event) where the projection could drift
  from the ledger, and it is a safety net, not the primary read path.
- **Partitioning `stock_movements` by month** (`PARTITION BY RANGE (created_at)`) is the recommended
  physical layout once a company's monthly movement volume exceeds roughly 500,000 rows — Postgres
  partition pruning keeps date-range queries (the overwhelming majority of Inventory Operations and
  Reports queries) fast without a full-table scan, and old partitions can be moved to cheaper storage
  tiers (or, for companies past their statutory retention requirement, archived to Cloudflare R2 as
  Parquet) without touching current-period query performance.
- **Composite indexes are built around the two dominant access patterns**: "all movements for this
  product in this warehouse over a date range" (`idx_stock_movements_company_product_wh`) and "all
  movements from a specific source document" (`idx_stock_movements_source`, critical for the invoice/
  delivery detail screens that need to show exactly which movements a document produced).
- **The reorder-point index is a partial index** (`WHERE quantity_on_hand <= reorder_point`) rather than
  a full index on the comparison — Postgres cannot index a cross-column comparison directly, so the
  reorder-scan job instead maintains a boolean `needs_reorder` column updated by the same trigger that
  updates `quantity_on_hand`, and the partial index is built on `WHERE needs_reorder = true`, keeping the
  "low stock" dashboard query O(rows-below-threshold) rather than O(all rows).
- **FEFO/FIFO layer consumption is index-driven**, never a full scan of `inventory_valuations`: the
  partial index on open layers (`WHERE is_open = true`) ordered by `opened_at` (FIFO) lets the consumption
  function fetch exactly the layers it needs, in order, with a `LIMIT`-bounded query even for products
  with thousands of historical layers.
- **Cache layer (Redis)** holds the hot read path for `available_to_sell` per popular SKU/warehouse
  combination (e-commerce storefront and POS lookups), invalidated on every `stock_movements` insert for
  that key via a cache-tag scheme, avoiding a database round-trip for the highest-frequency query in the
  entire module while never risking staleness beyond the sub-second window between a movement commit and
  its cache invalidation.
- **Bulk operations (import, RFID batch ingestion, bulk cycle-count submission) are processed via Laravel
  queued jobs**, not synchronously in the HTTP request, both to avoid request timeouts on large batches
  and to allow the AI Anomaly Detection review step to run without blocking the initiating user's request
  — the API returns `202 Accepted` with a job reference, and the client polls or receives a Reverb
  websocket completion event.
- **Reporting queries never hit the live `stock_movements` table for closed periods** — they read from
  `inventory_snapshots` for any period prior to the current open fiscal period, which is orders of
  magnitude smaller and pre-aggregated, only falling back to live movement summation for the current,
  still-open period.

# Edge Cases

- **Negative available-to-sell from an overselling race.** Two simultaneous sales orders both pass an
  availability check on 5 remaining units, both attempt to reserve 3 each. QAYD prevents this with a
  `SELECT ... FOR UPDATE` row lock on the `inventory_items` row during reservation creation (never an
  optimistic "check then write" without a lock), so the second reservation request sees the
  already-decremented availability and is forced into `backordered` status rather than succeeding and
  driving `quantity_reserved` above `quantity_on_hand` — the CHECK constraint
  `chk_inventory_items_reserved_le_onhand` is the last-resort guarantee even if application logic has a
  bug.
- **A serialized product somehow receives a duplicate serial value from two different suppliers.** The
  `uq_product_serials_value` unique constraint on `(company_id, product_id, serial_value)` makes the
  second receipt fail at the database level; the receiving UI surfaces this as a validation error requiring
  the clerk to either correct a data-entry typo or append a supplier-disambiguating suffix
  (`serial_value = 'ABC123-VENDOR2'`) with a note explaining the collision — the Fraud Detection agent is
  also notified regardless of resolution, since duplicate serials from *different* suppliers for what
  should be a globally-unique manufacturer serial is itself worth a human look.
- **A batch expires while units are already reserved against a confirmed sales order.** The nightly
  expiry scan flips the batch to `expired` and removes it from `available_to_sell`, but existing
  `stock_reservations` against that batch are **not** automatically cancelled — cancelling a confirmed
  customer commitment automatically is a business decision, not a system one. Instead, an
  `inventory.reserved_stock_expired` notification alerts the Sales/Inventory Manager roles to decide:
  substitute a fresher batch (common), request a customer waiver (regulated-goods dependent), or cancel
  the order line.
- **A transfer is dispatched but the destination warehouse cannot receive the full quantity** (e.g., a
  pallet was damaged in transit and only 38 of 40 units arrive intact). Receipt supports partial-quantity
  confirmation per `stock_transfer_items` line; the shortfall automatically generates a linked
  `stock_adjustments` row (`reason_code = 'damage'`, pre-filled from the transfer context) rather than
  silently disappearing units from the ledger — every unit that left the source warehouse is accounted for
  as either arrived or adjusted, never simply "lost" between the two movement rows.
- **A company switches a product's valuation method mid-year** (e.g., Weighted Average to FIFO). QAYD
  does not retroactively rewrite `inventory_valuations` history under the new method — it closes all
  currently-open layers under the old method's bookkeeping as of the effective date and opens the first
  new-method layer at the then-current average cost as its starting basis, exactly matching the SAP/Oracle
  approach of prospective-only valuation-method changes, with the change itself logged as described in
  Audit Logs.
- **Returned goods can no longer be matched to their original cost layer** because that layer has long
  since closed (fully consumed, e.g., a return processed a year after the original sale, well past the
  batch/layer's lifetime). QAYD falls back to the *current* valuation method's normal receiving logic for
  that product (opens a new FIFO layer at the original sale's recorded unit cost if still determinable
  from `stock_movements.unit_cost_base`, or at the current weighted-average cost if the original layer
  reference is unrecoverable) rather than blocking the return — a documented, deterministic fallback, never
  a silent zero-cost receipt that would understate returned inventory value.
- **Consignment stock is sold before the vendor's consignment agreement is confirmed active**, or expires
  mid-sale. The `consignment_usage` movement type requires a valid, active `procurement_contracts`
  reference at the moment of sale; if the contract has lapsed, the sale is blocked at the Inventory layer
  with a `409 CONSIGNMENT_CONTRACT_EXPIRED` error, forcing Purchasing to renew or convert the stock to an
  owned purchase before the sale can proceed — protecting the company from selling goods it does not yet
  legally own the cost basis for.
- **A physical inventory count is left open indefinitely**, blocking the warehouse. QAYD auto-escalates
  via notification to the Inventory Manager after `warehouses.count_max_duration_hours` (default 48) of
  `in_progress` status, and after a further configurable grace period, permits a Finance Manager (not the
  original counter) to force-close the count using system-expected quantities for any uncounted lines —
  logged distinctly as a "forced close" so the resulting adjustment's audit trail honestly reflects that
  it was not a completed count.
- **Multi-currency receipt where the exchange rate is later corrected** (e.g., a bookkeeping correction to
  a USD/KWD rate used on an already-posted receipt). Because `unit_cost_base` is stored, not recomputed,
  correcting the rate does not retroactively change any already-posted `inventory_valuations` layer or
  `stock_movements` row — the correction is itself a new adjusting journal entry in Accounting
  (`inventory.valuation_correction` event) referencing the original movement, preserving the immutability
  guarantee while still allowing the financial correction to flow through in the current period.
- **A product is deleted (soft-deleted) from the Products module while it still has on-hand
  inventory.** Inventory blocks the Products module's delete operation at the domain-event level
  (`product.delete_requested` triggers a synchronous check against `inventory_items.quantity_on_hand > 0`
  across all warehouses) and returns a validation error until the product is fully zeroed out or
  explicitly disposed — a product with stock on the books can never simply vanish from the item master.

# Future Improvements

- **Native Manufacturing/BOM integration.** Today, `movement_type = 'production_issue'` and
  `'production_receipt'` are defined in the enum and referenced conceptually throughout this document, but
  the Bill-of-Materials explosion, work-order costing, and byproduct/scrap handling that would drive them
  belong to a future Manufacturing module; Inventory's schema is deliberately future-proofed for that
  integration (the enum values and `parent_batch_ids` genealogy field already exist) without implementing
  the production logic itself.
- **Predictive slotting with reinforcement learning.** The current Warehouse Optimization agent
  recommends re-slotting from static pick-frequency statistics; a future iteration could model seasonal
  demand shifts and multi-SKU co-location (items frequently ordered together placed near each other) using
  a learned policy rather than a fixed heuristic.
- **Vendor-managed inventory (VMI) portal.** Extending the consignment model to let vendors directly view
  (read-only, permission-scoped) their consigned stock levels and receive automated replenishment triggers
  without a Purchasing employee manually raising a purchase request — a natural extension of the existing
  `procurement_contracts` and consignment-warehouse mechanisms.
- **IoT sensor integration beyond RFID** — temperature/humidity logging for cold-chain batches, feeding
  directly into `product_batches` as a time-series attachment, with automatic quarantine triggering if a
  batch's logged temperature excursion exceeds the product's defined tolerance, extending the existing
  quality-hold mechanism from manual QC results to continuous automated sensor-driven holds.
- **Blockchain-anchored serial provenance** for high-value or luxury serialized goods, where the
  `product_serials` history could be optionally hashed and anchored to a public ledger for tamper-evident
  proof of authenticity chain-of-custody, layered on top of (not replacing) the existing PostgreSQL audit
  trail.
- **Cross-company benchmarking (opt-in, anonymized).** Aggregate, de-identified turnover-ratio and
  ABC-distribution benchmarks across the QAYD customer base, letting a company see "how does my inventory
  turnover in this category compare to similar Gulf retailers" — requires a separate anonymization and
  consent layer entirely outside any single company's `company_id` boundary, explicitly deferred until
  that consent infrastructure exists.
- **Automated markdown/promotion engine for Dead Stock.** Currently the Dead Stock Detection agent only
  recommends action; a future capability would let it, within a pre-approved discount-percentage policy,
  automatically create discounted `price_rules` for identified dead stock, still requiring the initial
  policy (the discount ceiling and eligible categories) to be human-approved once, rather than per-SKU.

# End of Document
