# ADR-0003: Supabase for managed data services (Postgres, Storage, Realtime)

Status: Accepted

Date: 2026-07

## Context

QAYD needs a system-of-record database, object storage for documents and exports, and a realtime channel
for backend → client updates — all of them operationally demanding to stand up and run well (connection
pooling, backups, point-in-time recovery, a realtime server, a storage service with signed URLs). What QAYD
must **not** outsource is its domain. It is a double-entry accounting core whose surface area only grows —
Inventory, Payroll, a full Tax engine, Banking/Treasury, and a multi-agent AI layer within two years — and
that logic belongs in a strong, explicit domain layer, **Laravel 12**
([../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md);
[../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) ADR-001;
[../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md)). The decision here is narrow and
deliberate: adopt a managed data platform for the plumbing **without** letting it become the backend.

## Decision

QAYD adopts **Supabase for managed data services only** — **managed PostgreSQL**
([ADR-0004](./0004-postgresql.md)) as the system of record, **Supabase Storage** for documents,
attachments, and generated exports (signed URLs), **Supabase Realtime** as an available option for
company-scoped backend → client pushes ([ADR-0006](./0006-event-driven.md)), and **Supabase Auth** as an
option. It is **not** the backend.

The **backend and domain layer is Laravel 12 (PHP 8.4)** in `apps/api` ([ADR-0001](./0001-monorepo-structure.md)).
All business logic lives there: the General Ledger, the Posting Engine, double-entry enforcement, Bank
Reconciliation, the Tax Engine, Automation, Audit Logs, and the AI orchestration handoff. Every business
operation passes through Laravel's versioned, OpenAPI-documented `/api/v1`
([ADR-0008](./0008-typescript-shared-packages.md)). Clients (the Next.js web app, mobile, the AI engine) do
**not** query Postgres directly through PostgREST for business operations, and there are **no Supabase Edge
Functions carrying domain rules**. Authentication is **Laravel Sanctum** (cookie sessions) **+ RS256 JWT**
for bearer clients; Supabase Auth is available but is not the primary identity provider. Tenant isolation
is enforced at the database by Row-Level Security ([ADR-0005](./0005-multitenancy-rls.md)), with Laravel
pinning the active company per request. The AI engine ([ADR-0007](./0007-ai-orchestrator.md)) authenticates
and proposes through this same Laravel surface, never directly to the database.

## Consequences

Positive:
- QAYD gets managed Postgres, storage, and realtime — the operationally heavy plumbing — without operating it, while keeping every business rule in a domain layer built to hold accounting complexity.
- A strong Laravel domain layer (service classes, policies, queues, events, a real posting engine) scales cleanly into Inventory, Payroll, Tax, and AI far better than domain logic spread across database edge functions would.
- RLS is native to the managed Postgres, so the multi-tenant isolation floor ([ADR-0005](./0005-multitenancy-rls.md)) is enforced by the storage engine exactly as required, independent of the application layer.
- The data plane is portable: Postgres, the schema, and RLS are vendor-neutral, so the managed provider is a swappable dependency rather than a lock-in of the domain.

Negative / trade-offs:
- Running a Laravel domain layer is more up-front infrastructure than leaning on a backend-as-a-service — the API, queues, and realtime broadcaster must be operated (see [ADR-0006](./0006-event-driven.md), Laravel Reverb).
- Using managed Storage/Realtime/Auth means some provider-shaped configuration; these are integration points, not the domain, so a provider change is contained.
- The team owns the discipline that business logic stays in Laravel and never leaks into client-side or edge-function shortcuts.

**This ADR replaces the earlier "Supabase as the backend" direction.** An earlier draft of this record
proposed building the MVP on Supabase as the backend (business logic in TypeScript route handlers and Edge
Functions). That direction is **superseded by this decision**: it does not hold for an accounting platform
whose domain deepens every quarter, because a serious General Ledger, Posting Engine, Tax engine, and AI
orchestration layer need a first-class domain layer, not edge functions. Supabase's role is now strictly the
managed data plane; Laravel is the backend. This aligns the build with the target architecture in
[../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) rather than diverging
from it.

## Alternatives considered

- **Supabase as the backend (business logic in Edge Functions / TypeScript):** faster to a first screen, but it puts double-entry posting, tax, reconciliation, and AI governance in edge functions — the wrong home for a domain this heavy. Rejected; this is the direction this ADR replaces.
- **Self-managed Postgres on a VPS + hand-rolled storage/realtime:** maximum control, but we would rebuild precisely the managed Postgres, storage, and realtime Supabase provides, for no domain benefit.
- **Firebase:** excellent managed auth and realtime, but a document store is a poor fit for double-entry accounting, which needs relational integrity and SQL ([ADR-0004](./0004-postgresql.md)).

## Related

- [../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md) — the governing stack reference; see "Supabase's Role".
- [ADR-0001](./0001-monorepo-structure.md), [ADR-0004](./0004-postgresql.md), [ADR-0005](./0005-multitenancy-rls.md), [ADR-0006](./0006-event-driven.md), [ADR-0007](./0007-ai-orchestrator.md), [ADR-0008](./0008-typescript-shared-packages.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-001, ADR-002, ADR-004; [../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md); [../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md)
