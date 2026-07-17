# Order-to-Cash (O2C) — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: ORDER_TO_CASH
---

# Purpose

Order-to-Cash is the revenue engine of any trading company, and in QAYD it is the flagship
demonstration of what "the AI is not a chatbot, it is an autonomous finance workforce" means in
practice. A traditional system waits: a rep types a quotation, a clerk types an invoice, a
bookkeeper types a receipt, and the ledger is only ever as current as the last person who remembered
to open the screen. QAYD's O2C workflow inverts that. From the moment a quotation is drafted, a
standing team of six specialized agents — **Sales Agent**, **General Accountant**, **Inventory
Manager**, **Forecast Agent**, **Fraud Detection**, and **Approval Assistant** — is already reading
the emerging document, computing a price suggestion before the rep asks for one, checking stock
availability before the order is confirmed, scoring fraud risk before the warehouse picks a single
unit, and drafting the invoice before Finance opens the module. None of that work becomes a fact
until a human with the correct permission accepts it. The AI proposes; Laravel validates, authorizes,
and records; the ledger — `journal_entries` and `journal_lines` — remains the single, immutable
source of truth.

This document specifies exactly one thing: how those six agents orchestrate, gate, and hand off the
O2C lifecycle — quotation → sales order → credit-limit check → stock reservation → delivery (stock
out, COGS) → invoice (AR / Revenue / Output VAT) → receipt (Bank or Cash debit, AR credit) → optional
credit note — end to end, with every confidence score, every escalation, every journal entry, every
event, and every failure path made explicit. It does not restate the business rules that already
govern each underlying document; those are owned by `docs/accounting/SALES.md`, `docs/accounting/
JOURNAL_ENTRIES.md`, `docs/accounting/TAX.md`, and `docs/accounting/INVENTORY.md`. This document is
the seam between those modules and the AI layer that watches, drafts, and never bypasses them.

# Trigger & Preconditions

O2C has exactly two valid entry points, and the AI layer initiates neither of them — a human, a POS
terminal, an e-commerce checkout, or a marketplace webhook always starts the cycle:

1. **Quotation-led.** A `sales_quotations` row is created (`status = 'draft'`), built out with
   `sales_quotation_items`, sent (`sales.quotation.send`), and accepted (`sales.quotation.accept`),
   which creates the `sales_orders` row with `quotation_id` populated. This is the dominant path for
   wholesale/B2B channels where price and terms are negotiated before commitment.
2. **Direct order.** A `sales_orders` row is created with `quotation_id IS NULL` — the dominant path
   for `pos` and `ecommerce` channels, where the customer commits at the point of sale and no separate
   negotiation step exists.

Both paths converge on the same `sales_orders` row shape and the same confirmation gate described
under Orchestration. The workflow will not proceed past intake unless every precondition below holds;
a failed precondition blocks document creation with a `422` validation error and never reaches an AI
agent at all — the AI layer only ever reasons over documents that already satisfied deterministic
validation.

| Precondition | Enforced by | Failure behavior |
|---|---|---|
| `company_id` resolves to an active, non-suspended tenant | Laravel global scope + company-status middleware | `403`, request never reaches Sales controller |
| `customer_id` resolves and `customers.lifecycle_state <> 'blacklisted'` | FormRequest validation | `422`; blacklisted customers cannot receive a new quotation/order at all, regardless of any AI recommendation |
| At least one line with a resolvable `product_id`, a resolvable price (`price_list_item_id` or `base_unit_price`), and a resolvable `tax_code_id` (see Tax resolution priority, `docs/accounting/SALES.md` → Taxes) | Service layer | `422` with the specific line flagged |
| `warehouse_id` resolvable for every stock-tracked line | Service layer, delegating to Inventory | `422`; non-stock (service) lines are exempt |
| Acting user holds `sales.quotation.create` (quotation path) or `sales.order.create` (direct path) | RBAC middleware | `403` |
| `Idempotency-Key` header present on the creating `POST` | API gateway | A retried request with the same key inside 24 hours returns the original `201`/`200` instead of creating a duplicate document — the deterministic half of Duplicate Detection (see Confidence & Escalation Rules) |

**What the AI layer is allowed to have already done by this point.** Nothing that writes. The Sales
Agent may already be mid-conversation with the rep (an accepted `price_suggestion` recommendation,
an ATP heads-up from the Inventory Manager) before any of the rows above exist, because quote-building
is iterative; none of that reasoning commits until the deterministic preconditions above pass and a
human action (`sales.quotation.send`, `sales.order.create`) actually creates the row.

# Participating Agents

| Agent | Roster role | Responsibility inside O2C | Autonomy in this workflow | Writes |
|---|---|---|---|---|
| **Sales Agent** | Order-to-Cash orchestrating agent | Classifies every O2C event, requests price/ATP/fraud/forecast input from the other five agents, assembles quotation price suggestions, upsell candidates, invoice-draft proposals, and collections nudges | Suggest-only, end to end | `ai_recommendations`, `ai_conversations`, `ai_messages` |
| **General Accountant** | Accounting posting & validation agent | Runs an account-mapping sanity check on every invoice-draft and credit-note-draft before a human posts it (correct revenue/COGS/tax account per product category and tax code); drafts a reclassification entry if a posting anomaly is later found | Suggest-only (mapping check is automatic; any resulting journal proposal is a draft) | `ai_recommendations` (mapping-check result attached to the Sales Agent's `invoice_draft` payload) |
| **Inventory Manager** | Stock agent | Computes Available-to-Promise for the stock-availability gate, publishes the authoritative `cogs_unit_cost` (FIFO / weighted-average / standard, per the company's configured costing method) that Sales snapshots onto `invoice_items.cogs_unit_cost`, flags backorders | Automatic computation (read); never itself creates `stock_reservations` or `stock_movements` — those remain deterministic Inventory-module writes triggered by Sales events | `ai_recommendations` (ATP/backorder flags only) |
| **Forecast Agent** | Demand & cash forecasting agent | Supplies pipeline-weighted revenue forecast (quotation acceptance-rate history) and per-customer payment-date prediction, cited by Approval Assistant inside every credit-limit escalation so the approving human sees "how likely is this customer to pay soon" alongside "how far over the limit is this order" | Suggest-only, always range-bounded (P10/P50/P90) | `ai_recommendations` |
| **Fraud Detection** | Risk-scoring agent | Computes a 0–100 fraud/duplicate-risk score synchronously at every order-confirmation attempt; the one agent in this workflow with a structural, non-bypassable effect — a high score forces the order into `pending_approval` regardless of value | Requires-approval (step-up only; never rejects a transaction outright) | `ai_recommendations`; is also the **only** agent besides Approval Assistant permitted to write to `ai_approval_requests`, and only to set `status = 'held'` |
| **Approval Assistant** | Approval-routing agent | The sole writer of `ai_approval_requests`/`ai_approval_steps` for every O2C proposal that is sensitive or threshold-crossing (credit-limit breach, fraud step-up, an unusually large credit note); resolves the correct approver chain from the company's `roles`/`permissions` configuration, tracks the SLA clock, and escalates automatically on breach | Routing only — it never approves, rejects, or decides anything itself | `ai_approval_requests`, `ai_approval_steps` |

Every agent above shares the same constraint stated once, platform-wide, and never contradicted here:
none of the six holds `sales.order.confirm`, `sales.invoice.create`, `sales.invoice.post`,
`sales.receipt.create`, `sales.credit_note.post`, or `sales.refund.create`. The AI Agent
service-account role that all six run under is read-mostly across `sales.*`, `customers.read`, and
`inventory.read`, with write access restricted to the AI-owned tables named in the rightmost column
above plus `ai_tasks` and `ai_decisions` (schemas defined in `docs/ai/agents/SALES_AGENT.md` and
reused verbatim here — this document introduces no new AI table, only new `task_type` and
`decision_point` vocabulary values, per the extension mechanism that document already establishes).

# Orchestration (step-by-step, ASCII flow, with human-approval gates)

```
 QUOTATION                                          Sales Agent (parallel, suggest-only):
 ┌─────────────────────┐  sales.quotation.send      price_suggestion · ATP heads-up
 │ sales_quotations      │─────────────┐             upsell/cross-sell candidates
 │ draft → sent           │            │
 └─────────────────────┘             ▼
                              sales.quotation.accept (human)
                                       │
                                       ▼
 SALES ORDER                  ┌─────────────────────┐
 (or direct order,             │ sales_orders (draft)  │
  quotation_id = NULL)         └─────────────────────┘
                                       │  sales.order.confirm
                                       ▼
                    ┌──────────────────────────────────────┐
                    │   ORDER CONFIRMATION GATE (1 DB txn)    │
                    │   1. pricing freeze (already resolved)  │
                    │   2. stock availability — ATP            │
                    │      (Inventory Manager, read-only)       │
                    │   3. CREDIT-LIMIT CHECK (deterministic,     │
                    │      row-locked — see below)                 │
                    └──────────────────────────────────────┘
                          │ pass                    │ fail (>100% of limit)
                          ▼                          ▼
                 ┌───────────────────┐    ai_approval_requests
                 │ FRAUD GATE          │    subject_type='credit_hold'
                 │ Fraud Detection      │    Approval Assistant packages,
                 │ score ≥ 70 → hold    │◄───citing Forecast Agent + CFO Agent
                 └───────────────────┘    human: sales.credit.override
                          │ pass (or human-approved override on either gate)
                          ▼
                 sales_order.confirmed ──────► Inventory Manager: stock_reservations
                          │                     created per line (Inventory module writes)
                          ▼
 FULFILLMENT      pick → pack → ship ──────► delivery.shipped: COGS Entry B posts
 (Sales+Warehouse)        │                    (Dr COGS / Cr Inventory)
                          ▼
                     delivery.delivered
                          │
                          ▼
 INVOICE DRAFT     Sales Agent assembles invoice_draft (ai_recommendations)
                    General Accountant: account-mapping sanity check
                          │  sales.ai.approve_draft + sales.invoice.create (human)
                          ▼
                 invoices (draft) ── sales.invoice.post (human) ──► Entry A posts
                                     (Dr AR · Cr Revenue · Cr Output VAT)
                          │
                          ▼
 RECEIPT           receipts + receipt_allocations ──► Dr Bank/Cash · Cr AR
                          │  balance_due → 0
                          ▼
                     invoice.paid
                          │
                          ▼ (optional)
 CREDIT NOTE        sales_returns → credit_notes ──► Dr Revenue · Dr Output VAT · Cr AR
                    (+ Dr Inventory · Cr COGS if disposition = restock)
                    large credit notes ≥ threshold route through
                    ai_approval_requests subject_type='credit_note' first
```

**Step-by-step, with the concrete endpoint and permission each step calls:**

| # | Step | Endpoint | Permission | AI involvement |
|---|---|---|---|---|
| 1 | Build & send quotation | `POST /api/v1/sales/quotations`, `POST .../{id}/send` | `sales.quotation.create`, `sales.quotation.send` | Sales Agent: `GET .../ai/quotations/{id}/price-suggestion`, `GET .../ai/quotations/{id}/upsell-candidates` |
| 2 | Accept → create order | `POST .../quotations/{id}/accept` or `POST /api/v1/sales/orders` | `sales.quotation.accept` / `sales.order.create` | none (deterministic) |
| 3 | Confirm — pricing + ATP + credit gates | `POST /api/v1/sales/orders/{id}/confirm` | `sales.order.confirm` | Inventory Manager ATP read; Approval Assistant if credit gate fails |
| 4 | Fraud gate | evaluated inline within step 3's transaction | — | Fraud Detection score; Approval Assistant if ≥ 70 |
| 5 | Reservation | Inventory-module internal, triggered by `sales_order.confirmed` | `inventory.reserve` (Inventory module role) | Inventory Manager published the ATP the reservation consumes |
| 6 | Pick / pack / ship | `POST .../deliveries/{id}/pick|pack|ship` | `sales.delivery.pick`/`pack`/`ship` | none (deterministic); COGS posts at `ship` |
| 7 | Deliver | `POST .../deliveries/{id}/deliver` | `sales.delivery.deliver` | Sales Agent classifies `delivery.delivered` as an `invoice_draft` opportunity |
| 8 | Create invoice (draft) | `POST /api/v1/sales/invoices` | `sales.invoice.create` **+** `sales.ai.approve_draft` if materializing an `ai_recommendations` row | General Accountant mapping-sanity check feeds the recommendation's confidence |
| 9 | Post invoice | `POST .../invoices/{id}/post` | `sales.invoice.post` | none (deterministic posting service) |
| 10 | Capture & allocate receipt | `POST /api/v1/sales/receipts`, `POST .../{id}/allocate` | `sales.receipt.create`, `sales.receipt.allocate` | Forecast Agent's payment-date prediction informs any pre-due-date collections messaging, not the posting itself |
| 11 | Return → credit note (optional) | `POST /api/v1/sales/returns/{id}/approve`, `POST /api/v1/sales/credit-notes`, `POST .../{id}/post` | `sales.return.approve`, `sales.credit_note.create`, `sales.credit_note.post` | Approval Assistant gates large credit notes (`subject_type='credit_note'`) |
| 12 | Refund (optional) | `POST /api/v1/sales/refunds` | `sales.refund.create` | none |

**The credit-limit check, in full.** At step 3, inside the same database transaction that evaluates
and (if it passes) sets `sales_orders.status = 'confirmed'`, the service layer takes a row-level lock
on the customer (`SELECT ... FOR UPDATE` on `customers`) and reads the live `customer_balances.
balance_net_base` — never a possibly-stale cache — because two orders confirming concurrently against
the same customer must never both pass against a balance that neither of their commits has
incorporated yet. The check has two tiers:

- **≤ 90% of `credit_limit`** (this order's `base_currency_total_amount` added to the current
  `balance_net_base`): passes silently. No AI agent surfaces anything; a warning nobody needs is not
  manufactured.
- **> 90% and ≤ 100%**: passes, but the quote-builder/order UI shows a non-blocking advisory badge.
- **> 100%**: `sales_orders.credit_check_status` is set to `'failed'`, confirmation is blocked, and
  `sales_order.credit_blocked` fires — see Confidence & Escalation Rules for exactly what happens
  next. If a human subsequently grants an override, `credit_check_status` becomes `'overridden'` and
  a `customer_credit_overrides` row (`document_type = 'sales_order'`) is written; confirmation is
  re-attempted and, absent any other blocking gate, succeeds.

The fraud gate runs independently in the same confirmation attempt; either gate alone is sufficient to
route the order to `pending_approval`, and both can be open simultaneously (a credit-blocked order can
also be fraud-flagged) — Approval Assistant opens one `ai_approval_requests` row per distinct
`subject_type`, never conflating a credit hold and a fraud hold into a single ambiguous approval.

# Data & Tables Touched

O2C is an orchestration seam, not a table owner. Every business table below is defined and governed by
another module's document; this workflow only reads, inserts, or updates them through the endpoints
already named under Orchestration, and the table exists here purely so an implementer can see, in one
place, everything a single order-to-cash cycle touches without opening eleven other files.

**Business tables (owned elsewhere — referenced, never redefined):**

| Table | Owning document | Touched how | Role in O2C |
|---|---|---|---|
| `customers` | `docs/accounting/CUSTOMERS.md` | Read, row-locked | `credit_limit`; row-level `SELECT ... FOR UPDATE` at confirmation |
| `customer_balances` | `docs/accounting/CUSTOMERS.md` | Read | Live `balance_net_base` — the credit-check's other operand |
| `customer_credit_overrides` | `docs/accounting/CUSTOMERS.md` | Insert | One row per approved override, `document_type='sales_order'` |
| `customer_collection_cases` | `docs/accounting/CUSTOMERS.md` | Read | Context for a post-receipt aging/dunning nudge, out of this cycle's happy path |
| `price_lists`, `price_list_items` | `docs/accounting/SALES.md` | Read | Resolves each line's price at quotation/order time |
| `sales_quotations`, `sales_quotation_items` | `docs/accounting/SALES.md` | Insert, Update | Quotation-led entry path only |
| `sales_orders`, `sales_order_items` | `docs/accounting/SALES.md` | Insert, Update | Both entry paths converge here; carries `credit_check_status` |
| `stock_reservations` | `docs/accounting/INVENTORY.md` | Insert, Delete | Created on `sales_order.confirmed`; released on cancellation or a failed re-check |
| `inventory_valuations` | `docs/accounting/INVENTORY.md` | Read | Source of `cogs_unit_cost` / `unit_cost_at_shipment` |
| `deliveries`, `delivery_items` | `docs/accounting/SALES.md` | Insert, Update | Pick → pack → ship → deliver lifecycle |
| `stock_movements` | `docs/accounting/INVENTORY.md` | Insert | Issue movement at ship; the event that feeds Entry B |
| `tax_codes`, `tax_rates` | `docs/accounting/TAX.md` | Read | Line-level VAT resolution, effective-dated |
| `invoices`, `invoice_items` | `docs/accounting/SALES.md` | Insert, Update | `draft → posted`; `balance_due` is a generated column |
| `receipts`, `receipt_allocations` | `docs/accounting/SALES.md` | Insert | Cash application against one or more open invoices |
| `sales_returns`, `sales_return_items` | `docs/accounting/SALES.md` | Insert, Update | Optional return path preceding a credit note |
| `credit_notes`, `credit_note_items` | `docs/accounting/SALES.md` | Insert, Update | Reverses Entry A; mirrors Entry B if `disposition='restock'` |
| `refunds` | `docs/accounting/SALES.md` | Insert | Optional cash settlement of a credit note's remaining balance |
| `journal_entries`, `journal_lines` | `docs/accounting/JOURNAL_ENTRIES.md` | Insert (Posting Engine only) | Never written directly by Sales or by any agent |
| `audit_logs` | Platform foundation | Insert (automatic) | Every state transition, every override, every AI-decision write |
| `notifications` | Platform foundation | Insert | `quotation.sent`, `sales_order.confirmed`, `invoice.posted`, `approval_requests.created`, … |
| `attachments` | Platform foundation | Read, Insert | Delivery notes, invoice/credit-note PDFs (polymorphic `attachable_type`/`attachable_id`) |

**AI-layer tables (schemas reused verbatim from the documents that own them — this workflow contributes
new vocabulary values, never a new table or a new migration):**

- **`ai_recommendations`** — owned by `docs/accounting/SALES.md`'s Database Design, already reused by
  `docs/ai/agents/SALES_AGENT.md`. O2C's `recommendation_type` values were already enumerated there
  (`price_suggestion`, `credit_precheck_warning`, `stock_precheck_warning`, `fraud_score`,
  `invoice_draft`, `receipt_draft`, `receipt_allocation_suggestion`, `collections_nudge`); this document
  adds no new value, only the worked instances under Worked Example.
- **`ai_tasks` / `ai_decisions`** — DDL defined in `docs/ai/agents/SALES_AGENT.md` § Data Access & Tenant
  Scope, reused verbatim:

  ```sql
  -- excerpt — full DDL in docs/ai/agents/SALES_AGENT.md
  CREATE TABLE ai_tasks (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    ai_agent_id BIGINT NOT NULL REFERENCES ai_agents(id),
    task_type VARCHAR(60) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    input_payload JSONB NOT NULL DEFAULT '{}',
    result_summary JSONB, error TEXT, retry_count SMALLINT NOT NULL DEFAULT 0,
    scheduled_for TIMESTAMPTZ NOT NULL, started_at TIMESTAMPTZ, completed_at TIMESTAMPTZ
  );
  CREATE TABLE ai_decisions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    ai_agent_id BIGINT NOT NULL REFERENCES ai_agents(id),
    decision_point VARCHAR(60) NOT NULL,
    gate_evaluated VARCHAR(20) NOT NULL,      -- auto | suggest_only | requires_approval
    triggered BOOLEAN NOT NULL,
    confidence NUMERIC(4,3), recommendation_id BIGINT REFERENCES ai_recommendations(id),
    outcome VARCHAR(20) NOT NULL DEFAULT 'pending'
  );
  ```

  This document's new `task_type` values: `credit_check_evaluate`, `fraud_score_order`,
  `invoice_draft_generate`, `receipt_allocation_propose`. New `decision_point` values:
  `credit_limit_check`, `receipt_allocation_propose`, `credit_note_approval_route` (`fraud_step_up` and
  `invoice_draft_propose` already exist from `SALES_AGENT.md` and are reused unchanged).
- **`ai_approval_requests` / `ai_approval_steps`** — DDL and the `ai_approval_subject_type` enum
  (`'bank_transfer' | 'payroll_release' | 'tax_submission' | 'journal_void' | 'permission_change' |
  'purchase_order' | 'credit_note' | 'credit_hold' | 'vendor_bank_update'`) are defined in
  `docs/ai/AI_COMMAND_CENTER.md` § Approval Center, reused verbatim. O2C uses exactly two of the existing
  values — `credit_hold` (credit-limit breach, and any fraud-only step-up — see Confidence & Escalation
  Rules for why the two share one value) and `credit_note` (a return-driven credit note above the
  company's configured approval threshold). This document introduces no new enum member.

# Journal Entries Produced

Sales never writes to `journal_entries`/`journal_lines` directly (per the platform-wide posting rule
restated in `docs/accounting/SALES.md` § Accounting Integration); every entry below is generated by the
Accounting Engine's Posting Engine from a Sales-emitted event, and none of it is redefined here — it is
restated at the level of detail this workflow's own Orchestration and Worked Example sections need,
citing `docs/accounting/SALES.md` § Accounting Integration and `docs/accounting/JOURNAL_ENTRIES.md` §
Automatic Entries as the owning source for every account name and posting rule.

**Summary — which event produces which entry:**

| Entry | Trigger event | Debit | Credit |
|---|---|---|---|
| **A** — Revenue recognition | `invoice.posted` | Accounts Receivable — Trade (`total_amount`) | Sales Revenue (`subtotal − discount`); Output Tax Payable (`tax_amount`) |
| **B** — COGS relief | `delivery.shipped` (or `invoice.posted` under `on_order_confirmation` billing policy — see item 3 below) | Cost of Goods Sold (`Σ qty × cogs_unit_cost`) | Inventory — Finished Goods |
| **C** — Cash application | `payment.received` | Cash / Bank (per payment-method mapping) | Accounts Receivable — Trade (`Σ receipt_allocations.amount_allocated`); Customer Advances (unallocated portion, if any) |
| **D** — Credit note (reversal) | `credit_note.issued` | Sales Revenue or Sales Returns & Allowances; Output Tax Payable | Accounts Receivable — Trade |
| **D′** — Restock mirror (optional) | `credit_note.issued`, `disposition='restock'` | Inventory — Finished Goods | Cost of Goods Sold |
| **E** — Refund settlement (optional) | `refund.processed` | Customer Advances / Credit Note Liability | Cash / Bank |

**1. Entry A, in full.** Posted the instant `invoice.posted` fires, tagged `source_document_type =
'invoices'`, `source_document_id`:

| Account | Debit | Credit |
|---|---|---|
| Accounts Receivable — Trade (control account, tagged `customer_id`) | `total_amount` | |
| Sales Revenue (per product-category revenue mapping) | | `subtotal_amount − discount_amount` |
| Output Tax Payable (per tax code) | | `tax_amount` |

**2. Entry B, and exactly where it posts.** For a `billing_policy = 'on_delivery'` account, Entry B posts
at `delivery.shipped`, valued at `unit_cost_at_shipment` read live from `inventory_valuations`; the same
value is snapshotted onto `invoice_items.cogs_unit_cost` when the invoice later posts, and the Posting
Engine explicitly skips re-posting COGS for any `delivery_item_id` that already carries one — Entry B
never double-counts. For a `billing_policy = 'on_order_confirmation'` account (the common case for an
established, trusted wholesale relationship, where invoicing does not wait on physical delivery), Entry B
posts *at the same moment as Entry A*, as a second `journal_entries` row sharing the same
`source_document_id`, because no separate delivery-time COGS entry exists for that policy.

**3. Entry C, in full**, including the optional FX line when the receipt's currency/rate differs from the
invoice it settles:

| Account | Debit | Credit |
|---|---|---|
| Cash / Bank (per payment-method mapping) | `amount` (base currency) | |
| Accounts Receivable — Trade | | `Σ receipt_allocations.amount_allocated` (base currency) |
| Customer Advances (liability, unallocated portion) | | `unallocated_amount` |
| Foreign Exchange Loss (unfavorable) *or* Foreign Exchange Gain (favorable, credit side) | `abs(fx_difference)` | `abs(fx_difference)` |

**4. Entry D, in full**, the mirror image of Entry A, plus the optional restocking-fee line and the
optional D′ mirror of Entry B:

| Account | Debit | Credit |
|---|---|---|
| Sales Revenue (or Sales Returns & Allowances, company-configurable) | `subtotal_amount − restocking_fee_retained` | |
| Output Tax Payable | `tax_amount` | |
| Accounts Receivable — Trade | | `total_amount` |
| Restocking Fee Income (only if a fee is retained; posted as its own line, never netted silently) | | `restocking_fee_amount` |

If `disposition = 'restock'`, D′ posts alongside D: Dr Inventory — Finished Goods, Cr Cost of Goods
Sold, valued at the *original* invoice line's `cogs_unit_cost` for the quantity returned — never a
current, possibly different, cost.

**Reconciliation guarantee, inherited unchanged.** Because every entry above originates exclusively from
the Posting Engine reading a Sales-emitted event — never hand-entered, never editable from within Sales —
the sum of posted `journal_lines` tagged `source_document_type IN ('invoices','receipts','credit_notes',
'refunds','deliveries')` for a company/period reconciles to the cent against the sum of
`invoices.total_amount`, `receipts.amount`, `credit_notes.total_amount`, and `refunds.amount` for that
period; a nightly job verifies this identity and alerts Finance on any drift (`docs/accounting/SALES.md`
§ Accounting Integration). No agent in this workflow is a plausible source of drift, because none of the
six holds a permission that can post, edit, or delete a `journal_entries` row.

# Events Emitted/Consumed

Every event below follows the platform's Canonical Event Envelope (`docs/database/DATABASE_EVENTS.md` §
Canonical Event Envelope) — `event_id`, `event_name`, `event_version`, `company_id`, `branch_id`,
`aggregate_type`/`aggregate_id`, `occurred_at`, `produced_by`, `causation_id`, `correlation_id`,
`actor`, `payload`, `metadata` — never a bespoke shape per module.

**Emitted, in typical cycle order:**

| Event | Producer | Primary consumer(s) | Notes |
|---|---|---|---|
| `sales_quotation.sent` | Sales (quotation path only) | Customer portal/email, Sales Agent | Starts the quotation clock (`expires_at`) |
| `sales_quotation.expiring_soon` | Sales (scheduled sweep) | Sales Agent | Feeds a renewal nudge, not covered further here |
| `sales_order.confirmed` | Sales | Inventory (creates `stock_reservations`), Customers (`customer_status`, `first_order_date`), Notifications | The pivot event — both gates have already passed by the time this fires |
| `sales_order.credit_blocked` | Sales | Approval Assistant, Sales Agent, Notifications | Fires instead of `confirmed` when the credit gate fails |
| `delivery.shipped` | Sales/Warehouse | Accounting (Entry B, `on_delivery` policy only), Inventory (`stock_movements`) | Independent of invoice timing |
| `delivery.delivered` | Sales/Warehouse | Sales Agent (`invoice_draft` opportunity for `on_delivery` policy), Customer notification | |
| `invoice.posted` | Accounting (Posting Engine) | Customer (PDF email), Reporting Agent, Forecast Agent (actuals) | Triggers Entry A (+ Entry B under `on_order_confirmation`) |
| `invoice.overdue` | Sales (scheduled sweep, `due_date` passed with `balance_due > 0`) | Sales Agent (collections-nudge sweep), Notifications | |
| `payment.received` | Sales/Banking | Accounting (Entry C), Customers (`outstanding_ar_balance` recompute), Fraud Detection (re-score on the settling pattern) | Causes `invoice.paid` once allocation zeroes `balance_due` — the same causal pair `docs/database/DATABASE_EVENTS.md` uses to illustrate `causation_id` |
| `invoice.paid` | Sales (derived, same transaction as the triggering `payment.received`) | Customers, Reporting Agent, Forecast Agent | `causation_id` = the `payment.received` event that produced it |
| `receipt.bounced` | Banking (reversed bank transaction) | Sales Agent, Accounting (reversing entry), Fraud Detection | See Edge Cases |
| `credit_note.issued` | Accounting (Posting Engine) | Customer (PDF email), Inventory (`disposition='restock'`) | Triggers Entry D (+D′) |
| `refund.processed` | Sales/Banking | Accounting (Entry E) | Optional, cash-only settlement |

**Consumed from other modules:**

| Event | Producer module | What O2C does with it |
|---|---|---|
| `customer.dormant` | Customers/Reporting (weekly batch) | Sales Agent surfaces it in a digest; no gate in this workflow reacts automatically |
| `lead.created` | Marketing/CRM intake | Sales Agent's quotation-drafting Intake route (quotation-led path, pre-`sales_quotations` row) |
| Tax rate effective-dating | Tax (`tax_rates`, effective-dated rows, never an in-place update — `docs/accounting/TAX.md` § Rate Management) | Every quotation/order/invoice line resolves its tax rate as of its own document date, not as of posting time, so a rate change mid-cycle never silently reprices an already-quoted line (see Edge Cases) |
| Stock-on-hand changes | Inventory (`stock_movements`, `stock_adjustments`) | Read directly (not subscribed as an event) by Inventory Manager's ATP computation at quote-drafting and pre-confirmation time |

**A fully worked envelope — `invoice.posted` for invoice 71204** (the same invoice this document's
Worked Example walks through end to end):

```json
{
  "event_id": "018f7a2e-4b10-7000-9c3d-1a2b3c4d5e6f",
  "event_name": "invoice.posted",
  "event_version": 1,
  "company_id": 4821,
  "branch_id": 3,
  "aggregate_type": "invoices",
  "aggregate_id": 71204,
  "occurred_at": "2026-07-16T10:02:11.000Z",
  "produced_by": "sales-service",
  "causation_id": "018f7a2e-1120-7000-8b1e-3a2c5e6f7a01",
  "correlation_id": "018f7a2e-1120-7000-8b1e-3a2c5e6f7a00",
  "actor": { "type": "user", "user_id": 118 },
  "payload": {
    "invoice_id": 71204,
    "invoice_number": "INV-KWC-2026-14502",
    "sales_order_id": 9931,
    "customer_id": 1042,
    "currency_code": "KWD",
    "subtotal_amount": "1748.0000",
    "discount_amount": "92.0000",
    "tax_amount": "87.4000",
    "total_amount": "1835.4000",
    "balance_due": "1835.4000",
    "journal_entry_id": 553012,
    "status": "posted"
  },
  "metadata": { "source": "api", "request_id": "1c2d3e4f-5a6b-7c8d-9e0f-1a2b3c4d5e6f" }
}
```

# Confidence & Escalation Rules

**The credit-limit check is deterministic, never a confidence score.** As already fixed under
Orchestration, `> 100%` of `credit_limit` blocks unconditionally — no AI agent's confidence, however
high, ever waives it, and no company policy can configure it to auto-pass. What Confidence & Escalation
Rules governs is everything downstream of that block: how Approval Assistant packages the escalation, and
how a human resolves it fast without re-deriving the numbers themselves. Every credit-blocked order's
`ai_approval_requests` row carries, in its linked `ai_decisions` evidence, a Forecast Agent contribution —
a payment-date prediction for this specific customer, `p10`/`p50`/`p90`, drawn from their own trailing
days-to-pay distribution — and a CFO contribution — the customer's current `credit_risk_tier`. Neither
number changes what the human is *allowed* to decide; both exist so a Finance Manager looking at "10.9%
over the limit, a customer with a 22-day average days-to-pay against 30-day terms, tier `low`" can decide
in seconds instead of minutes.

**Reconciling the fraud score's two scales.** `docs/ai/agents/FRAUD_AGENT.md` defines Fraud Detection's
authoritative `fraud_signals.risk_score`/`fraud_cases.risk_score` as `NUMERIC(4,3)` on a 0.000–1.000
scale, with platform-wide defaults `min_risk_score_to_notify = 0.500` and `min_risk_score_to_hold =
0.800`. This workflow's own gate, as already fixed under Orchestration and Participating Agents, fires at
"score ≥ 70" on the familiar 0–100 display scale used in the Sales module's own AI summary — which is
`risk_score ≥ 0.700`, a full 0.100 *below* Fraud Detection's own platform-wide default. This is not a
typo carried over between documents; it is a deliberate, company-configurable policy tightening
(`fraud_detection_rules.min_risk_score_to_hold`, scoped to `entity_type = 'sales_orders'`, distinct from
the platform default that other modules keep at 0.800) reflecting that a revenue-side transaction — money
not yet in the door — warrants stepping up sooner than, say, a vendor payment already past its own
matching controls. Run 3 under Worked Example is built specifically to exercise this gap: a combined
score of 0.760 that blocks a sales order on Sales' own tightened gate while sitting below what would
qualify as an automatic hold anywhere else on the platform.

**Fraud Detection's feature vector for a sales order**, computed at every confirmation attempt, mirrors
the shape `docs/ai/agents/FRAUD_AGENT.md` already establishes for vendor payments (`entity_type`,
`entity_id`, `company_id`, `features`, `computed_at`, `feature_schema_version`), substituted with
customer-side and order-side features:

```json
{
  "entity_type": "sales_orders",
  "entity_id": 11002,
  "company_id": 4821,
  "features": {
    "order_amount": 1240.000,
    "amount_zscore_vs_customer_history": 0.400,
    "is_first_order": true,
    "shipping_billing_address_mismatch": true,
    "shipping_address_age_hours": 0.3,
    "payment_method_age_days": 0,
    "order_placed_hour_local": 2,
    "order_velocity_24h_count": 1,
    "customer_account_age_days": 0
  },
  "computed_at": "2026-09-04T02:41:00Z",
  "feature_schema_version": "v3"
}
```

**Fusion, restated for this workflow's own gate**, following the same `combined_risk_score =
Σ(rule_weight_i × rule_hit_i) + ml_weight × ml_score` formula `docs/ai/agents/FRAUD_AGENT.md` defines
(not redefined here — only its inputs for a `sales_orders` entity are new): rule hits contributing to a
sales-order score include `first_order_large_relative_to_segment_median`, `shipping_billing_mismatch`,
`off_hours_placement`, and `new_payment_method`; the ML component is the same isolation-forest
reconstruction-error model scored against this customer segment's 90-day feature distribution. The fused
score, its rule hits, and the ML component are all persisted to `ai_decisions.confidence` and cited in
the `ai_approval_requests.hold_reason` verbatim — never summarized away into a bare number.

**One hold, one row, distinctly attributable reasons — resolving how "one row per subject_type" and
"never conflating a credit hold and a fraud hold" both hold at once.** The platform-wide
`ai_approval_subject_type` enum (`docs/ai/AI_COMMAND_CENTER.md`) has exactly one member for a blocked
sales order — `credit_hold` — and no separate `fraud_hold` member. Because credit-gate and fraud-gate
failures on the *same* `sales_orders` row are, by definition, the same distinct `subject_type`, Approval
Assistant's "one row per distinct `subject_type`" rule collapses them into exactly one
`ai_approval_requests` row whenever both fire together — there is only one subject_type in play, so
there is only one row to open. "Never conflating a credit hold and a fraud hold into a single ambiguous
approval" is satisfied inside that one row, not by opening a second one: `hold_reason` states each
triggering condition by name, and each condition's own `ai_decisions` row (`decision_point =
'credit_limit_check'` or `'fraud_step_up'`) remains independently queryable and independently
resolvable in the audit trail, so an auditor can always answer "was this order held for credit, for
fraud, or both, and separately, was each condition itself correct" — three questions, cleanly separable,
inside one queue item a human only has to open once.

**Confidence banding for the five suggest-only agents, reused, not redefined.** `docs/ai/agents/
SALES_AGENT.md` § Outputs already fixes the platform's three-band convention — below 0.500 suppressed or
explicitly labeled low-confidence; 0.500–0.850 an editable suggestion requiring a deliberate accept;
above 0.850 a one-click "Apply" that still requires the click. This workflow's own recommendation types
land, in practice, at the extremes of that range far more often than the middle: an `invoice_draft` drawn
from quantities that match a confirmed order's lines exactly typically scores 0.94–0.98 (Worked Example
Run 1 scores 0.960), because there is little left to be uncertain about once delivery and order agree
line-for-line; a `price_suggestion` for a first-time product/customer pairing with few comparables can
fall to 0.60–0.75, correctly landing in the editable-suggestion band rather than the one-click band.

**Escalation routing.** Every `ai_approval_requests` row Approval Assistant opens for this workflow
resolves its approver chain from the company's own `roles`/`permissions` configuration for the row's
`required_permission`, exactly as `docs/ai/AI_COMMAND_CENTER.md` § Approval Center specifies — the table
below is this workflow's own policy defaults, not a new mechanism:

| Trigger | `subject_type` | `required_permission` | Approver chain | SLA | On breach |
|---|---|---|---|---|---|
| Credit-limit breach ≤ 20% over `credit_limit` | `credit_hold` | `sales.credit.override` | Finance Manager (1 step) | 4 business hours (critical-sourced) | Auto-escalates to CFO; surfaces in Urgent Actions |
| Credit-limit breach > 20% over `credit_limit` | `credit_hold` | `sales.credit.override` | Finance Manager → CFO (2 steps) | 4 business hours per step | Terminates to `expired`; order stays blocked, Sales Manager notified |
| Fraud step-up alone (`risk_score ≥ 0.700`, credit gate passed) | `credit_hold` | `sales.credit.override` | Finance Manager (1 step); Auditor independently reviews within 1 business day if `risk_score ≥ 0.800` | 2 hours | Order stays blocked; no auto-release under any condition |
| Credit note ≥ company-configured threshold (default KWD 500.000) | `credit_note` | `sales.credit_note.post` | Finance Manager (1 step) | 2 business days | Auto-escalates to CFO |
| Credit note below threshold | — | `sales.credit_note.post` | None — ordinary human posting, no AI gate | — | — |

A rejection at any step terminates the whole chain (`ai_approval_requests.status = 'rejected'`), never
silently advancing to the next approver — identical to the Approval Center's platform-wide rule. Bulk-
approve is never available for a `credit_hold` or `credit_note` row, consistent with the platform rule
that anything gating a `sales.*` money-adjacent permission always renders and is decided individually.

# Failure, Retry & Rollback

**Idempotency is the first line of defense, and it is deterministic, not AI.** Every document-creating
`POST` in this workflow requires an `Idempotency-Key` header (Trigger & Preconditions); a retried request
with the same key inside 24 hours returns the original response rather than creating a second document.
This is unaffected by anything below — a network timeout that causes a client to retry `POST /api/v1/
sales/orders` never produces two orders, whether or not any agent's reasoning was involved in building
the payload.

**Compensating actions, saga-style — because posted `journal_entries` are immutable, "rollback" past that
point always means a reversing entry, never a delete:**

| Failure point | Compensating action | Who/what triggers it |
|---|---|---|
| Credit/fraud check errors (Laravel 5xx, timeout) during confirmation | Retry with exponential backoff (2s, 8s, 30s); after 3 failures, `ai_tasks.status = 'failed'`, confirmation attempt returns `503`, no partial state persists (the check runs inside the same transaction as the `status = 'confirmed'` write) | API gateway retry policy |
| Stock reserved, then a *re-run* credit check (triggered by an order amendment) fails | `stock_reservations` released for the amended lines | Inventory module, listening for the amendment event |
| Order held (`pending_approval`) and never resolved before the customer cancels | Reservation released, order moves to `cancelled`; no journal entries exist yet to reverse — nothing was ever posted for a document that never confirmed | Human cancellation action |
| Invoice-posting attempt fails balance validation (a defensive check; Sales' own line-level tax/price resolution should make this unreachable in practice) | Rejected before commit by `JournalEntryService::create()`; nothing is written; `ai_logs` records the attempted payload for engineering triage | Posting Engine's own invariant check |
| Duplicate `payment.received` webhook (a payment gateway's at-least-once delivery retrying) | `receipts.gateway_reference` carries a unique constraint; the duplicate delivery is acknowledged `200` but produces no second receipt, no second Entry C | Gateway-reference uniqueness at the database layer |
| A receipt allocation attempt targets an invoice whose `balance_due` reached zero moments earlier (race between two concurrent receipts) | `409 Conflict`, `error.code = 'INVOICE_ALREADY_SETTLED'`; the allocating user re-queries the customer's open invoices and re-allocates against a different one or records an on-account credit | Row-level lock on the invoice during allocation |
| `receipt.bounced` arrives after a receipt was already fully allocated and the invoice marked `paid` | A reversing entry re-opens the exact `receipt_allocations` amount, `invoices.status` reverts from `paid` to `partially_paid`/`unpaid`, `payment_status` recomputes; the customer's aging clock resumes from the *original* `due_date`, never from the bounce date | Banking module's own reversed-transaction event |
| A COGS posting error is discovered after the fact (wrong `cogs_unit_cost` snapshot) | Never edited in place — an adjusting `stock_movement` plus a reversing/correcting journal entry are posted against the original `delivery_item_id` | Auditor or General Accountant, human-initiated |

**Illustrative error responses**, in the same envelope `docs/accounting/SALES.md` § API already
establishes (`success`, `data`, `message`, `errors[]`, `meta`, `request_id`, `timestamp`):

| HTTP | `error.code` | Fires when |
|---|---|---|
| 409 | `CREDIT_LIMIT_EXCEEDED` | Confirmation attempted while `credit_check_status` would resolve to `failed` (see Worked Example, Run 2, for the exact payload) |
| 409 | `INVOICE_ALREADY_SETTLED` | A receipt allocation targets an invoice with `balance_due = 0` |
| 409 | `STOCK_INSUFFICIENT` | ATP at confirmation time is below the requested quantity and the company has not enabled backorders |
| 422 | `DUPLICATE_REQUEST` | An `Idempotency-Key` collision resolves to a *different* payload hash than the original request (a client bug, not a legitimate retry) |
| 422 | `AI_RECOMMENDATION_STALE` | An `ai_recommendations` row is accepted after its `recommendable`'s underlying record has since changed (e.g. the order's lines were amended after the `invoice_draft` was proposed); the endpoint returns `422` rather than posting against stale quantities and asks the caller to re-request the recommendation |

# SLAs & Timing

Deterministic Laravel steps are held to sub-second-to-low-single-digit-second targets; AI reasoning steps
to single-digit-second targets; human approval steps to the hours/days the Approval Center's own SLA
policy already fixes. None of the AI-step timings below are safety-critical the way the approval SLAs
are — a slow price suggestion degrades UX, not correctness — but they are the targets the FastAPI layer's
own internal monitoring alerts on.

| Step | Actor | Target latency | Basis |
|---|---|---|---|
| Price/discount suggestion on a quote line | CFO tool, via Sales Agent | ≤ 2 s | Structured lookup, no generation |
| ATP heads-up | Inventory Manager | ≤ 1 s | Indexed read against `inventory_items` |
| Credit-limit check | Deterministic (Laravel service layer) | ≤ 200 ms | Row-locked aggregate, no AI involved |
| Fraud/duplicate score | Fraud Detection | ≤ 3 s | Feature-vector build + rule engine + ML inference, synchronous within the confirmation request |
| Credit-hold escalation packaging (Forecast + CFO citations attached) | Approval Assistant | ≤ 5 s after the block | Asynchronous — does not hold up the `409` response to the confirming user |
| Credit-hold approval (≤ 20% over limit) | Human, Finance Manager | ≤ 4 business hours | `docs/ai/AI_COMMAND_CENTER.md` § Approval Center default for critical-sourced requests |
| Credit-hold approval (> 20% over limit, or fraud-linked) | Human, Finance Manager → CFO | ≤ 4 business hours per step | Same default, applied per step |
| Invoice-draft assembly after `delivery.delivered` (or `sales_order.confirmed` for on-confirmation billing) | Sales Agent + General Accountant mapping check | ≤ 10 s | Two sequential tool calls, no generation loop needed for a line-for-line match |
| Invoice posting | Deterministic (Posting Engine) | ≤ 500 ms | Balanced-entry construction and write, single transaction |
| Receipt allocation on a matched incoming payment | Deterministic + Sales Agent allocation suggestion | ≤ 5 s from webhook receipt | Gateway webhook to `payment.received` to Entry C |
| Credit-note approval (below threshold) | None — ordinary human posting | Same-day, no AI SLA | No gate applies |
| Credit-note approval (at/above threshold) | Human, Finance Manager | ≤ 2 business days | Approval Center default for non-critical-sourced requests |

# Worked Example

All four runs below share one customer and reuse, verbatim, the numbers `docs/accounting/SALES.md` § API
already establishes for sales order 9931 and invoice 71204 — this section's job is to carry that same
canonical example all the way through delivery, COGS, and cash, which the API reference document
illustrates only up to the invoice-post response.

**Setup.** Al Rayyan Trading Co. W.L.L. (`customer_id = 1042`), a Kuwait City wholesale account,
`credit_limit = KWD 6,000.000`, billing policy `on_order_confirmation` (an established relationship;
invoicing does not wait on physical delivery). Product 205 (electrical connectors), `cogs_unit_cost =
KWD 16.8000`; product 318 (cable ties, bulk), `cogs_unit_cost = KWD 21.5000` — both sourced from
`inventory_valuations`, warehouse 3.

## Run 1 — Happy path: both gates pass, straight through to cash

**2026-07-16T09:12:03Z** — Sales Employee Fahad creates `sales_orders` id `9931` directly (no prior
quotation), two lines: product 205 × 50 @ `unit_price 24.0000`, product 318 × 20 @ `unit_price 32.0000`.
`subtotal_amount 1748.0000`, `discount_amount 92.0000` (already reflected inside the two unit prices;
carried as an informational total), `tax_amount 87.4000` (5% VAT: `60.0000` on line 1, `27.4000` on line
2), `total_amount 1835.4000`.

**~09:12:20Z** — Fraud Detection scores the confirmation attempt: `combined_risk_score 0.060` (6/100
display). Feature highlights: `is_first_order: false` (14 prior orders), `shipping_billing_mismatch:
false`, `amount_zscore_vs_customer_history: 0.30`, `order_velocity_24h_count: 1`. Well under the 0.700
gate — no step-up.

**09:12:41Z** — Order confirmation: `balance_net_base` (this customer's current outstanding position)
`3,120.0000` + this order's `1,835.4000` = `4,955.4000`, which is 82.6% of the `6,000.000` limit — under
the 90% advisory threshold, so the quote/order UI shows no badge at all (the deliberate "don't manufacture
a warning nobody needs" design choice already fixed under Orchestration). `credit_check_status:
'passed'`. `sales_order.confirmed` fires; Inventory reserves 50 units of product 205 and 20 of product
318 at warehouse 3.

**~09:58:00Z** — Sales Agent assembles an `invoice_draft` recommendation (`on_order_confirmation` policy
means this can happen immediately, without waiting for a delivery event). General Accountant's
account-mapping check passes with no anomaly.

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

**10:02:11Z** — Finance user Sara, holding `sales.ai.approve_draft` and `sales.invoice.create` /
`sales.invoice.post`, accepts and posts in one session. Invoice `71204` (`INV-KWC-2026-14502`) posts;
`invoice.posted` fires (see the fully worked envelope under Events Emitted/Consumed). Because this
account bills `on_order_confirmation`, Entry A **and** Entry B post together, as two `journal_entries`
rows sharing `source_document_id = 71204`:

*Entry A — `journal_entry_id 553012`:*

| Account | Debit | Credit |
|---|---|---|
| Accounts Receivable — Trade | 1,835.4000 | |
| Sales Revenue | | 1,748.0000 |
| Output Tax Payable | | 87.4000 |

*Entry B — `journal_entry_id 553013`:*

| Account | Debit | Credit |
|---|---|---|
| Cost of Goods Sold | 1,270.0000 | |
| Inventory — Finished Goods | | 1,270.0000 |

(`50 × 16.8000 = 840.0000` for product 205, `20 × 21.5000 = 430.0000` for product 318; `840.0000 +
430.0000 = 1,270.0000`.) Gross profit on this order: `1,748.0000 − 1,270.0000 = 478.0000` — a 27.3%
gross margin, computed the moment the invoice posts and available to Forecast Agent for its next
pipeline-weighted revenue run.

**2026-07-17 → 2026-07-18** — Delivery `20512` is picked, packed, shipped, and delivered — a logistics-
only sequence at this point; COGS was already booked at invoice time, so `delivery.shipped` and
`delivery.delivered` fire for tracking and customer notification only, with the Posting Engine's
`delivery_item_id` tagging preventing any second COGS posting.

**Due date 2026-08-15** (30-day terms). **2026-08-10T11:20:00Z** — receipt `34890` arrives by bank
transfer into Bank Accounts – Boubyan Current (`account 1101.001`), `amount 1,835.4000`, allocated
100% against invoice 71204. `balance_due → 0.0000`; `status → 'paid'`; `payment_status → 'paid'`.

*Entry C — `journal_entry_id 553877`:*

| Account | Debit | Credit |
|---|---|---|
| Bank Accounts – Boubyan Current | 1,835.4000 | |
| Accounts Receivable — Trade | | 1,835.4000 |

`payment.received` fires; because this allocation brings `balance_due` to zero, `invoice.paid` fires in
the same transaction, `causation_id` pointing back at the `payment.received` event — five days ahead of
terms, no collections nudge was ever generated for this invoice.

## Run 2 — Credit-limit breach, escalated and overridden

Some months later, Al Rayyan places another order of the same value against a now-larger open position —
this is `docs/accounting/SALES.md`'s own "Example 2" (order confirmation blocked by credit limit),
continued forward through the AI escalation the API reference itself stops short of narrating.

**2026-09-22T08:40:00Z** — `sales_orders` id `10877` reaches confirmation. `balance_net_base` is now
`4,820.0000` (accumulated from other invoices since Run 1). This order's `total_amount 1,835.4000`
would bring exposure to `6,655.4000` — `655.4000` over the `6,000.000` limit. `credit_check_status:
'failed'`; confirmation is blocked with exactly the response `docs/accounting/SALES.md` documents:

```json
{
  "success": false, "data": null,
  "message": "Order confirmation blocked: credit limit exceeded.",
  "errors": [{
    "code": "CREDIT_LIMIT_EXCEEDED",
    "detail": "Customer 1042 outstanding AR is KWD 4,820.000; this order (KWD 1,835.400) would bring exposure to KWD 6,655.400, exceeding the credit limit of KWD 6,000.000.",
    "field": null
  }]
}
```

**Same moment** — Fraud Detection independently scores order 10877: `combined_risk_score 0.310` —
under the 0.700 gate on its own, so fraud alone would not have blocked this order. Because the credit
gate already did, Approval Assistant's packaging attaches the fraud score as supporting context, not as
a second trigger.

**08:40:05Z** — Approval Assistant opens exactly one row:

```json
{
  "subject_type": "credit_hold", "subject_id": 10877, "title": "Al Rayyan Trading — order 10877 exceeds credit limit by KWD 655.400",
  "amount": "655.4000", "currency_code": "KWD", "required_permission": "sales.credit.override",
  "hold_reason": "credit_limit_check: balance_net_base 4,820.0000 + order 1,835.4000 = 6,655.4000 > limit 6,000.0000 (breach 655.4000, 10.9% over). fraud_step_up: combined_risk_score 0.310, informational only, did not itself trigger.",
  "sla_due_at": "2026-09-22T14:00:00+03:00",
  "steps": [{ "step_order": 1, "approver_role": "Finance Manager", "status": "pending" }]
}
```

A single step, because a 10.9% breach sits under this workflow's own 20%-ceiling for a Finance-Manager-
only decision (Confidence & Escalation Rules). Attached evidence: Forecast Agent's payment-date
prediction for this customer (`p50 2026-10-08`, 8 days past this order's would-be due date, `confidence
0.71`, drawn from Al Rayyan's own trailing days-to-pay distribution) and CFO's current `credit_risk_tier:
'low'`.

**11:15:00Z** — Finance Manager reviews (well inside the 4-hour SLA), sees a low-risk-tier, 14-order
customer 10.9% over a limit that has not been revisited in a while, and approves with `reason: "Long-
standing low-risk account; approving a one-time override rather than adjusting the standing limit ahead
of next quarter's credit review."` `customer_credit_overrides` gets a new row (`document_type =
'sales_order'`, `subject_id 10877`); `credit_check_status → 'overridden'`; confirmation is re-attempted
and succeeds. From here, delivery/COGS/invoice/receipt proceed exactly as in Run 1's mechanics (Entry A,
Entry B or B-at-shipment depending on this order's own billing policy, Entry C on payment) — not
re-derived here to avoid repeating identical arithmetic.

## Run 3 — Fraud-only hold: credit passes, the fraud gate alone blocks confirmation

**2026-09-04T02:41:00Z** — A new customer, Dana Gulf Fashions W.L.L. (`customer_id 5510`, `credit_limit
KWD 2,000.000`, `balance_net_base 0.0000`, first order ever) attempts to confirm `sales_orders` id
`11002`, `total_amount KWD 1,240.000`. Credit check: `0.0000 + 1,240.000 = 1,240.000`, 62% of the limit —
passes silently.

Fraud Detection's feature vector (shown in full under Confidence & Escalation Rules) carries
`is_first_order: true`, `shipping_billing_address_mismatch: true` (the order ships to a newly registered
warehouse address, billing remains the company's registered office), `order_placed_hour_local: 2`
(off-hours), `payment_method_age_days: 0` (a bank account added the same session). Rule hits
`first_order_large_relative_to_segment_median` (weight 0.30), `shipping_billing_mismatch` (0.25),
`off_hours_placement` (0.10), `new_payment_method` (0.15), fused with an ML anomaly component of 0.740,
produce `combined_risk_score 0.760` — above this workflow's own 0.700 gate, though below Fraud
Detection's platform-wide default `min_risk_score_to_hold` of 0.800 (see Confidence & Escalation Rules
for why that gap is intentional). `sales_order.credit_blocked` does **not** fire (credit passed); instead
the order routes straight to `pending_approval` on the fraud gate alone.

**02:41:04Z** — Approval Assistant opens `ai_approval_requests`, `subject_type: 'credit_hold'` (the
platform enum's only member for a blocked sales order — see Confidence & Escalation Rules),
`required_permission: 'sales.credit.override'`, `hold_reason: "fraud_step_up: combined_risk_score
0.760 — first order, shipping/billing address mismatch, off-hours placement (02:41 local), payment
method added same session."`, `sla_due_at` two hours out (fraud-linked holds get the tighter SLA).

**08:55:00Z** — Finance Manager Khalid opens the hold. Following the exact scripted verification pattern
`docs/ai/AI_COMMAND_CENTER.md` § Fraud Alerts already established for a vendor bank-detail change (call
the *registered* contact number, not any number supplied with the order), he calls Dana Gulf's
registered office line, confirms the order and the new warehouse address are genuine — the company just
leased a second location — and approves with `reason: "Verbally confirmed with registered contact;
legitimate first order to a newly leased second warehouse."` Confirmation is re-attempted and succeeds;
because `risk_score 0.760 < 0.800`, this case does not additionally require the mandatory Auditor
corroboration that a `≥ 0.800` score would trigger within one business day.

## Run 4 — A return, and a credit note that does not need an approval gate

Six units of product 318 from Run 1's shipment arrive at Al Rayyan visibly damaged. A `sales_returns`
row is approved (`disposition: 'restock'` — a cosmetic defect, not a scrap-worthy one) against invoice
71204, which by now is already `status: 'paid'`.

`credit_notes`: `subtotal_amount = 6 × 32.0000 = 192.0000`; `tax_amount = 192.0000 × 5% = 9.6000`;
`total_amount = 201.6000`. `KWD 201.600` sits below this company's configured `credit_note_approval_
threshold` of `KWD 500.000`, so no `ai_approval_requests` row opens at all — Sales Manager approves the
return (`sales.return.approve`), Finance posts the credit note (`sales.credit_note.post`) the same day,
with no AI escalation in the path.

*Entry D:*

| Account | Debit | Credit |
|---|---|---|
| Sales Revenue | 192.0000 | |
| Output Tax Payable | 9.6000 | |
| Accounts Receivable — Trade | | 201.6000 |

Because invoice 71204's `balance_due` is already `0.0000`, this credit note does not reduce an open
balance — it creates a `KWD 201.600` customer-advance position on Al Rayyan's account instead of ever
displaying a negative `balance_due`, exactly as `docs/accounting/SALES.md` § Edge Cases specifies.

*Entry D′ (restock mirror), valued at the original invoice line's cost:*

| Account | Debit | Credit |
|---|---|---|
| Inventory — Finished Goods | 129.0000 | |
| Cost of Goods Sold | | 129.0000 |

(`6 × 21.5000 = 129.0000`, the same `cogs_unit_cost` Run 1's Entry B used — not a current, possibly
different, cost.)

# Controls & Audit

**Segregation of duties, enforced at the permission layer, not by convention.** No agent in this workflow
holds `sales.order.confirm`, `sales.invoice.create`, `sales.invoice.post`, `sales.receipt.create`,
`sales.credit_note.post`, or `sales.credit.override` — restated from Participating Agents because it is
the load-bearing fact behind every control below. A Sales Employee cannot approve their own credit-limit
override (`sales.credit.override` is a Finance Manager/CFO-role permission the Sales Employee role does
not carry); the segregation-of-duties violation pattern `docs/ai/AI_COMMAND_CENTER.md` § Fraud Alerts
already documents (a Sales Employee attempting a direct AR adjustment without a linked credit note) is
blocked the same way here — at the permission layer first, flagged independently second.

**The three-document match.** Before an `invoice_draft` is surfaced, General Accountant's mapping check
implicitly re-verifies that the invoice's quantities trace to a `delivery`/`sales_order` that themselves
trace to each other — the O2C analogue of the three-way match Purchase-to-Pay runs on its own documents.
A quantity mismatch anywhere in that chain fails the mapping check and the `invoice_draft` recommendation
is never proposed at a one-click confidence band; it is either suppressed or explicitly flagged, per the
Grounding validation `docs/ai/agents/SALES_AGENT.md` § Reasoning & Prompt Strategy already specifies.

**Every gate evaluation is logged, whether or not it produced a visible recommendation.** Restated from
Orchestration because Controls & Audit is where it matters most: `ai_decisions` proves what the
orchestration *considered* — including the `auto`-tier evaluations nothing was ever shown to a human
for — closing the audit question "could this order have been auto-approved, and why wasn't it," on every
one of the four runs above, not only the ones that escalated.

**Reconciliation, nightly, automatic.** The identity `docs/accounting/SALES.md` § Accounting Integration
defines — posted `journal_lines` for `invoices`/`receipts`/`credit_notes`/`refunds`/`deliveries` sum to
the cent against their source documents — runs nightly and alerts Finance on drift. Because no agent in
this workflow can post, edit, or delete a `journal_entries` row, a drift alert on an O2C-tagged entry can
only originate from a manual, out-of-band posting — itself a flagged, auditable event, never traceable to
this workflow's own automation.

**Permission recap** (all pre-existing, none introduced by this document):

| Permission | Default roles |
|---|---|
| `sales.quotation.create`, `sales.order.create` | Sales Employee, Sales Manager |
| `sales.order.confirm` | Sales Manager, Sales Employee (per company policy) |
| `sales.ai.approve_draft` | Sales Employee, Sales Manager, Accountant (the *second*, additive gate on any AI draft) |
| `sales.invoice.create`, `sales.invoice.post` | Accountant, Senior Accountant, Finance Manager |
| `sales.receipt.create`, `sales.receipt.allocate` | Accountant, Senior Accountant |
| `sales.credit.override` | Finance Manager, CFO |
| `sales.return.approve` | Sales Manager |
| `sales.credit_note.post`, `sales.refund.create` | Finance Manager, CFO |
| `ai.approve` (act on any `ai_approval_requests` row) | Finance Manager, CFO, Senior Accountant (per Approval Center's own role table) |

# Edge Cases

- **Partial delivery / backorder.** ATP falls short at confirmation; if the company allows backorders,
  the order confirms with the short lines flagged, Inventory Manager supplies an expected-restock date,
  and Entry B/Entry A post per-delivery as partial shipments go out — never as one lump entry waiting for
  full fulfillment.
- **Overpayment.** A receipt exceeds the invoice(s) it settles; the excess posts to Customer Advances
  (Entry C's third line) rather than a negative `balance_due` — `docs/accounting/SALES.md` is explicit
  that a negative balance is never displayed to a customer as "owing negative money."
- **Underpayment / short payment with a deduction claim.** The receipt allocates its actual amount;
  `balance_due` remains positive for the shortfall, which ages normally and surfaces to Sales Agent's
  collections sweep like any other partially-paid invoice — no automatic write-off ever happens without a
  human-initiated Bad Debt Expense entry.
- **Multi-currency settlement.** A receipt in a different currency/rate than its invoice posts the FX
  Gain/Loss line already shown under Entry C; the underlying AR relief is always computed in base
  currency, never in the receipt's transaction currency.
- **Cancellation after partial fulfillment.** Delivered/invoiced quantities cannot be cancelled out of
  existence — a return/credit-note path is required for anything already shipped; only the *undelivered*
  remainder of an order can be cancelled outright, releasing its reservation.
- **A credit note against an already-fully-paid invoice** (Run 4) creates a customer-advance position,
  never a negative balance — see above.
- **Duplicate invoice detection.** The `Idempotency-Key` block is deterministic and sufficient on its own;
  Sales Agent's fuzzy duplicate-order pass is explicitly advisory on top of it, never a replacement for
  it, per `docs/ai/agents/SALES_AGENT.md` § Guardrails.
- **A price-list change lands between quotation and order confirmation.** Quotations freeze pricing at
  `sales.quotation.send` time (`price_list_item_id` resolved and stored on the line, not re-resolved
  later); a subsequent price-list change never silently reprices an accepted quotation.
- **A tax-rate change lands between quotation and invoice posting.** Every document resolves its tax rate
  as of its *own* document date against `tax_rates`' effective-dated rows (`docs/accounting/TAX.md`),
  never as of posting time — a quotation dated before a rate change and invoiced after it still invoices
  at the rate that was effective on the quotation's own date, consistent with how the 2020 5%→15% GCC VAT
  transition is handled platform-wide.
- **A fraud hold turns out to be a false positive.** The approving human's `reason` field is the appeal
  record; there is no separate appeal workflow because the hold was never a rejection — clearing it *is*
  the resolution, and Fraud Detection's own model-retrain loop (out of scope here, owned by `docs/ai/
  agents/FRAUD_AGENT.md`) treats a cleared hold with a "legitimate" reason as a negative training signal.
- **`receipt.bounced` after allocation.** Handled under Failure, Retry & Rollback — the reversing entry
  and status rollback restore the invoice to its pre-receipt aging position, dated from the *original*
  due date.
- **An AI draft is accepted after the underlying record changed underneath it.** Rejected `422
  AI_RECOMMENDATION_STALE` (Failure, Retry & Rollback) rather than posted against stale quantities — the
  concrete, code-level enforcement of "the model may not invent a number" applied to timing, not only to
  arithmetic.

# Future Improvements

- **Dynamic, model-driven credit scoring** replacing the static `credit_limit` field with a continuously
  recomputed exposure ceiling — extending CFO's existing `credit_risk_tier` work with cash-flow-forecast
  inputs, while keeping the actual limit change a human Finance action exactly as today.
- **Policy-bounded discount auto-negotiation.** A reinforcement-learning layer over Sales Agent's existing
  price-suggestion history (acceptance-rate-labeled) to tighten the suggested band itself over time,
  still surfaced as a suggestion inside the same one-click Apply affordance — never a new autonomy tier.
- **Cross-customer fraud graph analysis.** Today's `sales_orders` scoring is per-order; a graph model over
  shared shipping addresses, payment instruments, and device fingerprints across *different* customer_ids
  would catch a coordinated pattern no single order's feature vector can see.
- **Predictive dunning before an invoice is even overdue.** Forecast Agent's payment-date prediction
  already exists (used inside the credit-hold escalation package); wiring the same prediction into a
  pre-due-date nudge for invoices predicted to run late would shift collections left instead of only
  reacting to `invoice.overdue`.
- **Open-banking-fed instant receipt matching**, closing the gap between a bank crediting an account and
  `payment.received` firing — today bounded by the payment gateway/bank-feed webhook's own latency, not
  by anything in this workflow.
- **Computer-vision proof-of-delivery** feeding Document AI/OCR Agent to auto-verify a signed delivery
  note against the `delivery_items` it corresponds to, tightening the three-document match in Controls &
  Audit from "quantities reconcile in the database" to "quantities reconcile against a photographed
  signature."

# End of Document
