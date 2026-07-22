# QAYD Architecture Decision Records — Build Set

Status: Accepted
Date: 2026-07

This directory is the Architecture Decision Record (ADR) log for QAYD. It records the load-bearing technical
decisions that build the platform, and every record here agrees with the **locked, final architecture**
captured in [../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md) (referred to as **Option A**).

**Governing document.** [../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md) is the single authoritative reference
for the stack and the monorepo shape. If any ADR ever conflicts with it, the ADR is wrong and is updated to
match FINAL_TECH_STACK — that file wins. The short version of Option A: a **Laravel 12 (PHP 8.4)** backend and
domain layer, a **Next.js 15 / React 19 / TypeScript** web app, and a **FastAPI (Python)** AI engine, over a
single **PostgreSQL** database (RLS multi-tenancy), with **Redis** cache/queue, **Supabase** used only for
managed data services (Postgres, Storage, Realtime, optional Auth), **Laravel Reverb** realtime, and **Docker**
deployment — arranged as a polyglot monorepo (`apps/web` + `apps/api` + `apps/ai`, `packages/*`,
`infrastructure/*`).

This set is consistent with — not a divergence from — the platform's design-level ADR log at
[../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md), which records the
foundational decisions fixed by the specification set (modular monolith in Laravel, a separate FastAPI AI
engine, and so on). The two logs now describe the same architecture; read the ADRs here for the reasoning
behind each build decision, and FINAL_TECH_STACK for the authoritative summary that governs both.

## What an ADR Is

An Architecture Decision Record captures **one** architecturally significant decision — one that is expensive
to reverse, constrains many later decisions, or is something a new engineer would otherwise have to
reverse-engineer from the code and get wrong. It records the decision, why it was made, and what it costs, so
that the difference between "this is deliberate" and "this is an accident nobody cleaned up" is never lost.

These ADRs follow the widely-used **Michael Nygard** format. Every record in this set has exactly these
sections:

| Section | What it holds |
|---|---|
| `# ADR-NNNN: <Title>` | The decision as a short phrase — the choice, not the problem. |
| **Status** | `Proposed`, `Accepted`, `Superseded by ADR-NNNN`, or `Deprecated`. Every record here is `Accepted`. |
| **Date** | The month the decision was accepted. |
| **Context** | The forces in play: the requirement, the constraints, and what made the decision necessary. |
| **Decision** | The choice, in active voice — specific enough that a reviewer can tell if a pull request honors it. |
| **Consequences** | What becomes easier and what becomes harder — the positive and the negative/trade-offs. |
| **Alternatives considered** | The options weighed and why each was set aside. |
| **Related** | Links to sibling ADRs and the specification documents that own the mechanics. |

Two rules keep the log trustworthy: **ADRs are immutable once Accepted** (you supersede, never silently
rewrite), and **numbers are never reused**. This mirrors QAYD's own accounting rule — a posted fact is
corrected by a new linked record, not an edit.

## The Records

| ADR | Title | Status |
|---|---|---|
| [0001](./0001-monorepo-structure.md) | Polyglot monorepo (apps + packages + infrastructure) with per-app tooling | Accepted |
| [0002](./0002-nextjs-for-web.md) | Next.js 15 (App Router) for the web application, consuming Laravel's `/api/v1` | Accepted |
| [0003](./0003-supabase-as-backend.md) | Supabase for managed data services (Postgres, Storage, Realtime); Laravel 12 is the backend | Accepted |
| [0004](./0004-postgresql.md) | PostgreSQL as the system of record | Accepted |
| [0005](./0005-multitenancy-rls.md) | Single-database multi-tenancy via Postgres Row-Level Security on `company_id` | Accepted |
| [0006](./0006-event-driven.md) | An event-driven core (domain events, outbox, Reverb realtime) | Accepted |
| [0007](./0007-ai-orchestrator.md) | A dedicated AI Orchestrator in the FastAPI engine with a human-in-the-loop safety contract | Accepted |
| [0008](./0008-typescript-shared-packages.md) | Shared TypeScript types (web + SDK); the cross-service contract is Laravel's `/api/v1` (OpenAPI) | Accepted |
| [0009](./0009-tailwind-shadcn-design-system.md) | Tailwind + shadcn/ui bound to the QAYD brass design tokens | Accepted |

## How to Add a New ADR

1. Copy the section layout of any existing record. Number the file `NNNN-<slug>.md` using the next unused
   integer (numbers are never reused, even after a supersession).
2. Open it with status `Proposed` in the same pull request as the code that first honors the decision.
3. On merge after review, change the status to `Accepted` and add a row to the table above.
4. To reverse an accepted decision, write a **new** ADR that supersedes it, cite the old record, and set the
   old record's status line to `Superseded by ADR-NNNN` — the only permitted edit to an accepted ADR.
5. If a decision changes the target architecture recorded in
   [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md), cross-link both
   directions so neither log drifts from the other.
