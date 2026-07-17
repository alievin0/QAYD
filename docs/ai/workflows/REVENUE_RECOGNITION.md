# Revenue Recognition (IFRS 15) — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: REVENUE_RECOGNITION
---

# Purpose

Billing and revenue recognition are two different clocks, and most accounting mistakes in a subscription
or project-based business come from letting them run as if they were one. A customer can be billed —
and pay — in full on day one of a twelve-month contract; the revenue that invoice represents is not
"earned" on day one, it is earned a little at a time, every day, as QAYD's tenant delivers the service it
promised. IFRS 15, *Revenue from Contracts with Customers*, exists to force that separation onto every
company's books: recognize revenue when (or as) control of a promised good or service transfers to the
customer, in the amount the entity expects to be entitled to for that transfer — never when the invoice is
raised, never when the cash lands, unless those events happen to coincide with the transfer of control.

This document specifies the workflow that makes that separation automatic, continuous, and auditable
inside QAYD, rather than a spreadsheet a controller rebuilds every month-end. It is a **Module: AI**
workflow document, not a new accounting module: every table, account, and posting convention it touches
already exists (`invoices`, `invoice_items`, `journal_entries`, `journal_lines`, the Chart of Accounts'
Unearned Revenue liability, and `products.revenue_recognition_method`, defined respectively in the Sales,
Journal Entries, Chart of Accounts, and Products submodules). What did not exist until this document is the
machinery that sits between "an invoice posted" and "revenue is fully, correctly, and continuously
recognized over the life of the performance obligations that invoice represents" — the five new tables
(`revenue_contracts`, `performance_obligations`, `revenue_schedules`, `revenue_schedule_lines`,
`contract_modifications`), the orchestration that drives them, and the five specialized agents that propose,
approve, execute, watch, and disclose the result.

Consistent with the framing that governs every AI document in this platform: the AI here is not a chatbot
answering "what does deferred revenue mean" — it is an autonomous finance workforce that reads every posted
invoice and every sales contract the moment it is created, works out on its own which performance
obligations it contains, proposes exactly how much revenue to defer and exactly when to release it, and
then executes that plan every month for the life of the contract, without anyone opening a spreadsheet —
while a human accountant or CFO remains the one, and only, party who can authorize the plan in the first
place. Five agents participate, each doing exactly the part of the job that matches their existing mandate
elsewhere in the platform: **General Accountant** performs the IFRS 15 five-step analysis and drafts the
recognition schedule; **CFO** approves schedules above a materiality threshold and reads the resulting
deferred-revenue position as a board-level metric; **Auditor** continuously re-verifies that the schedule,
the ledger, and the contract never drift apart; **Compliance Agent** watches that the disclosures IFRS 15
requires are actually complete at period-end; **Reporting Agent** generates those disclosures — the
deferred-revenue rollforward and the Remaining Performance Obligations (RPO) note — on schedule, for every
period, without being asked.

The AI engine never writes to `journal_entries`, `performance_obligations`, or any other table directly.
Every table this document introduces is written exclusively through the Laravel 12 `/api/v1/*` API, subject
to the identical `FormRequest` validation and RBAC permission check a human user's request would receive —
the workflow below is a sequence of proposals and, where a company's policy allows it, pre-authorized
executions of an already-approved plan; it is never a backdoor into the ledger.

# Trigger & Preconditions

This workflow activates whenever a contract with a customer contains one or more **performance
obligations** — a promise to transfer a distinct good or service — whose satisfaction does not coincide
exactly with the moment the customer is billed. The common triggers, in order of frequency across a typical
QAYD tenant:

| Trigger event | Origin | What starts |
|---|---|---|
| `invoice.posted` | Sales module (the automatic journal-entry-producing event, per the Journal Entries submodule) | If any `invoice_items` row's product carries `revenue_recognition_method <> 'immediate'`, or the invoice covers a service period longer than the current fiscal period, Steps 1–4 run against that invoice |
| `sales_order.confirmed` | Sales module, for `billing_policy = 'milestone'` orders billed as work completes | Creates the `revenue_contracts` row ahead of the first invoice, so milestone recognition can begin the moment a milestone completes even if billing lags behind delivery |
| `subscription.renewed` (a recurring `invoices` row generated at `recurrence_rule.next_run_date`, per the Sales submodule's recurring-billing engine) | Sales module | Opens a **new** `revenue_contracts` row for the renewal term; the prior term's contract is marked `completed` once its schedule fully recognizes |
| `service_delivery.milestone_completed` | Delivery/Projects module, or a human confirmation in the Next.js app | Triggers point-in-time recognition for a specific `performance_obligations` row awaiting an output-method completion signal |
| `contract.amended` | Sales module (an accepted change order against a `sales_orders` row already linked to an active `revenue_contracts` row) | Starts the contract-modification sub-flow (see Edge Cases) |

**Preconditions** that must hold before the workflow can run to completion:

1. The invoice or order has at least one line item resolvable to a `products` row (revenue cannot be
   deferred against a line with no product master data — a `custom_fields`-only ad hoc line is rejected
   with `422 PRODUCT_REQUIRED_FOR_REVREC`).
2. The tenant's Chart of Accounts has at least one active liability account flagged for Deferred Revenue use
   (`accounts.tax_category` or a company setting identifies it; the platform default seeds account `2210 ·
   Deferred Revenue — Current` and `2220 · Deferred Revenue — Non-current`) and the relevant Revenue accounts
   (`4100 · Sales Revenue`, and this workflow's own `4200 · Subscription Revenue`, `4300 · Service Revenue —
   Milestone/Professional Services`) exist and are `status = 'active'`.
3. The fiscal period the triggering event falls into is `open` (or `soft_close` with an elevated role) — the
   same period-lock rule the Posting Engine enforces for every other automatic entry.
4. The customer relationship passes IFRS 15's own contract-existence test (commercial substance, identifiable
   payment terms, probable collection) — QAYD does not re-implement this as a blocking check; it is assumed
   satisfied by the existence of an approved `sales_orders`/`invoices` row, and only re-examined explicitly if
   `customers.credit_check_status = 'failed'` at contract date, which pauses Step 1 pending Finance Manager
   review rather than assuming collectibility.

# Participating Agents

| Agent | IFRS 15 responsibility in this workflow | Default autonomy | Primary artifacts | Escalates to |
|---|---|---|---|---|
| **General Accountant** | Performs Steps 1–4 (identify contract, identify performance obligations, determine transaction price, allocate transaction price), drafts the recognition schedule (Step 5 plan), drafts any period's recognition entry that deviates from an already-approved schedule | `suggest_only` for schedule creation and any judgment-bearing allocation; the routine execution of an *already-approved* schedule is a deterministic system action attributed to this agent, not a fresh proposal (see Confidence & Escalation Rules) | `revenue_contracts`, `performance_obligations`, `revenue_schedules`, `revenue_schedule_lines`, `journal_entries` (`entry_type = 'revenue_recognition'`) | Auditor, CFO |
| **CFO** | Approves schedule activation for any contract above the company's revenue-recognition materiality threshold; approves the treatment (separate contract / prospective termination / cumulative catch-up) of a contract modification; reads deferred-revenue and RPO position as a board metric | `suggest_only` (approval and narrative are always human-facing decisions this agent frames, never executes) | `cfo_capital_recommendation`-style narrative referencing the deferred-revenue rollforward; approval on `revenue_schedules`/`contract_modifications` | Human CFO / Owner |
| **Auditor** | Continuously re-verifies allocation completeness (`SUM(performance_obligations.allocated_transaction_price)` per contract equals the contract's transaction price), schedule-to-ledger tie-out (deferred-revenue account balance equals the sum of unrecognized schedule remainders), and that no recognition entry posts before its scheduled period has elapsed | `auto` for scanning and publishing findings; **never** authorized to post, adjust, or approve anything (identical boundary to its platform-wide mandate) | `ai_decisions` findings (`category = 'balance_integrity'` / `'control_violation'`) | Finance Manager, CFO |
| **Compliance Agent** | Confirms, at every period-end, that the IFRS 15 disclosures required by this workflow (disaggregated revenue, contract-balance rollforward, RPO table, significant-judgments note) are actually populated before the close checklist can complete; flags a contract modification whose recorded treatment looks inconsistent with the IFRS 15.20–21 classification criteria | `auto` for the completeness check; `suggest_only` for a classification-consistency flag | `compliance_assessments`, `compliance_alerts` (`category = 'data_gap'`, `domain = 'ifrs_reporting'`) | Tax Advisor, CFO |
| **Reporting Agent** | Generates the deferred-revenue rollforward note and the Remaining Performance Obligations (RPO) disclosure table on the standard reporting cadence, and folds them into the standard financial-statement package | `auto` for scheduled generation (a generated-but-undistributed disclosure changes nothing); `suggest_only` for ad hoc distribution outside the company | `report_runs` (disclosure notes) | CFO |

Each agent operates strictly inside the boundary already established for it elsewhere in this platform: the
General Accountant never posts or approves its own draft; the Auditor never mutates anything; the Compliance
Agent watches and never computes the schedule itself; the Reporting Agent never originates a number, only
presents what the ledger and the schedule tables already contain. This workflow adds no new capability to
any agent's permission set — it only gives the General Accountant a new *task type* to apply its existing
drafting mandate to, and gives the other four a new *subject* to watch, approve, or disclose.

# Orchestration

The workflow implements IFRS 15's five-step model exactly, with one human-approval gate (schedule
activation) governing an entire contract's future postings rather than gating every individual period.

```
 invoice.posted / sales_order.confirmed / subscription.renewed / service_delivery.milestone_completed
                                          │
                                          ▼
   STEP 1 — Identify the contract
   ┌─────────────────────────────────────────────────────────────────┐
   │ General Accountant creates/updates revenue_contracts:            │
   │   customer, term, currency, total_transaction_price,             │
   │   checks for a prior open contract with the same customer that   │
   │   IFRS 15.17 requires combining (same commercial objective,      │
   │   single negotiated price, or interdependent performance)        │
   └───────────────────────────────┬───────────────────────────────────┘
                                    ▼
   STEP 2 — Identify performance obligations
   ┌─────────────────────────────────────────────────────────────────┐
   │ For each invoice_items / sales_order_items row: is the promised  │
   │ good/service DISTINCT (customer can benefit from it alone or     │
   │ with readily available resources, AND separately identifiable   │
   │ from other promises)? One-line-one-obligation is the common,     │
   │ high-confidence case; a bundled multi-line arrangement is        │
   │ suggest-only and cites the specific bundling indicator found     │
   └───────────────────────────────┬───────────────────────────────────┘
                                    ▼
   STEP 3 — Determine the transaction price
   ┌─────────────────────────────────────────────────────────────────┐
   │ = invoices.total_amount, adjusted for variable consideration     │
   │ (constrained estimate of discounts/rebates/penalties expected to │
   │ reverse), less any significant financing component, already      │
   │ known from the posted invoice — no independent computation        │
   │ needed for the ordinary case                                      │
   └───────────────────────────────┬───────────────────────────────────┘
                                    ▼
   STEP 4 — Allocate the transaction price
   ┌─────────────────────────────────────────────────────────────────┐
   │ Relative standalone-selling-price (SSP) allocation across every  │
   │ performance_obligations row on the contract. Single-obligation   │
   │ contracts: 100% allocation, auto. Observable SSP for every line:  │
   │ high confidence, suggest-only. Non-observable SSP requiring       │
   │ adjusted-market-assessment/expected-cost-plus-margin/residual     │
   │ estimation: confidence capped, always suggest-only, always cites  │
   │ the estimation method used (see Confidence & Escalation Rules)    │
   └───────────────────────────────┬───────────────────────────────────┘
                                    ▼
                    ┌───────────────────────────────┐
                    │ BILL: invoice posts (existing  │
                    │ Sales/Journal Entries flow) —   │
                    │ Dr Accounts Receivable          │
                    │ Cr Deferred Revenue (2210/2220)  │
                    │ for every non-'immediate' line   │
                    └───────────────┬───────────────────┘
                                    ▼
   STEP 5 — Recognize revenue as/when each obligation is satisfied
   ┌─────────────────────────────────────────────────────────────────┐
   │ recognition_pattern decided per obligation:                      │
   │  point_in_time_at_invoice → nothing further to defer (handled     │
   │    upstream by the ordinary invoice template; this workflow does  │
   │    not touch these lines at all)                                  │
   │  point_in_time_on_completion → ONE revenue_schedule_lines row,     │
   │    released the moment a completion signal is confirmed            │
   │  over_time_straight_line → N equal (+ 1 rounding true-up) monthly  │
   │    revenue_schedule_lines rows across the service term             │
   │  over_time_milestone_output → one row per contractual milestone,   │
   │    amount = allocated price × that milestone's % of total value   │
   │  over_time_usage_based → one row per billing/usage period, amount  │
   │    computed from metered consumption at period-end                 │
   │  over_time_cost_to_cost_input → one row per period, amount =        │
   │    allocated price × (costs incurred to date ÷ total expected)      │
   └───────────────────────────────┬───────────────────────────────────┘
                                    ▼
                    ┌────────────────────────────────┐
                    │ HUMAN APPROVAL GATE (once per    │
                    │ contract/obligation, not per      │
                    │ period): Finance Manager/CFO       │
                    │ approves the revenue_schedules      │
                    │ row → status: draft → pending_      │
                    │ approval → active                    │
                    └────────────────┬────────────────────┘
                     approved         │        rejected/needs revision
                        │             │                │
                        ▼             │                ▼
   ┌─────────────────────────┐        │      General Accountant redrafts
   │ Deterministic scheduler   │        │      with the reviewer's stated
   │ posts each revenue_        │        │      correction; re-submits
   │ schedule_lines row on its   │        │
   │ period_end, IF status is     │◀───────┘
   │ still 'scheduled' and the      │
   │ target fiscal period is open:   │
   │  Dr Deferred Revenue             │
   │  Cr Revenue (4200/4300)           │
   │  entry_type = 'revenue_            │
   │  recognition', no fresh human       │
   │  approval per period (the plan       │
   │  itself was already approved)         │
   └────────────────┬──────────────────────┘
                     ▼
   ┌───────────────────────────────────────────────────────────────────┐
   │ Auditor re-verifies allocation completeness + rollforward tie-out  │
   │ Compliance Agent checks disclosure completeness at period-end       │
   │ Reporting Agent generates the rollforward + RPO note on schedule      │
   │ CFO reads the resulting deferred-revenue/RPO position as a KPI          │
   └───────────────────────────────────────────────────────────────────┘
```

A deviation from the approved schedule — an actual usage figure that differs from the estimate a
`usage_based` line was drafted against, a milestone whose completion percentage a project manager revises,
or any manual override — routes back through the General Accountant as a fresh, confidence-scored proposal
rather than posting automatically, exactly like any other AI-drafted entry that does not match a
pre-authorized, deterministic pattern (see Confidence & Escalation Rules).

## Step 4 in the existing schema, precisely

QAYD does not require a second, redundant computation of the allocated transaction price when the common
case applies. `invoice_items.unit_price` already carries each line's standalone selling price, and
`invoice_items.discount_amount`, whenever a multi-line invoice is genuinely bundled below the sum of its
parts, is computed by the Sales module's pricing engine as each line's proportional share of the bundle
discount — which is arithmetically identical to IFRS 15's relative-SSP allocation. In the common case,
`performance_obligations.allocated_transaction_price` is therefore populated directly from the paired
`invoice_items.line_total`, not independently recomputed. Only when standalone selling price is **not**
observable for a line (a new bundle with no history of being sold separately) does the General Accountant
run its own adjusted-market-assessment, expected-cost-plus-margin, or residual estimate — and that estimate
is what feeds back into `invoice_items.discount_amount` at quotation time, not the reverse.

# Data & Tables Touched

## Tables reused, not redefined

`companies`, `customers`, `products` (`revenue_recognition_method`, `revenue_account_id`), `sales_orders`,
`sales_order_items`, `invoices`, `invoice_items`, `journal_entries`, `journal_lines`, `accounts`,
`fiscal_periods`, `attachments`, and the shared AI-core tables `ai_agents`, `ai_tasks`, `ai_decisions`,
`ai_memory`, `ai_logs` (canonically defined in `CFO_AGENT.md`; reused here without redefinition, using its
`agent_code` + `decision_type` + `confidence_score NUMERIC(5,2)` 0–100 scale convention).

## Enum extensions (this workflow's only modification to shared types)

```sql
-- Products already exposes a per-product default recognition hint (immediate | deferred_straight_line).
-- This workflow adds the two remaining product-level defaults it needs; existing product rows are
-- unaffected (ADD VALUE is additive and does not touch existing data).
ALTER TYPE revenue_recognition_enum ADD VALUE 'deferred_milestone';
ALTER TYPE revenue_recognition_enum ADD VALUE 'deferred_usage_based';

-- Journal Entries' entry_type enum gains exactly one system-originated, automatic value for this
-- workflow's periodic and point-in-time-on-completion postings, joining 'depreciation'/'payroll' in the
-- "automatic, no approval required by default" bucket of the Approval Workflow routing table.
ALTER TYPE journal_entry_type ADD VALUE 'revenue_recognition';

-- The shared cross-agent proposal ledger (CFO_AGENT.md) gains this workflow's decision types.
ALTER TYPE ai_decision_type ADD VALUE 'revrec_contract_identification';
ALTER TYPE ai_decision_type ADD VALUE 'revrec_obligation_allocation';
ALTER TYPE ai_decision_type ADD VALUE 'revrec_schedule_proposal';
ALTER TYPE ai_decision_type ADD VALUE 'revrec_period_recognition';
ALTER TYPE ai_decision_type ADD VALUE 'revrec_contract_modification';
```

## New tables owned by this workflow

```sql
CREATE TYPE performance_obligation_pattern AS ENUM (
  'point_in_time_at_invoice',       -- control transfers at/before billing; handled upstream, not by this workflow
  'point_in_time_on_completion',    -- control transfers at one later, discrete point (e.g. onboarding go-live)
  'over_time_straight_line',        -- even consumption over the service term (subscriptions, access-based SaaS)
  'over_time_milestone_output',     -- progress measured by discrete contractual deliverables
  'over_time_usage_based',          -- progress measured by metered consumption each period
  'over_time_cost_to_cost_input'    -- progress measured by costs incurred to date over total expected costs
);
CREATE TYPE revenue_contract_status AS ENUM ('active', 'modified', 'completed', 'terminated');
CREATE TYPE performance_obligation_status AS ENUM ('not_started', 'in_progress', 'satisfied', 'cancelled');
CREATE TYPE allocation_method_enum AS ENUM (
  'single_obligation', 'observable_ssp', 'adjusted_market_assessment',
  'expected_cost_plus_margin', 'residual_approach'
);
CREATE TYPE revenue_schedule_status AS ENUM (
  'draft', 'pending_approval', 'active', 'completed', 'superseded', 'cancelled'
);
CREATE TYPE revenue_schedule_line_status AS ENUM ('scheduled', 'ready', 'recognized', 'skipped', 'adjusted');
CREATE TYPE contract_modification_type AS ENUM (
  'separate_contract', 'prospective_termination', 'cumulative_catch_up'
);

-- ============================================================
-- revenue_contracts — IFRS 15 Step 1
-- ============================================================
CREATE TABLE revenue_contracts (
  id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                  BIGINT NOT NULL REFERENCES companies(id),
  branch_id                   BIGINT NULL REFERENCES branches(id),
  contract_number              VARCHAR(40) NOT NULL,
  customer_id                   BIGINT NOT NULL REFERENCES customers(id),
  source_type                    VARCHAR(30) NOT NULL,   -- 'sales_order' | 'invoice' | 'subscription_renewal'
  source_id                       BIGINT NOT NULL,
  combined_with_contract_ids       JSONB NOT NULL DEFAULT '[]',  -- IFRS 15.17 contract combination
  contract_start_date               DATE NOT NULL,
  contract_end_date                  DATE NULL,             -- NULL = evergreen/usage-based, no fixed term
  currency_code                       CHAR(3) NOT NULL,
  exchange_rate                        NUMERIC(18,6) NOT NULL DEFAULT 1,
  total_transaction_price               NUMERIC(19,4) NOT NULL,
  variable_consideration_estimate        NUMERIC(19,4) NOT NULL DEFAULT 0,
  significant_financing_component         BOOLEAN NOT NULL DEFAULT false,
  status                                    revenue_contract_status NOT NULL DEFAULT 'active',
  ai_confidence                              NUMERIC(5,4) NULL,
  ai_decision_id                              BIGINT NULL REFERENCES ai_decisions(id),
  created_by                                   BIGINT NULL REFERENCES users(id),
  updated_by                                    BIGINT NULL REFERENCES users(id),
  created_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                      TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                       TIMESTAMPTZ NULL,
  CONSTRAINT uq_rc_number UNIQUE (company_id, contract_number),
  CONSTRAINT chk_rc_dates CHECK (contract_end_date IS NULL OR contract_end_date >= contract_start_date)
);
CREATE INDEX idx_rc_company ON revenue_contracts(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_rc_customer ON revenue_contracts(customer_id);
CREATE INDEX idx_rc_source ON revenue_contracts(source_type, source_id);

-- ============================================================
-- performance_obligations — IFRS 15 Steps 2 & 4
-- ============================================================
CREATE TABLE performance_obligations (
  id                            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                    BIGINT NOT NULL REFERENCES companies(id),
  revenue_contract_id            BIGINT NOT NULL REFERENCES revenue_contracts(id) ON DELETE CASCADE,
  invoice_item_id                 BIGINT NULL REFERENCES invoice_items(id),
  sales_order_item_id              BIGINT NULL REFERENCES sales_order_items(id),
  product_id                        BIGINT NOT NULL REFERENCES products(id),
  description                        TEXT NOT NULL,
  is_distinct                         BOOLEAN NOT NULL DEFAULT true,
  standalone_selling_price             NUMERIC(19,4) NOT NULL,
  allocation_method                     allocation_method_enum NOT NULL DEFAULT 'single_obligation',
  allocated_transaction_price            NUMERIC(19,4) NOT NULL,
  recognition_pattern                     performance_obligation_pattern NOT NULL,
  satisfaction_status                      performance_obligation_status NOT NULL DEFAULT 'not_started',
  revenue_account_id                        BIGINT NOT NULL REFERENCES accounts(id),
  deferred_revenue_account_id                BIGINT NOT NULL REFERENCES accounts(id),
  recognized_amount_to_date                   NUMERIC(19,4) NOT NULL DEFAULT 0,
  ai_confidence                                NUMERIC(5,4) NULL,
  ai_decision_id                                BIGINT NULL REFERENCES ai_decisions(id),
  created_by                                     BIGINT NULL REFERENCES users(id),
  updated_by                                      BIGINT NULL REFERENCES users(id),
  created_at                                       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                         TIMESTAMPTZ NULL,
  CONSTRAINT chk_po_recognized_not_over CHECK (recognized_amount_to_date <= allocated_transaction_price)
);
CREATE INDEX idx_po_contract ON performance_obligations(revenue_contract_id);
CREATE INDEX idx_po_company ON performance_obligations(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_po_invoice_item ON performance_obligations(invoice_item_id);

-- Allocation completeness: a contract's obligations may never sum to more than its transaction price
-- (IFRS 15 Step 4). Under-allocation is legal mid-drafting (not every line finalized yet); over-allocation
-- is not, at any time.
CREATE OR REPLACE FUNCTION fn_check_allocation_completeness() RETURNS TRIGGER AS $$
DECLARE v_total NUMERIC(19,4); v_contract_total NUMERIC(19,4);
BEGIN
  SELECT SUM(allocated_transaction_price) INTO v_total FROM performance_obligations
    WHERE revenue_contract_id = NEW.revenue_contract_id AND deleted_at IS NULL;
  SELECT total_transaction_price INTO v_contract_total FROM revenue_contracts WHERE id = NEW.revenue_contract_id;
  IF v_total > v_contract_total THEN
    RAISE EXCEPTION 'performance_obligations: allocated total % exceeds contract % transaction price %',
      v_total, NEW.revenue_contract_id, v_contract_total;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_po_allocation_completeness
  AFTER INSERT OR UPDATE ON performance_obligations
  FOR EACH ROW EXECUTE FUNCTION fn_check_allocation_completeness();

-- ============================================================
-- revenue_schedules — IFRS 15 Step 5, plan header (one per performance obligation)
-- ============================================================
CREATE TABLE revenue_schedules (
  id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                  BIGINT NOT NULL REFERENCES companies(id),
  performance_obligation_id    BIGINT NOT NULL REFERENCES performance_obligations(id) ON DELETE CASCADE,
  schedule_type                 performance_obligation_pattern NOT NULL,
  period_frequency               VARCHAR(20) NOT NULL DEFAULT 'monthly',  -- monthly|quarterly|milestone|usage
  start_date                       DATE NOT NULL,
  end_date                           DATE NULL,
  total_amount                        NUMERIC(19,4) NOT NULL,
  recognized_amount_to_date            NUMERIC(19,4) NOT NULL DEFAULT 0,
  status                                 revenue_schedule_status NOT NULL DEFAULT 'draft',
  ai_confidence                           NUMERIC(5,4) NULL,
  ai_decision_id                           BIGINT NULL REFERENCES ai_decisions(id),
  approved_by                               BIGINT NULL REFERENCES users(id),
  approved_at                                TIMESTAMPTZ NULL,
  superseded_by_schedule_id                   BIGINT NULL REFERENCES revenue_schedules(id),
  created_by                                   BIGINT NULL REFERENCES users(id),
  updated_by                                    BIGINT NULL REFERENCES users(id),
  created_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                      TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                                       TIMESTAMPTZ NULL
);
CREATE INDEX idx_rs_obligation ON revenue_schedules(performance_obligation_id);
CREATE INDEX idx_rs_company_status ON revenue_schedules(company_id, status);

-- ============================================================
-- revenue_schedule_lines — one row per period/milestone/usage-window to be recognized
-- ============================================================
CREATE TABLE revenue_schedule_lines (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  revenue_schedule_id     BIGINT NOT NULL REFERENCES revenue_schedules(id) ON DELETE CASCADE,
  line_number              SMALLINT NOT NULL,
  period_start               DATE NOT NULL,
  period_end                  DATE NOT NULL,
  milestone_name                VARCHAR(160) NULL,
  scheduled_amount                NUMERIC(19,4) NOT NULL,
  recognition_basis_detail          JSONB NOT NULL DEFAULT '{}',  -- e.g. {"usage_units": 48000, "pct_complete": 0.35}
  recognized_amount                  NUMERIC(19,4) NULL,
  status                                revenue_schedule_line_status NOT NULL DEFAULT 'scheduled',
  journal_entry_id                       BIGINT NULL REFERENCES journal_entries(id),
  ai_confidence                            NUMERIC(5,4) NULL,
  ai_decision_id                            BIGINT NULL REFERENCES ai_decisions(id),
  recognized_at                              TIMESTAMPTZ NULL,
  recognized_by                                BIGINT NULL REFERENCES users(id),  -- NULL = posted by the scheduler
  created_at                                    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_rsl_line UNIQUE (revenue_schedule_id, line_number),
  CONSTRAINT chk_rsl_period CHECK (period_end >= period_start)
);
CREATE INDEX idx_rsl_schedule ON revenue_schedule_lines(revenue_schedule_id);
CREATE INDEX idx_rsl_due ON revenue_schedule_lines(company_id, period_end) WHERE status = 'scheduled';
CREATE INDEX idx_rsl_journal ON revenue_schedule_lines(journal_entry_id) WHERE journal_entry_id IS NOT NULL;

-- ============================================================
-- contract_modifications — IFRS 15.18-21
-- ============================================================
CREATE TABLE contract_modifications (
  id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                BIGINT NOT NULL REFERENCES companies(id),
  revenue_contract_id        BIGINT NOT NULL REFERENCES revenue_contracts(id),
  modification_date            DATE NOT NULL,
  modification_type              contract_modification_type NOT NULL,
  description                      TEXT NOT NULL,
  price_change_amount                NUMERIC(19,4) NOT NULL DEFAULT 0,
  cumulative_catch_up_amount           NUMERIC(19,4) NOT NULL DEFAULT 0,
  new_contract_id                        BIGINT NULL REFERENCES revenue_contracts(id),
  ai_confidence                            NUMERIC(5,4) NULL,
  ai_decision_id                            BIGINT NULL REFERENCES ai_decisions(id),
  approved_by                                BIGINT NULL REFERENCES users(id),
  approved_at                                  TIMESTAMPTZ NULL,
  created_by                                    BIGINT NULL REFERENCES users(id),
  created_at                                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                                      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_cm_contract ON contract_modifications(revenue_contract_id);
```

## Chart of Accounts additions used throughout this document

| Code | Name | Nature | Role in this workflow |
|---|---|---|---|
| 2210 | Deferred Revenue — Current | Liability | Contract-liability balance expected to recognize within 12 months |
| 2220 | Deferred Revenue — Non-current | Liability | Portion of a multi-year contract's deferral due beyond 12 months |
| 4200 | Subscription Revenue | Revenue | Credited on recognition of `over_time_straight_line`/`over_time_usage_based` obligations |
| 4300 | Service Revenue — Milestone/Professional Services | Revenue | Credited on recognition of `point_in_time_on_completion`/`over_time_milestone_output` obligations |

`4100 · Sales Revenue` and `1120 · Accounts Receivable — Control`, both already canonical (Journal Entries
submodule's own worked example), are unchanged and reused exactly as defined there.

## Permissions introduced

| Permission key | Grants | Default roles |
|---|---|---|
| `accounting.revrec.read` | View contracts, obligations, schedules, and their lines | Accountant, Senior Accountant, Finance Manager, CFO, Auditor, Read Only |
| `accounting.revrec.propose` | AI service-account-only: create/update a `revenue_contracts`/`performance_obligations`/`revenue_schedules` row in `draft` | (service account `ai_general_accountant` only — no human role holds this; a human proposes through the ordinary manual-entry UI, not this key) |
| `accounting.revrec.approve` | Transition a `revenue_schedules` row `pending_approval → active`; approve a `contract_modifications` row | Finance Manager, CFO, Owner |
| `accounting.revrec.override` | Manually adjust a `revenue_schedule_lines.scheduled_amount` before it posts, or mark a line `skipped` | Finance Manager, CFO |
| `accounting.revrec.modify_contract` | Record a `contract_modifications` row against an active contract | Senior Accountant, Finance Manager |

# Journal Entries Produced

Two entry templates cover every posting this workflow produces; a third handles the modification case.

## 1. Billing entry (at invoice posting — unchanged `entry_type`, changed credit account)

This is the **existing** automatic invoice-posting template (Journal Entries / Sales submodules), with one
precise modification: for any `invoice_items` line whose paired `performance_obligations.recognition_pattern
<> 'point_in_time_at_invoice'`, the credit resolves to that obligation's `deferred_revenue_account_id`
instead of the product's ordinary `revenue_account_id`. Every other line on the same invoice (tax, a
point-in-time line with no deferral) posts exactly as the Journal Entries submodule already specifies.

| Line | Account | Debit | Credit |
|---|---|---|---|
| 1 | 1120 · Accounts Receivable — Control | *invoice total* | — |
| 2 | 2210 · Deferred Revenue — Current *(or 2220 for the non-current portion)* | — | *sum of allocated price for every deferred line* |
| 3 (if any point-in-time line exists on the same invoice) | 4100/4300 · Revenue | — | *that line's allocated price* |
| 4 (if VAT-registered) | 2310 · Output VAT Payable | — | *tax_amount* |

`entry_type = 'invoice'`, `source_module = 'sales'`, `source_type = 'invoices'`, `source_id = invoices.id` —
identical lineage to every other invoice entry; nothing about the entry's automatic, no-approval-required
posting changes, because the judgment (which account to credit) was already made and approved when the
performance obligation and its recognition pattern were set up in Steps 1–4, not at billing time.

## 2. Recognition entry (at each `revenue_schedule_lines.period_end`, or on a completion signal)

```
Dr  2210/2220 · Deferred Revenue        <scheduled_amount or recognized_amount>
    Cr  4200 · Subscription Revenue        <same amount>                      -- over_time_straight_line / over_time_usage_based
       — or —
    Cr  4300 · Service Revenue — Milestone <same amount>                       -- point_in_time_on_completion / over_time_milestone_output / over_time_cost_to_cost_input
```

`entry_type = 'revenue_recognition'`, `source_module = 'accounting'`, `source_type =
'revenue_schedule_lines'`, `source_id = revenue_schedule_lines.id`, `ai_generated = true`,
`ai_confidence` = the line's own confidence, `memo` auto-populated with the contract number and obligation
description (e.g., `"Revenue recognition — RC-2026-0771, Boulevard Suite Enterprise Annual, month 4 of 12"`).
Per the Approval Workflow routing table this joins `'depreciation'`/`'payroll'` in the automatic,
no-per-instance-approval bucket **only** when the amount posted equals the schedule's `scheduled_amount`
exactly; any deviation reclassifies the entry as a fresh `ai_generated` proposal requiring the platform's
standard minimum one approval level, regardless of amount (see Confidence & Escalation Rules).

## 3. Contract-modification entry (cumulative catch-up treatment only)

```
Dr / Cr  2210/2220 · Deferred Revenue          <cumulative_catch_up_amount>
    Cr / Dr  4200/4300 · Revenue                  <same amount>
```

Signed according to whether the modification increases or decreases previously-recognized revenue; always
`entry_type = 'revenue_recognition'`, always carries `source_type = 'contract_modifications'`, and — unlike
the routine periodic entry above — **always** requires human approval regardless of amount, because
classifying a modification (separate contract vs. prospective termination vs. cumulative catch-up) is
IFRS 15's own judgment call, never a mechanical calculation (see Edge Cases).

# Events Emitted/Consumed

Every state transition this workflow produces is also a fact the rest of the platform can react to without
polling `revenue_schedules` on a timer. Laravel Reverb broadcasts each emitted event on the company's
private channel (`private-company.{id}.revrec`) the instant it commits, which is what lets a Finance
Manager's dashboard show a deferred-revenue balance that visibly ticks down the moment a schedule line
posts, rather than a figure that only refreshes on the next page load.

## Consumed

| Event | Source | What this workflow does with it |
|---|---|---|
| `invoice.posted` | Sales, via the Journal Entries submodule's posting event | Primary Steps 1–4 trigger (see Trigger & Preconditions) |
| `sales_order.confirmed` | Sales | Milestone-billing-policy trigger; opens `revenue_contracts` ahead of the first invoice |
| `subscription.renewed` | Sales, recurring-billing engine | Opens a **new** `revenue_contracts` row for the renewal term |
| `service_delivery.milestone_completed` | Delivery/Projects module, or a human confirmation in the Next.js app | Fires `point_in_time_on_completion` / `over_time_milestone_output` recognition |
| `contract.amended` | Sales, an accepted change order | Starts the contract-modification sub-flow |
| `refund.created` / `credit_note.posted` | Sales | Starts the prospective-termination sub-flow when tied to an active `revenue_contracts` row (see Edge Cases) |
| `period.closed` | Month-End Close workflow (`docs/ai/workflows/MONTH_END_CLOSE.md`, Phase 10) | Triggers a final Auditor tie-out re-verification, scoped to every contract with activity inside the closed period, before that period is fully behind the company |

## Emitted

| Event | Producer | Primary Consumers | Fires when |
|---|---|---|---|
| `revrec.contract.identified` | General Accountant (Step 1) | Auditor, CFO | A `revenue_contracts` row is created or an existing one is updated by a combination |
| `revrec.obligations.allocated` | General Accountant (Steps 2 & 4) | Auditor | Every `performance_obligations` row for a contract reaches a finalized `allocated_transaction_price` |
| `revrec.schedule.proposed` | General Accountant (Step 5, draft) | Approval routing (Finance Manager or CFO inbox, per Confidence & Escalation Rules) | A `revenue_schedules` row moves `draft → pending_approval` |
| `revrec.schedule.activated` | Deterministic system action, attributed to General Accountant, on human approval | Auditor, CFO (RPO), Reporting Agent | A `revenue_schedules` row moves `pending_approval → active` |
| `revrec.period.recognized` | Deterministic scheduler (period-end) or completion-signal handler | Auditor, CFO, Compliance Agent, Reporting Agent | A `revenue_schedule_lines` row moves `scheduled/ready → recognized` and its journal entry posts |
| `revrec.obligation.satisfied` | Deterministic scheduler / completion-signal handler | CFO (RPO), Reporting Agent | A `performance_obligations` row's `satisfaction_status` moves to `satisfied` |
| `revrec.contract.completed` | Deterministic scheduler | CFO, Reporting Agent | Every obligation on a `revenue_contracts` row is `satisfied`; the contract's own `status` moves to `completed` |
| `revrec.contract.modified` | CFO-approved `contract_modifications` row | Auditor, Compliance Agent, Reporting Agent | A `contract_modifications` row's treatment is approved and applied |
| `revrec.schedule.superseded` | General Accountant, on a corrected redraft | Auditor | A `revenue_schedules` row moves `active → superseded`, `superseded_by_schedule_id` populated |

**`revrec.schedule.activated` payload example** — the human-approval-gate event, fired the instant a Finance
Manager or CFO moves a schedule from `pending_approval` to `active`:

```json
{
  "event": "revrec.schedule.activated",
  "company_id": 5210,
  "revenue_contract_id": 34410,
  "contract_number": "RC-2026-0771",
  "performance_obligation_id": 61802,
  "revenue_schedule_id": 40115,
  "schedule_type": "over_time_straight_line",
  "total_amount": "14400.0000",
  "line_count": 12,
  "approved_by": 1204,
  "approved_at": "2026-04-02T09:15:00+03:00",
  "ai_decision_id": 770003,
  "ai_confidence": 1.0000,
  "occurred_at": "2026-04-02T09:15:00+03:00"
}
```

**`revrec.period.recognized` payload example** — the highest-volume event this workflow emits once a
company has more than a handful of active schedules; every scheduled or completion-triggered posting fires
exactly one of these:

```json
{
  "event": "revrec.period.recognized",
  "company_id": 5210,
  "revenue_contract_id": 34410,
  "contract_number": "RC-2026-0771",
  "performance_obligation_id": 61802,
  "revenue_schedule_id": 40115,
  "revenue_schedule_line_id": 40124,
  "line_number": 4,
  "period_start": "2026-07-01",
  "period_end": "2026-07-31",
  "recognized_amount": "1200.0000",
  "journal_entry_id": 991005,
  "cumulative_recognized": "4800.0000",
  "remaining_deferred": "9600.0000",
  "memo": "Revenue recognition — RC-2026-0771, Boulevard Suite — Enterprise Annual, month 4 of 12",
  "posted_by": null,
  "occurred_at": "2026-08-01T00:05:11+03:00"
}
```

`posted_by: null` is not a missing field — it is the record's honest statement that no human clicked
anything for this specific posting, matching `revenue_schedule_lines.recognized_by`'s own documented
convention ("NULL = posted by the scheduler"). Auditor subscribes to every event in both tables above for
its continuous tie-out (Confidence & Escalation Rules, below); Compliance Agent specifically watches the
aggregate pattern of `revrec.period.recognized` and `revrec.schedule.activated` events across a fiscal
period before certifying disclosure completeness to Month-End Close's own Phase 5 gate; and the Reporting
Agent's scheduled disclosure generation (Worked Example, below) reads this same event stream rather than
re-deriving the rollforward from raw `journal_lines` on every run — which is what keeps disclosure
generation inside the Reporting Agent's platform-wide sub-90-second target even for a company running
thousands of active schedules concurrently.

# Confidence & Escalation Rules

Confidence in this workflow concentrates almost entirely in Steps 2 and 4 — deciding what is distinct, and
how much of the price belongs to it — because Steps 1, 3, and 5 are, for the overwhelming majority of
contracts QAYD processes, classification and arithmetic against data that already exists, not fresh
judgment. The rules below make that concentration explicit and mechanical rather than a matter of each
agent's individual discretion.

## The five-step confidence profile

| Step | Judgment involved | Confidence when… | Typical value |
|---|---|---|---|
| 1 — Identify the contract | Does this order combine with another open contract for the same customer (IFRS 15.17)? | No combination signal found | 1.00 |
| | | A combination indicator is present (shared negotiation, interdependent pricing) | 0.55–0.85 — always `suggest_only`, always cites the specific indicator found |
| 2 — Identify performance obligations | Is each promised good/service distinct? | One line, one obligation, no bundling indicator | 1.00 |
| | | A multi-line arrangement carries a bundling indicator (a discount that doesn't map cleanly to one line) | 0.65–0.90 — cites the specific indicator |
| 3 — Determine the transaction price | Variable consideration, significant financing component | Fixed price, no variable elements | 1.00 |
| | | Material variable consideration requiring a constrained estimate | 0.60–0.85 — cites the constraint methodology |
| 4 — Allocate the transaction price | Is standalone selling price observable? | Single obligation (100% allocation), or every line has an observable SSP | 1.00 |
| | | At least one line's SSP is estimated (adjusted-market-assessment / expected-cost-plus-margin / residual) | 0.55–0.85 — always cites the estimation method and its inputs |
| 5 — Build the recognition schedule | Pattern selection and period amounts | Pattern follows directly from `products.revenue_recognition_method`; amounts are arithmetic (allocated price ÷ term, or a contractual milestone %) | 1.00 |
| | | A pattern requires judgment the product default doesn't cover (e.g. choosing straight-line vs. an output method for a genuinely irregular service) | 0.60–0.80 — always routed to Finance Manager review regardless of the number |

`revenue_schedules.ai_confidence` is the **minimum**, not the average, of its contributing Step 1/2/4
confidences — a single weak link caps the whole schedule's confidence even when every other step scored a
clean 1.00, because IFRS 15's five steps are a dependency chain: a wrong Step 2 boundary invalidates a
perfect Step 4 allocation built on top of it. This is why both of RC-2026-0771's schedules carry
`ai_confidence = 1.0000` in the Worked Example below — Step 1 found no combination candidate, Step 2's two
lines were each independently distinct with no bundling indicator present, and Step 4's allocation used
fully observable standalone selling prices for both lines — while the illustrative access-control-kit bundle
a few paragraphs down caps out at 0.81 the moment Step 4 has to lean on an external market comparable for
just one line, regardless of how clean Steps 1, 2, and 3 were for that same contract.

## The two confidence scales, reconciled

Two confidence representations coexist by platform convention, and this workflow uses both precisely where
each is canonical. `ai_tasks.confidence` and every workflow-owned `ai_confidence` column
(`revenue_contracts`, `performance_obligations`, `revenue_schedules`, `revenue_schedule_lines`,
`contract_modifications`) store a `0.0000`–`1.0000` fraction — the per-artifact confidence of the specific
determination that row records. `ai_decisions.confidence_score`, the shared cross-agent decision ledger
every agent in the roster writes to (`CFO_AGENT.md`), stores the identical judgment on a `0`–`100` scale. A
row's `ai_confidence` value and the `confidence_score` on its paired `ai_decisions` row (joined via
`ai_decision_id`) always satisfy `confidence_score = ai_confidence × 100` — the same determination expressed
on the ledger's own scale, never independently re-estimated.

## Materiality-gated approval routing

`ai_agent_settings.thresholds.revrec_schedule_cfo_floor` (platform default: KWD 10,000.0000 aggregate
contract `total_transaction_price`; tightenable per company, never loosenable past a platform ceiling — the
same governance rule every other entry in `ai_agent_settings.thresholds` follows) decides who the one
mandatory human approval on schedule activation must be:

- **Below the floor:** Finance Manager (or higher) alone is a sufficient approver of record.
- **At or above the floor:** the CFO (or Owner) is required as the approver of record. A Finance Manager or
  Senior Accountant may still review and recommend first, but the schedule cannot reach `active` on their
  approval alone.

Marasi PropTech W.L.L. has set its floor at KWD 15,000.0000. RC-2026-0771's KWD 16,200.0000 total
transaction price clears it — which is why CFO Dana Al-Rashed, not Senior Accountant Omar Al-Fadhli who
reviewed first, is the `approved_by` on both of its schedules in the Worked Example below.

## A judgment call, worked: non-observable standalone selling price

RC-2026-0771 itself never needs this path — both of its lines have observable SSPs. A different, illustrative
Marasi deal makes the non-observable case concrete: a bundled "Boulevard Suite + access-control hardware
kit" offer, where the kit has never been sold standalone to this customer segment. General Accountant's
Step 4 proposal for that bundle's kit line:

```json
{
  "decision_id": "b3e9a1c4-7f2d-4a11-9c3e-51a2f6d8e930",
  "agent": "general_accountant",
  "task_type": "revrec.obligation_allocation.draft",
  "confidence": 0.81,
  "autonomy_applied": "suggest_only",
  "reasoning": "Line 2 (access-control hardware kit bundled with the Boulevard Suite Enterprise annual plan) has no standalone sales history for this customer segment — the kit has only ever been given away as part of a bundle. Adjusted-market-assessment applied using three comparable third-party access-control kits at similar spec (KWD 640.000-780.000 retail), yielding an estimated SSP of KWD 700.0000. Allocating the KWD 15,600.0000 contract price by relative SSP (subscription KWD 14,400.0000 observable + kit KWD 700.0000 estimated, total reference SSP KWD 15,100.0000) gives the kit KWD 723.1800 and the subscription KWD 14,876.8200.",
  "proposed_action": {
    "type": "performance_obligation.allocate",
    "endpoint": "POST /api/v1/accounting/revrec/performance-obligations/{id}/allocate",
    "permission_required": "accounting.revrec.propose",
    "payload": {
      "allocation_method": "adjusted_market_assessment",
      "standalone_selling_price": "700.0000",
      "allocated_transaction_price": "723.1800"
    }
  },
  "sources": [
    { "type": "invoice_items", "id": 771904 },
    { "type": "market_comparable", "label": "3 comparable access-control kits, KWD 640-780 retail", "sample_size": 3 }
  ],
  "requires_approval": true,
  "risk_flags": ["non_observable_ssp"],
  "created_at": "2026-06-11T10:02:44+03:00"
}
```

Confidence is capped below the observable-SSP band specifically because the estimate rests on external
market comparables rather than this company's own transaction history — the identical caution the Forecast
Agent and CFO Agent apply to any estimate without a matching track record in `ai_memory` (see those agents'
own documents). `risk_flags: ["non_observable_ssp"]` is what routes this proposal to the Escalation Ladder
below rather than the fast, one-click path a fully observable allocation gets.

## Escalation ladder

| Condition | Escalates to | Timing |
|---|---|---|
| Contract-combination indicator found (Step 1) | Finance Manager always, regardless of confidence; CFO cc'd if either contract involved exceeds the materiality floor | Same business day as detection |
| Non-observable SSP used for any obligation (Step 4) | Finance Manager; CFO required as a co-approver if that line's `ai_confidence` < 0.70 | Before schedule activation — activation is blocked without it |
| Schedule activation, contract at/above the materiality floor | CFO (or Owner) as approver of record | Per SLAs & Timing |
| Deviation from an approved schedule line, below the deviation floor (see below) | Finance Manager, ordinary suggest-only review | Before the next `period_end` |
| Deviation from an approved schedule line, at/above the deviation floor | CFO | Before the next `period_end`; blocks that specific line's posting until resolved |
| Compliance classification-consistency dual-pass disagreement on a `contract_modifications` row | Tax Advisor and CFO jointly — never resolved to either classification silently | Immediate |
| Auditor `control_violation`-category tie-out finding (schedule-to-ledger drift) | Finance Manager and CFO immediately | Immediate, per the Auditor's platform-wide guardrail (never softened by precedent) |
| Auditor `balance_integrity` soft-signal finding (timing-lag variance, not a rule violation) | Finance Manager, next business day | Routine review cadence |
| A `pending_approval` schedule sits unreviewed past its SLA | The named approver, then their manager role | Per SLAs & Timing |
| A contract's `customers.credit_check_status` fails after its schedule is already `active` | Finance Manager, informational only — recognition continues per Edge Cases | Same business day |

## Deviations from an approved schedule

A schedule's periodic posting is deterministic exactly until the moment a period's actual figure no longer
matches its `scheduled_amount` — at that instant General Accountant re-enters the loop as if drafting a
fresh proposal, per the rule already established in Orchestration. Two sources dominate in practice:

- **A `usage_based` line's metered actual differs from the estimate baked into the schedule at drafting
  time.** The scheduler does not silently substitute the real figure; it drafts a fresh
  `revrec_period_recognition` proposal at the corrected amount, confidence scored on how the metering source
  itself is trusted (see Edge Cases), and that proposal — not the original `scheduled_amount` — is what a
  human reviews before the line moves to `recognized`.
- **A `milestone_output` line's completion percentage is revised** by whoever owns the underlying delivery
  (a project manager marking a milestone 60% rather than 100% complete, with the remainder completing
  later). The revision splits the original line into what was actually earned now and a fresh `scheduled`
  line for the remainder — never a silent overwrite of the original percentage.

Materiality governs escalation here the same way it governs schedule activation.
`ai_agent_settings.thresholds.revrec_deviation_cfo_floor` (platform default: the lesser of KWD 500.0000 or
5% of the obligation's total `allocated_transaction_price`) routes a deviation below that bound to the
Finance Manager as an ordinary suggest-only correction, and at or above it to the CFO — reusing the same
structural pattern as `revrec_schedule_cfo_floor` rather than inventing an unrelated review path for what
is, structurally, the same class of decision: how much money moved from what was expected to what actually
happened, and does someone with sufficient authority need to see it before it becomes a ledger fact.

# Failure, Retry & Rollback

**Failure is task-scoped, not run-scoped, wherever the underlying unit of work allows it.** A single
`revenue_schedule_lines` row failing to post — the target fiscal period closed unexpectedly between
schedule activation and `period_end`, the line's `deferred_revenue_account_id` was deactivated, a
transient database contention error — never fails the whole `revenue_schedules` row, and never touches any
other contract's schedule. The line retries automatically with exponential backoff up to the platform
default `max_attempts` of 3, incrementing an `ai_tasks.output.attempt_count`-style counter; only once
retries are exhausted does the line move to a held state and a human is notified with the specific
`error_message`, while every other due line across the company's other active schedules posts on schedule,
unaffected.

**The periodic posting is a Laravel-native scheduled job, not a FastAPI AI-engine call.** Because the
General Accountant's confidence-scored judgment already happened once, at schedule-drafting time, and the
one human approval already happened once, at activation, executing an *already-approved* schedule line on
its `period_end` is deterministic system behavior — a Laravel queue worker reading `revenue_schedule_lines`
where `status = 'scheduled' AND period_end <= today` and posting through the ordinary journal-posting
service, under a service-account identity attributed to the General Accountant for lineage. An AI-layer
(FastAPI) outage therefore never blocks a scheduled or completion-eligible line from posting on time; only a
Laravel application outage would, and that already falls under the platform's own infrastructure SLAs
rather than something this workflow needs to re-solve. A completion-triggered line
(`point_in_time_on_completion`, `over_time_milestone_output`) is the one exception worth naming explicitly:
its trigger is an *event* (`service_delivery.milestone_completed`), not a calendar rollover, so it posts the
moment that event is received and validated, independent of the nightly scheduled-job cadence entirely.

**Every write this workflow's agents make is idempotent by construction.** A retried
`propose_revenue_schedule` call after a timeout does not produce a second `draft` schedule for the same
performance obligation (a partial unique index on `performance_obligation_id` where `status IN ('draft',
'pending_approval', 'active')` enforces at most one live schedule per obligation at a time). A retried
period-end posting run for the same `revenue_schedule_lines.id` cannot double-post: the posting service
transitions `status` from `scheduled`/`ready` to `recognized` inside the same transaction that creates the
journal entry, and a second attempt against a row already `recognized` is a no-op that returns the existing
`journal_entry_id` rather than creating a duplicate — the identical idempotency-key discipline every other
AI-agent write in the platform uses.

**Rollback never means deletion — this workflow adds three of its own state machines to the platform's
existing immutability rule, not an exception to it:**

- **A `draft` or `pending_approval` schedule** can be freely edited or discarded — no ledger impact yet,
  because nothing has posted.
- **An `active` schedule with a still-`scheduled` line** can have that specific line's `scheduled_amount`
  adjusted (`accounting.revrec.override`) or marked `skipped`, before it posts — never after.
- **A `recognized` line discovered to be wrong** is never edited or deleted. A correcting
  `revenue_schedule_lines` row is inserted at the next `line_number`, its `recognition_basis_detail` citing
  `{"correction_of_line": <original id>, "reason": "..."}`, and posts through the ordinary
  human-approval path for a deviation (Confidence & Escalation Rules) in the current open period — exactly
  the Journal Entries submodule's own reversing/adjusting-entry convention, applied at the schedule-line
  grain. The original line's `status` may move to `adjusted` as an informational flag that a correction
  exists downstream of it; the line itself, and the journal entry it produced, remain immutable and fully
  queryable.
- **An entire `active` schedule discovered to be fundamentally wrong** (the wrong recognition pattern was
  selected at drafting time, for instance) is never bulk-reversed. Its `status` moves to `superseded`
  (`superseded_by_schedule_id` populated), a new schedule is drafted correctly and carries forward
  `recognized_amount_to_date` from where the superseded one left off, and every line the superseded schedule
  already posted stands exactly as posted — only the *forward-looking* remainder is corrected, never the
  historical fact of what was already recognized.
- **A contract terminated mid-schedule** (`contract_modifications.modification_type =
  'prospective_termination'`) moves every remaining `scheduled` line on every affected schedule to
  `cancelled` — not deleted, permanently visible as "was scheduled, then cancelled by modification #NNN."
  Whether the remaining allocated-but-unrecognized balance is recognized immediately (the customer forfeits
  the right to future service; control of "the right to access" is deemed to have effectively transferred in
  full) or reversed against a refund (the customer is owed money back) is itself the judgment
  `contract_modifications` exists to record, and it is never assumed by the system in either direction — a
  human resolves it explicitly as part of approving the modification.

**A failed or interrupted run never leaves a partial, malformed artifact.** Nothing in this workflow
persists a financial fact until its own terminal write succeeds — a draft schedule is not `active` until a
human approves it; a line is not `recognized` until its journal entry has actually posted in the same
transaction. A server restart or queue outage mid-run resumes from the last completed
`revenue_schedule_lines` row rather than reprocessing already-posted work or leaving a half-posted entry
behind.

# SLAs & Timing

| Step / Phase | Target elapsed time | Escalation if missed |
|---|---|---|
| Steps 1–4 drafting, ordinary case (observable SSP, no combination) | p95 < 30 seconds from the triggering event — reading already-posted invoice/order fields, no independent estimation required | Investigated as a system defect if it regularly exceeds 2 minutes |
| Steps 1–4 drafting, non-observable-SSP or combination case | p95 < 5 minutes — a genuine retrieval-and-estimation pass against `ai_memory` and comparable history | Escalates to the review queue as stale after 24h unreviewed |
| Schedule activation, below the materiality floor | Target same business day the schedule reaches `pending_approval`; SLA ceiling 2 business days | Escalates to the named Finance Manager's manager after 48h |
| Schedule activation, at/above the materiality floor (CFO required) | Target ≤ 2 business days; SLA ceiling 5 business days | Escalates per the Escalation Ladder above |
| Periodic posting (calendar-scheduled lines) | The nightly `revenue-recognition:post-due-lines` job runs at 00:05 in the company's configured timezone; every eligible line posts within that run | A line still `scheduled` more than 24h past its `period_end` is surfaced to Finance Manager as a completeness gap feeding Month-End Close's own Phase 1 sweep |
| Completion-triggered posting (`point_in_time_on_completion`, `over_time_milestone_output`) | p95 < 2 minutes from a validated `service_delivery.milestone_completed` event | Investigated as a system defect if it regularly exceeds 15 minutes |
| Auditor continuous tie-out | Runs hourly for companies with same-day activity, and always synchronously on `period.closed` | A `control_violation` finding is surfaced within the same hourly cycle it is detected, never batched to the next day |
| Compliance disclosure-completeness check | Synchronous, target < 5 seconds — matches Month-End Close's own Phase 5 gate target exactly, since this check is one of the inputs to that gate | A gap found here blocks Month-End Close's Phase 5 the same way any other blocking compliance flag does |
| Reporting Agent disclosure generation (rollforward + RPO) | p95 < 90 seconds, matching the Reporting Agent's platform-wide generation-performance target | Investigated as a system defect if it regularly exceeds 5 minutes |
| **Full cycle, ordinary contract (trigger → schedule active)** | **Target same business day for contracts below the materiality floor; ≤ 2 business days for contracts requiring CFO approval** | See Escalation Ladder |

These targets are deliberately tight on the deterministic steps (drafting, posting, disclosure generation)
and deliberately loose on the one genuinely human step (approval) — the workflow's entire design goal is
that a contract's *mechanical* journey from signature to an active, self-executing schedule should be
measured in seconds and minutes, so that the only time a human actually spends is the minute or two it takes
to read a one-click-clean proposal, or the longer, warranted review a flagged, non-observable estimate
actually deserves.

# Worked Example

**Company:** Marasi PropTech W.L.L. (Kuwait, base currency KWD, `company_id` 5210), publisher of
**Boulevard Suite** — a property- and facilities-management SaaS product sold in Starter, Growth, and
Enterprise tiers, plus one-time onboarding/implementation services and custom-integration projects.
Marasi's own `revrec_schedule_cfo_floor` is KWD 15,000.0000; its `revrec_deviation_cfo_floor` is the platform
default (lesser of KWD 500.0000 or 5%).

## Contract RC-2026-0771 — an annual SaaS plan, recognized monthly, with a point-in-time onboarding fee

**2026-04-01 — Steps 1–4.** Marina Hospitality Group W.L.L. (`customer_id` 88342) signs a 12-month
Boulevard Suite Enterprise plan through `sales_orders#71190`. General Accountant's Step 1 check finds no
other open contract for this customer to combine with and creates `revenue_contracts#34410`:
`contract_number = 'RC-2026-0771'`, `contract_start_date = '2026-04-01'`, `contract_end_date =
'2027-03-31'`, `currency_code = 'KWD'`, `total_transaction_price = 16200.0000`, `ai_confidence = 1.0000`
(`ai_decisions#770001`, `decision_type = 'revrec_contract_identification'`, `confidence_score = 100.00`).
Step 2 finds two distinct promises on `sales_order_items`: the annual subscription itself, and a one-time
onboarding/data-migration package — each independently useful to the customer and separately identifiable,
so each becomes its own `performance_obligations` row. Step 3: the transaction price is the fixed KWD
16,200.0000 total, no variable consideration, no financing component (the customer pays the full annual
amount upfront under the plan's standard terms, not materially different from monthly-equivalent pricing,
so `significant_financing_component = false`). Step 4: both lines have observable standalone selling prices
— Marasi has sold the Enterprise annual tier standalone at KWD 14,400.0000 and the onboarding package
standalone at KWD 1,800.0000 to dozens of other customers — and their sum equals the contract price exactly,
so allocation is 1:1 with no bundle discount to spread:

| `performance_obligations` | SSP | `allocation_method` | `allocated_transaction_price` | `recognition_pattern` | Revenue account |
|---|---|---|---|---|---|
| #61802 "Boulevard Suite — Enterprise plan, annual access" | 14,400.0000 | `observable_ssp` | 14,400.0000 | `over_time_straight_line` | 4200 |
| #61803 "Boulevard Suite — Enterprise onboarding & data migration" | 1,800.0000 | `observable_ssp` | 1,800.0000 | `point_in_time_on_completion` | 4300 |

Both rows carry `ai_confidence = 1.0000` (`ai_decisions#770002`, `decision_type =
'revrec_obligation_allocation'`, `confidence_score = 100.00`) — the trigger `fn_check_allocation_completeness`
confirms `14400.0000 + 1800.0000 = 16200.0000`, exactly the contract's `total_transaction_price`, with
nothing left to allocate and nothing over-allocated.

**2026-04-01, same day — billing.** `invoices#55210` (`INV-2026-04812`) posts for the full KWD 16,200.0000.
Because neither line's `recognition_pattern` is `point_in_time_at_invoice`, both credit Deferred Revenue,
not Revenue, per the Journal Entries Produced billing template:

```
JE #990001 — 2026-04-01 — source: invoices #55210
  Dr  1120 · Accounts Receivable — Control        16,200.0000
      Cr  2210 · Deferred Revenue — Current                     16,200.0000
```

**2026-04-01 — Step 5, schedule drafting.** General Accountant drafts both schedules the same day.
`revenue_schedules#40115` (for #61802): `schedule_type = 'over_time_straight_line'`, `period_frequency =
'monthly'`, `start_date = '2026-04-01'`, `end_date = '2027-03-31'`, `total_amount = 14400.0000`, twelve
`revenue_schedule_lines` rows (#40121–#40132) at KWD 1,200.0000 each — KWD 14,400.0000 divides into twelve
equal calendar months exactly, so no rounding true-up line is needed here (had the annual price instead been
KWD 14,401.0000, eleven lines would post KWD 1,200.0800 each and the twelfth KWD 1,200.0800 plus the
KWD 0.1200 remainder, so the schedule's total still ties to the allocated price to the fils). `revenue_
schedules#40116` (for #61803): `schedule_type = 'point_in_time_on_completion'`, `period_frequency =
'milestone'`, one `revenue_schedule_lines` row (#40140) with a provisional `period_end` pending the
completion signal. Both schedules move `draft → pending_approval` immediately (`ai_decisions#770003` and
`#770004`, both `confidence_score = 100.00`); because RC-2026-0771's KWD 16,200.0000 total clears Marasi's
KWD 15,000.0000 `revrec_schedule_cfo_floor`, both route to the CFO queue. Senior Accountant Omar Al-Fadhli
(`user_id` 1188) reviews first the same afternoon and recommends approval; CFO Dana Al-Rashed (`user_id`
1204) approves both on **2026-04-02 at 09:15** — a same-next-business-day turnaround, well inside the
2-business-day SLA for CFO-gated schedules. Both `revrec.schedule.activated` events fire (the payload shown
under Events Emitted/Consumed is #40115's).

**2026-04-30 → 2026-05-01 — month 1.** The nightly `revenue-recognition:post-due-lines` job finds line
#40121 (`period_end = '2026-04-30'`) due and posts it at 00:05 on 2026-05-01:

```
JE #991001 — 2026-05-01 — source: revenue_schedule_lines #40121
  Dr  2210 · Deferred Revenue — Current             1,200.0000
      Cr  4200 · Subscription Revenue                          1,200.0000
  memo: "Revenue recognition — RC-2026-0771, Boulevard Suite — Enterprise Annual, month 1 of 12"
```

**2026-05-15, 14:20 — onboarding completes.** Marasi's onboarding specialist, Yousef Al-Mutairi, marks the
data-migration and go-live milestone complete in the Next.js app — a human confirmation, per the trigger
type this obligation was always going to use, since the work is entirely internal to Marasi and there is no
external system to emit `service_delivery.milestone_completed` on its behalf. Line #40140's `period_end`
resolves to `2026-05-15`, and it posts within the 2-minute completion-triggered SLA:

```
JE #991002 — 2026-05-15 — source: revenue_schedule_lines #40140
  Dr  2210 · Deferred Revenue — Current             1,800.0000
      Cr  4300 · Service Revenue — Milestone         1,800.0000
```

`performance_obligations#61803.satisfaction_status` moves to `satisfied`, `recognized_amount_to_date =
1800.0000 = allocated_transaction_price` — fully and finally satisfied; `revrec.obligation.satisfied` fires.
Auditor's next tie-out finds nothing to flag: GL account 2210's balance for this contract (KWD 13,200.0000 —
the KWD 16,200.0000 billed less KWD 1,200.0000 and KWD 1,800.0000 already recognized) matches the sum of
every still-`scheduled` line's `scheduled_amount` (eleven remaining subscription months × KWD 1,200.0000 =
KWD 13,200.0000) exactly. Had a bookkeeper instead mis-coded an unrelated customer deposit to account 2210
outside this workflow, the next tie-out run would find that same identity broken and raise a
`balance_integrity` finding the same way Month-End Close's own `control_account_mismatch` check works.

**2026-05-31 → 06-01 and 2026-06-30 → 07-01 — months 2 and 3.** Lines #40122 and #40123 post identically to
month 1, KWD 1,200.0000 each, memos reading "month 2 of 12" and "month 3 of 12."

**2026-07-31 → 08-01 — month 4.** Line #40124 posts KWD 1,200.0000 — this is the exact posting whose
`revrec.period.recognized` payload is shown in full under Events Emitted/Consumed, and whose memo,
`"Revenue recognition — RC-2026-0771, Boulevard Suite — Enterprise Annual, month 4 of 12"`, is the same
string quoted earlier under Journal Entries Produced. Cumulative recognized across both obligations is now
KWD 6,600.0000 (KWD 1,800.0000 onboarding + four months × KWD 1,200.0000); remaining deferred is
KWD 9,600.0000 — eight remaining subscription months, exactly.

**Through 2027-03-31 — months 5 through 12.** Each remaining line posts identically on its own `period_end`.
When line #40132 (month 12) posts, `revenue_schedules#40115.recognized_amount_to_date` reaches
`14400.0000 = total_amount`; `performance_obligations#61802.satisfaction_status` moves to `satisfied`; every
obligation on `revenue_contracts#34410` is now satisfied, so its own `status` moves `active → completed` and
`revrec.contract.completed` fires. The full contract recognized exactly KWD 16,200.0000 — no more, no
less — across twelve monthly postings and one point-in-time posting, against KWD 16,200.0000 billed on a
single day eleven months and thirty days earlier.

## Variant — a milestone-output contract

Not every service Marasi sells is a subscription. `revenue_contracts#34428` (`RC-2026-0793`), signed the
same week with Falak Serviced Residences W.L.L. (`customer_id` 88399), is a single undivided
custom-integration project — Falak's own reservation system talking to Boulevard Suite over a bespoke API —
delivered against three contractual milestones, not twelve equal months. Because the three milestones are
progress measures on **one** deliverable rather than three separately identifiable promises, this contract
has exactly **one** `performance_obligations` row (#61840, `recognition_pattern = 'over_time_milestone_output'`,
`allocated_transaction_price = 9000.0000` — single obligation, no allocation question at all) and one
`revenue_schedules` row (#40150, `period_frequency = 'milestone'`) carrying three lines, each keyed to a
contractual milestone rather than a calendar date:

| Line | Milestone | % of contract | `scheduled_amount` | Completed | Trigger | Journal entry |
|---|---|---|---|---|---|---|
| #40151 | Discovery & scoping | 20% | 1,800.0000 | 2026-04-20 | `service_delivery.milestone_completed` (Projects module webhook) | JE #991101 |
| #40152 | Build & integration | 50% | 4,500.0000 | 2026-06-10 | same | JE #991102 |
| #40153 | Go-live & handover | 30% | 2,700.0000 | 2026-07-05 | same | JE #991103 |

Every line here is machine-confirmed by Marasi's own Projects module — the same module that owns the
delivery, not a third party — so each posts at `ai_confidence = 1.0000` the moment its webhook fires,
contrasted deliberately with Edge Case 10 below, where a *partner-reported* completion signal for work
Marasi cannot itself observe is never trusted at that same confidence regardless of the pattern being
identical. By 2026-07-05, cumulative recognized reaches KWD 9,000.0000 — the full transaction price — and
`revenue_contracts#34428` moves to `completed` the same day its final milestone does.

## The disclosure, rolled up

At the July 2026 month-end close, Compliance Agent's completeness check confirms every IFRS 15 disclosure
this workflow owns is populated before Month-End Close's own Phase 5 gate can pass, and the Reporting Agent
generates both required notes for Marasi's full contract book — not just the two contracts walked through
above, which contribute only a small fraction of the company-wide total:

**Deferred-revenue rollforward, July 2026:**

| | Amount (KWD) |
|---|---|
| Opening balance, 2026-07-01 | 184,200.0000 |
| + Billings in July 2026 | 41,300.0000 |
| − Recognized in July 2026 | 33,150.0000 |
| **= Closing balance, 2026-07-31** | **192,350.0000** |

**Remaining Performance Obligations, as of 2026-07-31:**

| Expected recognition | Amount (KWD) | Account |
|---|---|---|
| Within 12 months | 178,900.0000 | 2210 · Deferred Revenue — Current |
| Beyond 12 months | 13,450.0000 | 2220 · Deferred Revenue — Non-current |
| **Total RPO** | **192,350.0000** | |

Total RPO always equals the aggregate Deferred Revenue balance at the same date — the same underlying
figure, described in the two distinct formats IFRS 15.120 requires: a period-over-period rollforward, and a
maturity-banded remaining-obligation table. None of Marasi's contracts in this worked example run longer
than twelve months, so account 2220 carries no balance from either RC-2026-0771 or RC-2026-0793 specifically
— the KWD 13,450.0000 non-current balance belongs entirely to other, longer multi-year enterprise
agreements elsewhere in Marasi's book, whose schedules split each period's remaining balance between 2210
and 2220 based on whether that balance's own remaining schedule lines fall within or beyond twelve months of
the reporting date — exactly the same current/non-current test the RPO table itself applies.

# Controls & Audit

**Segregation of duties is structural, not procedural.** General Accountant drafts every contract,
obligation, and schedule in this workflow and holds no permission to approve any of them —
`accounting.revrec.approve` is never granted to the AI service account, only to Finance Manager, CFO, and
Owner. The Auditor that continuously re-verifies allocation completeness and schedule-to-ledger tie-out
holds no write permission on any table this workflow owns; it can only read and publish findings to
`ai_decisions`. No agent in this workflow's roster holds a permission that would let it both propose and
approve the same artifact, exactly the platform-wide rule every other AI agent document states and never
contradicts.

**Allocation completeness is enforced twice, at two different layers, on purpose.** The
`fn_check_allocation_completeness` trigger (Data & Tables Touched) makes an over-allocated contract
structurally impossible to persist — the database itself rejects it, regardless of what any agent proposed.
The Auditor's own continuous tie-out is a *different* control, catching what the trigger cannot: a
correctly-allocated contract whose GL account 2210/2220 balance has since drifted from the schedule
remainders' sum because something *outside* this workflow touched the account directly — a manual journal
entry, an incorrect reclassification, a bug in an unrelated module. The trigger is preventive; the tie-out is
detective. Neither substitutes for the other.

**Every state transition writes an audit log entry.** Every `revenue_contracts`, `performance_obligations`,
`revenue_schedules`, `revenue_schedule_lines`, and `contract_modifications` status change — and every
`ai_decisions` row this workflow's agents produce — writes a corresponding `audit_logs` entry (who, when,
old value, new value, reason where applicable), the same platform-wide mutation-audit rule every other
workflow in QAYD follows without exception.

**Recognized lines are tamper-evident by construction, not by a separate hashing mechanism.** A `recognized`
`revenue_schedule_lines` row's `journal_entry_id` points to an immutable, posted `journal_entries` row; the
only way to change what was recognized for a given period is the correcting-line mechanism under Failure,
Retry & Rollback, which is itself fully logged and never edits the original row. There is no code path in
this workflow that updates `recognized_amount` on a row already carrying a `journal_entry_id`.

**The RevRec packet is where an external assertion over revenue starts, not a separate deliverable built for
one.** A company with an External Auditor role configured gets read-only access to every `revenue_contracts`,
`performance_obligations`, `revenue_schedules`, and `revenue_schedule_lines` row, every
`contract_modifications` row, and the full `audit_logs` trail behind them — the same records this workflow
relied on internally, not a re-assembled summary. Completeness and cutoff — the two assertions revenue draws
the most external audit attention on — are exactly what the allocation-completeness trigger and the
period-boundary rules under Failure, Retry & Rollback are built to make provable rather than merely
asserted.

**Changing the thresholds that govern this workflow is itself a sensitive action.** Both
`revrec_schedule_cfo_floor` and `revrec_deviation_cfo_floor` live in `ai_agent_settings.thresholds`, and
changing any `ai_agent_settings` value is a `permission_changes`-class action under the platform's
non-negotiable sensitive-operations list — it always requires human approval, regardless of how the change
would affect autonomy elsewhere. A company cannot quietly raise its own materiality floor to route more
contracts around CFO review without that change itself being logged and approved.

**A contract modification's classification is never a one-pass judgment on something this consequential.**
Per Confidence & Escalation Rules, the Compliance Agent's classification-consistency check on a
`contract_modifications` row runs an LLM-grounded pass and an independent rule-engine pass against the
IFRS 15.20–21 criteria, and a `contract_modifications` row cannot proceed toward CFO approval until both
have run and their outputs — whether they agree or not — are visible to the approver. A single-pass
disagreement is never silently resolved in either direction; it escalates per the Escalation Ladder.

# Edge Cases

| # | Case | Handling |
|---|---|---|
| 1 | A combination indicator is found late — a second, later order from an existing customer turns out to meet IFRS 15.17's combination test | Step 1 re-runs on every new order for a customer with an already-open contract, not only at first creation; a newly-met combination criterion is proposed as a fresh, always-`suggest_only` combination, always routed to Finance Manager and, if either contract clears the materiality floor, to the CFO too, since combining changes the SSP-allocation base for every affected obligation |
| 2 | A mid-term tier upgrade (Growth → Enterprise) | Whether this is a separate contract (the incremental service is priced at its own SSP — a new performance obligation is simply added prospectively, no catch-up) or a termination-and-replacement (it isn't — the remaining unrecognized balance combines with the new consideration and reallocates prospectively) is exactly IFRS 15.20–21's own test; Compliance Agent's classification-consistency check exists specifically for this case |
| 3 | A customer's `credit_check_status` fails mid-contract, after the schedule is already `active` | Recognition **continues** per the approved schedule — IFRS 15 recognizes revenue based on satisfaction of performance obligations, not on collectibility, unless collection is no longer probable at all. A collectibility problem is a bad-debt-provision question for the credit/collections process, never a reason to pause an already-earned recognition |
| 4 | A `usage_based` line's metering data arrives late or is disputed | The line stays `scheduled` past its `period_end` until the figure is confirmed; by default the company's policy estimates and posts, then trues up next period once the actual figure confirms, rather than blocking Month-End Close's completeness sweep over a single late metering feed |
| 5 | A product's `revenue_recognition_method` default changes after existing contracts already reference the old method | Never retroactive. `performance_obligations.recognition_pattern` is a point-in-time snapshot of the determination made at contract inception; only contracts created after the product change pick up the new default |
| 6 | A performance obligation is later found to have been mis-classified as distinct when it should have been combined, or vice versa | If the schedule is still `draft`/`pending_approval`, simply redraft it. If already `active`, treat it as a schedule supersession (Failure, Retry & Rollback) — never a silent in-place reclassification |
| 7 | A multi-currency contract's exchange rate moves during the contract term | `revenue_contracts.exchange_rate` is captured once, at contract inception, and every recognition posts in the original contract-currency amount translated at that same original rate — IFRS 15 does not re-translate the transaction price for FX movement during recognition. Any FX exposure on the underlying AR balance itself is a distinct revaluation concern owned by the General Ledger's multi-currency rules, never double-counted here |
| 8 | A bundled free trial or free add-on inside a paid annual plan | Still gets its own `performance_obligations` row (`is_distinct = true`, `standalone_selling_price = 0.0000`, `allocated_transaction_price = 0.0000`) so it is tracked for satisfaction status, even though no journal entry ever posts a non-zero amount against it — completeness of the obligation register matters independent of whether there is money to recognize |
| 9 | A refund or mid-term cancellation | A `refunds`/`credit_notes` row tied to an active `revenue_contracts` row starts the prospective-termination sub-flow: every unposted `scheduled` line moves to `cancelled`, and the remaining deferred balance is reversed against the refund rather than recognized — always human-approved, never automatic, regardless of amount |
| 10 | A performance obligation's completion signal comes from a third party outside QAYD's visibility (a reseller or channel partner reports completion on Marasi's behalf) | The signal's source (`internal_system` / `human_confirmation` / `partner_webhook`) is itself part of `recognition_basis_detail`; a `partner_webhook` source from a new or low-trust partner is always `suggest_only` pending human confirmation, never auto-recognized at the confidence an internal, directly-observed completion (like RC-2026-0793's milestones above) would earn |
| 11 | A company's fiscal year is not month-aligned (mid-month year-end) | Standard monthly `revenue_schedule_lines` assume month-aligned periods; a mid-month fiscal boundary is out of scope for the default straight-line generator and requires a custom `period_frequency` definition — a known constraint, addressed under Future Improvements |
| 12 | The company disables the General Accountant agent, or lowers all RevRec autonomy to fully manual | A human with `accounting.revrec.approve` authors the `draft` row directly and approves it in the same action — `propose` is specifically the AI-service-account gate; a human with approval authority needs no separate proposal step to originate a schedule the agent never drafted |
| 13 | Two products share the same revenue/deferred-revenue account pair but are bundled at a non-observable combined price | Exactly the "Step 4 in the existing schema, precisely" exception: General Accountant runs its own adjusted-market-assessment, expected-cost-plus-margin, or residual estimate, always at capped, `suggest_only` confidence, exactly as the illustrative access-control-kit bundle in Confidence & Escalation Rules shows |
| 14 | A `revenue_schedule_lines.period_end` falls inside a period still `soft_close`, not yet `locked` | Permitted only under an elevated role — Finance Manager or CFO, the same tier that holds `accounting.revrec.approve` — mirroring Trigger & Preconditions' own "open, or soft_close with an elevated role" rule exactly |
| 15 | A contract carries a significant financing component (the customer prepays annually for what would otherwise be billed monthly, and the imputed interest is material) | `revenue_contracts.significant_financing_component = true` triggers General Accountant to compute an imputed discount rate; the transaction price used for Step 3/4 is the cash-equivalent price, and the financing component itself is recognized separately as interest income over the term — never folded into Subscription or Service Revenue |

# Future Improvements

- **Observable-SSP learning from the company's own history.** Build a per-company standalone-selling-price
  library from `ai_memory`, populated automatically every time a product sells alone at a clean price, so
  fewer allocations ever need to fall back to adjusted-market-assessment, expected-cost-plus-margin, or
  residual estimation in the first place.
- **Explainable classification for contract modifications.** Surface the specific IFRS 15.20–21 criterion
  text the Compliance Agent's classification-consistency check weighed most heavily, alongside its
  recommendation, so a Finance Manager or CFO reviewing a modification sees the actual standard language the
  decision turns on, not only a confidence number.
- **A continuous RPO tile, not only a period-end disclosure.** Mirroring Month-End Close's own "continuous
  close-readiness" future improvement: expose the Remaining Performance Obligations figure as a live,
  always-on dashboard number that updates with every `revrec.period.recognized` event, rather than a table
  that only materializes when the Reporting Agent runs at period-end.
- **Direct metering-pipeline integration for usage-based lines.** Connect `over_time_usage_based` schedule
  lines straight to a company's own metering/usage-ingestion webhooks, removing the manual-confirmation step
  for high-trust, high-volume SaaS usage billing entirely, while keeping the same trust-tiering Edge Case 10
  already establishes for lower-trust sources.
- **Portfolio-level recognition-risk scoring.** A Fraud-Detection-adjacent extension that looks across a
  company's entire contract book for clusters of aggressive or optimistic recognition assumptions —
  systematically short service terms, unusually front-loaded milestone weightings — rather than evaluating
  each contract only in isolation.
- **Reusable bundle templates.** Let a company pre-define a named arrangement shape (e.g. "SaaS + Onboarding")
  so Steps 2–4 short-circuit to a known-good allocation instantly for a repeat deal shape, rather than
  re-deriving the same determination from scratch on every new contract that happens to look identical to
  the last fifty.
- **Native support for non-month-aligned fiscal calendars.** Extend the schedule generator beyond the
  calendar-month assumption in Edge Case 11, so a company whose fiscal year does not break on a calendar
  month boundary gets correctly-bounded schedule lines without a manual `period_frequency` workaround.
- **Deferred-revenue forecasting.** Extend the Forecast Agent to project the RPO burn-down and future
  Deferred Revenue rollforward under a "no new sales" baseline versus a "current pipeline converts" scenario
  — directly useful for cash-flow planning and, for a company with covenant obligations tied to revenue
  visibility, board-level reporting.

# End of Document
