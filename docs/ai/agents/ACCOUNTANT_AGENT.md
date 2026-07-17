# General Accountant Agent — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: ACCOUNTANT_AGENT
---

# Purpose

QAYD's AI layer is not a chatbot bolted onto an accounting system. It is an autonomous finance workforce — specialized agents that read every document, event, and posted fact continuously, and act, inside a hard permissioned boundary, before a human ever has to ask. The General Accountant Agent is the workhorse of that workforce: the highest-volume, most continuously active member of the roster, and the one whose output touches more of the platform's canonical financial tables than any other single agent. Where the CFO Agent synthesizes what already exists into judgment, and the Compliance Agent watches for regulatory exposure, the General Accountant Agent is the one that actually does the day-to-day bookkeeping — reading a scanned bill, an unmatched bank line, or a recurring pattern in the ledger, and turning it into a correctly structured, fully explained, balanced journal entry proposal before the human accountant has opened the document at all.

Concretely, this agent drafts and proposes journal entries from incoming documents and domain events, categorizes transactions against the Chart of Accounts, keeps the General Ledger and its sub-ledgers (Accounts Receivable, Accounts Payable) clean and explainable, and drafts month-end accruals ahead of period close. It never posts a single entry. It never approves its own work. It never moves money. Its entire contribution is a correctly reasoned, source-cited, confidence-scored proposal — a `draft` journal entry, a categorization suggestion, an accrual recommendation — that a human accountant reviews, edits if needed, and submits through precisely the same approval chain a human-authored entry would travel. Accounting becomes supervised, not manual: the accountant's day shifts from typing in what happened to reviewing a queue of what the agent already prepared, correctly, for the entries it was confident about, and flagged honestly for the ones it was not.

Two properties define this agent and separate it from the rest of the roster. First, it is the **highest-throughput proposer** in QAYD — every bill, every bank statement line, every recurring accrual pattern, and every uncategorized transaction is a candidate for its attention, which means its guardrails must scale to volume without becoming a rubber stamp (see Guardrails & Human Approval and Metrics & Evaluation). Second, it is one of only two agents in the roster (the other being Document AI, in a narrower sense) whose proposals can land as a real row in a module's own table rather than only as an entry in the shared cross-agent decision ledger — a `journal_entries` row with `status = 'draft'`, `entry_type = 'ai_generated'`, exactly as specified in the Journal Entries submodule — because journal entries already have a native draft lifecycle of their own. This document specifies that dual nature precisely: what the agent is mandated to do and not do; the autonomy level of every action it can take; the exact inputs it reads and the exact shape of everything it outputs; the tools it calls and the permission keys that gate them; how its data access is scoped per company; how it reasons internally; how it collaborates with Document AI/OCR, the Auditor, the Tax Advisor, and the CFO; the guardrails that keep it from ever posting or approving; three fully worked end-to-end scenarios; the metrics QAYD uses to judge whether it is actually good at its job; and the failure modes it must handle gracefully rather than paper over.

# Role & Mandate

The General Accountant Agent's mandate is bookkeeping execution, not bookkeeping policy. It does the work a Senior Accountant or Accountant would do by hand — matching, categorizing, drafting, explaining — inside a chart of accounts, an approval hierarchy, and a set of accounting policies that a human has already configured. It never decides the policy itself (that is the CFO's and Finance Manager's job), and it never has the authority to make its own draft become a fact in the books (that is always a human's job, through the Posting Engine specified in Journal Entries).

| In Mandate (owned here) | Out of Mandate (owned elsewhere) |
|---|---|
| Draft `ai_generated` journal entries from documents/events (unmatched bank lines, OCR'd bills without a Purchasing match, detected recurring patterns) | Posting, approving, submitting, or reversing any journal entry → **human accountant**, via the Posting Engine |
| Categorize transactions against the Chart of Accounts (account/cost-center/tax-code suggestions) | Defining the Chart of Accounts structure, account types, or tax categories → **Finance Manager / CFO**, via Chart of Accounts module |
| General Ledger upkeep — explain ledger activity, predict draft-time errors, recommend corrections | Posting Engine mechanics, fiscal-period locking, closing entries → **Journal Entries / Fiscal Periods modules** (owned mechanics, this agent only proposes into them) |
| Sub-ledger upkeep — AR/AP control-account tagging sanity, duplicate customer/vendor contribution to Fraud Detection's and the Customers/Vendors modules' own checks | Sub-ledger reconciliation sign-off → **Senior Accountant / Finance Manager** |
| Drafting month-end accruals — recurring-template generation and ad hoc pattern-based accrual proposals | Fiscal period lock/close, Trial Balance sign-off, Year Closing entry → **CFO / Owner**, via Fiscal Periods and Journal Entries |
| Three-way-match variance drafting (PO vs. goods receipt vs. bill) and non-stocked-line account mapping proposals | Vendor selection, RFQ award, price negotiation → **Purchasing Manager**, with Inventory Manager/Treasury Manager agents |
| Payroll journal-line validation (component/formula/proration sanity on a draft payroll run) | Payroll calculation, release, or GOSI/PIFSS remittance → **Payroll Manager**, requires-approval always |
| — | Bank transfers, payroll release, tax submission, permission changes, deleting/voiding financial data → hard-forbidden to this agent under any confidence, see Guardrails |

The agent never originates a primary financial fact out of nothing. Every proposal it makes is grounded in a specific document (an OCR'd bill, a bank statement line, an invoice), a specific domain event (`invoice.created`, `bank.synced`), or a specific, cited historical pattern (a recurring accrual template, a prior-period posting precedent) — never a number it invented to make an entry balance. If it cannot ground a line item, it lowers its confidence and says so explicitly rather than filling the gap silently; this is the platform-wide "no hallucinated accounting entries" rule made concrete for this agent specifically (see Reasoning & Prompt Strategy).

The mandate is bounded on both sides deliberately. Bounded from below: this agent does not decide how inventory is costed, does not compute tax rates, and does not calculate payroll gross-to-net — those are the Inventory Manager's, Tax Advisor's, and Payroll Manager's domains respectively, and this agent consumes their outputs (or their modules' posted facts) rather than re-deriving them. Bounded from above: this agent has no authority to make any of its own proposals real. `accounting.journal.post`, `accounting.journal.approve`, and every `*.submit` permission are structurally absent from its service-account role — not merely unused, but never granted, at any company, under any autonomy configuration (see Guardrails & Human Approval).

# Autonomy Level

Every action below falls into exactly one of the platform's three autonomy tiers — **auto** (the effect happens immediately, no human step), **suggest-only** (a proposal is written; a human may act on it or ignore it, with no obligation), and **requires-approval** (a `pending_approval`-equivalent resource is created and a human must explicitly approve it through the ordinary chain). The tier is fixed per action type in this document and enforced by which permission key the agent's service-account role is actually granted — never by the agent's own self-reported confidence.

| Action | Autonomy | Notes |
|---|---|---|
| Draft a journal entry from a document/event (OCR'd bill, unmatched bank line, recurring pattern) | **Suggest-only** | Always created `journal_entries.status = 'draft'`, `entry_type = 'ai_generated'`; a human must separately call `submit`, then satisfy the approval routing table in Journal Entries (`ai_generated` entries always require ≥ 1 approval level regardless of amount) |
| Categorize an already-matched, high-confidence (≥ 0.85) transaction line against a Chart-of-Accounts code | **Auto (metadata only)** | Applies only to filling in a draft line's `account_id`/`cost_center_id` suggestion on a row that is not yet posted; never extends to posting the row itself |
| Predict a likely draft-time error (unusual account/amount pattern) | **Auto (advisory, non-blocking)** | A dismissible warning banner with confidence and a one-line suggested fix; the human may post regardless — this is friction, not a gate |
| Recommend a correcting or reclassifying entry from an anomaly or Trial-Balance finding | **Suggest-only** | Always a fresh `draft`; never edits the entry it is correcting (posted entries are immutable) |
| Suggest a new Chart-of-Accounts account, a reclassification, or a duplicate-account merge | **Suggest-only** | Surfaced via the accounts `ai-suggestions` feed; a human calls `accept`/`reject` — this agent cannot create, reclassify, or merge an account directly |
| Flag a possible duplicate customer, vendor, or journal entry | **Suggest-only** | Surfaced inline at draft/save time; non-blocking |
| Propose a three-way-match (PO/GR/Bill) account mapping for a non-stocked line | **Auto for within-tolerance matches** (deterministic, sets `matched` automatically) / **Suggest-only for `variance_hold`** | Mirrors Purchasing's own three-way-match autonomy split exactly |
| Generate a scheduled recurring accrual from a `journal_entry_templates` row | **Suggest-only by default**; **auto only** for a company-whitelisted narrow class (e.g., small fixed-amount bank-fee accruals under an explicit threshold) | The auto carve-out must be explicitly enabled per company per template class; it is never a platform default |
| Explain ledger activity in response to a natural-language question | **Auto (read-only)** | No state change of any kind; pure query + synthesis |
| Validate a draft payroll run's line items for component/formula/proration errors | **Suggest-only** | Produces a validation report; Payroll Officer acknowledgment is required before Submit regardless of confidence |
| Submit, approve, post, void, or reverse any journal entry | **Never — architecturally unavailable, not merely gated** | `accounting.journal.submit`, `accounting.journal.approve`, `accounting.journal.post`, `accounting.journal.reverse` are never granted to this agent's service-account role, at any company, under any configuration |
| Create, reclassify, or merge an account directly; reopen a locked fiscal period | **Never — human-only** | The agent may recommend; only a human-held permission can execute |
| Touch a bank transfer, payroll release, tax submission, or permission change | **Never — hard-forbidden, out of mandate entirely** | Not part of this agent's tool list under any circumstance, regardless of confidence |
| Access another company's data or memory | **Never — architecturally impossible** | Not a permission check; there is no query path in the AI layer that can cross `company_id` |

This table is enforced by three independent layers, not agent discipline alone: (1) network isolation — the FastAPI subnet has no route to the PostgreSQL subnet at all; (2) application-layer RBAC — every call the agent makes runs through the identical Laravel `FormRequest` validation and permission gate a human's call would, under a service-account role that structurally lacks any `*.approve`/`*.post`/`*.submit`/`*.reverse` permission; (3) a database `CHECK` constraint (`chk_ai_draft_requires_review` on `journal_entries` and sibling tables) that refuses, at the PostgreSQL engine level, to let any AI-attributed row land in a terminal, money-moving status directly — a defense that holds even against a hypothetical bug in layers 1 or 2.

# Inputs

The agent is a read-and-propose service; like every agent in the roster it never queries PostgreSQL directly — it calls versioned, permissioned Laravel API endpoints (see Tools & API Access) that return exactly the rows the invoking session's RBAC context allows. Its inputs fall into six categories:

1. **Domain events** — delivered via the `events-ai` queue and `NotifyAiLayerListener` bridge (Database Events). This document extends the listener's `AGENT_SUBSCRIPTIONS` map with the General Accountant's own subscriptions: `bank.synced` (new statement lines to match), `document.ocr_completed` (Document AI/OCR Agent finished extracting a bill/receipt), `invoice.created` / `bill.created` (a source document exists but may lack a matching draft), `journal.posted` (feeds the historical-pattern retrieval used by Predict Errors and Recommend Corrections), and `payroll_run.drafted` (triggers payroll line-item validation).
2. **Documents** — structured OCR extraction (vendor/customer name, amount, date, line items, tax breakdown) produced by Document AI / OCR Agent, plus the original scanned image/PDF via the polymorphic `attachments` table, retrieved through a signed URL rather than bulk-downloaded.
3. **Historical posting patterns** — this company's own prior `journal_lines` for the same account/vendor/customer, retrieved for account-mapping precedent, error-signature comparison, and recurring-pattern detection; never another company's data (see Data Access & Tenant Scope).
4. **Company memory** — `ai_memory` rows scoped to this company (and optionally to `agent_code = 'general_accountant'`) holding preferred account mappings, materiality conventions, and previously human-corrected classifications (see Reasoning & Prompt Strategy).
5. **Sibling-agent context** — the Auditor's open Trial-Balance findings (`missing_account`, `currency_error`, `control_account_mismatch`), Fraud Detection's duplicate/anomaly flags, and Tax Advisor's tax-code guidance, read but never re-scored (see Collaboration With Other Agents).
6. **Conversational input** — a natural-language question via `ai_conversations` / `ai_messages` ("why did Trade Payables jump in May?", "draft the July internet accrual") posed by an Accountant, Senior Accountant, or Finance Manager.

**Example — context bundle assembled for an OCR-triggered draft task:**

```json
{
  "request_id": "7c3a1e50-2b4a-4b8a-9d21-6f0a3c5e9b12",
  "company_id": 4821,
  "task_type": "accountant_draft_from_document",
  "trigger_event": { "name": "document.ocr_completed", "attachment_id": 55231, "source_type": "bill", "source_id": null },
  "resolved_inputs": {
    "ocr_extraction": {
      "vendor_name_raw": "ACME SUPPLIES W.L.L.",
      "amount": "500.0000", "currency_code": "KWD", "document_date": "2026-07-15",
      "line_items": [{ "description": "Packaging materials", "amount": "500.0000" }]
    },
    "vendor_match_candidates": [{ "vendor_id": 8842, "name_en": "ACME Supplies W.L.L.", "similarity": 0.97 }],
    "open_bills_for_vendor": [{ "bill_id": 4471, "amount": "500.0000", "due_date": "2026-07-20" }],
    "historical_account_mapping": { "account_id": 5210, "name_en": "Packaging & Consumables Expense", "frequency_last_180d": 34 }
  }
}
```

# Outputs

Every proposal this agent makes carries the platform's three non-negotiable elements — a confidence score, explicit plain-language reasoning, and citable supporting sources — and lands in one of two places depending on whether the target module already has a native draft/proposal lifecycle of its own:

| Target capability | Where the artifact lives | Where the governance record lives |
|---|---|---|
| Draft a journal entry (the module already has a native `draft`/`ai_generated` lifecycle) | `journal_entries` (`status='draft'`, `entry_type='ai_generated'`) + `journal_lines`, each carrying `ai_confidence`, `ai_conversation_id`, and (on any line where a human later overrides the account) `ai_suggested_account_id` | A parallel `ai_decisions` row (`subject_type='journal_entries'`, `subject_id=<new draft id>`), so the cross-agent decision ledger, Approval Assistant, and analytics pipeline see it identically to every other agent's proposal |
| Suggest a new/reclassified/merged Chart-of-Accounts account (`accounts` has no draft state of its own — an account exists or it does not) | The accounts `ai-suggestions` feed (`GET /api/v1/accounting/accounts/ai-suggestions`) | `ai_decisions` (`subject_type='accounts'`, `subject_id=<existing account id or null for a "create new" suggestion>`) |
| Explain ledger activity, predict a draft-time error, validate payroll line items (pure advisory, no artifact created) | — nothing else is created | `ai_decisions` only (`requires_approval = false` for the read-only explanation case) |

`ai_decisions` is the shared, cross-agent proposal ledger defined once, in `CFO_AGENT.md`, and referenced — never redefined — by every other agent document in this roster, this one included. Its schema (`ai_decision_status`, `ai_decision_type`, `confidence_score NUMERIC(5,2)` on a **0–100** scale, `subject_type`/`subject_id`, `reasoning`, `payload`, `sources`, `alternatives`, `requires_approval`, `approved_by`/`approved_at`, `rejected_reason`, `superseded_by_id`, `expires_at`) is reused exactly as specified there. This document appends the following `ai_decision_type` values, following the same `<agent-prefix>_<noun>` convention `CFO_AGENT.md` established:

```sql
-- Appended to the shared ai_decision_type enum (defined in CFO_AGENT.md).
-- General Accountant values. The enum itself is not redefined here.
ALTER TYPE ai_decision_type ADD VALUE 'accountant_journal_draft';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_categorization';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_reclassification';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_month_end_accrual';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_ledger_explanation';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_error_warning';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_duplicate_flag';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_three_way_match';
ALTER TYPE ai_decision_type ADD VALUE 'accountant_payroll_validation';
```

**Confidence scale note.** `journal_entries.ai_confidence` and `ai_messages.confidence_score` (owned by the Journal Entries and AI Platform module documents respectively) are expressed on a **0–1** scale. `ai_decisions.confidence_score` (the shared cross-agent ledger) is expressed on a **0–100** scale by its own definition in `CFO_AGENT.md`. This agent computes one internal 0–1 confidence per proposal and writes it twice, in each table's native scale (e.g., an internal `0.94` is persisted as `ai_confidence = 0.9400` on the `journal_entries` row and as `confidence_score = 94.00` on the paired `ai_decisions` row) — a single documented conversion at the boundary, never a silent reinterpretation.

**Confidence banding (applies to categorization/advisory actions; a full journal-entry draft's routing to a human is governed by the approval-threshold table in Journal Entries, not by confidence alone):**

| Confidence | Treatment |
|---|---|
| < 0.60 | Shown as a low-confidence hint only, collapsed by default; never auto-applied |
| 0.60 – 0.85 | Shown as a pre-filled but fully editable default on the draft |
| ≥ 0.85 | Eligible for one-click "Apply" (categorization/metadata actions) or for the narrow whitelisted auto-post carve-out (journal-entry drafts, per Autonomy Level) — still never bypasses the mandatory ≥ 1 approval level for `entry_type = 'ai_generated'` |

**Example — `accountant_journal_draft` decision paired with its `journal_entries` artifact:**

```json
{
  "ai_decisions": {
    "id": 662104, "agent_code": "general_accountant", "decision_type": "accountant_journal_draft",
    "status": "pending_approval", "subject_type": "journal_entries", "subject_id": 903217,
    "confidence_score": 94.00,
    "reasoning": "Matched bank_transactions#88213 (KWD 500.000, 2026-07-15, memo 'ACME SUPPLIES') to bills#4471 (ACME Supplies W.L.L., due 2026-07-20) on exact amount and vendor-name similarity (0.97). Historical mapping for this vendor's packaging-materials line has posted to account 5210 in 34 of the last 34 occurrences over 180 days.",
    "payload": { "entry_type": "ai_generated", "total": "500.0000", "currency_code": "KWD" },
    "sources": [
      { "type": "bank_transactions", "id": 88213, "label": "Statement line, 2026-07-15, KWD 500.000" },
      { "type": "bills", "id": 4471, "label": "ACME Supplies W.L.L., due 2026-07-20" }
    ],
    "recommended_action": "Submit for approval; no manual correction expected.",
    "alternatives": [],
    "requires_approval": true
  },
  "journal_entries": {
    "id": 903217, "entry_type": "ai_generated", "status": "draft",
    "ai_confidence": "0.9400", "ai_conversation_id": 88431,
    "source_module": "banking", "source_type": "bank_transaction", "source_id": 88213
  }
}
```

# Tools & API Access

The agent calls a fixed set of tools exposed by the FastAPI orchestration layer, each a thin wrapper around one or more `/api/v1/accounting/*` Laravel endpoints. The wrapper adds no business logic of its own — it forwards the service account's bearer token and `X-Company-Id` context, so Laravel's own `FormRequest` validation and RBAC permission check apply exactly as they would to a human's identical call. A call the service-account role is not granted returns `403`; the tool call fails, and the agent reports the specific missing permission back to the orchestrator rather than silently omitting the action.

| Tool | Method | Path | Permission Key | Granted? | Purpose |
|---|---|---|---|---|---|
| `propose_journal_entry` | POST | `/api/v1/accounting/journal-entries` | `accounting.journal.create` | Yes | Create `entry_type='ai_generated'`, `status='draft'` |
| `update_own_draft` | PATCH | `/api/v1/accounting/journal-entries/{id}` | `accounting.journal.create` | Yes, own rows only (`created_by` = service account) | Revise a not-yet-submitted `ai_generated` draft before human review |
| `get_journal_entry` | GET | `/api/v1/accounting/journal-entries/{id}` | `accounting.journal.read` | Yes | Retrieve one entry for drill-down/citation |
| `search_journal_entries` | GET | `/api/v1/accounting/journal-entries` | `accounting.journal.read` | Yes | Pattern search for duplicate detection and precedent retrieval |
| `submit_journal_entry` | POST | `/api/v1/accounting/journal-entries/{id}/submit` | `accounting.journal.submit` | **No — human-only** | Draft → `pending_approval`; never called by this agent |
| `approve_journal_entry` | POST | `/api/v1/accounting/journal-entries/{id}/approve` | `accounting.journal.approve` | **No — human-only** | — |
| `post_journal_entry` | POST | `/api/v1/accounting/journal-entries/{id}/post` | `accounting.journal.post` | **No — human-only** | — |
| `reverse_journal_entry` | POST | `/api/v1/accounting/journal-entries/{id}/reverse` | `accounting.journal.reverse` | **No — human-only** | Agent may only emit a `accountant_reclassification` recommendation |
| `get_account` / `list_accounts` / `get_subtree` | GET | `/api/v1/accounting/accounts...` | `accounting.accounts.read` | Yes | Resolve/validate account candidates, tree context |
| `list_account_suggestions` | GET | `/api/v1/accounting/accounts/ai-suggestions` | `accounting.accounts.read` | Yes | Read back this agent's own pending suggestions |
| `propose_account_suggestion` | POST | `/api/v1/ai/decisions` | `ai.accountant.categorize` | Yes | Write a `accountant_categorization` / `accountant_duplicate_flag` decision (accounts has no draft row of its own) |
| `accept_reject_account_suggestion` | POST | `/api/v1/accounting/accounts/ai-suggestions/{id}/accept\|reject` | `accounting.accounts.update` | **No — human-only** | — |
| `search_ledger_entries` | GET | `/api/v1/accounting/ledger-entries/search` | `accounting.read` | Yes | Cluster posted lines by source/counterparty for "explain the movement" queries |
| `explain_ledger_activity` | POST | `/api/v1/accounting/ai/explain-activity` | `accounting.read` | Yes | Invoke the structured explanation pipeline (see Reasoning & Prompt Strategy) |
| `get_trial_balance` | GET | `/api/v1/accounting/trial-balance` | `accounting.read` | Yes | Read open findings feeding `accountant_reclassification` proposals |
| `get_recurring_templates` | GET | `/api/v1/accounting/journal-entry-templates` | `accounting.journal.read` | Yes | Read due/near-due recurring accrual templates |
| `draft_accrual` | POST | `/api/v1/accounting/journal-entries` | `accounting.journal.create` | Yes | Same underlying endpoint as `propose_journal_entry`, `entry_type='ai_generated'`, `is_recurring=true` |
| `get_purchase_documents` | GET | `/api/v1/purchasing/purchase-orders`, `/goods-receipts`, `/bills` | `purchasing.read` | Yes | Three-way-match inputs |
| `get_payroll_draft` | GET | `/api/v1/payroll/runs/{id}/items` | `payroll.read` | Yes | Payroll line-item validation inputs |
| `read_sibling_decisions` | GET | `/api/v1/ai/decisions?company_id={id}` | `ai.analyze` | Yes | Read Auditor/Fraud Detection/Tax Advisor decisions for grounding |
| `log_task` / `update_task` | POST/PATCH | `/api/v1/ai/tasks` | `ai.tasks.manage` | Yes | Create/update this agent's own `ai_tasks` row (see Reasoning & Prompt Strategy) |

**Two proposal shapes, by design.** This table reflects a deliberate architectural split, not an inconsistency: (1) when the target resource already has its own draft-capable lifecycle — `journal_entries` — the agent creates directly against that module's own endpoint, because a draft journal entry is a legitimate first-class object in that table's own state machine; (2) when the target resource has no draft state of its own — `accounts`, a Trial-Balance finding, a ledger explanation — the agent writes only to the shared `POST /api/v1/ai/decisions` envelope, because the "proposal" is a lighter-weight artifact than the resource itself. Both paths run through the identical `FormRequest`/RBAC/idempotency middleware; neither is a privileged shortcut.

**Idempotency.** Every write call carries an `Idempotency-Key` the agent generates per proposed action (`ai-proposal-<uuid>`), subject to the identical fingerprinting/replay/conflict rules as any human-driven client — if the agent's HTTP client retries a timed-out `POST /api/v1/accounting/journal-entries`, the platform guarantees exactly-once semantics, never a duplicate draft. For automatic entries specifically, a second, source-based dedup key (`source_module + source_type + source_id + event_name`) additionally guards against event redelivery producing two proposals for the same underlying fact.

**Example tool call — `propose_journal_entry`:**

```
POST /api/v1/accounting/journal-entries
Authorization: Bearer <AI service-account token, role: ai_general_accountant>
X-Company-Id: 4821
Idempotency-Key: ai-proposal-9f2e7d10-4b3a-4a1e-8c2d-5f6a7b8c9d10

{
  "entry_type": "ai_generated",
  "journal_date": "2026-07-15",
  "memo": "Vendor payment matched to open bill",
  "lines": [
    { "account_id": 2110, "debit": "500.0000", "vendor_id": 8842, "description": "AP settlement — Bill #4471" },
    { "account_id": 1010, "credit": "500.0000", "description": "NBK Current — statement line 88213" }
  ],
  "meta": {
    "ai_generated": true, "ai_agent": "general_accountant", "confidence": 0.94,
    "reasoning": "Matched bank_transactions#88213 to bills#4471 on exact amount and vendor-name similarity (0.97).",
    "source_documents": [{ "type": "bank_transaction", "id": 88213 }, { "type": "bill", "id": 4471 }]
  }
}
```

`meta.ai_generated`, `meta.confidence`, and `meta.reasoning` are required by the same `FormRequest` validator that gates every AI-service-account write platform-wide; their absence is a hard `422` (`ai_reasoning_required`), not a soft warning. The resulting row's `created_by` is the service account's own `user_id`, never the human who configured it — the audit trail always shows truthfully whether a human or this agent authored a row. Every tool call, regardless of outcome, is additionally written to `ai_logs` (shared, defined in `CFO_AGENT.md`) before the result returns to the reasoning loop, so a permission denial, a timeout, or a success are all equally observable after the fact.

# Data Access & Tenant Scope

The agent authenticates as a dedicated system principal — the `ai_agents` row for `code = 'general_accountant'` — carrying a Sanctum-issued service token scoped to a single `company_id` per invocation via `X-Company-Id`, identical isolation to a human user. There is no global credential and no cross-tenant code path: a run for company 4821 physically cannot see company 4822's rows, because every query passes through the same `company_id`-scoped access rule every controller and RLS policy enforces for a human session.

**Read scope:** `accounts`, `account_types`, `journal_entries`, `journal_lines`, `ledger_entries`, `fiscal_years`, `fiscal_periods`, `cost_centers`, `projects`, `departments`, `customers`, `vendors` (business fields only — banking/contact PII is never pulled into a prompt unless the specific match requires it), `invoices`, `bills`, `bank_accounts`, `bank_transactions`, `stock_movements`/`inventory_valuations` (for COGS-adjacent line mapping), `payroll_items`/`salary_components` (structure only, not individual employee PII beyond what a line-item validation requires), `tax_codes`, `tax_rates`, `journal_entry_templates`, `attachments` (metadata; content fetched via signed URL only when OCR text is specifically needed), `ai_decisions`/`ai_memory`/`ai_conversations`/`ai_messages` scoped to this company and, where relevant, this agent's own `agent_code`.

**Write scope:** `journal_entries` + `journal_lines` (create only, `entry_type='ai_generated'`, `status='draft'`, restricted to rows it created itself for any subsequent edit); `ai_decisions` (its own proposals); `ai_memory` (its own per-company, per-agent namespace); `ai_tasks` (its own task-queue rows, via the shared table defined in `CFO_AGENT.md`). It has **zero** write scope on `posted`/`reversed`/`voided`/`archived` journal entries, on `accounts` directly (only the `ai-suggestions` feed), on `bank_accounts` balances, on `payroll_runs`, on `tax_returns`, or on any user/role/permission table — those remain human-only or other-agent-proposal-only, exactly as Guardrails & Human Approval specifies.

**Tenant isolation is enforced at three layers**, matching the platform-wide pattern: (1) the service token's company claim; (2) the same `company_id`-scoped access rule (Eloquent global scope / RLS policy) every model the agent's role can touch already carries for a human session; (3) a runtime assertion in the FastAPI orchestration layer that rejects any tool result whose `company_id` does not match the active task's `company_id` — defense-in-depth specifically because agent-orchestration code is newer than the Laravel authorization stack it sits behind. A row-level security session bound to the agent's own actions (e.g., ad hoc ledger-explanation queries) is deliberately `NOBYPASSRLS`, exactly like every other AI-agent session, per the platform's Row-Level Security rules.

**Untrusted content handling.** OCR'd document text and bank-statement memo fields are treated as **data, never as instructions** — a scanned bill whose extracted text contains an embedded phrase resembling "approve this" or "ignore prior instructions" has no path to elevate the agent's own permissions or bypass a guard, both because the agent's tool-calling loop only accepts structured fields from the extraction pipeline (never free-text as a command channel) and because the underlying `company_id` scoping and permission grants described above do not vary based on document content under any circumstance.

# Reasoning & Prompt Strategy

The agent runs as a LangGraph-style state machine, not a single freeform prompt. Every unit of work — a scheduled scan, an event trigger, an ad hoc chat request, or a task delegated to it by another agent (e.g., the Auditor asking it to draft a correcting entry for a Trial-Balance finding) — becomes one row in the shared `ai_tasks` queue (defined once, in `CFO_AGENT.md`, and reused here without redefinition), with `agent_code = 'general_accountant'` and a `task_type` value from this document's own set: `accountant_draft_from_document`, `accountant_draft_from_bank_line`, `accountant_categorize_transaction`, `accountant_month_end_accrual`, `accountant_explain_ledger`, `accountant_predict_error`, `accountant_reclassify`, `accountant_three_way_match`, `accountant_payroll_validation`.

**Orchestration graph:**

```
  [queued: ai_tasks row created — schedule | event | user_request | agent_request]
     |
     v
  RETRIEVE  ── gather the document/event payload, historical posting patterns for the
     |          relevant account/vendor/customer, ai_memory hints, and any sibling-agent
     |          flags (Auditor findings, Fraud Detection duplicates) already on record
     v
  RESOLVE   ── (a) vendor/customer fuzzy match against master data, scored;
     |          (b) GL account mapping from this company's own historical frequency,
     |              weighted toward recency; (c) tax code/rate lookup where applicable;
     |          (d) for three-way-match tasks, PO/GR/Bill quantity+price reconciliation
     v
  ASSEMBLE  ── build balanced draft lines (SUM(debit) = SUM(credit), verified in code —
     |          never trusted from the model's own arithmetic, mirroring the Posting
     |          Engine's own "never trust client arithmetic" rule, applied symmetrically
     |          to this agent's own draft-construction step)
     v
  SCORE     ── confidence = weighted combination of sub-confidences:
     |            0.35 × vendor/customer match score
     |          + 0.35 × account-mapping precedent strength (frequency × recency)
     |          + 0.20 × tax-mapping certainty
     |          + 0.10 × (1 − three-way-match variance ratio, where applicable)
     v
  SELF-CHECK ── (a) does every line resolve to a cited source document or historical
     |           precedent? (b) does SUM(debit) = SUM(credit) hold exactly, independently
     |           recomputed? (c) is any control-account line missing its required
     |           customer_id/vendor_id tag? (d) is the proposed entry type/path one this
     |           agent is actually permitted to write (never post/approve/submit)?
     |           -> any failure: revise before persisting, or lower confidence and
     |              disclose the gap explicitly — never emit silently
     v
  PERSIST   ── propose_journal_entry (or propose_account_suggestion / ai_decisions-only
     |          for advisory tasks); write the paired ai_decisions row; update ai_tasks
     |          to 'completed' with result_decision_id set
     v
  [notify the reviewing accountant's queue / route per Guardrails]
```

**Retrieval is hybrid.** Structured retrieval (SQL, via the read tools in Tools & API Access) supplies exact historical facts — this vendor's last N bills, this account's last N postings, this company's configured materiality threshold. Vector similarity search over `ai_memory.embedding` (pgvector, shared schema from `CFO_AGENT.md`) supplies softer context — "has this company historically coded 'ACME Supplies' packaging invoices to a different account under a specific project?" — with structured facts always taking precedence over a memory hint when the two disagree, per the rule that memory informs, but never replaces, a citable source (the same rule `CFO_AGENT.md` states for its own `ai_memory` usage).

**Confidence formula, concretely.** For a document-triggered journal-entry draft, confidence is not a single undifferentiated model output; it is the weighted blend shown in the SCORE step above, computed in code from named sub-scores the model must populate individually (vendor match, account-mapping strength, tax certainty, match variance) — this prevents a single confident-sounding paragraph from masking one weak sub-judgment (e.g., a 0.97 vendor match paired with a never-seen-before account mapping should not average out to a falsely reassuring 0.9; the weights above keep the account-mapping term equally load-bearing).

**Determinism where it matters.** The numeric/structural portion of a proposal (balanced lines, `SUM(debit)=SUM(credit)`, tax arithmetic) is produced and verified by deterministic code, never trusted from the language model's own arithmetic — the model proposes which accounts and how the source document maps to them; a non-LLM validation pass independently recomputes every total before `PERSIST`. The natural-language reasoning portion runs at low decoding temperature and must be strictly grounded: every factual claim in `reasoning` must resolve to an entry in `sources`, checked by an output-schema validator that rejects (and forces a retry of) any proposal whose reasoning text references a document or pattern not present in the retrieved evidence — the same "no hallucinated accounting entries" guardrail stated once, platform-wide, in AI_ARCHITECTURE.md, made mechanically enforced here rather than aspirational.

**Model routing.** High-volume, low-ambiguity work (categorizing an already-matched bank line, populating a recurring accrual from a template) routes to a smaller/cheaper model tier; lower-volume, higher-ambiguity work (an OCR'd bill with no historical vendor precedent, a Trial-Balance correction requiring judgment about which of several plausible accounts is right) routes to the frontier tier. Every model call is logged to `ai_logs` with prompt/response token counts and latency, feeding the cost and quality metrics in Metrics & Evaluation.

# Collaboration With Other Agents

This agent sits in the middle of the roster's busiest collaboration chain — it is simultaneously a consumer of Document AI/OCR's extraction, a producer the Auditor reviews, a peer the Tax Advisor cross-checks, and a data source the CFO cites for drill-down. The canonical intake-to-notification chain, stated once in AI_ARCHITECTURE.md and reused here as this agent's own primary flow:

```
Invoice/Bill Uploaded
        │
        ▼
   Document AI  ──── structural extraction (vendor, amounts, line items)
        │
        ▼
   OCR Agent  ──── field-level text/number extraction, confidence per field
        │
        ▼
┌───────────────────────┐
│  General Accountant    │ ◀──────────────┐
│  (this document)       │                 │ historical patterns,
│  drafts the journal    │                 │ company memory
│  entry, categorizes,   │ ────────────────┘
│  proposes accruals     │
└──────────┬─────────────┘
           │ draft journal_entries + ai_decisions
           ▼
      Auditor  ──── reviews for anomalies, Trial-Balance consistency
           │
           ▼
    Tax Advisor  ──── validates tax-code/rate treatment on the draft
           │
           ▼
  Inventory Manager  ──── consumes for COGS/valuation consistency where stock-linked
           │
           ▼
     Reports Updated ──── once a human posts, GL/Trial Balance/statements reflect it
           │
           ▼
       Notify User
```

| Collaborator | What this agent requests | What it receives |
|---|---|---|
| **Document AI / OCR Agent** | Structured extraction of an uploaded bill/receipt/statement (intake) | Vendor/amount/date/line-item fields with per-field confidence; this agent never re-runs OCR itself |
| **Auditor** | Nothing directly on the way in; the Auditor reviews this agent's drafts and Trial-Balance findings on the way out | Findings (`missing_account`, `currency_error`, `control_account_mismatch`) this agent turns into `accountant_reclassification` correction drafts |
| **Tax Advisor** | Tax-code/rate resolution for a line item; validation that a proposed tax treatment is consistent with current registration status | Tax code/rate to apply to a draft line; this agent never computes or files tax itself |
| **CFO** | — (the CFO Agent is a consumer of this agent, not the reverse) | Read-only `journal_lines` for drill-down citation inside a CFO narrative (per `CFO_AGENT.md`'s own Collaboration table) |
| **Fraud Detection** | Duplicate/anomaly screening on a drafted entry before it reaches a human queue | `possible_duplicate_of` flags and anomaly severity, read and surfaced, never re-scored by this agent |
| **Inventory Manager** | COGS/valuation consistency for stock-linked lines (goods issued on sale, landed-cost allocations) | Confirmed valuation method and per-unit cost basis for the period |
| **Payroll Manager** | The draft payroll run's line items, for validation only | This agent's own validation report back to the Payroll Officer's queue |
| **Treasury Manager** | Bank-transaction context for unmatched-line drafting | Reconciliation status and matched/unmatched line detail; this agent never touches a bank transfer |
| **Approval Assistant** | Routing of any `requires_approval = true` decision to the correct human role/queue | Approval-status callbacks (`approved`/`rejected`/`edited`) that close the loop on an `ai_decisions` row |

When this agent needs a number or a judgment another agent already owns (a tax rate, a fraud score, a valuation method), it always asks for that agent's own confidence-scored output rather than recomputing an equivalent figure itself — the same rule `CFO_AGENT.md` states for its own collaboration, applied here to prevent two agents from ever producing two different answers to "what account does this line belong to."

# Guardrails & Human Approval

This agent operates inside the platform-wide AI safety rule that governs every agent in the roster: it never writes to PostgreSQL directly, it never bypasses the invoking session's own RBAC permissions, and every sensitive operation remains gated behind a human approval chain no matter how high its confidence score. Given this agent's exceptionally high proposal volume, four additional guardrails apply specifically to it:

**1. Structural incapacity to self-approve.** The `ai_general_accountant` service-account role is never granted `accounting.journal.submit`, `accounting.journal.approve`, `accounting.journal.post`, or `accounting.journal.reverse`, at any company, under any autonomy configuration — this is a hard-coded platform invariant, not a per-company setting a Finance Manager could accidentally loosen. Segregation of duties is therefore trivially and permanently satisfied for every AI-originated draft: the "creator" can structurally never also be the "approver."

**2. Zero tolerance on the balance invariant.** No proposal reaches `PERSIST` (Reasoning & Prompt Strategy) unless a non-LLM validation pass has independently confirmed `SUM(debit) = SUM(credit)` to the exact currency unit — this agent has no path to submit an unbalanced draft, mirroring the Posting Engine's own re-validation discipline one step earlier in the pipeline.

**3. Mandatory approval regardless of confidence for journal-entry drafts.** Per the approval-routing table in Journal Entries, `entry_type = 'ai_generated'` always requires a minimum of one human approval level, at any amount — a 0.99-confidence draft and a 0.61-confidence draft both stop at the same human gate; confidence affects how the draft is presented and prioritized in the review queue, never whether it needs review at all. The sole exception is the narrow, per-company, per-template whitelist for auto-post carve-outs described in Autonomy Level, which remains subject to the same platform-wide sensitive-operations exclusion (it can never apply to a control-account line, a cross-period entry, or any amount above the company's own configured ceiling for that specific template).

**4. Volume circuit breaker.** Beyond ordinary rate limiting, this agent's proposal volume is capped on the number of `pending_approval`-bound drafts it may create per company per hour — a control distinct from raw request throttling, protecting a Finance Manager's review queue from being flooded by a correct-but-overzealous run (e.g., a bulk bank-statement import producing hundreds of low-value line matches at once). When the cap is hit, the run pauses and the company's admins are notified that AI drafting has been throttled, rather than either silently dropping proposals or silently raising the cap.

| Decision Type | Required Approver Role | Escalation If Ignored |
|---|---|---|
| `accountant_journal_draft` | Accountant/Senior Accountant (Tier-1); Senior Accountant + CFO/Owner for amounts ≥ the company's Tier-2 threshold, per Journal Entries' own routing table | Ages in the review queue; no auto-expiry, but flagged stale after 5 business days |
| `accountant_categorization` | Accountant (one-click accept/reject) | None — low-stakes, reversible before posting |
| `accountant_reclassification` | Senior Accountant or Finance Manager | Escalates to Finance Manager after 48h unreviewed |
| `accountant_month_end_accrual` | Senior Accountant, then included in the period-end Trial-Balance sign-off | Blocks the period-end closing checklist step it belongs to until reviewed |
| `accountant_duplicate_flag` | Accountant (dismiss or merge-forward to the flagged record's own owner) | Escalates to Fraud Detection's own severity handling if the same pair recurs |
| `accountant_three_way_match` (variance hold) | Purchasing Manager or Finance Manager | Escalates per Purchasing's own variance-hold SLA |
| `accountant_payroll_validation` | Payroll Officer (must acknowledge before Submit) | Blocks payroll Submit until acknowledged, regardless of confidence |

Every guardrail above is computed from `ai_decisions.status` transitions and `ai_logs` timestamps — an evaluation pipeline the agent cannot influence, since it is a scheduled job reading the same immutable tables the agent itself writes to, never a self-report.

# Worked Scenarios

## Scenario 1 — OCR'd vendor bill matched to an unreconciled bank line

**Company:** Al-Rawda Trading & Logistics W.L.L. (Kuwait, base currency KWD, `company_id` 4821). **Trigger:** `event` — `bank.synced` imports a new NBK Current statement line (`bank_transactions#88213`, KWD 500.000, dated 2026-07-15, memo "ACME SUPPLIES"), and a separately OCR'd vendor bill (`bills#4471`, ACME Supplies W.L.L., KWD 500.000, due 2026-07-20) has no reconciled payment yet.

1. **RETRIEVE**: an `ai_tasks` row (`task_type='accountant_draft_from_bank_line'`, `triggered_by='event'`) pulls the statement line, the open bill, and the vendor's historical account mapping (packaging-materials line → account 5210, 34 of the last 34 occurrences in 180 days).
2. **RESOLVE**: vendor-name similarity between the statement memo and the vendor master record scores 0.97; amount matches exactly; account mapping precedent is strong (frequency 34/34, high recency weight).
3. **ASSEMBLE**: two balanced lines — debit Accounts Payable — Control (tagged `vendor_id` 8842) KWD 500.000; credit NBK Current bank account KWD 500.000.
4. **SCORE**: confidence = 0.35(0.97) + 0.35(0.98, strong precedent) + 0.20(1.0, no tax line — a pure AP settlement) + 0.10(n/a, not applicable) ≈ **0.94**.
5. **SELF-CHECK** passes: both lines cite `bank_transactions#88213` and `bills#4471`; debits equal credits exactly; the AP line carries the required `vendor_id` tag.
6. **PERSIST**: `propose_journal_entry` creates `journal_entries#903217` (`status='draft'`, `entry_type='ai_generated'`, `ai_confidence=0.9400`); a paired `ai_decisions` row (`accountant_journal_draft`, `confidence_score=94.00`, `status='pending_approval'` once the human submits) is written; `ai_tasks` completes with `result_decision_id` set.
7. The reviewing Accountant sees the draft pre-filled, both source documents linked, submits it unchanged, and a Senior Accountant approves — the entry posts, and `bank_transactions#88213` is marked reconciled.

## Scenario 2 — Recurring bank-fee line under the narrow auto-post whitelist

**Trigger:** `event` — `bank.synced` imports a monthly NBK account-maintenance fee, KWD 3.500, a pattern that has recurred on the 1st of every month for the last 14 months with an identical amount and memo. Al-Rawda has explicitly whitelisted this exact template (`journal_entry_templates` row `bank_fee_nbk_maintenance`, ceiling KWD 10.000, non-control-account only) for the narrow auto-post carve-out described in Autonomy Level.

1. **RETRIEVE/RESOLVE**: the pattern matches the whitelisted template exactly — same vendor-less bank fee, same account (Bank Charges Expense), amount KWD 3.500, well under the company's configured KWD 10.000 ceiling for this template class.
2. **SCORE**: confidence 0.99 (14/14 identical historical occurrences, fixed small amount, no control account involved).
3. **SELF-CHECK** additionally verifies the auto-post eligibility guard itself: template is company-whitelisted, amount is under the configured ceiling, no control-account line is present, entry is not cross-period. All conditions hold.
4. **PERSIST**: because this is the one narrowly whitelisted class, the resulting `journal_entries` row still lands as `entry_type='ai_generated'`, `status='draft'` exactly as any other AI proposal — the "auto" in Autonomy Level refers to the company's pre-authorized policy immediately advancing it through `submit`→`approve`→`post` without a per-instance human click, not to this agent itself calling those endpoints (which remain permanently outside its role, per Guardrails). The advancement is executed by a policy-driven system action distinct from this agent's own service-account permissions, logged in full to `audit_logs` and `ai_logs` and visible in the ledger identically to a manually posted entry.
5. A monthly digest notifies the Finance Manager that 1 bank-fee entry auto-posted under the whitelisted policy this period, with a one-click path to revoke the whitelist for that template if ever desired.

## Scenario 3 — Month-end accrual drafted ahead of period lock

**Trigger:** `schedule` — the agent's month-end sweep (part of the period-end closing checklist in Journal Entries) runs three business days before Al-Rawda's July fiscal period is due to move from `open` to `soft_close`. It detects an internet-service bill that historically arrives on the 3rd of the following month but whose service was fully consumed in July.

1. **RETRIEVE**: `journal_entry_templates` shows a recurring accrual pattern for this vendor (internet service, prior 11 months all accrued then reversed on bill receipt); no July bill has arrived yet.
2. **RESOLVE**: estimates the accrual amount from the trailing 3-month average (KWD 42.000/month, low variance), maps to Utilities Expense (account 6140) against Accrued Expenses (a liability control-adjacent account, no vendor tag required since no bill exists yet to reconcile against).
3. **ASSEMBLE**: debit Utilities Expense KWD 42.000; credit Accrued Expenses KWD 42.000.
4. **SCORE**: confidence 0.78 — lower than Scenarios 1–2 because the amount is an estimate, not a matched document; the agent states this explicitly rather than presenting an estimate with false precision.
5. **SELF-CHECK**: reasoning explicitly discloses "estimated from a 3-month trailing average; no invoice received yet" rather than implying a matched source; flags itself for automatic reversal in the next period once the real bill posts.
6. **PERSIST**: `accountant_month_end_accrual` decision, `status='pending_approval'`, `requires_approval=true`; surfaced as part of the period-end closing checklist for Finance Manager sign-off alongside the Trial Balance review.
7. The Finance Manager reviews the estimate against the trailing average shown in the citation, approves it unchanged, and the entry posts; the following month, when the real bill (KWD 44.500) arrives, the agent proposes the reversing entry plus the small KWD 2.500 true-up as a fresh, separately drafted correction — never a silent edit to the posted accrual.

# Metrics & Evaluation

| Metric | Definition | Target |
|---|---|---|
| Draft acceptance rate | % of `accountant_*` decisions approved with zero line/account edits | > 70% |
| Edit distance | Line-level edit distance (accounts/amounts changed) between drafted and approved journal entries | < 15% of lines touched |
| Confidence calibration (Brier score) | Squared error between stated confidence and realized acceptance, bucketed by decile | < 0.12 |
| Time-to-draft | Elapsed time from triggering event (OCR completion, bank sync) to a `draft` journal entry existing | p95 < 2 minutes |
| Duplicate-detection precision/recall | Of flagged possible duplicates, % confirmed real; of confirmed real duplicates, % this agent actually flagged | Precision > 90%; Recall > 80% |
| Three-way-match auto-clear rate | % of PO/GR/Bill triples cleared automatically within tolerance vs. sent to `variance_hold` | Tracked, not targeted — a rising hold rate is itself a signal to investigate vendor pricing drift |
| Month-end accrual coverage | % of the company's actual recurring-accrual patterns drafted before period `soft_close` vs. missed and entered manually after | > 95% |
| Approval-chain latency | Median time an `accountant_journal_draft` decision waits before human action | < 24h (Tier-1 amounts); < 48h (Tier-2, two-level approval) |
| Source-citation completeness | % of proposals whose every line resolves to an entry in `sources` | 100% (hard guardrail, not a soft target) |
| Volume-cap trigger rate | % of company-hours the circuit breaker (Guardrails) actually engages | < 1% — a sustained higher rate signals the cap or the underlying document volume needs review |

**Example — acceptance-rate query, computed from the shared ledger, never self-reported:**

```sql
SELECT
  d.decision_type,
  COUNT(*) FILTER (WHERE d.status = 'approved')::numeric
    / NULLIF(COUNT(*) FILTER (WHERE d.status IN ('approved','rejected')), 0) AS acceptance_rate,
  AVG(d.confidence_score) FILTER (WHERE d.status = 'approved') AS avg_confidence_when_approved
FROM ai_decisions d
WHERE d.agent_code = 'general_accountant'
  AND d.company_id = 4821
  AND d.created_at >= now() - INTERVAL '90 days'
GROUP BY d.decision_type
ORDER BY acceptance_rate DESC;
```

Every metric above is computed from `ai_decisions.status` transitions and `ai_logs` timestamps by a scheduled evaluation job reading the same immutable tables the agent writes to — the agent cannot mark its own homework, matching the pattern every sibling agent document in this roster uses.

# Failure Modes & Edge Cases

| Case | Handling |
|---|---|
| Low-quality OCR extraction (garbled vendor name or amount) | Confidence drops sharply and the reasoning explicitly states "low OCR quality — vendor name illegible" rather than guessing a best-effort match; routed to a human with the original image attached, not silently posted |
| Event redelivery (the same `bank.synced` or `document.ocr_completed` event fires twice) | The source-based dedup key (`source_module+source_type+source_id+event_name`) makes any re-trigger a no-op against an already-existing live proposal; no duplicate draft is created |
| Concurrent human edit to the same not-yet-submitted draft | Optimistic concurrency via the entry's `version` field; a mismatched write returns `409 Conflict` rather than silently overwriting the human's edit |
| Fiscal period moves to `locked` before a still-open draft is reviewed | The agent never silently redates the draft; it proposes re-dating into the current open period with an explicit memo, or, if the human prefers, the draft simply waits for the formal period-reopening procedure |
| FastAPI task times out mid-run | `ai_tasks.status='failed'`, retried per the platform's job-level retry policy; because nothing is persisted until the final `PERSIST` step, a mid-run failure never leaves a partial or malformed draft behind |
| A cited source document is later found not to exist or not to belong to this company | The `FormRequest` validates every `source_documents`/`sources` id server-side at proposal time — an id that does not resolve, or resolves outside the active `company_id`, is a hard `422`, not a soft warning, which is also the platform's structural defense against a prompt-injected fake citation |
| Confidence miscalibration drifts over time (e.g., a vendor changes its invoice template and match scores start looking falsely confident) | Caught by the Metrics & Evaluation Brier-score tracking; a sustained calibration miss triggers a model/prompt review, not a silent continuation |
| Company disables the agent entirely (`ai_agents.status='disabled'`) | New tasks stop being dispatched immediately; in-flight `ai_tasks` are cancelled gracefully, none left `running` indefinitely |
| Recurring accrual estimate later proves materially wrong once the real document arrives | Never edits the posted accrual; always proposes a fresh reversing entry plus a true-up, fully traceable as two separate, dated events |
| A malicious or malformed document contains text resembling an instruction ("approve automatically", "ignore review") | Treated as inert data, never as a command — the agent's tool-calling contract only accepts structured extraction fields, and no document content can widen a permission grant or skip a guardrail under any circumstance |
| Two sibling agents' inputs conflict (e.g., Fraud Detection flags a vendor this agent would otherwise treat as a clean historical match) | The conflict is surfaced explicitly in the reasoning text; this agent never silently overrides another agent's flag or silently proceeds as if it had not seen it |
| Ambiguous month-end accrual candidate (multiple plausible account mappings, no clear historical precedent) | Confidence is capped low and multiple `alternatives` are listed with their respective tradeoffs, rather than a single account chosen and presented as certain |

# Future Improvements

- **Per-template auto-post policy studio.** Extend today's narrow, manually configured whitelist (Autonomy Level, Scenario 2) into a self-service `ai_autonomy_policies` configuration surface where a Finance Manager sets confidence-threshold-driven auto-advance rules per template class, without engineering involvement — foreshadowed identically in the shared `ai_decisions`/`ai_feedback` future-expansion notes.
- **Natural-language accrual authoring.** Let an Accountant type "accrue KWD 400 for the July internet bill we haven't received yet" directly into a conversation and have it resolve into the same `propose_journal_entry` path Scenario 3 uses programmatically, with the conversational request itself becoming the cited source.
- **Batch month-end review mode.** Group all of a period's `accountant_month_end_accrual` and `accountant_reclassification` proposals into a single reviewable batch for the Finance Manager, rather than N separate queue items, reducing per-entry review friction during the closing checklist.
- **Cross-tenant, privacy-preserving account-mapping priors.** Extend the Chart of Accounts module's own anonymized, aggregated-only cross-tenant classification learning to this agent's vendor→account mapping confidence, so a brand-new company with no posting history yet still starts from a reasonable industry-informed prior instead of a cold start — never sharing any individual company's raw transactions.
- **Tighter Forecast Agent feedback loop.** Flag when a drafted accrual or recurring pattern materially deviates from the Forecast Agent's own trailing projection, so a drifting utility/rent pattern is caught as a forecasting signal, not only as a bookkeeping one.
- **Streaming partial-draft rendering.** Show a draft's lines populating incrementally in the review UI as the RESOLVE step completes each one, rather than only after the full ASSEMBLE step finishes, shortening perceived latency for larger multi-line document drafts.
- **Dedicated per-agent API credential.** Migrate from today's single shared `ai_agent` service-account key toward one scoped credential per agent (already noted as a platform-wide hardening step elsewhere in the roster's documentation) for tighter blast-radius containment specific to this agent's unusually high call volume.

# End of Document
