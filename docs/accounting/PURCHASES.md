# Purchases (Procure-to-Pay) — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Purchases
---

# Purpose

The Purchases module implements the full procure-to-pay (P2P) cycle for a company: requesting and
ordering goods/services from vendors, receiving them into inventory, recording the vendor's invoice
(bill), paying the vendor, and handling returns/adjustments (debit notes). It is the mirror module to
Sales (accounts-payable side, as Sales is accounts-receivable). It owns `purchase_orders`, `bills`,
`bill_items`, `goods_receipts`, `debit_notes`, and `vendor_payments`. It reads `vendors`/`vendor_contacts`
(owned by the Vendors doc), `products`/`units_of_measure` (owned by Products), and `warehouses`/
`inventory_items` (owned by Inventory), and it writes financial events into Accounting via
`journal_entries`/`journal_lines` — it never inserts into the GL tables directly from application code
outside the shared `AccountingPostingService`.

# Business Goals

- Give Purchasing staff a controlled way to request and order goods/services with budget and approval
  gates, and give Finance a guarantee that nothing is paid to a vendor without matching evidence.
- Enforce three-way matching (Purchase Order vs Goods Receipt vs Bill) before a bill can be approved for
  payment, catching price variance, quantity variance, and duplicate/fraudulent invoices early.
- Keep inventory valuation and Cost of Goods Sold accurate by posting stock-in at the Goods Receipt step
  (not at the bill step), so warehouse stock and its GL value move together.
- Give Treasury a forward view of AP (open bills, due dates, early-payment discounts) to schedule cash
  outflows optimally, including partial payments, prepayments/advances, and withholding tax where the
  jurisdiction requires it.
- Support multi-currency purchasing (a vendor invoiced in USD while the company's base currency is KWD)
  with correct FX gain/loss recognition at payment time.
- Every AP-affecting document produces a balanced journal entry automatically — no manual GL entry is
  ever required for a normal purchase transaction.

# Definitions

| Term | Meaning |
|---|---|
| Purchase Requisition (PR) | Internal request to buy something; not sent to a vendor; optional pre-step, may be embedded as `purchase_orders.status = 'draft'` with `is_requisition = true` or tracked as a separate lightweight table (out of scope here — assume PR collapses into a draft PO in v1). |
| Purchase Order (PO) | A committed, vendor-facing order for goods/services at agreed price and quantity. |
| Goods Receipt (GR) | Physical/logical confirmation that ordered goods arrived at a warehouse; increases stock on hand and posts Inventory value; can be partial. |
| Bill (Purchase Invoice) | The vendor's invoice claiming payment is owed; the AP-recognition event. |
| Three-Way Match | Automated comparison of PO, GR, and Bill quantities/prices before a bill is approved for payment. |
| Debit Note | A document reducing what the company owes a vendor (return of goods, price correction, credit received) — the AP-side mirror of a Sales credit note. |
| Vendor Payment | Cash/bank outflow that settles one or more bills (or a prepayment made before any bill exists). |
| Prepayment/Advance | Payment made to a vendor before a bill is recorded; carried as a vendor-side asset/contra-liability until applied against a future bill. |
| Landed Cost | Additional costs (freight, customs, insurance) allocated into inventory value at Goods Receipt — referenced but detailed in the Inventory doc. |
| Tolerance | Configurable acceptable variance (%, amount) between PO/GR/Bill quantities and prices before a match is auto-approved. |
| WHT | Withholding tax — a portion of a vendor payment withheld and remitted to the tax authority instead of paid to the vendor. |

# Database Design

All tables include the standard columns (`id`, `company_id`, `branch_id`, `created_by`, `updated_by`,
`created_at`, `updated_at`, `deleted_at`) per platform convention; they are omitted below where already
implied by `-- standard columns` and only module-specific columns are spelled out in full.

```sql
-- ============================================================
-- ENUM TYPES
-- ============================================================
CREATE TYPE purchase_order_status AS ENUM (
    'draft', 'pending_approval', 'approved', 'sent', 'partially_received',
    'fully_received', 'closed', 'cancelled', 'rejected'
);

CREATE TYPE goods_receipt_status AS ENUM ('draft', 'posted', 'reversed');

CREATE TYPE bill_status AS ENUM (
    'draft', 'pending_match', 'matched', 'variance_hold', 'pending_approval',
    'approved', 'partially_paid', 'paid', 'overdue', 'void'
);

CREATE TYPE debit_note_status AS ENUM ('draft', 'approved', 'applied', 'void');

CREATE TYPE vendor_payment_status AS ENUM (
    'draft', 'pending_approval', 'approved', 'posted', 'reconciled', 'voided', 'failed'
);

CREATE TYPE vendor_payment_method AS ENUM (
    'bank_transfer', 'cheque', 'cash', 'card', 'knet', 'other'
);

CREATE TYPE match_result AS ENUM ('matched', 'price_variance', 'quantity_variance', 'no_po', 'no_receipt', 'over_tolerance');

-- ============================================================
-- PURCHASE_ORDERS
-- ============================================================
CREATE TABLE purchase_orders (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    warehouse_id        BIGINT NOT NULL REFERENCES warehouses(id),
    vendor_id           BIGINT NOT NULL REFERENCES vendors(id),
    po_number           VARCHAR(30) NOT NULL,               -- e.g. PO-2026-000123
    is_requisition       BOOLEAN NOT NULL DEFAULT false,      -- true = internal PR not yet sent to vendor
    status              purchase_order_status NOT NULL DEFAULT 'draft',
    order_date          DATE NOT NULL,
    expected_date       DATE NULL,
    currency_code       VARCHAR(3) NOT NULL,
    exchange_rate       NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount          NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,     -- transaction currency
    base_total_amount   NUMERIC(19,4) NOT NULL DEFAULT 0,     -- total_amount * exchange_rate
    received_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,     -- sum of GR value against this PO, base currency
    billed_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,     -- sum of bill amounts matched to this PO, base currency
    budget_id           BIGINT NULL REFERENCES budgets(id),   -- optional budget-control link
    terms               TEXT NULL,
    notes               TEXT NULL,
    approved_by         BIGINT NULL REFERENCES users(id),
    approved_at         TIMESTAMPTZ NULL,
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    CONSTRAINT uq_po_number UNIQUE (company_id, po_number),
    CONSTRAINT chk_po_totals CHECK (total_amount >= 0 AND subtotal_amount >= 0)
);
CREATE INDEX idx_po_company ON purchase_orders (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_po_vendor ON purchase_orders (vendor_id);
CREATE INDEX idx_po_status ON purchase_orders (company_id, status);

CREATE TABLE purchase_order_items (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    purchase_order_id   BIGINT NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    line_no             INT NOT NULL,
    product_id          BIGINT NULL REFERENCES products(id),   -- nullable for one-off/service lines
    description         VARCHAR(500) NOT NULL,
    uom_id              BIGINT NOT NULL REFERENCES units_of_measure(id),
    quantity_ordered    NUMERIC(18,4) NOT NULL,
    quantity_received   NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_billed     NUMERIC(18,4) NOT NULL DEFAULT 0,
    quantity_cancelled  NUMERIC(18,4) NOT NULL DEFAULT 0,
    unit_price          NUMERIC(19,4) NOT NULL,
    tax_rate_id         BIGINT NULL REFERENCES tax_rates(id),
    tax_amount          NUMERIC(19,4) NOT NULL DEFAULT 0,
    line_total          NUMERIC(19,4) NOT NULL,                -- quantity_ordered * unit_price + tax_amount
    expense_account_id  BIGINT NULL REFERENCES accounts(id),   -- used when product_id IS NULL (service/expense line)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_po_line UNIQUE (purchase_order_id, line_no),
    CONSTRAINT chk_poi_qty CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND quantity_billed >= 0)
);
CREATE INDEX idx_poi_po ON purchase_order_items (purchase_order_id);
CREATE INDEX idx_poi_product ON purchase_order_items (product_id);

-- ============================================================
-- GOODS_RECEIPTS
-- ============================================================
CREATE TABLE goods_receipts (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    warehouse_id        BIGINT NOT NULL REFERENCES warehouses(id),
    purchase_order_id   BIGINT NOT NULL REFERENCES purchase_orders(id),
    vendor_id           BIGINT NOT NULL REFERENCES vendors(id),
    gr_number           VARCHAR(30) NOT NULL,                 -- GR-2026-000456
    status              goods_receipt_status NOT NULL DEFAULT 'draft',
    receipt_date        DATE NOT NULL,
    vendor_delivery_ref VARCHAR(100) NULL,                    -- vendor's own delivery-note number
    total_value_base    NUMERIC(19,4) NOT NULL DEFAULT 0,      -- base-currency inventory/expense value posted
    journal_entry_id    BIGINT NULL REFERENCES journal_entries(id),
    posted_by           BIGINT NULL REFERENCES users(id),
    posted_at           TIMESTAMPTZ NULL,
    notes               TEXT NULL,
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    CONSTRAINT uq_gr_number UNIQUE (company_id, gr_number)
);
CREATE INDEX idx_gr_po ON goods_receipts (purchase_order_id);
CREATE INDEX idx_gr_company_status ON goods_receipts (company_id, status);

CREATE TABLE goods_receipt_items (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT NOT NULL REFERENCES companies(id),
    goods_receipt_id       BIGINT NOT NULL REFERENCES goods_receipts(id) ON DELETE CASCADE,
    purchase_order_item_id BIGINT NOT NULL REFERENCES purchase_order_items(id),
    product_id             BIGINT NULL REFERENCES products(id),
    quantity_received      NUMERIC(18,4) NOT NULL,
    unit_cost              NUMERIC(19,4) NOT NULL,             -- PO unit_price at time of receipt (pre-landed-cost)
    line_value_base        NUMERIC(19,4) NOT NULL,             -- quantity_received * unit_cost * exchange_rate
    warehouse_id           BIGINT NOT NULL REFERENCES warehouses(id),
    stock_movement_id      BIGINT NULL REFERENCES stock_movements(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_gri_qty CHECK (quantity_received > 0)
);
CREATE INDEX idx_gri_gr ON goods_receipt_items (goods_receipt_id);
CREATE INDEX idx_gri_poi ON goods_receipt_items (purchase_order_item_id);

-- ============================================================
-- BILLS + BILL_ITEMS
-- ============================================================
CREATE TABLE bills (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    vendor_id             BIGINT NOT NULL REFERENCES vendors(id),
    purchase_order_id     BIGINT NULL REFERENCES purchase_orders(id),   -- nullable: non-PO bills allowed (see edge cases)
    bill_number           VARCHAR(30) NOT NULL,                 -- BILL-2026-000789 (internal)
    vendor_invoice_number VARCHAR(100) NOT NULL,                -- vendor's own invoice #, used for duplicate detection
    status                bill_status NOT NULL DEFAULT 'draft',
    bill_date             DATE NOT NULL,
    due_date              DATE NOT NULL,
    currency_code         VARCHAR(3) NOT NULL,
    exchange_rate         NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount        NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount             NUMERIC(19,4) NOT NULL DEFAULT 0,
    withholding_tax_amount NUMERIC(19,4) NOT NULL DEFAULT 0,    -- computed at approval, deducted at payment
    total_amount           NUMERIC(19,4) NOT NULL DEFAULT 0,    -- transaction currency, gross
    base_total_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,    -- total_amount * exchange_rate at bill_date
    paid_amount            NUMERIC(19,4) NOT NULL DEFAULT 0,    -- base currency, cumulative
    balance_due            NUMERIC(19,4) NOT NULL DEFAULT 0,    -- base_total_amount - paid_amount - applied debit notes
    match_result           match_result NULL,
    match_variance_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    matched_by             BIGINT NULL REFERENCES users(id),
    matched_at             TIMESTAMPTZ NULL,
    journal_entry_id       BIGINT NULL REFERENCES journal_entries(id),
    approved_by            BIGINT NULL REFERENCES users(id),
    approved_at            TIMESTAMPTZ NULL,
    fiscal_period_id       BIGINT NOT NULL REFERENCES fiscal_periods(id),
    attachment_id          BIGINT NULL REFERENCES attachments(id),   -- scanned invoice image/PDF
    ocr_confidence         NUMERIC(5,2) NULL,                 -- 0-100, from Document AI extraction
    notes                  TEXT NULL,
    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL,
    CONSTRAINT uq_bill_number UNIQUE (company_id, bill_number),
    -- prevents the same vendor invoice number being entered twice for the same vendor (duplicate-invoice guard)
    CONSTRAINT uq_vendor_invoice UNIQUE (company_id, vendor_id, vendor_invoice_number),
    CONSTRAINT chk_bill_totals CHECK (total_amount >= 0 AND paid_amount >= 0)
);
CREATE INDEX idx_bills_company_status ON bills (company_id, status);
CREATE INDEX idx_bills_vendor ON bills (vendor_id);
CREATE INDEX idx_bills_po ON bills (purchase_order_id);
CREATE INDEX idx_bills_due_date ON bills (company_id, due_date) WHERE status IN ('approved','partially_paid','overdue');

CREATE TABLE bill_items (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT NOT NULL REFERENCES companies(id),
    bill_id                BIGINT NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
    line_no                INT NOT NULL,
    purchase_order_item_id BIGINT NULL REFERENCES purchase_order_items(id),
    goods_receipt_item_id  BIGINT NULL REFERENCES goods_receipt_items(id),
    product_id             BIGINT NULL REFERENCES products(id),
    description            VARCHAR(500) NOT NULL,
    quantity               NUMERIC(18,4) NOT NULL,
    unit_price              NUMERIC(19,4) NOT NULL,
    tax_rate_id             BIGINT NULL REFERENCES tax_rates(id),
    tax_amount              NUMERIC(19,4) NOT NULL DEFAULT 0,
    line_total              NUMERIC(19,4) NOT NULL,
    inventory_account_id    BIGINT NULL REFERENCES accounts(id),  -- resolved from product/category if product_id set
    expense_account_id      BIGINT NULL REFERENCES accounts(id),  -- resolved for service/expense lines
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_bill_line UNIQUE (bill_id, line_no),
    CONSTRAINT chk_bi_qty CHECK (quantity > 0)
);
CREATE INDEX idx_bi_bill ON bill_items (bill_id);
CREATE INDEX idx_bi_poi ON bill_items (purchase_order_item_id);
CREATE INDEX idx_bi_gri ON bill_items (goods_receipt_item_id);

-- ============================================================
-- DEBIT_NOTES  (AP-side mirror of Sales credit_notes)
-- ============================================================
CREATE TABLE debit_notes (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    vendor_id          BIGINT NOT NULL REFERENCES vendors(id),
    bill_id            BIGINT NULL REFERENCES bills(id),        -- nullable: can be raised against a vendor generally
    debit_note_number  VARCHAR(30) NOT NULL,                    -- DN-2026-000045
    status             debit_note_status NOT NULL DEFAULT 'draft',
    reason             VARCHAR(50) NOT NULL,                    -- 'return_to_vendor','price_correction','damaged_goods','other'
    note_date          DATE NOT NULL,
    currency_code      VARCHAR(3) NOT NULL,
    exchange_rate      NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount    NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
    base_total_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    applied_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,  -- how much has offset a bill / been refunded
    journal_entry_id   BIGINT NULL REFERENCES journal_entries(id),
    stock_movement_id  BIGINT NULL REFERENCES stock_movements(id),  -- when reason = return_to_vendor
    approved_by        BIGINT NULL REFERENCES users(id),
    approved_at        TIMESTAMPTZ NULL,
    notes              TEXT NULL,
    created_by         BIGINT NULL REFERENCES users(id),
    updated_by         BIGINT NULL REFERENCES users(id),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at         TIMESTAMPTZ NULL,
    CONSTRAINT uq_debit_note_number UNIQUE (company_id, debit_note_number)
);
CREATE INDEX idx_dn_vendor ON debit_notes (vendor_id);
CREATE INDEX idx_dn_bill ON debit_notes (bill_id);

CREATE TABLE debit_note_items (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    debit_note_id  BIGINT NOT NULL REFERENCES debit_notes(id) ON DELETE CASCADE,
    line_no        INT NOT NULL,
    bill_item_id   BIGINT NULL REFERENCES bill_items(id),
    product_id     BIGINT NULL REFERENCES products(id),
    description    VARCHAR(500) NOT NULL,
    quantity       NUMERIC(18,4) NOT NULL,
    unit_price     NUMERIC(19,4) NOT NULL,
    tax_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    line_total     NUMERIC(19,4) NOT NULL,
    CONSTRAINT uq_dn_line UNIQUE (debit_note_id, line_no)
);
CREATE INDEX idx_dni_dn ON debit_note_items (debit_note_id);

-- ============================================================
-- VENDOR_PAYMENTS
-- ============================================================
CREATE TABLE vendor_payments (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),
    vendor_id               BIGINT NOT NULL REFERENCES vendors(id),
    bank_account_id         BIGINT NOT NULL REFERENCES bank_accounts(id),
    payment_number          VARCHAR(30) NOT NULL,                -- VP-2026-000234
    status                  vendor_payment_status NOT NULL DEFAULT 'draft',
    payment_method          vendor_payment_method NOT NULL,
    payment_date            DATE NOT NULL,
    is_prepayment           BOOLEAN NOT NULL DEFAULT false,  -- true = advance, no bill(s) yet
    currency_code           VARCHAR(3) NOT NULL,
    exchange_rate           NUMERIC(18,6) NOT NULL DEFAULT 1,
    amount                  NUMERIC(19,4) NOT NULL,       -- transaction currency, gross of WHT
    withholding_tax_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
    net_paid_amount         NUMERIC(19,4) NOT NULL,     -- amount - withholding_tax_amount
    base_amount             NUMERIC(19,4) NOT NULL,     -- amount * exchange_rate
    fx_gain_loss_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,  -- realized FX at settlement
    reference               VARCHAR(100) NULL,          -- cheque #/transfer ref
    journal_entry_id        BIGINT NULL REFERENCES journal_entries(id),
    bank_transaction_id     BIGINT NULL REFERENCES bank_transactions(id),
    approved_by             BIGINT NULL REFERENCES users(id),
    approved_at             TIMESTAMPTZ NULL,
    notes                   TEXT NULL,
    created_by              BIGINT NULL REFERENCES users(id),
    updated_by              BIGINT NULL REFERENCES users(id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at              TIMESTAMPTZ NULL,
    CONSTRAINT uq_vendor_payment_number UNIQUE (company_id, payment_number),
    CONSTRAINT chk_vp_amount CHECK (amount > 0)
);
CREATE INDEX idx_vp_vendor ON vendor_payments (vendor_id);
CREATE INDEX idx_vp_status ON vendor_payments (company_id, status);
CREATE INDEX idx_vp_bank_account ON vendor_payments (bank_account_id);

-- Allocation of one payment across one or more bills (many-to-many, supports partial + combined payment)
CREATE TABLE vendor_payment_allocations (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    vendor_payment_id  BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
    bill_id            BIGINT NOT NULL REFERENCES bills(id),
    allocated_amount   NUMERIC(19,4) NOT NULL,   -- base currency
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_vpa_amount CHECK (allocated_amount > 0),
    CONSTRAINT uq_vpa_payment_bill UNIQUE (vendor_payment_id, bill_id)
);
CREATE INDEX idx_vpa_payment ON vendor_payment_allocations (vendor_payment_id);
CREATE INDEX idx_vpa_bill ON vendor_payment_allocations (bill_id);
```

Relationships summary: `purchase_orders 1—N purchase_order_items`; `purchase_order_items 1—N goods_receipt_items`
(partial receipts create multiple rows over time); `goods_receipts 1—N goods_receipt_items`; `bills 1—N
bill_items`, each `bill_items` row optionally links back to `purchase_order_items` and
`goods_receipt_items` for the three-way match; `debit_notes 1—N debit_note_items`, optionally linked to
`bill_items`; `vendor_payments N—M bills` through `vendor_payment_allocations`. `goods_receipt_items` and
`debit_notes` (return reason) link to `stock_movements` (owned by Inventory) to move physical/valued stock.

# Business Rules

1. **Document sequence.** The natural procurement path is Purchase Requisition/Order → Goods Receipt →
   Bill → Vendor Payment, with an optional Debit Note at any point after a Bill exists. A Bill MAY exist
   without a PO (see Edge Cases — non-PO bills, e.g. utilities), but any Bill created from a PO MUST NOT
   exceed the PO's remaining quantity/amount without an explicit over-billing override permission.
2. **Numbering.** Each document type has a company-scoped, gapless-per-status sequence generated server
   side at creation: `PO-{YYYY}-{seq}`, `GR-{YYYY}-{seq}`, `BILL-{YYYY}-{seq}`, `DN-{YYYY}-{seq}`,
   `VP-{YYYY}-{seq}`. Numbers are never reused, even if the document is later voided/cancelled.
3. **States are one-directional except explicit reversals.** `purchase_orders`: draft → pending_approval →
   approved → sent → partially_received → fully_received → closed, or → cancelled/rejected from any
   pre-sent state. `bills`: draft → pending_match → matched|variance_hold → pending_approval → approved →
   partially_paid → paid, or → void (only if unpaid and unposted, else must be reversed via a new
   correcting entry, never edited in place once `journal_entry_id` is set).
4. **Posted documents are immutable.** Once a `goods_receipts`, `bills`, `debit_notes`, or
   `vendor_payments` row has a non-null `journal_entry_id`, its financial fields (amounts, accounts,
   quantities) cannot be edited. Corrections require a new debit note, a reversing journal entry, or a
   voided-and-recreated document, each independently permissioned and audit-logged.
5. **Three-way match is mandatory before bill approval** for any bill with a non-null `purchase_order_id`.
   A bill cannot move from `pending_match` to `approved` while `match_result` is `price_variance`,
   `quantity_variance`, or `over_tolerance`, unless a user holding
   `purchasing.bills.approve-with-variance` overrides it (reason required, logged).
6. **Tolerance configuration** lives on the company's purchasing settings (referenced, not owned here):
   default price tolerance 2% or 5.000 in base currency (whichever is greater), default quantity
   tolerance 0% over-receipt (partial under-receipt is always allowed and simply leaves the PO open).
   Values are configurable per company and optionally per vendor/category.
7. **Bill cannot exceed goods-received quantity** for stocked (product-linked) lines: `bill_items.quantity`
   summed per `purchase_order_item_id` must be ≤ `purchase_order_items.quantity_received`, unless the
   vendor invoices for services/non-stocked lines (no `product_id`), which match against the PO amount
   directly rather than a physical receipt.
8. **A Goods Receipt always posts a journal entry** (Inventory or Expense debit, GR/IR clearing credit)
   at receipt time — the company recognizes the obligation for goods received even before the vendor's
   bill arrives. The Bill, when it lands, debits the GR/IR clearing account (not Inventory again) and
   credits Accounts Payable, and reconciles against the GR clearing balance for that PO line.
9. **Vendor Payments only post against approved, unpaid/partially-paid bills**, or as a standalone
   `is_prepayment = true` payment when no bill exists yet. A prepayment posts to a
   `Vendor Advances (Asset)` account and is applied against a future bill via an explicit "apply advance"
   action, which is itself a mini-journal reclassifying Vendor Advances → Accounts Payable offset.
10. **Withholding tax** is computed at bill approval (stored on `bills.withholding_tax_amount`) using the
    vendor's WHT profile (owned by Vendors/Tax doc) and is deducted from the cash paid at Vendor Payment
    time; the withheld amount is credited to a `WHT Payable` liability account, later remitted to the tax
    authority through the Tax module's `tax_transactions`.
11. **Multi-currency bills** post at the `exchange_rate` in effect on `bill_date` for the initial AP
    recognition. At Vendor Payment time, the payment posts at the rate in effect on `payment_date`; the
    difference between the AP balance at the bill's rate and the cash paid at the payment's rate is
    recognized as realized FX Gain/Loss in the same journal entry as the payment.
12. **Debit notes reduce AP** and, when `reason = 'return_to_vendor'`, also reverse the corresponding
    stock movement/inventory value; when `reason` is a pure price correction, only the AP/expense side
    moves (no stock impact).
13. **A bill in an open fiscal period only.** No bill, debit note, or vendor payment may post into a
    `fiscal_periods` row with `status = 'closed'`; the API returns 409 and the record stays in draft.
14. **Budget control (optional).** If a `purchase_orders.budget_id` is set and the company has budget
    enforcement enabled, the PO cannot move to `approved` if it would exceed the remaining budget balance
    for that cost center/period, unless the approver holds `purchasing.orders.approve-over-budget`.

# Workflow

```
 ┌───────────────────┐
 │ Purchase           │  draft ──approve──► approved ──send──► sent
 │ Requisition/Order   │     │                                   │
 │ (purchase_orders)    │     └──reject/cancel──► rejected/cancelled
 └──────────┬───────────┘                                        │
            │  vendor delivers goods                             │
            ▼                                                    │
 ┌───────────────────┐                                           │
 │ Goods Receipt       │  draft ──post──► posted                  │
 │ (goods_receipts)     │        (posts Inventory/Expense Dr,       │
 │ partial allowed       │         GR/IR Clearing Cr; updates         │
 │ (0..N per PO line)      │         PO.quantity_received, stock)       │
 └──────────┬───────────────┘                                           │
            │  vendor sends invoice                                     │
            ▼                                                            │
 ┌───────────────────────┐                                                │
 │ Bill (Purchase Invoice) │  draft ──► pending_match ──3-way match──►      │
 │ (bills + bill_items)     │       matched ──► pending_approval ──►         │
 │                            │       approved (posts GR/IR Clearing Dr,       │
 │                              │       AP Cr, Input Tax Dr; WHT computed)       │
 │                                │  OR  variance_hold (needs override/PO amend)   │
 └──────────┬─────────────────────┘                                                 │
            │  optional: goods returned / price corrected                          │
            ▼                                                                       │
 ┌───────────────────┐                                                              │
 │ Debit Note          │  draft ──approve──► approved ──apply to bill──► applied     │
 │ (debit_notes)        │       (posts AP Dr, Inventory/Expense Cr)                    │
 └──────────┬───────────┘                                                              │
            │  reduces bills.balance_due                                               │
            ▼                                                                          │
 ┌───────────────────────┐                                                             │
 │ Vendor Payment           │  draft ──approve──► approved ──post──► posted ──►          │
 │ (vendor_payments +          │       reconciled (bank statement matched)                 │
 │ vendor_payment_allocations)   │  (posts AP Dr, WHT Payable Cr if any,                      │
 │                                  │   FX Gain/Loss, Bank/Cash Cr)                              │
 └──────────────────────────────────┘
            │
            ▼
 PO fully_received + fully_billed + bill.paid ──► purchase_orders.status = closed
```

Step-by-step:

1. **Create/approve Purchase Order.** Purchasing Employee drafts a PO against a vendor and warehouse;
   line items reference `products` (stocked) or an `expense_account_id` (service/non-stocked). PO routes
   to a Purchasing Manager for `pending_approval → approved` if above the company's auto-approval
   threshold. `sent` marks it transmitted to the vendor (email/portal — integration detail, not in scope).
2. **Record Goods Receipt(s).** Warehouse staff creates a `goods_receipts` row (possibly partial, possibly
   multiple over time) referencing the PO. On `post`, the system: (a) creates `stock_movements` /
   increases `inventory_items.quantity_on_hand` per the Inventory doc, (b) writes a journal entry debiting
   Inventory (stocked) or the line's Expense account (non-stocked), crediting `GR/IR Clearing (Liability)`,
   (c) updates `purchase_order_items.quantity_received` and rolls the PO to `partially_received` or
   `fully_received`.
3. **Record the Bill.** Accounts Payable staff (or Document AI via OCR) creates a `bills` row, linking each
   `bill_items` row to the matching `purchase_order_item_id`/`goods_receipt_item_id`. The matching engine
   compares PO price/qty vs GR qty vs Bill price/qty and sets `match_result` + `match_variance_amount`.
   If within tolerance → `matched`; else → `variance_hold` pending manual review or override.
4. **Approve the Bill.** A Senior Accountant/AP approver reviews the match result, WHT computation, and
   tax lines, then approves. This posts the journal entry: debit `GR/IR Clearing` (clearing the liability
   set up at receipt) for the goods-received portion, debit `Input Tax Receivable`, debit any non-PO
   Expense lines directly, credit `Accounts Payable — Vendor Control`. `bills.status → approved`.
5. **Pay the Vendor.** Treasury schedules and creates a `vendor_payments` row, allocating it across one or
   more approved/partially-paid bills via `vendor_payment_allocations` (or flags `is_prepayment` with no
   allocation). On approval + posting: debit `Accounts Payable`, credit `WHT Payable` (if applicable),
   credit/debit `Realized FX Gain/Loss` (if multi-currency), credit `Bank/Cash`. `bills.paid_amount` and
   `balance_due` update; status becomes `partially_paid` or `paid`.
6. **Optional Debit Note.** If goods are returned or price is corrected after a bill is approved, AP
   raises a `debit_notes` row referencing the bill/line. On approval: debit `Accounts Payable`, credit
   `Inventory` (return) or the original `Expense`/`Input Tax` account (price correction); if a physical
   return, a `stock_movements` reversal is created. The debit note is then applied to reduce the bill's
   `balance_due`, or refunded in cash if the bill was already fully paid.
7. **Close the PO.** Once `quantity_received` and `quantity_billed` both reach `quantity_ordered` (or the
   PO is manually closed short), `purchase_orders.status → closed`. A closed PO cannot accept new receipts
   or bills.

## Journal Entries by Step

Every row below is one balanced `journal_entries` header + its `journal_lines`. Amounts are illustrative
(base currency, e.g. KWD) for a stocked purchase: PO 100 units @ 4.200 = 420.000, tax 5% = 21.000, WHT n/a.

| # | Step | Trigger | Account | Debit | Credit |
|---|---|---|---|---|---|
| 1 | Goods Receipt posted | `POST /goods-receipts/{id}/post` | Inventory (Asset) | 420.000 | |
| 1 | Goods Receipt posted | — | GR/IR Clearing (Liability) | | 420.000 |
| 2 | Bill approved (matched, stocked line) | `POST /bills/{id}/approve` | GR/IR Clearing (Liability) | 420.000 | |
| 2 | Bill approved | — | Input Tax Receivable (Asset) | 21.000 | |
| 2 | Bill approved | — | Accounts Payable — Vendor Control (Liability) | | 441.000 |
| 2b | Bill approved (non-PO service line, no receipt) | `POST /bills/{id}/approve` | Expense account (e.g. Utilities) | 420.000 | |
| 2b | Bill approved (non-PO) | — | Input Tax Receivable (Asset) | 21.000 | |
| 2b | Bill approved (non-PO) | — | Accounts Payable — Vendor Control (Liability) | | 441.000 |
| 3 | Vendor Payment posted (no WHT, no FX) | `POST /payments/{id}/post` | Accounts Payable — Vendor Control (Liability) | 441.000 | |
| 3 | Vendor Payment posted | — | Bank/Cash (Asset) | | 441.000 |
| 3a | Vendor Payment posted (with WHT, e.g. 5%) | `POST /payments/{id}/post` | Accounts Payable — Vendor Control (Liability) | 441.000 | |
| 3a | Vendor Payment posted (WHT) | — | WHT Payable (Liability) | | 22.050 |
| 3a | Vendor Payment posted (WHT) | — | Bank/Cash (Asset) | | 418.950 |
| 3b | Vendor Payment posted (FX loss on settlement) | `POST /payments/{id}/post` | Accounts Payable — Vendor Control (Liability) | 441.000 | |
| 3b | Vendor Payment posted (FX loss) | — | Realized FX Loss (Expense) | 6.500 | |
| 3b | Vendor Payment posted (FX loss) | — | Bank/Cash (Asset) | | 447.500 |
| 4 | Prepayment posted (`is_prepayment=true`, no bill yet) | `POST /payments/{id}/post` | Vendor Advances (Asset) | 200.000 | |
| 4 | Prepayment posted | — | Bank/Cash (Asset) | | 200.000 |
| 4a | Advance applied to a later bill | Apply-advance action | Accounts Payable — Vendor Control (Liability) | 200.000 | |
| 4a | Advance applied | — | Vendor Advances (Asset) | | 200.000 |
| 5 | Debit Note approved — `return_to_vendor` | `POST /debit-notes/{id}/approve` | Accounts Payable — Vendor Control (Liability) | 44.100 | |
| 5 | Debit Note approved — return | — | Inventory (Asset) | | 42.000 |
| 5 | Debit Note approved — return | — | Input Tax Receivable (Asset) | | 2.100 |
| 6 | Debit Note approved — `price_correction` (no stock) | `POST /debit-notes/{id}/approve` | Accounts Payable — Vendor Control (Liability) | 21.000 | |
| 6 | Debit Note approved — price correction | — | Expense / original line account | | 20.000 |
| 6 | Debit Note approved — price correction | — | Input Tax Receivable (Asset) | | 1.000 |

Every line pair above nets to zero (`SUM(debit) = SUM(credit)`) within the same `journal_entries.id`; the
`AccountingPostingService` rejects any candidate entry that does not balance before it is persisted.

# User Permissions

| Permission Key | Description |
|---|---|
| `purchasing.requisitions.create` | Create a purchase requisition / draft PO |
| `purchasing.orders.create` | Create a purchase order |
| `purchasing.orders.read` | View purchase orders |
| `purchasing.orders.update` | Edit a draft/pending PO |
| `purchasing.orders.approve` | Approve a PO within normal budget |
| `purchasing.orders.approve-over-budget` | Approve a PO that exceeds its budget allocation |
| `purchasing.orders.send` | Mark a PO as sent to the vendor |
| `purchasing.orders.cancel` | Cancel/reject a PO |
| `purchasing.goods-receipts.create` | Record a goods receipt |
| `purchasing.goods-receipts.post` | Post a goods receipt (triggers GL + stock posting) |
| `purchasing.goods-receipts.reverse` | Reverse a posted goods receipt |
| `purchasing.bills.create` | Create/draft a bill |
| `purchasing.bills.match` | Run/override the three-way match |
| `purchasing.bills.approve` | Approve a matched bill for payment |
| `purchasing.bills.approve-with-variance` | Approve a bill despite a match variance/hold |
| `purchasing.bills.void` | Void an unposted or reverse a posted bill |
| `purchasing.debit-notes.create` | Create a debit note |
| `purchasing.debit-notes.approve` | Approve a debit note (posts GL) |
| `accounting.vendor-payments.create` | Draft a vendor payment / prepayment |
| `accounting.vendor-payments.approve` | Approve a vendor payment (release for posting) — **sensitive** |
| `accounting.vendor-payments.post` | Post an approved payment against the bank (executes the outflow) — **sensitive** |
| `accounting.vendor-payments.void` | Void/reverse a posted payment — **sensitive** |
| `purchasing.reports.read` | View AP aging, open PO, and spend reports |

Default role matrix (● = granted):

| Permission | Owner/CEO | CFO | Finance Mgr | Sr. Accountant | Accountant | Purchasing Mgr | Purchasing Employee | Warehouse Employee | Auditor / Read Only |
|---|---|---|---|---|---|---|---|---|---|
| purchasing.requisitions.create | ● | ● | ● | ● | ● | ● | ● | | |
| purchasing.orders.create | ● | ● | ● | | | ● | ● | | |
| purchasing.orders.approve | ● | ● | ● | | | ● | | | |
| purchasing.orders.approve-over-budget | ● | ● | | | | | | | |
| purchasing.orders.send | ● | ● | ● | | | ● | ● | | |
| purchasing.orders.cancel | ● | ● | ● | | | ● | | | |
| purchasing.goods-receipts.post | ● | ● | ● | | | ● | | ● | |
| purchasing.goods-receipts.reverse | ● | ● | ● | | | | | | |
| purchasing.bills.create | ● | ● | ● | ● | ● | | | | |
| purchasing.bills.approve | ● | ● | ● | ● | | | | | |
| purchasing.bills.approve-with-variance | ● | ● | ● | | | | | | |
| purchasing.bills.void | ● | ● | ● | | | | | | |
| purchasing.debit-notes.approve | ● | ● | ● | ● | | | | | |
| accounting.vendor-payments.create | ● | ● | ● | ● | ● | | | | |
| accounting.vendor-payments.approve | ● | ● | ● | | | | | | |
| accounting.vendor-payments.post | ● | ● | ● | | | | | | |
| accounting.vendor-payments.void | ● | ● | | | | | | | |
| purchasing.reports.read | ● | ● | ● | ● | ● | ● | ● | | ● |

Payment approval and posting are split into two distinct permissions deliberately, so the same person who
approves a payment amount is not, by default, the same person who releases the bank instruction —
segregation of duties for the highest-fraud-risk action in the module.

# AI Responsibilities

| Agent | Role in Purchases | Inputs | Outputs | Autonomy | Confidence Handling |
|---|---|---|---|---|---|
| **Document AI / OCR Agent** | Reads scanned/emailed supplier invoices (PDF/image attached via `attachments`), extracts vendor, invoice number, date, due date, line items, amounts, tax | Attachment file, vendor master data for name matching | A pre-filled `bills` draft (status `draft`) + `ocr_confidence` per field and overall | Suggest-only — always lands in `draft`, never auto-approved | Fields below 85% confidence are highlighted red in the UI and block one-click approval; overall confidence < 60% routes to manual entry with a "low confidence" banner |
| **General Accountant Agent** | Runs/proposes the three-way match: compares PO, GR, Bill quantities and prices, computes `match_variance_amount`, proposes GL account mapping for non-stocked lines | `purchase_order_items`, `goods_receipt_items`, `bill_items`, tolerance settings | `match_result`, suggested account codes, a plain-language variance explanation | Auto for matches within tolerance (sets `matched` automatically); suggest-only for anything triggering `variance_hold` — a human must call `purchasing.bills.approve-with-variance` | Always attaches reasoning ("PO unit price 12.500, bill unit price 13.200, variance 5.6%, exceeds 5% tolerance") and the exact line-level source documents |
| **Fraud Detection Agent** | Scans new bills for duplicate-invoice risk: same vendor + same `vendor_invoice_number` (hard-blocked by the unique constraint), or same vendor + near-identical amount + close date, or amounts inconsistent with the vendor's historical average | New/draft bill, vendor's bill history | A risk flag + confidence + explanation attached to the bill (does not block save, but blocks `purchasing.bills.approve` until a reviewer dismisses or confirms the flag) | Suggest-only, hard gate on approval only | Confidence + matched historical bill IDs shown; reviewer must record a dismissal reason, which is audit-logged |
| **Treasury Manager Agent** | Proposes a payment run: which approved bills to pay and when, prioritizing early-payment discounts, due dates, and available cash in `bank_accounts` | Open bills (`approved`/`partially_paid`), `bank_accounts` balances, vendor payment terms | A proposed batch of `vendor_payments` in `draft` status with suggested date/amount per bill | Suggest-only — drafts payments, never calls `accounting.vendor-payments.approve` or `.post` itself | Shows projected cash balance after the run and flags any bill that would overdraw an account, with the reasoning trace |
| **Auditor Agent** | Periodically reviews posted bills/payments for policy conformance (e.g., a bill approved with `approve-with-variance` but no override reason, WHT not withheld where the vendor profile requires it) | Posted `bills`, `vendor_payments`, `audit_logs` | Exception report entries, not data mutations | Read-only, suggest-only | Every finding cites the exact record id and the rule violated |

No AI agent in this module is ever granted `accounting.vendor-payments.approve`, `.post`, or `.void` —
these remain human-only actions per the platform-wide sensitive-operations rule.

# API Endpoints

Base path: `/api/v1/purchasing`. All requests require `Authorization: Bearer <token>` and
`X-Company-Id: <id>`. All responses use the standard envelope.

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/purchasing/orders` | `purchasing.orders.read` | List purchase orders (filter: status, vendor_id, date range) |
| POST | `/api/v1/purchasing/orders` | `purchasing.orders.create` | Create a draft PO with line items |
| GET | `/api/v1/purchasing/orders/{id}` | `purchasing.orders.read` | Get PO detail incl. receipt/bill progress |
| PATCH | `/api/v1/purchasing/orders/{id}` | `purchasing.orders.update` | Update a draft/pending PO |
| POST | `/api/v1/purchasing/orders/{id}/approve` | `purchasing.orders.approve` | Approve the PO |
| POST | `/api/v1/purchasing/orders/{id}/send` | `purchasing.orders.send` | Mark as sent to vendor |
| POST | `/api/v1/purchasing/orders/{id}/cancel` | `purchasing.orders.cancel` | Cancel/reject the PO |
| GET | `/api/v1/purchasing/goods-receipts` | `purchasing.orders.read` | List goods receipts |
| POST | `/api/v1/purchasing/goods-receipts` | `purchasing.goods-receipts.create` | Create a draft goods receipt against a PO |
| POST | `/api/v1/purchasing/goods-receipts/{id}/post` | `purchasing.goods-receipts.post` | Post the receipt (GL + stock) |
| POST | `/api/v1/purchasing/goods-receipts/{id}/reverse` | `purchasing.goods-receipts.reverse` | Reverse a posted receipt |
| GET | `/api/v1/purchasing/bills` | `purchasing.orders.read` | List bills (filter: status, vendor_id, due_date, match_result) |
| POST | `/api/v1/purchasing/bills` | `purchasing.bills.create` | Create a draft bill (manually or from OCR draft) |
| GET | `/api/v1/purchasing/bills/{id}` | `purchasing.orders.read` | Get bill detail incl. match result |
| POST | `/api/v1/purchasing/bills/{id}/match` | `purchasing.bills.match` | Re-run the three-way match |
| POST | `/api/v1/purchasing/bills/{id}/approve` | `purchasing.bills.approve` / `.approve-with-variance` | Approve the bill (posts GL) |
| POST | `/api/v1/purchasing/bills/{id}/void` | `purchasing.bills.void` | Void or reverse the bill |
| POST | `/api/v1/purchasing/debit-notes` | `purchasing.debit-notes.create` | Create a debit note |
| POST | `/api/v1/purchasing/debit-notes/{id}/approve` | `purchasing.debit-notes.approve` | Approve (posts GL, applies to bill) |
| GET | `/api/v1/purchasing/payments` | `purchasing.orders.read` | List vendor payments |
| POST | `/api/v1/purchasing/payments` | `accounting.vendor-payments.create` | Draft a payment/prepayment with bill allocations |
| POST | `/api/v1/purchasing/payments/{id}/approve` | `accounting.vendor-payments.approve` | Approve the draft payment |
| POST | `/api/v1/purchasing/payments/{id}/post` | `accounting.vendor-payments.post` | Post the payment (executes GL + bank outflow) |
| POST | `/api/v1/purchasing/payments/{id}/void` | `accounting.vendor-payments.void` | Void/reverse a posted payment |

### Sample: `POST /api/v1/purchasing/bills`

Request:
```json
{
  "vendor_id": 512,
  "purchase_order_id": 8801,
  "vendor_invoice_number": "INV-77123",
  "bill_date": "2026-07-14",
  "due_date": "2026-08-13",
  "currency_code": "USD",
  "exchange_rate": 0.307500,
  "items": [
    {
      "purchase_order_item_id": 20044,
      "goods_receipt_item_id": 31022,
      "product_id": 9010,
      "description": "A4 Copy Paper 80gsm - 5 ream pack",
      "quantity": 100,
      "unit_price": 4.20,
      "tax_rate_id": 3
    }
  ],
  "attachment_id": 55211
}
```

Response `201 Created`:
```json
{
  "success": true,
  "data": {
    "id": 44107,
    "bill_number": "BILL-2026-000789",
    "vendor_invoice_number": "INV-77123",
    "status": "pending_match",
    "vendor_id": 512,
    "purchase_order_id": 8801,
    "currency_code": "USD",
    "exchange_rate": 0.307500,
    "subtotal_amount": 420.00,
    "tax_amount": 21.00,
    "total_amount": 441.00,
    "base_total_amount": 135.61,
    "balance_due": 135.61,
    "match_result": null,
    "fiscal_period_id": 214,
    "items": [
      {
        "id": 91344,
        "line_no": 1,
        "purchase_order_item_id": 20044,
        "goods_receipt_item_id": 31022,
        "product_id": 9010,
        "quantity": 100,
        "unit_price": 4.20,
        "tax_amount": 21.00,
        "line_total": 441.00
      }
    ]
  },
  "message": "Bill created and queued for three-way match.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3f8a2b1e-4c9d-4a6b-9e12-7d1c9f0a2b33",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

### Sample: `POST /api/v1/purchasing/bills/{id}/approve`

Response `200 OK` (matched, no variance):
```json
{
  "success": true,
  "data": {
    "id": 44107,
    "status": "approved",
    "journal_entry_id": 771205,
    "withholding_tax_amount": 0.00,
    "balance_due": 135.61
  },
  "message": "Bill approved and posted to the General Ledger.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9c1d4e77-2a3f-4b8c-8e01-5f6a7b8c9d0e",
  "timestamp": "2026-07-16T09:20:11Z"
}
```

### Sample: `POST /api/v1/purchasing/payments`

Request:
```json
{
  "vendor_id": 512,
  "bank_account_id": 4,
  "payment_method": "bank_transfer",
  "payment_date": "2026-07-20",
  "currency_code": "USD",
  "exchange_rate": 0.306900,
  "amount": 441.00,
  "allocations": [
    { "bill_id": 44107, "allocated_amount": 135.61 }
  ]
}
```

# Validation Rules

- **Line math**: for every PO/Bill/Debit Note line, `line_total = ROUND(quantity * unit_price, 4) +
  tax_amount`; the sum of line `line_total` values must equal the document's `subtotal_amount +
  tax_amount = total_amount` within 0.0001 (rounding unit), else `422`.
- **Three-way match**: a bill line tied to a `purchase_order_item_id` must satisfy
  `ABS(bill_unit_price - po_unit_price) / po_unit_price <= price_tolerance_pct` AND
  `bill_quantity <= goods_receipt_items.quantity_received (cumulative, minus already-billed)`; violations
  set `match_result` accordingly rather than hard-rejecting the save (so the bill can still be reviewed).
- **Tax**: `tax_amount` on a line must equal `ROUND(quantity * unit_price * tax_rate.rate, 4)`; a line
  referencing a `tax_rate_id` that is inactive or outside the company's tax jurisdiction returns `422`.
- **Open period**: `bill_date`, `payment_date`, and `debit_notes.note_date` must fall inside a
  `fiscal_periods` row with `status = 'open'`; posting into a closed period returns `409`.
- **Budget**: if `purchase_orders.budget_id` is set and enforcement is on, approving the PO checks
  remaining budget balance; insufficient budget returns `422` unless the approver holds the override
  permission.
- **Currency consistency**: `bills.currency_code` must match `purchase_orders.currency_code` when a
  `purchase_order_id` is set (a bill cannot silently switch currency from its PO); mismatches return `422`.
- **Vendor invoice uniqueness**: `(company_id, vendor_id, vendor_invoice_number)` must be unique — enforced
  at the DB constraint level and surfaced to the API as `409 Conflict` with the existing bill id.
- **Payment allocation sum**: `SUM(vendor_payment_allocations.allocated_amount)` for a payment must equal
  `net_paid_amount` (or less than it, if part is a genuine overpayment credit — see edge cases) and no
  single allocation may exceed the target bill's current `balance_due`.
- **Approval sequencing**: a bill cannot be approved while `status = 'variance_hold'` unless the caller
  holds `purchasing.bills.approve-with-variance` and supplies a non-empty `override_reason`.
- **State guard**: every state-transition endpoint validates the current `status` is a legal predecessor
  of the target state (e.g. `POST /bills/{id}/approve` returns `409` if status is not `matched` or, with
  override, `variance_hold`).
- **Withholding tax**: if the vendor's tax profile marks them WHT-applicable, `bills.withholding_tax_amount`
  must be computed and non-zero at approval time; a zero WHT on a WHT-applicable vendor blocks approval
  with a `422` unless a `tax.wht-exempt-override` permission is used (owned by the Tax doc).

# Edge Cases

- **Partial receipt**: a PO can receive fewer than ordered; `purchase_order_items.quantity_received`
  accumulates across multiple `goods_receipts`; the PO stays `partially_received` until fully received or
  manually closed short (e.g. vendor cannot fulfill the remainder) — closing short is a
  `purchasing.orders.cancel`-permissioned action on the residual quantity, not a delete.
- **Partial payment**: a `vendor_payments` allocation may be less than a bill's `balance_due`; the bill
  moves to `partially_paid` and remains open, listed in AP aging until fully settled.
  Multiple payments can accumulate against one bill via multiple `vendor_payment_allocations` rows.
- **Over-billing (bill quantity/amount exceeds the PO)**: blocked by default for stocked lines
  (`bill quantity <= quantity_received`, not `quantity_ordered`, so a bill can legitimately be less than
  ordered but not more than received); a bill line with no matching PO/receipt quantity available sets
  `match_result = 'over_tolerance'` and requires `purchasing.bills.approve-with-variance`.
- **Price variance**: vendor bills at a different unit price than the PO (rate increase, spot-buy
  surcharge). Within tolerance → auto-`matched`, the bill posts at the *bill's* price (bills are always
  the source of truth for what is legally owed); outside tolerance → `variance_hold`, requiring override or
  a PO amendment before approval.
- **Non-PO bill**: recurring vendor costs with no formal PO (utilities, subscriptions, rent). Created with
  `purchase_order_id = NULL`; `match_result` stays `NULL` (three-way match is skipped entirely); such bills
  route straight from `draft` to `pending_approval` and only need the normal tax/period/line-math checks.
- **Currency rounding**: base-currency amounts are always `ROUND(transaction_amount * exchange_rate, 4)`;
  because `journal_lines` require exact debit=credit balance, a residual rounding difference up to 0.0001
  base-currency units is posted to a dedicated `Rounding Adjustment` expense/income line rather than left
  unbalanced.
- **FX gain/loss on payment**: if `exchange_rate` at `payment_date` differs from the rate at `bill_date`,
  the AP balance being cleared (at the bill's rate) will not exactly equal the cash paid (at the payment's
  rate); the difference posts to `Realized FX Gain` (credit, if the currency weakened in the company's
  favor) or `Realized FX Loss` (debit) within the same payment journal entry — never left as an
  out-of-balance entry.
- **Returns to vendor after payment**: a `debit_notes` row with `reason = 'return_to_vendor'` created
  against an already-fully-paid bill cannot reduce a `balance_due` of zero; instead `applied_amount` is
  refunded as an incoming vendor credit/cash refund, tracked as a negative AP balance until either offset
  against a future bill from the same vendor or refunded via a manual receipt (owned by Banking).
- **Duplicate invoice submission**: caught first by the hard DB uniqueness constraint on
  `(vendor_id, vendor_invoice_number)` (`409` immediately), and second, for near-duplicates with a
  different invoice number but suspiciously similar amount/date, by the Fraud Detection Agent's soft flag
  that blocks approval pending human review.
- **Prepayment applied to a bill smaller than the advance**: applying an advance larger than the target
  bill's total only consumes the needed portion from `Vendor Advances`; the remainder stays as an asset
  balance available for the next bill from that vendor.
- **Goods receipt reversed after a bill already references it**: reversing a `goods_receipts` row whose
  `goods_receipt_items` are already linked to an *approved* bill is blocked (`409`) — the bill must be
  voided/reversed first, maintaining referential and financial integrity between the two documents.

# Related Modules

- **Vendors** — owns `vendors`, `vendor_contacts`, and vendor WHT/payment-terms profiles; Purchases reads
  vendor master data and never writes to it. Events consumed: `vendor.updated` (invalidates cached payment
  terms).
- **Products** — owns `products`, `product_categories`, `units_of_measure`; Purchases reads product
  default cost/inventory account mapping used to resolve `bill_items.inventory_account_id`.
- **Inventory** — owns `inventory_items`, `stock_movements`, `stock_adjustments`; Goods Receipts and
  return-type Debit Notes emit `stock_movements` rows and update `inventory_items.quantity_on_hand` and
  valuation. Events emitted: `goods_receipt.posted`, `debit_note.stock_reversed`. Landed-cost allocation
  (freight/customs) at receipt is detailed in the Inventory doc and referenced here via
  `goods_receipt_items.line_value_base`.
- **Accounting (journal_entries, journal_lines, accounts, fiscal_periods)** — every posting event in this
  doc (`goods_receipt.posted`, `bill.approved`, `debit_note.approved`, `vendor_payment.posted`) calls the
  shared `AccountingPostingService`, which creates a balanced `journal_entries` row + `journal_lines` and
  respects `fiscal_periods.status`. Purchases never writes GL rows directly.
- **Banking** — owns `bank_accounts`, `bank_transactions`, `bank_reconciliations`; Vendor Payments create a
  `bank_transactions` row on posting and are later matched during bank reconciliation. Events consumed:
  `bank.transaction_matched` (marks a `vendor_payments` row `reconciled`).
- **Tax** — owns `tax_codes`, `tax_rates`, `tax_transactions`; Bills reference `tax_rates` for Input Tax
  lines, and approved bills emit a `bill.tax_recognized` event consumed by Tax to populate
  `tax_transactions` for VAT/GST returns; withheld amounts emit `payment.wht_withheld` consumed by Tax for
  WHT remittance filings.
- **Reports** — AP Aging, Open Purchase Orders, and Vendor Spend reports are `report_definitions` that
  query this module's tables (and `ledger_entries` for GL-verified totals) read-only.

# Future Improvements

- Formal, separately-tracked Purchase Requisition workflow (multi-approver, budget pre-check) ahead of PO
  creation, rather than collapsing it into `purchase_orders.is_requisition`.
- Vendor self-service portal for PO acknowledgment and invoice submission, feeding `bills` directly instead
  of via email/OCR.
- Automated landed-cost allocation rules (weighted by value/weight/volume) shared with Inventory, applied
  automatically at Goods Receipt rather than as a manual adjustment.
- Dynamic discounting / early-payment-discount capture suggested proactively by the Treasury Manager Agent
  when cash position allows.
- Blanket/framework purchase orders (a PO valid over a date range with call-off releases) for recurring
  high-volume vendors.
- Vendor scorecarding (on-time delivery %, price variance history, quality) feeding back into Vendor
  Manager Agent's automated approval routing.

# End of Document
