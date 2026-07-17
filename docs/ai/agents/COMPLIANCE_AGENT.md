# Compliance Agent — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: COMPLIANCE_AGENT
---

# Purpose

The Compliance Agent is QAYD's continuous statutory- and policy-monitoring member of the autonomous
finance workforce. It does not wait to be asked whether a company is compliant — it watches, every day,
across every module that produces financial or people data, and it surfaces risk before a deadline is
missed, before a regulator asks a question, and before an auditor finds a gap.

Traditional accounting software is silent about compliance until year-end, when an external auditor or
a government notice forces the issue. QAYD inverts this: the Compliance Agent runs a standing watch over
GCC VAT obligations, Kuwait labor law and PIFSS/WPS payroll obligations, IFRS presentation and disclosure
requirements, internal control attestations, and statutory data-retention rules — for every tenant company,
every day, scoped strictly to that company's own data.

The agent's job is narrow and disciplined by design: it **monitors, evaluates, flags, and escalates**. It
never files a return, never releases a payroll run, never deletes a record, and never silently "fixes"
a compliance gap. Every statutory judgment it renders carries a confidence score, an explicit chain of
reasoning, and a citation to the underlying data and regulation. Where risk is severe enough, it raises a
**blocking flag** that other modules (Accounting period-close, Payroll release, Tax filing) must respect
until a human with the right permission clears it. Where risk is lower, it raises an **advisory flag** that
informs but does not stop work.

In the target operating model, the human accountant becomes the supervisor of a standing compliance
function that never sleeps, never forgets a deadline, and never lets a regulation change go unnoticed —
while every consequential action (a filing submission, a payroll release, a permission change, a record
purge) still requires a named human signature.

# Role & Mandate

**Mandate.** Continuously determine, for each tenant company, whether it is meeting its active statutory
and internal-policy obligations, and make that status legible and actionable to the humans accountable for
it — without ever taking an irreversible action itself.

**In scope:**
- Maintaining the company's applicable-requirement set (which statutory obligations apply to this company,
  in which jurisdiction, at what frequency) by evaluating the requirement catalog against company facts
  (registration status, revenue, headcount, entity type, branches).
- Tracking every filing instance (`compliance_filings`) to its due date and readiness state, and computing
  a readiness score ahead of the deadline.
- Running data-quality and rate/threshold checks against the company's actual ledgers, payroll, and tax
  records (e.g., "does the payroll run apply the currently configured PIFSS employer/employee rates and
  salary cap?", "are all input-VAT invoices reconciled before the return is marked ready?").
- Ingesting regulation-change signals (new law, amended rate, new IFRS standard, new filing form) and
  assessing applicability to each tenant before that tenant is affected.
- Preparing (never signing) control-attestation evidence packages for the humans who must attest.
- Classifying records against retention policy and flagging retention/legal-hold conflicts.
- Raising advisory flags (informational) and blocking flags (hard stop pending human override) with a
  documented severity and escalation path.

**Out of scope (explicitly not this agent's job):**
- Computing the tax liability itself (Tax Advisor's job; Compliance checks the Tax Advisor's output against
  the calendar and completeness rules, it does not compute VAT).
- Computing payroll amounts (Payroll Manager's job; Compliance checks the computed payroll against
  statutory rate/cap rules).
- Submitting anything to a government portal, bank, or regulator. Submission is always a human action
  (optionally assisted by module UI), never an agent action.
- Legal advice or interpretation beyond what is encoded in the requirement catalog and regulation
  knowledge base; ambiguous or novel legal questions are flagged for human/legal review, not answered
  with invented certainty.

# Autonomy Level

Every action the Compliance Agent can take is individually classified. This table is the authoritative
autonomy contract for this agent — any new capability added to the agent must be assigned one of these
three levels before it ships.

| # | Action | Autonomy Level | Notes |
|---|---|---|---|
| 1 | Compute days-to-deadline and refresh `compliance_filings.status` readiness score | **Auto** | Pure read + derived computation, no side effect outside `ai_*`/`compliance_*` staging rows |
| 2 | Run a scheduled data/rate/control assessment (`compliance_assessments`) | **Auto** | Read-only evaluation; writing the assessment row is an AI-owned table, not a financial record |
| 3 | Raise an **advisory** alert (`compliance_alerts`, severity low/medium) | **Auto** | Informational; no gate on any other module |
| 4 | Raise a **blocking** flag on a target action (e.g., fiscal period close, payroll release, WPS file export) | **Auto-raise, human-gated to clear** | The agent can set the hold immediately (time-sensitive); only a human with the required permission can clear it |
| 5 | Ingest and summarize a regulation-change signal | **Auto** | Summarization and jurisdiction/domain tagging only |
| 6 | Determine applicability of a regulation change to a specific company | **Suggest-only** | Produced as a proposal (`ai_decisions`) requiring Finance Manager/CFO confirmation before the requirement catalog entry is activated for that tenant |
| 7 | Draft a control-attestation evidence summary | **Suggest-only** | Drafts `control_attestations.ai_draft_summary`; never sets `status = 'signed'` |
| 8 | Sign/attest a control | **Requires-approval (human-only, cannot be automated)** | Attestation is by definition a named human's accountable statement |
| 9 | Mark a filing "ready to submit" | **Suggest-only** | Human (or the owning agent, e.g., Tax Advisor) confirms readiness; Compliance's role is to recommend, not declare |
| 10 | Submit a filing to a government portal/bank | **Out of agent scope entirely** | Always a human action through the relevant module |
| 11 | Tag a record with a retention class and computed purge-eligibility date | **Auto** | Metadata tagging only; no data is altered or moved |
| 12 | Move a record to archive/cold-storage tier at retention expiry | **Requires-approval** | Owner/Finance Manager approval, and blocked entirely if an active `retention_holds` row exists |
| 13 | Override / clear a blocking flag | **Requires-approval, restricted roles** | Only Owner, CFO, or Auditor; logged to `audit_logs` with reason |
| 14 | Escalate an unresolved alert up the accountability chain | **Auto** | Time-boxed by `sla_hours`; escalation is a notification action, not a data mutation of financial records |

Autonomy is enforced structurally, not just by convention: every write the agent wants to make on a
non-AI-owned table is expressed as an `ai_decisions` row and submitted through the same Laravel API +
FormRequest validation + RBAC gate a human would hit — the agent has no direct database credential and no
code path that bypasses `Gate::authorize()`.

# Inputs

The agent consumes four input classes, all read-only relative to the source of truth:

**1. Scheduled triggers** (from Laravel's scheduler via a `compliance.run` webhook to the FastAPI layer):

```json
{
  "task_type": "compliance.scan.daily",
  "company_id": 4821,
  "trigger": "scheduler.daily_03:00_AST",
  "window": { "from": "2026-07-16", "to": "2026-08-15" },
  "requested_by": null
}
```

**2. Event-driven triggers** (domain events the agent subscribes to via Reverb/webhook dispatch):

| Event | Emitted by | Compliance reaction |
|---|---|---|
| `journal.posted` | Accounting | Re-check any control tied to posting frequency/segregation-of-duties |
| `tax_return.draft_updated` | Tax Advisor | Recompute filing readiness score |
| `payroll_run.calculated` | Payroll Manager | Run PIFSS/WPS rate-and-cap assessment before release |
| `payroll_run.release_requested` | Payroll Manager | Synchronous pre-release gate check (may block) |
| `company.settings_updated` | Core | Re-evaluate requirement applicability (e.g., VAT registration number added) |
| `regulation_feed.new_item` | Regulation ingestion service | Classify, tag jurisdiction/domain, assess applicability |
| `fiscal_period.close_requested` | Accounting | Synchronous pre-close gate check (may block) |
| `attachment.uploaded` (OCR-tagged as `government_notice`) | Document AI / OCR Agent | Parse and route as a regulation-change or filing-acknowledgement signal |

**3. On-demand human queries** (via `ai_conversations`/`ai_messages`, e.g., a CFO asking "are we compliant with
GCC VAT this quarter?"), routed to the same evaluation pipeline as a scheduled run but scoped to the asked
question and answered conversationally with the same reasoning/citation discipline.

**4. Reference data it reads but does not own:** the requirement catalog (`compliance_requirement_templates`),
the regulation knowledge base (vector store, see Reasoning & Prompt Strategy), and per-company configuration
(fiscal year, base currency, VAT registration number, entity type, headcount, branch list).

# Outputs

Every output the agent produces — whether an internal assessment row, an alert, or a conversational answer
— carries the same three non-negotiable fields: **result**, **confidence**, **reasoning + sources**. There
is no code path that returns a bare pass/fail without them.

**Assessment output** (written to `compliance_assessments` via the AI service's own repository — this is an
AI-owned table, distinct from writes to financial tables which always go through the Laravel API):

```json
{
  "assessment_id": 90210,
  "company_id": 4821,
  "requirement_id": 1932,
  "filing_id": 58210,
  "check_type": "data_check",
  "result": "fail",
  "confidence": 0.93,
  "flag_type": "blocking",
  "reasoning": "Q2-2026 GCC VAT return (requirement KW_VAT_GCC_QUARTERLY) is due 2026-07-28. tax_returns record #7734 is still in 'draft' status with 3 unreconciled input-VAT bills (#4410, #4477, #4502) totalling KWD 812.4000. At the current reconciliation pace (0.4 bills/day over the last 14 days), the draft cannot reach 'ready' before the internal T-2 checkpoint (2026-07-26).",
  "sources": [
    { "table": "tax_returns", "id": 7734, "field": "status", "value": "draft" },
    { "table": "bills", "id": 4410, "field": "vat_reconciled", "value": false },
    { "table": "bills", "id": 4477, "field": "vat_reconciled", "value": false },
    { "table": "bills", "id": 4502, "field": "vat_reconciled", "value": false },
    { "table": "compliance_requirement_templates", "id": 12, "field": "code", "value": "KW_VAT_GCC_QUARTERLY" }
  ],
  "recommended_action": "Escalate to Tax Advisor to reconcile the 3 open bills within 48 hours; notify CFO of at-risk filing.",
  "created_ai_decision_id": 55019,
  "created_at": "2026-07-16T03:00:04Z"
}
```

**Alert output** (`compliance_alerts`, the human-facing surface — what shows up on the CFO's dashboard and
the Finance Manager's notification feed):

```json
{
  "alert_id": 66142,
  "company_id": 4821,
  "source": "assessment",
  "category": "deadline",
  "severity": "high",
  "title": "GCC VAT return Q2-2026 at risk of late filing",
  "summary": "3 unreconciled input-VAT bills are blocking readiness. 8 days remain to the statutory deadline (2026-07-28); 6 days remain to the internal checkpoint.",
  "status": "open",
  "escalation_level": 1,
  "due_date": "2026-07-28",
  "assessment_id": 90210,
  "ai_decision_id": 55019,
  "created_at": "2026-07-16T03:00:05Z"
}
```

**Conversational output** (answering a direct human question) follows the identical contract, just rendered
as prose with an inline citation list rather than a JSON payload — the underlying `reasoning`/`sources`
fields are shared code, not a second implementation.

**Confidence semantics.** Confidence is not a vibe — it is calibrated against three inputs: (a) the
completeness of the source data the check touched (missing fields lower confidence), (b) the recency/
certainty of the regulation-knowledge-base entry the check cites (a change ingested and human-reviewed
scores higher than one auto-classified 10 minutes ago), and (c) historical accuracy of this specific
`check_type` against human-confirmed outcomes (see Metrics & Evaluation). Any assessment built on a
regulation-KB entry still in `status = 'unverified'` is capped at `confidence <= 0.60` and always renders
as `flag_type = 'advisory'`, never `'blocking'` — an unverified reading of the law is never allowed to stop
someone's work.

## Data Model — PostgreSQL DDL

These tables are owned by the Compliance Agent domain. All are tenant-scoped with the standard columns
except the system-level template catalog, which is global reference data (the same pattern as
`account_types`). `ai_agents`, `ai_tasks`, `ai_decisions`, `ai_memory`, and `ai_logs` are shared AI-core
infrastructure (canonically owned by the AI Core Infrastructure doc); a minimal reference definition is
included at the end of this subsection so this document is self-sufficient, not to redeclare ownership.

```sql
-- System-level catalog: the library of statutory/policy obligations QAYD knows about.
-- Not tenant-scoped — this is reference content shared across all companies in a jurisdiction.
CREATE TABLE compliance_requirement_templates (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                VARCHAR(64)  NOT NULL UNIQUE,             -- e.g. 'KW_VAT_GCC_QUARTERLY'
    jurisdiction        VARCHAR(8)   NOT NULL,                    -- ISO 3166-1 alpha-2, or '*' for cross-border (e.g. IFRS)
    domain              VARCHAR(24)  NOT NULL,
    name_en             VARCHAR(160) NOT NULL,
    name_ar             VARCHAR(160) NOT NULL,
    description         TEXT,
    authority           VARCHAR(120),                             -- e.g. 'Kuwait PIFSS', 'IASB', 'GCC VAT Framework'
    frequency           VARCHAR(16)  NOT NULL,
    default_lead_days   INTEGER      NOT NULL DEFAULT 14,
    severity_default    VARCHAR(12)  NOT NULL DEFAULT 'high',
    parameters          JSONB        NOT NULL DEFAULT '{}',        -- versioned rates/caps/thresholds
    kb_reference_ids    JSONB        NOT NULL DEFAULT '[]',        -- linked regulation-KB vector-store chunk ids
    source_url          TEXT,
    review_status       VARCHAR(16)  NOT NULL DEFAULT 'draft',     -- draft/ingested/human_reviewed/superseded
    effective_from      DATE         NOT NULL,
    effective_to        DATE         NULL,
    status              VARCHAR(16)  NOT NULL DEFAULT 'active',    -- active/superseded/retired
    superseded_by_id    BIGINT       NULL REFERENCES compliance_requirement_templates(id),
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_crt_domain CHECK (domain IN
        ('tax_vat','labor','payroll_social_security','ifrs_reporting','corporate_licensing',
         'data_retention','aml_kyc','other')),
    CONSTRAINT chk_crt_frequency CHECK (frequency IN
        ('one_time','monthly','quarterly','semi_annual','annual','event_driven')),
    CONSTRAINT chk_crt_severity CHECK (severity_default IN ('low','medium','high','critical')),
    CONSTRAINT chk_crt_review_status CHECK (review_status IN
        ('draft','ingested','human_reviewed','superseded'))
);
CREATE INDEX idx_crt_jurisdiction_domain ON compliance_requirement_templates (jurisdiction, domain)
    WHERE status = 'active';
CREATE INDEX idx_crt_review_status ON compliance_requirement_templates (review_status);

-- Tenant-scoped activation: which templates apply to THIS company (and any per-company overrides).
CREATE TABLE compliance_requirements (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT       NOT NULL REFERENCES companies(id),
    branch_id            BIGINT       NULL REFERENCES branches(id),
    template_id          BIGINT       NOT NULL REFERENCES compliance_requirement_templates(id),
    applicability_status VARCHAR(16)  NOT NULL DEFAULT 'evaluating',
    applicability_reason TEXT,
    applicability_confidence NUMERIC(5,4) NULL,
    parameter_overrides  JSONB        NOT NULL DEFAULT '{}',
    owner_role           VARCHAR(40),                              -- accountable human role, e.g. 'CFO'
    responsible_agent    VARCHAR(40)  NOT NULL DEFAULT 'compliance',
    next_due_date         DATE         NULL,
    status                VARCHAR(16)  NOT NULL DEFAULT 'active',   -- active/paused/retired
    created_by            BIGINT       NULL REFERENCES users(id),
    updated_by            BIGINT       NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ  NULL,
    CONSTRAINT uq_cr_company_template UNIQUE (company_id, template_id, branch_id),
    CONSTRAINT chk_cr_applicability CHECK (applicability_status IN
        ('evaluating','applicable','not_applicable','exempt'))
);
CREATE INDEX idx_cr_company ON compliance_requirements (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_cr_next_due ON compliance_requirements (company_id, next_due_date) WHERE status = 'active';

-- One row per due instance of a requirement (a specific period's filing).
CREATE TABLE compliance_filings (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT       NOT NULL REFERENCES companies(id),
    branch_id               BIGINT       NULL REFERENCES branches(id),
    requirement_id          BIGINT       NOT NULL REFERENCES compliance_requirements(id),
    period_label            VARCHAR(40)  NOT NULL,                 -- e.g. '2026-Q2'
    period_start             DATE         NOT NULL,
    period_end                DATE         NOT NULL,
    due_date                   DATE         NOT NULL,
    status                      VARCHAR(20)  NOT NULL DEFAULT 'not_started',
    readiness_score            NUMERIC(5,2) NULL,                    -- 0-100, AI-computed
    hold_active                BOOLEAN      NOT NULL DEFAULT false,   -- current blocking-flag state
    linked_table                VARCHAR(40)  NULL,                    -- 'tax_returns' | 'payroll_runs' | NULL
    linked_record_id            BIGINT       NULL,
    submitted_at                TIMESTAMPTZ  NULL,
    submitted_by                BIGINT       NULL REFERENCES users(id),
    evidence_attachment_id      BIGINT       NULL REFERENCES attachments(id),
    created_by                  BIGINT       NULL REFERENCES users(id),
    updated_by                  BIGINT       NULL REFERENCES users(id),
    created_at                  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at                  TIMESTAMPTZ  NULL,
    CONSTRAINT chk_cf_status CHECK (status IN
        ('not_started','in_progress','ready','submitted','accepted','rejected','late','waived')),
    CONSTRAINT chk_cf_period CHECK (period_end >= period_start),
    CONSTRAINT uq_cf_requirement_period UNIQUE (requirement_id, period_label)
);
CREATE INDEX idx_cf_due ON compliance_filings (company_id, due_date)
    WHERE status NOT IN ('submitted','accepted','waived');
CREATE INDEX idx_cf_status ON compliance_filings (company_id, status);
CREATE INDEX idx_cf_hold ON compliance_filings (company_id) WHERE hold_active = true;

-- One row per AI evaluation run (deadline/data/rate/control checks). Append-only audit trail.
CREATE TABLE compliance_assessments (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT       NOT NULL REFERENCES companies(id),
    branch_id         BIGINT       NULL REFERENCES branches(id),
    requirement_id    BIGINT       NULL REFERENCES compliance_requirements(id),
    filing_id         BIGINT       NULL REFERENCES compliance_filings(id),
    ai_task_id        BIGINT       NULL REFERENCES ai_tasks(id),
    check_type        VARCHAR(32)  NOT NULL,   -- 'deadline_check','data_check','rate_check','control_check'
    result            VARCHAR(16)  NOT NULL,   -- pass/fail/warning/inconclusive
    confidence        NUMERIC(5,4) NOT NULL,
    reasoning         TEXT         NOT NULL,
    evidence          JSONB        NOT NULL DEFAULT '[]',  -- [{table, id, field, value}, ...]
    flag_type         VARCHAR(16)  NOT NULL DEFAULT 'none', -- none/advisory/blocking
    self_consistency_agree BOOLEAN NULL,        -- result of the dual LLM+rule-engine cross-check
    superseded_by_id   BIGINT       NULL REFERENCES compliance_assessments(id),
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_ca_result CHECK (result IN ('pass','fail','warning','inconclusive')),
    CONSTRAINT chk_ca_flag CHECK (flag_type IN ('none','advisory','blocking')),
    CONSTRAINT chk_ca_confidence CHECK (confidence BETWEEN 0 AND 1)
);
CREATE INDEX idx_ca_requirement ON compliance_assessments (requirement_id, created_at DESC);
CREATE INDEX idx_ca_filing ON compliance_assessments (filing_id, created_at DESC);
CREATE INDEX idx_ca_company_result ON compliance_assessments (company_id, result);

-- Human-facing surface: one row per thing a human might need to look at or act on.
CREATE TABLE compliance_alerts (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT       NOT NULL REFERENCES companies(id),
    branch_id           BIGINT       NULL REFERENCES branches(id),
    source              VARCHAR(20)  NOT NULL,  -- 'regulation_feed','assessment','manual','scheduler'
    requirement_id      BIGINT       NULL REFERENCES compliance_requirements(id),
    filing_id           BIGINT       NULL REFERENCES compliance_filings(id),
    assessment_id       BIGINT       NULL REFERENCES compliance_assessments(id),
    ai_decision_id      BIGINT       NULL REFERENCES ai_decisions(id),
    severity            VARCHAR(12)  NOT NULL,
    category             VARCHAR(24)  NOT NULL,  -- deadline/regulation_change/data_gap/rate_mismatch/attestation_due/retention
    title                 VARCHAR(200) NOT NULL,
    summary               TEXT         NOT NULL,
    status                VARCHAR(16)  NOT NULL DEFAULT 'open',
    escalation_level      SMALLINT     NOT NULL DEFAULT 0,   -- 0=agent,1=role owner,2=CFO,3=Owner
    due_date              DATE         NULL,
    acknowledged_by       BIGINT       NULL REFERENCES users(id),
    acknowledged_at       TIMESTAMPTZ  NULL,
    resolved_at           TIMESTAMPTZ  NULL,
    created_at            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_cal_severity CHECK (severity IN ('low','medium','high','critical')),
    CONSTRAINT chk_cal_status CHECK (status IN ('open','acknowledged','escalated','resolved','dismissed'))
);
CREATE INDEX idx_calerts_open ON compliance_alerts (company_id, status) WHERE status IN ('open','escalated');
CREATE INDEX idx_calerts_due ON compliance_alerts (company_id, due_date);

-- Periodic human sign-off that a named internal control is operating effectively.
CREATE TABLE control_attestations (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT       NOT NULL REFERENCES companies(id),
    branch_id              BIGINT       NULL REFERENCES branches(id),
    requirement_id         BIGINT       NULL REFERENCES compliance_requirements(id),
    control_code           VARCHAR(64)  NOT NULL,   -- e.g. 'BANK_RECON_MONTHLY', 'IFRS18_READINESS_FY27'
    control_name           VARCHAR(160) NOT NULL,
    period_label           VARCHAR(40)  NOT NULL,
    ai_draft_summary       TEXT,
    ai_confidence          NUMERIC(5,4) NULL,
    attested_by            BIGINT       NULL REFERENCES users(id),
    attested_role          VARCHAR(40)  NULL,
    attestation_statement  TEXT         NULL,
    status                 VARCHAR(16)  NOT NULL DEFAULT 'draft',
    exceptions_noted        TEXT         NULL,
    signed_at                TIMESTAMPTZ  NULL,
    created_at                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_catt_status CHECK (status IN
        ('draft','pending_signature','signed','exception_noted')),
    CONSTRAINT uq_catt_control_period UNIQUE (company_id, control_code, period_label)
);
CREATE INDEX idx_catt_pending ON control_attestations (company_id, status) WHERE status = 'pending_signature';

-- Retention rules: how long a record type must be kept, and what happens at expiry.
-- action_on_expiry never includes hard delete — financial records are never hard-deleted on this platform.
CREATE TABLE retention_policies (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT       NULL REFERENCES companies(id),  -- NULL = system default for the jurisdiction
    record_type        VARCHAR(64)  NOT NULL,       -- e.g. 'journal_entries','payslips','tax_returns'
    jurisdiction       VARCHAR(8)   NOT NULL DEFAULT 'KW',
    retention_years    NUMERIC(4,1) NOT NULL,
    legal_basis        TEXT,
    action_on_expiry   VARCHAR(20)  NOT NULL DEFAULT 'archive',   -- archive/cold_storage/review
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_rp_expiry_action CHECK (action_on_expiry IN ('archive','cold_storage','review'))
);
CREATE UNIQUE INDEX uq_rp_scope ON retention_policies
    (COALESCE(company_id, -1), record_type, jurisdiction);

-- Active legal/litigation holds that override normal retention/purge eligibility.
CREATE TABLE retention_holds (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT       NOT NULL REFERENCES companies(id),
    record_type   VARCHAR(64)  NOT NULL,
    record_id     BIGINT       NOT NULL,
    reason        TEXT         NOT NULL,
    placed_by     BIGINT       NOT NULL REFERENCES users(id),
    released_by   BIGINT       NULL REFERENCES users(id),
    placed_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    released_at   TIMESTAMPTZ  NULL
);
CREATE UNIQUE INDEX uq_rh_active ON retention_holds (company_id, record_type, record_id)
    WHERE released_at IS NULL;
```

**Shared AI-core tables (reference only — canonical DDL owned by the AI Core Infrastructure doc),** shown
here at the columns this agent actually touches, so this document stands on its own:

```sql
-- ai_agents: registry row, one per agent, e.g. code='compliance'.
-- ai_tasks: one row per invocation (scheduled scan, event reaction, human query).
-- ai_decisions: proposals awaiting human approval (the gate described under Guardrails & Human Approval).
-- ai_memory: per-company, per-agent long-term memory (see Collaboration/Reasoning sections).
-- ai_logs: prompt/response/tool-call trace for every model and tool invocation, for cost & quality metrics.
CREATE TABLE ai_decisions (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id       BIGINT       NOT NULL REFERENCES companies(id),
    agent_code       VARCHAR(40)  NOT NULL REFERENCES ai_agents(code),
    decision_type    VARCHAR(64)  NOT NULL,
    target_table     VARCHAR(64)  NULL,
    target_id        BIGINT       NULL,
    proposed_change  JSONB        NOT NULL DEFAULT '{}',
    confidence       NUMERIC(5,4) NOT NULL,
    reasoning        TEXT         NOT NULL,
    requires_role    JSONB        NOT NULL DEFAULT '[]',   -- array of role names, any-of
    escalation_path  JSONB        NOT NULL DEFAULT '[]',   -- ordered array of role names
    sla_hours        INTEGER      NOT NULL DEFAULT 24,
    status           VARCHAR(20)  NOT NULL DEFAULT 'pending_approval',
    decided_by       BIGINT       NULL REFERENCES users(id),
    decided_at       TIMESTAMPTZ  NULL,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_aid_status CHECK (status IN
        ('pending_approval','applying','applied','rejected','escalated','expired','failed'))
);
CREATE INDEX idx_aid_company_status ON ai_decisions (company_id, status);
CREATE INDEX idx_aid_agent ON ai_decisions (agent_code, status);
```

# Tools & API Access

The agent calls a fixed set of MCP tool functions. Each tool wraps exactly one Laravel `/api/v1` endpoint
(read) or one `ai_decisions` proposal write (for anything with a side effect). No tool grants the agent a
capability a human in an equivalent role would not also need to be permitted.

**Read tools (mapped 1:1 to existing module endpoints):**

| Tool name | Method & Path | Permission Key | Purpose |
|---|---|---|---|
| `get_company_profile` | `GET /api/v1/companies/{id}` | `compliance.read` | Entity type, registration numbers, fiscal year, headcount, branches |
| `get_journal_entries` | `GET /api/v1/accounting/journal-entries` | `accounting.read` | Posting-pattern checks (e.g., manual entry frequency for SoD control) |
| `get_fiscal_periods` | `GET /api/v1/accounting/fiscal-periods` | `accounting.read` | Period status ahead of close-gate checks |
| `get_tax_returns` | `GET /api/v1/tax/tax-returns` | `tax.read` | Filing readiness/status for VAT and other tax obligations |
| `get_tax_transactions` | `GET /api/v1/tax/tax-transactions` | `tax.read` | Underlying VAT transaction completeness |
| `get_payroll_runs` | `GET /api/v1/payroll/payroll-runs` | `payroll.read` | WPS/PIFSS pre-release and post-hoc checks |
| `get_payslips` | `GET /api/v1/payroll/payslips` | `payroll.read` | Field-level rate/cap verification (PII-redacted, see Data Access) |
| `get_bank_reconciliations` | `GET /api/v1/banking/bank-reconciliations` | `bank.read` | Control-attestation evidence (bank recon control) |
| `get_invoices` / `get_bills` | `GET /api/v1/sales/invoices`, `GET /api/v1/purchasing/bills` | `sales.read`, `purchasing.read` | VAT-reconciliation completeness checks |
| `get_audit_logs` | `GET /api/v1/audit/audit-logs` | `audit.read` | Segregation-of-duties and change-history evidence |
| `search_regulation_kb` | internal vector search (no Laravel endpoint; FastAPI-local) | n/a (AI-internal) | Retrieve regulation passages relevant to a requirement/domain |
| `get_requirement_catalog` | `GET /api/v1/compliance/requirements` | `compliance.read` | Which requirements are active/applicable for the company |
| `get_filing_calendar` | `GET /api/v1/compliance/filings?due_before=` | `compliance.filings.read` | Upcoming due dates and current status |
| `get_open_alerts` | `GET /api/v1/compliance/alerts?status=open` | `compliance.alerts.read` | Current unresolved risk surface |
| `get_retention_policy` | `GET /api/v1/compliance/retention-policies` | `compliance.retention.read` | Applicable retention years per record type |

**Write tools (always routed through `ai_decisions`, never a direct table write):**

| Tool name | Resulting Laravel endpoint (on approval) | Permission Key | Purpose |
|---|---|---|---|
| `create_assessment` | n/a — AI-owned table, written directly by the FastAPI service via its own scoped repository | n/a | Persist an evaluation result (not a financial record; see Data Access) |
| `raise_alert` | n/a — AI-owned table, same as above | n/a | Persist an alert derived from an assessment or regulation-feed item |
| `propose_requirement_activation` | `POST /api/v1/compliance/requirements/{id}/activate` | `compliance.requirements.manage` | Propose a new/changed requirement applies to this company |
| `propose_blocking_flag` | `POST /api/v1/compliance/filings/{id}/hold` or `POST /api/v1/accounting/fiscal-periods/{id}/hold` | `compliance.block.apply` (agent-held system permission) / cleared by `compliance.block.override` | Place or request a hold on a target action |
| `draft_attestation` | `POST /api/v1/compliance/attestations` (creates in `draft` status) | `compliance.attestations.create` | Prepare an evidence summary for a human to review and sign |
| `propose_retention_tag` | `POST /api/v1/compliance/retention/tag` | `compliance.retention.manage` | Attach a retention class + purge-eligibility date to a record |
| `escalate_alert` | `POST /api/v1/compliance/alerts/{id}/escalate` | `compliance.alerts.escalate` | Move an unresolved alert to the next accountability tier |
| `notify_agent` | internal agent bus (LangGraph inter-agent message), not a REST call | n/a | Hand context to Tax Advisor / Payroll Manager / Auditor / CFO's Approval Assistant queue |

**Tool-call contract example** (MCP function-calling schema exposed to the LLM):

```json
{
  "name": "get_filing_calendar",
  "description": "Return this company's compliance filings due within a date window, with current status.",
  "parameters": {
    "type": "object",
    "properties": {
      "company_id": { "type": "integer" },
      "from_date": { "type": "string", "format": "date" },
      "to_date": { "type": "string", "format": "date" },
      "status_in": {
        "type": "array",
        "items": { "type": "string", "enum": ["not_started","in_progress","ready","submitted","accepted","rejected","late","waived"] }
      }
    },
    "required": ["company_id", "from_date", "to_date"]
  }
}
```

```json
{
  "name": "propose_blocking_flag",
  "description": "Request a compliance hold on a target action (fiscal period close, payroll release, filing submission). Requires human clearance to lift.",
  "parameters": {
    "type": "object",
    "properties": {
      "company_id": { "type": "integer" },
      "target_table": { "type": "string", "enum": ["fiscal_periods","payroll_runs","compliance_filings"] },
      "target_id": { "type": "integer" },
      "severity": { "type": "string", "enum": ["low","medium","high","critical"] },
      "reasoning": { "type": "string" },
      "assessment_id": { "type": "integer" }
    },
    "required": ["company_id","target_table","target_id","severity","reasoning","assessment_id"]
  }
}
```

# Data Access & Tenant Scope

The agent authenticates to the Laravel API as a dedicated system principal (`ai_agents` row for
`code = 'compliance'`) carrying a scoped Sanctum token bound to a single `company_id` per invocation via the
`X-Company-Id` header — identical isolation to a human user, with no cross-tenant code path. There is no
"global" credential; a run for company 4821 physically cannot see company 4822's rows, because every query
the agent issues passes through the same `company_id`-scoped Eloquent global scope every controller uses.

**Read scope** (via the tools above): `accounts`, `journal_entries`, `journal_lines`, `fiscal_years`,
`fiscal_periods`, `invoices`, `bills`, `bank_accounts`, `bank_reconciliations`, `payroll_runs`,
`payroll_items`, `payslips` (field-redacted), `tax_transactions`, `tax_returns`, `customers`, `vendors`
(name/registration fields only — no banking/contact PII unless a specific check requires it), `audit_logs`,
`companies`/`branches` configuration, `attachments` metadata (content fetched only when a check specifically
requires OCR'd text, via signed URL, never bulk-downloaded).

**Write scope:** `compliance_assessments`, `compliance_alerts` (AI-owned, written directly by the FastAPI
service's own repository layer — these are audit/observability rows, not financial records, so they are
exempt from the "AI never writes directly" rule the same way `ai_logs` is); `ai_memory` (its own per-company
namespace only); `ai_decisions` (proposals). It has **zero** write scope on `journal_entries`, `invoices`,
`bills`, `payroll_runs`, `tax_returns`, or any other financial source-of-truth table — those are only ever
touched by a human action or another agent's proposal going through Laravel validation.

**Field-level PII redaction.** Before any payroll or employee record enters an LLM prompt, a redaction
middleware strips national ID numbers, full IBANs (retains last 4 digits), home addresses, and phone
numbers unless the specific `check_type` requires that exact field (e.g., an IBAN-format validation check
receives the IBAN, but the surrounding prompt never asks the model to summarize or repeat it back
unnecessarily, and the field is never persisted into `ai_memory`). Aggregate facts only
(e.g., "this company runs monthly payroll for 42 employees across 2 branches") are eligible for
memory persistence; individual identities are not.

**Tenant isolation guarantees enforced at three layers:** (1) the Sanctum token's company claim, (2) the
Eloquent global scope on every model the agent's service account can touch, (3) a runtime assertion in the
FastAPI orchestration layer that rejects any tool result whose `company_id` does not match the task's
`company_id` — a defense-in-depth check specifically because agent orchestration code is newer and less
battle-tested than the Laravel authorization stack it sits behind.

# Reasoning & Prompt Strategy

**Orchestration graph.** The agent runs as a LangGraph state machine with the following nodes, invoked
per-task (a scheduled scan, an event trigger, or a human question resolve to the same graph):

```
┌───────────┐   ┌───────────┐   ┌───────────────┐   ┌─────────────────┐
│ Ingest     │──▶│ Classify & │──▶│ Retrieve       │──▶│ Evaluate         │
│ (trigger,  │   │ Prioritize │   │ Requirements + │   │ (rule engine +   │
│  event, or │   │ (domain,   │   │ Company Data   │   │  LLM synthesis   │
│  question) │   │  severity) │   │ (read-only)    │   │  over retrieval) │
└───────────┘   └───────────┘   └───────┬───────┘   └────────┬────────┘
                                                                │
                                                                ▼
┌────────────┐   ┌────────────┐   ┌────────────────┐   ┌─────────────────┐
│ Notify /    │◀──│ Gate        │◀──│ Score           │◀──│ Draft Output     │
│ Escalate    │   │ (advisory   │   │ Confidence      │   │ (result +        │
│ (ai_decision│   │  vs         │   │ (data           │   │  reasoning +     │
│  / alert)   │   │  blocking)  │   │  completeness × │   │  sources)        │
└─────┬──────┘   └────────────┘   │  KB certainty ×  │   └─────────────────┘
      │                            │  historical      │
      ▼                            │  accuracy)        │
┌────────────┐                    └────────────────┘
│ Human       │  yes   ┌────────────────┐
│ Approves /  │───────▶│ Apply via       │
│ Rejects     │        │ Laravel API     │
└─────┬──────┘        │ (validated,     │
      │ no / timeout   │  RBAC-checked)  │
      ▼                └────────────────┘
┌────────────┐
│ Escalate up │
│ chain /     │
│ expire      │
└────────────┘
```

**Retrieval strategy — hybrid RAG.** Two retrieval paths feed the Evaluate node:

1. **Structured retrieval** (SQL, via the read tools above): the company's actual data — filing status,
   payroll figures, VAT reconciliation state, control history.
2. **Unstructured retrieval** (vector search over the regulation knowledge base): chunked, embedded
   passages from the GCC VAT Framework Agreement, Kuwait Labor Law (private-sector employment provisions),
   the PIFSS social-security contribution scheme, Kuwait commercial record-keeping obligations, and IFRS
   standards text — each chunk tagged with `jurisdiction`, `domain`, `effective_from`/`effective_to`, and a
   `status` of `draft | ingested | human_reviewed | superseded`. Only `human_reviewed` chunks can support a
   `blocking` flag; `ingested`-but-not-yet-reviewed chunks can only support `advisory` output, per the
   confidence cap described under Outputs.

**Statutory parameters are data, not code.** Rates, caps, and thresholds (PIFSS employee/employer
contribution percentages and salary ceiling, VAT registration threshold, retention-year counts) are never
hard-coded into a prompt or into application logic. They live in
`compliance_requirement_templates.parameters` (JSONB) with `effective_from`/`effective_to` versioning, so a
change in law is a data update plus a regulation-change alert, not a code deployment — this is precisely
why "regulation-change alerts" is a first-class capability rather than an afterthought. *(Any specific rate
or year-count appearing in this document's worked examples is illustrative configuration data for the
purpose of specifying the system, not a legal citation — the actual values loaded into
`compliance_requirement_templates` for a live tenant must be sourced and kept current from the Ministry of
Finance, PIFSS, and IASB primary publications by the humans who own the regulation-content pipeline.)*

**Prompt template skeleton** (system + retrieved context + task; illustrative, abbreviated):

```
SYSTEM:
You are QAYD's Compliance Agent for company_id={company_id}. You evaluate statutory and policy
obligations against this company's data. You NEVER invent a rate, deadline, or legal conclusion not
present in the retrieved context. If retrieved context is insufficient, return result="inconclusive"
and confidence<=0.4, and state exactly what data or KB entry is missing.
You NEVER produce flag_type="blocking" from a KB chunk whose status != "human_reviewed".
Always cite every fact you use in `sources` with {table, id, field, value} or {kb_chunk_id}.

CONTEXT (retrieved):
- Requirement: {requirement_json}
- KB passages: {ranked_kb_chunks}
- Company data snapshot: {structured_query_results}
- Prior assessments for this requirement (last 3): {history}

TASK:
{check_type} for {requirement_code}, period {period_label}, as_of {as_of_date}.
Return the Assessment JSON contract exactly as specified.
```

**Model routing.** Classification and data-completeness checks (deterministic, high-volume) run on a
smaller/cheaper model tier; applicability judgment, regulation-change summarization, and attestation
drafting (higher-stakes, lower-volume, benefit from stronger reasoning) route to the frontier tier. Every
model call is logged to `ai_logs` with prompt/response token counts and latency for cost and quality
monitoring (see Metrics & Evaluation).

**Self-consistency check.** For any evaluation that would produce a `blocking` flag, the graph runs the
Evaluate node twice — once retrieval-grounded, once as a structured rule-engine pass over the same
retrieved facts with no LLM involved — and only emits `blocking` if both agree. A disagreement downgrades
the output to `advisory` with `result="warning"` and a note that automated cross-check disagreed, forcing
human eyes on anything the deterministic rules and the LLM read differently.

# Collaboration With Other Agents

The Compliance Agent is a **read-across watcher**, not a doer — its value comes entirely from how well it
plugs into the agents that do the actual financial work. It has no source-of-truth data of its own to
protect; it is a lens over everyone else's.

| Collaborator | Relationship | Shared data / events |
|---|---|---|
| **Tax Advisor** | Compliance owns the statutory *calendar and completeness bar*; Tax Advisor owns the *liability computation and draft return*. Compliance checks Tax Advisor's `tax_returns` draft against the deadline and against reconciliation completeness (`tax_transactions`, `bills.vat_reconciled`), and escalates to Tax Advisor first, CFO second, when a filing is at risk. | `tax_return.draft_updated` (consumed), `compliance_filings` readiness score (produced, read by Tax Advisor's own dashboard) |
| **Payroll Manager** | Compliance runs the PIFSS-rate/salary-cap and WPS-format pre-release check on every `payroll_run.calculated` event, *before* Payroll Manager is allowed to move a run to `release_requested`. A failing check sets a blocking flag on the run; Payroll Manager's release action checks for open compliance holds before proceeding. | `payroll_run.calculated` (consumed), `payroll_run.release_requested` (consumed, synchronous gate), `compliance_alerts` on payroll (produced) |
| **Auditor** | Auditor performs sample-based substantive and control testing; Compliance supplies the *evidence trail* — the requirement catalog, filing history, assessment history, and attestation records — as a ready-made audit package, and Auditor can request an ad-hoc `control_attestation` draft from Compliance rather than assembling evidence manually. | `compliance_assessments`, `control_attestations`, `compliance_alerts` history (read by Auditor), `audit.evidence_requested` (consumed) |
| **CFO** | CFO is the primary human approver for blocking-flag overrides (via `compliance.block.override`) and the final signer on most `control_attestations`. Compliance's weekly digest (open alerts by severity, upcoming filings, attestations pending signature) feeds the CFO's risk review directly. | `ai_decisions` requiring `requires_role: ["CFO"]` (produced), `compliance.digest.weekly` (produced) |
| **Approval Assistant** | Generic queue/notification manager for every agent's `ai_decisions`. Compliance does not build its own approval UI or notification logic — it emits standard `ai_decisions` rows and lets Approval Assistant route them to the right inbox with the right urgency, consistent with every other agent in the roster. | `ai_decisions` (produced, consumed by Approval Assistant for routing) |
| **Fraud Detection** | Bidirectional signal-sharing: a compliance breach pattern (e.g., invoices structured just under a VAT-registration threshold) is also a fraud signal, and a fraud-flagged transaction pattern may itself constitute a compliance gap (e.g., unusual related-party transactions requiring IFRS disclosure). Neither agent defers to the other; both raise independently and Approval Assistant de-duplicates overlapping alerts by shared `assessment_id`/transaction reference. | `compliance_alerts` ↔ Fraud Detection's own alert stream (cross-referenced, not merged) |
| **Reporting Agent** | Consumes Compliance's IFRS-applicability assessments (e.g., a new standard requiring a presentation change) to keep `report_definitions` current, and includes a compliance-status section in board/management reporting packs. | `compliance_alerts` where `category = 'regulation_change'` and `domain = 'ifrs_reporting'` (consumed) |
| **Document AI / OCR Agent** | Government notices and stamped filing acknowledgements arrive as scanned attachments; OCR Agent extracts text and Document AI classifies the document type, then routes anything tagged `government_notice` or `filing_receipt` to Compliance for parsing into a `compliance_alerts` (new regulation) or `compliance_filings.evidence_attachment_id` (submission proof) update. | `attachment.uploaded` (consumed, filtered by classification tag) |
| **General Accountant** | Receives advisory alerts touching day-to-day bookkeeping hygiene (e.g., "12 invoices this month are missing the counterparty's tax registration number, required for VAT input recovery") as low-severity, high-volume nudges rather than escalations. | `compliance_alerts` where `severity IN ('low','medium')` (consumed) |

**Inter-agent messages** travel over the internal LangGraph agent bus (not a public REST call) as a typed
envelope:

```json
{
  "from_agent": "compliance",
  "to_agent": "tax_advisor",
  "message_type": "escalation.filing_at_risk",
  "company_id": 4821,
  "payload": {
    "filing_id": 58210,
    "requirement_code": "KW_VAT_GCC_QUARTERLY",
    "due_date": "2026-07-28",
    "blocking_items": ["bills#4410", "bills#4477", "bills#4502"],
    "assessment_id": 90210
  },
  "correlation_id": "b6e2f8f0-...-compliance-run-90210"
}
```

# Guardrails & Human Approval

**The gate, precisely.** Any Compliance output that would change the state of a financial record, release
a hold, sign an attestation, submit a filing, or purge/archive a retained record is **never executed by the
agent**. It is written as a row in `ai_decisions` and nothing downstream acts on it until a human with the
required role calls the approve endpoint, which itself re-runs the full Laravel FormRequest validation and
`Gate::authorize()` check that any manual action would go through — the agent gets no shortcut through
authorization just because a human clicked "approve."

`ai_decisions` state machine:

```
        propose (agent)
             │
             ▼
        pending_approval ──── approve (human, role-checked) ───▶ applying ──▶ applied
             │                                                                    │
             │ reject (human)                                                    │ (Laravel API call fails
             ▼                                                                   │  validation/authorization)
          rejected                                                               ▼
             │                                                                failed (agent notified,
             │ sla_hours elapsed, no action                                    re-evaluates, may re-propose)
             ▼
        escalated (next role in escalation_path) ──── repeats until Owner ──── expired (logged, alert stays open)
```

**Escalation path is explicit per decision type**, not a single global chain:

| Decision type | Requires role (any of) | Escalation path (in order) | Default SLA |
|---|---|---|---|
| `compliance.block.period_close` | Finance Manager, CFO | Finance Manager → CFO → Owner | 24h |
| `compliance.block.payroll_release` | Payroll Officer's manager (HR Manager), CFO | HR Manager → CFO → Owner | 12h (payroll cycles are time-critical) |
| `compliance.requirement.activate` | Finance Manager, CFO | Finance Manager → CFO | 72h |
| `compliance.attestation.sign` | The named control owner (role stored on `control_attestations.attested_role`) | Control owner → CFO → Auditor (informational cc) | Per attestation cadence (e.g., monthly control: 5 business days into the following month) |
| `compliance.retention.archive` | Owner, Finance Manager | Finance Manager → Owner | 7 days |
| `compliance.block.override` | Owner, CFO, Auditor only | *(no further escalation — this is already the top of the chain)* | n/a — resolved same-session or stays open |

**Hard guardrails (structural, not policy-only):**
- The agent's service-account permission set includes `compliance.block.apply` but explicitly **excludes**
  `compliance.block.override` — it is architecturally incapable of clearing its own blocking flag, even if
  a prompt tried to convince it to. Override requires a distinct human-held permission.
- No `ai_decisions` row can be auto-approved. There is no configuration flag, environment variable, or
  "trusted mode" that skips the human gate for this agent — sensitive-operation approval is enforced in the
  Laravel policy layer, not in agent behavior, so a compromised or misconfigured agent cannot escalate its
  own privileges.
- Blocking flags fail safe: if the Compliance service is down, unreachable, or its last run errored, any
  target action gated on "no open Compliance hold" defaults to treating an **unknown** compliance status as
  a soft warning shown to the human approver, not a silent pass — visible degraded-mode banner, never
  invisible bypass.
- Every attestation signature is bound to a specific named `users.id` and role at time of signing (not just
  "an admin") — attestations are personally accountable statements, matching real internal-control practice.
- All guardrail-relevant actions (block applied, block overridden, attestation signed, retention archived)
  write to `audit_logs` with before/after state, reason, actor, IP, and device, independent of the
  `ai_decisions` record itself — belt-and-suspenders audit trail.

# Worked Scenarios

### Scenario 1 — GCC VAT quarterly filing at risk (blocking flag on period close)

**Day T-8 (2026-07-16, 03:00 AST).** Scheduled daily scan runs for company 4821. `get_filing_calendar`
returns `compliance_filings#58210` (requirement `KW_VAT_GCC_QUARTERLY`, period `2026-Q2`, due `2026-07-28`,
status `in_progress`). The Evaluate node pulls `tax_returns#7734` (status `draft`) and finds 3 unreconciled
bills. Confidence 0.93 (complete data, human-reviewed KB entry, this exact check type has 98% historical
accuracy). Because both the LLM evaluation and the deterministic rule-engine pass agree the filing cannot
reach "ready" before the internal T-2 checkpoint, the agent emits `flag_type = "blocking"` — targeting not
the tax return itself (Tax Advisor owns that) but a preemptive hold candidate on `fiscal_periods#331`
(the Q2 close), because closing the accounting period before the VAT return reconciles would make later
correction materially harder. `ai_decisions#55019` is created (`requires_role: ["Finance Manager","CFO"]`,
`sla_hours: 24`), and `compliance_alerts#66142` (severity `high`) appears on the CFO and Finance Manager
dashboards. An inter-agent message notifies Tax Advisor of the 3 blocking bills.

**Day T-7.** Tax Advisor's own workflow (informed by the escalation message) reconciles 2 of the 3 bills.
Compliance's next scheduled run re-evaluates: confidence stays 0.93, but `result` moves from `fail` to
`warning` (1 bill outstanding), `flag_type` downgrades from `blocking` to `advisory` automatically — the
period-close hold is provisionally lifted pending the last bill, and `ai_decisions#55019` moves to
`superseded` with a new `ai_decisions#55031` (advisory-only, no approval required) taking its place.

**Day T-6.** Last bill reconciles. Assessment `result = pass`, confidence 0.97. Alert `#66142` auto-resolves
(`status = resolved`, `resolved_at` set). Filing readiness score reaches 100. No further human action needed
— the entire episode from detection to resolution required exactly one human decision point (implicitly,
Tax Advisor acting on the escalation), and the period close proceeds without ever having been actually
blocked, because the risk was caught 8 days out rather than discovered at close time.

### Scenario 2 — Kuwait payroll: PIFSS rate/cap and WPS pre-release check (blocking on payroll release)

**Trigger.** Payroll Manager fires `payroll_run.calculated` for `payroll_runs#2290` (July 2026, 42
employees, 2 branches). Compliance's pre-release check retrieves `compliance_requirement_templates` code
`KW_PIFSS_MONTHLY_CONTRIBUTION`, whose `parameters` JSONB holds the currently configured employee rate,
employer rate, and monthly salary ceiling *(illustrative configuration values for this worked example —
loaded and kept current by the humans who own the regulation-content pipeline, not asserted here as legal
fact)*:

```json
{ "employee_rate": 0.08, "employer_rate": 0.115, "salary_cap_kwd": 2750.0000, "effective_from": "2026-01-01" }
```

The Evaluate node recomputes expected employee/employer contributions per `payroll_items` line, applying
the cap, and compares to what Payroll Manager actually calculated. For 39 of 42 employees, figures match
exactly. For 3 employees at a branch that was migrated from a legacy spreadsheet import, the employer
contribution was computed on gross salary **without** applying the salary cap — an overpayment of
KWD 14.6250/employee/month. `result = fail`, `confidence = 0.98` (deterministic arithmetic check, no
ambiguity), `flag_type = blocking` on `payroll_runs#2290.release_requested`. `ai_decisions#55240` is created
requiring HR Manager or CFO approval, SLA 12 hours (payroll is time-critical — WPS bank files typically need
to go out on a fixed monthly schedule). Reasoning cites the 3 specific `payroll_items` rows and the cap
parameter. HR Manager corrects the 3 lines in Payroll Manager's own workflow; Compliance re-runs
automatically on `payroll_run.recalculated`, confirms `pass`, hold clears, `ai_decisions#55240` closes as
`applied` (recorded as resolved-by-correction rather than override — no one had to invoke
`compliance.block.override`, because the underlying error was fixed rather than waived).

### Scenario 3 — IFRS 18 issuance: regulation-change alert and attestation readiness

**Signal ingestion.** The regulation-feed connector ingests IASB's issuance of IFRS 18 *Presentation and
Disclosure in Financial Statements* (issued April 2024, effective for annual reporting periods beginning on
or after 1 January 2027, replacing IAS 1, early application permitted). The Classify node tags it
`domain = ifrs_reporting`, `jurisdiction = *` (IFRS applies across all IFRS-reporting tenants regardless of
GCC jurisdiction), and creates a `compliance_requirement_templates` row in `status = 'draft'` pending human
legal/technical-accounting review — per the confidence-cap rule, nothing derived from this entry can be
`blocking` until that review completes.

**Applicability assessment (suggest-only).** For company 4821 (calendar-year fiscal year, currently prepares
a single-statement income statement without IFRS 18's required operating/investing/financing categorization
and the new-to-IFRS "management-defined performance measures" note), the agent drafts an applicability
finding: this company **will** need a presentation change for FY2027, and — since QAYD is now mid-2026 —
has roughly 18 months of lead time. `ai_decisions#55501` (`decision_type: compliance.requirement.activate`,
`requires_role: ["Finance Manager","CFO"]`, SLA 72h, low urgency) proposes activating the requirement for
this tenant with a target readiness date of Q3 2026 (giving Reporting Agent time to update
`report_definitions` well ahead of the effective date). The agent also drafts (not signs) a
`control_attestations` row: `control_code = 'IFRS18_READINESS_FY27'`, `status = 'draft'`,
`ai_draft_summary` describing the gap and the recommended remediation plan, for the CFO to review, edit, and
eventually sign as the FY2026 year-end control-readiness attestation. CFO approves activation; Reporting
Agent picks up the event and schedules the `report_definitions` update; the attestation stays open
(`status = pending_signature`) until the CFO's year-end control cycle, tracked as a routine (non-urgent)
item on the compliance dashboard rather than an alert, because there is no imminent deadline — a
deliberately calmer treatment than the blocking scenarios above, matching the actual urgency.

# Metrics & Evaluation

The agent is graded like any other member of the finance workforce: on whether its calls were right, on
time, and trusted enough that humans stop double-checking routine ones.

**Primary quality metrics:**

| Metric | Definition | Target | Data source |
|---|---|---|---|
| Filing on-time rate | % of `compliance_filings` reaching `submitted`/`accepted` before `due_date` | ≥ 99.5% across the tenant base | `compliance_filings` |
| Mean lead time | Average days between an alert's `created_at` and the requirement's `due_date`, for alerts that led to on-time resolution | ≥ 7 days for `high`/`critical` severity | `compliance_alerts` |
| Detection precision | Of assessments with `result IN ('fail','warning')`, % later confirmed correct by human action or override | ≥ 95% | `compliance_assessments` vs. `ai_decisions.status` outcome |
| Detection recall (regulation changes) | % of a curated legal-review gold set of known regulation changes that the agent ingested and correctly classified within 48h | ≥ 98% | Manual gold-set audit, quarterly |
| False-positive blocking rate | % of `blocking` flags cleared via `compliance.block.override` (i.e., a human judged the block wrong, not just resolved it by fixing data) | ≤ 2% | `ai_decisions` where `decision_type` starts with `compliance.block.` and outcome is override without underlying data change |
| Confidence calibration | Brier score comparing stated `confidence` to human-confirmed correctness, bucketed by `check_type` | Brier ≤ 0.08 | `compliance_assessments` joined to resolution outcome |
| Attestation cycle time | Median days from `control_attestations.status='draft'` to `signed` | ≤ 5 business days | `control_attestations` |
| Escalation SLA adherence | % of `ai_decisions` resolved (approved/rejected) before `sla_hours` elapses, at the first (non-escalated) tier | ≥ 90% | `ai_decisions` |
| Agent task success rate | % of `ai_tasks` for `agent_code='compliance'` completing without error | ≥ 99.9% | `ai_tasks`, `ai_logs` |
| Self-consistency agreement rate | % of assessments where the LLM pass and rule-engine pass agreed (a low rate signals prompt or rule-engine drift needing review) | ≥ 97% | `compliance_assessments.self_consistency_agree` |
| Cost per assessment | Mean LLM token cost per `compliance_assessments` row, by `check_type` | Tracked, not capped — reviewed monthly for model-routing tuning | `ai_logs` |
| Human override rate (net) | % of agent outputs a human overturned outright (not merely acted on) | ≤ 3%, reviewed per `check_type` — persistent high overturn on one check type triggers a prompt/rule review, not just a metric | `ai_decisions`, `compliance_assessments` |

**Evaluation cadence.** Automated metrics recompute nightly into a compliance-agent scorecard visible to the
platform team (not per-tenant — this is model/prompt quality, not a customer-facing number). A curated gold
set (real regulation changes, real rate updates, hand-labeled applicability judgments) is refreshed quarterly
by the humans who own the regulation-content pipeline and run as an offline eval before any prompt, model,
or rule-engine change ships — this agent does not get a production behavior change without passing the gold
set at or above its current baseline on precision, recall, and calibration simultaneously (a change that
improves recall but degrades calibration does not ship as-is).

**Per-tenant health indicator.** Each company sees a simple three-state compliance-health signal (green /
amber / red) on its dashboard, computed from open alert severity mix and filing readiness — this is a
derived, human-readable rollup of the metrics above, not a separate judgment call by the agent.

# Failure Modes & Edge Cases

| # | Failure mode / edge case | Handling |
|---|---|---|
| 1 | Regulation knowledge base has not yet been updated with a just-changed law | Any assessment resting on a `draft`/`ingested` (not `human_reviewed`) KB chunk is capped at `confidence ≤ 0.60` and forced `flag_type = advisory`. The gap itself becomes an alert: "requirement X may need review — recent regulation change detected, human-reviewed KB entry pending." |
| 2 | Multi-branch / multi-jurisdiction company (e.g., a Kuwait HQ with a Saudi branch) | `compliance_requirements` is scoped by `branch_id`; requirement applicability is evaluated per branch against that branch's jurisdiction, not inherited blindly from the parent company. Conflicting obligations (e.g., differing VAT registration thresholds) surface as two independent requirement rows, never silently merged. |
| 3 | Missing company configuration (e.g., no VAT registration number set, entity type not set) | The agent never treats "unknown" as "compliant." A missing required field produces `result = inconclusive`, `flag_type = advisory`, category `data_gap`, with a specific, actionable ask ("set companies.vat_registration_number to run this check") — never a silent pass and never a false `fail`. |
| 4 | Alert storm from a noisy upstream regulation feed (e.g., a feed re-publishes the same notice three times) | De-duplication key = `(jurisdiction, domain, source_document_hash)`; a repeat within a 30-day window updates the existing `compliance_requirement_templates`/alert rather than creating a duplicate. Feed-level anomaly (> N items/hour) trips a circuit breaker that queues items for batch human review instead of auto-processing. |
| 5 | No human acts on an escalation before the statutory deadline itself (not just the internal checkpoint) | The escalation path continues automatically up to Owner regardless of SLA breaches; the alert's severity auto-upgrades to `critical` inside the final 48 hours before a statutory (not internal) due date, and a same-day digest notification supplements the normal async queue. The agent still cannot submit the filing itself — it can only make the risk maximally visible. |
| 6 | Retention purge conflicts with an active legal hold | `retention_holds` is checked before any `compliance.retention.archive` proposal is even drafted; an active hold on a record suppresses the archive proposal entirely rather than generating a proposal a human must remember to reject. |
| 7 | Cross-tenant data leakage risk in a shared regulation-KB or shared LLM context | The regulation KB is jurisdiction/domain content only (no tenant data ever embedded into it); structured company data retrieval is always scoped by the runtime `company_id` assertion described under Data Access & Tenant Scope. Penetration-style tests inject a mismatched `company_id` in tool results specifically to confirm the assertion rejects them. |
| 8 | Timezone/fiscal-calendar edge cases in deadline math (Kuwait AST vs. a company's configured fiscal year) | All due-date math is computed in the company's configured fiscal calendar and stored as `DATE` (not `TIMESTAMPTZ`) to avoid off-by-one-day errors at UTC/AST boundaries; "days remaining" is always computed against 00:00 in the company's registered jurisdiction timezone. |
| 9 | Upstream regulation-feed outage (source unreachable) | A missed ingestion window raises an internal (agent-team-facing, not tenant-facing) health alert and sets a `feed_staleness_days` indicator; tenant-facing KB-dependent outputs surface a "regulation content last verified N days ago" disclosure once staleness exceeds a threshold, rather than silently presenting stale content as current. |
| 10 | Arabic-source legal text translation risk | Regulation-KB ingestion for Arabic-primary sources stores both the original Arabic passage and a machine-assisted English gloss, tagged separately; any check whose supporting citation relies solely on a low-confidence gloss (no bilingual human review yet) is held at the same `confidence ≤ 0.60` / advisory-only cap as an unreviewed KB entry. |
| 11 | Statutory parameter version drift (a rate/cap changed mid-period, but the wrong version was applied) | `compliance_requirement_templates.parameters` is versioned via `effective_from`/`effective_to`; every assessment records which parameter version it evaluated against (in `evidence`), so a later dispute or correction can be traced to the exact rate table used, and a mid-period change automatically triggers re-assessment of any `compliance_filings` row whose period spans the change. |
| 12 | Two agents (e.g., Compliance and Fraud Detection) raise overlapping alerts for the same underlying event | Alerts carry a shared reference (`assessment_id` or transaction identifier); Approval Assistant's queue de-duplicates by that reference for display purposes while both source alerts remain independently auditable — no alert is silently suppressed, only visually merged for the human. |
| 13 | A blocking flag is placed but the target record is deleted/voided before a human acts | Soft-delete propagation: if `journal_entries`/`payroll_runs`/etc. are voided through the proper reversing-entry workflow, the associated open `ai_decisions` and `compliance_alerts` auto-resolve as `dismissed` with reason `target_voided`, rather than lingering as an actionable item against a record that no longer needs action. |
| 14 | New tenant onboarding with no historical data | Requirement applicability for a brand-new company defaults to `evaluating` (never `applicable` by default) until the onboarding wizard captures entity type, registration numbers, and headcount — avoiding false-confidence assessments against an empty dataset. |

# Future Improvements

- **Direct e-filing integrations.** Read-only calendar/readiness tracking today; a future phase could add a
  human-initiated, agent-assisted submission flow directly to Kuwait government portals (e.g., pre-filling
  a GCC VAT return form from `tax_returns` for a human to review and submit with one click), keeping
  submission itself human-executed but removing manual re-entry.
- **Predictive threshold-crossing forecasts.** Forecast Agent collaboration to predict, ahead of time, when
  a growing company will cross a mandatory-registration threshold (e.g., VAT registration revenue
  threshold), so the applicability assessment runs before the company is already over the line, not just
  after.
- **Structured regulatory diffing.** Move from LLM-summarized regulation-change detection to a structured
  diff against a versioned, machine-readable representation of each regulation's clauses, reducing reliance
  on free-text summarization for high-stakes change detection.
- **Enterprise cross-company benchmarking.** For multi-entity customers (holding groups), a rolled-up
  compliance-health view across subsidiaries, without ever mixing underlying tenant data — aggregate
  status only.
- **One-click auditor evidence export.** A packaged export (PDF/zip) of the full compliance evidence trail
  for a period — requirement catalog snapshot, filings, assessments, attestations, resolved alerts — for
  External Auditor engagements, reducing manual evidence assembly during statutory audits.
- **Fine-tuned jurisdiction-clause extraction model.** Replace generic-LLM regulation summarization with a
  smaller model fine-tuned specifically on GCC statutory and IFRS text structure, improving both cost and
  the precision/recall metrics tracked above.
- **Direct bank-file pre-validation for WPS.** Validate the generated WPS bank file's structural format
  against the bank's published specification before Payroll Manager submits it, catching format-level
  rejections (not just rate/cap errors) pre-emptively.
- **Configurable escalation chains per tenant.** Today's escalation paths are role-based platform defaults;
  a future version could let each company customize its own accountability chain (e.g., a company without a
  dedicated HR Manager role routes payroll-compliance escalations directly to CFO).

# End of Document
