# Sprint 04 — AI Drafting & Reporting (MVP) — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: Sprint 04
---

# Purpose / Goal

Sprint 04 closes the MVP loop and makes QAYD demonstrably an AI-native accounting platform rather than a
double-entry system with an AI bolt-on. It builds the AI Service of
[../backend/AI_SERVICE.md](../backend/AI_SERVICE.md) — the Laravel-side boundary that is the *only* path
by which anything the AI produces becomes a fact in PostgreSQL — reusing the FastAPI transport stood up
in [SPRINT_03.md](./SPRINT_03.md). On top of it, the AI Accountant agent drafts journal entries from
uploaded documents through the register → extract → propose → human-approve flow, and QAYD assembles the
financial statements (Balance Sheet, Income Statement, Cash Flow) from the posted ledger, all surfaced
through an AI Command Center v1.

The governing rule of the whole sprint is one sentence from the AI Service spec: **the AI can reason,
retrieve, draft, and recommend, but it cannot write.** An agent that decides a journal entry should be
posted does not `INSERT` — it calls back through `POST /api/v1/ai/proposals`, which lands an
`origin='ai_draft'`, `status='draft'` entry that a human holding `accounting.journal.post` must dispose.
The `AutonomyResolver` forces every sensitive operation to `requires_approval` regardless of confidence,
and a database trigger rejects auto-posting an AI draft. This is enforced structurally, not by the
agent's good behavior.

The exit is the MVP made concrete: **an AI-drafted journal entry, approved by a human, and generated
financial statements** — a document uploaded, extracted, proposed with confidence and reasoning,
reviewed and posted by a person, and Balance Sheet / P&L / Cash Flow assembled from the resulting ledger
and validated against the accounting equation. At the end of this sprint QAYD is a demoable MVP.

# Sprint duration & team

- **Duration:** 2 weeks (10 working days). Sprint 04 of 04 — the MVP milestone.
- **Ceremonies:** planning (day 1), daily stand-up, `AutonomyResolver` governance review (day 3), MVP
  demo dry-run (day 9), MVP demo + retro + sprint-series retro (day 10).
- **Team (full squad; AI engineer at peak load):**

| Role | Count | Primary focus this sprint |
|---|---|---|
| Tech Lead / Eng Manager | 1 | Proposal-gateway governance, statement equation validation, MVP sign-off |
| Backend engineers (Laravel) | 2 | Proposal gateway, `ai_draft` intake, financial statements, exports |
| Frontend engineers (Next.js) | 2 | Statement screens, decision-review UI, AI Command Center + Copilot |
| AI engineer (FastAPI) | 1 | OCR/extract pipeline, Accountant Agent drafting, cost governance |
| Platform / DevOps | 1 (shared) | `ai`/`reports` queues, Supabase Storage exports, SSE relay, MVP demo env |
| QA / SDET | 1 | AutonomyResolver truth table, statement-equation tests, four-variant E2E |
| Product + Designer | 1 (part-time) | Command Center UX, confidence/sources presentation, MVP narrative |

- **Target velocity:** ~48 story points. Committed: 48 pts — a full but not over-committed final sprint,
  with the MVP demo dry-run on day 9 as a hard checkpoint.

# Objectives

1. **The governed write path from AI to ledger.** `POST /api/v1/ai/proposals` (the sole engine write
   path), `StoreAiProposalRequest` validating the payload through the same FormRequest + RBAC + Service
   pipeline a human traverses, the `AutonomyResolver` routing to execute / suggest / file-for-approval,
   and the `ai_decisions` status machine.
2. **AI-drafted journal entries from documents.** Register an uploaded document → OCR/extract fields →
   the Accountant Agent proposes a balanced journal entry with confidence, reasoning, and sources → it
   lands as `origin='ai_draft'`, `status='draft'` → a human reviews and posts. The AI never auto-posts.
3. **Financial statements from the posted ledger.** `FinancialStatementService` assembling the Balance
   Sheet, Income Statement, and Cash Flow as views over posted lines, validating `Assets = Liabilities +
   Equity` before presenting, and caching to `financial_statement_snapshots`
   ([../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md)).
4. **The AI Command Center v1.** The decisions queue (proposed / suggest_only / pending_approval), the
   agent catalogue merged with per-company settings, the pending-approvals surface, and a read/analyze
   Copilot chat over SSE — AI made visible and labeled, never silent.
5. **Governance that keeps a runaway engine from harming a tenant.** Per-company cost/rate governance
   (`AiCostGovernor`) and graceful degraded mode (AI-only → `503`, AI-optional → `200` with
   `meta.ai_suggestion: null`), so the ledger keeps working even when the intelligence is throttled.
6. **A demoable MVP.** The end-to-end AI-draft-to-approved-entry flow plus generated statements,
   asserted in all four visual variants, running against the demo environment.

# Backlog

Story points on the Fibonacci scale. Every AI-caused mutation carries `ai_assisted=true` +
`ai_confidence` into the audit trail; confidence is a `NUMERIC` string; sources are structured. Every
story ends in a demoable, tested increment.

## Epic A — AI proposal gateway & drafting

This epic is the mechanical heart of QAYD's AI promise. The proposal gateway is the *only* path by which
anything the AI produces becomes a fact in PostgreSQL, and it runs an agent's proposal through the exact
FormRequest → Policy/RBAC → Service pipeline a human clicking the same button would traverse — no
shortcut, no raw insert. The `AutonomyResolver` is a pure, side-effect-free function that forces every
sensitive operation to `requires_approval` regardless of confidence, and a database trigger independently
refuses to auto-post an `ai_draft`. A drafted journal entry lands as a draft a human must post; the AI
never disposes of what it proposes.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S4-01 | Proposal gateway + AutonomyResolver | AI | `POST /api/v1/ai/proposals` (`ai_agent` service scope), `StoreAiProposalRequest`, `AiProposalService` (re-establishes tenant scope, validates the payload as untrusted), the side-effect-free `AutonomyResolver` (`auto`/`suggest_only`/`requires_approval`), and the `ai_decisions` status machine + `ai_tasks`/`ai_logs` writes. | A sensitive op (`bank.transfer`) at 0.99 confidence resolves to `requires_approval` with an `approval_requests` row and no write; an in-ceiling reversible op resolves per the confidence/amount gate; a cross-tenant `account_id` in the payload returns `403 COMPANY_MISMATCH` + a `system` audit event; `AutonomyResolver` unit-tested across the full truth table, per-agent. | 8 | S3-07 |
| S4-02 | Document register → extract pipeline | AI | Document upload/register (Supabase Storage via the File Service), the FastAPI OCR/extract step producing per-field values with confidence, and the Accountant Agent composing a balanced `journal_entry.draft` proposal (reasoning + sources citing the attachment/bill/historical pattern). | An uploaded bill is registered, extracted, and turned into a proposal whose `proposed_action` is a balanced journal draft; per-field confidence below the bar is flagged, not guessed; sources resolve to the source document via signed URL; the engine writes nothing directly. | 8 | S4-01, S3-04 |
| S4-03 | ai_draft intake into Accounting | Accounting | The proposal gateway executing a `journal_entry.draft` through the Accounting Service so it lands as `origin='ai_draft'`, `status='draft'`, `created_by_agent`/`ai_confidence` set — never posted; a human holding `accounting.journal.post` posts it through the Sprint 02 engine. | An AI proposal creates a draft entry (not posted) via the owning Service (not a raw insert); the `trg_no_ai_autopost` trigger + `AutonomyResolver` both refuse an auto-post of an `ai_draft`; posting it later requires a human with `accounting.journal.post`; the posted entry carries `ai_assisted=true` in audit. | 5 | S4-01, S2-05 |
| S4-04 | Decision review UI | Frontend | The accept/reject surface for `suggest_only` and `draft` AI decisions: the proposed action, confidence, reasoning, and sources, with a human Accept (executes the `proposed_action`) / Reject (records an `ai_corrections` row) / Edit-and-accept. | A drafted entry shows its confidence, reasoning, and source document; Accept posts it (with the human as actor); Reject records a correction and posts nothing; edit-and-accept adjusts before posting; nothing commits without the human click; four-variant baseline. | 5 | S4-03 |

## Epic B — Financial statements

The statements are the payoff of the whole ledger: they turn posted lines into the reports a business
actually runs on. They are assembled as *views* over posted `ledger_entries`, never stored as independent
numbers that could drift, and the Balance Sheet validates `Assets = Liabilities + Equity` before it will
present — an out-of-balance assembly returns a diagnostic, never a silently wrong statement. This is the
same single-direction-of-derivation discipline from Sprint 02, now surfaced as the artifacts an owner,
CFO, or auditor reads.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S4-05 | Balance Sheet assembly + equation guard | Accounting | `FinancialStatementService::assemble` for the Balance Sheet as a view over posted `ledger_entries`, validating `Assets = Liabilities + Equity` before presenting; `financial_statement_snapshots` cache; `GET /accounting/statements/balance-sheet`. | The Balance Sheet assembles from posted lines with bilingual statement lines; an out-of-balance assembly returns a diagnostic (`422 statement_out_of_balance`), never a silently wrong statement; a snapshot caches; long generations return `202` on the `reports` queue; feature-tested. | 5 | S2-09 |
| S4-06 | Income Statement (P&L) | Accounting | The Income Statement assembly (revenue − expense over a period) as a view over posted lines; `GET /accounting/statements/income-statement`. | P&L assembles for a selected period from posted revenue/expense lines; ties to the ledger; net income flows into the Balance Sheet equity check; `accounting.report.read` gated; feature-tested. | 3 | S4-05 |
| S4-07 | Cash Flow Statement | Accounting | The Cash Flow Statement assembly; `GET /accounting/statements/cash-flow`. | Cash Flow assembles from posted lines for the period and reconciles to the movement in cash/bank control accounts; `accounting.report.read` gated; feature-tested. | 5 | S4-05, S3-03 |
| S4-08 | Statement screens + export | Frontend | The Balance Sheet, P&L, and Cash Flow screens under the accounting sub-nav, with a period selector and PDF/Excel export to Supabase Storage (signed URLs, `reports` queue). | Each statement renders bilingual with tying totals; the period selector re-fetches; export produces a downloadable PDF/Excel via a signed URL; an out-of-balance state surfaces the diagnostic; light/dark + RTL; four-variant baseline. | 5 | S4-05, S4-06, S4-07 |

## Epic C — AI Command Center v1

The Command Center is where the platform's "AI is visible, never silent" principle becomes a screen.
Every AI decision is queued, labeled, and traceable to its confidence, reasoning, and sources; a human
can see what each agent proposed and what still awaits approval. The v1 scope is deliberately a
*window* onto the governed pipeline plus a read-only Copilot — enough to make the AI legible and
trustworthy at the MVP, without the full fifteen-agent roster or autonomy-tuning UI.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S4-09 | AI Command Center screen v1 | Frontend | The AI home: the decisions queue (filter by status/agent/type), the agent catalogue merged with `ai_agent_settings`, and the pending-approvals surface; realtime updates on `private-company.{id}.ai`. | The queue lists AI decisions with status and confidence; the catalogue shows the agents and their per-company autonomy; a new proposal appears via Reverb without a refresh; every AI item is labeled and traceable to its reasoning/sources; four-variant baseline. | 8 | S4-04 |
| S4-10 | Copilot chat v1 (SSE) | AI | `POST /api/v1/ai/chat` streamed over SSE through `CopilotService` relaying the FastAPI stream; read/analyze scope only (`ai.chat`); the final turn persisted to `ai_messages` with citations; the browser never calls FastAPI directly. | A Copilot question streams tokens to the browser as they arrive; the agent's context is scoped to the asking user's own permissions (payroll never surfaces to a user without `payroll.read`); the final turn persists with citations; degraded mode returns `503 + Retry-After` when the engine is down. | 5 | S4-01 |

## Epic D — Governance & MVP exit

The last epic makes the AI safe to run unattended and proves the MVP end to end. Cost and rate governance
keep a runaway or unreachable engine from harming a tenant, and the degraded-mode split ensures the
ledger keeps working even when the intelligence is throttled — AI-optional features fall back first,
AI-only features last. The MVP E2E is the gate: the full document-to-approved-entry-to-statements journey,
asserted in all four visual variants, is what "demoable MVP" means in tests.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S4-11 | Cost/rate governance + degraded mode | AI | `AiCostGovernor` (per-company token/spend budget + Redis rate limit on `ai.*` classes), and the degraded-mode split: AI-only endpoints `503`, AI-optional endpoints `200` with `meta.ai_suggestion: null`. | Exceeding the budget returns `429 + Retry-After`; a near-budget company is warned before hard-limiting; with the engine unreachable, `/ai/chat` returns `503` while an AI-optional categorization still returns `200` and the underlying write succeeds; feature-tested. | 3 | S4-01 |
| S4-12 | MVP end-to-end + statements E2E | Testing | The four-variant Playwright journey: upload a document → AI drafts an entry → a human approves/posts it → generated statements reflect it; plus the AutonomyResolver + statement-equation regression suite as the MVP gate. | The E2E passes in all four variants (light/dark × LTR/RTL); an AI proposal for a sensitive op at 0.99 still requires approval (asserted); statements tie to the posted ledger; the MVP demo runs clean on the demo env; all blocking CI gates green. | 3 | S4-04, S4-08, S4-09 |

**Committed total: 48 points.** The full fifteen-agent roster, the CFO/CEO recommendation agents,
`ai_agent_settings` tuning UI (the endpoint exists and is approval-gated; the tuning *screen* is
post-MVP), the learning loop beyond `ai_corrections` capture, and the Statement of Changes in Equity are
**out of scope** for the MVP. Sprint 04 ships one document-drafting agent (Accountant), a read/analyze
Copilot, three core statements, and the Command Center that frames them.

# Sequencing & capacity

The sprint has two independent tracks that meet at the MVP E2E. The **AI track** (proposal gateway →
extract pipeline → `ai_draft` intake → review UI → Command Center → Copilot → governance) reuses the
transport proven in Sprint 03 and is the sprint's headline. The **statements track** (Balance Sheet →
P&L → Cash Flow → screens/export) is largely independent and built by the accounting-focused backend
engineer, so a slip in one track does not stall the other. Day 9 is a hard MVP demo dry-run checkpoint.

| Week | AI track | Statements track | Frontend / QA |
|---|---|---|---|
| Week 1 | S4-01 proposal gateway + AutonomyResolver (review day 3), S4-02 extract pipeline, S4-03 ai_draft intake | S4-05 Balance Sheet + equation guard, S4-06 P&L | S4-04 decision-review UI, AutonomyResolver truth-table tests |
| Week 2 | S4-10 Copilot (SSE), S4-11 cost governance + degraded mode | S4-07 Cash Flow, S4-08 statement screens + export | S4-09 Command Center v1, S4-12 MVP E2E (four variants), dry-run day 9 |

The critical path to the exit is **S4-01 → S4-02 → S4-03 → S4-04 → S4-12** on the AI side and
**S4-05 → S4-08 → S4-12** on the statements side; the two converge in the MVP E2E. Copilot (S4-10) and
the Command Center polish (S4-09) are the designated cut candidates if the dry-run reveals overrun —
the exit is drafting + statements, and both are protected ahead of Command Center breadth.

# Story spotlights

**S4-01 — Proposal gateway + AutonomyResolver.** When FastAPI calls back with a `proposed_action`,
`AiProposalService` re-establishes the tenant scope, treats the payload as *untrusted* (any `company_id`
or foreign key it names is validated against the header's company, and a mismatch is `403
COMPANY_MISMATCH` logged as a security event), and runs it through the identical write pipeline a human
would. `AutonomyResolver` then decides `auto` / `suggest_only` / `requires_approval` from a fixed
platform sensitive-op list no company config can shrink — confidence is irrelevant on that list. This is
the single most-covered class in the module, asserted per-agent so no agent gets a laxer path, and its
exhaustive truth table is a merge gate.

**S4-05 — Balance Sheet assembly + equation guard.** The statement is assembled as a view over posted
`ledger_entries` and validated against `Assets = Liabilities + Equity` *before* it is presented. The
behaviour that distinguishes a financial platform from a spreadsheet is here: an out-of-balance assembly
does not render a wrong statement — it returns a diagnostic (`422 statement_out_of_balance`) that names
the imbalance, so a user is never shown a number they might trust and act on that does not tie to the
ledger. Net income from the P&L (S4-06) flows into the equity side of this check, tying the three
statements together.

# Out of scope (deferred)

Explicitly *not* in the MVP, each a tracked post-MVP item rather than a gap:

- **The full fifteen-agent roster** — Sprint 04 ships one drafting agent (Accountant) and a read-only
  Copilot; the CFO/CEO recommendation, Auditor, Fraud, Tax, Payroll, and other agents of
  [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md) are post-MVP.
- **The autonomy-tuning UI** — `PATCH /ai/agents/{code}/settings` exists and is approval-gated, but the
  *screen* for tuning `ai_agent_settings` is deferred; the MVP runs on safe default thresholds.
- **The learning loop beyond capture** — `ai_corrections` are recorded on reject/edit-and-accept, but the
  loop that feeds them back into agent behavior is post-MVP.
- **The Statement of Changes in Equity and consolidated/multi-entity statements** — the three core
  statements (BS, P&L, CF) are the MVP set.
- **Autonomous (auto-executed) writes at scale** — the `auto` autonomy path exists and is tested, but MVP
  operation is deliberately human-in-the-loop for every material action; broad autonomy is a
  post-MVP tuning surface gated by `ai.settings.update`.
- **Write-capable Copilot actions** — the MVP Copilot is read/analyze only; letting a Copilot turn
  *initiate* a proposal is a fast-follow.

# Definition of Done

The [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md) checklist plus the
AI-governance bar from [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md):

- **AI never auto-commits a sensitive action.** `AutonomyResolver` is unit-tested exhaustively (every
  sensitive op forced to `requires_approval` regardless of confidence, every irreversible op likewise),
  per-agent, and a proposal-gateway feature test proves a `bank.transfer` at 0.99 still requires
  approval and moves no funds.
- **The AI only writes through the governed pipeline.** A feature test asserts an executed proposal was
  written by the owning Service (not a raw insert) and carries `ai_assisted=true` in `audit_logs`.
- **AI drafts land as drafts.** A test proves an `origin='ai_draft'` entry cannot be auto-posted (trigger
  + resolver) and requires a human with `accounting.journal.post`.
- **Statements never present a wrong number.** The Balance Sheet equation guard is tested; an
  out-of-balance assembly returns a diagnostic, not a statement; statements tie to the posted ledger.
- **Tenant isolation across the AI seam.** A cross-tenant proposal payload returns `403 COMPANY_MISMATCH`
  + a `system` audit event; a Copilot query never surfaces data the asking user cannot see.
- **Degraded mode + governance.** AI-only degrades to `503`, AI-optional to `200`/null; budget overage
  returns `429`; the ledger keeps working when the AI is throttled — all tested.
- **Frontend.** Every new screen has ≥1 Playwright happy-path spec and ≥1 Vitest spec; the MVP E2E passes
  in all four variants; confidence/reasoning/sources are visible and labeled; `i18n:check` green.
- **Reviewed + merged** behind all green blocking gates; the MVP demo dry-run (day 9) passed.

# Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A confidence score or an engine flag buys autonomy on a sensitive action, breaking the core AI promise. | Medium | Critical | `AutonomyResolver` is a pure, exhaustively-tested function with a fixed platform sensitive-op list no company config can shrink; day-3 governance review; the `trg_no_ai_autopost` trigger as an independent DB backstop. |
| The AI engine finds a path to write tenant data outside the proposal gateway. | Low | Critical | The engine holds no tenant DB credential (verified in S3-07); the gateway is the sole write path and runs the same FormRequest+RBAC+Service pipeline; payload treated as untrusted with FK/company validation. |
| A financial statement presents a wrong number (assembly bug, equity roll-up, cash reconciliation). | Medium | High | The accounting-equation guard blocks an out-of-balance Balance Sheet with a diagnostic; statements are views over posted lines (never independent stored numbers); tie-to-ledger tests. |
| OCR/extract quality is poor on real documents, producing low-value or wrong drafts. | High | Medium | Per-field confidence gating (flag, never guess); the human review step is mandatory — a wrong draft is caught before posting, not after; the MVP demo uses representative documents. |
| The final-sprint scope (AI drafting + three statements + Command Center + Copilot) overruns before the MVP demo. | Medium | High | Day-9 dry-run checkpoint; strict cut lines (Copilot is read-only; one drafting agent; SoCE deferred); Epic A + Epic B protect the exit and are prioritized over Epic C polish. |
| SSE relay / streaming instability makes the Copilot demo flaky. | Medium | Medium | Reuse the proven S3 transport; Copilot is non-blocking for the exit (the exit is drafting + statements); degraded mode returns a clean `503` rather than a hang. |
| Cost governance mis-tuned, throttling legitimate demo usage. | Low | Medium | Safe default budgets; warn-before-hard-limit; degrade AI-optional before AI-only so the ledger path never blocks. |

# Demo / exit criteria

The MVP is complete when this can be demonstrated live, end to end, against the demo environment, with
the governance and statement tests green in CI:

1. **Upload a document.** Register an uploaded bill/receipt; show it stored (Supabase Storage) and queued for
   extraction.
2. **Watch the AI draft an entry.** Show the Accountant Agent extracting the fields and proposing a
   balanced journal entry with a confidence score, plain-language reasoning, and sources citing the
   document — arriving in the AI Command Center via realtime, labeled as AI.
3. **Prove the AI cannot post.** Show the drafted entry sitting as `origin='ai_draft'`, `status='draft'`;
   show an attempt to auto-post it being refused; show a sensitive-op proposal at 0.99 confidence forced
   to `requires_approval`.
4. **A human approves and posts.** In the decision-review UI, a person reviews the draft, accepts it, and
   posts it through the Sprint 02 engine; show the posted entry carrying `ai_assisted=true` in the audit
   trail.
5. **Generate the statements.** Assemble the Balance Sheet, Income Statement, and Cash Flow from the
   posted ledger; show them tying out and the accounting-equation guard holding; export one to PDF via a
   signed URL.
6. **Show AI made visible.** Open the Command Center decisions queue and the agent catalogue; ask the
   Copilot a read-only question and watch it stream, scoped to the user's own permissions.
7. **Show it degrades safely.** Take the engine offline; show `/ai/chat` returning `503` while the ledger
   and statements keep working.
8. **Prove isolation and governance.** Show a cross-tenant proposal rejected (`403 COMPANY_MISMATCH`) and
   a budget overage returning `429`.

Meeting these criteria satisfies the sprint goal — **an AI-drafted entry approved by a human, and
generated statements** — and completes the MVP: a multi-tenant, bilingual, double-entry accounting
platform with a governed AI layer that proposes and never disposes, built end to end across
[SPRINT_01.md](./SPRINT_01.md) → [SPRINT_02.md](./SPRINT_02.md) → [SPRINT_03.md](./SPRINT_03.md) → this
sprint.

# Success metrics

Signals reviewed at the retro and the MVP milestone review, weighted toward AI governance and the
end-to-end demo:

| Metric | Target |
|---|---|
| Committed points delivered | ≥ 43 of 48 (≥ 90%); any carry-over is Copilot (S4-10) or Command Center polish, never drafting or statements |
| AutonomyResolver coverage | Exhaustive per-agent truth table green; every sensitive op → `requires_approval` regardless of confidence |
| AI writes only via the gateway | Proven: an executed proposal is written by the owning Service (not a raw insert) with `ai_assisted=true` in audit |
| ai_draft cannot auto-post | Proven at two layers (resolver + `trg_no_ai_autopost`); requires a human with `accounting.journal.post` |
| Statement correctness | Equation guard blocks an out-of-balance Balance Sheet with a diagnostic; statements tie to the posted ledger |
| Degraded mode | AI-only → `503`, AI-optional → `200`/null; the ledger keeps working when the engine is down |
| MVP E2E | The document → draft → human-post → statements journey passes in all four visual variants |
| Milestone acceptance | Product/stakeholders accept the MVP demo end to end on the demo environment |

A red mark on AutonomyResolver coverage, the gateway-only write path, or the ai_draft-no-auto-post metric
is milestone-blocking regardless of velocity: they are the mechanical enforcement of "AI proposes, human
disposes," the promise the whole product is sold on.

# Related Documents

- [SPRINT_01.md](./SPRINT_01.md), [SPRINT_02.md](./SPRINT_02.md), [SPRINT_03.md](./SPRINT_03.md) — the foundation, ledger, and AI transport this sprint completes.
- [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md) — the proposal gateway, `AutonomyResolver`, and human-in-the-loop boundary this sprint implements.
- [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md), [../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md) — the `ai_draft` intake and statement assembly.
- [../ai/agents/ACCOUNTANT_AGENT.md](../ai/agents/ACCOUNTANT_AGENT.md), [../ai/AI_COMMAND_CENTER.md](../ai/AI_COMMAND_CENTER.md), [../frontend/AI_COMMAND_CENTER.md](../frontend/AI_COMMAND_CENTER.md) — the drafting agent and Command Center.
- [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) — the AutonomyResolver truth-table and four-variant E2E bar.

# End of Document
