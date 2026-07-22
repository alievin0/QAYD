# 08 — Backend — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: BACKEND
---

# Purpose

This chapter states what the QAYD backend *is as a product-bearing system* — the guarantees it must enforce,
the shape that enforces them, and the boundaries it holds — at PRD altitude. It does not re-specify a
controller, an endpoint, a job, or an event; those live authoritatively under
[`../../backend/`](../../backend/BACKEND_ARCHITECTURE.md) and [`../../api/`](../../api/API_ARCHITECTURE.md),
and this chapter routes to them. It also deliberately does not describe the database — the schema,
multi-tenancy mechanics, RLS, and integrity constraints are the subject of PRD chapter 09 and
[`../../database/`](../../database/DATABASE_ARCHITECTURE.md). Here the backend is described as the thing that
turns a request into a correct, isolated, authorized, audited change of financial state.

The backend is the Laravel 12 application that is the **single source of truth** for every piece of business
and financial state in QAYD. The web client, the mobile client, partner integrations, and the FastAPI AI
engine are all callers of one `/api/v1` contract; none of them touches the database directly. This is the
architectural decision the whole platform rests on: one contract, one set of business invariants, held
regardless of who initiated the request.

# The Backend's Job

The backend exists to enforce three properties no client may be trusted to enforce on its own (full statement
in [`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md)):

- **Correctness.** Double-entry always balances, money never double-posts, and posted records are immutable —
  a correction is a new reversing entry, never an edit. These are enforced structurally, in the domain and
  database layers, not by convention.
- **Isolation.** Company A can never observe or mutate company B's data. Tenant scope is ambient and enforced,
  never passed by hand — resolved once per request and applied automatically at the ORM and database layers.
- **Authority.** Every action is authenticated, authorized against company-scoped RBAC, validated, and
  audited. The pipeline is default-deny: a request that fails any gate never reaches business logic.

The authoritative stack is fixed and not open to substitution here (per
[`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md)): Laravel 12 on PHP 8.4+,
PostgreSQL as the primary database, Redis for cache / session / queue / locks, Supabase Storage for object
storage, Laravel Reverb for realtime, and Laravel Sanctum for authentication. The AI engine is a separate
FastAPI service the backend calls over HTTP and queues — never an in-process library, never a component with
database access.

# The Shape: A Modular Monolith

QAYD's backend is a modular monolith, not a distributed system and not a big ball of mud. It deploys as one
codebase in several independently-scaled roles, but internally it is partitioned into modules that own their
tables and expose their capability only through their API endpoints and their domain events. This is the shape
that lets a small team ship a large surface without the operational tax of microservices, while keeping the
option to extract a module later because its boundary is already clean.

Two structural rules define the shape (owned by
[`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md)):

- **Layered within each module.** Every module follows Presentation → Application → Domain → Infrastructure.
  Controllers are thin — they authorize, delegate to an Action or Service, wrap the result in a Resource, and
  return. Business logic lives in the Application layer; invariants live in the Domain layer.
- **Modules are independent and event-connected.** A module never reaches into another module's tables or
  classes. Cross-module effects travel only through domain events, queued listeners, and the internal API.

The canonical module map is the unit of ownership — `accounting`, `sales`, `purchasing`, `banking`,
`inventory`, `payroll`, `tax`, `reports`, `ai`, `identity`, `company`, plus the cross-cutting `workflow`
(approvals) and `automation` engines. The concrete example the whole model turns on: posting an invoice.
Sales transitions the invoice and emits `InvoicePosted`; Accounting reacts by calling its own action to write
the journal; Inventory reacts by reducing stock; Reports invalidates its cache; a broadcast refreshes clients.
Sales never inserts a journal line — it announces a fact and the ledger's owner reacts. That is what keeps
modules replaceable and the ledger's authorship singular.

# Services, Actions, And The Double-Entry Invariant

The service-layer pattern is uniform across every module so any engineer or AI agent reading one module can
predict the next (owned by [`../../backend/SERVICE_ARCHITECTURE.md`](../../backend/SERVICE_ARCHITECTURE.md)):

- **Actions** are single-purpose, invokable use cases — one public entrypoint, input as an immutable DTO,
  output as a domain object, writes wrapped in a transaction. The same Action is called by a controller, by a
  queued listener, and by the AI proposal-commit path: one implementation, three callers, one set of rules.
- **Services** group cohesive operations that share internals (reconciliation, tax calculation, the posting
  routine). The rule of thumb is reach for an Action by default; promote to a Service only when operations
  genuinely share state.

Because the AI proposal-commit path constructs the *same* DTO from a validated proposed payload, an
AI-originated invoice and a human-originated invoice run through byte-for-byte the same Action and the same
invariants — the AI can never take a shortcut around a rule a human is held to. The most important invariant,
double-entry balance, is enforced in the domain layer before commit (unbalanced debits and credits throw a
typed exception rendered as `422 balance_mismatch`, computed on fixed-scale money values, never floats) and
is backstopped again in the database (chapter 09). Ledger lines are append-only; posted documents are
immutable; the idempotency record commits in the same transaction as the business write so a retried
money-moving request can never create a second document.

# The /api/v1 Contract And Envelope

One versioned, permissioned, enveloped REST contract serves every surface — there is no separate "mobile API"
or "web API", because divergence would fork the business rules. The contract is owned by
[`../../api/API_ARCHITECTURE.md`](../../api/API_ARCHITECTURE.md) and
[`../../api/REST_STANDARDS.md`](../../api/REST_STANDARDS.md); the product-level facts worth fixing here:

| Contract property | Decision |
|---|---|
| Envelope | Every response is `{ success, data, message, errors, meta, request_id, timestamp }`; every client unwraps one shape and handles one typed error class |
| Request pipeline | Fixed order — authenticate (Sanctum) → resolve tenant company → rate-limit → authorize (policy) → validate (FormRequest) → execute → resource → envelope. The four gates (auth, tenant, authorize, validate) run before any Service |
| External identifiers | Public identifiers on the wire are opaque UUID-backed values, never raw internal sequential IDs (see chapter 09; and the open `X-Company-Id` form question, ARC-3, in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)) |
| Surfaces | Web, mobile, partner, and AI-on-behalf-of-user differ only in auth-token class, client-platform tag, and rate-limit tier — never in what an endpoint does; the permission the caller holds, not the surface, decides what they can do |
| Errors | A single handler maps typed exceptions to status codes; cross-tenant access resolves to `404`, never `403`, so existence never leaks across companies |
| Idempotency | Every money-moving `POST` and sensitive `PATCH` requires an `Idempotency-Key`; replays return the stored envelope, conflicts return `409` |
| Contract fidelity | The OpenAPI spec is the source of truth; contract tests keep the SDKs from drifting |

# Async, Realtime, Caching, And Storage

Heavy or fan-out work never runs in the request thread — OCR, report generation, bulk import, AI analysis,
webhook delivery, and notifications run on named Redis queues (`realtime`, `default`, `ai`, `reports`,
`integrations`, `maintenance`) so latency-sensitive work is never starved by bulk work, and a client gets a
prompt `202 Accepted` for long operations. Every job is tenant-aware (it re-establishes company context before
touching a model) and idempotent (a natural key plus check-then-act inside a transaction), so a retried
money-affecting job never double-posts. The scheduler runs on exactly one node and dispatches all recurring
work — period-close reminders, tax-deadline checks, recurring invoices, automation time-triggers, approval-SLA
sweeps — onto those same queues.

| Concern | Product-level decision | Owned by |
|---|---|---|
| Queues & jobs | Async-by-default for heavy work; named queues scaled independently; idempotent, tenant-rebinding jobs | [`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md), [`../../backend/SERVICE_ARCHITECTURE.md`](../../backend/SERVICE_ARCHITECTURE.md) |
| Realtime | One-directional, company-scoped: backend broadcasts compact projections over private Reverb channels (`private-company.{id}` and its `.ai` / `.approvals` sub-channels); channel authorization re-runs the same RBAC as HTTP — the socket handshake is not a bypass | [`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md) |
| Caching | Redis, always company-keyed so no cross-tenant bleed; reference data cached read-through with event-driven invalidation; balances and PII are `no-store`; Redis locks serialize risk-sensitive sections (invoice-number allocation, reconciliation) | [`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md), [`../../api/API_ARCHITECTURE.md`](../../api/API_ARCHITECTURE.md) |
| Object storage | Supabase Storage (S3-compatible) for documents and exports; user content is served only through signed URLs, never from the app tier's own filesystem | [`../../backend/FILE_SERVICE.md`](../../backend/FILE_SERVICE.md) |
| Webhooks | Signed, retried outbound delivery on the `integrations` queue for partner integrations | [`../../api/API_WEBHOOKS.md`](../../api/API_WEBHOOKS.md) |

# The AI-Engine Boundary

The boundary between the backend and the FastAPI AI engine is one of the platform's most important invariants
and is a product guarantee, not just an implementation detail: **the AI engine never edits the database
directly; every write passes through `/api/v1`, and every read it performs is permission-checked as the acting
user.** The engine is a consultant, not an owner (owned by
[`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md) and
[`../../api/INTERNAL_API.md`](../../api/INTERNAL_API.md)).

The traffic flows two ways. The backend calls the engine for reasoning — OCR, chat, analysis, forecasting,
report narration — synchronously for latency-bounded turns and via the `ai` queue for heavy work. The engine
calls the backend for data and effects through the internal API with a short-lived service token scoped to the
acting user and company; a proposed write does not commit — it lands as an `ai_proposal` a human approves
through the workflow engine. Because the AI engine is deployed and scaled as its own service the backend only
depends on through an HTTP client and a queue, an AI outage degrades AI features (a graceful `503` or a null on
AI-optional paths) but never blocks a core accounting write. Several policy questions about AI autonomy,
provider selection, in-region inference, and spend governance are open and tracked in
[`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md) (AI-1 through AI-4, ARC-6, OPS-3); none changes this boundary.

# Monitoring, Logging, And Audit

Every request is traceable end to end by its `request_id`: the `X-Request-Id` is attached to every log line,
every queued job, every AI-engine call, and every webhook delivery, so one id follows a request across the
backend, the queues, and the engine. Two distinctions are fixed at PRD altitude and must not be conflated:

- **Operational logs are for engineers and are ephemeral;** they never record the monetary payload or PII.
  Unhandled exceptions go to error tracking with the correlation id and company id, and the client receives a
  generic non-leaking `500`.
- **The audit trail is durable, tamper-evident business history** and is written inside the same transaction as
  the state change it records, so data and its history can never diverge. Its schema and immutability guarantees
  belong to the data platform — see PRD chapter 09 and
  [`../../security/AUDIT_LOGS.md`](../../security/AUDIT_LOGS.md).

Health, readiness, and metrics (request latency, queue depth per queue, job runtime, AI-engine call latency
and error rate, broadcast throughput) are exposed for the load balancer and observability stack, per
[`../../api/API_MONITORING.md`](../../api/API_MONITORING.md).

# Deployment Shape And What This Chapter Does Not Decide

One codebase deploys as stateless web dynos, per-queue workers scaled on backlog depth, Reverb nodes scaled on
concurrent connections, and a single scheduler node, in front of PostgreSQL (primary + read replica), Redis,
Supabase Storage, and the independently-scaled AI engine (topology in
[`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md)). Single-region-at-launch versus
full active/passive DR (OPS-1), the first-party access-token TTL divergence (ARC-2), and the dev-on-Hetzner /
prod-on-AWS split (OPS-2) are open items in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md). The database's own
shape — schema, multi-tenancy, RLS, integrity, backup — is chapter 09, not this one.

## Related Documents

- **This chapter (08) expands and routes to:**
  [`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md),
  [`../../backend/SERVICE_ARCHITECTURE.md`](../../backend/SERVICE_ARCHITECTURE.md),
  [`../../backend/WORKFLOW_ENGINE.md`](../../backend/WORKFLOW_ENGINE.md),
  [`../../backend/AUTOMATION_ENGINE.md`](../../backend/AUTOMATION_ENGINE.md),
  [`../../backend/AI_SERVICE.md`](../../backend/AI_SERVICE.md),
  [`../../backend/FILE_SERVICE.md`](../../backend/FILE_SERVICE.md)
- **API contract:** [`../../api/API_ARCHITECTURE.md`](../../api/API_ARCHITECTURE.md),
  [`../../api/REST_STANDARDS.md`](../../api/REST_STANDARDS.md),
  [`../../api/INTERNAL_API.md`](../../api/INTERNAL_API.md),
  [`../../api/API_IDEMPOTENCY.md`](../../api/API_IDEMPOTENCY.md),
  [`../../api/API_WEBHOOKS.md`](../../api/API_WEBHOOKS.md),
  [`../../api/API_MONITORING.md`](../../api/API_MONITORING.md)
- **Security:** [`../../security/AUTHENTICATION.md`](../../security/AUTHENTICATION.md),
  [`../../security/AUTHORIZATION.md`](../../security/AUTHORIZATION.md),
  [`../../security/AI_SECURITY.md`](../../security/AI_SECURITY.md),
  [`../../security/AUDIT_LOGS.md`](../../security/AUDIT_LOGS.md)
- **Data platform:** [`../../database/DATABASE_ARCHITECTURE.md`](../../database/DATABASE_ARCHITECTURE.md),
  [`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md) — see PRD chapter 09
- **Cross-PRD:** [`./07_FRONTEND.md`](./07_FRONTEND.md), [`./09_DATABASE.md`](./09_DATABASE.md),
  [`../MASTER_PRD.md`](../MASTER_PRD.md), open items in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)

# End of Document
