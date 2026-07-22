# Feature Roadmap — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: FEATURE_ROADMAP

---

# Purpose

This document is the phased delivery plan for QAYD, the AI Financial Operating System: a
multi-tenant, bilingual (English / Arabic, full RTL) double-entry accounting platform for
Kuwait and the wider GCC, built as a Laravel 12 modular monolith backend, a separate FastAPI
AI engine, a Next.js 15 web client, and a Flutter mobile app, on PostgreSQL with Row-Level
Security, Redis, and Laravel Reverb. It sequences *what gets built, in what order, and why*,
so that every phase ships a coherent, testable slice of the platform rather than a scatter of
half-modules.

The roadmap is derived from — and stays subordinate to — the architecture already fixed by the
specification set. The module inventory and the "every module owns its own tables, communicates
by events" rule come from [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md);
the six-system decomposition (web, mobile, AI engine, backend API, database, infrastructure)
from [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md); the stack from
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md); the twelve load-bearing decisions from
[../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md); and the build-order
constraints from the companion [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md). Where this
document names a capability, the authoritative mechanics live in that capability's own module doc,
linked inline. This roadmap invents no new architecture; it schedules the architecture that exists.

The organizing principle is dependency, not ambition. QAYD's central invariant — that Sales,
Purchasing, Banking, Inventory, Payroll, and Tax never write a ledger line themselves but emit
events the Accounting Service posts through one code path (see
[../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md)) — means the ledger must exist
and be correct before any module that posts to it. Likewise, the tenant boundary and the auth layer
must exist before any tenant data does. The phases below are therefore ordered so that each one
stands on foundations the previous one made trustworthy.

# Phasing Principles

Five rules govern how a feature is assigned to a phase.

1. **Foundations before features.** Nothing that writes tenant data ships before the tenant boundary
   (RLS, per [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)) and authentication (per
   [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md)) are proven. Nothing that posts to the
   ledger ships before the double-entry posting engine is proven.
2. **A module is "delivered" only when it is complete.** Per the MODULE_ARCHITECTURE release
   checklist, a feature is done only when it ships with its migration, API, permissions, audit
   logging, AI support, documentation, and tests. A phase does not claim a module it has only
   half-built.
3. **AI is added in layers of trust, never all at once.** Every AI capability enters as *proposal-only*
   (draft, confidence, reasoning, human approval) exactly as fixed by ADR-007 and
   [../foundation/AI_ARCHITECTURE.md](../foundation/AI_ARCHITECTURE.md); autonomy for a class of action
   is widened only after that class has a track record of accepted proposals.
4. **The test pyramid grows with the code.** Each phase owes tests in the bands the seven-band pyramid
   assigns (see [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) and ADR-012); a
   phase's exit criteria always include its security and tenant-isolation suite passing.
5. **The public contract is versioned from day one.** Everything ships under `/api/v1` with the
   deprecation discipline of [../api/API_VERSIONING.md](../api/API_VERSIONING.md), so later phases and
   third-party integrations never force a breaking change on earlier clients.

# Phase Overview

| Phase | Theme | Primary outcome | Modules delivered | AI posture |
|---|---|---|---|---|
| Phase 0 | Foundations | A tenant can sign in and keep a correct ledger | Identity/Auth, Company/Tenancy, Accounting core, Audit | None in product; AI engine scaffolded only |
| Phase 1 | MVP | A tenant can bank, reconcile, report, and see the first AI proposals | Banking, Reconciliation, Reporting, Notifications, Files, Search (PG), first AI agents | Proposal-only: categorization, bank matching |
| Phase 2 | Commercial core | A tenant runs order-to-cash and procure-to-pay with GCC tax | Sales, Purchasing, Tax, Workflow engine | Proposal-only agents for Sales, Purchasing, Tax; OCR |
| Phase 3 | Operations + autonomy | A tenant runs stock and payroll; AI closes the loop | Inventory, Payroll, Automation engine, deeper agents | Scoped autonomy for pre-approved low-risk classes; continuous close |
| Phase 4 | Scale | Multi-region, mobile, partner ecosystem | Mobile app, Partner API, Integrations, Marketplace | Predictive/simulation agents; org-wide copilot |

The calendar below is a planning baseline anchored to a Q3 2026 start; it expresses *sequence and
relative effort*, not a commitment. Slippage in an earlier phase pushes later phases rather than
overlapping them across a dependency boundary.

| Quarter | Phase | Headline deliverables |
|---|---|---|
| Q3 2026 | Phase 0 | Auth (Sanctum + RS256 JWT), tenancy + RLS, Company/Branch, Accounting core (COA, journals, posting, GL, trial balance), Audit trail, CI + seven-band test harness scaffold |
| Q4 2026 | Phase 1 | Banking (accounts, transactions, transfers), bank reconciliation, Reporting (financial statements + report runs), Notifications, Files (Supabase Storage), PostgreSQL search, Reverb realtime dashboard |
| Q1 2027 | Phase 1 | First AI agents in proposal mode (Accountant, Banking), AI proposal → approval flow, AI Command Center v1, hardening + first design-partner tenants |
| Q2 2027 | Phase 2 | Sales (customers, invoices, receipts), Workflow/approval engine, order-to-cash postings, Sales + OCR (Document AI) agents |
| Q3 2027 | Phase 2 | Purchasing (vendors, POs, bills, three-way match), Tax (GCC VAT, withholding, returns), Purchasing/Tax/Compliance agents, e-invoicing groundwork |
| Q4 2027 | Phase 3 | Inventory (products, warehouses, stock movements, valuation), Automation engine (trigger→condition→action), Inventory agent |
| Q1 2028 | Phase 3 | Payroll (employees, runs, payslips, WPS), scoped AI autonomy for pre-approved classes, continuous/autonomous close, Auditor/Fraud/Forecast agents |
| Q2 2028 | Phase 4 | Flutter mobile app (dashboard, approvals, AI assistant), Partner/Public API + API keys, first external integrations (banks, e-invoicing) |
| Q3 2028+ | Phase 4 | Multi-region (residency + DR), integration marketplace, predictive/simulation engines, CFO/CEO copilot across the org |

---

# Phase 0 — Foundations

**Theme.** Make the platform *trustworthy before it is useful*: a company can be created, users can
sign in against the correct tenant boundary, and a correct, immutable double-entry ledger can be
kept by hand. No module that posts to the ledger exists yet; this phase builds the thing they will
all post *through*.

**Goals.**

- Stand up the Laravel 12 modular monolith, the PostgreSQL schema with RLS, Redis, and the CI
  pipeline, so every later phase inherits a working spine.
- Prove tenant isolation to the standard of [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)
  and [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md): a token for Company A
  gets `404`, never Company B's rows, and an unset GUC returns zero rows.
- Deliver the Accounting core exactly as specified in
  [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md): chart of accounts, journal
  entries, the `PostingService` balance invariant, the general ledger projection, the trial balance,
  fiscal periods, and financial-statement assembly.

**Modules and features delivered.**

| Module | Feature set | Reference |
|---|---|---|
| Identity / Auth | Users, roles, permissions, `company_users`; Sanctum web sessions + RS256 JWT for headless clients; RBAC `<area>.<entity>.<action>` grammar, default-deny | [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md) |
| Company | Companies, branches, departments; company creation/onboarding; base currency (KWD) and fiscal calendar | [../foundation/COMPANY_STRUCTURE.md](../foundation/COMPANY_STRUCTURE.md) |
| Tenancy | `company_id` on every table; `ResolveTenantCompany` middleware; `CompanyScope`; `SET LOCAL app.current_company_id`; RLS `ENABLE`+`FORCE` on all tenant tables | [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) |
| Accounting core | COA + account types, journal entries + lines, posting engine, ledger projection, trial balance, fiscal periods/close, financial statements | [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md) |
| Audit (cross-cutting) | Immutable audit trail written inside the same transaction as the state change | [../backend/AUDIT_SERVICE.md](../backend/AUDIT_SERVICE.md) |
| Web shell | Next.js 15 app shell, login flow, company switcher, COA + manual journal-entry screens, trial balance | [../frontend/README.md](../frontend/README.md) |

**AI capabilities added.** None in the product surface. The FastAPI engine repository is scaffolded
and the AI-never-writes boundary (ADR-007, [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md)) is
established in code, but no agent is exposed to tenants. The `journal_entries.origin = 'ai_draft'`
enum value and the `trg_no_ai_autopost` trigger exist from the first accounting migration, so the
gate is in place before the first agent arrives.

**Entry criteria.** Architecture specification set accepted; stack and repositories provisioned;
team onboarded to the ADRs.

**Exit criteria.**

- A journal entry can only reach `posted` with debits equal to credits in an open period, enforced by
  `PostingService` and re-checked by the `chk_journal_entries_balanced` and trigger guards.
- The tenant-isolation and RLS negative suite passes on every PR (Security band, ADR-012).
- A manual month can be kept end-to-end: COA created, entries posted, trial balance balances, the four
  statements assemble and satisfy the accounting equation.
- CI runs the Unit, Integration, Contract, and Security bands green; the OpenAPI contract for
  `/api/v1/accounting` and `/api/v1/auth` is published.

---

# Phase 1 — MVP

**Theme.** Turn the correct ledger into a usable product: connect money-in/money-out through Banking,
let a user reconcile it, report on it, and meet the *first* AI proposals — categorization and bank
matching — under the proposal-and-approve discipline.

**Goals.**

- Deliver Banking and bank reconciliation as the first modules that *post through* the Accounting core
  by event, proving the event-driven posting pattern end-to-end.
- Deliver Reporting as a query-only reader of the ledger, plus the durable report-run artifact.
- Ship the cross-cutting runtime a real product needs: Notifications, Files (Supabase Storage), search over
  PostgreSQL, and the Reverb live dashboard.
- Introduce the first AI agents (Accountant, Banking) strictly in proposal mode, with the AI Command
  Center rendering confidence, reasoning, and an approve/reject affordance on every proposal.

**Modules and features delivered.**

| Module | Feature set | Reference |
|---|---|---|
| Banking | Bank accounts, bank transactions, transfers, receipts; `banking.payment.received` → Accounting posts Dr bank / Cr AR | [../backend/BANKING_SERVICE.md](../backend/BANKING_SERVICE.md) |
| Reconciliation | Statement import, match/unmatch, reconciliation close; the `ReconciliationService` | [../backend/BANKING_SERVICE.md](../backend/BANKING_SERVICE.md), [../ai/workflows/BANK_RECONCILIATION.md](../ai/workflows/BANK_RECONCILIATION.md) |
| Reporting | Balance Sheet, Income Statement, Cash Flow, Statement of Changes in Equity; report definitions and `report_runs`; PDF/Excel export to Supabase Storage | [../backend/REPORTING_SERVICE.md](../backend/REPORTING_SERVICE.md) |
| Notifications (cross-cutting) | Email/SMS/in-app; company- and user-scoped delivery | [../backend/NOTIFICATION_SERVICE.md](../backend/NOTIFICATION_SERVICE.md) |
| Files (cross-cutting) | Supabase Storage upload/download, signed URLs, attachment model | [../backend/FILE_SERVICE.md](../backend/FILE_SERVICE.md) |
| Search (cross-cutting) | PostgreSQL full-text search (Phase 1 search tier per TECH_STACK) | [../backend/SEARCH_SERVICE.md](../backend/SEARCH_SERVICE.md) |
| Realtime | Reverb private company channels; live dashboard and posted-document deltas | [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md) |

**AI capabilities added (proposal-only).**

- **Accountant Agent** ([../ai/agents/ACCOUNTANT_AGENT.md](../ai/agents/ACCOUNTANT_AGENT.md)) — drafts
  standard journal entries and categorizes transactions; every draft enters as `origin='ai_draft'`,
  `status='draft'` awaiting a human holding `accounting.journal.post`.
- **Banking Agent** ([../ai/agents/BANKING_AGENT.md](../ai/agents/BANKING_AGENT.md)) — proposes bank
  transaction matches during reconciliation.
- **AI Command Center v1** ([../frontend/AI_COMMAND_CENTER.md](../frontend/AI_COMMAND_CENTER.md)) —
  the surface where proposals are reviewed with confidence + reasoning; the engine authenticates as an
  ordinary bearer client (ADR-004) and is RLS-scoped like any caller (ADR-002).

**Entry criteria.** Phase 0 exit met; Accounting core stable; the internal API contract to the AI
engine ([../api/INTERNAL_API.md](../api/INTERNAL_API.md)) defined.

**Exit criteria.**

- A bank payment posts a balanced, idempotent journal via the event → listener → `PostJournalEntryAction`
  path, and a redelivered event never double-posts.
- A user can reconcile a bank account to zero variance and generate a reconciliation record.
- The four financial statements render for a real tenant with light/dark × LTR/RTL visual regression
  green (E2E band).
- AI proposals appear only as drafts; an automated test proves the AI service token cannot post,
  approve, reverse, close, or lock.
- First external design-partner tenants onboarded.

---

# Phase 2 — Commercial Core (Sales, Purchasing, Tax)

**Theme.** Give a trading company its two revenue-and-cost workflows — order-to-cash and
procure-to-pay — with GCC tax computed once, centrally, and the approval engine that gates the
documents that carry money.

**Goals.**

- Deliver Sales and Purchasing as full modules that post to the ledger by event and never touch
  `journal_lines` directly.
- Deliver Tax as the single authoritative calculation subsystem so no other module embeds
  jurisdiction logic, per [../accounting/TAX.md](../accounting/TAX.md).
- Deliver the Workflow engine so invoices, bills, reversals, and every `ai_draft` above threshold run
  a maker-checker approval chain ([../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md)).
- Add OCR/document intake so a photographed invoice becomes a proposed entry.

**Modules and features delivered.**

| Module | Feature set | Reference |
|---|---|---|
| Sales | Customers, invoices + items, receipts; `invoice.posted` → Accounting posts AR; `invoice.paid` | [../accounting/SALES.md](../accounting/SALES.md), [../accounting/CUSTOMERS.md](../accounting/CUSTOMERS.md) |
| Purchasing | Vendors, purchase orders, bills, three-way match, vendor payments; `bill.approved` → Accounting posts AP | [../accounting/PURCHASING.md](../accounting/PURCHASING.md), [../accounting/VENDORS.md](../accounting/VENDORS.md) |
| Tax | Jurisdiction tree, tax codes/rates (effective-dated), `POST /api/v1/tax/calculate`, VAT/withholding/corporate returns; `tax.return.submitted` → Accounting posts tax liability | [../backend/TAX_SERVICE.md](../backend/TAX_SERVICE.md), [../accounting/TAX.md](../accounting/TAX.md) |
| Workflow (cross-cutting) | Approval chains, maker-checker, SLA/escalation sweeps, AI-proposal commit gate | [../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md) |

**AI capabilities added (proposal-only + OCR).**

- **Document AI / OCR** ([../ai/tools/DOCUMENT_TOOLS.md](../ai/tools/DOCUMENT_TOOLS.md)) — extract
  invoice/bill fields from uploads via Google Document AI / Azure Document Intelligence, fallback
  Tesseract; output is a proposed document, never a posted one.
- **Sales Agent** and **Purchasing Agent** ([../ai/agents/SALES_AGENT.md](../ai/agents/SALES_AGENT.md),
  [../ai/agents/PURCHASING_AGENT.md](../ai/agents/PURCHASING_AGENT.md)) — draft invoices/bills and flag
  duplicate vendor payments.
- **Tax Agent** and **Compliance Agent** ([../ai/agents/TAX_AGENT.md](../ai/agents/TAX_AGENT.md),
  [../ai/agents/COMPLIANCE_AGENT.md](../ai/agents/COMPLIANCE_AGENT.md)) — draft returns and surface tax
  exposure; submission stays on the human-approval sensitive-operations list.

**Entry criteria.** Phase 1 exit met; Banking posting pattern proven; Workflow engine contract fixed.

**Exit criteria.**

- An invoice priced with `tax/calculate`, approved through the workflow engine, posts a balanced AR
  entry plus output-tax lines to the correct control accounts.
- A three-way-matched bill posts AP; a mismatch is blocked before posting.
- A VAT return assembles from posted tax lines and reconciles to the ledger.
- Every sensitive document (invoice void, bill approval, tax submission) enforces maker ≠ approver.
- OCR-originated documents are provably proposal-only; contract tests validate the shared
  `ai/proposals` schema.

---

# Phase 3 — Operations and Autonomy (Inventory, Payroll, Deeper AI)

**Theme.** Cover the operational modules a real business runs on — stock and payroll — and turn AI
from proposal-only into *scoped autonomy* for classes of action a company has explicitly pre-approved,
plus the continuous-close and risk agents that make the platform a "system of action."

**Goals.**

- Deliver Inventory with valuation, so goods movements post COGS/GRNI correctly by event.
- Deliver Payroll with GCC/WPS specifics, so a released run posts salary, deductions, and WPS liability.
- Deliver the Automation engine (trigger → condition → action) so time- and event-triggered rules can
  invoke module Actions within guardrails ([../backend/AUTOMATION_ENGINE.md](../backend/AUTOMATION_ENGINE.md)).
- Introduce *scoped autonomy*: for pre-approved low-risk classes (expense categorization, standard
  bank matches), the AI's proposal auto-commits under policy — never for the sensitive-operations list
  (bank transfer, payroll release, tax submission, permission/settings changes, deleting financial data).

**Modules and features delivered.**

| Module | Feature set | Reference |
|---|---|---|
| Inventory | Products, warehouses, stock movements, adjustments, valuation; `goods.received` → Accounting posts GRNI; `stock.low` alerts | [../backend/INVENTORY_SERVICE.md](../backend/INVENTORY_SERVICE.md), [../accounting/INVENTORY.md](../accounting/INVENTORY.md) |
| Payroll | Employees, payroll runs, payslips, WPS file; `payroll.released` → Accounting posts salary/deductions/liability | [../backend/PAYROLL_SERVICE.md](../backend/PAYROLL_SERVICE.md), [../accounting/PAYROLL.md](../accounting/PAYROLL.md) |
| Automation (cross-cutting) | Rule engine, run history, guardrails, reuse of module Actions | [../backend/AUTOMATION_ENGINE.md](../backend/AUTOMATION_ENGINE.md) |

**AI capabilities added.**

- **Inventory, Payroll, Auditor, Fraud, Forecast, Treasury agents**
  ([../ai/agents/INVENTORY_AGENT.md](../ai/agents/INVENTORY_AGENT.md),
  [../ai/agents/PAYROLL_AGENT.md](../ai/agents/PAYROLL_AGENT.md),
  [../ai/agents/AUDITOR_AGENT.md](../ai/agents/AUDITOR_AGENT.md),
  [../ai/agents/FRAUD_AGENT.md](../ai/agents/FRAUD_AGENT.md),
  [../ai/agents/TREASURY_AGENT.md](../ai/agents/TREASURY_AGENT.md)) — shortage prediction, payroll
  preparation, continuous audit, duplicate/fraud detection, cash-flow forecasting.
- **Continuous / Autonomous Close** and **Continuous Audit**
  ([../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), [../ai/workflows/MONTH_END_CLOSE.md](../ai/workflows/MONTH_END_CLOSE.md))
  — month-end becomes a sign-off, not a scramble.
- **Per-company learning loop** — proposals improve from *approved corrections* retrieved per tenant,
  never shared model weights, so one company's data never shapes another's behavior.

**Entry criteria.** Phase 2 exit met; approval engine and event-posting pattern proven across four
posting modules; a body of accepted proposals exists to justify widening autonomy.

**Exit criteria.**

- A goods receipt and a released payroll run each post balanced, idempotent journals by event.
- Inventory cannot go negative (`insufficient_stock` guard); payroll release is on the
  human-approval list and enforces maker-checker.
- Scoped autonomy is live for at least one pre-approved class, with every auto-committed action logged,
  reversible, and attributable; the sensitive-operations list is proven un-bypassable by an
  automated test.
- The nightly `ledger_entries` integrity rebuild reproduces byte-identical statements across all
  posting modules.

---

# Phase 4 — Scale (Multi-Region, Mobile, Integrations, Marketplace)

**Theme.** Take the complete, single-region platform to scale: the Flutter mobile app, the partner
ecosystem, external integrations, and the multi-region posture that resilience and GCC data residency
demand.

**Goals.**

- Ship the Flutter mobile app against the identical `/api/v1` contract — dashboard, approvals, reports,
  AI assistant — with no divergent "mobile API" ([../frontend/MOBILE_APP.md](../frontend/MOBILE_APP.md)).
- Open the Partner/Public API and API keys so accountants' tooling, bank connectors, and government
  e-invoicing gateways can integrate under the 12-month deprecation policy
  ([../api/PARTNER_API.md](../api/PARTNER_API.md), [../api/API_VERSIONING.md](../api/API_VERSIONING.md)).
- Deliver the Integration engine and a marketplace of connectors (banks, payment gateways, government
  APIs, ERP/POS).
- Move to multi-region for high availability, disaster recovery, and residency
  ([../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md)).

**Modules and features delivered.**

| Capability | Feature set | Reference |
|---|---|---|
| Mobile app | Flutter dashboard, notifications, approvals, reports, AI assistant; offline queue replays with `Idempotency-Key` | [../frontend/MOBILE_APP.md](../frontend/MOBILE_APP.md) |
| Partner / Public API | Scoped API keys (`qyd_live_…`/`qyd_test_…`), partner rate tier, SDK guidelines | [../api/PARTNER_API.md](../api/PARTNER_API.md), [../api/PUBLIC_API.md](../api/PUBLIC_API.md) |
| Integrations | Bank feeds, payment gateways, government e-invoicing, ERP/POS via the integration layer | [../frontend/INTEGRATIONS.md](../frontend/INTEGRATIONS.md) |
| Marketplace | Discoverable, installable connectors and extensions | [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md) |
| Multi-region | Active-passive DR standby, residency-pinned regions, failover runbook | [../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md) |
| Search tier upgrade | Meilisearch (Phase 2 search tier per TECH_STACK) | [../backend/SEARCH_SERVICE.md](../backend/SEARCH_SERVICE.md) |

**AI capabilities added.**

- **CFO and CEO agents** ([../ai/agents/CFO_AGENT.md](../ai/agents/CFO_AGENT.md),
  [../ai/agents/CEO_AGENT.md](../ai/agents/CEO_AGENT.md)) — the Financial Copilot answering natural-language
  questions with citations and surfacing answers before the question is asked.
- **Predictive Finance / Simulation Engine** ([../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md)) —
  cash-shortfall and covenant-breach warnings with lead time; scenario simulation.
- **One-conversation orchestration** — the user never picks an agent; QAYD selects the best combination
  behind a single conversation, per [../foundation/AI_ARCHITECTURE.md](../foundation/AI_ARCHITECTURE.md).

**Entry criteria.** Phase 3 exit met; the full accounting/operations surface stable and load-tested in
a single region; deployment topology of [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md) proven.

**Exit criteria.**

- The mobile app performs the same permission-checked actions as the web client against `/api/v1`, with
  approval and AI-assistant flows working offline-tolerant.
- A partner integration runs against a scoped API key with a restricted permission set (e.g. invoices +
  customers, no payroll), and the deprecation headers are emitted.
- A full regional failover is exercised against the runbook within the stated RTO/RPO, with residency
  constraints honored.
- Predictive agents deliver at least one lead-time warning class in production, with confidence and
  reasoning, gated by the same approval rules for any action they trigger.

---

# Sequencing and Cross-Phase Guarantees

The phases are strictly dependency-ordered; the reasoning is spelled out in
[MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md). Three guarantees hold across every phase and are
never traded away for speed:

- **One posting path.** No module ever gains its own ledger-write shortcut. Every phase's new posting
  module emits an event that the Accounting Service (Phase 0) posts through `PostingService`.
- **AI proposes, human disposes.** No phase moves an item off the sensitive-operations list. Autonomy
  widens only within the low-risk classes and only after accepted-proposal history justifies it.
- **The contract only grows.** Additive changes ship continuously into `/api/v1`; a breaking change is
  a new major with a 12-month sunset, so an integration written in Phase 4 against a Phase 1 endpoint
  keeps working.

A phase is not "done" on feature-completeness alone. Its exit gate is the relevant slice of the
seven-band pyramid (ADR-012) passing — most importantly the tenant-isolation/RLS security band and, for
posting modules, the double-entry and idempotency integration tests — because in this platform a missed
test is a wrong debit, not a cosmetic bug.

# Related Documents

- [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md) — the build-order dependency map this roadmap sequences against.
- [TECH_DECISIONS.md](./TECH_DECISIONS.md) — the consolidated technical decisions the phases assume.
- [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md), [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md), [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md), [../foundation/AI_ARCHITECTURE.md](../foundation/AI_ARCHITECTURE.md)
- [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md), [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md), [../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md), [../backend/AUTOMATION_ENGINE.md](../backend/AUTOMATION_ENGINE.md)
- [../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md), [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md), [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md), [../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md)
- [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md)

# End of Document
