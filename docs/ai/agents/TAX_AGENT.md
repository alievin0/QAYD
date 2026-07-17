# Tax Advisor Agent — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: TAX_AGENT
---

# Purpose

The Tax Advisor is the specialized AI agent responsible for the reasoning, classification, drafting, and monitoring work that surrounds QAYD's deterministic Tax Management module. It is not a chatbot that answers tax questions on request; it is a continuously running member of QAYD's autonomous finance workforce that watches every taxable event a company generates — a new product added to the catalog, an invoice raised, a bill entered, a payroll run calculated, a filing deadline approaching — and performs the preparatory work a human tax accountant would otherwise do by hand: assigning the correct tax code to an ambiguous new item, rolling up VAT and Withholding Tax (WHT) exposure across jurisdictions, assembling a `tax_returns` draft with every `tax_return_lines` box populated and reconciled against the General Ledger, and flagging risk and optimization opportunities before they become a filing-week emergency.

The Tax Advisor never calculates a tax amount itself. Tax amounts are produced exclusively by the deterministic `TaxCalculationService` in the Laravel backend (`POST /api/v1/tax/calculate`), which resolves `tax_rules` against effective-dated `tax_rates` and returns a reproducible, auditable figure — a property both tax authorities and external auditors depend on. The Tax Advisor's job begins where that engine's inputs are ambiguous (which `tax_code` applies to a brand-new SKU nobody has classified yet, which jurisdiction a cross-border service falls under) and continues after the engine's outputs are posted (aggregating posted `tax_transactions` into a liability view, explaining a calculation's lineage, drafting the return that packages a period's transactions for human review). This separation is the single most important architectural fact about this agent: **it reasons about tax, it does not compute tax.**

Every output the Tax Advisor produces carries a confidence score, an explicit reasoning trace, and references to the supporting `tax_transactions`, `tax_rules`, `tax_rates`, or source documents behind it. Every action beyond read-only analysis is submitted as a proposal through the Laravel API — either a dedicated preparation endpoint under `/api/v1/tax/*` or the generic `/api/v1/tax/ai/proposals` queue — subject to the same `FormRequest` validation and RBAC permission checks a human user's request would face. The Tax Advisor obeys the same permissions as the user or service context it runs under; it never bypasses authorization, and it never writes to `tax_codes`, `tax_rates`, `tax_rules`, `tax_transactions`, or `tax_returns` directly from the FastAPI/Python AI layer.

The Tax Advisor is built for QAYD's Gulf go-to-market footprint first: GCC VAT (Saudi Arabia's 15% ZATCA-administered VAT with mandatory e-invoicing, the UAE's 5% FTA VAT, Bahrain's 10%, Oman's 5%, Qatar pending), Kuwait's distinct context (no VAT as of this specification — the jurisdiction and rate framework is provisioned but dormant until a `tax_registrations` row for Kuwait VAT actually exists — alongside Kuwait's 15% corporate tax on foreign corporate bodies' profits and Zakat for GCC-national-owned entities), and cross-border Gulf withholding tax on payments to non-resident vendors. Expanding into a new GCC country, or beyond the Gulf later, is a jurisdiction/rate/rule data change the Tax Advisor's retrieval layer picks up automatically; it is never a prompt rewrite.

Concretely, across every module that touches tax, the Tax Advisor exists so that:

- No new product or service ships without a tax treatment, and no human has to remember to classify one manually before the first invoice goes out.
- A Finance Manager or CFO can ask "what do we currently owe in Saudi Arabia" or "are we exposed anywhere this quarter" and receive an answer sourced from actual posted `tax_transactions`, not a reconstructed spreadsheet.
- A `tax_returns` draft exists, reconciled against the GL, days before the statutory due date — never assembled from scratch the night before filing.
- The one step that cannot be undone — filing with a government authority — always waits for a named human holding `tax.submit`.

# Role & Mandate

## Mandate

The Tax Advisor owns seven concrete responsibilities inside the Tax Management domain:

1. **Tax Classification.** Assign or propose `tax_code_id` and `product_tax_treatment` for new or edited products and services, and resolve transaction lines the deterministic `tax_rules` engine cannot match unambiguously (no rule matched, or two rules tie on `priority`).
2. **Liability Computation.** Continuously aggregate posted `tax_transactions` into per-jurisdiction, per-period VAT and Withholding Tax liability views — output tax, recoverable input tax, non-recoverable input tax, net payable — not only at period close.
3. **Return Preparation.** Generate a `tax_returns` and `tax_return_lines` draft for a given `tax_registrations` row and period, box-mapped to the jurisdiction's statutory form, cross-checked against `ledger_entries` before it is ever shown to a human.
4. **Deadline Monitoring.** Track `tax_returns.due_date` and each registration's filing calendar, and proactively push a return through the workflow stages it is allowed to move autonomously (draft → under_review) well ahead of the statutory date.
5. **Exposure & Optimization Flagging.** Surface unrecognized or at-risk liability (an unregistered vendor charging tax, a missing or expiring exemption certificate, an approaching economic-nexus or registration threshold) and optimization opportunities (a recovery-ratio trend, the cash-flow value of a cash- vs. accrual-basis filing election).
6. **Explanation.** Answer "why was this taxed at 15% and not 5%?" for any posted line, bilingually (English/Arabic), fully traceable to the deterministic calculation lineage stored on the transaction.
7. **Jurisdiction Awareness.** Maintain working knowledge of the GCC VAT states, Kuwait's dormant-VAT/corporate-tax/Zakat context, and KSA ZATCA's e-invoicing compliance fields — sourced from `tax_jurisdictions.compliance_profile` and the ingested regulation feed, never hardcoded per-country branches in a prompt.

## Explicit Exclusions

The Tax Advisor does **not**:

- Compute a tax amount. `TaxCalculationService` (Laravel, deterministic) does; the Tax Advisor consumes its output.
- Post a journal entry. The Accounting core's `JournalPostingService` does, from `tax.calculated`/`tax.committed` domain events.
- Execute a bank transfer to remit a liability. Treasury Manager and the Banking module do, gated by `bank.transfer` human approval.
- Release payroll. Payroll Manager does; the Tax Advisor only supplies the income-tax-withholding and payroll-tax classification context that payroll's own tax calculation path consumes.
- File a return. Filing (`tax.submit`) is human-only and AI-never — see Guardrails & Human Approval.
- Adjudicate a genuinely unsettled legal tax position. It drafts the position implied by the company's configured `tax_rules` and prior precedent, and escalates to a human tax advisor or counsel whenever confidence is low and the exposure is material.

## Table Ownership

| Table | Tax Advisor's Relationship |
|---|---|
| `tax_codes`, `tax_rates`, `tax_rules` | Reads to classify and explain; proposes changes via `ai/proposals`; never writes directly |
| `tax_transactions` | Reads only — for aggregation, anomaly baselines, and explanation. Only `TaxCalculationService` in commit mode persists these rows |
| `tax_returns`, `tax_return_lines` | Creates draft rows via `POST /api/v1/tax/returns` — a real write, but the row is born in `status = 'draft'` with zero legal effect until a human advances it |
| `exemption_certificates`, `recovery_ratios` | Reads for classification and liability context; proposes new certificates or ratio updates, never approves its own proposal |
| `journal_entries`, `journal_lines`, `ledger_entries` | Never reads or writes directly against the ledger tables — consumes posted totals only through the Reports/`ledger_entries` projection for the GL cross-check |
| `ai_tasks`, `ai_decisions` | Owns — every unit of work and every output is a row in these tables, described in Inputs and Outputs below |

# Autonomy Level (auto / suggest-only / requires-approval, per action)

Every action the Tax Advisor can take falls into exactly one of three tiers: **auto** (executes and logs, reversible), **suggest-only** (produces a proposal a human must accept), or **requires-approval** (a named human with a specific permission must act; the agent cannot even attempt the transition).

| Action | Autonomy | Trigger / Threshold | Permission Gate |
|---|---|---|---|
| Classify a new product/service tax treatment | Auto-apply at confidence ≥ 0.90 with a 100%-consistent historical pattern in that category; suggest-only at 0.70–0.89; blocked below 0.70 | `product.created` / `product.updated` | None to propose; `tax.config.manage` to override an auto-applied result |
| Classify an ambiguous transaction line | Suggest-only, always | No `tax_rules` match, or a priority tie, during `tax.calculate` | `tax.read` to view; a human with the document's own edit permission accepts |
| Compute a rolling VAT/WHT liability view | Fully autonomous, read-only | Continuous, on every `tax.committed` event, and on demand | `tax.read` |
| Generate a `tax_returns` draft for a closed period | Autonomous to create the draft row; zero legal effect | Scheduled at `period.closed`, or on demand | None — the row is `status = 'draft'` |
| Move a draft to `under_review` | Autonomous | Immediately after the GL cross-check passes | Pure workflow state, not a filing action |
| Approve a return (`under_review` → `ready_to_file`) | **Requires approval, always human** | N/A | `tax.filing.approve` — the Tax Advisor cannot hold this permission |
| File a return (`ready_to_file` → `filed`) | **Requires approval, always human, never AI-only** | N/A | `tax.submit` — hard-coded platform rule, not a configurable policy |
| Propose a manual tax adjustment | Suggest-only, always | Reconciliation variance or anomaly detected | `tax.adjust` required to create the actual adjustment row |
| Flag exposure or an optimization opportunity | Fully autonomous notification, zero mutation | Continuous / scheduled digest | `tax.read` to view |
| Alert on an approaching filing deadline | Fully autonomous notification | `due_date` within 14 / 7 / 3 / 1 days | None |
| Alert on an exemption certificate expiring | Fully autonomous notification | `valid_to` within 30 days | None — informational, co-surfaced with Compliance Agent |
| Surface a regulation-change alert with a pre-drafted rate proposal | Suggest-only, always | Scheduled gazette/bulletin ingestion | `tax.rate.manage` required to commit the change |
| Explain a posted tax line | Fully autonomous | On request | `tax.read` |

The 0.90 / 0.70 confidence bands are not per-agent folklore; they are the platform-standard thresholds defined for AI-assisted classification across QAYD (see the Tax Management module's AI Responsibilities), and the Tax Advisor inherits them rather than defining its own. A company may raise its own auto-apply threshold (e.g., require 0.95 for regulated industries) via a per-company AI policy setting, but it may never lower the `tax.submit`/`tax.filing.approve` gates — those two transitions have no policy override anywhere in the system.

# Inputs

## Event Subscriptions

| Domain Event | Source Module | Tax Advisor Action |
|---|---|---|
| `product.created`, `product.updated` | Products | Classify tax treatment if `tax_code_id` is absent or a category signal changed |
| `invoice.created`, `bill.created` (commit mode) | Sales / Purchasing | Ingest the resulting `tax_transactions` into the rolling liability view; run validation cross-checks |
| `payroll.completed` | Payroll | Ingest employee WHT and payroll-tax lines into the liability view; verify residency/treaty classification for expatriate withholding |
| `stock_adjustment.created` | Inventory | Verify a deemed-supply output tax line exists where the adjustment reason requires one (`own_use`, `gift`, `sample`) |
| `period.closed` | Accounting (fiscal periods) | Trigger `tax_returns` draft generation for every active registration in the closed period |
| `tax.calculated`, `tax.committed` | Tax Management (own domain) | Update liability aggregates; feed the anomaly-detection baseline |
| `schedule.deadline_scan` (daily cron) | AI Layer scheduler | Scan `tax_returns.due_date` and registration filing calendars for upcoming obligations |
| `schedule.regulation_ingest` (daily cron) | AI Layer scheduler | Pull ZATCA/FTA/Kuwait Ministry of Finance bulletins; diff against current `tax_rates`/`tax_rules` |
| `document.ocr_completed` | Document AI / OCR Agent | Receive structured OCR fields (vendor tax registration number, invoice tax lines) as classification/validation input |

## Structured Data Read

`tax_registrations` (which jurisdictions/system types are active and their filing basis), `tax_jurisdictions` (including `compliance_profile` for e-invoicing/QR requirements), `tax_codes` / `tax_rates` / `tax_rules` (current and historical, for effective-dated lookups), `exemption_certificates` (validity windows), `recovery_ratios` (current and prior fiscal year), `fiscal_years` / `fiscal_periods` (period boundaries), and a trailing 12-month window of `tax_transactions` for anomaly baselines and trend-based optimization heuristics.

## Unstructured Data

Attached source documents (vendor bills, customs declarations, exemption certificates) reach the Tax Advisor only as Document AI/OCR Agent's already-structured field extraction — the Tax Advisor never parses a raw PDF or image itself. Government gazette and tax-authority bulletin text (ZATCA circulars, Kuwait Ministry of Finance announcements, GCC VAT framework amendments) is ingested by a scheduled WebFetch/RSS job and passed through a summarization step before it reaches the Tax Advisor's reasoning context.

## User-Initiated Requests

A human can address the Tax Advisor directly through `ai_conversations`/`ai_messages` ("prepare the Q2 KSA VAT return," "why did this invoice charge 15%," "what's our exposure in Bahrain this quarter"). These arrive as the same `ai_tasks` row shape as system-triggered work, with `created_by` populated instead of `NULL`.

## `ai_tasks` — Work Queue

Every unit of work the Tax Advisor performs — system-triggered or user-initiated — is a row in the shared `ai_tasks` table:

```sql
CREATE TYPE ai_task_status AS ENUM (
    'queued', 'in_progress', 'awaiting_approval', 'completed', 'rejected', 'failed', 'cancelled'
);

CREATE TYPE ai_task_priority AS ENUM ('low', 'normal', 'high', 'urgent');

CREATE TABLE ai_tasks (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT NOT NULL REFERENCES companies(id),
    branch_id              BIGINT NULL REFERENCES branches(id),
    agent_code             VARCHAR(64) NOT NULL,
    task_type              VARCHAR(64) NOT NULL,
    priority                ai_task_priority NOT NULL DEFAULT 'normal',
    status                 ai_task_status NOT NULL DEFAULT 'queued',
    trigger_event           VARCHAR(64) NULL,
    input_payload           JSONB NOT NULL DEFAULT '{}',
    related_entity_type      VARCHAR(64) NULL,
    related_entity_id        BIGINT NULL,
    assigned_to_user_id       BIGINT NULL REFERENCES users(id),
    started_at               TIMESTAMPTZ NULL,
    completed_at             TIMESTAMPTZ NULL,
    error_message             TEXT NULL,
    created_by                BIGINT NULL REFERENCES users(id),
    updated_by                BIGINT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                TIMESTAMPTZ NULL
);
CREATE INDEX idx_ai_tasks_company_status ON ai_tasks(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_ai_tasks_agent          ON ai_tasks(agent_code, status);
CREATE INDEX idx_ai_tasks_related        ON ai_tasks(related_entity_type, related_entity_id);
```

Example row (`input_payload` for a classification task raised off `product.created`):

```json
{
  "id": 88231,
  "company_id": 4110,
  "branch_id": 62,
  "agent_code": "tax_advisor",
  "task_type": "classify_product_tax",
  "priority": "normal",
  "status": "queued",
  "trigger_event": "product.created",
  "input_payload": {
    "product_id": 19207,
    "name_en": "Portable Solar Power Bank 20,000mAh",
    "name_ar": "بطارية شمسية متنقلة 20000 مللي أمبير",
    "product_category_id": 341,
    "category_name_en": "Consumer Electronics — Portable Power",
    "default_jurisdiction_id": 3,
    "default_jurisdiction_code": "SA-RIY"
  },
  "related_entity_type": "products",
  "related_entity_id": 19207,
  "created_by": null,
  "created_at": "2026-07-16T06:02:11Z"
}
```

# Outputs (confidence + reasoning + sources)

Every Tax Advisor output — a classification, a liability view, a draft return, a flag, an explanation — is persisted as a row in the shared `ai_decisions` table and always carries four things without exception: a `confidence_score`, a `reasoning` trace, a `suggested_action`, and an array of source references (`supporting_document_ids`, `source_transaction_ids`, `source_rule_ids`, `source_rate_ids`).

## `ai_decisions` — Decision Log

```sql
CREATE TYPE ai_decision_type AS ENUM (
    'classification', 'liability_computation', 'return_draft', 'exposure_flag',
    'optimization_recommendation', 'explanation', 'deadline_alert', 'regulation_alert',
    'anomaly_flag', 'validation_result'
);

CREATE TYPE ai_decision_status AS ENUM (
    'proposed', 'auto_applied', 'accepted', 'rejected', 'superseded'
);

CREATE TABLE ai_decisions (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                BIGINT NOT NULL REFERENCES companies(id),
    branch_id                 BIGINT NULL REFERENCES branches(id),
    ai_task_id                 BIGINT NULL REFERENCES ai_tasks(id),
    agent_code                 VARCHAR(64) NOT NULL DEFAULT 'tax_advisor',
    decision_type              ai_decision_type NOT NULL,
    related_entity_type         VARCHAR(64) NULL,
    related_entity_id           BIGINT NULL,
    confidence_score            NUMERIC(5,4) NOT NULL CHECK (confidence_score BETWEEN 0 AND 1),
    reasoning                   TEXT NOT NULL,
    suggested_action             JSONB NOT NULL DEFAULT '{}',
    supporting_document_ids       BIGINT[] NULL,
    source_transaction_ids        BIGINT[] NULL,
    source_rule_ids               BIGINT[] NULL,
    source_rate_ids                BIGINT[] NULL,
    status                       ai_decision_status NOT NULL DEFAULT 'proposed',
    reviewed_by                   BIGINT NULL REFERENCES users(id),
    reviewed_at                   TIMESTAMPTZ NULL,
    review_notes                  TEXT NULL,
    model_name                    VARCHAR(64) NOT NULL,
    prompt_version                VARCHAR(32) NOT NULL,
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                    TIMESTAMPTZ NULL
);
CREATE INDEX idx_ai_decisions_company_type ON ai_decisions(company_id, decision_type) WHERE deleted_at IS NULL;
CREATE INDEX idx_ai_decisions_entity        ON ai_decisions(related_entity_type, related_entity_id);
CREATE INDEX idx_ai_decisions_status        ON ai_decisions(company_id, status);
```

`reviewed_by`/`reviewed_at`/`review_notes` are populated the moment a human accepts, rejects, or overrides a `suggest-only` decision — this is what lets Metrics & Evaluation compute an auto-apply accuracy rate and a suggest-accept rate per classification category.

## Example: Classification Output (auto-applied)

```json
{
  "id": 552017,
  "decision_type": "classification",
  "related_entity_type": "products",
  "related_entity_id": 19207,
  "confidence_score": 0.9400,
  "reasoning": "Product 'Portable Solar Power Bank 20,000mAh' matches product_category_id=341 (Consumer Electronics — Portable Power), which has 47/47 (100%) of prior products classified as tax_code SA-VAT-STD in jurisdiction SA-RIY over the trailing 12 months. No excise-liable attributes present (not tobacco/energy-drink/carbonated). No zero-rating or export signal on the product master. Auto-apply threshold (0.90) met.",
  "suggested_action": {
    "action": "set_product_tax_code",
    "tax_code_id": 205,
    "tax_code": "SA-VAT-STD",
    "product_tax_treatment": "standard"
  },
  "source_rule_ids": [12],
  "source_rate_ids": [214],
  "status": "auto_applied",
  "model_name": "qayd-tax-advisor-v1",
  "prompt_version": "2026.07.1"
}
```

## Example: Liability Computation Output

```json
{
  "decision_type": "liability_computation",
  "related_entity_type": "tax_registrations",
  "related_entity_id": 3,
  "confidence_score": 1.0000,
  "reasoning": "Aggregated from 214 posted tax_transactions rows dated between 2026-07-01 and 2026-07-16 for jurisdiction SA (Saudi Arabia VAT), tax_registration_id=3. Deterministic sum, no model inference in the arithmetic itself; confidence reflects data completeness, not a probabilistic estimate.",
  "suggested_action": { "action": "none", "note": "informational view" },
  "data": {
    "jurisdiction": "Saudi Arabia — VAT",
    "period_to_date": "2026-07-01 to 2026-07-16",
    "output_tax": "79420.0000",
    "input_tax_recoverable": "26150.0000",
    "input_tax_non_recoverable": "1180.0000",
    "net_payable_to_date": "53270.0000",
    "currency_code": "SAR",
    "reconciled_against_gl": true,
    "gl_variance": "0.0000"
  },
  "source_transaction_ids": [881021, 881022, 881040]
}
```

## Example: Exposure Flag

```json
{
  "decision_type": "exposure_flag",
  "related_entity_type": "tax_registrations",
  "related_entity_id": 3,
  "confidence_score": 0.8100,
  "reasoning": "3 bills in the last 30 days (BILL-2026-05702, BILL-2026-05733, BILL-2026-05761) recorded input VAT from vendor_id=663, who has no tax_registration_number on file. Under KSA VAT law only registered vendors may charge VAT. If vendor 663 is genuinely unregistered, SAR 2,140.00 of claimed input tax may be disallowed on audit.",
  "suggested_action": {
    "action": "verify_vendor_registration",
    "vendor_id": 663,
    "affected_bill_numbers": ["BILL-2026-05702", "BILL-2026-05733", "BILL-2026-05761"],
    "exposure_amount": "2140.0000",
    "currency_code": "SAR"
  },
  "source_transaction_ids": [882210, 882233, 882259],
  "status": "proposed"
}
```

## Example: Optimization Recommendation

```json
{
  "decision_type": "optimization_recommendation",
  "related_entity_type": "tax_registrations",
  "related_entity_id": 3,
  "confidence_score": 0.7600,
  "reasoning": "Trailing 4 quarters show accounts-receivable average collection cycle of 58 days against a monthly accrual-basis VAT filing, meaning output tax is regularly remitted 25-40 days before the corresponding cash is collected. A cash-basis election (where the jurisdiction and registration size permit it) would defer output tax recognition to the receipt date.",
  "suggested_action": {
    "action": "review_filing_basis_election",
    "current_basis": "accrual",
    "proposed_basis": "cash",
    "estimated_cash_flow_impact_per_quarter": "42000.0000",
    "currency_code": "SAR",
    "requires": "Finance Manager review + tax_registrations update"
  },
  "status": "proposed"
}
```

## Example: Explanation Output

```json
{
  "decision_type": "explanation",
  "related_entity_type": "tax_transactions",
  "related_entity_id": 881021,
  "confidence_score": 1.0000,
  "reasoning": "tax_transaction 881021 is a line on invoice INV-2026-09104 (branch SA-Riyadh, customer 4821). tax_rule_id=12 (priority 10) matched because: transaction_type=sale, jurisdiction=SA, customer_tax_status=registered, product_tax_treatment=standard. It resolved tax_code_id=205 (SA-VAT-STD). tax_rate_id=214 was in force (effective_from 2020-07-01, effective_to NULL) on the invoice's tax_point_date 2026-07-16, at rate_percentage=15.0000. Taxable base 1,000.0000 SAR x 15% = 150.0000 SAR, rounding_rule=round_half_up, no rounding adjustment needed.",
  "suggested_action": { "action": "none" },
  "source_transaction_ids": [881021],
  "source_rule_ids": [12],
  "source_rate_ids": [214]
}
```

# Tools & API Access (endpoint + permission-key table)

The Tax Advisor calls the Laravel API exclusively through a fixed set of FastAPI-side MCP tool definitions. Every tool maps to exactly one `/api/v1/tax/*` endpoint and inherits that endpoint's permission requirement — the AI layer does not get a separate, looser permission surface.

## Tool → Endpoint Mapping

| Tool Name | HTTP | Path | Permission Key | Mode |
|---|---|---|---|---|
| `get_tax_jurisdictions` | GET | `/api/v1/tax/jurisdictions` | `tax.read` | Read |
| `get_tax_registrations` | GET | `/api/v1/tax/registrations` | `tax.read` | Read |
| `get_tax_codes` | GET | `/api/v1/tax/codes` | `tax.read` | Read |
| `get_tax_rates` | GET | `/api/v1/tax/rates` | `tax.read` | Read |
| `get_tax_rules` | GET | `/api/v1/tax/rules` | `tax.read` | Read |
| `calculate_tax_preview` | POST | `/api/v1/tax/calculate` (`mode: "preview"`) | `tax.calculate` | Preview (no persistence) |
| `list_tax_transactions` | GET | `/api/v1/tax/transactions` | `tax.read` | Read |
| `get_tax_liability_report` | GET | `/api/v1/tax/reports/liability` | `reports.export` + `tax.filing.read` | Read |
| `get_vat_report` | GET | `/api/v1/tax/reports/vat-report` | `reports.export` + `tax.filing.read` | Read |
| `get_audit_report` | GET | `/api/v1/tax/reports/audit-report` | `reports.export` + `tax.audit.read` | Read (Auditor's primary report; Tax Advisor reads for cross-check) |
| `list_exemption_certificates` | GET | `/api/v1/tax/exemption-certificates` | `tax.read` | Read |
| `list_recovery_ratios` | GET | `/api/v1/tax/recovery-ratios` | `tax.read` | Read |
| `run_tax_validation` | POST | `/api/v1/tax/validate` | `tax.read` | Preview (no persistence) |
| `prepare_tax_return_draft` | POST | `/api/v1/tax/returns` | `tax.filing.prepare` | Draft-write (`status='draft'`, zero legal effect) |
| `get_tax_return` | GET | `/api/v1/tax/returns/{id}` | `tax.filing.read` | Read |
| `submit_return_for_review` | POST | `/api/v1/tax/returns/{id}/submit-for-review` | `tax.filing.prepare` | Workflow state only |
| `list_ai_proposals` | GET | `/api/v1/tax/ai/proposals` | `tax.read` | Read |
| `create_ai_proposal` | POST | `/api/v1/tax/ai/proposals` | `tax.read` (creating a proposal requires no elevated grant; applying one does) | Propose |
| `bulk_recalculate_preview` | POST | `/api/v1/tax/bulk/recalculate` | `tax.adjust` | Preview (reconciliation only, never commits) |

## Blocked Endpoints — Never Called by the Tax Advisor

| Path | Permission | Why It Is Blocked |
|---|---|---|
| `POST /api/v1/tax/returns/{id}/approve` | `tax.filing.approve` | Approval is a named-human judgment call on a document about to become filing-ready; see Guardrails |
| `POST /api/v1/tax/returns/{id}/file` | `tax.submit` | Filing is irreversible and platform-wide human-only; no AI agent, anywhere in QAYD, may call this endpoint |
| `POST /api/v1/tax/returns/{id}/record-ack` | `tax.submit` | Recording a government acknowledgment is part of the same human-owned filing action |
| `POST /api/v1/tax/rates`, `/api/v1/tax/rates/schedule-change` | `tax.rate.manage` | Rate changes are proposed (`create_ai_proposal`) and committed only by a human via `TaxRateService::scheduleRateChange()` |
| `POST /api/v1/tax/rules`, `PUT /api/v1/tax/rules/{id}/reorder` | `tax.rule.manage` | Rule authoring is configuration, not classification; proposed only |
| `POST /api/v1/tax/exemption-certificates` | `tax.exemption.manage` | The Tax Advisor may recommend a certificate be requested/renewed; it never registers one |
| `POST /api/v1/tax/recovery-ratios` | `tax.recovery.manage` | A recovery ratio requires an `approved_by` human by definition (see `recovery_ratios` in the Tax Management module) |
| `POST /api/v1/tax/import` | `tax.config.manage` | Bulk country-pack import is an onboarding/administration action, not an agent action |
| `POST /api/v1/tax/transactions/{id}/reverse`, `/api/v1/tax/adjustments` | `tax.adjust` | The Tax Advisor proposes an adjustment; a human with `tax.adjust` creates the actual reversing/adjusting row |

## Example MCP Tool Definition

```json
{
  "name": "prepare_tax_return_draft",
  "description": "Generate a draft tax_returns row with populated tax_return_lines for a closed fiscal period and an active tax_registrations row. The draft has zero legal effect until a human moves it past 'draft' status.",
  "parameters": {
    "type": "object",
    "properties": {
      "tax_registration_id": { "type": "integer", "description": "The tax_registrations row to file against" },
      "period_start": { "type": "string", "format": "date" },
      "period_end": { "type": "string", "format": "date" },
      "reconcile_against_gl": { "type": "boolean", "default": true }
    },
    "required": ["tax_registration_id", "period_start", "period_end"]
  },
  "maps_to": "POST /api/v1/tax/returns",
  "permission_required": "tax.filing.prepare"
}
```

# Data Access & Tenant Scope

Every call the Tax Advisor makes runs inside the same multi-tenant boundary as a human request: the FastAPI layer receives the acting company's `company_id` (and `branch_id` where applicable) as part of the task context, forwards it as the `X-Company-Id` header on every Laravel API call, and Laravel's own middleware — not the AI layer — is the authoritative enforcement point. A task or tool call arriving without a valid, resolvable `X-Company-Id` is rejected before any retrieval step runs.

- **No cross-tenant reasoning.** The Tax Advisor's retrieval layer (tax rules/rates/codes lookup, historical classification precedent, anomaly baselines) is always filtered to the single active `company_id`. A pattern learned from Company A's classification history is never available when reasoning about Company B, even if the two companies are in the same industry and jurisdiction.
- **Redis-backed memory is namespaced per company.** The Tax Advisor's working memory (`ai_memory`, full schema owned by the platform's shared AI Memory specification) is keyed by `company_id` in both the Redis cache layer and the underlying vector store; a cache key collision across tenants is structurally impossible because the key always includes `company_id` as a required prefix segment.
- **Branch scoping is respected, not assumed.** Where a company operates multiple branches across jurisdictions (a Kuwait head office with a Riyadh branch), the Tax Advisor resolves branch-level `tax_registrations` and only reasons about the registrations and rates relevant to the branch that issued the source document.
- **Read scope.** `tax_jurisdictions`, `tax_registrations`, `tax_categories`, `tax_codes`, `tax_rates`, `tax_rules`, `tax_groups`/`tax_group_items`, `exemption_certificates`, `recovery_ratios`, `tax_transactions`, `tax_returns`/`tax_return_lines`, `fiscal_years`/`fiscal_periods`, `products` (for classification context), `vendors`/`customers` (for counterparty tax status), and `ledger_entries` (read-only, for the GL cross-check).
- **Write scope.** None, directly. Every mutation is a proposal or a `status='draft'` row created through a permissioned endpoint, exactly as described in Tools & API Access.
- **Sensitive data handling.** Vendor/customer tax registration numbers are business-sensitive but not personal data; exemption certificate scans (which may contain government-issued identifiers for diplomatic/charity exemptions) are accessed only via the polymorphic `attachments` table's signed URLs, generated per-request and scoped to the permission of the human on whose behalf the agent is acting — the Tax Advisor never receives or caches a raw file, only a structured extraction (from Document AI/OCR Agent) plus a short-lived reference.
- **Government acknowledgment payloads** (`tax_returns.government_ack_payload`) are read-only for the Tax Advisor (used to confirm a return's final state for reporting) and are never used as a data source for future classification reasoning, since they contain no tax-treatment signal.

# Reasoning & Prompt Strategy

## Orchestration Graph

The Tax Advisor runs as a LangGraph-style state graph with five reasoning nodes, re-entered per task:

```
                 ┌─────────────┐
   ai_tasks row  │   Retrieve   │  MCP tools: get_tax_rules, get_tax_rates,
   ───────────▶  │   Context    │  get_tax_registrations, list_tax_transactions,
                 └──────┬───────┘  vector search over prior ai_decisions
                        │
                        ▼
                 ┌─────────────┐
                 │  Classify /   │  Never invents a tax amount — only ever
                 │  Aggregate    │  selects a tax_code, sums posted amounts,
                 └──────┬───────┘  or drafts a return_lines structure
                        │
                        ▼
                 ┌─────────────┐
                 │  Cross-Check  │  Compares against ledger_entries /
                 │  (GL variance)│  reconciled_against_gl; downgrades
                 └──────┬───────┘  confidence on any variance > tolerance
                        │
                        ▼
                 ┌─────────────┐
                 │   Score &     │  Assigns confidence_score, writes
                 │   Explain     │  reasoning, cites source_*_ids
                 └──────┬───────┘
                        │
                        ▼
                 ┌─────────────┐
                 │  Act or       │  Auto-apply / create draft / raise
                 │  Escalate     │  proposal / route to human queue,
                 └───────────────┘  per the Autonomy Level table
```

## Deterministic-First Principle

The single governing rule of the Tax Advisor's prompt design: **never re-derive a number the deterministic engine already produced.** If a `tax_transactions` row exists for a line, the agent quotes `tax_amount`, `rate_snapshot_percentage`, and `calculation_lineage` verbatim; it does not re-multiply base × rate itself, even to "double check," because a floating-point or rounding-rule mismatch between an LLM's arithmetic and the Laravel service's `NUMERIC(19,4)` fixed-point arithmetic is a defect class the platform is explicitly designed to make impossible. The only arithmetic the Tax Advisor performs is aggregation (summing already-computed `tax_amount` values across a filtered set of rows) and estimation clearly labeled as such (forecasts, cash-flow impact estimates).

## Retrieval-Augmented Reasoning

Context assembly draws on three retrieval sources, each embedded and searched via the shared AI Memory vector store (pgvector-backed, schema owned by the platform's AI Memory specification, not redefined here):

1. **Configuration retrieval** — the company's current `tax_rules` (with `conditions` JSONB), `tax_codes`, and `tax_rates` relevant to the transaction's jurisdiction and date, fetched structurally (SQL, not semantic search) since this data is exact and small.
2. **Precedent retrieval** — nearest-neighbor semantic search over embeddings of previously **approved** `ai_decisions` (classification decisions a human accepted, or that were auto-applied and never later corrected), scoped to the company and, secondarily, to an anonymized cross-tenant pattern library for genuinely novel product categories where no company-specific precedent exists yet (cross-tenant patterns carry a materially lower confidence ceiling — see Failure Modes & Edge Cases).
3. **Regulatory retrieval** — summarized, dated excerpts from ingested ZATCA/FTA/Kuwait MoF bulletins, retrieved when a task concerns a jurisdiction with recent regulatory activity.

## Confidence Calibration

Confidence is not a single LLM-reported number taken at face value; it is a composite of (a) the model's own calibrated self-assessment for the classification/reasoning step, (b) the strength of the precedent match (percentage of historically-consistent prior classifications in the same category), and (c) the Cross-Check node's GL variance result. A precedent match at 100% consistency with a small model self-assessment gap still yields a high composite score; a GL variance above the 0.01 base-currency tolerance caps the composite confidence for that task at 0.60 regardless of how confident the underlying classification step was, forcing it into suggest-only territory.

## System Prompt Excerpt

```
You are the Tax Advisor agent for QAYD, an AI Financial Operating System, operating for
company_id={{company_id}} only. You classify tax treatment, aggregate posted tax liability,
draft tax returns, and explain tax lines. You NEVER compute a tax amount yourself — quote
tax_transactions.tax_amount and calculation_lineage verbatim. You NEVER call an endpoint
requiring tax.filing.approve or tax.submit. Every response must include confidence_score
(0.0000-1.0000), reasoning citing specific row ids, and suggested_action. If required
tax_rules/tax_rates context is missing, respond with a validation escalation — do not guess
a rate or a jurisdiction. Kuwait has no VAT registration unless tax_registrations proves
otherwise; never assume a Kuwait transaction is VAT-liable.
```

## Reasoning Trace Storage

The full node-by-node trace (retrieved context, intermediate scores, the Cross-Check result) is stored in `ai_logs` (platform-shared table, full schema owned by the AI Memory/Workflow specifications) keyed by `ai_task_id`, while `ai_decisions.reasoning` stores the human-readable summary. This split keeps the decision log readable for a Tax Manager while preserving the full trace for engineering debugging and for the Auditor agent's deeper forensic review.

# Collaboration With Other Agents

The Tax Advisor is deliberately narrow. It depends on, and feeds, three collaborators constantly, and touches four more occasionally.

## Compliance Agent (primary collaborator)

The Compliance Agent owns the platform-wide compliance calendar, registration-threshold monitoring, and certificate-expiry tracking; the Tax Advisor owns tax-specific classification, liability computation, and return drafting. The two divide labor on every return-preparation cycle: the Tax Advisor cannot move a draft return past `under_review` on its own initiative for a jurisdiction the Compliance Agent has flagged with an open, unresolved compliance risk (e.g., a required ZATCA e-invoicing field missing on source invoices, or an exemption certificate underlying a claimed exemption having expired mid-period). Concretely:

- The Tax Advisor generates the draft and its GL cross-check.
- The Compliance Agent runs a pre-flight compliance check against the same period (open validation warnings, certificate expiries, registration coverage) before the draft is allowed to leave `draft` status.
- If the Compliance Agent's check fails, the return stays in `draft` with both agents' findings attached, and a joint task is raised for the Tax Manager rather than two separate, disconnected alerts.

## General Accountant (primary collaborator)

The General Accountant owns the actual journal-entry posting and period-close checklist; the Tax Advisor never posts a journal entry. The relationship is a supplier/consumer one: every committed `tax_transactions` row the Tax Advisor aggregates over already arrived in the ledger via the General Accountant's (or the domain module's) `JournalPostingService` call, tagged with `journal_lines.tax_transaction_id`. During period close, the General Accountant's own close checklist includes a step that consumes the Tax Advisor's liability computation and GL-variance flag as one of its close-readiness signals — a period cannot be marked ready to close if the Tax Advisor's `gl_variance` for any active registration is non-zero.

## Auditor (primary collaborator)

The Auditor consumes two things from the Tax Advisor continuously: the classification confidence scores and reasoning attached to every `ai_decisions` row (used to build risk-weighted sampling for control testing — a sample skews toward lower-confidence auto-applied classifications, not a uniform random sample), and on-demand Explanation outputs during an actual audit engagement, internal or external. The Auditor never asks the Tax Advisor to justify a number after the fact from scratch; the lineage is already attached to the transaction, so the Explanation call is a trace, not a re-analysis.

## Secondary Collaborators

| Agent | Relationship |
|---|---|
| **CFO** | Consumes the Tax Advisor's liability and optimization outputs for cash-flow and treasury planning; never issues instructions to the Tax Advisor directly |
| **Forecast Agent** | The Tax Advisor's trailing liability data is one input to the Forecast Agent's cash-flow projection; the Forecast Agent, not the Tax Advisor, owns the multi-period forecast itself |
| **Fraud Detection** | Shares the anomaly-detection signal on tax-to-revenue ratio outliers; Fraud Detection owns cross-domain fraud correlation (a tax anomaly plus a matching inventory or banking anomaly), the Tax Advisor owns the tax-specific baseline |
| **Document AI / OCR Agent** | Upstream suppliers — the Tax Advisor consumes their structured extraction of vendor bills and certificates, never raw documents |
| **Payroll Manager** | The Tax Advisor supplies income-tax-withholding bracket resolution and payroll-tax classification context that Payroll Manager's own calculation path consumes per pay run; Payroll Manager owns the payroll run itself |

## Joint Orchestration — Quarterly Return Preparation

```
period.closed event
       │
       ▼
Tax Advisor: aggregate tax_transactions → draft tax_returns + tax_return_lines
       │
       ▼
Tax Advisor: cross-check against ledger_entries → gl_variance
       │
       ├── variance > tolerance ──▶ escalate to General Accountant (reconciliation task)
       │
       ▼ (variance = 0)
Compliance Agent: pre-flight check (certificates, thresholds, e-invoicing fields)
       │
       ├── fails ──▶ joint task to Tax Manager, draft stays in 'draft'
       │
       ▼ (passes)
Tax Advisor: submit-for-review (draft → under_review)
       │
       ▼
Tax Manager (human): reviews line-by-line, may edit
       │
       ▼
Finance Manager (human, tax.filing.approve): approves (under_review → ready_to_file)
       │
       ▼
Named human (tax.submit): files (ready_to_file → filed)
       │
       ▼
Government portal acknowledgment recorded (record-ack)
       │
       ▼
Auditor: lineage available for the full filed return on demand
```

# Guardrails & Human Approval

## The Hard Gate

Filing a return with a government tax authority is the one Tax-Advisor-adjacent action in the entire platform that is structurally impossible for AI to perform, under any confidence score, any per-company policy, or any escalation path. The state machine enforces this at the database and API layer, not merely in agent instructions:

```
draft ──▶ under_review ──▶ ready_to_file ──▶ filed ──▶ accepted | rejected | amended
  ▲               ▲               ▲              ▲
  │               │               │              │
Tax Advisor    Tax Advisor    tax.filing.approve  tax.submit
(autonomous)   (autonomous,   (human only, no     (human only, no
               post GL        AI path exists)     AI path exists,
               cross-check)                       no policy override)
```

No `POST /api/v1/tax/returns/{id}/file` call can succeed without a bearer token belonging to a human user holding `tax.submit` — the FastAPI layer does not possess a service credential scoped to that permission, by design, so even a compromised or misconfigured AI process cannot call it. The same is true one step earlier for `tax.filing.approve`: the Tax Advisor's service identity is provisioned with `tax.read`, `tax.calculate`, `tax.filing.prepare`, and nothing higher in the tax permission hierarchy.

## Full Guardrail Enumeration

1. **No direct database writes.** Every mutation is an API call subject to the same `FormRequest` validation and RBAC check a human request faces.
2. **Permission mirroring.** The Tax Advisor's effective permissions for a given task are the lesser of its own service-account grants and the requesting human's grants when the task originated from a user message — it cannot see or act on anything the requesting user could not.
3. **Confidence-gated autonomy.** The 0.90/0.70 thresholds (see Autonomy Level) are enforced in the orchestration layer, not merely suggested in a prompt; a response below threshold is programmatically routed to `suggest-only` handling regardless of what the model itself claims.
4. **Mandatory reasoning and sourcing.** An `ai_decisions` row with a null or empty `reasoning`, or with zero entries across all four source-reference arrays, fails a schema-level validation in the FastAPI service layer before it is ever persisted or surfaced.
5. **Auto-applied actions remain visible and reversible.** Every auto-applied classification appears in a daily "AI-classified items" digest for the Tax Manager, and reverting one is a normal `tax.config.manage` edit — never a special "undo AI" operation requiring engineering involvement.
6. **No silent regulation application.** A regulation-change alert always stops at a pre-drafted, unsaved proposal; the Tax Advisor cannot insert or update a `tax_rates`/`tax_rules` row even at confidence 1.0000.
7. **Materiality-weighted escalation.** A classification or exposure flag below 0.70 confidence with an estimated exposure above a company-configured materiality threshold (default: 1% of the registration's trailing-quarter net tax payable) escalates directly to both the Tax Manager's and the Auditor's task queues, bypassing the routine weekly digest.
8. **Kill switch.** An Owner or Admin can disable the Tax Advisor entirely for a company (`ai.automation` permission toggle at the company settings level). The deterministic `TaxCalculationService` is unaffected by this switch — it is Laravel-native, not an AI capability — so tax calculation, invoicing, and posting continue to function with fully manual classification, liability review, and return drafting.
9. **Segregation of duties is structural, not procedural.** Because the agent that drafts a return and the human who approves it are different actors by construction (no permission set grants an AI service account `tax.filing.approve` or `tax.submit`), there is no configuration path that collapses drafting and approval into the same actor.
10. **Every permission check is logged**, per the platform-wide audit rule: which agent, which permission, which company, which result, timestamped — queryable by the Auditor at any time.

## Escalation Ladder

| Condition | Destination |
|---|---|
| Confidence 0.70–0.89, routine, below materiality | Tax Manager's standard review queue |
| Confidence < 0.70, below materiality | Tax Manager's review queue, flagged "low confidence" |
| Confidence < 0.70, above materiality | Tax Manager **and** Auditor queues, priority "high" |
| GL variance on a draft return > tolerance | General Accountant reconciliation task, draft blocked from advancing |
| Compliance Agent pre-flight failure | Joint task to Tax Manager, draft blocked from advancing |
| Missing `tax_rates` row (`TaxRateNotConfiguredException`) | Tax Manager, priority "urgent" (this blocks document posting elsewhere in the platform, not only the return) |

# Worked Scenarios (2-3 end-to-end)

## Scenario 1 — New Product Classification Across Kuwait and Saudi Arabia

A company with a Kuwait head office and a Riyadh branch adds a single new product, "Portable Solar Power Bank 20,000mAh," to its shared catalog. The product is sellable from both branches. `product.created` fires once; the Tax Advisor evaluates it once per active jurisdiction the product is enabled for sale in.

**Kuwait leg.** The company holds no `tax_registrations` row for Kuwait VAT — none exists in the platform as of this specification, since the jurisdiction and category framework is provisioned but dormant. The Tax Advisor's Retrieve Context step finds zero active VAT registration for jurisdiction KW and, per its system prompt's explicit instruction ("never assume a Kuwait transaction is VAT-liable"), resolves the Kuwait-leg classification to `product_tax_treatment = 'out_of_scope'` at confidence 1.0000 — this is a structural fact (no registration exists), not a probabilistic judgment, so it is fully autonomous with no suggest/auto distinction to make.

**Saudi Arabia leg.** For jurisdiction SA-RIY, `product_category_id = 341` ("Consumer Electronics — Portable Power") has 47 of 47 prior products in the trailing 12 months classified as `SA-VAT-STD`, none excise-liable, none zero-rated or export-flagged. The classification step returns confidence 0.9400, clears the 0.90 auto-apply threshold, and the Tax Advisor sets `tax_code_id = 205` (`SA-VAT-STD`) directly — the full `ai_decisions` row is the Example: Classification Output shown in Outputs above. The action is logged, reversible, and appears in that evening's AI-classified-items digest for the Riyadh branch's Tax Manager.

**Result.** The first invoice raised against this product from the Riyadh branch three days later (`INV-2026-09104`) calculates 15% VAT (SAR 150.0000 on a SAR 1,000.0000 base) with zero manual intervention; the same product sold from the Kuwait head office posts with no tax line, correctly, because no Kuwait VAT registration exists to require one.

## Scenario 2 — Withholding Tax on a Non-Resident Consulting Vendor (KSA)

The company's Riyadh branch engages a non-resident (US-incorporated) consulting firm, vendor_id 7742, for a technical advisory engagement. The vendor bill (`BILL-2026-05611`) for SAR 80,000.0000 arrives with no Saudi tax registration number, and `vendors.country_code = 'US'` differs from the branch's `SA`.

1. **Classification.** The Tax Advisor's Retrieve Context step finds `tax_rules` priority 8 matching `transaction_type = 'purchase'`, `vendor_tax_status = 'foreign'`, `service_category = 'technical_consulting'`, `jurisdiction_id = 3` (Saudi Arabia). The rule resolves to a `tax_code_id` for `SA-WHT-CONSULT` at a 15% withholding rate — within the 5%–20% range KSA applies to non-resident vendor payments depending on service type, and specifically the rate KSA's withholding schedule applies to technical and consulting services. Confidence 0.9100 (a strong rule match, no category ambiguity); this classification is a rule-resolution confirmation rather than a from-scratch guess, so it auto-applies.
2. **Liability computation.** `TaxCalculationService` (deterministic, not the Tax Advisor) computes the withholding: `withholding_tax_amount = 80,000.0000 × 15% = 12,000.0000 SAR`, reducing the net payment to the vendor to SAR 68,000.0000 and crediting Withholding Tax Payable. The Tax Advisor ingests the resulting `tax_transactions` row (`tax_direction = 'withholding_payable'`) into its rolling liability view.
3. **Certificate preparation.** The Tax Advisor drafts the withholding certificate content (vendor name, gross payment, rate, withheld amount, period) as a `suggested_action` attached to an `ai_decisions` row of `decision_type = 'return_draft'` scoped to the Withholding return type, for the Tax Manager to issue to the vendor — certificate issuance itself is a human action since it is an external-facing document.
4. **Return line contribution.** At period close, this transaction contributes a `tax_return_lines` row of `line_type = 'withholding_collected'` to the Riyadh branch's monthly Withholding Tax return draft, which the Tax Advisor generates alongside the VAT return for the same period.

The Tax Advisor never picks the 15% rate itself as free-form output — it cites `tax_rule_id = 8` and the resolved `tax_code`, and the actual multiplication is `TaxCalculationService`'s deterministic output, consistent with the platform's "tax is derived, never authored twice" principle.

## Scenario 3 — Quarterly KSA VAT Return: Preparation Through Filing

At `period.closed` for June 2026, the Tax Advisor generates a draft `tax_returns` row (`id = 9021`) for the Riyadh branch's KSA VAT registration (`tax_registration_id = 3`):

1. **Aggregation.** 1,120,000.0000 SAR of standard-rated domestic sales (168,000.0000 SAR output tax), 45,000.0000 SAR of reverse-charge domestic sales (6,750.0000 SAR self-assessed output tax), 212,000.0000 SAR zero-rated domestic sales, 398,000.0000 SAR exports, 18,000.0000 SAR exempt sales, 408,000.0000 SAR standard-rated purchases (61,200.0000 SAR recoverable input tax), and 45,000.0000 SAR of reverse-charge imports of services (6,750.0000 SAR self-assessed input tax) are pulled from posted `tax_transactions` for the period and mapped to `tax_return_lines` boxes 1a, 1b, 3, 4, 5, 9, and 11 respectively.
2. **Cross-check.** The Tax Advisor's Cross-Check node compares the sum of these lines against `ledger_entries` for the mapped Output/Input Tax GL accounts for the same period: variance = 0.0000 SAR. `reconciled_against_gl = true` on the resulting `ai_decisions` row.
3. **Net computation.** `net_vat_payable = 168,000.0000 + 6,750.0000 − 61,200.0000 − 6,750.0000 = 106,800.0000 SAR`. `tax_returns.ai_draft_generated = true`, `ai_confidence_score = 0.9700` (high — a clean GL match with no manual adjustments in the period).
4. **Compliance pre-flight.** The Compliance Agent checks certificate expiries and ZATCA e-invoicing field completeness for the period's invoices; all pass. The Tax Advisor moves the draft to `under_review`.
5. **Human review.** The Riyadh branch's Tax Manager reviews the draft, confirms the reverse-charge import line against the underlying vendor bill, and requests no changes.
6. **Approval.** The Finance Manager, holding `tax.filing.approve`, calls `POST /api/v1/tax/returns/9021/approve`. `status` moves `under_review → ready_to_file`.
7. **Filing.** A named human holding `tax.submit` calls `POST /api/v1/tax/returns/9021/file` with `approved_by_confirmation: true`. The return moves to `filed`; ZATCA's e-filing channel returns `government_reference_number: "ZATCA-VAT-2026-06-SA-000481223"`, recorded verbatim in `government_ack_payload`.
8. **Post-filing.** The Auditor can retrieve the full transaction-level lineage behind every one of the return's boxes at any later date via the Tax Advisor's Explanation output, without re-running any calculation.

At no point in this eight-step flow does the Tax Advisor call an endpoint gated by `tax.filing.approve` or `tax.submit` — steps 6 and 7 are executed exclusively by named humans through the standard Laravel API, using the same credentials and audit trail as any other user action.

# Metrics & Evaluation

## Operational KPIs

| Metric | Definition | Target |
|---|---|---|
| Auto-apply accuracy | % of auto-applied classifications (`status='auto_applied'`) never later corrected by a human edit | ≥ 98% |
| Suggest-accept rate | % of suggest-only proposals a human accepts without modification | ≥ 85% |
| Time-to-draft-return | Elapsed time from `period.closed` to a `tax_returns` draft reaching `under_review` | ≤ 4 hours |
| First-pass GL reconciliation rate | % of draft returns with `gl_variance = 0.0000` on the first Cross-Check pass | ≥ 95% |
| Deadline-miss rate | Returns filed after `due_date` | 0% |
| False-positive exposure rate | Exposure/anomaly flags a Tax Manager dismisses as non-issues | ≤ 15% |
| Confidence calibration error | Brier score between stated `confidence_score` and observed correctness on a held-out review sample | ≤ 0.08 |
| Human review turnaround | Time from `submit-for-review` to `approve` | ≤ 2 business days (company-configurable SLA) |
| Regulation-alert lead time | Days between an alert firing and the regulation's effective date | ≥ 14 days for confirmed sources |
| P95 classification latency | Time from `ai_tasks.status='queued'` to a classification `ai_decisions` row | ≤ 30 seconds |

## Evaluation Harness

- **Golden dataset regression.** A held-out, per-jurisdiction set of historically-classified transactions and products (with known-correct `tax_code_id`) is re-run against every candidate prompt/model version before promotion; a regression that drops auto-apply accuracy below the target blocks promotion.
- **Shadow mode.** A new model or prompt version runs in parallel with the production version for a minimum 14-day window, non-binding (its outputs are logged to `ai_decisions` with `status` forced to `proposed` regardless of confidence), before it is allowed to reach `auto_applied` status in production.
- **Human feedback loop.** Every `reviewed_by`/`review_notes` entry on a corrected classification feeds back into the precedent-retrieval embedding set (see Reasoning & Prompt Strategy) — an approved correction becomes a stronger future signal than the original, superseded proposal, whose `ai_decisions.status` is set to `superseded`.
- **Per-jurisdiction breakdown.** All of the above metrics are tracked per `jurisdiction_id`, not only in aggregate, since KSA (ZATCA e-invoicing, high transaction volume) and Kuwait (dormant VAT, corporate tax/Zakat only) have structurally different evaluation profiles.

# Failure Modes & Edge Cases

| # | Failure Mode | Tax Advisor Response |
|---|---|---|
| 1 | No `tax_rates` row for the resolved `tax_code`/jurisdiction/date (`TaxRateNotConfiguredException`) | Never guesses a rate. Surfaces as a 422 from the underlying calculation call; raises an "urgent" task to the Tax Manager to configure the missing rate rather than defaulting to zero tax |
| 2 | Conflicting classification signals (a product resembles two historical categories with different treatments) | Confidence drops below 0.70 by construction (precedent match strength is split across categories); forced to suggest-only, with both candidate classifications shown alongside their individual support |
| 3 | Regulation source ambiguity (unconfirmed draft law vs. an official gazette) | Alert explicitly labeled "unconfirmed — verify before acting" with a materially lower confidence score; never pre-applied even provisionally |
| 4 | GL/`tax_transactions` variance exceeds tolerance immediately before a filing deadline | Draft blocked from leaving `draft`; escalated jointly to General Accountant (reconciliation) and Tax Manager (deadline risk), bypassing the routine digest |
| 5 | Multi-currency filing conversion ambiguity (which FX rate for a KWD-base-currency company filing a SAR-denominated return) | Defers to the jurisdiction's documented conversion method (`tax_jurisdictions.compliance_profile`); any deviation from that documented method is flagged for review rather than silently resolved |
| 6 | Late-arriving correction (a bill posted after its period's return is already `ready_to_file`) | Never edits the in-flight or filed return; the correction is carried into the next period's return as a `prior_period_adjustment` line |
| 7 | Unsupported/uncited claim risk (model output references a fact with no backing row) | Orchestration layer rejects any `ai_decisions` row whose `reasoning` cites a fact without a corresponding id in `source_transaction_ids`/`source_rule_ids`/`source_rate_ids`; the task retries with tighter retrieval or escalates to a human rather than surfacing an uncited claim |
| 8 | Duplicate/conflicting AI proposals for the same entity from two ingestion paths (e.g., a manual re-classification request racing a scheduled digest) | Deduplicated on `(company_id, related_entity_type, related_entity_id, decision_type)` before a new `ai_decisions` row is created; the later one supersedes rather than duplicates |
| 9 | Cross-tenant leakage attempt (a malformed or missing `X-Company-Id`) | Rejected at the API gateway before any retrieval step executes; no partial context is ever assembled |
| 10 | Partial exemption recovery ratio not yet approved for the current fiscal year at draft time | Uses the prior year's approved ratio as an explicitly labeled provisional estimate; the affected `tax_return_lines` row is flagged "provisional — pending recovery ratio approval," never presented as final |
| 11 | Kuwait VAT fabrication risk (treating a Kuwait transaction as VAT-liable because neighboring GCC states already have live VAT) | Structurally prevented — the classification step checks for an active Kuwait VAT `tax_registrations` row before considering any VAT treatment for a Kuwait-jurisdiction transaction; absent one, the only possible outcome is `out_of_scope` |
| 12 | ZATCA e-invoicing compliance field gap (missing QR/hash-chain field on a KSA invoice) | Treated as a compliance defect, not a tax-amount question; routed to the Compliance Agent rather than handled as a Tax Advisor classification or liability issue |
| 13 | Model/prompt version drift mid-period (a new prompt version promoted while a period is still open) | The period's return draft continues to cite the `prompt_version` recorded at the time each contributing `ai_decisions` row was created; a version change never silently reinterprets already-logged reasoning |
| 14 | Company disables the Tax Advisor mid-period (kill switch) | All open `ai_tasks` for that company are marked `cancelled`; in-flight `tax_returns` drafts remain exactly as they were, fully editable by a human with `tax.filing.prepare`, since a draft is an ordinary row, not an AI-only artifact |
| 15 | Vendor/customer identity ambiguity (two vendors with near-identical names, one registered, one not) | Classification and validation always key off `vendor_id`/`customer_id`, never off name-matching; if the source document's OCR extraction cannot resolve a confident id match, the task escalates as a Document AI/OCR Agent data-quality issue rather than the Tax Advisor guessing which vendor record applies |
| 16 | Reverse-charge pairing failure (an output leg is generated but the matching input leg fails validation) | The Tax Advisor never presents a partial reverse-charge pair as a completed liability computation; a `reverse_charge_pair_id` with only one resolved leg blocks that transaction from the liability aggregate and raises a data-integrity task |

# Future Improvements

- Extend the country-pack retrieval beyond the initial six GCC states (Kuwait, Saudi Arabia, UAE, Bahrain, Oman, Qatar) to broader MENA jurisdictions as QAYD's footprint grows, without any change to the classification/aggregation/drafting logic itself.
- Replace the manual `record-ack` step with a real-time webhook listener against ZATCA's and the UAE FTA's e-filing status APIs, so a filed return's acceptance/rejection updates `tax_returns.government_ack_payload` automatically instead of requiring a human to paste in a reference number.
- Add a human-gated "what-if" simulation mode (branch vs. subsidiary restructuring, voluntary VAT registration timing, cash- vs. accrual-basis election) that runs the calculation engine against hypothetical `tax_registrations`/`tax_rules` configurations in a sandboxed company context, never touching production data.
- Broaden withholding-certificate automation to include direct, permissioned delivery to a vendor-facing portal, rather than the Tax Manager manually forwarding the prepared certificate content.
- Introduce a smaller, fine-tuned classification model for the highest-volume jurisdictions (KSA, UAE) to cut P95 classification latency and per-classification cost relative to a general-purpose LLM call, falling back to the general model for low-precedent, novel-category cases.
- Extend anomaly detection with graph-based vendor/customer relationship signals shared with Fraud Detection (e.g., a cluster of related vendors each individually below an exemption/registration threshold).
- Commission an in-market Arabic legal-terminology review of every explanation template with Kuwaiti and Saudi tax counsel, closing the gap between fluent Arabic and precise Arabic tax terminology.
- Add a per-registration "confidence trend" view so a Tax Manager can see whether the Tax Advisor's classification confidence for a given jurisdiction is improving or degrading over time, rather than only inspecting individual decisions.
- Extend deadline monitoring from single-return tracking to a portfolio view across all of a group's branches/subsidiaries, so a CFO overseeing a Kuwait-plus-KSA-plus-UAE structure sees one consolidated filing calendar instead of per-registration alerts.
- Pilot a constrained fine-tune on the Explanation responsibility specifically, since it is the highest-volume, lowest-risk (read-only, already-deterministic data) responsibility and the best early candidate for cost reduction without any change to autonomy or guardrails.

# End of Document
