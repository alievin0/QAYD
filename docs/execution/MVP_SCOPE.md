# MVP Scope — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: MVP_SCOPE
---

# Purpose

This document fixes the QAYD MVP: the smallest releasable slice of the product that proves the
thesis, and — just as importantly — everything left out of it on purpose. It is the decisive cut of
the capability map in [MASTER_PRD.md](./MASTER_PRD.md), and it is the contract the four MVP sprints
([SPRINT_01.md](./SPRINT_01.md) through [SPRINT_04.md](./SPRINT_04.md)) decompose and deliver
against. Where the PRD asks "what could QAYD do," this document answers "what must ship first, and
what must not."

The MVP is scoped to be **decisive, not exhaustive**. It is not a demo and it is not the whole
product; it is the first version a real Kuwaiti SME could keep its actual books on, end to end, with
the AI doing the mechanical work and a human approving the rest. Every inclusion earns its place by
being necessary to that claim; every exclusion is deferred because the claim survives without it.

# The MVP Thesis

QAYD's central bet (see [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md)) is that an AI workforce can
do the mechanical work of accounting continuously while a human supervises the exceptions, on a
correct double-entry ledger, in Arabic and English. The MVP proves exactly that bet and nothing
wider. Concretely, the MVP is provable if a finance manager or accountant at a GCC SME can:

> Connect their company, forward or upload a supplier bill, watch the AI read it and draft a
> correctly-coded, balanced journal entry with a confidence score and a citation to the scanned
> document, accept it with one click (or let it auto-post when it is confident and small), import a
> bank statement and watch the AI reconcile most of the lines automatically, review and approve the
> exceptions, and close the month as a same-day sign-off — then open a trial balance and financial
> statements that trace to source — all in Arabic or English, with every consequential action gated
> by a human approval and every step recorded in an immutable audit trail.

If that end-to-end loop works, the thesis is proven and the rest of the roadmap is elaboration. That
loop is therefore the definition of the MVP. It is deliberately built around the **purchase/bill →
ledger → bank → close → report** spine because that spine is where the mechanical burden is heaviest
and the AI value is most legible; sales, payroll, and tax filing are real but come after the spine is
proven (see **Deferred to Post-MVP**).

# The Smallest Lovable Slice

"Smallest lovable" means the MVP is minimal in breadth but complete in the one path it covers. It
includes the full vertical stack for that path — a correct backend ledger, the AI engine loop, the
human-approval surface, the bilingual web UI, and the audit trail — rather than a broad but shallow
skeleton across many modules. The MVP modules are:

- **Accounting core** — chart of accounts, journal entries with the enforced double-entry invariant,
  general ledger and posting, fiscal periods and human-approved close, trial balance, and core
  financial statements.
- **Purchasing (bills only)** — vendors and bills, so there is a real business document for the AI to
  ingest and turn into a journal entry. Full purchase orders and three-way match are deferred.
- **Banking** — bank accounts and statement-import transaction ingestion, so there is a real external
  source of truth to reconcile against. Live open-banking feeds, transfers, and payments are deferred.
- **AI core** — the FastAPI engine boundary, the MCP tool layer over `/api/v1`, Document AI + OCR
  ingestion, the General Accountant draft-and-suggest loop, the Treasury Manager reconciliation loop,
  the Auditor's basic continuous checks, and the `ai_tasks`/`ai_decisions`/proposal lifecycle with
  the confidence-and-reasoning contract.
- **Platform services** — auth and multi-tenant company switching, RBAC/permissions, the
  workflow/approval engine (the human-in-the-loop gate), the immutable audit log, file/attachment
  storage on Supabase Storage, and in-app/email notifications.

The MVP web client is bilingual Arabic/English with full RTL from the first screen; the mobile
(Flutter) client is out of MVP scope entirely.

# In-Scope vs Out-of-Scope, By Module

The table below is the authoritative MVP boundary. "In MVP" ships in the first release; "Deferred"
is real, specified, and scheduled for a later phase per [MASTER_PRD.md](./MASTER_PRD.md) and
[FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md) — it is not cut, only sequenced.

| Module | In MVP | Deferred (phase) |
|---|---|---|
| **Accounting** | Chart of accounts (templated, bilingual); journal entries with enforced double-entry balance; general ledger + posting; posted-record immutability; fiscal periods; human-approved period close; trial balance; P&L, balance sheet, cash flow (single company, single currency = KWD). | Multi-currency; cost centers/dimensions; multi-branch consolidation; recurring journals beyond AI accruals (P2). |
| **Purchasing** | Vendors; bills; bill capture via Document AI + OCR → AI-drafted journal entry. | Purchase orders; three-way match; vendor payments; purchase-to-pay workflow (P2). |
| **Banking** | Bank accounts; transaction import via statement upload; AI reconciliation (deterministic + fuzzy match); exception queue; human-confirmed reconciliation. | Live open-banking feeds; transfers; outbound payments; multi-account cash positioning (P2). |
| **Sales** | — (not in MVP). | Customers, invoices, receipts, order-to-cash, AR aging (P2). |
| **Inventory** | — (not in MVP). | Products, warehouses, stock movements, valuation, AI reorder (P2/P3). |
| **Payroll** | — (not in MVP). | Employees, payroll runs, payslips, PIFSS/WPS, human-approved release (P2). |
| **Tax** | Tax codes/rates; input/output VAT *tracked* per transaction so the ledger is VAT-consistent. | VAT return preparation and (human-approved) submission; withholding; continuous tax monitoring (P2/P3). |
| **Reports** | Trial balance and the three core statements, on demand, in Arabic/English. | Scheduled reports; AI narrative commentary; management/custom report builder; forecasts (P2/P3). |
| **AI layer** | FastAPI engine + MCP tools over `/api/v1`; Document AI + OCR; General Accountant draft loop; Treasury reconciliation loop; Auditor basic continuous checks; proposal lifecycle + confidence/reasoning/citation contract; per-tenant AI memory seeded empty. | Financial copilot chat; full 15-agent roster; forecasting/simulation; learning-loop retraining; automation engine (P2/P3). |
| **Identity / RBAC** | Auth (Sanctum/JWT); multi-tenant company switching; default roles (Owner, Finance Manager, Accountant, Auditor, Read Only); `<area>.<action>` permission enforcement. | Custom-role builder UI; SСIM/SSO; passkeys (P2/E). |
| **Workflow / approvals** | The human-in-the-loop approval engine for sensitive actions (period close, and AI proposals above threshold); approval routing and audit. | Multi-step/parallel approval chains; SLA escalation sweeps; delegation (P2). |
| **Audit** | Immutable, tamper-evident audit log written in-transaction for every state change and every AI action. | Advanced audit analytics/exports; external-auditor portal depth (P2/P3). |
| **Files** | Attachment upload to Supabase Storage; documents linked to bills and citations. | Full document center; bulk import/export tooling (P2). |
| **Notifications** | In-app and email (proposal awaiting approval, close-ready, exception raised). | SMS, WhatsApp, push (P2/P3). |
| **Search / Realtime** | Basic PostgreSQL search; polling for status (no live sockets required for MVP). | Meilisearch/Elasticsearch; Laravel Reverb live dashboard/AI status (P2). |
| **Mobile (Flutter)** | — (not in MVP). | Approvals, notifications, dashboard, AI assistant (P2). |

# The MVP User Journey

The MVP is validated against one continuous journey spanning the personas from
[MASTER_PRD.md](./MASTER_PRD.md). Each step names the acting persona, what happens, and the invariant
it must honor.

1. **Onboard (Owner + Finance Manager).** The owner creates the company; base currency is KWD, locale
   Arabic or English. A default bilingual chart of accounts is seeded; default roles are attached
   (Owner, Finance Manager, Accountant, Auditor, Read Only). AI memory starts empty — no cross-tenant
   carryover (see [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), boot sequence). *Invariant:
   tenant isolation is live from the first row.*

2. **Capture a bill (Accountant / system).** A supplier bill is uploaded or forwarded as a PDF/photo.
   Document AI classifies it as a bill; the OCR Agent extracts vendor, date, amount, line items, and
   tax, each with a per-field confidence; low-confidence fields are flagged, not guessed. *Invariant:
   the source document is stored in Supabase Storage and becomes a fetchable citation.*

3. **Draft the entry (General Accountant agent).** The agent retrieves the vendor's history from the
   company's own memory, drafts a correctly-coded, balanced journal entry, and emits the
   confidence-and-reasoning contract object (confidence, reasoning, `proposed_action`, resolvable
   `sources`). *Invariant: the AI proposes through `/api/v1`; it never writes the ledger directly.*

4. **Post or approve (system or Accountant).** Below the company's confidence-and-amount threshold the
   entry auto-posts; above it, it lands as a one-click suggestion the accountant accepts or edits.
   *Invariant: debits equal credits; a corrected pair suppresses future auto-posting for that
   vendor/account pair.*

5. **Import and reconcile the bank (Accountant + Treasury agent).** A bank statement is imported;
   Treasury Manager auto-matches lines deterministically then fuzzily, and routes the rest to an
   exception queue with a best-effort classification. The accountant clears exceptions and confirms
   the reconciliation. *Invariant: matching is observational; confirmation is a human checkpoint.*

6. **Close the period (Finance Manager / Owner).** The close-readiness checks run continuously; on
   period end the finance manager reviews the summary and approves the lock. *Invariant: period close
   is always human-approved — no agent can lock a period.*

7. **Report and audit (Owner + Auditor).** The owner opens the trial balance and the three
   statements; the auditor traces any figure to its journal entry, source document, and the AI
   reasoning that produced it, and reviews the immutable audit trail. *Invariant: every number is
   traceable; every AI and human action is recorded.*

This journey is the acceptance script. If a real company can complete all seven steps on its own
books, the MVP is done.

# MVP Success Criteria / Exit Criteria

The MVP exits to a pilot/GA decision only when all of the following hold. These are the done bar the
sprints are measured against; the pass/fail invariants are absolute.

## Functional exit criteria

1. A bill uploaded as a PDF or photo is classified, extracted, and turned into a balanced,
   correctly-coded draft journal entry carrying confidence, reasoning, and a resolvable citation.
2. High-confidence, small, previously-seen entries auto-post; everything else is a reviewable
   suggestion; a human edit suppresses future auto-posting for that pattern.
3. A bank statement import reconciles the majority of lines automatically, routes the rest to an
   exception queue, and reaches a human-confirmed reconciliation with zero variance.
4. A fiscal period can be reviewed and human-approved to `closed`, after which its entries are
   immutable and corrections flow through reversing entries in the next open period.
5. Trial balance and the three core financial statements render correctly in both Arabic (RTL) and
   English and trace to underlying journal lines.
6. All of the above works for at least two isolated companies used side by side by the same user,
   with correct company switching.

## Pass/fail invariants (a release violating any one is not shippable)

1. **Human-in-the-loop:** 100% of sensitive actions (period close, above-threshold AI proposals) pass
   a human approval chain the AI cannot satisfy itself.
2. **Tenant isolation:** zero cross-tenant reads or writes; a token for company A receives a 404 for
   company B's resources; AI agents see only the active company.
3. **AI boundary:** the FastAPI engine performs zero direct database writes; every AI effect is a
   permission-checked `/api/v1` call as the acting user.
4. **Double-entry integrity:** no unbalanced entry can post; no posted record can be mutated in place.
5. **Explainability:** 100% of AI proposals carry a confidence score, reasoning, and citations that
   resolve to concrete records.

## Non-functional exit criteria

6. Every MVP feature ships with its migration, API, permissions, audit logging, AI support (where
   applicable), documentation, and tests — the release checklist from
   [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md).
7. Tenant-isolation and permission (default-deny) feature tests pass for every MVP module, per
   [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md).
8. Performance floors are met on representative data: dashboard/report render within target and the
   AI draft loop returns within the interactive budget where the model allows
   ([../foundation/PRODUCT_PRINCIPLES.md](../foundation/PRODUCT_PRINCIPLES.md)).
9. The web client is fully bilingual with correct RTL on every MVP screen — no English-only surface.

# What Is Deliberately Deferred To Post-MVP

The following are intentionally excluded from the MVP. Each is real and specified; excluding it is a
sequencing decision, not a cut, and the reason is stated so the deferral is not relitigated every
sprint.

| Deferred | Why it is safe to defer | Returns in |
|---|---|---|
| Sales / invoicing (order-to-cash) | The AI thesis is proven on the purchase→ledger→bank spine; sales adds breadth, not new proof. | Phase 2 |
| Payroll (PIFSS/WPS) | High-value but self-contained; the ledger and approval spine must exist first to post and gate it. | Phase 2 |
| VAT return preparation and submission | VAT is *tracked* in MVP so books stay consistent; preparing/submitting returns is a compliance workflow that rides on the tracked data. | Phase 2 |
| Full purchasing (PO, three-way match) | Bills alone give the AI a document to ingest; PO matching is depth on top of a working bill loop. | Phase 2 |
| Inventory | Not on the critical proof path; adds a valuation/costing surface the MVP does not need. | Phase 2/3 |
| Financial copilot chat | The MVP proves AI *doing work*, not AI *answering questions*; the copilot is additive UX. | Phase 2 |
| Full 15-agent roster, forecasting, simulation, automation engine | The MVP proves the loop with a focused agent subset; predictive/autonomous depth is the Phase 3 theme. | Phase 3 |
| Live open-banking feeds, transfers, payments | Statement import proves reconciliation; live feeds and money movement add integration and money-movement risk best added after the core is trusted. | Phase 2/3 |
| Mobile (Flutter) app | Approvals and dashboards work on web for MVP; mobile is a second surface over the same API. | Phase 2 |
| Meilisearch/Elasticsearch, Reverb realtime | Postgres search and polling suffice at MVP scale; richer search and live sockets are performance/UX upgrades. | Phase 2 |

# Build Sequence Note

The MVP is planned as four two-week sprints against the core team in [README.md](./README.md), in the
order fixed by [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md): foundations and the ledger skeleton
first ([SPRINT_01.md](./SPRINT_01.md)), the live ledger and banking ingestion next
([SPRINT_02.md](./SPRINT_02.md)), the AI engine and draft loop third
([SPRINT_03.md](./SPRINT_03.md)), and reconciliation, approvals, reporting, and hardening last
([SPRINT_04.md](./SPRINT_04.md)). Nothing that depends on the ledger is scheduled before the ledger
exists; nothing AI-facing is scheduled before the API surface it calls exists.

# Related Documents

- [MASTER_PRD.md](./MASTER_PRD.md) — the full capability map this MVP cuts from.
- [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md), [SPRINT_01.md](./SPRINT_01.md)–[SPRINT_04.md](./SPRINT_04.md), [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md) — the sequencing that delivers this scope (planned).
- [README.md](./README.md) — how the execution documents relate; the team and cadence.
- [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md) — the AI loop, boot sequence, and confidence contract the MVP implements.
- [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md), [../accounting/GENERAL_LEDGER.md](../accounting/GENERAL_LEDGER.md) — the ledger and API invariants the MVP must honor.
- [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md) — the per-feature release checklist behind the non-functional exit criteria.

# End of Document
