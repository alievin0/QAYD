# Banking Agent — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: BANKING_AGENT
---

# Purpose

The Banking Agent is the specialized AI worker that turns raw, untrusted bank data — statement files,
Open Banking feeds, scanned PDFs — into a continuously reconciled, fully explained bank ledger, without
ever holding the authority to move a single fils of the company's money. It is the concrete execution
engine behind the promise made in `docs/accounting/BANKING.md`: that a company's books and its bank's
books describe the same reality, provably, in minutes rather than at the end of a manual weekly
spreadsheet exercise.

**Framing.** The Banking Agent is not a chatbot a user asks questions of. It is a background specialist
that:

- Watches every connected `bank_accounts` row for new activity — a scheduled Open Banking sync, an
  uploaded CSV/MT940/CAMT.053 file, a scanned PDF statement — and normalizes it into
  `bank_statement_lines` the instant it arrives.
- Continuously proposes, and at high confidence commits, matches between those statement lines and the
  company's own record of what happened: `bank_transactions`, and — where no transaction was ever
  recorded internally — the upstream `receipts` (Sales) and `vendor_payments` (Purchasing) rows that
  should have produced one.
- Surfaces reconciliation adjustments (bank fees, FX differences, timing gaps, genuine variances) as
  reviewable proposals, never as silent corrections.
- Screens every new statement line and transaction for duplicates, mis-parsed charges, and
  currency/FX inconsistencies before they can pollute the ledger.
- Never initiates, approves, or releases a payment, transfer, or reconciliation-period close. Those
  remain exclusively human actions gated by the platform's Finance Manager → CEO approval chain, exactly
  as specified in **Banking Philosophy** and **Payment Processing** of `docs/accounting/BANKING.md`.

**Relationship to the agent roster.** The Banking Agent specializes the canonical **Treasury Manager**
agent (see `docs/foundation/AI_ARCHITECTURE.md` and the platform's fixed agent roster) for exactly one
domain: bank feed ingestion and reconciliation. In `ai_agents`, it is registered as its own addressable,
company-configurable agent (`agent_key = 'banking_agent'`, `parent_agent_key = 'treasury_manager'`)
because the domain is large enough — four ingestion channels, a multi-signal matching engine,
duplicate/fee/FX detection, and its own approval boundary — to warrant a dedicated specification and a
dedicated name accountants recognize in the product ("the thing that reconciles my bank feed"). Treasury
Manager's other duties — the liquidity ratio, 13-week cash-flow forecast oversight, currency-exposure
strategy, and vendor-payment-batch preparation — remain under the Treasury Manager name and are out of
scope for this document; where the two intersect it is described in **Collaboration With Other Agents**.

Traditional bank-reconciliation software waits for an accountant to open a spreadsheet once a week. The
Banking Agent works the moment a statement line exists: it ingests, scores, matches, and — for anything
it cannot resolve with certainty — hands the accountant a short, ranked worklist with a reason attached
to every entry, instead of a wall of unexplained transactions. The accountant supervises; the Banking
Agent does the matching.

# Role & Mandate

**Identity.**

| Field | Value |
|---|---|
| `agent_key` | `banking_agent` |
| Display name | Banking Agent |
| `parent_agent_key` | `treasury_manager` |
| Module | Banking & Treasury (`docs/accounting/BANKING.md`) |
| Principal type (Laravel) | `actor_type = 'ai_agent'`, one scoped service-account session per company |
| Primary mandate | Ingest bank feeds → normalize → match → propose reconciliation adjustments → detect duplicates, mis-posted charges, and FX inconsistencies — continuously, per `bank_accounts` row, per company |

**In scope / out of scope.**

| Banking Agent DOES | Banking Agent DOES NOT |
|---|---|
| Ingest and normalize `bank_statement_lines` from CSV, MT940, CAMT.053, Open Banking API, and OCR'd PDF | Initiate, approve, or release a payment, transfer, or standing order |
| Score and auto-commit high-confidence matches to `bank_transactions` | Close or reopen a `bank_reconciliations` period |
| Propose document-first matches to `receipts` / `vendor_payments` when no internal transaction exists yet | Create or edit a `bank_accounts` row, or change its authorized users |
| Auto-post known-pattern `fee` / `interest_earned` / `profit_distribution` transactions from unmatched lines | Compute the liquidity ratio, currency exposure, or 13-week cash-flow forecast (Treasury Manager / Forecast Agent) |
| Detect likely duplicate statement lines or transactions and block silently proceeding | Score payment-level fraud risk on outgoing payments (Fraud Detection) — it forwards signals, it does not decide |
| Propose reconciliation adjustments for unexplained variance | Post a journal entry directly — it proposes; the Accounting Engine (General Accountant / Laravel `JournalEntryService`) posts |
| Compute and annotate realized/unrealized FX deltas on a matched pair | Change a company's auto-commit threshold, matching window, or any other setting (Finance Manager only, via `company_ai_agent_settings`) |
| Learn per-company payee aliases and recurring-fee signatures into `ai_memory` | Read or influence any other company's data, under any circumstance |

**Mandate boundary, in one sentence.** The Banking Agent may look at every byte of bank data a company
has and propose anything a reconciliation workbench could show a human, but it autonomously commits only
the specific, narrow class of matches and postings this document defines as auto-committable (see
**Autonomy Level**) — everything else is a proposal, and every proposal is reversible until a human
accepts it.

# Autonomy Level (auto / suggest-only / requires-approval, per action)

Every action below inherits the company's configurable `auto_commit_threshold` (default 90, stored on
`company_ai_agent_settings`, mirroring the threshold `docs/accounting/BANKING.md` describes at the
module level) and one structural rule that never changes regardless of configuration: **the Banking
Agent's own AI-similarity signal can never, by itself, cross the auto-commit threshold.** At least one
deterministic rule (exact reference ≥ 45, or amount+date ≥ 30) must already have fired before the
AI-similarity contribution (capped at +10, see **Reasoning & Prompt Strategy**) is even added to the
score. Every autonomously committed match is therefore anchored in a verifiable, non-AI fact — the AI
refines, it never alone decides, a fully automatic action.

| Action | Autonomy | Auto-Commit / Trigger Condition | Approval Gate |
|---|---|---|---|
| Ingest & normalize a statement file/feed into `bank_statement_lines` | Auto | Always, on upload or scheduled sync | Blocked only if `opening_balance + Σ lines ≠ closing_balance` |
| OCR field extraction from a scanned/PDF statement | Auto per field | Field-level confidence ≥ 95 (from Document AI/OCR Agent) | Fields < 95 highlighted for manual correction before the import finalizes |
| Match a statement line to an existing `bank_transactions` row | Auto | Cumulative score ≥ threshold AND a deterministic rule ≥ 30 fired | None — reversible via unmatch while the period is open |
| Suggest a match below threshold | Suggest-only | Score < threshold | `bank.reconcile` user accepts, overrides, or splits |
| Document-first match to `receipts` / `vendor_payments` (no `bank_transactions` row exists yet) | Suggest-only, always | N/A — creating a new financial record is never auto-committed | `bank.reconcile` user confirms before the backfilled draft transaction is created |
| Auto-post a `fee` / `interest_earned` / `profit_distribution` transaction from an unmatched line | Auto | Description matches a known pattern AND amount is within the account's historical variance band | Escalates to suggest-only if the amount is anomalous vs. history |
| Flag a likely duplicate | Suggest-only, blocking | Any positive duplicate-likelihood signal | Explicit typed override required; score ≥ 95 forces a logged reason |
| Propose a reconciliation adjustment for non-zero variance | Suggest-only | Variance persists after all matching | Senior Accountant / Finance Manager approval, regardless of amount |
| Compute and annotate a realized/unrealized FX delta on a matched pair | Auto (annotation only) | Every foreign-currency match/settlement | Posting the resulting journal line still runs through standard Accounting Integration, not the Banking Agent |
| Write or update an `ai_memory` payee alias / fee signature | Auto | Every reviewed `ai_decisions` row (accepted or rejected) | None — internal learning, not a financial action |
| Change `auto_commit_threshold`, `matching_window_days`, or other company settings | Never | N/A | Finance Manager, via Settings |
| Close a `bank_reconciliations` period | Never | N/A | `bank.reconcile.close` — Finance Manager only |
| Reopen a closed `bank_reconciliations` period | Never | N/A | `bank.reconcile.reopen` — Finance Manager only |
| Initiate or approve a `transfer`, `outgoing_payment`, or `standing_orders` execution | Never — structurally blocked | N/A | Finance Manager → CEO two-key chain; the AI principal type cannot hold `bank.transfer`, `bank.payment.approve`, or `bank.payment.approve.final` |

# Inputs

The Banking Agent's inputs fall into four groups: the raw feed being ingested, the candidate pool it
matches against, per-company configuration, and the learned memory it consults before scoring.

**Raw feed inputs**

| Source | Format | Carries |
|---|---|---|
| Statement file upload | CSV, XLSX, MT940, CAMT.053 XML, PDF | Raw lines; for CSV/XLSX, a saved `import_template` column mapping keyed by `bank_accounts.bank_name` |
| Open Banking sync | ISO 20022 CAMT.053 or bank/aggregator JSON | Same shape as a structured file import, pulled via the bank's or aggregator's API under a stored `open_banking_consent_id` |
| PDF / scanned statement | Image or non-tabular PDF | Routed first to Document AI / OCR Agent; the Banking Agent receives structured candidate lines with per-field `ocr_field_confidence` |

**Candidate-pool inputs (read-only, company-scoped)**

| Table | Fields Used | Purpose |
|---|---|---|
| `bank_transactions` | `id, transaction_type, status, amount, currency_code, value_date, bank_reference, payee_payer_name, payee_payer_iban, source_document_type, source_document_id` | Primary match target; only rows with `status = 'cleared'` and no `reconciliation_id` are eligible (`idx_bank_transactions_unreconciled`) |
| `receipts` | `id, customer_id, amount, base_currency_amount, currency_code, bank_account_id, receipt_date, status, journal_entry_id` | Document-first match target when a customer receipt was recorded in Sales but its cash leg never got a `bank_transactions` row |
| `vendor_payments` | `id, vendor_id, amount, net_paid_amount, currency_code, bank_account_id, payment_date, status, bank_transaction_id` | Document-first match target when a vendor payment was recorded in Purchasing but `bank_transaction_id IS NULL` |
| `standing_orders` | `id, payee_name, payee_iban, amount, amount_formula, frequency, next_run_date` | Recurring-pattern rule signal |
| `bank_reconciliation_matches` | `rules_fired, final_score, match_method` (historical, closed periods) | Training signal for the AI-similarity model |
| `exchange_rates` | `from_currency, to_currency, rate_date, rate, source` | FX-delta computation on cross-currency matches |
| `ai_memory` | `memory_type = 'payee_alias' \| 'recurring_fee_signature'`, `value`, `embedding`, `confidence` | Learned per-company patterns consulted before/alongside deterministic scoring |

**Configuration inputs.** `company_ai_agent_settings` (full DDL in **Data Access & Tenant Scope**):
`auto_commit_threshold` (default 90), `matching_window_days` (default 2), `is_enabled`.

**Example — matching-sweep task input**, constructed by the Laravel scheduler or fired on
`bank.statement.imported`, handed to the FastAPI AI layer as the payload for a new `ai_tasks` row:

```json
{
  "ai_task": {
    "task_type": "bank_matching_sweep",
    "company_id": 7,
    "agent_key": "banking_agent",
    "trigger_source": "webhook",
    "input_ref": {
      "bank_account_id": 12,
      "statement_import_id": "e3b0c442-98fc-4e1c-8b7a-2f9d4a1c3e5f",
      "unmatched_statement_line_ids": [771231, 771244, 771247],
      "auto_commit_threshold": 90,
      "matching_window_days": 2,
      "base_currency": "KWD"
    }
  }
}
```

# Outputs (confidence + reasoning + sources)

Every Banking Agent output is written via the Laravel API as an `ai_decisions` row — never a direct
database write, see **Data Access & Tenant Scope** — and every row carries the platform-wide triple: a
numeric `confidence_score` (0–100), a human-readable `reasoning` string, a structured `reasoning_factors`
breakdown, and `source_documents` citing the exact rows the decision rests on. Four output shapes cover
everything the Banking Agent produces.

**1. Match candidate / auto-commit note**

```json
{
  "decision_type": "bank_match_candidate",
  "status": "auto_committed",
  "subject_type": "bank_statement_lines",
  "subject_id": 771201,
  "confidence_score": 100.00,
  "reasoning": "Exact bank reference match to bank_transactions #90344 (Gulf Office Supplies payment, BILL-2026-00891); amount and value_date also agree.",
  "reasoning_factors": [
    { "rule": "exact_reference_match", "weight": 45, "detail": "bank_reference 'FT2607201234' equal on both sides" },
    { "rule": "amount_date_exact",     "weight": 30, "detail": "4250.0000 KWD, value_date within 0 days" },
    { "rule": "ai_similarity",         "weight": 7,  "detail": "counterparty embedding similarity 0.94 vs. ai_memory alias" }
  ],
  "proposed_payload": { "bank_transaction_id": 90344, "match_method": "auto_rule", "final_score": 82.0 },
  "source_documents": [
    { "type": "bank_statement_lines", "id": 771201 },
    { "type": "bank_transactions", "id": 90344 }
  ]
}
```

**2. Reconciliation adjustment proposal**

```json
{
  "decision_type": "bank_reconciliation_adjustment_proposal",
  "status": "proposed",
  "subject_type": "bank_reconciliations",
  "subject_id": 4102,
  "confidence_score": 74.50,
  "reasoning": "Period variance of KWD 3.500 matches the account's recurring monthly-maintenance-fee pattern (seen 11 of the last 12 months on the 30th).",
  "reasoning_factors": [
    { "rule": "recurring_fee_pattern", "weight": 60, "detail": "11/12 month history, median KWD 3.500, this period KWD 3.500" },
    { "rule": "unexplained_after_matching", "weight": 14.5, "detail": "no remaining unmatched bank_transactions candidate" }
  ],
  "proposed_payload": {
    "transaction_type": "fee", "amount": "3.5000", "currency_code": "KWD",
    "account_code": "6410", "adjustment_reason": "Monthly account maintenance fee — recurring pattern"
  },
  "source_documents": [ { "type": "bank_statement_lines", "id": 771244 } ]
}
```

**3. Duplicate flag**

```json
{
  "decision_type": "bank_duplicate_flag",
  "status": "proposed",
  "subject_type": "bank_transactions",
  "subject_id": 90501,
  "confidence_score": 97.00,
  "reasoning": "Identical amount, value_date, and payee to bank_transactions #90490 created 2 minutes earlier on the same account.",
  "reasoning_factors": [
    { "rule": "exact_duplicate_hash", "weight": 90, "detail": "(bank_account_id, amount, value_date, payee_payer_iban) hash collision" },
    { "rule": "creation_time_proximity", "weight": 7, "detail": "120 seconds apart, same created_by user" }
  ],
  "proposed_payload": { "likely_duplicate_of": 90490, "hold_recommended": true },
  "source_documents": [ { "type": "bank_transactions", "id": 90490 }, { "type": "bank_transactions", "id": 90501 } ]
}
```

**4. Document-first backfill proposal**

```json
{
  "decision_type": "bank_match_candidate",
  "status": "proposed",
  "subject_type": "bank_statement_lines",
  "subject_id": 771247,
  "confidence_score": 68.00,
  "reasoning": "No open bank_transactions candidate, but receipts #5521 (customer KNET receipt, same amount and date, no linked bank_transaction) plausibly explains this line.",
  "reasoning_factors": [
    { "rule": "amount_date_exact", "weight": 30, "detail": "KWD 615.000, same value_date" },
    { "rule": "document_first_source_match", "weight": 30, "detail": "receipts.status='cleared' AND receipts has no bank_transactions backing it" },
    { "rule": "ai_similarity", "weight": 8, "detail": "counterparty description similarity to customer name on file" }
  ],
  "proposed_payload": {
    "backfill_bank_transaction": {
      "transaction_type": "incoming_payment", "amount": "615.0000", "currency_code": "KWD",
      "source_document_type": "receipts", "source_document_id": 5521, "ai_generated": true
    }
  },
  "source_documents": [ { "type": "bank_statement_lines", "id": 771247 }, { "type": "receipts", "id": 5521 } ]
}
```

Every `confidence_score` below 60 on a classification-style output (see **Expense Classification** in
`docs/accounting/BANKING.md`) is left blank rather than guessed, per the platform-wide rule against
false precision.

# Tools & API Access

All Banking Agent tool calls resolve to `/api/v1/banking/*` — plus two cross-module read-only calls and
the AI-layer's own decision/memory endpoints — always bearing the AI service account's Sanctum/JWT token
and the active company's `X-Company-Id` header, and always checked against the exact permission the
endpoint already requires of a human caller. The AI principal simply never holds the permissions that
would let it call the mutating, sensitive endpoints (see **Guardrails & Human Approval**).

| MCP Tool | Endpoint | Permission Required | Purpose |
|---|---|---|---|
| `banking.get_statement_import` | `GET /api/v1/banking/statement-imports/{id}` | `bank.reconcile` | Check import status, line count, OCR confidence summary |
| `banking.list_unmatched_statement_lines` | `GET /api/v1/banking/bank-accounts/{id}/reconciliation/unmatched` | `bank.reconcile` | Fetch the current unmatched pool for an account |
| `banking.list_bank_transactions` | `GET /api/v1/banking/bank-transactions` | `bank.read` | Candidate transactions: `status=cleared`, unreconciled, filtered by account/date window |
| `banking.get_bank_account` | `GET /api/v1/banking/bank-accounts/{id}` | `bank.read` | Currency, institution type, thresholds, `available_balance` context |
| `banking.list_receipts` | `GET /api/v1/sales/receipts` | `sales.read` | Document-first candidates: cleared receipts with no linked `bank_transactions` row |
| `banking.list_vendor_payments` | `GET /api/v1/purchasing/vendor-payments` | `purchasing.read` | Document-first candidates: vendor payments with `bank_transaction_id IS NULL` |
| `banking.get_exchange_rate` | `GET /api/v1/accounting/exchange-rates` | `accounting.read` | Reference rate for FX-delta computation |
| `banking.get_reconciliation` | `GET /api/v1/banking/bank-accounts/{id}/reconciliations` | `bank.read` | Period status, `variance`, `matched_line_count` |
| `banking.commit_reconciliation_match` | `POST /api/v1/banking/reconciliation-matches` | `bank.reconcile` | Commit a match — the AI principal only ever calls this for the deterministic-anchored auto-commit path (`match_method='auto_rule'`) |
| `banking.create_transaction_draft` | `POST /api/v1/banking/bank-transactions` | `bank.transaction.create` | Create a `fee` / `interest_earned` / backfilled draft; always `ai_generated=true` |
| `banking.submit_transaction` | `POST /api/v1/banking/bank-transactions/{id}/submit` | `bank.transaction.submit` | Only for adjustment-type drafts requiring Senior Accountant/Finance Manager approval |
| `ai.persist_decision` | `POST /api/v1/ai/decisions` | `ai.decision.create` | Persist any of the four output shapes in **Outputs** as an `ai_decisions` row |
| `ai.write_memory` | `POST /api/v1/ai/memory` | `ai.memory.write` | Reinforce or down-weight a payee alias / fee signature after a human review |
| `notifications.raise` | `POST /api/v1/notifications` | `notifications.create` (service-scoped) | Fire `bank.duplicate_suspected`, `bank.reconciliation.discrepancy_detected`, etc. |

**Tool-calling shape** (FastAPI → Laravel, illustrative):

```python
result = await tool_client.call(
    "banking.list_unmatched_statement_lines",
    params={"bank_account_id": 12, "cursor": None},
    company_id=7,
    agent_key="banking_agent",
)
# result.data is a page of bank_statement_lines; the graph node scores each
# against candidates fetched via banking.list_bank_transactions,
# banking.list_receipts, and banking.list_vendor_payments in parallel.
```

No tool in this table lets the Banking Agent call `POST /api/v1/banking/transfers`, any `/approve*`
endpoint, `/reconciliations/{id}/close`, or `/reconciliations/{id}/reopen` — those are absent from its
tool registry entirely, not merely permission-denied at runtime, so a prompt-injected or misconfigured
agent has no code path to reach them.

# Data Access & Tenant Scope

Every call the Banking Agent makes — tool call, database read via the Laravel API, or `ai_decisions`/
`ai_memory` write — carries the active company's `company_id` and is rejected if it doesn't. There is no
code path, prompt, or tool argument that lets the Banking Agent see or influence a second company's
data; unlike Fraud Detection's portfolio-level **Risk Analysis** (which, per `BANKING.md`, may look for
patterns *within tenant-isolation limits*, i.e., never across `company_id`), the Banking Agent has no
cross-tenant capability of any kind — its mandate is a single company's bank feed, full stop.

**Read scope (via Laravel API, company-scoped).** `bank_accounts`, `bank_transactions`,
`bank_statement_lines`, `bank_reconciliations`, `bank_reconciliation_matches`, `standing_orders`,
`receipts`, `vendor_payments`, `exchange_rates`, `company_ai_agent_settings`, `ai_memory` (own
`agent_key` only).

**Write scope (via Laravel API only — never direct SQL).** `ai_decisions`, `ai_tasks`, `ai_memory` (own
`agent_key`), draft `bank_transactions` (capped to `fee`/`interest_earned`/`profit_distribution`/backfill
types, always `ai_generated=true`), `bank_reconciliation_matches` (only the `auto_rule` path described in
**Autonomy Level**).

**Never accessed, under any circumstance.** `bank_account_users`, `bank_accounts` financial/
authorization fields (freeze, close, verify), `transfers`, `standing_orders` creation/amount edits,
`payroll_runs`, `tax_returns`, `roles`/`permissions`, `company` settings, and any table belonging to a
different `company_id`.

**Agent registry and per-company configuration.** No DDL for `ai_agents`, `ai_tasks`, `ai_decisions`, or
`ai_memory` exists yet in the platform's foundation docs beyond their names
(`docs/foundation/DATABASE_ARCHITECTURE.md`); this document defines the reference shape all agent docs
share, scoped here to how the Banking Agent uses them.

```sql
-- Platform-wide catalog of agent types (one row per canonical/specialized agent)
CREATE TABLE ai_agents (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  agent_key         VARCHAR(60)  NOT NULL UNIQUE,      -- 'banking_agent'
  display_name      VARCHAR(120) NOT NULL,             -- 'Banking Agent'
  parent_agent_key  VARCHAR(60)  NULL REFERENCES ai_agents(agent_key), -- 'treasury_manager'
  module            VARCHAR(60)  NOT NULL,             -- 'banking'
  description       TEXT NOT NULL,
  default_enabled   BOOLEAN NOT NULL DEFAULT TRUE,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Per-company enablement + tunable thresholds; the ONLY lever a company has
-- over this agent's behavior, editable exclusively by a Finance Manager
CREATE TABLE company_ai_agent_settings (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  agent_key              VARCHAR(60) NOT NULL REFERENCES ai_agents(agent_key),
  is_enabled             BOOLEAN NOT NULL DEFAULT TRUE,
  auto_commit_threshold  NUMERIC(5,2) NOT NULL DEFAULT 90,
  matching_window_days   INT NOT NULL DEFAULT 2,
  settings               JSONB NOT NULL DEFAULT '{}',
  updated_by             BIGINT NULL REFERENCES users(id),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_company_ai_agent UNIQUE (company_id, agent_key),
  CONSTRAINT chk_auto_commit_threshold CHECK (auto_commit_threshold BETWEEN 0 AND 100)
);
```

`ai_memory` isolation. Every `ai_memory` row is keyed `(company_id, agent_key, memory_type, key)` with a
`UNIQUE` constraint enforcing it; a payee alias learned for Company A's "ABC TRD CO" abbreviation is
invisible to, and never influences scoring for, any other company — even one that happens to bank with
the same institution or share the same vendor name. Per-company isolation is the same structural
guarantee `docs/foundation/AI_ARCHITECTURE.md` states platform-wide ("Memory is isolated per company"),
applied here at the row level:

```sql
CREATE TABLE ai_memory (
  id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    BIGINT NOT NULL REFERENCES companies(id),
  agent_key     VARCHAR(60)  NOT NULL,             -- 'banking_agent'
  memory_type   VARCHAR(60)  NOT NULL,             -- 'payee_alias' | 'recurring_fee_signature' | 'match_acceptance_stat'
  key           VARCHAR(200) NOT NULL,             -- e.g. the raw counterparty string
  value         JSONB NOT NULL,                    -- e.g. { "vendor_id": 881, "canonical_name": "..." }
  embedding     VECTOR(1536) NULL,                 -- pgvector; semantic similarity over descriptions
  confidence    NUMERIC(5,2) NOT NULL DEFAULT 50,
  hit_count     INT NOT NULL DEFAULT 0,
  last_used_at  TIMESTAMPTZ NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at    TIMESTAMPTZ NULL,
  CONSTRAINT uq_ai_memory_company_agent_key UNIQUE (company_id, agent_key, memory_type, key)
);
CREATE INDEX idx_ai_memory_company_agent ON ai_memory (company_id, agent_key);
```

The `ai_tasks` (per-run execution record) and `ai_decisions` (per-output confidence/reasoning envelope)
tables — the other two foundation AI tables named in `docs/foundation/DATABASE_ARCHITECTURE.md` — are
defined in full in **Reasoning & Prompt Strategy** and immediately below, respectively, scoped to the
exact columns the Banking Agent populates.

```sql
CREATE TYPE ai_decision_type AS ENUM (
  'bank_match_candidate', 'bank_match_auto_committed',
  'bank_reconciliation_adjustment_proposal', 'bank_duplicate_flag',
  'bank_fee_fx_auto_post', 'bank_statement_ocr_extraction',
  'bank_expense_classification_suggestion'
);

CREATE TYPE ai_decision_status AS ENUM (
  'proposed', 'accepted', 'rejected', 'auto_committed', 'superseded', 'expired'
);

-- The single home for every not-yet-committed (and every auto-committed)
-- Banking Agent output — the four shapes shown in full in **Outputs**.
-- Distinct from the Accounting-owned bank_reconciliation_matches, which
-- records ONLY committed matches for audit; ai_decisions records the
-- AI's full candidate/reasoning trail, committed or not.
CREATE TABLE ai_decisions (
  id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id         BIGINT NOT NULL REFERENCES companies(id),
  agent_key          VARCHAR(60) NOT NULL,               -- 'banking_agent'
  decision_type      ai_decision_type NOT NULL,
  status             ai_decision_status NOT NULL DEFAULT 'proposed',
  subject_type       VARCHAR(60) NOT NULL,               -- 'bank_statement_lines' | 'bank_transactions' | 'bank_reconciliations'
  subject_id         BIGINT NOT NULL,
  confidence_score   NUMERIC(5,2) NOT NULL CHECK (confidence_score BETWEEN 0 AND 100),
  reasoning          TEXT NOT NULL,
  reasoning_factors  JSONB NOT NULL DEFAULT '[]',
  proposed_payload   JSONB NOT NULL,
  source_documents   JSONB NOT NULL DEFAULT '[]',
  ai_task_id         BIGINT NULL REFERENCES ai_tasks(id),
  reviewed_by        BIGINT NULL REFERENCES users(id),
  reviewed_at        TIMESTAMPTZ NULL,
  review_reason      VARCHAR(500) NULL,
  expires_at         TIMESTAMPTZ NULL,
  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at         TIMESTAMPTZ NULL,

  CONSTRAINT chk_ai_decisions_review CHECK (status = 'proposed' OR reviewed_at IS NOT NULL OR status = 'auto_committed')
);
CREATE INDEX idx_ai_decisions_company_agent ON ai_decisions (company_id, agent_key);
CREATE INDEX idx_ai_decisions_subject        ON ai_decisions (subject_type, subject_id);
CREATE INDEX idx_ai_decisions_status         ON ai_decisions (company_id, status) WHERE status = 'proposed';
```

# Reasoning & Prompt Strategy

**Orchestration.** The Banking Agent runs as a LangGraph-style stateful graph inside the FastAPI AI
layer, triggered by `bank.statement.imported`, `bank.transaction.cleared`, or a scheduled sweep. Every
run is recorded as one `ai_tasks` row:

```sql
CREATE TYPE ai_task_status AS ENUM ('queued', 'running', 'completed', 'failed', 'cancelled');

CREATE TYPE ai_task_type AS ENUM (
  'bank_statement_ingest', 'bank_statement_ocr', 'bank_matching_sweep',
  'bank_duplicate_scan', 'bank_reconciliation_variance_check', 'bank_fee_fx_scan'
);

CREATE TABLE ai_tasks (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id      BIGINT NOT NULL REFERENCES companies(id),
  agent_key       VARCHAR(60) NOT NULL,             -- 'banking_agent'
  task_type       ai_task_type NOT NULL,
  status          ai_task_status NOT NULL DEFAULT 'queued',
  trigger_source  VARCHAR(60) NOT NULL,             -- 'webhook' | 'schedule' | 'user_request'
  input_ref       JSONB NOT NULL DEFAULT '{}',
  output_summary  JSONB NULL,
  started_at      TIMESTAMPTZ NULL,
  completed_at    TIMESTAMPTZ NULL,
  error_message   TEXT NULL,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_tasks_company_agent ON ai_tasks (company_id, agent_key, status);
```

The graph itself:

```text
Statement File / Open Banking Webhook / bank.transaction.cleared event
        │
        ▼
┌────────────────────────┐
│ 1. INGEST & NORMALIZE   │  → bank_statement_lines (immutable)
└───────────┬────────────┘   opening_balance + Σ lines = closing_balance? ──No──▶ block, raise bank.statement.import_failed
            │ Yes
            ▼
┌────────────────────────┐
│ 2. OCR (PDF only)       │  → Document AI / OCR Agent; per-field confidence
└───────────┬────────────┘
            ▼
┌────────────────────────┐
│ 3. CANDIDATE RETRIEVAL  │  unmatched bank_transactions ∪ receipts ∪ vendor_payments
└───────────┬────────────┘  (same bank_account_id / currency / ± matching_window_days)
            ▼
┌────────────────────────┐
│ 4. DETERMINISTIC SCORING│  exact_reference(45) + amount_date(30) + fuzzy_counterparty(15) + recurring_pattern(10)
└───────────┬────────────┘
            ▼
┌────────────────────────┐
│ 5. AI SIMILARITY BOOST  │  embedding + ai_memory lookup, additive, capped at +10
└───────────┬────────────┘
            ▼
     score ≥ auto_commit_threshold
     AND a deterministic rule ≥ 30 fired?
        │ Yes                              │ No
        ▼                                  ▼
┌────────────────────┐           ┌─────────────────────────┐
│ 6a. AUTO-COMMIT      │           │ 6b. PERSIST PROPOSAL     │  ai_decisions (status='proposed')
│ match_method=         │           └────────────┬────────────┘
│ 'auto_rule'            │                        ▼
└────────────┬───────────┘              Reconciliation Workbench (human)
             ▼                                    │ accept / override / split / reject
   bank_transactions.status='reconciled'           ▼
             │                          bank_reconciliation_matches (ai_suggested_accepted | manual | split)
             ▼                                    │
┌─────────────────────────┐                       ▼
│ 7. LEARN                 │◀──────────────────────┘
│ ai_memory reinforced or down-weighted
└──────────────────────────┘
```

**When an LLM is actually invoked.** Steps 1, 3, 4, and 6a are deterministic code — no model call, no
latency risk, fully reproducible. The LLM/embedding step is invoked only at step 5, for two narrow
purposes: (a) producing a semantic-similarity score between a statement line's `raw_description`/
`counterparty_name` and the embeddings stored in `ai_memory` for this company's known payees and fee
signatures, and (b) drafting the human-readable `reasoning` string that accompanies every output (the
`reasoning_factors` array itself is always assembled from the deterministic rule engine's actual fired
rules — the model narrates real numbers, it does not invent them).

**Anti-hallucination constraint.** The model step is a closed-candidate-set classifier, not an open
generator: it receives the already-retrieved, already-scored candidate list from steps 3/4 and may only
select among those row IDs or say "none," never emit an ID that was not in its context window. Every
`source_documents` citation is validated server-side (Laravel) against real row existence before an
`ai_decisions` row is allowed to persist — a fabricated `bank_transactions.id` fails validation and the
task is marked `failed`, not silently accepted.

**Prompt skeleton** (system message for the step-5 adjudication call):

```text
You are the Banking Agent's matching adjudicator for QAYD, company {company_id}.
You will be shown ONE unmatched bank statement line and UP TO 5 deterministic
candidates already scored by the rule engine. Your only job is to:
  1. Assign a similarity contribution (0-10) reflecting how well the
     candidate's counterparty/description matches this company's known
     payee aliases and history — never based on generic world knowledge
     about the payee's name.
  2. Write one sentence explaining the strongest signal.
You MUST reference only the candidate IDs provided. If none plausibly match,
return similarity=0 for all and say so. Never suggest a transaction ID that
was not in the candidate list.

Candidates: {candidates_json}
Statement line: {statement_line_json}
Company payee-alias memory: {ai_memory_snippet}
```

**Vector search.** `ai_memory.embedding` (pgvector, 1536-dim) stores an embedding of every previously
accepted counterparty description per company; step 5 runs a cosine-similarity
`ORDER BY embedding <=> query_embedding LIMIT 5` lookup scoped to `company_id` before the adjudication
call, so the model is grounded in this company's own history, never a cross-tenant corpus.

# Collaboration With Other Agents

| Agent | Direction | Exchange |
|---|---|---|
| **Treasury Manager** (parent) | Banking Agent → Treasury Manager | Every reconciled transaction and closed period feeds the live cash position, the liquidity ratio, and the currency-exposure table Treasury Manager owns; the Banking Agent never computes these itself |
| **Forecast Agent** | Banking Agent → Forecast Agent | Newly `cleared`/`reconciled` transactions are the actuals feed that trues up the rolling 13-week forecast each time a new one lands |
| **General Accountant** | Banking Agent → General Accountant | The Banking Agent proposes the transaction/journal shape (`fee`, `adjustment`, backfilled `incoming_payment`/`outgoing_payment`); the Accounting Engine's posting logic — attributed to General Accountant — validates and posts the balanced journal entry. The Banking Agent never posts a journal entry itself, only ever a `source_document_type`/`source_document_id` reference for General Accountant's service to resolve |
| **Fraud Detection** | Bidirectional | The Banking Agent forwards duplicate/velocity/new-payee signals discovered during matching as additional input to Fraud Detection's risk scoring on any `outgoing_payment`/`transfer_out` tied to the same payee; conversely, a live `hold_recommended=true` flag from Fraud Detection makes that transaction ineligible for auto-match until the hold clears — an anomalous transaction is never quietly reconciled away |
| **Document AI / OCR Agent** | Document AI/OCR Agent → Banking Agent | PDF/scanned statements are OCR'd upstream; the Banking Agent consumes structured `bank_statement_lines` candidates with field-level confidence, it never performs OCR itself |
| **CFO (agent)** | Banking Agent → CFO | Reconciliation summaries and duplicate/FX bulletins are source material the CFO agent synthesizes into the weekly treasury briefing |
| **Auditor** | Banking Agent → Auditor (read-only) | Every `ai_decisions` row and every `bank_reconciliation_matches` row (with `rules_fired`) is queryable by the Auditor role — "why was this matched" always has a citation-backed answer |
| **Approval Assistant** | Banking Agent → Approval Assistant | Adjustment proposals and document-first backfill proposals awaiting Senior Accountant/Finance Manager sign-off are handed to Approval Assistant for routing, reminders, and cross-module approval-queue summarization; Approval Assistant never re-scores or re-decides a Banking Agent proposal |
| **Compliance Agent** | Banking Agent → Compliance Agent | Large/unusual reconciled transactions and cooling-off-period violations are surfaced as data for the Compliance Agent's AML/CFT-adjacent review; the Banking Agent never files or interprets a regulatory report |

**End-to-end collaboration flow (statement import to closed period):**

```
Open Banking sync (Banking Agent: ingest)
   → 44/47 lines auto-matched (Banking Agent: score + commit)
   → 3 lines proposed (Banking Agent: propose; Fraud Detection: cross-check new payees)
   → Accountant resolves workbench (human, via bank.reconcile)
   → Forecast Agent trues up week-1 actuals (Forecast Agent: consume)
   → Treasury Manager recomputes liquidity ratio (Treasury Manager: consume)
   → Finance Manager closes the period (human, via bank.reconcile.close)
   → Auditor reviews the match audit trail on demand (Auditor: read ai_decisions + bank_reconciliation_matches)
```

# Guardrails & Human Approval

**RBAC grant (exact).** The Banking Agent's Laravel principal (`actor_type = 'ai_agent'`,
`agent_key = 'banking_agent'`) holds exactly:

| Permission | Held? | Scope |
|---|---|---|
| `bank.read` | Yes | Own-company, read-only |
| `bank.reconcile` | Yes | Propose + the narrow `auto_rule` auto-commit path only |
| `bank.transaction.create` | Yes | Draft only, restricted to `fee`/`interest_earned`/`profit_distribution`/backfill types, always `ai_generated=true` |
| `bank.transaction.submit` | Yes | Adjustment-type drafts only, into `pending_approval` |
| `ai.decision.create`, `ai.memory.write`, `ai.task.manage` | Yes | Own `agent_key`, own `company_id` |
| `bank.transfer` | **No** | Never grantable to the `ai_agent` principal type — checked at the principal-type level, not just the permission-grant level |
| `bank.payment.approve` / `bank.payment.approve.final` | **No** | Same structural block |
| `bank.reconcile.close` / `bank.reconcile.reopen` | **No** | Same structural block |
| `bank.account.*`, `bank.standing_order.*` | **No** | Out of mandate entirely |

This mirrors, and does not weaken, the "AI Agent" row already defined in `docs/accounting/BANKING.md`'s
permission table: routes requiring `bank.transfer` or either approval key check the authenticated
principal's *type* in addition to its permission grant, and no `ai_agent`-type principal is ever
eligible for those three keys regardless of what a misconfiguration might otherwise grant it.

**The deterministic-anchor rule, restated as a guardrail.** `match_method = 'auto_rule'` is the only
value the Banking Agent's own auto-commit path is allowed to write. `match_method =
'ai_suggested_accepted'` can only be written by the Laravel `ReconciliationService` when a human user
with `bank.reconcile` accepts a proposal — the Banking Agent itself never sets that value. In other
words, the label "AI-matched" always implies a human clicked accept; the label "auto-matched" always
implies a deterministic rule (reference, amount+date, fuzzy name, or recurring pattern) independently
cleared at least a 30-point bar before any AI polish was added. This distinction is what makes every
auto-committed row defensible to an auditor without reference to model internals.

**Approval gates (summary, cross-referenced to the module doc).**

| Gate | Who | Why |
|---|---|---|
| Reconciliation period close/reopen | Finance Manager (`bank.reconcile.close`/`.reopen`) | Locks the matched state for the period; the Banking Agent has no path to either action |
| Reconciliation adjustment posting | Senior Accountant / Finance Manager | Adjustments encode judgment, not a mechanical bank fact, per **Banking Philosophy** |
| Document-first backfill | `bank.reconcile` user | Creates a new financial record from a proposal — never silent |
| Duplicate override | Any user, but with a mandatory typed reason | Score ≥ 95 is logged to `audit_logs`; the modal cannot be dismissed without it |
| Any transfer, outgoing payment, or payroll disbursement | Finance Manager → CEO (two-key) | Out of the Banking Agent's mandate entirely; it may only observe the resulting cleared transaction afterward |
| High-value approval (≥ company threshold, default KWD 25,000) | CEO with a fresh MFA step-up | Independent of any AI involvement — applies identically whether or not the Banking Agent touched the transaction |

**Escalation, not suppression.** A Banking Agent finding that touches on possible fraud (a new payee, an
unusual amount, a velocity spike surfaced during matching) is always forwarded to Fraud Detection and,
above a risk threshold, to the Auditor — it is never resolved quietly by the Banking Agent lowering its
own confidence and moving on. Silence is not a safe failure mode for a signal this agent is
well-positioned to notice first.

# Worked Scenarios

**Scenario 1 — Statement import, mostly automatic.**

NBK Operating (KWD), `bank_account_id = 12`, receives its bi-weekly CAMT.053 Open Banking sync
(`statement_import_id: e3b0c442-98fc-4e1c-8b7a-2f9d4a1c3e5f`, period 2026-07-01 → 2026-07-15, opening
KWD 182,430.5000, closing KWD 196,880.2500, 47 lines — the same import already introduced in
`docs/accounting/BANKING.md`'s API examples).

1. **Ingest.** All 47 lines land as `bank_statement_lines`, `status='unmatched'`; the arithmetic check
   (`182430.50 + Σ lines = 196880.25`) ties out, so the import proceeds.
2. **Sweep.** The matching graph runs candidate retrieval and scoring against the account's unreconciled
   `bank_transactions`. 44 lines resolve with `exact_reference_match` (45) + `amount_date_exact` (30) ≥
   90 and auto-commit (`match_method='auto_rule'`); `bank_transactions.status` moves to `reconciled` for
   each.
3. **Line #771201** (KWD 4,250.0000, reference `FT2607201234`) matches `bank_transactions #90344` — the
   Gulf Office Supplies vendor payment against BILL-2026-00891 already on file — via exact reference;
   auto-committed (see the **Outputs** JSON example).
4. **Line #771244** (KWD 3.5000, description "MONTHLY A/C FEE") has no candidate transaction. It matches
   the account's `recurring_fee_pattern` in `ai_memory` (11 of the last 12 months, same description,
   similar amount); the Banking Agent auto-creates and posts a `fee` transaction directly (per the
   module's "originates from reconciliation, nothing to approve after the fact" rule) and links it to
   the statement line in the same operation.
5. **Line #771247** (KWD 615.0000, description "KNET TRANSFER — CUST 4471") has no candidate
   `bank_transactions` row, but `receipts #5521` (a cleared customer receipt, same amount/date, no
   linked transaction) plausibly explains it. The Banking Agent persists a **document-first backfill
   proposal** (score 68, below auto-commit) rather than acting; a Senior Accountant reviews it in the
   workbench and accepts, which creates the missing `bank_transactions` row (`ai_generated=true`,
   `source_document_type='receipts'`, `source_document_id=5521`) and commits the match in one step
   (`match_method='ai_suggested_accepted'`).
6. **Result.** `bank_reconciliations` row: `matched_line_count=47`, `unmatched_line_count=0`,
   `variance=0.0000`, `status='balanced'`. A Finance Manager closes the period via `bank.reconcile.close`.

**Scenario 2 — Cross-currency settlement, FX delta.**

A USD wire receipt clears against the USD investment account. It was booked at KWD 0.3080/USD (base KWD
15,400.0000 for USD 50,000) when the invoice was raised; the statement line shows settlement at the
bank's dealt rate of KWD 0.3065/USD. The Banking Agent matches the line to the open `bank_transactions`
row by exact reference (auto-commit), then computes the realized delta:

```
booked_base_amount    = 15,400.0000  (50,000 × 0.3080)
settled_base_amount    = 15,325.0000  (50,000 × 0.3065)
realized_fx_delta       = -75.0000    (a realized loss)
```

The Banking Agent annotates the committed match with `reasoning_factors` including this delta and a
`source_document_type='bank_transactions'` reference; it does **not** post the journal line itself. The
annotation triggers the standard **Accounting Integration** event, and General Accountant's posting
logic books Debit 7810 Realized FX Loss KWD 75.0000 / Credit the settlement's clearing line, exactly
mirroring the unrealized-revaluation shape already defined in `docs/accounting/BANKING.md`, but realized
rather than reversing.

**Scenario 3 — Ambiguous name, workbench resolution, and the learning loop.**

A later statement brings a line for KWD 4,250.0000 with counterparty text "GULF OFF SUPP TRD" — a
bank-abbreviated rendering of "Gulf Office Supplies W.L.L." for a *different* invoice than Scenario 1's.
Deterministic scoring finds no `bank_reference` match (this payment went out as a local transfer, not a
wire, so no reference survived) but does find `amount_date_exact` = 30 and `fuzzy_counterparty`
(Levenshtein/phonetic against the known payee) = 15, total 45. The AI-similarity step adds +8 from an
`ai_memory` alias entry recorded during Scenario 1's acceptance (`"GULF OFF SUPP TRD" → vendor_id:
<gulf-office-supplies>`, confidence reinforced by one prior acceptance). Total score 53 — below the
company's threshold of 90, so it is **not** auto-committed; it is queued with an explicit "why it didn't
fire" explanation (`"amount and date match; reference absent; counterparty is a known alias with only
one prior confirmation"`).

- **Accept path.** The accountant accepts the top suggestion in the workbench.
  `bank_reconciliation_matches` records `match_method='ai_suggested_accepted'`, `matched_by=<user_id>`.
  The Banking Agent increments the alias's `hit_count` and raises its `confidence` in `ai_memory` — a
  second corroborating acceptance promotes it toward eligibility as a stronger future
  `fuzzy_counterparty` signal for this specific company.
- **Reject path (alternate ending).** Instead, the accountant determines the line is unrelated and marks
  "no matching transaction" — creating a new draft transaction directly. The Banking Agent down-weights
  the alias's `confidence` rather than reinforcing it, so a single coincidental resemblance does not
  calcify into a false pattern; `ai_memory` never promotes a mapping to auto-commit-eligible strength on
  the strength of one contested acceptance.

# Metrics & Evaluation

| Metric | Definition | Target | Alert Threshold |
|---|---|---|---|
| Auto-match rate | % of statement lines auto-committed without human action | ≥ 90% by a company's second full month | < 70% sustained 2 weeks → review threshold/config |
| Auto-match precision | Post-hoc audit sample: % of auto-committed matches confirmed correct | ≥ 99.5% | < 99% → freeze auto-commit for the account pending review |
| Suggestion acceptance rate | % of suggest-only proposals accepted as-is (vs. overridden/rejected) | ≥ 80% | < 50% sustained → signals scoring miscalibration |
| False-positive duplicate rate | % of duplicate flags overridden by a human as "not a duplicate" | < 5% | > 15% → recalibrate duplicate-hash/velocity thresholds |
| Duplicate recall (sampled) | % of known/injected duplicate test cases correctly flagged | ≥ 98% | < 95% |
| OCR field accuracy (per field) | % of OCR'd fields requiring no manual correction, by field type | ≥ 97% (date/amount), ≥ 90% (description) | Field-type-specific; routed to Document AI/OCR Agent owner |
| Time-to-reconciled (p50 / p95) | Statement-line arrival → `reconciled` or resolved-in-workbench | p50 < 5 min, p95 < 4 hours | p95 > 24 hours |
| Matching sweep latency | New import/cleared-transaction event → candidate scoring complete | p95 < 30 sec (accounts < 10k unmatched lines) | p95 > 5 min |
| Reconciliation cycle time | Import → `balanced` → `closed` | Median < 2 business days | > 10 business days |
| Variance-explained rate | % of periods closing with `variance = 0` without a manual adjustment | ≥ 95% | < 80% |
| Fee/FX auto-post accuracy | % of auto-posted `fee`/`interest_earned` rows never subsequently reversed | ≥ 99% | Any cluster of reversals on one account within 7 days |
| Learning stability | Rate of `ai_memory` alias promotions later reversed by a rejection | < 2% of promoted aliases per quarter | Sudden spike → investigate poisoning/drift |
| Confidence calibration | Reliability curve: for proposals bucketed by `confidence_score`, actual acceptance rate vs. predicted | Within 5 points per decile | Persistent > 10-point gap in any decile |

All metrics are computed per company (never pooled across tenants) and surfaced on a Banking Agent
health dashboard visible to the Finance Manager and CFO roles; the confidence-calibration curve
specifically is the trigger for adjusting the model weighting in **Reasoning & Prompt Strategy**, never
for silently changing what counts as an auto-commit — the deterministic-anchor rule in **Guardrails &
Human Approval** is never relaxed by a calibration exercise.

**Evaluation cadence.** Auto-match precision is measured by a weekly stratified audit sample (minimum
50 auto-committed rows per active account per week, or all of them if fewer than 50 exist), reviewed by
a rotating Senior Accountant, with results feeding directly into the confidence-calibration metric.
Suggestion-acceptance rate and duplicate false-positive rate are computed continuously and refreshed on
the health dashboard in real time, since both derive from immediate workbench actions rather than a
sampled audit.

# Failure Modes & Edge Cases

General banking edge cases (failed payment rails, FX-rate unavailability, closed-period late
transactions, and the like) are already defined in `docs/accounting/BANKING.md`'s **Edge Cases** table
and apply unchanged here, since the Banking Agent operates inside that same module. The cases below are
specific to the agent's own ingestion, matching, and learning behavior.

| Failure Mode | Handling |
|---|---|
| OCR misreads a decimal/thousands separator (e.g. European `1.234,56` read as `1234.56`) | Field-level `ocr_field_confidence` drops on the amount field specifically; below-95 fields are never auto-committed, and the import review screen highlights exactly that field, not the whole line |
| Embedding/vector service outage | AI-similarity contribution is treated as 0 for the outage's duration; deterministic scoring and auto-commit continue unaffected — an outage degrades suggestion quality, it never blocks the deterministic path |
| `ai_memory` poisoning from one bad acceptance | A single acceptance can raise an alias's `confidence` but never past a "corroboration-pending" ceiling; only a second independent acceptance (or zero rejections over a rolling window) promotes it to full weight, and any rejection immediately down-weights regardless of prior history |
| Statement re-imported over an overlapping period (manual CSV upload racing a scheduled Open Banking sync) | The ingestion hash check (`bank_account_id, value_date, amount, direction, bank_reference, raw_description`) skips already-present lines; the Banking Agent never re-proposes a line already resolved in a prior sweep |
| Company disables the agent (`company_ai_agent_settings.is_enabled = false`) | The Banking Agent stands down entirely for that company; no ingestion pause — statements still land as `bank_statement_lines` — but zero scoring, zero auto-commit, zero proposals; the workbench reverts to fully manual, exactly as if the agent were never enabled |
| Auto-commit threshold set unrealistically low (e.g. a company sets 40) | The deterministic-anchor rule still applies — a 40-point pure-AI-similarity result still cannot auto-commit, because `ai_similarity` alone never reaches 30 (its cap is 10); the floor is structural, not merely the configured threshold |
| Runaway fee-pattern false positive (a mis-learned "recurring fee" pattern matches an unrelated legitimate transaction) | Auto-posted fee/interest transactions are rate-limited per account per day (default cap: 5 without a review checkpoint); exceeding the cap escalates every subsequent candidate that day to suggest-only regardless of pattern confidence |
| Two-sided match race (two evaluations propose different candidates for the same statement line concurrently) | Handled at the Laravel layer via `SELECT ... FOR UPDATE` on the `bank_statement_lines` row (per `BANKING.md`); the losing proposal receives `ALREADY_MATCHED` and the Banking Agent discards it rather than retrying blindly |
| A `receipts` or `vendor_payments` row is soft-deleted after the Banking Agent proposed a document-first backfill against it | The proposal is invalidated server-side on acceptance attempt (`SOURCE_DOCUMENT_DELETED`); the Banking Agent re-sweeps the line as unmatched rather than completing a stale proposal |
| Company crosses a fiscal boundary mid-reconciliation-period | Reconciliation periods follow bank statement cycles, independent of `fiscal_periods` (per `BANKING.md`); the Banking Agent's matching is entirely unaffected by fiscal-year boundaries |
| Multi-tenant query bug class | Every Banking Agent query is required to carry `company_id` by construction (the tool layer injects it from the authenticated session, not from caller-supplied input); an automated tenant-isolation test suite runs against every new tool added to the registry before it can be deployed |
| A statement line's counterparty embedding is unavailable because the payee has never been seen before by this company | `ai_similarity` contributes 0 for a first-time payee; the match either clears the threshold on deterministic signals alone or is queued for manual resolution — a cold start never blocks, it only removes one contributing signal |

# Future Improvements

- **Confidence-calibration dashboard**, surfaced directly to Finance Manager/CFO: the reliability curve
  from **Metrics & Evaluation** as a self-service view, so a company can see exactly how much to trust a
  given confidence band before it happens to them as a bad auto-commit.
- **Feed the Fraud Detection retraining loop** (already planned platform-wide per `BANKING.md`'s Future
  Improvements) with the Banking Agent's own accept/reject outcomes on duplicate and new-payee signals as
  additional labeled ground truth, not just Fraud Detection's own accept/override history.
- **Real-time, per-rail webhook ingestion** for every institution type (extending the module's existing
  goal beyond Open Banking-native institutions) so the matching sweep runs on a push rather than a
  4-hour poll for every bank, not only the digitally native ones.
- **Line-item-level document-first matching.** Today the document-first path matches a statement line to
  a whole `receipts`/`vendor_payments` row; a future version could match against individual
  `invoice_items`/`bill_items` embeddings to disambiguate a single payment covering several small
  invoices without relying on `receipt_allocations` already existing.
- **Consume the standardized bank-column-mapping marketplace** described in `BANKING.md`'s Future
  Improvements the moment it ships, so a newly onboarded bank account starts with an accurate
  `import_template` and a warmer `ai_memory` prior rather than a cold start.
- **Explainable-by-default UI hooks.** Expose `reasoning_factors` as a first-class, filterable field in
  the reconciliation workbench (today it is captured in `ai_decisions` but the human-facing workbench
  summarizes it as prose) so a Senior Accountant can query "show me every match this period where
  `fuzzy_counterparty` was the deciding rule."
- **Cross-period duplicate detection.** Today duplicate scanning runs within a rolling window; extend it
  to detect a duplicate that spans a period boundary (a line re-imported a month later after a bank's own
  correction), which currently would only be caught by the reconciliation-hash check on import, not the
  transaction-level duplicate scan.
- **Per-company active learning queue.** Rather than treating every proposal below the auto-commit
  threshold identically, prioritize the workbench by expected information gain — surface first the
  proposals whose resolution would most improve `ai_memory` confidence for a frequently recurring payee,
  rather than strictly by score or arrival order.

# End of Document
