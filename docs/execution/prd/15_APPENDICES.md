# 15 — Appendices — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: Appendices
---

# Purpose

This chapter is the reference apparatus for the QAYD PRD: the glossary a reader keeps open while
reading the other chapters, an index into the platform's architecture diagrams, the decision-record
index that says where every load-bearing choice is written down, and a consolidated pointer to the
engineering standards, naming conventions, monorepo folder structure, and coding standards. It is
deliberately an *index*, not a source of truth in its own right — every definition and every standard
below is owned by a specification elsewhere in the tree, and this chapter routes to that owner rather
than duplicating it. When a term here and its owning document ever disagree, the owning document wins.

QAYD is an AI Financial Operating System: a multi-tenant, bilingual (English / Arabic, full RTL),
double-entry accounting core with a Laravel 12 backend as the single source of truth, a Next.js 15 web
client, a FastAPI AI engine that reasons but never writes the database, and a Flutter mobile client at
scale. The vocabulary below spans two worlds — the accounting domain and the QAYD platform — and a
reader fluent in one but not the other needs both to read the rest of this PRD.

# Glossary — accounting terms

| Term | Meaning in QAYD |
|---|---|
| Double-entry | Every financial event records equal debits and credits; QAYD enforces `debit = credit` at post time with zero tolerance, on exact decimals. |
| Chart of accounts (COA) | The tenant's tree of accounts under seven system account types (asset, liability, equity, revenue, expense, other income, other expense); seeded bilingual and templated. |
| Journal entry | The atomic accounting record: a header plus balanced debit/credit lines, moving `draft → pending_approval → posted → reversed/voided`. |
| Posting | The single authorized act of committing a balanced entry into the immutable ledger, done only through the backend posting engine inside one transaction. |
| General ledger (GL) | The projection of all posted lines; account activity and balances are *derived* from it, never stored as a mutable total. |
| Trial balance (TB) | The balanced list of account balances (total debits = total credits) that a period close is checked against; snapshottable and approvable. |
| Fiscal period / close | The calendar unit of accounting (`open → closed → locked`); no posting enters a non-open period, and period close is always human-approved. |
| Reversal / void | The only way to correct a posted record — a new, linked, system-generated mirror entry — because posted records are immutable (never edited in place). |
| Reconciliation | Matching bank-statement lines to ledger transactions (deterministic then fuzzy); the match is observational, and the confirmation is a human checkpoint. |
| VAT / withholding | GCC value-added and withholding tax; computed by one central Tax subsystem, tracked per transaction in V1 and prepared/filed (human-approved) from V2. |
| Three-way match | Reconciling a purchase order, goods receipt, and vendor bill before an AP entry posts; a mismatch is blocked before posting. |
| GRNI / COGS | Goods-received-not-invoiced and cost-of-goods-sold — the inventory postings a goods movement and a sale generate by event. |
| WPS / PIFSS | Kuwait's Wage Protection System file and Public Institution for Social Security contributions — the payroll-compliance specifics a released run posts and produces. |
| Financial statements | Balance Sheet, Income Statement (P&L), Cash Flow, and Statement of Changes in Equity — derived views over posted lines, validated against the accounting equation. |

# Glossary — QAYD platform terms

| Term | Meaning in QAYD |
|---|---|
| Modular monolith | One Laravel application containing every business module, each internally layered and communicating only by events and service interfaces — never another module's tables (ADR-001). |
| Multi-tenancy / RLS | One shared PostgreSQL database with a `company_id` on every tenant table, isolated in depth by an Eloquent scope, an explicit filter, and PostgreSQL Row-Level Security that fails closed (ADR-002). |
| GUC / `app.current_company_id` | The per-request Postgres session variable the RLS policy reads; set via `SET LOCAL` inside the request transaction. Its canonical name is being standardized (see [../OPEN_QUESTIONS.md](../OPEN_QUESTIONS.md), ARC-1). |
| `BelongsToCompany` / `CompanyScope` | The Eloquent trait and global scope that auto-fill `company_id` and inject the tenant predicate; an architecture test asserts every tenant model uses them. |
| `PostingService` | The single authorized writer of posted ledger lines: asserts balance, locks the period, assigns the number, and projects the ledger — invoked identically by a human click and an AI-drafted entry. |
| Money value object / `NUMERIC(19,4)` | Money is fixed-scale decimal, never a float; stored as `NUMERIC(19,4)`, handled through a `Money` value object, and sent as strings on the wire to prevent float coercion (ADR-009). |
| AI engine | The FastAPI/Python service that reasons, drafts, and proposes; it authenticates as an ordinary bearer client and never writes the database directly. |
| Proposal / confidence / reasoning | Every AI action is a proposal submitted through `/api/v1`, carrying a confidence score, its reasoning, and resolvable citations; the frontend is contractually required to render all three (ADR-007). |
| `origin='ai_draft'` | The enum value marking an AI-originated entry; a database trigger rejects auto-posting an `ai_draft`, so the human gate exists before the first agent arrives. |
| AutonomyResolver | The backend component that routes a proposal to execute / suggest / require-approval; it forces every sensitive operation to `requires_approval` regardless of confidence. |
| Sensitive-operations list | The fixed set of actions that stay irreducibly human-approved — bank transfer, payroll release, tax submission, permission/settings changes, deleting financial data — and never shrinks as autonomy widens. |
| Per-company learning loop | Proposals improve from a tenant's own approved corrections, retrieved per company — never shared model weights — so one company's data never shapes another's behavior. |
| `/api/v1` envelope | The uniform response shape `{ success, data, message, errors, meta, request_id, timestamp }` every endpoint returns, unwrapped in one client-side place. |
| Idempotency key | The `(company_id, endpoint, key)` guard on money-moving posts so a retried request never creates a second document; same key + different body is a conflict. |
| Reverb / private company channel | The one-way backend → client realtime layer over company-scoped private channels; a realtime message refreshes cached truth, never a second write path (ADR-011). |
| Agents (roster) | The named AI workers — General Accountant, Treasury, Auditor, Tax, Compliance, Inventory, Payroll, Fraud, Forecast, CFO, CEO — introduced across versions in proposal-then-scoped-autonomy order. |

# Architecture diagram references

QAYD's diagrams live with the specifications they illustrate; this PRD references rather than
redraws them. The primary entry points:

| Diagram / view | Where it lives |
|---|---|
| Six-system decomposition (web, mobile, AI engine, backend API, database, infrastructure) | [../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md) |
| Module map and the "every module owns its tables, communicates by events" rule | [../../foundation/MODULE_ARCHITECTURE.md](../../foundation/MODULE_ARCHITECTURE.md) |
| Backend layering (Presentation → Application → Domain → Infrastructure) and the posting flow | [../../backend/BACKEND_ARCHITECTURE.md](../../backend/BACKEND_ARCHITECTURE.md), [../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md) |
| Entity-relationship diagram and the multi-tenancy/RLS model | [../../database/ERD.md](../../database/ERD.md), [../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md) |
| The seven-band test pyramid | [../../testing/TESTING_STRATEGY.md](../../testing/TESTING_STRATEGY.md) |
| The build-order dependency graph | [../MODULE_DEPENDENCIES.md](../MODULE_DEPENDENCIES.md) |
| The AI loop, boot sequence, and confidence contract | [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) |

# Decision-record index

QAYD records its load-bearing choices as immutable, numbered Architecture Decision Records. The
canonical log of the twelve foundational decisions is
[../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md); ADRs added
after that initial log are mirrored as individual files under
[../../architecture/adr/](../../architecture/adr/), so a pull request that introduces a new
architectural decision carries its ADR in the same diff as the code that first honors it. The
still-open tensions and pending policy calls that shadow the plan — the ones that must be closed and
folded back into the specs before the schema, the first integration, and the security boundary are
frozen — are tracked in [../OPEN_QUESTIONS.md](../OPEN_QUESTIONS.md).

| ADR | Decision |
|---|---|
| ADR-001 | Modular monolith in Laravel with a separate AI engine, not microservices. |
| ADR-002 | Isolate tenants in a single shared database using PostgreSQL Row-Level Security. |
| ADR-003 | Make the Next.js web tier an unprivileged client of `/api/v1`. |
| ADR-004 | Split authentication — Sanctum cookies for web, RS256 JWTs for mobile/engine/partners. |
| ADR-005 | Make TanStack Query the single owner of server state in the web client. |
| ADR-006 | Post double-entry facts immutably — correct by reversal, never by edit. |
| ADR-007 | The AI engine never auto-commits — every AI action is a human-gated proposal through the same API. |
| ADR-008 | Version the API in the URI path (`/api/v1`) with a 12-month deprecation policy. |
| ADR-009 | Represent money as `NUMERIC(19,4)` and render Western-Arabic (`latn`) numerals in the Arabic locale. |
| ADR-010 | Own shadcn/Radix component primitives in-repo rather than depend on an opaque UI library. |
| ADR-011 | Use Laravel Reverb for one-way realtime over company-scoped private channels. |
| ADR-012 | Enforce a seven-band test pyramid whose shape is audited, not aspirational. |

The register's blocking open items (the divergent RLS GUC name, the first-party token TTL, the
`X-Company-Id` identifier type, in-region inference, and residency-versus-DR region) are resolved
first because the schema, the first end-to-end integration, and the security boundary cannot be frozen
until they close — see [../OPEN_QUESTIONS.md](../OPEN_QUESTIONS.md).

# Engineering standards and naming conventions

The platform's naming rules are fixed in
[../../foundation/PROJECT_STRUCTURE.md](../../foundation/PROJECT_STRUCTURE.md) and specialized per
language in [../../developer/CODING_GUIDELINES.md](../../developer/CODING_GUIDELINES.md). At a glance:

| Domain | Convention | Example |
|---|---|---|
| Folders | lowercase, words separated by `_` | `general_accountant/` |
| URLs / route segments | kebab-case, plural, mirroring the API resource | `accounting/journal-entries` |
| Database | snake_case tables and columns; every index leads with `company_id` | `journal_lines`, `(company_id, posted_at)` |
| PHP classes | PascalCase; Actions are single-purpose invokables | `PostJournalEntryAction` |
| Variables | camelCase, meaningful (`invoiceRepository`, never `data`) | `companyUser` |
| Frontend components | PascalCase file matching the default export, one per file | `ConfidenceBadge.tsx` |
| Frontend non-components | kebab-case | `use-trial-balance.ts` |
| Zod schemas / inputs | `<Entity>Schema`, `<Entity>Input` | `JournalEntrySchema` |
| Permission keys | dotted `<area>.<action>` / `<area>.<entity>.<action>`, default-deny | `accounting.journal.post` |
| Money on the wire | strings, not JSON numbers; `NUMERIC(19,4)` at rest | `"120.0000"` |
| RTL styling | logical CSS properties only (`ms-4`, `text-start`), never physical | never `ml-4`/`text-left` |

The RBAC permission grammar is owned by
[../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md); the API envelope and
error taxonomy by the API and coding-guideline docs.

# Monorepo folder structure

QAYD is one monorepo, and every developer, AI assistant, and automation follows the structure fixed in
[../../foundation/PROJECT_STRUCTURE.md](../../foundation/PROJECT_STRUCTURE.md). The top level, as the
contributor guide assumes it:

```text
QAYD/
├── frontend/     Next.js 15 / React 19 / TypeScript (strict) — web client
├── backend/      Laravel 12 / PHP 8.4 — the only write path to business state
├── ai/           FastAPI / Python (typed) — reasons, never writes to the database
├── database/     migrations, seeders, factories, ERD, schema snapshots
├── mobile/       Flutter — native iOS/Android client
├── infrastructure/  docker, terraform, nginx, monitoring, deployment
├── docs/         this documentation tree
└── .github/      workflows, CODEOWNERS, issue/PR templates
```

Each package carries its idiomatic tooling and its own README; ownership and the per-feature "no
feature is complete without controller, service, repository, policy, request, tests, and docs" rule
are in [../../foundation/PROJECT_STRUCTURE.md](../../foundation/PROJECT_STRUCTURE.md), and the
CODEOWNERS review routing in [../../developer/CONTRIBUTING.md](../../developer/CONTRIBUTING.md). The
structure is designed to hold millions of companies and hundreds of AI agents without changing shape.

# Coding standards index

The concrete rules a contributor keeps open while writing code are in
[../../developer/CODING_GUIDELINES.md](../../developer/CODING_GUIDELINES.md), the per-language
specialization of the platform standard in
[../../foundation/CODING_STANDARDS.md](../../foundation/CODING_STANDARDS.md). Three properties override
every stylistic preference, and every rule serves one of them:

- **Correctness.** The ledger always balances, money never double-posts, posted records are immutable,
  and money is never a float.
- **Isolation.** Company A can never read or mutate company B's data; there is no "quick unscoped
  query" in shipped code.
- **Authority and visibility.** Every action is authenticated, authorized against RBAC, validated, and
  audited; every AI-originated figure carries confidence and reasoning and never auto-commits a
  sensitive action.

The mechanical expressions — thin controllers over Actions/Services/DTOs, typed exceptions mapped to
the envelope, `unknown`-not-`any` on the frontend narrowed by Zod, `mypy --strict` and Pydantic
boundaries in the AI engine, and the formatter/linter/type gates that run identically locally and in
CI — are detailed there and enforced by the gates in
[../../developer/CONTRIBUTING.md](../../developer/CONTRIBUTING.md) and
[../../testing/TESTING_STRATEGY.md](../../testing/TESTING_STRATEGY.md). AI-authored code is held to the
identical bar; "the AI wrote it" is never a reason a gate is relaxed.

# How to read this PRD

For a reader arriving at the QAYD PRD for the first time, the three chapters in this folder compose in
one direction. [13_ROADMAP.md](./13_ROADMAP.md) answers *what QAYD becomes and in what order* — the
product versions V1 through V4 and the horizon beyond, each routing to the authoritative phase plan in
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md). [14_DEVELOPMENT.md](./14_DEVELOPMENT.md) answers *how
those versions are built, tested, and shipped* — the sprint model, the seven-band pyramid, and the
release discipline, routing to the sprints, the testing strategy, and the developer guides. This
chapter answers *what every term, decision, standard, and structure means and where it is owned*, so a
reader can resolve any word or reference the other two chapters use without leaving the PRD. The rule
that governs all three is the rule that governs the whole `docs/execution/` folder: these documents
point to specifications; they never replace them. Where a chapter names a capability, its mechanics
live in that capability's own module doc — and where this appendix defines a term, its authoritative
definition lives in the document named beside it.

## Related Documents

- [../MASTER_PRD.md](../MASTER_PRD.md), [../MVP_SCOPE.md](../MVP_SCOPE.md), [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md), [../OPEN_QUESTIONS.md](../OPEN_QUESTIONS.md), [../MODULE_DEPENDENCIES.md](../MODULE_DEPENDENCIES.md) — the execution documents this appendix indexes.
- [13_ROADMAP.md](./13_ROADMAP.md), [14_DEVELOPMENT.md](./14_DEVELOPMENT.md) — the sibling PRD chapters whose vocabulary and references this appendix collects.
- [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md), [../../architecture/adr/](../../architecture/adr/) — the ADR log and the per-decision ADR files.
- [../../developer/CODING_GUIDELINES.md](../../developer/CODING_GUIDELINES.md), [../../developer/CONTRIBUTING.md](../../developer/CONTRIBUTING.md) — coding standards and the workflow/gates that enforce them.
- [../../foundation/PROJECT_STRUCTURE.md](../../foundation/PROJECT_STRUCTURE.md), [../../foundation/CODING_STANDARDS.md](../../foundation/CODING_STANDARDS.md), [../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md) — the folder structure, platform coding standards, and RBAC grammar.
- [../../testing/TESTING_STRATEGY.md](../../testing/TESTING_STRATEGY.md), [../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md), [../../database/ERD.md](../../database/ERD.md), [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) — the diagram and standard sources referenced above.

# End of Document
