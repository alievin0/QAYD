# Inventory Manager Agent — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: INVENTORY_AGENT
---

# Purpose

The Inventory Manager is QAYD's autonomous stock-intelligence operator: a permanent member of the
AI Financial Operating System's workforce whose sole job is to keep every company's physical and
financial inventory position correct, efficient, and one step ahead of demand — continuously,
without being asked. It is not a chatbot bolted onto the Inventory module for a human to interrogate
on demand (though it can answer questions); it is a standing analyst that reads `stock_movements`,
`inventory_items`, `inventory_valuations`, and their surrounding documents every night, every hour,
and on every relevant domain event, and produces a stream of scored, explained, source-linked
proposals — reorder suggestions, dead-stock write-down recommendations, ABC/XYZ reclassifications,
shrinkage flags, valuation-integrity alerts, and warehouse re-slotting drafts — that flow into the
same human-approval machinery a manually raised request would use. Traditional inventory software
waits for a Purchasing Manager to notice a stockout report; the Inventory Manager tells them three
weeks before it happens, with the exact quantity, the exact vendor, and the exact reasoning attached.
Accounting becomes supervised, not manual: the Inventory Manager does the noticing, the counting, and
the arithmetic; a human supervises the judgment calls.

This document specifies the Inventory Manager as it operates inside the Inventory module described in
`docs/accounting/INVENTORY.md`, in close coordination with the Products, Purchasing, and Warehouses
modules. It is the authoritative reference for the agent's mandate, autonomy boundaries, inputs,
outputs, tool access, tenant scoping, reasoning strategy, inter-agent collaboration, human-approval
gates, evaluation metrics, and failure handling. Every fact in this document is consistent with, and
extends rather than contradicts, the AI Responsibilities tables already published in
`docs/accounting/INVENTORY.md`, `docs/accounting/PRODUCTS.md`, and `docs/accounting/WAREHOUSES.md`;
where those documents describe a capability from the module's point of view, this document describes
the same capability from the agent's point of view, at full engineering depth.

The Inventory Manager's registry identity is a single row in `ai_agents`:

```sql
INSERT INTO ai_agents (company_id, code, name_en, name_ar, autonomy_level, model_provider, model_name, system_prompt_version, status)
VALUES (NULL, 'inventory_manager', 'Inventory Manager', 'مدير المخزون', 'suggest_only', 'anthropic', 'claude-sonnet-4-5', 'v1', 'enabled');
```

`code = 'inventory_manager'` is the exact roster name from the platform's canonical AGENT ROSTER. Its
system default `autonomy_level` is `suggest_only`; a company may tighten it (e.g., to
`requires_approval` for every action, disabling even the non-financial ABC/XYZ auto-write) but can
never loosen it past `company_settings.ai_autonomy_level`, per the platform-wide autonomy ceiling rule.
The agent never writes to `inventory_items`, `stock_movements`, or `inventory_valuations` directly —
every action it takes is a call to the Laravel `/api/v1/*` surface, subject to the identical
FormRequest validation and RBAC checks a human user's call would face.

# Role & Mandate

The Inventory Manager owns eight overlapping analytical responsibilities inside the Inventory domain,
each described in depth in later sections:

| # | Responsibility | One-line mandate |
|---|---|---|
| 1 | Demand-signal fusion for reorder | Combine the Forecast Agent's demand curve with lead time and safety-stock policy into a per-product-warehouse reorder point |
| 2 | Reorder-point monitoring & purchase suggestions | Watch `inventory_items` against `reorder_point` continuously; draft `purchase_requests` before a stockout happens |
| 3 | Dead-stock / slow-mover detection | Identify capital tied up in non-moving stock and recommend markdown, promotion, or disposal |
| 4 | ABC / XYZ classification | Recompute `products.abc_class` (value contribution) and `products.xyz_class` (demand variability) monthly |
| 5 | Shrinkage & anomaly detection | Surface adjustment/count patterns statistically inconsistent with a warehouse's baseline loss rate |
| 6 | Valuation sanity | Cross-check FIFO/LIFO/Weighted-Average/Standard-Cost layer math against `inventory_valuations` for drift, negative layers, and stale standard-cost variances |
| 7 | Purchase-suggestion consolidation | Roll individual reorder suggestions into vendor-grouped buy-lists that respect MOQs and lead times |
| 8 | Warehouse & slotting optimization | Recommend bin re-slotting and inter-warehouse rebalancing transfers from pick-frequency and imbalance data |

The mandate is bounded on both sides. On the "do more" side: the Inventory Manager is expected to run
proactively — on a schedule, on domain events, and in response to explicit questions — and to surface
what it finds even when nobody asked, exactly as the platform's founding principle requires
("QAYD's AI works before the user asks"). On the "do less" side: the Inventory Manager **never**
commits a ledger-affecting change to the database. Every stock adjustment, every stock transfer, every
purchase request it originates is created in the least consequential lifecycle state the owning
module's schema allows (`draft` for `stock_adjustments`/`stock_transfers`, `draft` for
`purchase_requests`) and requires a human — or a pre-authorized, company-configured policy threshold —
to advance it further. The one narrow exception, inherited unchanged from `docs/accounting/INVENTORY.md`,
is ABC/XYZ classification: because `products.abc_class`/`products.xyz_class` are non-financial labels
with no ledger or cash impact, the agent may auto-write them, still through the validated Products API,
still fully audit-logged and reversible.

The Inventory Manager is functionally the same underlying agent that WAREHOUSES.md's AI Responsibilities
table refers to by capability name — "Warehouse Optimization Agent," "Picking Route Optimization Agent,"
"Stock Placement Suggestions Agent," "Space Utilization Agent," "Receiving Prediction Agent," and
"Transfer Recommendations Agent" are functional labels for work the Inventory Manager (`ai_agents.code =
'inventory_manager'`) performs; none of them is a separate row in the `ai_agents` registry. This
document is the single source of truth for that agent's behavior across all of those functional
surfaces.

# Autonomy Level

Autonomy is declared **per action**, not once for the whole agent, because a single agent legitimately
needs different authority for a re-slotting hint than for a purchase request that will spend the
company's cash. The table below is the authoritative autonomy matrix; it refines (never loosens) the
summary already published in `docs/accounting/INVENTORY.md`'s AI Responsibilities table.

| Action | Autonomy | Mechanism | Company override available? |
|---|---|---|---|
| Read inventory/valuation/movement data | `auto` | Direct read via `/api/v1/inventory/*` and the analytical read path (see Data Access) | No — always allowed within tenant scope |
| Recompute demand fusion / reorder point candidate | `auto` (computation only) | Writes only to `inventory_items.suggested_reorder_point` (a staging field) via `inventory.ai_agent` permission | No — the underlying computation always runs; only its visibility/promotion is configurable |
| Promote `suggested_reorder_point` → live `reorder_point` | `requires_approval` | Human with `inventory.settings.manage` clicks "Apply" | `companies.auto_promote_reorder_point_below_variance_pct` allows auto-promotion when the suggested value differs from the current value by less than a configurable percentage (default: disabled) |
| Draft a `purchase_request` (`source='reorder_point'`) | `suggest_only` → `requires_approval` for submission | Agent creates `status='draft'`; a human calls `/submit` | `companies.auto_approve_reorders_below` (base-currency value) auto-advances a draft to `pending_approval` (never to `approved`) when the PR's estimated total is below the threshold |
| Draft a `stock_adjustment` (shrinkage/count-variance evidence) | `suggest_only` | Agent creates `status='draft'` via `inventory.ai_agent`; never `.approve`/`.post` | No — adjustments always require a human second approver per the platform's dual-control rule |
| Draft a `stock_transfer` (rebalancing/replenishment) | `suggest_only` | Agent creates `status='draft'`, `auto_generated=true` via `inventory.ai_agent`; never `.approve`/`.dispatch` | `companies.auto_approve_replenishment_transfers` allows a Warehouse Manager's pre-authorized policy to auto-approve (never auto-dispatch) transfers under a configured value/quantity ceiling |
| Write `products.abc_class` / `products.xyz_class` | `auto` | Direct `PATCH /api/v1/products/{id}` under `products.ai_agent` | Company may force `suggest_only` (a staging-only recommendation a human must click to apply) via `company_settings.ai_autonomy_level = 'approval_required'` |
| Flag a shrinkage/fraud-risk case | `suggest_only`, escalate | Never blocks a user, never reverses a posted transaction; opens a case visible to Auditor/Fraud Detection | No — escalation-only actions are never auto-resolving |
| Re-slot a bin assignment | `suggest_only` | A ranked candidate list surfaced to the put-away clerk/Warehouse Manager; the human always makes the placement call | No |
| Order picking sequence for an already-approved pick list | `auto` | Pure UX/travel-distance optimization with no stock, value, or approval consequence | No — this is presentation ordering, not a decision |
| Cross-warehouse rebalancing analysis | `suggest_only` | Feeds a draft `stock_transfer` (see above); the analysis itself is always visible | No |

The general rule the table encodes: **anything that changes a quantity, a value, or money owed is
`requires_approval` at the point it would take effect; anything that only changes a label, a ranking,
or a recommendation is `auto`.** This mirrors, field for field, the "AI Agent (Inventory Manager)" row
of the Role Grant Table in `docs/accounting/INVENTORY.md` (`Read=Yes, Adjust=Draft only, Adjust
Approve=No, Transfer=Draft only, Transfer Approve=No, Reserve/Release=No, Count=No, Import/Export=No,
Valuation View=Yes, Settings=No`) — the Inventory Manager holds exactly that grant, nothing broader.

# Inputs

The Inventory Manager consumes five categories of input, each with a defined refresh cadence.

| Category | Concrete sources | Refresh cadence | Consumed for |
|---|---|---|---|
| Stock state | `inventory_items` (all eight quantity buckets), `inventory_valuations` (open cost layers, standard-cost variance) | Real-time (event-driven) + nightly full re-read | Reorder monitoring, valuation sanity, dead-stock candidacy |
| Movement history | `stock_movements` (12–24 months, filtered `direction`/`movement_type`/`source_type`) | Nightly batch for analytics; real-time stream for anomaly detection | Demand fusion, ABC/XYZ, shrinkage baselines, anomaly detection |
| Demand signal | `product_demand_forecasts` (written by the Forecast Agent — see Collaboration), seasonality calendar (Ramadan/Eid/school-year cycles per company fiscal calendar) | Whenever the Forecast Agent refreshes (typically daily) | Reorder-point sizing, purchase-suggestion timing, dead-stock vs. "seasonal lull" disambiguation |
| Commercial context | `vendors.default_lead_time_days` / `average_lead_time_days` / `on_time_delivery_rate` / `quality_score`, `price_list_items.lead_time_days` and MOQ, `products.preferred_vendor_id`, `products.min_stock_level`/`max_stock_level`/`reorder_point`/`reorder_quantity`, `product_warehouse_settings` (per-warehouse overrides) | On read, per computation | Reorder-point formula, vendor-grouped purchase suggestions |
| Physical/audit context | `stock_adjustments` (reason codes, approval history), `stock_counts`/`stock_count_lines` (variance history), `warehouse_zones`/`warehouse_bins` (capacity, pick_sequence), `product_serials`/`product_batches` (status, expiry) | Event-driven + nightly | Shrinkage/anomaly detection, warehouse optimization, expiry-aware dead-stock scoring |
| Company policy | `companies.dead_stock_threshold_days` (default 180), `companies.adjustment_approval_threshold` (default KWD 100 equivalent), `companies.auto_approve_reorders_below`, `companies.auto_approve_replenishment_transfers`, `warehouses.expiry_warning_days` (default 30), `warehouses.receipt_tolerance_pct` (default 5%), `company_settings.ai_autonomy_level` | On read, cached with invalidation on settings change | Every threshold decision below |

Every input read is scoped to exactly one `company_id` (see Data Access & Tenant Scope). The agent
never blends data across companies, and never uses another company's transaction history as a training
signal for a specific recommendation — a deliberately stricter stance than some sibling agents (e.g.,
Chart of Accounts' account-naming classifier, which uses anonymized cross-tenant *feature* statistics),
because inventory cost, margin, and vendor-pricing data is directly commercially sensitive between
competing companies on the same platform.

# Outputs (confidence + reasoning + sources)

Every output the Inventory Manager produces — whether it results in a database row or not — carries
the same three-part contract every AI agent on the platform carries: a `confidence_score`
(`NUMERIC(4,3)`, 0.000–1.000), a `reasoning` string (plain-language, specific enough that a Purchasing
Manager can tell in one sentence *why* the agent thinks what it thinks), and an array of
`supporting_document_ids` (the exact `stock_movements`/`inventory_valuations`/`stock_adjustments`/
`purchase_orders`/`product_demand_forecasts` rows the conclusion was derived from). Two tables carry
this contract for the Inventory Manager's autonomous (non-conversational) work; both are part of the
platform's canonical-but-previously-undefined AI table set (`ai_tasks`, `ai_decisions`) named in
`docs/foundation/DATABASE_ARCHITECTURE.md`, and this document is their point of introduction.

## `ai_tasks` — one row per unit of scheduled or triggered work

```sql
CREATE TYPE ai_task_status  AS ENUM ('queued','running','completed','failed','cancelled');
CREATE TYPE ai_task_trigger AS ENUM ('scheduled','event','manual','chained');

CREATE TABLE ai_tasks (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    ai_agent_id        BIGINT NOT NULL REFERENCES ai_agents(id),
    task_type          VARCHAR(60) NOT NULL,
        -- 'reorder_point_scan' | 'dead_stock_scan' | 'abc_xyz_reclass' | 'shrinkage_scan'
        -- 'valuation_sanity_check' | 'warehouse_slotting_review' | 'purchase_suggestion_rollup'
    trigger_type       ai_task_trigger NOT NULL,
    trigger_reference  VARCHAR(120) NULL,       -- cron expression, domain event name, or user id
    status             ai_task_status NOT NULL DEFAULT 'queued',
    scope              JSONB NOT NULL DEFAULT '{}',   -- {"warehouse_id":7} or {"product_category_id":41}
    input_params       JSONB NOT NULL DEFAULT '{}',
    output_summary     JSONB NULL,               -- {"decisions_created":14,"avg_confidence":0.81}
    decisions_count    INTEGER NOT NULL DEFAULT 0,
    started_at         TIMESTAMPTZ NULL,
    completed_at       TIMESTAMPTZ NULL,
    duration_ms        INTEGER NULL,
    error_message      TEXT NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_tasks_company_type   ON ai_tasks (company_id, task_type, created_at DESC);
CREATE INDEX idx_ai_tasks_status_running ON ai_tasks (status) WHERE status IN ('queued','running');
```

## `ai_decisions` — one row per individual proposal a task produces

```sql
CREATE TYPE ai_decision_status AS ENUM
    ('pending','approved','rejected','auto_applied','expired','superseded');

CREATE TABLE ai_decisions (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    ai_agent_id             BIGINT NOT NULL REFERENCES ai_agents(id),
    ai_task_id              BIGINT NULL REFERENCES ai_tasks(id),
    decision_type           VARCHAR(60) NOT NULL,
        -- 'reorder_suggestion' | 'dead_stock_flag' | 'abc_class_update' | 'xyz_class_update'
        -- 'shrinkage_alert' | 'valuation_variance' | 'warehouse_reslot' | 'transfer_recommendation'
        -- 'purchase_suggestion_group'
    subject_type            VARCHAR(60) NOT NULL,   -- 'products' | 'inventory_items' | 'warehouse_bins' | ...
    subject_id              BIGINT NULL,
    warehouse_id            BIGINT NULL REFERENCES warehouses(id),
    proposed_action         JSONB NOT NULL,         -- concrete payload: qty, vendor_id, bin_id, price, ...
    confidence_score        NUMERIC(4,3) NOT NULL CHECK (confidence_score BETWEEN 0 AND 1),
    reasoning               TEXT NOT NULL,
    supporting_document_ids JSONB NOT NULL DEFAULT '[]',
    status                  ai_decision_status NOT NULL DEFAULT 'pending',
    resulting_record_type   VARCHAR(60) NULL,       -- 'purchase_requests' | 'stock_adjustments' | 'stock_transfers'
    resulting_record_id     BIGINT NULL,
    decided_by              BIGINT NULL REFERENCES users(id),
    decided_at              TIMESTAMPTZ NULL,
    expires_at              TIMESTAMPTZ NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_ai_decisions_resulting CHECK (
        (resulting_record_type IS NULL) = (resulting_record_id IS NULL)
    )
);
CREATE INDEX idx_ai_decisions_company_status ON ai_decisions (company_id, status);
CREATE INDEX idx_ai_decisions_task          ON ai_decisions (ai_task_id);
CREATE INDEX idx_ai_decisions_subject       ON ai_decisions (subject_type, subject_id);
CREATE INDEX idx_ai_decisions_type_pending  ON ai_decisions (company_id, decision_type)
    WHERE status = 'pending';
```

`ai_decisions` is deliberately the background/autonomous counterpart to the existing
`ai_messages`/`ai_feedback` pair: `ai_messages` carries confidence+reasoning for turn-based chat
proposals grounded in an `ai_conversations` thread; `ai_decisions` carries the identical contract for
proposals the agent originates on its own initiative, outside any conversation, exactly the kind of
work "QAYD's AI works before the user asks" describes. A `stock_transfers` row created with
`auto_generated = true` always has exactly one `ai_decisions` row with
`resulting_record_type = 'stock_transfers'` and `resulting_record_id` pointing at it — the transfer
table itself stores only the `auto_generated` flag (see `docs/accounting/INVENTORY.md` Database
Design); the full confidence/reasoning/evidence trail lives in `ai_decisions`, keeping the
domain table clean while keeping the AI trail complete and queryable independently.

## Example: a reorder suggestion `ai_decisions` row

```json
{
  "id": 88231,
  "company_id": 42,
  "ai_agent_id": 5,
  "ai_task_id": 190442,
  "decision_type": "reorder_suggestion",
  "subject_type": "products",
  "subject_id": 4501,
  "warehouse_id": 3,
  "proposed_action": {
    "product_id": 4501,
    "sku": "WGT-100",
    "warehouse_id": 3,
    "suggested_purchase_request": {
      "vendor_id": 812,
      "quantity": 480,
      "unit_of_measure": "EA",
      "expected_unit_price": "2.150",
      "currency_code": "KWD",
      "required_by_date": "2026-08-05"
    }
  },
  "confidence_score": 0.87,
  "reasoning": "quantity_on_hand (96) plus quantity_on_order (0) will fall below the computed reorder point (210) on 2026-07-24, seven days before the forecast stockout date of 2026-07-31; Vendor 812's average_lead_time_days (9) plus a 2-day safety buffer requires ordering by 2026-07-22 at the latest. Quantity sized to cover 45 days of P50 forecast demand (392 units) plus safety stock at a 95% service level (88 units) = 480, rounded up to the vendor's MOQ multiple of 20.",
  "supporting_document_ids": [
    { "type": "product_demand_forecasts", "id": 60142 },
    { "type": "inventory_items", "id": 30871 },
    { "type": "vendors", "id": 812 },
    { "type": "stock_movements", "id_range": [982011, 982390] }
  ],
  "status": "pending",
  "resulting_record_type": null,
  "resulting_record_id": null,
  "expires_at": "2026-07-23T00:00:00Z",
  "created_at": "2026-07-16T02:00:00Z"
}
```

The `expires_at` field matters operationally: a reorder suggestion is time-sensitive (the lead-time
math that justified it decays every day it sits unreviewed), so a `pending` decision past `expires_at`
is transitioned to `expired` by a scheduled job and, if the underlying condition still holds, a fresh
decision is created rather than resurrecting a stale one with outdated numbers — this prevents a
Purchasing Manager from approving a two-week-old suggestion whose quantity math no longer reflects
current stock.

## Output shape by decision type

| `decision_type` | `proposed_action` payload shape | Typical confidence range | Where it surfaces |
|---|---|---|---|
| `reorder_suggestion` | `{vendor_id, quantity, unit_price, required_by_date}` | 0.55–0.95 | Purchasing Manager queue, Reorder report |
| `purchase_suggestion_group` | `{vendor_id, line_items: [...], consolidated_total}` | Weakest-link of grouped lines | Vendor-grouped buy-list UI |
| `dead_stock_flag` | `{days_since_last_sale, tied_up_value_base, recommendation: 'markdown'\|'promote'\|'dispose'\|'transfer'}` | 0.5–0.98 | Dead Stock report, Inventory Manager (human role) dashboard |
| `abc_class_update` / `xyz_class_update` | `{previous_class, new_class, pct_of_total_value or cov}` | Deterministic (reported, not probabilistic) | Product record, ABC/XYZ report |
| `shrinkage_alert` | `{warehouse_id, product_id\|employee_id, deviation_from_baseline_pct}` | 0.5–0.9 | Auditor case queue, Inventory Manager dashboard |
| `valuation_variance` | `{expected_value_base, actual_value_base, delta, likely_cause}` | 0.6–0.99 | General Accountant close-checklist |
| `warehouse_reslot` | `{product_id, from_bin_id, to_bin_id, pick_frequency_delta}` | 0.4–0.9 (sample-size dependent) | Warehouse Manager slotting review |
| `transfer_recommendation` | `{product_id, source_warehouse_id, destination_warehouse_id, quantity}` | 0.5–0.9 | Draft `stock_transfers`, Warehouse Manager queue |

# Tools & API Access (endpoint + permission-key table)

The Inventory Manager holds a single, narrowly-scoped service-account permission,
**`inventory.ai_agent`**, introduced by this document following the exact precedent set by Products'
`products.ai_agent` permission. `inventory.ai_agent` gates every write the agent performs inside its
home module (`inventory_items.suggested_reorder_point`, draft `stock_adjustments`, draft
`stock_transfers`, `ai_tasks`/`ai_decisions` rows) and is held **only** by the AI service account —
never assignable to a human role. It does not grant `.approve`, `.dispatch`, `.close`, `.reserve`,
`.count.*`, `.import`, `.export`, or `.settings.manage`; those remain exclusively human, exactly as the
Role Grant Table in `docs/accounting/INVENTORY.md` specifies. For cross-module actions, the service
account additionally holds two narrow grants: `purchasing.request.create` (draft-only — never
`.submit`/`.approve`) and `products.ai_agent` (classification-field writes only — never
`products.update`).

| Tool (function) | Method & Path | Permission Key | Draft/Read only? | Description |
|---|---|---|---|---|
| `inventory_get_items` | `GET /api/v1/inventory/items` | `inventory.read` | Read | List `inventory_items`, filterable by product/warehouse/bin/below-reorder |
| `inventory_get_item` | `GET /api/v1/inventory/items/{id}` | `inventory.read` + `inventory.valuation.view` | Read | Single item with current buckets and valuation layer detail |
| `inventory_get_movements` | `GET /api/v1/inventory/movements` | `inventory.read` | Read | Movement history for demand/shrinkage/anomaly analysis |
| `inventory_search` | `GET /api/v1/inventory/search` | `inventory.read` | Read | Barcode/serial/batch/full-text lookup |
| `inventory_get_counts` | `GET /api/v1/inventory/counts` | `inventory.count.read` | Read | Cycle/physical count history and variances (shrinkage input) |
| `inventory_update_suggested_reorder_point` | `PATCH /api/v1/inventory/items/{id}` `{suggested_reorder_point, suggested_max_stock_level}` | `inventory.ai_agent` | Write (staging field only) | Stock Optimization output; never touches live `reorder_point` |
| `inventory_create_adjustment_draft` | `POST /api/v1/inventory/adjustments` | `inventory.ai_agent` | Draft write | Shrinkage/count-variance evidence packaged as a draft adjustment |
| `inventory_create_transfer_draft` | `POST /api/v1/inventory/transfers` | `inventory.ai_agent` | Draft write | Rebalancing/replenishment recommendation, `auto_generated=true` |
| `inventory_bulk_adjust_draft` | `POST /api/v1/inventory/bulk-adjust` | `inventory.ai_agent` | Draft write | Batched draft adjustments from a reconciled cycle-count run |
| `products_get` | `GET /api/v1/products/{id}` | `products.read` | Read | Product master data, `inventory_summary`, current `abc_class`/`xyz_class`/`reorder_point` |
| `products_update_classification` | `PATCH /api/v1/products/{id}` `{abc_class, xyz_class}` | `products.ai_agent` | Auto write (label only) | Monthly ABC/XYZ reclassification, one call per SKU, individually audit-logged |
| `products_get_demand_forecast` | `GET /api/v1/products/{id}/demand-forecast` | `products.read` | Read | Reads the Forecast Agent's `product_demand_forecasts` output |
| `vendors_get` | `GET /api/v1/vendors/{id}` | `vendors.read` | Read | Lead time, on-time rate, quality score, MOQ for reorder sizing |
| `purchasing_create_request_draft` | `POST /api/v1/purchasing/purchase-requests` | `purchasing.request.create` | Draft write (cross-module) | Creates the actual `purchase_requests` row, `source='reorder_point'` |
| `purchasing_get_requests` | `GET /api/v1/purchasing/purchase-requests` | `purchasing.request.read` | Read | Checks for an existing open PR before creating a duplicate |
| `warehouses_get_bins` | `GET /api/v1/warehouses/bins` | `warehouses.read` | Read | Capacity, dimensions, pick_sequence for slotting recommendations |

Every call — read or write — carries `X-Company-Id` (the active tenant) and `X-Actor-Type: ai_agent`,
and every write additionally carries `X-AI-Task-Id`/`X-AI-Decision-Id` so the resulting `audit_logs`
row links back to the exact `ai_tasks`/`ai_decisions` row that authored it, satisfying the platform's
blanket audit rule (`audit_logs.ai_agent_id` populated, `user_id` NULL) without any bespoke logging code
in the agent itself — the Laravel middleware that already stamps human requests stamps AI requests
identically.

# Data Access & Tenant Scope

**Write path — always the API, no exceptions.** Every write listed in Tools & API Access above goes
through the Laravel API's Controller → FormRequest → Service → Repository chain, exactly as a human
user's request would. The FastAPI AI layer holds no direct PostgreSQL write credentials for any
tenant-scoped table. This is not a convention the agent chooses to follow — the Sanctum token issued to
the AI service account is scoped by RBAC to only the permission keys listed above, so a hypothetical
direct-write attempt would fail Laravel's authorization layer even if the AI layer's code tried it.

**Read path — API for scoped queries, analytical replica for heavy aggregation.** Interactive,
narrowly-scoped reads (one product's current buckets, one vendor's lead time) go through the same
`/api/v1/*` surface. Heavy historical aggregation — 12–24 months of `stock_movements` across an entire
product catalog for ABC/XYZ or demand-curve fusion, which would be impractical to paginate through JSON
REST calls — reads from a permissioned, read-only PostgreSQL replica, still filtered by `company_id` at
the query level and still subject to a row-level security policy identical in effect to the one the
Laravel app enforces (see `docs/database/ROW_LEVEL_SECURITY.md`), so the replica path can never leak a
row across tenants even though it bypasses the Laravel HTTP layer for performance. No table row is ever
written through this path; it is read-only at the database-role level (`GRANT SELECT` only, no
`INSERT`/`UPDATE`/`DELETE` grants to the analytical role).

**Tenant scope.** Every `ai_tasks` and `ai_decisions` row carries `company_id NOT NULL`, indexed, and
every task the agent runs is invoked with exactly one `company_id` in scope — there is no "run for all
companies" task type; a platform-wide nightly scan is implemented as N independent per-company task
enqueues, never a single cross-tenant query. `branch_id` is honored where a company has enabled
branch-level segregation (e.g., independently reordering for a Salmiya branch vs. a Fahaheel branch of
the same Kuwait company). The agent's own working memory (see Reasoning & Prompt Strategy and the
per-company `ai_memory` this document references but does not fully own) is likewise partitioned per
`company_id`; a lesson learned inside Company A's inventory patterns (e.g., "this company's true
customs lead time from China runs 12 days longer than the vendor's quoted lead time") never leaks into
Company B's context, even when both companies use the same vendor.

# Reasoning & Prompt Strategy

The Inventory Manager runs as a LangGraph-style state machine inside the FastAPI AI layer, not as a
single prompt-in/answer-out call. Each `task_type` maps to its own graph with a common shape:

```
┌───────────┐   ┌────────────┐   ┌───────────────┐   ┌────────────┐   ┌──────────────┐
│  Gather   │──▶│  Retrieve  │──▶│    Reason     │──▶│   Score    │──▶│    Emit      │
│  Scope    │   │  Context   │   │ (tool calls,  │   │ Confidence │   │ ai_decisions │
│ (company, │   │ (SQL reads,│   │  chain-of-    │   │ + write    │   │ row(s), or   │
│ warehouse,│   │  ai_memory,│   │  thought vs.  │   │ reasoning  │   │ direct draft │
│ product   │   │  vector    │   │  hard rules)  │   │  string    │   │ write via    │
│ set)      │   │  search)   │   │               │   │            │   │ tools        │
└───────────┘   └────────────┘   └───────────────┘   └────────────┘   └──────────────┘
                                        │
                                        ▼
                              ┌───────────────────┐
                              │ Hard-constraint    │  (capacity, MOQ, active-status,
                              │ filter — always    │   count-lock, existing pending
                              │ wins over          │   decision) — a rule violation
                              │ confidence         │   discards the candidate outright
                              └───────────────────┘
```

**Gather Scope.** Every run begins by resolving exactly which `company_id` (and optionally
`warehouse_id`/`product_category_id`/`abc_class` subset) it is operating against, recorded verbatim
into `ai_tasks.scope`. Scheduled tasks (nightly reorder scan, monthly ABC/XYZ reclass) resolve scope
from the company's registration in the job scheduler; event-triggered tasks (`inventory.
reorder_point_reached`) resolve scope from the event payload; manual tasks resolve scope from the
requesting user's session.

**Retrieve Context.** The agent assembles a bounded context window per candidate (never the entire
catalog in one prompt): for a single product-warehouse reorder evaluation, context includes that
pair's current `inventory_items` row, the last 90–365 days of relevant `stock_movements` (window length
adapts to the product's `xyz_class` — stable "X" products use a longer, smoother window; erratic "Z"
products use a shorter, more reactive one), the latest `product_demand_forecasts` row, the preferred
vendor's lead-time/MOQ/pricing data, and any relevant `ai_memory` entries scoped to that
`(company_id, product_id)` or `(company_id, vendor_id)` pair (see Collaboration and the dedicated AI
Memory documentation for the full retrieval contract). Catalog-wide tasks (ABC/XYZ, dead-stock sweep)
instead retrieve pre-aggregated summary statistics per product (total value, coefficient of variation,
days since last sale) computed by a SQL pass against the analytical replica, keeping the prompt's
context proportional to the number of *flagged* candidates, not the size of the catalog.

**Reason.** Two reasoning modes are used depending on `task_type`. Deterministic, formulaic
computations (ABC value-contribution ranking, XYZ coefficient of variation, the reorder-point formula
itself: `reorder_point ≈ average_daily_demand × lead_time_days × safety_factor`) are computed as plain
arithmetic against retrieved data, not "reasoned" by the language model at all — the model's job there
is to select the right window/safety factor per `xyz_class` and to compose the `reasoning` string, not
to do the arithmetic itself, which eliminates a whole class of numeric hallucination risk. Judgment-
heavy tasks (dead-stock disposal vs. markdown vs. promotion recommendation, shrinkage root-cause
narrative, warehouse re-slotting trade-offs) use full chain-of-thought reasoning over the retrieved
context, calling additional tools mid-reasoning where a fact is missing (e.g., checking
`vendors.on_time_delivery_rate` mid-reasoning when a reorder's confidence hinges on lead-time
reliability) via the platform's MCP tool-calling contract.

**Score Confidence.** Confidence is a function of (a) sample size / history depth, (b) signal agreement
across independent inputs (e.g., Forecast Agent's demand curve and the agent's own movement-history
read pointing the same direction), and (c) distance from a hard threshold (a product 40% below its
reorder point scores higher confidence than one 2% below it, all else equal). The platform-standard
confidence bands are reused verbatim: **< 0.6** is shown only in an expanded/"all suggestions" view and
excluded from auto-anything; **0.6–0.85** is a pre-filled, editable default requiring a click;
**≥ 0.85** is eligible for the narrow set of `auto` actions in the Autonomy Level table. Model routing
follows task weight: routine, high-volume, mostly-arithmetic tasks (nightly reorder scan across
thousands of SKUs) run on the registry's default `claude-sonnet-4-5`; low-volume, high-stakes narrative
reasoning (a dead-stock disposal recommendation crossing a materiality threshold, a shrinkage case with
a named-employee implication) is escalated to a stronger model tier via a per-`ai_tasks.task_type`
model override, keeping cost proportional to decision consequence.

**Emit.** The final graph node either (a) writes an `ai_decisions` row only (informational — ABC/XYZ
report entries, dead-stock flags with no action taken yet), or (b) writes the `ai_decisions` row **and**
calls the corresponding tool to create the actual draft record (`purchase_requests`, `stock_adjustments`,
`stock_transfers`, or the classification `PATCH`), storing the resulting `resulting_record_type`/
`resulting_record_id` back onto the same `ai_decisions` row in the same logical transaction (a Laravel-
side transaction wraps the draft-record insert and the audit log write; the `ai_decisions` row is
updated by the FastAPI layer immediately after a successful API response, with a reconciliation job
catching any decision left `pending` with a stale `ai_task_id` whose task already completed — a
dangling-decision edge case, see Failure Modes).

**The Hard-Constraint Filter** runs orthogonally to confidence at every stage, not just at the end: a
candidate that fails a hard constraint (insufficient bin capacity for a re-slot, a discontinued or
`is_purchasable = false` product for a reorder, an active warehouse count lock for an adjustment
target, a batch already fully consumed for a shrinkage investigation) is discarded before it ever
reaches the scoring node, regardless of how high its confidence would otherwise be — mirroring the
platform-wide principle already established for Warehouses' Stock Placement Suggestions ("a bin that
fails a hard capacity/dimension check is never suggested regardless of confidence").

**Learning loop.** Every `ai_decisions.status` transition to `approved`, `rejected`, or `auto_applied`
is a training signal written to `ai_memory` (full schema and governance owned by the dedicated AI
Memory documentation; the Inventory Manager is a writer and reader of it, not its owner). A rejection
with a human-entered reason carries the highest weight, exactly as established for the Chart of
Accounts classifier's learning loop: if a Purchasing Manager rejects a reorder suggestion three times
in a row with the reason "actual lead time from this vendor is always ~3 weeks, not the 9 days on
file," the agent's next reorder-point computation for that `(company_id, vendor_id)` pair uses the
learned 21-day figure as a prior alongside — never silently replacing — `vendors.average_lead_time_days`,
and surfaces the discrepancy itself as a `reasoning` note so a human can decide whether to correct the
vendor master record.

# Collaboration With Other Agents

The Inventory Manager is deliberately not self-sufficient — it is the hub of a four-agent working
group inside the Inventory/Purchasing/Accounting surface, and it explicitly defers work outside its own
competence rather than approximating it.

| Collaborator | Direction of flow | What crosses the boundary |
|---|---|---|
| **Forecast Agent** | Inbound (primary input) | The Forecast Agent owns time-series demand modeling: seasonality-adjusted 30/60/90-day demand curves per product/warehouse, written to `product_demand_forecasts` (jointly owned with Products — Products stores the forecast row, Inventory Manager's reorder job reads it). The Inventory Manager never re-derives a demand curve from raw movement history on its own; it consumes the Forecast Agent's curve and applies inventory-specific policy (lead time, safety stock, MOQ, current buckets) on top of it. When the Forecast Agent's confidence is low (thin history, a genuinely new SKU), the Inventory Manager's own reorder-point confidence is capped at that same ceiling — it cannot manufacture certainty its upstream input doesn't have. |
| **Purchasing Agent** | Outbound (primary output) | Once a reorder or vendor-grouped purchase suggestion clears its confidence and hard-constraint checks, the Inventory Manager calls `POST /api/v1/purchasing/purchase-requests` (`purchasing.request.create`, draft-only) to hand the suggestion to the procurement workflow the Purchasing Agent operates — RFQ consolidation, vendor negotiation support, and the three-way-match/approval chain described in `docs/accounting/PURCHASING.md` are the Purchasing Agent's responsibility, not the Inventory Manager's. The relationship is one-directional for creation (Inventory Manager proposes, Purchasing Agent's workflow processes) and bidirectional for data: the Purchasing Agent's module also writes back `vendors.average_lead_time_days`, `on_time_delivery_rate`, and `quality_score`, which the Inventory Manager reads on every subsequent reorder-point computation, closing the loop between "how well did this vendor actually perform" and "how much safety stock do we need against them." |
| **General Accountant** | Bidirectional | Every valuation-sanity finding (a FIFO/LIFO layer whose `quantity_remaining` has drifted from what `stock_movements` implies, a Weighted-Average recomputation that doesn't tie to `inventory_valuations_history`, a Standard Cost variance sitting unexplained for more than a fiscal period) is handed to the General Accountant as a `valuation_variance` decision rather than being corrected by the Inventory Manager itself, because correcting inventory valuation touches the general ledger — squarely General Accountant territory, matching the precedent in `docs/accounting/GENERAL_LEDGER.md` where the General Accountant Agent (not any sub-ledger agent) is the one that drafts correcting journal entries. The General Accountant, in turn, is the Inventory Manager's source of truth for which valuation method and control accounts apply per product, and confirms at period close that `inventory_items` on-hand value reconciles to the Inventory control account balance — a reconciliation the Inventory Manager's valuation-sanity task pre-checks nightly so period close never surfaces a surprise. |
| **Fraud Detection** | Outbound (escalation) | The Inventory Manager's Shrinkage Detection and Anomaly Detection capabilities compute the statistical signal (deviation from a warehouse's historical loss baseline, adjustment clustering around a specific `created_by` user, round-number adjustments, after-hours timing); Fraud Detection owns turning a cluster of such signals into a scored fraud-risk case and the cross-domain correlation (does this warehouse employee's adjustment pattern also correlate with anomalies Fraud Detection sees in Banking or Payroll for the same person). The Inventory Manager never accuses, blocks, or reverses a transaction; it hands evidence to Fraud Detection, which escalates to the Auditor role for human investigation, exactly as `docs/accounting/INVENTORY.md`'s Fraud Detection row specifies. |
| **Auditor** | Outbound | Shrinkage cases, LIFO-on-an-IFRS-company compliance flags (`companies.allow_lifo` misconfiguration), and dead-stock write-downs above a materiality threshold are surfaced to the Auditor agent/role as findings requiring independent review, following the same pattern the Auditor already uses platform-wide for Trial Balance and Chart of Accounts findings. |
| **CFO** | Outbound (informational) | Aggregate dead-stock tied-up capital, inventory turnover ratio trends, and large pending purchase-suggestion totals are surfaced to the CFO agent's dashboard-level rollups (not a direct API call — the CFO agent reads the same Reports layer a human CFO would) so working-capital decisions account for inventory's cash commitment. |
| **Reporting Agent** | Outbound | Every report listed in `docs/accounting/INVENTORY.md`'s Reports section that has an "AI Recommendation" or "Confidence" column is populated from the Inventory Manager's `ai_decisions`, not recomputed independently by the Reporting Agent — the Reporting Agent is a presentation layer over the Inventory Manager's findings for this domain, avoiding two agents silently disagreeing about the same number. |

Two clarifications on naming, stated explicitly to keep this document internally consistent with
sibling module documents written from each module's own point of view: first, `docs/accounting/
WAREHOUSES.md`'s AI Responsibilities table names capability-specific personas ("Warehouse Optimization
Agent," "Picking Route Optimization Agent," "Stock Placement Suggestions Agent," "Space Utilization
Agent," "Receiving Prediction Agent," "Transfer Recommendations Agent") — every one of these is a
function the Inventory Manager performs, not a distinct `ai_agents` registry row. Second,
`docs/accounting/PURCHASING.md`'s AI Responsibilities section attributes Vendor Recommendation to
"General Accountant / Treasury Manager (procurement-tuned)" and Demand Forecasting/Purchase Prediction
to "Forecast Agent"; the dedicated Purchasing Agent referenced throughout this document consolidates
and operates those procurement-side capabilities going forward, with the Inventory Manager remaining
the party that decides *when* and *how much* to reorder, and the Purchasing Agent remaining the party
that decides *from whom* and *on what commercial terms*.

# Guardrails & Human Approval

1. **No direct database writes, ever.** Every mutation goes through Laravel's validated API under the
   `inventory.ai_agent` / `products.ai_agent` / `purchasing.request.create` permissions. There is no
   code path, emergency override, or "trusted internal service" bypass — the AI service account's
   Sanctum token is authorization-checked identically to a human's.
2. **Draft is the ceiling, always, for financial and quantity-affecting actions.** `purchase_requests`
   land in `draft`; `stock_adjustments` land in `draft`; `stock_transfers` land in `draft` with
   `auto_generated = true`. The agent holds none of `.submit`, `.approve`, `.dispatch`, `.receive`,
   `.post`, or `.close` for any of these. This is enforced at the RBAC layer (the service account's role
   simply does not carry those permission keys), not merely by application logic the agent could
   theoretically ignore.
3. **The one auto-write exception is capped, logged, and reversible.** `products.abc_class`/
   `xyz_class` writes are non-financial labels with no ledger impact, permitted under
   `products.ai_agent`, but every write is still an `audit_logs` row (`ai_agent_id` populated,
   `old_value`/`new_value` captured) and a company can force even this to `suggest_only` via
   `company_settings.ai_autonomy_level`.
4. **Monetary thresholds are always human-configured, never self-set.**
   `companies.adjustment_approval_threshold`, `companies.auto_approve_reorders_below`,
   `companies.auto_approve_replenishment_transfers`, and `companies.dead_stock_threshold_days` are
   company settings a human with `inventory.settings.manage`/`accounting.settings.manage` configures;
   the Inventory Manager reads them, never writes them, and never recommends changing them without an
   explicit, separately-approved `settings_recommendation` decision type distinct from its normal
   operational decisions.
5. **Confidence floors gate visibility and action identically for every decision type.** Below 0.6:
   suppressed from primary views, visible only in an expanded list, never eligible for any `auto`
   pathway. 0.6–0.85: a pre-filled, editable, human-actionable default. At or above 0.85: eligible for
   the narrow `auto` action set in the Autonomy Level table, and no higher — there is no confidence
   level at which the agent gains authority it does not already hold in that table.
6. **Hard constraints always outrank confidence.** A capacity violation, an inactive/non-purchasable
   product, an active warehouse count lock, or a duplicate pending decision for the same subject
   discards a candidate outright, independent of how confidently the model would otherwise have scored
   it (see Reasoning & Prompt Strategy).
7. **Anti-flood rate limiting.** No more than `companies.ai_max_daily_transfer_drafts` (default 10) new
   `auto_generated` transfer drafts and no more than `companies.ai_max_daily_adjustment_drafts` (default
   15) new adjustment drafts are created per company per day without an explicit human request,
   preventing the agent from overwhelming a Warehouse Manager's approval queue during a volatile period
   (e.g., a large simultaneous multi-warehouse stock-count reconciliation) — additional candidates queue
   for the next day rather than being dropped, ranked by confidence and materiality.
8. **No cross-tenant learning on commercially sensitive data.** As stated in Data Access & Tenant Scope,
   the Inventory Manager's `ai_memory` is per-company only; it never trains a specific company's
   recommendation on another company's cost, margin, or vendor-pricing history, even in anonymized
   feature form.
9. **Every action is attributable and reversible in principle.** `ai_decisions`/`ai_tasks` rows are
   never deleted (only status-transitioned); `audit_logs` captures every write with `ai_agent_id`; a
   human reviewing "why did the system suggest this" always has a complete, replayable evidence chain
   back to the specific `stock_movements`/`product_demand_forecasts`/`vendors` rows involved.
10. **Escalation, never enforcement, for fraud/shrinkage signals.** The agent flags; it never blocks a
    user, freezes a warehouse, or reverses a posted transaction — those actions, if ever taken, are
    human decisions executed through the normal Approval Workflow, informed by but never triggered
    directly by the Inventory Manager's output.

# Worked Scenarios (2-3 end-to-end)

## Scenario 1 — Reorder-point breach becomes an approved purchase order

**Company 42**, a Kuwait medical-supplies distributor, base currency KWD. Product `WGT-100`
("Nitrile Examination Gloves, Box of 100" — `name_ar`: "قفازات نتريل، علبة 100"), warehouse
`WH-KWT-01`, `xyz_class = 'X'` (stable demand), `abc_class = 'A'` (top value contributor).

1. **02:00** — the nightly `reorder_point_scan` `ai_tasks` row is created (`trigger_type='scheduled'`,
   `scope={"company_id":42}`). It scans every `inventory_items` row where
   `quantity_on_hand + quantity_on_order <= reorder_point` (the partial index described in
   `docs/accounting/INVENTORY.md` Performance section). `WGT-100` at `WH-KWT-01` shows
   `quantity_on_hand=96`, `quantity_on_order=0`, `reorder_point=210`.
2. **Retrieve Context** — reads the latest `product_demand_forecasts` row (Forecast Agent, refreshed
   03:00 the prior day): P50 demand of 392 units over the next 45 days, confidence 0.91 (long, stable
   history — `xyz_class='X'`). Reads `vendors.id=812` (preferred vendor): `average_lead_time_days=9`,
   `on_time_delivery_rate=0.94`, MOQ multiple of 20 units, last-paid unit price KWD 2.150.
3. **Reason** — `reorder_point ≈ 392/45 × 9 × 1.3 (safety factor for 'X' class, 95% service level) ≈
   102`; but the *live* `reorder_point` on file (210) is already higher than this computation, so no
   `suggested_reorder_point` update is written this cycle (the existing policy is already conservative
   enough — the agent does not manufacture a change where none is warranted). Quantity to order:
   45-day P50 demand (392) plus 88 units of safety stock, rounded to the MOQ multiple of 20 = 480.
   Required-by date: forecast stockout (2026-07-31) minus lead time (9 days) minus a 2-day buffer =
   2026-07-20; the agent computes 2026-07-16 (today) is still inside the window, so this is a
   `reorder_suggestion`, not an emergency escalation.
4. **Score** — confidence 0.87 (see the full `ai_decisions` JSON example under Outputs above): high
   sample size, stable demand class, single-vendor scenario with a strong on-time track record.
5. **Emit** — since 0.87 ≥ 0.85 but the estimated total (480 × KWD 2.150 = KWD 1,032.000) exceeds
   `companies.auto_approve_reorders_below` (KWD 500 for Company 42), the decision auto-advances to
   creating a **draft** `purchase_requests` row (`source='reorder_point'`, `status='draft'`) via
   `purchasing.request.create`, but does **not** auto-submit it — it lands in the Purchasing Employee's
   queue tagged with the full `ai_decisions` reasoning chain attached.
6. **Human step** — the Purchasing Employee reviews the draft PR the next morning, sees the attached
   reasoning and the linked demand-forecast chart, and calls `POST .../purchase-requests/{id}/submit`.
   It enters the standard Approval Workflow (Section: Approval Workflow, `docs/accounting/
   PURCHASING.md`) with a `min_amount`/`max_amount` tier appropriate to KWD 1,032; a Purchasing Manager
   approves it same-day. Purchasing subsequently issues the PO to Vendor 812.
7. **Outcome** — the `ai_decisions` row transitions `pending → approved`, `resulting_record_type =
   'purchase_requests'`, `resulting_record_id` set to the PR's id, `decided_by` the Purchasing
   Employee's user id. Nine days later the goods receipt posts and `quantity_on_hand` never crosses
   zero — the stockout the forecast predicted for 2026-07-31 did not happen. This outcome (no stockout,
   PR approved without modification) is written back to `ai_memory` as a positive reinforcement signal
   for this `(product_id, vendor_id)` pair's lead-time and safety-factor assumptions.

## Scenario 2 — Dead-stock detection interacting with ABC/XYZ and a seasonal false alarm

**Company 42**, warehouse `WH-KWT-01`, product `SUN-220` ("SPF 50 Sunscreen Lotion, 200ml"),
`abc_class='B'`, `xyz_class='Y'` (variable demand).

1. The monthly `dead_stock_scan` task flags `SUN-220`: 172 days since last outbound movement, exceeding
   `companies.dead_stock_threshold_days` (180 is the default, but Company 42 has configured 150),
   `inventory_valuations` showing 340 units on hand worth KWD 1,904.000 tied up.
2. Before emitting a `dead_stock_flag`, the reasoning step cross-checks the Forecast Agent's seasonality
   calendar: `SUN-220`'s historical movement shows a sharp, recurring demand spike every April–September
   (Gulf summer season) and near-zero movement November–February, a pattern the coefficient-of-variation
   underlying `xyz_class='Y'` already reflects. The current date context (mid-July, in-season) means 172
   days of no movement is genuinely anomalous for this SKU rather than an expected seasonal lull — had
   this scan run in January, the same 172-day gap would have been suppressed entirely as
   seasonally-expected, never reaching a decision row at all.
3. Because the seasonal check confirms this is a real anomaly during what should be peak season, the
   agent raises confidence to 0.79 (moderate — a single, if unusual, product rather than a systemic
   pattern) and reasoning notes explicitly: "expected to be in peak season based on 3 prior years of
   April–September demand; a 172-day gap in-season is inconsistent with that pattern and warrants
   review, unlike a similar gap would be outside the season."
4. **Emit** — a `dead_stock_flag` decision with `recommendation: 'promote'` (not `'dispose'` — the
   product is not expired, not damaged, simply currently unsold; a promotional push during the
   remaining season is the economically rational recommendation the reasoning step selects over
   markdown or disposal, both of which would be premature for an in-season, non-expiring product).
5. **Human step** — the Inventory Manager (human role) reviews the flag alongside the underlying
   seasonality chart, agrees the in-season stall is unusual (a competitor recently undercut price,
   as it turns out — information outside the agent's data), and manually creates a promotional price
   rule through Sales/Pricing (a separate module's normal write path — the Inventory Manager never
   touches pricing itself).

## Scenario 3 — Shrinkage pattern escalated through Fraud Detection to the Auditor

**Company 87**, warehouse `WH-JAH-02`. Over six weeks, the `shrinkage_scan` task (triggered nightly,
`trigger_type='scheduled'`) accumulates signal: five `stock_adjustments` rows with `reason_code='damage'`
each between KWD 85–98 (just under the `companies.adjustment_approval_threshold` of KWD 100, which
would otherwise require a second approver), all `requested_by` the same warehouse employee, four of the
five entered after 18:00 (outside the warehouse's 08:00–17:00 posted shift hours), and all against
high-resale-value electronics accessories rather than the fragile/perishable categories where damage
adjustments are otherwise concentrated for this warehouse.

1. **Reason** — the shrinkage-detection graph computes a deviation score against `WH-JAH-02`'s
   historical baseline (typical damage-adjustment value distribution, typical reason-code/category
   correlation, typical time-of-day distribution) and finds this employee's pattern 3.4 standard
   deviations from that baseline on the combined signal (value-clustering-just-under-threshold +
   after-hours timing + category mismatch).
2. **Score** — confidence 0.78, reasoning explicitly enumerated per signal ("clustering under approval
   threshold: 0.31 contribution; after-hours timing: 0.28 contribution; category mismatch: 0.19
   contribution"), consistent with the platform's convention of decomposable fraud-confidence scoring.
3. **Emit** — a `shrinkage_alert` `ai_decisions` row, `status='pending'`, immediately visible to the
   Fraud Detection agent's case queue (Fraud Detection is a **subscriber** to Inventory Manager's
   `shrinkage_alert` decisions, not a poller of raw `stock_adjustments` — this keeps the correlation
   logic in one place). The Inventory Manager does **not** flag the specific employee by name in any
   user-facing report at this stage beyond the case queue visible to Auditor/Fraud Detection roles; it
   does not block the employee's account, does not prevent further adjustments, and does not reverse the
   five existing (already-approved, low-value, individually-legitimate-looking) adjustments.
4. **Cross-domain correlation (Fraud Detection)** — Fraud Detection checks whether this same
   `created_by` user correlates with any Banking/Payroll anomaly signal (out of this document's scope)
   and finds none; it still escalates the inventory-only pattern to the Auditor role given the
   statistical strength of the clustering alone.
5. **Human step** — the Auditor reviews the case, pulls the five underlying `stock_adjustments` and
   their attached photos (via the polymorphic `attachments` table), and opens a formal investigation,
   which may include a targeted physical count of that category at that warehouse. The case's outcome
   (substantiated / unsubstantiated / inconclusive) is written back so the Inventory Manager's baseline
   for this warehouse recalibrates — if substantiated, this warehouse's damage-adjustment baseline is
   *not* relaxed to include the fraudulent pattern; if unsubstantiated, the specific signal combination
   that triggered the false positive is down-weighted for this warehouse going forward via `ai_memory`.

# Metrics & Evaluation

| Metric | Definition | Target | Consumed by |
|---|---|---|---|
| Suggestion acceptance rate | `approved / (approved + rejected)` per `decision_type`, rolling 90 days | > 70% for `reorder_suggestion`; > 50% for `dead_stock_flag` (lower bar — disposal/markdown decisions are intentionally conservative to propose) | Agent tuning, company-level trust dashboard |
| Forecast-linked stockout rate | % of SKUs that stocked out despite an active, unexpired `reorder_suggestion` in `pending`/`approved` state within its lead-time window | < 2% | CFO/Inventory Manager (human) dashboard |
| Over-stock cost avoided vs. incurred | Value of stock held above 1.5× the computed reorder point, trended | Downward trend quarter over quarter | CFO |
| Dead-stock value recovered | KWD value of flagged dead stock that transitioned out of `quantity_on_hand` via markdown/promotion/disposal within 90 days of the flag | > 60% of flagged value actioned within 90 days | Inventory Manager (human), Reporting Agent |
| ABC/XYZ classification stability | % of products whose `abc_class`/`xyz_class` changes more than once per quarter (a proxy for noisy, low-confidence classification) | < 10% | Agent quality review |
| Shrinkage false-positive rate | `unsubstantiated / total shrinkage_alert cases closed`, rolling 180 days | < 30% (fraud signals are inherently precision-recall-tradeoff-heavy; this ceiling avoids alert fatigue while accepting some false positives are the cost of catching true ones) | Fraud Detection, Auditor |
| Valuation variance detection lead time | Days between an actual valuation drift occurring and the agent's `valuation_variance` decision surfacing it, vs. days until the next scheduled period close | Always ≥ 5 business days before close | General Accountant |
| Confidence calibration (Brier score) | Mean squared error between stated `confidence_score` and actual approval outcome, computed per `decision_type` | < 0.15, reviewed monthly, triggers a recalibration task if breached for two consecutive months | Agent evaluation pipeline |
| Draft-to-decision latency | Time from `ai_tasks.completed_at` to a human's first action on the resulting `ai_decisions` row | Reported, not target-gated (a proxy for approval-queue health, not agent quality) | Operations dashboard |
| Warehouse re-slotting adoption rate | % of `warehouse_reslot` suggestions a Warehouse Manager actually executes | Reported; low adoption with high confidence triggers a review of the underlying travel-distance model, not an assumption the suggestions are simply being ignored | Warehouse Optimization quality review |

Evaluation runs as a scheduled `ai_tasks` type of its own (`task_type='agent_evaluation'`), reading the
prior period's `ai_decisions` outcomes, computing the table above per company and in aggregate across
companies (in fully anonymized, statistical form only — never feeding a specific company's
recommendation, per the cross-tenant guardrail), and writing results to `ai_logs` for the platform
team's model/prompt iteration process.

# Failure Modes & Edge Cases

| Failure Mode / Edge Case | Behavior |
|---|---|
| New SKU, no movement history | Demand fusion and reorder-point confidence capped low (< 0.5); `abc_class` defaults to `'C'` and `xyz_class` is left unset with a "new SKU — insufficient history" flag rather than a guessed class, matching `docs/accounting/INVENTORY.md`'s stated default. |
| Forecast Agent's confidence is low or its forecast is stale (> 48h old) | Reorder-point computation falls back to a simple moving-average-of-consumption method with its own (lower) confidence ceiling, explicitly noted in `reasoning` as "forecast unavailable — using trailing 30-day average consumption," never silently substituting a stale forecast as if it were current. |
| Duplicate/overlapping decisions | Before emitting, the agent checks for an existing `pending`/`approved` `ai_decisions` row of the same `decision_type` + `subject_id` + `warehouse_id`; if found and still within its validity window, no new decision is created — the existing one is left to run its course, preventing duplicate purchase requests for the same shortage. |
| Conflicting signals across capabilities | A product flagged `dead_stock_flag` in one cycle and `reorder_suggestion` in a later cycle (e.g., a sudden post-lull demand recovery) is not a bug — the dead-stock flag from the prior cycle is automatically superseded (`status='superseded'`) rather than left `pending` to contradict the new suggestion, and the `reasoning` for the new suggestion explicitly notes the prior dead-stock flag and why conditions changed. |
| Same-SKU, multi-warehouse conflicting suggestions | If Warehouse A is projected to stock out while Warehouse B (same company, same region) holds surplus of the identical SKU, the Warehouse Optimization capability's `transfer_recommendation` is generated and evaluated **before** a `reorder_suggestion` is allowed to fire for Warehouse A — the agent always prefers an internal rebalancing transfer over a new external purchase when one can cover the shortfall within the required lead time, avoiding the wasteful outcome of buying more of something the company already owns elsewhere. |
| Active warehouse count lock | Any draft `stock_adjustment`/`stock_transfer` targeting a warehouse with `count_in_progress = true` is held (not discarded) until the lock clears, since the count itself will likely explain and resolve the underlying variance the adjustment would have addressed. |
| Multi-currency vendor pricing | Reorder-quantity/cost math converts vendor pricing in a foreign `currency_code` to the company's base currency using the same `exchange_rate` resolution the Purchasing module uses for a PO of that date, never a hard-coded or stale rate; `reasoning` states both the transaction-currency and base-currency figures explicitly. |
| Company disables AI entirely | With `company_settings.ai_autonomy_level` set to the strictest setting, scheduled tasks still run (the analysis itself has value even if unattended) but every output is written only as an informational `ai_decisions` row with no accompanying draft-record creation of any kind — effectively an "insights only" mode; if the company additionally disables the `ai_agents` row (`status='disabled'`), no tasks run for that company at all. |
| Discontinued or non-purchasable product | `products.status != 'active'` or `is_purchasable = false` hard-excludes a product from reorder-point/purchase-suggestion generation regardless of any stock signal, though it remains eligible for dead-stock/disposal analysis (a discontinued product with residual stock is a canonical dead-stock case, not a reorder case). |
| Regulated-category disposal recommendation | A `dead_stock_flag` recommending `'dispose'` for a pharmaceutical, food, or other regulated-category product is additionally routed to the Compliance Agent for a regulatory-requirements check (certificate of destruction, controlled-substance disposal rules) before the human Inventory Manager role acts on it — the agent never assumes disposal is procedurally simple just because it is economically indicated. |
| Race between event-driven and scheduled triggers | `inventory.reorder_point_reached` (real-time) and the nightly `reorder_point_scan` (scheduled) can both fire for the same product-warehouse within hours of each other; the duplicate-decision check above (keyed on `subject_id` + `warehouse_id` + `decision_type` + open status) deduplicates regardless of which trigger arrived first. |
| Negative or implausible on-hand quantity from an upstream race condition | The agent treats any `quantity_on_hand < 0` it encounters not as an inventory-optimization input but as a data-integrity anomaly in its own right, immediately raised as a `valuation_variance`-adjacent alert to General Accountant/Auditor rather than fed into reorder math that would produce an equally implausible suggested quantity. |

# Future Improvements

- **Multi-echelon optimization jointly with Treasury Manager.** Today, reorder sizing considers lead
  time and demand but not the company's cash position; a future version consults the Treasury Manager
  agent's short-term cash-flow forecast before finalizing a large purchase suggestion, downgrading
  urgency (never silently cancelling) a large non-critical reorder that would strain a tight cash week.
- **Price-elasticity-aware markdown sizing.** Dead-stock markdown recommendations currently propose a
  qualitative action (`markdown`/`promote`/`dispose`/`transfer`); a future iteration works jointly with
  the CFO agent's Price Optimization capability to recommend a specific discount percentage sized to
  the product's estimated price elasticity rather than a flat default.
- **Computer-vision-assisted cycle counting.** Integrating with Document AI/OCR Agent for shelf-image or
  drone-image based count estimation, cross-checked against the RFID/barcode count-line data already
  supported, to reduce manual cycle-count labor for high-SKU-count warehouses.
- **Reinforcement-learned, per-SKU safety-stock policy.** Replacing the static
  `average_daily_demand × lead_time × safety_factor` formula with a policy tuned per product from
  realized stockout/overstock outcomes recorded in `ai_memory`, while keeping the formula-based
  computation as an always-available, fully explainable fallback and audit baseline.
- **Supplier geopolitical/customs risk scoring.** Incorporating Gulf-region-specific customs and
  shipping-lane risk signals (port congestion, customs-clearance delay patterns by origin country) into
  lead-time assumptions, beyond the vendor's own historical `average_lead_time_days`.
- **Opt-in, fully anonymized cross-tenant benchmarking.** A strictly opt-in capability allowing a
  company to see "your dead-stock rate is above the anonymized median for your industry category," with
  the same non-negotiable guarantee already stated in Guardrails: no specific recommendation is ever
  computed from another tenant's raw data, only from aggregated, anonymized, statistically-disclosed
  benchmarks the company explicitly opts into.
- **Native support for consignment and drop-ship reorder logic.** Extending the reorder-point formula to
  correctly handle `warehouse_type = 'consignment'` stock (vendor-owned until consumed, per
  `docs/accounting/INVENTORY.md`'s Multi Warehouse Support section) and drop-ship products that never
  physically enter a company warehouse, both of which need materially different reorder semantics than
  owned, warehoused stock.

# End of Document
