# 04 — Architecture — QAYD PRD
Version 1.0
Status: Source of Truth
Module: PRD
Submodule: ARCHITECTURE
---

# Purpose And Altitude

This chapter states the *shape* of QAYD — the surfaces a user touches, the systems behind them,
how the systems are divided, and how a fact travels from one to the next. It is a map, not a
manual. It does not re-specify the database schema, re-declare a service's classes, or restate the
API envelope field by field; those decisions are owned, in full and authoritatively, by
[../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md),
[../../foundation/MODULE_ARCHITECTURE.md](../../foundation/MODULE_ARCHITECTURE.md),
[../../backend/](../../backend/), [../../database/](../../database/), and
[../../api/](../../api/). What this chapter does — and the only thing it does — is give a founder,
an investor, or an engineering lead the whole-system picture at PRD altitude: **High-Level
Architecture, the Service Map, Module Relationships, and Event Flow**, each anchored to the spec
that owns the detail.

One sentence governs everything below and is repeated in every architecture document QAYD ships:
the Laravel backend is the single source of truth and the only component with database write
credentials; the FastAPI AI engine never writes the database directly. Every relationship on this
map is arranged to make that structural, not procedural.

# High-Level Architecture

QAYD is composed of independent systems that cooperate through secure, versioned APIs, each with
one responsibility and none responsible for everything
([../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md)). At the coarsest
resolution there are **four surfaces** in front and **one kernel plus its datastores** behind.

```
        Web (Next.js 15)        Mobile (Flutter)
                │                      │
                └───────────┬──────────┘
                            │  HTTPS · Bearer (Sanctum/JWT) · X-Company-Id
                            ▼
                 ┌─────────────────────────┐        ┌───────────────────────────┐
                 │  Laravel 12 API kernel   │◀──────▶│  FastAPI AI engine         │
                 │  THE ONLY WRITER OF THE  │  /api  │  agents · MCP tools · OCR  │
                 │  DATABASE                │  /v1   │  vector retrieval          │
                 │  FormRequest→RBAC→       │        │  NO DB CREDENTIALS         │
                 │  Service→Repository      │        └───────────────────────────┘
                 └────────────┬────────────┘
                              │ Eloquent + RLS session variable
                              ▼
                       PostgreSQL (system of record · RLS · pgvector)
                 ┌────────────┼────────────┬───────────────┐
                 ▼            ▼             ▼               ▼
              Redis        Supabase Storage   Reverb        Search
           (cache/queue/   (documents)    (WebSockets)  (Postgres→
            AI working mem)                              Meili→ES)
```

The four surfaces are deliberately thin and deliberately symmetric at the boundary — a human on the
web, a human on mobile, an integration partner, and the AI engine all reach the system through the
*same* `/api/v1` front door, the same authentication, and the same permission checks. Nothing has a
privileged back channel to the data.

| Surface | Technology | Owns | Explicitly does not own |
|---|---|---|---|
| **Web app** | Next.js 15, React 19, TypeScript (strict), Tailwind, shadcn/ui | Dashboards, the Financial Copilot chat surface, approval inboxes, accounting/reporting/settings screens, bilingual RTL rendering | Any business or financial logic; any direct DB access |
| **Mobile app** | Flutter | Dashboard, notifications, approvals, reports, the AI assistant on the phone | Business logic; it is a thin client of the same API |
| **AI engine** | FastAPI + Python (LangGraph-style orchestration) | The fifteen-agent workforce, OCR pipeline, vector retrieval, reasoning, model calls | Database credentials to any tenant schema — it reads and writes *only* through Laravel |
| **API kernel** | Laravel 12, PHP 8.4+ | All business/financial rules, validation, RBAC, the double-entry core, queues, events, the exclusive database write path | Rendering; model inference |

The kernel-and-datastore layer is where correctness lives: PostgreSQL is the ACID system of record
with Row-Level Security on every tenant table and `pgvector` for AI memory; Redis serves sessions,
queues, rate-limit counters, and the AI engine's short-term working memory; Supabase Storage stores the
source documents every AI citation ultimately resolves to; and Laravel Reverb pushes state to
connected clients over WebSockets. The definitive component responsibilities are in
[../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md) and
[../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md); the stack is fixed and not
substitutable without an ADR.

# Service Map

The backend is a **modular monolith**: one Laravel deployable, internally partitioned into
independent modules that each own a complete business capability (business logic, tables, endpoints,
permissions, events, AI tools, tests) and never reach into another module's internals
([../../foundation/MODULE_ARCHITECTURE.md](../../foundation/MODULE_ARCHITECTURE.md),
[../../backend/BACKEND_ARCHITECTURE.md](../../backend/BACKEND_ARCHITECTURE.md)). The **AI engine** is
the one component that is *not* inside that monolith — a separately deployed, separately scalable
FastAPI service, kept outside the database-credential boundary on purpose. This is the whole
architectural story in one line: one modular monolith that owns the money, one AI engine that
reasons about it, and a hard wall between them that only the `/api/v1` contract crosses.

Each backend module is specified in its own service document; this map names each and routes to it.

| Backend module / service | Owns (capability, not schema) | Detailed spec |
|---|---|---|
| **Accounting Service** | The double-entry core: chart of accounts, journal entries, posting engine, general ledger, trial balance, fiscal periods, financial statements | [../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md) |
| **Banking Service** | Bank accounts, transaction import, reconciliation, transfers/payments | [../../backend/BANKING_SERVICE.md](../../backend/BANKING_SERVICE.md) |
| **Inventory Service** | Products, warehouses, stock movements, valuation | [../../backend/INVENTORY_SERVICE.md](../../backend/INVENTORY_SERVICE.md) |
| **Payroll Service** | Employees, payroll runs, payslips, PIFSS/WPS files | [../../backend/PAYROLL_SERVICE.md](../../backend/PAYROLL_SERVICE.md) |
| **Tax Service** | Tax codes/rates, input/output VAT tracking, return preparation | [../../backend/TAX_SERVICE.md](../../backend/TAX_SERVICE.md) |
| **Reporting Service** | Statement assembly, scheduled reports, exports | [../../backend/REPORTING_SERVICE.md](../../backend/REPORTING_SERVICE.md) |
| **AI Service** | The Laravel-side boundary to FastAPI: the proposal gateway, agent catalogue, autonomy resolution, cost governance | [../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md) |
| **Auth Service** | Authentication, sessions, company switching, service tokens | [../../backend/AUTH_SERVICE.md](../../backend/AUTH_SERVICE.md) |
| **Workflow Engine** | The human-in-the-loop approval chains every sensitive action routes through | [../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md) |
| **Automation Engine** | Trigger → condition → action rules | [../../backend/AUTOMATION_ENGINE.md](../../backend/AUTOMATION_ENGINE.md) |
| **Audit Service** | The immutable, tamper-evident record of what happened | [../../backend/AUDIT_SERVICE.md](../../backend/AUDIT_SERVICE.md) |
| **File Service** | Object storage, attachments, signed URLs to Supabase Storage | [../../backend/FILE_SERVICE.md](../../backend/FILE_SERVICE.md) |
| **Notification Service** | In-app, email, and later SMS/WhatsApp fan-out | [../../backend/NOTIFICATION_SERVICE.md](../../backend/NOTIFICATION_SERVICE.md) |
| **Search Service** | Search, staged from Postgres to Meilisearch to Elasticsearch | [../../backend/SEARCH_SERVICE.md](../../backend/SEARCH_SERVICE.md) |

Two services are cross-cutting rather than domain-owning and are load-bearing for safety: the
**Workflow Engine** is the mechanism that makes "human approves the consequential" real, and the
**Audit Service** is the mechanism that makes every change traceable. Every domain module leans on
both. The abstract Action → Service → Repository → Model pattern all of these share, and the shared
`PostingService` invariant, are defined in
[../../backend/SERVICE_ARCHITECTURE.md](../../backend/SERVICE_ARCHITECTURE.md).

# Module Relationships

The organizing rule is that **modules are independent and communicate through events, never through
direct database writes into one another's tables**
([../../foundation/MODULE_ARCHITECTURE.md](../../foundation/MODULE_ARCHITECTURE.md)). A module emits
a domain event describing what happened in its own world; other modules subscribe and react in
theirs. This keeps any single module replaceable without disturbing the rest and guarantees there is
exactly one code path per effect.

The relationship that matters most is the direction of coupling around the ledger. **Accounting is
the terminal consumer.** Sales, Purchasing, Banking, Inventory, Payroll, and Tax never write a
journal line themselves; they emit events, and the Accounting Service reacts by posting the ledger
effect through the *same* Actions a human would use. The dependency arrows all point *into*
accounting, never out of it:

```
  Sales ──▶  invoice.posted ─┐
  Purchasing ──▶ bill.approved ─┤
  Banking ──▶ payment.received ─┼──▶  Accounting Service ──▶ ledger_entries ──▶ Reports
  Inventory ──▶ goods.received ─┤        (single posting path)          │
  Payroll ──▶ payroll.released ─┤                                       └──▶ AI engine reads
  Tax ──▶ return.submitted ─────┘                                            (never writes)
```

This one-way coupling is what lets the source modules evolve — a new POS integration in Sales, a new
bank feed in Banking — without ever touching the posting code, and it is why there is a single,
audited place where money enters the books. The full inter-module dependency graph, including build
ordering, lives in
[../../execution/MODULE_DEPENDENCIES.md](../../execution/MODULE_DEPENDENCIES.md); the ledger side of
the contract is in [../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md).

The AI engine relates to every module the same way a disciplined external caller does: it *reads*
through scoped tools and *proposes* writes through the AI Service's proposal gateway, which lands the
proposal in the owning module's own Service, validated and permission-checked exactly like a human
click. The AI is a client of the modules, never a peer with a shortcut — the detail of that
relationship is Chapter [05_AI_PLATFORM.md](./05_AI_PLATFORM.md).

## The `/api/v1` Contract

Every surface reaches every module through one versioned REST contract over HTTPS
([../../foundation/API_ARCHITECTURE.md](../../foundation/API_ARCHITECTURE.md),
[../../api/REST_STANDARDS.md](../../api/REST_STANDARDS.md)). Four properties of that contract are
architecturally load-bearing and are the reason the surfaces can stay thin:

- **One response envelope.** Every endpoint returns `{ success, data, message, errors, meta,
  request_id, timestamp }`, so every client parses success and error identically.
- **Tenant on every request.** Each call carries `X-Company-Id`; the kernel verifies the caller
  belongs to that company and sets the PostgreSQL session variable that RLS reads.
- **Permission on every action.** Every endpoint enforces an `<area>.<action>` RBAC key, deny by
  default, identically whether the caller is a human or the AI engine
  ([../../foundation/PERMISSION_SYSTEM.md](../../foundation/PERMISSION_SYSTEM.md)).
- **Idempotency on money-moving writes.** Posts and disbursements carry an `Idempotency-Key` so a
  network retry never double-posts ([../../api/API_IDEMPOTENCY.md](../../api/API_IDEMPOTENCY.md)).

Versioning, pagination, filtering/sorting, rate limiting, error codes, and webhooks are each their
own spec under [../../api/](../../api/); this chapter only asserts that they exist and are uniform.

## Realtime

State that changes on the server reaches connected clients over Laravel Reverb WebSocket channels
rather than by polling: a posted journal, a proposed AI decision, an approval request, a health-score
change, or a forecast alert is broadcast on a private, company-scoped channel and the web/mobile
surface updates live. Realtime is a *push of already-committed facts* — it never carries a write path.
The channel catalogue is in
[../../backend/BACKEND_ARCHITECTURE.md](../../backend/BACKEND_ARCHITECTURE.md); the AI-specific
channels in [../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md).

## Multi-Tenancy Boundary

Every company is completely isolated: Company A can never reach Company B's data, and even AI agents
only ever receive the active company's data
([../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md)). This is enforced
in depth — an HTTP middleware ring that validates `X-Company-Id`, a global Eloquent scope, an
authorization policy layer, and, as the line that cannot be forgotten, PostgreSQL Row-Level Security
that fails to *empty* (zero rows) rather than to another tenant's rows when the session scope is
unset. The AI engine sits outside all of it by construction because it holds no tenant database
credentials at all. This chapter states the boundary exists and is absolute; the mechanism, the RLS
policies, and the isolation test suite that gates every release are owned by
[../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md),
[../../database/ROW_LEVEL_SECURITY.md](../../database/ROW_LEVEL_SECURITY.md), and
[../../security/TENANT_ISOLATION.md](../../security/TENANT_ISOLATION.md).

# Event Flow

Two flows describe how work moves through QAYD: the **domain event flow** (how a business fact
ripples across modules) and the **AI request lifecycle** (how an AI-initiated action reaches a
visible effect). Both share the same spine — everything real happens inside the Laravel kernel, in a
single transaction, and only then fans out.

**Domain event flow — a source fact becomes ledger, reports, and intelligence.** When a business
event occurs in a source module, it is committed in that module's own transaction and, only after
commit, published as a domain event that downstream modules consume:

```
  Invoice paid (Sales)
        │  commit, then publish
        ▼
  sales.invoice.posted  ──▶  Accounting posts the journal (single posting path, idempotency-guarded)
                                     │  accounting.journal.posted
                        ┌────────────┼───────────────┬──────────────────┐
                        ▼            ▼                ▼                  ▼
                  Banking sub-    Reports cache    Reverb push to     AI engine notified
                  ledger caches   invalidated      the dashboard      (an agent may react)
```

Cross-module listeners are queued and bind after-commit, so a rolled-back transaction never triggers
a downstream effect, and each carries a source idempotency key so a queue redelivery is a no-op — a
posted entry never double-posts. The canonical consumed/emitted event tables are in
[../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md) and
[../../backend/BACKEND_ARCHITECTURE.md](../../backend/BACKEND_ARCHITECTURE.md).

**AI request lifecycle — a trigger becomes a governed write.** An AI-initiated action follows the
same path from trigger to visible effect, and no step lets the AI engine skip the kernel:

```
 1  Trigger: document uploaded / bank feed synced / schedule fires / user asks the Copilot
 2  AI engine (FastAPI): orchestrator routes to agent(s); agent retrieves context, reasons,
       drafts a proposed_action, computes confidence; the Decision Engine computes autonomy
 3  MCP tool call: typed, one-to-one mapped to a Laravel endpoint + permission key
 4  Laravel API: FormRequest validation → RBAC check → IF requires_approval, file an approval
       request and STOP; IF auto (or a human just accepted), proceed
 5  Service → Repository → Model: business logic in one all-or-nothing DB transaction
 6  PostgreSQL: rows written; audit row written in the same transaction
 7  Domain event emitted  →  8  Reverb push  →  9  Web/mobile updates live, showing
       confidence + reasoning + sources
```

The critical property is that step 4 runs identically for a KWD 4.500 expense categorization and a
KWD 40,000 bank transfer — `auto` skips the *human approval wait*, never the *validation and
permission check*. The full end-to-end lifecycle, the security-boundary diagram, and the append-only
action log that makes every hop auditable are specified in
[../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md); the governed-write mechanics of the proposal
gateway are in [../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md). The product-level meaning
of this flow — the agents, the autonomy contract, and the human gate — is Chapter
[05_AI_PLATFORM.md](./05_AI_PLATFORM.md).

# Navigation And Information Architecture

At the product surface, the same architecture presents as a stable information hierarchy: a
company-scoped shell (top bar with company switcher, sidebar of modules) wrapping an **AI Command
Center** home, then the accounting core (chart of accounts, journal entries, general ledger, trial
balance, statements), the operational modules (sales, purchasing, banking, inventory, payroll, tax),
reporting, an approval center, an audit log, and settings — each screen a thin client of the module
and permission set behind it, rendered bilingually in Arabic and English with full RTL. The screen,
flow, and layout specifications are owned by [../../frontend/](../../frontend/); this chapter places
them on the architecture rather than designing them.

## Related Documents

- [../../backend/BACKEND_ARCHITECTURE.md](../../backend/BACKEND_ARCHITECTURE.md), [../../backend/SERVICE_ARCHITECTURE.md](../../backend/SERVICE_ARCHITECTURE.md), [../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md), [../../backend/AI_SERVICE.md](../../backend/AI_SERVICE.md), [../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md), [../../backend/AUTOMATION_ENGINE.md](../../backend/AUTOMATION_ENGINE.md) — the modular-monolith kernel and its services.
- [../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md), [../../database/ROW_LEVEL_SECURITY.md](../../database/ROW_LEVEL_SECURITY.md), [../../database/DATABASE_ARCHITECTURE.md](../../database/DATABASE_ARCHITECTURE.md), [../../database/DATABASE_EVENTS.md](../../database/DATABASE_EVENTS.md) — the tenant boundary and datastore.
- [../../api/REST_STANDARDS.md](../../api/REST_STANDARDS.md), [../../api/API_VERSIONING.md](../../api/API_VERSIONING.md), [../../api/API_IDEMPOTENCY.md](../../api/API_IDEMPOTENCY.md), [../../api/API_WEBHOOKS.md](../../api/API_WEBHOOKS.md), [../../api/INTERNAL_API.md](../../api/INTERNAL_API.md) — the `/api/v1` contract.
- [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) — the request lifecycle, security boundaries, and the AI thesis expanded in Chapter 05.
- [../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md), [../../foundation/MODULE_ARCHITECTURE.md](../../foundation/MODULE_ARCHITECTURE.md), [../../foundation/API_ARCHITECTURE.md](../../foundation/API_ARCHITECTURE.md), [../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md) — the owning foundation set.

# End of Document
