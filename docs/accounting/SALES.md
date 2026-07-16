# Sales (Order-to-Cash) — QAYD Accounting Engine

Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Sales (Order-to-Cash)
---

# Purpose

The Sales module is the order-to-cash engine of QAYD. It owns the entire commercial lifecycle of a
customer transaction — from the first expression of interest (a lead) through quotation, approval,
order confirmation, stock reservation, picking, packing, shipping, invoicing, payment collection,
returns, and refunds — and it is the exclusive origin point for every revenue-recognizing and
receivable-generating financial event in the platform. No other module is permitted to create an
`invoices` record of type `sales`, post to the Accounts Receivable control account, or recognize
revenue; Sales is the single source of truth for "how much a customer owes QAYD's tenant company,
and why."

Concretely, the Sales module is responsible for:

1. **Capturing demand.** Leads and quotations across nine channels (Retail, Wholesale, POS,
   E-Commerce, Marketplace, Phone, Subscription, B2B, B2C) are normalized into one canonical
   pipeline so a company sees a single funnel regardless of where the customer originated.
2. **Converting demand into commitment.** A quotation becomes a sales order only after pricing,
   discount, tax, and (where configured) approval rules have been deterministically applied — never
   ad-hoc.
3. **Reserving and fulfilling stock.** Sales orders trigger inventory reservation, picking, packing,
   and shipment orchestration in coordination with the Inventory and Warehouse modules, without
   Sales ever writing directly into inventory tables — it emits events and the Inventory module
   reacts.
4. **Billing and collecting cash.** Sales generates invoices from delivered (or, for services and
   subscriptions, order-confirmed) quantities, tracks the customer's Accounts Receivable exposure
   against a hard credit limit, records receipts, and allocates each receipt against one or more
   open invoices using either FIFO, oldest-first, or explicit customer-directed allocation.
5. **Reversing transactions cleanly.** Returns and refunds do not edit history — they generate their
   own signed documents (`credit_notes`, `refunds`) that reverse the original economic effect through
   new, fully-audited postings, exactly mirroring how `journal_entries` are reversed rather than
   edited once posted.
6. **Feeding Accounting, Inventory, and every downstream reporting surface** with a single, provably
   balanced stream of domain events, so the General Ledger, the Trial Balance, and every financial
   statement remain a pure derivation of what Sales (and the other transactional modules) posted —
   Sales never writes to `journal_entries` or `journal_lines` directly; it always posts through the
   Accounting Engine's event-driven posting service, which is the only writer of ledger data.
7. **Giving the AI layer a safe, bounded surface** to forecast revenue, suggest prices, recommend
   upsells and cross-sells, surface customer insights, flag fraud, score risk, and catch duplicate
   leads/customers/orders — always as suggestions with confidence and reasoning attached, never as
   autonomous writes to money-moving records.

The module must be implementable in Laravel 12 (Controller → FormRequest → Service → Repository →
Model, Repository + Service pattern, no business logic in controllers) against PostgreSQL, consumed
by a Next.js 15 / React 19 frontend, and observable/analyzable by the FastAPI AI layer through the
Laravel API only.

# Vision

QAYD's Sales module exists so that a Gulf SME — a Kuwait City electronics retailer with a wholesale
arm, a Riyadh e-commerce brand selling on its own storefront and on a marketplace, or a Dubai B2B
distributor invoicing on 30/60/90-day terms — can run every sale, in every channel, through one
system of record, in Arabic or English, in KWD/SAR/AED/USD, without stitching together a POS
system, a spreadsheet CRM, a shipping tracker, and a separate accounting package.

The long-term vision has four pillars:

- **One funnel, many doors.** Whether a sale starts as a walk-in POS transaction, an inbound phone
  call, a marketplace order pulled in via API, or a self-service e-commerce checkout, it lands in
  the same `leads` → `sales_quotations` → `sales_orders` pipeline, tagged with its channel, so
  management sees one number for "total pipeline" and "total revenue," not four disconnected ones.
- **Deterministic commercial logic.** Pricing, discounting, tax, and credit rules are configuration
  (`price_lists`, `price_rules`, `discounts`, `coupons`, `promotions`, `tax_rates`, customer credit
  limits), not code scattered across screens. Change a rule once; every channel obeys it
  immediately and identically.
- **Accounting-grade integrity from day one.** Every invoice posts a balanced journal entry the
  moment it is confirmed; every delivery posts a COGS/inventory entry; every receipt posts a
  cash/bank-to-AR entry; every credit note reverses the original entry's effect. A Sales module that
  cannot reconcile to the cent against the General Ledger is not shippable — this module is designed
  so that reconciliation is structurally guaranteed, not hoped for.
- **AI as a force multiplier for a lean sales team.** A five-person Gulf SME cannot afford a
  dedicated sales-ops analyst. QAYD's AI layer plays that role: it forecasts next month's revenue,
  suggests the right price and discount for a quotation, nudges a rep toward an upsell before they
  send it, flags the invoice that looks like a duplicate before it goes out, and scores a new
  customer's credit risk before the first order is confirmed — always visibly, always with a reason,
  never by silently changing a number.

Within three years, the ambition is for the Sales module to be the reason a Gulf distributor with
50 sales staff across three countries can close the books in two days instead of two weeks, because
every sale — regardless of channel, currency, or country — already arrived at Accounting fully
reconciled.

# Sales Philosophy

QAYD's Sales module is built on eight non-negotiable principles:

1. **The document trail is the truth, not the balance.** A customer's AR balance is never a column
   that gets decremented; it is always the live sum of unpaid `invoices` minus applied
   `receipt_allocations` and `credit_notes`. If the derived number and a cached number ever disagree,
   the derived number wins and the cache is rebuilt.
2. **Nothing is deleted, everything is reversed.** A confirmed sales order, a posted invoice, a
   captured payment — none of these are ever hard-deleted or silently edited once they affect
   accounting or inventory. Cancellation is a state transition with a reason; correction is a new,
   linked, signed document (credit note, reversing journal entry, refund).
3. **Credit limit and stock are checked before commitment, not after.** A sales order cannot be
   confirmed if it would push the customer over their credit limit (unless an authorized override is
   recorded) or if the required stock cannot be reserved (unless back-order is explicitly allowed for
   that product/customer). Discovering these problems at invoicing time is a process failure Sales is
   designed to prevent.
4. **One event bus, many listeners, zero direct cross-module writes.** Sales never writes to
   `inventory_items`, `stock_movements`, `journal_entries`, or `journal_lines`. It emits domain
   events (`sales_order.confirmed`, `delivery.shipped`, `invoice.posted`, `payment.received`,
   `credit_note.issued`) and the Inventory module and the Accounting Engine's posting service listen
   and react. This keeps modules independently testable and prevents the classic ERP failure mode of
   "the sales screen also happens to update the ledger."
5. **Every price, discount, and tax applied to a line is explainable after the fact.** A
   `sales_order_item` stores not just the final unit price but which `price_list`, which
   `price_rule`, which `discounts`/`coupons`/`promotions`, and which `tax_rates` were applied, at
   what percentage or amount, so a customer dispute or an auditor's question can be answered from the
   row itself, without reconstructing history from logs.
6. **Approval is a workflow, not a permission check.** "Can this user create a quotation" is a
   permission. "Does this specific quotation, with this specific discount and this specific
   customer's credit exposure, need a manager's sign-off before it can become an order" is a
   workflow evaluated per-document, driven by configurable thresholds (discount %, order value,
   customer risk tier), and it produces its own audit trail.
7. **Two audiences, two speeds.** A POS cashier needs a sale to complete in under five seconds with
   minimal fields. A B2B sales manager negotiating a KD 40,000 quarterly contract needs multi-round
   quotation revisions, approval chains, and payment terms. The same data model serves both; the UI
   and default workflow differ by channel, not the schema.
8. **AI recommends, humans and policy decide.** Every AI-generated number in Sales — a suggested
   price, a forecast, a fraud score, a duplicate-lead flag — is advisory. It is stored, versioned,
   and shown with confidence and reasoning. It becomes a fact in the ledger only after a human (or an
   explicit, pre-approved automation policy) accepts it through the normal Sales workflow.

# Sales Lifecycle

The canonical order-to-cash lifecycle has thirteen stages. A given sale may skip stages that do not
apply to its channel (a POS sale skips Lead and Quotation and often Reservation/Picking/Packing; a
subscription renewal skips Lead and Quotation entirely), but the state machine below is the superset
every channel maps onto.

```
 (1) LEAD ─────────► (2) QUOTATION ─────────► (3) APPROVAL ─────────► (4) SALES ORDER
      │  qualify           draft/revise/send        (conditional:            │ confirm
      │  or disqualify     accept/reject/expire      discount/value/         │
      ▼                                              credit-tier gated)      ▼
  [disqualified]                                                       (5) RESERVATION
                                                                              │ reserve stock
                                                                              ▼
                                                                        (6) PICKING
                                                                              │ pick list, confirm qty
                                                                              ▼
                                                                        (7) PACKING
                                                                              │ pack list, package/weight
                                                                              ▼
                                                                        (8) SHIPPING
                                                                              │ carrier, tracking, POD
                                                                              ▼
                                                                        (9) INVOICE
                                                                              │ post to AR + Revenue + Tax
                                                                              │ (+ COGS/Inventory)
                                                                              ▼
                                                                       (10) PAYMENT
                                                                              │ receipt + allocation
                                                                              ▼
                                                                 ┌──── (11) RETURN (optional)
                                                                 │           │ RMA, restock/scrap
                                                                 │           ▼
                                                                 └──── (12) REFUND (optional)
                                                                              │ credit note settlement
                                                                              ▼
                                                                       (13) ARCHIVE
                                                                              (soft-delete after
                                                                               retention window)
```

**1. Lead.** A `leads` record captures a prospective customer and an expression of interest before
any commercial document exists. A lead has a `source` (channel), a `status`
(`new` → `contacted` → `qualified` → `converted` | `disqualified`), an owning sales employee
(`assigned_to`), and optional linkage to an existing `customers` row (for repeat business) or a
brand-new prospect (`customer_id` null until conversion). Leads are scored by the AI layer
(`lead_score`, `lead_score_reasoning`) but never auto-disqualified by AI — disqualification is a
human or explicit-policy action so a promising lead is never silently dropped.

**2. Quotation.** A `sales_quotations` header with `sales_quotation_items` lines is drafted from a
lead (or directly, for repeat customers) with priced, discounted, taxed lines. A quotation is
versionable: revising a sent quotation creates a new `version` on the same `quotation_number` rather
than mutating a quotation the customer has already seen, so both parties can always refer to
"version 2 of Q-2026-00042." States: `draft` → `sent` → `accepted` | `rejected` | `expired` |
`revised`. `valid_until` enforces automatic expiry.

**3. Approval.** Not every quotation/order requires approval. The workflow engine evaluates
configurable thresholds — discount percentage above X, total value above Y, customer credit-risk
tier at "high," or an explicit "always approve for new customers" policy — and if triggered, routes
the document to one or more approvers in sequence before it can proceed to Sales Order. Approval
requests, decisions, and comments are logged (see Workflow detail under Quotations and Sales Orders).

**4. Sales Order.** Accepting a quotation (or creating one directly for repeat/POS/subscription
business) produces a `sales_orders` header and `sales_order_items` lines — the customer's binding
commitment and the tenant's binding commitment to fulfill. Confirmation runs three gates
atomically: **credit-limit check** (customer's outstanding AR + this order's value ≤ credit limit,
else block or require an authorized override), **stock-availability check** (per line, unless
back-order is allowed), and **pricing/tax freeze** (the priced, discounted, taxed amounts on the
quotation are copied verbatim onto the order — a confirmed order's price does not silently drift if
a `price_list` changes tomorrow).

**5. Reservation.** Confirming a sales order emits `sales_order.confirmed`; the Inventory module
listens and creates `stock_reservations` rows against `inventory_items`, soft-earmarking stock so
two orders cannot both promise the same last unit. Reservation does not move stock — it only
prevents it from being promised elsewhere. For services and pure digital/subscription line items,
this stage is a no-op.

**6. Picking.** A warehouse worker (or an automated picking robot integration, out of scope here)
works a pick list generated from `deliveries`/`delivery_items` against `stock_reservations`,
confirming picked quantities per bin (`warehouse_bins`) via the Warehouse module. Under-picks
(partial availability) are supported and drive partial-delivery logic.

**7. Packing.** Picked quantities are consolidated into one or more physical packages per
`deliveries` header (a single sales order can ship as multiple deliveries/packages). Package
weight/dimensions are recorded to drive carrier rate calculation in the Shipping stage.

**8. Shipping.** A carrier, service level, and tracking number are attached to the delivery; a
shipping label/manifest is generated (or, for local same-day/POS pickup, this stage may be
instantaneous with `carrier = 'self_pickup'` or `'in_store'`). Proof of delivery (POD) — signature or
photo, stored via the polymorphic `attachments` table — closes the stage and flips the delivery to
`delivered`, which is the trigger event for invoicing on a delivery-based billing policy.

**9. Invoice.** An `invoices` header (type `sales`) with `invoice_items` lines is generated either
from delivered quantities (physical-goods, delivery-triggered billing), from order confirmation
(services, subscriptions, order-triggered billing), or manually. Posting an invoice is the single
most important accounting event in this module: it emits `invoice.posted`, and the Accounting
Engine's posting service creates a balanced journal entry debiting Accounts Receivable and crediting
Revenue and Output Tax (and, for goods delivered against a perpetual-inventory valuation, a paired
entry debiting COGS and crediting Inventory — see Accounting Integration).

**10. Payment.** A `receipts` header records cash/bank/card/wallet money received from the customer;
one or more `receipt_allocations` rows link that receipt to specific open `invoices`, reducing each
invoice's `balance_due`. A receipt can be partially or fully unallocated (an advance/deposit) and
allocated later. Posting a receipt debits Cash/Bank and credits Accounts Receivable.

**11. Return.** A customer-initiated or company-initiated Return Merchandise Authorization creates
a return record referencing the original `invoices`/`delivery_items`, drives a physical goods-return
flow (restock to `inventory_items` via `stock_movements`, or scrap if damaged), and is the
prerequisite for issuing a `credit_notes` document (Sales does not refund cash directly off a
return — it issues a credit note first, which may then be settled as a refund or as credit against
future invoices).

**12. Refund.** A `refunds` record settles a `credit_notes` balance in cash/bank/original-payment-
method back to the customer, or the credit note is left open as store credit against future
invoices. Posting a refund debits the credit note's offsetting revenue/AR contra-entry (already
posted when the credit note was issued) and credits Cash/Bank when cash leaves the company.

**13. Archive.** After the fiscal retention window configured for the company (statutory minimum
plus buffer — commonly 5–10 years in Gulf jurisdictions, configurable per company), fully closed
sales documents (no open balance, no open return) are eligible for archival: `deleted_at` is set
(soft delete only — see Data Rules), and the row moves out of default query scope but remains
queryable by Auditor/Owner roles and by the Accounting Engine for historical reporting. Archival
never deletes posted `journal_lines`; those remain permanently in the ledger regardless of Sales
document archival state.

# Customer Journey

The Sales module models the customer journey as five phases, each mapped to concrete system state
so that a company can answer "where is this customer in their relationship with us" from data, not
from a sales rep's memory.

**Phase 1 — Discovery & Capture.** The customer's first touch is captured as a `leads` row with a
`source` channel and (optionally) UTM/marketing-attribution metadata in `custom_fields` JSONB. For
channels with no explicit lead step (POS walk-in, marketplace order), a lead is synthesized
automatically at first-transaction time so the funnel stays complete — a `leads` row is created with
`status = 'converted'` and `converted_at = now()` in the same transaction that creates the first
`sales_orders` row, and it is tagged `is_synthetic = true` so reporting can distinguish organically
nurtured leads from immediately-converting walk-ins.

**Phase 2 — Evaluation.** One or more `sales_quotations` are issued. The AI layer's Approval
Assistant and CFO Agent may suggest an upsell/cross-sell line or a price adjustment at this stage
(see AI Responsibilities). Multi-round negotiation is modeled as quotation `version` increments, not
as new quotations, so the full negotiation history stays attached to one commercial thread.

**Phase 3 — Commitment.** A quotation is accepted and becomes a `sales_orders` row. This is the
point at which the customer relationship converts from "prospect" to "customer" if this is their
first order — `customers.first_order_date` is stamped, and `customers.customer_status` moves from
`prospect` to `active`.

**Phase 4 — Fulfillment & Billing.** Reservation → Picking → Packing → Shipping → Invoice → Payment
execute per the Sales Lifecycle. The customer's experience here is channel-specific (a POS customer
experiences stages 5–8 as an instant in-store handover; a wholesale B2B customer experiences them
over days with tracked shipments), but the underlying documents are identical in shape.

**Phase 5 — Retention & Recovery.** Post-sale, the journey branches into three tracks that the
Sales module must support concurrently for the same customer:
- **Repeat purchase.** A new quotation/order references `customers.id`; `customers.lifetime_value`,
  `customers.total_orders`, and `customers.last_order_date` are recalculated (materialized, refreshed
  on `invoice.posted`) to drive segmentation and the AI Customer Insights agent.
- **Service recovery.** A Return/Refund cycle runs per the lifecycle; `customers.return_rate` is
  tracked as a risk/quality signal, not held against the customer punitively but surfaced to the
  Fraud Detection agent if it crosses an abnormal threshold (e.g., serial-returner pattern).
- **Churn / win-back.** A customer with no order in N days (company-configurable per channel; e.g.
  45 days for subscription, 180 days for wholesale) is flagged `dormant` by a scheduled job and
  surfaced to the Reporting Agent's Customer Insights output as a win-back candidate; this is a
  read-only flag, never an automated discount action, since GCC consumer-protection norms disfavor
  automated unsolicited pricing offers without a human decision.

Journey state is exposed on the customer record as a derived `journey_stage` enum
(`lead | evaluating | committed | fulfilling | active_customer | dormant | churned`) recomputed on
every relevant event rather than stored as a mutable field a UI can drift out of sync with.

# Sales Channels

All nine channels funnel into the same `leads` / `sales_quotations` / `sales_orders` pipeline. Each
channel is a value of `sales_orders.channel` (and `leads.source`, `sales_quotations.channel`) drawn
from a fixed enum, and each channel has channel-specific defaults and constraints layered on top of
the shared schema — never a parallel schema.

```sql
CREATE TYPE sales_channel AS ENUM (
  'retail', 'wholesale', 'pos', 'ecommerce', 'marketplace',
  'phone', 'subscription', 'b2b', 'b2c'
);
```

**Retail.** In-person, in-store, non-POS-terminal sales (e.g. a showroom sale written up by staff on
a tablet, not a checkout register). Typically walk-up, single delivery = pickup, invoice generated
and paid in the same visit. Approval thresholds are usually disabled below a configurable ticket
size (e.g. under KWD 500) to keep the counter moving.

**Wholesale.** B2B bulk sales to resellers, typically against a negotiated `price_lists` tier and
payment terms (`net_30`/`net_60`/`net_90`), with multi-delivery orders (large orders shipped in
tranches as stock becomes available) and mandatory credit-limit enforcement.

**POS.** Point-of-sale terminal transactions. The fastest path through the lifecycle: Lead is
synthesized, Quotation is skipped, Sales Order and Invoice are created together in one atomic
transaction (`pos_combined_checkout = true` on the order), Reservation/Picking/Packing collapse into
an immediate stock decrement, and Payment is captured inline (cash drawer, card terminal, KNET).
POS is the only channel permitted to post an invoice with `payment_status = 'paid'` in the same
request that creates the order — every other channel separates order confirmation from payment.

**E-Commerce.** The company's own online storefront. Orders arrive via the public storefront API,
always fully paid or payment-authorized before `sales_orders.status` moves to `confirmed` (no
unpaid e-commerce order reserves stock indefinitely — an unpaid cart expires and releases any
provisional hold after a configurable timeout, default 30 minutes). Shipping is always tracked;
self-pickup is a configurable fulfillment option.

**Marketplace.** Third-party marketplace integrations (e.g. a Gulf marketplace aggregator). Orders
are pulled in via a channel-specific adapter that maps the marketplace's order payload onto a
`sales_orders`/`sales_order_items` pair with `external_order_id` and `external_channel_ref` stored
for reconciliation; marketplace commission is recorded as a `sales_order_items` deduction line (a
negative-value line referencing a "Marketplace Commission" product/service) so gross vs. net revenue
is always reconstructable. Marketplace payouts (the marketplace remits net-of-commission in a batch)
are reconciled via Banking module bank statement matching, out of this module's direct scope but
referenced under Related Modules equivalents (see Accounting Integration).

**Phone.** Sales taken by a call-center or inside-sales rep over the phone. Functionally identical
to Retail/Wholesale in document flow, distinguished for channel-mix reporting and for a
mandatory `recorded_call_reference` field (linking to a call-recording system, stored as an
attachment reference) used for dispute resolution and AI conversation-quality scoring.

**Subscription.** Recurring revenue sold as a `products` row with `product_type = 'subscription'`
and a `billing_interval` (`monthly`/`quarterly`/`annual`). A subscription sales order has
`is_recurring = true` and a `recurrence_rule` (start date, interval, end date or `null` for
open-ended); a scheduled job generates a new `invoices` row each billing cycle referencing the same
`sales_orders.id`, without a new quotation/approval cycle unless the subscription's price or terms
change. Subscription churn (cancellation) sets `sales_orders.status = 'cancelled'` with an
effective-date and stops future invoice generation without touching already-posted invoices.

**B2B.** A cross-cutting tag (not mutually exclusive with Wholesale/Phone/E-Commerce) marking that
the counterparty is a business `customers.customer_type = 'business'` — enabling contract pricing,
multi-user company accounts (`customer_contacts` with role-based ordering authority), and
purchase-order-number capture (`sales_orders.customer_po_number`) required by many B2B buyers for
their own AP matching.

**B2C.** The counterpart tag for `customers.customer_type = 'individual'` — enabling consumer
protections (return windows enforced more permissively, no purchase-order-number requirement) and
consumer-focused marketing consent fields (`marketing_opt_in`, required under Gulf data-protection
norms before promotional communication).

Channel is captured once per document, immutable after creation, and is the primary dimension for
the "Sales by Branch," "Sales by Employee," and channel-mix reports (see Reports).

# Quotations

A quotation (`sales_quotations` + `sales_quotation_items`) is a non-binding, priced, time-boxed
offer to a customer. It is the primary artifact of the Evaluation phase of the Customer Journey.

**Header fields** (beyond standard columns): `quotation_number` (company-scoped sequence, format
`QT-{branch_code}-{YYYY}-{00000}`), `customer_id`, `lead_id` (nullable — direct quotations to
existing customers skip Lead), `channel`, `sales_employee_id`, `version` (integer, starts at 1),
`parent_quotation_id` (self-referencing — points to version 1 for any revision), `status`
(`draft | sent | accepted | rejected | expired | revised | cancelled`), `valid_until` (date),
`currency_code`, `exchange_rate`, `subtotal_amount`, `discount_amount`, `tax_amount`, `total_amount`
(all NUMERIC(19,4), transaction currency), `base_currency_total_amount` (NUMERIC(19,4), converted),
`payment_terms` (`due_on_receipt | net_15 | net_30 | net_60 | net_90 | custom`), `notes`,
`internal_notes` (never shown to customer), `cost_center_id`, `project_id`.

**Line fields:** `product_id` (or `product_variant_id`), `description` (overridable free text,
defaults from product but editable per line for custom specs), `quantity` (NUMERIC(18,4)), `uom_id`,
`unit_price` (NUMERIC(19,4), pre-discount, sourced from the applicable `price_lists`/
`price_list_items` or manually overridden with `is_price_overridden` + `override_reason`),
`price_list_item_id` (nullable, the exact price-list row used, for explainability),
`discount_type` (`percentage | fixed_amount`), `discount_value`, `discount_id` (nullable FK to
`discounts`), `price_rule_id` (nullable FK to `price_rules` if a rule, not a manual discount, set the
price), `line_subtotal`, `tax_code_id`, `tax_rate_snapshot` (NUMERIC(6,3) — the rate at the time of
quoting, frozen so a later tax-rate change never silently reprices a sent quotation), `tax_amount`,
`line_total`, `sort_order`.

**Workflow.**

```
draft ──(send)──► sent ──(customer accepts)──► accepted ──► [Sales Order created]
  │                 │
  │                 ├──(customer rejects)──► rejected
  │                 ├──(valid_until passes, cron)──► expired
  │                 └──(rep edits a sent quote)──► revised ──► new version in draft
  └──(rep cancels before sending)──► cancelled
```

A quotation moves `sent → accepted` only through an explicit customer-facing acceptance action
(portal click-through, or a rep recording verbal/written acceptance with `accepted_via` and
`accepted_by_name` captured for audit) — never implicitly by, say, an order being created that
merely references it. Every version keeps the same `quotation_number`; only `version` increments,
and `sales_quotations.is_latest_version` is a generated/maintained boolean so UIs default to showing
the latest without a subquery.

**Approval gating** (see also Sales Lifecycle §3 and Sales Orders below): a quotation whose
computed `discount_amount / subtotal_amount` exceeds the company's configured
`max_unapproved_discount_pct`, or whose `total_amount` exceeds `max_unapproved_quote_value`, or
whose customer's `credit_risk_tier = 'high'`, is created with `approval_status = 'pending'` and
cannot transition to `sent` until approved. Approval requests, approver, decision, timestamp, and
comment are stored on a shared `approval_requests` polymorphic table
(`approvable_type = 'sales_quotations'`, `approvable_id`), reused identically by Sales Orders.

**AI assist at this stage:** the CFO Agent proposes a price/discount band (see AI Responsibilities);
the Approval Assistant proposes upsell/cross-sell lines; the Fraud Detection agent flags a quotation
whose discount pattern resembles known abuse (e.g. a rep repeatedly discounting to a personally
connected customer). All three surface as suggestions attached to the quotation, never auto-applied.

# Sales Orders

A sales order (`sales_orders` + `sales_order_items`) is the customer's and the company's binding
commitment. It is created either by accepting a `sales_quotations` (copying header/line data
verbatim, freezing price/discount/tax) or directly (POS, subscription renewal, repeat-order
shortcut for an existing customer with no negotiation needed).

**Header fields:** `order_number` (`SO-{branch_code}-{YYYY}-{00000}`), `quotation_id` (nullable),
`customer_id`, `channel`, `sales_employee_id`, `status`
(`draft | pending_approval | confirmed | reserved | picking | packed | shipped | partially_invoiced
| invoiced | cancelled | closed`), `order_date`, `requested_delivery_date`, `promised_delivery_date`,
`customer_po_number` (nullable, common for B2B), `billing_policy`
(`on_delivery | on_order_confirmation | milestone | recurring`), `payment_terms`, `currency_code`,
`exchange_rate`, `subtotal_amount`, `discount_amount`, `tax_amount`, `total_amount`,
`base_currency_total_amount`, `credit_check_status` (`passed | overridden | failed`),
`credit_override_by`, `credit_override_reason`, `is_recurring`, `recurrence_rule` (JSONB: interval,
next_run_date, end_date), `pos_combined_checkout` (boolean), `warehouse_id` (default fulfillment
warehouse), `shipping_address_id` (FK `customer_addresses`), `billing_address_id`,
`cost_center_id`, `project_id`.

**Line fields:** mirrors `sales_quotation_items` (`product_id`, `product_variant_id`, `description`,
`quantity`, `uom_id`, `unit_price`, `discount_type`/`discount_value`/`discount_id`/`price_rule_id`,
`tax_code_id`, `tax_rate_snapshot`, `tax_amount`, `line_subtotal`, `line_total`) plus fulfillment
tracking: `quantity_reserved`, `quantity_picked`, `quantity_shipped`, `quantity_invoiced`,
`quantity_returned` (all NUMERIC(18,4), each monotonically progressing 0 → `quantity` across the
lifecycle and independently auditable per line for partial-fulfillment scenarios), `warehouse_id`
(line-level override of the header default, for split-warehouse fulfillment), `is_backorder`
(boolean, set when reservation could not fully satisfy the line and back-order was allowed).

**Confirmation gate (draft/pending_approval → confirmed).** Confirming a sales order is the single
most consequential state transition before invoicing, and it is atomic — it either fully succeeds
or fully rolls back:

1. **Approval check.** If `approval_status != 'approved'` and the order meets an approval-required
   threshold (same rule set as Quotations, evaluated again at order level because an order can be
   created directly without a quotation), reject with `409 Conflict` and create an
   `approval_requests` row.
2. **Credit check.** Compute `customers.outstanding_ar_balance` (sum of unpaid `invoices.balance_due`
   for that customer) `+ sales_orders.total_amount` (this order, base currency) and compare to
   `customers.credit_limit`. If it exceeds the limit: block, unless a user holding
   `sales.credit.override` permission supplies `credit_override_reason`, in which case
   `credit_check_status = 'overridden'` and the override is logged to `audit_logs`.
3. **Stock check.** For every line where the product is tracked (`products.is_stock_tracked = true`)
   and `is_backorder` is not pre-authorized, verify available-to-promise quantity
   (`inventory_items.quantity_on_hand - inventory_items.quantity_reserved`) at the target warehouse
   covers `quantity`. Insufficient stock either blocks the whole order (default), blocks only the
   short line while confirming the rest (`allow_partial_confirmation = true`), or auto-flags the
   short line `is_backorder = true` if the product/customer allows back-orders.
4. **Freeze pricing.** Copy final unit price, discount, and tax fields from the quotation (if any)
   or recompute from current `price_lists`/`price_rules`/`tax_rates` (if created directly) and store
   them on the order line — after this point, changes to price lists or tax rates never retroactively
   alter a confirmed order.
5. **Emit `sales_order.confirmed`.** This is the event the Inventory module consumes to create
   `stock_reservations` (Reservation stage).

**Amendment after confirmation.** A confirmed order's lines cannot be silently edited. Adding a line,
changing a quantity, or cancelling a line on a confirmed order requires an **order amendment**: a
new `sales_order_amendments` audit row capturing the delta (old values, new values, reason, actor),
applied only if the delta re-passes the credit and stock checks above. Cancelling an entire confirmed
order before any delivery releases its `stock_reservations` and sets `status = 'cancelled'`; if any
delivery has already occurred, full cancellation is disallowed and the correct path is a partial
Return.

**AI assist:** the Forecast Agent consumes confirmed `sales_orders` as its primary revenue-
recognition-timing input (see AI Responsibilities); the Fraud Detection agent scores each
confirmation attempt in real time (velocity of orders from a new customer, mismatched
billing/shipping geography, unusual round-number pricing) and can require step-up approval but
never blocks confirmation unilaterally — it downgrades the automatic-approval path to a
manual-approval path.

# Deliveries

A delivery (`deliveries` + `delivery_items`) represents one physical hand-off of goods against one
or more `sales_orders` lines. A single sales order can spawn multiple deliveries (partial
fulfillment as stock arrives); a delivery always references exactly one sales order (multi-order
consolidated shipments are modeled as a `stock_transfers`/logistics concern below the Sales module,
out of scope here — Sales always ships per order).

**Header fields:** `delivery_number` (`DO-{branch_code}-{YYYY}-{00000}`), `sales_order_id`,
`customer_id`, `warehouse_id`, `status`
(`pending | reserved | picking | picked | packed | shipped | in_transit | delivered | failed |
cancelled`), `delivery_date` (planned), `actual_delivery_date`, `shipping_address_id`,
`delivery_method` (`carrier | self_pickup | in_store`), `carrier_id` (nullable FK, see Shipping),
`tracking_number`, `total_weight_kg`, `total_volume_m3`, `package_count`, `pod_attachment_id`
(nullable FK `attachments` — signature/photo proof of delivery), `pod_captured_at`,
`pod_captured_by_name` (recipient's stated name, may differ from customer contact).

**Line fields:** `sales_order_item_id`, `product_id`, `quantity_ordered` (denormalized snapshot),
`quantity_to_deliver`, `quantity_delivered`, `uom_id`, `warehouse_bin_id` (nullable, picked-from
location), `batch_id` / `serial_id` (nullable FKs to `product_batches` / `product_serials` for
lot/serial-tracked products — required, not nullable, when the product mandates batch or serial
tracking, enforced by a CHECK-equivalent application-layer rule since the requirement is
product-conditional).

**Picking sub-workflow.** Confirming a delivery for picking generates a pick list grouped by
`warehouse_bin_id` (ascending bin walk order, to minimize picker travel) via the Warehouse module.
Each line's `quantity_delivered` is updated as items are physically picked and scanned; a pick that
comes up short (bin had less than reserved — a cycle-count discrepancy) creates a
`stock_adjustments` proposal in Inventory and flags the delivery line `pick_shortage = true`,
triggering either a partial delivery (ship what was picked, create a follow-up delivery for the
shortfall) or a hold, per company policy (`on_pick_shortage: 'partial' | 'hold'`).

**Packing sub-workflow.** Picked lines are grouped into one or more physical packages recorded as
child rows on a lightweight `delivery_packages` structure (weight, dimensions, package barcode);
packing confirms `total_weight_kg`/`total_volume_m3`/`package_count` on the delivery header, which
feed carrier rate shopping in Shipping. A delivery is not shippable until every line's
`quantity_delivered` is packed into at least one package (or the delivery is explicitly marked
partially-packed and a follow-up delivery created for the remainder).

**Delivery completion and its invoicing trigger.** When `status` transitions to `delivered`
(carrier confirms drop-off, or self-pickup/in-store is marked collected), the delivery emits
`delivery.delivered`. If the parent order's `billing_policy = 'on_delivery'`, this event is what the
Invoices process listens for to generate (or add lines to) an invoice — see Invoices. Delivery
status never itself posts to Accounting; only the resulting Invoice does. Delivery does, however,
drive perpetual-inventory `stock_movements` (a decrement of `inventory_items.quantity_on_hand` and
release of the matching `stock_reservations` row) the moment picking/packing physically removes
stock from the shelf — which is intentionally *before* invoicing for the on-delivery billing policy,
since stock leaves the warehouse before the invoice is necessarily posted (a company can batch-
invoice several deliveries into one weekly invoice for a wholesale customer). The COGS/Inventory
accounting entry (see Accounting Integration) is tied to the delivery event's stock movement, not to
the invoice event, so cost recognition timing matches physical goods movement even when billing lags.

# Shipping

Shipping is the carrier-facing layer that turns a packed delivery into a tracked, moving shipment.

**Carrier configuration.** Each company configures one or more shipping carriers/methods (a
lightweight `shipping_carriers` reference table: `id`, `company_id`, `name`, `carrier_type`
(`local_courier | national_post | international | self_fleet | pickup_point`), `api_provider`
(nullable — integration key for a rate/label API, e.g. a Gulf courier aggregator), `is_active`,
`default_service_level`). `deliveries.carrier_id` references this table.

**Rate calculation.** For carrier-integrated methods, a rate-shopping call (weight/dimensions/
destination from the packed delivery) returns candidate services with cost and ETA; the selected
service's cost is recorded on the delivery (`shipping_cost_amount`) and, if the company passes
shipping cost to the customer, added as a `sales_order_items`/`invoice_items` "Shipping & Handling"
line referencing a dedicated non-stock `products` row rather than being silently absorbed into
product pricing — this keeps revenue and freight cost separately reportable.

**Label & manifest.** A shipping label (carrier-format PDF/ZPL) and, for multi-package shipments
picked up in a batch, a manifest are generated and stored as `attachments` polymorphically linked to
the delivery. `tracking_number` and a `tracking_url` are stored on the delivery header.

**Tracking & status sync.** For carrier-integrated methods, a webhook or polling job updates
`deliveries.status` through `in_transit` states as the carrier reports scan events; each status
change is appended to a `delivery_status_history` audit trail (status, timestamp, location text,
raw carrier payload in JSONB) so a customer-facing tracking view can render a full timeline.

**Proof of delivery.** Final carrier scan ("delivered") or, for self-pickup/in-store, an explicit
staff action capturing a signature/photo, sets `status = 'delivered'`, `actual_delivery_date`, and
the `pod_attachment_id`/`pod_captured_at`/`pod_captured_by_name` fields. A delivery attempt that
fails (customer not present, address issue) sets `status = 'failed'` with `failure_reason`, and the
Sales workflow surfaces it to the assigned sales employee for rescheduling — it does not
auto-cancel the underlying sales order.

**International/cross-border shipping** additionally requires: `customs_declared_value`,
`hs_code` (per product, sourced from `products.hs_code`), `incoterms` (`DAP | DDP | FOB | EXW`,
company-default configurable, overridable per order), and a customs-documents attachment set. Tax
treatment of cross-border sales is handled per the Taxes section (export zero-rating, destination-
country VAT/import-duty responsibility per Incoterm).

# Invoices

An invoice (`invoices` with `type = 'sales'`, plus `invoice_items`) is the legal, tax-relevant demand
for payment and the trigger for every Accounting-facing posting this module produces. Sales invoices
and purchase bills share the physical `invoices` table only in the sense described by the platform's
canonical naming note — in practice `invoices.type = 'sales'` rows are exclusively owned, created,
and mutated by this module; `bills` (a separate table) is Purchasing's, and Sales never touches it.

**Header fields:** `invoice_number` (`INV-{branch_code}-{YYYY}-{00000}`, sequential, gapless within
a fiscal year for tax-compliance reasons — see Validation-adjacent rule in Edge Cases),
`sales_order_id`, `delivery_id` (nullable — set when generated from a specific delivery; null for
order-triggered or milestone billing), `customer_id`, `channel`, `invoice_date`, `due_date`
(computed from `payment_terms`), `currency_code`, `exchange_rate`, `subtotal_amount`,
`discount_amount`, `tax_amount`, `total_amount`, `base_currency_total_amount`, `amount_paid`,
`balance_due` (generated: `total_amount - amount_paid`), `status`
(`draft | posted | partially_paid | paid | overdue | void`), `payment_status`
(`unpaid | partially_paid | paid`), `posted_at`, `posted_by`, `journal_entry_id` (FK
`journal_entries`, set the moment it posts), `void_reason`, `voided_at`, `voided_by`,
`credit_note_id` (nullable — set if this invoice was fully voided via credit note rather than the
void flow), `cost_center_id`, `project_id`, `pdf_attachment_id`.

**Line fields:** `sales_order_item_id` (nullable), `delivery_item_id` (nullable), `product_id`,
`description`, `quantity`, `uom_id`, `unit_price`, `discount_amount`, `tax_code_id`,
`tax_rate_snapshot`, `tax_amount`, `line_subtotal`, `line_total`, `cogs_unit_cost` (NUMERIC(19,4),
snapshot of the inventory valuation cost at the moment of posting, used to generate the paired
COGS journal line — see Accounting Integration), `cost_center_id`, `project_id`.

**Generation triggers, by billing policy:**

| `billing_policy` | Trigger event | What gets invoiced |
|---|---|---|
| `on_delivery` | `delivery.delivered` | The delivered lines/quantities on that delivery |
| `on_order_confirmation` | `sales_order.confirmed` | The full order (services, digital goods, no physical delivery) |
| `milestone` | Manual or project-milestone completion event | The milestone's agreed percentage/amount |
| `recurring` | Scheduled job at each `recurrence_rule.next_run_date` | The subscription's current period amount |

An invoice is never generated automatically into `posted` status for `on_delivery`/`milestone`
policies by default (`draft` first, reviewed, then posted) unless the company enables
`auto_post_invoices = true` for a given channel (commonly enabled for `subscription` and `pos`).

**Posting.** Moving `draft → posted` is the accounting event boundary and, once posted, an
invoice's amounts are immutable — see Business Rules equivalents under Accounting Integration and
Edge Cases for the correction path (credit note, never edit). Posting:
1. Validates the invoice balances (`SUM(line_total) + tax_amount == total_amount`, to the cent).
2. Emits `invoice.posted` with the full invoice payload.
3. The Accounting Engine's posting service creates a balanced `journal_entries` row (see Accounting
   Integration for the exact debit/credit lines) and stamps `invoices.journal_entry_id`.
4. Updates `customers.outstanding_ar_balance` (materialized/cached, always reconcilable to the live
   sum of `balance_due` across open invoices).
5. Sets `sales_order_items.quantity_invoiced` += invoiced quantity on the parent order.

**Void vs. Credit Note.** A `draft` invoice can be voided outright (no accounting impact yet — it
never posted). A `posted` invoice can never be voided in place; the only correction mechanism is
issuing a `credit_notes` document against it (full or partial), which posts its own reversing
journal entry (see Accounting Integration) and is linked back via `invoices.credit_note_id` only
when the credit note fully cancels the invoice's remaining balance. This preserves the
immutable-once-posted rule from the platform's double-entry accounting mandate.

**Overdue handling.** A scheduled job flips `status = 'overdue'` for any posted invoice past
`due_date` with `balance_due > 0`, driving dunning notifications (see Notifications) and feeding
the CFO Agent's Risk Analysis output (aging-bucket exposure per customer).

# Payments

"Payments" in the Sales module means cash and cash-equivalent inflows collected from customers
against sales invoices — modeled as `receipts` (header) and `receipt_allocations` (the link table
distributing one receipt's amount across one or more invoices). This is distinct from
`vendor_payments` (Purchasing's outflow-side table) and from `bank_transactions` (Banking's raw feed,
which a receipt may reconcile against).

**Header fields (`receipts`):** `receipt_number` (`RC-{branch_code}-{YYYY}-{00000}`), `customer_id`,
`receipt_date`, `payment_method`
(`cash | card | bank_transfer | cheque | knet | apple_pay | wallet | other`), `currency_code`,
`exchange_rate`, `amount` (NUMERIC(19,4), transaction currency — the full amount received),
`base_currency_amount`, `unallocated_amount` (generated: `amount - SUM(receipt_allocations.amount)`
in transaction currency), `bank_account_id` (nullable FK `bank_accounts`, for non-cash methods),
`reference_number` (cheque number / card auth code / KNET reference), `status`
(`pending | cleared | bounced | cancelled` — relevant for cheques and card chargebacks),
`journal_entry_id`.

**Allocation fields (`receipt_allocations`):** `receipt_id`, `invoice_id`, `amount_allocated`
(NUMERIC(19,4), transaction currency of the receipt), `allocated_at`, `allocated_by`,
`allocation_method` (`manual | auto_fifo | auto_oldest_first | customer_directed`).

**Allocation strategies.** When a receipt is captured with `auto_allocate = true`, the service
applies one of three deterministic strategies, company-configurable as a default and overridable
per receipt:
- **FIFO (`auto_fifo`).** Allocates against invoices in `invoice_date` ascending order.
- **Oldest-first by due date (`auto_oldest_first`).** Allocates against invoices in `due_date`
  ascending order — the default for aging-sensitive wholesale customers, since it clears the most
  overdue exposure first regardless of when it was issued.
- **Customer-directed (`customer_directed`).** The customer (via remittance advice or portal
  self-service) specifies exactly which invoice(s) their payment covers; the system honors that
  mapping even if it leaves an older invoice unpaid, recording `allocation_method = 'customer_directed'`
  for audit clarity on any subsequent dispute.
Any leftover `unallocated_amount` remains as a customer credit balance (usable against future
invoices, or refundable) — it is never forced onto an invoice the customer did not intend to pay.

**Posting.** Confirming a receipt (`status: pending → cleared`, or immediately for cash/card/KNET
which clear instantly) emits `payment.received`; the Accounting Engine posts a journal entry
debiting the relevant Cash/Bank account and crediting Accounts Receivable for the allocated portion
(and crediting a "Customer Advances" liability sub-account for any unallocated portion — see
Accounting Integration). Each invoice's `amount_paid`/`balance_due`/`payment_status` recompute from
its `receipt_allocations`.

**Reversals.** A bounced cheque or a card chargeback does not delete the receipt; it transitions
`status → bounced`, reverses the original journal entry with an equal-and-opposite reversing entry,
zeroes out the affected `receipt_allocations` (setting `amount_allocated = 0` on new reversal rows
rather than deleting the original rows, to preserve full history), and flips the affected invoices'
`payment_status` back to `unpaid`/`partially_paid`. This is also the moment the Fraud Detection
agent's model is retrained/re-weighted against that customer's payment-reliability signal.

# Returns

A return is the physical-and-commercial process of a customer sending goods back (or the company
recalling them). Sales models it as a `sales_returns` header (Return Merchandise Authorization,
"RMA") with `sales_return_items` lines, always referencing the original `invoices`/`invoice_items`
(or, for pre-invoice cancellations, the `sales_orders`/`delivery_items`) being returned against —
a return can never exist without a source document, preventing fictitious returns from entering the
system.

**Header fields:** `rma_number` (`RMA-{branch_code}-{YYYY}-{00000}`), `invoice_id`, `customer_id`,
`return_reason` (`defective | wrong_item | not_as_described | customer_changed_mind | damaged_in_
transit | duplicate_order | other`), `return_reason_notes`, `status`
(`requested | approved | rejected | awaiting_receipt | received | inspected | completed |
cancelled`), `requested_at`, `approved_by`, `resolution_type`
(`refund | credit_note_only | exchange | repair`), `restocking_fee_amount`, `warehouse_id`
(receiving warehouse).

**Line fields:** `invoice_item_id`, `product_id`, `quantity_returned`, `uom_id`, `condition`
(`resalable | damaged | defective | expired`), `disposition`
(`restock | scrap | return_to_vendor | quarantine_for_inspection`), `unit_refund_amount`,
`line_refund_amount`, `tax_refund_amount`.

**Workflow.**

```
requested ──(review)──► approved ──► awaiting_receipt ──► received ──► inspected ──► completed
     │                      │                                              │
     └──(reject)──► rejected                                    (per-line disposition:
                                                                   restock | scrap |
                                                                   return_to_vendor)
```

A return request can be customer-initiated (self-service portal, within the configured return
window — see Business Rules under Edge Cases) or staff-initiated (a QC recall). Approval is required
before a customer ships anything back except in fully self-service e-commerce flows where policy
pre-approves returns meeting simple criteria (within N days, unopened) — even then, the system still
creates an `approved` record automatically rather than skipping the state, preserving a uniform audit
trail regardless of how approval was granted.

**Physical receipt & inspection.** When goods arrive at `warehouse_id`, staff record
`quantity_received` per line and set `condition`. Inspection assigns `disposition`: `restock`
triggers a `stock_movements` increment (goods back on the shelf, sellable) with the same
`inventory_valuations` cost method as the original sale (see Warehouse/Inventory Integration);
`scrap` triggers a `stock_adjustments` write-off with its own inventory-loss journal entry outside
Sales' scope (owned by Inventory); `return_to_vendor` links to Purchasing's debit-note flow (out of
this module's scope, referenced only); `quarantine_for_inspection` holds stock in a non-sellable
Warehouse zone pending a quality decision.

**Completion.** A return reaches `completed` only after every line has a disposition recorded and
either a `credit_notes` document has been generated (see Refunds) or the resolution is
`exchange`/`repair` and the replacement/repair order has been separately created and linked via
`related_sales_order_id`. A return with `resolution_type = 'exchange'` never issues cash-affecting
documents on its own — the exchange is modeled as this return plus a brand-new zero-net-cash
`sales_orders` for the replacement item, netted only if the replacement's value differs from the
original (the difference is either a small additional invoice or a small credit note).

**Return window & fraud signals.** Company-configured `return_window_days` per product category
(commonly 7–30 days in Gulf retail practice, 14 days statutory minimum for online sales in several
GCC consumer-protection frameworks — configurable, not hardcoded, since exact statutory minimums are
jurisdiction- and product-category-specific and must remain admin-configurable rather than baked
into this spec). Returns requested outside the window are blocked for `refund`/`credit_note_only`
resolution unless a manager override with `sales.return.override_window` permission is recorded. The
Fraud Detection agent scores return patterns (return-to-purchase ratio, repeated "wrong item"
reasons on high-value SKUs, returns immediately preceding a card chargeback) and surfaces a risk
flag on the customer record without ever auto-rejecting a return.

# Refunds

A refund settles a `credit_notes` balance back to the customer, in cash/bank/original-payment-
method, or leaves it as store credit. Refunds always originate from a credit note; there is no path
to a `refunds` row without a preceding `credit_notes` row, which itself always originates from a
Return or from a standalone billing-error correction (an invoice was wrong and needs reversing even
though no physical goods moved — e.g. a pricing mistake caught after posting).

**Credit note fields (`credit_notes`):** `credit_note_number` (`CN-{branch_code}-{YYYY}-{00000}`),
`invoice_id`, `sales_return_id` (nullable — null for a pure billing-correction credit note),
`customer_id`, `reason` (`return | pricing_error | goodwill | duplicate_invoice | cancellation`),
`currency_code`, `exchange_rate`, `subtotal_amount`, `tax_amount`, `total_amount`,
`base_currency_total_amount`, `status` (`draft | posted | applied | void`), `applied_amount`
(how much has been used to offset invoices or paid out as a refund), `remaining_amount` (generated:
`total_amount - applied_amount`), `journal_entry_id`, `posted_at`, `posted_by`.

**Credit note lines (`credit_note_items` — mirrors `invoice_items`):** `invoice_item_id`,
`product_id`, `quantity`, `unit_price`, `tax_code_id`, `tax_rate_snapshot`, `tax_amount`,
`line_total`.

**Posting a credit note** emits `credit_note.issued`; the Accounting Engine posts the mirror-image
entry of the original invoice posting (debit Revenue and Output Tax, credit Accounts Receivable —
see Accounting Integration for exact lines), reducing the customer's `outstanding_ar_balance`
immediately, regardless of whether cash is ever physically refunded.

**Settlement of the credit note's `remaining_amount`** has three mutually exclusive paths per
amount (a single credit note can be split across paths):
1. **Applied against future/existing invoices** — a `receipt_allocations`-like linkage
   (`credit_note_applications`: `credit_note_id`, `invoice_id`, `amount_applied`) reduces another
   invoice's `balance_due` directly; no cash moves; no `refunds` row is created for this portion.
2. **Cash/bank refund** — a `refunds` row: `refund_number` (`RF-{branch_code}-{YYYY}-{00000}`),
   `credit_note_id`, `customer_id`, `refund_method`
   (`cash | card_reversal | bank_transfer | wallet | store_credit`), `amount`,
   `base_currency_amount`, `bank_account_id`, `status`
   (`pending | processed | failed | cancelled`), `processed_at`, `journal_entry_id`. Posting a cash
   refund emits `refund.processed` and posts a journal entry crediting Cash/Bank and debiting the
   liability created when the credit note posted (see Accounting Integration) — cash physically
   leaves the company only at this step, never at credit-note-issuance step, which is a pure AR/
   revenue reversal.
3. **Store credit (left open)** — the credit note simply remains `status = 'posted'` with
   `remaining_amount > 0`, discoverable at the customer's next order/invoice for the sales rep or the
   customer portal to apply. Store credit older than a company-configured expiry
   (`store_credit_expiry_days`, nullable = never expires) is flagged for finance review before
   write-off, never auto-expired silently.

**Card refunds specifically** require the original payment reference (`receipts.reference_number`)
for a same-method reversal where the payment gateway/KNET mandates refund-to-original-card; the
`refunds.refund_method = 'card_reversal'` path stores `original_receipt_id` and blocks if that
receipt's `status != 'cleared'` (cannot reverse a payment that never cleared).

# Discounts

A `discounts` row is a reusable, named discount definition a sales rep or the system can attach to a
quotation/order line or header (`discounts.scope = 'line' | 'header'`). Discounts are the
manually-applied counterpart to Pricing Rules (system-evaluated, see below) — a rep picks a discount
from a list; a price rule applies itself automatically when conditions match.

**Fields:** `code` (internal short code, e.g. `EMP10`, `LOYALTY5`), `name_en`, `name_ar`,
`discount_type` (`percentage | fixed_amount`), `value`, `applies_to`
(`all_products | category | specific_products`), `category_id` / `product_ids` (JSONB array,
populated per `applies_to`), `max_uses_total` (nullable), `max_uses_per_customer` (nullable),
`current_uses`, `requires_approval_above_value` (nullable NUMERIC — a per-discount override of the
global approval threshold), `valid_from`, `valid_until`, `status` (`active | inactive | expired`),
`requires_manager_pin` (boolean — for in-person channels where a supervisor PIN must be entered at
POS to apply a sensitive discount, logged to `audit_logs` with the approving employee's id).

A line- or header-level discount always writes `sales_quotation_items.discount_id` /
`sales_order_items.discount_id` (or the header-level equivalent field) so the applied discount is
traceable, and increments `discounts.current_uses` transactionally with the order/quotation
creation (never as a separate, race-prone update).

# Coupons

A `coupons` row is a single-use-or-limited-use, customer-facing code, distinct from a `discounts`
row in that it is *redeemed* by the customer (typically self-service, in E-Commerce/Marketplace
checkout or POS entry) rather than *selected* by staff, and it always has a code the customer must
know and type/scan.

**Fields:** `code` (unique per company, case-insensitive match), `discount_id` (FK — a coupon is a
distribution mechanism for an underlying discount definition, reusing all of `discounts`' value/
type/scope logic rather than duplicating it), `campaign_name`, `distribution_channel`
(`email | sms | social | referral | in_store_flyer | influencer`), `max_redemptions_total`,
`max_redemptions_per_customer` (default 1), `current_redemptions`, `minimum_order_amount`
(nullable — coupon only valid above this subtotal), `stackable_with_promotions` (boolean — whether
this coupon can combine with an active `promotions` row on the same order, default `false` to
prevent uncontrolled discount stacking), `valid_from`, `valid_until`, `status`
(`active | inactive | exhausted | expired`), `single_use_token` (boolean — if true, the code itself
is one cryptographically random token per intended recipient, e.g. a personalized referral code,
rather than one shared code redeemed by many).

**Redemption.** Applying a coupon at checkout validates: code exists and `status = 'active'`;
`valid_from ≤ now() ≤ valid_until`; `current_redemptions < max_redemptions_total`; this customer's
redemption count `< max_redemptions_per_customer`; order subtotal `≥ minimum_order_amount`; and, if
`stackable_with_promotions = false`, no other promotion is already applied to the order. A
successful redemption writes a `coupon_redemptions` row (`coupon_id`, `customer_id`, `order_id` or
`quotation_id`, `redeemed_at`, `discount_amount_applied`) and increments `current_redemptions`
atomically with order/quotation creation, identical in concurrency treatment to `discounts.
current_uses`.

# Promotions

A `promotions` row is a time-boxed, condition-driven campaign that applies automatically —
customers need no code (unlike Coupons) and staff need not select anything (unlike Discounts); the
Pricing Rules engine (below) evaluates active promotions against every quotation/order line/header
at price-calculation time.

**Fields:** `name_en`, `name_ar`, `promotion_type`
(`buy_x_get_y | percentage_off | fixed_amount_off | bundle_price | free_shipping | tiered_quantity`),
`conditions` (JSONB — structured condition set, e.g.
`{"min_quantity": 3, "product_category_id": 42}` or
`{"buy_product_id": 10, "buy_qty": 2, "get_product_id": 10, "get_qty": 1, "get_discount_pct": 100}`
for a "buy 2 get 1 free"), `reward` (JSONB — the discount/benefit to apply once conditions match),
`applicable_channels` (JSONB array of `sales_channel` values — a promotion can be E-Commerce-only,
POS-only, or all channels), `priority` (integer — evaluation order when multiple promotions could
match the same line; lower number evaluates first), `is_exclusive` (boolean — if true, no other
promotion/coupon may also apply once this one matches), `valid_from`, `valid_until`, `status`
(`scheduled | active | ended | paused`), `usage_limit_total`, `usage_count`.

**Evaluation.** At line/order pricing time (see Pricing Rules for the full evaluation pipeline), the
engine fetches all `promotions` with `status = 'active'`, `applicable_channels` containing the
document's channel, and `valid_from/valid_until` bracketing `now()`, sorted by `priority`, and
evaluates each `conditions` JSONB against the current cart/order state. The first matching
`is_exclusive` promotion short-circuits further evaluation; non-exclusive matches accumulate (subject
to the same anti-stacking safeguard pattern as coupons — a company-level `max_stacked_promotions`,
default 1, prevents runaway discount stacking from a misconfigured campaign set).

# Pricing Rules

`price_rules` is the deterministic, always-on pricing engine that computes the *base* unit price
before any discount/coupon/promotion is layered on top. It answers "what should this product cost
this customer, right now" from configuration, not from a hardcoded price on the product record.

**Fields:** `name_en`, `name_ar`, `rule_type`
(`price_list | customer_tier | quantity_break | channel_override | time_based | geographic`),
`priority` (lower evaluates first; first full match wins for mutually exclusive rule types),
`conditions` (JSONB — e.g. `{"customer_tier": "gold"}`, `{"min_quantity": 50}`,
`{"channel": "wholesale"}`, `{"valid_days": ["fri","sat"], "valid_hours": ["18:00","23:00"]}` for a
happy-hour rule, `{"country_code": "SA"}` for a geographic price point), `price_list_id` (nullable —
when `rule_type = 'price_list'`, points to the `price_lists`/`price_list_items` tier to use),
`adjustment_type` (`set_price | percentage_adjustment | fixed_adjustment`), `adjustment_value`,
`applies_to` (`all_products | category | specific_products`), `category_id` / `product_ids`,
`valid_from`, `valid_until`, `status`.

**Evaluation pipeline (executed for every quotation/order line, in this fixed order):**

```
1. Resolve base list price  → products.base_price (fallback) OR matching price_lists/price_list_items
                                for the customer's assigned price list (customers.price_list_id)
2. Apply Pricing Rules       → price_rules matching conditions, highest-priority full match wins,
                                producing the "rule price" (set/percentage/fixed adjustment on step 1)
3. Apply Promotions          → auto-matching promotions.reward layered on the rule price
4. Apply Coupon (if any)     → customer-entered coupon's discount, respecting stackable_with_promotions
5. Apply manual Discount     → rep-selected discounts.value, if any (rare in combination with 3/4;
                                UI warns if a rep tries to stack a manual discount on top of an
                                already-promoted line, but does not hard-block — a manager approval
                                gate does, per requires_approval_above_value)
6. Compute tax                → tax_rates applied to the post-discount line_subtotal
```

Every step's contribution is stored, not just the final number: `sales_order_items` carries
`base_unit_price`, `rule_adjustment_amount`, `promotion_adjustment_amount`,
`coupon_adjustment_amount`, `manual_discount_amount`, and `unit_price` (final), so
"why did this line cost X" is answerable from the row alone — directly serving the Sales
Philosophy's explainability principle.

# Taxes

Sales integrates with the platform's shared `tax_codes`/`tax_rates`/`tax_transactions` tables owned
by the Tax module; Sales is a *consumer and poster* of tax data, never the owner of tax-rate
configuration.

**Resolution.** Every `sales_quotation_items`/`sales_order_items`/`invoice_items` line resolves a
`tax_code_id` from, in priority order: (1) an explicit override on the line, (2) the product's
default `products.tax_code_id`, (3) the customer's tax profile (`customers.tax_exempt = true`
routes to a zero-rate/exempt `tax_code_id` regardless of product default — e.g. a diplomatic or
free-zone customer), (4) the company's default output tax code. The resolved code's *rate* is
looked up from `tax_rates` (which carries `effective_from`/`effective_to` for rate changes over
time, e.g. a GCC country moving VAT from 5% to a new rate) as of the document's date, and frozen
onto the line as `tax_rate_snapshot` — never re-looked-up later, per the pricing-freeze principle
in Sales Orders.

**Output tax accounting.** Posting an invoice or credit note routes the tax portion to the Output
Tax liability account (see Accounting Integration) as a credit (invoice) or debit (credit note,
reversing). Output tax is never recognized as revenue and never nets against COGS — it is a pure
pass-through liability owed to the tax authority, tracked per-transaction in `tax_transactions`
(owned by the Tax module; Sales writes one row per posted invoice/credit-note line carrying
`source_type = 'sales_invoice' | 'sales_credit_note'`, `source_id`, `tax_code_id`, `taxable_amount`,
`tax_amount`, `direction = 'output'`) so the periodic VAT/tax return (Tax module's `tax_returns`)
can aggregate without re-deriving from `invoice_items` directly.

**Cross-border and zero-rating.** Export sales (shipping address outside the company's home tax
jurisdiction, or `incoterms` indicating the customer bears destination-country import tax) resolve
to a zero-rate `tax_code_id` automatically when `products.is_exportable = true` and the shipping
country differs from the company's registered tax jurisdiction, subject to the company's configured
`export_zero_rating_enabled` flag and any product-category exclusions (some goods are never
zero-rated regardless of destination, per local regulation — configured per `tax_codes`, not
hardcoded here).

**Tax-inclusive vs. tax-exclusive pricing.** A company (or, more granularly, a channel — B2C retail
commonly displays tax-inclusive prices; B2B commonly quotes tax-exclusive) sets
`price_display_mode = 'inclusive' | 'exclusive'`. Inclusive mode back-calculates the exclusive
`line_subtotal` from the displayed inclusive price and the resolved tax rate
(`line_subtotal = displayed_price / (1 + tax_rate)`), rounding per the company's configured
rounding rule (`round_half_up` at the currency's minor-unit precision) before storing — rounding
differences across many lines are absorbed into a dedicated `rounding_adjustment_amount` on the
document header, never silently distributed across lines, so the header total always foots exactly.

**Multiple tax codes per line** (e.g. a jurisdiction with both a national VAT and a municipal
excise on specific goods) are supported via `invoice_items` allowing a JSONB `additional_taxes`
array (`[{tax_code_id, rate, amount}]`) beyond the primary `tax_code_id`, each contributing its own
`tax_transactions` row and its own Output Tax sub-account line at posting.

# Multi Currency

Every Sales document (`sales_quotations`, `sales_orders`, `deliveries` where value matters,
`invoices`, `receipts`, `credit_notes`, `refunds`) carries `currency_code` (ISO 4217) and
`exchange_rate` (NUMERIC(18,6)) alongside transaction-currency amounts and a mirrored
`base_currency_*_amount` column set, per the platform's multi-currency convention. The company's
base currency (commonly KWD for a Kuwait-domiciled tenant, configurable per company) is the currency
every consolidated report and every `journal_lines` posting uses.

**Rate source and locking.** `exchange_rate` is sourced from the company's configured rate provider
(a daily rate feed, or manual entry for currencies without automated feeds) at document-creation
time and **locked** onto the document — a quotation issued at 1 USD = 0.307 KWD keeps that rate
through acceptance and order confirmation; it is not re-fetched at invoice time. This is deliberate:
a customer who accepted a quoted price in USD must be invoiced at the KWD-equivalent implied by the
rate they saw, not a rate that moved in the interim, unless the quotation explicitly states
"price subject to exchange rate at time of invoice" (`rate_lock_type = 'quote_date' | 'invoice_date'`,
company/customer-configurable, defaulting to `quote_date`).

**Multi-currency receipts.** A receipt can be captured in a currency different from the invoice it
settles (e.g. invoice in KWD, customer wires USD) — `receipts.currency_code` is independent of the
invoice's; the allocation amount (`receipt_allocations.amount_allocated`) is always expressed in the
*invoice's* currency, computed by converting the receipt amount at the receipt's locked rate into
base currency, then into the invoice's currency at the invoice's own locked rate. Any resulting
sub-cent/sub-fils rounding difference posts to a dedicated "Foreign Exchange Gain/Loss" account
(owned by Accounting, referenced here) rather than being absorbed into the AR balance.

**Realized FX gain/loss.** Because an invoice's rate is locked at invoice-date and the receipt's
rate is locked at receipt-date, any exchange-rate movement between the two dates on a foreign-
currency invoice produces a realized gain or loss, recognized at the moment the receipt posts —
computed as `(invoice.base_currency_total_amount portion settled) - (receipt.base_currency_amount
portion applied)`, posted per Accounting Integration.

**Reporting currency toggle.** Every Sales report (see Reports) supports rendering in either the
transaction currency (grouped/subtotaled per currency, useful for a multi-market wholesale business)
or the company's base currency (single consolidated total, using each document's locked rate) — the
toggle never re-converts at "today's" rate for historical documents, preserving historical accuracy.

# Inventory Integration

Sales never writes to `inventory_items`, `stock_movements`, `stock_reservations`, or
`inventory_valuations` directly. All interaction is event-driven, in both directions:

**Sales → Inventory (events emitted by Sales, consumed by Inventory):**

| Event | Emitted when | Inventory's reaction |
|---|---|---|
| `sales_order.confirmed` | Sales Order confirmation gate passes | Create `stock_reservations` per line at the target warehouse |
| `sales_order.cancelled` | Order cancelled before delivery | Release matching `stock_reservations` |
| `sales_order.amended` | Quantity increased/decreased on a confirmed order | Adjust `stock_reservations` by the delta (re-running the stock-availability check for increases) |
| `delivery.picked` | Picking confirmed | Convert `stock_reservations` into a pending `stock_movements` (type `sales_pick`) |
| `delivery.shipped` | Shipping confirmed | Finalize `stock_movements` (type `sales_shipment`), decrementing `inventory_items.quantity_on_hand` and `quantity_reserved` |
| `sales_return.received` | Return goods physically received | Create `stock_movements` (type `sales_return_restock`) increasing `quantity_on_hand` if `disposition = 'restock'` |
| `sales_return.scrapped` | Return disposition = scrap | Create a `stock_adjustments` write-off proposal for Inventory approval |

**Inventory → Sales (events emitted by Inventory, consumed by Sales):**

| Event | Sales' reaction |
|---|---|
| `inventory.stock_low` | Surface a "may not be able to fulfill" warning on any open quotation/order line for that product, without blocking |
| `inventory.stock_adjusted` | If a `stock_adjustments` write-down reduces available-to-promise below an already-reserved order's quantity, flag the affected `sales_order_items.is_backorder = true` and notify the sales employee |
| `inventory.valuation_updated` | Sales' next invoice-posting for that product uses the updated `inventory_valuations` unit cost to snapshot `invoice_items.cogs_unit_cost` |

**Costing method dependency.** The `cogs_unit_cost` snapshot taken at invoice/delivery posting time
is sourced from whichever costing method the company has configured in Inventory
(`FIFO | weighted_average | standard_cost` — Inventory's configuration, not Sales'). Sales does not
compute cost; it reads the authoritative unit cost Inventory publishes at the moment of the
stock-decrementing event and snapshots it immutably onto the invoice line, so a later inventory
revaluation never retroactively changes a posted invoice's COGS.

**Available-to-promise (ATP) calculation**, used by the stock-availability gate at order
confirmation and surfaced live in quotation-building UIs, is computed as
`inventory_items.quantity_on_hand - inventory_items.quantity_reserved + incoming_purchase_quantity`
(the last term only if the company enables ATP-including-incoming-POs, since promising against
unreceived purchase orders is a business-risk decision, off by default).

# Warehouse Integration

Sales references `warehouses` (foundation) and, transitively through the Warehouse module's storage
hierarchy (`warehouse_zones` → `warehouse_areas` → `warehouse_aisles` → `warehouse_rows` →
`warehouse_shelves` → `warehouse_bins`), for two purposes: choosing *which* warehouse fulfills an
order line, and generating pick lists ordered by physical location.

**Warehouse selection.** `sales_orders.warehouse_id` (header default) and
`sales_order_items.warehouse_id` (line override) determine fulfillment source. For companies with
multiple warehouses, the order-confirmation stock-availability check can either target a single
specified warehouse (simple, single-branch retailers) or run a **warehouse-selection strategy**
(`nearest_to_shipping_address | highest_stock_first | designated_branch_warehouse`) that Sales calls
into the Warehouse module to resolve before running the availability gate — Sales owns the decision
of *when* to ask, Warehouse owns the answer of *where* stock physically is.

**Split fulfillment.** When no single warehouse can satisfy a line's full quantity but the combined
stock across warehouses can, and the company allows split fulfillment
(`allow_multi_warehouse_split = true`), the confirmation process creates multiple
`sales_order_items` reservation records against different warehouses for the same logical line
(tracked via a shared `split_group_id`), which in turn produces multiple `deliveries` — one per
source warehouse — each independently picked, packed, and shipped, but rolling up to the same
sales order and (by default) the same consolidated invoice.

**Pick-list generation.** Sales requests a pick list from the Warehouse module by passing
`delivery_id` + `warehouse_id`; the Warehouse module returns an ordered walk
(`warehouse_bins` sorted by aisle/row/shelf sequence number) which the delivery's picking UI renders.
Sales stores only the *result* (`delivery_items.warehouse_bin_id`, `quantity_delivered`) — the
walk-optimization logic itself is Warehouse's, not duplicated here.

**Return receiving location.** A return's `warehouse_id` defaults to the warehouse that originally
shipped the order but is overridable (e.g. a customer returns to a different branch than they
ordered from) — the Warehouse module resolves the specific `warehouse_bin_id` for restocked/
quarantined items based on the product's normal storage location or a dedicated returns-quarantine
zone.

# Customer Integration

Sales is the primary write path into the customer's *commercial* history but not the owner of the
`customers` master-data record itself in the sense of contact/address management — that ownership
split is deliberate:

**What Sales reads from Customers (owned by the Customers module):** `customers.credit_limit`,
`customers.payment_terms` (default, overridable per order), `customers.price_list_id`,
`customers.tax_exempt`, `customers.customer_type` (business/individual), `customers.customer_status`,
`customer_addresses` (billing/shipping selection at quotation/order time), `customer_contacts`
(for B2B multi-user ordering authority — validating that the person placing/approving an order on
behalf of a business customer is an authorized contact), `customer_documents` (trade license, tax
registration certificate — referenced for B2B credit decisions, not modified by Sales).

**What Sales writes back to Customers (via events, never direct writes):**

| Event | Customer-side effect |
|---|---|
| `sales_order.confirmed` (customer's first order) | `customers.customer_status: prospect → active`, `first_order_date` stamped |
| `invoice.posted` | `customers.outstanding_ar_balance` recalculated; `customers.lifetime_value`, `total_orders`, `last_order_date` updated |
| `payment.received` | `customers.outstanding_ar_balance` recalculated downward |
| `sales_return.completed` with high frequency | `customers.return_rate` recalculated; feeds Fraud Detection risk signal |
| No order in N days (scheduled job) | `customers.journey_stage → dormant` |

**Credit limit enforcement** is the tightest coupling point: the Sales Order confirmation gate reads
`customers.credit_limit` and the live-computed `outstanding_ar_balance` synchronously (not from a
possibly-stale cache) inside the same database transaction that confirms the order, using a row-level
lock (`SELECT ... FOR UPDATE` on the customer row) to prevent a race where two concurrently-
confirming orders both pass the credit check against the same stale balance and jointly blow through
the limit.

**Lead-to-customer conversion.** When a `leads` row converts (quotation accepted, or first order
placed), if `leads.customer_id` is null (a genuinely new prospect, not an existing customer's repeat
inquiry), Sales calls the Customers module's create-customer service (never inserts into `customers`
directly) with the lead's captured contact details, receives back a new `customers.id`, and stamps
it onto the lead and the resulting quotation/order. Duplicate-customer risk at this exact moment is
where the AI Fraud Detection agent's Duplicate Detection responsibility is most active (see AI
Responsibilities) — fuzzy-matching name/phone/email/tax-ID against existing `customers` before a
new row is created, surfaced as a "this may be an existing customer" suggestion the rep can accept
(merge into existing) or dismiss (proceed with new customer).

# Accounting Integration

Sales never writes to `journal_entries` or `journal_lines`. It emits domain events with a fully
populated payload; the Accounting Engine's posting service is the only component that translates
those events into balanced `journal_entries` (state `draft → posted`, immutable once posted) and
their `journal_lines`. Every posting below is illustrated with concrete account names for a typical
Gulf trading company chart of accounts; actual `account_id` values are resolved at posting time from
a per-company **posting configuration** (`sales_posting_rules`: maps `revenue account per product
category`, `AR control account per customer type/currency`, `Output Tax account per tax code`,
`COGS account per product category`, `Inventory account per warehouse/product category`,
`Cash/Bank account per payment method`, `Customer Advances liability account`,
`FX Gain/Loss account`), never hardcoded account IDs in application code.

**1. Invoice posting (`invoice.posted`).** A perpetual-inventory, physical-goods invoice posts two
paired journal entries sharing the same `source_document_type = 'invoices'`,
`source_document_id`:

*Entry A — Revenue recognition (always):*

| Account | Debit | Credit |
|---|---|---|
| Accounts Receivable — Trade (control account, per customer) | `total_amount` | |
| Sales Revenue (per product category / revenue account mapping) | | `subtotal_amount − discount_amount` |
| Output Tax Payable (per tax code) | | `tax_amount` |

*Entry B — Cost of goods sold (only for stock-tracked products, keyed off the delivery's stock
movement, computed per line as `quantity × cogs_unit_cost`):*

| Account | Debit | Credit |
|---|---|---|
| Cost of Goods Sold (per product category) | `Σ(quantity × cogs_unit_cost)` | |
| Inventory — Finished Goods (per warehouse/product category) | | `Σ(quantity × cogs_unit_cost)` |

For a service or non-stock-tracked line, Entry B is simply omitted for that line's contribution
(no COGS/Inventory movement for services); Entry A still posts for the service's revenue and tax.
For `billing_policy = 'on_delivery'` companies, Entry B is actually posted at the *delivery* event
(`delivery.shipped`), not at invoice posting — see item 3 below — so a company that ships before it
invoices still recognizes COGS at the moment of physical stock movement; only Entry A is strictly
tied to `invoice.posted`.

**2. Receipt posting (`payment.received`).**

| Account | Debit | Credit |
|---|---|---|
| Cash / Bank (per payment method mapping) | `amount` (base currency) | |
| Accounts Receivable — Trade | | `Σ receipt_allocations.amount_allocated` (converted to base currency) |
| Customer Advances (liability, for any unallocated portion) | | `unallocated_amount` (base currency) |

If the receipt's currency/rate differs from the invoice(s) it settles, a third line captures the
FX difference:

| Account | Debit | Credit |
|---|---|---|
| Foreign Exchange Loss (if unfavorable movement) | `abs(fx_difference)` | |
| Foreign Exchange Gain (if favorable movement, credit side instead) | | `abs(fx_difference)` |

**3. Delivery / COGS posting (`delivery.shipped`, independent of invoice timing).**

| Account | Debit | Credit |
|---|---|---|
| Cost of Goods Sold (per product category) | `Σ(quantity_shipped × unit_cost_at_shipment)` | |
| Inventory — Finished Goods (per warehouse/product category) | | `Σ(quantity_shipped × unit_cost_at_shipment)` |

This entry's `unit_cost_at_shipment` is read live from `inventory_valuations` at the moment of
shipment (FIFO/weighted-average/standard, per Inventory's configuration) and is the same value later
snapshotted onto `invoice_items.cogs_unit_cost` when that shipment's invoice eventually posts —
guaranteeing Entry B under item 1 and the delivery-time posting under item 3 are never double-
counted: the posting service enforces this by tagging the delivery's COGS entry with
`delivery_item_id`, and invoice posting for an `on_delivery` policy invoice explicitly **skips**
re-posting COGS for any `delivery_item_id` already carrying a posted COGS entry, only posting COGS
at invoice time for `on_order_confirmation`/`milestone`/`recurring` policies where no separate
delivery-time COGS entry exists (services/subscriptions typically have no COGS line at all).

**4. Credit note posting (`credit_note.issued`) — the mirror image of Entry A:**

| Account | Debit | Credit |
|---|---|---|
| Sales Revenue (or a dedicated "Sales Returns & Allowances" contra-revenue account, company-configurable) | `subtotal_amount − (any restocking fee retained)` | |
| Output Tax Payable | `tax_amount` | |
| Accounts Receivable — Trade | | `total_amount` |

If the credit note relates to a `sales_return` with `disposition = 'restock'`, a paired inventory
reversal mirrors Entry B in reverse:

| Account | Debit | Credit |
|---|---|---|
| Inventory — Finished Goods | `Σ(quantity_returned × cogs_unit_cost, from the original invoice line)` | |
| Cost of Goods Sold | | `Σ(quantity_returned × cogs_unit_cost)` |

If a `restocking_fee_amount` is retained (return accepted but a fee charged), it is **not** netted
into the credit note's revenue reversal line silently — it posts as its own small revenue line
(debit AR / credit "Restocking Fee Income") within the same journal entry, so gross return value and
retained-fee revenue remain separately reportable.

**5. Refund posting (`refund.processed`) — settling a credit note's remaining balance in cash:**

| Account | Debit | Credit |
|---|---|---|
| Customer Advances / Credit Note Liability (the contra-AR position created when the credit note posted) | `amount` | |
| Cash / Bank (per refund method) | | `amount` |

Note that credit-note posting (item 4) already fully reversed AR; the refund step (item 5) is purely
a cash-liability settlement and never touches Accounts Receivable or Revenue again — this is the
structural guarantee that a customer's AR balance reflects "what they owe on invoices," strictly
separate from "how much cash has physically been paid back to them."

**6. Marketplace commission (channel-specific elaboration of Entry A).** For a `marketplace`-channel
invoice where a commission deduction line exists (see Sales Channels), the deduction posts as its
own line within Entry A rather than reducing gross revenue:

| Account | Debit | Credit |
|---|---|---|
| Accounts Receivable — Trade (marketplace as receivable party, not the end customer) | `total_amount − commission` | |
| Marketplace Commission Expense | `commission` | |
| Sales Revenue | | `subtotal_amount − discount_amount` |
| Output Tax Payable | | `tax_amount` |

**Reconciliation guarantee.** Because every one of the above entries is generated exclusively by the
Accounting Engine's posting service from a Sales-emitted event payload — never hand-entered, never
editable in Sales — the sum of all posted `journal_lines` tagged `source_document_type IN
('invoices','receipts','credit_notes','refunds','deliveries')` for a company/period always
reconciles exactly to the sum of `invoices.total_amount`, `receipts.amount`, `credit_notes.
total_amount`, `refunds.amount`, and delivery-time COGS for that same period, to the minor currency
unit. A scheduled reconciliation job (see Performance) verifies this identity nightly and alerts
Finance on any drift, which — given the architecture — can only originate from a manual out-of-band
journal entry booked directly in Accounting against a sales-owned account, itself a flagged,
auditable event.

# AI Responsibilities

Every AI action in Sales runs through the FastAPI AI layer, which never writes to PostgreSQL
directly — it calls the Laravel API under the acting user's (or a designated service account's)
permissions, and every output it produces is persisted with a `confidence` score (0.00–1.00), a
`reasoning` text explanation, and an array of `supporting_document_ids` (the quotations, orders,
invoices, or historical records the recommendation was derived from), stored on `ai_conversations`/
`ai_messages` and cross-referenced from the Sales record it pertains to via a polymorphic
`ai_recommendations` table (`recommendable_type`, `recommendable_id`, `agent_name`,
`recommendation_type`, `payload` JSONB, `confidence`, `reasoning`, `status`
(`pending | accepted | dismissed | expired`), `acted_by`, `acted_at`).

| Responsibility | Owning agent | Inputs | Outputs | Autonomy | Confidence handling |
|---|---|---|---|---|---|
| Sales Forecasting | Forecast Agent | Historical `sales_orders`/`invoices` (12–24 month window), seasonality, open pipeline (`sales_quotations` weighted by acceptance-rate history per channel/customer segment) | Monthly/quarterly revenue forecast by branch/channel/product category, with a confidence interval | Suggest-only | Forecast always shown as a range (P10/P50/P90), never a single point estimate; below 0.5 confidence the UI visibly labels the forecast "low confidence — thin historical data" |
| Price Suggestions | CFO Agent | Product cost, competitor price signals (where configured), customer tier, historical win/loss rate at various price points for similar quotations | A suggested unit price/discount band for a quotation line, shown inline in the quote builder | Suggest-only | Rep sees suggested price + confidence + one-line reasoning ("similar customers accepted this price 78% of the time"); never auto-applied |
| Upselling | Approval Assistant | Current cart/quotation lines, product affinity model (frequently-bought-with), customer's historical basket | Ranked list of 1–3 complementary/upgrade products to suggest before sending the quotation | Suggest-only | Surfaced as a dismissible card in the quote builder; accepting adds the line, which then goes through normal pricing/approval logic — AI never adds a line silently |
| Cross Selling | Approval Assistant | Same inputs as Upselling, cross-category rather than same-category | Same mechanism as Upselling, different candidate pool | Suggest-only | Same as Upselling |
| Customer Insights | Reporting Agent | Full customer transaction history, `journey_stage`, `return_rate`, payment reliability (on-time vs. late receipt history) | A customer health summary (segment, LTV trend, churn risk, recommended next action) surfaced on the customer detail screen | Suggest-only | Always dated with "as of" the data snapshot; risk flags below 0.4 confidence are hidden from the summary rather than shown with a low badge, to avoid noisy false positives |
| Revenue Prediction | Forecast Agent | Same as Sales Forecasting, at a finer grain (per-customer next-order prediction for renewal/subscription businesses) | "This customer is likely to renew/reorder around [date] for approximately [amount]" | Suggest-only | Feeds the Reporting Agent's win-back and dormant-customer flags; never triggers an automated invoice or order |
| Fraud Detection | Fraud Detection Agent | Order velocity, geographic mismatch (billing vs. shipping vs. IP), payment-method risk signals, discount-pattern anomalies, return-abuse patterns | A per-transaction fraud risk score (0–100) attached to the sales order/quotation | Requires-approval (step-up) | Score ≥ configurable threshold (default 70) forces the order into `pending_approval` regardless of value/discount thresholds, even if it would otherwise auto-confirm; the agent never rejects a transaction outright — only a human approver can reject |
| Risk Analysis | CFO Agent | Customer credit history, industry/segment default rates, aging-bucket exposure, macro signals (where available) | A `credit_risk_tier` (`low | medium | high`) recommendation per customer, feeding the approval-gating thresholds in Quotations/Sales Orders | Suggest-only, with a policy hook | A company may configure `auto_apply_risk_tier = true`, in which case the *tier assignment* (not the credit limit itself) updates automatically above a confidence threshold (default 0.75) — but changing the actual `credit_limit` number always remains a human Finance action |
| Duplicate Detection | Fraud Detection Agent | Fuzzy match on customer name, phone, email, tax/commercial-registration ID across `customers`, and on order content (same customer, same products, same amount, within a short window) across `sales_orders` | A "possible duplicate" flag at lead-conversion time (duplicate customer) and at order-creation time (duplicate order — e.g. a double-submitted POS transaction or a retried API call) | Suggest-only, except idempotency | True duplicate *order* detection driven by an idempotency key (see API) is a hard, non-AI, deterministic block; AI-driven *fuzzy* duplicate detection (different phone number but same name+address pattern) is always advisory, shown to the rep before the customer/order is finalized |

**Cross-cutting rules that apply to every row above:**
- The AI layer only ever reads Sales data through the same permission-scoped Laravel API endpoints
  a human user would use; there is no privileged AI-only database connection.
- Every `ai_recommendations` row is retained (never deleted) even after `status = 'dismissed'`, so the
  AI's suggestion-acceptance rate is itself a measurable, auditable metric per agent and per company —
  feeding a feedback loop that can down-rank a consistently-dismissed recommendation type.
- No AI agent in this module is authorized to change `credit_limit`, post a journal entry, confirm a
  sales order, post an invoice, or process a refund. Every one of those remains a human action (or an
  explicit, separately-audited company automation policy such as `auto_post_invoices` — a company
  configuration choice, not an AI decision).

# Database Design

All tables below carry the platform's standard columns
(`id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY`, `company_id`, `branch_id`, `created_by`,
`updated_by`, `created_at`, `updated_at`, `deleted_at`) even where not re-listed in the `CREATE
TABLE` for brevity; every `CREATE TABLE` below includes them explicitly the first time and a shared
comment marks subsequent tables. Money columns are `NUMERIC(19,4)`; exchange rates `NUMERIC(18,6)`;
quantities `NUMERIC(18,4)`. Every table is indexed on `company_id` and every foreign key.

```sql
-- ============================================================
-- ENUM TYPES
-- ============================================================
CREATE TYPE sales_channel AS ENUM (
  'retail', 'wholesale', 'pos', 'ecommerce', 'marketplace',
  'phone', 'subscription', 'b2b', 'b2c'
);

CREATE TYPE lead_status AS ENUM (
  'new', 'contacted', 'qualified', 'converted', 'disqualified'
);

CREATE TYPE quotation_status AS ENUM (
  'draft', 'sent', 'accepted', 'rejected', 'expired', 'revised', 'cancelled'
);

CREATE TYPE sales_order_status AS ENUM (
  'draft', 'pending_approval', 'confirmed', 'reserved', 'picking', 'packed',
  'shipped', 'partially_invoiced', 'invoiced', 'cancelled', 'closed'
);

CREATE TYPE approval_status AS ENUM ('not_required', 'pending', 'approved', 'rejected');

CREATE TYPE credit_check_status AS ENUM ('passed', 'overridden', 'failed');

CREATE TYPE billing_policy AS ENUM ('on_delivery', 'on_order_confirmation', 'milestone', 'recurring');

CREATE TYPE delivery_status AS ENUM (
  'pending', 'reserved', 'picking', 'picked', 'packed', 'shipped',
  'in_transit', 'delivered', 'failed', 'cancelled'
);

CREATE TYPE delivery_method AS ENUM ('carrier', 'self_pickup', 'in_store');

CREATE TYPE invoice_status AS ENUM (
  'draft', 'posted', 'partially_paid', 'paid', 'overdue', 'void'
);

CREATE TYPE payment_status AS ENUM ('unpaid', 'partially_paid', 'paid');

CREATE TYPE payment_method AS ENUM (
  'cash', 'card', 'bank_transfer', 'cheque', 'knet', 'apple_pay', 'wallet', 'other'
);

CREATE TYPE receipt_status AS ENUM ('pending', 'cleared', 'bounced', 'cancelled');

CREATE TYPE allocation_method AS ENUM (
  'manual', 'auto_fifo', 'auto_oldest_first', 'customer_directed'
);

CREATE TYPE return_status AS ENUM (
  'requested', 'approved', 'rejected', 'awaiting_receipt', 'received', 'inspected',
  'completed', 'cancelled'
);

CREATE TYPE return_reason AS ENUM (
  'defective', 'wrong_item', 'not_as_described', 'customer_changed_mind',
  'damaged_in_transit', 'duplicate_order', 'other'
);

CREATE TYPE return_resolution AS ENUM ('refund', 'credit_note_only', 'exchange', 'repair');

CREATE TYPE item_condition AS ENUM ('resalable', 'damaged', 'defective', 'expired');

CREATE TYPE item_disposition AS ENUM (
  'restock', 'scrap', 'return_to_vendor', 'quarantine_for_inspection'
);

CREATE TYPE credit_note_status AS ENUM ('draft', 'posted', 'applied', 'void');

CREATE TYPE credit_note_reason AS ENUM (
  'return', 'pricing_error', 'goodwill', 'duplicate_invoice', 'cancellation'
);

CREATE TYPE refund_method AS ENUM (
  'cash', 'card_reversal', 'bank_transfer', 'wallet', 'store_credit'
);

CREATE TYPE refund_status AS ENUM ('pending', 'processed', 'failed', 'cancelled');

CREATE TYPE discount_type AS ENUM ('percentage', 'fixed_amount');

CREATE TYPE applies_to_scope AS ENUM ('all_products', 'category', 'specific_products');

-- ============================================================
-- LEADS
-- ============================================================
CREATE TABLE leads (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  branch_id         BIGINT NULL REFERENCES branches(id),
  customer_id       BIGINT NULL REFERENCES customers(id),
  source            sales_channel NOT NULL,
  status            lead_status NOT NULL DEFAULT 'new',
  contact_name      VARCHAR(255) NOT NULL,
  contact_phone     VARCHAR(30),
  contact_email     VARCHAR(255),
  company_name      VARCHAR(255),
  assigned_to       BIGINT NULL REFERENCES users(id),
  lead_score        NUMERIC(5,2),
  lead_score_reasoning TEXT,
  is_synthetic      BOOLEAN NOT NULL DEFAULT false,
  disqualify_reason TEXT,
  converted_at      TIMESTAMPTZ,
  tags              JSONB NOT NULL DEFAULT '[]',
  custom_fields     JSONB NOT NULL DEFAULT '{}',
  created_by        BIGINT NULL REFERENCES users(id),
  updated_by        BIGINT NULL REFERENCES users(id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL,
  CONSTRAINT chk_leads_score CHECK (lead_score IS NULL OR (lead_score >= 0 AND lead_score <= 100))
);
CREATE INDEX idx_leads_company ON leads(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_leads_status ON leads(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_leads_customer ON leads(customer_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_leads_assigned ON leads(assigned_to) WHERE deleted_at IS NULL;

-- ============================================================
-- SALES QUOTATIONS
-- ============================================================
CREATE TABLE sales_quotations (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  branch_id             BIGINT NULL REFERENCES branches(id),
  quotation_number      VARCHAR(40) NOT NULL,
  lead_id               BIGINT NULL REFERENCES leads(id),
  customer_id           BIGINT NOT NULL REFERENCES customers(id),
  channel               sales_channel NOT NULL,
  sales_employee_id     BIGINT NOT NULL REFERENCES users(id),
  version               INT NOT NULL DEFAULT 1,
  parent_quotation_id   BIGINT NULL REFERENCES sales_quotations(id),
  is_latest_version     BOOLEAN NOT NULL DEFAULT true,
  status                quotation_status NOT NULL DEFAULT 'draft',
  approval_status       approval_status NOT NULL DEFAULT 'not_required',
  valid_until           DATE,
  currency_code         VARCHAR(3) NOT NULL,
  exchange_rate         NUMERIC(18,6) NOT NULL DEFAULT 1,
  subtotal_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
  discount_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
  tax_amount            NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_amount          NUMERIC(19,4) NOT NULL DEFAULT 0,
  base_currency_total_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  payment_terms         VARCHAR(20) NOT NULL DEFAULT 'due_on_receipt',
  accepted_via          VARCHAR(30),
  accepted_by_name      VARCHAR(255),
  accepted_at           TIMESTAMPTZ,
  rejected_reason       TEXT,
  notes                 TEXT,
  internal_notes        TEXT,
  cost_center_id        BIGINT NULL REFERENCES cost_centers(id),
  project_id            BIGINT NULL REFERENCES projects(id),
  created_by            BIGINT NULL REFERENCES users(id),
  updated_by            BIGINT NULL REFERENCES users(id),
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at            TIMESTAMPTZ NULL,
  CONSTRAINT uq_quotation_number UNIQUE (company_id, quotation_number),
  CONSTRAINT chk_quotation_totals CHECK (total_amount = subtotal_amount - discount_amount + tax_amount)
);
CREATE INDEX idx_sq_company ON sales_quotations(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_sq_customer ON sales_quotations(customer_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_sq_status ON sales_quotations(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_sq_lead ON sales_quotations(lead_id);
CREATE INDEX idx_sq_parent ON sales_quotations(parent_quotation_id);
CREATE UNIQUE INDEX uq_sq_latest_version ON sales_quotations(parent_quotation_id)
  WHERE is_latest_version = true AND parent_quotation_id IS NOT NULL;

CREATE TABLE sales_quotation_items (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  sales_quotation_id    BIGINT NOT NULL REFERENCES sales_quotations(id) ON DELETE CASCADE,
  product_id            BIGINT NOT NULL REFERENCES products(id),
  product_variant_id    BIGINT NULL REFERENCES product_variants(id),
  description           TEXT,
  quantity              NUMERIC(18,4) NOT NULL,
  uom_id                BIGINT NOT NULL REFERENCES units_of_measure(id),
  base_unit_price       NUMERIC(19,4) NOT NULL,
  price_list_item_id    BIGINT NULL REFERENCES price_list_items(id),
  is_price_overridden   BOOLEAN NOT NULL DEFAULT false,
  override_reason       TEXT,
  price_rule_id         BIGINT NULL REFERENCES price_rules(id),
  rule_adjustment_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  promotion_adjustment_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  coupon_adjustment_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  discount_type         discount_type,
  discount_value        NUMERIC(19,4),
  discount_id           BIGINT NULL REFERENCES discounts(id),
  manual_discount_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  unit_price            NUMERIC(19,4) NOT NULL,
  line_subtotal         NUMERIC(19,4) NOT NULL,
  tax_code_id           BIGINT NULL REFERENCES tax_codes(id),
  tax_rate_snapshot     NUMERIC(6,3) NOT NULL DEFAULT 0,
  tax_amount            NUMERIC(19,4) NOT NULL DEFAULT 0,
  line_total             NUMERIC(19,4) NOT NULL,
  sort_order             INT NOT NULL DEFAULT 0,
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at             TIMESTAMPTZ NULL,
  CONSTRAINT chk_sqi_quantity CHECK (quantity > 0),
  CONSTRAINT chk_sqi_line_total CHECK (line_total = line_subtotal + tax_amount)
);
CREATE INDEX idx_sqi_quotation ON sales_quotation_items(sales_quotation_id);
CREATE INDEX idx_sqi_product ON sales_quotation_items(product_id);

-- ============================================================
-- SALES ORDERS
-- ============================================================
CREATE TABLE sales_orders (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  branch_id              BIGINT NULL REFERENCES branches(id),
  order_number           VARCHAR(40) NOT NULL,
  quotation_id           BIGINT NULL REFERENCES sales_quotations(id),
  customer_id            BIGINT NOT NULL REFERENCES customers(id),
  channel                sales_channel NOT NULL,
  sales_employee_id      BIGINT NOT NULL REFERENCES users(id),
  status                 sales_order_status NOT NULL DEFAULT 'draft',
  approval_status        approval_status NOT NULL DEFAULT 'not_required',
  order_date             DATE NOT NULL DEFAULT CURRENT_DATE,
  requested_delivery_date DATE,
  promised_delivery_date  DATE,
  customer_po_number      VARCHAR(60),
  billing_policy           billing_policy NOT NULL DEFAULT 'on_delivery',
  payment_terms            VARCHAR(20) NOT NULL DEFAULT 'due_on_receipt',
  currency_code            VARCHAR(3) NOT NULL,
  exchange_rate            NUMERIC(18,6) NOT NULL DEFAULT 1,
  subtotal_amount          NUMERIC(19,4) NOT NULL DEFAULT 0,
  discount_amount          NUMERIC(19,4) NOT NULL DEFAULT 0,
  tax_amount               NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_amount             NUMERIC(19,4) NOT NULL DEFAULT 0,
  base_currency_total_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  credit_check_status       credit_check_status NOT NULL DEFAULT 'passed',
  credit_override_by        BIGINT NULL REFERENCES users(id),
  credit_override_reason    TEXT,
  is_recurring              BOOLEAN NOT NULL DEFAULT false,
  recurrence_rule           JSONB,
  pos_combined_checkout     BOOLEAN NOT NULL DEFAULT false,
  allow_partial_confirmation BOOLEAN NOT NULL DEFAULT false,
  warehouse_id               BIGINT NULL REFERENCES warehouses(id),
  shipping_address_id        BIGINT NULL REFERENCES customer_addresses(id),
  billing_address_id         BIGINT NULL REFERENCES customer_addresses(id),
  fraud_risk_score            NUMERIC(5,2),
  cost_center_id               BIGINT NULL REFERENCES cost_centers(id),
  project_id                   BIGINT NULL REFERENCES projects(id),
  cancelled_at                 TIMESTAMPTZ,
  cancelled_reason              TEXT,
  created_by                    BIGINT NULL REFERENCES users(id),
  updated_by                    BIGINT NULL REFERENCES users(id),
  created_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                    TIMESTAMPTZ NULL,
  CONSTRAINT uq_order_number UNIQUE (company_id, order_number),
  CONSTRAINT chk_so_totals CHECK (total_amount = subtotal_amount - discount_amount + tax_amount)
);
CREATE INDEX idx_so_company ON sales_orders(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_so_customer ON sales_orders(customer_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_so_status ON sales_orders(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_so_quotation ON sales_orders(quotation_id);
CREATE INDEX idx_so_channel ON sales_orders(company_id, channel);
CREATE INDEX idx_so_employee ON sales_orders(sales_employee_id);

CREATE TABLE sales_order_items (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  sales_order_id         BIGINT NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
  sales_quotation_item_id BIGINT NULL REFERENCES sales_quotation_items(id),
  product_id             BIGINT NOT NULL REFERENCES products(id),
  product_variant_id     BIGINT NULL REFERENCES product_variants(id),
  description             TEXT,
  quantity                 NUMERIC(18,4) NOT NULL,
  uom_id                    BIGINT NOT NULL REFERENCES units_of_measure(id),
  base_unit_price            NUMERIC(19,4) NOT NULL,
  price_list_item_id          BIGINT NULL REFERENCES price_list_items(id),
  is_price_overridden          BOOLEAN NOT NULL DEFAULT false,
  price_rule_id                 BIGINT NULL REFERENCES price_rules(id),
  rule_adjustment_amount          NUMERIC(19,4) NOT NULL DEFAULT 0,
  promotion_adjustment_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
  coupon_adjustment_amount          NUMERIC(19,4) NOT NULL DEFAULT 0,
  discount_id                        BIGINT NULL REFERENCES discounts(id),
  manual_discount_amount              NUMERIC(19,4) NOT NULL DEFAULT 0,
  unit_price                           NUMERIC(19,4) NOT NULL,
  line_subtotal                         NUMERIC(19,4) NOT NULL,
  tax_code_id                            BIGINT NULL REFERENCES tax_codes(id),
  tax_rate_snapshot                       NUMERIC(6,3) NOT NULL DEFAULT 0,
  tax_amount                                NUMERIC(19,4) NOT NULL DEFAULT 0,
  line_total                                 NUMERIC(19,4) NOT NULL,
  warehouse_id                                BIGINT NULL REFERENCES warehouses(id),
  split_group_id                               UUID,
  quantity_reserved                             NUMERIC(18,4) NOT NULL DEFAULT 0,
  quantity_picked                                NUMERIC(18,4) NOT NULL DEFAULT 0,
  quantity_shipped                                NUMERIC(18,4) NOT NULL DEFAULT 0,
  quantity_invoiced                                NUMERIC(18,4) NOT NULL DEFAULT 0,
  quantity_returned                                 NUMERIC(18,4) NOT NULL DEFAULT 0,
  is_backorder                                       BOOLEAN NOT NULL DEFAULT false,
  cost_center_id                                      BIGINT NULL REFERENCES cost_centers(id),
  project_id                                           BIGINT NULL REFERENCES projects(id),
  sort_order                                            INT NOT NULL DEFAULT 0,
  created_at                                             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                              TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                               TIMESTAMPTZ NULL,
  CONSTRAINT chk_soi_quantity CHECK (quantity > 0),
  CONSTRAINT chk_soi_fulfillment CHECK (
    quantity_shipped <= quantity AND quantity_invoiced <= quantity
    AND quantity_returned <= quantity_shipped
  )
);
CREATE INDEX idx_soi_order ON sales_order_items(sales_order_id);
CREATE INDEX idx_soi_product ON sales_order_items(product_id);
CREATE INDEX idx_soi_warehouse ON sales_order_items(warehouse_id);

CREATE TABLE sales_order_amendments (
  id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id       BIGINT NOT NULL REFERENCES companies(id),
  sales_order_id   BIGINT NOT NULL REFERENCES sales_orders(id),
  sales_order_item_id BIGINT NULL REFERENCES sales_order_items(id),
  amendment_type   VARCHAR(30) NOT NULL,
  old_values       JSONB NOT NULL,
  new_values       JSONB NOT NULL,
  reason           TEXT NOT NULL,
  created_by       BIGINT NOT NULL REFERENCES users(id),
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_soa_order ON sales_order_amendments(sales_order_id);

-- ============================================================
-- DELIVERIES
-- ============================================================
CREATE TABLE deliveries (
  id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id           BIGINT NOT NULL REFERENCES companies(id),
  branch_id            BIGINT NULL REFERENCES branches(id),
  delivery_number      VARCHAR(40) NOT NULL,
  sales_order_id       BIGINT NOT NULL REFERENCES sales_orders(id),
  customer_id          BIGINT NOT NULL REFERENCES customers(id),
  warehouse_id         BIGINT NOT NULL REFERENCES warehouses(id),
  status               delivery_status NOT NULL DEFAULT 'pending',
  delivery_date        DATE,
  actual_delivery_date DATE,
  shipping_address_id  BIGINT NULL REFERENCES customer_addresses(id),
  delivery_method      delivery_method NOT NULL DEFAULT 'carrier',
  carrier_id           BIGINT NULL REFERENCES shipping_carriers(id),
  tracking_number      VARCHAR(80),
  tracking_url         TEXT,
  shipping_cost_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_weight_kg      NUMERIC(12,3),
  total_volume_m3      NUMERIC(12,4),
  package_count        INT NOT NULL DEFAULT 0,
  pod_attachment_id    BIGINT NULL REFERENCES attachments(id),
  pod_captured_at      TIMESTAMPTZ,
  pod_captured_by_name VARCHAR(255),
  failure_reason       TEXT,
  split_group_id       UUID,
  created_by           BIGINT NULL REFERENCES users(id),
  updated_by           BIGINT NULL REFERENCES users(id),
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at           TIMESTAMPTZ NULL,
  CONSTRAINT uq_delivery_number UNIQUE (company_id, delivery_number)
);
CREATE INDEX idx_del_company ON deliveries(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_del_order ON deliveries(sales_order_id);
CREATE INDEX idx_del_status ON deliveries(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_del_warehouse ON deliveries(warehouse_id);

CREATE TABLE delivery_items (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  delivery_id            BIGINT NOT NULL REFERENCES deliveries(id) ON DELETE CASCADE,
  sales_order_item_id     BIGINT NOT NULL REFERENCES sales_order_items(id),
  product_id               BIGINT NOT NULL REFERENCES products(id),
  quantity_ordered           NUMERIC(18,4) NOT NULL,
  quantity_to_deliver          NUMERIC(18,4) NOT NULL,
  quantity_delivered            NUMERIC(18,4) NOT NULL DEFAULT 0,
  uom_id                         BIGINT NOT NULL REFERENCES units_of_measure(id),
  warehouse_bin_id                BIGINT NULL REFERENCES warehouse_bins(id),
  batch_id                         BIGINT NULL REFERENCES product_batches(id),
  serial_id                         BIGINT NULL REFERENCES product_serials(id),
  pick_shortage                      BOOLEAN NOT NULL DEFAULT false,
  created_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                           TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                            TIMESTAMPTZ NULL,
  CONSTRAINT chk_di_quantity CHECK (quantity_delivered <= quantity_to_deliver)
);
CREATE INDEX idx_dei_delivery ON delivery_items(delivery_id);
CREATE INDEX idx_dei_order_item ON delivery_items(sales_order_item_id);

CREATE TABLE delivery_packages (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id      BIGINT NOT NULL REFERENCES companies(id),
  delivery_id     BIGINT NOT NULL REFERENCES deliveries(id) ON DELETE CASCADE,
  package_barcode VARCHAR(80),
  weight_kg       NUMERIC(12,3),
  length_cm       NUMERIC(10,2),
  width_cm        NUMERIC(10,2),
  height_cm       NUMERIC(10,2),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_dp_delivery ON delivery_packages(delivery_id);

CREATE TABLE delivery_status_history (
  id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    BIGINT NOT NULL REFERENCES companies(id),
  delivery_id   BIGINT NOT NULL REFERENCES deliveries(id) ON DELETE CASCADE,
  status        delivery_status NOT NULL,
  location_text VARCHAR(255),
  raw_payload   JSONB,
  occurred_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_dsh_delivery ON delivery_status_history(delivery_id);

CREATE TABLE shipping_carriers (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  name                  VARCHAR(120) NOT NULL,
  carrier_type          VARCHAR(30) NOT NULL,
  api_provider          VARCHAR(60),
  default_service_level VARCHAR(60),
  is_active             BOOLEAN NOT NULL DEFAULT true,
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================
-- INVOICES
-- ============================================================
CREATE TABLE invoices (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  branch_id              BIGINT NULL REFERENCES branches(id),
  type                   VARCHAR(10) NOT NULL DEFAULT 'sales',
  invoice_number         VARCHAR(40) NOT NULL,
  sales_order_id         BIGINT NULL REFERENCES sales_orders(id),
  delivery_id            BIGINT NULL REFERENCES deliveries(id),
  customer_id            BIGINT NOT NULL REFERENCES customers(id),
  channel                sales_channel NOT NULL,
  invoice_date           DATE NOT NULL DEFAULT CURRENT_DATE,
  due_date               DATE NOT NULL,
  currency_code          VARCHAR(3) NOT NULL,
  exchange_rate          NUMERIC(18,6) NOT NULL DEFAULT 1,
  subtotal_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,
  discount_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,
  tax_amount             NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_amount           NUMERIC(19,4) NOT NULL DEFAULT 0,
  base_currency_total_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  amount_paid            NUMERIC(19,4) NOT NULL DEFAULT 0,
  balance_due            NUMERIC(19,4) GENERATED ALWAYS AS (total_amount - amount_paid) STORED,
  status                 invoice_status NOT NULL DEFAULT 'draft',
  payment_status          payment_status NOT NULL DEFAULT 'unpaid',
  posted_at               TIMESTAMPTZ,
  posted_by               BIGINT NULL REFERENCES users(id),
  journal_entry_id        BIGINT NULL REFERENCES journal_entries(id),
  void_reason              TEXT,
  voided_at                TIMESTAMPTZ,
  voided_by                 BIGINT NULL REFERENCES users(id),
  credit_note_id             BIGINT NULL,
  cost_center_id               BIGINT NULL REFERENCES cost_centers(id),
  project_id                    BIGINT NULL REFERENCES projects(id),
  pdf_attachment_id               BIGINT NULL REFERENCES attachments(id),
  created_by                       BIGINT NULL REFERENCES users(id),
  updated_by                        BIGINT NULL REFERENCES users(id),
  created_at                         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                           TIMESTAMPTZ NULL,
  CONSTRAINT uq_invoice_number UNIQUE (company_id, invoice_number),
  CONSTRAINT chk_invoice_type CHECK (type IN ('sales')),
  CONSTRAINT chk_invoice_totals CHECK (total_amount = subtotal_amount - discount_amount + tax_amount)
);
CREATE INDEX idx_inv_company ON invoices(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_inv_customer ON invoices(customer_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_inv_status ON invoices(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_inv_due ON invoices(company_id, due_date) WHERE status IN ('posted','partially_paid');
CREATE INDEX idx_inv_order ON invoices(sales_order_id);
CREATE INDEX idx_inv_delivery ON invoices(delivery_id);

CREATE TABLE invoice_items (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  invoice_id        BIGINT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  sales_order_item_id BIGINT NULL REFERENCES sales_order_items(id),
  delivery_item_id    BIGINT NULL REFERENCES delivery_items(id),
  product_id           BIGINT NOT NULL REFERENCES products(id),
  description            TEXT,
  quantity                 NUMERIC(18,4) NOT NULL,
  uom_id                     BIGINT NOT NULL REFERENCES units_of_measure(id),
  unit_price                  NUMERIC(19,4) NOT NULL,
  discount_amount               NUMERIC(19,4) NOT NULL DEFAULT 0,
  tax_code_id                    BIGINT NULL REFERENCES tax_codes(id),
  tax_rate_snapshot                NUMERIC(6,3) NOT NULL DEFAULT 0,
  additional_taxes                   JSONB NOT NULL DEFAULT '[]',
  tax_amount                          NUMERIC(19,4) NOT NULL DEFAULT 0,
  line_subtotal                        NUMERIC(19,4) NOT NULL,
  line_total                             NUMERIC(19,4) NOT NULL,
  cogs_unit_cost                          NUMERIC(19,4),
  cost_center_id                            BIGINT NULL REFERENCES cost_centers(id),
  project_id                                 BIGINT NULL REFERENCES projects(id),
  sort_order                                  INT NOT NULL DEFAULT 0,
  created_at                                   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                     TIMESTAMPTZ NULL,
  CONSTRAINT chk_ii_quantity CHECK (quantity > 0)
);
CREATE INDEX idx_ii_invoice ON invoice_items(invoice_id);
CREATE INDEX idx_ii_product ON invoice_items(product_id);

-- ============================================================
-- RECEIPTS
-- ============================================================
CREATE TABLE receipts (
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  branch_id           BIGINT NULL REFERENCES branches(id),
  receipt_number      VARCHAR(40) NOT NULL,
  customer_id         BIGINT NOT NULL REFERENCES customers(id),
  receipt_date        DATE NOT NULL DEFAULT CURRENT_DATE,
  payment_method      payment_method NOT NULL,
  currency_code       VARCHAR(3) NOT NULL,
  exchange_rate       NUMERIC(18,6) NOT NULL DEFAULT 1,
  amount              NUMERIC(19,4) NOT NULL,
  base_currency_amount NUMERIC(19,4) NOT NULL,
  bank_account_id     BIGINT NULL REFERENCES bank_accounts(id),
  reference_number    VARCHAR(80),
  status              receipt_status NOT NULL DEFAULT 'cleared',
  journal_entry_id    BIGINT NULL REFERENCES journal_entries(id),
  created_by          BIGINT NULL REFERENCES users(id),
  updated_by          BIGINT NULL REFERENCES users(id),
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at          TIMESTAMPTZ NULL,
  CONSTRAINT uq_receipt_number UNIQUE (company_id, receipt_number),
  CONSTRAINT chk_receipt_amount CHECK (amount > 0)
);
CREATE INDEX idx_rc_company ON receipts(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_rc_customer ON receipts(customer_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_rc_status ON receipts(company_id, status);

CREATE TABLE receipt_allocations (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  receipt_id        BIGINT NOT NULL REFERENCES receipts(id) ON DELETE CASCADE,
  invoice_id        BIGINT NOT NULL REFERENCES invoices(id),
  amount_allocated  NUMERIC(19,4) NOT NULL,
  allocation_method allocation_method NOT NULL DEFAULT 'manual',
  allocated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  allocated_by      BIGINT NULL REFERENCES users(id),
  reversed_at       TIMESTAMPTZ,
  CONSTRAINT chk_ra_amount CHECK (amount_allocated > 0)
);
CREATE INDEX idx_ra_receipt ON receipt_allocations(receipt_id);
CREATE INDEX idx_ra_invoice ON receipt_allocations(invoice_id);

-- ============================================================
-- RETURNS
-- ============================================================
CREATE TABLE sales_returns (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  branch_id             BIGINT NULL REFERENCES branches(id),
  rma_number            VARCHAR(40) NOT NULL,
  invoice_id            BIGINT NOT NULL REFERENCES invoices(id),
  customer_id           BIGINT NOT NULL REFERENCES customers(id),
  return_reason         return_reason NOT NULL,
  return_reason_notes   TEXT,
  status                return_status NOT NULL DEFAULT 'requested',
  requested_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  approved_by           BIGINT NULL REFERENCES users(id),
  approved_at           TIMESTAMPTZ,
  resolution_type       return_resolution,
  restocking_fee_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  warehouse_id          BIGINT NULL REFERENCES warehouses(id),
  related_sales_order_id BIGINT NULL REFERENCES sales_orders(id),
  created_by            BIGINT NULL REFERENCES users(id),
  updated_by            BIGINT NULL REFERENCES users(id),
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at            TIMESTAMPTZ NULL,
  CONSTRAINT uq_rma_number UNIQUE (company_id, rma_number)
);
CREATE INDEX idx_sr_company ON sales_returns(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_sr_invoice ON sales_returns(invoice_id);
CREATE INDEX idx_sr_status ON sales_returns(company_id, status);

CREATE TABLE sales_return_items (
  id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id           BIGINT NOT NULL REFERENCES companies(id),
  sales_return_id      BIGINT NOT NULL REFERENCES sales_returns(id) ON DELETE CASCADE,
  invoice_item_id      BIGINT NOT NULL REFERENCES invoice_items(id),
  product_id           BIGINT NOT NULL REFERENCES products(id),
  quantity_returned    NUMERIC(18,4) NOT NULL,
  uom_id               BIGINT NOT NULL REFERENCES units_of_measure(id),
  condition            item_condition,
  disposition          item_disposition,
  unit_refund_amount   NUMERIC(19,4) NOT NULL DEFAULT 0,
  line_refund_amount   NUMERIC(19,4) NOT NULL DEFAULT 0,
  tax_refund_amount    NUMERIC(19,4) NOT NULL DEFAULT 0,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT chk_sri_quantity CHECK (quantity_returned > 0)
);
CREATE INDEX idx_sri_return ON sales_return_items(sales_return_id);

-- ============================================================
-- CREDIT NOTES & REFUNDS
-- ============================================================
CREATE TABLE credit_notes (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  branch_id              BIGINT NULL REFERENCES branches(id),
  credit_note_number     VARCHAR(40) NOT NULL,
  invoice_id             BIGINT NOT NULL REFERENCES invoices(id),
  sales_return_id        BIGINT NULL REFERENCES sales_returns(id),
  customer_id            BIGINT NOT NULL REFERENCES customers(id),
  reason                 credit_note_reason NOT NULL,
  currency_code          VARCHAR(3) NOT NULL,
  exchange_rate          NUMERIC(18,6) NOT NULL DEFAULT 1,
  subtotal_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,
  tax_amount             NUMERIC(19,4) NOT NULL DEFAULT 0,
  total_amount           NUMERIC(19,4) NOT NULL DEFAULT 0,
  base_currency_total_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
  status                 credit_note_status NOT NULL DEFAULT 'draft',
  applied_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
  remaining_amount       NUMERIC(19,4) GENERATED ALWAYS AS (total_amount - applied_amount) STORED,
  store_credit_expiry_days INT,
  journal_entry_id       BIGINT NULL REFERENCES journal_entries(id),
  posted_at              TIMESTAMPTZ,
  posted_by              BIGINT NULL REFERENCES users(id),
  created_by             BIGINT NULL REFERENCES users(id),
  updated_by             BIGINT NULL REFERENCES users(id),
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at             TIMESTAMPTZ NULL,
  CONSTRAINT uq_cn_number UNIQUE (company_id, credit_note_number),
  CONSTRAINT chk_cn_totals CHECK (total_amount = subtotal_amount + tax_amount)
);
CREATE INDEX idx_cn_company ON credit_notes(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_cn_invoice ON credit_notes(invoice_id);
CREATE INDEX idx_cn_customer ON credit_notes(customer_id) WHERE deleted_at IS NULL;

ALTER TABLE invoices
  ADD CONSTRAINT fk_invoices_credit_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id);

CREATE TABLE credit_note_items (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  credit_note_id    BIGINT NOT NULL REFERENCES credit_notes(id) ON DELETE CASCADE,
  invoice_item_id   BIGINT NOT NULL REFERENCES invoice_items(id),
  product_id        BIGINT NOT NULL REFERENCES products(id),
  quantity          NUMERIC(18,4) NOT NULL,
  unit_price        NUMERIC(19,4) NOT NULL,
  tax_code_id       BIGINT NULL REFERENCES tax_codes(id),
  tax_rate_snapshot NUMERIC(6,3) NOT NULL DEFAULT 0,
  tax_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,
  line_total        NUMERIC(19,4) NOT NULL,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_cni_note ON credit_note_items(credit_note_id);

CREATE TABLE credit_note_applications (
  id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id       BIGINT NOT NULL REFERENCES companies(id),
  credit_note_id   BIGINT NOT NULL REFERENCES credit_notes(id) ON DELETE CASCADE,
  invoice_id       BIGINT NOT NULL REFERENCES invoices(id),
  amount_applied   NUMERIC(19,4) NOT NULL,
  applied_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  applied_by       BIGINT NULL REFERENCES users(id),
  CONSTRAINT chk_cna_amount CHECK (amount_applied > 0)
);
CREATE INDEX idx_cna_note ON credit_note_applications(credit_note_id);
CREATE INDEX idx_cna_invoice ON credit_note_applications(invoice_id);

CREATE TABLE refunds (
  id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id         BIGINT NOT NULL REFERENCES companies(id),
  branch_id          BIGINT NULL REFERENCES branches(id),
  refund_number      VARCHAR(40) NOT NULL,
  credit_note_id     BIGINT NOT NULL REFERENCES credit_notes(id),
  customer_id        BIGINT NOT NULL REFERENCES customers(id),
  refund_method      refund_method NOT NULL,
  amount             NUMERIC(19,4) NOT NULL,
  base_currency_amount NUMERIC(19,4) NOT NULL,
  bank_account_id    BIGINT NULL REFERENCES bank_accounts(id),
  original_receipt_id BIGINT NULL REFERENCES receipts(id),
  status             refund_status NOT NULL DEFAULT 'pending',
  processed_at        TIMESTAMPTZ,
  journal_entry_id    BIGINT NULL REFERENCES journal_entries(id),
  created_by           BIGINT NULL REFERENCES users(id),
  updated_by            BIGINT NULL REFERENCES users(id),
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at               TIMESTAMPTZ NULL,
  CONSTRAINT uq_refund_number UNIQUE (company_id, refund_number),
  CONSTRAINT chk_refund_amount CHECK (amount > 0)
);
CREATE INDEX idx_rf_company ON refunds(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_rf_credit_note ON refunds(credit_note_id);
CREATE INDEX idx_rf_customer ON refunds(customer_id);

-- ============================================================
-- DISCOUNTS, COUPONS, PROMOTIONS, PRICE RULES
-- ============================================================
CREATE TABLE discounts (
  id                            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                    BIGINT NOT NULL REFERENCES companies(id),
  code                           VARCHAR(40) NOT NULL,
  name_en                         VARCHAR(120) NOT NULL,
  name_ar                          VARCHAR(120),
  discount_type                     discount_type NOT NULL,
  value                               NUMERIC(19,4) NOT NULL,
  applies_to                           applies_to_scope NOT NULL DEFAULT 'all_products',
  category_id                           BIGINT NULL REFERENCES product_categories(id),
  product_ids                            JSONB NOT NULL DEFAULT '[]',
  max_uses_total                          INT,
  max_uses_per_customer                    INT,
  current_uses                              INT NOT NULL DEFAULT 0,
  requires_approval_above_value              NUMERIC(19,4),
  requires_manager_pin                        BOOLEAN NOT NULL DEFAULT false,
  valid_from                                   DATE,
  valid_until                                   DATE,
  status                                         VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by                                      BIGINT NULL REFERENCES users(id),
  updated_by                                       BIGINT NULL REFERENCES users(id),
  created_at                                        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                         TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                          TIMESTAMPTZ NULL,
  CONSTRAINT uq_discount_code UNIQUE (company_id, code)
);
CREATE INDEX idx_disc_company ON discounts(company_id) WHERE deleted_at IS NULL;

CREATE TABLE coupons (
  id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                  BIGINT NOT NULL REFERENCES companies(id),
  code                          VARCHAR(60) NOT NULL,
  discount_id                    BIGINT NOT NULL REFERENCES discounts(id),
  campaign_name                    VARCHAR(120),
  distribution_channel               VARCHAR(30),
  max_redemptions_total                INT,
  max_redemptions_per_customer          INT NOT NULL DEFAULT 1,
  current_redemptions                    INT NOT NULL DEFAULT 0,
  minimum_order_amount                    NUMERIC(19,4),
  stackable_with_promotions                BOOLEAN NOT NULL DEFAULT false,
  single_use_token                          BOOLEAN NOT NULL DEFAULT false,
  valid_from                                 DATE,
  valid_until                                 DATE,
  status                                       VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                      TIMESTAMPTZ NULL,
  CONSTRAINT uq_coupon_code UNIQUE (company_id, code)
);
CREATE INDEX idx_coup_company ON coupons(company_id) WHERE deleted_at IS NULL;

CREATE TABLE coupon_redemptions (
  id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                BIGINT NOT NULL REFERENCES companies(id),
  coupon_id                 BIGINT NOT NULL REFERENCES coupons(id),
  customer_id                BIGINT NOT NULL REFERENCES customers(id),
  sales_quotation_id          BIGINT NULL REFERENCES sales_quotations(id),
  sales_order_id                BIGINT NULL REFERENCES sales_orders(id),
  discount_amount_applied         NUMERIC(19,4) NOT NULL,
  redeemed_at                       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_credm_coupon ON coupon_redemptions(coupon_id);
CREATE INDEX idx_credm_customer ON coupon_redemptions(customer_id);

CREATE TABLE promotions (
  id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id               BIGINT NOT NULL REFERENCES companies(id),
  name_en                   VARCHAR(120) NOT NULL,
  name_ar                    VARCHAR(120),
  promotion_type              VARCHAR(30) NOT NULL,
  conditions                    JSONB NOT NULL DEFAULT '{}',
  reward                          JSONB NOT NULL DEFAULT '{}',
  applicable_channels               JSONB NOT NULL DEFAULT '[]',
  priority                            INT NOT NULL DEFAULT 100,
  is_exclusive                         BOOLEAN NOT NULL DEFAULT false,
  valid_from                            DATE NOT NULL,
  valid_until                            DATE NOT NULL,
  status                                  VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  usage_limit_total                        INT,
  usage_count                                INT NOT NULL DEFAULT 0,
  created_by                                  BIGINT NULL REFERENCES users(id),
  created_at                                   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                     TIMESTAMPTZ NULL
);
CREATE INDEX idx_prom_company ON promotions(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_prom_active ON promotions(company_id, status, valid_from, valid_until);

CREATE TABLE price_rules (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  name_en            VARCHAR(120) NOT NULL,
  name_ar             VARCHAR(120),
  rule_type            VARCHAR(30) NOT NULL,
  priority               INT NOT NULL DEFAULT 100,
  conditions               JSONB NOT NULL DEFAULT '{}',
  price_list_id             BIGINT NULL REFERENCES price_lists(id),
  adjustment_type            VARCHAR(20) NOT NULL,
  adjustment_value             NUMERIC(19,4),
  applies_to                    applies_to_scope NOT NULL DEFAULT 'all_products',
  category_id                    BIGINT NULL REFERENCES product_categories(id),
  product_ids                     JSONB NOT NULL DEFAULT '[]',
  valid_from                       DATE,
  valid_until                       DATE,
  status                             VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at                          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                           TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                            TIMESTAMPTZ NULL
);
CREATE INDEX idx_pr_company ON price_rules(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_pr_priority ON price_rules(company_id, priority);

-- ============================================================
-- APPROVALS & AI RECOMMENDATIONS (shared, polymorphic)
-- ============================================================
CREATE TABLE approval_requests (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  approvable_type   VARCHAR(40) NOT NULL,
  approvable_id     BIGINT NOT NULL,
  requested_by      BIGINT NOT NULL REFERENCES users(id),
  approver_id       BIGINT NULL REFERENCES users(id),
  sequence_number   INT NOT NULL DEFAULT 1,
  status            approval_status NOT NULL DEFAULT 'pending',
  reason_context    TEXT,
  decision_comment  TEXT,
  decided_at        TIMESTAMPTZ,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ar_approvable ON approval_requests(approvable_type, approvable_id);
CREATE INDEX idx_ar_approver ON approval_requests(approver_id, status);

CREATE TABLE ai_recommendations (
  id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id               BIGINT NOT NULL REFERENCES companies(id),
  recommendable_type       VARCHAR(40) NOT NULL,
  recommendable_id         BIGINT NOT NULL,
  agent_name               VARCHAR(60) NOT NULL,
  recommendation_type      VARCHAR(60) NOT NULL,
  payload                  JSONB NOT NULL,
  confidence               NUMERIC(4,3) NOT NULL,
  reasoning                TEXT NOT NULL,
  supporting_document_ids  JSONB NOT NULL DEFAULT '[]',
  status                   VARCHAR(20) NOT NULL DEFAULT 'pending',
  acted_by                 BIGINT NULL REFERENCES users(id),
  acted_at                 TIMESTAMPTZ,
  created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT chk_ai_confidence CHECK (confidence >= 0 AND confidence <= 1)
);
CREATE INDEX idx_air_recommendable ON ai_recommendations(recommendable_type, recommendable_id);
CREATE INDEX idx_air_agent ON ai_recommendations(company_id, agent_name, status);
```

**History & versioning.** Quotations version via `sales_quotations.version` +
`parent_quotation_id`, never via a separate history table — the row set itself is the history.
Orders, invoices, credit notes, and receipts never version in place; corrections are new linked
documents (`sales_order_amendments`, a new credit note, a new receipt) exactly per the Sales
Philosophy's "reverse, never edit" rule. `audit_logs` (foundation table) captures field-level
before/after diffs for every mutation across all tables in this section, keyed by
`(table_name, record_id)`, independent of the domain-specific amendment/versioning trails above —
`audit_logs` is the forensic layer; the domain tables above are the business layer.

# API

All endpoints are REST/JSON under `/api/v1/sales/`, require a Bearer (Sanctum/JWT) token, are scoped
to the active company via the `X-Company-Id` header, and return the platform's standard response
envelope (`success`, `data`, `message`, `errors`, `meta.pagination`, `request_id`, `timestamp`).
Pagination defaults to 25/page, supports cursor pagination, filtering (`filter[status]=confirmed`),
sorting (`sort=-created_at`), and full-text search (`search=`) on document number and customer name.

**Endpoint table:**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/sales/leads` | `sales.lead.read` | List leads, filterable by status/source/assigned_to |
| POST | `/api/v1/sales/leads` | `sales.lead.create` | Create a lead |
| PATCH | `/api/v1/sales/leads/{id}` | `sales.lead.update` | Update lead status/assignment |
| POST | `/api/v1/sales/leads/{id}/convert` | `sales.lead.convert` | Convert a lead into a customer + quotation |
| GET | `/api/v1/sales/quotations` | `sales.quotation.read` | List quotations, filterable by status/customer/channel |
| POST | `/api/v1/sales/quotations` | `sales.quotation.create` | Create a draft quotation |
| GET | `/api/v1/sales/quotations/{id}` | `sales.quotation.read` | Retrieve a quotation with items |
| PATCH | `/api/v1/sales/quotations/{id}` | `sales.quotation.update` | Update a draft quotation |
| POST | `/api/v1/sales/quotations/{id}/revise` | `sales.quotation.update` | Create a new version of a sent quotation |
| POST | `/api/v1/sales/quotations/{id}/send` | `sales.quotation.send` | Move draft → sent |
| POST | `/api/v1/sales/quotations/{id}/accept` | `sales.quotation.accept` | Record customer acceptance, create sales order |
| POST | `/api/v1/sales/quotations/{id}/reject` | `sales.quotation.update` | Record rejection with reason |
| GET | `/api/v1/sales/orders` | `sales.order.read` | List sales orders, filterable by status/customer/channel/date range |
| POST | `/api/v1/sales/orders` | `sales.order.create` | Create a draft sales order (direct, no quotation) |
| GET | `/api/v1/sales/orders/{id}` | `sales.order.read` | Retrieve a sales order with items, fulfillment progress |
| PATCH | `/api/v1/sales/orders/{id}` | `sales.order.update` | Update a draft order |
| POST | `/api/v1/sales/orders/{id}/confirm` | `sales.order.confirm` | Run credit/stock/pricing gates and confirm |
| POST | `/api/v1/sales/orders/{id}/amend` | `sales.order.amend` | Amend a confirmed order (quantity/line change) |
| POST | `/api/v1/sales/orders/{id}/cancel` | `sales.order.cancel` | Cancel before delivery |
| GET | `/api/v1/sales/deliveries` | `sales.delivery.read` | List deliveries, filterable by status/warehouse |
| POST | `/api/v1/sales/deliveries` | `sales.delivery.create` | Create a delivery from a confirmed order |
| POST | `/api/v1/sales/deliveries/{id}/pick` | `sales.delivery.pick` | Confirm picked quantities |
| POST | `/api/v1/sales/deliveries/{id}/pack` | `sales.delivery.pack` | Confirm packages |
| POST | `/api/v1/sales/deliveries/{id}/ship` | `sales.delivery.ship` | Attach carrier/tracking, mark shipped |
| POST | `/api/v1/sales/deliveries/{id}/deliver` | `sales.delivery.deliver` | Record proof of delivery, mark delivered |
| GET | `/api/v1/sales/invoices` | `sales.invoice.read` | List invoices, filterable by status/customer/due date |
| POST | `/api/v1/sales/invoices` | `sales.invoice.create` | Generate a draft invoice from a delivery/order |
| GET | `/api/v1/sales/invoices/{id}` | `sales.invoice.read` | Retrieve an invoice with items |
| POST | `/api/v1/sales/invoices/{id}/post` | `sales.invoice.post` | Post invoice (creates journal entry) |
| POST | `/api/v1/sales/invoices/{id}/void` | `sales.invoice.void` | Void a draft (unposted) invoice |
| GET | `/api/v1/sales/receipts` | `sales.receipt.read` | List receipts |
| POST | `/api/v1/sales/receipts` | `sales.receipt.create` | Capture a receipt, optionally auto-allocate |
| POST | `/api/v1/sales/receipts/{id}/allocate` | `sales.receipt.allocate` | Manually allocate to specific invoices |
| POST | `/api/v1/sales/receipts/{id}/bounce` | `sales.receipt.reverse` | Reverse a bounced/failed receipt |
| GET | `/api/v1/sales/returns` | `sales.return.read` | List returns |
| POST | `/api/v1/sales/returns` | `sales.return.create` | Create an RMA against an invoice |
| POST | `/api/v1/sales/returns/{id}/approve` | `sales.return.approve` | Approve a return request |
| POST | `/api/v1/sales/returns/{id}/receive` | `sales.return.receive` | Record physical receipt + condition + disposition |
| GET | `/api/v1/sales/credit-notes` | `sales.credit_note.read` | List credit notes |
| POST | `/api/v1/sales/credit-notes` | `sales.credit_note.create` | Create a credit note against an invoice/return |
| POST | `/api/v1/sales/credit-notes/{id}/post` | `sales.credit_note.post` | Post credit note (reversing journal entry) |
| POST | `/api/v1/sales/refunds` | `sales.refund.create` | Create + process a refund against a credit note |
| GET | `/api/v1/sales/discounts` | `sales.pricing.read` | List discounts |
| POST | `/api/v1/sales/coupons/{code}/validate` | `sales.pricing.read` | Validate a coupon code against a cart |
| GET | `/api/v1/sales/dashboard` | `sales.report.read` | Aggregated dashboard metrics |
| POST | `/api/v1/sales/bulk/orders/confirm` | `sales.order.confirm` | Bulk-confirm multiple draft orders |
| POST | `/api/v1/sales/bulk/invoices/post` | `sales.invoice.post` | Bulk-post multiple draft invoices |

**Idempotency.** Every POST that creates a money-affecting document (`orders`, `invoices`,
`receipts`, `credit-notes`, `refunds`) accepts an `Idempotency-Key` header; a repeated request with
the same key within 24 hours returns the original response (`200` with the original `data`) instead
of creating a duplicate — this is the deterministic, non-AI half of Duplicate Detection referenced
under AI Responsibilities, and it is what actually prevents a retried POS/mobile-network request
from double-charging a customer.

**Filtering, pagination, bulk — example.**

```
GET /api/v1/sales/orders?filter[status]=confirmed&filter[channel]=wholesale
    &filter[order_date][gte]=2026-07-01&sort=-total_amount&page=2&per_page=25
```

**Example 1 — create and confirm a sales order (happy path):**

Request:
```json
POST /api/v1/sales/orders
X-Company-Id: 41
Idempotency-Key: 8f14e45f-ceea-4f1d-8b0c-1a1c6e4c1e2e
{
  "customer_id": 1042,
  "channel": "wholesale",
  "sales_employee_id": 17,
  "currency_code": "KWD",
  "warehouse_id": 3,
  "shipping_address_id": 501,
  "payment_terms": "net_30",
  "billing_policy": "on_delivery",
  "items": [
    { "product_id": 205, "quantity": 50, "uom_id": 1 },
    { "product_id": 318, "quantity": 20, "uom_id": 1 }
  ]
}
```

Response (`201 Created`):
```json
{
  "success": true,
  "data": {
    "id": 9931,
    "order_number": "SO-KWC-2026-08841",
    "status": "draft",
    "customer_id": 1042,
    "channel": "wholesale",
    "currency_code": "KWD",
    "subtotal_amount": "1840.0000",
    "discount_amount": "92.0000",
    "tax_amount": "87.4000",
    "total_amount": "1835.4000",
    "items": [
      { "id": 55021, "product_id": 205, "quantity": "50.0000", "unit_price": "24.0000",
        "line_subtotal": "1200.0000", "tax_amount": "60.0000", "line_total": "1260.0000" },
      { "id": 55022, "product_id": 318, "quantity": "20.0000", "unit_price": "32.0000",
        "line_subtotal": "548.0000", "tax_amount": "27.4000", "line_total": "575.4000" }
    ]
  },
  "message": "Sales order created as draft.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b3f0b6d2-9e2a-4a55-8b7a-6c9f0e2d1a44",
  "timestamp": "2026-07-16T09:12:03Z"
}
```

Then:
```json
POST /api/v1/sales/orders/9931/confirm
{}
```

Response (`200 OK`):
```json
{
  "success": true,
  "data": {
    "id": 9931,
    "status": "confirmed",
    "credit_check_status": "passed",
    "stock_reservations": [
      { "product_id": 205, "warehouse_id": 3, "quantity_reserved": "50.0000" },
      { "product_id": 318, "warehouse_id": 3, "quantity_reserved": "20.0000" }
    ]
  },
  "message": "Sales order confirmed; stock reserved.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "0d3e2f9a-1b7c-4e88-9a2d-3f5b6c7d8e90",
  "timestamp": "2026-07-16T09:12:41Z"
}
```

**Example 2 — order confirmation blocked by credit limit (`409 Conflict`):**

```json
{
  "success": false,
  "data": null,
  "message": "Order confirmation blocked: credit limit exceeded.",
  "errors": [
    {
      "code": "CREDIT_LIMIT_EXCEEDED",
      "detail": "Customer 1042 outstanding AR is KWD 4,820.000; this order (KWD 1,835.400) would bring exposure to KWD 6,655.400, exceeding the credit limit of KWD 6,000.000.",
      "field": null
    }
  ],
  "meta": { "pagination": null },
  "request_id": "5a6b7c8d-9e0f-1a2b-3c4d-5e6f7a8b9c0d",
  "timestamp": "2026-07-16T09:13:02Z"
}
```

**Example 3 — post an invoice:**

Request:
```json
POST /api/v1/sales/invoices/71204/post
{}
```

Response (`200 OK`):
```json
{
  "success": true,
  "data": {
    "id": 71204,
    "invoice_number": "INV-KWC-2026-14502",
    "status": "posted",
    "posted_at": "2026-07-16T10:02:11Z",
    "journal_entry_id": 553012,
    "total_amount": "1835.4000",
    "balance_due": "1835.4000"
  },
  "message": "Invoice posted. Journal entry 553012 created.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1c2d3e4f-5a6b-7c8d-9e0f-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T10:02:11Z"
}
```

**Example 4 — capture a receipt with auto-allocation:**

Request:
```json
POST /api/v1/sales/receipts
{
  "customer_id": 1042,
  "receipt_date": "2026-08-10",
  "payment_method": "bank_transfer",
  "currency_code": "KWD",
  "amount": "1835.4000",
  "bank_account_id": 4,
  "reference_number": "TRF-88213",
  "auto_allocate": true,
  "allocation_method": "auto_oldest_first"
}
```

Response (`201 Created`):
```json
{
  "success": true,
  "data": {
    "id": 30877,
    "receipt_number": "RC-KWC-2026-07310",
    "status": "cleared",
    "amount": "1835.4000",
    "unallocated_amount": "0.0000",
    "journal_entry_id": 553980,
    "allocations": [
      { "invoice_id": 71204, "amount_allocated": "1835.4000" }
    ]
  },
  "message": "Receipt captured and fully allocated.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "2d3e4f5a-6b7c-8d9e-0f1a-2b3c4d5e6f7a",
  "timestamp": "2026-08-10T11:45:00Z"
}
```

**Example 5 — validation error (`422`) on quotation creation with a negative discount:**

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    { "code": "VALIDATION_ERROR", "field": "items.0.discount_value",
      "detail": "discount_value must be between 0 and 100 for discount_type=percentage." }
  ],
  "meta": { "pagination": null },
  "request_id": "3e4f5a6b-7c8d-9e0f-1a2b-3c4d5e6f7a8b",
  "timestamp": "2026-07-16T09:10:00Z"
}
```

**Bulk operations** accept an array under `items` and return a per-item result array under
`data.results`, with `data.summary.succeeded`/`data.summary.failed` counts — a partial failure in a
bulk call never rolls back the successful items in the same batch (each item posts in its own
sub-transaction), and `errors[]` at the top level lists only request-level errors (e.g. malformed
payload), not per-item business errors (those live in `data.results[i].errors`).

# Reports

All reports are generated server-side (Laravel) from the tables in Database Design, exposed via
`GET /api/v1/sales/reports/{report_key}` with the standard filtering/pagination envelope, and are
also schedulable (`report_schedules`, foundation table) for recurring email/PDF delivery.

| Report | Key metrics | Primary dimensions | Grain |
|---|---|---|---|
| Sales Dashboard | Today's revenue, open pipeline value, orders awaiting approval, overdue AR | Company/branch, real-time | Live |
| Revenue | Gross revenue, discounts given, net revenue, tax collected | Day/week/month, channel, currency | Time series |
| Top Products | Units sold, revenue, margin | Product, category | Ranked list |
| Top Customers | Revenue, order count, average order value, on-time payment rate | Customer | Ranked list |
| Sales by Branch | Revenue, order count, conversion rate (quotation → order) | Branch | Comparative table |
| Sales by Employee | Revenue, order count, average discount given, quota attainment | Sales employee | Comparative table |
| Profit Analysis | Revenue, COGS, gross profit, gross margin % | Product/category/branch/period | Time series |
| Margin Analysis | Margin % trend, margin erosion from discounting | Product/customer/channel | Time series |
| Forecast | Forecasted revenue (P10/P50/P90) vs. actual, forecast accuracy (MAPE) | Month/quarter, branch | Time series (AI-sourced, see AI Responsibilities) |
| Returns | Return rate %, return reasons breakdown, refunded amount | Product/category/customer | Time series |
| Refunds | Refund amount by method, average time-to-refund | Refund method, branch | Time series |

**Sales Dashboard response example:**
```json
GET /api/v1/sales/reports/dashboard?branch_id=3
{
  "success": true,
  "data": {
    "today_revenue_base_currency": "12480.5000",
    "open_pipeline_value": "48200.0000",
    "open_pipeline_weighted_value": "19680.0000",
    "orders_awaiting_approval": 4,
    "overdue_invoices_count": 11,
    "overdue_invoices_amount": "9340.2500",
    "as_of": "2026-07-16T12:00:00Z"
  },
  "message": "Dashboard metrics for branch 3.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "4f5a6b7c-8d9e-0f1a-2b3c-4d5e6f7a8b9c",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

Every report supports `?currency=base` or `?currency=transaction` (see Multi Currency), an
`export=csv|xlsx|pdf` parameter that queues an async export job and returns a signed download URL
via webhook/notification on completion for large datasets (>10,000 rows), and a `compare_to`
parameter for period-over-period deltas (e.g. `compare_to=previous_period` or
`compare_to=same_period_last_year`).

# Permissions

Permission keys follow `sales.<entity>.<action>`, default-deny. The table below maps each key to
the default roles granted it (✓ = granted by default; roles not listed for a key have no access by
default and must be explicitly granted).

| Permission | Owner | Sales Manager | Sales Employee | Finance | Warehouse | Auditor | AI Agent |
|---|---|---|---|---|---|---|---|
| `sales.lead.create` | ✓ | ✓ | ✓ | | | | |
| `sales.lead.read` | ✓ | ✓ | ✓ (own) | ✓ | | ✓ | ✓ (read-only) |
| `sales.quotation.create` | ✓ | ✓ | ✓ | | | | |
| `sales.quotation.send` | ✓ | ✓ | ✓ | | | | |
| `sales.quotation.accept` | ✓ | ✓ | ✓ | | | | |
| `sales.order.create` | ✓ | ✓ | ✓ | | | | |
| `sales.order.confirm` | ✓ | ✓ | ✓ (below threshold) | | | | |
| `sales.order.amend` | ✓ | ✓ | | | | | |
| `sales.order.cancel` | ✓ | ✓ | | | | | |
| `sales.credit.override` | ✓ | ✓ | | ✓ | | | |
| `sales.delivery.pick` | ✓ | | | | ✓ | | |
| `sales.delivery.pack` | ✓ | | | | ✓ | | |
| `sales.delivery.ship` | ✓ | | | | ✓ | | |
| `sales.invoice.create` | ✓ | ✓ | | ✓ | | | |
| `sales.invoice.post` | ✓ | | | ✓ | | | |
| `sales.invoice.void` | ✓ | | | ✓ | | | |
| `sales.receipt.create` | ✓ | | | ✓ | | | |
| `sales.receipt.allocate` | ✓ | | | ✓ | | | |
| `sales.receipt.reverse` | ✓ | | | ✓ | | | |
| `sales.return.approve` | ✓ | ✓ | | | | | |
| `sales.credit_note.post` | ✓ | | | ✓ | | | |
| `sales.refund.create` | ✓ | | | ✓ | | | |
| `sales.pricing.manage` (discounts/coupons/promotions/price_rules) | ✓ | ✓ | | | | | |
| `sales.return.override_window` | ✓ | ✓ | | | | | |
| `sales.report.read` | ✓ | ✓ | ✓ (own scope) | ✓ | | ✓ | ✓ (read-only) |
| `sales.report.export` | ✓ | ✓ | | ✓ | | ✓ | |
| `sales.audit.read` | ✓ | | | | | ✓ | |

**Sensitive-operation approval chains** (never AI-only, per platform mandate): `sales.order.confirm`
above the company's approval threshold, `sales.invoice.void`, `sales.credit_note.post`, and
`sales.refund.create` above a configurable value always require the requesting user to be different
from the approving user (self-approval is structurally blocked at the service layer, not just a UI
convention) when the document exceeds the configured threshold.

**AI Agent** permission scope is intentionally read-mostly: every AI agent listed in AI
Responsibilities operates under a service-account role holding only `*.read` permissions across
Sales plus write access solely to `ai_recommendations` — it can never hold `sales.order.confirm`,
`sales.invoice.post`, `sales.receipt.create`, or any other money-moving write permission, which is
the permission-layer enforcement of the "AI never bypasses authorization" platform rule.

# Notifications

Sales emits notifications (via the foundation `notifications` table and Laravel Reverb for
real-time delivery) for both internal staff and, where configured, the customer directly.

| Event | Recipient | Channel | Notes |
|---|---|---|---|
| `quotation.sent` | Customer | Email/SMS/portal | Includes a link to view/accept the quotation |
| `quotation.expiring_soon` | Sales employee | In-app | 3 days before `valid_until` |
| `approval_requests.created` | Approver | In-app + email | Links directly to the pending quotation/order |
| `sales_order.confirmed` | Customer | Email | Order confirmation with expected delivery date |
| `sales_order.credit_blocked` | Sales manager, Finance | In-app | Fires when confirmation is blocked by credit limit |
| `delivery.shipped` | Customer | SMS/email | Includes tracking link |
| `delivery.failed` | Sales employee | In-app | Prompts rescheduling |
| `invoice.posted` | Customer | Email | Attaches PDF invoice |
| `invoice.overdue` (dunning) | Customer, then Sales employee, then Finance | Email, escalating | Staged: day 1 gentle reminder to customer, day 7 CC's the rep, day 30 escalates to Finance for collections review |
| `payment.received` | Customer | Email/SMS | Payment receipt confirmation |
| `receipt.bounced` | Finance, Sales employee | In-app + email | Urgent — triggers AR follow-up |
| `sales_return.approved` | Customer | Email | Includes return shipping instructions |
| `credit_note.issued` | Customer | Email | Attaches PDF credit note |
| `refund.processed` | Customer | Email/SMS | Confirms refund method and expected timing |
| `ai_recommendations.created` (fraud/duplicate, high confidence) | Sales manager | In-app | Only for `requires-approval` autonomy-level recommendations |
| `customer.dormant` | Assigned sales employee | In-app (digest) | Weekly digest, not per-customer real-time, to avoid noise |

Customer-facing notifications respect `customers.marketing_opt_in` for anything promotional but are
always sent regardless of opt-in status for transactional events (order confirmation, invoice,
shipping, refund) — a distinction the notification service enforces by `notification_type
(transactional | marketing)`, never conflating the two consent categories.

# Security

- **Row-level tenant isolation.** Every query against every table in this module is automatically
  scoped by `company_id` via a Laravel global model scope bound to the authenticated user's active
  company (from the `X-Company-Id` header, cross-checked against `company_users` membership) — a
  query that omits this scope fails a static-analysis CI check before merge.
- **Field-level sensitivity.** `customers.credit_limit`, `customers.tax_exempt`, and
  `sales_orders.credit_override_reason` are visible only to roles holding `sales.credit.override` or
  Finance-tier permissions; a Sales Employee's API responses redact these fields rather than merely
  hiding them in the UI (redaction happens server-side in the Resource/Transformer layer).
- **Idempotency keys** (see API) prevent duplicate financial documents from network retries or
  double-clicks, independent of and in addition to AI-driven fuzzy duplicate detection.
- **Optimistic locking.** Every mutable document carries an implicit `updated_at`-based version
  check on PATCH/POST-transition requests (`If-Unmodified-Since` semantics) — a concurrent edit
  conflict returns `409 Conflict` rather than silently overwriting another user's change.
- **Row-level locking on credit checks.** As described under Customer Integration, the credit-limit
  check locks the customer row (`SELECT ... FOR UPDATE`) for the duration of the order-confirmation
  transaction to prevent concurrent-order race conditions from jointly exceeding the credit limit.
- **PII minimization in AI payloads.** When Sales data is sent to the FastAPI AI layer for
  forecasting/insights/fraud scoring, customer PII (name, phone, email, address) is tokenized to a
  stable pseudonymous identifier before leaving the Laravel boundary wherever the specific
  recommendation does not require the raw value (e.g. a forecast model needs `customer_id` and
  transaction history, not the customer's actual name) — the AI layer re-hydrates display values
  only when rendering a recommendation back through the Laravel API to an authorized user's screen.
- **Signed webhooks.** Outbound webhooks (marketplace commission sync, carrier tracking callbacks
  consumed inbound, ERP integrations) use HMAC-SHA256 request signing with per-endpoint rotating
  secrets; inbound webhook handlers verify the signature before processing and reject unsigned/
  mis-signed payloads with `401`.
- **Payment data.** Card/KNET references stored on `receipts.reference_number` are tokenized
  references from the payment gateway, never raw card numbers (PAN) or CVV — the platform never
  stores primary account numbers, consistent with PCI-DSS SAQ-A scope minimization achieved by
  delegating card capture entirely to the gateway's hosted fields/redirect.
- **Rate limiting.** Public-facing endpoints (e-commerce checkout, coupon validation) are rate-
  limited per IP and per customer account to blunt coupon-brute-forcing and checkout-flooding
  attempts, returning `429` with a `Retry-After` header.

# Audit Logs

Every create/update/delete/state-transition across every table in this module writes an
`audit_logs` row: `company_id`, `table_name`, `record_id`, `action`
(`create | update | delete | state_transition`), `actor_id` (user, or a fixed system/AI actor id for
automated jobs), `actor_type` (`user | system | ai_agent`), `old_values` (JSONB, changed fields
only), `new_values` (JSONB, changed fields only), `reason` (nullable free text — required, not
nullable, for credit overrides, discount-approval overrides, void/cancel actions, and any AI
recommendation acceptance), `ip_address`, `user_agent`, `device_id` (mobile app identifier where
applicable), `created_at`.

**What specifically must always carry a `reason`:** credit-limit override, discount above the
approval threshold, return-window override, invoice void, manual receipt reversal, credit note
issuance for reason `pricing_error`/`goodwill`, and any manager-PIN-gated discount at POS. The
service layer rejects these specific actions with `422` if `reason` is blank — this is enforced in
code, not merely a UI placeholder.

**Immutable, append-only.** `audit_logs` rows are never updated or deleted, including by an Owner
or Auditor role; the table has no `UPDATE`/`DELETE` grants at the database role level for the
application's connection user, only `INSERT`/`SELECT` — an application-layer bug cannot accidentally
tamper with audit history because the database itself refuses the statement.

**Retention & access.** Audit logs are retained for the same statutory period as the financial
documents they describe (see Sales Lifecycle §13, Archive) and are queryable by Owner, Auditor, and
External Auditor roles across the full retention window, including for archived (soft-deleted)
parent documents — an archived invoice's audit trail remains fully inspectable even after the
invoice itself is out of default query scope.

# Performance

- **Read/write split for reporting.** Reports (see Reports) query against a read replica where the
  company's plan/scale warrants it; the OLTP primary handles all Sales writes and the low-latency
  document-detail reads (viewing a single quotation/order/invoice).
- **Materialized aggregates.** `customers.outstanding_ar_balance`, `lifetime_value`, `total_orders`,
  `last_order_date`, and `return_rate` are maintained as materialized/cached columns refreshed
  synchronously on the triggering event (invoice posted, payment received) rather than computed via
  a live aggregate query on every page load — the live-sum derivation described under Sales
  Philosophy remains the source of truth for reconciliation jobs, but the UI/API reads the cache for
  speed, with a nightly job re-deriving and correcting any drift.
- **Indexing strategy.** Every table's `(company_id, status)` and `(company_id, <date column>)`
  combination is indexed (see Database Design) to serve the dominant query pattern — "open documents
  for my company" and "documents in this date range" — as partial indexes excluding soft-deleted
  rows to keep index size proportional to live data.
- **Pagination floor.** No list endpoint permits `per_page` above 100 without an explicit
  `sales.report.export` permission and the async-export path (see API/Reports); this bounds worst-
  case response payload size and prevents accidental full-table pulls from a misconfigured client.
- **Event queue backpressure.** Domain events (`sales_order.confirmed`, `invoice.posted`, etc.) are
  published to a Redis-backed queue (Laravel queues) rather than processed synchronously in the
  request/response cycle wherever the downstream consumer's work is not required for the client's
  immediate response (e.g. `customers.lifetime_value` recalculation) — but the Accounting posting
  service processes `invoice.posted`/`payment.received` synchronously within the same request/
  transaction, since the client (and the platform's reconciliation guarantee) requires the
  `journal_entry_id` to exist before the API responds success.
- **N+1 prevention.** All list/detail endpoints eager-load their standard relations
  (`items`, `customer`, `sales_employee`) via Laravel's `with()` at the Repository layer by default;
  a CI-enforced query-count assertion on the test suite fails the build if any endpoint's query count
  scales with result-set size.
- **Nightly reconciliation job.** As described under Accounting Integration, a scheduled job
  verifies posted-journal-line totals against Sales document totals per company/period and alerts
  Finance on any drift above a small floating-point tolerance (a few minor currency units,
  attributable to legitimate rounding, not a real discrepancy).
- **Bulk operation batching.** Bulk endpoints (see API) process items in batches of 50 within
  separate sub-transactions to avoid a single oversized database transaction holding locks (e.g. the
  customer row-lock during credit checks) for an extended period across hundreds of orders.

# Edge Cases

- **Partial delivery across multiple invoices.** A wholesale order for 1,000 units ships in three
  tranches of 400/400/200 as stock arrives; each delivery independently triggers its own invoice
  (for `on_delivery` billing), so the order ends up with three invoices, each individually payable,
  and `sales_order_items.quantity_invoiced` accumulates correctly to 1,000 only after the third.
- **Credit note larger than any single invoice's remaining balance.** A customer overpaid or a
  pricing error credit exceeds the invoice's own `total_amount` net of a prior partial payment — the
  credit note can still post for its full computed amount, but `credit_note_applications` can only
  reduce that specific invoice's `balance_due` to zero; the excess remains as `remaining_amount` on
  the credit note itself, available for application to other invoices or refund, never forced to
  create a negative `balance_due`.
- **Return of a partially-paid invoice.** The credit note reduces the invoice's effective
  `total_amount` (via the reversing entry), and `amount_paid`/`balance_due` recompute against the new
  effective total; if `amount_paid` now exceeds the reduced total, the excess becomes an automatic
  customer credit (a system-generated small credit-note-adjacent ledger entry) rather than a negative
  `balance_due` — negative balances are never displayed to a customer as "owing negative money."
- **Currency rate unavailable at document creation** (e.g. a newly-added currency with no rate feed
  yet). The document creation is blocked with a clear `422` (`EXCHANGE_RATE_UNAVAILABLE`) rather than
  silently defaulting to `1.0`, which would corrupt every downstream base-currency figure.
- **Product deleted (soft-deleted) after being referenced on an open quotation/order.** Soft-delete
  on `products` does not cascade-hide it from already-created `sales_order_items`; the line continues
  to render using its snapshotted `description`/`unit_price`, but the product can no longer be added
  to new quotations — this is why line-level `description` is stored as a snapshot rather than
  always joined live from `products.name`.
- **Discount stacking conflict.** A coupon with `stackable_with_promotions = false` is applied after
  an active exclusive promotion already matched the cart — the coupon application is rejected with a
  clear `409` explaining which promotion is blocking it, rather than silently ignoring the coupon or
  silently overriding the promotion.
- **Order confirmed, then the customer's credit limit is lowered by Finance before invoicing.** The
  already-confirmed order and its reservation are not retroactively unwound (the commitment was valid
  when made); however, any *subsequent* new order or amendment for that customer is evaluated against
  the new, lower limit immediately.
- **Multi-warehouse split order where one warehouse's shipment is lost/damaged in transit.** The
  affected `delivery_id`'s status moves to `failed`; the corresponding `sales_order_items` quantity
  for that split remains un-shipped and un-invoiced, and a replacement delivery is created referencing
  the same order — the customer is invoiced once, correctly, only for goods that actually arrived,
  regardless of how many delivery attempts it took.
- **Subscription price change mid-cycle.** Changing a `products.base_price` for a subscription
  product does not alter already-generated invoices; the next `recurring` invoice generation picks up
  the new price only if the specific `sales_orders.recurrence_rule` was also explicitly updated
  (a price list change alone does not silently reprice an active subscription — that requires an
  explicit subscription amendment, notified to the customer per the company's terms).
- **Marketplace webhook arrives for an order that was already manually entered** (a marketplace sync
  race or a rep who didn't realize the order syncs automatically). The `external_order_id` uniqueness
  constraint at the company level rejects the duplicate webhook-driven creation and instead links the
  existing manually-entered order for reconciliation, surfaced to the sales manager as a
  "possible manual/sync duplicate" review item rather than creating two orders for one sale.
- **Rounding drift across many small-quantity lines with tax-inclusive pricing.** As described under
  Taxes, any cent/fils-level rounding remainder is captured in a single `rounding_adjustment_amount`
  on the document header rather than distributed across lines, so the header total always foots
  exactly to the displayed line total sum plus this one explicit adjustment line.
- **A return requested for an invoice that itself was later fully reversed by an earlier credit
  note.** The system blocks a second return/credit-note cycle against an invoice whose
  `remaining_amount`-equivalent balance is already fully reversed (`invoices.total_amount` net of
  existing credit notes is zero), returning a clear `409` rather than allowing a double-credit.

# Future Improvements

- **Real-time available-to-promise across a partner network.** Extend ATP calculation (currently
  scoped to the company's own warehouses) to include drop-ship/consignment stock at approved
  partner/vendor locations, letting a quotation promise a delivery date backed by a vendor's stock
  without first receiving it into the company's own `inventory_items`.
- **Dynamic, AI-assisted price optimization at the promotion level.** Today Promotions are static,
  human-authored campaigns; a future iteration lets the Forecast Agent propose promotion *parameters*
  (discount depth, duration, eligible SKUs) to hit a target revenue or inventory-clearance goal,
  still surfaced as a suggestion a marketing/sales manager approves before the promotion activates.
- **Native support for consignment and vendor-managed inventory sales**, where title transfers to
  the customer only at point of sale rather than at delivery — requiring a new revenue-recognition
  timing rule distinct from the current delivery/order-confirmation/milestone/recurring set.
- **Customer self-service negotiation portal**, letting a B2B customer counter-propose quantities/
  prices on a sent quotation directly (today, a counter-proposal is handled informally and re-entered
  by the rep as a new quotation version) — with the counter-proposal itself becoming a structured,
  auditable input rather than a phone call or email thread.
- **Embedded financing / Buy-Now-Pay-Later at checkout** for B2C/E-Commerce channels, integrating a
  Gulf BNPL provider as a new `payment_method` value with its own settlement and risk-scoring flow,
  reconciled through the same `receipts`/`receipt_allocations` mechanism.
- **Predictive stock allocation for high-demand launches**, where the Forecast Agent pre-stages
  `stock_reservations` recommendations ahead of a known promotional launch based on historical
  launch-day demand curves, still requiring human/Inventory-Manager confirmation before any real
  reservation is created.
- **Cross-company intercompany sales** for group structures (a Kuwait entity selling to its own
  Saudi subsidiary), requiring a new intercompany elimination layer in Accounting Integration beyond
  this module's current single-company scope — noted here as explicitly out of scope for v1.
- **Voice- and chat-driven quotation building** ("Pip, quote Ahmad 50 units of SKU-205 at the
  wholesale tier") as a thin conversational front-end over the existing Quotations API, with every
  AI-drafted line still passing through the same pricing/discount/approval pipeline as a
  manually-built quotation — no new business logic, only a new input surface.

# End of Document
