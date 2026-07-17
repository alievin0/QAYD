# Payroll Manager Agent — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: PAYROLL_AGENT
---

# Purpose

The Payroll Manager is the specialized AI agent permanently assigned to QAYD's Payroll module, in every company that has payroll enabled. It is not a chatbot that answers questions about pay when asked — it is a continuously working member of QAYD's autonomous finance workforce that watches every payroll period open, triggers and cross-checks the calculation the moment inputs are ready, compares the resulting run against the company's own trailing history, hunts for the handful of anomalies a human reviewer would otherwise have to find by eye in a multi-page payroll register, and assembles the payslip content and Wage Protection System (WPS) bank-file package that a human will ultimately release. Accounting becomes supervised, not manual, at the payroll layer exactly as it does everywhere else in QAYD: instead of a Payroll Officer spending a day reconciling a spreadsheet before every pay run, the Payroll Manager has the validated register, the variance narrative, the anomaly list, and the payslip package ready within minutes of the calculation completing — every figure traceable to a source row, every flag citing the exact rule it triggered against.

Concretely, this document specifies an agent with five duties, in this order of execution within a single run:

1. **Compute** — trigger and supervise the payroll calculation for a run (gross pay, every earning and deduction component, the PIFSS employee/employer contribution split, and the end-of-service indemnity accrual), always by orchestrating QAYD's existing deterministic Laravel calculation engine rather than by independently deriving posted figures — see Role & Mandate for exactly why authority over the arithmetic itself never moves into the AI layer.
2. **Validate against the prior month** — compare the current run's totals, by employee and by component, against the trailing baseline, and explain every material movement in plain language before a human has to ask why it moved.
3. **Detect anomalies** — flag statistical and operational irregularities (an overtime spike, a salary outside its policy band, a missing mandatory component, a pro-ration arithmetic mismatch) with a severity and a citation, never silently correcting them.
4. **Prepare payslips and the WPS file** — assemble and validate the structured content of every payslip and pre-stage the Wage Protection System salary file so that, by the time a human is ready to disburse, the export is already known to be clean.
5. **Never release** — payroll release (the transition from an approved run to money actually leaving the company) requires the full Payroll Officer → Finance Manager → CEO human approval chain, on every run, in every company, regardless of how high the agent's confidence score is. This is not a policy the agent chooses to respect; it is architecturally incapable of doing otherwise, because its service-account role is never granted the permissions that gate that chain.

Payroll data is the most sensitive data this platform holds — it reveals who earns what, whose loan is being garnished, who is newly hired, who is being terminated — so the Payroll Manager is also the agent held to the platform's strictest data-handling discipline: it never receives an employee's plaintext national ID, passport number, or bank account number, it never writes a row to any table except its own decision log, and every company's data and every company's learned thresholds stay fully isolated from every other company it happens to also serve.

# Role & Mandate

The Payroll Manager's mandate is the compute-validate-prepare layer of the payroll lifecycle described in full in `docs/accounting/PAYROLL.md`. It sits between the deterministic Laravel calculation engine — which remains, without exception, the single source of truth for every number that is ever posted or paid — and the three-stage human approval chain that decides whether a run's numbers become real money movement.

| In Mandate (owned here) | Out of Mandate (owned elsewhere) |
|---|---|
| Triggering and supervising a calculation run; independently shadow-verifying its arithmetic | Authoritative computation of `payroll_items` / `payroll_item_lines` → **Laravel `PayrollCalculationService`** |
| Trend validation vs. prior period(s) and vs. budget | Approving a run at any of its three stages → **human Payroll Officer, Finance Manager, CEO** |
| Statistical/operational anomaly detection (overtime, salary-band deviation, missing components) | Statutory/legal correctness verdicts (garnishment cap, overtime multiplier floor, PIFSS base rules) → **Compliance Agent** |
| Assembling and validating structured payslip content | Bilingual plain-language payslip narration → **Approval Assistant** (Payroll Manager supplies the validated numbers it narrates) |
| Pre-staging and format-validating the WPS/SIF export | Actually submitting the bank file / moving funds → **Treasury Manager**, gated by human disbursement approval |
| Surfacing identity-level data-integrity signals (e.g., a duplicate bank-account hash) as they are first observed | Formal fraud determination and investigation → **Fraud Detection** |
| Independent audit-trail verification, sampling, and control-effectiveness testing | — owned by **Auditor**, who may in turn sample the Payroll Manager's own output |
| Posting the payroll journal entry into the General Ledger | → **Laravel `PayrollJournalBuilder`**, invoked only after `posted` status is reached by human action |

Two properties, mirrored deliberately from how every other agent in the roster is bounded, define the Payroll Manager. First, **it never originates the authoritative number**. Every monetary figure it cites in a validation report, an anomaly flag, or a payslip content package already exists as a row the Laravel calculation engine wrote after a human (or a scheduled job acting on the human's behalf) triggered it. The agent's own "shadow recompute" (see Reasoning & Prompt Strategy) exists only to cross-check that authoritative number, never to replace it — if its shadow figure and Laravel's figure disagree, the agent reports the disagreement as an anomaly, it does not decide which one is right. Second, **everything that could change what an employee is paid, or that could move money, is advisory**. A validation report is auto-generated because it is a read-only comparison of numbers that already exist; a recommendation to override a flagged anomaly, adjust a component before recalculation, or treat a run as WPS-ready is always a proposal — scored, reasoned, and cited — that a human with the relevant permission reviews and acts on.

The mandate is bounded on both sides for the same reason every agent in this roster is: bounded from below, the Payroll Manager does not touch `employee_salary_components`, `employee_loans`, `payroll_items`, or `payslips` directly, because duplicating the calculation engine's authority here would create a second, competing source of truth for numbers that must reconcile to the cent forever; bounded from above, the Payroll Manager has no path — not a missing permission check, an actual absence of any callable tool — to submit a run for approval, approve it at any stage, release it, disburse it, or reverse it. Payroll is QAYD's highest-blast-radius module after Banking, and the platform's standing rule is unambiguous: sensitive operations always require a human approval chain, never AI-only, regardless of confidence.

# Autonomy Level

| Action | Autonomy | Notes |
|---|---|---|
| Trigger a draft calculation (`draft → calculated`) on schedule or on demand | **Auto** | Zero financial consequence; per Payroll's "separation of computation and authorization" principle, `draft`/`calculated` states carry no money-movement risk |
| Recalculate after an upstream input changes (a late-approved leave request, a corrected attendance record) | **Auto** | Re-running is idempotent; regenerates `payroll_items` in place while still in `draft`/`calculated` |
| Shadow-verify Laravel's computed totals against an independent recompute | **Auto** | Read-only comparison; produces a `payroll_calculation_crosscheck` decision, never a correction |
| Validate the run against the trailing 3/6/12-month baseline | **Auto** | Pure comparison of already-posted history against the current draft |
| Detect and score statistical/operational anomalies | **Auto (flag only)** | A flag blocks nothing by itself; see Guardrails for which severities gate Submit |
| Surface a data-integrity signal (duplicate IBAN hash, duplicate national-ID hash) | **Auto (flag + notify Fraud Detection)** | Payroll Manager observes and reports the signal; it never itself concludes fraud |
| Request a compliance pass from the Compliance Agent before finalizing validation | **Auto** | An inter-agent read request, not a mutation |
| Assemble structured payslip content for a `posted` run | **Auto (content), Suggest-only (narrative)** | The structured numbers are auto-assembled from posted data; the plain-language narrative layer (via Approval Assistant) is always reviewable before an employee sees it |
| Pre-stage / format-validate the WPS export ("would this pass") | **Auto (preview only)** | Returns a pass/fail readiness report; produces no bank-submittable file and touches no `wps_export_batches` row |
| Recommend a specific override or correction before recalculation | **Suggest-only** | Written as a `pending_approval` decision; a Payroll Officer accepts, edits, or dismisses it |
| Notify the Auditor role directly on a high-confidence (≥0.90) data-integrity flag | **Auto (escalate)** | Bypasses the normal SLA and the normal approval chain notification path, so a compromised or inattentive Payroll Officer account cannot suppress the alert |
| Submit a run for approval (`calculated → pending_officer_approval`) | **Requires human action** | Not merely gated — no tool exists for the agent to call; Submit is a human's affirmative "I am satisfied, begin the approval chain" act |
| Approve at the Officer, Finance Manager, or CEO stage | **Never — architecturally unavailable** | The service-account role holds no `payroll.approve` grant under any company configuration |
| Post the run to the General Ledger, or disburse/release funds | **Never — architecturally unavailable** | No `payroll.release` / `payroll.disburse` grant exists for any AI role, ever |
| Reverse a posted run, or edit any row after `posted` | **Never — architecturally unavailable, and separately DB-trigger-locked** | Matches the platform-wide "reverse, never edit" rule; enforced twice, at the RBAC layer and at the Postgres trigger layer |
| Read another company's payroll data, or reuse another company's learned thresholds | **Never — architecturally impossible** | Not a permission check to fail; there is no query path in the AI layer that crosses `company_id` |

# Inputs

The Payroll Manager never queries Postgres directly. Like every agent in the FastAPI layer, it calls versioned, permissioned Laravel endpoints under `/api/v1/payroll/*` (see Tools & API Access) that return exactly the rows its service-account RBAC context is entitled to see, already scoped to the one active company named in the request. The categories of data it consumes:

1. **The run itself** — `payroll_runs` (status, period, run_type — regular or final_settlement), `payroll_items` and `payroll_item_lines` for the just-calculated draft, and the same for the trailing 3, 6, and 12 prior runs used as the validation baseline.
2. **Salary structure** — `salary_components` (company-level templates and their computation rules) and `employee_salary_components` (the effective-dated amount/formula assigned to each employee), read at the effective version that governed the period being validated, never the "current" version if it has since changed.
3. **Attendance and leave** — `attendance` aggregates (regular hours, overtime hours by multiplier bucket, night-shift days, holiday-worked days) and `leave_requests` / `employee_leave_balances`, used to confirm that pro-ration, overtime, and leave-pay treatment in the calculated run match what the underlying attendance record actually supports.
4. **Loans and advances** — `employee_loans` / `loan_installments`, to confirm the installment deducted this period matches the schedule and that no installment was taken from an employee whose balance had already reached zero.
5. **Statutory rule sets** — the country-scoped PIFSS contribution-rate table, the indemnity formula and its versioned parameters, and the current WPS/SIF file specification for the company's `country_code`, all owned and versioned by the Compliance Agent's rule store, read here, never re-authored here.
6. **Employee master data — masked** — `employees` organizational fields (department, branch, position, employment type, employment status) in full, but `national_id`, `passport_number`, and bank account/IBAN fields only as a deterministic salted hash and a display mask (`***-***-1234`); see Data Access & Tenant Scope for exactly how this is enforced upstream of the agent, not by the agent's own discipline.
7. **GL mappings** — `payroll_gl_account_mappings`, read-only, so the agent's validation can confirm every component maps to an account before the run reaches Post, rather than a Finance Manager discovering an unmapped component after posting fails.
8. **Company policy configuration** — `payroll.include_contractors`, `max_overtime_hours_per_period`, garnishment cap percentage, `indemnity_service_reset_on_rehire`, and the company's own configured anomaly-sensitivity thresholds, all of which change what "normal" means for that company specifically.
9. **This company's memory** — its own prior accepted and rejected recommendations, retrieved as retrieval examples so the agent does not re-flag a pattern this company's Payroll Officer has already told it, twice, is expected (see Collaboration and Future Improvements for the learning loop).

**Example — context bundle assembled when a calculation completes and validation begins:**

```json
{
  "request_id": "9a7c2e10-4f61-4b8a-9d2a-6b1e0c8f7a33",
  "company_id": 4821,
  "triggered_by": "scheduled_job",
  "task_type": "payroll_validation_pass",
  "scope": {
    "payroll_run_id": 55012,
    "period_start": "2026-06-01",
    "period_end": "2026-06-30",
    "run_type": "regular"
  },
  "resolved_inputs": {
    "payroll_items_count": 42,
    "baseline_run_ids": [54710, 54405, 54098],
    "country_code": "KW",
    "statutory_ruleset_version": "kw-2025-11",
    "employees_masked_pii": true,
    "gl_mappings_id": 118
  }
}
```

# Outputs

Every Payroll Manager output is a row in the shared `ai_decisions` table — the same table every agent in this roster writes to (defined in `docs/ai/agents/CFO_AGENT.md`; this document does not redefine it, only extends the shared `ai_decision_type` enum with the values this agent introduces). This is what lets a Payroll Officer's approval screen, the Auditor's review queue, and the CEO Assistant's daily digest all render a Payroll Manager decision identically to any other agent's, with no Payroll-specific rendering logic anywhere in the frontend.

```sql
-- ============================================================
-- Extends the shared ai_decisions.decision_type enum
-- (table itself owned by docs/ai/agents/CFO_AGENT.md — do not redefine)
-- ============================================================
ALTER TYPE ai_decision_type ADD VALUE 'payroll_calculation_crosscheck';
ALTER TYPE ai_decision_type ADD VALUE 'payroll_trend_validation';
ALTER TYPE ai_decision_type ADD VALUE 'payroll_anomaly_flag';
ALTER TYPE ai_decision_type ADD VALUE 'payroll_data_integrity_flag';
ALTER TYPE ai_decision_type ADD VALUE 'payroll_indemnity_reconciliation';
ALTER TYPE ai_decision_type ADD VALUE 'payroll_wps_readiness';
ALTER TYPE ai_decision_type ADD VALUE 'payroll_payslip_content_validation';
ALTER TYPE ai_decision_type ADD VALUE 'payroll_readiness_score';
```

Because a single validation pass over a 42-employee run can produce dozens of atomic `ai_decisions` rows (one per anomaly, one per employee-level check that fails), the Payroll Manager additionally owns one small, Payroll-specific rollup table — not a competing source of truth, purely a denormalized summary so the Payroll UI can render one badge ("AI-validated: 2 flags, 0 critical") without joining forty rows on every page load:

```sql
CREATE TYPE payroll_validation_verdict AS ENUM (
  'clean', 'flags_present', 'critical_flags_present', 'stale', 'skipped_ai_unavailable'
);

CREATE TABLE payroll_ai_validation_runs (
  id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id            BIGINT NOT NULL REFERENCES companies(id),
  payroll_run_id        BIGINT NOT NULL REFERENCES payroll_runs(id),
  agent_code            VARCHAR(40) NOT NULL DEFAULT 'PAYROLL_AGENT' REFERENCES ai_agents(code),
  verdict               payroll_validation_verdict NOT NULL,
  decision_ids          JSONB NOT NULL,          -- array of ai_decisions.id produced by this pass
  employees_checked     INT NOT NULL,
  flags_count           INT NOT NULL DEFAULT 0,
  critical_flags_count  INT NOT NULL DEFAULT 0,
  safe_to_submit        BOOLEAN NOT NULL DEFAULT false,
  ruleset_version       VARCHAR(30) NOT NULL,    -- statutory ruleset this pass validated against
  started_at            TIMESTAMPTZ NOT NULL,
  completed_at          TIMESTAMPTZ NULL,
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX idx_pavr_run_latest ON payroll_ai_validation_runs (payroll_run_id, started_at DESC);
CREATE INDEX idx_pavr_company_verdict ON payroll_ai_validation_runs (company_id, verdict);
```

**Example — `payroll_trend_validation` output (auto, no approval required to view):**

```json
{
  "success": true,
  "data": {
    "id": 771001,
    "agent_code": "PAYROLL_AGENT",
    "decision_type": "payroll_trend_validation",
    "status": "approved",
    "subject_type": "payroll_runs",
    "subject_id": 55012,
    "confidence_score": 96.0,
    "reasoning": "Total gross moved +3.1% against the 3-month trailing average, fully explained by two known events: employee #1204's approved promotion effective 2026-06-01 (+KWD 450 base) and one new hire (#1391) prorated for 18 of 30 days. No unexplained residual movement.",
    "payload": {
      "period": "2026-06",
      "total_gross_current": 132400.0000,
      "total_gross_trailing_avg": 128410.0000,
      "variance_pct": 3.1,
      "explained_variance_pct": 3.1,
      "unexplained_variance_pct": 0.0,
      "contributing_events": [
        { "employee_id": 1204, "type": "promotion", "delta": 450.0000 },
        { "employee_id": 1391, "type": "new_hire_proration", "delta": 1540.0000 }
      ]
    },
    "sources": [
      { "type": "payroll_runs", "id": 55012, "label": "June 2026 regular run (draft)" },
      { "type": "payroll_runs", "id": 54710, "label": "May 2026 (posted)" },
      { "type": "employee_position_history", "id": null, "label": "Employee #1204 promotion record" }
    ],
    "requires_approval": false
  },
  "message": "Trend validation complete",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9a7c2e10-4f61-4b8a-9d2a-6b1e0c8f7a33",
  "timestamp": "2026-07-02T05:11:03Z"
}
```

**Example — `payroll_anomaly_flag` output (auto-flagged, blocks Submit until acknowledged):**

```json
{
  "success": true,
  "data": {
    "id": 771014,
    "agent_code": "PAYROLL_AGENT",
    "decision_type": "payroll_anomaly_flag",
    "status": "pending_approval",
    "subject_type": "payroll_item_lines",
    "subject_id": 990234,
    "confidence_score": 91.0,
    "reasoning": "Employee #1188 (Ahmad Al-Fahad, Warehouse dept.) logged 62.0 overtime hours in June against a 6-month trailing average of 8.3 hours and a company policy cap (`max_overtime_hours_per_period`) of 40. All 62 hours are individually attendance-backed and manager-approved, so this is not a data error, but the volume itself exceeds policy and Kuwait Labour Law Art. 66-67's overtime provisions warrant a documented business reason before payment.",
    "payload": {
      "employee_id": 1188,
      "component": "overtime",
      "current_period_hours": 62.0,
      "trailing_6mo_avg_hours": 8.3,
      "policy_cap_hours": 40,
      "amount_at_risk": 612.5000,
      "severity": "warning"
    },
    "sources": [
      { "type": "attendance", "id": null, "label": "42 attendance rows, 2026-06-01 to 2026-06-30, employee #1188" },
      { "type": "payroll_runs", "id": 55012, "label": "June 2026 regular run (draft)" }
    ],
    "recommended_action": "Confirm the business reason (e.g., a covering shift for the vacant Warehouse Lead role) and record it before Submit, or cap the paid hours at policy and route the excess to a separate approval.",
    "requires_approval": true
  },
  "message": "Anomaly flagged, acknowledgment required before Submit",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9a7c2e10-4f61-4b8a-9d2a-6b1e0c8f7a33",
  "timestamp": "2026-07-02T05:11:07Z"
}
```

Confidence is expressed on the shared platform 0-100 scale (`NUMERIC(5,2)`, per `ai_decisions.confidence_score`). The Payroll Manager never emits 100.00 — 99.90 is the practical ceiling — so that no downstream UI or human can treat any agent output as certain rather than as a very-high-confidence proposal. Per the platform-wide convention already established for this module in `docs/accounting/PAYROLL.md`: confidence below 70 is always surfaced regardless of severity; confidence at or above 95 still requires an explicit Payroll Officer acknowledgment click before Submit, it never auto-clears; and a data-integrity flag at or above 90 additionally and independently notifies the Auditor role, bypassing the normal chain entirely.

# Tools & API Access

The Payroll Manager's service-account role, `ai_payroll_manager`, is a member of the same `roles`/`permissions` system every human user is, granted a deliberately restricted subset of the permissions a human Payroll Officer holds — critically, never including any approval-tier or money-movement permission, per the platform's hard-coded invariant that no company configuration can grant an AI service account an approval, release, disbursement, reversal, or void permission, ever. Every tool below is a thin wrapper around one Laravel endpoint; the agent has no other way to reach the database.

| Tool Function | Method & Endpoint | Permission Required | Read/Write | Notes |
|---|---|---|---|---|
| `get_payroll_run(run_id)` | `GET /api/v1/payroll/runs/{id}` | `payroll.read` | Read | Full run header: status, period, totals, run_type |
| `list_payroll_runs(company_id, filters)` | `GET /api/v1/payroll/runs` | `payroll.read` | Read | Used to assemble the trailing-baseline set |
| `trigger_calculation(run_id, idempotency_key)` | `POST /api/v1/payroll/runs/{id}/calculate` | `payroll.calculate` | Write (delegated) | Idempotency-keyed; only legal while `status IN ('draft','calculated')`; the arithmetic itself executes inside Laravel's `PayrollCalculationService`, never in the AI layer |
| `get_payroll_items(run_id)` | `GET /api/v1/payroll/runs/{id}/items` | `payroll.read`, `payroll.salary.view` | Read | Full monetary amounts — required for cross-checking; masked entirely without `payroll.salary.view` |
| `get_employee(employee_id)` | `GET /api/v1/payroll/employees/{id}` | `payroll.employee.read` | Read | Organizational fields in full; `national_id`/`passport_number`/IBAN returned only as `*_hash` + display mask, never plaintext — see Data Access & Tenant Scope |
| `get_attendance_summary(employee_id, period)` | `GET /api/v1/payroll/employees/{id}/attendance-summary` | `payroll.read` | Read | Aggregated hours by bucket, not raw clock events |
| `get_leave_ledger(employee_id, period)` | `GET /api/v1/payroll/employees/{id}/leave-ledger` | `payroll.read` | Read | For leave-pay-treatment cross-checks |
| `get_loan_schedule(employee_id)` | `GET /api/v1/payroll/employees/{id}/loans` | `payroll.read` | Read | Outstanding balance and next installment due |
| `get_statutory_ruleset(country_code)` | `GET /api/v1/payroll/statutory-rules?country={cc}` | `payroll.read` | Read | Versioned PIFSS/indemnity/WPS rule parameters; owned by Compliance Agent's rule store |
| `get_gl_mappings(company_id)` | `GET /api/v1/payroll/gl-account-mappings` | `payroll.read` | Read | Confirms every component maps to an account pre-Post |
| `request_compliance_scan(run_id)` | `POST /api/v1/ai/agents/compliance/tasks` | `ai.task.create` (scoped) | Write (inter-agent) | Delegates the statutory-correctness verdict to the Compliance Agent; not a payroll mutation |
| `preview_wps_export(run_id)` | `POST /api/v1/payroll/runs/{id}/wps-preview` | `payroll.wps.preview` | Read/sandbox | Returns a pass/fail readiness report against the SIF spec; produces no file and touches no `wps_export_batches` row |
| `get_payslip_data(payroll_item_id)` | `GET /api/v1/payroll/payslips/{item_id}/data` | `payroll.read` | Read | Structured line data feeding payslip content assembly |
| `submit_decision(payload)` | `POST /api/v1/ai/decisions` | `ai.decision.create` | Write | The agent's only true write path; creates one `ai_decisions` row, never touches a Payroll table |
| `get_company_policy(company_id)` | `GET /api/v1/payroll/settings` | `payroll.read` | Read | Overtime caps, garnishment cap %, contractor inclusion, indemnity rehire rule |

**Explicitly never available to this agent, under any company configuration** — listed here precisely because their absence is the load-bearing guarantee of this whole document, not an implementation detail:

| Endpoint | Permission | Why it is absent |
|---|---|---|
| `POST /api/v1/payroll/runs/{id}/submit` | `payroll.submit` | Submit is the human's affirmative act of starting the approval chain |
| `POST /api/v1/payroll/runs/{id}/approve` | `payroll.approve` | Gates all three approval stages (Officer/FM/CEO) |
| `POST /api/v1/payroll/runs/{id}/post` | `payroll.release` | Posts the journal entry into the GL |
| `POST /api/v1/payroll/runs/{id}/disburse` | `payroll.disburse` | Moves money; Finance Manager-only, human-executed |
| `POST /api/v1/payroll/runs/{id}/reverse` | `payroll.reverse` | Reversal of a posted run is a human financial-correction decision |
| `PATCH /api/v1/payroll/employees/{id}/salary-components` | `payroll.salary.manage` | Changing what someone is paid is never an AI action |
| `POST /api/v1/payroll/wps-export-batches` | `payroll.wps.export` | The bank-submittable file, distinct from the sandbox preview above |
| `DELETE` on any payroll resource | `payroll.void` / `*.delete` | Financial records are never hard-deleted, by anyone, let alone an agent |

# Data Access & Tenant Scope

The Payroll Manager's service-account token is bound at issuance to a specific, explicit set of companies — the same `company_users`-style binding a human employee has — and every tool call requires the target `company_id` to be a member of that bound set or the Laravel API rejects it with `403` before any query executes. There is no code path in the FastAPI layer that constructs a cross-company query; company scoping is enforced by the Laravel API the agent calls through, identically to how it is enforced for a human user, which means a defect in the agent's own prompt or reasoning can misdirect a request but can never widen what that request is allowed to see.

Within a single company, salary and identity data carry a second, independent scoping layer beyond ordinary RBAC, exactly as specified for the Payroll module generally:

- **Salary amounts** require `payroll.salary.view` specifically — a role with broad `accounting.*` permissions does not see individual salaries by default. The `ai_payroll_manager` role is granted `payroll.salary.view`, because validating a `NUMERIC(19,4)` net-pay figure against a prior period is impossible without seeing it; this is a deliberate, narrow, and necessary exception, and it is the only PII-adjacent grant this role holds.
- **National ID, passport number, and bank account/IBAN** are never sent to the AI layer in plaintext, under any permission. Laravel exposes two derived fields instead: a display mask (`***-***-1234`) for any human-readable output the agent produces, and a deterministic salted hash (`national_id_hash`, `iban_hash` — HMAC-SHA256 keyed per-company, so the same IBAN hashes identically only within one company's scope and never matches across companies) for exact-match comparison. This is what lets the agent detect "employee #1188 and employee #1341 share a bank account" — a genuine, useful data-integrity signal — without the agent, its prompts, its logs, or its model provider ever holding a real IBAN. The hash is computed and stored by Laravel at write time; the agent only ever reads it.
- **`ai_memory` is strictly per-company.** Every learned threshold (this company's typical overtime variance, this company's history of accepted vs. overridden flags) is keyed by `company_id` in both the Postgres row and the Redis cache key (`ai:mem:{company_id}:payroll:*`). A pattern the agent learns from Company A's payroll history — even an anonymized statistical shape, not raw data — never adjusts a threshold applied to Company B. Cross-company benchmarking is not built into the default agent at all; see Future Improvements for the explicit, opt-in exception to this rule.
- **Branch-level scoping** (`branch_id`) is honored wherever a company has enabled branch isolation, so a Payroll Officer scoped to one branch and an agent task triggered on that Officer's behalf only ever assembles a validation context from that branch's employees, never the whole company's.
- **Conversational context** (`ai_conversations`/`ai_messages`) used for an ad hoc question ("why did payroll jump this month?") is likewise scoped to the requesting user's own permission set at query time — the agent cannot use a conversational entry point to return data the asking user could not otherwise see through the ordinary UI.

# Reasoning & Prompt Strategy

The Payroll Manager runs as a LangGraph-style directed graph, not a single freeform prompt, because payroll validation is a fixed sequence of distinct reasoning tasks with very different risk profiles — arithmetic cross-checking must be deterministic and must never be delegated to a language model's own mental math, while explaining *why* a number moved is exactly the kind of synthesis a language model is well suited to, provided every number it uses in that explanation was fetched from a tool call, never generated.

```
┌───────────┐   ┌────────────────┐   ┌───────────────────┐   ┌──────────────────┐
│  TRIGGER   │──▶│ FETCH RUN STATE │──▶│ CROSS-CHECK CALC   │──▶│ TREND COMPARISON  │
│ (schedule  │   │ (run, items,    │   │ (independent shadow │   │ (vs trailing 3/6/ │
│  or status │   │  line items,    │   │  recompute vs the   │   │  12-mo baseline,  │
│  webhook)  │   │  salary rules)  │   │  posted numbers)    │   │  vs budget)       │
└───────────┘   └────────────────┘   └───────────────────┘   └──────────────────┘
                                                                          │
      ┌───────────────────────────────────────────────────────────────────┘
      ▼
┌───────────────────┐   ┌──────────────────────┐   ┌───────────────────────┐
│  ANOMALY SCAN       │──▶│ COMPLIANCE REQUEST     │──▶│ WPS READINESS PREVIEW  │
│ (overtime, salary   │   │ (delegate statutory    │   │ (IBAN format, missing  │
│  band, missing       │  │  correctness verdict   │   │  bank details, dup     │
│  components,         │  │  to Compliance Agent)  │   │  IBAN/national-ID hash)│
│  pro-ration errors)  │   └──────────────────────┘   └───────────────────────┘
└───────────────────┘                                            │
                                                                    ▼
                                                    ┌───────────────────────┐
                                                    │ PAYSLIP CONTENT BUILD   │
                                                    │ (structured lines →     │
                                                    │  handed to Approval     │
                                                    │  Assistant for EN/AR    │
                                                    │  narration)             │
                                                    └───────────────────────┘
                                                                    │
                                                                    ▼
                                                    ┌───────────────────────┐
                                                    │ EMIT ai_decisions +     │
                                                    │ payroll_ai_validation_  │
                                                    │ runs rollup row         │
                                                    └───────────────────────┘
```

Four rules govern how the model is prompted at every node:

1. **Numbers are fetched, never generated.** Any monetary figure that appears in a `reasoning` string must have arrived via a tool-call return value in the same execution trace. Before a decision is persisted, a server-side numeric-consistency check parses every number quoted in `reasoning` and rejects the write (logging an `ai_logs` integrity failure, never surfacing the draft to a human) if a figure cannot be matched to a tool result. This is the single most important guardrail against hallucinated payroll figures, and it runs in code, not in the prompt.
2. **Every reasoning string cites a rule, not just a fact.** A compliance-adjacent flag names the exact statutory provision (e.g., "Kuwait Labour Law No. 6/2010, Art. 66-67 overtime provisions") or the exact company policy field (`max_overtime_hours_per_period = 40`) that was breached, sourced from the Compliance Agent's rule store, never authored freehand by the Payroll Manager itself.
3. **Low temperature, structured output, few-shot calibration.** Anomaly classification and severity scoring run at low sampling temperature against a structured output schema (matching the `ai_decisions.payload` shape), with few-shot examples drawn from this company's own `ai_memory` of previously accepted and previously dismissed flags — a first-month pro-rated new hire's naturally lower gross pay is an explicit negative example so the model does not learn to flag every new hire as an anomaly.
4. **Explanation is separated from computation.** The Payroll Manager's own output never contains a bilingual, employee-facing narrative — it hands validated structured content to the Approval Assistant, whose sole job is EN/AR plain-language narration, keeping "is this number right" (Payroll Manager, deterministic, cross-checked) cleanly separated from "does this read clearly to a human" (Approval Assistant, a genuinely generative task where model creativity is an asset, not a risk).

Retrieval is split the same way as computation and narration: exact figures and rule parameters are always fetched via structured tool calls against Laravel endpoints (never RAG, never approximated), while the small amount of unstructured context the agent benefits from — this company's past written justifications for an overtime exception, an HR note attached to a disciplinary deduction — is retrieved via vector search over `ai_memory` and past `ai_decisions.reasoning` text, scoped to `company_id`, and is used only to inform tone and precedent, never as a substitute for a fetched number.

# Collaboration With Other Agents

| Collaborator | Direction | Interaction |
|---|---|---|
| **Compliance Agent** | Payroll Manager → Compliance Agent (request); Compliance Agent → Payroll Manager (verdict) | Before a validation pass is marked complete, the Payroll Manager calls `request_compliance_scan(run_id)`, handing off the statutory-correctness verdict entirely — garnishment-cap breach, overtime multiplier below the statutory floor, PIFSS base miscalculation, indemnity-formula version mismatch. A critical compliance flag returned by the Compliance Agent blocks Submit until a Finance Manager explicitly acknowledges it, exactly as specified for this module; the Payroll Manager never overrides or waters down a Compliance Agent verdict, it only aggregates it into the run's overall `payroll_ai_validation_runs.verdict`. |
| **Auditor** | Payroll Manager → Auditor (escalation + evidence); Auditor → Payroll Manager (sampling requests) | Any `payroll_data_integrity_flag` at confidence ≥ 90 is pushed to the Auditor role via `notifications` independent of the normal approval-chain path, so a compromised or inattentive Payroll Officer account cannot bury it. Separately, and on its own schedule, the Auditor agent samples historical `ai_decisions` rows the Payroll Manager produced — re-running the shadow cross-check against a already-posted, already-paid run — to independently verify the Payroll Manager's own historical accuracy; the Payroll Manager exposes `get_payroll_run`/`get_payroll_items` to the Auditor's queries exactly as it would to any other permissioned caller, with no special-cased self-audit exemption. |
| **Treasury Manager** | Payroll Manager → Treasury Manager (funding notice) | Once a run reaches `approved` and is queued for disbursement, the Payroll Manager (via the standard `notifications`/domain-event path, never a direct call) surfaces the run's `total_net` and `total_employer_cost` to the Treasury Manager agent three business days ahead of `pay_date` by default, so Treasury can confirm the operating account can cover it and flag a funding gap to the CFO/CEO before disbursement day, rather than a shortfall being discovered only when the bank transfer itself fails. |
| **Fraud Detection** | Payroll Manager → Fraud Detection (raw signal handoff) | The Payroll Manager is the first agent to see a run's raw data, so it is the natural point to notice a duplicate `iban_hash`/`national_id_hash` across active employees or a commission line with no matching sales event — but it stops at reporting the signal (`payroll_data_integrity_flag`); classifying it as fraud, and any deeper investigation (login activity, ghost-employee determination), is Fraud Detection's mandate, not this agent's. |
| **Forecast Agent** | Bidirectional | Payroll Manager supplies posted `payroll_runs` actuals as ground truth for the Forecast Agent's payroll-cost projections; it consumes the Forecast Agent's headcount-change projections to pre-warn a Payroll Officer that "next month's run will include 3 new hires and 1 termination" ahead of the period even opening. |
| **General Accountant** | Payroll Manager → General Accountant (handoff at Post) | The Payroll Manager's mandate ends at validating payroll-domain correctness; once a run is `posted`, verifying that the resulting journal entry itself balances and maps to the correct GL accounts is the General Accountant's cross-check, not a re-validation of payroll figures already checked upstream. |
| **Document AI / OCR Agent** | OCR Agent → Payroll Manager | When a new hire's bank letter, IBAN certificate, or civil ID is uploaded, OCR Agent extracts and structures the fields; the Payroll Manager consumes the already-validated, already-linked `bank_account_id`/`national_id_hash` rather than parsing any document itself — document understanding is never duplicated here. |
| **Approval Assistant** | Payroll Manager → Approval Assistant (content handoff) | The Payroll Manager assembles and validates the structured payslip content package; the Approval Assistant turns it into the bilingual, plain-language narrative an employee or approver actually reads — the numbers-correctness/explanation-clarity boundary described in Reasoning & Prompt Strategy. |
| **CEO Assistant / CFO** | Payroll Manager → CEO Assistant / CFO (summary feed) | Run-level summaries (total cost by department, flag counts, compliance status) feed the CEO Assistant's daily digest and the CFO Agent's cash-flow and board-narrative synthesis, exactly as any other agent's `ai_decisions` rows would. |

# Guardrails & Human Approval

Payroll release — the point at which an approved run's numbers stop being an internal draft and become money actually leaving the company — requires the full **Payroll Officer → Finance Manager → CEO** human approval chain on every single run, in every company, with no confidence threshold, no run size, and no company configuration capable of shortening it. This is the one guardrail this entire document exists to guarantee, and it is enforced four separate times, at four separate layers, deliberately redundant so that a defect in any single layer cannot itself become a bypass:

1. **RBAC layer — the permission simply does not exist for this role.** The `ai_payroll_manager` service-account role is never granted `payroll.approve`, `payroll.release`, `payroll.disburse`, `payroll.reverse`, or `payroll.void`, under any company's configuration, at any time. This is a platform invariant enforced centrally by the Authorization service, not a per-company setting a company's admin could accidentally misconfigure — no admin screen anywhere in QAYD offers a checkbox that would grant an AI role an approval-tier permission.
2. **Application layer — every approval-tagged endpoint requires an authenticated human session, not merely a valid permission.** `POST /api/v1/payroll/runs/{id}/approve` and its siblings additionally check that the calling principal is a human user (`users.id`, not an AI service-account token) before evaluating the permission at all, so even a hypothetical future misconfiguration granting the permission string would still be rejected on principal type.
3. **Database layer — the same trigger-level lock the Payroll module already enforces for everyone.** The moment `payroll_runs.status` leaves `draft`/`calculated`, a Postgres `BEFORE UPDATE` trigger on `payroll_items`/`payroll_item_lines` raises an exception on any attempted write outside the Post/Disburse transactions themselves — defense in depth against an application-layer bug, agent or human, bypassing the Service-layer check.
4. **UI layer — no pre-checked affordance.** Every Payroll Manager recommendation renders in the approval screen as a card a human reads, with its `reasoning` and `sources` visible, and an explicit Accept/Override/Dismiss control — never a pre-checked "approve" button, and never a UI state where accepting the AI's summary silently also executes the approval itself. Accepting a recommendation and approving a run are always two distinct, separately logged actions.

Additional guardrails specific to this agent:

- **MFA stacks on top of RBAC, not instead of it.** Every human approval action in the chain — Officer, Finance Manager, and CEO stage alike — requires a session with `mfa_verified: true` regardless of the approver's base role, per the platform-wide sensitive-operation rule. A session that has authenticated but not completed MFA can view the Payroll Manager's validation report but is rejected with `403`/`mfa_required` on the approval action itself.
- **Overrides are logged, not silent.** When a human proceeds despite a critical Payroll Manager or Compliance Agent flag, the acting approver must supply an `override_reason`, written to `payroll_run_approvals.override_reason` — a permanent part of the audit trail, queryable by the Auditor agent and by a human auditor years later, exactly like every other approval decision on the run.
- **Salary and identity privacy is structural, not a UI convention.** `payroll.salary.view` and the PII-hash-only design described in Data Access & Tenant Scope are enforced at the API layer the agent calls through; there is no "trust the agent to behave" step anywhere in this design — the agent is physically never handed a plaintext national ID or IBAN to mishandle in the first place.
- **The AI layer is a dependency, never a bottleneck, for the human-run pipeline.** If the FastAPI AI layer is unreachable, degraded, or simply slow, payroll calculation, submission, and approval proceed unaffected, because calculation is a Laravel-owned deterministic service that does not call out to the AI layer synchronously. A run calculated while the AI layer was down is marked `payroll_ai_validation_runs.verdict = 'skipped_ai_unavailable'` and surfaced plainly in the UI as "not yet AI-validated" — never silently presented as clean, and never blocking a human from proceeding with their own judgment.
- **No permission accretion over time.** The `ai_payroll_manager` role's permission set is defined once, centrally, and reviewed on the same cadence as every other role's; a company cannot incrementally widen it request-by-request (e.g., "just this once, let the agent post because the Finance Manager is on leave") — there is no emergency-override path that grants an AI role a permission it does not otherwise hold, because an emergency is precisely the condition under which the platform's designers assume judgment, not automation, is most needed.
- **Cross-company blast radius is architecturally zero.** Because the service-account token is bound to an explicit company set and every tool call is company-scoped at the API layer (Data Access & Tenant Scope), a defect in this agent's reasoning for Company A cannot leak into, or trigger any action in, Company B — there is no shared mutable state between companies for a bug to exploit.

# Worked Scenarios

## Scenario 1 — a clean monthly run

**Setup.** Al-Salam Trading Co. (`company_id 4821`), 42 active employees, monthly payroll, `country_code = 'KW'`. The June 2026 payroll period closes on 2026-06-30. On 2026-07-02 at 05:00 local time, a scheduled job triggers `payroll_runs` id `55012` (`run_type = 'regular'`) into existence in `draft`.

**Flow.**

1. The Payroll Officer (user `2290`, Fatima Al-Rashidi) opens the run and clicks **Calculate**. Laravel's `PayrollCalculationService` executes the twelve-step engine described in `docs/accounting/PAYROLL.md` and writes `payroll_items`/`payroll_item_lines`: `total_gross = 132,400.0000`, employee PIFSS withholding `8,850.0000`, employer PIFSS cost `12,980.0000`, loan repayments `2,450.0000`, other deductions `750.0000`, indemnity accrual `5,516.6667`, `total_net = 120,350.0000`. Status moves to `calculated`.
2. The `calculated` status transition emits a domain event the Payroll Manager subscribes to; within 45 seconds it has called `trigger_calculation`'s read-sibling `get_payroll_run`/`get_payroll_items`, run its shadow cross-check (independently re-deriving gross, PIFSS split, and indemnity from the same `salary_components`/`attendance`/statutory-ruleset inputs), and confirmed an exact match to the cent against Laravel's figures — `payroll_calculation_crosscheck`, confidence 99.10, `reasoning`: "Shadow recompute matches posted figures exactly across all 42 employees and all seven component categories; zero residual difference."
3. Trend validation runs next: `total_gross` moved +3.1% against the trailing 3-month average, fully attributable to employee #1204's approved 2026-06-01 promotion and employee #1391's prorated first-month pay — the exact `payroll_trend_validation` decision shown in Outputs, confidence 96.0, `requires_approval: false`.
4. The anomaly scan finds nothing above the company's configured sensitivity threshold; the compliance-scan request to the Compliance Agent returns clean (no garnishment breach, overtime within policy for every employee this period, PIFSS base correctly excludes the one non-PIFSS-eligible commission line).
5. `preview_wps_export` returns `ready: true` for all 42 employees — every active IBAN passes the Kuwait IBAN checksum, no duplicate `iban_hash` across the run.
6. A `payroll_ai_validation_runs` row is written: `verdict = 'clean'`, `flags_count = 0`, `critical_flags_count = 0`, `safe_to_submit = true`, referencing the two `ai_decisions` rows above.
7. Fatima sees a single green "AI-validated: no flags" badge, reviews the register herself, and clicks **Submit** — a purely human action the agent has no tool to perform. The run enters `pending_officer_approval`, and Fatima's own click is the first of three required human sign-offs (she is also the Payroll Officer of record, so this "Submit" and her earlier review together satisfy the Officer stage per the module's approval design).
8. Finance Manager (user `1187`, Yousef Al-Kandari) reviews and approves → `pending_ceo_approval`. CEO (user `101`) approves → `approved`. Fatima clicks **Post**; Laravel's `PayrollJournalBuilder` writes the balanced journal entry and `payroll_runs.status = 'posted'`. Payslips generate automatically; the Approval Assistant narrates them bilingually from the Payroll Manager's already-validated content. Yousef clicks **Disburse**; the WPS file — already known-clean from step 5 — is generated for real and uploaded to the bank. `payroll_items.payment_status` moves to `paid` per employee as the bank confirms.

**Outcome.** Total elapsed AI validation time: under two minutes. Zero AI-authored mutations occurred anywhere in the run; every state transition from `calculated` onward was a human click.

## Scenario 2 — an overtime anomaly and a data-integrity flag

**Setup.** Same company, July 2026 run, `payroll_runs` id `55013`.

**Flow.** The cross-check and trend validation pass clean as in Scenario 1, but the anomaly scan produces the `payroll_anomaly_flag` shown in full in Outputs: employee #1188's 62.0 overtime hours against a policy cap of 40 and a trailing average of 8.3 — `severity: warning`, confidence 91.0, `requires_approval: true`. Independently, the same pass computes `iban_hash` collisions across the run's 42 employees and finds one: employee #1188 and employee #1341 (Sara Al-Mutairi, a different department, hired eleven days earlier) resolve to the identical hash. The Payroll Manager emits a `payroll_data_integrity_flag`:

```json
{
  "decision_type": "payroll_data_integrity_flag",
  "status": "pending_approval",
  "confidence_score": 93.0,
  "reasoning": "Employees #1188 and #1341 resolve to an identical iban_hash. This does not by itself confirm fraud — a shared family bank account is a legitimate, if unusual, configuration — but it is a data-integrity pattern this platform's Fraud Detection agent should evaluate before this run's disbursement reaches these two accounts.",
  "payload": {
    "employee_ids": [1188, 1341],
    "signal_type": "duplicate_iban_hash",
    "hire_date_proximity_days": 11
  },
  "sources": [
    { "type": "employee_bank_accounts", "id": null, "label": "iban_hash comparison, active accounts, company 4821" }
  ],
  "recommended_action": "Hold disbursement to employees #1188 and #1341 pending Fraud Detection review; all other 40 employees are unaffected and may proceed.",
  "requires_approval": true
}
```

Because this flag's confidence (93.0) clears the 90 threshold, the Payroll Manager independently notifies the Auditor role via `notifications`, outside the normal approval-chain path, at the same moment it writes the decision — so the flag reaches an independent reviewer even if Fatima, the Payroll Officer, were to dismiss it without investigating. `payroll_ai_validation_runs.verdict` is set to `critical_flags_present`, `safe_to_submit = false`; the Submit button is disabled in the UI pending acknowledgment of both flags. Fatima investigates: the overtime is confirmed legitimate (a documented Warehouse Lead coverage gap) and she records the business reason; the IBAN match turns out to be a genuine data-entry error (Sara's own new account was mistyped as a copy-paste of a colleague's during onboarding) rather than fraud, corrected by HR, and the run is recalculated and re-validated before proceeding — clean on the second pass.

## Scenario 3 — a final settlement with an indemnity formula version mismatch

**Setup.** Employee #1052 (Mohammed Al-Ajmi, 6.4 years of service) resigns effective 2026-07-15. HR sets `employment_status = 'terminated'`, triggering a `final_settlement` run, `payroll_runs` id `55020`.

**Flow.** Laravel computes the settlement using the currently active indemnity ruleset version `kw-2025-11`, arriving at an indemnity figure of `4,480.0000` KWD. During cross-check, the Payroll Manager's shadow computation pulls the statutory ruleset via `get_statutory_ruleset('KW')` and notices the ruleset's `effective_from` is 2026-05-01 — but Mohammed's service began well before that, and the *prior* ruleset version (`kw-2024-03`) was still in effect for the majority of his accrual period. It computes the settlement both ways: `kw-2025-11` yields `4,480.0000`; a blended calculation that applies `kw-2024-03` through 2026-04-30 and `kw-2025-11` from 2026-05-01 onward — the platform's stated effective-dating rule for any versioned statutory parameter — yields `4,792.5000`, a difference of `312.5000` KWD in the employee's favor.

```json
{
  "decision_type": "payroll_indemnity_reconciliation",
  "status": "pending_approval",
  "confidence_score": 88.0,
  "reasoning": "The posted indemnity figure (4,480.0000) was computed entirely under ruleset kw-2025-11 (effective 2026-05-01). Employee #1052's service spans 2020-03-01 to 2026-07-15, meaning the bulk of the accrual period falls under the prior ruleset kw-2024-03. Applying the platform's effective-dated calculation rule (each period accrues under the ruleset active during that period, not the ruleset active on the termination date) yields 4,792.5000 — a 312.5000 KWD difference. Recommending Finance Manager confirm which treatment is intended; this is a configuration-interpretation question, not an arithmetic error in either figure.",
  "payload": {
    "employee_id": 1052,
    "posted_amount": 4480.0000,
    "effective_dated_amount": 4792.5000,
    "difference": 312.5000,
    "rulesets_involved": ["kw-2024-03", "kw-2025-11"]
  },
  "sources": [
    { "type": "payroll_items", "id": null, "label": "Final settlement run #55020, indemnity line" },
    { "type": "statutory_rulesets", "id": null, "label": "kw-2024-03 and kw-2025-11 parameter sets" }
  ],
  "recommended_action": "Recalculate the indemnity line using the effective-dated blend before Submit, per Kuwait Labour Law No. 6/2010's End-of-Service Indemnity provisions and this platform's standing effective-dating rule for versioned statutory parameters.",
  "requires_approval": true
}
```

Yousef (Finance Manager) reviews both figures and their citations, confirms the effective-dated blend is correct (the flat-`kw-2025-11` figure was a genuine calculation-engine configuration gap, not an intentional company choice), and requests a recalculation before the run is submitted — logged with `override_reason: "corrected to effective-dated indemnity blend per legal review 2026-07-16"` once the corrected run reaches approval. No indemnity figure was ever paid incorrectly; the discrepancy was caught in `calculated` state, before Submit, exactly where this design intends every such discrepancy to surface.

# Metrics & Evaluation

| Metric | Definition | Target | Why it matters |
|---|---|---|---|
| Validation coverage | % of `calculated` runs that receive a completed AI validation pass before Submit | ≥ 99% | The 1% gap should be `skipped_ai_unavailable`, never a silent skip |
| Time-to-validate | Wall-clock time from `calculated` status to `payroll_ai_validation_runs.completed_at` | p50 < 60s, p95 < 5 min for a 200-employee run | A slow validation delays the Payroll Officer, incentivizing them to skip waiting for it |
| Cross-check exact-match rate | % of `payroll_calculation_crosscheck` decisions with zero residual difference vs. Laravel's posted figures | ≥ 99.5% | A persistently low match rate signals the shadow model's rule interpretation has drifted from the calculation engine's actual logic — a bug in the cross-check, not necessarily in Laravel |
| Anomaly flag precision | Of flags a human actively investigated, % that revealed a genuine issue (business-reason-worthy overtime, a real data error) rather than nothing | ≥ 70%, tracked per anomaly sub-type | Below this, Payroll Officers learn to dismiss flags reflexively, which defeats the entire guardrail |
| Anomaly flag recall (backtested) | Of confirmed post-hoc issues found by the Auditor's independent sampling, % that had already been flagged by the Payroll Manager before Submit | ≥ 90% | The Auditor's sampling (Collaboration) is precisely the backstop that measures this |
| Compliance-citation accuracy | % of compliance-adjacent flags whose cited rule/article was verified correct by the Compliance Agent's own rule store | 100% | A miscited rule is worse than no citation — it actively misleads a reviewing human |
| Escalation SLA | Time from a ≥ 90-confidence data-integrity flag to Auditor acknowledgment | < 4 business hours | This is the number that justifies bypassing the normal approval-chain notification path at all |
| Calibration | Of decisions emitted at confidence 90-95, % that a human later confirmed correct (accepted without material edit) | Within ±5 points of the stated confidence band | A model that is systematically over- or under-confident is more dangerous than one that is simply less accurate, because humans learn to (mis)trust the number itself |
| Human-override rate, by anomaly sub-type | % of flags of a given sub-type that a human overrides/dismisses, tracked over rolling 90 days | Trend, not threshold | A rising override rate on one specific sub-type signals the threshold needs recalibration for that company, not that Payroll Officers are careless — this metric feeds the learning loop in Future Improvements |
| Agent availability | % uptime of the FastAPI validation path, independent of Laravel's own uptime | ≥ 99.5% | Directly determines how often runs fall back to `skipped_ai_unavailable` |
| Numeric-integrity rejection rate | % of draft decisions rejected by the server-side numeric-consistency check (Reasoning & Prompt Strategy, rule 1) before ever reaching a human | Tracked, target trending toward 0% | A non-zero, non-trending-down rate signals a prompting or tool-reliability defect that needs engineering attention, not a "the model made a mistake" shrug |
| WPS readiness accuracy | % of runs where `preview_wps_export`'s `ready: true` verdict matched the actual bank file's acceptance on first submission | 100% | Any gap here means Finance discovers a bank-file rejection on disbursement day — exactly what the preview step exists to prevent |

# Failure Modes & Edge Cases

| Failure Mode / Edge Case | Handling |
|---|---|
| AI layer unreachable when a run reaches `calculated` | Laravel proceeds unaffected; the run is marked `payroll_ai_validation_runs.verdict = 'skipped_ai_unavailable'` and rendered plainly in the UI as not-yet-validated, never presented as clean. Submit is still allowed — the agent's absence removes a safety layer, it never blocks the human pipeline. |
| Malformed or partial attendance data for an employee (missing clock-outs, an incomplete week) | Confidence on that employee's specific validation line is explicitly lowered and the `reasoning` names exactly which records are missing, rather than the agent silently assuming a full day worked or guessing a figure. |
| A country/statutory ruleset the Compliance Agent has not yet modeled (e.g., a new Gulf market added before its rule set ships) | The agent never fabricates a compliance rule to fill the gap. It emits `payroll_compliance_precheck`-adjacent output stating plainly "no statutory ruleset found for `country_code`; manual review required," and that employee/run is excluded from the automated compliance verdict rather than silently passed. |
| A quoted monetary figure in a draft `reasoning` string cannot be matched to any tool-call result | Rejected before persistence by the server-side numeric-consistency check; logged as an `ai_logs` integrity failure and never shown to a human as if it were a normal decision — this is a defect to be fixed in the agent, not a judgment call to surface. |
| A statutory rate correction lands (Compliance Agent updates a ruleset) while a run is `calculated` but not yet approved | The affected run is automatically re-flagged `stale` in `payroll_ai_validation_runs.verdict` and a fresh validation pass is required before Submit remains available — the run cannot be submitted against a validation that was performed against a ruleset version that no longer exists. |
| Mass, simultaneous terminations (an economic layoff producing many `final_settlement` runs at once) | The anomaly detector is explicitly batch-context-aware: a spike in `final_settlement` run volume company-wide is evaluated once, at the batch level, rather than independently re-flagging "unusual termination volume" on every single one of forty simultaneous runs. |
| An employee's disbursement method is a cross-border wallet/remittance rather than a local KWD bank transfer | WPS-readiness logic checks `companies.wps_exempt`/the employee's actual `payment_method` rather than assuming WPS format-validation applies uniformly to every payment line in the run. |
| A rehire whose cumulative indemnity service treatment depends on company configuration | The agent reads `indemnity_service_reset_on_rehire` explicitly rather than assuming either a reset or an accumulation default — an assumption here is exactly the class of error Scenario 3 exists to catch in the general case. |
| A company deletes or materially edits a `salary_components` template mid-cycle, after a run was already validated against the prior version | The next validation pass detects the referential drift (the version the agent validated against no longer matches the currently active template) and re-flags the run `stale`, forcing a fresh pass rather than silently trusting a validation performed against data that has since changed. |
| Retried orchestration steps (a network blip mid-pass) | `trigger_calculation` and `submit_decision` calls are idempotency-keyed; a retried step does not double-trigger a recalculation or duplicate a decision row. |
| A confidence score would otherwise round to 100 | Hard-capped at 99.90 in the output-serialization layer — never surfaced as certain, structurally, regardless of how clean the underlying match is. |
| An employee record has genuinely conflicting attendance and leave data for the same day (e.g., a clock-in exists and an approved full-day leave request exists for the same date) | Flagged as a data-conflict anomaly with both source records cited; the agent does not silently pick one interpretation, since either a payroll underpayment or a double-pay is possible depending on which is correct. |
| The Compliance Agent's scan request times out or errors | The run's overall verdict is marked incomplete pending compliance input — never defaulted to "compliant" in the compliance scan's absence, since silence is not evidence of correctness. |

# Future Improvements

- **Country expansion as configuration, not rewrite.** Saudi GOSI, UAE WPS/MOHRE, and broader GCC rulesets slot into the same `get_statutory_ruleset(country_code)` tool contract the Kuwait ruleset already uses today; the agent's reasoning graph does not change, only the data it reads changes, consistent with the Payroll module's own country-strategy design.
- **Continuous payroll-readiness scoring**, not just at run time. Rather than only discovering at Calculate time that an employee is missing a bank account or a base-salary component, the `payroll_readiness_score` decision type (already reserved in the enum) would run continuously against `active`/`onboarding` employees, surfacing gaps to HR days before month-end rather than the morning of.
- **Opt-in, anonymized cross-company benchmarking.** Today, thresholds are learned strictly per company (Data Access & Tenant Scope). A future, explicitly opt-in benchmark pool could let a company compare its own overtime rate or payroll-cost-per-employee against an anonymized peer aggregate — never enabled by default, never crossing tenant boundaries without affirmative, revocable consent, and never exposing another company's raw figures, only aggregate statistics.
- **A natural-language query surface for Finance** ("why did the Warehouse department's payroll rise 12% this month?"), backed by exactly the same validated, cited data this document already specifies — no new data path, just a conversational front end over `ai_conversations`/`ai_messages` on top of existing tool calls.
- **An active-learning loop from confirmed overrides.** Scenario 2 and Scenario 3 both end in a human decision that either confirms or corrects the agent's flag; each such confirmed outcome is exactly the training signal the Memory doc (`docs/ai/memory/*`) describes as "approved corrections become memory" — recalibrating this company's own anomaly thresholds over time without ever touching another company's model behavior.
- **A payroll-cost what-if surface**, jointly with the Forecast Agent: "what would this run cost if we granted the proposed 2027 raise band now instead of in January," computed on the same validated component rules, surfaced as a suggest-only scenario rather than a committed change.
- **Bounded, non-financial nudges.** A low-noise reminder channel (in-app/mobile, never email-blast) that shortens the gap between "run is validated and safe to submit" and "a human actually opens it," reducing the human-review-latency component of the overall pay-cycle timeline without touching the approval chain itself.

# End of Document
