# Module Dependencies — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: MODULE_DEPENDENCIES

---

# Purpose

This document is the build-order dependency map for QAYD, the AI Financial Operating System. It answers
one question precisely: *given the platform's modules and cross-cutting services, in what order can they
be built, and why?* It exists because QAYD is a modular monolith whose modules are deliberately
independent at runtime — communicating only through events, queues, and the internal API, never through
another module's tables (see [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)
and [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)) — yet strongly ordered at
*build* time, because a module that posts to the ledger cannot be trusted before the ledger is, and
nothing can hold tenant data before the tenant boundary exists.

The map here is the backbone the phased [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md) sequences against.
Where the roadmap says *when* a module ships, this document says *what it must stand on*. Every
dependency claim is grounded in the real service specifications — chiefly
[../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md),
[../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md),
[../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md), and
[../backend/AUTOMATION_ENGINE.md](../backend/AUTOMATION_ENGINE.md) — and the decisions in
[../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md). It introduces no new
module and no new dependency the specifications do not already imply.

# Two Kinds of Dependency

QAYD has two distinct dependency relationships, and conflating them is the classic error this document
prevents.

- **Build/foundation dependency (hard).** Module B cannot be *implemented and proven correct* until
  module or service A exists. Auth and tenancy are foundations of everything; the Accounting posting
  engine is a foundation of every module that posts. These are strict ordering constraints on the
  roadmap.
- **Runtime/event dependency (soft, decoupled).** At runtime, Sales *causes* an Accounting posting, but
  it does so by emitting `invoice.posted` and letting an Accounting listener react — Sales holds no
  compile-time reference to Accounting's tables or classes (see the inter-module communication rules in
  [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md)). The direction of *causation*
  (Sales → Accounting) is the reverse of the direction of *build dependency* (Accounting must exist
  first so the listener has something to call).

The rule that makes this coherent: **the posting module (Accounting) must be built first, and every
source module depends on it at build time, even though at runtime the source module is the initiator.**
A source module ships an event and an idempotency key; Accounting owns the single posting code path that
consumes it.

# The Shared Foundation

Four things are prerequisites for *everything* and belong to no single business domain. They are built
first and are never optional.

| Foundation | What it provides | Why everything needs it | Reference |
|---|---|---|---|
| Auth / Identity | Users, roles, permissions, `company_users`; Sanctum sessions + RS256 JWT; the RBAC grammar and default-deny gate | No request runs un-authenticated or un-authorized; every Policy and FormRequest calls into it | [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md) |
| Tenancy | `company_id` discriminator, `ResolveTenantCompany` middleware, `CompanyScope`, PostgreSQL RLS | Every tenant table's isolation depends on it; it is the boundary of last resort (ADR-002) | [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) |
| Company / Org | Companies, branches, departments, base currency, fiscal calendar | Tenant context and dimensions (`branch_id`, `department_id`) every table carries | [../foundation/COMPANY_STRUCTURE.md](../foundation/COMPANY_STRUCTURE.md) |
| Service spine | The Action/Service/DTO/Repository pattern, transactions, idempotency, the domain-event bus | The uniform shape every module instantiates; the event bus every cross-module effect travels | [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) |

Below these four sits the **Accounting core** — not a cross-cutting service, but the one *business*
module every other posting module depends on at build time, because it owns the only path that writes a
`journal_line` (see [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md)).

Orthogonal to all of the above are four **cross-cutting services** that many modules *use* but that
depend only on the foundation, not on any business module: Audit, Notifications, Files, and Search.
They are built alongside the early phases and consumed everywhere.

# Dependency Table

The table lists each module/service, its hard build-time dependencies, the modules it depends on at
runtime (by event), and the cross-cutting services it uses. "Posts via" names the Accounting Service as
the single writer of the ledger effect the module triggers.

| Module / Service | Hard build dependencies | Runtime (event) dependencies | Cross-cutting used | Posts via |
|---|---|---|---|---|
| Auth / Identity | Service spine | — | Audit | — |
| Tenancy | Auth | — | Audit | — |
| Company / Org | Auth, Tenancy | — | Audit, Files | — |
| Audit (X-cutting) | Service spine, Tenancy | observes all state transitions | — | — |
| Notifications (X-cutting) | Auth, Tenancy | consumes any notifying event | Files | — |
| Files (X-cutting) | Auth, Tenancy | — | — | — |
| Search (X-cutting) | Tenancy | reindex on module events | — | — |
| **Accounting (core)** | Auth, Tenancy, Company, Service spine | consumes posting events from all source modules | Audit, Files, Notifications | itself (single posting path) |
| Banking | Accounting | emits `payment.received`; consumes `invoice.posted`, `bill.approved` | Audit, Notifications, Files | Accounting (`banking.payment.received` → Dr bank / Cr AR) |
| Reporting | Accounting | reads ledger query-only; reindexes report runs | Files, Search | reads only (never writes ledger) |
| Sales | Accounting, Company | emits `invoice.posted/paid`; consumes `payment.received` | Workflow, Audit, Notifications, Files | Accounting (`sales.invoice.posted`) |
| Purchasing | Accounting, Company | emits `bill.approved/paid`; consumes `goods.received` | Workflow, Audit, Notifications, Files | Accounting (`purchasing.bill.approved`) |
| Tax | Accounting | serves `tax/calculate` to Sales/Purchasing/Payroll; emits `tax.return.submitted` | Audit, Files | Accounting (`tax.return.submitted`) |
| Workflow (X-cutting) | Auth, Tenancy, Service spine | observes any module's sensitive Action; drives approvals | Notifications, Audit | invokes the owning module's Action on approval |
| Inventory | Accounting, Purchasing, Sales | emits `goods.received`, `stock.low`; consumes `invoice.posted`, `po.received` | Workflow, Audit, Notifications | Accounting (`inventory.goods.received` → GRNI/COGS) |
| Payroll | Accounting, Company | emits `payroll.released`; consumes approval decisions | Workflow, Tax, Audit, Notifications | Accounting (`payroll.payroll.released`) |
| Automation (X-cutting) | Workflow, all target modules' Actions | consumes any triggering event; invokes module Actions | Notifications, Audit | invokes the owning module's Action |
| AI layer | Every module it exposes as a tool; Workflow (for proposal commit); AI engine (FastAPI) | reads via internal API; proposes via `/api/v1` | Audit, Notifications, Search | never posts directly — proposes an `ai_draft`; a human posts via Accounting |
| Mobile app | Every module's `/api/v1` endpoints | — (pure client) | — | — |
| Partner / Public API | Every module's `/api/v1`; Auth (API keys) | — | Audit | — |
| Integrations / Marketplace | Banking, Sales, Purchasing, Tax; Partner API | consumes/produces module events | Files, Notifications, Audit | via the owning module's Action |

# The Dependency DAG, Described

Read the graph in layers, top (no dependencies) to bottom (depends on everything above it). Edges point
from a dependency to its dependent (A → B means "B is built on A").

**Layer 0 — Platform spine.** `Service spine` and `Auth` have no business dependency. `Tenancy` builds
on `Auth`. `Company/Org` builds on `Auth` + `Tenancy`. Nothing tenant-scoped precedes this layer,
because without RLS and the `company_id` discriminator there is no safe place to put a row (ADR-002).

**Layer 1 — Cross-cutting services.** `Audit`, `Notifications`, `Files`, and `Search` build on Layer 0
only. They are horizontal: every business module consumes them, none of them consumes a business module.
`Audit` is special — it observes *all* state transitions and is written inside the same transaction as
the change it records, so it must exist before any module whose history matters (i.e. all of them).

**Layer 2 — The ledger.** `Accounting` builds on the full spine. It is the pivot of the whole graph:
every arrow from a posting module eventually reaches it. Critically, the edges *into* Accounting are
runtime edges (events), while the edge that matters for build order is that Accounting must exist first
so those events have a consumer. Accounting depends on nothing downstream — it neither imports nor calls
any source module; it only reacts to their events through its own listeners.

**Layer 3 — First posting + reading modules.** `Banking` builds on `Accounting` and is the first module
to prove the event-driven posting pattern end to end. `Reporting` builds on `Accounting` too, but as a
*read-only* consumer — it queries the ledger and never writes it, so it can be built in parallel with
Banking without a mutual dependency.

**Layer 4 — Commercial modules and the approval engine.** `Sales` and `Purchasing` build on `Accounting`
(to post AR/AP by event) and on `Company` (for customers/vendors/branches). `Tax` builds on `Accounting`
and is a *service* to Sales/Purchasing/Payroll: they call `tax/calculate`, so Tax must exist before their
tax-bearing documents are correct, but Tax itself only posts liability by event. `Workflow` is
cross-cutting and builds on the spine; it is pulled in here because Sales/Purchasing are the first
modules whose sensitive documents (invoice void, bill approval) need maker-checker sign-off.

**Layer 5 — Operational modules and automation.** `Inventory` builds on `Accounting` *and* on Sales +
Purchasing, because stock is driven by their events (`invoice.posted` reduces stock; `po.received`
increases it) and it posts GRNI/COGS. `Payroll` builds on `Accounting`, `Company`, and `Tax` (for
payroll tax/WPS), and on `Workflow` (release is a sensitive, approved action). `Automation` builds on
`Workflow` and on *every module whose Action it may invoke*, so it is necessarily late — it can only
orchestrate Actions that already exist.

**Layer 6 — AI, mobile, partners, scale.** The `AI layer` depends on every domain module it exposes as a
tool and on `Workflow` for the proposal-commit gate; it is the last of the core layers because an agent
can only reason over, and propose against, capabilities that already exist — and it never posts, so it
also depends on Accounting only as a *proposer* (ADR-007). `Mobile`, `Partner API`, `Integrations`, and
`Marketplace` are clients or edges over the finished `/api/v1` surface and therefore come last.

The graph has **no cycles**. The apparent Sales↔Accounting "loop" is not a cycle: the build edge is
Accounting → Sales (Accounting first), and the runtime edge is Sales → Accounting (Sales emits, Accounting
reacts). Because the runtime edge is an asynchronous event, not a function call, it creates no build-time
cycle. This acyclicity is exactly what ADR-001's "module boundaries enforced in code" buys: the seams are
one-directional at build time even where causation flows both ways at runtime.

```
Layer 0   Service spine ── Auth ── Tenancy ── Company/Org
                                   │
Layer 1        Audit · Notifications · Files · Search   (cross-cutting, on Layer 0 only)
                                   │
Layer 2                       Accounting  (the single posting path)
                              /          \
Layer 3                 Banking        Reporting (read-only)
                              \          /
Layer 4        Sales ─ Purchasing ─ Tax ─ Workflow (approval engine)
                              │
Layer 5        Inventory ─ Payroll ─ Automation
                              │
Layer 6            AI layer ─ Mobile ─ Partner API ─ Integrations ─ Marketplace
```

# Recommended Build Order

The order below is the topological sort of the DAG, collapsed onto the roadmap phases. Within a step,
items can proceed in parallel because they share no mutual dependency.

| Step | Build (parallel within a step) | Rationale | Roadmap phase |
|---|---|---|---|
| 1 | Service spine, Auth/Identity | Nothing runs without authenticated, structured use cases | Phase 0 |
| 2 | Tenancy (RLS), Company/Org | The safe place to put a tenant row | Phase 0 |
| 3 | Audit | Must record history from the first posting | Phase 0 |
| 4 | Accounting core | The single ledger-writing path all posting modules need | Phase 0 |
| 5 | Notifications, Files, Search (PG tier) | Cross-cutting runtime a real product needs | Phase 1 |
| 6 | Banking, Reporting | First event-posting module; first read-only ledger consumer (parallel) | Phase 1 |
| 7 | First AI agents (proposal-only) | Reason over the ledger + banking that now exist | Phase 1 |
| 8 | Workflow engine | Gate the sensitive documents the commercial modules introduce | Phase 2 |
| 9 | Sales, Purchasing, Tax | Order-to-cash and procure-to-pay with GCC tax (Tax before their tax-bearing docs are correct) | Phase 2 |
| 10 | Inventory | Driven by Sales/Purchasing events; posts GRNI/COGS | Phase 3 |
| 11 | Payroll | Needs Company, Tax, Workflow; release is approved | Phase 3 |
| 12 | Automation engine, deeper AI agents | Can only orchestrate/reason over Actions that now exist | Phase 3 |
| 13 | Mobile, Partner API, Integrations, Marketplace, Multi-region | Clients/edges over the finished contract; scale posture | Phase 4 |

Two ordering subtleties are worth stating explicitly:

- **Tax before the modules that price with it.** Sales, Purchasing, and Payroll each call
  `POST /api/v1/tax/calculate` ([../accounting/TAX.md](../accounting/TAX.md)) at the taxable event, so
  Tax's calculation contract must be built no later than the first tax-bearing document in those modules.
  It is placed in the same step because the calculation contract can be stubbed and hardened alongside
  them, but the contract must be fixed first.
- **Workflow before Sales/Purchasing's sensitive actions, Automation after everything it drives.** The
  approval engine is a prerequisite of the first maker-checker document; the automation engine is a
  *consequent* of the Actions it invokes, so despite both being cross-cutting, they sit on opposite ends
  of the order.

# Shared-Foundation Call-Outs

Some capabilities are depended on so widely that treating them as "just another module" causes
build-order mistakes. They are called out here as shared foundations with a single owner.

- **The single posting path is a shared foundation, not a Sales/Purchasing detail.** No module may grow
  its own ledger write. `PostingService` in the Accounting module is the only writer of `journal_lines`
  and `ledger_entries`; every source module reaches it by emitting an event with a source idempotency key
  `{source_module}:{source_type}:{source_id}:{event}` (see
  [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md)). Any pull request that inserts a
  ledger line from outside Accounting is a boundary violation, not a shortcut.
- **The tenant boundary is a shared foundation of every table.** Every new tenant table must `ENABLE` and
  `FORCE` RLS and lead its indexes with `company_id`; every new job must re-establish tenant context via
  `SET LOCAL app.current_company_id` in `handle()`. This is not per-module work that can be skipped — it
  is the platform's defense-in-depth of last resort (ADR-002, [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)).
- **The RBAC gate is a shared foundation of every endpoint.** Every module's FormRequest authorizes
  against `<area>.<entity>.<action>`, default-deny, and every Policy answers only "may this actor act in
  the active company?" ([../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)). Modules
  do not invent their own authorization.
- **The domain-event envelope is a shared foundation of every cross-module effect.** The canonical
  envelope `{event_id, event_name, company_id, aggregate_type, aggregate_id, occurred_at, causation_id,
  correlation_id, payload}` ([../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md)) is
  how modules stay decoupled; the AI-relevant subset is fanned out to the FastAPI engine on the
  `events-ai` queue.
- **The workflow engine is a shared foundation for sensitive actions across modules.** It is built once
  and consumed by Sales, Purchasing, Payroll, Accounting (year-close, reversals), and every `ai_draft`
  above threshold; it invokes the owning module's Action rather than writing that module's tables
  ([../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md)).
- **The AI engine is a peer, not a layer, and depends on the domain — never the reverse.** The FastAPI
  engine reads via the internal API and proposes via `/api/v1`; it holds no authoritative state and never
  writes the database (ADR-007, [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md)). A domain module
  therefore never build-depends on the AI engine; the AI layer build-depends on the domain modules it
  exposes as tools.

# Related Documents

- [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md) — the phased schedule this dependency order underpins.
- [TECH_DECISIONS.md](./TECH_DECISIONS.md) — the technical decisions the module boundaries assume.
- [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md), [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md), [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)
- [../backend/BANKING_SERVICE.md](../backend/BANKING_SERVICE.md), [../backend/INVENTORY_SERVICE.md](../backend/INVENTORY_SERVICE.md), [../backend/PAYROLL_SERVICE.md](../backend/PAYROLL_SERVICE.md), [../backend/TAX_SERVICE.md](../backend/TAX_SERVICE.md), [../backend/REPORTING_SERVICE.md](../backend/REPORTING_SERVICE.md), [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md)
- [../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md), [../backend/AUTOMATION_ENGINE.md](../backend/AUTOMATION_ENGINE.md), [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md), [../backend/AUDIT_SERVICE.md](../backend/AUDIT_SERVICE.md), [../backend/NOTIFICATION_SERVICE.md](../backend/NOTIFICATION_SERVICE.md), [../backend/SEARCH_SERVICE.md](../backend/SEARCH_SERVICE.md), [../backend/FILE_SERVICE.md](../backend/FILE_SERVICE.md)
- [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md), [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md)

# End of Document
