# The AI Finance Operating System — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: AI_FINANCE_OS
---

# Vision

QAYD is not accounting software. QAYD is an **AI Financial Operating System** — a continuously running, reasoning substrate that operates a company's finances the way an operating system runs a computer: silently, beneath the surface the user actually looks at, scheduling work, arbitrating access to shared resources, and enforcing the invariants that keep the whole machine from corrupting itself. An operating system does not wait for the user to manually allocate memory or schedule every process; it does that continuously, on the user's behalf, within rules the user configured once. QAYD's AI layer does the same thing for money: it ingests documents, drafts journal entries, reconciles bank feeds, watches for fraud, tracks tax exposure, forecasts cash, and drafts the monthly close — continuously, on the company's behalf, within rules the company configured once.

This is a different category of product from the ERP systems that preceded it, and the difference is architectural, not cosmetic. A traditional accounting system — whether it is a spreadsheet, QuickBooks, or a full SAP S/4HANA implementation — is a **system of record**: a place where humans enter what already happened, so that other humans can later look it up. QAYD is a **system of action**: a place where an AI workforce continuously determines what should happen next, prepares it, and — within the exact permission and approval rules the company already lives by — makes it happen, with a human able to inspect, override, or halt any of it at any time. The ledger is still the single source of truth (see **Enterprise Architecture**); what has changed is who is allowed to write proposals against it, and how fast a correct proposal can become a posted fact.

The framing that governs every decision in this document is stated once here and never contradicted anywhere else in the QAYD codebase or documentation set: **the AI is not a chatbot. It is an autonomous finance workforce** — a team of fifteen specialized agents (see **The AI Finance Team**) that read documents, draft entries, close books, reconcile accounts, watch for risk, and brief executives, while a human supervises and approves the subset of actions that carry real financial, legal, or reputational consequence. A chatbot answers questions when asked. QAYD's AI workforce works when nobody is asking — it drafts the accrual before the accountant remembers it is month-end, it flags the duplicate vendor payment before the money leaves the account, it prepares next month's cash-flow shortfall warning three weeks before the shortfall would otherwise surprise the CFO. Traditional software waits for users. QAYD's AI works *before* the user asks.

## The Four Pillars

Everything in this document is an elaboration of four commitments. They recur, named explicitly, throughout every section that follows:

1. **Autonomous.** Routine, well-understood, low-risk financial work — categorizing an expense, matching a bank line, drafting a standard journal entry, generating a scheduled report — happens without a human initiating it, and in many cases without a human approving each instance individually, because the class of action was pre-approved by policy. Autonomy is always scoped, always logged, and always reversible in substance (see **Human Approval** and **Continuous Audit**).

2. **Continuous.** QAYD does not have a month-end close, a quarterly audit, or an annual tax review in the sense of a single high-stress event performed once and then forgotten. It has **Autonomous Closing**, **Continuous Audit**, and **Continuous Tax Review** — the same work, performed in small, constant increments, every day, so that the period-end event becomes a formality (a sign-off) rather than a scramble (a reconstruction).

3. **Predictive.** QAYD does not only report what already happened. **Predictive Finance**, the **Simulation Engine**, and **Continuous Cash Flow Monitoring** exist so that a company knows what is *about* to happen — a cash shortfall, a covenant breach, a tax filing deadline it is not ready for — with enough lead time to act.

4. **Supervised.** Every autonomous action is scoped by policy, permission, and — for the actions that matter most — a human approval chain that cannot be bypassed by the AI, by a prompt, or by a confident-sounding justification. **Human Approval** is the gate that makes the other three pillars safe to build.

## What Changes For the People Who Use It

For the accountant, the job changes from *typing in what happened* to *supervising an AI that already typed it in and is waiting for a decision on the 8% of entries it was not confident about*. For the CFO, the job changes from *requesting a report and waiting three days* to *asking a question in natural language and getting an answer, with citations, in seconds* — and, increasingly, from *asking* to *being told*, because the **Financial Copilot** and the **CEO Assistant** agent surface the answer to a question the CFO had not yet formed. For the owner of a small Kuwaiti trading company with no finance department at all, the job changes from *not having real books* to *having a fully reconciled, IFRS-consistent, GCC-VAT-ready set of books that an AI workforce maintains continuously*, reviewed at whatever cadence the owner chooses to review it.

None of this requires trusting the AI blindly. It requires the opposite: an architecture in which trust is never assumed, only earned per-action, per-company, and per-decision — through confidence scores, explicit reasoning, cited source documents, and a human approval chain that is structurally incapable of being skipped for the actions a company has designated as sensitive. The rest of this document specifies that architecture in full: why the ERP category could never have built it (**Why ERP Is Obsolete**), why it is possible now (**Why AI Changes Accounting**), what it is made of (**The Finance Operating System Concept**, **The AI Finance Team**, **Enterprise Architecture**), how it behaves (the eleven **Autonomous** and **Continuous** capability sections, the **Decision**, **Recommendation**, and **Simulation** engines), how it stays safe (**Human Approval**, **Continuous Audit**, **Continuous Compliance**), how it gets smarter without ever mixing one company's knowledge into another's (**Learning Loop**, **Company Memory**), and how it compares to the incumbents it is built to obsolete (the six **Comparison** sections).

# Why ERP Is Obsolete

"ERP is obsolete" is not a marketing claim in this document; it is an architectural diagnosis. Enterprise Resource Planning, as a software category, was designed in the 1970s–1990s to solve a specific problem: give a large organization one shared database instead of forty incompatible ones. SAP R/2, and later R/3, and Oracle Financials, and everything modeled after them, are extraordinary achievements of that era's problem — data unification across a sprawling organization, enforced by a rigid, table-driven, rules-based system that a small army of configuration consultants could adapt to a given company's chart of accounts and approval matrix. That problem is solved. It has been solved for two decades. The category has spent those two decades adding surface area — more modules, more localization packs, more reporting cubes, more RPA bots bolted on the side — without changing the load-bearing assumption underneath all of it: **a human enters what happened, and the system records it.**

That assumption is now the bottleneck, and it shows up as five concrete, well-documented failure modes in every ERP deployment QAYD's design team studied:

## The five failure modes

**1. The system is passive.** An ERP does not notice that a vendor invoice looks like a duplicate of one paid eleven days ago. It does not notice that a customer's payment pattern shifted from Net 30 to Net 65 over the last two quarters. It does not notice anything, because noticing requires a standing process that continuously looks at data and reasons about it — and an ERP's rules engine reasons only about the data entry it was given at the moment of entry (a validation rule, a three-way match), never about the corpus of history around it. QAYD's agents are built to notice first and be asked second.

**2. The close is a fire drill, not a habit.** Because postings accumulate all month without continuous review, "month-end close" in a traditional ERP shop is a five-to-fifteen-business-day scramble: chase down accruals nobody logged, reconcile bank statements nobody looked at since the 3rd, hunt for the intercompany mismatch that has existed since the 14th. The event is stressful precisely because the underlying work was never continuous. **Autonomous Closing** replaces the fire drill with a running, always-current close position, so that "closing the books" becomes clicking "confirm" on work that was already done.

**3. Reporting looks backward, slowly.** A trial balance, an aged-receivables report, a cash position — in a classic ERP these are point-in-time queries a human runs after the fact, often batch-generated overnight because the underlying schema was not built for real-time aggregate queries at scale. By the time a CFO reads a report, the numbers describe a company that no longer quite exists. **Autonomous Reporting**, **Continuous Cash Flow Monitoring**, and **Predictive Finance** exist to close that lag — down to real time for monitoring, and forward past the present for forecasting.

**4. Configuration requires an army.** Adapting an ERP's rigid workflow engine to a specific company's approval chain, chart of accounts, or regional tax rule is a professional-services problem, not a software problem — SAP and Oracle implementations routinely run into the seven- and eight-figure range and multi-year timelines precisely because a static rules engine cannot infer intent; every rule must be hand-configured by a consultant who read a requirements document. An agent that reads the company's actual historical transactions and asks clarifying questions when uncertain (see **Learning Loop**, **Company Memory**) configures itself continuously, at a fraction of the cost, and keeps adapting after go-live instead of freezing at the last change request.

**5. Automation is scripted, not reasoned.** The last decade's answer to ERP's passivity was RPA (robotic process automation) and rules-based "smart" workflows: deterministic scripts that click through a UI or apply an if-this-then-that rule. RPA breaks the moment a vendor changes their invoice template, because it has no model of *what an invoice is* — only a model of *where the total was on the last one it saw*. QAYD's Document AI and OCR Agent (see **The AI Finance Team**) reason over document structure and semantics, not screen coordinates, and their confidence score tells you honestly when a new template has defeated their pattern-matching, instead of silently posting a wrong number.

## The comparison, stated plainly

| Dimension | Classic ERP (SAP / Oracle / Dynamics-era architecture) | QAYD (AI Financial Operating System) |
|---|---|---|
| Primary actor | Human data-entry clerk / accountant | AI agent, human supervises |
| System's default state | Passive — waits for input | Active — continuously analyzing and proposing |
| Month-end close | Discrete, stressful event | Continuous position, close is a confirmation |
| Reporting latency | Hours to days (batch) | Seconds (live) to leading (forecast) |
| Configuration | Consultant-driven static rules | Agent-inferred, self-adapting, human-approved |
| Automation mechanism | Deterministic rules / RPA scripts | Reasoning agents with confidence + citations |
| Audit | Periodic, sampled | Continuous, full-population (see **Continuous Audit**) |
| Cost driver | Implementation services, customization | Model inference, orchestration, human review time |
| Failure mode when reality changes | Breaks silently or requires a change request | Confidence drops, flags for human review |

None of this means the ERP category built bad software for its era. It means the category's core abstraction — a shared, rule-validated database that humans populate — was the correct answer to the correct question thirty years ago, and it is the wrong abstraction for a world where an AI agent can read a bill, understand it, cross-reference it against eleven months of history, and draft a correctly coded, correctly balanced journal entry with a citation to the source document, faster and more consistently than the human it used to take. QAYD is built on the assumption that the correct question has changed from *"how do we get humans to enter data consistently"* to *"how do we get an AI workforce to act correctly, safely, and transparently, with humans supervising the exceptions."*

# Why AI Changes Accounting

Accounting has always been an information-processing discipline pretending to be a bookkeeping discipline. Double-entry itself — Luca Pacioli's 1494 formalization — is an error-correction protocol: if debits do not equal credits, something is wrong, full stop. For five centuries the *processing* of that information (reading a document, deciding which accounts it touches, recognizing a pattern across a year of transactions, drafting a footnote that explains a judgment call) required a human, because no machine could read an unstructured invoice, infer intent, and exercise judgment under uncertainty. Large language models, agentic orchestration, retrieval over a company's own history, and OCR pipelines that generalize across document layouts have, over the last few years, made that no longer true. This section states precisely what became possible, and grounds it in QAYD's actual, fixed architecture rather than in generic AI enthusiasm.

## Three shifts, precisely stated

**Shift 1 — From recording to understanding.** A pre-LLM accounting system can validate that a field is numeric, that a date falls within an open period, that a customer ID exists. It cannot read a scanned delivery note in Arabic, cross-reference it against a purchase order in English, notice the quantities disagree by 4%, and draft a note explaining the discrepancy with a citation to both documents. QAYD's **Document AI** and **OCR Agent** do exactly that, because they are built on models trained to understand unstructured content, not merely validate structured fields against a schema.

**Shift 2 — From periodic to continuous.** Every "period-end" process in classical accounting — close, reconciliation, audit sampling, tax review — is periodic because a human can only do one for-real crunch a month; running one every day would exhaust a finance team, and running one every document is not humanly possible at all. An agent has no such constraint: **Continuous Audit** does not sample 25 transactions out of 40,000 once a quarter, it evaluates control tests against the full population every day (see that section for the exact test suite). Continuous is not a faster version of periodic; it is a qualitatively different guarantee — an exception is caught in hours, not discovered in the next audit cycle.

**Shift 3 — From human-initiated to AI-initiated.** In every ERP, work exists because a human logged in and started it. In QAYD, the majority of AI work is triggered by an event — a bill arriving in an inbox, a bank feed syncing new transactions, a fiscal period crossing its close date, a vendor payment exceeding a historical baseline by more than a configured deviation — not by a human opening a screen. This is the specific, falsifiable meaning of "the AI works before the user asks": the trigger is a domain event (see **Enterprise Architecture**, **Autonomous Accounting**), not a user click.

## What did *not* change, and why the architecture is built around it

It is exactly as important to state what AI did not change, because QAYD's entire safety architecture is a direct consequence of these limits:

- **AI is not infallible, and accounting cannot tolerate silent errors.** A model can be confidently wrong. This is why every AI output in QAYD — with no exception — carries a **confidence score**, **explicit reasoning**, and **supporting documents/citations** (see the JSON contract in **Autonomous Accounting** and **Decision Engine**), and why confidence, not enthusiasm, determines whether an action executes automatically, is offered as a one-click suggestion, or is blocked pending a formal approval chain.
- **AI must not become an unaudited actor with database access.** QAYD's foundational, non-negotiable rule is that **the AI engine never writes to the database directly**. Every AI action — from a routine categorization to a multi-million-KWD wire transfer draft — is a *proposal* submitted through the same Laravel 12 API, the same `FormRequest` validation, and the same RBAC permission checks that a human user's action would go through. The AI does not have a backdoor; it has the *same front door* as every other actor in the system, and it can only knock as hard as its permission grant allows (see **Human Approval**).
- **Judgment calls still belong to humans until a policy says otherwise.** Bank transfers, payroll release, tax submission, deleting or voiding financial data, and permission changes always require a human approval chain, regardless of how confident the AI is. Confidence changes *how much friction* stands between a draft and an approver seeing it; it never removes the human from sensitive decisions (see **Human Approval**).
- **Multi-tenancy is not optional for a finance AI.** An AI that can pattern-match across companies to get smarter is an AI that can leak Company A's supplier pricing into Company B's negotiation. QAYD's AI memory, tasks, decisions, logs, and conversations are all tenant-scoped by `company_id` with the same enforcement discipline as every other table in the platform (see **Company Memory**, **Learning Loop**) — cross-tenant learning never happens on raw company data, full stop.

## The concrete stack that makes this possible

QAYD's AI layer is a **FastAPI + Python** service, architecturally separate from the Laravel 12 backend, orchestrating a **LangGraph-style** multi-agent graph: a supervisor node routes intent to specialist agent nodes, each of which reasons over retrieved context (structured queries against the company's own data, plus semantic retrieval from **Company Memory**) and calls **MCP (Model Context Protocol) tools** — typed function definitions that map one-to-one to Laravel `/api/v1/*` endpoints and their permission keys. The AI engine holds no ledger of its own; PostgreSQL, reached exclusively through Laravel, remains the single source of financial truth. Redis backs the AI's short-term working memory and task queue; Cloudflare R2 stores the source documents (scanned bills, bank statement PDFs, contracts) that every citation in this document ultimately points back to; Laravel Reverb pushes the resulting state changes to the Next.js frontend in real time. **Vector search** (over embeddings of prior transactions, company policies, and historical corrections) is what lets an agent retrieve "how has this company always coded this vendor" instead of relying on a generic, cross-company prior. This is the concrete, unglamorous plumbing that turns "AI changes accounting" from a slogan into a system that a Kuwaiti SME can actually run its books on.

# The Finance Operating System Concept

Calling QAYD an "operating system" is a precise architectural claim, not a metaphor of convenience. An operating system has five recognizable parts — a kernel that owns the machine's core resources and enforces protection boundaries, processes that run concurrently under the kernel's supervision, drivers that translate the kernel's abstractions into interactions with the outside world, a shell through which a user issues commands, and system calls — the one and only interface through which a process may ask the kernel to do anything privileged. QAYD maps onto this shape exactly, and the mapping is what lets fifteen autonomous agents and an arbitrary number of human users share one company's finances without either group ever being able to corrupt it.

## The mapping

| Operating-system concept | QAYD equivalent | What it guarantees |
|---|---|---|
| Kernel | **Laravel 12 backend + PostgreSQL** | Single source of truth; owns all business/financial logic; the only component with direct DB write access |
| Protection boundary / ring 0 vs ring 3 | **RBAC permission system** (`<area>.<action>` keys) | Neither a human user nor an AI agent can perform an action their role does not grant, regardless of confidence or urgency |
| Processes | **The fifteen AI agents** (see **The AI Finance Team**), each a LangGraph node | Concurrent, specialized units of work, scheduled by the orchestrator, isolated from each other's internal state |
| Process scheduler | **LangGraph supervisor / orchestrator** | Decides which agent handles which event or user intent; manages hand-offs and escalation |
| System calls | **`/api/v1/*` REST endpoints, invoked via MCP tools** | The *only* channel through which an agent (or a frontend) can request a privileged action; every call is validated and permission-checked identically regardless of caller |
| Drivers | **Integrations**: bank feeds, PIFSS/WPS payroll files, GCC tax authority formats, OCR/document ingestion, ElevenLabs-class notification/voice channels, Cloudflare R2 | Translate the kernel's internal representation of money into the formats the outside world (banks, regulators, employees) actually speaks |
| Shell | **Financial Copilot** (chat) + the Next.js dashboards | The surface through which a human issues a command or asks a question; it never bypasses system calls to touch the kernel directly |
| File system / persistent state | **PostgreSQL tenant tables** (`accounts`, `journal_entries`, `journal_lines`, `invoices`, `bills`, …) | Durable, ACID-consistent, audited state that survives every process restart |
| Virtual memory / per-process isolation | **`company_id` tenant scoping + Row Level Security** | One company's agents and data can never read or corrupt another company's state |
| Kernel logs / `dmesg` | **`ai_logs`, `audit_logs`** | An immutable, append-only record of every privileged action taken by any process (human or AI) |
| Interrupts | **Domain events** (`invoice.created`, `bank.synced`, `payroll.completed`, …) | Asynchronous signals that wake the relevant agent without polling |
| Init system / boot sequence | **`ai_agents` catalog + onboarding** | Defines which agents exist, their default autonomy, and how a new company's instance comes up already staffed |

## Why the analogy is load-bearing, not decorative

The operating-system framing forces three design disciplines that a looser "AI features bolted onto an app" framing would not:

**1. There is exactly one kernel, and everything privileged goes through it.** It would be architecturally easy — and it is exactly what most "AI-enhanced" ERP add-ons do — to let the AI service maintain its own cache of "what it thinks the ledger looks like" and write back to the database asynchronously when convenient. QAYD forbids this outright. The FastAPI AI engine has no database credentials to the tenant schema at all; it only holds a service-account bearer token scoped to the same `/api/v1/*` surface and the same permission keys a human session would have. This is the direct implementation of "the AI never writes to the database directly," and it is why an AI agent cannot, even in principle, post an unbalanced journal entry, backdate a transaction into a closed fiscal period, or read Company B's data while acting for Company A — the kernel's validation and RLS enforce those invariants independent of which process is asking.

**2. Processes are specialized, not monolithic.** A single, generalized "finance AI" that tries to be an accountant, an auditor, a tax advisor, and a treasurer at once produces exactly the failure mode a general-purpose chatbot produces: broad, shallow, unaccountable competence. QAYD instead runs fifteen agents (see **The AI Finance Team**), each with a narrow mandate, a defined tool/permission surface, and a named escalation partner — the same design discipline that makes a Unix system reliable (many small, well-defined processes coordinating through defined interfaces) rather than a single monolithic program trying to do everything.

**3. The shell is not a bypass.** The **Financial Copilot** feels conversational, but a conversation with it never grants extra privilege. Every action the copilot's underlying orchestrator takes on the user's behalf is the same system call, subject to the same permission check, that the user's own click in the Next.js UI would trigger. A user cannot "socially engineer" the copilot into transferring money it would not otherwise be authorized to transfer, because authorization is enforced at the kernel (Laravel), not inferred from the shell's (the LLM's) judgment of the conversation.

## The operating system's boot state: what exists before a single transaction is entered

When a new company is provisioned in QAYD, the AI Financial Operating System does not start empty. The **`ai_agents`** catalog (fifteen rows, platform-wide, described fully in **The AI Finance Team**) is already resolvable; every agent's default autonomy level is already assigned per the platform defaults in the table below, pending the company's own configuration in onboarding:

```
BOOT SEQUENCE — new company provisioning
──────────────────────────────────────────────────────────────────
1. companies row created (base_currency = 'KWD', locale = 'ar_KW' / 'en')
2. Default chart of accounts seeded from account_types templates
3. ai_agent_settings seeded per company from ai_agents platform defaults
4. ai_memory seeded with zero rows (empty — no cross-tenant carryover)
5. Default RBAC roles attached: Owner, CFO, Accountant, Read Only, ...
6. Onboarding wizard asks: approval thresholds, fiscal year start,
   VAT registration status, payroll scheme (PIFSS/WPS), bank feeds
7. First domain event possible: document.uploaded → Document AI wakes
──────────────────────────────────────────────────────────────────
```

This is the concrete, inspectable meaning of "the AI works continuously from day one": there is no cold-start period in which the software is inert software waiting to be taught what an invoice is. There is a cold-start period in which **Company Memory** is empty and confidence is therefore calibrated conservatively (more suggest-only, less auto) until the agents have enough of the company's own history to raise their own confidence — a ramp described precisely in **Learning Loop**.

# The AI Finance Team

QAYD ships fifteen named, specialized AI agents. This roster is canonical and platform-wide — every QAYD company gets the same fifteen agents, provisioned at onboarding, with the same names, the same default autonomy levels, and the same escalation graph. What differs per company is *configuration* (thresholds, enabled/disabled status, which human roles receive which agent's escalations) and *memory* (each agent's retrieved context is entirely company-specific — see **Company Memory**), never the roster itself. A company does not "install the CFO agent"; it inherits it, tuned to zero history, and watches it get sharper.

## Roster

| Agent | Category | Primary Mandate | Default Autonomy | Primary Tables | Escalates To |
|---|---|---|---|---|---|
| **General Accountant** | Core Accounting | Drafts and posts routine journal entries; categorizes expenses; maintains the day-to-day ledger | `auto` below threshold, `suggest_only` above | `journal_entries`, `journal_lines`, `accounts` | Auditor, CFO |
| **Auditor** | Assurance | Continuous control testing; reviews every posting class; flags anomalies | `suggest_only` (always) | `audit_logs`, `journal_entries` | CFO, Fraud Detection |
| **Tax Advisor** | Compliance | Computes VAT/WHT, prepares filings, tracks tax exposure | `suggest_only`; `requires_approval` for submission | `tax_transactions`, `tax_returns`, `tax_codes` | Compliance Agent, CFO |
| **Payroll Manager** | Payroll | Calculates payroll runs, prepares PIFSS/WPS files | `suggest_only`; `requires_approval` for release | `payroll_runs`, `payroll_items`, `payslips` | CFO, Approval Assistant |
| **Inventory Manager** | Operations | Values stock, proposes reorders, reviews adjustments | `auto` for valuation, `suggest_only` for adjustments | `inventory_items`, `stock_movements`, `stock_adjustments` | General Accountant |
| **Treasury Manager** | Treasury | Cash positioning, bank reconciliation, liquidity monitoring | `auto` for matching, `requires_approval` for transfers | `bank_accounts`, `bank_transactions`, `bank_reconciliations` | CFO, Approval Assistant |
| **CFO** | Executive | Synthesizes cross-agent signal into a business health view; ranks recommendations | `suggest_only` (advisory only) | reads across all modules | Human CFO / Owner |
| **Fraud Detection** | Assurance | Anomaly scoring, duplicate and ghost-vendor detection, velocity checks | `auto`-flag, `suggest_only` for action | `ai_decisions`, `vendor_payments`, `audit_logs` | Auditor, CFO, Compliance Agent |
| **Reporting Agent** | Reporting | Generates financial statements, custom reports, narrative commentary | `auto` for scheduled reports, `suggest_only` for ad hoc distribution | `report_definitions`, `report_schedules`, `report_runs` | CFO |
| **Document AI** | Ingestion | Classifies incoming documents; routes to the right downstream agent | `auto` (classification only) | `attachments` | OCR Agent, General Accountant |
| **OCR Agent** | Ingestion | Extracts structured fields from scans/photos; confidence-gated | `auto`, confidence-gated | `attachments` | Document AI, General Accountant |
| **Forecast Agent** | Predictive | Cash flow, revenue, and expense forecasting; scenario modeling | `suggest_only` | derived only, no direct writes | CFO, Treasury Manager |
| **Compliance Agent** | Compliance | Regulatory-change monitoring; maps changes to affected transactions | `suggest_only` | `tax_codes`, `tax_rates` | Tax Advisor, CFO |
| **Approval Assistant** | Governance | Routes, tracks, and escalates human approval chains; never approves itself | `auto` for routing/reminders only | `approval_requests` | Named human approvers |
| **CEO Assistant** | Executive | Top-level orchestrator/copilot; plain-language cross-agent briefings | `suggest_only` | `ai_conversations`, `ai_messages` | Human Owner / CEO |

## Agent profiles

**General Accountant** is the workhorse of the team and the highest-volume agent by task count. It watches every incoming bill, invoice, receipt, and bank line, and — once Document AI and the OCR Agent have extracted structured fields — drafts the corresponding journal entry: correct accounts, correct tax treatment, correct dimensions (cost center, project, department) where the source document implies them. Below a company-configured confidence-and-amount threshold, it posts automatically; above it, or when the source document is unlike anything in the company's history, it drafts and waits (see **Autonomous Accounting**). It never touches payroll, tax filing, or bank transfers — those belong to specialists with tighter mandates.

**Auditor** never posts anything. Its entire mandate is to look at what General Accountant (and every other posting agent) already did, continuously, and flag what looks wrong before a human ever would have noticed — a duplicate entry, an account that is not normally used for this vendor, a journal posted outside business hours from an unusual session. It is QAYD's implementation of **Continuous Audit** at the agent level, and its findings feed both the Fraud Detection agent and the human-facing audit trail.

**Tax Advisor** tracks every transaction that carries tax consequence — input VAT on a bill, output VAT on an invoice, withholding on a cross-border payment — and maintains a running position of what the company owes and what it can reclaim. It drafts tax returns ahead of the filing deadline and computes exposure under GCC VAT frameworks (live in Saudi Arabia, the UAE, Bahrain, and Oman; pending in Kuwait and Qatar), but it can never submit a return itself — submission is a `requires_approval` action, always (see **Continuous Tax Review**, **Human Approval**).

**Payroll Manager** calculates each payroll run from `employees`, `salary_components`, and attendance/leave inputs, drafts `payslips`, and prepares the PIFSS contribution file and WPS salary information file Kuwait's regulator expects. Calculation is `suggest_only`; the moment a payroll run would actually disburse funds, it is gated behind a `requires_approval` chain that by platform rule can never be satisfied by the AI itself (see **Human Approval**).

**Inventory Manager** keeps `inventory_items` valued correctly under the company's chosen costing method, proposes reorder points from consumption velocity, and reviews `stock_adjustments` for plausibility before they reach a human. It hands off any adjustment that changes the balance sheet materially to General Accountant for journal posting — inventory valuation and inventory *accounting* are deliberately two different tables owned by two different agents in collaboration, matching the platform's module-ownership rule that no table is duplicated across module docs.

**Treasury Manager** ingests bank feeds (`bank_transactions`), runs the matching algorithm described in **Autonomous Reconciliation** against `journal_lines`, and maintains a live cash position across every `bank_accounts` row the company holds. It can auto-match a transaction with high confidence; it can never itself move money — every `transfers` record above a company's configured value or the platform default (see **Human Approval**) requires a human-approved chain regardless of Treasury Manager's confidence.

**CFO** (the agent) does not own a table of its own; it is a synthesis layer that reads the outputs of every other agent — Forecast Agent's projections, Fraud Detection's risk flags, Auditor's control findings, Reporting Agent's statements — and produces the **Business Health Monitoring** view and the ranked output of the **Recommendation Engine**. It is explicitly advisory: it can tell a human CFO what it would prioritize, and why, with full reasoning; it cannot execute anything on its own initiative that another specialist agent has not already drafted and routed through the proper autonomy gate.

**Fraud Detection** runs anomaly scoring continuously over `vendor_payments`, `journal_entries`, and vendor master data — duplicate-invoice detection, ghost-vendor pattern matching, velocity checks (see **Continuous Risk Detection**). It can auto-flag (raise a risk score visible to the Auditor and CFO agents) without any approval gate, because flagging is observational; it can never itself block, reverse, or execute a transaction — that authority belongs to a human with the right permission, acting on the flag.

**Reporting Agent** owns `report_definitions`, `report_schedules`, and `report_runs`. It generates standard financial statements on schedule (fully `auto`, since a generated-but-undistributed report changes nothing) and drafts narrative commentary explaining period-over-period movements; distributing a report outside the company (to an external auditor, a bank, an investor) is `suggest_only`, requiring a human to confirm the recipient.

**Document AI** is the front door for unstructured input: every attachment uploaded to QAYD — a photographed receipt, an emailed PDF invoice, a scanned bank statement — passes through Document AI first, which classifies its type and routes it (to the OCR Agent for extraction, then onward to General Accountant, Treasury Manager, or Payroll Manager depending on content). Classification alone is low-risk and fully `auto`.

**OCR Agent** performs the actual field extraction — vendor name, amount, date, line items, tax breakdown — from the pixels Document AI classified. Its output always carries a per-field confidence score; low-confidence fields are surfaced to a human for correction rather than guessed, and every correction becomes a **Learning Loop** signal that improves extraction on that company's own document templates over time.

**Forecast Agent** produces the projections that power **Continuous Cash Flow Monitoring**, **Predictive Finance**, and the **Simulation Engine**. It never writes anything to the ledger — its entire output is derived, forward-looking, and explicitly labeled as a projection with a confidence interval, never presented as fact.

**Compliance Agent** monitors regulatory frameworks relevant to the company's jurisdiction (GCC VAT rules, IFRS updates, Kuwait labor law provisions affecting payroll) and maps changes to the specific transactions or configurations they affect, briefing the Tax Advisor and CFO agents with a gap analysis rather than making changes itself.

**Approval Assistant** is the only agent whose entire mandate is procedural rather than financial: it manages the lifecycle of every `approval_requests` row — routing to the correct approver(s) based on the company's configured chain, tracking SLA timers, escalating on timeout, and notifying the requester of the outcome. It has no authority to approve anything; its `auto` autonomy covers routing and reminders exclusively (see **Human Approval**).

**CEO Assistant** is the top-level conversational entry point most owners and executives actually talk to (see **Financial Copilot**) — it does not have a narrower specialist mandate of its own so much as it is the supervisor node of the orchestration graph, deciding which of the other fourteen agents should handle a given request, synthesizing their responses into one coherent, plain-language briefing, and remembering the shape of the conversation across turns via `ai_conversations` and `ai_messages`.

## Orchestration shape

```
                              ┌──────────────────────────┐
                              │      CEO Assistant         │
                              │   (Supervisor / Orchestrator)│
                              └─────────────┬─────────────┘
                                            │ routes by intent / event type
        ┌───────────────┬───────────────────┼───────────────────┬───────────────┐
        ▼               ▼                   ▼                   ▼               ▼
 ┌─────────────┐ ┌─────────────┐   ┌────────────────┐   ┌───────────────┐ ┌─────────────┐
 │ General      │ │  Auditor     │   │  Tax Advisor    │   │ Treasury       │ │  Forecast    │
 │ Accountant   │ │              │   │                 │   │ Manager        │ │  Agent       │
 └──────┬───────┘ └──────┬───────┘   └────────┬────────┘   └───────┬────────┘ └──────┬──────┘
        │                │                    │                    │                 │
        │         ┌──────┴──────┐      ┌──────┴───────┐    ┌───────┴────────┐        │
        │         │Fraud         │      │ Compliance    │    │  CFO           │        │
        │         │Detection     │      │ Agent         │    │  (synthesis)   │        │
        │         └──────────────┘      └───────────────┘    └────────────────┘        │
        │                                                                              │
        └───────────────┬──────────────────────────────────────────────────┬──────────┘
                         ▼                                                  ▼
                 ┌──────────────────────────────────────────────────────────────┐
                 │        MCP Tool Layer — typed function-call contracts         │
                 │   (one MCP tool per permitted /api/v1/* endpoint + scope)      │
                 └────────────────────────────┬───────────────────────────────────┘
                                              ▼
                                 ┌────────────────────────────┐
                                 │   Laravel 12 API (/api/v1)   │
                                 │  FormRequest validation      │
                                 │  RBAC permission check       │
                                 │  Service → Repository → DB   │
                                 └────────────────────────────┘
```

Document AI and OCR Agent, and Payroll Manager and Approval Assistant, are omitted from the diagram above only for legibility; they attach to the same supervisor and the same MCP tool layer as every other agent — there is no privileged "back channel" for any of the fifteen.

## Foundational schema: the agent catalog

Two tables underlie the entire roster: a platform-wide catalog of what the fifteen agents *are*, and a per-company table of how each company has *tuned* them.

```sql
CREATE TABLE ai_agents (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                VARCHAR(40)  NOT NULL UNIQUE,       -- 'general_accountant', 'auditor', ...
    name_en             VARCHAR(120) NOT NULL,
    name_ar             VARCHAR(120) NOT NULL,
    category            VARCHAR(40)  NOT NULL
        CHECK (category IN ('core_accounting','assurance','compliance','payroll',
                             'operations','treasury','executive','reporting',
                             'ingestion','predictive','governance')),
    mandate             TEXT NOT NULL,
    default_autonomy    VARCHAR(20) NOT NULL DEFAULT 'suggest_only'
        CHECK (default_autonomy IN ('auto','suggest_only','requires_approval')),
    model_profile       VARCHAR(60) NOT NULL,               -- references the LLM/tool configuration
    escalates_to        VARCHAR(40) NULL REFERENCES ai_agents(code),
    status              VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','deprecated')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Platform-wide catalog: no company_id. Fifteen rows, seeded once, versioned like code.

CREATE TABLE ai_agent_settings (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    agent_code          VARCHAR(40) NOT NULL REFERENCES ai_agents(code),
    enabled             BOOLEAN NOT NULL DEFAULT true,
    autonomy_override   VARCHAR(20) NULL
        CHECK (autonomy_override IN ('auto','suggest_only','requires_approval')),
    thresholds          JSONB NOT NULL DEFAULT '{}'::jsonb, -- e.g. {"auto_post_confidence": 0.92, "auto_post_max_amount": 250.0000}
    escalation_role_id  BIGINT NULL REFERENCES roles(id),   -- overrides default human escalation target
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    UNIQUE (company_id, agent_code)
);
CREATE INDEX idx_ai_agent_settings_company ON ai_agent_settings(company_id);
```

`ai_agents` is intentionally platform-wide: the roster is not a per-tenant configuration choice, it is the product. `ai_agent_settings` is where a company's own policy lives — a conservative company can set every agent's `autonomy_override` to `requires_approval` and every threshold to its narrowest value; an aggressive, high-trust company (typically one with a long, clean transaction history — see **Learning Loop**) can widen `thresholds.auto_post_confidence` downward and `thresholds.auto_post_max_amount` upward. Changing `ai_agent_settings` is itself a `permission_changes`-class action and therefore always requires human approval under the platform's non-negotiable sensitive-operations list (see **Human Approval**).

# Autonomous Accounting

Autonomous Accounting is the General Accountant agent's core loop, and it is the single highest-volume path through the entire AI Financial Operating System — in a typical SME, it processes more events per day than every other agent combined, because every bill, every invoice, every receipt, and every miscellaneous expense passes through it. This section specifies that loop precisely: the trigger, the reasoning, the confidence gate, and the exact database writes it produces.

## The loop

```
 Document arrives (upload, email-in, photo, bank feed line)
              │
              ▼
   ┌─────────────────────┐
   │     Document AI      │  classifies: bill | invoice | receipt | bank_stmt | contract | other
   └──────────┬───────────┘
              ▼
   ┌─────────────────────┐
   │      OCR Agent        │  extracts: vendor, date, amount, currency, line items, tax
   └──────────┬───────────┘   (per-field confidence; low-confidence fields flagged, not guessed)
              ▼
   ┌─────────────────────┐
   │  General Accountant   │  retrieves company history + policy from ai_memory (vector + structured)
   │                       │  drafts: account coding, tax treatment, dimensions, journal_lines
   └──────────┬───────────┘
              ▼
      confidence ≥ auto threshold AND amount ≤ auto ceiling?
       ┌───────────┴────────────┐
      YES                       NO
       │                         │
       ▼                         ▼
 ┌───────────┐          ┌──────────────────┐
 │ auto-post  │          │ suggest_only:     │
 │ via        │          │ draft surfaced to  │
 │ POST       │          │ human accountant   │
 │ /journal-  │          │ for one-click      │
 │ entries    │          │ accept / edit      │
 └─────┬──────┘          └─────────┬─────────┘
       │                            │
       └─────────────┬──────────────┘
                      ▼
            ┌───────────────────┐
            │  Auditor spot-check │  (continuous, post-hoc — see Continuous Audit)
            └───────────────────┘
```

Every step above writes an `ai_tasks` row (the unit of work) and, when the loop produces a proposed ledger action, an `ai_decisions` row (the structured decision object — see **Decision Engine**). Nothing in this loop ever calls `INSERT` against `journal_entries` from the FastAPI process; the "auto-post" arrow in the diagram is a signed API call to Laravel's `POST /api/v1/accounting/journal-entries` using a service-account bearer token scoped to exactly the permission (`accounting.journal.create`, and — only when the company's policy allows fully autonomous posting — `accounting.journal.post`) that the agent has been granted.

## Schema: the unit of work

```sql
CREATE TABLE ai_tasks (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    agent_code          VARCHAR(40) NOT NULL REFERENCES ai_agents(code),
    task_type           VARCHAR(60) NOT NULL,     -- 'journal_entry.draft', 'ocr.extract', 'reconciliation.match', ...
    status              VARCHAR(20) NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','running','completed','failed','escalated')),
    trigger_type        VARCHAR(30) NOT NULL
        CHECK (trigger_type IN ('domain_event','schedule','user_request','agent_handoff')),
    trigger_ref         JSONB NOT NULL DEFAULT '{}'::jsonb,  -- e.g. {"event":"document.uploaded","attachment_id":88123}
    subject_type        VARCHAR(60) NULL,          -- polymorphic: 'bill','invoice','bank_transaction', ...
    subject_id          BIGINT NULL,
    input               JSONB NOT NULL DEFAULT '{}'::jsonb,
    output              JSONB NULL,
    confidence          NUMERIC(5,4) NULL CHECK (confidence BETWEEN 0 AND 1),
    decision_id         BIGINT NULL REFERENCES ai_decisions(id),
    started_at          TIMESTAMPTZ NULL,
    completed_at        TIMESTAMPTZ NULL,
    latency_ms          INTEGER NULL,
    error_message       TEXT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_tasks_company_status ON ai_tasks(company_id, status);
CREATE INDEX idx_ai_tasks_subject ON ai_tasks(subject_type, subject_id);
CREATE INDEX idx_ai_tasks_company_agent_created ON ai_tasks(company_id, agent_code, created_at DESC);
```

## The confidence-and-reasoning contract

Every terminal `ai_tasks.output` that proposes a ledger action conforms to one JSON shape across every agent in the platform. This is the contract referenced throughout this document as "confidence + reasoning + sources":

```json
{
  "decision_id": "6f19c2f0-2a41-4e2b-9b7e-2b9a6a2b9d41",
  "agent": "general_accountant",
  "task_type": "journal_entry.draft",
  "confidence": 0.94,
  "autonomy_applied": "auto",
  "reasoning": "Vendor 'Gulf Prime Distribution Co.' matched against 47 prior bills posted to account 5130 (Office Supplies Expense) with 100% consistency over the trailing 12 months. Extracted total (KWD 186.500) matches goods_receipt #GR-2291 to the fils. No tax code change detected since the last bill from this vendor.",
  "proposed_action": {
    "type": "journal_entry.create",
    "endpoint": "POST /api/v1/accounting/journal-entries",
    "permission_required": "accounting.journal.create",
    "payload": {
      "company_id": 4021,
      "entry_date": "2026-07-14",
      "source_type": "bill",
      "source_id": 5521,
      "currency_code": "KWD",
      "lines": [
        { "account_code": "5130", "debit": "186.5000", "credit": "0.0000", "cost_center_id": 12 },
        { "account_code": "2110", "debit": "0.0000",   "credit": "186.5000" }
      ]
    }
  },
  "sources": [
    { "type": "attachment", "id": 88123, "label": "bill_scan_2291.pdf", "page": 1 },
    { "type": "bill", "id": 5521 },
    { "type": "historical_pattern", "sample_size": 47, "consistency": 1.0, "window_months": 12 }
  ],
  "requires_approval": false,
  "risk_flags": [],
  "created_at": "2026-07-14T09:12:03Z"
}
```

Three fields deserve emphasis because they are what make this contract auditable rather than merely explainable: `sources` always resolves to a concrete, fetchable record (an `attachments` row backed by a real file in Cloudflare R2, a specific prior transaction, or a named historical-pattern computation with its sample size disclosed) — never a vague "based on general accounting knowledge." `reasoning` is written in the same plain, cite-your-work register a competent junior accountant would use in a handover note, not in generic LLM hedging language. `autonomy_applied` records what actually happened, so `ai_logs` (see **Enterprise Architecture**) can always answer "was this posted automatically, and under which policy" without inference.

## Autonomy thresholds by transaction class

Autonomy is never a single global switch. QAYD ships conservative platform defaults, expressed as `ai_agent_settings.thresholds`, that a company can tighten (never loosen past the platform ceiling for `requires_approval`-class actions — see **Human Approval**):

| Transaction class | Platform default autonomy | Default confidence floor for `auto` | Default amount ceiling for `auto` |
|---|---|---|---|
| Recurring vendor bill, previously seen vendor + account pair | `auto` | 0.90 | KWD 500.000 per entry |
| New vendor, first bill | `suggest_only` | n/a | n/a |
| Payroll-related journal entries | `suggest_only` | n/a | n/a |
| Bank transaction auto-match (see **Autonomous Reconciliation**) | `auto` | 0.95 | no ceiling (matching, not moving, money) |
| Manual adjustment / write-off | `requires_approval` | n/a | n/a |
| Any entry touching a closed or locked fiscal period | `requires_approval`, always | n/a | n/a |
| Any entry a human previously corrected for this vendor/account pair within the last 90 days | `suggest_only`, floor raised, regardless of computed confidence | n/a | n/a |

The last row is a deliberate design choice: a recent human correction is treated as evidence that the pattern the model would otherwise trust is currently unstable, and QAYD intentionally suppresses auto-posting for that vendor/account pair until either the correction is reflected consistently across several subsequent instances (a **Learning Loop** signal) or a human explicitly re-raises the threshold.

## Worked scenario

Al-Noor Trading Co. W.L.L., a Kuwait-based SME distributor and QAYD's running example throughout this document, receives a photographed bill from Gulf Prime Distribution Co. by WhatsApp-to-email forwarding at 08:47. Document AI classifies it as `bill` in 340ms. The OCR Agent extracts vendor, date, three line items, and a total of KWD 186.500, each field above 0.97 field-confidence except the tax code, which it leaves blank at 0.61 confidence because the scan's tax section is partially cropped. General Accountant retrieves the vendor's history from `ai_memory` (47 prior bills, always coded to account 5130, always zero-rated for this vendor's product category) and drafts the journal entry above, at 0.94 overall confidence — high enough to auto-post under Al-Noor's configured threshold (0.90 floor, KWD 500 ceiling, both satisfied). The entry posts at 08:47:04, seventeen seconds after the photo arrived. The missing tax code field is separately logged as a low-confidence extraction and surfaced to the bookkeeper's daily digest, not silently defaulted — because a confidence gate applies per-field to the extraction step even when the overall journal-entry confidence clears the posting bar for the fields that matter to the double-entry itself.

# Autonomous Closing

Month-end close is where the cost of periodic accounting is most visible: a company that posted every transaction correctly all month can still spend two weeks reconstructing accruals, chasing intercompany mismatches, and re-deriving numbers that a continuously-running system would already have current. Autonomous Closing does not compress that work into a faster sprint; it eliminates the sprint by keeping the close position current every day, so that "closing the period" becomes a formal lock-and-sign-off event rather than a reconstruction project.

## What "continuously closed" means

QAYD maintains, for every open `fiscal_periods` row, a live **close-readiness score** — a composite of eleven checks the General Accountant and Auditor agents evaluate daily, not just in the days before period-end:

| Check | Owning agent | What it verifies |
|---|---|---|
| Unposted source documents | General Accountant | Every `bills`/`invoices` row dated inside the period has a corresponding posted `journal_entries` row |
| Bank reconciliation currency | Treasury Manager | Every `bank_accounts` row has been reconciled (see **Autonomous Reconciliation**) through the last statement date within the period |
| Unresolved OCR low-confidence fields | OCR Agent | No extracted field below the confidence floor remains unresolved on a posted document |
| Accrual completeness | General Accountant | Recurring accrual patterns from prior periods (rent, utilities, known recurring vendor bills not yet received) have a matching accrual proposal for the current period |
| Prepayment amortization | General Accountant | Prepaid-expense schedules have posted their current-period amortization tranche |
| Depreciation run | General Accountant | Fixed-asset depreciation for the period has been calculated and posted |
| Payroll accrual | Payroll Manager | Payroll expense for days worked but not yet paid within the period is accrued |
| Intercompany mismatch | Auditor | Intercompany balances between related `companies` entries net to zero |
| Suspense account balance | Auditor | The suspense/clearing account balance is zero, or every non-zero line has an open, owned investigation task |
| Inventory valuation currency | Inventory Manager | Stock valuation reflects the latest costing run as of the period-end date |
| Tax position currency | Tax Advisor | Output/input VAT computed for every transaction dated inside the period |

Each check produces a boolean plus, where relevant, a list of exception rows. The close-readiness score is simply the fraction of checks passing with zero open exceptions; it is visible on the CFO's dashboard every day of the month, not revealed for the first time on day 28.

## The close loop

```
 Every day, for each open fiscal_period:
              │
              ▼
   ┌───────────────────────────┐
   │ Run all 11 close checks     │  (General Accountant, Auditor, Treasury Manager,
   │                             │   Payroll Manager, Inventory Manager, Tax Advisor)
   └──────────────┬─────────────┘
                  ▼
        any check failing?
       ┌──────────┴───────────┐
      YES                     NO
       │                       │
       ▼                       ▼
 ┌─────────────────┐   ┌─────────────────────┐
 │ Draft correcting  │   │  close_readiness =   │
 │ entry / task,      │   │  100%; period stays  │
 │ owning agent       │   │  open for new         │
 │ surfaces exception │   │  transactions until   │
 │ to accountant       │   │  period-end date      │
 └─────────────────┘   └─────────────────────┘
                                  │
                     period-end date reached
                                  ▼
                     ┌─────────────────────────┐
                     │ CFO / Owner reviews the   │
                     │ close-readiness summary   │
                     │ and the period's P&L/BS   │
                     └─────────────┬─────────────┘
                                  ▼
                     requires_approval: fiscal_periods.status → 'closed'
                     (accounting.period.close permission — human only)
```

Locking a fiscal period is, without exception, a `requires_approval` action: no agent, regardless of confidence, can transition `fiscal_periods.status` from `open` to `closed`. This is deliberate — closing a period is the single action that makes every posted entry inside it functionally immutable for ordinary correction (see **Autonomous Reconciliation** and **Continuous Audit** for how corrections after close are handled via reversing entries in the next open period).

## Worked example: compressing a ten-day close to same-day sign-off

Al-Noor Trading Co. closes its books on a calendar-month fiscal period. Under the company's pre-QAYD process (a spreadsheet-and-memory close), June's books were finalized on July 11 — eleven days after period end, because the bookkeeper reconstructed accruals from memory and reconciled the bank statement only once the statement arrived by email on the 4th. Under Autonomous Closing, by June 30 at 18:00 the close-readiness score already reads 100%: the rent accrual posted automatically on June 25 (matching a five-year unbroken monthly pattern in `ai_memory` at 0.98 confidence), the bank feed had been reconciling continuously all month (see **Autonomous Reconciliation**) so the June 30 statement closed with two exceptions rather than forty, and payroll accrual for the last four working days of June posted the moment the Payroll Manager's calculation completed. The owner reviews an eleven-line close-readiness summary on the morning of July 1, approves the period lock at 09:14, and June is closed — a same-day sign-off replacing an eleven-day reconstruction, with no less rigor, because every check that used to happen once at the end now happens daily throughout.

# Autonomous Reconciliation

Bank reconciliation is the process of proving that the company's own books (`journal_lines` posted against a `bank_accounts` control account) agree with the bank's independent record of the same account (`bank_transactions`, ingested from a bank feed or statement import). It is the oldest form of continuous control in accounting — a bank statement is an external, un-editable source of truth — and it is exactly the kind of high-volume, pattern-heavy matching work that an AI agent performs better and more often than a human reconciling once a month.

## The matching algorithm

Treasury Manager runs matching continuously as new `bank_transactions` rows arrive (typically daily, via an Open Banking feed; at minimum, on statement import). Matching proceeds through three passes of decreasing certainty:

**Pass 1 — Deterministic match.** Amount, currency, and value date match a single unmatched `journal_lines` row (or a small set that nets to the same total) exactly, and either a bank reference number matches a stored `journal_entries.external_reference`, or the counterparty name matches a known payee/payer on file. Confidence: 0.98–1.00. Auto-matched, no approval needed — matching is an observational reconciliation act, not a movement of money.

**Pass 2 — Fuzzy match.** Amount matches within a small tolerance (configurable, default zero for domestic transfers, small FX-conversion tolerance for cross-currency receipts) and the counterparty name is a high-similarity but non-exact match (trigram/embedding similarity above threshold) against a known customer or vendor. Confidence: 0.80–0.97. Auto-matched above the company's configured floor (default 0.90); below it, surfaced as a suggested match for one-click confirmation.

**Pass 3 — No match found.** The transaction is placed in the **exception queue** with Treasury Manager's best-effort classification (e.g., "likely a bank charge," "likely an unrecorded customer receipt") and a confidence-scored guess at the counterparty, but it does not get force-matched. Unmatched-transaction age is itself a **Continuous Audit** control test (a transaction unmatched for more than a configured number of days is escalated).

```sql
-- Simplified matching candidate query Treasury Manager evaluates per unmatched bank_transactions row
SELECT jl.id, jl.journal_entry_id, jl.debit, jl.credit,
       similarity(bt.counterparty_name, je.counterparty_name) AS name_score,
       (bt.amount = jl.debit OR bt.amount = jl.credit)        AS amount_exact
FROM bank_transactions bt
JOIN journal_lines jl   ON jl.company_id = bt.company_id
JOIN journal_entries je ON je.id = jl.journal_entry_id
WHERE bt.company_id = :company_id
  AND bt.matched_journal_line_id IS NULL
  AND jl.reconciled_at IS NULL
  AND je.bank_account_id = bt.bank_account_id
  AND bt.value_date BETWEEN jl.entry_date - INTERVAL '5 days' AND jl.entry_date + INTERVAL '5 days'
ORDER BY amount_exact DESC, name_score DESC
LIMIT 5;
```

## Schema addition: the reconciliation record

```sql
CREATE TABLE bank_reconciliations (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    bank_account_id      BIGINT NOT NULL REFERENCES bank_accounts(id),
    statement_start_date DATE NOT NULL,
    statement_end_date   DATE NOT NULL,
    statement_closing_balance NUMERIC(19,4) NOT NULL,
    book_closing_balance NUMERIC(19,4) NOT NULL,
    variance             NUMERIC(19,4) NOT NULL DEFAULT 0,
    matched_count         INTEGER NOT NULL DEFAULT 0,
    exception_count       INTEGER NOT NULL DEFAULT 0,
    status                VARCHAR(20) NOT NULL DEFAULT 'in_progress'
        CHECK (status IN ('in_progress','pending_review','confirmed')),
    ai_decision_id         BIGINT NULL REFERENCES ai_decisions(id),
    confirmed_by           BIGINT NULL REFERENCES users(id),
    confirmed_at           TIMESTAMPTZ NULL,
    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL,
    CHECK (variance = statement_closing_balance - book_closing_balance)
);
CREATE INDEX idx_bank_reconciliations_company_account ON bank_reconciliations(company_id, bank_account_id);
```

`bank_reconciliations.status` reaching `confirmed` requires a human to click confirm on the period's reconciliation summary even when every line auto-matched at high confidence — reconciliation confirmation is the human checkpoint that lets a company's own accountant, not just the algorithm, certify that the bank statement and the books agree, which is what an external auditor or bank will ask to see evidence of.

## Worked example

Al-Noor Trading's KWD current account receives 214 bank lines in June. Treasury Manager auto-matches 198 of them in Pass 1 (exact amount, exact reference number — mostly the recurring vendor payments and customer receipts that dominate the account's traffic), auto-matches 11 more in Pass 2 (a customer paid from a personal account under a slightly different name than the company record, matched at 0.93 similarity against three years of prior payments from the same account number), and leaves 5 in the exception queue — including one KWD 42.000 bank charge with no corresponding journal line, which Treasury Manager tags "likely bank fee, no matching entry" at 0.71 confidence and routes to the bookkeeper rather than guessing further. The reconciliation closes with a variance of KWD 0.000 once the bookkeeper posts the missing bank-fee entry and confirms the remaining four exceptions, all in the same session rather than across a multi-day, manually-driven line-by-line reconciliation.

# Autonomous Reporting

A financial statement is a derived view over posted `journal_lines` (see **Enterprise Architecture** and the platform's double-entry rules) — it never stores primary data of its own. Autonomous Reporting is the Reporting Agent's mandate to generate those derived views, and the narrative commentary that explains them, on a schedule or on demand, without a human needing to manually assemble either.

## What the Reporting Agent owns

```sql
CREATE TABLE report_definitions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    code            VARCHAR(60) NOT NULL,           -- 'income_statement', 'balance_sheet', 'cash_flow', 'ar_aging', ...
    name_en         VARCHAR(160) NOT NULL,
    name_ar         VARCHAR(160) NOT NULL,
    report_type     VARCHAR(30) NOT NULL
        CHECK (report_type IN ('standard_statement','management_report','regulatory_filing','custom')),
    parameters_schema JSONB NOT NULL DEFAULT '{}'::jsonb,  -- expected filter/parameter shape
    narrative_enabled BOOLEAN NOT NULL DEFAULT true,        -- generate AI commentary alongside the numbers
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL
);

CREATE TABLE report_schedules (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    report_definition_id BIGINT NOT NULL REFERENCES report_definitions(id),
    cadence         VARCHAR(20) NOT NULL CHECK (cadence IN ('daily','weekly','monthly','quarterly','annual')),
    recipients      JSONB NOT NULL DEFAULT '[]'::jsonb,     -- user_ids and/or external emails (external = suggest_only gate)
    next_run_at     TIMESTAMPTZ NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','paused')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE report_runs (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    report_definition_id BIGINT NOT NULL REFERENCES report_definitions(id),
    report_schedule_id  BIGINT NULL REFERENCES report_schedules(id),
    triggered_by        VARCHAR(20) NOT NULL CHECK (triggered_by IN ('schedule','user_request','ai_agent')),
    parameters          JSONB NOT NULL DEFAULT '{}'::jsonb,
    result_data         JSONB NOT NULL,                     -- the computed statement, ready for render/export
    narrative           TEXT NULL,                           -- AI-generated MD&A-style commentary
    confidence          NUMERIC(5,4) NULL,
    generated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    distributed_at       TIMESTAMPTZ NULL,
    distributed_to       JSONB NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_report_runs_company_def ON report_runs(company_id, report_definition_id, generated_at DESC);
```

## Narrative generation, grounded

The Reporting Agent's narrative is not a free-form summary; it is generated against the same `sources`-cited contract as every other agent output, referencing specific driving accounts and their period-over-period deltas:

```json
{
  "report_run_id": 88210,
  "report": "income_statement",
  "period": "2026-06",
  "narrative": "Net income was KWD 14,820 versus KWD 11,340 in May, a 30.7% increase. The primary driver was a 22% increase in Sales Revenue (account 4000), consistent with the two new B2B customers onboarded on 2026-06-03 and 2026-06-11. Cost of Goods Sold grew proportionally (account 5000, +19%), keeping gross margin stable at 41.2% versus 40.8% in May. Office Supplies Expense (account 5130) was flat month-over-month.",
  "confidence": 0.91,
  "sources": [
    { "type": "account_balance_delta", "account_code": "4000", "period_a": "2026-05", "period_b": "2026-06" },
    { "type": "customer", "id": 771, "onboarded_at": "2026-06-03" },
    { "type": "customer", "id": 774, "onboarded_at": "2026-06-11" }
  ]
}
```

Scheduled generation of an internal statement is `auto` — nothing external happens as a result of computing a number. Distribution to a recipient outside the requesting user's own company session — an external auditor, a bank, an investor's inbox — is always `suggest_only`, requiring an explicit human confirmation of the recipient list, because sending financial data outward is an act with real-world consequence that a misconfigured schedule should never be able to trigger silently.

## Natural-language reporting via the Copilot

Beyond scheduled statements, the **Financial Copilot** lets any user with `reports.read` permission ask for a report in plain language — "show me gross margin by product category for Q2, compared to Q1" — which the Reporting Agent translates into a `report_runs` row with `triggered_by = 'user_request'` and the same narrative-plus-citation contract as a scheduled run, rendered back into the chat in seconds rather than requiring a report-builder UI session.

# Continuous Audit

A traditional audit is a sample: an external or internal auditor pulls twenty-five transactions out of forty thousand, tests them for a control failure, and extrapolates. Sampling exists because a human auditor cannot examine every transaction in the time available. QAYD's Auditor agent removes the constraint that made sampling necessary in the first place: it can evaluate every transaction, every day, against the full population, at a cost that does not scale with headcount. Continuous Audit is what an audit looks like once "we don't have time to check everything" stops being true.

## The control test suite

The Auditor agent runs the following tests continuously against the full population of posted transactions, not a sample, and not only at period-end:

| Control test | Method | Typical trigger for a flag |
|---|---|---|
| Segregation of duties | Cross-reference `created_by`/`updated_by` on `journal_entries` against the same user's role permissions | Same user both created and approved a transaction above the approval threshold |
| Unusual posting time | Statistical baseline of posting timestamps per user | Entry posted outside the user's normal working-hours pattern by more than 3 standard deviations |
| Round-number bias | Benford's-Law-style leading-digit distribution test over transaction amounts | Leading-digit distribution for a vendor/account pair deviates materially from the expected logarithmic distribution |
| Duplicate entry detection | Fuzzy match on amount + vendor + date window across `journal_entries` | Two entries within 5 days, same vendor, amount within 1%, no linked credit note explaining the second |
| Account misuse | Compare account code used against the account's declared normal-use pattern (`account_types`, historical usage) | An expense posted to a balance-sheet account with no prior precedent for that vendor |
| Manual override rate | Ratio of `suggest_only`/`requires_approval` overrides accepted-with-edits versus accepted-as-is, per agent and per user | A specific user's override-and-edit rate on AI drafts spikes relative to their own baseline |
| Dormant account reactivation | Detect postings to an `accounts` row with zero activity for a defined lookback window | First posting to a long-dormant account, especially a suspense or write-off account |
| Void/reversal rate | Ratio of voided or reversed entries to total entries, per user and per period | Reversal rate for a user or period exceeds the trailing baseline materially |
| Approval chain integrity | Verify every `requires_approval` action in the sensitive-operations list actually has a resolved `approval_requests` row before execution | Any sensitive action found executed without a matching resolved approval — a hard platform invariant violation, escalated immediately regardless of confidence |
| Cross-tenant leakage canary | Periodic synthetic query verifying RLS session variables are correctly scoped per request | Any query returning rows outside the requesting `company_id` — a platform-level security incident, not a bookkeeping finding |

Each test produces an `ai_decisions` row of `decision_type = 'audit_finding'` with a severity (`informational`, `low`, `moderate`, `high`, `critical`), the full `reasoning` and `sources` contract described in **Decision Engine**, and a routing to either the General Accountant (routine correction), the Fraud Detection agent (pattern suggests intent), or directly to the CFO and, for `critical` severity, the human Auditor role, bypassing intermediate triage.

## Continuous versus periodic: a direct comparison

| Property | Periodic (quarterly sample) audit | QAYD Continuous Audit |
|---|---|---|
| Population examined | A statistical sample (commonly 25–60 items) | 100% of posted transactions |
| Detection latency | Up to one audit cycle (often 90 days) | Same day to a few hours |
| Cost structure | Scales with headcount and hours billed | Scales with compute; marginal cost per transaction is near zero |
| Coverage of rare events | Structurally likely to miss low-frequency anomalies | Every anomaly in the tested dimensions is inspected, regardless of frequency |
| Auditor's role | Primary investigator | Reviewer of AI-surfaced findings; investigates flagged items in depth |
| External audit relationship | Auditor performs original sampling | External auditor can request QAYD's full continuous-audit trail as evidence, materially reducing substantive testing effort |

Continuous Audit does not replace the external, independent audit a company's regulators or investors require — it makes that audit faster and more reliable, because the external auditor inherits eleven months of daily control testing instead of reconstructing assurance from scratch at year-end.

## Worked example

Across March, the Auditor agent's round-number bias test flags a pattern: eight of Al-Noor Trading's last eleven petty-cash reimbursements to the same employee are exact multiples of KWD 10.000, versus an expected roughly-uniform trailing-digit distribution given the category's historical spend pattern. Individually, none of the eight transactions would have triggered a control (each is below the approval threshold, each has a receipt attached, each was approved by a different manager on different days). The pattern, visible only across the population, is surfaced as a `moderate`-severity finding to the human Auditor role with all eight transaction IDs, the statistical basis for the flag, and an explicit statement that round numbers alone are not proof of fraud — only a reason to look. The human auditor reviews the receipts, finds two are for amounts rounded up from the true receipt total, and initiates a policy conversation with the employee — a finding that a 25-sample quarterly audit had a materially lower chance of surfacing at all, because seven of the eleven transactions in question would statistically fall outside a typical sample.

# Continuous Compliance

Regulatory frameworks that touch a company's finances — tax codes, labor law provisions governing payroll, financial-reporting standards — change on a schedule set by regulators, not by a company's own audit calendar. A compliance failure discovered a year after a rule changed is, in the median case, discovered by the regulator rather than by the company. Continuous Compliance is the Compliance Agent's mandate to monitor the regulatory frameworks relevant to a company's jurisdiction continuously, map each change to the specific transactions, configurations, or filings it affects, and brief the Tax Advisor and CFO agents with a gap analysis before a deadline arrives rather than after a penalty does.

## What the Compliance Agent watches

- **GCC VAT frameworks.** Live in Saudi Arabia, the UAE, Bahrain, and Oman; pending adoption in Kuwait and Qatar. The Compliance Agent tracks rate changes, registration-threshold changes, and exemption-category changes across whichever of these apply to a company's actual operating and invoicing jurisdictions, and maps each change against `tax_codes` and `tax_rates` currently in use.
- **IFRS updates.** New or amended International Financial Reporting Standards that affect how a transaction class must be recognized, measured, or disclosed (e.g., lease accounting, revenue recognition timing) are mapped against the company's actual chart of accounts and transaction patterns to identify which historical or ongoing treatments would need to change.
- **Kuwait labor and payroll law.** Provisions affecting PIFSS contribution rates, end-of-service indemnity calculation, and WPS filing requirements are tracked against the Payroll Manager's current calculation logic.
- **Sector-specific licensing and reporting obligations** the company has declared during onboarding (e.g., a customs-bonded warehouse operator's specific inventory reporting obligations).

## The gap-analysis loop

```
 Regulatory source monitored (scheduled crawl / curated feed)
              │
              ▼
   ┌─────────────────────────┐
   │  Compliance Agent detects  │
   │  a change vs last known    │
   │  state of a tracked rule   │
   └────────────┬─────────────┘
                ▼
   ┌─────────────────────────┐
   │  Map change against:       │
   │  tax_codes, tax_rates,     │
   │  chart of accounts usage,  │
   │  payroll configuration      │
   └────────────┬─────────────┘
                ▼
        Any affected company configuration or
        open transaction found?
       ┌──────────┴───────────┐
      YES                     NO
       │                       │
       ▼                       ▼
 ┌───────────────────┐  ┌─────────────────────┐
 │ ai_decisions:        │  │ Logged, no company    │
 │ decision_type =       │  │ impact — filed for     │
 │ 'compliance_gap',     │  │ reference only          │
 │ routed to Tax          │  └─────────────────────┘
 │ Advisor + CFO,         │
 │ requires_approval for  │
 │ any configuration       │
 │ change                  │
 └───────────────────┘
```

A detected gap never self-remediates. The Compliance Agent's output is always `suggest_only`: it drafts the specific change (a new `tax_rates` row, an updated `tax_codes` mapping, a revised payroll calculation parameter) and the specific transactions or filings affected, but altering tax configuration is itself a sensitive, `requires_approval`-class action under the platform's permission rules, because a wrong automatic change to tax configuration is arguably more damaging than a late manual one.

## Worked example

Kuwait's Ministry of Finance publishes updated guidance affecting the PIFSS contribution calculation base for a specific allowance category. The Compliance Agent's scheduled monitoring detects the change within its next crawl cycle, cross-references Al-Noor Trading's `salary_components` table, and finds four active salary components tagged with the affected allowance category across eleven employees. It drafts a `compliance_gap` decision naming the specific regulation, the specific affected `salary_components` rows, the specific employees, and the computed delta in monthly PIFSS contribution the change implies — routed to the HR/Payroll approver with `requires_approval` before any payroll configuration changes. The company's HR manager reviews, confirms the interpretation against the Payroll Manager agent's draft calculation, and approves the configuration change three weeks before the next payroll run, rather than discovering the discrepancy during a PIFSS audit months later.

# Continuous Cash Flow Monitoring

Cash is the resource a company runs out of before it runs out of profit. A profitable company with a cash-flow timing mismatch — customers paying late, a large inventory purchase due before the corresponding sales convert to cash — can still fail to meet payroll. Continuous Cash Flow Monitoring is the joint mandate of the Treasury Manager (current position) and the Forecast Agent (forward projection), maintained live rather than recomputed only when someone asks.

## The live position

Treasury Manager maintains, in real time, the aggregate cash position across every `bank_accounts` row the company holds, converted to base currency (KWD) at the current `exchange_rate` for any foreign-currency accounts, refreshed on every new `bank_transactions` row and every posted `journal_entries` row that touches a bank-linked account. This is a read-time aggregate over posted data, never a separately-maintained shadow balance — consistent with the platform rule that nothing which represents money is ever authoritative outside the ledger itself.

## The 13-week rolling forecast

The Forecast Agent maintains a rolling 13-week cash-flow forecast, rebuilt daily, combining:

- **Contracted certainty** — `invoices` with a due date and payment-term-implied expected collection date, `bills` and `payroll_runs` with known payment dates, scheduled `tax_transactions` due dates.
- **Behavioral prediction** — for customers whose actual payment behavior deviates from stated terms (a customer with Net 30 terms who has paid at an average of Net 47 over the last six months), the forecast uses the customer's own historical lag, not the contractual term, unless a human overrides it.
- **Seasonal and trend adjustment** — prior-year and prior-quarter patterns for revenue and expense categories with demonstrated seasonality.

```json
{
  "forecast_id": "f5c2a891-77e1-4c2b-9e88-1a9b6c2d4e10",
  "company_id": 4021,
  "as_of": "2026-07-14",
  "horizon_weeks": 13,
  "confidence": 0.86,
  "lowest_projected_balance": {
    "week_of": "2026-08-18",
    "amount_kwd": "3210.4500",
    "days_until": 35
  },
  "reasoning": "Projected low point driven by payroll run on 2026-08-15 (KWD 18,400) and vendor payment to Gulf Prime Distribution Co. due 2026-08-17 (KWD 6,200) landing before the expected collection of Invoice #INV-3381 from Al Bairaq Retail Group, whose historical payment lag averages Net 47 against stated Net 30 terms.",
  "sources": [
    { "type": "payroll_runs", "id": 812, "scheduled_date": "2026-08-15" },
    { "type": "bills", "id": 6031, "due_date": "2026-08-17" },
    { "type": "invoices", "id": 3381, "customer_id": 771, "historical_lag_days": 47 }
  ],
  "risk_flags": ["projected_balance_below_reserve_floor"]
}
```

## Alerting, not just reporting

A forecast that only appears when a CFO opens a dashboard is still a periodic tool wearing a predictive label. Continuous Cash Flow Monitoring pushes an alert — via the Financial Copilot, email, and Laravel Reverb real-time notification — the moment a newly-computed forecast crosses a company-configured reserve floor, with enough lead time (in the worked example above, 35 days) for a human to act: accelerate collections, delay a discretionary payment, or draw on a credit facility. The alert itself is `suggest_only` by nature — it is information, not an executed action — but the recommendations attached to it (see **Recommendation Engine**) may include draft actions (a payment-term reminder to a slow-paying customer, a proposed delay of a non-critical vendor payment) that still individually route through their own appropriate autonomy gate.

## Worked example

Continuing the forecast above: Al-Noor Trading's owner receives a Copilot notification on July 14 that the projected cash balance on the week of August 18 is KWD 3,210, against a configured reserve floor of KWD 5,000 — a 35-day lead time. The Recommendation Engine (see that section) attaches two suggested actions: a draft, pre-worded payment reminder to Al Bairaq Retail Group referencing the specific overdue pattern, and a flag on the Gulf Prime Distribution Co. bill noting it is not contractually due until August 17 and could be paid August 20 without a late fee under the vendor's stated terms, shifting the low point above the reserve floor. The owner accepts the reminder draft (dispatched as a `suggest_only` action requiring one click) and takes no action on the payment timing, having decided the buffer is acceptable — the system surfaced the decision with enough runway for it to be a choice rather than a crisis.

# Continuous Risk Detection

Financial fraud and error share a detection problem: both are rare relative to the volume of legitimate transactions, both are easiest to stop before money leaves the company, and both are best found by comparing a transaction against the full pattern of everything else the company has ever done — a comparison a human reviewing transactions one at a time cannot hold in working memory. Continuous Risk Detection is the Fraud Detection agent's mandate: score every transaction, continuously, against known fraud and error patterns, and surface the ones that deviate — without ever being granted the authority to block, reverse, or approve anything itself.

## Risk pattern library

| Pattern | Signal | Typical severity |
|---|---|---|
| Duplicate invoice | Same vendor, same amount (or amount within tolerance), same or near-same invoice number/date, submitted twice | High |
| Ghost vendor | Vendor record created and paid within an unusually short window; vendor bank account shares details with an employee's on-file payroll account; no prior trading history before first payment | Critical |
| Invoice splitting | Multiple invoices from the same vendor, submitted close together, each individually just under an approval threshold, summing to materially above it | High |
| Velocity anomaly | Payment frequency or volume to a single vendor/employee spikes relative to trailing baseline without a corresponding business event | Moderate |
| Round-trip transaction | Funds paid out and a similar amount received back from a related party within a short window, with no clear commercial substance | High |
| Unusual approval pattern | An approval granted unusually fast relative to the approver's own historical review time for similar amounts | Moderate |
| New-vendor high-value first payment | First payment to a newly onboarded vendor exceeds a configured multiple of the company's typical first-payment size | Moderate |
| Address/bank-detail change immediately before payment | Vendor bank account details changed within a short window of an imminent payment run | Critical |

Each pattern produces a **risk score** (`NUMERIC(5,4)`, 0–1) computed from a weighted combination of the specific signals present, attached to the relevant `ai_decisions` row alongside the same `reasoning` and `sources` contract used across the platform — a risk score is never presented without the specific evidence that produced it.

## Autonomy boundary

Fraud Detection's autonomy is deliberately asymmetric: **flagging is `auto`, action is never `auto`.** Raising a risk score, attaching it to a transaction, and notifying the Auditor and CFO agents happens without any approval gate, because it changes nothing about the money itself — it only changes what a human sees. Any action beyond flagging — holding a payment, disabling a vendor record, reversing an entry — requires a human with the relevant permission (`accounting.journal.void`, `vendor.disable`, `bank.transfer.hold`) to act on the flag; the agent cannot take that action on its own initiative even at a risk score of 1.0000, because a false positive that blocks a legitimate, time-sensitive payment is itself an operational harm the platform is designed to avoid causing autonomously.

## Schema note

Risk scoring extends the same `ai_decisions` structure used across the platform (full DDL in **Decision Engine**) with a `risk_score` and `risk_patterns` field rather than introducing a parallel table:

```sql
ALTER TABLE ai_decisions
    ADD COLUMN risk_score    NUMERIC(5,4) NULL CHECK (risk_score BETWEEN 0 AND 1),
    ADD COLUMN risk_patterns JSONB NULL DEFAULT '[]'::jsonb;  -- e.g. ["ghost_vendor","new_vendor_high_value"]
CREATE INDEX idx_ai_decisions_risk_score ON ai_decisions(company_id, risk_score DESC) WHERE risk_score IS NOT NULL;
```

## Worked example

A vendor record for "Bunyan Gulf Supplies" is created in Al-Noor Trading's system and its first invoice — KWD 4,200, roughly six times the company's median first-payment size to a new vendor — is submitted for approval nine days after the vendor record was created, with a bank account number that, on a fuzzy match, shares seven of nine digits with an existing employee's on-file payroll account. Fraud Detection raises a `critical` risk score (0.91) combining the new-vendor high-value pattern and the bank-detail-similarity pattern, cites both specific signals with the underlying account numbers redacted to their last four digits in the surfaced view, and routes the flag to the Auditor and the human Finance Manager before the payment approval completes — the payment itself is still sitting in a normal `requires_approval` chain (payments above threshold always require approval regardless of risk score), but the approver now sees the risk flag alongside the approval request rather than approving blind. The Finance Manager holds the payment and confirms the vendor's registration documents independently before releasing it.

# Continuous Tax Review

Tax exposure is a moving target: every transaction that carries input or output VAT, every cross-border payment with withholding implications, and every payroll run with PIFSS contribution obligations changes the company's running tax position the moment it posts. Continuous Tax Review is the Tax Advisor agent's mandate to maintain that running position live, rather than reconstructing it under deadline pressure at filing time.

## The running position

Tax Advisor maintains, per tax jurisdiction and tax type the company is registered for, a live rollup over `tax_transactions`:

```sql
CREATE TABLE tax_transactions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    tax_code_id     BIGINT NOT NULL REFERENCES tax_codes(id),
    source_type     VARCHAR(30) NOT NULL,          -- 'invoice','bill','credit_note','debit_note','payroll_item'
    source_id       BIGINT NOT NULL,
    direction        VARCHAR(10) NOT NULL CHECK (direction IN ('input','output')),
    taxable_amount   NUMERIC(19,4) NOT NULL,
    tax_amount       NUMERIC(19,4) NOT NULL,
    currency_code    CHAR(3) NOT NULL DEFAULT 'KWD',
    tax_period_id    BIGINT NOT NULL REFERENCES fiscal_periods(id),
    ai_confidence    NUMERIC(5,4) NULL,             -- confidence of the AI's tax-code determination, when AI-classified
    created_by       BIGINT NULL REFERENCES users(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_tax_transactions_company_period ON tax_transactions(company_id, tax_period_id);
```

For every transaction that touches a taxable account, Tax Advisor determines the tax code (drawing on `ai_memory` for how this company and this vendor/customer combination has historically been treated), computes the resulting `tax_transactions` row, and — because tax determination is a judgment call with financial consequence, not a mechanical extraction — carries its own `ai_confidence`, surfaced to the human whenever it falls below the company's configured floor rather than defaulted silently.

## From running position to filing

```
 Every posted transaction touching a taxable account
              │
              ▼
   Tax Advisor determines tax_code → writes tax_transactions row
              │
              ▼
   Running position updated: input VAT, output VAT, net payable/reclaimable
   (visible on demand, not only at filing time)
              │
   filing deadline approaches (per tax_returns schedule)
              ▼
   ┌─────────────────────────┐
   │ Tax Advisor drafts        │
   │ tax_returns row from the   │
   │ period's tax_transactions  │
   └────────────┬─────────────┘
                ▼
      requires_approval, always: tax.submit permission
      (no confidence threshold ever waives this gate)
                ▼
      Human reviews draft filing + Tax Advisor's reasoning,
      approves or amends, then submits
```

Tax submission is on the platform's fixed, non-negotiable sensitive-operations list — every filing, in every jurisdiction, at every confidence level, requires a human approval chain before submission. Continuous Tax Review's value is entirely in what happens *before* that gate: instead of a scramble to reconstruct a quarter's input and output VAT from scratch, the draft filing is already assembled, already reconciled against posted transactions, and already flagged for any determination the agent itself was not confident about.

## Worked example

Al-Noor Trading imports goods from a Saudi supplier subject to reverse-charge VAT treatment under the GCC framework. Tax Advisor recognizes the transaction pattern from three prior imports with the same supplier, applies the reverse-charge tax code at 0.95 confidence, and posts the corresponding input/output tax_transactions pair the same day the bill is recorded — rather than the treatment being decided for the first time when a bookkeeper prepares a filing weeks later and has to research the correct code under time pressure. When Kuwait's VAT framework moves from pending to live (tracked by the Compliance Agent — see **Continuous Compliance**), the Tax Advisor's reverse-charge logic for this specific supplier relationship is already validated against real transaction history, not implemented cold on the framework's effective date.

# Business Health Monitoring

A CFO's job is to hold a company's financial health in their head: is liquidity adequate, is the company burning cash faster than planned, are receivables aging, is margin holding. Business Health Monitoring is the CFO agent's mandate to hold that same picture continuously and surface it as a single, live, explainable view rather than requiring someone to assemble it from six different reports.

## The health score

The CFO agent computes a composite health score from weighted sub-scores, each independently inspectable:

| Sub-score | Inputs | What deterioration signals |
|---|---|---|
| Liquidity | Current ratio, quick ratio, cash runway (from **Continuous Cash Flow Monitoring**) | Ability to meet near-term obligations |
| Profitability | Gross margin, net margin, trend versus trailing 6 months | Whether the core business model still works at current scale |
| Receivables quality | DSO (days sales outstanding), aging bucket concentration | Collections risk, potential bad debt |
| Payables discipline | DPO (days payable outstanding), on-time payment rate | Vendor relationship risk, missed early-payment discounts |
| Working capital efficiency | Cash conversion cycle (DSO + DIO − DPO) | How much cash is tied up operating the business |
| Risk exposure | Aggregate open risk flags from **Continuous Risk Detection**, weighted by severity | Unresolved fraud/error risk sitting in the books |
| Compliance currency | Open items from **Continuous Compliance** and **Continuous Tax Review** | Regulatory exposure accumulating unaddressed |

Each sub-score is normalized to 0–100 and combined into an overall score with company-configurable weights (a capital-intensive company might weight liquidity higher; a services company might weight receivables quality higher). The score is never presented as a bare number — every sub-score expands, on request, into the specific accounts, ratios, and transactions driving it, following the same citation discipline as every other agent output in this document.

## Alert thresholds

```json
{
  "health_score": 78,
  "as_of": "2026-07-14",
  "sub_scores": {
    "liquidity": 82,
    "profitability": 88,
    "receivables_quality": 61,
    "payables_discipline": 90,
    "working_capital_efficiency": 74,
    "risk_exposure": 95,
    "compliance_currency": 100
  },
  "flagged_sub_scores": [
    {
      "name": "receivables_quality",
      "reasoning": "DSO has risen from 34 to 49 days over the trailing quarter, driven primarily by Al Bairaq Retail Group's payment lag (see Continuous Cash Flow Monitoring). Aging bucket 61-90 days now holds 18% of AR balance versus a 12-month average of 9%.",
      "sources": [
        { "type": "ar_aging_bucket", "bucket": "61-90", "pct_of_ar": 0.18, "trailing_avg": 0.09 },
        { "type": "customer", "id": 771, "dso_contribution_days": 9 }
      ]
    }
  ]
}
```

## Worked example

The CFO agent's weekly briefing to Al-Noor Trading's owner opens with a health score of 78 (down from 85 four weeks prior), and leads with the one sub-score that actually moved materially — receivables quality — rather than restating everything that stayed the same. The briefing, generated by the CEO Assistant agent for delivery through the **Financial Copilot**, reads in plain language: "Overall health is still good, but AR is loosening — Al Bairaq Retail Group is the main driver, now averaging 47 days against 30-day terms. Everything else — margin, payables, risk, compliance — is stable or improved." This is Business Health Monitoring's core value: not a dashboard a CFO has to interpret, but a synthesis that already did the interpreting and can defend every word of it with a citation.

# Decision Engine

Every capability described so far — Autonomous Accounting's journal drafts, Continuous Audit's findings, Continuous Risk Detection's flags, Continuous Cash Flow Monitoring's alerts — ultimately produces the same underlying artifact: a structured, evaluable proposal that something should happen. The Decision Engine is the shared machinery that turns an agent's analysis into that artifact, decides how much autonomy it is allowed, and routes it to execution, suggestion, or approval accordingly. It is the single mechanism referenced throughout this document every time a section says "produces an `ai_decisions` row."

## The decision object

```sql
CREATE TABLE ai_decisions (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    agent_code           VARCHAR(40) NOT NULL REFERENCES ai_agents(code),
    task_id              BIGINT NULL REFERENCES ai_tasks(id),
    decision_type        VARCHAR(60) NOT NULL,       -- 'journal_entry.draft','audit_finding','compliance_gap',
                                                       -- 'reconciliation_match','tax_filing_draft','recommendation', ...
    decision_class       VARCHAR(20) NOT NULL
        CHECK (decision_class IN ('operational','tactical','strategic')),
    subject_type         VARCHAR(60) NULL,           -- polymorphic reference to the affected record
    subject_id           BIGINT NULL,
    confidence           NUMERIC(5,4) NOT NULL CHECK (confidence BETWEEN 0 AND 1),
    reversibility        VARCHAR(20) NOT NULL DEFAULT 'reversible'
        CHECK (reversibility IN ('reversible','reversible_with_cost','irreversible')),
    expected_impact       JSONB NOT NULL DEFAULT '{}'::jsonb,   -- e.g. {"financial_amount": "186.5000", "currency":"KWD"}
    reasoning             TEXT NOT NULL,
    sources               JSONB NOT NULL DEFAULT '[]'::jsonb,
    proposed_action        JSONB NULL,                 -- endpoint + payload, when the decision implies an API call
    risk_score             NUMERIC(5,4) NULL CHECK (risk_score BETWEEN 0 AND 1),
    risk_patterns          JSONB NULL DEFAULT '[]'::jsonb,
    autonomy_applied        VARCHAR(20) NOT NULL
        CHECK (autonomy_applied IN ('auto','suggest_only','requires_approval')),
    status                  VARCHAR(20) NOT NULL DEFAULT 'proposed'
        CHECK (status IN ('proposed','auto_executed','accepted','edited_and_accepted',
                           'rejected','expired','pending_approval','approved','denied')),
    approval_request_id     BIGINT NULL REFERENCES approval_requests(id),
    executed_at              TIMESTAMPTZ NULL,
    executed_reference_type  VARCHAR(60) NULL,        -- e.g. 'journal_entries' once posted
    executed_reference_id    BIGINT NULL,
    resolved_by               BIGINT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_decisions_company_status ON ai_decisions(company_id, status);
CREATE INDEX idx_ai_decisions_company_type ON ai_decisions(company_id, decision_type, created_at DESC);
CREATE INDEX idx_ai_decisions_subject ON ai_decisions(subject_type, subject_id);
```

## The autonomy-determination formula

The Decision Engine computes `autonomy_applied` from three inputs — never from confidence alone — because a high-confidence decision about an irreversible, high-impact action is exactly the case the platform must not let confidence auto-execute:

```
autonomy_applied =
    IF subject/decision_type is on the fixed sensitive-operations list
        → 'requires_approval'                       (confidence is irrelevant; see Human Approval)
    ELSE IF reversibility == 'irreversible'
        → 'requires_approval'
    ELSE IF confidence >= ai_agent_settings.thresholds.auto_post_confidence
         AND expected_impact.financial_amount <= ai_agent_settings.thresholds.auto_post_max_amount
        → 'auto'
    ELSE
        → 'suggest_only'
```

This formula is evaluated identically regardless of which of the fifteen agents produced the decision — the Decision Engine is shared infrastructure, not a per-agent reimplementation, which is precisely what guarantees that "Payroll Manager" cannot have a laxer autonomy rule than "General Accountant" for equivalent risk simply because its code path was written differently.

## Decision taxonomy

`decision_class` exists because "operational," "tactical," and "strategic" decisions warrant different review cadences even at identical confidence:

| Class | Example | Typical review cadence |
|---|---|---|
| Operational | Categorize an expense; match a bank line | Reviewed in aggregate (exception-based), rarely individually |
| Tactical | Draft a payment-term reminder; propose a reorder quantity | Reviewed individually before dispatch, but quickly (seconds to minutes) |
| Strategic | Recommend delaying a hire; flag a customer concentration risk | Reviewed deliberately, often discussed before acting (see **Recommendation Engine**) |

## Worked example

A `journal_entry.draft` decision (operational class, 0.94 confidence, reversible, KWD 186.500 impact) auto-executes per **Autonomous Accounting**'s worked example. In the same week, a `compliance_gap` decision (tactical class, 0.88 confidence, but flagged `reversibility = 'reversible_with_cost'` because unwinding a payroll configuration change after a run has processed requires a correcting run) is routed to `suggest_only` despite confidence clearing the operational auto-threshold, because the Decision Engine's reversibility check — not a hand-coded exception in the Compliance Agent — is what holds it back. The distinction is enforced once, centrally, rather than trusted to every agent's own judgment.

# Recommendation Engine

Not every AI output is a decision about what the system should do; many are suggestions about what a *human* should decide to do — hire, price, negotiate, cut a cost line. The Recommendation Engine is the layer that ranks, deduplicates, and surfaces these judgment-requiring suggestions, sourced from every agent's ongoing analysis, into a single prioritized list rather than scattering them across fifteen separate agent feeds.

## Recommendation versus decision

A decision (see **Decision Engine**) has a concrete, executable `proposed_action` and a computed autonomy tier — it is a specific system-call-shaped thing that can, in the right conditions, execute itself. A recommendation is always `suggest_only` by category, because by definition it requires human judgment the AI is not positioned to make unilaterally — a recommendation to renegotiate payment terms with a vendor, to hire an additional warehouse employee given inventory growth, or to discontinue a low-margin product line. QAYD never auto-executes a recommendation; the Recommendation Engine's entire output surface is advisory.

## Ranking

Recommendations compete for a human's limited attention, so the CFO agent ranks the pool using a weighted score:

```
priority_score = (financial_impact_estimate × impact_weight)
               + (urgency_days_inverse × urgency_weight)
               + (confidence × confidence_weight)
               − (implementation_effort_estimate × effort_weight)
```

with default weights tuned so that a high-impact, time-sensitive, high-confidence, low-effort recommendation (e.g., "switch this recurring bill to the vendor's early-payment discount terms — 2% savings, no operational change required") ranks above a lower-impact or higher-effort one (e.g., "consider renegotiating the office lease — meaningful savings, six-month process, uncertain outcome"), even though both may be individually valid.

## Deduplication and lifecycle

```sql
CREATE TABLE ai_recommendations (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    decision_id         BIGINT NOT NULL REFERENCES ai_decisions(id),  -- decision_type = 'recommendation'
    category             VARCHAR(40) NOT NULL,        -- 'cost_reduction','revenue_growth','risk_mitigation','efficiency'
    priority_score        NUMERIC(6,2) NOT NULL,
    financial_impact_estimate NUMERIC(19,4) NULL,
    urgency_days           INTEGER NULL,
    effort_estimate         VARCHAR(20) NULL CHECK (effort_estimate IN ('low','medium','high')),
    status                  VARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open','accepted','dismissed','superseded','expired')),
    superseded_by_id         BIGINT NULL REFERENCES ai_recommendations(id),
    dismissed_reason          TEXT NULL,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_recommendations_company_status ON ai_recommendations(company_id, status, priority_score DESC);
```

Before a new recommendation is surfaced, the CFO agent checks for semantic similarity (via the same vector retrieval used in **Company Memory**) against open recommendations in the same category; a near-duplicate is merged (`superseded_by_id`) rather than presented twice. A dismissed recommendation's `dismissed_reason` becomes a **Learning Loop** signal — if a human dismisses "renegotiate the office lease" twice with the reason "not a priority this year," the CFO agent lowers that category's priority weighting for this company specifically, rather than re-surfacing it monthly regardless of feedback.

## Worked example

In the same week as the cash-flow alert described in **Continuous Cash Flow Monitoring**, the Recommendation Engine's ranked list for Al-Noor Trading's owner shows, in order: (1) shift the Gulf Prime Distribution Co. payment by three days — near-zero effort, directly resolves the projected reserve-floor breach; (2) enable the early-payment discount terms recently offered by a second vendor — low effort, 1.5% recurring savings; (3) review Al Bairaq Retail Group's credit terms given the sustained payment lag — medium effort, addresses a recurring pattern rather than one instance. A fourth, lower-ranked item — "consider a second warehouse given consistent 90%+ utilization" — carries high estimated impact but high effort and lower urgency, correctly ranked below the three faster wins despite its larger absolute financial-impact estimate.

# Simulation Engine

Some decisions cannot be safely reasoned about from historical pattern-matching alone, because they concern a state the company has never been in — hiring a first warehouse manager, losing its largest customer, entering a second country. The Simulation Engine, jointly operated by the Forecast Agent and the CFO agent, lets a human pose a hypothetical in natural language and see its modeled consequence across the company's actual financial structure before committing to it.

## What gets simulated

| Scenario type | Inputs varied | Output |
|---|---|---|
| Headcount change | New salary, start date, department | Revised payroll run projection, updated cash-flow forecast, updated margin |
| Customer loss/gain | Customer's historical revenue and margin contribution | Revised revenue forecast, updated customer-concentration risk score |
| Pricing change | Price delta applied to a product/category, elasticity assumption | Revised revenue and margin forecast under stated assumption |
| Vendor term change | New payment terms or price | Revised cash-flow forecast, updated DPO |
| Large one-time expense | Amount, timing, financing method (cash vs. facility draw) | Revised cash runway, covenant-ratio impact if financed |
| Currency exposure shift | New FX rate scenario | Revised base-currency-converted balances for foreign-currency accounts |

## Mechanism

A simulation never touches a real record. The Forecast Agent clones the relevant portion of the company's current-state projection (not the ledger — the ledger is never forked) into an isolated in-memory scenario, applies the stated hypothetical, and re-runs the same projection logic used in **Continuous Cash Flow Monitoring** and **Predictive Finance** against the modified inputs:

```json
{
  "simulation_id": "9b7c1e20-4f3a-4e11-8a2c-77d0f1c9a8b2",
  "company_id": 4021,
  "scenario": "Hire a full-time warehouse assistant at KWD 380/month starting 2026-09-01",
  "baseline_lowest_projected_balance": { "week_of": "2026-08-18", "amount_kwd": "3210.4500" },
  "simulated_lowest_projected_balance": { "week_of": "2026-09-15", "amount_kwd": "1740.1000" },
  "confidence": 0.83,
  "reasoning": "Adds KWD 380/month payroll cost plus estimated 12.5% PIFSS employer contribution (KWD 47.50/month) from September. No corresponding revenue increase modeled, per the scenario as stated — if the hire is expected to enable additional throughput or sales, add that assumption explicitly for a revised projection.",
  "sensitivity": [
    { "assumption": "no revenue offset", "lowest_balance_kwd": "1740.1000" },
    { "assumption": "5% revenue offset from added capacity", "lowest_balance_kwd": "2615.3000" }
  ]
}
```

The `sensitivity` array is a deliberate design choice: a single-point simulation output invites false precision. QAYD always surfaces at least the stated assumption and one bounding alternative, and explicitly names what was *not* modeled (here, any revenue benefit of the hire) so a human does not mistake a scenario for a forecast.

## Natural-language scenario input

Through the **Financial Copilot**, a user poses the scenario conversationally ("what happens to our cash position if we hire a warehouse assistant in September") and the CEO Assistant agent's orchestrator routes it to the Forecast Agent, which resolves the ambiguous parts of the request (salary estimate, start date) either from context already in the conversation or by asking a clarifying question — the Simulation Engine never silently assumes a material input it was not given.

## Worked example

Before committing to the warehouse-assistant hire modeled above, Al-Noor Trading's owner asks the Copilot to also simulate delaying the hire to November instead of September. The Forecast Agent returns a second simulation showing the lowest projected balance over the same 13-week horizon recovering to KWD 2,890 — comfortably above the reserve floor — because the added cost lands two months later, past the Al Bairaq Retail Group collection the CFO agent already expects to normalize by then. The owner proceeds with the November start date, a decision made with a concrete, numbers-based comparison rather than a guess.

# Predictive Finance

Continuous Cash Flow Monitoring and the Simulation Engine are the two most visible applications of a broader capability: the Forecast Agent's ongoing, always-current set of predictions about the company's financial future, built from the same historical data every other agent draws on, held to the same evidentiary standard as every other output in this document — a prediction is never presented without its confidence interval and the specific historical basis for it.

## What is forecast

| Prediction | Method basis | Refresh cadence |
|---|---|---|
| 13-week rolling cash position | Contracted certainty + customer-specific payment-behavior modeling (see **Continuous Cash Flow Monitoring**) | Daily |
| Revenue, next 1–3 months | Trailing trend + seasonality + pipeline signals (open `sales_quotations`/`sales_orders`) | Daily |
| Expense, next 1–3 months | Recurring pattern detection over `bills`/`journal_entries` history + known scheduled items (payroll, rent, contracted services) | Daily |
| Working capital position | Projected AR collections, AP disbursements, and inventory turnover combined | Daily |
| Tax liability, current period | Running `tax_transactions` rollup projected to period end at current transaction velocity | Daily |
| Customer churn risk | Payment-behavior deterioration, order-frequency deterioration, support/interaction signals where available | Weekly |
| Inventory stockout risk | Consumption velocity versus current stock and supplier lead time | Daily |

## Confidence intervals, not single numbers

Every prediction is returned as a range with an explicit method, never a bare point estimate:

```json
{
  "prediction_id": "c3a9e611-1b2d-4a90-9e77-2f4b8c1d9e33",
  "metric": "revenue_next_30_days",
  "company_id": 4021,
  "point_estimate_kwd": "48200.0000",
  "confidence_interval_80pct": { "low": "43100.0000", "high": "53400.0000" },
  "confidence": 0.79,
  "method": "trailing_13_week_trend + seasonal_adjustment(ramadan_slowdown=false) + open_pipeline(sales_orders, weighted_by_stage)",
  "reasoning": "Point estimate combines a trailing revenue run-rate of KWD 45,900/month, adjusted +5% for two open sales_orders in late-stage negotiation (weighted at their historical close probability by stage), with no seasonal adjustment applied since the forecast window does not overlap a historically slow period for this company.",
  "sources": [
    { "type": "trailing_trend", "window_weeks": 13 },
    { "type": "sales_orders", "id": 991, "stage": "negotiation", "close_probability": 0.6 },
    { "type": "sales_orders", "id": 1004, "stage": "negotiation", "close_probability": 0.45 }
  ]
}
```

## Reconciling predictions against actuals

A forecasting system that is never checked against what actually happened degrades silently. Every prediction row is retained after its horizon passes and automatically reconciled against the realized value, producing a per-metric, per-company accuracy trend that feeds directly into the **Learning Loop**:

```sql
CREATE TABLE ai_prediction_outcomes (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    prediction_id     UUID NOT NULL,             -- correlates to the ai_decisions/ai_tasks row that produced it
    metric            VARCHAR(60) NOT NULL,
    predicted_value   NUMERIC(19,4) NOT NULL,
    predicted_low     NUMERIC(19,4) NULL,
    predicted_high    NUMERIC(19,4) NULL,
    actual_value      NUMERIC(19,4) NOT NULL,
    absolute_error    NUMERIC(19,4) GENERATED ALWAYS AS (ABS(predicted_value - actual_value)) STORED,
    within_interval    BOOLEAN NOT NULL,
    horizon_date       DATE NOT NULL,
    reconciled_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_prediction_outcomes_company_metric ON ai_prediction_outcomes(company_id, metric, horizon_date);
```

A company's own `ai_prediction_outcomes` history is what lets the Forecast Agent's stated `confidence` mean something concrete and specific to that company, rather than a generic model-level number — if the 30-day revenue prediction has landed within its 80% interval 8 times out of the last 10, the agent's stated confidence is calibrated against that track record, not against an abstract prior.

## Worked example

Three months into using QAYD, Al-Noor Trading's `ai_prediction_outcomes` history shows the 30-day revenue forecast landing within its stated 80% interval in 11 of the last 13 predictions — a well-calibrated track record the Forecast Agent surfaces directly when asked "how much should I trust this forecast": "Over the last quarter, this prediction has been within its stated range 85% of the time, in line with the 80% interval it targets." A newly onboarded company with two weeks of history instead sees an explicit note that its forecast confidence is provisional pending more data — the platform never manufactures false precision to make a new tenant's dashboard look more finished than its actual evidentiary basis supports.

# Financial Copilot

The Financial Copilot is the conversational surface through which a human reaches the entire AI Finance Team — but it is a surface, not a sixteenth agent with its own independent authority. Every capability described in this document is reachable through it, and nothing is reachable through it that would not otherwise be reachable through the Next.js dashboards under the same user's own permissions. This section specifies the mechanism precisely, because "the AI is not a chatbot" is easiest to violate exactly at the chat interface if the orchestration underneath it is not disciplined.

## Architecture

The Copilot is the user-facing name for the CEO Assistant agent acting as the LangGraph supervisor node described in **The AI Finance Team**'s orchestration diagram. A message from a user becomes a turn in an `ai_conversations` thread; the orchestrator classifies intent, retrieves relevant context (structured queries scoped to the user's own permitted data, plus semantic retrieval from **Company Memory**), routes to the specialist agent(s) whose mandate matches the intent, and composes their outputs into one coherent reply — never bypassing any agent's own autonomy rules or the Decision Engine's approval gating to do so.

```sql
CREATE TABLE ai_conversations (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    user_id         BIGINT NOT NULL REFERENCES users(id),
    title           VARCHAR(160) NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived')),
    started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_message_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE ai_messages (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    conversation_id     BIGINT NOT NULL REFERENCES ai_conversations(id),
    role                VARCHAR(20) NOT NULL CHECK (role IN ('user','assistant','tool')),
    agent_code          VARCHAR(40) NULL REFERENCES ai_agents(code),  -- which specialist produced this turn, if 'assistant'
    content             TEXT NOT NULL,
    tool_calls          JSONB NULL DEFAULT '[]'::jsonb,     -- MCP tool invocations made to produce this turn
    confidence          NUMERIC(5,4) NULL,
    citations           JSONB NULL DEFAULT '[]'::jsonb,
    decision_id          BIGINT NULL REFERENCES ai_decisions(id),  -- set when the reply surfaces/creates a decision
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_messages_conversation ON ai_messages(conversation_id, created_at);
```

## Example dialogue

```
User:      Why did our KWD balance drop so much this week?

Assistant (Treasury Manager, via CEO Assistant orchestration):
           The main draw was the payroll run on July 15 (KWD 18,400) and the
           Gulf Prime Distribution Co. bill paid on July 16 (KWD 6,200). Two
           customer receipts also landed later than usual — Al Bairaq Retail
           Group's payment is now averaging 47 days against 30-day terms,
           which is the same pattern the CFO briefing flagged last week.
           Confidence: 0.93. Sources: payroll_runs #812, bills #6031,
           invoices #3381 / #3402.

User:      Should I be worried about the August 18 low point?

Assistant (Forecast Agent, via CEO Assistant orchestration):
           It's projected at KWD 3,210 against your KWD 5,000 reserve floor,
           35 days out — not urgent yet, and there are two easy levers: delay
           the Gulf Prime payment by three days (no late fee under their
           terms), or send a payment reminder to Al Bairaq. Want me to draft
           the reminder?

User:      Yes, draft it.

Assistant: Draft ready for your review — this will not send until you
           approve it. [renders the draft with an Approve / Edit action]
```

Nothing in this exchange executed with real-world effect until the explicit "draft ready... will not send until you approve it" checkpoint — the Copilot's fluency is a presentation-layer property, and the underlying `suggest_only` gate on outbound communication is identical to what a user would encounter clicking through the dashboard UI by hand.

## Tool-calling contract

Every tool the Copilot's orchestrator can invoke is an MCP tool definition mapped one-to-one to a permitted `/api/v1/*` endpoint, scoped to the requesting user's own permission grant — the orchestrator process authenticates as that user's session (or a narrowly-scoped service token for scheduled/event-triggered work), never as a platform-wide superuser:

```json
{
  "tool": "get_cash_position",
  "maps_to": "GET /api/v1/treasury/cash-position",
  "permission_required": "treasury.read",
  "parameters": { "company_id": "int", "as_of": "date (optional)" }
},
{
  "tool": "draft_payment_reminder",
  "maps_to": "POST /api/v1/sales/reminders/draft",
  "permission_required": "sales.customer.contact",
  "parameters": { "invoice_id": "int" },
  "produces": "ai_decisions row, decision_type='communication_draft', autonomy_applied='suggest_only' always"
}
```

`draft_payment_reminder`'s contract fixes `autonomy_applied` at `suggest_only` regardless of confidence, because outbound communication to a third party — a customer, a vendor, a regulator — sits on the same principle as the sensitive-operations list even where it is not itself a financial transaction: anything that leaves the company's own system and reaches an external party gets a human's eyes first.

# Human Approval

Every autonomy claim made in this document — auto-posting, auto-matching, auto-flagging — is safe only because of the mechanism specified in this section: a formal, structurally unbypassable approval chain that gates the specific list of sensitive operations no confidence score, no agent, and no orchestration shortcut can route around. This is the single most load-bearing section in the document, because it is what makes every other capability describable as "autonomous" without being reckless.

## The fixed sensitive-operations list

The following actions are `requires_approval`, always, platform-wide, with no company configuration able to loosen them (a company may add *more* actions to its own approval list; it may never remove one of these):

| Sensitive operation | Permission key | Why it cannot be automated away |
|---|---|---|
| Bank transfer / payment disbursement | `bank.transfer` | Irreversible movement of real funds out of the company |
| Payroll release (disbursement, not calculation) | `payroll.release` | Irreversible movement of funds to employees; errors are hard to claw back |
| Tax return submission | `tax.submit` | Legal filing with regulatory and financial consequence |
| Deleting or voiding posted financial data | `accounting.journal.void`, `accounting.period.delete` | Destroys or alters the historical record an audit depends on |
| Permission / role changes | `admin.permissions.update` | Could be used to widen an AI's or a compromised account's own authority |
| Company settings changes (fiscal year, base currency, tax registration) | `company.settings.update` | Cross-cutting changes that affect every other calculation in the system |
| AI autonomy configuration changes (`ai_agent_settings`) | `ai.settings.update` | Widening an agent's own auto-execution ceiling is itself a sensitive act |

## The approval chain

```sql
CREATE TABLE approval_requests (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    decision_id           BIGINT NULL REFERENCES ai_decisions(id),   -- NULL when a human-initiated action needs approval too
    initiated_by_user_id  BIGINT NULL REFERENCES users(id),
    initiated_by_agent    VARCHAR(40) NULL REFERENCES ai_agents(code),
    approval_type          VARCHAR(60) NOT NULL,     -- 'bank_transfer','payroll_release','tax_submission', ...
    risk_level              VARCHAR(20) NOT NULL DEFAULT 'standard'
        CHECK (risk_level IN ('standard','elevated','critical')),
    summary                 TEXT NOT NULL,
    payload                 JSONB NOT NULL,           -- full proposed action, identical to what would execute on approval
    required_approver_count INTEGER NOT NULL DEFAULT 1,
    sequential               BOOLEAN NOT NULL DEFAULT false,  -- true = approvers must act in order, not any-N-of-M
    sla_due_at                TIMESTAMPTZ NULL,
    status                    VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','approved','rejected','expired','escalated')),
    resolved_at                TIMESTAMPTZ NULL,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE approval_request_approvers (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    approval_request_id  BIGINT NOT NULL REFERENCES approval_requests(id),
    user_id               BIGINT NULL REFERENCES users(id),
    role_id                BIGINT NULL REFERENCES roles(id),   -- either a named user or "anyone holding this role"
    sequence_order          INTEGER NOT NULL DEFAULT 1,
    decision                 VARCHAR(20) NULL CHECK (decision IN ('approved','rejected')),
    comment                   TEXT NULL,
    decided_at                TIMESTAMPTZ NULL,
    notified_at                TIMESTAMPTZ NULL,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_approval_requests_company_status ON approval_requests(company_id, status);
CREATE INDEX idx_approval_approvers_request ON approval_request_approvers(approval_request_id);
```

The Approval Assistant agent (see **The AI Finance Team**) owns the lifecycle of these two tables end to end — creating the request, notifying approvers, tracking `sla_due_at`, escalating on timeout to the next approver or a configured fallback role — but it has no `decision` column write access of its own; only a human `user_id` acting through their own authenticated session can populate `approval_request_approvers.decision`. This is enforced identically to every other write in the platform: through Laravel's RBAC layer, not through the AI agent's own self-restraint.

## Multi-approver and escalation example

A bank transfer above KWD 10,000 at Al-Noor Trading requires two sequential approvers under the company's configured policy: the Finance Manager, then the Owner. Treasury Manager drafts the transfer as an `ai_decisions` row (`requires_approval`, per the fixed list, regardless of its 0.97 confidence in the payment's correctness) and the Approval Assistant creates the `approval_requests` row with `required_approver_count = 2`, `sequential = true`. The Finance Manager approves within four hours; the Owner does not act within the configured 24-hour SLA, so the Approval Assistant escalates — a reminder notification through the Financial Copilot and, at 48 hours, a fallback notification to the Owner's configured delegate — without ever lowering the required-approver count or attempting the transfer at one signature. The transfer executes only once both sequential approvals are recorded, at which point Treasury Manager's original `proposed_action` (unchanged since the moment it was drafted, so the approver's review and the executed action are guaranteed identical) is submitted to `POST /api/v1/treasury/transfers`.

## Mobile and real-time approval

Because Laravel Reverb pushes state changes in real time, an approval request reaches an approver as a push notification the moment it is created, with the full `summary`, `reasoning`, and `sources` payload rendered natively — an approver on a mobile device sees the same evidentiary basis a desktop reviewer would, and can approve, reject, or request more information (which reopens the underlying `ai_decisions` row to the originating agent for clarification) without needing to be at a desk, which is precisely what keeps a well-designed approval chain from becoming its own bottleneck.

# Learning Loop

An AI Finance Team that makes the same categorization mistake every month is not autonomous, it is merely automated. The Learning Loop is the mechanism by which a human's correction to an AI proposal becomes durable behavior change for that specific company — without ever fine-tuning a shared model on one tenant's private financial data, which would risk exactly the cross-tenant leakage the platform's multi-tenancy guarantee forbids.

## What "learning" means in QAYD, precisely

QAYD's agents do not learn by adjusting shared model weights per company — that would require training infrastructure disproportionate to the signal available from any single tenant, and it would risk blending one company's patterns into the base model other companies draw on. Instead, learning is **retrieval-augmented behavioral adaptation**: every accepted, edited, or rejected AI proposal becomes a row in **Company Memory** (see next section), retrieved at inference time for that company's future decisions, so that "the AI got smarter" means, concretely, "the next relevant decision retrieves this specific prior correction as evidence and weighs it accordingly" — fully inspectable, fully reversible, and never shared outside the company that produced it.

```
 Human accepts, edits, or rejects an ai_decisions row
              │
              ▼
   ┌─────────────────────────┐
   │ Correction captured:       │
   │ what the AI proposed,       │
   │ what the human did instead,  │
   │ and (optionally) why          │
   └────────────┬─────────────┘
                ▼
   ┌─────────────────────────┐
   │ Written to ai_memory as a   │
   │ memory_type='correction'     │
   │ row, embedded for retrieval  │
   └────────────┬─────────────┘
                ▼
   Next similar decision for this company retrieves
   this correction during context assembly, and the
   agent's reasoning explicitly cites it if it changed
   the outcome
```

## Schema: capturing the correction signal

```sql
CREATE TABLE ai_corrections (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    decision_id       BIGINT NOT NULL REFERENCES ai_decisions(id),
    original_proposal JSONB NOT NULL,
    human_action      VARCHAR(20) NOT NULL CHECK (human_action IN ('accepted','edited','rejected')),
    final_value        JSONB NULL,               -- what was actually posted, if different from the proposal
    reason_given        TEXT NULL,               -- optional free-text from the approver
    corrected_by         BIGINT NOT NULL REFERENCES users(id),
    memory_id            BIGINT NULL REFERENCES ai_memory(id),  -- the derived memory row, once written
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_corrections_company_decision ON ai_corrections(company_id, decision_id);
```

## Two speeds of adaptation

**Per-company, immediate.** A single correction is retrievable for this company's next relevant decision the moment it is written — no batch retraining cycle, no delay. This is what lets a company's own vendor-coding quirk ("this specific vendor's delivery fee always goes to account 5210, not 5130, because of an internal policy") take effect on the very next bill from that vendor.

**Platform-wide, aggregated, anonymized, deliberate.** Separately, and on a much slower cadence, QAYD's platform team reviews aggregated, anonymized correction *patterns* (never raw company data, never anything traceable to a specific tenant) to identify systemic model or prompt weaknesses — for example, if OCR extraction confidence is systematically low across many companies for a specific document layout, that is a signal to improve the shared extraction prompt/pipeline itself, benefiting every tenant, but it is derived from statistical pattern aggregation, never from copying one company's memory into another's.

## Guardrails on the loop itself

- A single correction does not overwrite an agent's general behavior instantly and irreversibly; it is weighted evidence retrieved alongside other memory, so a one-off mistaken correction by a new employee does not permanently misconfigure the agent (see the recency-based suppression rule in **Autonomous Accounting**'s threshold table, which raises scrutiny rather than blindly trusting the most recent correction).
- Every correction is itself logged in `ai_logs` and remains inspectable — a company can audit not just what the AI decided, but what it *used to* decide before a specific correction changed its behavior, and when.
- A correction can be explicitly retracted by a human with the appropriate permission, which does not delete the historical `ai_corrections` row (financial-adjacent records are never hard-deleted) but marks it superseded so it stops influencing future retrieval.

## Worked example

Three months into using QAYD, Al-Noor Trading's bookkeeper edits four consecutive General Accountant proposals for a specific vendor's monthly service fee, moving it from account 5130 (Office Supplies Expense) to account 5210 (Facilities Expense) each time, always with the same note: "this vendor's fee is for the warehouse lease service charge, not supplies." After the second consistent edit, the correction's retrieval weight is high enough that the third proposal already drafts to account 5210 directly, citing "corrected by user on 2 prior occasions with consistent reasoning" in its own `reasoning` field at 0.89 confidence — the fourth month, General Accountant proposes account 5210 at 0.96 confidence, now indistinguishable in quality from a pattern the agent had known from day one, because the correction is retrieved as concretely as any other historical fact about this company.

# Company Memory

Company Memory is the persistent substrate that makes every agent's output specific to one company rather than generic accounting knowledge applied blindly — it is the mechanism referenced by "AI memory is per-company and never crosses tenants" throughout this document, and this section specifies exactly what is stored, how it is retrieved, and how its tenant isolation is enforced at the schema level, not merely by application convention.

## What is stored

| Memory type | Example | Typical source |
|---|---|---|
| Policy | "Expenses under KWD 50 do not require a receipt photo" | Explicit company configuration during onboarding or settings |
| Preference | "This company always uses FIFO costing for the Electronics category" | Inferred from consistent historical treatment, confirmed by a human |
| Approval chain | "Bank transfers over KWD 10,000 require Finance Manager then Owner, sequentially" | Explicit configuration (see **Human Approval**) |
| Vendor/customer note | "Gulf Prime Distribution Co. invoices are always zero-rated for this product category" | Derived from consistent historical tax treatment |
| Correction | "This vendor's fee belongs to account 5210, not 5130" (see **Learning Loop**) | Human edit of an AI proposal |
| FAQ / institutional knowledge | "Our fiscal year starts April 1, not January 1" | Explicit configuration, reinforced by repeated retrieval |
| Employee-specific context | "This approver typically reviews payroll on Sundays; do not expect same-day response on other days" | Inferred from approval-timing pattern, used only to calibrate SLA expectations, never to bypass the SLA itself |

## Data model

```sql
CREATE TABLE ai_memory (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    memory_type      VARCHAR(30) NOT NULL
        CHECK (memory_type IN ('policy','preference','approval_chain','vendor_note',
                                'customer_note','correction','faq','employee_context')),
    subject_type     VARCHAR(60) NULL,           -- polymorphic: 'vendors','customers','accounts', NULL for company-wide
    subject_id       BIGINT NULL,
    content          TEXT NOT NULL,               -- human-readable statement of the memory
    embedding        VECTOR(1536) NOT NULL,        -- pgvector embedding of `content` for semantic retrieval
    structured_value JSONB NULL,                   -- machine-usable form, e.g. {"account_code":"5210"}
    confidence       NUMERIC(5,4) NOT NULL DEFAULT 1.0,
    source_type      VARCHAR(30) NOT NULL
        CHECK (source_type IN ('explicit_config','inferred_pattern','correction','conversation')),
    source_ref       JSONB NULL DEFAULT '{}'::jsonb,  -- e.g. {"ai_correction_id": 881}
    usage_count      INTEGER NOT NULL DEFAULT 0,
    last_used_at     TIMESTAMPTZ NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','superseded','retracted')),
    superseded_by_id BIGINT NULL REFERENCES ai_memory(id),
    expires_at       TIMESTAMPTZ NULL,
    created_by       BIGINT NULL REFERENCES users(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at       TIMESTAMPTZ NULL
);
CREATE INDEX idx_ai_memory_company_type ON ai_memory(company_id, memory_type, status);
CREATE INDEX idx_ai_memory_subject ON ai_memory(company_id, subject_type, subject_id);
CREATE INDEX idx_ai_memory_embedding ON ai_memory USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
```

## Per-company isolation, enforced structurally

`ai_memory.company_id` is `NOT NULL` and every retrieval query — whether a structured `WHERE subject_id = ?` lookup or a vector similarity search — is scoped by the same Row Level Security policy that protects every other tenant table in the platform (see **Enterprise Architecture**), applied identically regardless of whether the querying process is a human-facing API request or the AI engine's own retrieval step:

```sql
CREATE POLICY tenant_isolation_ai_memory ON ai_memory
    USING (company_id = current_setting('app.current_company_id')::bigint);
```

There is no cross-company retrieval path in the schema at all — not a slower one, not an opt-in one. An embedding computed from Company A's vendor notes is never in the same searchable index partition as Company B's, because the vector index itself is queried through the same RLS-scoped connection as every other query. This is the concrete mechanism behind the platform fact that AI memory never crosses tenants: it is not a policy the AI agent chooses to respect, it is a boundary the database enforces regardless of what the AI process asks for.

## Retrieval

At inference time, an agent assembles context through two parallel retrieval paths: a **structured query** (exact-match lookups — this vendor's last N transactions, this account's normal-use pattern) and a **semantic query** (vector similarity search over `ai_memory.embedding` for the current situation's free-text description, surfacing relevant policies or notes that would not match on any structured key — e.g., a general policy note about how the company treats delivery fees, retrieved because it is semantically close to the current bill's content even though it does not name this specific vendor). Both paths' results are merged, deduplicated, and passed into the agent's reasoning step, and any memory row that materially influenced the output is listed in that decision's `sources` array — Company Memory is retrievable evidence, not an invisible thumb on the scale.

## Retention and review

Memory rows do not accumulate forever unchallenged. `usage_count` and `last_used_at` drive a periodic review: a memory row unused for a long, company-configurable window is surfaced to a human as a candidate for confirmation or retirement (a policy from a prior fiscal year, a vendor relationship that ended) rather than silently continuing to influence decisions after it may have gone stale. `expires_at` allows time-bound memory (a temporary approval-chain delegation while an approver is on leave) to lapse automatically rather than requiring a human to remember to revoke it.

## Worked example

When Al-Noor Trading's Owner tells the Copilot, in passing during an unrelated conversation, "by the way, going forward we're not taking early-payment discounts from Gulf Prime anymore, cash flow is fine and I'd rather keep the float," the CEO Assistant agent writes an `ai_memory` row (`memory_type = 'vendor_note'`, `subject_type = 'vendors'`, `subject_id` = Gulf Prime's record, `source_type = 'conversation'`) rather than letting the instruction evaporate at the end of the chat turn. The next time Treasury Manager evaluates whether to recommend taking that vendor's early-payment discount (see **Recommendation Engine**), the retrieval step surfaces this note, and the recommendation is suppressed with an explicit citation back to the conversation it came from — institutional knowledge captured once, in passing, in natural language, and durably applied thereafter.

# Enterprise Architecture

This section specifies how every mechanism described so far assembles into one running system, end to end — the physical components, their responsibilities, the exact path a request takes from a document arriving to a number changing on a dashboard, and the security boundaries that make the whole thing safe to operate for money that is not QAYD's own.

## Component map

| Layer | Technology | Responsibility |
|---|---|---|
| Frontend | Next.js 15, React 19, TypeScript (strict), Tailwind CSS, shadcn/ui, Framer Motion | Renders dashboards, the Financial Copilot chat surface, approval inboxes; holds no business logic |
| Backend (the kernel) | Laravel 12, PHP 8.4+ | Single source of truth for business/financial logic; Controller → FormRequest → Service → Repository → Model; owns the database exclusively |
| AI Layer | FastAPI + Python | LangGraph-style multi-agent orchestration; MCP tool layer; OCR pipeline; vector retrieval; never holds direct database credentials to tenant data |
| Database | PostgreSQL | ACID-consistent system of record; Row Level Security for tenant isolation; `pgvector` for embeddings; JSONB for reasoning/context payloads |
| Cache / Queue / AI working memory | Redis | Session cache, job queues, AI short-term working-memory cache, rate-limit counters |
| Object storage | Cloudflare R2 | Source documents (scanned bills, statements, contracts) that every citation in this document ultimately resolves to |
| Realtime | Laravel Reverb (WebSockets) | Pushes domain events and notifications (approval requests, health-score changes, forecast alerts) to connected clients instantly |
| Auth | Laravel Sanctum + JWT bearer tokens | Authenticates humans and service accounts (including the AI engine's own scoped tokens) identically at the API boundary |

## The request lifecycle, end to end

Every AI-initiated action in QAYD — regardless of which of the fifteen agents produces it — follows the same path from trigger to visible effect:

```
 1. TRIGGER
    Document uploaded / bank feed synced / schedule fires / user asks the Copilot
                          │
                          ▼
 2. AI ENGINE (FastAPI)
    Orchestrator (LangGraph) routes to specialist agent(s)
    Agent retrieves context: structured queries + Company Memory (vector + structured)
    Agent reasons, drafts a proposed_action, computes confidence
    Decision Engine computes autonomy_applied (auto / suggest_only / requires_approval)
                          │
                          ▼
 3. MCP TOOL CALL
    Typed function call, one-to-one mapped to a Laravel endpoint + permission key
    Authenticated as the requesting user's session OR a narrowly-scoped service token
                          │
                          ▼
 4. LARAVEL API (the kernel)
    FormRequest validation (types, ranges, business-rule preconditions)
    Policy/RBAC check — identical whether the caller is human or AI
    IF requires_approval: create approval_requests row, STOP here until resolved
    IF auto or (suggest_only AND human just accepted): proceed
                          │
                          ▼
 5. SERVICE → REPOSITORY → MODEL
    Business logic executes (e.g. validate journal balances, check period is open)
    Single DB transaction; all-or-nothing
                          │
                          ▼
 6. POSTGRESQL (the source of truth)
    Row(s) written; audit_logs row written in the same transaction
    ai_decisions.status updated (auto_executed / approved / accepted)
                          │
                          ▼
 7. DOMAIN EVENT EMITTED
    e.g. journal_entry.posted, invoice.paid, payroll.completed
                          │
                          ▼
 8. LARAVEL REVERB
    Pushes the event to subscribed clients over WebSocket
                          │
                          ▼
 9. NEXT.JS FRONTEND
    Dashboard/Copilot updates in real time, rendering confidence + reasoning + sources
```

No step in this lifecycle allows the AI engine to skip step 4 — this is the concrete, inspectable meaning of "every AI action goes through the Laravel API and its validation + permission checks," and it is true identically for a KWD 4.500 expense categorization and a KWD 40,000 bank transfer; only the `autonomy_applied` value computed in step 2 differs, and even at `auto` the full validation and RBAC check in step 4 still executes — `auto` skips the *human approval wait*, never the *validation and permission check*.

## Security boundaries

```
┌───────────────────────────────────────────────────────────────────────┐
│  Next.js Frontend  (no direct DB access, no business logic)             │
└───────────────────────────┬───────────────────────────────────────────┘
                             │ HTTPS, Bearer (Sanctum/JWT)
┌───────────────────────────▼───────────────────────────────────────────┐
│  Laravel 12 API  — THE ONLY COMPONENT WITH DATABASE WRITE CREDENTIALS   │
│  ┌───────────────────────────────────────────────────────────────┐    │
│  │ FormRequest validation │ Policy/RBAC │ Service │ Repository     │    │
│  └───────────────────────────────────────────────────────────────┘    │
└───────────────────────────┬───────────────────────────────────────────┘
                             │ Eloquent + RLS session variable
┌───────────────────────────▼───────────────────────────────────────────┐
│  PostgreSQL  — company_id NOT NULL + Row Level Security on every table  │
└─────────────────────────────────────────────────────────────────────────┘
                             ▲
                             │ same HTTPS/Bearer boundary as the frontend,
                             │ scoped service-account token, no elevated path
┌───────────────────────────┴───────────────────────────────────────────┐
│  FastAPI AI Engine  — NO DATABASE CREDENTIALS TO TENANT SCHEMA          │
│  ┌───────────────────────────────────────────────────────────────┐    │
│  │ LangGraph orchestrator │ MCP tool layer │ Vector retrieval      │    │
│  │ (Redis-backed working memory; reads/writes only via Laravel)    │    │
│  └───────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────┘
```

The diagram's central claim is structural, not procedural: the FastAPI process is drawn *outside* the boundary that has database credentials, on purpose, because a procedural rule ("the AI agent should not write to the database") is a policy that a bug or a future refactor could violate, while a structural rule ("the AI process's database driver has no connection string to the tenant schema, only an HTTPS bearer token to Laravel") cannot be violated without a deliberate, reviewable infrastructure change.

## Multi-tenancy at the infrastructure level

Every business table carries `company_id BIGINT NOT NULL REFERENCES companies(id)`, indexed, with a Row Level Security policy scoping every query to `current_setting('app.current_company_id')`. This applies without exception to every AI-specific table introduced in this document — `ai_tasks`, `ai_decisions`, `ai_memory`, `ai_logs`, `ai_conversations`, `ai_messages`, `ai_recommendations`, `ai_corrections`, `approval_requests` — because a security boundary that protects `journal_entries` but not `ai_memory` would leave the exact channel this document spends the most words describing as the one channel where Company A's data could leak into Company B's agent context. `ai_agents` itself is the sole exception by design, being a platform-wide catalog with no tenant data in it at all (see **The AI Finance Team**).

## The append-only action log

```sql
CREATE TABLE ai_logs (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    agent_code    VARCHAR(40) NOT NULL REFERENCES ai_agents(code),
    task_id       BIGINT NULL REFERENCES ai_tasks(id),
    decision_id   BIGINT NULL REFERENCES ai_decisions(id),
    event_type    VARCHAR(60) NOT NULL,         -- 'prompt_executed','tool_called','api_request','api_response', ...
    payload       JSONB NOT NULL,               -- prompt/response pair, tool call args/result, or API request/response
    model_profile VARCHAR(60) NULL,
    tokens_input  INTEGER NULL,
    tokens_output INTEGER NULL,
    latency_ms    INTEGER NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
) PARTITION BY RANGE (created_at);
-- Partitioned monthly via pg_partman; never updated, never deleted (append-only), backing full explainability audits.
CREATE INDEX idx_ai_logs_company_created ON ai_logs(company_id, created_at DESC);
CREATE INDEX idx_ai_logs_decision ON ai_logs(decision_id) WHERE decision_id IS NOT NULL;
```

`ai_logs` is deliberately more granular than `ai_tasks`: a single task can involve several tool calls, several retrieval steps, and several intermediate model invocations, each of which is captured here. This is what lets an engineer — or an external auditor granted read access to a company's own audit trail — answer not just "what did the AI decide" (`ai_decisions`) but "exactly how did it get there, what did it retrieve, what did it call, and how long did it take," down to the individual tool invocation, for as long as the company's retention policy keeps that partition online.

## Deployment posture

The AI engine and the Laravel backend are deployed as independently scalable services — the AI engine's workload (LLM inference latency, OCR processing, embedding computation) has a fundamentally different resource profile than the backend's (low-latency CRUD, transactional writes), and coupling their deployment would force one to be over- or under-provisioned relative to the other. Both scale horizontally behind their own load balancers; PostgreSQL remains the single stateful component or, at larger scale, a primary with read replicas serving reporting and AI-retrieval traffic specifically so that `ai_memory` vector searches never compete with `journal_entries` posting for the same I/O (the same reasoning that drives read-replica routing for reporting traffic generally applies with additional force to the AI engine's retrieval-heavy access pattern).

# Comparison: SAP

SAP — R/3 historically, S/4HANA today — is the archetype of the ERP category this document describes in **Why ERP Is Obsolete**: a deep, exhaustively configurable system of record built for large, complex, often multinational organizations with the budget and internal staff to run a multi-year implementation. SAP's genuine strength is breadth and depth of coverage across industries and geographies few competitors match, and large manufacturing, logistics, and multinational enterprises with complex intercompany structures are frequently well served by it, especially where the buyer already has (or is willing to build) a large internal team of SAP-certified administrators. SAP has, in recent years, layered generative-AI features (marketed under the Joule name) onto S/4HANA — natural-language query and drafting assistance bolted onto the existing transactional core.

| Dimension | SAP S/4HANA | QAYD |
|---|---|---|
| Core abstraction | System of record; human-driven transactional entry | AI Financial Operating System; agent-proposed, human-approved action |
| AI integration | Generative-AI assistant layered onto a pre-existing relational core | AI-native from the schema up — `ai_decisions`, `ai_memory`, confidence/reasoning are first-class, not add-ons |
| Implementation model | Multi-year, consultant-led configuration; high services-to-license cost ratio | Self-configuring via agent inference over the company's own history, human-approved (see **Learning Loop**) |
| Target company size | Large enterprise, multinational, complex intercompany structures | SME through mid-market today; enterprise-scale roadmap (see **Future Roadmap**) |
| Autonomy model | Workflow automation is rules-based (deterministic approval routing); no confidence-gated autonomous execution native to the core | Confidence-gated autonomy per action class, uniform across all fifteen agents (see **Decision Engine**) |
| Continuous audit / continuous close | Add-on GRC and close-management modules exist but are configured as periodic processes by convention | Native, daily, full-population (see **Continuous Audit**, **Autonomous Closing**) |
| Gulf/Kuwait localization | Available via partner-built localization packs, at additional cost and lead time | Native KWD base currency, Arabic/English bilingual, GCC VAT and PIFSS/WPS support built into the platform from day one |

The honest case for choosing SAP over QAYD today is scale and industry-specific depth: a multinational manufacturer with dozens of legal entities, complex intercompany elimination rules, and industry-specific SAP modules built over two decades has requirements QAYD's current roadmap does not yet claim to match feature-for-feature. The honest case for QAYD is that for the overwhelming majority of companies — including most SAP customers below the very top of the enterprise segment — the AI-native architecture delivers continuous close, continuous audit, and autonomous transaction processing that a rules-based core with a chat assistant bolted on top cannot retrofit into being, because the retrofit inherits a schema and a workflow engine designed before any of this document's central claims were possible to build.

# Comparison: Oracle

Oracle's financial-management offerings — Oracle Fusion Cloud ERP for larger enterprises, NetSuite for the mid-market it acquired into its portfolio — share SAP's core lineage: a mature, broad, deeply configurable system of record, with Oracle's own generative-AI features (branded across its Fusion Applications suite) added as an assistive layer over the existing transactional model. Oracle's genuine strength is a strong, unified cloud data platform story and broad applications coverage (HR, supply chain, CX) alongside financials, which appeals to enterprises consolidating vendors.

| Dimension | Oracle Fusion Cloud ERP / NetSuite | QAYD |
|---|---|---|
| Core abstraction | System of record, unified cloud applications suite | AI Financial Operating System |
| AI integration | Embedded AI/ML features for anomaly detection and forecasting, assistive to human-run processes | Autonomous agents that execute within confidence-gated autonomy, not merely assistive |
| Data model exposure | Proprietary, largely opaque to the customer's own engineering team | Canonical, documented schema (this document set) an engineer can read and extend |
| Approval workflow | Configurable rules engine, mature and flexible | Confidence + reversibility + impact-driven autonomy determination (see **Decision Engine**), layered on top of a configurable approval chain |
| Pricing model | Per-user/module enterprise licensing, typically with implementation partner cost on top | SaaS pricing scaled to company size, no mandatory implementation-partner dependency |
| Continuous monitoring | Point-in-time and scheduled reporting; some real-time dashboards in newer releases | Continuous by default across audit, compliance, cash flow, and risk (this document's eleven **Continuous**/**Autonomous** sections) |
| Regional focus | Global, enterprise-first | Gulf-first (KWD, Arabic, GCC VAT, PIFSS/WPS), globally extensible roadmap |

Oracle's products are credible, capable systems for a large enterprise finance function that already has the internal headcount to administer them and the transaction volume to justify Oracle's enterprise pricing. QAYD's differentiation is architectural rather than a claim of broader feature coverage: an autonomous agent workforce operating under a uniform confidence-and-approval framework is a different category of system than a mature rules engine with AI features added for assistance, and that difference compounds specifically in the areas — continuous close, continuous audit, proactive cash-flow alerting — where Oracle's underlying core was not originally built to operate continuously.

# Comparison: Microsoft Dynamics

Microsoft Dynamics 365 Finance sits inside the broader Microsoft ecosystem — Power Platform, Power BI, Copilot for Microsoft 365 — which is its principal strength: a company already standardized on Microsoft 365 gets a financials product that shares identity, data connectors, and a low-code extensibility story (Power Automate, Power Apps) with tools its staff already uses. Microsoft has invested heavily in Copilot-branded generative-AI features across Dynamics 365, following the same industry pattern as SAP and Oracle: AI assistance layered onto an existing ERP core.

| Dimension | Microsoft Dynamics 365 Finance | QAYD |
|---|---|---|
| Core abstraction | System of record, deeply integrated with the Microsoft productivity and low-code ecosystem | AI Financial Operating System |
| AI integration | Copilot assistance for drafting, summarization, and Q&A over Dynamics data | Autonomous, confidence-gated agents that draft, execute (within policy), and continuously monitor |
| Extensibility model | Power Platform low-code (Power Automate flows, Power Apps) built by the customer or a partner | Agent behavior and thresholds configured via `ai_agent_settings`; new capability ships as platform updates, not customer-built automations |
| Best fit | Mid-market to enterprise companies already standardized on Microsoft 365 | SME to mid-market companies wanting an AI-native finance function without needing to build automations themselves |
| Workflow automation | Power Automate flows — deterministic, customer-built, RPA-adjacent | Reasoning agents with citations and confidence, not scripted flows (see **Why ERP Is Obsolete**, failure mode 5) |
| Localization for Gulf/Kuwait | Available through regional partners and localization SKUs | Native from day one |

Dynamics' low-code extensibility is a genuine advantage for a company with the internal capacity to build and maintain Power Automate flows tailored to its own processes — that flexibility, however, is precisely the "scripted, not reasoned" automation model this document's **Why ERP Is Obsolete** section identifies as brittle: a Power Automate flow built against last year's invoice template does not adapt when the template changes, where QAYD's Document AI and OCR Agent reason over document structure directly. For a company that would rather have an AI workforce that adapts to its own transaction history than a low-code platform it must program itself, the two products solve different problems even where their feature checklists overlap.

# Comparison: Odoo

Odoo is the most architecturally interesting comparison in this list, because it shares one of QAYD's instincts — modular, unbundled, SME-accessible software — while arriving at nearly the opposite core abstraction. Odoo's open-source, modular app-store model (accounting, inventory, sales, and dozens of other apps installed à la carte) makes it dramatically cheaper to start with than SAP, Oracle, or Dynamics, and its community and enterprise editions have real traction with cost-conscious SMEs and startups, including in the Gulf region. Odoo has also begun shipping AI-assisted features in newer versions, generally aimed at simplifying data entry and providing summarization, consistent with the industry-wide pattern of AI as an add-on rather than a redesign of the core.

| Dimension | Odoo | QAYD |
|---|---|---|
| Core abstraction | Modular open-source system of record, à la carte apps | AI Financial Operating System, unified agent workforce across modules |
| Cost structure | Low entry cost (community edition free/open-source; enterprise edition priced per app/user) | SaaS pricing scaled to company size; cost driver is AI inference + orchestration, not per-module licensing |
| Customization | Direct code-level customization (Python/XML), often requiring developer resources | Agent-inferred configuration from the company's own history; code-level extension remains available at the platform-engineering layer, not required for day-to-day adaptation |
| AI integration | Assistive features for data entry and summarization in newer releases | Autonomous agents with confidence-gated execution across accounting, treasury, audit, tax, compliance, and forecasting |
| Continuous close/audit | Manual/periodic, as in classic ERP | Native and continuous (see **Autonomous Closing**, **Continuous Audit**) |
| Gulf/Kuwait fit | Available via community/partner localizations | Native, purpose-built for the region from the platform's foundation |

Odoo's genuine advantage is cost and flexibility for a technically capable team willing to configure and, where needed, extend the platform directly — it is a strong choice for a company that wants control over its own source code and does not need continuous, AI-driven autonomy. QAYD's differentiation is that it delivers the SME-accessible cost profile Odoo pioneered while replacing "cheap because it's simple" with "efficient because an AI workforce does the operational work a human would otherwise configure Odoo, module by module, to approximate."

# Comparison: QuickBooks

QuickBooks (Intuit) is the dominant small-business and micro-business bookkeeping tool globally, and it is worth comparing honestly rather than dismissively: for a sole proprietor or a very small business with simple transaction volume, QuickBooks' simplicity, low cost, and enormous accountant/bookkeeper familiarity are real, legitimate advantages that a more architecturally ambitious platform should not pretend don't matter to that segment. Intuit has introduced its own AI assistant (Intuit Assist) for categorization suggestions and Q&A within the product, again following the assistive-layer pattern common across the category.

| Dimension | QuickBooks | QAYD |
|---|---|---|
| Target company size | Sole proprietor to small business | SME to mid-market, with an enterprise roadmap |
| Multi-tenancy / multi-entity | Limited; multi-entity consolidation is a separate, often clunky add-on workflow | Native multi-tenant architecture with full company isolation from the schema up |
| Double-entry rigor at scale | Adequate for simple books; can become unwieldy with complex intercompany or multi-currency needs | Full double-entry, multi-currency, dimensioned (cost center/project/department) ledger designed for growth |
| AI depth | Categorization suggestions, assistant Q&A | Fifteen specialized autonomous agents spanning accounting, audit, tax, payroll, treasury, compliance, and forecasting |
| Continuous audit/compliance | Not a native capability | Core capability (see **Continuous Audit**, **Continuous Compliance**) |
| Approval chains / RBAC depth | Basic user roles; not built for multi-approver, risk-tiered sensitive-operation gating | Full RBAC + multi-approver, sequential, SLA-driven approval chains (see **Human Approval**) |
| Gulf/Kuwait fit | Not a primary market; limited KWD/GCC-specific support | Native | 

The honest assessment is that QuickBooks solves a real problem extremely well for the segment it targets, and a one-person business with a handful of transactions a month may not need — and would not benefit from paying for — an autonomous fifteen-agent finance workforce. QAYD's target is the company that has outgrown that simplicity: multiple employees, inventory, payroll, multi-currency exposure, or simply enough transaction volume that continuous, AI-driven bookkeeping produces a return that a spreadsheet-adjacent tool cannot.

# Comparison: Xero

Xero occupies similar territory to QuickBooks — small-business cloud accounting — with a particular reputation for clean UX and strong bank-feed connectivity, and a genuinely well-regarded accountant/bookkeeper ecosystem, especially outside the United States. Xero has introduced AI-assisted features (including a natural-language assistant referred to as JAX in some markets) for search and task assistance within its product, again an assistive layer rather than an autonomous workforce.

| Dimension | Xero | QAYD |
|---|---|---|
| Core strength | Clean UX, strong bank-feed integrations, accountant-ecosystem trust | AI-native autonomous operation across the full finance function |
| Bank reconciliation | Bank-feed import with rules-based and ML-assisted suggested matching | Confidence-scored, tiered matching (deterministic, fuzzy, exception queue) as one of fifteen agents' continuous mandate (see **Autonomous Reconciliation**) |
| Reporting | Standard financial reports, reasonably fast | Standard + AI-narrated custom reports on demand via natural language (see **Autonomous Reporting**) |
| Forecasting | Basic cash-flow projection tools in some plans | 13-week rolling forecast with confidence intervals, scenario simulation (see **Continuous Cash Flow Monitoring**, **Simulation Engine**, **Predictive Finance**) |
| Payroll (Gulf-specific) | Not a native strength outside its core markets (UK/AU/NZ/US-centric payroll) | Native PIFSS/WPS support for Kuwait |
| Autonomy / approval architecture | Standard user permissions; no confidence-gated autonomous execution or structured decision engine | Full **Decision Engine** + **Human Approval** architecture |

Xero's UX quality is a legitimate bar QAYD's own Next.js frontend is held to — a clean, fast interface is not a distinguishing feature reserved for AI-native platforms, and QAYD does not claim otherwise. The distinguishing claim is entirely about what happens beneath that interface: Xero's bank-feed matching and reporting are good implementations of a system-of-record's traditional capabilities; QAYD's equivalent capabilities are one output of an underlying autonomous agent workforce that also closes books, audits continuously, tracks tax exposure, and forecasts cash — capabilities Xero, like QuickBooks, does not attempt to provide natively because its core architecture was not built around a reasoning, proposing, confidence-scored AI layer.

# Future Roadmap

QAYD's roadmap is organized in three horizons, each defined by a change in autonomy posture and organizational reach rather than by a calendar date, because the correct pace of the transition from "assisted" to "autonomous" is set by evidence — a company's own `ai_prediction_outcomes` track record, its accumulated **Company Memory**, its comfort widening `ai_agent_settings` thresholds — not by a fixed schedule.

## Horizon 1 — Assisted autonomy (current)

The state specified throughout this document: fifteen agents operating with conservative platform-default thresholds, the majority of high-value actions routed `suggest_only`, the sensitive-operations list strictly `requires_approval`, and per-company thresholds widening only as **Learning Loop** evidence accumulates. The near-term engineering roadmap within this horizon:

- **Deeper OCR generalization** across document layouts and handwriting quality common in Gulf-region paper trails (delivery notes, informal receipts), reducing the rate of low-confidence field extraction that currently routes to human review.
- **Expanded Simulation Engine scenario library** — additional pre-built scenario types (multi-year loan amortization impact, seasonal inventory build financing) beyond the six described in **Simulation Engine**.
- **Approval Assistant mobile-first redesign** — richer push-notification approval cards so that more of the **Human Approval** chain's latency is spent waiting on a decision, not on an approver finding the request.
- **Compliance Agent coverage expansion** to additional GCC jurisdictions' tax and labor frameworks as customers expand beyond Kuwait.

## Horizon 2 — Exception-based supervision

As a company's per-agent confidence calibration (see **Predictive Finance**'s reconciliation-against-actuals mechanism, and **Learning Loop**'s correction-frequency decay) demonstrates sustained accuracy, the default posture shifts from "review most things, act on the rest" to "act on most things, review the exceptions" — operationally, this means:

- Raising default `auto` ceilings for well-established transaction classes (recurring vendor bills, routine bank matching) company-by-company, evidence-gated rather than platform-wide, so a company with six months of clean track record is not held to the same conservative defaults as a brand-new tenant.
- A unified **exception inbox** across all fifteen agents — rather than reviewing General Accountant's drafts, Auditor's findings, and Fraud Detection's flags as separate streams, a single ranked queue of everything that needs a human's judgment, ordered by the same priority-scoring logic as the **Recommendation Engine**.
- Cross-agent consistency checks becoming proactive rather than reactive — e.g., the CFO agent flagging when two agents' independent confidence assessments of the same underlying fact (a vendor's reliability, a customer's creditworthiness) diverge, before either individually crosses a threshold worth a human's attention.

## Horizon 3 — Enterprise and ecosystem scale

The longer-range direction in which QAYD's architecture (multi-tenant from the schema up, agent roster shared platform-wide, per-company memory strictly isolated) is designed to extend without a rearchitecture:

- **Multi-entity consolidation AI** — a CFO agent instance that reasons across a group's related companies (each still fully isolated at the data layer) to produce consolidated financial statements and intercompany elimination proposals, extending **Autonomous Closing** to group level.
- **Industry-benchmarked, privacy-preserving comparative intelligence** — aggregated, anonymized cross-tenant statistical patterns (never raw data, following the exact **Learning Loop** discipline already in place for platform-wide model improvement) offered back to companies as opt-in context: "your gross margin trend is diverging from other companies in your sector," without any company's specific figures ever being visible to another.
- **Agentic bank and vendor negotiation** — extending the Recommendation Engine's "shift this payment by three days" suggestions toward AI-drafted (always human-approved, per the fixed sensitive-operations list) negotiation proposals: requesting extended terms from a vendor, or evaluating a bank's financing offer against the company's own simulated scenarios.
- **Voice-native Financial Copilot** — extending the conversational surface described in **Financial Copilot** to voice, particularly relevant to the senior-operator and on-the-move-owner segments common among QAYD's Gulf SME customer base.
- **Schema-per-tenant isolation as a paid enterprise tier**, for regulated or very large customers whose compliance requirements call for physical namespace separation beyond the row-level isolation this document specifies as the default (consistent with the platform's database architecture, which reserves this tier without assuming its existence in current code).

Across all three horizons, one constraint never moves: the sensitive-operations list in **Human Approval** does not shrink as autonomy expands elsewhere. Horizon 3's agentic negotiation drafts a proposal; it does not sign a contract. Horizon 2's exception-based supervision widens which transaction classes reach `auto`; it does not touch the fixed list. The roadmap is a roadmap for how much routine judgment an AI workforce earns the right to exercise unsupervised — never a roadmap for removing the human from the decisions this document defines as irreducibly human.

# End of Document
