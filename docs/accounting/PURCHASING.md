# Purchasing (Procure-to-Pay) — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Purchasing
Submodule: Procure-to-Pay
---

# Purpose

The Purchasing module is QAYD's procure-to-pay (P2P) engine: the system of record for every commitment a company makes to acquire goods or services from a vendor, from the moment an internal need is raised through the moment the vendor is paid and the transaction is archived. It owns the canonical tables `purchase_requests`, `purchase_request_items`, `rfqs`, `rfq_items`, `rfq_responses`, `purchase_orders`, `purchase_order_items`, `goods_receipts`, `goods_receipt_items`, `quality_inspections`, `bills`, `bill_items`, `debit_notes`, `vendor_payments`, and `procurement_contracts`. It reads from — but does not own — `vendors`, `vendor_contacts`, `vendor_addresses`, `vendor_bank_accounts`, `products`, `product_categories`, `units_of_measure`, `warehouses`, `inventory_items`, `stock_movements`, `accounts`, `tax_codes`, `tax_rates`, and `cost_centers`/`projects`.

Purchasing exists to answer, at any moment, for any company, five questions with certainty: what have we asked to buy, what have we committed to buy, what has physically arrived, what do we owe, and what have we paid. Each of those five questions corresponds to a distinct document type in this module — the Purchase Request, the Purchase Order, the Goods Receipt, the Bill, and the Vendor Payment — and the module's central discipline is keeping those five documents linked, quantity- and amount-consistent, and individually auditable rather than collapsing them into a single "purchase" record the way a spreadsheet or a small-business invoicing tool would.

This document specifies Purchasing as a production-grade module of the QAYD platform: its philosophy, its full lifecycle from request to archive, every document type's data model and business rules, its integration contracts with Accounting, Inventory, Warehouse, Tax, and multi-currency handling, its AI responsibilities, its complete PostgreSQL schema, its REST API, its reporting surface, its permission model, and its operational posture (notifications, security, audit, performance, edge cases). It is written so that a backend engineer can implement every table and endpoint in Laravel 12 against PostgreSQL 16+, a frontend engineer can build the Next.js purchasing screens against the documented API with zero ambiguity, and an AI engineer can wire the FastAPI purchasing agents against the same API surface a human user uses — without any of the three needing to ask a follow-up question.

Purchasing is the mirror image of Sales: where Sales converts a customer's demand into revenue, Purchasing converts the company's own demand into cost, inventory, and payables. The two modules share design DNA — a lifecycle of documents that mature from a soft draft into a hard financial commitment, then into a physical event, then into a payable, then into cash movement — but Purchasing carries additional discipline that Sales does not: a **three-way match** between what was ordered (Purchase Order), what physically arrived (Goods Receipt), and what the vendor billed (Bill). This three-way match, and the tolerance rules that govern it, is the single most important business rule in this module and is referenced throughout this document.

# Vision

The vision for Purchasing is that a Gulf trading, retail, or services company of any size — from a five-person import business in Kuwait to a 500-employee multi-branch group — never loses visibility or control over spend, never pays a vendor for goods it did not receive or a price it did not agree to, and never has its Inventory or Accounts Payable balances drift from reality because a receiving clerk and an accounts clerk worked from different paperwork.

Four pillars carry that vision:

**1. One event, one system, no re-entry.** A purchasing employee raises a Purchase Request once. A purchasing manager turns it into an RFQ and compares vendor quotations once. A Purchase Order is generated from the winning quotation without re-typing a single line item. A warehouse employee records a Goods Receipt against the PO by scanning or selecting lines, not retyping quantities. Accounting posts the Bill against the Goods Receipt automatically once the vendor bill matches within tolerance. Nowhere in this chain does a human re-key a quantity, a unit price, or a product code that the system already has from an earlier step. Every downstream document is generated from, and permanently linked to, its upstream document via foreign keys — never by loose text matching.

**2. Three-way match as a hard control, not a courtesy.** No Bill can be approved for payment unless its quantities and amounts reconcile — within configured tolerance — against the Goods Receipt it claims to bill, and the Goods Receipt in turn reconciles against the Purchase Order it was received against. A mismatch does not silently pass; it is surfaced to a human (Finance or Purchasing Manager, per policy) with the exact variance quantified in currency and quantity. This is the same discipline SAP MM and Oracle Procurement Cloud enforce as their core value proposition, and QAYD implements it as a first-class, non-optional workflow rather than an optional add-on module.

**3. AI that shortens cycles without owning decisions.** From the moment a Purchase Request is raised, AI agents recommend which vendor to route an RFQ to (based on historical price, lead time, and quality performance), auto-populate a price comparison grid the moment quotations arrive, forecast when a recurring purchase will next be needed before anyone asks, and flag a Bill that looks like a duplicate or a price that looks anomalous versus the PO or versus history — but AI never creates a Purchase Order, approves a Bill, or releases a payment on its own. Every AI output in this module is a draft, a ranking, or a flag with a confidence score and a stated reason; a human with the correct permission takes the action.

**4. Every koban (KWD/AED/SAR fils) is traceable to a posted journal line.** A CFO must be able to click from a line on the Trial Balance, into the journal entry that produced it, into the Bill or Payment that generated that journal entry, into the Goods Receipt and Purchase Order behind the Bill, and into the original Purchase Request and the employee who raised it — in five clicks, with no dead ends, in either English or Arabic, in the company's base currency or the original transaction currency.

Success for this module looks like: a Purchasing Manager can close the books for the month knowing that every unposted Bill has an identified reason (pending inspection, pending approval, disputed variance) rather than simply having "not gotten to it," and a CFO never discovers a vendor overpayment or a duplicate bill after the fact — the system caught it before the payment was approved.

# Purchasing Philosophy

QAYD's Purchasing module encodes five non-negotiable principles that govern every design decision in the sections that follow.

**Documents are immutable once they cross a commitment boundary.** A Purchase Request in `draft` can be freely edited. Once submitted for approval, its items are locked (a resubmission after rejection creates a new revision, not a silent edit — see Purchase Requests). A Purchase Order, once approved and sent to the vendor (`status = 'sent'` or later), cannot have its price or quantity edited in place; a change requires a formal PO Amendment (a new PO revision linked to the same `procurement_contract`/original PO) so that the vendor-facing document and the internal audit trail never diverge. A posted Bill's `journal_entry_id` and posted `journal_lines` are permanently immutable; corrections happen via a Debit Note or a reversing journal entry, never an UPDATE on a posted row. This mirrors the platform-wide rule that posted accounting records are immutable and is extended here to the commercial documents that drive them.

**Three-way match is the default, not an exception path.** SAP MM and Oracle Procurement Cloud both default new vendor bill entry to a blocked state until PO, GR, and invoice quantities/amounts agree within tolerance; QAYD adopts the same default. A company can configure tolerance bands (percentage and/or absolute amount, per vendor or globally) but cannot disable three-way match entirely for PO-backed purchases — only truly PO-less bills (utilities, ad-hoc small expenses under a configurable threshold) skip the match, and those are flagged distinctly in reporting as "non-PO spend" precisely because they bypass the control.

**Every state transition is a domain event, not a side effect of a screen.** `PurchaseRequestApproved`, `PurchaseOrderIssued`, `GoodsReceived`, `QualityInspectionCompleted`, `BillMatched`, `BillApproved`, `PaymentReleased`, `PurchaseReturnCreated` are first-class domain events published on the platform event bus. Accounting, Inventory, Warehouse, Notifications, and the AI layer all react to these events; none of those modules poll Purchasing tables directly, and Purchasing never writes to their tables directly. This event-driven boundary is what lets QAYD add a new consumer (e.g., a future Budgeting module) without touching Purchasing's code.

**Money and quantity are reconciled independently.** A three-way match check compares quantity (PO qty vs. GR qty vs. Bill qty) and amount (PO line amount vs. GR-implied amount vs. Bill line amount) as two separate checks, because a vendor can under-deliver on quantity while billing the correct unit price, or bill the correct quantity at a wrong price, and the two failure modes require different resolutions (a partial receipt / backorder vs. a price dispute).

**Approval thresholds scale with financial exposure, not with document type.** A Purchase Order for KWD 50 and a Purchase Order for KWD 50,000 are structurally the same document but must not carry the same approval burden. Every approval workflow in this module (Purchase Request, Purchase Order, Bill, Payment) is amount-tiered and configurable per company, per role, and optionally per vendor risk rating, rather than a fixed single-approver rule (see Approval Workflow).

# Procurement Lifecycle

The procure-to-pay lifecycle is the backbone of this module. A purchase moves through thirteen conceptual stages; not every purchase uses every stage (a low-value, non-PO purchase can skip RFQ/Quotation entirely; a services-only purchase skips Receiving/Inspection), but every stage that is used follows this exact sequence and every document created carries a foreign key back to the document that spawned it.

**Stage 1 — Purchase Request (PR).** An employee or an AI Forecast Agent proposes a need: product/service, quantity, target date, justification, and (optionally) a suggested vendor. The PR is the internal "I need this" record; it commits nothing externally and posts nothing to Accounting.

**Stage 2 — Approval (of the PR).** The PR routes through an approval chain based on its estimated total value and the requester's department/cost center. Approval converts the PR from `pending_approval` to `approved`, unlocking it for sourcing. Rejection returns it to the requester with a reason; the requester may revise and resubmit as a new revision.

**Stage 3 — RFQ (Request for Quotation).** A Purchasing Employee or Purchasing Manager selects one or more approved PRs (optionally merging PR line items from multiple requesters into a single RFQ for buying leverage) and issues an RFQ to a shortlist of vendors, with a response deadline.

**Stage 4 — Quotation Comparison.** Vendors respond (via portal, email-ingested, or manually keyed) with `rfq_responses` carrying price, lead time, payment terms, and validity per line. The system builds a side-by-side comparison grid; the AI Vendor Recommendation agent ranks responses; a human selects the winning vendor (or winners, split across line items).

**Stage 5 — Purchase Order (PO).** The winning quotation is converted into a Purchase Order — the first document in the chain that constitutes a binding commercial commitment. The PO can also be created directly from an approved PR (skipping RFQ) for low-value or single-source purchases, or from a `procurement_contract` release order.

**Stage 6 — Approval (of the PO).** The PO routes through its own amount-tiered approval chain (distinct from the PR's) before it is legally "sent" to the vendor. An approved-but-unsent PO is `approved`; once transmitted it becomes `sent`, then `partially_received` or `fully_received` as goods arrive.

**Stage 7 — Receiving.** The Warehouse Employee records a Goods Receipt (GR) against the PO as items physically arrive at a warehouse (or a services milestone is confirmed for a services PO). Partial receipts are normal and expected; a PO can spawn many GRs.

**Stage 8 — Inspection.** Where the product category or vendor risk profile requires it, a Quality Inspection is performed against the GR before the received quantity is accepted into usable stock. Rejected quantity triggers a Purchase Return; accepted quantity proceeds to Stage 9.

**Stage 9 — Inventory Update.** Accepted quantities post a `stock_movements` entry increasing `inventory_items.quantity_on_hand` in the receiving warehouse/bin, and (for standard/moving-average costing) update the item's weighted-average cost. This step also posts the Goods Receipt's own journal entry (see Accounting Integration) recognizing a GR/IR clearing liability, because the vendor's bill has typically not arrived yet.

**Stage 10 — Vendor Bill.** The vendor's bill (invoice) is entered (manually, OCR/Document-AI extracted, or vendor-portal submitted) and matched against the PO and the GR(s) it claims to bill — the three-way match. A matched, in-tolerance Bill is approved for posting; the GR/IR clearing entry is cleared and a real Accounts Payable liability is recognized.

**Stage 11 — Payment.** Finance schedules and releases a Vendor Payment against one or more approved Bills, following its own sensitive-operation approval chain, debiting Accounts Payable and crediting Bank/Cash.

**Stage 12 — Returns (when needed).** At any point after receiving — most commonly triggered by a failed Quality Inspection, a billing dispute, or a commercial return — a Purchase Return is raised, reversing the inventory and, via a Debit Note, reducing the amount owed to (or reclaiming cash already paid to) the vendor.

**Stage 13 — Archive.** Once a PO's linked GRs are fully received (or formally closed short), its Bills are fully paid, and no open Returns remain, the PO transitions to `closed` and the whole chain becomes a read-only, permanently retained record for audit, reporting, and AI historical analysis. Nothing is ever hard-deleted; "archive" means status-closed and excluded from active operational views, not removed.

```
 PURCHASE  ──approve──►  RFQ  ──quote──►  COMPARE  ──select──►  PURCHASE   ──approve──►  SENT
 REQUEST                (optional)        QUOTATIONS            ORDER                     TO VENDOR
     │                                                              │
     │ (reject → revise)                                            │
     ▼                                                              ▼
  REVISED                                                     GOODS RECEIPT ◄── partial/multiple
     │                                                              │
     └──────────────────────────────────────────────────────────►  ▼
                                                              QUALITY INSPECTION
                                                                pass │  │ fail
                                                                     │  └──► PURCHASE RETURN ──► DEBIT NOTE
                                                                     ▼
                                                          INVENTORY UPDATE (stock + GR/IR clearing)
                                                                     │
                                                                     ▼
                                                              VENDOR BILL ──three-way match──► (variance?)
                                                                     │                              │
                                                              within tolerance                  blocked/
                                                                     │                          disputed
                                                                     ▼                              │
                                                          BILL APPROVED (AP recognized)  ◄───────────┘
                                                                     │
                                                                     ▼
                                                          PAYMENT APPROVAL ──► PAYMENT RELEASED
                                                                     │
                                                                     ▼
                                                                  ARCHIVE
```

Not every purchase traverses every stage. A recurring low-value office-supplies purchase under a company's configured "non-PO threshold" may go directly from an approved PR to a Bill with no PO, RFQ, or GR at all — reported distinctly as `procurement_type = 'non_po'` spend. A services-only PO (e.g., an annual software license) skips Receiving and Inspection entirely and a milestone confirmation on the PO substitutes for a Goods Receipt event. A PO issued under an active `procurement_contract` skips RFQ/Quotation because pricing was already locked at contract signature; it is generated as a **contract release order** referencing `procurement_contracts.id`.

# Purchase Requests

A Purchase Request (PR) is the internal record of intent to buy: "Purchasing Employee X needs 200 units of product Y by date Z for cost center C, because reason R." It commits the company to nothing externally; it exists purely to route a need through internal approval before money or vendor commitments are involved.

**Lifecycle states.** `draft` → `pending_approval` → `approved` | `rejected`. An `approved` PR moves to `sourcing` once an RFQ or PO references it, and to `fulfilled` once every line item is covered by an issued PO, or to `cancelled` at any point before `fulfilled` by a user with `purchasing.request.cancel`. A `rejected` PR can be revised by the original requester, which creates a new `revision_number` on the same PR row (not a new PR id) so the full negotiation history stays attached to one logical request.

**Header fields.** `request_number` (company-scoped sequence, format `PR-{fiscal_year}-{seq}`, e.g. `PR-2026-000482`), `requested_by` (user), `department_id`, `cost_center_id`, `project_id` (nullable), `branch_id`, `request_date`, `required_by_date`, `priority` (`low`|`normal`|`high`|`urgent`), `justification` (text, required for `high`/`urgent`), `preferred_vendor_id` (nullable — a suggestion, not a commitment), `estimated_total_amount` (computed from items, base currency), `status`, `revision_number`, `rejection_reason` (nullable), `source` (`manual`|`ai_forecast`|`reorder_point`|`contract_renewal`), plus the standard columns.

**Line items (`purchase_request_items`).** Each line carries `product_id` (nullable for a free-text service line — see below), `description`, `quantity_requested`, `unit_of_measure_id`, `estimated_unit_price` (a planning estimate, not a commitment — real pricing is fixed at PO stage), `warehouse_id` (destination), `required_by_date` (can override the header date per line), `notes`, and `status` (`open`|`partially_sourced`|`sourced`|`cancelled`) tracking how much of that line has been covered by downstream POs. A PR line without a `product_id` is a services or non-stock line; it still flows through the same approval and RFQ machinery but never touches Inventory.

**Business rules.**
- BR-PR-1: A PR cannot be submitted for approval with zero line items, or with any line item having `quantity_requested <= 0`.
- BR-PR-2: `estimated_total_amount` is server-computed as `SUM(quantity_requested * estimated_unit_price)` across items; it is never client-supplied, to prevent an approval routing being gamed by understating the header total.
- BR-PR-3: Once a PR is `pending_approval`, its items are read-only; the requester must withdraw the request back to `draft` (only allowed while no approval step has yet acted) to edit, or wait for a decision and revise.
- BR-PR-4: A PR line's `status` becomes `sourced` only when the cumulative `quantity` across all `purchase_order_items` referencing this PR line (via `purchase_order_items.source_pr_item_id`) meets or exceeds `quantity_requested`; it becomes `partially_sourced` for any lesser positive coverage.
- BR-PR-5: A PR can be cancelled by its requester or a Purchasing Manager at any point before any line is `sourced`; once any line is `sourced`, cancellation requires cancelling or completing the downstream PO(s) first.
- BR-PR-6: Urgent-priority PRs bypass the normal SLA-based escalation timer in Notifications (see Notifications) and page the first approver in the chain immediately rather than on the standard digest cadence.

**AI touch point.** The Demand Forecasting and Purchase Prediction agents (see AI Responsibilities) can auto-generate a PR with `source = 'ai_forecast'` or `source = 'reorder_point'` when a product's `inventory_items.quantity_on_hand` crosses its reorder point, or when historical consumption predicts a stockout before the next expected delivery. An AI-generated PR is created in `draft` status and always requires the same human approval as a manually raised PR — the AI never self-approves its own request.

# Purchase Orders

The Purchase Order (PO) is the first legally binding document in the chain: it represents the company's commitment to buy specific quantities of specific products/services from a specific vendor at a specific price, under specific terms.

**Lifecycle states.** `draft` → `pending_approval` → `approved` → `sent` → `partially_received` → `fully_received` → `closed`, with `rejected` and `cancelled` as terminal off-ramps from `pending_approval`/`approved` (a `sent` PO can only be cancelled with vendor acknowledgment recorded, since the vendor has already been notified). A `closed` PO is one whose receiving, billing, and payment activity has fully concluded; it remains queryable forever but drops out of "open orders" operational views.

**Header fields.** `po_number` (`PO-{fiscal_year}-{seq}`), `vendor_id`, `vendor_contact_id`, `procurement_contract_id` (nullable — set when issued as a contract release order), `rfq_id` (nullable — set when generated from a won quotation), `branch_id`, `warehouse_id` (default delivery destination; line items may override), `order_date`, `expected_delivery_date`, `payment_terms` (e.g., `net_30`, `net_60`, `cod`, `advance_50`), `currency_code`, `exchange_rate`, `incoterm` (nullable, e.g., `FOB`, `CIF`, `DDP` — relevant for import-heavy Gulf trading companies), `shipping_address`, `billing_address`, `subtotal_amount`, `discount_amount`, `tax_amount`, `total_amount` (all in transaction currency, with base-currency equivalents computed at posting per the Multi Currency section), `status`, `approval_status`, `sent_at`, `sent_via` (`email`|`portal`|`manual`), `notes`, `internal_notes` (never shown to the vendor), plus standard columns.

**Line items (`purchase_order_items`).** `product_id` (nullable for services), `source_pr_item_id` (nullable FK to `purchase_request_items`), `source_rfq_item_id` (nullable FK to `rfq_items`), `description`, `quantity_ordered`, `unit_of_measure_id`, `unit_price`, `discount_percent`, `tax_code_id`, `line_amount` (computed), `warehouse_id`, `cost_center_id`, `project_id`, `expected_delivery_date` (line-level override), `quantity_received` (denormalized running total, maintained by Goods Receipt posting), `quantity_billed` (denormalized running total, maintained by Bill posting), `quantity_returned` (denormalized running total, maintained by Purchase Return posting), `line_status` (`open`|`partially_received`|`fully_received`|`closed`|`cancelled`).

**Business rules.**
- BR-PO-1: `total_amount` must equal `subtotal_amount - discount_amount + tax_amount`, recomputed server-side on every save; a client-supplied mismatch is rejected with `422`.
- BR-PO-2: A PO line's `unit_price` must fall within ±X% of the corresponding `rfq_responses` line price if `source_rfq_item_id` is set (default tolerance 2%, configurable); a larger deviation requires a `purchasing.order.override_price` permission and is logged.
- BR-PO-3: A `sent` PO's line items are immutable for `unit_price`, `quantity_ordered`, and `tax_code_id`. Any change requires a **PO Amendment**: a new `purchase_orders` row with `amends_po_id` pointing to the original and `amendment_number` incremented; the original PO is marked `superseded_by` the amendment but both remain permanently queryable.
- BR-PO-4: A PO cannot be `sent` while `approval_status != 'approved'`.
- BR-PO-5: `quantity_received` on a line can never exceed `quantity_ordered * (1 + over_receipt_tolerance_percent)` (default tolerance 0%, i.e., no over-receipt, configurable per company up to a hard platform ceiling of 10%); an attempted receipt beyond tolerance is rejected at the Goods Receipt step, not silently capped.
- BR-PO-6: A PO's `line_status` becomes `fully_received` when `quantity_received >= quantity_ordered`; the header `status` becomes `fully_received` only when every non-cancelled line is `fully_received`.
- BR-PO-7: A PO cannot transition to `closed` while any linked `bills` are unpaid (`bills.status != 'paid'`) unless a user with `purchasing.order.force_close` overrides and records a reason (e.g., a partially delivered PO the company has decided to abandon, with the vendor bill separately disputed).
- BR-PO-8: Currency and vendor cannot be changed once a PO has any linked Goods Receipt or Bill.

**PO types.** `standard` (single delivery/billing expectation), `blanket` (an umbrella commitment against a `procurement_contract` drawn down via multiple GRs/Bills over its validity period, common for raw-material or MRO supply agreements), `services` (no physical receipt; milestone- or period-based confirmation substitutes for a GR), `drop_ship` (goods ship directly to a customer or a third site rather than a company warehouse — `warehouse_id` is null and `drop_ship_address` is required).

# Vendor Quotations

The RFQ/Quotation stage exists to create documented price competition before a PO commits company funds, and to give the AI Vendor Recommendation and Price Comparison agents structured data to reason over.

**RFQ (`rfqs`) lifecycle.** `draft` → `issued` → `responses_open` → `closed` → `awarded` | `cancelled`. `header fields`: `rfq_number` (`RFQ-{fiscal_year}-{seq}`), `title`, `issued_by`, `issue_date`, `response_deadline`, `status`, `source_pr_ids` (JSONB array of `purchase_request_items.purchase_request_id` merged into this RFQ — an RFQ frequently consolidates line items from several PRs to negotiate better vendor pricing at volume), `invited_vendor_ids` (JSONB array), `terms_and_conditions` (text), `awarded_vendor_id` (nullable, set at close), `awarded_po_id` (nullable, set once the winning quotation converts to a PO).

**RFQ line items (`rfq_items`).** `product_id`, `description`, `quantity_requested`, `unit_of_measure_id`, `target_delivery_date`, `source_pr_item_id` (nullable FK, links back to the PR line this RFQ line was sourced from, for traceability).

**Vendor responses (`rfq_responses`).** One row per vendor per RFQ (header), with child rows implicitly represented per RFQ line via a `rfq_response_items` structure embedded as JSONB `line_quotes` (each entry: `rfq_item_id`, `unit_price`, `quantity_offered`, `lead_time_days`, `moq` (minimum order quantity), `notes`) for the common case, or normalized into a dedicated `rfq_response_items` table when a company's line volume makes JSONB querying impractical (both are supported; the default schema in this document uses a normalized `rfq_response_items`-equivalent captured directly in `rfq_responses` as JSONB for simplicity of a single-table quotation record per vendor — engineering teams handling very high RFQ line counts, e.g. 200+ lines per RFQ, should normalize this to a child table using the same columns). Header-level fields on `rfq_responses`: `vendor_id`, `submitted_at`, `valid_until`, `payment_terms_offered`, `total_quoted_amount`, `currency_code`, `status` (`received`|`under_review`|`shortlisted`|`awarded`|`rejected`), `rejection_reason`, `submission_channel` (`portal`|`email_ingested`|`manual`), `attachment_ids` (polymorphic via `attachments`).

**Comparison and award.** The comparison grid is a read view, not a stored table: for a given `rfq_id`, join every `rfq_responses` row against `rfq_items` to produce a matrix of vendor × line with unit price, total price, lead time, and an AI-computed composite score (see AI Responsibilities → Vendor Recommendation). Award is a single action (`POST /rfqs/{id}/award`) that can split lines across multiple winning vendors (e.g., vendor A wins lines 1–3, vendor B wins line 4) — each awarded vendor/line-set becomes its own Purchase Order, all linked back to the same `rfq_id`.

**Business rules.**
- BR-RFQ-1: An RFQ cannot be closed with zero responses; it can be cancelled instead, with a reason (e.g., need evaporated, budget cut).
- BR-RFQ-2: A vendor response submitted after `response_deadline` is accepted but flagged `late_submission = true`; it is included in the comparison but visually and in the API marked as late, since Purchasing Manager judgment may still want to consider a strong late bid.
- BR-RFQ-3: Awarding a response locks in `rfq_responses.status = 'awarded'` for the winner and `'rejected'` for all other responses to that RFQ (or the subset of lines, if split-awarded), and each rejected vendor is notified automatically.
- BR-RFQ-4: `unit_price` values entered on `rfq_responses` become the tolerance baseline referenced by BR-PO-2 when the response converts to a PO.

# Receiving Goods

The Goods Receipt (GR) is the record of a physical event: specific quantities of specific products actually arrived at a specific warehouse (or a specific services milestone was confirmed), regardless of what was ordered or what will eventually be billed. It is the pivot point of the three-way match and the trigger for the Inventory Update stage.

**Lifecycle states.** `draft` (being keyed by the receiving clerk, e.g., mid-count at the dock) → `posted` (quantities confirmed, `stock_movements` created, GR/IR clearing journal posted) → `reversed` (a posted GR can be reversed only before any downstream Bill or Quality Inspection references it, via a compensating negative GR, never by deleting the original).

**Header fields (`goods_receipts`).** `receipt_number` (`GR-{fiscal_year}-{seq}`), `purchase_order_id`, `warehouse_id`, `received_by` (user), `receipt_date`, `vendor_delivery_note_number` (the vendor's own packing-slip/delivery-note reference, important for Gulf customs and warehouse reconciliation), `carrier` (nullable), `status`, `requires_inspection` (computed from the product category / vendor risk flags at receipt time — see Quality Inspection), `notes`, plus standard columns.

**Line items (`goods_receipt_items`).** `purchase_order_item_id`, `product_id`, `quantity_ordered` (denormalized copy for display/audit even after the PO line later amends), `quantity_received`, `quantity_accepted` (defaults equal to `quantity_received`; reduced by a failed Quality Inspection), `quantity_rejected` (computed as `quantity_received - quantity_accepted`), `unit_of_measure_id`, `warehouse_id`, `bin_location_id` (nullable FK into `warehouse_bins`), `batch_number` (nullable, for `product_batches`-tracked items), `serial_numbers` (nullable JSONB array, for `product_serials`-tracked items), `expiry_date` (nullable), `condition` (`good`|`damaged`|`partial_damage`), `notes`.

**Business rules.**
- BR-GR-1: `quantity_received` on a line cannot cause the parent PO line's cumulative `quantity_received` to exceed `quantity_ordered * (1 + over_receipt_tolerance_percent)` (BR-PO-5); an attempted excess receipt is rejected with a `409` and the specific tolerance breached in the error payload.
- BR-GR-2: A GR line for a batch- or serial-tracked product cannot post without `batch_number` or the exact count of `serial_numbers` matching `quantity_received`.
- BR-GR-3: Posting a GR is atomic with (a) creating one `stock_movements` row per line of type `purchase_receipt` increasing `inventory_items.quantity_on_hand`, (b) updating the item's moving-average cost if the company uses `moving_average` costing (see Inventory Integration), and (c) posting the GR/IR clearing journal entry (see Accounting Integration) — all three happen in a single database transaction; a failure in any part rolls back the whole GR.
- BR-GR-4: A GR whose `requires_inspection = true` posts stock into a **quarantine** sub-location (`inventory_items` tracked with a `hold_status = 'pending_inspection'`) rather than directly into unrestricted, sellable/usable stock; the Quality Inspection step releases or rejects the hold (see Quality Inspection).
- BR-GR-5: A `services` PO's "receipt" is a milestone confirmation rather than a quantity — modeled as a GR with `product_id = NULL` on its line(s), `quantity_received = 1` representing "milestone met," and no stock movement generated; the GR/IR clearing entry still posts normally against the service expense/GR-IR account.
- BR-GR-6: Reversing a posted GR requires zero downstream references (no Quality Inspection, no Bill line billed against it); if references exist, the correction must flow through a Purchase Return instead.

# Quality Inspection

Quality Inspection is the controlled gate between "physically arrived" and "usable/sellable stock," used for products or vendors flagged as requiring verification before acceptance — a standard SAP MM ("QM in procurement") and Oracle Procurement Cloud ("Inspection") capability.

**When inspection is required.** `requires_inspection` is true on a GR when any of: the `product_categories.requires_inspection` flag is set for any received line's product category; the `vendors.risk_rating` is `high` or the vendor is newly onboarded (fewer than N prior completed POs, configurable, default N=3); the `procurement_contracts` terms for this PO explicitly mandate inspection; or a Purchasing Manager manually flags the GR for inspection at receipt time regardless of the above.

**Lifecycle states (`quality_inspections`).** `pending` → `in_progress` → `passed` | `failed` | `partially_passed`.

**Fields.** `inspection_number` (`QI-{fiscal_year}-{seq}`), `goods_receipt_id`, `goods_receipt_item_id`, `inspector_id` (user), `inspection_date`, `inspection_criteria` (JSONB — a checklist snapshot: e.g., `[{"criterion":"visual_damage","result":"pass"},{"criterion":"spec_conformance","result":"fail","note":"tolerance exceeded on diameter"}]`), `quantity_inspected`, `quantity_passed`, `quantity_failed`, `disposition` (`accept`|`reject`|`accept_with_deviation`|`return_to_vendor`), `deviation_approval_by` (nullable user — required when `disposition = 'accept_with_deviation'`, since accepting known-defective goods is itself a decision requiring authority), `notes`, `attachment_ids` (photos of damage/defects, polymorphic `attachments`), plus standard columns.

**Business rules.**
- BR-QI-1: `quantity_passed + quantity_failed = quantity_inspected`, and `quantity_inspected <= goods_receipt_items.quantity_received`; partial inspection (sampling) is allowed, in which case the un-sampled remainder inherits the sampled pass/fail ratio only if the company's inspection policy explicitly allows extrapolation (`inspection_policies.allow_extrapolation`) — otherwise the un-sampled remainder stays in `pending_inspection` hold.
- BR-QI-2: A `passed` (or `accept_with_deviation`) inspection releases the corresponding quantity from `hold_status = 'pending_inspection'` to unrestricted stock via a `stock_movements` row of type `quality_release`; this does not change total `quantity_on_hand` (the stock was already received) but does change its usable/available classification.
- BR-QI-3: A `failed` (fully or partially) inspection with `disposition = 'reject'` or `'return_to_vendor'` automatically drafts a Purchase Return for the failed quantity, pre-filled from the inspection record, requiring only Purchasing review before submission to the vendor.
- BR-QI-4: `accept_with_deviation` requires `deviation_approval_by` to hold `purchasing.inspection.approve_deviation`; the disposition and approver are permanently recorded on the GR line for future vendor-performance scoring (a vendor whose goods are frequently accepted "with deviation" scores worse in Supplier Performance analytics even though nothing was formally rejected).
- BR-QI-5: Inspection results feed directly into the AI Supplier Performance agent's quality-defect-rate metric (see AI Responsibilities) and into `vendors.quality_score`.

# Purchase Returns

A Purchase Return reverses previously received (and possibly already invoiced or paid) goods back to the vendor — triggered by a failed inspection, a commercial return, wrong-item delivery, or a post-receipt damage discovery. Returns are modeled through a **Debit Note**, which is the financial instrument (this module's mirror of a Sales Credit Note), while the physical/stock reversal is modeled as a distinct stock movement referencing the same event.

**Lifecycle states.** `draft` → `pending_approval` → `approved` → `sent_to_vendor` → `acknowledged` → `completed` | `rejected_by_vendor`.

**Return header fields** (modeled on `debit_notes`, which double as the Purchase Return document — see Database Design for full DDL; there is deliberately no separate `purchase_returns` table because a return is, financially, always a debit note against the vendor, and modeling it as a second parallel table would create two sources of truth for the same monetary claim). `debit_note_number` (`DN-{fiscal_year}-{seq}`), `vendor_id`, `bill_id` (nullable — set when the return relates to an already-posted bill), `goods_receipt_id` (nullable — set when the return originates from physical receiving/inspection rather than a billing dispute), `reason` (`quality_reject`|`wrong_item`|`damaged`|`overbilled`|`price_dispute`|`commercial_return`|`other`), `reason_detail` (text), `status`, `subtotal_amount`, `tax_amount`, `total_amount`, `currency_code`, `exchange_rate`, `applied_amount` (how much has been offset against future bills or refunded in cash), `remaining_amount` (computed `total_amount - applied_amount`), plus standard columns.

**Line items (`debit_note_items`, embedded in the Database Design section).** `product_id`, `bill_item_id` (nullable), `goods_receipt_item_id` (nullable), `quantity`, `unit_price`, `tax_code_id`, `line_amount`.

**Business rules.**
- BR-RET-1: A quality-triggered return (from BR-QI-3) pre-fills `goods_receipt_id`/`goods_receipt_item_id` and quantity from the inspection record; a billing-triggered return pre-fills `bill_id`/`bill_item_id` instead. A return can reference both when goods were both received and already billed (the common case for a return discovered after the vendor invoiced).
- BR-RET-2: Posting an approved return with a `goods_receipt_id` creates a `stock_movements` row of type `purchase_return` decreasing `inventory_items.quantity_on_hand` (or decreasing the `pending_inspection` hold quantity if the goods never left quarantine) in the same transaction as posting the debit note's accounting entry (see Accounting Integration).
- BR-RET-3: `remaining_amount` on an approved, posted debit note can be resolved two ways: (a) **applied** against a future Bill from the same vendor at Bill-approval time (reducing the amount payable), or (b) **refunded** as cash, recorded via a `vendor_payments` row with `payment_type = 'refund_received'` and a negative-direction posting (debit Bank, credit the same GR/IR or AP account the debit note hit). The API must expose both paths; neither is automatic.
- BR-RET-4: A return cannot exceed, in quantity or amount, the quantity/amount of the GR or Bill line(s) it references.
- BR-RET-5: `sent_to_vendor` requires `purchasing.return.send` permission distinct from `purchasing.return.create`, since transmitting a formal return/debit-note claim to a vendor is a step with external, reputational, and legal weight beyond internal drafting.

# Vendor Bills

The Bill (invoice from the vendor) is where the company's obligation to pay becomes a recognized Accounts Payable liability. This module's Bills are the purchasing mirror of Sales `invoices`, sharing the same platform-level convention: sales invoices live in `invoices`, purchase invoices live in `bills`.

**Lifecycle states.** `draft` → `pending_match` → `matched` | `variance_flagged` → `pending_approval` → `approved` → `posted` → `paid` | `partially_paid`, with `disputed` and `cancelled` as off-ramps from `variance_flagged`/`pending_approval`. `void` is reachable only from `draft` (an approved/posted Bill is never voided — it is reversed via a Debit Note or a reversing journal entry, preserving the audit trail).

**Header fields (`bills`).** `bill_number` (internal sequence `BILL-{fiscal_year}-{seq}`), `vendor_bill_reference` (the vendor's own invoice number — critical for Duplicate Bill Detection, see AI Responsibilities), `vendor_id`, `purchase_order_id` (nullable — null for non-PO bills), `bill_date`, `due_date` (computed from `payment_terms` unless overridden), `currency_code`, `exchange_rate`, `subtotal_amount`, `discount_amount`, `tax_amount`, `total_amount`, `amount_paid` (running total, maintained by Payment posting), `amount_due` (computed `total_amount - amount_paid`), `status`, `match_status` (`not_matched`|`matched`|`variance`|`override_approved`), `match_variance_amount` (signed, in base currency), `match_variance_percent`, `payment_terms`, `procurement_type` (`po`|`non_po`|`contract_release`), `journal_entry_id` (nullable until posted), `attachment_ids` (the scanned/OCR-sourced vendor invoice document), plus standard columns.

**Line items (`bill_items`).** `goods_receipt_item_id` (nullable — the specific GR line this Bill line claims to bill; required for PO-backed bills once matched), `purchase_order_item_id` (nullable, denormalized for direct querying), `product_id`, `description`, `quantity_billed`, `unit_of_measure_id`, `unit_price`, `discount_percent`, `tax_code_id`, `line_amount`, `cost_center_id`, `project_id`, `expense_account_id` (nullable — required for non-PO/non-inventory lines, since there is no Inventory/GR-IR account to hit; see Accounting Integration).

**Three-way match algorithm.** On submission for matching, the system evaluates, per Bill line matched to a GR line:
1. *Quantity check:* `bill_items.quantity_billed` vs. `goods_receipt_items.quantity_accepted` (not `quantity_received` — a rejected quantity from a failed inspection must not be billable). Variance beyond `qty_tolerance_percent` (default 0%, i.e., exact match required, configurable up to 5%) flags `quantity_variance`.
2. *Price check:* `bill_items.unit_price` vs. `purchase_order_items.unit_price`. Variance beyond `price_tolerance_percent` (default 2%) or beyond `price_tolerance_absolute` (default company base-currency equivalent of KWD 5.000, whichever is looser) flags `price_variance`.
3. *Tax check:* `bill_items.tax_code_id` must match the `tax_code_id` implied by the PO line and the vendor's tax registration status; a mismatch flags `tax_variance` and blocks posting until Finance resolves it (tax miscoding is a compliance risk, not merely a commercial one).
Any flag sets `bills.match_status = 'variance'` and blocks the `approved`→`posted` transition until a user with `purchasing.bill.override_variance` resolves it (accepts the variance with a reason, sends the line back to the vendor as a dispute, or corrects the Bill line to match).

**Business rules.**
- BR-BILL-1: A PO-backed Bill line cannot post against a `goods_receipt_item_id` whose cumulative billed quantity (across all bills) would exceed that GR item's `quantity_accepted`.
- BR-BILL-2: `total_amount` is server-recomputed from lines on every save, identically to BR-PO-1.
- BR-BILL-3: A non-PO Bill (`purchase_order_id IS NULL`) skips three-way match entirely but requires every line to carry an `expense_account_id`, and is capped at a company-configured `non_po_bill_max_amount` (default KWD 500 base-currency equivalent) above which it is rejected at submission and the user is directed to raise a Purchase Request instead.
- BR-BILL-4: `vendor_bill_reference` + `vendor_id` must be unique among non-cancelled, non-void bills for the company (the primary structural defense against duplicate billing, reinforced by the AI Duplicate Bill Detection agent's fuzzy/semantic layer — see AI Responsibilities).
- BR-BILL-5: Posting a Bill (transition to `posted`) is atomic with creating the Bill's journal entry (see Accounting Integration) and clearing the corresponding GR/IR clearing balance for the matched GR lines.
- BR-BILL-6: A Bill's `due_date` cannot be edited after `posted` without a corresponding audit-logged reason (vendors occasionally grant informal extensions; the system allows recording this but never silently).

# Payments

Vendor Payments settle approved, posted Bills. Payments are always a **sensitive operation**: release requires its own approval chain distinct from Bill approval, per platform convention (bank transfers/payment release "always require a human approval chain, never AI-only").

**Lifecycle states (`vendor_payments`).** `draft` → `pending_approval` → `approved` → `processing` → `completed` | `failed`, with `cancelled` reachable from `draft`/`pending_approval` only (an `approved` payment already committed to a bank rail requires a `failed` outcome plus a manual reconciliation step, not a silent cancel).

**Header fields.** `payment_number` (`VP-{fiscal_year}-{seq}`), `vendor_id`, `payment_date`, `payment_method` (`bank_transfer`|`cheque`|`cash`|`card`|`knet`), `bank_account_id` (the company's paying bank account, FK `bank_accounts`), `vendor_bank_account_id` (FK `vendor_bank_accounts`), `currency_code`, `exchange_rate`, `total_amount`, `payment_type` (`bill_payment`|`advance_payment`|`refund_received`), `reference_number` (bank transaction / cheque number), `status`, `approved_by`, `approved_at`, `journal_entry_id`, `notes`, plus standard columns.

**Allocations (`vendor_payment_allocations`, embedded — see Database Design).** A single payment can settle multiple Bills (a vendor statement-run payment), and a single Bill can be settled across multiple payments (partial payments). Each allocation row: `vendor_payment_id`, `bill_id`, `allocated_amount`, `discount_taken` (early-payment discount captured at settlement, if the vendor's `payment_terms` include one, e.g. `2/10 net 30`).

**Business rules.**
- BR-PAY-1: `SUM(vendor_payment_allocations.allocated_amount) = vendor_payments.total_amount`, enforced server-side; the allocation grid is the only place a payment's amount is composed.
- BR-PAY-2: An allocation's `allocated_amount` (plus `discount_taken`) cannot exceed the target Bill's `amount_due` at allocation time; posting an allocation atomically decrements `bills.amount_due` and increments `bills.amount_paid`, transitioning the Bill to `paid` once `amount_due` reaches zero (within a rounding tolerance of the base currency's minor unit) or `partially_paid` otherwise.
- BR-PAY-3: An `advance_payment` (a deposit paid before any Bill exists, common for import purchases in the Gulf requiring prepayment) posts against a dedicated "Vendor Advances" asset-type control account rather than directly clearing AP; when a subsequent Bill arrives from the same vendor, it can be allocated against the outstanding advance, converting the advance into a normal AP settlement.
- BR-PAY-4: Release of a payment (transition `approved`→`processing`) requires `purchasing.payment.release`, which by platform policy is never granted to an AI agent role and requires a two-person rule (maker ≠ checker) above a configurable threshold — see Approval Workflow and Permissions.
- BR-PAY-5: A `failed` payment (bank rejection, invalid account, etc.) automatically reverses its allocations (Bills return to their prior `amount_due`) and reopens for correction and resubmission; it never silently retries.
- BR-PAY-6: Early-payment `discount_taken` posts to a dedicated "Purchase Discounts Received" income-type account (or as a contra-expense, per the company's CoA convention — see Accounting Integration) rather than being netted invisibly into the Bill or Bank line.

# Procurement Contracts

A Procurement Contract is a standing commercial agreement with a vendor that fixes pricing, terms, and volume commitments over a validity period, so that individual purchases against it (contract release orders) skip re-negotiation and, per the Purchasing Philosophy, skip RFQ/Quotation entirely.

**Lifecycle states (`procurement_contracts`).** `draft` → `pending_approval` → `active` → `expiring_soon` (system-computed, not a stored transition, surfaced 30/60/90 days before `end_date` per company config) → `expired` | `terminated` | `renewed` (a renewal creates a new contract row with `renewed_from_contract_id` pointing to the prior one, preserving full history rather than extending the same row indefinitely).

**Fields.** `contract_number` (`PC-{fiscal_year}-{seq}`), `vendor_id`, `title`, `contract_type` (`blanket_po`|`fixed_price_agreement`|`framework_agreement`|`service_level_agreement`|`consignment`), `start_date`, `end_date`, `auto_renew` (boolean), `renewal_notice_days`, `currency_code`, `total_committed_value` (nullable — the ceiling for a value-committed blanket contract; null for a pure price-agreement with no volume commitment), `total_released_value` (running total drawn down via release POs), `remaining_value` (computed), `payment_terms`, `price_list_id` (nullable FK `price_lists` — the contract's locked pricing, reusing the platform's existing pricing infrastructure rather than duplicating price fields per contract line), `minimum_order_value` (nullable — some vendor agreements require a minimum draw per release order), `sla_terms` (JSONB — e.g., `{"max_lead_time_days":5,"on_time_delivery_target_pct":95,"quality_defect_rate_max_pct":1}`, consumed by the AI Supplier Performance agent to score actual vs. contracted SLA), `renewed_from_contract_id` (nullable), `terminated_reason` (nullable), `status`, `attachment_ids` (the signed contract document/PDF), plus standard columns.

**Contract line items (`procurement_contract_items`, embedded — see Database Design).** `product_id`, `contracted_unit_price`, `unit_of_measure_id`, `minimum_commitment_quantity` (nullable, per-line volume commitment), `quantity_released_to_date` (running total), `price_valid_from`, `price_valid_to` (supports scheduled price steps within a single contract's life, e.g., a 3-year raw-material agreement with an agreed 2%/year escalation).

**Business rules.**
- BR-PC-1: A `contract_release_order` type Purchase Order (Purchase Orders section) must set `procurement_contract_id` and, for each line whose `product_id` matches a `procurement_contract_items` row, must use `contracted_unit_price` unless a user with `purchasing.contract.override_price` explicitly overrides — logged with a reason, since deviating from a signed contract price is itself noteworthy.
- BR-PC-2: A release PO against a value-committed contract is rejected at submission if it would push `total_released_value` beyond `total_committed_value`, unless the requester holds `purchasing.contract.exceed_commitment`.
- BR-PC-3: `remaining_value` and `quantity_released_to_date` are maintained by a database trigger/service-layer hook on PO approval (not on PO creation, since a draft/rejected release PO must not consume contract capacity) and are decremented back on PO cancellation.
- BR-PC-4: A contract within `renewal_notice_days` of `end_date` and with `auto_renew = false` generates a Notification to the contract owner and the Purchasing Manager role, and feeds the AI Vendor Recommendation agent a "renewal decision due" item; auto-renewal, where `auto_renew = true`, still requires a human confirmation click before the renewed contract row is created — it is never silently regenerated.
- BR-PC-5: Contract SLA actuals (on-time delivery %, defect rate) are computed nightly from the GRs and Quality Inspections posted against release POs under that contract, and compared to `sla_terms`; a breach beyond a configured threshold triggers a Notification but never automatically terminates or price-adjusts the contract — that remains a human commercial decision.

# Approval Workflow

Every document type in this module — Purchase Request, Purchase Order, Bill, Vendor Payment, Debit Note/Return, Procurement Contract — routes through an approval chain built on the same underlying engine, configured per company via `approval_policies` and `approval_steps` (foundation tables shared across QAYD modules; Purchasing consumes them rather than owning a parallel workflow engine).

**Policy shape.** An `approval_policies` row targets a `document_type` (`purchase_request`|`purchase_order`|`bill`|`vendor_payment`|`debit_note`|`procurement_contract`) and defines an ordered list of `approval_steps`, each with: `step_order`, `approver_type` (`specific_user`|`role`|`manager_of_requester`|`department_head`|`dynamic_by_amount`), `approver_reference` (a user id or role name, depending on `approver_type`), `min_amount` / `max_amount` (the tier this step applies to, in base currency), `requires_all` (boolean — true means every user matching `approver_type` in this step must approve; false means any one is sufficient), and `sla_hours` (time before escalation, see Notifications).

**Default tiers (illustrative company defaults, fully configurable).**

| Document | Tier (base-currency equivalent) | Approver |
|---|---|---|
| Purchase Request | ≤ 250 | Department Head |
| Purchase Request | 250 – 2,500 | Department Head → Purchasing Manager |
| Purchase Request | > 2,500 | Department Head → Purchasing Manager → Finance Manager |
| Purchase Order | ≤ 500 | Purchasing Manager |
| Purchase Order | 500 – 10,000 | Purchasing Manager → Finance Manager |
| Purchase Order | > 10,000 | Purchasing Manager → Finance Manager → CFO |
| Bill (variance override only; in-tolerance bills auto-approve) | any | Finance Manager |
| Bill (non-PO) | > non_po_bill_max_amount config | Finance Manager → CFO |
| Vendor Payment | ≤ 1,000 | Finance Manager |
| Vendor Payment | 1,000 – 25,000 | Finance Manager → CFO |
| Vendor Payment | > 25,000 | Finance Manager → CFO → Owner |
| Procurement Contract | any | Purchasing Manager → CFO |

**Two-person rule for Payments.** Per platform policy, sensitive operations "always require a human approval chain, never AI-only," and QAYD additionally enforces maker-checker on Vendor Payments above `payment_two_person_threshold` (default company base-currency equivalent of KWD 500): the user who created/scheduled the payment (`draft`→`pending_approval`) can never be the same user who executes the final `approved`→`processing` release, enforced at the API layer (`403` with `code=maker_checker_violation` if the same `user_id` attempts both actions on the same payment above the threshold).

**Approval mechanics.**
- An approval step advances a document from `pending_approval` toward `approved` only when every configured step for the matched tier has recorded an `approve` action; any single `reject` at any step immediately sets the whole document to `rejected` and halts remaining steps.
- Each approval action is recorded in an `approval_actions` table (foundation-shared): `document_type`, `document_id`, `step_order`, `actor_id`, `action` (`approve`|`reject`|`request_changes`|`delegate`), `comment`, `acted_at` — this is the primary source both for the Approval Workflow UI and for audit.
- `request_changes` returns the document to its originator without a full rejection, preserving the approval progress already recorded on prior steps once resubmitted (configurable per policy — some companies require a full restart of approval after any edit; QAYD supports both `restart_on_edit = true|false` per policy).
- `delegate` lets an approver temporarily reassign their pending approvals to a named delegate for a date range (e.g., annual leave), fully logged, and automatically expiring back to the original approver at the delegate window's end.
- AI-proposed documents (an AI-drafted PR, or an AI-flagged Bill variance override suggestion) enter the identical approval chain as a human-created document of the same type and amount — the AI origin is recorded (`source = 'ai_forecast'`, etc.) but confers zero special approval treatment, per the platform rule that AI obeys the same permissions as a user and never bypasses authorization.

# Vendor Integration

Purchasing is the heaviest consumer of the `vendors` domain and, in turn, the primary contributor to a vendor's computed reputation and financial position.

**Read dependencies.** Every Purchase Order, Bill, and Vendor Payment resolves `vendor_id` against `vendors` (must be `status = 'active'`; a `blocked`/`suspended` vendor cannot receive a new PO or Bill, enforced at creation, though existing open documents against a since-blocked vendor remain fully processable so in-flight commitments are not stranded). `vendor_contacts` supplies the RFQ/PO recipient email and phone. `vendor_addresses` supplies the shipping/remit-to address defaulted onto POs and Payments. `vendor_bank_accounts` supplies the payout destination validated against `vendor_payments.vendor_bank_account_id`. `vendor_contracts` (a vendor-side generic contracts table, distinct from this module's `procurement_contracts`, which is the Purchasing-specific commercial agreement) and `vendor_certificates` (e.g., ISO certifications, food-safety certificates for F&B suppliers) inform eligibility checks — a PO for a food-category product can be policy-blocked if the vendor's relevant `vendor_certificates` row is expired, surfaced as a hard validation error, not merely a warning.

**Write-back / computed fields.** Purchasing is the primary writer (via scheduled aggregation jobs, never inline on the hot path of posting a document) of several `vendors` fields that other modules read: `vendors.total_spend_ytd` and `total_spend_lifetime` (summed from posted `bills`), `vendors.average_lead_time_days` (computed from `goods_receipts.receipt_date - purchase_orders.order_date` across recent POs), `vendors.on_time_delivery_rate` (percent of GRs received on or before the PO's `expected_delivery_date`), `vendors.quality_score` (derived from Quality Inspection pass rates, see Quality Inspection BR-QI-5), `vendors.payment_behavior_score` is NOT written by Purchasing (that reflects the vendor's own payment behavior toward the company as a customer if the relationship is bidirectional, owned by Sales/Vendors module) — Purchasing instead writes `vendors.risk_rating` inputs into a shared computed field alongside Finance-owned inputs (e.g., dispute frequency, bounced-payment-account history).

**Domain events consumed from Vendors.** `vendor.blocked`, `vendor.certificate_expired`, `vendor.bank_account_changed` (the last one is security-sensitive — see Security — and triggers a mandatory re-verification hold on any `vendor_payments` in `draft`/`pending_approval` status for that vendor until a human confirms the new bank details against an out-of-band source).

**Domain events published to Vendors.** `purchase_order.issued`, `goods_receipt.posted`, `quality_inspection.completed`, `bill.posted`, `vendor_payment.completed`, `purchase_return.completed` — each carries enough payload (amount, on-time flag, quality outcome) for the Vendors module's own scoring jobs to consume without querying Purchasing tables directly.

# Inventory Integration

Purchasing is one of two primary writers into the Inventory domain (the other being Sales, on the outbound side), and the only writer that increases `inventory_items.quantity_on_hand` through a vendor-sourced acquisition path (as opposed to a manual `stock_adjustments` correction or a `stock_transfers` inter-warehouse move).

**Stock movement types generated by Purchasing.** `purchase_receipt` (Goods Receipt posting, increases quantity, BR-GR-3), `quality_release` (Quality Inspection pass, reclassifies hold status without changing total quantity, BR-QI-2), `quality_reject`/`purchase_return` (decreases quantity or releases a hold without ever having added to unrestricted stock, BR-RET-2), `purchase_return_reversal` (the rare case where a vendor disputes and the company reverses its own return before the vendor acknowledges it).

**Costing method interaction.** QAYD supports `standard_cost`, `moving_average`, and `fifo` costing per `products.costing_method`. Purchasing's Goods Receipt posting behaves differently per method:
- *Moving average:* `new_average_cost = ((old_qty * old_average_cost) + (received_qty * received_unit_cost)) / (old_qty + received_qty)`, where `received_unit_cost` includes the PO line's `unit_price` plus any landed-cost allocation (freight, customs duty, insurance — see below) apportioned to that line. `inventory_items.average_cost` updates atomically with the stock movement.
- *Standard cost:* the GR posts at the product's fixed `standard_cost`; any difference between `received_unit_cost` and `standard_cost` posts to a **Purchase Price Variance** account (see Accounting Integration) rather than distorting the standard cost itself.
- *FIFO:* the GR creates a new cost layer (`inventory_valuations` row) at `received_unit_cost`; consumption (via Sales or internal issue) draws down layers oldest-first: Purchasing never has to compute a blended cost, it simply appends a layer.

**Landed cost allocation.** Freight, customs duty, and insurance charges tied to an import PO (common in Gulf trading businesses that import from Asia/Europe) are captured as additional `bill_items` lines against the same vendor or a separate freight-forwarder vendor, tagged `is_landed_cost = true` with a `landed_cost_allocation_method` (`by_value`|`by_quantity`|`by_weight`) and `landed_cost_target_po_id`; the system apportions the landed-cost amount across the target PO's GR lines proportionally to the chosen method and folds it into `received_unit_cost` for costing purposes, while still posting the landed-cost expense to its own GL account for expense-nature transparency (see Accounting Integration).

**Reservation interaction.** Purchasing does not create `stock_reservations` (that is a Sales/Inventory concern), but a GR posting that fills a warehouse whose `inventory_items` currently has active reservations against backordered sales orders triggers a `stock.replenished` event Inventory consumes to attempt reservation fulfillment — Purchasing publishes the event and stops there; it does not decide fulfillment order.

**Serial/batch tracking.** For `product_serials`/`product_batches`-tracked products, the GR line's `serial_numbers`/`batch_number` (Receiving Goods) is the point of first entry into the platform; Purchasing is therefore responsible for validating uniqueness of newly introduced serials/batch codes at posting time (a duplicate serial number across two different GRs for the same product is rejected with `409`, since it almost always indicates a data-entry error or, rarely, a counterfeit-goods signal worth flagging to the AI Fraud Detection agent).

# Warehouse Integration

Purchasing's physical touchpoint with the Warehouse domain is entirely at the Goods Receipt stage; it neither creates nor manages `warehouses`, `warehouse_zones`, `warehouse_areas`, `warehouse_aisles`, `warehouse_rows`, `warehouse_shelves`, or `warehouse_bins`, but it is a primary consumer of the storage-location hierarchy at receiving time.

**Put-away.** A `goods_receipt_items.bin_location_id` is either selected manually by the Warehouse Employee at receiving, or suggested by a put-away rule (a Warehouse-owned capability, e.g., "fast-moving items to ground-level bins nearest the dock") that Purchasing's receiving screen calls via the Warehouse module's put-away suggestion endpoint — Purchasing does not implement put-away logic itself, only invokes it and records the resulting `bin_location_id` on the GR line.

**Receiving dock / staging.** For companies whose `warehouses.has_staging_area = true`, a GR can post with `staging_only = true`, meaning stock is recorded as received (financially — the GR/IR clearing entry posts normally) but not yet put away to a final bin; the item's `hold_status` in this case is `pending_putaway` rather than `pending_inspection`, a distinct hold type. A subsequent Warehouse-owned put-away confirmation clears this hold and finalizes `bin_location_id` without any further Purchasing-side action.

**Cross-warehouse / drop-ship POs.** A `drop_ship` PO type (Purchase Orders section) never creates a GR against a company warehouse; instead, delivery confirmation is recorded by Sales (against the linked `sales_orders`/`deliveries` row the drop-ship PO was raised to fulfill) and Purchasing's PO closes on receipt of the vendor's proof-of-delivery attachment plus the Sales-side delivery confirmation event (`delivery.confirmed`), without ever touching `inventory_items` for that company's own warehouses.

**Multi-branch receiving.** `goods_receipts.warehouse_id` must belong to the same `branch_id` as (or a branch explicitly permitted to receive against) the parent PO's `branch_id`; receiving a Head-Office-issued PO into a different branch's warehouse is allowed only when `purchasing.order.cross_branch_receive` is granted, since it has inventory-ownership and inter-branch-transfer accounting implications the Warehouse and Accounting modules must both be aware of (an implicit `stock_transfers` row is auto-created in this case to keep branch-level stock accounting correct).

# Accounting Integration

Purchasing never writes to `journal_entries` or `journal_lines` directly from its own service classes; it always posts through the Accounting module's posting service via a domain event, and the Accounting module resolves the actual `account_id`s from the company's Chart of Accounts and dimension defaults (cost center, project, branch) carried on the source document. This section specifies, exactly, the debit/credit shape of every journal entry Purchasing triggers.

**Key accounts referenced (illustrative default `accounts` codes — actual account resolution is company-configurable via `account_type_id`-based default mapping, never hardcoded account codes).**

| Role | Account | Nature |
|---|---|---|
| Inventory | Inventory (Raw Materials / Trading Goods) | Asset |
| GR/IR Clearing | Goods Received / Invoice Received Clearing | Liability |
| Accounts Payable | Accounts Payable — Trade | Liability |
| Input VAT | Input VAT Receivable | Asset |
| Purchase Price Variance | Purchase Price Variance | Expense (or contra-Expense) |
| Freight/Landed Cost | Freight-In / Landed Cost Clearing | Asset (capitalized into Inventory) or Expense |
| Purchase Discounts Received | Purchase Discounts Received | Income (contra-Expense per company convention) |
| Vendor Advances | Vendor Advances (Prepayments) | Asset |
| Bank/Cash | Bank — Operating Account | Asset |
| Expense (non-stock/services) | the specific expense account on the Bill line (`bill_items.expense_account_id`) or the product's default expense/COGS mapping | Expense |

**1. Goods Receipt posting (stock item, standard/moving-average costing).** Triggered by `GoodsReceived` event, at `received_unit_cost` (unit price + apportioned landed cost, ex-tax — VAT is not yet recognized here because the vendor's tax invoice has not yet arrived; the GR/IR clearing amount is a placeholder liability for goods received without an invoice).

```
Dr  Inventory (Trading Goods)                    received_unit_cost × quantity_accepted
    Cr  GR/IR Clearing                                                  received_unit_cost × quantity_accepted
```

**1b. Goods Receipt posting (standard costing, with variance).**

```
Dr  Inventory (at standard_cost × quantity_accepted)
Dr/Cr Purchase Price Variance (the difference, sign depends on favorable/unfavorable)
    Cr  GR/IR Clearing (at received_unit_cost × quantity_accepted)
```

**1c. Goods Receipt posting (services PO, no inventory).**

```
Dr  Prepaid Expense / Accrued Expense (matched to the service period, or directly to Expense if not accrual-deferred)
    Cr  GR/IR Clearing
```

**2. Vendor Bill posting (matched, PO-backed, stock item) — clears GR/IR, recognizes real AP, recognizes input tax.**

```
Dr  GR/IR Clearing                    subtotal_amount (matching the GR's provisional value; any price variance discovered at billing posts to Purchase Price Variance, not silently absorbed into Inventory after the fact)
Dr  Input VAT Receivable              tax_amount
Dr/Cr Purchase Price Variance          bill_unit_price − received_unit_cost (if within tolerance but non-zero)
    Cr  Accounts Payable — Trade                          total_amount
```

**2b. Vendor Bill posting (non-PO, expense line — no Inventory/GR-IR involvement).**

```
Dr  Expense (bill_items.expense_account_id)   subtotal_amount
Dr  Input VAT Receivable                       tax_amount
    Cr  Accounts Payable — Trade                                 total_amount
```

**2c. Vendor Bill posting (landed cost line, e.g., freight/customs on an import PO).**

```
Dr  Inventory (apportioned share, if capitalized per company policy) or Freight/Customs Expense (if expensed)
Dr  Input VAT Receivable (if the freight/customs charge itself carries VAT)
    Cr  Accounts Payable — Trade
```

**3. Vendor Payment posting (settling one or more Bills).**

```
Dr  Accounts Payable — Trade          total_amount (across allocated bills)
    Cr  Bank — Operating Account                        total_amount − discount_taken
    Cr  Purchase Discounts Received                      discount_taken   (if any early-payment discount captured)
```

**3b. Advance Payment posting (no Bill yet).**

```
Dr  Vendor Advances (Prepayments)     total_amount
    Cr  Bank — Operating Account                        total_amount
```
— later applied when a Bill arrives: `Dr Accounts Payable — Trade / Cr Vendor Advances` for the applied amount, as a distinct allocation journal, leaving any true cash settlement to a normal Vendor Payment for the remainder.

**4. Debit Note / Purchase Return posting.**

*Return against an unpaid Bill (reduces AP, no cash moves):*
```
Dr  Accounts Payable — Trade          total_amount
    Cr  Inventory (Trading Goods)                        subtotal_amount  (reversing the original receipt value)
    Cr  Input VAT Receivable                              tax_amount      (reversing the input tax originally claimed)
```

*Return against an already-paid Bill, refunded in cash:*
```
Dr  Bank — Operating Account          total_amount
    Cr  Inventory (Trading Goods)                        subtotal_amount
    Cr  Input VAT Receivable                              tax_amount
```

*Return discovered before any Bill exists (against the GR/IR clearing balance only):*
```
Dr  GR/IR Clearing                    subtotal_amount
    Cr  Inventory (Trading Goods)                        subtotal_amount
```

**Posting sequencing and idempotency.** Every posting call from Purchasing to Accounting carries an idempotency key equal to `{document_type}:{document_id}:{event_type}` so that a retried event (e.g., a queue redelivery after a transient failure) never double-posts; the Accounting posting service checks for an existing `journal_entries.source_reference` matching that key and returns the existing entry rather than creating a duplicate. Every journal entry created by Purchasing carries `source_type = 'purchasing'`, `source_id` (the Bill/GR/Payment/Debit Note id), and the dimensions (`cost_center_id`, `project_id`, `department_id`, `branch_id`) copied from the source document's line, so GL-level P&L-by-cost-center reporting requires no join back into Purchasing tables at report time.

# Tax Integration

Purchasing is a primary source of **input tax** (VAT recoverable on purchases) across the GCC's evolving VAT landscape (implemented in Saudi Arabia and the UAE; anticipated under the GCC unified VAT framework for Kuwait, Bahrain, Oman, and Qatar at various stages of rollout), and Purchasing lines carry `tax_code_id` at multiple stages (PO line, Bill line, Debit Note line) rather than only at the final billing point, so that estimated input tax is visible even before a formal tax invoice is received.

**Tax code resolution order.** A line's effective tax code resolves, in priority order: (1) an explicit override set by the user on the line itself, (2) the `procurement_contract_items`/`price_list_items` tax mapping if the line derives from a contract or price list, (3) the `products.default_purchase_tax_code_id`, (4) the vendor's `vendors.default_tax_code_id` (relevant for vendors who are exclusively zero-rated or exempt, e.g., certain government or free-zone counterparties), (5) the company's system default purchase tax code. Whichever code resolves is stamped onto the line at document-creation time (not re-resolved retroactively if company defaults later change), so historical documents remain a faithful record of the tax treatment actually applied.

**Import VAT / reverse charge.** For companies importing goods (very common for Kuwait-based trading companies sourcing from outside the GCC), import VAT is frequently self-assessed via reverse charge rather than paid to the vendor directly. A Bill flagged `is_reverse_charge = true` posts both the input VAT debit and an equal-and-offsetting output VAT credit (the self-assessed liability) in the same journal entry, net zero cash impact but correctly populating both the input and output boxes of the VAT return:

```
Dr  Input VAT Receivable              tax_amount
    Cr  Output VAT Payable (reverse charge)               tax_amount
Dr  Expense/Inventory                 subtotal_amount
    Cr  Accounts Payable — Trade                          subtotal_amount   (the vendor is paid the tax-exclusive amount only)
```

**Vendor tax registration validation.** A Bill from a vendor whose `vendors.tax_registration_number` is null or flagged `unverified` cannot claim standard-rated input VAT (the line is forced to `tax_codes.category = 'exempt'` or `non_recoverable`) until the registration is verified — protecting the company from disallowed input-tax claims in a VAT audit. This check runs synchronously at Bill submission and is one of the few Purchasing validations that directly blocks on a Tax-module lookup rather than merely publishing an event.

**Withholding tax (where applicable).** For jurisdictions/contract terms requiring withholding on services payments (relevant for some cross-border service vendors), a Bill line can carry `withholding_tax_code_id`; the corresponding `vendor_payments` posting nets the withheld amount to a Withholding Tax Payable liability account rather than paying it to the vendor, with the withheld amount separately remitted to the tax authority via the Tax module's `tax_returns` process — Purchasing's responsibility ends at correctly computing and isolating the withheld amount on the payment.

**Tax reporting feed.** Every posted Bill and Debit Note line writes a `tax_transactions` row (owned by the Tax module) via the `bill.posted`/`debit_note.posted` events, carrying `tax_code_id`, `taxable_amount`, `tax_amount`, `direction = 'input'`, and `document_reference` — this is the sole feed the Tax module needs from Purchasing to populate the input-tax side of a VAT return; Purchasing never generates a tax return itself.

# Multi Currency

Gulf trading companies routinely purchase in USD, EUR, CNY, or other vendor-invoicing currencies against a KWD (or AED/SAR) base currency, so every monetary document in this module is currency-aware from creation, not merely at conversion time for reporting.

**Currency fields, present on every monetary document (`purchase_orders`, `bills`, `vendor_payments`, `debit_notes`, `procurement_contracts`, `rfq_responses`).** `currency_code` (ISO 4217, e.g., `USD`, `KWD`, `AED`, `SAR`, `EUR`, `CNY`), `exchange_rate` (NUMERIC(18,6), the rate to the company's base currency in effect at the document's functional date), plus every amount column stored twice conceptually: the transaction-currency amount (the column as named, e.g., `total_amount`) and an always-present base-currency equivalent computed as `total_amount * exchange_rate` and persisted in a shadow column (`total_amount_base`) so that base-currency aggregation (dashboards, reports, the GL) never needs a runtime FX join.

**Rate source and locking.** `exchange_rate` defaults from the platform's daily FX rate table (sourced from a central bank feed, refreshed daily) at document creation, but is a locked, editable field per document — once a PO is `sent`, its `exchange_rate` is frozen (BR-PO-8 already prohibits currency changes post-GR/Bill; the rate itself can still be explicitly re-locked to match an actual forward-contract or bank-quoted rate before `sent`, but never silently drifts with the daily feed after that point). A Bill's `exchange_rate` is independently set at Bill entry (it may differ from the PO's rate if time has passed), and the resulting **exchange gain/loss** between the GR/IR clearing amount (booked at the PO/GR rate) and the Bill amount (booked at the Bill-date rate) posts to a dedicated Realized Exchange Gain/Loss account as part of the Bill's journal entry:

```
Dr  GR/IR Clearing                    (original base-currency amount at GR rate)
Dr/Cr Realized Exchange Gain/Loss      (the FX difference)
    Cr  Accounts Payable — Trade                          (base-currency amount at Bill rate)
```

A parallel exchange gain/loss recognition occurs at Payment if the Payment-date rate differs again from the Bill-date rate at which AP was booked, using the same Realized Exchange Gain/Loss account.

**Multi-currency payments.** `vendor_payments.currency_code` can differ from the Bill's currency (e.g., a USD-invoiced Bill settled from a KWD bank account) — the payment allocation computes the settled amount in the Bill's currency for AP clearing purposes and in the payment's own currency for the Bank debit, with the FX difference again isolated to Realized Exchange Gain/Loss rather than blended into either the AP or Bank line.

**Reporting currency.** Every report in this module (Reports section) defaults to the company's base currency but supports an optional `?currency=` query parameter to re-render monetary columns in a requested currency using the *reporting date's* rate (a distinct, read-only conversion from the transactional `exchange_rate` locked on each document) — useful for a Gulf group company comparing spend across KWD-, AED-, and SAR-functional subsidiaries in one shared currency for consolidated board reporting.

# AI Responsibilities

Every AI agent below runs in the FastAPI AI layer, reasons over data it reads through the Laravel API (or a read-optimized replica the Laravel API also governs), and writes nothing to the database directly. Every recommendation, ranking, forecast, or flag it produces carries `confidence` (0.00–1.00), `reasoning` (natural-language explanation citing the specific documents/fields it used), and `supporting_document_ids`; the receiving human sees all three before acting. Autonomy level is stated explicitly per capability below and never exceeds what is declared.

**Vendor Recommendation.** *Agent: General Accountant / Treasury Manager (procurement-tuned).* *Inputs:* an RFQ's invited/eligible vendor pool, each candidate vendor's `on_time_delivery_rate`, `quality_score`, `average_lead_time_days`, `total_spend_lifetime`, `payment_terms` history, and (for re-sourcing an existing product) prior `rfq_responses` for that product. *Outputs:* a ranked shortlist of vendors to invite to a new RFQ, each with a composite score (weighted blend of price-competitiveness history, delivery reliability, and quality) and the specific factors driving the rank. *Autonomy:* suggest-only — the agent pre-populates the RFQ's `invited_vendor_ids` field as a draft the Purchasing Employee/Manager must review and confirm before the RFQ is issued; it never invites a vendor without that confirmation. *Confidence handling:* a recommendation below 0.55 confidence (typically a new product category with no purchase history) is shown but visually de-emphasized and labeled "low history — verify manually."

**Price Comparison.** *Agent: General Accountant.* *Inputs:* all `rfq_responses` for a closed RFQ, normalized to a common unit of measure and currency (base-currency conversion applied for cross-currency comparability). *Outputs:* the comparison grid itself (this is largely deterministic computation, not inference) plus a narrower AI overlay: an anomaly note when one vendor's price is a statistical outlier (>2 standard deviations from the response set's mean for that line) in either direction — flagging both suspiciously cheap (possible spec substitution or error) and expensive quotes. *Autonomy:* fully automatic for the grid computation itself (deterministic, not a judgment call); suggest-only for the anomaly narrative, which a human reads before awarding.

**Demand Forecasting.** *Agent: Forecast Agent.* *Inputs:* `stock_movements` consumption history per product per warehouse, seasonality patterns (detected automatically, e.g., Ramadan-driven demand shifts relevant to Gulf F&B and retail purchasers), open `sales_orders` backlog, and `products.reorder_point`/`reorder_quantity`. *Outputs:* a rolling 30/60/90-day demand forecast per product-warehouse pair, with a predicted stockout date where current `quantity_on_hand` plus open PO quantities will not cover forecast demand. *Autonomy:* suggest-only, feeding the Purchase Prediction agent below; the forecast itself is visible on the Purchase Forecast report (Reports) but triggers no document creation on its own.

**Purchase Prediction.** *Agent: Forecast Agent (action-oriented mode).* *Inputs:* the Demand Forecasting output plus each product's default vendor and last-paid price. *Outputs:* an auto-drafted Purchase Request (`source = 'ai_forecast'`, status `draft`) when a predicted stockout falls inside the vendor's `average_lead_time_days` window, with quantity set to cover the forecast horizon at the product's `reorder_quantity` policy. *Autonomy:* draft-creation only — the PR lands in `draft`, never `pending_approval`; a human (the department owner or a Purchasing Employee) must review and submit it, exactly like a manually created PR, before it enters the approval chain (Approval Workflow). This is the only capability in this module where AI creates a database row directly reachable through the normal Purchase Request table — and it is deliberately capped at the least-consequential lifecycle state (`draft`) to preserve the "AI proposes, human approves" boundary.

**Duplicate Bill Detection.** *Agent: Fraud Detection / Auditor.* *Inputs:* every incoming Bill's `vendor_id`, `vendor_bill_reference`, `total_amount`, `bill_date`, and OCR-extracted line items (when submitted via Document AI/OCR ingestion) compared against the company's existing `bills` for the same vendor within a rolling 180-day window. *Outputs:* a duplicate-risk score combining exact-match (same `vendor_bill_reference`, blocked structurally by BR-BILL-4 before this even runs), near-exact match (same amount ± 0.5%, same or adjacent date, different reference — classic re-submission-with-a-typo pattern), and semantic match (OCR line-item text similarity above a threshold despite a different reference number and amount, catching a vendor who split one delivery into two invoices). *Autonomy:* suggest-only — a Bill scoring above the duplicate threshold (default 0.75 confidence) is held at `pending_match` with a visible "possible duplicate of BILL-2026-000317" banner and requires a Finance user to explicitly confirm "not a duplicate" (logged) before it can proceed to approval; the agent never auto-rejects a Bill.

**Fraud Detection.** *Agent: Fraud Detection.* *Inputs:* the full transaction graph across a vendor relationship — unusual patterns such as a vendor's bank account changing shortly before a large payment (cross-referenced with the `vendor.bank_account_changed` event from Vendor Integration), a Purchase Order approved by the same person who created the vendor record and who also approves the corresponding payment (segregation-of-duties violation), round-number invoices with no supporting GR, or a sudden spike in a single employee's non-PO bill submissions just under the `non_po_bill_max_amount` threshold (a classic threshold-gaming pattern). *Outputs:* a risk alert routed to the Auditor role and Finance Manager with the specific pattern matched and supporting evidence (document ids, timestamps, the segregation-of-duties rule violated). *Autonomy:* suggest/alert-only, and — for the highest-severity patterns (bank-account-change-then-large-payment) — the agent can request (but not itself impose) a temporary payment hold; the hold is only actually placed if a user with `purchasing.payment.hold` acts on the alert, per the platform rule that AI never bypasses authorization even for security-motivated actions.

**Spend Analysis.** *Agent: Reporting Agent.* *Inputs:* all posted `bills` and `vendor_payments` across a selected period, sliceable by vendor, product/category, cost center, project, branch, and department. *Outputs:* the narrative and visual layer behind the Spend Analysis report (Reports) — top-vendor concentration, category spend trends period-over-period, maverick (non-PO / off-contract) spend as a percentage of total, and savings realized versus RFQ baseline pricing. *Autonomy:* fully read-only reporting; zero write capability of any kind.

**Supplier Performance.** *Agent: Auditor / Treasury Manager.* *Inputs:* `on_time_delivery_rate`, `quality_score`, price-variance history at Bill matching, contract SLA actuals (Procurement Contracts BR-PC-5), and responsiveness (RFQ response time, quote validity honored). *Outputs:* a composite Supplier Scorecard per vendor, refreshed nightly, feeding both the Vendor Performance report and the Vendor Recommendation agent's ranking inputs. *Autonomy:* fully automatic computation and publication of the scorecard (it is analytics, not a financial or physical action); any resulting commercial decision (de-listing a vendor, renegotiating a contract) remains entirely human.

**Purchase Optimization.** *Agent: CFO / Treasury Manager (advisory mode).* *Inputs:* the full spend graph plus contract terms, payment terms, and cash position context (read from Banking, without write access). *Outputs:* concrete, dated recommendations — e.g., "consolidate the 6 separate office-supplies vendors into the 2 highest-scoring ones to unlock volume pricing," "Vendor X offers 2/10 net 30; taking the discount across last quarter's spend with them would have saved KWD 1,240," "Contract PC-2025-0042 expires in 22 days with no renewal action logged." *Outputs feed the Owner/CFO dashboard directly; nothing here creates or modifies a document — it is pure decision support, the most advisory-only capability in this module.*

# Database Design

All fifteen tables owned by Purchasing follow the platform-wide standard columns (`id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) in addition to the columns listed. Money columns are `NUMERIC(19,4)`; exchange rates `NUMERIC(18,6)`; quantities `NUMERIC(18,4)`. Every table has a `company_id` index and every FK column is indexed. Enums are implemented as PostgreSQL `CHECK` constraints against a fixed value list (not native `ENUM` types, to avoid migration-lock pain when a value list grows) unless noted.

```sql
-- ============================================================
-- PURCHASE REQUESTS
-- ============================================================
CREATE TABLE purchase_requests (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    request_number          VARCHAR(32) NOT NULL,
    requested_by            BIGINT NOT NULL REFERENCES users(id),
    department_id           BIGINT NULL REFERENCES departments(id),
    cost_center_id          BIGINT NULL REFERENCES cost_centers(id),
    project_id              BIGINT NULL REFERENCES projects(id),
    request_date            DATE NOT NULL DEFAULT CURRENT_DATE,
    required_by_date         DATE NULL,
    priority                VARCHAR(16) NOT NULL DEFAULT 'normal'
                              CHECK (priority IN ('low','normal','high','urgent')),
    justification            TEXT NULL,
    preferred_vendor_id       BIGINT NULL REFERENCES vendors(id),
    estimated_total_amount    NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code             VARCHAR(3) NOT NULL DEFAULT 'KWD',
    status                    VARCHAR(24) NOT NULL DEFAULT 'draft'
                              CHECK (status IN ('draft','pending_approval','approved','rejected',
                                                 'sourcing','partially_sourced','fulfilled','cancelled')),
    revision_number          INT NOT NULL DEFAULT 1,
    rejection_reason          TEXT NULL,
    source                    VARCHAR(24) NOT NULL DEFAULT 'manual'
                              CHECK (source IN ('manual','ai_forecast','reorder_point','contract_renewal')),
    ai_confidence             NUMERIC(4,2) NULL,
    created_by                BIGINT NULL REFERENCES users(id),
    updated_by                BIGINT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                TIMESTAMPTZ NULL,
    CONSTRAINT uq_pr_number UNIQUE (company_id, request_number)
);
CREATE INDEX idx_pr_company ON purchase_requests(company_id);
CREATE INDEX idx_pr_status ON purchase_requests(company_id, status);
CREATE INDEX idx_pr_requested_by ON purchase_requests(requested_by);
CREATE INDEX idx_pr_vendor ON purchase_requests(preferred_vendor_id);

CREATE TABLE purchase_request_items (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    purchase_request_id      BIGINT NOT NULL REFERENCES purchase_requests(id) ON DELETE CASCADE,
    line_number               INT NOT NULL,
    product_id                BIGINT NULL REFERENCES products(id),
    description                VARCHAR(255) NOT NULL,
    quantity_requested          NUMERIC(18,4) NOT NULL CHECK (quantity_requested > 0),
    unit_of_measure_id           BIGINT NOT NULL REFERENCES units_of_measure(id),
    estimated_unit_price          NUMERIC(19,4) NOT NULL DEFAULT 0,
    warehouse_id                  BIGINT NULL REFERENCES warehouses(id),
    required_by_date               DATE NULL,
    status                          VARCHAR(20) NOT NULL DEFAULT 'open'
                                    CHECK (status IN ('open','partially_sourced','sourced','cancelled')),
    notes                            TEXT NULL,
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                       TIMESTAMPTZ NULL,
    CONSTRAINT uq_pri_line UNIQUE (purchase_request_id, line_number)
);
CREATE INDEX idx_pri_request ON purchase_request_items(purchase_request_id);
CREATE INDEX idx_pri_product ON purchase_request_items(product_id);

-- ============================================================
-- RFQ
-- ============================================================
CREATE TABLE rfqs (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    rfq_number               VARCHAR(32) NOT NULL,
    title                     VARCHAR(255) NOT NULL,
    issued_by                 BIGINT NOT NULL REFERENCES users(id),
    issue_date                 DATE NOT NULL DEFAULT CURRENT_DATE,
    response_deadline           TIMESTAMPTZ NOT NULL,
    status                       VARCHAR(20) NOT NULL DEFAULT 'draft'
                                 CHECK (status IN ('draft','issued','responses_open','closed','awarded','cancelled')),
    source_pr_ids                 JSONB NOT NULL DEFAULT '[]',
    invited_vendor_ids             JSONB NOT NULL DEFAULT '[]',
    terms_and_conditions             TEXT NULL,
    awarded_vendor_id                 BIGINT NULL REFERENCES vendors(id),
    awarded_po_id                      BIGINT NULL,
    created_by                          BIGINT NULL REFERENCES users(id),
    updated_by                          BIGINT NULL REFERENCES users(id),
    created_at                           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                           TIMESTAMPTZ NULL,
    CONSTRAINT uq_rfq_number UNIQUE (company_id, rfq_number)
);
CREATE INDEX idx_rfq_company_status ON rfqs(company_id, status);

CREATE TABLE rfq_items (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    rfq_id                   BIGINT NOT NULL REFERENCES rfqs(id) ON DELETE CASCADE,
    line_number                INT NOT NULL,
    product_id                  BIGINT NULL REFERENCES products(id),
    description                  VARCHAR(255) NOT NULL,
    quantity_requested             NUMERIC(18,4) NOT NULL CHECK (quantity_requested > 0),
    unit_of_measure_id              BIGINT NOT NULL REFERENCES units_of_measure(id),
    target_delivery_date              DATE NULL,
    source_pr_item_id                  BIGINT NULL REFERENCES purchase_request_items(id),
    created_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_rfqi_line UNIQUE (rfq_id, line_number)
);
CREATE INDEX idx_rfqi_rfq ON rfq_items(rfq_id);

CREATE TABLE rfq_responses (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    rfq_id                   BIGINT NOT NULL REFERENCES rfqs(id),
    vendor_id                 BIGINT NOT NULL REFERENCES vendors(id),
    submitted_at                TIMESTAMPTZ NULL,
    valid_until                  DATE NULL,
    payment_terms_offered          VARCHAR(32) NULL,
    line_quotes                     JSONB NOT NULL DEFAULT '[]',
    total_quoted_amount               NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code                      VARCHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate                       NUMERIC(18,6) NOT NULL DEFAULT 1,
    status                               VARCHAR(20) NOT NULL DEFAULT 'received'
                                         CHECK (status IN ('received','under_review','shortlisted','awarded','rejected')),
    late_submission                       BOOLEAN NOT NULL DEFAULT false,
    rejection_reason                        TEXT NULL,
    submission_channel                       VARCHAR(20) NOT NULL DEFAULT 'manual'
                                              CHECK (submission_channel IN ('portal','email_ingested','manual')),
    created_at                                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                TIMESTAMPTZ NULL,
    CONSTRAINT uq_rfq_vendor UNIQUE (rfq_id, vendor_id)
);
CREATE INDEX idx_rfqr_rfq ON rfq_responses(rfq_id);
CREATE INDEX idx_rfqr_vendor ON rfq_responses(vendor_id);

-- ============================================================
-- PURCHASE ORDERS
-- ============================================================
CREATE TABLE purchase_orders (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    po_number                VARCHAR(32) NOT NULL,
    vendor_id                 BIGINT NOT NULL REFERENCES vendors(id),
    vendor_contact_id           BIGINT NULL REFERENCES vendor_contacts(id),
    procurement_contract_id      BIGINT NULL REFERENCES procurement_contracts(id),
    rfq_id                        BIGINT NULL REFERENCES rfqs(id),
    warehouse_id                   BIGINT NULL REFERENCES warehouses(id),
    po_type                          VARCHAR(20) NOT NULL DEFAULT 'standard'
                                     CHECK (po_type IN ('standard','blanket','services','drop_ship')),
    order_date                        DATE NOT NULL DEFAULT CURRENT_DATE,
    expected_delivery_date              DATE NULL,
    payment_terms                        VARCHAR(32) NOT NULL DEFAULT 'net_30',
    currency_code                         VARCHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate                          NUMERIC(18,6) NOT NULL DEFAULT 1,
    incoterm                                 VARCHAR(10) NULL,
    shipping_address                          TEXT NULL,
    billing_address                            TEXT NULL,
    drop_ship_address                           TEXT NULL,
    subtotal_amount                              NUMERIC(19,4) NOT NULL DEFAULT 0,
    discount_amount                               NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount                                     NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount                                    NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount_base                                NUMERIC(19,4) NOT NULL DEFAULT 0,
    status                                            VARCHAR(24) NOT NULL DEFAULT 'draft'
                                                       CHECK (status IN ('draft','pending_approval','approved','rejected',
                                                                          'sent','partially_received','fully_received',
                                                                          'closed','cancelled')),
    approval_status                                    VARCHAR(20) NOT NULL DEFAULT 'not_started'
                                                        CHECK (approval_status IN ('not_started','pending','approved','rejected')),
    sent_at                                              TIMESTAMPTZ NULL,
    sent_via                                             VARCHAR(10) NULL CHECK (sent_via IN ('email','portal','manual')),
    amends_po_id                                          BIGINT NULL REFERENCES purchase_orders(id),
    amendment_number                                       INT NOT NULL DEFAULT 0,
    superseded_by                                           BIGINT NULL REFERENCES purchase_orders(id),
    notes                                                     TEXT NULL,
    internal_notes                                             TEXT NULL,
    created_by                                                  BIGINT NULL REFERENCES users(id),
    updated_by                                                   BIGINT NULL REFERENCES users(id),
    created_at                                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                                      TIMESTAMPTZ NULL,
    CONSTRAINT uq_po_number UNIQUE (company_id, po_number),
    CONSTRAINT chk_po_total CHECK (total_amount = subtotal_amount - discount_amount + tax_amount)
);
CREATE INDEX idx_po_company_status ON purchase_orders(company_id, status);
CREATE INDEX idx_po_vendor ON purchase_orders(vendor_id);
CREATE INDEX idx_po_contract ON purchase_orders(procurement_contract_id);
CREATE INDEX idx_po_rfq ON purchase_orders(rfq_id);

CREATE TABLE purchase_order_items (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    purchase_order_id        BIGINT NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    line_number                INT NOT NULL,
    source_pr_item_id           BIGINT NULL REFERENCES purchase_request_items(id),
    source_rfq_item_id            BIGINT NULL REFERENCES rfq_items(id),
    product_id                     BIGINT NULL REFERENCES products(id),
    description                     VARCHAR(255) NOT NULL,
    quantity_ordered                 NUMERIC(18,4) NOT NULL CHECK (quantity_ordered > 0),
    unit_of_measure_id                 BIGINT NOT NULL REFERENCES units_of_measure(id),
    unit_price                          NUMERIC(19,4) NOT NULL,
    discount_percent                     NUMERIC(5,2) NOT NULL DEFAULT 0,
    tax_code_id                           BIGINT NULL REFERENCES tax_codes(id),
    line_amount                            NUMERIC(19,4) NOT NULL,
    warehouse_id                            BIGINT NULL REFERENCES warehouses(id),
    cost_center_id                           BIGINT NULL REFERENCES cost_centers(id),
    project_id                                BIGINT NULL REFERENCES projects(id),
    expected_delivery_date                     DATE NULL,
    quantity_received                           NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_billed                              NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_returned                             NUMERIC(18,4) NOT NULL DEFAULT 0,
    line_status                                    VARCHAR(20) NOT NULL DEFAULT 'open'
                                                    CHECK (line_status IN ('open','partially_received','fully_received',
                                                                            'closed','cancelled')),
    created_at                                      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                        TIMESTAMPTZ NULL,
    CONSTRAINT uq_poi_line UNIQUE (purchase_order_id, line_number),
    CONSTRAINT chk_poi_received CHECK (quantity_received >= 0 AND quantity_billed >= 0 AND quantity_returned >= 0)
);
CREATE INDEX idx_poi_po ON purchase_order_items(purchase_order_id);
CREATE INDEX idx_poi_product ON purchase_order_items(product_id);
CREATE INDEX idx_poi_source_pr ON purchase_order_items(source_pr_item_id);

-- ============================================================
-- GOODS RECEIPTS
-- ============================================================
CREATE TABLE goods_receipts (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    receipt_number            VARCHAR(32) NOT NULL,
    purchase_order_id          BIGINT NOT NULL REFERENCES purchase_orders(id),
    warehouse_id                 BIGINT NULL REFERENCES warehouses(id),
    received_by                   BIGINT NOT NULL REFERENCES users(id),
    receipt_date                    DATE NOT NULL DEFAULT CURRENT_DATE,
    vendor_delivery_note_number       VARCHAR(64) NULL,
    carrier                            VARCHAR(128) NULL,
    status                               VARCHAR(16) NOT NULL DEFAULT 'draft'
                                         CHECK (status IN ('draft','posted','reversed')),
    requires_inspection                  BOOLEAN NOT NULL DEFAULT false,
    staging_only                          BOOLEAN NOT NULL DEFAULT false,
    reversed_by_receipt_id                 BIGINT NULL REFERENCES goods_receipts(id),
    notes                                    TEXT NULL,
    created_by                               BIGINT NULL REFERENCES users(id),
    updated_by                                BIGINT NULL REFERENCES users(id),
    created_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                   TIMESTAMPTZ NULL,
    CONSTRAINT uq_gr_number UNIQUE (company_id, receipt_number)
);
CREATE INDEX idx_gr_po ON goods_receipts(purchase_order_id);
CREATE INDEX idx_gr_company_status ON goods_receipts(company_id, status);
CREATE INDEX idx_gr_warehouse ON goods_receipts(warehouse_id);

CREATE TABLE goods_receipt_items (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    goods_receipt_id          BIGINT NOT NULL REFERENCES goods_receipts(id) ON DELETE CASCADE,
    line_number                 INT NOT NULL,
    purchase_order_item_id        BIGINT NOT NULL REFERENCES purchase_order_items(id),
    product_id                     BIGINT NULL REFERENCES products(id),
    quantity_ordered                 NUMERIC(18,4) NOT NULL,
    quantity_received                 NUMERIC(18,4) NOT NULL CHECK (quantity_received >= 0),
    quantity_accepted                  NUMERIC(18,4) NOT NULL,
    quantity_rejected                   NUMERIC(18,4) NOT NULL DEFAULT 0,
    unit_of_measure_id                   BIGINT NOT NULL REFERENCES units_of_measure(id),
    warehouse_id                          BIGINT NULL REFERENCES warehouses(id),
    bin_location_id                        BIGINT NULL REFERENCES warehouse_bins(id),
    batch_number                            VARCHAR(64) NULL,
    serial_numbers                           JSONB NULL,
    expiry_date                               DATE NULL,
    condition                                  VARCHAR(16) NOT NULL DEFAULT 'good'
                                                CHECK (condition IN ('good','damaged','partial_damage')),
    hold_status                                 VARCHAR(20) NOT NULL DEFAULT 'none'
                                                 CHECK (hold_status IN ('none','pending_inspection','pending_putaway')),
    notes                                        TEXT NULL,
    created_at                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_gri_line UNIQUE (goods_receipt_id, line_number),
    CONSTRAINT chk_gri_accept CHECK (quantity_accepted + quantity_rejected = quantity_received)
);
CREATE INDEX idx_gri_gr ON goods_receipt_items(goods_receipt_id);
CREATE INDEX idx_gri_poi ON goods_receipt_items(purchase_order_item_id);
CREATE INDEX idx_gri_batch ON goods_receipt_items(batch_number);

-- ============================================================
-- QUALITY INSPECTIONS
-- ============================================================
CREATE TABLE quality_inspections (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    inspection_number         VARCHAR(32) NOT NULL,
    goods_receipt_id           BIGINT NOT NULL REFERENCES goods_receipts(id),
    goods_receipt_item_id        BIGINT NOT NULL REFERENCES goods_receipt_items(id),
    inspector_id                  BIGINT NOT NULL REFERENCES users(id),
    inspection_date                 DATE NOT NULL DEFAULT CURRENT_DATE,
    inspection_criteria               JSONB NOT NULL DEFAULT '[]',
    quantity_inspected                 NUMERIC(18,4) NOT NULL,
    quantity_passed                      NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_failed                       NUMERIC(18,4) NOT NULL DEFAULT 0,
    disposition                            VARCHAR(20) NOT NULL
                                            CHECK (disposition IN ('accept','reject','accept_with_deviation','return_to_vendor')),
    deviation_approval_by                    BIGINT NULL REFERENCES users(id),
    status                                    VARCHAR(16) NOT NULL DEFAULT 'pending'
                                              CHECK (status IN ('pending','in_progress','passed','failed','partially_passed')),
    notes                                      TEXT NULL,
    created_by                                 BIGINT NULL REFERENCES users(id),
    updated_by                                  BIGINT NULL REFERENCES users(id),
    created_at                                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                     TIMESTAMPTZ NULL,
    CONSTRAINT uq_qi_number UNIQUE (company_id, inspection_number),
    CONSTRAINT chk_qi_qty CHECK (quantity_passed + quantity_failed = quantity_inspected)
);
CREATE INDEX idx_qi_gr ON quality_inspections(goods_receipt_id);
CREATE INDEX idx_qi_company_status ON quality_inspections(company_id, status);

-- ============================================================
-- BILLS
-- ============================================================
CREATE TABLE bills (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    bill_number               VARCHAR(32) NOT NULL,
    vendor_bill_reference       VARCHAR(64) NOT NULL,
    vendor_id                     BIGINT NOT NULL REFERENCES vendors(id),
    purchase_order_id               BIGINT NULL REFERENCES purchase_orders(id),
    bill_date                         DATE NOT NULL,
    due_date                           DATE NOT NULL,
    currency_code                        VARCHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate                         NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount                        NUMERIC(19,4) NOT NULL DEFAULT 0,
    discount_amount                         NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount                               NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount                              NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount_base                          NUMERIC(19,4) NOT NULL DEFAULT 0,
    amount_paid                                 NUMERIC(19,4) NOT NULL DEFAULT 0,
    amount_due                                   NUMERIC(19,4) NOT NULL DEFAULT 0,
    status                                        VARCHAR(24) NOT NULL DEFAULT 'draft'
                                                   CHECK (status IN ('draft','pending_match','matched','variance_flagged',
                                                                       'pending_approval','approved','posted',
                                                                       'paid','partially_paid','disputed','cancelled','void')),
    match_status                                    VARCHAR(20) NOT NULL DEFAULT 'not_matched'
                                                     CHECK (match_status IN ('not_matched','matched','variance','override_approved')),
    match_variance_amount                             NUMERIC(19,4) NOT NULL DEFAULT 0,
    match_variance_percent                              NUMERIC(6,3) NOT NULL DEFAULT 0,
    payment_terms                                        VARCHAR(32) NOT NULL DEFAULT 'net_30',
    procurement_type                                      VARCHAR(20) NOT NULL DEFAULT 'po'
                                                           CHECK (procurement_type IN ('po','non_po','contract_release')),
    is_reverse_charge                                      BOOLEAN NOT NULL DEFAULT false,
    journal_entry_id                                        BIGINT NULL REFERENCES journal_entries(id),
    created_by                                               BIGINT NULL REFERENCES users(id),
    updated_by                                                BIGINT NULL REFERENCES users(id),
    created_at                                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                                   TIMESTAMPTZ NULL,
    CONSTRAINT uq_bill_vendor_ref UNIQUE (company_id, vendor_id, vendor_bill_reference),
    CONSTRAINT chk_bill_total CHECK (total_amount = subtotal_amount - discount_amount + tax_amount),
    CONSTRAINT chk_bill_due CHECK (amount_due = total_amount - amount_paid)
);
CREATE INDEX idx_bill_company_status ON bills(company_id, status);
CREATE INDEX idx_bill_vendor ON bills(vendor_id);
CREATE INDEX idx_bill_po ON bills(purchase_order_id);
CREATE INDEX idx_bill_due_date ON bills(due_date) WHERE status IN ('posted','partially_paid');

CREATE TABLE bill_items (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    bill_id                  BIGINT NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
    line_number                INT NOT NULL,
    goods_receipt_item_id        BIGINT NULL REFERENCES goods_receipt_items(id),
    purchase_order_item_id         BIGINT NULL REFERENCES purchase_order_items(id),
    product_id                      BIGINT NULL REFERENCES products(id),
    description                      VARCHAR(255) NOT NULL,
    quantity_billed                    NUMERIC(18,4) NOT NULL CHECK (quantity_billed > 0),
    unit_of_measure_id                   BIGINT NOT NULL REFERENCES units_of_measure(id),
    unit_price                            NUMERIC(19,4) NOT NULL,
    discount_percent                        NUMERIC(5,2) NOT NULL DEFAULT 0,
    tax_code_id                              BIGINT NULL REFERENCES tax_codes(id),
    line_amount                               NUMERIC(19,4) NOT NULL,
    cost_center_id                             BIGINT NULL REFERENCES cost_centers(id),
    project_id                                  BIGINT NULL REFERENCES projects(id),
    is_landed_cost                               BOOLEAN NOT NULL DEFAULT false,
    landed_cost_allocation_method                  VARCHAR(12) NULL CHECK (landed_cost_allocation_method IN ('by_value','by_quantity','by_weight')),
    landed_cost_target_po_id                        BIGINT NULL REFERENCES purchase_orders(id),
    expense_account_id                               BIGINT NULL REFERENCES accounts(id),
    created_at                                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_bi_line UNIQUE (bill_id, line_number)
);
CREATE INDEX idx_bi_bill ON bill_items(bill_id);
CREATE INDEX idx_bi_gri ON bill_items(goods_receipt_item_id);

-- ============================================================
-- DEBIT NOTES (Purchase Returns)
-- ============================================================
CREATE TABLE debit_notes (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    debit_note_number         VARCHAR(32) NOT NULL,
    vendor_id                   BIGINT NOT NULL REFERENCES vendors(id),
    bill_id                       BIGINT NULL REFERENCES bills(id),
    goods_receipt_id                BIGINT NULL REFERENCES goods_receipts(id),
    reason                             VARCHAR(24) NOT NULL
                                       CHECK (reason IN ('quality_reject','wrong_item','damaged','overbilled',
                                                          'price_dispute','commercial_return','other')),
    reason_detail                       TEXT NULL,
    status                                VARCHAR(20) NOT NULL DEFAULT 'draft'
                                          CHECK (status IN ('draft','pending_approval','approved','sent_to_vendor',
                                                              'acknowledged','completed','rejected_by_vendor','cancelled')),
    subtotal_amount                       NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount                             NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount                            NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code                            VARCHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate                             NUMERIC(18,6) NOT NULL DEFAULT 1,
    applied_amount                             NUMERIC(19,4) NOT NULL DEFAULT 0,
    remaining_amount                            NUMERIC(19,4) NOT NULL DEFAULT 0,
    journal_entry_id                             BIGINT NULL REFERENCES journal_entries(id),
    created_by                                    BIGINT NULL REFERENCES users(id),
    updated_by                                     BIGINT NULL REFERENCES users(id),
    created_at                                      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                        TIMESTAMPTZ NULL,
    CONSTRAINT uq_dn_number UNIQUE (company_id, debit_note_number),
    CONSTRAINT chk_dn_total CHECK (total_amount = subtotal_amount + tax_amount),
    CONSTRAINT chk_dn_remaining CHECK (remaining_amount = total_amount - applied_amount)
);
CREATE INDEX idx_dn_vendor ON debit_notes(vendor_id);
CREATE INDEX idx_dn_bill ON debit_notes(bill_id);
CREATE INDEX idx_dn_gr ON debit_notes(goods_receipt_id);
CREATE INDEX idx_dn_company_status ON debit_notes(company_id, status);

CREATE TABLE debit_note_items (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    debit_note_id             BIGINT NOT NULL REFERENCES debit_notes(id) ON DELETE CASCADE,
    line_number                 INT NOT NULL,
    product_id                    BIGINT NULL REFERENCES products(id),
    bill_item_id                    BIGINT NULL REFERENCES bill_items(id),
    goods_receipt_item_id             BIGINT NULL REFERENCES goods_receipt_items(id),
    quantity                            NUMERIC(18,4) NOT NULL CHECK (quantity > 0),
    unit_price                            NUMERIC(19,4) NOT NULL,
    tax_code_id                            BIGINT NULL REFERENCES tax_codes(id),
    line_amount                             NUMERIC(19,4) NOT NULL,
    created_at                               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_dni_line UNIQUE (debit_note_id, line_number)
);
CREATE INDEX idx_dni_dn ON debit_note_items(debit_note_id);

-- ============================================================
-- VENDOR PAYMENTS
-- ============================================================
CREATE TABLE vendor_payments (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    payment_number            VARCHAR(32) NOT NULL,
    vendor_id                   BIGINT NOT NULL REFERENCES vendors(id),
    payment_date                  DATE NOT NULL DEFAULT CURRENT_DATE,
    payment_method                  VARCHAR(16) NOT NULL
                                     CHECK (payment_method IN ('bank_transfer','cheque','cash','card','knet')),
    bank_account_id                   BIGINT NULL REFERENCES bank_accounts(id),
    vendor_bank_account_id               BIGINT NULL REFERENCES vendor_bank_accounts(id),
    currency_code                         VARCHAR(3) NOT NULL DEFAULT 'KWD',
    exchange_rate                          NUMERIC(18,6) NOT NULL DEFAULT 1,
    total_amount                            NUMERIC(19,4) NOT NULL CHECK (total_amount > 0),
    payment_type                             VARCHAR(20) NOT NULL DEFAULT 'bill_payment'
                                              CHECK (payment_type IN ('bill_payment','advance_payment','refund_received')),
    reference_number                          VARCHAR(64) NULL,
    status                                     VARCHAR(20) NOT NULL DEFAULT 'draft'
                                               CHECK (status IN ('draft','pending_approval','approved','processing',
                                                                   'completed','failed','cancelled')),
    created_by_maker                            BIGINT NOT NULL REFERENCES users(id),
    approved_by                                  BIGINT NULL REFERENCES users(id),
    approved_at                                   TIMESTAMPTZ NULL,
    released_by                                    BIGINT NULL REFERENCES users(id),
    journal_entry_id                                BIGINT NULL REFERENCES journal_entries(id),
    failure_reason                                   TEXT NULL,
    notes                                             TEXT NULL,
    updated_by                                         BIGINT NULL REFERENCES users(id),
    created_at                                          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                            TIMESTAMPTZ NULL,
    CONSTRAINT uq_vp_number UNIQUE (company_id, payment_number)
);
CREATE INDEX idx_vp_vendor ON vendor_payments(vendor_id);
CREATE INDEX idx_vp_company_status ON vendor_payments(company_id, status);

CREATE TABLE vendor_payment_allocations (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    vendor_payment_id        BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
    bill_id                    BIGINT NOT NULL REFERENCES bills(id),
    allocated_amount             NUMERIC(19,4) NOT NULL CHECK (allocated_amount > 0),
    discount_taken                 NUMERIC(19,4) NOT NULL DEFAULT 0,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_vpa_bill_payment UNIQUE (vendor_payment_id, bill_id)
);
CREATE INDEX idx_vpa_payment ON vendor_payment_allocations(vendor_payment_id);
CREATE INDEX idx_vpa_bill ON vendor_payment_allocations(bill_id);

-- ============================================================
-- PROCUREMENT CONTRACTS
-- ============================================================
CREATE TABLE procurement_contracts (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    contract_number           VARCHAR(32) NOT NULL,
    vendor_id                   BIGINT NOT NULL REFERENCES vendors(id),
    title                         VARCHAR(255) NOT NULL,
    contract_type                   VARCHAR(24) NOT NULL
                                    CHECK (contract_type IN ('blanket_po','fixed_price_agreement','framework_agreement',
                                                               'service_level_agreement','consignment')),
    start_date                       DATE NOT NULL,
    end_date                          DATE NOT NULL,
    auto_renew                          BOOLEAN NOT NULL DEFAULT false,
    renewal_notice_days                   INT NOT NULL DEFAULT 30,
    currency_code                          VARCHAR(3) NOT NULL DEFAULT 'KWD',
    total_committed_value                    NUMERIC(19,4) NULL,
    total_released_value                       NUMERIC(19,4) NOT NULL DEFAULT 0,
    payment_terms                               VARCHAR(32) NOT NULL DEFAULT 'net_30',
    price_list_id                                BIGINT NULL REFERENCES price_lists(id),
    minimum_order_value                            NUMERIC(19,4) NULL,
    sla_terms                                       JSONB NOT NULL DEFAULT '{}',
    renewed_from_contract_id                          BIGINT NULL REFERENCES procurement_contracts(id),
    terminated_reason                                   TEXT NULL,
    status                                               VARCHAR(20) NOT NULL DEFAULT 'draft'
                                                          CHECK (status IN ('draft','pending_approval','active',
                                                                              'expired','terminated','renewed','cancelled')),
    created_by                                            BIGINT NULL REFERENCES users(id),
    updated_by                                             BIGINT NULL REFERENCES users(id),
    created_at                                              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                               TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                                                TIMESTAMPTZ NULL,
    CONSTRAINT uq_pc_number UNIQUE (company_id, contract_number),
    CONSTRAINT chk_pc_dates CHECK (end_date > start_date)
);
CREATE INDEX idx_pc_vendor ON procurement_contracts(vendor_id);
CREATE INDEX idx_pc_company_status ON procurement_contracts(company_id, status);
CREATE INDEX idx_pc_end_date ON procurement_contracts(end_date) WHERE status = 'active';

CREATE TABLE procurement_contract_items (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    procurement_contract_id   BIGINT NOT NULL REFERENCES procurement_contracts(id) ON DELETE CASCADE,
    line_number                 INT NOT NULL,
    product_id                    BIGINT NOT NULL REFERENCES products(id),
    contracted_unit_price           NUMERIC(19,4) NOT NULL,
    unit_of_measure_id                BIGINT NOT NULL REFERENCES units_of_measure(id),
    minimum_commitment_quantity         NUMERIC(18,4) NULL,
    quantity_released_to_date             NUMERIC(18,4) NOT NULL DEFAULT 0,
    price_valid_from                        DATE NOT NULL,
    price_valid_to                            DATE NULL,
    created_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_pci_line UNIQUE (procurement_contract_id, line_number)
);
CREATE INDEX idx_pci_contract ON procurement_contract_items(procurement_contract_id);
CREATE INDEX idx_pci_product ON procurement_contract_items(product_id);
```

**Relationships summary.** `purchase_requests (1) → (N) purchase_request_items`; `purchase_request_items (1) → (N) purchase_order_items` (via `source_pr_item_id`, tracking sourcing coverage); `rfqs (1) → (N) rfq_items`, `rfqs (1) → (N) rfq_responses`; `rfq_items (1) → (N) purchase_order_items` (via `source_rfq_item_id`); `purchase_orders (1) → (N) purchase_order_items`; `purchase_order_items (1) → (N) goods_receipt_items`; `goods_receipts (1) → (N) goods_receipt_items`; `goods_receipt_items (1) → (0..N) quality_inspections`; `goods_receipt_items (1) → (0..N) bill_items` and `(0..N) debit_note_items`; `bills (1) → (N) bill_items`; `bills (1) → (0..N) vendor_payment_allocations`; `vendor_payments (1) → (N) vendor_payment_allocations`; `vendors (1) → (N) procurement_contracts`; `procurement_contracts (1) → (N) procurement_contract_items` and `(0..N) purchase_orders`.

**Versioning.** PO amendments (BR-PO-3) use `amends_po_id`/`superseded_by` self-references rather than a separate history table, keeping the full amendment chain queryable with a single recursive CTE (`WITH RECURSIVE po_chain AS (...)`). Contract renewals use the equivalent `renewed_from_contract_id` self-reference. PR revisions use `revision_number` on the same row rather than a chain of rows, since a PR revision is a pre-commitment edit, not a post-commitment amendment — no external party has seen or relied on a specific PR version.

**History/audit note.** No table above stores a denormalized "previous value" column; all field-level history lives in the platform's shared `audit_logs` table (Audit Logs section), keeping this schema's tables lean and avoiding the anti-pattern of duplicating audit concerns per-table.

# API

All endpoints are versioned under `/api/v1/purchasing/`, require a Sanctum/JWT bearer token, are scoped to the active company via the `X-Company-Id` header, and return the standard platform envelope (`success`, `data`, `message`, `errors`, `meta.pagination`, `request_id`, `timestamp`). Default pagination is 25 per page with cursor pagination support (`?cursor=`); every list endpoint supports `?filter[field]=value`, `?sort=-created_at`, and `?search=`.

**Endpoint table.**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/purchasing/purchase-requests` | `purchasing.request.read` | List PRs, filterable by status, requester, department, date range |
| POST | `/purchasing/purchase-requests` | `purchasing.request.create` | Create a draft PR with line items |
| GET | `/purchasing/purchase-requests/{id}` | `purchasing.request.read` | Retrieve one PR with items and approval history |
| PUT | `/purchasing/purchase-requests/{id}` | `purchasing.request.update` | Update a draft PR |
| POST | `/purchasing/purchase-requests/{id}/submit` | `purchasing.request.submit` | Submit for approval |
| POST | `/purchasing/purchase-requests/{id}/approve` | `purchasing.request.approve` | Record an approval action at the caller's step |
| POST | `/purchasing/purchase-requests/{id}/reject` | `purchasing.request.approve` | Reject with a reason |
| DELETE | `/purchasing/purchase-requests/{id}` | `purchasing.request.cancel` | Cancel (soft-delete-equivalent status change) |
| POST | `/purchasing/rfqs` | `purchasing.rfq.create` | Create an RFQ, optionally merging PR line items |
| POST | `/purchasing/rfqs/{id}/issue` | `purchasing.rfq.issue` | Send the RFQ to invited vendors |
| POST | `/purchasing/rfqs/{id}/responses` | `purchasing.rfq.respond` (vendor portal) or `purchasing.rfq.enter_response` (manual entry) | Record a vendor's quotation |
| GET | `/purchasing/rfqs/{id}/comparison` | `purchasing.rfq.read` | Return the computed comparison grid with AI ranking overlay |
| POST | `/purchasing/rfqs/{id}/award` | `purchasing.rfq.award` | Award one or more vendors/lines, spawning PO(s) |
| GET | `/purchasing/purchase-orders` | `purchasing.order.read` | List POs, filterable by status, vendor, branch, date |
| POST | `/purchasing/purchase-orders` | `purchasing.order.create` | Create a PO (direct, from RFQ award, or contract release) |
| PUT | `/purchasing/purchase-orders/{id}` | `purchasing.order.update` | Update a `draft` PO |
| POST | `/purchasing/purchase-orders/{id}/submit` | `purchasing.order.submit` | Submit for approval |
| POST | `/purchasing/purchase-orders/{id}/approve` | `purchasing.order.approve` | Approve at caller's step |
| POST | `/purchasing/purchase-orders/{id}/send` | `purchasing.order.send` | Transmit to vendor (email/portal) |
| POST | `/purchasing/purchase-orders/{id}/amend` | `purchasing.order.amend` | Create an amendment revision |
| POST | `/purchasing/purchase-orders/{id}/cancel` | `purchasing.order.cancel` | Cancel with reason |
| POST | `/purchasing/goods-receipts` | `warehouse.receiving.create` | Create/post a GR against a PO |
| GET | `/purchasing/goods-receipts/{id}` | `warehouse.receiving.read` | Retrieve a GR with lines |
| POST | `/purchasing/goods-receipts/{id}/reverse` | `warehouse.receiving.reverse` | Reverse an unreferenced posted GR |
| POST | `/purchasing/quality-inspections` | `purchasing.inspection.create` | Record an inspection result against a GR line |
| POST | `/purchasing/quality-inspections/{id}/approve-deviation` | `purchasing.inspection.approve_deviation` | Approve an accept-with-deviation disposition |
| POST | `/purchasing/purchase-returns` (alias for `debit-notes` with `goods_receipt_id`) | `purchasing.return.create` | Draft a return/debit note |
| POST | `/purchasing/debit-notes/{id}/send` | `purchasing.return.send` | Transmit the debit note to the vendor |
| POST | `/purchasing/debit-notes/{id}/apply` | `purchasing.return.apply` | Apply remaining amount against a future bill |
| GET | `/purchasing/bills` | `purchasing.bill.read` | List bills, filterable by status, vendor, match_status, due date |
| POST | `/purchasing/bills` | `purchasing.bill.create` | Enter a vendor bill (manual, OCR-assisted, or portal-submitted) |
| POST | `/purchasing/bills/{id}/match` | `purchasing.bill.match` | Run/re-run the three-way match |
| POST | `/purchasing/bills/{id}/override-variance` | `purchasing.bill.override_variance` | Accept a flagged variance with reason |
| POST | `/purchasing/bills/{id}/approve` | `purchasing.bill.approve` | Approve for posting |
| POST | `/purchasing/bills/{id}/post` | `purchasing.bill.post` | Post the bill's journal entry |
| POST | `/purchasing/vendor-payments` | `purchasing.payment.create` | Draft a payment with bill allocations |
| POST | `/purchasing/vendor-payments/{id}/approve` | `purchasing.payment.approve` | Approve at caller's step |
| POST | `/purchasing/vendor-payments/{id}/release` | `purchasing.payment.release` | Release for processing (maker≠checker enforced) |
| POST | `/purchasing/procurement-contracts` | `purchasing.contract.create` | Create a contract |
| POST | `/purchasing/procurement-contracts/{id}/activate` | `purchasing.contract.approve` | Activate an approved contract |
| POST | `/purchasing/procurement-contracts/{id}/renew` | `purchasing.contract.renew` | Create a renewal linked to the original |
| GET | `/purchasing/reports/{report-key}` | `reports.export` + area-specific read permission | Retrieve any report listed in the Reports section |

**Bulk operations.** `POST /purchasing/purchase-order-items/bulk-receive` accepts an array of `{purchase_order_item_id, quantity_received, bin_location_id, batch_number}` and creates a single GR spanning multiple PO lines in one call (the common case for a truck delivering a full PO in one drop). `POST /purchasing/bills/bulk-approve` accepts an array of bill ids already `matched` (never `variance_flagged`) and approves them in one transaction, each still individually audit-logged. `POST /purchasing/vendor-payments/bulk-allocate` builds one payment against many bills for a vendor statement run.

**Example — create a Purchase Order (request).**
```json
POST /api/v1/purchasing/purchase-orders
{
  "vendor_id": 4821,
  "warehouse_id": 12,
  "po_type": "standard",
  "order_date": "2026-07-16",
  "expected_delivery_date": "2026-07-30",
  "payment_terms": "net_30",
  "currency_code": "USD",
  "exchange_rate": 0.307500,
  "rfq_id": 991,
  "items": [
    {
      "source_rfq_item_id": 5510,
      "product_id": 30122,
      "description": "A4 Copy Paper 80gsm, 500-sheet ream",
      "quantity_ordered": 2000,
      "unit_of_measure_id": 3,
      "unit_price": 2.15,
      "discount_percent": 0,
      "tax_code_id": 7,
      "warehouse_id": 12,
      "cost_center_id": 40
    }
  ]
}
```

**Example — create a Purchase Order (response, 201).**
```json
{
  "success": true,
  "data": {
    "id": 88231,
    "po_number": "PO-2026-004417",
    "vendor_id": 4821,
    "status": "draft",
    "approval_status": "not_started",
    "currency_code": "USD",
    "exchange_rate": 0.307500,
    "subtotal_amount": 4300.00,
    "discount_amount": 0.00,
    "tax_amount": 215.00,
    "total_amount": 4515.00,
    "total_amount_base": 1388.36,
    "items": [
      {
        "id": 210904,
        "line_number": 1,
        "product_id": 30122,
        "quantity_ordered": 2000,
        "unit_price": 2.15,
        "line_amount": 4300.00,
        "quantity_received": 0,
        "line_status": "open"
      }
    ]
  },
  "message": "Purchase order created as draft.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9f2e1c4a-6b3d-4e2f-a1b0-3c8d5e7f9a10",
  "timestamp": "2026-07-16T12:04:02Z"
}
```

**Example — post a Goods Receipt (request).**
```json
POST /api/v1/purchasing/goods-receipts
{
  "purchase_order_id": 88231,
  "warehouse_id": 12,
  "receipt_date": "2026-07-29",
  "vendor_delivery_note_number": "DN-77213",
  "items": [
    {
      "purchase_order_item_id": 210904,
      "quantity_received": 2000,
      "bin_location_id": 340,
      "condition": "good"
    }
  ]
}
```

**Example — three-way match variance error (Bill submission, 409).**
```json
{
  "success": false,
  "data": null,
  "message": "Bill could not be matched: quantity and price variance detected.",
  "errors": [
    {
      "code": "match_variance",
      "field": "items[0].quantity_billed",
      "detail": "Billed quantity 2050 exceeds goods-receipt accepted quantity 2000 by 50 (2.50%), outside the configured 0% tolerance.",
      "goods_receipt_item_id": 460112
    },
    {
      "code": "match_variance",
      "field": "items[0].unit_price",
      "detail": "Billed unit price 2.25 exceeds PO unit price 2.15 by 4.65%, outside the configured 2% tolerance.",
      "purchase_order_item_id": 210904
    }
  ],
  "meta": { "pagination": null },
  "request_id": "1a7b3d9e-5c2f-4a8b-9e0d-2f4c6a8b1d3e",
  "timestamp": "2026-07-29T09:15:44Z"
}
```

**Example — release a Vendor Payment (maker-checker violation, 403).**
```json
{
  "success": false,
  "data": null,
  "message": "Payment cannot be released by the same user who created it above the two-person threshold.",
  "errors": [
    { "code": "maker_checker_violation", "detail": "User 118 created and attempted to release payment VP-2026-000902 (total 1,240.000 KWD, above the 500.000 KWD threshold)." }
  ],
  "meta": { "pagination": null },
  "request_id": "6d4f2a1b-8e3c-4b7d-a1f0-9c5e2d8b4a6f",
  "timestamp": "2026-07-16T14:02:11Z"
}
```

**Error codes used across this module.** `400` malformed request body; `401` missing/invalid token; `403` valid token but lacking the required permission, or a business-rule authorization failure (maker-checker, cross-branch); `404` document not found or not visible to the caller's company; `409` a state-conflict (over-receipt, duplicate vendor bill reference, match variance, exceeding contract commitment); `422` field-level validation failure (`errors[]` populated per field); `429` rate limited; `500` internal error (logged with `request_id` for support correlation).

# Reports

| Report | Purpose | Key Dimensions | Primary Consumers |
|---|---|---|---|
| Purchase Dashboard | At-a-glance open PRs pending approval, open POs by status, bills due this week, YTD spend vs. budget | Company, branch, date range | Owner, CFO, Purchasing Manager |
| Purchases by Vendor | Ranked spend, average price trend, on-time rate, quality score per vendor | Vendor, period | Purchasing Manager, CFO |
| Purchases by Product | Quantity and spend per product/category, price trend across vendors for the same product | Product, category, period | Purchasing Manager, Inventory Manager |
| Purchase Trends | Month-over-month and year-over-year spend trend, seasonality overlay | Period, category, branch | CFO, Purchasing Manager |
| Receiving Reports | GRs posted per warehouse per period, on-time vs. late receipts, inspection pass/fail rates | Warehouse, vendor, period | Warehouse Manager, Purchasing Manager |
| Outstanding Orders | Open PO lines not yet fully received, with expected delivery date and days overdue | PO, vendor, product | Purchasing Manager, Warehouse Manager |
| Vendor Performance | Composite scorecard: on-time delivery %, quality defect rate, price competitiveness, responsiveness | Vendor, period | Purchasing Manager, CFO, Auditor |
| Purchase Forecast | AI-generated 30/60/90-day demand and predicted-stockout view feeding Purchase Prediction | Product, warehouse | Purchasing Manager, Inventory Manager |
| Spend Analysis | Category concentration, maverick (non-PO) spend %, savings vs. RFQ baseline, top-vendor dependency risk | Category, vendor, cost center, project | CFO, Owner, Auditor |

Every report supports export to XLSX/CSV/PDF (`reports.export` permission), scheduled delivery via `report_schedules` (e.g., a weekly Outstanding Orders digest emailed to the Purchasing Manager every Sunday), and a `?currency=` override per Multi Currency. Each report's underlying `report_definitions` row declares its base SQL/query plan so AI's Spend Analysis and Purchase Optimization agents consume the identical, single source of truth a human viewing the report sees — there is no separate "AI data pull" query path.

# Permissions

| Permission Key | Owner | Admin | Purchasing Manager | Purchasing Employee | Finance | Warehouse | Auditor | AI Agent |
|---|---|---|---|---|---|---|---|---|
| `purchasing.request.create` | ✔ | ✔ | ✔ | ✔ | — | — | — | draft-only |
| `purchasing.request.approve` | ✔ | ✔ | ✔ | — | ✔ (tier-dependent) | — | — | — |
| `purchasing.order.create` | ✔ | ✔ | ✔ | ✔ (below threshold) | — | — | — | — |
| `purchasing.order.approve` | ✔ | ✔ | ✔ | — | ✔ (tier-dependent) | — | — | — |
| `purchasing.order.send` | ✔ | ✔ | ✔ | — | — | — | — | — |
| `purchasing.order.override_price` | ✔ | ✔ | ✔ | — | — | — | — | — |
| `warehouse.receiving.create` | ✔ | ✔ | ✔ | — | — | ✔ | — | — |
| `purchasing.inspection.create` | ✔ | ✔ | ✔ | — | — | ✔ | — | — |
| `purchasing.inspection.approve_deviation` | ✔ | ✔ | ✔ | — | — | — | — | — |
| `purchasing.return.create` | ✔ | ✔ | ✔ | ✔ | — | ✔ (quality-triggered) | — | — |
| `purchasing.return.send` | ✔ | ✔ | ✔ | — | — | — | — | — |
| `purchasing.bill.create` | ✔ | ✔ | ✔ | — | ✔ | — | — | draft-only (OCR ingestion) |
| `purchasing.bill.override_variance` | ✔ | ✔ | — | — | ✔ | — | — | — |
| `purchasing.bill.approve` | ✔ | ✔ | — | — | ✔ | — | — | — |
| `purchasing.bill.post` | ✔ | ✔ | — | — | ✔ | — | — | — |
| `purchasing.payment.create` | ✔ | ✔ | — | — | ✔ | — | — | — |
| `purchasing.payment.approve` | ✔ | — | — | — | ✔ | — | — | — |
| `purchasing.payment.release` | ✔ | — | — | — | ✔ (maker≠checker) | — | — | never |
| `purchasing.payment.hold` | ✔ | ✔ | — | — | ✔ | — | ✔ | never (suggest-only) |
| `purchasing.contract.create` | ✔ | ✔ | ✔ | — | — | — | — | — |
| `purchasing.contract.approve` | ✔ | — | — | — | ✔ (as CFO) | — | — | — |
| `purchasing.*.read` (all read scopes) | ✔ | ✔ | ✔ | ✔ (own dept) | ✔ | ✔ (receiving scope) | ✔ (read-only, all) | ✔ (read-only) |
| `reports.export` | ✔ | ✔ | ✔ | — | ✔ | ✔ (receiving reports) | ✔ | — |

Default-deny applies: any permission not explicitly granted to a role is denied. The Auditor role is read-only across every table this module owns, including `audit_logs` entries about Purchasing documents, and can never hold a create/approve/release permission regardless of company configuration — this is a platform-level hard constraint, not a per-company toggle. The AI Agent identity (used for attribution on AI-originated actions like a drafted PR) never holds an approval or release permission under any circumstance; every row above marked "never" or "draft-only" is enforced at the authorization-middleware layer, not merely by UI hiding.

# Notifications

Notifications are delivered via the platform's `notifications` table and pushed in real time over Laravel Reverb where the recipient is online, falling back to a digest (see below) otherwise.

| Event | Recipient(s) | Channel/Timing |
|---|---|---|
| PR awaiting your approval | Current approval-step approver(s) | Immediate push; escalates to next-tier or an admin after `sla_hours` (default 24h, 4h for `urgent` priority) with no action |
| PR approved / rejected | Requester | Immediate |
| RFQ response deadline in 24h with responses missing | Purchasing Employee who issued the RFQ | Immediate |
| RFQ awarded / rejected | All invited vendors (external, via vendor portal/email) and internal requester | Immediate |
| PO awaiting your approval | Current approval-step approver(s) | Immediate push; same SLA-escalation pattern as PRs |
| PO sent to vendor | Requester, Purchasing Manager | Immediate |
| Goods receipt posted (partial or full) | Requester, Purchasing Employee who created the PO | Immediate |
| Quality inspection failed | Purchasing Manager, requester | Immediate, high-priority |
| Contract expiring within `renewal_notice_days` | Contract owner, Purchasing Manager | Daily digest starting at the notice window, immediate on day-of |
| Bill entered and pending match | Finance | Digest (hourly) unless `variance_flagged`, which is immediate |
| Bill variance flagged | Finance Manager | Immediate |
| Possible duplicate bill detected | Finance | Immediate, blocking banner in UI |
| Payment awaiting your approval | Current approval-step approver(s) | Immediate; same SLA-escalation pattern |
| Payment released successfully / failed | Finance, requester of the original bill | Immediate |
| Bill overdue (past `due_date`, unpaid) | Finance Manager | Daily digest |
| Vendor bank account changed | Finance Manager, Auditor | Immediate, high-priority, blocks affected draft payments per Vendor Integration |
| AI-drafted PR ready for review | The relevant department owner | Digest (daily) unless the predicted stockout is within lead-time margin, in which case immediate |
| Fraud/segregation-of-duties alert | Auditor, Finance Manager, Owner | Immediate, high-priority |

Every notification links directly to the source document and, for approval-related notifications, deep-links to the specific approval action screen rather than a generic document view.

# Security

Purchasing inherits the platform's tenant-isolation guarantee (every query is scoped by `company_id`; cross-company access is structurally impossible, not merely filtered in the UI) and layers module-specific controls on top.

- **Vendor bank-account change protection.** Any change to `vendor_bank_accounts` triggers an automatic hold on all `draft`/`pending_approval` payments for that vendor (Vendor Integration) and requires out-of-band re-verification (a documented callback to a vendor-provided phone number on file before the change, not the number in the change request itself) before the hold clears — this is the single highest-value control against vendor-impersonation/business-email-compromise payment fraud, the most common fraud vector against procurement functions in the region.
- **Maker-checker on Payments.** Enforced at the API authorization layer, not merely as a UI convention (BR-PAY-4); attempting to bypass via direct API call returns `403 maker_checker_violation` regardless of which client made the call.
- **Segregation of duties.** The same user cannot simultaneously hold `purchasing.order.create` and be the sole approver of their own PO above the lowest tier (system rejects self-approval at the API layer for any tier requiring `purchasing.order.approve` where `approved_by = created_by`); the same structural rule applies to Bills and Payments.
- **Field-level encryption.** `vendor_bank_accounts.account_number`/`iban` (read, not owned, by this module) are stored encrypted at rest by the Vendors module; Purchasing never logs or displays the full account number in any Purchasing-owned log, notification, or export — only a masked last-4 representation.
- **Attachment access control.** Vendor bill scans, contract PDFs, and inspection photos (`attachments`, polymorphic) are stored in Cloudflare R2 behind signed, time-limited URLs; a Purchasing document's attachments are only resolvable by users who can already read the parent document, never by a bare object-storage URL guess.
- **Rate limiting.** RFQ vendor-portal submission endpoints (externally reachable, unlike internal endpoints) are rate-limited per vendor-token and per IP to prevent scripted quotation-flooding or enumeration attacks against the portal.
- **AI boundary enforcement.** Every AI-agent call into the Laravel API carries a distinct `AI Agent` principal with its own scoped token whose permission set is enumerated in the Permissions table above and can never be expanded beyond `draft`-only/read-only/suggest-only capabilities by configuration — this is enforced server-side as a hard-coded ceiling on the AI Agent role, not merely a default.
- **Input validation against injection.** All `JSONB` fields accepting semi-structured input (`inspection_criteria`, `line_quotes`, `sla_terms`) are validated against a versioned JSON Schema server-side before persistence, preventing malformed or oversized payloads and providing a stable contract for any downstream consumer (reporting, AI) that reads them.

# Audit Logs

Every create, update, status-transition, approval action, and delete (soft-delete) on every table this module owns writes a row to the shared `audit_logs` table with: `company_id`, `user_id` (or `AI Agent` principal id), `action` (`create`|`update`|`approve`|`reject`|`post`|`release`|`cancel`|`soft_delete`), `auditable_type`, `auditable_id`, `old_values` (JSONB, only the changed fields), `new_values` (JSONB, only the changed fields), `reason` (nullable — mandatory for price overrides, variance overrides, deviation approvals, and cancellations), `ip_address`, `device_fingerprint`, `created_at`.

**Purchasing-specific audit guarantees.**
- Every three-way-match override (BR-BILL variance acceptance) is logged with the exact variance figures at the moment of override, not merely "variance overridden," so a later audit can reconstruct precisely what was accepted and why.
- Every PO amendment logs the full before/after line-item diff (price, quantity, tax code) between the original and the amendment, in addition to the two rows themselves being independently queryable.
- Every payment release logs both the `created_by_maker` and `released_by` user distinctly, satisfying maker-checker audit evidence without requiring a join across three tables.
- AI-originated actions (a drafted PR, a duplicate-bill flag, a fraud alert) are logged with `user_id = NULL` and an `ai_agent_name` + `confidence` + `reasoning` field, so an auditor can filter "show me everything the AI proposed this quarter and what a human did with each proposal" in one query.
- Audit log rows are themselves append-only (no `UPDATE`/`DELETE` grants on `audit_logs` for any application role, including Owner) and retained for the statutory minimum (10 years for VAT-relevant records under prevailing GCC practice, configurable upward per company/jurisdiction, never downward).

# Performance

- **Denormalized running totals.** `purchase_order_items.quantity_received/billed/returned` and `bills.amount_paid/amount_due` are maintained transactionally at write time specifically so that "outstanding orders" and "unpaid bills" list queries never require a runtime aggregation join across `goods_receipt_items`/`vendor_payment_allocations` at read time — those queries must return in under 200ms (p95) for a company with 50,000 open PO lines.
- **Partial indexes on hot statuses.** `idx_bill_due_date` and `idx_pc_end_date` (Database Design) are partial indexes scoped to the statuses that actually drive operational queries (`posted`/`partially_paid`, `active`), keeping index size proportional to the operationally relevant subset rather than the full historical table as a company's transaction volume grows into the millions of rows over years of operation.
- **Comparison grid computation.** The RFQ comparison grid (GET `/rfqs/{id}/comparison`) is computed on read from `rfq_responses.line_quotes` JSONB rather than materialized, since RFQs are bounded in size (typically under 50 lines × 10 vendors) and the AI overlay recomputation cost is negligible at that scale; for the documented edge case of very high-line-count RFQs, engineering teams are directed (Database Design) to normalize `rfq_response_items` into its own indexed table rather than scaling the JSONB approach past its comfortable range.
- **Bulk receiving and bulk approval** (API section) are implemented as single-transaction, single-round-trip endpoints specifically to avoid N sequential API calls (and N sequential domain-event publications) when a warehouse clerk is keying a 40-line delivery or Finance is clearing a batch of same-vendor bills — this is both a UX and a database-connection-pressure optimization.
- **Nightly aggregation jobs**, not inline computation, produce `vendors.on_time_delivery_rate`, `quality_score`, and the Supplier Scorecard (AI Responsibilities → Supplier Performance) — these are read-heavy, write-light analytics that would otherwise force an expensive cross-table aggregation on every PO/Bill save if computed synchronously; the nightly job writes the computed value once and every subsequent read (RFQ ranking, Vendor Performance report) is a cheap column read.
- **Archival partitioning.** `bills`, `goods_receipts`, and `vendor_payments` are range-partitioned by `created_at` (yearly partitions) once a company's row count for any of those tables crosses 5 million rows, keeping the hot (current fiscal year) partition small and query-fast while older partitions remain fully queryable for audit/reporting without degrading current-year performance — this is a platform-level partitioning convention, not a Purchasing-specific decision, applied consistently with how Sales and Banking partition their equivalent high-volume tables.

# Edge Cases

- **Vendor delivers more than ordered, within tolerance.** Handled by BR-PO-5/BR-GR-1's `over_receipt_tolerance_percent`; a delivery beyond tolerance is rejected at GR posting and the excess must either be returned unposted at the dock or the PO amended first to accommodate it — the system never silently accepts an out-of-tolerance over-delivery.
- **Vendor delivers less than ordered and never delivers the remainder.** The PO line stays `partially_received` indefinitely; a Purchasing Manager can either wait, escalate to the vendor, or formally close the line short via `purchasing.order.force_close` (BR-PO-7), which sets `line_status = 'closed'` with a captured reason and excludes the undelivered balance from Outstanding Orders going forward without pretending it was received.
- **Bill arrives before the Goods Receipt (common with vendor invoicing on dispatch rather than on delivery).** The Bill is accepted in `pending_match` but cannot progress past matching until a corresponding GR exists; it sits correctly visible in a "bills awaiting receipt" operational filter rather than blocking Finance from at least recording the vendor's claim for cash-flow-forecast purposes.
- **Goods Receipt exists but the vendor never bills (a common Gulf SME reality with informal/slow vendor invoicing).** The GR/IR clearing account carries an open balance indefinitely; a scheduled report (Outstanding Orders / a dedicated GR/IR aging view) surfaces any clearing balance older than a configurable threshold (default 45 days) to Finance for follow-up — the balance is never written off automatically.
- **Partial quality inspection failure on a batch/serial-tracked product.** Only the specific failed serials/the failed portion of the batch is rejected and routed to a return; the passed portion proceeds normally, and the GR line's `quantity_accepted`/`quantity_rejected` split (chk_gri_accept constraint) keeps both quantities individually traceable to the same physical delivery.
- **Currency devaluation/appreciation between PO and Bill on a large import order.** The realized exchange gain/loss (Multi Currency) can be material; the system never nets it into Inventory cost after the goods have already been received and costed — Inventory cost is fixed at GR-time `received_unit_cost`, and all subsequent FX movement is isolated to the Realized Exchange Gain/Loss account, keeping inventory valuation stable and auditable independent of later currency movement.
- **A PO is cancelled after partial receipt.** Only the un-received line balance is cancelled (`line_status = 'cancelled'` for the remainder); quantity already received, inspected, and possibly billed is entirely unaffected and continues its normal lifecycle — cancellation is prospective, never retroactive.
- **Duplicate vendor onboarding creates two vendor records for the same real-world supplier.** Purchasing does not de-duplicate `vendors` itself (that is the Vendors module's responsibility), but the Fraud Detection agent's spend-concentration and pattern analysis flags near-identical vendor names/bank accounts with materially split spend as a secondary detection signal, since duplicate vendors both obscure true spend concentration and can be an intentional fraud pattern.
- **A Purchase Request is raised for a product that has since been discontinued (`products.status = 'inactive'`).** The PR line can still be created (a company may be buying out remaining stock or fulfilling a legacy commitment) but is flagged with a non-blocking warning; the resulting RFQ/PO for a discontinued product is permitted but excluded from the AI Demand Forecasting agent's future-forecast baseline, so a one-off legacy purchase never distorts an active reorder-point calculation.
- **A company operates in a jurisdiction transitioning into VAT during an open PO's life (e.g., a PO raised pre-VAT-implementation, received post-implementation).** The Bill's `tax_code_id` resolves against the tax regime in effect at `bill_date`, not `order_date` (Tax Integration's resolution-order rule is stamped at document-creation time per document, so a PO created pre-VAT correctly carries no tax expectation while its later Bill correctly picks up the new regime) — this is a deliberate, tested transition path rather than an assumption that tax regimes are static for a Gulf-market platform.
- **Two Bills reference the same Goods Receipt line for a split shipment invoiced separately by the vendor.** Fully supported (BR-BILL-1 checks cumulative billed quantity across all bills, not "the" bill), and the Duplicate Bill Detection agent is specifically tuned to distinguish this legitimate pattern (different `vendor_bill_reference`, complementary rather than overlapping quantities, same GR) from a true duplicate (same or near-same quantity billed twice).

# Future Improvements

- **Vendor self-service portal enhancements.** Full PO acknowledgment workflow (vendor confirms/rejects/counter-proposes PO terms directly in-portal rather than via email), ASN (Advance Shipping Notice) submission that pre-populates a Goods Receipt draft before the truck arrives, and vendor-visible payment-status tracking to reduce inbound "where's my payment" inquiry volume on Finance.
- **Automated three-way match via Document AI end-to-end.** Extending current OCR-assisted Bill entry into a fully automated pipeline where a PO-matching Bill received by email is drafted, matched, and — if within tolerance and below a configurable auto-post ceiling — routed straight to `pending_approval` with zero manual keying, while anything outside tolerance or above the ceiling still requires full manual review exactly as today.
- **Dynamic discounting marketplace.** Let Finance offer a vendor an accelerated payment (before `due_date`) in exchange for a larger early-payment discount than the vendor's standard terms, computed and negotiated algorithmically within Treasury-set cash-availability bounds — an extension of the existing `discount_taken` mechanism into an active cash-deployment tool rather than a passive terms-capture field.
- **Supplier risk scoring against external data.** Incorporating external signals (corporate registry status, sanctions-list screening, credit-bureau signals where available in-market) into `vendors.risk_rating` alongside the currently internal-only performance signals, tightening the Fraud Detection agent's baseline for newly onboarded, thinly-transacted vendors where internal history alone is sparse.
- **Category management and strategic sourcing.** A dedicated category-level view (grouping vendors, contracts, and spend by `product_categories` rather than by individual PO) to support formal strategic-sourcing cycles for large Gulf group companies running periodic (e.g., annual) category-wide re-tendering rather than ad hoc RFQs per need.
- **Consignment inventory support.** `procurement_contracts.contract_type = 'consignment'` is modeled today at the contract level but the full consignment stock-ownership-transfer-at-consumption flow (vendor-owned stock sitting in the company's warehouse, with a Bill generated automatically only as it is consumed) is a planned deeper Inventory+Purchasing joint capability, not yet fully specified in this document's Inventory Integration section.
- **Carbon/ESG spend tagging.** Optional per-line sustainability/ESG tagging (e.g., recycled-content percentage, local-sourcing flag relevant to Gulf in-country-value/Kuwaitization-adjacent procurement policy trends) feeding a future ESG reporting module, without requiring a schema migration on the core Purchasing tables (implemented via the existing `custom_fields JSONB` convention on relevant master-data/line tables).

# End of Document
