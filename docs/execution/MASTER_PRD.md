# QAYD — Master Product Requirements Document
Version: 1.0
Status: Source of Truth
Owner: Founder & Product
Last updated: 2026-07

---

This is the single, authoritative product requirements document for QAYD. It is the company's blueprint and its source of truth: the one document a founder, an investor, a product manager, an engineering lead, a designer, or an AI coding agent reads first to understand *what QAYD is, why it exists, what it must do, how it is built, and how it wins*. It sits above the roadmap and beneath nothing except the mission itself. Where a detail is contested, this document routes to the specification that owns it rather than re-deciding it — the deep specs in `../foundation/`, `../ai/`, `../accounting/`, `../backend/`, `../frontend/`, `../design-system/`, `../security/`, `../database/`, `../api/`, `../deployment/`, `../testing/`, and `../developer/` remain authoritative for their mechanics. This PRD anchors and routes to more than three hundred existing specification files; it does not duplicate them.

One framing governs every page that follows and is never contradicted anywhere in the QAYD documentation set: **QAYD is not accounting software. QAYD is an AI Financial Operating System.** Its mission is to replace manual financial operations with an intelligent, autonomous finance platform so that people spend less time operating software and more time making decisions. AI is not a feature bolted onto a ledger; it is built into every workflow. Traditional software stores data and waits for users. QAYD understands data and works before the user asks.

## Table of Contents

1. [Vision](#1-vision)
2. [Product Overview](#2-product-overview)
3. [Market](#3-market)
4. [Product Architecture](#4-product-architecture)
5. [AI Platform](#5-ai-platform)
6. [Accounting Platform](#6-accounting-platform)
7. [User Experience](#7-user-experience)
8. [Backend](#8-backend)
9. [Frontend](#9-frontend)
10. [Security](#10-security)
11. [Infrastructure](#11-infrastructure)
12. [Business](#12-business)
13. [Product Roadmap](#13-product-roadmap)
14. [Development Plan](#14-development-plan)
15. [Appendices](#15-appendices)

---

# 1. Vision

## Mission

Every business should have an AI finance team available twenty-four hours a day. That single sentence, fixed in [../foundation/MISSION.md](../foundation/MISSION.md), is the whole of QAYD's ambition compressed. Companies waste thousands of hours every year on repetitive financial operations — recording invoices, reconciling bank accounts, matching transactions, reviewing documents, preparing reports, checking compliance, and coordinating between departments. Most accounting software only *stores* those operations; a human still performs each one by hand. QAYD's mission is to remove that human from the mechanical work and elevate them to the decision: the AI executes, reviews, and coordinates financial workflows, and a person supervises the subset of actions that require judgment or approval. The accountant stops being a data-entry operator and becomes the supervisor of a workforce.

## Vision

Build the world's most intelligent financial operating system: a platform where accounting is no longer manual, where financial work is performed by autonomous AI agents collaborating together, and where the human's contribution is judgment rather than transcription. In the long run, QAYD becomes the application every company opens every morning before it starts work and closes after it ends — a company operating system in which AI manages accounting, banking, payroll, inventory, purchasing, sales, tax, financial reporting, forecasting, and compliance from one unified surface, and in which accounting is only the first of many intelligent business modules.

## Core Philosophy

Traditional accounting software stores data; QAYD understands it. Traditional ERP systems wait for users; QAYD works before users ask. Traditional AI answers questions; QAYD completes work. This is a difference of category, not of degree. Incumbent systems are *systems of record* — a place where humans enter what already happened so other humans can later look it up. QAYD is a *system of action* — a place where an AI workforce continuously determines what should happen next, prepares it, and, within the exact permission and approval rules the company already lives by, makes it happen, with a human able to inspect, override, or halt any of it at any time. The ledger remains the single source of truth; what changes is who is allowed to write proposals against it and how fast a correct proposal can become a posted fact.

## Long-term Vision

QAYD's long-term goal is stated plainly in [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md): the platform evolves into the operating system of every business, with accounting as one module inside a larger intelligent business platform that may eventually contain more than fifty independent, replaceable, testable modules ([../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)). The trajectory is Kuwait first, the GCC next, and the world after — never a feature limited to one country, always a global architecture with localization that adapts on top of it.

## Company, Product, Design, AI, and Engineering Principles

QAYD's principles are inherited from [../foundation/PRODUCT_PRINCIPLES.md](../foundation/PRODUCT_PRINCIPLES.md), [../foundation/MISSION.md](../foundation/MISSION.md), and the design and engineering foundations, and are binding on every feature. They resolve into five families, summarized below and load-bearing throughout this document.

| Family | Principles (each traceable to a foundation doc) |
|---|---|
| Company | Build for the next ten years; scale globally, never country-locked; enterprise-ready from freelancer to enterprise on one system; security is never optional; the platform is the first app a company opens and the last it closes. |
| Product | AI first — every workflow asks "can AI do this?"; automation before manual work; AI works before the user asks; one source of truth for every datum; every module talks to every other module; every click must save time. |
| Design | Ink before color; one accent (brass), spent deliberately; numbers are the hero, not the chrome; density with air; motion explains, never entertains; Arabic is a first language, not a mirrored afterthought; the AI stays humble in the UI exactly as it stays humble in the API ([../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md)). |
| AI | Explain every AI decision (why, which documents, which standard, confidence, recommended action); human approval for critical decisions; the AI never bypasses permissions; continuous learning per company, never across companies; no hidden logic — every automated action is traceable to who, AI, human, or system, and when. |
| Engineering | Modular architecture; API first; default-deny everywhere; backend is the single source of truth; the AI engine never writes the database directly; async by default for heavy work; everything is auditable ([../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md)). |

The two principles that constrain everything else are **human approval for money-moving and legally binding acts** and **complete tenant isolation** — both are pass/fail invariants, not aspirations, and a release that violates either is not shippable regardless of any other metric.

# 2. Product Overview

## What QAYD is

QAYD is a multi-tenant, bilingual (Arabic/English, full right-to-left), double-entry financial platform for Kuwait and the wider GCC, built as a Laravel 12 modular-monolith backend that is the single source of truth, a separate FastAPI/Python AI engine that acts only through the backend's API, a Next.js 15 web client, and a Flutter mobile app, on a single-database PostgreSQL cluster with Row-Level Security. Underneath the AI is a correct, immutable double-entry ledger; on top of it is a workforce of fifteen specialized AI agents that read documents, draft entries, close books, reconcile accounts, watch for risk, and brief executives — while a human supervises and approves the actions that carry real financial, legal, or reputational consequence. QAYD is a system of action, not a chatbot on a ledger; it owns its ledger rather than sitting on top of QuickBooks.

## Problems it solves

A GCC SME today must keep books that are double-entry-correct, VAT-consistent, payroll-compliant, and bilingual — and most do it in spreadsheets, in a foreign-market tool that treats Arabic and the KWD as an afterthought, or by outsourcing to an accountant working in those same spreadsheets. Every incumbent path shares one cost: skilled humans spend most of their finance hours on repetitive, mechanical work rather than on judgment. QAYD's thesis, fixed in [MASTER_PRD.md](./MASTER_PRD.md), is that this work is now automatable with a human supervising the exceptions, and that the tool built AI-native from the first commit — for the Arabic/KWD/GCC context — wins a market the incumbents can only retrofit.

## Target customers

QAYD serves a company, not a single user: a 10–40-person Kuwaiti or GCC trading company, contractor, clinic, restaurant, retailer, or professional-services firm; the in-house finance function of an SME; and the accounting firm that serves several such clients. The buyers are the Owner and the Finance Manager; the highest-frequency daily user is the Accountant; the trust anchor that makes AI output defensible is the Auditor.

## Personas

| Persona | Role context | Core job-to-be-done | What "great" looks like | Primary QAYD surface |
|---|---|---|---|---|
| Owner / Founder (Layla / Fahad) | Runs a 10–40 person company; not an accountant; often the CEO | Know the financial truth at a glance; approve only what needs her | Sees cash position and pending approvals, signs off in seconds, trusts the books are current | Dashboard, Approval Center, AI copilot, mobile |
| Finance Manager (Khaled) | Owns the finance function; may manage staff; answers to owner/CFO | Keep the books correct; make month-end painless; own compliance | Close is a same-day sign-off, not a two-week scramble; exceptions are surfaced, not hunted | Approval Center, close-readiness view, reports, AI proposal review |
| Accountant / Bookkeeper (Mariam) | Day-to-day entry and reconciliation, in-house or at a firm | Process documents and reconcile fast and accurately; correct the AI where unsure | Reviews and one-click-accepts AI drafts instead of typing them; low-confidence items come to her | Journal/document review, bank reconciliation, suggest-only AI queue |
| Auditor (Dana, internal or external) | Reviews for correctness and control; scoped, often time-boxed access | Verify every number traces to a source and controls held over the full population | Continuous, full-population control evidence and an immutable trail — not a sampled scramble | Audit trail, AI reasoning/citations, read-only scoped access |
| CFO (Mariam Al-Sabah, in larger tenants) | Owns financial strategy and board reporting | Ask questions in natural language and get cited answers; be told what to prioritize | The copilot drafts the board narrative cited to ledger rows; recommendations arrive ranked | Business Health Score, Ask AI, Decision Support |

## Business goals

The Chart of Accounts, Tax, and Reporting specs each restate the same commercial goals: reduce the manual finance hours a company spends, shorten the period-end close from a multi-day scramble to a same-day sign-off, raise compliance and reduce accounting mistakes, give owners real-time financial visibility, and lower the operational cost of running a company's books — while never compromising correctness, isolation, or the human's authority over consequential acts.

## Success metrics and North Star

The metrics QAYD steers by are drawn from [MASTER_PRD.md](./MASTER_PRD.md) and the mission's promise. Two of them are pass/fail invariants rather than optimization targets.

| Category | KPI | Directional target |
|---|---|---|
| Automation depth | Share of routine journal entries AI-drafted vs human-typed | ≥ 70% at maturity |
| Automation trust | Share of AI drafts accepted without human edit | ≥ 85% on previously-seen vendor/account pairs |
| Close speed | Days from period end to period lock | Same-day / next-day (from a typical 5–15 day scramble) |
| Reconciliation | Share of bank lines auto-matched | ≥ 90% |
| Adoption | Time-to-first-value in onboarding | First reconciled books within the onboarding session |
| Safety (pass/fail) | Sensitive actions passing a human approval chain | Exactly 100% |
| Isolation (pass/fail) | Cross-tenant data incidents | Exactly 0 |
| Explainability | AI proposals with resolvable citations | 100% (contract requirement) |

**North Star Metric — Supervised Automation Rate:** the share of a company's financial transactions carried end-to-end by the AI workforce and accepted by a human supervisor. It is the one number that captures the entire thesis at once — it rises only when the AI drafts more, drafts better, and earns enough trust for a human to accept without rework, and it can never rise by removing the human from a sensitive act, because those are excluded by construction.

# 3. Market

## The ERP and accounting-software market

The GCC is home to a large, fast-formalizing base of SMEs being pulled into formal financial record-keeping faster than the available tools were built to serve. Three forces converge, as documented in [MASTER_PRD.md](./MASTER_PRD.md): the roll-out of VAT across the region (live in Saudi Arabia, the UAE, Bahrain, and Oman; announced or expected in Kuwait and Qatar), tightening payroll and labor-compliance regimes (in Kuwait, PIFSS social-insurance contributions and the WPS wage-protection file; in Saudi Arabia, GOSI and WPS/Mudad), and a generational shift of owners who expect software to feel like the consumer and fintech apps they already use. The global accounting-and-ERP market is enormous and mature, but it is structured around a thirty-year-old abstraction — a shared, rule-validated database that humans populate — and it has spent two decades adding surface area without changing that load-bearing assumption. QAYD's diagnosis, argued in full in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), is that the assumption is now the bottleneck.

## Competitor analysis

| Competitor | Strengths | Weaknesses relative to QAYD |
|---|---|---|
| Odoo | Broad open-source module suite; low entry cost; customizable | Passive system of record; AI is peripheral; GCC/Arabic and KWD/VAT localization is community-dependent; configuration-heavy |
| SAP (S/4HANA, Business One) | Deep, proven, enterprise-grade; strong controls and consolidation | Seven/eight-figure implementations, multi-year timelines, consultant-army configuration; passive; RPA bolted on; wrong weight class for a 20-person SME |
| Oracle NetSuite | Cloud-native ERP; strong multi-entity and reporting | Expensive, implementation-driven; retrofitted Gulf localization; batch reporting looks backward; automation is scripted, not reasoned |
| QuickBooks | Excellent SMB UX; ubiquitous; large ecosystem | US/UK-first; partial Arabic RTL; inconsistent KWD fils precision and GCC VAT; no PIFSS/WPS; a system of record — a human still does the work |
| Xero | Clean UX; strong bank feeds and app marketplace | Built for US/UK/AU/NZ; Arabic and GCC tax are afterthoughts; AI is assistive, not a workforce; no autonomous close |
| Microsoft Dynamics 365 | Enterprise integration with the Microsoft estate; strong finance depth | Heavy, licensing-complex, partner-implemented; Copilot is a chat layer over a passive ledger, not a supervised autonomous workforce |

The pattern is consistent: global SMB tools are competent double-entry systems that are localization-retrofitted for the Gulf and remain systems of record; local and regional ERPs understand the Arabic and tax context but are heavyweight, consultant-driven, expensive, and dated in experience. Across all of them, the same underlying cost recurs — humans do the mechanical work; the software only stores it.

## Opportunities and QAYD differentiation

The gap QAYD fills is not "a better accounting UI"; it is a different category. There is no AI-native, bilingual, GCC-first financial operating system in this market. QAYD's differentiation is fourfold and structural rather than cosmetic: an AI workforce that does the mechanical work continuously while a human approves the consequential; a correct KWD/GCC double-entry core from day one (the moat is the correct ledger, not the chat); Arabic as a first-class design language rather than a language pack; and per-company learning that makes the product get better the longer a company uses it, without ever leaking one tenant's data into another's. The five ERP failure modes QAYD is built to obsolete — the system is passive, the close is a fire drill, reporting looks backward slowly, configuration requires an army, and automation is scripted rather than reasoned — are each answered by a specific QAYD capability, detailed in Section 5.

The table below maps each incumbent failure mode to the QAYD answer, and states the architectural reason the incumbents cannot simply copy it.

| Incumbent failure mode | QAYD answer | Why it is hard to copy |
|---|---|---|
| The system is passive — it waits for input | AI-initiated work on domain events (a bill arriving wakes Document AI) | Requires a standing reasoning workforce, not a rules engine that only validates data at entry |
| The close is a fire drill | Autonomous Closing: a live eleven-check readiness score, daily | Requires continuous full-population evaluation no human team can sustain and no batch schema was built for |
| Reporting looks backward, slowly | Real-time monitoring + leading forecasts (Forecast Agent) | Requires live aggregation and a derived-only projection layer, not overnight cubes |
| Configuration requires an army | Agent-inferred, self-adapting coding from the company's own history | Requires per-tenant memory and a learning loop, not consultant-hand-configured static rules |
| Automation is scripted (RPA breaks on a new template) | Reasoning agents over document semantics with honest confidence | Requires models that understand what an invoice *is*, and a confidence gate that fails loudly, not silently |

The strategic consequence is that a retrofit cannot reach QAYD's position by adding an "AI" tab: the AI-native division of labor, the per-tenant learning, and the correct Arabic/KWD core are load-bearing from the first commit, and each is expensive to retrofit onto a system of record whose core abstraction assumes a human does the work.

# 4. Product Architecture

## The whole product

QAYD is composed of six systems, fixed by [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md) and [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md): the Next.js 15 web application, the Flutter mobile application, the Laravel 12 backend API, the FastAPI AI engine, the PostgreSQL/Redis/Supabase Storage data tier, and the infrastructure layer (Cloudflare edge, integrations, notifications). The organizing rule is a strict privilege gradient — `client → web → API → data` — in which only the backend holds credentials to business state, the web tier is an unprivileged renderer, and the AI engine is a peer the backend calls, never a component with database access. The backend is the single source of truth; the web client, mobile client, integrations, and AI engine are all callers of one versioned contract, `/api/v1`, so one OpenAPI contract drives four SDKs and one set of business invariants holds regardless of who initiated a request.

## Modules and subsystems

Every module is a complete business capability that owns its business logic, tables, API endpoints, permissions, AI integration, notifications, audit logs, tests, and events, and that is internally layered Presentation → Application → Domain → Infrastructure ([../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)). The canonical module prefixes match the API route files: `accounting`, `sales`, `purchasing`, `banking`, `inventory`, `payroll`, `tax`, `reports`, `ai`, `settings`, `identity`, and `company`.

| Module / subsystem | Responsibility | Governing spec |
|---|---|---|
| Accounting (core) | Chart of accounts, journal entries, posting engine, general ledger, trial balance, fiscal periods, financial statements | [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md), [../accounting/GENERAL_LEDGER.md](../accounting/GENERAL_LEDGER.md) |
| Sales | Customers, invoices, receipts, AR aging, order-to-cash postings | [../accounting/SALES.md](../accounting/SALES.md) |
| Purchasing | Vendors, purchase orders, bills, three-way match, vendor payments, procure-to-pay | [../accounting/PURCHASING.md](../accounting/PURCHASING.md) |
| Banking | Bank accounts, transactions, transfers, reconciliation | [../backend/BANKING_SERVICE.md](../backend/BANKING_SERVICE.md), [../accounting/BANKING.md](../accounting/BANKING.md) |
| Inventory | Products, warehouses, stock movements, adjustments, valuation | [../backend/INVENTORY_SERVICE.md](../backend/INVENTORY_SERVICE.md) |
| Payroll | Employees, payroll runs, payslips, PIFSS/WPS, GOSI/Mudad | [../backend/PAYROLL_SERVICE.md](../backend/PAYROLL_SERVICE.md) |
| Tax | Jurisdiction tree, tax codes/rates, VAT/withholding/corporate, returns | [../backend/TAX_SERVICE.md](../backend/TAX_SERVICE.md), [../accounting/TAX.md](../accounting/TAX.md) |
| Reports | Financial statements, management reports, scheduled reporting | [../backend/REPORTING_SERVICE.md](../backend/REPORTING_SERVICE.md) |
| AI | Fifteen-agent workforce, Command Center, memory, tools, workflows | [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md) |
| Identity / Auth | Users, roles, permissions, `company_users`, RBAC | [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md) |
| Company | Companies, branches, departments, onboarding | [../foundation/COMPANY_STRUCTURE.md](../foundation/COMPANY_STRUCTURE.md) |
| Workflow / Approval (cross-cutting) | Maker-checker chains, SLA/escalation, AI-proposal commit gate | [../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md) |
| Automation (cross-cutting) | Trigger → condition → action rules within guardrails | [../backend/AUTOMATION_ENGINE.md](../backend/AUTOMATION_ENGINE.md) |
| Notifications, Search, Files, Audit (cross-cutting) | Delivery, search tier, Supabase Storage storage, immutable trail | [../backend/NOTIFICATION_SERVICE.md](../backend/NOTIFICATION_SERVICE.md), [../backend/SEARCH_SERVICE.md](../backend/SEARCH_SERVICE.md), [../backend/FILE_SERVICE.md](../backend/FILE_SERVICE.md), [../backend/AUDIT_SERVICE.md](../backend/AUDIT_SERVICE.md) |

## Dependencies

Modules never modify each other directly; cross-module effects travel through domain events, queued listeners, and the internal API, never through a direct database write ([../execution/MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md)). The central invariant is one posting path: Sales, Purchasing, Banking, Inventory, Payroll, and Tax never write a ledger line themselves — they emit events (`invoice.posted`, `bill.approved`, `banking.payment.received`, `goods.received`, `payroll.released`, `tax.return.submitted`) that the Accounting Service posts through the single `PostingService`. This is why the ledger must exist and be correct before any module that posts to it, and why the tenant boundary and auth layer must exist before any tenant data.

## Navigation and information architecture

The product's information architecture, defined across [../frontend/README.md](../frontend/README.md), [../frontend/NAVIGATION_SYSTEM.md](../frontend/NAVIGATION_SYSTEM.md), and the screen specs, is organized around the three questions every QAYD dashboard commits to answering — *what happened, what needs attention, what should I do next.* The primary navigation is a left sidebar (mirrored in RTL) grouping the modules, a top bar carrying the company switcher, global search (command palette), notifications, and the AI assistant entry point. The AI Command Center is the home surface: a situational-awareness layer (Morning Briefing, Business Health Score, Cash Flow Status), a decision layer (recommendations, forecast, approval center), an interaction layer (Ask AI, today's tasks), and a surface layer (KPIs, widgets, settings) — specified exhaustively in [../ai/AI_COMMAND_CENTER.md](../ai/AI_COMMAND_CENTER.md) and [../frontend/AI_COMMAND_CENTER.md](../frontend/AI_COMMAND_CENTER.md).

## Module lifecycle and the feature checklist

Every module follows one lifecycle — Design → Database → API → Business Logic → Permissions → AI → Tests → Documentation → Release — and no feature is considered delivered until it ships with its migration, API, permissions, audit logging, AI support, documentation, tests, and monitoring ([../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)). Modules version independently (major/minor/patch) and are designed to support millions of records, multiple companies, multiple currencies, multiple branches, and a future extraction to microservices along seams that already exist. Shared services — authentication, permissions, notifications, storage, logging, AI, search, cache, audit, settings — are consumed by every module but never absorb a module's business logic. This discipline is what lets the platform grow toward the fifty-plus modules the long-term vision anticipates without the accounting core being rewritten to accommodate any of them.

## The multi-company and multi-branch model

QAYD serves a company, not a single user, and one user may belong to many companies with a different role in each ([../foundation/COMPANY_STRUCTURE.md](../foundation/COMPANY_STRUCTURE.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)). Switching the active company (the `X-Company-Id` boundary) swaps everything the user sees — data, permissions, AI memory, and reports — and the switch is enforced at the kernel, not trusted from the client. Inside a company, structure descends through branches (e.g. a Kuwait branch and a Riyadh branch) and departments (Finance, HR, Sales, Purchasing, Warehouse), each an isolation boundary a user only crosses when explicitly granted. The running example, Al-Noor Trading, is a three-branch company transacting in KWD base with a SAR-denominated Riyadh office at a maintained exchange rate — the shape the multi-currency, multi-branch capabilities in Section 6 are built to serve.

# 5. AI Platform

## AI Finance OS

The AI layer is the product's defining system, specified in full in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md). It is framed as a genuine operating system, not a metaphor: Laravel 12 + PostgreSQL is the kernel and single source of truth; the RBAC permission system is the protection boundary; the fifteen agents are processes; a LangGraph-style supervisor is the scheduler; the `/api/v1` endpoints invoked via MCP tools are the system calls — the only channel through which any agent can request a privileged action; integrations (bank feeds, PIFSS/WPS files, GCC tax formats, OCR) are drivers; the Financial Copilot and dashboards are the shell; `company_id` scoping plus RLS is per-process memory isolation; and `ai_logs`/`audit_logs` are the kernel log. The AI rests on four pillars — **Autonomous** (routine low-risk work happens without a human initiating it), **Continuous** (close, audit, and tax review happen in small daily increments so period-end is a sign-off), **Predictive** (the platform knows what is about to happen with lead time to act), and **Supervised** (every autonomous action is scoped by policy, permission, and, for the actions that matter most, a human approval chain that cannot be bypassed).

## AI Command Center, Memory, and Orchestrator

The AI Command Center ([../ai/AI_COMMAND_CENTER.md](../ai/AI_COMMAND_CENTER.md)) is the home screen of the workforce: by the time a human opens QAYD in the morning, the work has already happened, and the screen's job is to answer *what happened / what needs attention / what should I do next* in one click, one approval, or one spoken question. Every widget renders a shared "Command Card" envelope carrying `confidence_score`, `agent_code`, `reasoning`, `sources`, and `status`. AI memory ([../ai/memory/](../ai/memory/)) is per-company and layered — company memory, business memory, accounting memory, knowledge memory, and user memory — and is never blended across tenants; it is what lets an agent retrieve "how has this company always coded this vendor" instead of a generic prior. The orchestrator (the CEO Assistant / supervisor node) routes each event or user intent to the right specialist agent, manages hand-offs and escalation, and synthesizes multi-agent output into one plain-language briefing.

## The AI agent roster

QAYD ships fifteen named, specialized agents. The roster is canonical and platform-wide — every company gets the same fifteen, provisioned at onboarding with the same names, default autonomy, and escalation graph; what differs per company is configuration (thresholds, enabled status, escalation targets) and memory.

| Agent | Mandate | Default autonomy |
|---|---|---|
| General Accountant | Drafts/posts routine journal entries; categorizes expenses | `auto` below threshold, else `suggest_only` |
| Auditor | Continuous control testing; flags anomalies; never posts | `suggest_only` always |
| Tax Advisor | Computes VAT/WHT, prepares filings, tracks exposure | `suggest_only`; submission `requires_approval` |
| Payroll Manager | Calculates runs; prepares PIFSS/WPS files | `suggest_only`; release `requires_approval` |
| Inventory Manager | Values stock; proposes reorders; reviews adjustments | `auto` valuation, `suggest_only` adjustments |
| Treasury Manager | Cash positioning, reconciliation, liquidity | `auto` matching, `requires_approval` transfers |
| CFO | Synthesizes cross-agent signal into business health; ranks recommendations | `suggest_only` (advisory) |
| Fraud Detection | Anomaly scoring, duplicate/ghost-vendor detection, velocity checks | `auto`-flag, `suggest_only` action |
| Reporting Agent | Financial statements, custom reports, narrative commentary | `auto` scheduled, `suggest_only` distribution |
| Document AI | Classifies incoming documents; routes downstream | `auto` classification |
| OCR Agent | Extracts structured fields; confidence-gated per field | `auto`, confidence-gated |
| Forecast Agent | Cash flow, revenue, expense forecasting; scenarios | `suggest_only`, derived only |
| Compliance Agent | Regulatory-change monitoring; maps changes to transactions | `suggest_only` |
| Approval Assistant | Routes/tracks/escalates approval chains; never approves itself | `auto` routing/reminders only |
| CEO Assistant | Top-level orchestrator/copilot; cross-agent briefings | `suggest_only` |

## Reasoning, automation, decision engine, simulation, forecasting, learning

Every agent reasons over retrieved context — structured queries against the company's own data plus semantic retrieval from company memory — and produces a decision object conforming to one JSON contract across the platform: `confidence`, `reasoning` (plain, cite-your-work register), `proposed_action` (endpoint + permission + payload), `sources` (always resolving to a concrete fetchable record — an attachment in Supabase Storage, a prior transaction, a named historical pattern with its sample size), `autonomy_applied`, and `risk_flags`. Autonomy is never a global switch: it is scoped per transaction class through `ai_agent_settings.thresholds`, with conservative platform defaults a company may tighten. The Decision Engine turns confidence and policy into one of three outcomes — auto-post, one-click suggestion, or blocked-pending-approval. The Simulation Engine runs sandboxed, non-persistent scenarios ("simulate a fix" for a forecast cash trough). Forecasting (Forecast Agent) produces cash/revenue/expense projections labeled as projections with confidence intervals, never as fact. The Learning Loop improves proposals from *approved corrections* retrieved per tenant — never shared model weights — so one company's data never shapes another's behavior; a recent human correction even suppresses auto-posting for that vendor/account pair until the pattern re-stabilizes.

## Safety, guardrails, and the never-auto-commit contract

The safety architecture is a direct consequence of what AI did not change: models can be confidently wrong, and accounting cannot tolerate silent errors. Three guardrails are non-negotiable and restated identically everywhere ([../ai/prompts/SAFETY_PROMPTS.md](../ai/prompts/SAFETY_PROMPTS.md), [../security/AI_SECURITY.md](../security/AI_SECURITY.md), ADR-007). First, **the AI engine never writes the database directly** — it holds no tenant-schema credentials, only a scoped service-account bearer token, and every action is a proposal through the same `/api/v1`, the same `FormRequest` validation, and the same RBAC check a human passes; it cannot post an unbalanced entry, backdate into a closed period, or read another company's data. Second, **every AI output carries confidence, reasoning, and resolvable citations** — the AI never says "trust me." Third, **the sensitive-operations list is always human-gated regardless of confidence**: bank transfers, payroll release, tax submission, period close, permission/settings changes, and deleting or voiding financial data can never be satisfied by the AI itself. Confidence changes how much friction stands between a draft and an approver; it never removes the human from a sensitive decision. The Financial Copilot's conversational surface grants no extra privilege — a user cannot socially-engineer it into an action their role does not permit, because authorization is enforced at the kernel, not inferred from the conversation.

## MCP tool layer and the units of work

The AI engine's only channel to privileged action is the MCP (Model Context Protocol) tool layer: typed function definitions that map one-to-one to permitted `/api/v1/*` endpoints and their permission keys. An agent that wants to draft a journal entry does not hold a database handle; it calls an MCP tool that resolves to `POST /api/v1/accounting/journal-entries` with the `accounting.journal.create` permission, and the call is validated and permission-checked at Laravel exactly as a human click would be. Work is tracked in two shared tables that every agent writes: `ai_tasks` (the unit of work — trigger type, subject, input/output, confidence, latency) and `ai_decisions` (the structured decision object that carries the confidence-reasoning-sources contract). Because the entire workforce shares these tables and the one contract, `ai_logs` and `audit_logs` can always answer, for any posted fact, which agent proposed it, under what autonomy, with what confidence, citing which documents — the property that makes AI output defensible to an external auditor rather than merely explainable to a user.

## Continuous and predictive capabilities

The "continuous" and "predictive" pillars resolve into named capabilities in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md) and the workflow specs. Autonomous Closing maintains a live close-readiness score (eleven daily checks across unposted documents, reconciliation currency, accrual and prepayment completeness, depreciation, payroll accrual, intercompany netting, suspense balance, inventory valuation, and tax position) so that locking a period is a same-day sign-off rather than a two-week reconstruction — with the lock itself always a `requires_approval` action no agent can perform. Continuous Audit evaluates control tests against the full transaction population every day instead of sampling once a quarter. Continuous Cash Flow Monitoring, Predictive Finance, and the Simulation Engine give a company lead time on a cash shortfall or a covenant breach before it lands. A worked example from the running tenant makes the payoff concrete: Al-Noor Trading & Contracting W.L.L. (company 4821), a Kuwait-based building-materials trader with Kuwait City, Al-Ahmadi, and Riyadh branches, closed June under its pre-QAYD process on July 11 — eleven days late — because accruals were reconstructed from memory and the bank statement was reconciled only after it arrived; under Autonomous Closing the close-readiness score already read 100% at June 30, and the owner approved the period lock at 09:14 on July 1, a same-day sign-off with no less rigor because every check that used to happen once now happens daily.

# 6. Accounting Platform

## The correct core

Under everything the AI does is a correct, auditable, immutable double-entry ledger — the moat, not a commodity. The accounting platform is specified across [../accounting/](../accounting/) and implemented by [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md). Its non-negotiables, fixed by ADR-006, are that debits always equal credits, posted records are immutable, and corrections happen by reversing entry rather than by edit. Money is represented as `NUMERIC(19,4)` and rendered with KWD three-decimal (fils) precision (ADR-009).

## General Ledger, Chart of Accounts, Journal Entries

The Chart of Accounts ([../accounting/CHART_OF_ACCOUNTS.md](../accounting/CHART_OF_ACCOUNTS.md)) is templated per company and bilingual, organized into seven account natures — Assets, Liabilities, Equity, Revenue, Expenses, Other Income, Other Expenses — with unlimited nesting, parent/child accounts, groups and subgroups, tree-integrity rules, automatic or custom account codes, and country and industry templates. Journal Entries ([../accounting/JOURNAL_ENTRIES.md](../accounting/JOURNAL_ENTRIES.md)) are the atomic double-entry fact QAYD is named for (قيد); they have a strict lifecycle (draft → posted → reversed), normal-balance rules by account type, a signed debit/credit schema, and an enforced balance invariant checked by `PostingService` and re-checked by database constraints and a `chk_journal_entries_balanced` guard. Entries carry an `origin` (including `ai_draft`), and a `trg_no_ai_autopost` trigger exists from the first migration so the AI gate is in place before the first agent arrives. The General Ledger ([../accounting/GENERAL_LEDGER.md](../accounting/GENERAL_LEDGER.md)) is the posted projection; a nightly integrity rebuild must reproduce byte-identical statements across all posting modules.

## Financial Statements, Trial Balance, Reporting

The Trial Balance ([../accounting/TRIAL_BALANCE.md](../accounting/TRIAL_BALANCE.md)) proves the ledger balances; the four financial statements — Income Statement, Balance Sheet, Cash Flow, and Statement of Changes in Equity ([../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md)) — are derived views over posted `journal_lines` that store no primary data of their own and must satisfy the accounting equation. Reporting ([../accounting/REPORTS.md](../accounting/REPORTS.md), [../backend/REPORTING_SERVICE.md](../backend/REPORTING_SERVICE.md)) generates statements on demand or on schedule, exports to PDF/Excel in Supabase Storage, and, through the Reporting Agent, attaches narrative commentary that names the driver of any material period-over-period change.

## Banking and Reconciliation

Banking ([../accounting/BANKING.md](../accounting/BANKING.md)) covers bank accounts, transaction import (statement, then live feed), transfers, and receipts. Reconciliation is where the Treasury Manager earns its keep: a three-pass matching algorithm — deterministic match (0.98–1.00 confidence, auto-matched because matching is observational, not money-moving), fuzzy match (0.80–0.97, auto above the company's floor, else one-click), and an exception queue for no-match — culminating in a `bank_reconciliations` record whose `confirmed` status always requires a human click, because that is the evidence an external auditor or bank will ask to see. In the running example, Al-Noor Trading's KWD account receives 214 lines in June; the Treasury Manager auto-matches 198 in Pass 1 and 11 more in Pass 2 (a customer paying from a personal account under a slightly different name, matched at 0.93 similarity against three years of prior payments), and leaves 5 in the exception queue — including a KWD 42.000 bank charge tagged "likely bank fee, no matching entry" at 0.71 confidence and routed to the bookkeeper rather than force-matched. The reconciliation closes at KWD 0.000 variance once the missing fee entry is posted and the human confirms.

## Corrections, immutability, and the audit trail

Because posted records are immutable (ADR-006), a mistake is never silently rewritten; it is corrected by a linked reversing entry in the next open period, and the original and its reversal both survive in the ledger — the history is the point, mirroring the ADR log's own rule. This is what makes QAYD defensible to an auditor: every figure in every statement traces back through `journal_lines` to a source document stored in Supabase Storage, and every consequential mutation writes an immutable audit record inside the same transaction as the change. Fiscal periods, once locked by a human with the `accounting.period.close` permission, make every entry inside them immutable for ordinary correction; entries touching a closed or locked period are always `requires_approval`, never auto-posted, regardless of AI confidence.

## Sales, Purchasing, Inventory, Payroll, Tax, Assets

| Domain | Core capability | Ledger posting |
|---|---|---|
| Sales | Customers, invoices, receipts, AR aging | `invoice.posted` → AR; `invoice.paid` → cash |
| Purchasing | Vendors, POs, bills, three-way match, payments | `bill.approved` → AP; mismatch blocked before posting |
| Inventory | Products, warehouses, movements, valuation | `goods.received` → GRNI; cannot go negative (`insufficient_stock` guard) |
| Payroll | Employees, runs, payslips, PIFSS/WPS, GOSI/Mudad | `payroll.released` → salary/deductions/liability; release always human-approved |
| Tax | Jurisdiction tree, codes/rates (effective-dated), input/output VAT per transaction, returns | `tax.return.submitted` → liability; submission always human-approved |
| Assets | Fixed-asset register, depreciation runs (posted in the close) | Depreciation → journal via General Accountant |

Tax ([../accounting/TAX.md](../accounting/TAX.md), [../backend/TAX_SERVICE.md](../backend/TAX_SERVICE.md)) is the single authoritative calculation subsystem — no other module embeds jurisdiction logic — and supports VAT/GST, sales/use tax, withholding, corporate, income, payroll, excise, and digital-services tax across jurisdiction trees, with `POST /api/v1/tax/calculate` as the one pricing entry point. The continuous variants — Autonomous Closing (a daily eleven-check close-readiness score so period-end is a lock-and-sign-off), Continuous Audit (full-population control tests, not sampling), and Continuous Tax Review — are the accounting platform's expression of the "continuous, not periodic" pillar.

# 7. User Experience

## UX strategy and design language

QAYD's interface is the only place the product's trust becomes visible, and its design language ([../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md), [../design-system/](../design-system/)) is deliberately distinctive: **brass editorial**. It is built first in near-monochrome — a twelve-step warm-neutral ink scale — with exactly one brand accent, a restrained brass, rationed to the single primary action, the focus ring, the active tab, and anything the AI has touched. Numbers are the hero (tabular figures, right-alignment, generous row height, hairline dividers), density is achieved with air rather than clutter, and motion explains rather than entertains — every animation answers a question in under 300ms, and a posted journal entry is met with quiet certainty, never confetti. The archetype is the calm expert in the room: the register of a well-run private bank's annual report or a Swiss watchmaker's product page, never growth-hacker urgency or emoji in product chrome. The name QAYD (قيد) is the accounting term for a journal entry, and the brand leans into that literalism.

## Typography, navigation, and flows

Typography uses four families each doing one job: General Sans (Latin display), Inter (Latin text/UI, chosen for true tabular figures at 12–14px), IBM Plex Sans Arabic (both Arabic display and text), and JetBrains Mono (account codes, IDs). The core user flow, specified in [../frontend/flows/](../frontend/flows/), runs Landing → Auth ([LOGIN_FLOW.md](../frontend/flows/LOGIN_FLOW.md)) → Create Company ([CREATE_COMPANY_FLOW.md](../frontend/flows/CREATE_COMPANY_FLOW.md)) → Dashboard (AI Command Center) → core modules, with onboarding aiming to reach the first reconciled books inside the session. The AI stays humble in the UI exactly as in the API: a proposed journal entry, forecast, or anomaly flag is always visually distinct — same brass accent, "Suggested" language, confidence affordance — from anything a human has approved or the system has posted.

The principal flows each have a dedicated specification and a consistent shape: a clear entry point, a small number of decisive steps, an explicit human gate wherever money or legal weight is involved, and a confirmation that reads as quiet certainty.

| Flow | Entry → outcome | Human gate | Spec |
|---|---|---|---|
| Login | Credentials + MFA on financial roles → authenticated session, company chosen | — | [LOGIN_FLOW.md](../frontend/flows/LOGIN_FLOW.md) |
| Create company | Onboarding wizard → seeded COA, roles, agent settings, first reconciled books | — | [CREATE_COMPANY_FLOW.md](../frontend/flows/CREATE_COMPANY_FLOW.md), [ONBOARDING_FLOW.md](../frontend/flows/ONBOARDING_FLOW.md) |
| Create invoice | Draft (or AI-drafted) → priced via `tax/calculate` → posted AR | Approval on sensitive documents | [CREATE_INVOICE_FLOW.md](../frontend/flows/CREATE_INVOICE_FLOW.md) |
| Bank connection + reconciliation | Connect feed / import statement → three-pass match → confirmed reconciliation | Human confirms the reconciliation | [BANK_CONNECTION_FLOW.md](../frontend/flows/BANK_CONNECTION_FLOW.md), [BANK_RECONCILIATION_FLOW.md](../frontend/flows/BANK_RECONCILIATION_FLOW.md) |
| Approval | Proposal → maker-checker chain → approve/reject with reason | Maker ≠ approver enforced | [APPROVAL_FLOW.md](../frontend/flows/APPROVAL_FLOW.md) |
| Month-end close | Daily readiness → period-end review → lock | Human locks the period | [MONTH_END_CLOSE_FLOW.md](../frontend/flows/MONTH_END_CLOSE_FLOW.md) |
| Ask AI | Natural-language question → cited answer / ranked recommendation | Sensitive actions route to Approval Center | [AI_CHAT_FLOW.md](../frontend/flows/AI_CHAT_FLOW.md) |
| Import from another system | Upload → mapped, validated import of existing books | — | [IMPORT_ACCOUNTING_SYSTEM_FLOW.md](../frontend/flows/IMPORT_ACCOUNTING_SYSTEM_FLOW.md) |

## Accessibility, responsive surfaces, dark mode, and bilingual RTL

QAYD targets WCAG 2.2 AA ([../design-system/ACCESSIBILITY.md](../design-system/ACCESSIBILITY.md), [../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md)): AA contrast on ink and accent, visible focus rings, full keyboard operability, correct semantics, and motion gated by `prefers-reduced-motion`. The interface is responsive across desktop, tablet, and mobile ([../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md), [../frontend/TABLET_APP.md](../frontend/TABLET_APP.md), [../frontend/MOBILE_APP.md](../frontend/MOBILE_APP.md)), with the mobile app performing the same permission-checked actions as web against the same `/api/v1`. Dark mode ([../design-system/DARK_MODE.md](../design-system/DARK_MODE.md)) is a first-class theme, not an inversion. Arabic is a first language, not a mirrored afterthought: every screen is designed and reviewed in Arabic before it is considered done, with correct Arabic typography, Western-Arabic (latn) numerals in the Arabic locale (ADR-009), and correct icon mirroring — a screen that sounds premium in English and merely serviceable-translated in Arabic has failed half its audience.

# 8. Backend

## Architecture and services

The backend ([../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)) is the Laravel 12 (PHP 8.4+) modular monolith that enforces three properties no client may be trusted to enforce: correctness (double-entry always balances, money never double-posts, posted records are immutable), isolation (company A can never observe or mutate company B), and authority (every action authenticated, authorized against RBAC, validated, and audited). It is a modular monolith by deliberate decision (ADR-001) because a single sale posts to the GL, decrements inventory, accrues tax, and updates reports inside one atomic transaction — distributing that across microservices would force sagas onto the exact operation where correctness is least negotiable. The AI engine is the one carve-out: a separate FastAPI/Python service, because its ML/OCR/LLM ecosystem and second-scale latency profile do not belong in the PHP request cycle. Each module is internally layered and communicates with peers through events and service interfaces, never through another module's tables. The per-service specs ([../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md), and `*_SERVICE.md` per module) specialize the patterns established there.

## Events, database, API, caching, queues, storage

Cross-module effects travel through domain events and queued listeners; the request lifecycle is a fixed pipeline — global middleware (TrustProxies, CORS, JSON force-accept) → Sanctum auth (verify bearer, resolve the user) → `ResolveTenantCompany` (validate `X-Company-Id` against live `company_users`, open a transaction, `SET LOCAL app.company_id`) → `CheckPermission` deny-by-default gate → `FormRequest` whitelist validation → Action/Service (the use case, transactions, event emission) → Model/Repository (persistence behind the `CompanyScope` and RLS) → Resource → the standard response envelope. Controllers are thin and never hand-filter by `company_id` for isolation; the tenant scope is ambient and enforced, not passed. The canonical event flow is illustrative of the whole platform: an invoice is paid → the Sales module publishes an event → Accounting posts the journal → Banking updates cash → Reports refresh → AI analyzes → the dashboard updates over Reverb, all without any module reaching into another's tables. The database is PostgreSQL 15, single shared schema with RLS ([../database/](../database/)); Redis backs cache, sessions, queue, locks, and AI working memory; Supabase Storage stores objects. The API is REST/JSON, versioned under `/api/v1` (ADR-008, 12-month deprecation), with a standard envelope (`success`/`data`/`message`/`errors`/`meta`/`request_id`/`timestamp`), idempotency via `Idempotency-Key`, cursor pagination, and a published OpenAPI contract ([../api/](../api/)). Heavy work — OCR, report generation, bulk import, AI analysis, webhook delivery, notifications — runs async on per-purpose queues (`realtime`, `default`, `ai`, `reports`, `integrations`), never inline.

| Concern | Mechanism | Spec |
|---|---|---|
| Realtime | Laravel Reverb, one-way backend → client, company-scoped private channels (ADR-011) | [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md) |
| Auth | Sanctum cookie sessions (web) + RS256 JWT (mobile, engine, partners) (ADR-004) | [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md) |
| Idempotency | `EnforceIdempotency` middleware; offline mobile queue replays safely | [../api/API_IDEMPOTENCY.md](../api/API_IDEMPOTENCY.md) |
| Monitoring / logging | Sentry, Prometheus, Grafana; Laravel/audit/AI/security logs | [../api/API_MONITORING.md](../api/API_MONITORING.md), [../api/API_LOGGING.md](../api/API_LOGGING.md) |

## Monitoring and logging

Every state transition writes an immutable audit record inside the same transaction as the change and, where relevant, broadcasts a realtime event. Observability spans Sentry for error tracking, Prometheus for metrics, and Grafana for dashboards, over four log streams — application, audit, AI, and security — with per-request `request_id` correlation carried through the envelope. The AI engine is drawn as a peer, not a layer: it calls the backend back through the internal API ([../api/INTERNAL_API.md](../api/INTERNAL_API.md)) for any read or write, holds no authoritative state, and is rate- and cost-governed at that boundary.

# 9. Frontend

## Architecture and state management

The web client ([../frontend/FRONTEND_ARCHITECTURE.md](../frontend/FRONTEND_ARCHITECTURE.md), [../frontend/README.md](../frontend/README.md)) is Next.js 15 with React 19 and TypeScript in strict mode, styled with Tailwind CSS and shadcn/ui on Radix primitives, animated with Framer Motion, and iconed with Lucide. It is an unprivileged client of `/api/v1` (ADR-003): if the Next.js process is compromised it holds no database credentials and no AI key — it can only make the same permission-checked calls a user could. Server state is owned entirely by TanStack Query (ADR-004/005): there is one owner of server cache, and local UI state is kept separate and minimal. The BFF/SSR layer renders and proxies; it never becomes a privileged component.

## Components and design system

The component library ([../frontend/COMPONENT_LIBRARY.md](../frontend/COMPONENT_LIBRARY.md), [../design-system/components/](../design-system/components/)) is owned in-repo rather than pulled from an opaque UI dependency (ADR-010), so QAYD controls every primitive — buttons, inputs, cards, tables, drawers, modals, filters, search, toasts, and the AI-specific widgets (AI widget, command palette, approval patterns, confidence badges). The design system is tokenized ([../design-system/DESIGN_TOKENS.md](../design-system/DESIGN_TOKENS.md), [../design-system/foundations/](../design-system/foundations/)): the ink scale, the single brass accent, four semantic financial states (success green, info blue, warning orange, error red), tight corner radii, a disciplined spacing scale, an opacity scale, an elevation/shadow scale, and a motion system are all literal `:root` variables and `tailwind.config.ts` entries an engineer or AI agent can implement without a follow-up question. AI interaction patterns ([../design-system/patterns/AI_INTERACTION_PATTERNS.md](../design-system/patterns/AI_INTERACTION_PATTERNS.md)) codify how a proposal, a confidence score, and an approval affordance look and behave.

## Performance

Performance targets are fixed by product principle: the dashboard loads in under two seconds, search returns in under 300ms, and AI responses arrive in under five seconds where possible. The frontend meets them through server-side rendering for first paint, TanStack Query caching and background revalidation, virtualized dense tables for thousand-line ledgers, code-splitting per route, and Reverb-pushed deltas so a live dashboard updates without polling. Every dense table achieves density through smaller disciplined type and tighter row heights rather than by removing the whitespace that separates ideas — a dense table on a spacious page reads as expert. Visual regression is tested across light/dark × LTR/RTL so no locale or theme is ever an afterthought.

## Internationalization and RTL as a rendering discipline

Bilingual Arabic/English with full RTL is a frontend architecture concern, not a translation task. Every user-facing string is keyed and localized in both languages; there are no English-only screens, and generated reports carry both languages. The Arabic locale is not a blind logical mirror of the English layout: the `dir` attribute flips the document, but Arabic typography (IBM Plex Sans Arabic across the whole hierarchy), correct icon mirroring (directional icons flip, brand and semantic icons do not), and Western-Arabic (latn) numerals inside Arabic text (ADR-009) each follow their own correct rules, and every screen is reviewed in Arabic before it is considered done ([../frontend/THEMING.md](../frontend/THEMING.md), [../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md)). Mixed EN/AR strings — a currency code or a Latin-script partner name inside an Arabic sentence — are kept from visually lurching between scripts because the Arabic and Latin faces were chosen to be metrically sympathetic.

## Screens, data flow, and the AI surface in the client

The screen inventory ([../frontend/screens/](../frontend/screens/)) covers the home/AI Command Center, Accounting, Sales, Purchasing, Banking, Inventory, Payroll, Reports, Approval Center, Document Center, Search, Notifications, Settings, and Profile — each a composition of the in-repo component library over TanStack Query data. Data flows one way: the client reads and writes only through `/api/v1`, holds server state in TanStack Query, receives live deltas over company-scoped private Reverb channels, and never embeds business logic — a computed balance is always the server's number, never the client's. The AI surface is a first-class part of the client: the assistant entry point, the proposal-review affordances, and the confidence badges are shared components governed by the AI interaction patterns, so a proposal looks and behaves identically whether it appears in the Command Center, an inline document-review drawer, or the mobile app — the humility of the AI is enforced in the component, not left to each screen to re-implement.

# 10. Security

## Defense-in-depth

Security is by design, present from day one, and organized as ten concentric layers ([../security/SECURITY_ARCHITECTURE.md](../security/SECURITY_ARCHITECTURE.md)): an attacker must defeat every layer between them and an asset; a defender needs only one to hold. The security model assumes compromise of any single layer and is built so that no single failure is a breach. The most important property is that L3, L4, L6, and L7 are four *independent* enforcements of the same tenant predicate, written by different code, each failing closed.

The threat model ([../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md)) defends primarily against the malicious-or-curious tenant — a legitimately authenticated user of Company A who tries to read, write, guess, or infer the existence of Company B's data — and against the infrastructure adversary who reaches a pooled connection, a cache, a search index, or a backup. The concrete attacks it is engineered to defeat are ID enumeration (incrementing `/invoices/90441` hoping for a foreign row), parameter smuggling (a second `company_id` in a body or nested relation), header forgery (an `X-Company-Id` for a company the user does not belong to), the unscoped-query bug (a future engineer's raw `DB::table(...)->find($id)` bypassing the Eloquent scope), the pool leak (a PgBouncer-reused connection carrying prior tenant state), the sidecar leak (a Redis key, search document, or Supabase Storage object without a tenant namespace), and the cross-tenant write (an `UPDATE` rewriting a row's `company_id`). Each is caught by at least one of the four independent enforcements, and isolation extends to every store — PostgreSQL, Redis, search, Supabase Storage, and the job queue — because a boundary that holds only in the database is not a boundary.

| Layer | Control | Spec |
|---|---|---|
| L0 Edge | Cloudflare WAF, DDoS, bot mitigation, TLS | [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| L1 Transport | TLS 1.2+ (1.3 preferred), HSTS, mobile cert pinning, mTLS service-to-service | [../security/AUTHENTICATION.md](../security/AUTHENTICATION.md) |
| L2 Authentication | Sanctum sessions/tokens, MFA/TOTP on financial roles, revocation, lockout | [../security/AUTHENTICATION.md](../security/AUTHENTICATION.md) |
| L3 Tenant resolution | `X-Company-Id` validated against membership + live `company_users` | [../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md) |
| L4 Authorization | RBAC `<area>.<action>` / `<area>.<entity>.<action>`, deny-by-default, Policies, two-key chains | [../security/AUTHORIZATION.md](../security/AUTHORIZATION.md) |
| L5 Input validation | Form Requests (whitelist), OpenAPI schema check, mass-assignment guard | [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| L6 Data-access scoping | Eloquent `CompanyScope` global scope, tenant-scoped route-model binding | [../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md) |
| L7 Database RLS | PostgreSQL row-level security keyed on per-request GUCs (`app.company_id`, `app.user_id`) | [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) |
| L8 Data at rest | AES-256-GCM on Restricted columns, encrypted volumes, signed storage URLs | [../security/ENCRYPTION.md](../security/ENCRYPTION.md) |
| L9 Audit & detection | Immutable audit log, access-log alerting, anomaly heuristics | [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) |

## Authentication, authorization, encryption, audit

Authentication is split by client (ADR-004): Sanctum cookie sessions for the web SPA (with CSRF and the stateful guard), and RS256 JWTs for mobile, the AI engine, and partners, with MFA/TOTP required on financial roles and a revocation denylist. Authorization is deny-by-default RBAC with an `<area>.<action>` / `<area>.<entity>.<action>` grammar, Policy classes for per-model checks, and two-key approval chains for sensitive operations; everything is denied unless explicitly granted, and the AI follows the same rules as humans. Encryption applies AES-256-GCM at the application layer to Restricted columns on top of volume encryption. The audit trail is immutable and tamper-evident, written inside the same transaction as the mutation, recording user, company, IP, device, permission, time, and result for every consequential action and every permission check.

## Tenant isolation, compliance, and privacy posture

Tenant isolation ([../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md)) is the single assumption the multi-tenant business rests on and is enforced four times independently: the `X-Company-Id` boundary (`ResolveTenantCompany` middleware validating live, non-expired `company_users` membership), the Eloquent `CompanyScope`, the Policy re-check, and PostgreSQL RLS reading a per-request `SET LOCAL app.company_id` GUC. The database roles (`qayd_app`, `qayd_worker`, `qayd_ai`, `qayd_support`) are all `NOBYPASSRLS`; the platform runs behind PgBouncer in transaction pooling; absence of tenant context returns zero rows, never all rows; and a cross-tenant resource resolves to `404`, never `403`, because a `403` would confirm the row exists. QAYD's compliance and privacy posture ([../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md)) targets SOC 2 Type II and ISO 27001 controls and GDPR-aligned data-subject handling (access, rectification, erasure via the platform's soft-delete and archiving mechanics), with GCC data-residency honored through the multi-region topology in Section 11. AI security ([../security/AI_SECURITY.md](../security/AI_SECURITY.md)) extends the model to prompt-injection resistance and the never-auto-commit boundary.

# 11. Infrastructure

## Cloud, containers, and topology

QAYD deploys as three application tiers in front of a shared data plane ([../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md)): the Next.js web tier (Node.js, SSR/BFF, no DB credentials), the Laravel API tier (php-fpm + nginx, the only tier holding business-state credentials), and the FastAPI AI tier (Python, no DB access, calls the internal API with a scoped token). Behind them sit PostgreSQL 15 (primary + read replica), Redis (cache/queue/session/locks), Laravel Reverb nodes for WebSockets, Supabase Storage object storage, per-queue workers, and a singleton scheduler. Cloudflare is the edge (CDN, WAF, DDoS, TLS). All services are containerized with Docker; production runs on AWS with Kubernetes orchestration, development on Hetzner ([../deployment/CLOUD.md](../deployment/CLOUD.md), [../deployment/AWS.md](../deployment/AWS.md), [../deployment/SELF_HOSTING.md](../deployment/SELF_HOSTING.md)). The privilege gradient is the load-bearing structural fact: `client → web → API → data`, so a compromised web renderer holds no keys.

## CI/CD, monitoring, scaling, backups, disaster recovery

CI/CD runs on GitHub Actions with automatic testing and deployment; a release never takes traffic against a schema it was not built for — migrations gate the rollout, and secrets are injected at deploy time from a vault, never baked into an image or shipped in the browser bundle. Observability is Sentry + Prometheus + Grafana. The stateless tiers scale horizontally (web on request latency, API on CPU/latency, workers on per-queue backlog, Reverb on concurrent connections); the data plane scales vertically and by managed replica. Backups and recovery ([../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md)) provide point-in-time recovery with tested restores; disaster recovery and residency are delivered by the multi-region posture ([../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md)) — active-passive DR standby, residency-pinned regions, and a rehearsed failover runbook exercised against stated RTO/RPO.

| Concern | Approach | Spec |
|---|---|---|
| Containers / orchestration | Docker images per tier; Kubernetes in production | [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md) |
| CI/CD | GitHub Actions; migration-gated rollout; vault-injected secrets | [../developer/RELEASE_PROCESS.md](../developer/RELEASE_PROCESS.md) |
| Monitoring | Sentry (errors), Prometheus (metrics), Grafana (dashboards) | [../api/API_MONITORING.md](../api/API_MONITORING.md) |
| Scaling | Horizontal stateless tiers; per-queue workers; DB replicas | [../deployment/CLOUD.md](../deployment/CLOUD.md) |
| Backups / DR | PITR + tested restores; active-passive multi-region failover | [../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md) |

## Environments, queues, and the release path

Environments are staged — local ([../developer/LOCAL_SETUP.md](../developer/LOCAL_SETUP.md)), CI, staging, and production — and every target (single-node, Kubernetes, managed cloud) shares the same invariants: the same containers, the same migration-gated rollout, and the same privilege gradient. Background work is partitioned across dedicated Redis queues so a hot payroll run or a slow report never starves realtime delivery: `realtime` for latency-sensitive broadcasts, `default` for ordinary jobs, `ai` for OCR and agent analysis, `reports` for statement and export generation, and `integrations` for webhook delivery and external syncs — each consumed by its own worker pool scaled on its own backlog. A single scheduler node runs `schedule:run` as a singleton for the daily jobs (close-readiness sweeps, briefing generation, report schedules, SLA escalations). Self-hosting collapses this topology onto one customer-owned machine for tenants with strict residency needs, and the managed-cloud mapping ([../deployment/CLOUD.md](../deployment/CLOUD.md)) substitutes each component with its managed equivalent without changing the application. The whole model exists to preserve at runtime the three properties the backend enforces in code — correctness (migrations gate the rollout), isolation (the web tier holds no database credentials), and authority (secrets come from a vault at deploy time, never from an image).

# 12. Business

## Pricing, licensing, and subscriptions

QAYD's commercial model is a per-company SaaS subscription, priced for the GCC SME and denominated in KWD, with usage-metered AI at the margin. Licensing is per company (tenant), with seats inside a company and an AI-operations allowance scaled by tier; the correct-ledger core is always included, and AI autonomy widens with tier and with accumulated trust rather than being a separate paywalled product. The proposed tiering below is a planning baseline consistent with the freelancer-to-enterprise principle ([../foundation/PRODUCT_PRINCIPLES.md](../foundation/PRODUCT_PRINCIPLES.md)); exact prices are set commercially per phase.

| Tier | Target | Included | AI posture |
|---|---|---|---|
| Starter | Freelancer / micro-business | Ledger, invoicing, bank import, core reports, 1–2 seats | Proposal-only AI, capped AI operations |
| Business | 10–40-person SME (primary ICP) | Full Accounting, Sales, Purchasing, Banking, Tax, Payroll (PIFSS/WPS), scheduled reporting, copilot | Full 15-agent workforce, suggest-only + scoped low-risk autonomy |
| Enterprise | Multi-branch / multi-entity | Multi-currency, consolidation depth, additional GCC jurisdictions, SSO, priority support, higher AI allowance | Predictive/simulation, org-wide copilot, custom autonomy policy |
| Partner / Firm | Accounting firms serving many clients | Multi-client management, scoped access, partner tooling | Per-client workforce; firm-level oversight |

## Marketplace, partner program, public API, developer platform

The platform opens to third parties through a versioned public and partner API ([../api/PUBLIC_API.md](../api/PUBLIC_API.md), [../api/PARTNER_API.md](../api/PARTNER_API.md)): scoped API keys in the `qyd_live_…` / `qyd_test_…` form, OAuth `client_credentials` grants issuing JWTs that carry `company_id`, `partner_connection_id`, and `scope` claims, a `country_scope` (ISO 3166-1 alpha-2, e.g. `["KW","SA","AE"]`), granular scopes (e.g. `erp.sync.read`, `gateway.charge.write`), a partner rate tier, sandbox and certification plumbing, and SDK guidelines ([../api/API_SDK_GUIDELINES.md](../api/API_SDK_GUIDELINES.md)). A restricted partner key can be limited to invoices and customers with no payroll access, and every partner call passes the same scope and RBAC checks as any other caller before touching a row. The integration marketplace ([../frontend/INTEGRATIONS.md](../frontend/INTEGRATIONS.md)) is a discoverable, installable catalog of connectors — banks and open-banking feeds, payment gateways, government e-invoicing, ERP/POS/CRM — each passing through the single integration layer. The developer platform is the same `/api/v1` the first-party clients use, with a 12-month deprecation policy (ADR-008) so an integration written against a Phase 1 endpoint keeps working through Phase 4.

## Growth strategy and go-to-market

Go-to-market is Kuwait first, GCC next, world after — GCC-first and Kuwait-first in localization depth and distribution, on a globally-capable architecture. The wedge is the correct, AI-native, Arabic-first ledger for the Kuwaiti SME that the incumbents can only retrofit; the expansion motion moves from Kuwait's PIFSS/WPS and pending-VAT context outward to Saudi Arabia (live VAT, GOSI/Mudad), the UAE, Bahrain, and Oman, with the accounting-firm channel (the Partner/Firm tier) as a force-multiplier because a single firm brings many client tenants. The long-term commercial trajectory follows the mission's own arc: from the SME accounting operating system, to the whole-company operating system, to a platform whose module count and marketplace make it the default financial substrate of the region — with per-company learning making each tenant stickier the longer it stays.

# 13. Product Roadmap

## Phased delivery

The roadmap ([FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md)) is ordered by dependency, not ambition: nothing that writes tenant data ships before the tenant boundary and auth are proven, nothing that posts to the ledger ships before the posting engine is proven, AI is added in layers of trust (proposal-only first, scoped autonomy only after an accepted-proposal track record), the test pyramid grows with the code, and the public contract is versioned from day one. The MASTER_PRD frames the same delivery as MVP → Phase 2 → Phase 3 → Enterprise; the roadmap sequences it across Phases 0–4 on a Q3-2026-anchored baseline.

| Version / Phase | Theme | Primary outcome | AI posture |
|---|---|---|---|
| V1 — Phase 0 (Foundations) | Trustworthy before useful | A tenant signs in against the correct boundary and keeps a correct, immutable ledger by hand | None in product; engine scaffolded; AI-never-writes gate in code |
| V1 — Phase 1 (MVP) | The AI-native ledger | Bank, reconcile, report, and meet the first AI proposals (categorization, bank matching) under proposal-and-approve; AI Command Center v1 | Proposal-only |
| V2 — Phase 2 (Commercial core) | The full finance suite | Order-to-cash and procure-to-pay with GCC tax; workflow/approval engine; OCR/Document AI; copilot; realtime; search | Proposal-only + OCR; per-tenant memory |
| V3 — Phase 3 (Operations + autonomy) | Predictive + autonomous | Inventory and Payroll (PIFSS/WPS); automation engine; continuous close/audit/tax; full 15-agent roster; forecasting/simulation | Scoped autonomy for pre-approved low-risk classes only |
| V4 — Enterprise (Scale) | Scale and reach | Flutter mobile at parity; partner/public API + marketplace; external integrations; multi-region DR + residency; consolidation depth | Predictive/simulation; org-wide copilot |

## Future vision

Beyond V4, the trajectory returns to the mission: accounting becomes one module inside a larger intelligent business platform, the module count grows toward the fifty-plus independent modules the architecture anticipates, and the marketplace and partner ecosystem make QAYD the operating system a GCC company opens every morning. Three guarantees hold across every phase and are never traded for speed: one posting path (no module ever gains its own ledger-write shortcut), AI proposes and the human disposes (no phase moves an item off the sensitive-operations list), and the contract only grows (additive forever; a breaking change is a new major with a 12-month sunset).

## The calendar baseline and how autonomy widens

The roadmap's calendar ([FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md)) is anchored to a Q3 2026 start and expresses sequence and relative effort, not a fixed commitment: Phase 0 in Q3 2026 (auth, tenancy + RLS, accounting core, audit, CI + the test harness scaffold); Phase 1 across Q4 2026–Q1 2027 (banking, reconciliation, reporting, notifications, files, PostgreSQL search, Reverb, then the first proposal-mode agents and the AI Command Center v1); Phase 2 across 2027 (sales, purchasing with three-way match, GCC tax, the workflow engine, OCR); Phase 3 across late 2027–2028 (inventory, payroll with PIFSS/WPS, the automation engine, scoped autonomy, continuous close, and the deeper Auditor/Fraud/Forecast/Treasury agents); and Phase 4 from 2028 (Flutter mobile, partner/public API, integrations, multi-region, predictive/simulation, org-wide copilot). Slippage in an earlier phase pushes later phases rather than overlapping them across a dependency boundary. Autonomy is added in layers of trust and never all at once: every AI capability enters as proposal-only, and autonomy for a class of action widens only after that class has accumulated a track record of accepted proposals — and never for the sensitive-operations list. This is why a body of accepted proposals is itself an entry criterion for Phase 3, and why the product's own usage data, per company, governs how far the AI is allowed to run.

# 14. Development Plan

## Execution roadmap and sprint plan

Execution begins with Sprint 01 — Foundations ([SPRINT_01.md](./SPRINT_01.md)), organized into epics that stand up the monorepo and CI/CD skeleton (Epic A), the database, multi-tenancy, and RLS (Epic B), authentication and RBAC (Epic C), company onboarding (Epic D), the app shell and i18n (Epic E), and cross-cutting foundations (Epic F). Sprint 02 ([SPRINT_02.md](./SPRINT_02.md)) builds on that spine toward the accounting core. Each phase's exit gate is not feature-completeness but the relevant slice of the test pyramid passing — most importantly the tenant-isolation/RLS security band and, for posting modules, the double-entry and idempotency integration tests — because in this platform a missed test is a wrong debit, not a cosmetic bug.

## Engineering workflow, testing, and QA

Engineering follows the standards in [../developer/CODING_GUIDELINES.md](../developer/CODING_GUIDELINES.md) and [../foundation/CODING_STANDARDS.md](../foundation/CODING_STANDARDS.md): PSR-12 and Laravel Pint/PHPStan for PHP, TypeScript strict + ESLint + Prettier for the frontend, module boundaries enforced in review, and an ADR carried in the same pull request as the code that first honors a new architectural decision. Testing ([../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md), ADR-012) is a seven-band pyramid whose shape is audited, not aspirational: Unit, Integration, Contract, End-to-End, Security, Performance/Load, and AI-Eval, across the three codebases (Laravel, Next.js, FastAPI). Three invariants get their own dedicated tests: double-entry always balances, tenant isolation never leaks, and the AI can never satisfy a sensitive action — including an AI-engine unit test that forces a sensitive op to `requires_approval` regardless of confidence. Tooling is PHPUnit/Pest, Playwright, and Vitest, with per-tenant fixtures, factories that default to valid, real-guard authentication in every layer, and pinned time and randomness.

## Milestones, release strategy, and deployment

| Milestone | Exit criteria (abridged) |
|---|---|
| Phase 0 done | Journal entry reaches `posted` only with balanced debits/credits in an open period; RLS negative suite passes on every PR; a manual month keeps end-to-end; `/api/v1/accounting` and `/api/v1/auth` OpenAPI published |
| Phase 1 done | A bank payment posts a balanced, idempotent journal by event and never double-posts on redelivery; reconcile-to-zero; four statements render light/dark × LTR/RTL green; AI provably proposal-only; first design-partner tenants |
| Phase 2 done | Invoice priced via `tax/calculate` and approved through workflow posts balanced AR + output tax; three-way-matched bill posts AP, mismatch blocked; VAT return reconciles to ledger; maker ≠ approver on sensitive docs; OCR provably proposal-only |
| Phase 3 done | Goods receipt and released payroll each post balanced idempotent journals; inventory cannot go negative; scoped autonomy live for ≥1 pre-approved class, every auto-commit logged/reversible/attributable; nightly integrity rebuild byte-identical |
| Phase 4 done | Mobile at `/api/v1` parity offline-tolerant; partner key runs with restricted scope and deprecation headers; regional failover meets RTO/RPO with residency honored; ≥1 predictive lead-time warning class in production |

Releases are versioned and communicated per [../developer/RELEASE_PROCESS.md](../developer/RELEASE_PROCESS.md) and [../developer/CHANGELOG.md](../developer/CHANGELOG.md), deployed through the migration-gated CI/CD pipeline of Section 11, with the public contract only ever growing additively under `/api/v1`.

# 15. Appendices

## Glossary

| Term | Meaning |
|---|---|
| QAYD / قيد | The product; the Arabic accounting term for a journal entry — the atomic double-entry fact |
| AI Financial Operating System | QAYD's category: a system of action that operates a company's finances, not a system of record |
| Agent | One of the fifteen specialized AI workforce members (see Section 5 roster) |
| Autonomy level | `auto` \| `suggest_only` \| `requires_approval` — how much friction stands between an AI draft and a posted fact |
| Sensitive operation | Bank transfer, payroll release, tax submission, period close, permission/settings change, deletion/void — always human-gated |
| Proposal / never-auto-commit | Every AI write is a proposal through `/api/v1`; the engine never writes the database directly (ADR-007) |
| Confidence + reasoning + sources | The mandatory contract on every AI output; sources always resolve to a fetchable record |
| Tenant / `company_id` | A company; the isolation key present on every tenant table |
| RLS / GUC | PostgreSQL Row-Level Security keyed on the per-request `app.company_id` session variable (L7) |
| Fils | KWD's three-decimal minor unit; money is `NUMERIC(19,4)` (ADR-009) |
| PIFSS / WPS / GOSI / Mudad | Kuwait social insurance / Kuwait wage protection / Saudi social insurance / Saudi wage platform |
| Command Card | The shared dashboard-widget envelope carrying `confidence_score`, `agent_code`, `reasoning`, `sources`, `status` |

## Architecture-diagram references

The high-level system diagram lives in [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md); the backend request lifecycle and system-context diagram in [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md); the deployment topology in [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md); the agent orchestration graph and boot sequence in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md); the entity-relationship diagram in [../database/ERD.md](../database/ERD.md); and the module dependency map in [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md).

## Decision records

The twelve load-bearing decisions are logged in [../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md): ADR-001 modular monolith + separate AI engine; ADR-002 single-DB RLS multi-tenancy; ADR-003 unprivileged Next.js web tier; ADR-004 split auth (Sanctum web / RS256 JWT elsewhere); ADR-005 TanStack Query as the single server-state owner; ADR-006 immutable double-entry, correct by reversal; ADR-007 AI never auto-commits; ADR-008 URI-path API versioning with 12-month deprecation; ADR-009 `NUMERIC(19,4)` money and Western-Arabic numerals in Arabic locale; ADR-010 in-repo shadcn/Radix primitives; ADR-011 Reverb one-way realtime over company-scoped channels; ADR-012 seven-band audited test pyramid. ADRs are immutable once accepted, numbers are never reused, and one decision lives per record.

## Engineering standards, naming, folder structure, coding standards

Coding standards are fixed in [../foundation/CODING_STANDARDS.md](../foundation/CODING_STANDARDS.md) and [../developer/CODING_GUIDELINES.md](../developer/CODING_GUIDELINES.md); database naming conventions in [../database/DATABASE_NAMING_CONVENTIONS.md](../database/DATABASE_NAMING_CONVENTIONS.md) and standards in [../database/DATABASE_STANDARDS.md](../database/DATABASE_STANDARDS.md); the project structure in [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md). The permission grammar is `<area>.<action>` and `<area>.<entity>.<action>`; the module prefixes are `accounting`, `sales`, `purchasing`, `banking`, `inventory`, `payroll`, `tax`, `reports`, `ai`, `settings`, `identity`, `company`; the Laravel `app/` tree maps namespaces onto the four architectural layers (Presentation → Application → Domain → Infrastructure); the API is `/api/v1` with the standard response envelope; and contribution and review gates are documented in [../developer/CONTRIBUTING.md](../developer/CONTRIBUTING.md) and [../developer/LOCAL_SETUP.md](../developer/LOCAL_SETUP.md).

## Related documents

- Foundation: [../foundation/MISSION.md](../foundation/MISSION.md), [../foundation/PRODUCT_PRINCIPLES.md](../foundation/PRODUCT_PRINCIPLES.md), [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md), [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md), [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)
- AI: [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), [../ai/AI_COMMAND_CENTER.md](../ai/AI_COMMAND_CENTER.md), [../ai/agents/](../ai/agents/), [../ai/memory/](../ai/memory/), [../ai/workflows/](../ai/workflows/)
- Accounting: [../accounting/](../accounting/) (Chart of Accounts, Journal Entries, General Ledger, Financial Statements, Trial Balance, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports)
- Backend / API: [../backend/](../backend/), [../api/](../api/), [../database/](../database/)
- Frontend / Design: [../frontend/](../frontend/), [../design-system/](../design-system/)
- Security / Deployment / Testing / Developer: [../security/](../security/), [../deployment/](../deployment/), [../testing/](../testing/), [../developer/](../developer/)
- Execution: [MASTER_PRD.md](./MASTER_PRD.md), [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md), [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md), [SPRINT_01.md](./SPRINT_01.md), [SPRINT_02.md](./SPRINT_02.md), [OPEN_QUESTIONS.md](./OPEN_QUESTIONS.md)

# End of Document
