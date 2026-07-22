# 13 — Product Roadmap — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: Roadmap
---

# Purpose

This chapter is the product roadmap for QAYD — the AI-native, multi-tenant, bilingual (English /
Arabic, full RTL) AI Financial Operating System for Kuwait and the wider GCC — told at PRD altitude.
It answers a founder's, an investor's, or a new team lead's question: *what does QAYD become, in what
generations, and why does each generation exist before the next?* It narrates the four product
versions (V1 through V4) and the horizon beyond them, and for each it states the theme, the modules
and features it delivers, the AI capabilities it adds, and the criteria that let it begin and declare
it done.

It is deliberately a narrative, not a spec. The authoritative, dependency-ordered delivery plan —
the per-phase module tables, the quarter-by-quarter calendar, and the tested exit gates — lives in
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md), and this chapter routes to it rather than restating
it. Where this document names a capability, its mechanics live in that capability's own module doc,
reached through the roadmap. The roadmap sequences the architecture the specification set already
fixed; this chapter tells the product story that sequencing serves.

# Versions and phases: how they map

QAYD's engineering plan is expressed in **delivery phases** (Phase 0 through Phase 4) in
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md). This chapter speaks in **product versions** (V1
through V4) — the generations a customer, an investor, or a go-to-market team perceives. The two are
the same programme viewed at two altitudes, and they map cleanly:

| Product version | Delivery phase(s) | One-line identity |
|---|---|---|
| **V1 — The Supervised Ledger** | Phase 0 (Foundations) + Phase 1 (MVP) | A GCC SME keeps a correct, bilingual, double-entry ledger and meets the first AI proposals. |
| **V2 — The Commercial Core** | Phase 2 | Order-to-cash and procure-to-pay with GCC tax, gated by a maker-checker workflow. |
| **V3 — Operations and Autonomy** | Phase 3 | Stock and payroll, plus scoped AI autonomy and continuous close. |
| **V4 — Scale** | Phase 4 | Mobile, partner ecosystem, integrations marketplace, multi-region, org-wide copilot. |
| **Future vision** | Beyond Phase 4 | The fifty-plus-module Financial OS and the predictive digital twin of the business. |

The mapping is intentional: V1 folds the two engineering phases that ship together into one product
generation, because a customer does not perceive "an isolated tenant with no features" (Phase 0) as a
product — they perceive the first version they can keep their books on. Everything below stays
subordinate to the phase definitions, entry criteria, and exit gates in
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md); the MVP cut it delivers is fixed in
[../MVP_SCOPE.md](../MVP_SCOPE.md).

# The roadmap's organizing principle

Three ideas govern the whole sequence, and none of them is ever traded for speed. They are stated
here because they explain *why the versions are ordered the way they are* — the detail is in
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md) and [../MODULE_DEPENDENCIES.md](../MODULE_DEPENDENCIES.md).

- **Dependency, not ambition, sets the order.** QAYD's central invariant is that Sales, Purchasing,
  Banking, Inventory, Payroll, and Tax never write a ledger line themselves; they emit events that the
  Accounting Service posts through one balanced, immutable code path. So the ledger must exist and be
  correct before any module that posts to it, and the tenant boundary must exist before any tenant
  data. Every version stands on foundations the previous one made trustworthy.
- **AI enters in layers of trust.** Every AI capability arrives first as *proposal-only* — a draft
  carrying a confidence score, its reasoning, and a human approve/reject affordance — and autonomy for
  a class of action widens only after that class has an accepted-proposal track record. No version
  moves an item off the fixed sensitive-operations list (bank transfer, payroll release, tax
  submission, permission and settings changes, deleting financial data).
- **The contract only grows.** Everything ships under `/api/v1` with a 12-month deprecation
  discipline, so a later version — or a third-party integration written against an earlier one — never
  suffers a silent breaking change.

# V1 — The Supervised Ledger

**Theme.** Make the platform *trustworthy before it is useful*, then make it *useful without
sacrificing trust*. V1 spans the two engineering phases that ship as one product generation: the
foundations that let a company sign in against a real tenant boundary and keep a correct ledger by
hand, and the MVP that turns that ledger into a product a real Kuwaiti SME could keep its actual books
on, end to end, with the AI doing the mechanical work and a human approving the rest.

**What V1 proves.** QAYD's central bet — that an AI workforce can do the mechanical work of accounting
continuously while a human supervises the exceptions, on a correct double-entry ledger, in Arabic and
English. Concretely, a finance manager can connect a company, forward a supplier bill, watch the AI
read it and draft a correctly-coded, balanced journal entry with a confidence score and a citation,
accept it in one click, import a bank statement and watch the AI reconcile most lines automatically,
clear the exceptions, close the month as a same-day sign-off, and open a trial balance and financial
statements that trace to source — all bilingual, every consequential action human-gated, every step
audited. The precise MVP thesis and its seven-step acceptance journey are fixed in
[../MVP_SCOPE.md](../MVP_SCOPE.md).

**Modules and features delivered.** Identity and multi-tenancy (RLS-enforced), Company and fiscal
setup, the Accounting core (chart of accounts, journal entries, the balanced immutable posting engine,
the general ledger and trial balance, fiscal periods and human-approved close, the core financial
statements), Banking (accounts and statement-import ingestion) and reconciliation, Reporting, and the
cross-cutting runtime a real product needs — the workflow/approval gate, the immutable audit trail,
files on Supabase Storage, and notifications. Purchasing is present as *bills only* so the AI has a real document to
ingest; Tax is *tracked* per transaction so the books stay VAT-consistent, without yet preparing
returns. The precise in-scope/out-of-scope boundary per module is the table in
[../MVP_SCOPE.md](../MVP_SCOPE.md).

**AI capabilities added.** The FastAPI engine boundary and the MCP tool layer over `/api/v1`;
Document AI plus OCR intake; the General Accountant draft-and-suggest loop; the Treasury Manager
reconciliation loop; the Auditor's basic continuous checks; and the proposal lifecycle with the
confidence-reasoning-citation contract, all strictly proposal-only, with the AI Command Center v1 as
the review surface. The AI never writes the database directly — every effect is a permission-checked
`/api/v1` call as the acting user.

**Entry criteria.** The architecture specification set accepted; the stack and monorepo provisioned;
the team onboarded to the ADRs. (Phase 0 entry, per [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).)

**Exit criteria.** V1 is done when a real company can complete the seven-step MVP journey on its own
books and every pass/fail invariant holds — human-in-the-loop on 100% of sensitive actions, zero
cross-tenant leakage, zero AI database writes, no unbalanced or mutated posting, and 100% explainable
AI proposals. The authoritative functional and non-functional exit criteria are in
[../MVP_SCOPE.md](../MVP_SCOPE.md); the tested phase gates (double-entry, idempotency, RLS negative
suite) are in [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md). V1 is decomposed into four two-week
sprints, [../SPRINT_01.md](../SPRINT_01.md) through [../SPRINT_04.md](../SPRINT_04.md).

# V2 — The Commercial Core

**Theme.** Give a trading company its two revenue-and-cost workflows — order-to-cash and
procure-to-pay — with GCC tax computed once, centrally, and the approval engine that gates the
documents that carry money.

**What V2 proves.** That new posting modules extend the platform without ever gaining a ledger-write
shortcut: an invoice priced by the central Tax subsystem, approved through the maker-checker workflow,
posts a balanced AR entry with output-tax lines through the same posting path V1 proved; a
three-way-matched bill posts AP, and a mismatch is blocked before posting. It also proves the platform
can add breadth (sales) on top of the purchase spine without re-proving the AI thesis.

**Modules and features delivered.** Sales (customers, invoices, receipts), Purchasing in full
(vendors, purchase orders, bills, three-way match, vendor payments), Tax as the single authoritative
calculation subsystem (jurisdiction tree, effective-dated codes and rates, VAT and withholding return
preparation, and human-approved submission), and the Workflow engine (approval chains, maker-checker,
SLA/escalation, and the AI-proposal commit gate). The detailed feature sets and references are the
Phase 2 table in [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).

**AI capabilities added.** Document AI/OCR extends to invoices and bills; the Sales and Purchasing
agents draft documents and flag duplicate vendor payments; the Tax and Compliance agents draft returns
and surface exposure — submission stays irreducibly human-approved. All remain proposal-only.

**Entry criteria.** V1 exit met; the banking event-posting pattern proven; the Workflow engine
contract fixed.

**Exit criteria.** Every sensitive document (invoice void, bill approval, tax submission) enforces
maker ≠ approver; a VAT return assembles from posted tax lines and reconciles to the ledger;
OCR-originated documents are provably proposal-only. The authoritative Phase 2 exit gate is in
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).

# V3 — Operations and Autonomy

**Theme.** Cover the operational modules a real business runs on — stock and payroll — and turn AI
from proposal-only into *scoped autonomy* for classes of action a company has explicitly pre-approved,
plus the continuous-close and risk agents that make QAYD a system of action rather than a system of
record.

**What V3 proves.** That autonomy can widen safely. For pre-approved low-risk classes (expense
categorization, standard bank matches), a proposal may auto-commit under policy — every auto-committed
action logged, reversible, and attributable — while the sensitive-operations list stays
un-bypassable, proven by an automated test. It also proves the operational modules post correctly by
event: a goods receipt posts GRNI; a released payroll run posts salary, deductions, and WPS liability.

**Modules and features delivered.** Inventory (products, warehouses, stock movements, valuation,
COGS/GRNI postings, low-stock alerts), Payroll with GCC/WPS specifics (employees, runs, payslips, the
WPS file, human-approved release), and the Automation engine (trigger → condition → action) that
invokes module Actions within guardrails. The Phase 3 detail is in
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).

**AI capabilities added.** The Inventory, Payroll, Auditor, Fraud, Forecast, and Treasury agents
(shortage prediction, payroll preparation, continuous audit, duplicate/fraud detection, cash-flow
forecasting); continuous/autonomous close so month-end becomes a sign-off; and the per-company
learning loop, where proposals improve from a tenant's own approved corrections — never shared model
weights, so one company's data never shapes another's behavior.

**Entry criteria.** V2 exit met; the approval engine and event-posting pattern proven across four
posting modules; a body of accepted proposals exists to justify widening autonomy.

**Exit criteria.** Scoped autonomy live for at least one pre-approved class with full attribution and
reversibility; inventory cannot go negative; payroll release enforces maker-checker; the nightly
ledger-integrity rebuild reproduces byte-identical statements across all posting modules. The
authoritative gate is the Phase 3 exit in [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).

# V4 — Scale

**Theme.** Take the complete single-region platform to scale: the Flutter mobile app, the partner
ecosystem, external integrations, and the multi-region posture that resilience and GCC data residency
demand.

**What V4 proves.** That the identical `/api/v1` contract serves every surface — a mobile client
performs the same permission-checked actions as the web client, with approvals and the AI assistant
working offline-tolerant; a partner integration runs against a scoped API key with a restricted
permission set and receives machine-readable deprecation headers; a regional failover is exercised
against the runbook within the stated RTO/RPO with residency honored.

**Modules and features delivered.** The Flutter mobile app (dashboard, notifications, approvals,
reports, AI assistant, offline queue replayed with idempotency keys), the Partner/Public API and
scoped API keys, the Integrations layer and a marketplace of connectors (banks, payment gateways,
government e-invoicing, ERP/POS), multi-region active-passive DR with residency-pinned regions, and
the search-tier upgrade. The Phase 4 detail is in [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).

**AI capabilities added.** The CFO and CEO agents — the Financial Copilot answering natural-language
questions with citations and surfacing answers before the question is asked; predictive finance and
scenario simulation (cash-shortfall and covenant-breach warnings with lead time); and
one-conversation orchestration, where the user never picks an agent and QAYD selects the best
combination behind a single conversation.

**Entry criteria.** V3 exit met; the full accounting/operations surface stable and load-tested in a
single region; the deployment topology proven.

**Exit criteria.** Mobile parity against `/api/v1`; a scoped-key partner integration with emitted
deprecation headers; a rehearsed regional failover within RTO/RPO and residency; at least one
predictive lead-time warning class in production, gated by the same approval rules as any action it
triggers. The authoritative gate is the Phase 4 exit in [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).

# The future vision — beyond V4

Past V4, QAYD's ambition is the one fixed in the foundation set: a Financial Operating System of
fifty-plus independent business modules — CRM, HR, budgeting, fixed assets, manufacturing, projects —
each owning its own tables and communicating only by event, so the platform grows in breadth without
ever bending the one posting path or the tenant boundary. The AI horizon is the predictive *digital
twin of the business*: a continuously updated model that simulates a decision's financial consequence
before it is taken, and an integration marketplace where third parties extend QAYD the way apps extend
a phone. None of this is committed, scheduled, or staffed; it is captured — with honest cost,
dependencies, and a concrete revisit-when trigger for each idea — in
[../FUTURE_IDEAS.md](../FUTURE_IDEAS.md), so good ideas are held without sneaking into committed
scope. The rule that lets the vision stay open-ended is the modular-monolith discipline: because every
module boundary is a real, tested seam, the platform can absorb a fiftieth module, or extract a hot
one into its own service, as a refactor along existing seams rather than a rewrite.

# Cross-version guarantees

Independent of which version is shipping, three properties hold across the whole roadmap and are the
reason a customer or integrator can trust QAYD's forward motion:

- **One posting path.** No version ever gives a module its own ledger-write shortcut; every new
  posting module emits an event the Accounting Service posts through the balanced, immutable engine
  built in V1.
- **AI proposes, human disposes.** No version shrinks the sensitive-operations list. Autonomy widens
  only within low-risk classes and only after accepted-proposal history justifies it.
- **The contract only grows.** Additive change ships continuously into `/api/v1`; a breaking change is
  a new major with a minimum 12-month sunset, so an integration written against an early version keeps
  working. The versioning and deprecation discipline is owned by the release process (see
  [14_DEVELOPMENT.md](./14_DEVELOPMENT.md) and [../../developer/RELEASE_PROCESS.md](../../developer/RELEASE_PROCESS.md)).

A version is never "done" on feature-completeness alone. Its exit gate is the relevant slice of the
seven-band test pyramid passing — most importantly the tenant-isolation/RLS security band and, for
posting modules, the double-entry and idempotency integration tests — because in this platform a
missed test is a wrong debit, not a cosmetic bug. How the development programme delivers and gates
these versions is the subject of [14_DEVELOPMENT.md](./14_DEVELOPMENT.md).

## Related Documents

- [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md) — the authoritative, dependency-ordered phase plan
  (per-phase module tables, quarter calendar, tested exit gates) this chapter narrates at PRD altitude.
- [../MVP_SCOPE.md](../MVP_SCOPE.md) — the V1 cut: in-scope/out-of-scope per module, the MVP journey,
  and exit criteria.
- [../MASTER_PRD.md](../MASTER_PRD.md) — the anchor PRD (problem, personas, capability map) the versions realize.
- [../MODULE_DEPENDENCIES.md](../MODULE_DEPENDENCIES.md) — the build-order constraint graph the version sequence obeys.
- [../SPRINT_01.md](../SPRINT_01.md), [../SPRINT_02.md](../SPRINT_02.md), [../SPRINT_03.md](../SPRINT_03.md), [../SPRINT_04.md](../SPRINT_04.md) — the four sprints that deliver V1.
- [../FUTURE_IDEAS.md](../FUTURE_IDEAS.md) — the parked post-V4 backlog with revisit-when triggers.
- [14_DEVELOPMENT.md](./14_DEVELOPMENT.md) — how the programme is built, tested, and released.
- [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md), [../../developer/RELEASE_PROCESS.md](../../developer/RELEASE_PROCESS.md) — the decisions and release discipline the guarantees rest on.

# End of Document
