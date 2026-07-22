# ADR-0006: Build an event-driven core (domain events, outbox, realtime)

Status: Accepted

Date: 2026-07

## Context

QAYD spans many domains — Accounting, Sales, Purchasing, Banking, Inventory, Payroll, Tax — plus an AI layer,
and the design target ([../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md)
ADR-001) requires modules to communicate through **events and service interfaces, never by reaching into each
other's tables**. Three forces make an event backbone necessary for the MVP rather than a later refinement.
First, a single business action fans out: posting an invoice touches the ledger, inventory, and tax, and
downstream reactions (notifications, report cache invalidation) should not be wired into the posting code
itself. Second, the AI layer ([ADR-0007](./0007-ai-orchestrator.md)) learns from and reacts to what happens in
the books — it needs a reliable stream of "what just happened," not polling. Third, the live dashboard needs
backend → client updates ([../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md) names **Laravel
Reverb** as the primary realtime channel; **Supabase Realtime** is an available option,
[ADR-0003](./0003-supabase-as-backend.md)). All three want the same thing: a decoupled record of domain events.

## Decision

QAYD's core is **event-driven**. Significant state changes emit **domain events** (`journal_entry.posted`,
`invoice.created`, `ai_proposal.approved`) that carry an immutable, versioned payload. To make events reliable
across the transaction that produced them, events are written to an **outbox table in the same database
transaction** as the state change (transactional outbox pattern), so an event is emitted **if and only if** its
fact was committed — no lost events, no phantom events. A dispatcher relays outbox rows to consumers: in-process
handlers for cross-module reactions, the AI ingestion path (relayed to the FastAPI engine after commit,
[ADR-0007](./0007-ai-orchestrator.md)), and **Laravel Reverb** (with Supabase Realtime as an option) for
company-scoped, one-way backend → client pushes. Realtime is a notification to refresh authoritative state,
**never a second write path** — the database remains the sole writer. Channels are private and scoped by `company_id`, carrying
the tenant boundary ([ADR-0005](./0005-multitenancy-rls.md)) onto the socket layer.

## Consequences

Positive:
- Modules decouple: posting code emits `journal_entry.posted` and is done; notifications, cache invalidation, and AI ingestion subscribe without the posting path knowing they exist.
- The transactional outbox makes delivery reliable — events and facts commit atomically, so consumers never see a state that the ledger does not reflect.
- The AI layer feeds off a clean event stream rather than polling, and the live dashboard updates without polling either, both from the same backbone.
- The event seams are exactly the boundaries a future extraction to separate services would follow.

Negative / trade-offs:
- Event-driven flow is harder to trace than a straight call stack; debugging requires following events through the outbox and consumers, so events must be logged and correlatable.
- The outbox needs a dispatcher/relay and at-least-once consumer semantics, so handlers must be idempotent — extra discipline the MVP must hold from the start.
- Event payloads are a contract; changing one is a versioned change, like an API field ([ADR-0008](./0008-typescript-shared-packages.md)).

## Alternatives considered

- **Direct synchronous calls between modules:** simplest to trace, but couples modules tightly and drags cross-cutting reactions into core business paths — exactly what ADR-001's module discipline forbids.
- **A dedicated message broker (Kafka/RabbitMQ) now:** strong delivery semantics, but a heavy operational dependency the MVP does not need; the in-database outbox gives reliable delivery without a new system, and can front a broker later.
- **Realtime as a writable channel:** tempting for "instant" UX, but it would create a second source of truth competing with the database — rejected in favor of one-way, cache-refreshing pushes.

## Related

- [ADR-0003](./0003-supabase-as-backend.md), [ADR-0005](./0005-multitenancy-rls.md), [ADR-0007](./0007-ai-orchestrator.md), [ADR-0008](./0008-typescript-shared-packages.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-001, ADR-011; [../../database/DATABASE_EVENTS.md](../../database/DATABASE_EVENTS.md)
