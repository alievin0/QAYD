# Execution README & Build-Plan Index — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: README
---

# Purpose

This folder is the bridge between the QAYD specification set and a buildable plan. Everything
under `../foundation/`, `../backend/`, `../frontend/`, `../ai/`, `../database/`, `../accounting/`,
`../security/`, and `../api/` describes *what QAYD is and how each part must behave* — the fixed,
authoritative design. `docs/execution/` answers the next question: *in what order do we build it,
with what team, against what scope, and how do we know each increment is done.* It converts a
large, internally consistent design corpus into a sequenced, estimable, testable programme of work
without inventing a single product feature the specifications do not already define.

The execution documents are deliberately downstream of the specifications and never in conflict
with them. Where an execution document needs a fact about the system — the module boundaries, the
double-entry invariant, the AI human-in-the-loop rule, the multi-tenant isolation model, the
KWD/GCC tax context — it cites the governing specification rather than restating it. If an
execution document ever appears to contradict a foundation or module document, the specification
wins and the execution document is the bug. The specification set is the constitution; this folder
is the legislative calendar.

QAYD is an AI Financial Operating System — a multi-tenant, bilingual (Arabic/English, full RTL),
double-entry accounting platform for Kuwait and the wider GCC, built on a Laravel 12 backend (the
single source of truth), a FastAPI + Python AI engine that never writes the database directly, a
Next.js 15 web client, and a Flutter mobile client (see
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) and
[../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md)). This README indexes
the plan that turns that architecture into shipped software.

# What Belongs In This Folder, And What Does Not

This folder contains planning artifacts: the product requirements document, the phased feature
roadmap, the MVP scope contract, the sprint breakdowns, the module dependency graph, the record of
build-time technical decisions, the open-questions register, and the parked future-ideas backlog.

This folder does **not** contain design specifications (they live in the module folders above), API
contracts (see [../api/](../api/)), database schemas (see [../database/](../database/)), or per-agent
behavior (see [../ai/agents/](../ai/agents/)). It also does not contain project-management minutiae —
individual ticket text, standup notes, or burndown charts — which belong in the team's issue
tracker. An execution document names and sequences the work; the tracker records its day-to-day
state.

# How The Execution Documents Relate

The documents form a single dependency chain, read top to bottom. Each one narrows the one above it
from "everything we will ever build" down to "the exact work in front of us this fortnight."

```
                 ../foundation/  ../backend/  ../frontend/  ../ai/  ../database/
                        │  (the authoritative specification set — the "what")
                        ▼
              ┌───────────────────────────┐
              │      MASTER_PRD.md          │   the anchor: problem, personas, capability
              │  (why + who + what, whole)  │   map across all modules, KPIs, release phases
              └──────────────┬──────────────┘
                             ▼
              ┌───────────────────────────┐
              │    FEATURE_ROADMAP.md       │   phases the whole capability map across time
              │  (what, ordered by phase)   │   (MVP → Phase 2 → Phase 3 → enterprise)
              └──────────────┬──────────────┘
                             ▼
              ┌───────────────────────────┐
              │      MVP_SCOPE.md           │   the decisive cut: the smallest lovable slice,
              │  (the first releasable cut) │   in-scope vs out-of-scope per module, exit criteria
              └──────────────┬──────────────┘
                             ▼
              ┌───────────────────────────┐
              │  SPRINT_01 … SPRINT_04.md   │   MVP scope decomposed into two-week increments,
              │   (the fortnightly plan)    │   each with goals, stories, and a done bar
              └──────────────┬──────────────┘
                             ▼
              ┌───────────────────────────┐
              │  MODULE_DEPENDENCIES.md     │   the build-order constraint graph that the
              │   (what must precede what)  │   roadmap and sprints must both obey
              └───────────────────────────┘

   Cross-cutting, referenced by all of the above:
     TECH_DECISIONS.md   — build-time choices made within the fixed stack
     OPEN_QUESTIONS.md   — unresolved decisions blocking or shadowing the plan
     FUTURE_IDEAS.md     — explicitly parked scope, kept out of MVP on purpose
```

The reading order is also the authoring order. `MASTER_PRD.md` and `MVP_SCOPE.md` are authored first
(this revision) because nothing downstream can be written without them: a roadmap phases the PRD's
capability map, and a sprint decomposes the MVP scope. `MODULE_DEPENDENCIES.md` is the constraint
every sequencing decision above it must respect and is therefore cited by the roadmap and every
sprint even though it reads last.

# The Documents

The table below is the canonical index of `docs/execution/`. "Authored" documents exist in this
revision; "Planned" documents are named, scoped, and reserved here so the whole plan is visible at
once, and will be filled in on the cadence described under **Cadence** below. Every planned document
already has a fixed purpose so no one has to renegotiate what it is for.

| Document | Status | Purpose |
|---|---|---|
| [MASTER_PRD.md](./MASTER_PRD.md) | Authored | The anchor product requirements document: problem and market, vision and positioning, personas, goals and non-goals, the full capability map across every module, the AI thesis, success metrics, constraints, and high-level release phases. |
| [MVP_SCOPE.md](./MVP_SCOPE.md) | Authored | The MVP definition: the smallest lovable slice that proves the AI-native double-entry thesis, an explicit in-scope/out-of-scope table per module, the MVP user journey, exit criteria, and everything deliberately deferred. |
| [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md) | Planned | The capability map from the PRD placed on a timeline: MVP, Phase 2 (full accounting suite depth), Phase 3 (predictive + full autonomy), and enterprise. Each phase states its theme, entry criteria, and headline capabilities. |
| [SPRINT_01.md](./SPRINT_01.md) | Planned | Sprint 1 — foundations: tenancy, identity/RBAC, company/branch setup, the accounting core skeleton (chart of accounts, journal entries, double-entry invariant), and the CI/CD and test harness. |
| [SPRINT_02.md](./SPRINT_02.md) | Planned | Sprint 2 — the ledger comes alive: full journal posting and period model, banking accounts and transaction ingestion, and the first read models (trial balance). |
| [SPRINT_03.md](./SPRINT_03.md) | Planned | Sprint 3 — AI enters the loop: the FastAPI engine boundary, Document AI + OCR ingestion, the General Accountant draft-and-suggest loop, and the `ai_tasks`/`ai_decisions`/proposal lifecycle. |
| [SPRINT_04.md](./SPRINT_04.md) | Planned | Sprint 4 — reconcile and report: autonomous bank reconciliation, the approval/workflow engine for human-in-the-loop sign-off, core financial statements, and MVP hardening against the exit criteria. |
| [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md) | Planned | The directed build-order graph across all modules (which module's tables, events, and permissions must exist before another can be built), so the roadmap and sprints never schedule a dependent before its dependency. |
| [TECH_DECISIONS.md](./TECH_DECISIONS.md) | Planned | A running log of build-time decisions taken *within* the fixed stack (library choices, folder conventions, queue names, migration strategy), in lightweight ADR form. It never overrides [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md); it records the choices that stack leaves open. |
| [OPEN_QUESTIONS.md](./OPEN_QUESTIONS.md) | Planned | The register of unresolved product, regulatory, and technical questions that shadow the plan (e.g. Kuwait VAT timing, bank-feed provider selection, e-invoicing mandates), each with an owner and a needed-by date. |
| [FUTURE_IDEAS.md](./FUTURE_IDEAS.md) | Planned | The parking lot: scope deliberately kept out of the near-term plan (additional agents, extra GCC jurisdictions, marketplace integrations, digital-twin forecasting) so good ideas are captured without expanding committed work. |

# How To Use These Documents

Different readers enter this folder for different reasons; the shortest correct path for each is
below.

| If you are… | Start with | Then read | To answer |
|---|---|---|---|
| A founder, investor, or new team lead | [MASTER_PRD.md](./MASTER_PRD.md) | [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md) | What is QAYD, who is it for, and what ships when. |
| An engineer joining the build | [MVP_SCOPE.md](./MVP_SCOPE.md) | the current `SPRINT_0N.md`, then [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md) | What am I building now, and what must already exist for it. |
| A product manager grooming the backlog | [MVP_SCOPE.md](./MVP_SCOPE.md) | [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md) and [FUTURE_IDEAS.md](./FUTURE_IDEAS.md) | Is this request in MVP, in a later phase, or parked. |
| A designer or AI coding agent | [MASTER_PRD.md](./MASTER_PRD.md) | the relevant module spec under `../frontend/` or `../ai/` | The capability's intent, before the screen-level or agent-level detail. |
| A reviewer checking a claim of "done" | the current `SPRINT_0N.md` | [MVP_SCOPE.md](./MVP_SCOPE.md) exit criteria | Whether the increment meets its stated done bar. |

The rule for all readers: **execution documents point to specifications; they never replace them.**
When an execution document says "the AI never writes the ledger directly," it is citing
[../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md) and
[../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), not re-deciding it here.

# Team And Roles Assumption

The plan is sized for a small, senior, cross-functional core team — the composition QAYD assumes for
the MVP through Phase 2. The roadmap and sprint load are calibrated against these seats; if the real
team is smaller, timelines stretch rather than scope silently growing.

| Seat | Count (MVP) | Primary ownership in the plan |
|---|---|---|
| Backend engineer (Laravel 12 / PHP 8.4+) | 2 | The single-source-of-truth API, double-entry core, module boundaries, RBAC, workflow/approval engine. Owns the `../backend/` surface. |
| AI engineer (FastAPI / Python / LangGraph) | 1 | The AI engine, agent orchestration, MCP tool layer, OCR/Document AI integration, the `ai_tasks`/`ai_decisions` lifecycle. Owns the `../ai/` surface. |
| Frontend engineer (Next.js 15 / React 19 / TypeScript) | 1–2 | The web client, bilingual RTL, the AI approve/reject surfaces, dashboards. Owns the `../frontend/` surface. |
| Mobile engineer (Flutter) | 0 in MVP, 1 from Phase 2 | Approvals, notifications, dashboard, AI assistant on mobile. Deferred per [MVP_SCOPE.md](./MVP_SCOPE.md). |
| Product / accounting domain lead | 1 | Owns the PRD, MVP scope decisions, GCC/Kuwait accounting correctness, and [OPEN_QUESTIONS.md](./OPEN_QUESTIONS.md). Doubles as the human-in-the-loop domain reviewer. |
| Design (product + design system) | 1 (shared) | Owns the `../design-system/` and `../frontend/` design-language conformance. |

Quality is not a separate seat: per [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)
and [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md), every feature ships with
its migration, API, permissions, audit logging, AI support, documentation, and tests, and no feature
is "done" without them. Testing (PHPUnit, Pest, Playwright, Vitest) is owned by whoever writes the
feature.

# Cadence

The programme runs on a fixed rhythm so the planning documents stay live rather than decaying into
fiction.

| Rhythm | Activity | Documents touched |
|---|---|---|
| Every 2 weeks (sprint boundary) | Close the current sprint against its done bar; open the next. | `SPRINT_0N.md` (close/open), [OPEN_QUESTIONS.md](./OPEN_QUESTIONS.md) (resolve/raise) |
| Every 2 weeks (mid-sprint) | Groom the next sprint's stories against [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md). | next `SPRINT_0N.md`, [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md) |
| Per phase (roughly quarterly) | Re-baseline the roadmap; promote items from `FUTURE_IDEAS` if capacity allows. | [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md), [FUTURE_IDEAS.md](./FUTURE_IDEAS.md) |
| On any build-time decision | Record the choice and its rationale as a short ADR entry. | [TECH_DECISIONS.md](./TECH_DECISIONS.md) |
| On any scope change | Re-cut in-scope/out-of-scope; never let scope drift silently. | [MVP_SCOPE.md](./MVP_SCOPE.md), [MASTER_PRD.md](./MASTER_PRD.md) |

The MVP is planned as four two-week sprints (an eight-week core build) against the team above,
followed by a hardening and pilot window before general availability. The roadmap places the
subsequent phases; those dates are directional, not contractual, and are re-baselined per phase.

# Document Conventions

Every document in this folder follows the platform documentation house style: a metadata header
(`Version`, `Status`, `Module: Execution`, `Submodule`), authoritative prose with tables and
diagrams rather than bullet-dumps, relative links to sibling and specification documents, no emoji,
and a closing `# End of Document`. Statuses used across this folder are: **Planning** (design agreed,
not yet built), **In Build** (a sprint is actively delivering it), **Shipped** (in a released
version), and **Parked** (deliberately deferred, see [FUTURE_IDEAS.md](./FUTURE_IDEAS.md)).

# Related Documents

- [MASTER_PRD.md](./MASTER_PRD.md), [MVP_SCOPE.md](./MVP_SCOPE.md) — the authored anchors of this folder.
- [../foundation/MISSION.md](../foundation/MISSION.md), [../foundation/PRODUCT_PRINCIPLES.md](../foundation/PRODUCT_PRINCIPLES.md) — the mission and principles every execution decision serves.
- [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md), [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md), [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) — the architecture the plan builds toward.
- [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md), [../frontend/README.md](../frontend/README.md), [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md) — the three system surfaces the sprints deliver against.

# End of Document
