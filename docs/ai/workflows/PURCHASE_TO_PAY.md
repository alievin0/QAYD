# Purchase-to-Pay (P2P) Workflow — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: PURCHASE_TO_PAY
---

# Purpose

Purchase-to-Pay is the single workflow that makes QAYD's "autonomous finance workforce" claim concrete rather than aspirational. A traditional ERP's procurement module is a filing cabinet with validation rules: a human raises a request, a human sources a vendor, a human keys a receipt, a human re-keys the vendor's invoice, a human reconciles three documents by eye, and a human decides, at every step, what happens next. QAYD's P2P workflow keeps every one of those documents and every one of those controls — the Purchase Request, the Purchase Order, the Goods Receipt, the three-way match, the Bill, the Payment — but replaces the human keystrokes and the human vigilance in between with six specialized agents that watch the same tables a human would watch, continuously, and act inside a hard permissioned boundary before anyone has to ask. The Purchasing Agent drafts the reorder before a shelf is empty. Document AI and OCR read the vendor's invoice the moment it lands in an inbox. The General Accountant resolves the GL account and the tax code the moment a bill needs one. Fraud Detection is already comparing this bill to the last ninety days of the same vendor's billing behavior before a human opens the screen. Treasury Manager is already deciding which of this week's bills to pay early for the discount and which to defer, bounded by the cash the company actually has. None of that removes a single control a Finance team relies on today — every sensitive step still stops at a human, at the same amount tiers, through the same approval chain, with the AI's contribution fully visible as a confidence-scored, source-cited proposal rather than a black box.

This document is the orchestration layer. It does not redefine the Purchasing module's tables, states, or business rules — those are owned, exhaustively, by `docs/accounting/PURCHASING.md`. It does not redefine any individual agent's mandate, tool contract, or autonomy table — those are owned by `docs/ai/agents/PURCHASING_AGENT.md` (the named orchestrator for this workflow), `docs/ai/agents/ACCOUNTANT_AGENT.md`, `docs/ai/agents/TREASURY_AGENT.md`, and `docs/ai/agents/FRAUD_AGENT.md`. What this document specifies, precisely and for the first time, is the thing none of those documents can specify on their own: how six agents and a chain of human approvers hand a single purchase off to one another from the moment a need is recognized to the moment cash leaves the bank — the exact sequence, the exact journal entries the sequence produces, the exact events that wake each participant, the confidence and escalation rules that decide whether a step clears itself or stops for a human, what happens when a step fails partway through, how long each stage is allowed to take, and how an Auditor reconstructs the whole chain after the fact. Accounting becomes supervised, not manual, precisely because this orchestration is written down to this level of detail — an engineer implementing the FastAPI/LangGraph side, and an engineer implementing the Laravel side, can build against this document without a single follow-up question about who does what, when, or under what confidence.

# Trigger & Preconditions

The workflow has one canonical entry point and three shortcut entry points, all converging on the same downstream chain.

**Canonical entry — Purchase Request.** A human (any role holding `purchasing.request.create`) or the Purchasing Agent (`source = 'ai_forecast'` or `'reorder_point'`, per its Purchase Prediction capability) creates a `purchase_requests` row in `draft`. This is the entry point for the overwhelming majority of stocked-inventory and planned-services spend, and the one this document's Worked Example follows end to end.

**Shortcut entry — direct Purchase Order.** A `purchasing.order.create` holder with authority up to the applicable threshold may create a `purchase_orders` row directly, with no antecedent `purchase_requests` row, for a `blanket`/`contract_release` PO type drawing down an already-`active` `procurement_contracts` row (RFQ and PR are both correctly skipped — the commercial terms were already negotiated when the contract was signed).

**Shortcut entry — non-PO Bill.** A vendor bill for a service or a small purchase with no PO behind it (`purchase_order_id IS NULL`) enters the workflow directly at the Bill stage, skips the three-way match entirely (there is nothing to match against), and is capped at the company-configured `non_po_bill_max_amount` (default KWD 500 base-currency equivalent) — anything larger is rejected at submission and redirected to raise a proper Purchase Request instead.

**Shortcut entry — landed-cost / debit-note lines.** Freight, customs, and return/credit corrections attach to an already-open PO or Bill rather than starting a new chain; they are covered in Journal Entries Produced and Edge Cases rather than as a fourth top-level entry point.

**Preconditions that must hold before any step below can execute.** The company has an active Chart of Accounts with resolvable default mappings for Inventory, GR/IR Clearing, Accounts Payable — Trade, Input VAT Receivable, Purchase Price Variance, and at least one operating Bank account (`docs/accounting/CHART_OF_ACCOUNTS.md`). The vendor exists in `vendors` at `status = 'active'` — a `blocked`/`suspended` vendor cannot receive a new PO or Bill, though an already-open document against a since-blocked vendor is allowed to finish its own lifecycle. Where the purchase will carry standard-rated input VAT, the vendor's `tax_registration_number` is present and verified (an unverified registration forces the line to `exempt`/`non_recoverable` until cleared — see Controls & Audit). The requester, department, and cost center on the originating PR/PO resolve to real, active rows. Every AI participant in this chain (Purchasing Agent, General Accountant, Treasury Manager, Fraud Detection) is registered in `ai_agents` with an `autonomy_level` ceiling at or above what each step below requires, and every one of them is scoped to exactly the triggering event's `company_id` — no step in this workflow ever executes across a tenant boundary.

# Participating Agents

| Agent | Primary Role in This Workflow | Autonomy Ceiling Here | Governing Document |
|---|---|---|---|
| **Purchasing Agent** | Named orchestrator for the whole chain: drafts the PR, ranks the RFQ shortlist, drafts the Bill from OCR extraction, runs the three-way match, flags duplicates | Draft-only / suggest-only; three-way match execution is auto (compute-only) | `docs/ai/agents/PURCHASING_AGENT.md` |
| **Document AI** | Classifies an inbound file (vendor invoice vs. delivery note vs. contract vs. credit note) before anything downstream touches it | Auto (deterministic classification, no financial effect) | `docs/foundation/AI_ARCHITECTURE.md` (platform intake pattern; no dedicated agent document yet) |
| **OCR Agent** | Extracts header and line-item fields from the classified document with a per-field confidence score | Auto (compute-only; below-floor fields are held, never guessed) | `docs/foundation/AI_ARCHITECTURE.md` (platform intake pattern; no dedicated agent document yet) |
| **General Accountant** | Resolves the GL account for non-PO/non-stocked Bill lines, validates the tax-code mapping, drafts the correcting entry if a Trial-Balance finding later traces back to this purchase | Suggest-only; drafts land as `journal_entries.status = 'draft'`, never posted by the agent | `docs/ai/agents/ACCOUNTANT_AGENT.md` |
| **Treasury Manager** | Consumes the posted Bill's due date and discount terms, computes the live cash position, and proposes which bills a payment run should include and when | Suggest-only; the narrowest write surface of any agent in this chain (draft `bank_transactions` only, never `bank.transfer` or any approval key) | `docs/ai/agents/TREASURY_AGENT.md` |
| **Fraud Detection** | Continuously screens every Bill and every Vendor Payment in this chain for duplicate/near-duplicate billing, vendor bank-account-change patterns, structuring, and segregation-of-duties conflicts | Suggest/alert-only; a hold is *requested*, never placed, by this agent | `docs/ai/agents/FRAUD_AGENT.md` |
| **Approval Assistant** | Routes every `requires_approval = true` output from the five agents above to the correct human role and approval-chain step, and drives the SLA-escalation timers in SLAs & Timing | Never approves anything itself | `docs/foundation/AI_ARCHITECTURE.md` (platform-wide routing pattern, referenced identically by `docs/ai/agents/TREASURY_AGENT.md` and `docs/ai/agents/ACCOUNTANT_AGENT.md`) |

The Purchasing Agent is the named orchestrator, not a supervisor of the other five — it is a peer specialist that happens to own the majority of this workflow's document types, and it delegates outward exactly as `docs/ai/agents/PURCHASING_AGENT.md` § Collaboration With Other Agents specifies: accounting judgment to General Accountant, cash-timing judgment to Treasury Manager, risk corroboration to Fraud Detection, and document extraction to Document AI/OCR. No agent in this table ever re-derives a number another agent already owns — when the Purchasing Agent needs a GL account, it asks General Accountant; when it needs a cash-availability judgment, it asks Treasury Manager; it never approximates either itself.

**Secondary participants, consulted but not orchestrating.** Inventory Manager is the authority on physical stock truth and costing-method interaction (Goods Receipt posting, moving-average/FIFO/standard-cost mechanics) but is not itself an AI decision point in this chain — the Goods Receipt's accounting posting is deterministic system code, not an agent proposal (see Orchestration, Stage 3). The Auditor consumes every output in this chain read-only, for independent corroboration, and never receives a write permission regardless of company configuration. The CFO is a recipient of Treasury Manager's payment-run and discount-capture insight and, at the highest PO/Payment approval tiers, a human approval-chain participant in their own right — never a delegate for any agent above.

# Orchestration

The chain below is deliberately linear with two conditional branches (the three-way match outcome, and the payment-approval tier), because procure-to-pay is a bounded, auditable business process, not open-ended agent exploration. `[H]` marks a step that cannot advance without a human decision. `[AI]` marks a step where an agent computes and proposes. `[SYS]` marks a step that is deterministic system code — the Accounting posting service reacting to a domain event — with no agent judgment involved at all; this distinction matters because not every automatic step in this chain is "AI," and this document is precise about which is which.

```
PURCHASE-TO-PAY — END-TO-END ORCHESTRATION
================================================================================

 [AI] TRIGGER                                    [H] MANUAL TRIGGER
 inventory.low_stock /                             an employee has a need
 demand-forecast stockout window                          │
        │                                                 │
        └──────────────────┬──────────────────────────────┘
                            ▼
              ┌───────────────────────────┐
              │ [AI] Purchasing Agent      │  draft_purchase_request — status='draft'
              │ drafts the PR              │  source='ai_forecast' or a human's own draft
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │ [H] PR APPROVAL CHAIN      │  Dept Head → Purchasing Mgr → Finance Mgr
              │ tiered by estimated_total  │  (tier depends on amount; Approval Assistant
              │ _amount                    │  routes + runs the SLA-escalation timer)
              └─────────────┬─────────────┘
                            │ approved
                            ▼
              ┌───────────────────────────┐
              │ RFQ / Vendor Award         │  [AI] Purchasing Agent ranks the shortlist
              │ (skippable for a contract  │  (suggest-only); [H] Purchasing Manager awards
              │ -release PO)               │
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │ PO created                 │  from the awarded RFQ response, or direct entry
              └─────────────┬─────────────┘  for a contract-release PO
                            ▼
              ┌───────────────────────────┐
              │ [H] PO APPROVAL CHAIN      │  Purchasing Mgr → Finance Mgr → CFO if > 10,000
              └─────────────┬─────────────┘
                            │ approved → sent
                            ▼
              ┌───────────────────────────┐
              │ [SYS] GOODS RECEIPT        │  Warehouse posts the GR; stock_movements +
              │ (stock in)                 │  the GR/IR Clearing journal entry post in the
              └─────────────┬─────────────┘  same DB transaction — deterministic, no agent
                            │ goods_receipt.posted
        ┌───────────────────┼────────────────────────────────┐
        ▼                   ▼                                ▼
 ┌─────────────┐   ┌─────────────────┐              ┌────────────────────┐
 │ [AI]         │   │ [AI] OCR Agent   │              │ [AI] Fraud Detection│
 │ Document AI  │──▶│ extracts header  │─────────────▶│ continuous screen:  │
 │ classifies   │   │ + lines, per-    │              │ duplicate / BEC /    │
 │ the inbound  │   │ field confidence │              │ structuring          │
 │ vendor file  │   └─────────────────┘              └──────────┬──────────┘
 └─────────────┘                                                │ risk_score ≥ 0.80
                            │                                    │ (request_transaction_hold,
                            ▼                                    │  never places it directly)
              ┌───────────────────────────┐                     │
              │ [AI] Purchasing Agent      │◀────────────────────┘
              │ drafts the Bill; runs the  │
              │ three-way match (compute)  │
              └─────────────┬─────────────┘
                 matched ┌──┴──┐ variance
                         ▼     ▼
        ┌──────────────────┐   ┌───────────────────────────┐
        │ [SYS] AUTO-APPROVE│   │ [H] FINANCE MANAGER        │
        │ posts in the same │   │ purchasing.bill.override_  │
        │ pass, zero human   │   │ variance — accept / dispute│
        │ touch              │   │ / correct the line         │
        └─────────┬─────────┘   └─────────────┬─────────────┘
                   └──────────────┬───────────┘
                                  ▼
              ┌───────────────────────────┐
              │ [SYS] BILL POSTS           │  Input VAT / AP-Trade / GR-IR Clearing entry
              └─────────────┬─────────────┘
                            │ bill.posted
                            ▼
              ┌───────────────────────────┐
              │ [AI] Treasury Manager      │  daily 05:00 liquidity pass; proposes a
              │ proposes a PAYMENT RUN     │  payment-run batch, discount-capture timing
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │ [H] FINANCE MGR REVIEWS    │  edits/confirms the batch, submits it
              │ THE BATCH                  │
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │ [H] bank.payment.approve   │  Finance Manager — first key
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │ [H] bank.payment.approve   │  CEO (or a delegate holding the final key)
              │      .final                │  maker (FM) ≠ checker (CEO), always
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │ [SYS] ScheduledPayment     │  dispatches at value_date, every 15 minutes,
              │ Dispatcher                 │  onto the appropriate bank rail
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │ [SYS] PAYMENT CLEARS       │  AP-Trade Dr / Bank Cr / Discount Cr entry;
              │ Bill → paid                │  bills.status → paid; vendor scorecard updates
              └───────────────────────────┘
```

**Stage 0 — Trigger.** Either `inventory.low_stock` fires from Inventory Manager's own posting (a `stock_movements` row drops `inventory_items.quantity_on_hand` below `products.reorder_point`), and the Purchasing Agent's `events-ai` subscription catches it, or a human simply has a need and opens the Purchase Request screen. Both paths converge on an identical `purchase_requests` row; the only difference is `source` (`'ai_forecast'`/`'reorder_point'` vs. `'manual'`).

**Stage 1 — PR drafted and approved.** An AI-drafted PR is created in `draft`, never `pending_approval` — the department owner or a Purchasing Employee must review and submit it, exactly like a manually raised PR, before it enters the approval chain (`docs/accounting/PURCHASING.md` § Purchase Requests; `docs/ai/agents/PURCHASING_AGENT.md` § Autonomy Level). Once submitted, it routes through the tiered chain in `docs/accounting/PURCHASING.md` § Approval Workflow (≤ 250 base-currency equivalent: Department Head alone; 250–2,500: Department Head → Purchasing Manager; > 2,500: Department Head → Purchasing Manager → Finance Manager). The AI origin is recorded but confers zero special treatment — an AI-drafted PR waits in exactly the same queue, under exactly the same SLA, as a human-raised one.

**Stage 2 — Sourcing and PO.** An approved PR is either merged into an RFQ (the Purchasing Agent pre-populates a ranked vendor shortlist, suggest-only, and a human confirms the invite list and later awards the winning quotation) or converted directly to a PO where a `procurement_contract` already fixes pricing. The resulting PO routes through its own tiered chain (≤ 500: Purchasing Manager; 500–10,000: Purchasing Manager → Finance Manager; > 10,000: Purchasing Manager → Finance Manager → CFO), and only once `approval_status = 'approved'` can it transition to `sent` (`docs/accounting/PURCHASING.md` BR-PO-4).

**Stage 3 — Goods Receipt.** Physical arrival is recorded by a Warehouse Employee against the PO's line items. Posting a GR is atomic with creating `stock_movements`, updating the item's costing (moving-average/standard/FIFO per `docs/accounting/PURCHASING.md` § Inventory Integration), and posting the GR/IR Clearing journal entry — all inside one database transaction, all deterministic system code triggered by the `GoodsReceived` domain event, with no agent in this chain making a judgment call at this step. This is the one stage in the whole workflow that is purely `[SYS]`: the physical fact of what arrived is a human observation (the receiving clerk), and the accounting consequence of that fact is arithmetic, not inference.

**Stage 4 — Vendor Bill intake and the three-way match.** A vendor's invoice — by email, portal upload, or manual scan — is classified by Document AI (invoice vs. delivery note vs. contract vs. credit note) and field-extracted by OCR Agent, each field carrying its own confidence. The Purchasing Agent maps the extraction onto `bills`/`bill_items`, holding any field below the 0.80 extraction-confidence floor for manual re-key rather than guessing, and immediately runs the three-way match: quantity (`bill_items.quantity_billed` vs. `goods_receipt_items.quantity_accepted`, default 0% tolerance), price (`bill_items.unit_price` vs. `purchase_order_items.unit_price`, default 2% or KWD 5.000 absolute, whichever is looser), and tax code. A clean match sets `bills.match_status = 'matched'` and the Bill auto-advances through `pending_approval` to `approved` with zero human touch — this is the "in-tolerance bills auto-approve" row in `docs/accounting/PURCHASING.md` § Approval Workflow, and it is what makes the Purchasing Agent's own Three-Way-Match Auto-Clear Rate metric (target 60–85%) meaningful. Any flag sets `match_status = 'variance'` and blocks `approved → posted` until a Finance Manager (`purchasing.bill.override_variance`) accepts the variance with a reason, disputes it back to the vendor, or corrects the line. Fraud Detection scores every incoming Bill in parallel against the vendor's own 90-day billing history and the company's structuring/segregation-of-duties patterns, independent of whether the three-way match itself passes — a Bill can match perfectly on quantity and price and still be a near-duplicate resubmission, which is exactly the pattern the Purchasing Agent's own Duplicate Bill Detection and Fraud Detection's cross-transaction correlation are each independently tuned to catch (see Confidence & Escalation Rules).

**Stage 5 — Bill posts.** The instant a Bill reaches `approved` — whether by auto-clear or by a human's override decision — posting to `posted` is atomic with creating its journal entry and clearing the corresponding GR/IR Clearing balance (`docs/accounting/PURCHASING.md` BR-BILL-5). This is the financial event that turns a physical receipt into a recognized Accounts Payable liability; see Journal Entries Produced for the exact shape.

**Stage 6 — Payment scheduling.** Treasury Manager's daily 05:00 liquidity pass (and any `bill.approved`/`bill.posted` event in between) picks up every `approved`/`posted` Bill as a candidate for the next payment run. It never proposes paying a Bill Purchasing has not itself cleared, and it never decides Bill correctness — it consumes the Bill's due date and payment terms exactly as posted and layers on cash-timing judgment: whether the company's current liquidity ratio and the Forecast Agent's cash-flow projection make it safe to pay early and capture a discount, or safer to hold cash and pay on the due date instead (`docs/ai/agents/TREASURY_AGENT.md` § Autonomy Level, § Reasoning & Prompt Strategy). Its output is a `treasury_payment_run_proposal` — a draft `bank_transactions` batch a Finance Manager must review, edit, and submit; Treasury Manager holds `bank.transaction.create` for a draft line only and never `bank.transaction.submit`.

**Stage 7 — Payment approval and dispatch.** A submitted batch enters Banking's own two-key approval chain: Finance Manager holds `bank.payment.approve` (the first key), and only the CEO (or a named delegate holding `bank.payment.approve.final`) can give the second, final sign-off — the AI service-account principal is structurally ineligible for either key regardless of any grant in `roles`/`permissions` (`docs/accounting/BANKING.md` § Permissions). Once both keys are recorded, the transaction moves to `scheduled` and the `ScheduledPaymentDispatcher` job (running every 15 minutes) submits it to the appropriate rail — SWIFT wire, local clearing/KNET B2B, check, or payment-provider payout — exactly at its `value_date`.

**Stage 8 — Clearing and close.** A cleared payment posts its own journal entry, decrements the Bill's `amount_due` via its `vendor_payment_allocations` row, transitions the Bill to `paid` (or `partially_paid`), and publishes `vendor_payment.completed` — which Vendors consumes to refresh `on_time_delivery_rate`/`total_spend_lifetime`-adjacent scoring inputs and which Approval Assistant uses to close out every open notification thread this specific purchase generated, from the original PR approval to the final payment release.

# Data & Tables Touched

No table in this section is owned by the AI layer. Every one of them is owned by the Laravel module named in the right-hand column, and every write an agent makes to any of them travels through that module's own authenticated REST API, `FormRequest` validation, and permission gate — never a direct database write (`DESIGN_CONTEXT` § 1; restated per-agent in every governing document listed in Participating Agents).

| Stage | Tables | Key Fields Touched | Owning Module |
|---|---|---|---|
| PR | `purchase_requests`, `purchase_request_items` | `status`, `source`, `estimated_total_amount`, `quantity_requested`, `department_id`, `cost_center_id` | Purchasing |
| Approval (every stage) | `approval_policies`, `approval_steps`, `approval_actions` | `document_type`, `document_id`, `step_order`, `actor_id`, `action` | Foundation (shared workflow engine) |
| RFQ | `rfqs`, `rfq_items`, `rfq_responses` | `invited_vendor_ids`, `line_quotes`, `awarded_vendor_id`, `awarded_po_id` | Purchasing |
| PO | `purchase_orders`, `purchase_order_items` | `status`, `approval_status`, `unit_price`, `quantity_ordered`, `quantity_received`, `quantity_billed` | Purchasing |
| Goods Receipt | `goods_receipts`, `goods_receipt_items` | `quantity_accepted`, `quantity_rejected`, `hold_status`, `bin_location_id` | Purchasing (receiving), Warehouses (bin resolution) |
| Physical stock | `stock_movements`, `inventory_items` | `quantity_on_hand`, `average_cost`/`standard_cost` variance, `hold_status` | Inventory |
| Quality (conditional) | `quality_inspections` | `disposition`, `quantity_passed`, `quantity_failed`, `deviation_approval_by` | Purchasing |
| Bill intake | `bills`, `bill_items`, `attachments` | `match_status`, `match_variance_amount`, `vendor_bill_reference`, extraction confidence (via linked `attachments`) | Purchasing |
| Tax | `tax_transactions`, `tax_codes`, `tax_rates` | `tax_code_id`, `taxable_amount`, `tax_amount`, `direction='input'`, `is_reverse_charge` | Tax |
| Payment | `vendor_payments`, `vendor_payment_allocations` | `status`, `total_amount`, `discount_taken`, `allocated_amount` | Purchasing |
| Cash leg | `bank_transactions`, `payment_batches` (batch grouping at the API level) | `transaction_type='outgoing_payment'`, `status`, `ai_generated`, `value_date` | Banking |
| Accounting | `journal_entries`, `journal_lines`, `ledger_entries` (GL projection) | `entry_type`, `status`, `source_type`/`source_id`, `ai_confidence` | Accounting |
| Vendor context | `vendors`, `vendor_bank_accounts` (masked), `vendor_contacts` | `status`, `risk_rating`, `on_time_delivery_rate`, `iban_hash`/last-4 only | Vendors |
| AI governance | `ai_decisions`, `ai_agents`, `audit_logs` | `decision_type`, `confidence_score`, `status`, `agent_code`, `ai_agent_id` | AI Platform (shared, `docs/ai/agents/CFO_AGENT.md`) |
| Fraud-specific | `fraud_signals`, `fraud_cases` | `signal_type`, `risk_score`, `hold_applied`, `resolution` | AI Platform (`docs/ai/agents/FRAUD_AGENT.md`) |
| Orchestration queue | the per-agent `ai_tasks` queue | `task_type`, `trigger_source`/`ai_task_trigger`, `status` | AI Platform (`docs/ai/agents/CFO_AGENT.md` and `docs/ai/agents/PURCHASING_AGENT.md` § Reasoning & Prompt Strategy — referenced here, not redefined) |

Every document created downstream of a PR carries a foreign key back to the document that spawned it — `purchase_order_items.source_pr_item_id`, `goods_receipt_items.purchase_order_item_id`, `bill_items.goods_receipt_item_id`, `vendor_payment_allocations.bill_id` — so an Auditor or a CFO can walk the entire chain backward from a posted journal line to the employee who first raised the need, in the "five clicks, no dead ends" property `docs/accounting/PURCHASING.md` § Vision commits to. No agent in this workflow ever writes to a table it does not own; the AI-governance tables (`ai_decisions`, `fraud_signals`/`fraud_cases`, the orchestration queue) exist precisely so that every one of the module tables above stays exclusively Laravel-owned.

# Journal Entries Produced

Every entry below is posted by the Accounting module's posting service, never by an agent's own database write, and every one balances to the base-currency minor unit before it is allowed to persist (`docs/accounting/JOURNAL_ENTRIES.md` § Posting Engine). Account codes are illustrative — `docs/accounting/PURCHASING.md` § Accounting Integration is explicit that actual resolution is company-configurable via `account_type_id`-based default mapping, never a hardcoded code — but the codes below follow `docs/accounting/CHART_OF_ACCOUNTS.md` § Numbering System (`1xxx` asset, `2xxx` liability, `5xxx` expense, `8xxx` other income) and reuse the exact accounts already established in `docs/ai/agents/ACCOUNTANT_AGENT.md` (`1010` Bank — NBK Operating, `2110` Accounts Payable — Trade) and `docs/accounting/BANKING.md` (`1010`, `6410` Bank Charges Expense) for cross-document consistency.

**1. Goods Receipt posting — stock item, moving-average costing (Stage 3, `[SYS]`).** Triggered by the `GoodsReceived` event, at `received_unit_cost` (ex-tax; VAT is not yet recognized because no tax invoice has arrived):

```
Dr  1140 · Inventory — Trading Goods         received_unit_cost × quantity_accepted
    Cr  2150 · GR/IR Clearing                                          received_unit_cost × quantity_accepted
```

**1b. Goods Receipt posting — standard costing, with variance.**

```
Dr  1140 · Inventory (at standard_cost × quantity_accepted)
Dr/Cr 5190 · Purchase Price Variance          (the difference; sign depends on favorable/unfavorable)
    Cr  2150 · GR/IR Clearing                                (at received_unit_cost × quantity_accepted)
```

**2. Vendor Bill posting — matched, PO-backed, standard-rated vendor (Stage 5, `[SYS]`, domestic or standard-VAT-charging vendor).**

```
Dr  2150 · GR/IR Clearing                     subtotal_amount
Dr  1180 · Input VAT Receivable                tax_amount
Dr/Cr 5190 · Purchase Price Variance           bill_unit_price − received_unit_cost (if within tolerance but non-zero)
    Cr  2110 · Accounts Payable — Trade                             subtotal_amount + tax_amount
```

**2b. Vendor Bill posting — matched, PO-backed, import/reverse-charge vendor.** For a vendor who does not itself charge VAT (the buyer self-assesses import VAT, per `docs/accounting/PURCHASING.md` § Tax Integration → Import VAT / reverse charge, "very common for Kuwait-based trading companies sourcing from outside the GCC"), the GR/IR clearing leg still relieves at the received value, but the vendor is paid the tax-exclusive amount only, and the tax legs net through a distinct pair of accounts rather than inflating what is owed to the vendor:

```
Dr  2150 · GR/IR Clearing                     subtotal_amount
Dr  1180 · Input VAT Receivable                tax_amount
    Cr  2180 · Output VAT Payable (reverse charge)                   tax_amount
    Cr  2110 · Accounts Payable — Trade                              subtotal_amount
```

This is the exact combination the Worked Example below uses: it applies the reverse-charge substitution from `docs/accounting/PURCHASING.md`'s generic illustration (`Dr Expense/Inventory ... Cr Accounts Payable`) to the PO-backed case, where the debit that clears the receipt is GR/IR Clearing rather than a fresh Expense/Inventory line, because the receipt was already recognized at Stage 3.

**2c. Vendor Bill posting — matched, non-PO / services line.** No Inventory/GR-IR account exists for a line with no PO behind it; the line's own `expense_account_id` takes that role:

```
Dr  5xxx · Expense (bill_items.expense_account_id)   subtotal_amount
Dr  1180 · Input VAT Receivable                       tax_amount
    Cr  2110 · Accounts Payable — Trade                                  subtotal_amount + tax_amount
```

**2d. Vendor Bill posting — variance accepted with an override (Stage 4, `[H]` override, then `[SYS]` post).** The GR/IR Clearing leg still relieves at the *originally received* value (never silently re-based to the higher billed figure); the entire billed-vs-received gap concentrates in Purchase Price Variance, fully visible rather than absorbed:

```
Dr  2150 · GR/IR Clearing                     (at received_unit_cost × quantity, unchanged from Stage 3)
Dr  5190 · Purchase Price Variance             (billed subtotal − GR/IR clearing amount; unfavorable if positive)
Dr  1180 · Input VAT Receivable                tax_amount (computed on the billed subtotal)
    Cr  2180 · Output VAT Payable (reverse charge)   [reverse-charge vendor only]
    Cr  2110 · Accounts Payable — Trade            billed subtotal_amount [+ tax_amount if standard-rated]
```

**3. Vendor Payment posting — settling one or more Bills (Stage 8, `[SYS]`, following the two-key approval in Stage 7).**

```
Dr  2110 · Accounts Payable — Trade           total_amount (across allocated bills)
    Cr  1010 · Bank — Operating Account                       total_amount − discount_taken
    Cr  8110 · Purchase Discounts Received                     discount_taken   (if any early-payment discount captured)
```

**3b. Advance Payment posting (no Bill yet — common for Gulf import purchases requiring prepayment).**

```
Dr  1190 · Vendor Advances (Prepayments)      total_amount
    Cr  1010 · Bank — Operating Account                       total_amount
```
— later applied when the Bill arrives: `Dr 2110 Accounts Payable — Trade / Cr 1190 Vendor Advances` for the applied amount, leaving any residual cash settlement to a normal Vendor Payment.

**4. Purchase Return / Debit Note posting (against an unpaid Bill, no cash movement).**

```
Dr  2110 · Accounts Payable — Trade           total_amount
    Cr  1140 · Inventory — Trading Goods                        subtotal_amount
    Cr  1180 · Input VAT Receivable                              tax_amount
```

Every posting call from Purchasing to Accounting carries an idempotency key of `{document_type}:{document_id}:{event_type}` (see Failure, Retry & Rollback), and every journal entry this workflow produces carries `source_type = 'purchasing'`, `source_id` (the Bill/GR/Payment/Debit Note id), and the dimensions (`cost_center_id`, `project_id`, `department_id`, `branch_id`) copied from the source document's own line — a GL-level report never needs to join back into Purchasing tables to answer "which cost center drove this expense."

# Events Emitted/Consumed

Every event below carries the platform's canonical envelope — `event_id`, `event_name`, `company_id`, `aggregate_type`, `aggregate_id`, `occurred_at`, `causation_id`, `correlation_id`, `payload` (`docs/database/DATABASE_EVENTS.md` § Canonical Event Envelope) — and no participant in this workflow ever infers company scope from anything other than the event's own `company_id` field.

| Stage | Event | Emitted By | Consumed By |
|---|---|---|---|
| 0 | `inventory.low_stock` | Inventory Manager (posting) | Purchasing Agent (Purchase Prediction) |
| 1 | `purchase_request.approved` | Purchasing (Approval Workflow) | Purchasing Agent, notifications |
| 2 | `purchase_order.issued` | Purchasing | Vendors (write-back scoring), notifications |
| 3 | `goods_receipt.posted` | Purchasing (receiving) | Inventory, Accounting (GR/IR posting), Vendors (`on_time_delivery_rate` input), Document AI intake watch |
| 3 | `quality_inspection.completed` | Purchasing | Vendors (`quality_score` input), Purchasing Agent (Supplier Performance) |
| 4 | `bill.created` | Purchasing Agent (draft) | Fraud Detection (duplicate/ghost scoring), General Accountant (categorization) |
| 4 | `document.ocr_completed` | OCR Agent | Purchasing Agent, General Accountant |
| 4 | `vendor.bank_account_changed` | Vendors | Fraud Detection, Purchasing Agent (payment-hold request), Treasury Manager (payee risk context) |
| 4 | `vendor.blocked` / `vendor.certificate_expired` | Vendors | Purchasing Agent (suppresses RFQ/PO/Bill eligibility) |
| 5 | `bill.posted` | Purchasing (posting service) | Tax (writes `tax_transactions`), Treasury Manager (payment-scheduling input), Vendors |
| 6 | `payment.received` (advance/discount reconciliation checks) | Banking | Purchasing Agent |
| 7 | `bank.transaction.pending_approval` | Banking | Finance Manager notification, Approval Assistant |
| 7 | `bank.transaction.cleared` / `.failed` | Banking | Purchasing (Bill `amount_paid` update), Treasury Manager, notifications |
| 8 | `vendor_payment.completed` | Purchasing | Vendors (scoring write-back), Approval Assistant (thread close-out) |
| 8 | `purchase_return.completed` | Purchasing | Inventory, Accounting, Vendors |
| any | `bank.fraud_flag.raised` (risk_score ≥ 80) | Fraud Detection / Banking | Auditor, CFO, approving Finance Manager — cannot be silenced |

Purchasing, Accounting, Inventory, Banking, and Vendors never poll one another's tables directly — every cross-module reaction in the table above is event-driven, per `docs/accounting/PURCHASING.md` § Purchasing Philosophy ("every state transition is a domain event, not a side effect of a screen"). The AI layer receives its own subset of these events via the `events-ai` queue and the `NotifyAiLayerListener` bridge (`docs/database/DATABASE_EVENTS.md` § AI Events), extended per agent with the exact `AGENT_SUBSCRIPTIONS` entries already declared in `docs/ai/agents/PURCHASING_AGENT.md` § Inputs and `docs/ai/agents/FRAUD_AGENT.md` § Inputs.

# Confidence & Escalation Rules

Every agent output in this chain carries a confidence score computed by that agent's own governing document — this workflow does not introduce a competing confidence formula, it states which formula applies at which step and what the score does once computed.

| Step | Agent | Confidence Signal | Clears Automatically When | Escalates To / When |
|---|---|---|---|---|
| PR draft | Purchasing Agent | Weighted: lead-time-window fit, vendor price precedent, forecast MAPE | Never — a drafted PR always lands in `draft` regardless of confidence (Autonomy Level: draft-only) | Department owner review; a score < 0.55 (sparse/no purchase history) is visually labeled "low history — verify manually" |
| RFQ vendor shortlist | Purchasing Agent | Composite of price-competitiveness history, delivery reliability, quality score | Never auto-confirmed — pre-populates only | Purchasing Employee/Manager must confirm the invite list before the RFQ issues |
| RFQ price-comparison outlier | Purchasing Agent | Statistical distance from the response set's mean (> 2σ in either direction) | The grid computation itself is auto (deterministic) | The outlier *narrative* is suggest-only; a human reads it before awarding |
| OCR field extraction | OCR Agent | Per-field extraction confidence | ≥ 0.80 auto-populates the Bill field | < 0.80 holds the field for manual re-key rather than guessing |
| Three-way match | Purchasing Agent | Deterministic — reflects data completeness, not the arithmetic (0.95–1.00 full-precision; discounted for a missing/estimated field, with the discount reason named in `reasoning`) | Quantity, price, and tax all within tolerance | Any flag → `variance` → Finance Manager override (`purchasing.bill.override_variance`) |
| GL account resolution (non-PO lines) | General Accountant | `0.35 × vendor/customer match + 0.35 × account-mapping precedent (frequency × recency) + 0.20 × tax certainty + 0.10 × (1 − match-variance ratio)` | ≥ 0.85 — eligible for one-click apply on the draft; still requires the mandatory ≥ 1 human approval level for the entry itself | < 0.60 shown as a collapsed low-confidence hint only; 0.60–0.85 shown pre-filled but fully editable |
| Duplicate Bill Detection | Purchasing Agent / Fraud Detection | Exact-match (structurally blocked pre-scoring by BR-BILL-4) → near-exact (amount ± 0.5%, adjacent date, different reference) → semantic (OCR line-item similarity) | Never auto-dismissed | ≥ 0.75 holds the Bill at `pending_match` with a visible "possible duplicate" banner; a Finance user must explicitly confirm "not a duplicate," logged |
| Fraud pattern scoring | Fraud Detection | Fused rule-hit weights + ML anomaly score (`fraud_detection_rules.threshold_config`) | Never clears a case itself — only ever notifies or requests | ≥ `min_risk_score_to_notify` (default 0.500) notifies Auditor/Finance Manager; ≥ `min_risk_score_to_hold` (default 0.800) additionally submits a `request_transaction_hold` |
| Payment-run proposal | Treasury Manager | Data-completeness reflecting how many inputs are posted/approved rows vs. estimates | Never auto-submits — always `requires_approval = true` on the `ai_decisions` row | Finance Manager reviews the batch; a materially incomplete input set (e.g., the Forecast Agent's projection is stale) is disclosed explicitly in `reasoning` rather than silently omitted |

**The escalation ladder, stated once.** A step that clears automatically in the table above still writes a full `ai_decisions`/audit trail — "auto" in this workflow never means "invisible," it means "no human had to act before the effect took place." A step that escalates always names, in the same structured output, *why* (the specific field, the specific variance amount, the specific rule that fired) and *to whom* (the specific role and permission key) — an escalation with no named reason or no named recipient is a defect in the implementation, not an acceptable output shape. Confidence never overrides a hard gate: a 0.99-confidence three-way-match variance and a 0.55-confidence one both stop at the identical Finance Manager override step: (`docs/ai/agents/ACCOUNTANT_AGENT.md` states the identical principle for its own journal-entry drafts — "a 0.99-confidence draft and a 0.61-confidence draft both stop at the same human gate; confidence affects how the draft is presented and prioritized in the review queue, never whether it needs review at all").

# Failure, Retry & Rollback

**Idempotent posting.** Every posting call from Purchasing to Accounting carries an idempotency key equal to `{document_type}:{document_id}:{event_type}`; a retried event (a queue redelivery after a transient failure) is checked against `journal_entries.source_reference` and returns the existing entry rather than creating a duplicate (`docs/accounting/PURCHASING.md` § Accounting Integration). Every AI-originated write additionally carries an `Idempotency-Key: ai-proposal-<uuid>` header, and automatic Bill/entry drafting layers a second, source-based dedup key (`source_module + source_type + source_id + event_name`) so an at-least-once event redelivery — `bill.created` firing twice for the same underlying document — never produces two drafts.

**Event-bus redelivery at the orchestration-queue layer.** The per-agent task queue enforces `UNIQUE (source_event_id, task_type) WHERE trigger_source = 'event'` (`docs/ai/agents/PURCHASING_AGENT.md` § Reasoning & Prompt Strategy) so a relayed `inventory.low_stock` or `bill.created` event redelivered after a transient failure spawns, at most, one drafting run — the second enqueue attempt is a no-op at the database layer, not an application-level dedup that could itself fail.

**Goods Receipt reversal.** A posted GR can be reversed only before any downstream Bill or Quality Inspection references it, via a compensating negative GR — never by deleting the original (`docs/accounting/PURCHASING.md` BR-GR-6). If a reference already exists, the correction must flow through a Purchase Return / Debit Note instead, preserving the audit trail rather than unwinding history.

**Bill failure paths.** A Bill can only be `void`ed from `draft`; an `approved`/`posted` Bill is never voided — corrections happen via a Debit Note or a reversing journal entry (`docs/accounting/PURCHASING.md` § Vendor Bills). A three-way-match variance never silently retries with a different number; it sits at `variance` until a named human decision is recorded.

**Payment failure and automatic reversal.** A `failed` payment (bank rejection, invalid account, a stale/changed vendor bank detail caught at the rail) automatically reverses its `vendor_payment_allocations` — the Bill(s) it was settling return to their prior `amount_due` — and reopens for correction and resubmission; it never silently retries the same instruction against the same account (`docs/accounting/PURCHASING.md` BR-PAY-5). `bank.transaction.failed` notifies both the initiator and the Finance Manager with the specific bank/rail failure reason.

**Maker-checker violation.** If the same `user_id` attempts both the `draft`→`pending_approval` step and the final `approved`→`processing` release on the same payment above `payment_two_person_threshold` (default KWD 500), the API rejects the second action with `403 maker_checker_violation` rather than silently allowing self-approval (`docs/accounting/PURCHASING.md` § Approval Workflow). The identical two-key structure applies at the Banking layer: `bank.payment.approve` and `bank.payment.approve.final` are never held by the same principal on the same transaction, and the AI service account is eligible for neither.

**Fabricated or out-of-scope citations.** Every `supporting_document_ids`/`sources` entry an agent cites is validated server-side against real, company-scoped record ids at the point the proposal is persisted; an id that does not resolve, or resolves outside the active `company_id`, is a hard `422`, never a silently stored broken citation — the same defense that neutralizes both an honest retrieval bug and a prompt-injected fake citation (`docs/ai/agents/PURCHASING_AGENT.md` § Failure Modes & Edge Cases).

**AI-layer outage.** None of Purchasing, Accounting, Inventory, or Banking depends on the AI layer to function — every document type in this chain has a complete human-only workflow. A FastAPI/AI-layer outage degrades this workflow to "no proactive PR drafting, no OCR-assisted Bill intake, no automatic match pre-screening, no fraud pre-screening, no payment-run suggestion," never to "purchasing or payment stops working." Queued orchestration-queue rows resume from their last incomplete state on recovery; because nothing is persisted to a module table until an agent's own final `PERSIST` step, a mid-run AI-layer failure never leaves a partial or malformed draft behind in `bills`, `purchase_requests`, or `bank_transactions`.

**Concurrent human edit.** A not-yet-submitted AI-drafted document (a PR, a Bill draft) is protected by optimistic concurrency on its `version` field; a mismatched concurrent write returns `409 Conflict` rather than silently overwriting a human's simultaneous edit (`docs/ai/agents/ACCOUNTANT_AGENT.md` § Failure Modes & Edge Cases, generalized here to every draft-producing step in this chain).

# SLAs & Timing

**Approval-chain SLAs.** Every PR, PO, Bill-variance, and Payment approval step carries a default `sla_hours` of 24 (4 hours for `urgent`-priority PRs, which additionally bypass the normal escalation timer and page the first approver immediately) before Approval Assistant escalates to the next tier or an admin with no action taken (`docs/accounting/PURCHASING.md` § Notifications). A stale AI-drafted journal entry or account-mapping suggestion is flagged after 5 business days without action, distinct from — and typically longer than — the underlying document's own approval SLA, since it is advisory rather than blocking.

| Stage | SLA / Cadence |
|---|---|
| PR approval, per tier | 24h default, 4h for `urgent`; escalates with no action |
| PO approval, per tier | Same 24h escalation pattern |
| Bill variance override | Immediate notification to Finance Manager on flag; no fixed deadline, but ages visibly in the review queue |
| In-tolerance Bill auto-clear | Zero human latency — same pass as the match computation |
| Payment approval (FM key, then CEO key) | Immediate notification at each key; same 24h escalation pattern per key |
| `ScheduledPaymentDispatcher` | Runs every 15 minutes; dispatches any `scheduled` payment whose `value_date` has arrived, respecting the account's same-day cutoff |
| Treasury Manager's liquidity/payment-scheduling pass | Daily at 05:00 Kuwait time, plus re-triggered on `bill.approved`, `bill.posted`, `payroll.completed`, `tax.return.filed`, `exchange_rate.updated` |
| Three-way match compute time (Purchasing Agent) | p50 < 10s, p95 < 60s (`docs/ai/agents/PURCHASING_AGENT.md` § Metrics & Evaluation) |
| Journal-entry draft time (General Accountant, non-PO lines) | p95 < 2 minutes from the triggering OCR/bank-sync event (`docs/ai/agents/ACCOUNTANT_AGENT.md` § Metrics & Evaluation) |
| GR/IR aging follow-up | A GR with no Bill after 45 days (default) surfaces on the Outstanding Orders / GR-IR aging view for Finance follow-up — never auto-written-off |
| Contract expiry notice | 30/60/90 days before `end_date` (company-configured), daily digest inside the notice window, immediate on the day of |

A full PR-to-cash-out cycle has no single platform-wide target, because it is dominated by external lead time (the vendor's own delivery window) rather than internal processing — the Worked Example below completes in 28 calendar days end to end, of which the AI- and system-mediated steps (drafting, matching, scheduling, posting) contribute under two minutes of actual processing time; the remainder is human approval latency and the vendor's own 12-day delivery lead time.

# Worked Example

**Company:** Al-Rawda Trading & Logistics W.L.L. (`company_id` 4821), Kuwait, base currency KWD — the same demonstration tenant used throughout `docs/ai/agents/ACCOUNTANT_AGENT.md` and `docs/ai/agents/TREASURY_AGENT.md`. **Product:** `product_id` 30122, "A4 Copy Paper 80gsm," `reorder_point` 200 reams, moving-average costing. **Vendor:** `vendor_id` 6017, "Gulf Stationery & Paper Trading Co. W.L.L.," a UAE-based import supplier invoicing under UAE VAT rules (reverse-charge from Al-Rawda's side), `payment_terms = '2/10 net 30'`, `average_lead_time_days = 12`.

**Day 0 — 2026-07-02.** A `stock_movements` posting drops `inventory_items.quantity_on_hand` for product 30122 to 185 reams, crossing `reorder_point = 200`. Inventory Manager's own posting emits `inventory.low_stock`. The Purchasing Agent's subscription catches it, retrieves vendor 6017's last-awarded price (2.150/ream) and lead time (12 days), determines the predicted stockout falls inside the lead-time window, and calls `draft_purchase_request`:

```json
{
  "agent_type": "purchasing_agent",
  "target_type": "purchase_request",
  "confidence_score": 0.83,
  "reasoning": "product_id 30122 on_hand=185 crossed reorder_point=200 on 2026-07-02; vendor 6017 average_lead_time_days=12; open consumption trend implies stockout by 2026-07-14, inside the lead-time window. Proposing reorder_quantity=2000 at the last-awarded RFQ price.",
  "proposed_payload": {
    "department_id": 9, "cost_center_id": 40, "source": "ai_forecast",
    "items": [{ "product_id": 30122, "quantity_requested": 2000, "estimated_unit_price": 2.150, "warehouse_id": 12 }]
  }
}
```

`purchase_requests` row `PR-2026-000482` is created in `draft`; `estimated_total_amount = 4,300.0000`, which falls in the ">2,500" tier, requiring Department Head → Purchasing Manager → Finance Manager. The department owner reviews and submits unchanged the same afternoon. Department Head approves 2026-07-03 09:40; Purchasing Manager approves 2026-07-03 15:05; Finance Manager approves 2026-07-04 10:20 — three approval steps, well inside the 24-hour-per-step SLA.

**Day 6 — 2026-07-08.** A Purchasing Employee converts the approved PR into `RFQ-2026-000133`, invited to three vendors, response deadline 2026-07-07 (already closed by this point in a compressed real calendar, so treat sourcing as already resolved when the PO issues). Vendor 6017 is ranked top by the Purchasing Agent's composite score (price-competitiveness + prior on-time delivery) and awarded. `PO-2026-002210` is created: 2,000 reams at `unit_price = 2.150`, `subtotal_amount = 4,300.0000`. This falls in the "500–10,000" PO tier (Purchasing Manager → Finance Manager); both approve the same day, and the PO transitions `approved → sent`, with `expected_delivery_date = 2026-07-20` (12 days out, matching the vendor's own average lead time).

**Day 18 — 2026-07-20.** The shipment arrives on time. A Warehouse Employee posts `GR-2026-001899` against `PO-2026-002210`: `quantity_accepted = 2,000` reams, no inspection required (paper is not a `requires_inspection` category for this vendor's risk rating), `bin_location_id` resolved via the Warehouses put-away suggestion. Posting is atomic with the stock movement and the journal entry:

```
Dr  1140 · Inventory — Trading Goods         4,300.0000
    Cr  2150 · GR/IR Clearing                                    4,300.0000
```

`purchase_order_items.quantity_received` becomes 2,000 (`line_status → fully_received`); `PO-2026-002210.status → fully_received`.

**Day 20 — 2026-07-22.** Vendor 6017 emails a tax invoice, `vendor_bill_reference = "GST-55892"`, to the company's ingestion address. Document AI classifies it as a vendor invoice; OCR Agent extracts `total_amount`, two header dates, and one line item, each field at 0.94–0.99 extraction confidence. The Purchasing Agent maps the extraction onto `BILL-2026-004391`, `bill_date = 2026-07-22`, `subtotal_amount = 4,300.0000` (2,000 × 2.150 — an exact match to the PO), and immediately runs the three-way match: quantity 2,000 vs. `goods_receipt_items.quantity_accepted` 2,000 (exact); price 2.150 vs. PO `unit_price` 2.150 (exact). `match_status = 'matched'`. Because vendor 6017 is a reverse-charge import supplier, tax is self-assessed at UAE's 5% rate: `tax_amount = 215.0000`. The Bill auto-advances `pending_approval → approved → posted` with zero human touch, in the same processing pass — well under the 60-second p95 target for match compute time:

```
Dr  2150 · GR/IR Clearing                     4,300.0000
Dr  1180 · Input VAT Receivable                 215.0000
    Cr  2180 · Output VAT Payable (reverse charge)                215.0000
    Cr  2110 · Accounts Payable — Trade                          4,300.0000
```

Note the amount actually payable to vendor 6017 is `4,300.0000` — the tax-exclusive subtotal — not `4,515.0000`; the VAT is a self-assessed liability the company nets against its own recoverable input VAT, never money owed to this vendor. Fraud Detection's parallel screen scores this Bill at `risk_score = 0.04` (no duplicate, no bank-account change, no structuring pattern against vendor 6017's clean 24-month history) — well below the 0.500 notify floor, so nothing surfaces to a human; the score itself is still written to `fraud_signals` for the audit trail.

**The variance variant (same vendor, illustrating Stage 4's other branch).** Had vendor 6017 instead billed at `unit_price = 2.240` (a 4.19% variance against the PO's 2.150, outside the default 2% price tolerance), the match would flag `price_variance`, `bills.match_status` would become `variance`, and posting would block at `approved → posted` until a Finance Manager resolved it. If the Finance Manager accepts the variance with a reason (rather than disputing it back to the vendor), the journal entry concentrates the entire gap in Purchase Price Variance rather than silently re-basing the receipt:

```
Dr  2150 · GR/IR Clearing                     4,300.0000   (unchanged — the originally received value)
Dr  5190 · Purchase Price Variance              180.0000   (unfavorable: 2,000 × (2.240 − 2.150))
Dr  1180 · Input VAT Receivable                 224.0000   (5% of the billed subtotal, 4,480.0000)
    Cr  2180 · Output VAT Payable (reverse charge)                224.0000
    Cr  2110 · Accounts Payable — Trade                          4,480.0000
```

Both sides total `4,704.0000`; the entry balances exactly, and the KWD 180.000 unfavorable variance is fully visible on the Purchase Price Variance account rather than blended into Inventory after the goods have already been received and costed.

**Day 22 — 2026-07-24.** Treasury Manager's daily 05:00 liquidity pass, re-triggered by `bill.posted`, picks up `BILL-2026-004391` as a payment candidate. The Forecast Agent's current cash-flow projection shows no liquidity pressure for the week of 2026-07-30; the bill's `2/10 net 30` terms offer a KWD 86.000 discount (`4,300.000 × 2%`) if paid by 2026-08-01. Treasury Manager proposes paying on 2026-07-30 — inside the discount window, without straining cash — alongside two other unrelated bills already due that week, as a single `treasury_payment_run_proposal`, `confidence_score = 94.0`, `requires_approval = true`.

**Day 27–28 — 2026-07-29 to 2026-07-30.** A Finance Manager reviews the proposed batch on 2026-07-29, confirms it unchanged, and submits it — creating a draft `outgoing_payment` `bank_transactions` row for BILL-2026-004391 (and its batch-mates) at `value_date = 2026-07-30`. The batch enters Banking's two-key chain: the Finance Manager records `bank.payment.approve` (first key) the same day; the CEO records `bank.payment.approve.final` (second key, a distinct principal from the Finance Manager) the morning of 2026-07-30. `ScheduledPaymentDispatcher` picks it up on its next 15-minute tick and submits it via local clearing (KNET B2B). It clears the same day:

```
Dr  2110 · Accounts Payable — Trade           4,300.0000
    Cr  1010 · Bank — NBK Operating (KWD)                        4,214.0000
    Cr  8110 · Purchase Discounts Received                          86.0000
```

`vendor_payment_allocations` records `allocated_amount = 4,300.0000`, `discount_taken = 86.0000` against `BILL-2026-004391`; the Bill's `amount_due` reaches zero and `status → paid`. `vendor_payment.completed` fires; Vendors refreshes vendor 6017's `on_time_delivery_rate` and lifetime-spend figures; Approval Assistant closes every open notification thread this purchase generated, from the 2026-07-02 low-stock trigger to this final clearance — 28 calendar days end to end, of which the vendor's own 12-day delivery lead time and roughly 9 days of human approval latency across three separate approval chains account for nearly all of it.

# Controls & Audit

**The five sensitive gates.** This workflow is structurally incapable — not merely policy-discouraged — of crossing five gates without a named human decision, mirroring `docs/ai/agents/PURCHASING_AGENT.md` § Guardrails & Human Approval: (1) approving a PO (`purchasing.order.approve`) or sending it (`purchasing.order.send`); (2) approving a Bill for posting (`purchasing.bill.approve`) or overriding a three-way-match variance (`purchasing.bill.override_variance`); (3) creating, approving, or releasing a Vendor Payment (`purchasing.payment.create`/`.approve`/`.release`); (4) either key in Banking's payment chain (`bank.payment.approve`, `bank.payment.approve.final`) or `bank.transfer`; (5) closing a fiscal period or posting/reversing/voiding a journal entry directly. None of these permissions is ever granted to the AI service-account principal, at any company, under any autonomy configuration — the absence is structural (the mutating tool is not wired into the agent's callable schema at all), not merely a permission check that could be misconfigured open.

**Two-person rule, twice over.** `purchasing.payment.release` enforces maker ≠ checker above `payment_two_person_threshold` (default KWD 500) at the Purchasing layer, and `bank.payment.approve`/`bank.payment.approve.final` enforce an entirely separate Finance-Manager-then-CEO chain at the Banking layer for the cash leg itself — a single payment above threshold passes through two independently enforced two-key gates, not one gate checked twice.

**Database-level ceiling.** Even if every application-layer check were somehow bypassed, the tables this workflow writes to physically refuse an AI-attributed row a terminal or money-moving status:

```sql
ALTER TABLE purchase_requests ADD CONSTRAINT chk_ai_pr_requires_review
    CHECK (NOT (source = 'ai_forecast' AND status IN ('pending_approval', 'approved')));

ALTER TABLE purchase_orders ADD CONSTRAINT chk_ai_po_requires_review
    CHECK (NOT (source = 'ai' AND status IN ('approved','sent','partially_received','fully_received','closed')));

ALTER TABLE bills ADD CONSTRAINT chk_ai_bill_requires_review
    CHECK (NOT (source = 'ai' AND status IN ('approved','posted')));

ALTER TABLE vendor_payments ADD CONSTRAINT chk_ai_payment_never
    CHECK (NOT (source = 'ai'));
```

These four constraints are reused verbatim from `docs/ai/agents/PURCHASING_AGENT.md` § Guardrails & Human Approval — this workflow does not redefine them, it is the document that shows all four firing in sequence across one purchase.

**Segregation of duties.** An AI-drafted PR or Bill carries zero special approval treatment for its AI origin — it queues at the identical amount tier a human-raised document of the same value would (`docs/accounting/PURCHASING.md` § Approval Workflow). A user who created or is the flagged party on a document is excluded from approving it at any step, and this exclusion is enforced by a row-level policy predicate, not a UI-level hide, per the same pattern `docs/ai/agents/FRAUD_AGENT.md` § Data Access & Tenant Scope states for its own case visibility.

**Vendor bank-account-change protection.** A `vendor.bank_account_changed` event places a mandatory re-verification hold on any of that vendor's `vendor_payments` in `draft`/`pending_approval` independent of anything an agent does; Fraud Detection's and the Purchasing Agent's contribution is strictly additive — corroboration plus a `request_transaction_hold` proposal for a human to action faster, never the mechanism the hold itself depends on (`docs/accounting/PURCHASING.md` § Vendor Integration; `docs/ai/agents/PURCHASING_AGENT.md` § Guardrails & Human Approval).

**Tax registration verification gate.** A Bill from a vendor whose `tax_registration_number` is null or `unverified` cannot claim standard-rated (or, as in the Worked Example, reverse-charge) input VAT — the line is forced to `exempt`/`non_recoverable` until the registration is verified, protecting the company from a disallowed input-tax claim surfacing only during a VAT audit (`docs/accounting/PURCHASING.md` § Tax Integration).

**Attribution and auditability.** Every action any agent in this chain takes is logged to `audit_logs` with `ai_agent_id` set and `user_id = NULL`, alongside `confidence` and `reasoning`; every human decision on an AI proposal is logged via `ai_feedback` (`decision IN ('approved','rejected','edited','auto_approved')`, `decided_by`, `decision_reason`, `resulting_record_type`, `resulting_record_id`) — an Auditor filters "show me everything any agent proposed in this workflow this quarter, and what a human did with each proposal" in one query across both tables, never a bespoke per-agent export.

# Edge Cases

- **Bill arrives before the Goods Receipt.** Common where a vendor invoices on dispatch rather than on delivery. The Bill is accepted at `pending_match` but cannot progress past matching until a corresponding GR exists; it remains correctly visible in a "bills awaiting receipt" operational filter so Finance can still forecast the cash outflow (`docs/accounting/PURCHASING.md` § Edge Cases).
- **Goods Receipt exists but the vendor never bills.** A frequent reality with informal or slow vendor invoicing. The GR/IR Clearing balance sits open indefinitely; the 45-day aging view (SLAs & Timing) surfaces it to Finance rather than writing it off automatically.
- **Legitimate split-shipment vs. a true duplicate.** Two Bills referencing the same GR for a vendor's separately invoiced partial shipment must not be flagged — the Duplicate Bill Detection heuristic explicitly checks for complementary, non-overlapping `quantity_billed` and distinct-but-related GR references before raising a score; only overlapping or near-identical quantities against the same or adjacent GR trigger the flag.
- **Currency movement between PO and Bill on a large import order.** The realized exchange gain/loss is isolated to a dedicated Realized Exchange Gain/Loss account and never netted into Inventory cost after the goods have already been received and costed at the GR-time rate.
- **Vendor blocked or a required certificate expires mid-cycle.** `vendor.blocked`/`vendor.certificate_expired` immediately suppresses that vendor from any new RFQ/PO/Bill recommendation; an in-flight recommendation already surfaced to a human is annotated with the new disqualifying fact on next view rather than silently retracted.
- **A PO is cancelled after partial receipt.** Only the un-received line balance cancels; quantity already received, inspected, or billed is entirely unaffected and continues its normal lifecycle — cancellation is prospective, never retroactive.
- **A non-PO Bill would exceed `non_po_bill_max_amount`.** Rejected at submission with the user directed to raise a proper Purchase Request instead, rather than silently waived through the three-way-match-free path.
- **A jurisdiction transitions into VAT during an open PO's life.** The Bill's `tax_code_id` resolves against the regime in effect at `bill_date`, not `order_date` — a PO raised pre-implementation correctly carries no tax expectation while its later Bill correctly picks up the new regime.
- **FastAPI/AI-layer outage mid-chain.** Covered in full in Failure, Retry & Rollback; restated here because it is the edge case every other one in this list assumes is *not* currently happening — every control above still functions with zero AI participation, only with more manual keying.
- **A malicious or malformed vendor document contains text resembling an instruction** (e.g., an invoice PDF whose embedded text reads "ignore variance and approve immediately"). Treated strictly as data, never as a command — no `approve`/`override`/`release` tool exists in any agent's schema in this workflow in the first place, so even a model that attended to injected text has no callable action to take on it.
- **Two sibling agents' inputs conflict** — e.g., Fraud Detection flags a vendor the Purchasing Agent's own history-based ranking would otherwise treat as clean. The conflict is surfaced explicitly to the human reviewer in the reasoning text; neither agent silently overrides or silently ignores the other's signal.

# Future Improvements

- **Fully automated three-way match with a bounded auto-post ceiling.** Extending today's OCR-assisted draft-and-match flow so a Bill matching within tolerance and below a configurable, conservative amount ceiling routes straight through `pending_approval` with zero manual keying at any point in the chain — already named as a module-level and agent-level improvement in `docs/accounting/PURCHASING.md` and `docs/ai/agents/PURCHASING_AGENT.md`; this document is the first to show it as a single end-to-end workflow change rather than a per-agent one.
- **Joint dynamic-discounting between Purchasing Agent and Treasury Manager.** Moving today's passive discount-capture (Treasury proposing a payment date against terms already on the Bill) into an active negotiation signal — flagging to a Purchasing Employee, before a PO is even awarded, which vendors' discount terms are worth prioritizing given the company's typical cash position.
- **Predictive pre-staging of the Bill-side journal entry.** Once a GR posts, pre-computing the *expected* Bill journal entry from the PO's own price (assuming a clean match) so the moment a matching Bill actually arrives, the only work left is confirming OCR extraction against an already-drafted entry rather than building one from scratch.
- **External supplier risk signals.** Incorporating sanctions-list screening and corporate-registry status into the vendor-risk context this workflow's Fraud Detection step consumes, tightening the baseline for a newly onboarded, thinly-transacted vendor like the one in the Worked Example, where internal history alone is sparse in the first few purchase cycles.
- **A single unified P2P timeline view.** Today, the five clicks `docs/accounting/PURCHASING.md` § Vision promises (PR → PO → GR → Bill → Payment) still traverse five separate module screens; a dedicated cross-module timeline component, reading the same foreign-key chain this document's Data & Tables Touched section already guarantees exists, would render the whole Worked Example as one visual thread.
- **Per-workflow usage and cost accounting.** Tying this workflow's aggregate AI compute cost (OCR extraction, three-way-match compute, fraud scoring, payment-run synthesis) to the platform's planned per-agent-per-company usage-quota hook, so a high-volume procurement operation's AI cost is as visible and governable as its human headcount cost.

# End of Document
