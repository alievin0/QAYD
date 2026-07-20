# Inventory Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: INVENTORY_SERVICE
---

# Purpose

This document specifies the backend **Inventory Service** — the module that owns products/items,
warehouses, stock levels and the immutable movement ledger, valuation (FIFO, weighted-average, and the
other supported methods), and the recognition of Cost of Goods Sold. It is the concrete instantiation of
the shared service-layer contract in [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) for the
inventory concern, and it is the backend counterpart of the accounting-facing designs in
[../accounting/INVENTORY.md](../accounting/INVENTORY.md),
[../accounting/WAREHOUSES.md](../accounting/WAREHOUSES.md), and
[../accounting/PRODUCTS.md](../accounting/PRODUCTS.md).

The Inventory Service is a **source module** in the double-entry sense: physical events it records —
receipts, issues, transfers, adjustments, count variances, disposals — have monetary consequences that
must land in the General Ledger. But Inventory does not post journal entries itself. Following the
platform's strict module-independence rule, Inventory *records the physical fact* (a `stock_movements`
row and the valuation consumed) and *announces it as a domain event*; [ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md)
owns the ledger and reacts by posting the balanced Inventory/COGS/GRNI journal entry. This document
specifies the physical and valuation side in full and references the posting engine rather than
duplicating it.

The invariant that governs everything here is that **the quantity ledger and the valuation ledger are
append-only and always reconcilable to the GL.** On-hand quantity is a materialized projection of the
movement ledger; inventory value on the Balance Sheet equals the sum of open valuation layers; COGS on
the Income Statement equals the cost consumed by outbound movements — and the journal entries Accounting
posts from Inventory's events keep the three in lockstep.

# Responsibilities

The Inventory Service owns the following:

- **Products/items as stock.** The stock-tracking projection of a product in a location: on-hand,
  reserved, on-order, in-transit, damaged, and quarantined buckets per `(product, warehouse, bin)`.
  Product master data (SKU, category, GL-account mapping, `valuation_method`) is defined in
  [../accounting/PRODUCTS.md](../accounting/PRODUCTS.md); Inventory consumes it and owns the quantities.
- **Warehouses and the location hierarchy.** Warehouse -> zone -> area -> aisle -> row -> shelf -> bin,
  per [../accounting/WAREHOUSES.md](../accounting/WAREHOUSES.md); Inventory reads this hierarchy for
  putaway, picking, and bin-level stock.
- **Stock levels and the movement ledger.** The append-only `stock_movements` ledger and the materialized
  `inventory_items` current-state projection, covering receipts, issues (sales), transfers
  (out/in), adjustments, counts (variance), disposals, and production issue/receipt.
- **Valuation.** Per-product method selection (`fifo | lifo | weighted_average | specific_identification |
  standard_cost | moving_average`) via `products.valuation_method`, the `inventory_valuations` layer model,
  and the cost consumed by every outbound movement.
- **COGS and inventory journal effects.** Computing the cost of every outbound movement and the value of
  every inbound one, then emitting the events from which Accounting posts Dr COGS / Cr Inventory (on
  issue) and Dr Inventory / Cr Goods Received Not Invoiced (on receipt).
- **Reservations.** Committing available stock against a demand document before physical movement.
- **Stock reconciliation and counts.** Cycle counts and full physical counts, variance calculation, and
  the count-close approval that generates variance adjustments.
- **Low-stock and lifecycle events.** Reorder-point, backorder-availability, batch-expiry, count-variance,
  and stock-out events.

Explicitly **not** its responsibility: the posting engine and the ledger (Accounting), product master
CRUD and account mapping beyond quantities (Products), purchase-order lifecycle (Purchasing), or the
demand documents it reserves against (Sales).

# Domain Model

- **InventoryItem** — the materialized current-state projection, one row per
  `(company_id, product_id, warehouse_id, bin_id)`. Holds `quantity_on_hand`, `quantity_reserved`,
  `quantity_on_order`, `quantity_in_transit`, `quantity_damaged`, `quantity_quarantined`, the reorder
  configuration, and `average_cost_base`. It is a derived read model — the movement ledger is the source of
  truth — protected by check constraints (`quantity_on_hand >= 0`, `quantity_reserved <= quantity_on_hand`).
- **StockMovement** — the append-only, immutable quantity ledger. Every physical change in the company is
  one row: a `movement_type` (`purchase_receipt`, `sale`, `transfer_out`/`transfer_in`, `adjustment`,
  `disposal`, `count_variance`, `production_issue`/`production_receipt`, ...), a `direction` (`in`/`out`),
  a positive `quantity`, the consumed `unit_cost_base`, a `source_type`/`source_id` link to the originating
  document, and, for corrections, a `reversed_movement_id`. Never updated or deleted; a correction is a new
  reversing row.
- **ValuationLayer** (`inventory_valuations`) — one row per receipt "layer" (FIFO/LIFO/Specific ID), or one
  in-place-updated blended row per product/warehouse (Weighted/Moving Average), plus a
  `standard_cost` treatment. `quantity_remaining` decrements as outbound movements consume the layer;
  a fully consumed layer is closed (`is_open = false`) but never deleted. Weighted/Moving Average's every
  recomputation is preserved in the append-only `inventory_valuations_history`.
- **StockReservation** — commits available stock against a demand document, moving through
  `active -> backordered -> fulfilled | released | expired`, with a `fulfillment_status` sub-state machine
  (`pending -> picking -> picked -> packed -> shipped`) so warehouse UI shows progress without inflating
  the movement ledger.
- **StockTransfer** — a two-legged movement between warehouses: `transfer_out` at dispatch (source),
  `transfer_in` at receipt (destination), with `quantity_in_transit` tracked between the two.
- **StockAdjustment** — a manual or count-driven quantity correction with a `reason_code`, approval-gated
  above a value threshold.
- **StockCount** — a cycle or physical count with counted lines and computed variances, whose close
  generates variance adjustments.
- **ProductSerial** / **ProductBatch** — per-unit and per-lot tracking, the former carrying
  `purchase_cost` for Specific Identification, the latter carrying expiry for FEFO and expiry events.

The core invariants: **stock never goes negative** (enforced by check constraint and the
`InsufficientStockException` guard), **a layer is never deleted** (append-only valuation), and **the
movement ledger is immutable** (corrections are reversing rows, mirroring the ledger's append-only rule).

## The stock lifecycle in movement terms

Every physical state a unit passes through is expressible as movement rows and projection-bucket shifts,
which is what keeps the module auditable end-to-end:

- **Purchase -> Receive** — a `purchase_receipt` inbound movement opens a valuation layer at the receipt
  unit cost and increments `quantity_on_hand`; over-receipt beyond `warehouses.receipt_tolerance_pct`
  (default 5%) requires Purchasing-Manager approval before the receipt posts.
- **Store -> Reserve** — putaway assigns a bin; a reservation moves quantity from available into
  `quantity_reserved` without any movement row (a commitment, not a physical change).
- **Transfer** — `transfer_out` at dispatch decrements the source and increments `quantity_in_transit`;
  `transfer_in` at receipt decrements in-transit and increments the destination on-hand.
- **Sell -> Ship** — the Sales `delivery.shipped` event produces the `sale` outbound movement, consumes
  valuation (the cost that becomes COGS), and releases the reservation. Partial shipments are separate
  movement batches, each tied to its own `delivery_id`.
- **Return / Adjust / Dispose / Count** — `sales_return`/`purchase_return`, `adjustment`, `disposal`, and
  `count_variance` movements each carry a reason and, where value-material, an approval gate.

Because on-hand is a `SUM()` projection of these rows, rebuilding `inventory_items` from `stock_movements`
must always reproduce the live buckets — a reconciliation the maintenance job asserts nightly.

# Key Classes

- `App\Actions\Inventory\ReceiveStockAction` — record an inbound receipt (from a Goods Receipt or a
  standalone receipt), open a valuation layer, update the projection, emit `StockReceived`.
- `App\Actions\Inventory\IssueStockAction` — record an outbound issue (sale/consumption), consume valuation
  per the product's method, compute COGS, emit `StockIssued`.
- `App\Actions\Inventory\CreateAdjustmentAction` / `ApproveAdjustmentAction` — draft and (post-approval)
  post a stock adjustment.
- `App\Actions\Inventory\DispatchTransferAction` / `ReceiveTransferAction` — the two legs of a transfer.
- `App\Actions\Inventory\ReserveStockAction` / `ReleaseReservationAction` — reservation lifecycle.
- `App\Actions\Inventory\CloseCountAction` — close a count and generate variance adjustments
  (permission `inventory.count.approve`/`inventory.count.close`).
- `App\Services\Inventory\ValuationService` — the valuation engine: opens layers on receipt, consumes cost
  on issue per method, and produces the `CostConsumed` value object that becomes the COGS journal amount.
- `App\Services\Inventory\StockLevelService` — applies a movement to the `inventory_items` projection under
  a row lock, enforcing the non-negative and reserved-<=-on-hand invariants.
- `App\Repositories\Inventory\StockMovementRepository`, `ValuationLayerRepository`,
  `InventoryItemRepository`.
- `App\Data\Inventory\MovementData`, `AdjustmentData`, `TransferData`, `ReservationData` — the DTOs.

The valuation engine is a Domain Service because the double-entry consequence must not be duplicated across
Actions — every outbound Action asks `ValuationService` for the cost consumed, and every inbound Action
asks it to open a layer:

```php
namespace App\Services\Inventory;

use App\Domain\Inventory\CostConsumed;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Inventory\StockMovement;
use App\Repositories\Inventory\ValuationLayerRepository;
use App\Support\Enums\ValuationMethod;
use App\Support\Money;

final class ValuationService
{
    public function __construct(private readonly ValuationLayerRepository $layers) {}

    /**
     * Consume cost for an outbound movement per the product's valuation method.
     * Returns the base-currency cost that Accounting will post as Dr COGS / Cr Inventory.
     */
    public function consume(StockMovement $movement): CostConsumed
    {
        $method = $movement->product->valuation_method;

        return match ($method) {
            ValuationMethod::Fifo, ValuationMethod::Lifo =>
                $this->consumeLayers($movement, newestFirst: $method === ValuationMethod::Lifo),
            ValuationMethod::WeightedAverage, ValuationMethod::MovingAverage =>
                $this->consumeBlended($movement),
            ValuationMethod::SpecificIdentification =>
                $this->consumeSerial($movement),          // from product_serials.purchase_cost
            ValuationMethod::StandardCost =>
                $this->consumeStandard($movement),        // products.standard_cost, variance isolated
        };
    }

    /** FIFO/LIFO layer consumption: oldest (or newest) open layer first, never below zero. */
    private function consumeLayers(StockMovement $movement, bool $newestFirst): CostConsumed
    {
        $remaining = $movement->quantity;
        $cost      = Money::zero($movement->currency_code);
        $open      = $this->layers->openFor($movement->product_id, $movement->warehouse_id, $newestFirst);

        foreach ($open as $layer) {
            if ($remaining->isZero()) {
                break;
            }
            $take = $remaining->min($layer->quantity_remaining);
            $cost = $cost->plus($take->times($layer->unit_cost_base));
            $layer->decrement('quantity_remaining', $take->value());   // append-only: close at zero, never delete
            $remaining = $remaining->minus($take);
        }

        if (! $remaining->isZero()) {
            throw new InsufficientStockException($movement->product_id, $movement->warehouse_id, $remaining);
        }

        return new CostConsumed($cost);
    }
}
```

For a single receipt/issue sequence the method choice changes both COGS and ending value from identical
physical events, which is why the method is a disclosed accounting policy, not an operational detail
(worked in full in [../accounting/INVENTORY.md](../accounting/INVENTORY.md)):

| Sequence (SKU WGT-100) | FIFO COGS | Weighted-Avg COGS | Ending value |
|---|---|---|---|
| Recv 100 @ 2.000, recv 150 @ 2.200, sell 120, sell 50 | 244.000 + 110.000 | 254.400 + 106.000 | FIFO 176.000 vs WA 169.600 |

`ValuationService` returns the `CostConsumed` value object for the outbound leg; Accounting posts exactly
that figure as `Dr COGS / Cr Inventory`, so the Income Statement's COGS and the movement ledger can never
disagree. The issue Action ties valuation, the projection update, and the event together inside one
transaction:

```php
namespace App\Actions\Inventory;

use App\Data\Inventory\MovementData;
use App\Events\Inventory\StockIssued;
use App\Models\Inventory\StockMovement;
use App\Models\User;
use App\Repositories\Inventory\StockMovementRepository;
use App\Services\Inventory\StockLevelService;
use App\Services\Inventory\ValuationService;
use Illuminate\Support\Facades\DB;

final class IssueStockAction
{
    public function __construct(
        private readonly StockMovementRepository $movements,
        private readonly ValuationService $valuation,
        private readonly StockLevelService $levels,
    ) {}

    public function execute(MovementData $data, User $actor): StockMovement
    {
        return DB::transaction(function () use ($data, $actor): StockMovement {
            $movement = $this->movements->append([
                'product_id'   => $data->productId,
                'warehouse_id' => $data->warehouseId,
                'movement_type'=> $data->type,          // 'sale' | 'production_issue' | ...
                'direction'    => 'out',
                'quantity'     => $data->quantity,
                'source_type'  => $data->sourceType,
                'source_id'    => $data->sourceId,
                // company_id, created_by auto-filled by BelongsToCompany
            ]);

            $cost = $this->valuation->consume($movement);        // FIFO/WA/... cost, or throws insufficient_stock
            $movement->setConsumedCost($cost);
            $this->levels->applyOutbound($movement);             // row-locked projection update, non-negative guard

            // Accounting owns the ledger; Inventory only announces the fact + the cost consumed.
            event(new StockIssued($movement, costBase: $cost->value(), actorId: $actor->id));

            return $movement->refresh();
        });
    }
}
```

# Endpoints Backed

All endpoints are versioned under `/api/v1/inventory/`, require a Sanctum/JWT bearer token, are scoped to
the active company via the `X-Company-Id` header, and return the standard platform envelope per
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md). Most movements are system-generated from domain
events (a Sales `delivery.shipped` produces the outbound movement); the direct movement endpoint is rarely
used.

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | /api/v1/inventory/items | inventory.read | List `inventory_items` with filters (product, warehouse, bin, below-reorder) |
| GET | /api/v1/inventory/items/{id} | inventory.read | Retrieve one inventory item with its current buckets |
| POST | /api/v1/inventory/movements | inventory.movements.create | Create a manual stock movement (rare; most are event-driven) |
| GET | /api/v1/inventory/movements | inventory.read | List movement history with filters |
| POST | /api/v1/inventory/adjustments | inventory.adjust | Create a stock adjustment (draft) |
| POST | /api/v1/inventory/adjustments/{id}/submit | inventory.adjust | Submit an adjustment for approval |
| POST | /api/v1/inventory/adjustments/{id}/approve | inventory.adjust.approve | Approve and post an adjustment |
| POST | /api/v1/inventory/transfers | inventory.transfer.create | Create a stock transfer (draft) |
| POST | /api/v1/inventory/transfers/{id}/dispatch | inventory.transfer.dispatch | Dispatch an approved transfer (posts `transfer_out`) |
| POST | /api/v1/inventory/transfers/{id}/receive | inventory.transfer.receive | Receive a transfer at destination (posts `transfer_in`) |
| POST | /api/v1/inventory/reservations | inventory.reserve | Reserve stock against a source document |
| POST | /api/v1/inventory/reservations/{id}/release | inventory.release | Release a reservation back to available |
| GET | /api/v1/inventory/search | inventory.read | Full-text/barcode/serial/batch search |
| POST | /api/v1/inventory/import | inventory.import | Bulk import opening balances / stock-take results |
| GET | /api/v1/inventory/export | inventory.export | Bulk export current stock or movement history |
| POST | /api/v1/inventory/bulk-adjust | inventory.adjust | Bulk-create adjustments from a count/RFID batch |
| POST | /api/v1/inventory/rfid-events | inventory.movements.create | Ingest a batch of RFID gate-reader events |
| GET | /api/v1/inventory/counts | inventory.count.read | List stock counts |
| POST | /api/v1/inventory/counts | inventory.count.create | Create a cycle or physical count |
| POST | /api/v1/inventory/counts/{id}/lines | inventory.count.submit | Submit counted quantities |
| POST | /api/v1/inventory/counts/{id}/close | inventory.count.close | Close a count, auto-generating variance adjustments |

```json
{
  "success": true,
  "data": {
    "id": 771,
    "adjustment_number": "ADJ-2026-000771",
    "status": "pending_approval",
    "total_value_base": "16.8000",
    "items": [
      { "id": 1502, "product_id": 4501, "quantity_before": "60.0000",
        "quantity_after": "52.0000", "quantity_delta": "-8.0000", "unit_cost_base": "2.1000" }
    ]
  },
  "message": "Adjustment created and routed for approval (exceeds KWD 100 threshold)",
  "errors": [],
  "meta": {},
  "request_id": "b7e1c9a2-3f4d-4a11-9c2e-5d8f0a1b2c3d",
  "timestamp": "2026-07-16T07:45:10Z"
}
```

# Database Tables Owned

The Inventory Service owns these tables (full DDL in
[../accounting/INVENTORY.md](../accounting/INVENTORY.md)). All carry the platform standard columns
(`id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`);
money is `NUMERIC(19,4)`, exchange rates `NUMERIC(18,6)`, quantities `NUMERIC(18,4)`; every table is
scoped by `company_id NOT NULL REFERENCES companies(id)`.

| Table | Role | Notes |
|---|---|---|
| `inventory_items` | Materialized current-state projection | one row per `(company, product, warehouse, bin)`; on-hand/reserved/on-order/in-transit/damaged/quarantined buckets; `chk_inventory_items_nonneg`, `chk_inventory_items_reserved_le_onhand` |
| `stock_movements` | Append-only immutable quantity ledger | `movement_type` enum, `direction`, `unit_cost_base`, generated `total_cost_base`, `source_type`/`source_id`, `reversed_movement_id`; no UPDATE/DELETE grant |
| `stock_reservations` | Demand commitments | `reservation_status`, `fulfillment_status` sub-state |
| `stock_adjustments` / `stock_adjustment_items` | Quantity corrections | `reason_code`, approval-gated by value threshold |
| `stock_transfers` / `stock_transfer_items` | Inter-warehouse moves | dispatch/receive legs, in-transit tracking |
| `stock_counts` / `stock_count_lines` | Cycle & physical counts | counted vs. system qty, variance |
| `inventory_valuations` | Valuation layers | per-receipt layer (FIFO/LIFO/Specific), or in-place blended row (Weighted/Moving Average); `quantity_remaining`, `is_open` |
| `inventory_valuations_history` | Append-only recomputation audit | every Weighted/Moving Average recalculation |
| `product_serials` | Per-unit tracking | `purchase_cost` for Specific Identification |
| `product_batches` | Per-lot tracking | expiry for FEFO and expiry events |
| `inventory_snapshots` | Point-in-time projection snapshots | historical on-hand for as-of reporting |

The `stock_movements` ledger is the source of truth; `inventory_items` is a projection rebuilt from it, and
production corrections always insert a new movement row with `reversed_movement_id` set rather than editing
history. Indexes lead with `company_id` (`idx_stock_movements_company_product_wh`,
`idx_stock_movements_source`, and the partial reorder index on `inventory_items`) so filters stay sargable.

# Multi-Tenancy Enforcement

Inventory is company-scoped per [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md).

- **Ambient scope.** Every table is `company_id NOT NULL`; `BelongsToCompany` fills it on write and
  `CompanyScope` scopes reads, so a movement, item, or valuation query can never cross a tenant boundary.
  A cross-tenant id hits `findOrFail` and 404s at route-model binding before any policy runs.
- **RLS backstop.** Every statement runs on a connection carrying `SET LOCAL app.current_company_id`, so
  the PostgreSQL RLS policies on `stock_movements`, `inventory_items`, and `inventory_valuations` filter
  even a query that forgot its `company_id` predicate — critical for the valuation engine's layer scans,
  which must never consume another tenant's cost layers.
- **Warehouse and bin scope.** The warehouse hierarchy is itself tenant-scoped; a movement's
  `warehouse_id`/`bin_id` are validated to belong to the active company, so stock cannot be received into
  or issued from another tenant's location. Branch-restricted users are further constrained by
  `app.current_user_branch_restricted` where configured.
- **Jobs re-bind tenant context.** Movement-driven work dispatched to the queue (e.g., bulk RFID ingest,
  valuation recomputation) runs through `TenantAwareJob`, re-issuing `SET LOCAL app.current_company_id`
  under PgBouncer transaction pooling before touching any row.
- **Cross-tenant reads** are only the `RequirePlatformAdmin` analytics path; no operational inventory query
  uses `withoutCompanyScope()`.

# Events, Queues & Realtime

Inventory is an **event producer** whose events drive Accounting's ledger posting, and a consumer of
Sales/Purchasing events that trigger physical movements.

**Events emitted** (past-tense facts, ids + minimal payload including the cost consumed):
`StockReceived`, `StockIssued`, `StockTransferDispatched`, `StockTransferReceived`, `StockAdjusted`,
`StockCountClosed`, `ReorderPointReached`, `BackorderAvailable`, `BatchExpiringSoon`, `BatchExpired`,
`CountVarianceHigh`, `StockOut`, `ShrinkageFlagged`. The money-affecting subset carries the base-currency
value/cost so Accounting can post without re-deriving it.

**Ledger posting is Accounting's job.** A queued, idempotent `CreateJournalForStockMovement` listener in
the Accounting module reacts to `StockReceived`/`StockIssued`/`StockAdjusted` and posts the balanced entry
through `PostJournalEntryAction`, using the movement key as the idempotency guard:

```php
namespace App\Listeners\Accounting;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Domain\Accounting\JournalDraft;
use App\Events\Inventory\StockIssued;
use Illuminate\Contracts\Queue\ShouldQueue;

final class PostCogsForStockIssue implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly PostJournalEntryAction $post) {}

    public function handle(StockIssued $event): void
    {
        // Dr Cost of Goods Sold / Cr Inventory, at the cost Inventory already consumed.
        $this->post->execute(
            JournalDraft::forStockIssue($event->movementId, $event->costBase),
            idempotencyKey: "stock_movement:{$event->movementId}:cogs",
        );
    }
}
```

The exact journal shapes (Dr Inventory / Cr GRNI on receipt; Dr COGS / Cr Inventory on issue; Purchase
Price Variance on standard-cost receipts) are worked in [../accounting/INVENTORY.md](../accounting/INVENTORY.md)
and posted by [ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md) — this service never posts them.

**Events consumed.** Sales' `delivery.shipped` triggers `IssueStockAction` and releases the reservation;
Purchasing's `goods_receipt.posted` triggers `ReceiveStockAction`. Because events dispatch only after the
originating transaction commits, a rolled-back sale never issues stock.

**Queues.** Heavy work — bulk imports, RFID batch ingest, full physical-count variance generation, and
valuation recomputation after a retroactive cost correction — runs on named queues (`default`,
`integrations`, `maintenance`); each job is tenant-aware and idempotent on the source key.

**Realtime.** `ReorderPointReached`, `StockOut`, and `BatchExpiringSoon` are `ShouldBroadcast` on the
company-scoped private channel (Reverb), so the inventory dashboard and the Inventory Manager's alert panel
update live. AI recommendations (reorder, dead-stock, rebalancing) arrive on the `.ai` sub-channel.

# Integrations

- **Accounting (ledger posting).** Inventory emits movement events; Accounting posts the Inventory / COGS /
  GRNI / Purchase Price Variance entries. Inventory supplies the cost consumed; it never touches
  `journal_entries`/`journal_lines`. See [ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md).
- **Products.** Product master data — SKU, category, `valuation_method`, `inventory_account_id`,
  `cogs_account_id`, `standard_cost`, tracking flags — is owned by
  [../accounting/PRODUCTS.md](../accounting/PRODUCTS.md); Inventory reads it and owns the quantities and
  layers. A non-stocked product type cannot carry inventory accounts.
- **Warehouses.** The location hierarchy and slotting (`warehouse_bins.pick_sequence`) are owned by
  [../accounting/WAREHOUSES.md](../accounting/WAREHOUSES.md); Inventory reads it for putaway/pick/bin stock.
- **Sales & Purchasing.** Sales reserves and issues stock (via events); Purchasing receives it. Inventory
  never writes their tables; it reacts to their events and announces its own.
- **AI engine (Inventory Manager, Forecast, Fraud, Auditor agents).** Per
  [../api/INTERNAL_API.md](../api/INTERNAL_API.md), the FastAPI engine reads movement/valuation/reservation
  data through the Laravel API and returns proposals — demand forecast, reorder drafts (as
  `purchase_requests`, never auto-`purchase_order`), dead-stock and shrinkage detection, ABC/XYZ
  classification, and cross-warehouse rebalancing (as `draft` transfers requiring approval). Every proposal
  carries `confidence_score`, `reasoning`, and `supporting_document_ids`, respects the acting user's RBAC,
  and never writes `inventory_items`/`stock_movements`/`inventory_valuations` directly. ABC/XYZ labels are
  the only auto-write, and only to a non-financial classification field.
- **Reporting.** Inventory Valuation, Aging, Turnover, Dead-Stock, and Reorder reports are assembled by
  [REPORTING_SERVICE.md](./REPORTING_SERVICE.md) reading `inventory_items`/`inventory_valuations` and the
  `mv_inventory_valuation_current` materialized view.

# Permissions

Authorization follows the RBAC grammar `<area>.<action>` / `<area>.<entity>.<action>` from
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), company-scoped, default-deny,
and enforced by policies invoked from FormRequests (and, for mid-operation checks like the adjustment
value threshold, from Actions).

| Permission | Grants |
|---|---|
| `inventory.read` | View items, movements, and search |
| `inventory.movements.create` | Create a manual movement / ingest RFID events |
| `inventory.adjust` | Create/submit stock adjustments |
| `inventory.adjust.approve` | Approve and post adjustments (maker cannot approve own) |
| `inventory.transfer.create` / `.approve` / `.dispatch` / `.receive` | The transfer lifecycle stages |
| `inventory.reserve` / `inventory.release` | Reservation lifecycle |
| `inventory.count.read` / `.create` / `.submit` / `.close` / `.approve` | Stock-count lifecycle |
| `inventory.import` / `inventory.export` | Bulk import/export |
| `inventory.valuation.view` | View valuation layers and unit costs |
| `inventory.settings.manage` | Manage reorder points, tolerances, valuation config |
| `inventory.serial.manage` / `inventory.batch.manage` | Manage serialized/batch tracking |

Two maker-checker rules matter: an adjustment above the configured value threshold requires
`inventory.adjust.approve` from a *different* user than the maker (a self-approval throws
`maker_checker_violation`); and closing a count that generates variance adjustments requires
`inventory.count.approve`/`inventory.count.close`, so a single warehouse clerk cannot both count and
absorb a shrinkage variance unreviewed. AI agents act only under the permissions the acting user holds —
an AI-drafted rebalancing transfer still requires a human with `inventory.transfer.approve` to dispatch it.

# Error Handling

The service throws typed domain exceptions; the global handler maps them per
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md). The exceptions carry structured context so the
handler can localise `errors[].message` without the service knowing about HTTP.

| Thrown | Meaning | Rendered |
|---|---|---|
| `InsufficientStockException` | an issue/transfer would drive on-hand negative | 422 `insufficient_stock` (product, warehouse, shortfall) |
| `ImmutableRecordException` | attempt to edit/delete a posted `stock_movements` row | 409 `immutable_record` (names the reversing action) |
| `InvalidStateTransitionException` | e.g. dispatch a transfer not yet approved, close an open count with unsubmitted lines | 409 `invalid_state_transition` |
| `MakerCheckerViolationException` | approver == maker on an adjustment/count above threshold | 403 `maker_checker_violation` |
| `WarehouseCountLockException` | a movement attempted against a warehouse locked for a physical count | 409 `warehouse_count_locked` |
| `AdjustmentThresholdException` | adjustment above threshold submitted without approval routing | 422 `approval_required` |
| `CurrencyMismatchException` | receipt cost currency conflicts with the base-currency layer without opt-in | 422 `currency_mismatch` |
| `ValuationLayerExhaustedException` | Specific-ID consume of a serial with no open cost | 422 `valuation_layer_exhausted` |

Two design points. First, **the movement ledger is never rolled back after the fact** — a posted movement
that later proves wrong is corrected by a reversing movement (`reversed_movement_id`), exactly as the GL
corrects a posted entry with a reversing entry; attempting a `PATCH`/`DELETE` returns `immutable_record`.
Second, the **warehouse count lock** is a hard stop: while a full physical count is open on a warehouse,
outbound movements against it are rejected with `warehouse_count_locked` so the counted quantity cannot
drift under the count — anomaly detection separately flags any large adjustment posted immediately before a
scheduled count.

# Testing

- **Unit-test `ValuationService` per method** with fixed receipt/issue sequences: FIFO consumes oldest
  layers first and closes them at zero; LIFO consumes newest; Weighted Average recomputes the blended cost
  after each receipt and updates the single row in place; Moving Average recomputes on every movement
  including returns; Specific Identification consumes exactly the sold serial's `purchase_cost`; Standard
  Cost posts at standard and isolates the Purchase Price Variance. Assert the worked examples in
  [../accounting/INVENTORY.md](../accounting/INVENTORY.md) reproduce to the fills-cent.
- **Unit-test the movement Actions** with repositories and the valuation service mocked: assert the
  transaction boundary, that the projection update and event fire inside it, and that
  `InsufficientStockException` is thrown before any partial write.
- **Concurrency tests** assert that two simultaneous issues of the last unit do not both succeed
  (row-locked projection update; one gets `insufficient_stock`).
- **Feature-test through `/api/v1/inventory`** to prove thin controller + FormRequest + policy + envelope,
  and to prove **tenant isolation** (company A cannot receive into company B's warehouse; a valuation scan
  never consumes company B's layers) and **default-deny** (missing `inventory.adjust` -> 403).
- **Maker-checker tests** assert an above-threshold adjustment cannot be self-approved and a count close
  requires the approve permission.
- **Event/posting tests** assert that `StockIssued` dispatches after commit, that the Accounting listener
  posts Dr COGS / Cr Inventory once (idempotent on the movement key), and that a rolled-back issue posts no
  COGS.
- **Immutability tests** assert a posted movement cannot be edited and that a correction is a reversing row.
- **Count-lock tests** assert an outbound movement against a warehouse under physical count is rejected.

# End of Document
