# 05 — AI Platform — QAYD PRD
Version 1.0
Status: Source of Truth
Module: PRD
Submodule: AI_PLATFORM
---

# Why This Is The Center Of The Product

Every other chapter of this PRD describes a competent, correct, bilingual accounting platform. This
chapter describes the reason QAYD is a different category of product rather than a better version of
an existing one. The thesis is simple to state and hard to build: a **workforce of specialized AI
agents does the mechanical finance work continuously, and a human approves the decisions that move
money or carry legal weight** — all on top of a correct double-entry ledger the AI can propose to but
can never quietly rewrite. Incumbent tools are *systems of record*: a human does the work and the
software stores it. QAYD is a *system of action*: the software does the work and a human supervises
it. That inversion is the whole product, and this chapter is its specification at PRD altitude.

This chapter expands and routes to the AI specification set, which is the deepest body of design in
the repository. The governing document is
[../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) (the AI Finance Operating System and the
fifteen-agent thesis); the home-screen surface is
[../../ai/AI_COMMAND_CENTER.md](../../ai/AI_COMMAND_CENTER.md); the per-agent mandates live under
[../../ai/agents/](../../ai/agents/); memory under [../../ai/memory/](../../ai/memory/); the tool,
prompt, and workflow contracts under [../../ai/tools/](../../ai/tools/),
[../../ai/prompts/](../../ai/prompts/), and [../../ai/workflows/](../../ai/workflows/). This chapter
does not restate their schemas; it states, at requirements altitude, *what the AI platform is, what
it may and may not do, and the contract that makes "autonomous" safe.*

## The Four Pillars

The AI Finance OS rests on four pillars, and every capability in this chapter is an application of
them ([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)):

1. **A workforce, not a chatbot.** Fifteen named, specialized agents with distinct mandates and
   distinct autonomy ceilings — not one general assistant answering questions.
2. **A correct ledger underneath.** The AI reasons over and proposes to a real double-entry core; it
   never "guesses numbers" without an auditable ledger behind them (Chapter
   [06_ACCOUNTING.md](./06_ACCOUNTING.md)).
3. **Supervised autonomy.** The AI acts continuously within scoped, logged, reversible limits, and a
   human approves everything consequential — permanently, by design, not as a temporary limitation.
4. **Explainability as an output.** Every AI proposal carries a confidence score, plain-language
   reasoning, and fetchable source citations. The AI never says "trust me."

# The Core Contract: Draft, Never Auto-Commit The Consequential

Before any agent, panel, or engine is described, the contract that governs all of them must be fixed,
because it is what lets the rest of this chapter use the word "autonomous" without being reckless.
The contract has four clauses, each already load-bearing in the specification set and each restated
here as a product requirement.

**1. The AI drafts; a human disposes of the consequential.** Agents draft journal entries, match
bank lines, extract document fields, compute tax positions, and prepare reports. For low-risk,
well-understood, previously-seen work below a company-configured confidence-and-amount threshold, a
draft may post automatically. Above that threshold — or for anything on the fixed sensitive-operations
list — the draft is a one-click suggestion or a filed approval, never an execution. **Money movement,
payroll release, tax submission, period close, permission changes, and deleting posted data are
always human-approved regardless of confidence.** A 0.99-confidence bank transfer is still an
approval, not an auto-execution.

**2. Every output is explainable and auditable.** Per the confidence-and-reasoning contract in
[../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md), every proposal carries a numeric `confidence`,
plain `reasoning` written in the register a competent junior accountant would use, and `sources` that
resolve to concrete fetchable records — a stored document in Supabase Storage, a specific prior transaction, or a
named historical pattern with its sample size disclosed — never a vague "based on general accounting
knowledge." This is what makes the output defensible to an external auditor or a bank.

**3. The AI uses the same front door as everyone else.** The FastAPI engine holds no ledger and no
database credentials to any tenant schema. Every AI write is a proposal submitted through the same
Laravel `/api/v1` API, the same validation, and the same RBAC check a human action passes. The AI
cannot post an unbalanced entry, backdate into a closed period, or read another company's data,
because the kernel enforces those invariants independent of who is asking
([../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md),
[../../security/AI_SECURITY.md](../../security/AI_SECURITY.md)).

**4. The AI gets smarter per-company, never across companies.** AI memory, tasks, decisions, and
conversations are tenant-scoped by `company_id` and Row-Level Security. A human correction improves
that company's own coding and extraction over time; one company's patterns never leak into another's.
The product gets better the longer a company uses it, without ever compromising isolation
([../../ai/memory/COMPANY_MEMORY.md](../../ai/memory/COMPANY_MEMORY.md)).

These four clauses are not aspirations enforced by the agents' good behavior. They are enforced
structurally — by the absence of a database driver in the AI process, by a shared autonomy resolver
that no agent can bypass, and by an approval chain only a human session can satisfy. The remainder of
this chapter describes what the platform does *within* this contract.

# The AI Command Center: The Home Screen Of A Workforce

The AI Command Center is where a human meets the workforce each morning, and it is the clearest
expression of the system-of-action premise: **by the time a human opens QAYD, the work has already
happened** ([../../ai/AI_COMMAND_CENTER.md](../../ai/AI_COMMAND_CENTER.md)). Overnight and through
the prior business day, the fifteen agents have read every posted transaction, every bank feed, every
open invoice and bill, every payroll cycle and tax deadline, and produced their findings as
confidence-scored, reasoned, cited rows. The Command Center's only job is to answer, the instant a
human looks, the three questions QAYD's design system commits every dashboard to: **What happened?
What needs attention? What should I do next?** — and to make each answer actionable in one click, one
approval, or one spoken question.

Every widget renders a shared **Command Card** payload — headline, narrative, `confidence_score`,
`status` (`ok | info | warning | critical`), the `agent_code` that produced it, its `sources`, and
its `actions` — nested in the standard API envelope. No card shows a bare number the AI computed
without showing its work. The panels group into four layers, all specified in
[../../ai/AI_COMMAND_CENTER.md](../../ai/AI_COMMAND_CENTER.md):

| Layer | Representative panels | What it answers |
|---|---|---|
| Situational awareness | Morning Briefing, Business Health Score, Cash Flow Status, Revenue/Expense Trends, AI Insights, Detected Risks, Fraud Alerts, Payroll/Inventory/Supplier/Customer risks, Upcoming Tax Deadlines | What happened / what needs attention |
| Decision | Financial Forecast, AI Recommendations, Approval Center, Automation Center | What the AI proposes and where the human gate sits |
| Interaction | Today's Tasks, Urgent Actions, Ask AI, Voice Assistant, Natural-Language Queries, Simulation Panel, Decision Support | How a human questions and directs the workforce |
| Surface | KPIs, Customization, Mobile Experience, Widgets, Layout | Configuration and the data contract every widget conforms to |

Crucially, the Command Center is not a superuser view: every widget's endpoint enforces the same RBAC
key a direct API call would, so an Owner and a Sales Employee looking at the same company on the same
morning see structurally different dashboards, and a time-boxed External Auditor sees only a
read-only slice. The Command Center reads facts Laravel already validated and authorized; the
**Approval Center** is the literal, visible embodiment of the human gate, and no panel auto-executes a
sensitive action no matter how high its confidence.

# The Agent Roster

QAYD ships fifteen named, specialized agents. The roster is **canonical and platform-wide** — every
company gets the same fifteen, provisioned at onboarding with the same names, the same default
autonomy, and the same escalation graph. What differs per company is *configuration* (thresholds,
enabled/disabled, which human roles receive which escalations) and *memory* (each agent's retrieved
context is entirely company-specific), never the roster itself. A company does not "install the CFO
agent"; it inherits it, tuned to zero history, and watches it get sharper
([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)).

| Agent | Category | Primary mandate | Default autonomy | Escalates to | Deep spec |
|---|---|---|---|---|---|
| **General Accountant** | Core accounting | Drafts/posts routine journals; categorizes expenses; maintains the day-to-day ledger | `auto` below threshold, else `suggest_only` | Auditor, CFO | [../../ai/agents/ACCOUNTANT_AGENT.md](../../ai/agents/ACCOUNTANT_AGENT.md) |
| **Auditor** | Assurance | Continuous control testing; flags anomalies; never posts | `suggest_only` (always) | CFO, Fraud Detection | [../../ai/agents/AUDITOR_AGENT.md](../../ai/agents/AUDITOR_AGENT.md) |
| **Tax Advisor** | Compliance | Computes VAT/WHT, drafts filings, tracks exposure | `suggest_only`; `requires_approval` to submit | Compliance, CFO | [../../ai/agents/TAX_AGENT.md](../../ai/agents/TAX_AGENT.md) |
| **Payroll Manager** | Payroll | Calculates runs; prepares PIFSS/WPS files | `suggest_only`; `requires_approval` to release | CFO, Approval Assistant | [../../ai/agents/PAYROLL_AGENT.md](../../ai/agents/PAYROLL_AGENT.md) |
| **Inventory Manager** | Operations | Values stock; proposes reorders; reviews adjustments | `auto` for valuation, else `suggest_only` | General Accountant | [../../ai/agents/INVENTORY_AGENT.md](../../ai/agents/INVENTORY_AGENT.md) |
| **Treasury Manager** | Treasury | Cash positioning, bank reconciliation, liquidity | `auto` for matching, `requires_approval` for transfers | CFO, Approval Assistant | [../../ai/agents/TREASURY_AGENT.md](../../ai/agents/TREASURY_AGENT.md) |
| **Banking** | Treasury | Bank feeds, statement ingestion, payment rails | `auto` for matching, human-gated for money movement | Treasury Manager | [../../ai/agents/BANKING_AGENT.md](../../ai/agents/BANKING_AGENT.md) |
| **Sales** | Operations | Order-to-cash: quotes, invoices, receipts, AR aging | `suggest_only` for outbound; `auto` for drafts | General Accountant, CFO | [../../ai/agents/SALES_AGENT.md](../../ai/agents/SALES_AGENT.md) |
| **Purchasing** | Operations | Procure-to-pay: bills, POs, three-way match | `auto` below threshold, else `suggest_only` | General Accountant | [../../ai/agents/PURCHASING_AGENT.md](../../ai/agents/PURCHASING_AGENT.md) |
| **CFO** | Executive | Synthesizes cross-agent signal into a health view; ranks recommendations | `suggest_only` (advisory only) | Human CFO / Owner | [../../ai/agents/CFO_AGENT.md](../../ai/agents/CFO_AGENT.md) |
| **Fraud Detection** | Assurance | Anomaly scoring, duplicate/ghost-vendor detection, velocity checks | `auto`-flag (freeze only, reversible); `suggest_only` for action | Auditor, CFO, Compliance | [../../ai/agents/FRAUD_AGENT.md](../../ai/agents/FRAUD_AGENT.md) |
| **Compliance** | Compliance | Regulatory-change monitoring; maps changes to affected transactions | `suggest_only` | Tax Advisor, CFO | [../../ai/agents/COMPLIANCE_AGENT.md](../../ai/agents/COMPLIANCE_AGENT.md) |
| **CEO Assistant** | Executive | Top-level orchestrator/copilot; plain-language cross-agent briefings | `suggest_only` | Human Owner / CEO | [../../ai/agents/CEO_AGENT.md](../../ai/agents/CEO_AGENT.md) |
| **Reporting Agent** | Reporting | Generates statements and narrative commentary | `auto` for scheduled reports, `suggest_only` for external distribution | CFO | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |
| **Document AI / OCR** | Ingestion | Classifies incoming documents; extracts structured fields, confidence-gated | `auto` (classification/extraction), low-confidence fields routed to a human | General Accountant | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |
| **Forecast Agent** | Predictive | Cash-flow/revenue/expense forecasting; scenario modeling | `suggest_only`, derived only, no ledger writes | CFO, Treasury Manager | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |
| **Approval Assistant** | Governance | Routes, tracks, escalates human approval chains; never approves | `auto` for routing/reminders only | Named human approvers | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |

Three roster properties are worth drawing out because they are safety features, not org-chart trivia.
The **General Accountant** is the highest-volume agent — it touches every bill, invoice, receipt, and
bank line — and yet it never touches payroll, tax filing, or bank transfers, which belong to
specialists with tighter mandates. The **Auditor and Fraud Detection** agents never post, reverse, or
execute anything; observation and flagging are their entire authority, and acting on a flag belongs to
a human with the right permission. The **CFO agent owns no table of its own**; it is a synthesis layer
that reads every other agent's output and is explicitly advisory. No agent has a privileged back
channel — Document AI, OCR, Payroll Manager, and Approval Assistant attach to the same supervisor and
the same tool layer as every other agent.

# The AI Orchestrator

The **CEO Assistant** agent is the supervisor node of a LangGraph-style orchestration graph and the
user-facing name for the Financial Copilot. A message or trigger becomes a turn; the orchestrator
classifies intent, retrieves the context the requesting principal is permitted to see (structured
queries plus semantic retrieval from Company Memory), routes to the specialist agent(s) whose mandate
matches, and composes their outputs into one coherent reply — **never bypassing any agent's own
autonomy rules or the Decision Engine's approval gating to do so**
([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)).

The orchestrator reaches the platform through a **Model Context Protocol (MCP) tool layer**: every
tool is a typed function-call definition mapped one-to-one to a permitted `/api/v1/*` endpoint and
scoped to the requesting user's own permission grant. The orchestrator authenticates as that user's
session — or a narrowly-scoped service token for scheduled/event-triggered work — never as a
platform-wide superuser. This is what guarantees the Copilot can never reveal data the asking human
could not see by hand: if a user cannot read payroll, the agent's tools carry no payroll scope, so the
Copilot cannot surface payroll, enforced in Laravel's RBAC layer rather than the agent's restraint.
The tool contracts are specified in [../../ai/tools/](../../ai/tools/) and
[../../ai/prompts/TOOLS_PROMPTS.md](../../ai/prompts/TOOLS_PROMPTS.md); a tool that produces an
outbound communication (a payment reminder, a report to a bank) fixes its `autonomy_applied` at
`suggest_only` regardless of confidence, because anything that leaves the company's own system and
reaches a third party gets a human's eyes first.

The Copilot's fluency is a presentation-layer property. In a dialogue where a user asks "draft a
payment reminder," the reply renders the draft behind an explicit "will not send until you approve it"
checkpoint — the same `suggest_only` gate a user would meet clicking through the dashboard by hand.
"The AI is not a chatbot" is easiest to violate exactly at the chat interface, and the orchestration
discipline above is how the platform holds the line there. Full architecture, the conversation/message
model, and the tool-calling contract are in the Financial Copilot section of
[../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md), with the surface behavior in
[../../ai/AI_COMMAND_CENTER.md](../../ai/AI_COMMAND_CENTER.md).

# The Decision Engine

Every capability in this chapter — a journal draft, an audit finding, a risk flag, a cash-flow alert
— ultimately produces the same artifact: a structured, evaluable proposal that something should
happen, recorded as an `ai_decisions` row. The **Decision Engine** is the shared machinery that turns
an agent's analysis into that artifact, decides how much autonomy it is allowed, and routes it to
execution, suggestion, or approval ([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)). Each
decision carries its `confidence`, `reversibility` (`reversible | reversible_with_cost |
irreversible`), `expected_impact`, `reasoning`, `sources`, an optional `proposed_action` (endpoint +
payload), a `risk_score`, the computed `autonomy_applied`, and a `status` that walks a fixed state
machine from `proposed` to a terminal outcome.

The autonomy tier is **never computed from confidence alone**, because a high-confidence decision
about an irreversible, high-impact action is exactly the case the platform must not let confidence
auto-execute. The determination is a fixed, shared formula:

```
autonomy_applied =
    IF decision_type/subject is on the fixed sensitive-operations list  → requires_approval
    ELSE IF reversibility == irreversible                               → requires_approval
    ELSE IF confidence >= threshold.auto_post_confidence
         AND expected_impact <= threshold.auto_post_max_amount          → auto
    ELSE                                                                → suggest_only
```

This formula is evaluated **identically for all fifteen agents** — it is shared infrastructure, not a
per-agent reimplementation — which is precisely what guarantees the Payroll Manager cannot get a laxer
autonomy rule than the General Accountant for equivalent risk simply because its code path was written
differently. On the Laravel side this is the `AutonomyResolver`, a deliberately standalone,
side-effect-free class asserted per-agent in tests because a regression there is a governance failure,
not a bug ([../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md)). Decisions also carry a
`decision_class` — operational, tactical, or strategic — that sets review cadence: operational items
(categorize an expense, match a bank line) are reviewed in aggregate; strategic ones (flag a customer
concentration risk) are reviewed deliberately.

## Autonomy Thresholds

QAYD ships conservative platform defaults, expressed per company as `ai_agent_settings.thresholds`,
that a company may **tighten but never loosen past the platform ceiling** for
`requires_approval`-class actions. Representative defaults
([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)):

| Transaction class | Default autonomy | Confidence floor for `auto` | Amount ceiling for `auto` |
|---|---|---|---|
| Recurring vendor bill, previously-seen vendor + account pair | `auto` | 0.90 | KWD 500.000 |
| New vendor, first bill | `suggest_only` | n/a | n/a |
| Payroll-related journal entries | `suggest_only` | n/a | n/a |
| Bank transaction auto-match | `auto` | 0.95 | no ceiling (matching, not moving) |
| Manual adjustment / write-off | `requires_approval` | n/a | n/a |
| Any entry touching a closed/locked period | `requires_approval`, always | n/a | n/a |
| Any vendor/account pair a human corrected in the last 90 days | `suggest_only`, floor raised | n/a | n/a |

The last row is a deliberate design choice: a recent human correction is treated as evidence the
pattern is currently unstable, so QAYD suppresses auto-posting for that pair until the correction is
consistently reflected or a human re-raises the threshold. And widening any threshold is itself the
sensitive action `ai.settings.update`, which routes through the approval chain — a company cannot
silently make its AI bolder without a recorded human sign-off.

# Human Approval: The Gate That Makes Autonomy Safe

Every autonomy claim in this chapter is safe only because of a formal, structurally unbypassable
approval chain that gates a fixed list of sensitive operations no confidence score, no agent, and no
orchestration shortcut can route around ([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md),
[../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md)). The list is platform-wide; a
company may add more actions to its own list but may never remove one of these:

| Sensitive operation | Why it can never be automated away |
|---|---|
| Bank transfer / payment disbursement | Irreversible movement of real funds out of the company |
| Payroll release (disbursement, not calculation) | Irreversible movement of funds to employees; hard to claw back |
| Tax return submission | A legal filing with regulatory and financial consequence |
| Deleting or voiding posted financial data | Destroys or alters the record an audit depends on |
| Permission / role changes | Could widen an AI's or a compromised account's own authority |
| Company settings (fiscal year, base currency, tax registration) | Cross-cutting; affects every other calculation |
| AI autonomy configuration (`ai_agent_settings`) | Widening an agent's own ceiling is itself sensitive |

The chain supports single- or multi-approver, sequential or any-N-of-M, with SLA timers and escalation
on timeout. The **Approval Assistant** agent owns the lifecycle end to end — creating the request,
notifying approvers, tracking the SLA, escalating a lapsed one — but has **no write access to the
decision column**; only a human acting through their own authenticated session can approve or reject,
enforced through RBAC exactly like every other write. The proposed action is frozen from the moment
it is drafted, so the approver's review and the executed action are guaranteed identical, and it
executes only once the required approvals are recorded. Because Reverb pushes state in real time, an
approval reaches a mobile approver as a push notification carrying the full `summary`, `reasoning`,
and `sources` — the same evidentiary basis a desktop reviewer sees — which is what keeps a
well-designed gate from becoming its own bottleneck.

# AI Memory: Per-Tenant, Retrieval-Based, Structurally Isolated

Company Memory is the substrate that makes every agent's output specific to one company rather than
generic accounting knowledge applied blindly
([../../ai/memory/COMPANY_MEMORY.md](../../ai/memory/COMPANY_MEMORY.md), and the
[../../ai/memory/](../../ai/memory/) set for accounting, business, knowledge, and user memory). It
stores policies, preferences, approval chains, vendor/customer notes, human corrections, institutional
FAQs, and approver-timing context, each as a human-readable statement plus a `pgvector` embedding for
semantic retrieval and an optional machine-usable structured value.

Isolation is enforced **structurally, at the schema, not by application convention**. `ai_memory` is
`company_id NOT NULL` and every retrieval — whether an exact-match structured lookup or a vector
similarity search — is scoped by the same Row-Level Security policy that protects `journal_entries`,
applied identically whether the querying process is a human API request or the AI engine's own
retrieval step. There is no cross-company retrieval path in the schema at all — not a slower one, not
an opt-in one — because the AI engine retrieves *through* Laravel tools carrying the same session
scope, so a vector search is physically incapable of returning another tenant's embeddings. This is
the concrete mechanism behind "AI memory never crosses tenants": a boundary the database enforces
regardless of what the AI process asks for.

At inference time an agent assembles context through two parallel paths — a structured query (this
vendor's last N transactions, this account's normal use) and a semantic query (vector similarity over
the current situation's free-text description, surfacing a relevant policy that matches no structured
key) — merged and passed into reasoning, with any memory row that materially influenced the output
listed in that decision's `sources`. Memory is retrievable evidence, not an invisible thumb on the
scale. Rows carry `usage_count`, `last_used_at`, and `expires_at`, so stale memory is surfaced for
human confirmation or retirement rather than silently influencing decisions forever.

# The Learning Loop

An AI Finance Team that makes the same categorization mistake every month is not autonomous, merely
automated. The **Learning Loop** is how a human correction becomes durable behavior change for that
specific company — **without ever fine-tuning a shared model on one tenant's private financial data**,
which would risk exactly the cross-tenant leakage the platform forbids
([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)).

Learning here is precisely **retrieval-augmented behavioral adaptation**: every accepted, edited, or
rejected proposal becomes a Company Memory row, retrieved at inference time for that company's future
decisions, so "the AI got smarter" means concretely "the next relevant decision retrieves this
specific prior correction as evidence and weighs it" — fully inspectable, fully reversible, never
shared outside the company that produced it. It runs at two speeds: **per-company and immediate** (a
single correction is retrievable for the next relevant decision the moment it is written, letting a
vendor-coding quirk take effect on the very next bill) and **platform-wide, aggregated, anonymized,
and deliberate** (the platform team reviews anonymized correction *patterns* — never raw tenant data —
to spot systemic prompt/pipeline weaknesses, improving the shared pipeline for everyone). Guardrails
keep the loop honest: a single correction is weighted evidence, not an instant irreversible overwrite,
so one mistaken correction by a new employee does not permanently misconfigure an agent; every
correction is logged and inspectable; and a correction can be retracted (marked superseded, never
hard-deleted). The reconciliation-of-predictions mechanism below feeds the same loop.

# The Simulation Engine

Some decisions cannot be reasoned about from historical pattern-matching alone, because they concern a
state the company has never been in — hiring a first warehouse manager, losing its largest customer,
entering a second country. The **Simulation Engine**, jointly run by the Forecast Agent and the CFO
agent, lets a human pose a hypothetical in natural language and see its modeled consequence across the
company's actual financial structure before committing
([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)).

A simulation **never touches a real record**. The Forecast Agent clones the relevant portion of the
current-state projection — not the ledger, which is never forked — into an isolated, non-persistent
in-memory scenario, applies the hypothetical, and re-runs the same projection logic used in cash-flow
monitoring and forecasting. Output always includes a `sensitivity` array with at least the stated
assumption and one bounding alternative, and explicitly names what was *not* modeled (a hire's revenue
benefit, for instance), so a human never mistakes a scenario for a forecast. Scenarios are posed
conversationally through the Copilot, and the engine asks a clarifying question rather than silently
assuming a material input it was not given.

# Forecasting And Predictive Finance

The Forecast Agent maintains an always-current set of predictions — a 13-week rolling cash position, 1
to 3 month revenue and expense, working-capital position, current-period tax liability, customer-churn
risk, and inventory stockout risk — built from the same historical data every other agent draws on and
held to the same evidentiary standard ([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)). **A
prediction is never a bare point estimate.** Every forecast is returned as a range with an explicit
`confidence_interval`, a stated `method`, plain reasoning, and resolvable `sources`.

The property that makes the confidence numbers mean something is **reconciliation against actuals**:
every prediction is retained after its horizon passes and automatically compared to the realized
value, producing a per-metric, per-company accuracy track record that feeds the Learning Loop. A
company whose 30-day revenue forecast has landed within its 80% interval 11 of the last 13 times gets
a stated confidence calibrated against *that* track record, not an abstract model-level prior; a newly
onboarded company with two weeks of history sees an explicit note that its confidence is provisional.
The platform never manufactures false precision to make a new tenant's dashboard look more finished
than its evidentiary basis supports.

# The Recommendation Engine

Not every AI output is a decision about what the *system* should do; many are suggestions about what a
*human* should decide — hire, price, negotiate, cut a cost line. The **Recommendation Engine** ranks,
deduplicates, and surfaces these judgment-requiring suggestions into a single prioritized list rather
than scattering them across fifteen agent feeds
([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)). A recommendation is **always `suggest_only`
by category** — QAYD never auto-executes one — because by definition it requires human judgment the AI
is not positioned to make unilaterally. The CFO agent ranks the pool by a weighted score of financial
impact, urgency, confidence, and inverse implementation effort, so a high-impact, time-sensitive,
low-effort item outranks a larger-but-slower one. Before surfacing, a near-duplicate is merged rather
than shown twice, and a dismissed recommendation's reason becomes a Learning-Loop signal that lowers
that category's weighting for that company specifically.

# The Automation Engine

Beyond agent-initiated work, companies can define **trigger → condition → action** automation rules
that let agents act on a schedule or in response to an event within scoped, logged limits
([../../backend/AUTOMATION_ENGINE.md](../../backend/AUTOMATION_ENGINE.md), surfaced in the Automation
Center of [../../ai/AI_COMMAND_CENTER.md](../../ai/AI_COMMAND_CENTER.md)). Automation does not create a
bypass of the core contract: an automated action still produces an `ai_decisions` row, still runs
through the Decision Engine's autonomy formula, and still stops at the human gate if its action is on
the sensitive-operations list. Configuring automation that lets an agent act on a schedule is governed
by the `ai.automation` permission, and the rules themselves are tenant-scoped. Automation is a
convenience layer over the same governed pipeline, never a second, looser one.

# The AI Finance OS Capabilities

The agents, engines, and contract above assemble into a set of continuous capabilities that constitute
the operating system's actual behavior day to day. Each is specified in full — trigger, loop,
confidence contract, worked example, and schema — in
[../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) and the corresponding
[../../ai/workflows/](../../ai/workflows/) document; this chapter names them and routes to them.

| Capability | What the workforce does continuously | Deep spec |
|---|---|---|
| **Autonomous Accounting** | Ingest a document, extract fields, draft the journal, auto-post below threshold or suggest above it | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md), [../../ai/agents/ACCOUNTANT_AGENT.md](../../ai/agents/ACCOUNTANT_AGENT.md) |
| **Autonomous Closing** | Keep the books continuously close-ready so period-end is a same-day human sign-off | [../../ai/workflows/MONTH_END_CLOSE.md](../../ai/workflows/MONTH_END_CLOSE.md), [../../ai/workflows/YEAR_END_CLOSE.md](../../ai/workflows/YEAR_END_CLOSE.md) |
| **Autonomous Reconciliation** | Match bank lines against ledger lines (deterministic + fuzzy), auto-match high confidence | [../../ai/workflows/BANK_RECONCILIATION.md](../../ai/workflows/BANK_RECONCILIATION.md) |
| **Autonomous Reporting** | Generate statements on schedule with grounded narrative commentary | [../../ai/workflows/FINANCIAL_REPORTING.md](../../ai/workflows/FINANCIAL_REPORTING.md) |
| **Continuous Audit** | Post-hoc control testing over every posting class, flagging anomalies | [../../ai/agents/AUDITOR_AGENT.md](../../ai/agents/AUDITOR_AGENT.md) |
| **Continuous Compliance** | Monitor regulatory change and map it to affected transactions | [../../ai/agents/COMPLIANCE_AGENT.md](../../ai/agents/COMPLIANCE_AGENT.md) |
| **Continuous Cash-Flow Monitoring** | Maintain a live position and a 13-week rolling forecast, alerting on reserve-floor breaches | [../../ai/agents/TREASURY_AGENT.md](../../ai/agents/TREASURY_AGENT.md) |
| **Continuous Risk / Fraud Detection** | Anomaly scoring, duplicate/ghost-vendor and velocity checks; reversible auto-hold only | [../../ai/agents/FRAUD_AGENT.md](../../ai/agents/FRAUD_AGENT.md) |
| **Continuous Tax Review** | Maintain a running VAT/WHT position, draft returns ahead of deadline; submission human-gated | [../../ai/workflows/TAX_FILING.md](../../ai/workflows/TAX_FILING.md), [../../ai/agents/TAX_AGENT.md](../../ai/agents/TAX_AGENT.md) |
| **Business Health Monitoring** | Synthesize a health score and ranked recommendations from cross-agent signal | [../../ai/agents/CFO_AGENT.md](../../ai/agents/CFO_AGENT.md) |

# Safety And Guardrails

Safety is not a section bolted onto the AI platform; it is the set of structural properties that make
every capability above describable as "autonomous" without being reckless. Gathered in one place, the
guardrails are:

- **The structural write boundary.** The FastAPI process has no database connection string to the
  tenant schema — only an HTTPS bearer to Laravel. "The AI should not write the DB" is not a policy an
  agent respects; it is a wall a bug cannot cross without a deliberate, reviewable infrastructure
  change ([../../security/AI_SECURITY.md](../../security/AI_SECURITY.md),
  [../../security/TENANT_ISOLATION.md](../../security/TENANT_ISOLATION.md)).
- **The single autonomy resolver.** One shared, side-effect-free function computes every agent's
  autonomy tier, provably identical across agents, forcing every sensitive or irreversible action to
  `requires_approval` regardless of confidence
  ([../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md)).
- **The permission ceiling.** An agent invoked in a user's context receives only what that user's
  permissions allow, and a proposal's own `permission_required` key is checked against the acting
  principal — default-deny throughout
  ([../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md)).
- **The append-only action log.** Every prompt, tool call, retrieval, and API request/response is
  captured in a partitioned, never-updated, never-deleted log, so an engineer or auditor can
  reconstruct not just what the AI decided but exactly how it got there, down to the tool invocation
  ([../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md)).
- **Injection and prompt safety.** Untrusted document content and user input are handled under the
  defense model and safety prompts in
  [../../ai/prompts/SAFETY_PROMPTS.md](../../ai/prompts/SAFETY_PROMPTS.md) and
  [../../security/AI_SECURITY.md](../../security/AI_SECURITY.md).
- **Cost, rate, and degraded mode.** A per-company token/spend budget and rate limiter protect the
  tenant from a runaway agent; when the engine is slow or unreachable, AI-only endpoints return `503`
  while AI-optional features degrade gracefully — the ledger keeps working even when the intelligence
  is throttled ([../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md)).
- **Evaluation as a gate.** Agent quality, calibration, and the governance boundary are held to an
  evaluation harness — the platform's tests prove the boundary is safe, not merely that the model is
  smart ([../../testing/AI_EVALUATION.md](../../testing/AI_EVALUATION.md)).

Two of these are pass/fail invariants, never optimization targets: **every sensitive action must pass
a human approval chain**, and **cross-tenant data leakage must be zero**. A release that violates
either is not shippable regardless of any other metric — which is exactly the standard the rest of
this PRD holds the platform to, and the standard this chapter exists to make concrete.

## Related Documents

- [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md), [../../ai/AI_COMMAND_CENTER.md](../../ai/AI_COMMAND_CENTER.md) — the AI Finance Operating System thesis and the Command Center surface.
- [../../ai/agents/](../../ai/agents/) — the full roster: [ACCOUNTANT_AGENT.md](../../ai/agents/ACCOUNTANT_AGENT.md), [AUDITOR_AGENT.md](../../ai/agents/AUDITOR_AGENT.md), [CFO_AGENT.md](../../ai/agents/CFO_AGENT.md), [TAX_AGENT.md](../../ai/agents/TAX_AGENT.md), [TREASURY_AGENT.md](../../ai/agents/TREASURY_AGENT.md), [BANKING_AGENT.md](../../ai/agents/BANKING_AGENT.md), [PAYROLL_AGENT.md](../../ai/agents/PAYROLL_AGENT.md), [SALES_AGENT.md](../../ai/agents/SALES_AGENT.md), [PURCHASING_AGENT.md](../../ai/agents/PURCHASING_AGENT.md), [INVENTORY_AGENT.md](../../ai/agents/INVENTORY_AGENT.md), [FRAUD_AGENT.md](../../ai/agents/FRAUD_AGENT.md), [COMPLIANCE_AGENT.md](../../ai/agents/COMPLIANCE_AGENT.md), [CEO_AGENT.md](../../ai/agents/CEO_AGENT.md).
- [../../ai/memory/COMPANY_MEMORY.md](../../ai/memory/COMPANY_MEMORY.md) and [../../ai/memory/](../../ai/memory/), [../../ai/tools/](../../ai/tools/), [../../ai/prompts/](../../ai/prompts/), [../../ai/workflows/](../../ai/workflows/) — memory, tool/prompt contracts, and end-to-end AI workflows.
- [../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md), [../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md), [../../backend/AUTOMATION_ENGINE.md](../../backend/AUTOMATION_ENGINE.md) — the Laravel-side proposal gateway, approval chains, and automation.
- [../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md), [../../database/ROW_LEVEL_SECURITY.md](../../database/ROW_LEVEL_SECURITY.md) — the tenant boundary the AI tables inherit.
- [../../api/INTERNAL_API.md](../../api/INTERNAL_API.md), [../../api/REST_STANDARDS.md](../../api/REST_STANDARDS.md) — the `/api/v1` contract the MCP tools map to.

# End of Document
