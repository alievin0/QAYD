# ADR-0011: Refresh-only broadcasts may be emitted directly until the transactional outbox exists

Status: Accepted

Date: 2026-08-05

Amends: [ADR-0006](./0006-event-driven.md) (does not supersede it)

## Context

[ADR-0006](./0006-event-driven.md) decided that significant state changes emit domain events written to
a **transactional outbox in the same database transaction** as the change, and that a dispatcher relays
outbox rows to consumers — in-process handlers, the AI ingestion path, and **Laravel Reverb** for
company-scoped backend → client pushes. [`DATABASE_EVENTS.md`](../../database/DATABASE_EVENTS.md)
specifies that machinery in full: a partitioned `domain_events` table, `event_inbox`,
`dead_letter_events`, `event_type_registry`, PL/pgSQL trigger functions, `LISTEN`/`NOTIFY` on
`domain_events_channel`, a relay process, Redis Streams, and inbox deduplication for at-least-once
consumers.

None of it is built. SPRINT_02 §S2-13 asks for one thing out of that picture — the
`accounting.journal.posted` Reverb push on `private-company.{id}`, so an open ledger screen re-reads
after a posting — and estimates it at 3 points. Building the outbox first would be an epic, and an epic
whose only consumer today is a hint to refresh a screen.

The two documents also disagree about timing: [`MVP_SCOPE.md`](../../execution/MVP_SCOPE.md) lists
"Reverb realtime" as deferred to Phase 2 and states that no live sockets are required for the MVP, while
SPRINT_02 schedules this story in Sprint 2. That contradiction was raised and resolved by the
architecture owner in favour of shipping the story now.

## Decision

**A broadcast whose entire payload is a refresh notification may be emitted directly from its domain
event, without passing through the transactional outbox.** ADR-0006's outbox requirement continues to
govern every other consumer.

The permission is narrow, and each bound is load-bearing:

1. **Refresh-only.** The payload may carry enough for a client to decide whether what it is showing is
   stale, and nothing a client could render as a figure. `JournalEntryPosted::broadcastWith()` is the
   reference shape: identifiers plus a total, consumed by a component that discards it and re-reads the
   API.
2. **Never a source of truth.** Restates ADR-0006 and `FINAL_TECH_STACK`. A broadcast is not a write
   path, not an ordering guarantee, and not evidence that anything happened.
3. **After commit only.** The event is constructed and dispatched after the posting transaction commits,
   so a delivered message always describes a durable fact. The failure this permits is a *missing*
   message, never a false one.
4. **Any durable consumer waits for the outbox.** Anything whose correctness depends on receiving every
   event — the AI ingestion path, cross-module reactions, webhooks, sub-ledger cache invalidation — must
   not subscribe to a direct broadcast. When the outbox lands, those consumers read from it and this
   event's delivery moves behind the relay without its shape changing.
5. **Tenant isolation is not relaxed.** Channels stay private and company-scoped, authorized by the same
   membership rule the HTTP path uses (`App\Broadcasting\CompanyChannel`).

## Consequences

Positive:

- S2-13 ships at its estimated size, and the live-refresh behaviour the sprint asked for exists.
- The event's public shape is already what it will be after the migration, so moving delivery behind a
  relay later changes how the message is sent, not what it says or who receives it.
- The bound in (4) keeps the shortcut from spreading: the consumers that would actually be harmed by
  at-most-once delivery are excluded in writing, before any of them exist.

Negative / trade-offs:

- Delivery is at-most-once. A dropped push leaves a screen stale until the person reloads. Acceptable
  precisely because the payload is a hint; unacceptable for anything in (4).
- There is a second, temporary emission path in the system, and someone reading ADR-0006 alone would not
  expect it. Hence this amendment, `TD-30`, and the note in the event's own docblock.
- The migration is real work that is not yet scheduled. TD-30 carries it.

## Alternatives considered

- **Build the transactional outbox first.** Fully honours ADR-0006 and is the correct end state, but an
  epic in place of a 3-point story, and premature: its guarantees exist for consumers that do not yet
  exist, and designing an outbox around a screen refresh would be designing it around the wrong load.
- **Defer the broadcast to Phase 2**, per `MVP_SCOPE.md`. No deviation and no new infrastructure, and a
  defensible reading of the frozen documents — rejected by the architecture owner in favour of the
  sprint plan, which schedules the story now.
- **Broadcast from a queued listener rather than the event.** Moves the work off the request path, which
  `ShouldBroadcast` already does, while adding a layer that still would not be the outbox. No benefit.

## Related

- [ADR-0006](./0006-event-driven.md) — the decision this amends
- [`DATABASE_EVENTS.md`](../../database/DATABASE_EVENTS.md) — the outbox specification this defers
- `TECH_DEBT.md` TD-30 — the migration back onto the outbox
- `docs/execution/SPRINT_02.md` §S2-13
