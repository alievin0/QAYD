# 03 — Product — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: PRODUCT
---

This chapter defines QAYD as a product: the map of modules it is made of, the people it is built for
and the jobs they hire it to do, the strategy that sequences what gets built, the experience that makes
it feel like one calm system rather than a pile of features, and the hierarchy that orders those
features from the non-negotiable core outward. It stays at PRD altitude — what the product is and why
it is shaped this way — and routes every capability to its governing specification through the map and
the footer rather than re-describing it. It inherits the identity fixed in
[01_VISION.md](./01_VISION.md) and the position fixed in [02_MARKET.md](./02_MARKET.md), and it aligns
with the capability map and personas in the anchor PRD ([../MASTER_PRD.md](../MASTER_PRD.md)).

The one sentence to hold throughout: QAYD is an AI Financial Operating System — a system of action in
which an AI workforce does the mechanical work continuously and a human approves the consequential. The
module map, the personas, the strategy, the experience, and the feature hierarchy are all expressions
of that one idea.

# The Module Map

QAYD is organised into independent modules that communicate through events and never reach into one
another's internals (see [../../foundation/MODULE_ARCHITECTURE.md](../../foundation/MODULE_ARCHITECTURE.md)).
Accounting is the correct core; the AI layer is cross-cutting and threads through every module; the
platform services underneath are shared. The map below is the product surface at PRD altitude — *what
each module is for* — with each pointing to its governing specification. Release phasing of these
capabilities is owned by [../MASTER_PRD.md](../MASTER_PRD.md) and [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md);
this map states composition, not schedule.

## Finance modules

| Module | What it is for | Governing spec |
|---|---|---|
| Accounting (the core) | Chart of accounts, journal entries with enforced double-entry balance, immutable posting, fiscal periods and close, trial balance and financial statements. | [../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md), [../../accounting/JOURNAL_ENTRIES.md](../../accounting/JOURNAL_ENTRIES.md), [../../accounting/CHART_OF_ACCOUNTS.md](../../accounting/CHART_OF_ACCOUNTS.md), [../../accounting/FINANCIAL_STATEMENTS.md](../../accounting/FINANCIAL_STATEMENTS.md) |
| Sales | Customers, invoices, receipts, AR aging, revenue recognition; invoice-to-ledger via events. | [../../accounting/SALES.md](../../accounting/SALES.md), [../../accounting/CUSTOMERS.md](../../accounting/CUSTOMERS.md) |
| Purchasing | Vendors, bills, purchase orders, vendor payments; bill capture via Document AI to draft entry. | [../../accounting/PURCHASING.md](../../accounting/PURCHASING.md), [../../accounting/VENDORS.md](../../accounting/VENDORS.md) |
| Banking | Bank accounts, transaction import then live feeds, AI reconciliation, human-approved transfers. | [../../accounting/BANKING.md](../../accounting/BANKING.md) |
| Inventory | Products, warehouses, stock movements and valuation, AI reorder proposals. | [../../accounting/INVENTORY.md](../../accounting/INVENTORY.md), [../../accounting/WAREHOUSES.md](../../accounting/WAREHOUSES.md) |
| Payroll | Employees, payroll runs, payslips, Kuwait PIFSS contribution and WPS file generation, human-approved release. | [../../accounting/PAYROLL.md](../../accounting/PAYROLL.md) |
| Tax | Tax codes and rates, input/output VAT tracking per transaction, GCC VAT return preparation, withholding, continuous tax-position monitoring. | [../../accounting/TAX.md](../../accounting/TAX.md) |
| Reports | Standard financial statements on demand, scheduled reports with AI narrative, management and custom reports, forecasts. | [../../accounting/REPORTS.md](../../accounting/REPORTS.md) |

## The AI layer (cross-cutting)

| Capability | What it is for | Governing spec |
|---|---|---|
| The AI workforce | Fifteen specialized agents (General Accountant, Auditor, Tax Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO, Fraud Detection, Reporting, Document AI, OCR, Forecast, Compliance, Approval Assistant, CEO Assistant). | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md), [../../ai/agents/](../../ai/agents/) |
| Document ingestion | Classify incoming documents, OCR-extract structured fields, route to the right agent to draft an entry. | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |
| Proposal lifecycle and confidence | Every AI output carries confidence, reasoning, and citations; auto below threshold, suggest above, block for sensitive acts. | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |
| Financial copilot | Natural-language questions answered with citations; cross-agent briefings. | [../../frontend/AI_COMMAND_CENTER.md](../../frontend/AI_COMMAND_CENTER.md), [../../ai/AI_COMMAND_CENTER.md](../../ai/AI_COMMAND_CENTER.md) |
| Company memory and learning loop | Per-company memory of accounts, policies, and corrections that sharpens the AI over time, never shared across customers. | [../../ai/memory/](../../ai/memory/) |
| Predictive finance | Cash-flow monitoring, forecasting, and simulation — what is about to happen, with lead time to act. | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |

## Platform services (shared, underneath every module)

| Service | What it is for | Governing spec |
|---|---|---|
| Auth and multi-tenancy | Sign-in, company switching, full per-company isolation. | [../../foundation/AUTHENTICATION.md](../../foundation/AUTHENTICATION.md), [../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md) |
| Permissions (RBAC) | Role- and permission-scoped access; the AI obeys the same rules as the user. | [../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md) |
| Workflow and approvals | Human-in-the-loop approval chains for every consequential act. | [../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md) |
| Audit log | Immutable, tamper-evident record of every action by any actor, human or AI. | [../../security/AUDIT_LOGS.md](../../security/AUDIT_LOGS.md) |
| Files, notifications, search, realtime | Document storage, in-app/email notifications, search, and live dashboard/AI-status updates. | [../../backend/FILE_SERVICE.md](../../backend/FILE_SERVICE.md), [../../backend/NOTIFICATION_SERVICE.md](../../backend/NOTIFICATION_SERVICE.md), [../../backend/SEARCH_SERVICE.md](../../backend/SEARCH_SERVICE.md) |

The map's shape is the product's thesis: a **correct ledger core**, an **AI workforce** that acts
across every module, **shared services** that make it safe and multi-tenant, and a **supervision layer**
(permissions, approvals, audit) that keeps a human in control of anything that matters.

# User Personas

QAYD serves a company, not a single user, and its permission model supports the full role catalog (see
[../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md)). Five personas shape the
product; each is defined by the job it hires QAYD to do and what "great" looks like for it.

| Persona | Context | Job-to-be-done | What "great" looks like | Primary surface |
|---|---|---|---|---|
| **Owner / Founder** (Layla) | Runs a 10–40 person Kuwaiti trading or services company; not an accountant; often the CEO and a buyer. | Know the financial truth of the business at a glance, and approve the few things that need her. | Opens QAYD, sees cash position and what needs approval, signs off in seconds, trusts the books are current. | Dashboard, approvals, the AI copilot, mobile. |
| **Finance Manager** (Khaled) | Owns the finance function in an SME; a buyer; answers to the owner/CFO. | Keep the books correct and month-end painless; supervise the AI; own compliance. | The close is a same-day sign-off, not a two-week scramble; exceptions are surfaced, not hunted. | Approval center, close-readiness view, reports, AI proposal review. |
| **Accountant / Bookkeeper** (Mariam) | Day-to-day entry and reconciliation, in-house or at a firm serving several clients; highest-frequency user. | Process documents and reconcile accounts accurately and fast; correct the AI where it is unsure. | She reviews and one-click-accepts AI drafts instead of typing them; low-confidence items come to her. | Journal/document review, bank reconciliation, the suggest-only AI queue. |
| **Auditor** (internal or external) | Reviews the books for correctness and control; the trust anchor for the AI's output. | Verify every number traces to a source and that controls held, over the full population. | Continuous, full-population control evidence and an immutable audit trail — not a sampled scramble. | Audit trail, AI reasoning/citations, read-only scoped access. |
| **CFO** (in-house or fractional) | Owns financial strategy and reporting in larger SMEs; consumes the AI's synthesis. | Understand where the business is heading and act with lead time; report with confidence. | Asks a question in natural language and gets a cited answer in seconds; is warned before problems arrive. | Copilot, business-health view, forecasts, management reports. |

The Owner and Finance Manager are the buyers; the Accountant is the highest-frequency daily user; the
Auditor is the trust anchor that makes AI output defensible; the CFO is the persona the predictive
layer serves most. A product that delights the Accountant but cannot satisfy the Auditor fails, and
vice versa — the personas are a system, not a list, and the product must serve all five without any one
of them being able to bypass the controls the others depend on. Full persona-to-capability
justification lives in [../MASTER_PRD.md](../MASTER_PRD.md).

# Product Strategy

QAYD's strategy is a sequence, and the order is the strategy. Four commitments define it.

**1. Win on a correct core before winning on intelligence.** The AI is only as trustworthy as the
ledger under it. QAYD builds the double-entry core first — balanced, immutable, auditable, KWD/GCC-correct
— because every AI proposal must resolve to a real, correct record or the whole value proposition
collapses. Intelligence is layered onto correctness, never in place of it.

**2. Prove the thesis on a focused slice, then widen.** The first release is not the whole module map;
it is the smallest slice that proves an AI workforce can keep a real company's books — Accounting plus
bill capture, Banking with AI reconciliation, and the core agents — in Arabic and English with human
approval and a same-day close. Once that thesis is proven, the suite widens to Sales, full Purchasing,
Payroll, Tax, reporting, and the copilot, then to prediction and full autonomy, then to enterprise
scale. The cut and sequencing are owned by [../MVP_SCOPE.md](../MVP_SCOPE.md) and
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md).

**3. Land Gulf-first, expand outward.** The beachhead is the Kuwaiti/GCC SME where the incumbents are
weakest — bilingual, GCC-tax, KWD precision, PIFSS/WPS — and where formalisation is forcing correct
books now (see [02_MARKET.md](./02_MARKET.md)). The architecture stays global so expansion never
requires a rebuild, but the go-to-market depth is deliberately regional first.

**4. Compound the moat through per-company learning.** Every human correction becomes a learning-loop
signal that improves that company's own extraction and coding over time, without ever crossing between
customers. The product gets sharper the longer a company uses it — a moat that deepens with usage and
that a feature-copying competitor cannot instantly match (see
[../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)).

The non-goals are as strategic as the goals: QAYD is not fully autonomous finance (the human never
leaves the money-moving loop), not a generic ERP chasing manufacturing MRP or deep CRM/HR, and not a
bring-your-own-ledger AI layer sitting on top of someone else's system of record. Owning the correct
core is the point.

# Product Experience

QAYD must feel like one calm, competent system, not a suite of modules stapled together. Five
experience commitments make that true.

**Two audiences, two tones — one product.** The owner and executive surfaces are reassuring and
high-signal: cash position, what needs attention, what to approve, answered in plain language. The
accountant and auditor surfaces are precise and dense: drafts to review, exceptions to resolve,
citations to trace. The product speaks both registers without fragmenting, because they operate on the
same records (see [../../foundation/DESIGN_SYSTEM.md](../../foundation/DESIGN_SYSTEM.md)).

**The dashboard answers three questions.** What happened, what needs my attention, and what should I do
next. Everything on the home surface earns its place against those three questions; anything that does
not is noise (see [../../frontend/screens/HOME_SCREEN.md](../../frontend/screens/HOME_SCREEN.md)).

**The AI is legible and controllable, never a black box.** Every AI proposal shows its confidence, its
reasoning, and its source documents; the human reviews, edits, accepts, or rejects; sensitive actions
route into an approval chain the AI cannot satisfy itself. The approve/reject surface is a first-class
part of the experience, not a settings page (see
[../../frontend/screens/APPROVAL_CENTER_SCREEN.md](../../frontend/screens/APPROVAL_CENTER_SCREEN.md) and
[../../frontend/screens/AI_HOME_SCREEN.md](../../frontend/screens/AI_HOME_SCREEN.md)).

**Bilingual and RTL as a native experience.** Arabic and English on every label and every generated
report, full right-to-left layout, dark mode from day one — not a language toggle bolted onto an
English product (see [../../frontend/README.md](../../frontend/README.md)).

**Fast, and available where the work is.** Instant-feeling pages, live updates as the AI works, and a
mobile experience for the approvals and questions an owner handles away from a desk. Speed is part of
trust: a finance tool people open every morning must never make them wait (see
[../../foundation/PRODUCT_PRINCIPLES.md](../../foundation/PRODUCT_PRINCIPLES.md)).

The felt result QAYD is designing for: a user opens it, sees that the mechanical work is already done,
reviews a short list of things that genuinely need a human, and closes it — every day, with confidence
that the books are current and correct.

# Feature Hierarchy

QAYD's features are ordered by dependency and by trust, from the layer nothing else can stand without,
outward to the layer that makes the product feel prescient. Each layer requires the ones beneath it;
none can be skipped to reach a higher one faster.

| Layer | What it is | Why it sits here | Routes to |
|---|---|---|---|
| **1. The correct ledger** | Double-entry core: accounts, balanced journal entries, immutable posting, periods, statements, KWD/GCC correctness. | The non-negotiable substrate; every AI proposal resolves to a record here, or it means nothing. | [../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md) |
| **2. The AI workforce** | Agents that ingest documents, draft entries, reconcile banks, track tax, prepare reports — continuously. | This is the product's reason to exist; it does the mechanical majority of the work. | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |
| **3. Supervision and trust** | Confidence and reasoning on every output, permissions, human approval chains, immutable audit trail. | What makes it safe to give the AI real authority; without it, autonomy is reckless. | [../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md), [../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md), [../../security/AUDIT_LOGS.md](../../security/AUDIT_LOGS.md) |
| **4. The full finance suite** | Sales, full Purchasing, Payroll (PIFSS/WPS), Tax returns, scheduled reporting, the copilot. | Widens QAYD from the ledger to the whole day-to-day finance function once the thesis is proven. | [../MASTER_PRD.md](../MASTER_PRD.md), [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md) |
| **5. Prediction and continuous control** | Forecasting, simulation, continuous cash/audit/tax monitoring, the full agent roster, automation. | The "works before you ask" promise; only credible on top of a correct, supervised, complete core. | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |
| **6. Scale and reach** | Multi-entity/consolidation depth, additional GCC jurisdictions, integration breadth, enterprise hardening. | Extends the proven platform from freelancer to enterprise without a rewrite. | [../../foundation/COMPANY_STRUCTURE.md](../../foundation/COMPANY_STRUCTURE.md), [../FUTURE_IDEAS.md](../FUTURE_IDEAS.md) |

The hierarchy is also a discipline against building the exciting layers too early. A forecasting
feature on an incorrect ledger is a confident lie; an autonomous action without an approval chain is a
liability; a full module suite without the AI workforce is just another system of record. QAYD builds
upward, in order, and each layer earns the next.

## Related Documents
- [../../foundation/MISSION.md](../../foundation/MISSION.md) — the mission the product embodies.
- [../../foundation/PRODUCT_PRINCIPLES.md](../../foundation/PRODUCT_PRINCIPLES.md) — the principles every feature and experience decision obeys.
- [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) — the AI workforce, agent roster, and proposal/confidence model behind layers 2, 3, and 5.
- [../MASTER_PRD.md](../MASTER_PRD.md) — the anchor PRD's capability map, personas, and phasing.
- [../MVP_SCOPE.md](../MVP_SCOPE.md), [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md), [../FUTURE_IDEAS.md](../FUTURE_IDEAS.md) — the strategy's sequencing, first cut, and parked scope.
- [../../foundation/MODULE_ARCHITECTURE.md](../../foundation/MODULE_ARCHITECTURE.md), [../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md) — module composition and the role catalog behind the personas.
- [../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md) and the [../../accounting/](../../accounting/) module specs — the finance modules in the map.
- [../../frontend/README.md](../../frontend/README.md), [../../frontend/screens/](../../frontend/screens/), [../../foundation/DESIGN_SYSTEM.md](../../foundation/DESIGN_SYSTEM.md) — the product experience surfaces.
- [01_VISION.md](./01_VISION.md), [02_MARKET.md](./02_MARKET.md) — sibling PRD chapters.

# End of Document
